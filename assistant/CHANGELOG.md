# Changelog

## Unreleased

### Added

- The chat works beside the page, on every screen. **New Chat** at the top of the sidebar, the chat button at the end of the top bar or `⌘J` opens it at the right edge, ready to type in. The page stays open and usable, and anything it changes shows there as it happens, in the builder too. Drag it wider, or take it to full size and back without losing your place.
- In an editor the chat floats over the page, so the inspector stays where it is and the canvas doesn't move; drag it wherever suits you. Selecting a block with the chat open attaches that block. Anywhere else, **Detach** does the same.
- The chat knows what you're looking at, including what's on the page: the entry you have open, the block selected in the builder, the text you selected. Chips in the composer show what a message carries, and each comes off with its ×. Account, Users, Licence and Audit Log stay private, and show no chip.
- `@` in a message attaches anything else: a page or post, a file from the library, a global set, the collections, the design system. `/` asks for a skill by name, such as `/proofread`, `/build-page` or `/audit-site`. Plugins add to both.
- Each message shows what it carried, one click away.
- Chats name themselves after the first reply, and a new chat suggests what to work on based on what you have on view. Both run on a small model you choose under **Settings → Assistant**: Claude Haiku 5.5 unless you change it. A suggestion goes into the composer for you to read before sending.

### Changed

- Fewer approvals: a chat that has read content others may have written saves drafts without asking, since nothing is live until you publish and the history keeps what was there. Anything that goes live or can't be undone still asks, and **Ask Before: Every change** still asks for everything.
- Your chats are in one place: in the chat, all of them, by age, with a column of their own at full size. They're no longer listed in the sidebar or the Ask widget.

## 0.1.0 (2026-11-01)

### Added

- First release.
