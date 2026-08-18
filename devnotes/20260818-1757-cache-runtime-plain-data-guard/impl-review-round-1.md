仮説どおり、実行時 guard の基本動作は妥当ですが、静的 gate と結線 gate に fail-closed を破る検出漏れがあります。テストが全件 green でもセキュリティ不変条件の保証には届きません。

### `AGENTS.md`

判定: 概ね問題なし。

[Suggestion] 「境界迂回は自己テストだけを exact-fit」とありますが、実装では guard 本体の継承 2 件も別目録で許可されています。自己テスト例外と guard 実装例外を分けて記述すると、実態と一致します。

### `config/prism-prompt.php`

判定: 問題なし。

オブジェクトキャッシュを `false` 固定にし、env で再開できない設計は詳細設計と一致しています。

### `docs/app-integration-guide.md`

判定: 概ね問題なし。

[Suggestion] `getStore()` について、AGENTS.md と同様に「vendor がこの経路で書く値は2層とも見えない」まで明記すると、保証範囲が揃います。

### `docs/architecture.md`

判定: 概ね問題なし。

排他2語彙の設計差分は実測結果による正当な変更です。

### `docs/template-divergence.md`

判定: 問題なし。

D30 の登録形式、理由、再判定条件は妥当です。

### `tests/Architecture/CacheGuardWiringGateTest.php`

判定: 修正が必要です。

[Warning] `cacheGuardLaneWiringViolations()` は呼び出しが各 `beforeEach` / `afterEach` クロージャの内部にあることを解析していません。単に token 上で `afterEach` より後に `reset()` があれば通るため、`finally` の外やクロージャ終了後に置いても合格します。実際、W8 の `missingFlush` fixture は `reset()` を `finally` なしで置いており、それを正常扱いしています。詳細設計の「finally に reset」を保証できていません。

[Warning] W6 はファイル全体で最初の結線と `bootstrap()` の順番を見るだけです。`IsolatedApplicationProbe::run()` と同じ関数内にあることは確認しておらず、`method_exists()` もこの穴を塞ぎません。対象メソッド本体を反射で切り出して検査する必要があります。

[Warning] W4 の trait 検出は短名の `use WithCachedConfig;` だけです。完全修飾名、alias、複数 trait の `use A, WithCachedConfig;` を見逃し、名前解決も行っていません。また `getRealPath()` 失敗を黙って除外しています。走査共通規約の完全修飾名照合・fail-closed に反します。

[Warning] W8 の vendor token 増減・順序変更とローカル文削除の負例は、実際の判定関数を通していません。加工した配列が元配列と異なることを確認しているだけなので、gate の判定ロジックが壊れても負例が green のままです。

### `tests/Architecture/CachePayloadPlainDataGateTest.php`

判定: 修正が必要です。

[Critical] 動的な直接生成を黙って見逃します。たとえば次の形では `new` の対象を解決できず、`$store` も型付き受け手ではないため、静的層にも実行時層にも捕まりません。

```php
$class = \Illuminate\Cache\ArrayStore::class;
$store = new $class;
$store->put('key', new stdClass(), 60);
```

`T_NEW` の後が解決可能なクラス名でない場合を `unclassified` として落とす分岐が必要です。現在の docblock は動的生成を保証外にしておらず、走査共通規約の「解決できない形は落とす」に反します。

[Warning] L4c は `$store` の直前 token が `Store` と `(` の2件であることしか見ていません。「`PlainDataGuardedRepository` の第1引数」という構造を確認していないため、次のような流出へ置き換えても合格できます。

```php
$copy = leak($store);
return new PlainDataGuardedRepository($copy, []);
```

第2出現が対象コンストラクタの第1引数であることを、括弧対応を含めて検査してください。この分岐の負例も必要です。

[Warning] 継承解析には「解決不能なら `null` で落とす」分岐がありますが、その分岐を通す負例がありません。AGENTS.md の4点セットにある fail-closed 分岐の裏取りが不足しています。

[Suggestion] 冒頭の不変条件説明がまだ「配列 / 文字列 / 数値 / 真偽値」となっており、今回追加した `null` が抜けています。

[Suggestion] L4g は名称・説明では「一致」としていますが、実装は runtime 許可語彙が TERMINAL の部分集合であることだけを検査しています。意図が部分集合なら文言を修正してください。

### `tests/Architecture/TemplateDivergenceLedgerFormatTest.php`

判定: 問題なし。

件数更新は文書側と一致しています。

### `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php`

判定: 概ね問題なし。

[Suggestion] `PlainDataGuardedRepository::put()` に追加された `$key` が配列の場合の専用分岐が直接テストされていません。`put(['k' => new stdClass()], null)` の負例と素データの正例を追加すると、override の全分岐が固定されます。

### `tests/Feature/Config/ConfigHardeningTest.php`

判定: 問題なし。

宣言値と実効値の二段 pin は詳細設計どおりです。

### `tests/Pest.php`

判定: 問題なし。

3レーンすべてに確認・flush・finally reset が実装されています。問題は実装ではなく、上記 wiring gate がこの構造を十分に保証できていない点です。

### `tests/Support/Cache/BootTimeCacheWriteProbeProvider.php`

判定: 問題なし。

例外を握り潰して accumulator を検証する負例として妥当です。

### `tests/Support/Cache/CachePayloadViolation.php`

判定: 問題なし。

### `tests/Support/Cache/CachePayloadViolationAssertions.php`

判定: 問題なし。

例外だけでなく accumulator の内容と drain 後の状態まで確認しています。

### `tests/Support/Cache/GuardedBoundaryProbe.php`

判定: 問題なし。

境界自己テストを1ファイルに集約し、型付き受け手を使う構造は静的 gate と整合しています。

### `tests/Support/Cache/IsolatedApplicationProbe.php`

判定: 概ね問題なし。

コンテナと facade の復元順序は設計どおりです。W6 の不足はこの実装ではなく、結線 gate 側の保証不足です。

### `tests/Support/Cache/PlainDataCacheGuard.php`

判定: 問題なし。

値検査、accumulator、macro pin、起動前 extender の基本設計は詳細設計と一致しています。

### `tests/Support/Cache/PlainDataGuardedCacheManager.php`

判定: 問題なし。

### `tests/Support/Cache/PlainDataGuardedRepository.php`

判定: 概ね問題なし。

排他2語彙だけを許可する設計差分は、実測・正のコントロール・静的語彙との pin があり正当です。

[Suggestion] docblock は語彙一致を `CacheGuardWiringGateTest.php` が固定すると記載していますが、実際の L4g は `CachePayloadPlainDataGateTest.php` にあります。

### `tests/Support/Cache/PlainDataInspector.php`

判定: 問題なし。

許可集合、未知型、resource、深さ・ノード制限の fail-closed 分岐は妥当です。

### `tests/TestCase.php`

判定: 問題なし。

vendor 本体を維持しつつ bootstrap 前に結線する実装は詳細設計と一致しています。

### `devnotes/20260818-1757-cache-runtime-plain-data-guard/runtime-exposure.md`

判定: 修正が必要です。

[Warning] 詳細設計の必須成果物であり、実装メモでは wave 0 を参照していますが、提示された差分に追加がありません。各 wave、一意ファイル数、違反サイト数、累積、最終0件の記録がない状態では完了条件1を確認できません。未追跡ファイルならレビュー対象の差分へ含めてください。

CHANGES_REQUESTED