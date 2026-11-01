---
id: 01m5x7tx4c1h9c7js9rt8ke54y
title: Article
status: published
position: 3
description: 'A narrow article with date, title, body and tags.'
collections:
  - posts
---

::: pattern {"id":"p-header","props":{"ref":"header"}}
:::

::: box {"id":"p-main","props":{"tag":"main"},"label":"Article","style":{"base":{"maxWidth":"token:container.prose","marginInline":"auto","paddingInline":"token:space.gutter","paddingBlock":"token:space.section"}},"anchor":"main"}
:::: box {"id":"p-head","props":{"tag":"header"},"style":{"base":{"display":"flex","flexDirection":"column","gap":"token:space.4","marginBottom":"token:space.12"}}}
::::: paragraph {"id":"p-date","props":{"tag":"time"},"style":{"base":{"fontSize":"token:text.sm","color":"token:color.muted"}},"bind":{"text":"entry.date|date:F j, Y"}}
:::::

::::: heading {"id":"p-title","props":{"level":"1"},"bind":{"text":"entry.title"}}
:::::

::::

:::: outlet {"id":"p-body","props":{"wrap":true},"class":"cms-prose"}
::::

:::: repeat {"id":"p-tags","props":{"source":"list","path":"entry.tags","tag":"ul"},"style":{"base":{"display":"flex","flexWrap":"wrap","gap":"token:space.2","marginTop":"token:space.12"}}}
::::: paragraph {"id":"p-tag","props":{"tag":"li"},"style":{"base":{"paddingInline":"0.75rem","paddingBlock":"0.25rem","borderRadius":"token:radius.full","backgroundColor":"token:color.surface","fontSize":"token:text.sm","color":"token:color.muted"}},"bind":{"text":"{item}"}}
:::::
::::
:::

::: pattern {"id":"p-footer","props":{"ref":"footer"}}
:::
