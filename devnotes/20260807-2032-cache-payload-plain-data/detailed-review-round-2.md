仮説は「Round 1 の3経路が期待どおり検出され、同時に同等の別表記から書き込みが迂回できないこと」です。提示コードをトークン分岐に沿って追った結果、3件自体は塞がっていますが、ヘルパの完全修飾形に新しい bypass が残っています。

## S1: REQUEST_CHANGES

[Critical] `\cache(...)` がヘルパとして分類されず、配列書き込みを回避できます。

`T_NAME_FULLY_QUALIFIED` の `$token->text` は `\cache` ですが、判定は次のとおりです。

```php
$lower = strtolower($token->text);

if (! $isMemberName && $lower === 'cache') {
```

そのため、以下は `surface=true` にはなるものの、`writes`にも`unclassified`にも入りません。

```php
\cache(['key' => new stdClass], 60);
\cache($values, 60);
```

修正案: 呼び出し名の判定用に先頭の `\` を除去し、名前空間を含まないルート関数だけを対象にしてください。

```php
$callableName = strtolower(ltrim($token->text, '\\'));
$isRootCallable = ! str_contains($callableName, '\\');

if (! $isMemberName && $isRootCallable && $callableName === 'cache') {
    // ...
}
```

`app` / `resolve` / `make` も同様に `\app(...)`、`\resolve(...)` を処理する必要があります。恒久 fixture と mutation に完全修飾形を追加してください。

[Warning] M13 の期待結果「緑に戻る」は現在の分類ロジックと一致しません。

`getstore`をCHAINから削除すると、`Cache::getStore()`は`unclassified`になり、検査1が赤になります。書き込みを見逃してもテスト全体は緑になりません。

修正案: CHAINから削除するのではなく、CHAINからNON_WRITEへ移す mutation としてください。ただし新規ファイルではL3が赤になるため、既存の登録済み surface 内へ一時注入するなど、確認対象を明確にする必要があります。単純にはM13を削除し、恒久 fixture が `getStore` の分類退行を十分固定しています。

[Warning] docblock型の受け手について、保証範囲の説明が実装より広くなっています。

```php
/** @var \Illuminate\Contracts\Cache\Repository $cache */
$cache->put(...);
```

このようにimportも型宣言もない場合、`receiverNames`にもL3 surfaceにも入りません。「これもL3で面としては捕まる」は成立しません。

修正案: 「対応する型のimportが同じファイルにあればL3で捕捉されるが、完全修飾docblockだけの形は捕捉しない」と限界を正確に記述してください。完全に禁止するならdocblock解析が必要ですが、現スコープでは説明修正が妥当です。

[Suggestion] コメント見出しがまだ「検査9-14」ですが、本文と冒頭説明は「9-19」です。番号を同期してください。

### Round 1 Criticalの確認

- `cache($values, 60)`: 通常形は`unclassified`になり、塞がっています。
- `app(Repository::class)->put(...)`: `::class`確認と型解決を経て捕捉されます。
- `Cache::getStore()->put(...)`: `getstore`がCHAINになり、後続`put`まで到達します。

### followChainとfixture件数

`$rawName` / `$afterName`の制御フローに明確な破綻はありません。`Repository::class`が単独で`followChain()`へ渡されても、`T_CLASS`がメソッド名として受理されず空配列で戻るため無害です。

期待件数も提示コード上は整合します。

- facade等: writes 10件
- container/getStore/dynamic literal: writes 5件
- 判定不能形: unclassified 3件
- 未知API: unclassified 1件

変数動的ディスパッチをfail-closedにする判断も過剰ではありません。キャッシュ受け手まで確定している以上、動的メソッドを許す実益より書き込み迂回の危険が大きく、現状0件なら妥当です。

## S2: REQUEST_CHANGES

[Warning] `fxSnapshotPlainArray()`のコード例に、PHPStanチェック欄で宣言している戻り値shapeがありません。

現状は日本語コメントだけです。

```php
/** 正常系の素データ（cache に入る形そのもの）。 */
function fxSnapshotPlainArray(): array
```

修正案:

```php
/**
 * 正常系の素データ（cache に入る形そのもの）。
 *
 * @return array{rate: float, pair: string, source: string, fetched_at: string}
 */
function fxSnapshotPlainArray(): array
```

PHPStan level 10の成否に加え、`unset($data[$missing])`やdatasetによる代入時の型推論を安定させるためにも、設計どおり明記すべきです。

[Suggestion] 無効日時テスト追加後、テスト計画の「計13 assertion群」は古いままです。Pestのdataset展開単位なら現在は14ケースなので更新してください。

追加された`InvalidFormatException`テスト自体は妥当です。

## S3: APPROVE

宣言の存在と値を分けたpin、実行時pinとの責務分離ともに問題ありません。

## S4: APPROVE

旧allowlist方針の削除、採番維持、gateとDTO proofへの参照は整合しています。

## S5: APPROVE

番号競合時の同期対象が具体化されており、既存採番を変更しない方針も適切です。

## 全体判定: CHANGES_REQUESTED

Round 1のCritical 3件は解消されています。一方、S1には`\cache([...])`という同等機能の明確な静的走査 bypassが残っています。完全修飾ヘルパの正負コントロールを追加し、M13とdocblock保証範囲を訂正すれば承認可能です。