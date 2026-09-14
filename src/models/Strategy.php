<?php

declare(strict_types=1);

namespace justinholtweb\glue\models;

use Craft;

/**
 * The four things that can happen to one field in a merge, and the kinds of combining.
 *
 * Kept as a value vocabulary rather than an enum so it survives a round trip through project
 * config, a saved preset, a query string and a console option without a cast at every boundary.
 */
class Strategy
{
    /** Take the value from the first entry. */
    public const A = 'a';

    /** Take the value from the second entry. */
    public const B = 'b';

    /** Take both, in the way {@see self::kinds()} says this field can be combined. */
    public const COMBINE = 'combine';

    /** Take neither — the merged entry gets an empty field. */
    public const BLANK = 'blank';

    public const ALL = [self::A, self::B, self::COMBINE, self::BLANK];

    // ------------------------------------------------------------------ combine kinds

    /** Union of a list of scalars: relations, tags, checkboxes, multi-selects. A's order first. */
    public const KIND_LIST = 'list';

    /** Append rows: table fields, and anything else serialising to a list of arrays. */
    public const KIND_ROWS = 'rows';

    /** Append nested entries: Matrix, and anything else built on nested elements. */
    public const KIND_BLOCKS = 'blocks';

    /** Join two strings with a separator: plain text, and rich text. */
    public const KIND_TEXT = 'text';

    /** The field holds something with no meaningful "both" — a date, a number, a switch. */
    public const KIND_NONE = null;

    public static function isValid(string $strategy): bool
    {
        return in_array($strategy, self::ALL, true);
    }

    public static function label(string $strategy): string
    {
        return match ($strategy) {
            self::A => Craft::t('glue', 'A'),
            self::B => Craft::t('glue', 'B'),
            self::COMBINE => Craft::t('glue', 'Both'),
            self::BLANK => Craft::t('glue', 'Neither'),
            default => $strategy,
        };
    }

    /**
     * What "Both" does, in a sentence, for the merge screen's tooltip.
     *
     * Editors guess at "Both" and guess wrong — half expect a union and half expect a
     * concatenation — so the screen says which one this particular field will get.
     */
    public static function combineDescription(?string $kind, string $separator = ''): string
    {
        return match ($kind) {
            self::KIND_LIST => Craft::t('glue', 'Both lists, A’s order first, duplicates dropped.'),
            self::KIND_ROWS => Craft::t('glue', 'A’s rows, then B’s. Rows identical to an earlier row are dropped.'),
            self::KIND_BLOCKS => Craft::t('glue', 'A’s blocks, then B’s. B’s blocks are copied, not moved.'),
            self::KIND_TEXT => Craft::t('glue', 'A’s text, then {separator}, then B’s.', [
                'separator' => $separator === ''
                    ? Craft::t('glue', 'the separator')
                    : '“' . trim(str_replace("\n", '⏎', $separator)) . '”',
            ]),
            default => Craft::t('glue', 'This field cannot be combined — pick A or B.'),
        };
    }
}
