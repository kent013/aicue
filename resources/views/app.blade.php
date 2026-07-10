<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        {{-- GTM head (production かつ GTM_CONTAINER_ID 設定時のみ描画) --}}
        @include('partials.gtm-head')
        {{-- title/meta/canonical/og/twitter/json-ld はサーバ描画に一本化 (SeoComposer が供給。未供給ページは noindex) --}}
        {!! $seoHead !!}
        {{-- ブランドアセット (public/ のプレースホルダ。アプリ初期化時に差し替える) --}}
        <link rel="icon" href="/favicon.ico" sizes="any" />
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png" />
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
        {{-- 撮影 PWA (/app/*) はホーム画面追加用の専用 manifest (start_url=/app)。他は既存 site.webmanifest --}}
        @if (request()->is('app') || request()->is('app/*'))
            <link rel="manifest" href="/manifest.webmanifest" />
        @else
            <link rel="manifest" href="/site.webmanifest" />
        @endif
        <meta name="theme-color" content="{{ config('seo.theme_color') }}" />
        @inertiaHead
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="font-sans antialiased">
        {{-- GTM noscript (production かつ GTM_CONTAINER_ID 設定時のみ描画) --}}
        @include('partials.gtm-body')
        @inertia
    </body>
</html>
