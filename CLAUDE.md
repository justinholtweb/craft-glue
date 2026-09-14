# Glue — Craft CMS 5 Plugin

## Project Overview

Glue merges two Craft entries into one. An editor selects exactly two entries in an entry index,
picks a winner field by field (or "both", where both means something), and Glue writes the result
to a new entry or into one of the two sources. Pro then repoints everything that pointed at the
entry that was retired. Distributed as `justinholtweb/craft-glue`. **Paid, Lite/Pro** — Lite $59,
Pro $79.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step, no runtime dependencies. One table (`glue_merges`). The merge screen's JavaScript
  is plain ES5-compatible script in `src/web/assets/merge/dist/`, edited in place.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\glue`
- Package: `justinholtweb/craft-glue`
- Handle: `glue`

### The load-bearing idea: everything happens in serialized values

Craft's own way of copying a field between elements is `Field::copyValue()`, which is exactly
`serializeValue($from)` → `setFieldValue($to)`. Glue uses that round trip for every field and
every strategy, but calls the two halves itself rather than calling `copyValue()`, for two
reasons:

1. **`Matrix::copyValue()` is a deliberate no-op.** It defers to `afterElementPropagate()`, which
   only does anything when the target is a *duplicate* of the source. Called across two unrelated
   entries it silently copies nothing — and a merge that silently drops the Matrix field is the
   worst bug this plugin could have.
2. Combining needs both values in a form that can be taken apart, and serialized is the only such
   form every field type agrees on.

The round trip also gets nested entries right for free, and this is the safety property the whole
plugin rests on: `Matrix::normalizeValue()` looks incoming block IDs up **against the target's own
nested entries**. IDs belonging to another owner are not found, so they are built as new blocks
rather than stolen. That is what makes "A's blocks, then B's" safe — B keeps its blocks. There is
an integration check for exactly this, and it is the one that must never be allowed to go red.

### `null` does not mean empty

Passing `null` to `setFieldValue()` on a Matrix or relation field does **not** clear it. Both read
`null` as "nothing was posted" and fall back to what is in the database. Correct for a partial
save, catastrophic here — "Neither" would leave the target's own value in place. `Merger::
blankValueFor()` spells out the empty value per field kind: `[]` for anything list-shaped,
`null` for the scalars.

### The plan is the unit that travels

`models\MergePlan` is what the merge screen posts, what a preset stores, what the console command
builds, and what goes into the history row. One `Merger::apply()` serves all of them, so the CP
and the console cannot drift. The dry run is the same call with `$dryRun` — the screen's Result
column *is* the merge, less the save.

A handle **missing** from `$plan->choices` is not the same as one set to `blank`: missing means
"no opinion" and falls to the pre-selection rule. That difference is the only reason a preset can
be replayed against an entry type it was not written for.

### Rewiring is element saves, not an UPDATE

Since Craft 5.3 a relation field's value lives in the element's **content JSON**; `relations` is a
secondary index used for `relatedTo` and as a fallback when the content value is `null`
(`BaseRelationField::normalizeValue()`). An `UPDATE relations SET targetId = …` would fix the
index and leave every field value pointing at the trashed entry — `relatedTo` searches agreeing
with you while templates render nothing. Only a real element save writes both.

Two consequences, both found by the tests:

- **Rewire before retiring.** Once a source is trashed, relation queries stop returning it, so a
  rewire that ran afterwards would see nothing to do and leave the stale ID in the content column
  forever. `Merger::apply()` commits, then rewires, then retires.
- **Read through the trash anyway**, because the queued path necessarily runs later.
  `Rewirer::storedIds()` clones the query with `status(null)->drafts(null)->trashed(null)`.

Revisions are deliberately not rewired — a revision records what an entry *was*. Provisional
drafts are skipped: saving somebody's open editor would stamp their unfinished work as modified.

### Fields are matched by handle, checked by identity

Craft 5 lets a layout override a field's handle per instance (`CustomField::setField()` clones the
field and applies the override), so a handle is a label, not an identity. Glue lines the two sides
up by handle — that is what `getFieldValue()` takes — then compares the underlying field UID. Same
field: silent. Same class, different field: allowed with a warning. Different class: not offered.

Values are always serialized with the **source's own** field instance, never the target's:
instance settings such as a Matrix field's entry types belong to the layout the value was stored
under.

## Traps found while building this

- **`Matrix::copyValue()` is a no-op.** See above. The obvious implementation of this plugin is
  silently broken.
- **`null` is "unchanged", not "empty"**, for Matrix and every relation field.
- **Rewiring after trashing sees nothing**, because relation queries hide trashed targets.
- **`array_merge()` renumbers numeric string keys.** Matrix serializes to a map keyed by block ID.
  `array_merge($a, $b)` on that turns every block into a "new" block named 0, 1, 2 and loses the
  identity of the target's existing blocks on a merge-into-A. `$a + $b` preserves them.
  `combineRows()` two methods away *wants* the renumbering, because those are lists.
- **Craft's `.data` tables stripe even rows**, with a selector carrying the same weight as an
  unscoped `tr[…] td[…]`. The chosen-cell tint appeared on odd rows and lost on even ones, which
  looks exactly like the JavaScript failing on half the table. Scoped to `.glue-table` for
  specificity.
- **`FieldLayoutTab` must be told its layout before its elements.** `setElements()` reaches for
  `getLayout()`, so `new FieldLayoutTab(['name' => …, 'elements' => …])` throws.
- **Craft namespaces plugin settings HTML itself** — field names in `settings.twig` carry no
  `settings[…]` prefix, or every edit is silently discarded.
- **Yii assigns console options raw when the property's default is `null`.** Every option on
  `GlueController` is `?string` and cast by hand; the booleans have non-null defaults and are safe.
- **`yii\base\Event` has no `isValid`.** A cancellable event extends `craft\events\CancelableEvent`.
- **Rendering a chip for an entry whose *section* was deleted throws.** `Entry::getSection()`
  raises `InvalidConfigException` for a section ID that no longer resolves, and
  `Cp::elementChipHtml()` reaches it through `canView()`. That is the history screen's own worst
  case — the merge whose everything is gone — so `HistoryController::isRenderable()` guards it and
  the row falls back to the title copied in at merge time.
- **`cp.entries.edit.details` does not exist in Craft 5.** Entries are edited through the generic
  element editor now, and registering that hook silently does nothing — the panel just never
  appears, with no error anywhere. The sidebar panel uses `Element::EVENT_DEFINE_SIDEBAR_HTML`.
- **`referencingElementIds()` must exclude revisions at the query.** Every save of a referencing
  entry leaves a revision with its own relation rows, so an entry edited a dozen times contributes
  a dozen source IDs — inflating the count reported to the user, pushing small rewires over the
  queue threshold, and handing the job a list it can only discard.
- **Project config writes are buffered until the end of a request**, and a script that bootstraps
  Craft without calling `run()` never gets one. `tests/integration/edition.php` calls
  `saveModifiedConfigData()` and `writeYamlFiles()` itself, or the edition switch lives only in
  that process.
- **`$(printf '…\n\n')` strips trailing newlines.** Use `$'…\n\n'` when posting a separator.
- Craft has **no `plugin/switch-edition` console command**; `tests/integration/edition.php` is it.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container.

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-glue/tests/integration/checks.php      # 84 checks
ddev exec bash /var/www/craft-glue/tests/integration/cp-smoke.sh    # screens + write paths
ddev exec bash -c 'find /var/www/craft-glue/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

`checks.php` builds its own section, two entry types, a Matrix block type and eight fields, then
tears all of it down — idempotent, and it restores the plugin settings it touches. `cp-smoke.sh`
loads every screen as a logged-in admin and then exercises the POSTs, because a screen that
renders is not a screen that saves; it also checks the Lite boundary from the outside, which is
the only place an edition is really an edition.

`ddev exec php /var/www/craft-glue/tests/integration/edition.php pro|lite` switches editions.

`ddev exec php craft clear-caches/cp-resources` after editing anything under
`src/web/assets/*/dist`.

## Coding conventions

- `Craft::t('glue', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required`
- Every edition check goes through `Plugin::isPro()` and a named method on `models\Edition`
- Refuse and say why, rather than quietly doing something else — a merge that half-happened is
  worse than one that did not start
