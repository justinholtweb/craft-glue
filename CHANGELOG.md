# Release Notes for Glue

## 5.0.0 — 2026-09-14

Initial release.

Glue merges two Craft entries into one: pick a winner field by field, take both where taking both
means something, and write the result to a new entry or into one of the two you started with.

### Added

- **Merge with Glue…** in the entry index actions menu, for exactly two selected entries.
- A merge screen with one row per field of the target entry type, the two values side by side, and
  a live **Result** column that is the merge itself rather than a model of it.
- Pre-selection from whichever entry has a value, configurable to always-A, always-B or
  always-both.
- Three targets: a new entry, or into either source — keeping its ID, URL, revision history and
  inbound links.
- Combine strategies per field kind: union for relations and option fields, appended blocks for
  Matrix, appended rows for tables, a separator join for plain and rich text. Unknown field types
  are classified by what they serialize to.
- Compatibility warnings before anything is written: different entry types, different sections,
  fields the target cannot hold, and two layouts using one handle for two different fields.
- Title, slug, author, post date, status and structure position chosen independently of the
  fields.
- Disposition of the originals: leave, disable, or move to the trash.
- A merge history, with the titles copied into each row so it still reads after the entries are
  gone, plus a note on the edit screen of any entry that was part of a merge.
- Three permissions, on top of Craft's own.
- `EVENT_BEFORE_MERGE` (cancellable) and `EVENT_AFTER_MERGE`.

### Added — Pro

- Repointing every relation that targeted a retired entry at the merged one, through real element
  saves so the content column and the `relations` table agree, queued above a threshold.
- A count of reference tags naming a retired entry, which Glue does not rewrite and says so.
- Merge presets, stored in project config.
- Merging every site the two entries share, resolving each site's own values rather than
  propagating one site's.
- `glue/merge`, `glue/duplicates` and `glue/rewire` console commands, plus `glue/inspect`, which
  is free.
- A duplicate finder for entries sharing a title or a slug.
