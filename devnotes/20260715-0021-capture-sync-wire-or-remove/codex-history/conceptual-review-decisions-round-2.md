# 対応マトリクス: conceptual-review Round 2

## [Warning] 既存テストが「あれば/確定予定/補強」で担保未確認
- 判断: 対応する
- 根拠: 実在確認せず候補列挙のままでは禁止事項 #1(テストなし完了)回避の根拠にならない。
- 対応内容: 実ファイルを走査し「## 廃止前に検証・補強する代替経路の不変条件」表を (1)実在確認済み /
  (2)実在するが別観点 / (3)新規必要 に再分類。全不変条件が (1) で担保され (3) は無いことを実測(ファイル:行)で確定。
  特に Codex 指摘の「Show.svelte が reload を呼ぶ JS 振る舞い」は `tests/js/pages/CaptureShow.test.ts:173` が
  `router.reload({only:["manual"]})` を assert 済みであることを確認し明記。

## [Warning] 「無害な往復のみ」の断定に根拠不足(resume の再送コスト)
- 判断: 対応する(断定を撤回し、正確なコストとして明記)
- 根拠: 実コード確認の結果、crash-before-ack エッジでは register 冪等判定より前に upload-url(quota 予約)+
  S3 PUT(blob 再送)が走る。TakeRegistrationTest:230 が「別 path 重複 → 200 既存 + 予約 released + 重複オブジェクト削除」を担保。
  よって「無害」ではなく実コストを伴う。
- 対応内容: 失敗モード表の当該行を △ に修正し「### 再送コストの正直な評価」節を新設。受容する既知コスト
  (稀なエッジ限定・register の自己修復・孤児掃除 cron backstop で有界)と評価し、reconcile 配線は稀なエッジ用の
  micro-opt に新 endpoint+client 照合を足す過剰実装として退けることを明記。廃止判断は維持。

## [Warning] 参照監査の判定基準が既知参照と矛盾(trio 外=全て削除中止 は誤り)
- 判断: 対応する
- 根拠: Feature テスト/inventory/operations/doc は trio 外だが削除・更新対象。一律「中止」は不整合。
- 対応内容: 判定基準を 3 分類(予定済み削除更新対象からの参照=設計どおり / 未記載プロダクションコード=削除中止再設計 /
  未記載テスト文書ツール=評価して一覧追加)に再構成。URL 監査を route:list + code-review-graph + シンボル検索の三系統に。

## [Suggestion] cross-device / eviction / observability 分離は承認水準
- 判断: 反映済み(変更不要)
