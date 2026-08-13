## 全体判定: CHANGES_REQUESTED

Round 2 の主要な欠陥は解消されています。軸1 window と観測終了の分離、復元後 `page-hide` の限定、`AppLayout` までの unload 禁止は妥当です。

残る問題は2点です。いずれも局所的ですが、イベント契約と誤判定に関わるため実装前に確定が必要です。

### 施策1: REQUEST_CHANGES

[Warning] 複数 `trialId` 混入時の契約が、3つの導出関数すべてには成立していません。

設計では「derive 関数は単一 trialId の配列だけを受け、複数 ID なら `inconsistent`」としていますが、`inconsistent` を返せるのは `TrialVerdict` だけです。`GuardVerdict` と `TrialPhase` には該当値がありません。

修正案:

- 共通の事前条件として `hasSingleTrialId(events)` を設ける。
- `deriveTrialVerdict()` は違反時に `inconsistent`。
- `deriveGuardVerdict()` は違反時に `failed-transition`。
- `deriveTrialPhase()` は違反時に自動追記を許可しない終端状態が必要。

最も明快なのは `TrialPhase` に `"invalid"` を追加することです。あるいは、3関数へ渡す前に単一IDを保証する専用パーサーを設け、違反時は導出関数を呼ばない契約でも構いません。現状の5状態だけでは、混入ログで listener を止める判断を表現できません。

[Warning] `away-navigation-failed` の次タスク判定は、full navigation の失敗を確定する根拠としてまだ強すぎます。

`setTimeout(..., 0)` 相当の次タスクが実行された時点で `visibilityState !== "hidden"` でも、低速端末やブラウザのナビゲーション処理順によっては、その後に正常な full navigation が進む可能性があります。逆に click interception 後に別処理がページを hidden にすれば見逃します。

修正案:

- このイベントを `away-navigation-check-failed` のような「診断上の疑い」に留め、`invalid-wrong-route` を確定させない。
- または、タイマーで失敗を確定せず、次タスクでまだ表示中なら利用者に「離脱が始まっていない」旨を表示し、利用者の明示操作で `away-navigation-failed` を記録する。
- 自動判定を維持するなら、`pagehide` 発生時に未確定の failed イベントを相殺できるイベントモデルが必要ですが、この用途には過剰です。

本設計の原則に合うのは、単なる `away-started` + hide 不在を `incomplete` とし、intercept の診断は画面表示に留める方法です。

### 施策2: APPROVE

`LocalOnly`、`auth`、no-store、Inertia component の正負コントロールが揃っています。propsなしのControllerも適切です。

### 施策3: REQUEST_CHANGES

[Warning] `deriveTrialPhase()` の5状態について、判定優先順位を明文化する必要があります。

特に次の状態が重なります。

- 復元後 hide 済み、`redirect-observed` 未登録
- `trial-aborted` と他の終端イベントが併存
- 軸1が `invalid-not-bfcache` または `inconsistent`
- 複数 trialId の混入
- `away-navigation-failed` 後

修正案として、優先順位を純粋関数の契約に固定してください。

```text
invalid input             → invalid
trial-aborted             → aborted
軸1未終端                 → collecting-axis1
軸1がvalid以外で終端      → complete
軸2観測中                 → collecting-axis2
hidden-then-left          → awaiting-manual-confirmation
軸2正常・異常終端         → complete
```

`collecting-axis1` のまま hide/show が来ない場合はlistenerを止めず、利用者の中止操作で閉じる設計で妥当です。タイムアウトを追加する必要はありません。

また、`awaiting-manual-confirmation` では自動イベントを追記せず、`redirect-observed` と `trial-aborted` だけを許可することを明記すると、fresh loadによる汚染防止が実装へ直接落とせます。

### 施策4: APPROVE

手順、責務、logout導線の扱いに問題ありません。

### 施策5: REQUEST_CHANGES

[Warning] storage節の最後が新しい契約と矛盾しています。

現在も次の記述が残っています。

> 複数 trialId が混入した場合、対象 trialId 以外のイベントを無視する

今回の決定は「混入時は `inconsistent`」なので、無視してはいけません。

修正案:

- 複数ID混入時に `deriveTrialVerdict()` が `inconsistent`
- `deriveGuardVerdict()` が定めた異常値
- `deriveTrialPhase()` が自動追記禁止状態

を返すテストへ置き換えてください。

併せて次を追加すれば状態機械の境界が固定されます。

- 軸1 incomplete → `collecting-axis1`
- valid window後、guard未終端 → `collecting-axis2`
- hidden-then-left → `awaiting-manual-confirmation`
- redirect追記後 → `complete`
- aborted → `aborted`
- awaiting中のfresh-loadイベントを自動追記しない

### 施策6: APPROVE

対象範囲と禁止理由は妥当です。productionコンポーネントに課す制約としても、経路Bの成立条件に直接結びついており過剰ではありません。

### 施策7: APPROVE

実機確認設備とT085完了を分離できています。文書間のmanual confirmation表現統一も適切です。

## 承認条件

次の3点を設計へ反映すれば、実装可能な水準です。

1. 複数 trialId に対する `GuardVerdict` / `TrialPhase` の異常値を定義する。
2. `deriveTrialPhase()` の判定優先順位と、各phaseで許可する追記イベントを固定する。
3. 次タスクの可視性だけで `invalid-wrong-route` を確定しない。