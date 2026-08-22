# 概念設計: security-audit-remediation

対象監査レポート: `devnotes/20260805-1600-audit-cycle-2/security.md`
(High 2 件 / Medium 2 件。Critical 0 件)

> Round 1 の Codex レビューを受けて全面改訂 (v2)。
> 改訂点: `verified` / 2FA 強制 gate の同型残存を「exemption 凍結」から
> **middleware priority list による構造的解決**へ変更 (§S2)。
> 判定基準を `SubstituteBindings` の位置に相対化し、分類の粗さ問題を解消 (§S4)。
>
> Round 2 の Codex レビューを受けて改訂 (v3)。
> 改訂点: `ResolveApiActor` を `SubstituteBindings` の**前**へ移し、**例外を 0 件にした** (§S2)。
> S4 の分類対象を「解決済み middleware 列に現れた全クラス」へ拡大し、
> 検査対象 route の母集団も deny-by-default にした (§S4)。
> S5 に運用 runbook を追加 (§S5)。S7 を構造化 map ベースへ変更 (§S7)。
>
> Round 3 の Codex レビューを受けて改訂 (v4)。
> 改訂点: `ResolveApiActor` 前倒し後の**順序契約を設計全体で統一** (§S1/§S2/§S4/§API 影響)。
> API のエラー象限表を actor 状態別に全展開し「唯一の変化」表現を撤回 (§API 影響)。
> exemption 機構を**作らない** (新規例外は無条件 fail) に変更 (§S4)。
> route parameter の分類を**parameter 単位**に細分化 (§S4)。

---

## 背景・課題

直近サイクル (T099〜T106) 後の多角監査で、**今サイクルの主目的そのものに残った穴**が 2 件見つかった。
いずれも既存テストの検査範囲外であり、`composer test` が 2865 passed でも安全性の証明にならない。

### 課題 1 — 存在オラクルが ability チェックの手前で残っている (High-1)

T103 は `EnsureProjectBelongsToApiOrganization` (`api.project-in-org`) を FormRequest より前に置いたが、
**実行時 middleware 順序** (監査が実測) は

```
Authenticate → Throttle → SubstituteBindings → ResolveApiActor
  → RequireApiKeyAbility(403) → EnsureProjectBelongsToApiOrganization(404) → IdempotentRequest
```

であり、**ability の 403 がテナント境界の 404 より前**にある。

ここで効くのが `SubstituteBindings` の位置である。**不在 id は `SubstituteBindings` で 404 になる**ため、
それより後で 404 以外を返す短絡はすべて「実在 / 不在」の 1 bit を漏らす。

| read-only API キーで `POST /api/v1/projects/{id}/items` | 応答 |
|---|---|
| 他組織に実在する project | **403** `insufficient_ability` |
| どこにも存在しない id | **404** `not_found` |

しかも `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php:125-128` が
`api-key.ability:* < api.project-in-org` を**意図的に機械固定**しているため、
順序を直すと Architecture テストが落ちる = 自然治癒しない。

**横断確認の結果、同じ構造の穴が web 側にも複数見つかった** (本設計で自ら実査):

| # | 対象 | 先に走る短絡 | 漏れるもの |
|---|---|---|---|
| W-1 | `projects.*` / `capture.*` の 30 route (`routes/web.php:400`, `:528`) | `require-active-subscription` (402 XHR / 302) | project id の実在 (未契約組織のユーザー) |
| W-2 | `organizations.members.two-factor.reset` | `recent-auth` (302 / 409) | **グローバルな `users.id` の実在** (step-up が stale なユーザー) |
| W-3 | 上記 30 route + `{project}` を持つ全 web route | `verified` (`EnsureEmailIsVerified` の 302) | project id の実在 (メール未確認ユーザー) |
| W-4 | 同上 | `RequireTwoFactorForEnforcedOrganizations` (302 / 409) | project id の実在 (2FA 強制組織の未準拠ユーザー) |
| W-5 | 同上 | `HandleInertiaRequests` (Inertia version mismatch の 409) | project id の実在 (stale version ヘッダを送るだけ) |

W-2 だけは性質が違い、`{user}` が**グローバル implicit binding**
(`RouteBindingTypes` の `'user' => User::class`) で解決され、org 所属検査が controller の
inline guard (`OrganizationMemberController::resolveOrganizationMember`) にしかないことが原因。

**一般化**: `SubstituteBindings` より後に走り 404 以外で短絡するあらゆる middleware が同じ穴になる。
逆に `SubstituteBindings` **より前**に走る短絡 (`Authenticate` の 302 / `ThrottleRequests` の 429 /
CSRF 419 / `AuthenticateSession` の logout) は、不在 id もまだ 404 になっていないため
オラクルにならない。この境界が本設計の判定基準になる。

ただしこれは**構造的保証ではなく、現行実装の性質**である。前段 middleware が生の route parameter を
読んで DB 照会すれば binding 前でも存在依存の応答を作れる。
したがって「**現在登録されている前段の短絡 middleware は route resource の存在に依存しない**」という
条件付き命題として扱い、その性質自体を inventory に記録して機械検証する (§S4-6)。

### 課題 2 — `trustProxies(at: '*')` により client IP が攻撃者制御 (High-2)

`bootstrap/app.php:53` の `at: '*'` は全アドレスを trusted proxy 扱いにするため、
Symfony の `getClientIps()` は `X-Forwarded-For` の**最左** = クライアントが自由に書ける値を返す
(監査で実測確認済み: `XFF: 9.9.9.9` → `ip() = '9.9.9.9'`)。

結果、`$request->ip()` をキーにする**全ての防御が無効**になる:

| 用途 | 定義箇所 |
|---|---|
| `login` 5/min | `FortifyServiceProvider.php:172` |
| `two-factor` 5/min (fallback) | `:181` |
| `passkeys` 10/min (未認証は IP 単位) | `:192` — **T106 の新設分** |
| `inquiry` (IP / IP+email) | `AppServiceProvider.php:270` |
| `oauth-register` 10/min | `:293` |
| `api-*` 未認証 fallback | `:310`, `:324` |
| reCAPTCHA へ渡す IP | `StoreInquiryRequest.php:68` |
| 監査ログ `security_audit_events.ip_address` | `SecurityEventRecorder.php:26` |

`X-Forwarded-For` を毎リクエスト変えるだけで総当り・資源枯渇が無制限に可能で、
かつ**監査ログの IP も信用できない**(事後追跡が成立しない)。

**本番プロキシ構成は本リポジトリからは確認できなかった** — 実査結果は §制約・前提に明記する。

### 課題 3 — passkey の監査ログ欠落 (Medium-1) / `passkey.destroy` の throttle 欠落 (Medium-2)

- パスワード変更・2FA・SSO 連携・API キー発行/失効は `SecurityEventRecorder` に残るのに、
  **単独でログインできる強い資格である passkey の増減だけが残らない**。
  セッション乗っ取り後に攻撃者が passkey を登録して永続化しても、
  パスワード変更 (`logoutOtherDevices`) では追い出せず痕跡も残らない。
- `passkey.destroy` だけ throttle が無い (vendor の `$passkeyMiddleware` が `$throttle` を含まない。
  `vendor/laravel/fortify/routes/routes.php:217-219` で確認)。
  `EnsureLoginMethodRemains` は毎リクエスト `DB::transaction` + User 行 `lockForUpdate()` を取るため、
  認証済みユーザーが自分の User 行に無制限のロック競合を起こせる。

---

## 改善アイデア

> **設計の芯**: 個別の穴を潰すだけでは同型の穴が再発する。
> 「**テナント境界の 404 は、`SubstituteBindings` の直後に走る**」を
> Laravel の middleware priority list で**構造的に確定**させ、
> 「`SubstituteBindings` と guard の間に短絡 middleware を置かない」を
> Architecture テストで機械強制する。
> 個別 route の宣言順に依存する現行方式 (= 新しい route / 新しい middleware ごとに人間が気づく必要がある)
> から、**配線 1 箇所で全 route に効く方式**へ移す。

### S1 [High-1] API v1: `api.project-in-org` を `api-key.ability:*` より前へ

新しい順序契約 (実行順。S2 の priority list 変更を織り込んだ**最終形**):

```
Authenticate → Throttle → resolve.api-actor → SubstituteBindings
  → api.project-in-org → api-key.ability:* → idempotent → controller
```

- `resolve.api-actor` は **`SubstituteBindings` より前** (S2。actor 解決の 401/403 が
  「不在 404 がまだ起きていない時点」で返るようにする)
- `api.project-in-org` は `resolve.api-actor` より後 (`organization` attribute が前提。不可侵)
- `api-key.ability` より前: **ability 403 で cross-org の実在を漏らさない** (今回の反転)
- `idempotent` より前: cross-org リクエストで idempotency 行を作らせない (不可侵)

この順序は設計全体 (S1 / S2 / S4 のテスト計画 / API 影響評価) で**同一の 1 本**を正本とする。

**なぜ現在この順序なのかの実査**: T103 の詳細設計
(`devnotes/20260805-1244-controller-authorization-gate/detailed-design.md:772-780`) では
順序契約の表に載っていたのは `resolve.api-actor` と `idempotent` の 2 件だけで、
`api-key.ability` は順序を書き下した 1 行に紛れて入っただけだった。
テスト側の根拠コメント「エラー契約 insufficient_ability が route ごとにぶれる」も**後付けの説明**であり、
ability を先に置く積極的な理由は存在しない。
むしろ `docs/app-integration-guide.md` §7 不変条件 8 は
`api-key.ability` middleware を**認可 (層 3) として数えない**と明記しており、
「層 2 (404) → 層 3 (403)」の原則から見れば ability は層 2 の後に来るべきである。

`routes/api.php` の宣言順も同時に直す (思考原則 3: 誤解を招く並走を残さない)。
ただし**正本は S2 の priority list**であり、テストは解決後の実行順を検査する。

### S2 [構造] テナント guard を `SubstituteBindings` の直後に pin する

`bootstrap/app.php` の middleware priority list に 3 本を追加する
(本リポジトリは既に `McpConsentOrganizationBinder` で同 API を使用済み = 既存作法):

```php
// actor 解決は binding より前へ (401/403 を「不在 404 がまだ起きていない時点」で返す)
$middleware->prependToPriorityList(SubstituteBindings::class, ResolveApiActor::class);
// テナント guard は binding の直後へ
$middleware->appendToPriorityList(SubstituteBindings::class, EnsureProjectBelongsToApiOrganization::class);
$middleware->appendToPriorityList(EnsureProjectBelongsToApiOrganization::class, EnsureProjectBelongsToRouteOrganization::class);
```

**`ResolveApiActor` を `SubstituteBindings` の前に置けること**は実装で確認済み:
同 middleware は `$request->attributes->get('api_key')` と `$request->user('api-oauth')` しか読まず、
**route binding に一切依存しない** (`$request->route(...)` の参照が 0 箇所)。
`Authenticate` (priority 5) / `ThrottleRequests` (priority 6-7) より後という既存の前提も維持される
(挿入位置が `SubstituteBindings` = priority 9 の直前のため)。
これにより「actor が壊れている場合の 401/403 が実在を漏らす」経路も同時に閉じ、
**既知の例外が 0 件**になる。

なお `appendToPriorityList()` / `prependToPriorityList()` は **middleware を注入しない**。
その route に実在する middleware の相対順序を整えるだけであり、
`{project}` を持たない route や guard を持たない route には何の影響も無い。
(API guard と web guard は同一 route に共存しないため相対順序に意味は無いが、
priority list を決定的に保つため鎖状に並べる。)

**この 1 箇所で W-1〜W-5 がすべて閉じる**:

| 短絡 middleware | 変更前 | 変更後 |
|---|---|---|
| `RequireActiveSubscription` (402/302) | guard より前 | guard より後 |
| `EnsureEmailIsVerified` (302) | guard より前 | guard より後 |
| `RequireTwoFactorForEnforcedOrganizations` (302/409) | guard より前 | guard より後 |
| `HandleInertiaRequests` (409) | guard より前 | guard より後 |
| `RequireApiKeyAbility` (403) | guard より前 | guard より後 |
| `ResolveApiActor` (401/403) | binding より後・guard より前 | **binding より前** (オラクル成立しない位置) |
| `IdempotentRequest` (409) | guard より後 | 変更なし |

**副作用の評価 (重要)**: guard が 404 で短絡すると、guard より内側の middleware は走らない。
すなわち cross-org の 404 応答には `HandleInertiaRequests` / `SecurityHeaders` /
`NoStoreCacheHeadersForAuthenticatedPages` / `EncryptHistory` が乗らなくなる。
これは**既存契約と完全に一致する**:
`tests/Feature/Security/SecurityHeadersTest.php:163-171` が
「binding 失敗 404 には `Permissions-Policy` が一切付かない (SecurityHeaders は
`SubstituteBindings` より内側のため到達せず、ヘッダは付かない = fail-safe)」を**既に固定している**。
cross-org 404 が不在 404 と同じ扱いになるのは劣化ではなく、
**応答ヘッダまで含めて不在と cross-org が完全同一になる**という副次的な改善である
(監査 §1.1 が確認した「body / ヘッダ完全同一」を web 側にも拡張する)。

**この配線で既知の例外は 0 件になる**。したがって S4 では
**exemption (例外登録) 機構そのものを作らない** — 違反は無条件 fail とする
(Round 3 指摘。「`risk` 等を付ければ登録できる」機構は非交渉の不変条件を緩める入口になり、
かつ登録件数 0 のまま機構だけ作るのは AGENTS.md 思考原則 2「今必要なものだけ作る」に反する)。
将来やむを得ない例外が生じたときは、その時点で**設計判断としてテストを変更**する
(黙って inventory に 1 行足す運用にはしない)。

### S3 [W-2] 組織メンバー route の `{user}` を `Route::scopeBindings()` 化

`organizations.members.update` / `.destroy` / `.two-factor.reset` の 3 本を
`Route::scopeBindings()` group に入れ、`{user}` を `Organization::users()` relation 経由で解決させる。
不整合は **binding 段で 404** になり、`recent-auth` を含む
**binding より後に走るすべての短絡 middleware より前**に閉じる
(S2 の priority pin でも閉じない領域を、「そもそも binding で 404 にする」
テンプレート主防御で閉じる)。
なお最終順序では `ResolveApiActor` が `SubstituteBindings` より前に走るため、
「全 app middleware より前」ではない点に注意する (該当 route は web 専用のため
`ResolveApiActor` は載らない)。
controller の inline guard (`resolveOrganizationMember`) は二重防御として残す。

`NestedRouteIdorDefenseTest` の inventory も 3 本とも `UrlIntegrityGuard` → `ScopeBindings` に更新する。

### S4 [構造強制] 順序不変条件の Architecture テスト新設

**これが「同じ穴が再発したら落ちる」本体** (AGENTS.md 禁止事項 1)。
検査は **`Router::gatherRouteMiddleware()` (priority 適用後の実行順)** に対して行う
— `gatherMiddleware()` (宣言順) を見ていたことが、監査が実測するまで穴が見えなかった直接の原因。

1. **検査対象を deny-by-default 化 (parameter 単位)**
   既存の `NestedRouteIdorDefenseTest` は「route parameter が 2 つ以上」の route しか分類対象にしておらず、
   今回の穴の当事者である `api.v1.projects.items.store` や `projects.update` (1 param) は
   **inventory の外**にあった。母集団を「**route parameter を 1 つ以上持つアプリ所有 route**」へ広げる
   (vendor 所有の livewire / filament / passport prefix は現行どおり除外)。
   ただし route 単位で 1 モードに畳むと概念が広がりすぎるため (Round 3 指摘)、
   inventory の値を **parameter 名 → モードの map** に変更する。モードの集合:
   | モード | 意味 |
   |---|---|
   | `ScopeBindings` | `Route::scopeBindings()` が親 relation 経由で解決 (既存) |
   | `UrlIntegrityGuard` | controller の inline 親子整合 guard (既存) |
   | `ScopedBinder` | `Route::bind()` の scoped binder が binding 段で 404 (`{organization}` / `{passkey}` / `{notification}`) |
   | `TenantGuardMiddleware` | テナント guard middleware が担う (`{project}`) |
   | `NonResourceParameter` | そもそもリソース id ではない (`{provider}` / `{intent}` / `{token}` / ページ番号等) |
   | `PublicGlobalResource` | テナントに属さない公開リソース |
   後ろ 2 つは「テナント防御対象ではない」ことを**明示的に宣言**させるための case であり、
   テナント防御モードと混同しないよう enum 上も別グループとして docblock に記す。
2. **解決済み middleware の deny-by-default 分類**
   検査対象 route の **`Router::gatherRouteMiddleware()` に実際に現れた全クラス**を
   `ShortCircuits` (`$next` を呼ばずに 3xx/4xx を返しうる) / `Transparent` に分類する。
   **由来 (app / framework / vendor) を問わず未分類なら fail**。
   今回の穴の 1 つは framework 側の `EnsureEmailIsVerified` だったため、
   namespace 走査では不十分 (Round 2 指摘)。closure middleware は分類不能として fail させる。
3. **境界規則 (本設計の核)**
   `TenantGuardMiddleware` モードの route では、解決後の実行順で
   **`SubstituteBindings` と guard の間に `ShortCircuits` middleware が 1 つも存在しない**こと。
4. **inline guard に頼る route の制約**
   `UrlIntegrityGuard` モードの route には、`SubstituteBindings` より後に
   `ShortCircuits` middleware を置けない
   (inline guard は全 middleware の後に走るため、前に短絡があれば必ずオラクルになる)。
5. **binder に頼る route**
   `ScopedBinder` / `ScopeBindings` モードは binding 段で 404 になるため順序制約を課さない。
   ただし「実際に scoped binder / scopeBindings が効いていること」は
   既存の Feature テスト (cross-tenant が 404) 側の責務として維持する。
6. **前段 (pre-binding) 短絡の性質を固定**
   `SubstituteBindings` より前に走る `ShortCircuits` middleware は
   「route resource の存在に依存しない」ことが前提になる。
   現行の登録対象は `Authenticate` / `ThrottleRequests` / `PreventRequestForgery` /
   `AuthenticateSession` / `ResolveApiActor`。
   **機械保証の範囲を限定して明記する** (Round 4 指摘。「明記するだけ」では保証にならない):
   - (a) 静的検査: 登録クラスのソースに `$request->route(` / `Route::input(` /
     `$request->segment(` など**生 route parameter を読む呼び出しが無い**ことを検査する
     (未登録の前段短絡 middleware が現れたら inventory 未登録で fail)
   - (b) 振る舞い検査: 各登録 middleware が短絡する状態を作り、
     **実在 id と不在 id で応答が完全同一**になることを Feature テストで固定する
   - (c) **限界の明示**: 呼び出し先クラスを経由した間接的な DB 参照までは静的に証明できない。
     (a) は「直接参照が無いこと」までを保証し、(b) が実際の応答同一性を担保する、
     という二段構えであることを docblock に書き残す
7. **例外機構は作らない**
   既知の例外が 0 件になったため、**exemption inventory を用意しない**。
   違反は無条件 fail とする (Round 3 指摘)。
   将来やむを得ない例外が必要になったら、その時点で設計判断としてテストを変更する
   (「理由を書けば通る」抜け道を最初から作らない)。
8. **priority 配線の実 route 検証 (Round 2 / Round 3 指摘)**
   `appendToPriorityList` / `prependToPriorityList` は middleware を注入せず相対順序を整えるだけであり、
   相対挿入 API を重ねた結果は自明ではない。**解決済み middleware 列の完全な順序**を
   実 route に対して固定する。最低限の検証対象:
   - `api.v1.projects.items.store` (write group: guard + ability + idempotent) —
     API キー actor / OAuth actor の**両方**で列全体を検証する
   - `api.v1.projects.items.index` (read group)
   - `api.v1.me` / `api.v1.projects.index` (同一 group で `{project}` を持たない route)
   - `projects.update` (web guard + `verified` + 2FA 強制 + 課金ゲート + Inertia)
   - `capture.manuals.show` (capture group)
   - `organizations.settings` (guard を持たない web route = 影響が無いこと)
9. **no-op の固定**
   「`{project}` を持たない route では guard が素通しする」ことを Feature テストで固定する。

### S5 [High-2] `trustProxies` を env 由来の allowlist へ

- `bootstrap/app.php` から `at: '*'` を撤去し、`headers:` のみ指定する。
  proxy 集合は Laravel `TrustProxies` の**公式 fallback 経路**から供給する (思考原則 1)。
  実装で確認済みの事実:
  - `vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustProxies.php` の
    `setTrustedProxyIpAddresses()` が `$this->proxies() ?: config('trustedproxy.proxies')` を読む
  - `proxies()` は `static::$alwaysTrustProxies ?: $this->proxies` であり、
    **`TrustProxies::at()` を呼ばなければ config へ落ちる**
  - `Middleware::trustProxies(at: null, headers: X)` は `TrustProxies::at()` を呼ばない
  - 文字列 `'REMOTE_ADDR'` は「直接の接続元を信頼」を意味する予約値
    (`setTrustedProxyIpAddressesToSpecificIps()` が変換する)
  - `withMiddleware` の callback は config 読込より前に走るため `at:` に closure は渡せない
    (`trustHosts` と違い `trustProxies(at:)` は `array|string|null` のみ) — これが config 経由を選ぶ理由
- `config/trustedproxy.php` を新設し、2 本立ての値を expose する
  (`trusted_hosts` の `wildcard_suffixes` / `raw_wildcard_suffixes` と同じ作法):
  - `proxies`: **検証を通過した値のみ**の `list<non-empty-string>` (framework が読む正本)
  - `raw_proxies`: 生 token の `list<string>` (空要素・空白のみ要素も保持)。
    config 段で silent drop された不正値を起動時 fail-fast で表面化させるために使う
  「validator 通過前の値を `list<non-empty-string>` と断定しない」= 不正値が型で消えないようにする
  (Round 2 指摘)。
  `none` は「プロキシは無い」という**明示宣言**として空 list に写す
  (「未設定で空」と「意図的に空」を区別するため)。
- `App\Support\TrustedProxiesConfigValidator` (純粋クラス) を新設し、
  `ProductionEnvGuard::violations()` から委譲する
  (`TrustedHostsConfigValidator` と完全に同じ作法。production 起動時 fail-fast + `production:preflight`)。
  production での違反: 未設定 (空かつ `none` 未宣言) / `*` / `**` / `REMOTE_ADDR` /
  IP・CIDR 書式違反 / 空白のみ要素。
- **既定は「信頼しない」**。ローカル (`artisan serve` :8001) / bug-hunt (:8010-8018) / CI /
  in-process browser server はいずれもプロキシ無しの loopback 直結のため、
  空 = `ip()` が REMOTE_ADDR になり**現状と同じかより正確**になる (実査で確認)。
  ホスト側 Valet 越しに TLS で開発する場合の推奨値 (`REMOTE_ADDR`) を `.env.example` に併記する。
- **運用契約を片側で終わらせない** (Round 2 指摘)。`docs/trusted-proxies-runbook.md` を新設し、
  以下を固定する:
  - 本番/staging の**実 proxy hop 一覧**と CIDR の管理主体 (記入は運用者の作業。
    リポジトリからは確認できないため空欄のまま設計を終わらせない)
  - `TRUSTED_PROXIES` の変更手順と、変更時に再確認する項目 (client IP の実測)
  - **`production:preflight` をデプロイ前の必須 gate とする** (「組み込めば検知できる」ではなく必須)
  - fail-fast したときの切り分け手順と rollback 条件
    (rollback は `at: '*'` へ戻すことではない — 正しい CIDR を設定するまでデプロイしない)
  - **未記入の機械検出** (Round 3 指摘): runbook の運用者記入欄には固定の placeholder トークン
    (例: `<!-- OPS-FILL -->`) を置き、Architecture テストが「placeholder が残っていたら fail」させる。
    これにより「運用者記入待ちのまま実装完了扱い」を構造的に防ぐ

### S6 [High-2 付随] `RedirectToHttps` を `TrustProxies` より後ろへ

`bootstrap/app.php:50` の `$middleware->prepend(RedirectToHttps::class)` は
グローバル middleware 配列の**先頭** = `TrustProxies` **より前**に置く
(`Middleware::getGlobalMiddleware()` が `array_merge($prepends, $global, $appends)` を返すため。実査済み)。
そのため `$request->isSecure()` は `X-Forwarded-Proto` を見ておらず、
LB 終端構成で `FORCE_HTTPS_REDIRECT=true` にすると**308 の無限ループ**になる。
`prepend` → `append` に変更し、グローバル middleware の最後 (= `TrustProxies` の後、
route group より前) で走らせる。

現在 `FORCE_HTTPS_REDIRECT` は既定 false かつどの env にも書かれていないため**顕在化していない**が、
High-2 の対処で「本番はプロキシ終端」を正面から扱う以上、同じ PR で閉じる
(思考原則 6: タコツボ実装を避ける)。

### S7 [Medium-1] passkey 増減を SecurityAuditEvent に記録

- `SecurityEventType` に `PasskeyRegistered` / `PasskeyDeleted` を追加 (label 付き)。
- `RecordSecurityEvent` subscriber に vendor の `Laravel\Passkeys\Events\PasskeyRegistered` /
  `PasskeyDeleted` を追加購読する (enum の docblock が指示している購読 map の更新)。
- **記録経路の構造化 map を正本にする** (Round 2 指摘。grep 走査は label 表示や match 式で
  素通りするため保証にならない)。`SecurityEventType` の各 case に対して
  「event 駆動 (購読するイベントクラス)」か「直接記録 (呼び出し元クラス)」かを宣言する map を持ち、
  Architecture テストが (a) enum 全 case と map key の**完全一致**、
  (b) event 駆動 case が実際に `Event::subscribe` / `Event::listen` で登録されていること、
  (c) 直接記録 case の宣言クラスが実在し `SecurityEventRecorder` を参照していること、
  を検査する。加えて**全 case について Feature テストで実際に行が増えることを固定する**
  (Round 3 指摘。map と型だけでは「呼ばれること」を保証できない。
  既存の `ResetAdminMfaCommandTest` / 2FA 系テストで既に担保済みの case は、
  その担保先を map の備考欄に記載して重複実装しない)。
- **メール通知は今回入れない**。2FA 有効/無効も SSO 連携も通知は無く監査ログのみで、
  passkey だけ通知を足すと「認証手段変更の通知ポリシー」が passkey だけ突出した非一貫な状態になる。
  通知は独立した設計テーマとして切り出し、TODO 登録を完了条件に含める (§スコープ外)。

### S8 [Medium-2] `passkey.destroy` に `throttle:passkeys` を後付け

`PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes()` に throttle 後付けを追加し、
`PasskeyRouteProtectionTest` の inventory と**解決後の実行順**
(`ThrottleRequests` < `RequireRecentAuth` < `EnsureLoginMethodRemains`) を固定する。
`ThrottleRequests` は priority list に含まれるため `Authenticate` の後に来る
= 認証済み user 単位で 10/min になる (未認証 IP fallback には落ちない)。

---

## 期待効果

### 使命への貢献

本アプリは**現場の作業手順書 (SOP) という顧客の機密資産**を組織単位で預かる。
「他社の project がこの ID で存在する」が漏れる状態は、SOP を預ける前提そのものを損なう。
存在オラクルの封じ込めと client IP の信頼回復は、**AI-CUE を業務データの預け先として成立させるための土台**であり、
機能追加ではなく**土台の欠損の是正**である。

### 具体的な改善見込み

| 項目 | Before | After |
|---|---|---|
| API v1 の cross-org 実在判定 | ability 不足キーで 403 / 不在で 404 に分岐 | 全て同一 404 |
| web の cross-org 実在判定 (課金 / メール未確認 / 2FA 強制 / Inertia 409) | 302・402・409 と 404 に分岐 | 全て同一 404 (応答ヘッダも同一) |
| `users.id` 実在判定 (メンバー 2FA リセット) | stale step-up で 302/409 と 404 に分岐 | binding 段で同一 404 |
| actor 解決失敗時 (トークン失効 / membership 剥奪) の実在判定 | 実在は 401/403・不在は 404 に分岐 | binding より前に 401/403 = 分岐しない (不在も 401/403) |
| 同型の穴の再発 | 検出手段なし (実測して初めて分かる) | Architecture テストが実行順を測って落とす (**登録済み例外 0 件**) |
| 新 route / 新 middleware の追加時 | 人間が順序に気づく必要 | priority list で自動的に guard が先行 + 未分類 route / 未分類 middleware は fail |
| `$request->ip()` | XFF 最左 = 攻撃者制御 | 信頼 proxy の外側 = 実クライアント |
| `FORCE_HTTPS_REDIRECT` | LB 終端で 308 無限ループ (潜在) | 正常動作 |
| passkey 登録/削除 | 監査証跡ゼロ | `security_audit_events` に記録 + 全 case の記録経路を機械保証 |
| `passkey.destroy` | throttle 無し (User 行ロック無制限) | 10/min (user 単位) |

---

## 実装方針（概要）

| # | 施策 | 主な変更コンポーネント |
|---|---|---|
| S1 | API 順序反転 | `routes/api.php`, `EnsureProjectBelongsToApiOrganization` docblock, `bootstrap/app.php` alias コメント, `ProjectRouteCurrentOrgGuardTest`, `ItemAuthorizationTest` |
| S2 | priority list による guard の pin | `bootstrap/app.php`, `ProjectRouteCurrentOrgGuardTest` (docblock の前提を書き換え) |
| S3 | メンバー route の scopeBindings 化 | `routes/web.php`, `NestedRouteIdorDefenseTest` inventory, Feature テスト |
| S4 | 順序不変条件の機械強制 | `NestedRouteDefenseMode` 拡張 + `NestedRouteIdorDefenseTest` inventory 拡張, 新 enum (`App\Enums\Security\*`) + 新 Architecture テスト, `docs/app-integration-guide.md` §7 |
| S5 | trusted proxies | `bootstrap/app.php`, `config/trustedproxy.php` (新), `TrustedProxiesConfigValidator` (新), `ProductionEnvGuard` (+ その Feature テストの baseline), `.env.example`, `EnvExampleInvariantTest`, `docs/trusted-proxies-runbook.md` (新), `docs/auth-security-mechanisms.md` |
| S6 | RedirectToHttps の位置 | `bootstrap/app.php`, Architecture / Feature テスト |
| S7 | passkey 監査ログ | `SecurityEventType`, `RecordSecurityEvent`, Architecture テスト (新), Feature テスト |
| S8 | passkey.destroy throttle | `PasskeyServiceProvider`, `PasskeyRouteProtectionTest` |

各施策は「テストを先に落としてから直す」(思考原則 5)。
特に S1/S2 は `ProjectRouteCurrentOrgGuardTest` の既存アサーションを**反転**する必要があるため、
反転前に Feature テスト (read-only キー × cross-org / 未契約ユーザー × cross-org) が
red になることを確認してから着手する。

---

## 制約・前提

### 本番プロキシ構成は確認できなかった (実査結果)

リポジトリ全体を実査した結果、**本番/staging のインフラ定義もドキュメントも存在しない**:

- `deploy/` ディレクトリは存在しない。terraform / k8s / Procfile / fly.toml / vapor.yml も無い
- nginx / Caddy / Traefik の設定ファイルはリポジトリ内に 1 つも無い
- `docker-compose.yml` は devcontainer 専用 (app / db / mailpit の 3 サービス、proxy 無し)。
  ローカルのみホスト側 Valet (nginx) が `aicue.test → 127.0.0.1:8002` を固定プロキシする
- CI (`.github/workflows/ci.yml`) に deploy job は無い
- `docs/billing-gate-inversion-runbook.md:29-32` が
  「**本リポジトリにはデプロイ自動化が存在しない**」と明記している
- ALB / CloudFront への言及は `config/security.php:121-123` などの
  「LB 終端構成では false にする」という**条件付きコメントのみ**で、構成の宣言ではない
- `TRUSTED_PROXIES` という env 変数はリポジトリ内に 1 度も現れない (新規追加になる)

したがって**具体的な CIDR を設計で決めることはできない**。
本設計は「安全側の既定 (信頼しない) + 明示設定を production で強制 (fail-fast) + 設定手順の文書化」で構成する。
運用者が構成を宣言するまで production が起動しないのは意図的な設計であり、
`config/trusted_hosts.php` の既存作法と完全に同型である。

**設定手順に必ず含める注意点** (Symfony の client IP 導出仕様に由来):

- XFF の連鎖 (`[XFF 左…右, REMOTE_ADDR]`) から trusted なものを除去した**最も右の untrusted 値**が
  client IP になる。したがって **hop を 1 つでも取りこぼすと client IP がその hop の IP に固定**され、
  全利用者が 1 つの rate limit バケットに落ちる (= 自己 DoS)。
  CloudFront → ALB のような多段構成では**両方の range** を列挙する必要がある。
- `TRUSTED_PROXIES` を設定せずに本設計をデプロイすると **production 起動が fail-fast する**。
  デプロイ手順では「env 設定 → デプロイ」の順序を守ること
  (`production:preflight` を deploy pipeline に組み込めば事前検知できる)。

### 既存の「問題なし」判定を壊さない

監査が自己申告を検証して問題なしと確認した以下は、本設計で**壊さないことを明示的に確認する**:

| 項目 | 本設計での扱い |
|---|---|
| 404 body / ヘッダの完全同一性 | `ApiExceptionRenderer` に触れない。S1/S2 は「404 以外が出る条件」を減らすだけ |
| passkey 削除 4 ケースの同一 404 | `SelfScopedPasskeyBinder` に触れない。S8 は throttle 追加のみ (429 は binding より前で id 非依存) |
| `PasskeyLoginPolicy` の fail-closed | 触れない |
| `password_confirmed_at` 非汚染 | 触れない |
| TOCTOU (同時削除) | `EnsureLoginMethodRemains` のロジックに触れない。S8 は前段に throttle を足すだけ |
| CSRF / SQLi / XSS | 新規の raw SQL / `{@html}` / CSRF 除外を追加しない |

### その他の前提

- 既存 API クライアント (`packages/cli`) は自組織の project しか扱わないため S1 の影響を受けない。
- テストは T099 のグローバルロック経由 (`composer test`) で走る。
- PHPStan level 10 / Pint / RefreshDatabase グローバル適用は既存どおり。

---

## API クライアントへの影響 (S1 / S2 の順序変更)

変更は **2 系統**に分かれる:
**(i) actor 解決に成功したリクエストにおけるテナント境界の応答** (cross-org が常に 404 になる) と、
**(ii) actor 解決に失敗したリクエストにおけるエラー優先順位** (不在 id が 404 ではなく 401/403 になる)。
web (S2) は (i) のみ、API (S1 + S2) は (i) と (ii) の両方が該当する。

### API (S1 + S2) — actor 状態別の全象限

S2 で `ResolveApiActor` が `SubstituteBindings` **より前**に来るため、変化は 1 象限では収まらない
(Round 3 指摘。「唯一の変化」という v3 までの記述は撤回する)。
actor 状態 × 対象 id の全組み合わせは以下:

**(a) actor 解決に成功する (トークン有効・セッション有効・membership 有効)**

| ability | {project} | Before | After |
|---|---|---|---|
| 有 | 自組織 | 通常処理 | 変更なし |
| 有 | 他組織実在 / 不在 | 404 `not_found` | 変更なし |
| **不足** | **自組織** | 403 `insufficient_ability` | **変更なし** (エラー契約は保たれる) |
| **不足** | **他組織実在** | 403 `insufficient_ability` | **404 `not_found`** ← S1 による変化 |
| 不足 | 不在 | 404 `not_found` | 変更なし |

**(b) actor 解決に失敗する (API キー発行者削除 = 403 `actor_not_resolvable` /
OAuth トークン失効・CLI セッション失効・membership 剥奪 = 401 `unauthenticated`)**

| {project} | Before | After |
|---|---|---|
| 自組織 / 他組織実在 | 401 or 403 | 変更なし |
| **不在** | **404 `not_found`** (binding が先) | **401 or 403** ← S2 による変化 |

評価:

- (a) の変化は「他組織の project id を、権限不足のトークンで叩いた」ケースのみ。
  **正当なクライアントは自組織の project id しか持たない** (API キー actor は組織に束縛され、
  OAuth CLI actor もセッションが 1 組織に束縛される) ため発生しない。
  「自組織のリソースに対する `insufficient_ability`」というエラー契約は**完全に維持**される。
- (b) の変化は「トークン/セッションが既に無効な状態で、存在しない id を叩いた」ケースのみ。
  応答が 404 から 401/403 に変わるのは**エラー優先順位の変更**であり、
  「無効なトークンには、リソースの話をする前に 401/403 を返す」という
  より自然な契約への是正である (`Authenticate` の 401 と同じ優先順位になる)。
  ただし**エラー契約の変更ではある**ため、以下を Feature テストに個別登録する:
  API キー失効 / API キー発行者削除 / OAuth トークン失効 / CLI セッション失効 / membership 剥奪
  の 5 状態 × (自組織 / 他組織実在 / 不在) の応答。
- **一貫性は向上する**: 反転前は同じ cross-org リクエストが actor の ability によって
  403 / 404 に分かれていた。反転後は **cross-org は ability に関係なく常に 404**。
- 破壊的変更ではないが、`docs/app-integration-guide.md` §5 の API エラー契約に
  「actor 解決 → テナント境界の 404 → ability 判定」の優先順位を明記する。

**副作用の確認 (Round 3 指摘)**: `ResolveApiActor` を前倒しすると、
不在 id のリクエストでも actor 解決処理が走るようになる。
少なくとも OAuth 経路の `$session->touchLastUsedAt()` (`ResolveApiActor:159`) は
**不在 id でも発火するようになる**。これは actor 自身のセッションのタイムスタンプ更新であり
テナント境界を越えないが、詳細設計で
「DB 書き込み / イベント発火 / 監査記録 / 例外形式」を 1 件ずつ洗い出し、
不在 id リクエストでテナント越えの副作用が発生しないことをテストで固定する。

### web (S2)

| 短絡条件 | {project} が自組織 | {project} が他組織 |
|---|---|---|
| 未契約 / 支払い不健全 | onboarding 着地 (302) / 402 — **変更なし** | 302/402 → **404** |
| メール未確認 | `/email/verify` へ 302 — **変更なし** | 302 → **404** |
| 2FA 強制組織で未準拠 | `settings.security` へ 302 / 409 — **変更なし** | 302/409 → **404** |
| Inertia version mismatch | 409 (リロード) — **変更なし** | 409 → **404** |

正当な利用者の遮断挙動 (未契約 → onboarding 着地 等) は**一切変わらない**ため、
`docs/architecture.md` の課金ゲート運用契約 (「403 で突き放さず専用画面で受ける」) と矛盾しない。
cross-org は元々 404 が正しい応答であり、行き先のない詰みを新たに作らない。

なお `current_organization_id` が null のユーザーは、変更前も
`RequireActiveSubscription` が素通し (`:73-75`) して結局 guard の 404 に落ちていたため、挙動は不変。

---

## レート制限キーを IP 以外へ広げるかの検討 (結論: 今回は広げない)

| 案 | 判定 | 根拠 |
|---|---|---|
| 未認証 limiter を `REMOTE_ADDR` 固定に切替 | **却下** | LB 終端では REMOTE_ADDR が LB の IP になり、**全利用者が 1 バケット**に落ちて正規利用者を巻き込む。監査も「即時に絞れないなら」の代替案として挙げており、S5 で絞れる以上は不要 |
| `login` に per-account limiter を追加 | **今回は見送り** | `config('fortify.limiters.login')` を設定している構成では Fortify が `EnsureLoginIsNotThrottled` を外し `throttle:` middleware に委ねるため、**成功リクエストも計数される**。account 単位で絞ると攻撃者が被害者を締め出す lockout DoS を新設してしまう。失敗のみ計数する機構は別設計テーマ |
| session / device 単位のキー追加 | **却下** | 未認証攻撃者は Cookie を捨てるだけで回避でき、防御価値がない (config theater) |

S5 で `ip()` が実クライアントに戻れば、既存の `login` 5/min・`passkeys` 10/min は
設計どおりの防御力を回復する。**仕組みが機能していない段階で値を弄らない**(思考原則) に従い、
まず信頼境界を直し、追加のキー設計は実測 (レートリミット到達ログ) を得てから判断する。

---

## スコープ外 (いずれも完了条件に TODO 登録を含める)

1. **MCP の idempotency replay がリソース解決より前に走る点**
   `AppMcpTool::handle()` は `idempotency_key` の replay 判定を `runTool()` より前に行う。
   現時点で write tool は 0 本 (`isWriteTool()` が全て false) のため実害は無いが、
   REST 側で `api.project-in-org < idempotent` として閉じたのと同型のハザードが構造的には残る。
   write tool 追加時に同時対応する。
2. **認証手段変更のメール通知ポリシー** (S7 の通知見送り分)。
   passkey だけでなく 2FA / SSO 連携も含めた一貫したポリシーとして別途設計する。
3. **監査の Low-1〜Low-5** (phantom password の fail-open / `two-factor.confirm` の走査対象外 /
   vendor route の gate スキップ / 404 のタイミング差分 / `svelte/no-at-html-tags` 未有効化)。
   監査自身が「記録のみで可」「次に該当変更を足すときに同時対応」と判定しており、本サイクルでは扱わない。
   ただし Low-4 (タイミング差分) は S1/S2 で経路が変わるため、詳細設計で差分の位置を再確認する。
4. **`security_audit_events` に既に記録済みの IP の遡及修正**。
   S5 以前に記録された `ip_address` は信用できないが、遡及的に訂正する手段は無い。
   運用上の注意として docs に残すに留める。
