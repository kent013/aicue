## Round 4 判定

Round 3 の指摘である「探索範囲を当該 `beforeEach(...)` / `afterEach(...)` の引数内へ閉じる」は解消しています。ただし、引数内の任意のネストしたclosureをcallback本体と誤認できる穴が残っています。

### S1

**判定: APPROVE**

前回までの指摘は解消済みです。

### S2

**判定: APPROVE**

自己完結したinstall、case D、vendor契約の固定に問題はありません。

### S3

**判定: APPROVE**

追加変更による後退はありません。

### S4

**判定: REQUEST_CHANGES**

[Warning] `beforeEach(...)` の引数内にある任意の `function` を拾う設計では、引数そのものがclosureであることを保証できません。

現在の手順は次のとおりです。

> 取り出した引数内で `function` トークンに続く最初の `{` を探す

これでは、例えば次を配線済みと誤認できます。

```php
->beforeEach(wrap(function (): void {
    StrayHttpRequestGuard::install($this->app);
}))
```

`beforeEach` に渡されているのは `wrap(...)` の戻り値であり、検出されたclosureが実際にPestのhookとして登録される保証はありません。同様に、配列や別の関数呼び出しの内部にあるclosureも拾えます。

修正案:

- 引数内の先頭の有意トークンが `T_FUNCTION` であることを要求する。
- `static function` を許容するなら、先頭の `T_STATIC` に続く有意トークンが `T_FUNCTION` であることを許可する。
- 空白以外の接頭構文がある場合は `null` を返してfail-closedにする。
- `T_FUNCTION` に対応するclosure本体の `{` を構文深度に基づいて取得する。
- 引数にカンマ区切りの追加引数がある形も許可しないなら、その判断を契約に明記する。

次の負のコントロールを追加してください。

```php
test('負のコントロール: beforeEach 引数内のネストした closure を本体と誤認しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(wrap(function (): void {
            StrayHttpRequestGuard::install($this->app);
        }))
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressCode($fixture)),
    );

    expect(implode("\n", $violations))->toContain('install');
});
```

Round 3 の「後続closureを拾う問題」は直っていますが、「対象引数内ならどのclosureでもよい」という一段内側の問題が残っています。

[Suggestion] 「負のコントロール計11本」とありますが、提示された一覧は13本です。設計書の件数を実際の一覧へ同期してください。挙動には影響しません。

[Suggestion] 実装時は「`function` トークン」を単なる文字列検索で判定しないでください。正規化後も文字列内容の `function` という語自体は残るため、`PhpToken` のトークンIDで `T_FUNCTION` を確認する必要があります。

### S5

**判定: APPROVE**

文書上の保証範囲も適切です。UI/frontend変更はなく、DESIGN.mdおよびAtomic Designは非該当です。

### S6

**判定: APPROVE**

変更ありません。

## 全体判定

**CHANGES_REQUESTED**

Round 3 の必須修正は実質的に反映されています。残る必須修正は、`beforeEach(...)` / `afterEach(...)` の**引数そのものがclosureであること**を確認し、ネストしたclosureをhook本体として認めないことです。