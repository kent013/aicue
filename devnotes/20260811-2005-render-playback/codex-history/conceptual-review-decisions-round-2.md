# 対応マトリクス: conceptual-review Round 2

## [Warning] 1. unpublished manual でも `finishedJob` が生成され「押すと 404」が再発する
- 判断: **対応する**(UI 側の二重判定のみ見送り)
- 根拠: 指摘のとおり。`CurrentRenderArtifact` から published を外した結果、条件を
  呼び出し側で書く責任が生じており、props 側にそれを明記していなかったのは設計の穴。
- 対応内容: 概念設計に「props は `status === Published` のときだけ `finishedJob` を組み立てる」
  を明記。Feature テスト「ready に戻った manual では `finishedJob=null`」を計画に入れる。
  **見送り**: UI 側で `status === "published"` を再判定する案は採らない。同じ規則を props と
  UI の 2 箇所に持つと、どちらが正かが曖昧になり drift する (T148 が「注記と URL を同一
  オブジェクトから出す」で潰したのと同じ種類の穴)。判断は props で 1 回に閉じる。
  書き出し中 (`rendering`) の除外は、完成動画ブロックを既存 DL ボタンと同じ
  `{#if rendering}…{:else}` の else 枝に置くことで構造的に行う。

## [Warning] 2. `canManage` は `download` ability の恒久的な代理にならない (誇張)
- 判断: **対応する**(前者 = 主張を削る)
- 根拠: 指摘が正しい。UI は `canManage` のままなので「自動追従」は嘘になる。
  `canDownload` props の新設は現時点で可否が完全同値であり、思考原則 2 に反する。
- 対応内容: 「将来自動追従する」記述を削除し、「現行では完全に同値」「policy が分岐した日には
  props と UI も併せて変える必要がある (自動では追従しない)」と明記した。

## [Suggestion] 3. Architecture gate の検出条件を詳細設計で実証せよ
- 判断: **対応する**
- 対応内容: 詳細設計のテスト計画に、(a) 移設**前**に既存 3 経路すべてが母集団へ入ること
  (負のコントロール)、(b) 母集団 0 件なら gate 自身が fail すること、(c) 移設後は
  `CurrentRenderArtifact` だけが残ること (exact-fit) を、mutation 手順つきで書く。
