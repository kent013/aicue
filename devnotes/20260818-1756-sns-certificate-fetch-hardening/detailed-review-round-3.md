## 全体判定: CHANGES_REQUESTED

Round 2の必須修正は概ね解消されています。DNS並列リスクも、後退ではないこと、既存防御、再検討条件を示した明示的な受容判断として成立します。

残る必須修正は施策Eの関数呼び出し走査です。現在のC13aは、完全修飾関数や関数importで回避できるため、通信原語を禁止するgateとしてfail-openです。

---

## A. 値オブジェクト

判定: APPROVE

credentialだけが新たな拒否対象であるという説明に修正され、実装・テスト・リスク記述が一致しました。

---

## B. config

判定: APPROVE

設定の配置、時間モデル、PHPStan上の型確定に問題ありません。

---

## C. 証明書取得口

判定: APPROVE

DNS解決をロック外へ出す判断について、次が明示されました。

- t0からの後退ではない
- HTTP取得自体はpermit 1へ改善される
- DNS解決は並列になり得る
- 現時点では意図的に受容する
- 再検討条件と緩和候補がある

この範囲限定を前提に承認します。

[Suggestion] 再検討条件は、実際に観測可能なデータへ結び付けてください。「DNS解決の待ち時間」を直接計測していない場合は、例えばwebhook処理時間のp95/p99や`verification_unavailable`率など、現存する観測値を正本にすると判断を再現できます。

また、異なるhostを生成された場合、NXDOMAIN cacheは必ずしも別名間で共有されません。そのため、否定応答cacheは補助的事情に留め、主要な受容根拠にはしない現在の書き方を維持してください。

---

## D. 署名検証器

判定: APPROVE

named constructorによる取得結果の整合性、キャッシュ再書き込み防止、署名成功後の昇格が揃っています。

C11の検出限界も文書で限定され、G8/G9のbehavioralテストで補完されています。

---

## E. 取得口の契約テスト

判定: REQUEST_CHANGES

[Critical] C13aは完全修飾関数呼び出しを検出できません。

現在の判定は`T_STRING`だけを見るため、次の外部取得が検出を通過します。

```php
\file_get_contents($url);
namespace\file_get_contents($url);
```

これらはそれぞれ`T_NAME_FULLY_QUALIFIED`や`T_NAME_RELATIVE`になり得ます。C12は完全修飾名を許可しているため、組み合わせても防げません。

関数importによる別名も回避経路になります。

```php
use function file_get_contents as fetchCertificate;

fetchCertificate($url);
```

修正案:

- `T_STRING`だけでなく、`T_NAME_FULLY_QUALIFIED`、`T_NAME_QUALIFIED`、`T_NAME_RELATIVE`を関数呼び出し位置で解決する
- `use function`のimportとaliasを対応表へ入れる
- 解決できない関数参照は未解決としてgateを失敗させる
- 少なくとも次を負例へ追加する

```php
file_get_contents($url);
\file_get_contents($url);
namespace\file_get_contents($url);
use function file_get_contents as fetchCertificate;
fetchCertificate($url);
```

ローカルに同名関数が定義されている場合を保証対象外にするなら、その名前解決限界をdocblockとテスト名へ明記する必要があります。

[Warning] C12の`T_USE`読み飛ばしはnamespace import、closure capture、trait useを区別する必要があります。

現在はclosureの`use (`だけを除外していますが、class内部のtrait useも`T_USE`です。

```php
class Example
{
    use Some\QualifiedTrait {
        method as alias;
    }
}
```

単純に次の`;`まで読み飛ばすと、trait adaptation blockの後方まで走査対象から落とす可能性があります。

修正案:

- brace depthとclass/namespace contextを追跡する
- namespace scopeの`use`だけをimportとして読み飛ばす
- closure useは対応する`)`まで処理する
- trait useは読み飛ばさず、qualified参照を解決するか未解決として失敗させる
- trait use adaptation blockを負例または正例に追加する

[Suggestion] C10は各メソッドの存在と引数を固定しますが、同じHTTP fluent chain上にあることまでは証明しません。検出力を「取得口内の配線site」に限定する現在の説明を維持し、同一chainを保証すると誇張しないでください。

---

## F. 取得口の振る舞いテスト

判定: APPROVE

専用array storeへの切り替えにより、`Cache::flush()`の広域干渉は解消されています。probe lockの解放、TTLの正のコントロールも適切です。

[Suggestion] `useFreshSnsCertificateCacheStore()`のテストを1件置き、呼び出し前の既存default storeに入れたsentinelが消えないことと、2回呼ぶと` sns_cert_test`の値だけが消えることを固定すると、ヘルパ自身の目的を直接証明できます。

---

## G. 署名検証器テスト

判定: APPROVE

有効PEMとダミー署名の役割が明確になりました。G10も後続の証明書処理から独立したvendor契約テストになっています。

---

## H. middleware E2E

判定: APPROVE

成功、2回目の受理、HTTP取得回数、抑止レコードの非重複が分離され、E2Eとして十分です。

専用cache storeへ切り替えることで、既存storeへの干渉も解消されています。

---

## I. テスト部品

判定: REQUEST_CHANGES

[Warning] `lambdaStyleNotification()`はoverrideによって再びcanonicalキーを追加できるため、メソッドの契約である「lambdaキーだけ」を維持できません。

```php
SnsTestData::lambdaStyleNotification('{}', [
    'SigningCertURL' => '...',
]);
```

修正案:

```php
$payload = array_merge($payload, $overrides);
unset($payload['SigningCertURL']);

return $payload;
```

または`SigningCertURL`をoverride禁止キーとしてAssertで拒否してください。両キー封筒は既存の`notification()`へ`SigningCertUrl`を追加すれば生成できるため、このhelperはlambda-onlyを保つ方が明確です。

キャッシュstoreヘルパと証明書生成部品については承認します。

---

## J. 既存目録

判定: APPROVE

専用store化後の参照siteに合わせた目録方針は妥当です。最終的な件数をexact-fit gateの実測で確定する手順も適切です。

---

## K. 文書

判定: APPROVE

保証する範囲と保証しない範囲、DNS並列リスクの受容判断、再検討条件が明確になっています。

[Suggestion] 再検討条件には「どのログ・メトリクスで判断するか」も併記してください。観測手段が存在しない条件は、実運用では発火できません。

---

## 承認までの必須修正

1. C13aで完全修飾・相対修飾・`use function` aliasを扱う
2. C12でnamespace importとtrait useを文脈上区別する
3. `lambdaStyleNotification()`が常にlambda-onlyを維持するようにする

この3点が解消されれば、詳細設計として全体承認可能です。