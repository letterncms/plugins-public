import { registry } from '@letterncms/sdk'
import { ColorField } from '../color'
import { BindField } from '../content/BindField'
import { GroupField, PairsField } from './composite'
import { EaseField, ValueField } from './controls'
import { NodeField, NodesField } from './nodes'
import { ClipField } from './clip'
import { ConditionsField } from './conditions'
import { FiltersField } from './filters'
import { InlineTextField } from './inlineText'
import { ShadowsField } from './shadows'
import { SpacingField } from './spacing'
import { AlignField, GradientField, OriginField } from './style'

/** The inspector's field components; panels describe their controls as field definitions using these keys. */
export function registerFields() {
  const fields = {
    'blocks-value': ValueField,
    'blocks-ease': EaseField,
    'blocks-color': ColorField,
    'blocks-bind': BindField,
    'blocks-node': NodeField,
    'blocks-nodes': NodesField,
    'blocks-group': GroupField,
    'blocks-pairs': PairsField,
    'blocks-shadows': ShadowsField,
    'blocks-gradient': GradientField,
    'blocks-align': AlignField,
    'blocks-origin': OriginField,
    'blocks-spacing': SpacingField,
    'blocks-filters': FiltersField,
    'blocks-clip': ClipField,
    'blocks-conditions': ConditionsField,
    'blocks-inline-text': InlineTextField,
  }
  for (const [key, component] of Object.entries(fields)) registry.fields.set(key, component as any)
}
