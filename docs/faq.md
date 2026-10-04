---
title: FAQ
slug: faq
order: 70
summary: Short answers to the questions that come up before buying.
---

### Can I merge more than two entries at once?

No, and not by oversight. Three-way merges have no obvious order and no obvious tie-breaks, and
"Both" stops meaning anything specific. Merge a pair, then pair the result with the next one — the
Duplicates screen lists groups and offers the first pair for exactly this.

### Can I undo a merge?

Not in one step. If you trashed the sources they are in the trash until it is emptied, and the
merged entry has ordinary revisions you can restore from. The History screen records exactly what
was chosen, which is what you need to put things back by hand.

If you are unsure, merge to **a new entry** and leave the originals alone. Nothing is lost, and you
can delete the result if it is wrong.

### Does merging into an entry break its URL?

No — that is the point of merging into a source. It keeps its ID, its URL, its revision history
and every inbound link. The only way to change its URL is to choose a different slug, and Glue
tells you when you are doing that.

### What happens to entries that linked to the one I retired?

On **Lite**, nothing: their relation to the retired entry stays, and since the entry is disabled
or trashed it will resolve to nothing. You would fix those by hand.

On **Pro**, switch on **Repoint everything that linked to them** and Glue re-saves each one so it
relates to the merged entry instead.

### Will rewiring flood my revision history?

It creates one revision per element it actually changes, per site where the value actually
changed. Elements that did not change are not saved at all. There is no way to avoid this and be
correct: the entry genuinely changed, and a content change with no revision is a worse problem
than a revision you did not ask for.

### What about `{entry:482:url}` in my rich text?

Reference tags are not relations — they create no row in the `relations` table and cannot be found
by looking there. Glue does not rewrite them, but when rewiring it searches the content for them
and tells you how many it found and what string to search for. Rewriting them is a content
search-and-replace, which is a different tool's job.

### Why is "Both" greyed out on some fields?

Because there is no sensible "both" for that field. A date, a number, a switch, a dropdown — two
values do not combine into one. Hover the option and Glue says what "Both" would do, or why it
cannot.

It is also greyed out for fields that hold nested elements but are not Matrix — Addresses, Content
Blocks, rich text with embedded entries. Those can be taken from A or from B, but appending them
would risk moving nested content off the entry it belongs to instead of copying it.

### The two entries are different entry types. Will that work?

Yes. Pick the entry type the result should be; its field layout decides which fields exist. Glue
warns you about the fields on either source that the result cannot hold, and names them, so
"different entry types" is a decision you make with the list in front of you.

Merging *into* an existing entry always keeps that entry's type.

### Two of my entry types use the same field handle for different fields. What happens?

Glue matches the two sides by handle — that is what an editor sees and what Craft's own API takes
— and then checks what is underneath. Same field: nothing to say. Same *kind* of field, different
field: the value can still be copied, and Glue warns you to check the result. Two different kinds:
the row is not offered at all, because there is no sense in which a Matrix value can become a
date.

### Does it work with my third-party field type?

Almost certainly for A/B, because Glue uses Craft's own copy path and any field that implements
`serializeValue()` and `normalizeValue()` correctly will work.

For "Both", Glue classifies unknown fields by what they serialize to: a list of IDs gets a union,
a list of rows gets appended, a long-text field gets joined. A field with an unusual serialization
simply gets no "Both" — a missing option rather than a wrong answer.

### Can I merge in every language at once?

On Pro, yes. Each site is resolved from its own values rather than propagated from the first, so a
translatable field merged in French uses the French values. Lite merges the site you are working
in.

### Will it merge Matrix blocks?

It merges *entries*, and it copies their Matrix blocks. It will not merge two Matrix blocks with
each other — a nested entry has an owner, a field and a sort order, none of which a merge knows
how to reassign.

### Does B lose its Matrix blocks when I take "Both"?

No. Copying is genuinely copying: the merged entry gets new blocks with the same content, and B
keeps the ones it had, with the same IDs. Glue verifies this after every merge and warns if any
field is still sharing nested content with a source.

### Does Glue send anything anywhere?

No. It makes no outbound requests of any kind.

### What does it add to my database?

One table, `glue_merges`, holding the history. Uninstalling drops it. Entries that Glue merged are
ordinary entries and are unaffected by uninstalling.

### Why is rewiring off by default, even on Pro?

Because it edits other people's entries. A setting that quietly does that on somebody's first
merge is a setting that gets the plugin uninstalled. Turn it on once you have watched it work.
