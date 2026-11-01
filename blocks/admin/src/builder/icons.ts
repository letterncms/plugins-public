import { registry } from '@letterncms/sdk'
import { BUILDER_ICONS } from './icon-data'

/** Border side pickers: a faint dashed box with the chosen side drawn solid. */
const box = '<rect x="44" y="44" width="168" height="168" rx="20" fill="none" stroke="currentColor" stroke-width="14" stroke-dasharray="18 18" opacity=".4"/>'
const SIDES: Record<string, string> = {
  'b-side-all': '<rect x="44" y="44" width="168" height="168" rx="20" fill="none" stroke="currentColor" stroke-width="18"/>',
  'b-side-top': box + '<path d="M44 44h168" stroke="currentColor" stroke-width="22" stroke-linecap="round"/>',
  'b-side-right': box + '<path d="M212 44v168" stroke="currentColor" stroke-width="22" stroke-linecap="round"/>',
  'b-side-bottom': box + '<path d="M44 212h168" stroke="currentColor" stroke-width="22" stroke-linecap="round"/>',
  'b-side-left': box + '<path d="M44 44v168" stroke="currentColor" stroke-width="22" stroke-linecap="round"/>',
}

/** The builder's icons ("b-<phosphor name>") join the admin icon registry. */
export function registerBuilderIcons() {
  for (const [name, body] of Object.entries({ ...BUILDER_ICONS, ...SIDES })) if (!registry.icons.get(name)) registry.icons.set(name, body)
}
