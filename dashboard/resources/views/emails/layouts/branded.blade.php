<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $subject ?? 'VerifySky Message' }}</title>
</head>
<body style="margin:0;background:#0f1723;color:#d7e1f5;font-family:Arial,sans-serif;">
  <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
    <div style="border:1px solid rgba(255,255,255,0.08);border-radius:20px;overflow:hidden;background:#171c26;">
      <div style="padding:20px 24px;background:linear-gradient(135deg,#0f1723 0%,#1e293b 100%);border-bottom:1px solid rgba(255,255,255,0.08);">
        <div style="font-size:11px;letter-spacing:0.24em;text-transform:uppercase;color:#fcb900;font-weight:700;">VerifySky</div>
        <div style="margin-top:10px;font-size:24px;line-height:1.2;font-weight:800;color:#ffffff;">{{ $headline ?? 'Operational Update' }}</div>
      </div>
      <div style="padding:24px;">
        @yield('content')
      </div>
    </div>
    <div style="padding:14px 8px 0;color:#94a3b8;font-size:12px;line-height:1.7;">
      Sent by VerifySky.
    </div>
  </div>
</body>
</html>
