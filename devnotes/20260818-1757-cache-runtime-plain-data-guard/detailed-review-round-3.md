## Round 3 判定

Round 2 の主要な設計欠陥は解消されています。特に `__call()` の無条件hard fail、W5b、自己テスト目録、autoload可能なassertion helperは妥当です。

ただし、実装順とL4自己テストの検出方法に、実際には全テストを緑にできない不整合が残っています。

## 必須修正

### [Critical] 実装順どおりではS5/S6を緑にできない

現在の順序は次のとおりです。

1. S5/S6の負例
2. S1〜S4を実装し、S5/S6を緑
3. S8で `composer test`
4. S7を実装

しかしS5を追加した時点で、既存の静的gateは次の新規経路・面を検出します。

- S5の `put`、`add`、`remember` など
- BootTimeCacheWriteProbeProvider
- 新しいキャッシュsurface
- ArrayAccess書き込み
- `Cache::extend()` 等の境界自己テスト

L2/L3目録と語彙を変更するS7が未実装なので、既存の `CachePayloadPlainDataGateTest` が失敗します。したがって、手順2の「S5/S6が緑」も、手順3の全 `composer test` も達成できません。

修正案:

1. S5/S6/S7の負例を先に書き、対象テストを期待した理由で赤くする
2. S1〜S4とS7を実装する
3. 新規振る舞い検査・結線gate・既存静的gateを緑にする
4. S8の全レーン反復計測へ進む
5. S9〜S11

S7をS8より後に置く必要があるなら、S7を「目録・新規サイト受け入れの最小変更」と「露出是正後の確定」の二段階に分ける必要があります。

### [Critical] L4自己テストが必ず検出される設計になっていない

自己テスト目録を追加したこと自体は正しいですが、`tags()` / `setStore()` / 未知メソッドを呼ぶ変数が、静的scannerの受け手として解決される保証がありません。

既存scannerの受け手名は型宣言から作られます。次のようなテストでは、`instanceof` や代入から型を伝播しない限り `$cache` は受け手になりません。

```php
$cache = Cache::store('array');

$cache->tags(...);
$cache->setStore(...);
$cache->unknownMethod(...);
```

また、変数を `PlainDataGuardedRepository` 型にしても、同クラスは現在の `CACHE_PAYLOAD_RECEIVER_TYPES` に含まれず、scannerは継承関係から受け手を解決しません。

その場合、自己テスト目録に登録しても実測件数0となり、exact-fit gateが失敗します。

修正案:

- 境界テスト用helperの引数を、scannerが認識する `Illuminate\Cache\Repository` に型付けする。

```php
function exerciseGuardedBoundary(
    Repository $cache,
    string $operation,
    mixed $argument = null,
): void {
    // tags / setStore / 未知メソッド
}
```

- または `PlainDataGuardedRepository` を「自己テスト内の呼び出し受け手」として明示的に解決する専用分岐を設ける。
- 負のコントロールだけでなく、実在するS5ソースを収集した結果が自己テスト目録の全entryと一致することを検査する。
- `storePassthrough` の自己テストが `unclassified` ではなく、意図した境界自己テストとして分類される規則も明記する。

### [Warning] `new` の自己テスト目録keyは短名ではなくFQCNにするべき

現在の形式は次です。

```php
{相対パス}::new {型の短名}
```

これはaliasや同名クラスを区別できず、AGENTS.mdの「クラス参照は完全修飾名で突き合わせる」に反します。

修正案:

```php
tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::new Illuminate\Cache\ArrayStore
```

scannerが解決したFQCNを目録keyに使用してください。未解決なら目録照合へ進めず、`unclassified` として失敗させます。

### [Warning] W5bでは `#[Override]` を別途pinする必要がある

`ReflectionMethod::getStartLine()` から切り出したソースに、メソッドの前にあるattribute行が含まれる保証はありません。そのため、token列比較だけでは `#[\Override]` の削除を検出できない可能性があります。

修正案:

- token走査はメソッドを含むclassファイル側からattribute開始位置まで切り出す、または
- reflectionで別途検査する。

```php
$attributes = $method->getAttributes(Override::class);

expect($attributes)->toHaveCount(1);
```

併せて、許可差分のtoken列が「一度だけ」「期待する位置」に存在することを検査してください。単純な部分列除去だけでは、別位置に同じ列を置いても通る可能性があります。

### [Warning] 境界自己テストを登録できるパスが広い

次の条件では、将来追加された任意の `tests/Support/Cache/*.php` が自己テストを名乗れます。

> `tests/Support/Cache/` 配下

目録登録によってレビューには現れますが、「免除の口にならない」という表現は過大です。

修正案:

- 登録可能なファイルを名指しの集合に固定する。
- 新しい補助ファイルを追加する場合は、許可ファイル集合と負例も同じ変更で更新する。
- 少なくとも `tests/Support/Cache/` 配下すべてではなく、実際に境界APIを呼ぶ自己テストhelperだけを許可する。

### [Warning] 第三者Storeについての「唯一の登録口」は不正確

S7は次の両方を述べています。

- `new Vendor\Package\CacheBackend()` は検出しない
- そうした面が増える唯一の登録口は `Cache::extend()`

しかし、前者は `Cache::extend()` を使わず直接生成できるため、「唯一」ではありません。

修正案:

> `Cache::extend()` のpinは、CacheManager経由で第三者Store面を追加する経路を閉じる。走査根外の第三者Storeを直接生成・独自コンテナbinding等で取得する経路までは保証しない。

と限定してください。

### [Warning] S10の保証正本の説明が自己矛盾している

AGENTS.md本文に `getStore()` の非保証を書いた直後に、

> 保証しないものの正本は実行時層のdocblockであり、本書には写さない

としています。実際には重要な非保証を本書へ写しています。

修正案:

> 主要な境界例外として `getStore()` だけをここにも記す。網羅的な保証外一覧の正本は実行時層のdocblockとする。

のように役割を分けてください。

### [Warning] S11の「0件pin」がS7の新しい方式と不一致

D30案では依然として、

> macro系 / 具体storeの生成 / 受け手型の継承も0件pin

とありますが、S7では「通常経路0件＋自己テストexact-fit」です。

修正案:

D30も同じ表現に統一してください。

## 施策別判定

| 施策 | 判定 | 理由 |
|---|---|---|
| S1 | APPROVE | 型分類、上限、nullの正典同期は妥当 |
| S2 | APPROVE | `__call()` hard failとvendor互換シグネチャが明確 |
| S3 | APPROVE | accumulator、macro、RateLimiterの責務が明確 |
| S4 | APPROVE | vendor処理を保持した起動前結線は妥当 |
| S5 | REQUEST_CHANGES | 境界自己テストの受け手解決を明示する必要がある |
| S6 | REQUEST_CHANGES | `#[Override]` と許可差分の位置pinが不足 |
| S7 | REQUEST_CHANGES | FQCN目録、自己テスト検出、保証文言の修正が必要 |
| S8 | APPROVE | 反復計測自体は妥当。ただし実装順を変更する必要あり |
| S9 | APPROVE | 保証範囲が現実的に限定された |
| S10 | REQUEST_CHANGES | 保証正本の説明を整理する必要あり |
| S11 | REQUEST_CHANGES | 0件pinの表現をS7と同期する必要あり |

UI、DTO、JsonResource、Inertia、Atomic Designは該当なしです。

## 全体判定

**CHANGES_REQUESTED**

設計の中心部分は承認に近い状態です。残る実質的なブロッカーは、S7を後回しにできない実装順の問題と、L4自己テストが静的scannerで確実に観測されることの設計不足です。ここを直せば、次ラウンドでAPPROVEDに到達できる見込みです。