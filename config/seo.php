<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SEO Configuration
|--------------------------------------------------------------------------
|
| サーバサイド SEO ヘッド描画基盤 (app/Support/Seo) の設定。
| クローラが読む正本は SeoRenderer がサーバ描画した <head> であり、
| title / canonical / og / JSON-LD はすべて本 config を起点に組み立てる。
|
*/

return [

    /*
    | 公開 URL の正本。Host ヘッダは使わず APP_URL を唯一の真実とする
    | (Host ヘッダ汚染による canonical / og:url 偽装の防止)。
    | origin のみ (path / query / fragment 不可)。SeoUrl が起動時に検証する。
    */
    'base_url' => env('APP_URL'),

    /*
    | SEO 上のサイト名・既定タイトル。APP_NAME は「App (Alpha)」のような
    | 表示用サフィックスを含み得るため、SEO 用は独立した env で上書きできる。
    */
    'site_name' => env('SEO_SITE_NAME', env('APP_NAME', 'Laravel')),
    'default_title' => env('SEO_SITE_NAME', env('APP_NAME', 'Laravel')),
    'title_separator' => ' | ',
    'default_description' => env('SEO_DEFAULT_DESCRIPTION', ''),
    'locale' => 'ja_JP',
    'twitter_card' => 'summary_large_image',

    /*
    | ブラウザ UI (アドレスバー等) と PWA の基調色。<meta name="theme-color"> と
    | public/site.webmanifest の theme_color の単一ソース。ニュートラルなプレースホルダ
    | (#1f2937) を置いてあるので、アプリ初期化時にブランド色へ差し替える
    | (BrandAssetsTest の drift guard が webmanifest との一致を強制する)。
    */
    'theme_color' => env('SEO_THEME_COLOR', '#1f2937'),

    // og:image の既定 (1200x630 推奨)。アプリ側で public/ に実ファイルを配置すること。
    'og_default_image' => '/images/og-default.png',

    /*
    | 公開 route の SEO 分類。
    | full    = controller が SeoManager にメタを供給し、canonical / og / JSON-LD を描画
    | minimal = 静的ページ等。SeoComposer が default メタ + minimal_titles でヘッドを描画
    | excluded = robots / sitemap 等の機械可読 route 自体 (HTML ヘッドを持たない)
    | 上記いずれにも属さない route (認証配下のアプリ画面等) は noindex + title のみ描画される。
    */
    'route_classification' => [
        'full' => ['home', 'pricing'],
        'minimal' => [],
        'excluded' => ['seo.robots', 'seo.sitemap', 'seo.llms', 'seo.ai'],
    ],

    // minimal 分類のページ固有 title (route name => 固有名)。
    'minimal_titles' => [],

    /*
    | sitemap.xml に載せる公開 HTML ページ (route name => changefreq/priority)。
    | llms.txt の公開ページ一覧も本 map を読む (単一ソース)。
    | 認証配下・noindex のページは載せないこと。
    */
    'sitemap_routes' => [
        'home' => ['changefreq' => 'weekly', 'priority' => '1.0'],
        'pricing' => ['changefreq' => 'monthly', 'priority' => '0.8'],
    ],

    /*
    | 認証配下のアプリ画面 (SEO 非対象 = noindex) の per-page ブラウザタイトル
    | (route name => 固有名)。SeoComposer の private 経路がこれを引いて
    | `{固有名}{separator}{site_name}` を描画する (noindex は維持)。
    | 動的な固有名 (プロジェクト名等) は controller が SeoManager::setPrivateTitle() で
    | 上書きでき、本 map はその fallback (未設定 route はサイト名のみ)。
    */
    'app_titles' => [
        'dashboard' => 'ダッシュボード',
        // 公開問い合わせフォーム (spam bot の標的になるため意図的に noindex のまま)
        'contact' => 'お問い合わせ',
        'contact.thanks' => 'お問い合わせ完了',
        // 認証フロー (Fortify)
        'login' => 'ログイン',
        'register' => 'アカウント登録',
        'password.request' => 'パスワードリセット',
        'password.reset' => 'パスワードリセット',
        'password.confirm' => 'パスワードの確認',
        'two-factor.login' => '2要素認証',
        'verification.notice' => 'メール認証',
        'recent-auth.confirm' => '本人確認',
        // 設定
        'settings' => '設定',
        'settings.security' => 'セキュリティ設定',
        // 組織
        'organizations.create' => '組織の作成',
        'organizations.settings' => '組織設定',
        'invitations.accept' => '組織への招待',
        // 課金
        'billing.index' => 'プランとお支払い',
        // プラン比較 (billing.plans — Billing/Plans.svelte の見出し「プラン比較」)
        'billing.plans' => 'プラン比較',
        'billing.tickets.show' => 'チケットを購入',
        /*
        | 課金オンボーディング (課金ゲートの着地先。未契約組織が「契約するために」
        | 到達する導線なので、タブ識別性は詰み回避の一部。AGENTS.md ドメイン規約 4)。
        | onboarding.checkout の画面見出しは `ようこそ、{組織名}` という動的な挨拶文で、
        | タブ title としては組織名が幅を食い機能も伝わらないため、
        | 機能を表す静的名を採る (billing.tickets.show と同じ判断)。
        */
        'onboarding.checkout' => 'プランの選択',
        // 課金手続き待ち (onboarding.billing-required — Onboarding/BillingRequired.svelte
        // の見出し「課金手続き中です」)
        'onboarding.billing-required' => '課金手続き中です',
        // プロジェクト (show は controller が setPrivateTitle でプロジェクト名を供給)
        'projects.index' => 'プロジェクト',
        'projects.create' => 'プロジェクトの作成',
        'projects.edit' => 'プロジェクトの編集',
        // 動画マニュアル (show/edit/撮影 show は controller が setPrivateTitle で
        // マニュアル名を供給。create のみ静的 = 対象実体が未存在のため)
        'projects.manuals.create' => '動画マニュアルの作成',
        /*
        | 以下は各画面の h1 見出しと一致させる (タブ title と画面見出しの表現一貫性)。
        | いずれも静的見出しで足りるため controller の setPrivateTitle 上書きは不要。
        | 見出し文言を変えるときは本 map も追随させること (SeoManagerTest が固有 title を固定)。
        */
        // カテゴリ管理 (projects.categories.index — Admin/Categories.svelte h1「カテゴリ管理」)
        'projects.categories.index' => 'カテゴリ管理',
        // ユーザー管理 (manage.users.index — Admin/Users.svelte h1「ユーザー管理」)
        'manage.users.index' => 'ユーザー管理',
        // API キー (organizations.api-keys.index — ApiKeys/Index.svelte h1「API キー」)
        'organizations.api-keys.index' => 'API キー',
        // 接続セッション (organizations.api-keys.sessions.index — ApiKeys/Sessions.svelte h1「接続セッション」)
        'organizations.api-keys.sessions.index' => '接続セッション',
        // CLI 導入ガイド (organizations.onboarding.cli — Onboarding/Cli.svelte h1「CLI 導入ガイド」)
        'organizations.onboarding.cli' => 'CLI 導入ガイド',
        // MCP 導入ガイド (organizations.onboarding.mcp — Onboarding/Mcp.svelte h1「MCP 導入ガイド」)
        'organizations.onboarding.mcp' => 'MCP 導入ガイド',
        // 通知一覧 (notifications.index — Notifications/Index.svelte h1「通知」)
        'notifications.index' => '通知',
        /*
        | 撮影 PWA (/app/*)。manuals.show は controller が setPrivateTitle で
        | マニュアル名を供給するため、静的名が必要なのは一覧 (index) と
        | アカウント確認画面 (account) の 2 つ。
        | スマホで複数タブ / ホーム画面から戻る現場ユースケースではタブ名が唯一の識別子。
        */
        'capture.account' => 'アカウント',
        'capture.manuals.index' => '撮影するマニュアルを選ぶ',
    ],

];
