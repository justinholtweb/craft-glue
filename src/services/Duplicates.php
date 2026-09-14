<?php

declare(strict_types=1);

namespace justinholtweb\glue\services;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use justinholtweb\glue\models\Edition;
use justinholtweb\glue\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Finds the pairs worth merging. **Pro.**
 *
 * ## What counts as a duplicate
 *
 * Nothing clever, on purpose. Two entries are candidates when a human would read them as the
 * same thing: identical title, or identical slug, within one section. A fuzzy matcher —
 * Levenshtein, trigram similarity, a shingled hash of the body — finds more pairs and is worse,
 * because the cost of a false positive here is somebody merging two entries that were never the
 * same and losing one of them. Exact matching produces a short list that is almost always right;
 * the long list is the editor's job.
 *
 * ## Why it is one query and not N
 *
 * Grouping happens in the database. The naive version loads every entry in a section and
 * compares them in PHP, which is both O(n²) and a memory problem on the sites that need this
 * most — the ones with the import that ran twice.
 */
class Duplicates extends Component
{
    public const BY_TITLE = 'title';
    public const BY_SLUG = 'slug';

    /**
     * Groups of entries sharing a title or a slug.
     *
     * @return array<int, array{value: string, ids: int[], titles: array<int, string>, count: int}>
     */
    public function find(
        ?int $sectionId = null,
        ?int $siteId = null,
        string $by = self::BY_TITLE,
        int $limit = 100,
    ): array {
        $this->assertPro();

        if (!in_array($by, [self::BY_TITLE, self::BY_SLUG], true)) {
            throw new InvalidArgumentException(Craft::t('glue', '“{by}” is not something Glue can compare.', ['by' => $by]));
        }

        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $column = $by === self::BY_SLUG ? 'es.slug' : 'es.title';

        $base = (new Query())
            ->from(['e' => CraftTable::ELEMENTS])
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
            ->innerJoin(['en' => CraftTable::ENTRIES], '[[en.id]] = [[e.id]]')
            ->where([
                'e.dateDeleted' => null,
                'e.draftId' => null,
                'e.revisionId' => null,
                'es.siteId' => $siteId,
            ])
            // Nested entries — Matrix blocks — are not duplicates of anything a person would
            // want to merge, and on a big site they outnumber the real entries several times
            // over.
            ->andWhere(['en.fieldId' => null])
            ->andWhere(['not', [$column => null]])
            ->andWhere(['not', [$column => '']]);

        if ($sectionId !== null) {
            $base->andWhere(['en.sectionId' => $sectionId]);
        }

        $groups = (clone $base)
            ->select(['value' => $column, 'total' => 'COUNT(*)'])
            ->groupBy([$column])
            ->having(['>', 'COUNT(*)', 1])
            ->orderBy(['COUNT(*)' => SORT_DESC, $column => SORT_ASC])
            ->limit($limit)
            ->all();

        if ($groups === []) {
            return [];
        }

        $values = array_column($groups, 'value');

        $members = (clone $base)
            ->select(['id' => 'e.id', 'value' => $column, 'title' => 'es.title'])
            ->andWhere([$column => $values])
            ->orderBy(['e.id' => SORT_ASC])
            ->all();

        $byValue = [];

        foreach ($members as $row) {
            $byValue[(string)$row['value']][] = $row;
        }

        $out = [];

        foreach ($groups as $group) {
            $value = (string)$group['value'];
            $rows = $byValue[$value] ?? [];

            $out[] = [
                'value' => $value,
                'count' => (int)$group['total'],
                'ids' => array_map(fn(array $row) => (int)$row['id'], $rows),
                'titles' => array_combine(
                    array_map(fn(array $row) => (int)$row['id'], $rows),
                    array_map(fn(array $row) => (string)$row['title'], $rows),
                ),
            ];
        }

        return $out;
    }

    private function assertPro(): void
    {
        if (!Edition::allowsDuplicateFinder(Plugin::getInstance()->isPro())) {
            throw new InvalidArgumentException(Craft::t('glue', 'The duplicate finder is a Glue Pro feature.'));
        }
    }
}
