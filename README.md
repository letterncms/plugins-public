# Plugins

The free plugins we build, in one place. Each one is in its own folder, with its own version and changelog, and installs on its own:

```bash
composer require letterncms/forms
```

Or from Modules → Store in the admin.

## Working here

You need PHP 8.4 and Composer:

```bash
git clone https://github.com/letterncms/plugins-public
cd plugins-public
composer install
composer test
```

The admin parts take Node 22 and pnpm:

```bash
pnpm install
pnpm build
pnpm typecheck
```

To try a change in a real site, build first, then make a site next to your clone and point it there:

```bash
composer create-project letterncms/lettern ../sandbox
cd ../sandbox
composer config repositories.plugins path '../plugins-public/*'
composer require letterncms/forms
php cms install
php cms serve
```

Open issues and pull requests here. The repository named after each plugin, such as [letterncms/forms](https://github.com/letterncms/forms), is what Composer installs from. A release writes it, so it's never edited by hand.
