---
id: 01m5x7tw1j65b1ek9kq2bz797w
title: Page
status: published
position: 2
description: 'Header, the page’s own blocks, footer.'
collections:
  - pages
---

::: pattern {"id":"t-header","props":{"ref":"header"}}
:::

::: box {"id":"t-main","props":{"tag":"main"},"label":"Main","style":{"base":{"minHeight":"60vh"}},"anchor":"main"}
:::: outlet {"id":"t-outlet"}
::::
:::

::: pattern {"id":"t-footer","props":{"ref":"footer"}}
:::
