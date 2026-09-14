<?php

declare(strict_types=1);

namespace justinholtweb\glue\services;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\errors\InvalidFieldException;
use craft\helpers\ElementHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use justinholtweb\glue\models\FieldPair;
use justinholtweb\glue\models\MergePlan;
use justinholtweb\glue\models\Pair;
use justinholtweb\glue\models\Settings;
use justinholtweb\glue\models\Strategy;
use justinholtweb\glue\Plugin;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Loads two entries and works out what merging them would involve.
 *
 * Every question a merge screen, a preview request or a console merge asks is asked here, in one
 * order, so all three get the same answers — including the ones that mean "this merge cannot be
 * done", which are much cheaper to find now than halfway through a transaction.
 *
 * ## Fields are matched by handle, checked by identity
 *
 * Craft 5 lets a field layout override a field's handle per instance, so a handle is a *label*
 * and not an identity: two layouts can both have `summary` and mean two entirely different
 * fields. Glue lines the two sides up by handle — that is what an editor sees and what
 * `getFieldValue()` takes — and then checks whether the field underneath is really the same one.
 * Same field, silent. Same class, different field: allowed, with a warning, because copying a
 * plain text value into another plain text field is fine and refusing it would be pedantic.
 * Different class: not offered at all, because there is no sense in which a Matrix value can
 * become a date.
 */
class Pairs extends Component
{
    /**
     * Loads both entries in a site, canonical and editable.
     *
     * Canonical rather than whatever draft is open, because a merge writes a real entry and
     * merging somebody's half-finished autosave into a third entry is a surprise. Provisional
     * drafts are left exactly where they are.
     *
     * @throws InvalidArgumentException if either entry cannot be loaded in that site
     */
    public function load(int $aId, int $bId, ?int $siteId = null): Pair
    {
        if ($aId === $bId) {
            throw new InvalidArgumentException(Craft::t('glue', 'An entry cannot be merged with itself.'));
        }

        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        $a = $this->loadOne($aId, $siteId);
        $b = $this->loadOne($bId, $siteId);

        $pair = new Pair();
        $pair->a = $a;
        $pair->b = $b;
        $pair->siteId = $siteId;
        $pair->sharedSiteIds = $this->sharedSiteIds($a, $b);

        return $pair;
    }

    private function loadOne(int $id, int $siteId): Entry
    {
        $entry = Entry::find()
            ->id($id)
            ->siteId($siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->one();

        if (!$entry instanceof Entry) {
            throw new InvalidArgumentException(Craft::t('glue', 'Entry {id} could not be loaded in this site.', [
                'id' => $id,
            ]));
        }

        // Matrix blocks are entries too. They have an owner, a field and a sort order, none of
        // which a merge knows how to reassign, and merging two of them would quietly detach them
        // from the entries they belong to.
        if ($entry->fieldId !== null) {
            throw new InvalidArgumentException(Craft::t('glue', '“{title}” is a nested entry inside a field. Glue merges entries, not the blocks inside them.', [
                'title' => $entry->title ?? (string)$id,
            ]));
        }

        return $entry;
    }

    /**
     * Sites both entries exist in.
     *
     * Two entries in sections with no site in common cannot be merged across sites at all, and
     * the cross-site option has to say so rather than quietly merging one of them.
     *
     * @return int[]
     */
    public function sharedSiteIds(Entry $a, Entry $b): array
    {
        $sites = fn(Entry $entry) => array_column(ElementHelper::supportedSitesForElement($entry), 'siteId');

        return array_values(array_intersect($sites($a), $sites($b)));
    }

    /**
     * Fills in the field-by-field analysis for a pair, against the plan's target entry type.
     *
     * The target's layout is the one that matters: it decides what fields the merged entry can
     * hold at all. A field that only exists on a source is not a choice, it is a loss, and it
     * comes back in {@see Pair::$droppedFromA} so the screen can say so before anybody presses
     * the button.
     */
    public function inspect(Pair $pair, MergePlan $plan): Pair
    {
        $a = $pair->a;
        $b = $pair->b;

        if ($a === null || $b === null) {
            return $pair;
        }

        $entryType = $this->targetEntryType($pair, $plan);
        $layout = $entryType?->getFieldLayout();

        if ($layout === null) {
            $pair->warnings[] = Craft::t('glue', 'The target entry type has no field layout.');
            return $pair;
        }

        $strategies = Plugin::getInstance()->strategies;
        $previews = Plugin::getInstance()->previews;
        $settings = Plugin::getInstance()->getSettings();

        $aLayout = $a->getFieldLayout();
        $bLayout = $b->getFieldLayout();

        $fields = [];

        foreach ($layout->getCustomFieldElements() as $layoutElement) {
            try {
                $field = $layoutElement->getField();
            } catch (Throwable) {
                continue;
            }

            $handle = (string)$field->handle;

            if ($handle === '' || isset($fields[$handle])) {
                continue;
            }

            $fieldPair = new FieldPair();
            $fieldPair->handle = $handle;
            $fieldPair->label = $layoutElement->label ?? $field->name ?? $handle;
            $fieldPair->field = $field;

            [$aField, $aNote] = $this->matchField($aLayout, $handle, $field);
            [$bField, $bNote] = $this->matchField($bLayout, $handle, $field);

            $fieldPair->inA = $aField !== null;
            $fieldPair->inB = $bField !== null;
            $fieldPair->warning = $aNote ?? $bNote;

            $fieldPair->aValue = $aField !== null ? $this->serialize($a, $aField) : null;
            $fieldPair->bValue = $bField !== null ? $this->serialize($b, $bField) : null;

            $fieldPair->combineKind = $strategies->kindFor($field, $fieldPair->aValue, $fieldPair->bValue);
            $fieldPair->combineHint = Strategy::combineDescription(
                $fieldPair->combineKind,
                $fieldPair->combineKind === Strategy::KIND_TEXT ? $settings->textSeparator : '',
            );

            $fieldPair->aPreview = $previews->of($field, $fieldPair->aValue, $pair->siteId);
            $fieldPair->bPreview = $previews->of($field, $fieldPair->bValue, $pair->siteId);

            $fieldPair->suggested = $this->seed($fieldPair, $settings->defaultStrategy, $strategies);

            $fields[$handle] = $fieldPair;
        }

        $pair->fields = $fields;
        $pair->droppedFromA = $this->dropped($aLayout, $layout);
        $pair->droppedFromB = $this->dropped($bLayout, $layout);

        $this->addPairWarnings($pair, $plan, $entryType);

        return $pair;
    }

    /**
     * The entry type the merged entry will have.
     *
     * Merging into a source keeps that source's type — changing an existing entry's type as a
     * side effect of a merge would be a content-model edit wearing a merge's clothes.
     */
    public function targetEntryType(Pair $pair, MergePlan $plan): ?EntryType
    {
        if ($plan->target === Settings::TARGET_A) {
            return $pair->a?->getType();
        }

        if ($plan->target === Settings::TARGET_B) {
            return $pair->b?->getType();
        }

        if ($plan->entryTypeId !== null) {
            $entryType = Craft::$app->getEntries()->getEntryTypeById($plan->entryTypeId);

            if ($entryType !== null) {
                return $entryType;
            }
        }

        return $pair->a?->getType();
    }

    /**
     * Finds the same field on a source layout, and says how confident that match is.
     *
     * @return array{0: FieldInterface|null, 1: string|null}
     */
    private function matchField(?FieldLayout $layout, string $handle, FieldInterface $target): array
    {
        $candidate = $layout?->getFieldByHandle($handle);

        if ($candidate === null) {
            return [null, null];
        }

        if ($candidate->uid === $target->uid) {
            return [$candidate, null];
        }

        if ($candidate::class === $target::class) {
            return [$candidate, Craft::t('glue', 'The two entry types use different fields with this handle. They are the same kind of field, so the value can still be copied — check the result.')];
        }

        return [null, Craft::t('glue', 'The two entry types use different fields with this handle, of different kinds. Glue cannot copy between them.')];
    }

    /**
     * A field's value in the form everything downstream works in.
     *
     * Serialized with the **source's own** field instance, not the target's: instance settings
     * such as a Matrix field's entry types belong to the layout the value was stored under, and
     * serializing through the wrong instance is how a block quietly loses its type.
     */
    private function serialize(Entry $entry, FieldInterface $field): mixed
    {
        try {
            return $field->serializeValue($entry->getFieldValue((string)$field->handle), $entry);
        } catch (InvalidFieldException | Throwable $e) {
            Craft::warning(sprintf(
                'Could not read the “%s” field off entry %d: %s',
                $field->handle,
                (int)$entry->id,
                $e->getMessage(),
            ), Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /**
     * Handles a source has that the target has nowhere to put.
     *
     * @return array<string, string> handle => label
     */
    private function dropped(?FieldLayout $from, FieldLayout $to): array
    {
        if ($from === null) {
            return [];
        }

        $targetHandles = [];

        foreach ($to->getCustomFields() as $field) {
            $targetHandles[(string)$field->handle] = true;
        }

        $dropped = [];

        foreach ($from->getCustomFields() as $field) {
            $handle = (string)$field->handle;

            if ($handle !== '' && !isset($targetHandles[$handle])) {
                $dropped[$handle] = (string)($field->name ?: $handle);
            }
        }

        return $dropped;
    }

    /**
     * Which side a field's choice starts on.
     *
     * The default is "whichever one has a value", because the commonest real merge by a distance
     * is two records of the same thing where one was filled in further, and pre-selecting that
     * turns a forty-field entry type into four decisions instead of forty.
     */
    private function seed(FieldPair $pair, string $rule, Strategies $strategies): string
    {
        $aEmpty = $strategies->isEmptyValue($pair->aValue) || !$pair->inA;
        $bEmpty = $strategies->isEmptyValue($pair->bValue) || !$pair->inB;

        if (!$pair->inA && !$pair->inB) {
            return Strategy::BLANK;
        }

        if (!$pair->inA) {
            return Strategy::B;
        }

        if (!$pair->inB) {
            return Strategy::A;
        }

        if ($rule === Settings::SEED_COMBINE && $pair->combineKind !== null && !$aEmpty && !$bEmpty) {
            return Strategy::COMBINE;
        }

        if ($rule === Settings::SEED_A) {
            return Strategy::A;
        }

        if ($rule === Settings::SEED_B) {
            return Strategy::B;
        }

        // nonEmpty, and the fallback for combine when combining is not on offer.
        if ($aEmpty && !$bEmpty) {
            return Strategy::B;
        }

        return Strategy::A;
    }

    private function addPairWarnings(Pair $pair, MergePlan $plan, ?EntryType $entryType): void
    {
        $a = $pair->a;
        $b = $pair->b;

        if ($a === null || $b === null) {
            return;
        }

        if ($a->getType()->id !== $b->getType()->id) {
            $pair->warnings[] = Craft::t('glue', 'These entries are different entry types — {a} and {b}. The merged entry will be {target}.', [
                'a' => $a->getType()->name,
                'b' => $b->getType()->name,
                'target' => $entryType?->name ?? '?',
            ]);
        }

        if ($a->sectionId !== $b->sectionId) {
            $pair->warnings[] = Craft::t('glue', 'These entries are in different sections — {a} and {b}.', [
                'a' => $a->getSection()?->name ?? '?',
                'b' => $b->getSection()?->name ?? '?',
            ]);
        }

        if ($pair->droppedFromA !== []) {
            $pair->warnings[] = Craft::t('glue', 'The first entry has {n, plural, =1{a field} other{# fields}} the merged entry cannot hold: {fields}.', [
                'n' => count($pair->droppedFromA),
                'fields' => implode(', ', $pair->droppedFromA),
            ]);
        }

        if ($pair->droppedFromB !== []) {
            $pair->warnings[] = Craft::t('glue', 'The second entry has {n, plural, =1{a field} other{# fields}} the merged entry cannot hold: {fields}.', [
                'n' => count($pair->droppedFromB),
                'fields' => implode(', ', $pair->droppedFromB),
            ]);
        }

        if ($plan->allSites && count($pair->sharedSiteIds) < 2) {
            $pair->warnings[] = Craft::t('glue', 'These entries have no second site in common, so “every site” will merge one site.');
        }
    }
}
