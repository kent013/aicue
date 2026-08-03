Round 2 の指摘 (Warning 2 件) を反映した。再レビューを依頼する。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

## [Warning] (施策3) `?portal` はキー存在だけで `PortalReturned` になる (値検証がない)
- 判断: **対応する**
- 根拠: 指摘のとおり非対称だった。Round 1 で `session_id` には
  「canonical 判定 (キー存在) と kind 判定 (値の妥当性) の分離」を入れたのに、
  `portal` は分離していなかった。`portal()` が Stripe に渡す return_url は
  `route('billing.index', ['portal' => 1])` の **1 形だけ**なので、
  それ以外の値で状態 (「お支払い管理画面から戻りました」) を主張する理由がない。
  fail-closed の一貫性を優先する。
- 対応内容: kind 解決を
  `$isPortalReturn => $request->query('portal') === '1' ? BillingFeedbackKind::PortalReturned : null`
  に変更。キーが存在すれば 303 で畳む点は不変 (query を残さない)。
  既存テストは `?portal=1` を使っているため回帰しない。

## [Warning] (施策7) T6 に portal の不正値ケースを追加
- 判断: **対応する**
- 対応内容: T6 の dataset に `?portal`(値なし) / `?portal=forged` / `?portal[]=x` を追加し、
  303 + `assertRedirect('/billing')` + `assertSessionMissing(FLASH_KEY)` を固定。
  正常系 `?portal=1` は T4 が担当することを明記 (T4 の見出しも `?portal=1` に限定した)。

## その他 (施策 1 / 2 / 4 / 5 / 6): APPROVE
- 現状維持。

---

## 修正後の該当箇所 (施策 3 の kind 解決 / 施策 7 の T4・T6)

        // (3) kind 解決 (portal 優先。値は自分が発行した形だけを認める = fail-closed)。
        //     canonical 判定 (キー存在) と kind 判定 (値の妥当性) は分離する。
        $sessionId = $request->query('session_id');
        $kind = match (true) {
            // portal() が渡す return_url は route('billing.index', ['portal' => 1]) の 1 形だけ。
            // ?portal[]=x / ?portal=forged / 値なしは状態を主張しない (畳むだけ)。
            $isPortalReturn => $request->query('portal') === '1' ? BillingFeedbackKind::PortalReturned : null,
            // 空文字 / 配列など string でない値は無言 (fail-closed)。畳むことだけはする
            is_string($sessionId) && $sessionId !== '' => $this->resolveCheckoutReturnKind($organization, $sessionId),
            default => null,
        };

| T4 | **`?portal=1`**（`portal()` が発行する唯一の形）は `portal_returned` を flash する | 303 + flash + 追従 render でバナー |
| T6 | 着地 query は必ず畳まれる（契約テスト） | dataset: 有効 `session_id` / 未知 `session_id` / **空 `?session_id=`** / **配列 `?session_id[]=`** / `?portal=1` / **`?portal`(値なし)** / **`?portal=forged`** / **`?portal[]=x`** の全ケースで `assertStatus(303)` + `assertRedirect('/billing')`（着地 query が `Location` に残らない）。**値が不正な 5 ケース**（空/配列 session_id・値なし/forged/配列 portal）は `assertSessionMissing(FLASH_KEY)` も付ける（畳むが状態は主張しない） |

---

## 参考: 詳細設計 全文 (再掲)

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

/**
 * @phpstan-type BillingFeedbackShape array{kind: value-of<BillingFeedbackKind>, message: string}
 */
final readonly class BillingFeedbackDto
{
    // ...

    /** kind から確定文言を引いて組み立てる (文言の出典は enum 一本)。 */
    public static function fromKind(BillingFeedbackKind $kind): self
    {
        return new self($kind, $kind->message());
    }

    /**
     * @return BillingFeedbackShape
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'message' => $this->message,
        ];
    }
}
```

> `@phpstan-type SimpleBillingFeedbackKind` の**手書き literal union は削除**し、
> `value-of<BillingFeedbackKind>` に置き換える (enum 追加時の drift 防止。Codex R1 [Suggestion])。
> 併せて `toArray()` の `/** @var SimpleBillingFeedbackKind $kindValue */` も不要になる
> (PHPStan は backed enum の `->value` を literal union に narrowing する)。
> **fallback**: 万一 `composer phpstan` が `value-of<>` を解決できない場合のみ、
> 現行の手書き union を維持する (その場合も `@var` の位置は現行どおり)。

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
 *
 * `highlight` は「どのカードへスクロールするか」だけを表す**副作用のない anchor**で、
 * 状態を主張しないためリロードで再適用されても嘘にならない = 保持してよい。
 * 逆に**状態を主張する query (`session_id` / `portal` / `setup_session_id`) は保持しない**
 * — 保持すると one-shot 契約が壊れる。query を追加する人はこの基準で振り分けること。
 *
 * @var list<string>
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
     *
     * 着地判定は **キーの存在**で行う (値の妥当性では判定しない)。`?session_id=` (空) や
     * `?session_id[]=` (配列) でも canonical へ畳む = 「バナーは出ないが query は残る」を作らない。
     */
    private function resolveBillingFeedbackLanding(Request $request, Organization $organization): ?RedirectResponse
    {
        // (1) 着地判定 — 着地 query キーが無ければ素通し (通常 render)。
        //     InputBag::has() を使うのは「キーはあるが値が空/配列」も着地として畳むため。
        $isPortalReturn = $request->query->has('portal');
        $isCheckoutReturn = $request->query->has('session_id');

        if (! $isPortalReturn && ! $isCheckoutReturn) {
            return null;
        }

        // (2) error flash がある着地では成功偽装を抑止する。hop で error を消さないよう keep する
        //     (型に依存しないよう has() で判定する)
        if ($request->session()->has('error')) {
            $request->session()->keep(['error']);

            return $this->canonicalBillingRedirect($request);
        }

        // (3) kind 解決 (portal 優先。値は自分が発行した形だけを認める = fail-closed)。
        //     canonical 判定 (キー存在) と kind 判定 (値の妥当性) は分離する。
        $sessionId = $request->query('session_id');
        $kind = match (true) {
            // portal() が渡す return_url は route('billing.index', ['portal' => 1]) の 1 形だけ。
            // ?portal[]=x / ?portal=forged / 値なしは状態を主張しない (畳むだけ)。
            $isPortalReturn => $request->query('portal') === '1' ? BillingFeedbackKind::PortalReturned : null,
            // 空文字 / 配列など string でない値は無言 (fail-closed)。畳むことだけはする
            is_string($sessionId) && $sessionId !== '' => $this->resolveCheckoutReturnKind($organization, $sessionId),
            default => null,
        };

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
      `is_string()` で narrowing してから使う（キャストで黙らせない）
- [x] `$request->query` は Symfony `Request::$query` (`InputBag`) のプロパティ参照で型が付く
      （`has(): bool`）。Laravel の `query(string $key)` メソッドと**併用**するのは
      「キー存在」と「値取得」で必要な API が違うため（どちらも public な標準 API）
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
| T4 | **`?portal=1`**（`portal()` が発行する唯一の形）は `portal_returned` を flash する | 303 + flash + 追従 render でバナー |
| T5 | `?portal` + error flash では feedback を出さず、**error を取りこぼさない** | `assertSessionMissing(FLASH_KEY)` + `assertSessionHas('error')`（hop で消えない）。さらに**追従先の Inertia props で `flash.error` が届く**ことまで確認する（`keep()` の実効保証。Codex R1 [Suggestion]） |
| T6 | 着地 query は必ず畳まれる（契約テスト） | dataset: 有効 `session_id` / 未知 `session_id` / **空 `?session_id=`** / **配列 `?session_id[]=`** / `?portal=1` / **`?portal`(値なし)** / **`?portal=forged`** / **`?portal[]=x`** の全ケースで `assertStatus(303)` + `assertRedirect('/billing')`（着地 query が `Location` に残らない）。**値が不正な 5 ケース**（空/配列 session_id・値なし/forged/配列 portal）は `assertSessionMissing(FLASH_KEY)` も付ける（畳むが状態は主張しない） |
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
