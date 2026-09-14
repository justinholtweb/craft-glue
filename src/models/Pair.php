<?php

declare(strict_types=1);

namespace justinholtweb\glue\models;

use craft\base\Model;
use craft\elements\Entry;

/**
 * Two entries, loaded and checked, plus everything that is true of the two of them together.
 *
 * Exists so the merge screen, the preview endpoint and the console command all ask the same
 * questions in the same order and get the same answers — including the awkward ones, like "these
 * two entries have no site in common", which is a merge that cannot be done at all and is much
 * better to discover here than halfway through a transaction.
 */
class Pair extends Model
{
    public ?Entry $a = null;
    public ?Entry $b = null;

    /** The site both entries were loaded in. */
    public ?int $siteId = null;

    /** Sites both entries exist in. @var int[] */
    public array $sharedSiteIds = [];

    /** @var FieldPair[] Keyed by handle, in the target layout's order. */
    public array $fields = [];

    /**
     * Fields that exist on a source but have nowhere to go in the target entry type.
     *
     * @var array<string, string> handle => label
     */
    public array $droppedFromA = [];
    public array $droppedFromB = [];

    /** Things worth saying before the merge runs. @var string[] */
    public array $warnings = [];

    public function entry(string $side): ?Entry
    {
        return $side === Strategy::B ? $this->b : $this->a;
    }

    /** @return FieldPair[] The rows a person actually has to decide. */
    public function contestedFields(): array
    {
        return array_filter($this->fields, fn(FieldPair $pair) => $pair->contested());
    }
}
