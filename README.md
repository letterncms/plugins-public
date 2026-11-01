# Plugins

The free plugins we build, in one place. Each one is in its own folder, with its own version and changelog, and installs on its own:

```bash
composer require letterncms/forms
```

Or from Modules → Store in the admin.

## Working here

Clone [letterncms/dev](https://github.com/letterncms/dev) and run `./dev setup`: this repository lands at `repos/plugins-public`, running with everything else. Its `AGENTS.md` has the rest.

Open issues and pull requests here. The repository named after each plugin, such as [letterncms/forms](https://github.com/letterncms/forms), is what Composer installs from. A release writes it, so it's never edited by hand.
