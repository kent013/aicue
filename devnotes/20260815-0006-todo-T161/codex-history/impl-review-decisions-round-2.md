# 対応マトリクス: impl-review Round 2

全件受け入れ（反論なし）。指摘は実バグだった。

## [Warning] `hidden-then-left` 判定が `PageHideEvent.guardState` を見ていない
- 判断: **対応する（実バグ）**
- 根拠: 指摘が正しい。`PageHideEvent.guardState` は
  「離脱時点で実際に秘匿されていたか」を記録するために持たせたフィールドなのに、
  判定で使っていなかった。結果として、guard 状態が
  `pending → verifying` でありさえすれば、**秘匿解除済み (`guardState === null`) の離脱でも
  `hidden-then-left` になり、`redirect-observed` を足せば PASS まで通ってしまう**。
  設計条件は「`pending → verifying → **秘匿維持のまま** page-hide`」だった。
- 対応内容:
  - `event.guardState === "verifying"` を要求するようにした
  - `guardState` がそれ以外の離脱は**証跡どうしの矛盾**として `failed-transition` に倒す
    （合格側にも `hidden-then-left` にも倒さない）
  - 実挙動との整合を確認: guard は `unauthenticated` のとき属性を `verifying` のまま
    `location.replace(LOGIN_PATH)` を呼ぶため、pagehide 時点のスナップショットは
    `verifying` になる。`authenticated` のときは属性を削除するので
    そもそもこの経路に入らない

## [Warning] テストヘルパー `hide()` が常に `guardState: null` を作る
- 判断: **対応する**
- 根拠: 指摘が正しい。**軸 2 の #2/#3/#9-c/#9-d は「秘匿維持」を再現できていなかった**。
  上のバグが検出されなかった直接の原因でもある。
- 対応内容:
  - `hide(persisted, trialId, guardState = null)` に第 3 引数を追加
  - 軸 2 のリダイレクト離脱ケース（#2 / #3 / #9-c / #9-d / 逐次適用）を
    `hide(true, TRIAL, "verifying")` に変更
  - 負のコントロールを 2 本追加:
    - #3-b `page-hide(guardState=null)` → `failed-transition`
    - #3-c それに `redirect-observed` を足しても `failed-transition`（合格にしない）
  - テストは 84 → 86 件

## APPROVE された項目（Round 2 時点）
- 軸 1 window より後だけを走査するため往路 `page-hide` を拾わない
- 終端 / `page-hide` で break するため終端後の guard イベントを無視できている
- `hasOrderedAwayFailure()` の sequence 比較に穴なし
- `Object.hasOwn()` / 保存失敗時の遷移停止 / storage キー限定 / Clipboard 存在確認
- controller / BfcacheTrial.svelte / route gate test（`auth.user` の正のコントロール追加を含む）
