# YouTube

Adds a **YouTube** block for a video or a Short. Paste its link, from the video's Share button, the address bar, a Shorts or an embed link, or just its id. The page shows the video's poster with YouTube's play button, and YouTube's player takes its place, already playing, when a visitor presses it. Until then nothing on the page contacts YouTube and no cookie is set, so pages stay fast and visitors decide. Without JavaScript the poster is a link to the video on YouTube.

The site asks YouTube about each video once, not the visitor: its title, which screen readers read when the block has none, and whether it may be shown. A private video, one that is gone, or one whose owner doesn't allow it on other sites shows nothing on the page, and the block says why in the editor. The poster is copied into the site, in `public/_cms/youtube/`, in the largest size YouTube has. If YouTube can't be reached when a page is made, the video still shows, with its poster from YouTube's image server.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/youtube
```

## Settings

The plugin has no settings of its own. The block has these:

| Setting | What it does |
|---|---|
| **Video Link** (`url`) | The video's link or id. A start time in the link (`t=1m30s`) is kept. |
| **Title for Screen Readers** (`title`) | Read out for the player. Empty: the video's title on YouTube. |
| **Start At** (`start`) | Where the video starts: `90`, `1:30` or `1m30s`. Wins over a time in the link. |
| **Aspect Ratio** (`aspect`) | `auto` (16:9, and 9:16 for a Short), `16/9`, `4/3`, `1/1` or `9/16`. |
| **Show Subtitles** (`captions`) | On by default: subtitles in the site's language when the video has them. |
| **Privacy-Enhanced Mode** (`privacy`) | On by default: the player comes from `youtube-nocookie.com`, which sets no cookie until the video plays. |
| **Load When Played** (`load_on_click`) | On by default: the poster first, the player on a click. Off, the player loads with the page. |
| **Poster Image** (`poster`) | A picture from the media library to show instead of YouTube's poster. |

In Block Markdown:

```markdown
::: youtube/video {"props":{"url":"https://www.youtube.com/watch?v=aqz-KE-bpKQ","start":"0:30"}}
:::
```

With the site's content security policy on, the player's frames from `www.youtube-nocookie.com` and `www.youtube.com` are allowed, and `i.ytimg.com` for a poster served by YouTube.

## Abilities

None of its own. Agents add the block like any other, through the `blocks/*` and `entries/*` abilities; `blocks/types` lists it with an example.

## Hooks

It adds the player's hosts to the core's `site.policy` filter, and names `i.ytimg.com` through `site.policy.source` when a poster comes from YouTube. It adds no hooks of its own.
