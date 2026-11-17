// Site Search (SearchForm, SearchResults). On the results page the Search Results block holds an empty list: this reads
// `q` and `kind` from the address, searches the site's index (/search.json, the core's, with the kind, date and keywords
// the plugin adds) and draws the results. A Search Field there shows what was searched for. Without this script the
// form still sends its query, and the results page says search needs JavaScript.

/** @type {Promise<any[]> | null} */
let index = null
const load = () =>
  (index ??= fetch('/search.json', { credentials: 'same-origin' })
    .then((response) => (response.ok ? response.json() : { pages: [] }))
    .then((data) => (Array.isArray(data?.pages) ? data.pages.filter((page) => page && typeof page.title === 'string' && typeof page.url === 'string') : []))
    .catch(() => []))

const ready = new WeakSet()
const WORD = /[\p{L}\p{N}]/u

/** Lower case without accents: "Ärger" and "arger" are the same word to a search. */
const fold = (text) => String(text ?? '').normalize('NFD').replace(/\p{M}+/gu, '').toLowerCase()

/** What was typed, as the words to find: every one must match. */
const termsOf = (query) => [...new Set(fold(query).split(/[^\p{L}\p{N}]+/u).filter(Boolean))]

/** A word in the text starts with the term: "recycl" finds "recycling", "cycl" does not. */
function startsWord(text, term) {
  for (let at = text.indexOf(term); at !== -1; at = text.indexOf(term, at + 1)) {
    if (at === 0 || !WORD.test(text[at - 1])) return true
  }
  return false
}

const escape = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])

/** A record with its words folded once, for every search on the page. */
function prepare(page) {
  const headings = Array.isArray(page.headings) ? page.headings.filter((h) => h && typeof h.text === 'string') : []
  return {
    page,
    title: fold(page.title),
    keywords: fold(page.keywords),
    headings: headings.map((h) => ({ ...h, folded: fold(h.text) })),
    description: fold(page.description),
    text: fold(page.text),
  }
}

/** Score each record for the terms; a record must match every one. Title word-start > title > keywords > headings > description > text. */
function search(records, terms) {
  const hits = []
  records.forEach((record, order) => {
    let score = 0
    for (const term of terms) {
      if (startsWord(record.title, term)) score += record.title.startsWith(term) ? 14 : 12
      else if (record.title.includes(term)) score += 8
      else if (startsWord(record.keywords, term)) score += 6
      else if (record.headings.some((h) => startsWord(h.folded, term))) score += 4
      else if (startsWord(record.description, term)) score += 3
      else if (startsWord(record.text, term)) score += 1
      else return
    }
    // The words as typed, together in the title: the page people mean.
    if (terms.length > 1 && record.title.includes(terms.join(' '))) score += 6
    const inTitle = terms.every((t) => record.title.includes(t))
    // Only a heading names it: the result leads to that part of the page.
    let url = record.page.url
    if (!inTitle && !terms.every((t) => startsWord(record.keywords, t) || startsWord(record.description, t))) {
      const heading = record.headings
        .map((h) => ({ h, n: terms.filter((t) => startsWord(h.folded, t)).length }))
        .filter((x) => x.n > 0 && x.h.id)
        .sort((a, b) => b.n - a.n)[0]
      if (heading) url = `${record.page.url.split('#')[0]}#${heading.h.id}`
    }
    hits.push({ record, score, order, url })
  })
  return hits.sort((a, b) => b.score - a.score || a.order - b.order)
}

/** Folded text with, for each of its characters, where it came from in the original. */
function folded(text) {
  let out = ''
  const from = []
  for (let i = 0; i < text.length; i++) {
    const piece = fold(text[i])
    for (let j = 0; j < piece.length; j++) from.push(i)
    out += piece
  }
  from.push(text.length)
  return { out, from }
}

/** The text as HTML, every word a term starts wrapped in <mark>. */
function mark(text, terms) {
  const { out, from } = folded(text)
  const ranges = []
  for (const term of terms) {
    for (let at = out.indexOf(term); at !== -1; at = out.indexOf(term, at + 1)) {
      if (at > 0 && WORD.test(out[at - 1])) continue
      let end = at + term.length
      while (end < out.length && WORD.test(out[end])) end++
      ranges.push([from[at], from[end]])
    }
  }
  ranges.sort((a, b) => a[0] - b[0])
  let html = ''
  let last = 0
  for (const [start, end] of ranges) {
    if (start < last) continue
    html += escape(text.slice(last, start)) + '<mark>' + escape(text.slice(start, end)) + '</mark>'
    last = end
  }
  return html + escape(text.slice(last))
}

/** What a result says of its page: the description, else some 160 characters of its text around the first match. */
function snippet(page, terms) {
  const description = String(page.description ?? '').trim()
  if (description) return mark(description, terms)
  const text = String(page.text ?? '').trim()
  if (!text) return ''
  const { out, from } = folded(text)
  let first = -1
  for (const term of terms) {
    for (let at = out.indexOf(term); at !== -1; at = out.indexOf(term, at + 1)) {
      if (at === 0 || !WORD.test(out[at - 1])) {
        if (first === -1 || at < first) first = at
        break
      }
    }
  }
  let start = first === -1 ? 0 : Math.max(0, from[first] - 60)
  let end = Math.min(text.length, start + 160)
  // Whole words at both ends.
  if (start > 0) start = text.indexOf(' ', start) + 1 || start
  if (end < text.length) end = text.lastIndexOf(' ', end) > start ? text.lastIndexOf(' ', end) : end
  return (start > 0 ? '…' : '') + mark(text.slice(start, end), terms) + (end < text.length ? '…' : '')
}

/** The records in the page's language, when the index holds several. */
function inLanguage(pages, lang) {
  const locales = new Set(pages.map((p) => p.locale).filter(Boolean))
  if (locales.size < 2 || !lang) return pages
  const base = (code) => String(code).toLowerCase().replace('_', '-').split('-')[0]
  const exact = pages.filter((p) => !p.locale || String(p.locale).toLowerCase().replace('_', '-') === lang.toLowerCase())
  const kept = exact.some((p) => p.locale) ? exact : pages.filter((p) => !p.locale || base(p.locale) === base(lang))
  return kept.some((p) => p.locale) ? kept : pages
}

function formatDate(value, lang) {
  if (typeof value !== 'string' || !value) return ''
  const day = /^\d{4}-\d{2}-\d{2}$/.test(value)
  const date = new Date(day ? `${value}T00:00:00Z` : value)
  if (Number.isNaN(date.getTime())) return ''
  try {
    return new Intl.DateTimeFormat(lang || undefined, { dateStyle: 'long', ...(day ? { timeZone: 'UTC' } : {}) }).format(date)
  } catch {
    return date.toISOString().slice(0, 10)
  }
}

function formatNumber(value, lang) {
  try {
    return new Intl.NumberFormat(lang || undefined).format(value)
  } catch {
    return String(value)
  }
}

const fill = (text, values) => String(text).replace(/\{(count|query)\}/g, (_, key) => values[key])

function result(hit, terms, level, lang) {
  const page = hit.record.page
  const kind = typeof page.kind === 'string' && page.kind ? `<p class="cms-site-search-results__kind">${escape(page.kind)}</p>` : ''
  const date = formatDate(page.date, lang)
  const time = date ? `<time class="cms-site-search-results__date" datetime="${escape(page.date)}">${escape(date)}</time>` : ''
  const text = snippet(page, terms)
  const li = document.createElement('li')
  li.className = 'cms-site-search-results__item'
  li.innerHTML =
    `<article class="cms-site-search-results__result"><h${level} class="cms-site-search-results__title"><a href="${escape(hit.url)}">${escape(page.title)}</a></h${level}>` +
    kind +
    time +
    (text ? `<p class="cms-site-search-results__snippet">${text}</p>` : '') +
    '</article>'
  return li
}

function filters(nav, hits, query, current, texts, lang) {
  const counts = new Map()
  for (const hit of hits) {
    const kind = hit.record.page.kind
    if (typeof kind === 'string' && kind) counts.set(kind, (counts.get(kind) ?? 0) + 1)
  }
  if (counts.size < 2) {
    nav.hidden = true
    nav.innerHTML = ''
    return
  }
  const link = (kind, label, count) => {
    const params = new URLSearchParams({ q: query })
    if (kind) params.set('kind', kind)
    const here = (kind ?? '') === (current ?? '')
    return `<li><a href="?${escape(params.toString())}"${here ? ' aria-current="true"' : ''}>${escape(label)} (${escape(formatNumber(count, lang))})</a></li>`
  }
  nav.innerHTML = `<ul>${link(null, texts.all, hits.length)}${[...counts].map(([kind, count]) => link(kind, kind, count)).join('')}</ul>`
  nav.hidden = false
}

async function results(root) {
  let config
  try {
    config = JSON.parse(root.dataset.cmsSiteSearchResults || '{}')
  } catch {
    config = {}
  }
  const texts = config.texts ?? {}
  const level = [2, 3, 4].includes(config.level) ? config.level : 2
  const perPage = Math.max(1, Number(config.perPage) || 20)
  const status = root.querySelector('.cms-site-search-results__status')
  const nav = root.querySelector('.cms-site-search-results__filters')
  const list = root.querySelector('.cms-site-search-results__list')
  if (!status || !list) return
  // The button that shows more is the script's own: without scripts there is nothing it could do.
  let more = root.querySelector('.cms-site-search-results__more')
  if (!more) {
    more = document.createElement('button')
    more.type = 'button'
    more.className = `cms-button ${config.moreClass || 'btn-secondary'} cms-site-search-results__more`
    more.textContent = texts.more ?? ''
    more.hidden = true
    list.after(more)
  }

  const params = new URLSearchParams(location.search)
  const query = (params.get('q') ?? '').trim()
  const kind = (params.get('kind') ?? '').trim()
  const lang = document.documentElement.lang || navigator.language
  const terms = termsOf(query)
  const say = (text) => {
    status.textContent = text
  }

  list.innerHTML = ''
  more.hidden = true
  if (nav) nav.hidden = true

  if (!terms.length) {
    say(texts.noQuery ?? '')
    return
  }

  // The tab says what was searched for, before the site's name.
  root.dataset.cmsTitle ??= document.title
  document.title = `${query} – ${root.dataset.cmsTitle}`
  // The line keeps its place while the index loads: nothing moves when the count arrives.
  say(' ')

  const hits = search(inLanguage(await load(), lang).map(prepare), terms)
  // A kind none of the results has (an old link) shows them all.
  const current = kind && hits.some((hit) => hit.record.page.kind === kind) ? kind : ''
  const shown = current ? hits.filter((hit) => hit.record.page.kind === current) : hits
  const count = shown.length
  if (config.filters !== false && nav) filters(nav, hits, query, current, texts, lang)
  say(fill(count === 0 ? texts.none : count === 1 ? texts.one : texts.many, { count: formatNumber(count, lang), query }))

  let drawn = 0
  const draw = () => {
    const next = shown.slice(drawn, drawn + perPage).map((hit) => result(hit, terms, level, lang))
    list.append(...next)
    drawn += next.length
    more.hidden = drawn >= shown.length
    return next[0]
  }
  draw()

  if (!ready.has(more)) {
    ready.add(more)
    more.addEventListener('click', () => {
      const first = draw()
      // The first new result has the focus, so the keyboard and screen readers go on where the list grew.
      first?.querySelector('a')?.focus()
    })
  }
}

/** On the results page, the field shows what was searched for. */
function prefill(form) {
  const input = form.querySelector('input[name="q"]')
  if (!input || input.value) return
  let action
  try {
    action = new URL(form.getAttribute('action') || location.pathname, location.href)
  } catch {
    return
  }
  const path = (p) => p.replace(/\/(index\.html?)?$/, '') || '/'
  if (action.origin !== location.origin || path(action.pathname) !== path(location.pathname)) return
  const query = new URLSearchParams(location.search).get('q')
  if (query) input.value = query
}

export function start() {
  for (const form of document.querySelectorAll('form[data-cms-site-search-form]')) {
    if (ready.has(form)) continue
    ready.add(form)
    prefill(form)
  }
  for (const root of document.querySelectorAll('[data-cms-site-search-results]')) {
    if (ready.has(root)) continue
    ready.add(root)
    void results(root)
  }
}
