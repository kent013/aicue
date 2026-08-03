## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

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

【本件固有の補足】
- 対象リポジトリは /workspace。事実確認のためのファイル読み込みは許可されている。
  特に以下は実在する: vendor/inertiajs/inertia-laravel/src/ResponseFactory.php,
  vendor/inertiajs/inertia-laravel/src/Response.php, vendor/inertiajs/inertia-laravel/src/Middleware/EncryptHistory.php,
  vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php,
  app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php,
  resources/js/lib/bfcache-guard.ts, resources/js/app.ts, bootstrap/app.php, routes/web.php,
  tests/Browser/AuthenticatedPageBfcacheTest.php, docs/supported-browsers.md。
  @inertiajs/core のソースは node_modules 経由では読めない (pnpm store) が、
  本設計中の引用 (src/history.ts / src/encryption.ts / src/eventHandler.ts / src/page.ts / src/router.ts) は
  sourcemap から復元した実物に基づく。
---
## 概念設計

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

すなわち **認証済み画面が復元されうる経路は 3 本あり、既存設計は 2 本しか塞いでいない**:

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
| 2 | ログアウト時に history 鍵を捨てる | Fortify の `LogoutResponse` contract をアプリ実装に差し替え (既存 10 本の Fortify response 差し替えと同じパターン) し、`Inertia::clearHistory()` を呼んでから `redirect('/')` |
| 3 | 契約文書の更新 | `docs/supported-browsers.md` / `docs/testing-browser.md` / `AGENTS.md` ドメイン固有規約 #3 を「3 経路 × 3 枚の網」に書き換える |

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

## 実装方針（概要）

1. **`bootstrap/app.php`**: `$middleware->web(append: [...])` に `Inertia\Middleware\EncryptHistory` を追加。
   `NoStoreCacheHeadersForAuthenticatedPages` の隣に置き、「3 経路 × 3 枚の網」をコメントで明示する。
   - **グローバル適用にする** (認証済み route 限定にしない)。理由:
     - route 群への限定適用は inventory のドリフトを生み、Architecture テストの追加が要る
       (認証済み route は `['auth','verified']` グループ以外にも `Route::middleware('auth')` 単発や
       `verified` を要求しない招待受諾など複数ある)。
     - 公開ページの履歴も暗号化されるが、公開ページに PII は無く、
       ログアウト後に公開ページへ戻った場合の追加コストは「再取得 1 回」だけ。
     - Inertia 公式が案内しているグローバル適用手順そのもの。
2. **`app/Http/Responses/Fortify/LogoutResponse.php` (新規)** + `FortifyServiceProvider` で bind。
   `toResponse()` で `Inertia::clearHistory()` を呼び、`redirect(Fortify::redirects('logout', '/'))` を返す。
   - Fortify の `AuthenticatedSessionController::destroy()` は
     `guard->logout()` → `session()->invalidate()` → `session()->regenerateToken()` の**後**に
     `app(LogoutResponse::class)` を返し、`toResponse()` はさらに後 (router の Responsable 解決時) に走る。
     よって `clearHistory()` の session 書き込みは **invalidate 後の新しい session に載り**、
     着地 `GET /` まで確実に届く (`Inertia\Response::__construct` が `session()->pull` する)。
   - 着地先 `/` は `HomeController` = `Inertia::render('Welcome')` = **Inertia 応答**であることを確認済み。
     フラグが宙に浮かない。
3. **文書更新** (正本の更新箇所を明示):
   - `docs/supported-browsers.md`: 冒頭の「何と何のセットで守るか」を 3 枚に書き換え。
     経路 C は **Chromium レーンで自動回帰できる** (bfcache と違い再現可能) ことを Current の表に追記。
     `crypto.subtle` 非対応環境 (非セキュアコンテキスト) での degrade を「未対応事項」に明記。
   - `docs/testing-browser.md`: 「bfcache 復元は再現できないが **Inertia SPA history 復元は両レーンで再現できる**」
     という差を明記 (新規 Browser テストの位置づけ)。
   - `AGENTS.md` ドメイン固有規約 #3: セット構成を 3 枚に更新し、
     「認証済みページの history 暗号化 + ログアウト時 clearHistory」を規約化する。

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

## スコープ外

- **セッション期限切れ / 他デバイスからの強制ログアウト後の履歴復元**。
  この場合ブラウザには `clearHistory` が届かないため、鍵が残り履歴は復号できてしまう。
  ただしこの状況は「利用者本人が端末を離れていない / 明示ログアウトしていない」ケースであり、
  F-4-01 の脅威モデル (共有 PC でログアウトした直後の覗き見) とは別物。
  塞ぐには popstate ごとのサーバ問い合わせという自前機構が要り、原則 2 (今必要なものだけ) に反する。
  **残存リスクとして `docs/supported-browsers.md` に明記する** (黙って落とさない)。
- iOS Safari 実機での bfcache 受入確認 (既存 T085 の責務。本件では動かさない)。
- Playwright Chromium の bfcache 有効化 (`--disable-back-forward-cache` 回避) — 既存 Target のまま。
- Filament 管理パネル (`/admin`) の logout (Inertia ではない)。
- `bfcache-guard.ts` の挙動変更 (docblock の責務分担の追記のみ)。

