# impl-review Round 3 (T133) — Round 2 指摘への対応

Round 2 の [Warning] (DNF 型) を修正した。**却下した指摘は無い**。

# 対応マトリクス: impl-review Round 2

Round 2 の指摘は 1 件 ([Warning])。Round 1 の 3 指摘は「すべて適切に解消」と確認された。

## [Warning] DNF 型 (`(A&B)|C`) の括弧を越えられず、型付きキャッシュ受け手を見落とす

- 判断: **対応する**
- 根拠: 指摘のとおり。`cachePayloadReceiverNames()` の skip 集合は `|` / `&` / `?` だけで
  `(` / `)` を含まないため、`(Repository&Marker)|FallbackCache $cache` の `$cache` が
  受け手名に登録されない。**深刻なのは「既に role=write のファイルへこの形で足された場合」**で、
  L3 (面) の集合も L2 の件数も変わらず**緑のまま通る**。しかも冒頭コメントは
  「型宣言 (引数 / プロパティ / promoted ctor param) を見る」、実装コメントは
  「union / nullable / intersection を跨いで」と書いており、**説明と実態が食い違っていた**。
  誇張の是正という意味でも直すべき指摘。
- 対応内容:
  - skip 集合に `(` / `)` を追加し、DNF 型の括弧を跨げるようにした。
  - **副作用を同時に封じた**: 括弧を無条件に跨ぐと `cache($values, 60)` や
    `new Repository($store)` の**引数**が受け手名として登録され、無関係な `$values->put()` を
    キャッシュ書き込みと誤検出する。型名の**直後が `(`** の場合は型宣言ではなく
    呼び出し / インスタンス化なので、その時点で走査を打ち切るガードを入れた。
    (誤検出は「目録を意味の無い儀式に変える」方向の劣化なので、見落としと同様に潰す)
  - 負のコントロール fixture を 1 本追加 (DNF の順序 2 通り: `(A&B)|C` と `C|(B&A)`)。
  - 正のコントロール fixture を 1 本追加 (呼び出し / インスタンス化の引数を登録しないこと)。
  - 実装コメントを「union / nullable / intersection / DNF の括弧を跨いで」に更新し、
    説明と実態を一致させた。
- mutation M17: **既に role=write の** `FxRateService` へ
  `public function mutationProbe((\Illuminate\Contracts\Cache\Repository&\Stringable)|\Illuminate\Contracts\Cache\Store $c): void { $c->forever('k', new \stdClass); }`
  を追加 → 検査 2 が赤 (27 tests / 1 failed) を実測。修正前ならこの形は緑のまま通っていた。
  revert 後に 27 tests passed を再確認済み。

---

## 変更後コード (cachePayloadReceiverNames の該当箇所)

```php
function cachePayloadReceiverNames(array $tokens, array $useMap): array
{
    $names = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }
        if (! in_array(cachePayloadResolveName($tokens[$i]->text, $useMap), CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
            continue;
        }
        // 型宣言の直後 (union / nullable / intersection / DNF の括弧を跨いで) 最初に現れる変数
        $j = cachePayloadNext($tokens, $i + 1);
        // ★直後が `(` なら型宣言ではなく**呼び出し / インスタンス化** (`cache($values, 60)` /
        //   `new Repository($store)`)。ここを跨ぐと引数の変数が受け手名として登録され、
        //   無関係な `$values->put()` を cache 書き込みと誤検出する (impl-review Round 2 反映)。
        if ($j !== null && $tokens[$j]->text === '(') {
            continue;
        }
        while ($j !== null && (
            $tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            || in_array($tokens[$j]->text, ['|', '&', '?', '(', ')'], true)
        )) {
            $j = cachePayloadNext($tokens, $j + 1);
        }
        if ($j !== null && $tokens[$j]->is(T_VARIABLE)) {
            $names[] = ltrim($tokens[$j]->text, '$');
        }
    }

    return array_values(array_unique($names));
}
```

## 追加した fixture (2 本)

```php
test('負のコントロール: DNF 型 ((A&B)|C) で宣言された受け手も解決する', function (): void {
    // ★DNF 型の `(` / `)` を跨げないと、既に role=write のファイルへこの形で書き込みを
    //   足しても L2 の件数も L3 の集合も変わらず素通りする (impl-review Round 2 [Warning] 反映)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    interface Marker {}
    class Fixture {
        public function write((Repository&Marker)|FallbackCache $cache): void {
            $cache->put('a', [1], 60);
        }
        public function writeReversed(FallbackCache|(Marker&Repository) $other): void {
            $other->forever('b', [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(2);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('正のコントロール: 呼び出し / インスタンス化の引数を受け手名に登録しない', function (): void {
    // ★DNF 対応で `(` を跨ぐようにした副作用を封じる。`cache($values, 60)` や
    //   `new Repository($store)` の引数まで受け手扱いすると、無関係な `$values->put()` を
    //   キャッシュ書き込みと誤検出する (誤検出は目録を意味の無い儀式に変える)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function run(array $values, $store): void {
            cache($values, 60);
            $values->put('k', 'v');
            $repo = new \Illuminate\Cache\Repository($store);
            $store->put('k', 'v');
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toHaveCount(1); // cache($values, 60) だけ
});
```

---

## 再実測

- gate 単体: **27 tests passed / 0 failed** (fixture 2 本追加で 25 → 27)
- `composer test` (全件・グローバルロック配下・`--parallel`):
  `tests=3731 passed=3729 skipped=2 failed=0 assertions=15039`
- `composer phpstan` (level 10, 809 files): `No errors`
- `vendor/bin/pint --test`: `passed`
- 追加 mutation M17 (**既に role=write の** `FxRateService` へ DNF 型で受け手を宣言し
  `$c->forever('k', new \stdClass);` を書く) → 検査 2 が赤 (27 tests / 1 failed) を実測。
  修正前ならこの形は緑のまま通っていた。revert 後に 27 passed を再確認済み。

## 判定のお願い

Round 1 の [Critical] ×2 / [Warning] ×1、Round 2 の [Warning] ×1 をすべて修正した。
最終判定 (**APPROVED** / **CHANGES_REQUESTED**) を明示してほしい。

なお本件は「アプリの振る舞いを 1 行も変えない」施策であり、`app/` `config/` `routes/`
`resources/` への差分はゼロである (差分は新規テスト 2 本 + 既存テストへの追記 + 文書 2 箇所)。
機能追加の提案ではなく、**この差分がマージ可能か**の判定を求めている。
残る限界 (payload の式の型は静的に見ない / 束縛名が変数のコンテナ解決 / docblock 型 /
group use / Mockery 系 TERMINAL) は冒頭コメントに「保証しないもの」として明記済みで、
これらを閉じることは今回のスコープではない (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
