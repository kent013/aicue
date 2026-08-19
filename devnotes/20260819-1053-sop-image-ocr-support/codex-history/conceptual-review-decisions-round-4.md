# 対応マトリクス: conceptual-review Round 4

## [Critical] `match ($media)` は DTO の型に対する網羅的分岐として成立しない
- 判断: 対応する
- 根拠: 指摘のとおり。PHP の `match` は値の厳密比較であり、型パターンには一致しない。
  「型で網羅する `match`」という書き方自体が PHP の言語仕様上誤りだった。
- 対応内容: `match (true) { $media instanceof ImageAnalysisMediaData => ..., $media instanceof
  PdfAnalysisMediaData => ... }` という `instanceof` ベースの型判別に書き改めた。
  ここでの `match (true)` は真偽値をぼかす分岐ではなく、PHP に型に対する網羅的 match が
  無いことの代替としての型判別であることを明記した。`default` 節は置かず、union が
  2 型で閉じている限り PHPStan level 10 がこの `match` を型検査で確認できること、
  3 つ目の型を union へ足したときはこの箇所が型エラーで落ちることを明記した。

## [Warning] 「検証済み DTO だけが窓口へ届く」は DTO の生成子が公開なら成立しない
- 判断: 対応する
- 根拠: 指摘のとおり。union 型は媒体の種類を表せても、値が検証済みであることまでは
  型だけでは表せない。
- 対応内容: DTO を `final` にし、コンストラクタを `private` にして、検証済みの値だけを
  引数に取る named constructor (`fromValidated()` 相当) 経由でのみ生成できる設計にした。
  named constructor を呼べる箇所を専用のバリデーションサービス 1 つに限る運用とし、
  PHP の可視性だけでは呼び出し元を強制できない部分は、窓口 gate と同じ形で
  呼び出し箇所を deny-by-default で走査し件数を pin することで補うと明記した。

## [Warning] 「指標は 3 つとも `llm_call_logs` から出る」が (c) の定義と矛盾する
- 判断: 対応する
- 根拠: 指摘のとおり。(c) OCR 失敗率は `analysis_jobs` の終端状態との突合が必要と
  既に書いていたのに、直前の文が「3 つとも `llm_call_logs` から出る」と矛盾していた。
- 対応内容: 「集計元は指標ごとに異なる」と明記し、(a)(b) は `llm_call_logs` だけで出せるが
  (c) は `llm_call_logs` だけでは出せず `analysis_jobs` の終端状態との突合が要ることを
  明記した。

## [Warning] 非対応 provider の fail-fast とチケット予約の順序が未確定
- 判断: 対応する
- 根拠: 指摘のとおり。`startJob` 後に検知する場合の reserve/commit/release の扱いが
  明記されていなかった。
- 対応内容: この判定を既存のページ数・画素数・容量の上限判定と同じ「受理判定」の段階に置き、
  チケット予約 (`startJob`) より前に弾くことを明記した。既に予約された解析が実行段階で
  非対応 provider に当たった場合も、既存の `failJob` 経路 (予約を確実に release する) を
  必ず通すことを明記し、この 2 経路を Feature テストで両方固定する設計にした。

## [Warning] 画像内 prompt injection の手動評価が一度きりの承認では変更後の安全性を担保できない
- 判断: 対応する
- 根拠: 指摘のとおり。provider/model・system prompt・媒体マッピングの変更で
  実効性が変わりうる。
- 対応内容: OCR 用 provider/model・媒体 YAML (防御指示を含む)・Prism/Anthropic の
  媒体マッピングのいずれかに変更が入る場合は、production を継続する前に同じ評価セットで
  再評価・再承認することを rollout 条件に加えた。provider/model pin の更新と
  再評価・再承認の記録を同じ変更単位で揃えることも明記した。

## [Warning] 法務確認だけでは利用者がアップロード前に判断できない
- 判断: 対応する
- 対応内容: 法務文面の整備を待つ間も、アップロード画面に
  「画像・PDF は AI 解析のため外部の LLM provider に送信される。不要な個人情報や
  機密情報が写っていないか確認する」という法務確認済みの短い案内を表示することを
  設計に加えた。この文言自体も rollout dependency の確認対象に含めた。

## [Suggestion] 使命・スコープ・見積りと実 token 保証の区別
- 判断: 反映不要 (肯定的評価)
