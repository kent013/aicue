## Round 4 判定

設計と実装時の実測をS8で分担する方針は妥当です。露出件数や未知のvendor利用を設計段階で推測せず、fail-closedのguardを入れて反復計測し、解消不能なら設計へ差し戻す流れになっています。

ただし、境界macro自己テストの後始末に、現状のコードでは全レーンを緑にできない不整合が残っています。

## 必須修正

### [Critical] `callMacro()` がmacroを残し、global afterEachで失敗する

提示コードはmacroを登録して呼び出しますが、削除していません。

```php
public static function callMacro(Repository $cache): void
{
    Repository::macro('guardProbeMacro', fn (): bool => true);
    $cache->guardProbeMacro();
}
```

guardの `__call()` が例外を投げるため、通常の後続行として `flushMacros()` を置いても実行されません。`expectViolation()` がdrainするのはaccumulatorだけで、macroのstatic状態は残ります。

その結果、global afterEachの `pinMacros()` が `MACRO_REGISTERED` を記録し、意図的負例が二重に失敗します。

また、境界APIの呼び出しを `GuardedBoundaryProbe.php` のみに制限しているため、テスト本体の `finally` から `Repository::flushMacros()` を呼ぶとL4fに違反します。

修正案:

```php
public static function callMacro(Repository $cache): void
{
    Repository::macro('guardProbeMacro', fn (): bool => true);

    try {
        $cache->guardProbeMacro();
    } finally {
        Repository::flushMacros();
    }
}
```

これにより、例外を維持したままmacroを必ず復元でき、`flushmacros` も名指し自己テスト目録に現れます。

残存macroを検査する検査16については、別メソッドで意図的に登録だけを行い、テスト内の `flushAndFailIfStray()` が検出・復元する形に分けてください。

### [Critical] Probeメソッドの `@return never` が実際の型契約と一致しない

例では次のようになっています。

```php
/** @return never */
public static function callTags(Repository $cache): void
{
    $cache->tags(['t']);
}
```

引数のnative型は通常の `Illuminate\Cache\Repository` です。通常Repositoryの `tags()` は返り得るため、このメソッドが型として常にthrowする保証はありません。実行時にguard付きサブクラスを渡すことと、静的なメソッド契約は別です。

PHPStanが「neverなのに到達可能」と判断する可能性があり、契約としても不正確です。

修正案:

- `@return never` を削除し、native `void` のままにする。
- 「guard付きRepositoryを渡した場合に例外になる」ことはS5の振る舞いテストで保証する。
- `callSetStore()`、`callUnknownMethod()`、`callMacro()`も同様にする。

## Warning

### [Warning] `resolveCustomDriver()` がFacadeと引数managerの同一性に暗黙依存している

現在は登録先がFacade、解決先が引数です。

```php
Cache::extend('guard-probe', ...);

return $manager->store('guard-probe');
```

Facade rootと `$manager` が異なると、`extend()` の前提ではなく別インスタンス問題でテストが落ちます。

修正案:

```php
$manager->extend(
    'guard-probe',
    fn (): Repository => new Repository(new ArrayStore),
);

return $manager->store('guard-probe');
```

`CacheManager` はscannerの受け手型なので、静的L4の検出力は維持できます。Facade経由を使う必要があるなら、事前にFacade rootと引数managerが同一であることをassertしてください。

### [Warning] 施策一覧のS5補助ファイル数が古い

S5は現在、次の4本を追加します。

- `BootTimeCacheWriteProbeProvider.php`
- `IsolatedApplicationProbe.php`
- `CachePayloadViolationAssertions.php`
- `GuardedBoundaryProbe.php`

施策一覧は「補助3本」のままです。波及変更の漏れにつながるため「補助4本」へ修正してください。

### [Warning] L4fのテスト名が旧表現のまま

本文は許可先を1ファイルに固定していますが、検査名は次のままです。

> 自己テスト目録の key は許可された2つの置き場所にしか無い

「`GuardedBoundaryProbe.php` ちょうど」に合わせて名称も修正してください。

## 施策別判定

| 施策 | 判定 | 理由 |
|---|---|---|
| S1 | APPROVE | 型分類と境界検査は妥当 |
| S2 | APPROVE | 標準APIと境界迂回の分離が明確 |
| S3 | APPROVE | accumulator・macro pin・後始末は妥当 |
| S4 | APPROVE | 起動前結線とvendor同期の方針は妥当 |
| S5 | REQUEST_CHANGES | macro自己テストの後始末と戻り値契約に修正が必要 |
| S6 | APPROVE | vendor/local token pinと属性pinは十分 |
| S7 | REQUEST_CHANGES | macro自己テスト目録がS5修正に連動する |
| S8 | APPROVE | 実装時の反復計測へ委ねる分担は妥当 |
| S9 | APPROVE | 設定hardeningと保証範囲は妥当 |
| S10 | APPROVE | 主要例外と網羅的正本の役割分担は明確 |
| S11 | APPROVE | 通常経路0件＋自己テストexact-fitに統一済み |

## 全体判定

**CHANGES_REQUESTED**

設計の構造的な課題はほぼ解消されています。`callMacro()` の `finally` 復元と、Probeメソッドの不正確な `@return never` を直せば、承認可能な状態です。