---
id: 01m5x7tz7b6n961bpt19rdef1k
title: Header
status: published
position: 1
synced: true
category: Components
description: 'Site name, main menu, sign in or your account once the site has public accounts, and a menu button on phones.'
---

::: box {"id":"hdr","props":{"tag":"header"},"label":"Header","style":{"base":{"position":"sticky","top":"0","zIndex":40,"backgroundColor":"token:color.background/85","backdropFilter":"saturate(1.6) blur(14px)","borderBottom":"1px solid transparent","transition":"border-color 200ms ease, background-color 200ms ease"}},"logic":{"data":{"open":false},"on":[{"event":"keydown","mods":["window"],"key":"escape","actions":[{"do":"set","var":"open","value":"false"}]}],"states":{"open":"open"},"behaviors":{"scroll":{"threshold":8,"hide":false}}}}
:::: box {"id":"hdr-bar","label":"Bar","style":{"base":{"width":"100%","maxWidth":"token:container.content","marginInline":"auto","paddingInline":"token:space.gutter","display":"flex","alignItems":"center","gap":"token:space.8","height":"64px"}}}
::::: link {"id":"hdr-logo","props":{"url":"/"},"label":"Logo","style":{"base":{"fontWeight":"token:weight.semibold","letterSpacing":"token:tracking.tight","fontSize":"1.0625rem"}},"bind":{"label":"site.name"}}
:::::

::::: box {"id":"hdr-nav","props":{"tag":"nav"},"label":"Main menu","style":{"base":{"marginLeft":"auto"},"mobile":{"display":"none"}},"attrs":{"aria-label":"Main"}}
:::::: repeat {"id":"hdr-links","props":{"source":"menu","menu":"main","tag":"ul"},"style":{"base":{"display":"flex","alignItems":"center","gap":"token:space.6","fontSize":"token:text.sm"}}}
::::::: box {"id":"hdr-item","props":{"tag":"li"}}
:::::::: link {"id":"hdr-link","style":{"base":{"color":"token:color.muted","transition":"color 150ms ease"},"hover":{"color":"token:color.foreground"},"is-active":{"color":"token:color.foreground","fontWeight":"token:weight.medium"}},"bind":{"label":"item.label","url":"item.url","state:active":"item.active"}}
::::::::
:::::::
::::::
:::::

::::: box {"id":"hdr-account","label":"Account","style":{"base":{"display":"flex","alignItems":"center"},"mobile":{"display":"none"}},"bind":{"visible":"accounts"}}
:::::: link {"id":"hdr-sign-in","style":{"base":{"color":"token:color.muted","fontSize":"token:text.sm","transition":"color 150ms ease"},"hover":{"color":"token:color.foreground"}},"bind":{"label":"accounts.sign_in.label","url":"accounts.sign_in.url","hidden":"account"}}
::::::

:::::: link {"id":"hdr-my-account","style":{"base":{"color":"token:color.muted","fontSize":"token:text.sm","transition":"color 150ms ease"},"hover":{"color":"token:color.foreground"}},"bind":{"label":"accounts.account.label","url":"accounts.account.url","visible":"account"}}
::::::
:::::

::::: box {"id":"hdr-toggle","props":{"tag":"button"},"label":"Menu button","style":{"base":{"display":"none","marginLeft":"auto","width":"40px","height":"40px","placeItems":"center","borderRadius":"token:radius.md"},"mobile":{"display":"grid"},"hover":{"backgroundColor":"token:color.surface"}},"attrs":{"aria-label":"Menu"},"logic":{"on":[{"event":"click","actions":[{"do":"toggle","var":"open"},{"do":"if","test":"open","then":[{"do":"play","node":"hdr-panel-links","motion":"open","mode":"restart"}],"else":[]}]}],"attrs":{"aria-expanded":"open"}}}
:::::: icon {"id":"hdr-icon-open","props":{"icon":"list"},"style":{"base":{"width":"22px","height":"22px"}},"logic":{"show":"!open"}}
::::::

:::::: icon {"id":"hdr-icon-close","props":{"icon":"x"},"style":{"base":{"width":"22px","height":"22px"}},"logic":{"show":"open"}}
::::::
:::::
::::

:::: box {"id":"hdr-panel","props":{"tag":"nav"},"label":"Phone menu","style":{"base":{"display":"none"},"mobile":{"display":"block","borderTop":"1px solid token:color.border"}},"attrs":{"aria-label":"Main"},"logic":{"show":"open","transition":"collapse"}}
::::: repeat {"id":"hdr-panel-links","props":{"source":"menu","menu":"main","tag":"ul"},"style":{"base":{"width":"100%","maxWidth":"token:container.content","marginInline":"auto","paddingInline":"token:space.gutter","paddingBlock":"token:space.4"}},"motion":[{"id":"open","trigger":{"type":"event"},"steps":[{"target":"children","from":{"opacity":0,"y":10},"duration":0.4,"ease":"power3.out","stagger":0.04}]}]}
:::::: box {"id":"hdr-panel-item","props":{"tag":"li"}}
::::::: link {"id":"hdr-panel-link","style":{"base":{"display":"block","paddingBlock":"token:space.3","fontSize":"token:text.lg"}},"bind":{"label":"item.label","url":"item.url"}}
:::::::
::::::
:::::

::::: box {"id":"hdr-panel-account","label":"Account","style":{"base":{"width":"100%","maxWidth":"token:container.content","marginInline":"auto","paddingInline":"token:space.gutter","paddingBottom":"token:space.4"}},"bind":{"visible":"accounts"}}
:::::: link {"id":"hdr-panel-sign-in","style":{"base":{"display":"block","paddingBlock":"token:space.3","fontSize":"token:text.lg"}},"bind":{"label":"accounts.sign_in.label","url":"accounts.sign_in.url","hidden":"account"}}
::::::

:::::: link {"id":"hdr-panel-my-account","style":{"base":{"display":"block","paddingBlock":"token:space.3","fontSize":"token:text.lg"}},"bind":{"label":"accounts.account.label","url":"accounts.account.url","visible":"account"}}
::::::
:::::
::::
:::
