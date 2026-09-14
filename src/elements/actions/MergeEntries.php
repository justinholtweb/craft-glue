<?php

declare(strict_types=1);

namespace justinholtweb\glue\elements\actions;

use Craft;
use craft\base\ElementAction;

/**
 * “Merge with Glue…” in the entry index's action menu.
 *
 * ## Why this action never reaches the server
 *
 * Craft's element actions are built to *do* something to a selection and report back. A merge
 * cannot work that way — it needs a screen, because the whole job is choosing between two values
 * forty times. So the trigger supplies an `activate` handler, which makes Craft's element index
 * treat the click as custom and skip its own POST entirely, and the handler navigates to the
 * merge screen with the two IDs on the query string.
 *
 * The consequence worth knowing: `performAction()` is never called, and the action's server side
 * is only ever asked for its label. That is why there is no `performAction()` here at all rather
 * than a stub — a stub would be a merge path with no permission checks in it, sitting one forged
 * POST away from being reachable.
 */
class MergeEntries extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('glue', 'Merge with Glue');
    }

    public function getTriggerLabel(): string
    {
        // The ellipsis is doing real work: it is the difference between a menu item that acts and
        // one that opens a screen, and it is the only warning an editor gets before the page
        // changes under them.
        return Craft::t('glue', 'Merge with Glue…');
    }

    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(
            fn($type, $mergeUrl, $wrongCount) => <<<JS
(() => {
  new Craft.ElementActionTrigger({
    type: $type,
    bulk: true,
    requireId: true,
    validateSelection: (selectedItems) => selectedItems.length === 2,
    activate: (selectedItems, elementIndex) => {
      const ids = elementIndex.getSelectedElementIds();

      if (ids.length !== 2) {
        Craft.cp.displayError($wrongCount);
        return;
      }

      const params = {a: ids[0], b: ids[1]};

      if (elementIndex.siteId) {
        params.site = elementIndex.siteId;
      }

      window.location.href = Craft.getCpUrl($mergeUrl, params);
    },
  });
})();
JS,
            [
                static::class,
                'glue/merge',
                Craft::t('glue', 'Select exactly two entries to merge.'),
            ],
        );

        return null;
    }
}
