# MCP / CLI OAuth 2.1 認可基盤

**実装**: `app/Providers/McpPassportServiceProvider.php`, `app/Passport/`, `app/Http/Middleware/{VerifyMcpOrigin,EnforceMcpTransport,McpConsentOrganizationBinder}.php`, `config/mcp.php`, `config/passport.php`, `routes/ai.php`

> テンプレート共通のセキュリティ機構。本書は**機構全体の契約 (不変条件)** を記述し、実装詳細は各クラスの
> docblock を正とする。REST API v1 / MCP tool の公開面そのものは [docs/architecture.md](architecture.md) の
> 「公開面」表と [docs/app-integration-guide.md](app-integration-guide.md) §5 を参照。

## 概要

MCP クライアント (Claude 等) と first-party CLI (`packages/cli`) が REST API v1 / MCP エンドポイントに
アクセスするための OAuth 2.1 認可サーバ。Laravel Passport をベースに、**自前 grant / repository へ差し替えて**
セキュリティ上の不変条件を上乗せしている。差し替えの要点は以下。

- **Passport の auto-discovery を無効化**し、`McpPassportServiceProvider` を唯一の Passport 登録点にする
  (`laravel/passport` は composer.json の `dont-discover` で除外、`bootstrap/providers.php` が本 Provider を登録)。
- **PKCE `S256` を強制**する。MCP クライアントは `/oauth/register` の DCR (RFC 7591) で誰でも登録できる
  public client 扱いのため、`code_challenge_method=plain` (認可コード横取りに使われ得る / RFC 7636 §7.2) を
  authorize 段階で拒否する。
- **consent での組織選択を最終防御まで検証**する。非 member 組織を body に改ざんして送っても 403 で弾く。
- **token chain に `organization_id` / `session_id` を継承**する。authorization_code → access_token、
  refresh → 新 access_token の各交換で前世代の組織 / セッションを引き継ぐ。
- **CLI セッションは refresh 時に失効 / membership を再検証**し、失効済み / 非メンバーは `invalid_grant` で拒否する。

## アーキテクチャ

### エンドポイントと middleware 契約 (`routes/ai.php`)

- Passport の `/oauth/{authorize,token,...}` は `McpPassportServiceProvider` が登録する。
- `Mcp::oauthRoutes()` が `.well-known/oauth-*` (RFC 8414 / RFC 9728 の discovery metadata) と
  DCR `/oauth/register` (RFC 7591) を登録する。`/oauth/register` には `throttle:oauth-register` を後付け配線し、
  route 構造が見つからなければ起動時 fail-fast する (`laravel/mcp` の update 検出)。
- MCP エンドポイント `POST /api/v1/mcp` の middleware 順序契約:
  `mcp.origin` (Origin allowlist) → `mcp.transport` (POST+JSON 強制) → `throttle:api-mcp` → `auth:mcp-oauth` (Passport Bearer)。
- `.well-known/oauth-*` は `SecurityHeaders` が最小 subset (nosniff + no-referrer) のみを当てる
  (JSON discovery に CSP / X-Frame-Options を付けない。[docs/auth-security-mechanisms.md](auth-security-mechanisms.md) 参照)。

### 認可時の不変条件

- **PKCE S256 強制** (`McpAuthorizationCodeGrant::validateAuthorizationRequest`): `code_challenge` 欠落は
  `invalid_request`、`code_challenge_method` が `S256` 以外も `invalid_request` で拒否。
- **consent 組織バインド** (`McpConsentOrganizationBinder`): `/oauth/authorize` の approve POST の
  `organization_id` を検証する。`filter_var(FILTER_VALIDATE_INT, min_range=1)` で型を厳格化し (`1.5` / `1e5` 等を reject)、
  未知組織は 422、**ログインユーザーが member でない組織は 403**。検証済み id を request attribute
  `mcp_selected_organization_id` に詰め、`McpAuthCodeRepository` がそれを読む。`organization_id` が無いリクエスト
  (非 MCP フロー) では attribute を set しない (no-op)。この middleware は priority list で `Authenticate` の後ろに
  置き、session + auth 解決後 (= `$request->user()` が確定した後) に走る。
- consent 画面 (`resources/views/mcp/authorize.blade.php`) の組織候補はログインユーザー所属組織のみを列挙するが、
  UI ガードは迂回され得るため上記 binder が最終防御。

### token chain への組織 / セッション継承

`organization_id` / `session_id` を「auth code / 前世代 access token → 新 access token」に引き継ぐ経路:

1. **auth code 発行** (`McpAuthCodeRepository::persistNewAuthCode`): consent の `organization_id` を
   同一トランザクションで `oauth_auth_codes.organization_id` に書く。`client_kind='cli'` の場合はさらに
   同トランザクションで `oauth_sessions` を 1 行作成し `oauth_auth_codes.session_id` を書く
   (「session は作られたが code に id が無い / その逆」を構造排除)。user/org が引けない不整合は
   `invalid_grant` で交換自体を失敗させる (fail-loudly)。MCP クライアントは `session_id` NULL のまま。
2. **token 交換** (`McpAuthorizationCodeGrant::respondToAccessTokenRequest`): body の code を decrypt し、
   `oauth_auth_codes` から `organization_id` / `session_id` を読んで request attribute
   (`mcp_inherited_organization_id` / `oauth_inherited_session_id`) に set する。
3. **access token 発行** (`McpAccessTokenRepository::persistNewAccessToken`): attribute を読んで
   `oauth_access_tokens.organization_id` / `session_id` に書く。非 MCP 経路では attribute 未 set のため no-op。
4. **refresh** (`McpRefreshTokenGrant::respondToAccessTokenRequest`): 前世代 access token から
   `organization_id` / `session_id` を読んで attribute に set し、新 access token に継承する。

CLI セッション (`session_id` 継承あり) の refresh では、継承前に `assertSessionRefreshable()` が
**セッション失効チェック + membership 再検証**を行い、失効済み / 非メンバーなら `invalid_grant` で拒否する。
CLI 経路の access / refresh TTL は `config('template.cli_oauth.*')` を per-request に適用し、`try/finally` で
grant の mutable プロパティを呼び出し前の値へ復元する (Octane で前リクエストの TTL が残留しないことを保証)。

### token rotation / TTL

- refresh token rotation は OAuth 2.1 契約として明示 (`Passport::$revokeRefreshTokenAfterUse = true`)。
  Passport の property が消えた場合は fatal ではなく `RuntimeException` で upgrade task を示唆する。
- 既定 TTL は access 30 日 / refresh 90 日 (`Passport::tokensExpireIn` / `refreshTokensExpireIn`)。CLI は上記 per-client TTL。
- scope は `mcp:use` (MCP) + CLI user token 用 `CliOAuthScope` 群 (`cli:use` / `read` / `write` / `session.revoke`)。
  tool / ability 粒度の認可は runtime 再評価で行う (`Mcp/Auth/McpAuthorizationContext`)。

### 組織の役割変更に同期した失効

組織の中で誰かの役割が変わったら、**その変更と同じひとまとまり (トランザクション) の中で**
その人のその組織における資格情報を失効させる (窓口は `app/Services/OAuth/OrganizationAccessRevoker.php`)。

- **境界は「役割を変える操作が成功したこと」**。役割の集合の差分は取らない。差分で判断すると
  権限ライブラリの役割キャッシュ (本番で 1 時間有効) に依存し、取りこぼしたときに通してしまう側へ倒れる。
  帰結として **昇格でも接続はやり直しになる**。これは既知の仕様である
  (監査の理由に `ownership_transferred_to` があるのはこの説明のため)。
- **失効するもの (3 家族。途中で打ち切らない)**: `oauth_sessions` /
  `oauth_access_tokens` と紐づく `oauth_refresh_tokens` / 未交換の `oauth_auth_codes`。
  認可コードを落とすと「失効の直前に発行された code を失効の後に交換して新しいトークンを得る」
  経路が残るため、3 家族目まで必ず撃つ。
- **失効しないもの**: 組織の API キー (`api_keys`) と、プロジェクト単位の役割。
- 監査は握り潰さない (`SecurityEventRecorder::recordOrFail`)。書けなければ役割の変更ごと巻き戻る。
  **失効 0 件でも 1 行残す** (「対象が無かった」ことも監査上の事実である)。

**保証しないもの**

- 失効の選択と確定の間に新しい資格情報が発行される隙間は閉じていない
  (発行の経路は組織行・利用者行のロックを取らない)。最後の拒否線は要求ごとの再評価
  (`ResolveApiActor` / `McpAuthorizationContext`) である。
- **API キーの残余リスク**: **発行した人が組織から外れても、その鍵の読み取り権限は残る**。
  書き込みは認可 (`ProjectPolicy` が発行者の現在の組織ロールを評価する) で 403 になる。
  この非対称を「防御がある」と丸めないこと。鍵を止める手段は組織管理者による失効操作
  (API キー画面) である。所属の再評価を足すと発行者の退職で組織の自動連携が無言で止まるため、
  **別の判断として独立に起こす**。
- 静的検査 (`OrganizationAccessRevocationChokePointTest`) が固定するのは
  「呼び出しの字句が在ること」と「その位置」までで、すべての制御経路で失効が走ることは
  保証しない。実挙動は `tests/Feature/Organizations/OrganizationAccessRevocationTest.php` が担う。

### transport / Origin ガード

- `EnforceMcpTransport` (`mcp.transport`): MCP Streamable HTTP の前提 (POST のみ / `Accept: application/json` /
  `Content-Type: application/json`) を強制。違反は 400。
- `VerifyMcpOrigin` (`mcp.origin`): Origin allowlist 検証 (DNS rebinding / CSRF 対策)。allowlist は
  `config('mcp.allowed_origins')`。**fail-closed**: allowlist が空なら全拒否、production の bare `*` は拒否
  (非 production のみ `*` 可)。Origin 欠落時は `config('mcp.strict_transport')` に従う (true=403 / false=非ブラウザ
  クライアントとして通す)。

## 関連ファイル

| ファイル | 役割 |
|---------|------|
| `app/Providers/McpPassportServiceProvider.php` | Passport の唯一の登録点。自前 grant / repository へ差替、scope / consent view / TTL / rotation 設定 |
| `app/Passport/Grants/McpAuthorizationCodeGrant.php` | authorization_code grant 拡張。PKCE S256 強制 + org/session 継承の起点 |
| `app/Passport/Grants/McpRefreshTokenGrant.php` | refresh_token grant 拡張。org/session 継承 + CLI セッション失効 / membership 再検証 |
| `app/Passport/McpAuthCodeRepository.php` | auth code 発行時に consent 組織を書き込み、CLI は `oauth_sessions` を同トランザクションで作成 |
| `app/Passport/McpAccessTokenRepository.php` | access token 発行時に継承された org/session を書き込み |
| `app/Services/OAuth/OrganizationAccessRevoker.php` | 組織アクセス失効の唯一の窓口 (役割変更と同一トランザクション) |
| `app/Services/Organization/OrganizationMembershipService.php` | 役割変更 4 経路。失効を同一トランザクション内で呼ぶ |
| `app/Http/Middleware/McpConsentOrganizationBinder.php` | consent の `organization_id` 検証 (非 member を 403) と attribute バインド |
| `app/Http/Middleware/VerifyMcpOrigin.php` | `mcp.origin`。Origin allowlist 検証 (fail-closed) |
| `app/Http/Middleware/EnforceMcpTransport.php` | `mcp.transport`。POST + JSON 強制 |
| `routes/ai.php` | Passport / discovery / DCR / MCP エンドポイントの配線と middleware 順序契約 |
| `config/mcp.php` | redirect domains / custom schemes / Origin allowlist / strict_transport |
| `config/passport.php` | Passport 本体の設定 |
| `resources/views/mcp/authorize.blade.php` | 組織選択付き consent 画面 |
| `bootstrap/app.php` | `mcp.origin` / `mcp.transport` alias と `McpConsentOrganizationBinder` の priority list 配線 |

導入導線 (CLI / MCP のオンボーディング画面・スニペット生成) は `app/Services/Onboarding/SnippetBuilder.php` +
`app/Http/Controllers/Organizations/OrganizationOnboardingController.php` + `resources/js/pages/Organizations/Onboarding/`
にある。first-party CLI 自体のセットアップは `packages/cli/README.md` を参照。
