# 対応マトリクス: v2 全面差し戻し（Round 11 の APPROVED 後、ユーザー指摘により方針転換）

Round 11 で一度 APPROVED を得たが、その後**ユーザー指摘により設計方針そのものを差し戻した**。

## ユーザー指摘
1. 「**基本的に全部揃える方向だよ。揃えてから問題を調整してもらいたい**」
2. 「**値段を憶測に基づいていじるよりもロジックを合わせて欲しい**」（U1 の「合わない可能性」は実測なしの憶測だった）
3. 「**指摘されたバグが aigenba に存在しているバグなのかどうかをちゃんと検証してください**」

## 検証結果（`bug-origin-verification.md`）
**Codex が指摘した 7 バグのうち 5 件は「私が aigenba から逸脱して発明した独自実装」が原因**と実コードで確定した。
aigenba 通りに移植していれば発生しなかった。合議 11 ラウンドの大半は**私が作った問題を私が塞ぐ作業**だった。

| 私が「バグ」と呼んだもの | aigenba に存在するか | 真因 |
|---|---|---|
| `NoPlan` variant 欠落 | **無い**（`OnboardingBillingState` が `ActiveFreePlan` / `NoSubscription` を分離済み） | 私の D18 |
| paid 判定の同期ラグ / 不健全素通し | **無い**（`state()` は `plan_code` を見ず `subscription` + `deriveEntitlement` のみ） | 私の D26 |
| debt の二重回収 | **無い**（debt 概念が無い。per-source clamp） | 私の D19/D24/D27 |
| `reserve()` が debt 無視 | **無い**（同上の派生） | 同上 |
| Personal 非公開のまま反転 | **無い**（`is_active=true` で公開） | 私の D10 |

## v2 の変更点

- **原則を「aigenba verbatim」に固定**。逸脱してよいのは **AGENTS.md の禁止事項・不変条件に抵触する場合のみ**
  （私の設計判断は根拠にしない）。値は aigenba の既定値をそのまま。
- **撤回**: D18/D23（`EffectivePlan` 4 variant）→ `OnboardingBillingState`(5 状態) + `BillingAccess::state()` verbatim /
  D26（`plan_code` 依存）→ `subscription` + `deriveEntitlement` / D19/D24/D27（debt）→ per-source clamp verbatim /
  D10（`is_active=false` seed）→ `true` seed / D1・D2（PlanCode 3 case 縮小）→ **verbatim 5 case** /
  U1・U2・U3（値の再検討）→ aigenba 既定値。
- **D25 の変更**: `BillingCheckoutSession` テーブルは **P2 へ前倒し**（`state()` の Pending/ExpiredCheckout が読むため、
  状態モデルの一部）。P9 は冪等配線・feedback・請求先のみ。
- **D28 新規**: **月次チケット付与を廃止**（`PlanSeeder` 全 tier `monthly_ticket_grant=0`）。
  aigenba は施策8/v3 で廃止済み（都度購入 / オートリチャージのみ）で、**AI-CUE だけ月次が生きていると
  per-source clamp の移植で「aigenba では死んでいる債務の逃げ道」が生きる**ため、**clamp 移植と不可分**。
  コード経路は不変（既存 guard `monthly_ticket_grant <= 0` が aigenba の `if ($count < 1) return;` と同形になる）。

## 維持する逸脱（AGENTS.md 由来のみ。私の設計判断ではない）
- amount ベース reserve（AI-CUE は解析 1 枚 / レンダ 3 枚の**可変コスト**。ドメイン要件）
- reserve→commit/release の 2 フェーズ（不変条件 #7。**aigenba も同じ**なので実は逸脱ですらない）
- disabled ボタンを移植しない（禁止事項 #8）
- 請求先 PII は email + name とも CipherSweet（不変条件 #6。aigenba は平文 string）

## aigenba 側で CLOSED になった件
私が aigenba へ報告した「返金債務が回収されない経路」は**先方の実行検証で不成立**（私の再現手順が消費優先
monthly→purchased と矛盾。`ACTUAL = 0`）。「悪用の敷居が低い」も誇張だった。先方は当面挙動を変更せず、
**verbatim 移植で問題なし・往復は発生しない**と回答。`TicketRefundClawbackTest:147` の `-2` → `0` も確認済み。
