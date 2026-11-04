# Changelog

## Unreleased

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
