## 全体判定: CHANGES_REQUESTED

Round 1の主要論点はほぼ適切に解消されています。特に以下は妥当です。

- `203.0.113.10` への反論は受け入れます。現在のdeny CIDRとF0の正のコントロールを前提に、テストfixtureとして成立します。
- timeoutの時間モデル修正
- `services.ses` からの設定分離
- 3xxの明示拒否
- `SnsCertificate` による取得元の区別
- 実verifierの成功系E2E追加

ただし、Architecture gateに実コード上必ず失敗する条件が2件あります。これは実装前に修正が必要です。

---

## A. 値オブジェクト

判定: APPROVE

型絞り込みとfail-closed化は適切です。

[Suggestion] リスク欄の次の記述だけ訂正してください。

> credential と fragment の拒否は意図した後方非互換

提示されたt0コードは既にfragmentを拒否しています。新たな後方非互換はcredential拒否だけです。

---

## B. config

判定: APPROVE

`connect <= request` と `request + post <= lock_ttl` への修正、ならびに`services.sns_certificate`への分離は妥当です。

`post_fetch_budget_seconds`を強制上限ではなく安全余裕として扱う説明も、実装の保証範囲と一致しています。

---

## C. 証明書取得口

判定: REQUEST_CHANGES

[Warning] DNS検査をロック外へ出した結果、未認証リクエストによるDNS解決は直列化されなくなります。

攻撃者は次のように異なるregion相当文字列を並べられます。

```text
sns.a1.amazonaws.com
sns.a2.amazonaws.com
sns.a3.amazonaws.com
```

このため、HTTP取得のpermitは1でも、時間上限のないDNS解決によるworker占有は並列に発生します。現在の文書はこの事実を記載していますが、無認証webhookの可用性境界としてはリスク受容の根拠が不足しています。

修正案は次のいずれかです。

- 証明書hostのregionをTopicArnまたは許可済みAWS regionへ束縛する
- DNS resolverに実効timeoutを設定する
- DNS解決用の独立した同時実行制限を設ける
- AG-199として意図的に受容するなら、受容理由、既存throttleとの関係、再検討条件を文書へ明記する

単なる「保証しないもの」だけでは、無認証入力から作れるworker占有経路への判断として不足します。

[Suggestion] `SnsCertificate` はpublic constructorにより不整合な値を作れるため、named constructorにすると意味が明確になります。

```php
SnsCertificate::cached($pem);
SnsCertificate::fetched($pem);
```

---

## D. 署名検証器

判定: APPROVE

`fromCache`による再昇格防止と、検証後だけの`rememberVerified()`呼び出しは整合しています。

C11の「行順」は完全な制御フロー証明ではありません。例えば、ファイル後方にあるhelperから昇格し、そのhelperを検証前に呼ぶ形は見抜けません。ただし、G8/G9のbehavioralテストと組み合わせ、文書でも「言語の可視性で閉じていない」と限定しているため、現状の保証として許容できます。

---

## E. 取得口の契約テスト

判定: REQUEST_CHANGES

[Critical] C5は正当な署名検証呼び出しを違反として検出します。

走査根には次のmiddlewareが含まれます。

```php
$this->verifier->verify($message);
```

しかしC5は走査根内の`->verify(`を0件と要求しています。したがって既存の正当なコードだけで必ず赤になります。

修正案:

- 全メソッドの`verify()`を禁止しない
- `withoutVerifying()`を禁止する
- HTTP optionの`'verify' => false`を禁止する
- 必要ならHTTP pending requestに限定できる解析を行う
- domainの`SnsSignatureVerifier::verify()`は正例へ入れる

[Critical] C12の「`T_NAME_QUALIFIED`が現れたら失敗」もそのままでは成立しません。

`T_NAME_QUALIFIED`は、未解決な部分修飾参照だけでなく、通常のnamespace宣言やqualified nameにも現れ得ます。

```php
namespace App\Services\Mail\Sns;
```

したがってトークン種別だけを見て拒否すると、走査対象の通常ファイル自体を拒否します。

修正案:

- namespace宣言、use宣言、group useを文脈として除外する
- 式・型・new等の参照位置にあるqualified nameだけを解析する
- 可能なら`PhpReferenceScanner`を拡張してqualified nameを解決する
- 解決できない参照位置だけを「未解決」として失敗させる

C8/C9には少なくとも次を追加してください。

- namespace宣言は正例
- use宣言は正例
- 解決可能なqualified class参照は正例
- 解決不能として扱う部分修飾参照は負例

[Warning] C13の関数名とクラス参照は同じ走査方法では扱えません。

`file_get_contents`や`curl_exec`は関数呼び出しトークン、`GuzzleHttp\Client`はクラス参照です。設計中の「C1/C13はPhpReferenceScannerの完全修飾名で照合」と「C13はPhpTokenの完全一致」の関係を明確にしてください。

修正案:

- 関数呼び出しは`T_STRING`と直後の`(`を検査
- クラス参照は`PhpReferenceScanner`でFQCN解決
- それぞれ別の正例・負例を用意する

---

## F. 取得口の振る舞いテスト

判定: REQUEST_CHANGES

`203.0.113.10`についての反論は妥当です。F0も十分な安全策です。

[Warning] `Cache::flush()`は対象キーだけでなくstore全体を消します。

テスト設定が共有storeを向いた場合や並列実行時に、他テストのcache・rate limiter・lockへ干渉します。特にHではmiddlewareのthrottleもcacheを利用し得ます。

修正案:

- テストごとに専用array storeへ切り替える
- または既知の証明書cache keyとlockだけを明示的に掃除する
- `Cache::flush()`を使う場合は、対象storeがテスト専用であることをbeforeEachで固定する

[Warning] F17の解放確認用に取得したprobe lockも必ず解放してください。

```php
$probe = Cache::lock('sns:cert:fetch', 10);
expect($probe->get())->toBeTrue();
$probe->release();
```

取得したままだと、同一テスト内の後続ケースやdataset実装次第で別の失敗原因になります。

[Suggestion] F19は時刻移動後に`cached() === null`だけでなく、必要なら該当キーが期限切れとして取得不能であることを確認してください。`cached()`が別理由でnullになった場合との区別が明確になります。

---

## G. 署名検証器テスト

判定: APPROVE

G4/G5を有効なPEMへ変更し、HTTP到達と未キャッシュを併せて確認する修正は適切です。

G10も両キー拒否の前提を固定するvendor契約テストとして有効です。certClientでは要求URLを記録した後、テスト用の明示的な終了例外を投げるなど、証明書内容や後続署名検証にassertionが依存しない形が望まれます。

---

## H. middleware E2E

判定: REQUEST_CHANGES

H4の追加は適切です。

[Warning] ここでも`Cache::flush()`によるstore全体への干渉があります。Fと同じく、専用storeまたは対象キー限定の初期化へ変更してください。

また、2回目の同一通知がcontrollerの冪等処理で受理されることと、HTTP取得が増えないことを別々にassertしてください。後者だけでなく、`EmailSuppression`が重複作成されないことも確認するとE2Eとして明確です。

---

## I. テスト部品

判定: REQUEST_CHANGES

[Warning] `certificatePem()`の説明と実装が矛盾しています。

```php
public static function certificatePem(): string
{
    return self::keyPair()['pem'];
}
```

`signedNotification()`も同じ`keyPair()`の秘密鍵で署名するため、この証明書は`signedNotification()`の署名と一致します。

現在のG4/G8が通常のfake signatureを使うならテスト自体は失敗できますが、次の説明は誤りです。

> `signedNotification()` の署名とは一致しない証明書

修正案はどちらかです。

- メソッドを「PEMとして有効なテスト証明書」として再定義し、fake signatureとの不一致をテスト側で説明する
- 本当に非一致証明書が必要なら、署名用とは別のkey pairを生成する

前者の方が単純です。

[Suggestion] `lambdaStyleNotification()`は最後に`CERT_URL`を代入するため、`$overrides['SigningCertUrl']`が無視されます。テストで異なるlambda URLを必要とする可能性があるなら、既定値を先に入れてからoverrideを適用してください。

---

## J. 既存目録

判定: APPROVE

exact-fitの結果を見て`Cache::flush()`利用テストを登録する方針は適切です。

ただしF/Hの初期化を専用storeまたは対象キー限定へ変更した場合は、実際の参照siteに合わせて目録を確定してください。

---

## K. 文書

判定: APPROVE

保証範囲の限定は適切です。

ただしCのDNS並列化を受容する場合は、「DNS時間に上限がない」だけでなく、次も明記してください。

- DNS検査はpermit 1の対象外で並列に走り得る
- 無認証入力が異なるSNS風hostを生成できる
- どの既存防御で許容可能と判断したか
- 再検討条件

---

## 承認までの必須修正

最低限、次の5点を直せば再承認可能です。

1. C5からdomainの`->verify()`を誤検出する条件を除く
2. C12を文脈非依存の`T_NAME_QUALIFIED`全面禁止から修正する
3. C13の関数呼び出し走査とクラス参照走査を分ける
4. `Cache::flush()`の共有store干渉をなくす
5. `certificatePem()`の説明または生成元を実装と一致させる

加えて、ロック外へ移したDNS解決の並列DoSリスクについて、緩和策または明示的なリスク受容判断が必要です。