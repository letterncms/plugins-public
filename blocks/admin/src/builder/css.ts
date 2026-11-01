/** Split a CSS value on top-level spaces (not inside parentheses): "0 token:space.4 calc(1px + 2px)". */
export function splitValue(value: string): string[] {
  const out: string[] = []
  let depth = 0
  let current = ''
  for (const ch of value.trim()) {
    if (ch === '(') depth++
    if (ch === ')') depth--
    if (/\s/.test(ch) && depth === 0) {
      if (current) out.push(current)
      current = ''
    } else current += ch
  }
  if (current) out.push(current)
  return out
}

/** Split on top-level commas (multiple shadows, gradient stops). */
export function splitList(value: string): string[] {
  const out: string[] = []
  let depth = 0
  let current = ''
  for (const ch of value) {
    if (ch === '(') depth++
    if (ch === ')') depth--
    if (ch === ',' && depth === 0) {
      out.push(current.trim())
      current = ''
    } else current += ch
  }
  if (current.trim()) out.push(current.trim())
  return out
}

/** Four sides from a shorthand value ("1px 2px" → [1px, 2px, 1px, 2px]). */
export function sides(value: string | number | undefined): [string, string, string, string] | null {
  if (value === undefined || value === '') return null
  const p = splitValue(String(value))
  if (p.length === 1) return [p[0], p[0], p[0], p[0]]
  if (p.length === 2) return [p[0], p[1], p[0], p[1]]
  if (p.length === 3) return [p[0], p[1], p[2], p[1]]
  return [p[0], p[1], p[2], p[3]]
}
