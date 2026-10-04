<?php

declare(strict_types=1);

namespace justinholtweb\glue\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\glue\Plugin;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * What was merged into what.
 *
 * The rows are read straight out of the table rather than hydrated into models, because every
 * one of them may name entries that no longer exist — that is the case the screen is for — and a
 * model layer over "an ID that may resolve to nothing" buys nothing but a null check per field.
 * The entries that *do* still resolve are loaded in one query and rendered as chips.
 */
class HistoryController extends Controller
{
    private const PER_PAGE = 50;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        if (!Plugin::getInstance()->canViewHistory()) {
            throw new ForbiddenHttpException(Craft::t('glue', 'You are not allowed to view the merge history.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $page = max(1, (int)Craft::$app->getRequest()->getQueryParam('page', 1));
        $history = Plugin::getInstance()->history;

        $rows = $history->recent(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $total = $history->total();

        return $this->renderTemplate('glue/_history', [
            'plugin' => Plugin::getInstance(),
            'rows' => $this->decorate($history->masked($rows)),
            'page' => $page,
            'pages' => (int)ceil($total / self::PER_PAGE),
            'total' => $total,
        ]);
    }

    /**
     * Whether an entry can survive being rendered as a chip.
     *
     * Not a paranoid guard — this is the screen's own worst case. When a whole section is deleted
     * its entries are soft-deleted with it, and they still come back from a `trashed(null)`
     * query; but `Entry::getSection()` then throws `InvalidConfigException` for a section ID that
     * no longer resolves, and `Cp::elementChipHtml()` reaches `getSection()` through `canView()`.
     * So the one row the history exists to explain — the merge whose everything is gone — is the
     * row that takes the page down with a 500.
     *
     * An entry that cannot be rendered simply is not attached, and the row falls back to the
     * title copied into it at merge time, which is what that column is for.
     */
    private function isRenderable(Entry $entry): bool
    {
        try {
            return $entry->getSection() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Attaches the entries that still exist, and decodes the stored plan.
     *
     * One query for every entry named by every row on the page, not one per row: a history page
     * is 50 rows and up to 150 entries, and the per-row version is the classic N+1 that makes an
     * audit screen unopenable on exactly the sites that have the most to audit.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            foreach (['targetId', 'aId', 'bId'] as $key) {
                if (!empty($row[$key])) {
                    $ids[] = (int)$row[$key];
                }
            }
        }

        $entries = $ids === [] ? [] : Entry::find()
            ->id(array_unique($ids))
            ->siteId('*')
            ->unique()
            ->status(null)
            ->trashed(null)
            ->drafts(null)
            ->indexBy('id')
            ->all();

        $entries = array_filter($entries, fn(Entry $entry) => $this->isRenderable($entry));

        foreach ($rows as &$row) {
            foreach (['target', 'a', 'b'] as $side) {
                $entry = $entries[$row[$side . 'Id'] ?? 0] ?? null;

                // A chip shows the status the entry had before it was trashed — a green dot on
                // something the merge threw away. The stored title, marked, says what happened.
                if ($entry?->trashed) {
                    $row[$side . 'Title'] = Craft::t('glue', '{title} (trashed)', [
                        'title' => $row[$side . 'Title'] ?? $entry->title,
                    ]);
                    $entry = null;
                }

                $row[$side . 'Entry'] = $entry;
            }

            try {
                $row['planData'] = $row['plan'] ? Json::decode($row['plan']) : [];
                $row['warningList'] = $row['warnings'] ? Json::decode($row['warnings']) : [];
            } catch (Throwable) {
                $row['planData'] = [];
                $row['warningList'] = [];
            }
        }

        return $rows;
    }
}
