# Installation

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

Nothing else. Glue has no runtime dependencies, makes no outbound requests, and needs no build
step.

## Install

```sh
composer require justinholtweb/craft-glue
php craft plugin/install glue
```

Or find **Glue** in the Plugin Store and install it there.

The install creates one table, `glue_merges`, which holds the merge history. Nothing else in your
database is touched at install time.

## Choose an edition

Glue installs as **Lite**. Lite is a complete merge tool — every combine strategy, every target,
the warnings, the disposition of the sources and the history.

**Pro** adds relation rewiring, presets, cross-site merging, the console commands and the
duplicate finder. Switch editions from **Settings → Plugins → Glue**, or buy a licence in the
Plugin Store.

## Give people permission

Glue adds three permissions under **Settings → Users → User Groups → *group* → Glue**:

| Permission | What it allows |
| --- | --- |
| **Merge entries** | The merge action in the entry index, and the merge screen |
| **View the merge history** | The History screen, and the note on an entry's edit screen |
| **Save and delete merge presets** | Writing presets, which are project config |

Craft's own permissions still apply on top, and they are the authority. A merge is a save, so the
user must be able to save the merged entry; a merge that trashes its sources is a delete, so they
must be able to delete those too. Glue checks both with Craft's own `canSave()` and `canDelete()`
before it writes anything — a plugin permission that let somebody write to a section they cannot
otherwise write to would be a hole, not a feature.

Admins have all three.

## Check it worked

Go to any entry index, tick two entries, and open the actions menu. **Merge with Glue…** should be
there, and enabled only while exactly two entries are selected.

If it is not there, check that the user has the **Merge entries** permission, and that the index
is an *entry* index — Glue merges entries and nothing else.

## Uninstalling

```sh
php craft plugin/uninstall glue
```

This drops `glue_merges` and removes the plugin's settings from project config. **Entries that
Glue merged are ordinary entries and are not affected** — they stay exactly as they are. What you
lose is the record of which of them were merges.
