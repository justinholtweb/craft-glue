# Console commands

All of these are **Pro**, except `glue/inspect`, which writes nothing and is free — being able to
ask "what would merging these two do" without a licence is how you decide whether you want one.

## `glue/inspect`

Prints the field-by-field analysis without writing anything.

```sh
php craft glue/inspect --a=482 --b=917
```

```
A: Q3 Pricing Update (482)
B: Pricing update, Q3 (917)

body                         A      A: 412 words                 B: 96 words
topics                       A      A: 2 items                   B: 2 items
heroImage                    B      A: Empty                     B: 1 item
legacyId                     A      A: PR-2019-44                B: Empty

! These entries are different entry types — Press Release and Article. The merged entry will be
  Press Release.
```

Contested rows — where the two sides differ and both have something — are highlighted. The column
after the handle is the strategy that would be used.

Options: `--a`, `--b`, `--target`, `--entry-type`, `--site`.

## `glue/merge`

```sh
php craft glue/merge --a=482 --b=917
```

| Option | |
| --- | --- |
| `--a`, `--b` | The two entry IDs. Required. |
| `--target` | `new` (default), `a` or `b`. |
| `--section` | Section handle, for a new entry. |
| `--entry-type` | Entry type handle, for a new entry. |
| `--site` | Site handle. Defaults to the primary site. |
| `--preset` | A saved preset's handle. |
| `--disposition` | `leave`, `disable` or `trash`. |
| `--rewire` | Repoint relations that pointed at the retired sources. |
| `--all-sites` | Merge every site the two entries share. |
| `--dry-run` | Resolve the whole merge and print it. Write nothing. |
| `--force` | Skip the confirmation prompt. |

With no `--preset` and no per-field options, every field falls to the pre-selection rule from the
settings. That is the point: for scripted merges you set the rule once and let it apply, or you
write a preset in the control panel and replay it here.

```sh
# Replay choices made in the CP, across a list of pairs
while read A B; do
    php craft glue/merge --a="$A" --b="$B" --preset=press-release-dedupe --force
done < pairs.txt
```

Always try `--dry-run` first on a pair you know.

## `glue/duplicates`

```sh
php craft glue/duplicates --section=news
```

```
Pricing update, Q3 (2)
  482
  917
  → php craft glue/merge --a=482 --b=917
```

Entries sharing an exact title, or an exact slug with `--by=slug`. Nothing fuzzy: a false positive
here costs somebody an entry that should never have been merged, and the short list that is almost
always right beats the long list that needs checking.

Options: `--section`, `--site`, `--by` (`title` or `slug`), `--limit`.

Nested entries — Matrix blocks — are excluded. They are not duplicates of anything a person wants
to merge, and on a large site they outnumber real entries several times over.

## `glue/rewire`

Repoints every relation that targets one entry at another, without merging anything.

```sh
php craft glue/rewire --from=482 --to=917
```

Useful on its own: an entry that was merged by hand months ago, or one you are about to delete for
unrelated reasons. It reports how many elements it touched and warns about any reference tags it
found, which it does not rewrite.

Options: `--from`, `--to`, `--force`.

## Exit codes

`0` on success. `69` (unavailable) when the command needs Pro. `65` (usage) for bad arguments.
`1` when the merge itself failed — the reason is on stderr.
