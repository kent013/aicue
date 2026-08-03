# サポート対象ブラウザ方針

AI-CUE が「どのブラウザで、どのレベルまで動作を保証しているか」の正本。

**Inertia が描画する認証済み画面**が「ログアウト後に復元される」経路は 3 本あり、
それぞれ担当が違う。本書はその保証範囲を語るための前提として置く
(Filament 管理パネル `/admin` は Inertia でも web グループでもないため本書の対象外)。

| 経路 | 担当 | 何を保証するか |
|------|------|----------------|
| A: HTTP / disk / proxy cache、Chrome・Firefox の bfcache | `NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により格納拒否 / cookie 変更時 evict |
| B: Safari の真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + `session.status` プローブ | **描画前に同期秘匿**し、セッション有効なら秘匿解除のみ (hard reload しない) |
| C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory` (web グループ) + `App\Http\Responses\Fortify\LogoutResponse` の `Inertia::clearHistory()` | ログアウト後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |

経路 C の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
`Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
履歴暗号鍵が実際に消えるのは `page.set()` 冒頭の `history.clear()` が走った瞬間だからである
(受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
アプリの `/logout` 導線は 2 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte`) で
いずれも `router.post` = Inertia visit のため、正常完了時にこの条件を満たす
(この不変条件は `tests/js/architecture/logout-call-site-inventory.test.ts` が固定する)。
**ログアウト導線を非 Inertia 経路 (JSON 204 で完結する XHR 等) で新設すると、
この条件が崩れて経路 C の保証が外れる。**

「対応している」という言葉を検証レベルと切り離さないこと。
本書では **Current (実際に回っている検証)** と **Target (到達目標)** を分けて書く。

## 対象ブラウザ

撮影 PWA と管理画面はプラットフォーム前提が違うため分けて定義する。

| 面 | URL 空間 | 主要ブラウザ |
|----|----------|--------------|
| **撮影 PWA** | `/app/*` (`manifest.webmanifest`, ホーム画面追加) | **iOS Safari** (standalone 含む) / Android Chrome |
| **管理画面** | 上記以外 | デスクトップ Chrome / Edge / Firefox / Safari |

撮影 PWA が中核 (使命 = 現場作業者がスマホで撮る) であり、**iOS Safari が最重要**。
bfcache 周りの設計判断はすべてこの前提から来ている
(Safari は `Cache-Control: no-store` のページでも bfcache に格納しうる)。

## Current — マージ後に実際に保証していること

| 区分 | 対象 | 扱い |
|------|------|------|
| **自動回帰テスト (恒久) — 経路 B** | **Chromium + WebKit** (Playwright / pest-plugin-browser) | `composer test:browser` が両レーンを実行する。カバーしているのは**秘匿の配線** (pagehide で秘匿属性が付き実描画が止まる / pageshow でプローブが走り秘匿が解ける) と**通常遷移で誤発火しないこと**。**bfcache 復元そのものは下記の理由でカバーできていない** |
| **自動回帰テスト (恒久) — 経路 C** | **Chromium + WebKit** (`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php`) | 経路 C は bfcache と無関係の Inertia 内部機構であり、**両レーンで実際に再現する = skip しない恒久回帰**。ログイン → ログアウト → 戻る で PII が **一度も DOM に出現せず** `/login` に倒れることを固定する。空振り防止の正のコントロールとして (i) `window.history.state.page instanceof ArrayBuffer` (暗号化が実際に効いている) (ii) 一連の操作で JS 実行コンテキストが生存 (= 本当に same-document の popstate 復元) を必須検証にしている |
| **契約テスト (Feature/Architecture) — 経路 C** | `tests/Feature/Security/InertiaHistoryGuardTest.php` / `tests/js/architecture/logout-call-site-inventory.test.ts` | page ペイロードの `encryptHistory` / `clearHistory` 契約と、ログアウト着地が Inertia 応答であること、ログアウト導線が Inertia visit 一本であること (deny-by-default) を固定 |
| **ユニット (vitest)** | `tests/js/lib/bfcache-guard.test.ts` | guard の分岐 (persisted 有無 / 秘匿属性 有無 / プローブ成功・失敗・エラー / 再試行) と負のコントロールを固定。**復元シナリオの分岐ロジックはここが唯一の恒久回帰** |
| **実機受入確認 (手動)** | **iOS Safari 実機** (PWA standalone 含む) | **「恒久テスト済み」とは表現しない**。実施したら**日時・端末・OS バージョン・結果**を devnotes に記録する |

レーンの実行方法・前提は `docs/testing-browser.md`。

### bfcache 復元が自動回帰でカバーできていない理由 (実測)

**Chromium / WebKit のどちらのレーンでも「戻る」で bfcache 復元が起きない**。
`Cache-Control: no-store` の付かない公開ページ間ですら、戻ると JS 実行コンテキストごと
作り直される (= 通常の再取得) ことを実測している。

原因はレーンごとに異なり、**片方は原因が特定できている**:

| レーン | 原因 | 状態 |
|--------|------|------|
| **Chromium** | **Playwright が既定の起動スイッチに `--disable-back-forward-cache` を渡している** (`playwright-core` の chromium switches に固定で含まれる。playwright 1.61.1 で確認)。`no-store` による evict 以前に、**bfcache 機構そのものがブラウザ起動時点で無効**になっている | **原因特定済み**。`launch` に `ignoreDefaultArgs: ['--disable-back-forward-cache']` を渡せば有効化できるが、`pest-plugin-browser` (`Playwright/Client.php::connectTo()`) が launch-options を **ハードコード**しており、プラグイン側の対応か vendor patch が要る |
| **WebKit** | **未特定**。Playwright の WebKit ビルド / automation セッションで page cache が使われない可能性があるが、確証は取れていない | **要調査**。復元シナリオの正本レーンなのでここが本丸 |

> 「Playwright は自動化インスペクタを接続しているから bfcache が効かない」という説明は
> **Chromium については誤り**である (原因は上記の起動スイッチ)。誤った原因を残すと
> 対処の方向を誤らせるため、判明した事実だけを書く。

そのため `tests/Browser/AuthenticatedPageBfcacheTest.php` のシナリオ 2〜4 は、
**ハーネスの bfcache 再現能力を毎回実測**し、再現できない環境では理由付きで skip する。
再現できる環境 (将来ツール側が対応した場合) では、
`pageshow.persisted === true` を観測できなければ**失敗する**正のコントロールが効く。

**skip は合格ではない**。現時点で復元シナリオを担保しているのは
vitest のユニットテスト (分岐ロジック) と実機受入確認 (未実施) だけである。

### 実機受入確認の再確認条件

一度きりの確認では陳腐化する。**以下のいずれかに挙動変更が入ったら再実施する**:

- `resources/js/lib/bfcache-guard.ts` (bfcache guard 本体)
- `resources/css/app.css` の秘匿オーバーレイのスタイル (`#bfcache-guard-overlay` 周辺)
- プローブ endpoint (`routes/web.php` の `session.status` /
  `App\Http\Controllers\Auth\SessionStatusController` / `SessionStatusResource`)

**docblock / コメントのみの変更はトリガに当たらない** (挙動が変わっていないため)。
不要な実機再確認を誘発しないよう、トリガは「挙動変更」に限る。

記録先: `devnotes/<日付>-<topic>/` に日時・端末・iOS バージョン・実施シナリオ・結果を残す。
**本書には「いつ・何を確認したか」を書かない** (記録の二重管理を作らない)。

> 現時点でこのリポジトリに iOS 実機受入確認の記録はまだない。
> **bfcache 復元後の実挙動 (PII が出ないこと) を実環境で確認できているものは無い**
> — 自動回帰が復元を再現できない以上、実機確認は**補完ではなく現状唯一の実環境検証手段**である。

## 未対応事項 (誤読を防ぐため明示列挙する)

- **どちらのレーンも bfcache 復元そのものを再現していない** (上記「実測」節)。
  Chromium は Playwright の起動スイッチで bfcache 自体が無効。仮に有効化しても、
  cookie 変更時に CCNS (`Cache-Control: no-store`) ページを bfcache から evict する仕様のため
  **シナリオ 4 (ログアウト後の復元) は Chromium では原理的に再現できない**
  (シナリオ 2・3 は有効化すれば再現しうる)。
- **Playwright WebKit ≠ 実機 iOS Safari**。bfcache 挙動・PWA standalone モード・
  iOS 固有の WebKit ビルド差がある。WebKit レーンの green を
  **「iOS Safari 対応を実証した」と言い換えない**。
- **Firefox / Edge のブラウザ自動テストレーンは持たない** (Firefox は `no-store` で
  bfcache 格納自体を拒否するため、本件のリスク面では最も安全側)。
- **経路 C は「`clearHistory: true` を含む Inertia page をクライアントが適用したタブ」のみを保証する**
  (受信ではなく適用)。JSON 204 で完結するログアウト (Fortify 既定の `wantsJson()` 分岐) では、
  次の Inertia page を適用するまでクライアントの履歴暗号鍵は残る。
  現行の `/logout` 導線は 2 箇所ともに Inertia visit のため実運用では条件を満たすが、
  非 Inertia のログアウト導線を新設すると保証が外れる
  (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
- **上記を満たしたタブ以外は保証外**。Inertia の履歴暗号鍵は
  `sessionStorage` = タブ単位のため、同一ブラウザの**別タブ**に残った履歴は復号できてしまう。
  すなわち **別タブでは、現在表示されていない過去の PII が履歴から再表示され得る**
  (例: タブ B でメンバー一覧を見た後に公開ページへ遷移 → タブ A でログアウト →
  端末を引き継いだ第三者がタブ B で「戻る」)。塞ぐには全タブへのセッション失効伝播
  (BroadcastChannel 等) が要るため本件では扱わない。**既知の残存リスク**。
- **セッション期限切れ / 他デバイスからの強制ログアウトは経路 C の保証外**。
  ブラウザに `clearHistory` が届かないため鍵が残り、履歴は復号できる。
- **非 Inertia 面 (Filament `/admin`) は経路 B / C の保証外**。独自 middleware stack を持ち
  web グループを経由せず、Inertia でも描画されない。
- **非セキュアコンテキスト (`http://` の LAN IP 等) では経路 C が degrade する**。
  `window.crypto.subtle` が無い環境で Inertia は履歴を平文で保存する (`console.warn` のみ)。
  撮影 PWA は `getUserMedia` / Service Worker のためセキュアコンテキスト必須であり、
  degrade するのは中核機能が既に動かない環境に限られる。

## Target — 到達目標 (未達)

| 目標 | 現状 |
|------|------|
| **bfcache 復元シナリオの恒久自動回帰** (Chromium: `--disable-back-forward-cache` を外せる launch-options 経路 / WebKit: page cache が効かない原因の特定、または別ハーネス) | **未達** — 現状は分岐ロジックの vitest のみ |
| iOS Safari 実機での受入確認を**定期的に**回す (再確認条件のトリガ運用) | 未着手 |
| Android Chrome 実機での撮影フロー確認 | 未着手 |

Target を Current に格上げするときは、**何をどう検証したか**を Current の表に書いてから行う。
