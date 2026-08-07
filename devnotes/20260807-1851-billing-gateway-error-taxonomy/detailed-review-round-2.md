## 施策別判定

### S1: `GatewayFailureClass`

**APPROVE**

語彙境界、`unknown` の用途、フロント・DTOへの非露出が明確です。Round 1 指摘への追加懸念はありません。

### S2: `GatewayFailureClassifier`

**APPROVE**

`is_int()` narrowing、通常経路外のStripe例外を分類対象に含める根拠、条件付き規則と直接写像の分離はいずれも妥当です。

### S3: `AutoRechargeService`

**APPROVE**

report文言の完全一致、成功時のnullキー固定、原例外をpreviousへ接続しない保証まで揃っています。制御フローを変更しないという概念設計とも整合しています。

### S4: 共有fixtureとspy

**APPROVE**

`EXTERNAL_MESSAGE_MARKER` の正方向保証を追加したことで、message非含有テストが空虚にgreenになる問題は解消されています。

### S5: deny-by-default gate

**REQUEST_CHANGES**

[Critical] 検査17cは、そのままでは自己検出します。

Architectureテスト自身に次の旧API名リテラルが含まれるため、`tests/` 全体を走査すると最低でも検査コード自身がhitします。

```php
'failOnTerminate'
'failOnResolveSubscriptionPaymentMethod'
```

コメントやテスト名に同じ文字列を書いた場合もhitします。

修正案: 正本であるArchitectureテスト自身を走査対象から除外したうえで、`tests/` のPHPファイルを走査してください。より対象を絞るなら、`tests/Support/FakeAutoRechargeGateway.php` とFeatureテスト群を母集団として明示する方法でも構いません。いずれの場合も「除外した正本以外では0件」という保証として記述してください。

[Warning] 検査13の「除外した各サブ名前空間の具象例外が母集団に0件」は定義上自明です。

母集団が直下の`*.php`だけなら、`OAuth/`配下は最初から母集団へ入りません。一方、OAuthディレクトリを実際に走査して「具象例外が0件」を要求すると、SDKの内容によっては意図と矛盾します。

修正案:

- 実サブディレクトリ集合と除外宣言のキー集合を一致させる。
- 除外理由が30文字以上であることを検査する。
- 直下母集団の各クラスが除外名前空間に属さないことを検査する。
- 必要なら除外ディレクトリを別途走査し、「存在するクラスが分類母集団に混入していない」ことを集合の非交差で確認する。

「OAuth配下に具象例外が存在しない」こと自体は要求しない方が、除外という設計意図に一致します。

### S6: Unit / Featureテスト

**REQUEST_CHANGES**

[Warning] `directMap()` の独立期待値表は23 entryです。設計中の「24 entryを手書きで再宣言」は数が一致していません。

内訳はStripe 12、Cashier 8、非vendor 3で合計23です。`UnknownApiErrorException`は`conditionalClasses()`側なので、`directMap()`の期待値表には入りません。

修正案: 文言を「23 entry」または件数を埋め込まない「全entry」に修正してください。実装ではキー集合一致が正本になるため、固定件数を別途持たせる必要はありません。

### S7: 運用契約

**APPROVE**

保証範囲が「本サービスの構造化ログとreport message」に限定され、stack trace、vendor側ログ、queue failureを除外したため、実装が保証できる範囲と一致しています。AGENTS.mdの追記手順も番号衝突へ対応しています。

## 全体判定

**CHANGES_REQUESTED**

Round 1の指摘は実質的にすべて解消されています。ただし、S5の検査17cは現状の記述どおり実装するとArchitectureテスト自身を検出して必ず失敗するため、実装前の修正が必要です。

併せて、Stripeサブ名前空間検査の定義を非自明な保証へ修正し、S6の`directMap()`件数を23へ訂正すれば、詳細設計として承認可能です。