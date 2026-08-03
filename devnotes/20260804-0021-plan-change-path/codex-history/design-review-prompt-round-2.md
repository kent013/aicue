Round 1 の Critical 1 件・Warning 3 件・Suggestion 1 件をすべて設計へ反映しました。反論はありません。

## 対応マトリクス

| 指摘 | 判断 | 対応 |
|---|---|---|
| [Critical] `StaleP1anChangeException` の型名タイポ | 対応 | `StalePlanChangeException` に修正 |
| [Warning] `current_plan_code` が required だと `currentPlanCode === null` で恒常 422 | 対応 | FormRequest を `['present','nullable','string',Rule::enum(PlanCode::class)]` に (キー送信は必須・値は null 可)。`changePlan()` / `changePlanLocked()` の引数と `StalePlanChangeException::$expectedPlanCode` を `?string` 化。Controller は `Assert::nullOrString`。判定は `!==` のままなので **null 同士は一致 = stale にならない**。テストに「null 一致は stale にならない」「キー欠落は 422 / 値 null は通る」を追加 |
| [Warning] stale 判定を同一プラン no-op より先に置くと実態が目標プランでも弾く | 対応 | 段順を **段 2 = 同一プラン no-op → 段 3 = stale** に入れ替え、理由を docblock 化 (実態が既に目標プランなら「変更は済んでいる」のが事実で、画面が古いことを理由に拒否するのは嘘になる)。段番号を全体で振り直し。テストに「同一プラン かつ stale → `AlreadyOnTargetPrice`」を追加 |
| [Warning] remote item の `quantity` を検証していない | 対応 | `normalizeItems()` の戻りに `quantity` を含め、`count !== 1 || quantity !== 1` を `UnexpectedSubscriptionShapeException` に倒す (暗黙補正しない)。例外に `?int $quantity` を追加。gateway テストに「quantity !== 1 → 例外 / update 0 回」を追加 |
| [Suggestion] 「即時反映」と「反映まで数分」の衝突 | 対応 | 確認ダイアログ文言を「変更は Stripe 側に即時反映され (画面表示への反映は数分かかる場合があります)、差額は日割りで次回のご請求に調整されます。」に統一 |

## 更新後の詳細設計書 (全文)

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

        /** @var list<array{id: string, priceId: string, quantity: int}> $items */
        $items = $this->normalizeItems($remote);

        // AI-CUE の subscription は **base 1 item・quantity=1 固定** (席課金なし)。
        // 想定外の構成は触らずに fail-closed (多 item / 数量付き契約を無言で潰さない)。
        if (count($items) !== 1 || $items[0]['quantity'] !== 1) {
            throw new UnexpectedSubscriptionShapeException(
                $stripeId,
                count($items),
                $items[0]['quantity'] ?? null,
            );
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
     * remote subscription の items を {id, priceId, quantity} へ正規化する。
     * price は string id / expanded object のどちらも取り得るため両対応する
     * (`StripeWebhookProcessor::resolveStripeIdField` と同型の防御)。
     * quantity 欠落は 0 に落とす (= 想定外として上位の fail-closed 判定に倒す)。
     *
     * @return list<array{id: string, priceId: string, quantity: int}>
     */
    private function normalizeItems(StripeSubscription $remote): array
    {
        $normalized = [];
        foreach ($remote->items->data as $item) {
            $priceId = $this->resolveStripeIdField($item->price);
            if (! is_string($item->id) || $item->id === '' || $priceId === null) {
                continue; // id / price が取れない item は「無い」ものとして扱う (count 判定で fail-closed)
            }
            $quantity = $item->quantity;
            $normalized[] = [
                'id' => $item->id,
                'priceId' => $priceId,
                'quantity' => is_int($quantity) ? $quantity : 0,
            ];
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
  - item は 1 個だが `quantity !== 1` (例: 2) → `UnexpectedSubscriptionShapeException`
    (update は 0 回。暗黙に 1 へ補正しない)
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
     * 段 0: 事前 assert (Price / プラン種別) / 段 1: 契約の再読込と存在 guard /
     * 段 2: 同一プラン no-op / 段 3: stale UI 検知 / 段 4: 変更可能 state 判定 /
     * 段 5: schedule 管理下の拒否 / 段 6: Stripe swap。
     *
     * **段 2 を段 3 より先に置く理由**: 実態が既に目標プランなら「変更は済んでいる」のが
     * 事実であり、画面が古いことを理由に拒否するのは利用者から見て嘘になる
     * (反映待ち中の再操作を stale として弾かない)。
     *
     * @param  string  $planChangeToken  画面 render ごとの ULID (idempotency key の素)
     * @param  string|null  $expectedCurrentPlanCode  画面 render 時点の現在プラン (**UX 用の
     *                                                stale 検知専用**。認可・対象決定には使わない。
     *                                                未知 Price 等で null になりうるため nullable)
     *
     * @throws StalePlanChangeException 画面が古い (別操作でプランが変わっていた)
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
        ?string $expectedCurrentPlanCode,
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
        ?string $expectedCurrentPlanCode,
    ): SubscriptionSwapOutcome {
        // 段 1: lock 内で DB から読み直す (Cashier の subscription() は relation cache を
        // 返しうるため refresh する。org 側も plan_code の最新を読む)
        $org->refresh();
        $sub = $org->subscription('default');
        if (! $sub instanceof Subscription || ! $sub->valid()) {
            throw new InvalidArgumentException('変更できる契約がありません。プランのお申し込みからお手続きください。');
        }
        $sub->refresh();

        // 段 2: 同一プランは no-op (反映待ち中の再操作 / 直 POST。stale 判定より先に置く)。
        if ($org->plan_code === $plan->code) {
            return SubscriptionSwapOutcome::AlreadyOnTargetPrice;
        }

        // 段 3: stale UI 検知 (以降の判定は「最新画面前提」の意味を保つ)。
        // null 同士の一致も許容する (未知 Price 等で currentPlanCode が null の画面)。
        if ($org->plan_code !== $expectedCurrentPlanCode) {
            throw new StalePlanChangeException(
                expectedPlanCode: $expectedCurrentPlanCode,
                actualPlanCode: $org->plan_code,
                requestedPlanCode: $plan->code,
            );
        }

        // 段 4: 変更可能 state (Active のみ)。past_due / paused / inactive は Stripe 側 mutation が
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

        // 段 5: schedule 管理下は拒否。AI-CUE は schedule を作らないが、
        // billing:reconcile-schedules が remote から復元しうる (手動 Dashboard 操作等)。
        // schedule 管理下の直接 swap は Stripe 側と衝突するため触らない。
        if ($sub->stripe_schedule_id !== null) {
            throw new InvalidArgumentException('予約済みのプラン変更があります。反映後に再度お試しください。');
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
        public readonly ?string $expectedPlanCode,
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
    public function __construct(string $stripeSubscriptionId, int $itemCount, ?int $quantity = null)
    {
        parent::__construct(
            "subscription {$stripeSubscriptionId} の item 構成が想定外です "
            ."(item 数: {$itemCount} / quantity: ".($quantity ?? 'null').')',
        );
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
  - **stale の null 一致**: `organizations.plan_code` が null の契約中 org に
    `expectedCurrentPlanCode = null` を渡すと **stale にならない** (段 3 を通過して swap する)
  - **同一プラン**: `AlreadyOnTargetPrice` / gateway は **0 回**
  - **同一プラン かつ stale**: 実態が既に目標プラン (`plan_code = standard`) で
    `expectedCurrentPlanCode = 'starter'` (古い画面) → **stale ではなく
    `AlreadyOnTargetPrice`** (段 2 が段 3 より先) / gateway は 0 回
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
 *   未知 Price 等で表示値が null になりうるため `present` + `nullable` (キー欠落は 422、値 null は許容)。
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
            // 表示用 currentPlanCode は未知 Price 等で null になりうる。**キーの送信は必須**
            // (present) だが値は null 可 = 恒常 422 を作らない。
            'current_plan_code' => ['present', 'nullable', 'string', Rule::enum(PlanCode::class)],
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
        Assert::nullOrString($expected);

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
  - **validation**: `plan_change_token` 欠落 / 非 ULID → 422、`plan_code` 未知 → 422、
    `current_plan_code` **キー欠落は 422** / **値 null は通る** (present + nullable の意味を固定)
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
            `プランを「${name}」に変更します。変更は Stripe 側に即時反映され` +
            `(画面表示への反映は数分かかる場合があります)、差額は日割りで次回のご請求に調整されます。`;
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

- `currentPlanCode` が null の契約中 org (未知 Price 等) では `current_plan_code: null` を
  送信する。`present` + `nullable` により 422 にならず、service 側も null 同士の一致を
  stale としない (恒常 422 を作らない)。
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
