<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $site }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $colors['background'] }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{{ $colors['text'] }};-webkit-font-smoothing:antialiased">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $colors['background'] }}">
  <tr><td align="center" style="padding:32px 16px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">
      <tr><td style="padding:0 4px 16px;font-size:14px;font-weight:600;color:{{ $colors['text'] }}">{{ $site }}</td></tr>
      <tr><td style="background:{{ $colors['card'] }};border:1px solid {{ $colors['line'] }};border-radius:10px;padding:28px;font-size:15px;line-height:1.6">
        {!! $body !!}
      </td></tr>
      <tr><td style="padding:16px 4px;font-size:12px;line-height:1.5;color:{{ $colors['muted'] }}">
        {{ $footer }}@if ($url) · <a href="{{ $url }}" style="color:{{ $colors['muted'] }}">{{ preg_replace('#^https?://#', '', $url) }}</a>@endif
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
