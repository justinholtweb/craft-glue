<?php

declare(strict_types=1);

namespace justinholtweb\glue;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\glue\console\controllers\GlueController;
use justinholtweb\glue\elements\actions\MergeEntries;
use justinholtweb\glue\models\Edition;
use justinholtweb\glue\models\Settings;
use justinholtweb\glue\services\Duplicates;
use justinholtweb\glue\services\History;
use justinholtweb\glue\services\Merger;
use justinholtweb\glue\services\Pairs;
use justinholtweb\glue\services\Presets;
use justinholtweb\glue\services\Previews;
use justinholtweb\glue\services\Rewirer;
use justinholtweb\glue\services\Strategies;
use justinholtweb\glue\twig\GlueVariable;
use Throwable;
use yii\base\Event;

/**
 * Glue — merge two entries into one.
 *
 * @property-read Pairs $pairs
 * @property-read Strategies $strategies
 * @property-read Previews $previews
 * @property-read Merger $merger
 * @property-read Rewirer $rewirer
 * @property-read History $history
 * @property-read Presets $presets
 * @property-read Duplicates $duplicates
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_MERGE = 'glue:merge';
    public const PERMISSION_HISTORY = 'glue:viewHistory';
    public const PERMISSION_PRESETS = 'glue:managePresets';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'glue';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'pairs' => Pairs::class,
                'strategies' => Strategies::class,
                'previews' => Previews::class,
                'merger' => Merger::class,
                'rewirer' => Rewirer::class,
                'history' => History::class,
                'presets' => Presets::class,
                'duplicates' => Duplicates::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerConsoleCommands();
        $this->registerElementActions();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerGarbageCollection();
        $this->registerEntryDetails();
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('glue/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'isPro' => $this->isPro(),
            'targetOptions' => Settings::targetOptions(),
            'seedOptions' => Settings::seedOptions(),
            'dispositionOptions' => Settings::dispositionOptions(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['label'] = Craft::t('glue', 'Glue');
        $item['subnav'] = [];

        if ($this->canViewHistory()) {
            $item['subnav']['history'] = [
                'label' => Craft::t('glue', 'History'),
                'url' => 'glue/history',
            ];
        }

        if ($this->isPro() && $this->canMerge()) {
            $item['subnav']['duplicates'] = [
                'label' => Craft::t('glue', 'Duplicates'),
                'url' => 'glue/duplicates',
            ];
        }

        // A nav item with nothing behind it is worse than no nav item: it is a link that always
        // 403s. If this user can do neither thing, Glue simply is not in their sidebar.
        return $item['subnav'] === [] ? null : $item;
    }

    public function canMerge(): bool
    {
        $user = Craft::$app->getUser();

        return $user->getIsAdmin() || $user->checkPermission(self::PERMISSION_MERGE);
    }

    public function canViewHistory(): bool
    {
        $user = Craft::$app->getUser();

        return $user->getIsAdmin() || $user->checkPermission(self::PERMISSION_HISTORY);
    }

    public function canManagePresets(): bool
    {
        if (!Edition::allowsPresets($this->isPro())) {
            return false;
        }

        $user = Craft::$app->getUser();

        return $user->getIsAdmin() || $user->checkPermission(self::PERMISSION_PRESETS);
    }

    // ------------------------------------------------------------------ registration

    /**
     * Puts “Merge with Glue…” in the entry index's action menu.
     *
     * The action's trigger takes over the click entirely rather than posting to an element
     * action endpoint, because a merge is not something that can be done *to* a selection — it
     * needs a screen, and an element action that silently redirected after a POST would lose the
     * selection and half the browser's back button with it.
     */
    private function registerElementActions(): void
    {
        Event::on(Entry::class, Entry::EVENT_REGISTER_ACTIONS, function(RegisterElementActionsEvent $event) {
            if (!$this->canMerge()) {
                return;
            }

            $event->actions[] = MergeEntries::class;
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['glue'] = 'glue/history/index';
            $event->rules['glue/history'] = 'glue/history/index';
            $event->rules['glue/merge'] = 'glue/merge/index';
            $event->rules['glue/duplicates'] = 'glue/duplicates/index';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('glue', 'Glue'),
                'permissions' => [
                    self::PERMISSION_MERGE => [
                        'label' => Craft::t('glue', 'Merge entries'),
                        'info' => Craft::t('glue', 'Craft’s own permissions still apply — a merge is a save, and a merge that retires its sources is a delete.'),
                    ],
                    self::PERMISSION_HISTORY => [
                        'label' => Craft::t('glue', 'View the merge history'),
                    ],
                    self::PERMISSION_PRESETS => [
                        'label' => Craft::t('glue', 'Save and delete merge presets'),
                        'info' => Craft::t('glue', 'Presets are stored in project config, so this is effectively permission to edit the project’s config.'),
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('glue', GlueVariable::class);
        });
    }

    /**
     * Prunes the history during garbage collection rather than on write.
     *
     * An afternoon of merging should not pay for a DELETE per merge, and the limit is not a
     * correctness constraint — being a hundred rows over it between two nightly runs costs
     * nothing.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            try {
                $this->history->prune();
            } catch (Throwable $e) {
                Craft::warning('Could not prune the Glue history: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * Notes on an entry's edit screen that it is the result of a merge.
     *
     * This is the one thing a merged entry cannot tell you about itself. Its revisions start at
     * the merge and say nothing about the entry that was absorbed, and the absorbed entry's own
     * history ends without explanation — so without this, "where did the other one go" has no
     * answer on the screen where it gets asked.
     *
     * `EVENT_DEFINE_SIDEBAR_HTML` rather than a template hook: Craft 5 edits entries through the
     * generic element editor, and the `cp.entries.edit.details` hook that Craft 3 and 4 had does
     * not exist any more — registering it silently does nothing at all.
     */
    private function registerEntryDetails(): void
    {
        Event::on(Entry::class, Entry::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            $entry = $event->sender;

            if (!$entry instanceof Entry || $entry->id === null) {
                return;
            }

            // A draft or revision shares its canonical entry's history, and the canonical entry's
            // own screen is where it belongs.
            if ($entry->getIsDraft() || $entry->getIsRevision() || !$this->canViewHistory()) {
                return;
            }

            try {
                $merges = $this->history->masked($this->history->forEntry((int)$entry->getCanonicalId()));
            } catch (Throwable) {
                return;
            }

            if ($merges === []) {
                return;
            }

            try {
                $event->html .= Craft::$app->getView()->renderTemplate('glue/_details', [
                    'entry' => $entry,
                    'merges' => $merges,
                ], View::TEMPLATE_MODE_CP);
            } catch (Throwable $e) {
                // A note in a sidebar is never worth taking the entry editor down for.
                Craft::warning('Could not render the Glue panel: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * Gives each command its own top-level name: `glue/merge`, not `glue/glue/merge`.
     *
     * Yii builds a console route from the module id and a *controller* id, so one controller
     * holding three commands would name all three after itself. These are verbs with no resource
     * to sit under, so each id is mapped onto the same class with its own default action.
     */
    private function registerConsoleCommands(): void
    {
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        foreach (['merge', 'duplicates', 'inspect', 'rewire'] as $command) {
            $this->controllerMap[$command] = [
                'class' => GlueController::class,
                'defaultAction' => $command,
            ];
        }
    }
}
