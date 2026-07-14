Round 1 の指摘への対応を反映しました。対応マトリクスと概念設計の差分を報告します。

## 対応サマリー

### [Warning] Edit からの遷移で未保存編集を落とすリスク → 一部対応＋一部反論
- 現状 Edit には既に「キャンセル」ghost リンクがあり、未保存 title/category を dirty ガードなしで破棄し
  Show へ遷移する。フォーム離脱ガードはアプリ全体に存在しない。撮影リンクだけに離脱確認を新設するのは
  既存挙動と不整合で over-engineering と判断。
- 対応: 撮影リンクは既存「キャンセル」と同一の Inertia 通常遷移セマンティクス（dirty ガードなし）であることを
  設計に明記。撮影リンクは保存ボタン群（基本情報カード内）と別のヘッダ側に置き視覚的競合を避ける。
  アプリ全体の dirty-navigation ガードは別課題として out-of-scope 明記。

### [Warning] ready/published 直書きのドリフト ＋ [Warning] 型安全性 → 対応（統合）
- `resources/js/types/manual.ts` に `CAPTURE_NAVIGABLE_STATUSES` と型付き
  `isCaptureNavigable(status: VideoManualStatus): boolean` を追加。Show/Edit は predicate 経由でのみ判定。
  `'ready' | 'published'` の裸リテラルを画面に散在させない。

### [Suggestion] disabled ボタンにしない → 対応
- 撮影不可状態は**リンク非表示**（disabled ボタンにしない。禁止事項 #8）と明記。

### [Suggestion] 期待効果の表現をアプリ内導線短縮に限定 → 対応
- 「アプリ内導線短縮であり PC→スマホ実機ハンドオフの解決ではない」旨を注記。

### [Suggestion] 非表示理由の補助文言 → 見送り
- draft 等では他の主要導線が主役で撮影リンク不在は自然。補助文言はノイズと判断。将来 UX データで再検討。

## 更新後の概念設計（全文）

（以下に conceptual-design.md 更新版を貼り付け）
