<?php

declare(strict_types=1);

namespace justinholtweb\glue\models;

use craft\base\Model;

/**
 * Everything a merge needs to know, and nothing about how to do it.
 *
 * A plan is the unit that travels: it is what the merge screen posts, what a preset stores, what
 * the console command builds from its options, and what gets written into the history row so a
 * merge can be read back a year later. Keeping it a dumb value object is what lets the CP, the
 * console and a replayed preset all run through one {@see \justinholtweb\glue\services\Merger}
 * rather than three near-copies that drift.
 */
class MergePlan extends Model
{
    /** More field choices than any real layout has; the cap on what a request may post. */
    public const MAX_CHOICES = 500;

    public const TITLE_A = 'a';
    public const TITLE_B = 'b';
    public const TITLE_CUSTOM = 'custom';

    public const SLUG_A = 'a';
    public const SLUG_B = 'b';
    public const SLUG_AUTO = 'auto';
    public const SLUG_CUSTOM = 'custom';

    public const AUTHOR_A = 'a';
    public const AUTHOR_B = 'b';
    public const AUTHOR_CURRENT = 'current';

    public const DATE_A = 'a';
    public const DATE_B = 'b';
    public const DATE_EARLIEST = 'earliest';
    public const DATE_NOW = 'now';

    public const STATUS_A = 'a';
    public const STATUS_B = 'b';
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public const PARENT_A = 'a';
    public const PARENT_B = 'b';
    public const PARENT_NONE = 'none';

    /** The two entries being merged, canonical IDs. */
    public ?int $aId = null;
    public ?int $bId = null;

    /** The site the merge is being performed in. With `allSites`, the site it starts from. */
    public ?int $siteId = null;

    /** `new`, `a` or `b` — see {@see Settings::TARGET_NEW}. */
    public string $target = Settings::TARGET_NEW;

    /** Where a new entry is created. Ignored when merging into a source. */
    public ?int $sectionId = null;
    public ?int $entryTypeId = null;

    /**
     * Strategy per field, keyed by the field's handle in the target's layout.
     *
     * A handle missing from this map is not the same as a handle set to `blank`: missing means
     * "the plan has no opinion", which the merger resolves with the seeding rule, and `blank`
     * means somebody looked at the field and said no. Presets rely on the difference — a preset
     * written for one entry type replayed on another should leave the unknown fields to the
     * seeding rule rather than silently emptying them.
     *
     * @var array<string, string>
     */
    public array $choices = [];

    // The attributes, which are not fields and do not behave like them --------------------

    public string $titleFrom = self::TITLE_A;
    public ?string $title = null;

    public string $slugFrom = self::SLUG_A;
    public ?string $slug = null;

    public string $authorFrom = self::AUTHOR_A;
    public string $postDateFrom = self::DATE_EARLIEST;
    public string $statusFrom = self::STATUS_A;
    public string $parentFrom = self::PARENT_A;

    // What happens afterwards -------------------------------------------------------------

    public string $disposition = Settings::DISPOSITION_LEAVE;
    public bool $rewire = false;
    public bool $allSites = false;

    /**
     * Resolved at plan time, not read from the settings at merge time.
     *
     * A history row that says "combined with the separator in the settings" is worthless once
     * somebody changes the settings, so the plan carries the separators it actually used.
     */
    public string $textSeparator = "\n\n";
    public string $richTextSeparator = "\n";
    public bool $dedupeRows = true;

    /** The preset this plan was seeded from, for the history row. */
    public ?string $preset = null;

    public function rules(): array
    {
        return [
            [['aId', 'bId', 'siteId'], 'required'],
            [['aId', 'bId', 'siteId', 'sectionId', 'entryTypeId'], 'integer'],
            [['target'], 'in', 'range' => [Settings::TARGET_NEW, Settings::TARGET_A, Settings::TARGET_B]],
            [['disposition'], 'in', 'range' => [Settings::DISPOSITION_LEAVE, Settings::DISPOSITION_DISABLE, Settings::DISPOSITION_TRASH]],
            [['titleFrom'], 'in', 'range' => [self::TITLE_A, self::TITLE_B, self::TITLE_CUSTOM]],
            [['slugFrom'], 'in', 'range' => [self::SLUG_A, self::SLUG_B, self::SLUG_AUTO, self::SLUG_CUSTOM]],
            [['authorFrom'], 'in', 'range' => [self::AUTHOR_A, self::AUTHOR_B, self::AUTHOR_CURRENT]],
            [['postDateFrom'], 'in', 'range' => [self::DATE_A, self::DATE_B, self::DATE_EARLIEST, self::DATE_NOW]],
            [['statusFrom'], 'in', 'range' => [self::STATUS_A, self::STATUS_B, self::STATUS_ENABLED, self::STATUS_DISABLED]],
            [['parentFrom'], 'in', 'range' => [self::PARENT_A, self::PARENT_B, self::PARENT_NONE]],
            [['bId'], 'compare', 'compareAttribute' => 'aId', 'operator' => '!=',
                'message' => \Craft::t('glue', 'An entry cannot be merged with itself.'), ],
            [['choices'], 'validateChoices'],
        ];
    }

    public function validateChoices(): void
    {
        // The @var above is what a valid plan holds; this is the check that makes it true, so it
        // has to treat the posted value as anything at all.
        /** @var array<mixed, mixed> $posted */
        $posted = $this->choices;

        // A plan has one choice per field. Far more than any layout holds is not a plan.
        if (count($posted) > self::MAX_CHOICES) {
            $this->addError('choices', \Craft::t('glue', 'A plan cannot have more than {max} field choices.', ['max' => self::MAX_CHOICES]));
            return;
        }

        foreach ($posted as $handle => $strategy) {
            if (!is_string($strategy) || !Strategy::isValid($strategy)) {
                $this->addError('choices', \Craft::t('glue', '“{strategy}” is not a merge strategy.', [
                    'strategy' => is_string($strategy) ? $strategy : gettype($strategy),
                ]));
                return;
            }

            // The shape of a Craft field handle. Presets write these as project config keys,
            // where a dot would open a new level of the tree.
            if (!is_string($handle) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $handle)) {
                $this->addError('choices', \Craft::t('glue', 'A field choice was posted without a valid field handle.'));
                return;
            }
        }
    }

    /** Whether the merged entry is one of the two sources rather than a third entry. */
    public function mergesIntoSource(): bool
    {
        return $this->target !== Settings::TARGET_NEW;
    }

    /** The source entry that survives as the merged entry, or null for a new one. */
    public function survivorId(): ?int
    {
        return match ($this->target) {
            Settings::TARGET_A => $this->aId,
            Settings::TARGET_B => $this->bId,
            default => null,
        };
    }

    /**
     * The sources that are *not* the merge target, and so are candidates for the disposition.
     *
     * @return int[]
     */
    public function retiredIds(): array
    {
        return match ($this->target) {
            Settings::TARGET_A => [$this->bId],
            Settings::TARGET_B => [$this->aId],
            default => [$this->aId, $this->bId],
        };
    }

    /**
     * Swaps which entry is A and which is B, choices included.
     *
     * The order is an accident of which checkbox somebody ticked first, but it decides the
     * seeding, the tie-breaks and which way round “Both” concatenates — so it has to be
     * changeable without starting the screen over.
     */
    public function swapped(): self
    {
        $swapped = clone $this;
        [$swapped->aId, $swapped->bId] = [$this->bId, $this->aId];

        $flip = [
            Strategy::A => Strategy::B,
            Strategy::B => Strategy::A,
        ];

        $swapped->choices = array_map(fn(string $s) => $flip[$s] ?? $s, $this->choices);

        $swapped->target = match ($this->target) {
            Settings::TARGET_A => Settings::TARGET_B,
            Settings::TARGET_B => Settings::TARGET_A,
            default => $this->target,
        };

        foreach (['titleFrom', 'slugFrom', 'authorFrom', 'postDateFrom', 'statusFrom', 'parentFrom'] as $attribute) {
            $swapped->$attribute = $flip[$this->$attribute] ?? $this->$attribute;
        }

        return $swapped;
    }
}
