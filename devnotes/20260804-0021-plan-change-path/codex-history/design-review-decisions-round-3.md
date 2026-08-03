# 対応マトリクス: design-review Round 3

判定: C は APPROVE、A/B/D/E が REQUEST_CHANGES (Warning 2 件 + Suggestion 1 件)。全件対応した。

## [Warning] grace period 契約で「同一プラン no-op」が段 2 にあるため、変更できない契約が成功扱いになる

- 判断: **対応する** (指摘のとおり。`plan_code=standard` のまま解約予約中の org が standard を
  選ぶと、state 判定に到達せず `AlreadyOnTargetPrice` で成功文言が出る。解約予約も解除されない)
- 対応内容: guard 順を
  **段 1 契約再読込 → 段 2 state 判定 → 段 3 schedule 拒否 → 段 4 同一プラン no-op →
  段 5 stale → 段 6 swap** に変更。
  「no-op を stale より先」の原則は維持しつつ、「state / schedule はさらに前」に置く理由
  (grace period 契約の `plan_code` は旧プランのまま残るため) を docblock に明記。
  テストに **「grace period かつ同一プラン → `InvalidArgumentException` (no-op にしない) /
  gateway 0 回」** を追加。`docs/architecture.md` の追記内容も同順序へ修正。

## [Warning] interface は「SDK 例外を外へ出さない」と宣言しているのに Service が `ApiErrorException` を catch している

- 判断: **対応する** (指摘のとおり契約と実装が矛盾していた。SDK 隔離を選ぶ)
- 対応内容:
  - **gateway 側で変換**する形に統一。`CashierStripeGateway::swapSubscriptionPrices()` を
    `try { ... } catch (ApiErrorException $e) { throw PlanChangeFailedException::stripeApiError(...); }`
    で包み、想定外構成も `PlanChangeFailedException::unexpectedShape(...)` にする。
  - `UnexpectedSubscriptionShapeException` クラスは**廃止**し、`PlanChangeFailedException` に
    名前付きコンストラクタ 2 種 (`stripeApiError` / `unexpectedShape`) を持たせた
    (クラス数を増やさずに診断情報を保持する)。
  - `getMessage()` は常に `USER_MESSAGE` 固定 (Controller が error flash に流すため内部情報を
    漏らさない)。診断は `public readonly string $reason` に持ち、Service は log にだけ落とす。
  - Service の catch は `PlanChangeFailedException` のみ (log して rethrow)。
    実装バグ (TypeError 等) は変換せず 500 のまま。
  - interface の docblock に `@throws PlanChangeFailedException` を明記。
  - gateway テストに「`ApiErrorException` → `PlanChangeFailedException`
    (`getMessage()` は固定文言 / `reason` は `stripe_api_error:` 始まり / `previous` に SDK 例外)」
    を追加。

## [Suggestion] 施策 D の「props 3 件追加」と TS の「2 フィールド」表記の不一致

- 判断: 対応する
- 対応内容: `resources/js/types/billing.ts` の記述を 3 フィールドへ統一。
