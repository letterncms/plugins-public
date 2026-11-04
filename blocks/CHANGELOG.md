# Changelog

## Unreleased

## 0.5.0 (2026-11-04)

### Added

- A plugin can name its own block category (`BlockRegistry::category()`). Categories without a name show their handle in title case, after the built-in ones.

### Changed

- Needs the CMS 0.4 or later.
- Templates and Components are in the sidebar's Design section, after the Design System.
- **Desktop**, **Tablet** and **Phone** are in the editor's top bar, with or without a selected block, instead of the block inspector's header. Desktop always shows the desktop layout: when the window has less room, the page is scaled down rather than squeezed into its tablet or phone layout. On a phone, the editor shows the phone layout.
- A block's inspector has one header: the way back, the block's name and its actions share a row, and the Content tab no longer repeats "Content" as a heading. A component's copy is named after its component (Header, Footer) in the Outline, the toolbar and the inspector.
- Every control in the inspector ends at the same edge. Connecting a field to data shows beside its label when you point at the row, and switches don't offer it.
- Every section title in the inspector works the same way: click it to fold or unfold the section, with + at the right where you can add to it. An empty one says **None** when opened. The Interact tab's "Show and States" is now "Bindings".
- A component's **Synced**, **Category**, **Description** and **Properties** are one group of rows above **Info**, labeled like the rows around them.
- Block descriptions in the inserter are a few plain words, and searching puts the block whose name matches first: "form" finds the Form block before the form's fields.
- Menu items and buttons use title case ("Insert Before", "Move Up", "Add Block", "Browse All"), Delete shows the same ⌫ in its menu and its tooltip, and "Filter Empty" is "No Results".
- The state bar only appears for tablet and phone styles, and states are named as in the menu ("Pressed", not "active").
- A selected block shows its outline while you write in it too, 1.5px in light and dark mode; its toolbar appears with Esc. The toolbar sits above the block, or below it or at its right where it would cover a logo, a heading or other content.
- Blocks inside a Repeat (a post card, its title) can be selected on the canvas and show their outline, the first item standing for them. Their text still comes from the data.
- The editor keeps the inspector's place while it loads, so the canvas doesn't change width, and the insert button leaves the top bar on phones, where the canvas has its own.
- The inspector reads as one panel: every tab has the same rows, an 88px label column, 28px controls and one section header. A value set on the block reads darker (click its label to reset it) and one that comes from a larger screen or another state carries a small dot, instead of blue and orange labels. Choices never wrap onto a second line, and states sit on one track like a segmented control.
- The Outline names layers by what they are and hold: a box is a Section, Header, Row, Stack or Grid followed by its heading or first words, an image by its alt text. Icons are quieter, nesting is tighter and the selected layer stands out.
- Duplicate, Make a Component and Delete are in the block's ⋯ menu, beside Rename.
- The Dashboard widget that lists components and templates is called **Components and Templates**, no longer **Design System**. The warning on **Add Custom HTML Blocks** reads like the others.

- Content tab fields follow the Design tab's rule: long choices and long labels (often in translation) sit above their control instead of being cut off.
- Technical settings move under **Advanced** in the Content tab (formerly **Element**): the HTML tag, a button's type, a heading's anchor, and how an image loads (**Load First**, **Shown Width**, formerly **Sizes**). Their explanations are gone.
- A button's look is called **Style** in the Content tab, as when writing. Screens have one name everywhere: Columns **Stack On** offers **Phone** and **Tablet and Phone**, and the Design tab says **Tablet and Phone** and **Back to Desktop**.
- A label, caption or attribution is a one-line field in the Content tab instead of a rich text box with its own link button.
- In the Design tab, **Style** (upright or italic) and **Decoration** have a row each, **Opacity** is a percentage, and the token button of **Shadows** shows on hover like every other one.
- Units after a value (rem, px) get the room they need, so they never cover the number.
- The Outline describes every container the same way, by a heading among its own blocks or else their first words, and shows text that comes from data by its field (a post's **Header** reads *Header Title*, apart from the site's Header).

### Fixed

- A color, size, duration or CSS value that CSS can't use is no longer saved: the field shows it as invalid and goes back to the last value when you leave it. Typing a second hex code into a color used to save both and break the button using it.
- Excerpts keep words of separate paragraphs, headings and list items apart ("details that vary. Choose what…"), and end with an ellipsis character.
- Blocks from plugins no longer come first in the inserter under their raw category handle.
- An image without a caption is named by its alt text wherever blocks are listed.

## 0.4.0 (2026-11-02)

### Added

- A block shown or hidden on `account` works on pages built in advance: both versions are in the page, and the browser shows the one for someone signed in or out before it's painted.

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
