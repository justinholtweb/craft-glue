<?php

declare(strict_types=1);

namespace justinholtweb\glue\twig;

use craft\helpers\UrlHelper;
use justinholtweb\glue\Plugin;
use yii\base\BaseObject;

/**
 * `craft.glue` — a deliberately small surface.
 *
 * Glue is a control-panel tool; there is nothing about a merge a front-end template needs to
 * render. What is here is for the control panel and for anyone building their own merge screen:
 * the edition, the link, and the record of what happened to an entry.
 */
class GlueVariable extends BaseObject
{
    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }

    /** The merge screen for two entries, ready to link to. */
    public function mergeUrl(int $aId, int $bId, ?int $siteId = null): string
    {
        $params = ['a' => $aId, 'b' => $bId];

        if ($siteId !== null) {
            $params['site'] = $siteId;
        }

        return UrlHelper::cpUrl('glue/merge', $params);
    }

    /**
     * Merges an entry was involved in, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(int $entryId): array
    {
        return Plugin::getInstance()->history->forEntry($entryId);
    }

    /**
     * Whether an entry is the result of a merge.
     *
     * The question templates actually ask — "is this thing a survivor" — without making them
     * read the history rows to find out.
     */
    public function wasMergedInto(int $entryId): bool
    {
        foreach ($this->history($entryId) as $row) {
            if ((int)($row['targetId'] ?? 0) === $entryId) {
                return true;
            }
        }

        return false;
    }
}
