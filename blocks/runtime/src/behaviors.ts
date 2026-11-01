// Behaviors: ready-made interactions that set custom states (is-scrolled, is-current…)
// which the Design panel styles. Each reads its options from the directive's JSON.
import type { DirectiveCallback } from 'alpinejs'

const options = (expression: string) => {
  try {
    return JSON.parse(expression || '{}')
  } catch {
    return {}
  }
}

/** is-scrolled once the page scrolled past a threshold; is-hidden while scrolling down (optional). */
export const scroll: DirectiveCallback = (el, { expression }, { cleanup }) => {
  const { threshold = 20, hide = false } = options(expression)
  let last = window.scrollY
  let frame = 0
  const update = () => {
    frame = 0
    const y = window.scrollY
    el.classList.toggle('is-scrolled', y > threshold)
    if (hide) el.classList.toggle('is-hidden', y > last && y > threshold + el.offsetHeight && !el.classList.contains('is-open'))
    last = y
  }
  const onScroll = () => (frame ||= requestAnimationFrame(update))
  update()
  window.addEventListener('scroll', onScroll, { passive: true })
  cleanup(() => window.removeEventListener('scroll', onScroll))
}

/** In-page links get is-current (and aria-current) while their section is in view. */
export const spy: DirectiveCallback = (el, { expression }, { cleanup }) => {
  const { offset = 96 } = options(expression)
  const links = [...el.querySelectorAll<HTMLAnchorElement>('a[href^="#"]')]
  const targets = links.map((a) => document.getElementById(decodeURIComponent(a.hash.slice(1)))).filter((t): t is HTMLElement => !!t)
  if (!targets.length) return
  const visible = new Set<Element>()
  const mark = () => {
    const current = targets.find((t) => visible.has(t)) ?? null
    links.forEach((a) => {
      const on = !!current && a.hash.slice(1) === current.id
      a.classList.toggle('is-current', on)
      on ? a.setAttribute('aria-current', 'location') : a.removeAttribute('aria-current')
    })
  }
  const io = new IntersectionObserver((entries) => {
    entries.forEach((e) => (e.isIntersecting ? visible.add(e.target) : visible.delete(e.target)))
    mark()
  }, { rootMargin: `-${offset}px 0px -55% 0px` })
  targets.forEach((t) => io.observe(t))
  cleanup(() => io.disconnect())
}

/** An endless horizontal loop of the element's children. */
export const marquee: DirectiveCallback = (el, { expression }, { cleanup }) => {
  const { speed = 60, reverse = false, pause = false } = options(expression)
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return
  const track = document.createElement('div')
  track.style.cssText = 'display:flex;width:max-content;gap:inherit;will-change:transform'
  while (el.firstChild) track.appendChild(el.firstChild)
  const copy = track.cloneNode(true) as HTMLElement
  copy.setAttribute('aria-hidden', 'true')
  const outer = document.createElement('div')
  outer.style.cssText = 'display:flex;width:max-content;gap:inherit'
  outer.append(track, copy)
  el.style.overflow = 'hidden'
  el.appendChild(outer)
  const distance = track.getBoundingClientRect().width + parseFloat(getComputedStyle(el).columnGap || '0')
  const animation = outer.animate([{ transform: 'translateX(0)' }, { transform: `translateX(-${distance}px)` }], { duration: (distance / speed) * 1000, iterations: Infinity, direction: reverse ? 'reverse' : 'normal' })
  if (pause) {
    el.addEventListener('mouseenter', () => animation.pause())
    el.addEventListener('mouseleave', () => animation.play())
  }
  cleanup(() => animation.cancel())
}

/**
 * A row people can step through: the first child scrolls sideways (give it overflow and scroll snap), and the
 * first two buttons after it go back and forward by one item. A button is disabled at its end of the row.
 */
export const carousel: DirectiveCallback = (el, _, { cleanup }) => {
  const row = el.firstElementChild as HTMLElement | null
  if (!row) return
  const [back, next] = [...el.querySelectorAll<HTMLButtonElement>(':scope > :not(:first-child) button:not([data-carousel-to]), :scope > button:not([data-carousel-to])')]
  const thumbs = [...el.querySelectorAll<HTMLElement>('[data-carousel-to]')]
  // A row of pictures has nothing to focus, and keyboards only scroll what takes focus.
  if (!row.querySelector('a[href],button,input,select,textarea,[tabindex]')) row.tabIndex = 0
  const step = () => {
    const [first, second] = [...row.children] as HTMLElement[]
    return second ? second.offsetLeft - first.offsetLeft : row.clientWidth
  }
  const go = (direction: number) => row.scrollBy({ left: direction * step(), behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
  const update = () => {
    if (back) back.disabled = row.scrollLeft <= 1
    if (next) next.disabled = row.scrollLeft + row.clientWidth >= row.scrollWidth - 1
    const at = Math.round(row.scrollLeft / (step() || 1))
    thumbs.forEach((thumb, i) => thumb.toggleAttribute('data-current', i === at))
  }
  const previous = () => go(-1)
  const forward = () => go(1)
  const jump = (event: Event) => {
    const to = thumbs.indexOf(event.currentTarget as HTMLElement)
    row.scrollTo({ left: to * step(), behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
  }
  back?.addEventListener('click', previous)
  next?.addEventListener('click', forward)
  thumbs.forEach((thumb) => thumb.addEventListener('click', jump))
  row.addEventListener('scroll', update, { passive: true })
  const observer = new ResizeObserver(update)
  observer.observe(row)
  update()
  cleanup(() => {
    back?.removeEventListener('click', previous)
    next?.removeEventListener('click', forward)
    thumbs.forEach((thumb) => thumb.removeEventListener('click', jump))
    row.removeEventListener('scroll', update)
    observer.disconnect()
  })
}

/**
 * A navigation menu with one panel that moves between items: a pill follows the pointer or focus, and the
 * panel's background morphs to fit the next item while its content cross-fades. Each child is an item:
 * a link or button, and optionally a panel as its second element. Styled in blocks.css (`.cms-menu`).
 */
export const menu: DirectiveCallback = (el, _, { cleanup }) => {
  type Item = { item: HTMLElement; trigger: HTMLElement; panel: HTMLElement | null }
  const items: Item[] = []
  for (const item of [...el.children] as HTMLElement[]) {
    const trigger = item.querySelector<HTMLElement>('a,button')
    if (!trigger) continue
    const panel = (item.children[1] as HTMLElement | undefined) ?? null
    items.push({ item, trigger, panel })
    if (panel) {
      panel.id ||= `cms-menu-${Math.random().toString(36).slice(2, 8)}`
      panel.classList.add('cms-menu__content')
      trigger.setAttribute('aria-expanded', 'false')
      trigger.setAttribute('aria-controls', panel.id)
    }
  }
  const pill = document.createElement('div')
  const sheet = document.createElement('div')
  pill.className = 'cms-menu__pill'
  sheet.className = 'cms-menu__sheet'
  pill.setAttribute('aria-hidden', 'true')
  sheet.setAttribute('aria-hidden', 'true')
  el.prepend(pill, sheet)
  el.classList.add('cms-menu')

  let open: Item | null = null
  let closing = 0
  const place = (target: HTMLElement) => {
    const box = el.getBoundingClientRect()
    const rect = target.getBoundingClientRect()
    pill.style.width = `${rect.width}px`
    pill.style.height = `${rect.height}px`
    pill.style.transform = `translate(${rect.left - box.left}px, ${rect.top - box.top}px)`
    pill.classList.add('is-on')
  }
  const reveal = (next: Item | null) => {
    if (next === open) return
    const before = open ? items.indexOf(open) : -1
    const after = next ? items.indexOf(next) : -1
    if (open?.panel) {
      open.panel.classList.remove('is-open')
      open.panel.dataset.leave = after > before ? 'left' : 'right'
      open.trigger.setAttribute('aria-expanded', 'false')
    }
    open = next
    if (!next?.panel) {
      sheet.classList.remove('is-on')
      return
    }
    const box = el.getBoundingClientRect()
    const rect = next.trigger.getBoundingClientRect()
    const { offsetWidth: width, offsetHeight: height } = next.panel
    const left = Math.max(-box.left + 8, Math.min(rect.left - box.left - 8, document.documentElement.clientWidth - box.left - width - 8))
    const top = el.offsetHeight + 6
    const fresh = !sheet.classList.contains('is-on')
    // Opening from nothing, the sheet appears in place; from another item, it travels there.
    if (fresh) sheet.style.transition = 'none'
    sheet.style.width = `${width}px`
    sheet.style.height = `${height}px`
    sheet.style.transform = `translate(${left}px, ${top}px)`
    next.panel.style.setProperty('--x', `${left}px`)
    next.panel.style.setProperty('--y', `${top}px`)
    delete next.panel.dataset.leave
    next.panel.dataset.enter = fresh ? 'up' : before < after ? 'right' : 'left'
    void next.panel.offsetWidth
    if (fresh) {
      void sheet.offsetWidth
      sheet.style.transition = ''
    }
    sheet.classList.add('is-on')
    delete next.panel.dataset.enter
    next.panel.classList.add('is-open')
    next.trigger.setAttribute('aria-expanded', 'true')
  }
  const reset = () => {
    reveal(null)
    pill.classList.remove('is-on')
  }
  const cancel = () => window.clearTimeout(closing)
  const later = () => {
    cancel()
    closing = window.setTimeout(reset, 140)
  }
  const handlers: [EventTarget, string, EventListener][] = []
  const on = (target: EventTarget, name: string, fn: EventListener) => {
    target.addEventListener(name, fn)
    handlers.push([target, name, fn])
  }
  for (const entry of items) {
    on(entry.item, 'pointerenter', (event) => {
      if ((event as PointerEvent).pointerType === 'touch') return
      cancel()
      place(entry.trigger)
      reveal(entry)
    })
    on(entry.trigger, 'focus', () => {
      cancel()
      place(entry.trigger)
      if (open !== entry) reveal(entry.panel ? open && entry : null)
    })
    if (entry.panel) {
      on(entry.trigger, 'click', (event) => {
        if (entry.trigger.tagName === 'A') return
        event.preventDefault()
        place(entry.trigger)
        reveal(open === entry ? null : entry)
      })
      on(entry.trigger, 'keydown', (event) => {
        if ((event as KeyboardEvent).key !== 'ArrowDown') return
        event.preventDefault()
        reveal(entry)
        entry.panel?.querySelector<HTMLElement>('a,button')?.focus()
      })
    }
  }
  on(el, 'pointerleave', (event) => {
    if ((event as PointerEvent).pointerType !== 'touch') later()
  })
  on(el, 'pointerenter', cancel)
  on(el, 'keydown', (event) => {
    if ((event as KeyboardEvent).key !== 'Escape' || !open) return
    const { trigger } = open
    reset()
    trigger.focus()
  })
  on(el, 'focusout', (event) => {
    if (!el.contains((event as FocusEvent).relatedTarget as Node | null)) later()
  })
  on(document, 'pointerdown', (event) => {
    if (!el.contains(event.target as Node)) reset()
  })
  cleanup(() => {
    cancel()
    handlers.forEach(([target, name, fn]) => target.removeEventListener(name, fn))
    pill.remove()
    sheet.remove()
    el.classList.remove('cms-menu')
  })
}
