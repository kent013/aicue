CHANGES_REQUESTED

[Critical] テストだけ追加する方針自体は概ね妥当ですが、「実データが 1 件消えた」観測に対して **テスト追加だけで決着**は弱いです。アプリコードに二重防御を足さない判断は正当化できますが、最低限、即時削除の実行直前に「凍結中ユーザーではない」ことを監査可能にする手当てが必要です。原因未特定なら、次に起きたときの証拠を残す設計が要ります。

[Warning] 固定する契約 3 つは方向性として正しいです。ただし漏れがあります。特に見るべきです。

- **recent-auth なし + JSON DELETE**: 凍結が recent-auth より先に効くのか、recent-auth challenge が先に返るのかを固定する。
- **recent-auth あり + HTML DELETE**: 既存があるならよいが、JSON 側と対になる契約として明示する。
- **凍結中ユーザーが DELETE を試みても controller 側の削除処理に到達しない**こと。DB 件数だけでなく対象 user の `exists` / soft-delete 有無 / 関連状態も確認したい。
- **allowlist route から即時削除が漏れていない**こと。`AccountDeletionFreezeAllowance` に `settings.account.destroy` が入らない deny-by-default 風のテストがあると強いです。
- **2FA 必須組織 + 凍結中 + recent-auth 済/未済**。削除系が step-up/2FA/recent-auth と絡むなら、凍結が迂回されないことを 1 本だけでも見る価値があります。

[Warning] 「再現しないものを放置しない」ための手当てとして、Feature テスト追加は必要ですが十分ではありません。今回の観測は「単一リクエストでは再現しないが実データが消えた」なので、並行・タブ遷移・XHR の競合を完全に捨てるなら、その代わりに **削除前状態の記録** が必要です。例: account deletion 実行ログに user id、pending deletion 状態、route name、expectsJson、recent-auth 判定、actor/org を残す。PII は平文にしない前提です。

[Suggestion] アプリコードを変えない理由は、「現実装が正しいから」ではなく「現時点で壊れ方を特定できず、重複防御は設計を濁すため。まず契約テストと観測を増やす」と書く方が defensible です。現状文の「防御は 1 mm も増えない」は、実データ消失の文脈では強すぎます。

[Suggestion] この TODO の成功条件は次のように置くと明確です。

- `settings.account.destroy` の HTML/JSON 両方で凍結遮断が固定される。
- recent-auth/step-up の有無に関係なく、凍結中の即時削除が削除処理に到達しない。
- 削除が実行された場合、直前の凍結状態を後から検証できる監査情報が残る。

結論として、「アプリコードに防御を足さず、テストで XHR DELETE を固定する」は妥当寄りです。ただし、実データ消失 1 件を扱うには **監査・削除前状態記録なしでは不足**です。そこをスコープに入れれば承認可能です。