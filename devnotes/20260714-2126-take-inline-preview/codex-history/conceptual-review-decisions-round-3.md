# 対応マトリクス: conceptual-review Round 3

Codex 判定: CHANGES_REQUESTED（Critical/設計 Warning なし。残るは全てテスト計画への反映漏れ）

## [Warning] R3-W2: 録画排他の不変条件に対応する Vitest が無い
- 判断: 対応する
- 対応内容: vitest 計画に 3 ケース追加 —(a) 録画中の再生押下で dialog を開かずエラー表示、(b) recorder 録画終了/破棄を呼ばない、(c) 録画待機中 open で stream 解放・close 後再取得。

## [Warning] R3-W5: video teardown（pause/src除去/load）の Vitest が無い
- 判断: 対応する
- 対応内容: dialog close 経路・採用成功経路の両方で teardown が呼ばれる vitest を追加。teardown は単一関数に集約。

## [Warning] R3-W8: Cache-Control テストが no-store のみ、private 未検証
- 判断: 対応する
- 対応内容: Feature テストで `no-store` と `private` の両 directive を固定。

## [Suggestion] 型安全性/スコープ/効果限定の妥当性確認
- 判断: 情報。追加対応なし。
