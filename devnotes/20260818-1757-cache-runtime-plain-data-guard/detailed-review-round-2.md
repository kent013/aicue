## Round 2 判定

Round 1 の主要指摘は大幅に改善されています。特に、反復計測、閉じた resource、accumulator の drain、起動前結線、ArrayAccess 走査、vendor token pin の方向性は妥当です。

ただし、静的 L4 の「0件 pin」と実行時自己テストが両立していないことなど、実装すると確実に gate または PHPStan が落ちる問題が残っています。

## 残る必須修正

### [Critical] L4「0件」と実際に迂回APIを呼ぶS5が矛盾する

S5は実行時テストとして以下を実際に呼びます。

- `Cache::extend()`
- `tags()`
- `setStore()`
- `macro()` / `flushMacros()`
- `new ArrayStore()`（`setStore()` 用の Store を作る場合）
- 場合によっては `getStore()`

一方、S7のL4a/L4bは、これらを `tests/` を含む走査根で「0件」と要求します。特に `Cache::extend()` はFacade受け手として確実に検出されます。

このままでは、実行時の負例を書いた瞬間にArchitecture gateが失敗します。

修正案:

- L4に厳密な `boundary-selftest` 目録を設け、S5の意図的呼び出しだけをパス・メソッド・件数でexact-fit登録する。
- 通常コードについては0件を維持する。
- または、guard付きサブクラスへの `tags/setStore/macro` 呼び出しは「迂回に成功しない自己テスト」として明確に別分類する。
- 「0件 pin」という文言は「通常経路0件＋名指し自己テストexact-fit」に修正する。

動的呼び出しでscannerを避ける方法は、検出力の裏取りを弱めるため採るべきではありません。

### [Critical] 非macroの `__call()` が未検査のStore経路を残す

設計はmacroでなければ次へ委譲します。

```php
return parent::__call($method, $parameters);
```

これは最終的に次の経路です。

```php
$this->store->$method(...);
```

「Repository自身が持つpayload APIは `__call()` に来ない」ことは、Store固有APIや将来追加されるAPIがpayloadを運ばない証明にはなりません。vendor/custom Storeに書き込みAPIがあれば、実行時guardを通らず書けます。

修正案:

- 原則として `__call()` はmacroか否かにかかわらず `reportBoundary()` でhard failさせる。
- 現行テストで必要な非macro呼び出しが露出した場合は、まず用途を分類する。
- 安全な非payload APIを残す必要がある場合も、無条件の親委譲ではなく、vendor実読と負例を伴う閉じた分類にする。ただし「許可一覧を持たない」方針との整合が必要です。

### [Critical] `setStore()` のシグネチャと「Store参照唯一サイト」が矛盾する

Laravel側が次のシグネチャなら、提示コードは「vendor宣言を1文字ずつ合わせる」という方針と一致しません。

```php
public function setStore(Store $store)
```

一方で完全互換にすると、`PlainDataGuardedRepository` も `Store` を参照するため、

> PlainDataGuardedCacheManager は Store を参照してよい唯一のサイト

というL4cの前提が崩れます。

修正案:

- 実vendor宣言に合わせた `setStore()` の完成シグネチャを設計書に掲載する。
- `Store` 型参照の許可サイトを、必要なら次の2箇所にexact-fitする。

  - managerの `repository(Store $store, ...)`
  - repositoryの `setStore(Store $store)`

- 各 `$store` の用途を別々の構造条件でpinする。
- native typeを意図的に外す場合は、PHPStan level 10で許容される根拠と、vendor完全一致という記述の訂正が必要です。

### [Critical] S5の共通assertion helperはglobal functionのままではautoloadされない

`tests/Support/Cache/CachePayloadViolationAssertions.php` にglobal functionを置いても、PSR-4は関数をautoloadしません。明示的な `require` またはComposerのfiles autoloadがなければ呼べません。

修正案:

```php
final class CachePayloadViolationAssertions
{
    /**
     * @param Closure(): mixed $callback
     * @param list<string> $expectedFragments
     */
    public static function expectViolation(
        Closure $callback,
        array $expectedFragments,
    ): void {
        // ...
    }
}
```

既存の `Tests\Support\Cache` PSR-4でautoloadできるクラスにしてください。

### [Critical] ArrayAccessの提示コードがPHPStanチェック欄と一致しない

helperの引数はcontractです。

```php
function cachePayloadGuardWrite(Repository $cache, ...)
```

しかし提示コードはそのまま添字アクセスしています。

```php
$cache[$key] = $value;
```

`Illuminate\Contracts\Cache\Repository` 自体が `ArrayAccess` を保証しない場合、PHPStanはこのコードを認めません。本文では「具体Repositoryへinstanceofで絞る」とありますが、コードにはありません。

修正案:

- helper引数を `Illuminate\Cache\Repository` にする。呼び出し前に `Cache::store()` の結果をinstanceofで絞る。
- またはArrayAccessの2 armだけ専用helperへ分離し、そこで具体Repositoryへ絞る。
- 修正後コードとPHPStanチェック欄を一致させる。

### [Critical] W5がvendor変更だけをpinし、ローカルの写しを比較していない

W5はvendorの `createApplication()` が期待token列と一致することを検査します。しかし、`tests/TestCase.php` のローカル実装がvendorを忠実に写しているかは検査していません。

現在のW1が保証するのは、guard呼び出しがbootstrapより前にあることだけです。ローカル側から以下を消しても、W1とW5は緑のままです。

- `$this->traitsUsedByTest` の代入
- cached config分岐
- cached routes分岐
- `return $app`

修正案:

- vendor token列のpinに加え、ローカル `createApplication()` のtoken列もpinする。
- ローカル側の許可差分を次に限定する。

  - 戻り値のfail-closed確認
  - `registerBeforeBootstrap()`
  - 必要なら戻り値型と属性

- 「vendor期待列へ許可差分を挿入した列」とローカル列を比較するか、ローカル期待列を別に完全一致で持つ。

### [Critical] L4dの `prev === T_IMPLEMENTS` 判定では複数interfaceを検出できない

次の形では `Store` の直前tokenはカンマであり、提示された判定から漏れます。

```php
final class CustomStore implements SomeInterface, Store
{
}
```

alias付き、完全修飾名、複数implementsも同様に考慮が必要です。

修正案:

- `T_EXTENDS` / `T_IMPLEMENTS` から宣言句全体を解析する。
- `implements` は `{` までのカンマ区切りをすべて完全修飾名へ解決する。
- 未解決の名前は候補から外さずfailさせる。
- 負例に最低限、次を追加する。

  - 2番目のinterfaceとしての `Store`
  - alias付き `Store`
  - 完全修飾名
  - 複数行のimplements

### [Warning] Store型の保証範囲の説明がまだ広すぎる

次のような、走査根外で宣言されたStore実装は名前規則から漏れます。

```php
new Vendor\Package\CacheBackend();
```

そのクラスがvendor内で `Store` を実装していても、FQCNが `Illuminate\Cache\*Store` に一致せず、app側の型宣言にも `Store` が現れなければ検出できません。

修正案:

「まったく型に現れない形」ではなく、次を明記してください。

> 走査根外で宣言され、FQCNが組み込みStore命名規則に一致しない第三者Store実装の直接生成・解決は検出しない。

可能なら、独自cache driver登録口を閉じる `extend` pinとの責務関係も併記してください。

### [Warning] 第2ApplicationでFacadeの「元の解決済みインスタンス」は復元されていない

提示された復元手順は、

```php
Facade::clearResolvedInstances();
Facade::setFacadeApplication($savedApplication);
```

であり、元のresolved instance集合を復元しているわけではありません。単に消去し、元Applicationから遅延再解決できる状態へ戻しています。

修正案:

- 主張を「元のresolved instancesを復元する」から「第2Applicationのresolved instancesを残さず、元Applicationから再解決可能にする」へ変更する。
- 検査22もobject identityの完全一致ではなく、Facade applicationが元へ戻り、再解決されたcache managerがguard付きであることを検査する。
- `PlainDataCacheGuard::reset()` と第2Applicationの後始末を`finally`へ含めることも明記する。

### [Warning] macro残存テストはglobal afterEachを直接は検証できない

検査16でmacroを本当に残してglobal afterEachへ到達させると、そのテスト自身が失敗します。

修正案:

- テスト内で `flushAndFailIfStray()` を明示的に呼び、`RuntimeException` と `MACRO_REGISTERED` を検査する。
- テスト名を「flushが残存macroを検出する」にする。
- Pest全レーンからflushが呼ばれることはS6へ委譲する。

### [Warning] `Cache::extend()` の前提が未確定のまま設計本文に断定されている

本文は「独自creatorはrepositoryを通らない」と断定していますが、同時に「振る舞いテストで実証できなければ説明を直す」としています。これは詳細設計上の未解決分岐です。

修正案:

- Laravel 12の `CacheManager::build()/resolve()` を実読し、実装前に結論を確定する。
- creatorが返す型もテストで明記する。Storeを返す通常creatorと、Repositoryを返す不正creatorを混同しない。
- repositoryを通る場合でも、正典上禁止するなら「迂回するから」ではなく「独自driverにより静的に把握できないStore面が増えるから」など、実態に合う根拠へ変更する。

### [Warning] `PRISM_PROMPT_CACHE` の「追跡下0件」は達成不能

追加するテスト自身に文字列があります。

```php
evaluateConfigFileWithEnv(
    'prism-prompt.php',
    ['PRISM_PROMPT_CACHE' => 'true'],
);
```

したがって追跡下全ファイルで0件にはなりません。詳細設計書を追跡する場合も文字列が残ります。

修正案:

- 0件ではなく、必要なテスト内の名指し1件などをexact-fitで管理する。
- または「実行設定・env例・config本体から除去する」と保証範囲を限定する。
- テストを回避するために文字列を動的連結する方法は採らないでください。

## 施策別判定

| 施策 | 判定 | 主な理由 |
|---|---|---|
| S1 | APPROVE | UNKNOWN_TYPE、nullの正典同期、境界値が整理された |
| S2 | REQUEST_CHANGES | 非macro `__call()`、`setStore()`型、Store参照箇所が未解決 |
| S3 | APPROVE | macro pin、RateLimiter前提、inspected resetが明確になった |
| S4 | APPROVE | vendor処理を保持して起動前に結線する方針は妥当 |
| S5 | REQUEST_CHANGES | L4との自己テスト衝突、function autoload、ArrayAccess型、Facade復元 |
| S6 | REQUEST_CHANGES | ローカルcreateApplicationの忠実性がpinされていない |
| S7 | REQUEST_CHANGES | L4自己テスト目録、複数implements、Store保証範囲が不足 |
| S8 | APPROVE | wave反復と累積一意ファイル数が明確 |
| S9 | REQUEST_CHANGES | `PRISM_PROMPT_CACHE` 0件条件が自己矛盾 |
| S10 | REQUEST_CHANGES | S2/S7の保証範囲確定後に文言の再調整が必要 |
| S11 | APPROVE | 実装後の実在差だけを登録する方針は妥当 |

UI、DTO、JsonResource、Inertia、Atomic Designは今回も該当ありません。

## 全体判定

**CHANGES_REQUESTED**

Round 1の設計上の大穴はかなり閉じました。残る中心課題は、実行時自己テストと静的0件pinの両立、非macro `__call()`、W5によるローカル写しの検証です。この3点を解消すれば、承認にかなり近い状態です。