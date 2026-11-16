// Visitors mode's script, in the foot of every published page (see Snippet). It stores nothing in the
// browser. Its settings are the script element's attributes, so every page and every site runs the same
// code and the content security policy needs one hash for it.
//   data-endpoint  where it reports
//   data-consent   "1": wait for the consent signal (window.cmsConsent.analytics, or a cms:consent event)
//   data-auto      "1": also count outbound links, downloads and forms
//   data-status    "404": this is the page for an address that doesn't exist
// Sites call window.cmsAnalytics.track(name, props) for an event of their own, or give an element
// data-cms-event="Name" (and data-cms-event-<property>="value") to count clicks on it.
(function (w, d, n) {
  var s = d.currentScript
  var url = s && s.getAttribute('data-endpoint')
  if (!url || n.webdriver || n.doNotTrack == '1' || w.doNotTrack == '1' || n.globalPrivacyControl) return
  var on = false
  var waiting = []
  var earlier = w.cmsAnalytics && w.cmsAnalytics.q

  function send(body) {
    body.p = location.pathname
    var b = JSON.stringify(body)
    try {
      if (!(n.sendBeacon && n.sendBeacon(url, b))) fetch(url, { method: 'POST', body: b, keepalive: true })
    } catch (e) {}
  }

  function track(name, props) {
    if (!name) return
    var event = { t: 'event', n: String(name), o: props || {} }
    on ? send(event) : waiting.push(event)
  }

  w.cmsAnalytics = { track: track }
  if (earlier) for (var k = 0; k < earlier.length; k++) track(earlier[k][0], earlier[k][1])

  function go() {
    if (on) return
    on = true
    var q = new URLSearchParams(location.search)
    var zone
    try { zone = Intl.DateTimeFormat().resolvedOptions().timeZone } catch (e) {}
    send({
      t: 'pageview', r: d.referrer, w: w.innerWidth, z: zone, f: s.getAttribute('data-status') === '404' ? 404 : 0,
      s: q.get('utm_source') || q.get('ref') || '', m: q.get('utm_medium') || '', c: q.get('utm_campaign') || '',
      g: q.has('gclid') || q.has('gbraid') || q.has('wbraid') || q.has('msclkid') ? 1 : 0
    })
    for (var i = 0; i < waiting.length; i++) send(waiting[i])
    waiting = []
    engagement()
    if (s.getAttribute('data-auto') === '1') automatic()
  }

  // Time in front of the visitor (visible and focused), how far down they read, and the page's speed for them.
  function engagement() {
    var engaged = 0, since = 0, depth = 0, lcp, inp = 0, cls = 0, shifts = 0, first = 0, last = 0, sent = ''
    var background = d.visibilityState === 'hidden'
    // Layout shifts only where the browser measures them: elsewhere none measured is not none.
    var shifting = (w.PerformanceObserver && PerformanceObserver.supportedEntryTypes || []).indexOf('layout-shift') >= 0
    function resume() { if (!since && d.visibilityState === 'visible') since = Date.now() }
    function pause() { if (since) { engaged += Date.now() - since; since = 0 } }
    function scrolled() {
      var height = Math.max(d.documentElement.scrollHeight, d.body ? d.body.scrollHeight : 0)
      depth = Math.max(depth, height > 0 ? Math.min(100, Math.round((w.scrollY + w.innerHeight) / height * 100)) : 100)
    }
    function report() {
      pause()
      var now = [Math.floor(engaged / 3000), depth, lcp, inp, Math.round(cls * 1000)].join()
      if (now !== sent && (engaged >= 3000 || depth > 0)) {
        sent = now
        send({ t: 'engagement', e: engaged, d: depth, l: lcp, i: inp || undefined, x: shifting ? Math.round(cls * 1000) : undefined })
      }
    }
    function observe(type, each, options) {
      try {
        var o = new PerformanceObserver(function (list) { list.getEntries().forEach(each) })
        o.observe(Object.assign({ type: type, buffered: true }, options))
      } catch (e) {}
    }
    if (!background) observe('largest-contentful-paint', function (e) { lcp = Math.round(e.startTime) })
    observe('layout-shift', function (e) {
      if (e.hadRecentInput) return
      if (shifts && e.startTime - last < 1000 && e.startTime - first < 5000) shifts += e.value
      else { shifts = e.value; first = e.startTime }
      last = e.startTime
      cls = Math.max(cls, shifts)
    })
    observe('event', function (e) { if (e.interactionId) inp = Math.max(inp, Math.round(e.duration)) }, { durationThreshold: 40 })
    observe('first-input', function (e) { inp = Math.max(inp, Math.round(e.duration)) })
    resume()
    scrolled()
    var timer
    w.addEventListener('scroll', function () { clearTimeout(timer); timer = setTimeout(scrolled, 100) }, { passive: true })
    w.addEventListener('load', scrolled)
    d.addEventListener('visibilitychange', function () { d.visibilityState === 'hidden' ? report() : resume() })
    w.addEventListener('blur', report)
    w.addEventListener('focus', resume)
    w.addEventListener('pagehide', report)
  }

  function automatic() {
    var files = /\.(pdf|docx?|xlsx?|pptx?|csv|txt|rtf|odt|ods|odp|key|pages|numbers|zip|rar|7z|gz|tgz|dmg|exe|msi|pkg|apk|mp3|wav|m4a|mp4|mov|avi|mkv|webm|epub|ics|vcf)$/i
    function clicked(e) {
      var a = e.target && e.target.closest && e.target.closest('a[href]')
      if (!a || a.closest('[data-cms-event]') || e.type === 'auxclick' && e.button !== 1) return
      var u
      try { u = new URL(a.href, location.href) } catch (x) { return }
      if (!/^https?:$/.test(u.protocol)) return
      if (u.host !== location.host) track('Outbound Link', { url: u.origin + u.pathname })
      else if (files.test(u.pathname)) track('File Download', { url: u.pathname })
    }
    d.addEventListener('click', clicked, true)
    d.addEventListener('auxclick', clicked, true)
    d.addEventListener('submit', function (e) {
      var f = e.target
      if (f && f.tagName === 'FORM' && !f.closest('[data-cms-event]')) track('Form Sent', { form: f.getAttribute('name') || f.id || f.getAttribute('aria-label') || location.pathname })
    }, true)
  }

  // An element of the site's that names an event: data-cms-event="Sign Up" data-cms-event-plan="Pro".
  d.addEventListener('click', function (e) {
    var el = e.target && e.target.closest && e.target.closest('[data-cms-event]')
    if (!el) return
    var props = {}
    for (var i = 0; i < el.attributes.length; i++) {
      var at = el.attributes[i]
      if (at.name.indexOf('data-cms-event-') === 0) props[at.name.slice(15)] = at.value
    }
    track(el.getAttribute('data-cms-event'), props)
  }, true)

  if (s.getAttribute('data-consent') !== '1') go()
  else if (w.cmsConsent && w.cmsConsent.analytics === true) go()
  else d.addEventListener('cms:consent', function f(e) {
    if (e.detail && e.detail.analytics === true) { d.removeEventListener('cms:consent', f); go() }
  })
})(window, document, navigator)
