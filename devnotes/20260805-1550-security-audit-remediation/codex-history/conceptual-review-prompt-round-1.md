【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【セキュリティ不変条件(アプリ都合で緩めない)】

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

本件は「直近サイクル完了後の多角監査 (セキュリティ観点) で検出された欠陥の是正」の概念設計です。
監査レポートは `devnotes/20260805-1600-audit-cycle-2/security.md` にあります (読んで構いません)。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項・セキュリティ不変条件に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）。特に middleware 実行順序・
   Laravel の middleware priority・TrustProxies の config fallback 経路の理解が正しいか
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか (特に既存の運用契約・エラー契約の破壊)
6. スコープの適切さ: 過大または過小になっていないか。特に「スコープ外」に落とした項目の判断が妥当か
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10 を通せるか

【特に厳しく見てほしい点】
- S1/S2 の順序反転が本当に存在オラクルを閉じるか。閉じ残る象限は無いか
- S4 の Architecture テスト設計が「同じ穴が再発したら落ちる」を実際に満たすか。
  分類の粒度 (ShortCircuits / Transparent) が実務的に維持可能か
- S5 の「production で fail-fast」が運用上受け入れ可能か。より安全側の代替はあるか
- スコープ外 1 (`verified` / 2FA 強制 gate の同型残存) を残す判断が妥当か。
  「不変条件をアプリ都合で緩めない」に抵触しないか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は `devnotes/20260805-1550-security-audit-remediation/conceptual-design.md` の全文）

# 概念設計: security-audit-remediation

対象監査レポート: `devnotes/20260805-1600-audit-cycle-2/security.md`
(High 2 件 / Medium 2 件。Critical 0 件)

---

## 背景・課題

直近サイクル (T099〜T106) 後の多角監査で、**今サイクルの主目的そのものに残った穴**が 2 件見つかった。
いずれも既存テストの検査範囲外であり、`composer test` が 2865 passed でも安全性の証明にならない。

### 課題 1 — 存在オラクルが ability チェックの手前で残っている (High-1)

T103 は `EnsureProjectBelongsToApiOrganization` (`api.project-in-org`) を FormRequest より前に置いたが、
**実行時 middleware 順序**は

```
Authenticate → Throttle → SubstituteBindings → ResolveApiActor
  → RequireApiKeyAbility(403) → EnsureProjectBelongsToApiOrganization(404) → IdempotentRequest
```

であり、**ability の 403 がテナント境界の 404 より前**にある。
`SubstituteBindings` は不在 id を 404 にするため、両者の差が 1 bit の存在オラクルになる。

| read-only API キーで `POST /api/v1/projects/{id}/items` | 応答 |
|---|---|
| 他組織に実在する project | **403** `insufficient_ability` |
| どこにも存在しない id | **404** `not_found` |

しかも `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php:125-128` が
`api-key.ability:* < api.project-in-org` を**意図的に機械固定**しているため、
順序を直すと Architecture テストが落ちる = 自然治癒しない。

**横断確認の結果、同じ構造の穴がさらに 2 件見つかった**(本設計で自ら実査):

- **W-1 (web / 30 route)**: `routes/web.php:400` と `:528` の業務 group が
  `['require-active-subscription', 'project.in-route-org']` の順で宣言されている。
  未契約 / 支払い不健全な組織のユーザーが `projects.*` / `capture.*` を叩くと
  cross-org 実在 project は **402 (XHR) / 302 (ブラウザ)**、不在 id は **404** に分岐する。
  漏れる情報は API と同じ「project id の実在」で、影響 route 数は API より多い。
- **W-2 (`organizations.members.two-factor.reset`)**: `{user}` が**グローバル implicit binding**
  (`RouteBindingTypes` の `'user' => User::class`) で解決され、org 所属検査は controller の
  inline guard (`OrganizationMemberController::resolveOrganizationMember`) にしかない。
  そこへ `recent-auth` (302/409) が middleware として先に走るため、
  step-up が stale な状態では「実在するが非メンバーの user id = 302/409」「不在 id = 404」に分岐し、
  **グローバルな `users.id` の実在**が漏れる。

さらに一般化すると、**`SubstituteBindings` より後に走り 404 以外で短絡するあらゆる middleware**が
同じ穴になる。現状 `EnsureEmailIsVerified` (未認証メールの 302) や
`RequireTwoFactorForEnforcedOrganizations` (2FA 未設定の 302) も
テナント guard より前に走っており、同型の残存がある (§スコープ外で扱いを定義する)。

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
- `passkey.destroy` だけ throttle が無い (vendor の `$passkeyMiddleware` が `$throttle` を含まない)。
  `EnsureLoginMethodRemains` は毎リクエスト `DB::transaction` + User 行 `lockForUpdate()` を取るため、
  認証済みユーザーが自分の User 行に無制限のロック競合を起こせる。

---

## 改善アイデア

> **設計の芯**: 個別の穴を潰すだけでは同型の穴が再発する。
> 「**テナント境界の 404 は、SubstituteBindings 以降で短絡しうるどの middleware よりも前**」という
> **順序不変条件を言語化し、Architecture テストで機械強制**する。
> 既存の残存 (`verified` / 2FA 強制) は「隠す」のではなく**理由付き exemption として凍結**し、
> 新規の穴だけが必ず落ちる deny-by-default にする。

### S1 [High-1] API v1: `api.project-in-org` を `api-key.ability:*` より前へ

新しい順序契約:

```
auth → throttle → resolve.api-actor → api.project-in-org → api-key.ability:* → idempotent → controller
```

- `resolve.api-actor` より後: `organization` attribute が前提 (これは不可侵のまま)
- `api-key.ability` より前: **ability 403 で cross-org の実在を漏らさない** (今回の反転)
- `idempotent` より前: cross-org リクエストで idempotency 行を作らせない (不可侵のまま)

**なぜ現在この順序なのかの実査**: T103 の詳細設計 (`devnotes/20260805-1244-controller-authorization-gate/detailed-design.md:772`)
では順序契約の表に載っていたのは `resolve.api-actor` と `idempotent` の 2 件だけで、
`api-key.ability` は「順序を書き下した 1 行」に紛れて入っただけだった。
テスト側の根拠コメント「エラー契約 insufficient_ability が route ごとにぶれる」も
**後付けの説明**であり、ability を先に置く積極的な理由は存在しない。
むしろ `docs/app-integration-guide.md` §7 不変条件 8 は
`api-key.ability` middleware を**認可 (層 3) として数えない**と明記しており、
「層 2 (404) → 層 3 (403)」の原則から見れば ability は層 2 の後に来るべきである。

### S2 [High-1 横断] web 業務 group: `project.in-route-org` を `require-active-subscription` より前へ

`routes/web.php:400` / `:528` の middleware 配列を
`['project.in-route-org', 'require-active-subscription']` に反転する。

`EnsureProjectBelongsToRouteOrganization` は `{project}` を持たない route では no-op であり、
`RequireActiveSubscription` は current org 未設定なら素通しする (`:73-75`) ため、
**正当な利用者の遮断挙動 (未契約 → onboarding 着地) は一切変わらない**。
変わるのは「他組織の project id を指定したとき 302/402 ではなく 404 になる」だけ。

### S3 [High-1 横断] 組織メンバー route の `{user}` を `Route::scopeBindings()` 化

`organizations.members.update` / `.destroy` / `.two-factor.reset` の 3 本を
`Route::scopeBindings()` group に入れ、`{user}` を `Organization::users()` relation 経由で解決させる。
不整合は **binding 段 (= 全 app middleware より前) で 404** になり、
`recent-auth` を含むどの middleware より前に閉じる。
controller の inline guard (`resolveOrganizationMember`) は二重防御として残す。

`NestedRouteIdorDefenseTest` の inventory も `UrlIntegrityGuard` → `ScopeBindings` に更新する。

### S4 [構造強制] 順序不変条件の Architecture テスト新設

**これが「同じ穴が再発したら落ちる」本体** (AGENTS.md 禁止事項 1)。

1. **middleware の deny-by-default 分類**: `App\Http\Middleware\*` の全クラスを
   `ShortCircuits` (3xx/4xx を返して `$next` を呼ばずに返しうる) / `Transparent` に分類する
   inventory を持ち、**未分類クラスがあれば fail**させる (新 middleware の追加時に必ず判断させる)。
2. **guard を持つ route の順序**: テナント guard
   (`EnsureProjectBelongsToRouteOrganization` / `EnsureProjectBelongsToApiOrganization`) を持つ route は、
   **解決後 (priority 適用後) の実行順**で guard より前に `ShortCircuits` middleware を置けない。
   例外は**理由 30 文字以上付きの exemption inventory** 登録のみ。
3. **inline guard に頼る route の制約**: `NestedRouteIdorDefenseTest` で
   `UrlIntegrityGuard` モードに分類された route には `ShortCircuits` middleware を置けない
   (inline guard は全 middleware の後に走るため、前に短絡があれば必ずオラクルになる)。

**測定は宣言順ではなく実行順で行う**。既存 `ProjectRouteCurrentOrgGuardTest` は
`gatherMiddleware()` (宣言順) しか見ておらず、監査が「docblock を信用せず実測した」ときに
初めて穴が見えた。この教訓を「テスト自体を実測器にする」形で構造化する。

### S5 [High-2] `trustProxies` を env 由来の allowlist へ

- `bootstrap/app.php` から `at: '*'` を撤去し、`headers:` のみ指定する。
  proxy 集合は Laravel `TrustProxies` の**公式 fallback 経路** `config('trustedproxy.proxies')` から供給する
  (思考原則 1: フレームワークのレンジ内でやる)。
  `withMiddleware` の callback は config 読込より前に走るため `at:` に closure は渡せない
  (`trustHosts` と違い `trustProxies(at:)` は `array|string|null` のみ) — この制約が config 経由を選ぶ理由。
- `config/trustedproxy.php` が `TRUSTED_PROXIES` (CSV) を list へ写す。
  `none` は「プロキシは無い」という**明示宣言**として空 list に写す
  (「未設定で空」と「意図的に空」を区別するため)。
- `App\Support\TrustedProxiesConfigValidator` (純粋クラス) を新設し、
  `ProductionEnvGuard::violations()` から委譲する
  (`TrustedHostsConfigValidator` と完全に同じ作法。production 起動時 fail-fast + `production:preflight`)。
  production での違反: 未設定 (空かつ `none` 未宣言) / `*` / `**` / `REMOTE_ADDR` / IP・CIDR 書式違反。
- **既定は「信頼しない」**。ローカル (`artisan serve` :8001) / bug-hunt (:8010-8018) / CI /
  in-process browser server はいずれもプロキシ無しの loopback 直結のため、
  空 = `ip()` が REMOTE_ADDR になり**現状と同じかより正確**になる (実査で確認)。

### S6 [High-2 付随] `RedirectToHttps` を `TrustProxies` より後ろへ

`bootstrap/app.php:50` の `$middleware->prepend(RedirectToHttps::class)` は
グローバル middleware 配列の**先頭** = `TrustProxies` **より前**に置く。
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
- **Architecture テスト**: `SecurityEventType` の全 case が `app/` 内で少なくとも 1 箇所
  記録経路から参照されていることを deny-by-default で走査する (今回の欠落が構造的に再発しない)。
- **メール通知は今回入れない**。2FA 有効/無効も SSO 連携も通知は無く監査ログのみで、
  passkey だけ通知を足すと「認証手段変更の通知ポリシー」が passkey だけ突出した非一貫な状態になる。
  通知は独立した設計テーマとして切り出す (§スコープ外)。

### S8 [Medium-2] `passkey.destroy` に `throttle:passkeys` を後付け

`PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes()` に throttle 後付けを追加し、
`PasskeyRouteProtectionTest` の inventory と**解決後の実行順**
(`ThrottleRequests` < `RequireRecentAuth` < `EnsureLoginMethodRemains`) を固定する。

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
| web 業務 route の cross-org 実在判定 | 未契約組織で 402/302 と 404 に分岐 (30 route) | 全て同一 404 |
| `users.id` 実在判定 (メンバー 2FA リセット) | stale step-up で 302/409 と 404 に分岐 | binding 段で同一 404 |
| 同型の穴の再発 | 検出手段なし (実測して初めて分かる) | Architecture テストが実行順を測って落とす |
| `$request->ip()` | XFF 最左 = 攻撃者制御 | 信頼 proxy の外側 = 実クライアント |
| passkey 登録/削除 | 監査証跡ゼロ | `security_audit_events` に記録 + 全 case の記録経路を機械保証 |
| `passkey.destroy` | throttle 無し (User 行ロック無制限) | 10/min |

---

## 実装方針（概要）

| # | 施策 | 主な変更コンポーネント |
|---|---|---|
| S1 | API 順序反転 | `routes/api.php`, `EnsureProjectBelongsToApiOrganization` docblock, `bootstrap/app.php` alias コメント, `ProjectRouteCurrentOrgGuardTest`, `ItemAuthorizationTest` |
| S2 | web 順序反転 | `routes/web.php` (2 group), Feature テスト |
| S3 | メンバー route の scopeBindings 化 | `routes/web.php`, `NestedRouteIdorDefenseTest` inventory, Feature テスト |
| S4 | 順序不変条件の機械強制 | 新 enum + 新 Architecture テスト, `docs/app-integration-guide.md` §7 |
| S5 | trusted proxies | `bootstrap/app.php`, `config/trustedproxy.php` (新), `TrustedProxiesConfigValidator` (新), `ProductionEnvGuard`, `.env.example`, docs |
| S6 | RedirectToHttps の位置 | `bootstrap/app.php`, Feature テスト |
| S7 | passkey 監査ログ | `SecurityEventType`, `RecordSecurityEvent`, Architecture テスト (新), Feature テスト |
| S8 | passkey.destroy throttle | `PasskeyServiceProvider`, `PasskeyRouteProtectionTest` |

各施策は「テストを先に落としてから直す」(思考原則 5)。
特に S1 は `ProjectRouteCurrentOrgGuardTest` の既存アサーションを**反転**する必要があり、
反転前に Feature テスト (read-only キー × cross-org) が red になることを確認してから着手する。

---

## 制約・前提

### 本番プロキシ構成は確認できなかった (実査結果)

リポジトリ全体を実査した結果、**本番/staging のインフラ定義もドキュメントも存在しない**:

- `deploy/` ディレクトリは存在しない。terraform / k8s / Procfile / fly.toml / vapor.yml も無い
- nginx / Caddy / Traefik の設定ファイルはリポジトリ内に 1 つも無い
- `docker-compose.yml` は devcontainer 専用 (app / db / mailpit の 3 サービス、proxy 無し)
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

- XFF の連鎖 (`[XFF 左…右, REMOTE_ADDR]`) から trusted なものを右から除去した**最初の untrusted 値**が
  client IP になる。したがって **hop を 1 つでも取りこぼすと client IP がその hop の IP に固定**され、
  全利用者が 1 つの rate limit バケットに落ちる (= 自己 DoS)。
  CloudFront → ALB のような多段構成では**両方の range** を列挙する必要がある。
- `TRUSTED_PROXIES` を設定せずに本設計をデプロイすると **production 起動が fail-fast する**。
  デプロイ手順では「env 設定 → デプロイ」の順序を守ること。

### 既存の「問題なし」判定を壊さない

監査が自己申告を検証して問題なしと確認した以下は、本設計で**壊さないことを明示的に確認する**:

| 項目 | 本設計での扱い |
|---|---|
| 404 body / ヘッダの完全同一性 | `ApiExceptionRenderer` に触れない。S1 は「403 が出る条件」を減らすだけで 404 の生成経路は不変 |
| passkey 削除 4 ケースの同一 404 | `SelfScopedPasskeyBinder` に触れない。S8 は throttle 追加のみ (429 は binding より前で、id に依存しない) |
| `PasskeyLoginPolicy` の fail-closed | 触れない |
| `password_confirmed_at` 非汚染 | 触れない |
| TOCTOU (同時削除) | `EnsureLoginMethodRemains` のロジックに触れない。S8 は前段に throttle を足すだけ |
| CSRF / SQLi / XSS | 新規の raw SQL / `{@html}` / CSRF 除外を追加しない |

### その他の前提

- 既存 API クライアント (`packages/cli`) は自組織の project しか扱わないため S1 の影響を受けない (§API クライアント影響)。
- テストは T099 のグローバルロック経由 (`composer test`) で走る。
- PHPStan level 10 / Pint / RefreshDatabase グローバル適用は既存どおり。

---

## API クライアントへの影響 (S1 の順序反転)

反転で応答が変わるのは **「ability が不足」かつ「{project} が actor の組織に属さない」** という
**両方が同時に成立する 1 象限だけ**である。

| ability | {project} | Before | After |
|---|---|---|---|
| 有 | 自組織 | 通常処理 | 変更なし |
| 有 | 他組織実在 | 404 | 変更なし |
| 有 | 不在 | 404 | 変更なし |
| **不足** | **自組織** | 403 `insufficient_ability` | **変更なし** (エラー契約は保たれる) |
| **不足** | **他組織実在** | 403 `insufficient_ability` | **404 `not_found`** ← 唯一の変化 |
| 不足 | 不在 | 404 | 変更なし |

評価:

- **正当なクライアントは自組織の project id しか持たない**。API キー actor は組織に束縛され、
  OAuth CLI actor もセッションが 1 組織に束縛される (`ResolveApiActor`)。
  「自組織のリソースに対する `insufficient_ability`」というエラー契約は**完全に維持**される。
- 変化する象限は「他組織の project id を、権限不足のトークンで叩いた」ケースのみ。
  これは正当なクライアントには発生しない (発生していたなら、それ自体が調査対象)。
- **一貫性はむしろ向上する**: 反転前は「ability を持つと 404 / 持たないと 403」と
  同じ cross-org リクエストが actor の ability で応答が変わっていた。反転後は
  **cross-org は ability に関係なく常に 404** になる。
- 破壊的変更ではないが、`docs/app-integration-guide.md` §5 の API エラー契約に
  「テナント境界の 404 は ability 判定より前」を明記し、リリースノート相当の記述を残す。

S2 も同型で、変化するのは「未契約組織のユーザーが他組織の project id を叩いた」象限のみ。
自組織 project に対する遮断 (onboarding 着地 / 402) は不変であり、
`docs/architecture.md` の課金ゲート運用契約 (「403 で突き放さず専用画面で受ける」) と矛盾しない
(cross-org は元々 404 が正しい応答であり、行き先のない詰みを新たに作らない)。

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

## スコープ外

1. **`verified` / 2FA 強制 gate の同型残存**
   `EnsureEmailIsVerified` と `RequireTwoFactorForEnforcedOrganizations` は
   `SubstituteBindings` より後・テナント guard より前に走るため、
   「メール未確認ユーザー」「2FA 強制組織で 2FA 未設定のユーザー」という**劣化状態の認証済みユーザー**に対しては
   cross-org 実在が 302 として漏れる。
   これを閉じるにはテナント guard を Laravel の middleware priority list で
   `SubstituteBindings` の直後へ引き上げる必要があるが、その場合
   `SecurityHeaders` / `NoStoreCacheHeaders` / `EncryptHistory` / `HandleInertiaRequests` が
   **404 応答に対して走らなくなる**という波及が出る (これらは guard より後段になるため)。
   費用対効果が本サイクルの範囲を超えるため、**S4 の exemption inventory に理由付きで凍結**し、
   「新規の短絡 middleware は必ず落ちる」状態にしたうえで別 TODO とする。
2. **MCP の idempotency replay がリソース解決より前に走る点**
   `AppMcpTool::handle()` は `idempotency_key` の replay 判定を `runTool()` より前に行う。
   現時点で write tool は 0 本 (`isWriteTool()` が全て false) のため実害は無いが、
   REST 側で `api.project-in-org < idempotent` として閉じたのと同型のハザードが構造的には残る。
   write tool 追加時に同時対応する。
3. **認証手段変更のメール通知ポリシー** (S7 の通知見送り分)。
   passkey だけでなく 2FA / SSO 連携も含めた一貫したポリシーとして別途設計する。
4. **監査の Low-1〜Low-5** (phantom password の fail-open / `two-factor.confirm` の走査対象外 /
   vendor route の gate スキップ / 404 のタイミング差分 / `svelte/no-at-html-tags` 未有効化)。
   監査自身が「記録のみで可」「次に該当変更を足すときに同時対応」と判定しており、本サイクルでは扱わない。
   ただし Low-4 (タイミング差分) は S1 で経路が変わるため、詳細設計で差分の位置を再確認する。
5. **`security_audit_events` に既に記録済みの IP の遡及修正**。
   S5 以前に記録された `ip_address` は信用できないが、遡及的に訂正する手段は無い。
   運用上の注意として docs に残すに留める。

