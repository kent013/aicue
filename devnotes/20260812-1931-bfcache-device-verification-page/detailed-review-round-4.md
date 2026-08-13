## 全体判定: CHANGES_REQUESTED

Round 3 の承認条件3点はすべて閉じています。ただし、状態機械を逐次イベントへ適用すると、正常な guard 遷移の途中で観測を終了する新たな問題が1点あります。

### 施策1: REQUEST_CHANGES

[Critical] 正常な guard 遷移の途中状態が `failed-transition` となり、観測が早期終了します。

イベントは逐次追記されるため、正常経路でも一時的に次のログになります。

```text
pending
pending → verifying
```

現在の軸2規則では、これらが「上記以外の遷移列」として `failed-transition` になります。さらに `deriveTrialPhase()` は軸2の異常終端を `complete` とするため、`null`、`retry`、復元後 `page-hide` が来る前に自動追記が停止します。

修正案:

- `GuardVerdict` に `"in-progress"` を追加する。
- `pending` および `pending → verifying` は `in-progress` とする。
- `deriveTrialPhase()` は `in-progress` を `collecting-axis2` とする。
- `failed-transition` は、正常遷移のprefixではない列に限定する。例: `verifying` から開始、`pending → null`、終端後の矛盾した遷移。
- `not-observed` も軸1 window確定直後は `collecting-axis2` とする。

少なくとも次の逐次テストが必要です。

- window確定直後、guardイベントなし → `collecting-axis2`
- `pending` → `in-progress` / `collecting-axis2`
- `pending → verifying` → `in-progress` / `collecting-axis2`
- `pending → verifying → null` → `authenticated-unhidden` / `complete`
- `pending → verifying → retry` → `retry-hidden` / `complete`
- `pending → verifying → 復元後hide` → `hidden-then-left` / `awaiting-manual-confirmation`

### 施策2: APPROVE

route、認証、LocalOnly、no-store、Inertia componentの検証計画に問題ありません。

### 施策3: REQUEST_CHANGES

[Warning] 離脱リンク節の説明が手動判定への変更を反映していません。

現在も次の趣旨が残っています。

> 押下時に `away-navigation-started` を同期記録するので、interceptされた場合は `invalid-wrong-route` で検出できる

`away-navigation-started` だけでは検出せず、利用者が `away-navigation-failed` を手動記録した場合に限って判定する設計へ変更済みです。

修正案: 「`away-navigation-started` は操作事実のみを記録し、離脱失敗は利用者の手動記録によってのみ `invalid-wrong-route` とする」へ統一してください。画面の操作一覧にも「離脱失敗を記録」を明記します。

### 施策4: APPROVE

手順と既存logout導線の利用は妥当です。

### 施策5: REQUEST_CHANGES

[Critical] `GuardVerdict` の途中状態を固定する逐次テストが必要です。

上記施策1の修正に合わせて、最終的なイベント列だけでなく、各イベント追記直後の verdict と phase を検証してください。これがないと、最終形の純粋関数テストが通ってもUIのlistenerが途中で停止します。

### 施策6: APPROVE

route gateとunload禁止範囲は妥当です。

### 施策7: APPROVE

設備追加とT085完了の区別、manual confirmationの表現、運用規律に問題ありません。

## Round 3 承認条件の確認

- 複数trialIdに対する3関数の異常値: **解決済み**
- `deriveTrialPhase()` の優先順位と追記許可: **解決済み**
- 可視性タイマーによる誤った自動判定: **解決済み**

残る承認条件は、軸2に `in-progress` 相当を設けて正常な遷移prefixでlistenerを止めないことと、離脱リンク節の古い説明を修正することです。