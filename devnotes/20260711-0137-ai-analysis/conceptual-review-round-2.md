全体判定: CHANGES_REQUESTED

前回指摘の大半は適切に解消されています。ただし、以下が残ります。

- [Critical] stale recovery と正常 finalize の競合が未解決です。`materialize` 完了後、`finalize` 前に cron が `failJob()` を実行すると、予約を release した後に pipeline が「非 Reserved でも続行」し、`succeeded` へ上書きして無課金成功になる可能性があります。修正案: terminal transaction で job 行をロックし、`status=running` と予約 `Reserved` を必須条件として、materialize・commit・job succeeded を原子的に確定してください。cron が先勝ちした場合、pipeline は materialize・commit・succeeded の全てを行わず終了させます。「非 Reserved は report + 続行」は禁止し、整合性例外として扱うべきです。

- [Warning] `updated_at` は step 間でしか更新されないため、厳密には heartbeat ではありません。現状は timeout 600秒に依存して安全性を説明していますが、timeout が確実にプロセスを停止することまで保証できない実行環境では誤回収があり得ます。修正案: 「step 更新を stale 判定時刻として利用」と表現を改め、上記 terminal transaction の所有権確認を必須にしてください。専用 heartbeat カラムの追加までは不要です。

- [Warning] SOP 差し替えと analyze の競合条件が不明です。両方が `{draft, ready}` を確認しても、差し替え側が VideoManual 行をロックしなければ、analyze が旧 document を選択した直後に新 document が追加される可能性があります。修正案: 差し替えも VideoManual 行を `lockForUpdate()` した同一 transaction 内で状態確認と SourceDocument 作成を行い、latest の選択順を `created_at, id` 等で決定的にしてください。

- [Warning] `100,000文字` は LLM のコンテキスト上限を保証しません。特に日本語では文字数と token 数が一致せず、入力、プロンプト、最大16,000出力の合計がモデル上限を超える可能性があります。修正案: 採用モデルの context window に対し、固定プロンプトと出力予約分を差し引いた入力 token budget を定義してください。tokenizer を導入しない場合も、UTF-8 byte 数等による十分保守的な上限根拠を固定テストにします。

- [Warning] `ready→analyzing` を本設計だけで追加すると、確定仕様の §10.2 と不一致が残ります。修正案: 本設計の承認と同時に `/workspace/doc/10_実装仕様.md` §10.2も更新し、許可遷移と失敗復帰を状態遷移テストへ登録してください。

上記以外の前回 Critical / Warning、特に監査性、JSON型境界、メソッド単位inventory、402応答、段階実装については解消されたと判断します。