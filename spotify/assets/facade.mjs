// Spotify blocks that load on a click (SpotifyPlayer::render): each is a card that links to Spotify. Pressed here, it
// becomes Spotify's player at the same height. Until then nothing on the page has contacted Spotify.
const ready = new WeakSet()

function load(card) {
  const frame = document.createElement('iframe')
  frame.src = card.dataset.cmsSpotify
  frame.title = card.dataset.cmsSpotifyTitle || ''
  frame.style.height = card.style.height
  frame.allow = 'autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture'
  card.replaceWith(frame)
  frame.focus()
}

export function start() {
  for (const card of document.querySelectorAll('a[data-cms-spotify]')) {
    if (ready.has(card)) continue
    ready.add(card)
    // A button now: it loads the player here instead of leading away.
    card.setAttribute('role', 'button')
    card.addEventListener('click', (event) => {
      // A new tab or window still opens it on Spotify.
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
      event.preventDefault()
      load(card)
    })
    card.addEventListener('keydown', (event) => {
      if (event.key !== ' ') return
      event.preventDefault()
      load(card)
    })
  }
}
