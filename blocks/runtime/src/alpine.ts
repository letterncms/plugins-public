// Scripts built in the builder run on Alpine.js — the CSP build, which evaluates expressions
// without eval() and without access to globals. Actions run through the $cms magic.
import Alpine from '@alpinejs/csp'
import intersect from '@alpinejs/intersect'
import collapse from '@alpinejs/collapse'
import focus from '@alpinejs/focus'
import ajax from '@imacrayon/alpine-ajax'
import { cms } from './cms'
import { carousel, marquee, menu, scroll, spy } from './behaviors'

declare global {
  interface Window {
    Alpine: typeof Alpine
  }
}

export function start() {
  Alpine.plugin(intersect)
  Alpine.plugin(collapse)
  Alpine.plugin(focus)
  Alpine.plugin(ajax)
  Alpine.magic('cms', () => cms)
  Alpine.directive('cms-scroll', scroll)
  Alpine.directive('cms-spy', spy)
  Alpine.directive('cms-marquee', marquee)
  Alpine.directive('cms-menu', menu)
  Alpine.directive('cms-carousel', carousel)
  window.Alpine = Alpine
  // custom.js registers components on this event.
  document.dispatchEvent(new CustomEvent('cms:alpine', { detail: Alpine }))
  Alpine.start()
}
