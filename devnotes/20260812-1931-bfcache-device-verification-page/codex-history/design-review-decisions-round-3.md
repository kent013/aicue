# 対応マトリクス: design-review Round 3

全件受け入れ（反論なし）。

## [Warning] 複数 `trialId` 混入時の契約が 3 関数すべてには成立していない（施策1）
- 判断: **対応する**
- 根拠: 指摘のとおり。`inconsistent` を返せるのは `TrialVerdict` だけで、
  `GuardVerdict` と `TrialPhase` には該当値が無い。
  現状の 5 状態では**混入ログで listener を止める判断を表現できない**。
- 対応内容:
  - 共通の事前条件 `hasSingleTrialId(events): boolean` を設けた
  - `deriveTrialVerdict()` → 違反時 `inconsistent`
  - `deriveGuardVerdict()` → 違反時 `failed-transition`
  - `deriveTrialPhase()` → **`"invalid"` を追加**（自動追記を許可しない終端状態）

## [Warning] `away-navigation-failed` の次タスク判定は根拠として強すぎる（施策1）
- 判断: **対応する（自動判定を撤回し、手動記録に変更）**
- 根拠: 指摘が正しく、しかも本設計が一貫して排してきた
  「観測できないことを推論する」に自分で戻っていた。具体的には
  - 低速端末やナビゲーション処理順によっては、次タスク時点で
    `visibilityState !== "hidden"` でもその後に正常な full navigation が進みうる（**誤検出**）
  - 逆に intercept 後に別処理がページを hidden にすれば**見逃す**
- 対応内容:
  - **タイマーによる自動判定を削除**した
  - `away-started` があり `page-hide` が無いだけの状態は **`incomplete`**（安全側）
  - 画面には「離脱が始まっていないようです」という**診断表示に留める**
  - `AwayNavigationFailedEvent` は **`observationMethod: "manual"` を持つ手動イベント**へ変更し、
    利用者の明示操作でのみ記録する（`redirect-observed` と同じ扱いに揃う）
  - `invalid-wrong-route` はこの手動イベントからのみ導出する

## [Warning] `deriveTrialPhase()` の判定優先順位が未定義（施策3）
- 判断: **対応する**
- 根拠: 指摘の 5 ケースは実際に重なる。優先順位が無いと実装者ごとに解釈が割れる。
- 対応内容: 純粋関数の契約として優先順位を固定した（Codex 提示の表を採用）。

  ```text
  invalid input          → invalid
  trial-aborted          → aborted
  軸1未終端              → collecting-axis1
  軸1がvalid以外で終端   → complete
  軸2観測中              → collecting-axis2
  hidden-then-left       → awaiting-manual-confirmation
  軸2正常・異常終端      → complete
  ```

  あわせて **phase ごとに許可する追記イベント**を表で固定した。
  とくに `awaiting-manual-confirmation` では自動イベントを追記せず
  `redirect-observed` / `trial-aborted` / `away-navigation-failed`(手動) のみ許可する
  （fresh load による汚染防止が実装へ直接落ちる）。
  `collecting-axis1` のまま hide/show が来ない場合は listener を止めず、
  利用者の中止操作で閉じる（**タイムアウトは追加しない**）。

## [Warning] 施策5 の storage 節が新しい契約と矛盾している
- 判断: **対応する**
- 根拠: 「複数 trialId が混入した場合、対象 trialId 以外のイベントを無視する」が残っていた。
  Round 2 で「混入時は `inconsistent`」に決めたので**無視してはいけない**。私の消し漏れ。
- 対応内容: 当該行を削除し、3 関数それぞれの異常値を確認するテストに置き換えた。
  あわせて状態機械の境界テスト 6 本を追加した。

## APPROVE された施策
施策 2 / 施策 4 / 施策 6 / 施策 7
