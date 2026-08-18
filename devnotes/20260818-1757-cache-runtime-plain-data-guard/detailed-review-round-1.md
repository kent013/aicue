## レビュー仮説

本設計の成否は、次の仮説で評価しました。

> `Repository` 境界を起動前に差し替えれば、実行されたすべてのキャッシュ書き込み値を検査でき、静的層がその境界を迂回する経路を漏れなく禁止できる。

成功条件は、直接 `Store` に到達する経路、マジックメソッド、macro、独自 driver、テスト用の意図的違反を含めても、無検査の書き込みが成立しないことです。

現状の詳細設計には、この仮説を崩す経路と、テストスイートを緑にできない不整合が残っています。

## 主要な指摘

### [Critical] `Store` への直接到達を L4 が閉じ切れていない

`CACHE_PAYLOAD_RECEIVER_TYPES` には `Illuminate\Contracts\Cache\Store` が含まれていますが、L4 が禁止するのは主に `getStore()`、直接 `new Repository`、`new CacheManager` です。

次の経路は実行時 guard を通りません。

```php
public function __construct(Store $store) {}

$store->put('key', new stdClass(), 60);
```

```php
$store = new ArrayStore();
$store->put('key', new stdClass(), 60);
```

また、`PlainDataGuardedRepository` が継承する以下も境界迂回になります。

- `getStore()` / `setStore()`
- `Repository::__call()` から `$this->store->$method(...)`
- macro のクロージャから `$this->store` への直接アクセス

静的層は vendor を走査しないため、vendor 由来の直接 `Store` 利用も両層から漏れます。

修正案:

- `PlainDataGuardedRepository` で `getStore()`、`setStore()`、`tags()`、`__call()` を compatible signature のまま override し、`reportBoundary()` で hard fail させる。
- `__call()` を閉じれば、macro の登録・使用・即時削除も使用時点で検出できる。
- 静的層では `Store` contract の注入・型宣言・解決、具体 Store の直接生成を、`PlainDataGuardedCacheManager::repository()` の厳密な一箤所以外禁止する。
- `ArrayStore`、`DatabaseStore` などの concrete Store も検出対象にする。単なる型名リストではなく、既知の実装一覧または継承関係の解決が必要。
- `Cache::extend()` が本当に `repository()` を迂回するか、実際の Laravel 12 の `build()/resolve()` を固定する振る舞いテストを置く。通過するなら「迂回するから禁止」という説明を修正する。

### [Critical] 意図的違反テストが accumulator を残し、afterEach で失敗する

S5 の負例は `CachePayloadViolation` を期待して捕捉しても、違反が accumulator に残ります。そのままではグローバル `afterEach` の `flushAndFailIfStray()` が再度例外を投げ、すべての負例が失敗します。

修正案:

- 意図的違反を検査する共通 helper を用意し、例外を検証した後に必ず `drainForAssertion()` を呼ぶ。
- drain 結果がちょうど期待件数で、method/key/type を含むことまで検査する。
- `tags()`、Closure、各 API 合流テストにも同じ処理を適用する。
- `reset()` では `$inspected` もゼロへ戻す。前テストの計測値で空振り検知が緑にならないようにする。

### [Critical] `null` の許可が上位規約と矛盾している

AGENTS.md の不変条件は「配列 / 文字列 / 数値 / 真偽値に限る」であり、`null` は含まれていません。一方、S1・S5・例外メッセージでは `null` を許可しています。

これは単なる実装詳細ではなく、セキュリティ不変条件の拡張です。

修正案:

- 本設計では `null` を違反にする。
- `null` を許可したい場合は、先に正典・概念設計・AGENTS.md の規約変更として別途承認を得る。詳細設計だけで許可集合を広げない。

### [Critical] S1 の提示コードが既知の閉じた resource バグを含む

提示された `PlainDataInspector` は、object/resource/array 以外をすべて正常として扱うため、閉じた resource を通します。リスク欄に修正方針がありますが、「変更後コード」と設計上の正本が食い違っています。

修正案:

```php
if (is_scalar($value)) {
    return;
}

if ($value === null) {
    // null を規約上禁止するなら違反にする
}

if (! is_array($value)) {
    $violations[] = $path.' = UNKNOWN_TYPE('.get_debug_type($value).')';

    return;
}
```

レビュー対象となる完成コードにこの分岐を反映し、説明だけに残さないでください。

### [Critical] extender の `$manager::class` は `mixed` に対して安全でない

次のコードは、非 object が渡された場合に意図した例外にならず、PHPStan level 10 でも問題になります。

```php
if ($manager::class !== CacheManager::class) {
```

修正案:

```php
if (! $manager instanceof CacheManager || $manager::class !== CacheManager::class) {
    throw new RuntimeException(...);
}
```

### [Critical] 静的層の語彙・目録と追加コードが一致していない

少なくとも以下が不足しています。

- `rememberWithWarmth` が `CACHE_PAYLOAD_WRITE_METHODS` に追加されていない。
- `$cache[$key] = $value` と `$cache[$key] ??= $value` はメソッド呼び出し走査では検出できない。
- `BootTimeCacheWriteProbeProvider` が変更ファイル一覧、L2、L3 目録にない。
- `PlainDataGuardedRepository` は `parent::put()` などを呼ぶため、「guard-implementation はキャッシュ API 呼び出し 0 件」という規則と衝突する可能性が高い。
- `guard-implementation` role を任意のファイルが名乗れると、新しい迂回実装の免除に使える。

修正案:

- `rememberwithwarmth` を WRITE 語彙へ追加する。
- ArrayAccess 書き込みを検出する走査を追加し、型解決、正負例、未解決 fail、空振り検知、保証範囲の docblock を揃える。
- probe provider を変更ファイル一覧、L2 `guard-selftest`、L3 `write` に登録する。
- `parent::{put,add,forever,putMany}` を guard 実装だけに許す厳密な規則を定義する。
- `guard-implementation` を名乗れるパスを固定し、追加ファイルが自由にこの role を選べないようにする。

### [Critical] W5 の字句解析案では vendor 本体を正しく解析できない

`ReflectionMethod` の行範囲だけを `PhpToken::tokenize()` に渡すと、PHP 開始タグがないためコードとして token 化されません。また、`;` と `}` で単純分割すると、if・closure・ネストしたブロックを壊します。

修正案:

- `<?php\n` を付加して token 化する。
- 単純な文分割ではなく、brace/parenthesis depth を管理する。
- より単純で強い方式として、コメント・空白を除去した当該メソッドの正規化 token 列を完全一致で pin する。
- 負例では token 列への文追加、順序変更、bootstrap 前後の移動をそれぞれ検出する。

### [Critical] S0 の「各1回」で全露出は測れない

guard はその場で throw するため、同じテスト内に複数の違反があれば最初の1件しか観測できません。したがって、一度の `composer test` で「全件転記」は保証できません。

修正案:

- 計測 → 修正 → 再実行を、違反がゼロになるまで反復する。
- `runtime-exposure.md` には各 wave の累積結果を残す。
- 「10ファイル以上」の判定も累積した一意ファイル数で行う。
- S0/S8 は実質的に交互に進む工程として実装順を訂正する。

## 施策別判定

### S0: 露出の計測 — REQUEST_CHANGES

- [Critical] 1回の実行では、throw 後に同じテスト内の後続違反が隠れる。反復計測へ変更が必要。
- [Warning] 一覧では S0 が最初ですが、本文では S1〜S4 が先です。さらにS5はテストファーストを要求しています。

修正順を、例えば「S5/W8の負例を先に赤化 → S1〜S4 → 初回計測 → S8と再計測の反復 → S6/S7完成」のように一本化してください。

### S1: 値検査器と例外 — REQUEST_CHANGES

- [Critical] `null` 許可がAGENTS.mdと矛盾。
- [Critical] 提示コードが閉じた resource を通す。
- [Warning] ノード数は根も1件に数えます。「10000件の要素」ではなく「根を含む総ノード10000」であることをテスト名と生成 helper に明記してください。

### S2: guard付き受け皿とmanager — REQUEST_CHANGES

- [Critical] `__call()`、`getStore()`、`setStore()`、直接 Store 利用が未封鎖。
- [Critical] `$manager::class` が `mixed` に対して安全でない。
- [Warning] 「末端4メソッドだけで足りる」は標準 Repository API の値合流についてのみ成立します。Store境界の完全性とは分けて記述してください。
- [Warning] `Cache::extend()` の迂回性を実 API テストで実証してください。

### S3: guard本体 — REQUEST_CHANGES

- [Warning] `flushAndFailIfStray()` の提示コードには macro の pin がありません。説明と実装が不一致です。

修正案は、`flushAndFailIfStray()` の先頭で「検査して記録し復元」を行い、その後 accumulator を判定し、`finally` では記録せず復元・消去する流れに固定することです。

- [Warning] `RateLimiter` の検査が `resolved()` のときだけなので、将来解決されなくなった場合に静かに検査を飛ばします。前提として必須なら未解決も失敗させ、任意なら「起動前結線の証拠」という主張を削除してください。
- [Warning] `reset()` と `registerBeforeBootstrap()` で `$inspected` の初期化も明記してください。

### S4: 起動前結線と全レーンの後始末 — REQUEST_CHANGES

- [Warning] vendor の `createApplication()` から既知の処理を意図的に削る設計は、フレームワーク準拠の観点で不要な分岐です。

修正案は、vendor 本体を保持し、`require` 後かつ `bootstrap()` 前に guard 登録だけを挿入することです。`traitsUsedByTest` と cached config/routes の処理も残せます。

- [Warning] 「3レーン」を、Feature/Unit、Architecture、Browser のどの `pest()->extend()` ブロックに対応させるか明示してください。単にブロック数3を数える検査ではなく、期待するレーン集合を照合すべきです。

### S5: 実行時層の振る舞い検査 — REQUEST_CHANGES

- [Critical] 意図的違反後の drain がテスト計画に組み込まれていない。
- [Critical] `BootTimeCacheWriteProbeProvider` が静的目録から漏れている。
- [Warning] 検査15はテスト本体で `drainForAssertion()` するため、「afterEachで捕まえる」ことを実証していません。実証しているのは「providerが握り潰してもaccumulatorに残る」です。

テスト名と主張を訂正し、afterEach 結線自体は S6 の gate が保証すると分担してください。

- [Warning] 第2アプリの生成は Container と Facade 以外の static 状態も変更し得ます。専用 helper に隔離し、アプリの `flush()`、Facade resolved instances、Container instance の復元順を固定するテストを追加してください。

### S6: 結線のpin — REQUEST_CHANGES

- [Critical] W5のtoken化・文分割方式では実装不能または誤判定になる。
- [Warning] W6は「両方に同じ関数名がある」だけでなく、第2アプリ側でも register が bootstrap より前であることを W1 と同じ抽出器で検査してください。
- [Warning] W4はクラス参照走査なので、完全修飾名解決・alias・未解決failを設計に含める必要があります。
- [Warning] vendor method の母集団非空だけでなく、正規化された期待 token 群が全て一度ずつ対応したことを検査してください。

### S7: 静的層の訂正とL4 — REQUEST_CHANGES

- [Critical] Store contract注入・具体Store生成がL4から漏れる。
- [Critical] ArrayAccess書き込みがL2に現れない。
- [Critical] `rememberwithwarmth` の語彙追加が不足。
- [Critical] probe providerのL2/L3登録が不足。
- [Warning] `new PlainDataGuardedRepository` を受け手型でないという理由だけで除外すると、任意のRepositoryサブクラスも同じ方法で逃げられます。

修正案は、サブクラスを含めて検出し、manager 内の一箇所だけを構造条件付きで許可することです。

- [Warning] `guard-implementation` role は許可パスと許可する parent 呼び出しを厳密に固定してください。

### S8: 露出の是正 — REQUEST_CHANGES

- [Critical] S0が一回実行のままでは、是正対象と10ファイル閾値の判定が不完全です。
- [Warning] 「ファイル数」「違反サイト数」「違反件数」を分けて記録し、10件閾値が一意ファイル数であることを明記してください。

### S9: 同梱パッケージのオブジェクトキャッシュを閉じる — APPROVE

設定値を `false` のリテラルで固定し、環境変数でも開けないことを宣言値と実効値の二段で検査する方針は妥当です。

- [Suggestion] `.env.example` だけでなく、追跡下のデプロイ・テスト設定に `PRISM_PROMPT_CACHE` が残っていないことも文字列検索で確認すると、死んだ設定の誤解を減らせます。

### S10: 規約の明文化 — REQUEST_CHANGES

- [Critical] 現状の実装では直接 Store、`__call()`、具体 Store 生成が漏れるため、「境界迂回を0件でpin」「vendor由来は実行時層が見る」と書くと保証を過大表現します。
- [Critical] 素データの型集合について `null` の扱いが本文・例外・AGENTS.mdで不一致です。

S1/S2/S7を直した後、その実際の保証範囲に合わせて記述してください。

### S11: テンプレートとの差の登録 — APPROVE

実装後に実在する差だけをD30として登録し、件数・パス・状態を既存gateに合わせる方針は妥当です。

- [Suggestion] `Cache::extend()` のLaravel実装上の扱いを再確認した結果、差の根拠が変わった場合はD30の説明も同時に修正してください。

## 観点別補足

- DTO / JsonResource / Inertia Props: 本件では該当なし。
- DESIGN.md / Atomic Design: UI変更がないため該当なし。
- 認可・tenant境界: 直接の変更なし。
- PHPStan level 10: extender の `mixed` 取り扱い、Reflectionの戻り値、`require` の戻り値絞り込みを完成コードへ反映する必要があります。
- RefreshDatabase / Factory: 新しいモデルデータを作らないため問題ありません。
- 後退リスク: `createApplication()` のvendor処理削除と、第2Applicationによるglobal/static状態汚染が主なリスクです。

## 全体判定

**CHANGES_REQUESTED**

2層構成そのものは妥当ですが、現在の詳細設計では「Repositoryを通らずStoreへ届く経路」が残り、静的層の全数性にも不足があります。特に直接Store、`__call()`、ArrayAccess書き込み、意図的違反テストのdrain、W5のtoken解析を直すまでは実装開始可能な詳細度に達していません。