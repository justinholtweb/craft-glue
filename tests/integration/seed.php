<?php
/**
 * Fixture for the control-panel smoke test.
 *
 * Prints one line: `<aId> <bId> <sectionId> <entryTypeId>`. With `--clean`, removes everything it
 * made. Kept separate from `checks.php` because the smoke test needs the fixture to *outlive* the
 * PHP process — the screens are then loaded over HTTP by a different request entirely.
 *
 *     ddev exec php /var/www/craft-glue/tests/integration/seed.php
 *     ddev exec php /var/www/craft-glue/tests/integration/seed.php --clean
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;

const HANDLE_PREFIX = 'glueSmoke';

$fields = Craft::$app->getFields();
$entries = Craft::$app->getEntries();

if (in_array('--clean', $argv, true)) {
    $section = $entries->getSectionByHandle(HANDLE_PREFIX . 'Section');

    if ($section !== null) {
        $entries->deleteSection($section);
    }

    foreach (['Block', 'Main'] as $name) {
        foreach ($entries->getAllEntryTypes() as $entryType) {
            if ($entryType->handle === HANDLE_PREFIX . $name) {
                $entries->deleteEntryType($entryType);
            }
        }
    }

    foreach (['Body', 'Topics', 'Blocks', 'BlockText'] as $name) {
        $field = $fields->getFieldByHandle(HANDLE_PREFIX . $name);

        if ($field !== null) {
            $fields->deleteField($field);
        }
    }

    echo "cleaned\n";
    exit(0);
}

function layout(array $elements): FieldLayout
{
    $layout = new FieldLayout(['type' => Entry::class]);
    $tab = new FieldLayoutTab(['name' => 'Content']);
    $tab->setLayout($layout);
    $tab->setElements($elements);
    $layout->setTabs([$tab]);

    return $layout;
}

function field(object $field): object
{
    $existing = Craft::$app->getFields()->getFieldByHandle($field->handle);

    if ($existing !== null) {
        return $existing;
    }

    if (!Craft::$app->getFields()->saveField($field)) {
        throw new RuntimeException('Could not save ' . $field->handle . ': ' . json_encode($field->getErrors()));
    }

    return $field;
}

$body = field(new PlainText(['name' => 'Glue Smoke Body', 'handle' => HANDLE_PREFIX . 'Body', 'multiline' => true]));
$topics = field(new EntriesField(['name' => 'Glue Smoke Topics', 'handle' => HANDLE_PREFIX . 'Topics', 'maxRelations' => null]));
$blockText = field(new PlainText(['name' => 'Glue Smoke Block Text', 'handle' => HANDLE_PREFIX . 'BlockText']));

$blockType = null;

foreach ($entries->getAllEntryTypes() as $candidate) {
    if ($candidate->handle === HANDLE_PREFIX . 'Block') {
        $blockType = $candidate;
    }
}

if ($blockType === null) {
    $blockType = new EntryType(['name' => 'Glue Smoke Block', 'handle' => HANDLE_PREFIX . 'Block', 'hasTitleField' => false]);
    $blockType->setFieldLayout(layout([new CustomField($blockText)]));

    if (!$entries->saveEntryType($blockType)) {
        throw new RuntimeException('Could not save the block type: ' . json_encode($blockType->getErrors()));
    }
}

$blocks = $fields->getFieldByHandle(HANDLE_PREFIX . 'Blocks');

if ($blocks === null) {
    $blocks = new Matrix(['name' => 'Glue Smoke Blocks', 'handle' => HANDLE_PREFIX . 'Blocks']);
    $blocks->setEntryTypes([$blockType]);
    field($blocks);
}

$mainType = null;

foreach ($entries->getAllEntryTypes() as $candidate) {
    if ($candidate->handle === HANDLE_PREFIX . 'Main') {
        $mainType = $candidate;
    }
}

if ($mainType === null) {
    $mainType = new EntryType(['name' => 'Glue Smoke Main', 'handle' => HANDLE_PREFIX . 'Main']);
    $mainType->setFieldLayout(layout([
        new EntryTitleField(),
        new CustomField($body),
        new CustomField($topics),
        new CustomField($blocks),
    ]));

    if (!$entries->saveEntryType($mainType)) {
        throw new RuntimeException('Could not save the entry type: ' . json_encode($mainType->getErrors()));
    }
}

$section = $entries->getSectionByHandle(HANDLE_PREFIX . 'Section');

if ($section === null) {
    $section = new Section([
        'name' => 'Glue Smoke',
        'handle' => HANDLE_PREFIX . 'Section',
        'type' => Section::TYPE_CHANNEL,
    ]);
    $section->setSiteSettings([
        new Section_SiteSettings([
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'hasUrls' => false,
            'enabledByDefault' => true,
        ]),
    ]);
    $section->setEntryTypes([$mainType]);

    if (!$entries->saveSection($section)) {
        throw new RuntimeException('Could not save the section: ' . json_encode($section->getErrors()));
    }
}

function entry(Section $section, EntryType $type, string $title, array $values = []): Entry
{
    $existing = Entry::find()->sectionId($section->id)->title($title)->status(null)->one();

    if ($existing instanceof Entry) {
        return $existing;
    }

    $entry = new Entry(['sectionId' => $section->id, 'typeId' => $type->id, 'title' => $title]);

    foreach ($values as $handle => $value) {
        $entry->setFieldValue($handle, $value);
    }

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException("Could not save “$title”: " . json_encode($entry->getErrors()));
    }

    return $entry;
}

$topicOne = entry($section, $mainType, 'Smoke Topic One');
$topicTwo = entry($section, $mainType, 'Smoke Topic Two');

$a = entry($section, $mainType, 'Smoke Alpha', [
    $body->handle => "Alpha body.\nSecond line.",
    $topics->handle => [$topicOne->id],
    $blocks->handle => [
        'sortOrder' => ['new1'],
        'entries' => ['new1' => ['type' => $blockType->handle, 'enabled' => true, 'fields' => [$blockText->handle => 'Alpha block']]],
    ],
]);

$b = entry($section, $mainType, 'Smoke Beta', [
    $body->handle => 'Beta body.',
    $topics->handle => [$topicTwo->id],
    $blocks->handle => [
        'sortOrder' => ['new1'],
        'entries' => ['new1' => ['type' => $blockType->handle, 'enabled' => true, 'fields' => [$blockText->handle => 'Beta block']]],
    ],
]);

printf("%d %d %d %d\n", $a->id, $b->id, $section->id, $mainType->id);
