# Import

Brings a site over from somewhere else: a WordPress export (posts, pages, the media they show, and redirects from their old addresses), a CSV file, or a folder of Markdown with front matter. Every import is previewed first, and can be run again: what it made before is updated when its source changed, left alone when it did not, and never made twice.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/import
```

### In the admin

**Settings → Import** is the whole of it on one screen:

1. **Upload File** takes a WordPress export (`.xml`, up to 20 MB), a CSV file (up to 50 MB) or a zip of Markdown files, which is unpacked into a folder. A file is checked by what it holds, gets a safe name of its own, and goes to `storage/app/import/`; files put there by hand are listed too.
2. The file's page shows what importing it would do, before anything is written: how many entries would be created, updated or left unchanged, which would replace an entry that is already there with the same slug, the files it would fetch and the redirects it would add.
3. **Options** says where the entries go and, for a CSV, what each column is: the title, the slug, the date, a field of the collection, or left out.
4. **Start Import** runs it a few seconds at a time and shows how far it is. Reloading or closing the page loses nothing; on PHP-FPM and LiteSpeed it carries on with the page closed, elsewhere it carries on when the page is opened again. **Cancel Import** stops it, and what it made stays.
5. **History** keeps the report of every import: what was created, updated, left unchanged or failed, with the way to each entry.

### On the command line

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
| `import/run` | Runs the import in one go and answers with the same report. With `dryRun: true` it writes nothing and lists the entries, files and redirects it would make. | `import.run` |
| `import-sources/get` | One source with what was chosen for it, and the columns of a CSV. | `import.run` |
| `import-sources/update` | Chooses where a source's entries go and what each CSV column is. | `import.run` |
| `import-sources/preview` | What importing a source would do with what was chosen for it, a page at a time: entries, files or redirects. | `import.run` |
| `import-sources/delete` | Removes a file or folder from the import folder, not while it is being imported. A dry run says how many files go. | `import.run` |
| `import-runs/start` | Starts importing a source a few seconds at a time. A dry run lists what it would make. | `import.run` |
| `import-runs/step` | Imports for a few seconds more and says how the import stands. What failed in an earlier turn is not tried again; the Audit Log keeps only the turn that finishes the import. | `import.run` |
| `import-runs/cancel` | Stops an import that is running. | `import.run` |
| `import-runs/list` | The imports that were started, newest first, a page at a time. | `import.run` |
| `import-runs/get` | One import: how far it is and a page of what it did. | `import.run` |
| `import-runs/delete` | Forgets a finished import's report. | `import.run` |

Files are uploaded with `POST /api/admin/import/upload` (multipart, field `file`), signed in to the admin.

`import.run` (**Import Content**) is for admins until a site's roles give it to someone else.

## Hooks

None of its own. Entries are saved through the entry service and files through the media library, so `entry.saved`, `entry.published` and `media.uploaded` fire as they do for any other change, and redirects go through SEO's `redirects.saved`.
