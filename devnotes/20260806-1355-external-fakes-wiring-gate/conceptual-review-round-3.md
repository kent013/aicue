# 全体判定: CHANGES_REQUESTED

Round 2 の状態リーク、`refreshApplication()`、検証表現に関する指摘は解消されています。ただし、網羅性 gate に2つの設計上の穴が残り、現状コードをそのまま検査すると自己矛盾も発生します。

## [Critical] `make()` の無制限許可で配線を別クラスへ逃がせる

条件1は `$this->app->make(...)` を引数に関係なく許可しています。次の形なら3条件を回避できます。

```php
$this->app->make(ExternalRegistrar::class)->register();
```

`ExternalRegistrar` が別ファイルで container binding を行えば、

- providerでは許可済みの `make()` しか使わない
- providerはfakeクラスを直接参照しない
- provider内のbind集合にも現れない

となります。特に既存の `CannedPromptFakeRegistrar` が既に委譲パターンを採用しているため、非現実的な抜け道ではありません。

修正提案: method名だけでなく呼び出し形を固定してください。

- `bind(A::class, B::class)` はinventoryとの集合一致
- `make()` の引数は現行で必要なクラスだけを明示許可
- `environment()` も現在使用する直接呼び出しだけ許可
- 許可された `make()` 対象を増やす場合は、container配線を行わないことを理由付きで分類

少なくとも「任意の `make()` を許可」した状態ではdeny-by-defaultとはいえません。

## [Critical] fake配置規約が現行クラスと矛盾する

次の規約は現行コードを即座に赤くする可能性があります。

> クラス名が `Fake` で始まる / 終わるクラスは `Fakes/` または `Testing/` 配下のみ

設計本文に登場するだけでも、少なくとも以下が対象になります。

- `FakeExternalsServiceProvider`
- `FakeStorageGate`

どちらもfake実装そのものではなく、Providerやgate predicateです。`FakeStorageGate` は条件3で明示例外なのに、施策2の配置規約では例外になっていません。

修正提案:

- 配置規約にインフラ用クラスの理由付き例外を設ける
- 現時点では `FakeExternalsServiceProvider` と `FakeStorageGate` を明示する
- または規約を「fake実装クラス」に限定し、その判定母集団を定義する

現状の文章では、実装直後のmainが緑になる受入条件と両立しません。

## [Warning] `mutationIds` の集合一致は形骸化防止として弱い

M3～M7は個々のinventory entryに属するmutationではありません。

- M3/M4: provider全体
- M5/M6: 網羅性走査全体
- M7: fake参照走査全体

これらをentryの`mutationIds`へ割り当てても意味的な対応にはならず、既存IDを各entryへ記載するだけで集合一致を満たせます。

修正提案: mutation coverageを次の2層に分けてください。

- entry mutation: 各entryをdata-drivenなreal/fake厳密一致検査が被覆
- gate mutation: M3～M7をテストケース単位のcoverage mapで管理

inventoryに必要なのは`risk`と、必要ならentry固有のmutation IDだけです。全M1～M7をinventoryと一致させる必要はありません。

## [Suggestion] それ以外のスコープは着手可能

以下は妥当です。

- containerの復元を独立test caseへ委ねる
- Prompt staticだけを`try/finally`で往復検査する
- `afterEach`をフェイルセーフに限定する
- route冪等性をスコープから外す
- 柱2・柱3bを発火条件付きTODOとする
- 厳密クラス一致を全entryへ適用する

上記Critical 2件、特に`make()`の引数制限と配置規約の例外整合を修正すれば、概念設計として実装着手可能です。