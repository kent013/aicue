# 実査ブリーフ: external-egress-default-deny

> lctl 台帳 (feature id: `external-egress-default-deny`) の正典設計と aicue の実コードを突き合わせた調査結果。
> 2026-08-07 の実査。設計フェーズの入力であり、設計そのものではない。

## 序列

- 順位: #3 / 候補 8 件
- 想定 TODO タイトル: テストレーンの HTTP 出口既定拒否
- テーマ / 優先度 / モード: test / Medium / standalone
- value=7 effort=4 self_contained=True
- 選定理由: 裁定 AG-105 の必須が「テストレーンの既定として preventStrayRequests を常時有効化 + loopback を明示許可」の 1 点に絞られていてスコープが動かず (基準 3)、変更が tests/ 配下にほぼ閉じるので effort 4 と最小 (基準 5)。効果は間接だが正しさ寄与は明確で、api.frankfurter.dev / reCAPTCHA / SNS 証明書取得という実在の外部到達点が、いずれも Throwable を握り潰す実装のため「静かに緑」を作っている。accumulator を同時に入れれば、この偽グリーンを検出可能にできる。棄却理由コメント (RegistrationTest:45) が古い前提のまま残っており、放置すると次の担当が同じ判断を繰り返す点も期限性がある。

## 設計で最初に決めるべき論点

「preventStrayRequests 単体では赤くならない」問題の検出方式を最初に決める。FxRateService / AwsSnsSignatureVerifier / RecaptchaVerifier はいずれも例外を握り潰すため、Http::globalMiddleware で StrayRequestException の rejection を static accumulator へ記録し afterEach で一括判定する (StrayLlmCallGuard と同一 API 形) か否かを決め、globalMiddleware が stub handler より外側で走る前提を自己検査で behavioral に固定する。あわせて loopback 許可パターンの正本 (config('app.url') のホスト / 127.0.0.1 / localhost) をどこに 1 か所で置くかを決める。

## 台帳が確定させた標準形

裁定 AG-105 (2026-08-06)。必須は 1 点のみ: テストレーンの既定として `Http::preventStrayRequests()` を常時有効にする (テスト内で局所的に張って外す形は既定と認めない)。自機宛て loopback は `Http::allowStrayRequests([...])` の明示許可で通す = これが aicue の棄却理由への回答。適用はテスト実行時に限る (本番の通信は対象外)。あわせて「出口拒否は呼んだプロセス内でしか効かない」ことの明文化 (別プロセスの探索的検査には無言で効かない。両者を対称に書くのは禁止)、導入手段は既製パッケージの採否を先に評価 (公式で足りれば新規依存を増やさない)、LLM の StrayLlmCallGuard は維持し並存させる、が確定事項。資格情報の無効化 (強制キー集合の検査 / FILESYSTEM_DISK 上書き) と代替実装の到達性確認・未消費検出は boundary 内だが必須化は未決定 = 各リポジトリ裁量。

## aicue の現状 (実在確認済み)

標準形の中核 (HTTP 出口既定拒否のレーン既定) は未実装。実査結果: (1) `tests/Pest.php` は Feature/Unit lane (36-63 行) と Browser lane (78-108 行) の beforeEach で `Tests\Support\StrayLlmCallGuard::install()`、afterEach で `flushAndFailIfStray()` を呼ぶが、`Http::preventStrayRequests` の呼び出しは 1 件も無い。Architecture lane (65-69 行) は `withoutVite()` のみ。(2) `Http::preventStrayRequests()` の実在は tests 配下 5 箇所だけで、すべてテスト本体内の局所使用: `tests/Feature/Security/ThrottleExemptionPremiseTest.php:349,410,515,544` と `tests/Feature/Security/AuthThrottleCoverageTest.php:267`。(3) `Http::allowStrayRequests` はリポジトリ全体で 0 件 (vendor/node_modules 除外の rg)。(4) 台帳が指す棄却理由は実在: `tests/Feature/Auth/RegistrationTest.php:45` に「(preventStrayRequests は合法な他 HTTP まで例外化するため使わない = 過検出回避)」。(5) laravel/framework ^13.8 で `Factory::allowStrayRequests(?array $only)` / `PendingRequest::isAllowedRequestUrl()` (`vendor/.../Http/Client/Factory.php:429`、`PendingRequest.php:1912,1925,1757`) が実在し、`buildStubHandler` は fake 未登録でも常時 stack に積まれるため `Http::fake()` なしでも遮断が効く。`Factory::fake()` は preventStrayRequests フラグを reset しない (共存可)。(6) アプリ側の Laravel HTTP client 経由の出口は 3 本のみ: `app/Services/FxRateService.php:68` (api.frankfurter.dev)、`app/Services/Captcha/RecaptchaVerifier.php:47`、`app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` (`Illuminate\Http\Client\Factory` を DI し certClient で cert 取得) + vendor の Fortify NotPwnedVerifier。Socialite は Guzzle 直 (`app/Http/Controllers/Auth/SocialAuthController.php`)、Stripe SDK / AWS SDK も対象外。(7) 既存の抑止は phpunit.xml / phpunit.browser.xml の `<server force>` による LLM キーのダミー化と STRIPE_* 空文字化、`tests/Feature/Config/PrismApiKeyDummyTest.php`。(8) Browser lane の in-process サーバ (`vendor/pestphp/pest-plugin-browser/src/Drivers/LaravelHttpServer.php:238` が `app()->make(HttpKernel::class)`) はテストプロセスの同一 container を使うため、レーン既定の遮断はブラウザ経由リクエストにも効く。(9) `docs/TODO.md` に本件の TODO は無く、台帳観測点 aicue@db4620c 以降 `tests/Pest.php` / `tests/Support/` を触った 7 コミットにも egress guard の追加は無い。

## ギャップ

1. tests/Pest.php の Feature/Unit lane・Browser lane・Architecture lane のいずれにも Http::preventStrayRequests() のレーン既定配線が無い。
2. 自機宛て loopback の明示許可 (Http::allowStrayRequests([...])) がリポジトリ全体で 0 件で、許可パターンの正本 (config('app.url') のホスト / 127.0.0.1 / localhost) がどこにも定義されていない。
3. FxRateService::fetchFromFrankfurter / AwsSnsSignatureVerifier::certClient / RecaptchaVerifier はいずれも Throwable を握り潰すため、preventStrayRequests 単体では赤くならず挙動が変わるだけになる。StrayLlmCallGuard 同型の accumulator + afterEach 一括判定が無い。
4. レーン配線 (全レーンが guard を install していること / 許可パターンが loopback に限られること / opt-out 箇所が理由付き inventory 登録されること) を deny-by-default で固定する Architecture gate が無い。
5. tests/Feature/Auth/RegistrationTest.php:45 の棄却理由コメントが古いまま残っており、更新しないと次の人が同じ判断を繰り返す (裁定が明示的に更新を要求している)。
6. 出口既定拒否がプロセス境界内でしか効かない事実 (bughunt の別プロセス実行には無言で効かない) が AGENTS.md にも docs/testing-browser.md にも書かれていない。

## 想定スコープ

新規 3 本: (a) `tests/Support/StrayHttpRequestGuard.php` — install($app) で `Http::preventStrayRequests()` + `Http::allowStrayRequests([loopback パターン])` を張り、`Http::globalMiddleware()` で StrayRequestException の rejection を static accumulator へ記録、`flushAndFailIfStray()` / `reset()` / `drainForAssertion()` を StrayLlmCallGuard と同一 API 形で提供 (握り潰し貫通が本体)。(b) `tests/Feature/Support/StrayHttpRequestGuardTest.php` — StrayLlmCallGuardTest.php (115 行, case A〜F) と同型の自己検査。最低限 (1) 未 fake の外向き HTTP が例外 + accumulator 記録、(2) Http::fake(['*'=>…]) で透過、(3) loopback 許可先は通る、(4) FxRateService 経由の握り潰しでも accumulator に残る、(5) flush の finally clear。(c) `tests/Architecture/StrayHttpEgressLaneGateTest.php` — `GlobalTestLockInventoryTest.php` (425 行) と同じ deny-by-default 目録型。tests/Pest.php をソース走査し全レーンの install/flush を強制、許可 URL パターン定数を inventory 化して loopback 以外を拒否、opt-out (allowStrayRequests(null) / preventStrayRequests(false)) の呼び出し箇所を理由付き exemption inventory へ登録必須にする。変更 5〜6 本: `tests/Pest.php` (3 レーンの beforeEach/afterEach)、`tests/Feature/Auth/RegistrationTest.php:43-49` (棄却理由コメントを裁定準拠へ書き換え)、`tests/Feature/Security/ThrottleExemptionPremiseTest.php` / `AuthThrottleCoverageTest.php` (局所 prevent の位置づけコメント整理。既定 ON でも冪等なので削除は必須でない)、`AGENTS.md` (テスト規約 or セキュリティ不変条件に 1 項 + プロセス境界の明文化)、`docs/testing-browser.md` §LLM fake (in-process) 周辺 (二層防御の記述へ HTTP 出口既定拒否を追記し、bughunt 別プロセスに効かないことを明記)。既製パッケージ採否の評価 (裁定 4 点目) は devnotes の設計文書で 1 段落済ませ、公式機構で足りる結論なら新規依存ゼロ。

## リスク

最大のリスクは「入れたのに赤くならない」誤った安心。FxRateService (Throwable catch → null 返し + Log::warning)、AwsSnsSignatureVerifier (catch → SnsVerificationUnavailableException)、RecaptchaVerifier はいずれも例外を握り潰すため、preventStrayRequests だけでは fx_snapshot が null 化する等の挙動変化に化けてテストが静かに緑のまま通る可能性がある。accumulator を入れないと LLM 側で既に学習済みの失敗を繰り返す。次に、Http::globalMiddleware が Guzzle HandlerStack 上で stub handler より外側で走ること (push 順で stub が最内) に依存するため、この前提は自己検査で behavioral に固定する必要がある。既存テストへの波及は小さい: Prism::fake / Prompt::fake を使うテストファイルは全件が既に Http::fake を併用しており (未併用は tests/Pest.php と tests/Support/StrayLlmCallGuard.php のみ)、Http::fake を持つテストは 16 ファイル。ただし pwnedpasswords 限定 fake の 4 ファイル (RegistrationTest / RegisterVerifyFlowTest / RegisterPlanHandoffTest / RecentAuthPasswordRecoveryTest) と CERT_URL 限定 fake の AwsSnsSignatureVerifierTest は、想定外の別 URL が出れば新たに落ちる (=検出であって回帰ではないが、初回導入時に赤が出うる)。Browser lane は in-process の Amp サーバが同一 container を使うため効くが、Playwright ブラウザ自身が出す外部フォント/CDN 取得や、Socialite (Guzzle 直)・Stripe SDK (curl)・AWS SDK は捕捉できないので、ドキュメントで過大な保証を書くと嘘になる。実行時間の増加は実質ゼロ。

## 実装者への申し送り (台帳と実コードの食い違いを含む)

台帳と実コードの食い違いを 2 点。(1) 台帳 gates の「spirux が家系で唯一の公式機構の使用例」は aicue に関して不正確。aicue にも公式機構の局所使用が 5 箇所実在する (tests/Feature/Security/ThrottleExemptionPremiseTest.php:349,410,515,544 / tests/Feature/Security/AuthThrottleCoverageTest.php:267)。使い方は spirux と同じくテスト内で局所的に張る形で「レーン既定ではない」点は台帳の結論どおりだが、「使用例は spirux が唯一」は還流して訂正すべき。(2) RegistrationTest:45 の棄却理由が想定する「合法な他 HTTP」の実体は HIBP ではない。`app/Support/PasswordPolicy.php:32` の PWNED_CHECK_DISABLED_APP_ENVS に 'testing' が含まれるため testing env では uncompromised 自体が付かず HIBP 通信は発生しない (同ファイルの pwnedpasswords fake は現状 no-op の保険)。実際に既定拒否へ引っかかるのは FxRateService の api.frankfurter.dev と reCAPTCHA であり、いずれも外部宛て = 通してはいけない通信なので、棄却理由は「loopback 許可で解ける」以前に前提自体が成立していない。コメント更新時はこの事実まで書き残すこと。実装補足: `Illuminate\Http\Client\Factory::fake()` は preventStrayRequests フラグを reset しない (vendor/laravel/framework/src/Illuminate/Http/Client/Factory.php:309-)、`createPendingRequest()` が prevent/allow を PendingRequest へ伝播する (同 582-590)、`buildStubHandler` は stub 未登録でも常時 push される (PendingRequest.php:1691) — この 3 点により「レーン既定 ON + 各テストの局所 Http::fake」は無改修で共存する。Architecture lane は HTTP を出さないが、install しておく方が「全レーン一律」で gate が単純になる。JS (vitest) レーンは本裁定の対象外 (裁定は Laravel の Http:: 機構を名指ししている) なので今回は触らない。
