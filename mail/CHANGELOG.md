# Changelog

## Unreleased

## 0.3.0 (2026-11-04)

### Changed

- Needs the CMS 0.5 or later.
- **Send Test Email** on the Email settings page is a button of the page's bar, sent to whoever presses it.

## 0.2.0 (2026-11-04)

### Changed

- Needs the CMS 0.4 or later.
- The plugin is called Email, like its settings page.
- The Email settings page is described in one line.
- **Send Test Email** is a button on the Email settings page, as well as in the command palette.

## 0.1.1 (2026-11-02)

### Fixed

- **Server mail (sendmail)** is only offered where the host lets PHP run it. On hosts that turn `proc_open` off, it failed every email; a site still set to it now shows that email isn't set up, so you can choose SMTP instead.

## 0.1.0 (2026-11-01)

### Changed

- Mail wears the site's colours, and mail to visitors and buyers has one clear button with its link written out.
