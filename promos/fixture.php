<?php
/**
 * Demo content for the promo screenshots, built in the plugin-testing harness.
 *
 * A press-release section where somebody has written up the same library reopening twice, plus a
 * handful of same-titled pairs for the duplicate finder and three events that link to the copy
 * that will be retired. Idempotent; prints the IDs as JSON. `--clean` removes all of it.
 *
 *     cd ~/Sites/plugin-testing
 *     ddev exec php /var/www/craft-glue/promos/fixture.php
 *     ddev exec php /var/www/craft-glue/promos/fixture.php --clean
 *
 * Every handle starts with `release` or `promo` so the clean-up can never reach anything else.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Date;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;

$fields = Craft::$app->getFields();
$entries = Craft::$app->getEntries();

const SECTIONS = ['promoReleases', 'promoTopics', 'promoEvents'];
const TYPES = ['promoRelease', 'promoTopic', 'promoEvent', 'promoTextBlock'];
const FIELDS = ['releaseSummary', 'releaseBody', 'releaseTopics', 'releaseDate', 'releaseFeatured', 'releaseBlocks', 'releaseBlockText', 'releaseRelated'];

if (in_array('--clean', $argv, true)) {
    foreach (SECTIONS as $handle) {
        if ($section = $entries->getSectionByHandle($handle)) {
            $entries->deleteSection($section);
        }
    }
    foreach ($entries->getAllEntryTypes() as $type) {
        if (in_array($type->handle, TYPES, true)) {
            $entries->deleteEntryType($type);
        }
    }
    foreach (FIELDS as $handle) {
        if ($field = $fields->getFieldByHandle($handle)) {
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
    if ($existing = Craft::$app->getFields()->getFieldByHandle($field->handle)) {
        return $existing;
    }
    if (!Craft::$app->getFields()->saveField($field)) {
        throw new RuntimeException('Could not save ' . $field->handle . ': ' . json_encode($field->getErrors()));
    }

    return $field;
}

function entryType(string $name, string $handle, array $elements, bool $hasTitle = true): EntryType
{
    foreach (Craft::$app->getEntries()->getAllEntryTypes() as $candidate) {
        if ($candidate->handle === $handle) {
            return $candidate;
        }
    }
    $type = new EntryType(['name' => $name, 'handle' => $handle, 'hasTitleField' => $hasTitle]);
    $type->setFieldLayout(layout($elements));
    if (!Craft::$app->getEntries()->saveEntryType($type)) {
        throw new RuntimeException("Could not save $handle: " . json_encode($type->getErrors()));
    }

    return $type;
}

function section(string $name, string $handle, EntryType $type): Section
{
    if ($section = Craft::$app->getEntries()->getSectionByHandle($handle)) {
        return $section;
    }
    $section = new Section(['name' => $name, 'handle' => $handle, 'type' => Section::TYPE_CHANNEL]);
    $section->setSiteSettings([
        new Section_SiteSettings([
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'hasUrls' => true,
            'uriFormat' => strtolower($name) . '/{slug}',
            'template' => '_entry',
            'enabledByDefault' => true,
        ]),
    ]);
    $section->setEntryTypes([$type]);
    if (!Craft::$app->getEntries()->saveSection($section)) {
        throw new RuntimeException("Could not save $handle: " . json_encode($section->getErrors()));
    }

    return $section;
}

function entry(Section $section, EntryType $type, string $title, array $values = [], ?string $postDate = null, int $nth = 1): Entry
{
    $existing = Entry::find()->sectionId($section->id)->title($title)->status(null)->orderBy('elements.id')->all();
    if (count($existing) >= $nth) {
        return $existing[$nth - 1];
    }
    $entry = new Entry(['sectionId' => $section->id, 'typeId' => $type->id, 'title' => $title]);
    $entry->authorId = 1;
    if ($postDate) {
        $entry->postDate = new DateTime($postDate);
    }
    foreach ($values as $handle => $value) {
        $entry->setFieldValue($handle, $value);
    }
    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException("Could not save “$title”: " . json_encode($entry->getErrors()));
    }

    return $entry;
}

// ------------------------------------------------------------------ fields

$summary = field(new PlainText(['name' => 'Summary', 'handle' => 'releaseSummary', 'multiline' => true, 'initialRows' => 2]));

$bodyClass = class_exists(\craft\ckeditor\Field::class) ? \craft\ckeditor\Field::class : null;
$body = $bodyClass
    ? field(new $bodyClass(['name' => 'Body', 'handle' => 'releaseBody']))
    : field(new PlainText(['name' => 'Body', 'handle' => 'releaseBody', 'multiline' => true]));

$topicType = entryType('Topic', 'promoTopic', [new EntryTitleField()]);
$topicSection = section('Topics', 'promoTopics', $topicType);

$topics = field(new EntriesField([
    'name' => 'Topics',
    'handle' => 'releaseTopics',
    'sources' => ['section:' . $topicSection->uid],
]));
$date = field(new Date(['name' => 'Event date', 'handle' => 'releaseDate', 'showDate' => true, 'showTime' => false]));
$featured = field(new Lightswitch(['name' => 'Featured', 'handle' => 'releaseFeatured']));
$blockText = field(new PlainText(['name' => 'Text', 'handle' => 'releaseBlockText', 'multiline' => true]));

$blockType = entryType('Text block', 'promoTextBlock', [new CustomField($blockText)], false);

$blocks = $fields->getFieldByHandle('releaseBlocks');
if ($blocks === null) {
    $blocks = new Matrix(['name' => 'Content blocks', 'handle' => 'releaseBlocks']);
    $blocks->setEntryTypes([$blockType]);
    field($blocks);
}

$releaseType = entryType('Press release', 'promoRelease', [
    new EntryTitleField(),
    new CustomField($summary),
    new CustomField($body),
    new CustomField($topics),
    new CustomField($date),
    new CustomField($featured),
    new CustomField($blocks),
]);
$releases = section('News', 'promoReleases', $releaseType);

// ------------------------------------------------------------------ the pair

$t = [];
foreach (['Libraries', 'Community', 'Events', 'Accessibility', 'Capital projects'] as $name) {
    $t[$name] = entry($topicSection, $topicType, $name);
}

function html(string ...$paragraphs): string
{
    return implode('', array_map(fn($p) => "<p>$p</p>", $paragraphs));
}

function blocksValue(string $type, array $texts): array
{
    $value = ['sortOrder' => [], 'entries' => []];
    foreach ($texts as $i => $text) {
        $key = 'new' . ($i + 1);
        $value['sortOrder'][] = $key;
        $value['entries'][$key] = ['type' => $type, 'enabled' => true, 'fields' => ['releaseBlockText' => $text]];
    }

    return $value;
}

$a = entry($releases, $releaseType, 'Riverside Library reopens after two-year renovation', [
    'releaseSummary' => 'The branch reopens on Saturday 14 March with a new children’s wing, a maker space and step-free access throughout.',
    'releaseBody' => html(
        'Riverside Library will reopen to the public on Saturday 14 March, two years after closing for the largest renovation in its ninety-year history.',
        'The work adds a children’s wing on the ground floor, a maker space with 3D printers and a sewing studio, and a lift serving every floor for the first time.',
        'The reading room has been restored to its 1934 layout, and the reference collection returns from storage in Eastgate.',
        '“This building has been here for every one of us,” said branch manager Dana Okafor. “Now it can be here for everyone.”',
    ),
    'releaseTopics' => [$t['Libraries']->id, $t['Capital projects']->id],
    'releaseDate' => new DateTime('2026-03-14'),
    'releaseFeatured' => true,
    'releaseBlocks' => blocksValue('promoTextBlock', [
        'Opening hours: Monday to Saturday, 9am–8pm. Sunday, 11am–5pm.',
        'Parking: the Mill Street car park is free for library users for the first two hours.',
    ]),
], '2026-02-20 09:00');

$b = entry($releases, $releaseType, 'Riverside Library Reopening', [
    'releaseSummary' => '',
    'releaseBody' => html(
        'Join us on 14 March for a family open day: storytelling at 10am, a maker-space taster at 1pm and a ribbon cutting at 3pm.',
        'British Sign Language interpretation is available for all three sessions.',
    ),
    'releaseTopics' => [$t['Community']->id, $t['Events']->id, $t['Accessibility']->id],
    'releaseDate' => new DateTime('2026-03-14'),
    'releaseFeatured' => false,
    'releaseBlocks' => blocksValue('promoTextBlock', [
        'Photo credits: Sam Whitley for Riverside Council.',
    ]),
], '2026-02-23 14:30');

// ------------------------------------------------------------------ things that link to B

$related = field(new EntriesField([
    'name' => 'Related release',
    'handle' => 'releaseRelated',
    'sources' => ['section:' . $releases->uid],
]));
$eventType = entryType('Event', 'promoEvent', [new EntryTitleField(), new CustomField($related)]);
$events = section('Events', 'promoEvents', $eventType);
foreach (['Storytelling for under-fives', 'Maker space taster', 'Ribbon cutting'] as $name) {
    entry($events, $eventType, $name, ['releaseRelated' => [$b->id]]);
}

// ------------------------------------------------------------------ duplicates

$dupes = [
    ['Summer Reading Challenge 2026', 'Kids can sign up from 1 June at any branch.'],
    ['Holiday closures', 'All branches close on public holidays.'],
    ['Board meeting minutes — February', 'Minutes of the February meeting of the library board.'],
];
foreach ($dupes as [$title, $text]) {
    entry($releases, $releaseType, $title, ['releaseSummary' => $text], '2026-01-12 10:00', 1);
    entry($releases, $releaseType, $title, ['releaseSummary' => $text . ' Updated.'], '2026-01-19 10:00', 2);
}
entry($releases, $releaseType, 'Holiday closures', ['releaseSummary' => 'Third copy, from the 2025 import.'], '2025-12-01 10:00', 3);

// ------------------------------------------------------------------ pairs to merge for the history screen

$history = [];
foreach ([
    ['Volunteer week: thank you', 'Volunteer Week — Thank You'],
    ['New e-book lending app', 'Libby now available at all branches'],
    ['Author talk: Maya Lin Torres', 'An evening with Maya Lin Torres'],
] as [$x, $y]) {
    $history[] = [
        entry($releases, $releaseType, $x, ['releaseSummary' => "$x."], '2026-01-05 10:00')->id,
        entry($releases, $releaseType, $y, ['releaseSummary' => "$y."], '2026-01-06 10:00')->id,
    ];
}

echo json_encode([
    'a' => $a->id,
    'b' => $b->id,
    'section' => $releases->id,
    'type' => $releaseType->id,
    'history' => $history,
    'body' => $body::class,
]) . "\n";
