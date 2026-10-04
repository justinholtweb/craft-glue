<?php

declare(strict_types=1);

namespace justinholtweb\glue\services;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\base\FieldInterface;
use craft\db\mysql\Schema as MysqlSchema;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Matrix;
use craft\fields\Table;
use craft\fields\Url;
use craft\helpers\Json;
use justinholtweb\glue\models\FieldPair;
use justinholtweb\glue\models\MergePlan;
use justinholtweb\glue\models\Strategy;
use yii\base\Component;
use yii\db\Schema;

/**
 * Decides what “Both” means for a given field, and does it.
 *
 * ## Everything here works in serialized values
 *
 * Craft's own way of copying a field from one element to another is
 * `Field::copyValue()`, which is exactly `serializeValue($from)` → `setFieldValue($to)`. Glue
 * uses that round trip for every field and every strategy rather than calling `copyValue()`,
 * for two reasons:
 *
 * 1. `Matrix::copyValue()` is deliberately a **no-op** — it defers to `afterElementPropagate()`,
 *    which only does anything when the target is a duplicate of the source. Called across two
 *    unrelated entries it silently copies nothing, and a merge that silently drops the Matrix
 *    field is the worst possible bug for this plugin to have.
 * 2. Combining needs both values in a form that can be taken apart, and serialized is the only
 *    such form that every field type agrees on.
 *
 * The round trip also gets nested entries right for free. `Matrix::normalizeValue()` looks the
 * incoming block IDs up **against the target's own nested entries**; IDs belonging to some other
 * owner are not found, so they are built as new blocks rather than stolen from the entry they
 * came from. That one fact is what makes “A's blocks, then B's” safe: B keeps its blocks.
 *
 * ## Deciding the kind
 *
 * Known core classes are matched first, then the shape of the serialized values themselves. The
 * shape fallback is what lets a third-party relation field get a union and a third-party rich
 * text field get a join without Glue having heard of either — the cost is that a field with an
 * unusual serialization gets no “Both”, which is a missing option rather than a wrong answer.
 */
class Strategies extends Component
{
    /**
     * Fields whose serialized value is a string but for which joining two of them is nonsense.
     *
     * `Url` is the only core offender: it does not override `dbType()`, so it reads as a long
     * text field, and "https://a.example\n\nhttps://b.example" is not a URL.
     */
    private const NOT_TEXT = [Url::class];

    /**
     * What "Both" would do to this field, or null if it would do nothing sensible.
     *
     * Takes the values as well as the field because a third-party field can only be classified
     * by what it serializes to, and because a field that is empty on both sides has no shape to
     * read — that case returns a kind anyway when the class is known, so the option does not
     * appear and disappear as an editor types.
     */
    public function kindFor(FieldInterface $field, mixed $aValue, mixed $bValue): ?string
    {
        if ($field instanceof Matrix) {
            return Strategy::KIND_BLOCKS;
        }

        // Addresses, ContentBlock, CKEditor-with-entries: they hold nested elements but none of
        // them has Matrix's `sortOrder`/`entries` input shape, so there is no way to say "A's
        // then B's" that is guaranteed to copy rather than re-own. Refused rather than guessed.
        //
        // Except rich text that holds none. CKEditor implements the interface because it *can*
        // embed entries, so every CKEditor field is a container whether or not it has one —
        // refusing them all would refuse the most common field there is. Prose with no
        // `<craft-entry>` in it is just prose, and joins like any other text.
        if ($field instanceof ElementContainerFieldInterface) {
            return $this->isTextField($field) && !$this->embedsEntries($aValue) && !$this->embedsEntries($bValue)
                ? Strategy::KIND_TEXT
                : Strategy::KIND_NONE;
        }

        if ($field instanceof Table) {
            return Strategy::KIND_ROWS;
        }

        if ($field instanceof BaseRelationField) {
            return Strategy::KIND_LIST;
        }

        if ($field instanceof BaseOptionsField) {
            // Dropdowns and radio buttons serialize to one value; checkboxes and multi-selects
            // serialize to a list. The list ones are the multi ones — no protected property to
            // read, and none needed.
            return (is_array($aValue) || is_array($bValue)) ? Strategy::KIND_LIST : Strategy::KIND_NONE;
        }

        if ($this->isTextField($field)) {
            return Strategy::KIND_TEXT;
        }

        return $this->kindFromShape($aValue, $bValue);
    }

    /**
     * Whether joining two of this field's values with a separator makes sense.
     *
     * The test is the field's storage type, not its class, so it answers correctly for rich text
     * fields Glue has never heard of. A long text column is where prose lives; a `string`,
     * `decimal`, `boolean` or `datetime` column is not, and neither is a field storing several
     * columns at once (`Link`, `Date`) whose `dbType()` is an array.
     */
    private function isTextField(FieldInterface $field): bool
    {
        if (in_array($field::class, self::NOT_TEXT, true)) {
            return false;
        }

        try {
            $type = $field::dbType();
        } catch (\Throwable) {
            return false;
        }

        if (!is_string($type)) {
            return false;
        }

        return $type === Schema::TYPE_TEXT
            || $type === MysqlSchema::TYPE_LONGTEXT
            || $type === MysqlSchema::TYPE_MEDIUMTEXT
            || str_starts_with($type, Schema::TYPE_TEXT);
    }

    /**
     * Whether a serialized rich text value carries a nested entry, which CKEditor writes as a
     * `<craft-entry data-entry-id="…">` tag. Anything that is not a string carries none we can see.
     */
    private function embedsEntries(mixed $value): bool
    {
        return is_string($value) && stripos($value, '<craft-entry') !== false;
    }

    /**
     * Last resort: classify by what the two values look like.
     *
     * Only a value that is present can be read, so an empty side is ignored; if both sides are
     * empty there is nothing to classify and no combining to offer.
     */
    private function kindFromShape(mixed $aValue, mixed $bValue): ?string
    {
        $sample = $this->isEmptyValue($aValue) ? $bValue : $aValue;

        if (is_string($sample)) {
            return Strategy::KIND_TEXT;
        }

        if (!is_array($sample) || $sample === []) {
            return Strategy::KIND_NONE;
        }

        if (!array_is_list($sample)) {
            // Keyed by something meaningful — a Money field's value/currency, a Link field's
            // parts. Appending two of those produces a value the field cannot read back.
            return Strategy::KIND_NONE;
        }

        return is_array(reset($sample)) ? Strategy::KIND_ROWS : Strategy::KIND_LIST;
    }

    /**
     * The serialized value one field should end up with.
     *
     * @return mixed A serialized value, ready for `setFieldValue()`.
     */
    public function resolve(FieldPair $pair, string $strategy, MergePlan $plan): mixed
    {
        return match ($strategy) {
            Strategy::A => $pair->inA ? $pair->aValue : null,
            Strategy::B => $pair->inB ? $pair->bValue : null,
            Strategy::COMBINE => $this->combine($pair, $plan),
            default => null,
        };
    }

    private function combine(FieldPair $pair, MergePlan $plan): mixed
    {
        if ($pair->combineKind === null) {
            // The screen should never offer this, but a posted plan or a replayed preset can ask
            // for it. Falling back to A is the choice that loses nothing.
            return $pair->inA ? $pair->aValue : $pair->bValue;
        }

        $a = $pair->inA ? $pair->aValue : null;
        $b = $pair->inB ? $pair->bValue : null;

        if ($this->isEmptyValue($a)) {
            return $b;
        }

        if ($this->isEmptyValue($b)) {
            return $a;
        }

        return match ($pair->combineKind) {
            Strategy::KIND_LIST => $this->combineList($a, $b),
            Strategy::KIND_ROWS => $this->combineRows($a, $b, $plan->dedupeRows),
            Strategy::KIND_BLOCKS => $this->combineBlocks($a, $b),
            Strategy::KIND_TEXT => $this->combineText($a, $b, $pair, $plan),
            default => $a,
        };
    }

    /**
     * A's list, then whatever of B's is not already in it.
     *
     * Strict comparison, so the integer element ID 12 and the string "12" are the same relation —
     * which they are, and which `in_array()` without the flag would get right by accident and
     * `array_unique()` would get right by string coercion. Both of those also reorder or reindex;
     * a relation field's order is content, so neither is used.
     *
     * @return array<int, mixed>
     */
    private function combineList(mixed $a, mixed $b): array
    {
        $a = is_array($a) ? array_values($a) : [$a];
        $b = is_array($b) ? array_values($b) : [$b];

        $combined = $a;

        foreach ($b as $value) {
            if (!$this->containsValue($combined, $value)) {
                $combined[] = $value;
            }
        }

        return $combined;
    }

    /**
     * @param array<int, mixed> $haystack
     */
    private function containsValue(array $haystack, mixed $needle): bool
    {
        foreach ($haystack as $value) {
            if ($value === $needle) {
                return true;
            }

            // Element IDs arrive as ints from one field instance and as numeric strings from
            // another depending on which side of the query they came through.
            if (is_scalar($value) && is_scalar($needle) && (string)$value === (string)$needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * A's rows, then B's, optionally dropping a row identical to one already above it.
     *
     * `array_merge()` is right here and wrong two methods down: these are lists, so renumbering
     * the keys is the intended result.
     *
     * @return array<int, mixed>
     */
    private function combineRows(mixed $a, mixed $b, bool $dedupe): array
    {
        $rows = array_merge(
            is_array($a) ? array_values($a) : [],
            is_array($b) ? array_values($b) : [],
        );

        if (!$dedupe) {
            return $rows;
        }

        $seen = [];
        $kept = [];

        foreach ($rows as $row) {
            $key = json_encode($row);

            if ($key !== false && isset($seen[$key])) {
                continue;
            }

            if ($key !== false) {
                $seen[$key] = true;
            }

            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * A's blocks, then B's, in Matrix's own input shape.
     *
     * Matrix serializes to a map keyed by block ID. `array_merge()` on that map would **renumber
     * the keys**, because the IDs are numeric strings — turning every block into a "new" block
     * named 0, 1, 2, and so losing the identity of the target's existing blocks on a merge into
     * A. The `+` union preserves them. The IDs cannot collide: they are element IDs from two
     * different entries.
     *
     * `sortOrder` is given explicitly rather than left to the key order, so the ordering is a
     * decision in this method rather than an accident of PHP's array semantics.
     *
     * @return array{sortOrder: array<int, int|string>, entries: array<int|string, mixed>}
     */
    private function combineBlocks(mixed $a, mixed $b): array
    {
        $a = is_array($a) ? $a : [];
        $b = is_array($b) ? $b : [];

        $entries = $a + $b;

        return [
            'sortOrder' => array_merge(array_keys($a), array_keys(array_diff_key($b, $a))),
            'entries' => $entries,
        ];
    }

    /**
     * A's text, the separator, then B's.
     *
     * Rich text gets its own separator. Two blocks of prose want a blank line between them; two
     * blocks of block-level HTML already have their spacing and want a single newline, because
     * `</p>\n\n<p>` renders identically to `</p>\n<p>` and only the diff can tell them apart.
     */
    private function combineText(mixed $a, mixed $b, FieldPair $pair, MergePlan $plan): string
    {
        $separator = $this->looksLikeHtml((string)$a) || $this->looksLikeHtml((string)$b)
            ? $plan->richTextSeparator
            : $plan->textSeparator;

        return rtrim((string)$a) . $separator . ltrim((string)$b);
    }

    private function looksLikeHtml(string $value): bool
    {
        return (bool)preg_match('/<(p|div|h[1-6]|ul|ol|li|blockquote|figure|table|section|article)\b/i', $value);
    }

    /**
     * Whether a serialized value counts as "nothing in this field".
     *
     * `0`, `'0'` and `false` are values, not emptiness — a Number field holding zero and a
     * Lightswitch that is off are both things somebody chose. `empty()` disagrees, which is why
     * it is not used.
     */
    public function isEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value)) {
            // Matrix's delta shape is "empty" when it carries no blocks, whichever key it used.
            if (isset($value['sortOrder']) && $value['sortOrder'] === []) {
                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * A digest of a serialized value, for "these two sides are the same" checks.
     *
     * Matrix values are deliberately digested **without** their block IDs: two entries with the
     * same three blocks holding the same content are the same content, and comparing the IDs
     * would report every Matrix field on every pair as contested, which is exactly the noise the
     * comparison exists to remove.
     */
    public function digest(FieldInterface $field, mixed $value): string
    {
        if ($field instanceof Matrix && is_array($value)) {
            $value = array_values(array_map(
                fn($block) => is_array($block) ? array_diff_key($block, ['collapsed' => null]) : $block,
                $value['entries'] ?? $value,
            ));
        }

        return md5((string)json_encode($this->normalizeForDigest($value)));
    }

    private function normalizeForDigest(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn($item) => $this->normalizeForDigest($item), $value);
        }

        if (is_scalar($value)) {
            // "12" and 12 are the same relation; 1 and true are the same lightswitch.
            return is_bool($value) ? (int)$value : (string)$value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value === null) {
            return null;
        }

        // Anything else a field serializes to is an object. asText() would throw on one that is
        // not a string or date, and a digest must never stop a merge screen from loading.
        return $value instanceof \Stringable ? (string)$value : Json::encode($value);
    }
}
