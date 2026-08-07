仮説は「静的に解決できるキャッシュ受け手とヘルパが全てL1/L2へ到達し、L3のroleが未検出経路の抜け道にならないこと」です。`\cache` / `\app` の修正は正しく機能しますが、L3の`read-only`検証に1点残っています。

## S1: REQUEST_CHANGES

[Warning] `role=read-only`が実測メソッドと整合していることを検査していません。

現在の検査5では、`read-only`について確認するのは「L2 inventoryに書き込みentryがないこと」だけです。そのため、例えば静的に受け手を解決できない書き込みを含むファイルを、`read-only`として登録できます。

```php
use Illuminate\Cache\Repository;

(new Repository($store))->put('key', new stdClass, 60);
```

この例では型の`use`によりL3 surfaceには現れますが、`new Repository(...)->put()`はL2では追跡されません。`methods`は空のままなので、`role=read-only`で登録すると通過します。また、`Cache::lock()`だけのファイルを`read-only`と宣言することも可能です。

これは「L3がL1/L2の原理的な穴を粗い網で補う」「roleが実測と整合する」という設計上の主張と一致しません。

修正案: `read-only`について、少なくとも次を検査してください。

- メソッド実測が1件以上ある
- WRITEおよびTERMINALを含まない
- 許可するのはNON_WRITE、CHAIN、読み出し形の`cache`マーカーだけ

概念的には以下です。

```php
if ($entry['role'] === 'read-only') {
    $allowed = array_merge(
        CACHE_PAYLOAD_NON_WRITE_METHODS,
        CACHE_PAYLOAD_CHAIN_METHODS,
        ['cache'],
    );

    expect($methods)->not->toBe(
        [],
        "{$path}: role=read-only なのにキャッシュAPI呼び出しがありません",
    );
    expect(array_values(array_diff($methods, $allowed)))->toBe(
        [],
        "{$path}: role=read-only なのに読み出し以外のキャッシュAPIを呼んでいます",
    );
}
```

CHAINだけで終わる呼び出しを許すかは判断が必要ですが、少なくとも最終的なNON_WRITEまたは読み出し形`cache`が1件あることまで要求するとroleの意味がより正確になります。現時点では`read-only` entryが0件なので、今のうちに固定する方が低コストです。

[Suggestion] mutationの説明がまだM10で止まっています。

- 「下表 M1-M10」
- 実装順序の「mutationで赤化確認（M1-M10）」

実際の表はM1-M13なので同期してください。

### 走査確認

`\cache` / `\app`の完全修飾形は塞がっています。

- `\cache([...])`: `$callable='cache'`、`$isRootCallable=true`となりWRITEへ到達
- `\cache($values)`: 同じ分岐から`unclassified`へ到達
- `\app(Repository::class)->put()`: コンテナ解析から`followChain()`へ到達
- `\App\Support\cache(...)`: `$callable='app\support\cache'`となり除外

名前空間付き同名関数の除外による取りこぼしもありません。`\App\Support\cache()`はLaravelのグローバルヘルパとは別の関数です。非修飾`cache()`は名前空間内で同名関数が定義されている場合に過剰検出し得ますが、安全側の誤検出であり許容できます。

記載された限界も実装と一致しています。

- 完全修飾docblockだけの受け手: L1/L2/L3とも非検出
- 動的に得られる受け手: 型記号がなければL3でも非検出
- facade mock: WRITEには数えず、通常のFacade記号によりL3には出現

追加fixtureの期待値`writes=3 / unclassified=1`も実装ロジックと一致します。全21テストという数も、通常検査9本とfixture 12本の合計として正しいです。

## S2: APPROVE

戻り値shape、無効日時、14ケースへの更新はいずれも整合しています。PHPStan level 10上の新たな問題も見当たりません。

## S3: APPROVE

変更なし。宣言pinと実行時pinの責務分離は妥当です。

## S4: APPROVE

変更なし。規約訂正と採番維持は妥当です。

## S5: APPROVE

変更なし。番号競合時の同期手順も十分具体的です。

## 全体判定: CHANGES_REQUESTED

Round 2の指摘は全件解消されています。残る修正は、L3を補完層として成立させるための`read-only` role検証と、M1-M13への文言同期です。新しい走査機構の追加は不要で、この範囲は思考原則2にも反しません。