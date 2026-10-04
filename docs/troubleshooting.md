---
title: Troubleshooting
slug: troubleshooting
order: 60
summary: When the action is missing, a merge is refused, or a reference survives.
---

### “Merge with Glue…” is not in the actions menu

- The user needs the **Merge entries** permission (**Settings → Users → User Groups**).
- It only appears on **entry** indexes. Glue merges entries and nothing else.

### The menu item is there but greyed out

It enables at exactly two selected entries. Not one, not three.

### “Entry *n* could not be loaded in this site”

The entry does not exist in the site you are working in. Switch sites in the entry index, or pick
a pair that share one. A merge writes content, so which site is a real question and not a display
one.

### “… is a nested entry inside a field”

You selected a Matrix block. Blocks have an owner, a field and a sort order, and a merge has no
way to reassign them. Merge the entries that own the blocks.

### “You are not allowed to save the merged entry”

Craft's permissions, not Glue's. The user needs to be able to save entries in the target section —
and, if the merge retires the sources, to delete them. Glue checks with Craft's own `canSave()`
and `canDelete()` before writing anything.

### The Result column says “Could not work out the result”

The preview request failed. Look in **Utilities → System Report → Logs**, or `storage/logs/`, for
a `glue` entry. The form still works — the Result column is a convenience, and the merge itself
does not depend on it.

### A field shows “Has a value” instead of a real preview

The field type threw while being described. Glue logs a warning and carries on rather than taking
the screen down, so you can still choose. The merge itself is unaffected: previews are only ever
for reading.

### The merged entry is missing a field that both sources had

Check the warnings at the top of the merge screen. The target entry type's field layout decides
what the result can hold, and a field that is not in it is listed as dropped before you press the
button.

If the field *is* in the layout but arrived empty, check whether the two entry types use the same
handle for two different fields — Glue warns about that too, and refuses to copy between different
kinds of field.

### “The … field is still sharing nested content with one of the source entries”

A field that holds nested elements re-parented them onto the merged entry instead of copying them.
Matrix cannot do this; a third-party field type can. **Do not trash the sources.** Open the merged
entry, check that field, and if the content is genuinely shared, rebuild it by hand before
retiring anything.

### Rewiring said it touched 0 elements

Nothing related to the entries being retired — which is the common case for entries nobody links
to. `php craft glue/rewire --from=<id> --to=<id>` reports the same count independently if you want
to check.

Relations created by reference tags in rich text are not counted, because they are not relations.

### Rewiring left some elements alone

The result says how many elements pointed at a retired entry but were not yours to edit. Rewiring
only saves what the merging user could have saved by hand, in sites they can edit. Ask somebody
with access to run the merge, or rewire the rest from the console, which has no such limit:
`php craft glue/rewire --from=<id> --to=<id>`.

### “You are not allowed to edit the … site”

**Merge in every site** writes to every site the two entries share, so it needs edit access to all
of them. Merge in the sites you can edit one at a time, or ask somebody with access to all of them.

### Saving a preset says admin changes are turned off

Presets are project config, and project config can only be changed where `allowAdminChanges` is
on. Save the preset in development, commit the YAML and deploy it. Saved presets can still be
*used* everywhere; the **Save these choices** panel is simply hidden where they cannot be saved.

### Rewiring is slow, or times out

Each rewire is a full element save. Lower **Queue rewiring above** in the settings so the work goes
to the queue, and make sure your queue is actually running — `php craft queue/listen` in
development, a daemon in production.

### Entries still point at the retired entry after rewiring

- **Revisions** are not rewired, on purpose: a revision records what an entry was. A `relations`
  row from a revision is expected and harmless.
- **Provisional drafts** are skipped, so somebody's open editor is not saved under your name. It
  resolves when they save.
- **Reference tags** in rich text are never rewritten. Glue reports how many it found.

### “The merge saved, but the original entries could not be retired”

The merge is committed and correct; only the disposition failed. Usually another plugin vetoed the
delete, or the entry is in a structure that refused the change. Disable or trash the source by
hand — nothing else is outstanding.

### Presets are refused with “a Glue Pro feature”

Presets are Pro. Check the edition at **Settings → Plugins → Glue**.

### A preset did nothing for half the fields

It was written against a different entry type. Handles it does not recognise are left to the
pre-selection rule rather than emptied — that is deliberate, and it is what makes a preset safe to
replay on a pair it was not written for. Save a preset for that entry type instead.

### Settings changes are ignored

`config/glue.php` overrides the control panel, and settings defined there are read-only in the CP.
Check for a `config/glue.php`, and for an environment-specific block in it.

### The History screen is empty after merging

**Record every merge** may be off, or `historyLimit` may be low enough that garbage collection has
pruned what you are looking for.

### Something else

Run the plugin's own checks against your site — they are non-destructive and clean up after
themselves:

```sh
php /path/to/vendor/justinholtweb/craft-glue/tests/integration/checks.php
```

Then open an issue at <https://github.com/justinholtweb/craft-glue/issues> with the output.
