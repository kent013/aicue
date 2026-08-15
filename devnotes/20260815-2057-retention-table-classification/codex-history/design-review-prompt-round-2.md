# Round 2: 詳細設計の改訂版

Round 1 の指摘への対応マトリクスは
`devnotes/20260815-2057-retention-table-classification/codex-history/design-review-decisions-round-1.md`
に記録した。以下は対応の要点と、改訂した箇所の実物である。再レビューを願う。

## 対応の要点

| # | 指摘 | 判断 | 対応 |
|---|---|---|---|
| 1 | [Critical] Schema API が PHPStan level 10 で不安定 | 対応 | `DB::connection()->getSchemaBuilder()` で具体の Builder を取り、実体側の `@return list<array{name: string, …}>` を効かせる。`array_map` で `list<string>` を明示し `sort()` で順序も固定 |
| 2 | [Critical] RC-6 / RC-7 が合成入力で点灯できない | 対応 | 外部キーの一覧を引数で受け取る純関数を 2 本に分離。実 DB 側は `retentionForeignKeyMap()` が 1 度だけ組み立てて渡す。負のコントロール 4 本を点灯条件つきで明記 |
| 3 | [Warning] `entries()` の戻り値が矛盾 | 対応 | `list<RetentionTableEntry>` に統一。連想配列にすると二重宣言が上書きで消えることを docblock に書いた |
| 4 | [Warning] FK 照会がスキーマを絞っていない | 対応 | `getForeignKeys()` は `parseSchemaAndTable()` を通すので `schema.table` を受け取れる (vendor 実物で確認: Builder.php 492 行 / 758 行)。修飾名で問い合わせる形にし、`search_path` の健全性は前提であって保証ではない旨を追記 |
| 5 | [Warning] `users` は行ごとに寿命が違う | 対応 | 根拠欄に明記させ、「行ごとの寿命の違いは表現しない」を保証しないものへ (gate docblock と docs の両方) |
| 6 | [Warning] `oauth_*` の理由と RC-5 がずれる | 対応 | 理由を「保持期限の責任者が未決 (掃除の配線を含む決着が未決)」に書き換え、「Schedule への登録有無は見ない」を保証しないものへ明記 |
| 7 | [Warning] RC-7 に FrameworkManaged を含めるのは強い | 対応 (含めたまま理由を明記) | 基盤の表がアプリの寿命を持つ表を親に持つなら、それは基盤が寿命を決めているとは言えず `DeletedWithParent` である。RC-7 は区分の定義そのものの検査になる |
| 8 | [Warning] `Artisan::all()` の副作用 | 対応 | `array_key_exists(..., Artisan::all())` に限定し、コマンドは実行しないことをコメントで固定。`ownerCommand` は名前付き生成子の都合で「定期実行が消す」区分だけが持つ |
| 9 | [Suggestion] 区分ごとの件数 pin は過剰摩擦 | **対応 (落とす)** | 全体件数と未確定の表名一覧で「台帳が空になった」「未確定が無音で増えた」は捕まる。区分ごとの件数は書き換えの手間だけで新しく捕まえるものが無い |
| 10 | [Suggestion] 施策 4 の文言が不正確 | 対応 | 「年数・起算点・purger を写さない。表名の重なりは RC-4 の結線で管理する」に直した |
| 11 | [Suggestion] Undecided の hasHorizon コメント | 対応 | 「期限が要ると決まった」ではなく「期限の連鎖に入りうるので保守的に horizon 側へ寄せる」 |
| 12 | [Suggestion] AGENTS.md の節名確認 | 確認済み | `## ドメイン固有規約` は実在し、現在 14 項。施策 6 はその 15 項めとして足す |

## 改訂した実物 (抜粋)

### スキーマ照会 (指摘 1・4)

```php
/**
 * スキーマ照会の入口。
 *
 * **ファサードではなく具体の Builder を取る** — `Schema::` の docblock は
 * `array getTables(...)` としか書いておらず、level 10 では要素が mixed になる。
 * `Connection::getSchemaBuilder()` は `Illuminate\Database\Schema\Builder` を返し、
 * 実体側の `@return list<array{name: string, schema: string|null, …}>` がそのまま効く。
 */
function retentionSchemaBuilder(): Builder
{
    return DB::connection()->getSchemaBuilder();
}

/** 現在のスキーマの表名 (非修飾・sort 済み)。pgsql は引数なしだと全スキーマを返すため必ず絞る。 */
function retentionSchemaTableNames(): array
{
    $builder = retentionSchemaBuilder();
    $names = array_map(
        static fn (array $table): string => $table['name'],
        $builder->getTables($builder->getCurrentSchemaName()),
    );
    sort($names);

    return $names;
}

/**
 * 全表の外部キーを 1 度だけ読み、表名 => 参照先と on delete の一覧にする。
 *
 * **スキーマ修飾名で問い合わせる** (`getForeignKeys()` は `schema.table` を受け取り
 * `parseSchemaAndTable()` で分解する)。表一覧を現在のスキーマに絞っておきながら
 * 外部キーの照会だけ `search_path` 任せにすると、同名表があるときに食い違う。
 *
 * @return array<string, list<array{foreign_table: string, on_delete: string|null}>>
 */
function retentionForeignKeyMap(): array
{
    $builder = retentionSchemaBuilder();
    $schema = $builder->getCurrentSchemaName();

    $map = [];
    foreach (retentionSchemaTableNames() as $table) {
        $map[$table] = array_map(
            static fn (array $fk): array => [
                'foreign_table' => $fk['foreign_table'],
                'on_delete' => $fk['on_delete'],
            ],
            $builder->getForeignKeys($schema.'.'.$table),
        );
    }

    return $map;
}
```

### RC-6 / RC-7 の純関数化 (指摘 2)

```php
/**
 * RC-6 の判定 (**純関数**。外部キーの一覧を引数で受け取るので合成入力で点灯させられる)。
 *
 * 通り道は 2 つだけである:
 *   (a) `on delete cascade` の外部キーを 1 本以上持つ (DB が連動を保証する)
 *   (b) 削除責務を持つクラスを宣言している (連動がアプリ側にある。vendor 由来の表など)
 *
 * @param  list<RetentionTableEntry>  $entries
 * @param  array<string, list<array{foreign_table: string, on_delete: string|null}>>  $foreignKeys
 * @return list<string>
 */
function retentionDeletedWithParentViolations(array $entries, array $foreignKeys): array
{
    $violations = [];
    foreach ($entries as $entry) {
        if ($entry->class !== RetentionClass::DeletedWithParent || $entry->ownerClass !== null) {
            continue;
        }
        $cascades = array_filter(
            $foreignKeys[$entry->table] ?? [],
            static fn (array $fk): bool => $fk['on_delete'] === 'cascade',
        );
        if ($cascades === []) {
            $violations[] = $entry->table;
        }
    }

    return $violations;
}

/**
 * RC-7 の判定 (**純関数**)。期限を持たない区分の表が、期限が要る区分の表を親に持っていないか。
 *
 * @param  list<RetentionTableEntry>  $entries
 * @param  array<string, list<array{foreign_table: string, on_delete: string|null}>>  $foreignKeys
 * @return list<string>
 */
function retentionHorizonParentViolations(array $entries, array $foreignKeys): array
{
    // …
}
```

負のコントロール (すべて DB を触らず合成入力で点灯させる):

- NC-1: 台帳に無い表名を表一覧へ足すと RC-1 が点灯する
- NC-2: 実在しない表名を台帳へ足すと RC-2 が点灯する
- NC-3: `on delete` が cascade でない外部キーしか持たない表を「親と一緒に消える」と宣言し、
  削除責務クラスを書かないと RC-6 が点灯する
- NC-4: 「基準データ」の表が「定期実行が消す」表への外部キーを持つと RC-7 が点灯する

### 保証しないもの (指摘 5・6・8 の反映後)

```
 *   - **列の内容が個人情報かどうかは見ない**。単位は表であり、列は見ない
 *   - **実データが実際に消えることは保証しない**。各掃除バッチの behavioral テストの担当である
 *   - **`on delete cascade` の存在は「親が実際に消される」ことを意味しない**
 *   - **保持者の実在は「そのクラス / コマンドがその表を処理すること」を意味しない**。
 *     見ているのは識別先が実在することだけであり、**Schedule に配線されているかも見ない**
 *   - **行ごとの寿命の違いは表現しない**。単位は表なので、users のように
 *     「退会予約が入った行だけが消える」表も 1 つの区分に丸められる
 *   - **区分の意味が正しいかは人間のレビュー対象**である
 *   - S3 上の実体 / ビュー / 他スキーマの表は対象外である
 *   - 表と外部キーの読み取りは**現在のスキーマ**に限る (`search_path` の健全性は前提であって
 *     保証ではない)
```

### RC-8 (指摘 9 の反映後)

```php
/** 台帳の総件数 (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
const RETENTION_TABLE_COUNT = 63; // 実装時に実スキーマから確定する

/** 保持期限が**まだ決まっていない**表 (現在値ちょうど。増えるときも減るときもここを書き換える)。 */
const RETENTION_UNDECIDED_TABLES = [ /* 実装時に確定 */ ];
```

> **区分ごとの件数は pin しない**。全体件数と未確定の表名一覧があれば、
> 「台帳が空になった」「未確定が無音で増えた」の 2 つは捕まる。
> 区分ごとの件数まで pin すると、分類を 1 つ直すたびに数字の書き換えが要るだけで、
> 新しく捕まえられるものが無い。

## 質問

1. 2 件の [Critical] は解消したか。
2. 指摘 7 (RC-7 に FrameworkManaged を含める) と指摘 9 (区分ごとの件数 pin を落とす) の
   判断は妥当か。
3. 残る [Critical] / [Warning] があれば挙げてほしい。実装時に決めれば足りるものは
   [Suggestion] にしてほしい。

各施策の判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示すること。
