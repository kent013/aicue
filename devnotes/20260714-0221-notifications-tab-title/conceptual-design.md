# 概念設計: notifications-tab-title

## 背景・課題

bug-hunt 回帰 run の finding **F-4-02 (Low)** は、前回修正 **T029 (`seo-tab-titles`)** の
**取りこぼし**である。T029 は「認証配下アプリ画面のブラウザタブ title がサイト名のみになる」
問題に対し、`config/seo.php` の `app_titles` へ未登録 6 ルートの固有タイトルを補ったが、
その対象集合に **`notifications.index` (`/notifications`) を含めていなかった**。

このため、通知一覧画面 (`Notifications/Index.svelte`, h1「通知」) は `app_titles` 未登録のまま残り、
`SeoManager::resolvePrivateTitle()` が `null` を返す → `SeoTitle::compose(null)` がサイト名のみを返す
→ ブラウザタブ title が **「AI-CUE」だけ**になる (画面固有名が出ない)。

- 影響度は Low。noindex のアプリ内画面であり SEO/クローラ影響はなく、機能不全でもない。
- ただし他の全アプリ画面はタブ title に固有名を出しており、通知画面のみ抜けているのは
  **一貫性の欠落**で、複数タブを開くユーザーの識別性を損なう。

## 改善アイデア

`config/seo.php` の `app_titles` マップに **`notifications.index` の固有タイトルエントリ 1 行**を追加する。
文言は既存他ルートのスタイル (画面 h1 見出しと一致させる方針) に合わせ、
`Notifications/Index.svelte` の h1「通知」と一致させて **`'notifications.index' => '通知'`** とする。

- 完成タイトルは `SeoTitle::compose('通知')` = **「通知 | AI-CUE」** (site_name は env 由来)。
- 解決経路・優先順位・描画は既存の `SeoManager` / `SeoComposer` / `HandleInertiaRequests` を
  そのまま使う (新規経路・新規コードなし、config データ 1 行の追加のみ)。

## 期待効果

- **使命への貢献 (補助的)**: 機能価値そのものではなく、複数タブ運用時の識別性を改善する
  補助的な UX 一貫性改善。AI-CUE を業務で使う現場管理者が複数画面 (プロジェクト・マニュアル・
  通知) を並行操作する実運用で、タブ title から通知画面を識別でき、認知負荷を下げる。
- **具体的改善**: `/notifications` のタブ title が「AI-CUE」→「通知 | AI-CUE」になり、
  T029 で既知だった取りこぼし 1 件 (`notifications.index`) を塞ぐ (全画面棚卸しは伴わない)。
- **回帰防止**: `SeoManagerTest` に `notifications.index` の固有 title を固定するケースを足し、
  当該 route のエントリ欠落 drift を機械的に検出できるようにする。

## 成功条件

- `/notifications` (`notifications.index`) のブラウザタブ title が **「通知 | AI-CUE」**
  (テスト環境の site_name では「通知 | Acme」) になる。
- `SeoManagerTest` が `notifications.index` の固有名欠落 (config からの脱落) を検出して fail する。
- 既存の他ルートのタブ title・SEO head 描画・PHPStan level 10・全既存テストに後退がない。

## 実装方針（概要）

1. `config/seo.php` の `app_titles` 配列に、既存コメント様式に倣って
   `// 通知一覧 (notifications.index — Notifications/Index.svelte h1「通知」)` の注記付きで
   `'notifications.index' => '通知'` を追加する (T029 の h1 一致方針を踏襲)。
2. `tests/Feature/Seo/SeoManagerTest.php` に、`resolveDocumentTitle('notifications.index')` が
   「通知 | Acme」を返し、かつ実 config に固有名エントリが存在することを検証するケースを追加する
   (T029 で用意した data-driven テストへ 1 行足す形が自然)。

## 制約・前提

- `notifications.index` は `route_classification` の full / minimal / excluded いずれにも属さず、
  認証配下の private (noindex) 画面である → `app_titles` fallback が正しい解決経路 (設計整合)。
- 固有名は静的見出しで足りる (対象実体が動的でない一覧画面) ため、controller の
  `SeoManager::setPrivateTitle()` 動的上書きは不要 (config 既定で完結)。
- HTTP レスポンス body を新たに作らない (title は Inertia 共有 prop / Blade head 描画で既存経路)
  ため、DTO/JsonResource パターン・`response()->json()` 禁止事項には抵触しない。
- PHPStan level 10: config データ追加のみで型面の変更なし。テストは既存 `Assert` 様式を踏襲。

## スコープ外

- 通知一覧以外の notifications 系ルート (`notifications.read-all` / `notifications.open` /
  `notifications.read`) は **POST の操作エンドポイント**で HTML head を持たないタブ表示対象外 →
  今回追加しない。
- `SeoManager` / `SeoComposer` / `HandleInertiaRequests` のロジック変更・リファクタは行わない。
- 動的タイトル (未読件数のタブ表示等) の導入は行わない (オーバーエンジニアリング回避)。
- 他に未登録アプリ画面が残っていないかの全ルート棚卸しは本 finding のスコープ外
  (F-4-02 が指す `notifications.index` のみ対象)。
