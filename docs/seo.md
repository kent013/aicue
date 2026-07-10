# SEO サーバサイド描画基盤

**実装**: `app/Support/Seo/`, `app/View/Composers/SeoComposer.php`, `app/Providers/SeoServiceProvider.php`, `app/Http/Controllers/Seo/`, `config/seo.php`

## 概要

クローラ (検索エンジン / SNS / AI) が読む **正本を「サーバ描画した `<head>`」に固定する**ための機構。
`title` / `description` / `canonical` / OGP / Twitter Card / JSON-LD をすべて `config/seo.php` を起点に
組み立て、`SeoRenderer` が root Blade (`app.blade.php`) の `$seoHead` としてサーバ側で描画する。
SPA (Inertia) の JS 実行を待たずに完全なメタが返るため、JS を実行しないクローラでも正しく読める。

契約上の要点は以下。

- **Host ヘッダ非依存**: 公開 URL (`canonical` / `og:url` / `sitemap` / JSON-LD) はすべて
  `config('seo.base_url')` (= `APP_URL`) を唯一の正本とする。Host ヘッダ汚染による canonical 偽装を防ぐ。
  `SeoUrl` のコンストラクタが起動時に「origin のみ (path/query/fragment 不可)・http(s)・host 必須」を検証する。
- **供給漏れは noindex に倒れる**: controller が `SeoManager` にメタを供給しなかったページは、
  `SeoComposer` が `title + noindex` のみを描画する。供給漏れが「誤って index される」方向に倒れない設計。
- **エスケープの一元化**: HTML / JSON-LD のエスケープ責務は `SeoRenderer` に集約する。
  JSON-LD は `JSON_HEX_*` フラグで `</script>` や属性破壊を無害化する。
- **単一ソースの徹底**: robots.txt と ai.txt の Disallow 集合は `CrawlPolicy` 1 箇所、
  sitemap.xml と llms.txt の公開ページ集合は `config('seo.sitemap_routes')` 1 箇所を読み、両者がドリフトしない。

## アーキテクチャ

### route の SEO 分類 (`config('seo.route_classification')`)

公開 route を 3 分類し、`SeoComposer` が分類ごとに `<head>` を出し分ける。いずれにも属さない
route (認証配下のアプリ画面等) は `title + noindex` のみになる。

| 分類 | 誰がメタを供給するか | 描画される `<head>` |
|------|--------------------|--------------------|
| `full` | controller が `SeoManager::set()` で明示供給 | `title` / `description` / `canonical` / OGP / Twitter / JSON-LD の完全なヘッド |
| `minimal` | `SeoComposer` が default メタ + `config('seo.minimal_titles')` から生成 | 静的公開ページ向けの `canonical` / OGP 付き最小ヘッド |
| `excluded` | (対象外) | robots / sitemap 等の機械可読 route 自体。HTML ヘッドを持たない |
| 上記以外 | (供給なし) | `title + noindex` のみ (private URL のメタ漏れ・誤インデックス防止) |

`full` 分類の参考実装は `HomeController` (route `home`)。`SeoMeta::default()` に `withTitle()` /
`withJsonLd()` を重ねて `SeoManager::set()` する。

### タイトル解決の単一経路

`<title>` の完成文字列は `SeoManager::resolveDocumentTitle()` に一元化され、フルロード時
(`SeoComposer` の Blade 描画) と SPA 遷移時 (`HandleInertiaRequests` の共有 prop `title`) が
**同一メソッドを共有**する。これにより両経路で `document.title` と `<title>` が一致する (二重 SoT を作らない)。
固有名 → 完成タイトル (`{固有名}{separator}{サイト名}`) の合成は `SeoTitle::compose()` に集約
(空/空白 → サイト名のみ、サイト名一致 → 二重化回避)。

`noindex` ページの per-page タイトル固有名は、動的値を controller が `SeoManager::setPrivateTitle()`
で供給でき、その fallback が `config('seo.app_titles')[route]` になる (未設定 route はサイト名のみ)。

### `SeoManager` のライフサイクル

`SeoServiceProvider::register()` で `SeoManager` を **scoped 束縛** する (singleton にしない)。
リクエスト単位で状態を保持し、Octane 等の長寿命プロセスでリクエスト間に SEO メタが漏れないようにする。
`SeoUrl` は `SeoUrl::fromConfig()` を bind し、`base_url` 検証をコンストラクタに集約する。

### 機械可読エンドポイント (`app/Http/Controllers/Seo/`)

いずれも `__invoke` の単一アクション controller。`SeoUrl` (= `APP_URL` 正本) 基準で Host ヘッダ非依存。

| route name | パス | 生成内容 | ソース |
|-----------|------|---------|--------|
| `seo.robots` | `/robots.txt` | `User-agent` + Disallow + Sitemap 行 | Disallow は `CrawlPolicy`、Sitemap 行は `SeoUrl` |
| `seo.sitemap` | `/sitemap.xml` | 公開 HTML ページの `<urlset>` | `config('seo.sitemap_routes')` (route 名を `route()` で相対 path 化 → `SeoUrl` で絶対化) |
| `seo.llms` | `/llms.txt` | llmstxt.org 形式 (H1=サイト名 / blockquote=要約 / 公開ページ一覧) | `config('seo.sitemap_routes')` (sitemap と同一ソース) |
| `seo.ai` | `/ai.txt` | AI クローラ向けクロール方針 (Disallow 集合) | `CrawlPolicy` (robots.txt と同一ソース) |

## 関連ファイル

| ファイル | 役割 |
|---------|------|
| `app/Support/Seo/SeoManager.php` | リクエスト単位で現在の SEO メタを保持 (scoped)。`resolveDocumentTitle()` がタイトル解決の単一経路 |
| `app/Support/Seo/SeoMeta.php` | 1 ページ分の SEO メタを表す不変 DTO。`default()` / `with*()` で組み立てる |
| `app/Support/Seo/SeoRenderer.php` | `SeoMeta` を安全な `<head>` HTML に描画。HTML/JSON エスケープの一元化。`renderPrivate()` は noindex 用 |
| `app/Support/Seo/SeoUrl.php` | 公開 URL の単一経路。`base_url` (= `APP_URL`) を起動時検証 (origin のみ・Host 非依存) |
| `app/Support/Seo/SeoTitle.php` | 固有名 → `{固有名}{separator}{サイト名}` 合成の単一経路 |
| `app/Support/Seo/JsonLd.php` | schema.org JSON-LD ノード (Organization / WebSite / SoftwareApplication) の型付き builder |
| `app/Support/Seo/CrawlPolicy.php` | robots.txt / ai.txt の Disallow パス prefix 集合 (単一ソース) |
| `app/View/Composers/SeoComposer.php` | root Blade に `$seoHead` を供給。分類ごとの出し分け・供給漏れの noindex fallback |
| `app/Providers/SeoServiceProvider.php` | `SeoManager` の scoped 束縛・`SeoUrl` bind・root view への composer 登録 |
| `app/Http/Controllers/Seo/RobotsController.php` | `/robots.txt` (`seo.robots`) |
| `app/Http/Controllers/Seo/SitemapController.php` | `/sitemap.xml` (`seo.sitemap`) |
| `app/Http/Controllers/Seo/LlmsTxtController.php` | `/llms.txt` (`seo.llms`) |
| `app/Http/Controllers/Seo/AiTxtController.php` | `/ai.txt` (`seo.ai`) |
| `app/Http/Controllers/HomeController.php` | `full` 分類の参考実装 (route `home`) |
| `app/Http/Middleware/HandleInertiaRequests.php` | 共有 prop `title` が `SeoManager::resolveDocumentTitle()` を読む (SPA 遷移時のタイトル一致) |
| `config/seo.php` | サイト名 / 既定メタ / route 分類 / タイトル map / sitemap route の設定 |
