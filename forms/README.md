# Forms

Form builder on the block editor, spam-protected submissions for static sites, notifications, confirmations, webhooks and an inbox.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/forms
```

## Settings

Under Forms → Settings, kept in `config/forms.yaml`:

| Setting | What it does |
|---|---|
| `endpoint` | The address of the CMS, when the site is hosted somewhere else: built pages post submissions there. Other plugins with a public endpoint of their own, such as Brevo's signup, use it too. |
| `origins` | Other websites whose forms may post here. |
| `retention_days` | How many days submissions are kept. Empty (or `0`) keeps them forever. |
| `captcha` | The check a visitor passes before a form is accepted: `none`, `turnstile` (Cloudflare Turnstile) or `hcaptcha`. |
| `captcha_site_key` | The provider's site key, carried by the page. |
| `captcha_secret` | The provider's secret key. Stored encrypted, never in `config/`. |

Each form has settings of its own on its Settings tab: what the button and the success message say, a page to go to instead, who is emailed, the confirmation email, whether submissions are kept, the hidden spam trap, the least seconds to fill it in, and a webhook.

## Abilities

| Ability | Permission | What it does |
|---|---|---|
| `forms/list` | `forms.view` | Forms with their field counts and new submissions. |
| `forms/overview` | `forms.view` | The number of forms, unread submissions, submissions per day for two weeks, and the latest ones. |
| `forms/get` | `forms.view` | One form: its fields and settings. |
| `forms/create` | `forms.manage` | A new form with name, email and message fields. |
| `forms/save` | `forms.manage` | Create or update a form. What is left out stays. |
| `form-settings/update` | `forms.manage` | Change some of a form's settings. |
| `form-words/get` | `forms.view` | What a form says in the site's other languages. |
| `form-words/update` | `forms.manage` | Store what a form says in other languages. |
| `forms/delete` | `forms.manage` | Delete a form. Its submissions are kept. With `dryRun: true` it only names the form. |
| `form-submissions/list` | `forms.view` | Submissions of a form, newest first. |
| `form-submissions/get` | `forms.view` | One submission, with its form's field labels. Reading it leaves it unread. |
| `form-submissions/update` | `forms.view` | Mark submissions as new, read, spam or archived. |
| `form-submissions/delete` | `forms.manage` | Delete submissions and their files. With `dryRun: true` it only lists them. |
| `form-submissions/csv` | `forms.view` | All submissions of a form as CSV. |

Every ability says what it answers with (`output`). The Editor role starts with `forms.view` and `forms.manage`.

## Hooks

| Hook | Kind | When |
|---|---|---|
| `form.saved` | action | A form was saved or deleted: `(string $handle)`. |
| `form.submitted` | action | A submission was accepted: `(array $form, array $data, ?Submission $submission)`. |
| `form.data` | filter | The answers of a valid submission, before they are stored and sent: `(array $data, array $form, Request $request)`. |
| `fields.options.forms` | filter | The forms a Form block offers. |

Both CAPTCHA providers implement `Cms\Forms\Captcha\Captcha` and ask through `Cms\Forms\Captcha\Client`, which a test binds to a stand-in.
