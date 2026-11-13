// YouTube blocks that wait to be played (YoutubeVideo::render): each is a link to the video on YouTube. Pressed here, it
// becomes YouTube's player, already playing, in the same place. Until then nothing on the page has contacted YouTube.
const ready = new WeakSet()

function play(link) {
  const frame = document.createElement('iframe')
  frame.src = link.dataset.cmsYoutube
  frame.title = link.dataset.cmsYoutubeTitle || ''
  frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen'
  frame.allowFullscreen = true
  frame.referrerPolicy = 'strict-origin-when-cross-origin'
  link.replaceWith(frame)
  frame.focus()
}

export function start() {
  for (const link of document.querySelectorAll('a[data-cms-youtube]')) {
    if (ready.has(link)) continue
    ready.add(link)
    // A button now: it plays here instead of leading away.
    link.setAttribute('role', 'button')
    link.addEventListener('click', (event) => {
      // A new tab or window still opens the video on YouTube.
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
      event.preventDefault()
      play(link)
    })
    link.addEventListener('keydown', (event) => {
      if (event.key !== ' ') return
      event.preventDefault()
      play(link)
    })
  }
}
