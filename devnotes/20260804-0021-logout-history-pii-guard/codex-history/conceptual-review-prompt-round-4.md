# Round 4: 対応マトリクスと修正後の概念設計

Round 3 の [Warning] (UX コスト表現の不統一) に対応しました。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 3

## [Warning] 4. グローバル適用の説明に旧記述 (「追加コストは再取得 1 回だけ」) が残存し設計内で矛盾
- 判断: 対応する
- 根拠: 指摘のとおり。「引き換えに払う UX コスト」節では
  再取得 + rememberedState + スクロール位置の喪失と正しく書いているのに、
  実装方針 1 の注記だけ旧表現が残っており、読み手に軽く見せてしまう。
- 対応内容: 実装方針 1 の該当箇所を
  「再取得 1 回に加えて rememberedState とスクロール位置も失われるが、
   影響はログアウト前に作られた履歴エントリに限定され、この UX コストを許容する」
  に統一した (上位節の許容判断へ参照を張る形にした)。

## [Suggestion] 1/2/3/5/6/7
- Round 2 の Critical (別タブ) / Warning (背景の主語) は解消と確認された。追加対応なし。


---

## 修正後の概念設計 (全文)

# 概念設計: logout-history-pii-guard (F-4-01)

出典: `devnotes/20260803-203721-bug-hunt/report.md` の **F-4-01 (Critical)** /
shard レポート `devnotes/20260803-203721-bug-hunt/shard-4/shard-report.md#F-4-01`

## 背景・課題

### 観測された事象 (bug-hunt shard-4, 2 回独立再現)

1. `owner-starter@example.com` でログイン → `/manage/users` → `/dashboard` (Inertia SPA 遷移)
2. サイドバーのユーザーメニューから「ログアウト」 (`POST /logout` → 302 → `GET /`)
3. ブラウザの「戻る」
4. **`/dashboard` がログアウト前の PII (ユーザー名・組織名・チケット残高) 込みで可視状態のまま復元される**。
   `GET /session/status` は一度も発火しない (requests ログ 0 件)。console エラーも 0 件
   (= guard が「判定不能で秘匿維持」にすら入っていない)。

サーバ側の認可自体は健全 (復元後にリンクを押すとサーバに飛んで `/login` へ落ちる)。
問題は **押す前に PII が画面に見えていること** そのもの。

### なぜ既存の 2 枚の網を抜けるのか (ソースで裏取り済み)

| 既存の網 | 実体 | なぜ効かないか |
|---|---|---|
| サーバ no-store baseline | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` | 正しく `no-store, private` を付けている。しかし今回の復元は **サーバへ一切リクエストが行かない**ため無関係 |
| クライアント bfcache guard | `resources/js/lib/bfcache-guard.ts` | 購読しているのは `window` の `pagehide` / `pageshow` のみ。復元マーカーも documentElement 属性。**Inertia 自身の history キャッシュによる `popstate` 復元は設計上スコープ外**で、guard は一度も起動しない |

ログアウトは `resources/js/components/templates/AppLayout.svelte:156-170` の
`router.post("/logout")` = **Inertia の SPA visit** であり、フルドキュメント遷移ではない。
以降の「戻る」も `popstate` で完結し、`pagehide` / `pageshow` は発火しない。

すなわち **Inertia 面において、本件で扱う認証済み画面の復元経路は 3 本あり、
既存設計は 2 本しか塞いでいない** (Filament `/admin` 等の非 Inertia 面はこの整理の対象外):

| # | 経路 | 現状 |
|---|---|---|
| A | HTTP / disk / proxy cache、および Chrome・Firefox の bfcache (no-store で拒否 / evict) | 塞いでいる (サーバ baseline) |
| B | Safari の真の bfcache 復元 (`pagehide`/`pageshow`) | 塞いでいる (bfcache-guard.ts) |
| C | **Inertia SPA のクライアント履歴復元 (`popstate`)** | **塞いでいない ← F-4-01** |

### 契約違反

- `docs/supported-browsers.md` と `AGENTS.md` ドメイン固有規約 #3 は
  「サーバ no-store baseline + クライアント bfcache 秘匿・再検証の**セット**で守る」と宣言している。
  この宣言が経路 C を取りこぼしている (= 文書が保証していない穴を保証しているかのように読める)。
- bug-hunt ストーリー `stories/S6-security-2fa-profile.md` 手順 5 の契約そのものの破れ。

## 改善アイデア

**経路 C を、自前機構を足さずに Inertia 公式の history 機構で塞ぐ。**

`inertiajs/inertia-laravel` v3.1.0 (`composer.lock` で確認) と `@inertiajs/core` 3.3.1 は、
まさにこの用途の機構を同梱している:

1. **history 暗号化** — `Inertia\Middleware\EncryptHistory` (= `Inertia\EncryptHistoryMiddleware`)。
   これを通した応答は page オブジェクトに `encryptHistory: true` を載せ、クライアントは
   `history.pushState` / `replaceState` に **AES-GCM で暗号化した ArrayBuffer** を保存する
   (`@inertiajs/core` `src/history.ts` `getPageData()` → `src/encryption.ts`)。
   鍵と IV は `sessionStorage` (`historyKey` / `historyIv`)。
2. **history 鍵の破棄** — `Inertia::clearHistory()` (`ResponseFactory::clearHistory()`)。
   session に `inertia.clear_history` を立て、次の Inertia 応答が `clearHistory: true` を返す。
   クライアントは `page.set()` 冒頭で `history.clear()` を呼び、`sessionStorage` の鍵と IV を削除する
   (`src/page.ts` L78-80 → `src/history.ts` `clear()`)。
3. **復号失敗時の挙動** — `popstate` で `history.decrypt(state.page)` が reject すると
   `eventHandler.onMissingHistoryItem()` が走り、`router.visit(location.href, { replace: true })` で
   **サーバに問い合わせ直す** (`src/eventHandler.ts` L81-131 / `src/router.ts` L86-90)。
   **復号に失敗した時点でコンポーネントの swap は行われない**ため、
   **PII は一度も描画されない**まま `/login` へ倒れる (auth ミドルウェアの 302 を XHR が追う)。

つまり「認証済みページの履歴を暗号化しておき、ログアウト時に鍵を捨てる」だけで、
戻る操作は **描画前にサーバ再問い合わせ → login** に倒れる。追加のクライアント機構は要らない。

### 具体策 (3 点だけ)

| # | 施策 | 変更点 |
|---|---|---|
| 1 | history 暗号化を有効化 | `bootstrap/app.php` の `$middleware->web(append: [...])` に `Inertia\Middleware\EncryptHistory` を追加 (Inertia 公式のグローバル適用手順) |
| 2 | ログアウト時に history 鍵を捨てる | Fortify の `LogoutResponse` contract をアプリ実装に差し替え (既存 10 本の Fortify response 差し替えと同じパターン) し、`Inertia::clearHistory()` を呼んでから **`route('home')` へ固定リダイレクト** |
| 3 | 契約文書の更新 | `docs/supported-browsers.md` / `docs/testing-browser.md` / `AGENTS.md` ドメイン固有規約 #3 を「3 経路 × 3 枚の網」に書き換える。**主語は Inertia 面に限定**し、残存リスクを分離記載する |

### この設計が保証する範囲 (主語をここで固定する)

保証対象は **「Inertia が描画する認証済み画面」× 「ログアウトを実行したタブ」× 「`popstate` による履歴復元」**。
この 3 条件を外れるものは後述の「スコープ外 / 残存リスク」に分離し、文書でも同じ粒度で書く
(実装より広い保証を文書に書かない)。

### 既存 `bfcache-guard.ts` をどうするか — **残す (捨てない)**

思考原則 3 (後方互換の並走を残さない) に照らして検討した結果、**併存ではなく責務分割**と判断する。

- Inertia の公式機構は `pageshow(persisted)` も購読している (`src/eventHandler.ts` L75-79) が、
  そこでやるのは **復号 → 失敗なら再問い合わせ** であり、**非同期**である。
  真の bfcache 復元ではブラウザが復元 DOM を**即座に描画する**ため、
  再問い合わせ完了までの間 **PII が実際に画面に出る**。
- `bfcache-guard.ts` の設計要件は「復元後に検証」ではなく **「検証完了まで秘匿」** であり、
  `pagehide` で **同期的に** 秘匿属性を立てて DOM ごと bfcache に入れる点が本質。
  これは Inertia 側では代替できない (公式機構は pagehide を持たない)。
- したがって両者は**同じ問題の二重実装ではなく、別経路の担当**である:

| 経路 | 担当 | 保証内容 |
|---|---|---|
| A: HTTP/disk/proxy cache, Chrome/Firefox bfcache | `NoStoreCacheHeadersForAuthenticatedPages` | 格納拒否 / evict |
| B: Safari の真の bfcache | `bfcache-guard.ts` + `/session/status` | **描画前に同期秘匿**し、有効なら秘匿解除のみ (hard reload しない) |
| C: Inertia SPA history (`popstate`) | `EncryptHistory` + `Inertia::clearHistory()` | **復号不能 → swap せず再問い合わせ → login** |

- 経路 B で両者が同時に走るケース (ログアウト後の真の bfcache 復元) は、
  guard が `location.replace('/login')`、Inertia が XHR 再訪問を行い、**着地は共に `/login`** で一致する。
  hard navigation が XHR を打ち切るだけで、矛盾した状態には落ちない。
- `bfcache-guard.ts` に `popstate` フックを**足さない**。経路 C はフレームワークが持つ機構で足りており、
  足すと同一問題の二重実装 (原則 3 違反) になる。

### 撮影 PWA の制約を壊さないこと

`bfcache-guard.ts` の docblock が明記する制約 — **hard reload を常用しない**
(撮影中の media stream・未送信フォーム・Inertia 履歴を壊す) — は維持される:

- 施策 1/2 が働くのは **鍵が消えた後 = ログアウト後の履歴復元時だけ**。
  ログイン中の通常の戻る/進むは復号に成功し、従来どおり client-side で完結する
  (再取得も hard reload も起きない)。
- 施策 1/2 は撮影画面 (`/app/*`) の DOM や media stream に触れない。

## 期待効果

- **使命への貢献**: 撮影 PWA は現場の共有端末・iOS Safari が主戦場であり、
  「ログアウトしたら自分の業務データが他人に見えない」は導入時に必ず問われる最低条件。
  Critical のセキュリティ後退を塞ぐことは、現場導入の前提を守ることそのもの。
- **具体的な改善見込み**:
  - F-4-01 の再現手順 (Chromium で再現可能) で PII が一切描画されなくなる。
  - 経路 C は**フレームワーク標準の仕組み**で塞がるため、今後 Inertia 側の history 実装が
    変わっても追随コストを自前で負わない。
  - 文書 (`docs/supported-browsers.md` / `AGENTS.md` #3) の保証範囲が実装と一致する。

### 引き換えに払う UX コスト (許容判断)

`Inertia::clearHistory()` が消すのは `sessionStorage` の鍵だけで、`window.history` のエントリ自体は残る。
復号不能になったエントリへ戻ると `router.visit(..., replace: true)` でサーバから取り直すため、
**そのエントリの再取得 1 回に加えて `rememberedState` (フォーム状態) とスクロール位置も失われる**。

許容できると判断する根拠:

- 失われるのは **ログアウト以前に作られた履歴エントリ**だけ。ログアウト後のエントリは
  新しい鍵で暗号化され、通常どおり client-side で復元できる。
- 表示中ページの `rememberedState` はメモリ上 (`history.current`) にあり影響を受けない。
- ログアウト前の認証済みエントリは**そもそも復元させてはいけない**もの。
  実害は「ログアウト前に見ていた公開ページのスクロール位置が戻らない」程度。
- 撮影中 (`/app/*`) の media stream・未送信フォームには一切触れない (施策は hard reload を起こさない)。

「auth 配下限定適用」に切り替えればこのコストを公開ページから外せるが、採らない
(理由は下記「実装方針」1 の注記)。

## 実装方針（概要）

1. **`bootstrap/app.php`**: `$middleware->web(append: [...])` に `Inertia\Middleware\EncryptHistory` を追加。
   `NoStoreCacheHeadersForAuthenticatedPages` の隣に置き、「3 経路 × 3 枚の網」をコメントで明示する。
   - **グローバル適用にする** (認証済み route 限定にしない)。理由:
     - route 群への限定適用は inventory のドリフトを生み、Architecture テストの追加が要る
       (認証済み route は `['auth','verified']` グループ以外にも `Route::middleware('auth')` 単発や
       `verified` を要求しない招待受諾など複数ある)。
     - 公開ページの履歴も暗号化される。ログアウト後にそのエントリへ戻ると、
       **再取得 1 回に加えて `rememberedState` (フォーム状態) とスクロール位置も失われる**が、
       影響はログアウト前に作られた履歴エントリに限定される
       (上記「引き換えに払う UX コスト」と同じ許容判断。この UX コストを許容する)。
     - Inertia 公式が案内しているグローバル適用手順そのもの。
2. **`app/Http/Responses/Fortify/LogoutResponse.php` (新規)** + `FortifyServiceProvider` で bind。
   `toResponse()` で `Inertia::clearHistory()` を呼び、**`redirect()->route('home')`** を返す。
   - Fortify の `AuthenticatedSessionController::destroy()` は
     `guard->logout()` → `session()->invalidate()` → `session()->regenerateToken()` の**後**に
     `app(LogoutResponse::class)` を返し、`toResponse()` はさらに後 (router の Responsable 解決時) に走る。
     よって `clearHistory()` の session 書き込みは **invalidate 後の新しい session に載り**、
     着地 `GET /` まで確実に届く (`Inertia\Response::__construct` が `session()->pull` する)。
   - 着地先を `Fortify::redirects('logout', '/')` (= 設定由来) ではなく **`route('home')` に固定**する。
     `clearHistory` フラグは「次の Inertia 応答」でしか消費されないため、着地が非 Inertia になると
     **静かに防御が消える**。設定 1 つで壊れる経路を残さない (原則 3)。
     現状 `config/fortify.php` に `redirects` キーは無く、既定の着地 `/` と挙動は同一。
   - 着地先 `/` は `HomeController` = `Inertia::render('Welcome')` = **Inertia 応答**であることを確認済み。
     docblock に「着地は Inertia 応答であること」を契約として明記し、Feature テストで固定する。
3. **文書更新** (正本の更新箇所を明示。**主語は Inertia 面に限定する**):
   - `docs/supported-browsers.md`: 冒頭の「何と何のセットで守るか」を 3 枚に書き換え。
     経路 C は **Chromium / WebKit 両レーンで自動回帰できる** (bfcache と違い再現可能) ことを Current の表に追記。
     残存リスク (非 Inertia 面 `/admin` / 別タブ / セッション失効 / 非セキュアコンテキストでの degrade) を
     「未対応事項」に**分離して**明記する。
   - `docs/testing-browser.md`: 「bfcache 復元は再現できないが **Inertia SPA history 復元は両レーンで再現できる**」
     という差を明記 (新規 Browser テストの位置づけ)。
   - `AGENTS.md` ドメイン固有規約 #3: セット構成を 3 枚に更新する。文言は
     「**Inertia が描画する認証済み画面**」に限定し、Filament (`/admin`) 等の別スタックへ
     自動拡張されて読まれない書き方にする。

## テスト方針（概要 — 詳細は詳細設計）

| 層 | 何を固定するか |
|---|---|
| Feature (Pest) | (a) 認証済み / 公開の Inertia 応答に `encryptHistory: true` が載る (b) `POST /logout` の着地 `GET /` の Inertia 応答に `clearHistory: true` が載る (c) 通常の認証済み応答には `clearHistory` が載らない (負のコントロール) |
| Browser (Chromium + WebKit 両レーン) | **F-4-01 の再現手順そのもの**: ログイン → SPA でログアウト → `back()` → PII が描画されず `/login` に倒れる。正のコントロールとして (i) ログアウト前に `window.history.state.page instanceof ArrayBuffer` (= 暗号化が実際に効いている) (ii) `back()` 後に JS 実行コンテキストが生存 (= 本当に SPA popstate 復元であり、フルリロードで空振りしていない) を必須検証にする |
| Vitest | 追加なし (JS を書かないため)。既存 `tests/js/lib/bfcache-guard.test.ts` は経路 B の分岐の正本として不変 |

Playwright Chromium は bfcache を無効化しているため経路 B は再現できない (既存 T085 が Open) が、
**経路 C は bfcache と無関係の Inertia 内部機構であり Chromium で再現する** (bug-hunt が実証済み)。
よって本件は skip 前提ではなく**恒久自動回帰で担保できる**。

## 制約・前提

- `inertiajs/inertia-laravel` **v3.1.0** / `@inertiajs/core` **3.3.1** (実測)。
  `config/inertia.php` は未 publish のため `config('inertia.history.encrypt')` は既定 false。
  設定ファイルを publish せず middleware で有効化する (設定ファイルの二重管理を作らない)。
- history 暗号化は `window.crypto.subtle` に依存する = **セキュアコンテキスト必須**。
  非セキュアコンテキスト (`http://` の LAN IP 等) では `console.warn` の上、
  平文で history に載る (`src/encryption.ts` `encryptData`)。
  ただし撮影 PWA は `getUserMedia` / Service Worker のためどのみちセキュアコンテキストが必須であり、
  degrade するのは**アプリの中核機能が既に動かない環境**に限られる。文書に明記する。
- Fortify の logout 着地は `config('fortify.redirects.logout')` 未設定のため常に `/`。
- 本設計はコードを変更しない (設計フェーズ)。実装は後続 TODO。

## スコープ外 / 残存リスク (文書にも同じ粒度で明記する)

いずれも「黙って落とす」のではなく `docs/supported-browsers.md` の未対応事項へ分離記載する。

1. **別タブに残る Inertia history (既知の残存リスク。過小評価しない)**。
   `sessionStorage` はタブ単位のため、`Inertia::clearHistory()` が消すのは
   **ログアウト応答を受け取ったタブの鍵だけ**。同一ブラウザの別タブ B の履歴は B の鍵で復号できる。
   したがって **別タブでは、現在表示されていない過去の PII が履歴から再表示され得る**
   (例: B で組織メンバー一覧を見た後に公開ページへ SPA 遷移 → A でログアウト →
   端末を引き継いだ第三者が B で「戻る」→ メンバーの氏名/メールが復元される)。
   「B が開いている」ことと「B の現在の DOM に同等の PII が見えている」ことは同義ではない。
   本件で扱わないのは**リスクが小さいからではなく**、塞ぐには全タブへのセッション失効伝播
   (BroadcastChannel 等) という自前機構が必要で、F-4-01 (同一タブの明示ログアウト) とは
   別課題だから (原則 1 / 原則 2)。`docs/supported-browsers.md` に既知の残存リスクとして明記する。
2. **セッション期限切れ / 他デバイスからの強制ログアウト後の履歴復元**。
   ブラウザに `clearHistory` が届かないため鍵が残り、履歴は復号できてしまう。
   この状況は「利用者本人が明示ログアウトしていない」ケースであり、
   F-4-01 の脅威モデル (共有 PC でログアウトした直後の覗き見) とは別物。
   塞ぐには popstate ごとのサーバ問い合わせという自前機構が要る (原則 2 に反する)。
3. **非 Inertia 面**。Filament 管理パネル (`/admin`) は独自 middleware stack で web グループを
   経由せず、Inertia でも描画されない。経路 C の保証対象外であることを文書で明示する。
4. **非セキュアコンテキスト (`http://` の LAN IP 等)** では `window.crypto.subtle` が無く、
   Inertia は `console.warn` の上 **平文で history に載せる** (= 経路 C の防御が degrade する)。
   撮影 PWA は `getUserMedia` / Service Worker のためどのみちセキュアコンテキスト必須であり、
   degrade するのは中核機能が既に動かない環境に限られる。
5. iOS Safari 実機での bfcache 受入確認 (既存 T085 の責務。本件では動かさない)。
6. Playwright Chromium の bfcache 有効化 (`--disable-back-forward-cache` 回避) — 既存 Target のまま。
7. `bfcache-guard.ts` の挙動変更 (docblock の責務分担の追記のみ)。


---

## 再レビュー依頼

残存指摘が解消したか判定し、全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
