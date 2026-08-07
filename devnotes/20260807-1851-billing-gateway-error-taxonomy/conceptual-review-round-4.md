全体判定: **CHANGES_REQUESTED**

Round 3 のCritical 2件は解消されています。`UnknownApiErrorException` の条件付き規則、直接写像との集合分離、unknown fixtureの分離はいずれも妥当です。

ただし、unknownの運用契約とgateの集合契約に1件、文言上の矛盾が1件残っています。

## 1. 使命との整合性

[Suggestion] 問題ありません。

使命への貢献を「再送可能性の一次切り分け」という間接効果に限定しており、過大主張は解消されています。

## 2. 禁止事項違反

[Suggestion] 問題ありません。

テスト登録、PHPStan level 10、既存制御フロー維持、旧ログ語彙の同一PR内撤去が設計に含まれています。

## 3. 実現可能性

[Warning] unknownで検出したアプリ例外を追加すると、現在の集合gateに拒否されます。

現在の契約は次です。

```text
(directMap ∪ conditionalClasses) \ vendor具象クラス集合
    = framework明示宣言集合
```

一方、未知の扱いでは「アプリ自身の新しい例外」もunknownになり、そのクラスを写像表へ必ず追加するとしています。アプリ例外はvendorにもframework集合にも属さないため、追加すると集合一致が失敗します。

修正案:

- `framework明示宣言集合` を `nonVendorExplicitClasses` のような集合へ一般化する。
- 初期値としてframework 3クラスを登録する。
- 将来アプリ例外を分類する場合は、この明示集合と`directMap`を同じPRで更新する。
- この明示集合自体にもexact-fitまたは理由付きinventoryを持たせる。

集合契約は次の形になります。

```text
keys(directMap) ∪ conditionalClasses
    = vendorConcreteClasses ∪ nonVendorExplicitClasses
```

これならvendor外のクラスを無断追加できず、unknownの運用契約とも両立します。

## 4. 期待効果の妥当性

[Suggestion] 問題ありません。

`provider_rejected` が再送可能性の完全な意味判定ではないこと、nullを保守的に分類することが明記され、効果の範囲が適切になっています。

## 5. リスク

[Warning] 背景部分に「クラス名は有界」という旧表現が残っています。

後半では正しく、`error_class` の値域自体は有界ではなく、有界性を保証するのは写像表側だと説明しています。冒頭の以下の記述とは矛盾します。

> さらに、クラス名は「有界」ではあるが分類ではない。

修正案:

> さらに、クラス名は外部サービス生成メッセージではないが、運用行動を示す分類ではない。

表の `terminateInvoiceBestEffort()` にある「有界な語彙のみ」も、厳密には「外部生成メッセージを含まない」に直すと文書内で一貫します。

## 6. スコープの適切さ

[Suggestion] 適切です。

条件付き規則を1クラスに限定し、他gatewayや横断redactへ広げていません。`AGENTS.md` への短い不変条件追記も妥当です。

## 7. 型安全性

[Suggestion] 問題ありません。

`directMap()`、`conditionalClasses()`、`class-string<Throwable>`、ログ境界だけでの`->value`変換により、PHPStan level 10で扱える設計になっています。499・500・nullの境界テストも十分です。

上記2点はいずれも局所修正です。特に非vendor集合を一般化すれば、分類器・gate・unknown運用契約が一貫します。その修正後は概念設計として承認可能です。