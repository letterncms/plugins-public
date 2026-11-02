# Changelog

## Unreleased

## 0.2.0 (2026-11-02)

### Changed

- Redirects no longer check the core's `CMS_LOCKDOWN`, which is gone. This version works with every 0.x of the core; the previous one stops saving on a core without lockdown.

## 0.1.0 (2026-11-01)

### Added

- Set a security contact in SEO settings and the site publishes `/.well-known/security.txt`, renewed on every build. A home page's social title now matches its page title, and a site with no favicon no longer gets a 404 for `/favicon.ico`.
