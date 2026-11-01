import { t } from '@letterncms/sdk'
import type { MotionDef, MotionStep } from '../../core/types'

export interface Preset {
  name: string
  group: 'appear' | 'scroll' | 'hover' | 'loop' | 'load' | 'event'
  text?: boolean
  make: () => Omit<MotionDef, 'id'>
}

export const groupLabel = (group: Preset['group']) => ({ appear: t('Appear'), scroll: t('Scroll'), hover: t('Hover and Press'), loop: t('Loop'), load: t('Page Load'), event: t('On Event') })[group]

const ease = 'power3.out'

/** One-click starting points; everything stays editable afterwards. */
export const presets = (): Preset[] => [
  { name: t('Fade In'), group: 'appear', make: () => ({ trigger: { type: 'appear' }, steps: [{ target: 'self', from: { opacity: 0 }, duration: 0.8, ease }] }) },
  { name: t('Fade Up'), group: 'appear', make: () => ({ trigger: { type: 'appear' }, steps: [{ target: 'self', from: { opacity: 0, y: 32 }, duration: 0.9, ease }] }) },
  { name: t('Slide in from Left'), group: 'appear', make: () => ({ trigger: { type: 'appear' }, steps: [{ target: 'self', from: { opacity: 0, x: -48 }, duration: 0.9, ease }] }) },
  { name: t('Scale In'), group: 'appear', make: () => ({ trigger: { type: 'appear' }, steps: [{ target: 'self', from: { opacity: 0, scale: 0.92 }, duration: 0.8, ease: 'expo.out' }] }) },
  { name: t('Blur In'), group: 'appear', make: () => ({ trigger: { type: 'appear' }, steps: [{ target: 'self', from: { opacity: 0, blur: 16, y: 12 }, duration: 1, ease }] }) },
  { name: t('Reveal (Wipe Up)'), group: 'appear', make: () => ({ trigger: { type: 'appear' }, steps: [{ target: 'self', from: { clipPath: 'inset(100% 0% 0% 0%)' }, to: { clipPath: 'inset(0% 0% 0% 0%)' }, duration: 1.1, ease: 'power4.inOut' }] }) },
  { name: t('Children one by one'), group: 'appear', make: () => ({ trigger: { type: 'appear' }, steps: [{ target: 'children', from: { opacity: 0, y: 24 }, duration: 0.7, ease, stagger: 0.08 }] }) },
  { name: t('Words Rise'), group: 'appear', text: true, make: () => ({ trigger: { type: 'appear' }, steps: [{ target: 'words', from: { opacity: 0, y: 20 }, duration: 0.7, ease, stagger: 0.04 }] }) },
  { name: t('Words Slide Up (Masked)'), group: 'appear', text: true, make: () => ({ trigger: { type: 'appear' }, steps: [{ target: 'words', mask: true, from: { yPercent: 110 }, duration: 0.9, ease: 'expo.out', stagger: 0.04 }] }) },

  { name: t('Parallax'), group: 'scroll', make: () => ({ trigger: { type: 'scroll', start: 'top bottom', end: 'bottom top' }, steps: [{ target: 'self', from: { yPercent: -12 }, to: { yPercent: 12 }, ease: 'none', duration: 1 }] }) },
  { name: t('Zoom While Scrolling'), group: 'scroll', make: () => ({ trigger: { type: 'scroll', start: 'top bottom', end: 'center center' }, steps: [{ target: 'self', from: { scale: 0.85, borderRadius: '32px' }, to: { scale: 1, borderRadius: '0px' }, ease: 'none', duration: 1 }] }) },
  { name: t('Fade Through'), group: 'scroll', make: () => ({ trigger: { type: 'scroll', start: 'top 90%', end: 'top 40%' }, steps: [{ target: 'self', from: { opacity: 0, y: 60 }, to: { opacity: 1, y: 0 }, ease: 'none', duration: 1 }] }) },
  { name: t('Highlight Words'), group: 'scroll', text: true, make: () => ({ trigger: { type: 'scroll', start: 'top 80%', end: 'bottom 50%' }, steps: [{ target: 'words', from: { opacity: 0.15 }, to: { opacity: 1 }, ease: 'none', duration: 1, stagger: 0.1 }] }) },
  { name: t('Rotate While Scrolling'), group: 'scroll', make: () => ({ trigger: { type: 'scroll' }, steps: [{ target: 'self', from: { rotate: -8 }, to: { rotate: 8 }, ease: 'none', duration: 1 }] }) },
  { name: t('Slide Sideways'), group: 'scroll', make: () => ({ trigger: { type: 'scroll' }, steps: [{ target: 'self', from: { xPercent: 10 }, to: { xPercent: -10 }, ease: 'none', duration: 1 }] }) },

  { name: t('Lift'), group: 'hover', make: () => ({ trigger: { type: 'hover' }, steps: [{ target: 'self', to: { y: -6 }, duration: 0.3, ease }] }) },
  { name: t('Grow'), group: 'hover', make: () => ({ trigger: { type: 'hover' }, steps: [{ target: 'self', to: { scale: 1.04 }, duration: 0.35, ease }] }) },
  { name: t('Zoom Image Inside'), group: 'hover', make: () => ({ trigger: { type: 'hover' }, steps: [{ target: 'children', to: { scale: 1.06 }, duration: 0.6, ease }] }) },
  { name: t('Press'), group: 'hover', make: () => ({ trigger: { type: 'press' }, steps: [{ target: 'self', to: { scale: 0.96 }, duration: 0.15, ease: 'power2.out' }] }) },

  { name: t('Float'), group: 'loop', make: () => ({ trigger: { type: 'loop' }, repeat: -1, yoyo: true, steps: [{ target: 'self', to: { y: -10 }, duration: 2.2, ease: 'sine.inOut' }] }) },
  { name: t('Spin'), group: 'loop', make: () => ({ trigger: { type: 'loop' }, repeat: -1, steps: [{ target: 'self', to: { rotate: 360 }, duration: 12, ease: 'none' }] }) },
  { name: t('Pulse'), group: 'loop', make: () => ({ trigger: { type: 'loop' }, repeat: -1, yoyo: true, steps: [{ target: 'self', to: { scale: 1.06 }, duration: 1.2, ease: 'sine.inOut' }] }) },

  { name: t('Intro: Fade Up'), group: 'load', make: () => ({ trigger: { type: 'load' }, steps: [{ target: 'self', from: { opacity: 0, y: 24 }, duration: 1, ease, delay: 0.1 }] }) },
  { name: t('Intro: Words'), group: 'load', text: true, make: () => ({ trigger: { type: 'load' }, steps: [{ target: 'words', from: { opacity: 0, y: 40 }, duration: 0.9, ease, stagger: 0.05 }] }) },
  { name: t('Intro: Children'), group: 'load', make: () => ({ trigger: { type: 'load' }, steps: [{ target: 'children', from: { opacity: 0, y: 16 }, duration: 0.8, ease, stagger: 0.1 }] }) },

  { name: t('Open (Fade and Scale)'), group: 'event', make: () => ({ trigger: { type: 'event' }, steps: [{ target: 'self', from: { opacity: 0, scale: 0.96 }, duration: 0.3, ease }] }) },
  { name: t('Slide in Panel'), group: 'event', make: () => ({ trigger: { type: 'event' }, steps: [{ target: 'self', from: { xPercent: 100 }, duration: 0.5, ease: 'expo.out' }, { target: 'children', from: { opacity: 0, x: 24 }, duration: 0.4, ease, stagger: 0.05 }] as MotionStep[] }) },
]

export const triggers = (): Record<string, { label: string; icon: string; help: string }> => ({
  appear: { label: t('Appear'), icon: 'b-eye', help: t('Plays once as it scrolls into view.') },
  scroll: { label: t('Scroll'), icon: 'b-mouse-scroll', help: t('Follows the scroll between a start and an end point.') },
  hover: { label: t('Hover'), icon: 'b-hand-pointing', help: t('Plays on hover and focus, reverses after.') },
  press: { label: t('Press'), icon: 'b-hand-tap', help: t('Plays while pressed.') },
  load: { label: t('Page Load'), icon: 'b-rocket-launch', help: t('Plays as the page paints, with no script. Delay stops at 0.2s and stagger at 0.3s.') },
  loop: { label: t('Loop'), icon: 'b-arrows-clockwise', help: t('Stops within 5 seconds, unless it repeats forever. Then the page needs a Motion Toggle block.') },
  event: { label: t('On Event'), icon: 'b-broadcast', help: t('Plays from a “Play animation” action in Events.') },
})

export const propertyLabels = (): Record<string, string> => ({
  opacity: t('Opacity'), x: t('Move X'), y: t('Move Y'), xPercent: t('Move X %'), yPercent: t('Move Y %'), z: t('Move Z'), scale: t('Scale'), scaleX: t('Scale X'), scaleY: t('Scale Y'),
  rotate: t('Rotate'), rotateX: t('Rotate X (3D)'), rotateY: t('Rotate Y (3D)'), skewX: t('Skew X'), skewY: t('Skew Y'), blur: t('Blur'), brightness: t('Brightness'), grayscale: t('Grayscale'),
  clipPath: t('Clip Path'), backgroundColor: t('Fill'), color: t('Text Color'), borderColor: t('Border Color'), borderRadius: t('Radius'), boxShadow: t('Shadow'),
  letterSpacing: t('Letter Spacing'), transformOrigin: t('Origin'), perspective: t('Perspective'),
})

export const eases = () => [
  { value: 'power3.out', label: t('Smooth Out') }, { value: 'expo.out', label: t('Expo Out') }, { value: 'power4.inOut', label: t('Smooth In-Out') }, { value: 'power2.out', label: t('Gentle Out') },
  { value: 'back.out(1.7)', label: t('Overshoot') }, { value: 'elastic.out(1, 0.4)', label: t('Elastic') }, { value: 'bounce.out', label: t('Bounce') }, { value: 'sine.inOut', label: t('Sine In-Out') },
  { value: 'power2.in', label: t('Ease In') }, { value: 'none', label: t('Linear') }, { value: 'cubic-bezier(0.2, 0.8, 0.2, 1)', label: t('Signature Ease') },
]
