# Round 3: Round 2 指摘への対応

Round 2 の指摘に対する対応マトリクスと、**改訂後の概念設計の全文**です。
改訂本文を実読したうえで再判定してください (未読のまま APPROVED としないでください)。

# 対応マトリクス: conceptual-review Round 2

## [Critical] `route:cache` と `RouteThrottleBinder` の自己矛盾 (観点 3)
- 判断: **対応する (全面的に受け入れ)**
- 根拠: 指摘のとおり。`RouteCacheCommand` は `getFreshApplicationRoutes()` で
  **アプリを再 bootstrap してから** `router->getRoutes()` を直列化する。
  provider の boot → `booted()` callback も走るため、**binder が付けた throttle が cache に焼き込まれる**。
  その cache を読んだ次回起動でも `booted()` は走るので、
  「既存 throttle があれば常に例外」だと **cached 起動が必ず落ちる**。設計の欠陥だった。
- 対応内容: `attachByName()` を**真の冪等**に作り直す。実効 throttle entry を数え、
  - 0 本 → 付与する
  - ちょうど 1 本 かつ `ThrottleRequests::class.':'.$limiter` と**完全一致** → **no-op**
  - それ以外 (別 limiter / 2 本以上) → `RuntimeException`
  検証は「uncached 起動 / cache 生成 / cached 起動」の 3 状態を個別にテストする。

## [Warning] `gatherRouteMiddleware()` の entry はパラメータ付き文字列 (観点 3)
- 判断: **対応する**
- 根拠: 実効列の entry は `Illuminate\Routing\Middleware\ThrottleRequests:login` のように
  `{class}:{params}` 形式で出る。class 名には `:` を含まないため
  `Str::before($entry, ':')` で class 部を切り出せる (Laravel の `Pipeline::parsePipeString` と同じ分割)。
- 対応内容: 判定述語を「`Str::before($entry, ':')` に対して
  `is_a($class, ThrottleRequests::class, true)`」に修正し、目録検査・binder の両方で共有する。

## [Warning] limiter closure を 1 回実行するだけでは分岐を網羅できない (観点 5)
- 判断: **対応する**
- 根拠: 正しい。`passkeys` / `two-factor` / `api-*` は「認証済み / 未認証」で別のキーを返すため、
  片方だけ評価すると規約違反の分岐を見逃す。
- 対応内容: inventory を
  `array{scenarios: array<string, callable(): Request>, expectedKeyPrefixes: list<string>}` にする。
  検査は 2 段:
  1. 全 scenario の全 `Limit::$key` が規約 regex に一致する
  2. **produce された `{レーン}:{種別}` の集合が `expectedKeyPrefixes` と完全一致**する
     (未宣言の分岐が出れば fail = 新しい分岐の見逃し防止。
      宣言したのに produce されない prefix があっても fail = 死んだ scenario の検出)

## [Warning] 正規表現走査では非リテラル / 空白・改行の登録がすり抜ける (観点 8)
- 判断: **対応する**
- 根拠: 正しい。`RateLimiter::for(\n    'name',` や `RateLimiter::for(self::NAME, …)` を
  素の正規表現は取りこぼし、しかも「集合一致」は成功してしまう (最悪の壊れ方)。
- 対応内容: 走査を `token_get_all()` ベースの scanner
  (`tests/Support/RateLimiterRegistrationScanner`) に変更し、
  - `app/` 配下の**全** `RateLimiter::for(...)` 呼び出しを数える
  - 第 1 引数が `T_CONSTANT_ENCAPSED_STRING` でない呼び出しが 1 件でもあれば **fail**
    (「解析できなかった」を沈黙させない)
  - 「検出した名前の集合が inventory と一致」に加えて「**全呼び出しを分類できた**」ことを検証する
  scanner 自体の positive/negative は `tests/Unit/Architecture/` に単体テストを置く
  (`AuthorizationMarkerScannerTest` と同じ流儀)。

## [Warning] webhook の IP 単位制限に対する断定が強すぎる (観点 2)
- 判断: **対応する (表現の修正 + 監視項目の追加)**
- 対応内容: 「攻撃者が正当送信元のバケットを消費させることはできない」→
  「通常のネットワーク条件では第三者が同一バケットを選びにくい。
  ただし共有クラウド出口・送信元構成の変更・proxy 設定の誤りでは巻き添えがありうる」に修正し、
  **送信元 IP の分布と 429 発生率**を監視項目に加える。

## [Suggestion] exemption cap を §9 リスク表にも明記 (観点 8)
- 判断: **対応する**

## [Suggestion] テスト側の型を `array<int, Limit>` まで明示 (観点 7)
- 判断: **対応する** (詳細設計のシグネチャで明示する)


---

## 改訂後の概念設計 (全文)

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

> 注: `route:list --json` は**実査 (本節) 専用**である。group 名 (`'web'`) や alias が
> 展開されない形で出るため、**テストの入力には使わない** (§4-A で述べるとおり
> Architecture テストは Router から解決済み middleware class 列を得る)。

**未認証で本体に到達しうる変更系 (POST/PUT/PATCH/DELETE) のうち throttle 無し**:

| route | 危険性 |
|-------|--------|
| `POST /forgot-password` (`password.email`) | **メール送信の増幅口 + アカウント列挙**。Fortify は `limiters` に reset 系を持たず、標準では無制限 |
| `POST /reset-password` (`password.update`) | **reset token の総当り** |
| `POST /register` (`register.store`) | **アカウント量産 + 確認メール送信の増幅** |
| `POST /ses/notification` (`webhooks.ses`) | 署名検証 (`VerifySnsSignature`) は証明書取得を伴う。**署名検証コスト自体が増幅対象** |
| `POST /stripe/webhook` (`cashier.webhook`) | middleware が **1 本も無い** |
| `PUT /storage/{path}` (`storage.local.upload`) | **production でも route は登録される** (`config/filesystems.php:36` が local disk に `'serve' => true`)。ただし `Illuminate\Filesystem\ReceiveFile::__invoke()` が本体到達前に `abort_unless($request->boolean('upload') && $request->hasValidRelativeSignature(), production ? 404 : 403)` を実行する |
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

**実効 middleware の取得元**: `Illuminate\Support\Facades\Route::getRoutes()` +
`Route::getFacadeRoot()->gatherRouteMiddleware($route)`。
group 名・alias を Laravel 自身に展開させた **class 名列**で判定する
(`route:list --json` は group 名がそのまま出るためテストには使わない)。

**throttle の判定述語** (目録検査と binder で共有する):
`gatherRouteMiddleware()` の entry は `Illuminate\Routing\Middleware\ThrottleRequests:login` のように
`{class}:{params}` 形式で出る。class 名に `:` は含まれないため `Str::before($entry, ':')` で class 部を切り出し
(Laravel の `Pipeline::parsePipeString` と同じ分割)、`is_a($class, ThrottleRequests::class, true)` で判定する。
`ThrottleRequestsWithRedis extends ThrottleRequests` (実査確認済み) なので、
cache driver を Redis に切り替えても 1 つの述語で拾える (文字列 `throttle:` の照合はしない)。

**合格条件**: 実効 middleware 列に throttle middleware が **ちょうど 1 本**
(0 本 = 付与漏れ / 2 本以上 = 二重付与で実効上限半減。両方を同じ検査で殺す)。

**不合格の逃げ道**: `App\Enums\Security\ThrottleCoverageExemption` + 30 文字以上の具体的理由を
inventory に登録する。理由が書けないものは「throttle を足すべき route」である。

**形骸化の抑止**: 母集団件数の **floor** (空振り検出) に加えて、
exemption 件数の **cap** を置く。cap を超える登録はテストの定数を上げる意図的な行為を要求する。

### 方針 B: 検出された穴を塞ぐ (閾値は既存値の踏襲を最優先)

| route | 施策 | 閾値の根拠 |
|-------|------|-----------|
| `POST /forgot-password` | 新 limiter `password-reset-request` | `inquiry` と同性質 (未認証 + メール送信を伴う公開 POST) → **既存 `inquiry` の値をそのまま踏襲** (5/min + 10/60min) |
| `POST /reset-password` | 新 limiter `password-reset-submit` | `login` と同格の credential 総当り面 → **既存 `login` の 5/min を踏襲** + IP-email の 10/60min |
| `POST /register` | 新 limiter `account-register` | 同上 (`oauth-register` と紛らわしいので `account-register`) |
| `POST /ses/notification` | 新 limiter `webhook-ses` (**IP 単位のみ**) | 正常時ピークは分あたり数件。単一送信元からの署名検証コスト増幅を有界にする値として 300/min |
| `POST /stripe/webhook` | 新 limiter `webhook-stripe` (**IP 単位のみ**) | 同上。**レーンを分ける** (SES への攻撃で Stripe を止めない) |
| `POST /user/confirm-password` | 既存汎用 `throttle:6,1` | `recent-auth.password` / `settings.password.store` と同一値・同一性質 |
| `PUT /user/password` | 既存汎用 `throttle:6,1` | 同上 (current_password 必須 = 総当り面) |
| `POST /invitations/accept` | 既存汎用 `throttle:10,1` | `onboarding.activate-personal` と同値 |
| 2FA 管理 4 本 | 既存汎用 `throttle:10,1` | 同上 |

**新規閾値の発明は webhook 2 本だけ**。他はすべて既存値の踏襲。

> **固定キーの全体天井 (標準形 (3)) は本タスクでは採らない**。
> throttle middleware は署名検証**より前**に走るため、固定キーのバケットを middleware で消費させると
> 「無効 body の連打で正当な Stripe / SES 通知を 429 にできる」= **攻撃者が任意に止められる**口になり、
> 標準形 (3) の適用条件そのものを満たさない。
> 全体天井を採るなら「**署名検証成功後にだけ消費される位置**」(Controller / Service 層) での設計が要る。
> これは後続 TODO 候補 (§6-2)。

### 方針 C: 後付け配線の単一化 (標準形の第 2 段を helper 化)

`app/Support/Http/RouteThrottleBinder.php` を新設する。

- `attachByName(string $routeName, string $throttle): void`
  route 名で引き、**引けなければ `RuntimeException` で起動を止める** (fail-fast)。
- **真の冪等**にする。実効 throttle entry を数えて:
  - **0 本** → 付与する
  - **ちょうど 1 本かつ `ThrottleRequests::class.':'.$limiter` と完全一致** → **no-op**
  - **それ以外** (別 limiter / 2 本以上) → `RuntimeException`

  「既存があれば常に例外」にしてはならない。`php artisan route:cache` の
  `RouteCacheCommand::getFreshApplicationRoutes()` は**アプリを再 bootstrap してから**
  `router->getRoutes()` を直列化するため、binder が付けた throttle が **cache に焼き込まれる**。
  その cache を読んだ次回起動でも `booted()` は走るので、常に例外だと
  **cached 起動が必ず落ちる** (Codex Round 2 Critical)。

**呼び出し位置は `AppServiceProvider::boot()` 内の `$this->app->booted(...)` に一本化する**。

- vendor provider の route 登録が済み、`route:cache` からの `RouteCollection` 読み込みも済んだ後に
  走ることが確定する位置である。
- **routes ファイル側では新たな後付けをしない**。`php artisan route:cache` を使うと
  `routes/*.php` は実行されないため、routes ファイルに後付けを書くと
  「cache 生成時に焼き込まれるが、その後の起動では fail-fast が走らない」という
  cache 有無で挙動が変わる場所を作ってしまう。

**本 feature で新たに追加する後付けだけがこの helper を通る**。
既存の DCR 後付け (`routes/ai.php:47-72`) と `PasskeyServiceProvider` の後付けは
既に fail-fast 済みで動作しているため**今回は触らない** (§6-2 参照)。

### 方針 D: キー規約 `{レーン}:{種別}:{値}` への統一 + 機械検査

- 全 15 本 (既存 10 + 新規 5) のキーを規約形に統一する。**閾値は 1 つも変えない**。
- `login`: `Str::transliterate` を廃止 → `EmailNormalizer::normalize()` + `EmailHash::compute()`。
  キーは `login:email-ip:{hmac}:{ip}`。
  → 巻き添えロックアウトの解消 + キャッシュから email 由来の低エントロピー値を排除。
- `inquiry`: `hash('sha256', …)` → `EmailHash::compute()` (鍵付き)。
- `tests/Architecture/RateLimiterKeyConventionTest.php` を新設する。
  limiter 本体は `app(\Illuminate\Cache\RateLimiter::class)->limiter($name)`
  (public・`?Closure` 返り) で取得し、`Limit|array<int, Limit>` の絞り込みは
  `Webmozart\Assert\Assert` で行う (PHPStan level 10 対応。**Reflection は 1 箇所も使わない**)。

- **closure は 1 回だけ実行しない**。`passkeys` / `two-factor` / `api-*` は
  「認証済み / 未認証」で別のキーを返すため、片方だけ評価すると規約違反の分岐を見逃す。
  inventory を
  `array{scenarios: array<string, callable(): Request>, expectedKeyPrefixes: list<string>}` にして、
  1. **全 scenario の全 `Limit::$key`** が `^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*:` に一致する
  2. produce された `{レーン}:{種別}` の集合が `expectedKeyPrefixes` と**完全一致**する
     (未宣言の分岐が出れば fail = 新分岐の見逃し防止。
      宣言したのに produce されない prefix があっても fail = 死んだ scenario の検出)

- **limiter 名の列挙は token ベースの scanner で行う** (正規表現ではない)。
  `tests/Support/RateLimiterRegistrationScanner` を新設し、`token_get_all()` で
  `app/` 配下の**全** `RateLimiter::for(...)` 呼び出しを検出する。
  第 1 引数が `T_CONSTANT_ENCAPSED_STRING` でない呼び出しが 1 件でもあれば **fail**
  (「解析できなかった」を沈黙させない)。
  検査は「検出した名前の集合が inventory と一致」+「**全呼び出しを分類できた**」の 2 本立てにする。
  素の正規表現だと `RateLimiter::for(\n    'name',` や `RateLimiter::for(self::NAME, …)` を取りこぼしつつ
  集合一致は成功してしまう (最悪の壊れ方)。
  scanner 自体の positive/negative は `tests/Unit/Architecture/` に単体テストを置く
  (`tests/Support/AuthorizationMarkerScanner` + `AuthorizationMarkerScannerTest` と同じ流儀)。

---

## 5. 代替案と却下理由

| 代替案 | 却下理由 |
|--------|---------|
| aigenba 方式の `PathBasedThrottle` middleware (URL パス表) を移植する | AG-096 で置換対象と裁定済み。aicue は全レーンが標準の名前付き route で引けるため、第 2 の経路指定を持ち込む理由が無い |
| 母集団を「全 route (206 本)」にする | 未分類が 175 本になり、exemption inventory が形骸化する。静的アセット・SEO ファイル・認証済み業務 route まで巻き込むのは過大 |
| 母集団を「認証系 route 名の列挙」にする | 新しい認証 route を足したときに列挙漏れで検査がすり抜ける。**目録検査の意味が半減する** |
| 検出された穴をすべて exemption に登録して塞がない | exemption enum は「持たないことが**正しい**と裁定した理由」の分類。塞ぐべき穴を exemption にすると台帳に嘘を書くことになる |
| 全体天井 (固定キー Limit) を認証系にも被せる | 標準形 (3) の適用条件は「増幅があり、**かつ止まっても中核の業務が止まらない**口」。ログイン面に被せると上限到達の瞬間に全員がログインできなくなり、攻撃者の目的そのものになる |
| 全体天井を webhook に middleware として被せる | throttle middleware は**署名検証より前**に走る。固定キーのバケットを署名前に消費させると、無効 body の連打で正当な Stripe / SES 通知を 429 にでき、**攻撃者が任意に業務を止められる**。適用条件を満たさないため本タスクでは採らない (後続 TODO で「署名検証成功後にだけ消費する」設計として扱う) |
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
| **固定キーの全体天井 (標準形 (3)) そのもの** | middleware 位置では署名検証前に消費され、攻撃者に「正当通知を止める手段」を与える (Codex Round 1 Critical)。採るなら **署名検証成功後にだけ消費される位置** (Controller / Service 層) での設計が必要で、適用条件の判定 (増幅の有無 × 止まったときの業務影響) も口ごとに要る。後続 TODO |
| **閾値の見直し・チューニング** | AG-096 で「閾値はプロダクト依存」と裁定。既存値は 1 つも変えない |
| **フロント側の 429 ハンドリング (待ち時間表示・戻り先解決)** | aigenba の `resources/js/lib/rate-limit/*` に相当するもの。`error-response-contract` の射程 |
| **`storage.local.upload` の throttle** | `config/filesystems.php:36` の `'serve' => true` により **production でも route は登録される**が、`ReceiveFile::__invoke()` が本体到達前に「`upload=1` かつ有効な relative signature (`app.key` HMAC)」を要求し、不成立なら production では 404 で短絡する。exemption 登録に留め、**その前提自体を Feature テストで固定する** (vendor の変更で前提が崩れたら赤くなる) |

---

## 7. 期待効果 (使命への貢献)

- AI-CUE の使命は「現場作業者が専門知識ゼロで標準化されたマニュアル動画を作れる」こと。
  流量制限は使命そのものを前に進める機能ではない。位置づけは
  「**現場の SOP と手順動画という顧客資産を預かる基盤の前提条件**」である
  (アカウント乗っ取り 1 件で SOP と手順動画がまるごと漏れる)。
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
| 5b | **uncached 起動 / `route:cache` 生成 / cached 起動**の 3 状態で 1・4・5 を確認 | 3 状態すべてで挙動が同一。cached 起動で binder が `RuntimeException` を投げない (冪等 no-op) |
| 5c | 署名なしの `PUT /storage/{path}` | 403 (non-production) / 404 (production)。exemption の前提が実効していることの証明 |
| 6 | `RateLimiterKeyConventionTest` | 全 limiter の**全分岐**のキーが `{レーン}:{種別}:{値}` に一致し、produce された prefix 集合が宣言と完全一致 |
| 6b | `RateLimiter::for($name, …)` を非リテラル引数で 1 件足す | scanner が「解析不能」で fail する |
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
| webhook への throttle 付与で正当な通知が 429 になる | 課金同期・バウンス処理の遅延 | **IP 単位のみ**にしたため、通常のネットワーク条件では第三者が正当送信元 (AWS SNS / Stripe) と同一バケットを選びにくい。ただし共有クラウド出口・送信元構成の変更・proxy 設定の誤りでは巻き添えがありうる。**監視項目**: 送信元 IP の分布 / 429 発生率。SNS も Stripe も 429 は再送対象で恒久喪失しない (Stripe は最大 3 日間の指数バックオフ) |
| exemption inventory が肥大化して形骸化する | 目録検査が「全部 exemption」になり無意味化 | 母集団の floor に加えて **exemption 件数の cap** を置く。cap 超過はテスト定数の引き上げ = 意図的な行為を要求する |
| `POST /register` / `POST /forgot-password` への throttle で正当な同時登録が弾かれる | 展示会・現場 Wi-Fi 等の同一 NAT 配下での巻き添え | IP 単独 5/min は同一 NAT 配下で当たりうる。IP-email の 60 分レーンを併用し、IP 単独は `inquiry` (既に本番稼働中の同性質エンドポイント) と同値に留める。**観測すべきメトリクス**: 429 発生率 / 同一 IP 配下で観測される別 email の件数。**UX 救済** (入力を失わせないフォーム内エラー) は `error-response-contract` feature 側の射程 |
| 目録検査の母集団セレクタが広すぎ / 狭すぎ | 形骸化 (広すぎ) / すり抜け (狭すぎ) | floor (母集団件数の下限) を置いて空振りを検出。exemption 件数が増えたら設計を見直す合図とする |
| Fortify / Cashier の route 名が upgrade で変わる | 後付けが外れて無防備になる | `RouteThrottleBinder` が起動時 `RuntimeException` で落とす (silent degradation を作らない) |

