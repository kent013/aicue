**ファイル別判定**

`app/Services/Manual/AnalysisPipeline.php`

指摘なし。`forceFill($attributes)->getAttributes()` は、モデル cast を通した DB 表現を条件付き UPDATE に渡す方法として妥当です。`result_json` は JSON 文字列へ正規化され、`where status=running` も維持されています。

`app/Services/Manual/RenderPipeline.php`

指摘なし。現在の更新対象は `step` の backing value と `int` のみで、cast 後も同じスカラー表現です。現時点で正規化処理を追加しない判断は妥当で、将来の列追加条件も docblock に明記されています。

`tests/Architecture/JobExclusionOrderingInvariantTest.php`

指摘なし。実行時 `queue.default` が `sync` に上書きされるテスト環境では、ソース上の production fallback を別途固定する方式に合理性があります。

この gate は以下を検出できます。

- fallback の `database` からの変更
- 対象ジョブの connection pin
- 比較先 `retry_after` の欠落または非正値
- TTL / `uniqueFor` の序列違反

文字列検査は書式変更でも赤くなりますが、不変条件テストとしては fail-closed 側の挙動であり問題ありません。

`app/Services/Billing/AutoRechargeService.php`

[Warning] `$exception->getMessage()` をそのまま `error` に入れる点について、反論には完全には同意できません。

問題は PII だけではなく、レビュー観点で明示された「外部 payload をログへ漏らさないこと」です。Stripe SDK の例外メッセージは外部サービスが生成する可変文字列であり、現在知られている例が invoice ID と status だけでも、将来の SDK・API 応答によって内容が増えないという契約はありません。

また、`JobOwnershipLostContextTest` が固定するのは抑止ログだけであり、cleanup ログの `error` 値には安全性の gate がありません。既存の `tryTerminateInvoice()` が同じ実装であることは一貫性の説明にはなりますが、新規経路の安全性を保証する根拠にはなりません。

運用情報を失わない具体案は、例えば次の固定情報へ正規化することです。

- `error_class`
- Stripe の安定した `error code`
- HTTP status
- Stripe request ID
- `invoice_id`
- `termination_operation`
- アプリ側で定義した失敗分類

これらが取得できない例外は `error_class` のみとし、例外そのものは既存の安全な例外報告経路へ渡します。7 キー schemaを維持する必要があるなら、`error` の値を固定カテゴリとコードの組み合わせにできます。キー集合を変える必要はありません。

最低限、現状維持するなら `error` に email、name、カード番号形式などが入らないことではなく、Stripe 例外メッセージそのものを許容するというセキュリティ判断を文書化し、cleanup ログ専用テストで固定する必要があります。

`docs/architecture.md`

[Warning] open invoice の種類 (b) について、所有者と逆引き方法は書かれていますが、検知方法が不足しています。

invoice 作成後、DB 保存前にプロセスが死亡した場合は `job_ownership_lost_cleanup` ログも残りません。「Stripe metadata から逆引きできる」は収束方法であって、孤児 invoice をどう発見するかの説明ではありません。例えば「課金運用担当が Stripe 上で `recharge_attempt_ulid` を持つ open/draft invoice を定期検索し、DB に対応する `stripe_invoice_id` がないものを抽出する」など、検知元と照合条件を明記する必要があります。

閉じない窓 4 件、preflight の配置規則、条件付き UPDATE、保証層、規約とテストの対応関係は実装と整合しています。

`AGENTS.md`

指摘なし。S7 は実在し、詳細設計の規約を十分に反映しています。

`tests/Feature/*` / `tests/Architecture/JobExecutionDedupInventoryTest.php`

指摘なし。対応表が指すテストは提示差分に実在し、Architecture gate と behavioral placement test の責務分担も一致しています。

UI、DTO / JsonResource、PHPStan widen、baseline、`response()->json()` に関する問題はありません。

全体判定: CHANGES_REQUESTED