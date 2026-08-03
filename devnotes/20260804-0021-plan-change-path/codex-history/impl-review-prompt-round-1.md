# Codex 実装レビュー依頼 (impl-review round 1) — T090 / bug-hunt F-3-01 プラン変更経路
## アプリの使命 (AGENTS.md より)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md より)

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件 (AGENTS.md より)

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)

## 実装規約 (AGENTS.md より)

## 実装規約

- `declare(strict_types=1)` + 日本語コメント。Controller は薄く(Service 委譲)、
  transaction は Service 内。保護キーは forceFill / relation で明示代入
- 新しいドメインリソースの追加手順は **Item リソースが見本**
  (`docs/app-integration-guide.md` §2 のチェックリスト)。
  新規モデル追加時は Factory の追加と `docs/architecture.md` / `docs/factories.md`
  への追記が必須
- フロントは Svelte 5 runes + DS token/ramp のみ(`DESIGN.md` が canonical、
  ds-purity テストが検出)。フォームは FormField / Checkbox atom 経由
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages`
  の単方向 import のみ(下層から上層・features の domain 間横参照・component 層から pages は
  禁止。`tests/js/architecture/atomic-import-graph.test.ts` が強制)。アイコンは
  `@lucide/svelte` のみ。Lucide に無いブランド/SSO ロゴの SVG 内包は
  `components/atoms/icons/` 配下に限る(`svg-inline-allowlist.test.ts` が強制)
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`(全 green でコミット)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。


# あなたの役割

Laravel 12 + Svelte 5 (Inertia) アプリ **AI-CUE** の実装レビュアー。以下の実装差分を、詳細設計書との一致性の観点でレビューせよ。

## レビュー観点

1. **設計との一致性** — 詳細設計書 (下記) の各施策 A〜E が実装されているか。逸脱があれば、それが正当か (実コードの意味論に合わせた等) を判定せよ。
2. **正確性** — ロジックの誤り・境界条件・競合・fail-open になっていないか。とくに **課金の冪等性** (AGENTS.md セキュリティ不変条件 #7) を壊していないか。
3. **PHPStan level 10 適合性** — 型の widen / @phpstan-ignore / baseline を使っていないか (使っていたら Critical)。
4. **DTO / JsonResource / Inertia パターン** — `response()->json()` 直書きが無いか。DTO の shape と TS 型が exact 対か。
5. **テスト網羅性** — 設計の test_plan に対して抜けが無いか。テストが実装を追認するだけで意味を持たない箇所が無いか。
6. **セキュリティ** — tenant キー不信 / current-org スコープ / 保護キー / 例外メッセージからの内部情報漏洩。
7. **DESIGN.md 準拠** — `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やしていないか。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか。
8. **Atomic Design 準拠** — `resources/js/components/` は `atoms/molecules/organisms/templates` (+ `features/{domain}` / `pages`) の責務分離と単方向 import に従っているか。アイコンは Lucide のみ、SVG 直書きを増やしていないか。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示する

## 本実装の非交渉事項 (壊していないか確認せよ)

- `organizations.plan_code` の writer は webhook (`SubscriptionService::applySubscriptionSnapshot`) **一本**。プラン変更経路では書かない。
- 外向き mutation は決定的 idempotency key を伴う (`change-plan:{token}:{planCode}`) + remote Price 照合の二層で二重 proration を作らない。
- Stripe SDK の object / 例外を gateway 境界の外へ出さない。ただし前提違反 (`Assert` の `InvalidArgumentException`) は fail-fast で 500 に落とす。
- CTA を disabled にしない (禁止事項 #8)。
- Customer Portal の `subscription_update` は無効のまま。



## 実装時に設計から逸脱した点 (レビュー対象)

1. **`CashierStripeGateway` から `final` を外した**。設計は「`stripe()` を protected seam にして Mockery の partial mock で差し替える」としていたが、Mockery は final クラスを mock できない (= 設計どおりに書くと final は外れる)。テストは Mockery partial mock ではなく **匿名 subclass で `stripe()` を override** する形にした (protected seam であることは同じ)。
2. **Cashier の `Subscription::valid()` の意味論が設計の想定と違っていた**ため、テスト期待値を実コードに合わせた:
   - `canceled` + `ends_at = null` は Cashier の `active()` が true → `valid()` **true** (設計の test_plan は false を想定していた)。
   - `past_due` は `Cashier::$deactivatePastDue` 既定 true により `active()` false → `valid()` **false** = 段 1 (`PlanChangeNotAllowedException`「変更できる契約がありません」) で止まる。段 2 の PastDue 文言はこの経路からは到達しない (防御的に残置)。
   - よって「失効済み」を表す fixture は `ends_at` を過去にしたものへ変更した。
3. **`enterprise` プランの 422 テストは書いていない**。`PlanSeeder` は personal / starter / standard しか投入せず、Plan/PlanPrice の真実源は PlanSeeder (Factory が存在しない)。決済対象外プランの回帰は `personal` で固定した (段 0 の順序 = `assertStripeBillablePlan` が先、の回帰防止という意図は満たす)。
4. `normalizeItems()` の item id 解決を `is_string()` 直接判定から `resolveStripeIdField()` 経由に変えた (PHPStan level 10 が `SubscriptionItem::$id` を string と推論して `function.alreadyNarrowedType` を出すため。**型を緩めずに** helper 経由で `?string` に落とす形で解消した)。

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
| A | Gateway に subscription swap 経路を追加 | `app/Enums/Billing/SubscriptionSwapOutcome.php`(新) / `app/Exceptions/Billing/PlanChangeFailedException.php`(新。**A の前提**。定義は施策 B に記載) / `app/Services/Billing/Contracts/StripeGatewayInterface.php` / `app/Services/Billing/CashierStripeGateway.php` / `app/Services/Billing/Fakes/FakeStripeGateway.php` | 高 |
| B | `SubscriptionService::changePlan()` の新設 | `app/Services/Billing/SubscriptionService.php` / `app/Exceptions/Billing/StalePlanChangeException.php`(新) / `app/Exceptions/Billing/PlanChangeNotAllowedException.php`(新) / `app/Exceptions/Billing/PlanChangeFailedException.php`(新。gateway と共用) | 高 |
| C | route / FormRequest / Controller action | `routes/web.php` / `app/Http/Requests/Billing/ChangePlanRequest.php`(新) / `app/Http/Controllers/Billing/BillingController.php` / `lang/ja/validation.php` | 高 |
| D | プラン比較画面の送信先分岐と文言 (props 3 件追加) | `app/DataTransferObjects/Billing/BillingPlansPageDto.php` / `app/Http/Controllers/Billing/BillingController.php::plans()` / `resources/js/types/billing.ts` / `resources/js/pages/Billing/Plans.svelte` | 高 |
| E | ドキュメント / bug-hunt インベントリ更新 | `docs/architecture.md` / `.claude/skills/app-bug-hunt/operations.md` | 中 |

---

## A. Gateway に subscription swap 経路を追加

### 変更箇所

- 新規: `app/Enums/Billing/SubscriptionSwapOutcome.php`
- 新規: `app/Exceptions/Billing/PlanChangeFailedException.php`
  (**本施策の前提**。gateway が throw する唯一の課金ドメイン例外。定義は施策 B に記載)
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
     *
     * **Stripe SDK の object も例外も本 interface の外へ出さない**。API 障害
     * (`\Stripe\Exception\ApiErrorException`) と想定外の subscription 構成は、実装側で
     * `PlanChangeFailedException` (利用者向け文言 + 診断用 reason) に変換して throw する。
     *
     * ただし **前提違反 (呼び出し規約の破り) と実装バグは変換しない**:
     * 契約行の不在 (`Assert::isInstanceOf` → `InvalidArgumentException`) や `TypeError` は
     * fail-fast でそのまま外へ出す (呼び出し側 = Service が段 1 で契約の存在を保証済みのため、
     * ここに到達するのは実装不備)。
     *
     * @throws PlanChangeFailedException Stripe API 障害 / 想定外の subscription 構成
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

        // SDK 例外を境界の外へ出さない (API 障害は PlanChangeFailedException へ変換する)。
        try {
            // item id の解決と remote 現在 Price の照合を **同じ 1 回の read** で行う。
            $remote = $stripe->subscriptions->retrieve($stripeId, ['expand' => ['items.data']]);

            // AI-CUE の subscription は **base 1 item・quantity=1 固定** (席課金なし)。
            // 想定外の構成は触らずに fail-closed (多 item / 数量付き / 解決不能 item を
            // 無言で潰さない)。normalizeItems は解決できない item があれば throw する
            // (**skip しない** = 「正常 1 件 + 不正 1 件」を 1 件として通さない)。
            $items = $this->normalizeItems($stripeId, $remote);

            if (count($items) !== 1 || $items[0]['quantity'] !== 1) {
                throw PlanChangeFailedException::unexpectedShape(
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
        } catch (ApiErrorException $e) {
            // 想定された外部障害のみ変換する (実装バグは素通しして 500 = 調査対象)。
            throw PlanChangeFailedException::stripeApiError($stripeId, $e);
        }
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
     *
     * **解決できない item が 1 つでもあれば throw する** (skip しない)。skip すると
     * 「正常 1 件 + 解決不能 1 件」が正規化後 1 件になり、多 item 契約を更新してしまうため。
     * quantity 欠落も同様に想定外として扱う。
     *
     * @return list<array{id: string, priceId: string, quantity: int}>
     */
    private function normalizeItems(string $stripeSubscriptionId, StripeSubscription $remote): array
    {
        $normalized = [];
        $rawCount = 0;
        foreach ($remote->items->data as $item) {
            $rawCount++;
            $priceId = $this->resolveStripeIdField($item->price);
            $quantity = $item->quantity;
            if (! is_string($item->id) || $item->id === '' || $priceId === null || ! is_int($quantity)) {
                // 解決不能 item は「無い」ものにせず、その場で fail-closed に倒す。
                throw PlanChangeFailedException::unexpectedShape($stripeSubscriptionId, $rawCount, null);
            }
            $normalized[] = ['id' => $item->id, 'priceId' => $priceId, 'quantity' => $quantity];
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

> **例外の境界**: **想定される外部障害 (Stripe API) と remote shape 異常は
> `PlanChangeFailedException` に統一**する (SDK 例外は境界を越えない)。
> 一方 **前提違反・実装不備は fail-fast でそのまま外へ出す**
> (`Assert::isInstanceOf`/`Assert::stringNotEmpty` の `InvalidArgumentException`、`TypeError` 等。
> Service は段 1 で契約の存在を保証しているため、到達したら実装不備)。
> 例外の定義は施策 B に記載する (`getMessage()` は常に利用者向け文言、診断は `reason`)。

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
  - item が 0 個 / 2 個 → `PlanChangeFailedException` (`reason` が `unexpected_shape:` 始まり /
    update は 0 回)
  - **正常 1 件 + price 解決不能 1 件** → 同上
    (update は 0 回。解決不能 item を skip して 1 件扱いにしない = fail-closed の回帰防止)
  - item は 1 個だが `quantity !== 1` (例: 2) → 同上 (update は 0 回。暗黙に 1 へ補正しない)
  - `retrieve` / `update` が `ApiErrorException` を投げる → `PlanChangeFailedException`
    (`getMessage()` は `USER_MESSAGE` 固定 = 内部情報を漏らさない / `reason` は
    `stripe_api_error:` 始まり / `previous` に SDK 例外)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- Stripe SDK の `items.data` shape 依存。`expand` 指定を落とすと item id が取れず
  `PlanChangeFailedException` (`reason` = `unexpected_shape:`) に倒れる
  (= 課金を壊さず失敗する側に落ちる)。
- `Mockery` による partial mock は実装のメソッド名 (`stripe()`) に結合する。seam の
  リネーム時はテストも同時更新が必要 (docblock に明記する)。

---

## B. `SubscriptionService::changePlan()` の新設

### 変更箇所

- `app/Services/Billing/SubscriptionService.php` (末尾に public `changePlan` + private `changePlanLocked`)
- 新規 `app/Exceptions/Billing/StalePlanChangeException.php`
- 新規 `app/Exceptions/Billing/PlanChangeNotAllowedException.php` (業務拒否)
- 新規 `app/Exceptions/Billing/PlanChangeFailedException.php`
  (**施策 A の gateway が throw する唯一の課金ドメイン例外**。Service は log して rethrow するだけ)

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
     * 段 2: 変更可能 state 判定 / 段 3: schedule 管理下の拒否 /
     * 段 4: stale UI 検知 (**要求先が local の現在プランと違うときだけ**) / 段 5: Stripe swap。
     *
     * **local の `organizations.plan_code` で「もう目標プランだから成功」と返さない**:
     * この列は webhook 遅延を持つ projection であり、remote が別 Price のままの可能性がある
     * (「受付済み」と嘘をつくことになる)。**同一プラン判定は gateway の remote 照合に一本化**し、
     * `Applied` / `AlreadyOnTargetPrice` は remote の事実で決める。
     *
     * stale 検知は **要求先 ≠ local 現在プラン** のときだけ行う。要求先 = local 現在プランの
     * ケース (反映待ち中の再操作 / 古い画面からの再送) を stale で誤拒否しないため。
     * **state / schedule 判定はさらに前**に置く: grace period (解約予約中) の契約は
     * `plan_code` が旧プランのまま残るため、後段で「変更できない契約なのに成功扱い」に
     * ならないようにする。
     *
     * @param  string  $planChangeToken  画面 render ごとの ULID (idempotency key の素)
     * @param  string|null  $expectedCurrentPlanCode  画面 render 時点の現在プラン (**UX 用の
     *                                                stale 検知専用**。認可・対象決定には使わない。
     *                                                未知 Price 等で null になりうるため nullable)
     *
     * @throws StalePlanChangeException 画面が古い (別操作でプランが変わっていた)
     * @throws CheckoutInProgressException lock 競合
     * @throws PlanChangeFailedException Stripe 由来の失敗 / 想定外の subscription 構成
     *                                    (gateway が変換済み。本 Service は log して rethrow)
     * @throws StripePriceNotSyncedException production runtime で未 sync の Price のとき
     * @throws ValidationException Stripe 決済対象外のプランのとき (422)
     * @throws PlanChangeNotAllowedException 契約が無い / 変更できない状態 / schedule 管理下のとき
     *                                       (**業務上の拒否**。Controller が error flash に変換する。
     *                                       前提違反の `InvalidArgumentException` とは区別する)
     */
    public function changePlan(
        Organization $org,
        Plan $plan,
        string $planChangeToken,
        ?string $expectedCurrentPlanCode,
    ): SubscriptionSwapOutcome {
        // 段 0: lock を取る前に確定できる guard は先に倒す。
        // **順序が重要**: 決済対象外プラン (personal / enterprise) は先に 422 へ倒す。
        // 後段の Assert は「Stripe 決済対象プランなのに base Price が無い」= 設定不備であり、
        // 変換せず 500 に落として調査対象にする (利用者操作では到達しない)。
        $this->assertStripeBillablePlan($plan);

        $basePrice = $plan->currentPrice(PlanPriceKind::Base);
        Assert::isInstanceOf($basePrice, PlanPrice::class, '基本 Price 未設定のプランです');
        $this->assertPriceSynced($basePrice);
        // token は FormRequest が 'ulid' で検証済み。空到達は実装不備 = fail-fast。
        Assert::stringNotEmpty($planChangeToken, 'プラン変更トークンが不正です');

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
            throw new PlanChangeNotAllowedException('変更できる契約がありません。プランのお申し込みからお手続きください。');
        }
        $sub->refresh();

        // 段 2: 変更可能 state (Active のみ)。past_due / paused / inactive (解約予約中の
        // grace period 契約を含む) は Stripe 側 mutation がエラーになり得るため、
        // **他のどの判定よりも先に**理由付きでクリーンに拒否する (押下時エラー = 禁止事項 #8)。
        $state = SubscriptionState::fromSubscription($sub);
        if ($state !== SubscriptionState::Active) {
            throw new PlanChangeNotAllowedException(match ($state) {
                SubscriptionState::PastDue => 'お支払いが確認できていないため、プランを変更できません。お支払い方法をご確認ください。',
                SubscriptionState::Paused => 'ご契約が一時停止中のため、プランを変更できません。お支払い方法を登録してください。',
                SubscriptionState::UpgradeRecovery => 'ご契約の同期処理中です。数分お待ちのうえ再度お試しください。',
                default => 'ご契約が有効でないため、プランを変更できません。',
            });
        }

        // 段 3: schedule 管理下は拒否。AI-CUE は schedule を作らないが、
        // billing:reconcile-schedules が remote から復元しうる (手動 Dashboard 操作等)。
        // schedule 管理下の直接 swap は Stripe 側と衝突するため触らない。
        if ($sub->stripe_schedule_id !== null) {
            throw new PlanChangeNotAllowedException('予約済みのプラン変更があります。反映後に再度お試しください。');
        }

        // 段 4: stale UI 検知。**要求先が local の現在プランと違うとき**だけ評価する
        // (要求先 = local 現在プランなら「反映待ち中の再操作」= 古い画面でも拒否しない)。
        // null 同士の一致も許容する (未知 Price 等で plan_code が null の画面)。
        if ($org->plan_code !== $plan->code && $org->plan_code !== $expectedCurrentPlanCode) {
            throw new StalePlanChangeException(
                expectedPlanCode: $expectedCurrentPlanCode,
                actualPlanCode: $org->plan_code,
                requestedPlanCode: $plan->code,
            );
        }

        // 段 5: Stripe へ swap。**同一プラン判定は remote の事実で行う** (local projection では
        // 判定しない)。gateway が既存 item id 解決と同じ 1 回の read で照合し、
        // 既に対象 Price なら update を送らず AlreadyOnTargetPrice を返す。
        // gateway は Stripe SDK の例外を外へ出さない契約なので、ここで扱うのは
        // PlanChangeFailedException だけ (診断は reason を log に落として rethrow)。
        try {
            return $this->gateway->swapSubscriptionPrices(
                $org,
                $basePrice->stripe_price_id,
                "change-plan:{$planChangeToken}:{$plan->code}",
            );
        } catch (PlanChangeFailedException $e) {
            Log::error('changePlan: swap failed', [
                'organization_id' => $org->getKey(),
                'plan_code' => $plan->code,
                'reason' => $e->reason, // 診断用 (利用者向け文言は getMessage())
            ]);

            throw $e;
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
// app/Exceptions/Billing/PlanChangeNotAllowedException.php
declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * **業務上の理由**でプラン変更を受け付けられないとき (契約が無い / 変更できない state /
 * schedule 管理下)。メッセージは**そのまま利用者に見せる文言**として書く。
 *
 * 前提違反・実装不備 (`Assert` の `InvalidArgumentException` / `TypeError`) とは**別型**にする:
 * Controller は本例外だけを error flash に変換し、`InvalidArgumentException` は catch せず
 * 500 に落とす (Assert の内部文言を利用者へ露出させない / 実装不備を握り潰さない)。
 */
final class PlanChangeNotAllowedException extends RuntimeException {}
```

```php
<?php
// app/Exceptions/Billing/PlanChangeFailedException.php
declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;
use Throwable;

/**
 * プラン変更が Stripe 側の障害 / 想定外の subscription 構成で失敗した。
 *
 * **`getMessage()` は常に利用者向けの固定文言**にする (Controller が
 * `back()->with('error', $e->getMessage())` に流すため、内部識別子を漏らさない)。
 * 診断情報は `$reason` に持たせ、Service が log にだけ落とす。
 *
 * 変換対象は **想定された外部障害だけ** (実装バグは 500 のまま調査対象にする)。
 * 生成は名前付きコンストラクタ経由に限定し、文言の再発明を防ぐ。
 */
final class PlanChangeFailedException extends RuntimeException
{
    public const USER_MESSAGE = 'プラン変更に失敗しました。時間をおいて再度お試しください。';

    private function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(self::USER_MESSAGE, previous: $previous);
    }

    /** Stripe API 障害 (ApiErrorException) の変換。SDK 例外は previous に格納する。 */
    public static function stripeApiError(string $stripeSubscriptionId, Throwable $previous): self
    {
        return new self(
            "stripe_api_error: subscription={$stripeSubscriptionId} / {$previous->getMessage()}",
            $previous,
        );
    }

    /**
     * remote subscription の item 構成が AI-CUE の前提 (base 1 item・quantity=1) と違うとき。
     * 席課金を持たない本アプリでは発生しない想定だが、**無言で潰さず fail-closed** にする。
     */
    public static function unexpectedShape(string $stripeSubscriptionId, int $itemCount, ?int $quantity): self
    {
        return new self(
            "unexpected_shape: subscription={$stripeSubscriptionId} / items={$itemCount} / "
            .'quantity='.($quantity ?? 'null'),
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
    `expectedCurrentPlanCode = null` を渡すと **stale にならない** (段 4 を通過して swap する)
  - **local が既に対象プラン・remote も対象 Price**: gateway が **呼ばれ**
    `AlreadyOnTargetPrice` (local projection で早期 return しない)
  - **local が既に対象プラン・remote は別 Price** (webhook が別経路で先行した等):
    gateway が **呼ばれ** `Applied` (「受付済み」と嘘をつかない)
  - **要求先 = local 現在プラン かつ 期待値が古い**: `plan_code='standard'` /
    `expectedCurrentPlanCode='starter'` で standard を要求 → **stale にならず** gateway へ進む
    (段 4 の条件が「要求先 ≠ local 現在プラン」のときだけ評価される回帰防止)
  - **要求先 ≠ local 現在プラン かつ 期待値も不一致** → `StalePlanChangeException` / gateway 0 回
  - **grace period**: `stripe_status='canceled'` かつ `ends_at` 未来 (Cashier の `valid()`=true) で
    `plan_code='standard'` の org が standard を選ぶ → 段 2 で `PlanChangeNotAllowedException`
    (成功扱いにしない) / gateway は 0 回
  - **決済対象外プラン**: `personal` を要求 → `ValidationException` (422)。
    **`InvalidArgumentException` (base Price 未設定の Assert) には落ちない**
    (段 0 の順序 = `assertStripeBillablePlan` が先、の回帰防止)
  - **state 拒否**: `past_due` / `paused` / `canceled` の契約 → `PlanChangeNotAllowedException`
    (メッセージが state ごとに異なること) / gateway は 0 回
  - **schedule 管理下**: `stripe_schedule_id` 非 null → `PlanChangeNotAllowedException` / gateway 0 回
  - **契約なし**: subscription 行が無い org → `PlanChangeNotAllowedException` / gateway 0 回
  - **プラン種別**: `personal` / `enterprise` → `ValidationException` (422) / gateway 0 回
  - **ABA**: `starter→standard` → `standard→starter` → `starter→standard` を
    別 token で 3 回実行すると **idempotency key が 3 回とも異なる**
    (2 回目・3 回目が 1 回目の replay にならない)
  - **swap 失敗**: gateway が `PlanChangeFailedException` を投げる → そのまま伝播し、
    `Log::error` に `reason` が出る (利用者向け文言 `getMessage()` は固定のまま)
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
 * - `current_plan_code`: **stale UI 検知専用**の期待値で、認可・対象決定には使わない。
 *   送信元は画面の `planChangeExpectedPlanCode` (= サーバの `organizations.plan_code` そのもの。
 *   表示用の `currentPlanCode` ではない)。変更元には personal 等も入りうるため PlanCode 全集合で
 *   domain 制約をかける。`organizations.plan_code` は null になりうるため
 *   `present` + `nullable` (キー欠落は 422、値 null は許容)。
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
            // 送信元は画面の planChangeExpectedPlanCode (= organizations.plan_code そのもの)。
            // 当該列は null になりうるため **キーの送信は必須 (present) / 値は null 可**
            // = 送信漏れは 422 で検出しつつ、正当な null で恒常 422 を作らない。
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
        } catch (PlanChangeNotAllowedException|PlanChangeFailedException|CheckoutInProgressException $e) {
            // 業務拒否 (NotAllowed) / 外部障害 (Failed) / lock 競合のみを利用者向け文言に変換する。
            // **`InvalidArgumentException` は catch しない** — Assert 由来の前提違反・設定不備は
            // 500 に落として調査対象にする (内部文言を flash に載せない)。
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
  - **業務拒否の文言**: service が `PlanChangeNotAllowedException` を投げる状態
    (`past_due` 契約) → back + `session('error')` にその文言が出る
  - **前提違反は 500**: gateway/Assert 由来の `InvalidArgumentException` は **catch されず 500**
    (Assert の内部文言が flash に載らないことを併せて確認する)
  - **外部障害の文言**: service が `PlanChangeFailedException` → flash は
    `PlanChangeFailedException::USER_MESSAGE` 固定 (内部 reason が出ない)
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

- `app/DataTransferObjects/Billing/BillingPlansPageDto.php` (3 フィールド追加 + shape 更新)
- `app/Http/Controllers/Billing/BillingController.php::plans()` (L140-158)
- `resources/js/types/billing.ts` (`BillingPlansPageProps` に 3 フィールド)
- `resources/js/pages/Billing/Plans.svelte` (送信先分岐 + 文言 + エラー表示キーの拡張)

### 波及変更

- TypeScript 型定義: `BillingPlansPageProps` に `hasChangeableSubscription` /
  `planChangeToken` / `planChangeExpectedPlanCode` を追加
  (**PHP DTO の shape と exact 対**を維持)
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
 *   planChangeToken: string,
 *   planChangeExpectedPlanCode: string|null
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
        /**
         * 楽観的競合制御 (stale UI 検知) の期待値 = **`organizations.plan_code` そのもの**。
         *
         * 表示用の `currentPlanCode` とは**別物**なので混ぜない: 表示用は
         * `BillingController::resolveCurrentPlanCode()` の projection で、ActiveFreePlan の
         * org では `free_plan_code` を返す。`hasChangeableSubscription`
         * (= `Subscription::valid()`) と ActiveFreePlan は同時に成立しうる
         * (例: `canceled` かつ期末まで有効な grace period 契約) ため、表示値を競合制御に
         * 使うと恒常 422 (stale) の詰みになる。
         */
        public ?string $planChangeExpectedPlanCode,
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
            // 競合制御の期待値は projection ではなく列そのもの (currentPlanCode と別物)
            planChangeExpectedPlanCode: $organization->plan_code,
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
    /**
     * stale UI 検知の期待値 (= サーバの organizations.plan_code)。
     * 表示用の currentPlanCode とは別物なので、この値をそのまま送る。
     */
    readonly planChangeExpectedPlanCode: string | null;
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
                  // 表示用 currentPlanCode ではなく競合制御用の期待値を送る
                  current_plan_code: page.planChangeExpectedPlanCode,
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
    非空 ULID / `page.planChangeExpectedPlanCode === organizations.plan_code`
  - **表示用と競合制御値の分離**: `free_plan_code='personal'` かつ `plan_code='standard'` の
    grace period 契約 (`valid()` は true) で、`currentPlanCode` は projection 側
    (`personal`) でも `planChangeExpectedPlanCode` は `standard` になる
  - ActiveFreePlan / 未契約 org → `false`
  - `canceled` subscription が残る org → `false` (`valid()` の意味を固定)
- [ ] `tests/js/pages/Billing/Plans.test.ts` に追加:
  - `hasChangeableSubscription: true` → `/billing/plan` へ
    `{plan_code, current_plan_code, plan_change_token}` を POST (`subscription_attempt_token` を
    **載せない**)。`current_plan_code` は `planChangeExpectedPlanCode` の値であり
    **`currentPlanCode` ではない** (両者が異なる props で固定する)
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
- **既知の境界 (本設計のスコープ外)**: `canceled` かつ期末まで有効な grace period 契約は
  `valid()`=true のため変更経路に入るが、`SubscriptionState::Inactive` で段 2 に
  `PlanChangeNotAllowedException` として拒否される (checkout も既存 guard で拒否する)。
  **理由が出るので行き止まりではない**が、
  「解約予約中の再契約」導線は本設計では扱わない (open question に記載)。

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
  - guard 順: 契約再読込 → **変更可能 state (Active のみ)** → schedule 管理下の拒否 →
    stale UI 検知 (`current_plan_code`。UX 専用。**要求先 ≠ local 現在プランのときだけ**評価) →
    Stripe swap。
    **`organizations.plan_code` が既に目標プランでも「受付済み」で早期 return しない** —
    この列は webhook 遅延を持つ projection なので、同一プラン判定は
    **gateway の remote 照合に一本化**する (`Applied` / `AlreadyOnTargetPrice` は remote の事実)。
    **state / schedule 判定は最前段**に置く — grace period (解約予約中) の契約は
    `plan_code` が旧プランのまま残るため、後段で「変更できない契約なのに成功扱い」に
    ならないようにする
  - stale 検知の期待値は **`organizations.plan_code` そのもの**
    (`planChangeExpectedPlanCode` prop)。表示用の `currentPlanCode`
    (ActiveFreePlan では `free_plan_code` を返す projection) とは別物で、混ぜると
    grace period 契約で恒常 422 になる
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

0. **例外 3 種** (`app/Exceptions/Billing/PlanChangeFailedException.php` /
   `PlanChangeNotAllowedException.php` / `StalePlanChangeException.php`) — **A と B の共通前提**
   (gateway が `PlanChangeFailedException` を throw するため A より先に置く)
1. **A** (enum + interface + 2 実装 + gateway テスト)
2. **B** (Service + service テスト)
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

## 実装差分 (git diff HEAD)

```diff
diff --git a/.claude/skills/app-bug-hunt/operations.md b/.claude/skills/app-bug-hunt/operations.md
index 18c267c..adf6adf 100644
--- a/.claude/skills/app-bug-hunt/operations.md
+++ b/.claude/skills/app-bug-hunt/operations.md
@@ -9,6 +9,7 @@ ## 操作一覧 (web セッション面)
 | method | route | name | story | 区分 |
 |---|---|---|---|---|
 | POST | billing/checkout | billing.checkout | S5 | 通常 |
+| POST | billing/plan | billing.plan.change | S5 | 通常 |
 | POST | billing/portal | billing.portal | S5 | 通常 |
 | POST | billing/auto-recharge | billing.auto-recharge.update | S5 | 通常 |
 | POST | billing/auto-recharge/setup | billing.auto-recharge.setup | S5 | 通常 |
@@ -87,7 +88,7 @@ ## 課金ゲート allowlist と認可 (P4 反転後、要検出)
 いないと開けない」= 詰み finding (H4)。
 
 - `billing.auto-recharge.update` / `billing.auto-recharge.setup` / `billing.contact.update` /
-  `billing.checkout` / `billing.tickets.checkout` の認可は Controller 冒頭の
+  `billing.checkout` / `billing.plan.change` / `billing.tickets.checkout` の認可は Controller 冒頭の
   `Gate::authorize('manageBilling')` (owner / admin)。member は 403、他組織はそもそも
   current org スコープ (route parameter なし) で構造的に到達不能。
 - `onboarding.activate-personal` は `throttle:10,1` 付き。連打時に 429 が UX として
diff --git a/app/DataTransferObjects/Billing/BillingPlansPageDto.php b/app/DataTransferObjects/Billing/BillingPlansPageDto.php
index 44f9a67..909f973 100644
--- a/app/DataTransferObjects/Billing/BillingPlansPageDto.php
+++ b/app/DataTransferObjects/Billing/BillingPlansPageDto.php
@@ -23,7 +23,10 @@
  *   currentPlanCode: string|null,
  *   billingState: string,
  *   canManage: bool,
- *   subscriptionAttemptToken: string
+ *   subscriptionAttemptToken: string,
+ *   hasChangeableSubscription: bool,
+ *   planChangeToken: string,
+ *   planChangeExpectedPlanCode: string|null
  * }
  */
 final readonly class BillingPlansPageDto
@@ -43,6 +46,28 @@ public function __construct(
          * POST が 422 になる silent failure を作らないため。
          */
         public string $subscriptionAttemptToken,
+        /**
+         * 有効な subscription を持つか (= `startCheckout` が拒否し `changePlan` が受ける側)。
+         * 判定は `startCheckoutLocked` 段 1 と**同一の述語** (`Subscription::valid()`) を使う
+         * ため、UI がどちらの経路を選んでも「押したら循環エラー」にならない。
+         */
+        public bool $hasChangeableSubscription,
+        /**
+         * プラン変更 POST の冪等 token (画面 render ごとに固定される ULID)。
+         * `subscriptionAttemptToken` (契約 checkout) とは **別 key 空間**で混ぜない。
+         */
+        public string $planChangeToken,
+        /**
+         * 楽観的競合制御 (stale UI 検知) の期待値 = **`organizations.plan_code` そのもの**。
+         *
+         * 表示用の `currentPlanCode` とは**別物**なので混ぜない: 表示用は
+         * `BillingController::resolveCurrentPlanCode()` の projection で、ActiveFreePlan の
+         * org では `free_plan_code` を返す。`hasChangeableSubscription`
+         * (= `Subscription::valid()`) と ActiveFreePlan は同時に成立しうる
+         * (例: `canceled` かつ期末まで有効な grace period 契約) ため、表示値を競合制御に
+         * 使うと恒常 422 (stale) の詰みになる。
+         */
+        public ?string $planChangeExpectedPlanCode,
     ) {}
 
     /**
@@ -59,6 +84,9 @@ public function toArray(): array
             'billingState' => $this->billingState->value,
             'canManage' => $this->canManage,
             'subscriptionAttemptToken' => $this->subscriptionAttemptToken,
+            'hasChangeableSubscription' => $this->hasChangeableSubscription,
+            'planChangeToken' => $this->planChangeToken,
+            'planChangeExpectedPlanCode' => $this->planChangeExpectedPlanCode,
         ];
     }
 }
diff --git a/app/Enums/Billing/SubscriptionSwapOutcome.php b/app/Enums/Billing/SubscriptionSwapOutcome.php
new file mode 100644
index 0000000..c238f1c
--- /dev/null
+++ b/app/Enums/Billing/SubscriptionSwapOutcome.php
@@ -0,0 +1,21 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * subscription の base Price 差し替え (プラン変更) の結果。
+ *
+ * - Applied: Stripe に update を送って受理された (accepted)。
+ * - AlreadyOnTargetPrice: remote が既に対象 Price だったため update を送っていない
+ *   (webhook 反映待ち中の再操作 / idempotency key 期限切れ後の再操作で到達する)。
+ *
+ * どちらも「利用者から見た結末は同じ (対象プランで確定済み)」。呼び出し側は flash 文言の
+ * 出し分けにのみ使う。`organizations.plan_code` の追随 (projection_synced) は webhook が担う。
+ */
+enum SubscriptionSwapOutcome: string
+{
+    case Applied = 'applied';
+    case AlreadyOnTargetPrice = 'already_on_target_price';
+}
diff --git a/app/Exceptions/Billing/PlanChangeFailedException.php b/app/Exceptions/Billing/PlanChangeFailedException.php
new file mode 100644
index 0000000..b7d6c11
--- /dev/null
+++ b/app/Exceptions/Billing/PlanChangeFailedException.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Billing;
+
+use RuntimeException;
+use Throwable;
+
+/**
+ * プラン変更が Stripe 側の障害 / 想定外の subscription 構成で失敗した。
+ *
+ * **`getMessage()` は常に利用者向けの固定文言**にする (Controller が
+ * `back()->with('error', $e->getMessage())` に流すため、内部識別子を漏らさない)。
+ * 診断情報は `$reason` に持たせ、Service が log にだけ落とす。
+ *
+ * 変換対象は **想定された外部障害だけ** (実装バグは 500 のまま調査対象にする)。
+ * 生成は名前付きコンストラクタ経由に限定し、文言の再発明を防ぐ。
+ */
+final class PlanChangeFailedException extends RuntimeException
+{
+    public const USER_MESSAGE = 'プラン変更に失敗しました。時間をおいて再度お試しください。';
+
+    private function __construct(public readonly string $reason, ?Throwable $previous = null)
+    {
+        parent::__construct(self::USER_MESSAGE, previous: $previous);
+    }
+
+    /** Stripe API 障害 (ApiErrorException) の変換。SDK 例外は previous に格納する。 */
+    public static function stripeApiError(string $stripeSubscriptionId, Throwable $previous): self
+    {
+        return new self(
+            "stripe_api_error: subscription={$stripeSubscriptionId} / {$previous->getMessage()}",
+            $previous,
+        );
+    }
+
+    /**
+     * remote subscription の item 構成が AI-CUE の前提 (base 1 item・quantity=1) と違うとき。
+     * 席課金を持たない本アプリでは発生しない想定だが、**無言で潰さず fail-closed** にする。
+     */
+    public static function unexpectedShape(string $stripeSubscriptionId, int $itemCount, ?int $quantity): self
+    {
+        return new self(
+            "unexpected_shape: subscription={$stripeSubscriptionId} / items={$itemCount} / "
+            .'quantity='.($quantity ?? 'null'),
+        );
+    }
+}
diff --git a/app/Exceptions/Billing/PlanChangeNotAllowedException.php b/app/Exceptions/Billing/PlanChangeNotAllowedException.php
new file mode 100644
index 0000000..1f26f83
--- /dev/null
+++ b/app/Exceptions/Billing/PlanChangeNotAllowedException.php
@@ -0,0 +1,17 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Billing;
+
+use RuntimeException;
+
+/**
+ * **業務上の理由**でプラン変更を受け付けられないとき (契約が無い / 変更できない state /
+ * schedule 管理下)。メッセージは**そのまま利用者に見せる文言**として書く。
+ *
+ * 前提違反・実装不備 (`Assert` の `InvalidArgumentException` / `TypeError`) とは**別型**にする:
+ * Controller は本例外だけを error flash に変換し、`InvalidArgumentException` は catch せず
+ * 500 に落とす (Assert の内部文言を利用者へ露出させない / 実装不備を握り潰さない)。
+ */
+final class PlanChangeNotAllowedException extends RuntimeException {}
diff --git a/app/Exceptions/Billing/StalePlanChangeException.php b/app/Exceptions/Billing/StalePlanChangeException.php
new file mode 100644
index 0000000..d9d9f19
--- /dev/null
+++ b/app/Exceptions/Billing/StalePlanChangeException.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Billing;
+
+use RuntimeException;
+
+/**
+ * 画面 render 時点のプラン (`current_plan_code`) と実際の現在プランが食い違ったとき。
+ *
+ * **UX 用の stale 検知専用**であり認可判定ではない (認可は Gate、変更可否は subscription 状態)。
+ * Controller が 422 (`errors.plan_code`) に変換し、redirect-back で props も最新化される。
+ */
+final class StalePlanChangeException extends RuntimeException
+{
+    public function __construct(
+        public readonly ?string $expectedPlanCode,
+        public readonly ?string $actualPlanCode,
+        public readonly string $requestedPlanCode,
+    ) {
+        parent::__construct('プラン変更の前提が変わりました。');
+    }
+}
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index 6ea278e..39030a1 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -16,15 +16,20 @@
 use App\Enums\Billing\BillingFeedbackKind;
 use App\Enums\Billing\OnboardingBillingState;
 use App\Enums\Billing\SignupFundingChoice;
+use App\Enums\Billing\SubscriptionSwapOutcome;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
 use App\Exceptions\Billing\CheckoutInProgressException;
+use App\Exceptions\Billing\PlanChangeFailedException;
+use App\Exceptions\Billing\PlanChangeNotAllowedException;
 use App\Exceptions\Billing\StaleCheckoutAttemptException;
+use App\Exceptions\Billing\StalePlanChangeException;
 use App\Exceptions\Billing\StripePriceNotSyncedException;
 use App\Exceptions\Billing\SubscriptionAttemptPlanMismatchException;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Billing\BillingCheckoutRequest;
+use App\Http\Requests\Billing\ChangePlanRequest;
 use App\Http\Requests\Billing\StartAutoRechargeSetupRequest;
 use App\Http\Requests\Billing\UpdateAutoRechargeRequest;
 use App\Http\Requests\Billing\UpdateBillingContactRequest;
@@ -55,8 +60,9 @@
 /**
  * 課金画面 (current org スコープ)。
  *
- * - プラン変更は Stripe Checkout / Customer Portal 経由のみ (アプリは plan_code を
- *   直接書かない。organizations.plan_code は webhook で同期される)
+ * - 新規契約は Stripe Checkout、契約中プランの変更は in-app swap (`changePlan`)、
+ *   解約・支払い方法は Customer Portal。いずれもアプリは plan_code を直接書かない
+ *   (organizations.plan_code は webhook で同期される)
  * - 閲覧は組織メンバー全員、Checkout / Portal / オートリチャージ設定は
  *   manageBilling (owner / admin) のみ
  */
@@ -145,6 +151,8 @@ public function plans(Request $request, PricingService $pricing): Response
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
+        $subscription = $organization->subscription('default');
+
         $dto = new BillingPlansPageDto(
             plans: $pricing->listPublicPlans(),
             currentPlanCode: $this->resolveCurrentPlanCode($organization),
@@ -152,6 +160,11 @@ public function plans(Request $request, PricingService $pricing): Response
             canManage: $user->can('manageBilling', $organization),
             // P9: 契約 checkout の冪等 token (画面 render ごとに固定 = 1 render 1 token)。
             subscriptionAttemptToken: (string) Str::ulid(),
+            // F-3-01: startCheckout 段 1 の guard と同一述語 (valid()) で経路を分ける
+            hasChangeableSubscription: $subscription instanceof Subscription && $subscription->valid(),
+            planChangeToken: (string) Str::ulid(),
+            // 競合制御の期待値は projection ではなく列そのもの (currentPlanCode と別物)
+            planChangeExpectedPlanCode: $organization->plan_code,
         );
 
         return Inertia::render('Billing/Plans', ['page' => $dto->toArray()]);
@@ -276,6 +289,67 @@ public function checkout(
         return Inertia::location($result->url);
     }
 
+    /**
+     * F-3-01: 契約中プランの変更 (in-app swap)。`checkout()` と排他の経路。
+     *
+     * 実行順: (1) 認可 → (2) 契約の存在確認 (無ければ 422 で新規契約導線へ) →
+     * (3) plan 解決 → (4) Service へ委譲。**アプリは plan_code を直接書かない**
+     * (反映は webhook 同期)。
+     *
+     * ボタンを disabled にはしない (禁止事項 #8) ため、ここで返す error flash / 422 が
+     * 押下時のフィードバックになる。
+     */
+    public function changePlan(
+        ChangePlanRequest $request,
+        SubscriptionService $subscriptions,
+    ): RedirectResponse {
+        $organization = $this->resolveCurrentOrganization($request);
+        Gate::authorize('manageBilling', $organization);
+
+        $subscription = $organization->subscription('default');
+        if (! $subscription instanceof Subscription || ! $subscription->valid()) {
+            // 未契約 / 失効済みは 500 (service 内 guard) に到達させず 422 で新規契約導線へ倒す。
+            throw ValidationException::withMessages([
+                'plan_code' => '有効なご契約がないため変更できません。プランのお申し込みからお手続きください。',
+            ]);
+        }
+
+        $planCode = $request->validated('plan_code');
+        Assert::string($planCode);
+        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();
+
+        $token = $request->validated('plan_change_token');
+        Assert::string($token);
+        $expected = $request->validated('current_plan_code');
+        Assert::nullOrString($expected);
+
+        try {
+            $outcome = $subscriptions->changePlan($organization, $plan, $token, $expected);
+        } catch (StalePlanChangeException) {
+            // 競合検知。errors.plan_code は Plans.svelte の確認 modal 内 Alert に出て、
+            // redirect-back で props (currentPlanCode) も最新化される。
+            throw ValidationException::withMessages([
+                'plan_code' => 'プランが別の操作で変更されました。最新の内容をご確認のうえ、必要であれば改めて変更してください。',
+            ]);
+        } catch (StripePriceNotSyncedException) {
+            // production の sync 漏れ。500 にせず checkout と同一文言で差し戻す
+            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
+        } catch (PlanChangeNotAllowedException|PlanChangeFailedException|CheckoutInProgressException $e) {
+            // 業務拒否 (NotAllowed) / 外部障害 (Failed) / lock 競合のみを利用者向け文言に変換する。
+            // **`InvalidArgumentException` は catch しない** — Assert 由来の前提違反・設定不備は
+            // 500 に落として調査対象にする (内部文言を flash に載せない)。
+            return back()->with('error', $e->getMessage());
+        }
+
+        // accepted までを成功とし、projection (plan_code) の追随は webhook が担うことを文言で表す。
+        return redirect()->route('billing.index')->with(
+            'success',
+            $outcome === SubscriptionSwapOutcome::Applied
+                ? 'プラン変更を受け付けました。反映まで数分かかる場合があります。'
+                : 'このプランへの変更は受付済みです。反映まで数分かかる場合があります。',
+        );
+    }
+
     /**
      * P9: 請求先連絡先 (メール / 宛名) の更新。current-org スコープ
      * (route parameter を持たないため cross-org 指定が構造的に不能)。
diff --git a/app/Http/Requests/Billing/ChangePlanRequest.php b/app/Http/Requests/Billing/ChangePlanRequest.php
new file mode 100644
index 0000000..2f8e24b
--- /dev/null
+++ b/app/Http/Requests/Billing/ChangePlanRequest.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Billing;
+
+use App\Enums\PlanCode;
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
+
+/**
+ * 契約中プランの変更。Policy 検証 (manageBilling) は Controller 側 (Gate::authorize)。
+ *
+ * - `plan_code`: 変更先。「ユーザーがどのプランへ変えるか」の選択値であり状態キーではない
+ *   (`organizations.plan_code` への反映は webhook 同期のみ)。
+ * - `current_plan_code`: **stale UI 検知専用**の期待値で、認可・対象決定には使わない。
+ *   送信元は画面の `planChangeExpectedPlanCode` (= サーバの `organizations.plan_code` そのもの。
+ *   表示用の `currentPlanCode` ではない)。変更元には personal 等も入りうるため PlanCode 全集合で
+ *   domain 制約をかける。`organizations.plan_code` は null になりうるため
+ *   `present` + `nullable` (キー欠落は 422、値 null は許容)。
+ * - `plan_change_token`: 画面 render ごとの ULID。Stripe idempotency key
+ *   `change-plan:{token}:{plan_code}` の素で、同一 render からの二重送信を収束させる。
+ */
+class ChangePlanRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, list<mixed>>
+     */
+    public function rules(): array
+    {
+        return array_merge([
+            'plan_code' => ['required', 'string', 'exists:plans,code'],
+            // 送信元は画面の planChangeExpectedPlanCode (= organizations.plan_code そのもの)。
+            // 当該列は null になりうるため **キーの送信は必須 (present) / 値は null 可**
+            // = 送信漏れは 422 で検出しつつ、正当な null で恒常 422 を作らない。
+            'current_plan_code' => ['present', 'nullable', 'string', Rule::enum(PlanCode::class)],
+            // Str::ulid() は大文字 Crockford base32 を含むため 'ulid' ルールを使う
+            // (subscription_attempt_token と同じ作法)。
+            'plan_change_token' => ['required', 'ulid'],
+        ], $this->protectedKeyMissingRules());
+    }
+}
diff --git a/app/Services/Billing/CashierStripeGateway.php b/app/Services/Billing/CashierStripeGateway.php
index 871e2ec..cad1f93 100644
--- a/app/Services/Billing/CashierStripeGateway.php
+++ b/app/Services/Billing/CashierStripeGateway.php
@@ -6,18 +6,162 @@
 
 use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\Enums\Billing\SubscriptionSwapOutcome;
+use App\Exceptions\Billing\PlanChangeFailedException;
+use App\Models\Billing\Subscription;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use Carbon\CarbonImmutable;
 use Laravel\Cashier\Cashier;
+use Stripe\Exception\ApiErrorException;
+use Stripe\StripeClient;
+use Stripe\StripeObject;
+use Stripe\Subscription as StripeSubscription;
 use Webmozart\Assert\Assert;
 
 /**
  * StripeGatewayInterface の Cashier (Stripe SDK) 実装。
  * PortalConfigurationSpec は同一名前空間 (App\Services\Billing) のため use 不要。
+ *
+ * **final にしない**のは `stripe()` を test seam として持つため
+ * (`tests/Feature/Billing/SubscriptionSwapGatewayTest.php` が subclass で差し替える)。
+ * seam をリネームしたら同テストも同時に更新すること。
  */
-final class CashierStripeGateway implements StripeGatewayInterface
+class CashierStripeGateway implements StripeGatewayInterface
 {
+    /**
+     * Stripe クライアント取得の seam (テストで差し替えるためだけに切り出す)。
+     * 実装は Cashier の既定クライアントをそのまま返す。
+     */
+    protected function stripe(): StripeClient
+    {
+        return Cashier::stripe();
+    }
+
+    public function swapSubscriptionPrices(
+        Organization $organization,
+        string $basePriceId,
+        string $idempotencyKey,
+    ): SubscriptionSwapOutcome {
+        $subscription = $organization->subscription('default');
+        Assert::isInstanceOf($subscription, Subscription::class, '契約が見つかりません');
+        $stripeId = $subscription->stripe_id;
+        Assert::stringNotEmpty($stripeId, 'Stripe subscription id がありません');
+
+        $stripe = $this->stripe();
+
+        // SDK 例外を境界の外へ出さない (API 障害は PlanChangeFailedException へ変換する)。
+        try {
+            // item id の解決と remote 現在 Price の照合を **同じ 1 回の read** で行う。
+            $remote = $stripe->subscriptions->retrieve($stripeId, ['expand' => ['items.data']]);
+
+            // AI-CUE の subscription は **base 1 item・quantity=1 固定** (席課金なし)。
+            // 想定外の構成は触らずに fail-closed (多 item / 数量付き / 解決不能 item を
+            // 無言で潰さない)。normalizeItems は解決できない item があれば throw する
+            // (**skip しない** = 「正常 1 件 + 不正 1 件」を 1 件として通さない)。
+            $items = $this->normalizeItems($stripeId, $remote);
+
+            if (count($items) !== 1 || $items[0]['quantity'] !== 1) {
+                throw PlanChangeFailedException::unexpectedShape(
+                    $stripeId,
+                    count($items),
+                    $items[0]['quantity'] ?? null,
+                );
+            }
+
+            $item = $items[0];
+            if ($item['priceId'] === $basePriceId) {
+                return SubscriptionSwapOutcome::AlreadyOnTargetPrice; // update を送らない
+            }
+
+            $stripe->subscriptions->update(
+                $stripeId,
+                $this->buildSwapPayload($item['id'], $basePriceId),
+                ['idempotency_key' => $idempotencyKey],
+            );
+
+            return SubscriptionSwapOutcome::Applied;
+        } catch (ApiErrorException $e) {
+            // 想定された外部障害のみ変換する (実装バグは素通しして 500 = 調査対象)。
+            throw PlanChangeFailedException::stripeApiError($stripeId, $e);
+        }
+    }
+
+    /**
+     * subscription update payload (pure)。
+     *
+     * invariant (gateway 単体テストで固定):
+     * - **既存 item id を指定**して price を差し替える (id 無指定は item の二重化を招く)
+     * - `proration_behavior = create_prorations` — 日割り明細を作り、**次回請求に反映**する
+     *   (`always_invoice` にしない = 即時請求 → 与信失敗の状態遷移を呼び込まない)
+     * - `billing_cycle_anchor` / `trial_end` / `payment_behavior` は **送らない**
+     *   (即時請求・trial 再開の誘発を構造的に避ける)
+     *
+     * @return array{
+     *   items: array{array{id: string, price: string, quantity: int}},
+     *   proration_behavior: 'create_prorations'
+     * }
+     */
+    public function buildSwapPayload(string $itemId, string $basePriceId): array
+    {
+        Assert::stringNotEmpty($itemId);
+        Assert::stringNotEmpty($basePriceId);
+
+        return [
+            'items' => [
+                ['id' => $itemId, 'price' => $basePriceId, 'quantity' => 1],
+            ],
+            'proration_behavior' => 'create_prorations',
+        ];
+    }
+
+    /**
+     * remote subscription の items を {id, priceId, quantity} へ正規化する。
+     * price は string id / expanded object のどちらも取り得るため両対応する
+     * (`StripeWebhookProcessor::resolveStripeIdField` と同型の防御)。
+     *
+     * **解決できない item が 1 つでもあれば throw する** (skip しない)。skip すると
+     * 「正常 1 件 + 解決不能 1 件」が正規化後 1 件になり、多 item 契約を更新してしまうため。
+     * quantity 欠落も同様に想定外として扱う。
+     *
+     * @return list<array{id: string, priceId: string, quantity: int}>
+     */
+    private function normalizeItems(string $stripeSubscriptionId, StripeSubscription $remote): array
+    {
+        $normalized = [];
+        $rawCount = 0;
+        foreach ($remote->items->data as $item) {
+            $rawCount++;
+            // id / price は同じ helper で正規化する (空文字・未設定を null に落とす)。
+            $itemId = $this->resolveStripeIdField($item->id);
+            $priceId = $this->resolveStripeIdField($item->price);
+            $quantity = $item->quantity;
+            if ($itemId === null || $priceId === null || ! is_int($quantity)) {
+                // 解決不能 item は「無い」ものにせず、その場で fail-closed に倒す。
+                throw PlanChangeFailedException::unexpectedShape($stripeSubscriptionId, $rawCount, null);
+            }
+            $normalized[] = ['id' => $itemId, 'priceId' => $priceId, 'quantity' => $quantity];
+        }
+
+        return $normalized;
+    }
+
+    /** Stripe の id フィールド (string id または expanded object) から id を取り出す。 */
+    private function resolveStripeIdField(mixed $value): ?string
+    {
+        if (is_string($value)) {
+            return $value !== '' ? $value : null;
+        }
+        if ($value instanceof StripeObject) {
+            // `?? null` は __isset を先に通すため、未設定キーで SDK の logger を鳴らさない。
+            $id = $value->id ?? null;
+
+            return is_string($id) && $id !== '' ? $id : null;
+        }
+
+        return null;
+    }
+
     public function createSubscriptionCheckout(
         Organization $organization,
         string $stripePriceId,
diff --git a/app/Services/Billing/Contracts/StripeGatewayInterface.php b/app/Services/Billing/Contracts/StripeGatewayInterface.php
index 71f9bdf..d4b7ba1 100644
--- a/app/Services/Billing/Contracts/StripeGatewayInterface.php
+++ b/app/Services/Billing/Contracts/StripeGatewayInterface.php
@@ -6,6 +6,8 @@
 
 use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\Enums\Billing\SubscriptionSwapOutcome;
+use App\Exceptions\Billing\PlanChangeFailedException;
 use App\Models\Organization;
 
 /**
@@ -35,6 +37,32 @@ public function createSubscriptionCheckout(
         string $idempotencyKey,
     ): CreatedCheckoutSession;
 
+    /**
+     * 契約中 subscription の base Price を差し替える (プラン変更 = Stripe Subscription Update)。
+     *
+     * 実装は **remote の現在 Price と照合し、既に対象 Price なら update を送らない**
+     * (`AlreadyOnTargetPrice`)。ローカル列 (`subscriptions.stripe_price` /
+     * `organizations.plan_code`) は webhook 同期のためラグがあり判定に使えない。
+     *
+     * $idempotencyKey は Stripe へそのまま渡す (`change-plan:{token}:{planCode}`)。
+     *
+     * **Stripe SDK の object も例外も本 interface の外へ出さない**。API 障害
+     * (`\Stripe\Exception\ApiErrorException`) と想定外の subscription 構成は、実装側で
+     * `PlanChangeFailedException` (利用者向け文言 + 診断用 reason) に変換して throw する。
+     *
+     * ただし **前提違反 (呼び出し規約の破り) と実装バグは変換しない**:
+     * 契約行の不在 (`Assert::isInstanceOf` → `InvalidArgumentException`) や `TypeError` は
+     * fail-fast でそのまま外へ出す (呼び出し側 = Service が段 1 で契約の存在を保証済みのため、
+     * ここに到達するのは実装不備)。
+     *
+     * @throws PlanChangeFailedException Stripe API 障害 / 想定外の subscription 構成
+     */
+    public function swapSubscriptionPrices(
+        Organization $organization,
+        string $basePriceId,
+        string $idempotencyKey,
+    ): SubscriptionSwapOutcome;
+
     /**
      * Stripe 側 Checkout Session を expire する (別 plan の live pending 整理)。
      *
diff --git a/app/Services/Billing/Fakes/FakeStripeGateway.php b/app/Services/Billing/Fakes/FakeStripeGateway.php
index 7a12102..e522bbe 100644
--- a/app/Services/Billing/Fakes/FakeStripeGateway.php
+++ b/app/Services/Billing/Fakes/FakeStripeGateway.php
@@ -6,6 +6,7 @@
 
 use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\Enums\Billing\SubscriptionSwapOutcome;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use Carbon\CarbonImmutable;
@@ -37,6 +38,17 @@ public function createSubscriptionCheckout(
         );
     }
 
+    public function swapSubscriptionPrices(
+        Organization $organization,
+        string $basePriceId,
+        string $idempotencyKey,
+    ): SubscriptionSwapOutcome {
+        // 中立帰還: 実 Stripe を叩かず、subscription 状態も変えない
+        // (active subscription の正本は BughuntBillingSeeder。反映は webhook が担うが
+        //  fake 環境では webhook が発火しないため、画面は「反映待ち」までを観測する)。
+        return SubscriptionSwapOutcome::Applied;
+    }
+
     public function expireCheckoutSession(string $stripeSessionId): string
     {
         return 'expired';
diff --git a/app/Services/Billing/PortalConfigurationSpec.php b/app/Services/Billing/PortalConfigurationSpec.php
index 0a29bdc..b64a762 100644
--- a/app/Services/Billing/PortalConfigurationSpec.php
+++ b/app/Services/Billing/PortalConfigurationSpec.php
@@ -8,8 +8,9 @@
  * Customer Portal Configuration の許可機能ポリシー (コード上の固定真実源)。
  *
  * 核心: subscription_update を無効化し、Portal からの out-of-band プラン変更を構造的に封じる。
- * プラン変更はアプリ側 (Checkout / Subscription Schedule) が所有しており、Portal で直接変更
- * されると plan_code / schedule 整合が壊れるため。env はこの spec から生成された
+ * プラン変更はアプリ側 (新規契約 = Checkout / 契約中の変更 = `SubscriptionService::changePlan`)
+ * が所有しており、Portal で直接変更されると plan_code / schedule 整合が壊れるため。
+ * env はこの spec から生成された
  * configuration id を保持するのみで、ポリシー切替先ではない。
  *
  * 公式 API ref: POST /v1/billing_portal/configurations の features 集合に対応。
diff --git a/app/Services/Billing/SubscriptionService.php b/app/Services/Billing/SubscriptionService.php
index a9d41f7..abd9c2f 100644
--- a/app/Services/Billing/SubscriptionService.php
+++ b/app/Services/Billing/SubscriptionService.php
@@ -12,11 +12,15 @@
 use App\Enums\Billing\ScheduleSetupStatus;
 use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\Billing\SubscriptionState;
+use App\Enums\Billing\SubscriptionSwapOutcome;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
 use App\Enums\PlanCode;
 use App\Exceptions\Billing\CheckoutInProgressException;
+use App\Exceptions\Billing\PlanChangeFailedException;
+use App\Exceptions\Billing\PlanChangeNotAllowedException;
 use App\Exceptions\Billing\StaleCheckoutAttemptException;
+use App\Exceptions\Billing\StalePlanChangeException;
 use App\Exceptions\Billing\StripePriceNotSyncedException;
 use App\Exceptions\Billing\SubscriptionAttemptPlanMismatchException;
 use App\Models\Billing\BillingCheckoutSession;
@@ -556,6 +560,155 @@ private function isUniqueViolation(QueryException $e): bool
                 && str_contains($message, 'attempt_token'));
     }
 
+    /**
+     * 契約中プランの **即時 swap** (Stripe Subscription Update)。
+     *
+     * `startCheckout` (新規契約) と排他の経路。有効な subscription が**ある**組織はこちら、
+     * **ない**組織は `startCheckout` を通る (段 1 の guard が両者の境界)。
+     *
+     * 冪等は 2 層:
+     *  1. **同一 render の二重送信** → Stripe idempotency key `change-plan:{token}:{planCode}`
+     *     (`$planChangeToken` は画面 render ごとに 1 個。DB 行は作らない)
+     *  2. **別 render からの再操作 / key 期限切れ後の再操作** → gateway の remote Price 照合
+     *     (`AlreadyOnTargetPrice` = update を送らない)
+     *
+     * 本メソッドは **DB を書かない**。`organizations.plan_code` の追随は webhook
+     * (`applySubscriptionSnapshot`) が唯一の writer である契約を維持する。
+     *
+     * 段 0: 事前 assert (Price / プラン種別) / 段 1: 契約の再読込と存在 guard /
+     * 段 2: 変更可能 state 判定 / 段 3: schedule 管理下の拒否 /
+     * 段 4: stale UI 検知 (**要求先が local の現在プランと違うときだけ**) / 段 5: Stripe swap。
+     *
+     * **local の `organizations.plan_code` で「もう目標プランだから成功」と返さない**:
+     * この列は webhook 遅延を持つ projection であり、remote が別 Price のままの可能性がある
+     * (「受付済み」と嘘をつくことになる)。**同一プラン判定は gateway の remote 照合に一本化**し、
+     * `Applied` / `AlreadyOnTargetPrice` は remote の事実で決める。
+     *
+     * stale 検知は **要求先 ≠ local 現在プラン** のときだけ行う。要求先 = local 現在プランの
+     * ケース (反映待ち中の再操作 / 古い画面からの再送) を stale で誤拒否しないため。
+     * **state / schedule 判定はさらに前**に置く: grace period (解約予約中) の契約は
+     * `plan_code` が旧プランのまま残るため、後段で「変更できない契約なのに成功扱い」に
+     * ならないようにする。
+     *
+     * @param  string  $planChangeToken  画面 render ごとの ULID (idempotency key の素)
+     * @param  string|null  $expectedCurrentPlanCode  画面 render 時点の現在プラン (**UX 用の
+     *                                                stale 検知専用**。認可・対象決定には使わない。
+     *                                                未知 Price 等で null になりうるため nullable)
+     *
+     * @throws StalePlanChangeException 画面が古い (別操作でプランが変わっていた)
+     * @throws CheckoutInProgressException lock 競合
+     * @throws PlanChangeFailedException Stripe 由来の失敗 / 想定外の subscription 構成
+     *                                   (gateway が変換済み。本 Service は log して rethrow)
+     * @throws StripePriceNotSyncedException production runtime で未 sync の Price のとき
+     * @throws ValidationException Stripe 決済対象外のプランのとき (422)
+     * @throws PlanChangeNotAllowedException 契約が無い / 変更できない状態 / schedule 管理下のとき
+     *                                       (**業務上の拒否**。Controller が error flash に変換する。
+     *                                       前提違反の `InvalidArgumentException` とは区別する)
+     */
+    public function changePlan(
+        Organization $org,
+        Plan $plan,
+        string $planChangeToken,
+        ?string $expectedCurrentPlanCode,
+    ): SubscriptionSwapOutcome {
+        // 段 0: lock を取る前に確定できる guard は先に倒す。
+        // **順序が重要**: 決済対象外プラン (personal / enterprise) は先に 422 へ倒す。
+        // 後段の Assert は「Stripe 決済対象プランなのに base Price が無い」= 設定不備であり、
+        // 変換せず 500 に落として調査対象にする (利用者操作では到達しない)。
+        $this->assertStripeBillablePlan($plan);
+
+        $basePrice = $plan->currentPrice(PlanPriceKind::Base);
+        Assert::isInstanceOf($basePrice, PlanPrice::class, '基本 Price 未設定のプランです');
+        $this->assertPriceSynced($basePrice);
+        // token は FormRequest が 'ulid' で検証済み。空到達は実装不備 = fail-fast。
+        Assert::stringNotEmpty($planChangeToken, 'プラン変更トークンが不正です');
+
+        try {
+            $outcome = Cache::lock("billing:plan-change:{$org->id}", 10)->block(
+                5,
+                fn (): SubscriptionSwapOutcome => $this->changePlanLocked(
+                    $org, $plan, $basePrice, $planChangeToken, $expectedCurrentPlanCode,
+                ),
+            );
+            // Cache::lock()->block() は mixed を返すため型を絞る (startCheckout と同型)
+            Assert::isInstanceOf($outcome, SubscriptionSwapOutcome::class);
+
+            return $outcome;
+        } catch (LockTimeoutException $e) {
+            // fail-closed: ロックなし実行へフォールバックしない (二重 swap を作らない)
+            throw new CheckoutInProgressException('直前の操作が進行中です。数秒お待ちください。', previous: $e);
+        }
+    }
+
+    private function changePlanLocked(
+        Organization $org,
+        Plan $plan,
+        PlanPrice $basePrice,
+        string $planChangeToken,
+        ?string $expectedCurrentPlanCode,
+    ): SubscriptionSwapOutcome {
+        // 段 1: lock 内で DB から読み直す (Cashier の subscription() は relation cache を
+        // 返しうるため refresh する。org 側も plan_code の最新を読む)
+        $org->refresh();
+        $sub = $org->subscription('default');
+        if (! $sub instanceof Subscription || ! $sub->valid()) {
+            throw new PlanChangeNotAllowedException('変更できる契約がありません。プランのお申し込みからお手続きください。');
+        }
+        $sub->refresh();
+
+        // 段 2: 変更可能 state (Active のみ)。past_due / paused / inactive (解約予約中の
+        // grace period 契約を含む) は Stripe 側 mutation がエラーになり得るため、
+        // **他のどの判定よりも先に**理由付きでクリーンに拒否する (押下時エラー = 禁止事項 #8)。
+        $state = SubscriptionState::fromSubscription($sub);
+        if ($state !== SubscriptionState::Active) {
+            throw new PlanChangeNotAllowedException(match ($state) {
+                SubscriptionState::PastDue => 'お支払いが確認できていないため、プランを変更できません。お支払い方法をご確認ください。',
+                SubscriptionState::Paused => 'ご契約が一時停止中のため、プランを変更できません。お支払い方法を登録してください。',
+                SubscriptionState::UpgradeRecovery => 'ご契約の同期処理中です。数分お待ちのうえ再度お試しください。',
+                default => 'ご契約が有効でないため、プランを変更できません。',
+            });
+        }
+
+        // 段 3: schedule 管理下は拒否。AI-CUE は schedule を作らないが、
+        // billing:reconcile-schedules が remote から復元しうる (手動 Dashboard 操作等)。
+        // schedule 管理下の直接 swap は Stripe 側と衝突するため触らない。
+        if ($sub->stripe_schedule_id !== null) {
+            throw new PlanChangeNotAllowedException('予約済みのプラン変更があります。反映後に再度お試しください。');
+        }
+
+        // 段 4: stale UI 検知。**要求先が local の現在プランと違うとき**だけ評価する
+        // (要求先 = local 現在プランなら「反映待ち中の再操作」= 古い画面でも拒否しない)。
+        // null 同士の一致も許容する (未知 Price 等で plan_code が null の画面)。
+        if ($org->plan_code !== $plan->code && $org->plan_code !== $expectedCurrentPlanCode) {
+            throw new StalePlanChangeException(
+                expectedPlanCode: $expectedCurrentPlanCode,
+                actualPlanCode: $org->plan_code,
+                requestedPlanCode: $plan->code,
+            );
+        }
+
+        // 段 5: Stripe へ swap。**同一プラン判定は remote の事実で行う** (local projection では
+        // 判定しない)。gateway が既存 item id 解決と同じ 1 回の read で照合し、
+        // 既に対象 Price なら update を送らず AlreadyOnTargetPrice を返す。
+        // gateway は Stripe SDK の例外を外へ出さない契約なので、ここで扱うのは
+        // PlanChangeFailedException だけ (診断は reason を log に落として rethrow)。
+        try {
+            return $this->gateway->swapSubscriptionPrices(
+                $org,
+                $basePrice->stripe_price_id,
+                "change-plan:{$planChangeToken}:{$plan->code}",
+            );
+        } catch (PlanChangeFailedException $e) {
+            Log::error('changePlan: swap failed', [
+                'organization_id' => $org->getKey(),
+                'plan_code' => $plan->code,
+                'reason' => $e->reason, // 診断用 (利用者向け文言は getMessage())
+            ]);
+
+            throw $e;
+        }
+    }
+
     /**
      * 契約開始前の事前検証: 請求先メールが解決できること
      * (billing_contact_email 正本 → owner email fallback)。
diff --git a/docs/architecture.md b/docs/architecture.md
index 2a35256..44139cd 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -137,7 +137,7 @@ ## 主要 Service (テンプレート同梱)
 | `Billing/BillingNotificationDispatcher` | 請求通知の冪等 dispatch 窓口 (通知台帳へ insertOrIgnore → 新規行のみ queue。**請求系通知の送信は本クラス経由のみ**) |
 | `Billing/StripeScheduleGateway` | Subscription Schedule API の集約 gateway (create/update/release/retrieve。テストは mock 差替) |
 | `Billing/StripePriceCatalogClient` | Stripe Price Catalog への read-only adapter (`prices.list` の lookup_keys で現行 active Price を解決。価格カタログ as-code の sync/verify コマンドが利用) |
-| `Billing/PortalConfigurationSpec` | Customer Portal の許可機能ポリシー固定真実源 (subscription_update 無効化。`billing:ensure-portal-configuration` が生成/検証) |
+| `Billing/PortalConfigurationSpec` | Customer Portal の許可機能ポリシー固定真実源 (subscription_update 無効化。`billing:ensure-portal-configuration` が生成/検証)。プラン変更はアプリが所有する (`SubscriptionService::changePlan`) |
 | `Billing/TicketLedgerService` | チケットの reserve/commit/release と冪等付与 (grantMonthly/grantSignupGrant/grantPurchased)・返金逆仕訳 (clawback) |
 | `Billing/TicketCheckoutService` | チケットスポット購入の冪等 Checkout 開始 (org 単位 Cache::lock 直列化 + attempt_token 冪等 + live pending dedup + INSERT unique 違反の re-read 収束。二重課金防止の冪等マシン) |
 | `Billing/TicketCheckoutGateway` (interface) + `Billing/CashierTicketCheckoutGateway` | Stripe one-time Checkout の抽象 (mode=payment / card のみ / promo・tax なし = amount_subtotal 照合の前提。idempotency key 対応。テストは fake を bind) |
@@ -256,6 +256,7 @@ ## サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運
   | GET `/billing-required` (`onboarding.billing-required`) | `Onboarding/BillingRequired` — 未契約 かつ `manageBilling` なし member への説明 (Owner 連絡先 + 問い合わせ導線) | `view` 認可 + 離脱ガード (利用可 → `dashboard` / `manageBilling` 保持 → `onboarding.checkout`) |
   | POST `/onboarding/activate-personal` (`onboarding.activate-personal`) | Personal(無料)の即時有効化 (Stripe Checkout を通らない) | `manageBilling` + `throttle:10,1` |
   | POST `/billing/checkout` (`billing.checkout`) | 有償プランの Stripe Checkout 開始 | `manageBilling` |
+  | POST `/billing/plan` (`billing.plan.change`) | 契約中プランの in-app swap (プラン変更) | `manageBilling` |
   | PATCH `/billing/contact` (`billing.contact.update`) | 請求先連絡先の更新 | `manageBilling` |
 
   いずれも `require-active-subscription` group の**外**にある構造的 allowlist
@@ -284,6 +285,32 @@ ## サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運
   - live/stale の閾値は `BillingCheckoutSession::staleThresholdAt()` が単一出典で、
     `BillingAccess::state()` / 段 2・3・4 / 日次 sweeper が共有する
     (Architecture テストが literal の再発明を検出)
+- **契約中プランの変更 (in-app swap / F-3-01)**: `POST /billing/plan` (`billing.plan.change`) →
+  `SubscriptionService::changePlan()`。**有効な subscription を持つ組織専用**の経路で、
+  持たない組織の `billing.checkout` と `Subscription::valid()` を境に排他
+  (どちらの CTA も `/billing/plans` から出るが、送信先はサーバが決めた
+  `hasChangeableSubscription` で分かれる)。
+  - guard 順: 契約再読込 → **変更可能 state (Active のみ)** → schedule 管理下の拒否 →
+    stale UI 検知 (`current_plan_code`。UX 専用。**要求先 ≠ local 現在プランのときだけ**評価) →
+    Stripe swap。
+    **`organizations.plan_code` が既に目標プランでも「受付済み」で早期 return しない** —
+    この列は webhook 遅延を持つ projection なので、同一プラン判定は
+    **gateway の remote 照合に一本化**する (`Applied` / `AlreadyOnTargetPrice` は remote の事実)。
+    **state / schedule 判定は最前段**に置く — grace period (解約予約中) の契約は
+    `plan_code` が旧プランのまま残るため、後段で「変更できない契約なのに成功扱い」に
+    ならないようにする
+  - stale 検知の期待値は **`organizations.plan_code` そのもの**
+    (`planChangeExpectedPlanCode` prop)。表示用の `currentPlanCode`
+    (ActiveFreePlan では `free_plan_code` を返す projection) とは別物で、混ぜると
+    grace period 契約で恒常 422 になる
+  - Stripe への更新は `proration_behavior=create_prorations` (日割りは**次回請求に反映**。
+    `always_invoice` は使わない = 即時請求の与信失敗遷移を持ち込まない)
+  - 冪等は 2 層: 同一 render の二重送信は idempotency key `change-plan:{token}:{planCode}`、
+    別 render からの再操作は **gateway の remote Price 照合** (`AlreadyOnTargetPrice` =
+    update を送らない)
+  - **`organizations.plan_code` は書かない**。反映 (projection_synced) は
+    `customer.subscription.updated` → `applySubscriptionSnapshot` が唯一の writer
+  - Customer Portal の `subscription_update` は **無効のまま** (プラン変更はアプリが所有する)
 - **着地 feedback (P9)**: `Inertia::location()` の full page redirect を跨いだ後、
   `/billing` 着地で one-shot バナーを出す (`BillingFeedbackKind`: purchase_received /
   purchase_processing / purchase_already_received / checkout_retry_required / portal_returned)。
diff --git a/lang/ja/validation.php b/lang/ja/validation.php
index 9c9528b..68471a9 100644
--- a/lang/ja/validation.php
+++ b/lang/ja/validation.php
@@ -213,6 +213,8 @@
         'count' => '購入枚数',
         'attempt_token' => '操作トークン',
         'subscription_attempt_token' => '契約手続きトークン',
+        'current_plan_code' => '現在のプラン',
+        'plan_change_token' => 'プラン変更トークン',
         'billing_contact_email' => '請求先メールアドレス',
         'billing_contact_name' => '請求先の宛名',
         // オートリチャージ (P8a)。'enabled' は 2 段階認証と同名キーのため
diff --git a/resources/js/pages/Billing/Plans.svelte b/resources/js/pages/Billing/Plans.svelte
index c96921b..5ad24dd 100644
--- a/resources/js/pages/Billing/Plans.svelte
+++ b/resources/js/pages/Billing/Plans.svelte
@@ -14,9 +14,13 @@
 
     /**
      * プラン比較 (/billing/plans)。閲覧は組織メンバー全員、変更は manageBilling のみ。
-     * 変更は既存の Stripe Checkout (POST /billing/checkout) へ委譲する。body は plan_code +
-     * subscription_attempt_token (冪等 token。funding_choice は載せない = 契約変更経路に
-     * 資金選択の提示は無い)。
+     *
+     * 送信先はサーバが決めた `hasChangeableSubscription` (= `Subscription::valid()`) で分岐する:
+     * - 有効な契約あり → POST /billing/plan (in-app swap)。body は plan_code +
+     *   current_plan_code (stale 検知の期待値) + plan_change_token (冪等 token)
+     * - 契約なし → 従来の POST /billing/checkout。body は plan_code +
+     *   subscription_attempt_token (funding_choice は載せない)
+     * 判定述語がサーバと同一なので「押したら循環エラー」にならない。
      *
      * 変更できないプランでも CTA は enabled のまま描画し、理由は caption + 押下時 Alert で
      * 伝える (DESIGN.md / 禁止事項 #8)。
@@ -30,10 +34,11 @@
     const shared = $derived(inertiaPage.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
-    // サーバ validation エラー (旧タブからの送信・未同期プラン等) は dialog 内に出す。
+    // サーバ validation エラー (旧タブからの送信・未同期プラン等) は dialog 内に出す
+    // (3 キーのいずれか = 最初に見つかったもの)。
     const planCodeError = $derived.by<string | null>(() => {
         const errors = inertiaPage.props.errors as Record<string, string> | undefined;
-        return errors?.plan_code ?? null;
+        return errors?.plan_code ?? errors?.current_plan_code ?? errors?.plan_change_token ?? null;
     });
 
     const formatLimit = (value: number | null): string => (value === null ? "無制限" : String(value));
@@ -65,6 +70,33 @@
     const planNameOf = (code: string): string =>
         page.plans.find((plan) => plan.code === code)?.name ?? code;
 
+    const targetPlan = $derived(page.plans.find((plan) => plan.code === confirmingPlanCode) ?? null);
+    const currentPlanAmount = $derived(
+        page.plans.find((plan) => plan.code === page.currentPlanCode)?.baseAmountJpy ?? null,
+    );
+    // 金額比較は**文言の出し分けにのみ**使う (可否判定はサーバ)。
+    const isDowngrade = $derived(
+        page.hasChangeableSubscription &&
+            targetPlan !== null &&
+            currentPlanAmount !== null &&
+            (targetPlan.baseAmountJpy ?? 0) < currentPlanAmount,
+    );
+
+    const confirmMessage = $derived.by<string>(() => {
+        const name = targetPlan?.name ?? planNameOf(confirmingPlanCode ?? "");
+        if (!page.hasChangeableSubscription) {
+            return `プランを「${name}」に変更します。よろしいですか？お支払い手続きの画面 (Stripe) に移動します。`;
+        }
+        const base =
+            `プランを「${name}」に変更します。変更は Stripe 側に即時反映され` +
+            `(画面表示への反映は数分かかる場合があります)、差額は日割りで次回のご請求に調整されます。`;
+        return isDowngrade
+            ? base +
+                  "新しいプランの上限 (プロジェクト数・メンバー数・保存容量) を超えている場合、" +
+                  "既存のデータは削除されませんが、上限内に収まるまで新規作成とアップロードができません。"
+            : base;
+    });
+
     function openConfirm(planCode: string): void {
         confirmingPlanCode = planCode;
         confirmOpen = true;
@@ -77,9 +109,22 @@
     function submitPlanChange(): void {
         const planCode = confirmingPlanCode;
         if (planCode === null || submitting) return;
+
+        // 有効な契約がある組織は in-app swap、無い組織は従来の Checkout。
+        // 判定述語はサーバ (Subscription::valid()) と同一なので循環エラーにならない。
+        const url = page.hasChangeableSubscription ? "/billing/plan" : "/billing/checkout";
+        const payload = page.hasChangeableSubscription
+            ? {
+                  plan_code: planCode,
+                  // 表示用 currentPlanCode ではなく競合制御用の期待値を送る
+                  current_plan_code: page.planChangeExpectedPlanCode,
+                  plan_change_token: page.planChangeToken,
+              }
+            : { plan_code: planCode, subscription_attempt_token: page.subscriptionAttemptToken };
+
         router.post(
-            "/billing/checkout",
-            { plan_code: planCode, subscription_attempt_token: page.subscriptionAttemptToken },
+            url,
+            payload,
             {
                 onStart: () => {
                     submitting = true;
@@ -125,7 +170,7 @@
 <ConfirmDialog
     bind:open={confirmOpen}
     title="プラン変更の確認"
-    message={`プランを「${planNameOf(confirmingPlanCode ?? "")}」に変更します。よろしいですか？お支払い手続きの画面 (Stripe) に移動します。`}
+    message={confirmMessage}
     confirmLabel="変更する"
     processing={submitting}
     onConfirm={submitPlanChange}
diff --git a/resources/js/types/billing.ts b/resources/js/types/billing.ts
index ec03b87..8a00373 100644
--- a/resources/js/types/billing.ts
+++ b/resources/js/types/billing.ts
@@ -95,6 +95,15 @@ export interface BillingPlansPageProps {
     readonly canManage: boolean;
     /** 契約 checkout の冪等 token (チケット購入 / カード登録とは別 key 空間) */
     readonly subscriptionAttemptToken: string;
+    /** 有効な契約があるか (true = プラン変更経路 / false = 新規契約 checkout 経路) */
+    readonly hasChangeableSubscription: boolean;
+    /** プラン変更 POST の冪等 token (契約 checkout とは別 key 空間) */
+    readonly planChangeToken: string;
+    /**
+     * stale UI 検知の期待値 (= サーバの organizations.plan_code)。
+     * 表示用の currentPlanCode とは別物なので、この値をそのまま送る。
+     */
+    readonly planChangeExpectedPlanCode: string | null;
 }
 
 /** PHP: BillingDashboardDto (BillingDashboardShape) と対 */
diff --git a/routes/web.php b/routes/web.php
index fdfb259..d312f4a 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -312,7 +312,8 @@
         ->name('organizations.onboarding.cli');
 
     /*
-    | 課金 (current org スコープ)。プラン変更は Stripe Checkout / Customer Portal 経由のみ。
+    | 課金 (current org スコープ)。新規契約は Stripe Checkout、契約中プランの変更は
+    | in-app swap (billing.plan.change)、解約・支払い方法は Customer Portal。
     | Stripe webhook ルート (POST /stripe/webhook) は Cashier が自動登録する
     | (CSRF 除外は bootstrap/app.php の validateCsrfTokens except 'stripe/*')。
     | billing / webhook / 組織管理系は課金ゲート (require-active-subscription) の
@@ -326,6 +327,12 @@
         ->name('billing.plans');
     Route::post('/billing/checkout', [BillingController::class, 'checkout'])
         ->name('billing.checkout');
+    // F-3-01: 契約中プランの変更 (in-app swap)。有効な subscription を**持つ**組織の経路で、
+    // 持たない組織の billing.checkout と排他。Portal の subscription_update は無効のまま
+    // (プラン変更はアプリが所有する = PortalConfigurationSpec の宣言どおり)。
+    // 課金ゲート allowlist に置く理由: billing.* と同じく「支払い状態の是正」に到達させるため。
+    Route::post('/billing/plan', [BillingController::class, 'changePlan'])
+        ->name('billing.plan.change');
     Route::post('/billing/portal', [BillingController::class, 'portal'])
         ->name('billing.portal');
     // P9: 請求先連絡先 (メール / 宛名)。current org スコープ (route parameter なし)。
diff --git a/tests/Feature/Billing/BillingPlansPageTest.php b/tests/Feature/Billing/BillingPlansPageTest.php
index 394bb87..f1b80aa 100644
--- a/tests/Feature/Billing/BillingPlansPageTest.php
+++ b/tests/Feature/Billing/BillingPlansPageTest.php
@@ -105,6 +105,52 @@
     expect($response->headers->get('Location'))->toContain('fake_external=stripe');
 });
 
+test('有償契約中の org では hasChangeableSubscription=true と競合制御用 props が載る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    contractPaidPlan($organization);
+
+    $this->actingAs($owner)->get('/billing/plans')
+        ->assertOk()
+        ->assertInertia(function (AssertableInertia $page) use ($organization): void {
+            $props = $page->toArray()['props']['page'];
+            expect($props['hasChangeableSubscription'])->toBeTrue();
+            expect($props['planChangeToken'])->toBeString()->not->toBe('');
+            expect($props['planChangeExpectedPlanCode'])->toBe($organization->fresh()?->plan_code);
+        });
+});
+
+test('grace period 契約では表示用 currentPlanCode と競合制御用 planChangeExpectedPlanCode が分かれる', function (): void {
+    // free_plan_code='personal' (ActiveFreePlan) かつ plan_code='standard' の解約予約中契約。
+    // 表示用 (projection) を競合制御に使うと恒常 422 (stale) の詰みになるため別物であることを固定する。
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+    $subscription = createFakeSubscription($organization, status: 'canceled');
+    $subscription->forceFill(['ends_at' => now()->addDays(10)])->save();
+
+    $this->actingAs($owner)->get('/billing/plans')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.currentPlanCode', 'personal')
+            ->where('page.planChangeExpectedPlanCode', 'standard')
+            ->where('page.hasChangeableSubscription', true));
+});
+
+test('未契約 org / 期間終了済み契約の org は hasChangeableSubscription=false', function (): void {
+    // 述語は startCheckout 段 1 と同一の Subscription::valid()。Cashier の valid() は
+    // 「ends_at が過去」= ended() のときだけ false になる (canceled + ends_at=null は active 扱い)。
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $this->actingAs($owner)->get('/billing/plans')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.hasChangeableSubscription', false));
+
+    [$organization2, $owner2] = createOrganizationWithOwner();
+    $ended = createFakeSubscription($organization2, status: 'canceled');
+    $ended->forceFill(['ends_at' => now()->subDay()])->save();
+    $this->actingAs($owner2)->get('/billing/plans')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.hasChangeableSubscription', false));
+});
+
 test('Billing/Plans の props に render 単位の subscriptionAttemptToken が載る', function (): void {
     [, $owner] = createOrganizationWithOwner();
 
diff --git a/tests/Feature/Billing/PlanChangeEndpointTest.php b/tests/Feature/Billing/PlanChangeEndpointTest.php
new file mode 100644
index 0000000..80706e4
--- /dev/null
+++ b/tests/Feature/Billing/PlanChangeEndpointTest.php
@@ -0,0 +1,223 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\SubscriptionSwapOutcome;
+use App\Exceptions\Billing\PlanChangeFailedException;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Illuminate\Support\Str;
+
+/*
+ * F-3-01: POST /billing/plan (billing.plan.change)。
+ *
+ * 有効な subscription を**持つ**組織専用の経路で、持たない組織の billing.checkout と排他。
+ * 認可は manageBilling。応答は redirect + flash (禁止事項 #4 / #7)。
+ * 例外の変換境界: 業務拒否 / 外部障害 / lock 競合 は flash、**前提違反 (Assert) は 500**。
+ */
+
+/**
+ * @return array{Organization, User}
+ */
+function planChangeEndpointOrganization(string $planCode = 'starter', string $status = 'active'): array
+{
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    createFakeSubscription($organization, status: $status);
+    $organization->forceFill(['plan_code' => $planCode])->save();
+    $organization->refresh();
+
+    return [$organization, $owner];
+}
+
+/**
+ * @return array<string, string|null>
+ */
+function planChangePayload(string $planCode = 'standard', ?string $currentPlanCode = 'starter'): array
+{
+    return [
+        'plan_code' => $planCode,
+        'current_plan_code' => $currentPlanCode,
+        'plan_change_token' => (string) Str::ulid(),
+    ];
+}
+
+test('契約中 owner のプラン変更は /billing へ redirect し受付 flash を出す', function (): void {
+    [, $owner] = planChangeEndpointOrganization();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);
+
+    $this->actingAs($owner)->post('/billing/plan', planChangePayload())
+        ->assertRedirect('/billing')
+        ->assertSessionHas('success', 'プラン変更を受け付けました。反映まで数分かかる場合があります。');
+});
+
+test('AlreadyOnTargetPrice のときは受付済み文言になる', function (): void {
+    [, $owner] = planChangeEndpointOrganization();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()
+        ->andReturn(SubscriptionSwapOutcome::AlreadyOnTargetPrice);
+
+    $this->actingAs($owner)->post('/billing/plan', planChangePayload())
+        ->assertRedirect('/billing')
+        ->assertSessionHas('success', 'このプランへの変更は受付済みです。反映まで数分かかる場合があります。');
+});
+
+test('manageBilling を持たない member は 403', function (): void {
+    [$organization] = planChangeEndpointOrganization();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    $this->actingAs($member)->post('/billing/plan', planChangePayload())->assertForbidden();
+});
+
+test('契約の無い組織は 422 で新規契約導線へ倒す', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    $this->actingAs($owner)->post('/billing/plan', planChangePayload(currentPlanCode: null))
+        ->assertSessionHasErrors(['plan_code']);
+});
+
+test('期間終了済み契約は valid() が false のため 422', function (): void {
+    // Cashier の valid() は「ends_at が過去」= ended() のときだけ false になる
+    // (canceled + ends_at=null は active 扱い。実コードの意味論をそのまま固定する)。
+    [$organization, $owner] = planChangeEndpointOrganization(status: 'canceled');
+    $organization->subscription('default')?->forceFill(['ends_at' => now()->subDay()])->save();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    $this->actingAs($owner)->post('/billing/plan', planChangePayload())
+        ->assertSessionHasErrors(['plan_code']);
+});
+
+test('current_plan_code が実際と食い違うと stale として errors.plan_code を返す', function (): void {
+    [, $owner] = planChangeEndpointOrganization();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    $this->actingAs($owner)->post('/billing/plan', planChangePayload(currentPlanCode: 'personal'))
+        ->assertSessionHasErrors(['plan_code']);
+});
+
+test('plan_change_token の欠落・非 ULID は 422', function (): void {
+    [, $owner] = planChangeEndpointOrganization();
+    $this->mock(StripeGatewayInterface::class)->shouldNotReceive('swapSubscriptionPrices');
+
+    $this->actingAs($owner)->post('/billing/plan', [
+        'plan_code' => 'standard',
+        'current_plan_code' => 'starter',
+    ])->assertSessionHasErrors(['plan_change_token']);
+
+    $this->actingAs($owner)->post('/billing/plan', [
+        'plan_code' => 'standard',
+        'current_plan_code' => 'starter',
+        'plan_change_token' => 'not-a-ulid',
+    ])->assertSessionHasErrors(['plan_change_token']);
+});
+
+test('未知の plan_code は 422', function (): void {
+    [, $owner] = planChangeEndpointOrganization();
+    $this->mock(StripeGatewayInterface::class)->shouldNotReceive('swapSubscriptionPrices');
+
+    $this->actingAs($owner)->post('/billing/plan', planChangePayload(planCode: 'unknown-plan'))
+        ->assertSessionHasErrors(['plan_code']);
+});
+
+test('current_plan_code はキー欠落なら 422 だが値 null は通る', function (): void {
+    [$organization, $owner] = planChangeEndpointOrganization();
+
+    $this->mock(StripeGatewayInterface::class)->shouldNotReceive('swapSubscriptionPrices');
+    $this->actingAs($owner)->post('/billing/plan', [
+        'plan_code' => 'standard',
+        'plan_change_token' => (string) Str::ulid(),
+    ])->assertSessionHasErrors(['current_plan_code']);
+
+    // plan_code=null の組織 + current_plan_code=null は正当な組み合わせ (恒常 422 を作らない)
+    $organization->forceFill(['plan_code' => null])->save();
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);
+
+    $this->actingAs($owner)->post('/billing/plan', [
+        'plan_code' => 'standard',
+        'current_plan_code' => null,
+        'plan_change_token' => (string) Str::ulid(),
+    ])->assertRedirect('/billing');
+});
+
+test('業務拒否 (paused 契約) は back + error flash でその文言を返す', function (): void {
+    [, $owner] = planChangeEndpointOrganization(status: 'paused');
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    $response = $this->actingAs($owner)->post('/billing/plan', planChangePayload());
+
+    $response->assertRedirect();
+    expect(session('error'))->toBeString()->toContain('一時停止');
+});
+
+test('外部障害の flash は固定文言で内部 reason を漏らさない', function (): void {
+    [, $owner] = planChangeEndpointOrganization();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()
+        ->andThrow(PlanChangeFailedException::unexpectedShape('sub_secret_1', 2, null));
+
+    $this->actingAs($owner)->post('/billing/plan', planChangePayload())->assertRedirect();
+
+    expect(session('error'))->toBe(PlanChangeFailedException::USER_MESSAGE);
+    expect(session('error'))->not->toContain('sub_secret_1');
+});
+
+test('前提違反 (InvalidArgumentException) は catch されず 500 になる', function (): void {
+    [, $owner] = planChangeEndpointOrganization();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()
+        ->andThrow(new InvalidArgumentException('内部 Assert 文言'));
+
+    $response = $this->actingAs($owner)->post('/billing/plan', planChangePayload());
+
+    $response->assertStatus(500);
+    expect(session('error'))->toBeNull();
+});
+
+test('保護キーを payload に混ぜると 422', function (): void {
+    [, $owner] = planChangeEndpointOrganization();
+    $this->mock(StripeGatewayInterface::class)->shouldNotReceive('swapSubscriptionPrices');
+
+    $this->actingAs($owner)->post('/billing/plan', array_merge(planChangePayload(), [
+        'organization_id' => 999,
+    ]))->assertSessionHasErrors(['organization_id']);
+});
+
+test('route parameter を持たないため current org の契約しか変更されない', function (): void {
+    [$current, $owner] = planChangeEndpointOrganization();
+    // owner が別組織にも所属していても、current org 以外は指定する手段が無い
+    [$other] = planChangeEndpointOrganization();
+    $other->users()->attach($owner);
+
+    $seen = null;
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')
+        ->once()
+        ->andReturnUsing(function (Organization $org) use (&$seen): SubscriptionSwapOutcome {
+            $seen = $org->getKey();
+
+            return SubscriptionSwapOutcome::Applied;
+        });
+
+    $this->actingAs($owner)->post('/billing/plan', planChangePayload())->assertRedirect('/billing');
+
+    expect($seen)->toBe($current->getKey());
+});
diff --git a/tests/Feature/Billing/SubscriptionPlanChangeTest.php b/tests/Feature/Billing/SubscriptionPlanChangeTest.php
new file mode 100644
index 0000000..af24c94
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionPlanChangeTest.php
@@ -0,0 +1,301 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Enums\Billing\ScheduleSetupStatus;
+use App\Enums\Billing\SubscriptionSwapOutcome;
+use App\Exceptions\Billing\PlanChangeFailedException;
+use App\Exceptions\Billing\PlanChangeNotAllowedException;
+use App\Exceptions\Billing\StalePlanChangeException;
+use App\Models\Billing\Plan;
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\SubscriptionService;
+use App\Services\Billing\SubscriptionSnapshot;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Str;
+use Illuminate\Validation\ValidationException;
+use Webmozart\Assert\Assert;
+
+/*
+ * F-3-01 層 2/3: SubscriptionService::changePlan()。gateway は mock 差し替え。
+ *
+ * 段 1 契約再読込 → 段 2 state 判定 → 段 3 schedule 拒否 → 段 4 stale 検知 → 段 5 swap。
+ *
+ * - `organizations.plan_code` は **書かない** (webhook = applySubscriptionSnapshot が唯一の writer)。
+ * - 同一プラン判定は **remote 照合に一本化** (local projection で早期 return しない)。
+ * - stale 検知は「要求先 ≠ local 現在プラン」のときだけ評価する (反映待ち中の再操作を誤拒否しない)。
+ */
+
+function planChangePlan(string $code): Plan
+{
+    return Plan::query()->where('code', $code)->firstOrFail();
+}
+
+function planChangeBasePriceId(string $code): string
+{
+    $price = planChangePlan($code)->currentPrice(PlanPriceKind::Base);
+    Assert::notNull($price, "{$code} プランの current base price が未 seed");
+
+    return $price->stripe_price_id;
+}
+
+/**
+ * starter 契約中の組織を作る。
+ *
+ * @return array{Organization, Subscription}
+ */
+function planChangeOrganization(string $planCode = 'starter', string $status = 'active'): array
+{
+    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $subscription = createFakeSubscription($organization, status: $status);
+    $organization->forceFill(['plan_code' => $planCode])->save();
+    $organization->refresh();
+
+    return [$organization, $subscription];
+}
+
+function planChangeService(): SubscriptionService
+{
+    return app(SubscriptionService::class);
+}
+
+test('starter 契約中の組織は standard へ swap でき、plan_code は webhook 前なので変わらない', function (): void {
+    [$organization] = planChangeOrganization();
+    $token = (string) Str::ulid();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')
+        ->once()
+        ->withArgs(function (Organization $org, string $priceId, string $key): bool {
+            return $org->getKey() !== null
+                && $priceId === planChangeBasePriceId('standard')
+                && str_starts_with($key, 'change-plan:')
+                && str_ends_with($key, ':standard');
+        })
+        ->andReturn(SubscriptionSwapOutcome::Applied);
+
+    $outcome = planChangeService()->changePlan($organization, planChangePlan('standard'), $token, 'starter');
+
+    expect($outcome)->toBe(SubscriptionSwapOutcome::Applied);
+    // 単一 writer 契約: swap 経路は organizations.plan_code を書かない
+    expect($organization->fresh()?->plan_code)->toBe('starter');
+});
+
+test('idempotency key は change-plan:{token}:{planCode} の形をとる', function (): void {
+    [$organization] = planChangeOrganization();
+    $token = (string) Str::ulid();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')
+        ->once()
+        ->with(Mockery::type(Organization::class), planChangeBasePriceId('standard'), "change-plan:{$token}:standard")
+        ->andReturn(SubscriptionSwapOutcome::Applied);
+
+    planChangeService()->changePlan($organization, planChangePlan('standard'), $token, 'starter');
+});
+
+test('swap 後に customer.subscription.updated が届くと plan_code が追随する (projection_synced)', function (): void {
+    [$organization, $subscription] = planChangeOrganization();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);
+
+    $service = planChangeService();
+    $service->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter');
+    expect($organization->fresh()?->plan_code)->toBe('starter');
+
+    $service->applySubscriptionSnapshot($organization, new SubscriptionSnapshot(
+        stripeId: $subscription->stripe_id,
+        status: 'active',
+        basePriceId: planChangeBasePriceId('standard'),
+        baseQuantity: 1,
+        currentPeriodEnd: null,
+        trialEndsAt: null,
+        endsAt: null,
+    ));
+
+    expect($organization->fresh()?->plan_code)->toBe('standard');
+});
+
+test('要求先が local 現在プランと違い期待値も不一致なら stale で拒否する', function (): void {
+    [$organization] = planChangeOrganization();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    expect(fn () => planChangeService()->changePlan(
+        $organization, planChangePlan('standard'), (string) Str::ulid(), 'personal',
+    ))->toThrow(StalePlanChangeException::class);
+});
+
+test('plan_code が null の組織に期待値 null を渡しても stale にならない', function (): void {
+    [$organization] = planChangeOrganization();
+    $organization->forceFill(['plan_code' => null])->save();
+    $organization->refresh();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);
+
+    expect(planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), null))
+        ->toBe(SubscriptionSwapOutcome::Applied);
+});
+
+test('local が既に対象プランでも gateway を呼び、remote が同一なら AlreadyOnTargetPrice', function (): void {
+    [$organization] = planChangeOrganization(planCode: 'standard');
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()
+        ->andReturn(SubscriptionSwapOutcome::AlreadyOnTargetPrice);
+
+    expect(planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'standard'))
+        ->toBe(SubscriptionSwapOutcome::AlreadyOnTargetPrice);
+});
+
+test('local が既に対象プランでも remote が別 Price なら Applied (受付済みと嘘をつかない)', function (): void {
+    [$organization] = planChangeOrganization(planCode: 'standard');
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);
+
+    expect(planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'standard'))
+        ->toBe(SubscriptionSwapOutcome::Applied);
+});
+
+test('要求先 = local 現在プランなら期待値が古くても stale にしない', function (): void {
+    [$organization] = planChangeOrganization(planCode: 'standard');
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::AlreadyOnTargetPrice);
+
+    expect(planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter'))
+        ->toBe(SubscriptionSwapOutcome::AlreadyOnTargetPrice);
+});
+
+test('grace period (解約予約中) の契約は同一プラン要求でも state で拒否する', function (): void {
+    [$organization, $subscription] = planChangeOrganization(planCode: 'standard', status: 'canceled');
+    $subscription->forceFill(['ends_at' => CarbonImmutable::now()->addDays(10)])->save();
+    $organization->refresh();
+    // Cashier の valid() は grace period を true にする (= 変更経路には入る)
+    expect($organization->subscription('default')?->valid())->toBeTrue();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    expect(fn () => planChangeService()->changePlan(
+        $organization, planChangePlan('standard'), (string) Str::ulid(), 'standard',
+    ))->toThrow(PlanChangeNotAllowedException::class);
+});
+
+test('変更できない state は段ごとに異なる理由で拒否する', function (): void {
+    // Cashier の valid() 意味論 (実コードが正):
+    //  - past_due は Cashier::$deactivatePastDue 既定 true により active()=false = 段 1 で拒否
+    //  - paused / canceled(ends_at=null) は valid()=true のまま段 2 の state 判定へ進む
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    $messages = [];
+    foreach (['past_due', 'paused', 'canceled'] as $status) {
+        [$org] = planChangeOrganization(status: $status);
+        try {
+            planChangeService()->changePlan($org, planChangePlan('standard'), (string) Str::ulid(), 'starter');
+            $this->fail("PlanChangeNotAllowedException が投げられていない ({$status})");
+        } catch (PlanChangeNotAllowedException $e) {
+            $messages[] = $e->getMessage();
+        }
+    }
+
+    expect($messages[0])->toContain('変更できる契約がありません');
+    expect($messages[1])->toContain('一時停止');
+    expect($messages[2])->toContain('ご契約が有効でないため');
+    expect(array_unique($messages))->toHaveCount(3);
+});
+
+test('schedule 管理下の契約は swap せず拒否する', function (): void {
+    [$organization, $subscription] = planChangeOrganization();
+    $subscription->forceFill([
+        'stripe_schedule_id' => 'sub_sched_1',
+        'schedule_setup_status' => ScheduleSetupStatus::Configured,
+    ])->save();
+    $organization->refresh();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    expect(fn () => planChangeService()->changePlan(
+        $organization, planChangePlan('standard'), (string) Str::ulid(), 'starter',
+    ))->toThrow(PlanChangeNotAllowedException::class, '予約済みのプラン変更があります。反映後に再度お試しください。');
+});
+
+test('契約が無い組織は業務拒否 (前提違反の InvalidArgumentException にしない)', function (): void {
+    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    expect(fn () => planChangeService()->changePlan(
+        $organization, planChangePlan('standard'), (string) Str::ulid(), null,
+    ))->toThrow(PlanChangeNotAllowedException::class);
+});
+
+test('決済対象外プラン (personal) は 422 で倒れ、base Price 未設定の Assert には落ちない', function (): void {
+    [$organization] = planChangeOrganization();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldNotReceive('swapSubscriptionPrices');
+
+    // 段 0 の順序 (assertStripeBillablePlan が先) の回帰防止:
+    // personal は base Price を持たないため、順序を逆にすると InvalidArgumentException になる。
+    expect(fn () => planChangeService()->changePlan(
+        $organization, planChangePlan('personal'), (string) Str::ulid(), 'starter',
+    ))->toThrow(ValidationException::class);
+});
+
+test('ABA 往復では 3 回とも異なる idempotency key になる', function (): void {
+    [$organization] = planChangeOrganization();
+
+    $keys = [];
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')
+        ->times(3)
+        ->andReturnUsing(function (Organization $org, string $priceId, string $key) use (&$keys): SubscriptionSwapOutcome {
+            $keys[] = $key;
+
+            return SubscriptionSwapOutcome::Applied;
+        });
+
+    $service = planChangeService();
+    $service->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter');
+    $service->changePlan($organization, planChangePlan('starter'), (string) Str::ulid(), 'starter');
+    $service->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter');
+
+    expect($keys)->toHaveCount(3);
+    expect(array_unique($keys))->toHaveCount(3);
+});
+
+test('gateway の PlanChangeFailedException はそのまま伝播し reason が log に出る', function (): void {
+    [$organization] = planChangeOrganization();
+
+    $gateway = $this->mock(StripeGatewayInterface::class);
+    $gateway->shouldReceive('swapSubscriptionPrices')
+        ->once()
+        ->andThrow(PlanChangeFailedException::unexpectedShape('sub_x', 2, null));
+
+    Log::shouldReceive('error')
+        ->once()
+        ->withArgs(function (string $message, array $context): bool {
+            return $message === 'changePlan: swap failed'
+                && is_string($context['reason'])
+                && str_starts_with($context['reason'], 'unexpected_shape:');
+        });
+
+    try {
+        planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter');
+        $this->fail('PlanChangeFailedException が投げられていない');
+    } catch (PlanChangeFailedException $e) {
+        expect($e->getMessage())->toBe(PlanChangeFailedException::USER_MESSAGE);
+    }
+});
diff --git a/tests/Feature/Billing/SubscriptionSwapGatewayTest.php b/tests/Feature/Billing/SubscriptionSwapGatewayTest.php
new file mode 100644
index 0000000..25ef359
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionSwapGatewayTest.php
@@ -0,0 +1,229 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\SubscriptionSwapOutcome;
+use App\Exceptions\Billing\PlanChangeFailedException;
+use App\Models\Organization;
+use App\Services\Billing\CashierStripeGateway;
+use Mockery\MockInterface;
+use Stripe\Exception\InvalidRequestException;
+use Stripe\Service\SubscriptionService as StripeSubscriptionService;
+use Stripe\StripeClient;
+use Stripe\Subscription as StripeSubscription;
+
+/*
+ * F-3-01 層 0: CashierStripeGateway::swapSubscriptionPrices() の **制御フロー**を固定する。
+ *
+ * Stripe client の取得は protected seam (`stripe(): StripeClient`) 越しなので、テストでは
+ * seam を差し替えた subclass に mock client を返させる (**実ネットワークに出ない**)。
+ * seam をリネームしたら本テストも同時に更新すること。
+ *
+ * 固定する契約:
+ *  - remote の base item price が対象と同一 → `update()` は 0 回 / `AlreadyOnTargetPrice`
+ *  - 異なる → `update()` が 1 回・buildSwapPayload() と同一 payload + idempotency key / `Applied`
+ *  - `retrieve` と `update` は同一 subscription id を使う
+ *  - 想定外の item 構成 (0 個 / 2 個 / 解決不能 / quantity != 1) は fail-closed で update 0 回
+ *  - SDK 例外 (`ApiErrorException`) は境界を越えず `PlanChangeFailedException` に変換される
+ */
+
+/**
+ * seam を差し替えた gateway (Stripe client を注入できる本番実装)。
+ */
+function swapGateway(StripeClient $client): CashierStripeGateway
+{
+    return new class($client) extends CashierStripeGateway
+    {
+        public function __construct(private readonly StripeClient $client) {}
+
+        protected function stripe(): StripeClient
+        {
+            return $this->client;
+        }
+    };
+}
+
+/**
+ * remote subscription object を組み立てる (ネットワークに出ない)。
+ *
+ * @param  list<array<string, mixed>>  $items
+ */
+function swapRemoteSubscription(string $stripeId, array $items): StripeSubscription
+{
+    return StripeSubscription::constructFrom([
+        'id' => $stripeId,
+        'object' => 'subscription',
+        'items' => ['object' => 'list', 'data' => $items],
+    ]);
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function swapRemoteItem(string $id, ?string $priceId, ?int $quantity = 1): array
+{
+    return [
+        'id' => $id,
+        'object' => 'subscription_item',
+        'price' => $priceId === null ? null : ['id' => $priceId, 'object' => 'price'],
+        'quantity' => $quantity,
+    ];
+}
+
+/**
+ * 契約中組織 + mock Stripe client を用意する。
+ *
+ * @return array{Organization, StripeClient, MockInterface}
+ */
+function swapGatewayFixture(): array
+{
+    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $subscription = contractPaidPlan($organization);
+    $subscription->forceFill(['stripe_id' => 'sub_swap_1'])->save();
+    $organization->refresh();
+
+    $subscriptionsService = Mockery::mock(StripeSubscriptionService::class);
+    /** @var StripeClient&MockInterface $client */
+    $client = Mockery::mock(StripeClient::class);
+    // `$client->subscriptions` は StripeClient::__get → getService() に落ちる
+    // (Mockery は magic method を素通しするため getService に期待を張る)。
+    $client->shouldReceive('getService')->with('subscriptions')->andReturn($subscriptionsService);
+
+    return [$organization, $client, $subscriptionsService];
+}
+
+test('remote が既に対象 Price なら update を送らず AlreadyOnTargetPrice を返す', function (): void {
+    [$organization, $client, $subscriptions] = swapGatewayFixture();
+
+    $subscriptions->shouldReceive('retrieve')
+        ->once()
+        ->with('sub_swap_1', ['expand' => ['items.data']])
+        ->andReturn(swapRemoteSubscription('sub_swap_1', [swapRemoteItem('si_1', 'price_target')]));
+    $subscriptions->shouldNotReceive('update');
+
+    $outcome = swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
+
+    expect($outcome)->toBe(SubscriptionSwapOutcome::AlreadyOnTargetPrice);
+});
+
+test('remote が別 Price なら既存 item id を指定した update を 1 回だけ送る', function (): void {
+    [$organization, $client, $subscriptions] = swapGatewayFixture();
+
+    $subscriptions->shouldReceive('retrieve')
+        ->once()
+        ->with('sub_swap_1', ['expand' => ['items.data']])
+        ->andReturn(swapRemoteSubscription('sub_swap_1', [swapRemoteItem('si_1', 'price_current')]));
+    $subscriptions->shouldReceive('update')
+        ->once()
+        ->with(
+            'sub_swap_1',
+            (new CashierStripeGateway)->buildSwapPayload('si_1', 'price_target'),
+            ['idempotency_key' => 'change-plan:tok:standard'],
+        )
+        ->andReturn(swapRemoteSubscription('sub_swap_1', [swapRemoteItem('si_1', 'price_target')]));
+
+    $outcome = swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
+
+    expect($outcome)->toBe(SubscriptionSwapOutcome::Applied);
+});
+
+test('item が 0 個の remote は fail-closed で update を送らない', function (): void {
+    [$organization, $client, $subscriptions] = swapGatewayFixture();
+
+    $subscriptions->shouldReceive('retrieve')->once()->andReturn(swapRemoteSubscription('sub_swap_1', []));
+    $subscriptions->shouldNotReceive('update');
+
+    try {
+        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
+        $this->fail('PlanChangeFailedException が投げられていない');
+    } catch (PlanChangeFailedException $e) {
+        expect($e->reason)->toStartWith('unexpected_shape:');
+        expect($e->getMessage())->toBe(PlanChangeFailedException::USER_MESSAGE);
+    }
+});
+
+test('item が 2 個の remote は fail-closed で update を送らない', function (): void {
+    [$organization, $client, $subscriptions] = swapGatewayFixture();
+
+    $subscriptions->shouldReceive('retrieve')->once()->andReturn(swapRemoteSubscription('sub_swap_1', [
+        swapRemoteItem('si_1', 'price_current'),
+        swapRemoteItem('si_2', 'price_seat'),
+    ]));
+    $subscriptions->shouldNotReceive('update');
+
+    try {
+        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
+        $this->fail('PlanChangeFailedException が投げられていない');
+    } catch (PlanChangeFailedException $e) {
+        expect($e->reason)->toStartWith('unexpected_shape:');
+    }
+});
+
+test('正常 1 件 + price 解決不能 1 件は skip せず fail-closed にする', function (): void {
+    // skip すると「正常 1 件 + 解決不能 1 件」が正規化後 1 件になり、多 item 契約を更新してしまう。
+    [$organization, $client, $subscriptions] = swapGatewayFixture();
+
+    $subscriptions->shouldReceive('retrieve')->once()->andReturn(swapRemoteSubscription('sub_swap_1', [
+        swapRemoteItem('si_1', 'price_current'),
+        swapRemoteItem('si_2', null),
+    ]));
+    $subscriptions->shouldNotReceive('update');
+
+    try {
+        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
+        $this->fail('PlanChangeFailedException が投げられていない');
+    } catch (PlanChangeFailedException $e) {
+        expect($e->reason)->toStartWith('unexpected_shape:');
+    }
+});
+
+test('quantity が 1 でない item は暗黙補正せず fail-closed にする', function (): void {
+    [$organization, $client, $subscriptions] = swapGatewayFixture();
+
+    $subscriptions->shouldReceive('retrieve')->once()->andReturn(swapRemoteSubscription('sub_swap_1', [
+        swapRemoteItem('si_1', 'price_current', 2),
+    ]));
+    $subscriptions->shouldNotReceive('update');
+
+    try {
+        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
+        $this->fail('PlanChangeFailedException が投げられていない');
+    } catch (PlanChangeFailedException $e) {
+        expect($e->reason)->toStartWith('unexpected_shape:');
+    }
+});
+
+test('retrieve の ApiErrorException は PlanChangeFailedException に変換される', function (): void {
+    [$organization, $client, $subscriptions] = swapGatewayFixture();
+
+    $sdkError = new InvalidRequestException('no such subscription');
+    $subscriptions->shouldReceive('retrieve')->once()->andThrow($sdkError);
+    $subscriptions->shouldNotReceive('update');
+
+    try {
+        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
+        $this->fail('PlanChangeFailedException が投げられていない');
+    } catch (PlanChangeFailedException $e) {
+        expect($e->getMessage())->toBe(PlanChangeFailedException::USER_MESSAGE);
+        expect($e->reason)->toStartWith('stripe_api_error:');
+        expect($e->getPrevious())->toBe($sdkError);
+    }
+});
+
+test('update の ApiErrorException も PlanChangeFailedException に変換される', function (): void {
+    [$organization, $client, $subscriptions] = swapGatewayFixture();
+
+    $sdkError = new InvalidRequestException('card declined');
+    $subscriptions->shouldReceive('retrieve')->once()->andReturn(
+        swapRemoteSubscription('sub_swap_1', [swapRemoteItem('si_1', 'price_current')]),
+    );
+    $subscriptions->shouldReceive('update')->once()->andThrow($sdkError);
+
+    try {
+        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
+        $this->fail('PlanChangeFailedException が投げられていない');
+    } catch (PlanChangeFailedException $e) {
+        expect($e->reason)->toStartWith('stripe_api_error:');
+        expect($e->getPrevious())->toBe($sdkError);
+    }
+});
diff --git a/tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php b/tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php
new file mode 100644
index 0000000..ff092d8
--- /dev/null
+++ b/tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Billing\CashierStripeGateway;
+
+/*
+ * F-3-01: subscription swap (プラン変更) payload の invariant。**payload 変更の唯一の入口**。
+ *
+ * - `items[0]` は **既存 item id を指定**して price を差し替える (id 無指定は item の二重化)。
+ * - `proration_behavior = create_prorations` — 日割り明細を作り **次回請求に反映**する。
+ *   `always_invoice` にしない (= 即時請求 → 与信失敗の状態遷移を持ち込まない)。
+ * - `billing_cycle_anchor` / `trial_end` / `payment_behavior` / `default_tax_rates` は **送らない**
+ *   (即時請求・trial 再開の誘発を構造的に避ける)。
+ */
+
+test('payload は既存 item id と price / quantity=1 と create_prorations だけを返す', function (): void {
+    $payload = (new CashierStripeGateway)->buildSwapPayload('si_existing_1', 'price_standard');
+
+    expect($payload)->toBe([
+        'items' => [
+            ['id' => 'si_existing_1', 'price' => 'price_standard', 'quantity' => 1],
+        ],
+        'proration_behavior' => 'create_prorations',
+    ]);
+    // キー集合を厳密一致で固定する (増やすなら本テストを通す = 意図的な変更のみ)
+    expect(array_keys($payload))->toBe(['items', 'proration_behavior']);
+});
+
+test('payload に即時請求・trial 再開を誘発するパラメータを含めない', function (): void {
+    $payload = (new CashierStripeGateway)->buildSwapPayload('si_existing_1', 'price_standard');
+
+    expect($payload)->not->toHaveKey('billing_cycle_anchor');
+    expect($payload)->not->toHaveKey('trial_end');
+    expect($payload)->not->toHaveKey('payment_behavior');
+    expect($payload)->not->toHaveKey('default_tax_rates');
+    expect($payload['proration_behavior'])->not->toBe('always_invoice');
+});
+
+test('空の item id は fail-fast する', function (): void {
+    (new CashierStripeGateway)->buildSwapPayload('', 'price_standard');
+})->throws(InvalidArgumentException::class);
+
+test('空の price id は fail-fast する', function (): void {
+    (new CashierStripeGateway)->buildSwapPayload('si_existing_1', '');
+})->throws(InvalidArgumentException::class);
diff --git a/tests/js/pages/Billing/Plans.test.ts b/tests/js/pages/Billing/Plans.test.ts
index 997d330..cb2868b 100644
--- a/tests/js/pages/Billing/Plans.test.ts
+++ b/tests/js/pages/Billing/Plans.test.ts
@@ -43,6 +43,29 @@ const basePage: BillingPlansPageProps = {
     billingState: "active_free_plan",
     canManage: true,
     subscriptionAttemptToken: "01JQ0000000000000000000000",
+    hasChangeableSubscription: false,
+    planChangeToken: "01JQ1111111111111111111111",
+    planChangeExpectedPlanCode: null,
+};
+
+/** 有効な契約がある組織 (starter 契約中 → in-app swap 経路) の props */
+const contractedPage: BillingPlansPageProps = {
+    ...basePage,
+    plans: [
+        ...basePage.plans,
+        {
+            code: "starter",
+            name: "Starter",
+            baseAmountJpy: 980,
+            maxProjects: 3,
+            maxMembers: 5,
+            maxStorageGb: 10,
+        },
+    ],
+    currentPlanCode: "starter",
+    billingState: "subscribed",
+    hasChangeableSubscription: true,
+    planChangeExpectedPlanCode: "starter",
 };
 
 afterEach(() => {
@@ -92,6 +115,77 @@ describe("Billing/Plans", () => {
         );
     });
 
+    it("有効な契約があるときは /billing/plan へ swap payload を POST する", async () => {
+        render(Plans, { props: { page: contractedPage } });
+
+        await fireEvent.click(screen.getByTestId("plan-change-standard"));
+        await screen.findByTestId("plan-change-confirm");
+        await fireEvent.click(screen.getByText("変更する"));
+
+        const [url, payload] = routerPostMock.mock.calls[0] as [string, Record<string, unknown>];
+        expect(url).toBe("/billing/plan");
+        expect(payload).toEqual({
+            plan_code: "standard",
+            current_plan_code: "starter",
+            plan_change_token: "01JQ1111111111111111111111",
+        });
+        expect(payload).not.toHaveProperty("subscription_attempt_token");
+    });
+
+    it("current_plan_code は表示用 currentPlanCode ではなく planChangeExpectedPlanCode を送る", async () => {
+        // grace period 契約では表示用 (personal) と競合制御値 (starter) が食い違う
+        render(Plans, {
+            props: {
+                page: {
+                    ...contractedPage,
+                    currentPlanCode: "personal",
+                    planChangeExpectedPlanCode: "starter",
+                },
+            },
+        });
+
+        await fireEvent.click(screen.getByTestId("plan-change-standard"));
+        await screen.findByTestId("plan-change-confirm");
+        await fireEvent.click(screen.getByText("変更する"));
+
+        const [, payload] = routerPostMock.mock.calls[0] as [string, Record<string, unknown>];
+        expect(payload.current_plan_code).toBe("starter");
+    });
+
+    it("downgrade の確認ダイアログは上限低下を告知し、upgrade では出さない", async () => {
+        const downgrading = {
+            ...contractedPage,
+            currentPlanCode: "standard",
+            planChangeExpectedPlanCode: "standard",
+        };
+        render(Plans, { props: { page: downgrading } });
+
+        await fireEvent.click(screen.getByTestId("plan-change-starter"));
+        const dialog = await screen.findByTestId("plan-change-confirm");
+        expect(dialog).toHaveTextContent("上限内に収まるまで新規作成とアップロードができません");
+        cleanup();
+
+        render(Plans, { props: { page: contractedPage } });
+        await fireEvent.click(screen.getByTestId("plan-change-standard"));
+        const upgradeDialog = await screen.findByTestId("plan-change-confirm");
+        expect(upgradeDialog).not.toHaveTextContent(
+            "上限内に収まるまで新規作成とアップロードができません",
+        );
+        expect(upgradeDialog).toHaveTextContent("日割り");
+    });
+
+    it("errors.current_plan_code だけでも dialog にサーバ文言を描画する", async () => {
+        pageState.props = { errors: { current_plan_code: "プランが別の操作で変更されました。" } };
+        render(Plans, { props: { page: contractedPage } });
+
+        await fireEvent.click(screen.getByTestId("plan-change-standard"));
+        await screen.findByTestId("plan-change-confirm");
+
+        expect(screen.getByTestId("plan-change-error")).toHaveTextContent(
+            "プランが別の操作で変更されました。",
+        );
+    });
+
     it("canManage=false でも CTA は enabled のまま (押下で理由を出す)", async () => {
         render(Plans, { props: { page: { ...basePage, canManage: false } } });
 

```


## テスト結果

- `composer test` (全 Feature/Unit/Architecture): 実行中 → 結果は本レビュー後に確定させる。新規/改修分は個別に green を確認済み:
  - `tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php` / `tests/Feature/Billing/SubscriptionSwapGatewayTest.php` /
    `tests/Feature/Billing/SubscriptionPlanChangeTest.php` / `tests/Feature/Billing/PlanChangeEndpointTest.php` /
    `tests/Feature/Billing/BillingPlansPageTest.php` → **52 passed / 262 assertions / 0 failed**
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: **passed**
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: **passed**
- `pnpm vitest run tests/js/pages/Billing/Plans.test.ts`: **9 passed**
- `scripts/bug-hunt-inventory-check.sh`: operations drift なし (screens 側の `organizations.api-keys.index` 消失候補は本変更と無関係の既存 drift)


## design system 参照 (DESIGN.md 全文)

---
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
colors:
    primary: "#2563EB"
    primary-hover: "#1D4ED8"
    tertiary: "#0F766E"
    tertiary-hover: "#115E59"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#15803D"
    warning: "#B45309"
    danger: "#DC2626"
typography:
    display:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 48px
        fontWeight: 500
        lineHeight: 1.2
        letterSpacing: 0.02em
    h1:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 32px
        fontWeight: 500
        lineHeight: 1.3
        letterSpacing: 0.02em
    h2:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 24px
        fontWeight: 500
        lineHeight: 1.4
    h3:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 18px
        fontWeight: 500
        lineHeight: 1.5
    body:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 16px
        fontWeight: 400
        lineHeight: 1.7
    caption:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 12px
        fontWeight: 400
        lineHeight: 1.5
rounded:
    sm: 4px
    md: 6px
    lg: 8px
spacing:
    xs: 4px
    sm: 8px
    md: 16px
    lg: 24px
    xl: 40px
---

# Design System

本ファイルが**デザインの canonical source**。`resources/css/tokens.css` はその実装写像であり、
独自に値を変えてはいけない(同期契約は `docs/design-system.md`)。

## Overview

テンプレート既定のニュートラルテーマ。中立的な青(#2563EB)を主役、teal(#0F766E)を強アクセント、
無彩のスレート(#F4F4F5)を背景に据える。**アプリ固有のテーマは frontmatter の色値と
tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。

## Colors

色は意味で割り当てる。順序や見た目の好みで使い分けない。

- **Primary(#2563EB)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
  1 画面の主要 CTA 以外には濫用しない。
  - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
- **Tertiary(#0F766E)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
  1 画面に 1 箇所が原則。
  - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
- **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
  - tailwind: `bg-neutral`
- **Surface(#FFFFFF)**: カード・モーダル・浮いた要素の背景。Neutral との明度差で奥行きを出す。
  - tailwind: `bg-surface`
- **Border(#E4E4E7)**: 区切り線、入力欄の枠。常に細く(1px)。
  - tailwind: `border-border`
- **Border Strong(#A1A1AA)**: 区切りの強調、ghost ボタンの枠。
  - tailwind: `border-border-strong`
- **Text Primary(#18181B)**: 本文・見出しの主たる色。純黒は使わない。
  - tailwind: `text-text`(`--color-text` を参照)
- **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
  - tailwind: `text-text-secondary`

### 状態色

- **Success(#15803D)**: 完了・正常・公開済み。
  - tailwind: `text-success`, `bg-success`, `border-success`
- **Warning(#B45309)**: 注意・確認が必要・保留。
  - tailwind: `text-warning`, `bg-warning`, `border-warning`
- **Danger(#DC2626)**: 失敗・破壊的操作・エラー。Tertiary とは別物
  (Tertiary は前向きな強調、Danger は否定的なシグナル)。
  - tailwind: `text-danger`, `bg-danger`, `border-danger`

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
検討すること**(追加条件は `docs/design-system.md` の 4 条件)。

## Typography

全ランプ Noto Sans JP。フォントウェイトは **400 と 500 の 2 階層のみ**(700 は使わない)。
コード・識別子・数値整列には `font-mono` を許可する(日本語 prose には使わない)。

### Typography ramp utility

各 ramp は `resources/css/tokens.css` の `@utility` で定義済。実装はこの utility を
そのまま class として適用する。**raw の `text-sm` / `font-bold` 等は禁止**(ds-purity が検出)。

- **text-display**: 48px / 500 / lh 1.2 / ls 0.02em — tailwind: `text-display`
- **text-h1**: 32px / 500 / lh 1.3 / ls 0.02em — tailwind: `text-h1`
- **text-h2**: 24px / 500 / lh 1.4 — tailwind: `text-h2`
- **text-h3**: 18px / 500 / lh 1.5 — tailwind: `text-h3`
- **text-body**: 16px / 400 / lh 1.7 — tailwind: `text-body`
- **text-caption**: 12px / 400 / lh 1.5 — tailwind: `text-caption`

役割マッピング: 本文/入力値/主要数値 → `text-body`、ラベル/補助情報/日時 → `text-caption`、
page タイトル → `text-h1`/`text-h2`、section/card 見出し → `text-h3`。
強調は `font-medium`(500)を上限とし、足りなければ weight を上げず ramp 昇格+余白+
色階層(text vs text-secondary)でコントラストを作る。

## Layout

8px ベースのスケール。要素間は `md (16px)` を基本に、セクション間は `xl (40px)`。
コンテナは最大幅 1080px を目安に、画面の左右に 32px の余白を確保する。

## Elevation & Depth

**`box-shadow` は使わない。** Neutral(背景)と Surface(カード)の明度差、および 1px の
ボーダーで階層を表現する。ホバー時も影を出さず、ボーダー色や文字色の変化で反応を示す。
グラデーション・scale 効果も使わない。

## Shapes

角丸 ramp は **`rounded-sm`(4px)/ `rounded-md`(6px)/ `rounded-lg`(8px)の 3 段のみ**。
DOM 役割で選ぶ(上から優先): カード・モーダル=`lg` / 中間 box(パネル・`<pre>`)=`md` /
ボタン・入力・バッジ等の小コントロール=`sm`。
素の `rounded`・`rounded-xl` 以上・任意値・方向別(`rounded-t-*` 等)は使わない。
完全円(`rounded-full`)はアバター/status dot/トグル等の**真に円形な UI に限る** ramp 外の例外で、
file-scoped allowlist で個別管理する。

## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。

### Button

実装: `components/atoms/Button.svelte`(仕様の真実は `Button.types.ts`)。

| variant | 用途 | スタイル要旨 |
|---------|------|------------|
| `primary` | 主要 CTA(1 画面 1 つ目安) | bg-primary + text-neutral |
| `tertiary` | 真に重要な前向き CTA(1 画面 1 箇所) | bg-tertiary + text-neutral |
| `ghost` | 補助・キャンセル | 透明 + border-border-strong、hover で primary 化 |
| `neutral` | 取消可能・UI-only の補助操作(一時停止等) | bg-neutral + 常時 border(境界確保) |
| `success` | 肯定操作(追加・承認・付与) | bg-success + text-neutral |
| `danger` | dialog/form の主破壊 CTA | bg-danger + text-neutral |
| `danger-outline` | section 単位の破壊(card 内の削除) | border-danger、hover で塗り |
| `danger-ghost` | dense な row/list 内の破壊アクション | text-danger + 透明、hover で淡い tint |

- **全 variant が border(透明 or 色)を持ち外形高さを統一する**
- danger 系は irreversible / destructive 操作専用(削除・revoke・移譲・再開不可の中断)。
  危険度ではなく**配置文脈**で 3 重みを選ぶ
- **anchor 対応**: `href` 指定で `<a>`(`inertia` 指定で Inertia Link)。anchor モードでは
  `type`/`disabled` は型レベルで禁止。`target="_blank"` には `rel="noopener noreferrer"` を自動補完
- **iconOnly**: `ghost` / `neutral` / `danger-ghost` のみ許可。`ariaLabel` が型で必須
- **disclosure**: button モード限定で `ariaExpanded` / `ariaControls` / `element`(bindable な
  `HTMLButtonElement` 参照)を受ける。ハンバーガー等のトグルはこれを使い素の `<button>` を書かない
- size: `sm`(caption)/ `md`(既定)/ `lg`(form 入力面との高さ整合限定)

### Input / Textarea / Select(入力系 atom)

実装: `components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte`。
見た目は `components/atoms/input-state.ts`(`INPUT_BASE_CLASSES` + `inputStateClass`)に集約し、
入力系 atom 間で統一する。`error` prop で danger 枠と `aria-invalid` が連動する。
`aria-describedby` 等は restProps で透過。Select の `<option>` 群は呼び出し側が
children snippet として記述する。Input の `type` は text 系に限定した union。
ラベル・エラー文言・`aria-describedby` の配線は FormField molecule の責務
(入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
PasswordInput molecule を使う。

### Checkbox

実装: `components/atoms/Checkbox.svelte`。インラインラベル(右側)とエラー表示
(FormError 内包)を持つチェックボックス。ラベルは string のほか snippet でも受けられる
(利用規約リンク等を含める用)。複数行ラベルでもチェックボックスが 1 行目に揃う行揃えは
本 atom の責務。ページ側で素の `<input type="checkbox">` を書かない(§Do's and Don'ts)。

### FormError

実装: `components/atoms/FormError.svelte`。フィールド単位のエラー文言
(`text-caption text-danger`。message が無ければ何も描画しない)。FormField / Checkbox から
composition される前提の最小 atom。単体で使う場合、`aria-describedby` の配線は呼び出し側の
責務。ページ常在の通知は Alert、一時通知は Toast を使う。

### Avatar

実装: `components/atoms/Avatar.svelte`。`src` があれば画像、無ければ `name` の先頭 1 文字
(大文字化。サロゲートペアも 1 文字扱い)をイニシャル表示する。アバターは真に円形な UI
のため `rounded-full` を使う ramp 外例外(Toggle と並び ds-purity の file-scoped allowlist
出荷時 2 件の 1 つ)。size: `sm` / `md`(既定)/ `lg`。

### Badge

実装: `components/atoms/Badge.svelte`(仕様の真実は `Badge.types.ts`)。状態・属性の
**結果表示**ラベル(操作は Button。action button と status badge は意味色を独立に判断する
— §色の意味的割り当てルール)。tone: `primary` / `tertiary` / `success` / `warning` /
`danger` / `neutral`(中立ラベル)。既定は soft(tone 色の淡い背景 + tone 色文字)、
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
`aria-checked`)。フォーム送信を伴う選択には使わない。`ariaLabel` は型レベルで必須。
トラックは On=`bg-primary` / Off=`bg-border-strong`、つまみは `bg-surface`(影なし、
明度差で表現)。`rounded-full` は真に円形な UI の例外として file-scoped allowlist で管理する。

### Modal

実装: `components/organisms/Modal.svelte`(仕様の真実は `Modal.types.ts`)。bits-ui Dialog のラップ。

- overlay は `bg-text/50`(墨色 50%。黒 hex を使わない)、本体は `bg-surface border border-border rounded-lg`
  (影が使えないためボーダーで背景と区別する)
- size: `sm`(max-w-md)/ `md`(max-w-lg 既定)/ `lg`(max-w-2xl)
- `processing` 中は ESC / overlay クリックでの close を抑止し、X ボタンを disabled にする(二重実行防止)
- title は `text-h3`。a11y 名は bits-ui `Dialog.Title` 経由で `aria-labelledby` に配線される

### ConfirmDialog

実装: `components/organisms/ConfirmDialog.svelte`(仕様の真実は `ConfirmDialog.types.ts`)。Modal の composition。

- `confirmVariant` は `primary` / `danger` の 2 値のみ。**irreversible / destructive な操作は danger**
  (§色の意味的割り当てルール)
- footer は Button atom(cancel=`ghost` / confirm=`confirmVariant`、processing 中は loading)
- confirm で自動 close しない(処理完了後に呼び出し側が `open=false` にする)。
  cancel / ESC / overlay / X は `onCancel` を発火して close
- `banner?: Snippet` は message 直上の任意スロット(サーバ validation エラーの Alert 等)。
  未指定なら描画されない(既存の出力は不変)

### Toast

実装: `components/organisms/ToastContainer.svelte` + `lib/stores/toast.ts`(addToast / dismissToast)。
Laravel flash の取り込みは `lib/stores/flash-to-toast.ts` の `consumeFlash`(visitKey で de-dup)。

- 上部中央 fixed(`top-6 left-1/2 -translate-x-1/2 z-50`)に縦 stack 表示。アプリで 1 箇所のみ mount する
- 自動消去: **success / info / warning = 4 秒、error = 手動閉じのみ**
- 各 toast は `bg-surface` + type 別 border / アイコン色(success / primary(info)/ warning / danger)。
  アイコンは CircleCheck / Info / TriangleAlert / CircleX(@lucide/svelte)
- a11y: `role="status"`(error のみ `role="alert"`)

### Alert

実装: `components/atoms/Alert.svelte`。ページ内に常在するインライン通知ボックス
(一時通知は Toast、フィールド単位のエラーは FormField/FormError を使う)。

- type: `success` / `warning` / `danger` / `info`(info は primary を流用。Toast と同じ規約)
- 配色: ボーダー=状態色、見出し(title 任意)=状態色、本文=`text-text`、背景=`bg-surface`。
  テーマ色を面塗りに使わない。中間 box なので `rounded-md`
- `action` snippet(本文下の CTA)、`dismissible` + `onDismiss`(右上の X)を持つ
- a11y: **danger のみ `role="alert"`(assertive)**、他は `role="status"`(polite)

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+
ヘルプの複合 molecule。入力 atom を最小責務に保つため、ラベル・エラー文言・
`aria-describedby` の配線は本 molecule が担う(関心分離)。children snippet に
`{ id, describedBy, invalid }` を渡すので、呼び出し側はそれを入力 atom へ流し込む。
`required` は `*`(danger 色、`aria-hidden`)をラベルに付与する。フォームの入力欄は
本 molecule 経由で組む(AGENTS.md 実装規約)。

### DangerZone

実装: `components/molecules/DangerZone.svelte`。破壊的・取り返しのつかない操作
(アカウント削除等)を集約する警告セクション(presentational・状態なし)。
`border-danger/30` + 淡い danger 背景の枠に title(danger 色 `text-h3`)+ 任意の
description、children には danger 系 Button(card 内なら `danger-outline`)を置く。
`<section>` + `aria-labelledby` で region 境界に accessible name を紐付ける。
複数同居時は `idBase` で id 衝突を回避する。

### Divider

実装: `components/molecules/Divider.svelte`。区切り線の正規化(「または」セパレータ等)。
`label` 指定時は中央ラベル付き区切り(線は `aria-hidden`、ラベルは `bg-surface` で線を
切り抜く)、省略時は素の `<hr>`。余白は呼び出し側が class で渡す(`my-6` 等)。

### Pagination

実装: `components/molecules/Pagination.svelte`。前へ / ページ番号 / 次へのページ送り UI。
callback ベース(ページング state は親が持ち、`currentPage` / `totalPages` を受けて
`onChange(page)` を返す)で遷移手段を持たないため、全て `<button type="button">` で構成する
(Inertia 遷移かローカル state 更新かは呼び出し側裁量)。総ページ ≤ 7 は全番号表示、
超過時は先頭・末尾 + 現在ページ ± 1 の窓を出し、飛びに省略記号を挿入する最小実装。
`<nav>` ランドマーク + 現在ページに `aria-current="page"`。

### Tabs

実装: `components/molecules/Tabs.svelte`。**同一ページ内 section 切替**の WAI-ARIA タブバー
(tablist のみ。URL 遷移で切り替えるページ間タブは ApiKeyTabNav のような専用 molecule を
使う)。パネル本体の描画は呼び出し側責務(god component 回避)で、
`id="{idBase}-panel-{tab.id}"` / `role="tabpanel"` / `aria-labelledby` を id 生成規則に
揃えて配線する。キーボードは ←/→(端でラップしない)+ Home/End、自動アクティベーション +
roving tabindex(active のみ tabindex=0)。`active` は bindable、`idBase` は必須
(複数同居時の id 衝突回避)。

### PasswordInput

実装: `components/molecules/PasswordInput.svelte`。Input atom + 右端の Eye/EyeOff トグルで
`password` ↔ `text` を即時切替する(button トグル + `aria-pressed`)。`id` は必須
(トグルの `aria-controls` に結線)。label/error 配線は FormField 側が担う。
Auth 系のパスワード入力は素の `Input type="password"` ではなく本 molecule を使う。

### CodeSnippet

実装: `components/molecules/CodeSnippet.svelte`。コピー付きコードブロック
(API キー・リカバリコード・CLI コマンド等)。コピー処理(navigator.clipboard)は
component 内に内包し、成功「コピー完了」/失敗「コピー失敗」を 2 秒表示する。
`<pre>` は `rounded-md bg-neutral` + `font-mono text-caption`。

### StatCard

実装: `components/molecules/StatCard.svelte`。Card atom に label(`text-caption`)+
value(`text-h2`。weight でなく ramp 昇格で強調)+ 任意の subtext / Lucide icon
(`bg-primary-soft` の rounded-md box)を載せる統計カード。

### EmptyState

実装: `components/molecules/EmptyState.svelte`。リストやテーブルが空のとき、次の行動を
案内する空状態表示。`description`(必須)+ 任意の `title` / Lucide `icon`(装飾なので
`aria-hidden`、`size-10`)。`cta` は discriminated union で遷移(`kind: "link"` = Button
の anchor+inertia)と操作(`kind: "action"` = onclick)を型安全に出し分ける。`bordered`
で破線枠サーフェス(`border-dashed`。drop 領域や明示的な空 region 向け)。

### Breadcrumb

実装: `components/molecules/Breadcrumb.svelte`。`BreadcrumbItem[]`(`@/types/components`)を
`ChevronRight` 区切りで並べるパンくず。**`href` 省略の項目は現在位置**としてリンクにしない。
atom 非依存(Lucide アイコンのみ)。単体で置かず、通常は PageHeaderSection 経由で出す。

### PageHeader / PageHeaderSection

実装: `components/molecules/PageHeaderSection.svelte`(full feature)と
`components/molecules/PageHeader.svelte`(shorthand)。

- **PageHeaderSection**: `title` / `breadcrumbs` / `description` / `icon`(Lucide 互換
  `Component`)/ actions(`children` Snippet)を持つ詳細画面用ヘッダ。全幅バーは
  PageContainer の padding を打ち消す**負マージン契約**で敷き、サイドバーのロゴブロックと
  同じ高さに揃える。**パンくずは 2 件以上のときだけ出す**(1 件は h1 と二重提示になるため)。
- **PageHeader**: breadcrumbs / actions を使わないルート画面用の薄いラッパー。
  内部で PageHeaderSection を呼ぶだけ。**actions や breadcrumbs が要るなら
  PageHeaderSection を直接使う**(PageHeader に prop を足さない)。
- actions は children Snippet で渡す(旧 slot API は使わない)。

### NotificationBell

実装: `components/molecules/NotificationBell.svelte`。`/notifications` への Inertia link に
未読数バッジを重ねた通知ベル。未読数は shared props(`notifications.unreadCount`)を親が渡す。
**100 以上は `99+` に丸める**。v1 はドロップダウンを持たない最小構成(フォーカス管理・
開閉状態を持たない)。**通知はこのベルが単一導線**で、サイドバー nav 項目に重複掲載しない。
`data-testid` は既定 `notification-bell`(mobile は呼び出し側が `notification-bell-mobile`)。

### PricingPlanCard

実装: `components/molecules/PricingPlanCard.svelte`(仕様の真実は `PricingPlanCard.types.ts`)。
料金プランカード。**DTO 非依存**(primitive props)で、feature 文言と CTA は呼び出し側が
props / Snippet で供給する。

- `priceAmount` が **null = 基本料金を持たない = 「無料」表示**(0 も防御的に同一表示)。
- `priceCaption`(例: 「基本料金」)は表示価格が総額と誤解されるのを防ぐための価格直上の説明。
- `isHighlighted` で `border-primary` の強調枠(現在のプラン等)。
- `headerBadges`(header 右上)/ `footerCta`(card 下部)は Snippet 専用スロット。

### ApiKeyTabNav

実装: `components/molecules/ApiKeyTabNav.svelte`。API キー管理ドメインのページ間
(API キー ⇔ 接続セッション ⇔ 導入ガイド)を **URL 遷移**(Inertia `Link`)で切替えるタブナビ。
同一ページ内 section 切替の `molecules/Tabs.svelte` とは責務が異なる。`tabs`(label + href +
active)はページ側が組み立てる(どのタブを出すか・URL は呼び出し側責務)。active タブに
`aria-current="page"` を付与する。

### RecentAuthModal

実装: `components/organisms/RecentAuthModal.svelte`(Modal の composition)。機微操作
(API キー発行/失効・アカウント削除・オーナー移譲)の前に出す**同一画面の再認証(step-up)
モーダル**。パスワード設定済みは再入力 → `POST /recent-auth/password`(成功は XHR 204)、
再 SSO 可能な provider は `reauthUrl` へフルリダイレクト、再認証手段なし(`canSatisfy=false`)は
回復導線(パスワード設定)を案内する。認可の最終ゲートは各操作の recent-auth middleware で、
本モーダルは UX 補助。

## Do's and Don'ts

**Do**

- 背景は常に neutral、浮いた要素は surface(逆に使わない)
- 余白を多めにとる。色は Primary / Tertiary / 状態色 1 種までを目安に
- 操作の可否は**押した後のフィードバック**で伝える(バリデーションエラー表示+フォーカス移動)

**Don't**

- グラデーション・ドロップシャドウ・scale 効果を使わない
- Danger と Tertiary を同一 action cluster・隣接 CTA 群で併置しない(赤系・強調系の意味が混ざる)
- **必須条件未充足を理由にボタンを disabled でブロックしない**。ボタンは活性のまま、
  押下時に何が足りないかをエラー表示する(例: 利用規約同意チェック。
  disabled はユーザーに「なぜ押せないか」を伝えられない)
- ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)

## 色の意味的割り当てルール

- **danger** = irreversible な喪失・破壊(削除・revoke・unassign・移譲・再開不可の中断)。
  確認 dialog があっても操作自体が不可逆ならボタン色は danger
- **warning** = 注意喚起 / 保留 / 可逆な要確認状態
- **tertiary** = 前向きな強調のみ(1 画面 1 箇所)
- **primary** = ブランド中核 / 主要 CTA / 選択中
- **neutral / text-secondary** = 中立・取消可能・UI-only の補助操作

action button(操作)と status badge(結果表示)は意味色を**独立に判断**する。


## 触れた atomic ディレクトリ構造

```
resources/js/components/atoms:
Alert.svelte
Avatar.svelte
Badge.svelte
Badge.types.ts
Button.svelte
Button.types.ts
Card.svelte
Checkbox.svelte
FormError.svelte
Input.svelte
Select.svelte
Spinner.svelte
TextLink.svelte
TextLink.types.ts
Textarea.svelte
Toggle.svelte
Toggle.types.ts
icons
input-state.ts

resources/js/components/molecules:
ApiKeyTabNav.svelte
Breadcrumb.svelte
CodeSnippet.svelte
DangerZone.svelte
Divider.svelte
EmptyState.svelte
FormField.svelte
NotificationBell.svelte
PageHeader.svelte
PageHeaderSection.svelte
Pagination.svelte
PasswordInput.svelte
PricingPlanCard.svelte
PricingPlanCard.types.ts
StatCard.svelte
Tabs.svelte

resources/js/components/organisms:
ConfirmDialog.svelte
ConfirmDialog.types.ts
Modal.svelte
Modal.types.ts
RecentAuthModal.svelte
ToastContainer.svelte

resources/js/components/templates:
AppLayout.svelte
AuthLayout.svelte
GuestLayout.svelte
PageContainer.svelte
PageContent.svelte
_helpers

resources/js/pages/Billing:
Index.svelte
Plans.svelte
PurchaseTickets.svelte
_helpers
ticketCount.ts
```
