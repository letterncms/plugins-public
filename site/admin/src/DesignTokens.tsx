import { can, queryClient, run, t, useAbility, useTab, type FieldDef } from '@letterncms/sdk'
import { Button, EmptyState, Fields, Icon, IconButton, Menu, SaveBar, ScreenSidebar, Segmented, SidebarRow, Spinner, Tooltip, Topbar, cn, confirm, prompt, toast, useScreenSidebar } from '@letterncms/ui'
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

const SECTIONS = ['color', 'type', 'space', 'shape', 'motion', 'elements', 'buttons', 'code'] as const
type SectionKey = (typeof SECTIONS)[number]

function sections(): { group: string; items: { value: SectionKey; label: string; icon: string }[] }[] {
  return [
    {
      group: t('Tokens'),
      items: [
        { value: 'color', label: t('Colors'), icon: 'palette' },
        { value: 'type', label: t('Typography'), icon: 'typography' },
        { value: 'space', label: t('Spacing'), icon: 'spacing' },
        { value: 'shape', label: t('Shape'), icon: 'radius' },
        { value: 'motion', label: t('Motion'), icon: 'speed' },
      ],
    },
    {
      group: t('Styles'),
      items: [
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

/**
 * The site at the chosen screen's width. Desktop takes the room there is, but never less
 * than the site's desktop width: with less room it is scaled down, so it keeps its desktop
 * layout. Tablet and phone draw at their width (scaled down only when the room is smaller).
 */
function Preview({ viewport, desktop, children }: { viewport: Viewport; desktop: number; children: (style: CSSProperties) => ReactNode }) {
  const box = useRef<HTMLDivElement>(null)
  const [room, setRoom] = useState({ width: 0, height: 0 })
  useEffect(() => {
    const el = box.current
    if (!el) return
    const measure = () => setRoom({ width: el.clientWidth, height: el.clientHeight })
    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(el)
    return () => observer.disconnect()
  }, [])
  const width = viewport === 'desktop' ? Math.max(room.width, desktop) : parseFloat(WIDTHS[viewport])
  const scale = room.width ? Math.min(1, room.width / width) : 1
  return (
    <div ref={box} className="relative min-h-0 flex-1 overflow-hidden">
      {room.width > 0 && children({ position: 'absolute', top: 0, left: Math.max(0, (room.width - width * scale) / 2), width, height: room.height / scale, transform: scale < 1 ? `scale(${scale})` : undefined, transformOrigin: 'top left' })}
    </div>
  )
}

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
  const [failed, setFailed] = useState<string | null>(null)
  const sidebar = useScreenSidebar()
  const [compiled, setCompiled] = useState<string | null>(null)
  const frame = useRef<HTMLIFrameElement>(null)

  const current = draft ?? base
  const dirty = !!draft && !!base && !same(draft, base)
  const update = (fn: (d: Draft) => Draft) => base && setDraft((d) => fn(d ?? base))


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
    setFailed(null)
    try {
      const change = changes(base, draft)
      const tokensChanged = Object.keys(change.tokens).length + Object.keys(change.dark).length + Object.keys(change.rename).length + change.remove.length > 0 || ['styles', 'prose', 'buttons', 'transition'].some((key) => key in change)
      if (tokensChanged) await run('design/update', change)
      if (draft.css !== base.css || draft.js !== base.js) await run('design/code', { css: draft.css, ...(can('site.code') ? { js: draft.js } : {}) })
      await queryClient.invalidateQueries({ predicate: (q) => typeof q.queryKey[0] === 'string' && q.queryKey[0].startsWith('design/') })
      setDraft(null)
      frame.current?.contentWindow?.location.reload()
    } catch (e) {
      setFailed(e instanceof Error ? e.message : String(e))
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
  /** A token's value as a field drawn with Fields: one control, one width, in every group (sizes bring their fluid switch into the room the others leave). */
  const value = (token: DraftToken, field: Partial<FieldDef>, key: 'value' | 'dark' = 'value') => (
    <Fields bare className={cn('flex min-w-0 flex-1', !['site-size', 'blocks-color'].includes(String(field.component)) && 'pr-8')} fields={[{ handle: `${token.path}:${key}`, type: 'text', label: token.name, size: 'sm', mono: true, ...field }]} values={{ [`${token.path}:${key}`]: token[key] }} onChange={(_, v) => setToken(token, { [key]: key === 'dark' ? v || null : v ?? token[key] })} />
  )
  /** The inspector's value control for a token: a number with its unit. */
  const number = (unit: string, extra: Partial<FieldDef> = {}): Partial<FieldDef> => ({ component: 'blocks-value', unit, ...extra })
  // Samples use the site's values (in the previewed mode), never the admin's own variables.
  const siteVars = Object.fromEntries(tokens.map((token) => [variable(token.group, token.name), css(token.group, mode === 'dark' && token.dark ? token.dark : token.value)])) as CSSProperties
  const durationEase = css('ease', byGroup('ease')[0]?.value ?? 'cubic-bezier(0.2, 0.8, 0.2, 1)')
  const families = [...new Set((theme.data.fonts ?? []).map((font) => font.family))]
  const refreshFonts = () => { void theme.refetch(); frame.current?.contentWindow?.location.reload() }

  return (
    <div className="flex h-full flex-col">
      <Topbar
        crumbs={[{ label: t('Design System'), icon: 'palette' }, { label: sections().flatMap((g) => g.items).find((item) => item.value === section)?.label ?? '' }]}
        actions={
          <Tooltip content={t('Open Site')}>
            <IconButton icon="external" label={t('Open Site')} onClick={() => window.open('/', '_blank', 'noopener')} />
          </Tooltip>
        }
      />
      {/* The sections are rows under Design System in the sidebar: no column of their own, and the sidebar stays the one it was. */}
      <ScreenSidebar title={t('Design System')} backTo="/" backLabel={t('Dashboard')} nested>
        <nav aria-label={t('Design System')} className={sidebar.nested ? 'space-y-px' : 'px-2 pb-4'}>
          {sections().map((group, index) => (
            <div key={group.group} className={cn('space-y-px', index > 0 && (sidebar.nested ? 'mt-1' : 'mt-4'))}>
              {!sidebar.nested && <div className="cms-label flex h-7 items-center px-2">{group.group}</div>}
              {group.items.map((item) => (
                <SidebarRow key={item.value} label={item.label} icon={sidebar.nested ? undefined : item.icon} active={section === item.value} onClick={() => setSection(item.value)} />
              ))}
            </div>
          ))}
        </nav>
      </ScreenSidebar>
      <SaveBar dirty={dirty} saving={busy} error={failed} onSave={() => void save()} onDiscard={() => { setDraft(null); setFailed(null) }} />
      {/* On a phone the panel takes the screen; the site is a tab away (Open Site). */}
      <div className="grid min-h-0 flex-1 grid-cols-1 md:grid-cols-[minmax(340px,420px)_minmax(0,1fr)] xl:grid-cols-[minmax(380px,460px)_minmax(0,1fr)]">
        <div key={section} className="overflow-y-auto border-border px-4 pb-16 pt-2 md:border-r">
          {section === 'color' && (
            <Group title={t('Colors')} onAdd={() => add('color', '#000000')}>
              <div className="flex h-7 items-center gap-2 text-2xs text-faint">
                <span className="min-w-0 flex-1">{t('Name')}</span>
                <span className={cn(COLOR, 'flex items-center gap-1')}><Icon name="sun" size={11} />{t('Light')}</span>
                <span className={cn(COLOR, 'flex items-center gap-1')}><Icon name="moon" size={11} />{t('Dark')}</span>
                <span className="w-6 shrink-0" />
              </div>
              {byGroup('color').map((token) => (
                <Row key={token.from ?? token.path} token={token} {...actions} wide>
                  <div className={COLOR}>{value(token, { type: 'color', component: 'blocks-color' })}</div>
                  <div className={COLOR}>{value(token, { type: 'color', component: 'blocks-color', placeholder: t('Same') }, 'dark')}</div>
                </Row>
              ))}
            </Group>
          )}

          {section === 'type' && (
            <>
              <Group title={t('Font Families')} onAdd={() => add('font', [...(families[0] ? [families[0]] : []), 'ui-sans-serif', 'system-ui', 'sans-serif'])}>
                {byGroup('font').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} below={<p className="m-0 truncate pb-2.5 text-lg leading-tight text-ink" style={{ fontFamily: css('font', token.value) }}>{t('The quick brown fox jumps over the lazy dog')}</p>}>
                    {value(token, { component: 'site-font-stack', families })}
                  </Row>
                ))}
              </Group>
              <Fonts fonts={theme.data.fonts ?? []} tokens={byGroup('font')} onChanged={refreshFonts} />
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
                    {value(token, number('', { step: 100, min: 100, max: 900 }))}
                  </Row>
                ))}
              </Group>
              <Group title={t('Line Heights')} onAdd={() => add('leading', '1.5')}>
                {byGroup('leading').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="flex w-4 flex-col" style={{ gap: `${Math.max(1, (Number(token.value) - 0.8) * 6)}px` }}>{[0, 1, 2].map((i) => <span key={i} className={cn('h-0.5 rounded-full bg-muted', i === 2 && 'w-2/3')} />)}</span>}>
                    {value(token, number('', { step: 0.05, min: 0 }))}
                  </Row>
                ))}
              </Group>
              <Group title={t('Letter Spacing')} onAdd={() => add('tracking', '0em')}>
                {byGroup('tracking').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="text-xs font-medium text-ink" style={{ letterSpacing: css('tracking', token.value) }}>Aa</span>}>
                    {value(token, number('em', { units: ['em', 'px'], step: 0.005 }))}
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
                    {value(token, number('px', { units: ['px', 'rem', 'em'], min: 0 }))}
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
                    {value(token, number('px', { units: ['px', 'rem', '%'], min: 0 }))}
                  </Row>
                ))}
              </Group>
              <Group title={t('Shadows')} onAdd={() => add('shadow', '0 4px 16px -4px rgb(0 0 0 / 0.12)')}>
                {byGroup('shadow').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="size-5 shrink-0 rounded-[5px] border border-border bg-bg" style={{ boxShadow: css('shadow', token.value) }} />}>
                    {value(token, { component: 'site-shadow' })}
                  </Row>
                ))}
              </Group>
            </>
          )}

          {section === 'motion' && (
            <>
              <Group title={t('Page Transition')}>
                <Setting label={t('Effect')}>
                  <Fields bare className="flex min-w-0 flex-1" fields={[{ handle: 'type', type: 'select', label: t('Effect'), size: 'sm', required: true, options: { none: t('None'), fade: t('Fade'), slide: t('Slide'), scale: t('Scale') } }]} values={current.transition} onChange={(_, v) => update((d) => ({ ...d, transition: { ...d.transition, type: String(v ?? 'none') } }))} />
                </Setting>
                {current.transition.type !== 'none' && (
                  <Setting label={t('Duration')}>
                    <Fields bare className="flex min-w-0 flex-1" fields={[{ handle: 'duration', type: 'text', component: 'blocks-value', label: t('Duration'), unit: 'ms', units: ['ms'], step: 10, min: 50, max: 2000 }]} values={{ duration: `${Math.round(current.transition.duration * 1000)}ms` }} onChange={(_, v) => update((d) => ({ ...d, transition: { ...d.transition, duration: seconds(v, d.transition.duration) } }))} />
                  </Setting>
                )}
              </Group>
              <Group title={t('Durations')} onAdd={() => add('duration', '300ms')}>
                {byGroup('duration').map((token) => (
                  <Row key={token.from ?? token.path} token={token} {...actions} sample={<span className="relative h-3 w-6"><span className="absolute left-0 top-1/2 size-2 -translate-y-1/2 rounded-full bg-muted transition-transform group-hover:translate-x-4" style={{ transitionDuration: css('duration', token.value), transitionTimingFunction: durationEase }} /></span>}>
                    {value(token, number('ms', { units: ['ms'], step: 10, min: 0 }))}
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

          {section === 'elements' && (
            <>
              <StyleList title={t('Elements')} options={theme.data.elements ?? []} tokens={tokens} vars={siteVars} value={current.styles} onChange={(styles) => update((d) => ({ ...d, styles }))} />
              <StyleList title={t('Prose')} options={theme.data.elements ?? []} tokens={tokens} vars={siteVars} value={current.prose} onChange={(prose) => update((d) => ({ ...d, prose }))} />
            </>
          )}

          {section === 'buttons' && <ButtonVariants value={current.buttons} tokens={tokens} vars={siteVars} onChange={(buttons) => update((d) => ({ ...d, buttons }))} />}

          {section === 'code' && <Code css={current.css} js={current.js} onChange={(code) => update((d) => ({ ...d, ...code }))} />}
        </div>

        <div className="flex min-w-0 flex-col bg-sunken max-md:hidden">
          <div className="flex h-10 shrink-0 items-center gap-2 border-b border-border bg-bg px-3">
            <Segmented size="xs" value={viewport} onChange={setViewport} options={[{ value: 'desktop', icon: 'desktop', title: t('Desktop') }, { value: 'tablet', icon: 'tablet', title: t('Tablet') }, { value: 'phone', icon: 'phone', title: t('Phone') }]} />
            {viewport !== 'desktop' && <span className="font-mono text-2xs text-faint">{WIDTHS[viewport]}</span>}
            <span className="flex-1" />
            <Segmented size="xs" value={mode} onChange={setMode} options={[{ value: 'light', icon: 'sun', title: t('Light') }, { value: 'dark', icon: 'moon', title: t('Dark') }]} />
          </div>
          <Preview viewport={viewport} desktop={px(theme.data.breakpoints?.lg) || 1024}>
            {(style) => <iframe ref={frame} src="/" title={t('Site Preview')} className={cn('border-0 bg-bg', viewport !== 'desktop' && 'border-x border-border')} style={style} onLoad={inject} />}
          </Preview>
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

/** The width of a color column (light, dark). */
const COLOR = 'w-[108px] shrink-0 max-sm:w-[92px]'

/** A duration typed in the inspector's control (`250ms`, `0.3s`) in seconds, as the page transition stores it. */
function seconds(value: unknown, fallback: number): number {
  const m = String(value ?? '').trim().match(/^(\d*\.?\d+)\s*(ms|s)?$/)
  if (!m) return fallback
  const n = m[2] === 's' ? Number(m[1]) : Number(m[1]) / 1000
  return Math.min(2, Math.max(0.05, Math.round(n * 1000) / 1000))
}

/**
 * One token: an optional sample, its name (click to copy `token:group.name`), its
 * value control — the same width in every group — and a menu. `below` adds a
 * specimen line, `after` a visual after the value; `wide` gives the value the row
 * (colors, with a column for each mode).
 */
function Row({ token, sample, after, below, wide, rename, remove, children }: { token: DraftToken; sample?: ReactNode; after?: ReactNode; below?: ReactNode; wide?: boolean; rename: (token: DraftToken) => void; remove: (token: DraftToken) => void; children: ReactNode }) {
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
          <button type="button" onClick={copy} title={token.name} className={cn('h-6 truncate rounded-xs text-left text-xs text-text hover:text-ink', wide ? 'min-w-0 flex-1' : sample !== undefined ? 'w-[84px] shrink-0 max-sm:w-16' : 'w-[120px] shrink-0 max-sm:w-20')}>
            {token.name}
          </button>
        </Tooltip>
        {/* On a phone the value takes the row; the bar beside it waits for a wider screen. */}
        {wide ? children : <div className="flex w-60 shrink-0 max-lg:w-52 max-sm:w-auto max-sm:min-w-0 max-sm:flex-1">{children}</div>}
        {!wide && <span className="flex min-w-0 flex-1 items-center max-sm:hidden">{after}</span>}
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

/** A row of a setting that is not a token, in a token row's columns. */
function Setting({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex min-h-9 items-center gap-2 border-b border-border py-1 last:border-b-0">
      <span className="w-[120px] shrink-0 truncate text-xs text-text">{label}</span>
      <div className="flex w-60 shrink-0 pr-8 max-lg:w-52">{children}</div>
    </div>
  )
}

/** A length as a bar, relative to the largest in its group. */
function Bar({ value, of }: { value: any; of: Token[] }) {
  const max = Math.max(...of.map((token) => px(token.value)), 1)
  return <span className="h-1.5 min-w-px rounded-full bg-border-strong" style={{ width: `${(px(value) / max) * 100}%` }} />
}

/** The font files the site serves: its families, a Google font to import, a .woff2 to upload. Font families above name them. */
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
    if (!(await confirm({ title: t('Remove {family}?', { family: name }), description: t('Font families that use it fall back to the system font.'), confirm: t('Remove'), danger: true }))) return
    try {
      await run('design/update', { fonts: fonts.filter((font) => font.family !== name) })
      onChanged()
    } catch (e) {
      toast.error(e)
    }
  }

  return (
    <Group title={t('Font Files')}>
      {families.length === 0 && <p className="m-0 py-3 text-xs text-faint">{t('None yet. The site uses system fonts.')}</p>}
      {families.map((name) => {
        const files = fonts.filter((font) => font.family === name)
        const used = tokens.filter((token) => Array.isArray(token.value) && token.value[0] === name).map((token) => token.name)
        return (
          <div key={name} className="group flex items-center gap-3 border-b border-border py-2.5">
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
      {/* A form, so Enter in the field adds the family. Served from this site: visitors never connect to Google. */}
      <form className="flex items-center gap-2 py-2.5" onSubmit={(e) => { e.preventDefault(); void importFamily() }}>
        <Fields bare className="min-w-0 flex-1" fields={[{ handle: 'family', type: 'text', label: t('Google Font'), size: 'sm', placeholder: t('Google Font, like Inter Tight'), icon: 'search' }]} values={{ family }} onChange={(_, v) => setFamily(v ?? '')} />
        <Button type="submit" size="sm" loading={busy} disabled={!family.trim()}>{t('Add')}</Button>
      </form>
      <Fields bare fields={[{ handle: 'file', type: 'json', component: 'site-file', label: t('Font File'), placeholder: t('Drop a .woff2 file or choose one'), accept: '.woff2,font/woff2' }]} values={{}} onChange={(_, file) => file && void upload(file)} />
    </Group>
  )
}

/** What people call the elements the design system styles. */
function elementLabel(selector: string): string {
  const names: Record<string, string> = {
    html: t('Page'), body: t('Body'), h1: t('Heading 1'), h2: t('Heading 2'), h3: t('Heading 3'), h4: t('Heading 4'), h5: t('Heading 5'), h6: t('Heading 6'),
    p: t('Paragraph'), a: t('Link'), 'a:hover': t('Link on Hover'), small: t('Small Text'), strong: t('Bold Text'), em: t('Italic Text'), mark: t('Highlight'),
    code: t('Code'), kbd: t('Keyboard Key'), pre: t('Code Block'), blockquote: t('Quote'), hr: t('Divider'), ul: t('Bulleted List'), ol: t('Numbered List'),
    li: t('List Item'), img: t('Image'), figcaption: t('Caption'), table: t('Table'), th: t('Table Header'), td: t('Table Cell'), button: t('Form Button'),
    input: t('Text Input'), textarea: t('Text Area'), select: t('Dropdown'), label: t('Form Label'), '::selection': t('Selected Text'), ':focus-visible': t('Focus Ring'),
    'h1 em': t('Italic in Heading 1'), 'h2 em': t('Italic in Heading 2'), 'h3 em': t('Italic in Heading 3'), 'h1 strong': t('Bold in Heading 1'), 'h2 strong': t('Bold in Heading 2'),
  }
  return names[selector] ?? selector
}

/** Short element-style keys from earlier versions, as the compiler reads them (DesignCompiler::SHORT). */
const SHORT: Record<string, string> = { font: 'fontFamily', size: 'fontSize', weight: 'fontWeight', leading: 'lineHeight', tracking: 'letterSpacing', transform: 'textTransform', decoration: 'textDecoration', underlineOffset: 'textUnderlineOffset', wrap: 'textWrap', style: 'fontStyle', features: 'fontFeatureSettings', background: 'backgroundColor', radius: 'borderRadius' }
const UNITLESS = new Set(['fontWeight', 'lineHeight', 'opacity', 'zIndex'])

/** Declarations with their keys spelled out (short keys from earlier versions become camelCase properties). */
function longhand(declarations: Declarations | undefined): Declarations {
  return Object.fromEntries(Object.entries(declarations ?? {}).map(([key, v]) => [SHORT[key] ?? key, v]))
}

/** Declarations as React styles, roughly as the compiler writes them (tokens → var()). */
function style(declarations: Declarations | undefined): CSSProperties {
  return Object.fromEntries(Object.entries(longhand(declarations)).map(([prop, v]) => [prop, typeof v === 'number' && !UNITLESS.has(prop) && v !== 0 ? `${v}px` : css('', v)]))
}

/** The controls for type: font, size, weight, spacing and color, each offering the site's tokens. */
function textFields(tokens: Token[]): FieldDef[] {
  const of = (group: string) => tokens.filter((token) => token.group === group)
  return [
    { handle: 'fontFamily', type: 'select', size: 'sm', label: t('Font'), placeholder: t('Inherit'), options: of('font').map((token) => ({ value: `token:${token.path}`, label: token.name })) },
    { handle: 'fontSize', type: 'text', component: 'blocks-value', label: t('Size'), group: 'text', placeholder: t('Inherit'), unit: 'rem', units: ['rem', 'px', 'em', '%'], step: 0.125 },
    { handle: 'fontWeight', type: 'select', size: 'sm', label: t('Weight'), placeholder: t('Inherit'), options: [...of('weight').map((token) => ({ value: `token:${token.path}`, label: `${token.name} · ${token.value}` })), ...['100', '200', '300', '400', '500', '600', '700', '800', '900'].map((w) => ({ value: w, label: w }))] },
    { handle: 'lineHeight', type: 'text', component: 'blocks-value', label: t('Line Height'), group: 'leading', unit: '', step: 0.05, placeholder: t('Inherit') },
    { handle: 'letterSpacing', type: 'text', component: 'blocks-value', label: t('Letter Spacing'), group: 'tracking', unit: 'em', units: ['em', 'px'], step: 0.005, placeholder: '0' },
    { handle: 'color', type: 'color', component: 'blocks-color', label: t('Color'), placeholder: t('Inherit') },
  ]
}

/** The controls for a box: fill, border, corners and padding. */
function boxFields(): FieldDef[] {
  return [
    { handle: 'backgroundColor', type: 'color', component: 'blocks-color', label: t('Fill'), placeholder: t('None') },
    {
      handle: 'border', type: 'group', component: 'blocks-group', label: t('Border'),
      fields: [
        { handle: 'borderWidth', type: 'text', component: 'blocks-value', label: t('Border Width'), placeholder: '0', min: 0, className: '!flex-none w-[64px]' },
        { handle: 'borderColor', type: 'color', component: 'blocks-color', label: t('Border Color'), placeholder: t('None') },
      ],
    },
    { handle: 'borderRadius', type: 'text', component: 'blocks-value', label: t('Radius'), group: 'radius', unit: 'px', units: ['px', 'rem', '%'], min: 0, placeholder: '0' },
    {
      handle: 'padding', type: 'group', component: 'blocks-group', label: t('Padding'),
      fields: [
        { handle: 'paddingBlock', type: 'text', component: 'blocks-value', label: t('Vertical Padding'), group: 'space', prefixIcon: 'b-arrows-out-line-vertical', unit: 'rem', placeholder: '0', min: 0 },
        { handle: 'paddingInline', type: 'text', component: 'blocks-value', label: t('Horizontal Padding'), group: 'space', prefixIcon: 'b-arrows-out-line-horizontal', unit: 'rem', placeholder: '0', min: 0 },
      ],
    },
  ]
}

/**
 * Declarations edited with controls: each field's handle is the property it sets (a
 * group's sub-fields are); a set row's label resets it. Properties no control covers
 * stay editable as CSS below, like the canvas inspector's CSS section.
 */
function StyleControls({ value, onChange, fields, tokens }: { value: Declarations | undefined; onChange: (next: Declarations) => void; fields: FieldDef[]; tokens: Token[] }) {
  const declarations = longhand(value)
  const keysOf = (field: FieldDef) => ((field.fields as FieldDef[] | undefined) ?? [field]).map((f) => f.handle)
  const known = new Set(fields.flatMap(keysOf))
  const write = (patch: Record<string, unknown>) => {
    const next: Declarations = { ...declarations }
    for (const [key, v] of Object.entries(patch)) {
      if (v === null || v === undefined || v === '') delete next[key]
      else next[key] = v as string | number
    }
    onChange(next)
  }
  const values: Record<string, unknown> = {}
  const defs = fields.map((field) => {
    const keys = keysOf(field)
    values[field.handle] = field.fields ? Object.fromEntries(keys.map((k) => [k, declarations[k] ?? null])) : declarations[field.handle] ?? null
    return { ...field, status: keys.some((k) => k in declarations) ? 'own' : undefined, reset: () => write(Object.fromEntries(keys.map((k) => [k, null]))) } as FieldDef
  })
  const rest = Object.fromEntries(Object.entries(declarations).filter(([key]) => !known.has(key)))

  return (
    <>
      <Fields
        layout="inline"
        fields={defs}
        values={values}
        onChange={(handle, v) => {
          const field = fields.find((f) => f.handle === handle)
          write(field?.fields ? Object.fromEntries(keysOf(field).map((k) => [k, (v as Record<string, unknown> | null)?.[k] ?? null])) : { [handle]: v })
        }}
      />
      <Fields
        bare
        fields={[{ handle: 'css', type: 'json', component: 'site-declarations', label: t('CSS'), mono: false, tokens: tokens.map((token) => `token:${token.path}`) }]}
        values={{ css: rest }}
        onChange={(_, d) => onChange({ ...Object.fromEntries(Object.entries(declarations).filter(([key]) => known.has(key))), ...(d ?? {}) })}
      />
    </>
  )
}

/** A row that opens to show its controls. */
function Disclosure({ open, onToggle, label, aside, menu, children }: { open: boolean; onToggle: () => void; label: ReactNode; aside?: ReactNode; menu?: ReactNode; children: ReactNode }) {
  return (
    <div className="group border-b border-border last:border-b-0">
      <div className="flex min-h-10 items-center gap-2">
        <button type="button" aria-expanded={open} onClick={onToggle} className="flex min-h-10 min-w-0 flex-1 items-center gap-1.5 rounded-xs text-left text-xs text-text outline-offset-[-2px] hover:text-ink">
          <Icon name="chevron-right" size={12} className={cn('shrink-0 text-faint transition-transform duration-120', open && 'rotate-90')} />
          <span className="min-w-0 flex-1 truncate">{label}</span>
          {aside}
        </button>
        {menu}
      </div>
      {open && <div className="pb-3 pl-[18px]">{children}</div>}
    </div>
  )
}

/** Elements (or prose elements) and their styles: a specimen each, its controls a click away. */
function StyleList({ title, value, onChange, options, tokens, vars }: { title: string; value: Record<string, Declarations>; onChange: (v: Record<string, Declarations>) => void; options: string[]; tokens: Token[]; vars: CSSProperties }) {
  const [open, setOpen] = useState<string | null>(null)
  const free = options.filter((option) => !(option in value))
  const fields = [...textFields(tokens), ...boxFields()]
  return (
    <Group title={title} action={<Menu trigger={<IconButton icon="plus" label={t('Add Element')} size="xs" />} items={free.map((option) => ({ label: elementLabel(option), onSelect: () => { onChange({ ...value, [option]: {} }); setOpen(option) } }))} className="max-h-72 overflow-auto" />}>
      {Object.keys(value).length === 0 && <p className="m-0 py-3 text-xs text-faint">{t('No element styles')}</p>}
      {Object.entries(value).map(([element, declarations]) => (
        <Disclosure
          key={element}
          open={open === element}
          onToggle={() => setOpen(open === element ? null : element)}
          label={elementLabel(element)}
          // A specimen on the site's own background, in the site's text color unless the element sets its own.
          aside={<span aria-hidden="true" className="grid h-6 w-9 shrink-0 place-items-center rounded-xs" style={{ ...vars, background: 'var(--color-background)', color: 'var(--color-foreground)' }}><span className="px-0.5" style={{ ...style(declarations), fontSize: 15, lineHeight: 1.2, margin: 0, paddingBlock: 0 }}>Aa</span></span>}
          menu={<Menu trigger={<IconButton icon="more" label={t('Element Actions')} size="xs" className="shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[popup-open]:opacity-100" />} items={[{ label: t('Remove'), icon: 'trash', danger: true, onSelect: () => onChange(Object.fromEntries(Object.entries(value).filter(([k]) => k !== element))) }]} />}
        >
          <StyleControls value={declarations} fields={fields} tokens={tokens} onChange={(next) => onChange({ ...value, [element]: next })} />
        </Disclosure>
      ))}
    </Group>
  )
}

/** Button variants (Primary, Secondary…) used by every Button block: each as it looks, its controls for each state a click away. */
function ButtonVariants({ value, onChange, tokens, vars }: { value: Buttons; onChange: (v: Buttons) => void; tokens: Token[]; vars: CSSProperties }) {
  const [open, setOpen] = useState<string | null>(null)
  const [state, setState] = useState<'base' | 'hover'>('base')
  const add = async () => {
    const asked = await prompt({ title: t('New Button Variant'), fields: [{ handle: 'name', type: 'text', label: t('Name'), placeholder: 'outline', mono: true, autofocus: true }], confirm: t('Add') })
    const name = String(asked?.name ?? '').toLowerCase().replace(/[^a-z0-9-]/g, '')
    if (name && !(name in value)) {
      onChange({ ...value, [name]: { base: {}, hover: {} } })
      setOpen(name)
    }
  }
  const states = (variant: Record<string, Declarations>) => ({ base: variant.base ?? (!('hover' in variant) ? (variant as unknown as Declarations) : {}), hover: variant.hover ?? {} })
  const sample: CSSProperties = { ...vars, display: 'inline-flex', alignItems: 'center', padding: '0.45em 0.9em', fontSize: 13, fontWeight: 'var(--font-weight-medium, 500)', lineHeight: 1.2, borderRadius: 'var(--radius-md, 0.5rem)', border: '1px solid transparent', fontFamily: 'var(--font-sans, inherit)', color: 'var(--color-foreground)', whiteSpace: 'nowrap' }
  const label = (name: string) => name.charAt(0).toUpperCase() + name.slice(1).replace(/-/g, ' ')
  const fields = [...boxFields(), ...textFields(tokens).filter((f) => ['fontWeight', 'fontSize', 'color'].includes(f.handle)).map((f) => (f.handle === 'color' ? { ...f, label: t('Text') } : f)), { handle: 'opacity', type: 'text', component: 'blocks-value', label: t('Opacity'), percent: true, step: 0.05, min: 0, max: 1, placeholder: '100' }]

  return (
    <Group title={t('Button Variants')} onAdd={add}>
      {Object.keys(value).length === 0 && <p className="m-0 py-3 text-xs text-faint">{t('No variants')}</p>}
      {Object.entries(value).map(([name, variant]) => {
        const { base, hover } = states(variant)
        return (
          <Disclosure
            key={name}
            open={open === name}
            onToggle={() => setOpen(open === name ? null : name)}
            label={<span className="inline-flex rounded-xs px-1.5 py-1" style={{ ...vars, background: 'var(--color-background)' }}><span style={{ ...sample, ...style(base), fontSize: 11 }}>{label(name)}</span></span>}
            menu={<Menu trigger={<IconButton icon="more" label={t('Variant Actions')} size="xs" className="shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[popup-open]:opacity-100" />} items={[{ label: t('Delete'), icon: 'trash', danger: true, onSelect: () => onChange(Object.fromEntries(Object.entries(value).filter(([k]) => k !== name))) }]} />}
          >
            {/* Each state as it looks; the one picked is the one the controls below edit. */}
            <div role="radiogroup" aria-label={t('State')} className="mb-2 grid grid-cols-2 gap-2">
              {(['base', 'hover'] as const).map((key) => (
                <button
                  key={key}
                  type="button"
                  role="radio"
                  aria-checked={state === key}
                  onClick={() => setState(key)}
                  className={cn('flex h-12 items-center justify-center rounded-sm border outline-offset-2 transition-[border-color,box-shadow] duration-120', state === key ? 'border-focus ring-1 ring-focus' : 'border-border hover:border-border-strong')}
                  style={{ ...vars, background: 'var(--color-background)' }}
                >
                  <span style={{ ...sample, ...style(base), ...(key === 'hover' ? style(hover) : {}) }}>{key === 'base' ? t('Default') : t('Hover')}</span>
                </button>
              ))}
            </div>
            <StyleControls key={state} value={state === 'base' ? base : hover} fields={fields} tokens={tokens} onChange={(next) => onChange({ ...value, [name]: { ...states(variant), [state]: next } })} />
          </Disclosure>
        )
      })}
    </Group>
  )
}

/** Custom CSS and JavaScript (content/design/custom.css|js), saved with everything else. */
function Code({ css, js, onChange }: { css: string; js: string; onChange: (code: { css?: string; js?: string }) => void }) {
  const allowed = can('site.code')
  const field = (handle: 'css' | 'js', label: string, placeholder: string, value: string) => (
    <Group title={label}>
      <div className="py-2.5">
        <Fields bare fields={[{ handle, type: 'code', label, rows: 14, placeholder }]} values={{ [handle]: value }} onChange={(_, v) => onChange({ [handle]: v ?? '' })} disabled={!allowed} />
      </div>
    </Group>
  )
  return (
    <>
      {field('css', t('Custom CSS'), '.cms-prose figure { margin-block: 2rem; }', css)}
      {field('js', t('Custom JavaScript'), "document.addEventListener('cms:alpine', (e) => …)", js)}
      {!allowed && <p className="m-0 text-2xs text-warning">{t('Editing code needs the “Edit custom CSS and JavaScript” permission.')}</p>}
    </>
  )
}
