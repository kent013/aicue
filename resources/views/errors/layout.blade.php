{{--
    自己完結 error layout。アプリ (Vite/Inertia) が壊れた 500 経路でも確実に描画するため、
    @vite / 外部 CSS / 外部フォント / @font-face / DB アクセス / Inertia を一切含まない。
    DESIGN.md の「生 CSS/inline style 禁止」「SVG 直書き禁止」は Vite/Tailwind パイプラインに
    乗る Svelte コンポーネントへの規約であり、本 error blade はそのパイプラインに依存できない
    ため inline CSS + inline SVG ロゴが正当な例外。色は DS token を参照できないためニュートラルな
    プレースホルダ (スレート系) を hex 直書きで固定する。アプリ初期化時にブランド色へ差し替える。
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
            color: #1f2937;
            padding: 24px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 48px 40px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.18);
        }
        .logo { width: 64px; height: auto; margin: 0 auto 24px; display: block; }
        .code { font-size: 56px; font-weight: 700; color: #374151; line-height: 1; margin-bottom: 12px; }
        .title { font-size: 20px; font-weight: 700; margin-bottom: 8px; color: #111827; }
        .message { font-size: 14px; color: #6b7280; margin-bottom: 32px; line-height: 1.7; }
        .home {
            display: inline-block;
            background: #1f2937;
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 9999px;
        }
        .home:hover { opacity: .9; }
    </style>
</head>
<body>
    <div class="card">
        @include('errors.partials.logo')
        <div class="code">@yield('code')</div>
        <h1 class="title">@yield('title')</h1>
        <p class="message">@yield('message')</p>
        <a class="home" href="/">ホームに戻る</a>
    </div>
</body>
</html>
