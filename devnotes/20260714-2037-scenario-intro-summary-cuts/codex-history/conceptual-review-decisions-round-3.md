# 対応マトリクス: conceptual-review Round 3

## [Warning] point 不在時に「要点再掲」が存在しない
- 判断: 対応する
- 対応内容: 抽出を 3 段に。(i) point.subtitlePrimary 非空 → (ii) 0 件なら top-level step.subtitlePrimary 非空 → (iii) いずれも 0 件のみ定型フォールバック。期待効果も「再掲可能な生成内容がある場合に要点再掲、無い稀なケースは定型締め」に限定。

## [Suggestion] 1 件でも上限超過する場合
- 判断: 対応する
- 対応内容: 件数削減後も 1 件超過なら最後に文字単位で MAX_SUBTITLE_SECONDARY_CHARS へ truncate。

## [Suggestion] config も typed accessor で正整数保証
- 判断: 対応する
- 対応内容: N・truncate 長は config()->integer(...) 等の正整数保証 accessor で読む。
