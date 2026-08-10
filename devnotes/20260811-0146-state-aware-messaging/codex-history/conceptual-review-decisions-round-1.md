# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 3 / Suggestion 多数)。
Warning 3 件はいずれも詳細設計へ持ち越す形で対応する。

## [Warning] `pending_checkout` の CTA「手続きを再開する」は過剰表現の疑い

- 判断: **対応する (文言を修正)**
- 根拠: 指摘を受けて実装を読み直した。
  - `OnboardingController::show()` は既存 Stripe Checkout session を再開しない。
    プラン選択画面を描画するだけである。
  - 再度同じ plan で POST すると `SubscriptionService::startCheckout()` 段 3 の
    live pending dedup に当たり `CheckoutSessionDto{url: null}` が返る。
    controller は `back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。')`
    (BillingController:301-305) を返す = **詰みではないが「再開」もしていない**。
  - よって「再開する」は嘘になる。Codex の指摘が正しい。
- 対応内容: `pending_checkout` の copy を
  本文「お支払いのお手続きが完了していません。ご利用を開始するには、プラン選択からお手続きください。」/
  CTA「プラン選択へ」(`/onboarding/checkout`) に変更した。
  「再開」という語を使わない。

## [Warning] `has_billing_access` 削除の参照漏れリスク

- 判断: **対応する (詳細設計で全参照を施策に明示)**
- 根拠: 思考原則 3 (後方互換の並走を残さない) に従い削除する以上、参照の網羅は必須。
  実際に `rg` で洗い出した参照は 8 箇所 (アプリ 4 + テスト 4) で、
  漏れは `pnpm typecheck` (TS) と PHPStan level 10 (PHP shape) が機械検出する。
- 対応内容: 詳細設計の「波及変更」に全 8 箇所を列挙し、検証コマンドを明記する。

## [Warning] `expired_checkout` の多義性が copy map で固定化される

- 判断: **見送る (今回のスコープ外として明記する)**
- 根拠: `expired_checkout` を分解するには `BillingAccess::state()` の分類 = 課金ゲートの
  判定ロジックに触れる必要があり、本設計の前提条件 (判定ロジックを変えない) に反する。
  かつ enum に case を足すと `grantsAccess()` の母集団と gate 側の 2 分岐
  (`RequireActiveSubscription`) の意味が変わる = 表示の修正の範囲を超える。
  Codex 自身も「現行文言維持に留め、case 追加をこの PR に混ぜないこと」を修正提案としている。
- 対応内容: 概念設計「スコープ外」に既記載。詳細設計の「保証しないもの」にも
  多義性が残ることを明記する (誇張しない)。

## [Suggestion] 群

- 共通化しない判断・429 のみ分類・Retry-After 秒数を出さない判断・
  `satisfies Record<BillingStateValue, …>` による網羅性確保・enum⇔TS union gate —
  いずれも Codex が妥当と評価。**変更なし**。
