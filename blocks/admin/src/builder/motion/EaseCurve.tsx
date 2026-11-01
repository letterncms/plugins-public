/** Approximate cubic-bezier shapes of GSAP eases, for previews. */
const BEZIER: Record<string, [number, number, number, number]> = {
  none: [0, 0, 1, 1], linear: [0, 0, 1, 1], ease: [0.25, 0.1, 0.25, 1], 'ease-in-out': [0.42, 0, 0.58, 1],
  'power1.out': [0.25, 0.46, 0.45, 0.94], 'power2.out': [0.215, 0.61, 0.355, 1], 'power3.out': [0.165, 0.84, 0.44, 1], 'power4.out': [0.23, 1, 0.32, 1],
  'power1.in': [0.55, 0.085, 0.68, 0.53], 'power2.in': [0.55, 0.055, 0.675, 0.19], 'power3.in': [0.895, 0.03, 0.685, 0.22], 'power4.in': [0.755, 0.05, 0.855, 0.06],
  'power1.inOut': [0.455, 0.03, 0.515, 0.955], 'power2.inOut': [0.645, 0.045, 0.355, 1], 'power3.inOut': [0.77, 0, 0.175, 1], 'power4.inOut': [0.86, 0, 0.07, 1],
  'expo.out': [0.19, 1, 0.22, 1], 'expo.in': [0.95, 0.05, 0.795, 0.035], 'expo.inOut': [1, 0, 0, 1], 'circ.out': [0.075, 0.82, 0.165, 1], 'circ.inOut': [0.785, 0.135, 0.15, 0.86],
  'sine.out': [0.39, 0.575, 0.565, 1], 'sine.inOut': [0.445, 0.05, 0.55, 0.95], 'back.out': [0.175, 0.885, 0.32, 1.275], 'back.inOut': [0.68, -0.55, 0.265, 1.55], 'back.in': [0.6, -0.28, 0.735, 0.045],
  'elastic.out': [0.2, 1.6, 0.4, 0.9], 'bounce.out': [0.3, 1.3, 0.5, 1],
}

export function bezierOf(ease: string | undefined): [number, number, number, number] {
  if (!ease) return BEZIER['power3.out']
  const m = ease.match(/cubic-bezier\(([^)]+)\)/)
  if (m) {
    const n = m[1].split(',').map(Number)
    if (n.length === 4 && n.every((x) => !Number.isNaN(x))) return n as [number, number, number, number]
  }
  return BEZIER[ease.replace(/\(.*\)$/, '')] ?? BEZIER[`${ease}.out`] ?? BEZIER['power3.out']
}

export function EaseCurve({ value, size = 32, className }: { value?: string; size?: number; className?: string }) {
  const [x1, y1, x2, y2] = bezierOf(value)
  const p = (x: number, y: number) => `${4 + x * (size - 8)},${size - 4 - y * (size - 8)}`
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className={className ?? 'shrink-0 rounded-xs bg-sunken text-focus'} aria-hidden>
      <path d={`M${p(0, 0)} C${p(x1, y1)} ${p(x2, y2)} ${p(1, 1)}`} fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
  )
}
