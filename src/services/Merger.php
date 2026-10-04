<?php

declare(strict_types=1);

namespace justinholtweb\glue\services;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use craft\elements\Entry;
use craft\errors\ElementNotFoundException;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Matrix;
use craft\fields\Table;
use craft\models\Section;
use craft\services\Elements as ElementsService;
use DateTime;
use justinholtweb\glue\events\MergeEvent;
use justinholtweb\glue\models\Edition;
use justinholtweb\glue\models\FieldPair;
use justinholtweb\glue\models\MergePlan;
use justinholtweb\glue\models\MergeResult;
use justinholtweb\glue\models\Pair;
use justinholtweb\glue\models\Settings;
use justinholtweb\glue\Plugin;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Turns a plan into an entry.
 *
 * ## One code path, two answers
 *
 * `apply()` with `$dryRun` resolves every value and previews it, and then stops. The real run
 * resolves the same values through the same calls and saves them. Nothing about the resolution
 * is conditional on which one is happening, so the screen's result column is not a model of what
 * the merge will do — it is the merge, less the save.
 *
 * ## `null` does not mean empty
 *
 * The one thing to know before reading {@see self::blankValueFor()}: passing `null` to
 * `setFieldValue()` on a Matrix or relation field does **not** clear it. Both read `null` as "no
 * value was posted" and fall back to what is already in the database — which is correct for a
 * partial save and catastrophic here, because "Neither" would silently leave the target's own
 * value in place. The empty value has to be an empty *array*, and that is a per-field-type fact
 * rather than a general one.
 */
class Merger extends Component
{
    /** Raised before anything is written, with a cancellable event. */
    public const EVENT_BEFORE_MERGE = 'beforeMerge';

    /** Raised after the merged entry has saved and the sources have been dealt with. */
    public const EVENT_AFTER_MERGE = 'afterMerge';

    /**
     * Runs a plan.
     *
     * @param bool $dryRun Resolve and preview, write nothing.
     * @throws InvalidArgumentException if the plan does not describe a possible merge
     */
    public function apply(MergePlan $plan, bool $dryRun = false): MergeResult
    {
        $plan = $this->withSettings($plan);

        if (!$plan->validate()) {
            throw new InvalidArgumentException(implode(' ', $plan->getErrorSummary(true)));
        }

        $pairs = Plugin::getInstance()->pairs;
        $pair = $pairs->inspect($pairs->load((int)$plan->aId, (int)$plan->bId, (int)$plan->siteId), $plan);

        $result = new MergeResult();
        $result->createdNew = !$plan->mergesIntoSource();
        $result->warnings = $pair->warnings;
        $result->values = $this->resolveAll($pair, $plan);
        $result->previews = $this->previewAll($pair, $result->values);

        if ($dryRun) {
            $result->entry = $this->targetEntry($pair, $plan);
            return $result;
        }

        $this->assertAllowed($pair, $plan);

        $event = new MergeEvent(['plan' => $plan, 'pair' => $pair, 'result' => $result]);
        $this->trigger(self::EVENT_BEFORE_MERGE, $event);

        if (!$event->isValid) {
            throw new InvalidArgumentException(Craft::t('glue', 'The merge was cancelled by another plugin.'));
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $entry = $this->write($pair, $plan, $result);
            $result->entry = $entry;
            $result->saved = true;

            $this->mergeOtherSites($pair, $plan, $entry, $result);
            $this->verifyNestedOwnership($entry, $pair, $result);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Outside the transaction on purpose. Rewiring can touch hundreds of unrelated entries,
        // and holding a write transaction open across that many element saves is how a merge
        // becomes a lock-wait timeout on somebody else's publish.
        //
        // And *before* retiring, not after. Once a source is in the trash, Craft's relation
        // queries stop returning it — so a rewire that ran afterwards would look at each
        // referencing entry, see a topics field that no longer mentions the retired entry at
        // all, conclude there was nothing to do, and leave the ID sitting in the content column
        // where it will resolve to nothing forever. Order is the fix here; `Rewirer` reads
        // through the trash as well, because the queued path necessarily runs later.
        $this->rewire($plan, $result);
        $this->retire($plan, $result);

        $result->logId = Plugin::getInstance()->history->record($plan, $result);

        $this->trigger(self::EVENT_AFTER_MERGE, new MergeEvent([
            'plan' => $plan,
            'pair' => $pair,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * Fills in the parts of a plan that came from the settings rather than the form.
     *
     * Done once, here, so the plan written to the history is the plan that ran — including the
     * separators, which are the difference between a merge you can reproduce and one you cannot.
     */
    private function withSettings(MergePlan $plan): MergePlan
    {
        $settings = Plugin::getInstance()->getSettings();
        $isPro = Plugin::getInstance()->isPro();

        $plan = clone $plan;
        $plan->textSeparator = $settings->textSeparator;
        $plan->richTextSeparator = $settings->richTextSeparator;
        $plan->dedupeRows = $settings->dedupeRows;

        if (!Edition::allowsRewiring($isPro)) {
            $plan->rewire = false;
        }

        if (!Edition::allowsAllSites($isPro)) {
            $plan->allSites = false;
        }

        $plan->siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        return $plan;
    }

    // ------------------------------------------------------------------ resolving

    /**
     * The serialized value every field in the target's layout ends up with.
     *
     * @return array<string, mixed>
     */
    public function resolveAll(Pair $pair, MergePlan $plan): array
    {
        $strategies = Plugin::getInstance()->strategies;
        $values = [];

        foreach ($pair->fields as $handle => $fieldPair) {
            $strategy = $this->strategyFor($fieldPair, $plan);
            $value = $strategies->resolve($fieldPair, $strategy, $plan);

            // `resolve()` returns null both for "take nothing" and for "that side does not have
            // this field". Either way the field must end up empty, and empty is a shape.
            $values[$handle] = $value === null
                ? $this->blankValueFor($fieldPair)
                : $value;
        }

        return $values;
    }

    /**
     * The strategy a field will actually get.
     *
     * A plan with no opinion on a field falls back to the seeding rule rather than to a
     * hard-coded side — which is what lets a preset written for one entry type be replayed on
     * another without emptying the fields it has never heard of.
     */
    public function strategyFor(FieldPair $fieldPair, MergePlan $plan): string
    {
        $strategy = $plan->choices[$fieldPair->handle] ?? $fieldPair->suggested;

        if (!in_array($strategy, $fieldPair->availableStrategies(), true)) {
            return $fieldPair->suggested;
        }

        return $strategy;
    }

    /**
     * What "nothing" looks like to a given field.
     *
     * See the class comment: a `null` handed to Matrix or to a relation field means "unchanged",
     * not "empty", so the empty value has to be spelled out. An empty array is the right answer
     * for every field whose value is a list of something, and `null` for the scalars.
     */
    private function blankValueFor(FieldPair $fieldPair): mixed
    {
        $field = $fieldPair->field;

        if (
            $field instanceof Matrix ||
            $field instanceof BaseRelationField ||
            $field instanceof Table
        ) {
            return [];
        }

        if ($field instanceof BaseOptionsField) {
            return (is_array($fieldPair->aValue) || is_array($fieldPair->bValue)) ? [] : null;
        }

        // A third-party field Glue has never seen: if either side serialized to an array, an
        // array is the shape it reads back.
        if (is_array($fieldPair->aValue) || is_array($fieldPair->bValue)) {
            return [];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, \justinholtweb\glue\models\Preview>
     */
    private function previewAll(Pair $pair, array $values): array
    {
        $previews = Plugin::getInstance()->previews;
        $out = [];

        foreach ($values as $handle => $value) {
            $field = $pair->fields[$handle]->field ?? null;

            if ($field instanceof FieldInterface) {
                $out[$handle] = $previews->of($field, $value, $pair->siteId);
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ writing

    private function targetEntry(Pair $pair, MergePlan $plan): Entry
    {
        if ($plan->target === Settings::TARGET_A && $pair->a !== null) {
            return $pair->a;
        }

        if ($plan->target === Settings::TARGET_B && $pair->b !== null) {
            return $pair->b;
        }

        $entry = new Entry();
        $entry->siteId = $pair->siteId;
        $entry->sectionId = $plan->sectionId ?? $pair->a?->sectionId;
        $entry->typeId = Plugin::getInstance()->pairs->targetEntryType($pair, $plan)?->id;

        return $entry;
    }

    private function write(Pair $pair, MergePlan $plan, MergeResult $result): Entry
    {
        $entry = $this->targetEntry($pair, $plan);

        $this->applyAttributes($entry, $pair, $plan);
        $this->applyValues($entry, $result->values);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new ElementNotFoundException(sprintf(
                'The merged entry could not be saved: %s',
                implode(' ', $entry->getErrorSummary(true)) ?: 'no reason given',
            ));
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function applyValues(Entry $entry, array $values): void
    {
        $layout = $entry->getFieldLayout();

        foreach ($values as $handle => $value) {
            if ($layout?->getFieldByHandle($handle) === null) {
                continue;
            }

            $entry->setFieldValue($handle, $value);
        }
    }

    private function applyAttributes(Entry $entry, Pair $pair, MergePlan $plan): void
    {
        $a = $pair->a;
        $b = $pair->b;

        $entry->title = match ($plan->titleFrom) {
            MergePlan::TITLE_B => $b?->title,
            MergePlan::TITLE_CUSTOM => $plan->title,
            default => $a?->title,
        };

        match ($plan->slugFrom) {
            MergePlan::SLUG_B => $entry->slug = $b?->slug,
            MergePlan::SLUG_CUSTOM => $entry->slug = $plan->slug,
            // Null, not empty string: Craft only regenerates a slug from the title when the slug
            // is genuinely unset, and an empty string is a slug it will try to validate.
            MergePlan::SLUG_AUTO => $entry->slug = null,
            default => $entry->slug = $a?->slug,
        };

        $authorIds = match ($plan->authorFrom) {
            MergePlan::AUTHOR_B => $b?->getAuthorIds() ?? [],
            MergePlan::AUTHOR_CURRENT => array_filter([Craft::$app->getUser()->getId()]),
            default => $a?->getAuthorIds() ?? [],
        };

        $entry->setAuthorIds($authorIds);

        $entry->postDate = $this->resolvePostDate($plan, $a, $b) ?? $entry->postDate;

        $enabled = match ($plan->statusFrom) {
            MergePlan::STATUS_B => (bool)$b?->enabled,
            MergePlan::STATUS_ENABLED => true,
            MergePlan::STATUS_DISABLED => false,
            default => (bool)$a?->enabled,
        };

        $entry->enabled = $enabled;
        $entry->setEnabledForSite($enabled);

        $this->applyParent($entry, $pair, $plan);
    }

    private function resolvePostDate(MergePlan $plan, ?Entry $a, ?Entry $b): ?DateTime
    {
        $aDate = $a?->postDate;
        $bDate = $b?->postDate;

        return match ($plan->postDateFrom) {
            MergePlan::DATE_B => $bDate,
            MergePlan::DATE_NOW => new DateTime(),
            // The default. Two records of the same thing were first published when the earlier
            // of them was; taking the later one silently reorders the archive.
            MergePlan::DATE_EARLIEST => match (true) {
                $aDate === null => $bDate,
                $bDate === null => $aDate,
                default => min($aDate, $bDate),
            },
            default => $aDate,
        };
    }

    /**
     * Places the merged entry in a structure.
     *
     * Only ever attempted for a structure section, and never when the chosen parent is one of
     * the entries being merged — an entry cannot be its own parent, and the slip is easy to make
     * when B is A's child.
     */
    private function applyParent(Entry $entry, Pair $pair, MergePlan $plan): void
    {
        if ($entry->getSection()?->type !== Section::TYPE_STRUCTURE) {
            // Not a structure: there is nowhere to put a parent, and Craft ignores one anyway.
            return;
        }

        $parentId = match ($plan->parentFrom) {
            MergePlan::PARENT_B => $pair->b?->getParentId(),
            MergePlan::PARENT_NONE => null,
            default => $pair->a?->getParentId(),
        };

        if ($parentId !== null && in_array($parentId, [$pair->a?->id, $pair->b?->id], true)) {
            $parentId = null;
        }

        $entry->setParentId($parentId);
    }

    // ------------------------------------------------------------------ other sites

    /**
     * Repeats the merge in every other site the two entries share. **Pro.**
     *
     * Each site is resolved from scratch rather than propagated, because a translatable field
     * holds a different value per site — propagating the English merge over the French entries
     * would not be a merge, it would be an overwrite with English.
     */
    private function mergeOtherSites(Pair $pair, MergePlan $plan, Entry $entry, MergeResult $result): void
    {
        if (!$plan->allSites) {
            return;
        }

        $pairs = Plugin::getInstance()->pairs;

        foreach ($pair->sharedSiteIds as $siteId) {
            if ($siteId === $pair->siteId) {
                continue;
            }

            $sitePlan = clone $plan;
            $sitePlan->siteId = $siteId;
            $sitePlan->allSites = false;

            try {
                $sitePair = $pairs->inspect($pairs->load((int)$plan->aId, (int)$plan->bId, $siteId), $sitePlan);
            } catch (Throwable $e) {
                $result->addWarning(Craft::t('glue', 'Could not merge in one of the sites: {message}', [
                    'message' => $e->getMessage(),
                ]));
                continue;
            }

            $siteEntry = Entry::find()
                ->id($entry->id)
                ->siteId($siteId)
                ->status(null)
                ->one();

            if (!$siteEntry instanceof Entry) {
                continue;
            }

            $this->applyAttributes($siteEntry, $sitePair, $sitePlan);
            $this->applyValues($siteEntry, $this->resolveAll($sitePair, $sitePlan));

            if (!Craft::$app->getElements()->saveElement($siteEntry)) {
                $result->addWarning(Craft::t('glue', 'The merged entry could not be saved in one of the sites: {errors}', [
                    'errors' => implode(' ', $siteEntry->getErrorSummary(true)),
                ]));
            }
        }
    }

    // ------------------------------------------------------------------ afterwards

    /**
     * Checks that copying a nested-element field copied rather than re-owned.
     *
     * Matrix is safe by construction — its normaliser looks incoming block IDs up against the
     * target's own blocks and builds new ones for anything it does not recognise. Other fields
     * that hold nested elements are third-party code with their own normalisers, and a field
     * that re-parented B's blocks onto the merged entry would leave B looking fine right up
     * until B is trashed and takes the blocks with it. Cheap to check, so it is checked.
     */
    private function verifyNestedOwnership(Entry $entry, Pair $pair, MergeResult $result): void
    {
        $sourceIds = array_filter([$pair->a?->id, $pair->b?->id]);
        $sourceIds = array_diff($sourceIds, [$entry->id]);

        if ($sourceIds === []) {
            return;
        }

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$field instanceof ElementContainerFieldInterface) {
                continue;
            }

            try {
                $value = $entry->getFieldValue((string)$field->handle);
                $nested = is_object($value) && method_exists($value, 'all') ? $value->all() : [];
            } catch (Throwable) {
                continue;
            }

            foreach ($nested as $element) {
                if (
                    $element instanceof NestedElementInterface &&
                    in_array($element->getPrimaryOwnerId(), $sourceIds, true)
                ) {
                    $result->addWarning(Craft::t('glue', 'The “{field}” field is still sharing nested content with one of the source entries. Do not delete the sources until you have checked it.', [
                        'field' => $field->name ?: $field->handle,
                    ]));
                    break;
                }
            }
        }
    }

    /**
     * Disables or trashes the sources that did not survive the merge.
     *
     * Never the merge target, even if the plan somehow says so: `retiredIds()` already excludes
     * it, and this is the second place that would have to be wrong for a merge to delete its own
     * result.
     */
    private function retire(MergePlan $plan, MergeResult $result): void
    {
        if ($plan->disposition === Settings::DISPOSITION_LEAVE) {
            return;
        }

        $elements = Craft::$app->getElements();
        $survivorId = $plan->survivorId();

        // Its own transaction: this runs after the merge has committed, so the merge must not be
        // undone by a source that refuses to be trashed — but the two sources should still
        // succeed or fail together rather than leaving one disabled and one live.
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $this->retireEach($plan, $result, $elements, $survivorId);
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            $result->retired = [];
            $result->addWarning(Craft::t('glue', 'The merge saved, but the original entries could not be retired: {message}', [
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function retireEach(MergePlan $plan, MergeResult $result, ElementsService $elements, ?int $survivorId): void
    {
        foreach ($plan->retiredIds() as $id) {
            if ($id === $survivorId || $id === $result->entry?->id) {
                continue;
            }

            $entry = Entry::find()->id($id)->siteId($plan->siteId)->status(null)->one();

            if (!$entry instanceof Entry) {
                continue;
            }

            if ($plan->disposition === Settings::DISPOSITION_TRASH) {
                if ($elements->deleteElement($entry)) {
                    $result->retired[] = $id;
                }

                continue;
            }

            $entry->enabled = false;
            $entry->setEnabledForSite(false);

            if ($elements->saveElement($entry, false)) {
                $result->retired[] = $id;
            }
        }
    }

    private function rewire(MergePlan $plan, MergeResult $result): void
    {
        if (!$plan->rewire || $result->entry?->id === null) {
            return;
        }

        $retired = array_values(array_filter(
            $plan->retiredIds(),
            fn(?int $id) => $id !== null && $id !== $result->entry->id,
        ));

        if ($retired === []) {
            return;
        }

        Plugin::getInstance()->rewirer->rewire($retired, (int)$result->entry->id, $result);
    }

    /**
     * Refuses a merge the current user is not allowed to make.
     *
     * Craft's own `canSave`/`canDelete` are the authority, because a merge is exactly a save and
     * possibly a delete — a plugin permission that let somebody write to a section they cannot
     * otherwise write to would be a hole, not a feature.
     */
    private function assertAllowed(Pair $pair, MergePlan $plan): void
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return;
        }

        $elements = Craft::$app->getElements();
        $target = $this->targetEntry($pair, $plan);

        if (!$elements->canSave($target, $user)) {
            throw new InvalidArgumentException(Craft::t('glue', 'You are not allowed to save the merged entry.'));
        }

        // "Every site" writes to sites the form never showed. canSave() does not look at sites, so
        // each one is checked here — and refused rather than skipped, because a merge that landed
        // in three sites of four is the half-done merge this plugin will not make.
        if ($plan->allSites && Craft::$app->getIsMultiSite()) {
            foreach ($pair->sharedSiteIds as $siteId) {
                $site = Craft::$app->getSites()->getSiteById($siteId, true);

                if ($site !== null && !$user->can("editSite:$site->uid")) {
                    throw new InvalidArgumentException(Craft::t('glue', 'You are not allowed to edit the {site} site, so the merge cannot be repeated in every site.', [
                        'site' => $site->getName(),
                    ]));
                }
            }
        }

        // Leaving the sources alone needs nothing more — unless their inbound relations are
        // moving. Taking over everything that points at an entry is a bigger change to it than
        // disabling it, so it needs at least the right to edit it.
        if ($plan->disposition === Settings::DISPOSITION_LEAVE && !$plan->rewire) {
            return;
        }

        foreach ($plan->retiredIds() as $id) {
            $entry = $id === $pair->a?->id ? $pair->a : $pair->b;

            if ($entry === null) {
                continue;
            }

            $allowed = $plan->disposition === Settings::DISPOSITION_TRASH
                ? $elements->canDelete($entry, $user)
                : $elements->canSave($entry, $user);

            if (!$allowed) {
                throw new InvalidArgumentException(Craft::t('glue', 'You are not allowed to retire “{title}”.', [
                    'title' => $entry->title ?? (string)$entry->id,
                ]));
            }
        }
    }
}
