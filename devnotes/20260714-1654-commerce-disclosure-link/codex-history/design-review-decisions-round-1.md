# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED（施策1/2 は APPROVE、施策3/4 の contract テスト設計に Warning）。

## [Warning] 施策3/4: `getAllByRole` からの厳密一致 `toEqual` が順序依存で脆い
- 判断: 一部対応（フィルタ済みである点を明確化しつつ、個別 href 検証を追加）
- 根拠: 現設計は既に `.filter(href => ["/terms","/privacy","/commerce-disclosure"].includes(href))`
  で **法的リンクだけに絞ってから**順序比較しているため、footer に非法的リンク
  （料金プラン / お問い合わせ 等）が増減しても順序比較は壊れない（ノイズ耐性あり）。
  Codex の「ノイズリンク混入で揺れる」懸念はフィルタで解消済み。ただし意図をより明確に
  するため、terms / privacy / commerce の href を **個別に `getByRole` で取得して assert** し、
  順序は filtered DOM 順で確認する二段構えに補強する。将来 4 つ目の法的リンクが追加された
  場合に toEqual が要更新になるのは「法的リンク集合の契約」として意図的（ドリフト検知の要）。
- 対応内容: 詳細設計の施策3/4 テストを、(a) terms/privacy/commerce それぞれ個別 href 検証 +
  (b) filtered legal href 配列の順序検証、に更新。

## [Warning] 施策4: `within` 未 import でコンパイルエラー
- 判断: 対応する
- 根拠: `tests/js/pages/Pricing.test.ts` は現状 `within` を import していない。設計の
  「リスク欄」記載に留めず、施策4 の**変更内容として import 追記を正式に明示**する。
- 対応内容: 施策4 の変更箇所に
  `import { fireEvent, render, screen, within } from "@testing-library/svelte";`
  への差し替えを明記。Welcome.test.ts は既に within を import 済みで追加不要（現状維持）。

## [Warning] ラベル完全一致が表記ゆれで壊れる（正規表現化の提案）
- 判断: 見送る（完全一致を契約として維持）
- 根拠: 本テストの狙いは「footer 文言を blade（`legal/commerce-disclosure.blade.php` の
  `特定商取引法に基づく表記`）と一致させる契約の固定」。表記ゆれを許容する正規表現は
  この契約を緩める。Codex 自身も「契約として文言固定が必要なら現状維持で可」と許容。
  文言は法定表記であり揺れさせない方が正しい。完全一致（exact string name）を維持する。

## [Suggestion] ルート path の共通定数化
- 判断: 見送る
- 根拠: 既存の terms/privacy も path 直書き。今回だけ定数化すると整合性を欠き、
  「今必要なものだけ作る」に反する。既存踏襲。
