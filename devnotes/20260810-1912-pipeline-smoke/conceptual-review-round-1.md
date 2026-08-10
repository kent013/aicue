全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**

[Suggestion] パイプライン smoke は North Star にかなり直結している。品質評価ではなく「SOP → シナリオ → 撮影テイク → 合成 mp4」が実際に回ることを検出する設計なので、「思考ゼロ・編集ゼロ」の前提を守る改善として妥当。

**2. 禁止事項違反**

[Warning] SOP ダミーテキストを「コマンドに埋め込む」とある点は、prompt 文字列直書き禁止との境界が曖昧。SOP は prompt template ではなく fixture 入力なので直ちに違反とは言い切れないが、LLM へ渡る一次入力である以上、コード直書きは将来の誤解を生みやすい。

修正提案: `tests/Fixtures` ではなく、bug-hunt 用 fixture として `devnotes/...` または専用の fixture ファイルに置き、コマンドはそれを読むだけにする。prompt template は引き続き `resources/prompts/*.yaml` の既存経路を使う、と明記する。

**3. 実現可能性**

[Warning] 「この実行分の費用」を `llm_call_logs` から取る設計がまだ弱い。`AnalysisPipeline` が metadata を付けない前提なら、`organization_id` / `subject_*` で smoke 実行を特定できない。時刻範囲だけだと、同じ bug-hunt DB で別の LLM 呼び出しが重なった場合に混入する。

修正提案: smoke 開始時に `llm_call_logs` の最大 id または開始時刻を snapshot し、終了後に `id > before_max_id` かつ `created_at` 範囲内で集計する、と設計に明記する。可能なら `--run-id` を metadata に載せる改善は別件に分離し、本件では「DB shard 内の差分ログ」を実行分の定義にする。

[Warning] worker 待ちの成功条件が抽象的。timeout が長いだけでは、失敗時にどの段で止まったかの診断性が不足する。

修正提案: analysis / render それぞれで polling 対象、成功状態、失敗状態、待機上限、失敗時に出す最低限の診断情報を定義する。例: job status、manual status、最新 exception/message、関連 queue job id、期待 cuts 数。

**4. 期待効果の妥当性**

[Suggestion] 実 LLM の DTO スキーマ破損、queue 配線、ffmpeg 実行、チケット reserve→commit を同時に検出できるという期待は合理的。ただし「実 S3 presigned PUT は検証しない」と明記しているので、検出範囲の線引きも妥当。

**5. リスク**

[Warning] 実 LLM 3 段呼び出し + 長時間 worker 待ち + チケット消費があるため、誤実行リスクがある。`ConfirmableTrait` と `--check` は良いが、bug-hunt 以外の DB で誤実行しない防壁が設計上まだ薄い。

修正提案: コマンド本体でも `DetectsBughuntDatabase` 相当の DB 名判定、`APP_ENV` / `BUGHUNT_*` env 判定、`--force` 時でも bug-hunt 外なら拒否、を必須条件として書く。薄い shell 導線だけに安全性を寄せない。

**6. スコープの適切さ**

[Suggestion] 品質評価、実 S3、ブラウザ操作、Filament、スケジュール実行を外しているのは適切。オーナー指示と「今必要なものだけ作る」に沿っている。

**7. 型安全性**

[Warning] コストレポート DTO の粒度は良いが、`metadata_missing` の扱いを DTO に含めるだけでは PHPStan level 10 的に十分とは言えない。nullable 金額、nullable JPY、group-by 種別の型が曖昧になりやすい。

修正提案: `group-by` は enum 化し、DTO は `Money` 的な独自過剰抽象ではなく、`numeric-string|null` または既存 money 方針に合わせた型で固定する。`total_cost_jpy` は「null 行数」と「非 null 合計」を別フィールドで返す設計にすると、null 混在を隠さずに済む。

この設計は方向性は承認に近いです。主な修正点は、「この smoke 実行分の LLM ログをどう厳密に切り出すか」と「bug-hunt 外で誤実行しない防壁」を概念設計に入れることです。