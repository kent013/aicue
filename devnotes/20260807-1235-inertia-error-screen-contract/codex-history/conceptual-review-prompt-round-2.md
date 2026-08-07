Round 1 のレビューありがとうございます。指摘 6 件の Warning と 1 件の Suggestion をすべて設計へ反映しました。
以下に対応マトリクスと、修正後の概念設計の全文を示します。再レビューをお願いします。

なお Round 1 の観点 3 の [Warning]（Error.svelte の chunk）については、実際に `resources/js/inertia.ts` を読んで
「非 eager な import.meta.glob で全ページが遅延 chunk」「resolvePage は未解決時に throw」であることを確認しました。
指摘は事実として正しく、概念設計の成功条件『今日より悪くならない』に抵触していたため、resolver の変更を実装方針へ追加しています。

# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: CHANGES_REQUESTED / [Critical] 0 件・[Warning] 6 件・[Suggestion] 3 件。

## [Warning] 観点 2: `Error.svelte` の CTA を disabled 化しない契約を明記せよ (禁止事項 8)

- 判断: **対応する**
- 根拠: AGENTS.md 禁止事項 8 は aicue の明示禁止事項であり、エラー画面という「押せないと詰む」面で
  最も守られるべき。指摘は正当。
- 対応内容: 「戻り先はサーバ側に固定した許可一覧から出す」節に、
  (a) 戻り先は必ず 1 件以上返る (どちらの分岐も 2 件)、(b) `Error.svelte` に disabled 状態を実装しない、
  (c) 空リストが渡り得ないことを型とテスト (全 status × 認証状態) で固定する、を追記。

## [Warning] 観点 3: `Error.svelte` が遅延 chunk だと 500 時に画面自体が出ない

- 判断: **対応する** (指摘は事実として正しい)
- 根拠: `resources/js/inertia.ts` を確認したところ `import.meta.glob("./pages/**/*.svelte")` の
  **非 eager** glob で、全ページが遅延 chunk。しかも `resolvePage()` は未解決時に throw するため、
  chunk 取得失敗は SPA の例外停止になり、現状 (モーダル表示) より悪化する。
  概念設計の成功条件「今日より悪くならない」に直接抵触していた。
- 対応内容: 実装方針に `resources/js/inertia.ts` の変更を追加し、
  「`Error` ページだけ eager glob で初期 bundle に含める」節を新設。eager 維持を JS 側
  Architecture テストで固定することも明記。

## [Warning] 観点 3: respond 単一スロット gate の検出範囲が不足

- 判断: **対応する**
- 根拠: 指摘どおり。`Inertia::handleExceptionsUsing()` は内部で `$handler->respondUsing()` を
  呼ぶため (`ResponseFactory.php:397-430` で確認済み)、`->respond(` だけの走査では素通りする。
  gate が守るべきは「単一スロットを奪う登録が 1 箇所」であって「respond の文字列が 1 個」ではない。
- 対応内容: 検出対象を `->respond(` / `->respondUsing(` / `handleExceptionsUsing(` の 3 入口に拡張し、
  走査対象を `app/` + `bootstrap/` + `routes/` + `config/`、許可箇所を `bootstrap/app.php` の
  1 箇所のみに固定する旨を表で追記。

## [Warning] 観点 5: 素通しテストをケースごとに分けよ

- 判断: **対応する**
- 根拠: 素通し条件は P1〜P5 で守っている対象が異なり、1 本にまとめると「どの条件が壊れたか」が
  失敗メッセージから分からない。aicue の既存 gate も条件ごとにテストを分ける作法。
- 対応内容: 「素通し契約は条件ごとにテストを分ける」節を新設し、8 ケースの表を追加
  (409+X-Inertia-Location / 409 / 302 / 4xx+Location / 422 / api・expectsJson / admin / 非 Inertia)。

## [Warning] 観点 5: 応答正規化の適用範囲を「入力 id の echo」に限定せよ

- 判断: **対応する** (元設計の意図と同じだが、契約が散文で曖昧だった)
- 根拠: 指摘のとおり、ここが緩いと存在オラクル検査が空洞化する。契約は箇条書きで固定すべき。
- 対応内容: 3 点の契約 (テストローカルに置く / 置換対象は自分が入れた id 文字列のみ /
  置換 0 件は fail) を明示。`Tests\Support\ResponseSignature` は変更しないことも再確認。

## [Warning] 観点 6: `ApiExceptionRenderer` 変更は (c) の既存挙動に触れる → 回帰テストを同一 PR に

- 判断: **対応する**
- 根拠: 「実挙動は変わらない」は主張ではなくテストで示すべき、という指摘は正当。
  app/ 配下に HTTP-date 形式の `Retry-After` 発行箇所は 0 件と確認済みだが、
  それは「今そうである」に過ぎず、契約として固定されていない。
- 対応内容: 「API 側 (c) の回帰テストを同一 PR に入れる」節を新設。
  4 ケース (整数 / 整数文字列 / 解釈不能な文字列 / 未設定) を固定する。

## [Warning] 観点 7: DTO を作るだけで配列を手書きすると型安全性の恩恵が薄い

- 判断: **対応する**
- 根拠: 正当。props 生成の入口が 2 つあると DTO が飾りになる。
- 対応内容: 実装方針の表で `ErrorScreenData` に
  「`toInertiaProps(): array` が props 生成の唯一の入口」と明記。array shape の phpdoc は
  詳細設計で具体化する。

## [Suggestion] 観点 1: 方針は North Star に直接貢献する

- 判断: **見送る** (指摘ではなく肯定的評価のため対応不要)

## [Suggestion] 観点 4: 「待ち時間が表示される」は「表示可能になる」に弱めるべき

- 判断: **対応する**
- 根拠: `Retry-After` が無い 429 経路では非表示が正しい挙動であり、断定は不正確。
- 対応内容: 期待効果の (c) 整合の項に「`Retry-After` が非負整数で存在するときに表示可能になる」
  と補足。

## [Suggestion] 観点 6: (a)(c) を混ぜない判断は適切

- 判断: **見送る** (肯定的評価のため対応不要)


---

# 修正後の概念設計（全文）

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

**戻り先は必ず 1 件以上返る**ことを型と実装の不変条件にする (どちらの分岐も 2 件を返す)。
これは AGENTS.md 禁止事項 8 (「必須条件未充足を理由にボタンを disabled にする UI」の禁止) の
帰結でもある: Error 画面の CTA は「戻り先が決まらないから押せない」状態を持ってはならず、
`Error.svelte` に disabled 状態を実装しない。空リストが渡り得ないことを型 (非空前提の DTO) と
テスト (全 status × 認証状態で 1 件以上) の両方で固定する。

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
| 変更 | `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` | 応答比較を「入力 id を正規化してから」に拡張 (後述) |
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
その対価に見合う。「eager に載っていること」は JS 側の Architecture テストで固定する
(将来 resolver をリファクタして黙って遅延へ戻る退行を防ぐ)。

### 既存テストへの波及 (先に把握済み)

`tests/Feature/Security/TenantBoundaryPrecedenceTest.php` の
「Inertia version mismatch (409 契機) でも cross-org 実在 project と不在 id は同一 404」は、
`ResponseSignature::of()` で **body の完全一致**を要求する。Inertia の page JSON は
`url` フィールドにリクエスト URL を含むため、`/projects/{実在 id}` と `/projects/999999999` で
**body が必ず異なる**。したがってこのテストは本改善で機械的に落ちる。

これは存在オラクルではない (差分は攻撃者自身が与えた id の echo であり、新しい情報は 1 bit も
増えない) が、テスト側の比較器が「入力の echo」を許容していないだけである。よって
**比較の前に「そのリクエストが与えた id 文字列」だけをプレースホルダへ正規化する**形に拡張する。
契約は次の 3 点で、これを緩めると存在オラクル検査が空洞化する:

1. 正規化 helper は `TenantBoundaryPrecedenceTest.php` の**テストローカル**に置く
   (`Tests\Support\ResponseSignature` は他テストが共有する安全ヘルパなので**変更しない**)
2. 置換対象は「そのリクエストの URL に自分で入れた id 文字列」のみ。他の差分は 1 文字も許さない
3. 置換件数が 0 件なら fail (正規化が空振りしたまま green になる状態を作らない)

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
| 非 X-Inertia のフルロード | Blade のまま | 前提条件 |

### API 側 (c) の回帰テストを同一 PR に入れる

`ApiExceptionRenderer::rateLimitDetails()` を共有 SoT へ寄せる以上、「実挙動は変わらない」は
主張ではなくテストで示す。同一 PR に API 429 の封筒 JSON contract テストを追加し、
`Retry-After` が (整数 / 整数文字列 / 解釈不能な文字列 / 未設定) の 4 ケースで
`details.retry_after` がどうなるかを固定する。

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

