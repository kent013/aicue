仮説は「字句走査の受理境界と、負例行列の件数 pin に偽グリーンが残りやすい」です。確認の結果、実際に 1 本の偽グリーン経路があります。

### 指摘あり

`tests/js/support/enum-ts-sync/php-enums.ts`

- [Critical] `CASE_SINGLE` / `CASE_DOUBLE` の値部分が改行を許しています。`[^'\\]*` と `[^"\\$]*` は CR/LF にも一致するため、次は受理されます。

```php
enum X: string
{
    case A = 'a
b';
}
```

TS 側を `"a\nb"` にすれば値集合も一致し、gate 全体が緑になります。これは「改行を含む case は例外」「1 行だけを受理」という設計・文書に反します。値部分から `\r\n` を除外するか、宣言範囲に CR/LF があれば先に拒否する必要があります。

`tests/js/architecture/enum-ts-sync-extractor.test.ts`

- [Warning] P15 は `=` と文字列の間の改行だけを検査しており、文字列リテラル内部の改行を検査していません。このため上記の不具合を検出できません。単一・二重引用符の双方について負例が必要です。
- [Warning] 「TS 27 件」の pin が実際に実行される行列を固定していません。`it.each` に渡るのは 26 件で、27 件目は独立した T25b です。T25b の `it` だけを削除しても、`TS_CASES.length + 1` は引き続き 27 となります。設計どおり、program 種別を行に持たせた 27 行のデータ駆動行列など、実行対象そのものを pin してください。

`tests/js/architecture/enum-ts-sync.test.ts`

- [Warning] パス検査の実装自体は適切ですが、負のコントロールが設計上重要な分岐を固定できていません。特に以下が未検査です。

  - `app-legacy/` のような兄弟 prefix
  - symlink による範囲外への脱出
  - symlink 別名による同一 TS 宣言の二重登録
  - 絶対パス、逆斜線、`.`、空区間
  - 拡張子違い、ディレクトリ

  現状の `config/app.php` では、`root + path.sep` を単なる `root` に弱める回帰や、`realpathSync` 検査の撤去を検出できません。セキュリティ境界として設計した分岐には対応する負例が必要です。

`docs/architecture.md`

- [Warning] 「複数行の case は例外」という保証が、現在の抽出器を超えています。上記 Critical の修正後は記述と一致します。

`AGENTS.md`

- [Warning] PHP 側を「1 行に一致」とする登録規約も、現在の実装より強い主張です。上記修正と同時に整合します。

`docs/TODO.md` / `docs/TODO-closed.md`

- [Warning] 詳細設計で「本作業の完了条件」とされた AG-099 後半 TODO の起票が、提示差分にはありません。すでに別変更で起票済みでない限り、本変更内で登録が必要です。

`docs/template-divergence.md`  
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php`

- [Suggestion] 現在の基準差分としては整合しています。ただし説明どおり main に D27/D28 が存在する状態へ載せる前に、D29・28件へ実差分を更新する必要があります。「追随予定」のままでは着地できません。

### 指摘なし

以下は詳細設計と一致しています。

- `tests/js/support/enum-ts-sync/errors.ts`
- `tests/js/support/enum-ts-sync/program.ts`
- `tests/js/support/enum-ts-sync/ts-value-sets.ts`
- `tests/js/support/enum-ts-sync/fixtures/*.ts`
- `tests/js/support/enum-ts-sync/program-fixtures/*.ts`
- `tsconfig.json`
- 8 件の `app/Enums/**` docblock 更新
- `resources/js/types/{account,manual,notification}.ts`
- `tests/Architecture/TicketLedgerReaderInventoryTest.php`
- 旧 PHP テスト 4 本と `tests/Support/TsUnionValues.php` の削除

旧 14 組の値集合比較は新目録の先頭 14 行へ移設され、抽出不能の自己検査も T7 に引き継がれています。全体 program、空集合拒否、enum literal 拒否、逆斜線偶奇、行注釈内 `?>`、深さ判定、重複値検査にも明白な取り残しは見当たりません。`any`、非 null 断言、例外の握り潰しもありません。

CHANGES_REQUESTED