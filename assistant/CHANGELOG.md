# Changelog

## Unreleased

## 0.7.0 (2026-11-04)

### Added

- Claude opens places of the admin for you as it answers: "go to the last edited page", "open the General settings at the site name" or "show me the visitors" takes you there, with the thread beside it. Places it mentions are links that open in the admin.

### Changed

- **Breaking.** Needs the CMS 0.6 or later. Claude answers in the workspace's thread, now part of the CMS, as its agent: the chat, its history, composer and approvals are the workspace's, and work the same with or without the assistant. Claude is told what you ran and searched in the thread since it last answered.
- Without an API key, or for people without **Use the AI Assistant**, the thread runs commands and searches, and nothing in it mentions Claude. The setup lives in the assistant's settings.
- The model picker sits in the thread's composer, through the workspace's `workspace.composer` slot, and the **Ask** widget asks in the thread.
- Claude can turn plugins on and off and uninstall them, and always asks first.

### Removed

- **Breaking.** `/api/admin/assistant/chat`, the conversation abilities (`assistant/conversations`, `assistant/conversation`, `assistant/conversation-title`, `assistant/conversation-delete`) and the `assistant_conversations` table: threads are the workspace's (`/api/admin/workspace/turn`, `threads/*`). Chats from before are not carried over.
- **Hide Chat**: the thread is the admin's, and works without a key.

### Fixed

- A chat goes on after an update, a renamed site or another plugin. Claude's earlier reasoning in it is set aside when its instructions change, where Anthropic used to refuse the chat with an invalid `signature` in a `thinking` block.

## 0.6.1 (2026-11-04)

### Fixed

- Its words read the same as the rest of the admin in Dutch, French and German: where two packages translated a word differently, which one you saw could change from one page to the next.

## 0.6.0 (2026-11-04)

### Changed

- Answers are drawn with the same components as the admin's own screens, and can use notices, charts, details and before-and-after.

## 0.5.0 (2026-11-04)

### Changed

- Needs the CMS 0.4 or later.
- The Assistant's settings are under Plugins in Settings, and the **Chat** button lines up with the other buttons in the bar.
- The assistant's settings say less.
- The Ask widget says it asks from the Dashboard, and the permission is **Use the AI Assistant**.
- **Chat** stays in its place in the top bar while the chat is docked, shown pressed, and closes it again.
- The chat's setup says less: **Hide Chat** is there on its own.

### Fixed

- **New Chat** after a first message that failed starts a fresh chat instead of keeping the failed one.
- A chat opened by its address that is older than the chats loaded so far is listed at the top of the chats, as the one on view.

## 0.4.0 (2026-11-02)

### Changed

- An approval says in a sentence what it changes, such as “Add alt text to terrace.jpg”, instead of the action's name and an ID. Exactly what it sends is one click away, and the note under it says plainly why it's asking.

## 0.3.0 (2026-11-02)

### Changed

- New chats use Claude Sonnet 5.5 unless you pick another model. It handles everyday site work well, faster and at half the price of Opus. Claude Opus 5.5 is still in the model menu under the chat box. A default you saved under **Settings → Assistant** stays as it is.

### Removed

- A new chat no longer suggests what to work on. The suggestions called the model each time you opened a page, and a new chat is quieter without them. The chat still sees what you have on view.

## 0.2.0 (2026-11-02)

### Added

- The chat works beside the page, on every screen. **Chat** in the top bar or `⌘J` opens it at the right edge, ready to type in. The page stays open and usable, and anything it changes shows there as it happens, in the builder too. Drag it wider, or take it to full size and back without losing your place.
- In an editor the chat floats over the page at the right of the canvas, so the inspector stays where it is and the canvas doesn't move; drag it wherever suits you. Selecting a block with the chat open attaches that block. Anywhere else, **Detach** does the same.
- The chat knows what you're looking at, including what's on the page: the entry you have open, the block selected in the builder, the text you selected. Chips in the composer show what a message carries, and each comes off with its ×. Account, Users, Licence and Audit Log stay private, and show no chip.
- `@` in a message attaches anything else: a page or post, a file from the library, a global set, the collections, the design system. `/` asks for a skill by name, such as `/proofread`, `/build-page` or `/audit-site`. Plugins add to both.
- Each message shows what it carried, one click away.
- Chats name themselves after the first reply, and a new chat suggests what to work on based on what you have on view. Both run on a small model you choose under **Settings → Assistant**: Claude Haiku 5.5 unless you change it. A suggestion goes into the composer for you to read before sending.

### Changed

- Fewer approvals: a chat that has read content others may have written saves drafts without asking, since nothing is live until you publish and the history keeps what was there. Anything that goes live or can't be undone still asks, and **Ask Before: Every change** still asks for everything.
- Your chats are in one place: in the chat, all of them, by age, with a column of their own at full size. They're no longer listed in the sidebar or the Ask widget.
- **New Chat** is no longer a sidebar row, since the sidebar isn't always there: **Chat** in the top bar is on every screen. If you don't use AI, **Hide Chat** on the setup screen takes it out of the bar.

## 0.1.0 (2026-11-01)

### Added

- First release.
