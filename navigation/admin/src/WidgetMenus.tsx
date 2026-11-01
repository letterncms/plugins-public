import { can, navigate, plural, t, useAbility } from '@letterncms/sdk'
import { Button, Widget, WidgetRow } from '@letterncms/ui'

interface Menu {
  handle: string
  title: string
  count: number
}

/** Home-screen widget: the site's menus, each with its number of links. */
export default function WidgetMenus() {
  const menus = useAbility<Menu[]>('navigation/list')

  return (
    <Widget
      query={menus}
      action={{ label: t('All Menus'), to: '/navigation' }}
      empty={
        menus.data?.length === 0 && {
          icon: 'list-tree',
          title: t('No menus yet'),
          action: can('navigation.manage') && <Button size="sm" icon="plus" onClick={() => navigate('/navigation')}>{t('New Menu')}</Button>,
        }
      }
    >
      {menus.data?.slice(0, 6).map((m) => (
        <WidgetRow key={m.handle} to={`/navigation/${m.handle}`} icon="list-tree" title={<span className="font-medium">{m.title}</span>} meta={plural(m.count, '{count} link', '{count} links')} />
      ))}
    </Widget>
  )
}
