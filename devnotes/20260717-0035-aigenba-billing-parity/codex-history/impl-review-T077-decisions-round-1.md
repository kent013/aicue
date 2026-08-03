# 対応マトリクス: impl-review-T077 Round 1

対象: T077 (決済 parity P6 = signup grant 契機変更 F2 + LP 文言)
Codex 返答: `devnotes/20260717-0035-aigenba-billing-parity/impl-review-T077-round-1.md`
モデル: `gpt-5.3-codex` / `model_reasoning_effort=high` / one-shot (`--ephemeral --sandbox read-only`)

**Codex 総合判定: APPROVED**（[Critical] なし・[Warning] なし・[Suggestion] 1 件）

---

## [Critical] なし

Codex は「マージ前に必須修正となる欠陥は確認できなかった」と明記。対応不要。

## [Warning] なし

## [Suggestion] ローリングデプロイ窓の説明責務を強める回帰テストの追加

> `customer.subscription.created` と `invoice.paid(subscription_create)` を同一 org へ順序違いで
> 連続投入し、「signup は高々 1 回」を明示固定するテストを 1 本追加すると、将来の文脈共有が
> より堅くなる（現状実装でもロジック上は安全）。

- 判断: **対応する**
- 根拠:
  - 実運用では初回契約でこの 2 イベントが**必ず両方届く**（順序は Stripe 側の都合で前後する）。
    D29 で付与契機を `customer.subscription.created` 単独へ寄せた以上、「順序に関わらず付与は 1 回」は
    P6 の中核不変条件であり、単独イベントのテスト（設計テスト計画 #9 = `invoice.paid` 単独で付与されない）
    だけでは**組み合わせ順序**を固定できていなかった。
  - コストは test 1 本のみ（アプリコード変更なし・migration なし）。禁止事項・設計のいずれにも抵触しない。
  - 空振りしない設計にした: 順序 B（`invoice.paid` 先着）で
    (a) `invoice.paid` 適用後に `signup_tickets_granted_at` が **null のまま**であること、
    (b) 最終的な台帳キーが **`signup_grant:sub_order_b`**（= created 由来）であること
    を assert する。`invoice.paid` が付与を再開する後退が起きれば (a) が落ち、旧キー
    (`signup_grant:org:{id}`) での付与が復活すれば (b) が落ちる。
    （順序 A の「1 行のみ」assert は部分 UNIQUE index でも成立してしまうため、
    それ単独では回帰検出力を持たない。検出力の本体は (a)(b) 側にある。）
- 対応内容:
  - `tests/Feature/Billing/SignupGrantOnActivationTest.php` に
    `subscription.created と invoice.paid(subscription_create) が両方来ても signup grant は高々 1 回`
    を追加（順序 A / 順序 B を別 org で 1 test 内に固定）。
  - アプリコードは**変更していない**（Codex も「現状実装でもロジック上は安全」と判定）。

---

## 再検証（Suggestion 対応後）

| コマンド | 結果 |
|---|---|
| `composer test` | **pass** — `{"result":"passed","tests":2282,"passed":2280,"assertions":9169,"skipped":2}` / 0 failed（追加 1 test 分だけ増加: 2281 → 2282） |
| `composer phpstan` | **pass** — level 10 / 695 files / `[OK] No errors`（baseline・ignoreErrors・`@phpstan-ignore` の追加なし） |
| `vendor/bin/pint --test` | **pass** |
| `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` | **未再実行**（本ラウンドの変更は PHP テスト 1 ファイルのみで JS/TS に一切触れていないため。直前の実装レポート時点で全 green を実測済み: eslint 0 / tsc 0 / vitest 838 passed / vite build 成功） |

---

## レビュアー（Claude）自身の追加確認

Codex 返答を鵜呑みにせず、P6 固有の観点を独自に検証した結果を記録する。

1. **「marker だけ立って永久に付与されない org」を作る経路の残存**: なし。
   `grep -rn "claimSignupGrantMarker"` の結果、呼び出しは `PersonalPlanService::activateWithinTransaction`
   の 1 箇所のみ（同一 tx 内で `grantSignupGrant` と一体）。paid 側は
   `SubscriptionService::grantSignupInitialTickets` が単一 `DB::transaction` 内で
   claim → grant を閉じている。`CreateNewUser` からは marker 設定と grant が**一体で**撤去済み。
2. **移行前後の二重付与の窓**: 破れていない。
   - P1 以前登録の org: backfill migration `2026_07_17_000110` が `signup_grant:%` 行を持つ org の
     marker を埋め済み → `created` / `activate` のいずれでも claim できない。
   - P1〜P6 の間に登録した org: 旧 `CreateNewUser` が marker + grant を同一 tx で立てている → 同上。
   - P6 後に登録した org: marker も grant も無い状態から、free activate か paid created の**先着 1 回のみ**。
   - ローリングデプロイ窓（旧新ノード同居）: 旧ノードの `invoice.paid` 経路も marker 条件付き先取を
     経由する（P1 で前倒し済み）ため、新ノードの `created` 経路と真実源を共有し、先着のみが付与する。
3. **marker と部分 UNIQUE index の関係**: 鍵形式が `signup_grant:org:{id}` →
   `signup_grant:personal:{id}` / `signup_grant:{stripeSubId}` に変わっても、index 述語は
   `LIKE 'signup_grant:%'` で全形式を包含する。`SignupGrantUniqueIndexInvariantTest` は無改変で green
   （設計が「赤くなったら設計違反」と定義した条件を満たす）。さらに本 PR で
   `TicketLedgerService::grantSignupGrant` に `Assert::startsWith($key, 'signup_grant:')` が入り、
   述語を外れた鍵での付与が fail-closed で停止する（`TicketGrantTest` に回帰を追加済み）。
4. **旧 `invoice.paid` 経路の退役漏れ**: なし。`StripeWebhookProcessor` から `PersonalPlanService`
   の DI と signup grant ブロックが除去され、docblock も更新済み。月次付与
   （`GRANTING_BILLING_REASONS` / `monthly:{invoiceId}` / `monthly_ticket_grant<=0` guard）は無変更で、
   `WebhookIdempotencyTest` が「invoice.paid は月次 100 のみ・signup 0 件・marker null」を固定。
5. **設計からの逸脱**: 実質 2 点のみで、いずれも設計の意図の範囲内（詳細は実装レポート参照）。
   - `SubscriptionDeleted` の match arm も enum 引き回し形へ揃えた（設計は created/updated の 2 arm 分割のみ言及）。
     `terminated` は `$event === SubscriptionDeleted` から導出され挙動は同値。
   - `Assert::stringNotEmpty` / `Assert::startsWith` を `grantSignupGrant` に追加。
     設計 PHPStan 節は「P1 で導入済み」と書いていたが実際は未導入だったため、設計が記述する契約を
     本 PR で実体化した（設計の契約に対する**充足**であって逸脱ではない）。
