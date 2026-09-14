# Configuration

Everything is at **Settings → Plugins → Glue**, and can be overridden in `config/glue.php`.

## What the merge screen opens with

| Setting | Default | What it does |
| --- | --- | --- |
| `defaultTarget` | `new` | Where a merge writes: `new`, `a` or `b`. `new` is the default because it is the only one that cannot lose anything. |
| `defaultStrategy` | `nonEmpty` | How each field's choice is pre-selected: `nonEmpty`, `a`, `b` or `combine`. |
| `defaultDisposition` | `leave` | What happens to the originals: `leave`, `disable` or `trash`. |

`nonEmpty` takes A unless A is empty and B is not. It is the default because the commonest real
merge by a distance is "these two are the same entry, one of them was filled in further", and
pre-selecting that turns a forty-field entry type into four decisions instead of forty.

`combine` pre-selects "Both" wherever the field allows it and both sides have something, and falls
back to `nonEmpty` everywhere else.

## What "Both" does

| Setting | Default | What it does |
| --- | --- | --- |
| `textSeparator` | `"\n\n"` | Between two plain-text values joined with "Both". |
| `richTextSeparator` | `"\n"` | Between two rich-text values. |
| `dedupeRows` | `true` | Drop a combined table row identical to one already above it. |

Rich text gets a single newline rather than a blank line because it already carries its own block
spacing: `</p>\n\n<p>` renders exactly like `</p>\n<p>`, so the extra newline is visible only in
the diff.

An empty separator is allowed and means straight concatenation. It is almost never what you want
for prose — check the Result column before you rely on it.

## Rewiring — Pro

| Setting | Default | What it does |
| --- | --- | --- |
| `rewireRelations` | `false` | Whether the rewire switch starts on. |
| `rewireThreshold` | `25` | Above this many referencing elements, rewiring goes to the queue. `0` never queues. |

Off by default **even on Pro**. Rewiring edits other people's entries, and a setting that quietly
does that on somebody's first merge is a setting that gets the plugin uninstalled. Turn it on once
you have watched it work.

The threshold exists because each rewire is a full element save — content, relations, search
index, revision. A few dozen is a slow response; a few thousand is a timed-out one.

## Multi-site — Pro

| Setting | Default | What it does |
| --- | --- | --- |
| `allSites` | `false` | Whether "merge in every site" starts on. |

## History

| Setting | Default | What it does |
| --- | --- | --- |
| `logMerges` | `true` | Record every merge in `glue_merges`. |
| `historyLimit` | `500` | Merges to keep. Older rows are pruned during garbage collection. `0` keeps everything. |

Pruning happens in garbage collection rather than on write, so an afternoon of merging does not
pay for a `DELETE` per merge. Being a hundred rows over the limit between two nightly runs costs
nothing.

## Presets — Pro

`presets` is a map of saved plans, keyed by handle. It is written by the **Save as preset** button
on the merge screen; there is rarely a reason to write one by hand, but it is readable:

```php
'presets' => [
    'press-release-dedupe' => [
        'name' => 'Press release dedupe',
        'entryTypeId' => 12,
        'target' => 'a',
        'choices' => [
            'body' => 'combine',
            'topics' => 'combine',
            'heroImage' => 'a',
            'legacyId' => 'blank',
        ],
        'titleFrom' => 'a',
        'slugFrom' => 'a',
        'authorFrom' => 'a',
        'postDateFrom' => 'earliest',
        'statusFrom' => 'a',
        'parentFrom' => 'a',
        'disposition' => 'trash',
        'rewire' => true,
        'allSites' => false,
    ],
],
```

A handle **absent** from `choices` is not the same as one set to `blank`: absent means "no
opinion" and falls back to the pre-selection rule, which is what lets a preset be replayed against
an entry type it was not written for.

## A config file

```php
<?php
// config/glue.php

return [
    '*' => [
        'defaultStrategy' => 'nonEmpty',
        'defaultDisposition' => 'leave',
        'textSeparator' => "\n\n",
        'logMerges' => true,
    ],
    'production' => [
        // Rewiring on production, where the inbound links actually matter.
        'rewireRelations' => true,
        'rewireThreshold' => 10,
    ],
];
```

Settings in `config/glue.php` are read-only in the control panel, as with any Craft plugin.
