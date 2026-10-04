<?php

declare(strict_types=1);

namespace justinholtweb\glue\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use justinholtweb\glue\models\Edition;
use justinholtweb\glue\Plugin;
use justinholtweb\glue\services\Duplicates;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Entries that share a title or a slug, so they can be paired off and merged. **Pro.**
 */
class DuplicatesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->canMerge()) {
            throw new ForbiddenHttpException(Craft::t('glue', 'You are not allowed to merge entries.'));
        }

        if (!Edition::allowsDuplicateFinder($plugin->isPro())) {
            throw new ForbiddenHttpException(Craft::t('glue', 'The duplicate finder is a Glue Pro feature.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $sectionId = ($id = (int)$request->getQueryParam('section')) > 0 ? $id : null;
        $by = (string)$request->getQueryParam('by', Duplicates::BY_TITLE);
        $siteId = (int)($request->getQueryParam('site') ?: Craft::$app->getSites()->getCurrentSite()->id);

        // Only sites this user can edit, the same list the CP's own site menu offers.
        if (!in_array($siteId, Craft::$app->getSites()->getEditableSiteIds(), true)) {
            throw new ForbiddenHttpException(Craft::t('glue', 'You are not allowed to edit that site.'));
        }

        $groups = [];
        $error = null;

        try {
            $groups = Plugin::getInstance()->duplicates->find($sectionId, $siteId, $by, 100, $this->viewableSectionIds());
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->renderTemplate('glue/_duplicates', [
            'plugin' => Plugin::getInstance(),
            'groups' => $this->withEntries($groups, $siteId),
            'sectionId' => $sectionId,
            'siteId' => $siteId,
            'by' => $by,
            'error' => $error,
            'sections' => Craft::$app->getEntries()->getEditableSections(),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function withEntries(array $groups, int $siteId): array
    {
        $ids = [];

        foreach ($groups as $group) {
            foreach ($group['ids'] as $id) {
                $ids[] = (int)$id;
            }
        }

        $entries = $ids === [] ? [] : Entry::find()
            ->id($ids)
            ->siteId($siteId)
            ->status(null)
            ->indexBy('id')
            ->all();

        $elements = Craft::$app->getElements();
        $out = [];

        foreach ($groups as $group) {
            // Section access is settled in the query; this catches the rest — peer entries,
            // per-entry rules from other plugins. A group with one visible member is not a
            // duplicate as far as this user is concerned, and listing it would leak the other.
            $group['entries'] = array_values(array_filter(array_map(
                fn(int $id) => $entries[$id] ?? null,
                $group['ids'],
            ), fn(?Entry $entry) => $entry !== null && $elements->canView($entry)));

            if (count($group['entries']) >= 2) {
                $out[] = $group;
            }
        }

        return $out;
    }

    /**
     * @return int[]
     */
    private function viewableSectionIds(): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        $ids = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($user?->can("viewEntries:$section->uid")) {
                $ids[] = (int)$section->id;
            }
        }

        return $ids;
    }
}
