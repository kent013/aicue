---
id: S5
title: 課金・チケット(残高/チャージ/消費)
surface: billing
lane: parallel_browser
priority: P1
applicability: applicable
depends_on: []
reseed_before: false
accounts: [owner]
setup: [Stripe は fake 実装で走らせる]
covers_screens: [billing.index, billing.plans, billing.tickets.show, pricing]
covers_operations: [billing.auto-recharge.setup, billing.auto-recharge.update, billing.checkout, billing.contact.update, billing.plan.change, billing.portal, billing.tickets.checkout]
covers_capabilities: [BILL-01, BILL-02, BILL-03, BILL-04, BILL-05, PUB-01]
---

# S5: 課金・チケット(残高/チャージ/消費)

## 目的
組織オーナー/管理者によるプラン選択 → checkout → サブスク状態確認、およびチケット残高の確認・チャージ・消費が二重課金/無反応/残高不整合なく進むか。料金表(pricing)の表示が実際の課金と矛盾しないか。

## 手順
1. `pricing`(料金表)を開く → 三層(個人バナー / 法人グリッド / 大規模利用バナー)とチケット価格表が表示され、CTA(申込/チャージ)導線が見える。未ログインでも閲覧でき、申込はログインへ誘導。**月次付与は廃止済み(D28)なので「月 N 枚のチケット付与」表記が復活していないか**も見る。
2. `billing.index` → 現在のプラン・per-bucket チケット残高(今すぐ使える/プラン付与残/購入済み残)・現行 quota 上限・「プラン比較」導線が表示(※容量Quota使用率は Dashboard 側。billing.index には容量ウィジェットは無い)。プラン一覧は `billing.plans` へ移設済み。
3. `billing.plans`(プラン比較) → 変更できないプランでも CTA は押せ、押下時に理由が出る(disabled で詰まない)。
4. `billing.checkout`(プラン申込/チケットチャージ) → Stripe fake の checkout へ → 戻ると残高/プランが更新され、二重送信しても二重課金にならない(冪等)。
5. `billing.portal` → Stripe カスタマーポータルへ遷移(無料パーソナル / 未契約では error flash で戻る = 500 にならない)。
6. チケットスポット購入 `billing.tickets.show`(`/organizations/{slug}/purchase-tickets`) → 枚数入力 → `billing.tickets.checkout`(Stripe fake)。**枚数に範囲外(>上限)を入れてエラー表示後、有効値に修正するとエラー/invalid が即座に消える**か(stale invalid 解消, T041)。合計金額が枚数に応じ再計算されるか。
7. **着地 feedback バナー(P9)**: Stripe fake の Checkout / ポータルから `billing.index` へ戻ると
   **one-shot バナー**(`billing-feedback-{purchase_received|purchase_processing|purchase_already_received|checkout_retry_required|portal_returned}`)が
   1 度だけ出る。`PurchaseFormState::Completed` 撤去後、**購入完了をユーザーに伝える唯一の経路**なので、
   ここが無言だと「押したのに何も起きない」finding (H3)。リロードで復活しないこと、
   他組織の session_id を query に差しても出ないこと(org スコープ + intent 検証で fail-closed)。
8. **請求先情報(P9)**: `billing.index` の請求先フォーム(`billing-contact-form`)で
   `billing.contact.update`(PATCH `/organizations/{slug}/billing/contact`)。メール/氏名を保存 → 再読込で反映
   (`billing-contact-email-readonly` / `billing-contact-name-readonly`)。不正メールでエラー →
   修正で即座にクリアされるか。`manageBilling` を持たない member では**読み取り専用**で、
   直 PATCH は 403 か。
9. **オートリチャージ(P8a、opt-in・既定 off)**: `billing.index` の `auto-recharge-card`
   (`?highlight=auto-recharge` で購入画面から誘導される着地 anchor)。
   - 既定は **off**。同意文言(上限額・補充枚数・停止方法・カードの取得手段)が提示され、
     **同意しないと有効化できない**か。閾値 ≧ 上限のような不正な組み合わせで
     `auto-recharge-range-error` が出て、直すと消えるか。
   - カード未登録なら `auto-recharge-no-pm` / 登録中は `auto-recharge-setup-pending` が出て、
     `billing.auto-recharge.setup` でカード登録 Checkout(mode=setup)へ。**cancel して戻っても
     詰まず CTA が残る**か。二重送信で台帳が増殖しないか(attempt_token 冪等)。
   - `billing.auto-recharge.update` で停止 → 即座に off になり、**支払い不健全で遮断中の組織でも
     この画面に到達して停止できる**か(課金ゲート allowlist)。
   - 価格改定・同意 version 改定後は**再同意を要求**して自動購入が止まる(fail-closed)。
     `auto-recharge-status` / `auto-recharge-max-amount` の表示が設定値と矛盾しないか。
10. チケット消費との整合(S3 と連動): analyze で 1、render で N 消費され、残高が減る。preview は非消費。ジョブ失敗時は予約が解放され残高が戻る(reserve→commit/release の 2 フェーズ)。

## 逸脱アイデア (--deviate 時)
- checkout を二重送信/戻る→再送 → 二重課金・二重チャージにならないか(冪等マシン/webhook)。
- 残高不足のまま analyze/render を強行 → 押下時エラーで詰まないか、残高がマイナスにならないか。
- チャージ直後にジョブ失敗 → 予約解放で残高が正しく戻るか。TTL 切れ予約の付け替えで二重消費しないか。
- 料金表の価格と実 checkout 金額・付与チケット数が一致するか(表示と課金の乖離)。
- 他組織の billing/checkout に自分のセッションでアクセス → 認可されるか。
- **サブスク契約 checkout の冪等 (P9)**: 同じ `attempt_token` で**別プラン**を送る → 422 で
  「押したプランと違う Checkout」に着地しないか。他組織/他ユーザーの token を送る → **404**
  (403 ではない = 存在オラクルを漏らさない)。戻る→再送で pending が増殖しないか。
- オートリチャージの同意上限を超える自動購入が起きないか (同意時に提示した上限額を
  超える単価/枚数に化けないか)。停止直後に補充が走らないか。
- `manageBilling` を持たない member で `billing.auto-recharge.*` / `billing.contact.update` を
  直 POST/PATCH → 403 か (UI 非表示だけで守っていないか)。
