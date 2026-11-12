# Changelog

## Unreleased

## 0.14.1 (2026-11-12)

### Fixed

- A choice field's **Options** tell agents again that they go one per line, as `value|Label` to store a different value.

## 0.14.0 (2026-11-12)

### Added

- The Audit Log names what Forms changed in the past tense, such as **Created Form** and **Deleted Form Submissions**.
- With the site's content security policy on, a form's CAPTCHA (Turnstile or hCaptcha) loads and runs, and a form whose **Redirect To** is another site can lead there after it is sent without JavaScript: Forms names those hosts in the policy (`site.policy`).

### Changed

- A choice field's **Options** shows its format as a placeholder ("value|Label") instead of a sentence under it.
- A form's editor sits in the one column every form-like page has, and its **Delete** is the red button the other editors have.
- **Breaking.** A form's **Languages** tab is laid out as every value in several languages is: one group per thing the form says, its button or a field's label, with a field per language named in that language. `form-words/get` and `form-words/update` read and write `{"s:submit": {"de": "Senden"}}` instead of `"de:s:submit"` keys.
- **Breaking.** Needs the core 0.26 or newer, whose views draw a red **Delete** (and, as 0.24 does, say which phone **Phone** means).
- The first tab of the Forms screen is **All Forms**, so it no longer repeats the screen's name. With no forms yet, **New Form** is offered where the list would be.
- On a phone, a form's row says what its numbers count: “6 Fields”, “128 Submissions”, “3 Unread”.
- A dropdown's empty option reads **Choose** unless you give it other words, and the Form block's list reads **Choose** like every other select.
- The form builder no longer carries a line of advice under the fields: the empty line already says to press “/”.

### Fixed

- In the form builder, a field's name and a hidden value are real buttons: they take the keyboard, show focus and are large enough to tap.
- In German, the **Phone** field stays "Telefon" where the core now says "Smartphone" for a phone's screen, a slider's **Starts At** is its "Startwert", and **None** reads "Ohne", which fits whatever it stands beside.

## 0.13.0 (2026-11-11)

### Added

- Every Forms ability says what it answers with (`output`), for agents, the API and the CLI. A form’s settings come back in the types they are said to have: an empty address is `null`, a switch is `true` or `false`.

### Changed

- In German, a form’s handle is a “Handle” as everywhere else in the admin (“Ungültiger Formular-Handle.”), and **None** reads “Keine”, the core’s word for it.
- **Breaking.** The abilities follow the core’s naming rule, one thing and one verb each: `forms/activity` is `forms/overview`, `forms/configure` is `form-settings/update`, `forms/words` and `forms/translate` are `form-words/get` and `form-words/update`, and submissions are a thing of their own: `form-submissions/list`, `get`, `update`, `delete` and `csv` (were `forms/submissions`, `forms/submission`, `forms/submission-update`, `forms/submission-delete` and `forms/export`). The old names are gone.
- **Breaking.** `form-submissions/get` only reads: it no longer marks a new submission read. The admin’s dialog does that when it opens one, with `form-submissions/update`.
- A form’s settings and a field’s name show their shape as a placeholder instead of a note. The New Form dialog has no sentence and its button says **Create Form**; the submissions’ search field says **Search Submissions**.

## 0.12.0 (2026-11-11)

### Added

- **CAPTCHA** in the Forms settings: Cloudflare Turnstile or hCaptcha. With a provider and its two keys, every form shows the provider's check and a submission is accepted only when the provider vouches for it. A visitor who fails is told and can try again. While the provider can't be reached nothing gets through: the visitor reads that it isn't them and to try again in a few minutes, and the site's log says what happened. The secret key is stored encrypted, and the provider's script loads only on pages that show a form.
- The admin's words for Forms in German.
- `forms/delete` and `forms/submission-delete` take `dryRun: true` and answer with the form or the submissions they would remove, without removing them.

### Changed

- The Forms settings say less: no notes under the fields, and **Keep Submissions For** left empty reads **Forever**.
- **Breaking.** Needs CMS 0.22: the plugin calls the core by its new names (`setting()`), which an older CMS does not have.

## 0.11.0 (2026-11-06)

### Added

- **Tags**: visitors add several words or names, each with Enter, with suggestions you give. Without a script they're typed comma-separated.
- **Slider**: a number picked on a scale, with what comes before or after it, like *€* or *%*, and the minimum and maximum beside it.
- **Show As** on **Checkbox** makes it a switch, and on **Choice** and **Checkboxes** shows the options as chips.

### Changed

- **Breaking.** Needs CMS 0.17. Fields look as **Design System → Form Fields** sets them, like every other form on the site, instead of carrying styles of their own, and the send button takes the form's size. A field the server rejects is marked invalid for screen readers too.

## 0.10.0 (2026-11-05)

### Changed

- **Breaking.** Needs CMS 0.12. Forms look as they did in 0.8.0 again, styled by the site’s tokens and custom CSS.

## 0.9.0 (2026-11-05)

### Changed

- Built for CMS 0.11; 0.10.0 replaces it.

## 0.8.0 (2026-11-05)

### Changed

- **Breaking.** Needs the CMS 0.9.
- The Editor role a site starts with can view submissions and manage forms because Forms gives it both permissions, not the CMS: without Forms, the role doesn’t list them.
- **Breaking.** The Form block lists the forms through the `fields.options.forms` filter (was `blocks.options.forms`), and `FormFields::blocks()` takes no package name.
- A form that isn’t there says so with the admin’s own Not Found page.
- Sync lists form submissions as **Form Submissions**, pulled but not pushed, from this plugin's own `composer.json`.
- A changed form rebuilds only the pages that show it, and only when the site rebuilds on its own (Settings › Site › Rebuild Automatically on Publish), instead of the whole site every time.
- The notification email wears the site’s mail colours, like every other message the site sends, and comes with a plain-text version.
- Language names on a form’s Languages tab come from the Languages module.

### Security

- A webhook goes only to a public address, checked after DNS and pinned for the request, IPv6 included.

## 0.7.0 (2026-11-04)

### Changed

- **Breaking.** Needs the CMS 0.8, where Site comes with the CMS.

## 0.6.0 (2026-11-04)

### Changed

- **Breaking.** Needs the CMS 0.7, where Blocks, Email, Collaboration, Languages, MCP, Media and Navigation come with the CMS. Forms is on while Blocks is.

## 0.5.0 (2026-11-04)

### Added

- In the workspace, a new form is answered with **Open** and **Delete**.

## 0.4.1 (2026-11-04)

### Fixed

- Its words read the same as the rest of the admin in Dutch, French and German: where two packages translated a word differently, which one you saw could change from one page to the next.
- Forms in German address visitors with *du*, like the account and checkout pages.

## 0.4.0 (2026-11-04)

### Changed

- Needs the CMS 0.5 or later.
- A form's **Rename** and **Delete** are in its top bar, where its name was a field of its own, and its settings are in sections: what it does when sent, **Email** and **Submissions**. Someone who may only read submissions sees the settings and languages as they are.
- **Export CSV** is in the top bar of the **Submissions** tab.
- A Form block with no forms to choose from leads to **Forms** for **New Form**.

## 0.3.0 (2026-11-04)

### Changed

- Needs the CMS 0.4 or later.
- The form settings are a **Settings** tab on the Forms screen.
- **Submission Endpoint** is **Submission Address**, and **Delete Submissions After (Days)** is **Keep Submissions For**, with less text.
- With no forms yet, the list shows its message without a second **New Form** under the one in the top bar.
- On a phone, the search box above submissions takes the full width.
- Field blocks are in title case (**Short Text**, **Long Text**, **File Upload**, **Hidden Value**) with a few plain words each, and the Form block asks you to choose a form to show. Permissions are **View Form Submissions** and **Manage Forms**; the settings say less.
- The Form block asks to **Choose a Form**; with no forms yet its list says so and offers **New Form**, which opens the new-form dialog. A form’s On or Off in the list is a dot, like every table’s status.

## 0.2.0 (2026-11-02)

### Changed

- Forms no longer check the core's `CMS_LOCKDOWN`, which is gone. This version works with every 0.x of the core; the previous one stops saving on a core without lockdown.

## 0.1.1 (2026-11-02)

### Fixed

- A form embedded in a page shows its fields on the builder's canvas, not only its submit button, from the moment the page opens.

## 0.1.0 (2026-11-01)

### Added

- First release.
