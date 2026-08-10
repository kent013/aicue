**施策 1: APPROVE**

[Suggestion] `LlmCallContextData` は妥当です。列追加なしで既存 listener の 4 キー契約に乗っており、過剰ではありません。  
ただし docs の表現は「帰属メタデータの配線はテストレーンでは検証できない」では広すぎます。reflection で `metadata_context` までは検証できます。正しくは「イベント/listener 経由で `llm_call_logs` に記録されることは fake 経路では検証できない」です。

**施策 2: REQUEST_CHANGES**

[Critical] 0 件時の TOTAL 仕様と集計クエリ仕様が矛盾しています。  
`SUM(input_tokens)` / `SUM(output_tokens)` / `SUM(CASE ...)` は対象 0 件だと SQL 上 `NULL` になり得ます。一方 DTO は `int` を要求し、テスト計画は「0 件時 TOTAL が全部 0 / null」としているため、そのままだと TypeError / Assert 失敗 / 暗黙キャストのいずれかになります。

直し方: 加算整数列はクエリ側で明示的に `COALESCE(..., 0)` してください。金額列だけは未解決表現として `SUM(total_cost_*)` の `NULL` を維持する、という切り分けがよいです。

[Warning] 「全軸が index に乗る」は言い過ぎです。列は存在しますが、期間条件 `created_at` と `prompt_template` / `subject_type, subject_id` の組み合わせで常に効率よく index が効くとは言えません。今回の規模なら index 追加は不要ですが、設計文は「既存列だけで成立する。SQL 関数 GROUP BY は使わない」程度に弱めるべきです。

**施策 3: APPROVE**

薄い CLI 入口として妥当です。施策 2 の 0 件時集計仕様を直せば、この設計で成立します。スケジュール登録しない判断も過剰化を避けていて妥当です。

**施策 4: APPROVE**

`BughuntDatabaseGuard` の app 側昇格は小さく、smoke の fail-secure 条件から使う具体的理由があります。cap より広い regex を検出用に使う説明も筋が通っています。

**施策 5: APPROVE**

fixture は必要最小限です。`SopTextExtractor` を通す behavioral test にして比率ロジックを再実装しない判断も妥当です。

**施策 6: REQUEST_CHANGES**

[Critical] `ConfirmableTrait::confirmToProceed()` はデフォルトでは production 環境でしか確認しません。  
設計は「毎回確認する」としている一方、実行環境は `bughunt.local` なので、単に `confirmToProceed()` を呼ぶだけでは確認が出ず、そのまま実行されます。

直し方: `confirmToProceed($warning, true)` または常に true を返す callback を渡し、`--force` のときだけ skip される形にしてください。確認拒否時に `self::INVALID` を返すこともテストで固定してください。

[Warning] `llm-evidence` の分類入力が粗く、穴があります。  
3 template のうち 1〜2 template だけ成功行があり、failure 行がなく、帰属欠落もない場合、段は失敗しているのに #8 にも #9 にも入らず `Unknown` になります。分析成功後に一部 template の記録だけ欠けるのは provider より記録/配線側の問題に見えるため、`Unknown` でよいなら明記、そうでないなら `hasRequiredLlmEvidence` のような入力を足すか、llm-evidence 固有で「必要 template 不足」を `Wiring` に分類してください。

[Suggestion] `$baselineId` をいつ取るかを明記してください。`id > baselineId` が smoke の証跡境界なので、fixture 作成前、少なくとも analysis trigger 前に取る必要があります。

**施策 7: APPROVE**

fake storage 直接書き込み + allowlist は、既存の fake controller と同じ境界で説明できています。新しい外部 HTTP seam を作らない判断も妥当です。

**施策 8: APPROVE**

orchestrator 限定、子 wrapper 非公開、転送 option allowlist は妥当です。`--run-id` / `--shard` を artisan に渡さない判断も維持で問題ありません。

**施策 9: REQUEST_CHANGES**

[Warning] docs の「帰属メタデータの配線はテストレーンでは検証できない」は施策 1 と矛盾します。  
テストレーンで検証できないのは fake 実行時のイベント発火と DB 記録であって、factory が `metadata_context` を持つことは検証できます。保証しないものの文言を狭めてください。

**施策 10: REQUEST_CHANGES**

[Critical] `ConfirmableTrait` の「毎回確認」挙動を固定するテストが必要です。現設計のままだと `bughunt.local` では確認が出ないため、ケース 9 が偽の前提になります。

[Warning] `SmokeFailureClassifierTest` に、llm-evidence の「必要 template が一部だけ欠ける」ケースを追加してください。上記の分類方針を `Wiring` / `Unknown` のどちらにするか決めて固定する必要があります。

**全体判定: CHANGES_REQUESTED**

v2 の大きな方向性、特に「記録側を先に直す」「集計は DB の GROUP BY に閉じる」「day 軸や duration を削る」は妥当です。追加機構を増やす必要はありません。  
ただし、施策 2 の 0 件集計、施策 6 の確認プロンプト、llm-evidence 分類の穴は実装時にそのまま壊れるため修正が必要です。