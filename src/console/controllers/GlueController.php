<?php

declare(strict_types=1);

namespace justinholtweb\glue\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\glue\models\Edition;
use justinholtweb\glue\models\MergePlan;
use justinholtweb\glue\models\Settings;
use justinholtweb\glue\Plugin;
use justinholtweb\glue\services\Duplicates;
use Throwable;
use yii\console\ExitCode;

/**
 * Glue from the command line. **Pro, except `inspect`.**
 *
 * ## Why every option is a string
 *
 * Yii assigns console options **raw** when the property's default is `null` — a typed
 * `public ?int $a = null` fatals on `--a=5` before the action body is ever reached, with a
 * TypeError that names Yii's own code and not yours. Options with a non-null default get a
 * `settype()` and are safe, which is why the booleans below are declared `bool` and the rest are
 * declared `?string` and cast by hand. See `[[craft-plugin-gotchas]]`.
 *
 * ## Why `inspect` is free
 *
 * It writes nothing. Being able to ask "what would merging these two do" without a licence is
 * how somebody decides whether they want one.
 */
class GlueController extends Controller
{
    /** The first entry, by ID. */
    public ?string $a = null;

    /** The second entry, by ID. */
    public ?string $b = null;

    /** `new`, `a` or `b`. */
    public ?string $target = null;

    /** Section handle for a new entry. */
    public ?string $section = null;

    /** Entry type handle for a new entry. */
    public ?string $entryType = null;

    /** Site handle to merge in. */
    public ?string $site = null;

    /** A saved preset's handle. */
    public ?string $preset = null;

    /** `leave`, `disable` or `trash`. */
    public ?string $disposition = null;

    /** Comparison for `duplicates`: `title` or `slug`. */
    public ?string $by = null;

    /** Row cap for `duplicates`. */
    public ?string $limit = null;

    /** The entry whose inbound relations should move, for `rewire`. */
    public ?string $from = null;

    /** The entry they should move to, for `rewire`. */
    public ?string $to = null;

    /** Repoint relations that pointed at the retired sources. */
    public bool $rewire = false;

    /** Merge every site the two entries share. */
    public bool $allSites = false;

    /** Work out the whole merge and print it, but write nothing. */
    public bool $dryRun = false;

    /** Skip the confirmation prompt. */
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'merge' => ['a', 'b', 'target', 'section', 'entryType', 'site', 'preset', 'disposition', 'rewire', 'allSites', 'dryRun', 'force'],
            'inspect' => ['a', 'b', 'target', 'entryType', 'site'],
            'duplicates' => ['section', 'site', 'by', 'limit'],
            'rewire' => ['from', 'to', 'force'],
            default => [],
        });
    }

    /**
     * Merges two entries.
     */
    public function actionMerge(): int
    {
        if (!$this->requirePro('Merging from the command line')) {
            return ExitCode::UNAVAILABLE;
        }

        $plan = $this->buildPlan();

        if ($plan === null) {
            return ExitCode::USAGE;
        }

        if (!$this->dryRun && !$this->force && !$this->confirm(sprintf(
            'Merge entry %d and entry %d into %s?',
            $plan->aId,
            $plan->bId,
            $plan->target === Settings::TARGET_NEW ? 'a new entry' : "entry {$plan->survivorId()}",
        ))) {
            return ExitCode::OK;
        }

        try {
            $result = Plugin::getInstance()->merger->apply($plan, $this->dryRun);
        } catch (Throwable $e) {
            $this->stderr('Merge failed: ' . $e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout('Dry run — nothing was written.' . PHP_EOL, Console::FG_YELLOW);
        }

        $this->printResolved($result->previews);

        foreach ($result->warnings as $warning) {
            $this->stdout('  ! ' . $warning . PHP_EOL, Console::FG_YELLOW);
        }

        if ($result->saved) {
            $this->stdout(sprintf('Merged into entry %d.' . PHP_EOL, (int)$result->entry?->id), Console::FG_GREEN);

            if ($result->retired !== []) {
                $this->stdout(sprintf('Retired: %s.' . PHP_EOL, implode(', ', $result->retired)));
            }

            if ($result->rewired > 0) {
                $this->stdout(sprintf(
                    '%d %s %s.' . PHP_EOL,
                    $result->rewired,
                    $result->rewired === 1 ? 'element' : 'elements',
                    $result->rewireQueued ? 'queued for rewiring' : 'rewired',
                ));
            }
        }

        return ExitCode::OK;
    }

    /**
     * Prints what merging two entries would do, field by field. Free.
     */
    public function actionInspect(): int
    {
        $plan = $this->buildPlan();

        if ($plan === null) {
            return ExitCode::USAGE;
        }

        $pairs = Plugin::getInstance()->pairs;

        try {
            $pair = $pairs->inspect($pairs->load((int)$plan->aId, (int)$plan->bId, (int)$plan->siteId), $plan);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf('A: %s (%d)' . PHP_EOL, $pair->a?->title ?? '?', (int)$pair->a?->id));
        $this->stdout(sprintf('B: %s (%d)' . PHP_EOL . PHP_EOL, $pair->b?->title ?? '?', (int)$pair->b?->id));

        $merger = Plugin::getInstance()->merger;

        foreach ($pair->fields as $handle => $fieldPair) {
            $strategy = $merger->strategyFor($fieldPair, $plan);
            $contested = $fieldPair->contested();

            $this->stdout(sprintf('%-28s ', $handle), $contested ? Console::FG_YELLOW : Console::FG_GREY);
            $this->stdout(sprintf(
                '%-5s  A: %-28s B: %s' . PHP_EOL,
                strtoupper($strategy),
                $this->clip($fieldPair->aPreview?->summary ?? ''),
                $this->clip($fieldPair->bPreview?->summary ?? ''),
            ));
        }

        foreach ($pair->warnings as $warning) {
            $this->stdout(PHP_EOL . '! ' . $warning . PHP_EOL, Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Lists entries sharing a title or a slug.
     */
    public function actionDuplicates(): int
    {
        if (!$this->requirePro('The duplicate finder')) {
            return ExitCode::UNAVAILABLE;
        }

        $sectionId = null;

        if ($this->section !== null) {
            $section = Craft::$app->getEntries()->getSectionByHandle($this->section);

            if ($section === null) {
                $this->stderr(sprintf('No section with the handle “%s”.' . PHP_EOL, $this->section), Console::FG_RED);

                return ExitCode::USAGE;
            }

            $sectionId = (int)$section->id;
        }

        try {
            $groups = Plugin::getInstance()->duplicates->find(
                $sectionId,
                $this->siteId(),
                $this->by ?? Duplicates::BY_TITLE,
                max(1, (int)($this->limit ?? 100)),
            );
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($groups === []) {
            $this->stdout('No duplicates found.' . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        }

        foreach ($groups as $group) {
            $this->stdout(sprintf('%s (%d)' . PHP_EOL, $group['value'], $group['count']), Console::FG_YELLOW);

            foreach ($group['ids'] as $id) {
                $this->stdout(sprintf('  %d' . PHP_EOL, $id));
            }

            // The pair a person would actually merge, spelled out, because turning "these four
            // IDs share a title" into a command is the tedious half of this job.
            if (count($group['ids']) >= 2) {
                $this->stdout(sprintf(
                    '  → php craft glue/merge --a=%d --b=%d' . PHP_EOL . PHP_EOL,
                    $group['ids'][0],
                    $group['ids'][1],
                ), Console::FG_GREY);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Repoints every relation that targets one entry at another, without merging anything.
     */
    public function actionRewire(): int
    {
        if (!$this->requirePro('Rewiring')) {
            return ExitCode::UNAVAILABLE;
        }

        $from = (int)$this->from;
        $to = (int)$this->to;

        if ($from <= 0 || $to <= 0 || $from === $to) {
            $this->stderr('Give --from and --to two different entry IDs.' . PHP_EOL, Console::FG_RED);

            return ExitCode::USAGE;
        }

        $rewirer = Plugin::getInstance()->rewirer;
        $ids = $rewirer->referencingElementIds([$from]);

        if ($ids === []) {
            $this->stdout('Nothing relates to that entry.' . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        }

        if (!$this->force && !$this->confirm(sprintf('Repoint %d %s at entry %d?', count($ids), count($ids) === 1 ? 'element' : 'elements', $to))) {
            return ExitCode::OK;
        }

        $done = 0;

        Console::startProgress(0, count($ids));

        foreach ($ids as $index => $elementId) {
            if ($rewirer->rewireElement($elementId, [$from], $to)) {
                $done++;
            }

            Console::updateProgress($index + 1, count($ids));
        }

        Console::endProgress();

        $this->stdout(sprintf('Rewired %d.' . PHP_EOL, $done), Console::FG_GREEN);

        foreach ($rewirer->refTagWarnings([$from]) as $warning) {
            $this->stdout('! ' . $warning . PHP_EOL, Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    // ------------------------------------------------------------------ helpers

    private function buildPlan(): ?MergePlan
    {
        $aId = (int)$this->a;
        $bId = (int)$this->b;

        if ($aId <= 0 || $bId <= 0) {
            $this->stderr('Give --a and --b two entry IDs.' . PHP_EOL, Console::FG_RED);

            return null;
        }

        $settings = Plugin::getInstance()->getSettings();

        $plan = new MergePlan([
            'aId' => $aId,
            'bId' => $bId,
            'siteId' => $this->siteId(),
            'target' => $this->target ?? $settings->defaultTarget,
            'disposition' => $this->disposition ?? $settings->defaultDisposition,
            'rewire' => $this->rewire,
            'allSites' => $this->allSites,
        ]);

        if ($this->section !== null) {
            $plan->sectionId = Craft::$app->getEntries()->getSectionByHandle($this->section)?->id;
        }

        if ($this->entryType !== null) {
            foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
                if ($entryType->handle === $this->entryType) {
                    $plan->entryTypeId = (int)$entryType->id;
                    break;
                }
            }
        }

        if ($this->preset !== null) {
            $plan = Plugin::getInstance()->presets->applyTo($plan, $this->preset);
        }

        return $plan;
    }

    private function siteId(): int
    {
        if ($this->site !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($this->site);

            if ($site !== null) {
                return (int)$site->id;
            }
        }

        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * @param array<string, \justinholtweb\glue\models\Preview> $previews
     */
    private function printResolved(array $previews): void
    {
        foreach ($previews as $handle => $preview) {
            $this->stdout(sprintf('  %-28s %s' . PHP_EOL, $handle, $this->clip($preview->summary)), Console::FG_GREY);
        }
    }

    private function clip(string $value): string
    {
        $value = str_replace(["\n", "\r"], ' ', $value);

        return mb_strlen($value) > 28 ? mb_substr($value, 0, 27) . '…' : $value;
    }

    private function requirePro(string $what): bool
    {
        if (Edition::allowsConsole(Plugin::getInstance()->isPro())) {
            return true;
        }

        $this->stderr($what . ' is a Glue Pro feature.' . PHP_EOL, Console::FG_YELLOW);

        return false;
    }
}
