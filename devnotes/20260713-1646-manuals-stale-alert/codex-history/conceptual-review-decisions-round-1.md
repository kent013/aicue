# 対応マトリクス: conceptual-review Round 1

## [Warning] (観点3 実現可能性) `job.id` 単体監視だと job 内容変化 (id 同一で status 変化) を取りこぼす
- 判断: 対応する
- 根拠: 妥当。同一 job id が queued→running→failed と遷移するため、id 単体では新スナップショット検知に穴がある。
- 対応内容: 再同期トリガーを **署名比較**に変更。署名 = `hasDocument` + `job?.id` + `job?.status` + `manualStatus`。
  これで job の状態変化も検知する。設計文の検知条件を明文化。

## [Warning] (観点4 期待効果) props 変化時に overlay を全消去すると未解決の別種エラー (402/401/419) まで隠す
- 判断: 対応する (全消去方針を維持しつつ UX 原則を明文化する側で対応)
- 根拠: Codex は「原因別条件分け」か「新スナップショット到来で overlay 常時破棄という UX 原則の明文化」の
  二択を提示。原因別分岐は分岐が増え 禁止事項#6 (やたらに複雑な案) に寄る。
  overlay は「過去の 1 操作の結果表示」であり、権威ある新サーバスナップショットが来た時点で陳腐化する。
  再操作で 402 等はサーバ再判定され再表示される。session 系は「sibling POST が成功した = session 有効」なので
  クリアが正しい。よって「新スナップショット到来で transient overlay を常時破棄」を UX 原則として採用・明文化する。
- 対応内容: 概念設計に UX 原則セクションを追加し、402/401/419 も同原則に従ってよい理由を補強。
  期待効果の「他の Inertia reload 起因の stale も一括解消」は誇張を避け「Show 内 sibling 操作由来の stale」に限定。

## [Warning] (観点5 リスク) `currentJob/status` まで毎回 props に戻すのは影響範囲が広い
- 判断: 対応する (broad な re-sync を設計から除外し、overlay クリアのみに絞る)
- 根拠: 本 finding の本質は errorMessage overlay の残留。finding のシナリオ (422 missing-doc → SOP upload) では
  job は null のままで currentJob/status 再同期は不要。broad な re-sync は poll 駆動 state と干渉するリスクを増やす。
  禁止事項#6 に照らし最小責務に絞るのが正しい。
- 対応内容: 設計を「**overlay (errorMessage/showPurchaseLink/sessionExpiredMessage) のクリアのみ**」に縮小。
  currentJob/status の seed-once は温存 (XHR/reload 経路が既に更新している)。
  panel 内の「transient overlay」と「server-truth 由来 (currentJob/failedJob)」の区別を実装コメントで明記。

## [Suggestion] 成功条件を明示
- 判断: 対応する
- 対応内容: 受け入れ基準 (acceptance criteria) を概念設計に追記。

## [Suggestion] エラー時ボタン disabled にしない原則の維持を明記 / job・status 型を props 型から逸脱させない
- 判断: 対応する (明記)
- 対応内容: 制約に追記。
