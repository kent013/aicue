前提: 提供 diff のみでレビュー。申告テスト結果は再実行していません。

**AGENTS.md / docs/app-integration-guide.md / docs/architecture.md**
判定: 問題なし。gate 名の追記は設計意図と一致しています。

**app/Enums/Security/DirectFetchJustification.php**
[Warning] `IdDerivedFromSameMethodQuery` / `IdSuppliedByInternalCaller` の追加自体は申告されていますが、どちらも機械証明が弱い case です。現状の副条件実装だと「deny-by-default の分類語彙」として濫用しやすく、後述の scanner 側修正が必要です。

**tests/Support/Security/PrimaryKeyStaticQueryScanner.php**
[Critical] 検出漏れがあります。`predicateAt()` が `where` / `whereIn` 系の一部だけを見るため、次が素通りします。

```php
User::query()->orWhere('id', $payloadId)->first();
User::query()->orWhereIn('id', $ids)->get();
User::query()->whereNotIn('id', $ids)->get();
User::query()->where('id', '!=', $payloadId)->first();
```

[Critical] 動的列名が out-of-scope のままですが、Architecture 側で 0 件固定されていません。次は候補にも raw guard にも出ません。

```php
$column = 'id';
User::query()->where($column, $payloadId)->first();
```

[Critical] `importsOf()` が group use / 複数 import を無視するため、モデル解決に失敗して候補が消えます。

```php
use App\Models\{User};
User::find($payloadId);

use App\Models\User, App\Models\Project;
User::find($payloadId);
```

[Critical] raw guard が quoted identifier と raw variant を漏らします。

```php
User::query()->whereRaw('`id` = ?', [$id]);
User::query()->whereRaw('"id" = ?', [$id]);
User::query()->orWhereIntegerInRaw('id', $ids);
User::query()->whereIntegerNotInRaw('id', $ids);
```

[Critical] `queryResultVariables()` は `$obj->method()` を relation/query result とみなします。`IdDerivedFromSameMethodQuery` の副条件が実質「任意 object の method 結果を foreach しただけ」で通ります。

```php
$ids = $input->ids();
foreach ($ids as $id) {
    User::find($id);
}
```

[Critical] `whereKey(...)->delete()` が `DestructiveIdentity` になりません。`QueuePayloadRehydration + DestructiveIdentity` を禁止しても、次は `SingleIdentity` として通せます。

```php
User::query()->whereKey($this->userId)->delete();
```

**tests/Architecture/ModelDirectFetchInvariantTest.php**
[Warning] `LocalOnlyDiagnostics` の登録条件確認が、routes 全体に `isLocal` / `runningUnitTests` 文字列があるかだけです。対象 route がその条件内にあることを証明していません。

[Warning] delegated `QueuePayloadRehydration` は job source に `->methodName(` があることだけを見ており、job property の id がその引数として渡っていること、`enqueuedBy` の method が実在することを検証していません。

**tests/Support/Security/DirectFetchInventory.php**
[Warning] 債務 case の `todoRef` が TODO ID ではなく概念設計ファイルを指しています。事情は申告されていますが、債務追跡としては弱く、設計の「追跡不能にしない」意図から逸脱しています。

**tests/Support/Security/DirectFetchJustificationEntry.php**
判定: 構造化 field の型付け方針は概ね妥当です。ただし `todoRef` にファイルパスを許す仕様変更は上記 inventory 側の Warning と同じリスクを持ちます。

**tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php**
[Critical] 抜け道 fixture が不足しています。最低限、`orWhere*`、group/multiple use、動的列名の実コード 0 件 guard、quoted raw id、`whereKey()->delete()`、`sameMethodQuery` の任意 object method 誤受理を固定してください。

CHANGES_REQUESTED