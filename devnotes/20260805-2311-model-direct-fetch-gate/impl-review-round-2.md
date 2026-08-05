**`tests/Support/Security/PrimaryKeyStaticQueryScanner.php`**

- [Critical] `queryResultVariables()` は依然として任意の static 呼び出しを「クエリ結果」と認定します。モデル解決も実行系メソッド確認もありません。

```php
$ids = Payload::ids();

foreach ($ids as $id) {
    User::find($id);
}
```

これで `IdDerivedFromSameMethodQuery` の副条件を通過できます。`staticRootAt()` で Model/DB 起点を確認し、実行結果を返す chain に限定する必要があります。

- [Critical] `provenModelVariables()` の relation 起点判定も任意オブジェクトの chain をモデルとして証明します。

```php
$dto = $input->payload()->dto();
User::find($dto->user_id);
```

`$dto` が proven model に入り、`User::find()` 自体が候補から消えます。これは inventory 登録すら要求されない fail-open です。relation 起点として受理するなら、chain の基底変数が既にモデルと証明されていることが最低条件です。

- [Critical] `identityAssignedFromRelationQuery()` にも同じ問題があります。

```php
$id = $input->payload()->value('id');
User::find($id);
```

これを `IdDerivedFromTenantScopedQuery` として登録すると機械副条件を通過します。relation 名らしいメソッドがあるだけではテナントスコープの証明になりません。

- [Critical] 動的列名 inventory の descriptor が値引数を含まず、`array_unique()` で重複を潰します。既存のレビュー済み呼び出しと同じメソッド内へ次を追加しても未知として検出されません。

```php
BillingNotification::query()->where($column, $payloadId)->first();
```

descriptor に値引数の fingerprint と ordinal を含め、重複時は通常候補と同様に明示レビューさせる必要があります。

- [Critical] 動的列名の array 形が inventory を素通りします。

```php
User::query()->where([$column => $payloadId])->first();
User::query()->where([[$column, '=', $payloadId]])->first();
```

`scanDynamicColumns()` は引数が2個未満だと除外するため、どちらも候補にも動的列名 inventory にも現れません。

- [Critical] array 形の否定演算子が検出されません。

```php
User::query()->where([['id', '!=', $payloadId]])->get();
User::query()->orWhere([['id', '<>', $payloadId]])->get();
```

`arrayFormPredicate()` が `=` しか受理しないためです。`IdentityExclusion` として固定してください。

- [Critical] raw SQL の `id` 判定が大文字小文字を区別します。PostgreSQL の未引用識別子では次の `ID` は `id` と同じですが、gate を通過します。

```php
User::query()->whereRaw('ID = ?', [$id])->first();
```

正規表現を case-insensitive にするか、SQL断片を小文字化してから照合する必要があります。

- [Warning] `literalIsInsideGuardedBlock()` は guard が条件式に属することや肯定条件であることを証明しません。

```php
$local = app()->isLocal();

if (true) {
    Route::post(...)->name('debug.login-as');
}
```

また `if (! app()->isLocal())` も受理します。`T_IF` の条件範囲と、その直後の本文ブロックを対応付けてください。

**`tests/Architecture/ModelDirectFetchInvariantTest.php`**

- [Warning] 直接形の `QueuePayloadRehydration` は、`enqueuedBy` メソッドの実在しか確認しません。そのメソッドが対象 Job を dispatch し、対象 property を渡していることは未検証です。構造化 field が実質的に無関係な既存メソッド名でも通ります。

- [Warning] `todoRef` のファイル形式は任意の既存ファイルを受理します。今回の専用追跡ファイルは妥当ですが、gate としては `AGENTS.md` 等でも通ります。許可するパスを専用ファイル、または限定した devnotes パターンへ絞るべきです。

**`tests/Support/Security/DirectFetchInventory.php`**

- [Warning] 動的列名 descriptor が値側を識別しないため、同一メソッド・同一 root・同一列変数の複数呼び出しに裁定理由が横滑りします。通常 inventory の fingerprint 方針と一致していません。

**`tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php`**

- [Critical] 以下の抜け道 fixture が不足しています。

  - `Payload::ids()` を走査元にした `sameMethodQuery`
  - 任意オブジェクトの chain 結果を proven model にする形
  - 動的列名の associative/nested array 形
  - 同一 dynamic descriptor の安全・危険な2呼び出し
  - array 形の `!=` / `<>`
  - `whereRaw('ID = ?')`
  - 否定された local guard

**その他のファイル**

`DirectFetchJustificationEntry.php`、`PrimaryKeyPredicateKind.php`、`PrimaryKeyStaticQueryCandidate.php` には、この Round で新たな単独指摘はありません。

CHANGES_REQUESTED