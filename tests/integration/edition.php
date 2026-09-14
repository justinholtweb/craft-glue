<?php
/**
 * Reads or sets Glue's edition, because Craft has no `plugin/switch-edition` console command.
 *
 *     ddev exec php /var/www/craft-glue/tests/integration/edition.php        # prints it
 *     ddev exec php /var/www/craft-glue/tests/integration/edition.php pro    # sets it
 *
 * Switching editions is a project-config write, so this is also the quickest way to put a test
 * site back to Lite and watch the Pro screens start refusing.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$wanted = $argv[1] ?? null;

if ($wanted !== null) {
    Craft::$app->getPlugins()->switchEdition('glue', $wanted);

    // Project config writes are buffered until the end of the *request*, and a script that
    // bootstraps the application without calling `run()` never gets an end of request — so the
    // switch would live only in this process's memory and the next HTTP request would still see
    // the old edition. See `[[craft-plugin-gotchas]]`. `saveModifiedConfigData()` persists to the
    // `projectconfig` table; `writeYamlFiles()` is the separate half that updates
    // `config/project/*.yaml`.
    $projectConfig = Craft::$app->getProjectConfig();
    $projectConfig->saveModifiedConfigData();

    if ($projectConfig->writeYamlAutomatically) {
        $projectConfig->writeYamlFiles();
    }
}

echo Craft::$app->getPlugins()->getPlugin('glue')?->edition ?? 'not installed', "\n";
