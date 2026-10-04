---
title: Using Glue
slug: usage
order: 20
summary: The merge screen, choosing a target, and what happens to the originals.
---

## Starting a merge

Select **exactly two entries** in any entry index and choose **Merge with Glue…** from the actions
menu. The menu item stays disabled until there are two — not one, not three.

Glue merges two entries at a time on purpose. Three-way merges have no obvious order and no
obvious tie-breaks, and the answer to "I have four duplicates" is to merge a pair and then pair
the result with the next one, which is exactly what the Duplicates screen suggests.

You can also link straight to the screen:

```twig
{{ craft.glue.mergeUrl(482, 917) }}
```

## Reading the screen

The two entries sit at the top as **A** and **B**. Which is which is an accident of the order they
were selected in, and it decides the pre-selection, the tie-breaks and which way round "Both"
concatenates — so there is a **Swap** button between them.

Below that, a row per field of the target entry type:

| | |
| --- | --- |
| **Field** | Its label, and its handle underneath |
| **A** | What the first entry holds |
| **B** | What the second entry holds |
| **Take** | A · B · Both · Neither |
| **Result** | What the merged entry will hold |

Clicking anywhere in the **A** or **B** cell picks that side — the values are big and the radios
are small, and the value is what you are actually looking at when you decide.

The **Result** column is not a description of what will happen. It is the merge, resolved by the
same code that will run, and simply not saved.

### Getting through a long entry type

- **All A**, **All B**, **All both** set every row that will take them, leaving the rest alone.
- **Reset** puts every row back to what Glue suggested.
- **Only the *n* that differ** hides every row where the two sides are the same or both empty —
  usually most of them.

## Where the merged entry goes

**A new entry.** Both sources survive untouched. Pick the section and entry type; the entry type
is what decides which fields exist at all, so changing it rebuilds the screen.

**Into the first or second entry.** That entry keeps its ID, its URL, its revision history and
every inbound link — nothing that points at it breaks, because it never went anywhere. The other
entry is the one that gets retired. Merging into a source never changes its entry type; that
would be a content-model edit wearing a merge's clothes.

## Title, slug and the rest

These are not fields and do not behave like them, so they have their own section:

| | Options |
| --- | --- |
| **Title** | A's · B's · something else |
| **Slug** | A's · B's · generated from the title · something else |
| **Author** | A's · B's · you |
| **Post date** | the earlier of the two · A's · B's · now |
| **Status** | A's · B's · enabled · disabled |
| **Position** | under A's parent · under B's parent · top level *(structures only)* |

Post date defaults to **the earlier of the two**: two records of the same thing were first
published when the earlier of them was, and taking the later one silently reorders the archive.

Changing the slug of an entry that already has one changes its URL. Glue says so on the screen
when you are merging into a source.

## What happens to the originals

- **Leave them alone** — the default, and the only one that cannot lose anything.
- **Disable them** — they stop being published but stay exactly where they are.
- **Move them to the trash** — recoverable until the trash is emptied.

Whichever you choose, the entry the merge *wrote to* is never retired, even if a hand-built plan
says so.

## Warnings

Glue says these before you press the button, not after:

- The two entries are **different entry types**, and which one the result will be.
- The two entries are in **different sections**.
- A source has **fields the merged entry cannot hold** — it names them.
- Two entry types use **one handle for two different fields**. If they are the same kind of field
  the value can still be copied and Glue says to check it; if they are different kinds the row is
  not offered at all.
- A field **holds nested elements** and is still sharing them with a source after the merge. Glue
  checks this after every merge; Matrix is safe by construction, and this is here for field types
  it has never met.

## Merging every site at once — Pro

On a multi-site install, **Merge in every site** repeats the merge in every site the two entries
share. Each site is resolved from its own values rather than propagated from the first — a
translatable field merged in French uses the French values, which is the difference between a
merge and an overwrite with English.

If the two entries have no second site in common, Glue says so and merges the one. If you cannot
edit one of the shared sites, Glue refuses the whole merge rather than skipping that site.

## Presets — Pro

**Save these choices** at the bottom of the screen stores the whole plan — the target, every field
choice, the attribute sources and the disposition — under a name. Saved presets appear as buttons
at the top of the screen for the next pair.

Presets live in project config, so they are reviewed in a pull request and deploy with the entry
types they describe. That also means they are saved where admin changes are allowed — usually
development — and the panel for saving them does not appear anywhere else.

A preset replayed against an entry type it was not written for applies what it recognises and
leaves the rest to the pre-selection rule. It does not empty fields it has never heard of.

## The history

**Glue → History** lists every merge: what went in, what came out, who did it, what they chose,
and any warnings. The titles are copied into each row when the merge runs, so the list still reads
after the entries are in the trash — which is the case it exists for.

Entries that were part of a merge also say so on their own edit screen.
