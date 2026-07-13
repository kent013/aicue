# 詳細設計: notifications-tab-title

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成。新モデル追加時は Factory も施策に含める（**本設計は新モデルなし**）
- **DTO + JsonResource** パターン（**本設計は該当なし = HTTP レスポンス body を新規作成しない**）
- アーリーリターン推奨 / `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260714-0221-notifications-tab-title/conceptual-design.md`
- 概念レビュー: `conceptual-review-round-1.md` (APPROVED, Round 1) /
  対応: `codex-history/conceptual-review-decisions-round-1.md`

## 背景 (finding)

bug-hunt 回帰 run **F-4-02 (Low)** = 前回修正 **T029 (`seo-tab-titles`)** の取りこぼし。
T029 は `config/seo.php` の `app_titles` に未登録アプリ画面 6 ルートの固有タイトルを追加したが、
対象集合に `notifications.index` を含めていなかった。結果、`/notifications` のタブ title が
サイト名「AI-CUE」のみになる (画面固有名が出ない)。

## 解決経路の確認 (現行コード把握)

- `notifications.index` は `routes/web.php` L333-334 で `/notifications` にマップ。認証配下。
- `config/seo.php` の `route_classification` の full/minimal/excluded いずれにも属さない
  → private (noindex) 経路。
- `SeoManager::resolveDocumentTitle($routeName)` (app/Support/Seo/SeoManager.php L54-65):
  meta なし・minimal でない → `SeoTitle::compose($this->resolvePrivateTitle($routeName))`。
- `SeoManager::resolvePrivateTitle()` (L71-85): `setPrivateTitle` 上書きなし →
  `config('seo.app_titles')[$routeName] ?? null`。`notifications.index` 未登録のため **null**。
- `SeoTitle::compose(null)` → site_name のみ。よってタブ title = 「AI-CUE」だけになる。
- 対応画面 `resources/js/pages/Notifications/Index.svelte` L55 の h1 は **「通知」**。
  → 既存の「app_titles 固有名は画面 h1 と一致させる」方針 (config/seo.php L111-127 のコメント群)
  に従い、固有名を **「通知」** とする。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | `app_titles` に `notifications.index` の固有タイトルを追加 | `config/seo.php` | Low |
| S2 | `SeoManager` が `notifications.index` で固有 title を返す Feature テストを追加 | `tests/Feature/Seo/SeoManagerTest.php` | Low |

> 実装順序 (テストファースト / AGENTS.md 思考原則5): **S2 を先に追加して fail を確認**
> (現状 `notifications.index` は未登録なので「通知 | Acme」を期待するテストは fail する) →
> **S1 の config 追加で green** にする。

---

## S1: app_titles に notifications.index の固有タイトルを追加

### 変更箇所

- ファイル: `config/seo.php` (`app_titles` 配列、L80-128 の末尾付近)

### 波及変更

- **TypeScript 型定義**: なし。タブ title は Blade head (`SeoComposer`) と Inertia 共有 prop
  (`HandleInertiaRequests`) が `SeoManager::resolveDocumentTitle()` の文字列を描画する経路で、
  TS 型・Props インターフェースに新フィールドは増えない。
- **API Resource / DTO**: なし。HTTP レスポンス body を新規に作らない (config データ 1 行の追加)。
  `response()->json()` 直書き禁止・DTO/JsonResource 必須にはそもそも該当しない。
- **Inertia Props**: なし (既存の title 共有 prop 経路をそのまま利用)。
- **テストファイル**: あり → S2 で `tests/Feature/Seo/SeoManagerTest.php` を更新。

### 現行コード (config/seo.php `app_titles` 末尾)

```php
        // CLI 導入ガイド (organizations.onboarding.cli — Onboarding/Cli.svelte h1「CLI 導入ガイド」)
        'organizations.onboarding.cli' => 'CLI 導入ガイド',
        // MCP 導入ガイド (organizations.onboarding.mcp — Onboarding/Mcp.svelte h1「MCP 導入ガイド」)
        'organizations.onboarding.mcp' => 'MCP 導入ガイド',
    ],
```

### 変更後コード

```php
        // CLI 導入ガイド (organizations.onboarding.cli — Onboarding/Cli.svelte h1「CLI 導入ガイド」)
        'organizations.onboarding.cli' => 'CLI 導入ガイド',
        // MCP 導入ガイド (organizations.onboarding.mcp — Onboarding/Mcp.svelte h1「MCP 導入ガイド」)
        'organizations.onboarding.mcp' => 'MCP 導入ガイド',
        // 通知一覧 (notifications.index — Notifications/Index.svelte h1「通知」)
        'notifications.index' => '通知',
    ],
```

- 既存コメント様式 (route 名 — 画面ファイル h1) を踏襲。
- キーは route name `notifications.index` (ドット含み) のリテラル文字列。
- 値「通知」は Svelte h1 と完全一致 (表現一貫性・drift 検出容易性)。

### PHPStan 適合チェック

- [x] 型面の変更なし (`array<string, string>` の要素を 1 つ増やすのみ)。
      `SeoManager::resolvePrivateTitle()` の `@var array<string, string> $appTitles` 前提を崩さない。
- [x] null 安全: 追加により当該 route で `null` fallback が発生しなくなる (回帰方向は改善)。
- [x] DTO を返す/配列返却の論点は非該当 (config 定数)。
- [x] Generics 型パラメータ変更なし。

### テスト計画

S2 で担保 (下記)。

### リスク

- ほぼなし。config データ 1 行追加で、当該 route 以外の解決・描画に影響しない。
- 唯一の残存リスクは将来 h1「通知」文言変更時の drift → S2 のテストが config 側の欠落/不一致を
  検出する (コメントにも h1 追随を明記)。

---

## S2: SeoManagerTest に notifications.index の固有 title 固定ケースを追加

### 変更箇所

- ファイル: `tests/Feature/Seo/SeoManagerTest.php`
  - 既存の data-driven テスト
    「`resolveDocumentTitle: 未登録だった 6 アプリ画面が固有 title を返す (仕様固定・h1 と一致)`」
    (L86-109) の `->with([...])` データセットに 1 行追加する。

### 波及変更

- TypeScript 型定義: なし。
- API Resource / DTO: なし。
- テストファイル: 本施策そのもの。

### 現行コード (テストの データセット末尾)

```php
})->with([
    'カテゴリ管理' => ['projects.categories.index', 'カテゴリ管理'],
    'ユーザー管理' => ['manage.users.index', 'ユーザー管理'],
    'API キー' => ['organizations.api-keys.index', 'API キー'],
    '接続セッション' => ['organizations.api-keys.sessions.index', '接続セッション'],
    'CLI 導入ガイド' => ['organizations.onboarding.cli', 'CLI 導入ガイド'],
    'MCP 導入ガイド' => ['organizations.onboarding.mcp', 'MCP 導入ガイド'],
]);
```

このテストは実 `config/seo.php` の `app_titles` を検証対象にしており (beforeEach は
`site_name` 等のみ上書き、`app_titles` は上書きしない)、各 route について
(a) `resolveDocumentTitle($route) === "{固有名} | Acme"` と
(b) `config('seo.app_titles')[$route] === {固有名}` (欠落 drift 検出) の両方を固定する。

### 変更後コード

```php
})->with([
    'カテゴリ管理' => ['projects.categories.index', 'カテゴリ管理'],
    'ユーザー管理' => ['manage.users.index', 'ユーザー管理'],
    'API キー' => ['organizations.api-keys.index', 'API キー'],
    '接続セッション' => ['organizations.api-keys.sessions.index', '接続セッション'],
    'CLI 導入ガイド' => ['organizations.onboarding.cli', 'CLI 導入ガイド'],
    'MCP 導入ガイド' => ['organizations.onboarding.mcp', 'MCP 導入ガイド'],
    // F-4-02 (T029 取りこぼし) 回帰防止: 通知一覧 (Notifications/Index.svelte h1「通知」)
    '通知' => ['notifications.index', '通知'],
]);
```

補足 (design-review Round 1 [Warning] 反映): テストケースの見出し文言
(it の説明「未登録だった 6 アプリ画面…」) は 7 件になり件数不整合となる。将来の件数増減で
再び陳腐化しないよう、**件数非依存の表現に変更する** (確定):

```php
it('resolveDocumentTitle: 未登録だったアプリ画面が固有 title を返す (仕様固定・h1 と一致)', function (
```

値の検証ロジック・アサーションは無変更。

### テスト計画 (テストファースト)

- [x] **バグ再現 (fail 先行)**: S2 のデータ 1 行を先に追加し、S1 未適用の状態で
      `composer test -- --filter=SeoManagerTest` を実行 → `notifications.index` ケースが
      (a) `resolveDocumentTitle` が「Acme」を返し「通知 | Acme」と不一致、
      (b) `config` 側が `null` で「通知」と不一致、の両方で **fail** することを確認。
- [x] **修正で green**: S1 (config 追加) 適用後に再実行し green を確認。
- [x] 既存 6 ケース・他テスト (`SeoHeadCompositionTest` / `SeoTitleTest` 等) に後退なし。
- [x] 個別の `DatabaseTransactions` を使わない (このテストは DB 非依存の純粋 unit 的 Feature、
      既存同様 `RefreshDatabase` グローバル適用下で問題なし)。
- [x] `it` 説明の件数「6」→「7」(または件数非依存表現) に更新。

### PHPStan 適合チェック

- [x] 追加はデータセットの `list<array{string, string}>` 要素 1 つ。既存 `Assert::isArray()` +
      リテラルキー参照の型前提を崩さない。戻り値型・generics 変更なし。

### リスク

- なし (テスト追加のみ)。既存アサーションロジックを変更しないため他ケースへ影響しない。

---

## 全体テスト計画・検証コマンド

1. `composer test -- --filter=SeoManagerTest` (S2 fail → S1 適用 → green)
2. `composer test` (全 Pest green)
3. `composer phpstan` (level 10 green — config/テスト変更で型変化なし)
4. `vendor/bin/pint --test` (config/seo.php・テストのフォーマット)
5. (任意) `/notifications` を実ブラウザで開きタブ title が「通知 | AI-CUE」になることを目視

## 使命・禁止事項 最終チェック

- 使命: 補助的だが「思考ゼロで迷わない」UX の一貫性に寄与。過大表現は概念設計で是正済み。
- 禁止事項1 (テストなし完了): S2 で回帰テストを追加。抵触なし。
- 禁止事項2 (PHPStan widen/baseline): 型変更なし。抵触なし。
- 禁止事項4 (`response()->json()` 直書き): HTTP body を作らない。非該当。
- 禁止事項5/6 (Prism/prompt): 非該当。
- その他 (3,7,8): 非該当。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存 `config/seo.php` の `app_titles` と既存 `SeoManagerTest` への追記のみ。新規ファイル・新規モデル・新規経路なし。T029 と同一ファイル/同一テストの純増分修正で、単独ブランチ化する独立性がない。 |
| 競合リスク | 低。`config/seo.php` の `app_titles` と `SeoManagerTest.php` を同時に触る他タスクがなければ衝突しない。T029 は既にクローズ済みで競合しない。 |
