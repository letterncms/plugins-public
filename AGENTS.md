# Public plugins

The free plugins. Each folder is one package, `letterncms/<folder>`, with its own version and changelog. The repository named after a plugin is written by a release: never edit it. `README.md` says how to run all of this from a clean clone.

- `composer test` needs no Node. The root `composer.json` installs the CMS from its `main` branch, with its test helpers, and links every folder here into `plugins/`.
- `pnpm build` and `pnpm typecheck` (the build doesn't typecheck) use the published `@letterncms/*` packages.

## Rules

- **The product's name in code is data:** `{brand}` or `:brand` in translated strings, `cms_brand()` in PHP, `brand()` in the admin. Identifiers use the permanent prefix `cms` and the permanent vendor `letterncms`.
- **Plugins only depend down:** on `letterncms/cms` and other plugins, in `require`. A library goes in the plugin's own `vendor/` unless the core already has it.
- **No plan files.** No specs, research or progress notes in the repository. Decisions go in commit messages.
- **Keep it true.** `letterncms/docs` describes the product as it is after your change.
- A change people can see or use gets a line under `## Unreleased` in that plugin's `CHANGELOG.md`, written for the reader, starting with `**Breaking.**` when it breaks something.
