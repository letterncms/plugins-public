# Changelog

## Unreleased

### Changed

- The card in the player's place shows the cover, on the colour the cover is mostly made of, with the title, at the player's own height, as Spotify's player looks. The site copies the cover when it asks Spotify about the link, so the page still contacts Spotify only when a visitor presses play.
- In the editor the block shows that card instead of the player: the editor runs no scripts, so Spotify's player could only show half of itself there. The site shows the player as before.

## 0.1.0 (2026-11-13)

### Added

- **Spotify** block: a song, album, playlist, artist, podcast episode or show with Spotify's player, compact for one thing to play and tall for a list, in Spotify's colors or dark. **Load on Click** shows a card instead and loads the player when it is pressed. Something private or gone shows nothing, and the editor says why.
