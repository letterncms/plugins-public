// Tabs by the WAI-ARIA pattern. Panels are real HTML, and `hidden="until-found"` lets find-in-page open them.
export function start() {
  document.querySelectorAll<HTMLElement>('[data-cms-tabs]').forEach((root) => {
    const list = root.querySelector<HTMLElement>(':scope > [role=tablist]')
    if (!list) return
    const tabs = [...list.querySelectorAll<HTMLElement>('[role=tab]')]
    const panelOf = (tab: HTMLElement) => document.getElementById(tab.getAttribute('aria-controls') ?? '')

    const select = (tab: HTMLElement, focus = false) => {
      for (const other of tabs) {
        const on = other === tab
        other.setAttribute('aria-selected', String(on))
        other.tabIndex = on ? 0 : -1
        const panel = panelOf(other)
        if (panel) on ? panel.removeAttribute('hidden') : panel.setAttribute('hidden', 'until-found')
      }
      if (focus) tab.focus()
      tab.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
    }

    list.addEventListener('click', (event) => {
      const tab = (event.target as Element).closest<HTMLElement>('[role=tab]')
      if (tab) select(tab)
    })

    list.addEventListener('keydown', (event) => {
      const at = tabs.indexOf(document.activeElement as HTMLElement)
      if (at < 0) return
      const next = { ArrowRight: at + 1, ArrowLeft: at - 1, ...(list.classList.contains('cms-tabs__list--vertical') ? { ArrowDown: at + 1, ArrowUp: at - 1 } : {}), Home: 0, End: tabs.length - 1 }[event.key]
      if (next === undefined) return
      event.preventDefault()
      select(tabs[(next + tabs.length) % tabs.length], true)
    })

    // Find in page, or a link to something inside a panel.
    for (const tab of tabs) panelOf(tab)?.addEventListener('beforematch', () => select(tab))
    const fromHash = () => {
      const target = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null
      const panel = target?.closest('[role=tabpanel]')
      const tab = panel && tabs.find((t) => t.getAttribute('aria-controls') === panel.id)
      if (tab) select(tab)
    }
    addEventListener('hashchange', fromHash)
    fromHash()
  })
}
