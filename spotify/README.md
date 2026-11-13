# Spotify

Adds a **Spotify** block: a song, album, playlist, artist, podcast episode or show with Spotify's own player. Paste its link from Spotify's Share menu, as `open.spotify.com/…` or a `spotify:` URI. One thing to play gets the compact player and a list gets the tall one, or choose a size.

The site asks Spotify about each link once, not the visitor: its title, which screen readers read when the block has none, and whether it can be shown. Something private or gone shows nothing on the page, and the block says why in the editor, as it does for a short `spotify.link` link or a link to a library.

With **Load on Click**, the page shows a card with the title, a link to it on Spotify, and Spotify's player loads in its place, at the same height, when a visitor presses it. Until then nothing on the page contacts Spotify. It is off by default: the card has no artwork, since that would come from Spotify, and Spotify's player can't start playing by itself, so visitors press twice.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/spotify
```

## Settings

The plugin has no settings of its own. The block has these:

| Setting | What it does |
|---|---|
| **Spotify Link** (`url`) | The link or URI. |
| **Title for Screen Readers** (`title`) | Read out for the player. Empty: its title on Spotify. |
| **Size** (`size`) | `auto` (152 pixels for a song or an episode, 352 for the rest), `slim` (80, one row), `compact` (152) or `tall` (352). |
| **Colors** (`theme`) | `auto`, Spotify's colors from the artwork, or `dark`. Spotify's player has no light version. |
| **Load on Click** (`load_on_click`) | Off by default. On, a card first and the player on a click. |

In Block Markdown:

```markdown
::: spotify/player {"props":{"url":"https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M","theme":"dark"}}
:::
```

With the site's content security policy on, the player's frame from `open.spotify.com` is allowed.

## Abilities

None of its own. Agents add the block like any other, through the `blocks/*` and `entries/*` abilities; `blocks/types` lists it with an example.

## Hooks

It adds the player's host to the core's `site.policy` filter, and adds no hooks of its own.
