# 概念設計: bughunt-sso-egress (bug-hunt の SSO 外部遷移を塞ぐ)

> 一次入力: `devnotes/20260811-1736-bughunt-sso-egress/recon-brief.md`
> 先行設計: `devnotes/20260809-0027-external-seam-funnel/` (aicue:T138)。本 TODO は
> T138 が「既存テストへの波及が大きい」として先送りした側を引き受ける。

## 背景・課題

`config/testing.php` L16-17 と `app/Providers/FakeExternalsServiceProvider.php` の docblock、
`docs/architecture.md` §外部到達点の目録、`AGENTS.md` ドメイン規約 9 が揃って
**「SSO (Socialite) は fake しない」**と明記している。帰結として:

1. bug-hunt (`APP_ENV=bughunt.local`) のブラウザが SSO ボタンを押すと
   `Socialite::driver('google')->redirect()` が返す **実 IdP (`accounts.google.com`) の URL** へ遷移する。
2. bug-hunt スキルの**禁止事項 4** は「許可する実外部接続は LLM プロバイダ API ドメインのみ。
   決済 / Captcha / **SSO** / mail / S3 等は fake / 外部通信なし」と定めている
   (`.claude/skills/app-bug-hunt/SKILL.md` L72-76 および L194 の環境表)。
   **つまりスキル正本の記述が現状すでに嘘である**。
3. 実運用でも歪みが出ている。run `20260811-003230` の shard 4 では
   「実 IdP ドメインへの遷移を検知したら即中断して報告」と探索エージェントに指示して回避していた
   (= 探索の網を人手で狭めていた)。
4. `PLAYWRIGHT_MCP_ALLOWED_ORIGINS` が自シャードのポートに限っているので実際の遷移は
   **ブラウザ側の allowlist** が止めている。アプリ側は塞いでいない。この二重性が
   「どちらが本当の保証か」を曖昧にしている。

T130 で入れたテストレーンの HTTP 出口既定拒否 (`StrayHttpRequestGuard`) は
**Socialite (Guzzle 直) にも bug-hunt の別プロセスにも効かない**ことを AGENTS.md 自身が明記している。
既存機構では塞がらない。

## 仮説

**Socialite の driver 解決点を 1 つの自前クラスへ切り出せば、既存の captcha fake と
まったく同じ形 (capability flag + env allowlist + container bind) で SSO を fake でき、
かつ既存テストへの波及は 0 行になる。**

成功条件:
- flag on + allowlist 環境で `social.redirect` の遷移先 host が**自アプリの host** になる。
- flag off (= 自動テストレーン既定・本番) では現行と 1 bit も変わらない。
- bug-hunt の 1 provision 内で、`link`→`step-up`→`login` の連鎖 **または**
  `register`→`step-up`→`login` の連鎖のどちらかが最後まで踏める
  (両方の同時成立は求めない。理由は「固定 identity と bug-hunt の共有 DB」節)。
- `SocialAuthTest` / `RecentAuthTest` / `RecentAuthMethodStampingTest` /
  `SecurityAuditTrailCoverageTest` / `AuthThrottleCoverageTest` / `ThrottleExemptionPremiseTest` を
  **1 行も変更しない**。

## 改善アイデア

### 採用案: driver 解決点の切り出し + 既存 fake 配線への相乗り

1. `App\Services\Auth\SocialiteDriverResolver` (新規・薄い) を作り、
   `Socialite::driver($provider)` の呼び出しをここ 1 箇所に集める。
   `SocialAuthController` は facade ではなく本クラスを constructor 注入して使う。
2. `App\Services\Auth\Fakes\FakeSocialiteDriverResolver` (`SocialiteDriverResolver` を継承) を作り、
   `App\Services\Auth\Fakes\FakeSocialiteProvider` (`Laravel\Socialite\Contracts\Provider` 実装) を返す。
   - `redirect()` → **自アプリの `route('social.callback', ['provider' => …])` へ 302**。
   - `user()` → provider 名から決定論的に導出した canned な `Laravel\Socialite\Two\User`。
3. `FakeExternalsServiceProvider` に SSO 専用の早期 return ブロックを 1 つ足し、
   `bind(SocialiteDriverResolver::class, FakeSocialiteDriverResolver::class)` する。
   `ExternalFakeWiringInventory::bindings()` に `ExternalFakeBinding` を 1 本足す。
   **capability flag は既存の `testing.fake_externals` を再利用**する (新 flag を作らない) が、
   **env allowlist は `['testing', 'bughunt.local']`** とし **`local` を除外**する
   (理由は下記「なぜ SSO だけ `local` を外すのか」。storage fake と同じ 2 環境で、
   `ExternalFakeBinding::$allowedEnvironments` は元から entry ごとに宣言できる = 独自形ではない)。
4. 嘘になる記述を同一 PR で直す (下記「同時に直す文書」)。
5. `scripts/bug-hunt-shard.sh` provision の**実効 env 検証**に `fake_externals` の期待値を足し、
   bughunt 環境で fake が外れていたら provision 時点で fail-fast させる。

### なぜ `Laravel\Socialite\Contracts\Factory` に直接 bind しないのか (最重要)

`vendor/laravel/socialite/src/SocialiteServiceProvider.php` は **`DeferrableProvider`** であり、
`provides()` が `[Factory::class]` を返す。`Container::bind()` は `deferredServices` を消さないため:

1. `FakeExternalsServiceProvider::register()` で `bind(Factory::class, Fake…)` する
2. 最初の `app(Factory::class)` で `Application::loadDeferredProviderIfNeeded()` が
   `isDeferredService(Factory::class) === true` かつ `instances[Factory::class]` 未設定を見て
   `SocialiteServiceProvider` を読み込む
3. その `register()` が `singleton(Factory::class, fn () => new SocialiteManager($app))` を**後勝ちで**張る
4. **fake は無言で消え、実 IdP へ戻る**

「無言で real に戻る」は本目録が最も嫌う失敗形 (captcha entry の risk 文と同じ構図) であり、
これを避けるには `instance()` か `registerDeferredProvider()` が要る。しかし
`FakeWiringSourceScanner::ALLOWED_APP_CALLS` は provider 内の container 呼び出しを
`bind` / `make` / `environment` の 3 形に閉じており、**gate の文法を広げる**ことになる
(その gate の docblock は「allowlist を広げる方向へ倒さない」と明記している)。
**自前の具象クラスを container の差し替えキーにすれば** (= PHP の `abstract class` ではなく、
`bind($abstract, $concrete)` の第 1 引数として自前クラスを使う)、
**gate の文法にも既存 fake の形にも一切触らずに済む**。
`RecaptchaVerifier` (具象 real) → `RecaptchaVerifierTestFake` (サブクラス fake) と同一形である。

なお `ExternalSeamInventory::socialLoginFunnel()` の docblock は
「集約先を別クラスへ切り出さないのは、**差し替え先 (SSO fake) を今作らないため**」と書いており、
T138 自身が「fake を作るなら切り出す」を想定していた。本案はその想定どおりの続きである。

### なぜ SSO だけ `local` を外すのか

SSO fake は Stripe / captcha fake と**危険度の質が違う**。fake が立つと
`GET /auth/google/redirect/login` → `GET /auth/google/callback` の **未認証 GET 2 本で
canned アカウントへログインできる = 認証バイパス**になる (Stripe fake は課金が起きない、
captcha fake は bot 防御が緩む、で止まる)。加えて `local` は開発者が**実 IdP 連携を
確認する唯一の環境**であり、そこで無言に fake が立つと本番 SSO の回帰を見逃す。

したがって SSO の allowlist は `['testing', 'bughunt.local']` (storage fake と同一) とする。
除外時に **warning ログは出さない** — LLM fake と同じく「誤設定ではなく設計上の除外」であり、
かつ既存の `3-4` テストが `Log::warning` の回数を `once()` で固定しているため、
ここで warning を足すと既存テストを壊す (波及 0 行の前提が崩れる)。

### fake identity の決定規則 (アプリが読む値をすべて埋める)

アプリが socialite user から読むのは実測で 3 つだけ:
`getId()` (`SocialAccountService::findLinkedUser` / `link` / `SocialAuthController::completeStepUp`)、
`getEmail()` / `getName()` (`SocialAccountService::register`)。
`EmailTrustPolicy` (Confirmed / Unconfirmed) は socialite user から値を読まない。

fake は vendor の `Laravel\Socialite\Two\User` を `map()` で組み立てる (自前の
`Contracts\User` 実装を書かない = 先人の知恵)。値は provider 名から決定論的に導出し、
**一目で fake と分かる**ものにする:

| フィールド | 値 |
|---|---|
| `id` | `fake-{provider}-user` |
| `nickname` | `fake-{provider}` |
| `name` | `SSO Fake User ({provider})` |
| `email` | `fake-{provider}-sso@example.com` |
| `avatar` | `null` |

決定論にするのは、`register` で作った fake ユーザーへ次の run の `login` / `link` /
`step-up` が同じ identity で戻れるようにするため (round-trip が探索可能になる)。

### 固定 identity と bug-hunt の共有 DB — どの intent がどこまで探索できるか

**前提**: `scripts/bug-hunt-shard.sh provision` は毎回 `migrate:fresh --seed` を実行し、
その後 `ManualTestSeeder` / `BughuntBillingSeeder` / `AdminUserSeeder` / `BughuntOAuthSeeder` を流す。
**どの seeder も `social_accounts` に行を作らない**。したがって run 開始時点で
`fake-{provider}-user` は**どのユーザーにも紐づいていない**。

この初期状態からの到達可能性 (実コードの分岐で確認):

| intent | 成功経路 | 到達条件 |
|---|---|---|
| `link` | 現在のユーザーに連携が付く | **`fake-{provider}-user` が未連携**であること (先着 1 回) |
| `register` | 新規 User + 個人組織が作られる | **`fake-{provider}-user` が未連携**であること (先着 1 回) |
| `login` | 連携済みユーザーとしてログイン | 誰かが `link` または `register` を済ませていること |
| `step-up` | recent-auth の鮮度が stamp される | 現在のユーザーが `fake-{provider}-user` を連携済みであること |

**`link` の成功と `register` の新規作成成功は排他**である (どちらかが先着 1 回で
「未連携」状態を消費する)。2 回目以降は競合経路
(`register` → 連携済みユーザーへのログイン扱い / `link` → 「既に別のユーザーに連携されています」)
へ落ちるが、これは**アプリの正当な分岐であり探索価値がある** (詰みではない)。

- 排他は **run ごとにリセットされる** (provision が `migrate:fresh --seed` するため)。
  `--parallel` では shard ごとに独立 DB なので、shard 間で違う枝を踏める。
- 探索中に状態を戻したければ既存の子 wrapper `tmp/bug-hunt/shard-{i}-cmd.sh reseed` がある。
- **identity をリクエストパラメータで選ばせる案は採らない** (認証バイパス面を広げる)。
- **seeder で事前に連携を張る案も採らない**。事前連携は `link` / `register` の成功経路を
  逆に潰すため、探索能力が下がる (思考原則 2: 今必要ないものを作らない)。

この制限は「保証しないもの」ではなく**仕様として**設計に残す。
Feature テストが示すのは各 intent の round-trip 成立であって、
「1 回の provision 内で 4 intent の成功経路が同時に成立すること」ではない
(そこは上表の排他があるため主張しない)。

### OAuth `state` を持たないことについて

実コードを確認したところ、`SocialAuthController` が session に置くのは
`social_auth_intent` **だけ**で、OAuth の `state` は Socialite 内部 (`AbstractProvider`) の
責務であり controller / `SocialAccountService` は一切参照しない。
したがって fake が `state` を積まなくても**アプリ層の契約は 1 つも飛ばさない**。
この主張は「redirect 先 host」だけでなく **`social.redirect` → `social.callback` の
full round-trip を 4 intent (register / login / link / step-up) で通す Feature テスト**で示す。

### 採らない案と理由

| 案 | 却下理由 |
|---|---|
| (b) bug-hunt レーンだけ SSO ボタンを出さない | 探索の網を自分で狭める。route は残るので「アプリ側が塞いだ」と言えない。本番と違う UI を bug-hunt に見せると UX 検証としての価値が落ちる |
| (c') IdP 風の中間スタブ画面を自アプリに作る | 新 route + controller + ページが要るのに、**我々が所有する UX は 1 つも増えない** (IdP の同意画面はアプリの UX ではない)。思考原則 2 に反する。fake の `redirect()` を直接 callback へ向ければ同じ効果が 0 route で得られる |
| vendor の `Socialite::fake()` (`Laravel\Socialite\Testing\SocialiteFake`) をそのまま使う | `FakeProvider::redirect()` の戻り先が `https://socialite.fake/...` という**解決不能な外部 URL**。ブラウザは DNS エラー画面で詰み、round-trip が完成しない。ただし `Two\User` は再利用する (先人の知恵) |
| SSO 用の新しい capability flag を切る | 系統が増えるだけ。captcha と同じ「外部サービス fake」系統であり、分離する logic が無い |

## 期待効果

- **使命への貢献 (間接 / 探索基盤の信頼性)**: bug-hunt は「現場作業者が詰まないか」を実走で確かめる装置である。
  SSO 経路が探索不能だったため、SSO 登録 → 個人組織 provisioning → オンボーディング着地、
  SSO 連携 → recent-auth step-up satisfier という**認証系の詰み**を一度も実走できていなかった。
  本改善で初めてこの経路が探索対象になる (塞ぐだけでなく**探索能力が増える**)。
  ただし 1 provision 内で踏めるのは `link` 系列か `register` 系列の**どちらか一方**である
  (上表の排他。誇張しない)。
- 規約の嘘が消える。bug-hunt スキルの禁止事項 4 と環境表が現実と一致する。
- 「ブラウザ側 allowlist が守っている」から「アプリ側が塞いでいる」へ、保証の所在が 1 本化される
  (allowlist は多層防御として残す)。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `app/Services/Auth/SocialiteDriverResolver.php` | 新規。`driver(string $provider): Provider` のみ |
| `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php` | 新規。resolver を継承し fake provider を返す |
| `app/Services/Auth/Fakes/FakeSocialiteProvider.php` | 新規。`redirect()` は自アプリ callback へ、`user()` は canned |
| `app/Http/Controllers/Auth/SocialAuthController.php` | facade → resolver 注入へ (2 箇所) |
| `app/Providers/FakeExternalsServiceProvider.php` | bind 1 行 + docblock 修正 |
| `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php` | `ExternalFakeBinding` 1 本追加 |
| `tests/Support/ExternalSeam/ExternalSeamInventory.php` | funnel 名指しを resolver へ、rationale / docblock 修正 |
| `config/testing.php` / `.env.bughunt.local.example` / `docs/architecture.md` / `AGENTS.md` | 「SSO は fake しない」の記述を修正 |
| `scripts/bug-hunt-shard.sh` | 実効 env 検証に `fake_externals` を追加 |
| 新規テスト | fake の遷移先が自 host であることの behavioral テスト (負のコントロール付き) |

## 既存テストへの波及 (実コードで確認した結論: **0 行**)

1. **自動テストレーンでは fake が立たない**。`phpunit.xml` / `phpunit.browser.xml` のどちらにも
   `TESTING_FAKE_EXTERNALS` の宣言が無い → `config('testing.fake_externals')` は既定 `false` →
   `registerExternalServiceFakes()` は early return。Browser lane も captcha fake すら張っていない。
2. **facade mock はそのまま効く**。`SocialAuthTest` / `RecentAuthTest` /
   `RecentAuthMethodStampingTest` / `SecurityAuditTrailCoverageTest` は
   `Socialite::shouldReceive('driver')->with('google')` で**ファサードの root を差し替える**。
   `SocialiteDriverResolver` は呼び出しのたびに `Socialite::driver($provider)` を実行するため、
   mock は従来どおり介入する。
3. `AuthThrottleCoverageTest` の `Socialite::spy()` / `Socialite::shouldNotHaveReceived('driver')` も同じ理由で不変。
4. `ThrottleExemptionPremiseTest` は `Socialite::getFacadeRoot()` が `SocialiteManager` であることを
   前提に実 driver へ HTTP spy を差す。flag off なので resolver は実 facade を通す → 不変。

→ **T138 が懸念した波及は「`Factory` を差し替える形」を採った場合のものであり、
facade 経由の解決を残す形なら発生しない**。これが本 TODO を今やれる根拠である。

## 機械で守れること / 守れないこと

守れる:
- 配線の存在と env / flag 条件 → 既存 `ExternalFakeWiringInvariantTest` が
  inventory entry を足すだけで **対照 (flag off → real) / 実証 (flag on + 許可 env → fake) /
  拒否 (production・staging → real)** を自動生成する。
- 「Socialite の driver 解決は resolver 1 クラスだけ」→ 既存 `ExternalSeamInventoryTest` の
  名指し固定を retarget するだけ (新 gate 不要)。当該テストは走査結果集合と
  `socialLoginFunnel()` の**完全一致 (`toBe`)** を要求するため、resolver 以外に
  `Socialite` facade 参照が 1 つでも残れば赤くなる (controller / service / 監査ログ側を含む)。
- 「fake の遷移先が自アプリ host」「4 intent の round-trip が完成する」→ 新規 behavioral テスト
  (負のコントロール = flag off なら遷移先が外部 host になることを同ファイルで示す)。
- bughunt 実行時に fake が外れていないこと → provision の実効 env 検証に `fake_externals` を追加。

守れない (「保証しないもの」に明記する):
- **ブラウザ自身が出す通信**。Playwright の origin allowlist が担う層であり、本 PR は代替しない。
- `.env.bughunt.local` (git 管理外) の手編集。provision 実行時にしか見ない。
- Socialite / Cashier など **vendor 内部**から出る通信。
- `local` 環境での SSO。fake は `local` に**立たない**ので、`local` の SSO ボタンは
  従来どおり実 IdP へ出る (これは意図した挙動。実 IdP 連携の確認手段を残すため)。

## 制約・前提

- **resolver に責務を足さない**。`SocialiteDriverResolver` は `driver()` **1 メソッドのみ**。
  `final` にしない (fake がサブクラスで差し替わるため)。fake は `driver()` 以外を持たない。
  ここが太ると継承ベースの差し替えが崩れる (Codex Round 1 の指摘。制約として固定する)。
- **本番の SSO を壊さない**。fake は `config('testing.fake_externals') === true` かつ
  env ∈ {testing, bughunt.local} の 2 軸でのみ bind される。production は
  `ProductionEnvGuard` が flag=true を deploy 時 fail-fast で拒否する既存機構をそのまま使う (追加しない)。
- **T138 が入れた到達点の目録の形を変えない**。`ExternalSeamKind` / `requiredDimensions()` /
  分類語彙 / 委譲の結線には触らない。変えるのは **funnel の名指し先クラス 1 つと rationale 文言**だけ。
- `RefreshDatabase` グローバル適用 + `--parallel` + Factory 必須の規律に従う。
- PHPStan level 10。

## スコープ外

- UI 変更 (Svelte / DESIGN.md token / Atomic Design 層に触らない)。SSO ボタンは現行のまま。
- Playwright の origin allowlist の変更。
- `google` 以外の provider の追加。
- `Socialite::fake()` を使ったテスト作法の全面移行 (既存の facade mock を書き換えない)。
- MCP / API v1 など SSO 以外の外部到達点。
