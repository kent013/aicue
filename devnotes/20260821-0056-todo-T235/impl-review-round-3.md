Round 2 の指摘はすべて解消されています。新たな blocking finding はありません。

## ファイル別判定

### `tests/js/support/file-input-scan.ts`

判定: 問題なし。

大小文字を正規化した後の属性を配列として保持し、複数件なら `unresolved-type` / `unresolved-accept` へ落としているため、指摘した穴は閉じています。

- `nativeInputCount` は診断前に加算される
- 診断時は `fileInputs` に入れず、無条件違反へ渡される
- 属性の宣言順に依存しない
- 同一綴りの重複は `parse-failed`
- 大小文字違いの重複は走査器による診断

という分担も正確です。

確認した範囲では、ほかに正規化後の情報を先頭要素だけ採用する経路は残っていません。要素名、`type` 値、属性名はいずれも、今回の判定目的に必要な情報を維持したまま処理されています。

### `tests/js/support/file-input-accept-inventory.ts`

判定: 問題なし。

免除理由の定数配列を単一の情報源として型を導出する形になり、型と実行時集合の乖離も解消されています。大小文字違いの重複から生じる診断は免除不能なので、判定側でも fail-closed です。

### `tests/js/architecture/file-input-scan.test.ts`

判定: 問題なし。

次の両方向が十分に固定されています。

- 同一綴りの重複が parse で拒否される
- 大小文字違いの重複が parse を通っても走査器で診断される
- `type` の宣言順を入れ替えても診断される
- `accept` の大小文字違いも診断される
- 通常の大文字属性名は誤検出せず、正しく file input として扱われる

負例・正例、母集団、判定関数の接続まで揃っています。

全レーンについては、今回変更した範囲の focused test、lint、typecheck は十分なレビュー根拠です。ただし、実装完了・コミット前には記載された全検証コマンドの green を確認してください。

APPROVED