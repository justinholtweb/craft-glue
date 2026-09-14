<?php

declare(strict_types=1);

namespace justinholtweb\glue\models;

use craft\base\Model;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Plugin settings.
 *
 * Nothing here is `required`. A settings model with a required attribute cannot be saved by the
 * installer and the plugin then fails to install at all — see `[[craft-plugin-gotchas]]`. Every
 * default below is chosen so that the first merge somebody runs, before they have opened this
 * screen, is the cautious one: sources are left alone, nothing is rewired, and the merge is
 * recorded.
 */
class Settings extends Model
{
    // What the merge screen opens with ------------------------------------------------

    public const TARGET_NEW = 'new';
    public const TARGET_A = 'a';
    public const TARGET_B = 'b';

    public const SEED_A = 'a';
    public const SEED_B = 'b';
    public const SEED_NON_EMPTY = 'nonEmpty';
    public const SEED_COMBINE = 'combine';

    public const DISPOSITION_LEAVE = 'leave';
    public const DISPOSITION_DISABLE = 'disable';
    public const DISPOSITION_TRASH = 'trash';

    /**
     * Where a merge writes by default: a third entry, or one of the two sources.
     *
     * `new` is the default because it is the only one of the three that cannot lose anything —
     * both sources survive untouched until you say otherwise.
     */
    public string $defaultTarget = self::TARGET_NEW;

    /**
     * How each field's choice is pre-selected when the merge screen opens.
     *
     * `nonEmpty` — take A, unless A is empty and B is not. It is the default because the single
     * commonest real merge is "these two are the same entry, one of them was filled in further",
     * and pre-selecting it turns that merge into one click instead of forty.
     */
    public string $defaultStrategy = self::SEED_NON_EMPTY;

    /** What happens to the two sources once the merge has saved. */
    public string $defaultDisposition = self::DISPOSITION_LEAVE;

    // How "Both" behaves --------------------------------------------------------------

    /** Put between two plain-text values joined by “Both”. */
    public string $textSeparator = "\n\n";

    /**
     * Put between two rich-text values joined by “Both”.
     *
     * A newline, not a blank line: rich text is already block markup, and `</p>\n<p>` renders as
     * a paragraph break while `</p>\n\n<p>` renders as exactly the same thing plus a diff.
     */
    public string $richTextSeparator = "\n";

    /** Drop a combined table row that is identical to a row already above it. */
    public bool $dedupeRows = true;

    // What happens to what pointed at the sources --------------------------------------

    /**
     * Repoint relations that targeted a retired source at the merged entry. **Pro.**
     *
     * Off by default even on Pro. Rewiring edits other people's entries, and a setting that
     * quietly does that on somebody's first merge is a setting that gets the plugin uninstalled.
     */
    public bool $rewireRelations = false;

    /**
     * Above this many referencing elements, rewiring goes to the queue instead of the request.
     *
     * Each rewire is a full element save — content, relations, search index, revision — so a few
     * dozen is a slow page and a few thousand is a timed-out one.
     */
    public int $rewireThreshold = 25;

    /** Merge every site the two sources share, not just the one being edited. **Pro.** */
    public bool $allSites = false;

    // Bookkeeping ----------------------------------------------------------------------

    /** Record every merge in `glue_merges`. */
    public bool $logMerges = true;

    /** Merges to keep in the history. Older rows are pruned by garbage collection. */
    public int $historyLimit = 500;

    /**
     * Saved field-choice sets, keyed by handle. **Pro.**
     *
     * Kept in the settings rather than a table because a preset names entry types and field
     * handles — it is a statement about the content model, so it belongs in project config and
     * deploys with the model it describes.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $presets = [];

    public function rules(): array
    {
        return [
            [['defaultTarget'], 'in', 'range' => [self::TARGET_NEW, self::TARGET_A, self::TARGET_B]],
            [['defaultStrategy'], 'in', 'range' => [self::SEED_A, self::SEED_B, self::SEED_NON_EMPTY, self::SEED_COMBINE]],
            [['defaultDisposition'], 'in', 'range' => [self::DISPOSITION_LEAVE, self::DISPOSITION_DISABLE, self::DISPOSITION_TRASH]],
            [['rewireThreshold'], 'integer', 'min' => 0],
            [['historyLimit'], 'integer', 'min' => 0],
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public static function targetOptions(): array
    {
        return [
            ['label' => \Craft::t('glue', 'A new entry'), 'value' => self::TARGET_NEW],
            ['label' => \Craft::t('glue', 'The first entry'), 'value' => self::TARGET_A],
            ['label' => \Craft::t('glue', 'The second entry'), 'value' => self::TARGET_B],
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public static function seedOptions(): array
    {
        return [
            ['label' => \Craft::t('glue', 'Whichever one has a value'), 'value' => self::SEED_NON_EMPTY],
            ['label' => \Craft::t('glue', 'Always the first entry'), 'value' => self::SEED_A],
            ['label' => \Craft::t('glue', 'Always the second entry'), 'value' => self::SEED_B],
            ['label' => \Craft::t('glue', 'Both, where the field allows it'), 'value' => self::SEED_COMBINE],
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public static function dispositionOptions(): array
    {
        return [
            ['label' => \Craft::t('glue', 'Leave them alone'), 'value' => self::DISPOSITION_LEAVE],
            ['label' => \Craft::t('glue', 'Disable them'), 'value' => self::DISPOSITION_DISABLE],
            ['label' => \Craft::t('glue', 'Move them to the trash'), 'value' => self::DISPOSITION_TRASH],
        ];
    }

    /**
     * Casts HTTP strings onto typed properties before Yii assigns them.
     *
     * Every value off a CP form is a string, and a typed `int` property assigned `''` is a
     * TypeError inside Yii's own `setAttributes()` — a white screen on save, from a form that
     * looked fine. See `[[craft-plugin-gotchas]]`. A value that cannot be read at all leaves the
     * default in place; losing one setting is recoverable and fatalling the save is not.
     *
     * @param array<string, mixed>|mixed $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        parent::setAttributes(is_array($values) ? $this->normalize($values) : $values, $safeOnly);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalize(array $values): array
    {
        // The separators are the one place a CP form legitimately posts whitespace that matters,
        // and a `trim()` anywhere in the pipeline would quietly turn "\n\n" into "".
        foreach (['textSeparator', 'richTextSeparator'] as $key) {
            if (isset($values[$key]) && is_string($values[$key])) {
                $values[$key] = str_replace(["\r\n", "\r"], "\n", $values[$key]);
            }
        }

        if (isset($values['presets']) && !is_array($values['presets'])) {
            unset($values['presets']);
        }

        foreach ($values as $name => $value) {
            if (!property_exists($this, $name) || is_array($value)) {
                continue;
            }

            $cast = self::cast($name, $value);

            if ($cast === null) {
                unset($values[$name]);
                continue;
            }

            $values[$name] = $cast[0];
        }

        return $values;
    }

    /**
     * @return array{0: mixed}|null Null when the default should be kept.
     */
    private static function cast(string $property, mixed $value): ?array
    {
        $type = (new ReflectionProperty(self::class, $property))->getType();

        if (!$type instanceof ReflectionNamedType) {
            return [$value];
        }

        return match ($type->getName()) {
            // A lightswitch posts the string "0" when off, and `(bool)"0"` is already false; the
            // explicit "false" check is for `config/glue.php`, which people write by hand.
            'bool' => [(bool)$value && $value !== 'false'],
            'int' => is_numeric($value) ? [(int)$value] : null,
            'string' => is_scalar($value) ? [(string)$value] : null,
            default => [$value],
        };
    }
}
