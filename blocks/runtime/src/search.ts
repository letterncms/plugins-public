// The Search block: instant search over /search.json (titles, headings and text of every live page).
interface Page {
  title: string
  url: string
  section?: string
  description?: string
  headings?: { text: string; id: string; level: number }[]
  text?: string
}

interface Hit {
  title: string
  url: string
  section: string
  snippet: string
  score: number
}

let index: Promise<Page[]> | null = null
const load = () => (index ??= fetch('/search.json').then((r) => (r.ok ? r.json() : { pages: [] })).then((d) => d.pages ?? []).catch(() => []))

const escape = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!)
const mark = (text: string, terms: string[]) => terms.reduce((html, t) => html.replace(new RegExp(`(${t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig'), '<mark>$1</mark>'), escape(text))

function search(pages: Page[], query: string): Hit[] {
  const terms = query.toLowerCase().split(/\s+/).filter(Boolean)
  if (!terms.length) return []
  const hits: Hit[] = []
  for (const page of pages) {
    const title = page.title.toLowerCase()
    const text = (page.text ?? '').toLowerCase()
    let score = 0
    for (const t of terms) {
      if (title.includes(t)) score += title.startsWith(t) ? 12 : 8
      else if (page.headings?.some((h) => h.text.toLowerCase().includes(t))) score += 4
      else if ((page.description ?? '').toLowerCase().includes(t)) score += 3
      else if (text.includes(t)) score += 1
      else {
        score = 0
        break
      }
    }
    if (!score) continue
    const heading = page.headings?.find((h) => terms.some((t) => h.text.toLowerCase().includes(t)))
    hits.push({
      title: page.title,
      url: heading && !terms.every((t) => title.includes(t)) ? `${page.url}#${heading.id}` : page.url,
      section: page.section ?? '',
      snippet: heading ? heading.text : page.description || (page.text ?? '').slice(0, 140),
      score,
    })
  }
  return hits.sort((a, b) => b.score - a.score).slice(0, 12)
}

function dialog(config: { placeholder: string; empty: string }): HTMLDialogElement {
  const el = document.createElement('dialog')
  el.className = 'cms-search-dialog'
  el.setAttribute('aria-label', 'Search')
  el.innerHTML = `<input type="search" autocomplete="off" spellcheck="false" placeholder="${escape(config.placeholder)}" aria-label="Search"><ul role="listbox"></ul>`
  document.body.appendChild(el)
  const input = el.querySelector('input')!
  const list = el.querySelector('ul')!
  let hits: Hit[] = []
  let selected = 0

  const render = () => {
    const terms = input.value.toLowerCase().split(/\s+/).filter(Boolean)
    if (!input.value.trim()) list.innerHTML = ''
    else if (!hits.length) list.innerHTML = `<p>${escape(config.empty)}</p>`
    else list.innerHTML = hits.map((h, i) => `<li><a href="${escape(h.url)}" role="option" aria-selected="${i === selected}">${h.section ? `<small>${escape(h.section)}</small>` : ''}<strong>${mark(h.title, terms)}</strong><span>${mark(h.snippet, terms)}</span></a></li>`).join('')
  }
  input.addEventListener('input', async () => {
    hits = search(await load(), input.value)
    selected = 0
    render()
  })
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      selected = (selected + (e.key === 'ArrowDown' ? 1 : -1) + hits.length) % Math.max(1, hits.length)
      render()
      list.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' })
    } else if (e.key === 'Enter' && hits[selected]) {
      location.href = hits[selected].url
    }
  })
  el.addEventListener('click', (e) => e.target === el && el.close())
  return el
}

export function start() {
  const buttons = [...document.querySelectorAll<HTMLElement>('[data-cms-search]')]
  if (!buttons.length) return
  const config = { placeholder: 'Search…', empty: 'No results', shortcut: true, ...JSON.parse(buttons[0].dataset.cmsSearch || '{}') }
  let el: HTMLDialogElement | null = null
  const open = () => {
    el ??= dialog(config)
    void load()
    el.showModal()
    el.querySelector('input')!.select()
  }
  buttons.forEach((b) => b.addEventListener('click', open))
  if (config.shortcut) {
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        open()
      } else if (e.key === '/' && !(e.target as HTMLElement).closest('input,textarea,[contenteditable]')) {
        e.preventDefault()
        open()
      }
    })
  }
}
