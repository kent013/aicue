# 対応マトリクス: conceptual-review Round 1

## [Warning] Edit.svelte からの直接遷移で未保存の編集を落とすリスク
- 判断: 一部対応（設計に明記）＋一部反論
- 根拠: 現状 `Manuals/Edit.svelte` には既に「キャンセル」ghost リンクがあり、未保存の title/category を
  dirty ガードなしで破棄して Show へ遷移する。ScenarioEditor は独立 XHR で保存し、フォーム離脱ガードは
  アプリ全体に存在しない。撮影リンクだけに離脱確認を新設するのは既存挙動と不整合で over-engineering。
- 対応内容: (1) 撮影リンクは既存「キャンセル」と同一の Inertia 通常遷移セマンティクス（dirty ガードなし）で
  あることを設計に明記。(2) Edit では撮影リンクを「基本情報を保存」ボタン群の近傍ではなくヘッダ側に置き、
  保存アクションと視覚的に競合させない。(3) アプリ全体の dirty-navigation ガードは別課題として out-of-scope 明記。

## [Warning] `ready|published` 判定を Show/Edit に直書きするとステータス体系変更でドリフト
## [Warning] 型安全性: status 文字列リテラルを複数箇所に裸で置くと enum 追加時に見落とす
- 判断: 対応する（統合）
- 根拠: 妥当。共通の型付き predicate に寄せるのは安価で将来の enum 追加に強い。
- 対応内容: `resources/js/types/manual.ts`（`VideoManualStatus` / `VIDEO_MANUAL_STATUS_LABELS` の定義元）に
  `CAPTURE_NAVIGABLE_STATUSES` と型付き `isCaptureNavigable(status: VideoManualStatus): boolean` を追加し、
  Show/Edit 双方はこの predicate 経由でのみ判定する。`'ready' | 'published'` の裸のリテラルを画面に散在させない。

## [Suggestion] ready/published 以外は「非表示」であり disabled ボタンにしない旨を明記
- 判断: 対応する
- 対応内容: 禁止事項 #8 に従い、撮影不可状態では**リンク自体を非表示**（disabled ボタンにしない）と設計へ明記。

## [Suggestion] 期待効果は「アプリ内導線短縮」に留め、PC→スマホ実機ハンドオフの解決と誤読させない
- 判断: 対応する
- 対応内容: 期待効果の表現を「アプリ内導線（リセレクト手数）の短縮」に限定する旨に修正。

## [Suggestion] 非表示の理由が伝わりにくい → 補助文言
- 判断: 見送る
- 根拠: draft 等では他の主要導線（解析/シナリオ編集）が主役であり、撮影リンクの不在は自然。補助文言は
  ノイズになり over-engineering。将来 UX データで必要と分かれば別途検討。
