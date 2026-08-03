【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【背景】
これは LLM 探索的バグハントで確定した finding F-3-04 (High)
「P9 着地 feedback バナーが one-shot 契約を満たさず、ブラウザリロードで無限に復活する」への設計。
概念設計は同じ Codex セッション系列で APPROVED 済み (別セッション)。
リポジトリは /workspace。必要なら実ファイルを read してよい。

---

## 詳細設計書

# 詳細設計: billing-feedback-oneshot (F-3-04)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### セキュリティ不変条件のうち本設計に効くもの

- **#3 cross-org 不可**: `session_id` は `$organization->checkoutSessions()` relation 経由でのみ引く。
- **#7 課金の冪等性**: `BillingCheckoutSession` の状態機械・webhook 冪等マシンには**触れない**。
  本設計は**表示経路のみ**の変更で、**GET で DB を書かない**。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは Svelte 5 runes + DS token/ramp のみ（`DESIGN.md` が canonical）

## 概念設計リファレンス

- `devnotes/20260804-0021-billing-feedback-oneshot/conceptual-design.md`
  （Codex `gpt-5.4` レビュー Round 3 で APPROVED）
- 出典 finding: `devnotes/20260803-203721-bug-hunt/report.md` F-3-04 (High) /
  `devnotes/20260803-203721-bug-hunt/shard-3/shard-report.md#F-4`

## 設計の要点（3 行）

1. `/billing` の着地 query (`?session_id` / `?portal`) を **canonical URL へ 303 で畳む**。
2. feedback は **次の 1 リクエストだけ生きる session flash** で運ぶ（Laravel 標準の one-shot 機構）。
3. `?replayed` / `?retry` は**アプリ自身が発行していた query**なので、発行側で最初から flash にする
   （query 解釈の分岐ごと削除。並走させない）。

## 「購入完了を伝える唯一の経路」を痩せさせない担保

`PurchaseFormState::Completed` 撤去後、このバナーは購入完了を伝える唯一の経路
（`BillingFeedbackKind` の docblock / `docs/architecture.md`）。one-shot 化で情報が失われないよう:

- 描画先は**ページ内常在の `Alert`**（`flash-to-toast` の自動消滅トーストに格下げしない）。
  `Alert.svelte` の docblock どおり「ページ内に常在するインライン通知ボックス」を維持する。
- **通常の 303 追従フローでは、直後の GET が必ず flash を読む**（hop の間に flash を消費する
  中間リクエストが挟まらない）。※通信中断や同一 session の並行リクエストまで保証する主張はしない。
- `purchase_processing` を「DB の live pending から恒久表示」に格上げ**しない**。
  DB の `pending` は「決済済み・webhook 待ち」と「Checkout 放棄」を区別できず（両方 pending で
  最大 1 日 live）、恒久バナー化すると放棄ユーザーに嘘をつく。
  **「Stripe から `session_id` 付きで戻ってきた」というイベント性が根拠**なので、
  イベント（one-shot flash）のまま扱うのが正しい。
- `purchase_received` の恒久的な裏付けは既に画面上にある（webhook 反映後の「現在のプラン」カード /
  次回請求日 / チケット残高）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 文言を `BillingFeedbackKind` に集約し、DTO を enum 駆動にする | `app/Enums/Billing/BillingFeedbackKind.php` / `app/DataTransferObjects/Billing/BillingFeedbackDto.php` | High |
| 2 | 着地 query を canonical へ畳む共通ヘルパを作り、既存 2 着地を載せ替える | `app/Http/Controllers/Billing/BillingController.php` | High |
| 3 | feedback を「query 解釈」から「着地 303 + flash」へ作り替える | `app/Http/Controllers/Billing/BillingController.php` | High |
| 4 | `?replayed` / `?retry` を廃止し、発行側で flash する | `app/Http/Controllers/Billing/BillingController.php` | High |
| 5 | Svelte の誤ったコメントを実装に合わせて訂正する | `resources/js/pages/Billing/Index.svelte` | Medium |
| 6 | one-shot 契約と副作用境界を正本 (`docs/architecture.md`) に明記する | `docs/architecture.md` | Medium |
| 7 | one-shot 契約・fail-closed・波及をテストで固定する | `tests/Feature/Billing/BillingFeedbackTest.php` ほか 3 ファイル | High |

---

## 施策 1: 文言を `BillingFeedbackKind` に集約し、DTO を enum 駆動にする

### 変更箇所
- `app/Enums/Billing/BillingFeedbackKind.php`（全体）
- `app/DataTransferObjects/Billing/BillingFeedbackDto.php` (L24-31 `simple()`)

### 波及変更
- TypeScript型定義: **なし**（`toArray()` の shape = `{kind, message}` は不変。
  `resources/js/types/billing.ts` の `BillingFeedbackShape` / `BillingFeedbackKind` は無変更）
- API Resource/DTO: `BillingFeedbackDto::simple()` を `fromKind()` へ置換（旧 API は残さない）
- テストファイル: 施策 7 参照

### 現行コード

```php
// app/Enums/Billing/BillingFeedbackKind.php
enum BillingFeedbackKind: string
{
    case PurchaseReceived = 'purchase_received';
    // ...
}

// app/DataTransferObjects/Billing/BillingFeedbackDto.php
public static function simple(BillingFeedbackKind $kind, string $message): self
{
    return new self($kind, $message);
}
```

文言は `BillingController::resolveBillingFeedback()` の中に直書き（5 箇所）。

### 変更後コード

```php
// app/Enums/Billing/BillingFeedbackKind.php
/**
 * P9: 課金 Checkout / portal の着地フィードバック種別。
 * Inertia::location() の full page redirect を跨いだ後、/billing 着地で
 * ユーザーに「購入を受け付けたか / 処理中か / 既に受付済みか」を伝える。
 *
 * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
 * 唯一の経路が本 feedback (one-shot)** になっている。
 *
 * one-shot は **着地 query を canonical URL へ 303 で畳み、kind を FLASH_KEY の
 * session flash (次の 1 リクエストのみ生存) で運ぶ**ことで担保する
 * (詳細は docs/architecture.md §サブスク契約 Checkout とオンボーディング着地)。
 */
enum BillingFeedbackKind: string
{
    /** 着地 hop で kind を運ぶ session flash キー (shared flash の 4 キーと衝突しない名前)。 */
    public const string FLASH_KEY = 'billing_feedback_kind';

    case PurchaseReceived = 'purchase_received';
    case PurchaseProcessing = 'purchase_processing';
    case PurchaseAlreadyReceived = 'purchase_already_received';
    case CheckoutRetryRequired = 'checkout_retry_required';
    case PortalReturned = 'portal_returned';

    /** ユーザーに提示する確定文言 (単一出典。flash には kind だけを載せる)。 */
    public function message(): string
    {
        return match ($this) {
            self::PurchaseReceived => 'お支払いを受け付けました。プランへの反映には数分かかる場合があります。',
            self::PurchaseProcessing => 'お支払いを確認しています。プラン反映までしばらくお待ちください。',
            self::PurchaseAlreadyReceived => 'この内容のお支払いは既に受け付け済みです。',
            self::CheckoutRetryRequired => 'お手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
            self::PortalReturned => 'お支払い管理画面から戻りました。',
        };
    }
}

// app/DataTransferObjects/Billing/BillingFeedbackDto.php
/** kind から確定文言を引いて組み立てる (文言の出典は enum 一本)。 */
public static function fromKind(BillingFeedbackKind $kind): self
{
    return new self($kind, $kind->message());
}
```

> 文言は**現行と一字一句同じ**にする（コピー改善は本設計のスコープ外。差分を「移設のみ」に保つ）。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`message(): string` / `fromKind(): self`）
- [x] null 安全（`match ($this)` は enum 全ケース網羅で `UnhandledMatchError` 不到達）
- [x] DTO を返している（配列返却なし）
- [x] Generics の型パラメータ: 該当なし
- [x] `@phpstan-type BillingFeedbackShape` は既存のまま（shape 不変）

### テスト計画
- 既存 `tests/Feature/Billing/BillingFeedbackTest.php` が文言・kind を通しで固定する（施策 7）。
- 単体テストは新設しない（enum の match は Feature 経由で全 5 ケース到達する）。

### リスク
- `simple()` を消すため、他の呼び出し元が残っていると fatal。
  → `rg 'BillingFeedbackDto::simple'` で BillingController 以外に無いことを確認済み（0 件）。

---

## 施策 2: 着地 query を canonical へ畳む共通ヘルパ

### 変更箇所
- `app/Http/Controllers/Billing/BillingController.php`
  - 新規 private const + private method
  - `resolveAutoRechargeSetupLanding()` (L378-401) / `resolveAutoRechargeLanding()` (L411-439) の
    redirect 生成箇所を載せ替え

### 波及変更
- TypeScript型定義: なし / API Resource・DTO: なし
- テストファイル: `tests/Feature/Billing/AutoRechargeEndpointTest.php`（既存の
  `?setup_session_id` 着地アサーションは `/billing` のままで**変化しない**ことの確認のみ）

### 現行コード

```php
// resolveAutoRechargeSetupLanding()
if ($session === null) {
    return redirect()->route('billing.index', [], 303);
}
// ...
return redirect()->route('billing.index', [], 303)->with('success', $message);

// resolveAutoRechargeLanding()
return redirect()
    ->route('billing.index', ['highlight' => 'auto-recharge'], 303)
    ->with('info', $message);
```

### 変更後コード

```php
/**
 * 着地 hop を跨いで **保持する** query（着地 query は畳んで落とす）。
 * `highlight` は副作用のない scroll anchor なので保持する。
 */
private const PRESERVED_LANDING_QUERY = ['highlight'];

/**
 * 着地 query を畳んだ canonical `/billing` への 303 を返す。
 *
 * `/billing` の着地 3 系統 (setup_session_id / T1004 / feedback) が**共通で使う**
 * 唯一の canonical URL 構築点。着地 query (`setup_session_id` / `session_id` / `portal`) は
 * 引き継がず、`PRESERVED_LANDING_QUERY` に載っている query だけを引き継ぐ。
 *
 * **flash には触れない**（純粋な URL 構築）。flash の扱いは各着地の判断
 * (T1004 は「成功着地で error を延命しない」を明示的に選んでいる)。
 *
 * @param  array<string, string>  $extra  呼び出し側が立てる query (保持分より優先)
 */
private function canonicalBillingRedirect(Request $request, array $extra = []): RedirectResponse
{
    $preserved = [];
    foreach (self::PRESERVED_LANDING_QUERY as $key) {
        $value = $request->query($key);
        if (is_string($value) && $value !== '') {
            $preserved[$key] = $value;
        }
    }

    return redirect()->route('billing.index', [...$preserved, ...$extra], 303);
}
```

呼び出し側:

```php
// resolveAutoRechargeSetupLanding()
if ($session === null) {
    // 未追跡 session — 成功文言は出さず canonical URL へ倒すだけ (query を残さない)。
    return $this->canonicalBillingRedirect($request);
}
// ...
return $this->canonicalBillingRedirect($request)->with('success', $message);

// resolveAutoRechargeLanding()
// reflash() はしない: 成功着地で直前の error flash まで延命すると
// 「成功と失敗が同時に出る」着地を作るため (feedback は本 info 文言だけを主張する)。
return $this->canonicalBillingRedirect($request, ['highlight' => 'auto-recharge'])
    ->with('info', $message);
```

### PHPStan適合チェック
- [x] `$request->query($key)` は `mixed` → `is_string()` で narrowing
- [x] `@param array<string, string>` で `$extra` の shape を明示
- [x] 戻り値 `RedirectResponse` 明示
- [x] spread 結合 `[...$preserved, ...$extra]` は string キーの array<string,string> を保つ

### テスト計画
- Feature: `?setup_session_id` + `?highlight=auto-recharge` → `Location: /billing?highlight=auto-recharge`
- Feature: 既存の `?setup_session_id` 単独ケース（`AutoRechargeEndpointTest`）が
  `/billing` のまま変わらないこと（回帰）
- Feature: T1004 着地（`SubscriptionPmReuseTest`）が `/billing?highlight=auto-recharge` のまま
  変わらないこと（回帰）

### リスク
- `highlight` を保持するようになるのは setup 着地の**挙動変化**（現行は落とす）。
  ただし `setup_session_id` と `highlight` が同時に来る導線は現状存在しない
  （setup の success_url は `?setup_session_id=...` のみ）ため、実害のない規約統一。

---

## 施策 3: feedback を「query 解釈」から「着地 303 + flash」へ

### 変更箇所
- `app/Http/Controllers/Billing/BillingController.php`
  - `index()` (L80-133): 着地判定の 3 本目を追加、`feedback:` の解決元を flash に変更、docblock 追記
  - `resolveBillingFeedback()` (L442-511) を削除し、
    `resolveBillingFeedbackLanding()` / `resolveCheckoutReturnKind()` / `resolveFlashedFeedback()` を新設

### 波及変更
- TypeScript型定義: **なし**（`feedback` prop の shape 不変）
- Inertia Props: `BillingDashboardDto` のフィールド構成は不変
- API Resource/DTO: 施策 1 のとおり
- テストファイル: `tests/Feature/Billing/BillingFeedbackTest.php`（全面更新）、
  `tests/Feature/Billing/SubscriptionPmReuseTest.php`（IDOR ケースの期待値更新）

### 現行コード

```php
// index() 抜粋
$landing = $this->resolveAutoRechargeSetupLanding($request, $organization);
if ($landing !== null) {
    return $landing;
}
$autoRechargeLanding = $this->resolveAutoRechargeLanding($request, $organization);
if ($autoRechargeLanding !== null) {
    return $autoRechargeLanding;
}
// ...
$dto = new BillingDashboardDto(
    // ...
    continueUrl: $this->resolveOnboardingContinue($organization),
    // ...
    feedback: $this->resolveBillingFeedback($request, $organization),
);

// resolveBillingFeedback(): query を毎 render 解釈する (= リロードで復活する)
private function resolveBillingFeedback(Request $request, Organization $organization): ?BillingFeedbackDto
{
    if ($request->query('portal') !== null) {
        if (is_string($request->session()->get('error'))) {
            return null;
        }
        return BillingFeedbackDto::simple(BillingFeedbackKind::PortalReturned, 'お支払い管理画面から戻りました。');
    }

    $sessionId = $request->query('session_id');
    if (is_string($sessionId) && $sessionId !== '') {
        $session = $organization->checkoutSessions()->where('stripe_session_id', $sessionId)->first();
        if (! $session instanceof BillingCheckoutSession) { return null; }
        if ($session->intent !== CheckoutIntent::SubscriptionStart->value) { return null; }
        if ($session->status === CheckoutSessionStatus::Completed->value) { /* PurchaseReceived */ }
        if ($session->status === CheckoutSessionStatus::Pending->value) { /* PurchaseProcessing */ }
        return null;
    }

    if ($request->query('replayed') !== null) { /* PurchaseAlreadyReceived */ }
    if ($request->query('retry') !== null) { /* CheckoutRetryRequired */ }

    return null;
}
```

### 変更後コード

```php
    /**
     * 課金ダッシュボード (現在プラン / per-bucket チケット残高 / quota 上限 / 導線)。
     *
     * P8b (bs-14): プラン一覧は /billing/plans へ移設し、ここは請求ダッシュボードに寄せる。
     * props は BillingDashboardDto の 1 本 (禁止事項 #4)。
     *
     * **着地 (landing) の優先順位** — 先着が redirect を返したら後段は評価しない:
     *   1. `?setup_session_id` … P8a カード登録の戻り
     *   2. `?session_id` かつ funding=auto_recharge の完了行 … T1004 (→ ?highlight=auto-recharge)
     *   3. `?session_id` / `?portal` … P9 着地 feedback (本 one-shot バナー)
     *   4. 着地 query 無し … 通常 render
     * いずれも **DTO 構築より前**に置く: `resolveOnboardingContinue()` は return_to を
     * peek + forget (消費) するため、hop する request で DTO を組むと復帰先を無音で失う。
     */
    public function index(/* 変更なし */): Response|RedirectResponse
    {
        // ... (認可・user narrowing は現行のまま)

        $landing = $this->resolveAutoRechargeSetupLanding($request, $organization);
        if ($landing !== null) {
            return $landing;
        }

        $autoRechargeLanding = $this->resolveAutoRechargeLanding($request, $organization);
        if ($autoRechargeLanding !== null) {
            return $autoRechargeLanding;
        }

        // P9: 決済戻り着地 (?session_id / ?portal) を canonical URL へ畳む (one-shot の担保)。
        $feedbackLanding = $this->resolveBillingFeedbackLanding($request, $organization);
        if ($feedbackLanding !== null) {
            return $feedbackLanding;
        }

        // ... 以降は現行どおり。feedback だけ解決元が変わる:
        $dto = new BillingDashboardDto(
            // ...
            // P9: 着地 hop が積んだ one-shot フィードバック (flash = 次の 1 render のみ生存)。
            feedback: $this->resolveFlashedFeedback($request),
            // ...
        );

        return Inertia::render('Billing/Index', ['page' => $dto->toArray()]);
    }

    /**
     * P9: 決済戻り着地 (`?session_id` / `?portal`) を検証して canonical URL へ 303 + flash に畳む。
     *
     * **one-shot の担保はここ**。着地 query を URL から落とし、kind は
     * `BillingFeedbackKind::FLASH_KEY` の session flash (次の 1 リクエストのみ生存) で運ぶ。
     * 着地 URL が履歴に残らないため、リロード / 戻る / ブックマークでバナーが復活しない
     * (bug-hunt F-3-04)。
     *
     * 契約:
     * - 着地 query を認識したら **feedback の有無に関わらず必ず 303** する (query を残さない)
     * - `session_id` は **org スコープ relation** 経由でのみ引く (他 org の id で成功偽装しない)
     * - **intent !== subscription_start は無言** (P8a の setup_payment_method 行が
     *   同一テーブルに実在するため必須。fail-closed)
     * - Failed / Expired も無言 (状態を主張しない。出口は Plans からの新規 token 発行)
     * - error flash がある着地では feedback を出さず、error だけを次 render へ keep する
     *   (成功と失敗を同時に出さない)
     * - **GET で DB を書かない** (状態遷移は webhook の管轄)
     */
    private function resolveBillingFeedbackLanding(Request $request, Organization $organization): ?RedirectResponse
    {
        $isPortalReturn = $request->query('portal') !== null;
        $sessionId = $request->query('session_id');
        $hasSessionId = is_string($sessionId) && $sessionId !== '';

        // (1) 着地判定 — 着地 query が無ければ素通し (通常 render)
        if (! $isPortalReturn && ! $hasSessionId) {
            return null;
        }

        // (2) error flash がある着地では成功偽装を抑止する。hop で error を消さないよう keep する
        if (is_string($request->session()->get('error'))) {
            $request->session()->keep(['error']);

            return $this->canonicalBillingRedirect($request);
        }

        // (3) kind 解決 (portal 優先。session_id は org スコープ + intent で fail-closed)
        $kind = $isPortalReturn
            ? BillingFeedbackKind::PortalReturned
            : $this->resolveCheckoutReturnKind($organization, (string) $sessionId);

        // (4) 303 — kind が無くても canonical へ倒す (URL に着地 query を残さない)
        $redirect = $this->canonicalBillingRedirect($request);

        return $kind === null ? $redirect : $redirect->with(BillingFeedbackKind::FLASH_KEY, $kind->value);
    }

    /**
     * 自 org の `subscription_start` 行の状態から着地 kind を決める (fail-closed)。
     * 未知 / 他 org / intent 不一致 / Failed / Expired はすべて null (無言)。
     */
    private function resolveCheckoutReturnKind(Organization $organization, string $sessionId): ?BillingFeedbackKind
    {
        $session = $organization->checkoutSessions()
            ->where('stripe_session_id', $sessionId)
            ->first();

        if (! $session instanceof BillingCheckoutSession
            || $session->intent !== CheckoutIntent::SubscriptionStart->value) {
            return null;
        }

        return match ($session->status) {
            CheckoutSessionStatus::Completed->value => BillingFeedbackKind::PurchaseReceived,
            CheckoutSessionStatus::Pending->value => BillingFeedbackKind::PurchaseProcessing,
            default => null,
        };
    }

    /**
     * 着地 hop が積んだ feedback flash を DTO 化する (未知値・欠落は null = fail-closed)。
     * flash なので **次の render では自動的に消える** = one-shot。
     */
    private function resolveFlashedFeedback(Request $request): ?BillingFeedbackDto
    {
        $raw = $request->session()->get(BillingFeedbackKind::FLASH_KEY);
        $kind = is_string($raw) ? BillingFeedbackKind::tryFrom($raw) : null;

        return $kind === null ? null : BillingFeedbackDto::fromKind($kind);
    }
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`?RedirectResponse` / `?BillingFeedbackKind` / `?BillingFeedbackDto`）
- [x] null 安全: `$request->query()` / `$request->session()->get()` の `mixed` は
      `is_string()` で narrowing してから使う（`(string) $sessionId` は `$hasSessionId` 成立後）
- [x] DTO を返している（配列返却なし。props は `BillingDashboardDto` 1 本）
- [x] `match ($session->status)` の `default => null` で網羅（`status` は string カラム）
- [x] `BillingFeedbackKind::tryFrom()` で未知値を null に落とす（`from()` の例外に頼らない）

### テスト計画
施策 7 に集約。

### リスク
- **`?session_id` 着地の HTTP ステータスが 200 → 303 に変わる**。
  影響を受ける既存テスト: `SubscriptionPmReuseTest` の
  「他 org / setup_payment_method の session_id は 303 しない (IDOR 防御)」が `assertOk()` を期待。
  → 新契約では「T1004 の highlight 着地にならない（info flash を積まない）」が守るべき不変条件なので、
  テスト名と assertion を更新する（施策 7）。**IDOR 防御そのものは変更しない**。
- **Inertia の redirect 追従**: `/billing?session_id=` への着地は Stripe からの full page GET
  （`Inertia::location()` 経由）なので、通常の 303 追従で問題ない。
  SPA 内から `?session_id` 付き `/billing` へ visit する導線は存在しない（`rg` 確認済み）。
- **flash の消費タイミング**: hop の直後の GET が `feedback` を読む。
  `HandleInertiaRequests::share()` の `flash.*` 4 キーとは別キーなので、toast へは流れない
  （バナーと toast の二重表示にならない）。

---

## 施策 4: `?replayed` / `?retry` を廃止し、発行側で flash する

### 変更箇所
- `app/Http/Controllers/Billing/BillingController.php` `checkout()` (L253, L266-268)

### 波及変更
- TypeScript型定義: なし / API Resource・DTO: なし
- テストファイル: `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php` (L165, L184, L470),
  `tests/Feature/Billing/CheckoutStaleThresholdTest.php` (L64)

### 現行コード

```php
} catch (StaleCheckoutAttemptException) {
    return redirect()->route('billing.index', ['retry' => 1]);
}
// ...
if ($result->url === null) {
    return $subscriptions->isAttemptCompleted($organization, $result->stripeSessionId)
        ? redirect()->route('billing.index', ['replayed' => 1])
        : back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。');
}
```

### 変更後コード

```php
} catch (StaleCheckoutAttemptException) {
    // 着地 query は発明しない: 自前 redirect なので最初から one-shot flash に載せる。
    return $this->redirectWithFeedback(BillingFeedbackKind::CheckoutRetryRequired);
}
// ...
if ($result->url === null) {
    // url=null は「新規 Checkout を作らなかった」= 受付済み replay か live pending dedup。
    return $subscriptions->isAttemptCompleted($organization, $result->stripeSessionId)
        ? $this->redirectWithFeedback(BillingFeedbackKind::PurchaseAlreadyReceived)
        : back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。');
}

/**
 * 自前 redirect で /billing の one-shot feedback を出す (query を経由しない)。
 * Inertia の POST 応答は middleware が 302 → 303 に変換する。
 */
private function redirectWithFeedback(BillingFeedbackKind $kind): RedirectResponse
{
    return redirect()->route('billing.index')->with(BillingFeedbackKind::FLASH_KEY, $kind->value);
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`RedirectResponse`）
- [x] enum 値を直接渡すため文字列リテラルの再発明がない

### テスト計画
- Feature（回帰）: completed 行の token 再送 → `assertRedirect('/billing')` +
  `assertSessionHas(BillingFeedbackKind::FLASH_KEY, 'purchase_already_received')`
- Feature（回帰）: expired / failed / stale pending の token 再送 → `assertRedirect('/billing')` +
  `assertSessionHas(BillingFeedbackKind::FLASH_KEY, 'checkout_retry_required')`
- Feature: redirect 追従後の render でバナーが 1 回だけ出る（`BillingFeedbackTest`）

### リスク
- URL 契約の変更（`/billing?replayed=1` → `/billing`）。外部に公開している URL ではなく、
  アプリ内 redirect 先なので後方互換の必要がない（思考原則 3: 並走させない）。

---

## 施策 5: Svelte の誤ったコメントを訂正

### 変更箇所
- `resources/js/pages/Billing/Index.svelte` (L41-45)

### 波及変更
- TypeScript型定義: なし（props 不変）
- テストファイル: `tests/js/pages/Billing/Index.test.ts` は**変更不要**
  （`feedback` prop 契約が不変。既存 3 ケースはそのまま通る）

### 現行コード

```svelte
    /**
     * P9: 決済戻り着地の one-shot フィードバック。**raw query は一切見ない** —
     * kind → variant の写像だけを持ち、文言はサーバ確定値をそのまま描画する。
     * 一度表示したら消える (リロードで query が落ちれば feedback は null で届く)。
     */
```

### 変更後コード

```svelte
    /**
     * P9: 決済戻り着地の one-shot フィードバック。**raw query は一切見ない** —
     * kind → variant の写像だけを持ち、文言はサーバ確定値をそのまま描画する。
     *
     * one-shot はサーバが担保する: 着地 query は canonical URL へ 303 で畳まれ、
     * feedback は次の 1 リクエストだけ生きる session flash で届く。
     * したがってリロード / 戻る / ブックマークでは feedback=null になりバナーは復活しない
     * (クライアント側の URL scrub は行わない)。
     */
```

- ロジック変更なし。`onMount` の `?highlight=auto-recharge` 処理も現状維持
  （副作用のない scroll anchor であり、着地 hop でも保持される）。

### PHPStan適合チェック
- 該当なし（TypeScript / Svelte）。`pnpm typecheck` / `pnpm lint` は通る（コメントのみ）。

### テスト計画
- JS: 既存 `tests/js/pages/Billing/Index.test.ts` の
  「feedback=null では何も描画しない (リロードで消える one-shot)」「raw query を参照しない」を維持。

### リスク
- なし（コメントのみ）。ただし**このコメントの誤りが今回の finding の温床**だったため、
  「サーバが担保する」ことを明記して同種の乖離を防ぐ。

---

## 施策 6: 正本 (`docs/architecture.md`) に one-shot 契約を明記

### 変更箇所
- `docs/architecture.md` §サブスク契約 Checkout とオンボーディング着地 の
  「**着地 feedback (P9)**」項 (L286-291)

### 波及変更
- なし（ドキュメントのみ）

### 現行記述

```markdown
- **着地 feedback (P9)**: `Inertia::location()` の full page redirect を跨いだ後、
  `/billing` 着地で one-shot バナーを出す (`BillingFeedbackKind`: purchase_received /
  purchase_processing / purchase_already_received / checkout_retry_required / portal_returned)。
  org スコープ + intent 検証で **fail-closed**、UI は raw query を見ない。
  `PurchaseFormState::Completed` 撤去後、**購入完了を伝える唯一の経路**。
```

### 変更後記述

```markdown
- **着地 feedback (P9)**: `Inertia::location()` の full page redirect を跨いだ後、
  `/billing` 着地で one-shot バナーを出す (`BillingFeedbackKind`: purchase_received /
  purchase_processing / purchase_already_received / checkout_retry_required / portal_returned)。
  org スコープ + intent 検証で **fail-closed**、UI は raw query を見ない。
  `PurchaseFormState::Completed` 撤去後、**購入完了を伝える唯一の経路**。
  - **one-shot の定義**: 「サーバが同じ状態を再主張しない」こと。着地 query
    (`?session_id` / `?portal`) を認識したら **feedback の有無に関わらず canonical `/billing` へ
    303** で畳み、kind は `BillingFeedbackKind::FLASH_KEY` の session flash
    (次の 1 リクエストのみ生存) で運ぶ。着地 URL が履歴に残らないため、リロード・戻る・
    ブックマークでバナーが復活しない (bfcache による DOM 復元まで禁じる契約ではない)。
    アプリ自身が出す `checkout_retry_required` / `purchase_already_received` は
    query を経由せず発行側で直接 flash する (着地 query を発明しない)。
  - **着地の優先順位** (先着が redirect を返したら後段は評価しない):
    `?setup_session_id` (P8a) → `?session_id` かつ funding=auto_recharge (T1004) →
    `?session_id` / `?portal` (feedback) → 通常 render。
    着地判定は **DTO 構築より前**に置く (`resolveOnboardingContinue()` が return_to を
    消費するため、hop する request で DTO を組むと復帰先を無音で失う)。
  - **副作用境界**: 着地は **GET で DB を書かない** (状態遷移は webhook の管轄)。
    canonical URL の構築は 3 着地共通のヘルパ 1 箇所で、`highlight` のみ引き継ぐ。
    error flash がある着地では feedback を出さず error を次 render へ keep する
    (成功と失敗を同時に出さない)。
```

### テスト計画
- ドキュメント自体のテストはない（Architecture テストの対象外）。
  記述内容はすべて施策 7 の Feature テストで実行時に固定される。

### リスク
- なし。

---

## 施策 7: テストで one-shot 契約を固定する

> **テストファースト**（思考原則 5）: まず `BillingFeedbackTest` の one-shot 回帰テストを
> **現行コードに対して書いて fail させる**（現行はリロード相当でもバナーが出続けるため fail する）。
> その後に施策 1-4 を実装して green にする。

### 変更ファイル一覧

| ファイル | 変更 |
|---|---|
| `tests/Feature/Billing/BillingFeedbackTest.php` | 全面更新（新契約 + one-shot 回帰） |
| `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php` | L165 / L184 / L470 の期待値更新 |
| `tests/Feature/Billing/CheckoutStaleThresholdTest.php` | L64 の期待値更新 |
| `tests/Feature/Billing/SubscriptionPmReuseTest.php` | IDOR ケース (L389-402) の期待値更新 |
| `tests/js/pages/Billing/Index.test.ts` | 変更不要（props 契約不変を確認するだけ） |

### `tests/Feature/Billing/BillingFeedbackTest.php`（新契約）

| # | テスト | 検証内容 |
|---|---|---|
| T1 | **one-shot 回帰 (F-3-04 本体)** | `?session_id=` 着地 → 303 `/billing` → 追従先 render でバナー → **同じ canonical URL を再 GET (= リロード相当) でバナーが消える**。さらに元の着地 URL が `Location` に含まれないことも確認 |
| T2 | 自 org の completed / pending は対応する kind を flash する | dataset: completed→`purchase_received` / pending→`purchase_processing` |
| T3 | **fail-closed**: failed / expired / 未知 / 他 org / setup intent | いずれも **303 `/billing`** かつ `assertSessionMissing(BillingFeedbackKind::FLASH_KEY)` |
| T4 | `?portal` は `portal_returned` を flash する | 303 + flash + 追従 render でバナー |
| T5 | `?portal` + error flash では feedback を出さず、**error を取りこぼさない** | `assertSessionMissing(FLASH_KEY)` + `assertSessionHas('error')`（hop で消えない） |
| T6 | 着地 query は必ず畳まれる（契約テスト） | dataset `session_id`(有効/無効) / `portal` の全ケースで `Location` に着地 query が含まれない |
| T7 | `highlight` は着地 hop を跨いで保持される | `?session_id=...&highlight=auto-recharge` → `Location: /billing?highlight=auto-recharge` |
| T8 | 着地の優先順位（相互排他） | `?setup_session_id=...&session_id=...` → setup 着地が勝つ（`success` flash あり / feedback flash なし） |
| T9 | C-2 との結合（既存維持） | Expired 行が遅延 completed 化した後の着地は `purchase_received` |
| T10 | 発行側 flash（施策 4 の着地側） | `checkout_retry_required` / `purchase_already_received` を flash して `/billing` を GET するとバナーが出て、次の GET で消える |

- 検証は `->assertStatus(303)` / `->assertRedirect('/billing')` / `->assertSessionHas(...)` と、
  追従後の `assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', ...))` の 2 段。
  **props だけでなく flash の有無も直接 assert する**（props が null なだけでは one-shot 前提が
  変わったときに緩む）。
- `RefreshDatabase` はグローバル適用のため個別 trait は使わない。データは
  `BillingCheckoutSession::factory()` / `createOrganizationWithOwner()` で生成する（手組み禁止）。

### 他ファイルの更新内容

```php
// SubscriptionCheckoutIdempotencyTest.php
->assertRedirect('/billing?replayed=1');
// ↓
->assertRedirect('/billing')
->assertSessionHas(BillingFeedbackKind::FLASH_KEY, BillingFeedbackKind::PurchaseAlreadyReceived->value);

->assertRedirect('/billing?retry=1');
// ↓
->assertRedirect('/billing')
->assertSessionHas(BillingFeedbackKind::FLASH_KEY, BillingFeedbackKind::CheckoutRetryRequired->value);

// SubscriptionPmReuseTest.php — テスト名も変える
test('着地 flash: 他 org / setup_payment_method の session_id は T1004 着地にならない (IDOR 防御)', ...)
    ->get('/billing?session_id='.$session->stripe_session_id)
    ->assertRedirect('/billing')                       // feedback 着地として canonical へ畳まれる
    ->assertSessionMissing('info')                     // T1004 の成功文言は出ない
    ->assertSessionMissing(BillingFeedbackKind::FLASH_KEY); // feedback も出ない (fail-closed)
```

### Architecture テストを新設しない理由

- 固定したい不変条件は「着地 query が canonical へ畳まれること」で、
  これは**実行時の HTTP 契約**なので Feature テスト（T6）が正しい層。
  静的検査（Architecture テスト）で表現すると `route('billing.index', [...])` の
  文字列パターン検査になり、偽陽性・偽陰性の両方を生む。
- Browser テスト（Chromium + WebKit 2 レーン）も新設しない。
  ブラウザの reload は「同じ URL への再 GET」であり、303 後の canonical URL への
  再 GET を Feature テスト T1 が忠実に再現できる。実ブラウザ固有の要素（bfcache 等）は
  本 finding の争点ではない。

### リスク
- テスト更新対象が 4 ファイルに跨るため、更新漏れがあると CI で落ちる（= 検出できる）。
  `rg 'billing\?(replayed|retry|session_id)' tests/` で洗い出してから着手する。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1-7 が `BillingController::index()` / `checkout()` と `BillingFeedbackDto` / `BillingFeedbackKind` という同一の狭い面を触り、**分割すると中間状態でテストが red になる**（例: 施策 4 だけ入れて施策 3 が無いと `?replayed` 解釈が消えていないのに flash が読まれない）。1 ブランチで一括実装し、テストファーストで fail → green を確認する。 |
| 競合リスク | 同時に進行中の billing 系 TODO があると `BillingController` で衝突しうる。特に bug-hunt 由来の F-3-02 / F-3-03 / F-3-05（`BillingContactForm.svelte` / `AutoRechargeCard.svelte`）は**フロントの別ファイル**なので衝突しない。`/purchase-tickets` 側の着地整理（別 finding 候補）と同時進行する場合のみ `BillingController` 外だが規約が競合しうるため、本設計を先に入れる。 |

## 最終確認（使命・禁止事項）

- **使命への寄与**: 課金導線は North Star の中核機能ではないが、「思考ゼロ」を成立させるには
  「直前の操作と矛盾する状態を何度も突きつけない」ことが前提。決済まわりの信頼性は
  現場管理者が AI-CUE を使い続けられるかに直結する。
- **禁止事項**: #1 テスト必須（施策 7 でテストファースト）/ #2 PHPStan widen なし /
  #3 DB 破壊操作なし / #4 `response()->json()` なし（Inertia + DTO 1 本）/
  #5-6 LLM 非関与 / #7 `redirect()->intended()` 不使用（named route への redirect のみ）/
  #8 disabled ボタン非関与 / #9 Artifact 不使用。
- **セキュリティ不変条件**: #3 cross-org（org スコープ relation 経由を維持）/
  #7 課金の冪等性（状態機械・webhook に触れず、GET で DB を書かない）。
- **コーディングルール**: PHPStan level 10 適合を各施策でチェック済み、
  テストは Factory + グローバル `RefreshDatabase`、DTO パターン維持。

---

## 関連する現行コード

### app/Http/Controllers/Billing/BillingController.php (L74-135: index)
```php
    /**
     * 課金ダッシュボード (現在プラン / per-bucket チケット残高 / quota 上限 / 導線)。
     *
     * P8b (bs-14): プラン一覧は /billing/plans へ移設し、ここは請求ダッシュボードに寄せる。
     * props は BillingDashboardDto の 1 本 (禁止事項 #4)。
     */
    public function index(
        Request $request,
        TicketLedgerService $tickets,
        QuotaService $quota,
        PricingService $pricing,
    ): Response|RedirectResponse {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // カード登録 (mode=setup) の着地。GET で副作用を起こさないよう、検証済みの
        // ?setup_session_id を消費して 303 + flash で canonical URL へ倒す
        // (リロード・共有時に query が残らない)。
        $landing = $this->resolveAutoRechargeSetupLanding($request, $organization);
        if ($landing !== null) {
            return $landing;
        }

        // T1004: funding=auto_recharge の契約完了着地は ?highlight=auto-recharge へ 303 + flash
        // (オートリチャージ設定への導線を成功着地の主役にする)。非該当なら通常 feedback へ委ねる。
        $autoRechargeLanding = $this->resolveAutoRechargeLanding($request, $organization);
        if ($autoRechargeLanding !== null) {
            return $autoRechargeLanding;
        }

        $canManageBilling = $user->can('manageBilling', $organization);
        $subscription = $organization->subscription('default');

        $dto = new BillingDashboardDto(
            plan: $this->resolveCurrentPlan($organization, $pricing),
            billingState: $this->access->state($organization),
            currentPeriodEnd: $subscription instanceof Subscription
                ? $subscription->current_period_end?->toIso8601String()
                : null,
            balance: $tickets->balance($organization),
            quotas: QuotaLimitsDto::fromLimits($quota->limits($organization)),
            canManageBilling: $canManageBilling,
            continueUrl: $this->resolveOnboardingContinue($organization),
            // P8a: オートリチャージ設定カード。subscription 有無に依存せず常に非 null
            // (無料パーソナル含む全プランが対象。**既定は enabled=false の opt-in**)。
            autoRecharge: $this->autoRecharge->settingsFor($organization, $canManageBilling),
            // カード登録開始 POST の attempt_token (render 単位。setup は課金を伴わないため
            // 購入導線のようなサーバ側安定化は不要 — 同一 token の再送は台帳 unique で冪等)。
            autoRechargeSetupToken: strtolower((string) Str::ulid()),
            // P9: 決済戻り着地の one-shot フィードバック (query 解釈済み)。
            feedback: $this->resolveBillingFeedback($request, $organization),
            // P9: 請求先連絡先 (未設定なら owner email が実際の宛先)。
            billingContact: BillingContactDto::fromOrganization($organization),
        );

        return Inertia::render('Billing/Index', ['page' => $dto->toArray()]);
    }

    /**
```

### app/Http/Controllers/Billing/BillingController.php (L240-275: checkout の redirect 部)
```php
            $result = $subscriptions->startCheckout(
                $organization,
                $user,
                $plan,
                route('billing.index').'?session_id={CHECKOUT_SESSION_ID}',
                route('billing.plans'),
                $attemptToken,
                $funding,
            );
        } catch (SubscriptionAttemptPlanMismatchException $e) {
            // 同 token・別 plan (1 render 1 token のため「戻って別プランを押す」で実在する)
            throw ValidationException::withMessages(['plan_code' => $e->getMessage()]);
        } catch (StaleCheckoutAttemptException) {
            return redirect()->route('billing.index', ['retry' => 1]);
        } catch (CheckoutInProgressException $e) {
            return back()->with('error', $e->getMessage());
        } catch (StripePriceNotSyncedException) {
            // production の sync 漏れ。500 にせず現行と同一文言で差し戻す
            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
        } catch (InvalidArgumentException $e) {
            // 既に有効なサブスクリプションがある / Price 未設定 (service 層の fail-closed ガード)
            return back()->with('error', $e->getMessage());
        }

        if ($result->url === null) {
            // url=null は「新規 Checkout を作らなかった」= 受付済み replay か live pending dedup。
            return $subscriptions->isAttemptCompleted($organization, $result->stripeSessionId)
                ? redirect()->route('billing.index', ['replayed' => 1])
                : back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。');
        }

        // 契約開始が成立したのでプラン意図を消費する (checkout URL 取得後・遷移前)。
        // 開始不可の back() 経路では forget しない = 意図を維持して再試行できる。
        $this->intendedPlanResolver->forgetForOrganization($organization);

        // 外部 URL への遷移は Inertia::location (full page redirect)
```

### app/Http/Controllers/Billing/BillingController.php (L370-511: 着地 resolver 3 種)
```php
     * カード登録着地 (`?setup_session_id=...`) を検証して 303 + flash に倒す。
     *
     * - session id は**自 org の SetupPaymentMethod 台帳行**に一致する場合のみ成功文言を出す
     *   (cross-org の session id を投げ込んでも成功と誤認させない = IDOR 防御)
     * - 状態の書き込みは webhook (SetDefaultPaymentMethodJob) の管轄。ここは表示のみ
     *   = **GET で副作用を起こさない**
     * - 欠落時は素通し (通常の課金ページ表示)
     */
    private function resolveAutoRechargeSetupLanding(Request $request, Organization $organization): ?RedirectResponse
    {
        $sessionId = $request->query('setup_session_id');
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        $session = BillingCheckoutSession::query()
            ->where('organization_id', $organization->getKey())
            ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
            ->where('stripe_session_id', $sessionId)
            ->first();

        if ($session === null) {
            // 未追跡 session — 成功文言は出さず canonical URL へ倒すだけ (query を残さない)。
            return redirect()->route('billing.index', [], 303);
        }

        $message = $session->status === CheckoutSessionStatus::Completed->value
            ? 'お支払いカードを登録しました。'
            : 'お支払いカードの登録を受け付けました。反映まで少しお待ちください。';

        return redirect()->route('billing.index', [], 303)->with('success', $message);
    }

    /**
     * P9 (T1004): funding=auto_recharge の契約完了着地を `?highlight=auto-recharge` へ 303 する。
     *
     * 自 org の `subscription_start` + `completed` + `funding_choice=auto_recharge` を検証できた
     * ときだけ変換する (他 org / `setup_payment_method` の session_id は素通し = IDOR 防御)。
     * 文言は「実際に PM 流用 Job が dispatch 済み (= 決済確定) かつ有効な事前同意が待機中」の
     * ときだけ確定表現にし、それ以外は fail-closed な誘導文言に落とす。
     */
    private function resolveAutoRechargeLanding(Request $request, Organization $organization): ?RedirectResponse
    {
        $sessionId = $request->query('session_id');
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        $session = $organization->checkoutSessions()
            ->where('stripe_session_id', $sessionId)
            ->first();

        if (! $session instanceof BillingCheckoutSession
            || $session->intent !== CheckoutIntent::SubscriptionStart->value
            || $session->status !== CheckoutSessionStatus::Completed->value
            || $session->funding_choice !== SignupFundingChoice::AutoRecharge->value) {
            return null; // それ以外は従来どおり resolveBillingFeedback に委ねる
        }

        $message = $session->pm_reuse_dispatched_at !== null
            && $this->autoRecharge->isAutoEnablePending($organization)
            ? 'お支払いを受け付けました。オートリチャージは、ご契約のお支払いカードで自動的に有効になります。反映されない場合は、この画面から設定できます。'
            : 'お支払いを受け付けました。オートリチャージの設定はこの画面から確認できます。';

        // reflash() はしない: 成功着地で直前の error flash まで延命すると
        // 「成功と失敗が同時に出る」着地を作るため (feedback は本 info 文言だけを主張する)。
        return redirect()
            ->route('billing.index', ['highlight' => 'auto-recharge'], 303)
            ->with('info', $message);
    }

    /**
     * P9: /billing 着地時の query を解釈してフィードバックを構築する (one-shot)。
     *
     * UI は raw query を見ず、この DTO のみを描画する。`session_id` は **org スコープ relation**
     * 経由でのみ引くため、他 org の session_id を付けても feedback は出ない (偽 success 排除)。
     * さらに **intent !== subscription_start は null** に倒す (fail-closed。P8a の
     * `setup_payment_method` 行が同一テーブルに実在するため必須)。
     */
    private function resolveBillingFeedback(Request $request, Organization $organization): ?BillingFeedbackDto
    {
        if ($request->query('portal') !== null) {
            // error flash がある着地では成功偽装を抑止するため portal_returned を出さない。
            if (is_string($request->session()->get('error'))) {
                return null;
            }

            return BillingFeedbackDto::simple(
                BillingFeedbackKind::PortalReturned,
                'お支払い管理画面から戻りました。',
            );
        }

        $sessionId = $request->query('session_id');
        if (is_string($sessionId) && $sessionId !== '') {
            $session = $organization->checkoutSessions()
                ->where('stripe_session_id', $sessionId)
                ->first();

            // 未知 / 別 org の session_id (手動付与) は feedback を出さない。
            if (! $session instanceof BillingCheckoutSession) {
                return null;
            }
            // intent 検証で fail-closed (カード登録の着地に購入文言を出さない)。
            if ($session->intent !== CheckoutIntent::SubscriptionStart->value) {
                return null;
            }

            if ($session->status === CheckoutSessionStatus::Completed->value) {
                return BillingFeedbackDto::simple(
                    BillingFeedbackKind::PurchaseReceived,
                    'お支払いを受け付けました。プランへの反映には数分かかる場合があります。',
                );
            }

            if ($session->status === CheckoutSessionStatus::Pending->value) {
                return BillingFeedbackDto::simple(
                    BillingFeedbackKind::PurchaseProcessing,
                    'お支払いを確認しています。プラン反映までしばらくお待ちください。',
                );
            }

            // Failed / Expired は無言 (状態を主張しない。出口は Plans からの新規 token 発行)。
            return null;
        }

        if ($request->query('replayed') !== null) {
            return BillingFeedbackDto::simple(
                BillingFeedbackKind::PurchaseAlreadyReceived,
                'この内容のお支払いは既に受け付け済みです。',
            );
        }

        if ($request->query('retry') !== null) {
            return BillingFeedbackDto::simple(
                BillingFeedbackKind::CheckoutRetryRequired,
                'お手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
            );
        }

        return null;
    }
```

### app/Http/Controllers/Billing/BillingController.php (L513-560: resolveOnboardingContinue / portal)
```php
    /**
     * 契約成立着地でのみ「元の画面に戻る」導線を出す (1 回限り = リロードで CTA が残らない)。
     *
     * 判定は BillingAccess::state()->grantsAccess() 一本 (subscription 直参照も
     * `?session_id` 依存もしない)。未契約 org では peek すらせず return_to を維持する
     * (契約前に消費すると本来の復帰先が失われる)。
     */
    private function resolveOnboardingContinue(Organization $organization): ?string
    {
        if (! $this->access->state($organization)->grantsAccess()) {
            return null;
        }

        $continue = $this->returnResolver->peekForOrganization($organization);
        if ($continue === null) {
            return null;
        }

        $this->returnResolver->forgetForOrganization($organization);

        return $continue;
    }

    /**
     * Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理)。
     *
     * P8b (bs-11): Portal は Stripe customer + サブスク前提。free personal
     * (canceled サブスク行が残る paid→free を含む = billingState で判定) / 未契約 org は
     * Cashier の assertCustomerExists() 例外 (= 500) に到達させず error flash で back する。
     */
    public function portal(Request $request, SubscriptionService $subscriptions): SymfonyResponse|RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        if ($this->access->state($organization) === OnboardingBillingState::ActiveFreePlan
            || ! $organization->subscription('default') instanceof Subscription) {
            return back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。');
        }

        // 戻り着地で `portal_returned` feedback を出すため ?portal=1 を付ける (UI は raw query を見ない)。
        return Inertia::location(
            $subscriptions->createPortalSession($organization, route('billing.index', ['portal' => 1]))->url,
        );
    }
}
```

### app/Enums/Billing/BillingFeedbackKind.php
```php
<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * P9: 課金 Checkout / portal の着地フィードバック種別。
 * Inertia::location() の full page redirect を跨いだ後、/billing 着地で
 * ユーザーに「購入を受け付けたか / 処理中か / 既に受付済みか」を伝える。
 *
 * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
 * 唯一の経路が本 feedback (one-shot)** になっている。
 */
enum BillingFeedbackKind: string
{
    case PurchaseReceived = 'purchase_received';
    case PurchaseProcessing = 'purchase_processing';
    case PurchaseAlreadyReceived = 'purchase_already_received';
    case CheckoutRetryRequired = 'checkout_retry_required';
    case PortalReturned = 'portal_returned';
}
```

### app/DataTransferObjects/Billing/BillingFeedbackDto.php
```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\BillingFeedbackKind;

/**
 * P9: /billing 着地時のフィードバック。
 * Controller が query (session_id / portal / replayed / retry) を解釈して構築し、
 * UI は raw query を見ずにこの DTO のみを描画する。
 *
 * @phpstan-type SimpleBillingFeedbackKind 'purchase_received'|'purchase_processing'|'purchase_already_received'|'checkout_retry_required'|'portal_returned'
 * @phpstan-type BillingFeedbackShape array{kind: SimpleBillingFeedbackKind, message: string}
 */
final readonly class BillingFeedbackDto
{
    private function __construct(
        public BillingFeedbackKind $kind,
        public string $message,
    ) {}

    /**
     * CTA を持たない通常フィードバック (purchase_received / processing / already / retry / portal)。
     */
    public static function simple(BillingFeedbackKind $kind, string $message): self
    {
        return new self($kind, $message);
    }

    /**
     * @return BillingFeedbackShape
     */
    public function toArray(): array
    {
        /** @var SimpleBillingFeedbackKind $kindValue */
        $kindValue = $this->kind->value;

        return [
            'kind' => $kindValue,
            'message' => $this->message,
        ];
    }
}
```

### app/Http/Middleware/HandleInertiaRequests.php (L60-85: flash 共有)
```php
            'organizations' => $this->organizationsProp($user),
            'currentOrganization' => $this->currentOrganizationProp($user),
            // 通知センターの未読数 (全 org 横断・自分宛のみ)。closure = Inertia partial reload で
            // 省略可能 (将来の router.reload({ only: ['notifications'] }) ポーリング拡張にも使える)
            'notifications' => [
                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                'visitKey' => Str::uuid()->toString(),
            ],
            // 問い合わせ CTA の宛先 (内部 /contact / 外部 URL / mailto を config 駆動で切替)。
            'contact' => fn (): array => [
                'url' => app(ContactUrl::class)->resolve(),
                'kind' => app(ContactUrl::class)->kind()->value,
            ],
            // サーバ描画 <title> と同一文字列を共有し、SPA 遷移後の document.title 陳腐化を解消する
            // (resources/js/lib/document-title.ts が同期)。SeoManager は request-scoped で
            // SeoComposer と同じ実体 (二重 SoT を作らない)。controller の set / setPrivateTitle は
            // share 評価時点 (response 構築時) で反映済み。
            'title' => fn (): string => $this->seoManager->resolveDocumentTitle($request->route()?->getName()),
        ];
    }
```

### resources/js/lib/stores/flash-to-toast.ts
```ts
import { addToast } from "@/lib/stores/toast";

/**
 * Laravel flash → toast 変換。
 *
 * Inertia の shared props (flash) は Layout の再評価ごとに同じ値で再注入されるため、
 * visit ごとに一意な visitKey で de-dup し、同一 visit の flash は一度だけ消費する。
 */

export interface FlashPayload {
    success?: string | null;
    error?: string | null;
    info?: string | null;
    warning?: string | null;
    /** visit ごとに一意なキー (de-dup 用)。backend が flash と一緒に発行する */
    visitKey?: string | null;
}

/** 最後に消費した visitKey (モジュール変数で保持し、同一 visit の再評価を抑止する) */
let lastVisitKey: string | null = null;

/** flash の各キーと toast type の対応 (キーが入っていれば対応する type で addToast する) */
const FLASH_KEYS = ["success", "error", "info", "warning"] as const;

/**
 * flash payload を toast に変換して enqueue する。
 * 同じ visitKey は一度だけ消費する。visitKey 不在時は de-dup 不能のため消費しない
 * (stale props の再評価で同じ通知を二重表示しないことを優先する)。
 */
export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.visitKey ?? null;
    if (!key || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (message) {
            addToast(flashKey, message);
        }
    }
}

/** de-dup 状態をリセットする (テスト用。アプリコードからは呼ばない) */
export function resetFlashConsumption(): void {
    lastVisitKey = null;
}
```

### resources/js/pages/Billing/Index.svelte (L36-105)
```svelte
    // Personal (free) はサブスクなし。Stripe portal / 次回請求日などサブスク前提の UI を出さない。
    const isFreePlan = $derived(page.billingState === "active_free_plan");

    let portalProcessing = $state(false);

    /**
     * P9: 決済戻り着地の one-shot フィードバック。**raw query は一切見ない** —
     * kind → variant の写像だけを持ち、文言はサーバ確定値をそのまま描画する。
     * 一度表示したら消える (リロードで query が落ちれば feedback は null で届く)。
     */
    const FEEDBACK_VARIANTS = {
        purchase_received: "success",
        purchase_processing: "info",
        purchase_already_received: "info",
        checkout_retry_required: "warning",
        portal_returned: "info",
    } as const satisfies Record<BillingFeedbackKind, AlertType>;

    const feedbackVariant = $derived(
        page.feedback === null ? null : FEEDBACK_VARIANTS[page.feedback.kind],
    );

    const formatYen = (amount: number | null): string =>
        amount === null ? "—" : new Intl.NumberFormat("ja-JP").format(amount);

    const formatLimit = (value: number | null): string => (value === null ? "無制限" : String(value));

    function openPortal(): void {
        router.post(
            "/billing/portal",
            {},
            {
                onStart: () => {
                    portalProcessing = true;
                },
                onFinish: () => {
                    portalProcessing = false;
                },
            },
        );
    }

    // ?highlight=auto-recharge の着地 anchor (購入画面等からの誘導。scroll のみ・副作用なし)。
    onMount(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get("highlight") === "auto-recharge") {
            const card = document.querySelector('[data-testid="auto-recharge-card"]');
            card?.scrollIntoView({ behavior: "smooth" });
            card?.setAttribute("data-highlighted", "true");
        }
    });
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="プランとお支払い"
            description="この組織のプランとチケット残高を管理します。"
            icon={CreditCard}
            testId="billing-heading"
        />
        <PageContent>
            <div class="flex flex-col gap-10">
                {#if page.feedback !== null && feedbackVariant !== null}
                    <Alert type={feedbackVariant} testId="billing-feedback">
                        <span data-testid={`billing-feedback-${page.feedback.kind}`}>
                            {page.feedback.message}
                        </span>
                    </Alert>
                {/if}
```

### tests/Feature/Billing/BillingFeedbackTest.php (現行全文)
```php
<?php

declare(strict_types=1);

use App\Enums\CheckoutSessionStatus;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Organization;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Events\WebhookReceived;

/*
 * P9: /billing 着地の one-shot フィードバック。
 *
 * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
 * 唯一の経路**がこれ。UI は raw query を見ず DTO のみを描画する。
 * session_id は org スコープ relation 経由でのみ引き、intent 検証で fail-closed にする。
 */

test('自 org の completed / pending は対応する kind を返し、failed / expired は null', function (string $state, ?string $kind): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $factory = BillingCheckoutSession::factory()->for($organization);
    $stated = match ($state) {
        'completed' => $factory->completed(),
        'pending' => $factory,
        'failed' => $factory->failed(),
        default => $factory->expired(),
    };
    $session = $stated->create();

    $this->actingAs($owner)
        ->get('/billing?session_id='.$session->stripe_session_id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $kind === null
            ? $page->where('page.feedback', null)
            : $page->where('page.feedback.kind', $kind));
})->with([
    'completed' => ['completed', 'purchase_received'],
    'pending' => ['pending', 'purchase_processing'],
    'failed' => ['failed', null],
    'expired' => ['expired', null],
]);

test('他 org / 未知 / intent=setup_payment_method の session_id は feedback を出さない', function (string $case): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$foreign] = createOrganizationWithOwner('他組織');

    $sessionId = match ($case) {
        'foreign' => BillingCheckoutSession::factory()->for($foreign)->completed()->create()->stripe_session_id,
        'setup' => BillingCheckoutSession::factory()->for($organization)->setupPaymentMethod()->completed()
            ->create()->stripe_session_id,
        default => 'cs_unknown_session',
    };

    $this->actingAs($owner)
        ->get('/billing?session_id='.$sessionId)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null));
})->with(['他 org' => ['foreign'], '未知' => ['unknown'], 'P8a の setup 行' => ['setup']]);

test('?portal は portal_returned を返すが、error flash がある着地では null (成功偽装の抑止)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get('/billing?portal=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'portal_returned'));

    $this->actingAs($owner)
        ->withSession(['error' => 'お支払い管理画面は有償プラン契約後にご利用いただけます。'])
        ->get('/billing?portal=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null));
});

test('?replayed / ?retry は中立文言の kind を返す', function (string $query, string $kind): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get('/billing?'.$query.'=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', $kind));
})->with([
    'replayed' => ['replayed', 'purchase_already_received'],
    'retry' => ['retry', 'checkout_retry_required'],
]);

test('query の無い着地では feedback=null (one-shot: リロードで消える)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = BillingCheckoutSession::factory()->for($organization)->completed()->create();

    $this->actingAs($owner)
        ->get('/billing?session_id='.$session->stripe_session_id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'purchase_received'));

    // canonical URL への再訪 (= リロード相当) では feedback が消える
    $this->actingAs($owner)
        ->get('/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null));
});

test('C-2 との結合: Expired 行が遅延 completed で Completed になった後の着地は purchase_received', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_feedback_1';
    $organization->save();

    $session = BillingCheckoutSession::factory()->for($organization)->expired()->create([
        'stripe_session_id' => 'cs_feedback_1',
        'plan_code' => 'standard',
    ]);

    event(new WebhookReceived(feedbackCompletedPayload($organization)));
    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);

    $this->actingAs($owner)
        ->get('/billing?session_id=cs_feedback_1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'purchase_received'));
});

/**
 * @return array<string, mixed>
 */
function feedbackCompletedPayload(Organization $organization): array
{
    return [
        'id' => 'evt_feedback_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_feedback_1',
            'mode' => 'subscription',
            'customer' => 'cus_feedback_1',
            'payment_status' => 'paid',
            'metadata' => [
                'purpose' => 'subscription_start',
                'org_ref' => (string) $organization->id,
                'plan_code' => 'standard',
            ],
        ]],
    ];
}
```
