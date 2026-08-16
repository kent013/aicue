# Round 4: Round 3 の Warning 1 件 + Suggestion 1 件を反映

## W1: 「追加の LLM 呼び出しは 0 回」の矛盾を解消 (§期待効果 の費用 1・2 を差し替え)

```
  1. 新しい必須段・新しい実行経路は追加しない。必須段数は 3 段のまま、
     有界リトライの上限 (analysis_llm_max_retries = 2) も時間 budget も変えない。
     通常成功時の呼び出し回数は 3 回のまま。
     ただし validation のスキーマ違反により、従来なら起きなかった 2 段目の再試行が
     最大 2 回発生しうる。その分の provider 実費は増える (下記 3 と同じ話)。
  2. 利用者のチケット消費は COST_ANALYSIS = 1 のままとする。必須段数とリトライ上限を
     変更しておらず、validation 起因の実費増分は実装後に観測するため、
     現時点でチケット価格を変更する根拠が無い。
```

## S1: 観測条件を固定キーの構造化 context へ (§(A) 必須度 の観測条件を差し替え)

```
validation のスキーマ違反は、steps 側の違反と識別できる形で記録する。
メッセージ文字列に頼らず、リトライログに固定キーの構造化 context を載せる:
stage (= work_decomposition) / failure_category (= schema_violation) /
failure_path (= validation.works.2.title のような違反パス) / attempt。
可変の LLM 応答本文は含めない。
評価指標は「validation 起因の再試行数 / 最終失敗数 / 2 段目の出力 token 分布」の 3 つ。
閾値は今は置かない (分布を見てから判断する)。
```

他の節に変更はありません。再判定をお願いします。
