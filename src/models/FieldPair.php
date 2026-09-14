<?php

declare(strict_types=1);

namespace justinholtweb\glue\models;

use craft\base\FieldInterface;
use craft\base\Model;

/**
 * One field, seen from both sides.
 *
 * Built against the **target's** field layout, not either source's, because the target decides
 * what fields the merged entry can even have. A field that only exists on A is not a row here —
 * it is an entry in {@see Pair::$droppedFromA}, which is a warning, not a choice.
 */
class FieldPair extends Model
{
    /** The handle as the target's layout knows it, which is the handle `getFieldValue()` wants. */
    public string $handle = '';

    public string $label = '';

    /** The field instance from the target's layout. Instance overrides already applied. */
    public ?FieldInterface $field = null;

    /** Whether each source can supply this field at all. */
    public bool $inA = false;
    public bool $inB = false;

    /** Serialized values — the form everything downstream works in. */
    public mixed $aValue = null;
    public mixed $bValue = null;

    public ?Preview $aPreview = null;
    public ?Preview $bPreview = null;

    /** One of the `Strategy::KIND_*` values, or null when “Both” is meaningless here. */
    public ?string $combineKind = null;

    /** Why this row cannot do what it looks like it should. Rendered next to the choice. */
    public ?string $warning = null;

    /**
     * What “Both” would do to this field, in a sentence.
     *
     * Resolved here rather than in the template because it depends on the separators the merge
     * will actually use, and a tooltip that describes a different separator from the one that
     * runs is worse than no tooltip.
     */
    public ?string $combineHint = null;

    /** The strategy the screen opens with, from the seeding rule. */
    public string $suggested = Strategy::A;

    public function bothEmpty(): bool
    {
        return ($this->aPreview?->empty ?? true) && ($this->bPreview?->empty ?? true);
    }

    /**
     * Whether the two sides hold the same thing, so the choice does not matter.
     *
     * Worth its own method because it is what collapses a forty-field entry type down to the
     * four rows a person actually has to think about.
     */
    public function identical(): bool
    {
        return $this->aPreview?->digest !== null
            && $this->aPreview->digest === $this->bPreview?->digest;
    }

    /** Whether this row needs a human — the two sides differ and both have something to say. */
    public function contested(): bool
    {
        return !$this->bothEmpty() && !$this->identical();
    }

    /**
     * @return string[] The strategies this row will actually accept.
     */
    public function availableStrategies(): array
    {
        $available = [];

        if ($this->inA) {
            $available[] = Strategy::A;
        }

        if ($this->inB) {
            $available[] = Strategy::B;
        }

        if ($this->combineKind !== null && $this->inA && $this->inB) {
            $available[] = Strategy::COMBINE;
        }

        $available[] = Strategy::BLANK;

        return $available;
    }
}
