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

【セキュリティ不変条件 (アプリ都合で緩めない) — AGENTS.md より】

1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404
3. cross-org 不可
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に `laratrust_team_id` を明示
6. PII(email/name)は CipherSweet
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

AI-CUE 固有の思考原則: (1) フレームワークのレンジ内でやる (自前機構の前に Laravel / 同梱モジュールの公式作法を確認する) (2) 今必要なものだけ作る (オーバーエンジニアリング禁止) (3) 後方互換の並走を残さない (4) 別物の概念を「似ているから」で統合しない (5) テストファースト (6) タコツボ実装を避ける。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript + Laravel Cashier (Stripe)
- PHPStan level 10
- Pestテストフレームワーク (RefreshDatabase グローバル適用 + --parallel)
- DTO + Inertia props パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/Inertia props パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、DTO、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI 変更を含む）: design token 経由の参照か、hex 直書きを増やさないか
11. Atomic Design準拠（UI 変更を含む）: `resources/js/components/` の atoms/molecules/organisms/templates の責務分離。本件は page-local helper (`pages/Billing/_helpers/PlanCard.svelte`) の契約を変えない方針

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: plan-change-path (契約済み組織のプラン変更経路)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### セキュリティ不変条件（本設計に効くもの）

- **#1 tenant キー不信**: `plan_code` / `current_plan_code` / `plan_change_token` はいずれも
  ownership/actor/tenant キーではない。`ProhibitsProtectedKeys` を FormRequest に適用する。
- **#3 cross-org 不可**: route parameter を持たない current-org スコープを維持する。
- **#7 課金の冪等性**: 外向き mutation に決定的 idempotency key を渡す + remote 状態照合の二層。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory / 既存ヘルパで生成**（`createOrganizationWithOwner` / `contractPaidPlan` / `createFakeSubscription`。Plan / PlanPrice は `PlanSeeder` が真実源）
- **DTO + Inertia** パターン、**アーリーリターン** 推奨
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く (Service 委譲)
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript + Cashier(Stripe)

## 概念設計リファレンス

`devnotes/20260804-0021-plan-change-path/conceptual-design.md`（Codex 概念設計レビュー Round 5 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | Gateway に subscription swap 経路を追加 | `app/Enums/Billing/SubscriptionSwapOutcome.php`(新) / `app/Services/Billing/Contracts/StripeGatewayInterface.php` / `app/Services/Billing/CashierStripeGateway.php` / `app/Services/Billing/Fakes/FakeStripeGateway.php` | 高 |
| B | `SubscriptionService::changePlan()` の新設 | `app/Services/Billing/SubscriptionService.php` / `app/Exceptions/Billing/StalePlanChangeException.php`(新) / `app/Exceptions/Billing/PlanChangeFailedException.php`(新) | 高 |
| C | route / FormRequest / Controller action | `routes/web.php` / `app/Http/Requests/Billing/ChangePlanRequest.php`(新) / `app/Http/Controllers/Billing/BillingController.php` / `lang/ja/validation.php` | 高 |
| D | プラン比較画面の送信先分岐と文言 | `app/DataTransferObjects/Billing/BillingPlansPageDto.php` / `resources/js/types/billing.ts` / `resources/js/pages/Billing/Plans.svelte` | 高 |
| E | ドキュメント / bug-hunt インベントリ更新 | `docs/architecture.md` / `.claude/skills/app-bug-hunt/operations.md` | 中 |

---

## A. Gateway に subscription swap 経路を追加

### 変更箇所

- 新規: `app/Enums/Billing/SubscriptionSwapOutcome.php`
- `app/Services/Billing/Contracts/StripeGatewayInterface.php` (L18-59 に メソッド追加)
- `app/Services/Billing/CashierStripeGateway.php` (L19-140。seam + swap 実装 + 純関数 payload builder)
- `app/Services/Billing/Fakes/FakeStripeGateway.php` (L21-54。中立帰還の実装追加)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし (enum は PHP 内部で閉じる。UI へは flash 文言としてのみ出る)
- テストファイル: 新規 2 本 (下記テスト計画)。既存の
  `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` は bind の確認のみで影響なし
- **interface にメソッドを足すため、実装 2 クラス (Cashier / Fake) を同一 PR で更新する**
  (後方互換の並走を残さない)

### 変更後コード

```php
<?php
// app/Enums/Billing/SubscriptionSwapOutcome.php
declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * subscription の base Price 差し替え (プラン変更) の結果。
 *
 * - Applied: Stripe に update を送って受理された (accepted)。
 * - AlreadyOnTargetPrice: remote が既に対象 Price だったため update を送っていない
 *   (webhook 反映待ち中の再操作 / idempotency key 期限切れ後の再操作で到達する)。
 *
 * どちらも「利用者から見た結末は同じ (対象プランで確定済み)」。呼び出し側は flash 文言の
 * 出し分けにのみ使う。`organizations.plan_code` の追随 (projection_synced) は webhook が担う。
 */
enum SubscriptionSwapOutcome: string
{
    case Applied = 'applied';
    case AlreadyOnTargetPrice = 'already_on_target_price';
}
```

```php
// app/Services/Billing/Contracts/StripeGatewayInterface.php (追加分)

    /**
     * 契約中 subscription の base Price を差し替える (プラン変更 = Stripe Subscription Update)。
     *
     * 実装は **remote の現在 Price と照合し、既に対象 Price なら update を送らない**
     * (`AlreadyOnTargetPrice`)。ローカル列 (`subscriptions.stripe_price` /
     * `organizations.plan_code`) は webhook 同期のためラグがあり判定に使えない。
     *
     * $idempotencyKey は Stripe へそのまま渡す (`change-plan:{token}:{planCode}`)。
     * Stripe SDK の object / 例外を本 interface の外へ出さない
     * (失敗は呼び出し側が `PlanChangeFailedException` に変換する契約)。
     */
    public function swapSubscriptionPrices(
        Organization $organization,
        string $basePriceId,
        string $idempotencyKey,
    ): SubscriptionSwapOutcome;
```

```php
// app/Services/Billing/CashierStripeGateway.php (追加分)

    /**
     * Stripe クライアント取得の seam (テストで差し替えるためだけに切り出す)。
     * 実装は Cashier の既定クライアントをそのまま返す。
     */
    protected function stripe(): StripeClient
    {
        return Cashier::stripe();
    }

    public function swapSubscriptionPrices(
        Organization $organization,
        string $basePriceId,
        string $idempotencyKey,
    ): SubscriptionSwapOutcome {
        $subscription = $organization->subscription('default');
        Assert::isInstanceOf($subscription, Subscription::class, '契約が見つかりません');
        $stripeId = $subscription->stripe_id;
        Assert::stringNotEmpty($stripeId, 'Stripe subscription id がありません');

        $stripe = $this->stripe();

        // item id の解決と remote 現在 Price の照合を **同じ 1 回の read** で行う。
        $remote = $stripe->subscriptions->retrieve($stripeId, ['expand' => ['items.data']]);

        /** @var list<array{id: string, priceId: string}> $items */
        $items = $this->normalizeItems($remote);

        // AI-CUE の subscription は base 1 item 固定 (席課金なし)。想定外の構成は
        // 触らずに fail-closed (多 item を無言で潰さない)。
        if (count($items) !== 1) {
            throw new UnexpectedSubscriptionShapeException($stripeId, count($items));
        }

        $item = $items[0];
        if ($item['priceId'] === $basePriceId) {
            return SubscriptionSwapOutcome::AlreadyOnTargetPrice; // update を送らない
        }

        $stripe->subscriptions->update(
            $stripeId,
            $this->buildSwapPayload($item['id'], $basePriceId),
            ['idempotency_key' => $idempotencyKey],
        );

        return SubscriptionSwapOutcome::Applied;
    }

    /**
     * subscription update payload (pure)。
     *
     * invariant (gateway 単体テストで固定):
     * - **既存 item id を指定**して price を差し替える (id 無指定は item の二重化を招く)
     * - `proration_behavior = create_prorations` — 日割り明細を作り、**次回請求に反映**する
     *   (`always_invoice` にしない = 即時請求 → 与信失敗の状態遷移を呼び込まない)
     * - `billing_cycle_anchor` / `trial_end` / `payment_behavior` は **送らない**
     *   (即時請求・trial 再開の誘発を構造的に避ける)
     *
     * @return array{
     *   items: array{array{id: string, price: string, quantity: int}},
     *   proration_behavior: 'create_prorations'
     * }
     */
    public function buildSwapPayload(string $itemId, string $basePriceId): array
    {
        Assert::stringNotEmpty($itemId);
        Assert::stringNotEmpty($basePriceId);

        return [
            'items' => [
                ['id' => $itemId, 'price' => $basePriceId, 'quantity' => 1],
            ],
            'proration_behavior' => 'create_prorations',
        ];
    }

    /**
     * remote subscription の items を {id, priceId} へ正規化する。
     * price は string id / expanded object のどちらも取り得るため両対応する
     * (`StripeWebhookProcessor::resolveStripeIdField` と同型の防御)。
     *
     * @return list<array{id: string, priceId: string}>
     */
    private function normalizeItems(StripeSubscription $remote): array
    {
        $normalized = [];
        foreach ($remote->items->data as $item) {
            $priceId = $this->resolveStripeIdField($item->price);
            if (! is_string($item->id) || $item->id === '' || $priceId === null) {
                continue; // id / price が取れない item は「無い」ものとして扱う (count 判定で fail-closed)
            }
            $normalized[] = ['id' => $item->id, 'priceId' => $priceId];
        }

        return $normalized;
    }

    /** Stripe の id フィールド (string id または expanded object) から id を取り出す。 */
    private function resolveStripeIdField(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_object($value) && property_exists($value, 'id') && is_string($value->id)) {
            return $value->id !== '' ? $value->id : null;
        }

        return null;
    }
```

> **`UnexpectedSubscriptionShapeException`**: `App\Exceptions\Billing` に新設する
> `RuntimeException` 派生。Service が `PlanChangeFailedException` へ変換して
> 「プラン変更に失敗しました…」に倒す (500 にしない / Stripe を壊さない)。
> ※ 施策 B の例外変換節に含める。

```php
// app/Services/Billing/Fakes/FakeStripeGateway.php (追加分)

    public function swapSubscriptionPrices(
        Organization $organization,
        string $basePriceId,
        string $idempotencyKey,
    ): SubscriptionSwapOutcome {
        // 中立帰還: 実 Stripe を叩かず、subscription 状態も変えない
        // (active subscription の正本は BughuntBillingSeeder。反映は webhook が担うが
        //  fake 環境では webhook が発火しないため、画面は「反映待ち」までを観測する)。
        return SubscriptionSwapOutcome::Applied;
    }
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`SubscriptionSwapOutcome`)
- [x] null 安全 (`Assert::isInstanceOf` / `Assert::stringNotEmpty` / `resolveStripeIdField` が `?string`)
- [x] 配列返却は **shape 付き** (`buildSwapPayload` の `@return array{...}`)
- [x] `mixed` を直接使わず helper で `?string` に落としてから使う
- [x] Stripe SDK の型は gateway 内部に閉じる (`StripeSubscription` は private メソッドの引数のみ)

### テスト計画

- [ ] 新規 `tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php`
  (既存 `SubscriptionCheckoutPayloadInvariantTest` と同型):
  - `buildSwapPayload()` が `items[0] = {id, price, quantity:1}` と
    `proration_behavior='create_prorations'` **だけ**を返す (キー集合を厳密一致で固定)
  - `billing_cycle_anchor` / `trial_end` / `payment_behavior` / `default_tax_rates` を**含まない**
  - 空文字 id / price は `InvalidArgumentException`
- [ ] 新規 `tests/Feature/Billing/SubscriptionSwapGatewayTest.php` (**層 0 = 制御フローの固定**):
  - `Mockery::mock(CashierStripeGateway::class)->makePartial()->shouldAllowMockingProtectedMethods()`
    で `stripe()` を `Mockery::mock(StripeClient::class)` に差し替える
    (`StripeClient::__get()` は public なので `shouldReceive('__get')->with('subscriptions')` で
    service を stub。subscription object は `\Stripe\Subscription::constructFrom([...])` で組み立て、
    **実ネットワークに出ない**)
  - remote の base item price が対象と**同一** → `subscriptions->update()` は **0 回**、
    戻り値 `AlreadyOnTargetPrice`
  - **異なる** → `update()` が **1 回**・`buildSwapPayload()` と同一 payload・
    `['idempotency_key' => 'change-plan:...']` で呼ばれ、戻り値 `Applied`
  - `retrieve` と `update` が**同一 subscription id** を使う
  - item が 0 個 / 2 個 → `UnexpectedSubscriptionShapeException` (update は 0 回)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- Stripe SDK の `items.data` shape 依存。`expand` 指定を落とすと item id が取れず
  `UnexpectedSubscriptionShapeException` に倒れる (= 課金を壊さず失敗する側に落ちる)。
- `Mockery` による partial mock は実装のメソッド名 (`stripe()`) に結合する。seam の
  リネーム時はテストも同時更新が必要 (docblock に明記する)。

---

## B. `SubscriptionService::changePlan()` の新設

### 変更箇所

- `app/Services/Billing/SubscriptionService.php` (末尾に public `changePlan` + private `changePlanLocked`)
- 新規 `app/Exceptions/Billing/StalePlanChangeException.php`
- 新規 `app/Exceptions/Billing/PlanChangeFailedException.php`
- 新規 `app/Exceptions/Billing/UnexpectedSubscriptionShapeException.php` (施策 A から throw)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規 `tests/Feature/Billing/SubscriptionPlanChangeTest.php`
- **DB 書き込みを一切行わない** ため migration / model 変更なし
  (`organizations.plan_code` の writer は `applySubscriptionSnapshot` 一本のまま = 既存不変条件を維持)

### 現行コード (関連する既存の作法)

```php
// SubscriptionService::startCheckout (L282-297) — lock の取り方と例外変換の既存作法
$result = Cache::lock("billing:checkout:start:{$org->id}", 10)->block(
    5,
    fn (): CheckoutSessionDto => $this->startCheckoutLocked(...),
);
Assert::isInstanceOf($result, CheckoutSessionDto::class);
```

### 変更後コード

```php
    /**
     * 契約中プランの **即時 swap** (Stripe Subscription Update)。
     *
     * `startCheckout` (新規契約) と排他の経路。有効な subscription が**ある**組織はこちら、
     * **ない**組織は `startCheckout` を通る (段 1 の guard が両者の境界)。
     *
     * 冪等は 2 層:
     *  1. **同一 render の二重送信** → Stripe idempotency key `change-plan:{token}:{planCode}`
     *     (`$planChangeToken` は画面 render ごとに 1 個。DB 行は作らない)
     *  2. **別 render からの再操作 / key 期限切れ後の再操作** → gateway の remote Price 照合
     *     (`AlreadyOnTargetPrice` = update を送らない)
     *
     * 本メソッドは **DB を書かない**。`organizations.plan_code` の追随は webhook
     * (`applySubscriptionSnapshot`) が唯一の writer である契約を維持する。
     *
     * 段 0: 事前 assert (Price / プラン種別) /
     * 段 1: 契約の再読込と存在 guard / 段 2: stale UI 検知 / 段 3: 変更可能 state 判定 /
     * 段 4: schedule 管理下の拒否 / 段 5: 同一プラン no-op / 段 6: Stripe swap。
     *
     * @param  string  $planChangeToken  画面 render ごとの ULID (idempotency key の素)
     * @param  string  $expectedCurrentPlanCode  画面 render 時点の現在プラン (**UX 用の
     *                                           stale 検知専用**。認可・対象決定には使わない)
     *
     * @throws StaleP1anChangeException 画面が古い (別操作でプランが変わっていた)
     * @throws CheckoutInProgressException lock 競合
     * @throws PlanChangeFailedException Stripe 由来の失敗 / 想定外の subscription 構成
     * @throws StripePriceNotSyncedException production runtime で未 sync の Price のとき
     * @throws ValidationException Stripe 決済対象外のプランのとき (422)
     * @throws \InvalidArgumentException 契約が無い / 変更できない状態のとき
     */
    public function changePlan(
        Organization $org,
        Plan $plan,
        string $planChangeToken,
        string $expectedCurrentPlanCode,
    ): SubscriptionSwapOutcome {
        // 段 0: lock を取る前に確定できる guard は先に倒す (startCheckout と同型)
        Assert::stringNotEmpty($planChangeToken, 'プラン変更トークンが不正です');

        $basePrice = $plan->currentPrice(PlanPriceKind::Base);
        Assert::isInstanceOf($basePrice, PlanPrice::class, '基本 Price 未設定のプランです');
        $this->assertPriceSynced($basePrice);
        $this->assertStripeBillablePlan($plan);

        try {
            $outcome = Cache::lock("billing:plan-change:{$org->id}", 10)->block(
                5,
                fn (): SubscriptionSwapOutcome => $this->changePlanLocked(
                    $org, $plan, $basePrice, $planChangeToken, $expectedCurrentPlanCode,
                ),
            );
            // Cache::lock()->block() は mixed を返すため型を絞る (startCheckout と同型)
            Assert::isInstanceOf($outcome, SubscriptionSwapOutcome::class);

            return $outcome;
        } catch (LockTimeoutException $e) {
            // fail-closed: ロックなし実行へフォールバックしない (二重 swap を作らない)
            throw new CheckoutInProgressException('直前の操作が進行中です。数秒お待ちください。', previous: $e);
        }
    }

    private function changePlanLocked(
        Organization $org,
        Plan $plan,
        PlanPrice $basePrice,
        string $planChangeToken,
        string $expectedCurrentPlanCode,
    ): SubscriptionSwapOutcome {
        // 段 1: lock 内で DB から読み直す (Cashier の subscription() は relation cache を
        // 返しうるため refresh する。org 側も plan_code の最新を読む)
        $org->refresh();
        $sub = $org->subscription('default');
        if (! $sub instanceof Subscription || ! $sub->valid()) {
            throw new InvalidArgumentException('変更できる契約がありません。プランのお申し込みからお手続きください。');
        }
        $sub->refresh();

        // 段 2: stale UI 検知を全判定より先に行う (古い画面からの要求はまず reject して
        // 画面を最新化させる。以降の判定は「最新画面前提」の意味を保つ)。
        if ($org->plan_code !== $expectedCurrentPlanCode) {
            throw new StalePlanChangeException(
                expectedPlanCode: $expectedCurrentPlanCode,
                actualPlanCode: $org->plan_code,
                requestedPlanCode: $plan->code,
            );
        }

        // 段 3: 変更可能 state (Active のみ)。past_due / paused / inactive は Stripe 側 mutation が
        // エラーになり得るため、理由付きでクリーンに拒否する (押下時エラー = 禁止事項 #8)。
        $state = SubscriptionState::fromSubscription($sub);
        if ($state !== SubscriptionState::Active) {
            throw new InvalidArgumentException(match ($state) {
                SubscriptionState::PastDue => 'お支払いが確認できていないため、プランを変更できません。お支払い方法をご確認ください。',
                SubscriptionState::Paused => 'ご契約が一時停止中のため、プランを変更できません。お支払い方法を登録してください。',
                SubscriptionState::UpgradeRecovery => 'ご契約の同期処理中です。数分お待ちのうえ再度お試しください。',
                default => 'ご契約が有効でないため、プランを変更できません。',
            });
        }

        // 段 4: schedule 管理下は拒否。AI-CUE は schedule を作らないが、
        // billing:reconcile-schedules が remote から復元しうる (手動 Dashboard 操作等)。
        // schedule 管理下の直接 swap は Stripe 側と衝突するため触らない。
        if ($sub->stripe_schedule_id !== null) {
            throw new InvalidArgumentException('予約済みのプラン変更があります。反映後に再度お試しください。');
        }

        // 段 5: 同一プランは no-op (lock 内の再評価。UI は現在プランの CTA を出さないが
        // 直 POST は実在しうる)
        if ($org->plan_code === $plan->code) {
            return SubscriptionSwapOutcome::AlreadyOnTargetPrice;
        }

        // 段 6: Stripe へ swap。remote Price 照合は gateway が同じ read の中で行う。
        // 変換対象は **想定された外部障害のみ** (TypeError 等は握り潰さず 500 に落とす)。
        try {
            return $this->gateway->swapSubscriptionPrices(
                $org,
                $basePrice->stripe_price_id,
                "change-plan:{$planChangeToken}:{$plan->code}",
            );
        } catch (ApiErrorException|UnexpectedSubscriptionShapeException $e) {
            Log::error('changePlan: Stripe swap failed', [
                'organization_id' => $org->getKey(),
                'plan_code' => $plan->code,
                'error' => $e->getMessage(),
            ]);

            throw new PlanChangeFailedException(
                'プラン変更に失敗しました。時間をおいて再度お試しください。',
                previous: $e,
            );
        }
    }
```

```php
<?php
// app/Exceptions/Billing/StalePlanChangeException.php
declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * 画面 render 時点のプラン (`current_plan_code`) と実際の現在プランが食い違ったとき。
 *
 * **UX 用の stale 検知専用**であり認可判定ではない (認可は Gate、変更可否は subscription 状態)。
 * Controller が 422 (`errors.plan_code`) に変換し、redirect-back で props も最新化される。
 */
final class StalePlanChangeException extends RuntimeException
{
    public function __construct(
        public readonly string $expectedPlanCode,
        public readonly ?string $actualPlanCode,
        public readonly string $requestedPlanCode,
    ) {
        parent::__construct('プラン変更の前提が変わりました。');
    }
}
```

```php
<?php
// app/Exceptions/Billing/PlanChangeFailedException.php
declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * プラン変更が Stripe 側の障害 (ApiErrorException) / 想定外の subscription 構成で失敗した。
 * **想定された外部障害だけ**を本例外に変換する (実装バグは 500 のまま調査対象にする)。
 * web 経路では Controller が back + error flash に変換する。
 */
final class PlanChangeFailedException extends RuntimeException {}
```

```php
<?php
// app/Exceptions/Billing/UnexpectedSubscriptionShapeException.php
declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * remote subscription の item 構成が AI-CUE の前提 (base 1 item) と違うとき。
 * 席課金を持たない本アプリでは発生しない想定だが、**無言で潰さず fail-closed** にする。
 */
final class UnexpectedSubscriptionShapeException extends RuntimeException
{
    public function __construct(string $stripeSubscriptionId, int $itemCount)
    {
        parent::__construct("subscription {$stripeSubscriptionId} の item 数が想定外です ({$itemCount})");
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示 (`SubscriptionSwapOutcome`)
- [x] `Cache::lock()->block()` の `mixed` を `Assert::isInstanceOf` で絞る (既存 `startCheckout` と同型)
- [x] `$org->subscription('default')` の `Subscription|null` を `instanceof` で判定
- [x] `match ($state)` は `default` を持ち網羅漏れなし
- [x] 配列返却なし (DTO/enum を返す)

### テスト計画

- [ ] 新規 `tests/Feature/Billing/SubscriptionPlanChangeTest.php`
  (gateway は `$this->mock(StripeGatewayInterface::class)` で差し替え = **層 2**):
  - **正常系**: starter 契約中 → standard へ。gateway が
    `(org, standard の current base price id, 'change-plan:{token}:standard')` で **1 回**呼ばれ、
    戻り値 `Applied`。**DB (`organizations.plan_code`) は変わらない**ことも固定する
    (webhook 前は projection 未同期 = 単一 writer 契約の回帰防止)
  - **projection_synced (層 3)**: 上記の直後に `customer.subscription.updated` payload を
    `applySubscriptionSnapshot` 経由で注入すると `organizations.plan_code` が `standard` に追随する
    (既存 `SubscriptionSnapshotSyncTest` と同型の payload 組み立て)
  - **stale**: `expectedCurrentPlanCode` が現在と不一致 → `StalePlanChangeException` /
    gateway は **0 回**
  - **同一プラン**: `AlreadyOnTargetPrice` / gateway は **0 回**
  - **state 拒否**: `past_due` / `paused` / `canceled` の契約 → `InvalidArgumentException`
    (メッセージが state ごとに異なること) / gateway は 0 回
  - **schedule 管理下**: `stripe_schedule_id` 非 null → `InvalidArgumentException` / gateway 0 回
  - **契約なし**: subscription 行が無い org → `InvalidArgumentException` / gateway 0 回
  - **プラン種別**: `personal` / `enterprise` → `ValidationException` (422) / gateway 0 回
  - **ABA**: `starter→standard` → `standard→starter` → `starter→standard` を
    別 token で 3 回実行すると **idempotency key が 3 回とも異なる**
    (2 回目・3 回目が 1 回目の replay にならない)
  - **Stripe 障害**: gateway が `ApiErrorException` を投げる → `PlanChangeFailedException` に変換
  - **想定外構成**: gateway が `UnexpectedSubscriptionShapeException` → 同上
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `SubscriptionState::UpgradeRecovery` は AI-CUE では reconcile が remote schedule を
  復元したときにしか立たない。到達不能に近いが、メッセージを用意して詰みを作らない。
- lock 競合 (`CheckoutInProgressException`) は決済系の既存文言・既存例外を再利用する
  (新しい概念を増やさない)。

---

## C. route / FormRequest / Controller action

### 変更箇所

- `routes/web.php` (L322-334 の billing ブロックへ 1 route 追加)
- 新規 `app/Http/Requests/Billing/ChangePlanRequest.php`
- `app/Http/Controllers/Billing/BillingController.php` (`checkout()` の直後に `changePlan()` 追加 +
  クラス docblock L55-62 の更新)
- `lang/ja/validation.php` (課金セクション L210-217 に attribute 2 件追加)

### 波及変更

- TypeScript 型定義: 施策 D で対応 (props 追加)
- API Resource/DTO: 施策 D で対応
- テストファイル: 新規 `tests/Feature/Billing/PlanChangeEndpointTest.php`
- **`ValidationAttributeCoverageTest`** (Architecture) が FormRequest の全 rules キーに
  `lang/ja/validation.php` の attribute 登録を要求する → `current_plan_code` /
  `plan_change_token` の登録が**必須**
- **bug-hunt インベントリ** (`operations.md`) の drift 検知対象 → 施策 E で更新

### 変更後コード

```php
// routes/web.php (billing ブロック内、billing.checkout の直後)

    // F-3-01: 契約中プランの変更 (in-app swap)。有効な subscription を**持つ**組織の経路で、
    // 持たない組織の billing.checkout と排他。Portal の subscription_update は無効のまま
    // (プラン変更はアプリが所有する = PortalConfigurationSpec の宣言どおり)。
    // 課金ゲート allowlist に置く理由: billing.* と同じく「支払い状態の是正」に到達させるため。
    Route::post('/billing/plan', [BillingController::class, 'changePlan'])
        ->name('billing.plan.change');
```

```php
<?php
// app/Http/Requests/Billing/ChangePlanRequest.php
declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\PlanCode;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 契約中プランの変更。Policy 検証 (manageBilling) は Controller 側 (Gate::authorize)。
 *
 * - `plan_code`: 変更先。「ユーザーがどのプランへ変えるか」の選択値であり状態キーではない
 *   (`organizations.plan_code` への反映は webhook 同期のみ)。
 * - `current_plan_code`: 画面 render 時点の現在プラン。**stale UI 検知専用**で認可・対象決定には
 *   使わない。変更元には personal 等も入りうるため PlanCode 全集合で domain 制約をかける。
 * - `plan_change_token`: 画面 render ごとの ULID。Stripe idempotency key
 *   `change-plan:{token}:{plan_code}` の素で、同一 render からの二重送信を収束させる。
 */
class ChangePlanRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            'current_plan_code' => ['required', 'string', Rule::enum(PlanCode::class)],
            // Str::ulid() は大文字 Crockford base32 を含むため 'ulid' ルールを使う
            // (subscription_attempt_token と同じ作法)。
            'plan_change_token' => ['required', 'ulid'],
        ], $this->protectedKeyMissingRules());
    }
}
```

```php
// app/Http/Controllers/Billing/BillingController.php (checkout() の直後に追加)

    /**
     * F-3-01: 契約中プランの変更 (in-app swap)。`checkout()` と排他の経路。
     *
     * 実行順: (1) 認可 → (2) 契約の存在確認 (無ければ 422 で新規契約導線へ) →
     * (3) plan 解決 → (4) Service へ委譲。**アプリは plan_code を直接書かない**
     * (反映は webhook 同期)。
     *
     * ボタンを disabled にはしない (禁止事項 #8) ため、ここで返す error flash / 422 が
     * 押下時のフィードバックになる。
     */
    public function changePlan(
        ChangePlanRequest $request,
        SubscriptionService $subscriptions,
    ): RedirectResponse {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        $subscription = $organization->subscription('default');
        if (! $subscription instanceof Subscription || ! $subscription->valid()) {
            // 未契約 / 失効済みは 500 (service 内 guard) に到達させず 422 で新規契約導線へ倒す。
            throw ValidationException::withMessages([
                'plan_code' => '有効なご契約がないため変更できません。プランのお申し込みからお手続きください。',
            ]);
        }

        $planCode = $request->validated('plan_code');
        Assert::string($planCode);
        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

        $token = $request->validated('plan_change_token');
        Assert::string($token);
        $expected = $request->validated('current_plan_code');
        Assert::string($expected);

        try {
            $outcome = $subscriptions->changePlan($organization, $plan, $token, $expected);
        } catch (StalePlanChangeException) {
            // 競合検知。errors.plan_code は Plans.svelte の確認 modal 内 Alert に出て、
            // redirect-back で props (currentPlanCode) も最新化される。
            throw ValidationException::withMessages([
                'plan_code' => 'プランが別の操作で変更されました。最新の内容をご確認のうえ、必要であれば改めて変更してください。',
            ]);
        } catch (StripePriceNotSyncedException) {
            // production の sync 漏れ。500 にせず checkout と同一文言で差し戻す
            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
        } catch (PlanChangeFailedException|CheckoutInProgressException|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        // accepted までを成功とし、projection (plan_code) の追随は webhook が担うことを文言で表す。
        return redirect()->route('billing.index')->with(
            'success',
            $outcome === SubscriptionSwapOutcome::Applied
                ? 'プラン変更を受け付けました。反映まで数分かかる場合があります。'
                : 'このプランへの変更は受付済みです。反映まで数分かかる場合があります。',
        );
    }
```

```php
// lang/ja/validation.php (課金セクション)
        'subscription_attempt_token' => '契約手続きトークン',
+       'current_plan_code' => '現在のプラン',
+       'plan_change_token' => 'プラン変更トークン',
```

### PHPStan適合チェック

- [x] `validated()` の戻りを `Assert::string` で絞る (既存 `checkout()` と同型)
- [x] `subscription('default')` の null を `instanceof` で判定
- [x] 戻り値型は `RedirectResponse` 単一 (`SymfonyResponse` union にしない = 外部遷移なし)
- [x] `response()->json()` を使わない (禁止事項 #4)
- [x] `redirect()->intended()` を使わない (禁止事項 #7)

### テスト計画

- [ ] 新規 `tests/Feature/Billing/PlanChangeEndpointTest.php`:
  - **認可**: `manageBilling` を持たない member → 403 / gateway 0 回
  - **未契約**: subscription 行なし → 422 (`errors.plan_code`) / gateway 0 回
  - **失効**: `canceled` subscription → 422 (`valid()` が false)
  - **正常系**: 契約中 owner → 302 `/billing` + `session('success')` が
    「プラン変更を受け付けました」を含む / gateway 1 回
  - **AlreadyOnTargetPrice**: gateway が `AlreadyOnTargetPrice` を返す → 文言が
    「受付済みです」側になる
  - **stale**: `current_plan_code` 不一致 → 422 (`errors.plan_code`)
  - **validation**: `plan_change_token` 欠落 / 非 ULID → 422、`plan_code` 未知 → 422
  - **保護キー混入**: `organization_id` を payload に載せると 422 (`ProhibitsProtectedKeys`)
  - **current-org スコープ**: 別 org の owner としてログインしても自分の current org の
    subscription しか変更されない (route parameter が無いことの回帰)
- [ ] 既存 `tests/Architecture/ValidationAttributeCoverageTest.php` が green
      (attribute 追加漏れの検出)

### リスク

- route 追加により bug-hunt インベントリの drift 検知 (`scripts/bug-hunt-inventory-check.sh` /
  `BugHuntInventoryCheckInvariantTest`) が fail する → 施策 E で同一 PR に含める。

---

## D. プラン比較画面の送信先分岐と文言

### 変更箇所

- `app/DataTransferObjects/Billing/BillingPlansPageDto.php` (2 フィールド追加 + shape 更新)
- `app/Http/Controllers/Billing/BillingController.php::plans()` (L140-158)
- `resources/js/types/billing.ts` (`BillingPlansPageProps` に 2 フィールド)
- `resources/js/pages/Billing/Plans.svelte` (送信先分岐 + 文言 + エラー表示キーの拡張)

### 波及変更

- TypeScript 型定義: `BillingPlansPageProps` に `hasChangeableSubscription` /
  `planChangeToken` を追加 (**PHP DTO の shape と exact 対**を維持)
- API Resource/DTO: `BillingPlansPageDto` の `@phpstan-type BillingPlansPageShape` を更新
- テストファイル: `tests/Feature/Billing/BillingPlansPageTest.php` (props 検証を追加) /
  `tests/js/pages/Billing/Plans.test.ts` (送信先分岐・文言)

### 変更後コード

```php
// BillingPlansPageDto (追加分)
 * @phpstan-type BillingPlansPageShape array{
 *   plans: list<PricingPlanShape>,
 *   currentPlanCode: string|null,
 *   billingState: string,
 *   canManage: bool,
 *   subscriptionAttemptToken: string,
 *   hasChangeableSubscription: bool,
 *   planChangeToken: string
 * }
...
        /**
         * 有効な subscription を持つか (= `startCheckout` が拒否し `changePlan` が受ける側)。
         * 判定は `startCheckoutLocked` 段 1 と**同一の述語** (`Subscription::valid()`) を使う
         * ため、UI がどちらの経路を選んでも「押したら循環エラー」にならない。
         */
        public bool $hasChangeableSubscription,
        /**
         * プラン変更 POST の冪等 token (画面 render ごとに固定される ULID)。
         * `subscriptionAttemptToken` (契約 checkout) とは **別 key 空間**で混ぜない。
         */
        public string $planChangeToken,
```

```php
// BillingController::plans() (追加分)
        $subscription = $organization->subscription('default');

        $dto = new BillingPlansPageDto(
            plans: $pricing->listPublicPlans(),
            currentPlanCode: $this->resolveCurrentPlanCode($organization),
            billingState: $this->access->state($organization),
            canManage: $user->can('manageBilling', $organization),
            subscriptionAttemptToken: (string) Str::ulid(),
            // startCheckout 段 1 の guard と同一述語 (valid()) で経路を分ける
            hasChangeableSubscription: $subscription instanceof Subscription && $subscription->valid(),
            planChangeToken: (string) Str::ulid(),
        );
```

```ts
// resources/js/types/billing.ts (追加分)
export interface BillingPlansPageProps {
    readonly plans: readonly PricingPlanShape[];
    /** 表示用の現在プラン code (gate 判定には使わない) */
    readonly currentPlanCode: string | null;
    readonly billingState: BillingStateValue;
    readonly canManage: boolean;
    /** 契約 checkout の冪等 token (チケット購入 / カード登録とは別 key 空間) */
    readonly subscriptionAttemptToken: string;
    /** 有効な契約があるか (true = プラン変更経路 / false = 新規契約 checkout 経路) */
    readonly hasChangeableSubscription: boolean;
    /** プラン変更 POST の冪等 token (契約 checkout とは別 key 空間) */
    readonly planChangeToken: string;
}
```

```svelte
<!-- resources/js/pages/Billing/Plans.svelte (差分の要点) -->
<script lang="ts">
    // サーバ validation エラーは dialog 内に出す (3 キーのいずれか = 最初に見つかったもの)。
    const planCodeError = $derived.by<string | null>(() => {
        const errors = inertiaPage.props.errors as Record<string, string> | undefined;
        return errors?.plan_code ?? errors?.current_plan_code ?? errors?.plan_change_token ?? null;
    });

    const targetPlan = $derived(
        page.plans.find((plan) => plan.code === confirmingPlanCode) ?? null,
    );
    const currentPlanAmount = $derived(
        page.plans.find((plan) => plan.code === page.currentPlanCode)?.baseAmountJpy ?? null,
    );
    // 文言の出し分けにのみ使う (可否判定はサーバ)。
    const isDowngrade = $derived(
        page.hasChangeableSubscription &&
            targetPlan !== null &&
            currentPlanAmount !== null &&
            (targetPlan.baseAmountJpy ?? 0) < currentPlanAmount,
    );

    const confirmMessage = $derived.by<string>(() => {
        const name = targetPlan?.name ?? "";
        if (!page.hasChangeableSubscription) {
            return `プランを「${name}」に変更します。よろしいですか？お支払い手続きの画面 (Stripe) に移動します。`;
        }
        const base =
            `プランを「${name}」に変更します。変更は即時に反映され、` +
            `差額は日割りで次回のご請求に反映されます。`;
        return isDowngrade
            ? base +
                  "新しいプランの上限 (プロジェクト数・メンバー数・保存容量) を超えている場合、" +
                  "既存のデータは削除されませんが、上限内に収まるまで新規作成とアップロードができません。"
            : base;
    });

    function submitPlanChange(): void {
        const planCode = confirmingPlanCode;
        if (planCode === null || submitting) return;

        // 有効な契約がある組織は in-app swap、無い組織は従来の Checkout。
        // 判定述語はサーバ (Subscription::valid()) と同一なので循環エラーにならない。
        const url = page.hasChangeableSubscription ? "/billing/plan" : "/billing/checkout";
        const payload = page.hasChangeableSubscription
            ? {
                  plan_code: planCode,
                  current_plan_code: page.currentPlanCode,
                  plan_change_token: page.planChangeToken,
              }
            : { plan_code: planCode, subscription_attempt_token: page.subscriptionAttemptToken };

        router.post(url, payload, { /* 既存の onStart / onFinish / onSuccess と同一 */ });
    }
</script>
```

- `ConfirmDialog` の `message` を `confirmMessage` に差し替える (他の props は現行のまま)。
- **CTA / `canSwitchTo` / `switchBlockedReasonFor` は変更しない** (禁止事項 #8 の現行実装を維持)。

### PHPStan適合チェック

- [x] DTO の `@phpstan-type` shape と `toArray()` のキー集合が一致
- [x] `subscription('default')` の null を `instanceof` で判定してから `valid()`
- [x] TS 側は `readonly` フィールドを exact 対で追加 (`pnpm typecheck`)

### テスト計画

- [ ] `tests/Feature/Billing/BillingPlansPageTest.php` に追加:
  - 有償契約中の org → `page.hasChangeableSubscription === true` / `page.planChangeToken` が
    非空 ULID
  - ActiveFreePlan / 未契約 org → `false`
  - `canceled` subscription が残る org → `false` (`valid()` の意味を固定)
- [ ] `tests/js/pages/Billing/Plans.test.ts` に追加:
  - `hasChangeableSubscription: true` → `/billing/plan` へ
    `{plan_code, current_plan_code, plan_change_token}` を POST (`subscription_attempt_token` を
    **載せない**)
  - `hasChangeableSubscription: false` → 現行どおり `/billing/checkout`
  - downgrade (`baseAmountJpy` が小さいプラン) の確認ダイアログに「上限内に収まるまで新規作成と
    アップロードができません」が出る / upgrade では出ない
  - `errors.current_plan_code` だけが返ったときも dialog 内 Alert に出る
- [ ] 既存 `tests/js/pages/Billing/PlanCard.test.ts` は無変更で green (CTA 契約は不変)

### リスク

- `currentPlanCode` が null の契約中 org (未知 Price 等) では `current_plan_code` が null で
  送信され 422 になる。**dialog にサーバ文言が出て再読込を促す**ため詰みではない
  (テストで文言経路を固定する)。
- props 追加は `BillingPlansPageDto::toArray()` のキー集合を厳密一致で検証している既存テストに
  影響する可能性 → 同一 PR で更新する。

---

## E. ドキュメント / bug-hunt インベントリ更新

### 変更箇所

- `docs/architecture.md` §「サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運用契約」
  (L245 以降) に **プラン変更 (in-app swap) の運用契約** を追記。
  `Billing/PortalConfigurationSpec` の表記 (L140) には「プラン変更はアプリが所有する
  (`SubscriptionService::changePlan`)」の実体ができたことを反映。
- `.claude/skills/app-bug-hunt/operations.md` の操作一覧に
  `| POST | billing/plan | billing.plan.change | S5 | 通常 |` を追加し、
  認可注記 (L89-90 付近) の manageBilling 一覧にも加える。

### 追記内容 (docs/architecture.md)

```markdown
- **契約中プランの変更 (in-app swap / F-3-01)**: `POST /billing/plan` (`billing.plan.change`) →
  `SubscriptionService::changePlan()`。**有効な subscription を持つ組織専用**の経路で、
  持たない組織の `billing.checkout` と `Subscription::valid()` を境に排他
  (どちらの CTA も `/billing/plans` から出るが、送信先はサーバが決めた
  `hasChangeableSubscription` で分かれる)。
  - guard 順: 契約再読込 → stale UI 検知 (`current_plan_code`。UX 専用) → 変更可能 state
    (Active のみ) → schedule 管理下の拒否 → 同一プラン no-op → Stripe swap
  - Stripe への更新は `proration_behavior=create_prorations` (日割りは**次回請求に反映**。
    `always_invoice` は使わない = 即時請求の与信失敗遷移を持ち込まない)
  - 冪等は 2 層: 同一 render の二重送信は idempotency key `change-plan:{token}:{planCode}`、
    別 render からの再操作は **gateway の remote Price 照合** (`AlreadyOnTargetPrice` =
    update を送らない)
  - **`organizations.plan_code` は書かない**。反映 (projection_synced) は
    `customer.subscription.updated` → `applySubscriptionSnapshot` が唯一の writer
  - Customer Portal の `subscription_update` は **無効のまま** (プラン変更はアプリが所有する)
```

### テスト計画

- [ ] `scripts/bug-hunt-inventory-check.sh` が drift 0 で終了する
      (`tests/Architecture/BugHuntInventoryCheckInvariantTest` が CI で検証)
- [ ] docs は機械検証対象外だが、`docs/architecture.md` の記述と実装の乖離が出ないよう
      施策 A〜D と同一コミットで更新する

### リスク

- インベントリ更新漏れは CI で fail する (fail-closed) ため、検出漏れのリスクは低い。

---

## 実装順序

1. **A** (enum + interface + 2 実装 + gateway テスト) — 他施策の前提
2. **B** (Service + 例外 3 種 + service テスト)
3. **C** (route + FormRequest + Controller + lang + endpoint テスト)
4. **D** (DTO + TS + Svelte + page/JS テスト)
5. **E** (docs + inventory)

各段階で `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` を green に保つ。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | Gateway interface / Service / Controller / route / DTO / TS 型 / Svelte / docs / bug-hunt インベントリの **9 レイヤを協調変更**する。特に `StripeGatewayInterface` へのメソッド追加は実装 2 クラスの同時更新が必須で、部分適用が壊れる |
| 競合リスク | `BillingController` / `Billing/Plans.svelte` / `routes/web.php` は他の bug-hunt 由来 TODO (課金着地バナー等) と競合しうる。**同時実装を避け、マージ順を直列化する** |

---

## 関連する現行コード (抜粋)

### app/Services/Billing/SubscriptionService.php (抜粋: L242-360, L559-627)
    /**
     * P9: Stripe Checkout (サブスク契約) を **冪等状態機械** として開始する。
     *
     * クエリは常に `intent=subscription_start` でスコープする (`UNIQUE(organization_id,
     * intent, attempt_token)` の intent 軸が P8a のカード登録 token 空間と分ける)。
     * live 判定は `BillingCheckoutSession` の述語 (C-1) だけを使い、独自の日付比較を書かない。
     *
     * 段 0: 事前 assert + 基準時刻 / 段 1: 既存 subscription guard /
     * 段 2: 同 token 行 (別 plan → 422 / replayable → 再生 / それ以外 → stale) /
     * 段 3: 同 plan の live pending dedup (org-wide) / 段 4: 別 plan の live pending を expire /
     * 段 5: Stripe 作成 → DB 記録 / 段 6: UNIQUE 違反の re-read 収束 (500 にしない)。
     *
     * @param  SignupFundingChoice|null  $funding  T1004: 行の funding_choice に記録する
     *                                             (null = 従来の契約 checkout = PM 流用しない)
     *
     * @throws SubscriptionAttemptPlanMismatchException 同 token・別 plan の再送 (Controller が 422)
     * @throws StaleCheckoutAttemptException 期限切れ / 終端済み token の再送
     * @throws CheckoutInProgressException lock 競合 / 別 plan session の整理失敗 / 決済処理中
     * @throws StripePriceNotSyncedException production runtime で未 sync の Price のとき
     * @throws ValidationException Stripe 決済対象外のプランのとき (422)
     * @throws \InvalidArgumentException 既に有効なサブスクリプションがあるとき
     */
    public function startCheckout(
        Organization $org,
        User $user,
        Plan $plan,
        string $successUrl,
        string $cancelUrl,
        string $attemptToken,
        ?SignupFundingChoice $funding,
    ): CheckoutSessionDto {
        // 段 0: 事前 assert (lock を取る前に確定できる guard は先に倒す)
        Assert::stringNotEmpty($attemptToken, '契約手続きトークンが不正です');
        $this->assertCheckoutReady($org);

        $basePrice = $plan->currentPrice(PlanPriceKind::Base);
        Assert::isInstanceOf($basePrice, PlanPrice::class, '基本 Price 未設定のプランです');
        $this->assertPriceSynced($basePrice);
        $this->assertStripeBillablePlan($plan);

        try {
            $result = Cache::lock("billing:checkout:start:{$org->id}", 10)->block(
                5,
                fn (): CheckoutSessionDto => $this->startCheckoutLocked(
                    $org, $user, $plan, $basePrice, $successUrl, $cancelUrl, $attemptToken, $funding,
                ),
            );
            // Cache::lock()->block() は mixed を返すため型を絞る (TicketCheckoutService と同型)。
            Assert::isInstanceOf($result, CheckoutSessionDto::class);

            return $result;
        } catch (LockTimeoutException $e) {
            // fail-closed: ロックなし実行へフォールバックしない (二重 subscription を作らない)
            throw new CheckoutInProgressException('直前の操作が進行中です。数秒お待ちください。', previous: $e);
        }
    }

    /**
     * 要件 7: (org, user) スコープ外に同 token 行が在るか。
     * true なら Controller が **Gate より前に 404** を返す (存在オラクル封じ)。
     */
    public function attemptTokenIsForeign(string $attemptToken, Organization $org, User $user): bool
    {
        if ($attemptToken === '') {
            return false;
        }

        return BillingCheckoutSession::query()
            ->where('intent', CheckoutIntent::SubscriptionStart->value)
            ->where('attempt_token', $attemptToken)
            ->where(function (Builder $q) use ($org, $user): void {
                /** @var Builder<BillingCheckoutSession> $q */
                $q->where('organization_id', '!=', $org->getKey())
                    ->orWhereNull('initiated_by_user_id')
                    ->orWhere('initiated_by_user_id', '!=', $user->getKey());
            })
            ->exists();
    }

    /**
     * 指定 session id の自 org 行が Completed か (Controller の `?replayed=1` 分岐の判定源)。
     */
    public function isAttemptCompleted(Organization $org, string $stripeSessionId): bool
    {
        return BillingCheckoutSession::query()
            ->where('organization_id', $org->getKey())
            ->where('intent', CheckoutIntent::SubscriptionStart->value)
            ->where('stripe_session_id', $stripeSessionId)
            ->where('status', CheckoutSessionStatus::Completed->value)
            ->exists();
    }

    private function startCheckoutLocked(
        Organization $org,
        User $user,
        Plan $plan,
        PlanPrice $basePrice,
        string $successUrl,
        string $cancelUrl,
        string $attemptToken,
        ?SignupFundingChoice $funding,
    ): CheckoutSessionDto {
        // lock closure 先頭で基準時刻を 1 回だけ取り、段 2/3/4 の live 判定を共有述語へ通す (C-1)。
        $now = CarbonImmutable::now();
        $threshold = BillingCheckoutSession::staleThresholdAt($now);

        // 段 1: 既存 subscription guard
        $existing = $org->subscription('default');
        Assert::true(
            ! $existing instanceof Subscription || ! $existing->valid(),
            '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
        );

        // 段 2: 同 token 行 (intent=subscription_start スコープ)
        $sameAttempt = $this->subscriptionAttemptQuery($org)
            ->where('attempt_token', $attemptToken)
            ->latest('id')
            ->first();


    /**
     * 契約開始前の事前検証: 請求先メールが解決できること
     * (billing_contact_email 正本 → owner email fallback)。
     */
    public function assertCheckoutReady(Organization $org): void
    {
        $email = $org->billingContactEmail();
        Assert::stringNotEmpty($email, '請求先メールが未設定です');
        Assert::regex($email, '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', '請求先メールの形式が不正です');
    }

    /** Stripe Customer Portal セッション (支払い方法・解約の自己管理) の遷移先を返す。 */
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect
    {
        return $this->gateway->createPortalSession($org, $returnUrl);
    }

    /**
     * Stripe Checkout の対象プランかを service 層で明示拒否する (validation 迂回対策)。
     * Personal (free) / Enterprise / 未知 code は fail-closed で 422。
     */
    private function assertStripeBillablePlan(Plan $plan): void
    {
        $planCode = PlanCode::tryFrom($plan->code);
        if ($planCode === null || ! $planCode->requiresStripeCheckout()) {
            throw ValidationException::withMessages([
                'plan_code' => 'このプランは Stripe 決済の対象外です。',
            ]);
        }
    }

    /**
     * production runtime で未 sync の test mode Price を checkout に使う事故を防ぐ DB レベル guard。
     */
    private function assertPriceSynced(PlanPrice $price): void
    {
        if (! app()->environment('production')) {
            return;
        }
        if (! $price->livemode || $price->synced_at === null) {
            $lookupKey = $price->lookup_key ?? "plan_id={$price->plan_id}:kind={$price->kind}";
            throw new StripePriceNotSyncedException($lookupKey);
        }
    }

    /** base Price ID からプラン (PlanCode) を逆引きする。未知 Price は null。 */
    private function resolvePlanCodeFromPriceId(?string $priceId): ?PlanCode
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        $row = PlanPrice::query()
            ->where('stripe_price_id', $priceId)
            ->where('kind', PlanPriceKind::Base->value)
            ->first();

        if (! $row instanceof PlanPrice) {
            return null;
        }

        $plan = $row->plan;
        if (! $plan instanceof Plan) {
            return null;
        }

        return PlanCode::tryFrom($plan->code);
    }
}

### app/Services/Billing/CashierStripeGateway.php (現行全文)
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Webmozart\Assert\Assert;

/**
 * StripeGatewayInterface の Cashier (Stripe SDK) 実装。
 * PortalConfigurationSpec は同一名前空間 (App\Services\Billing) のため use 不要。
 */
final class CashierStripeGateway implements StripeGatewayInterface
{
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): CreatedCheckoutSession {
        // Cashier の `newSubscription()->checkout()` は最終的に request options 無しで
        // `checkout->sessions->create()` を呼ぶため per-request idempotency key を伝播できない。
        // 冪等キーを Stripe Checkout 作成 API へ確実に渡すため SDK を直叩きする
        // (CashierTicketCheckoutGateway と同型)。
        $organization->createOrGetStripeCustomer();

        $session = $organization->stripe()->checkout->sessions->create(
            $this->buildSubscriptionSessionPayload($organization, $stripePriceId, $successUrl, $cancelUrl, $metadata),
            ['idempotency_key' => $idempotencyKey],
        );

        // hosted mode では url / expires_at が常に返る (欠落は SDK/設定異常として fail-fast)
        Assert::string($session->url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');
        Assert::integer($session->expires_at, 'Checkout Session に expires_at がありません');

        return new CreatedCheckoutSession(
            sessionId: $session->id,
            url: $session->url,
            expiresAt: CarbonImmutable::createFromTimestamp($session->expires_at),
        );
    }

    public function expireCheckoutSession(string $stripeSessionId): string
    {
        // 決済主体は organization だが expire は session id 単独で完結する
        // (呼び出し側が自 org 行の session id のみ渡す契約)
        $session = Cashier::stripe()->checkout->sessions->expire($stripeSessionId);

        return is_string($session->status) ? $session->status : 'expired';
    }

    /**
     * subscription Checkout Session payload (pure)。
     *
     * invariant (gateway ユニットテストで固定):
     * - `subscription_data.metadata.{name,type} = 'default'` — Cashier の WebhookController が
     *   `subscriptions` 行を作る際に読むラベル。**落とすと課金成立なのに subscription 行が
     *   作られず** `BillingAccess::state()` が NoSubscription に落ちて締め出しが起きる。
     * - `subscription_data.payment_settings.save_default_payment_method = 'on_subscription'` —
     *   T1004 の PM 流用の第一候補 (`subscription.default_payment_method`) が埋まる前提。
     *
     * @param  array<string, string>  $metadata
     * @return array{
     *   mode: 'subscription',
     *   customer: string,
     *   line_items: array{array{price: string, quantity: int}},
     *   success_url: string,
     *   cancel_url: string,
     *   metadata: array<string, string>,
     *   subscription_data: array{
     *     metadata: array<string, string>,
     *     payment_settings: array{save_default_payment_method: 'on_subscription'}
     *   }
     * }
     */
    public function buildSubscriptionSessionPayload(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
    ): array {
        // createOrGetStripeCustomer() 後は必ず存在する (欠落は設定異常として fail-fast)
        $customerId = $organization->stripe_id;
        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では Checkout を作れません');

        return [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [
                [
                    'price' => $stripePriceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'subscription_data' => [
                'metadata' => [
                    'name' => 'default',
                    'type' => 'default',
                ],
                'payment_settings' => [
                    'save_default_payment_method' => 'on_subscription',
                ],
            ],
        ];
    }

    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
    {
        // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
        // subscription_update 無効の spec 準拠 configuration で portal session を作る
        // (未設定なら Dashboard 既定 configuration。PortalConfigurationSpec 参照)
        return new ExternalBillingRedirect($organization->billingPortalUrl(
            $returnUrl,
            PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
        ));
    }

    public function syncCustomerDetails(Organization $organization): void
    {
        // 実 Stripe では Cashier の Billable 同期をそのまま使う。stripe_id 未設定は no-op
        // (Cashier 側も customer 不在では更新しないが、呼び出し前提を実装側でも明示)。
        if ($organization->stripe_id === null) {
            return;
        }

        $organization->syncStripeCustomerDetails();
    }
}

### app/Services/Billing/Contracts/StripeGatewayInterface.php (現行全文)
<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;

/**
 * サブスクリプション系 Stripe 呼び出しの抽象
 * (実装: CashierStripeGateway。fake_externals 時は FakeStripeGateway を bind)。
 *
 * Stripe 呼び出しを本 interface に閉じ、Controller / Service は戻り値 DTO の URL へ
 * Inertia::location するのみ。チケット系は TicketCheckoutGateway が担う (境界を分ける)。
 */
interface StripeGatewayInterface
{
    /**
     * subscription (type=default) の hosted Checkout Session を作り snapshot を返す。
     *
     * 戻り値に session id を含むのは **webhook 照合の pin** に必須のため
     * (billing_checkout_sessions.stripe_session_id が真実源になる)。
     * $idempotencyKey は Stripe へそのまま渡す (`sub_start:{attemptToken}`)。
     *
     * @param  array<string, string>  $metadata  照合専用 (認可・org 解決には使わない)
     */
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): CreatedCheckoutSession;

    /**
     * Stripe 側 Checkout Session を expire する (別 plan の live pending 整理)。
     *
     * @return string expire 後の session status ('expired'|'complete'|...)
     */
    public function expireCheckoutSession(string $stripeSessionId): string;

    /**
     * Customer Portal セッションを作り遷移先を返す
     * (configuration は PortalConfigurationSpec 準拠。実装側で解決する)。
     */
    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect;

    /**
     * 請求先連絡先 (name 等) を Stripe Customer に同期する。
     *
     * Cashier の Billable 同期メソッドを job から直接呼ぶと fake 環境 (bug-hunt / Browser) を
     * 素通りして実 Stripe API を叩く。同期も interface 境界を通すことで fake 可能にする。
     * `stripe_id` 未設定の組織は呼び出し側で skip 済の前提 (実装側でも no-op を許容)。
     */
    public function syncCustomerDetails(Organization $organization): void;
}

### app/Http/Controllers/Billing/BillingController.php (抜粋: L55-160, L191-280)
/**
 * 課金画面 (current org スコープ)。
 *
 * - プラン変更は Stripe Checkout / Customer Portal 経由のみ (アプリは plan_code を
 *   直接書かない。organizations.plan_code は webhook で同期される)
 * - 閲覧は組織メンバー全員、Checkout / Portal / オートリチャージ設定は
 *   manageBilling (owner / admin) のみ
 */
class BillingController extends Controller
{
    use ResolvesCurrentOrganization;

    public function __construct(
        private readonly BillingAccess $access,
        private readonly IntendedPlanResolver $intendedPlanResolver,
        private readonly OnboardingReturnResolver $returnResolver,
        private readonly AutoRechargeService $autoRecharge,
    ) {}

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
     * プラン比較ページ (P8b / bs-6)。閲覧は組織メンバー全員、変更は manageBilling のみ。
     *
     * プラン台帳 → DTO の mapper は公開料金表と共有する (新 DTO を発明しない)。
     */
    public function plans(Request $request, PricingService $pricing): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $dto = new BillingPlansPageDto(
            plans: $pricing->listPublicPlans(),
            currentPlanCode: $this->resolveCurrentPlanCode($organization),
            billingState: $this->access->state($organization),
            canManage: $user->can('manageBilling', $organization),
            // P9: 契約 checkout の冪等 token (画面 render ごとに固定 = 1 render 1 token)。
            subscriptionAttemptToken: (string) Str::ulid(),
        );

        return Inertia::render('Billing/Plans', ['page' => $dto->toArray()]);
    }

    /**

    /**
     * P9: Stripe Checkout (サブスク契約) を **冪等** に開始し、Checkout URL へリダイレクトする。
     *
     * 実行順は不変条件 #2 (「不整合は認可より前に 404」) に従う:
     * (1) 他 org / 他 user の token は Gate より前に 404 (403 にしない = 存在オラクル封じ)
     * (2) 認可 (3) T1004 の事前同意記録 (4) plan 解決 → 冪等開始。
     *
     * ボタンを disabled にはしない (禁止事項 #8) ため、ここで返すエラー・422 が
     * 押下時のフィードバックになる。
     */
    public function checkout(
        BillingCheckoutRequest $request,
        SubscriptionService $subscriptions,
        AutoRechargeService $autoRecharge,
    ): SymfonyResponse|RedirectResponse {
        $organization = $this->resolveCurrentOrganization($request);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $attemptToken = $request->validated('subscription_attempt_token');
        Assert::string($attemptToken);

        // (1) 他 org / 他 user の token は 404 (Gate より前 = 存在オラクル封じ)
        abort_if($subscriptions->attemptTokenIsForeign($attemptToken, $organization, $user), 404);

        // (2) 認可
        Gate::authorize('manageBilling', $organization);

        // (3) T1004: funding=auto_recharge は事前同意 (enabled=false) を Checkout 開始前に記録する。
        //     Checkout が後段で失敗・放棄されても同意 row は無害 (enabled=false = 課金は発生しない)。
        $fundingRaw = $request->validated('funding_choice');
        $funding = is_string($fundingRaw) ? SignupFundingChoice::from($fundingRaw) : null;
        if ($funding === SignupFundingChoice::AutoRecharge) {
            $consentVersion = $request->validated('consent_version');
            Assert::stringNotEmpty($consentVersion);
            try {
                $autoRecharge->recordPreConsent($organization, $user, new AutoRechargeConsentDto($consentVersion));
            } catch (CheckoutInProgressException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        // (4) plan 解決 → 冪等開始
        $planCode = $request->validated('plan_code');
        Assert::string($planCode);
        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

        try {
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
        return Inertia::location($result->url);
    }

    /**
     * P9: 請求先連絡先 (メール / 宛名) の更新。current-org スコープ

### resources/js/pages/Billing/Plans.svelte (現行全文)
<script lang="ts">
    import { page as inertiaPage, router } from "@inertiajs/svelte";
    import { CreditCard } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { BillingPlansPageProps } from "@/types/billing";
    import type { PricingPlanShape } from "@/types/marketing";
    import PlanCard from "./_helpers/PlanCard.svelte";

    /**
     * プラン比較 (/billing/plans)。閲覧は組織メンバー全員、変更は manageBilling のみ。
     * 変更は既存の Stripe Checkout (POST /billing/checkout) へ委譲する。body は plan_code +
     * subscription_attempt_token (冪等 token。funding_choice は載せない = 契約変更経路に
     * 資金選択の提示は無い)。
     *
     * 変更できないプランでも CTA は enabled のまま描画し、理由は caption + 押下時 Alert で
     * 伝える (DESIGN.md / 禁止事項 #8)。
     */
    interface Props {
        page: BillingPlansPageProps;
    }

    let { page }: Props = $props();

    const shared = $derived(inertiaPage.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // サーバ validation エラー (旧タブからの送信・未同期プラン等) は dialog 内に出す。
    const planCodeError = $derived.by<string | null>(() => {
        const errors = inertiaPage.props.errors as Record<string, string> | undefined;
        return errors?.plan_code ?? null;
    });

    const formatLimit = (value: number | null): string => (value === null ? "無制限" : String(value));

    // Personal は個人専用の無料プラン。有効化は onboarding 経路のため本画面からは変更しない。
    const isPersonal = (plan: PricingPlanShape): boolean => plan.code === "personal";

    const canSwitchTo = (plan: PricingPlanShape): boolean => {
        if (!page.canManage) return false;
        if (page.currentPlanCode === plan.code) return false;
        if (isPersonal(plan)) return false;
        return true;
    };

    // canSwitchTo の各分岐に 1:1 対応する理由文言 (canSwitch=true では空文字)。
    const switchBlockedReasonFor = (plan: PricingPlanShape): string => {
        if (!page.canManage) return "プランを変更する権限がありません";
        if (page.currentPlanCode === plan.code) return "現在ご利用中のプランです";
        if (isPersonal(plan)) {
            return "パーソナルプラン（無料）は個人専用のため、こちらからは変更できません";
        }
        return "";
    };

    let confirmingPlanCode = $state<string | null>(null);
    let confirmOpen = $state(false);
    let submitting = $state(false);

    const planNameOf = (code: string): string =>
        page.plans.find((plan) => plan.code === code)?.name ?? code;

    function openConfirm(planCode: string): void {
        confirmingPlanCode = planCode;
        confirmOpen = true;
    }

    function closeConfirm(): void {
        confirmingPlanCode = null;
    }

    function submitPlanChange(): void {
        const planCode = confirmingPlanCode;
        if (planCode === null || submitting) return;
        router.post(
            "/billing/checkout",
            { plan_code: planCode, subscription_attempt_token: page.subscriptionAttemptToken },
            {
                onStart: () => {
                    submitting = true;
                },
                onFinish: () => {
                    submitting = false;
                },
                // 成功時のみ閉じる (validation error 時は開いたままサーバ文言を出す)
                onSuccess: () => {
                    confirmOpen = false;
                    confirmingPlanCode = null;
                },
            },
        );
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="プラン比較"
            description="現在のプランの変更・新規契約ができます"
            icon={CreditCard}
            testId="billing-plans-heading"
        />
        <PageContent>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="plans-grid">
                {#each page.plans as plan (plan.code)}
                    <PlanCard
                        {plan}
                        isCurrent={page.currentPlanCode === plan.code}
                        canSwitch={canSwitchTo(plan)}
                        switchBlockedReason={switchBlockedReasonFor(plan)}
                        {formatLimit}
                        onSwitch={openConfirm}
                    />
                {/each}
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>

<ConfirmDialog
    bind:open={confirmOpen}
    title="プラン変更の確認"
    message={`プランを「${planNameOf(confirmingPlanCode ?? "")}」に変更します。よろしいですか？お支払い手続きの画面 (Stripe) に移動します。`}
    confirmLabel="変更する"
    processing={submitting}
    onConfirm={submitPlanChange}
    onCancel={closeConfirm}
    testId="plan-change-confirm"
>
    {#snippet banner()}
        {#if planCodeError !== null}
            <div class="mb-3">
                <Alert type="danger" testId="plan-change-error">{planCodeError}</Alert>
            </div>
        {/if}
    {/snippet}
</ConfirmDialog>

### app/DataTransferObjects/Billing/BillingPlansPageDto.php (現行全文)
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\DataTransferObjects\Marketing\PricingPlanDto;
use App\Enums\Billing\OnboardingBillingState;

/**
 * プラン比較ページ (/billing/plans) の Inertia page prop。
 *
 * プラン台帳 → DTO の mapper は公開料金表と共有する (PricingService::listPublicPlans)。
 * currentPlanCode は **表示専用** の解決結果であり gate 判定には使わない
 * (判定は BillingAccess::state() 一本)。
 *
 * TS 側は resources/js/types/billing.ts の BillingPlansPageProps と exact 対で保守する。
 *
 * @phpstan-import-type PricingPlanShape from PricingPlanDto
 *
 * @phpstan-type BillingPlansPageShape array{
 *   plans: list<PricingPlanShape>,
 *   currentPlanCode: string|null,
 *   billingState: string,
 *   canManage: bool,
 *   subscriptionAttemptToken: string
 * }
 */
final readonly class BillingPlansPageDto
{
    /**
     * @param  list<PricingPlanDto>  $plans
     */
    public function __construct(
        public array $plans,
        public ?string $currentPlanCode,
        public OnboardingBillingState $billingState,
        public bool $canManage,
        /**
         * P9: 契約 checkout 開始 POST の冪等 token (画面 render ごとに固定される ULID)。
         * チケット購入の `ticketAttemptToken` / カード登録の `autoRechargeSetupToken` とは
         * **別 key 空間** (混ぜない)。**既定値を持たない** — 渡し忘れると空 token が front へ出て
         * POST が 422 になる silent failure を作らないため。
         */
        public string $subscriptionAttemptToken,
    ) {}

    /**
     * @return BillingPlansPageShape
     */
    public function toArray(): array
    {
        return [
            'plans' => array_map(
                static fn (PricingPlanDto $plan): array => $plan->toArray(),
                $this->plans,
            ),
            'currentPlanCode' => $this->currentPlanCode,
            'billingState' => $this->billingState->value,
            'canManage' => $this->canManage,
            'subscriptionAttemptToken' => $this->subscriptionAttemptToken,
        ];
    }
}

### app/Enums/Billing/SubscriptionState.php (現行全文)
<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use App\Models\Billing\Subscription;

/**
 * Subscription の派生状態。
 *
 * `Active` / `UpgradeRecovery` は流入制御を通過させる。
 * `Inactive` は `canceled` / `unpaid` / `incomplete` / `incomplete_expired` を統合した拒否状態。
 * `incomplete` / `unpaid` を `Active` に含めない理由: いずれも支払いが完了していない
 * (= 顧客カードが未承認 or 失敗) 状態のため、流入制御の目的 (= LLM コスト負担確認) に反する。
 *
 *  - `PastDue` = 有料化後 (PM 登録済) の請求失敗・dunning 中。**回復余地あり**で利用は継続させる
 *    (grantsAccess=true)。PM **無し** past_due (= trial 後カード無し dunning) は entitlement gate
 *    (`SubscriptionService::deriveEntitlement`) で別途遮断する。
 *  - `Paused` = trial 終了後カード未登録で Stripe が paused にした read-only 状態 (grantsAccess=false)。
 *
 * **重要**: 利用可否の最終判定を state 単体で行ってはならない。`grantsAccess` は state のみの粗い
 * 判定であり、PM 有無 / trial_ends_at / Stripe status snapshot を加味した最終判定は
 * `SubscriptionService::deriveEntitlement` が唯一の経路。
 *
 * 移植元の `ScheduledForUpgrade` は入力列 (`subscriptions.pending_plan_code`) が AI-CUE に無いため
 * 非移植。`upgrade_recovery_required` 列も無いため、`UpgradeRecovery` は schedule 部分完了
 * (`stripe_schedule_id` + `schedule_setup_status=Created`) の分岐のみを持つ。
 */
enum SubscriptionState: string
{
    case Active = 'active';
    case UpgradeRecovery = 'upgrade_recovery';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Inactive = 'inactive';

    /**
     * Subscription model から派生状態を導出。
     *
     * 評価順は重要 (stripe_status を最優先に保つ):
     *   1. stripe_status を最初に評価 → terminal/拒否系は即返却 (schedule_id に関わらず)
     *   2. paused / past_due は専用 state へ
     *   3. schedule_setup_status === Created (部分完了) は UpgradeRecovery 扱い
     */
    public static function fromSubscription(Subscription $sub): self
    {
        // paused / past_due は固有 state に分離 (stripe_status 最優先・schedule 状態に依らない)。
        if ($sub->stripe_status === 'paused') {
            return self::Paused;
        }
        if ($sub->stripe_status === 'past_due') {
            return self::PastDue;
        }

        // trialing は試用期間として通す。それ以外の非 active 系 (canceled/unpaid/incomplete*) は Inactive。
        $activeStatuses = ['active', 'trialing'];
        if (! in_array($sub->stripe_status, $activeStatuses, true)) {
            return self::Inactive;
        }

        // 部分完了 schedule は recovery 扱い (Stripe phases 未設定 = phase transition 起きない)。
        // enum cast 経由なので instance 比較。
        if ($sub->stripe_schedule_id !== null
            && $sub->schedule_setup_status === ScheduleSetupStatus::Created) {
            return self::UpgradeRecovery;
        }

        return self::Active;
    }

    /**
     * state 単体の粗いアクセス判定。**最終判定には使わない**
     * (`SubscriptionService::deriveEntitlement` 経由が唯一の経路)。
     *
     * - `PastDue` = true: 請求失敗中でも利用継続 (PM 無し past_due の遮断は deriveEntitlement)。
     * - `Paused` = false: trial 後カード無し read-only。
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Active, self::UpgradeRecovery, self::PastDue => true,
            self::Paused, self::Inactive => false,
        };
    }
}

### app/Http/Requests/Billing/BillingCheckoutRequest.php (現行全文 = FormRequest の作法)
<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\SignupFundingChoice;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webmozart\Assert\Assert;

/**
 * Stripe Checkout 開始。Policy 検証 (manageBilling) は Controller 側 (Gate::authorize)。
 *
 * plan_code は「ユーザーがどのプランを購入するか」の選択値であり、tenant/状態キーではない
 * (organizations.plan_code への反映は webhook 同期のみ。この値で直接書き換えることはない)。
 *
 * P9: `subscription_attempt_token` (冪等 token) を必須にする。単一契約 route が
 * Plans 経路 (funding 非提示) と Onboarding 経路 (funding 2 択) の両方を宿すため
 * `funding_choice` は **nullable** (null = 従来の契約 checkout = PM 流用しない)。
 * `funding_choice=auto_recharge` のときだけ現行 `consent_version` との完全一致を要求する
 * (不一致・欠落は 422 = recordPreConsent にも Stripe にも到達しない fail-closed)。
 */
class BillingCheckoutRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            // Str::ulid() は大文字 Crockford base32 を含むため lowercase regex 不可
            // → Laravel の 'ulid' ルールを使う。
            'subscription_attempt_token' => ['required', 'ulid'],
            'funding_choice' => [
                'nullable',
                'string',
                Rule::in(array_map(
                    static fn (SignupFundingChoice $choice): string => $choice->value,
                    SignupFundingChoice::cases(),
                )),
            ],
            'consent_version' => [
                'required_if:funding_choice,'.SignupFundingChoice::AutoRecharge->value,
                'string',
                'max:16',
                Rule::in([$this->currentAutoRechargeConsentVersion()]),
            ],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent_version.required_if' => '自動購入への同意が必要です。',
            'consent_version.in' => '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。',
        ];
    }

    private function currentAutoRechargeConsentVersion(): string
    {
        $version = config()->string('billing.auto_recharge.consent_version');
        Assert::stringNotEmpty($version, 'config billing.auto_recharge.consent_version は非空で設定してください');

        return $version;
    }
}
