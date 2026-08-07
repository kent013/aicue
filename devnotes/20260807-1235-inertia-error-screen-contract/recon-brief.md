# 実査ブリーフ: error-response-contract

> lctl 台帳 (feature id: `error-response-contract`) の正典設計と aicue の実コードを突き合わせた調査結果。
> 2026-08-07 の実査。設計フェーズの入力であり、設計そのものではない。

## 序列

- 順位: #2 / 候補 8 件
- 想定 TODO タイトル: Inertia の 4xx/5xx を Error 画面へ差替
- テーマ / 優先度 / モード: backend / High / incremental
- value=8 effort=6 self_contained=True
- 選定理由: 判断基準 2 の「行き先のないエラー」の代表例。X-Inertia 付き XHR に対して素の Blade が返るため、419 (セッション切れ)・404・429 という日常的に踏む経路で利用者が Inertia のエラーモーダルに閉じ込められ、自力で画面へ戻れない。頻度は本一覧中で最も高い。value 8 と最大で、裁定は 3 分岐 ((a) は推奨止まり) まで確定済み、self_contained。(c) の封筒 JSON と admin 文面分離は既に実装済みなので追加は (b) だけに閉じられ、既存の ApiExceptionRenderer と対称な形で書ける。順位を 1 位にしなかったのは、現状の戻り先が "/" 固定で open redirect も情報漏洩も無く、閉じるのは不変条件の穴ではなく UX の詰みと将来の契約固定だから (基準 1 < 基準 2 の重み差)。

## 設計で最初に決めるべき論点

「差し替えてはならない応答」の集合と適用順を先に確定する。具体的には (1) Inertia version mismatch の 409 と Location ヘッダを持つ 3xx を素通しにする条件、(2) AuthenticationException の render callback (Inertia::clearHistory() の副作用 + null 返しで既定処理へ委ねる契約) より後段に差し替えを置くこと、(3) ApiExceptionRenderer (api/*) → admin の respond() → Inertia 差し替え の適用順、の 3 点。あわせて差し替えロジックは bootstrap/app.php に直書きせず app/Exceptions/InertiaExceptionRenderer.php へ切り出す (InertiaRenderPageExistsInvariantTest の走査対象が app/ と routes/ のみで bootstrap/ を含まないため、直書きするとページ実在 gate が効かない)。

## 台帳が確定させた標準形

経路の性質で 3 分岐。(a) 人が使うフォーム POST は素のエラーページを出さず入力を保ったままフォーム内へ文言を出す。待ち時間はヘッダでなく本文へ。ただし「人が使うフォームか」を機械判定できないため必須化せず推奨に留める。(b) 画面遷移 (Inertia XHR) はサーバ側の例外ハンドラで Error 画面へ差し替える。戻り先はサーバ側に固定した許可一覧から出しリクエスト入力を混ぜない。Inertia 手順上の 409 と遷移先ヘッダを持つ応答は素通し。待ち時間は非負整数のみ採り解釈不能なら非表示。(c) プログラム向け経路は封筒 JSON で返し Retry-After を details.retry_after へ写す。共通規約として文面は中立、運営者向けと利用者向けで文面を分ける。骨格は spirux 形、待ち時間表示と戻り先決定は aigenba から合成。

## aicue の現状 (実在確認済み)

(c) プログラム向け経路のみ実装済み。`app/Exceptions/ApiExceptionRenderer.php` が `shouldHandle()` = `$request->is('api/*')` で分岐し、`rateLimitDetails()` が `Retry-After` を `details.retry_after` へ写す (`ctype_digit` のときのみ int 化)。`bootstrap/app.php:352` の `$exceptions->render(Throwable)` で配線。共通規約のうち「運営者向けと利用者向けの文面分離」も実装済み — `bootstrap/app.php:360` の `$exceptions->respond()` が `AdminPanelPath::resolve()` 配下だけ `errors.admin.4xx` / `errors.admin.5xx` へ差し替える。(b) は不在 — `Inertia::render('Error'` / `pages/Error` の literal 参照は app/ bootstrap/ routes/ resources/ tests/ 全体で 0 件、`resources/js/pages/` 直下・2 階層に `*rror*` にマッチするファイルなし (存在するのは `resources/js/components/atoms/FormError.svelte` のみ)。`bootstrap/app.php` に X-Inertia 判定の差し替えは無く、`X-Inertia` を見る PHP は `RequireRecentAuth.php:97` / `PasskeyConfirmationResponse.php:40` / `ConfirmRecentAuthController.php:127` の 3 箇所だけで、いずれも本契約とは別目的。したがって Inertia XHR には `resources/views/errors/{401,403,404,419,429,500,503,4xx,5xx}.blade.php` の素の HTML が返る。(a) も不在 — named limiter は `FortifyServiceProvider.php` に 4 本 + ループ登録群、`AppServiceProvider.php` に 12 本以上あるが `->response(` を持つものは 0 件。ブラウザ側の横取り機構は無い (resources/js に 429 / Retry-After / retry_after の参照 0 件)。エラー画面の戻り先は `resources/views/errors/layout.blade.php` の `<a class="home" href="/">ホームに戻る</a>` 固定。既存検査は `tests/Feature/Errors/ErrorPagesTest.php` (自己完結性 + 404 着地 + admin 中立文面) のみで、経路の性質による分岐を固定する検査は無い。関連して `app/Support/Http/SameOriginPath.php` (referer/intended を同一オリジン相対 path へ正規化) は存在するが、これは**リクエスト入力を許容する**正規化であり、裁定が要求する「サーバ側に固定した許可一覧」ではない。

## ギャップ

1. Inertia XHR (X-Inertia ヘッダ付き) の 4xx/5xx をサーバ側で Error 画面へ差し替える分岐が bootstrap/app.php に無く、素の HTML が返るため Inertia の全画面 iframe で利用者が画面から出られない。
2. 受け皿となる resources/js/pages/Error.svelte が存在しない (Inertia resolver は未解決ページで throw する)。
3. 戻り先をサーバ側に固定した許可一覧が無い (既存の SameOriginPath はリクエスト入力由来の path を通すため裁定の要件を満たさない)。
4. 差し替えてはならない例外 (Inertia 手順上の 409 と Location ヘッダを持つ応答) の明示が無く、素通し契約が機械で固定されていない。
5. 待ち時間の秒数を画面へ渡す経路が無い (非負整数のみ採用・解釈不能は非表示、というパース規約も未実装)。
6. フォーム内エラー契約 (named limiter の ->response() で 302 + back()->withErrors()) が 0 件 — 裁定上は推奨に留まるが、対象フォームの選定判断自体が未着手。

## 想定スコープ

変更: `bootstrap/app.php` (`withExceptions` に X-Inertia 判定の差し替えを追加。既存の admin 差し替え `$exceptions->respond()` および `ApiExceptionRenderer` の `render()` との**適用順**を明示的に決める必要がある)。新規: `resources/js/pages/Error.svelte` (status / message / 戻り先 / retry_after を props で受ける)、`app/Support/Http/ErrorScreenDestinations.php` 相当のサーバ側固定許可一覧、`app/Support/Http/RetryAfterSeconds.php` 相当の非負整数パーサ (ApiExceptionRenderer::rateLimitDetails と共有 SoT 化するのが望ましい)。新規テスト: `tests/Feature/Errors/InertiaErrorScreenTest.php` (X-Inertia で 403/404/419/429/500 が Inertia component 'Error' になる / 戻り先が固定許可一覧から出る / retry_after が非負整数のときだけ載る)、`tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php` (Inertia version mismatch 409 と Location 付き 302 が素通しされる)、Architecture gate `tests/Architecture/InertiaErrorScreenContractTest.php` (差し替え対象 status と除外条件の deny-by-default 目録。書き方は `tests/Architecture/ThrottleCoverageInventoryTest.php` の「母集団を過大に取り、除外は型付き enum + 30 文字以上の根拠 + exact-fit の cap で縛る」形が最も近い。除外理由は `App\Enums\Security\ThrottleCoverageExemption` に倣った専用 enum を新設する)。既存改修の可能性: `resources/views/errors/layout.blade.php` の戻り先を許可一覧と揃える。既存テストの追随確認: `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` (X-Inertia + stale version で 404 を期待)、`tests/Feature/Auth/PasskeyRouteAccessTest.php`、`tests/Feature/Auth/RecentAuthTest.php`、`tests/Feature/Settings/PasswordSetupTest.php` ほか X-Inertia を送る Feature テスト計 11 ファイル。フロント側 gate: `tests/js/architecture/page-shell-structure.test.ts` / `svelte-head-no-title.test.ts` / `ds-purity.test.ts` / `typography-invariant.test.ts` / `contrast-invariant.test.ts` が新規ページに掛かる。

## リスク

最大のリスクは「差し替えてはならない応答」を巻き込むこと。(1) Inertia version mismatch の 409 を差し替えると資産の再読み込みが壊れる。`tests/Feature/Security/TenantBoundaryPrecedenceTest.php` が X-Inertia + `X-Inertia-Version: stale-version` で 404 を期待しており、実装次第で直接落ちる。(2) `Location` ヘッダを持つ 3xx (`LoginResponse::redirect()->intended()`、Fortify 各 Response、`back()->with('error')` に変換される QuotaExceededException / InsufficientTicketsException) を差し替えると外部遷移とフロー全体が壊れる。(3) `ConfirmRecentAuthController` は X-Inertia 有無で 204 と Inertia 応答を出し分けており (`ConfirmRecentAuthController.php:127`)、`PasskeyConfirmationResponse.php:40` も同様。ここへ差し替えが混ざると再認証モーダルが壊れる。(4) `bootstrap/app.php` の `shouldRenderJsonWhen` (api/* または expectsJson) と admin 用 `respond()` の**適用順**を誤ると、api 経路の封筒 JSON か admin の中立テンプレートのどちらかが Inertia 差し替えに食われる。(5) `AuthenticationException` の render callback は `Inertia::clearHistory()` を副作用として持ち、null 返しで既定処理へ委ねる契約 (AGENTS.md ドメイン規約 3 / `InertiaHistoryGuardTest`)。差し替えを先に噛ませると履歴鍵破棄の契機が消え、ログアウト後復元の防御が崩れる。(6) `resources/views/errors/*.blade.php` の自己完結契約 (Vite/Inertia 非依存) は 500 経路の最後の砦なので、Inertia 化で置き換えず**併存**させる必要がある (置き換えると Vite が壊れた 500 で白画面)。実行時間の増加は軽微。

## 実装者への申し送り (台帳と実コードの食い違いを含む)

台帳と実コードの食い違い: 台帳 aicue note は「中立な文面の静的な 429 ページのみ」と書くが、実際には `bootstrap/app.php:360` の `$exceptions->respond()` + `resources/views/errors/admin/{4xx,5xx}.blade.php` で**運営者向け / 利用者向けの文面分離は既に実装済み**であり、`tests/Feature/Errors/ErrorPagesTest.php` の 'renders the admin error layout with a neutral operator tone' が固定している。つまり共通規約のうち 1 項目は追従不要。それ以外 ((b) 全部・(a) 全部) は台帳の記述どおり不在であることをファイル実在レベルで確認した。

実装者への申し送り 3 点。(1) **`Inertia::render('Error')` を `bootstrap/app.php` に書くと既存 gate の網に掛からない** — `tests/Architecture/InertiaRenderPageExistsInvariantTest.php` の走査対象は `inertiaScanTargets()` が返す `app/` と `routes/` の 2 ディレクトリのみで、`bootstrap/` は含まれない。ページ実在検査を効かせたいなら差し替えロジックを `app/Exceptions/` 配下のクラス (ApiExceptionRenderer と対になる `InertiaExceptionRenderer` 等) に切り出して bootstrap からは呼ぶだけにするか、gate の走査対象に `bootstrap` を足すこと。前者を推奨する (既存の ApiExceptionRenderer と対称になり、AGENTS.md の「Controller は薄く」の思想にも合う)。(2) `resources/js/pages/Error.svelte` は `AppLayout` / `AuthLayout` のどちらも import しない構成にするのが安全 — import すると `tests/js/architecture/page-shell-structure.test.ts` の PageContainer/PageHeader/PageContent 必須契約か AuthLayout の離脱導線契約が掛かる。(3) `Retry-After` のパースは `ApiExceptionRenderer::rateLimitDetails()` (139 行目で `is_int || is_string` の分岐があり、HTTP-date 形式の文字列をそのまま素通しする) と、裁定が要求する「非負整数のみ採り解釈不能は非表示」が**一致していない**。共通ヘルパへ寄せる際に (c) 側の既存挙動が変わるので、api 封筒の後方互換を壊すかどうかを先に決めること。

なお AGENTS.md 禁止事項 7 (「操作系 POST の応答での `redirect()->intended()` 禁止、招待送信等は `back()->with(...)` で完結」) は (a) のフォーム内エラー契約と方向が一致しており、`back()` 系の作法は既に定着している (`bootstrap/app.php:334` / `:347` の QuotaExceededException / InsufficientTicketsException が実例)。(a) を入れるならこの既存経路と同じ形にできる。
