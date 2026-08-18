Round 2 の主要な問題はほぼ解消されています。ただし、名前解決と wiring の try/finally 対応関係にまだ検出漏れがあります。

### `tests/Architecture/CacheGuardWiringGateTest.php`

判定: 修正が必要です。

[Warning] `try` と `finally` が同じ構文に属することを確認していません。現在は afterEach 内で最初に見つけた `try` ブロックと最初に見つけた `finally` ブロックを独立に採用しています。

たとえば次の形でも、flush と reset はそれぞれ取得した範囲内にあるため通り得ます。

```php
try {
    PlainDataCacheGuard::flushAndFailIfStray();
} catch (Throwable) {
    return;
}

if (false) {
    try {
        // unrelated
    } finally {
        PlainDataCacheGuard::reset();
    }
}
```

この場合、flush が投げると catch の `return` で reset に到達しません。選択した `try` の対応する `catch` 群と `finally` を解析し、その try 文自身に属する finally であることを固定してください。この形の負例も必要です。

[Warning] W4 は動的な Pest trait 適用を保証外にしていますが、W4 の上位主張は依然として「対象 trait を適用するテストが0件」です。

```php
$trait = WithCachedConfig::class;
uses($trait);
```

これは保護対象の状態を作れるため、AGENTS.md 規約 (b) 上、単に保証外へ書くだけでは足りません。`uses()` の引数に静的に解決できない値があれば未分類として落とすのが妥当です。通常の `uses(TestCase::class, SomeTrait::class)` はすべて解決可能なので、誤検出も限定できます。

その他の W1/W5/W6、クロージャ範囲判定、group use 対応、負例の判定関数経由は適切に改善されています。

### `tests/Architecture/CachePayloadPlainDataGateTest.php`

判定: 修正が必要です。

[Critical] 同一 namespace 内の短名を完全修飾名へ解決できず、guard 実装クラスの継承禁止を迂回できます。

現在の `cachePayloadInheritanceClause()` は `cachePayloadResolveName($name, $useMap)` だけを呼んでおり、現在 namespace を渡していません。そのため次の合法なコードでは、`PlainDataGuardedRepository` が `Tests\Support\Cache\PlainDataGuardedRepository` へ解決されず、短名のまま比較される可能性があります。

```php
namespace Tests\Support\Cache;

final class BypassRepository extends PlainDataGuardedRepository
{
}
```

追加された負例は別 namespace から `use` で取り込む形なので、この経路を固定していません。

これは「合法な未解決形は存在しない」という整理への反例でもあります。PHP文法上は名前でも、scanner が namespace を考慮しなければ完全修飾名へ解決できません。次を同じ変更で揃える必要があります。

- namespace 宣言を抽出する
- import にない非完全修飾名を現在 namespace 相対で解決する
- `namespace\Foo` の `T_NAME_RELATIVE` を処理するか、未分類として確実に落とす
- 同一 namespace 短名の負例を追加する
- 完全修飾名・alias・同一 namespace の3形が同じFQCNになる正例を置く

[Suggestion] 動的 `new` を走査根全体の exact-fit 目録へ移した判断は妥当です。4ファイル5件で用途も明確であり、Round 2 の問題は解消されています。

[Suggestion] `CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY` はファイル単位の件数なので、同じファイル内で許可済み生成をキャッシュ store の生成へ置き換えても件数だけなら維持できます。ただし、この目録は payload と同じく人間の申告をレビュー対象にする設計なので、現時点では blocking とはしません。docblockで「用途は機械検証せず rationale は人間の申告」と明記すると保証が正確になります。

### `docs/app-integration-guide.md`

判定: 修正が必要です。

[Suggestion] 対応マトリクスでは継承・実装を別目録へ分けたとありますが、提示差分では依然として

> 直接生成 / 継承 / macro 登録は、自己テストだけを名指しの目録へ exact-fit

と読める状態です。AGENTS.md と同様に、継承・実装は別目録で guard 実装2本だけを許すと明記してください。

### `docs/architecture.md`

判定: 問題なし。

「一致」から「部分集合」への訂正は実装と合っています。

### `docs/template-divergence.md`

判定: 軽微な修正が必要です。

[Suggestion] 「揃え続ける不変条件と保証機構」が L4a–L4g のままで、新設した動的生成目録 L4h を含んでいません。また、表の「迂回の判定」は自己テスト目録だけを説明しており、動的 `new` の非キャッシュ用途目録が表現されていません。

### `devnotes/20260818-1757-cache-runtime-plain-data-guard/runtime-exposure.md`

判定: 問題なし。

計測記録と「部分集合」への訂正は妥当です。

### その他の実装ファイル

判定: 問題なし。

実行時 guard、設定固定、配列キー形のテスト、動的 `new` の全走査、guard 実装型の継承禁止、全検証結果について新たな問題は見つかりません。

継承の未解決分岐については、「PHP構文上は名前しか書けない」は正しいものの、「scanner が合法な名前を必ずFQCNへ解決できる」とは限りません。同一 namespace の短名が今回の具体例です。したがって、不正構文の合成負例だけでなく、合法な名前解決の全経路を正負コントロールで固定する必要があります。

CHANGES_REQUESTED