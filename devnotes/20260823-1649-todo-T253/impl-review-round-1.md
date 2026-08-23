## 全体判定

`CHANGES_REQUESTED`

OIDC の主要な状態遷移・SSRF 経路・tenant 境界は丁寧に設計されていますが、認可コード等のセッション漏洩が実際に発生するため承認できません。さらに、メール昇格の one-time 性、キュー上の token、JWK 検証、JWKS 再取得排他にも修正が必要です。

## ファイル別判定

### [EnterpriseSsoCallbackRequest.php](/workspace/.claude/worktrees/tasks/T253/app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php)  
### [ConfirmEmailPromotionRequest.php](/workspace/.claude/worktrees/tasks/T253/app/Http/Requests/Auth/ConfirmEmailPromotionRequest.php)  
### [bootstrap/app.php](/workspace/.claude/worktrees/tasks/T253/bootstrap/app.php)

[Critical] FormRequest の validation failure で、認可コード・state・メール確認 token がセッションへ保存されます。

Laravel は validation failure 時、controller に到達する前に入力を `_old_input` として flash します。実装コメントの「controller で `withInput()` を呼ばない」はこの経路を防ぎません。Laravel の実装も `withInput(Arr::except($request->input(), $this->dontFlash))` です。[Laravel validation documentation](https://laravel.com/index.php/docs/12.x/validation)、[framework Handler source](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Foundation/Exceptions/Handler.php)

例えば次の入力で再現します。

```text
GET /enterprise/callback?state=<secret>&code=<authorization-code>&error=access_denied
```

`code` と `error` の排他検査が失敗し、`state` と `code` が `_old_input` に残ります。メール昇格でも、長すぎる `token` を POST すると同様です。

`dontFlash` には `client_secret` しか追加されておらず、`code`、`state`、`token` は対象外です。汎用名をグローバル登録しない方針なら、両 FormRequest の validation failure を入力なしの redirect に変換してください。

必要な回帰テストは、controller/service の失敗ではなく、実際に validation を失敗させた後の session に以下が存在しないことです。

```php
_old_input.code
_old_input.state
_old_input.token
```

---

### [OidcDiscoveryService.php](/workspace/.claude/worktrees/tasks/T253/app/Services/EnterpriseSso/OidcDiscoveryService.php)

[Warning] 未知 `kid` に対する JWKS 再取得が原子的ではありません。

提示実装の `refetchJwks()` は、最終再取得時刻の `get` → 判定 → `put` を行うだけで、設計にある「接続 ID 単位のロック」を取得していません。

同じ接続に未知 `kid` の callback が二つ同時に来ると、両方が古い時刻を読み、両方が JWKS を再取得できます。攻撃者が署名不正の token を並行投入すると、IdP への外向き取得を増幅できます。

接続単位の lock 内で時刻を再確認し、lock 基盤の失敗時は fail-closed にしてください。並行テストは「同じ未知 `kid` の同時要求で transport 到達が一回」を測る必要があります。

---

### [EmailPromotionService.php](/workspace/.claude/worktrees/tasks/T253/app/Services/Auth/EmailPromotionService.php)

[Warning] 競合時に token の削除も rollback され、one-time consume が成立しません。

`confirm()` は promotion 行を削除してからメールを更新しますが、blind index の一意制約違反を `EmailPromotionConflictException` として transaction 内から投げています。そのため削除も rollback されます。

具体的には、別利用者が既に所有するメールを昇格対象にすると、

1. promotion 行を削除
2. メール更新が一意制約違反
3. 例外
4. transaction rollback
5. promotion 行が復活

となり、同じ token を期限まで繰り返し送信できます。

競合を transaction 内では結果値として返し、promotion 行の削除を commit した後で一様な例外へ変換してください。「競合後に行が消えている」「同じ token の二回目は無効」をテストで固定する必要があります。

[Warning] メールを既に持つ利用者にも昇格フローを許しており、既存のメール変更経路を迂回できます。

例えば `old@example.com` を確認済みの通常利用者が `new@example.com` を発行・確認すると、このサービスがそのままメールを上書きします。既存のプロフィール更新経路とは別の、監査や旧メール通知を通らないメール変更経路になります。

機能名どおり「メールを持たない利用者の昇格」なら、発行時と確定時のロック内で `email === null` を要求してください。通常のメール変更も意図するなら、既存の変更規約へ統合する必要があります。

[Warning] 詳細設計にある、成功したメール変更の security audit が実装されていません。

成功時に token や平文メールを記録せず、利用者 ID と固定イベント種別だけを既存監査基盤へ記録してください。

---

### [EmailPromotionMail.php](/workspace/.claude/worktrees/tasks/T253/app/Mail/EmailPromotionMail.php)

[Warning] 生の確認 token が暗号化されない queue payload に保存されます。

`ShouldQueue` の Mailable は queued job として serialize されます。private property であっても serialization の対象です。Laravel が queue payload を暗号化するのは `ShouldBeEncrypted` を実装した job/mailable です。[Laravel mail documentation](https://laravel.com/docs/12.x/mail)、[Laravel queue encryption](https://laravel.com/docs/12.x/queues)、[SendQueuedMailable source](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Mail/SendQueuedMailable.php)

database/Redis/SQS queue を閲覧できる主体がいる場合、token と宛先を取り出し、利用者として確認操作を完了できます。

`EmailPromotionMail` に `ShouldBeEncrypted` を実装し、その契約を Architecture テストの inventory に固定してください。

---

### [OidcJsonWebKeySet.php](/workspace/.claude/worktrees/tasks/T253/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php)

[Warning] `key_ops` を部分文字列で判定しているため、検証用途でない鍵が受理されます。

`key_ops` を空白連結した後に `str_contains(..., 'verify')` で調べる実装では、次が通ります。

```json
{
  "key_ops": ["notverify"]
}
```

RFC 7517 の `key_ops` は大文字小文字を区別する文字列配列で、検証用途は完全一致の `"verify"` です。[RFC 7517 §4.3](https://www.rfc-editor.org/rfc/rfc7517.html#section-4.3)

配列のまま具体型を検証し、`in_array('verify', $keyOps, true)` で判定してください。少なくとも次を負例に追加すべきです。

```json
["notverify"]
["VERIFY"]
["verify", "verify"]
```

[Warning] `use` が存在するが文字列でない場合に、欠落として扱われます。

例えば以下は malformed JWK ですが、正規化時に `use` が捨てられ、「optional なので欠落可」として進みます。

```json
{"use": ["sig"]}
```

存在する既知 field は具体型が違えば拒否してください。`key_ops` の非配列・非文字列要素も同様です。

---

### [EnterpriseSsoOutboundHttpGateTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php)

[Warning] G2-4 は file 単位の部分文字列検査なので、安全でない `fetch()` を見逃します。

現在は `->fetch(` を持つファイルに `followRedirects: false` が一箇所でもあれば green になります。例えば同じファイルへ次を追加しても、既存の安全な呼び出しがあるため検査は通ります。

```php
$this->pinned->fetch($unsafeRequest, $deadline);
```

`fetch()` 呼び出しごとに第 3 引数を確認するか、安全な呼び出し位置を exact-fit で数えてください。「一つは安全・もう一つは既定値」の負例 fixture が必要です。

---

### [Index.svelte](/workspace/.claude/worktrees/tasks/T253/resources/js/pages/Organizations/Sso/Index.svelte)

`APPROVE`

未充足条件を理由に action button を disabled にせず、押下後の server validation に任せています。FormField/Input、DS token、Lucide、Atomic Design の依存方向にも、提示差分上の違反はありません。

---

### [confirm.blade.php](/workspace/.claude/worktrees/tasks/T253/resources/views/auth/email-promotion/confirm.blade.php)

`APPROVE`

GET で状態を変更せず、POST の明示操作に分離した判断は妥当です。token を Inertia history state に載せず、外部 resource と Referer を閉じている点も設計と一致します。

ただし、上記 FormRequest の validation-flash 問題により、POST 後の異常系では token がセッションへ移動します。Blade 自体ではなく入力境界側の修正が必要です。

---

### [routes/web.php](/workspace/.claude/worktrees/tasks/T253/routes/web.php)

`APPROVE`

T247 後の組織 URL、`scopeBindings()`、recent-auth、管理操作の throttle 分離、公開接続 binder の一様な missing 応答は妥当です。操作系 route の認可 inventory も提示差分上は対応しています。

[Suggestion] 提示された UI 差分には、メール昇格の発行・再送 route を利用者が操作するフォームが見当たりません。既存画面に呼び出し元がない場合、メールなし利用者は HTTP を手組みしないと機能を開始できません。設定画面の導線が別ファイルに既にあるなら、そのテスト／変更ファイルをレビュー対象に明示してください。

## 実装側の適応の判定

| # | 判定 | コメント |
|---|---|---|
| 1 | APPROVE | ssrf-pin 0.4 の実 API への追随は妥当。transport 全体上限の内側に用途別上限を置く二層構造も成立しています。 |
| 2 | APPROVE | `login_slug` は既存 gate の不変条件を壊さず、意味も明確です。 |
| 3 | APPROVE | explicit binder と一様な `missing()` は、直接 fetch gate と存在オラクルの双方へ適応できています。 |
| 4 | APPROVE | Fake IdP を `tests/Support` に置き、実 `UrlSafetyInspector` と ssrf-pin の transport seam を使う判断は、設計案より安全です。本番 route・fake binding を増やさない点も妥当です。 |
| 5 | APPROVE（限定） | 2 接続による pgsql 行ロックの実測としては十分です。実 OS process の PHP 競合を証明しないことも明記されています。「B4/C1/C2 全体の実プロセス競合を証明した」とは扱わないでください。 |
| 6 | APPROVE | 外向き交換と保存用暗号化の reveal を分けたことで、権限目的が明確になっています。 |
| 7 | APPROVE | null email の blind index 行を作らない override は、二人目以降の企業 SSO 利用者を作るために必要です。 |
| 8 | APPROVE |保存済み暗号文を読み直して snapshot を取るのが正しい比較です。 |
| 9 | APPROVE | 使用済み番号を再利用せず D45–D48 に分離した判断は妥当です。 |
| 10 | 要修正 | transaction 内 queue 投入の方針自体は既存規約と整合しますが、Mailable の queue payload 暗号化が欠けています。 |

## 最低限必要な修正

1. FormRequest validation failure で `code`、`state`、`token` を flash しない。
2. メール昇格の競合時にも token を不可逆に consume する。
3. queued Mailable を暗号化する。
4. JWK の `key_ops` と `use` を厳密に検証する。
5. 未知 `kid` の JWKS 再取得を接続単位で直列化する。
6. メール昇格の対象条件と監査を設計どおりに閉じる。
7. redirect-following gate を呼び出し単位の検査に直す。

なお、振る舞いテスト 29 ファイルは本文が提示されておらず、ツール制限上ローカル読み出しもできなかったため、テスト本体の逐行判定はしていません。ただし Critical の validation-flash は Laravel の確定した framework 経路から発生するため、全 green でも解消されません。現在のテストが controller/service の失敗だけを測り、FormRequest の validation failure を通していない可能性が高いです。