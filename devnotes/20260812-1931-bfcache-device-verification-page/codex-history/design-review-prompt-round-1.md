# アプリの使命（AGENTS.md より）

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


# 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


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
- PHPStan level 10 / Pest / DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: /DESIGN.md が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠: resources/js/components/ の atoms/molecules/organisms/templates の責務分離に沿った配置か。アイコンは Lucide 前提で SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本件の経緯】
概念設計は Codex 合議 6 ラウンドで APPROVED 済み。とくに以下は決着済みなので蒸し返さないこと:
- 専用 env フラグを追加しない（既存三層 + より低い権限のため）
- /login 到達は手動確認とし完全自動判定を主張しない
- logout 導線を新設しない（既存 inventory テストが禁止）
- 離脱は plain anchor の full document navigation（Inertia visit では bfcache に入らない）

【参照できる実ファイル】
- devnotes/20260812-1931-bfcache-device-verification-page/conceptual-design.md
- resources/js/lib/bfcache-guard.ts / resources/js/app.ts
- routes/web.php / app/Http/Middleware/LocalOnly.php / bootstrap/app.php
- app/Http/Controllers/DebugLoginController.php / resources/js/pages/Debug/Login.svelte
- tests/js/architecture/logout-call-site-inventory.test.ts
- docs/supported-browsers.md / DESIGN.md / docs/design-system.md

---

## 詳細設計書

# 詳細設計: bfcache 実機受入確認の検証ページ (debug 限定)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン（AGENTS.md 参照）
- アーリーリターン推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[conceptual-design.md](./conceptual-design.md)（Round 6 で APPROVED）

判断の経緯は `codex-history/conceptual-review-decisions-round-{1..5}.md`。

## 前提（現行コードの実測）

| 事実 | 出典 |
|---|---|
| guard は全ページに自動インストールされ、`isAuthenticated` は Inertia 共有 props の `auth.user` 起点 | `resources/js/app.ts:18-24` |
| guard の状態は `documentElement` の `data-bfcache-hidden` 属性（`pending`/`verifying`/`retry`／削除） | `resources/js/lib/bfcache-guard.ts` |
| `unauthenticated` 時は属性を `verifying` のまま `location.replace('/login')` | 同 `verify()` |
| `NoStoreCacheHeadersForAuthenticatedPages` は **web グループ全体**に append され、認証済み route 限定ではない | `bootstrap/app.php:132` + 同 143 行のコメント |
| プローブ先 `/session/status` は `auth` グループ外・`LocalOnly` 外 | `routes/web.php:155` |
| debug route は `isLocal() \|\| runningUnitTests()` ブロック内の `LocalOnly` グループ | `routes/web.php:676-680` |
| `LocalOnly` は `config('app.env') !== 'local'` で 404、`DEBUG_LOGIN_*` 未設定で 404、Basic 認証 | `app/Http/Middleware/LocalOnly.php` |
| logout は Inertia visit 一本。`fetch`/`axios` 併用は deny-by-default で検出 | `tests/js/architecture/logout-call-site-inventory.test.ts` |

**帰結**: 検証ページを `auth` 配下の Inertia ページとして web グループに置けば、
no-store も guard も本物がそのまま乗る。**検証対象への変更は一切不要**。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 観測ライブラリ（イベント型 / validator / 導出関数） | `resources/js/lib/debug/bfcache-trial.ts`（新規） | High |
| 2 | route + controller | `routes/web.php`, `app/Http/Controllers/DebugBfcacheTrialController.php`（新規） | High |
| 3 | 検証ページ A | `resources/js/pages/Debug/BfcacheTrial.svelte`（新規） | High |
| 4 | 相方ページ B | `resources/js/pages/Debug/BfcacheTrialAway.svelte`（新規） | High |
| 5 | 導出関数と validator の vitest | `tests/js/unit/debug/bfcache-trial.test.ts`（新規） | High |
| 6 | architecture テスト（route 包含 / unload 禁止） | `tests/Feature/Architecture/DebugBfcacheTrialRouteGateTest.php`（新規）, `tests/js/architecture/debug-no-unload-listener.test.ts`（新規） | High |
| 7 | ドキュメント反映 | `docs/supported-browsers.md`, `docs/TODO.md` | Medium |

---

# 施策 1: 観測ライブラリ

### 変更箇所

- `resources/js/lib/debug/bfcache-trial.ts` — **新規**

`resources/js/lib/` 直下ではなく `lib/debug/` に置く。
production バンドルに debug 専用ロジックを混ぜないための区画であり、
施策 6 の architecture テストが対象ディレクトリを特定するのにも使う。

### 波及変更

- TypeScript 型定義: 本ファイル内で完結（他への影響なし）
- API Resource/DTO: **なし**（サーバ通信を持たない）
- テストファイル: 施策 5 で新規作成

### 設計

#### イベント型（観測事実のみ）

```ts
/** schema 変更時に必ず上げる。復元時に不一致なら破棄する。 */
export const TRIAL_SCHEMA_VERSION = 1;

/** 検証シナリオ。利用者が試行開始時に宣言する。 */
export type TrialScenario = "expired-session" | "active-session";

/** guard の秘匿属性がとりうる値（属性削除は null で表す）。 */
export type GuardState = "pending" | "verifying" | "retry" | null;

interface EventBase {
    schemaVersion: number;
    trialId: string;
    sequence: number;
    timestamp: string; // ISO 8601
}

export interface TrialStartedEvent extends EventBase {
    type: "trial-started";
    scenario: TrialScenario;
    contextToken: string;
    userAgent: string;
    uaReportedOs: string;
    displayMode: string;
    navigatorStandalone: boolean | null;
    /** 利用者申告。自由記述の抜け道にしないため長さ・文字種を制限する。 */
    deviceModel: string;
    verifiedOsVersion: string;
}

export interface PageHideEvent extends EventBase {
    type: "page-hide";
    persisted: boolean;
    guardState: GuardState;
}

export interface PageShowEvent extends EventBase {
    type: "page-show";
    persisted: boolean;
    contextToken: string;
    displayMode: string;
}

export interface GuardStateChangedEvent extends EventBase {
    type: "guard-state-changed";
    state: GuardState;
}

/** 利用者が /login 到達を確認して記録する手入力イベント。 */
export interface RedirectObservedEvent extends EventBase {
    type: "redirect-observed";
    observationMethod: "manual";
}

/** 保存できれば記録する診断イベント。保存不能の永続証拠ではない。 */
export interface StorageFailedEvent extends EventBase {
    type: "storage-failed";
    reason: string;
}

export interface TrialAbortedEvent extends EventBase {
    type: "trial-aborted";
}

export type TrialEvent =
    | TrialStartedEvent
    | PageHideEvent
    | PageShowEvent
    | GuardStateChangedEvent
    | RedirectObservedEvent
    | StorageFailedEvent
    | TrialAbortedEvent;
```

**verdict 型は持たない。** 軸 1 / 軸 2 / 総合はすべて導出関数で計算する
（概念設計 §型境界。保存すると `redirect-observed` 追記時に stale になる）。

#### 手入力値の制約

| フィールド | 最大長 | 許可文字 | 正規化 |
|---|---|---|---|
| `deviceModel` | 40 | 英数字 / 空白 / `-` / `,` / `(` `)` / `.` | 前後 trim、連続空白を 1 個に |
| `verifiedOsVersion` | 20 | 数字 / `.` / 英字 / 空白 | 同上 |

許可外の文字を含む入力は**拒否する**（除去して通さない。除去すると
利用者が意図しない値が保存される）。入力欄に「氏名等を入力しない」旨を表示する。

#### runtime validator

`sessionStorage` からの復元は型 assertion で済ませない。
`bfcache-guard.ts` の `readAuthenticatedFlag()` と同じ
「shape を厳密判定し、崩れていたら採用しない」idiom に揃える。

```ts
export function parseTrialEvent(value: unknown): TrialEvent | null;
export function parseTrialLog(raw: string | null): TrialEvent[] | null;
```

判定内容:

1. plain object であること（配列・null を弾く）
2. `schemaVersion === TRIAL_SCHEMA_VERSION`（不一致は破棄）
3. `type` が既知の literal であること
4. **その `type` に許可されたキー以外を持たないこと**（余分キーを拒否）
5. 各フィールドの型・長さ・文字種が制約を満たすこと

1 件でも壊れていたら、**そのログ全体を破棄する**（部分採用しない）。
壊れたログを黙って部分表示すると、欠落した証跡を完全な証跡と誤読する。

#### 導出関数（純粋関数）

```ts
export type TrialVerdict =
    | "valid-bfcache" | "invalid-not-bfcache" | "invalid-wrong-route"
    | "inconsistent" | "incomplete";

export type GuardVerdict =
    | "authenticated-unhidden" | "unauthenticated-redirected"
    | "hidden-then-left" | "retry-hidden" | "failed-transition" | "not-observed";

export type OverallVerdict = "pass" | "fail" | "expectation-mismatch" | "undetermined";

export function deriveTrialVerdict(events: TrialEvent[]): TrialVerdict;
export function deriveGuardVerdict(events: TrialEvent[]): GuardVerdict;
export function deriveOverallVerdict(
    scenario: TrialScenario, trial: TrialVerdict, guard: GuardVerdict,
): OverallVerdict;
```

**軸 1 の判定規則**（概念設計の 5 条件を機械化）:

`valid-bfcache` は次をすべて満たす場合に限る。

1. `page-hide.persisted === true`
2. 対応する `page-show.persisted === true`
3. `page-show.contextToken === trial-started.contextToken`（**full 値で比較**）
4. 同一 `trialId`
5. `sequence` 上で `trial-started < page-hide < page-show`

| 条件 | 判定 |
|---|---|
| 上記 5 条件をすべて満たす | `valid-bfcache` |
| `page-show.persisted === false` かつ token 不一致 | `invalid-not-bfcache` |
| `page-show` 無しで `guard-state-changed` のみ観測（= A が再表示されたが lifecycle 無し） | `invalid-wrong-route` |
| `page-hide` はあるが対応する `page-show` が無い／`trial-aborted` | `incomplete` |
| 上記のいずれにも当てはまらない（**`page-hide.persisted` と `page-show.persisted` の不一致を含む**） | `inconsistent` |

**軸 2 の判定規則**:

| 観測 | 判定 |
|---|---|
| `pending → verifying → 属性削除(null)` | `authenticated-unhidden` |
| `pending → verifying → 秘匿維持のまま page-hide` **かつ** `redirect-observed` あり | `unauthenticated-redirected` |
| 同上だが `redirect-observed` **無し** | `hidden-then-left` |
| `pending → verifying → retry` | `retry-hidden` |
| `guard-state-changed` が一度も無い | `not-observed` |
| 上記以外の遷移列（`pending` のまま停止など） | `failed-transition` |

**軸 3（総合）**:

```
期待 guard 結果 = scenario === "expired-session"
    ? "unauthenticated-redirected"
    : "authenticated-unhidden";

trial === "incomplete"                  → "undetermined"
trial !== "valid-bfcache"               → "undetermined"   // 空振り。PASS でも FAIL でもない
guard === 期待値                        → "pass"
guard が期待値と異なる正常終端          → "expectation-mismatch"
それ以外（failed-transition 等）        → "fail"
```

`hidden-then-left` は `expired-session` の期待値に**達していない**ので `pass` にしない
（`redirect-observed` が入るまで `expectation-mismatch` ではなく `undetermined` 扱い）。

#### storage 層

```ts
export function probeStorageWritable(): boolean; // 試行開始前の保存テスト
export function appendEvent(event: TrialEvent): boolean; // 失敗時 false（例外を投げない）
export function loadTrials(): Map<string, TrialEvent[]>;
```

- キー: `bfcache-trial:v1`（単一キーに全試行の event 配列を JSON で保持）
- `appendEvent` は `try/catch` で例外を捕捉し、**黙って成功扱いにしない**
- 論理モデルは append-only。物理的な配列再保存は実装詳細として許容する

### PHPStan 適合チェック

- [x] PHP ファイルを含まない施策（TypeScript のみ）

### テスト計画

施策 5 で網羅する（導出関数の真理値表 + validator の負のコントロール）。

### リスク

| リスク | 対策 |
|---|---|
| 導出関数の分岐が多く、取りこぼしが起きる | 施策 5 で真理値表を全行テストし、`default` 分岐に到達したら明示的に `inconsistent` へ倒す |
| schema 変更で既存ログが読めなくなる | `TRIAL_SCHEMA_VERSION` 不一致は破棄（部分採用しない）。破棄したことを画面に表示する |

---

# 施策 2: route + controller

### 変更箇所

- `routes/web.php` — 既存 `LocalOnly` グループ（L677-680）に 2 route 追加
- `app/Http/Controllers/DebugBfcacheTrialController.php` — **新規**

### 波及変更

- TypeScript 型定義: **なし**（props を持たない）
- API Resource/DTO: **なし**
- テストファイル: 施策 6 で route 包含テストを新規作成

### 変更後コード

```php
if (app()->isLocal() || app()->runningUnitTests()) {
    Route::middleware(LocalOnly::class)->group(function (): void {
        Route::get('/debug/login', [DebugLoginController::class, 'index'])->name('debug.login');
        Route::post('/debug/login/{userId}', [DebugLoginController::class, 'loginAs'])->name('debug.login-as');

        // bfcache 実機受入確認 (T085) の検証ページ。auth 配下に置くことで
        // web グループの NoStoreCacheHeadersForAuthenticatedPages が乗り、
        // 本番の認証済みページと同じ no-store 条件で bfcache を試せる。
        // A と B の 2 枚が要るのは、A から full document navigation で離脱しないと
        // A が bfcache に入らないため (Inertia visit は同一 Document のままで経路 C になる)。
        Route::middleware('auth')->group(function (): void {
            Route::get('/debug/bfcache-trial', [DebugBfcacheTrialController::class, 'trial'])
                ->name('debug.bfcache-trial');
            Route::get('/debug/bfcache-trial/away', [DebugBfcacheTrialController::class, 'away'])
                ->name('debug.bfcache-trial.away');
        });
    });
}
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * bfcache 実機受入確認 (T085) の検証ページ。LocalOnly + auth の背後でのみ動く。
 *
 * 観測値はすべてクライアント側で生成されるため **props を一切渡さない**
 * (実ユーザー情報を debug ページへ流さないための設計判断でもある)。
 * サーバの責務は「認証済みページとして描画されること」だけで、それにより
 * web グループの NoStoreCacheHeadersForAuthenticatedPages が no-store を付け、
 * app.ts が登録した本物の bfcache guard がそのまま作動する。
 */
class DebugBfcacheTrialController extends Controller
{
    /** 検証ページ A (観測・判定・証跡表示)。 */
    public function trial(): Response
    {
        return Inertia::render('Debug/BfcacheTrial');
    }

    /** 相方ページ B。A を bfcache に入れるための full document navigation の着地点。 */
    public function away(): Response
    {
        return Inertia::render('Debug/BfcacheTrialAway');
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`Inertia\Response`）
- [x] null 安全（null を扱わない）
- [x] DTO を返している（配列返却なし。そもそも props なし）
- [x] Generics の型パラメータが正しい（該当なし）

### テスト計画

- [ ] 新規 `DebugBfcacheTrialRouteGateTest`（施策 6）— route 包含・env ゲート・`auth`・`no-store`
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

| リスク | 対策 |
|---|---|
| `auth` を付け忘れると guest でも開け、no-store も付かず検証が無効になる | 施策 6 の route 包含テストで `auth` の存在を機械固定 |
| 将来 debug ブロックの外へ移動される | 同テストで「`LocalOnly` グループ内にあること」を構造的に固定 |

---

# 施策 3: 検証ページ A

### 変更箇所

- `resources/js/pages/Debug/BfcacheTrial.svelte` — **新規**

### 波及変更

- TypeScript 型定義: 施策 1 の型を import するのみ
- API Resource/DTO: **なし**
- テストファイル: 導出ロジックは施策 1 側にあるため施策 5 で担保。本ページ自体は施策 6 の unload 禁止テスト対象

### 設計

#### レイアウト

`AppLayout` を使う。理由は 2 つ。

1. 本番の認証済みページと同じ土俵にする（no-store・guard の作動条件を揃える）
2. **B と共通で、既存のユーザーメニュー logout をそのまま使える**
   （新しい logout 導線を作らない = `logout-call-site-inventory` に触れない）

#### モード

| モード | 既定 | 挙動 |
|---|---|---|
| **stored report** | ○ | 保存済み試行を一覧・表示。読み込んだだけでは**何も記録しない** |
| **live observation** | | 明示操作で新規試行を開始した状態 |

`$state` でモードを持つ。**マウント時に自動で試行を開始しない**
（自動開始が保存済み試行の上書き原因になる）。

ただし「進行中の試行が存在する状態で A が再表示された」場合は、
`page-show` / `guard-state-changed` の**観測は行う**（記録は進行中試行に追記）。
これは新規試行の開始ではない。

#### 起動時の処理順

1. `crypto.randomUUID` が使えるか確認 → 使えなければ
   **「secure context が必要です。この環境では検証できません」を表示して終了**
   （沈黙で劣化させない。平文 http で気づかず確認する事故を防ぐ）
2. `sessionStorage` から `parseTrialLog` で復元。壊れていたら破棄してその旨を表示
3. 進行中試行があれば観測を再開、なければ stored report を表示

#### 観測の配線

| 観測 | 実装 |
|---|---|
| context token | module scope で `crypto.randomUUID()` を 1 回だけ評価。**比較は full 値**、表示は先頭 8 文字 |
| `pagehide` / `pageshow` | `window.addEventListener`。**`unload` / `beforeunload` は使わない** |
| guard 状態 | `MutationObserver` で `documentElement` の `data-bfcache-hidden` を監視（`attributeFilter`） |

`pagehide` ハンドラ内の `sessionStorage` 書き込みは同期実行する。

#### 表示

- 現在のモード（**live observation / stored report** を明示）
- 試行 ID / 宣言シナリオ / 開始・離脱・復帰時刻
- **自動観測**セクション: UA / UA reported OS / `display-mode` / `navigator.standalone`
- **利用者申告**セクション: `deviceModel` / `verifiedOsVersion`（視覚的に区別）
- 観測イベント列（時刻付き）
- **軸 1 / 軸 2 / 総合**の 3 判定。`unauthenticated-redirected` の場合は
  **「manual confirmation」と明示**
- ダミー PII ブロック（オーバーレイが覆う対象。明らかに偽物と分かる固定文字列）
- 操作: 新規試行開始（シナリオ選択 + 端末情報入力）/ 試行を中止 /
  `/login` 到達を記録（`redirect-observed`）/ テキストコピー

#### ダミー PII

`架空 太郎 / example-not-real@invalid.test / 000-0000-0000` のように、
予約ドメイン (`.invalid` / `.test`) と明示的な架空表記を使う。
証跡を devnotes に貼るため、本物めいた値を写り込ませない。
**この文字列自体は sessionStorage に保存しない**（allowlist 外）。

#### 離脱リンク

```html
<a href="/debug/bfcache-trial/away">相方ページへ移動する (full reload)</a>
```

Inertia の `Link` コンポーネントを**使わない**。`target="_blank"` / `download` も使わない。
「plain anchor だから必ず full navigation」とは仮定せず、
**A で `page-hide` が観測されたこと**を軸 1 の必須条件にしている（施策 1）。

### PHPStan 適合チェック

- [x] PHP ファイルを含まない施策

### テスト計画

- [ ] 導出関数・validator は施策 5（vitest）で担保
- [ ] 本ページに `unload` / `beforeunload` を登録しないことを施策 6 で固定
- [ ] 実ブラウザでの結合確認は T085 の実機確認そのものであり、自動化しない（概念設計スコープ外）

### リスク

| リスク | 対策 |
|---|---|
| 秘匿オーバーレイでログが読めない | stored report モードで回収する設計（概念設計 §ログの保存） |
| 自動で新規試行が始まり保存済み証跡を上書きする | マウント時に自動開始しない。開始は明示操作のみ |
| `AppLayout` が将来 `beforeunload` を持つと検証が空振りする | 施策 6 の unload 禁止テストの対象に `AppLayout` を含めるか要検討（下記「未解決事項」） |

---

# 施策 4: 相方ページ B

### 変更箇所

- `resources/js/pages/Debug/BfcacheTrialAway.svelte` — **新規**

### 波及変更

なし（型・DTO・既存テストへの影響なし）。

### 設計

**極めて薄いページにする**（思考原則 2）。責務は 2 つだけ。

1. A から full document navigation で離脱した先として存在すること
2. 次に何をすべきかを画面に書くこと

`AppLayout` を使い、**そこに元からあるユーザーメニューの logout** でログアウトする。
B 自身は logout 導線を持たない（`logout-call-site-inventory` に追記が発生しない）。

表示内容:

- 進行中の試行 ID とシナリオ（A と同一試行であることの確認用）
- シナリオ別の次の操作
  - **失効セッション経路**: ユーザーメニューからログアウト → 履歴で A を選ぶ
  - **有効セッション経路**: ログアウトせず、そのまま履歴で A を選ぶ
- **「戻る 1 回では A に戻らない」旨の注意**
  （B での logout は Inertia が履歴を積むため）
- A への plain anchor（**ただしこれは復帰手段ではない**旨を明記。
  クリックすると新しい履歴エントリになり bfcache 復元にならない）

B は観測を行わない。B での `pagehide`/`pageshow` は記録しない
（試行の判定対象は A の lifecycle だけ）。

### PHPStan 適合チェック

- [x] PHP ファイルを含まない施策

### テスト計画

- [ ] 施策 6 の unload 禁止テストの対象に含める

### リスク

| リスク | 対策 |
|---|---|
| 利用者が B から A へリンクで戻ってしまい、bfcache 復元にならない | 画面に明記。かつ軸 1 が `invalid-not-bfcache`（token 変化）で機械的に検出する |

---

# 施策 5: 導出関数と validator の vitest

### 変更箇所

- `tests/js/unit/debug/bfcache-trial.test.ts` — **新規**

### 波及変更

なし。

### テスト計画

**fail-first**: 施策 1 の実装前にテストを書き、落ちることを確認してから実装に入る。

#### 軸 1 の真理値表（全行）

| # | 入力イベント列 | 期待 |
|---|---|---|
| 1 | started → hide(persisted=true) → show(persisted=true, token 一致) | `valid-bfcache` |
| 2 | started → hide(true) → show(false, token 不一致) | `invalid-not-bfcache` |
| 3 | started → hide(**false**) → show(true, token 一致) | `inconsistent` |
| 4 | started → hide(true) → show(**false**, token **一致**) | `inconsistent` |
| 5 | started → hide(true) → show(true, token **不一致**) | `inconsistent` |
| 6 | started → hide(true)（show 無し） | `incomplete` |
| 7 | started → hide(true) → aborted | `incomplete` |
| 8 | started → guard-state-changed のみ（hide/show 無し） | `invalid-wrong-route` |
| 9 | started のみ | `incomplete` |
| 10 | sequence 逆順（show が hide より前） | `inconsistent` |

#### 軸 2 の真理値表（全行）

| # | guard 状態遷移 + 補助イベント | 期待 |
|---|---|---|
| 1 | pending → verifying → null | `authenticated-unhidden` |
| 2 | pending → verifying → hide（秘匿維持）+ `redirect-observed` あり | `unauthenticated-redirected` |
| 3 | pending → verifying → hide（秘匿維持）、`redirect-observed` **無し** | `hidden-then-left` |
| 4 | pending → verifying → retry | `retry-hidden` |
| 5 | 遷移イベント無し | `not-observed` |
| 6 | pending のまま停止 | `failed-transition` |
| 7 | verifying を経ずに null（秘匿解除が早すぎる） | `failed-transition` |

#### 軸 3（総合）

- `expired-session` × `valid-bfcache` × `unauthenticated-redirected` → `pass`
- `active-session` × `valid-bfcache` × `authenticated-unhidden` → `pass`
- `expired-session` × `valid-bfcache` × `authenticated-unhidden` → `expectation-mismatch`
- `expired-session` × `valid-bfcache` × `hidden-then-left` → `undetermined`
- 任意 × `invalid-not-bfcache` × 任意 → `undetermined`（**空振りを pass にも fail にもしない**）
- 任意 × `incomplete` × 任意 → `undetermined`
- 任意 × `valid-bfcache` × `failed-transition` → `fail`

#### validator の負のコントロール

- [ ] `schemaVersion` 不一致 → `null`（破棄）
- [ ] 未知の `type` → `null`
- [ ] **許可外の余分なキーを持つ** → `null`
- [ ] `deviceModel` が最大長超過 → `null`
- [ ] `deviceModel` に許可外文字 → `null`
- [ ] JSON として壊れている → `null`
- [ ] 配列の 1 件だけ壊れている → **ログ全体を破棄**（部分採用しないことを固定）
- [ ] 正常なログ → パースされ、イベント数が一致

#### storage

- [ ] `setItem` が例外を投げる環境で `appendEvent` が `false` を返し、例外を伝播しない
- [ ] `probeStorageWritable()` が書き込み不可環境で `false`

### リスク

| リスク | 対策 |
|---|---|
| 真理値表とテストがずれる | 表をテストの `describe` 名に 1:1 で写し、行番号を対応させる |

---

# 施策 6: architecture テスト

### 変更箇所

- `tests/Feature/Architecture/DebugBfcacheTrialRouteGateTest.php` — **新規**
- `tests/js/architecture/debug-no-unload-listener.test.ts` — **新規**

### 波及変更

なし。

### 設計

#### route 包含テスト（Pest）

概念設計で「専用 env フラグを追加しない」と判断した前提条件が
**構造的に維持されていること**を機械固定する。

- [ ] `debug.bfcache-trial` / `debug.bfcache-trial.away` が
      **`LocalOnly` middleware を持つ**
- [ ] 同 route が **`auth` middleware を持つ**
- [ ] `config('app.env')` が local 以外のとき **404**
- [ ] `DEBUG_LOGIN_*` 未設定のとき **404**
- [ ] 認証済み + Basic 認証ありで 200 が返り、応答に **`Cache-Control: no-store` が含まれる**
      （no-store が実際に付くことの正のコントロール。付かなければ検証条件が崩れる）
- [ ] guest では `/login` へリダイレクト（`auth` が効いていることの負のコントロール）

`RefreshDatabase` はグローバル適用済み。ユーザーは Factory で生成する。

#### unload 禁止テスト（vitest）

`tests/js/architecture/` の既存 deny-by-default テスト群
（`logout-call-site-inventory.test.ts` 等）の様式に倣う。

対象: `resources/js/pages/Debug/BfcacheTrial*.svelte` と `resources/js/lib/debug/`。

- [ ] `unload` / `beforeunload` の文字列リテラルを含まないこと
- [ ] 検出できない書き方（動的なイベント名生成）が入った場合の**既知の限界を docblock に明記**する
      （`logout-call-site-inventory.test.ts` が同様の限界を明記している前例に倣う）

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（`Webmozart\Assert\Assert` を必要箇所で使用）
- [x] DTO を返している（該当なし。テストコード）

### テスト計画

本施策自体がテストである。**fail-first**: 施策 2・3 の実装前に書き、
route 未定義で落ちることを確認する。

### リスク

| リスク | 対策 |
|---|---|
| `AppLayout` 側に将来 `beforeunload` が入ると、検証ページ側だけ見ていても検出できない | **未解決事項として下記に記載**（対象範囲を広げるかは要判断） |

---

# 施策 7: ドキュメント反映

### 変更箇所

- `docs/supported-browsers.md` — 実機受入確認の節（L120-148 付近）
- `docs/TODO.md` — T085 の行

### 設計

#### `docs/supported-browsers.md`

L146 の「現時点でこのリポジトリに iOS 実機受入確認の記録はまだない」は
**本施策では変わらない**（設備を作るだけで実施はしない）。書き換えない。

追記する内容:

- 検証ページの所在（`/debug/bfcache-trial`）と、それが**手動確認の補助**であること
- **2 経路（失効セッション / 有効セッション）の両方 PASS が T085 の完了条件**であること
- 証跡セットの構成（失効セッション経路は `/login` 画面 + stored report の 2 枚）
- **`unauthenticated-redirected` は manual confirmation を含む**こと
  （完全自動判定と誤読させない）
- トンネル運用規律: (1) 検証中のみ起動 (2) Basic 認証の資格情報を使い回さない (3) 検証後に停止
- **本検証では HTTPS 必須**（`crypto.randomUUID()` が secure context を要求するため、
  概念設計の「ほぼ必須」から表現を強める。Codex Round 6 §5 の指摘）

#### `docs/TODO.md`

T085 の備考を「Playwright 不可のため実機で確認+記録」から、
検証ページを使う手順と 2 経路必須である旨に更新する。
**T085 自体はクローズしない**（本施策は設備であり実施ではない）。

### テスト計画

- [ ] `docs/supported-browsers.md` からの参照切れが無いこと
      （既存 `verification-commands-doc-sync.test.ts` と同種の観点。
      新規テストを足すかは実装時に既存 gate の射程を確認して判断）

### リスク

| リスク | 対策 |
|---|---|
| 設備を作っただけで「T085 対応済み」と誤読される | TODO の文面で「設備の追加であり実機確認は未実施」と明記。`supported-browsers.md` L146 も書き換えない |

---

## 未解決事項（実装前に判断が要る）

| # | 事項 | 選択肢 |
|---|---|---|
| 1 | `AppLayout` に将来 `beforeunload` が入った場合の検出 | (a) unload 禁止テストの対象を `AppLayout` まで広げる（production コンポーネントに debug 都合の制約を課す）/ (b) 検証ページ側だけに留め、リスクとして受容し docblock に記す |
| 2 | 2 経路を束ねる試行セット識別子 | (a) 持つ（同一端末・OS・モードの組を機械的に対応付け）/ (b) 持たず devnotes 上の記載で対応付ける |
| 3 | `appendEvent` の read-back validation | (a) 毎回行う（確実だが遅い）/ (b) 試行開始時の `probeStorageWritable()` のみ |

いずれも Codex 詳細設計レビューで判断を仰ぐ。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規ファイル 7 本が中心で、既存ファイルへの変更は `routes/web.php` の 1 ブロック追記と docs 2 本のみ。他 TODO と競合する箇所が無い。施策 1 → 5（fail-first）→ 2 → 6 → 3 → 4 → 7 の順で 1 本の worktree に収まる |
| 競合リスク | `routes/web.php` の debug ブロックに他 TODO が同時に触る可能性は低い。`docs/supported-browsers.md` は T085 実施時に再度触るため、本施策では追記に留め既存記述を書き換えない |
