## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

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


あなたは Laravel 12 + Svelte 5 (runes) + Inertia.js のコードレビュアーである。
TODO **T150「状態を区別しない汎用メッセージの是正」** の実装差分をレビューせよ。
詳細設計は Codex design-review で **APPROVED 済み**である。

## レビュー観点

1. **設計との一致性** — 詳細設計の施策 1〜4 が実装されているか。設計から逸脱した箇所は
   逸脱の根拠が妥当か (下記「実装者からの申し送り」に逸脱 1 件の説明がある)
2. **正確性** — 分岐漏れ・null 安全・wire 契約 (PHP DTO ⇔ TS 型) の不整合
3. **PHPStan level 10 適合性** — `value-of<...>` array shape、import 漏れ
4. **DTO / JsonResource パターン** — `response()->json()` の直書きをしていないか
5. **テスト網羅性** — バグ修正としてテストファーストが成立しているか。
   negative control / degenerate PASS 防止が効いているか。
   **mutation evidence に記載した「設計の予測と実測のずれ」の扱いが誠実か**
6. **セキュリティ** — 認可をフロントで二重実装していないか。破壊的な wire 変更の取りこぼし
7. **DESIGN.md 準拠** — `/DESIGN.md` が design token の canonical source。
   color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。
   token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか
8. **Atomic Design 準拠** — `resources/js/components/` は `atoms/molecules/organisms/templates` の
   責務分離に従う。atom は単機能・状態を持たない、molecule は atom の組合せという階層を
   逆流していないか。アイコンは Lucide を使い SVG 直書きを増やさない。
   import は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向

## 出力形式

- **ファイルごとに判定**を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - Critical: 修正しないとマージできないもの (バグ・不変条件違反・禁止事項違反)
  - Warning: 検討して対応すべきもの
  - Suggestion: 任意
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

**設計レビューで既に決着した論点を蒸し返さないこと** (例: 2 件を共通化しない / 新 enum を作らない /
CTA を権限で分岐させない / 429 は最小形で Retry-After 秒数を出さない / 閾値と課金ゲート判定は変えない)。

---

## 詳細設計書

# 詳細設計: state-aware-messaging (F-2-01 / F-2-02)

## 使命・制約（絶対遵守）

### アプリの使命（North Star） — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase は `tests/Pest.php` でグローバル適用**、`--parallel` 実行。
  個別 `DatabaseTransactions` 使用禁止
- **テストデータは必ず Factory**（`Model::create()` 手組み禁止）
- **DTO + Inertia props** パターン（本設計に新規モデル・新規 API endpoint は無い）
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- フォーマット: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは DESIGN.md の token 経由のみ（hex 直書き禁止）。component 階層は
  `atoms → molecules → organisms → features → templates → pages` の単方向

## 概念設計リファレンス

`devnotes/20260811-0146-state-aware-messaging/conceptual-design.md`（Codex conceptual-review Round 1 で **APPROVED**）

## 対象 finding（実ブラウザで再現済み・推測ではない）

| finding | severity | 再現手順 | 証跡 |
|---|---|---|---|
| F-2-01 | Medium | `/register` → メール認証 → `/dashboard` を開く | `devnotes/20260811-003230-bug-hunt/shard-2/screenshots/F-2-01-dashboard-billing-callout.png` |
| F-2-02 | Low | `/login` で「パスキーでログイン」を 11 回連打 → `GET /passkeys/login/options` が 429 | `.../screenshots/F-2-02-passkey-429.png` |

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | dashboard props を `has_billing_access: bool` → `billing_state` へ差し替え | `app/DataTransferObjects/Dashboard/BillingSummaryData.php` / `app/DataTransferObjects/Dashboard/DashboardPageData.php` / `app/Services/Dashboard/DashboardService.php` | High |
| 2 | dashboard callout を状態別に出し分ける | `resources/js/types/dashboard.ts` / `resources/js/pages/Dashboard.svelte` | High |
| 3 | passkey の 429 を他の失敗と区別する | `resources/js/lib/passkeys.ts` | Medium |
| 4 | 再発防止 gate（enum ⇔ TS union 同期） | `tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php`（新規） | Medium |

**施策 1+2 は不可分**（wire の型を変える以上、同じ commit で TS 側も変える。思考原則 3）。
**施策 3 は独立**（PHP に一切触れない）。

---

## 施策 1: dashboard props を state enum へ差し替える

### 変更箇所

- `app/DataTransferObjects/Dashboard/BillingSummaryData.php`（全体）
- `app/DataTransferObjects/Dashboard/DashboardPageData.php`（`toArray()` の `@return` shape）
- `app/Services/Dashboard/DashboardService.php`（`billingSummary()` L219-236）

### 波及変更

- **TypeScript 型定義**: `resources/js/types/dashboard.ts` の `BillingSummary`（施策 2）
- **Inertia Props**: `Dashboard.svelte` の `billing.has_billing_access` 参照（施策 2）
- **API Resource/DTO**: なし（dashboard は Inertia props のみ。API 面には出ていない）
- **テストファイル**:
  - `tests/Feature/DashboardTest.php`（`has_billing_access` を見る 4 箇所: L208 / L412-419 / L424-437 / L444-452）
  - `tests/js/pages/Dashboard.test.ts`（L18 の `billingData` 既定 / L248-278 の 2 テスト）
- **他に参照なし**（`rg 'has_billing_access|hasBillingAccess'` の全ヒットが上記 + `docs/template-divergence.md` L277 の記述 + `docs/TODO-closed.md` の履歴。**履歴 2 ファイルは書き換えない**。`docs/template-divergence.md` は現況記述なので更新する）

### 現行コード

```php
// app/DataTransferObjects/Dashboard/BillingSummaryData.php
final readonly class BillingSummaryData
{
    public function __construct(
        public int $ticketBalance,
        public bool $isLowBalance,
        public int $storageUsedBytes,
        public ?int $storageLimitBytes,
        public ?int $storageUsagePercent,
        public bool $hasBillingAccess,      // BillingAccess::hasActiveAccess (billing entitlement。free 組織は true)
    ) {}

    /**
     * @return array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
     *   storage_limit_bytes: int|null, storage_usage_percent: int|null,
     *   has_billing_access: bool}
     */
    public function toArray(): array
    {
        return [
            // ...
            'has_billing_access' => $this->hasBillingAccess,
        ];
    }
}
```

```php
// app/Services/Dashboard/DashboardService.php:219-236
private function billingSummary(Organization $organization): BillingSummaryData
{
    // ...
    return new BillingSummaryData(
        // ...
        hasBillingAccess: $this->billingAccess->hasActiveAccess($organization),
    );
}
```

### 変更後コード

```php
// app/DataTransferObjects/Dashboard/BillingSummaryData.php
use App\Enums\Billing\OnboardingBillingState;

/**
 * チケット残高 + 容量 Quota (低残高警告と高使用率警告は別個のフラグ)。
 * TS 側 types/dashboard.ts の BillingSummary と対で保守する。
 */
final readonly class BillingSummaryData
{
    public function __construct(
        public int $ticketBalance,
        public bool $isLowBalance,
        public int $storageUsedBytes,
        public ?int $storageLimitBytes,
        public ?int $storageUsagePercent,
        /**
         * 課金状態 (BillingAccess::state)。**真偽値に潰さない** — 「未契約」と
         * 「支払い不健全」は次の一手が違うため、画面が区別できる必要がある
         * (bug-hunt 20260811-003230 F-2-01)。利用可否だけが要るときは
         * `$billingState->grantsAccess()` で判定できる (情報量は真偽値より広い)。
         */
        public OnboardingBillingState $billingState,
    ) {}

    /**
     * `value-of<OnboardingBillingState>` を使う (単なる `string` にしない)。
     * 既存の `BillingFeedbackDto` の `@phpstan-type` が同じ書き方で、
     * enum の値集合を PHPStan に伝えている先例である。
     *
     * @return array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
     *   storage_limit_bytes: int|null, storage_usage_percent: int|null,
     *   billing_state: value-of<OnboardingBillingState>}
     */
    public function toArray(): array
    {
        return [
            'ticket_balance' => $this->ticketBalance,
            'is_low_balance' => $this->isLowBalance,
            'storage_used_bytes' => $this->storageUsedBytes,
            'storage_limit_bytes' => $this->storageLimitBytes,
            'storage_usage_percent' => $this->storageUsagePercent,
            'billing_state' => $this->billingState->value,
        ];
    }
}
```

```php
// app/Services/Dashboard/DashboardService.php
    return new BillingSummaryData(
        ticketBalance: $balance,
        isLowBalance: $balance < config()->integer('billing.ticket_low_balance_threshold'),
        storageUsedBytes: $used,
        storageLimitBytes: $limit,
        storageUsagePercent: $percent,
        // 真偽値へ潰さず state をそのまま渡す (画面が未契約と支払い不健全を区別するため)
        billingState: $this->billingAccess->state($organization),
    );
```

`DashboardPageData` の `@return` shape も `has_billing_access: bool` →
`billing_state: value-of<OnboardingBillingState>` に更新する
（`billing: array{...}|null` の入れ子部分）。

> **import 必須（Codex design-review Round 2 の指摘）**: `DashboardPageData.php` は現在
> `OnboardingBillingState` を import していない。PHPDoc の型名も現在の namespace で解決されるため、
> **`use App\Enums\Billing\OnboardingBillingState;` を必ず追加する**
> （追加しないと `App\DataTransferObjects\Dashboard\OnboardingBillingState` と解釈されて
> PHPStan が未知クラスとして落ちる）。完全修飾名で書く選択肢もあるが、
> `BillingSummaryData` 側と書式を揃えるため import する。

### 設計判断（なぜこの形か）

- **新しい enum を作らない**。`OnboardingBillingState` が既に流入制御の正本語彙であり、
  `/billing` の `BillingDashboardDto::$billingState` が同じ enum を wire に載せている前例がある。
  別の enum を新設すると同じ概念が 2 つになる（思考原則 4）。
- **`OnboardingBillingState` に case を足さない**。case 追加は `grantsAccess()` の母集団
  = 課金ゲートの判定に触れることになり、「判定ロジックを変えない」前提に反する。
- **`has_billing_access` を残さない**（思考原則 3）。`billing_state` から
  `grantsAccess()` 相当を一意に導けるため、並走させると 2 つの真実ができる。
- **`BillingAccess::state()` の呼び出しコストは増えない**。`hasActiveAccess()` の実装は
  `return $this->state($organization)->grantsAccess();` の 1 行であり、`state()` に置き換えても
  クエリ本数は同じ（entitlement 導出 + 必要時のみ checkout session 参照）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`toArray(): array` + `@return` array shape）
- [x] null 安全（新しい nullable を導入しない。`billingState` は非 null）
- [x] DTO を返している（配列返却なし。`OnboardingBillingState` は enum のまま DTO が保持し、
      wire 化の瞬間だけ `->value` で string へ落とす = `BillingDashboardDto` と同作法）
- [x] Generics の型パラメータ: 該当なし
- [x] `@return` shape の `billing_state: string` は `OnboardingBillingState::$value` の型と一致
      （backed enum の `value` は `string`）

### リスク

- **wire の破壊的変更**。`has_billing_access` を読む未知の消費者がいると壊れる。
  → **リポジトリ内の**参照は `rg 'has_billing_access|hasBillingAccess'` で全数確認済み
  （アプリ 2 + テスト 2 ファイル + docs 2 ファイル）。dashboard props は Inertia page prop であり
  公開 API 契約ではないため、破壊の影響はリポジトリ内に閉じると**期待**できる。
  ただし**リポジトリ外の消費者（外部 E2E スクリプト・ブラウザ拡張・運用スクリプト等）の
  不存在は機械的には保証できない**（Codex design-review Round 2 の指摘。断定しない）。
- **PHPStan の array shape 不一致**が `DashboardPageData` 側に残ると level 10 で落ちる
  → 施策 1 の変更対象に明示済み（`composer phpstan` が検出する）。

---

## 施策 2: dashboard callout を状態別に出し分ける

### 変更箇所

- `resources/js/types/dashboard.ts`（`BillingSummary` interface）
- `resources/js/pages/Dashboard.svelte`（L208-217 の callout + `<script>` に copy map）

### 波及変更

- API Resource/DTO: 施策 1 と対（同一 commit）
- テスト: `tests/js/pages/Dashboard.test.ts` / `tests/Browser/DashboardBillingCalloutTest.php`（新規）

### 現行コード

```ts
// resources/js/types/dashboard.ts
export interface BillingSummary {
    ticket_balance: number;
    is_low_balance: boolean;
    storage_used_bytes: number;
    storage_limit_bytes: number | null;
    storage_usage_percent: number | null;
    has_billing_access: boolean;
}
```

```svelte
<!-- resources/js/pages/Dashboard.svelte:208-217 -->
{#if !billing.has_billing_access}
    <Card class="mt-6" testId="billing-callout">
        <p class="text-body text-text">
            サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。
        </p>
        <div class="mt-4">
            <Button href="/billing" inertia>お支払い方法を確認</Button>
        </div>
    </Card>
{/if}
```

### 変更後コード

```ts
// resources/js/types/dashboard.ts
import type { BillingStateValue } from "@/types/billing";

export interface BillingSummary {
    ticket_balance: number;
    is_low_balance: boolean;
    storage_used_bytes: number;
    storage_limit_bytes: number | null;
    storage_usage_percent: number | null;
    /** PHP: BillingSummaryData::$billingState (OnboardingBillingState)。真偽値に潰さない */
    billing_state: BillingStateValue;
}
```

```svelte
<script lang="ts">
    // ... 既存 import
    import type { BillingStateValue } from "@/types/billing";

    /**
     * 課金状態ごとの callout。**null = callout を出さない**。
     *
     * 未契約 (no_subscription) と支払い不健全 (expired_checkout) は次の一手が違う
     * (bug-hunt 20260811-003230 F-2-01: 新規登録直後の全ユーザーに支払い失敗の文言が出ていた)。
     * `satisfies Record<BillingStateValue, …>` により、state が増えたら
     * `pnpm typecheck` が落ちる (描画漏れの silent 化を防ぐ)。
     *
     * CTA の行き先を権限で分岐させない: /onboarding/checkout は契約済みなら /billing、
     * manageBilling なしなら /billing-required へサーバが捌く (OnboardingController::show)。
     * フロントで認可を再判定しないし、押せないボタンも作らない (禁止事項 8)。
     */
    const BILLING_CALLOUTS = {
        subscribed: null,
        active_free_plan: null,
        no_subscription: {
            body: "ご利用にはプランの選択が必要です。プランを選ぶと機能をご利用いただけます。",
            cta: { label: "プランを選ぶ", href: "/onboarding/checkout" },
        },
        pending_checkout: {
            body: "お支払いのお手続きが完了していません。ご利用を開始するには、プラン選択からお手続きください。",
            cta: { label: "プラン選択へ", href: "/onboarding/checkout" },
        },
        expired_checkout: {
            body: "サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。",
            cta: { label: "お支払い方法を確認", href: "/billing" },
        },
    } as const satisfies Record<
        BillingStateValue,
        { body: string; cta: { label: string; href: string } } | null
    >;

    const billingCallout = $derived(billing ? BILLING_CALLOUTS[billing.billing_state] : null);
</script>
```

```svelte
{#if billingCallout}
    <Card class="mt-6" testId="billing-callout">
        <p class="text-body text-text" data-testid="billing-callout-body">{billingCallout.body}</p>
        <div class="mt-4">
            <Button href={billingCallout.cta.href} inertia>{billingCallout.cta.label}</Button>
        </div>
    </Card>
{/if}
```

### 設計判断

- **Card のまま**（Alert に変えない）。色・トーンを変えると DESIGN.md の状態色の意味づけと
  a11y role（danger のみ `role=alert`）に踏み込む。今回直すのは**文言と行き先**であり、
  見た目を変えないほうが差分と検証範囲が小さい（思考原則 2）。
  → **DESIGN.md token の新規参照ゼロ / hex 直書きゼロ / 新規 atom ゼロ**。
- **Atomic Design の層は跨がない**。分岐は page 内に閉じる（新しい molecule を作らない）。
  copy が 3 状態しかなく、他ページで再利用する予定もない。
- `billing` は `state === "no_organization"` のとき null。既存の `{#if billing}` の内側に置く
  （`$derived` 側でも null ガードする = 二重で安全側）。

### リスク

- **文言の後退**: `expired_checkout` の文言と CTA を現行から変えない。既存 vitest が
  この文言と `/billing` 遷移先を固定しており、変えると「二重契約誘導」の検出が効かなくなる
  （既存テストのコメントが明記している）。→ 文字列をそのまま維持する。
- `@/types/billing` を `@/types/dashboard` から import する新しい依存が増える。
  型ファイル同士の import であり、`atomic-import-graph.test.ts` の対象（component 層）ではない。

---

## 施策 3: passkey の 429 を他の失敗と区別する

### 変更箇所

- `resources/js/lib/passkeys.ts`（`fetchOptions` / `assertPasskey` / `createPasskeyCredential` / `readErrorMessage`）

### 波及変更

- TypeScript 型定義: **`PasskeyOutcome<T>` は変えない**（呼び出し側 4 ファイルは無変更）
- API Resource/DTO: なし（サーバ側は 1 行も変えない）
- テスト: `tests/js/lib/passkeys.test.ts`

### 現行コード

```ts
async function fetchOptions(url: string): Promise<JsonRecord | null> {
    try {
        const res = await requestJson(url);
        if (!res.ok) return null;         // ← 429 も 500 も 403 もここで同じ null になる
        const payload: unknown = await res.json();
        if (!isRecord(payload) || !isRecord(payload.options)) return null;
        return payload.options;
    } catch {
        return null;
    }
}

async function assertPasskey(optionsUrl: string): Promise<PasskeyOutcome<JsonRecord>> {
    if (!isPasskeySupported()) return { status: "unsupported" };
    const options = await fetchOptions(optionsUrl);
    if (options === null) {
        return { status: "failed", message: "パスキーの認証を開始できませんでした。" };
    }
    // ...
}

async function readErrorMessage(response: Response): Promise<string> {
    try {
        const payload: unknown = await response.json();
        // ... payload.message / payload.errors[..][0] / GENERIC_FAILURE
    } catch { return GENERIC_FAILURE; }
}
```

### 変更後コード

```ts
/**
 * 429 専用の文言。「待てば直る」は他の失敗と質が違う唯一の情報であり、
 * 汎用文言に畳むとユーザーは連打を続けて状況を悪化させる
 * (bug-hunt 20260811-003230 F-2-02)。
 *
 * 文言はアプリ既存の 429 語彙に揃える
 * (InertiaErrorScreenStatus::TooManyRequests->message())。
 * **待ち時間の秒数は出さない**: Retry-After の解釈点は PHP 側の
 * App\Support\Http\RetryAfterSeconds 1 箇所に集約されており、
 * 表示のためだけにクライアント側へ 2 つ目の解釈点を作らない。
 */
const RATE_LIMITED_FAILURE = "リクエストが続けて行われました。少し時間をおいてからお試しください。";

/** options 取得の結果。**HTTP status を捨てない** (429 だけは呼び出し側で分岐が要る) */
type OptionsFetchResult =
    | { status: "ok"; options: JsonRecord }
    | { status: "rate_limited" }
    | { status: "error" };

async function fetchOptions(url: string): Promise<OptionsFetchResult> {
    try {
        const res = await requestJson(url);
        if (res.status === 429) return { status: "rate_limited" };
        if (!res.ok) return { status: "error" };
        const payload: unknown = await res.json();
        if (!isRecord(payload) || !isRecord(payload.options)) return { status: "error" };
        return { status: "ok", options: payload.options };
    } catch {
        return { status: "error" };
    }
}

async function assertPasskey(optionsUrl: string): Promise<PasskeyOutcome<JsonRecord>> {
    if (!isPasskeySupported()) return { status: "unsupported" };

    const fetched = await fetchOptions(optionsUrl);
    if (fetched.status === "rate_limited") {
        return { status: "failed", message: RATE_LIMITED_FAILURE };
    }
    if (fetched.status === "error") {
        return { status: "failed", message: "パスキーの認証を開始できませんでした。" };
    }

    const requestOptions = toRequestOptions(fetched.options);
    // ... 以降は現行どおり
}

export async function createPasskeyCredential(): Promise<PasskeyOutcome<JsonRecord>> {
    if (!isPasskeySupported()) return { status: "unsupported" };

    const fetched = await fetchOptions("/user/passkeys/options");
    if (fetched.status === "rate_limited") {
        return { status: "failed", message: RATE_LIMITED_FAILURE };
    }
    if (fetched.status === "error") {
        return { status: "failed", message: "パスキーの登録を開始できませんでした。" };
    }

    const creationOptions = toCreationOptions(fetched.options);
    // ... 以降は現行どおり
}

async function readErrorMessage(response: Response): Promise<string> {
    // 429 は POST 経路 (/passkeys/login, /passkeys/confirm) でも起きる。
    // Laravel の 429 本文は message="Too Many Requests" (英語) のため、
    // そのまま出すと汎用文言より悪化する。status を先に見る。
    if (response.status === 429) return RATE_LIMITED_FAILURE;

    try {
        // ... 現行どおり
    } catch { return GENERIC_FAILURE; }
}
```

### 設計判断

- **429 だけを分類する**（思考原則 2）。401/403/419/5xx/通信断はユーザー側の次の一手が
  変わらない（どれも「もう一度試すかパスワードでログイン」）。429 だけが
  「**待てば直る**」という行動指針を含む。
- **`PasskeyOutcome` の形を変えない**。呼び出し側 4 ファイル
  （`pages/Auth/Login.svelte` / `components/organisms/RecentAuthModal.svelte` /
  `components/features/auth/PasskeySection.svelte` / `pages/Auth/ConfirmRecentAuth.svelte`）は
  すでに `outcome.message` を描画しているため**無変更**で恩恵を受ける。
  `passkeys-import-isolation.test.ts` の allowlist にも変化はない。
- **代替手段（パスワードログイン）の案内文を足さない**。`/login` は同じ画面に
  パスワードフォームを常時表示している（画面が既に代替を提示している）。
  一方 `passkeys.ts` は登録 / step-up 確認からも呼ばれ、そこでは「パスワードでログイン」は
  誤案内になる。共有モジュールの文言は経路非依存に保つ。
- **サーバ側は 1 行も変えない**。throttle の閾値・limiter・route 付与はすべて不変。

### PHPStan適合チェック

該当なし（PHP の変更ゼロ）。`pnpm typecheck` / `pnpm lint` が検証対象。

### リスク

- `fetchOptions` の戻り型変更は**内部関数**（`export` していない）なので外部影響なし。
  呼び出し箇所は `assertPasskey` と `createPasskeyCredential` の 2 つだけ。
- 429 以外の 4xx を `{status:"error"}` に畳む現行挙動は維持（後退なし）。

---

## 施策 4: 再発防止 gate（enum ⇔ TS union 同期）

### 変更箇所

- `tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php`（新規）

### 現行の状況

`resources/js/types/billing.ts` の `BillingStateValue` は
「PHP: OnboardingBillingState の value 集合と exact 対」と**コメントで**書かれているだけで、
**機械的に固定されていない**。今回この union が dashboard の分岐にも効くようになるため、
既存基盤（`Tests\Support\TsUnionValues`、既に 3 テストが使用）で固定する。

### 追加コード

```php
<?php

declare(strict_types=1);

use App\Enums\Billing\OnboardingBillingState;
use Tests\Support\TsUnionValues;

/*
 * OnboardingBillingState (PHP enum) ⇔ resources/js/types/billing.ts の BillingStateValue
 * (TS literal union) の値集合同期 invariant。
 *
 * この union は /billing と /dashboard の**両方**で分岐に使われる (dashboard は
 * bug-hunt 20260811-003230 F-2-01 の是正で state 分岐になった)。case 追加が
 * TS 側の更新なしに通ると、新状態が画面で「どの分岐にも当たらない」= 無言の描画漏れになる。
 */

test('OnboardingBillingState の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    $enumValues = TsUnionValues::enumStringValues(OnboardingBillingState::cases());

    // 母集団 0 件での degenerate PASS を防ぐ (空 vs 空は一致してしまう)
    expect($enumValues)->not->toBeEmpty();

    expect(TsUnionValues::extract('resources/js/types/billing.ts', 'BillingStateValue'))
        ->toBe($enumValues);
});

test('billing.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => TsUnionValues::extract('resources/js/types/billing.ts', 'NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
```

> **closure の戻り型 `array` について（Codex design-review Round 1 の指摘への回答）**:
> PHPStan の解析対象は `phpstan.neon` の `paths: [app, config, database, routes]` であり
> **`tests/` は含まれない**。加えて、同じ書き方の
> `expect(fn (): array => TsUnionValues::extract(...))` が
> `tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php` に**既に存在し
> CI が緑**である。よって書き方を変える必要はなく、**先例と同一の形に揃える**
> （既存 3 つの sync gate と読み比べたときに差分がないほうが保守しやすい）。

### 検査が空振りしないことの保証

| 保証 | 手段 |
|---|---|
| 母集団 0 件で PASS しない | `expect($enumValues)->not->toBeEmpty()`（enum が空なら fail） |
| TS 側の抽出失敗を PASS にしない | `TsUnionValues::extract()` が `RuntimeException('degenerate PASS 防止')` を投げる |
| 負のコントロール | 存在しない union 名 `NoSuchUnionName` で **throw することを確認**する 2 本目のテスト |
| exact-fit | `toBe()`（ソート済み list の完全一致）。片側にだけ値があると fail |

### 画面側の網羅性（gate では守れないので型で守る）

`BILLING_CALLOUTS` を `satisfies Record<BillingStateValue, …>` にすることで、
state が増えたときに `pnpm typecheck` が
「Property 'xxx' is missing in type ... but required in type 'Record<BillingStateValue, …>'」で落ちる。
**これは Architecture テストではなく TypeScript コンパイラが担う不変条件**である。

---

## テスト計画

**共通前提**: Pest + `RefreshDatabase` グローバル適用（`tests/Pest.php`）+ `--parallel`。
個別 `DatabaseTransactions` は使わない。テストデータは Factory
（`createOrganizationWithOwner()` / `contractPaidPlan()` / `BillingCheckoutSession::factory()`）。
**バグ修正なので再現テストを先に書き、fail を確認してから実装に入る**（思考原則 5）。

### A. Pest Feature — `tests/Feature/DashboardTest.php`（既存を更新 + 新規追加）

既存 4 箇所の `has_billing_access` アサーションを `billing_state` に読み替える（**削除しない**）:

| 既存テスト名 | 変更後のアサーション |
|---|---|
| （L208 を含む既存テスト） | `->where('dashboard.billing.billing_state', 'active_free_plan')` |
| `Free (未契約) org: dashboard 200 + has_billing_access=true + 業務 route 開通` | 名前を `Free (grandfathered) org: dashboard 200 + billing_state=active_free_plan + 業務 route 開通` に更新し、`billing_state === 'active_free_plan'` を検証 |
| `有償契約 + 支払い不健全 org: has_billing_access=false + CTA 遷移先 200 (redirect loop なし)` | 名前を `… billing_state=expired_checkout + CTA 遷移先 200 (redirect loop なし)` に更新 |
| `有償契約 + past_due org: has_billing_access=true (cohort D。dunning 中も利用継続)` | 名前を `… billing_state=subscribed (cohort D。dunning 中も利用継続)` に更新 |

新規テスト:

> **fixture の必須注意（Codex design-review Round 1 の指摘）**: `createOrganizationWithOwner()` は
> **既定で `free_plan_code='personal'` を立てる**（`tests/Pest.php:173-180`）。
> `BillingAccess::state()` は entitled subscription → `free_plan_code` の順で判定するため、
> 既定のまま `BillingCheckoutSession` を作っても **`ActiveFreePlan` で先に返り
> pending / expired に到達しない**。未契約系の state を作るテストは
> **必ず `createOrganizationWithOwner(grandfatherFreePlan: false)` を使う**。

1. `test('新規登録相当 (未契約) org: billing_state=no_subscription (F-2-01 再現)')`
   ```php
   [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
   Project::factory()->forOrganization($organization)->create();
   $this->actingAs($owner)->get('/dashboard')->assertOk()
       ->assertInertia(fn (Assert $page) => $page
           ->where('dashboard.billing.billing_state', 'no_subscription')
           // 旧 prop を並走させていないこと (思考原則 3 の機械固定)
           ->missing('dashboard.billing.has_billing_access'));
   ```
   **これが F-2-01 の再現テスト**（実装前は `billing_state` 自体が存在せず fail する）。
2. `test('未契約 org の CTA 着地 /onboarding/checkout が 200 (行き先のない詰みを作らない)')`
   — 同じ org の owner で `/onboarding/checkout` が 200。
3. `test('未契約 org の非 manageBilling メンバーは CTA 着地で /billing-required へ捌かれる')`
   — member を作り `/onboarding/checkout` → `assertRedirect(route('onboarding.billing-required'))` →
   その着地が 200。**CTA をフロントで権限分岐しない判断の behavioral な裏付け**。
4. `test('live pending checkout org: billing_state=pending_checkout')`
   ```php
   [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
   BillingCheckoutSession::factory()->for($organization)->create(); // 既定 = live pending
   ```
5. `test('expired checkout org: billing_state=expired_checkout')`
   ```php
   [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
   BillingCheckoutSession::factory()->for($organization)->expired()->create();
   ```
   （**組織生成行を省略しない**。`grandfatherFreePlan: false` の取り違えがこのテストの空振りに直結する）

> **旧 prop の非残存**は 1 本目の `->missing('dashboard.billing.has_billing_access')` で固定する。
> これが無いと、実装者が `billing_state` を**追加**しただけで `has_billing_access` を残しても
> 全テストが緑になってしまう（並走の許容 = 思考原則 3 違反が検出できない）。

> 状態そのものの導出（cohort A〜I）は `tests/Feature/Billing/BillingAccessStateTest.php` が
> 既に固定しているので**重複させない**。ここで見るのは「dashboard props に載って出てくるか」だけ。

### B. Architecture — `tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php`（新規）

1. `test('OnboardingBillingState の PHP enum ⇔ TS union 値集合が一致する')`
2. `test('billing.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)')`

### C. vitest（component）— `tests/js/pages/Dashboard.test.ts`（既存を更新 + 追加）

`billingData` の既定を `billing_state: "subscribed"` にする。

| テスト名 | 検証内容 |
|---|---|
| `billing_state=no_subscription で「プランを選ぶ」callout が出る (F-2-01)` | `billing-callout-body` が「ご利用にはプランの選択が必要です。」を含み、**「お支払いが確認できない」を含まない**。CTA href が `/onboarding/checkout` |
| `billing_state=pending_checkout で「プラン選択へ」callout が出る` | 本文と CTA href `/onboarding/checkout` |
| `billing_state=expired_checkout で支払い確認 callout が出る (現行文言を維持)` | 既存 L248 のテストを rename して流用。文言と `/billing` を**そのまま固定** |
| `billing_state=subscribed で callout は出ない` | 既存 L269 を rename |
| `billing_state=active_free_plan で callout は出ない` | 新規（negative control。**2 状態とも非表示**であることを別々に固定する） |
| （既存）`disabled 属性を持つ要素が 1 つも存在しない` | 変更なしで通ること（禁止事項 8 の回帰防止） |

### D. vitest（lib）— `tests/js/lib/passkeys.test.ts`（追加）

| テスト名 | 検証内容 |
|---|---|
| `options が 429 のとき流量制限だと分かる文言を返す (F-2-02)` | fetch stub が `{ok:false, status:429}` → `loginWithPasskey()` が `{status:"failed", message: "リクエストが続けて行われました。少し時間をおいてからお試しください。"}` |
| `options が 500 のときは汎用文言のまま (429 だけを特別扱いする)` | **負のコントロール**。status 500 → 「パスキーの認証を開始できませんでした。」。429 判定が「あらゆる失敗」に反応していないことを示す |
| `登録 options が 429 のとき流量制限の文言を返す` | `createPasskeyCredential()` 経路 |
| `POST が 429 のとき本文の英語 message ではなく流量制限の文言を返す` | options は 200、`POST /passkeys/login` が `{status:429, json:{message:"Too Many Requests"}}` → 日本語の流量制限文言（**英語を出さない**ことの固定） |
| （既存）`options 取得失敗は failed (メッセージ付き)` | 変更なしで通ること |

### E. Browser lane（Chromium + WebKit の 2 レーン）— `tests/Browser/DashboardBillingCalloutTest.php`（新規）

UI を変えるため Browser lane に入れる（`docs/testing-browser.md` の契約: 実行時間を理由に
WebKit を落とさない）。

**テストは 1 本に統合する**（Codex design-review Round 2 の提案を採用）。同一 fixture・同一画面を
2 本に分けても検出力は変わらず、グローバルテストロック下の実行時間だけが増えるため。

| テスト名 | 検証内容 |
|---|---|
| `未契約 org の dashboard は「プランを選ぶ」callout を出し、旧「支払いが確認できない」文言を出さず、CTA でプラン選択に着地する` | `createOrganizationWithOwner(grandfatherFreePlan: false)` の owner で `/dashboard` を開き、同一セッション内で (1) `[data-testid="billing-callout-body"]` が「プランの選択が必要」文言、(2) ページ本文に旧文言「お支払いが確認できないため」が**含まれない**、(3) CTA クリック → `/onboarding/checkout` に到達（**行き先のない詰みがないことを実ブラウザで確認**）の 3 点を assert する |

> passkey 429 は Browser lane に**入れない**。10 req/分の枯渇を実ブラウザで作るのは
> グローバルテストロック下の実行時間を無駄に伸ばし、他レーンの limiter バケットにも影響する。
> 分岐は jsdom（vitest）で決定的に固定できる。

### F. mutation で赤化を確認する手順（実装後に必ず実施し、結果を PR に記載する）

| # | 変異 | 赤くなるべきもの |
|---|---|---|
| 1 | `DashboardService` で `billingState: OnboardingBillingState::ExpiredCheckout` を固定値で渡す | Feature の新規 1 / 4 / 5 と既存 3 本 |
| 2 | `BILLING_CALLOUTS.no_subscription` の body を `expired_checkout` と同じ文言にする | vitest C の 1 本目 + Browser E（統合 1 本の (1)(2) 両方） |
| 3 | `BILLING_CALLOUTS` から `pending_checkout` キーを削除する | `pnpm typecheck`（`satisfies Record<…>` が欠落を検出） |
| 4 | `billing.ts` の `"no_subscription"` を `"no_subscription_x"` に書き換える | Architecture B の 1 本目 |
| 5 | B の 2 本目で `NoSuchUnionName` を実在する `BillingStateValue` に差し替える | B の 2 本目（throw しなくなる = 負のコントロールが機能している証明） |
| 6 | `fetchOptions` の `if (res.status === 429)` 行を削除する | vitest D の 1・3 本目（2 本目=500 は緑のまま = 変異の位置が特定できる） |
| 7 | `readErrorMessage` の 429 早期 return を削除する | vitest D の 4 本目 |
| 8 | `BillingSummaryData::toArray()` の**返り値配列に** `'has_billing_access' => $this->billingState->grantsAccess()` を併記して並走させる（**PHPDoc だけ変えても Inertia payload は変わらず `missing()` は赤くならない**。実出力を変えること） | Feature 新規 1 本目の `->missing(...)`（旧 prop 残置の検出） |

各変異は**1 つずつ**入れて該当テストが赤くなることを確認し、**必ず revert する**。

### G. 検証コマンド（全 green でコミット）

AGENTS.md の canonical list（`VERIFICATION_COMMANDS` マーカー内）に **UI 変更なので
`composer test:browser` を足した**もの:

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` /
`composer test:browser`

> `pnpm *:packages` の 3 本は本設計が `packages/` を触らないため差分は出ないが、
> canonical list を部分引用して短くしない（`verification-commands-doc-sync.test.ts` が
> 同期を強制している list である）。

---

## 保証しないもの（誇張しない）

1. **文言が状態に対して「正しい」ことは機械で保証しない**。テストが見るのは文字列一致だけで、
   日本語として適切か・誤解を招かないかは人間のレビューでしか判定できない。
   本設計が機械化するのは「**状態が画面まで届くこと**」と「**状態が増えたら気付くこと**」の 2 つだけである。
2. **`expired_checkout` の多義性は残る**。この state は
   (a) 有償契約後の支払い不健全（canceled / paused / trial 終了 & PM 無 / PM 無 past_due）と
   (b) checkout session が期限切れ・失敗した未契約 の**両方**を指す。
   分離には `BillingAccess::state()` の分類（= 課金ゲートの判定ロジック）に触れる必要があり、
   今回の前提条件で禁じられている。**(b) のユーザーには引き続き支払い寄りの文言が出る**。
   ただし (b) は「一度は決済手続きを開始した」ユーザーであり、F-2-01 が問題にした
   「**一度も何もしていないのに支払い失敗を告げられる**」ケースではない。
3. **`subscribed` かつ dunning 中（past_due + PM 有り）には何も出ない**。これは今日と同じ挙動で、
   本設計では変えない（新機能になるため）。「支払い失敗を必ず知らせる」とは言わない。
4. **429 以外の失敗理由は依然として汎用文言**。401/403/419/5xx/通信断は区別しない。
5. **passkey 429 の分岐は `resources/js/lib/passkeys.ts` を通る経路にだけ効く**。
   同じ 429 でも Inertia 経路は T129 の Error 画面、api 面は `ApiExceptionRenderer` が担当で、
   本変更はそれらに一切触れない。「アプリの 429 表示が統一された」とは書かない。
6. **待ち時間（あと何秒で再試行できるか）は表示しない**。`Retry-After` はクライアントで読まない。
7. **enum ⇔ TS union gate が守るのは値集合だけ**。「その値に対応する分岐が画面に**ある**こと」は
   TypeScript の `satisfies` が守り、「**その分岐が正しく描画される**こと」は vitest が守る。
   3 つは別の層であり、どれか 1 つでは足りない。
8. **429 の「発生」は減らない**。本施策が変えるのは 429 到達**後**の文言だけである。
   passkey ボタンの連打抑止・cooldown 表示・in-flight 多重送信の抑制・
   流量制限に到達しにくくすることは**一切保証しない**（limiter と閾値は 1 文字も変えない）。
   `Login.svelte` は既に `passkeyProcessing` で二重実行を防いでいるが、
   「押すたびに 1 リクエスト」であること自体は現行どおりである。
9. **enum ⇔ TS union gate は「`export type X = "a" | "b";` の literal union」という
   書式に依存する**。`billing.ts` を `const array` 由来の派生 union
   （`typeof VALUES[number]`）へ書き換えると `TsUnionValues::extract()` は抽出できず、
   gate は **fail-closed で赤くなる**（silent PASS にはならない）が、
   その時は helper 側の更新が必要になる。書式を変えるときはここを一緒に見ること。
10. **bug-hunt 環境での再現確認は本設計の範囲外**。fake gateway 環境では
   Stripe 実挙動（PendingCheckout の実発生）を作れない（shard-3 の記録どおり）。
   `pending_checkout` の検証は Factory 由来の状態に対する props の検証にとどまる。

---

## ドキュメント更新

| ファイル | 更新内容 |
|---|---|
| `docs/template-divergence.md` L277 | ダッシュボード callout の記述を `has_billing_access` → `billing_state`（状態別 callout）に更新 |
| `docs/architecture.md` §サブスク契約 Checkout とオンボーディング着地 | dashboard callout が state 別になったこと（CTA の行き先はサーバが決める契約）を 2〜3 行追記 |

`docs/TODO-closed.md` の履歴記述は**書き換えない**（過去の記録である）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `BillingSummaryData` の wire 契約を変えるため、PHP DTO / TS 型 / Svelte / Feature / vitest / Browser を**同一 commit で揃える**必要がある（思考原則 3: 並走を残さない）。(2) 同時に走っている他 2 設計（`blocked-action-context` / `preview-render-parity`）は別ファイル群だが、`blocked-action-context` が `RequireActiveSubscription` 系の遮断文脈に触れる可能性があり、`Dashboard.svelte` / `docs/architecture.md` で競合しうる。worktree で独立させたほうが安全 |
| 競合リスク | `docs/architecture.md` への追記が他 2 設計と行競合しうる（マージ時に手で解消）。アプリコードの重複は現時点で確認できない（`Dashboard.svelte` / `passkeys.ts` / `BillingSummaryData` を触る設計は他に無い） |
| 施策の分割可否 | 施策 3（passkey）は PHP に触れず独立しているため、レビュー都合で別 commit にしてよい。施策 1+2+4 は不可分 |


---

## 実装者からの申し送り (設計からの逸脱 1 件)

設計は「`BILLING_CALLOUTS` を `Dashboard.svelte` の `<script>` に置き、
`satisfies Record<BillingStateValue, …>` により state 追加時に `pnpm typecheck` が落ちる」
としていた。mutation 3 (pending_checkout キーを削除) を実測したところ
**`pnpm typecheck` は緑のまま通った**。`pnpm typecheck` は `tsc --noEmit` であり
`tsc` は `.svelte` を型検査しないため (svelte-check は本リポジトリに無い)、
page 内の `satisfies` は一度も評価されない = 空振りだった。
`pnpm lint` (eslint) も `pnpm build` も落ちなかった。

そこで `BILLING_CALLOUTS` を `resources/js/types/dashboard.ts` へ移した
(`resources/js/types/manual.ts` の `VIDEO_MANUAL_STATUS_LABELS` /
`CAPTURE_NAVIGABLE_BY_STATUS` が「status 追加時のキー漏れをコンパイル時検出する」目的で
同じ場所に置かれている先例に揃えた)。移設後、同じ mutation で
`error TS1360: Property 'pending_checkout' is missing …` が出て赤くなることを実測した。

この判断が妥当か、他に良い置き場所があるかを評価してほしい。

---

## 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Dashboard/BillingSummaryData.php b/app/DataTransferObjects/Dashboard/BillingSummaryData.php
index 045f1cc..9a2debd 100644
--- a/app/DataTransferObjects/Dashboard/BillingSummaryData.php
+++ b/app/DataTransferObjects/Dashboard/BillingSummaryData.php
@@ -4,6 +4,8 @@
 
 namespace App\DataTransferObjects\Dashboard;
 
+use App\Enums\Billing\OnboardingBillingState;
+
 /**
  * チケット残高 + 容量 Quota (低残高警告と高使用率警告は別個のフラグ)。
  * TS 側 types/dashboard.ts の BillingSummary と対で保守する。
@@ -16,13 +18,23 @@ public function __construct(
         public int $storageUsedBytes,       // StorageUsageService::occupiedBytes
         public ?int $storageLimitBytes,     // QuotaService::limits[max_storage_bytes] (無制限は null)
         public ?int $storageUsagePercent,   // 0-100 に clamp (limit null なら null)
-        public bool $hasBillingAccess,      // BillingAccess::hasActiveAccess (billing entitlement。free 組織は true)
+        /**
+         * 課金状態 (BillingAccess::state)。**真偽値に潰さない** — 「未契約」と
+         * 「支払い不健全」は次の一手が違うため、画面が区別できる必要がある
+         * (bug-hunt 20260811-003230 F-2-01)。利用可否だけが要るときは
+         * `$billingState->grantsAccess()` で判定できる (情報量は真偽値より広い)。
+         */
+        public OnboardingBillingState $billingState,
     ) {}
 
     /**
+     * `value-of<OnboardingBillingState>` を使う (単なる `string` にしない)。
+     * 既存の `BillingFeedbackDto` の `@phpstan-type` が同じ書き方で、
+     * enum の値集合を PHPStan に伝えている先例である。
+     *
      * @return array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
      *   storage_limit_bytes: int|null, storage_usage_percent: int|null,
-     *   has_billing_access: bool}
+     *   billing_state: value-of<OnboardingBillingState>}
      */
     public function toArray(): array
     {
@@ -32,7 +44,7 @@ public function toArray(): array
             'storage_used_bytes' => $this->storageUsedBytes,
             'storage_limit_bytes' => $this->storageLimitBytes,
             'storage_usage_percent' => $this->storageUsagePercent,
-            'has_billing_access' => $this->hasBillingAccess,
+            'billing_state' => $this->billingState->value,
         ];
     }
 }
diff --git a/app/DataTransferObjects/Dashboard/DashboardPageData.php b/app/DataTransferObjects/Dashboard/DashboardPageData.php
index 03107bd..e44c314 100644
--- a/app/DataTransferObjects/Dashboard/DashboardPageData.php
+++ b/app/DataTransferObjects/Dashboard/DashboardPageData.php
@@ -4,6 +4,7 @@
 
 namespace App\DataTransferObjects\Dashboard;
 
+use App\Enums\Billing\OnboardingBillingState;
 use App\Enums\Dashboard\DashboardRole;
 use App\Enums\Dashboard\DashboardState;
 
@@ -47,7 +48,7 @@ public function __construct(
      *     pending_cuts_count: int}>,
      *   billing: array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
      *     storage_limit_bytes: int|null, storage_usage_percent: int|null,
-     *     has_billing_access: bool}|null}
+     *     billing_state: value-of<OnboardingBillingState>}|null}
      */
     public function toArray(): array
     {
diff --git a/app/Services/Dashboard/DashboardService.php b/app/Services/Dashboard/DashboardService.php
index a9056be..27e8eda 100644
--- a/app/Services/Dashboard/DashboardService.php
+++ b/app/Services/Dashboard/DashboardService.php
@@ -231,7 +231,9 @@ private function billingSummary(Organization $organization): BillingSummaryData
             storageUsedBytes: $used,
             storageLimitBytes: $limit,
             storageUsagePercent: $percent,
-            hasBillingAccess: $this->billingAccess->hasActiveAccess($organization),
+            // 真偽値へ潰さず state をそのまま渡す (画面が未契約と支払い不健全を区別するため)。
+            // hasActiveAccess() は state()->grantsAccess() の 1 行なのでクエリ本数は変わらない。
+            billingState: $this->billingAccess->state($organization),
         );
     }
 }
diff --git a/docs/architecture.md b/docs/architecture.md
index 7454eb4..f04a5e5 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -746,6 +746,23 @@ ## サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運
   (`pm_reuse_dispatched_at` が dispatch marker)。**webhook 同期処理から外向き Stripe API を
   撃たない**不変条件のため Job へ退避する。`AutoRechargeService::applyReusedPaymentMethod` は
   適格性先行の fail-closed (同意なし・失効・停止状態では Stripe にも DB にも触らない)。
+- **ダッシュボード callout は state 別 (T150)**: `/dashboard` の課金 callout は
+  `BillingSummaryData::$billingState` (`OnboardingBillingState` の 5 値) をそのまま props に
+  載せて分岐する。**真偽値に潰さない** — 一度も契約していない組織 (`no_subscription`) に
+  支払い失敗の文言を出していた回帰 (bug-hunt 20260811-003230 F-2-01) の原因が、
+  `hasBillingAccess` が「未契約」と「支払い不健全」を 1 bit に畳んでいたことだった。
+  - **CTA の行き先を画面が権限で分岐させない**: 未契約系 (`no_subscription` /
+    `pending_checkout`) の CTA は常に `/onboarding/checkout` を指し、契約済みなら
+    `billing.index`、`manageBilling` なしなら `onboarding.billing-required` へ
+    **サーバ (`OnboardingController::show` の離脱ガード) が捌く**。認可をフロントで
+    二重実装しないし、押せないボタンも作らない (禁止事項 8)。
+  - 値集合の同期は `OnboardingBillingStateTsSyncInvariantTest` (PHP enum ⇔
+    `resources/js/types/billing.ts` の `BillingStateValue`)、分岐の網羅は Svelte 側の
+    `satisfies Record<BillingStateValue, …>` (= `pnpm typecheck`)、描画は vitest と
+    Browser lane が担う。**3 層は別物でどれか 1 つでは足りない**。
+  - **判定ロジックは変えていない** (`BillingAccess::state()` / `grantsAccess()` は不変)。
+    `expired_checkout` が「有償契約後の支払い不健全」と「checkout 期限切れの未契約」を
+    ともに指す多義性は残る。
 
 ## チケットスポット購入 (T007) の運用契約
 
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index afaa4d0..82f0932 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -274,7 +274,7 @@ ## D9 ✅→解消 BillingAccess の entitlement 判定への書き換え (free
 |---|---|---|
 | BillingAccess::hasActiveAccess | `subscription('default')` が active/trialing のときのみ許可 (未契約 = fail-closed) | plan_code null (未契約 = 支払い不要 free tier) は許可 / plan_code 非 null (有償プラン契約状態) のみ active/trialing を要求 |
 | 遮断時の UX | billing へ redirect (理由提示なし) / JSON 402 「有効なサブスクリプションがありません」 | billing へ redirect + 理由 flash / JSON 402 (両経路とも「サブスクリプションのお支払いが確認できないため…」で統一) |
-| ダッシュボード callout | `has_active_subscription` (subscription 有無) | `has_billing_access` (billing entitlement) + 支払い方法確認 CTA |
+| ダッシュボード callout | `has_active_subscription` (subscription 有無) | `billing_state` (`OnboardingBillingState` の 5 値) による状態別 callout。未契約はプラン選択 CTA / 支払い不健全は支払い方法確認 CTA (真偽値に潰さない。T150) |
 
 ### なぜ正当な差分か (logic-driven)
 
diff --git a/resources/js/lib/passkeys.ts b/resources/js/lib/passkeys.ts
index d7f681f..4d9a498 100644
--- a/resources/js/lib/passkeys.ts
+++ b/resources/js/lib/passkeys.ts
@@ -37,6 +37,19 @@ export type PasskeyOutcome<T> =
 
 const GENERIC_FAILURE = "パスキーの処理に失敗しました。時間をおいて再度お試しください。";
 
+/**
+ * 429 専用の文言。「待てば直る」は他の失敗と質が違う唯一の情報であり、
+ * 汎用文言に畳むとユーザーは連打を続けて状況を悪化させる
+ * (bug-hunt 20260811-003230 F-2-02)。
+ *
+ * 文言はアプリ既存の 429 語彙に揃える
+ * (InertiaErrorScreenStatus::TooManyRequests->message())。
+ * **待ち時間の秒数は出さない**: Retry-After の解釈点は PHP 側の
+ * App\Support\Http\RetryAfterSeconds 1 箇所に集約されており、
+ * 表示のためだけにクライアント側へ 2 つ目の解釈点を作らない。
+ */
+const RATE_LIMITED_FAILURE = "リクエストが続けて行われました。少し時間をおいてからお試しください。";
+
 /** この端末で passkey ceremony を開始できるか (API の存在確認) */
 export function isPasskeySupported(): boolean {
     return (
@@ -132,16 +145,23 @@ async function requestJson(url: string, body?: JsonRecord): Promise<Response> {
     });
 }
 
-/** options endpoint から `{ options }` を取り出す (不正 shape は null) */
-async function fetchOptions(url: string): Promise<JsonRecord | null> {
+/** options 取得の結果。**HTTP status を捨てない** (429 だけは呼び出し側で分岐が要る) */
+type OptionsFetchResult =
+    | { status: "ok"; options: JsonRecord }
+    | { status: "rate_limited" }
+    | { status: "error" };
+
+/** options endpoint から `{ options }` を取り出す (不正 shape は error) */
+async function fetchOptions(url: string): Promise<OptionsFetchResult> {
     try {
         const res = await requestJson(url);
-        if (!res.ok) return null;
+        if (res.status === 429) return { status: "rate_limited" };
+        if (!res.ok) return { status: "error" };
         const payload: unknown = await res.json();
-        if (!isRecord(payload) || !isRecord(payload.options)) return null;
-        return payload.options;
+        if (!isRecord(payload) || !isRecord(payload.options)) return { status: "error" };
+        return { status: "ok", options: payload.options };
     } catch {
-        return null;
+        return { status: "error" };
     }
 }
 
@@ -268,12 +288,15 @@ function toRequestOptions(options: JsonRecord): PublicKeyCredentialRequestOption
 export async function createPasskeyCredential(): Promise<PasskeyOutcome<JsonRecord>> {
     if (!isPasskeySupported()) return { status: "unsupported" };
 
-    const options = await fetchOptions("/user/passkeys/options");
-    if (options === null) {
+    const fetched = await fetchOptions("/user/passkeys/options");
+    if (fetched.status === "rate_limited") {
+        return { status: "failed", message: RATE_LIMITED_FAILURE };
+    }
+    if (fetched.status === "error") {
         return { status: "failed", message: "パスキーの登録を開始できませんでした。" };
     }
 
-    const creationOptions = toCreationOptions(options);
+    const creationOptions = toCreationOptions(fetched.options);
     if (creationOptions === null) {
         return { status: "failed", message: GENERIC_FAILURE };
     }
@@ -349,12 +372,15 @@ export async function confirmWithPasskey(): Promise<PasskeyOutcome<void>> {
 async function assertPasskey(optionsUrl: string): Promise<PasskeyOutcome<JsonRecord>> {
     if (!isPasskeySupported()) return { status: "unsupported" };
 
-    const options = await fetchOptions(optionsUrl);
-    if (options === null) {
+    const fetched = await fetchOptions(optionsUrl);
+    if (fetched.status === "rate_limited") {
+        return { status: "failed", message: RATE_LIMITED_FAILURE };
+    }
+    if (fetched.status === "error") {
         return { status: "failed", message: "パスキーの認証を開始できませんでした。" };
     }
 
-    const requestOptions = toRequestOptions(options);
+    const requestOptions = toRequestOptions(fetched.options);
     if (requestOptions === null) {
         return { status: "failed", message: GENERIC_FAILURE };
     }
@@ -373,6 +399,11 @@ async function assertPasskey(optionsUrl: string): Promise<PasskeyOutcome<JsonRec
 
 /** サーバのエラー本文から表示可能なメッセージを取り出す (取れなければ既定文言) */
 async function readErrorMessage(response: Response): Promise<string> {
+    // 429 は POST 経路 (/passkeys/login, /passkeys/confirm) でも起きる。
+    // Laravel の 429 本文は message="Too Many Requests" (英語) のため、
+    // そのまま出すと汎用文言より悪化する。status を先に見る。
+    if (response.status === 429) return RATE_LIMITED_FAILURE;
+
     try {
         const payload: unknown = await response.json();
         if (!isRecord(payload)) return GENERIC_FAILURE;
diff --git a/resources/js/pages/Dashboard.svelte b/resources/js/pages/Dashboard.svelte
index f8b8ddc..ed3632e 100644
--- a/resources/js/pages/Dashboard.svelte
+++ b/resources/js/pages/Dashboard.svelte
@@ -22,7 +22,7 @@
     import PageContent from "@/components/templates/PageContent.svelte";
     import { formatBytes } from "@/lib/format-bytes";
     import type { SharedProps } from "@/lib/shared-props";
-    import type { DashboardProps } from "@/types/dashboard";
+    import { BILLING_CALLOUTS, type DashboardProps } from "@/types/dashboard";
     import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
 
     /**
@@ -43,6 +43,10 @@
     const isEditor = $derived(dashboard.role === "editor");
     const isShooter = $derived(dashboard.role === "shooter");
 
+    // null = callout を出さない。copy と CTA の正本は types/dashboard.ts
+    // (`.svelte` は tsc の検査対象外なので、キー漏れをコンパイル時に検出させるには
+    //  `.ts` に置く必要がある。VIDEO_MANUAL_STATUS_LABELS と同じ所在)
+    const billingCallout = $derived(billing ? BILLING_CALLOUTS[billing.billing_state] : null);
 </script>
 
 {#snippet shootingCard()}
@@ -205,13 +209,15 @@
                         />
                     </div>
 
-                    {#if !billing.has_billing_access}
+                    {#if billingCallout}
                         <Card class="mt-6" testId="billing-callout">
-                            <p class="text-body text-text">
-                                サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。
+                            <p class="text-body text-text" data-testid="billing-callout-body">
+                                {billingCallout.body}
                             </p>
                             <div class="mt-4">
-                                <Button href="/billing" inertia>お支払い方法を確認</Button>
+                                <Button href={billingCallout.cta.href} inertia>
+                                    {billingCallout.cta.label}
+                                </Button>
                             </div>
                         </Card>
                     {/if}
diff --git a/resources/js/types/dashboard.ts b/resources/js/types/dashboard.ts
index a4c71a5..3a8f361 100644
--- a/resources/js/types/dashboard.ts
+++ b/resources/js/types/dashboard.ts
@@ -2,6 +2,7 @@
  * ダッシュボードの Inertia props 型。
  * PHP 側 App\DataTransferObjects\Dashboard\DashboardPageData::toArray() と対で保守する。
  */
+import type { BillingStateValue } from "@/types/billing";
 import type { VideoManualStatus } from "@/types/manual";
 
 export type DashboardState = "no_organization" | "no_project" | "ready";
@@ -38,7 +39,8 @@ export interface BillingSummary {
     storage_used_bytes: number;
     storage_limit_bytes: number | null;
     storage_usage_percent: number | null;
-    has_billing_access: boolean;
+    /** PHP: BillingSummaryData::$billingState (OnboardingBillingState)。真偽値に潰さない */
+    billing_state: BillingStateValue;
 }
 
 export interface DashboardData {
@@ -57,3 +59,40 @@ export interface DashboardData {
 export interface DashboardProps {
     dashboard: DashboardData;
 }
+
+/**
+ * 課金状態ごとのダッシュボード callout。**null = callout を出さない**。
+ *
+ * 未契約 (no_subscription) と支払い不健全 (expired_checkout) は次の一手が違う
+ * (bug-hunt 20260811-003230 F-2-01: 新規登録直後の全ユーザーに支払い失敗の文言が出ていた)。
+ * `satisfies Record<BillingStateValue, …>` により、state が増えたときの
+ * キー漏れ = 無言の描画漏れを `pnpm typecheck` が検出する。
+ *
+ * **`.svelte` ではなくここに置く理由**: `pnpm typecheck` は `tsc --noEmit` であり
+ * `.svelte` を型検査しない。page 内に書くと `satisfies` が一度も評価されず、
+ * 「コンパイル時に守っているつもり」の空振りになる (T150 の mutation 3 で実測)。
+ * `types/manual.ts` の VIDEO_MANUAL_STATUS_LABELS / CAPTURE_NAVIGABLE_BY_STATUS と同じ所在。
+ *
+ * **CTA の行き先を権限で分岐させない**: /onboarding/checkout は契約済みなら /billing、
+ * manageBilling なしなら /billing-required へサーバが捌く (OnboardingController::show)。
+ * フロントで認可を再判定しないし、押せないボタンも作らない (禁止事項 8)。
+ */
+export const BILLING_CALLOUTS = {
+    subscribed: null,
+    active_free_plan: null,
+    no_subscription: {
+        body: "ご利用にはプランの選択が必要です。プランを選ぶと機能をご利用いただけます。",
+        cta: { label: "プランを選ぶ", href: "/onboarding/checkout" },
+    },
+    pending_checkout: {
+        body: "お支払いのお手続きが完了していません。ご利用を開始するには、プラン選択からお手続きください。",
+        cta: { label: "プラン選択へ", href: "/onboarding/checkout" },
+    },
+    expired_checkout: {
+        body: "サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。",
+        cta: { label: "お支払い方法を確認", href: "/billing" },
+    },
+} as const satisfies Record<
+    BillingStateValue,
+    { body: string; cta: { label: string; href: string } } | null
+>;
diff --git a/tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php b/tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php
new file mode 100644
index 0000000..e72c8b0
--- /dev/null
+++ b/tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\OnboardingBillingState;
+use Tests\Support\TsUnionValues;
+
+/*
+ * OnboardingBillingState (PHP enum) ⇔ resources/js/types/billing.ts の BillingStateValue
+ * (TS literal union) の値集合同期 invariant。
+ *
+ * この union は /billing と /dashboard の**両方**で分岐に使われる (dashboard は
+ * bug-hunt 20260811-003230 F-2-01 の是正で state 分岐になった)。case 追加が
+ * TS 側の更新なしに通ると、新状態が画面で「どの分岐にも当たらない」= 無言の描画漏れになる。
+ */
+
+test('OnboardingBillingState の PHP enum ⇔ TS union 値集合が一致する', function (): void {
+    $enumValues = TsUnionValues::enumStringValues(OnboardingBillingState::cases());
+
+    // 母集団 0 件での degenerate PASS を防ぐ (空 vs 空は一致してしまう)
+    expect($enumValues)->not->toBeEmpty();
+
+    expect(TsUnionValues::extract('resources/js/types/billing.ts', 'BillingStateValue'))
+        ->toBe($enumValues);
+});
+
+test('billing.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
+    expect(fn (): array => TsUnionValues::extract('resources/js/types/billing.ts', 'NoSuchUnionName'))
+        ->toThrow(RuntimeException::class, 'degenerate PASS');
+});
diff --git a/tests/Browser/DashboardBillingCalloutTest.php b/tests/Browser/DashboardBillingCalloutTest.php
new file mode 100644
index 0000000..3bc726b
--- /dev/null
+++ b/tests/Browser/DashboardBillingCalloutTest.php
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Project;
+use Pest\Browser\Api\PendingAwaitablePage;
+
+/*
+|--------------------------------------------------------------------------
+| ダッシュボード課金 callout の状態別出し分け (bug-hunt 20260811-003230 F-2-01)
+|--------------------------------------------------------------------------
+|
+| 一度も契約していない組織 (NoSubscription) のダッシュボードに
+| 「サブスクリプションのお支払いが確認できないため…」が出ていた。支払い失敗と同じ文言で、
+| S1 登録ファネルを通る全新規ユーザーが最初に目にする。原因は props が
+| hasBillingAccess の真偽値で「未契約」と「支払い不健全」を潰していたこと。
+|
+| 実ブラウザで再現された finding なので、実ブラウザで是正を固定する
+| (Chromium + WebKit の 2 レーンが契約。docs/testing-browser.md)。
+|
+| テストは 1 本に統合する: 同一 fixture・同一画面を分けても検出力は変わらず、
+| グローバルテストロック下の実行時間だけが増えるため。
+|
+| fixture 注意: createOrganizationWithOwner() は既定で free_plan_code='personal' を立て
+| ActiveFreePlan (= callout なし) になる。grandfatherFreePlan: false が必須。
+|
+| 実行: composer test:browser。前提: pnpm build 済み。
+*/
+
+/**
+ * Inertia のクライアント遷移が完了するまで待つ。
+ *
+ * `assertPathIs()` は現在 URL を**即座に**読むだけで待たないため、click 直後に呼ぶと
+ * 遷移前の path を見て落ちる。`script()` も自動待機しないので、ここで明示的に回す
+ * (script() 呼び出しが in-process サーバの event loop を回す = 他の Browser テストと同じ流儀)。
+ */
+function waitForPath(PendingAwaitablePage $page, string $expected, int $attempts = 40): void
+{
+    for ($i = 0; $i < $attempts; $i++) {
+        if ($page->script('window.location.pathname') === $expected) {
+            return;
+        }
+
+        usleep(100_000);
+    }
+}
+
+test('未契約 org の dashboard は「プランを選ぶ」callout を出し、旧「支払いが確認できない」文言を出さず、CTA でプラン選択に着地する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner);
+
+    $page = visit('/dashboard');
+
+    // (1) 未契約に対しては「プランの選択が必要」— 支払い失敗ではない。
+    //     locator 経由なので hydration 完了まで自動待機する (以降の非待機 API の前提になる)
+    $body = $page->text('[data-testid="billing-callout-body"]');
+    expect($body)->toContain('ご利用にはプランの選択が必要です。');
+
+    // (2) 旧文言がページのどこにも可視で出ていないこと (F-2-01 の本体)
+    $page->assertDontSee('お支払いが確認できないため');
+
+    // (3) CTA が行き先のない詰みを作らないこと。着地先はサーバが決める
+    //     (manageBilling 保持者 = owner なので /onboarding/checkout に留まる)
+    $page->click('[data-testid="billing-callout"] a');
+    waitForPath($page, '/onboarding/checkout');
+    $page->assertPathIs('/onboarding/checkout');
+});
diff --git a/tests/Feature/DashboardTest.php b/tests/Feature/DashboardTest.php
index 64fb10b..7076906 100644
--- a/tests/Feature/DashboardTest.php
+++ b/tests/Feature/DashboardTest.php
@@ -6,6 +6,7 @@
 use App\Enums\OrganizationRole;
 use App\Enums\ProjectRole;
 use App\Models\AnalysisJob;
+use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Category;
 use App\Models\Cut;
 use App\Models\Project;
@@ -205,7 +206,7 @@ function adoptTakeFor(Cut $cut): Take
             ->where('dashboard.billing.ticket_balance', 10)
             ->where('dashboard.billing.is_low_balance', false)
             ->where('dashboard.billing.storage_used_bytes', 0)
-            ->where('dashboard.billing.has_billing_access', true));
+            ->where('dashboard.billing.billing_state', 'active_free_plan'));
 });
 
 test('残高/容量: threshold 未満で is_low_balance=true', function (): void {
@@ -409,19 +410,19 @@ function adoptTakeFor(Cut $cut): Take
     expect($member->fresh()->current_organization_id)->toBe($organization->id);
 });
 
-test('Free (未契約) org: dashboard 200 + has_billing_access=true + 業務 route 開通', function (): void {
+test('Free (grandfathered) org: dashboard 200 + billing_state=active_free_plan + 業務 route 開通', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     Project::factory()->forOrganization($organization)->create();
 
     $this->actingAs($owner)->get('/dashboard')
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
-            ->where('dashboard.billing.has_billing_access', true));
+            ->where('dashboard.billing.billing_state', 'active_free_plan'));
 
     $this->actingAs($owner)->get('/projects')->assertOk();
 });
 
-test('有償契約 + 支払い不健全 org: has_billing_access=false + CTA 遷移先 200 (redirect loop なし)', function (): void {
+test('有償契約 + 支払い不健全 org: billing_state=expired_checkout + CTA 遷移先 200 (redirect loop なし)', function (): void {
     // 有償 org は grandfatherFreePlan: false (backfill 対象は plan_code/free_plan_code とも
     // NULL の org に閉じるため、有償 org に grandfather マーカーが付くのは非現実な fixture。
     // 付くと state() の ActiveFreePlan が支払い健全性判定を覆い隠す)
@@ -434,14 +435,14 @@ function adoptTakeFor(Cut $cut): Take
     $this->actingAs($owner)->get('/dashboard')
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
-            ->where('dashboard.billing.has_billing_access', false));
+            ->where('dashboard.billing.billing_state', 'expired_checkout'));
 
     // CTA 遷移先は課金ゲート外 (redirect loop なし不変条件)
     $this->actingAs($owner)->get('/purchase-tickets')->assertOk();
     $this->actingAs($owner)->get('/billing')->assertOk();
 });
 
-test('有償契約 + past_due org: has_billing_access=true (cohort D。dunning 中も利用継続)', function (): void {
+test('有償契約 + past_due org: billing_state=subscribed (cohort D。dunning 中も利用継続)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     contractPaidPlan($organization, status: 'past_due');
     Project::factory()->forOrganization($organization)->create();
@@ -449,7 +450,67 @@ function adoptTakeFor(Cut $cut): Take
     $this->actingAs($owner)->get('/dashboard')
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
-            ->where('dashboard.billing.has_billing_access', true));
+            ->where('dashboard.billing.billing_state', 'subscribed'));
+});
+
+/*
+ * F-2-01 (bug-hunt 20260811-003230): 一度も契約していない組織に「支払いが確認できない」
+ * 文言が出ていた。原因は props が hasBillingAccess の真偽値で「未契約」と「支払い不健全」を
+ * 潰していたこと。ここで固定するのは「state が画面まで届くこと」であり、state 導出そのものは
+ * tests/Feature/Billing/BillingAccessStateTest.php が担当する (重複させない)。
+ *
+ * fixture 注意: createOrganizationWithOwner() は既定で free_plan_code='personal' を立てる
+ * (= ActiveFreePlan で先に返る)。未契約系 state を作るテストは grandfatherFreePlan: false 必須。
+ */
+test('新規登録相当 (未契約) org: billing_state=no_subscription (F-2-01 再現)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.billing.billing_state', 'no_subscription')
+            // 旧 prop を並走させていないこと (思考原則 3 の機械固定)
+            ->missing('dashboard.billing.has_billing_access'));
+});
+
+test('未契約 org の CTA 着地 /onboarding/checkout が 200 (行き先のない詰みを作らない)', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    $this->actingAs($owner)->get('/onboarding/checkout')->assertOk();
+});
+
+test('未契約 org の非 manageBilling メンバーは CTA 着地で /billing-required へ捌かれる', function (): void {
+    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    // current org は保護キーのため forceFill (attachOrganizationMember は設定しない)
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    // CTA の行き先はサーバが決める (フロントで認可を二重実装しない) ことの behavioral な裏付け
+    $this->actingAs($member)->get('/onboarding/checkout')
+        ->assertRedirect(route('onboarding.billing-required'));
+
+    $this->actingAs($member)->get('/billing-required')->assertOk();
+});
+
+test('live pending checkout org: billing_state=pending_checkout', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    BillingCheckoutSession::factory()->for($organization)->create(); // 既定 = live pending
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.billing.billing_state', 'pending_checkout'));
+});
+
+test('expired checkout org: billing_state=expired_checkout', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    BillingCheckoutSession::factory()->for($organization)->expired()->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.billing.billing_state', 'expired_checkout'));
 });
 
 test('ゲストは /login へ redirect (既存挙動維持)', function (): void {
diff --git a/tests/js/lib/passkeys.test.ts b/tests/js/lib/passkeys.test.ts
index 21a5c7b..df7d9d4 100644
--- a/tests/js/lib/passkeys.test.ts
+++ b/tests/js/lib/passkeys.test.ts
@@ -166,6 +166,72 @@ describe("ceremony の分岐", () => {
         expect(outcome.status).toBe("failed");
     });
 
+    /*
+     * F-2-02 (bug-hunt 20260811-003230): 429 が汎用の「開始できませんでした」に畳まれ、
+     * 流量制限だと分からないためユーザーが連打して状況を悪化させていた。
+     * 429 だけが「待てば直る」という他と質の違う行動指針を持つので、それだけを区別する。
+     */
+    const RATE_LIMITED_FAILURE =
+        "リクエストが続けて行われました。少し時間をおいてからお試しください。";
+
+    it("options が 429 のとき流量制限だと分かる文言を返す (F-2-02)", async () => {
+        stubWebAuthnApis();
+        fetchMock.mockResolvedValue({ ok: false, status: 429, json: () => Promise.resolve({}) });
+
+        await expect(loginWithPasskey()).resolves.toEqual({
+            status: "failed",
+            message: RATE_LIMITED_FAILURE,
+        });
+    });
+
+    it("options が 500 のときは汎用文言のまま (429 だけを特別扱いする)", async () => {
+        // negative control: 429 判定が「あらゆる失敗」に反応していないことを示す
+        stubWebAuthnApis();
+        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
+
+        await expect(loginWithPasskey()).resolves.toEqual({
+            status: "failed",
+            message: "パスキーの認証を開始できませんでした。",
+        });
+    });
+
+    it("登録 options が 429 のとき流量制限の文言を返す", async () => {
+        stubWebAuthnApis();
+        fetchMock.mockResolvedValue({ ok: false, status: 429, json: () => Promise.resolve({}) });
+
+        await expect(createPasskeyCredential()).resolves.toEqual({
+            status: "failed",
+            message: RATE_LIMITED_FAILURE,
+        });
+    });
+
+    it("POST が 429 のとき本文の英語 message ではなく流量制限の文言を返す", async () => {
+        const credentials = stubWebAuthnApis();
+        fetchMock.mockImplementation((url: string) =>
+            url.endsWith("/options")
+                ? Promise.resolve(optionsResponse(loginOptions))
+                : Promise.resolve({
+                      ok: false,
+                      status: 429,
+                      json: () => Promise.resolve({ message: "Too Many Requests" }),
+                  }),
+        );
+
+        const credential = Object.create(
+            (window.PublicKeyCredential as unknown as { prototype: object }).prototype,
+        ) as Record<string, unknown>;
+        credential.id = "cred-id";
+        credential.rawId = new Uint8Array([1, 2, 3, 4]).buffer;
+        credential.type = "public-key";
+        credential.response = {};
+        credentials.get.mockResolvedValue(credential);
+
+        await expect(loginWithPasskey()).resolves.toEqual({
+            status: "failed",
+            message: RATE_LIMITED_FAILURE,
+        });
+    });
+
     it("options 取得は Accept: application/json を送る", async () => {
         stubWebAuthnApis();
         fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
diff --git a/tests/js/pages/Dashboard.test.ts b/tests/js/pages/Dashboard.test.ts
index d22597f..161a24c 100644
--- a/tests/js/pages/Dashboard.test.ts
+++ b/tests/js/pages/Dashboard.test.ts
@@ -15,7 +15,7 @@ function billingData(overrides: Partial<BillingSummary> = {}): BillingSummary {
         storage_used_bytes: 250 * 1024 * 1024,
         storage_limit_bytes: 1024 * 1024 * 1024,
         storage_usage_percent: 25,
-        has_billing_access: true,
+        billing_state: "subscribed",
         ...overrides,
     };
 }
@@ -245,11 +245,46 @@ describe("Dashboard", () => {
         expect(screen.getByTestId("stat-tickets")).toHaveTextContent("残高が少なくなっています");
     });
 
-    it("has_billing_access=false で billing callout が出る (支払い確認文言 + /billing CTA)", () => {
+    it("billing_state=no_subscription で「プランを選ぶ」callout が出る (F-2-01)", () => {
         render(Dashboard, {
             props: {
                 dashboard: dashboardData({
-                    billing: billingData({ has_billing_access: false }),
+                    billing: billingData({ billing_state: "no_subscription" }),
+                }),
+            },
+        });
+
+        // 一度も契約していないユーザーに支払い失敗を告げないこと (F-2-01 の本体)
+        const body = screen.getByTestId("billing-callout-body");
+        expect(body).toHaveTextContent("ご利用にはプランの選択が必要です。");
+        expect(body).not.toHaveTextContent("お支払いが確認できない");
+        expect(screen.getByText("プランを選ぶ").getAttribute("href")).toMatch(
+            /\/onboarding\/checkout$/,
+        );
+    });
+
+    it("billing_state=pending_checkout で「プラン選択へ」callout が出る", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    billing: billingData({ billing_state: "pending_checkout" }),
+                }),
+            },
+        });
+
+        expect(screen.getByTestId("billing-callout-body")).toHaveTextContent(
+            "お支払いのお手続きが完了していません。",
+        );
+        expect(screen.getByText("プラン選択へ").getAttribute("href")).toMatch(
+            /\/onboarding\/checkout$/,
+        );
+    });
+
+    it("billing_state=expired_checkout で billing callout が出る (支払い確認文言 + /billing CTA)", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    billing: billingData({ billing_state: "expired_checkout" }),
                 }),
             },
         });
@@ -266,11 +301,24 @@ describe("Dashboard", () => {
         );
     });
 
-    it("has_billing_access=true で billing callout は出ない", () => {
+    it("billing_state=subscribed で billing callout は出ない", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    billing: billingData({ billing_state: "subscribed" }),
+                }),
+            },
+        });
+
+        expect(screen.queryByTestId("billing-callout")).toBeNull();
+    });
+
+    it("billing_state=active_free_plan で billing callout は出ない", () => {
+        // negative control: 非表示の 2 状態を別々に固定する (片方だけの取りこぼしを防ぐ)
         render(Dashboard, {
             props: {
                 dashboard: dashboardData({
-                    billing: billingData({ has_billing_access: true }),
+                    billing: billingData({ billing_state: "active_free_plan" }),
                 }),
             },
         });

```

---

## mutation evidence (設計 §テスト計画 F の全 8 変異を実施した記録)

# T150 mutation evidence

詳細設計 `devnotes/20260811-0146-state-aware-messaging/detailed-design.md` §テスト計画 F の
mutation 手順を 1 つずつ実施した記録。**入れた変異はすべて revert 済み**
(`git diff --stat` で施策の差分だけが残っていることを確認済み)。

**設計の予測と実測がずれた箇所は辻褄を合わせず、ずれとして記録する**。

| # | 変異 | 設計の予測 | 実測 | 判定 |
|---|---|---|---|---|
| 1 | `DashboardService` で `billingState:` を `OnboardingBillingState::ExpiredCheckout` 固定値にする | Feature 新規 1/4/5 と既存 3 本 (計 6) | **5 本 red** (下記) | 予測とずれ (下記) |
| 2 | `BILLING_CALLOUTS.no_subscription.body` を `expired_checkout` と同文言にする | vitest C 1 本目 + Browser E の (1)(2) 両方 | vitest **1 本 red**、Browser **1 本 red (line 59 = (1) で停止)** | 予測とずれ (下記) |
| 3 | `BILLING_CALLOUTS` から `pending_checkout` キーを削除 | `pnpm typecheck` が落ちる | **落ちなかった** (実装を移設して再実測 → 落ちるようになった) | **設計の誤り**。下記「発見」 |
| 4 | `billing.ts` の `"no_subscription"` を `"no_subscription_x"` に書換 | Architecture B 1 本目 | 1 本目のみ red (差分表示付き) | 予測どおり |
| 5 | B 2 本目の `NoSuchUnionName` を実在の `BillingStateValue` に差替 | B 2 本目 | 2 本目のみ red (`RuntimeException not thrown`) | 予測どおり |
| 6 | `fetchOptions` の `if (res.status === 429)` 行を削除 | vitest D の 1・3 本目 (2 本目=500 は緑のまま) | 1・3 本目のみ red、**500 の負のコントロールは緑のまま** | 予測どおり |
| 7 | `readErrorMessage` の 429 早期 return を削除 | vitest D の 4 本目 | 4 本目のみ red | 予測どおり |
| 8 | `BillingSummaryData::toArray()` の返り値に `'has_billing_access' => $this->billingState->grantsAccess()` を併記 | Feature 新規 1 本目の `->missing(...)` | **その 1 本だけ red** (30 passed / 1 failed) | 予測どおり |

---

## 変異 1 のずれ (予測 6 本 → 実測 5 本)

red になったのは:

1. `残高/容量: grant 済み残高・低残高フラグ・使用率が正しい` (`active_free_plan` 期待)
2. `Free (grandfathered) org: … billing_state=active_free_plan …`
3. `有償契約 + past_due org: billing_state=subscribed …`
4. `新規登録相当 (未契約) org: billing_state=no_subscription (F-2-01 再現)`
5. `live pending checkout org: billing_state=pending_checkout`

green のまま残ったのは **`expired_checkout` を期待する 2 本**
(`有償契約 + 支払い不健全 org: …expired_checkout…` / `expired checkout org: …expired_checkout…`)。
変異が `ExpiredCheckout` を固定値で返す以上、この 2 本が緑なのは論理的に正しい。
設計の「既存 3 本」という数え方が実際の母集団 (既存 4 本 + 新規 3 本 = 7、うち 2 本は
expired 期待で不感) と噛み合っていなかっただけで、**検出力の不足ではない**。
変異の位置が特定できるという意味では、むしろ 2 本が緑で残る方が情報量が多い。

## 変異 2 のずれ (Browser の (2) は評価されない)

Browser lane は 1 本のテストに 3 つの assert を直列に置いているため、
(1) 「プランの選択が必要」の assert が先に失敗して**そこでテストが終わる**
(実測: `tests/Browser/DashboardBillingCalloutTest.php:59` で失敗)。
(2) の `assertDontSee('お支払いが確認できないため')` は**同じ実行では評価されない**。

設計の「(1)(2) 両方が赤くなる」は 1 テスト = 1 失敗という pest の挙動を織り込んでいない。
検出そのものは効いている (この変異で Browser lane は確実に赤くなる) ので実装は変えていない。
「(2) が独立に効くこと」は別変異でしか示せないため、**ここでは示せていない**と記録する。

## 変異 3 で見つかった設計の誤り (実装を変更した)

設計は「`satisfies Record<BillingStateValue, …>` により state が増えたら `pnpm typecheck` が
落ちる」としていたが、`BILLING_CALLOUTS` を `Dashboard.svelte` の `<script>` に置いた状態で
`pending_checkout` キーを削除しても **`pnpm typecheck` は緑のまま通った**。

原因: `pnpm typecheck` は `tsc --noEmit` であり、`tsc` は `.svelte` を型検査しない
(`tsconfig.json` の `include` に `resources/js/**/*.svelte` はあるが、tsc 単体では
Svelte SFC をパースできず黙って対象外になる)。`svelte-check` は本リポジトリに無い。
`pnpm lint` (eslint) も `pnpm build` (Svelte コンパイラ) も落ちなかった
(実測: `LINT_RC=0` / `BUILD_RC=0`)。

つまり **page 内に置いた `satisfies` は一度も評価されない** = 「コンパイル時に守っている
つもり」の空振りだった。設計の「保証しないもの 7」が挙げる 3 層のうち 1 層が実在しない状態。

**対応**: `BILLING_CALLOUTS` を `resources/js/types/dashboard.ts` へ移した
(`types/manual.ts` の `VIDEO_MANUAL_STATUS_LABELS` / `CAPTURE_NAVIGABLE_BY_STATUS` が
「status 追加時のキー漏れをコンパイル時検出する」目的で同じ場所に置かれている先例に揃えた)。
移設後に同じ変異を再実測したところ、狙いどおり赤くなった:

```
resources/js/types/dashboard.ts(91,12): error TS1360:
  Property 'pending_checkout' is missing in type '…'
  but required in type 'Record<BillingStateValue, … | null>'.
TYPECHECK_RC=2
```

なお移設**前**の状態でも vitest は赤くなっていた
(`billing_state=pending_checkout で「プラン選択へ」callout が出る` の 1 本)。
これは今ある 5 状態すべてに vitest を書いてあるからで、**将来 enum に case が増えたときは
その case の vitest が存在しない**ため、移設しなければ描画漏れは無言で通っていた。
Architecture gate は `billing.ts` の更新までしか強制できず、`BILLING_CALLOUTS` の
キー追加までは強制しない。移設によって初めて設計が意図した 3 層になった。

これは設計からの逸脱だが、**設計が主張していた不変条件を実際に成立させるための変更**である
(Atomic Design 上は component 層を増やしておらず、page 内の分岐は `$derived` 1 行のまま)。


---

## テスト結果 (全レーン green)

```
composer phpstan            : [OK] No errors (884 files)
composer test               : 4408 tests, 4406 passed, 2 skipped, 0 failed (18925 assertions)
vendor/bin/pint --test      : passed (app tests docs)
pnpm lint                   : passed (eslint resources/js)
pnpm typecheck              : passed (tsc --noEmit)
pnpm test                   : 130 files, 1306 tests passed
pnpm build                  : built
pnpm typecheck:packages     : passed
pnpm build:packages         : passed
pnpm test:packages          : 10 files, 106 tests passed
composer test:browser       : chromium 20 passed / 3 skipped, webkit 20 passed / 3 skipped
```

**注記**: `vendor/bin/pint --test` をリポジトリ全体で走らせると
`devnotes/20260811-0146-blocked-action-context/probe-middleware.php` が fail するが、
これは **main に既存の失敗**であり (main HEAD でも同じ fail を実測)、
並走中の別 TODO の設計ディレクトリのファイルなので本 diff では触っていない。

---

## design system 参照

### DESIGN.md 抜粋 (Card / Alert — 本 diff が触る callout の様式判断に関係する)

```
`bordered` は tone 色 border を atom 内で付与する(呼び出し側から border を足さない)。
左アイコン 1 つを snippet で受け、size/色の責務は Badge 内 wrapper に閉じる。
小コントロールなので `rounded-sm`。size: `sm`(既定)/ `md`。

### Card

実装: `components/atoms/Card.svelte`。浮いた要素の基本サーフェス
(`bg-surface border border-border rounded-lg`。影を使わず明度差 + 1px border で階層を
表現する — §Elevation & Depth)。padding: `none`(table/list 等を内包し内側で個別に
padding を制御する箱用)/ `sm` / `md`(既定)/ `lg`。

### Spinner

実装: `components/atoms/Spinner.svelte`。LoaderCircle(@lucide/svelte)+ `animate-spin`。
色は currentColor 継承(置かれた文脈の文字色に従う)。既定は装飾扱い(`aria-hidden`)で、
単独のローディング表示に使うときだけ `label` を渡す(`role="status"` + sr-only で
読み上げ)。size: `sm` / `md`(既定)/ `lg` / `xl`。

### TextLink

実装: `components/atoms/TextLink.svelte`(仕様の真実は `TextLink.types.ts`)。
リンク風 `<a>` / `<button>` の手書きは禁止(§Do's and Don'ts)、本 atom を使う。
3 モードの discriminated union: (a) `href` のみ = Inertia Link(SPA 遷移)、
(b) `href` + `external` = ネイティブ `<a>` + 別タブ + `rel="noopener noreferrer"` +
末尾 ExternalLink アイコン(`icon` で差し替え可)、(c) `onclick` のみ = リンク風
`<button type="button">`。様式は `text-primary` + 下線(hover で下線が濃くなる)で 3 モード共通。

### Toggle

実装: `components/atoms/Toggle.svelte`(仕様の真実は `Toggle.types.ts`)。
オン/オフを**即時反映**する設定スイッチ(ネイティブ `<button>` + `role="switch"` +
実装: `components/atoms/Alert.svelte`。ページ内に常在するインライン通知ボックス
(一時通知は Toast、フィールド単位のエラーは FormField/FormError を使う)。

- type: `success` / `warning` / `danger` / `info`(info は primary を流用。Toast と同じ規約)
- 配色: ボーダー=状態色、見出し(title 任意)=状態色、本文=`text-text`、背景=`bg-surface`。
  テーマ色を面塗りに使わない。中間 box なので `rounded-md`
- `action` snippet(本文下の CTA)、`dismissible` + `onDismiss`(右上の X)を持つ
- a11y: **danger のみ `role="alert"`(assertive)**、他は `role="status"`(polite)
- **非フィールド起因の操作失敗は Alert**。フォームのフィールドに紐づかない失敗
  (WebAuthn ceremony 失敗・端末非対応・ネットワーク失敗など)は、操作したその場に残る
  Alert で出す。FormError は**フィールド単位**のエラー専用であり、Toast は「一時通知」なので、
  押した直後に読ませたい失敗理由を画面外(上部中央)へ飛ばさない

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+

```

### 触れた atomic ディレクトリ

本 diff は `resources/js/components/` 配下を **1 ファイルも触っていない**
(新規 atom / molecule / organism ゼロ)。触ったのは
`resources/js/pages/Dashboard.svelte` (pages 層) /
`resources/js/types/dashboard.ts` / `resources/js/types/billing.ts` (型のみ) /
`resources/js/lib/passkeys.ts` (lib) である。

`Dashboard.svelte` が import している component:
- atoms: `Badge` / `Button` / `Card` / `TextLink`
- molecules: `EmptyState` / `PageHeader` / `StatCard`
- templates: `AppLayout` / `PageContainer` / `PageContent`

既存 `atoms/` 一覧: Alert, Avatar, Badge, Button, Card, Checkbox, FormError, Input,
Select, Spinner, TextLink, Textarea, Toggle, icons/
既存 `molecules/` 一覧: ApiKeyTabNav, Breadcrumb, CodeSnippet, DangerZone, Divider,
EmptyState, FormField, NotificationBell, PageHeader, PageHeaderSection, Pagination,
PasswordInput, PendingInvitationsNotice, PricingPlanCard, RecentAuthRecoveryNotice,
StatCard, Tabs
