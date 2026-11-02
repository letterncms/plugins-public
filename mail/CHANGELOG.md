# Changelog

## Unreleased

### Fixed

- **Server mail (sendmail)** is only offered where the host lets PHP run it. On hosts that turn `proc_open` off, it failed every email; a site still set to it now shows that email isn't set up, so you can choose SMTP instead.

## 0.1.0 (2026-11-01)

### Changed

- Mail wears the site's colours, and mail to visitors and buyers has one clear button with its link written out.
