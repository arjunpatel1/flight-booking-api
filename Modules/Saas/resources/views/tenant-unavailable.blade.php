<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $tenant->name }} · Access unavailable</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f5f6fa; color: #263244; }
        main { width: min(560px, 100%); padding: 40px; border: 1px solid #e1e5ec; border-radius: 20px; background: white; box-shadow: 0 18px 50px rgba(25, 36, 55, .08); text-align: center; }
        .mark { width: 64px; height: 64px; margin: 0 auto 20px; display: grid; place-items: center; border-radius: 18px; background: #fff0e3; color: #f97316; font-size: 28px; font-weight: 800; }
        h1 { margin: 0; font-size: clamp(24px, 5vw, 32px); }
        p { margin: 12px auto 0; max-width: 430px; color: #667085; line-height: 1.65; }
        .status { display: inline-flex; margin-top: 22px; padding: 8px 12px; border-radius: 999px; background: #fff1f0; color: #c4322b; font-size: 13px; font-weight: 700; }
        .help { margin-top: 26px; padding-top: 22px; border-top: 1px solid #edf0f4; font-size: 14px; color: #667085; }
    </style>
</head>
<body>
<main>
    <div class="mark">{{ strtoupper(mb_substr($tenant->name, 0, 1)) }}</div>
    <h1>{{ $tenant->name }}</h1>
    <p>{{ $message }}</p>
    <span class="status">Restaurant access suspended</span>
    <div class="help">Restaurant owners can contact their NexDine administrator to restore access.</div>
</main>
</body>
</html>
