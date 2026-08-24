再レビューの仮説は「Round 1の修正によって、身元の永続性・OIDC標準適合・秘密保持・並行実行・既存gate規約まで一貫したか」です。

PinnedHttpClientへの一本化、B4のcommit後拒否、認証材料更新時のDraft差し戻し、secretを一覧DTOから除いた点は適切です。一方、永続subject指紋、callbackと無効化の競合、秘密値型、E1の未設計部分に重大な問題が残っています。

## A1: 設定・指紋鍵

判定: REQUEST_CHANGES

- [Critical] `subject_fingerprint` をAPP_KEY由来にしたことで、「APP_KEYローテートで失効するのは進行中の試行だけ」という説明が成立しません。

  C1は永続的な身元検索にも次を使っています。

  ```php
  AttemptFingerprint::of(FingerprintPurpose::Subject, $claims->subject)
  ```

  APP_KEYを変更すると既存の全subject fingerprintを再現できなくなり、次回ログインで別User・別IdentityをJIT作成します。これはアカウント分裂です。

  修正案: 一時的な `state` / `nonce` / browser bindingと、永続的なsubjectを分離してください。subjectはローテーション契約を持つ専用の安定鍵、または既存CipherSweet鍵を用いた専用の決定的fingerprintへ移し、鍵の版管理・再計算手順も設計してください。`AttemptFingerprint` に永続subjectを持たせない方が名前にも合います。

- [Warning] EmailPromotionで使うfingerprint purposeが4種類の一覧に存在しません。

  修正案: `EmailPromotionToken` の用途を独立して追加し、state等のラベルを流用しないことをテストしてください。

## A2: モデル・秘密値型

判定: REQUEST_CHANGES

- [Critical] `__debugInfo()` は `var_dump()` 等には効きますが、`var_export()` から秘密を隠しません。提示したクラスではprivateな `$plaintext` が出力に現れ得るため、予定している「var_exportに平文が出ない」テストは通りません。

  修正案: `__debugInfo()` の保証をvar_dump系に限定し、`var_export`・serialize・Reflectionまで安全という主張を削除してください。そのうえで、secret値型をログ・dump・直列化関数へ渡す記法をgateで禁止し、実際の例外・監査・ログ境界で漏洩しないことを主証明にしてください。任意のPHP introspectionから平文を隠す保証は置くべきではありません。

- [Warning] Round 1対応表では「列・cast・relationを列挙した」とありますが、本文には3モデルすべてのcasts/relationsがありません。特に以下が未確定です。

  - `client_secret_encrypted` と `pkce_verifier_encrypted` のcast
  - status enum cast
  - immutable datetime cast
  - connection→identities/attempts
  - identity→connection/user
  - attempt→connection
  - 秘密列の `$hidden`

  修正案: 各モデルについてcasts・hidden・relation・Factory genericsを明記してください。一覧で復号しないだけでなく、モデルの `toArray()` から暗号文や秘密値型を出さないことも固定してください。

## A3: users.email nullable化

判定: REQUEST_CHANGES

- [Warning] `email = null` なのに `email_verified_at = now()` とする形は、後から別経路でemailだけが設定された場合、その新しいemailが自動的に確認済みになります。

  修正案: null→非nullの全更新経路を棚卸しし、EmailPromotion以外では必ず `email_verified_at` を消すことを固定してください。EmailPromotionの確定時は、以前の値を維持するのではなく、新しいemailを実際に確認した時刻へ更新する方が監査上正確です。

- [Warning] 4つの新規モデルに対する必須文書更新が施策にありません。

  修正案: AGENTS.mdに従い、`docs/architecture.md` と `docs/factories.md` へ4モデルを追加してください。EmailPromotionも `MassAssignmentSafetyTest` の母集団に含める必要があります。

## B1: discovery/JWKS取得

判定: REQUEST_CHANGES

- [Critical] issuerの「末尾スラッシュを正規化」はOIDC issuerの完全一致要件を弱めます。`https://idp.example/tenant` と `https://idp.example/tenant/` は別のissuer識別子になり得ます。

  修正案: 登録issuerの識別文字列は勝手に末尾スラッシュ正規化せず保存し、discovery文書のissuerと仕様どおり完全一致させてください。パス付きissuerについてwell-known URLが正しく構築されるテストも必要です。

- [Critical] `token_endpoint_auth_methods_supported` はOIDC Discovery上optionalで、欠落時の既定は `client_secret_basic` です。欠落を「対応方式なし」として拒否すると、仕様準拠IdPを拒否します。

  修正案: フィールド欠落時はbasicとして扱い、明示された場合だけbasic→postの優先順位で選択してください。欠落・空配列・未知値だけ・basic/post混在を別々にテストしてください。

- [Warning] metadata内endpointの検査は「HTTPS絶対URL」だけでなく、少なくともuserinfoとfragmentを拒否してください。queryは仕様上必要なIdPがあり得るため、禁止するなら標準上の根拠が必要です。

## B2: トークン交換

判定: REQUEST_CHANGES

- [Critical] `PinnedHttpClient::fetch()` の戻り値は `PinnedResponse|PinnedFailure` ですが、コード例は `PinnedFailure` を分岐せず、そのまま `OidcTokenResponse::fromPinnedResponse()` へ渡しています。`catch (Throwable)` では値として返るfailureを処理できません。

  修正案: `PinnedFailure` を明示分岐し、固定理由コードの例外へ変換してください。2xx、body上限、JSON object、必須 `id_token` の検査もDTO構築前に確定させてください。

- [Warning] `token.connect_timeout_seconds` がコード例で使われていません。「参照されない設定を作らない」というA1の方針に反します。

  修正案: v0.4の実APIに沿って接続期限と全体期限の両方へ反映するか、APIが個別接続期限を受けないなら設定自体を削除してください。

- [Warning] `client_secret_basic` の生成はOAuthのapplication/x-www-form-urlencoded規則に従う必要があります。`rawurlencode()` の独自連結でなく、既存ライブラリまたはRFC準拠の共通エンコーダを使い、空白・`+`・`:`・非ASCIIを含む資格情報でテストしてください。

- [Warning] 漏洩テストは平文だけでなく、Basicヘッダのbase64値とform-urlencodedされた値も対象にしてください。

## B3: IDトークン検証

判定: REQUEST_CHANGES

- [Warning] JWKの `use` はoptionalです。「`use` がsigでない」をそのまま実装すると、`use` 欠落の有効な鍵も拒否します。

  修正案: `use` が存在するときだけ `sig` を要求してください。`key_ops` と同じ条件付き検査として明記してください。

- [Warning] audience/azp規則を曖昧な論理和で書かず、次のように分離してください。

  - `aud` が複数なら `azp` 必須
  - `azp` が存在するなら文字列かつclient_idと一致
  - `aud` は必ずclient_idを含む

- [Warning] `firebase/php-jwt` を直接依存へ昇格するため、変更対象に `composer.lock` がありません。

  修正案: `composer.json` と `composer.lock` を同じ施策へ含めてください。

## B4: ログイン試行

判定: REQUEST_CHANGES

- [Critical] 独立DB接続の並行テスト方法が、グローバル `RefreshDatabase` と両立する設計になっていません。通常のテストトランザクション内で作ったfixtureは、2本目の接続から見えません。

  修正案: 既存の並行テストハーネスに合わせ、fixture可視性、同期点、後片付け、失敗時の子回収まで具体化してください。単に「2接続を使う」だけではテストは成立しません。C1とE1の並行テストも同じ設計を共有すべきです。

- [Warning] callback失敗時にbrowser binding secretを「成功時のみ破棄」すると、DB試行はconsume済みなのにセッション値だけが残ります。開始や失敗を繰り返すとセッションが増え続けます。

  修正案: 行が既に不可逆にconsumeされた失敗では対応するセッション値を削除し、binding mismatchのように試行を保持する場合だけ秘密も保持してください。

## C1: always-JIT

判定: REQUEST_CHANGES

- [Critical] APP_KEY由来subject fingerprintのため、現状ではAPP_KEYローテート後に既存identityへ到達できません。A1の修正がC1の前提です。

  修正案: 永続subject fingerprintの鍵を修正したうえで、鍵ローテーション後も既存Userへ戻るテストを追加してください。

- [Warning] `findIdentity()` はconnection relation起点で実装すると明記してください。クラス起点で接続IDを条件にする実装へ流れる余地を残さず、組織スコープの出所を型とrelationで固定する方が不変条件3と整合します。

## C2: 開始・callback

判定: REQUEST_CHANGES

- [Critical] 「外向き取得前」と「ログイン直前」の2回Activeを読むだけでは、disableとの競合を閉じられません。最終確認直後にdisableがcommitされ、その後ログインが確定する窓が残ります。

  さらに現在の順序ではJIT後に最終確認するため、最終確認で拒否されてもUser・Identity・membershipだけが残り得ます。

  修正案: callback確定とdisableの線形化点を定義してください。例えば外向き取得後にconnection行をロックし、Active確認→JIT→ログイン確定判断までをdisableと直列化します。少なくとも「disableが先に線形化した場合はJITもログインも起きない」「callbackが先ならdisableはその後に成立する」を並行テストで固定する必要があります。

- [Warning] セッションからbrowser binding secretを取得する手順と、欠落・非文字列時の扱いがコード契約にありません。

  修正案: state fingerprintから試行固有キーを導き、値が非空文字列でなければ外向き取得前に一様拒否することを明記してください。

## D1: 状態遷移

判定: APPROVE

単体の遷移規則と認証材料更新時のDraft差し戻しは妥当です。ただしC2との競合制御を同じconnection行で設計する必要があります。

## D2: 管理画面・管理route

判定: REQUEST_CHANGES

- [Critical] Laravel 12の標準的な `dontFlash` 登録場所が曖昧です。`AppServiceProvider.php（必要なら）` では実装者判断に委ねすぎており、秘密がold inputへ残る危険があります。

  修正案: `bootstrap/app.php` の `withExceptions()` で `Exceptions::dontFlash()` へ登録するなど、このリポジトリの既存方式に合わせて変更ファイルと実装点を確定してください。

- [Warning] indexを含む読み取りrouteの認可を明示してください。変更系6本だけでなく、接続一覧も組織権限なしでは読めない必要があります。

  修正案: controller actionごとのpolicy abilityと期待する403を表にしてください。

## E1: メール昇格

判定: REQUEST_CHANGES

- [Critical] 新規モデル・移行を宣言していますが、テーブルの列、外部キー、token fingerprintの一意制約、user単位の未消費制約、cast、relationがありません。

  修正案: A2と同じ粒度でEmailPromotionの完全なスキーマとモデル契約を記載してください。

- [Critical] controllerを追加するのにrouteが1本も設計されておらず、F2の「新規10 route」にも含まれていません。

  修正案: 発行・再送・確認の全routeについて、HTTP method、認証、認可、throttle、recent-auth、no-store、CSRF、名前を列挙し、既存のroute目録へ全件登録してください。

- [Critical] 期限を「設定値」としていますが、`config/enterprise-sso.php` にEmailPromotionのTTLがありません。また専用fingerprint purposeもありません。

  修正案: 正数・上限付きTTLと専用purposeをA1へ追加してください。

- [Warning] 昇格確定時は、新しいemailの確認時刻として `email_verified_at` を更新してください。「前後とも確認済み」というbooleanだけでなくtimestampの意味を保つ必要があります。

## F1: gate・走査器

判定: REQUEST_CHANGES

- [Critical] 「変数経由のメソッド呼び出しを検出したらすべて未解決として失敗」は実用上成立しません。設計内だけでも `$this->pinned->fetch()`、`$result->attemptOrFail()`、`$connection->clientSecret()` など通常のobject callが多数あります。import解決用の字句走査だけではreceiverの型まで解決できません。

  修正案: 「動的なメソッド名・可変クラス・可変callable」を禁止対象にし、通常の固定メソッド呼び出しは宣言型やPHPDocから解決する範囲を明示してください。完全な型解決が必要ならPHPStan/ASTを利用し、字句走査で全callを解決できるとは主張しないでください。

- [Warning] G3の「例外は理由文字列だけ」を、型のフィールド検査だけで証明するのは不足します。

  修正案: custom exceptionのconstructorをreason enumまたは限定文字列だけに閉じ、`previous` を受け取れない型にしてください。呼び出し箇所の実挙動漏洩テストと組み合わせて保証範囲を記載してください。

## F2: 目録登録

判定: REQUEST_CHANGES

- [Critical] 「10 routeの全分類」はOIDC管理・ログインrouteだけで、E1のEmailPromotion routeを含みません。feature全体の新規route全数にはなっていません。

  修正案: E1のroute確定後、全新規routeを母集団として再集計し、throttle・認可・recent-auth・nested・no-storeを分類してください。

## F3: 逸脱登録

判定: REQUEST_CHANGES

- [Warning] 対応マトリクスでは `routes/console.php` をD37対象へ含めたと書き、本文では明示的に除外しています。Round 1対応として不整合です。

  修正案: 既存台帳の「対象パス重複禁止」と、機構全体の対象パス網羅のどちらを優先するかを台帳規約に沿って解決してください。単に将来重複しそうという理由で、日次掃除の実行点をD37から外すのは不十分です。

- [Warning] D37の独自機構はB4だけでなく、AttemptFingerprintとC2の開始・consume結線にも広がっています。

  修正案: D37の対象を「DB試行方式を構成する全固有パス」から再抽出し、中央ファイルを除外する場合は除外しても追跡が切れない根拠を記載してください。

## F4: 偽IdP

判定: APPROVE

本番route不在、フラグ無効時の結線不在、fake往復とは別のPinnedHttpClient経路検査という三層は妥当です。

## 横断的指摘

- [Critical] 新規モデル4本に対して、AGENTS.md必須の `docs/architecture.md` / `docs/factories.md` 更新が施策一覧にありません。

  修正案: A2・E1の変更対象へ追加し、Itemリソースのチェックリストに沿ってください。

- [Warning] 必須検証コマンドの受入条件がありません。

  修正案: `composer test`、`composer phpstan`、`vendor/bin/pint --test`、全pnpm検証コマンドを受入条件へ明記してください。`composer fix` / `pnpm lint:fix` は検証コマンドの代替ではありません。

## 全体判定

CHANGES_REQUESTED

最優先は次の5点です。

1. 永続subject fingerprintをAPP_KEYから分離する  
2. callback確定とconnection無効化を線形化し、拒否ログインでJIT副作用を残さない  
3. `ConnectionSecret`の `var_export` 安全性という成立しない保証を修正する  
4. EmailPromotionのスキーマ・設定・route・目録を完成させる  
5. F1の「すべての変数経由callを未解決として失敗」という実装不能な走査契約を狭める