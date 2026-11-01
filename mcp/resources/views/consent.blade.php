<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $text['title'] }}</title>
<style>
:root{--bg:#fff;--sunken:#f4f4f3;--border:#e7e7e5;--ink:#111;--text:#1c1c1a;--muted:#6b6b67;--faint:#9c9c97}
@media (prefers-color-scheme:dark){:root{--bg:#0e0e0d;--sunken:#1b1b1a;--border:#262624;--ink:#f5f5f3;--text:#e8e8e5;--muted:#9a9a95;--faint:#6b6b66}}
*{box-sizing:border-box}body{margin:0;background:var(--sunken);color:var(--text);font:14px/1.5 "Geist",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
main{max-width:420px;margin:12vh auto;padding:0 20px}
.card{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:28px}
h1{margin:0 0 6px;font-size:20px;letter-spacing:-.015em;color:var(--ink)}p{margin:0;color:var(--muted)}
ul{margin:18px 0;padding:0;list-style:none;display:grid;gap:10px}li{display:flex;gap:10px;align-items:flex-start}
li span:first-child{flex:none;width:18px;height:18px;border-radius:50%;background:var(--sunken);display:grid;place-items:center;font-size:11px;color:var(--muted)}
label{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-top:8px;cursor:pointer}
label input{margin-top:3px}label strong{display:block;color:var(--ink);font-weight:500}label small{color:var(--faint)}
.actions{display:flex;gap:8px;margin-top:22px}
button{flex:1;height:40px;border-radius:7px;font:inherit;font-weight:600;cursor:pointer;border:1px solid var(--border);background:var(--bg);color:var(--text)}
button.primary{background:var(--ink);border-color:var(--ink);color:var(--bg)}
.who{margin-top:16px;font-size:12px;color:var(--faint)}
code{font-family:ui-monospace,monospace;font-size:12px}
</style>
</head>
<body>
<main>
  <div class="card">
    <h1>{{ $text['heading'] }}</h1>
    <p>{{ $text['as'] }}</p>
    <ul>
      @foreach ($text['can'] as $line)
        <li><span>✓</span><span>{{ $line }}</span></li>
      @endforeach
    </ul>
    <form method="post" action="/oauth/authorize">
      @csrf
      @foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'] as $key)
        <input type="hidden" name="{{ $key }}" value="{{ $params[$key] }}">
      @endforeach
      <label><input type="radio" name="access" value="full" checked><span><strong>{{ $text['full'] }}</strong><small>{{ $text['fullHint'] }}</small></span></label>
      <label><input type="radio" name="access" value="read"><span><strong>{{ $text['read'] }}</strong><small>{{ $text['readHint'] }}</small></span></label>
      <div class="actions">
        <button type="submit" name="decision" value="deny">{{ $text['cancel'] }}</button>
        <button type="submit" name="decision" value="approve" class="primary">{{ $text['connect'] }}</button>
      </div>
    </form>
    <p class="who">{{ $text['returns'] }}</p>
  </div>
</main>
</body>
</html>
