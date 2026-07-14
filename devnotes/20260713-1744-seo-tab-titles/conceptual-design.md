# 概念設計: seo-tab-titles

## 背景・課題

bug-hunt finding **F-L2 (Low)**。

`config/seo.php` の `app_titles` マップ (認証配下アプリ画面の per-page ブラウザタブ title の
route 既定) に未登録の 6 ルートで、`<title>` がサイト名 "AI-CUE" のみになる。結果、これらの
画面を開いたブラウザタブ・履歴・ブックマークが全て同一文字列になり、複数タブを開いた作業者・
スクリーンリーダー利用者が画面を識別できない (アクセシビリティ / UX 破綻)。

対象ルート (いずれも `SeoManager::resolvePrivateTitle()` が `app_titles[route] ?? null` で
未登録 → `SeoTitle::compose(null)` = サイト名のみ になる):

| route name | 画面 (h1 見出し) |
|------------|------------------|
| `projects.categories.index` | カテゴリ管理 |
| `manage.users.index` | ユーザー管理 |
| `organizations.api-keys.index` | API キー |
| `organizations.api-keys.sessions.index` | 接続セッション |
| `organizations.onboarding.cli` | CLI 導入ガイド |
| `organizations.onboarding.mcp` | MCP 導入ガイド |

## 改善アイデア

`config/seo.php` の `app_titles` に上記 6 ルートの固有タイトルを追加する。文言は各画面の
h1 見出し (画面内の一次見出し) に一致させ、既存 `app_titles` の簡潔な名詞スタイル
(例: 'ダッシュボード', 'プロジェクト', 'セキュリティ設定') に揃える。

追加内容 (route name => 固有名):

```php
'projects.categories.index'            => 'カテゴリ管理',
'manage.users.index'                   => 'ユーザー管理',
'organizations.api-keys.index'         => 'API キー',
'organizations.api-keys.sessions.index'=> '接続セッション',
'organizations.onboarding.cli'         => 'CLI 導入ガイド',
'organizations.onboarding.mcp'         => 'MCP 導入ガイド',
```

これにより `resolvePrivateTitle()` が固有名を返し、`SeoTitle::compose('カテゴリ管理')` →
`カテゴリ管理 | AI-CUE` のように per-page title が描画される (noindex は維持)。

## 期待効果

- **使命への貢献 (間接)**: 現場作業者が迷わず操作できる導線の一部。タブ/履歴/スクリーンリーダーで
  画面を識別できることは「思考ゼロ」の運用体験を支える基本的アクセシビリティ。
- 6 ルートで一意なブラウザタブ title が付与され、複数タブ運用・履歴・ブックマークで画面識別可能に。
- スクリーンリーダーが `<title>` を読み上げ、視覚障害者の画面識別が可能に。
- 既存の SEO 不変条件 (noindex 維持・canonical/og を漏らさない) は一切変えない。

## 実装方針（概要）

- **単一ファイル変更**: `config/seo.php` の `app_titles` 配列に 6 エントリを追記するのみ。
- ロジック変更なし。`SeoManager` / `SeoComposer` / `HandleInertiaRequests` は既存経路
  (`resolvePrivateTitle` → `SeoTitle::compose`) がそのまま固有名を拾う。
- **テスト**: `SeoManager` が 6 ルート名で `{固有名} | {site_name}` を返すことを検証する
  Feature テスト (`tests/Feature/Seo/SeoManagerTest.php` に追加、既存の
  「private 経路は app_titles を合成」テストと同じ書式)。既存テストは削除・上書きしない。

## 制約・前提

- title 文言は各画面の h1 見出しに一致させる (画面内表現とタブ表現の一貫性)。
- `app_titles` は route 既定の fallback。動的固有名が要る画面は controller が
  `setPrivateTitle()` で上書きする設計だが、対象 6 ルートはいずれも静的見出しで足りるため
  config 追記で十分 (controller 変更不要)。
- PHPStan L10 / Pest / DTO パターンには無関係 (config 値と assertion のみ、`response()->json()` 不使用)。

## スコープ外

- 上記 6 ルート以外の app_titles 網羅性監査 (今回の finding 対象のみ扱う)。
- `app_titles` 全 route 網羅を強制する drift-guard テストの新設 (別施策。今回はスコープ外)。
- 動的タイトル (setPrivateTitle) を要する画面の見直し。
- title 文言の英語化 / i18n。
