# 概念設計: stray-http-egress-deny

> lctl feature id: `external-egress-default-deny` / 裁定 AG-105 (2026-08-06) の**必須 1 点**への準拠。
> 一次入力: `devnotes/20260807-1235-stray-http-egress-deny/recon-brief.md`

## 背景・課題

### 仮説

**「テストが緑である」ことは、いま aicue では「外部サービスへ通信していない」ことを意味しない。**
テストレーンには HTTP 出口の既定拒否が無く、外部宛て HTTP を出すコードパスは実際に外へ出る
(あるいは出ようとして失敗し、握り潰されて緑のまま通る)。この状態が続く限り、テストの緑は
「外部依存ゼロで再現した」という保証を持たない。

### 実査で確認した事実 (自分でコードを読んで確認済み)

1. `tests/Pest.php` に `Http::preventStrayRequests()` の呼び出しは **1 件も無い**。
   Feature/Unit lane (36-63 行) と Browser lane (78-108 行) は `StrayLlmCallGuard` を
   install/flush するのみ。Architecture lane (65-69 行) は `withoutVite()` だけ。
2. `Http::preventStrayRequests()` の実在は **テスト本体内の局所使用 5 箇所のみ**
   (`tests/Feature/Security/ThrottleExemptionPremiseTest.php:349,410,515,544` /
   `tests/Feature/Security/AuthThrottleCoverageTest.php:267`)。`Http::allowStrayRequests` は
   リポジトリ全体で 0 件。
3. アプリの Laravel HTTP client 経由の外向き出口は 3 本:
   - `app/Services/FxRateService.php:68` → `api.frankfurter.dev`
   - `app/Services/Captcha/RecaptchaVerifier.php:47` → `www.google.com/recaptcha/...`
   - `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php:58-66` → SNS 証明書 URL
   (+ vendor の Fortify `NotPwnedVerifier`)
4. **3 本のうち 2 本は例外を握り潰す**。`FxRateService::fetchFromFrankfurter` は
   `catch (Throwable) { Log::warning(); return null; }`、`AwsSnsSignatureVerifier::certClient` は
   `catch (\Throwable) { throw new SnsVerificationUnavailableException(...) }` で原因を潰す。
   つまり `preventStrayRequests` **だけ**を張っても、テストは赤くならず「fx_snapshot が null になる」
   等の挙動変化に化けて**静かに緑のまま**になる。
5. `tests/Feature/Auth/RegistrationTest.php:45` に古い棄却理由コメントが残っている
   (「preventStrayRequests は合法な他 HTTP まで例外化するため使わない = 過検出回避」)。
   この前提は現在成立しない (下記「棄却理由の再検討」)。

### 課題

この構図は `StrayLlmCallGuard` が LLM 呼び出しについて既に解決した問題と**同型**である
(guard 例外が Service 層の握り潰しで消えるので accumulator + afterEach 一括判定にした)。
HTTP 出口についてだけ、同じ学習が適用されていない。

## 改善アイデア

**テストレーンの既定として HTTP 出口を deny-by-default にし、握り潰し貫通の accumulator を付ける。**

裁定 AG-105 の必須 1 点 = 「テストレーンの既定として `Http::preventStrayRequests()` を常時有効にする
(テスト内で局所的に張って外す形は既定と認めない)。自機宛て loopback は
`Http::allowStrayRequests([...])` の明示許可で通す」に、`StrayLlmCallGuard` と同一 API 形の
`StrayHttpRequestGuard` を新設して応える。

### 論点 1 への回答: 「preventStrayRequests 単体では赤くならない」問題の検出方式

**採用する。** `Http::globalMiddleware()` に guard middleware を 1 本積み、
`Illuminate\Http\Client\StrayRequestException` を **同期 try/catch で捕捉して static accumulator に
記録し、再 throw** する。`tests/Pest.php` の afterEach で `flushAndFailIfStray()` が一括判定する。

vendor 実装を読んで確定した前提 (すべて自己検査で behavioral に固定する):

- `PendingRequest::pushHandlers()` は `middleware`(= globalMiddleware) → beforeSending →
  recorder → **stub handler の順に push** する (`PendingRequest.php:1682-1692`)。
  Guzzle `HandlerStack::resolve()` は `array_reverse` して包むため、**最初に push された
  globalMiddleware が最外側**・stub handler が最内側になる。よって guard は stub handler の
  throw を必ず観測できる。
- stub handler は `throw new StrayRequestException(...)` を **同期 throw** する
  (`PendingRequest.php:1758`)。promise の rejection ではないので、`->otherwise()` ではなく
  **try/catch** で捕える必要がある。async / pool 経路も Guzzle `Client::requestAsync` が
  同期 throw を受けて rejection 化するため、guard より内側で握られることはない。
- `StrayRequestException extends RuntimeException` であり `TransferException` ではないため、
  `PendingRequest::send()` の `catch (TransferException)` には掛からず素通りする。
  `makePromise()` も `if ($e instanceof StrayRequestException) { throw $e; }` で
  ConnectionException 化から除外している (`PendingRequest.php:1201-1203`)。
  → **guard が握り潰すのはアプリ側の catch だけで、フレームワークは潰さない**。

これで「preventStrayRequests を張ったのに赤くならない」= 誤った安心を構造的に潰す。

### 論点 2 への回答: loopback 許可パターンの正本

**許可集合は「loopback リテラルのみ」の静的定数 1 か所** (`StrayHttpRequestGuard::ALLOWED_URL_PATTERNS`)
とし、`config('app.url')` の host は**含めない**。

- 対象は `127.0.0.1` / `localhost` / `[::1]` の 3 ホスト × http/https × 「ポート無し」「任意ポート」
  「任意パス」の形。`Str::is()` の glob なので `http://127.0.0.1`, `http://127.0.0.1/*`,
  `http://127.0.0.1:*` の 3 形で 1 ホストを覆う。
  **`http://127.0.0.1*` のような末尾ワイルドカード 1 本にはしない** —
  `http://127.0.0.1.evil.example/` まで通してしまうため。
- `config('app.url')` を含めない根拠:
  1. Browser lane の in-process サーバは `ServerManager::DEFAULT_HOST` で**常に 127.0.0.1 に
     bind** する (`vendor/pestphp/pest-plugin-browser/src/ServerManager.php:88`) ので、
     loopback リテラルで足りる。
  2. `LaravelHttpServer::boot()` が `config(['app.url' => $url])` を**テスト実行中に書き換える**
     (`LaravelHttpServer.php:153`)。beforeEach 時点の `config('app.url')` を snapshot しても
     Browser lane では古い値になる = 動的導出は正しく効かない。
  3. `APP_URL` は環境依存 (`.env` は `http://aicue.test`、`.env.example` は `http://localhost`、
     CI は別値)。許可集合を環境依存にすると Architecture gate が固定値を検査できず、
     「開発者の .env 次第で外部ドメインが許可される」穴になる。
  4. `aicue.test` のような名前は DNS 解決先が保証されず「自機宛て」を名乗る根拠にならない。

### 施策の構成 (概要)

| # | 施策 | 中身 |
|---|------|------|
| S1 | `StrayHttpRequestGuard` の新設 | prevent + loopback allow + globalMiddleware accumulator。`StrayLlmCallGuard` と同一 API 形 |
| S2 | 自己検査 (behavioral) | 前提 (最外側で捕える / 握り潰し貫通 / fake 透過 / loopback 通過 / flush の finally clear) を固定 |
| S3 | 3 レーンへの既定配線 | `tests/Pest.php` の Feature/Unit・Browser・Architecture の beforeEach/afterEach |
| S4 | Architecture gate (deny-by-default 目録型) | レーン配線・許可パターン・opt-out の型付き exemption inventory を機械強制 |
| S5 | 既存記述の是正 | `RegistrationTest:45` の棄却理由コメント / `AGENTS.md` / `docs/testing-browser.md` |

### 既製パッケージ採否の評価 (裁定 4 点目への回答)

**新規依存はゼロ**。laravel/framework ^13.8 に必要なものが全て揃っていることを実コードで確認した:

- `Factory::preventStrayRequests(bool)` / `Factory::allowStrayRequests(?array $only)`
  (`Factory.php:429-445`)
- `Factory::createPendingRequest()` が prevent / allow を PendingRequest へ伝播
  (`Factory.php:583-590`)
- `Factory::fake()` は `preventStrayRequests` / `allowedStrayRequestUrls` を **reset しない**
  (`Factory.php:309-` を読んで確認) → 「レーン既定 ON + 各テストの局所 `Http::fake`」が無改修で共存
- `PendingRequest::buildStubHandler()` は stub 未登録でも常時 push される
  (`PendingRequest.php:1692`) → `Http::fake()` を呼ばないテストでも遮断が効く
- `Factory::globalMiddleware()` (`Factory.php:111`)

外部パッケージ (`spatie/laravel-*` 系の HTTP guard 等) を足す理由は無い
(思考原則 1「フレームワークのレンジ内でやる」)。

## 期待効果

- **使命への貢献 (間接だが直結)**: aicue は SOP → シナリオ → 撮影 → レンダの各段で LLM・
  為替・captcha・SNS 通知という外部依存に囲まれている。「テストが緑 = 外部に触っていない」を
  構造的に保証できないと、CI の緑が現場品質の根拠にならない。既に LLM 側でこの保証を作った
  (`StrayLlmCallGuard`) ので、HTTP 出口にも同じ床を敷いて**保証の穴を閉じる**。
- **偽グリーンの検出**: `FxRateService` の握り潰しで消えていた外部到達が accumulator に残り、
  afterEach で必ず赤くなる。「入れたのに赤くならない」誤った安心を作らない。
- **秘密の漏出面の縮小 (Laravel HTTP client 経由に限る)**: `Http::` 経由のテスト実行時通信が
  実 API へ到達しなくなる = 誤って本物のキーが `.env` に入っていても、**この経路からは**外へ出ない。
  Socialite (Guzzle 直) / Stripe SDK / AWS SDK / ブラウザ自身の fetch は**対象外**
  (下記「保証範囲の明示」)。
- **再現性**: 外部サービスの障害・レート制限・ネットワーク不通で CI が揺れる経路が消える。
- **同型の学習の再利用**: guard の API 形・レーン配線・gate 形式をすべて既存 (`StrayLlmCallGuard` /
  `GlobalTestLockInventoryTest` / `ThrottleCoverageInventoryTest`) に合わせるため、
  読み手が新しい概念を覚える必要がない。
- 実行時間の増加は実質ゼロ (middleware 1 本 + 配列判定)。

## 実装方針（概要）

**導入順序 (テストファースト / 思考原則 5)**: S2 の自己検査を先に書いて**赤を確認**してから
S1 の guard 実装 → S3 のレーン配線 → S4 の gate → S5 の記述是正、の順で進める。
S4 の gate も「素の main では赤にならない」ため、mutation (配線行の一時削除) で
赤化を確認する手順を詳細設計に書く。

### 新規 4 本

| ファイル | 役割 |
|---------|------|
| `tests/Support/StrayHttpRequestGuard.php` | `install(Application)` で `Http::preventStrayRequests()` + `Http::allowStrayRequests(ALLOWED_URL_PATTERNS)` + `Http::globalMiddleware(...)`。`flushAndFailIfStray()` / `reset()` / `drainForAssertion()` を `StrayLlmCallGuard` と同一 API 形で提供 |
| `tests/Support/Security/StrayHttpEgressExemption.php` | opt-out 箇所の分類 (backed string enum)。`Tests\Support\Security\PrimaryKeyPredicateKind` と同じ置き場 |
| `tests/Feature/Support/StrayHttpRequestGuardTest.php` | 自己検査。`StrayLlmCallGuardTest.php` (case A〜F) と同型 |
| `tests/Architecture/StrayHttpEgressLaneGateTest.php` | deny-by-default 目録型 gate。`GlobalTestLockInventoryTest` と同じ「純関数 + 負のコントロール」形 |

### 変更 4〜5 本

| ファイル | 変更内容 |
|---------|---------|
| `tests/Pest.php` | Feature/Unit・Browser・Architecture の 3 レーンに install / flush を追加 |
| `tests/Feature/Auth/RegistrationTest.php` (43-49 行) | 棄却理由コメントを裁定準拠へ書き換え |
| `AGENTS.md` | 「テストレーンの HTTP 出口既定拒否」を 1 項追加 + プロセス境界の明文化 |
| `docs/testing-browser.md` §LLM fake (in-process) 周辺 | 二層防御の記述へ HTTP 出口既定拒否を追記 + 保証範囲の限界を明記 |

### 棄却理由の再検討 (`RegistrationTest:45`)

現行コメントは「preventStrayRequests は合法な他 HTTP まで例外化するため使わない」と書くが、
その「合法な他 HTTP」の実体が存在しない:

- 想定されていた HIBP (`api.pwnedpasswords.com`) は、`app/Support/PasswordPolicy.php:32` の
  `PWNED_CHECK_DISABLED_APP_ENVS` に `'testing'` が含まれるため **testing env では
  `uncompromised` 自体が付かず通信が発生しない** (同ファイルの pwnedpasswords fake は
  現状 no-op の保険)。
- 実際に既定拒否へ掛かるのは `api.frankfurter.dev` と reCAPTCHA であり、いずれも
  **外部宛て = 通してはいけない通信**。

よって棄却理由は「loopback 許可で解ける」以前に**前提そのものが成立していない**。
コメントはこの事実まで書き残す (次の担当が同じ判断を繰り返さないため)。

### 保証範囲の明示 (過大な保証を書かない)

裁定が明文化を求めている「出口拒否は呼んだプロセス内でしか効かない」を、
両者を対称に書かずに記録する。捕捉できないもの:

- **別プロセス**: bug-hunt (`scripts/bug-hunt-shard.sh` 経由の別プロセス実行) には**無言で効かない**。
- **Guzzle 直**: `app/Http/Controllers/Auth/SocialAuthController.php` の Socialite は
  Laravel HTTP client を通らない。
- **SDK 直**: Stripe SDK (curl) / AWS SDK。
- **ブラウザ自身の出口**: Browser lane で Playwright のブラウザが出す外部フォント / CDN 取得。
  (in-process サーバ経由のアプリ側 HTTP は同一 container なので効く)

## 制約・前提

1. **アプリコードを変更しない**。変更は `tests/` + `AGENTS.md` + `docs/` に閉じる
   (アプリ側の握り潰しを直すのは別件。ここでは「握り潰されても検出できる」を作る)。
2. laravel/framework ^13.8 の HTTP client 内部挙動 (handler stack の push 順 / 同期 throw /
   `fake()` が prevent flag を保たないこと) に依存する。**この前提は自己検査 (S2) で
   behavioral に固定する**ので、framework 更新で崩れたら CI が赤くなる。
3. `RefreshDatabase` はグローバル適用済み。`DatabaseTransactions` の個別使用は禁止。
   テストは `--parallel` で走る → guard の static accumulator は**プロセス内 static**でよい
   (`StrayLlmCallGuard` と同じ)。
4. `StrayLlmCallGuard` は**維持して並存**させる (裁定の確定事項)。責務が違う
   (Prism の provider 解決 vs HTTP 出口) ので統合しない (思考原則 4)。
5. 初回導入時に既存テストが赤くなりうる。特に pwnedpasswords 限定 fake の 4 ファイル
   (`RegistrationTest` / `RegisterVerifyFlowTest` / `RegisterPlanHandoffTest` /
   `RecentAuthPasswordRecoveryTest`) と CERT_URL 限定 fake の `AwsSnsSignatureVerifierTest`。
   **これは回帰ではなく検出**であり、`Http::fake()` の追加で解く (テストを緩めない)。
6. JS (vitest) レーンは本裁定の対象外 (裁定は Laravel の `Http::` 機構を名指ししている)。

## スコープ外

今回のスコープは**裁定 AG-105 の必須 1 点**に絞る。以下は裁定でも「boundary 内だが必須化は
未決定 = 各リポジトリ裁量」とされたもので、**今回は入れない**。

| 除外するもの | 理由 |
|------------|------|
| **資格情報の無効化** (強制キー集合の検査 / `FILESYSTEM_DISK` 上書き) | 裁定が裁量と明示。既に `phpunit.xml` / `phpunit.browser.xml` の `<server force>` による LLM キーのダミー化 + STRIPE_* 空文字化 + `PrismApiKeyDummyTest` という別層の防御が実在する。必須 1 点と混ぜると施策の合否判定が「どこまでやれば完了か」で曖昧になり、レビューの焦点がぼやける (思考原則 2: 今必要なものだけ作る) |
| **代替実装の到達性確認・未消費検出** (fake が実際に消費されたかの検査) | 同じく裁量。本件の不変条件 (出口が閉じている) とは別の不変条件 (fake が使われている) であり、別の概念を「似ているから」で統合しない (思考原則 4)。必要なら独立 TODO で起票する |
| **アプリ側の握り潰し (`catch (Throwable)`) の是正** | `FxRateService` / `AwsSnsSignatureVerifier` の catch は production の可用性設計として意図的。テスト検出のために production の挙動を変えるのは本末転倒。本設計は「握り潰されても accumulator で検出する」で解く |
| **JS (vitest) レーンの fetch 遮断** | 裁定の対象外 (Laravel の `Http::` 機構を名指し)。別の機構が要るため別件 |
| **bug-hunt (別プロセス) への出口拒否** | プロセス境界を越えないという事実そのものが裁定の明文化対象。**効かないことを書く**のが今回の責務で、効かせるのは別設計 (別プロセスの egress 遮断は OS/コンテナ層の話) |
| **局所 `preventStrayRequests` 5 箇所の削除** | 既定 ON 下でも冪等なので削除は必須でない。位置づけコメントの整理に留める (後方互換の並走ではなく、既定と同値の重複宣言) |
