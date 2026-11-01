import { blockNavigation, can, queryClient, run, t, useAbility, useTab, type FieldDef } from '@letterncms/sdk'
import { Button, EmptyState, Fields, Icon, IconButton, Menu, Segmented, Spinner, Tooltip, Topbar, cn, confirm, prompt, toast, useShortcut } from '@letterncms/ui'
import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react'

interface Token {
  path: string
  group: string
  name: string
  value: any
  dark: any
  type?: string
  description?: string
}

/** A token being edited; `from` is its saved path (absent for new tokens). */
interface DraftToken extends Token {
  from?: string
}

type Declarations = Record<string, string | number>
type Buttons = Record<string, Record<string, Declarations>>
type Font = { family: string; src: string; weight?: string; style?: string; preload?: boolean }

interface ThemeInfo {
  tokens: Token[]
  breakpoints: Record<string, string>
  fonts: Font[]
  styles: Record<string, Declarations>
  prose: Record<string, Declarations>
  buttons: Buttons
  transition: { type: string; duration: number }
  elements: string[]
  css: string
  js: string
}

/** Everything the Save button writes: tokens, the style sections and custom code. */
interface Draft {
  tokens: DraftToken[]
  styles: Record<string, Declarations>
  prose: Record<string, Declarations>
  buttons: Buttons
  transition: { type: string; duration: number }
  css: string
  js: string
}

const SECTIONS = ['color', 'type', 'space', 'shape', 'motion', 'fonts', 'elements', 'buttons', 'code'] as const
type SectionKey = (typeof SECTIONS)[number]

function sections(): { group: string; items: { value: SectionKey; label: string; icon: string }[] }[] {
  return [
    {
      group: t('Tokens'),
      items: [
        { value: 'color', label: t('Color'), icon: 'palette' },
        { value: 'type', label: t('Typography'), icon: 'typography' },
        { value: 'space', label: t('Spacing'), icon: 'spacing' },
        { value: 'shape', label: t('Shape'), icon: 'radius' },
        { value: 'motion', label: t('Motion'), icon: 'speed' },
      ],
    },
    {
      group: t('Styles'),
      items: [
        { value: 'fonts', label: t('Fonts'), icon: 'text' },
        { value: 'elements', label: t('Elements'), icon: 'paragraph' },
        { value: 'buttons', label: t('Buttons'), icon: 'button' },
        { value: 'code', label: t('Custom Code'), icon: 'code' },
      ],
    },
  ]
}

/** The title of the dialog that adds a token to a group. */
function newTitle(group: string): string {
  const titles: Record<string, string> = {
    color: t('New Color'), font: t('New Font Family'), text: t('New Text Size'), weight: t('New Weight'), leading: t('New Line Height'), tracking: t('New Letter Spacing'),
    space: t('New Spacing'), container: t('New Container'), breakpoint: t('New Breakpoint'), radius: t('New Radius'), shadow: t('New Shadow'), duration: t('New Duration'), ease: t('New Easing'),
  }
  return titles[group] ?? t('New Token')
}

const WIDTHS = { desktop: '100%', tablet: '768px', phone: '390px' } as const
type Viewport = keyof typeof WIDTHS

/** CSS custom property prefixes that differ from the group name — mirrors DesignJson::GROUPS. */
const PREFIX: Record<string, string> = { weight: 'font-weight' }
const variable = (group: string, name: string) => `--${PREFIX[group] ?? group}-${name}`

/** CSS for a token value — mirrors the server's DesignCompiler (token references become var()). */
function css(group: string, value: any): string {
  if (value && typeof value === 'object' && !Array.isArray(value) && 'min' in value) return fluid(String(value.min), String(value.max))
  if (group === 'font' && Array.isArray(value)) return value.map((f: string) => (/\s/.test(f) && !f.includes('"') ? `"${f}"` : f)).join(', ')
  return String(value ?? '').replace(/token:([a-z]+)\.([a-z0-9-]+)(?:\/(\d{1,3}))?/g, (_, g: string, n: string, alpha?: string) => (alpha ? `color-mix(in srgb, var(${variable(g, n)}) ${alpha}%, transparent)` : `var(${variable(g, n)})`))
}

function fluid(min: string, max: string): string {
  const rem = (v: string) => (v.endsWith('rem') ? parseFloat(v) : parseFloat(v) / 16)
  const a = rem(min)
  const b = rem(max)
  const slope = (b - a) / (80 - 22.5)
  const n = (x: number) => String(Math.round(x * 10000) / 10000)
  return `clamp(${n(Math.min(a, b))}rem, ${n(a - slope * 22.5)}rem + ${n(slope * 100)}vw, ${n(Math.max(a, b))}rem)`
}

/** A length in px (fluid values: their desktop size), for bars and samples. */
function px(value: any): number {
  const v = value && typeof value === 'object' && 'max' in value ? value.max : value
  const n = parseFloat(String(v ?? ''))
  if (Number.isNaN(n)) return 0
  return /r?em$/.test(String(v)) ? n * 16 : n
}

const same = (a: unknown, b: unknown) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null)

function initial(data: ThemeInfo): Draft {
  return {
    tokens: data.tokens.map((token) => ({ ...token, from: token.path })),
    styles: data.styles ?? {},
    prose: data.prose ?? {},
    buttons: data.buttons ?? {},
    transition: data.transition ?? { type: 'none', duration: 0.3 },
    css: data.css ?? '',
    js: data.js ?? '',
  }
}

/** Rename a token in the draft and point its references (token values, element and button styles) at the new name. */
function retarget(draft: Draft, from: string, to: string): Draft {
  const pattern = new RegExp(`token:${from.replace(/[.-]/g, '\\$&')}(?![a-z0-9-])`, 'g')
  const swap = <T,>(value: T): T => JSON.parse(JSON.stringify(value ?? null).replace(pattern, `token:${to}`))
  return {
    ...draft,
    styles: swap(draft.styles),
    prose: swap(draft.prose),
    buttons: swap(draft.buttons),
    tokens: draft.tokens.map((token) => (token.path === from ? { ...token, path: to, name: to.split('.')[1] } : { ...token, value: swap(token.value), dark: swap(token.dark) })),
  }
}

/** The design/update input that turns `base` into `draft` (renames keep a token's place and references). */
function changes(base: Draft, draft: Draft) {
  const saved = new Map(base.tokens.map((token) => [token.path, token]))
  const kept = new Set(draft.tokens.map((token) => token.from).filter(Boolean))
  const input: Record<string, any> = { tokens: {}, dark: {}, rename: {}, remove: base.tokens.filter((token) => !kept.has(token.path)).map((token) => token.path) }
  for (const token of draft.tokens) {
    const was = token.from ? saved.get(token.from) : undefined
    if (was && was.path !== token.path) input.rename[was.path] = token.path
    if (!was || !same(was.value, token.value)) input.tokens[token.path] = token.value
    if (!was || !same(was.dark || null, token.dark || null)) input.dark[token.path] = token.dark || null
  }
  for (const key of ['styles', 'prose', 'buttons', 'transition'] as const) {
    if (!same(base[key], draft[key])) input[key] = draft[key]
  }
  return input
}

/**
 * The design system editor: tokens, fonts, element and button styles, motion and
 * custom code, with the real site beside it. Every edit previews live (the server
 * compiles the draft) and is written to content/design on save.
 */
export default function DesignTokens() {
  const theme = useAbility<ThemeInfo>('design/get', {}, { refetchOnWindowFocus: false, retry: false })
  const base = useMemo(() => (theme.data ? initial(theme.data) : null), [theme.data])
  const [draft, setDraft] = useState<Draft | null>(null)
  const [section, setSection] = useTab(SECTIONS)
  const [mode, setMode] = useState<'light' | 'dark'>('light')
  const [viewport, setViewport] = useState<Viewport>('desktop')
  const [busy, setBusy] = useState(false)
  const [compiled, setCompiled] = useState<string | null>(null)
  const frame = useRef<HTMLIFrameElement>(null)

  const current = draft ?? base
  const dirty = !!draft && !!base && !same(draft, base)
  const update = (fn: (d: Draft) => Draft) => base && setDraft((d) => fn(d ?? base))

  useEffect(() => blockNavigation(() => (dirty ? t('Discard unsaved design changes?') : null)), [dirty])
  useShortcut('mod+s', () => void save())

  // Live preview, part 1: the server compiles the whole draft (elements, buttons, transitions, custom CSS).
  const input = useMemo(() => (dirty && base && draft ? { ...changes(base, draft), css: draft.css } : null), [dirty, base, draft])
  useEffect(() => {
    if (!input) return setCompiled(null)
    let current = true
    const timer = setTimeout(() => {
      run<{ css: string }>('design/preview', input).then((r) => current && setCompiled(r.css), () => {})
    }, 150)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [input])

  // Part 2: token values as custom properties, applied instantly while a value is dragged.
  const vars = useMemo(() => (current?.tokens ?? []).map((token) => `${variable(token.group, token.name)}:${css(token.group, mode === 'dark' && token.dark ? token.dark : token.value)}`).join(';'), [current, mode])

  const inject = () => {
    const doc = frame.current?.contentDocument
    if (!doc?.head) return
    const link = doc.querySelector<HTMLLinkElement>('link[rel="stylesheet"][href*="/_cms/site/design-"]')
    let style = doc.getElementById('cms-design-preview') as HTMLStyleElement | null
    if (compiled !== null) {
      if (!style) {
        style = doc.createElement('style')
        style.id = 'cms-design-preview'
        // In the stylesheet's place, so cascade layers keep their order.
        link ? link.after(style) : doc.head.appendChild(style)
      }
      if (style.textContent !== compiled) style.textContent = compiled
      if (link) link.disabled = true
    } else {
      style?.remove()
      if (link) link.disabled = false
    }
    let tokens = doc.getElementById('cms-token-preview') as HTMLStyleElement | null
    if (!tokens) {
      tokens = doc.createElement('style')
      tokens.id = 'cms-token-preview'
      doc.head.appendChild(tokens)
    }
    const text = dirty ? `:root:root:root{${vars}}` : ''
    if (tokens.textContent !== text) tokens.textContent = text
    if (doc.documentElement.dataset.theme !== mode) doc.documentElement.dataset.theme = mode
  }
  useEffect(inject)

  // The site's fonts, so specimens here show them.
  useEffect(() => {
    const style = document.createElement('style')
    style.textContent = (theme.data?.fonts ?? []).map((font) => `@font-face{font-family:"${font.family}";src:url("${/^(https:\/\/|\/)/.test(font.src) ? font.src : `/_cms/fonts/${font.src.split('/').pop()}`}") format("woff2");font-weight:${font.weight ?? '400'};font-style:${font.style ?? 'normal'};font-display:swap}`).join('\n')
    document.head.appendChild(style)
    return () => style.remove()
  }, [theme.data?.fonts])

  const save = async () => {
    if (!dirty || busy || !base || !draft) return
    setBusy(true)
    try {
      const change = changes(base, draft)
      const tokensChanged = Object.keys(change.tokens).length + Object.keys(change.dark).length + Object.keys(change.rename).length + change.remove.length > 0 || ['styles', 'prose', 'buttons', 'transition'].some((key) => key in change)
      if (tokensChanged) await run('design/update', change)
      if (draft.css !== base.css || draft.js !== base.js) await run('design/code', { css: draft.css, ...(can('site.code') ? { js: draft.js } : {}) })
      await queryClient.invalidateQueries({ predicate: (q) => typeof q.queryKey[0] === 'string' && q.queryKey[0].startsWith('design/') })
      setDraft(null)
      toast.success(t('Design system saved'))
      frame.current?.contentWindow?.location.reload()
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }

  if (theme.isLoading) return <div className="grid h-full place-items-center text-faint"><Spinner /></div>
  if (!theme.data || !current) {
    return (
      <div className="flex h-full flex-col">
        <Topbar crumbs={[{ label: t('Design System'), icon: 'palette' }]} />
        <EmptyState icon="palette" title={t('The design system could not be loaded')} description={theme.error?.message} />
      </div>
    )
  }

  const tokens = current.tokens
  const byGroup = (group: string) => tokens.filter((token) => token.group === group)
  const setToken = (token: DraftToken, patch: Partial<DraftToken>) => update((d) => ({ ...d, tokens: d.tokens.map((x) => (x.path === token.path ? { ...x, ...patch } : x)) }))
  const removeToken = (token: DraftToken) => update((d) => ({ ...d, tokens: d.tokens.filter((x) => x.path !== token.path) }))
  const tokenName = async (title: string, group: string, name = '') => {
    const asked = await prompt({ title, description: name ? t('Blocks that use token:{path} keep the old name.', { path: `${group}.${name}` }) : undefined, fields: [{ handle: 'name', type: 'text', label: t('Name'), mono: true, autofocus: true, placeholder: group === 'color' ? 'brand' : 'md' }], values: { name }, confirm: name ? t('Rename') : t('Add') })
    const next = String(asked?.name ?? '').trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '').replace(/^-+/, '')
    if (!next || next === name) return null
    if (tokens.some((x) => x.path === `${group}.${next}`)) {
      toast.error(t('“{name}” already exists', { name: next }))
      return null
    }
    return next
  }
  const add = async (group: string, value: any) => {
    const name = await tokenName(newTitle(group), group)
    if (name) update((d) => ({ ...d, tokens: [...d.tokens, { path: `${group}.${name}`, group, name, value, dark: null }] }))
  }
  const rename = async (token: DraftToken) => {
    const name = await tokenName(t('Rename Token'), token.group, token.name)
    if (name) update((d) => retarget(d, token.path, `${token.group}.${name}`))
  }
  const actions = { rename, remove: removeToken }
  const references = tokens.map((token) => `token:${token.path}`)
  /** A token's value as a field drawn with Fields. */
  const value = (token: DraftToken, field: Partial<FieldDef>, key: 'value' | 'dark' = 'value') => (
    <Fields bare className="min-w-0 flex-1" fields={[{ handle: `${token.path}:${key}`, type: 'text', label: token.name, size: 'xs', mono: true, ...field }]} values={{ [`${token.path}:${key}`]: token[key] }} onChange={(_, v) => setToken(token, { [key]: key === 'dark' ? v || null : v })} />
  )
  // Samples use the site's values (in the previewed mode), never the admin's own variables.
  const siteVars = Object.fromEntries(tokens.map((token) => [variable(token.group, token.name), css(token.group, mode === 'dark' && token.dark ? token.dark : token.value)])) as CSSProperties
  const durationEase = css('ease', byGroup('ease')[0]?.value ?? 'cubic-bezier(0.2, 0.8, 0.2, 1)')

  return (
    <div className="flex h-full flex-col">
      <Topbar
        crumbs={[{ label: t('Design System'), icon: 'palette' }]}
        actions={
          <>
            <Tooltip content={t('Open Site')}>
              <IconButton icon="external" label={t('Open Site')} onClick={() => window.open('/', '_blank', 'noopener')} />
            </Tooltip>
            {dirty && <Button variant="ghost" onClick={() => setDraft(null)}>{t('Discard')}</Button>}
            <Tooltip content={t('Save')} shortcut="mod+s">
              <Button variant="primary" disabled={!dirty} loading={busy} onClick={save}>{t('Save')}</Button>
            </Tooltip>
          </>
        }
      />
      <div className="grid min-h-0 flex-1 grid-cols-[44px_minmax(320px,380px)_minmax(0,1fr)] xl:grid-cols-[148px_minmax(360px,420px)_minmax(0,1fr)]">
        <nav aria-label={t('Design System')} className="overflow-y-auto border-r border-border px-1.5 py-3 xl:px-2">
          {sections().map((group) => (
            <div key={group.group} className="mb-4 space-y-px">
              <p className="cms-caps m-0 hidden px-2 pb-1 text-faint xl:block">{group.group}</p>
              {group.items.map((item) => (
                <button
                  key={item.value}
                  type="button"
                  aria-current={section === item.value ? 'page' : undefined}
                  title={item.label}
                  onClick={() => setSection(item.value)}
                  className={cn('flex h-7 w-full items-center justify-center gap-2 rounded-sm px-2 text-left text-sm transition-colors xl:justify-start', section === item.value ? 'bg-active font-medium text-ink' : 'text-muted hover:bg-hover hover:text-text')}
                >
                  <Icon name={item.icon} size={14} className="shrink-0" />
                  <span className="hidden truncate xl:inline">{item.label}</span>
                </button>
              ))}
            </div>
          ))}
        </nav>

        <div key={section} className="overflow-y-auto border-r border-border px-4 pb-16 pt-2">
          {section === 'color' && (
            <Group title={t('Colors')} onAdd={() => add('color', '#000000')}>
              <div className="flex h-7 items-center gap-2 text-2xs text-faint">
                <span className="w-28 shrink-0">{t('Name')}</span>
                <span className="flex flex-1 items-center gap-1"><Icon name="sun" size={11} />{t('Light')}</span>
                <span className="flex flex-1 items-center gap-1"><Icon name="moon" size={11} />{t('Dark')}</span>
                <span className="w-6 shrink-0" />
              </div>
              {byGroup('color').map((token) => (
                <Row key={token.from ?? token.path} token={token} {...actions}>
                  {value(token, { type: 'color', component: 'blocks-color' })}
                  {value(token, { type: 'color', component: 'blocks-color', placeholder: t('Same') }, 'dark')}
                </Row>
              ))}
            </Group>
          )}

          {section === 'type' && (
            <>
              <Group title={t('Font Families')} onAdd={() => add('font', ['system-ui', 'sans-serif'])}>
                {byGroup('font').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} below={<p className="m-0 truncate pb-2.5 text-lg leading-tight text-ink" style={{ fontFamily: css('font', token.value) }}>{t('The quick brown fox jumps over the lazy dog')}</p>}>
                    {value(token, { component: 'site-font-stack' })}
                  </Row>
                ))}
              </Group>
              <Group title={t('Type Scale')} onAdd={() => add('text', { min: '1rem', max: '1.25rem' })}>
                {byGroup('text').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} below={<p className="m-0 truncate pb-2.5 leading-[1.1] tracking-[-0.01em] text-ink" style={{ fontSize: css('text', token.value), fontFamily: css('font', byGroup('font').find((x) => x.name === 'display')?.value ?? byGroup('font')[0]?.value) }}>{t('Ship It')}</p>}>
                    {value(token, { component: 'site-size' })}
                  </Row>
                ))}
              </Group>
              <Group title={t('Weights')} onAdd={() => add('weight', '600')}>
                {byGroup('weight').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="text-base text-ink" style={{ fontWeight: Number(token.value) || 400 }}>Ag</span>}>
                    {value(token, { inputmode: 'numeric' })}
                  </Row>
                ))}
              </Group>
              <Group title={t('Line Heights')} onAdd={() => add('leading', '1.5')}>
                {byGroup('leading').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="flex w-4 flex-col" style={{ gap: `${Math.max(1, (Number(token.value) - 0.8) * 6)}px` }}>{[0, 1, 2].map((i) => <span key={i} className={cn('h-0.5 rounded-full bg-muted', i === 2 && 'w-2/3')} />)}</span>}>
                    {value(token, {})}
                  </Row>
                ))}
              </Group>
              <Group title={t('Letter Spacing')} onAdd={() => add('tracking', '0')}>
                {byGroup('tracking').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="text-xs font-medium text-ink" style={{ letterSpacing: css('tracking', token.value) }}>Aa</span>}>
                    {value(token, {})}
                  </Row>
                ))}
              </Group>
            </>
          )}

          {section === 'space' && (
            <>
              <Group title={t('Spacing')} onAdd={() => add('space', '1rem')}>
                {byGroup('space').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} after={<Bar value={token.value} of={byGroup('space')} />}>
                    {value(token, { component: 'site-size' })}
                  </Row>
                ))}
              </Group>
              <Group title={t('Containers')} onAdd={() => add('container', '72rem')}>
                {byGroup('container').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} after={<Bar value={token.value} of={byGroup('container')} />}>
                    {value(token, { component: 'site-size' })}
                  </Row>
                ))}
              </Group>
              <Group title={t('Breakpoints')} onAdd={() => add('breakpoint', '768px')}>
                {byGroup('breakpoint').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} after={<Bar value={token.value} of={byGroup('breakpoint')} />}>
                    {value(token, {})}
                  </Row>
                ))}
              </Group>
            </>
          )}

          {section === 'shape' && (
            <>
              <Group title={t('Radius')} onAdd={() => add('radius', '0.5rem')}>
                {byGroup('radius').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="size-5 translate-x-0.5 translate-y-0.5 border-l-[1.5px] border-t-[1.5px] border-muted" style={{ borderTopLeftRadius: `min(${css('radius', token.value)}, 20px)` }} />}>
                    {value(token, {})}
                  </Row>
                ))}
              </Group>
              <Group title={t('Shadows')} onAdd={() => add('shadow', '0 4px 16px -4px rgb(0 0 0 / 0.12)')}>
                {byGroup('shadow').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="size-10 shrink-0 scale-50 rounded-md bg-white ring-1 ring-black/5" style={{ boxShadow: css('shadow', token.value) }} />}>
                    {value(token, { component: 'site-shadow' })}
                  </Row>
                ))}
              </Group>
            </>
          )}

          {section === 'motion' && (
            <>
              <Group title={t('Page Transition')}>
                <Fields
                  layout="inline"
                  className="space-y-1.5 py-2"
                  fields={[
                    { handle: 'type', type: 'select', label: t('Effect'), display: 'segmented', block: true, size: 'xs', required: true, options: { none: t('None'), fade: t('Fade'), slide: t('Slide'), scale: t('Scale') } },
                    { handle: 'duration', type: 'number', label: t('Duration'), size: 'xs', decimals: true, min: 0.05, max: 2, step: 0.05, suffix: 's', if: { type: ['fade', 'slide', 'scale'] } },
                  ]}
                  values={current.transition}
                  onChange={(h, v) => update((d) => ({ ...d, transition: { ...d.transition, [h]: h === 'duration' ? Number(v) || 0.3 : v } }))}
                />
              </Group>
              <Group title={t('Durations')} onAdd={() => add('duration', '300ms')}>
                {byGroup('duration').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="relative h-3 w-6"><span className="absolute left-0 top-1/2 size-2 -translate-y-1/2 rounded-full bg-focus transition-transform group-hover:translate-x-4" style={{ transitionDuration: css('duration', token.value), transitionTimingFunction: durationEase }} /></span>}>
                    {value(token, {})}
                  </Row>
                ))}
              </Group>
              <Group title={t('Easing')} onAdd={() => add('ease', 'cubic-bezier(0.2, 0.8, 0.2, 1)')}>
                {byGroup('ease').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions}>
                    {value(token, { component: 'site-ease' })}
                  </Row>
                ))}
              </Group>
            </>
          )}

          {section === 'fonts' && <Fonts fonts={theme.data.fonts ?? []} tokens={byGroup('font')} onChanged={() => { void theme.refetch(); frame.current?.contentWindow?.location.reload() }} />}

          {section === 'elements' && (
            <>
              <StyleMap title={t('Elements')} options={theme.data.elements ?? []} tokens={references} value={current.styles} onChange={(styles) => update((d) => ({ ...d, styles }))} />
              <StyleMap title={t('Prose')} options={theme.data.elements ?? []} tokens={references} value={current.prose} onChange={(prose) => update((d) => ({ ...d, prose }))} />
            </>
          )}

          {section === 'buttons' && <ButtonVariants value={current.buttons} tokens={references} vars={siteVars} onChange={(buttons) => update((d) => ({ ...d, buttons }))} />}

          {section === 'code' && <Code css={current.css} js={current.js} onChange={(code) => update((d) => ({ ...d, ...code }))} />}
        </div>

        <div className="flex min-w-0 flex-col bg-sunken">
          <div className="flex h-10 shrink-0 items-center gap-2 border-b border-border bg-bg px-3">
            <Segmented size="xs" value={viewport} onChange={setViewport} options={[{ value: 'desktop', icon: 'desktop', title: t('Desktop') }, { value: 'tablet', icon: 'tablet', title: t('Tablet') }, { value: 'phone', icon: 'phone', title: t('Phone') }]} />
            {viewport !== 'desktop' && <span className="font-mono text-2xs text-faint">{WIDTHS[viewport]}</span>}
            <span className="flex-1" />
            <Segmented size="xs" value={mode} onChange={setMode} options={[{ value: 'light', icon: 'sun', title: t('Light') }, { value: 'dark', icon: 'moon', title: t('Dark') }]} />
          </div>
          <div className="flex min-h-0 flex-1 justify-center">
            <iframe ref={frame} src="/" title={t('Site Preview')} className={cn('h-full border-0 bg-bg', viewport !== 'desktop' && 'border-x border-border')} style={{ width: WIDTHS[viewport], maxWidth: '100%' }} onLoad={inject} />
          </div>
        </div>
      </div>
    </div>
  )
}

/** A titled list of rows — hairlines, no card. */
function Group({ title, onAdd, action, children }: { title: string; onAdd?: () => void; action?: ReactNode; children: ReactNode }) {
  return (
    <section className="mb-6">
      <div className="flex h-9 items-center justify-between border-b border-border">
        <h3 className="m-0 text-xs font-semibold text-ink">{title}</h3>
        {action}
        {onAdd && (
          <Tooltip content={t('Add Token')}>
            <IconButton icon="plus" label={t('Add Token')} size="xs" onClick={onAdd} />
          </Tooltip>
        )}
      </div>
      {children}
    </section>
  )
}

/**
 * One token: an optional sample, its name (click to copy `token:group.name`), its
 * value fields and a menu. `below` adds a specimen line, `after` a visual after the value.
 */
function Row({ token, sample, after, below, rename, remove, children }: { token: DraftToken; sample?: ReactNode; after?: ReactNode; below?: ReactNode; rename: (token: DraftToken) => void; remove: (token: DraftToken) => void; children: ReactNode }) {
  const reference = `token:${token.path}`
  const copy = () => {
    void navigator.clipboard?.writeText(reference)
    toast.success(t('Copied {reference}', { reference }))
  }
  return (
    <div className="group border-b border-border last:border-b-0">
      <div className="flex min-h-9 items-center gap-2 py-1">
        {sample !== undefined && <span className="grid w-7 shrink-0 place-items-center">{sample}</span>}
        <Tooltip content={<span className="font-mono">{reference}</span>}>
          <button type="button" onClick={copy} className={cn('h-6 shrink-0 truncate rounded-xs text-left text-xs text-text hover:text-ink', sample !== undefined ? 'w-[76px]' : 'w-28')}>
            {token.name}
          </button>
        </Tooltip>
        {after ? <div className="flex w-36 shrink-0">{children}</div> : children}
        {after && <span className="flex min-w-0 flex-1 items-center">{after}</span>}
        <Menu
          trigger={<IconButton icon="more" label={t('Token Actions')} size="xs" className="shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[popup-open]:opacity-100" />}
          items={[
            { label: t('Copy Reference'), icon: 'copy', onSelect: copy },
            { label: t('Rename…'), icon: 'edit', onSelect: () => rename(token) },
            { separator: true, label: '' },
            { label: t('Delete'), icon: 'trash', danger: true, onSelect: () => remove(token) },
          ]}
        />
      </div>
      {below}
    </div>
  )
}

/** A length as a bar, relative to the largest in its group. */
function Bar({ value, of }: { value: any; of: Token[] }) {
  const max = Math.max(...of.map((token) => px(token.value)), 1)
  return <span className="h-1.5 min-w-px rounded-full bg-focus/40" style={{ width: `${(px(value) / max) * 100}%` }} />
}

/** Self-hosted fonts: the families on this site, importing a Google font and uploading .woff2 files. */
function Fonts({ fonts, tokens, onChanged }: { fonts: Font[]; tokens: Token[]; onChanged: () => void }) {
  const [family, setFamily] = useState('')
  const [busy, setBusy] = useState(false)
  const families = [...new Set(fonts.map((font) => font.family))]

  const importFamily = async () => {
    const name = family.trim()
    if (!name) return
    setBusy(true)
    try {
      await run('design/fonts-import', { family: name })
      toast.success(t('{family} added', { family: name }))
      setFamily('')
      onChanged()
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }

  const upload = async (file: File) => {
    const data = await new Promise<string>((resolve) => {
      const reader = new FileReader()
      reader.onload = () => resolve(String(reader.result))
      reader.readAsDataURL(file)
    })
    const name = file.name.replace(/\.woff2$/i, '')
    const asked = await prompt({ title: t('Upload Font'), fields: [{ handle: 'family', type: 'text', label: t('Family Name'), autofocus: true }], values: { family: name.replace(/[-_](regular|bold|variable|italic|\d+).*$/i, '').replace(/[-_]/g, ' ') }, confirm: t('Upload') })
    const fam = String(asked?.family ?? '').trim()
    if (!fam) return
    try {
      await run('design/fonts-upload', { family: fam, name, data, weight: /variable/i.test(name) ? '100 900' : '400', style: /italic/i.test(name) ? 'italic' : 'normal' })
      toast.success(t('{family} uploaded', { family: fam }))
      onChanged()
    } catch (e) {
      toast.error(e)
    }
  }

  const remove = async (name: string) => {
    if (!(await confirm({ title: t('Remove {family}?', { family: name }), description: t('Font tokens that name it fall back to the next family in their stack.'), confirm: t('Remove'), danger: true }))) return
    try {
      await run('design/update', { fonts: fonts.filter((font) => font.family !== name) })
      onChanged()
    } catch (e) {
      toast.error(e)
    }
  }

  return (
    <>
      <Group title={t('Families')}>
        {families.length === 0 && <p className="m-0 py-3 text-xs text-faint">{t('None yet. The site uses system fonts.')}</p>}
        {families.map((name) => {
          const files = fonts.filter((font) => font.family === name)
          const used = tokens.filter((token) => Array.isArray(token.value) && token.value[0] === name).map((token) => token.name)
          return (
            <div key={name} className="group flex items-center gap-3 border-b border-border py-2.5 last:border-b-0">
              <span className="grid size-9 shrink-0 place-items-center rounded-sm bg-sunken text-lg text-ink" style={{ fontFamily: `"${name}"` }}>Aa</span>
              <div className="min-w-0 flex-1">
                <p className="m-0 truncate text-sm text-ink" style={{ fontFamily: `"${name}"` }}>{name}</p>
                <p className="m-0 truncate text-2xs text-faint">
                  {files.map((font) => `${font.weight ?? '400'}${font.style === 'italic' ? ' italic' : ''}`).join(' · ')}
                  {used.length > 0 && ` · ${t('used by {names}', { names: used.join(', ') })}`}
                </p>
              </div>
              <Menu trigger={<IconButton icon="more" label={t('Font Actions')} size="xs" className="opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[popup-open]:opacity-100" />} items={[{ label: t('Remove'), icon: 'trash', danger: true, onSelect: () => void remove(name) }]} />
            </div>
          )
        })}
      </Group>
      <Group title={t('Add a Google Font')}>
        {/* A form, so Enter in the field adds the family. */}
        <form className="flex gap-1.5 py-2.5" onSubmit={(e) => { e.preventDefault(); void importFamily() }}>
          <Fields bare className="min-w-0 flex-1" fields={[{ handle: 'family', type: 'text', label: t('Google Font Family'), size: 'sm', placeholder: 'Inter Tight, Fraunces…' }]} values={{ family }} onChange={(_, v) => setFamily(v ?? '')} />
          <Button type="submit" size="sm" loading={busy} disabled={!family.trim()}>{t('Add')}</Button>
        </form>
        <p className="m-0 -mt-1 text-2xs text-faint">{t('Served from this site; visitors never connect to Google.')}</p>
      </Group>
      <Group title={t('Upload')}>
        <div className="py-2.5">
          <Fields bare fields={[{ handle: 'file', type: 'json', component: 'site-file', label: t('Font File'), placeholder: t('Drop a .woff2 file or choose one'), accept: '.woff2,font/woff2' }]} values={{}} onChange={(_, file) => file && void upload(file)} />
        </div>
      </Group>
    </>
  )
}

/** Element → declarations: one declarations field per element. */
function StyleMap({ title, value, onChange, options, tokens }: { title: string; value: Record<string, Declarations>; onChange: (v: Record<string, Declarations>) => void; options: string[]; tokens: string[] }) {
  const free = options.filter((option) => !(option in value))
  return (
    <Group title={title} action={<Menu trigger={<IconButton icon="plus" label={t('Add Element')} size="xs" />} items={free.map((option) => ({ label: option, onSelect: () => onChange({ ...value, [option]: {} }) }))} className="max-h-72 overflow-auto font-mono" />}>
      {Object.keys(value).length === 0 && <p className="m-0 py-3 text-xs text-faint">{t('No element styles')}</p>}
      <div className="divide-y divide-border">
        {Object.keys(value).map((element) => (
          <Fields
            key={element}
            bare
            fields={[{ handle: element, type: 'json', component: 'site-declarations', label: element, removable: true, tokens }]}
            values={value}
            onChange={(_, d) => onChange(d === null ? Object.fromEntries(Object.entries(value).filter(([k]) => k !== element)) : { ...value, [element]: d })}
          />
        ))}
      </div>
    </Group>
  )
}

const SHORT: Record<string, string> = { font: 'fontFamily', size: 'fontSize', weight: 'fontWeight', leading: 'lineHeight', tracking: 'letterSpacing', background: 'backgroundColor', radius: 'borderRadius' }
const UNITLESS = new Set(['fontWeight', 'lineHeight', 'opacity', 'zIndex'])

/** Declarations as React styles, roughly as the compiler writes them (tokens → var()). */
function style(declarations: Declarations | undefined): CSSProperties {
  return Object.fromEntries(Object.entries(declarations ?? {}).map(([key, v]) => {
    const prop = SHORT[key] ?? key
    return [prop, typeof v === 'number' && !UNITLESS.has(prop) && v !== 0 ? `${v}px` : css('', v)]
  }))
}

/** Button variants (Primary, Secondary…) used by every Button block: a sample, then default and hover declarations. */
function ButtonVariants({ value, onChange, tokens, vars }: { value: Buttons; onChange: (v: Buttons) => void; tokens: string[]; vars: CSSProperties }) {
  const add = async () => {
    const asked = await prompt({ title: t('New Button Variant'), fields: [{ handle: 'name', type: 'text', label: t('Name'), placeholder: 'outline', mono: true, autofocus: true }], confirm: t('Add') })
    const name = String(asked?.name ?? '').toLowerCase().replace(/[^a-z0-9-]/g, '')
    if (name && !(name in value)) onChange({ ...value, [name]: { base: {}, hover: {} } })
  }
  const states = (variant: Record<string, Declarations>) => ({ base: variant.base ?? (!('hover' in variant) ? (variant as unknown as Declarations) : {}), hover: variant.hover ?? {} })
  const sample: CSSProperties = { ...vars, display: 'inline-flex', alignItems: 'center', padding: '0.45em 0.9em', fontSize: 13, fontWeight: 'var(--font-weight-medium, 500)', lineHeight: 1.2, borderRadius: 'var(--radius-md, 0.5rem)', border: '1px solid transparent', fontFamily: 'var(--font-sans, inherit)', color: 'var(--color-foreground)', whiteSpace: 'nowrap' }

  return (
    <Group title={t('Button Variants')} onAdd={add}>
      {Object.keys(value).length === 0 && <p className="m-0 py-3 text-xs text-faint">{t('No variants')}</p>}
      {Object.entries(value).map(([name, variant]) => {
        const { base, hover } = states(variant)
        return (
          <div key={name} className="group border-b border-border pb-1 last:border-b-0">
            <div className="flex h-9 items-center gap-2">
              <span className="min-w-0 flex-1 truncate font-mono text-xs font-medium text-ink">{name}</span>
              <Menu trigger={<IconButton icon="more" label={t('Variant Actions')} size="xs" className="opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[popup-open]:opacity-100" />} items={[{ label: t('Delete'), icon: 'trash', danger: true, onSelect: () => onChange(Object.fromEntries(Object.entries(value).filter(([k]) => k !== name))) }]} />
            </div>
            <div className="flex items-center gap-3 rounded-sm px-3 py-3" style={{ ...vars, background: 'var(--color-background)' }}>
              <span style={{ ...sample, ...style(base) }}>{t('Default')}</span>
              <span style={{ ...sample, ...style(base), ...style(hover) }}>{t('Hover')}</span>
            </div>
            {(['base', 'hover'] as const).map((state) => (
              <Fields
                key={state}
                bare
                fields={[{ handle: state, type: 'json', component: 'site-declarations', label: state === 'base' ? t('Default') : t('Hover'), mono: false, tokens }]}
                values={{ base, hover }}
                onChange={(_, d) => onChange({ ...value, [name]: { ...states(variant), [state]: d ?? {} } })}
              />
            ))}
          </div>
        )
      })}
    </Group>
  )
}

/** Custom CSS and JavaScript (content/design/custom.css|js), saved with everything else. */
function Code({ css, js, onChange }: { css: string; js: string; onChange: (code: { css?: string; js?: string }) => void }) {
  const allowed = can('site.code')
  return (
    <>
      <Group title={t('Custom CSS')}>
        <div className="py-2.5">
          <Fields bare fields={[{ handle: 'css', type: 'code', label: t('Custom CSS'), rows: 14, placeholder: '.cms-prose figure { margin-block: 2rem; }' }]} values={{ css }} onChange={(_, v) => onChange({ css: v ?? '' })} disabled={!allowed} />
        </div>
      </Group>
      <Group title={t('Custom JavaScript')}>
        <div className="py-2.5">
          <Fields bare fields={[{ handle: 'js', type: 'code', label: t('Custom JavaScript'), rows: 14, placeholder: "document.addEventListener('cms:alpine', (e) => …)" }]} values={{ js }} onChange={(_, v) => onChange({ js: v ?? '' })} disabled={!allowed} />
          <p className="m-0 mt-1.5 text-2xs text-faint">{t('Runs on every page once saved.')}</p>
        </div>
      </Group>
      {!allowed && <p className="m-0 text-2xs text-warning">{t('Editing code needs the “Edit custom CSS and JavaScript” permission.')}</p>}
    </>
  )
}
