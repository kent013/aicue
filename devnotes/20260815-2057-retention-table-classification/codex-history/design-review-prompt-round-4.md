# Round 4: 詳細設計の改訂版 (`set null` の nullable 条件)

Round 3 の指摘への対応マトリクスは
`devnotes/20260815-2057-retention-table-classification/codex-history/design-review-decisions-round-3.md`
に記録した。修正必須とされた 1 点 (`set null` の nullable 条件) を含め、全指摘に対応した。

## 対応の要点

| # | 指摘 | 判断 | 対応 |
|---|---|---|---|
| 1 | [Warning] `set null` は列が nullable でなければ親削除が成立しない | **対応 (nullable まで検査する側を採る)** | 外部キーの一覧に `columns` を残し、nullable の一覧を引数で受け取る形にした (下記) |
| 2 | [Suggestion] `restrict` / `no action` の説明 | 対応 | 「削除対象の親行が子から参照されていれば親の削除を拒否する」に書き換え |
| 3 | [Suggestion] `set default` の説明 | 対応 | 「既定値への置換を試みるが、その値が制約を満たさなければ親削除は失敗する。現在利用例が無いため分類の見直しを求める」に書き換え |
| 4 | [Warning] 施策 5 の文書にも nullable 条件を反映 | 対応 | 「列がすべて nullable な `set null` だけが矛盾ではない」と書く指示にした |

**非保証事項へ逃がす案を採らなかった理由**: RC-7 が使っている推論そのもの
(「`set null` なら子は残る」) を「保証しない」と書くのは、検査の根拠を空にすることになる。
判定に使う推論は検査するのが一貫している。

## 改訂後の実物

```php
/**
 * 全表の外部キーを 1 度だけ読み、表名 => 参照先・列・on delete の一覧にする。
 *
 * @return array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>
 */
function retentionForeignKeyMap(): array { /* getForeignKeys($schema.'.'.$table) から 3 キーを取り出す */ }

/**
 * 指定した表の列が nullable かどうか。
 *
 * RC-7 が `on delete set null` を非違反にしてよいかの判定にだけ使う。
 * **対象は「基準データ」「基盤が寿命を持つ」に分類した表だけ**に絞って引く (十数表)。
 *
 * @param  list<string>  $tables
 * @return array<string, array<string, bool>> 表名 => 列名 => nullable か
 */
function retentionNullableColumnMap(array $tables): array
{
    $builder = retentionSchemaBuilder();
    $schema = $builder->getCurrentSchemaName();

    $map = [];
    foreach ($tables as $table) {
        $columns = [];
        foreach ($builder->getColumns($schema.'.'.$table) as $column) {
            $columns[$column['name']] = $column['nullable'];
        }
        $map[$table] = $columns;
    }

    return $map;
}

/**
 * RC-7 の判定 (**純関数**)。期限を持たない区分の表が、期限が要る区分の表を
 * **矛盾する `on delete` で**参照していないか。
 *
 * ★**外部キーの存在だけでは違反にしない**。親が消えたときに子がどうなるかで意味が変わる:
 *   - `cascade` = 子も消える → 「期限を持たない」と矛盾する (違反)
 *   - `restrict` / `no action` = 削除対象の親行が子から参照されていれば**親の削除を拒否する**
 *     → 親の期限の執行を止めうる (違反)
 *   - `set null` = 子の外部キー列を空にして子は残る → 子自身は期限の連鎖の外にある。
 *     **ただし外部キーの列がすべて nullable なときに限る** — `NOT NULL` の列が混ざっていると
 *     親の削除は制約違反で失敗するので、実際には `restrict` と同じ結果になる (違反)
 *   - `set default` = 既定値への置換を試みるが、その値が外部キー制約を満たさなければ
 *     親の削除は失敗する。本リポジトリに 1 本も無いため、**現れたら分類の見直しが要る**ものとして
 *     保守的に違反へ倒す
 *   - `null` (取得できない) = 未知 → 保守的に違反へ倒す
 *
 * @param  list<RetentionTableEntry>  $entries
 * @param  array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>  $foreignKeys
 * @param  array<string, array<string, bool>>  $nullableColumns  表名 => 列名 => nullable か
 * @return list<string> `{表名} -> {親表名} (on delete …)` の形の違反一覧
 */
function retentionHorizonParentViolations(array $entries, array $foreignKeys, array $nullableColumns): array
{
    $conflicting = ['cascade', 'restrict', 'no action', 'set default', null];
    // `set null` だけは列の nullable を 1 つずつ見てから決める (複合外部キーがあるため)
    // …
}
```

テスト側 (nullable の照会を絞る):

```php
test('RC-7: 期限を持たない区分の表が、期限が要る区分の表を親に持っていない', function (): void {
    $entries = RetentionTableRegistry::entries();
    $noHorizonTables = array_values(array_map(
        static fn (RetentionTableEntry $entry): string => $entry->table,
        array_filter($entries, static fn (RetentionTableEntry $entry): bool => ! $entry->class->hasHorizon()),
    ));

    $violations = retentionHorizonParentViolations(
        $entries,
        retentionForeignKeyMap(),
        retentionNullableColumnMap($noHorizonTables),
    );
    // …
});
```

負のコントロールと正のコントロール (すべて合成入力):

- NC-4: 「基準データ」の表が「定期実行が消す」表を `cascade` で参照すると点灯する
  (`restrict` でも点灯することを同じテストで確かめる)
- **NC-5**: `set null` でも外部キーの列に `NOT NULL` が混ざっていると点灯する
  (複合外部キーの合成入力で確かめる)
- 正のコントロール: `set null` + 列がすべて nullable なら点灯しない

`Builder::getColumns()` の戻り値に `nullable: bool` があることは vendor 実物で確認した
(`vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php` 397 行)。

## 質問

1. `set null` の nullable 条件はこれで解消したか。
2. 残る [Critical] / [Warning] があれば挙げてほしい。実装時に決めれば足りるものは
   [Suggestion] にしてほしい。

各施策の判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示すること。
