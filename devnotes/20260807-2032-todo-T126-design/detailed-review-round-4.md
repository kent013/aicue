## 全体判定

**CHANGES_REQUESTED**

Round 3 の指摘内容自体は反映されています。ただし、施策5のscannerに、許可対象のPestテストを必ず違反扱いにする矛盾が1件残っています。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1 | APPROVE |
| 2 | APPROVE |
| 3 | APPROVE |
| 4 | APPROVE |
| 5 | REQUEST_CHANGES |
| 6 | REQUEST_CHANGES |
| 7 | APPROVE |
| 8 | APPROVE |
| 9 | APPROVE |

## 残る指摘

### 施策5: file-scopeのPest closureが`null`帰属になり、許可siteと両立しない

[Critical] scanner仕様では「`null`帰属のsiteは違反」としています。一方、setterの許可対象である次のテストは、クラスではなくファイルスコープのPest closureです。

- `ExternalClientTimeoutServiceProviderTest.php`
- `AutoRechargeStripeCallBudgetTest.php`

これらのsetterはクラスscopeを持たないため、現在の規則では正しいsiteも`null`帰属となり、件数目録との比較以前に違反します。

修正案: 帰属規則を用途別に分けてください。

- R1〜R5の境界検査: `app/`の名前付きクラスへの帰属を要求する。匿名クラス内は専用の匿名scopeとして検出し、違反にする。
- R6のsetter検査: クラス帰属を要求せず、`相対パス × シンボル × site件数`を正本とする。
- R6の診断上のcallable名: 名前付きメソッドならメソッド名、Pestのファイルスコープclosureなら`{closure}`とする。
- 匿名クラス内のsetterもファイルと件数には含めるため、許可ファイル内であっても件数増加により検出できる。

「匿名クラス」と「ファイルスコープclosure」を同じ`null`に潰さず、scope種別を例えば次のように保持すると実装が安定します。

```php
enum ScanScopeKind
{
    case NamedClass;
    case AnonymousClass;
    case FileScope;
}
```

### 施策6: PHPDocの配置ではclosure引数がnarrowingされない可能性がある

[Warning] 次のPHPDocは`test()`呼び出しの直前にあり、匿名関数そのもののPHPDocとしてPHPStanに認識される保証がありません。

```php
/**
 * @param list<...> $responses
 */
test('...', function (array $responses, ...): void {
```

特に`$responses`はnative型が`array`なので、`CountingStripeHttpClient`が要求する`list<array{status:int, body:string}>`へ狭まりません。

修正案: PHPDocをclosureへ直接付けるか、closure冒頭でローカル変数をnarrowingしてください。実装時にPHPStanが認識する既存リポジトリのPest datasetパターンへ合わせるのが適切です。

```php
test(
    '既定接続の Stripe 呼び出しは予算を超えない',
    /**
     * @param list<array{status: int, body: string}> $responses
     * @param class-string<Throwable>|null $expectedException
     */
    function (
        array $responses,
        int $expectedCalls,
        AutoRechargeAttemptStatus $expectedStatus,
        ?string $expectedException,
    ): void {
        // ...
    },
)->with(...);
```

これは設計方針の変更ではありませんが、PHPStan level 10適合を設計書で主張する以上、PHPDocの付与先は明確にしておく必要があります。

Round 3のsetter件数、`Assert` import、`class-string<Throwable>`への型狭窄という修正内容そのものは妥当です。上記2点を直せば、設計上の残件は解消します。