# Codex レビュー依頼: 概念設計 (path-based-throttle / 流量制限の付与漏れ検査)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)。
   **クラス起点の主キー同一性クエリ**(`User::find($payloadId)` /
   `User::query()->where('id', …)` / `DB::table('users')->where('id', …)`)は
   deny-by-default で分類が要る(`ModelDirectFetchInvariantTest` + `DirectFetchInventory`。
   route parameter 由来の id は `NestedRouteIdorDefenseTest` の担当で母集団が交わらない)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)
9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE は `Gate::authorize` を通すか、
   exemption inventory へ理由付きで登録する(deny-by-default)。
   **層 2(テナント境界 = 404)は層 3(認可 = 403)より前**(逆にすると存在が漏れる)
   (`ControllerAuthorizationGateTest`)
10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
> (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
> 相互参照するときは**番号ではなく項目名**で指すこと。既存の参照
> (`docs/app-integration-guide.md` の「§7 不変条件 8」/ stripe webhook migration の「不変条件 7」)
> を壊すため、どちらの側も renumber しない。

> **運用要件 (T108)**: production は `TRUSTED_PROXIES` の**明示宣言が必須**
> (未宣言 / `*` / `REMOTE_ADDR` / 書式不正は `ProductionEnvGuard` が起動時 fail-fast する
> = **初回デプロイ前に設定が要る破壊的変更**)。`trustProxies(at: '*')` はレート制限を
> 総当りに無効化するため復活させない。実 hop 一覧・CIDR の管理主体・変更手順は
> `docs/trusted-proxies-runbook.md` が正本。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション(Laravel 12 + Svelte 5 + Inertia)の改善に関する概念設計レビュアーです。

【重要な前提 — 蒸し返さないこと】
本設計の上位裁定 (c2c 台帳 AG-096 / AG-097、2026-08-06、オーナー判断) は確定した与件です。
以下は議論の対象外であり、これに反する指摘は行わないでください:
- 機構は自前で作らずフレームワーク標準の名前付きリミッタを使う
- 貼る仕組みは 3 段の優先順 (設定 > route 名の後付け + 起動時 fail-fast > URL パス表は原則禁止)
- 閾値はプロダクト依存であり既存値は変えない
- 429 応答の契約と信頼するプロキシの設定は別 feature へ切り出し済み (本設計の射程外)
- 外部パッケージは採用しない

【レビュー観点】
1. 使命との整合性
2. 禁止事項・セキュリティ不変条件への抵触
3. 実現可能性 (Laravel 12 の RateLimiter / ThrottleRequests / route:list の実挙動として成立するか)
4. 期待効果の妥当性
5. リスク (副作用・後退。とくに「throttle を貼ったことで新たな DoS / 巻き添え / 存在オラクルが生まれないか」)
6. スコープの適切さ (過大 / 過小。とくに §6-2「スコープに入れないもの」の判断が妥当か)
7. 型安全性 (PHPStan level 10 を通せる設計か)
8. 目録検査 (Architecture テスト) の母集団セレクタが「すり抜け」と「形骸化」の両方を避けられているか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: 流量制限の付与漏れ検査 + キー規約の是正 (path-based-throttle)

- 対象 feature (c2c): `path-based-throttle`
- 裁定: AG-096 (2026-08-06) / AG-097 (2026-08-06) — 蒸し返さない確定与件
- 設計日時: 2026-08-06 14:03 JST
- 実査対象コミット: main (`2cb9068` 時点の作業ツリー)

---

## 0. 与件 (裁定済み・議論しない)

AG-096 が確定させた標準形のうち、本設計が従う部分:

1. **機構は自前で作らない**。フレームワーク標準の名前付きリミッタ (`RateLimiter::for`) を使う。
   URL パス文字列の表を持つ独自 middleware は採らない。
2. **貼る仕組みは 3 段の優先順**。
   (a) 設定ファイルで貼れるものは設定で貼る (`config/fortify.php` の `limiters`)
   (b) 設定で貼れないベンダー登録ルートは **route 名で後付けし、引けなければ起動時に例外で落とす**
   (c) URL パス表は原則禁止
3. **数える単位 (キー) は規約で規定する**。`{レーン}:{種別}:{値}`。
   メールをキーにするときは**認証側と同じ正規化関数**を使い、**ハッシュ化**してから使う。
4. **閾値はプロダクト依存**。既存値は変えない。新規に必要な値は根拠をコードに残す。
5. **付与は冪等**にし、実効 middleware 列に throttle が **1 本だけ**であることを固定する
   (二重付与は実効上限を半減させる)。
6. 家系全体の穴 = 「**制限を貼り忘れた経路がある**ことを機械検出できるリポジトリが 1 つも無い」。
   本設計の最大の価値はここを埋めること。

AG-097 により本 feature の boundary から外れたもの (**本設計では扱わない**):

- 信頼するプロキシの設定 (`trusted-proxy-hardence` → aicue では T108 で実装済み)
- 429 応答の経路別契約 (フォーム内エラー / エラー画面 / API 形式) — `error-response-contract`
- API エラー封筒 — `api-error-envelope`

---

## 1. 仮説

> **「貼り忘れは無音である」**。aicue には既に 10 本の名前付きリミッタがあり、
> キー不変条件テスト (`NamedRateLimiterKeyTest`) すら家系唯一で持っている。
> にもかかわらず、**未認証で到達できる credential 面と webhook に throttle が 1 本も付いていない**
> はずである。理由は「付いていないことを誰も検出できない」から。
>
> 検証方法: `php artisan route:list --json` で実効 middleware 列を機械的に走査し、
> 「保護対象群に属するのに `ThrottleRequests` を持たない route」を列挙する。
> 1 本でも出れば仮説は成立する。

**成功の判定条件**:

- 保護対象群の付与漏れが機械検出できる (deny-by-default の Architecture テストが存在する)
- 実際に検出された穴が塞がっている (または理由付きで exemption 登録されている)
- 新しい route を足したときに、貼り忘れれば **CI が赤くなる**

---

## 2. 現状 (実査結果 — 台帳・ブリーフを鵜呑みにせず実コードで確認)

### 2-1. 名前付きリミッタは 10 本 (台帳は 9 本と記述 → 食い違い)

| # | limiter | 定義箇所 | 閾値 | キー |
|---|---------|---------|------|------|
| 1 | `login` | `app/Providers/FortifyServiceProvider.php:169` | 5/min | `Str::transliterate(Str::lower($username).'|'.$ip)` |
| 2 | `two-factor` | 同 `:178` | 5/min | `login.id` 生値 / `{ip}|2fa` |
| 3 | `passkeys` | 同 `:188` | 10/min | `passkey|{userId}` / `passkey|{ip}` |
| 4 | `render-trigger` | `app/Providers/AppServiceProvider.php:247` | 6/min | `render-trigger:{userId}:{orgId}` |
| 5 | `inquiry` | 同 `:266` | 5/min + 10/60min | `inquiry:ip:{ip}` / `inquiry:ip-email:{ip}:{sha256}` |
| 6 | `api-read` | 同 `:286` | 120/min | `api-key:{id}` / `oauth-user:{id}` / `ip:{ip}` |
| 7 | `api-write` | 同 `:287` | 60/min | 同上 |
| 8 | `api-status` | 同 `:288` | 30/min | 同上 |
| 9 | `api-mcp` | 同 `:289` | 60/min | `mcpRateKey()` |
| 10 | `oauth-register` | 同 `:293` | 10/min | `oauth-register:ip:{ip}` |

台帳 (AG-096 の actions) は「リミッタ 9 本」と記述しているが、実測は **10 本**。
`passkeys` は T106 (`d34cc6a`) で追加されており、台帳の観測点 `aicue@db4620c` より後の可能性が高い。

### 2-2. 「貼る仕組み」の 3 段は既に部分的に実践済み

- (a) 設定経由: `config/fortify.php` の `limiters` に `login` / `two-factor` / `passkeys` の 3 本
- (b) route 名で後付け + 起動時 fail-fast: `routes/ai.php:47-72` の DCR (`POST /oauth/register`)。
  URI + action name の二段照合で、見つからなければ `RuntimeException` で起動を止める。
  `PasskeyServiceProvider` も同種の後付けを持つ (`PasskeyRouteProtectionTest` が固定)。
- (c) URL パス表の独自 middleware: **存在しない** (= 標準形に既に適合)

→ **仕組みは揃っている。足りないのは「貼り忘れの検出」と「キー規約の統一」だけ**。

### 2-3. 実測した付与漏れ (route:list 206 本の実効 middleware 走査)

**未認証で本体に到達しうる変更系 (POST/PUT/PATCH/DELETE) のうち throttle 無し**:

| route | 危険性 |
|-------|--------|
| `POST /forgot-password` (`password.email`) | **メール送信の増幅口 + アカウント列挙**。Fortify は `limiters` に reset 系を持たず、標準では無制限 |
| `POST /reset-password` (`password.update`) | **reset token の総当り** |
| `POST /register` (`register.store`) | **アカウント量産 + 確認メール送信の増幅** |
| `POST /ses/notification` (`webhooks.ses`) | 署名検証 (`VerifySnsSignature`) は証明書取得を伴う。**署名検証コスト自体が増幅対象** |
| `POST /stripe/webhook` (`cashier.webhook`) | middleware が **1 本も無い** |
| `PUT /storage/{path}` (`storage.local.upload`) | local disk driver の署名付きアップロード経路 |
| `POST /livewire-*/update` | Filament 管理画面 (admin login を含む) の唯一の入口 |
| `POST /debug/login/{userId}` | `app()->isLocal()` で route 登録自体が囲われている |
| `POST /admin/logout` | セッション破棄のみ |

**認証系 (認証済み側) のうち throttle 無し**:

`password.confirm.store` / `user-password.update` (current_password 必須 = 総当り面) /
`invitations.accept.store` (招待トークン照合) /
`two-factor.confirm` / `two-factor.enable` / `two-factor.disable` /
`two-factor.regenerate-recovery-codes`

**ステートレスな機械向け経路のうち throttle 無し**:

`GET|DELETE /api/v1/mcp` (laravel/mcp が登録する定数 405 スタブ) /
`.well-known/oauth-*` ×4 (定数 JSON メタデータ)

→ **仮説は成立**。とくに `POST /forgot-password` / `POST /register` / `POST /reset-password` は
「ログイン画面がある限り狙われる」典型面であり、AG-096 が必須化した認証経路そのもの。

### 2-4. キー規約の実態

- 規約形 `{レーン}:{種別}:{値}` に**準拠しているのは `inquiry` と `oauth-register` の 2 本のみ**。
- `login` のキーは `Str::transliterate(Str::lower($username).'|'.$ip)`。
  ところが同リポジトリの `app/Support/EmailNormalizer.php` は docblock で
  「用途: rate limiter key 生成 (login / register / forgot 等)」と自称しつつ、
  「**`Str::transliterate()` は legitimate な Unicode email を別 user に collapse させる
  リスクがあるため使わない**」と明記している。
  **設計意図の正本 (EmailNormalizer) と実装 (login limiter) が正面から矛盾している**。
  実害は「無関係アカウントの巻き添えロックアウト」。
- `inquiry` は `hash('sha256', $email)` の非鍵付きハッシュ。同リポジトリには
  `app/Support/EmailHash.php` (HMAC-SHA256 / `app.key` 鍵付き) があり、
  docblock が「単純 sha256 は辞書攻撃に弱い」と明記している。**helper があるのに使っていない**。
- なお `ThrottleRequests::handleRequestUsingNamedLimiter()` は
  `md5($limiterName.$limit->key)` を最終キーにするため、**limiter 間のキー衝突は構造的に起きない**。
  規約の価値は衝突防止ではなく「読んで意味が分かること」と「PII を持ち込まないこと」にある。

### 2-5. 既存の周辺不変条件 (壊してはならない)

- `ThrottleRequests` は Laravel 既定の priority list で **`Authenticate` の後・`SubstituteBindings` の前**。
  `bootstrap/app.php` は `appendToPriorityList()` しか使っておらず既定順を置換していない
  → **429 は pre-binding の短絡**であり、route parameter の実在を漏らさない (AGENTS.md 不変条件 2/10)。
- `NamedRateLimiterKeyTest` (Feature/Security) が「limiter キーが route parameter を含まない」を
  実挙動で固定済み (家系唯一)。
- `PasskeyRouteProtectionTest` が passkey 系 7 route の middleware 列を完全一致で固定済み。
- `ControllerAuthorizationGateTest` (Architecture) が「変更系 route は認可を通る」を
  **enum + 理由文字列付き exemption inventory + deny-by-default** で強制している。
  → **本設計の目録検査はこの構造をそのまま踏襲する** (新しい流儀を発明しない)。
- `CACHE_STORE=database` (`.env.example`) = カウンタは全ノード共有の保存先にある (標準形 (3) を満たす)。

---

## 3. 課題

1. **付与漏れが無音** (最重要)。新しい route を足して throttle を忘れても、
   エラーは出ずテストも通る。気付くのは攻撃を受けた後。
2. **未認証の credential 面 3 本と webhook 2 本が実際に無防備**。
3. **キーの流儀が 10 本でバラバラ**。うち 1 本 (`login`) は
   リポジトリ内の正本 helper が「使うな」と書いた関数を使っており、巻き添えロックアウトを生む。
4. **二重付与を止めるものが無い**。後付け配線を増やすと、
   設定経由 + 後付けの二重適用で実効上限が半減する事故が起きうる (現状は 0 件だが構造的に無防備)。

---

## 4. 方針

### 方針 A: 保護対象群の目録検査を deny-by-default で作る (最大の価値)

`tests/Architecture/ThrottleCoverageInventoryTest.php` を新設する。
`ControllerAuthorizationGateTest` と同じ構造 (母集団セレクタ + 型付き exemption + 理由必須 +
stale 検出 + floor による空振り検出) を採る。

**母集団は名前の列挙ではなく構造セレクタで決める** (列挙にすると新 route の登録漏れで検査がすり抜ける):

- **S1 未認証変更系**: method ∈ {POST, PUT, PATCH, DELETE} かつ
  実効 middleware に `Authenticate` を含まない
- **S2 ステートレスな機械向け経路**: uri が `api/` / `oauth/` / `.well-known/oauth-` で始まり、
  かつ実効 middleware に `StartSession` を含まない
- **S3 認証系の変更系**: named route が認証面パターン
  (`login|logout|register|password.|user-password.|two-factor.|passkey.|verification.|recent-auth.|invitations.|settings.password.|social.|filament.admin.auth.`)
  に一致し、かつ method ∈ {POST, PUT, PATCH, DELETE}

**合格条件**: 実効 middleware 列に `ThrottleRequests` が **ちょうど 1 本**
(0 本 = 付与漏れ / 2 本以上 = 二重付与で実効上限半減。両方を同じ検査で殺す)。

**不合格の逃げ道**: `App\Enums\Security\ThrottleCoverageExemption` + 30 文字以上の具体的理由を
inventory に登録する。理由が書けないものは「throttle を足すべき route」である。

### 方針 B: 検出された穴を塞ぐ (閾値は既存値の踏襲を最優先)

| route | 施策 | 閾値の根拠 |
|-------|------|-----------|
| `POST /forgot-password` | 新 limiter `password-reset-request` | `inquiry` と同性質 (未認証 + メール送信を伴う公開 POST) → **既存 `inquiry` の値をそのまま踏襲** (5/min + 10/60min) |
| `POST /reset-password` | 新 limiter `password-reset-submit` | `login` と同格の credential 総当り面 → **既存 `login` の 5/min を踏襲** + IP-email の 10/60min |
| `POST /register` | 新 limiter `account-register` | 同上 (`oauth-register` と紛らわしいので `account-register`) |
| `POST /ses/notification` | 新 limiter `webhook-ses` (IP + 固定キー全体天井) | 正常時ピークは分あたり数件。標準形 (3) に従い 1〜2 桁上の値を天井に置く |
| `POST /stripe/webhook` | 新 limiter `webhook-stripe` (同上) | 同上。**レーンを分ける** (SES への攻撃で Stripe を止めない) |
| `POST /user/confirm-password` | 既存汎用 `throttle:6,1` | `recent-auth.password` / `settings.password.store` と同一値・同一性質 |
| `PUT /user/password` | 既存汎用 `throttle:6,1` | 同上 (current_password 必須 = 総当り面) |
| `POST /invitations/accept` | 既存汎用 `throttle:10,1` | `onboarding.activate-personal` と同値 |
| 2FA 管理 4 本 | 既存汎用 `throttle:10,1` | 同上 |

**新規閾値の発明は webhook 2 本だけ**。他はすべて既存値の踏襲。

### 方針 C: 後付け配線の単一化 (標準形の第 2 段を helper 化)

`app/Support/Http/RouteThrottleBinder.php` を新設する。

- `attachByName(string $routeName, string $throttle): void`
  route 名で引き、**引けなければ `RuntimeException` で起動を止める** (fail-fast)。
- 既に `ThrottleRequests` を持つ route への attach も `RuntimeException`
  (= **付与の冪等性を型で担保**。二重付与で実効上限が半減する事故を構造的に潰す)。

Fortify / Cashier / Passport が登録する vendor route への付与はすべてこの helper を通す。

### 方針 D: キー規約 `{レーン}:{種別}:{値}` への統一 + 機械検査

- 全 15 本 (既存 10 + 新規 5) のキーを規約形に統一する。**閾値は 1 つも変えない**。
- `login`: `Str::transliterate` を廃止 → `EmailNormalizer::normalize()` + `EmailHash::compute()`。
  キーは `login:email-ip:{hmac}:{ip}`。
  → 巻き添えロックアウトの解消 + キャッシュから email 由来の低エントロピー値を排除。
- `inquiry`: `hash('sha256', …)` → `EmailHash::compute()` (鍵付き)。
- `tests/Architecture/RateLimiterKeyConventionTest.php` を新設し、
  登録済み limiter を列挙 → closure を実行 → 返る `Limit::$key` が
  `^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*:` に一致することを検査する。
  **未知の limiter 名は fail** (deny-by-default) にして、新 limiter の追加を強制的に検知する。

---

## 5. 代替案と却下理由

| 代替案 | 却下理由 |
|--------|---------|
| aigenba 方式の `PathBasedThrottle` middleware (URL パス表) を移植する | AG-096 で置換対象と裁定済み。aicue は全レーンが標準の名前付き route で引けるため、第 2 の経路指定を持ち込む理由が無い |
| 母集団を「全 route (206 本)」にする | 未分類が 175 本になり、exemption inventory が形骸化する。静的アセット・SEO ファイル・認証済み業務 route まで巻き込むのは過大 |
| 母集団を「認証系 route 名の列挙」にする | 新しい認証 route を足したときに列挙漏れで検査がすり抜ける。**目録検査の意味が半減する** |
| 検出された穴をすべて exemption に登録して塞がない | exemption enum は「持たないことが**正しい**と裁定した理由」の分類。塞ぐべき穴を exemption にすると台帳に嘘を書くことになる |
| 全体天井 (固定キー Limit) を認証系にも被せる | 標準形 (3) の適用条件は「増幅があり、**かつ止まっても中核の業務が止まらない**口」。ログイン面に被せると上限到達の瞬間に全員がログインできなくなり、攻撃者の目的そのものになる。webhook のみに限定する |
| `POST /livewire-*/update` に route 単位の throttle を貼る | Filament 管理画面の**全操作**が同一バケットに落ちる。防御は既に component 内にある (`vendor/filament/filament/src/Auth/Pages/Login.php:76` の `rateLimit(5)`、Register / ResetPassword / EmailVerification も同様)。exemption 登録が正しい |
| キー規約検査を静的走査 (正規表現) で行う | `apiRateKey()` のような helper 経由のキー組み立てを追えず脆い。closure を実行する behavioral 検査のほうが確実 |
| 閾値をこの機会に見直す | AG-096 が「閾値はプロダクト依存」と裁定済み。仕組みが機能していない段階で値を弄らない (思考原則) |

---

## 6. スコープ境界

### 6-1. このタスクでやる

- A1: `ThrottleCoverageInventoryTest` + `ThrottleCoverageExemption` enum
- A2: 実測された付与漏れ 12 route の是正 (新 limiter 5 本 + 既存汎用 throttle の適用)
- A3: `RouteThrottleBinder` (route 名後付け + 起動時 fail-fast + 二重付与拒否)
- A4: キー規約 `{レーン}:{種別}:{値}` への統一 (15 本。**閾値不変**)
- A5: `RateLimiterKeyConventionTest` (キー規約の機械検査、deny-by-default)

### 6-2. スコープに入れないものと理由 (必読)

| 入れないもの | 理由 |
|-------------|------|
| **429 応答の経路別契約** (フォーム内エラー / エラー画面 / API 形式) | AG-097 で `error-response-contract` へ切り出し済み。射程が流量制限より広く、ここに含めると他の用途から見えなくなる |
| **信頼するプロキシの設定と本番 fail-fast** | AG-097 で `trusted-proxy-hardening` へ切り出し済み。aicue では T108 で `ProductionEnvGuard` として実装済みであり、重ねて触らない |
| **秘密を返す GET の保護** (`two-factor.qr-code` / `secret-key` / `recovery-codes`) | `config/fortify.php:165-168` に「step-up なしで到達可能」の TODO(template) が既にあり、**recent-auth 化と一体で設計すべき別課題**。throttle だけ貼ると「対処済み」に見えて本質 (step-up 不足) が隠れる。後続 TODO 候補 |
| **`POST /livewire-*/update` の専用 throttle** | 防御は Filament component 内に既にある。route 単位で貼ると管理画面の全操作が同一バケットに落ちる。exemption 登録に留め、契約の明文化は後続 TODO |
| **DCR 後付け (`routes/ai.php`) と `PasskeyServiceProvider` 後付けの helper への統合** | 両者は既に fail-fast 済みで動作しており、触る必要が無い (思考原則 2)。ただし「新しい後付けは helper を通す」を規約として残す。統合は後続 TODO |
| **全体天井の webhook 以外への適用** | 適用条件の判定 (増幅の有無 × 止まったときの業務影響) を口ごとにやる必要があり、本タスクの射程を超える。後続 TODO |
| **閾値の見直し・チューニング** | AG-096 で「閾値はプロダクト依存」と裁定。既存値は 1 つも変えない |
| **フロント側の 429 ハンドリング (待ち時間表示・戻り先解決)** | aigenba の `resources/js/lib/rate-limit/*` に相当するもの。`error-response-contract` の射程 |
| **`storage.local.upload` の throttle** | local disk driver 専用経路 (production は S3 presigned)。exemption 登録に留める |

---

## 7. 期待効果 (使命への貢献)

- AI-CUE の使命は「現場作業者が専門知識ゼロで標準化されたマニュアル動画を作れる」こと。
  流量制限は使命そのものを前に進める機能ではないが、
  **アカウント乗っ取り 1 件で現場の SOP と手順動画がまるごと漏れる**以上、
  認証面の総当り防御は事業の前提条件である。
- 直接効果:
  - 未認証で到達できる credential 面 3 本と webhook 2 本の無防備が解消される
  - 「throttle を貼り忘れた route」が **CI で赤くなる** (家系で最初の実装)
  - 巻き添えロックアウト (`Str::transliterate` による Unicode email の collapse) が解消される
- 家系への還流: 目録検査は 5 リポジトリのいずれにも無く、テンプレートへ還流できる資産になる。

---

## 8. 検証方法

| # | 検証 | 期待結果 |
|---|------|---------|
| 1 | `ThrottleCoverageInventoryTest` を実装前の main で走らせる | **fail** し、本書 §2-3 の付与漏れが列挙される (テストファースト: 先に赤を見る) |
| 2 | 同テストを是正後に走らせる | green。exemption inventory の件数 = §2-3 の「塞がない」判断分だけ |
| 3 | 任意の route から `throttle:` を 1 本外す | 目録検査が fail する (検査が実効している証明) |
| 4 | 任意の route に `throttle:` を二重付与する | 目録検査が fail する (冪等性の証明) |
| 5 | `RouteThrottleBinder::attachByName()` に存在しない route 名を渡す | 起動時に `RuntimeException` (fail-fast の証明) |
| 6 | `RateLimiterKeyConventionTest` | 全 limiter のキーが `{レーン}:{種別}:{値}` に一致 |
| 7 | 既存 `NamedRateLimiterKeyTest` | 引き続き green (キー変更が route parameter 混入を招いていない) |
| 8 | 既存 `PasskeyRouteProtectionTest` | 引き続き green (middleware 列の完全一致を壊していない) |
| 9 | Feature テスト: `POST /forgot-password` を上限+1 回叩く | 429。ヘッダは Laravel 既定のまま (`Retry-After` / `X-RateLimit-*` を削らない・書き換えない) |
| 10 | Feature テスト: 同一 IP で email だけ変えて `POST /forgot-password` | IP 単独レーンが先に効く (メール爆撃の抑制) |
| 11 | Feature テスト: 大文字小文字違いの email で `POST /forgot-password` | 同一バケットを消費する (正規化の証明) |
| 12 | `composer phpstan` / `vendor/bin/pint --test` | green |

---

## 9. リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| キー文字列の変更でデプロイ時に既存バケットがリセットされる | ロックアウト中の攻撃者の枠が 1 回だけ戻る | 一過性。閾値は不変なので恒久的な劣化は無い |
| webhook への throttle 付与で正当な通知が 429 になる | 課金同期・バウンス処理の遅延 | SNS も Stripe も 429 は再送対象。閾値は正常時ピークの 1〜2 桁上。恒久喪失しない (Stripe は最大 3 日間の指数バックオフ) |
| `POST /register` への throttle で正当な同時登録が弾かれる | 展示会等での一斉登録 | IP 単独 5/min は同一 NAT 配下で当たりうる。IP-email の 60 分レーンを併用し、IP 単独は `inquiry` (既に本番稼働中の同性質エンドポイント) と同値に留める |
| 目録検査の母集団セレクタが広すぎ / 狭すぎ | 形骸化 (広すぎ) / すり抜け (狭すぎ) | floor (母集団件数の下限) を置いて空振りを検出。exemption 件数が増えたら設計を見直す合図とする |
| Fortify / Cashier の route 名が upgrade で変わる | 後付けが外れて無防備になる | `RouteThrottleBinder` が起動時 `RuntimeException` で落とす (silent degradation を作らない) |
