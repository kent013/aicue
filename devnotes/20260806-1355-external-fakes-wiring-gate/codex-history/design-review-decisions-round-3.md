# 対応マトリクス: design-review Round 3

## [Warning] `bind()` の第 3 引数 (`$shared`) が許可されたままで M6 を回避できる
- 判断: **対応する** (指摘は正しい。`bind(A::class, B::class, true)` は **singleton 相当**で、
  bindPairs の集合一致・3-9・3-10・実証解決のすべてを通ってしまう)
- 対応内容: `disallowedContainerCalls()` の検出仕様を **引数の個数と形まで固定**する形に強化。
  - `bind()` は「**位置引数ちょうど 2 個、かつ両方が `::class` 定数**」のみ許可
  - `make()` は「**位置引数ちょうど 1 個**、かつ許可 class-string」のみ許可
  - **名前付き引数 / spread unpack** を伴う呼び出しは fail-closed で禁止
    (現行 provider に不要。許すと引数位置の解析が破れる)
- 追加テスト: **5-18** (`bind(A::class, B::class, true)` / 引数付き `make()` /
  名前付き引数 `bind(abstract: …)` を各 1 件検出する)。

## [Suggestion] `referencedClasses()` の `$candidates` 説明が本文と不一致
- 判断: **対応する**
- 対応内容: docblock を「収集元 3 の文字列完全一致と、**収集元 4 の basename 照合**に使う」へ修正。

## 施策 1 / 3 / 4 / 6: APPROVE (指摘なし)
- 判断: **現状維持**

## 打ち切りについて
- 詳細設計レビューは上限 3 ラウンド (タスク指定)。Round 3 の Warning 2 件は**すべて反映済み**だが、
  **反映後の再判定 (Round 4) は実施していない**。残る未検証点は
  「引数個数まで固定した `disallowedContainerCalls()` の仕様に、さらに別の抜け道が無いか」のみで、
  実装時に施策 5 (走査器の Unit テスト 18 ケース) が positive/negative を実コードで固定する。
