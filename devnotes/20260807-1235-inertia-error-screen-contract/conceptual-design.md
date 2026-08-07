# 概念設計: inertia-error-screen-contract

> lctl feature id: `error-response-contract` / 裁定 (b) 「画面遷移 (Inertia XHR) のサーバ側統一」限定。
> 一次入力: `devnotes/20260807-1235-inertia-error-screen-contract/recon-brief.md`

## 背景・課題

### 何が起きているか (実コードで確認済み)

aicue の Inertia 面で 4xx/5xx が発生すると、**X-Inertia ヘッダ付きの XHR に対しても
`resources/views/errors/{401,403,404,419,429,500,503}.blade.php` の素の HTML が返る**。
`bootstrap/app.php` の `withExceptions` に X-Inertia を見る分岐は 1 つも無く、
`Inertia::render('Error'` の literal 参照はリポジトリ全体で 0 件、
`resources/js/pages/Error.svelte` も存在しない (実査で確認)。

クライアント側の帰結は、インストール済みの `@inertiajs/core` 3.3.1 の実装で確定できる
(`dist/index.js`):

```js
if (!this.isInertiaResponse()) { return this.handleNonInertiaResponse(); }
...
async handleNonInertiaResponse() {
  if (this.isInertiaRedirect()) { ... }        // 409 + X-Inertia-Location
  if (this.isLocationVisit()) { ... }          // X-Inertia-Location
  ...
  if (fireHttpExceptionEvent(response)) { return dialog_default.show(response.data); }
}
isInertiaResponse() { return this.hasHeader("x-inertia"); }
isHttpException()   { return this.response.status >= 400; }
```

`x-inertia` ヘッダを持たない応答は `dialog_default.show()` = **エラーモーダルに HTML を丸ごと
流し込む**経路に落ちる。SPA の履歴も URL も動かないため、利用者はモーダルを閉じても
同じ画面に戻るだけで、**自力で次の行き先を選べない**。

一方、**status が 400 以上でも `x-inertia` ヘッダを持つ応答は `isHttpException()` を経て
そのまま `setPage()` される** = 4xx/5xx の Inertia ページは正しく画面として描画される。
これが本設計の技術的前提であり、推測ではなく実装で確認済みである。

### なぜ今これを閉じるか

踏む頻度が最も高い経路がこれである:

| status | 日常的な発生源 | 現状の利用者体験 |
|--------|--------------|----------------|
| 419 | セッション切れ後の操作 (PWA は撮影中に放置されやすい) | モーダルに素の HTML。ログインへの導線なし |
| 404 | cross-org / 削除済みリソースへの遷移 | 同上 |
| 429 | throttle 到達 | 待ち時間が本文にもヘッダにも表示されない |
| 403 | 権限不足 | 同上 |
| 500/503 | 障害・メンテ | 同上 |

使命 (North Star) は「専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れる」ことであり、
**現場作業者が画面から出られなくなる状態は使命の直接の否定**である。撮影 PWA は現場のスマホで
使われるため、開発者コンソールを開いて復帰する回避策は存在しない。

### 仮説

> **仮説**: Inertia XHR の 4xx/5xx を「x-inertia ヘッダ付きの Error ページ」へ差し替えれば、
> 利用者はモーダルではなく通常の画面として受け取り、サーバ側が固定した戻り先で必ず離脱できる。
>
> **成功条件**: (1) 差し替え対象 status で `component: 'Error'` の Inertia 応答が返り status が保存される、
> (2) 素通し必須の応答 (Inertia version mismatch 409 / Location 保持 / 3xx / api / expectsJson / admin) が
> 1 件も巻き込まれない、(3) 非 Inertia のフルロードでは既存 Blade がそのまま出る (500 の最後の砦を壊さない)。
>
> **不成功の判定**: 上記のいずれかが機械検査で固定できないなら、差し替えの適用条件が
> 「機械判定できない条件」に依存している = 設計の方向性が誤っている。

## 改善アイデア

### 骨子

例外レンダリングの**最終段**で、次の 4 条件をすべて満たす応答だけを Inertia の `Error` ページへ
差し替える。それ以外は一切触らない (**deny-by-default**)。

1. リクエストが `X-Inertia` を持つ (= SPA 遷移)
2. リクエストの `X-Inertia-Version` が**現在の asset version と一致する** (= そのタブが読み込んだ
   bundle に `Error` ページが必ず含まれる。下記「配備境界」)
3. status が**差し替え対象目録に明示登録された status** である
4. 素通し必須条件 (下記) に 1 つも該当しない

### 差し替え対象 status の正本 (v1)

| status | 文言 (利用者向け・中立) | 待ち時間 | 戻り先 | 差し替える根拠 |
|--------|----------------------|---------|--------|--------------|
| 403 | この操作を行う権限がありません | 無 | 認証状態依存 | 権限不足は利用者が別画面へ移れば継続できる。詰みにしない |
| 404 | ページが見つかりません | 無 | 認証状態依存 | cross-org / 削除済みリソース。存在を漏らさない固定文言 |
| 419 | セッションの有効期限が切れました | 無 | ログイン + トップ (**認証状態を問わない** = 戻り先規則 D1) | 最頻出。ログイン導線が無いと確実に詰む |
| 429 | しばらく時間をおいてください | 有 (`Retry-After` が非負整数のときのみ) | 認証状態依存 | 待ち時間を本文へ出す ((c) と対称) |
| 500 | 問題が発生しました | 無 | 認証状態依存 | `app.debug` が true のときは差し替えない (下記) |
| 503 | ただいまメンテナンス中です | 有 (`Retry-After` が非負整数のときのみ) | 認証状態依存 | 同上 |

**目録に入れない status とその理由** (deny-by-default なので自動的に素通し):

- `401`: web 面の `AuthenticationException` は既定処理で `/login` への 302 になり、Inertia 面に
  401 が出る経路が無い (401 は api 面 = (c) の担当)。出たら Blade にフォールバックさせる
- `409`: Inertia 手順そのもの (version mismatch / `Inertia::location()`)。差し替えたら SPA が壊れる
- `422`: バリデーション。field errors を潰すと入力が失われる
- `400 / 405 / 410 / 502 / 504` 等: aicue の web 面に発生経路が無い。必要になった時点で
  目録へ「なぜ画面へ差し替えてよいか」の根拠付きで足す

**5xx と `app.debug`**: `config('app.debug') === true` のときは 5xx を差し替えない。
開発時に Laravel の例外詳細ページを Inertia の中立文言で潰すと、原因調査の手段を失うため
(Inertia 公式レシピも local/testing を除外している)。4xx は debug でも差し替える (詳細が無いため)。

### 配備境界: 旧 asset を保持したタブへ `Error` を返さない

`resources/js/inertia.ts` の resolver は未解決ページで throw する。したがって
**`Error.svelte` を含まない bundle を読み込んだままのタブ**へ `component: "Error"` を返すと、
ページ解決が失敗して SPA が無反応になる (= 今日のモーダルより悪化する)。しかもこの母集団は
「長時間開きっぱなしのタブ」= 419 を最も踏みやすい母集団と強く相関する。

Inertia の version mismatch (`Middleware::handle()` の `onVersionChange`) は
**GET のみ**が対象であり、かつテナント guard 404 のように `HandleInertiaRequests` が走る前に
例外が出る経路では働かない。したがって version mismatch には依存できない。

対処は**サーバ側の判定 1 つ**で閉じる:

> リクエストの `X-Inertia-Version` が現在の asset version と一致するときだけ差し替える。

一致する = そのタブは現在の build から asset を読み込んでいる = その bundle には
`Error.svelte` が含まれている (同一 build で出荷するため)。一致しないタブには従来どおり
Blade を返す = **今日と同じ挙動**であり、悪化しない。GET なら version mismatch が
409 + full reload で自動的に新 bundle へ載せ替える。

asset version の取得は `app(HandleInertiaRequests::class)->version($request)` を使う。
`Inertia::getVersion()` は `HandleInertiaRequests::handle()` が走った後でないと空文字になり、
テナント guard 404 のように middleware より前で例外が出る経路で誤って不一致になるため使わない。

**nullable の扱い (deny-by-default)**: `Middleware::version()` の戻りは `?string` であり、
`null === null` は「同じ build を使っている」証明にならない (versioning 無効・manifest 不在・
テスト環境で version が生成されない状態が、すべて「一致」に化ける)。よって比較は
**両辺が非空文字列のときだけ成立**とする:

```php
is_string($requestVersion) && $requestVersion !== ''
    && is_string($currentVersion) && $currentVersion !== ''
    && $requestVersion === $currentVersion
```

片方でも `null` / 空文字なら差し替えない (= Blade のまま = 今日の挙動)。
帰結として **version を持たない環境では本機能が無効になる**が、これは fail-safe な既定である
(`public/build/manifest.json` は `@vite` の前提でもあるため、production で version が空になる
状態は Inertia 面がそもそも動かない状態と等価)。テストでは `config(['app.asset_url' => …])` を
設定して version を決定的な非空文字列にし、build 生成物への依存を持ち込まない。

**判定と生成の全体を fail-safe で包む**: version 解決 (manifest 読み) 自体が throw しうるため、
try/catch の範囲は `toResponse()` だけでなく **version 取得 → 目録判定 → route 解決 →
DTO 生成 → `toResponse()` → ヘッダ移植の全段**とする。どの段の `Throwable` でも `null` を返し、
原応答 (Blade) をそのまま残す。

この判定は**恒久的な不変条件**であり、一時的な配備フラグや二段階配備の運用手順を持ち込まない
(思考原則 2「今必要なものだけ作る」/ 3「後方互換の並走を残さない」)。

副次効果として、`tests/Feature/Security/TenantBoundaryPrecedenceTest.php` の
「Inertia version mismatch (409 契機)」ケースは `X-Inertia-Version: stale-version` を送るため
**差し替え対象外**になり、既存テストは無改修で green のままになる (後述の正規化は不要になる)。

### 素通し必須条件 (適用順の正本)

| # | 条件 | 理由 |
|---|------|------|
| P1 | status < 400 | 3xx (Fortify Response / `back()->with('error')` / `redirect()->intended()`) を壊さない |
| P2 | `api/*` または `expectsJson()` | (c) の封筒 JSON が差し替えに食われる |
| P3 | admin panel 配下 | 運営者向け中立テンプレート (実装済み) が食われる |
| P4 | `X-Inertia-Location` または `Location` ヘッダを持つ | `Inertia::location()` (409) と外部遷移が壊れる |
| P5 | status が目録未登録 (409 / 422 を含む) | version mismatch の資産再読込・バリデーションエラーの field errors を壊さない |

P1〜P3 は**既存の `$exceptions->respond()` の早期 return をそのまま使う**。
P4/P5 は新設する。

### 「戻り先はサーバ側に固定した許可一覧から出す」

裁定の要求どおり、戻り先に**リクエスト入力を一切混ぜない**。既存の
`App\Support\Http\SameOriginPath` は referer / intended を正規化して**通す**ヘルパであり、
入力由来である以上この要件を満たさない (実査の指摘どおり)。したがって専用の固定表を新設する。

**分岐の入力は `status` と認証状態の 2 つだけ**とし、リクエスト由来の値は一切読まない。
適用順序 (上が優先) も正本として固定する:

| # | 条件 | 戻り先 | 根拠 |
|---|------|--------|------|
| D1 | status が 419 | ログイン (`route('login')`) / トップ (`/`) | **認証状態を問わない**。419 は CSRF token 不一致でも起きるため「認証済みのまま 419」がありうるが、その状態でダッシュボードへ戻しても同じ token 不一致を踏み直すだけで詰みが再生産される。セッションを取り直せる導線 (ログイン) が唯一の確実な脱出路 |
| D2 | 認証済み | ダッシュボード (`route('dashboard')`) / トップ (`/`) | ログイン後シェルへ確実に戻す |
| D3 | 未認証 | ログイン (`route('login')`) / トップ (`/`) | 未認証で到達できる入口 |

戻り先の解決に referer・intended・query・route parameter を読まない = open redirect も
存在オラクルも構造的に起こり得ない。**「認証済み 419 もログインへ倒れる」ことは
全 status × 認証状態のテストで固定する** (D1 が D2 より先であることの behavioral な証明)。

**遷移方式は全 status で「通常の `<a href>` によるフル document navigation」に固定する**。
Inertia の `Link` / `router.visit()` は同じ document を保ったまま遷移するため、
419 の原因が「開いている document が保持する古い CSRF token と現在の session の不一致」だった場合、
遷移後の POST で同じ 419 を踏み直す。D1 が意図した「セッションと token を取り直す」は
**document を作り直して初めて成立する**。

status 別に遷移方式を出し分けると props (`navigation: 'hard'` 等) が増え、
Error 画面という最も壊れにくくあるべき面に条件分岐が入る。全 status で通常の `<a>` に統一する方が
小さく、かつエラー時に SPA の操作感を優先する理由も無い。

実装上は `Button` atom の anchor モードを **`inertia` prop を渡さずに**使う
(`resources/js/components/atoms/Button.types.ts` の既定はネイティブ `<a>`)。
JS テストで `Error.svelte` が `@inertiajs/svelte` の `Link` を import せず、
`inertia` prop も `router.visit` も使っていないことを固定する。

**戻り先は必ず 1 件以上返る**ことを型と実装の不変条件にする (どちらの分岐も 2 件を返す)。
これは AGENTS.md 禁止事項 8 (「必須条件未充足を理由にボタンを disabled にする UI」の禁止) の
帰結でもある: Error 画面の CTA は「戻り先が決まらないから押せない」状態を持ってはならず、
`Error.svelte` に disabled 状態を実装しない。空リストが渡り得ないことを型 (非空前提の DTO) と
テスト (全 status × 認証状態で 1 件以上) の両方で固定する。

### 待ち時間 (429)

`Retry-After` を**非負整数のときだけ**採り、それ以外は非表示にする。パーサは新設の共有 SoT に
一本化し、既存の `ApiExceptionRenderer::rateLimitDetails()` (HTTP-date 文字列をそのまま素通しする)
も同じ SoT へ寄せる。app/ 配下に HTTP-date 形式の `Retry-After` を発行する箇所は 0 件
(実査で grep 確認済み。発行元は Laravel の `ThrottleRequests` = 常に非負整数秒) であり、
**現在の正規発行経路では (c) の実挙動は不変**、不正形式 (負数 / HTTP-date / 任意文字列) は
意図的に非表示へ**厳格化**する。思考原則 3 (後方互換の並走を残さない) に従い、
同じヘッダの解釈を 2 つ残さない。

解釈の一本化はヘッダにも及ぶ。差し替え後の応答に載せる `Retry-After` は
**`RetryAfterSeconds::parse()` が成功したときだけ、正規化した整数値を設定する**
(パース不能なら載せない)。本文表示 / API の `details.retry_after` / HTTP ヘッダの三者が
同じ SoT を通る形にする。

### 既存の作法に載せる (先人の知恵)

- 差し替えロジックは `bootstrap/app.php` に直書きせず `app/Exceptions/InertiaExceptionRenderer.php`
  へ切り出す。**既存の `ApiExceptionRenderer` と対称**になり、かつ
  `tests/Architecture/InertiaRenderPageExistsInvariantTest.php` の走査対象 (`app/` + `routes/`) に
  入るため「参照先ページが実在する」gate が効く (bootstrap/ は走査対象外)。
- 差し替え対象 status と素通し理由は**型付き enum + 30 文字以上の根拠**の目録にし、
  `tests/Architecture/` の deny-by-default gate で固定する
  (`ThrottleCoverageInventoryTest` / `QueuedJobLeaseInventoryTest` と同じ作法)。

### ⚠ 最重要の実装上の制約 (実査より深い発見)

`$exceptions->respond()` は Laravel の `Handler::respondUsing()` に落ち、実体は

```php
protected $finalizeResponseCallback;          // Handler.php:149
public function respondUsing($callback) { $this->finalizeResponseCallback = $callback; }  // :751
```

= **単一スロットの last-write-wins** である。`$exceptions->respond()` を 2 本目として追加すると、
**既存の admin 中立テンプレート差し替え (`bootstrap/app.php:360`) が黙って無効化される**。
しかも既存の `tests/Feature/Errors/ErrorPagesTest.php` の admin 検査は `view()->render()` を
直接叩いており HTTP 経路を通らないため、**この退行をテストが検出しない**。

同じ理由で、`inertia-laravel` v3 が用意している `Inertia::handleExceptionsUsing()`
(`ResponseFactory.php:397` → 内部で `$handler->respondUsing(...)`) も**採用しない**。
これも同一スロットを奪うため、登録順に依存した壊れ方をする。

したがって本設計は「**既存の単一 respond callback を拡張し、その中で admin → Inertia の順に
分岐させる**」形を採る。加えて「単一スロットを奪う登録が 1 箇所しか無いこと」を Architecture gate で
固定し、将来の 2 本目追加を機械的に落とす。gate の検出対象は `$exceptions->respond(` だけでは
不十分で、**同一スロットを奪う 3 つの入口すべて**を走査する:

| 入口 | 実体 |
|------|------|
| `->respond(` | `Exceptions::respond()` → `Handler::respondUsing()` |
| `->respondUsing(` | `Handler::respondUsing()` の直呼び |
| `handleExceptionsUsing(` | `Inertia\ResponseFactory::handleExceptionsUsing()` → 内部で `respondUsing()` |

走査対象は `app/` + `bootstrap/` + `routes/` + `config/` とし、許可される登録箇所は
`bootstrap/app.php` の既存 1 箇所のみに固定する (それ以外に現れたら fail)。

**この gate の保証範囲は文字列走査の範囲に限られる** (変数経由の動的呼び出し・vendor 内での
別名再エクスポート・将来 Laravel が別 API を生やした場合は検出できない)。テスト名と docblock に
この限界を明記し、「gate があるから絶対に壊れない」という誤読を作らない
(`contrast-invariant.test.ts` が未検査領域を明示宣言しているのと同じ作法)。

## 期待効果

- **使命への貢献**: 現場作業者が 419/404/429/403/500 を踏んでも、画面として受け取り
  「ダッシュボードへ」「ログインへ」で必ず離脱できる。撮影 PWA での詰みが構造的に消える。
- **契約の固定**: 「差し替えてよい status」「素通しすべき応答」が目録として機械強制されるため、
  将来の例外ハンドラ改修で暗黙に壊れない (今は暗黙の 0 件状態で、契約が存在しない)。
- **既存の穴の閉塞**: respond callback 単一スロット問題を gate で固定し、admin 中立テンプレートの
  サイレント無効化という**既に踏みうる地雷**を除去する。
- **(c) との整合**: `Retry-After` の解釈が API 封筒と画面で 1 つになる。
  429 の待ち時間は「`Retry-After` が非負整数で存在するときに**表示可能になる**」であり、
  常に表示されると主張はしない (ヘッダが無い経路では非表示が正しい挙動)。

## 実装方針 (概要)

| 種別 | 対象 | 内容 |
|------|------|------|
| 新規 | `app/Exceptions/InertiaExceptionRenderer.php` | 差し替え判定 + Inertia 応答生成。ApiExceptionRenderer と対称 |
| 新規 | `app/Enums/Http/InertiaErrorScreenStatus.php` | 差し替え対象 status の型付き目録 (文言込み) |
| 新規 | `app/Enums/Http/InertiaErrorScreenPassthrough.php` | 素通し理由の型付き分類 (P1〜P5) |
| 新規 | `app/Support/Http/ErrorScreenDestinations.php` | サーバ側固定の戻り先許可一覧 (入力を混ぜない) |
| 新規 | `app/Support/Http/RetryAfterSeconds.php` | 非負整数のみ採る共有パーサ (SoT) |
| 新規 | `app/DataTransferObjects/Http/ErrorScreenData.php` | Error ページ props の DTO。`toInertiaProps(): array` が props 生成の唯一の入口 |
| 変更 | `bootstrap/app.php` | 既存の**単一** respond callback を拡張 (admin → Inertia の順)。2 本目は追加しない |
| 変更 | `app/Exceptions/ApiExceptionRenderer.php` | `Retry-After` パースを共有 SoT へ寄せる |
| 新規 | `resources/js/pages/Error.svelte` | layout 非依存・shared props 非依存の受け皿 |
| 新規 | `resources/js/types/error-screen.ts` | Error ページ props の TS 型 |
| 変更 | `resources/js/inertia.ts` | `Error` ページだけ eager glob で初期 bundle に含める (下記) |
| 新規 | Feature / Architecture テスト一式 | 下記 |

### Error ページを遅延 chunk にしない (「今日より悪くならない」の担保)

`resources/js/inertia.ts` の resolver は `import.meta.glob("./pages/**/*.svelte")` (非 eager) で、
**全ページが個別の遅延 chunk** になる。このまま `Error.svelte` を足すと、
「サーバが 500 を返す状況・デプロイ直後に古い chunk 名を要求する状況」でエラー画面自体の
chunk 取得に失敗しうる。resolver は未解決時に throw する実装なので、失敗すると SPA が例外で
止まり、**今日 (モーダル表示) より悪化する**。

対処: `Error` ページだけを eager glob で初期 bundle に含める。

```ts
const eagerPages = import.meta.glob<ResolvedComponent>("./pages/Error.svelte", { eager: true });
const pages = import.meta.glob<ResolvedComponent>("./pages/**/*.svelte");
```

`resolvePage()` は eager 側を先に引き、無ければ従来どおり遅延 loader に落とす。
初期 bundle の増分は 1 ページ分 (数 KB) で、**エラー時にネットワークへ出ないこと**が
その対価に見合う。

**gate は「ソースに `{ eager: true }` の文字列がある」だけにしない**。それでは
「Error が独立した遅延 entry に戻っていないこと」を保証できない。次の 2 段で固定する:

1. **振る舞い**: `resolvePage("Error")` が **遅延 loader を 1 度も呼ばずに**同期取得済みの
   component を返すことを単体テストで固定する (遅延 map をスパイに差し替え、
   呼ばれたら fail)。これが eager 化の本質的な性質そのもの
2. **ソース**: eager glob の対象が `./pages/Error.svelte` であることを Architecture テストで固定し、
   将来 glob パターンが広がって全ページ eager 化する退行も検出する

`pnpm build` 後の manifest / bundle graph を検査する案は**採らない**。
`pnpm test` (vitest) を `pnpm build` の生成物に依存させると、テストレーンが build レーンに
従属し、build 未実行の環境で恒常的に赤くなる (AGENTS.md の検証コマンドは各々独立に green に
できることが前提)。**この gate は「resolver が遅延 loader を呼ばない」ところまでしか保証しない**
ことをテストの docblock に明記し、保証範囲を誇張しない。

### 既存テストへの波及 (配備境界の判定で解消)

`tests/Feature/Security/TenantBoundaryPrecedenceTest.php` の
「Inertia version mismatch (409 契機) でも cross-org 実在 project と不在 id は同一 404」は、
`ResponseSignature::of()` で **body の完全一致**を要求する。Inertia の page JSON は
`url` フィールドにリクエスト URL を含むため、もし差し替えが起きれば
`/projects/{実在 id}` と `/projects/999999999` で body が必ず異なり、テストは落ちる。

しかし本設計の**配備境界の判定**（`X-Inertia-Version` が現在の asset version と一致するときだけ
差し替える）により、`X-Inertia-Version: stale-version` を送るこのテストは差し替え対象外になる。
よって **`TenantBoundaryPrecedenceTest` も `Tests\Support\ResponseSignature` も変更しない**。
Round 1 で検討していた「入力 id の正規化」は不要になったため撤回する
(安全ヘルパを緩めずに済む方が明確に良い)。

その代わり、この「無改修で green」が**偶然ではなく設計の帰結**であることを固定するため、
新規 Feature テストに「stale version の X-Inertia 要求は差し替えられない」ケースを置く。

**新規テストは正しい version ヘッダを送る必要がある**。テスト用ヘルパ
(`tests/Feature/Errors/` 内のローカル関数) が `app(HandleInertiaRequests::class)->version($request)`
相当を評価して `X-Inertia-Version` を組み立てる。既存の 11 本の X-Inertia Feature テストは
version ヘッダを送っていないため差し替え対象外となり、**すべて無改修で green のまま**である。

### 素通し契約は条件ごとにテストを分ける

「差し替えてはならない応答」は 1 本のテストにまとめず、条件ごとに独立したケースにする
(どの条件が壊れたかが失敗メッセージで一意に分かるようにする):

| ケース | 期待 | 守る条件 |
|--------|------|---------|
| `409` + `X-Inertia-Location` (Inertia::location) | 差し替えない | P4 |
| `409` (version mismatch 契機) | 差し替えない | P5 (目録未登録) |
| `302/303` + `Location` | 差し替えない | P1 |
| `4xx` + `Location` ヘッダ | 差し替えない | P4 (仮に来ても) |
| `422` (バリデーション) | 差し替えない | P5 |
| `api/*` / `expectsJson` | 封筒 JSON のまま | P2 |
| admin panel 配下 | 中立テンプレートのまま | P3 |
| 非 X-Inertia のフルロード | Blade のまま | 前提条件 1 |
| X-Inertia + stale version | Blade のまま | 前提条件 2 (配備境界) |
| X-Inertia + version ヘッダ欠落 | Blade のまま | 配備境界の nullable 規則 |
| X-Inertia + version 空文字 | Blade のまま | 同上 |
| X-Inertia + 現 version が null (asset_url 未設定 + manifest 不在) | Blade のまま | 同上 (fail-safe な既定) |
| X-Inertia + version 一致 | **差し替える** | 正のコントロール (gate の空振り検出) |
| version resolver が throw | Blade のまま (原応答と完全一致) | 全段 fail-safe |
| 5xx かつ `app.debug` = true | 既定の debug 応答のまま | 開発時の調査手段を潰さない |

### 差し替え後に保持するヘッダ (allowlist)

差し替えで生成する応答は Inertia の JSON であり、`Content-Type` / `Content-Length` /
`X-Inertia` は新しい応答が所有する。原応答のヘッダを丸ごと移植すると壊れるため、
**移植は allowlist 方式 (deny-by-default)** とする。

| ヘッダ | 移植 | 根拠 |
|-------|------|------|
| `Retry-After` | **する (正規化して再設定)** | 429/503 の機械可読な待ち時間。ただし原値をそのまま移植するのではなく、`RetryAfterSeconds::parse()` が成功したときだけ正規化済みの整数を設定する。パース不能なら**載せない** (本文・API `details.retry_after`・HTTP ヘッダの三者が同じ SoT を通る) |
| 上記以外 | しない | 必要になった時点で根拠付きで allowlist へ追加する (Architecture gate が allowlist の件数と根拠長を検査) |

なお `SecurityHeaders` / `NoStoreCacheHeadersForAuthenticatedPages` は middleware で
`$next` から返った応答に対して働くため、差し替え後の応答にも従来どおり適用される
(テナント guard 404 のように middleware より前で例外が出る経路で付かないのは**現状と同じ**であり、
`SecurityHeadersTest` の既存契約と一致する)。

### API 側 (c) の回帰テストを同一 PR に入れる

`ApiExceptionRenderer::rateLimitDetails()` を共有 SoT へ寄せる以上、挙動の変化は
主張ではなくテストで示す。正確には「**現在の正規発行経路 (Laravel の `ThrottleRequests` =
常に非負整数秒) では挙動不変**、不正形式は意図的に非表示へ**厳格化**する」である
(現行は解釈不能な文字列をそのまま `details.retry_after` に載せてしまう)。
同一 PR に API 429 の封筒 JSON contract テストを追加し、次を固定する:

| `Retry-After` | 現行 | 変更後 | 変化 |
|--------------|------|--------|------|
| `60` (int) | `60` | `60` | 不変 |
| `"60"` (整数文字列) | `60` | `60` | 不変 |
| `"0"` | `0` | `0` | 不変 |
| `"-5"` | `"-5"` (文字列のまま) | 非表示 | **厳格化** |
| `"Wed, 21 Oct 2015 07:28:00 GMT"` | 文字列のまま | 非表示 | **厳格化** |
| 未設定 | 非表示 | 非表示 | 不変 |

### Architecture gate (目録) の契約

`ThrottleCoverageInventoryTest` の作法をそのまま踏襲する:

| 検査 | 内容 |
|------|------|
| 母集団下限 | 差し替え対象 status が **6 件未満**なら fail (目録が空振りしていないか) |
| 件数上限 (exact fit) | 差し替え対象 status は **ちょうど 6 件**。増やすときは必ずこの数値を変える差分として現れる |
| 型付き分類 | 各 status に `InertiaErrorScreenStatus` の case が対応し、**30 文字以上の根拠**を持つ |
| 素通し理由の目録 | P1〜P5 が `InertiaErrorScreenPassthrough` の case として存在し、各 case が 30 文字以上の根拠を持つ。**未使用 case は fail** (死んだ分類を残さない) |
| stale 検出 | 目録にあるが enum に無い / enum にあるが目録に無い status を fail |
| ページ実在 | 目録の各 status に対して利用者向け文言が定義済みで、`resources/js/pages/Error.svelte` が実在する |
| Blade 併存 | 目録の各 status に対して `resources/views/errors/{status}.blade.php` または `{4xx,5xx}.blade.php` が実在する (非 Inertia 経路の最後の砦を消していない) |
| respond 単一スロット | 上記 3 入口の登録が `bootstrap/app.php` の 1 箇所のみ |
| 直書き禁止 | `bootstrap/app.php` に `Inertia::render(` が現れない (`InertiaRenderPageExistsInvariantTest` の網の外になるため) |

**素の main では赤にならない gate をどう受け入れるか**: 本 gate は新規契約なので、実装後の main では
必ず green になる (= 空振りと区別が付かない)。よって
`InertiaRenderPageExistsInvariantTest` と同じく **負のコントロール**を必ず併置し、
「検出器が実際に点灯すること」を fixture で固定する。加えて実装時には
mutation (目録から 1 status を削る / passthrough 条件を 1 つ外す / respond を 2 本にする /
`Error.svelte` を消す) で gate が赤くなることを手で確認し、その手順と結果を
詳細設計のテスト計画に書く。

### 型境界 (PHPStan level 10 を成功条件にするため概念段階で固定する)

| 対象 | 型 |
|------|----|
| `InertiaExceptionRenderer::render()` | `(SymfonyResponse $response, Request $request): ?SymfonyResponse` — 差し替えないときは `null` |
| Inertia 応答の生成 | renderer 内で `Inertia::render(...)->toResponse($request)` まで完了させ、`setStatusCode()` で原 status を保存する (`Inertia\Response` は `Responsable` であり Symfony Response ではないため、finalize callback へ直接返さない) |
| fail-safe | `toResponse()` を含む生成全体を `try { … } catch (Throwable) { return null; }` で包み、原応答 (Blade) を残す |
| `ErrorScreenData::$status` | `InertiaErrorScreenStatus` (enum) |
| `ErrorScreenData::$retryAfterSeconds` | `int<0, max>|null` |
| `ErrorScreenDestinations::for()` | `(InertiaErrorScreenStatus $status, bool $authenticated): non-empty-list<ErrorScreenDestination>` — **status を必ず受け取る** (D1 の 419 override があるため、認証状態だけを受ける型にしない) |
| `ErrorScreenData::$destinations` | `non-empty-list<ErrorScreenDestination>` (PHPDoc)。コンストラクタで空配列を `Assert::minCount()` 相当で拒否する |
| `ErrorScreenData::toInertiaProps()` | `array{status: int, title: string, message: string, retryAfterSeconds: int<0, max>|null, destinations: non-empty-list<array{label: string, href: string}>}` — props 生成の**唯一**の入口 |
| `RetryAfterSeconds::parse()` | `(mixed $value): ?int` (戻りは `int<0, max>|null`) |
| TS 側 `resources/js/types/error-screen.ts` | 上記 array shape と 1:1 の `readonly` interface。`ErrorScreenProps` / `ErrorScreenDestination` |

## 制約・前提

- **非 Inertia 経路は既存 Blade を維持する**。`resources/views/errors/*.blade.php` は
  `@vite` / Inertia / DB に依存しない自己完結契約 (`ErrorPagesTest` が固定) で、Vite が壊れた
  500 の最後の砦である。Inertia 化で置き換えず**併存**させる。
- **`AuthenticationException` の render callback を先に走らせる**契約 (`Inertia::clearHistory()` の
  副作用 + null 返しで既定処理へ委譲。AGENTS.md ドメイン規約 3 / `InertiaHistoryGuardTest`) を壊さない。
  `respond()` は `Handler::finalizeRenderedResponse()` = render callbacks の**後**に必ず走るため、
  順序は構造的に保証される。加えて既定処理の結果は `/login` への 302 = P1 で素通しになる。
- **shared props に依存しない**。例外はテナント guard 404 のように `HandleInertiaRequests` が
  走る**前**にも発生する。その場合 `Inertia::share()` は未実行であり、`appName` すら props に無い。
  よって `Error.svelte` は `AppLayout` / `AuthLayout` / `GuestLayout` のいずれも import せず
  (前 2 者は `page-shell-structure.test.ts` の契約が掛かる、`GuestLayout` は `appName` を要求する)、
  必要な値をすべて明示 props で受ける。
- **Error ページのレンダリングで新たな例外を出さない**。props はスカラーのみ (DB アクセス無し)。
  それでも生成中に throw した場合は元の応答 (Blade) を返す fail-safe を置き、
  「今日より悪くならない」ことを保証する。
- フロント側 gate (`ds-purity` / `typography-invariant` / `contrast-invariant` /
  `svelte-head-no-title` / `atomic-import-graph` / `page-shell-structure` /
  `pages-path-case-invariant` / `svelte-no-undef-gate`) が新規ページに掛かる。
  DS token と named typography ramp のみを使い、hex 直書き・inline SVG を持ち込まない。
- 実装は worktree で行い、`composer test` / `composer phpstan` / `pnpm test` 他が全 green。

## スコープ外

### 1. 裁定 (a) 「人が使うフォーム POST のフォーム内エラー」 — 今回は実装しない

**理由**: 裁定自体が「『人が使うフォームか』を機械判定できないため必須化せず**推奨に留める**」と
明記しており、必須の不変条件ではない。aicue の現状は named limiter に `->response(` が 0 件で、
入れるなら 302 + `back()->withErrors()` を limiter ごとに個別裁量で選ぶことになる。
機械強制できない選定を (b) と同じ PR に混ぜると、(b) の deny-by-default gate の輪郭がぼやける。

**ただし対象選定の判断だけは残す** (次に着手する人が 0 から考え直さないため):

| 候補 limiter | (a) 適用の可否 | 判断根拠 |
|-------------|--------------|---------|
| `login` / `two-factor` / `password-reset-*` | **適格** | 未認証の人手フォーム。429 で画面ごと飛ばすと入力が消え、再入力コストが最大。`back()->withErrors()` で入力保持できる |
| `social-callback` | 不適 | IdP からのリダイレクト着地で、人が入力したフォームではない |
| `api/*` の limiter 群 | 不適 | (c) の封筒 JSON が正しい応答形。機械向け経路 |
| `oauth/*` / `.well-known/oauth-*` | 不適 | 同上 (ステートレスな機械向け経路) |
| `recent-auth.password` の inline `throttle:6,1` | 保留 | inline throttle は actor 単位で全 route が 1 bucket を共有する (AGENTS.md §流量制限)。応答を変えるなら named limiter への切り出しが先 |

つまり (a) を着手するなら **`login` / `two-factor` / `password-reset-request` /
`password-reset-submit` の 4 本に限定**するのが妥当、という選定までを本設計の結論とする。
実装は別 TODO とする。

### 2. 裁定 (c) 「プログラム向け経路の封筒 JSON」 — 実装済みのため追加なし

`ApiExceptionRenderer` + `ApiErrorResource` で実装済み。本設計で触るのは
`Retry-After` パーサの SoT 統合のみ (**現在の正規発行経路では不変**、
不正形式は意図的に非表示へ厳格化する)。

### 3. 共通規約「運営者向けと利用者向けの文面分離」 — 実装済み

`bootstrap/app.php:360` の respond callback + `resources/views/errors/admin/{4xx,5xx}.blade.php` で
実装済み (台帳 note の「中立な文面の静的な 429 ページのみ」は実コードと食い違っている)。
本設計は**これを壊さないこと**を制約として扱うだけで、追加の文面分離は行わない。

### 4. `resources/views/errors/layout.blade.php` の戻り先変更

現状の `<a class="home" href="/">` は既にサーバ側固定 (リクエスト入力を混ぜていない) で、
裁定の要件を満たしている。Blade 側は「Vite もセッションも当てにできない最後の砦」であり、
ここに認証状態判定 (session 読み) を持ち込むと自己完結契約が弱まる。よって変更しない。

### 5. SSR / エラーページの多言語化 / Retry-After の Blade 側表示

いずれも本改善の成功条件に含まれない。オーバーエンジニアリング禁止 (思考原則 2)。

### 6. 例外の観測性 (ログ・アラート) の変更

差し替えはレンダリング層のみで、`report()` 経路には触れない。
