{{--
    /admin (Filament 運営) 配下の運用者向け中立 error layout。
    顧客向けマーケ文言・ブランドロゴを出さず、運用者向けの中立トーンに分離する。
    customer-facing の errors.layout とは独立。Vite/Inertia 非依存で自己完結。
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') | 管理パネル</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Hiragino Kaku Gothic ProN", "Hiragino Sans", "Yu Gothic", "Meiryo", system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            color: #1f2937;
            padding: 24px;
        }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 40px;
            max-width: 440px;
            width: 100%;
            text-align: center;
        }
        .code { font-size: 48px; font-weight: 700; color: #374151; line-height: 1; margin-bottom: 12px; }
        .title { font-size: 18px; font-weight: 700; margin-bottom: 8px; color: #111827; }
        .message { font-size: 14px; color: #6b7280; margin-bottom: 28px; line-height: 1.7; }
        .back {
            display: inline-block;
            background: #374151;
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 8px;
        }
        .back:hover { opacity: .9; }
    </style>
</head>
<body>
    <div class="card">
        <div class="code">@yield('code')</div>
        <h1 class="title">@yield('title')</h1>
        <p class="message">@yield('message')</p>
        <a class="back" href="/{{ $adminPath ?? 'admin' }}">管理パネルに戻る</a>
    </div>
</body>
</html>
