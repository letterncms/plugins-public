/** Keep live text portals and keyed source nodes while applying the server's markup. */
export function morphDocument(current: Document, next: Document) {
  const key = (node: Node): string | null => {
    if (node.nodeType !== 1) return null
    const el = node as HTMLElement
    if (el.hasAttribute('data-cms-document') && el.dataset.cmsNode) return `node:${el.dataset.cmsNode}`
    if (el.hasAttribute('data-cms-editor-root')) return 'source-root'
    return null
  }
  const existing = new Map<string, Element>()
  for (const el of current.body.querySelectorAll('[data-cms-document][data-cms-node],[data-cms-editor-root]')) {
    const id = key(el)
    if (id) existing.set(id, el)
  }
  const attrs = (from: Element, to: Element) => {
    for (const attr of [...from.attributes]) if (!to.hasAttribute(attr.name)) from.removeAttribute(attr.name)
    for (const attr of [...to.attributes]) if (from.getAttribute(attr.name) !== attr.value) from.setAttribute(attr.name, attr.value)
  }
  const patch = (from: Node, to: Node) => {
    if (from.nodeType === 3 || from.nodeType === 8) { if (from.nodeValue !== to.nodeValue) from.nodeValue = to.nodeValue; return }
    const a = from as HTMLElement, b = to as HTMLElement
    const slot = (a.hasAttribute('data-cms-prop') && a.getAttribute('data-cms-prop') === b.getAttribute('data-cms-prop') && a.getAttribute('data-cms-index') === b.getAttribute('data-cms-index')) || (a.hasAttribute('data-cms-entry-field') && a.getAttribute('data-cms-entry-field') === b.getAttribute('data-cms-entry-field')) || (a.hasAttribute('data-cms-instance-prop') && a.getAttribute('data-cms-instance-node') === b.getAttribute('data-cms-instance-node') && a.getAttribute('data-cms-instance-prop') === b.getAttribute('data-cms-instance-prop'))
    attrs(a, b)
    // React owns only the text inside explicit server slots; the server owns the surrounding layout.
    if (slot && a.querySelector('[data-cms-inline]')) return
    if (a.tagName === 'TEMPLATE') { a.innerHTML = b.innerHTML; return }
    const used = new Set<Node>()
    let cursor = a.firstChild
    for (const fresh of [...b.childNodes]) {
      const id = key(fresh)
      let match: Node | null = id ? existing.get(id) ?? null : cursor
      if (match && (used.has(match) || key(match) !== id || match.nodeType !== fresh.nodeType || (match.nodeType === 1 && (match as Element).tagName !== (fresh as Element).tagName))) match = null
      if (!match) match = current.importNode(fresh, true)
      if (match !== cursor) a.insertBefore(match, cursor)
      used.add(match)
      patch(match, fresh)
      cursor = match.nextSibling
    }
    for (const child of [...a.childNodes]) if (!used.has(child)) {
      // An optional caption/citation stays writable while focused, even when temporarily empty.
      const activeSlot = child.nodeType === 1 && !key(child) && ((child as Element).hasAttribute('data-cms-prop') || (child as Element).hasAttribute('data-cms-instance-prop')) && child.contains(current.activeElement)
      if (!activeSlot) child.remove()
    }
  }
  attrs(current.documentElement, next.documentElement)
  patch(current.body, next.body)
  // Site styles are authoritative, including styles generated for the current working copy.
  const styles = 'style:not([data-cms-bridge]),link[rel="stylesheet"]'
  const signature = (doc: Document) => [...doc.head.querySelectorAll(styles)].map((el) => el.outerHTML).join('')
  if (signature(current) !== signature(next)) {
    for (const el of [...current.head.querySelectorAll(styles)]) el.remove()
    for (const el of next.head.querySelectorAll(styles)) current.head.appendChild(current.importNode(el, true))
  }
  current.title = next.title
}
