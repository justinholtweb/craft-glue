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

        $groups = [];
        $error = null;

        try {
            $groups = Plugin::getInstance()->duplicates->find($sectionId, $siteId, $by);
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

        foreach ($groups as &$group) {
            $group['entries'] = array_values(array_filter(array_map(
                fn(int $id) => $entries[$id] ?? null,
                $group['ids'],
            )));
        }

        return $groups;
    }
}
