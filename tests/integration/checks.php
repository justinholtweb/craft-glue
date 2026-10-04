<?php
/**
 * Glue integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-glue/tests/integration/checks.php
 *
 * These are integration checks and not unit tests on purpose. Almost everything that can go
 * wrong in a merge goes wrong at the boundary between Glue and Craft — whether a serialized
 * Matrix value handed to a different owner copies the blocks or steals them, whether `null`
 * clears a relation field or silently leaves it alone, whether a rewired relation reaches the
 * content column as well as the `relations` table. None of those can be observed without a
 * database and a real element save.
 *
 * The whole fixture — a section, two entry types, a Matrix block type, eight fields and a
 * handful of entries — is created at the start and torn down at the end, so the run is
 * idempotent and leaves the test site as it found it.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Table as TableField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use justinholtweb\glue\db\Table as GlueTable;
use justinholtweb\glue\models\Edition;
use justinholtweb\glue\models\MergePlan;
use justinholtweb\glue\models\Settings;
use justinholtweb\glue\models\Strategy;
use justinholtweb\glue\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage()
            . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$fields = Craft::$app->getFields();
$entries = Craft::$app->getEntries();
$elements = Craft::$app->getElements();
$siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

Craft::$app->getPlugins()->switchEdition('glue', Plugin::EDITION_PRO);

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$made = ['fields' => [], 'entryTypes' => [], 'sections' => []];

/**
 * Removes everything the run created, in the order the foreign keys allow.
 *
 * Registered as a shutdown function rather than only called at the end, so a fatal halfway
 * through does not leave a test site littered with `glueBody_a1b2c3` fields that the next run
 * then collides with.
 */
function teardown(array &$made): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    foreach ($made['sections'] as $section) {
        try {
            Craft::$app->getEntries()->deleteSection($section);
        } catch (Throwable) {
        }
    }

    foreach ($made['entryTypes'] as $entryType) {
        try {
            Craft::$app->getEntries()->deleteEntryType($entryType);
        } catch (Throwable) {
        }
    }

    foreach ($made['fields'] as $field) {
        try {
            Craft::$app->getFields()->deleteField($field);
        } catch (Throwable) {
        }
    }
}

register_shutdown_function(fn() => teardown($made));

// ---------------------------------------------------------------- the fixture

/**
 * A one-tab field layout.
 *
 * The tab has to be told its layout **before** its elements: `FieldLayoutTab::setElements()`
 * reaches for `getLayout()` to give each element its own back-reference, and a tab constructed
 * with `['elements' => …]` in one go throws before it is ever saved.
 *
 * @param array<int, \craft\base\FieldLayoutElement> $elements
 */
function makeLayout(array $elements): FieldLayout
{
    $layout = new FieldLayout(['type' => Entry::class]);
    $tab = new FieldLayoutTab(['name' => 'Content']);
    $tab->setLayout($layout);
    $tab->setElements($elements);
    $layout->setTabs([$tab]);

    return $layout;
}

function makeField(object $field, array &$made): object
{
    if (!Craft::$app->getFields()->saveField($field)) {
        throw new RuntimeException('Could not save field ' . $field->handle . ': ' . json_encode($field->getErrors()));
    }

    $made['fields'][] = $field;

    return $field;
}

$body = makeField(new PlainText([
    'name' => "Glue Body $suffix",
    'handle' => "glueBody$suffix",
    'multiline' => true,
]), $made);

$summary = makeField(new PlainText([
    'name' => "Glue Summary $suffix",
    'handle' => "glueSummary$suffix",
]), $made);

$topics = makeField(new EntriesField([
    'name' => "Glue Topics $suffix",
    'handle' => "glueTopics$suffix",
    'maxRelations' => null,
]), $made);

$rows = makeField(new TableField([
    'name' => "Glue Rows $suffix",
    'handle' => "glueRows$suffix",
    'columns' => [
        'col1' => ['heading' => 'Name', 'handle' => 'name', 'type' => 'singleline'],
        'col2' => ['heading' => 'Note', 'handle' => 'note', 'type' => 'singleline'],
    ],
]), $made);

$flag = makeField(new Lightswitch([
    'name' => "Glue Flag $suffix",
    'handle' => "glueFlag$suffix",
]), $made);

$count = makeField(new Number([
    'name' => "Glue Count $suffix",
    'handle' => "glueCount$suffix",
]), $made);

$blockText = makeField(new PlainText([
    'name' => "Glue Block Text $suffix",
    'handle' => "glueBlockText$suffix",
]), $made);

// The Matrix block type has to exist before the Matrix field that lists it.
$blockType = new EntryType([
    'name' => "Glue Block $suffix",
    'handle' => "glueBlock$suffix",
    'hasTitleField' => false,
]);
$blockType->setFieldLayout(makeLayout([new CustomField($blockText)]));

if (!$entries->saveEntryType($blockType)) {
    throw new RuntimeException('Could not save the block type: ' . json_encode($blockType->getErrors()));
}

$made['entryTypes'][] = $blockType;

$blocks = new Matrix([
    'name' => "Glue Blocks $suffix",
    'handle' => "glueBlocks$suffix",
]);
$blocks->setEntryTypes([$blockType]);
makeField($blocks, $made);

$mainType = new EntryType([
    'name' => "Glue Main $suffix",
    'handle' => "glueMain$suffix",
]);
$mainType->setFieldLayout(makeLayout(array_merge(
    [new EntryTitleField()],
    array_map(
        fn($field) => new CustomField($field),
        [$body, $summary, $topics, $rows, $flag, $count, $blocks],
    ),
)));

if (!$entries->saveEntryType($mainType)) {
    throw new RuntimeException('Could not save the main entry type: ' . json_encode($mainType->getErrors()));
}

$made['entryTypes'][] = $mainType;

// A second type with a *subset* of the fields, for the "what gets dropped" checks.
$altType = new EntryType([
    'name' => "Glue Alt $suffix",
    'handle' => "glueAlt$suffix",
]);
$altType->setFieldLayout(makeLayout([
    new EntryTitleField(),
    new CustomField($body),
    new CustomField($summary),
]));

if (!$entries->saveEntryType($altType)) {
    throw new RuntimeException('Could not save the alt entry type: ' . json_encode($altType->getErrors()));
}

$made['entryTypes'][] = $altType;

$section = new Section([
    'name' => "Glue Section $suffix",
    'handle' => "glueSection$suffix",
    'type' => Section::TYPE_CHANNEL,
]);
$section->setSiteSettings([
    new Section_SiteSettings(['siteId' => $siteId, 'hasUrls' => false, 'enabledByDefault' => true]),
]);
$section->setEntryTypes([$mainType, $altType]);

if (!$entries->saveSection($section)) {
    throw new RuntimeException('Could not save the section: ' . json_encode($section->getErrors()));
}

$made['sections'] = [$section];

function makeEntry(Section $section, EntryType $type, string $title, array $values = []): Entry
{
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $type->id;
    $entry->title = $title;

    foreach ($values as $handle => $value) {
        $entry->setFieldValue($handle, $value);
    }

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException("Could not save entry “$title”: " . json_encode($entry->getErrors()));
    }

    return $entry;
}

$t1 = makeEntry($section, $altType, "Topic One $suffix");
$t2 = makeEntry($section, $altType, "Topic Two $suffix");
$t3 = makeEntry($section, $altType, "Topic Three $suffix");

$blockTypeHandle = $blockType->handle;

$a = makeEntry($section, $mainType, "Alpha $suffix", [
    $body->handle => 'Alpha body.',
    $summary->handle => 'Alpha summary',
    $topics->handle => [$t1->id, $t2->id],
    $rows->handle => [
        ['col1' => 'one', 'col2' => 'from A'],
        ['col1' => 'shared', 'col2' => 'same'],
    ],
    $flag->handle => true,
    $count->handle => 5,
    $blocks->handle => [
        'sortOrder' => ['new1', 'new2'],
        'entries' => [
            'new1' => ['type' => $blockTypeHandle, 'enabled' => true, 'fields' => [$blockText->handle => 'A block one']],
            'new2' => ['type' => $blockTypeHandle, 'enabled' => true, 'fields' => [$blockText->handle => 'A block two']],
        ],
    ],
]);

$b = makeEntry($section, $mainType, "Beta $suffix", [
    $body->handle => 'Beta body.',
    $summary->handle => '',
    $topics->handle => [$t2->id, $t3->id],
    $rows->handle => [
        ['col1' => 'two', 'col2' => 'from B'],
        ['col1' => 'shared', 'col2' => 'same'],
    ],
    $flag->handle => false,
    $count->handle => 7,
    $blocks->handle => [
        'sortOrder' => ['new1'],
        'entries' => [
            'new1' => ['type' => $blockTypeHandle, 'enabled' => true, 'fields' => [$blockText->handle => 'B block one']],
        ],
    ],
]);

// Something that points at both, so rewiring has work to do.
$referencer = makeEntry($section, $mainType, "Referencer $suffix", [
    $topics->handle => [$a->id, $b->id, $t1->id],
]);

$bBlockIds = array_map(fn(Entry $block) => (int)$block->id, $b->getFieldValue($blocks->handle)->all());

section('Fixture');
check('the fixture built', fn() => $a->id > 0 && $b->id > 0 && count($bBlockIds) === 1);

function plan(int $aId, int $bId, int $siteId, array $overrides = []): MergePlan
{
    return new MergePlan(array_merge([
        'aId' => $aId,
        'bId' => $bId,
        'siteId' => $siteId,
    ], $overrides));
}

// ---------------------------------------------------------------- strategies

section('Strategies — deciding what “Both” means');

$strategies = $plugin->strategies;

check('Matrix combines as blocks', fn() => $strategies->kindFor($blocks, [], []) === Strategy::KIND_BLOCKS);
check('a table combines as rows', fn() => $strategies->kindFor($rows, [], []) === Strategy::KIND_ROWS);
check('a relation field combines as a list', fn() => $strategies->kindFor($topics, [], []) === Strategy::KIND_LIST);
check('a multiline text field combines as text', fn() => $strategies->kindFor($body, 'x', 'y') === Strategy::KIND_TEXT);
check('a lightswitch does not combine', fn() => $strategies->kindFor($flag, true, false) === Strategy::KIND_NONE);
check('a number does not combine', fn() => $strategies->kindFor($count, 5, 7) === Strategy::KIND_NONE);

// CKEditor implements ElementContainerFieldInterface whether or not it embeds anything, so a
// container check alone refuses "Both" on the most common rich text field there is.
if (class_exists(craft\ckeditor\Field::class)) {
    $ckeditor = new craft\ckeditor\Field(['handle' => 'glueCk']);

    check('CKEditor prose combines as text', fn() => $strategies->kindFor($ckeditor, '<p>a</p>', '<p>b</p>') === Strategy::KIND_TEXT);
    check('CKEditor with a nested entry does not combine', fn() => $strategies->kindFor($ckeditor, '<p>a</p><craft-entry data-entry-id="5"></craft-entry>', '<p>b</p>') === Strategy::KIND_NONE);
}

check('a rich text preview keeps paragraphs apart', function() use ($plugin, $body) {
    $preview = $plugin->previews->of($body, '<p>The library reopened.</p><p>The work took a year.</p>');

    return str_contains((string)$preview->body, 'reopened. The') ?: (string)$preview->body;
});

check('a date previews as a date, not a word', function() use ($plugin) {
    $preview = $plugin->previews->of(new craft\fields\Date(['handle' => 'glueDate']), '2026-03-14T00:00:00-07:00');

    return !str_contains((string)$preview->summary, 'word') ?: (string)$preview->summary;
});

check('zero is a value, not emptiness', fn() => $strategies->isEmptyValue(0) === false
    && $strategies->isEmptyValue('0') === false
    && $strategies->isEmptyValue(false) === false);
check('null, empty string and empty array are emptiness', fn() => $strategies->isEmptyValue(null)
    && $strategies->isEmptyValue('')
    && $strategies->isEmptyValue([]));
check('a Matrix value with no blocks is emptiness', fn() => $strategies->isEmptyValue(['sortOrder' => [], 'entries' => []]));

check('the digest ignores int/string drift', fn() => $strategies->digest($topics, [1, 2]) === $strategies->digest($topics, ['1', '2']));
check('the digest still separates different values', fn() => $strategies->digest($topics, [1, 2]) !== $strategies->digest($topics, [1, 3]));

// ---------------------------------------------------------------- the edition boundary

section('Editions');

check('Lite gets every combine strategy', fn() => $strategies->kindFor($topics, [], []) === Strategy::KIND_LIST);
check('rewiring is Pro', fn() => Edition::allowsRewiring(false) === false && Edition::allowsRewiring(true) === true);
check('presets are Pro', fn() => Edition::allowsPresets(false) === false && Edition::allowsPresets(true) === true);
check('the console is Pro', fn() => Edition::allowsConsole(false) === false && Edition::allowsConsole(true) === true);
check('the duplicate finder is Pro', fn() => Edition::allowsDuplicateFinder(false) === false);
check('cross-site merging is Pro', fn() => Edition::allowsAllSites(false) === false);

// ---------------------------------------------------------------- inspection

section('Inspection — lining the two entries up');

$basePlan = plan((int)$a->id, (int)$b->id, $siteId, ['entryTypeId' => (int)$mainType->id]);
$pair = $plugin->pairs->inspect($plugin->pairs->load((int)$a->id, (int)$b->id, $siteId), $basePlan);

check('every field of the target type is a row', fn() => count($pair->fields) === 7
    ?: 'got ' . count($pair->fields) . ': ' . implode(', ', array_keys($pair->fields)));

check('a field both sides fill is contested', fn() => $pair->fields[$body->handle]->contested() === true);

check('a field with identical values is not contested', function() use ($pair, $rows) {
    // Both sides have a "shared/same" row but different first rows, so the table *is*
    // contested; the summary is the one to check — A has one and B has none.
    return $pair->fields[$rows->handle]->contested() === true;
});

check('an empty side seeds the other one', fn() => $pair->fields[$summary->handle]->suggested === Strategy::A);

check('both sides present seeds A by default', fn() => $pair->fields[$body->handle]->suggested === Strategy::A);

check('“Both” is offered for Matrix', fn() => in_array(Strategy::COMBINE, $pair->fields[$blocks->handle]->availableStrategies(), true));
check('“Both” is not offered for a lightswitch', fn() => !in_array(Strategy::COMBINE, $pair->fields[$flag->handle]->availableStrategies(), true));

check('a preview names the related entries', function() use ($pair, $topics, $t1) {
    $preview = $pair->fields[$topics->handle]->aPreview;

    return count($preview->elements) === 2
        && (int)$preview->elements[0]->id === (int)$t1->id;
});

$altPlan = plan((int)$a->id, (int)$b->id, $siteId, ['entryTypeId' => (int)$altType->id]);
$altPair = $plugin->pairs->inspect($plugin->pairs->load((int)$a->id, (int)$b->id, $siteId), $altPlan);

check('a narrower target entry type reports what is dropped', fn() => count($altPair->droppedFromA) === 5
    ?: 'dropped ' . implode(', ', array_keys($altPair->droppedFromA)));

check('dropping fields raises a warning', fn() => count(array_filter(
    $altPair->warnings,
    fn(string $w) => str_contains($w, 'cannot hold'),
)) === 2);

// ---------------------------------------------------------------- merging into a new entry

section('Merging into a new entry');

$newPlan = plan((int)$a->id, (int)$b->id, $siteId, [
    'target' => Settings::TARGET_NEW,
    'sectionId' => (int)$section->id,
    'entryTypeId' => (int)$mainType->id,
    'choices' => [
        $body->handle => Strategy::COMBINE,
        $summary->handle => Strategy::A,
        $topics->handle => Strategy::COMBINE,
        $rows->handle => Strategy::COMBINE,
        $flag->handle => Strategy::B,
        $count->handle => Strategy::B,
        $blocks->handle => Strategy::COMBINE,
    ],
    'titleFrom' => MergePlan::TITLE_CUSTOM,
    'title' => "Merged $suffix",
]);

$result = $plugin->merger->apply($newPlan);
$merged = $result->entry;

check('a new entry was created', fn() => $merged instanceof Entry
    && (int)$merged->id !== (int)$a->id
    && (int)$merged->id !== (int)$b->id);

check('the custom title was used', fn() => $merged->title === "Merged $suffix");

check('both sources still exist', function() use ($a, $b, $siteId) {
    return Entry::find()->id([$a->id, $b->id])->siteId($siteId)->status(null)->count() == 2;
});

check('combined text is joined with the separator', function() use ($merged, $body) {
    $value = $merged->getFieldValue($body->handle);

    return $value === "Alpha body.\n\nBeta body." ?: 'got ' . var_export($value, true);
});

check('combined relations are a union in A’s order', function() use ($merged, $topics, $t1, $t2, $t3) {
    $ids = $merged->getFieldValue($topics->handle)->ids();

    return $ids === [(int)$t1->id, (int)$t2->id, (int)$t3->id] ?: 'got ' . json_encode($ids);
});

check('combined table rows append and drop the duplicate', function() use ($merged, $rows) {
    $value = $merged->getFieldValue($rows->handle);

    return count($value) === 3 ?: 'got ' . count($value) . ' rows: ' . json_encode($value);
});

check('a lightswitch takes the chosen side', fn() => $merged->getFieldValue($flag->handle) === false);
check('a number takes the chosen side', fn() => (int)$merged->getFieldValue($count->handle) === 7);

$mergedBlocks = $merged->getFieldValue($blocks->handle)->all();

check('combined Matrix has A’s blocks then B’s', function() use ($mergedBlocks, $blockText) {
    $texts = array_map(fn(Entry $block) => $block->getFieldValue($blockText->handle), $mergedBlocks);

    return $texts === ['A block one', 'A block two', 'B block one'] ?: 'got ' . json_encode($texts);
});

// The load-bearing safety property of the whole plugin. If Matrix's normaliser re-owned B's
// blocks instead of copying them, B would look fine right up until it was trashed.
check('B’s Matrix blocks were copied, not moved', function() use ($mergedBlocks, $bBlockIds) {
    $mergedIds = array_map(fn(Entry $block) => (int)$block->id, $mergedBlocks);

    return array_intersect($mergedIds, $bBlockIds) === [] ?: 'shared block IDs: ' . json_encode(array_intersect($mergedIds, $bBlockIds));
});

check('B still has its own Matrix block', function() use ($b, $blocks, $siteId, $bBlockIds) {
    $fresh = Entry::find()->id($b->id)->siteId($siteId)->status(null)->one();
    $ids = array_map(fn(Entry $block) => (int)$block->id, $fresh->getFieldValue($blocks->handle)->all());

    return $ids === $bBlockIds ?: 'got ' . json_encode($ids);
});

check('no merged block is still owned by a source', function() use ($mergedBlocks, $merged) {
    foreach ($mergedBlocks as $block) {
        if ((int)$block->getPrimaryOwnerId() !== (int)$merged->id) {
            return 'block ' . $block->id . ' belongs to ' . $block->getPrimaryOwnerId();
        }
    }

    return true;
});

check('the merge produced no ownership warning', fn() => $result->warnings === []
    ?: implode(' | ', $result->warnings));

check('the merge was recorded', fn() => $result->logId !== null);

check('the history row names both sources', function() use ($result, $a, $b) {
    $row = (new Query())->from(GlueTable::MERGES)->where(['id' => $result->logId])->one();

    return (int)$row['aId'] === (int)$a->id
        && (int)$row['bId'] === (int)$b->id
        && $row['aTitle'] === $a->title;
});

// ---------------------------------------------------------------- “Neither” actually empties

section('“Neither” — the null-is-not-empty trap');

$blankPlan = plan((int)$a->id, (int)$b->id, $siteId, [
    'target' => Settings::TARGET_NEW,
    'sectionId' => (int)$section->id,
    'entryTypeId' => (int)$mainType->id,
    'choices' => array_fill_keys(
        [$body->handle, $summary->handle, $topics->handle, $rows->handle, $flag->handle, $count->handle, $blocks->handle],
        Strategy::BLANK,
    ),
]);

$blankResult = $plugin->merger->apply($blankPlan);
$blankEntry = $blankResult->entry;

check('“Neither” empties a relation field', fn() => $blankEntry->getFieldValue($topics->handle)->count() == 0);
check('“Neither” empties a Matrix field', fn() => count($blankEntry->getFieldValue($blocks->handle)->all()) === 0);
check('“Neither” empties a table field', fn() => empty($blankEntry->getFieldValue($rows->handle)));
check('“Neither” empties a text field', fn() => in_array($blankEntry->getFieldValue($body->handle), [null, ''], true));

// ---------------------------------------------------------------- merging into a source

section('Merging into one of the sources');

$intoA = makeEntry($section, $mainType, "Into A $suffix", [
    $body->handle => 'Into A body.',
    $topics->handle => [$t1->id],
    $blocks->handle => [
        'sortOrder' => ['new1'],
        'entries' => ['new1' => ['type' => $blockTypeHandle, 'enabled' => true, 'fields' => [$blockText->handle => 'Keeper']]],
    ],
]);

$intoB = makeEntry($section, $mainType, "Into B $suffix", [
    $body->handle => 'Into B body.',
    $topics->handle => [$t3->id],
    $blocks->handle => [
        'sortOrder' => ['new1'],
        'entries' => ['new1' => ['type' => $blockTypeHandle, 'enabled' => true, 'fields' => [$blockText->handle => 'Incoming']]],
    ],
]);

$intoAId = (int)$intoA->id;
$keeperBlockId = (int)$intoA->getFieldValue($blocks->handle)->all()[0]->id;

$intoPlan = plan($intoAId, (int)$intoB->id, $siteId, [
    'target' => Settings::TARGET_A,
    'choices' => [
        $body->handle => Strategy::COMBINE,
        $topics->handle => Strategy::COMBINE,
        $blocks->handle => Strategy::COMBINE,
    ],
    'disposition' => Settings::DISPOSITION_TRASH,
]);

$intoResult = $plugin->merger->apply($intoPlan);

check('merging into A keeps A’s ID', fn() => (int)$intoResult->entry->id === $intoAId);
check('merging into A creates nothing new', fn() => $intoResult->createdNew === false);

check('A’s own Matrix blocks keep their IDs', function() use ($intoResult, $blocks, $keeperBlockId) {
    $ids = array_map(fn(Entry $block) => (int)$block->id, $intoResult->entry->getFieldValue($blocks->handle)->all());

    return $ids[0] === $keeperBlockId ?: 'got ' . json_encode($ids) . ', expected to start with ' . $keeperBlockId;
});

check('B’s blocks were added to A', fn() => count($intoResult->entry->getFieldValue($blocks->handle)->all()) === 2);

check('the retired source was trashed', function() use ($intoB, $siteId) {
    return Entry::find()->id($intoB->id)->siteId($siteId)->status(null)->count() == 0
        && Entry::find()->id($intoB->id)->siteId($siteId)->status(null)->trashed()->count() == 1;
});

check('the surviving source was not trashed', fn() => Entry::find()->id($intoAId)->siteId($siteId)->status(null)->count() == 1);

// ---------------------------------------------------------------- disposition

section('Disposition');

$dx = makeEntry($section, $altType, "Disable X $suffix");
$dy = makeEntry($section, $altType, "Disable Y $suffix");

$plugin->merger->apply(plan((int)$dx->id, (int)$dy->id, $siteId, [
    'target' => Settings::TARGET_NEW,
    'sectionId' => (int)$section->id,
    'entryTypeId' => (int)$altType->id,
    'disposition' => Settings::DISPOSITION_DISABLE,
]));

check('“Disable” disables both sources', function() use ($dx, $dy, $siteId) {
    $found = Entry::find()->id([$dx->id, $dy->id])->siteId($siteId)->status(null)->all();

    foreach ($found as $entry) {
        if ($entry->enabled) {
            return $entry->title . ' is still enabled';
        }
    }

    return count($found) === 2;
});

// ---------------------------------------------------------------- rewiring

section('Rewiring — the content column, not just the relations table');

$rw1 = makeEntry($section, $altType, "Rewire Old $suffix");
$rw2 = makeEntry($section, $altType, "Rewire Other $suffix");
$pointer = makeEntry($section, $mainType, "Pointer $suffix", [
    $topics->handle => [$t1->id, $rw1->id],
]);

$rewirePlan = plan((int)$rw1->id, (int)$rw2->id, $siteId, [
    'target' => Settings::TARGET_NEW,
    'sectionId' => (int)$section->id,
    'entryTypeId' => (int)$altType->id,
    'disposition' => Settings::DISPOSITION_TRASH,
    'rewire' => true,
]);

$rewireResult = $plugin->merger->apply($rewirePlan);
$survivorId = (int)$rewireResult->entry->id;

check('the referencing entry was rewired', fn() => $rewireResult->rewired === 1
    ?: 'rewired ' . $rewireResult->rewired);

check('the field value points at the merged entry', function() use ($pointer, $topics, $siteId, $survivorId, $t1) {
    $fresh = Entry::find()->id($pointer->id)->siteId($siteId)->status(null)->one();
    $ids = $fresh->getFieldValue($topics->handle)->ids();

    return $ids === [(int)$t1->id, $survivorId] ?: 'got ' . json_encode($ids);
});

check('the content column was rewritten, not just the relations table', function() use ($pointer, $topics, $siteId, $survivorId) {
    $content = (new Query())
        ->select(['content'])
        ->from(CraftTable::ELEMENTS_SITES)
        ->where(['elementId' => $pointer->id, 'siteId' => $siteId])
        ->scalar();

    return str_contains((string)$content, (string)$survivorId)
        ?: 'the merged entry ID is not in the content JSON';
});

check('the relations table agrees', function() use ($pointer, $survivorId) {
    return (new Query())
        ->from(CraftTable::RELATIONS)
        ->where(['sourceId' => $pointer->id, 'targetId' => $survivorId])
        ->exists();
});

// Revisions are excluded on purpose. A revision is a record of what an entry was, so its
// relations legitimately still name the retired entry — rewriting them would be falsifying the
// history, and a check that did not exclude them could never pass.
check('no live entry still points at the retired entry', function() use ($rw1) {
    $leftover = (new Query())
        ->select(['r.sourceId'])
        ->from(['r' => CraftTable::RELATIONS])
        ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[r.sourceId]]')
        ->where(['r.targetId' => $rw1->id, 'e.revisionId' => null, 'e.draftId' => null])
        ->column();

    return $leftover === [] ?: 'still referenced by ' . json_encode($leftover);
});

check('a revision keeps its own record of the old relation', function() use ($rw1) {
    return (new Query())
        ->from(['r' => CraftTable::RELATIONS])
        ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[r.sourceId]]')
        ->where(['r.targetId' => $rw1->id])
        ->andWhere(['not', ['e.revisionId' => null]])
        ->exists();
});

// The threshold is "more than", and 0 is the never-queue sentinel — so the smallest case that
// queues is two referencing elements against a threshold of one.
check('rewiring goes to the queue above the threshold', function() use ($plugin, $section, $altType, $mainType, $topics, $siteId) {
    $settings = $plugin->getSettings();
    $before = $settings->rewireThreshold;
    $settings->rewireThreshold = 1;

    $old = makeEntry($section, $altType, 'Queue Old ' . uniqid());
    $other = makeEntry($section, $altType, 'Queue Other ' . uniqid());
    makeEntry($section, $mainType, 'Queue Pointer A ' . uniqid(), [$topics->handle => [$old->id]]);
    makeEntry($section, $mainType, 'Queue Pointer B ' . uniqid(), [$topics->handle => [$old->id]]);

    $queueBefore = Craft::$app->getQueue()->getTotalJobs();

    try {
        $result = $plugin->merger->apply(plan((int)$old->id, (int)$other->id, $siteId, [
            'target' => Settings::TARGET_NEW,
            'sectionId' => (int)$section->id,
            'entryTypeId' => (int)$altType->id,
            'rewire' => true,
        ]));
    } finally {
        $settings->rewireThreshold = $before;
    }

    return ($result->rewireQueued === true
        && $result->rewired === 2
        && Craft::$app->getQueue()->getTotalJobs() > $queueBefore)
        ?: 'queued=' . var_export($result->rewireQueued, true) . ' counted=' . $result->rewired;
});

check('the queued job rewires when it runs', function() use ($plugin, $section, $altType, $mainType, $topics, $siteId) {
    $old = makeEntry($section, $altType, 'Job Old ' . uniqid());
    $survivor = makeEntry($section, $altType, 'Job Survivor ' . uniqid());
    $pointer = makeEntry($section, $mainType, 'Job Pointer ' . uniqid(), [$topics->handle => [$old->id]]);

    // Trash the old entry first, which is the state the job actually runs in — after the merge
    // has committed and the sources have been retired.
    Craft::$app->getElements()->deleteElement($old);

    (new \justinholtweb\glue\jobs\RewireRelations([
        'elementIds' => [(int)$pointer->id],
        'retiredIds' => [(int)$old->id],
        'survivorId' => (int)$survivor->id,
    ]))->execute(Craft::$app->getQueue());

    $fresh = Entry::find()->id($pointer->id)->siteId($siteId)->status(null)->one();

    return $fresh->getFieldValue($topics->handle)->ids() === [(int)$survivor->id]
        ?: 'got ' . json_encode($fresh->getFieldValue($topics->handle)->ids());
});

// ---------------------------------------------------------------- dry run

section('Dry run');

$dryBefore = (new Query())->from(GlueTable::MERGES)->count();
$dry = $plugin->merger->apply(plan((int)$a->id, (int)$b->id, $siteId, [
    'target' => Settings::TARGET_NEW,
    'sectionId' => (int)$section->id,
    'entryTypeId' => (int)$mainType->id,
    'choices' => [$body->handle => Strategy::COMBINE],
]), true);

check('a dry run saves nothing', fn() => $dry->saved === false
    && (new Query())->from(GlueTable::MERGES)->count() == $dryBefore);

check('a dry run still resolves every value', fn() => $dry->values[$body->handle] === "Alpha body.\n\nBeta body.");

check('a dry run previews the result', fn() => isset($dry->previews[$topics->handle])
    && $dry->previews[$topics->handle]->empty === false);

// ---------------------------------------------------------------- refusals

section('Refusals');

check('an entry cannot be merged with itself', function() use ($a, $siteId, $plugin) {
    try {
        $plugin->pairs->load((int)$a->id, (int)$a->id, $siteId);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'itself');
    }

    return 'no exception';
});

check('a Matrix block cannot be merged', function() use ($plugin, $siteId, $a, $blocks) {
    $blockId = (int)$a->getFieldValue($blocks->handle)->all()[0]->id;

    try {
        $plugin->pairs->load($blockId, (int)$a->id, $siteId);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'nested entry');
    }

    return 'no exception';
});

check('a missing entry is refused', function() use ($plugin, $siteId, $a) {
    try {
        $plugin->pairs->load((int)$a->id, 99999999, $siteId);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'could not be loaded');
    }

    return 'no exception';
});

// Loading a pair is reading both entries — every screen that loads one shows their values — so a
// user who cannot view one must not get it as the other half of a merge.
check('an entry the user cannot view is refused', function() use ($plugin, $siteId, $a, $b) {
    $users = Craft::$app->getUser();
    $previous = $users->getIdentity();
    $users->setIdentity(new craft\elements\User(['username' => 'glue-nobody', 'admin' => false]));

    try {
        $plugin->pairs->load((int)$a->id, (int)$b->id, $siteId);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'not allowed to view');
    } finally {
        $users->setIdentity($previous);
    }

    return 'no exception';
});

// A rewire saves elements nobody opened. For a logged-in user it must only touch what that user
// could have edited by hand — otherwise a merge is a way to put your entry into other people's pages.
check('a rewire leaves alone what the user cannot edit', function() use ($plugin, $section, $mainType, $topics, $a, $suffix) {
    $target = makeEntry($section, $mainType, "Rewire target $suffix");
    $holder = makeEntry($section, $mainType, "Not yours $suffix", [$topics->handle => [$target->id]]);

    $users = Craft::$app->getUser();
    $previous = $users->getIdentity();
    $users->setIdentity(new craft\elements\User(['username' => 'glue-nobody', 'admin' => false]));
    $result = new justinholtweb\glue\models\MergeResult();

    try {
        $count = $plugin->rewirer->rewire([(int)$target->id], (int)$a->id, $result);
    } finally {
        $users->setIdentity($previous);
    }

    $after = Entry::find()->id($holder->id)->status(null)->one();
    $ids = $after->getFieldValue($topics->handle)->status(null)->ids();

    if ($count !== 0 || $ids !== [(int)$target->id]) {
        return sprintf('rewired %d, holder now relates to %s', $count, json_encode($ids));
    }

    return str_contains(implode(' ', $result->warnings), 'not yours to edit') ?: 'no warning';
});

check('a field choice must look like a field handle', function() use ($a, $b, $siteId) {
    $plan = plan((int)$a->id, (int)$b->id, $siteId, ['choices' => ['nested.key' => Strategy::A]]);

    return !$plan->validate(['choices']);
});

// ---------------------------------------------------------------- presets

section('Presets');

$before = $plugin->getSettings()->presets;

$presetHandle = $plugin->presets->save("Glue Test $suffix", $newPlan);

check('a preset is saved under a kebab-case handle', fn() => str_starts_with($presetHandle, 'glue-test-'));

check('a preset stores the field choices', function() use ($plugin, $presetHandle, $body) {
    $preset = $plugin->presets->get($presetHandle);

    return ($preset['choices'][$body->handle] ?? null) === Strategy::COMBINE;
});

check('a preset does not remember the pair it came from', function() use ($plugin, $presetHandle) {
    $preset = $plugin->presets->get($presetHandle);

    return !isset($preset['aId']) && !isset($preset['bId']);
});

check('applying a preset overlays the choices and leaves the pair alone', function() use ($plugin, $presetHandle, $a, $b, $siteId, $body) {
    $fresh = plan((int)$a->id, (int)$b->id, $siteId);
    $applied = $plugin->presets->applyTo($fresh, $presetHandle);

    return $applied->aId === (int)$a->id
        && $applied->bId === (int)$b->id
        && ($applied->choices[$body->handle] ?? null) === Strategy::COMBINE
        && $applied->preset === $presetHandle;
});

check('an unknown handle in a preset is left to the seeding rule, not blanked', function() use ($plugin, $a, $b, $siteId, $mainType, $summary) {
    $applied = $plugin->presets->applyTo(
        plan((int)$a->id, (int)$b->id, $siteId, ['entryTypeId' => (int)$mainType->id]),
        'glue-nonexistent-preset',
    );
    $pair = $plugin->pairs->inspect($plugin->pairs->load((int)$a->id, (int)$b->id, $siteId), $applied);

    return $plugin->merger->strategyFor($pair->fields[$summary->handle], $applied) === Strategy::A;
});

check('deleting a preset removes it', function() use ($plugin, $presetHandle) {
    return $plugin->presets->delete($presetHandle) && $plugin->presets->get($presetHandle) === null;
});

// Restore whatever the site had, so running the checks does not reconfigure it.
Craft::$app->getPlugins()->savePluginSettings(
    $plugin,
    array_merge($plugin->getSettings()->toArray(), ['presets' => $before]),
);

// ---------------------------------------------------------------- duplicates

section('Duplicate finder');

$dupA = makeEntry($section, $altType, "Same Name $suffix");
$dupB = makeEntry($section, $altType, "Same Name $suffix");

$groups = $plugin->duplicates->find((int)$section->id, $siteId);
$group = null;

foreach ($groups as $candidate) {
    if ($candidate['value'] === "Same Name $suffix") {
        $group = $candidate;
        break;
    }
}

check('entries sharing a title are found', fn() => $group !== null && $group['count'] === 2);
check('the group lists both IDs', fn() => $group !== null
    && in_array((int)$dupA->id, $group['ids'], true)
    && in_array((int)$dupB->id, $group['ids'], true));

check('Matrix blocks are not offered as duplicates', function() use ($groups, $bBlockIds) {
    foreach ($groups as $candidate) {
        if (array_intersect($candidate['ids'], $bBlockIds) !== []) {
            return 'a nested entry was listed';
        }
    }

    return true;
});

// ---------------------------------------------------------------- swapping

section('Swapping A and B');

$swapped = plan((int)$a->id, (int)$b->id, $siteId, [
    'target' => Settings::TARGET_A,
    'choices' => [$body->handle => Strategy::A, $summary->handle => Strategy::B],
    'titleFrom' => MergePlan::TITLE_A,
])->swapped();

check('swapping exchanges the IDs', fn() => $swapped->aId === (int)$b->id && $swapped->bId === (int)$a->id);
check('swapping flips every A/B choice', fn() => $swapped->choices[$body->handle] === Strategy::B
    && $swapped->choices[$summary->handle] === Strategy::A);
check('swapping flips the target', fn() => $swapped->target === Settings::TARGET_B);
check('swapping flips the attribute sources', fn() => $swapped->titleFrom === MergePlan::TITLE_B);
check('swapping leaves “Both” alone', function() use ($a, $b, $siteId, $body) {
    $combine = plan((int)$a->id, (int)$b->id, $siteId, ['choices' => [$body->handle => Strategy::COMBINE]])->swapped();

    return $combine->choices[$body->handle] === Strategy::COMBINE;
});

// ---------------------------------------------------------------- history pruning

section('History');

check('the history finds merges by either side', function() use ($plugin, $a) {
    $rows = $plugin->history->forEntry((int)$a->id);

    return count($rows) >= 1;
});

check('pruning keeps the newest rows', function() use ($plugin) {
    $total = $plugin->history->total();

    if ($total < 2) {
        return true;
    }

    $newest = (new Query())->from(GlueTable::MERGES)->orderBy(['id' => SORT_DESC])->limit(1)->scalar();
    $plugin->history->prune($total);

    return (new Query())->from(GlueTable::MERGES)->where(['id' => $newest])->exists();
});

// ---------------------------------------------------------------- done

teardown($made);

echo "\n";
echo $failed === 0
    ? "All $passed checks passed.\n"
    : "$passed passed, $failed failed.\n";

exit($failed === 0 ? 0 : 1);
