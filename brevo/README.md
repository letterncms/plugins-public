# Brevo

Sends the site's email through Brevo's transactional API and lets visitors join your Brevo newsletter lists from a signup block, with double opt-in. Free, under the MIT license.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/brevo
```

## Settings

Under Settings → Email, once **Brevo** is the transport:

- **Brevo API Key**: from Brevo → SMTP & API → API keys. Stored encrypted.

Under Settings → Brevo:

- **Brevo API Key**: asked for only while there is none under Settings → Email. That one is used for lists too.
- **Default List**: where signups go when a block doesn't name a list.
- **Confirm Addresses by Email**: double opt-in, on by default. The site mails a link that is good for two days. The link opens a page that asks, and its button adds the address to the list: a mail scanner that follows the link subscribes nobody. One address is mailed at most three times an hour.
- **Confirmation Subject**, **Confirmation Message**: what that email says. Left empty, it is written in the language of the page the person signed up on.
- **After Confirming, Go To**: a page of the site to show instead of the plain confirmation.

When the built site is hosted elsewhere, signups go to the address under Forms → Settings → **Submission Address** (`endpoint` in `config/forms.yaml`): one place says where the CMS answers.

The **Newsletter Signup** block (`brevo/signup`) has its own list, label, button label, consent sentence and success message.

## Abilities

- `brevo/stats`: transactional email totals for the last 7 days. Needs `settings.manage`.
- `brevo/lists`: the account's contact lists with id, name and subscribers. Needs `settings.manage`.
- `brevo/subscribe`: add an address to a list right away, without a confirmation email. Needs `settings.manage`.

Agents read `cms://brevo` for whether a key is set, the default list and whether addresses are confirmed.

## Hooks

- `brevo.subscribed` (action): `string $email, int $list`, after an address was added to a list.
- `fields.options.brevo-lists` (filter): the lists a select field with `dynamic: brevo-lists` offers, as `#<id>` => name.
