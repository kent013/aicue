# 対応マトリクス: conceptual-review Round 2

## [Warning] 観点2/禁止事項#1: 回帰を固定する新規テストが未明記
- 判断: 対応する
- 根拠: テストファースト原則。回帰固定テストの明記が必須。
- 対応内容: 概念設計の受け入れ基準に **新規 vitest ケースを明示**:
  (a)「422 表示 → `hasDocument: false→true` で start-error alert が消える」
  (b)「ポーリング中の進捗/step/2.5秒間隔は不変」(既存回帰の非退行)
  (c)「failedJob alert は非退行」。詳細設計のテスト計画にも具体ケースを列挙する。

## [Warning] 観点3: 署名比較は「新スナップショット」を識別できていない (値が全て同じなら no-op で overlay 残留)
- 判断: 対応する (Codex 推奨の path A = 本 finding に限定した narrow fix へ設計変更)
- 根拠: 署名方式は「無条件破棄」原則と実装が乖離する (指摘の通り)。
  Show から revision/key を渡す path B は Show/AnalysisPanel 双方に手を入れ、props も増える (over-engineering)。
  本 finding の本質は「手順書なし 422 overlay が SOP アップロード後に残る」ことであり、
  その precondition (手順書なし) が解消される契機は `hasDocument: false→true` の遷移。
  この遷移を直接トリガーにするのが根本原因・機能名に最も忠実 (機能の名前に立ち返れ / 禁止事項#6)。
- 対応内容: 「新スナップショットで無条件破棄」という一般化を撤回。
  設計を **「`hasDocument: false→true` の遷移時に、start error が missing-document (422) 種別なら overlay を破棄」**
  に限定する。署名比較は廃止。

## [Suggestion] 観点4/6: 効果と責務を「Show 内の SOP アップロード後に残る 422」に限定
- 判断: 対応する
- 対応内容: 期待効果・UX 原則の記述を narrow fix に合わせて書き換え。「transient overlay 無条件破棄」の
  一般化表現を削除。

## [Suggestion] 観点7: errorMessage 文字列判定でなく、エラー種別/ステータスを state に持つと型安全に 422 だけ消せる
- 判断: 対応する
- 根拠: server-side の検証順序 (402 が document チェックより前に来る可能性) に依存せず、
  422 (missing-document) の overlay だけを型安全に消すために、start error の **種別 (kind)** を
  ローカル state として保持するのが堅い。
- 対応内容: `startErrorKind: "missing_document" | "insufficient_tickets" | "conflict" | "generic" | null`
  を導入し、`handleStartResponse` で res.status/code から設定。overlay 破棄は
  `startErrorKind === "missing_document"` のときのみ行う。

## [Suggestion] 観点5: currentJob/status を再同期対象から外した判断は妥当
- 判断: 維持 (そのまま)
