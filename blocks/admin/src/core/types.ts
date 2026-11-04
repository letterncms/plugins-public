import type { FieldDef } from '@letterncms/sdk'

/** Variant ("base", "tablet", "hover", "mobile:hover", "is-open"…) → CSS properties in camelCase. */
export type StyleMap = Record<string, Record<string, string | number>>

export interface LogicAction {
  do: string
  [key: string]: any
}

export interface LogicMap {
  data?: Record<string, unknown>
  /** An Alpine component registered in custom.js, used instead of data. */
  component?: string
  init?: LogicAction[]
  on?: { event: string; mods?: string[]; key?: string; actions: LogicAction[] }[]
  show?: string
  transition?: string
  states?: Record<string, string>
  text?: string
  attrs?: Record<string, string>
  model?: string
  target?: string[]
  push?: boolean
  merge?: string
  behaviors?: { scroll?: { threshold?: number; hide?: boolean }; spy?: { offset?: number }; group?: boolean; menu?: Record<string, never>; carousel?: Record<string, never>; marquee?: { speed?: number; reverse?: boolean; pause?: boolean } }
}

export interface MotionStep {
  target?: string
  mask?: boolean
  from?: Record<string, number | string>
  to?: Record<string, number | string>
  duration?: number
  delay?: number
  ease?: string
  stagger?: number
  at?: number
}

export interface MotionDef {
  id: string
  name?: string
  trigger: { type: string; start?: string; end?: string; once?: boolean; node?: string }
  on?: string[]
  steps: MotionStep[]
  repeat?: number
  yoyo?: boolean
  hold?: number
}

export interface BlockNode {
  id: string
  type: string
  props: Record<string, any>
  style?: StyleMap
  children?: BlockNode[]
  anchor?: string
  class?: string
  label?: string
  hidden?: boolean
  attrs?: Record<string, string>
  bind?: Record<string, string>
  logic?: LogicMap
  motion?: MotionDef[]
}

export interface BlockTypeDef {
  name: string
  title: string
  icon: string
  category: string
  description?: string
  keywords?: string[]
  fields?: FieldDef[]
  children?: boolean | string[]
  styles?: string[]
  inline?: string[]
  defaults?: Record<string, any>
  template?: Partial<BlockNode>[]
  parent?: string
  variants?: string[]
  example?: Record<string, any>
  package?: string
  html?: string
}

export interface DataSourceDef {
  name: string
  label: string
  description?: string
  fields: FieldDef[]
  item: Record<string, string>
}

export interface Catalog {
  blocks: BlockTypeDef[]
  /** The inserter's groups, in order and in the user's language. */
  categories?: { name: string; label: string }[]
  styles: Record<string, { property: string; group: string }>
  aliases: Record<string, string>
  states: string[]
  breakpoints: Record<string, string>
  sources: DataSourceDef[]
  motion: { triggers: string[]; properties: Record<string, 'number' | 'length' | 'css'> }
  logic: { events: string[]; mods: string[]; actions: string[]; transitions: string[]; merges: string[] }
}

/** Where the caret should land after an edit. */
export interface FocusRequest {
  id: string
  prop?: string
  index?: number
  at: 'start' | 'end' | number
  nonce: number
}

export const TEXT_TYPES = ['paragraph', 'heading', 'quote', 'list', 'callout']

export function newId(): string {
  return 'b' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-4)
}
