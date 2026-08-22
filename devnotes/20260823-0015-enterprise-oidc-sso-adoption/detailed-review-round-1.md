## レビュー方針

仮説は「正典追従の方向性は妥当だが、SSRF、OIDC subject の同一性、JIT 利用者、秘密情報、状態遷移、並行試験に実装不能または安全性を崩す穴が残っている」です。

成功条件を「詳細設計だけから実装者が重要判断を追加せず、安全な実装と再現可能なテストを書けること」と置いて確認しました。

## 施策別判定

### A1: 設定ファイルと値域 enum — REQUEST_CHANGES

- [Warning] 設定テストが `connect_timeout <= request_timeout` だけでは不足しています。0・負数、過大な leeway、TTL、body size を受け入れられます。

  修正案: 全整数について正数、合理的な上限、大小関係を固定してください。特に `leeway_seconds` と `login_attempt.ttl_seconds` はセキュリティ境界として上限も検査します。

- [Warning] B4 の指紋用サーバー秘密の出所とローテーション時の挙動が設定設計にありません。

  修正案: `APP_KEY` から用途別に HKDF/HMAC 導出するのか、専用秘密を要求するのかを決め、state・nonce・binding で domain separation する入力形式を固定してください。TTL 10 分の試行だけが失効するなら `APP_KEY` 由来でも、その判断を明記します。

---

### A2: モデル・移行・Factory — REQUEST_CHANGES

- [Critical] `subject` が通常の MySQL case-insensitive collation だと、OIDC 上は異なる `Alice` と `alice` が同一視されます。検索と一意制約の両方が I1 を誤って実装します。

  修正案: `subject` を binary/case-sensitive collation にするか、原文に加えてバイト列ベースの keyed fingerprint を一意キーにしてください。大小文字違いを別 identity とする Feature テストを追加します。

- [Critical] `organization_oidc_connections` の詳細列、制約、cast、relation が示されていません。特に slug の一意性、issuer、client ID、secret、状態、`verified_at`、親組織との外部キー、route scoped binding の relation 名を判断できません。

  修正案: 3 migration すべての列・index・unique・FK・cast・relation を詳細設計に列挙してください。公開ログインに使う接続 slug は推測可能性と全体一意性も定義します。

- [Warning] G1 の「身元表の索引が 0 本」は記述が矛盾しています。表には複合 unique、FK index が存在します。

  修正案: 「`claimed_email_encrypted` または対応する blind index を含む索引が 0 本」と正確に定義してください。

- [Critical] `EncryptedSecretStringCast` の値型が不明確です。常に伏字なら token 交換できず、平文へ暗黙変換できるなら DTO・ログ・例外へ漏れ得ます。

  修正案: 秘密値を暗黙に文字列化できない型にし、平文取り出しを token exchanger の限定メソッドに集約してください。`__toString()` は持たせず、デバッグ表現も伏字にします。

---

### B1: discovery/JWKS 取得 — REQUEST_CHANGES

- [Critical] 抜粋コードは `UrlSafetyInspector` の検査後に通常の `HttpFactory::get()` を実行しています。検査と接続の間で名前解決が変わり得るため、AGENTS.md の `PinnedHttpClient` 境界を満たしません。「DNS rebinding は解消しない」という仕様化も不変条件 8 の後退です。

  修正案: 前段の `ssrf-pin ^0.4` が提供する pin 済みクライアントで、検査・名前解決・接続を一つの経路にしてください。`HttpFactory` で再取得しない構造を G2 で固定します。

- [Critical] 登録 issuer が HTTPS であることが保証されていません。`config/ssrf-pin.php` は HTTP も許しているため、client secret、code、token が平文通信へ流れ得ます。

  修正案: enterprise OIDC 自身の入力規則として HTTPS、userinfo/query/fragment なし、正規化可能な絶対 URL を必須化してください。HTTP が必要な偽 IdP は本番モデルへ登録せず fake seam で扱います。

- [Warning] endpoint の同一 origin 強制は OIDC 標準の要件ではなく、正当な discovery 構成を拒否します。Google 等、issuer・token・JWKS が別 origin のプロバイダーがあります。

  修正案: AG-200 の正典が同一 origin を必須としていないなら独自強化を外し、discovery から得た各 HTTPS URL を個別に PinnedHttpClient 境界へ通してください。残すなら対応対象 IdP を明記し、互換性判断を受入条件にします。

- [Warning] cache を触るファイル、cache key、破損値の `forget`、G11 の静的目録登録が施策一覧にありません。

  修正案: metadata/JWKS/refetch timestamp ごとの保存スキーマを素の配列・scalar で定義し、`CachePayloadPlainDataGateTest` の目録と破損 cache テストを変更対象へ追加してください。

---

### B2: トークン交換 — REQUEST_CHANGES

- [Critical] `pinnedPost()` の契約が設計されていません。B1 と同様に「inspect 後に通常 HTTP POST」なら不変条件違反です。

  修正案: ^0.4 の body 付き pinned request API を具体的な依存型・呼び出し順で示し、pin 済み接続以外では POST できない設計にしてください。

- [Critical] transport 例外を previous exception として連結すると、request body や認可コード、client secret をログ処理が展開する可能性があります。

  修正案: token 交換境界では vendor 例外を外へ連結せず、固定 reason code の例外へ変換してください。引数には `#[SensitiveParameter]` を付け、HTTP client の request logging も対象にした漏洩テストを置きます。

- [Warning] 常に `client_secret_post` を使う設計ですが、discovery DTO に `token_endpoint_auth_methods_supported` がありません。

  修正案: v1 で対応する認証方式を一つに固定するなら、metadata による対応確認と拒否理由を設計してください。可能なら body 漏洩面が小さい `client_secret_basic` を優先し、正典の方式と一致させます。

---

### B3: ID トークン検証 — REQUEST_CHANGES

- [Critical] JWT/JWK の拒否条件が不足しています。`kid` 重複、`kid` 欠落、`kty`、EC の `crv`、JWK の `use`/`key_ops`、不正な claim 型、欠落した `exp`/`iat` が明示されていません。

  修正案: 使用ライブラリと、その戻り値を再検査する責務を確定し、各条件を deny-by-default で列挙してください。少なくとも malformed JWT、署名不一致、重複 kid、alg–kty/crv 不整合、aud の不正型、時刻 claim の欠落・不正型を負例へ追加します。

- [Warning] 未知 kid の再取得制限について、キーの粒度、原子性、cache 障害時の挙動がありません。

  修正案: 接続 ID または issuer fingerprint 単位のロックと最終再取得時刻を scalar で保持し、同時要求でも再取得が一回になるテストを追加してください。

---

### B4: ログイン試行保管 — REQUEST_CHANGES

- [Critical] 期限切れ分岐は `delete()` 後に例外を投げるため、`DB::transaction()` が削除をロールバックします。「オンアクセスで掃除する」という設計どおりには動きません。

  修正案: トランザクション内では結果 DTO/enum を返し、commit 後に期限切れ例外を投げるか、掃除と拒否を別トランザクションに分けてください。

- [Critical] 「DB の一意制約と行ロックはドライバの種別に依存しない」は誤りです。SQLite の `SELECT ... FOR UPDATE` は MySQL/PostgreSQL と同じ排他契約を持ちません。

  修正案: 本番 DB を契約対象として明記し、その DB を使う integration lane で二つの独立接続と同期点を設けて競合を再現してください。Pest の `--parallel` は同じ行への同時アクセス試験の代用になりません。

- [Warning] browser binding secret の生成、session key、再利用範囲、成功後の破棄が未定義です。

  修正案: CSPRNG 生成、試行ごとの session 保存、callback での取得、成功時のみ破棄、binding mismatch 時は保持、複数タブ時の扱いを定義してください。

---

### C1: always-JIT — REQUEST_CHANGES

- [Critical] 既存 `User` の email/name/password/`email_verified_at` 制約との接続がありません。`verified` middleware 下の dashboard に、メールを持たない企業専用利用者が到達できるかも未定義です。

  修正案: JIT 利用者の各列を具体化してください。email を nullable 化するのか、`hasVerifiedEmail()` を認証方式込みで再定義するのか、企業 SSO の本人確認を `verified` とどう整合させるかを Feature テストで固定します。仮メール文字列による回避は避けます。

- [Critical] 組織所属時の初期 role と、Laratrust の `laratrust_team_id` の明示がありません。

  修正案: always-JIT に付与する最小 role を正典どおり定義し、所属・role 付与の全操作で organization の team ID を明示してください。別組織の role が参照されないテストを追加します。

- [Critical] `UniqueConstraintViolationException` を一律に identity 競合として回復すると、users、membership、blind index 等の別の一意制約違反も握り潰します。

  修正案: constraint 名または SQLSTATE と対象 index を照合し、`enterprise_identities(connection_id, subject)` の競合だけを回復してください。他の制約違反は再送出します。

---

### C2: callback/controller/routes — REQUEST_CHANGES

- [Critical] redirect 側の state、nonce、PKCE verifier/challenge、`openid` scope、authorization URL 構築が詳細設計にありません。

  修正案: CSPRNG のバイト数、base64url 形式、PKCE S256、必須 parameter、session binding 保存順を明記し、redirect URL の完全なテストを追加してください。

- [Critical] callback の入力制約がありません。巨大な state/code、不正な配列入力、IdP の `error` 応答をそのままサービスへ渡せます。

  修正案: 専用 FormRequest または controller 境界で scalar 型・長さ・相互排他を検証してください。失敗応答は一様にしつつ、外向き HTTP を開始しないことも検査します。

- [Warning] callback 時点で接続がまだ `Active` か再確認することを明記してください。redirect 後に管理者が無効化した場合、JIT とログインを成立させてはいけません。

  修正案: attempt に紐づく接続を callback で再取得し、active 状態を外部取得前と認証確定直前に判定します。

- [Warning] `remember: true` は接続無効化後も remember cookie から新しい session を開始できるため、「既存 session の継続」より強い存続になります。

  修正案: 正典が明示していなければ企業 SSO は `remember: false` を既定にし、永続ログインを許すならスコープ外事項との整合を文書化してください。

---

### D1: 状態遷移 — REQUEST_CHANGES

- [Critical] `update` と状態遷移が整合していません。Active 接続の issuer/client ID/secret を変更しても Active のままなら、未検証の構成で直ちにログインできます。一方、許可遷移には Active/Verified → Draft がありません。

  修正案: 表示名だけの更新と認証材料の更新を分離してください。issuer/client ID/secret の変更時は `Draft` へ戻し、`verified_at` を消し、再 verify・activate を必須にします。更新と状態変更は一つのトランザクションにします。

---

### D2: 接続管理 UI/controller/routes — REQUEST_CHANGES

- [Critical] Laravel の validation error は通常入力を session に flash します。client secret を通常の FormRequest 入力として扱うと、秘密を session/old input に保存し得ます。

  修正案: secret を `dontFlash` 対象にし、validation response、監査ログ、例外、request logging に含めないことを Feature テストで確認してください。masked sentinel を update 値として受け付けない規則も必要です。

- [Critical] `{connection}` の scoped binding が利用する Organization relation 名が未定義です。

  修正案: route parameter と一致する `connections()` relation、または `resolveChildRouteBinding` の明示実装を設計に含め、認可より前の 404 を実挙動で確認します。

- [Warning] DTO が `clientSecretMasked` を持つため、一覧生成時に秘密を復号する実装へ誘導します。

  修正案: `hasCredentials: bool` や固定表示状態へ置き換え、一覧取得では秘密を一度も復号しない設計にしてください。

- [Warning] Inertia に readonly DTO を直接渡した場合のシリアライズ形、enum、Carbon、camelCase の契約がありません。

  修正案: Inertia 用 `toArray()` または既存の Page Props/Resource パターンを使い、PHP 出力と TypeScript Props の一致をテストします。JSON API は新設しない判断で問題ありません。

- [Warning] UI 設計がファイル名だけで、DESIGN.md・Atomic Design の適合を判断できません。

  修正案: 既存 atom/molecule を使うフォーム構成、DS token の使用、Lucide icon、必須条件不足時もボタンを disabled にせず押下後にエラー表示することを設計へ追記してください。

---

### E1: メール昇格 — REQUEST_CHANGES

- [Critical] `EmailPromotion` の token 保存方式、TTL、一回使用、認証済み user との結合、原子的 consume が設計されていません。

  修正案: token 原文を保存せず fingerprint のみ、user ID、expires/consumed 状態を持たせ、確認時に認証済み user と一致させて一回だけ consume してください。再送・旧 token 失効・並行確認も定義します。

- [Critical] JIT 利用者の `MustVerifyEmail` と昇格後の `email_verified_at` の関係が未解決です。

  修正案: C1 と一体で、昇格前後の `hasVerifiedEmail()`、dashboard/settings 到達性、password reset 可否を明示してください。

- [Warning] users の一意制約違反も、対象 constraint だけを衝突として処理する必要があります。

  修正案: email blind index の unique constraint だけを一様応答へ変換し、DB 障害や他制約違反は握り潰さないでください。

---

### F1: gate 5 本 — REQUEST_CHANGES

- [Critical] 「変数経由の間接呼び出しは保証外」としながら、G2 は「配下に素の HTTP 呼び出しがない」と広く主張しています。AGENTS.md の規約上、保護対象操作を保証外構文で書けるなら、説明追加だけでは適合しません。

  修正案: 間接呼び出しを未解決として gate を失敗させるか、gate の保証主張を実際の検出範囲まで狭め、その外側を別 gate で閉じてください。同様に G1/G3/G4/G5 の動的呼び出しも確認します。

- [Warning] G3 の語彙走査だけでは、別名プロパティや配列キーで秘密を受け渡せます。

  修正案: 禁止語だけでなく、対象 DTO/exception/FormRequest の構造・constructor property・公開 serialization を型単位で検査してください。実挙動の漏洩テストを主証明にします。

---

### F2: 目録登録 — REQUEST_CHANGES

- [Warning] route 数の説明が不整合です。追加 10 route のうち named limiter は redirect、callback、管理更新 6 本の計 8 本です。`enterprise-sso.login` と管理 index の2本は limiter を持ちません。

  修正案: throttle 母集団、exemption、対象外を route ごとに10本すべて分類してください。

- [Warning] 開始 route は GET ですが、DB に login attempt を作る変更操作です。HTTP verb ベースの authorization gate には入らないため、既存検査だけでは副作用付き GET を見落とします。

  修正案: OAuth initiation GET として理由付きで明示分類し、CSRF 代替となる state/browser binding、throttle、cache/no-store を Feature/Architecture テストで固定してください。

---

### F3: D37 — REQUEST_CHANGES

- [Warning] 対象パスが機構全体を覆っていません。少なくとも login attempt Factory、`ConsumedLoginAttempt`、`routes/console.php`、対応テストも上積みの構成要素です。

  修正案: 台帳の「対象パス」が実装差分全体を意味する規約なら列挙を完全にしてください。マージ直前に D 番号と3点の件数 pin を現物から再計算する方針は妥当です。

---

### F4: 偽 IdP — REQUEST_CHANGES

- [Critical] fake route を「試験環境限定で登録する」だけでは route cache や環境判定の変更時に本番へ混入するリスクが残ります。

  修正案: production 相当環境で fake route が route collection に存在しないことを Architecture/Feature テストで固定してください。flag 無効時にも route・binding とも存在しないことを検査します。

- [Warning] fake service への全面差し替えだけでは、実装の PinnedHttpClient 経路を往復テストできません。

  修正案: fake round-trip とは別に、`Http::fake` または ssrf-pin のテスト seam を用いて、実 discovery/token サービスが pinned 経路を通ることを検査してください。

## 全体判定

**CHANGES_REQUESTED**

正典追従、always-JIT、メール非引当、2FA 非介在、browser binding、秘密を DTO に出さない方針は妥当です。一方、少なくとも次の6点は実装開始前に詳細設計へ反映する必要があります。

1. 通常の `HttpFactory` ではなく PinnedHttpClient へ一本化する  
2. OIDC `subject` をバイト列として case-sensitive にする  
3. 期限切れ attempt 削除のトランザクション rollback バグを直す  
4. JIT 利用者の User 列・verified middleware・Laratrust role を確定する  
5. 接続認証材料の更新時に必ず Draft へ戻す  
6. client secret の validation flash、復号、ログ、例外への露出経路を閉じる