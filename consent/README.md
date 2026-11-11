# Consent

A cookie-consent banner on the published site, in the site's own design. Visitors accept everything, only what is necessary, or choose between analytics and marketing; scripts wait for their answer. The banner sits over the page, so nothing moves when it appears, and the answer is kept in the browser, so it works on built pages too. Visitors who send Global Privacy Control aren't asked: nothing is accepted until they say otherwise.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/consent
```

## Settings

Under Settings → Consent (`config/consent.yaml`).

| Setting | What it does |
|---|---|
| `enabled` | Show the banner. On by default. |
| `analytics` | Ask about analytics. On by default. |
| `marketing` | Ask about marketing. Off by default. |
| `reopen` | Show the **Cookie Settings** button once a visitor has answered. On by default. |
| `title_<language>`, `text_<language>` | The banner's title and text, once per language of the site (`title_en`, `title_pt_br`). Left empty, the banner says its own words in that language, fitted to what it asks about; the empty field shows them. |
| `policy_<language>` | The privacy policy the banner links to: a page of the site or an address. |

With neither analytics nor marketing asked about, pages carry no banner at all. Asking about another category asks every visitor again.

A link to `#cms-consent`, or any element with `data-cms-consent-open`, opens the choices again: put one in the footer if you turn the button off.

## Abilities

None.

## Hooks

For scripts on the page:

- `window.cmsConsent` is `{necessary: true, analytics, marketing, decided}`, with `open()` to show the choices and `set({analytics, marketing})` to answer for the visitor.
- Every answer, and a stored one on each page load, fires `cms:consent` on `document` with `detail` `{necessary, analytics, marketing}`. Analytics waits for it in visitors mode.
- `<script type="text/plain" data-cms-consent="marketing">` (or `analytics`) runs once that category is accepted. Taking a category back reloads the page.
- The answer is in the first-party cookie `cms_consent`, for six months.

In PHP it adds the banner through the `site.foot` filter (never in the builder or a preview) and its settings to `site.build.signature`.
