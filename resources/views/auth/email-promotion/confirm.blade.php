{{-- メール昇格の確認画面。**standalone Blade** (Inertia / Vite に依存しない)。
   形の先例は resources/views/mcp/authorize.blade.php。

   ★Inertia を使わないのは、Inertia が page object を history.state へ載せるためである。
     prop へ置いた瞬間に**トークンがブラウザの履歴に残る**。encryptHistory() で緩和はできるが、
     それは「履歴の暗号化に依存する」ことになる。Blade なら**そもそも履歴に載らない**。

   ★DESIGN.md の「生 CSS / inline style 禁止」は Vite/Tailwind パイプラインに乗る Svelte
     コンポーネントへの規約であり、本 blade はそのパイプラインに依存できないため inline CSS が
     正当な例外である (errors/layout.blade.php / legal/layout.blade.php / mcp/authorize.blade.php
     と同じ扱い)。色は DS token を参照できないため**ニュートラルなプレースホルダを hex 直書き**で
     固定する (新しいパレットを作らない)。

   ★トークンの有効・無効で画面を変えない (一様。存在の探り当てを作らない)。
   ★外部リソースを一切読み込まない (@vite なし・外部 CSS / フォント / 画像 / リンクなし)。
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- ★この document からの Referer を止める。
         ヘッダで上書きしない理由: SecurityHeaders は web group の middleware で、
         route middleware より外側から Referrer-Policy を無条件に set するため、
         route 側で立てても後から上書きされる。document 側で閉じる。 --}}
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex">
    <title>メールアドレスの確認 | {{ config('app.name') }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #f9fafb; margin: 0; padding: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { max-width: 32rem; width: 100%; background: #fff; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.12); box-sizing: border-box; }
        h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 0.5rem; color: #111827; }
        p { font-size: 0.875rem; color: #374151; margin: 0.5rem 0 0; }
        .muted { color: #6b7280; }
        button { margin-top: 1.5rem; width: 100%; padding: 0.625rem 1rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 600; border: none; cursor: pointer; background: #2563eb; color: #fff; }
        button:hover { background: #1d4ed8; }
    </style>
</head>
<body>
<div class="card">
    <h1>メールアドレスの確認</h1>
    <p>下のボタンを押すと、このメールアドレスがアカウントに登録されます。</p>
    <p class="muted">心当たりがない場合は、このまま画面を閉じてください (押さなければ何も起きません)。</p>

    <form method="POST" action="{{ route('settings.email-promotion.confirm') }}">
        @csrf
        {{-- ★サーバが描画した hidden。Inertia の props にも history.state にも載らない --}}
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit" data-testid="email-promotion-confirm-submit">このメールアドレスを確定する</button>
    </form>
</div>
</body>
</html>
