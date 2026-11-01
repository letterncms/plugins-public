// Filters the items of a Repeat inside a List Filter: all terms for search, one value per Choice, and
// reorders them by a Sort. Without this script the controls are hidden and every item is there in its
// own order. ?category=seo&q=image&sort=name links to a result.
const fold = (text: string) => text.toLowerCase().normalize('NFD').replace(/\p{M}/gu, '')

function setup(root: HTMLElement) {
  const items = [...root.querySelectorAll<HTMLElement>('[data-cms-fi]')]
  const empty = root.querySelector<HTMLElement>('[data-cms-filter-empty]')
  const status = root.querySelector<HTMLElement>('[data-cms-filter-status]')
  const search = root.querySelector<HTMLInputElement>('[data-cms-filter-control] input[type=search]')
  const facets = [...root.querySelectorAll<HTMLElement>('fieldset[data-facet]')]
  const sort = root.querySelector<HTMLSelectElement>('[data-cms-filter-control] select[name=sort]')
  const collator = new Intl.Collator(document.documentElement.lang || undefined, { numeric: true, sensitivity: 'base' })
  let timer = 0

  const chosen = (facet: HTMLElement) => facet.querySelector<HTMLInputElement>('input:checked')?.value ?? ''

  // Back to the Repeat's own order, then the chosen one. A stable sort keeps ties in that order.
  const reorder = () => {
    if (!sort || !items.length) return
    const { field, direction } = sort.selectedOptions[0]?.dataset ?? {}
    const sign = direction === 'desc' ? -1 : 1
    const value = (item: HTMLElement) => item.getAttribute(`data-cms-s-${field}`) ?? ''
    const ordered = field ? [...items].sort((a, b) => sign * collator.compare(value(a), value(b))) : items
    const parent = items[0].parentElement!
    const after = items[items.length - 1].nextSibling
    const end = document.createComment('')
    parent.insertBefore(end, after)
    for (const item of ordered) parent.insertBefore(item, end)
    end.remove()
  }

  const apply = (write: boolean) => {
    const terms = fold(search?.value ?? '').split(/\s+/).filter(Boolean)
    let count = 0
    for (const item of items) {
      const text = item.dataset.cmsQ ?? ''
      const show = terms.every((t) => text.includes(t)) && facets.every((f) => {
        const value = chosen(f)
        return !value || (item.getAttribute(`data-cms-f-${f.dataset.facet}`) ?? '').split(' ').includes(value)
      })
      item.hidden = !show
      if (show) count++
    }
    reorder()
    if (empty) empty.hidden = count > 0
    clearTimeout(timer)
    timer = window.setTimeout(() => {
      if (status) status.textContent = (root.dataset.status ?? ':count of :total').replace(':count', String(count)).replace(':total', String(items.length))
    }, 250)
    if (write) {
      const url = new URL(location.href)
      search && (search.value ? url.searchParams.set('q', search.value) : url.searchParams.delete('q'))
      sort && (sort.value ? url.searchParams.set('sort', sort.value) : url.searchParams.delete('sort'))
      for (const f of facets) chosen(f) ? url.searchParams.set(f.dataset.facet!, chosen(f)) : url.searchParams.delete(f.dataset.facet!)
      history.replaceState(history.state, '', url)
    }
  }

  const read = () => {
    const query = new URLSearchParams(location.search)
    if (search) search.value = query.get('q') ?? ''
    if (sort) {
      const wanted = [...sort.options].find((o) => o.value === query.get('sort'))
      sort.value = wanted ? wanted.value : (sort.options[0]?.value ?? '')
    }
    for (const f of facets) {
      const inputs = [...f.querySelectorAll<HTMLInputElement>('input')]
      // A value this list doesn't have is ignored, and leaves the URL at the next change.
      const wanted = inputs.find((i) => i.value === (query.get(f.dataset.facet!) ?? '')) ?? inputs[0]
      if (wanted) wanted.checked = true
    }
    apply(false)
  }

  root.addEventListener('input', (event) => {
    if ((event.target as Element).closest('[data-cms-filter-control]')) apply(true)
  })
  addEventListener('popstate', read)
  if (location.search) read()
}

export function start() {
  document.querySelectorAll<HTMLElement>('[data-cms-filter]').forEach(setup)
}
