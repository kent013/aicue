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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上記に挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 補足コンテキスト（実査で確認済みの事実。推測ではない）

- 環境: PHP 8.4 / Laravel 12 / Inertia.js (inertiajs/inertia-laravel v3.1.0 + @inertiajs/core 3.3.1 + @inertiajs/svelte 3.x) / Svelte 5 runes / TypeScript / PHPStan level 10 / Pest (RefreshDatabase グローバル適用 + --parallel)
- `bootstrap/app.php` の `withExceptions` 現状:
  1. `$exceptions->shouldRenderJsonWhen(fn ($r) => $r->is('api/*') || $r->expectsJson())`
  2. `$exceptions->render(AuthenticationException ...)` — `Inertia::clearHistory()` を副作用として実行し **null を返して既定処理へ委譲**
  3. `$exceptions->render(QuotaExceededException ...)` / `InsufficientTicketsException` — web は `back()->with('error', ...)`、expectsJson は JsonResource
  4. `$exceptions->render(Throwable ...)` → `ApiExceptionRenderer::render()` (api/* のみ封筒 JSON)
  5. `$exceptions->respond(...)` — status>=400 かつ api/expectsJson でなく admin panel 配下のときだけ `errors.admin.{4xx,5xx}` に差し替える（**現在ここが唯一の respond callback**）
- Laravel の `Illuminate\Foundation\Exceptions\Handler` は `protected $finalizeResponseCallback;` を持ち、`respondUsing($callback)` は単純代入。`finalizeRenderedResponse()` が render callbacks の後に 1 回だけ呼ぶ。= respond は単一スロット・last-write-wins。
- `@inertiajs/core` 3.3.1 の実装（dist/index.js）: `isInertiaResponse()` は `x-inertia` ヘッダの有無のみ。ヘッダが無ければ `handleNonInertiaResponse()` → `dialog_default.show(response.data)`（エラーモーダル）。ヘッダがあれば status>=400 でも `isHttpException()` イベント発火の後 `setPage()` される。
- `tests/Architecture/InertiaRenderPageExistsInvariantTest.php` の走査対象は `app/` と `routes/` のみ（`bootstrap/` は含まない）。
- `tests/Architecture/ThrottleCoverageInventoryTest.php` が aicue の deny-by-default 目録 gate の見本（型付き enum + 30 文字以上の根拠 + exact-fit の cap + 母集団下限 + stale 検出 + 死んだ免除の検出）。
- `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` は `Tests\Support\ResponseSignature::of()` で status + 正規化ヘッダ + body の完全一致を比較する。
- `app/Support/Http/SameOriginPath.php` は referer / intended を同一オリジン相対 path へ正規化して**通す**ヘルパ（入力由来）。
- `app/Exceptions/ApiExceptionRenderer.php` の `rateLimitDetails()` は `Retry-After` が ctype_digit の文字列なら int 化、そうでない文字列/int はそのまま素通しする。
- `resources/views/errors/*.blade.php` は `@vite` / Inertia / DB に依存しない自己完結契約（`tests/Feature/Errors/ErrorPagesTest.php` が固定）。


## 概念設計

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

例外レンダリングの**最終段**で、次の 3 条件をすべて満たす応答だけを Inertia の `Error` ページへ
差し替える。それ以外は一切触らない (**deny-by-default**)。

1. リクエストが `X-Inertia` を持つ (= SPA 遷移)
2. status が**差し替え対象目録に明示登録された status** である
3. 素通し必須条件 (下記) に 1 つも該当しない

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
入力由来である以上この要件を満たさない (実査の指摘どおり)。したがって専用の固定表を新設し、
分岐の入力は**認証状態のみ**とする:

- 認証済み: ダッシュボード (`route('dashboard')`) / トップ (`/`)
- 未認証 (419 のセッション切れを含む): ログイン (`route('login')`) / トップ (`/`)

戻り先の解決に referer・intended・query・route parameter を読まない = open redirect も
存在オラクルも構造的に起こり得ない。

### 待ち時間 (429)

`Retry-After` を**非負整数のときだけ**採り、それ以外は非表示にする。パーサは新設の共有 SoT に
一本化し、既存の `ApiExceptionRenderer::rateLimitDetails()` (HTTP-date 文字列をそのまま素通しする)
も同じ SoT へ寄せる。app/ 配下に HTTP-date 形式の `Retry-After` を発行する箇所は 0 件
(実査で grep 確認済み。発行元は Laravel の `ThrottleRequests` = 常に整数秒) であり、
**寄せても (c) の実挙動は変わらない**。思考原則 3 (後方互換の並走を残さない) に従い、
同じヘッダの解釈を 2 つ残さない。

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
分岐させる**」形を採る。加えて「respond callback が 1 本しか無いこと」を Architecture gate で固定し、
将来の 2 本目追加を機械的に落とす。

## 期待効果

- **使命への貢献**: 現場作業者が 419/404/429/403/500 を踏んでも、画面として受け取り
  「ダッシュボードへ」「ログインへ」で必ず離脱できる。撮影 PWA での詰みが構造的に消える。
- **契約の固定**: 「差し替えてよい status」「素通しすべき応答」が目録として機械強制されるため、
  将来の例外ハンドラ改修で暗黙に壊れない (今は暗黙の 0 件状態で、契約が存在しない)。
- **既存の穴の閉塞**: respond callback 単一スロット問題を gate で固定し、admin 中立テンプレートの
  サイレント無効化という**既に踏みうる地雷**を除去する。
- **(c) との整合**: `Retry-After` の解釈が API 封筒と画面で 1 つになる。

## 実装方針 (概要)

| 種別 | 対象 | 内容 |
|------|------|------|
| 新規 | `app/Exceptions/InertiaExceptionRenderer.php` | 差し替え判定 + Inertia 応答生成。ApiExceptionRenderer と対称 |
| 新規 | `app/Enums/Http/InertiaErrorScreenStatus.php` | 差し替え対象 status の型付き目録 (文言込み) |
| 新規 | `app/Enums/Http/InertiaErrorScreenPassthrough.php` | 素通し理由の型付き分類 (P1〜P5) |
| 新規 | `app/Support/Http/ErrorScreenDestinations.php` | サーバ側固定の戻り先許可一覧 (入力を混ぜない) |
| 新規 | `app/Support/Http/RetryAfterSeconds.php` | 非負整数のみ採る共有パーサ (SoT) |
| 新規 | `app/DataTransferObjects/Http/ErrorScreenData.php` | Error ページ props の DTO |
| 変更 | `bootstrap/app.php` | 既存の**単一** respond callback を拡張 (admin → Inertia の順)。2 本目は追加しない |
| 変更 | `app/Exceptions/ApiExceptionRenderer.php` | `Retry-After` パースを共有 SoT へ寄せる |
| 新規 | `resources/js/pages/Error.svelte` | layout 非依存・shared props 非依存の受け皿 |
| 新規 | `resources/js/types/error-screen.ts` | Error ページ props の TS 型 |
| 変更 | `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` | 応答比較を「入力 id を正規化してから」に拡張 (後述) |
| 新規 | Feature / Architecture テスト一式 | 下記 |

### 既存テストへの波及 (先に把握済み)

`tests/Feature/Security/TenantBoundaryPrecedenceTest.php` の
「Inertia version mismatch (409 契機) でも cross-org 実在 project と不在 id は同一 404」は、
`ResponseSignature::of()` で **body の完全一致**を要求する。Inertia の page JSON は
`url` フィールドにリクエスト URL を含むため、`/projects/{実在 id}` と `/projects/999999999` で
**body が必ず異なる**。したがってこのテストは本改善で機械的に落ちる。

これは存在オラクルではない (差分は攻撃者自身が与えた id の echo であり、新しい情報は 1 bit も
増えない) が、テスト側の比較器が「入力の echo」を許容していないだけである。よって
**比較の前に「そのリクエストが与えた id 文字列」だけをプレースホルダへ正規化する**形に拡張する。
正規化は id 文字列に限定し、置換が 0 件だった場合は fail させる (空振り正規化で検査を空洞化させない)。
`Tests\Support\ResponseSignature` 自体は**変更しない** (他テストが共有する安全ヘルパのため)。

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
`Retry-After` パーサの SoT 統合のみ (実挙動は不変)。

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

