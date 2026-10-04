<?php

declare(strict_types=1);

namespace justinholtweb\glue\services;

use Craft;
use craft\helpers\StringHelper;
use justinholtweb\glue\models\Edition;
use justinholtweb\glue\models\MergePlan;
use justinholtweb\glue\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Saved field choices, replayed on the next pair. **Pro.**
 *
 * ## Why these live in project config and not a table
 *
 * A preset is a sentence about the content model — "for a Press Release, take the body from the
 * newer one and union the topics". Field handles and entry type IDs are the model, so a preset
 * belongs beside it: written in `config/project/`, reviewed in a pull request, deployed with the
 * entry type it talks about. A preset in a database table would have to be re-created by hand on
 * staging, and would silently rot the moment somebody renamed a field in a migration.
 *
 * ## Why replaying a preset does not empty unknown fields
 *
 * A preset carries `choices` for the fields it knew about. Replayed against an entry type with
 * fields it has never seen, those fields are simply absent from the map — and
 * {@see Merger::strategyFor()} reads an absent handle as "no opinion, use the seeding rule"
 * rather than as "blank". The difference is the whole reason a preset can be safely used on a
 * pair it was not written for.
 */
class Presets extends Component
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return Plugin::getInstance()->getSettings()->presets;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $handle): ?array
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * The presets worth offering for a given entry type.
     *
     * A preset with no entry type is offered everywhere; one saved against a type is offered for
     * that type. Nothing is hidden outright — a preset for the wrong type is a degraded replay,
     * not a broken one — but the ones that fit are listed first.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forEntryType(?int $entryTypeId): array
    {
        $presets = $this->all();

        uasort($presets, function(array $a, array $b) use ($entryTypeId) {
            $rank = fn(array $preset) => match (true) {
                $entryTypeId !== null && ($preset['entryTypeId'] ?? null) === $entryTypeId => 0,
                ($preset['entryTypeId'] ?? null) === null => 1,
                default => 2,
            };

            return [$rank($a), $a['name'] ?? ''] <=> [$rank($b), $b['name'] ?? ''];
        });

        return $presets;
    }

    /**
     * Saves a plan's choices under a name.
     *
     * The two entries the plan came from are deliberately not stored: a preset is a rule, and a
     * rule that remembered the pair it was born from would be a bookmark.
     */
    public function save(string $name, MergePlan $plan, ?string $handle = null): string
    {
        $this->assertPro();

        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException(Craft::t('glue', 'A preset needs a name.'));
        }

        if (!$plan->validate(['choices'])) {
            throw new InvalidArgumentException(implode(' ', $plan->getErrorSummary(true)));
        }

        $handle ??= $this->uniqueHandle($name);

        $presets = $this->all();
        $presets[$handle] = [
            'name' => $name,
            'entryTypeId' => $plan->entryTypeId,
            'target' => $plan->target,
            'choices' => $plan->choices,
            'titleFrom' => $plan->titleFrom,
            'slugFrom' => $plan->slugFrom,
            'authorFrom' => $plan->authorFrom,
            'postDateFrom' => $plan->postDateFrom,
            'statusFrom' => $plan->statusFrom,
            'parentFrom' => $plan->parentFrom,
            'disposition' => $plan->disposition,
            'rewire' => $plan->rewire,
            'allSites' => $plan->allSites,
        ];

        $this->write($presets);

        return $handle;
    }

    public function delete(string $handle): bool
    {
        $presets = $this->all();

        if (!isset($presets[$handle])) {
            return false;
        }

        unset($presets[$handle]);
        $this->write($presets);

        return true;
    }

    /**
     * Overlays a preset onto a plan, leaving the pair and the site alone.
     *
     * The entry type is *not* overlaid onto a merge into an existing source: that entry already
     * has a type, and a preset is not licence to change it.
     */
    public function applyTo(MergePlan $plan, string $handle): MergePlan
    {
        $preset = $this->get($handle);

        if ($preset === null) {
            return $plan;
        }

        $plan = clone $plan;
        $plan->preset = $handle;

        foreach ([
            'target', 'titleFrom', 'slugFrom', 'authorFrom', 'postDateFrom',
            'statusFrom', 'parentFrom', 'disposition',
        ] as $attribute) {
            if (isset($preset[$attribute]) && is_string($preset[$attribute])) {
                $plan->$attribute = $preset[$attribute];
            }
        }

        if (isset($preset['choices']) && is_array($preset['choices'])) {
            $plan->choices = array_filter($preset['choices'], 'is_string');
        }

        $plan->rewire = (bool)($preset['rewire'] ?? $plan->rewire);
        $plan->allSites = (bool)($preset['allSites'] ?? $plan->allSites);

        if (!$plan->mergesIntoSource() && isset($preset['entryTypeId'])) {
            $plan->entryTypeId = (int)$preset['entryTypeId'];
        }

        return $plan;
    }

    private function uniqueHandle(string $name): string
    {
        $base = StringHelper::toKebabCase($name) ?: 'preset';
        $handle = $base;
        $presets = $this->all();
        $suffix = 1;

        while (isset($presets[$handle])) {
            $handle = $base . '-' . (++$suffix);
        }

        return $handle;
    }

    /**
     * @param array<string, array<string, mixed>> $presets
     */
    private function write(array $presets): void
    {
        // Presets live in project config. Writing it where admin changes are off puts the database
        // out of step with the YAML, and the next deploy quietly undoes the change.
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new InvalidArgumentException(Craft::t('glue', 'Presets are stored in project config, and admin changes are turned off in this environment. Save presets in development and deploy them.'));
        }

        $plugin = Plugin::getInstance();

        // The whole settings array, never just the changed key. `savePluginSettings()` replaces
        // the plugin's project config node wholesale, so posting one key blanks every other
        // setting — see `[[craft-plugin-gotchas]]`.
        $settings = $plugin->getSettings()->toArray();
        $settings['presets'] = $presets;

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            throw new InvalidArgumentException(Craft::t('glue', 'The preset could not be saved.'));
        }
    }

    private function assertPro(): void
    {
        if (!Edition::allowsPresets(Plugin::getInstance()->isPro())) {
            throw new InvalidArgumentException(Craft::t('glue', 'Merge presets are a Glue Pro feature.'));
        }
    }
}
