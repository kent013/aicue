# 対応マトリクス: design-review Round 3（最終）

Codex 全体判定: **APPROVED**（施策 1〜4 すべて APPROVE / Critical 0 / Warning 0）。
新規指摘なし。設計変更なし。

## 合議の経緯

| フェーズ | Round | 判定 | 主な論点 |
|---|---|---|---|
| 概念設計 | 1 | APPROVED | 「再開する」の過剰表現（対応）/ 参照漏れ（詳細設計へ）/ `expired_checkout` 多義性（スコープ外を明記） |
| 詳細設計 | 1 | CHANGES_REQUESTED | fixture の空振り / 旧 prop 非残存の未固定 / `value-of<>` / 検証コマンド不足 / 保証しないものの書き漏れ。**PHPStan closure 指摘は反論** |
| 詳細設計 | 2 | CHANGES_REQUESTED | enum import / fixture 具体化 / mutation #8 の定義 / 断定の緩和 / Browser 統合。**反論は Codex が撤回** |
| 詳細設計 | 3 | **APPROVED** | 残 Critical / Warning なし |

## Phase 2-5 最終確認（使命・禁止事項チェック）

- **使命への寄与**: F-2-01 は S1 登録ファネルを通る全新規ユーザーの初回着地体験を直す。
  「思考ゼロで使える」入口で、身に覚えのない支払い失敗ではなく実行可能な次の一手
  （プランを選ぶ）を提示する。F-2-02 は現場 PWA の主要ログイン手段である passkey の
  失敗時に「待てば直る」を伝える。**どちらもエラーを隠すのではなく行き先を示す変更**である。
- **禁止事項の確認**:
  - 1（テストなしの完了報告）: Feature / Architecture / vitest / Browser の 4 レーンに割付済み。
    新しい不変条件（enum ⇔ TS union）は Architecture gate に登録する計画がある。
  - 2（PHPStan widen / baseline）: なし。`value-of<OnboardingBillingState>` で**狭めて**いる。
  - 3（dev DB 破壊操作）: なし（migration も無い）。
  - 4（`response()->json()` 直書き）: なし（Inertia props のみ）。
  - 5 / 6（Prism・prompt）: 該当なし。
  - 7（`redirect()->intended()`）: 該当なし。
  - 8（必須条件未充足での disabled ボタン）: **むしろ遵守を強化**している。CTA を権限で
    出し分けず常に押せる形にし、行き先はサーバ（`OnboardingController::show`）が決める。
    既存の「disabled 属性が 1 つも存在しない」vitest も維持する。
  - 9（Artifact）: 使用していない。成果物はすべて `devnotes/` 配下のファイル。
- **前提条件の遵守**: 課金ゲートの判定ロジック（`BillingAccess` / `RequireActiveSubscription` /
  `OnboardingBillingState` の case 集合と `grantsAccess()`）は 1 行も変えない。
  流量制限の閾値・limiter・route 付与も変えない。
- **コーディングルール**: PHPStan level 10 / Pest + `RefreshDatabase` グローバル + `--parallel` /
  テストデータは Factory / DTO パターン / DESIGN.md token（hex 直書き増やさない）/
  Atomic Design の層を跨がない — すべて詳細設計に反映済み。
