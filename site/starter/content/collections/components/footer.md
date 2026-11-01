---
id: 01m5x7ty7mtr92dn0arwwwc1z6
title: Footer
status: published
position: 0
synced: true
category: Components
description: 'Site name, footer menu and tagline.'
---

::: box {"id":"ftr","props":{"tag":"footer"},"label":"Footer","style":{"base":{"marginTop":"token:space.24","borderTop":"1px solid token:color.border"}}}
:::: box {"id":"ftr-inner","style":{"base":{"width":"100%","maxWidth":"token:container.content","marginInline":"auto","paddingInline":"token:space.gutter","display":"flex","flexWrap":"wrap","alignItems":"center","justifyContent":"space-between","gap":"token:space.4","paddingBlock":"token:space.12","fontSize":"token:text.sm","color":"token:color.muted"}}}
::::: paragraph {"id":"ftr-copy","bind":{"text":"© {site.year} {site.name}"}}
:::::

::::: repeat {"id":"ftr-nav","props":{"source":"menu","menu":"footer","tag":"ul"},"label":"Footer menu","style":{"base":{"display":"flex","flexWrap":"wrap","gap":"token:space.6"}}}
:::::: box {"id":"ftr-item","props":{"tag":"li"}}
::::::: link {"id":"ftr-link","style":{"hover":{"color":"token:color.foreground"}},"bind":{"label":"item.label","url":"item.url"}}
:::::::
::::::
:::::

::::: paragraph {"id":"ftr-tagline","bind":{"text":"globals.footer.tagline","visible":"globals.footer.tagline"}}
:::::
::::
:::
