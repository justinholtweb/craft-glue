<?php

declare(strict_types=1);

namespace justinholtweb\glue\models;

use craft\base\Model;
use craft\elements\Entry;

/**
 * What a merge did.
 *
 * Returned by both the real run and the dry run, with `$saved` telling them apart, so the
 * controller and the console command render one shape whichever they asked for.
 */
class MergeResult extends Model
{
    public ?Entry $entry = null;

    /** Whether anything was written. False for a dry run. */
    public bool $saved = false;

    /** Whether the merged entry is a third entry rather than one of the sources. */
    public bool $createdNew = false;

    /** The resolved value of each field, serialized, keyed by handle. @var array<string, mixed> */
    public array $values = [];

    /** Previews of the resolved values, for the screen's result column. @var array<string, Preview> */
    public array $previews = [];

    /** @var string[] */
    public array $warnings = [];

    /** Entry IDs that were disabled or trashed. @var int[] */
    public array $retired = [];

    /** How many elements had a relation repointed at the merged entry. */
    public int $rewired = 0;

    /** Whether the rewiring was handed to the queue instead of being done in the request. */
    public bool $rewireQueued = false;

    /** The `glue_merges` row, when one was written. */
    public ?int $logId = null;

    public function addWarning(string $warning): void
    {
        if (!in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }
}
