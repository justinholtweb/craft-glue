<?php

declare(strict_types=1);

namespace justinholtweb\glue\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application and read in one place as the answer to "what exactly does Pro
 * buy".
 *
 * The rule the split follows: **Lite is a complete merge tool, not a demo.** Everything that
 * makes a merge *correct* is in Lite — every combine strategy, every target, the compatibility
 * warnings, the disposition of the sources, the history. An editor cleaning up a handful of
 * duplicate entries by hand should never meet a paywall.
 *
 * Pro is for the two things that only arrive with volume:
 *
 * - **Integrity at scale.** Retiring an entry that a hundred other entries point at is not a
 *   merge problem, it is a link-rot problem. Rewiring is Pro.
 * - **Doing it without a human.** Presets, the console commands and the duplicate finder are
 *   what you need when the pairs number in the hundreds; the CP screen is what you need when
 *   they number in the tens.
 *
 * Nothing here downgrades silently. A lapsed Pro licence keeps every merge it already made —
 * merges are ordinary entries once they are saved — and the CP refuses the Pro controls with a
 * reason rather than quietly doing something else, because "it merged but did not rewire" is a
 * failure you would not notice until the links were already broken.
 */
class Edition
{
    /**
     * Repointing every relation that targeted a retired source at the surviving entry.
     *
     * Pro because the problem it solves is proportional to how much content you have: on a site
     * with twelve entries you can fix the three inbound links by hand in a minute.
     */
    public static function allowsRewiring(bool $isPro): bool
    {
        return $isPro;
    }

    /** Saving a set of field choices and replaying it on the next pair. */
    public static function allowsPresets(bool $isPro): bool
    {
        return $isPro;
    }

    /** `glue/merge` and friends — merging with no CP screen in the loop. */
    public static function allowsConsole(bool $isPro): bool
    {
        return $isPro;
    }

    /** `glue/duplicates` — finding the pairs worth merging in the first place. */
    public static function allowsDuplicateFinder(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * Merging every site the sources share in one pass, rather than the site being edited.
     *
     * Lite merges the current site. That is the whole job on a single-site install, which is most
     * of them; it is a quarter of the job on a four-locale install, which is where Pro is.
     */
    public static function allowsAllSites(bool $isPro): bool
    {
        return $isPro;
    }

    /** Presets Lite may hold. Zero — the feature is Pro — but named rather than implied. */
    public const LITE_MAX_PRESETS = 0;

    public static function maxPresets(bool $isPro): int
    {
        return $isPro ? PHP_INT_MAX : self::LITE_MAX_PRESETS;
    }
}
