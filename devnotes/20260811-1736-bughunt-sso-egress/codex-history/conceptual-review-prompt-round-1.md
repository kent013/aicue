## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


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

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【本件の追加コンテキスト】
- リポジトリは /workspace。ファイル読み込みは許可されているので、必要なら現行コードを確認してよい。
- 本設計は aicue:T138 (devnotes/20260809-0027-external-seam-funnel/) が「既存テストへの波及が大きい」として
  先送りした課題を引き受けるもの。目録の「形」は変えない前提。

---

## 概念設計

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
3. `FakeExternalsServiceProvider::registerExternalServiceFakes()` に
   `bind(SocialiteDriverResolver::class, FakeSocialiteDriverResolver::class)` を 1 行足す。
   `ExternalFakeWiringInventory::bindings()` に `ExternalFakeBinding` を 1 本足す。
   **既存の capability flag (`testing.fake_externals`) と env allowlist
   (`local` / `testing` / `bughunt.local`) をそのまま再利用する** (新 flag / 新 gate / 新 allowlist を作らない)。
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
自前クラスを abstract にすれば **gate の文法にも既存 fake の形にも一切触らずに済む**。

なお `ExternalSeamInventory::socialLoginFunnel()` の docblock は
「集約先を別クラスへ切り出さないのは、**差し替え先 (SSO fake) を今作らないため**」と書いており、
T138 自身が「fake を作るなら切り出す」を想定していた。本案はその想定どおりの続きである。

### 採らない案と理由

| 案 | 却下理由 |
|---|---|
| (b) bug-hunt レーンだけ SSO ボタンを出さない | 探索の網を自分で狭める。route は残るので「アプリ側が塞いだ」と言えない。本番と違う UI を bug-hunt に見せると UX 検証としての価値が落ちる |
| (c') IdP 風の中間スタブ画面を自アプリに作る | 新 route + controller + ページが要るのに、**我々が所有する UX は 1 つも増えない** (IdP の同意画面はアプリの UX ではない)。思考原則 2 に反する。fake の `redirect()` を直接 callback へ向ければ同じ効果が 0 route で得られる |
| vendor の `Socialite::fake()` (`Laravel\Socialite\Testing\SocialiteFake`) をそのまま使う | `FakeProvider::redirect()` の戻り先が `https://socialite.fake/...` という**解決不能な外部 URL**。ブラウザは DNS エラー画面で詰み、round-trip が完成しない。ただし `Two\User` は再利用する (先人の知恵) |
| SSO 用の新しい capability flag を切る | 系統が増えるだけ。captcha と同じ「外部サービス fake」系統であり、分離する logic が無い |

## 期待効果

- **使命への貢献 (間接)**: bug-hunt は「現場作業者が詰まないか」を実走で確かめる装置である。
  SSO 経路が探索不能だったため、SSO 登録 → 個人組織 provisioning → オンボーディング着地、
  SSO 連携 → recent-auth step-up satisfier という**認証系の詰み**を一度も実走できていなかった。
  本改善で初めてこの経路が探索対象になる (塞ぐだけでなく**探索能力が増える**)。
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
  名指し固定を retarget するだけ (新 gate 不要)。
- 「fake の遷移先が自アプリ host」→ 新規 behavioral テスト (負のコントロール = flag off なら外部 host)。
- bughunt 実行時に fake が外れていないこと → provision の実効 env 検証に `fake_externals` を追加。

守れない (「保証しないもの」に明記する):
- **ブラウザ自身が出す通信**。Playwright の origin allowlist が担う層であり、本 PR は代替しない。
- `.env.bughunt.local` (git 管理外) の手編集。provision 実行時にしか見ない。
- Socialite / Cashier など **vendor 内部**から出る通信。
- `local` 環境で開発者が自分の意思で flag を立てた場合の挙動 (既存の Stripe / captcha fake と同じ受容)。

## 制約・前提

- **本番の SSO を壊さない**。fake は `config('testing.fake_externals') === true` かつ
  env ∈ {local, testing, bughunt.local} の 2 軸でのみ bind される。production は
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
