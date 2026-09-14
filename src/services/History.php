<?php

declare(strict_types=1);

namespace justinholtweb\glue\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\glue\db\Table;
use justinholtweb\glue\models\MergePlan;
use justinholtweb\glue\models\MergeResult;
use justinholtweb\glue\Plugin;
use Throwable;
use yii\base\Component;

/**
 * The record of what was merged into what.
 *
 * Worth having for one reason: a merge is the one content operation that cannot be read back off
 * the thing it produced. A merged entry looks exactly like an entry somebody typed. Six weeks
 * later, "where did the old one go" has no answer anywhere in Craft — the revisions of the
 * survivor do not mention the entry that was absorbed, and the absorbed entry's own revisions
 * end before the merge. This table is that answer.
 */
class History extends Component
{
    /**
     * Writes one row, and never lets doing so break a merge that has already happened.
     *
     * The merge is committed by the time this runs. Throwing here would report a failure for
     * work that succeeded, and the caller would have no way to tell the difference — so a
     * logging failure is logged and swallowed.
     */
    public function record(MergePlan $plan, MergeResult $result): ?int
    {
        if (!Plugin::getInstance()->getSettings()->logMerges) {
            return null;
        }

        try {
            $now = Db::prepareDateForDb(new DateTime());

            Craft::$app->getDb()->createCommand()->insert(Table::MERGES, [
                'targetId' => $result->entry?->id,
                'aId' => $plan->aId,
                'bId' => $plan->bId,
                'siteId' => $plan->siteId,
                'userId' => Craft::$app->getUser()->getId(),
                'targetTitle' => $this->truncate($result->entry?->title),
                'aTitle' => $this->truncate($this->titleOf((int)$plan->aId, (int)$plan->siteId)),
                'bTitle' => $this->truncate($this->titleOf((int)$plan->bId, (int)$plan->siteId)),
                'target' => $plan->target,
                'disposition' => $plan->disposition,
                'createdNew' => $result->createdNew,
                'rewired' => $result->rewired,
                'rewireQueued' => $result->rewireQueued,
                'preset' => $this->truncate($plan->preset),
                'plan' => Json::encode($this->planSnapshot($plan)),
                'warnings' => Json::encode($result->warnings),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])->execute();

            return (int)Craft::$app->getDb()->getLastInsertID();
        } catch (Throwable $e) {
            Craft::error('Could not record the merge: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /**
     * The plan as it ran, with the separators spelled out.
     *
     * Stored rather than referenced: a history row that says "the separator from the settings"
     * stops being true the first time somebody changes the settings, and the row exists to be
     * read long after that.
     *
     * @return array<string, mixed>
     */
    private function planSnapshot(MergePlan $plan): array
    {
        return [
            'target' => $plan->target,
            'sectionId' => $plan->sectionId,
            'entryTypeId' => $plan->entryTypeId,
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
            'textSeparator' => $plan->textSeparator,
            'richTextSeparator' => $plan->richTextSeparator,
            'dedupeRows' => $plan->dedupeRows,
            'preset' => $plan->preset,
        ];
    }

    private function titleOf(int $id, int $siteId): ?string
    {
        if ($id <= 0) {
            return null;
        }

        return (new Query())
            ->select(['title'])
            ->from(\craft\db\Table::ELEMENTS_SITES)
            ->where(['elementId' => $id, 'siteId' => $siteId])
            ->scalar() ?: null;
    }

    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, 255);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50, int $offset = 0): array
    {
        return (new Query())
            ->from(['m' => Table::MERGES])
            ->leftJoin(['u' => \craft\db\Table::USERS], '[[u.id]] = [[m.userId]]')
            ->select([
                'm.id', 'm.targetId', 'm.aId', 'm.bId', 'm.siteId', 'm.userId',
                'm.targetTitle', 'm.aTitle', 'm.bTitle', 'm.target', 'm.disposition',
                'm.createdNew', 'm.rewired', 'm.rewireQueued', 'm.preset',
                'm.plan', 'm.warnings', 'm.dateCreated',
                'username' => 'u.username',
            ])
            ->orderBy(['m.dateCreated' => SORT_DESC, 'm.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();
    }

    public function total(): int
    {
        return (int)(new Query())->from(Table::MERGES)->count();
    }

    /**
     * Merges an entry was on either side of. Powers "this entry absorbed another one".
     *
     * @return array<int, array<string, mixed>>
     */
    public function forEntry(int $entryId): array
    {
        return (new Query())
            ->from(Table::MERGES)
            ->where(['or', ['targetId' => $entryId], ['aId' => $entryId], ['bId' => $entryId]])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
    }

    /**
     * Drops everything past the newest `$limit` rows.
     *
     * Run from garbage collection rather than on write, so a busy afternoon of merging does not
     * pay for a DELETE on every single one.
     */
    public function prune(?int $limit = null): int
    {
        $limit ??= Plugin::getInstance()->getSettings()->historyLimit;

        if ($limit <= 0) {
            return 0;
        }

        $cutoff = (new Query())
            ->select(['id'])
            ->from(Table::MERGES)
            ->orderBy(['id' => SORT_DESC])
            ->offset($limit - 1)
            ->limit(1)
            ->scalar();

        if ($cutoff === false || $cutoff === null) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->delete(Table::MERGES, ['<', 'id', (int)$cutoff])
            ->execute();
    }
}
