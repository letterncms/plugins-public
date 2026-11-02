# Changelog

## Unreleased

## 0.3.0 (2026-11-02)

### Fixed

- Saving a page keeps every block as it was. A text block with a tag such as `span`, an id that interactions point at, or no text yet no longer turns into plain Markdown and loses them, two lists in a row stay two, and a screen size with no styles left is dropped instead of saved as an empty list. A page saved without changes keeps its file as it was.
- A save keeps `calc()` lengths in animations, and a `style` attribute that only sets CSS variables, such as a section's own colours.

### Added

- A box can be a heading (`h1` to `h6`) or a paragraph, such as a heading that holds a link.

## 0.2.0 (2026-11-02)

### Fixed

- Clicking into text on the canvas and leaving it no longer saves the page. Text the browser writes differently, such as a non-breaking space, counted as an edit and was changed without anyone typing.
- The admin's shortcuts, such as `⌘J`, `⌘K` and `⌘\`, work with the cursor on the canvas.
- A page whose blocks have short ids, or an id used twice, no longer opens with an empty canvas in the editor.

### Changed

- The builder gets the whole window: the admin's sidebar folds away when it opens and comes back when you leave. Bring it out with the same button and `⌘\`, and it shows the outline, with a way back to the list, the way it shows the settings on a settings page.
- One bar: insert, undo and redo moved from their own row into the top bar.
- The selected block is known to whatever works alongside the editor, such as the assistant's chat.
- The inspector no longer shows a block's parents above its name. The outline and the canvas lead to them.

## 0.1.0 (2026-11-01)

### Added

- An accordion can look like cards, with each question on a soft panel of its own.
- A row can step through its items with a Carousel behaviour and two buttons, and Tabs can stand in a column beside their panels.
- A List Filter can sort, with a new Filter Sort block and Sort By on the Repeat, and a Filter Choice can look like a list. A carousel can have thumbnails, through `data-carousel-to`.
- An Appear animation can now loop as one timeline: set Repeat to forever and a rest at the end, and its steps play together while the element is on screen, so a short product demo needs no video or script of its own.
- A Menu behavior for navigation: one panel moves between items, changing shape as it goes, with a pill that follows the pointer and keyboard focus.
- The Tabs block can look like a segmented control.
- A Table can mark one column, head its rows with its first column and use a soft look without rules, which stacks neatly on a phone.
- New Tabs and List Filter blocks that work without scripts, `where` on the entries source, and an RSS feed for each collection of dated entries.

### Changed

- **Breaking.** Animations no longer use GSAP. Page load, scroll and loop animations are plain CSS, and the rest use the Web Animations API in a 2 KB script. Each word animates from markup in the page, letters and lines read as words, and scroll's Smooth, Lazy and Hold in place are gone. Animations can play per screen size, and a new Motion Toggle block pauses what runs forever.

### Fixed

- Long code blocks and carousels of pictures can be scrolled from the keyboard, and the Search block's name no longer differs from its visible label.
