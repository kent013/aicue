## アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則 (AGENTS.md)

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。**実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、`->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける**
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## 思考原則 — 全議論に適用 (app-codex-review スキル規定)

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pest テストフレームワーク (RefreshDatabase はグローバル適用・--parallel 実行)
- DTO + JsonResource パターン
- Laratrust RBAC (Organization → Team → Project 階層)

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン、API)
3. PHPStan level 10 適合性 (型安全性、generics、Assert 使用)
4. テスト計画の網羅性 (各施策に Pest テスト、RefreshDatabase グローバル適用に従う)
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性 (TypeScript 型定義、API Resource、テストが変更対象に含まれているか)
9. セキュリティ (認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件)
10. DESIGN.md 準拠 (UI/frontend 変更を含む): `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design 準拠: `resources/js/components/` の atoms/molecules/organisms/templates の責務分離に沿った配置か。アイコンは Lucide 前提で SVG 直書きを新設していないか

【この設計に固有の前提 — レビュー時に必ず踏まえること】
- 実ブラウザで再現された bug-hunt finding 2 件 (F-2-01 Medium / F-2-02 Low) への対応であり、推測ではない。
- **課金ゲートの判定ロジック (BillingAccess / RequireActiveSubscription / OnboardingBillingState の case 集合) は変更しない**。
  流量制限の閾値も変更しない。これは前提条件であり、これに触れる修正提案は採用できない。
- 過剰に作らないこと (思考原則 2) が明示的に要求されている。一般化・共通機構の追加を提案する場合は
  「それが今この 2 件の finding を閉じるために必要か」を必ず明示すること。
- 「保証しないもの」を誇張せず書くことが要求されている。この節に**書き漏れ**があれば指摘してほしい。
- 検査が空振りしないこと (負のコントロール / 母集団 0 件で fail / exact-fit) と
  mutation で赤化を確認する手順が要求されている。ここに穴があれば指摘してほしい。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

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
     * @return array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
     *   storage_limit_bytes: int|null, storage_usage_percent: int|null,
     *   billing_state: string}
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

`DashboardPageData` の `@return` shape も `has_billing_access: bool` → `billing_state: string` に更新する
（`billing: array{...}|null` の入れ子部分）。

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
  → 参照は `rg` で全数確認済み（アプリ 2 + テスト 2 ファイル）。dashboard props は
  Inertia page prop であり外部 API 契約ではないため、外部消費者は存在しえない。
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

1. `test('新規登録相当 (未契約) org: billing_state=no_subscription (F-2-01 再現)')`
   — `createOrganizationWithOwner(grandfatherFreePlan: false)` + Project factory。
   `/dashboard` が 200 かつ `dashboard.billing.billing_state === 'no_subscription'`。
   **これが F-2-01 の再現テスト**（実装前は `has_billing_access=false` しか出せず fail する）。
2. `test('未契約 org の CTA 着地 /onboarding/checkout が 200 (行き先のない詰みを作らない)')`
   — 同じ org の owner で `/onboarding/checkout` が 200。
3. `test('未契約 org の非 manageBilling メンバーは CTA 着地で /billing-required へ捌かれる')`
   — member を作り `/onboarding/checkout` → `assertRedirect(route('onboarding.billing-required'))` →
   その着地が 200。**CTA をフロントで権限分岐しない判断の behavioral な裏付け**。
4. `test('live pending checkout org: billing_state=pending_checkout')`
   — `BillingCheckoutSession::factory()->for($organization)->create()`（既定 = live pending）。
5. `test('expired checkout org: billing_state=expired_checkout')`
   — `BillingCheckoutSession::factory()->for($organization)->expired()->create()`。

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

| テスト名 | 検証内容 |
|---|---|
| `未契約 org の dashboard は「プランを選ぶ」callout を表示し、CTA を押すとプラン選択に着地する` | `createOrganizationWithOwner(grandfatherFreePlan: false)` の owner で `/dashboard` を開き、`[data-testid="billing-callout-body"]` が「プランの選択が必要」文言。CTA クリック → `/onboarding/checkout` に到達（**行き先のない詰みがないことを実ブラウザで確認**） |
| `未契約 org の dashboard に「お支払いが確認できない」文言が出ない (F-2-01 の再現が閉じたこと)` | ページ本文に旧文言が**含まれない**ことを assert |

> passkey 429 は Browser lane に**入れない**。10 req/分の枯渇を実ブラウザで作るのは
> グローバルテストロック下の実行時間を無駄に伸ばし、他レーンの limiter バケットにも影響する。
> 分岐は jsdom（vitest）で決定的に固定できる。

### F. mutation で赤化を確認する手順（実装後に必ず実施し、結果を PR に記載する）

| # | 変異 | 赤くなるべきもの |
|---|---|---|
| 1 | `DashboardService` で `billingState: OnboardingBillingState::ExpiredCheckout` を固定値で渡す | Feature の新規 1 / 4 / 5 と既存 3 本 |
| 2 | `BILLING_CALLOUTS.no_subscription` の body を `expired_checkout` と同じ文言にする | vitest C の 1 本目 + Browser E の 2 本目 |
| 3 | `BILLING_CALLOUTS` から `pending_checkout` キーを削除する | `pnpm typecheck`（`satisfies Record<…>` が欠落を検出） |
| 4 | `billing.ts` の `"no_subscription"` を `"no_subscription_x"` に書き換える | Architecture B の 1 本目 |
| 5 | B の 2 本目で `NoSuchUnionName` を実在する `BillingStateValue` に差し替える | B の 2 本目（throw しなくなる = 負のコントロールが機能している証明） |
| 6 | `fetchOptions` の `if (res.status === 429)` 行を削除する | vitest D の 1・3 本目（2 本目=500 は緑のまま = 変異の位置が特定できる） |
| 7 | `readErrorMessage` の 429 早期 return を削除する | vitest D の 4 本目 |

各変異は**1 つずつ**入れて該当テストが赤くなることを確認し、**必ず revert する**。

### G. 検証コマンド（全 green でコミット）

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm test` / `pnpm build` / `composer test:browser`

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
8. **bug-hunt 環境での再現確認は本設計の範囲外**。fake gateway 環境では
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

## 関連する現行コード (抜粋・設計者が実際に読んだもの)

### app/DataTransferObjects/Dashboard/BillingSummaryData.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Dashboard;

/**
 * チケット残高 + 容量 Quota (低残高警告と高使用率警告は別個のフラグ)。
 * TS 側 types/dashboard.ts の BillingSummary と対で保守する。
 */
final readonly class BillingSummaryData
{
    public function __construct(
        public int $ticketBalance,
        public bool $isLowBalance,          // balance < billing.ticket_low_balance_threshold
        public int $storageUsedBytes,       // StorageUsageService::occupiedBytes
        public ?int $storageLimitBytes,     // QuotaService::limits[max_storage_bytes] (無制限は null)
        public ?int $storageUsagePercent,   // 0-100 に clamp (limit null なら null)
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
            'ticket_balance' => $this->ticketBalance,
            'is_low_balance' => $this->isLowBalance,
            'storage_used_bytes' => $this->storageUsedBytes,
            'storage_limit_bytes' => $this->storageLimitBytes,
            'storage_usage_percent' => $this->storageUsagePercent,
            'has_billing_access' => $this->hasBillingAccess,
        ];
    }
}

```

### app/Services/Dashboard/DashboardService.php (billingSummary + build の抜粋)
```php

    public function build(User $user, ?Organization $organization): DashboardPageData
    {
        if ($organization === null) {
            return new DashboardPageData(
                state: DashboardState::NoOrganization, role: null, canCreateProject: false,
                organizationName: null, projectId: null, projectName: null,
                inProgress: [], recentManuals: [], shootingTargets: [], billing: null,
            );
        }

        $billing = $this->billingSummary($organization);
        $project = $this->defaultProjects->resolve($organization);
        if ($project === null) {
            return new DashboardPageData(
                state: DashboardState::NoProject, role: null,
                canCreateProject: $user->can('create', [Project::class, $organization]),
                organizationName: $organization->name, projectId: null, projectName: null,
                inProgress: [], recentManuals: [], shootingTargets: [], billing: $billing,
            );
        }

        $role = $this->resolveRole($user, $project);

        return new DashboardPageData(
            state: DashboardState::Ready, role: $role, canCreateProject: false,
            organizationName: $organization->name,
            projectId: $project->id, projectName: $project->name,
            inProgress: $this->inProgress($project),
            recentManuals: $this->recentManuals($project),
            shootingTargets: $this->shootingTargets($project),
            billing: $billing,
        );

    }

    private function billingSummary(Organization $organization): BillingSummaryData
    {
        $balance = $this->tickets->balance($organization)->totalAvailable();
        $used = $this->storage->occupiedBytes($organization);
        $limit = $this->quota->limits($organization)[QuotaKey::MaxStorageBytes->value] ?? null;
        $percent = ($limit === null || $limit <= 0)
            ? null
            : (int) max(0, min(100, floor($used / $limit * 100)));

        return new BillingSummaryData(
            ticketBalance: $balance,
            isLowBalance: $balance < config()->integer('billing.ticket_low_balance_threshold'),
            storageUsedBytes: $used,
            storageLimitBytes: $limit,
            storageUsagePercent: $percent,
            hasBillingAccess: $this->billingAccess->hasActiveAccess($organization),
        );
    }
```

### app/DataTransferObjects/Dashboard/DashboardPageData.php (@return shape)
```php
    ) {}

    /**
     * @return array{state: 'no_organization'|'no_project'|'ready', role: string|null,
     *   can_create_project: bool, organization_name: string|null,
     *   project: array{id: int, name: string}|null,
     *   in_progress: list<array{manual_id: int, title: string, manual_status: string,
     *     job_status: string|null, progress: int|null, job_updated_at: string|null}>,
     *   recent_manuals: list<array{id: int, title: string, status: string,
     *     category_name: string|null, updated_at: string}>,
     *   shooting_targets: list<array{manual_id: int, title: string, cuts_count: int,
     *     pending_cuts_count: int}>,
     *   billing: array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
     *     storage_limit_bytes: int|null, storage_usage_percent: int|null,
     *     has_billing_access: bool}|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'role' => $this->role?->value,
            'can_create_project' => $this->canCreateProject,
            'organization_name' => $this->organizationName,
            'project' => ($this->projectId !== null && $this->projectName !== null)
                ? ['id' => $this->projectId, 'name' => $this->projectName]
                : null,
            'in_progress' => array_map(
                static fn (InProgressManualData $row): array => $row->toArray(),
                $this->inProgress,
            ),
            'recent_manuals' => array_map(
                static fn (RecentManualData $row): array => $row->toArray(),
                $this->recentManuals,
            ),
            'shooting_targets' => array_map(
                static fn (ShootingTargetData $row): array => $row->toArray(),
                $this->shootingTargets,
            ),
            'billing' => $this->billing?->toArray(),
        ];
    }
```

### app/Services/Billing/BillingAccess.php (state() 全文)
```php

    /**
     * 組織が業務機能を利用してよいか (billing entitlement)。
     *
     * 判定は `state()->grantsAccess()` の一本 (= 無料枠は `ActiveFreePlan`、有償は
     * `Subscribed` でのみ許可)。移行 OR (`plan_code === null` を通す 1 行) はゲート反転で
     * 削除済み — 既存の未契約組織は grandfathering backfill が `free_plan_code = 'personal'`
     * を書いて `ActiveFreePlan` として許可されるため、締め出しは発生しない。
     */
    public function hasActiveAccess(Organization $organization): bool
    {
        return $this->state($organization)->grantsAccess();
    }

    /**
     * 流入制御目線の課金状態。**`plan_code` を一切見ない** (entitlement は subscription /
     * free_plan_code / checkout session から導出する)。
     *
     * 読み取り経路のため **DB 書き込みをしない**。stale な pending checkout は in-memory で
     * expired 扱いにしてアクセス判定の整合性を保ち、実 DB の expired 化は sweeper に委ねる
     * (require.subscription が付く多数の GET 経路で毎回 UPDATE が走る副作用を排除する)。
     */
    public function state(Organization $organization): OnboardingBillingState
    {
        $sub = $organization->subscription('default');
        $entitled = $sub instanceof Subscription
            && $this->subscriptions->deriveEntitlement($sub)->entitled;

        // 利用可否は SubscriptionState 単体ではなく deriveEntitlement で確定する
        // (SubscriptionState::grantsAccess を直接参照しない)。
        if ($entitled) {
            return OnboardingBillingState::Subscribed;
        }

        // 現在 entitled な Stripe subscription が「ない」(行の不在ではなく entitlement で判定。
        // canceled 等の過去行が残っていてもよい = paid→free 経路) とき free entitlement を見る。
        // 判定は定数比較 (未知値は fail-closed で通さない)。entitled subscription があれば上で
        // Subscribed 優先 (free と併存しない invariant)。
        if ($organization->free_plan_code === PersonalPlanService::FREE_PLAN_CODE) {
            return OnboardingBillingState::ActiveFreePlan;
        }

        if ($sub instanceof Subscription) {
            // 利用不可 (Inactive / Paused / trial 終了 & PM 無 / PM 無 past_due) は gate を通さない
            // → ExpiredCheckout 扱い (未契約導線へ)。
            return OnboardingBillingState::ExpiredCheckout;
        }

        // live/stale の判定は BillingCheckoutSession の述語だけが定義する (P9 C-1)。
        // 閾値 literal をここに再発明しない (CheckoutLiveThresholdSingleSourceTest が機械検出)。
        $now = CarbonImmutable::now();
        /** @var Collection<int, BillingCheckoutSession> $pendingRows */
        $pendingRows = BillingCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('status', CheckoutSessionStatus::Pending->value)
            ->get(['id', 'status', 'created_at']);

        $hasLivePending = false;
        $hasStalePending = false;
        foreach ($pendingRows as $row) {
            if ($row->isLivePending($now)) {
                $hasLivePending = true;
            } else {
                $hasStalePending = true;
            }
        }

        if ($hasLivePending) {
            return OnboardingBillingState::PendingCheckout;
        }

        $hasExpired = $hasStalePending || BillingCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [
                CheckoutSessionStatus::Expired->value,
                CheckoutSessionStatus::Failed->value,
            ])
            ->exists();

        return $hasExpired ? OnboardingBillingState::ExpiredCheckout : OnboardingBillingState::NoSubscription;
    }
}

```

### resources/js/types/dashboard.ts (該当部)
```ts

export interface BillingSummary {
    ticket_balance: number;
    is_low_balance: boolean;
    storage_used_bytes: number;
    storage_limit_bytes: number | null;
    storage_usage_percent: number | null;
    has_billing_access: boolean;
}

export interface DashboardData {
    state: DashboardState;
    role: DashboardRole | null;
    can_create_project: boolean;
    organization_name: string | null;
    project: { id: number; name: string } | null;
    in_progress: InProgressManual[];
    recent_manuals: RecentManual[];
    shooting_targets: ShootingTarget[];
    billing: BillingSummary | null;
}

/** ページ props (Inertia)。共有 props は SharedProps を合成して参照する (契約 1 本化) */
export interface DashboardProps {
    dashboard: DashboardData;
```

### resources/js/pages/Dashboard.svelte (script 冒頭と callout)
```svelte
    import type { SharedProps } from "@/lib/shared-props";
    import type { DashboardProps } from "@/types/dashboard";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

    /**
     * ダッシュボード (ログイン直後の着地点)。PHP: DashboardController / DashboardPageData と対。
     * state (no_organization / no_project / ready) とロール (editor / shooter / viewer) で
     * 表示を分岐する。権限がない導線は非描画 (disabled ボタンは一切作らない)。
     */
    let { dashboard }: DashboardProps = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const user = $derived(shared.auth?.user ?? null);
    const appName = $derived(shared.appName ?? "");
    // 未読数は shared props (T008 ベルと同源。サーバ二重集計なし)
    const unreadCount = $derived(shared.notifications?.unreadCount ?? 0);

    const billing = $derived(dashboard.billing);
    const project = $derived(dashboard.project);
    const isEditor = $derived(dashboard.role === "editor");
    const isShooter = $derived(dashboard.role === "shooter");
...
                </Card>
            {:else}
                <!-- スタットタイル (org があれば billing は非 null) -->
                {#if billing}
                    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <StatCard
                                label="チケット残高"
                                value={billing.ticket_balance}
                                subtext={billing.is_low_balance ? "残高が少なくなっています" : undefined}
                                icon={Ticket}
                                testId="stat-tickets"
                            />
                            {#if billing.is_low_balance}
                                <p class="mt-2 text-caption">
                                    <TextLink href="/purchase-tickets" testId="purchase-link">
                                        チケットを購入
                                    </TextLink>
                                </p>
                            {/if}
                        </div>
                        <StatCard
                            label="容量使用率"
                            value={billing.storage_usage_percent === null
                                ? "無制限"
                                : `${billing.storage_usage_percent}%`}
                            subtext={billing.storage_limit_bytes === null
                                ? `${formatBytes(billing.storage_used_bytes)} 使用中`
                                : `${formatBytes(billing.storage_used_bytes)} / ${formatBytes(billing.storage_limit_bytes)}`}
                            icon={HardDrive}
                            testId="stat-storage"
                        />
                        <div>
                            <StatCard label="未読通知" value={unreadCount} icon={Bell} testId="stat-unread" />
                            <p class="mt-2 text-caption">
                                <TextLink href="/notifications">通知を確認</TextLink>
                            </p>
                        </div>
                        <StatCard
                            label="進行中ジョブ"
                            value={dashboard.in_progress.length}
                            icon={Loader}
                            testId="stat-inprogress"
                        />
                    </div>

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
                {/if}

                {#if dashboard.state === "no_project"}
```

### resources/js/lib/passkeys.ts (関係部のみ)
```ts
export type PasskeyOutcome<T> =
    | { status: "ok"; value: T }
    | { status: "cancelled" }
    | { status: "unsupported" }
    | { status: "failed"; message: string };

const GENERIC_FAILURE = "パスキーの処理に失敗しました。時間をおいて再度お試しください。";

/** この端末で passkey ceremony を開始できるか (API の存在確認) */
...
}

/**
 * 共通 fetch。`Accept: application/json` は必須
 * (無いと Laravel が redirect を返し、PasskeyLoginResponse も JSON 分岐に入らない)。
 */
async function requestJson(url: string, body?: JsonRecord): Promise<Response> {
    const headers: Record<string, string> = {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
    };
    if (body !== undefined) {
        headers["Content-Type"] = "application/json";
        headers["X-XSRF-TOKEN"] = csrfToken();
    }
    return fetch(url, {
        method: body === undefined ? "GET" : "POST",
        headers,
        credentials: "same-origin",
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

/** options endpoint から `{ options }` を取り出す (不正 shape は null) */
async function fetchOptions(url: string): Promise<JsonRecord | null> {
    try {
        const res = await requestJson(url);
        if (!res.ok) return null;
        const payload: unknown = await res.json();
        if (!isRecord(payload) || !isRecord(payload.options)) return null;
        return payload.options;
    } catch {
        return null;
    }
}

/** ユーザーキャンセル / タイムアウトを「失敗」として騒がないために畳む */
function isCancellation(error: unknown): boolean {
    return (
        error instanceof Error &&
        (error.name === "NotAllowedError" || error.name === "AbortError")
...
        challenge: base64UrlToBuffer(challenge),
        rpId: readString(options, "rpId") ?? undefined,
        allowCredentials: readDescriptors(options, "allowCredentials"),
        timeout: typeof options.timeout === "number" ? options.timeout : undefined,
        userVerification: (readString(options, "userVerification") ?? undefined) as
            | UserVerificationRequirement
            | undefined,
    };
}

/**
 * 登録 ceremony (GET options → navigator.credentials.create)。
 * **送信は行わない**。呼び出し側が
 * `router.post('/user/passkeys', { name, credential })` する (transport 契約 4-d)。
 */
export async function createPasskeyCredential(): Promise<PasskeyOutcome<JsonRecord>> {
    if (!isPasskeySupported()) return { status: "unsupported" };

    const options = await fetchOptions("/user/passkeys/options");
    if (options === null) {
        return { status: "failed", message: "パスキーの登録を開始できませんでした。" };
    }

    const creationOptions = toCreationOptions(options);
    if (creationOptions === null) {
        return { status: "failed", message: GENERIC_FAILURE };
    }

    try {
        const credential = await navigator.credentials.create({ publicKey: creationOptions });
        if (!(credential instanceof PublicKeyCredential)) {
            return { status: "failed", message: GENERIC_FAILURE };
        }
        return { status: "ok", value: serializeCredential(credential) };
    } catch (error) {
        if (isCancellation(error)) return { status: "cancelled" };
        return { status: "failed", message: GENERIC_FAILURE };
    }
}

/** ログイン ceremony (GET options → navigator.credentials.get → POST → `{ redirect }`) */
export async function loginWithPasskey(
    remember = false,
): Promise<PasskeyOutcome<{ redirect: string }>> {
    const assertion = await assertPasskey("/passkeys/login/options");
    if (assertion.status !== "ok") return assertion;

    try {
...
(assertPasskey / readErrorMessage)

    try {
        const res = await requestJson("/passkeys/confirm", { credential: assertion.value });
        // 成功は 204 No Content (recent-auth.password と同契約)
        if (res.status === 204) return { status: "ok", value: undefined };
        return { status: "failed", message: await readErrorMessage(res) };
    } catch {
        return { status: "failed", message: "通信エラーが発生しました。" };
    }
}

/** options 取得 + assertion ceremony の共通部 */
async function assertPasskey(optionsUrl: string): Promise<PasskeyOutcome<JsonRecord>> {
    if (!isPasskeySupported()) return { status: "unsupported" };

    const options = await fetchOptions(optionsUrl);
    if (options === null) {
        return { status: "failed", message: "パスキーの認証を開始できませんでした。" };
    }

    const requestOptions = toRequestOptions(options);
    if (requestOptions === null) {
        return { status: "failed", message: GENERIC_FAILURE };
    }

    try {
        const credential = await navigator.credentials.get({ publicKey: requestOptions });
        if (!(credential instanceof PublicKeyCredential)) {
            return { status: "failed", message: GENERIC_FAILURE };
        }
        return { status: "ok", value: serializeCredential(credential) };
    } catch (error) {
        if (isCancellation(error)) return { status: "cancelled" };
        return { status: "failed", message: GENERIC_FAILURE };
    }
}

/** サーバのエラー本文から表示可能なメッセージを取り出す (取れなければ既定文言) */
async function readErrorMessage(response: Response): Promise<string> {
    try {
        const payload: unknown = await response.json();
        if (!isRecord(payload)) return GENERIC_FAILURE;
        const direct = readString(payload, "message");
        if (direct !== null) return direct;
```

### tests/Support/TsUnionValues.php (再発防止 gate が使う既存ヘルパ・全文)
```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use BackedEnum;
use RuntimeException;

/**
 * PHP enum ⇔ TS literal union の値集合同期 invariant 用の抽出ヘルパ。
 * ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest が共有する
 * (T008 で ManualEnumTsSyncInvariantTest 内のローカル関数から昇格)。
 */
final class TsUnionValues
{
    /**
     * TS ファイルから `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
     * 抽出不能 (degenerate PASS) は fail させる (RuntimeException)。
     *
     * @param  string  $relativePath  base_path からの相対パス (例: resources/js/types/manual.ts)
     * @return list<string>
     */
    public static function extract(string $relativePath, string $typeName): array
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("TS ファイルを読めません: {$path}");
        }

        // `export type X =` から次の `;` までを取り出す (複数行 union 対応)
        $matched = preg_match(
            '/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s',
            $contents,
            $matches,
        );
        if ($matched !== 1) {
            throw new RuntimeException("TS union が抽出できません (degenerate PASS 防止): {$typeName}");
        }

        $literalCount = preg_match_all('/"([^"]+)"/', $matches[1], $literals);
        if ($literalCount === false || $literalCount === 0) {
            throw new RuntimeException("TS union のリテラルが抽出できません: {$typeName}");
        }

        $values = $literals[1];
        sort($values);

        return $values;
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<string>
     */
    public static function enumStringValues(array $cases): array
    {
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
        sort($values);

        return $values;
    }
}

```

### tests/js/pages/Dashboard.test.ts (更新対象の既存テスト)
```ts

function billingData(overrides: Partial<BillingSummary> = {}): BillingSummary {
    return {
        ticket_balance: 10,
        is_low_balance: false,
        storage_used_bytes: 250 * 1024 * 1024,
        storage_limit_bytes: 1024 * 1024 * 1024,
        storage_usage_percent: 25,
        has_billing_access: true,
        ...overrides,
    };
}

function dashboardData(overrides: Partial<DashboardData> = {}): DashboardData {
    return {
        state: "ready",
        role: "editor",
        can_create_project: false,
        organization_name: "テスト組織",
        project: { id: 1, name: "テストプロジェクト" },
        in_progress: [],
...
    it("has_billing_access=false で billing callout が出る (支払い確認文言 + /billing CTA)", () => {
        render(Dashboard, {
            props: {
                dashboard: dashboardData({
                    billing: billingData({ has_billing_access: false }),
                }),
            },
        });

        const callout = screen.getByTestId("billing-callout");
        expect(callout).toBeInTheDocument();
        // 表示対象は「有償プラン契約中の支払い不健全」— 新規契約を誘導する文言・CTA への
        // 後退 (二重契約誘導) を検出するため、文言と遷移先を固定する
        expect(callout).toHaveTextContent(
            "サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。",
        );
        expect(screen.getByText("お支払い方法を確認").getAttribute("href")).toMatch(
            /\/billing$/,
        );
    });

    it("has_billing_access=true で billing callout は出ない", () => {
        render(Dashboard, {
            props: {
                dashboard: dashboardData({
                    billing: billingData({ has_billing_access: true }),
                }),
            },
        });

        expect(screen.queryByTestId("billing-callout")).toBeNull();
    });

    it("disabled 属性を持つ要素が 1 つも存在しない", () => {
        const { container } = render(Dashboard, { props: { dashboard: fullData() } });

        expect(container.querySelectorAll("[disabled]")).toHaveLength(0);
    });
```

### tests/Feature/DashboardTest.php (更新対象の既存テスト)
```php
});

test('Free (未契約) org: dashboard 200 + has_billing_access=true + 業務 route 開通', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.has_billing_access', true));

    $this->actingAs($owner)->get('/projects')->assertOk();
});

test('有償契約 + 支払い不健全 org: has_billing_access=false + CTA 遷移先 200 (redirect loop なし)', function (): void {
    // 有償 org は grandfatherFreePlan: false (backfill 対象は plan_code/free_plan_code とも
    // NULL の org に閉じるため、有償 org に grandfather マーカーが付くのは非現実な fixture。
    // 付くと state() の ActiveFreePlan が支払い健全性判定を覆い隠す)
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    // P2 の判定モデル置換で past_due は cohort D として許可へ反転したため、
    // 遮断側の不変条件 (redirect loop なし) は canceled (cohort G) で保持する。
    contractPaidPlan($organization, status: 'canceled');
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.has_billing_access', false));

    // CTA 遷移先は課金ゲート外 (redirect loop なし不変条件)
    $this->actingAs($owner)->get('/purchase-tickets')->assertOk();
    $this->actingAs($owner)->get('/billing')->assertOk();
});

test('有償契約 + past_due org: has_billing_access=true (cohort D。dunning 中も利用継続)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'past_due');
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.has_billing_access', true));
});

test('ゲストは /login へ redirect (既存挙動維持)', function (): void {
```

### tests/Pest.php の Factory ヘルパ (テスト計画が使うもの)
```php
 * owner の current_organization_id はこの組織になる。
 *
 * 既定では grandfathering backfill 相当 (`free_plan_code='personal'` / declarer NULL) を付与し、
 * 課金ゲート (require-active-subscription) を `ActiveFreePlan` で通る既存組織を再現する。
 * PersonalPlanService::activate() は**呼ばない** (signup grant が発火して残高期待が壊れ、
 * declarer の partial unique index にも触れるため)。
 *
 * `$grandfatherFreePlan: false` は真の未契約組織 (free_plan_code NULL = 業務 route が
 * onboarding へ遮断される) を作る。ゲート / onboarding のテストで使う。
 * 有償プラン契約状態を検証するテストは contractPaidPlan() を併用する。
 *
 * @return array{Organization, User} [organization, owner]
 */
function createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    if ($grandfatherFreePlan) {
        $organization->forceFill([
            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
            'free_plan_activated_at' => CarbonImmutable::now(),
        ])->save();
    }

    return [$organization, $owner];
}

/**
 * recent-auth (step-up) を確実に満たす fresh session 値。
 * 窓は config('auth.recent_auth_timeout')(既定 900s)。注入時点の elapsed≈0 で窓に対し十分 fresh。
 * recent-auth を要する route を「step-up 済み相当」で叩くテストは withSession() でこれを注入する。
 *
 * @return array{recent_auth_at: int}
 */
function freshRecentAuthSession(): array
{
    return ['recent_auth_at' => now()->timestamp];
}

/**
 * 組織を有償プラン契約状態にする (plan_code + Cashier subscription 行)。
 * plan_code は $fillable 外の状態キー (webhook 同期のみ) のため forceFill で明示代入。
 * BillingAccess は plan_code 非 null の組織にのみ active/trialing subscription を要求する。
 *
 * plan_code は PlanSeeder が投入する有償プラン code ('standard') を使う
 * (プラン名分岐ではなく seeded fixture の参照。アプリコードには入らない)。
 */
function contractPaidPlan(Organization $organization, string $status = 'active'): Subscription
{
    $organization->forceFill(['plan_code' => 'standard'])->save();

    return createFakeSubscription($organization, status: $status);
}

/**
 * テスト用の Cashier subscription 行を直接作成する (Stripe には到達しない)。
 * BillingAccess (課金ゲート) は plan_code 非 null の組織に対して stripe_status が
 * active / trialing のとき許可する (plan_code null = free tier は行の有無に依らず許可)。
 */
function createFakeSubscription(
    Organization $organization,
    string $status = 'active',
    string $type = 'default',
): Subscription {
    /** @var Subscription $subscription */
```
