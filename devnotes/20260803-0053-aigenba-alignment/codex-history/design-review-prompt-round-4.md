Round 3 のレビューを受けて詳細設計を再改訂しました。

1. **施策 6 Critical (プローブが 2FA gate に遮断される)**: 完全に見落としでした。`RequireTwoFactorForEnforcedOrganizations` は `web` グループ append なので全 web route に効き、2FA 強制中は 409/redirect が返って **reload ループ**になります。既存の `ALLOWED_ROUTE_NAMES` 機構 (route name → 理由の連想配列、`TwoFactorEnforcementAllowlistTest` が鮮度保証) に `session.status` を登録する形にしました。web グループ append の他 middleware も一つずつ確認し、遮断要因が 2FA gate のみであることを表にしています。Feature テスト (2FA 強制中 / recent-auth 期限切れ / 組織未選択で必ず 200 boolean) も追加しました。
2. **施策 2 Warning (出自判定が未確定)**: ご指摘のとおり controller namespace 方式は closure route と「vendor controller をアプリ側で登録する route」を誤分類します。第 2 案を採り、**inventory に第 5 分類 `EXTERNAL` を追加して出自判定そのものを不要**にしました。あわせて IV-7 の保証の限界 (機械的な意味判定ではなく「新 param 出現時に人間の分類を強制する」) も正直に明記しました。
3. **施策 7 Warning (WebKit の記述が取り残されていた)**: 施策 8 で WebKit を必須にしたのに施策 7 の運用文書だけ R1 時点の「未対応」のままでした。`Current` を Chromium + WebKit に更新し、実装途中の状態は設計書にのみ残す形にしました。

fetch の厳密判定 (`ok` + `Content-Type` + JSON shape)、`withResponse()` でのヘッダ付与、テスト専用 route を `routes/` に置かない方針、vitest の責務表現、負のコントロール (IV-7/IV-8) も反映済みです。

再レビューをお願いします。全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。

## 対応マトリクス (Round 3)

# 対応マトリクス: design-review Round 3

## 施策 6 [Critical] `/session/status` が既存の認証後 middleware に遮断され reload ループになり得る

- **判断: 対応する（実装可能性に直結する重大な見落とし）**
- **根拠**: 全面的に正しい。`RequireTwoFactorForEnforcedOrganizations` は
  `bootstrap/app.php` の **`web` グループ append** に登録されているため、
  **web グループの全 route に効く**。プローブもその対象になり、
  2FA 強制中のユーザーには **409 / redirect** が返る。
  guard は 200 boolean 以外をプローブ失敗として扱うため、
  **有効なセッションなのに秘匿が解除されず、再試行 → 同じ結果 → ループ**になる。
- **対応内容**: 既存の allowlist 機構に載せる。
  - `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`（`app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php:41`）は
    **route name → 必要理由**の連想配列で、`TwoFactorEnforcementAllowlistTest` が
    「全エントリが実在する named route」「各エントリが非空の理由を持つ」を CI で固定している。
    **本リポジトリに確立済みの exemption 作法**なので、`session.status` をここへ登録する。
  - 安全性: プローブの応答は **`{ authenticated: bool }` のみで PII も操作も含まない**ため、
    2FA 強制中に 200 を返しても情報露出にならない。
  - **web グループ append の他 middleware も確認済み**:
    `BlockTwoFactorDisableForEnforcedOrganizations` は 2FA disable route 限定、
    `HandleInertiaRequests` / `SecurityHeaders` は遮断しない。
    `RequireRecentAuth` / `RequireActiveSubscription` / `verified` は **route レベル**適用で
    プローブ route には付かない。→ **遮断要因は 2FA gate のみ**。
  - **Feature テストを追加**する: **2FA 強制中 / recent-auth 期限切れ / 組織未選択**の各状態で
    **必ず 200 + boolean** を返すこと。

## 施策 6 [Warning] `SessionStatusResource` にヘッダを付ける方法が未確定

- **判断: 対応する**
- **根拠**: 正当。Controller の戻り値を Resource に固定したままだと、
  ヘッダ付与のフックが設計から抜けている。
- **対応内容**: **`JsonResource::withResponse()`** で `no-store, private` を設定すると明記した。
  Controller の戻り値型は `SessionStatusResource` のまま保てる
  （既存 `RecentAuthStatusResource` は controller 側で付けているが、
   プローブは guest 応答も対象なので **Resource 側に閉じる方が漏れない**）。

## 施策 6 [Warning] `fetch()` の HTTP 成功だけで JSON を信用すると HTML redirect / 409 body を誤処理する

- **判断: 対応する**
- **根拠**: 正当。`fetch` は redirect を自動追従するため、
  login ページの **HTML が 200 で返る**ケースがある。
  `res.ok` だけで JSON を期待すると誤判定する。
- **対応内容**: 判定条件を厳密化した。以下を**全て満たした場合のみ**判定に採用する:
  1. `response.ok`（2xx）
  2. `Content-Type` が JSON
  3. JSON shape が厳密に成立（`authenticated` が `boolean`）

  いずれか 1 つでも崩れたら **プローブ失敗**へ倒す（= 秘匿維持 + 再試行導線）。

## 施策 6 [Suggestion] vitest では実際の描画露出は検証できない

- **判断: 対応する**
- **対応内容**: 負のコントロールの表現を
  「旧 DOM が可視」→ **「秘匿属性が付いていない」** に言い換えた。
  テスト責務（vitest は属性・分岐の検証、実描画は E2E）を正確にした。

## 施策 2 [Warning] route 出自判定が「実装時に候補から選ぶ」のままで未確定

- **判断: 対応する（方式そのものを変更した）**
- **根拠**: 正当。指摘のとおり controller namespace 方式は
  **closure route** と **vendor controller をアプリ側で登録する route（Fortify 等）** を
  正しく分類できない。**実装時判断として残すべきではない**。
- **対応内容**: Codex の第 2 案を採り、**出自判定そのものを不要にした**。
  - inventory に **第 5 分類 `EXTERNAL`**（vendor route が持ち込む param 名）を追加する。
  - **IV-1 は全 route（vendor 含む）を走査**し、
    **現れる全 param 名が 5 分類のいずれかに登録されていること**を要求する。
    → **出自を判定する必要が無くなる**。
  - **限界を正直に書く**: IV-7（衝突検出）が保証するのは
    「**新しい param 名が現れた時点で人間の分類を強制する**」ことであって、
    「vendor が `{user}` を非数値用途で使っていることを機械的に意味判定する」ことではない。
    新規 param は必ず未登録 → IV-1 が fail → 分類時に人間が既存 `BIGINT` との衝突に気づく、
    という**強制レビュー**が実質的な防御になる。この限界を設計に明記する。

## 施策 2 [Warning] 負のコントロール計画が IV-1・IV-3 までしか無い

- **判断: 対応する**
- **対応内容**: **IV-7 / IV-8 の負のコントロール**を追加した。
  - IV-7: fixture の vendor route に未登録 param を持たせて fail することを確認
  - IV-8: `BIGINT_PATTERN` を `[0-9]+` に変えると fail することを確認

## 施策 2 [Suggestion] リスク表が見出しで分断され IV-2 の行が表外に出ている

- **判断: 対応する**
- **対応内容**: 文書構造を修正し、リスク表を分断しないよう節を並べ替えた。

## 施策 3 [Suggestion] `{organization:未許可 field}` のテスト専用 route が production inventory に混入する

- **判断: 対応する（施策 2 の IV-1 と直接ぶつかるため重要）**
- **根拠**: 正当。IV-1 が全 route を走査する設計にしたため、
  テスト用 route を `routes/` に置くと **inventory 登録が必要になり本番 route を汚す**。
- **対応内容**: **テスト内で route を定義する**（`Route::get(...)` をテストケース内で登録し、
  そのテストの中でだけ有効にする）。`routes/` 配下には置かない。
  IV-1 は別テストで実行されるためテスト用 route を観測しない。この方針を設計に明記した。

## 施策 4 [Suggestion] 「最終応答」より「下流から返った応答」が正確

- **判断: 対応する**
- **根拠**: 正当。`$next` 後の応答は**さらに外側の middleware がまだ変更できる**ため
  「最終」ではない。
- **対応内容**: 表現を **「`$next` から返った（= 下流の）応答」**に修正した。

## 施策 7 [Warning] 同じ PR で WebKit を必須導入するのに、文書が WebKit を「Target・未対応」としている

- **判断: 対応する**
- **根拠**: 正当。施策 8 で WebKit レーンを**必須の実装完了条件**にしたのに、
  施策 7 の運用文書だけが R1 時点の「未対応」記述のまま取り残されていた。
  **運用文書はマージ後の実態を書くべき**で、実装途中の状態は設計書に残せばよい。
- **対応内容**: 施策 7 の `Current` を **Chromium + WebKit** に更新し、
  「未対応事項」から WebKit レーンを削除した。
  実装途中で WebKit が未導入であることは**本詳細設計書にのみ**残す。
  併せて施策 8 の完了条件と施策 7 の保証表を**同期**させた。

## 施策 1 [Suggestion] IV-8 の pin と regex 境界テストは重複気味

- **判断: 対応不要（安全性重視として許容と明示されているため現状維持）**
- **根拠**: 役割が違う。IV-8 は「値が変えられた」ことの検出（Architecture）、
  regex 境界テストは「その値が意図どおり 18/19 桁を分ける」ことの検証（Unit）。
  重複コストは小さく、`[0-9]+` への退行という**実害の大きい変更**を二重に防げる。

## 施策 5 / 施策 8 / 施策 9〜14

- **判断: 対応不要**（APPROVE。施策 8 の Warning は施策 7 の同期で解消済み）

---

## 再改訂版 詳細設計書

# 詳細設計: aigenba 整列 (決済ドメイン以外)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan` = `bash scripts/phpstan.sh analyse --memory-limit=2G`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- 概念設計: [`conceptual-design.md`](./conceptual-design.md) （Codex 合議 Round 5 で **APPROVED**）
- 監査台帳: [`../20260802-1548-aigenba-alignment-audit/audit.md`](../20260802-1548-aigenba-alignment-audit/audit.md)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 | トラック |
|---|--------|------------|--------|---|
| **1** | route binding 型制約の適用 (bigint / uuid) | `app/Providers/AppServiceProvider.php`, `app/Http/Routing/RouteBindingTypes.php` (新規), `app/Http/Routing/NormalizesRouteBindingInput.php` (新規), `tests/Unit/Routing/RouteBindingPatternTest.php` (新規), `docs/architecture.md` | Critical | T-a |
| **2** | route binding total inventory gate | `tests/Architecture/RouteBindingTypeConstraintInventoryTest.php` (新規) | Critical | T-a |
| **3** | 非適合セグメント → 404 の実挙動テスト | `tests/Feature/Routing/RouteBindingTypeConstraintTest.php` (新規) | Critical | T-a |
| **4** | no-store baseline middleware (P3-a) | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` (新規), `bootstrap/app.php` | High | T-a |
| **5** | 既存 no-store 4 経路のヘッダ完全値ピン | `tests/Feature/Security/ExistingNoStoreContractTest.php` (新規) | High | T-a |
| **6** | bfcache 秘匿・再検証 (P3-b) | `resources/js/lib/bfcache-guard.ts` (新規), `resources/js/app.ts`, `resources/css/`, `SessionStatusController`/`Dto`/`Resource` (新規), `routes/web.php`, `RequireTwoFactorForEnforcedOrganizations` (allowlist 追記) | High | T-a |
| **7** | サポート対象ブラウザ方針の明文化 (P3-c) | `docs/supported-browsers.md` (新規), `AGENTS.md` | High | T-a |
| **8** | P3 の Browser E2E 4 シナリオ + **WebKit レーン追加** | `tests/Browser/AuthenticatedPageBfcacheTest.php` (新規), `scripts/run-browser-test.sh`, `docs/testing-browser.md` | High | T-a |
| **9** | adjudication registry の機構修復 | `.claude/skills/app-bug-hunt/ledger/validate_findings.py` | Critical | T-b |
| **10** | registry データ棚卸し + 運用ガード固定 | `ledger/adjudications.jsonl`, `ledger/README.md`, `spec-ledger.md` (新規) | Critical | T-b |
| **11** | 汎用 Architecture gate 移植 (6 本) | `tests/Architecture/*.php` (新規) | Medium | T-c |
| **12** | JS gate 移植 (1 本) | `tests/js/architecture/pages-path-case-invariant.test.ts` (新規) | Medium | T-c |
| **13** | bug-hunt 文書 + docs 整備 | `capability-catalog.md`, `docs/*.md` (新規 2 本) | Low | T-c |
| **14** | aigenba へ返す handoff 文書 | `devnotes/.../aigenba-handoff.md` (新規) | Low | T-c |

---

# 施策 1: route binding 型制約の適用 (bigint / uuid)

### 変更箇所

- `app/Providers/AppServiceProvider.php` — `boot()` に型制約の一括適用を追加（現行 L154 の `Route::bind('organization', ...)` の直後）
- `app/Http/Routing/RouteBindingTypes.php` — **新規**。total inventory の単一 source of truth

### 波及変更

- TypeScript型定義: **なし**
- API Resource/DTO: **なし**
- **ドメイン制約の導入（重要・design-review R2 Warning）**: `[0-9]{1,18}` は DB の bigint が
  許容する **19 桁 ID を意図的に排除する**。「適合値の挙動は不変」ではなく、
  **「AI-CUE の route key は最大 18 桁」という新しいドメイン制約を導入している**。
  この制約は `RouteBindingTypes` の docblock と `docs/architecture.md` に記録し、
  施策 2 の Architecture テストで **`BIGINT_PATTERN` の値自体を pin** する
  （将来 `[0-9]+` に戻す変更を検出するため）
- テストファイル: 施策 2（Architecture）・施策 3（Feature）・**regex 単体テスト（Unit）**を新規追加

### 設計判断: なぜ `Route::pattern` を使うのか

概念設計 Round 1 Critical の結論どおり **global 一律適用はしない**が、
**inventory に登録した param 名に対して個別に `Route::pattern($name, $regex)` を呼ぶ**形にする。

| 候補 | 採否 | 理由 |
|---|---|---|
| 各 route に `->whereNumber()` を書く | **不採用** | web 約 120 + api 7 箇所への手書きは**漏れが必ず出る**。追加 route での付け忘れを人手に依存する |
| `Route::pattern` を inventory 駆動で適用 | **採用** | 適用漏れが構造的に起きない。inventory が単一 SoT になり施策 2 の gate と突合できる |
| global `Route::pattern('*', ...)` | **不採用** | `{organization:slug}` が全滅する（Round 1 Critical） |
| bigint param ごとに正規化 binder (`Route::bind`) を生やす | **不採用** | `Route::bind` を 11 個生やすことになる。`[0-9]{1,18}` の pattern で 22P02 / 22003 の両方を保証できる以上**過剰**（思考原則 #1 / #2）。ただしこの判断は施策 3 の実測で確定させる |

`Route::pattern` は Laravel 標準機構であり、**フレームワークのレンジ内**（思考原則 #1）。
制約に合致しないセグメントは**そもそも route にマッチしない = 404** になり、
`SubstituteBindings` に到達しないため DB クエリが発行されない（= 22P02 が起きない）。

### 現行コード

`app/Providers/AppServiceProvider.php`:

```php
        Route::bind('organization', MembershipScopedOrganizationBinder::class);
```

`routes/web.php`（該当箇所のみ）:

```php
        Route::patch('/notifications/{notification}/read', ...)
            ->whereUuid('notification')
            ->name('notifications.read');
```

### 変更後コード

**新規 `app/Http/Routing/RouteBindingTypes.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Http\Routing;

/**
 * route binding param の型 inventory（total inventory。分類漏れを禁止する）。
 *
 * 背景: pgsql は型不一致の比較で 22P02 (invalid input syntax) を投げるため、
 * 非適合セグメント (/projects/abc) が implicit binding に届くと QueryException →
 * **404 ではなく生 500** になる。AI-CUE はこのバグクラスを {notification} (whereUuid) と
 * {organization} (binder の normalizeIntegerId) で個別に潰していたが系統化されておらず、
 * bigint 11 param と uuid {oauthSession} が無防備だった (監査 2026-08-02)。
 *
 * 本 inventory は「全 binding param を 4 分類のいずれかに登録する」ことを要求する
 * 単一 source of truth であり、tests/Architecture/RouteBindingTypeConstraintInventoryTest が
 * routes 定義と突合して**未登録 param の出現を fail** させる (deny-by-default)。
 * 未知 param を数値と推測することはしない。
 *
 * 分類の意味:
 *  - BIGINT:        $table->id() の PK。数値制約を Route::pattern で適用する
 *  - UUID:          $table->uuid() / HasUuids の PK。UUID 制約を適用する
 *  - CUSTOM_BINDER: Route::bind の explicit binder が入力正規化を担う。pattern は適用しない
 *  - NON_MODEL:     モデル binding ではない文字列 param。型制約の対象外
 */
final class RouteBindingTypes
{
    /** bigint PK。Route::pattern で数値制約を適用する。 @var list<string> */
    public const BIGINT = [
        'analysisJob', 'apiKey', 'category', 'cut', 'invitation', 'item',
        'manual', 'project', 'renderJob', 'take', 'user',
    ];

    /** UUID PK。Route::pattern で UUID 制約を適用する。 @var list<string> */
    public const UUID = ['notification', 'oauthSession'];

    /**
     * explicit binder が入力正規化を担う param。**pattern は適用しない**。
     * {organization} は {organization:slug} を併用するため数値制約を掛けると
     * slug route が全滅する (概念設計 Round 1 Critical)。
     *
     * @var array<string, class-string>
     */
    public const CUSTOM_BINDER = ['organization' => MembershipScopedOrganizationBinder::class];

    /** モデル binding ではない文字列 param。型制約の対象外。 @var list<string> */
    public const NON_MODEL = [
        'ability', 'action', 'bucket', 'intent', 'provider', 'resource', 'userId',
    ];

    /**
     * vendor (Passport 等) の route が持ち込む param 名。**pattern は適用しない**。
     *
     * この分類を設けることで、inventory gate は「route の出自を判定する」必要が無くなる
     * (全 route を走査し、現れる param 名が 5 分類のいずれかに在ることだけを要求すればよい)。
     * Laravel の Route は route ファイルの出自を保持せず、controller namespace 方式は
     * closure route や「vendor controller をアプリ側で登録する route」を誤分類するため、
     * 出自判定に依存しない本方式を採る (design-review R3 Warning)。
     *
     * @var list<string>
     */
    public const EXTERNAL = [
        // 実装時に `Route::getRoutes()` を実走査して確定する (Passport / health check 等)。
    ];

    /**
     * bigint PK の route 制約。**18 桁上限**にすることで 2 種類の pgsql 例外を同時に塞ぐ。
     *
     *  - 非数値 (/projects/abc) → 22P02 invalid_text_representation
     *  - 桁あふれ (/projects/<30桁>) → **22003 numeric_value_out_of_range**
     *
     * `[0-9]+` だと後者が regex を通過して DB へ到達し 500 になる (design-review R1 Critical)。
     * bigint / PHP_INT_MAX = 9223372036854775807 (**64bit PHP 前提**) は 19 桁なので、
     * 18 桁の最大値 999999999999999999 は必ず範囲内 = **桁数だけで範囲内を保証できる**。
     *
     * **これはドメイン制約の導入である**: DB の bigint が許容する 19 桁 ID を意図的に排除し、
     * 「AI-CUE の route key は最大 18 桁」と定める。実 ID が 10^18 に達することは無いため
     * 運用上の制約にならないが、「適合値の挙動が不変」ではない点に注意
     * (docs/architecture.md に記録。値自体を Architecture テストで pin する)。
     *
     * 先頭ゼロ ('007') は本 pattern にマッチするが pgsql は '007'::bigint を正常に解釈するため
     * 500 にならない (該当行なしで 404)。canonical URL の要件は別問題なのでここでは制約しない。
     */
    public const BIGINT_PATTERN = '[0-9]{1,18}';

    /** Laravel の UUID 制約 (whereUuid 相当)。 */
    public const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * 登録済みの全 param 名（gate が routes 定義と突合するために使う）。
     *
     * @return list<string>
     */
    public static function allRegistered(): array
    {
        return [
            ...self::BIGINT,
            ...self::UUID,
            ...array_keys(self::CUSTOM_BINDER),
            ...self::NON_MODEL,
            ...self::EXTERNAL,
        ];
    }
}
```

**`app/Providers/AppServiceProvider.php`（`boot()` 内、既存 `Route::bind` の直後）**:

```php
        Route::bind('organization', MembershipScopedOrganizationBinder::class);

        // route binding 型制約 (RouteBindingTypes が単一 SoT)。
        // 非適合セグメントは route にマッチしない = 404 になり、SubstituteBindings へ
        // 到達しないため pgsql 22P02 (→ 生 500) が構造的に起きない。
        // CUSTOM_BINDER (= {organization}) は binder 側が正規化するため pattern を適用しない
        // ({organization:slug} を併用しており数値制約は掛けられない)。
        foreach (RouteBindingTypes::BIGINT as $param) {
            Route::pattern($param, RouteBindingTypes::BIGINT_PATTERN);
        }
        foreach (RouteBindingTypes::UUID as $param) {
            Route::pattern($param, RouteBindingTypes::UUID_PATTERN);
        }
```

### 後方互換の並走を残さない（思考原則 #3）

`routes/web.php:358,361` の `->whereUuid('notification')` は
`Route::pattern('notification', UUID_PATTERN)` と**同じ制約の二重掛け**になる。
**同じ PR で `whereUuid` 呼び出しを削除**し、L350 のコメントを
「型制約は `RouteBindingTypes` に集約」へ書き換える（旧実装を残さない）。

### PHPStan適合チェック

- [x] `allRegistered()` の戻り値を `list<string>` で明示（`array_keys` の結果は `list<string>` に収まる）
- [x] **regex 単体テスト（Unit）**: `BIGINT_PATTERN` に **18 桁が成功・19 桁が失敗**することを直接検証する。
      Feature テストでは 18 桁も 19 桁も最終結果が 404 で**区別できない**ため、
      「route にマッチした」ことの証明はこの層で行う（design-review R2 Warning）
- [x] const 配列に `@var list<string>` / `array<string, class-string>` を付与
- [x] null 安全: null を扱わない（全て const と foreach）
- [x] DTO 返却なし（本施策は値オブジェクトを返さない。const と静的メソッドのみ）

### テスト計画

- [x] バグ修正の再現テストを先に書く（施策 3）。`/projects/abc` と `DELETE .../sessions/abc` が
      **現状 500 で fail** することを確認してから本施策を実装する
- [x] 施策 2 の inventory gate も**先に落ちる**ことを確認する（未制約 param があるため）
- [x] 既存テストの更新: `tests/Feature/Notifications/NotificationCenterTest.php` が
      `whereUuid` 由来の 404 を検証している可能性があるため、`whereUuid` 削除後も green を確認する
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

| リスク | 対策 |
|---|---|
| **`{user}` が数値以外で bind されている route が存在する** | 施策 3 で全 bigint param の 404 化を確認する。もし slug/username bind の route があれば inventory の分類を訂正する（gate が突合するため設計時に気づける） |
| `Route::pattern` は**全 route に効く**ため、同名 param を非モデル用途で使う route があると壊れる | `NON_MODEL` に `userId` を分けてあるのはこのため（`{user}` と `{userId}` は別 param）。gate が全 param を突合するので混入は検出される |
| `whereUuid` 削除で notification の制約が緩む | `Route::pattern('notification', UUID_PATTERN)` が同一制約を掛ける。施策 3 で 404 を実測して固定する |
| **桁あふれ (22003) が残る** | `BIGINT_PATTERN` を `[0-9]{1,18}` にして regex だけで範囲内を保証する。**施策 3 で範囲外・極長桁を実測**して確定させる (design-review R1 Critical) |
| **vendor / 将来の非モデル route が同名 param を使い `Route::pattern` と衝突する** | 施策 2 の **IV-7 (衝突検出)** が検出する。衝突時は (a) param 名を分離する か (b) 当該 param を `Route::pattern` から外し個別 `where` へ切替える |

---

# 施策 2: route binding total inventory gate

### 変更箇所

- `tests/Architecture/RouteBindingTypeConstraintInventoryTest.php` — **新規**

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし / テストファイル: 本施策自体が成果物

### 設計方針

**deny-by-default の total inventory**。`Route::getRoutes()` から全 route の binding param を集め、
`RouteBindingTypes::allRegistered()` と突合する。

| 検証 | 内容 |
|---|---|
| **IV-1 (分類漏れ禁止)** | **全 route（vendor 含む）**に現れる全 param が inventory の **5 分類**のいずれかに登録されていること。**未登録は fail**（メッセージで「型・解決方式・除外理由を登録せよ」と促す） |
| **IV-2 (逆方向)** | inventory に登録済みだが routes に現れない param が無いこと（陳腐化した登録の検出） |
| **IV-3 (bigint 制約)** | `BIGINT` の全 param が `Route::pattern` で数値制約を持つこと |
| **IV-4 (uuid 制約)** | `UUID` の全 param が UUID 制約を持つこと |
| **IV-5 (custom binder)** | `CUSTOM_BINDER` の param に対応する binder クラスが実在し、**`NormalizesRouteBindingInput`（分類宣言）を実装している**こと。かつ **pattern が適用されていない**こと（`{organization:slug}` を壊さないため）。※ **入力正規化の実効性は本 gate では保証しない**（下記） |
| **IV-6 (排他性)** | 同一 param が複数分類に重複登録されていないこと |
| **IV-7 (衝突検出)** | **vendor / 非アプリ route が inventory 登録済みの param 名を使っていない**こと。`Route::pattern` は global なので、vendor が `{user}` 等を非モデル用途で使うと壊れる（design-review R1 Warning） |
| **IV-8 (pattern 値の pin)** | `BIGINT_PATTERN` が `[0-9]{1,18}` であること。`[0-9]+` へ戻すと桁あふれ 22003 が復活するため、**値自体を固定**する（design-review R2 Warning） |

#### IV-5 の責務分離（design-review R1 → R2 で 2 度修正）

R1 では「メソッド名依存は脆い」ため interface 化した。しかし **空の marker interface は
空実装でも通過するため、それ自体は何も保証しない**（R2 Critical 相当の Warning）。
そこで**責務を分けて**扱う。

| 層 | 何を担うか |
|---|---|
| **marker interface（分類の宣言）** | 「この param は `Route::pattern` ではなく binder が担う」という**意思表示**。IV-5 はこれと「pattern 未適用」を検証する |
| **binder ごとの Feature テスト（実効性の正本）** | **入力正規化が実際に効いていること**。施策 3 に `{organization}` の異常系を追加する |

```php
namespace App\Http\Routing;

/**
 * CUSTOM_BINDER 分類の宣言用 marker。
 *
 * この interface 自体は挙動を強制しない (空 interface のため空実装でも通る)。
 * **入力正規化が実際に効いていることの正本は Feature テスト**
 * (tests/Feature/Routing/RouteBindingTypeConstraintTest の {organization} 異常系) である。
 *
 * 本 interface の役割は「この param は Route::pattern による宣言的制約を適用できず
 * ({organization} は {organization:slug} を併用するため)、binder が 22P02 / 22003 相当の
 * 入力を弾く責務を負う」という分類を型で表明することに限られる。
 */
interface NormalizesRouteBindingInput {}
```

#### IV-7 の衝突時の運用と、**保証の限界**（design-review R3 Warning）

衝突を検出したら、次のいずれかを取る（gate のメッセージに明記する）:

1. **param 名を分離する**（例: vendor が `{user}` を使うならアプリ側を `{appUser}` へ）
2. **当該 param を `Route::pattern` の適用対象から外し、個別 `where` へ切り替える**
   （inventory の分類は維持し、制約の掛け方だけ変える）

**限界を正直に書く**: IV-7 が保証するのは
**「新しい param 名が現れた時点で人間の分類を強制する」**ことであって、
「vendor が `{user}` を非数値用途で使っていることを機械的に意味判定する」ことではない。
新規 param は必ず未登録 → **IV-1 が fail** → 分類時に人間が既存 `BIGINT` との衝突に気づく、
という**強制レビュー**が実質的な防御になる。

### 実装スケッチ

```php
<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Http\Routing\RouteBindingTypes;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * route binding param の型制約 total inventory gate（deny-by-default）。
 *
 * 不変条件: routes に現れる**全ての** binding param が RouteBindingTypes の 4 分類の
 * いずれかに登録され、分類に応じた制約を持つ。未登録 param の出現は fail させる
 * （未知 param を数値と推測しない ＝ 概念設計 Round 2 の決定）。
 *
 * 守る事故: pgsql の型不一致 22P02 / 桁あふれ 22003 → QueryException →
 * **404 ではなく生 500**。
 * 実挙動 (非適合→404) と custom binder の入力正規化の実効性は
 * tests/Feature/Routing/RouteBindingTypeConstraintTest が担保する
 * (本 gate は分類の網羅と制約の適用のみを見る)。
 */
final class RouteBindingTypeConstraintInventoryTest extends TestCase
{
    // IV-1 〜 **IV-7** を it() で分割して実装する。
    // IV-8: BIGINT_PATTERN / UUID_PATTERN の値自体を pin する
    //       (`[0-9]+` へ戻す退行の検出。design-review R2 Warning)
}
```

**param の抽出方法**: `Route::getRoutes()` を走査し、各 `$route->parameterNames()` を集める。
`{organization:slug}` のような field 指定は `parameterNames()` が `organization` を返すため、
field を剥がす追加処理は不要。

**pattern の確認方法**: `$route->wheres` に param → regex が入る（`Route::pattern` は
route 登録時に merge される）。`RouteBindingTypes::BIGINT_PATTERN` との一致で検証する。

### PHPStan適合チェック

- [x] `parameterNames()` の戻り値を `list<string>` として扱う（Laravel の型定義に従う）
- [x] `$route->wheres` は `array<string, string>`
- [x] Assert は Pest の `expect()` を使い、null 分岐を作らない

### テスト計画

- [x] **負のコントロール（IV-1）**: inventory から `project` を一時的に外すと fail することを確認
- [x] **負のコントロール（IV-3）**: `Route::pattern` の適用をコメントアウトすると fail することを確認
- [x] **負のコントロール（IV-7）**: fixture の vendor route に未登録 param を持たせると fail することを確認
- [x] **負のコントロール（IV-8）**: `BIGINT_PATTERN` を `[0-9]+` に変えると fail することを確認
- [x] 負のコントロールは**実ファイルを書き換えず fixture に対して実行**する
- [x] 個別の `DatabaseTransactions` を使っていないことを確認（本テストは DB 不使用）

### リスク

| リスク | 対策 |
|---|---|
| Filament / Passport / Livewire 等の**ベンダー route** が param を持ち込み IV-1 が誤 fail する | 走査対象を**アプリ route に限定**する。判定は**登録元ベース**で固定し、**URI 文字列ベースの除外は禁止**する。なお vendor route は IV-1 の対象外だが **IV-7 の衝突検出の対象には含める**（`Route::pattern` が global に効くため） |

#### 出自判定は行わない（design-review R3 Warning で方式変更）

R2 では「controller namespace で app route か判定する」候補を残していたが、
この方式は **closure route** と **vendor controller をアプリ側で登録する route（Fortify 等）** を
正しく分類できず、**実装時判断として残すのは危険**という指摘を受けた。

**方式を変更し、出自判定そのものを不要にした**:

- inventory に **第 5 分類 `EXTERNAL`**（vendor route が持ち込む param 名）を追加する
- **IV-1 は全 route（vendor 含む）を走査**し、現れる全 param 名が
  **5 分類のいずれかに登録されている**ことだけを要求する

これで「この route はアプリ由来か」を判定する必要が無くなる。
`EXTERNAL` の初期値は**実装時に `Route::getRoutes()` を実走査して確定**する
（これは「未確定の設計判断」ではなく、**実データの採取**である）。
| IV-2（逆方向）が worktree 差分で誤 fail する | inventory は「routes に現れうる param」の集合。将来 route を消したら登録も消す運用にする（gate が促す） |

---

# 施策 3: 非適合セグメント → 404 の実挙動テスト

### 変更箇所

- `tests/Feature/Routing/RouteBindingTypeConstraintTest.php` — **新規**

### テスト計画（fail-first を先に確認する）

**前提を各ケースに明示する**（design-review R1 Warning）。認証 / CSRF / 認可に吸われると
「404 が binding 由来か認可由来か」が区別できないため、**適合値の対比ケースを必ず併記**し、
「非適合だけが 404 になる」ことを対比で示す。

| # | ケース | 前提 | 期待 | 現状 |
|---|---|---|---|---|
| 1 | `GET /projects/abc`（bigint・**非数値**） | 認証済み・当該 org メンバー | **404**（500 でない） | **500 で fail** |
| 1' | `GET /projects/{実在ID}`（**対比**） | 同上 | **404 でない**（200 等） | green |
| 2 | `GET /projects/9223372036854775808`（**PHP_INT_MAX+1 = 19 桁**） | 同上 | **404**（500 でない） | **500 で fail** |
| 3 | `GET /projects/<30 桁>`（**極長数値**） | 同上 | **404** | **500 で fail** |
| 4 | `GET /projects/999999999999999999`（**18 桁上限値**） | 同上 | **404**（route にはマッチする = 制約が過剰に狭くない） | green |
| 5 | `GET /projects/007`（**先頭ゼロ**） | 同上 | **500 でない**（pgsql は正常解釈するため 404 想定） | green |
| 6 | `DELETE /organizations/{slug}/api-keys/sessions/abc`（uuid・非適合） | 認証済み・CSRF 付き・当該 org の管理権限 | **404** | **500 で fail** |
| 6' | `DELETE .../sessions/{実在 UUID}`（**対比**） | 同上 | **404 でない**（204/302 等） | green |
| 7 | 全 `BIGINT` param の代表 route に非数値を投げる | 各 route の前提を満たす | 404 | fail |
| 8 | `{organization:slug}` の route が**引き続き slug で解決する** | 認証済み・メンバー | 既存挙動 | green（回帰確認） |

> ケース 2・3 は **`[0-9]+` では通過して 22003 → 500 になる**ため、
> 施策 1 の `[0-9]{1,18}` が正しいことを実測で確定させる**本丸のケース**（design-review R1 Critical）。

#### custom binder（`{organization}`）の入力正規化 — 実効性の正本（design-review R2 Warning）

施策 2 の marker interface は**分類の宣言に過ぎず何も保証しない**ため、
`MembershipScopedOrganizationBinder` の入力正規化が実際に効いていることは**ここで固定する**。

| # | ケース | 期待 |
|---|---|---|
| 9 | `{organization}` に**非数値**（`/organizations/abc/...` の id bind 経路） | **404** |
| 10 | `{organization}` に **19 桁**（`9223372036854775808`） | **404** |
| 11 | `{organization}` に **30 桁** | **404** |
| 12 | `{organization:未許可 field}`（`BINDABLE_FIELDS` 外） | **404**（500 でない）。※ **テスト内で `Route::get(...)` を登録**し `routes/` には置かない（施策 2 の IV-1 が全 route を走査するため、本番 inventory を汚さない。design-review R3 Suggestion） |
| 13 | `{organization:slug}` に**実在 slug**（**対比**） | **200**（既存挙動の回帰確認） |

#### 対比ケースの fixture 要件（design-review R2 Warning）

対比ケース（1'・6'・13）は fixture が不完全だと**認可 / nested binding に吸われて
404 になり、対比の意味を失う**。したがって:

- **実在する親子関係を Factory で構築**する（Organization → Team → Project の階層、
  および `actingAs` するユーザーの当該組織メンバーシップ・必要な role）
- **期待ステータスを具体値で固定**する（「404 でない」ではなく `200` / `204` 等）

#### テスト環境契約（design-review R2 Suggestion）

本件は **pgsql 固有の事故**（22P02 / 22003）である。SQLite 等へ切り替わると
**非適合値でも例外にならず、テストが空振りで green になる**。
そのため接続 driver が `pgsql` であることを**テスト内で assert** し、
方言が変わったら気づけるようにする。

- ケース 1・2 を**先に書いて 500 を確認**してから施策 1 を実装する（AGENTS.md 思考原則 #5）
- テストデータは **Factory で生成**（`Model::create()` 手組み禁止）
- 認証が要る route は Factory で作った User で `actingAs()`
- 個別の `DatabaseTransactions` は使わない（`tests/Pest.php` のグローバル `RefreshDatabase` に従う）

### リスク

- ケース 3 の「既存挙動」はテナント境界により 403/404 に分岐しうる。
  **アサートは「500 でないこと」に寄せ**、具体的なステータスは既存テストの責務とする

---

# 施策 4: no-store baseline middleware (P3-a)

### 変更箇所

- `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` — **新規**
- `bootstrap/app.php` — `$middleware->web(append: [...])` の**末尾**に追加（現行 L82-89）

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 5（既存 4 経路のピン）・施策 8（Browser E2E）

### 現行コード

`bootstrap/app.php:82-89`:

```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
        ]);
```

### 変更後コード

```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
            // 認証済み応答の no-store baseline。
            // 契約: $next から返った (= 下流の) 応答を確認し、既に `no-store` を持つなら変更しない。
            // (位置関係ではなくこの契約が正本。実効性は Feature テストが固定する)
            NoStoreCacheHeadersForAuthenticatedPages::class,
        ]);
```

**新規 middleware**:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 認証済みリクエストの web 応答に `no-store` を保証する baseline middleware。
 *
 * 目的: ログアウト後のブラウザ「戻る」で認証済み画面 (メンバー一覧等の PII) が
 * bfcache から再表示されるのを防ぐ。`no-store` により Firefox は bfcache 格納自体を
 * 拒否し、Chrome は cookie 変更 (= ログアウト) 時に CCNS ページを bfcache から
 * eviction する。副次的に disk / proxy cache への認証済み応答残留も禁止される。
 *
 * **Safari は `no-store` でも bfcache に格納しうる**ため本 middleware だけでは
 * 抑止できない。AI-CUE は撮影が PWA (iOS Safari が主要プラットフォーム) であるため、
 * クライアント側の bfcache 秘匿・再検証 (resources/js/lib/bfcache-guard.ts) と
 * **セットで** 主便益を達成する。対象ブラウザは docs/supported-browsers.md。
 *
 * 適用判定は route 列挙ではなく「認証済みか」で行う (path 列挙は一般認証画面を
 * 取りこぼす)。guest / 公開ページ (login・LP・SEO) は対象外のままにし bfcache /
 * 共有キャッシュの恩恵を維持する。認証済み画面は Inertia SPA でアプリ内の戻る/進むは
 * client-side navigation のため bfcache 喪失による UX 後退はない。
 */
final class NoStoreCacheHeadersForAuthenticatedPages
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // logout POST は $next 通過後に guard 上の user が null になるため、
        // リクエスト時点の認証状態を先に捕捉する (= logout redirect も対象に含める)。
        $wasAuthenticated = $this->isAuthenticated($request);

        $response = $next($request);

        // リクエスト時点 or 応答時点のどちらかで認証済みなら付与対象
        // (login POST 応答 = 応答時点で認証済み、も保護側に倒す)。
        if (! $wasAuthenticated && ! $this->isAuthenticated($request)) {
            return $response;
        }

        // 既に no-store を持つ応答 (recent-auth 409 / 2FA 409 / 署名 URL redirect 等、
        // 内側で明示されたより厳格な値) は書き換えず維持する。
        // directive が縮む方向の上書きをしない。
        if ($response->headers->hasCacheControlDirective('no-store')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * 本 middleware の対象は session-backed な web 認証画面。session を持たない
     * リクエスト (routes/web.php:74,99 の stateless block: SEO/robots/公開ページは
     * StartSession を withoutMiddleware 済) は stateless 公開配信であり対象外。
     */
    private function isAuthenticated(Request $request): bool
    {
        return $request->hasSession() && $request->user() !== null;
    }
}
```

### 契約（概念設計 Round 3 で一意に確定）

| 応答の状態 | 挙動 |
|---|---|
| `Cache-Control` に **`no-store` を持つ** | **untouched** |
| `no-store` を**持たない** | `Cache-Control` を **`no-store, private` で置換** |

判定キーは `Cache-Control` の**存在ではなく `no-store` directive の有無**。
置換方式のため `public` / `max-age` 等の矛盾 directive は置換で消える。
**矛盾ヘッダの一般正規化は行わない**（思考原則 #2）。

### response class 判定を設けない理由

aigenba はヘッダ判定のみで運用している。AI-CUE の実測でも `StreamedResponse` は **0 件**、
`BinaryFileResponse` は `app/Http/Controllers/Testing/GetFakeStorageObjectController.php` の
**1 件のみ**（非 production の fake storage gate）。クラス判定を足す必要性が現時点で無い。

> **要確認（実装時）**: `GetFakeStorageObjectController` は `<video>` シーク用に
> Range 対応の `BinaryFileResponse` を返す。`no-store` 付与が Range リクエストの挙動を
> 壊さないことを施策 5 のテストで確認する。壊す場合のみクラス除外を追加する。

### 既存 4 経路への影響（実測値）

| 経路 | 現行値 | P3-a 適用後 |
|---|---|---|
| `FortifyServiceProvider:199`（招待 email を含む応答のみ） | `no-store` | **untouched** |
| 同上（招待 email が空の通常登録応答） | Cache-Control **なし** | `no-store, private` が付く（**強化方向。意図どおり**） |
| `RequireRecentAuth:57`（409 JSON） | `no-store` | **untouched** |
| `RequireTwoFactorForEnforcedOrganizations:93`（409 JSON） | `no-store` | **untouched** |
| `Capture/CaptureTakeController:177`（署名 URL への 302） | `no-store, private` | **untouched** |

### PHPStan適合チェック

- [x] 戻り値の型 `Response` を明示
- [x] `$request->user()` の null 判定を明示（`!== null`）
- [x] 判定を純粋な private メソッド `isAuthenticated()` に分離
- [x] DTO 返却なし（middleware のためヘッダ操作のみ）

### テスト計画

- [x] **fail-first**: 認証済みページの `Cache-Control` に `no-store` が付くテストを先に書き、fail を確認
- [x] 新規テスト: 認証済み Inertia 応答 → `no-store, private`
- [x] 新規テスト: guest / 公開ページ（LP・login）→ **付与されない**
- [x] 新規テスト: stateless block（SEO/robots）→ **付与されない**
- [x] 新規テスト: **logout POST の redirect 応答**にも付与される（`$wasAuthenticated` の効果）
- [x] 新規テスト: **login POST の応答**にも付与される（応答時点判定の効果）
- [x] 既存 4 経路のピンは施策 5
- [x] テストデータは Factory 生成 / 個別 `DatabaseTransactions` を使わない

### リスク

| リスク | 対策 |
|---|---|
| Range リクエスト（`<video>` シーク）が壊れる | 施策 5 で `GetFakeStorageObjectController` の挙動を確認。壊れる場合のみクラス除外 |
| 認証済みページの**共有キャッシュ恩恵が消える** | 認証済み応答は元々 `private` 相当であるべきで、後退ではない。guest / 公開ページは対象外 |
| bug-hunt の pcov middleware（`BughuntCoverageMiddleware`）と順序衝突 | `$middleware->append()`（L146）は web グループ外の global append。web 末尾の本 middleware とは独立 |

---

# 施策 5: 既存 no-store 4 経路のヘッダ完全値ピン

### 変更箇所

- `tests/Feature/Security/ExistingNoStoreContractTest.php` — **新規**

### 設計方針（概念設計 Round 3 Warning への対応）

`no-store` の**存在チェックだけでは `public, no-store` のような矛盾値を検出できない**。
4 経路それぞれについて **`Cache-Control` のヘッダ完全値**をピンする。

| # | 経路 | 期待完全値 |
|---|---|---|
| 1 | Fortify 登録応答（招待 email あり） | `no-store` |
| 2 | `RequireRecentAuth` の 409 | `no-store` |
| 3 | `RequireTwoFactorForEnforcedOrganizations` の 409 | `no-store` |
| 4 | `Capture/CaptureTakeController` の 302 | `no-store, private` |
| 5 | `GetFakeStorageObjectController` の Range 応答 | 実測して確定（施策 4 のリスク確認と兼ねる） |

### テスト計画

- [x] 各経路を実際に叩き、(a) `$response->headers->get('Cache-Control')` の**完全一致** と
      (b) **directive 集合（順序非依存）** の 2 段で assert する。
      **2 つのアサートは分離し、それぞれ固有のメッセージを付ける**
      （どちらが失敗したかで「順序だけ変わった」のか「意味が後退した」のかを判別できる。R1/R2 Suggestion）
- [x] P3-a 適用前後で**値が変わらない**ことを確認（untouched 契約の証明）
- [x] テストデータは Factory 生成 / 個別 `DatabaseTransactions` を使わない

### リスク

- 完全一致ピンは「意図的な強化」も落とす。**落ちたら期待値を更新する**運用でよい
  （落ちること自体が「契約が変わった」というシグナルとして機能する）

---

# 施策 6: bfcache 秘匿・再検証 (P3-b)

### 変更箇所

- `resources/js/lib/bfcache-guard.ts` — **新規**
- `resources/js/app.ts` — guard の初期化（**認証済みページのみ**）
- `resources/css/`（DS token 経由） — 秘匿オーバーレイのスタイル
- `app/Http/Controllers/Auth/SessionStatusController.php` — **新規**（軽量プローブ）
- `app/DataTransferObjects/Auth/SessionStatusDto.php` — **新規**
- `app/Http/Resources/Auth/SessionStatusResource.php` — **新規**
- `routes/web.php` — プローブ route の追加

### 波及変更

- TypeScript型定義: `PageTransitionEvent`（DOM 標準型）を明示。プローブ応答の型を `bfcache-guard.ts` 内に定義
- API Resource/DTO: **`SessionStatusDto` + `SessionStatusResource` を新設**（禁止事項 #4 遵守）
- テストファイル: 施策 8（Browser E2E）+ プローブの Feature テスト + guard 分岐の vitest

### 設計判断（概念設計 Round 4 Critical / design-review R1 Critical の反映）

**「復元後に検証」ではなく「検証完了まで復元内容を秘匿」**（概念設計 Round 4 Critical）。
`pageshow` 後に非同期検証する構造だと、検証完了までの間、**復元済みの古い DOM が表示され
PII が一瞬露出する**。「無効なら遷移する」は「再表示しない」と同義ではない。

**ただし hard reload は常用しない**（design-review R1 Critical）。
概念設計 Round 5 で第一候補としていた hard reload は、
**シナリオ 3（未ログアウトでの復元）で正当なユーザーの復元済みフォーム状態を無条件に破棄する**ため、
Round 4 で決めた「media stream / 未送信フォーム / Inertia 履歴を破棄しない」要件と**矛盾する**。

**確定した状態遷移**:

| # | 契機 | 動作 |
|---|---|---|
| 1 | `pagehide` | 画面を**同期的に秘匿**する = **`documentElement` に秘匿属性を付ける**（+ CSS でオーバーレイ表示）。**この DOM 状態ごと bfcache snapshot に入る**ことが要点 |
| 2 | `pageshow` | **`documentElement` に秘匿属性が付いていれば**（= bfcache 復元）、**秘匿状態のまま**軽量プローブでセッション有効性を確認する |
| 3 | セッション**有効** | **秘匿属性を外す（unhide）だけ**。DOM・フォーム状態・Inertia 履歴はそのまま |
| 4 | セッション**無効** | login へ **hard navigation**（遷移先は固定。下記） |
| 5 | プローブ**初回失敗** | **秘匿を維持**したまま**再試行ボタンを表示**する（自動再試行はしない） |
| 6 | ユーザーが**再試行を押下** | **現在 URL を hard reload**（サーバに再判定させる） |

これにより **PII の一瞬の露出も無く**（秘匿はプローブ完了まで解かない）、
かつ**正当なユーザーの状態も壊さない**（有効なら unhide のみ）。

### 軽量プローブ endpoint（概念設計 Round 4 の条件を全て満たす）

**既存 `/recent-auth/status` は流用しない**。あれは step-up 鮮度の endpoint であり、
セッション有効性とは**意味が違う**（思考原則「機能の名前に立ち返れ」）。
また recent-auth の provider 情報等、必要以上を露出する。

**最小の専用 endpoint を新設する**:

| 条件（Round 4） | 満たし方 |
|---|---|
| 同一オリジン | `routes/web.php` の web グループ（session cookie 前提） |
| `no-store` | 施策 4 の baseline middleware が付与（認証済み時）。**guest 応答にも明示付与**する |
| セッション認証 | web guard の session を参照する |
| **DTO + JsonResource** | `SessionStatusDto` → `SessionStatusResource`（禁止事項 #4） |
| PHPStan level 10 | 対象に含める |
| **PII を含まない** | 応答は `{ "authenticated": bool }` のみ |

```php
// routes/web.php — auth グループの **外**。guest でも 200 を返し、authenticated: false を伝える。
// auth グループ内に置くと未認証時に 302/401 になり、guard 側が「セッション無効」と
// 「endpoint 不在/エラー」を区別しにくくなるため。
Route::get('/session/status', SessionStatusController::class)->name('session.status');
```

> **なぜ guest でも 200 か**: guard は「無効なら login へ倒す」だけなので、
> ステータスコードではなく**明示的な boolean** を見る方が分岐が単純で誤判定しにくい。
> 認証状態そのものは同一オリジンの呼び出し元が cookie で既に知りうる情報であり、
> 新たな情報露出にはならない。

#### 既存 middleware からの exemption（design-review R3 Critical）

`RequireTwoFactorForEnforcedOrganizations` は `bootstrap/app.php` の **`web` グループ append** に
登録されており **web グループの全 route に効く**。プローブもその対象になるため、
2FA 強制中のユーザーには **409 / redirect** が返る。
guard は 200 boolean 以外を「プローブ失敗」として扱うので、
**有効なセッションなのに秘匿が解除されず、再試行 → 同じ結果 → ループ**になる。

**既存の allowlist 機構に載せる**:
`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`
（`app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php:41`）は
**route name → 必要理由**の連想配列で、`TwoFactorEnforcementAllowlistTest` が
「全エントリが実在する named route」「各エントリが非空の理由を持つ」を CI で固定している。
**本リポジトリに確立済みの exemption 作法**なので、`session.status` をここへ登録する。

> **安全性**: プローブの応答は `{ authenticated: bool }` のみで **PII も操作も含まない**ため、
> 2FA 強制中に 200 を返しても情報露出にならない。

**web グループ append の他 middleware も確認済み**:

| middleware | プローブへの影響 |
|---|---|
| `RequireTwoFactorForEnforcedOrganizations` | **遮断する → allowlist 登録が必要** |
| `BlockTwoFactorDisableForEnforcedOrganizations` | 2FA disable route 限定。影響なし |
| `HandleInertiaRequests` / `SecurityHeaders` | 遮断しない |
| `RequireRecentAuth` / `RequireActiveSubscription` / `verified` | **route レベル**適用。プローブ route には付かない |

→ **遮断要因は 2FA gate のみ**。

#### 応答ヘッダの付与方法（design-review R3 Warning）

**`JsonResource::withResponse()`** で `no-store, private` を設定する。
Controller の戻り値型を `SessionStatusResource` のまま保てる。
既存 `RecentAuthStatusResource` は controller 側で付けているが、
プローブは **guest 応答も対象**（施策 4 の baseline は認証済みのみ）なので
**Resource 側に閉じる方が漏れない**。

### guard の適用範囲（design-review R1 Critical）

**Inertia の共有 props（`auth.user`）を起点に「認証済みページのみ初期化」**する。
既存の `resources/js/lib/shared-props.ts` が共有 props ヘルパ。
LP・login・SEO 等の公開ページでは guard を初期化しない
（不要なちらつき・プローブを起こさない）。

### 復元マーカーは `documentElement` の秘匿属性そのもの（design-review R2 Critical）

**`sessionStorage` は使わない**。`sessionStorage` は**タブ単位で共有される**ため、
**ページ A の `pagehide` が立てたフラグを通常遷移先のページ B が読み、誤って秘匿・プローブする**
（R1 でフォールバックとして足したものが新しい誤動作を生んでいた）。

代わりに **`documentElement` の秘匿属性そのものを復元マーカーにする**:

| | 挙動 |
|---|---|
| bfcache 復元 | `pagehide` で付けた属性が **DOM ごと復元される** → `pageshow` 時に属性あり |
| 通常ナビゲーション | サーバから**新しい HTML** を取得 → 属性は**存在しない** |

= マーカーが**本質的に履歴エントリ単位**になり、別ページへ漏れない。
`persisted` が取れない環境でも属性の有無だけで保守的に判定できるため、
フォールバックとしても `sessionStorage` より正確。

### ちらつき対策（概念設計 Round 5 Warning）

`pagehide` は**通常遷移でも発火する**ため無条件秘匿はちらつきを生む。
- `PageTransitionEvent.persisted` が利用できる環境では **bfcache 対象時だけ秘匿**する
- 利用できない環境では**安全側（秘匿する）** へ倒す
  （通常遷移では直後に新しい Document へ移るため、実害はほぼ無い）

### 設計制約

**秘匿は DOM 表示に限定する**（オーバーレイ要素の可視化 / `documentElement` への属性付与）。
**DOM ツリーの破棄・再構築はしない**。
**撮影中の media stream・未送信フォーム状態・Inertia 履歴状態は破棄しない**。
撮影 PWA が中核であるため、ここを壊すと使命に直撃する。

スタイルは **DS token 経由**（`DESIGN.md` が canonical。hex 直書きを増やさない）。
オーバーレイは既存の Atomic Design 階層に**新規 component を作らず**、
`app.ts` 由来のグローバル要素 + CSS で完結させる
（atoms/molecules の責務ではない = 階層を汚さない）。

### PHPStan適合チェック（プローブ側）

- [x] `SessionStatusDto` は `readonly` + `bool` プロパティ
- [x] `SessionStatusResource::toArray()` の戻り値型を明示
- [x] Controller は `__invoke(Request $request): SessionStatusResource`
- [x] `$request->user() !== null` で null 安全に判定
- [x] `response()->json()` を使わない（禁止事項 #4）

### テスト計画

- [x] **fail-first**: 施策 8 のシナリオ 4 を先に書き、PII が再表示されて fail することを確認
- [x] **プローブの Feature テスト**: 認証済み → `authenticated: true` / guest → `authenticated: false` /
      応答に `no-store, private` が付くこと / PII を含まないこと / **`$wrap = null` により
      `{ "authenticated": bool }` が top-level で返ること（完全一致）**
- [x] **exemption の Feature テスト（R3 Critical）**: **2FA 強制中 / recent-auth 期限切れ /
      組織未選択**の各状態で、プローブが**必ず 200 + boolean** を返すこと
- [x] **guard 分岐の vitest**（design-review R1 Warning）: `pageshow(persisted=true/false)`、
      **`documentElement` の秘匿属性あり/なし**、プローブ成功/失敗/エラー、再試行押下の各分岐を
      **ユニットテストで固定**する。E2E は統合挙動の確認に絞る（E2E 単体だと不安定なため）
- [x] **負のコントロール（vitest）**: 「**秘匿ロジックを外すと `pagehide` 後に
      `documentElement` の秘匿属性が付かない**」ことを先行して固定する。
      vitest では実描画の露出は検証できないため、**属性の有無**で責務を閉じる
      （実描画は E2E の責務。design-review R3 Suggestion）
- [x] `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build` green
- [x] **bug-hunt は完了条件に含めない**（禁止事項 #1）

### リスク

| リスク | 対策 |
|---|---|
| 通常遷移でちらつく | `persisted` で絞る。E2E シナリオ 1（撮影画面からの通常遷移）が固定 |
| 撮影中の media stream が切れる | 秘匿を DOM 表示に限定し reload しない。E2E シナリオ 1 で確認 |
| 未ログアウト復元で状態が飛ぶ | **unhide のみ**にしたため飛ばない（R1 Critical の修正点）。E2E シナリオ 3 で固定 |
| プローブ失敗時に秘匿したまま操作不能になる | fail-secure だが**詰み**は避ける。秘匿オーバーレイに**再試行ボタン**を出す（禁止事項 #8 の精神: 押せない状態で放置しない）。**自動無限再試行は行わない**（design-review R2 Warning で状態遷移を一意化） |
| プローブが増えることでリクエストが増える | `pageshow(persisted)` 時のみ = 通常遷移では発火しない |

# 施策 7: サポート対象ブラウザ方針の明文化 (P3-c)

### 変更箇所

- `docs/supported-browsers.md` — **新規**
- `AGENTS.md` — ドメイン固有規約への参照追記

### 背景

**リポジトリ内にサポート対象ブラウザ方針が一切ない**
（`DESIGN.md` / `docs/*.md` / `package.json` の browserslist をいずれも確認、記載なし）。
施策 4・6 の保証範囲を語るために、まず方針が要る。

### 内容

| 項目 | 記載 |
|---|---|
| 対象ブラウザ | 撮影 PWA（iOS Safari / Android Chrome）と管理画面（デスクトップ Chrome / Firefox / Safari / Edge）を分けて定義 |
| **検証レベルの区分** | 下表 |

**`Current`（現行で実際に回っている検証）と `Target`（到達目標）を分離して書く**
（design-review R1 Warning: 「WebKit を含む」と「現状未導入」の同居は自己矛盾）。

> **本節はマージ後の実態を書く**（design-review R3 Warning）。施策 8 が WebKit レーンを
> **必須の実装完了条件**にしているため、この文書がマージされる時点で WebKit は導入済みである。
> 実装途中で未導入である状態は**本詳細設計書にのみ**残す。

#### Current（マージ後に実際に保証していること）

| 区分 | 対象 | 扱い |
|---|---|---|
| 自動回帰テスト（恒久） | **Chromium + WebKit**（Playwright） | 反復実行。**WebKit レーンが bfcache 復元シナリオの正本**。Chromium は `no-store` により bfcache 復元自体を再現できないため**部分検証**（秘匿属性付与・プローブ発火）に留まる |
| 実機受入確認（手動） | **iOS Safari 実機**（PWA standalone 含む） | **「恒久テスト済み」とは表現しない**。**日時・端末・OS バージョン・結果**を devnotes に記録する。**再確認条件**: `bfcache-guard.ts` / 秘匿スタイル / プローブ endpoint に変更が入ったら再実施する（一度きりではない。design-review R2 Suggestion） |

#### 未対応事項（誤読を防ぐため明示列挙する）

- **Chromium レーンは bfcache 復元そのものを再現していない**（`no-store` で evict されるため）。
  復元シナリオの正本は WebKit レーン
- **Playwright WebKit ≠ 実機 iOS Safari**。PWA standalone モードの差異は
  **実機受入確認でのみ**確認しており、自動回帰では担保されていない

> Playwright WebKit と実機 iOS Safari も同一ではない（bfcache 挙動・PWA standalone モード・
> iOS 固有の WebKit ビルド差）。WebKit レーン導入後も、前者の green を
> 「iOS Safari 対応を実証した」と言い換えない。

### テスト計画

- [x] 文書のため自動テストなし。ただし施策 11 の gate 群と同じく、
      **参照切れ**（`AGENTS.md` からのリンク先不在）を防ぐ

---

# 施策 8: P3 の Browser E2E 4 シナリオ

### 変更箇所

- `tests/Browser/AuthenticatedPageBfcacheTest.php` — **新規**

### 前提

- 既存 `tests/Browser/SmokeTest.php` があり、`scripts/run-browser-test.sh`（`composer test:browser`）で実行
- 実行前に `pnpm build` 済み + `pnpm exec playwright install chromium` 済みが必要
  （`docs/testing-browser.md`）

### 4 シナリオ（概念設計 Round 4 Warning への対応）

| # | シナリオ | 確認内容 |
|---|---|---|
| 1 | **撮影画面からの通常遷移** | 秘匿処理が**誤発火しない**こと。media stream / 未送信フォーム / Inertia 履歴が壊れないこと |
| 2 | **bfcache 復元（一般）** | 秘匿 → 検証 → 復帰の状態遷移が成立すること |
| 3 | **未ログアウトでの復元** | 表示が正しく**戻る**こと（= 誤検知しない） |
| 4 | **ログアウト後の復元** | **PII が出ない**こと（= 本来の目的） |

### 完了条件（design-review R1 Critical への対応）

**核心リスクは iOS Safari 系 bfcache であり、Chromium 主体では安全性を証明できない。**
Chromium は `no-store` のページを bfcache から evict するため、**シナリオ 4 がそもそも空振りする**。
したがって完了条件を次の優先順で定める。

| 区分 | 完了条件 | 内容 |
|---|---|---|
| **必須（実装完了条件）** | **WebKit レーンの追加** | `pnpm exec playwright install webkit` + `scripts/run-browser-test.sh` の対応。**恒久的な自動回帰**としてシナリオ 2・4 を成立させる |
| **補完（WebKit の代替ではない）** | iOS 実機受入確認 | **PWA standalone 差異**の確認。**日時・端末・OS バージョン・結果**を devnotes に記録する |
| 部分検証 | Chromium レーン | 「秘匿属性が `pagehide` で付く」「`pageshow` でプローブが走る」の確認。**これを全体の証明として扱わない** |

> **R1 からの変更（design-review R2 Critical）**: R1 では「WebKit が成立しなければ実機確認で完了」と
> したが、これは**セキュリティ不変条件を恒久的な自動回帰テストなしで完了扱いにできる**構造であり、
> 概念設計 Round 3 Critical（bug-hunt を完了条件にした誤り）と**同型の誤り**だった。
> **WebKit レーンを必須**とし、実機確認は**補完**に降格した。

**正のコントロール（design-review R2 Warning）**: 「WebKit なら再現できる見込み」では
成功条件にならない。**シナリオ 2・4 は `pageshow.persisted === true` を実際に観測できた場合のみ有効**とし、
**観測できなければテストを失敗させる**（空振りを green にしない）。

さらに、分岐ロジック自体は **vitest のユニットテストで固定**する（施策 6）。
E2E は統合挙動の確認に絞る（`pageshow(persisted)` 分岐は E2E 単体だと不安定なため）。

### fail-first の置き場所（design-review R2 Warning）

Chromium では施策 4 適用後に bfcache 復元が起きなくなるため、**シナリオ 4 の fail-first を
Chromium で再現できない**。したがって:

1. **WebKit レーンで fail-first を確認**する（第一）
2. 併せて **guard の vitest で「秘匿しなければ復元後に旧 DOM が可視のまま」という負のコントロール**を
   先行させ、秘匿ロジックの必要性をユニット層で先に固定する（施策 6）

### リスク

| リスク | 対策 |
|---|---|
| Chromium で bfcache 復元が再現できず**テストが空振りする** | 上記の完了条件で対応。**空振りテストを green として扱わない**（負のコントロールを必ず置き、「復元が起きていない」ことを検出できるようにする） |
| WebKit レーン追加で CI 実行時間が増える | 既存 SmokeTest と同じ排他レーンに乗せる。**実行時間を理由に WebKit を落とすことはしない**（落とすと恒久自動回帰が消えるため。R2 Critical） |
| Browser テストは実行が重く CI で不安定 | `run-browser-test.sh` が排他 + 並列上限を管理済み |

---

# 施策 9: adjudication registry の機構修復

### 変更箇所

- `.claude/skills/app-bug-hunt/ledger/validate_findings.py`
  - `COND_KEYS`（L197）
  - `analyze()`（L139-141）
  - `main()`（L643-668）
- `.claude/skills/app-bug-hunt/ledger/test_validate_findings.py` — 回帰テスト追加

### 現行コード

```python
COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition"}
...
def analyze(path) -> Report:
    ...
    lines = sys.stdin if path == "-" else open(path, encoding="utf-8")
...
    rep = analyze(args.path)
    ...
            findings = [a for _, a, _ in load_jsonl(args.path) if isinstance(a, dict)]
```

### 変更後コード

**(1) `COND_KEYS` に `mode` / `env` を governed key として追加**（aigenba 整列）:

```python
# mode/env は bug-hunt harness の第一級ディメンション (manifest.real_mode / 走行環境)。
# fake 限定の偽陽性を real モードの実退行に誤適用しないための load-bearing な条件なので、
# generic な precondition に潰さず governed key として持つ (spirux HARNESS-01 の教訓:
# 旧 COND_KEYS に mode/env が無く schema drift → fail-closed で抑制が全面停止した。
# AI-CUE も同じ状態だった = 2026-08-02 監査で A-008 が bad condition key で fail)。
COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition", "mode", "env"}
```

**(2) stdin 2-pass の修正**（aigenba 整列）:

```python
def analyze(path, text=None) -> Report:
    """text 指定時はそれを読む (stdin `-` + --annotate の 2-pass 用に親でバッファする)。"""
    import io as _io
    ...
    if text is not None:
        lines = _io.StringIO(text)
    else:
        lines = sys.stdin if path == "-" else open(path, encoding="utf-8")
```

`load_jsonl()` にも同じ `text=None` を追加し、`main()` で:

```python
    # stdin `-` は 1 度しか読めないため、annotate の 2-pass (analyze + findings 再読) 用に
    # 親でバッファする。
    stdin_text = sys.stdin.read() if args.path == "-" else None
    rep = analyze(args.path, text=stdin_text)
    ...
            findings = [a for _, a, _ in load_jsonl(args.path, text=stdin_text) if isinstance(a, dict)]
```

### PHPStan適合チェック

- **対象外**（Python）。検証は `python3 -m unittest`（AGENTS.md §bug-hunt）

### テスト計画

- [x] **fail-first**: `test_seed_registry_is_valid` が**現状すでに赤**（実測済み）。修復後 green を確認
- [x] 新規テスト: `conditions.mode` / `conditions.env` を持つ adjudication が **valid** になること
- [x] 新規テスト: **stdin `-` + `--annotate`** で findings が落ちないこと（現状 2 回目の read が空）
- [x] 既存 56 テストが全て green であること
- [x] 検証コマンド: `cd .claude/skills/app-bug-hunt && python3 -m unittest discover -s ledger -p 'test_*.py'`

### リスク

| リスク | 対策 |
|---|---|
| `COND_KEYS` 追加が既存の `conditions_status()` ロジック（L404-417）に影響する | L417 の `if k in COND_KEYS and k not in conds` は「registry が条件を指定していないのに finding が観測条件を持つ」判定。`mode`/`env` 追加でこの判定が厳しくなる方向（= 安全側）。既存テストで確認 |
| `import io` を関数内に置くのは非標準 / `open()` が context manager でない | **aigenba 実装と揃える（整列優先）**。ここで AI-CUE だけ改善すると**新たな乖離を作る**ため、施策 14 の handoff に **F-5** として回し、aigenba 側が直したら追随する（design-review R1 Suggestion への回答） |

---

# 施策 10: registry データ棚卸し + 運用ガード固定

### 変更箇所

- `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl` — spirux 由来 18 件を削除
- `.claude/skills/app-bug-hunt/ledger/README.md` — 運用ガード追記
- `.claude/skills/app-bug-hunt/spec-ledger.md` — **新規**（枠組み）

### 削除理由（概念設計 Round 1 Critical の随伴要件 (d)）

A-001〜A-018 は **AI-CUE に実在しない資産**を指す:

| 件 | 指している先 | AI-CUE での実在 |
|---|---|---|
| A-012 | `.claude/skills/**spirux**-bug-hunt/operations.md` | **無し** |
| A-005 / A-006 | `/api/v1/personas/*` / `routes/api.php` の persona controller | **無し** |
| A-004 / A-008〜A-011 | `resources/js/**Pages**/Billing/Index.svelte`（大文字 `Pages`） | **無し**（AI-CUE は小文字 `pages/`） |
| A-018 | `app/Filament/Resources/OrganizationResource.php` | **無し**（AI-CUE に Filament admin は無い） |

`watch_globs` が実在しないパスを指すため **invalidation が永久に発火しない**。
= registry は AI-CUE に対して**空同然**であり、かつ**他アプリの偽陽性判定を
AI-CUE の実退行へ誤適用するリスク**を持つ。**seed を空にする**のが安全側。

> 実効的な抑制件数は **0 → 0 で不変**（現状 fail-closed で 18 件全てが無効）。
> 「機能を消す」のではなく**機構を使える状態に戻す**変更である。

### 運用ガードの固定（随伴要件 (a)〜(c)）

`ledger/README.md` と `spec-ledger.md` に以下を明記する:

- **(a)** `species_key` の 4 セグメント規約
  （`failure_class:resource_type:operation:tenant_relation`）。
  A-004〜A-007 が 3 セグメントで invalid だった実例を根拠として残す
- **(b)** governed `COND_KEYS` の一覧と、`mode` / `env` を含める理由
  （fake 限定の偽陽性を real モードの実退行に誤適用しないため）
- **(c)** 新規 adjudication の登録手順（どの run で・何を根拠に・`watch_globs` に何を書くか）

### `spec-ledger.md`（新規・枠組みのみ）

aigenba の 273 行はドメイン固有項目が中身なので**枠組みだけ移植**する。
機械 registry（adjudication）に対する**人間可読の対**として、
「過去 run で SPEC/DOC と確定した事象を再起票しない」申し送りを蓄積する器。
**中身は AI-CUE の実 run から書き起こす**（初期は空 + 運用ルールのみ）。

**初回登録テンプレートを先に置く**（design-review R1 Suggestion。運用開始を速くする）:
「事象 / 判定 (SPEC or DOC) / **根拠 (file:line)** / `watch_globs` / `review_after_days` /
確定した run_id」の欄を持つ雛形を最初から用意する。

### テスト計画

- [x] `test_seed_registry_is_valid` が green（空 registry は valid）
- [x] `--adjudications` 指定時に `adjudications_total: 0` / `invalid: 0` / exit 0 になること
- [x] `python3 -m unittest` 全 green

### リスク

| リスク | 対策 |
|---|---|
| 「registry を空にした」が**機能削除**と誤読される | README に削除理由と「実効抑制は 0 → 0 で不変」を明記。本詳細設計も根拠として参照可能にする |
| 次回 bug-hunt run で偽陽性が抑制されず findings が増える | **想定どおり**（概念設計の成果指標に明記済み）。登録手順 (c) に従って積み上げる |

---

# 施策 11: 汎用 Architecture gate 移植 (6 本)

### 横断原則（概念設計 Round 1 Warning）

各 invariant の source of truth は **AI-CUE の `AGENTS.md` / `docs` / 実スクリプト**に置く。
**aigenba の文言・path を比較対象にしない**（verbatim 移植は禁止）。

### 対象

| # | テスト | **固定する事故 / 不変条件** | AI-CUE 側の SoT |
|---|---|---|---|
| 11-1 | `PhpstanWrapperInvariantTest` | orbstack virtiofs で phar 並列 open が死ぬ回避策が外れる退行 | `composer.json:108-110` / `scripts/phpstan.sh` |
| 11-2 | `BughuntOrchestratorGateInvariantTest` | AGENTS.md が「非交渉」と書く `BUGHUNT_ORCHESTRATOR=1` default-deny の 2 層 gate 崩れ | AGENTS.md §bug-hunt / `scripts/bug-hunt-shard.sh` / `.claude/agents/bughunt-shard.md` |
| 11-3 | `BugHuntInventoryCheckInvariantTest` | `bug-hunt-inventory-check.sh` の exit code 規約（0=一致 / 3=ドリフト）崩れ | `scripts/bug-hunt-inventory-check.sh` |
| 11-4 | `BugHuntSkillInvariantTest` | 「finding は停止信号ではない」規約の喪失 | `.claude/skills/app-bug-hunt/SKILL.md` |
| 11-5 | `BughuntEnvExampleContractTest` | `.env.bughunt.local.example` の production 同等性最小セット欠落 | `.env.bughunt.local.example` |
| 11-6 | `InertiaRenderPageExistsInvariantTest` | `Inertia::render` の literal 参照先ページ不在 → **本番白画面** | `app/` の literal 参照 / `resources/js/pages/` |

> **`WorktreeRuleInvariantTest` は本施策に含めない**。AI-CUE の worktree 規約
> （`.claude/worktrees/tasks/<id>`・ブランチ削除責務が呼び出し側）は aigenba と異なり、
> 検査項目の**再設計**が必要。別 TODO へ切り出す。

### テスト計画（全 gate 共通）

- [x] **負のコントロール必須**: 各 gate について「AI-CUE で意図どおり fail する状態」を
      手元で作って確認する。空振り gate を green として扱わない
- [x] 11-6 は現時点で **dangling 0 件**（literal 参照 39 件を手検証済み）= 予防 gate
- [x] DB 不使用の静的検査に寄せる（既存 Architecture テストと同じ作法）

### リスク

| リスク | 対策 |
|---|---|
| 11-6 の PhpToken 走査が AI-CUE の記法（変数展開・定数）で誤検出する | **literal 引数のみ**を対象にする（aigenba も同方針）。非 literal は検査対象外として明記 |
| 11-2〜11-5 が AI-CUE の bug-hunt 実装と細部で合わない（AI-CUE の `bug-hunt-shard.sh` は 1982 行で aigenba の 1305 行より進んでいる） | **AI-CUE の実スクリプトを読んで検査項目を書き直す**。aigenba のテスト本文を写さない |

---

# 施策 12: JS gate 移植 (1 本)

### 変更箇所

- `tests/js/architecture/pages-path-case-invariant.test.ts` — **新規**

### 固定する不変条件

大文字 `./Pages/` 参照の禁止（case-sensitive CI で解決不能になる）。
**施策 10 で spirux 由来の `resources/js/Pages/` 参照が実際に混入していた**ことから実効性がある。

検査対象は静的 import / glob に加え、**dynamic import の文字列リテラル**も含める
（design-review R1 Suggestion。`import('./Pages/...')` の漏れを防ぐ）。

### テスト計画

- [x] 負のコントロール: `'./Pages/Foo.svelte'` を含むダミー文字列で fail することを確認
- [x] `pnpm test` green

---

# 施策 13: bug-hunt 文書 + docs 整備

### 変更箇所

| ファイル | 内容 |
|---|---|
| `.claude/skills/app-bug-hunt/capability-catalog.md`（新規） | `capability_tag` 語彙の正本。**枠組みのみ移植**し、語彙は AI-CUE ドメインで作る。**先に `SOP → scenario → capture → render` の責務境界を定義**してから capability_id を割り当てる（design-review R1 Suggestion。境界を後決めすると語彙がブレる） |
| `docs/pnpm-global-virtual-store-runbook.md`（新規） | AGENTS.md §worktree が依存する `enableGlobalVirtualStore` の背景・障害対応 |
| `docs/worktree-isolation-strategy.md`（新規） | 同上。worktree 分離設計の背景 |
| `AGENTS.md` | 上記への参照追記 |

> `coverage-audit.md` は **本施策に含めない**。AI-CUE では route 全面監査が未実施であり、
> 「空の枠組み」を作っても意味がない。実監査を伴う別 TODO とする。

### テスト計画

- [x] 文書のため自動テストなし。`AGENTS.md` からの参照切れが無いことを確認

---

# 施策 14: aigenba へ返す handoff 文書

### 変更箇所

- `devnotes/20260803-0053-aigenba-alignment/aigenba-handoff.md` — **新規**

### 内容（「合わせる」は双方向）

| # | 差分 | 提案理由 |
|---|---|---|
| F-1 | `scripts/bug-hunt-shard.sh` の `guard_shard_db_name` / `guard_bughunt_runtime` / `guard_admin_provision` の 3 段 DB guard、`secret_xtrace_off` / `secret_xtrace_restore` | `secret_xtrace_off` は `set -x` 下で API key が漏れるのを防ぐ。安全性に直結 |
| F-2 | `coverage/correlate.py` のヘッダ列 index 動的決定（5 列 / 6 列の両節対応）+ backtick 剥がし | aigenba の operations.md が将来 6 列節を持つと誤 join する |
| F-3 | `scripts/audit-gate.test.ts` | supply-chain gate 自体は両者にあるが、gate のテストは AI-CUE のみ |
| **F-4** | **施策 6 の bfcache 秘匿・再検証（P3-b）** | aigenba は Safari の bfcache を「スコープ外」としているが、**PWA を持つなら同じ穴がある**。AI-CUE 固有の追加として実装した内容を返す |
| **F-5** | `validate_findings.py` の `import io` モジュール先頭化 / `open()` の context manager 化 | 可読性・リソース解放の改善（design-review R1 Suggestion）。**AI-CUE 側では整列優先で見送った**ため、aigenba が直したら双方で追随する |

各項目に**受け手側の採否結果欄（adopt / reject / defer）**を用意し、往復管理できる形にする
（design-review R1 Suggestion）。

既存の `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md` と
同じカテゴリ運用（B = 返す）に従う。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| **推奨モード** | **standalone（3 トラック分割）** |
| **判断根拠** | 実バグ修正（T-a: 施策 1-8 / T-b: 施策 9-10）・gate 移植（T-c: 施策 11-14）で**変更対象が完全に分離**している。T-b は `.claude/skills/` 配下のみ、T-a は `app/` + `routes/` + `resources/js/`、T-c は `tests/` + `docs/`。fail-first の確認単位も分かれる（概念設計 §段階リリース） |
| **競合リスク** | **低**。3 トラックでファイル重複が無い。ただし T-c の施策 11-4（`BugHuntSkillInvariantTest`）と T-b の `spec-ledger.md` 追加が同じ skill ディレクトリを触るため、**T-b → T-c の順**で実施する |

### トラックごとの fail-first 確認

| トラック | 先に落ちることを確認するテスト |
|---|---|
| **T-a** | 施策 3 のケース 1・2（現状 500）/ 施策 2 の IV-3・IV-4（未制約 param あり）/ 施策 4 の認証済み `Cache-Control`（現状 no-store 無し）/ 施策 8 のシナリオ 4（現状 PII 再表示） |
| **T-b** | `test_seed_registry_is_valid`（**現状すでに赤**）/ stdin `--annotate` の 2-pass 回帰テスト（新規） |
| **T-c** | 各 gate の負のコントロール |

### 検証コマンド（全トラック共通・全 green でコミット）

```
composer test / composer phpstan / vendor/bin/pint --test
pnpm lint / pnpm typecheck / pnpm test / pnpm build
composer test:browser                                    # T-a のみ
cd .claude/skills/app-bug-hunt && python3 -m unittest discover -s ledger -p 'test_*.py'   # T-b
```
