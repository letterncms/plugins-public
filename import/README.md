# Import

Brings a site over from somewhere else: a WordPress export (posts, pages, the media they show, and redirects from their old addresses), a CSV file, or a folder of Markdown with front matter. Every import is previewed first, and can be run again: what it made before is updated when its source changed, left alone when it did not, and never made twice.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/import
```

Put the file or folder in `storage/app/import/`, look at what it would do, then run it:

```bash
php cms import wordpress export.xml --dry-run
php cms import wordpress export.xml
php cms import csv products.csv --collection=products --map=Name=title --map=Price=price
php cms import markdown notes --collection=notes
```

- **WordPress** (Tools → Export, all content): posts go to `posts` and pages to `pages` unless `--collection` and `--pages` say otherwise; `--type-map=product=products` brings another post type along. Text becomes Markdown, blocks or rich text, whichever the collection's body field is. Images in the text and featured images are fetched into the media library, in the folder `import`. With SEO on, each old permalink redirects to the entry's new address. A file that could not be fetched is reported, and the next run fetches it and puts it into the entries that show it. Through an ability an export may be 20 MB; the command line reads up to 256 MB.
- **CSV**: one row, one entry, with a header row. A column goes to the field of the same name; `title`, `slug`, `status`, `date`, `body` and `redirect_from` are the entry's own. `--map=Column=field` sends a column elsewhere, and `--key` names the column that tells rows apart (`id` when there is one).
- **Markdown**: every `.md` file in the folder, with YAML front matter. `title`, `slug`, `date`, `draft` and `redirect_from` or `aliases` are the entry's own; other keys go to the field of the same name. A file named `2024-05-01-hello.md` is `hello`, published that day.

A record that can't be published yet, because a required field is empty, comes in as a draft and the report says why. `--as=you@example.com` makes the entries yours; `--json` prints the report as JSON.

## Settings

None.

## Abilities

| Ability | What it does | Permission |
|---|---|---|
| `import/sources` | Lists what is in `storage/app/import/`, with the type each looks like. | `import.run` |
| `import/preview` | Says what an import would do, per record, file and redirect, without doing it. | `import.run` |
| `import/run` | Runs the import and answers with the same report. | `import.run` |

`import.run` (**Import Content**) is for admins until a site's roles give it to someone else.

## Hooks

None of its own. Entries are saved through the entry service and files through the media library, so `entry.saved`, `entry.published` and `media.uploaded` fire as they do for any other change, and redirects go through SEO's `redirects.saved`.
