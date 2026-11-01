// Animations from the builder that wait for something: scrolling into view, hover, press or a
// "Play animation" action. PHP compiles them (Motion::compile) to what element.animate() takes;
// load, scroll and loop animations are plain CSS and never get here.
//   <h1 data-cms="b1" data-cms-motion='[{"id":"m1","type":"appear","steps":[{"t":"self","k":[{"opacity":0,"offset":0}],"d":800}]}]'>

interface Step {
  t: string // self, children, words or #<node id>
  k: Keyframe[]
  d: number // duration
  w?: number // start
  r?: number // time after it ends, so all steps of an animation end together and reverse together
  e?: string
  s?: number // stagger
  f: FillMode
  i?: number // iterations, -1 = forever
  y?: 1 // back and forth
  h?: 1 // it has an end state of its own, to keep after playing
  c?: 1 // part of a looping timeline: null in a keyframe means the element's own style
}

export interface Animation {
  id: string
  type: string
  start?: string
  once?: boolean
  node?: string
  media?: string
  cycle?: boolean // the steps loop as one timeline, for as long as it is on screen
  steps: Step[]
}

interface Built {
  animation: Animation
  el: HTMLElement
  running: globalThis.Animation[]
  stop: () => void
}

/** Words beyond this spread start together, so a long paragraph doesn't take seconds to appear. */
const SPREAD = 800

const registry = new WeakMap<Element, Map<string, Built>>()
const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches
let visitorPaused = false
let resolveReady: () => void
export const ready = new Promise<void>((resolve) => (resolveReady = resolve))

declare global {
  interface Window {
    cmsMotion?: { play: typeof play; pauseAll: typeof pauseAll; resumeAll: typeof resumeAll; ready: Promise<void> }
  }
}

function targets(el: HTMLElement, target: string): Element[] {
  if (target === 'self') return [el]
  if (target === 'children') return [...el.children]
  if (target === 'words') return [...el.querySelectorAll('.cms-w__i')]
  return [...document.querySelectorAll(`[data-cms="${CSS.escape(target.slice(1))}"]`)]
}

/** A timeline's keyframes with null filled in from the element's own style, read before it starts animating. */
function own(item: Element, frames: Keyframe[]): Keyframe[] {
  const style = getComputedStyle(item) as unknown as Record<string, string>
  return frames.map((frame) => Object.fromEntries(Object.entries(frame).map(([key, value]) => [key, value === null ? style[key] : value])) as Keyframe)
}

/** The animations of a step, created paused. */
function create(el: HTMLElement, animation: Animation): globalThis.Animation[] {
  const out: globalThis.Animation[] = []
  for (const step of animation.steps) {
    const items = targets(el, step.t)
    const stagger = Math.min(step.s ?? 0, items.length > 1 ? SPREAD / (items.length - 1) : 0)
    items.forEach((item, i) => {
      const a = item.animate(step.c ? own(item, step.k) : step.k, {
        duration: step.d,
        delay: (step.w ?? 0) + i * stagger,
        endDelay: (step.r ?? 0) + (items.length - 1 - i) * stagger,
        easing: step.e ?? 'linear',
        fill: step.f,
        iterations: step.i === -1 ? Infinity : step.i ?? 1,
        direction: step.y ? 'alternate' : 'normal',
      })
      a.pause()
      out.push(a)
    })
  }
  return out
}

/** Where the start line sits: `top 85%` plays once the element's top passes 85% down the screen. */
function observe(target: Element, position: string, enter: () => void, leave: () => void): () => void {
  const [edge, view] = position.split(' ')
  const line = view === 'top' ? 0 : view === 'center' ? 50 : view === 'bottom' ? 100 : parseFloat(view)
  const tall = target.getBoundingClientRect().height > innerHeight * (1 - line / 100)
  const threshold = tall || edge === 'top' ? 0 : edge === 'center' ? 0.5 : 1
  const io = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) entry.isIntersecting ? enter() : entry.boundingClientRect.top > 0 && leave()
    },
    { rootMargin: `0px 0px -${100 - line}% 0px`, threshold },
  )
  io.observe(target)
  return () => io.disconnect()
}

/** After a one-way play the element goes back to its own styles, so hover and transitions work again. */
function release(built: Built) {
  if (built.animation.steps.some((s) => s.h)) return
  built.running.forEach((a) => a.finished.then(() => a.cancel()).catch(() => {}))
}

function forwards(built: Built) {
  if (!built.running.length) built.running = create(built.el, built.animation)
  built.running.forEach((a) => {
    a.playbackRate = 1
    a.play()
  })
}

function backwards(built: Built, clean: boolean) {
  built.running.forEach((a) => {
    a.playbackRate = -1
    a.play()
    if (clean) a.finished.then(() => a.playbackRate < 0 && a.cancel()).catch(() => {})
  })
}

/** Plays while on screen and rests while it isn't, unless the visitor has paused it. */
function cycle(built: Built): () => void {
  const { el, animation } = built
  const watched = (animation.node && document.querySelector(`[data-cms="${CSS.escape(animation.node)}"]`)) || el
  built.running = create(el, animation)
  const io = new IntersectionObserver(
    ([entry]) => {
      if (!entry.isIntersecting) built.running.forEach((a) => a.pause())
      else if (!visitorPaused) built.running.forEach((a) => a.play())
    },
    { threshold: 0.3 },
  )
  io.observe(watched)
  return () => io.disconnect()
}

function appear(built: Built): () => void {
  const { el, animation } = built
  if (animation.cycle) return cycle(built)
  const watched = (animation.node && document.querySelector(`[data-cms="${CSS.escape(animation.node)}"]`)) || el
  const once = animation.once ?? true
  // Already on screen: it has been painted, and hiding it now would only flash and delay the page.
  if (watched.getBoundingClientRect().top < innerHeight) return () => {}
  built.running = create(el, animation)
  const stop = observe(
    watched,
    animation.start ?? 'top 85%',
    () => {
      forwards(built)
      if (once) {
        stop()
        release(built)
      }
    },
    () => !once && backwards(built, false),
  )
  return stop
}

function hover(built: Built): () => void {
  const target = (built.animation.node && document.querySelector(`[data-cms="${CSS.escape(built.animation.node)}"]`)) || built.el
  const on = (event: Event) => {
    if ((event as PointerEvent).pointerType !== 'touch') play(built.el, built.animation.id)
  }
  const off = (event: Event) => {
    if ((event as PointerEvent).pointerType !== 'touch') play(built.el, built.animation.id, 'reverse', true)
  }
  const events: [string, EventListener][] = [['pointerenter', on], ['pointerleave', off], ['focusin', on], ['focusout', off]]
  events.forEach(([name, fn]) => target.addEventListener(name, fn))
  return () => events.forEach(([name, fn]) => target.removeEventListener(name, fn))
}

function press(built: Built): () => void {
  const target = built.el
  const on = () => play(built.el, built.animation.id)
  const off = () => play(built.el, built.animation.id, 'reverse', true)
  const leaves = ['pointerup', 'pointerleave', 'pointercancel']
  target.addEventListener('pointerdown', on)
  leaves.forEach((name) => target.addEventListener(name, off))
  return () => {
    target.removeEventListener('pointerdown', on)
    leaves.forEach((name) => target.removeEventListener(name, off))
  }
}

const begin = (built: Built) => {
  const type = built.animation.type
  if (reduced() && type === 'appear') return
  built.stop = type === 'appear' ? appear(built) : type === 'hover' ? hover(built) : type === 'press' ? press(built) : () => {}
}

const end = (built: Built) => {
  built.stop()
  built.stop = () => {}
  built.running.forEach((a) => a.cancel())
  built.running = []
}

function setup(el: HTMLElement) {
  let animations: Animation[] = []
  try {
    animations = JSON.parse(el.dataset.cmsMotion ?? '[]')
  } catch {
    return
  }
  const built = new Map<string, Built>()
  for (const animation of animations) {
    const entry: Built = { animation, el, running: [], stop: () => {} }
    built.set(animation.id, entry)
    if (!animation.media) {
      begin(entry)
      continue
    }
    const query = matchMedia(animation.media)
    if (query.matches) begin(entry)
    query.addEventListener('change', () => (query.matches ? begin(entry) : end(entry)))
  }
  registry.set(el, built)
}

/** Play an animation of an element (from a "play" action, hover or press). */
export function play(el: Element, id?: string, mode = 'play', clean = false) {
  const animations = registry.get(el)
  const built = id ? animations?.get(id) : animations?.values().next().value
  if (!built || (built.animation.media && !matchMedia(built.animation.media).matches)) return
  if (reduced()) {
    if (mode === 'reverse') built.running.splice(0).forEach((a) => a.cancel())
    else {
      forwards(built)
      built.running.forEach((a) => a.finish())
    }
    return
  }
  const playing = built.running.some((a) => a.playState !== 'idle' && a.playbackRate > 0)
  if (mode === 'restart') {
    built.running.splice(0).forEach((a) => a.cancel())
    forwards(built)
  } else if (mode === 'reverse' || (mode === 'toggle' && playing)) backwards(built, clean)
  else forwards(built)
}

const looping = () => document.getAnimations().filter((a) => a.effect?.getComputedTiming().iterations === Infinity)

/** Everything that would run forever, CSS animations and marquees included. */
export function pauseAll() {
  visitorPaused = true
  looping().forEach((a) => a.pause())
}

export function resumeAll() {
  visitorPaused = false
  looping().forEach((a) => a.play())
}

/** A visitor's way to stop what moves by itself (WCAG 2.2.2). Stays hidden when nothing runs or nothing should. */
function toggles() {
  const key = 'cms-motion-paused'
  const stored = () => {
    try {
      return localStorage.getItem(key) === '1'
    } catch {
      return false
    }
  }
  document.querySelectorAll<HTMLButtonElement>('[data-cms-motion-toggle]').forEach((button) => {
    if (reduced()) return
    const set = (paused: boolean) => {
      paused ? pauseAll() : resumeAll()
      button.setAttribute('aria-pressed', String(paused))
      button.textContent = (paused ? button.dataset.playLabel : button.dataset.pauseLabel) ?? ''
    }
    button.hidden = false
    set(stored())
    button.addEventListener('click', () => {
      const paused = button.getAttribute('aria-pressed') !== 'true'
      set(paused)
      try {
        paused ? localStorage.setItem(key, '1') : localStorage.removeItem(key)
      } catch {
        // Storage blocked: the choice lasts for this page.
      }
    })
  })
}

export function start() {
  window.cmsMotion = { play, pauseAll, resumeAll, ready }
  document.querySelectorAll<HTMLElement>('[data-cms-motion]').forEach(setup)
  toggles()
  // Print everything that hasn't scrolled into view.
  addEventListener('beforeprint', () => document.getAnimations().forEach((a) => a.playState === 'paused' && a.finish()))
  resolveReady()
}
