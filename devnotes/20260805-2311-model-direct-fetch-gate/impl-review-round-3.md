**`tests/Support/Security/PrimaryKeyStaticQueryScanner.php`**

- [Critical] provenance 判定が代入順序と再代入を考慮していません。メソッド全体から証明変数を先に集めるため、将来の安全な代入で過去の危険な値が安全扱いされます。

```php
$dto = $input;
User::find($dto->user_id); // 候補から消える

$dto = User::firstOrFail();
```

逆順の再代入でも同様です。

```php
$dto = User::firstOrFail();
$dto = $input;
User::find($dto->user_id); // 候補から消える
```

候補位置より前の reaching definition のみを使い、使用前の再代入で証明を失効させる必要があります。

- [Critical] `queryResultVariables` にも同じ時間順序の欠落があります。

```php
$ids = Payload::ids();

foreach ($ids as $id) {
    User::find($id);
}

$ids = User::pluck('id');
```

後段のクエリ代入によって `IdDerivedFromSameMethodQuery` が通ります。クエリ代入が `foreach` より前にあり、その間に再代入がないことを確認してください。

- [Critical] `identityAssignedFromRelationQuery()` も代入順序を無視します。

```php
$id = $input->id;
User::find($id);

$id = $project->manuals()->value('id');
```

この候補を `IdDerivedFromTenantScopedQuery` に分類すると副条件を通過します。安全な代入が候補より前にあり、使用まで再代入されていないことが必要です。

- [Critical] PHPDoc 型証明がファイル全体の変数名マップです。別メソッドの `@var` でも同名変数を証明します。

```php
public function safe(): void
{
    /** @var User $dto */
    $dto = User::first();
}

public function unsafe(object $dto): void
{
    User::find($dto->user_id); // 候補から消える
}
```

`@var` は同一スコープかつ対象代入の直近に限定する必要があります。

- [Critical] array 形は最初の主キーエントリしか返しません。

```php
User::where([
    ['id', '=', $trustedId],
    ['id', '=', $payloadId],
])->first();
```

`arrayFormPredicate()` が最初の一致で `return` するため、後続 identity は候補化されません。全エントリを候補として返す必要があります。

- [Critical] Laravelの直接取得 `findOr()` が検出集合にありません。

```php
User::findOr($payloadId, fn () => null);
```

`EXECUTOR_METHODS` には存在しますが、`predicateAt()` にはないため完全に素通りします。

**`tests/Architecture/ModelDirectFetchInvariantTest.php`**

- [Critical] 直接形 `QueuePayloadRehydration` は Job クラスの dispatch が存在することしか確認せず、dispatch 引数の provenance を検証しません。

```php
RehydrateUserJob::dispatch($request->integer('user_id'));
```

Job 側が `User::find($this->userId)` でも、`enqueuedBy` にこのメソッドを書けば副条件を通ります。「enqueue 時にサーバが確定した値」という case 名の条件を満たしていません。少なくとも dispatch 引数が request accessor 由来でないことを検査する必要があります。

- [Suggestion: v1既知限界] `literalIsInsideGuardedBlock()` は条件内に一つでも `!` があると拒否するため、guard と無関係な否定を含む安全な条件も fail-closed になります。セキュリティ上は安全側なので、docblockへの限界記録で十分です。

**`tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php`**

- [Critical] 次の fixture が不足しています。

  - 安全な代入が候補より後にある provenance
  - 安全な代入後、候補前に untrusted 値へ再代入
  - 別メソッドの同名変数に対する `@var`
  - array形に複数の `id` 条件
  - `User::findOr($payloadId)`
  - request値を直接 Jobへ dispatchする形

**その他のファイル**

`DirectFetchInventory.php`、`DirectFetchJustificationEntry.php`、`PrimaryKeyPredicateKind.php`、`PrimaryKeyStaticQueryCandidate.php` に単独の追加指摘はありません。

CHANGES_REQUESTED