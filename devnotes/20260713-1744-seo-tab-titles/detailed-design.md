# 詳細設計: seo-tab-titles

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
- テストデータは Factory 生成。新モデル追加時は Factory も施策に含める（本設計は新モデルなし）
- **DTO + JsonResource** パターン（本設計は該当なし = HTTP レスポンス body を作らない）
- アーリーリターン推奨 / `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260713-1744-seo-tab-titles/conceptual-design.md`
- 概念レビュー: `conceptual-review-round-1.md` (APPROVED) / 対応: `codex-history/conceptual-review-decisions-round-1.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | `app_titles` に未登録 6 ルートの固有タイトルを追加 | `config/seo.php` | High |
| S2 | `SeoManager` が 6 ルートで固有 title を返す Feature テストを追加 | `tests/Feature/Seo/SeoManagerTest.php` | High |

---

## S1: app_titles に未登録 6 ルートの固有タイトルを追加

### 変更箇所

- ファイル: `config/seo.php` (`app_titles` 配列、L80-111)

### 波及変更

- TypeScript 型定義: **なし** (title は `HandleInertiaRequests` が `title` prop として
  `resolveDocumentTitle()` で供給する既存経路。config 追記で shape 不変)
- API Resource/DTO: **なし** (HTTP レスポンス body を作らない。config 値のみ)
- Inertia Props: **なし** (`title` prop は既に共有済み。値の解決先が埋まるだけ)
- テストファイル: `tests/Feature/Seo/SeoManagerTest.php` に追加 (S2)
- Svelte / DESIGN.md / Atomic Design: **無関係** (frontend 変更なし)

### 現行コード

```php
// config/seo.php L80-111 (抜粋末尾)
'app_titles' => [
    'dashboard' => 'ダッシュボード',
    // ...(中略)...
    // プロジェクト (show は controller が setPrivateTitle でプロジェクト名を供給)
    'projects.index' => 'プロジェクト',
    'projects.create' => 'プロジェクトの作成',
    'projects.edit' => 'プロジェクトの編集',
    // 動画マニュアル (show/edit/撮影 show は controller が setPrivateTitle で
    // マニュアル名を供給。create のみ静的 = 対象実体が未存在のため)
    'projects.manuals.create' => '動画マニュアルの作成',
],
```

### 変更後コード

`app_titles` 配列末尾 (`projects.manuals.create` の後) に以下を追記する。文言は各画面の
**h1 見出しと一致**させる (下記コメントで運用契約を明示 = 概念レビュー Round 1 [Warning] 対応)。

```php
'app_titles' => [
    // ...(既存エントリは不変)...
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
],
```

**文言の根拠 (各画面の h1 を実確認)**:

| route name | h1 見出し (SoT) | app_titles 値 |
|------------|-----------------|---------------|
| `projects.categories.index` | カテゴリ管理 | カテゴリ管理 |
| `manage.users.index` | ユーザー管理 | ユーザー管理 |
| `organizations.api-keys.index` | API キー | API キー |
| `organizations.api-keys.sessions.index` | 接続セッション | 接続セッション |
| `organizations.onboarding.cli` | CLI 導入ガイド | CLI 導入ガイド |
| `organizations.onboarding.mcp` | MCP 導入ガイド | MCP 導入ガイド |

### PHPStan 適合チェック

- [x] 型変更なし (`array<string, string>` の要素追加のみ。`resolvePrivateTitle` の
      `@var array<string, string> $appTitles` を満たす string 値)
- [x] null 安全: 追加キーは全て非 null string
- [x] DTO を返す箇所ではない (config 値)
- [x] Generics 影響なし

### テスト計画

S2 に集約 (config 値の効果は SeoManager 公開メソッド経由で検証)。

### リスク

- 文言ドリフト (h1 変更時に config 追随漏れ) — コメントの運用契約 + S2 の固定テストで緩和。
- 既存 6 エントリ以外への影響なし (連想配列への追記のみ、既存キー不変)。

---

## S2: SeoManager が 6 ルートで固有 title を返す Feature テストを追加

### 変更箇所

- ファイル: `tests/Feature/Seo/SeoManagerTest.php` (末尾に `it(...)` を 1 ケース追加)

### 波及変更

- 既存テストの削除・上書き: **なし** (追記のみ)
- `DatabaseTransactions` 個別使用: **なし** (`tests/Pest.php` グローバル `RefreshDatabase` に従う。
  本テストは DB 非依存だが規約通り個別トランザクション trait を持ち込まない)

### 設計方針

- 既存の「resolveDocumentTitle: private 経路は app_titles を合成」テスト (L67-74) と
  **同じ書式** (実 config を上書きせず、`config/seo.php` の実値を読む形にする)。
  ただし既存テストは `beforeEach` で `site_name => 'Acme'` を設定しているため、本ケースでも
  その文脈を利用し `{固有名} | Acme` を期待値にする (実 site_name 'AI-CUE' に依存しない =
  env 非依存で安定)。
- **実 config の app_titles を検証対象にする**ため、本ケースでは `config(['seo.app_titles' => ...])`
  で上書きせず、`config('seo.app_titles')` の実値 (S1 で追記したもの) を引く。これにより
  「config/seo.php に実際にエントリが存在すること」を保証する (drift でエントリが消えたら fail)。
- 6 ルート → 期待固有名の対応表を `dataset` もしくはループ assertion で網羅する。

### 追加テスト (擬似コード)

```php
it('resolveDocumentTitle: bug-hunt F-L2 の 6 アプリ画面が固有 title を返す (h1 と一致)', function (
    string $routeName,
    string $expectedFragment,
): void {
    // 実 config/seo.php の app_titles を検証対象にする (beforeEach は site_name のみ上書き)。
    $manager = new SeoManager;

    expect($manager->resolveDocumentTitle($routeName))
        ->toBe("{$expectedFragment} | Acme");

    // config の実値にも固有名が存在すること (エントリ欠落の drift を検出)
    expect(config("seo.app_titles.{$routeName}"))->toBe($expectedFragment);
})->with([
    'カテゴリ管理'    => ['projects.categories.index', 'カテゴリ管理'],
    'ユーザー管理'    => ['manage.users.index', 'ユーザー管理'],
    'API キー'        => ['organizations.api-keys.index', 'API キー'],
    '接続セッション'  => ['organizations.api-keys.sessions.index', '接続セッション'],
    'CLI 導入ガイド'  => ['organizations.onboarding.cli', 'CLI 導入ガイド'],
    'MCP 導入ガイド'  => ['organizations.onboarding.mcp', 'MCP 導入ガイド'],
]);
```

**注意点**:
- `beforeEach` が `seo.site_name => 'Acme'` / `seo.title_separator => ' | '` を設定するが
  `seo.app_titles` は上書きしないため、実 config 値が読まれる (S1 の追記が効く)。
- 既存の L67 テストは `config(['seo.app_titles' => [...]])` でローカル上書きしているので
  本ケースと干渉しない (テストは相互独立)。

### PHPStan 適合チェック

- [x] `it(...)` クロージャ引数に型注釈 (`string $routeName, string $expectedFragment`)
- [x] `config("seo.app_titles.{$routeName}")` の戻りを `toBe(string)` で検証 (mixed を放置しない)
- [x] 戻り値 void

### テスト計画

- [x] バグ修正の再現: S1 未適用状態ではこのテストが fail する (未登録 route はサイト名 'Acme'
      のみ → `{固有名} | Acme` と不一致)。S1 適用で green。テストファーストで先に追加し fail を確認する。
- [x] 既存テスト `tests/Feature/Seo/SeoManagerTest.php` の他ケースは不変 (追記のみ)
- [x] 新規テスト: 6 ルート dataset で `resolveDocumentTitle` と実 config 値を検証
- [x] 個別 `DatabaseTransactions` 不使用
- [x] 検証コマンド: `composer test -- --filter=SeoManagerTest` / `composer phpstan` /
      `vendor/bin/pint --test`

### リスク

- なし (追記のみ、既存テスト非破壊、DB 非依存)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | config 1 ファイル + テスト 1 ファイルの局所追記。既存ロジック・型・API を一切変えず、他施策と競合する共有面がない。worktree での短時間実装に適する。 |
| 競合リスク | 極小。`config/seo.php` の `app_titles` 連想配列への追記と `SeoManagerTest.php` への `it()` 追記のみ。並行して SEO title 経路を触る他タスクがなければ衝突しない。 |

## 使命・禁止事項 最終チェック

- 使命寄与: 「思考ゼロ」運用を支える基本アクセシビリティ (タブ/履歴/スクリーンリーダーでの画面識別)。間接的だが正当。
- 禁止事項: 全て非該当 (テスト必須=S2 で担保 / PHPStan 影響なし / `response()->json()` 不使用 / Prism・prompt 無関係 / redirect・disabled UI 無関係)。
- コーディングルール: PHPStan L10 維持 / Pest テスト追加 / RefreshDatabase グローバル準拠 / DTO 該当なし。
