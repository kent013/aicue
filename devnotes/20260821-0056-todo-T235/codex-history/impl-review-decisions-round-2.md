# 対応マトリクス: impl-review Round 2

## [Critical] 大小文字違いの重複属性が未検証 (`type="text" TYPE="file"`)

- 判断: **対応する** (指摘が正しく、Round 1 での私の「到達不能」判断は裏取り不足だった)
- 根拠: 実測した結果、**svelte の重複検査は大小文字を区別する**ことが分かった。
  - `<input type="file" type="text" accept="y" />` → `Attributes need to be unique` で parse 拒否
  - `<input type="text" TYPE="file" accept="x" />` → **parse を通る** (属性は `type,TYPE,accept`)
  - `<input type="file" accept="x" ACCEPT="y" />` → **parse を通る** (`type,accept,ACCEPT`)

  したがって Round 1 で属性名を小文字化した変更と引き換えに、`attributeNamed()` が
  正規化後の**先頭だけ**を採る形が新しい fail-open を作っていた
  (`type="text" TYPE="file"` は先頭の `type="text"` を見て母集団外になる = 実行時には file input)。
- 対応内容:
  - `attributeNamed()` を `attributesNamed()` へ戻し、**正規化後に複数件ある形を診断へ落とす**
    分岐を復活させた (`type` → `unresolved-type` / `accept` → `unresolved-accept`)。
    これらは免除できない理由なので無条件違反になる。
  - 撤去したのは「綴りが同じ重複」用の分岐という判断も改めた: 分岐は 1 本で両方を扱う
    (綴りが同じ重複は parse 側で落ちるため、この分岐に到達するのは大小文字違いだけ)。
  - 負例を 3 件追加した:
    - `44.` `type="text" TYPE="file" accept="x"` → `unresolved-type` (母集団から外れない)
    - `45.` `type="file" accept="x" ACCEPT="y"` → `unresolved-accept`
    - `46.` 宣言順を入れ替えた `TYPE="file" type="text"` でも `unresolved-type`
  - 既存の `41.` は「**綴りが同じ**属性の重複は parse が拒否する」ことの pin として残した
    (2 通りの経路がどちらも fail-closed であることを別々に固定する)。

## [Warning] `FileInputScanResult.diagnostics` の説明が実態と合っていない

- 判断: **対応する**
- 根拠: 指摘どおり。免除目録と突き合わせるのは `spread-attribute` だけで、既定は無条件違反である。
- 対応内容: 「判定側の既定は**無条件で違反**で、免除目録と突き合わせるのは免除できる理由に
  限られる (現在は `spread-attribute` だけ。正本は `ExemptibleDiagnosticReason`)」へ書き換えた。
  併せて走査器 docblock の属性重複の記述も、2 通りの経路 (綴り同じ = `parse-failed` /
  大小文字違い = `unresolved-type` / `unresolved-accept`) を書く形へ訂正した。

## [Warning] 自己検査の説明に「名指しの免除目録」が残っている

- 判断: **対応する**
- 根拠: 指摘どおり。AGENTS.md と目録 docblock は訂正したのに、テスト側の説明だけ古かった。
- 対応内容: 「鍵は `file` + `reason` + 件数の完全一致であり『名指し』と呼べる精度ではない。
  同一ファイル・同一理由・同数の置き換えは検出しない (最後の負のコントロールで境界を機械 pin)」
  へ書き換えた。

## [Suggestion] `ExemptibleDiagnosticReason` と `EXEMPTIBLE_DIAGNOSTIC_REASONS` の二重定義

- 判断: **対応する**
- 根拠: 二重定義は「片方だけ広げられる」ずれの余地を残す。提案の形で構造的に防げる。
- 対応内容: 提案どおり定数配列を正本にし、型をそこから導出する形へ変えた。
  `satisfies readonly ScanDiagnosticReason[]` を付けて、配列の要素が診断理由の
  値域から外れたらコンパイルで落ちるようにしてある。
