# 対応マトリクス: conceptual-review Round 3

## [Warning] 条件付き UPDATE の不変条件を固定するテストが不足
- 判断: 対応する
- 対応内容: Feature テストに 3 ケースを追記。(1) 候補 membership が UPDATE 前に消失（EXISTS 偽）→ current を設定しない、(2) 観測後に current が別 org へ変更済み → 上書きしない、(3) 条件付き UPDATE 0 件 → fresh 再取得した最新状態で解決（1 回のみ・解決不能なら null）

## [Warning] UPDATE 0 件時の再解決方法が曖昧（stale インスタンス/relation キャッシュ）
- 判断: 対応する
- 対応内容: 解決契約に「UPDATE の成否にかかわらず relation キャッシュを破棄して User を DB から fresh 再取得し、最新値に対して所属再確認つき読み出しを行う。再確認は 1 回のみ、解決不能なら null（無限再試行しない）」を明記

## [Suggestion] 各所（結果整合性の許容・Resolver 境界・型）
- 判断: 現設計のまま（肯定的評価）。「すべての組織データ取得は Resolver の所属確認済み結果（返り値 Organization の relation）を起点とする」は設計どおり
