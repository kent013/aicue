# 対応マトリクス: design-review Round 2（CHANGES_REQUESTED, 残 1 Warning）

## S3 [Warning] `<<IDENT>` 単純出現検査が接頭辞一致を誤検出
- 判断: 対応する
- 対応: 開始タグ名の終端境界まで検査。`new RegExp('<' + escapeRegExp(identifier) + '(?:\\s|/?>)')`
  で通常属性/改行/自己閉じ/空タグに対応しつつ `<PageContentPreview>` 等の接頭辞一致を排除。

## S3 [Suggestion] 「allowlist 未登録」分類は import 不足と区別不能
- 判断: 対応する
- 対応: 失敗分類を「import 不足 / import はあるが未使用」の 2 種のみに。allowlist は理由コメント規約として残す。

## S2 [Suggestion] testId? は既定値ありで固定 testid とほぼ同等
- 判断: 現状維持（testId? を残す。無害・小）。固定でも可という指摘は受容しつつ任意化を維持。

## S2/S3 [Suggestion] 「例外は理由付き」は機械検証不可
- 判断: 対応する
- 対応: arch テスト先頭コメントに「標準 2xl・例外理由付き」「allowlist 追加は理由必須」は**機械強制ではない
  運用規約**と明記。

## S1: APPROVE / S2: APPROVE（変更なし）
