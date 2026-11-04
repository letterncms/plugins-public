import { registry, t } from '@letterncms/sdk'
import { Fields, cn } from '@letterncms/ui'
import { Section, inputClass } from '../controls'
import { parseGradient } from '../fields/style'
import type { StyleApi } from '../useStyle'
import { StyleFields } from './StyleFields'

const CLEAR = { backgroundImage: null, backgroundSize: null, backgroundPosition: null, backgroundRepeat: null }

/** Background color, gradient or image, and text clipping (gradient text). */
export function Fill({ s }: { s: StyleApi }) {
  const image = s.value('backgroundImage')
  const kind = !image ? 'none' : parseGradient(image) ? 'gradient' : 'image'
  const media = !!registry.fields.get('media')
  const mediaId = image?.match(/^media:([0-9a-z]{26})$/i)?.[1]
  const any = s.value('backgroundColor') || image

  return (
    <Section title={t('Fill')} empty={!any}>
      <StyleFields
        s={s}
        fields={[
          { handle: 'backgroundColor', type: 'color', component: 'blocks-color', label: t('Color') },
          {
            handle: 'kind',
            type: 'select',
            display: 'segmented',
            block: true,
            size: 'sm',
            label: t('Image'),
            options: [{ value: 'none', label: t('None') }, { value: 'gradient', icon: 'b-gradient', title: t('Gradient') }, { value: 'image', icon: 'b-image', title: t('Image') }],
            keys: ['backgroundImage'],
            reset: () => s.write(CLEAR),
            get: () => kind,
            set: (v) => {
              if (v === 'gradient') s.set('backgroundImage', 'linear-gradient(180deg, token:color.primary, token:color.background)')
              else if (v === 'image') s.write({ backgroundImage: 'none', backgroundSize: 'cover', backgroundPosition: 'center' })
              else s.write(CLEAR)
            },
          },
        ]}
      />
      {kind === 'gradient' && <StyleFields s={s} bare fields={[{ handle: 'backgroundImage', type: 'text', component: 'blocks-gradient', className: 'items-start', label: t('Gradient') }]} />}
      {kind === 'image' && media && (
        <div className="rounded-md bg-active/60 p-1.5 text-xs">
          <Fields bare fields={[{ handle: 'bg', type: 'media', label: t('Image'), max: 1, accept: 'image/*' }]} values={{ bg: mediaId ? [mediaId] : [] }} onChange={(_, v: string[]) => s.set('backgroundImage', v?.[0] ? `media:${v[0]}` : 'none')} />
        </div>
      )}
      <StyleFields
        s={s}
        fields={[
          kind === 'image' && !media && { handle: 'mediaId', type: 'text', component: 'blocks-value', label: t('Media ID'), unit: '', get: () => mediaId, set: (v) => s.set('backgroundImage', v ? `media:${v}` : null) },
          kind === 'image' && { handle: 'backgroundSize', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Size'), options: [{ value: 'cover', label: t('Cover') }, { value: 'contain', label: t('Contain') }, { value: 'auto', label: t('Original') }] },
          kind === 'image' && { handle: 'backgroundPosition', type: 'json', component: 'blocks-origin', label: t('Position'), className: 'items-start py-0.5 [&>label]:leading-6' },
          kind === 'image' && { handle: 'backgroundRepeat', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Repeat'), placeholder: 'repeat', options: [{ value: 'no-repeat', label: t('Once') }, { value: 'repeat', label: t('Tile') }, { value: 'repeat-x', icon: 'b-arrows-out-line-horizontal', title: t('Tile Across') }, { value: 'repeat-y', icon: 'b-arrows-out-line-vertical', title: t('Tile Down') }] },
          kind === 'image' && { handle: 'backgroundAttachment', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Scrolling'), options: [{ value: 'scroll', label: t('With Page') }, { value: 'fixed', label: t('Fixed') }], get: () => s.value('backgroundAttachment') ?? 'scroll', set: (v) => s.set('backgroundAttachment', v === 'fixed' ? 'fixed' : null) },
          kind !== 'none' && { handle: 'backgroundClip', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Clip'), options: [{ value: 'border-box', label: t('Box') }, { value: 'text', label: t('Text') }], get: () => s.value('backgroundClip') ?? 'border-box', set: (v) => s.write({ backgroundClip: v === 'text' ? 'text' : null, color: v === 'text' ? 'transparent' : null }) },
        ]}
      />
      {image && kind === 'image' && !mediaId && image !== 'none' && <p className={cn(inputClass, 'h-auto py-1 font-mono')}>{image}</p>}
    </Section>
  )
}
