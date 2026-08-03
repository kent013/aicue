# 対応マトリクス: impl-review T080 (P8b) Round 1

対象レビュー: `devnotes/20260717-0035-aigenba-billing-parity/impl-review-T080-round-1.md`
(gpt-5.3-codex / reasoning=high / one-shot)

Critical 1 件・Warning 4 件・Suggestion 3 件。**Critical は対応済み**。以下は各指摘の判断と根拠。

---

## [Critical] `billing.plans` の課金ゲート allowlist 不変条件がテストで固定されていない

- 判断: **対応する**
- 根拠: 指摘のとおり。`/billing/plans` を `require-active-subscription` group の外に置くのは
  **構造的 allowlist**(middleware 内に route 名リストが無い)であり、route 定義が group 内へ動いても
  既定 fixture (`grandfatherFreePlan: true` = ActiveFreePlan) のテストは全て通過してしまう。
  未契約 (NoSubscription) org がプラン比較から契約できなくなる詰みを検知できない。
- 対応内容:
  - `tests/Feature/Billing/GateInversionF07RegressionTest.php` の
    「(d) 遮断先および課金系 route は gate group 外で再遮断されない」dataset に `billing.plans` を追加。
  - `tests/Feature/Billing/BillingPlansPageTest.php` に
    「未契約 (NoSubscription) org でも 200 で到達できる (課金ゲート allowlist)」を追加
    (`grandfatherFreePlan: false` / `billingState='no_subscription'` / `currentPlanCode=null` /
    プラン 3 件 / `canManage=true` を固定)。

## [Warning] `resolveResumablePurchase` が `isLivePending()` を使わず条件を再実装

- 判断: **対応する**
- 根拠: 設計 P8b(b) が「live pending 判定は既存 `TicketCheckoutSession::isLivePending()` を使う」と
  明記しており、SQL 側で `status` / `expires_at` を直書きしたのは**設計からの逸脱**。
  行判定と SQL 判定の二重定義は将来の乖離要因。
- 対応内容:
  - `TicketCheckoutSession` に `scopeLivePending(Builder, CarbonImmutable): void` を追加し、
    live pending の意味論を model の 2 メソッド (`isLivePending` / `scopeLivePending`) に閉じた。
    `checkout_url <> ''` は「replay 先 URL が実在する」= resume 固有の追加条件としてコメント付きで Service 側に残す
    (aigenba `TicketService.php:1395-1405` の replayable pending 条件と同じ切り分け)。
  - `TicketCheckoutService::resolveResumablePurchase()` を `->livePending($now)` 経由へ。
  - 等価性を `tests/Feature/Billing/TicketCheckoutSessionLivePendingTest.php` で固定
    (live / stale(期限切れ pending) / completed / expired の 4 行に対し scope 結果 ≡ 行判定結果、
    および `expires_at == now` の境界が live でないこと)。

## [Warning] `purchased` 成功バナーと `formState='resume'` が同時表示されうる

- 判断: **対応する**(設計未規定の相互作用のため、fail-safe 側へ倒す最小の逸脱)
- 根拠: 設計 P8b(b) は `formState` の写像と `purchased` バナーの**相互作用を規定していない**。
  実挙動としては、決済成功着地 (`/purchase-tickets?purchased=1&session_id=…`) の直後は
  webhook 未達の一瞬だけ当該 session が live pending のままであり、
  「購入を受け付けました」と「決済手続きが進行中です／決済を続ける」(= 支払い済み Checkout への直リンク)
  が同時に出る。押下先は既に支払い済みの session であり **誤誘導**になる。
  移植元 aigenba は完了 acknowledgment を `?session_id` 着地 feedback に委ねているが、
  それは **P9 所管**であり P8b 単独では成立しない。
- 対応内容: `TicketPurchaseController::show()` の resume 解決条件を
  `($canManage && ! $purchased && ! $request->boolean('fresh'))` へ変更 (理由をコメントで明記)。
  `$purchased` は `confirmsPurchaseReturn()` で **自 org の session_id と照合済み**の値であり、
  任意 query による抑止はできない (fail-closed)。二重課金防止は POST 側の
  attempt_token 冪等 + live pending dedup が担うため、resume 非表示で弱くなる保護は無い。
  `tests/Feature/Billing/TicketPurchaseResumeStateTest.php` に
  「決済成功着地 (?purchased=1 + 自 org の session_id) では resume を出さない」を追加。
- 設計との差分: **逸脱 1 件**として実装レポートの deviations に記載する。

## [Warning] 現在プラン解決が「公開プラン一覧のみ」依存で、契約中なのに `plan=null` 表示になりうる

- 判断: **見送る**(設計準拠のため意図的に変えない)
- 根拠:
  1. 設計 P8b(a) が「プラン台帳 → DTO の mapper は **`PricingService::listPublicPlans()`(`PricingPlanDto`)
     を共有する(新 DTO を発明しない)**」と明記。非公開プランも解決するには Billing 専用の
     別 mapper が要り、設計の共有規約に反する。
  2. 現時点で **到達不能**: 設計前提どおり `plans.is_active` は P1 で全行 `true` seed 済みで、
     「再公開フェーズは存在しない」= 非公開プランを契約中の org は存在しない。
  3. 原則 2 (今必要なものだけ作る) / 原則 4 に照らし、実在しない状態への分岐を先置きしない。
- 対応内容: 修正しない。将来 `is_active=false` 運用を導入する slice の DoD へ
  「非公開プラン契約中の表示解決」を含める必要がある旨を実装レポートで申し送る。

## [Warning] `Billing/Plans` の validation エラーが別プラン操作へ残留

- 判断: **反論する**(移植元 verbatim であり、意図的な同一挙動)
- 根拠: 設計 P8b(a) は「サーバ validation エラー(`page.props.errors.plan_code`)は dialog 内に
  `Alert` で描画し、**成功時のみ閉じる**(aigenba :121-127 verbatim)」と規定。
  移植元 `/tmp/aigenba/resources/js/pages/Billing/Plans.svelte:37-40` も
  `$derived.by(() => page.props.errors?.plan_code ?? …)` の**共有 errors 由来**であり、
  実装はそれと同一。設計のリスク表が明示する **原則 5(aigenba にある問題を AI-CUE 側で
  先回り修正しない)** に該当する。
  なお Inertia の validation error は次回 visit で置き換わるため、残留は
  「エラー後に POST せず別プランの dialog を開いた場合」に限られる (実害は誤表示のみ)。
- 対応内容: 変更しない。aigenba 側で修正されたら取り込む。

## [Suggestion] `月額 ¥—` 表示の文言改善

- 判断: **見送る**
- 根拠: 設計 P8b(d) が「`billingState === 'active_free_plan'` なら『月額 無料（チケット代のみ）』/
  それ以外は `¥{baseAmountJpy}`」と規定しており、`formatYen(null) === '—'` は
  aigenba `Plans.svelte:42-43` verbatim。非 free 状態で base price 行を持たないプランは
  現行台帳に存在しない (personal のみが `baseAmountJpy=null`)。文言の発明は行わない。

## [Suggestion] `ConfirmDialog` `banner` 追加の DESIGN.md 同期

- 判断: **対応する**
- 根拠: DESIGN.md は DS の canonical。types 側にのみ書くと共通部品の増えた口が見落とされる。
- 対応内容: `DESIGN.md` §Components > ConfirmDialog に
  「`banner?: Snippet` は message 直上の任意スロット。未指定なら描画されない(既存の出力は不変)」を追記。

## [Suggestion] bug-hunt ストーリー文言の陳腐化解消

- 判断: **対応する**
- 根拠: `screens.md` に `billing/plans` を足しただけでは story 側が D28 前の語彙 (月次付与枚数) のままで、
  探索観点が実装とズレる (誤検知/見落としの温床)。
- 対応内容: `.claude/skills/app-bug-hunt/stories/S5-billing.md` を更新
  (per-bucket 残高・quota 上限・「プラン比較」導線・`billing.plans` の disabled 不使用確認・
  portal の error flash 着地・D28 表記復活の監視を追加。screens 一覧にも `billing.plans` を追加)。

---

## 再検証 (対応後・全 green)

```
composer test          : pass (2393 tests / 2391 passed / 2 skipped / 9657 assertions)
composer phpstan       : pass (730/730, [OK] No errors。baseline / @phpstan-ignore 追加なし)
vendor/bin/pint --test : pass
pnpm lint              : pass
pnpm typecheck         : pass
pnpm test              : pass (97 files / 879 tests)
pnpm build             : pass
```
