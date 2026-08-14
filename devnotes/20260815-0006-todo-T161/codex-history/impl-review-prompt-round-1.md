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
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。想定外のパターンも判断材料になる。何が起きているのかを理解してから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なコードレビュアーです。Laravel + Svelte アプリの実装をレビューしてください。

【前提環境】
PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript / PHPStan level 10 / Pest / vitest

【レビュー観点】
1. 詳細設計との一致性（設計から逸脱していないか。逸脱があるなら妥当か）
2. コードの正確性（ロジックエラー、エッジケース、null 安全性）
3. PHPStan level 10 適合性
4. DTO / JsonResource パターンの遵守
5. テストの網羅性（真理値表の全行が実際にテストされているか。負のコントロールがあるか）
6. セキュリティ（AGENTS.md のセキュリティ不変条件）
7. DESIGN.md 準拠: /DESIGN.md が design token の canonical source。color / radius / typography を token 経由で参照し hex 直書きを増やしていないか
8. Atomic Design 準拠: resources/js/components/ の atoms/molecules/organisms/templates の責務分離。アイコンは Lucide、SVG 直書きを増やしていないか
9. 禁止事項 8（必須条件未充足を理由にボタンを disabled にしない）の遵守

【出力形式】
- ファイルごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本件の経緯】
概念設計・詳細設計とも Codex 合議で APPROVED 済み (各 6 ラウンド)。決着済みで蒸し返さない事項:
- 専用 env フラグを追加しない / /login 到達は手動確認 / logout 導線を新設しない
- 離脱は plain anchor の full document navigation
- 検証対象 (bfcache-guard.ts / 秘匿 CSS / /session/status) には手を入れない

【実装時に設計から変えた点（重点的に見てほしい）】
1. middleware 実行順の実測により、guest には 404 ではなく /login への 302 が返ることが判明。
   Authenticate が Laravel 既定 priority list に載っており LocalOnly より先にソートされるため。
   bootstrap/app.php の priority list は TenantBoundaryOrderingTest が固定する load-bearing な
   宣言なので動かさず、テストを「認証済みユーザーに対する LocalOnly の実効性」中心に組み替えた。
   詳細設計にも実測所見として追記済み。この判断は妥当か。
2. テストの置き場所を設計の tests/js/unit/debug/ から既存慣習の tests/js/lib/debug/ へ、
   tests/Feature/Architecture/ から tests/Feature/ へ変更 (既存 LocalOnlyMiddlewareTest と同階層)。
3. lib に normalizeUserReported / isValidDeviceModel / isValidVerifiedOsVersion を追加
   (ページ側で正規表現を複製しないため。設計には明記していなかった)。

【参照できる実ファイル】
- devnotes/20260812-1931-bfcache-device-verification-page/detailed-design.md (詳細設計)
- devnotes/20260812-1931-bfcache-device-verification-page/conceptual-design.md
- resources/js/lib/bfcache-guard.ts (検証対象。変更していない)
- routes/web.php / app/Http/Middleware/LocalOnly.php / bootstrap/app.php
- DESIGN.md / docs/design-system.md / docs/supported-browsers.md
- resources/js/components/atoms/ (Button.types.ts に variant 定義)

## テスト結果

- PHPStan level 10: No errors
- Pest: 4564 tests / 4562 passed / 2 skipped / 0 failed
- vitest (root): 1439 passed (うち本施策の新規 75 + 2)
- vitest (packages): 106 passed
- pint --test / eslint / tsc --noEmit / vite build: すべて green

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

/**
 * 離脱リンクが押された**操作事実**を同期記録する。
 * `page-hide` の不在だけから離脱失敗を推論しない (正常な時間差と区別できないため)。
 * 離脱失敗の判定は `AwayNavigationFailedEvent` (手動記録) のみが担う。
 */
export interface AwayNavigationStartedEvent extends EventBase {
    type: "away-navigation-started";
}

/**
 * 離脱が始まらなかったことを**利用者が明示的に記録する**手動イベント。
 *
 * タイマーで自動判定しない: 次タスク時点で `visibilityState !== "hidden"` でも
 * その後に正常な full navigation が進みうる (誤検出) 一方、intercept 後に
 * 別処理がページを hidden にすれば見逃す。どちらの向きにも外すので、
 * 「観測できないことを推論しない」という本設計の原則に反する。
 */
export interface AwayNavigationFailedEvent extends EventBase {
    type: "away-navigation-failed";
    observationMethod: "manual";
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
    reason: string; // 最大 200 文字
}

export interface TrialAbortedEvent extends EventBase {
    type: "trial-aborted";
}

export type TrialEvent =
    | TrialStartedEvent
    | AwayNavigationStartedEvent
    | AwayNavigationFailedEvent
    | PageHideEvent
    | PageShowEvent
    | GuardStateChangedEvent
    | RedirectObservedEvent
    | StorageFailedEvent
    | TrialAbortedEvent;

/** 復元後も採番が壊れないよう、常に max(sequence) + 1 を返す純粋関数。 */
export function nextSequence(events: TrialEvent[], trialId: string): number;
```

**verdict 型は持たない。** 軸 1 / 軸 2 / 総合はすべて導出関数で計算する
（概念設計 §型境界。保存すると `redirect-observed` 追記時に stale になる）。

#### 手入力値の制約

| フィールド | 最大長 | 許可文字 | 正規化 |
|---|---|---|---|
| `deviceModel` | 40 | 英数字 / 空白 / `-` / `,` / `(` `)` / `.` | 前後 trim、連続空白を 1 個に |
| `verifiedOsVersion` | 20 | 数字 / `.` / 英字 / 空白 | 同上 |
| `StorageFailedEvent.reason` | 200 | 制限なし（例外メッセージ由来） | 超過分は切り詰める |

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
    | "in-progress"            // 正常な遷移の途中 (prefix)。終端していない
    | "authenticated-unhidden" | "unauthenticated-redirected"
    | "hidden-then-left" | "retry-hidden" | "failed-transition" | "not-observed";

export type OverallVerdict = "pass" | "fail" | "expectation-mismatch" | "undetermined";

export function deriveTrialVerdict(events: TrialEvent[]): TrialVerdict;
export function deriveGuardVerdict(events: TrialEvent[]): GuardVerdict;
export function deriveOverallVerdict(
    scenario: TrialScenario, trial: TrialVerdict, guard: GuardVerdict,
): OverallVerdict;
```

#### terminal window（証跡の後発汚染を防ぐ）

**失効セッション経路では、判定確定後に必ず追加観測が発生する。**
`/login` 到達後に再ログインして A を stored report で開く手順が前提なので、
新しい document の `page-show(persisted=false, token 不一致)` が同じ trial に流れ込み、
放置すると軸 1 が `valid-bfcache` から `inconsistent` へ**後から崩れる**。

そこで **軸 1 window** を導入する。

- 最初に成立した `trial-started < away-navigation-started < page-hide < page-show` の窓を、
  **軸 1 が参照する唯一の範囲**として確定させる
- **軸 1 はこの窓の外のイベントを一切参照しない**

#### 軸 1 window の確定と観測終了は別物である

**窓が確定しても観測は止めない。** 止めると軸 2 が壊れる。
失効セッション経路の実際の時系列は次のとおりで、
**軸 2 の根拠になる `page-hide` は窓の確定より後に発生する**。

```text
A → B の離脱      : page-hide            ← 軸 1 の往路
A を bfcache 復元 : page-show            ← ここで軸 1 window が確定
                    pending → verifying
                    location.replace('/login')
                  : page-hide            ← 軸 2 の「秘匿維持のまま離脱」の観測
```

| 概念 | 意味 |
|---|---|
| **軸 1 window の確定** | 軸 1 の参照範囲が固まる。**記録は止めない** |
| **観測終了** | listener の自動追記を止める。**軸 2 が終端したとき** |

観測終了の条件（いずれか）:

- `authenticated-unhidden`（属性が削除された）
- `retry-hidden`
- **秘匿維持状態での復元後 `page-hide`**（= リダイレクト離脱と見なせる）
- `trial-aborted`（利用者による中止）

保存側で後続 lifecycle を捨てるのではなく、
**導出側で軸ごとに観測範囲を固定する**。証跡としても完全になる。

#### 試行の進行状態（保存せず導出する）

listener の追記可否は、保存した status ではなく純粋導出関数で決める
（保存すると stale 化する）。

```ts
export type TrialPhase =
    | "invalid"                      // 入力が契約違反 (複数 trialId 等)。自動追記を許可しない
    | "collecting-axis1"
    | "collecting-axis2"
    | "awaiting-manual-confirmation"
    | "complete"
    | "aborted";

/** 3 導出関数の共通事前条件。 */
export function hasSingleTrialId(events: TrialEvent[]): boolean;

export function deriveTrialPhase(events: TrialEvent[]): TrialPhase;
```

**判定優先順位（純粋関数の契約として固定する）**:

```text
invalid input (hasSingleTrialId が false)  → invalid
trial-aborted あり                          → aborted
軸1 未終端                                  → collecting-axis1
軸1 が valid-bfcache 以外で終端             → complete
軸2 が in-progress                          → collecting-axis2
軸2 が hidden-then-left                     → awaiting-manual-confirmation
軸2 が上記以外で終端                        → complete
```

**`in-progress` が `collecting-axis2` に写ることが要点**である。
これが無いと、正常な `pending` / `pending → verifying` の途中で
`complete` に落ちて自動追記が止まり、`null` / `retry` / 復元後 `page-hide` を
記録できなくなる。

**phase ごとに許可する追記イベント**:

| phase | 自動追記 | 手動追記 |
|---|---|---|
| `invalid` | **不可** | 不可 |
| `collecting-axis1` | 可 | `trial-aborted` / `away-navigation-failed` |
| `collecting-axis2` | 可 | `trial-aborted` |
| `awaiting-manual-confirmation` | **不可** | `redirect-observed` / `trial-aborted` |
| `complete` | **不可** | 不可 |
| `aborted` | **不可** | 不可 |

`awaiting-manual-confirmation` で自動追記を止めることが、
**再ログイン後の fresh load による証跡汚染を防ぐ実装上の要**である。

`collecting-axis1` のまま hide/show が来ない場合も **listener は止めない**。
利用者の中止操作で閉じる（**タイムアウトは追加しない**。
自動的に失敗へ変換しないという概念設計の決定と整合する）。

#### 複数 trialId 混入時の各関数の返り値

| 関数 | 違反時の返り値 |
|---|---|
| `deriveTrialVerdict()` | `inconsistent` |
| `deriveGuardVerdict()` | `failed-transition`（`in-progress` にしない。異常入力は終端扱い） |
| `deriveTrialPhase()` | `invalid` |

**黙って無視して混ぜない**（誤判定より異常値を返す方が安全）。

**軸 1 の判定規則**（概念設計の 5 条件を機械化）:

`valid-bfcache` は次をすべて満たす場合に限る。

1. `page-hide.persisted === true`
2. 対応する `page-show.persisted === true`
3. `page-show.contextToken === trial-started.contextToken`（**full 値で比較**）
4. 同一 `trialId`
5. `sequence` 上で `trial-started < away-navigation-started < page-hide < page-show`

| 条件 | 判定 |
|---|---|
| 上記 5 条件をすべて満たす | `valid-bfcache` |
| `page-show.persisted === false` かつ token 不一致 | `invalid-not-bfcache` |
| **`away-navigation-failed` イベントがある** | `invalid-wrong-route` |
| `page-hide` はあるが対応する `page-show` が無い／`trial-aborted`／`away-started` の後まだ `page-hide` が無い | `incomplete` |
| 上記のいずれにも当てはまらない（**`page-hide.persisted` と `page-show.persisted` の不一致を含む**） | `inconsistent` |
| イベント列に**複数の `trialId` が混入**している | `inconsistent` |

`guard-state-changed` の有無だけを根拠に `invalid-wrong-route` と判定**しない**
（開始直後や遅延した初回 guard でも同じ形になるため、推論として強すぎる）。

**「`away-started` があり `page-hide` がまだ無い」だけでも判定しない。**
リンク押下と `pagehide` の間には正常な時間差があり、
その瞬間に再描画すると正常な遷移を失敗として表示してしまう。

**タイマーによる自動判定も行わない。** 次タスク時点で `visibilityState !== "hidden"` でも
その後に正常な full navigation が進みうる（誤検出）一方、
intercept 後に別処理がページを hidden にすれば見逃す。どちらの向きにも外す。

`invalid-wrong-route` は**利用者が明示的に記録した `away-navigation-failed`
（手動イベント）からのみ**導出する。それ以外は `incomplete`（安全側）。
画面には「離脱が始まっていないようです」という**診断表示に留め**、
記録するかどうかは利用者が決める。

#### derive 関数は単一 trialId の配列だけを受ける

`loadTrials()` が trialId ごとの分離を担うため、derive 側で対象 ID を選ばせない。
複数 ID が混入していたら `inconsistent` を返す（黙って混ぜて誤判定しない）。

**軸 2 の判定規則**:

**軸 2 が参照するのは、軸 1 window の `page-show.sequence` より後のイベントだけ。**
往路（A → B）の `page-hide` を軸 2 のリダイレクト離脱として拾ってはならない。

**イベントは逐次追記されるため、正常経路も必ず途中状態を通る。**
`pending` だけの瞬間や `pending → verifying` の瞬間を異常扱いすると、
終端が来る前に listener が止まって観測が壊れる。
そこで**正常遷移の prefix を `in-progress` として明示的に区別する**。

| 観測（すべて復元 `page-show` より後） | 判定 |
|---|---|
| `guard-state-changed` がまだ無い（試行は継続中） | **`in-progress`** |
| `pending` のみ | **`in-progress`** |
| `pending → verifying` | **`in-progress`** |
| `pending → verifying → 属性削除(null)` | `authenticated-unhidden` |
| `pending → verifying → 秘匿維持のまま page-hide` **かつ** `redirect-observed` あり | `unauthenticated-redirected` |
| 同上だが `redirect-observed` **無し** | `hidden-then-left` |
| `pending → verifying → retry` | `retry-hidden` |
| `trial-aborted` された時点で `guard-state-changed` が一度も無い | `not-observed` |
| **正常遷移の prefix ではない列** | `failed-transition` |

`failed-transition` に落とすのは次のような列に限定する。

- `pending` を経ずに `verifying` から始まる
- `pending → 属性削除(null)`（`verifying` を経ずに秘匿解除 = 解除が早すぎる）
- 終端後に矛盾した遷移が続く

「`pending` のまま停止」は**それ自体では異常ではない**（停止したかどうかは
イベント列からは判断できない）。利用者が `trial-aborted` するまで `in-progress` に留める。

**軸 3（総合）**:

```
期待 guard 結果 = scenario === "expired-session"
    ? "unauthenticated-redirected"
    : "authenticated-unhidden";

trial === "incomplete"          → "undetermined"
trial !== "valid-bfcache"       → "undetermined"   // 空振り。PASS でも FAIL でもない
guard === "in-progress"         → "undetermined"   // 観測途中。判定を出さない
guard === "not-observed"        → "undetermined"
guard === 期待値                → "pass"
guard が期待値と異なる正常終端  → "expectation-mismatch"
guard === "failed-transition"   → "fail"
```

**`in-progress` を必ず `undetermined` に落とすこと**が要点である。
これが無いと、復元直後の正常な観測途中が一時的に `fail` と表示される。

`not-observed` を `fail` ではなく `undetermined` とする意図:
これは「`trial-aborted` された時点で guard イベントが 1 件も無い」状態である。
guard が本当に発火しなかったのか、利用者が早すぎる時点で中止したのかを
**イベント列からは区別できない**。区別できないものを `fail` と断定しない
（本設計が一貫して採る立場）。

`hidden-then-left` は `expired-session` の期待値に**達していない**ので `pass` にしない
（`redirect-observed` が入るまで `expectation-mismatch` ではなく `undetermined` 扱い）。

#### storage 層

```ts
export function probeStorageWritable(): boolean; // 試行開始前の保存テスト
export function appendEvent(event: TrialEvent): boolean; // 失敗時 false（例外を投げない）
export function loadTrials(): Map<string, TrialEvent[]>; // trialId ごとに分離して返す
```

- キー: `bfcache-trial:v1`（単一キーに全試行の event 配列を JSON で保持）
- `appendEvent` は `try/catch` で例外を捕捉し、**黙って成功扱いにしない**
- **書き込みのたびに read-back validation を行う**（未解決事項 3 = (a)）。
  証跡ツールなので破損の即時検出を優先する。1 試行あたりのイベントは 10 件未満で
  性能上の問題は無い
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

### 実装時の実測: `auth` は `LocalOnly` より先に走る（2026-08-14）

設計は「local 以外は 404 で**経路の存在自体を秘匿**する」を前提にしていたが、
実装して測ったところ **guest には 404 ではなく `/login` への 302 が返る**。

**機序**: `Authenticate` は Laravel 既定の priority list に載っており、
載っていない `LocalOnly` より前へソートされる。
`bootstrap/app.php` の注記どおり
「priority list は載っている middleware 同士の相対順序しか強制しない」ため、
`LocalOnly` グループの内側に `auth` を書いても実行順は逆転する。
`auth` を持たない `/debug/login` とはここが非対称になる。

**判断: 許容する。priority list は動かさない。**

- staging / production では route 登録ゲートが働き **route 自体が存在しない**ため、
  存在オラクルにならない
- local でのみ guest が 302 を観測しうるが、開発者自身の環境である。
  実際に到達しうる相手（認証済みユーザー）に対しては
  `LocalOnly` の env / 資格情報ゲートが正しく 404 / 401 を返す
- `bootstrap/app.php` の priority list は `TenantBoundaryOrderingTest` が固定している
  **load-bearing な宣言**であり、local 限定 debug ページのために順序を動かすのは
  リスクに見合わない（思考原則 2）

施策 6 のテストはこの実測に合わせ、
**認証済みユーザーに対する `LocalOnly` の実効性**を主に固定し、
guest には 302（= `auth` が効いていること）を負のコントロールとして固定する。

### リスク

| リスク | 対策 |
|---|---|
| `auth` を付け忘れると guest でも開け、no-store も付かず検証が無効になる | 施策 6 の route 包含テストで `auth` の存在を機械固定 |
| 将来 debug ブロックの外へ移動される | 同テストで env ゲート・資格情報ゲートの実効性を固定 |

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
| context token | **`onMount` 内**で 1 回だけ生成。**比較は full 値**、表示は先頭 8 文字 |
| `pagehide` / `pageshow` | `window.addEventListener`。**`unload` / `beforeunload` は使わない** |
| 離脱リンク押下 | `click` ハンドラで `away-navigation-started` を**同期記録**してから遷移させる |
| guard 状態 | `MutationObserver` で `documentElement` の `data-bfcache-hidden` を監視（`attributeFilter`） |

module scope で `crypto.randomUUID()` を評価すると SSR / テスト import 時に壊れるため使わない。
**bfcache 復元では component が再生成されず `onMount` は再実行されない**ので、
「Document 生存を示す」という token の目的は `onMount` 初期化でも満たされる
（fresh load でのみ再生成される）。

feature detection と呼び出しを分ける（receiver を失わない）:

```ts
if (typeof globalThis.crypto?.randomUUID !== "function") {
    // 検証不能を表示して終了
}
const contextToken = globalThis.crypto.randomUUID();
```

`pagehide` ハンドラ内の `sessionStorage` 書き込みは同期実行する。

**自動追記を止めるのは軸 1 window の確定時ではなく、軸 2 の終端時**である
（施策 1 §軸 1 window の確定と観測終了は別物）。
追記可否は `deriveTrialPhase()` の結果で決める。

#### 型の扱い

`navigator.standalone` は TypeScript 標準型に存在しない。`any` に逃がさず型を切る。

```ts
interface NavigatorWithStandalone extends Navigator {
    standalone?: boolean;
}
```

取得値は `boolean | null` に正規化する（未定義環境を `false` と混同しない）。

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
  `/login` 到達を記録（`redirect-observed`）/ **離脱失敗を記録**（`away-navigation-failed`）/
  テキストコピー

#### ボタンを disabled にしない（禁止事項 8）

phase により許可されない操作を **disabled にしてはならない**
（AGENTS.md 禁止事項 8「必須条件未充足を理由にボタンを disabled にする UI」）。

- ボタンは常に押せる
- **押下時に `deriveTrialPhase()` を検査**する
- 許可されない場合はイベントを追記せず、**理由を画面に表示**する
  （例: 「この試行は既に完了しています」「観測中のため記録できません」）
- 二重送信防止など**処理実行中の一時的な disabled とは区別**する
  （`Debug/Login.svelte` の `submitting` と同じ扱いは可）

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

押下時に記録する `away-navigation-started` は**操作事実のみ**を残す。
これ自体は離脱失敗の判定に使わない。
**離脱失敗は利用者が `away-navigation-failed` を手動記録した場合に限り
`invalid-wrong-route` となる**（施策 1）。
画面には「離脱が始まっていないようです」という診断表示を出すに留める。

#### DESIGN.md / Atomic Design 準拠

- 入力欄・ボタン・状態表示は**既存の DS token と atoms/molecules を使う**
  （`Input` / `TextLink` 等。`Debug/Login.svelte` と同じ構成）
- **hex 直書き・独自 radius・独自 typography を新設しない**。色は token 経由
- アイコンは **Lucide**（`@lucide/svelte`）。SVG 直書きを新設しない
- debug ページのために atoms/molecules を新設しない
  （既存で足りない場合は組合せで解決する。debug 都合で DS を汚さない）

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
| `AppLayout` が将来 `beforeunload` を持つと検証が空振りする | **施策 6 の unload 禁止テストの対象に `AppLayout` を含める**（design-review Round 1 で決定済み） |

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
- **失効セッション経路の残り手順**:
  (1) `/login` へ着地したらその画面を撮影する
  (2) `/debug/login` で入り直す
  (3) A を stored report で開き、`/login` 到達を記録する（`redirect-observed`）
  (4) stored report を撮影する

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

基本形は `started → away → hide → show`（`away` 欠落は #14 に分離）。

| # | 入力イベント列 | 期待 |
|---|---|---|
| 1 | started → away → hide(persisted=true) → show(persisted=true, token 一致) | `valid-bfcache` |
| 2 | started → away → hide(true) → show(false, token 不一致) | `invalid-not-bfcache` |
| 3 | started → away → hide(**false**) → show(true, token 一致) | `inconsistent` |
| 4 | started → away → hide(true) → show(**false**, token **一致**) | `inconsistent` |
| 5 | started → away → hide(true) → show(true, token **不一致**) | `inconsistent` |
| 6 | started → away → hide(true)（show 無し） | `incomplete` |
| 7 | started → away → hide(true) → aborted | `incomplete` |
| 8 | started → away（hide 無し。**failed イベント無し**） | `incomplete`（時間差を失敗と見なさない） |
| 9 | started → away → **away-navigation-failed（手動記録）** | `invalid-wrong-route` |
| 10 | started のみ | `incomplete` |
| 11 | sequence 逆順（show が hide より前） | `inconsistent` |
| 12 | started → guard-state-changed のみ | `incomplete`（**`invalid-wrong-route` にしない**） |
| 13 | 複数 `trialId` が混入 | `inconsistent` |
| 14 | started → hide → show（**away 欠落**） | `inconsistent`（必須条件 5 を満たさない） |
| 15 | **軸 1 window 確定後**に show(false, token 不一致) が追記されている | `valid-bfcache` を維持（窓の外は参照しない） |
| 16 | 窓確定後に redirect-observed が追記されている | `valid-bfcache` を維持 |
| 17 | 窓確定後に**復元後 page-hide** が追記されている | `valid-bfcache` を維持（軸 2 の観測であって軸 1 には影響しない） |

#### 軸 2 の真理値表（全行）

すべて**軸 1 window の `page-show` より後**のイベントを対象とする。

| # | guard 状態遷移 + 補助イベント | 期待 |
|---|---|---|
| 1 | 往路 hide → 復元 show → pending → verifying → null | `authenticated-unhidden` |
| 2 | 往路 hide → 復元 show → pending → verifying → **復元後 hide**（秘匿維持）+ `redirect-observed` | `unauthenticated-redirected` |
| 3 | 同上だが `redirect-observed` **無し** | `hidden-then-left` |
| 4 | 往路 hide → 復元 show → pending → verifying → retry | `retry-hidden` |
| 7 | verifying を経ずに null（秘匿解除が早すぎる） | `failed-transition` |
| 8 | **往路 hide のみ**（復元後 hide 無し）で `redirect-observed` あり | `unauthenticated-redirected` に**しない**（往路 hide を redirect hide として採用しない） |
| 9 | 軸 2 終端後に fresh load の show/guard イベントが追記されている | 両軸の判定が崩れない |
| 10 | 復元 show 直後、guard イベント無し | **`in-progress`** |
| 11 | `pending` のみ | **`in-progress`** |
| 12 | `pending → verifying` | **`in-progress`** |
| 13 | `verifying` から始まる（`pending` を経ない） | `failed-transition` |
| 14 | `pending → null`（`verifying` を経ない） | `failed-transition` |
| 15 | guard イベント無しのまま `trial-aborted` | `not-observed` |

#### 逐次適用のテスト（最終形だけでなく各追記直後を検証する）

最終形の純粋関数テストが通っても、**途中で listener が止まると実機で観測できない**。
イベントを 1 件ずつ追記しながら、各時点の `GuardVerdict` と `TrialPhase` を検証する。

| 追記後の状態 | 期待 `GuardVerdict` | 期待 `TrialPhase` |
|---|---|---|
| 軸 1 window 確定直後（guard イベント無し） | `in-progress` | `collecting-axis2` |
| `pending` | `in-progress` | `collecting-axis2` |
| `pending → verifying` | `in-progress` | `collecting-axis2` |
| `pending → verifying → null` | `authenticated-unhidden` | `complete` |
| `pending → verifying → retry` | `retry-hidden` | `complete` |
| `pending → verifying → 復元後 hide` | `hidden-then-left` | `awaiting-manual-confirmation` |
| 上記 + `redirect-observed` | `unauthenticated-redirected` | `complete` |

#### 軸 3 に `in-progress` / `not-observed` を渡すテスト

- [ ] `valid-bfcache` × `in-progress` → `undetermined`（**`fail` にしない**）
- [ ] `valid-bfcache` × `not-observed` → `undetermined`
- [ ] `valid-bfcache` × `failed-transition` → `fail`

#### ボタン挙動（禁止事項 8）

- [ ] 許可されない phase でもボタンが **disabled にならない**こと
- [ ] 押下時にイベントが追記されず、理由が表示されること

#### 軸 3（総合）

- `expired-session` × `valid-bfcache` × `unauthenticated-redirected` → `pass`
- `active-session` × `valid-bfcache` × `authenticated-unhidden` → `pass`
- `expired-session` × `valid-bfcache` × `authenticated-unhidden` → `expectation-mismatch`
- `expired-session` × `valid-bfcache` × `hidden-then-left` → `undetermined`
- **任意 × `valid-bfcache` × `in-progress` → `undetermined`**（観測途中を fail にしない）
- **任意 × `valid-bfcache` × `not-observed` → `undetermined`**
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

#### storage / 採番 / trial 分離

- [ ] `setItem` が例外を投げる環境で `appendEvent` が `false` を返し、例外を伝播しない
- [ ] `probeStorageWritable()` が書き込み不可環境で `false`
- [ ] **read-back validation** が書き戻し内容の不一致を検出して `false` を返す
- [ ] `nextSequence()` が空配列で 0（または 1）を返す
- [ ] `nextSequence()` が **sessionStorage から復元した進行中 trial に対して `max+1`** を返す
      （reload をまたいでも採番が壊れない）
- [ ] `nextSequence()` が sequence の欠番・重複があっても `max+1` を返す
- [ ] `loadTrials()` が**複数 trialId を分離**して返す

#### 複数 trialId 混入時の 3 関数（無視ではなく異常値）

- [ ] `deriveTrialVerdict()` → `inconsistent`
- [ ] `deriveGuardVerdict()` → `failed-transition`
- [ ] `deriveTrialPhase()` → `invalid`（自動追記禁止状態）
- [ ] `hasSingleTrialId()` が単一 ID で `true`、混入で `false`

#### `deriveTrialPhase()` の状態機械（境界を固定する）

- [ ] 軸1 incomplete → `collecting-axis1`
- [ ] valid window 後、guard 未終端 → `collecting-axis2`
- [ ] `hidden-then-left` → `awaiting-manual-confirmation`
- [ ] `redirect-observed` 追記後 → `complete`
- [ ] `trial-aborted` → `aborted`（他の終端イベントと併存しても `aborted` が優先）
- [ ] 軸1 が `invalid-not-bfcache` で終端 → `complete`
- [ ] **`awaiting-manual-confirmation` 中の fresh load イベントを自動追記しない**

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
- [ ] 200 応答の **Inertia component 名**が `Debug/BfcacheTrial` /
      `Debug/BfcacheTrialAway` であること（controller の取り違え検出）

構造の証明（「`LocalOnly` グループ内にある」）に寄りかからず、
**実効条件を正負のコントロールで固定する**方針を採る。

`RefreshDatabase` はグローバル適用済み。ユーザーは Factory で生成する。

#### unload 禁止テスト（vitest）

`tests/js/architecture/` の既存 deny-by-default テスト群
（`logout-call-site-inventory.test.ts` 等）の様式に倣う。

**対象は debug ページに留めない**（未解決事項 1 = (a)）。

| 対象 | 理由 |
|---|---|
| `resources/js/pages/Debug/BfcacheTrial*.svelte` | 検証ページ自身 |
| `resources/js/lib/debug/` | 観測ライブラリ |
| **`resources/js/components/templates/AppLayout.svelte`** | ここに `beforeunload` が入ると、検証ページ側をいくら縛っても検証条件が壊れる |
| **`resources/js/lib/bfcache-guard.ts` / `resources/js/app.ts`** | 経路 B の当事者 |

`AppLayout` を対象に含めるのは **debug 設備の都合ではない**。
`unload` / `beforeunload` が入ると認証済み画面が
**bfcache 対象外になる、または適格性が不安定になる**ため、
**guard が守ろうとしている経路 B が無効化されうる**。
これは認証済み画面全体の bfcache 契約に関わる制約である。
テストの docblock にこの理由を明記する。

> ブラウザ横断で「`beforeunload` があれば必ず bfcache 対象外」と断定はしない
> （本設計は一貫してブラウザ挙動を出典なく断定しない立場を取る）。
> 禁止の理由は「対象外になる、または適格性を不安定にする」で十分である。

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
| 動的なイベント名生成で `unload` 登録を書かれると検出できない | 既知の限界として docblock に明記（`logout-call-site-inventory.test.ts` の前例に倣う） |

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
  （完全自動判定と誤読させない）。**`docs/TODO.md` の T085 と同一の表現を使う**
  （両文書で言い回しがずれると、片方だけ読んだ人が自動判定と誤解する）
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

## 実装時の確認事項（design-review Round 6 で提示。設計変更は不要）

- `nextSequence()` の初期値を `0` / `1` のどちらかに確定し、テスト期待値と統一する
- ボタン挙動テストは Svelte component test を使うか、
  **phase 別の追記可否を純粋関数へ切り出して**テストする（後者が単純）
- `AwayNavigationFailedEvent` は少なくとも
  `trial-started < away-navigation-started < away-navigation-failed` の順序を導出テストで固定する
- `appendEvent()` の read-back では、JSON parse 成功だけでなく
  **追記したイベントが末尾に同値で存在すること**を確認する
- 実装完了時は Pest / Vitest に加え、**PHPStan level 10・型検査・lint・build** の
  規定レーンをすべて通す

## 過去の未解決事項（design-review Round 1 で決定済み）

| # | 事項 | 決定 | 根拠 |
|---|---|---|---|
| 1 | `AppLayout` の unload 検出 | **(a) 対象に含める** | debug 制約ではなく経路 B の前提条件。`beforeunload` が入れば認証済み画面が bfcache 対象外になり guard の守備範囲が消える |
| 2 | 2 経路を束ねる試行セット識別子 | **(b) 持たない** | まずは devnotes 上の対応付けで足りる（思考原則 2） |
| 3 | `appendEvent` の read-back validation | **(a) 毎回行う** | 証跡ツールなので破損の即時検出を優先。1 試行 10 件未満で性能上の問題なし |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規ファイル 7 本が中心で、既存ファイルへの変更は `routes/web.php` の 1 ブロック追記と docs 2 本のみ。他 TODO と競合する箇所が無い。施策 1 → 5（fail-first）→ 2 → 6 → 3 → 4 → 7 の順で 1 本の worktree に収まる |
| 競合リスク | `routes/web.php` の debug ブロックに他 TODO が同時に触る可能性は低い。`docs/supported-browsers.md` は T085 実施時に再度触るため、本施策では追記に留め既存記述を書き換えない |

---

## 実装差分 (git diff)

```diff
diff --git a/app/Http/Controllers/DebugBfcacheTrialController.php b/app/Http/Controllers/DebugBfcacheTrialController.php
new file mode 100644
index 0000000..f9ce1b3
--- /dev/null
+++ b/app/Http/Controllers/DebugBfcacheTrialController.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers;
+
+use Inertia\Inertia;
+use Inertia\Response;
+
+/**
+ * bfcache 実機受入確認 (T085) の検証ページ。LocalOnly + auth の背後でのみ動く。
+ *
+ * 目的: T085 の実機手順には**負のコントロールが無く**、「guard が働いた」と
+ * 「そもそも bfcache 復元が起きなかった」を目視で区別できない。どちらも
+ * 「PII が出ない」に見えるため空振りを合格として記録しうる。本ページは
+ * pageshow.persisted・JS 実行コンテキスト生存トークン・guard の状態遷移を
+ * 観測して、その区別を機械化する。
+ *
+ * 観測値はすべてクライアント側で生成されるため **props を一切渡さない**
+ * (実ユーザー情報を debug ページへ流さないための設計判断でもある)。
+ * サーバの責務は「認証済みページとして描画されること」だけで、それにより
+ * web グループの NoStoreCacheHeadersForAuthenticatedPages が no-store を付け、
+ * app.ts が登録した**本物の** bfcache guard がそのまま作動する
+ * (検証対象を検証の都合で変えたら、確認しているものが production と別物になる)。
+ *
+ * ページが 2 枚あるのは、A から **full document navigation** で離脱しないと
+ * A が bfcache に入らないためである。Inertia visit は同一 Document のままなので
+ * pagehide が起きず、戻る操作は経路 C (Inertia の history 復元) になってしまう。
+ */
+class DebugBfcacheTrialController extends Controller
+{
+    /** 検証ページ A (観測・判定・証跡表示)。 */
+    public function trial(): Response
+    {
+        return Inertia::render('Debug/BfcacheTrial');
+    }
+
+    /** 相方ページ B。A を bfcache に入れるための full document navigation の着地点。 */
+    public function away(): Response
+    {
+        return Inertia::render('Debug/BfcacheTrialAway');
+    }
+}
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index 1be8542..b94d639 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -147,6 +147,40 @@ ### 実機受入確認の再確認条件
 > **bfcache 復元後の実挙動 (PII が出ないこと) を実環境で確認できているものは無い**
 > — 自動回帰が復元を再現できない以上、実機確認は**補完ではなく現状唯一の実環境検証手段**である。
 
+### 検証ページ (`/debug/bfcache-trial`) — 手動確認の補助
+
+上記の実機受入確認そのものを自動化するものではなく、**手動確認を補助する道具**として
+`/debug/bfcache-trial` (検証ページ A) と相方ページ `/debug/bfcache-trial/away` (ページ B) を
+`LocalOnly` + `auth` の背後 (debug 限定) に用意している。
+設計は `devnotes/20260812-1931-bfcache-device-verification-page/`。
+
+**T085 の完了条件は、失効セッション経路 / 有効セッション経路の 2 経路が両方 PASS すること**である。
+どちらか片方のみ PASS した状態は T085 未完了として扱う。
+
+証跡セットの構成:
+
+| 経路 | 証跡 |
+|---|---|
+| 失効セッション経路 | `/login` 到達画面のスクリーンショット + stored report のスクリーンショットの **2 枚** |
+| 有効セッション経路 | live observation のスクリーンショット **1 枚** |
+
+失効セッション経路の軸 2 判定 `unauthenticated-redirected` は、**利用者が `/login` 到達を目視確認して
+記録する manual confirmation を含む** (イベント列だけからリダイレクト成功を機械的に断定しない設計であり、
+完全自動判定ではない)。この表現は `docs/TODO.md` の T085 の記述と揃えること
+(片方だけ読んだ人が自動判定と誤解しないため)。
+
+トンネル運用規律 (実機からの到達には HTTPS トンネルが要る。`APP_ENV=local` のまま露出する運用のため、
+誤公開時の影響を軽く見ない):
+
+1. トンネルは検証中のみ起動する
+2. Basic 認証 (`LocalOnly` middleware) の資格情報を他と使い回さない
+3. 検証後はトンネルを停止する
+
+**本検証では HTTPS 必須**である。`crypto.randomUUID()` は secure context を要求し、
+使えない環境では検証ページ自体が「secure context が必要です」と表示して終了する
+(沈黙で劣化させない設計)。撮影 PWA が `getUserMedia` / Service Worker のため
+そもそも secure context が前提であることとも整合する。
+
 ## 未対応事項 (誤読を防ぐため明示列挙する)
 
 - **どちらのレーンも bfcache 復元そのものを再現していない** (上記「実測」節)。
diff --git a/resources/js/lib/debug/bfcache-trial.ts b/resources/js/lib/debug/bfcache-trial.ts
new file mode 100644
index 0000000..c64aef6
--- /dev/null
+++ b/resources/js/lib/debug/bfcache-trial.ts
@@ -0,0 +1,709 @@
+/**
+ * bfcache 実機受入確認 (T085) の観測ライブラリ。
+ *
+ * 目的: T085 の実機手順には**負のコントロールが無く**、「guard が働いた」と
+ * 「そもそも bfcache 復元が起きなかった」を目視で区別できない。どちらも
+ * 「PII が出ない」に見えるため、空振りを合格として記録しうる。
+ * 同じ欠陥は Playwright レーンについては徹底的に潰されている
+ * (詳細設計 施策 8「空振りを green と偽らない」)。その規律を実機レーンへ揃える。
+ *
+ * 設計方針:
+ * - **検証対象 (bfcache-guard.ts / 秘匿 CSS / /session/status) には一切手を入れない**。
+ *   本ライブラリは guard を**外から観測するだけ**である
+ * - 判定は **二軸 + 総合**。「bfcache が成立したか」(軸 1) と
+ *   「guard が合格したか」(軸 2) は別の問いで、混ぜると受入失敗を PASS と読む
+ * - event log には**観測事実のみ**を保存し、verdict は保存しない
+ *   (後から `redirect-observed` が追記されるため、保存すると必ず stale になる)
+ * - **観測できないことを推論しない**。離脱先が /login だったか、離脱が
+ *   intercept されたかは A から観測できないため、利用者の手動記録に倒す
+ *
+ * 全体設計は devnotes/20260812-1931-bfcache-device-verification-page/detailed-design.md。
+ */
+
+/** schema 変更時に必ず上げる。復元時に不一致なら破棄する。 */
+export const TRIAL_SCHEMA_VERSION = 1;
+
+/** sessionStorage のキー。 */
+export const TRIAL_STORAGE_KEY = "bfcache-trial:v1";
+
+/** 検証シナリオ。利用者が試行開始時に宣言する。 */
+export type TrialScenario = "expired-session" | "active-session";
+
+/** guard の秘匿属性がとりうる値 (属性削除は null で表す)。 */
+export type GuardState = "pending" | "verifying" | "retry" | null;
+
+/** 利用者申告フィールドの制約 (自由記述の抜け道にしない)。 */
+export const DEVICE_MODEL_MAX_LENGTH = 40;
+export const VERIFIED_OS_VERSION_MAX_LENGTH = 20;
+export const STORAGE_FAILURE_REASON_MAX_LENGTH = 200;
+
+const DEVICE_MODEL_PATTERN = /^[A-Za-z0-9 \-,().]*$/;
+const VERIFIED_OS_VERSION_PATTERN = /^[A-Za-z0-9 .]*$/;
+
+/** 前後の空白を落とし、連続空白を 1 個に畳む。 */
+export function normalizeUserReported(value: string): string {
+    return value.trim().replace(/\s+/g, " ");
+}
+
+/**
+ * 利用者申告値を検証する。**許可外文字を除去して通さない**
+ * (除去すると利用者が意図しない値が証跡に残る)。拒否して入力し直させる。
+ */
+export function isValidDeviceModel(value: string): boolean {
+    return (
+        value.length <= DEVICE_MODEL_MAX_LENGTH &&
+        DEVICE_MODEL_PATTERN.test(value)
+    );
+}
+
+export function isValidVerifiedOsVersion(value: string): boolean {
+    return (
+        value.length <= VERIFIED_OS_VERSION_MAX_LENGTH &&
+        VERIFIED_OS_VERSION_PATTERN.test(value)
+    );
+}
+
+interface EventBase {
+    schemaVersion: number;
+    trialId: string;
+    sequence: number;
+    /** ISO 8601。 */
+    timestamp: string;
+}
+
+export interface TrialStartedEvent extends EventBase {
+    type: "trial-started";
+    scenario: TrialScenario;
+    contextToken: string;
+    userAgent: string;
+    uaReportedOs: string;
+    displayMode: string;
+    navigatorStandalone: boolean | null;
+    /** 利用者申告。長さ・文字種を制限する。 */
+    deviceModel: string;
+    /** 利用者申告。長さ・文字種を制限する。 */
+    verifiedOsVersion: string;
+}
+
+/**
+ * 離脱リンクが押された**操作事実**を同期記録する。
+ * `page-hide` の不在だけから離脱失敗を推論しない (正常な時間差と区別できないため)。
+ * 離脱失敗の判定は `AwayNavigationFailedEvent` (手動記録) のみが担う。
+ */
+export interface AwayNavigationStartedEvent extends EventBase {
+    type: "away-navigation-started";
+}
+
+/**
+ * 離脱が始まらなかったことを**利用者が明示的に記録する**手動イベント。
+ *
+ * タイマーで自動判定しない: 次タスク時点で `visibilityState !== "hidden"` でも
+ * その後に正常な full navigation が進みうる (誤検出) 一方、intercept 後に
+ * 別処理がページを hidden にすれば見逃す。どちらの向きにも外すので、
+ * 「観測できないことを推論しない」という本設計の原則に反する。
+ */
+export interface AwayNavigationFailedEvent extends EventBase {
+    type: "away-navigation-failed";
+    observationMethod: "manual";
+}
+
+export interface PageHideEvent extends EventBase {
+    type: "page-hide";
+    persisted: boolean;
+    guardState: GuardState;
+}
+
+export interface PageShowEvent extends EventBase {
+    type: "page-show";
+    persisted: boolean;
+    contextToken: string;
+    displayMode: string;
+}
+
+export interface GuardStateChangedEvent extends EventBase {
+    type: "guard-state-changed";
+    state: GuardState;
+}
+
+/**
+ * 利用者が /login 到達を確認して記録する手入力イベント。
+ *
+ * guard は `unauthenticated` のとき属性を `verifying` のまま
+ * `location.replace('/login')` を呼ぶため、A から観測できるのは
+ * 「秘匿を維持したまま離脱した」までである。離脱先は観測できない。
+ */
+export interface RedirectObservedEvent extends EventBase {
+    type: "redirect-observed";
+    observationMethod: "manual";
+}
+
+/**
+ * 保存できれば記録する診断イベント。**保存不能の永続証拠ではない**
+ * (storage が壊れていれば本イベント自身も残らない)。
+ */
+export interface StorageFailedEvent extends EventBase {
+    type: "storage-failed";
+    reason: string;
+}
+
+export interface TrialAbortedEvent extends EventBase {
+    type: "trial-aborted";
+}
+
+export type TrialEvent =
+    | TrialStartedEvent
+    | AwayNavigationStartedEvent
+    | AwayNavigationFailedEvent
+    | PageHideEvent
+    | PageShowEvent
+    | GuardStateChangedEvent
+    | RedirectObservedEvent
+    | StorageFailedEvent
+    | TrialAbortedEvent;
+
+export type TrialEventType = TrialEvent["type"];
+
+/** 軸 1: 試行が成立したか (bfcache 復元が本当に起きたか)。 */
+export type TrialVerdict =
+    | "valid-bfcache"
+    | "invalid-not-bfcache"
+    | "invalid-wrong-route"
+    | "inconsistent"
+    | "incomplete";
+
+/** 軸 2: guard がどう振る舞ったか。`in-progress` は正常遷移の途中 (終端していない)。 */
+export type GuardVerdict =
+    | "in-progress"
+    | "authenticated-unhidden"
+    | "unauthenticated-redirected"
+    | "hidden-then-left"
+    | "retry-hidden"
+    | "failed-transition"
+    | "not-observed";
+
+/** 軸 3: 総合。保存せず軸 1・軸 2 から導出する。 */
+export type OverallVerdict =
+    | "pass"
+    | "fail"
+    | "expectation-mismatch"
+    | "undetermined";
+
+/** 試行の進行状態。保存せず導出する (保存すると stale 化する)。 */
+export type TrialPhase =
+    | "invalid"
+    | "collecting-axis1"
+    | "collecting-axis2"
+    | "awaiting-manual-confirmation"
+    | "complete"
+    | "aborted";
+
+// ---------------------------------------------------------------------------
+// validator
+// ---------------------------------------------------------------------------
+
+/**
+ * 各 event type に許可されるキー。**ここに無いキーを 1 つでも持つイベントは拒否する**
+ * (余分キーの混入を黙って通さない)。
+ */
+const ALLOWED_KEYS: Record<TrialEventType, readonly string[]> = {
+    "trial-started": [
+        "scenario",
+        "contextToken",
+        "userAgent",
+        "uaReportedOs",
+        "displayMode",
+        "navigatorStandalone",
+        "deviceModel",
+        "verifiedOsVersion",
+    ],
+    "away-navigation-started": [],
+    "away-navigation-failed": ["observationMethod"],
+    "page-hide": ["persisted", "guardState"],
+    "page-show": ["persisted", "contextToken", "displayMode"],
+    "guard-state-changed": ["state"],
+    "redirect-observed": ["observationMethod"],
+    "storage-failed": ["reason"],
+    "trial-aborted": [],
+} as const;
+
+const BASE_KEYS: readonly string[] = [
+    "schemaVersion",
+    "trialId",
+    "sequence",
+    "timestamp",
+    "type",
+] as const;
+
+const GUARD_STATES: readonly GuardState[] = [
+    "pending",
+    "verifying",
+    "retry",
+    null,
+] as const;
+
+function isPlainObject(value: unknown): value is Record<string, unknown> {
+    return (
+        typeof value === "object" && value !== null && !Array.isArray(value)
+    );
+}
+
+function isNonEmptyString(value: unknown): value is string {
+    return typeof value === "string" && value.length > 0;
+}
+
+function isConstrainedString(
+    value: unknown,
+    maxLength: number,
+    pattern: RegExp,
+): value is string {
+    return (
+        typeof value === "string" &&
+        value.length <= maxLength &&
+        pattern.test(value)
+    );
+}
+
+function isEventType(value: unknown): value is TrialEventType {
+    return typeof value === "string" && value in ALLOWED_KEYS;
+}
+
+/**
+ * 1 イベントを厳密に検証する。shape が少しでも崩れていたら `null` を返す。
+ *
+ * `bfcache-guard.ts` の `readAuthenticatedFlag()` と同じ
+ * 「shape を厳密判定し、崩れていたら採用しない」idiom に揃えている。
+ */
+export function parseTrialEvent(value: unknown): TrialEvent | null {
+    if (!isPlainObject(value)) return null;
+    if (value.schemaVersion !== TRIAL_SCHEMA_VERSION) return null;
+    if (!isEventType(value.type)) return null;
+    if (!isNonEmptyString(value.trialId)) return null;
+    if (typeof value.sequence !== "number" || !Number.isInteger(value.sequence)) {
+        return null;
+    }
+    if (value.sequence < 0) return null;
+    if (!isNonEmptyString(value.timestamp)) return null;
+
+    const allowed = new Set<string>([...BASE_KEYS, ...ALLOWED_KEYS[value.type]]);
+    for (const key of Object.keys(value)) {
+        if (!allowed.has(key)) return null;
+    }
+    for (const key of ALLOWED_KEYS[value.type]) {
+        if (!(key in value)) return null;
+    }
+
+    if (!parsePayload(value.type, value)) return null;
+
+    return value as unknown as TrialEvent;
+}
+
+/** type 固有フィールドの型・制約を検証する。 */
+function parsePayload(
+    type: TrialEventType,
+    value: Record<string, unknown>,
+): boolean {
+    switch (type) {
+        case "trial-started":
+            return (
+                (value.scenario === "expired-session" ||
+                    value.scenario === "active-session") &&
+                isNonEmptyString(value.contextToken) &&
+                typeof value.userAgent === "string" &&
+                typeof value.uaReportedOs === "string" &&
+                typeof value.displayMode === "string" &&
+                (typeof value.navigatorStandalone === "boolean" ||
+                    value.navigatorStandalone === null) &&
+                isConstrainedString(
+                    value.deviceModel,
+                    DEVICE_MODEL_MAX_LENGTH,
+                    DEVICE_MODEL_PATTERN,
+                ) &&
+                isConstrainedString(
+                    value.verifiedOsVersion,
+                    VERIFIED_OS_VERSION_MAX_LENGTH,
+                    VERIFIED_OS_VERSION_PATTERN,
+                )
+            );
+        case "page-hide":
+            return (
+                typeof value.persisted === "boolean" &&
+                GUARD_STATES.includes(value.guardState as GuardState)
+            );
+        case "page-show":
+            return (
+                typeof value.persisted === "boolean" &&
+                isNonEmptyString(value.contextToken) &&
+                typeof value.displayMode === "string"
+            );
+        case "guard-state-changed":
+            return GUARD_STATES.includes(value.state as GuardState);
+        case "away-navigation-failed":
+        case "redirect-observed":
+            return value.observationMethod === "manual";
+        case "storage-failed":
+            return (
+                typeof value.reason === "string" &&
+                value.reason.length <= STORAGE_FAILURE_REASON_MAX_LENGTH
+            );
+        case "away-navigation-started":
+        case "trial-aborted":
+            return true;
+    }
+}
+
+/**
+ * 保存済みログ全体をパースする。
+ *
+ * **1 件でも壊れていたらログ全体を破棄する** (部分採用しない)。
+ * 欠落した証跡を完全な証跡と誤読させないため。
+ */
+export function parseTrialLog(raw: string | null): TrialEvent[] | null {
+    if (raw === null || raw === "") return null;
+
+    let decoded: unknown;
+    try {
+        decoded = JSON.parse(raw);
+    } catch {
+        return null;
+    }
+    if (!Array.isArray(decoded)) return null;
+
+    const events: TrialEvent[] = [];
+    for (const entry of decoded) {
+        const parsed = parseTrialEvent(entry);
+        if (parsed === null) return null;
+        events.push(parsed);
+    }
+    return events;
+}
+
+// ---------------------------------------------------------------------------
+// 採番 / 前提条件
+// ---------------------------------------------------------------------------
+
+/**
+ * 常に `max(sequence) + 1` を返す。sessionStorage から復元した進行中試行へ
+ * 追記する場合も採番が壊れない (欠番・重複があっても max を基準にする)。
+ * 空配列では 1 を返す (先頭イベントの sequence は 1)。
+ */
+export function nextSequence(events: TrialEvent[], trialId: string): number {
+    const target = events.filter((event) => event.trialId === trialId);
+    if (target.length === 0) return 1;
+    return Math.max(...target.map((event) => event.sequence)) + 1;
+}
+
+/** 3 導出関数の共通事前条件。イベントが 1 つの trialId だけに属するか。 */
+export function hasSingleTrialId(events: TrialEvent[]): boolean {
+    if (events.length === 0) return true;
+    const first = events[0].trialId;
+    return events.every((event) => event.trialId === first);
+}
+
+// ---------------------------------------------------------------------------
+// 軸 1: 試行成立判定
+// ---------------------------------------------------------------------------
+
+interface Axis1Window {
+    started: TrialStartedEvent;
+    away: AwayNavigationStartedEvent;
+    hide: PageHideEvent;
+    show: PageShowEvent;
+}
+
+function bySequence(events: TrialEvent[]): TrialEvent[] {
+    return [...events].sort((a, b) => a.sequence - b.sequence);
+}
+
+/**
+ * 軸 1 window を探す。
+ *
+ * 最初に成立した `trial-started < away-navigation-started < page-hide < page-show` を
+ * **軸 1 が参照する唯一の範囲**として確定させる。窓の外は軸 1 の判定に用いない
+ * (失効セッション経路では再ログイン後に必ず追加観測が発生するため、
+ * これが無いと判定が後から崩れる)。
+ */
+export function findAxis1Window(events: TrialEvent[]): Axis1Window | null {
+    const ordered = bySequence(events);
+
+    const started = ordered.find(
+        (event): event is TrialStartedEvent => event.type === "trial-started",
+    );
+    if (started === undefined) return null;
+
+    const away = ordered.find(
+        (event): event is AwayNavigationStartedEvent =>
+            event.type === "away-navigation-started" &&
+            event.sequence > started.sequence,
+    );
+    if (away === undefined) return null;
+
+    const hide = ordered.find(
+        (event): event is PageHideEvent =>
+            event.type === "page-hide" && event.sequence > away.sequence,
+    );
+    if (hide === undefined) return null;
+
+    const show = ordered.find(
+        (event): event is PageShowEvent =>
+            event.type === "page-show" && event.sequence > hide.sequence,
+    );
+    if (show === undefined) return null;
+
+    return { started, away, hide, show };
+}
+
+export function deriveTrialVerdict(events: TrialEvent[]): TrialVerdict {
+    if (!hasSingleTrialId(events)) return "inconsistent";
+
+    const window = findAxis1Window(events);
+    if (window !== null) {
+        const tokenMatches =
+            window.show.contextToken === window.started.contextToken;
+
+        if (window.hide.persisted && window.show.persisted && tokenMatches) {
+            return "valid-bfcache";
+        }
+        if (!window.show.persisted && !tokenMatches) {
+            return "invalid-not-bfcache";
+        }
+        return "inconsistent";
+    }
+
+    const hasHide = events.some((event) => event.type === "page-hide");
+    const hasShow = events.some((event) => event.type === "page-show");
+    // hide と show が揃っているのに窓を成せない = away 欠落 or 順序異常
+    if (hasHide && hasShow) return "inconsistent";
+
+    if (events.some((event) => event.type === "away-navigation-failed")) {
+        return "invalid-wrong-route";
+    }
+
+    return "incomplete";
+}
+
+// ---------------------------------------------------------------------------
+// 軸 2: guard 結果判定
+// ---------------------------------------------------------------------------
+
+/**
+ * 軸 2 は**軸 1 window の `page-show` より後**のイベントだけを見る。
+ * 往路 (A → B) の `page-hide` をリダイレクト離脱として拾ってはならない。
+ */
+export function deriveGuardVerdict(events: TrialEvent[]): GuardVerdict {
+    if (!hasSingleTrialId(events)) return "failed-transition";
+
+    const window = findAxis1Window(events);
+    const boundary = window?.show.sequence ?? Number.POSITIVE_INFINITY;
+    const after = bySequence(events).filter(
+        (event) => event.sequence > boundary,
+    );
+
+    const states = after
+        .filter(
+            (event): event is GuardStateChangedEvent =>
+                event.type === "guard-state-changed",
+        )
+        .map((event) => event.state);
+    const aborted = events.some((event) => event.type === "trial-aborted");
+
+    if (states.length === 0) return aborted ? "not-observed" : "in-progress";
+
+    // 正常遷移は pending → verifying → (null | retry)。prefix を異常扱いしない
+    if (states[0] !== "pending") return "failed-transition";
+    if (states.length === 1) return "in-progress";
+    if (states[1] !== "verifying") return "failed-transition";
+
+    if (states.length === 2) {
+        const hiddenThenLeft = after.some(
+            (event) => event.type === "page-hide",
+        );
+        if (!hiddenThenLeft) return "in-progress";
+        return events.some((event) => event.type === "redirect-observed")
+            ? "unauthenticated-redirected"
+            : "hidden-then-left";
+    }
+
+    if (states.length > 3) return "failed-transition";
+    if (states[2] === null) return "authenticated-unhidden";
+    if (states[2] === "retry") return "retry-hidden";
+    return "failed-transition";
+}
+
+// ---------------------------------------------------------------------------
+// 軸 3: 総合判定 / 進行状態
+// ---------------------------------------------------------------------------
+
+/** シナリオごとに期待される guard 結果。 */
+export function expectedGuardVerdict(scenario: TrialScenario): GuardVerdict {
+    return scenario === "expired-session"
+        ? "unauthenticated-redirected"
+        : "authenticated-unhidden";
+}
+
+/**
+ * 総合判定。**軸 1 と軸 2 から導出するだけで、保存しない**。
+ *
+ * `in-progress` / `not-observed` / `hidden-then-left` を `undetermined` に落とすのが要点。
+ * - `in-progress`: 観測途中。ここを fail にすると復元直後の正常な状態が FAIL 表示になる
+ * - `not-observed`: guard が発火しなかったのか利用者が早く中止したのか**区別できない**
+ * - `hidden-then-left`: `redirect-observed` が入るまで終端していない
+ */
+export function deriveOverallVerdict(
+    scenario: TrialScenario,
+    trial: TrialVerdict,
+    guard: GuardVerdict,
+): OverallVerdict {
+    if (trial !== "valid-bfcache") return "undetermined";
+    if (
+        guard === "in-progress" ||
+        guard === "not-observed" ||
+        guard === "hidden-then-left"
+    ) {
+        return "undetermined";
+    }
+    if (guard === expectedGuardVerdict(scenario)) return "pass";
+    if (guard === "failed-transition") return "fail";
+    return "expectation-mismatch";
+}
+
+/**
+ * 試行の進行状態。listener の追記可否をこの結果で決める。
+ *
+ * `in-progress` が `collecting-axis2` に写ることが要点である。これが無いと
+ * 正常な `pending` / `pending → verifying` の途中で `complete` に落ちて
+ * 自動追記が止まり、`null` / `retry` / 復元後 `page-hide` を記録できなくなる。
+ */
+export function deriveTrialPhase(events: TrialEvent[]): TrialPhase {
+    if (!hasSingleTrialId(events)) return "invalid";
+    if (events.some((event) => event.type === "trial-aborted")) return "aborted";
+
+    const trial = deriveTrialVerdict(events);
+    if (trial === "incomplete") return "collecting-axis1";
+    if (trial !== "valid-bfcache") return "complete";
+
+    const guard = deriveGuardVerdict(events);
+    if (guard === "in-progress") return "collecting-axis2";
+    if (guard === "hidden-then-left") return "awaiting-manual-confirmation";
+    return "complete";
+}
+
+/** phase ごとに追記を許可するイベント種別。 */
+const ALLOWED_APPENDS: Record<TrialPhase, readonly TrialEventType[]> = {
+    invalid: [],
+    "collecting-axis1": [
+        "away-navigation-started",
+        "away-navigation-failed",
+        "page-hide",
+        "page-show",
+        "guard-state-changed",
+        "storage-failed",
+        "trial-aborted",
+    ],
+    "collecting-axis2": [
+        "page-hide",
+        "page-show",
+        "guard-state-changed",
+        "storage-failed",
+        "trial-aborted",
+    ],
+    "awaiting-manual-confirmation": ["redirect-observed", "trial-aborted"],
+    complete: [],
+    aborted: [],
+} as const;
+
+/**
+ * その phase でそのイベントを追記してよいか。
+ *
+ * `awaiting-manual-confirmation` で自動イベントを止めることが、
+ * **再ログイン後の fresh load による証跡汚染を防ぐ実装上の要**である。
+ */
+export function canAppend(phase: TrialPhase, type: TrialEventType): boolean {
+    return ALLOWED_APPENDS[phase].includes(type);
+}
+
+// ---------------------------------------------------------------------------
+// storage
+// ---------------------------------------------------------------------------
+
+function storage(): Storage | null {
+    try {
+        return globalThis.sessionStorage;
+    } catch {
+        return null;
+    }
+}
+
+/** 試行開始前の保存テスト。書けない環境では試行を始めさせない。 */
+export function probeStorageWritable(): boolean {
+    const store = storage();
+    if (store === null) return false;
+
+    const probeKey = `${TRIAL_STORAGE_KEY}:probe`;
+    try {
+        store.setItem(probeKey, "1");
+        const readBack = store.getItem(probeKey);
+        store.removeItem(probeKey);
+        return readBack === "1";
+    } catch {
+        return false;
+    }
+}
+
+/** 保存済みログを読む。壊れていたら `null` (呼び出し側が破棄を表示する)。 */
+export function readTrialLog(): TrialEvent[] | null {
+    const store = storage();
+    if (store === null) return null;
+    try {
+        return parseTrialLog(store.getItem(TRIAL_STORAGE_KEY));
+    } catch {
+        return null;
+    }
+}
+
+/**
+ * イベントを追記する。**例外を投げず、失敗を `false` で返す**
+ * (黙って成功扱いにしない)。
+ *
+ * 書き込み後に read-back validation を行い、追記したイベントが末尾に
+ * 同値で存在することまで確認する (JSON parse 成功だけでは破損を見逃す)。
+ */
+export function appendEvent(event: TrialEvent): boolean {
+    const store = storage();
+    if (store === null) return false;
+
+    try {
+        const existing = parseTrialLog(store.getItem(TRIAL_STORAGE_KEY)) ?? [];
+        const next = [...existing, event];
+        store.setItem(TRIAL_STORAGE_KEY, JSON.stringify(next));
+
+        const readBack = parseTrialLog(store.getItem(TRIAL_STORAGE_KEY));
+        if (readBack === null) return false;
+        if (readBack.length !== next.length) return false;
+
+        const tail = readBack[readBack.length - 1];
+        return JSON.stringify(tail) === JSON.stringify(event);
+    } catch {
+        return false;
+    }
+}
+
+/** 保存済みイベントを trialId ごとに分離して返す (混ぜて誤判定させない)。 */
+export function loadTrials(): Map<string, TrialEvent[]> {
+    return groupByTrialId(readTrialLog() ?? []);
+}
+
+/** イベント列を trialId ごとに分離する (純粋関数。テスト用に公開する)。 */
+export function groupByTrialId(
+    events: TrialEvent[],
+): Map<string, TrialEvent[]> {
+    const grouped = new Map<string, TrialEvent[]>();
+    for (const event of bySequence(events)) {
+        const bucket = grouped.get(event.trialId);
+        if (bucket === undefined) {
+            grouped.set(event.trialId, [event]);
+            continue;
+        }
+        bucket.push(event);
+    }
+    return grouped;
+}
diff --git a/resources/js/pages/Debug/BfcacheTrial.svelte b/resources/js/pages/Debug/BfcacheTrial.svelte
new file mode 100644
index 0000000..b7290de
--- /dev/null
+++ b/resources/js/pages/Debug/BfcacheTrial.svelte
@@ -0,0 +1,689 @@
+<script lang="ts">
+    import { onMount } from "svelte";
+    import { page } from "@inertiajs/svelte";
+    import { ShieldQuestion } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import Select from "@/components/atoms/Select.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import { BFCACHE_HIDDEN_ATTRIBUTE } from "@/lib/bfcache-guard";
+    import {
+        DEVICE_MODEL_MAX_LENGTH,
+        TRIAL_SCHEMA_VERSION,
+        VERIFIED_OS_VERSION_MAX_LENGTH,
+        appendEvent,
+        canAppend,
+        deriveGuardVerdict,
+        deriveOverallVerdict,
+        deriveTrialPhase,
+        deriveTrialVerdict,
+        expectedGuardVerdict,
+        isValidDeviceModel,
+        isValidVerifiedOsVersion,
+        loadTrials,
+        nextSequence,
+        normalizeUserReported,
+        probeStorageWritable,
+        readTrialLog,
+        type GuardState,
+        type TrialEvent,
+        type TrialEventType,
+        type TrialPhase,
+        type TrialScenario,
+    } from "@/lib/debug/bfcache-trial";
+    import type { SharedProps } from "@/lib/shared-props";
+
+    /**
+     * bfcache 実機受入確認 (T085) の検証ページ A。local / debug 限定。
+     *
+     * **なぜ要るのか**: T085 の実機手順は素の目視確認であり、「guard が働いた」と
+     * 「そもそも bfcache 復元が起きなかった」を区別できない。どちらも「PII が出ない」に
+     * 見えるため、空振りを合格として記録しうる。同じ欠陥は Playwright レーンについては
+     * 潰されている (「空振りを green と偽らない」)。その規律を実機レーンへ揃える。
+     *
+     * **観測するだけで、検証対象は一切変更しない**。guard は app.ts が登録した本物が
+     * そのまま動く。ここでは documentElement の秘匿属性を MutationObserver で見るだけ。
+     *
+     * 判定は二軸 + 総合 (詳細は lib/debug/bfcache-trial.ts):
+     *   軸 1 = bfcache 復元が本当に起きたか / 軸 2 = guard がどう振る舞ったか
+     * 混ぜると受入失敗を PASS と読むので分けてある。
+     *
+     * **軸 2 の unauthenticated-redirected だけは自動判定できない。** guard は
+     * location.replace('/login') を呼ぶだけで、A からは離脱先が観測できないため、
+     * 利用者の手動記録 (manual confirmation) を必須にしている。
+     */
+
+    const shared = $derived(page.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+
+    /**
+     * JS 実行コンテキスト生存トークン。**onMount で 1 回だけ生成する**。
+     * bfcache 復元では component が再生成されないため onMount は再実行されず値が残る =
+     * 「Document が再実行されていない」ことの証拠になる。fresh load でのみ変わる。
+     * (module scope で評価すると SSR / テスト import 時に壊れる)
+     */
+    let contextToken = $state<string | null>(null);
+    let secureContextReady = $state(true);
+    let storageWritable = $state(true);
+    let logDiscarded = $state(false);
+
+    let events = $state<TrialEvent[]>([]);
+    let notice = $state<string | null>(null);
+
+    let scenario = $state<TrialScenario>("expired-session");
+    let deviceModel = $state("");
+    let verifiedOsVersion = $state("");
+
+    /** 進行中の試行 (phase が終端していないもの)。無ければ stored report モード。 */
+    const activeTrialId = $derived.by(() => {
+        let candidate: string | null = null;
+        let bestSequence = -1;
+        for (const [trialId, trialEvents] of groupEvents(events)) {
+            const phase = deriveTrialPhase(trialEvents);
+            if (phase === "complete" || phase === "aborted" || phase === "invalid") {
+                continue;
+            }
+            const first = Math.min(...trialEvents.map((event) => event.sequence));
+            if (first > bestSequence) {
+                bestSequence = first;
+                candidate = trialId;
+            }
+        }
+        return candidate;
+    });
+
+    const mode = $derived(activeTrialId === null ? "stored report" : "live observation");
+    const trials = $derived([...groupEvents(events)].reverse());
+
+    /**
+     * trialId ごとに分離する。**Map を返さない** — reactive な文脈で組み込み Map を
+     * 持つと svelte/prefer-svelte-reactivity に触れる。順序が要るだけなので tuple 配列で足りる。
+     */
+    function groupEvents(all: TrialEvent[]): Array<[string, TrialEvent[]]> {
+        const grouped: Array<[string, TrialEvent[]]> = [];
+        for (const event of [...all].sort((a, b) => a.sequence - b.sequence)) {
+            const bucket = grouped.find(([trialId]) => trialId === event.trialId);
+            if (bucket === undefined) {
+                grouped.push([event.trialId, [event]]);
+                continue;
+            }
+            bucket[1].push(event);
+        }
+        return grouped;
+    }
+
+    function refresh(): void {
+        const stored = readTrialLog();
+        logDiscarded = stored === null && hasStoredPayload();
+        events = [...loadTrials().values()].flat();
+    }
+
+    function hasStoredPayload(): boolean {
+        try {
+            return globalThis.sessionStorage.length > 0;
+        } catch {
+            return false;
+        }
+    }
+
+    function displayMode(): string {
+        if (typeof globalThis.matchMedia !== "function") return "unknown";
+        for (const candidate of ["standalone", "fullscreen", "minimal-ui", "browser"]) {
+            if (globalThis.matchMedia(`(display-mode: ${candidate})`).matches) {
+                return candidate;
+            }
+        }
+        return "unknown";
+    }
+
+    /** iOS Safari の非標準 API。any に逃がさず型を切る。 */
+    interface NavigatorWithStandalone extends Navigator {
+        standalone?: boolean;
+    }
+
+    function navigatorStandalone(): boolean | null {
+        const value = (navigator as NavigatorWithStandalone).standalone;
+        return typeof value === "boolean" ? value : null;
+    }
+
+    /**
+     * UA から読み取れる OS。**確定した OS バージョンとして扱わない**
+     * (UA reduction / iPadOS の desktop-class UA / standalone と Safari の差がある)。
+     * 確定値は利用者申告の verifiedOsVersion 側が持つ。
+     */
+    function uaReportedOs(): string {
+        const match = navigator.userAgent.match(
+            /(iPhone OS|CPU OS|Mac OS X|Android)\s+([0-9_.]+)/,
+        );
+        return match === null ? "unknown" : `${match[1]} ${match[2].replace(/_/g, ".")}`;
+    }
+
+    /** 現在の試行に 1 イベント追記する。phase で許可されない場合は理由を表示する。 */
+    function record(
+        trialId: string,
+        build: (base: {
+            schemaVersion: number;
+            trialId: string;
+            sequence: number;
+            timestamp: string;
+        }) => TrialEvent,
+        type: TrialEventType,
+        options: { silent?: boolean } = {},
+    ): void {
+        const stored = readTrialLog() ?? [];
+        const trialEvents = stored.filter((event) => event.trialId === trialId);
+        const phase = deriveTrialPhase(trialEvents);
+
+        if (!canAppend(phase, type)) {
+            if (options.silent !== true) {
+                notice = `この試行では「${type}」を記録できません (状態: ${phaseLabel(phase)})。`;
+            }
+            return;
+        }
+
+        const event = build({
+            schemaVersion: TRIAL_SCHEMA_VERSION,
+            trialId,
+            sequence: nextSequence(stored, trialId),
+            timestamp: new Date().toISOString(),
+        });
+
+        if (!appendEvent(event)) {
+            notice = "証跡の保存に失敗しました。この試行は証跡を回収できません (unrecordable)。";
+        }
+        refresh();
+    }
+
+    function startTrial(): void {
+        notice = null;
+
+        if (!secureContextReady) {
+            notice = "secure context が必要です。この環境では検証できません。";
+            return;
+        }
+
+        const model = normalizeUserReported(deviceModel);
+        const version = normalizeUserReported(verifiedOsVersion);
+
+        if (model === "" || !isValidDeviceModel(model)) {
+            notice = `端末モデルを英数字と - , ( ) . の範囲・${DEVICE_MODEL_MAX_LENGTH} 文字以内で入力してください。`;
+            return;
+        }
+        if (version === "" || !isValidVerifiedOsVersion(version)) {
+            notice = `OS バージョンを英数字と . の範囲・${VERIFIED_OS_VERSION_MAX_LENGTH} 文字以内で入力してください。`;
+            return;
+        }
+        if (activeTrialId !== null) {
+            notice = "進行中の試行があります。中止してから新しい試行を開始してください。";
+            return;
+        }
+        if (!probeStorageWritable()) {
+            storageWritable = false;
+            notice =
+                "sessionStorage に書き込めません (unrecordable)。この状態では試行を開始しません。";
+            return;
+        }
+
+        const token = contextToken;
+        if (token === null) return;
+
+        const stored = readTrialLog() ?? [];
+        const trialId = globalThis.crypto.randomUUID();
+        const event: TrialEvent = {
+            schemaVersion: TRIAL_SCHEMA_VERSION,
+            trialId,
+            sequence: nextSequence(stored, trialId),
+            timestamp: new Date().toISOString(),
+            type: "trial-started",
+            scenario,
+            contextToken: token,
+            userAgent: navigator.userAgent,
+            uaReportedOs: uaReportedOs(),
+            displayMode: displayMode(),
+            navigatorStandalone: navigatorStandalone(),
+            deviceModel: model,
+            verifiedOsVersion: version,
+        };
+
+        if (!appendEvent(event)) {
+            notice = "証跡の保存に失敗しました (unrecordable)。試行を開始しません。";
+            return;
+        }
+        refresh();
+    }
+
+    function leaveToAway(event: MouseEvent): void {
+        const trialId = activeTrialId;
+        if (trialId === null) {
+            event.preventDefault();
+            notice = "進行中の試行がありません。先に試行を開始してください。";
+            return;
+        }
+        // 操作事実のみを同期記録する。page-hide の不在から離脱失敗を推論しない
+        record(trialId, (base) => ({ ...base, type: "away-navigation-started" }), "away-navigation-started");
+    }
+
+    function recordManual(type: "redirect-observed" | "away-navigation-failed"): void {
+        notice = null;
+        const trialId = activeTrialId;
+        if (trialId === null) {
+            notice = "進行中の試行がありません。";
+            return;
+        }
+        record(trialId, (base) => ({ ...base, type, observationMethod: "manual" }), type);
+    }
+
+    function abortTrial(): void {
+        notice = null;
+        const trialId = activeTrialId;
+        if (trialId === null) {
+            notice = "進行中の試行がありません。";
+            return;
+        }
+        record(trialId, (base) => ({ ...base, type: "trial-aborted" }), "trial-aborted");
+    }
+
+    function copyReport(): void {
+        notice = null;
+        void navigator.clipboard
+            .writeText(reportText())
+            .then(() => {
+                notice = "証跡テキストをコピーしました。";
+            })
+            .catch(() => {
+                notice = "クリップボードにコピーできませんでした。";
+            });
+    }
+
+    function reportText(): string {
+        const lines: string[] = [`# bfcache 実機受入確認の証跡 (${mode})`, ""];
+        for (const [trialId, trialEvents] of trials) {
+            const started = trialEvents.find((event) => event.type === "trial-started");
+            if (started === undefined || started.type !== "trial-started") continue;
+            lines.push(`## trial ${trialId}`);
+            lines.push(`- シナリオ: ${scenarioLabel(started.scenario)}`);
+            lines.push(`- 自動観測 UA: ${started.userAgent}`);
+            lines.push(`- 自動観測 UA reported OS: ${started.uaReportedOs}`);
+            lines.push(`- 自動観測 display-mode: ${started.displayMode}`);
+            lines.push(`- 自動観測 navigator.standalone: ${started.navigatorStandalone}`);
+            lines.push(`- 利用者申告 端末モデル: ${started.deviceModel}`);
+            lines.push(`- 利用者申告 OS バージョン: ${started.verifiedOsVersion}`);
+            lines.push(`- 軸1 試行成立: ${deriveTrialVerdict(trialEvents)}`);
+            lines.push(`- 軸2 guard 結果: ${deriveGuardVerdict(trialEvents)}`);
+            lines.push(
+                `- 総合: ${deriveOverallVerdict(started.scenario, deriveTrialVerdict(trialEvents), deriveGuardVerdict(trialEvents))}`,
+            );
+            lines.push(`- 期待 guard 結果: ${expectedGuardVerdict(started.scenario)}`);
+            lines.push("- イベント:");
+            for (const event of trialEvents) {
+                lines.push(`  - [${event.sequence}] ${event.timestamp} ${event.type}`);
+            }
+            lines.push("");
+        }
+        return lines.join("\n");
+    }
+
+    function phaseLabel(phase: TrialPhase): string {
+        const labels: Record<TrialPhase, string> = {
+            invalid: "不正 (複数試行の混入)",
+            "collecting-axis1": "軸1 観測中",
+            "collecting-axis2": "軸2 観測中",
+            "awaiting-manual-confirmation": "手動確認待ち",
+            complete: "完了",
+            aborted: "中止",
+        };
+        return labels[phase];
+    }
+
+    function scenarioLabel(value: TrialScenario): string {
+        return value === "expired-session"
+            ? "失効セッション経路 (本試行)"
+            : "有効セッション経路 (正のコントロール)";
+    }
+
+    function guardStateOf(): GuardState {
+        const value = document.documentElement.getAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
+        if (value === "pending" || value === "verifying" || value === "retry") return value;
+        return null;
+    }
+
+    onMount(() => {
+        if (typeof globalThis.crypto?.randomUUID !== "function") {
+            secureContextReady = false;
+            return;
+        }
+        contextToken = globalThis.crypto.randomUUID();
+        storageWritable = probeStorageWritable();
+        refresh();
+
+        const onPageHide = (event: Event): void => {
+            const trialId = activeTrialId;
+            if (trialId === null) return;
+            record(
+                trialId,
+                (base) => ({
+                    ...base,
+                    type: "page-hide",
+                    persisted: (event as PageTransitionEvent).persisted,
+                    guardState: guardStateOf(),
+                }),
+                "page-hide",
+                { silent: true },
+            );
+        };
+
+        const onPageShow = (event: Event): void => {
+            const trialId = activeTrialId;
+            const token = contextToken;
+            if (trialId === null || token === null) return;
+            record(
+                trialId,
+                (base) => ({
+                    ...base,
+                    type: "page-show",
+                    persisted: (event as PageTransitionEvent).persisted,
+                    contextToken: token,
+                    displayMode: displayMode(),
+                }),
+                "page-show",
+                { silent: true },
+            );
+        };
+
+        // 秘匿属性の変化を外から観測する (guard には手を入れない)
+        const observer = new MutationObserver(() => {
+            const trialId = activeTrialId;
+            if (trialId === null) return;
+            record(
+                trialId,
+                (base) => ({ ...base, type: "guard-state-changed", state: guardStateOf() }),
+                "guard-state-changed",
+                { silent: true },
+            );
+        });
+        observer.observe(document.documentElement, {
+            attributes: true,
+            attributeFilter: [BFCACHE_HIDDEN_ATTRIBUTE],
+        });
+
+        // unload / beforeunload は使わない (bfcache の適格性を壊す。architecture テストが固定)
+        window.addEventListener("pagehide", onPageHide);
+        window.addEventListener("pageshow", onPageShow);
+
+        return () => {
+            observer.disconnect();
+            window.removeEventListener("pagehide", onPageHide);
+            window.removeEventListener("pageshow", onPageShow);
+        };
+    });
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader
+            title="bfcache 実機受入確認"
+            description="T085 の実機確認を空振りと区別するための観測ページ (local / debug 限定)"
+            icon={ShieldQuestion}
+        />
+        <PageContent>
+            {#if !secureContextReady}
+                <Alert variant="danger" testId="bfcache-trial-insecure">
+                    secure context が必要です。この環境では検証できません。HTTPS で開き直してください
+                    (平文 http で試すと本番と違う条件を見て「確認済み」と記録する事故になります)。
+                </Alert>
+            {:else}
+                <div class="space-y-6">
+                    <Alert variant="info" testId="bfcache-trial-mode">
+                        現在のモード: <strong>{mode}</strong>
+                        {#if activeTrialId !== null}
+                            / 進行中の試行: <code>{activeTrialId.slice(0, 8)}</code>
+                        {/if}
+                    </Alert>
+
+                    {#if !storageWritable}
+                        <Alert variant="danger" testId="bfcache-trial-unrecordable">
+                            sessionStorage に書き込めません (unrecordable)。証跡を回収できないため
+                            試行を開始しません。
+                        </Alert>
+                    {/if}
+
+                    {#if logDiscarded}
+                        <Alert variant="warning" testId="bfcache-trial-log-discarded">
+                            保存済み証跡の形式が壊れていたため破棄しました (部分採用はしません)。
+                        </Alert>
+                    {/if}
+
+                    {#if notice !== null}
+                        <Alert variant="warning" testId="bfcache-trial-notice">{notice}</Alert>
+                    {/if}
+
+                    <Card padding="lg">
+                        <h2 class="text-h2">新しい試行を開始する</h2>
+                        <p class="mt-2 text-caption text-text-secondary">
+                            端末モデルと OS バージョンは UA から確定できないため手入力します
+                            (UA reduction / iPadOS の desktop-class UA があるため)。
+                            <strong>氏名などの個人情報は入力しないでください。</strong>
+                        </p>
+
+                        <div class="mt-4 space-y-4">
+                            <FormField label="検証シナリオ" htmlFor="bfcache-trial-scenario">
+                                <Select id="bfcache-trial-scenario" bind:value={scenario}>
+                                    <option value="expired-session"
+                                        >失効セッション経路 (本試行)</option
+                                    >
+                                    <option value="active-session"
+                                        >有効セッション経路 (正のコントロール)</option
+                                    >
+                                </Select>
+                            </FormField>
+
+                            <FormField label="端末モデル (利用者申告)" htmlFor="bfcache-trial-device">
+                                <Input
+                                    id="bfcache-trial-device"
+                                    bind:value={deviceModel}
+                                    placeholder="iPhone 15 Pro"
+                                    testId="bfcache-trial-device"
+                                />
+                            </FormField>
+
+                            <FormField
+                                label="確認済み OS バージョン (利用者申告)"
+                                htmlFor="bfcache-trial-os"
+                            >
+                                <Input
+                                    id="bfcache-trial-os"
+                                    bind:value={verifiedOsVersion}
+                                    placeholder="18.2"
+                                    testId="bfcache-trial-os"
+                                />
+                            </FormField>
+
+                            <Button onclick={startTrial} testId="bfcache-trial-start">
+                                試行を開始する
+                            </Button>
+                        </div>
+                    </Card>
+
+                    <Card padding="lg">
+                        <h2 class="text-h2">操作</h2>
+                        <p class="mt-2 text-caption text-text-secondary">
+                            下のリンクは <strong>plain な a 要素</strong>です (Inertia の Link
+                            ではありません)。full document navigation でないと A が bfcache に入らないためです。
+                        </p>
+                        <p class="mt-2 text-caption text-text-secondary">
+                            戻るときは<strong>履歴から A を選んで復帰</strong>してください。
+                            相方ページでログアウトすると Inertia が履歴を積むため、戻る 1 回では A に戻りません。
+                        </p>
+
+                        <div class="mt-4 flex flex-wrap gap-3">
+                            <a
+                                href="/debug/bfcache-trial/away"
+                                class="text-body text-primary underline"
+                                data-testid="bfcache-trial-away-link"
+                                onclick={leaveToAway}
+                            >
+                                相方ページへ移動する (full reload)
+                            </a>
+                        </div>
+
+                        <div class="mt-4 flex flex-wrap gap-3">
+                            <Button
+                                variant="ghost"
+                                onclick={() => recordManual("redirect-observed")}
+                                testId="bfcache-trial-record-redirect"
+                            >
+                                /login 到達を記録する (手動確認)
+                            </Button>
+                            <Button
+                                variant="ghost"
+                                onclick={() => recordManual("away-navigation-failed")}
+                                testId="bfcache-trial-record-away-failed"
+                            >
+                                離脱失敗を記録する (手動確認)
+                            </Button>
+                            <Button
+                                variant="ghost"
+                                onclick={abortTrial}
+                                testId="bfcache-trial-abort"
+                            >
+                                試行を中止する
+                            </Button>
+                            <Button
+                                variant="neutral"
+                                onclick={copyReport}
+                                testId="bfcache-trial-copy"
+                            >
+                                証跡テキストをコピー
+                            </Button>
+                        </div>
+                    </Card>
+
+                    <!--
+                        オーバーレイが覆う対象。明らかに偽物と分かる固定文字列にしてある
+                        (証跡を devnotes に貼るため、本物めいた個人情報を写り込ませない)。
+                        この文字列自体は sessionStorage に保存しない (allowlist 外)。
+                    -->
+                    <Card padding="lg" testId="bfcache-trial-fake-pii">
+                        <h2 class="text-h2">ダミー PII (架空データ)</h2>
+                        <dl class="mt-3 space-y-1 text-body">
+                            <div><dt class="inline">氏名:</dt> <dd class="inline">架空 太郎</dd></div>
+                            <div>
+                                <dt class="inline">メール:</dt>
+                                <dd class="inline">example-not-real@invalid.test</dd>
+                            </div>
+                            <div><dt class="inline">電話:</dt> <dd class="inline">000-0000-0000</dd></div>
+                        </dl>
+                    </Card>
+
+                    {#each trials as [trialId, trialEvents] (trialId)}
+                        {@const started = trialEvents.find((e) => e.type === "trial-started")}
+                        {#if started !== undefined && started.type === "trial-started"}
+                            {@const trialVerdict = deriveTrialVerdict(trialEvents)}
+                            {@const guardVerdict = deriveGuardVerdict(trialEvents)}
+                            <Card padding="lg">
+                                <h2 class="text-h2">
+                                    trial <code>{trialId.slice(0, 8)}</code>
+                                    <span class="text-caption text-text-secondary">
+                                        ({trialId === activeTrialId
+                                            ? "live observation"
+                                            : "stored report"})
+                                    </span>
+                                </h2>
+
+                                <dl class="mt-3 grid gap-1 text-body">
+                                    <div>
+                                        <dt class="inline">シナリオ:</dt>
+                                        <dd class="inline">{scenarioLabel(started.scenario)}</dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">状態:</dt>
+                                        <dd class="inline">
+                                            {phaseLabel(deriveTrialPhase(trialEvents))}
+                                        </dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">軸1 試行成立:</dt>
+                                        <dd class="inline" data-testid="bfcache-trial-verdict">
+                                            {trialVerdict}
+                                        </dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">軸2 guard 結果:</dt>
+                                        <dd class="inline" data-testid="bfcache-guard-verdict">
+                                            {guardVerdict}
+                                        </dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">総合:</dt>
+                                        <dd class="inline" data-testid="bfcache-overall-verdict">
+                                            {deriveOverallVerdict(
+                                                started.scenario,
+                                                trialVerdict,
+                                                guardVerdict,
+                                            )}
+                                        </dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">期待 guard 結果:</dt>
+                                        <dd class="inline">
+                                            {expectedGuardVerdict(started.scenario)}
+                                        </dd>
+                                    </div>
+                                </dl>
+
+                                {#if guardVerdict === "unauthenticated-redirected"}
+                                    <p class="mt-3 text-caption text-text-secondary">
+                                        この判定は <strong>manual confirmation</strong> を含みます
+                                        (guard の離脱先は A から観測できないため、/login 到達は利用者の確認記録によります)。
+                                    </p>
+                                {/if}
+                                {#if guardVerdict === "hidden-then-left"}
+                                    <p class="mt-3 text-caption text-text-secondary">
+                                        秘匿を維持したまま A から離脱しました。<strong
+                                            >/login に着地したことを確認して記録</strong
+                                        >すると判定が確定します。
+                                    </p>
+                                {/if}
+
+                                <h3 class="mt-4 text-h3">自動観測</h3>
+                                <ul class="mt-1 text-caption text-text-secondary">
+                                    <li>UA: {started.userAgent}</li>
+                                    <li>UA reported OS: {started.uaReportedOs}</li>
+                                    <li>display-mode: {started.displayMode}</li>
+                                    <li>navigator.standalone: {String(started.navigatorStandalone)}</li>
+                                </ul>
+
+                                <h3 class="mt-4 text-h3">利用者申告</h3>
+                                <ul class="mt-1 text-caption text-text-secondary">
+                                    <li>端末モデル: {started.deviceModel}</li>
+                                    <li>確認済み OS バージョン: {started.verifiedOsVersion}</li>
+                                </ul>
+
+                                <h3 class="mt-4 text-h3">観測イベント</h3>
+                                <ol class="mt-1 space-y-1 text-caption text-text-secondary">
+                                    {#each trialEvents as event (event.sequence)}
+                                        <li>
+                                            [{event.sequence}] {event.timestamp} — {event.type}
+                                            {#if event.type === "page-hide" || event.type === "page-show"}
+                                                (persisted: {String(event.persisted)})
+                                            {/if}
+                                            {#if event.type === "guard-state-changed"}
+                                                (state: {String(event.state)})
+                                            {/if}
+                                        </li>
+                                    {/each}
+                                </ol>
+                            </Card>
+                        {/if}
+                    {/each}
+                </div>
+            {/if}
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/resources/js/pages/Debug/BfcacheTrialAway.svelte b/resources/js/pages/Debug/BfcacheTrialAway.svelte
new file mode 100644
index 0000000..5f8c892
--- /dev/null
+++ b/resources/js/pages/Debug/BfcacheTrialAway.svelte
@@ -0,0 +1,134 @@
+<script lang="ts">
+    import { onMount } from "svelte";
+    import { page } from "@inertiajs/svelte";
+    import { SignpostBig } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import {
+        deriveTrialPhase,
+        loadTrials,
+        type TrialScenario,
+    } from "@/lib/debug/bfcache-trial";
+    import type { SharedProps } from "@/lib/shared-props";
+
+    /**
+     * bfcache 実機受入確認 (T085) の相方ページ B。local / debug 限定。
+     *
+     * 責務は 2 つだけである (意図的に薄い):
+     *   1. A から **full document navigation** で離脱した先として存在すること
+     *      (これで A が bfcache に入る。Inertia visit では同一 Document のままで
+     *       pagehide が起きず、経路 C になってしまう)
+     *   2. 次に何をすべきかを画面に書くこと
+     *
+     * **logout 導線を新設しない。** AppLayout に元からあるユーザーメニューの logout
+     * (tests/js/architecture/logout-call-site-inventory.test.ts に登録済みの既存 call site) を
+     * そのまま使う。JSON 204 で完結する logout を足すと Inertia の履歴鍵が消えず、
+     * 経路 C の保証が壊れる。
+     *
+     * **B では観測しない。** 判定対象は A の lifecycle だけである。
+     */
+
+    const shared = $derived(page.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+
+    let activeTrialId = $state<string | null>(null);
+    let activeScenario = $state<TrialScenario | null>(null);
+
+    onMount(() => {
+        for (const [trialId, events] of loadTrials()) {
+            const phase = deriveTrialPhase(events);
+            if (phase === "complete" || phase === "aborted" || phase === "invalid") {
+                continue;
+            }
+            const started = events.find((event) => event.type === "trial-started");
+            if (started === undefined || started.type !== "trial-started") continue;
+            activeTrialId = trialId;
+            activeScenario = started.scenario;
+        }
+    });
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader
+            title="bfcache 検証: 相方ページ"
+            description="ここに来た時点で検証ページ A が bfcache に入ります (local / debug 限定)"
+            icon={SignpostBig}
+        />
+        <PageContent>
+            <div class="space-y-6">
+                {#if activeTrialId === null}
+                    <Alert variant="warning" testId="bfcache-away-no-trial">
+                        進行中の試行が見つかりません。検証ページ A で試行を開始してから、
+                        A のリンク経由でこのページへ来てください。
+                    </Alert>
+                {:else}
+                    <Alert variant="info" testId="bfcache-away-trial">
+                        進行中の試行: <code>{activeTrialId.slice(0, 8)}</code>
+                        {#if activeScenario !== null}
+                            / シナリオ:
+                            {activeScenario === "expired-session"
+                                ? "失効セッション経路 (本試行)"
+                                : "有効セッション経路 (正のコントロール)"}
+                        {/if}
+                    </Alert>
+                {/if}
+
+                <Card padding="lg">
+                    <h2 class="text-h2">次の操作</h2>
+
+                    {#if activeScenario === "active-session"}
+                        <p class="mt-2 text-body">
+                            <strong>ログアウトしません。</strong>このまま
+                            <strong>履歴から検証ページ A を選んで復帰</strong>してください。
+                        </p>
+                        <p class="mt-2 text-caption text-text-secondary">
+                            期待: guard が秘匿 → 検証 → 秘匿解除まで進み、DOM とフォーム状態が温存されること
+                            (撮影導線を壊していないことの確認)。
+                        </p>
+                    {:else}
+                        <ol class="mt-2 list-decimal space-y-2 pl-5 text-body">
+                            <li>
+                                左のサイドバー下部の<strong>ユーザーメニューからログアウト</strong>する
+                                (このページ独自のログアウトボタンは用意していません)
+                            </li>
+                            <li><strong>履歴から検証ページ A を選んで復帰</strong>する</li>
+                            <li>guard が /login へ倒したら、<strong>その画面を撮影</strong>する (証跡 1 枚目)</li>
+                            <li><code>/debug/login</code> で入り直す</li>
+                            <li>A を開き、<strong>「/login 到達を記録する」</strong>を押す</li>
+                            <li>A の stored report を撮影する (証跡 2 枚目)</li>
+                        </ol>
+                    {/if}
+
+                    <Alert variant="warning" class="mt-4" testId="bfcache-away-back-notice">
+                        <strong>戻る 1 回では A に戻りません。</strong>
+                        ログアウトは Inertia visit なので履歴が積まれます。履歴一覧から A を選んでください。
+                    </Alert>
+                </Card>
+
+                <Card padding="lg">
+                    <h2 class="text-h2">検証ページ A へのリンク</h2>
+                    <p class="mt-2 text-caption text-text-secondary">
+                        <strong>これは復帰手段ではありません。</strong>
+                        クリックすると新しい履歴エントリになり、bfcache 復元になりません
+                        (軸 1 が invalid-not-bfcache として機械的に検出します)。
+                        試行をやり直すときだけ使ってください。
+                    </p>
+                    <p class="mt-3">
+                        <a
+                            href="/debug/bfcache-trial"
+                            class="text-body text-primary underline"
+                            data-testid="bfcache-away-restart-link"
+                        >
+                            検証ページ A を開き直す (試行のやり直し用)
+                        </a>
+                    </p>
+                </Card>
+            </div>
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/routes/web.php b/routes/web.php
index a267210..ebd567a 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -13,6 +13,7 @@
 use App\Http\Controllers\Capture\TakeUploadUrlController;
 use App\Http\Controllers\ContactController;
 use App\Http\Controllers\DashboardController;
+use App\Http\Controllers\DebugBfcacheTrialController;
 use App\Http\Controllers\DebugLoginController;
 use App\Http\Controllers\HomeController;
 use App\Http\Controllers\Marketing\PricingController;
@@ -677,5 +678,23 @@
     Route::middleware(LocalOnly::class)->group(function (): void {
         Route::get('/debug/login', [DebugLoginController::class, 'index'])->name('debug.login');
         Route::post('/debug/login/{userId}', [DebugLoginController::class, 'loginAs'])->name('debug.login-as');
+
+        /*
+        | bfcache 実機受入確認 (T085) の検証ページ。
+        |
+        | auth 配下に置くのは必須である。web グループの
+        | NoStoreCacheHeadersForAuthenticatedPages が乗って **no-store が実際に付いた状態**に
+        | ならないと、「Safari は no-store でも bfcache に格納する」の検証にならない
+        | (本番と違う条件を見て「確認済み」と記録する事故になる)。
+        |
+        | A と B の 2 枚が要るのは、A から full document navigation で離脱しないと
+        | A が bfcache に入らないため (Inertia visit は同一 Document のまま = 経路 C)。
+        */
+        Route::middleware('auth')->group(function (): void {
+            Route::get('/debug/bfcache-trial', [DebugBfcacheTrialController::class, 'trial'])
+                ->name('debug.bfcache-trial');
+            Route::get('/debug/bfcache-trial/away', [DebugBfcacheTrialController::class, 'away'])
+                ->name('debug.bfcache-trial.away');
+        });
     });
 }
diff --git a/tests/Architecture/DocumentTitleCoverageTest.php b/tests/Architecture/DocumentTitleCoverageTest.php
index dccd144..2de3ad8 100644
--- a/tests/Architecture/DocumentTitleCoverageTest.php
+++ b/tests/Architecture/DocumentTitleCoverageTest.php
@@ -91,6 +91,10 @@ function documentTitleExemptAllowlist(): array
         // routes/web.php が isLocal() || runningUnitTests() で route 登録自体を囲む =
         // staging / production には存在しない。LocalOnly middleware で二重防御済み。
         'debug.login' => 'local / テスト専用のデバッグログイン。本番に存在しないため固有タイトルを持たせる価値がない',
+        // 同上。bfcache 実機受入確認 (T085) の検証ページ。local 限定で、実機を手元に
+        // 置いて 1 枚ずつ操作する手動確認の設備であり、タブを並べて見分ける用途がない。
+        'debug.bfcache-trial' => 'local / テスト専用の bfcache 検証ページ。本番に存在しないため固有タイトルを持たせる価値がない',
+        'debug.bfcache-trial.away' => 'local / テスト専用の bfcache 検証ページ (相方)。本番に存在しないため固有タイトルを持たせる価値がない',
     ];
 }
 
diff --git a/tests/Feature/DebugBfcacheTrialRouteGateTest.php b/tests/Feature/DebugBfcacheTrialRouteGateTest.php
new file mode 100644
index 0000000..d1a087c
--- /dev/null
+++ b/tests/Feature/DebugBfcacheTrialRouteGateTest.php
@@ -0,0 +1,130 @@
+<?php
+
+declare(strict_types=1);
+
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * bfcache 検証ページ (/debug/bfcache-trial) の防御層と前提条件のテスト。
+ *
+ * 本ページ専用の env フラグは追加しない判断をしている (概念設計)。根拠は
+ * 既存の三層防御 (route 登録ゲート / LocalOnly の env 判定 + 資格情報未設定 404 /
+ * production での ProductionEnvGuard fail-fast) が既にあり、しかも本ページは
+ * 同一ゲート上の /debug/login より権限が低いためである。
+ * **その前提が構造的に維持されていることを、ここで実効条件として機械固定する。**
+ *
+ * とくに `Cache-Control: no-store` は正のコントロールである。これが付かなくなると
+ * 「Safari は no-store でも bfcache に格納する」という検証したい条件そのものが崩れ、
+ * **本番と違う条件を見て「確認済み」と記録する**事故になる。
+ *
+ * **middleware 実行順の実測 (実装時に判明)**: 本 route は `LocalOnly` グループの内側に
+ * `auth` を重ねているが、解決後の実行順は **`auth` が先**である。`Authenticate` は
+ * Laravel 既定の priority list に載っており、載っていない `LocalOnly` より前へソートされる
+ * (bootstrap/app.php の注記どおり、priority list は「載っている middleware 同士の相対順序」
+ * しか強制しない)。auth を持たない `/debug/login` とはここが非対称になる。
+ *
+ * 帰結として **guest は 404 ではなく /login へ 302 する**。この差は許容する:
+ *   - staging / production では route 登録ゲート自体が働き **route が存在しない**ため、
+ *     存在オラクルにならない
+ *   - local でのみ「登録済み route に guest が触れた」ことが 302 で分かるが、
+ *     これは開発者自身の環境であり、実際に到達しうる相手 (認証済みユーザー) に対しては
+ *     `LocalOnly` の env / 資格情報ゲートが正しく 404 / 401 を返す
+ *
+ * したがって本テストは **認証済みユーザーに対する LocalOnly の実効性**を主に固定し、
+ * guest に対しては 302 (= auth が効いていること) を負のコントロールとして固定する。
+ * `bootstrap/app.php` の priority list は TenantBoundaryOrderingTest が固定している
+ * load-bearing な宣言であり、debug ページのために順序を動かすことはしない。
+ */
+
+beforeEach(function (): void {
+    config(['app.env' => 'local']);
+    config(['debug.login.user' => 'testuser']);
+    config(['debug.login.password' => 'testpass123']);
+});
+
+/** @return array{string, string} */
+function bfcacheTrialBasicAuthHeaders(): array
+{
+    return [
+        'PHP_AUTH_USER' => 'testuser',
+        'PHP_AUTH_PW' => 'testpass123',
+    ];
+}
+
+dataset('bfcache trial routes', [
+    'trial (A)' => ['/debug/bfcache-trial', 'Debug/BfcacheTrial'],
+    'away (B)' => ['/debug/bfcache-trial/away', 'Debug/BfcacheTrialAway'],
+]);
+
+test('認証済みでも production 環境なら 404 (LocalOnly の env ゲート)', function (string $path): void {
+    [, $user] = createOrganizationWithOwner();
+    config(['app.env' => 'production']);
+
+    $this->actingAs($user)
+        ->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get($path)
+        ->assertNotFound();
+})->with('bfcache trial routes');
+
+test('認証済みでも DEBUG_LOGIN_* 未設定なら 404 (fail-secure。明示的な env opt-in が必須)', function (string $path): void {
+    [, $user] = createOrganizationWithOwner();
+    config(['debug.login.user' => '']);
+    config(['debug.login.password' => '']);
+
+    $this->actingAs($user)
+        ->get($path)
+        ->assertNotFound();
+})->with('bfcache trial routes');
+
+test('認証済みでも Basic 認証なしなら 401', function (string $path): void {
+    [, $user] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($user)->get($path);
+
+    $response->assertStatus(401);
+    expect((string) $response->headers->get('WWW-Authenticate'))->toContain('Basic');
+})->with('bfcache trial routes');
+
+test('guest は /login へリダイレクト (auth が効いていることの負のコントロール)', function (string $path): void {
+    // auth が LocalOnly より先に走るため 404 ではなく 302 になる (docblock の実行順の項)
+    $this->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get($path)
+        ->assertRedirect('/login');
+})->with('bfcache trial routes');
+
+test('認証済み + Basic 認証で 200。Inertia component が取り違えられていない', function (string $path, string $component): void {
+    [, $user] = createOrganizationWithOwner();
+
+    $this->actingAs($user)
+        ->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get($path)
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->component($component));
+})->with('bfcache trial routes');
+
+test('認証済み応答に Cache-Control: no-store が付く (検証条件の正のコントロール)', function (string $path): void {
+    [, $user] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($user)
+        ->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get($path);
+
+    $response->assertOk();
+    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
+})->with('bfcache trial routes');
+
+test('サーバは props を渡さない (実ユーザー情報を debug ページへ流さない)', function (): void {
+    [, $user] = createOrganizationWithOwner();
+
+    $this->actingAs($user)
+        ->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get('/debug/bfcache-trial')
+        ->assertOk()
+        ->assertInertia(function (Assert $page): void {
+            // controller は Inertia::render にデータを渡していない。
+            // 見えるのは HandleInertiaRequests の共有 props だけである。
+            $page->component('Debug/BfcacheTrial')
+                ->missing('users')
+                ->missing('trial');
+        });
+});
diff --git a/tests/js/architecture/no-unload-listener.test.ts b/tests/js/architecture/no-unload-listener.test.ts
new file mode 100644
index 0000000..eedd080
--- /dev/null
+++ b/tests/js/architecture/no-unload-listener.test.ts
@@ -0,0 +1,110 @@
+import { describe, it, expect } from "vitest";
+import fs from "fs/promises";
+import path from "path";
+
+/**
+ * 認証済み画面に `unload` / `beforeunload` リスナを持ち込まないことを
+ * deny-by-default で固定する。
+ *
+ * **これは debug 設備の都合ではない。** `unload` / `beforeunload` が入ると
+ * 認証済み画面が bfcache の**対象外になる、または適格性が不安定になる**ため、
+ * bfcache-guard.ts が守っている経路 B (Safari の真の bfcache 復元。
+ * docs/supported-browsers.md が正本) が無効化されうる。
+ * 認証済み画面全体の bfcache 契約に関わる制約である。
+ *
+ * ブラウザ横断で「beforeunload があれば必ず bfcache 対象外」と断定はしない。
+ * 禁止の理由は「対象外になる、または適格性を不安定にする」で十分である。
+ *
+ * さらに悪いことに、この破綻は **T085 の実機確認を無言で空振りにする**。
+ * 空振りは「PII が出ない」に見えるため緑と誤認されうる
+ * (まさに検証ページが潰そうとしている失敗モードそのもの)。
+ *
+ * 既知の限界: 検出は **文字列リテラル `"unload"` / `"beforeunload"`** に限定される。
+ * 動的にイベント名を組み立てる書き方 (`addEventListener("before" + "unload")` 等) は
+ * 検出外である。その種の書き方を導入する際は本テストのパターンも同時に更新すること
+ * (tests/js/architecture/logout-call-site-inventory.test.ts が同様の限界を明記している前例に倣う)。
+ */
+
+const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
+
+/**
+ * 監視対象。検証ページ本体だけでは足りない —
+ * AppLayout に beforeunload が入れば、検証ページ側をいくら縛っても検証条件が壊れる。
+ */
+const WATCHED_PATHS: readonly string[] = [
+    // 経路 B の当事者
+    "lib/bfcache-guard.ts",
+    "app.ts",
+    // 認証済み画面の共通レイアウト (ここに入ると全認証済み画面が影響を受ける)
+    "components/templates/AppLayout.svelte",
+    // T085 の検証設備
+    "lib/debug",
+    "pages/Debug",
+] as const;
+
+const FORBIDDEN_PATTERN = /["'`](?:before)?unload["'`]/;
+
+const SOURCE_EXTENSIONS: readonly string[] = [".svelte", ".ts"] as const;
+
+/** 監視対象パス (ファイル or ディレクトリ) を実ファイル一覧へ展開する。 */
+const resolveWatchedFiles = async (): Promise<string[]> => {
+    const files: string[] = [];
+
+    for (const watched of WATCHED_PATHS) {
+        const absolute = path.join(JS_ROOT, watched);
+        const stat = await fs.stat(absolute);
+
+        if (stat.isFile()) {
+            files.push(absolute);
+            continue;
+        }
+
+        const entries = await fs.readdir(absolute, {
+            recursive: true,
+            withFileTypes: true,
+        });
+        for (const entry of entries) {
+            if (!entry.isFile()) continue;
+            if (!SOURCE_EXTENSIONS.includes(path.extname(entry.name))) continue;
+            const parent =
+                (entry as unknown as { parentPath?: string }).parentPath ??
+                absolute;
+            files.push(path.join(parent, entry.name));
+        }
+    }
+
+    return files;
+};
+
+describe("no unload listener", () => {
+    it("監視対象がすべて実在する (パス変更で検査が無言で空になるのを防ぐ)", async () => {
+        const files = await resolveWatchedFiles();
+
+        expect(files.length).toBeGreaterThan(0);
+        for (const watched of WATCHED_PATHS) {
+            await expect(fs.stat(path.join(JS_ROOT, watched))).resolves.toBeDefined();
+        }
+    });
+
+    it("unload / beforeunload の文字列リテラルを含まない", async () => {
+        const files = await resolveWatchedFiles();
+        const offenders: string[] = [];
+
+        for (const file of files) {
+            const source = await fs.readFile(file, "utf8");
+            if (FORBIDDEN_PATTERN.test(source)) {
+                offenders.push(path.relative(JS_ROOT, file));
+            }
+        }
+
+        expect(
+            offenders,
+            [
+                "認証済み画面に unload / beforeunload リスナを追加しないこと。",
+                "bfcache の適格性を壊し、経路 B (Safari の真の bfcache 復元) の保証と",
+                "T085 の実機受入確認を無言で空振りにする。",
+                `検出: ${offenders.join(", ")}`,
+            ].join("\n"),
+        ).toEqual([]);
+    });
+});
diff --git a/tests/js/lib/debug/bfcache-trial.test.ts b/tests/js/lib/debug/bfcache-trial.test.ts
new file mode 100644
index 0000000..5bdd7d9
--- /dev/null
+++ b/tests/js/lib/debug/bfcache-trial.test.ts
@@ -0,0 +1,755 @@
+import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
+import {
+    TRIAL_SCHEMA_VERSION,
+    TRIAL_STORAGE_KEY,
+    DEVICE_MODEL_MAX_LENGTH,
+    STORAGE_FAILURE_REASON_MAX_LENGTH,
+    appendEvent,
+    canAppend,
+    deriveGuardVerdict,
+    deriveOverallVerdict,
+    deriveTrialPhase,
+    deriveTrialVerdict,
+    expectedGuardVerdict,
+    groupByTrialId,
+    hasSingleTrialId,
+    loadTrials,
+    nextSequence,
+    parseTrialEvent,
+    parseTrialLog,
+    probeStorageWritable,
+    type GuardState,
+    type TrialEvent,
+} from "@/lib/debug/bfcache-trial";
+
+/**
+ * 観測ライブラリの真理値表テスト (詳細設計 施策 5)。
+ *
+ * **最終形だけでなく逐次適用も検証する**。listener の追記可否は
+ * deriveTrialPhase() の結果で決まるため、正常な遷移 prefix で phase が
+ * complete に落ちると実機で観測が途中停止する。最終形のテストだけでは
+ * この回帰を検出できない。
+ */
+
+const TRIAL = "trial-a";
+const TOKEN = "token-a";
+
+let sequence = 0;
+
+function base(trialId = TRIAL): {
+    schemaVersion: number;
+    trialId: string;
+    sequence: number;
+    timestamp: string;
+} {
+    sequence += 1;
+    return {
+        schemaVersion: TRIAL_SCHEMA_VERSION,
+        trialId,
+        sequence,
+        timestamp: `2026-08-14T00:00:${String(sequence).padStart(2, "0")}.000Z`,
+    };
+}
+
+function started(trialId = TRIAL, contextToken = TOKEN): TrialEvent {
+    return {
+        ...base(trialId),
+        type: "trial-started",
+        scenario: "expired-session",
+        contextToken,
+        userAgent: "test-agent",
+        uaReportedOs: "iOS",
+        displayMode: "standalone",
+        navigatorStandalone: true,
+        deviceModel: "iPhone 15 Pro",
+        verifiedOsVersion: "18.2",
+    };
+}
+
+function away(trialId = TRIAL): TrialEvent {
+    return { ...base(trialId), type: "away-navigation-started" };
+}
+
+function awayFailed(trialId = TRIAL): TrialEvent {
+    return {
+        ...base(trialId),
+        type: "away-navigation-failed",
+        observationMethod: "manual",
+    };
+}
+
+function hide(persisted: boolean, trialId = TRIAL): TrialEvent {
+    return { ...base(trialId), type: "page-hide", persisted, guardState: null };
+}
+
+function show(
+    persisted: boolean,
+    contextToken = TOKEN,
+    trialId = TRIAL,
+): TrialEvent {
+    return {
+        ...base(trialId),
+        type: "page-show",
+        persisted,
+        contextToken,
+        displayMode: "standalone",
+    };
+}
+
+function guard(state: GuardState, trialId = TRIAL): TrialEvent {
+    return { ...base(trialId), type: "guard-state-changed", state };
+}
+
+function redirect(trialId = TRIAL): TrialEvent {
+    return {
+        ...base(trialId),
+        type: "redirect-observed",
+        observationMethod: "manual",
+    };
+}
+
+function aborted(trialId = TRIAL): TrialEvent {
+    return { ...base(trialId), type: "trial-aborted" };
+}
+
+beforeEach(() => {
+    sequence = 0;
+    sessionStorage.clear();
+});
+
+// ---------------------------------------------------------------------------
+
+describe("軸 1: 試行成立判定", () => {
+    it("#1 started → away → hide(true) → show(true, token 一致) は valid-bfcache", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), hide(true), show(true)]),
+        ).toBe("valid-bfcache");
+    });
+
+    it("#2 show(false) かつ token 不一致は invalid-not-bfcache (空振り)", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(false, "other-token"),
+            ]),
+        ).toBe("invalid-not-bfcache");
+    });
+
+    it("#3 hide(false) と show(true) の不一致は inconsistent", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), hide(false), show(true)]),
+        ).toBe("inconsistent");
+    });
+
+    it("#4 show(false) だが token 一致は inconsistent", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), hide(true), show(false)]),
+        ).toBe("inconsistent");
+    });
+
+    it("#5 show(true) だが token 不一致は inconsistent", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(true, "other-token"),
+            ]),
+        ).toBe("inconsistent");
+    });
+
+    it("#6 show が無ければ incomplete", () => {
+        expect(deriveTrialVerdict([started(), away(), hide(true)])).toBe(
+            "incomplete",
+        );
+    });
+
+    it("#7 hide 後に aborted は incomplete", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), hide(true), aborted()]),
+        ).toBe("incomplete");
+    });
+
+    it("#8 away 後に hide が無いだけでは incomplete (時間差を失敗と見なさない)", () => {
+        expect(deriveTrialVerdict([started(), away()])).toBe("incomplete");
+    });
+
+    it("#9 away-navigation-failed (手動記録) があれば invalid-wrong-route", () => {
+        expect(deriveTrialVerdict([started(), away(), awayFailed()])).toBe(
+            "invalid-wrong-route",
+        );
+    });
+
+    it("#10 started のみは incomplete", () => {
+        expect(deriveTrialVerdict([started()])).toBe("incomplete");
+    });
+
+    it("#11 sequence 逆順 (show が hide より前) は inconsistent", () => {
+        const s = started();
+        const a = away();
+        const sh = show(true);
+        const h = hide(true);
+        expect(deriveTrialVerdict([s, a, sh, h])).toBe("inconsistent");
+    });
+
+    it("#12 guard-state-changed のみは incomplete (invalid-wrong-route にしない)", () => {
+        expect(deriveTrialVerdict([started(), guard("pending")])).toBe(
+            "incomplete",
+        );
+    });
+
+    it("#13 複数 trialId の混入は inconsistent", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), away("trial-b")]),
+        ).toBe("inconsistent");
+    });
+
+    it("#14 away 欠落 (started → hide → show) は inconsistent", () => {
+        expect(deriveTrialVerdict([started(), hide(true), show(true)])).toBe(
+            "inconsistent",
+        );
+    });
+
+    it("#15 窓確定後に show(false, token 不一致) が追記されても valid-bfcache を維持", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(true),
+                show(false, "fresh-token"),
+            ]),
+        ).toBe("valid-bfcache");
+    });
+
+    it("#16 窓確定後に redirect-observed が追記されても valid-bfcache を維持", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(true),
+                redirect(),
+            ]),
+        ).toBe("valid-bfcache");
+    });
+
+    it("#17 窓確定後の復元後 page-hide は軸 1 に影響しない", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(true),
+                guard("pending"),
+                guard("verifying"),
+                hide(true),
+            ]),
+        ).toBe("valid-bfcache");
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("軸 2: guard 結果判定", () => {
+    /**
+     * 軸 1 window を成立させたうえで、復元後のイベントを足す。
+     *
+     * **thunk で受ける**のが要点。イベントを値で受けると JS の引数評価順により
+     * 復元後イベントの sequence が window の page-show より小さくなり、
+     * 軸 2 の境界フィルタで除外されてしまう (テストが意図しない列を検証することになる)。
+     */
+    function withWindow(...makeAfter: Array<() => TrialEvent>): TrialEvent[] {
+        const events: TrialEvent[] = [
+            started(),
+            away(),
+            hide(true),
+            show(true),
+        ];
+        for (const make of makeAfter) events.push(make());
+        return events;
+    }
+
+    it("#1 pending → verifying → null は authenticated-unhidden", () => {
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => guard(null)),
+            ),
+        ).toBe("authenticated-unhidden");
+    });
+
+    it("#2 秘匿維持のまま復元後 hide + redirect-observed は unauthenticated-redirected", () => {
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => hide(true), () => redirect()),
+            ),
+        ).toBe("unauthenticated-redirected");
+    });
+
+    it("#3 同じ列で redirect-observed が無ければ hidden-then-left", () => {
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => hide(true)),
+            ),
+        ).toBe("hidden-then-left");
+    });
+
+    it("#4 pending → verifying → retry は retry-hidden", () => {
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => guard("retry")),
+            ),
+        ).toBe("retry-hidden");
+    });
+
+    it("#7 verifying を経ずに null は failed-transition (秘匿解除が早すぎる)", () => {
+        expect(
+            deriveGuardVerdict(withWindow(() => guard("pending"), () => guard(null))),
+        ).toBe("failed-transition");
+    });
+
+    it("#8 往路 hide のみでは unauthenticated-redirected にしない", () => {
+        // 復元後の hide が無い (往路 hide は軸 1 window の内側)
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => redirect()),
+            ),
+        ).toBe("in-progress");
+    });
+
+    it("#9 軸 2 終端後に fresh load のイベントが追記されても判定が崩れない", () => {
+        const events = withWindow(() => guard("pending"), () => guard("verifying"), () => guard(null), () => show(false, "fresh-token"));
+        expect(deriveTrialVerdict(events)).toBe("valid-bfcache");
+        expect(deriveGuardVerdict(events)).toBe("authenticated-unhidden");
+    });
+
+    it("#10 復元直後で guard イベント無しは in-progress", () => {
+        expect(deriveGuardVerdict(withWindow())).toBe("in-progress");
+    });
+
+    it("#11 pending のみは in-progress (停止をイベント列から判定しない)", () => {
+        expect(deriveGuardVerdict(withWindow(() => guard("pending")))).toBe(
+            "in-progress",
+        );
+    });
+
+    it("#12 pending → verifying は in-progress", () => {
+        expect(
+            deriveGuardVerdict(withWindow(() => guard("pending"), () => guard("verifying"))),
+        ).toBe("in-progress");
+    });
+
+    it("#13 verifying から始まる列は failed-transition", () => {
+        expect(deriveGuardVerdict(withWindow(() => guard("verifying")))).toBe(
+            "failed-transition",
+        );
+    });
+
+    it("#15 guard イベント無しのまま aborted は not-observed", () => {
+        expect(deriveGuardVerdict(withWindow(() => aborted()))).toBe("not-observed");
+    });
+
+    it("複数 trialId の混入は failed-transition", () => {
+        expect(deriveGuardVerdict([started(), guard("pending", "trial-b")])).toBe(
+            "failed-transition",
+        );
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("軸 3: 総合判定", () => {
+    it("expired-session × valid-bfcache × unauthenticated-redirected は pass", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "unauthenticated-redirected",
+            ),
+        ).toBe("pass");
+    });
+
+    it("active-session × valid-bfcache × authenticated-unhidden は pass", () => {
+        expect(
+            deriveOverallVerdict(
+                "active-session",
+                "valid-bfcache",
+                "authenticated-unhidden",
+            ),
+        ).toBe("pass");
+    });
+
+    it("expired-session で authenticated-unhidden は expectation-mismatch", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "authenticated-unhidden",
+            ),
+        ).toBe("expectation-mismatch");
+    });
+
+    it("hidden-then-left は undetermined (redirect-observed 待ち)", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "hidden-then-left",
+            ),
+        ).toBe("undetermined");
+    });
+
+    it("in-progress は undetermined (観測途中を fail にしない)", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "in-progress",
+            ),
+        ).toBe("undetermined");
+    });
+
+    it("not-observed は undetermined (guard 故障と中止を区別できない)", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "not-observed",
+            ),
+        ).toBe("undetermined");
+    });
+
+    it("failed-transition は fail", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "failed-transition",
+            ),
+        ).toBe("fail");
+    });
+
+    it("空振り (invalid-not-bfcache) は pass にも fail にもしない", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "invalid-not-bfcache",
+                "authenticated-unhidden",
+            ),
+        ).toBe("undetermined");
+    });
+
+    it("incomplete は undetermined", () => {
+        expect(
+            deriveOverallVerdict("expired-session", "incomplete", "in-progress"),
+        ).toBe("undetermined");
+    });
+
+    it("expectedGuardVerdict がシナリオごとの期待値を返す", () => {
+        expect(expectedGuardVerdict("expired-session")).toBe(
+            "unauthenticated-redirected",
+        );
+        expect(expectedGuardVerdict("active-session")).toBe(
+            "authenticated-unhidden",
+        );
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("逐次適用: 各追記直後の verdict と phase", () => {
+    it("正常な遷移 prefix で観測が停止しない", () => {
+        const events: TrialEvent[] = [started(), away(), hide(true), show(true)];
+
+        // 軸 1 window 確定直後
+        expect(deriveGuardVerdict(events)).toBe("in-progress");
+        expect(deriveTrialPhase(events)).toBe("collecting-axis2");
+
+        events.push(guard("pending"));
+        expect(deriveGuardVerdict(events)).toBe("in-progress");
+        expect(deriveTrialPhase(events)).toBe("collecting-axis2");
+
+        events.push(guard("verifying"));
+        expect(deriveGuardVerdict(events)).toBe("in-progress");
+        expect(deriveTrialPhase(events)).toBe("collecting-axis2");
+
+        events.push(guard(null));
+        expect(deriveGuardVerdict(events)).toBe("authenticated-unhidden");
+        expect(deriveTrialPhase(events)).toBe("complete");
+    });
+
+    it("retry 終端は complete", () => {
+        const events: TrialEvent[] = [
+            started(),
+            away(),
+            hide(true),
+            show(true),
+            guard("pending"),
+            guard("verifying"),
+            guard("retry"),
+        ];
+        expect(deriveGuardVerdict(events)).toBe("retry-hidden");
+        expect(deriveTrialPhase(events)).toBe("complete");
+    });
+
+    it("復元後 hide は awaiting-manual-confirmation、redirect 追記で complete", () => {
+        const events: TrialEvent[] = [
+            started(),
+            away(),
+            hide(true),
+            show(true),
+            guard("pending"),
+            guard("verifying"),
+            hide(true),
+        ];
+        expect(deriveGuardVerdict(events)).toBe("hidden-then-left");
+        expect(deriveTrialPhase(events)).toBe("awaiting-manual-confirmation");
+
+        events.push(redirect());
+        expect(deriveGuardVerdict(events)).toBe("unauthenticated-redirected");
+        expect(deriveTrialPhase(events)).toBe("complete");
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("deriveTrialPhase の状態機械", () => {
+    it("軸 1 未終端は collecting-axis1", () => {
+        expect(deriveTrialPhase([started(), away()])).toBe("collecting-axis1");
+    });
+
+    it("軸 1 が invalid-not-bfcache で終端すると complete", () => {
+        expect(
+            deriveTrialPhase([
+                started(),
+                away(),
+                hide(true),
+                show(false, "other-token"),
+            ]),
+        ).toBe("complete");
+    });
+
+    it("trial-aborted は他の終端イベントと併存しても aborted が優先", () => {
+        expect(
+            deriveTrialPhase([
+                started(),
+                away(),
+                hide(true),
+                show(true),
+                guard("pending"),
+                guard("verifying"),
+                guard(null),
+                aborted(),
+            ]),
+        ).toBe("aborted");
+    });
+
+    it("複数 trialId の混入は invalid", () => {
+        expect(deriveTrialPhase([started(), away("trial-b")])).toBe("invalid");
+    });
+
+    it("awaiting-manual-confirmation では自動イベントを追記できない", () => {
+        expect(canAppend("awaiting-manual-confirmation", "page-show")).toBe(
+            false,
+        );
+        expect(canAppend("awaiting-manual-confirmation", "guard-state-changed")).toBe(
+            false,
+        );
+        expect(canAppend("awaiting-manual-confirmation", "redirect-observed")).toBe(
+            true,
+        );
+        expect(canAppend("awaiting-manual-confirmation", "trial-aborted")).toBe(
+            true,
+        );
+    });
+
+    it("complete / aborted / invalid では一切追記できない", () => {
+        for (const phase of ["complete", "aborted", "invalid"] as const) {
+            expect(canAppend(phase, "page-show")).toBe(false);
+            expect(canAppend(phase, "redirect-observed")).toBe(false);
+        }
+    });
+
+    it("collecting-axis1 では離脱失敗の手動記録を許可する", () => {
+        expect(canAppend("collecting-axis1", "away-navigation-failed")).toBe(
+            true,
+        );
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("validator の負のコントロール", () => {
+    it("schemaVersion 不一致は破棄", () => {
+        const event = { ...started(), schemaVersion: 99 };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("未知の type は破棄", () => {
+        const event = { ...started(), type: "unknown-type" };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("許可外の余分なキーを持つイベントは破棄", () => {
+        const event = { ...started(), extraKey: "x" };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("必須キーの欠落は破棄", () => {
+        const event: Record<string, unknown> = { ...started() };
+        delete event.contextToken;
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("deviceModel が最大長超過なら破棄", () => {
+        const event = {
+            ...started(),
+            deviceModel: "a".repeat(DEVICE_MODEL_MAX_LENGTH + 1),
+        };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("deviceModel に許可外文字があれば破棄", () => {
+        expect(parseTrialEvent({ ...started(), deviceModel: "山田太郎" })).toBeNull();
+        expect(parseTrialEvent({ ...started(), deviceModel: "a@b.com" })).toBeNull();
+    });
+
+    it("storage-failed の reason が最大長超過なら破棄", () => {
+        const event = {
+            ...base(),
+            type: "storage-failed",
+            reason: "x".repeat(STORAGE_FAILURE_REASON_MAX_LENGTH + 1),
+        };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("observationMethod が manual 以外なら破棄", () => {
+        const event = { ...redirect(), observationMethod: "auto" };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("JSON として壊れていれば null", () => {
+        expect(parseTrialLog("{not json")).toBeNull();
+    });
+
+    it("配列でなければ null", () => {
+        expect(parseTrialLog('{"a":1}')).toBeNull();
+    });
+
+    it("1 件だけ壊れていてもログ全体を破棄する (部分採用しない)", () => {
+        const raw = JSON.stringify([started(), { broken: true }, away()]);
+        expect(parseTrialLog(raw)).toBeNull();
+    });
+
+    it("正常なログはイベント数どおりパースされる", () => {
+        const events = [started(), away(), hide(true)];
+        const parsed = parseTrialLog(JSON.stringify(events));
+        expect(parsed).not.toBeNull();
+        expect(parsed).toHaveLength(3);
+    });
+
+    it("null / 空文字は null", () => {
+        expect(parseTrialLog(null)).toBeNull();
+        expect(parseTrialLog("")).toBeNull();
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("採番と trial 分離", () => {
+    it("空配列では 1 を返す", () => {
+        expect(nextSequence([], TRIAL)).toBe(1);
+    });
+
+    it("復元した進行中 trial に対して max+1 を返す", () => {
+        const events = [started(), away(), hide(true)];
+        expect(nextSequence(events, TRIAL)).toBe(4);
+    });
+
+    it("欠番・重複があっても max+1 を返す", () => {
+        const events: TrialEvent[] = [
+            { ...started(), sequence: 1 },
+            { ...away(), sequence: 7 },
+            { ...hide(true), sequence: 7 },
+        ];
+        expect(nextSequence(events, TRIAL)).toBe(8);
+    });
+
+    it("他 trial の sequence を混ぜない", () => {
+        const events: TrialEvent[] = [
+            { ...started(), sequence: 1 },
+            { ...started("trial-b"), sequence: 99 },
+        ];
+        expect(nextSequence(events, TRIAL)).toBe(2);
+    });
+
+    it("hasSingleTrialId が単一で true、混入で false", () => {
+        expect(hasSingleTrialId([started(), away()])).toBe(true);
+        expect(hasSingleTrialId([started(), away("trial-b")])).toBe(false);
+        expect(hasSingleTrialId([])).toBe(true);
+    });
+
+    it("groupByTrialId が trialId ごとに分離する", () => {
+        const grouped = groupByTrialId([
+            started(),
+            away(),
+            started("trial-b"),
+        ]);
+        expect(grouped.size).toBe(2);
+        expect(grouped.get(TRIAL)).toHaveLength(2);
+        expect(grouped.get("trial-b")).toHaveLength(1);
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("storage", () => {
+    afterEach(() => {
+        vi.restoreAllMocks();
+    });
+
+    it("probeStorageWritable が書き込み可能環境で true", () => {
+        expect(probeStorageWritable()).toBe(true);
+    });
+
+    it("probeStorageWritable が setItem 例外環境で false", () => {
+        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
+            throw new Error("QuotaExceededError");
+        });
+        expect(probeStorageWritable()).toBe(false);
+    });
+
+    it("appendEvent が例外を伝播せず false を返す", () => {
+        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
+            throw new Error("QuotaExceededError");
+        });
+        expect(() => appendEvent(started())).not.toThrow();
+        expect(appendEvent(started())).toBe(false);
+    });
+
+    it("appendEvent の read-back が書き戻し内容の不一致を検出する", () => {
+        // setItem は成功するが保存内容が別物になる環境を模す
+        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
+            // 何も保存しない (getItem は null のまま)
+        });
+        expect(appendEvent(started())).toBe(false);
+    });
+
+    it("appendEvent が追記し、loadTrials が trialId ごとに返す", () => {
+        expect(appendEvent(started())).toBe(true);
+        expect(appendEvent(away())).toBe(true);
+        expect(appendEvent(started("trial-b"))).toBe(true);
+
+        const trials = loadTrials();
+        expect(trials.size).toBe(2);
+        expect(trials.get(TRIAL)).toHaveLength(2);
+        expect(trials.get("trial-b")).toHaveLength(1);
+    });
+
+    it("保存済みログが壊れていれば loadTrials は空を返す", () => {
+        sessionStorage.setItem(TRIAL_STORAGE_KEY, "{broken");
+        expect(loadTrials().size).toBe(0);
+    });
+});
```
