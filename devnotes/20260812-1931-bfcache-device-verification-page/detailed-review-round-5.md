## 全体判定: CHANGES_REQUESTED

Round 4 の承認条件2点について、設計の中心部分は閉じています。ただし、旧規則の残存による矛盾と、`in-progress` の総合判定漏れがあります。実装者がどちらを正本とするか判断できないため、最終承認にはできません。

### 施策1: REQUEST_CHANGES

[Critical] `in-progress` が軸3で `fail` に落ちます。

現在の軸3規則では、`trial === valid-bfcache` かつ `guard === in-progress` の場合、期待値とも一致せず、正常終端でもないため、最後の「それ以外」に入り得ます。復元直後の正常な観測途中が一時的に `fail` 表示になります。

修正案として、軸3の先頭に途中状態を明示してください。

```text
trial === "incomplete"     → "undetermined"
trial !== "valid-bfcache"  → "undetermined"
guard === "in-progress"    → "undetermined"
guard === "not-observed"   → "undetermined"
guard === 期待値           → "pass"
guard が期待と異なる正常終端 → "expectation-mismatch"
guard === "failed-transition" → "fail"
```

`not-observed` は中止後の診断結果なので `fail` とする設計も可能ですが、その場合は意図を真理値表へ明記してください。少なくとも `in-progress` は必ず `undetermined` です。

[Warning] `AwayNavigationStartedEvent` のコメントに旧推論が残っています。

> これが記録されたのに `page-hide` が続かない場合、plain anchor が何かに intercept されたことを示す

現在の契約では、それだけでは `incomplete` です。

修正案:

> 離脱リンクが押された操作事実を同期記録する。`page-hide` の不在だけから離脱失敗を推論しない。

へ変更してください。

### 施策2: APPROVE

`LocalOnly`、`auth`、no-store、Inertia component 名を正負のコントロールで固定する計画は妥当です。

### 施策3: REQUEST_CHANGES

[Warning] 「操作ボタンの活性を `deriveTrialPhase()` の許可表に従わせる」は、禁止事項8と衝突する表現です。

phaseにより許可されない操作を disabled にすると、「必須条件未充足を理由にボタンを disabled にしない」に違反します。

修正案:

- ボタンは disabled にしない。
- 押下時にphaseを検査する。
- 許可されない場合はイベントを追記せず、理由を画面に表示する。
- 二重送信防止など、処理実行中の一時的disabledとは区別する。

離脱リンク節自体の手動判定への修正は完了しています。

### 施策4: APPROVE

責務分離、既存logout導線、失効後の証跡回収手順に問題ありません。

### 施策5: REQUEST_CHANGES

[Critical] 軸2真理値表に旧期待値が残り、新規規則と直接矛盾しています。

同じ表の中で次が併存しています。

- #5 遷移イベントなし → `not-observed`
- #10 guardイベントなし → `in-progress`
- #6 pendingのまま停止 → `failed-transition`
- #11 pendingのみ → `in-progress`

修正案:

- #5を削除するか、「`trial-aborted` 済みでguardイベントなし → `not-observed`」へ変更する。
- #6を削除するか、「停止はイベント列から判定しないため `in-progress`」へ変更する。
- `not-observed` は#15の中止済みケースだけに限定する。
- 軸3に `in-progress → undetermined` のテストを追加する。
- `not-observed` の総合判定も、決定した期待値でテストする。

これを直せば、逐次適用テストは正常prefixでlistenerを止めない契約を十分固定できます。

### 施策6: APPROVE

route gateとunload禁止範囲は妥当です。`AppLayout`への制約も経路Bの成立条件として正当です。

### 施策7: APPROVE

設備追加とT085完了の分離、manual confirmation、HTTPSおよびトンネル運用規律に問題ありません。

## Round 4 承認条件の確認

- 軸2への `in-progress` 導入: **中心設計は解決済み。ただし旧真理値表の消し漏れあり**
- 離脱リンク節の手動判定への修正: **解決済み**
- 正常prefixの逐次テスト: **追加済み**
- `in-progress` の総合判定: **未定義**

承認に必要なのは、軸2真理値表の矛盾解消、`in-progress → undetermined` の固定、禁止事項8に沿ったボタン挙動への文言修正です。