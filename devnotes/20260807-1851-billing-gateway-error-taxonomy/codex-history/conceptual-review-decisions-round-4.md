# 対応マトリクス: conceptual-review Round 4

Codex 判定: **CHANGES_REQUESTED** ([Critical] 0 / [Warning] 2 / [Suggestion] 5)。
Round 3 の Critical 2 件は解消と認定された。残り 2 件はいずれも局所修正で、全件対応した。

## [Warning] unknown で検出したアプリ例外を足すと集合 gate に拒否される

- 判断: **対応する (Codex の修正案をそのまま採る)**
- 根拠: 指摘は正しい。集合契約を
  `(directMap ∪ conditionalClasses) \ vendorConcreteClasses = framework 明示宣言集合`
  と書いた時点で、「**運用契約に従ってアプリ例外を写像表へ足した瞬間に gate が赤くなる**」
  という自己矛盾が入っていた。運用契約 (unknown が出たら必ず表へ足す) と
  gate が両立していない設計は、運用の側が黙って形骸化する。
- 対応内容:
  - 集合名を `nonVendorExplicitClasses` へ**一般化**した (framework 由来に限定しない)。
    「将来アプリ自身の例外がここへ入りうる」ことを概念設計に明記。
  - 集合契約を Codex 提示の形に置き換えた:
    `keys(directMap) ∪ conditionalClasses = vendorConcreteClasses ∪ nonVendorExplicitClasses`
  - この集合にも **exact fit の件数 cap (初期値 3)** を持たせ、無断追加を差分に必ず現す。

## [Warning] 背景部分に「クラス名は有界」という旧表現が残っている

- 判断: **対応する**
- 根拠: 正しい。Round 1 の W6 で後半だけ直し、冒頭の記述を直し忘れていた。
  文書内で「有界」の意味が 2 通りになっていた。
- 対応内容: 2 箇所を修正。
  - 「クラス名は『有界』ではあるが分類ではない」→
    「クラス名は外部サービスが生成するメッセージではないが、**運用行動を示す分類ではない**」
  - 冒頭の表の `terminateInvoiceBestEffort()` 行「有界な語彙のみ」→
    「**外部生成メッセージを含まない**」

## [Suggestion] 使命 / 禁止事項 / 期待効果 / スコープ / 型安全性

- 判断: 変更不要 (すべて肯定的評価)。
