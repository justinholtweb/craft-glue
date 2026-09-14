# How merging works

This page is for the person who wants to know what Glue is doing to their database before they let
it. Nothing here is needed to use the plugin.

## Values are copied the way Craft copies them

Craft's own method for copying a field from one element to another is `Field::copyValue()`, and it
is exactly two lines:

```php
$value = $this->serializeValue($from->getFieldValue($this->handle), $from);
$to->setFieldValue($this->handle, $value);
```

Glue does that round trip for every field and every strategy. It calls the two halves itself
rather than calling `copyValue()`, because **`Matrix::copyValue()` is a deliberate no-op** — it
defers to a different mechanism that only works when the target is a duplicate of the source, and
across two unrelated entries it copies nothing at all.

Working in serialized values has a second benefit: it is the only form every field type agrees on,
so combining two values means combining two arrays or two strings rather than knowing what a
particular field's object model looks like.

## Nested entries are copied, never stolen

This is the property everything else rests on.

When Glue hands a serialized Matrix value to the merged entry, `Matrix::normalizeValue()` looks
each incoming block ID up **against the target's own nested entries**. IDs belonging to some other
owner are not found, so they are built as new blocks. The entry they came from keeps its own.

The same mechanism gets merging *into* a source right without a special case: the target's own
block IDs are recognised and those blocks are reused, keeping their identities and their own
revision trail, while the other entry's blocks arrive as copies.

Glue checks this after every merge anyway. Any field holding nested elements is inspected, and if
a block on the merged entry still belongs to a source, you get a warning telling you not to delete
the sources until you have looked. Matrix is safe by construction; the check is for field types
Glue has never met.

## "Neither" has to be spelled out

Handing `null` to a Matrix or relation field does **not** empty it. Both read `null` as "nothing
was posted for this field" and fall back to what is already stored — which is correct for a
partial save and would be catastrophic here, because "Neither" would quietly leave the target's
own value in place.

So Glue knows what "empty" looks like for each kind of field: an empty array for anything
list-shaped (Matrix, relations, tables, multi-option fields), `null` for the scalars. A field type
Glue does not recognise is given an empty array if either side serialized to one.

## How "Both" is decided

Known core classes first, then the shape of the serialized values:

1. Matrix → append blocks.
2. Any other field holding nested elements → **not combinable**. Addresses, Content Blocks and
   rich text with embedded entries all hold nested elements, and none of them has Matrix's input
   shape, so there is no way to say "A's then B's" that is guaranteed to copy rather than re-own.
   Refused rather than guessed.
3. Table → append rows.
4. Relation field → union.
5. Multi-option field → union.
6. A field whose storage type is a long text column → join with a separator. The test is the
   storage type and not the class, so it answers correctly for rich text fields Glue has never
   heard of, and says no to emails, URLs, numbers and dates — all of which are strings or
   scalars for which joining two is nonsense.
7. Otherwise, by shape: a list of scalars unions, a list of arrays appends, a string joins, and
   anything keyed — a Money field's value and currency, a Link field's parts — is refused, because
   appending two of those produces a value the field cannot read back.

The union keeps A's order and appends only what B adds, comparing `12` and `"12"` as the same
relation. Rows are appended and, by default, a row identical to one already above it is dropped.

## What "identical" means

The merge screen can hide rows where the two sides agree, which is what makes a forty-field entry
type workable. "Agree" is a digest of the serialized value, with two deliberate normalisations:

- Integers and numeric strings are the same, because an element ID arrives as one or the other
  depending on which side of a query it came through.
- Matrix values are digested **without** their block IDs. Two entries with the same three blocks
  holding the same content are the same content; comparing IDs would report every Matrix field on
  every pair as contested, which is exactly the noise the comparison exists to remove.

## Rewiring is element saves

Since Craft 5.3 a relation field's value lives in the **element's content column**. The
`relations` table is a secondary index, used for `relatedTo` queries and as a fallback for
elements that have not been re-saved since the upgrade.

So the tempting one-line fix —

```sql
UPDATE relations SET targetId = :survivor WHERE targetId IN (:a, :b)
```

— is wrong in the worst way. It repairs the index and leaves every actual field value pointing at
the entry you just trashed, so `relatedTo` searches agree with you while the templates render
nothing.

Glue loads each referencing element, swaps the IDs in its relation fields, and saves it. Orders of
magnitude slower, and correct. Consequences worth knowing:

- Each rewire produces a revision and bumps `dateUpdated`, because the entry did change.
- Elements are handled per site, because a translatable relation field's French value is not
  touched by saving the English one. Sites where nothing changed are not saved at all.
- **Rewiring happens before the sources are retired.** Once a source is in the trash, Craft's
  relation queries stop returning it, so a rewire that ran afterwards would look at each
  referencing entry, see a field that no longer mentions the retired one, and conclude there was
  nothing to do — leaving the stale ID in the content column forever.
- **Revisions are not rewired.** A revision is a record of what an entry was; rewriting one would
  be falsifying it. A relation row pointing at a retired entry legitimately survives in the
  revision history.
- **Provisional drafts are skipped.** Saving somebody's open editor would stamp their unfinished
  work as modified under your name. A provisional draft is merged into its canonical entry when
  they save, and the canonical entry has been rewired.
- **Reference tags are not relations.** `{entry:482:url}` in a rich text field creates no
  `relations` row and cannot be found this way. Glue counts them with a text search and tells you
  what to look for.

## Transactions

The merged entry, the other-site passes and the nested-ownership check run in one transaction. If
any of them fails, none of it happened.

Rewiring runs **outside** that transaction, because it can touch hundreds of unrelated entries and
holding a write transaction open across that many element saves is how a merge becomes a lock-wait
timeout on somebody else's publish. Retiring the sources then runs in its own short transaction;
if it fails, the merge still stands and you are told the originals could not be retired.

The queued rewire job is deliberately not transactional either. A run that fails on element 400 of
900 has correctly fixed 399, and rolling those back would leave 900 broken relations instead of
501.

## What Glue does not do

- **Merge more than two entries.** Three-way merges have no obvious order and no obvious
  tie-breaks. Merge a pair, then pair the result with the next one.
- **Merge Matrix blocks.** A nested entry has an owner, a field and a sort order, none of which a
  merge knows how to reassign.
- **Merge anything but entries.** Categories, assets, users and third-party elements are not
  offered.
- **Rewrite reference tags, or any other mention of an entry inside text.** That is a content
  search-and-replace.
- **Undo.** The sources are recoverable from the trash and the merged entry has ordinary
  revisions, but there is no single button that puts the two back the way they were.
