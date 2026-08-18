## 全体判定: CHANGES_REQUESTED

方向性は妥当です。特に「実効 URL の確定」「一時障害と恒久不正の分離」「未検証 PEM を直ちにキャッシュしない」という仮説は正しいです。

一方、次の3点は設計上の保証と実装案が一致しておらず、実装前の修正が必要です。

- 「署名検証済みだけをキャッシュする」が公開 `remember()` の呼び出し規約に依存している
- ロック寿命の計算に、ロック保持中の DNS/SSRF 検査時間が含まれていない
- 契約テストが、SSRF検査・timeout・redirect禁止など主要防御の配線を固定していない

---

## A. 検証済み証明書 URL の値オブジェクト

判定: APPROVE

値オブジェクト化と両キー処理からの分離は適切です。credential、port、query、fragment、path を vendor より厳しく制限する方針にも問題ありません。

[Suggestion] PHPStan の結果を見て cast するのではなく、詳細設計時点で fail-closed な型絞り込みを確定してください。

```php
$host = $parts['host'] ?? null;
if (! is_string($host)) {
    return false;
}
```

`path`、`scheme` も同様です。単純な `(string)` cast より、想定外の型を拒否する方が値オブジェクトの役割に合います。

また「判定式を移設するだけなので振る舞いは変わらない」は不正確です。credential 拒否は意図した後方非互換なので、「既存の正当な SNS URL の振る舞いは維持する」と記述してください。

---

## B. 証明書取得予算の config 化

判定: REQUEST_CHANGES

[Warning] `timeout()` は「読み取り timeout」ではなく、Laravel/Guzzle上は原則としてリクエスト全体の timeout です。

したがって、

```text
connect + read + post <= lock TTL
```

という説明は、提示コードの実際の時間モデルと一致しません。

修正案:

- `cert_read_timeout_seconds` を `cert_request_timeout_seconds` に変更する
- 契約を次のようにする

```text
0 < connect_timeout <= request_timeout
request_timeout + post_fetch_margin <= lock_ttl
```

本当に読み取り単独の timeout を設定する場合は、使用する Guzzle option とその適用範囲を明記し、HTTP request option をテストで確認してください。

[Warning] `services.ses.webhook` が `SesV2Client` の引数へ流れる設計は、AWS SDK が未知キーを無視することへの不要な依存です。

既存キーが同居していることは、新規キーをさらに混在させる根拠にはなりません。例えば次のように vendor 設定と分離する方が安全です。

```php
'services.sns_certificate' => [
    // ...
],
```

現配置を維持するなら、「現行版で壊れない」だけでなく、MailManagerがどのキーをAWS SDKへ渡すかを固定するテストが必要です。

---

## C. 証明書取得口

判定: REQUEST_CHANGES

[Critical] ロック寿命の安全性証明から DNS/SSRF 検査時間が抜けています。

`UrlSafetyInspector::inspect()` はロック取得後に実行されますが、契約上は次しか計上されていません。

```text
接続 + 読み取り + 後処理
```

DNS検査が8秒を超えれば、1件目がまだ処理中なのにロックが失効し、2件目が取得を開始できます。これにより `CERT_FETCH_PERMITS = 1` の保証が崩れます。

修正案は次のいずれかです。

- DNS resolver の実効 timeout を設定し、ロック寿命の計算と契約テストに含める
- SSRF検査をロック取得前へ移し、ロック中の再検査または接続先固定を別途設計する
- ロック更新を実装する

少なくとも現在の「右の不等号がpermit 1を保証する」という説明は成立しません。キャッシュ再確認のI/OやPEM解析時間も強制上限ではないため、`post_fetch_budget` は「保証値」ではなく「安全余裕」であることを明記すべきです。

[Critical] 「署名検証済みだけをキャッシュへ昇格する」が構造的に守られていません。

`remember()` は public で任意の `string` を保存でき、F1自身が署名検証なしで次を行います。

```text
fetchSerialized → remember → cached
```

これは設計が掲げる不変条件の反例です。

修正案:

- 取得・署名検証・成功時昇格を一つのオーケストレーションAPIに閉じ、裸の `remember()` を公開しない
- それが難しければ、最低限 `rememberVerified()` の呼び出し箇所を `AwsSnsSignatureVerifier` の検証成功後1箇所に exact-fit で固定するArchitectureテストを追加する
- F1は直接 `remember()` を呼ばず、実verifierの署名成功を経由して確認する

[Warning] `withoutRedirecting()` の後の `throw()` は、一般に3xxをエラーとして投げません。3xx応答の本文が有効なPEMなら受理される余地があります。

修正案:

- レスポンスが2xxであることを明示検査する
- 301/302/307/308を503へ分類し、リダイレクト先へ通信しないテストを追加する

[Warning] 「キャッシュ読み取り障害で署名検証を止めない」という説明は条件付きです。同じcache storeがロックにも使われるため、read失敗後にlock取得も失敗すれば503になります。

「getだけが失敗し、lock基盤は利用可能な場合はmissとして継続する」と記述を狭めてください。

---

## D. 署名検証器

判定: REQUEST_CHANGES

[Critical] Cと同じく、検証済みキャッシュ昇格が呼び出し順の作法に留まっています。

G8/G9はこの実装経路の振る舞いを証明しますが、別の呼び出し元が `remember()` を先に呼べないことは証明しません。取得口側のAPI再設計、または呼び出し箇所のArchitecture gateが必要です。

[Warning] `$fresh` は「実際にHTTP取得した値」とは限りません。外側の `cached()` がmissした後、ロック内再確認でhitした場合も `$fresh` に設定され、再度 `put()` されます。

安全性上の問題ではありませんが、「新しく取得したPEMだけ」というコメントと一致しません。返り値を例えば次のshapeを持つDTO/値オブジェクトにすると明確です。

```text
pem
source: cache | remote
```

[Suggestion] 「SDKが別URLを要求する」分岐を現行vendorでbehavioralに到達できないという限定は適切です。将来のvendor更新検知として、vendor契約テストで実効URLの変換規則をpinすることは検討できます。

---

## E. 取得口の契約テスト

判定: REQUEST_CHANGES

[Critical] テスト名が主張する契約に対して、固定する防御が不足しています。

現在のC1〜C9では、次を削除しても契約テストが緑になり得ます。

- `UrlSafetyInspector::inspect()`
- `connectTimeout()`
- `timeout()`
- `withoutRedirecting()`
- サイズ上限判定
- PEM検査
- 検証成功後だけのキャッシュ昇格

修正案:

- 上記の配線をArchitectureテストまたはFeatureテストで個別に固定する
- timeout、redirect、TLS verifyは `Http::fake()` で送信request optionを検査する
- `rememberVerified()` の呼び出しsiteをexact-fitで固定する
- SSRF検査を外した負例が失敗することを固定する
- PEM/サイズ判定を外した負例をFeatureテストで固定する

[Critical] `PhpReferenceScanner` が部分修飾名を解決しないまま「取得口の唯一性」を名乗るのは、共通規約(b)と緊張します。

保護対象の参照を部分修飾名で記述できる以上、単に「保証しない」とdocblockへ書くだけでは弱いです。次のいずれかが必要です。

- `T_NAME_QUALIFIED` を解決する
- 対象構文を検出したら「未解決」としてgateを失敗させる
- テスト名と保証を「解決可能なLaravel HTTP client参照の配置」に明示的に狭める

[Warning] C1はLaravelのHTTP facade/factoryだけを見ています。`file_get_contents`、Guzzle、curl等による取得を検出しないため、「外部HTTPを行うのはこのクラスだけ」とは証明できません。

SNS領域で禁止するネットワーク原語を追加で検出するか、保証文言を狭めてください。

[Warning] C8/C9の検出器自己テストと、本番コードに対するgateの赤確認を分けて記述してください。負例検出器そのものは最初から緑であるべきで、「施策Cがないため赤」となるのは本番コード側の契約assertionです。

---

## F. 取得口の振る舞いテスト

判定: REQUEST_CHANGES

[Critical] 正常系DNSに使う `203.0.113.10` はTEST-NET-3の文書用予約アドレスであり、公開到達可能IPとして扱われない可能性が高いです。

SSRF inspectorが予約レンジを拒否する実装なら、正常系がすべてSSRF拒否になります。

修正案:

- `ssrf-pin` 自身のテストで「許可される公開IP」として使用しているfixtureを採用する
- 少なくとも、正常系fixtureについて `inspect()->allowed === true` を先に固定する

[Warning] F15の例外型が不正確です。Laravelの非LockProvider storeでは通常 `BadMethodCallException` 系になります。`InvalidArgumentException` 固定は実装と合わない可能性があります。

修正案:

- Laravel 12の実際の契約に合わせる
- 本質が「握り潰さずfail-fast」であれば、例外型を過剰に固定せず、そのまま伝播することを検査する

[Warning] 次のテストが不足しています。

- 3xxを受理せず、Locationへ追従しない
- HTTP request optionにconnect/total timeoutが設定される
- TLS verificationが有効なまま
- 成功時・HTTP失敗時・PEM不正時のすべてでロックが解放される
- cache TTLが設定値どおり
- URLが異なればcache keyも異なる
- テスト間で同一 `CERT_URL` のcacheが残らない

特にG/Hと同じcache keyを共有するため、`RefreshDatabase`だけに依存せず、各テストで対象cache key/storeを明示的に隔離してください。

---

## G. 署名検証器テスト

判定: REQUEST_CHANGES

[Warning] G4/G5の既存fixtureは、新実装では目的の段階まで到達しません。

```text
cert-body
-----BEGIN CERTIFICATE-----\nnot-a-real-cert...
```

はいずれも `isReadablePem()` で拒否されるため、「署名検証段で失敗した」ことを証明しません。

修正案:

- `SnsTestData::certificatePem()` の「PEMとして有効だが署名とは一致しない証明書」を使う
- G4/G5では `SnsSignatureInvalidException` の型だけでなく、HTTP送信と未キャッシュを併せて検査する

[Warning] G9はcache状態に左右されます。同一URLを使う他テストから独立するよう、対象cache keyの初期化またはテスト専用storeを明示してください。

G6、G7、G8、G9の追加方針自体は妥当です。

---

## H. middleware end-to-endテスト

判定: REQUEST_CHANGES

[Warning] 実verifierのE2Eが失敗系だけです。DI、自動解決、実証明書検証、controller到達までを通す成功系がありません。

修正案:

- `signedNotification()` と対応PEMを用いて実verifier経由の成功テストを1件追加する
- bounce通知なら、期待する成功ステータスと `EmailSuppression` 作成まで確認する
- 同一通知の2回目でHTTP取得が増えないことも、可能ならこの層で確認する

H1〜H3のステータス分類テストは適切です。

---

## I. 署名済み通知の生成

判定: REQUEST_CHANGES

[Warning] 詳細設計としては擬似コード部分が不足しています。特にPHPStan level 10で問題になりやすい次を確定してください。

- `openssl_csr_new()` の `false` narrowing
- `openssl_x509_export()` の戻り値と出力変数
- `openssl_sign()` の戻り値
- `$signature` の初期化
- private key / PEMの静的キャッシュshape
- OpenSSL warningが例外化された場合の扱い

例えば署名は少なくとも次の形が必要です。

```php
$signature = '';
Assert::true(openssl_sign(
    $stringToSign,
    $signature,
    $privateKey,
    OPENSSL_ALGO_SHA1,
));
```

[Warning] lambdaキー単独のfixture生成方法を明記してください。現在の `notification()` は常に `SigningCertURL` を入れるため、overrideで `SigningCertUrl` を追加するだけでは「両キー同時」になります。

キーを削除できるビルダーAPI、またはlambda専用builderが必要です。

---

## J. 既存目録更新

判定: APPROVE

目録を実装後の実測に合わせてexact-fitで更新する方針は適切です。不要なテストファイルを先回りで登録しない判断も正しいです。

ただし、施策Eを修正して次の責務をどこかのgateへ必ず登録してください。

- SSRF検査とHTTP optionの配線
- 検証成功後だけのcache昇格
- `remember`相当の呼び出しsiteの唯一性

`AwsSnsSignatureVerifier` の rationale は「MessageValidator自身はtransportを構築せず、証明書取得callbackをFetcherへ委譲する」の方が正確です。

---

## K. 文書更新

判定: REQUEST_CHANGES

[Warning] 現状のまま文書化すると、次を実際より強く保証することになります。

- lock TTLによるpermit 1
- 署名検証済みだけのcache昇格
- 外部HTTP取得口の唯一性
- cache read障害時の継続性

修正案:

- DNS検査時間と未強制の後処理余裕を含めてロック保証を条件付きにする
- 唯一性は「指定した走査根・検出可能なHTTP client参照の範囲」と明記する
- キャッシュ昇格を構造的に閉じてから「検証済みのみ」と記載する
- cache store障害ではlock取得も失敗し503になり得ることを書く

---

## 観点別補足

- DTO/JsonResource、Inertia Props/API Response: 本施策にはHTTPレスポンスshapeや画面変更がなく、対象外です。
- DESIGN.md、Atomic Design、TypeScript型: frontend変更がないため対象外です。
- 認可・tenant境界: 外部webhook署名境界の変更であり、新たなtenant key入力やnested routeはありません。
- OWASP/SSRF: URL値オブジェクト、redirect禁止、SSRF inspectorの方向は適切ですが、DNS検査と接続のTOCTOUは残存します。残存リスクの明記だけでなく、ロック予算からDNS時間が抜ける問題は修正必須です。

上記のCriticalを解消し、G4/G5を有効な非一致証明書へ変更すれば、全体として承認可能な設計になります。