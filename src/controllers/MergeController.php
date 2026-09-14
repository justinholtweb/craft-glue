<?php

declare(strict_types=1);

namespace justinholtweb\glue\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\glue\models\Edition;
use justinholtweb\glue\models\MergePlan;
use justinholtweb\glue\models\Pair;
use justinholtweb\glue\models\Settings;
use justinholtweb\glue\models\Strategy;
use justinholtweb\glue\Plugin;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The merge screen and the three things it posts.
 *
 * ## Why changing the target reloads the page
 *
 * The target entry type decides which fields exist at all, so switching from "a new Press
 * Release" to "merge into the second entry" does not change the *values* on the screen — it
 * changes which rows are on it. Re-rendering server-side is the honest way to do that; the
 * alternative is shipping every entry type's field analysis to the browser up front and hoping
 * it stays in step with the one that will actually run.
 */
class MergeController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        if (!Plugin::getInstance()->canMerge()) {
            throw new ForbiddenHttpException(Craft::t('glue', 'You are not allowed to merge entries.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $aId = (int)$request->getQueryParam('a');
        $bId = (int)$request->getQueryParam('b');
        $siteId = $this->resolveSiteId($request->getQueryParam('site'));

        if ($aId <= 0 || $bId <= 0) {
            throw new BadRequestHttpException(Craft::t('glue', 'Two entries are needed for a merge.'));
        }

        $settings = $plugin->getSettings();

        $plan = new MergePlan([
            'aId' => $aId,
            'bId' => $bId,
            'siteId' => $siteId,
            'target' => (string)($request->getQueryParam('target') ?? $settings->defaultTarget),
            'disposition' => $settings->defaultDisposition,
            'rewire' => $settings->rewireRelations && Edition::allowsRewiring($plugin->isPro()),
            'allSites' => $settings->allSites && Edition::allowsAllSites($plugin->isPro()),
        ]);

        if (($entryTypeId = (int)$request->getQueryParam('entryType')) > 0) {
            $plan->entryTypeId = $entryTypeId;
        }

        if (($preset = $request->getQueryParam('preset')) && $plugin->canManagePresets()) {
            $plan = $plugin->presets->applyTo($plan, (string)$preset);
        }

        try {
            $pair = $plugin->pairs->load($aId, $bId, $siteId);
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirect('entries');
        }

        $plan->sectionId ??= $pair->a?->sectionId;
        $pair = $plugin->pairs->inspect($pair, $plan);

        $entryType = $plugin->pairs->targetEntryType($pair, $plan);
        $plan->entryTypeId ??= $entryType?->id;

        return $this->renderTemplate('glue/_merge', [
            'plugin' => $plugin,
            'pair' => $pair,
            'plan' => $plan,
            'entryType' => $entryType,
            'isPro' => $plugin->isPro(),
            'sections' => $this->sectionOptions(),
            'entryTypes' => $this->entryTypeOptions($plan->sectionId),
            'presets' => $plugin->canManagePresets() ? $plugin->presets->forEntryType($plan->entryTypeId) : [],
            'targetOptions' => Settings::targetOptions(),
            'dispositionOptions' => Settings::dispositionOptions(),
            'sites' => $this->siteOptions($pair),
        ]);
    }

    /**
     * Re-resolves every field for the choices currently on the screen.
     *
     * Runs the real merge's own `resolveAll()` with `dryRun`, so the result column cannot drift
     * from the merge — there is no second implementation of "what would this produce".
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plan = $this->planFromRequest();

        try {
            $result = Plugin::getInstance()->merger->apply($plan, true);
        } catch (Throwable $e) {
            return $this->asJson(['error' => $e->getMessage()]);
        }

        $view = Craft::$app->getView();
        $previews = [];

        foreach ($result->previews as $handle => $preview) {
            $previews[$handle] = $view->renderTemplate('glue/_preview', ['preview' => $preview]);
        }

        return $this->asJson([
            'previews' => $previews,
            'warnings' => $result->warnings,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $plan = $this->planFromRequest();

        try {
            $result = Plugin::getInstance()->merger->apply($plan);
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirectToPostedUrl();
        }

        foreach ($result->warnings as $warning) {
            Craft::$app->getSession()->setNotice($warning);
        }

        Craft::$app->getSession()->setNotice(Craft::t('glue', 'Entries merged.'));

        $url = $result->entry?->getCpEditUrl();

        return $url !== null ? $this->redirect($url) : $this->redirect('glue/history');
    }

    public function actionSavePreset(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Plugin::getInstance()->canManagePresets()) {
            throw new ForbiddenHttpException(Craft::t('glue', 'You are not allowed to manage merge presets.'));
        }

        $name = (string)Craft::$app->getRequest()->getBodyParam('name', '');
        $plan = $this->planFromRequest();

        try {
            $handle = Plugin::getInstance()->presets->save($name, $plan);
        } catch (Throwable $e) {
            return $this->asJson(['error' => $e->getMessage()]);
        }

        return $this->asJson(['handle' => $handle, 'name' => $name]);
    }

    public function actionDeletePreset(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Plugin::getInstance()->canManagePresets()) {
            throw new ForbiddenHttpException(Craft::t('glue', 'You are not allowed to manage merge presets.'));
        }

        $handle = (string)Craft::$app->getRequest()->getBodyParam('handle', '');

        return $this->asJson(['deleted' => Plugin::getInstance()->presets->delete($handle)]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Builds a plan out of whatever was posted.
     *
     * Everything is cast here rather than trusted, because a typed property assigned a raw HTTP
     * string is a TypeError inside Yii's own `setAttributes()` — a white screen from a form that
     * looked fine. See `[[craft-plugin-gotchas]]`.
     */
    private function planFromRequest(): MergePlan
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $choices = $request->getBodyParam('choices', []);
        $choices = is_array($choices) ? $choices : [];

        $plan = new MergePlan([
            'aId' => (int)$request->getBodyParam('a'),
            'bId' => (int)$request->getBodyParam('b'),
            'siteId' => $this->resolveSiteId($request->getBodyParam('siteId')),
            'target' => (string)$request->getBodyParam('target', Settings::TARGET_NEW),
            'sectionId' => ($id = (int)$request->getBodyParam('sectionId')) > 0 ? $id : null,
            'entryTypeId' => ($id = (int)$request->getBodyParam('entryTypeId')) > 0 ? $id : null,
            'choices' => array_filter(
                array_map(fn($value) => is_string($value) ? $value : null, $choices),
                fn(?string $value) => $value !== null && Strategy::isValid($value),
            ),
            'titleFrom' => (string)$request->getBodyParam('titleFrom', MergePlan::TITLE_A),
            'title' => $this->nullableString($request->getBodyParam('title')),
            'slugFrom' => (string)$request->getBodyParam('slugFrom', MergePlan::SLUG_A),
            'slug' => $this->nullableString($request->getBodyParam('slug')),
            'authorFrom' => (string)$request->getBodyParam('authorFrom', MergePlan::AUTHOR_A),
            'postDateFrom' => (string)$request->getBodyParam('postDateFrom', MergePlan::DATE_EARLIEST),
            'statusFrom' => (string)$request->getBodyParam('statusFrom', MergePlan::STATUS_A),
            'parentFrom' => (string)$request->getBodyParam('parentFrom', MergePlan::PARENT_A),
            'disposition' => (string)$request->getBodyParam('disposition', Settings::DISPOSITION_LEAVE),
            'rewire' => (bool)$request->getBodyParam('rewire'),
            'allSites' => (bool)$request->getBodyParam('allSites'),
            'preset' => $this->nullableString($request->getBodyParam('preset')),
        ]);

        if (!Edition::allowsRewiring($plugin->isPro())) {
            $plan->rewire = false;
        }

        if (!Edition::allowsAllSites($plugin->isPro())) {
            $plan->allSites = false;
        }

        return $plan;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    /**
     * The site to work in, refusing one the user cannot edit.
     *
     * A merge writes content, so "which site" is a permission question and not a display one.
     */
    private function resolveSiteId(mixed $raw): int
    {
        $sites = Craft::$app->getSites();
        $editable = array_map(fn($site) => (int)$site->id, $sites->getEditableSites());

        if ($editable === []) {
            throw new ForbiddenHttpException(Craft::t('glue', 'You are not allowed to edit any sites.'));
        }

        $siteId = is_numeric($raw) ? (int)$raw : null;

        if ($siteId !== null && in_array($siteId, $editable, true)) {
            return $siteId;
        }

        $current = (int)$sites->getCurrentSite()->id;

        return in_array($current, $editable, true) ? $current : $editable[0];
    }

    /**
     * @return array<int, array{label: string, value: int}>
     */
    private function sectionOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getEntries()->getEditableSections() as $section) {
            if ($section->type === \craft\models\Section::TYPE_SINGLE) {
                // A single is one entry by definition; there is nothing to merge *into* that is
                // not already that entry, and creating a second one is not a thing Craft allows.
                continue;
            }

            $options[] = ['label' => Craft::t('site', $section->name), 'value' => (int)$section->id];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: int}>
     */
    private function entryTypeOptions(?int $sectionId): array
    {
        if ($sectionId === null) {
            return [];
        }

        $options = [];

        foreach (Craft::$app->getEntries()->getEntryTypesBySectionId($sectionId) as $entryType) {
            $options[] = ['label' => Craft::t('site', $entryType->name), 'value' => (int)$entryType->id];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: int}>
     */
    private function siteOptions(Pair $pair): array
    {
        $options = [];
        $editable = array_map(fn($site) => (int)$site->id, Craft::$app->getSites()->getEditableSites());

        foreach ($pair->sharedSiteIds as $siteId) {
            if (!in_array($siteId, $editable, true)) {
                continue;
            }

            $site = Craft::$app->getSites()->getSiteById($siteId);

            if ($site !== null) {
                $options[] = ['label' => Craft::t('site', $site->name), 'value' => (int)$site->id];
            }
        }

        return $options;
    }
}
