{{--
    法的ページ (利用規約 / プライバシーポリシー / 特商法表記) の自己完結スタブ layout。
    Route::view で返す薄い Blade のため、認証前提の共有データ・Inertia・Vite に依存しない。
    文面は未確定のプレースホルダなので noindex (meta + route 側の X-Robots-Tag で二重防御)。
    アプリ初期化時に正式な文面へ差し替え、公開する場合は noindex を外すこと。
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') | {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Hiragino Kaku Gothic ProN", "Hiragino Sans", "Yu Gothic", "Meiryo", system-ui, sans-serif;
            background: #f9fafb;
            color: #1f2937;
            line-height: 1.8;
            padding: 48px 24px;
        }
        .container { max-width: 720px; margin: 0 auto; }
        h1 { font-size: 28px; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .updated { font-size: 13px; color: #6b7280; margin-bottom: 32px; }
        h2 { font-size: 18px; font-weight: 700; color: #111827; margin: 28px 0 8px; }
        p, li { font-size: 14px; color: #374151; }
        dl { border-top: 1px solid #e5e7eb; margin-top: 16px; }
        .row { display: grid; grid-template-columns: 1fr; gap: 4px; padding: 16px 0; border-bottom: 1px solid #e5e7eb; }
        dt { font-weight: 600; color: #374151; font-size: 14px; }
        dd { color: #111827; font-size: 14px; }
        a { color: #1f2937; text-decoration: underline; }
        .home { display: inline-block; margin-top: 40px; font-size: 14px; }
        @media (min-width: 640px) { .row { grid-template-columns: 200px 1fr; gap: 16px; } }
    </style>
</head>
<body>
    <div class="container">
        @yield('main')
        <a class="home" href="/">← ホームに戻る</a>
    </div>
</body>
</html>
