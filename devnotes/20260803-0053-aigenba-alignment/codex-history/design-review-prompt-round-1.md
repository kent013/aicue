【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件(アプリ都合で緩めない)】
1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404
3. cross-org 不可: 組織を跨ぐ read/write をしない
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に `laratrust_team_id` を明示(strict_check=true)
6. PII(email/name)は CipherSweet。検索は `whereBlind()`
7. 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: design token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: atoms/molecules/organisms/templates の責務分離、アイコンは Lucide 前提

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| **1** | route binding 型制約の適用 (bigint / uuid) | `app/Providers/AppServiceProvider.php`, `app/Http/Routing/RouteBindingTypes.php` (新規) | Critical | T-a |
| **2** | route binding total inventory gate | `tests/Architecture/RouteBindingTypeConstraintInventoryTest.php` (新規) | Critical | T-a |
| **3** | 非適合セグメント → 404 の実挙動テスト | `tests/Feature/Routing/RouteBindingTypeConstraintTest.php` (新規) | Critical | T-a |
| **4** | no-store baseline middleware (P3-a) | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` (新規), `bootstrap/app.php` | High | T-a |
| **5** | 既存 no-store 4 経路のヘッダ完全値ピン | `tests/Feature/Security/ExistingNoStoreContractTest.php` (新規) | High | T-a |
| **6** | bfcache 秘匿・再検証 (P3-b) | `resources/js/lib/bfcache-guard.ts` (新規), `resources/js/app.ts`, `resources/css/` | High | T-a |
| **7** | サポート対象ブラウザ方針の明文化 (P3-c) | `docs/supported-browsers.md` (新規), `AGENTS.md` | High | T-a |
| **8** | P3 の Browser E2E 4 シナリオ | `tests/Browser/AuthenticatedPageBfcacheTest.php` (新規) | High | T-a |
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

- TypeScript型定義: **なし**（route の URL 形状は変わらない。適合値の挙動は不変）
- API Resource/DTO: **なし**
- テストファイル: 施策 2（Architecture）・施策 3（Feature）で新規追加

### 設計判断: なぜ `Route::pattern` を使うのか

概念設計 Round 1 Critical の結論どおり **global 一律適用はしない**が、
**inventory に登録した param 名に対して個別に `Route::pattern($name, $regex)` を呼ぶ**形にする。

| 候補 | 採否 | 理由 |
|---|---|---|
| 各 route に `->whereNumber()` を書く | **不採用** | web 約 120 + api 7 箇所への手書きは**漏れが必ず出る**。追加 route での付け忘れを人手に依存する |
| `Route::pattern` を inventory 駆動で適用 | **採用** | 適用漏れが構造的に起きない。inventory が単一 SoT になり施策 2 の gate と突合できる |
| global `Route::pattern('*', ...)` | **不採用** | `{organization:slug}` が全滅する（Round 1 Critical） |

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

    /** Laravel の数値制約 (whereNumber 相当)。 */
    public const BIGINT_PATTERN = '[0-9]+';

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
| **IV-1 (分類漏れ禁止)** | routes に現れる全 param が inventory に登録されていること。**未登録は fail**（メッセージで「型・解決方式・除外理由を登録せよ」と促す） |
| **IV-2 (逆方向)** | inventory に登録済みだが routes に現れない param が無いこと（陳腐化した登録の検出） |
| **IV-3 (bigint 制約)** | `BIGINT` の全 param が `Route::pattern` で数値制約を持つこと |
| **IV-4 (uuid 制約)** | `UUID` の全 param が UUID 制約を持つこと |
| **IV-5 (custom binder)** | `CUSTOM_BINDER` の param に対応する binder クラスが実在し、**入力正規化メソッドを持つ**こと。かつ **pattern が適用されていない**こと（`{organization:slug}` を壊さないため） |
| **IV-6 (排他性)** | 同一 param が複数分類に重複登録されていないこと |

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
 * 守る事故: pgsql の型不一致 22P02 → QueryException → **404 ではなく生 500**。
 * 実挙動 (非適合→404) は tests/Feature/Routing/RouteBindingTypeConstraintTest が担保する。
 */
final class RouteBindingTypeConstraintInventoryTest extends TestCase
{
    // IV-1 〜 IV-6 を it() で分割して実装する
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

- [x] **負のコントロール**: inventory から `project` を一時的に外すと IV-1 が fail することを
      手元で確認する（gate が実際に検出することの証明）
- [x] 同じく `Route::pattern` の適用をコメントアウトすると IV-3 が fail することを確認する
- [x] 個別の `DatabaseTransactions` を使っていないことを確認（本テストは DB 不使用）

### リスク

| リスク | 対策 |
|---|---|
| Filament / Passport / Livewire 等の**ベンダー route** が param を持ち込み IV-1 が誤 fail する | 走査対象を**アプリ route に限定**する。`routes/web.php` `routes/api.php` 由来の route のみを対象にし、判定は route の `uri` ではなく**登録元の除外リスト**で行う。除外リスト自体も inventory と同じ思想で明示する |
| IV-2（逆方向）が worktree 差分で誤 fail する | inventory は「routes に現れうる param」の集合。将来 route を消したら登録も消す運用にする（gate が促す） |

---

# 施策 3: 非適合セグメント → 404 の実挙動テスト

### 変更箇所

- `tests/Feature/Routing/RouteBindingTypeConstraintTest.php` — **新規**

### テスト計画（fail-first を先に確認する）

| # | ケース | 期待 | 現状 |
|---|---|---|---|
| 1 | `GET /projects/abc`（bigint 代表） | **404**（500 でない） | **500 で fail** |
| 2 | `DELETE /organizations/{slug}/api-keys/sessions/abc`（uuid 代表） | **404** | **500 で fail** |
| 3 | `GET /projects/1`（適合値） | 既存挙動（200 or 403 or 404。**500 でない**） | green |
| 4 | 全 `BIGINT` param の代表 route に非数値を投げる | 404 | fail |
| 5 | `{organization:slug}` の route が**引き続き slug で解決する** | 既存挙動 | green（回帰確認） |

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
            // 認証済み応答の no-store baseline は **末尾 (= 最内側)**。
            // pipeline は「配列で前 = 外側 = ヘッダ後勝ち」のため、より厳格な値を明示する
            // 内側の経路 (recent-auth 409 等) が後勝ちで維持される。
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

- [x] 各経路を実際に叩き、`$response->headers->get('Cache-Control')` を**完全一致**で assert
- [x] P3-a 適用前後で**値が変わらない**ことを確認（untouched 契約の証明）
- [x] テストデータは Factory 生成 / 個別 `DatabaseTransactions` を使わない

### リスク

- 完全一致ピンは「意図的な強化」も落とす。**落ちたら期待値を更新する**運用でよい
  （落ちること自体が「契約が変わった」というシグナルとして機能する）

---

# 施策 6: bfcache 秘匿・再検証 (P3-b)

### 変更箇所

- `resources/js/lib/bfcache-guard.ts` — **新規**
- `resources/js/app.ts` — guard の初期化
- `resources/css/`（または既存 DS token 経由のスタイル） — 秘匿オーバーレイ

### 波及変更

- TypeScript型定義: `PageTransitionEvent` を明示（DOM 標準型。追加の型定義ファイルは不要）
- API Resource/DTO: **なし**（専用 endpoint を追加しないため）
- テストファイル: 施策 8（Browser E2E 4 シナリオ）

### 設計判断（概念設計 Round 4 Critical / Round 5 Warning の反映）

**「復元後に検証」ではなく「検証完了まで復元内容を秘匿」**。
`pageshow` 後に非同期検証する構造だと、検証完了までの間、**復元済みの古い DOM が表示され
PII が一瞬露出する**。「無効なら遷移する」は「再表示しない」と同義ではない。

**第一候補（hard reload）の状態遷移**:

| # | 契機 | 動作 |
|---|---|---|
| 1 | `pagehide` | 画面を**同期的に秘匿**する。**この秘匿状態が bfcache snapshot に入る**ことが要点 |
| 2 | `pageshow`（`persisted === true`） | **秘匿状態のまま** hard reload する |
| 3 | reload 後 | 認証済みなら**新しい Document** を表示 / 未認証なら既存の `auth` middleware が **login へ redirect** |

hard reload は新しい Document へ遷移するため「旧 DOM を検証結果に応じて出し直す」経路は通らない。
**秘匿は「reload が効くまでの目隠し」という単純な役割に閉じる**。

**専用 endpoint は追加しない**（Round 4 Warning）。hard reload なら施策 4 の `no-store` により
bfcache ではなくネットワークから取り直され、未認証なら既存の `auth` middleware が login へ倒す
= **新しい経路・攻撃面・DTO 規約を増やさない**（思考原則 #1 / #2）。

**ちらつき対策（Round 5 Warning）**: `pagehide` は**通常遷移でも発火する**ため無条件秘匿は
ちらつきを生む。`PageTransitionEvent.persisted` が利用できる環境では **bfcache 対象時だけ秘匿**し、
利用できない環境では**安全側（秘匿する）** へ倒す。

**設計制約**: **秘匿は DOM 表示に限定**する。
**撮影中の media stream・未送信フォーム状態・Inertia 履歴状態は破棄しない**。
撮影 PWA が中核であるため、ここを壊すと使命に直撃する。

### 実装スケッチ

```ts
/**
 * bfcache 由来の認証済み画面再表示ガード。
 *
 * 背景: Safari は Cache-Control: no-store でも bfcache に格納しうるため、サーバ側の
 * NoStoreCacheHeadersForAuthenticatedPages だけではログアウト後の「戻る」で
 * 認証済み画面 (PII) が復元されうる。AI-CUE は撮影が PWA (iOS Safari が主要
 * プラットフォーム) のため、この穴を塞ぐ必要がある。
 *
 * 方式: 「復元後に検証」ではなく「**検証完了まで秘匿**」。
 *   pagehide で同期的に秘匿 → その秘匿状態が bfcache snapshot に入る
 *   → pageshow(persisted) では秘匿のまま hard reload
 *   → 認証済みなら新 Document 表示 / 未認証なら auth middleware が login へ
 * 単純な「pageshow → 非同期検証」では検証完了までの間 PII が露出するため不可
 * (この不変条件は tests/Browser/AuthenticatedPageBfcacheTest が固定する)。
 *
 * 制約: 秘匿は **DOM 表示のみ**。media stream / 未送信フォーム / Inertia 履歴は破棄しない。
 */
```

- 秘匿は「オーバーレイ要素の可視化」または `documentElement` への属性付与で行い、
  **DOM ツリーの破棄や再構築はしない**
- スタイルは **DS token 経由**（DESIGN.md が canonical。hex 直書きを増やさない）

### PHPStan適合チェック

- 本施策は TypeScript のため PHPStan 対象外
- `pnpm typecheck` / `pnpm lint` を通す
- `PageTransitionEvent` を明示し `any` を使わない

### テスト計画

- [x] 施策 8 の Browser E2E 4 シナリオが本施策の**完了条件**
- [x] `pnpm typecheck` / `pnpm lint` / `pnpm build` green
- [x] **bug-hunt は完了条件に含めない**（禁止事項 #1。自由探索は恒久回帰テストの代替にならない）

### リスク

| リスク | 対策 |
|---|---|
| 通常遷移でちらつく | `persisted` で絞る。E2E シナリオ 1（撮影画面からの通常遷移）が固定 |
| 撮影中の media stream が切れる | 秘匿を DOM 表示に限定。E2E シナリオ 1 で確認 |
| 未ログアウト復元で誤って reload し、未送信フォームが飛ぶ | E2E シナリオ 3（未ログアウトでの復元）で「表示が正しく戻る」を固定。reload 自体は許容するが、フォーム状態の扱いを実装時に確認する |
| Inertia の履歴状態が壊れる | E2E シナリオ 1・3 で確認 |

---

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

| 区分 | 対象 | 扱い |
|---|---|---|
| **自動回帰テスト（恒久）** | Chromium / WebKit（Playwright） | **完了条件**。反復実行する |
| **実機受入確認（手動・一度きり）** | iOS Safari 実機（PWA standalone 含む） | **「恒久テスト済み」とは表現しない**。確認記録を devnotes に残す |

> Playwright WebKit と実機 iOS Safari は同一ではない（bfcache 挙動・PWA standalone モード・
> iOS 固有の WebKit ビルド差）。前者の green を「iOS Safari 対応を実証した」と言い換えない。

### 実装上の制約（実測）

`scripts/run-browser-test.sh` の前提は **`pnpm exec playwright install chromium`** であり、
**WebKit は現状インストールされていない**。
自動回帰に WebKit を含めるには **WebKit の導入とスクリプト対応が必要**。

> **判断**: WebKit 導入は本施策の**必須要件とはしない**（実行時間・CI コストの増加を伴うため）。
> まず Chromium で 4 シナリオを固定し、WebKit 追加は方針文書に**未対応として明記**する。
> これにより「WebKit で検証済み」と誤読される余地を消す。

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

### fail-first

シナリオ 4 は施策 4・6 の実装前に**先に書いて fail を確認**する（現状 PII が再表示される）。

### リスク

| リスク | 対策 |
|---|---|
| Chromium は `no-store` で bfcache に入れないため、シナリオ 2・4 が**そもそも復元しない**（テストが空振りする） | Chromium で bfcache 復元を強制できない場合、**シナリオ 2・4 は「秘匿マーカーが `pagehide` で付く」ことと「`pageshow(persisted)` で reload される」ことを分けて検証**する。空振りテストを green として扱わない（負のコントロールを必ず置く） |
| Browser テストは実行が重く CI で不安定 | `run-browser-test.sh` が排他 + 並列上限を管理済み。既存 SmokeTest と同じレーンに乗せる |

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
| `import io` を関数内に置くのは非標準 | aigenba 実装と揃える（整列優先）。モジュール先頭へ移すのは aigenba へ返す差分として扱う |

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

### テスト計画

- [x] 負のコントロール: `'./Pages/Foo.svelte'` を含むダミー文字列で fail することを確認
- [x] `pnpm test` green

---

# 施策 13: bug-hunt 文書 + docs 整備

### 変更箇所

| ファイル | 内容 |
|---|---|
| `.claude/skills/app-bug-hunt/capability-catalog.md`（新規） | `capability_tag` 語彙の正本。**枠組みのみ移植**し、語彙は AI-CUE ドメイン（SOP / シナリオ / 撮影 / レンダ）で作る |
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

---

## 関連する現行コード

### app/Providers/AppServiceProvider.php (該当箇所)
```php
        Route::bind('organization', MembershipScopedOrganizationBinder::class);
```

### bootstrap/app.php:82-89
```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
        ]);
```

### routes/web.php:350-361 (whereUuid の現行)
```php
    | whereUuid は不正形式 id を route 不一致 = 404 に落とす (pgsql uuid 比較の 22P02 防止)。
        Route::patch('/notifications/{notification}/read', ...)
            ->whereUuid('notification')
        Route::patch('/notifications/{notification}/unread', ...)
            ->whereUuid('notification')
```

### app/Http/Routing/MembershipScopedOrganizationBinder.php (抜粋)
```php
    private const BINDABLE_FIELDS = ['id', 'slug'];

    public function bind(mixed $value, ?Route $route = null): Organization
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) { throw (new ModelNotFoundException)->setModel(Organization::class); }
        if (! is_string($value) && ! is_int($value)) { throw (new ModelNotFoundException)->setModel(Organization::class); }
        $field = $route?->bindingFieldFor('organization') ?? (new Organization)->getRouteKeyName();
        if (! in_array($field, self::BINDABLE_FIELDS, true)) { ...; throw ...; }
        if ($field === 'id') {
            $value = $this->normalizeIntegerId($value);
            if ($value === null) { throw (new ModelNotFoundException)->setModel(Organization::class); }
        }
        $organization = Organization::query()->where($field, $value)
            ->whereHas('users', static fn (Builder $q): Builder => $q->whereKey($user->id))->first();
        if (! $organization instanceof Organization) { throw (new ModelNotFoundException)->setModel(Organization::class); }
        return $organization;
    }

    private function normalizeIntegerId(string|int $value): ?int
    {
        if (is_int($value)) { return $value >= 0 ? $value : null; }
        if (! ctype_digit($value)) { return null; }
        if (strlen($value) > 1 && $value[0] === '0') { return null; }
        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) { return null; }
        return (int) $value;
    }
```

### validate_findings.py (該当箇所)
```python
COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition"}

def analyze(path) -> Report:
    lines = sys.stdin if path == "-" else open(path, encoding="utf-8")

def main(argv=None) -> int:
    rep = analyze(args.path)
    summary = to_summary(rep)
    adj_errors = []
    if args.adjudications:
        adjs = load_jsonl(args.adjudications)
        adj_errors = validate_adjudications(adjs)
        if args.annotate:
            findings = [a for _, a, _ in load_jsonl(args.path) if isinstance(a, dict)]
            # fail-closed: registry に 1 件でも error があれば、壊れた台帳は一切信頼しない
            registry = [] if adj_errors else adjs
            ...
            return 1 if (adj_errors or kpi["rederive_errors"]) else 0
```

### 既存 no-store 4 経路の実測値
- FortifyServiceProvider.php:199 → `$response->headers->set('Cache-Control', 'no-store');` (invitationEmail 非空時のみ)
- RequireRecentAuth.php:57 → `->withHeaders(['Cache-Control' => 'no-store']);` (409)
- RequireTwoFactorForEnforcedOrganizations.php:93 → `->withHeaders(['Cache-Control' => 'no-store']);` (409)
- Capture/CaptureTakeController.php:177 → `->withHeaders(['Cache-Control' => 'no-store, private']);` (302 away)

### tests/Browser の現状
- `tests/Browser/SmokeTest.php` のみ。`scripts/run-browser-test.sh` は chromium 前提 (`pnpm exec playwright install chromium`)。WebKit 未導入。
