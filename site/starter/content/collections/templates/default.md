---
id: 01m5x7ttztbtcwvfhg4p1ygzn2
title: Default
status: published
position: 1
description: 'Used for anything without its own template.'
---

::: pattern {"id":"t-header","props":{"ref":"header"}}
:::

::: box {"id":"t-main","props":{"tag":"main"},"label":"Main","style":{"base":{"minHeight":"60vh"}},"anchor":"main"}
:::: outlet {"id":"t-outlet"}
::::
:::

::: pattern {"id":"t-footer","props":{"ref":"footer"}}
:::
