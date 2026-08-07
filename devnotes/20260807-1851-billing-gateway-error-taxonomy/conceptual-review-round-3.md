全体判定: **CHANGES_REQUESTED**

`UnknownApiErrorException` の5xx分類は認めます。調査結果も分類目的に直接効いており、`>= 500 → provider_unavailable` は妥当です。

ただし、現在の `map()` APIとfake fixture契約では、「条件付き規則」と「写像不在専用のunknown」を表現できません。概念上ではなく、設計内部の型・集合契約にCriticalな矛盾が2件残っています。

## 1. 使命との整合性

[Suggestion] 問題ありません。

効果を「再送で収束するか否か」に限定したことで、分類器が提供できる価値と主張が一致しました。

## 2. 禁止事項違反

[Suggestion] 問題ありません。

テスト、PHPStan、既存制御フロー維持、思考原則3への対応が明示されています。

## 3. 実現可能性

[Critical] `map()` の型では `UnknownApiErrorException` の条件付き規則を表現できません。

現在の契約は次です。

```php
/** @return array<class-string<Throwable>, GatewayFailureClass> */
public static function map(): array;
```

一方、`UnknownApiErrorException` は同じクラスから2種類の結果を返します。このクラスをmapに入れる場合、値をどちらか一方に固定せざるを得ません。

- mapに入れる: 値が実際の分類規則を表さない
- mapから外す: 「mapの集合 == vendor具象クラスの集合」が失敗する
- classify側で先に分岐する: mapが「写像表の正本」ではなくなる

修正案:

```php
/** @return array<class-string<Throwable>, GatewayFailureClass> */
public static function directMap(): array;

/** @return list<class-string<Throwable>> */
public static function conditionalClasses(): array;
```

gateは次を保証します。

```text
keys(directMap) ∪ conditionalClasses = vendor具象クラス集合
keys(directMap) ∩ conditionalClasses = 空集合
conditionalClasses = [UnknownApiErrorException::class]
directMapの値にUnknownは存在しない
```

`map()` という名前を維持するなら、戻り値を「直接写像だけ」と明記する必要があります。条件付き規則を含めて正本と呼ぶなら、rule object等が必要になりますが、この1件には過剰です。

[Critical] 「全caseにfixture」と「unknownは写像不在専用」が両立していません。

現在のfixture契約は以下です。

- 全5 caseにfixtureがある
- fixtureは実ライブラリ例外
- vendor例外は全件分類済み
- framework側で実際に受ける例外も明示分類済み
- unknownは未登録例外だけ

したがって、実ライブラリ例外をunknown fixtureに選ぶと、その例外は意図的に未分類になります。運用契約では発生時に表へ追加するため、fixtureが壊れます。逆に表へ追加すればunknownを返さなくなります。

修正案:

- fakeと本物の一致保証は、業務分類4 caseだけを対象にする。
- `unknown` は専用のテスト用例外、例えば `UnmappedGatewayFailureForTest` でclassifier単体テストを行う。
- 「fixtureが実ライブラリ名前空間」という条件は4 caseに限定する。
- spyからunknownを投げる必要がなければ、spyの失敗注入対象からunknownを外す。

`unknown` は「本物と同じ例外を再現する分類」ではなく、分類器の全域性を守るfallbackです。同じfixture契約に含めない方が概念に忠実です。

## 4. 期待効果の妥当性

[Warning] `UnknownApiErrorException` の「それ以外は再送で収束しない」は少し強い表現です。

5xxは明確ですが、表には `0 / 4xx / その他` とあります。`0` や3xxについて、再送で収束しないことまではHTTP statusだけから断定できません。

修正案:

- 実throw siteでstatusが必ず有効なHTTP statusになるなら、`0` を表から削除し、その前提をテストする。
- それ以外は「5xx以外はprovider_rejectedとして扱う。再送可能性の完全な意味判定ではなく、運用上の保守的分類」と書く。
- 少なくとも `null`、500、4xxの境界テストを置く。`getHttpStatus()` がnullableなら、nullの分類も概念設計で確定する。

## 5. リスク

[Warning] 特別規則のexact-fitは「件数1」だけでなく、クラス同一性まで固定してください。

「statusで細分するクラスが1件」だけでは、UnknownApiErrorが外れて別クラスが1件入ってもgreenになります。

修正案:

```text
conditionalClasses === [UnknownApiErrorException::class]
```

さらに最低限、499・500、可能ならnullの境界をFeatureまたはUnitテストで固定してください。

## 6. スコープの適切さ

[Suggestion] 適切です。

HTTP statusを使う規則は1クラスに限定されており、Stripe error code全体を分類体系へ持ち込む拡張にはなっていません。AGENTS.md追記も前回判定どおり受け入れます。

## 7. 型安全性

[Warning] PHPDoc自体は改善されていますが、条件付き規則を型構造にも反映させる必要があります。

直接写像と条件付き分類を別APIにすれば、PHPStan level 10でも「配列の値が実際には使われないダミー分類」という状態を避けられます。

また、`getHttpStatus()` が `?int` なら、次のようにnullを明示処理してください。

```php
$status = $throwable->getHttpStatus();

if ($status !== null && $status >= 500) {
    return GatewayFailureClass::ProviderUnavailable;
}

return GatewayFailureClass::ProviderRejected;
```

## 確認事項への回答

1. status 2分岐は認めます。ただし、`map()` の単純なclass-to-enum写像とは別の条件付き規則として表現してください。
2. map値でのunknown禁止は必要ですが、それだけでは不十分です。直接写像と条件付き規則の和集合が母集団と一致し、積集合が空であることまでgateで保証すれば十分です。

この2つのCriticalは分類判断ではなく、既に決めた判断をコードとgateで矛盾なく表現するための修正です。そこを直せば概念設計として承認できます。