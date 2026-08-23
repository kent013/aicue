## 全体判定

`CHANGES_REQUESTED`

Round 1 の Critical は正しく塞がれています。JWKS ロックと queue 暗号化も妥当です。

ただし、メール昇格の二段化によって新しい競合窓が生じています。また、G2 の修正は名前付き引数の存在しか確認せず、`true` を見逃します。少なくともこの2点は修正が必要です。

## ファイル別判定

### [EnterpriseSsoCallbackRequest.php](/workspace/.claude/worktrees/tasks/T253/app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php)

`APPROVE`

`ValidationException` に明示的な response を設定した場合、Laravel は通常の validation redirect を組み立てず、その response を返します。したがって `Handler::invalid()` の `withInput()` は通らず、`code` と `state` は flash されません。

回帰テストも以下を実際に validation failure にしており、十分です。

- `code` と `error` の同時指定
- `state` 欠落
- `code` と `error` の両方欠落

`UniformLoginFailure` への集約も妥当です。

---

### [ConfirmEmailPromotionRequest.php](/workspace/.claude/worktrees/tasks/T253/app/Http/Requests/Auth/ConfirmEmailPromotionRequest.php)

`APPROVE`

実装上は、企業 SSO callback と同様に validation の既定 redirect を迂回できています。token をグローバル `dontFlash` に追加せず、機密性が必要な経路だけで閉じた判断も妥当です。

ただし、対応するテストには空振りがあります。後述します。

---

### [UniformLoginFailure.php](/workspace/.claude/worktrees/tasks/T253/app/Support/EnterpriseSso/UniformLoginFailure.php)

`APPROVE`

失敗時の行き先、文言、入力の扱いを一箇所へ集約できています。理由コードだけをログに残し、入力値や vendor 例外を載せていない点も問題ありません。

---

### [EmailPromotionService.php](/workspace/.claude/worktrees/tasks/T253/app/Services/Auth/EmailPromotionService.php)

[Warning] 消費の commit とメール適用の間に、別経路のメール更新が割り込むと、その更新を上書きします。

現在の順序は次のとおりです。

1. 第1段で利用者をロックし、`email === null` を確認
2. promotion 行を削除して commit
3. ロックを解放
4. 第2段で、条件を再確認せず `users.email` を更新

次の競合が成立します。

```text
A: confirm 第1段で email=null を確認し、token を消費して commit
B: プロフィール等の別経路で email=other@example.com を設定して commit
A: 第2段で email=new@example.com を forceFill し、B の更新を上書き
```

「発行後に別経路でメールが入ったら確定できない」テストは、第1段より前にメールを入れる場合しか測っておらず、この commit 間の窓を固定していません。

第2段でも利用者行をロックして読み直し、`email === null` のときだけ適用してください。例えば適用結果を次のように分類できます。

- 適用成功
- 第1段後にメールが設定されたため適用しない
- blind index 競合

token はどの場合も既に消費済みのままで構いません。これなら一回使用を維持しながら上書きを防げます。

[Suggestion] `applyVerifiedEmail()` の SQL が失敗すると、渡された `$user` インスタンスには `forceFill()` した未保存値が残ります。現在は例外後に再利用していないため直ちに障害にはなりませんが、競合を outcome として扱うなら、DB の fresh instance を内部で取得する方が安全です。

---

### [EmailPromotionTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Feature/Auth/EmailPromotionTest.php)

[Warning] 「トークンが長すぎる」dataset が validation failure を発生させていません。

使用している値は次の18文字です。

```text
super-secret-token
```

一方、rule は以下です。

```php
'max:'.(AttemptFingerprint::HEX_LENGTH * 4)
```

`HEX_LENGTH` が指紋の16進長である64なら上限は256文字です。この入力は validation を通り、service の「存在しない token」分岐へ進みます。controller が `withInput()` を使わないことしか測れておらず、Round 1 の Critical に対する `failedValidation()` の回帰テストになっていません。

必ず上限から生成してください。

```php
str_repeat('x', AttemptFingerprint::HEX_LENGTH * 4 + 1)
```

さらに validation failure だったことを間接的に保証するため、service を mock して未呼び出しを確認するか、少なくとも入力が規則上確実に不正になる値を使ってください。

[Warning] 二段構成の新しい競合窓を測るテストがありません。

第1段の commit 後、第2段の適用前に別接続から利用者のメールを更新する割り込みテストが必要です。成功条件は以下です。

- 別経路で設定したメールが残る
- promotion 行は消費済み
- 昇格の監査イベントは作られない
- 同じ token は再利用できない

---

### [EmailPromotionMail.php](/workspace/.claude/worktrees/tasks/T253/app/Mail/EmailPromotionMail.php)

`APPROVE`

`ShouldQueue` と `ShouldBeEncrypted` の併記で、queued mailable の payload 暗号化が有効になります。Round 1 の指摘は解消しています。

`SensitiveParameter` は queue serialization 自体を防ぐものではありませんが、ここでは `ShouldBeEncrypted` がその責務を担っているため役割分担も正しいです。

---

### [OidcJsonWebKeySet.php](/workspace/.claude/worktrees/tasks/T253/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php)

`APPROVE`

Round 1 の具体的な欠陥は解消しています。

- `notverify` は通らない
- 大文字の `VERIFY` は通らない
- `use` や `kty` 等の既知 member が型違反なら拒否する
- `key_ops` の非配列・非文字列要素を拒否する
- 区切り文字を含む operation を拒否する

キャッシュへ素のデータだけを入れるための scalar 化としても成立しています。

[Suggestion] テストでは重複した `["verify", "verify"]` を正例にしています。RFC上の強い必須条件ではないため直ちに脆弱性とはしませんが、重複に意味はなく malformed 寄りです。deny-by-default を優先するなら、正規化時に重複を拒否する方が単純です。

---

### [OidcDiscoveryService.php](/workspace/.claude/worktrees/tasks/T253/app/Services/EnterpriseSso/OidcDiscoveryService.php)

`APPROVE`

接続単位の non-blocking lock、lock 内での最小間隔再確認、基盤障害時の fail-closed は妥当です。

15秒の lease は現在の discovery 時間予算である最大8秒より長く、合理的な余裕があります。設定値を将来変更して時間予算が15秒以上になると前提が崩れるため、設定検査で次を固定するとより堅牢です。

```text
JWKS_REFETCH_LOCK_SECONDS
    > connect_timeout_seconds + request_timeout_seconds
```

[Suggestion] `$lock->release()` の例外は現在固定理由へ変換されず、そのまま伝播します。「ロック基盤の障害は一様な拒否」という契約を release 障害にも適用するなら変換が必要です。ただし callback が500になるだけで認証を許可する方向には倒れないため、承認阻害とはしません。

---

### [OidcDiscoveryServiceTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php)

`APPROVE`

ロック保持中の拒否、外向き通信ゼロ、解放後の正例、接続 ID 間の分離を測っており、実装の分岐を十分に固定しています。

厳密には本テストは「二要求が実際に同時実行され、片方だけが取得した」ことまでは測りませんが、lock の適用箇所と non-blocking failure を測るテストとしては妥当です。保証範囲を誇張していません。

---

### [EnterpriseSsoSourceScanner.php](/workspace/.claude/worktrees/tasks/T253/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php)  
### [EnterpriseSsoOutboundHttpGateTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php)

[Warning] `followRedirects:` の存在だけを検査しており、値が `false` かを確認していません。

次の危険な呼び出しが green になります。

```php
$this->pinned->fetch(
    $request,
    $deadline,
    followRedirects: true,
);
```

gate の名前とエラーメッセージは「追従を明示的に切る」と主張していますが、実際の保証は「`followRedirects:` という名前付き引数がある」だけです。

`callsMissingNamedArgument()` ではなく、名前付き引数の値が literal `false` であることまで確認してください。解決できない式も fail-closed が必要です。

```php
followRedirects: $configuredValue
followRedirects: ! false
followRedirects: false || true
```

これらも許可せず、正確な literal `false` だけを通すのが、この gate の役割に合います。

---

### [RedirectFollowingSample.php.txt](/workspace/.claude/worktrees/tasks/T253/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt)  
### [EnterpriseSsoSourceScannerTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php)

[Warning] Round 1 の「一つは安全、一つは引数省略」の負例は追加されていますが、上記 `followRedirects: true` の負例がありません。

最低限、次の3方向が必要です。

- 引数そのものがない
- `followRedirects: true`
- 値が動的で静的に `false` と確定できない

正例は literal `followRedirects: false` のみとしてください。

---

### [SecurityController.php](/workspace/.claude/worktrees/tasks/T253/app/Http/Controllers/Settings/SecurityController.php)  
### [Security.svelte](/workspace/.claude/worktrees/tasks/T253/resources/js/pages/Settings/Security.svelte)

`APPROVE`

メールなし利用者だけに導線を表示しつつ、server 側でも発行・確定時に条件を再検査しています。UI の `canPromoteEmail` を認可境界として扱っていないため適切です。

未入力でもボタンを押せるため、禁止事項8にも抵触しません。既存の `FormField`、`Input`、`Button` を使用し、提示差分上の DS token・Atomic Design 違反もありません。

## 再確認事項への回答

1. `failedValidation()` の塞ぎ方  
   **十分です。** response を持つ `ValidationException` により既定の `withInput()` を迂回できます。ただしメール昇格側の回帰テスト入力は修正が必要です。

2. メール昇格の二段構成  
   token の一回使用は成立しました。しかし二段間で別のメール更新を上書きする競合が新しく生まれています。第2段でも利用者を再ロックして `email === null` を確認してください。

3. JWKS 再取得ロック  
   寿命、非待機、fail-closed の判断は妥当です。現在の時間予算と lease の大小関係をテストで固定するとさらに安全です。

4. 新しく生まれた欠陥  
   承認阻害となるのは、メール適用前の再確認不足と、G2 が `followRedirects: true` を通す点です。メール validation のテスト空振りも修正が必要です。

`CHANGES_REQUESTED`