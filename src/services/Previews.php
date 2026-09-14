<?php

declare(strict_types=1);

namespace justinholtweb\glue\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\Table;
use craft\helpers\StringHelper;
use justinholtweb\glue\models\Preview;
use justinholtweb\glue\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Turns a serialized field value into something a person can choose between.
 *
 * Deliberately works from the **serialized** value and nothing else, because the merged result
 * has no element to read a live value off — it does not exist yet. One input shape means the
 * "A", "B" and "Result" columns of the merge screen are rendered by one method, so the result
 * column cannot drift from the two columns it is supposed to be the answer to.
 *
 * The cost is that relation fields have to be re-queried from their IDs. That is one query per
 * relation field per merge screen, and it buys the thing that actually makes the screen usable:
 * chips the editor recognises instead of "3 entries".
 */
class Previews extends Component
{
    /** Longest excerpt shown for a text value. */
    public const EXCERPT = 280;

    /** Most elements listed as chips before the rest become "+ 4 more". */
    public const MAX_CHIPS = 8;

    public function of(FieldInterface $field, mixed $serialized, ?int $siteId = null): Preview
    {
        $preview = new Preview();
        $preview->digest = Plugin::getInstance()->strategies->digest($field, $serialized);

        if ($this->isEmpty($serialized)) {
            $preview->empty = true;
            $preview->summary = Craft::t('glue', 'Empty');
            return $preview;
        }

        $preview->empty = false;

        try {
            $this->describe($field, $serialized, $siteId, $preview);
        } catch (Throwable $e) {
            // A preview is a convenience. A field type that throws while being described must not
            // take down the screen that would let somebody work around it.
            Craft::warning(sprintf(
                'Could not preview the “%s” field: %s',
                $field->handle,
                $e->getMessage(),
            ), 'glue');

            $preview->summary = Craft::t('glue', 'Has a value');
        }

        return $preview;
    }

    private function describe(FieldInterface $field, mixed $value, ?int $siteId, Preview $preview): void
    {
        if ($field instanceof Matrix) {
            $this->describeBlocks($field, $value, $preview);
            return;
        }

        if ($field instanceof BaseRelationField) {
            $this->describeRelations($field, $value, $siteId, $preview);
            return;
        }

        if ($field instanceof Table) {
            $rows = is_array($value) ? count($value) : 0;
            $preview->summary = Craft::t('glue', '{n, plural, =1{1 row} other{# rows}}', ['n' => $rows]);
            return;
        }

        if ($field instanceof Lightswitch) {
            $preview->summary = $value ? Craft::t('glue', 'On') : Craft::t('glue', 'Off');
            // An off lightswitch is a value, not an absence — but it is also what an untouched
            // field looks like, so the screen treats it as empty for the "differences only"
            // filter while still saying "Off" here.
            $preview->empty = !$value;
            return;
        }

        if ($field instanceof BaseOptionsField) {
            $this->describeOptions($field, $value, $preview);
            return;
        }

        if (is_string($value)) {
            $this->describeText($value, $preview);
            return;
        }

        if (is_bool($value)) {
            $preview->summary = $value ? Craft::t('glue', 'Yes') : Craft::t('glue', 'No');
            return;
        }

        if (is_scalar($value)) {
            $preview->summary = (string)$value;
            return;
        }

        if (is_array($value)) {
            $preview->summary = array_is_list($value)
                ? Craft::t('glue', '{n, plural, =1{1 item} other{# items}}', ['n' => count($value)])
                : implode(', ', array_map(
                    fn($k, $v) => $k . ': ' . (is_scalar($v) ? (string)$v : '…'),
                    array_keys($value),
                    $value,
                ));
            $preview->summary = StringHelper::safeTruncate($preview->summary, 120);
            return;
        }

        $preview->summary = Craft::t('glue', 'Has a value');
    }

    private function describeBlocks(Matrix $field, mixed $value, Preview $preview): void
    {
        $blocks = is_array($value) ? ($value['entries'] ?? $value['blocks'] ?? $value) : [];

        if (isset($value['sortOrder']) && is_array($value['sortOrder'])) {
            // Honour the stated order rather than the map's key order, which is what the merged
            // result carries and what the editor will actually see once it saves.
            $ordered = [];

            foreach ($value['sortOrder'] as $id) {
                if (isset($blocks[$id])) {
                    $ordered[$id] = $blocks[$id];
                }
            }

            $blocks = $ordered ?: $blocks;
        }

        $labels = [];

        foreach ($field->getEntryTypes() as $entryType) {
            $labels[$entryType->handle] = Craft::t('site', $entryType->name);
        }

        $names = [];

        foreach ($blocks as $block) {
            $handle = is_array($block) ? ($block['type'] ?? null) : null;
            $names[] = $handle !== null ? ($labels[$handle] ?? $handle) : Craft::t('glue', 'Block');
        }

        $preview->summary = Craft::t('glue', '{n, plural, =1{1 block} other{# blocks}}', ['n' => count($names)]);
        $preview->empty = $names === [];

        if ($names !== []) {
            $shown = array_slice($names, 0, self::MAX_CHIPS);
            $preview->body = implode(' · ', $shown)
                . (count($names) > count($shown) ? ' · …' : '');
        }
    }

    private function describeRelations(BaseRelationField $field, mixed $value, ?int $siteId, Preview $preview): void
    {
        $ids = array_values(array_filter(
            is_array($value) ? $value : [$value],
            fn($id) => is_numeric($id),
        ));

        $preview->empty = $ids === [];
        $preview->summary = Craft::t('glue', '{n, plural, =1{1 item} other{# items}}', ['n' => count($ids)]);

        if ($ids === []) {
            return;
        }

        /** @var class-string<ElementInterface> $elementType */
        $elementType = $field::elementType();

        $elements = $elementType::find()
            ->id($ids)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->trashed(null)
            ->limit(self::MAX_CHIPS)
            ->indexBy('id')
            ->all();

        // Back into the order the field stores, which is content in its own right.
        $ordered = [];

        foreach ($ids as $id) {
            if (isset($elements[$id])) {
                $ordered[] = $elements[$id];
            }
        }

        $preview->elements = $ordered;

        if (count($ids) > count($ordered)) {
            $preview->body = Craft::t('glue', '+ {n} more', ['n' => count($ids) - count($ordered)]);
        }
    }

    private function describeOptions(BaseOptionsField $field, mixed $value, Preview $preview): void
    {
        $labels = [];

        foreach ($field->options as $option) {
            if (isset($option['value'])) {
                $labels[(string)$option['value']] = (string)($option['label'] ?? $option['value']);
            }
        }

        $values = is_array($value) ? $value : [$value];
        $names = array_map(fn($v) => $labels[(string)$v] ?? (string)$v, $values);

        $preview->summary = StringHelper::safeTruncate(implode(', ', $names), 120);
        $preview->empty = $names === [];
    }

    private function describeText(string $value, Preview $preview): void
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        if ($plain === '') {
            // Markup with no words in it — an empty paragraph, a lone image. Not nothing, but
            // nothing to quote either.
            $preview->summary = Craft::t('glue', '{n, plural, =1{1 character} other{# characters}} of markup', [
                'n' => mb_strlen($value),
            ]);
            return;
        }

        $preview->summary = Craft::t('glue', '{n, plural, =1{1 word} other{# words}}', [
            'n' => count(preg_split('/\s+/u', $plain) ?: []),
        ]);

        $preview->body = StringHelper::safeTruncate($plain, self::EXCERPT, '…');
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value) && isset($value['sortOrder'])) {
            return $value['sortOrder'] === [];
        }

        return false;
    }
}
