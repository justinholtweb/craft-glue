<?php

declare(strict_types=1);

namespace justinholtweb\glue\models;

use craft\base\ElementInterface;
use craft\base\Model;

/**
 * A field value rendered small enough to sit in a table cell.
 *
 * The merge screen is a decision screen: a person is choosing between two values they cannot see
 * in full, so the preview has to say the one thing that distinguishes them. For a rich text field
 * that is the opening words; for a Matrix field it is how many blocks and of what type; for a
 * relation field it is *which elements*, which is why this carries real elements rather than a
 * string — a chip the editor recognises beats a count they have to trust.
 */
class Preview extends Model
{
    /** One line. Always set, even when the value is empty. */
    public string $summary = '';

    /** Elements to render as chips, when the value is a relation. @var ElementInterface[] */
    public array $elements = [];

    /** A longer excerpt, already truncated and plain. */
    public ?string $body = null;

    /** Whether the field has nothing in it. Drives the "only show differences" filter. */
    public bool $empty = true;

    /**
     * A stable fingerprint of the underlying value, for "these two are identical" checks.
     *
     * Hashed rather than kept, because the values it is built from include whole Matrix trees
     * and the only question ever asked of it is equality.
     */
    public ?string $digest = null;

    public static function of(string $summary, bool $empty = false): self
    {
        return new self(['summary' => $summary, 'empty' => $empty]);
    }
}
