<?php

declare(strict_types=1);

namespace justinholtweb\glue\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\fields\BaseRelationField;
use justinholtweb\glue\jobs\RewireRelations;
use justinholtweb\glue\models\MergeResult;
use justinholtweb\glue\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Repoints everything that pointed at a retired entry at the entry that survived it. **Pro.**
 *
 * ## Why this is not an UPDATE statement
 *
 * It is tempting — `relations` has `sourceId`, `fieldId` and `targetId`, and one statement would
 * fix every row. It would also be wrong. Since Craft 5.3 a relation field's value lives in the
 * **element's content JSON**, and `relations` is a secondary index used for `relatedTo` queries
 * and as a fallback for elements that have not been re-saved since. `BaseRelationField::
 * normalizeValue()` reads the content column first and only looks at `relations` when the
 * content value is `null`. So an UPDATE would fix the query index and leave every actual field
 * value pointing at the entry you just trashed — the worst kind of wrong, because `relatedTo`
 * searches would agree with you while the templates rendered nothing.
 *
 * The only thing that writes both is a real element save, so that is what this does: load each
 * referencing element, swap the IDs in its relation fields, save. Slower by orders of magnitude,
 * and correct.
 *
 * ## Reference tags are not relations
 *
 * Revisions are not rewired either, and that is not an omission: a revision is a record of what
 * an entry *was*, and rewriting one would be falsifying it. So a relation row pointing at a
 * retired entry legitimately survives in the revision history — a "nothing points at it any
 * more" check has to exclude revisions or it will always fail.
 *
 * `{entry:482:url}` in a rich text field creates no `relations` row and cannot be found this
 * way. Glue does not rewrite those — that is a content search-and-replace, not a relation — but
 * it does count them and say so, because "it rewired everything" and "it rewired everything it
 * can see" are very different promises to make to somebody about to empty the trash.
 *
 * ## Only what the user could have edited by hand
 *
 * A rewire saves elements nobody opened — potentially every page that links to the retired entry,
 * in any section and any site. Run for a logged-in user, it changes only the elements and sites
 * that user could have changed themselves, and says how many it left alone. Otherwise a merge
 * would be a way to put your entry into pages you cannot edit. The console has no user and is
 * trusted, as it is everywhere else in the plugin.
 */
class Rewirer extends Component
{
    /**
     * @param int[] $retiredIds
     */
    public function rewire(array $retiredIds, int $survivorId, ?MergeResult $result = null): int
    {
        $retiredIds = array_values(array_filter(
            array_map('intval', $retiredIds),
            fn(int $id) => $id > 0 && $id !== $survivorId,
        ));

        if ($retiredIds === []) {
            return 0;
        }

        $sourceIds = $this->referencingElementIds($retiredIds);
        $user = Craft::$app->getUser()->getIdentity();

        if ($user !== null) {
            $editable = array_values(array_filter($sourceIds, fn(int $id) => $this->canRewire($id, $user)));
            $skipped = count($sourceIds) - count($editable);
            $sourceIds = $editable;

            if ($skipped > 0 && $result !== null) {
                $result->addWarning(Craft::t('glue', '{n, plural, =1{One element points} other{# elements point}} at a retired entry but {n, plural, =1{is} other{are}} not yours to edit, so {n, plural, =1{it was} other{they were}} left alone.', [
                    'n' => $skipped,
                ]));
            }
        }

        $threshold = Plugin::getInstance()->getSettings()->rewireThreshold;

        if ($result !== null) {
            foreach ($this->refTagWarnings($retiredIds) as $warning) {
                $result->addWarning($warning);
            }
        }

        if ($sourceIds === []) {
            return 0;
        }

        if ($threshold > 0 && count($sourceIds) > $threshold) {
            Craft::$app->getQueue()->push(new RewireRelations([
                'elementIds' => $sourceIds,
                'retiredIds' => $retiredIds,
                'survivorId' => $survivorId,
                'userId' => $user?->id,
            ]));

            if ($result !== null) {
                $result->rewireQueued = true;
                $result->rewired = count($sourceIds);
            }

            return count($sourceIds);
        }

        $rewired = 0;

        foreach ($sourceIds as $sourceId) {
            if ($this->rewireElement($sourceId, $retiredIds, $survivorId, $user)) {
                $rewired++;
            }
        }

        if ($result !== null) {
            $result->rewired = $rewired;
        }

        return $rewired;
    }

    /**
     * Elements with at least one relation pointing at one of the retired entries.
     *
     * The survivor is excluded: an entry relating to the entry it just absorbed would otherwise
     * be rewired into relating to itself, which is a self-relation nobody asked for. Removing
     * the stale ID is what the ordinary field rewrite does to it instead.
     *
     * So are revisions — see the comment on the query. The count this returns is what the user
     * is told and what the queue threshold is measured against, so it has to be the number of
     * elements that will actually be changed.
     *
     * @param int[] $retiredIds
     * @return int[]
     */
    public function referencingElementIds(array $retiredIds): array
    {
        if ($retiredIds === []) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select(['r.sourceId'])
            ->distinct()
            ->from(['r' => CraftTable::RELATIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[r.sourceId]]')
            ->where(['r.targetId' => $retiredIds])
            ->andWhere(['not', ['r.sourceId' => $retiredIds]])
            // Revisions are excluded here rather than left for `rewireElement()` to skip. Every
            // save of a referencing entry leaves a revision with its own relation rows, so an
            // entry edited a dozen times contributes a dozen rows — which would inflate the
            // count reported to the user, push a small rewire over the queue threshold, and give
            // the job a list of elements it can only discard.
            ->andWhere(['e.revisionId' => null])
            ->column());
    }

    /**
     * Rewrites one element's relation fields, in every site it has.
     *
     * Per site rather than once, because a relation field can be translatable, and a
     * translatable field's French value is not touched by saving the English one. Sites where
     * nothing changed are not saved at all, so a translatable field pointing at the retired
     * entry in one locale costs one save and not five.
     *
     * @param int[] $retiredIds
     * @param User|null $user Who asked for the rewire; sites and elements they cannot edit are
     * skipped. Null for the console, which is not checked.
     */
    public function rewireElement(int $elementId, array $retiredIds, int $survivorId, ?User $user = null): bool
    {
        $elementsService = Craft::$app->getElements();
        $type = $elementsService->getElementTypeById($elementId);

        if ($type === null) {
            return false;
        }

        /** @var class-string<ElementInterface> $type */
        $changed = false;

        foreach ($this->siteIdsFor($elementId) as $siteId) {
            $element = $type::find()
                ->id($elementId)
                ->siteId($siteId)
                ->status(null)
                ->drafts(null)
                ->revisions(false)
                ->trashed(null)
                ->one();

            if ($element === null) {
                continue;
            }

            // Somebody's open editor. Saving it would stamp their half-finished work as modified
            // and push it into the change log under Glue's name. A provisional draft is merged
            // back into its canonical entry when they save, and the canonical entry is rewired
            // here, so the stale ID does not survive the round trip either way.
            if ($element->isProvisionalDraft) {
                continue;
            }

            if ($user !== null && !$this->userCanEdit($element, $user)) {
                continue;
            }

            if (!$this->rewriteFields($element, $retiredIds, $survivorId)) {
                continue;
            }

            try {
                if ($elementsService->saveElement($element, false)) {
                    $changed = true;
                }
            } catch (Throwable $e) {
                Craft::error(sprintf(
                    'Could not rewire relations on element %d in site %d: %s',
                    $elementId,
                    $siteId,
                    $e->getMessage(),
                ), Plugin::LOG_CATEGORY);
            }
        }

        return $changed;
    }

    /**
     * Whether the user could save this element in at least one site — the up-front filter, so the
     * count the user is told and the queue threshold both measure what will really happen.
     */
    private function canRewire(int $elementId, User $user): bool
    {
        $type = Craft::$app->getElements()->getElementTypeById($elementId);

        if ($type === null) {
            return false;
        }

        /** @var class-string<ElementInterface> $type */
        $elements = $type::find()
            ->id($elementId)
            ->site('*')
            ->status(null)
            ->drafts(null)
            ->revisions(false)
            ->trashed(null)
            ->all();

        foreach ($elements as $element) {
            if ($this->userCanEdit($element, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Craft's own test for editing an element in a site: permission on the element, and on the
     * site, which `canSave()` does not check by itself.
     */
    private function userCanEdit(ElementInterface $element, User $user): bool
    {
        if (!Craft::$app->getElements()->canSave($element, $user)) {
            return false;
        }

        if (!Craft::$app->getIsMultiSite()) {
            return true;
        }

        $site = Craft::$app->getSites()->getSiteById((int)$element->siteId, true);

        return $site !== null && $user->can("editSite:$site->uid");
    }

    /**
     * @return int[]
     */
    private function siteIdsFor(int $elementId): array
    {
        $siteIds = array_map('intval', (new Query())
            ->select(['siteId'])
            ->from(CraftTable::ELEMENTS_SITES)
            ->where(['elementId' => $elementId])
            ->column());

        return $siteIds ?: [Craft::$app->getSites()->getPrimarySite()->id];
    }

    /**
     * Swaps the IDs in every relation field on an element.
     *
     * The survivor replaces the retired entry **in place**, keeping the field's order, and then
     * duplicates are dropped keeping the first — so an entry that already related to both A and
     * B ends up relating to the merged entry once, in A's position, rather than twice or at the
     * end.
     *
     * @param int[] $retiredIds
     * @return bool Whether anything changed.
     */
    private function rewriteFields(ElementInterface $element, array $retiredIds, int $survivorId): bool
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return false;
        }

        $changed = false;

        foreach ($layout->getCustomFields() as $field) {
            if (!$field instanceof BaseRelationField) {
                continue;
            }

            $handle = (string)$field->handle;

            try {
                $ids = $this->storedIds($field, $element, $handle);
            } catch (Throwable) {
                continue;
            }

            if (array_intersect($ids, $retiredIds) === []) {
                continue;
            }

            $rewritten = [];

            foreach ($ids as $id) {
                $new = in_array($id, $retiredIds, true) ? $survivorId : $id;

                if (!in_array($new, $rewritten, true)) {
                    $rewritten[] = $new;
                }
            }

            // An element that related only to the entry it has now become keeps no self
            // relation; it just loses the field's value, which is the truthful outcome.
            if ($element->id === $survivorId) {
                $rewritten = array_values(array_filter($rewritten, fn(int $id) => $id !== $survivorId));
            }

            if ($rewritten === $ids) {
                continue;
            }

            $element->setFieldValue($handle, $rewritten);
            $changed = true;
        }

        return $changed;
    }

    /**
     * The element IDs a relation field is actually storing, trash included.
     *
     * `serializeValue()` resolves the field's query, and a relation query drops targets that are
     * in the trash — which is exactly the state a retired source is in by the time the queued
     * rewire runs. Read through it and the field looks like it never mentioned the retired entry
     * at all, so nothing is rewritten and the stale ID stays in the content column forever.
     *
     * Cloning the query and clearing its status, trashed, draft and limit filters gets back the
     * stored list. Falling back to `serializeValue()` covers a field whose value is a collection
     * rather than a query.
     *
     * @return int[]
     */
    private function storedIds(BaseRelationField $field, ElementInterface $element, string $handle): array
    {
        $value = $element->getFieldValue($handle);

        if ($value instanceof ElementQueryInterface) {
            $query = (clone $value)
                ->status(null)
                ->drafts(null)
                ->trashed(null)
                ->limit(null);

            return array_map('intval', $query->ids());
        }

        return array_map('intval', $field->serializeValue($value, $element) ?: []);
    }

    /**
     * Counts reference tags naming a retired entry, so somebody can be told about them.
     *
     * A `LIKE` over the content column, which is a table scan on a big site — run once per
     * merge, and only when rewiring was asked for, which is the one moment the answer matters.
     *
     * @param int[] $retiredIds
     * @return string[]
     */
    public function refTagWarnings(array $retiredIds): array
    {
        if ($retiredIds === []) {
            return [];
        }

        $uids = (new Query())
            ->select(['id', 'uid'])
            ->from(CraftTable::ELEMENTS)
            ->where(['id' => $retiredIds])
            ->pairs();

        $needles = [];

        foreach ($retiredIds as $id) {
            $needles[] = sprintf('{entry:%d:', $id);

            if (isset($uids[$id])) {
                $needles[] = sprintf('{entry:%s:', $uids[$id]);
            }
        }

        $condition = ['or'];

        foreach ($needles as $needle) {
            $condition[] = ['like', 'content', $needle, false];
        }

        try {
            $count = (new Query())
                ->from(CraftTable::ELEMENTS_SITES)
                ->where($condition)
                ->count();
        } catch (Throwable) {
            return [];
        }

        if ((int)$count === 0) {
            return [];
        }

        return [Craft::t('glue', '{n, plural, =1{One field mentions} other{# fields mention}} a retired entry with a reference tag. Glue does not rewrite reference tags — search for “{tag}” before deleting anything for good.', [
            'n' => (int)$count,
            'tag' => $needles[0],
        ])];
    }
}
