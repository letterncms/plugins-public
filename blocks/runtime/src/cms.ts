// $cms: the actions people put together in the builder (Interact → Events), run in order.
//   x-on:click="$cms.run($el, $event, '0')"  +  data-cms-on='{"0":[{"do":"toggle","var":"open"}]}'
import Alpine from '@alpinejs/csp'

type Action = { do: string; [key: string]: any }

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

function evaluate(el: HTMLElement, expression: string | undefined, scope: Record<string, unknown> = {}): unknown {
  if (expression === undefined || expression === '') return undefined
  return Alpine.evaluate(el, expression, { scope } as any)
}

function setVar(el: HTMLElement, path: string, value: unknown) {
  const data: any = Alpine.$data(el)
  const keys = path.split('.')
  const last = keys.pop()!
  let target = data
  for (const key of keys) target = target?.[key]
  if (target && typeof target === 'object') target[last] = value
}

function getVar(el: HTMLElement, path: string): any {
  return path.split('.').reduce((v: any, k) => v?.[k], Alpine.$data(el))
}

export function node(id: string | undefined): HTMLElement | null {
  return id ? document.querySelector<HTMLElement>(`[data-cms="${CSS.escape(id)}"]`) : null
}

let locked = 0

async function exec(el: HTMLElement, action: Action, event: Event | null): Promise<void> {
  const scope = { $event: event }
  switch (action.do) {
    case 'set':
      return setVar(el, action.var, evaluate(el, action.value, scope))
    case 'toggle':
      return setVar(el, action.var, !getVar(el, action.var))
    case 'add':
      return setVar(el, action.var, Number(getVar(el, action.var) ?? 0) + Number(evaluate(el, action.value, scope) ?? 1))
    case 'if':
      for (const a of (evaluate(el, action.test, scope) ? action.then : action.else) ?? []) await exec(el, a, event)
      return
    case 'wait':
      return void (await wait(action.ms ?? 300))
    case 'play': {
      const target = action.node ? node(action.node) : el
      if (target) await cms.play(target, action.motion, action.mode)
      return
    }
    case 'scroll': {
      const to = action.to as string
      const offset = Number(action.offset ?? 0)
      const smooth = matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
      if (to === 'top' || to === 'bottom') return window.scrollTo({ top: to === 'top' ? 0 : document.body.scrollHeight, behavior: smooth })
      const target = to.startsWith('#') ? document.getElementById(to.slice(1)) : node(to)
      if (target) window.scrollTo({ top: target.getBoundingClientRect().top + window.scrollY - offset, behavior: smooth })
      return
    }
    case 'copy': {
      const text = action.node ? node(action.node)?.innerText ?? '' : String(evaluate(el, action.value, scope) ?? '')
      try {
        await navigator.clipboard.writeText(text)
        el.dispatchEvent(new CustomEvent('cms:copied', { bubbles: true }))
      } catch {
        // Clipboard blocked: nothing to do.
      }
      return
    }
    case 'go':
      if (action.newTab) window.open(action.url, '_blank', 'noopener')
      else location.href = action.url
      return
    case 'theme':
      return cms.theme(action.mode)
    case 'focus':
      return void (action.node ? node(action.node) : el)?.focus()
    case 'dispatch':
      el.dispatchEvent(new CustomEvent(action.event, { bubbles: true, detail: evaluate(el, action.detail, scope) }))
      return
    case 'fetch': {
      const url = action.url ?? String(evaluate(el, action.urlFrom, scope) ?? '')
      if (!url.startsWith('/')) return
      const targets = (action.target as string[]).map((id) => `cms-${id}`)
      await evaluate(el, '$ajax(url, { targets: targets })', { url, targets })
      return
    }
    case 'lock':
      locked = Math.max(0, locked + (action.on ? 1 : -1))
      document.documentElement.style.overflow = locked ? 'hidden' : ''
      return
    case 'run':
      evaluate(el, action.expr, scope)
      return
  }
}

export const cms = {
  /** Run the actions stored under `key` in the element's data-cms-on. */
  async run(el: HTMLElement, event: Event | null, key: string) {
    let handlers: Record<string, Action[]> = {}
    try {
      handlers = JSON.parse(el.dataset.cmsOn ?? '{}')
    } catch {
      return
    }
    for (const action of handlers[key] ?? []) await exec(el, action, event)
  },

  /** A bound href or src (its expression in data-cms-href / data-cms-src), only with the schemes Elements::url allows. */
  url(el: HTMLElement, attr: 'href' | 'src'): string | null {
    const value = evaluate(el, el.dataset[attr === 'href' ? 'cmsHref' : 'cmsSrc'])
    const url = typeof value === 'string' ? value.trim() : typeof value === 'number' ? String(value) : ''
    return /^(https?:\/\/|\/|#|\?|mailto:|tel:)/i.test(url) ? url : null
  },

  async play(el: HTMLElement, motion?: string, mode = 'play') {
    const m = await import('./motion')
    await m.ready
    m.play(el, motion, mode)
  },

  theme(mode: 'toggle' | 'light' | 'dark' | 'system' = 'toggle') {
    const root = document.documentElement
    const system = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
    const current = root.dataset.theme ?? system
    const next = mode === 'toggle' ? (current === 'dark' ? 'light' : 'dark') : mode === 'system' ? system : mode
    root.dataset.theme = next
    try {
      if (mode === 'system' || next === system) localStorage.removeItem('cms-theme')
      else localStorage.setItem('cms-theme', next)
    } catch {
      // Storage blocked: the choice lasts for this page.
    }
  },

  copy: (text: string) => navigator.clipboard?.writeText(text),
}
