# サポート対象ブラウザ方針

AI-CUE が「どのブラウザで、どのレベルまで動作を保証しているか」の正本。

**Inertia が描画する認証済み画面**が「ログアウト後に復元される」経路は 3 本あり、
それぞれ担当が違う。本書はその保証範囲を語るための前提として置く
(Filament 管理パネル `/admin` は Inertia でも web グループでもないため本書の対象外)。

| 経路 | 担当 | 何を保証するか |
|------|------|----------------|
| A: HTTP / disk / proxy cache、Chrome・Firefox の bfcache | `App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により格納拒否 / cookie 変更時 evict |
| B: Safari の真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + `session.status` プローブ (`App\Http\Controllers\Auth\SessionStatusController`) | **描画前に同期秘匿**し、セッション有効なら秘匿解除のみ (hard reload しない) |
| C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory` (web グループ) + `Inertia::clearHistory()` の発行契機 2 つ: **ログアウト** (`App\Http\Responses\Fortify\LogoutResponse`) と **認証失敗** (`bootstrap/app.php` の `AuthenticationException` render callback) | 発行契機の後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |

> 経路 B / C の実装は上表の参照点が正本 (将来の差分レビューで担当実装を辿れるよう、
> 本書では実装ファイルを名指しする)。

経路 C の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
`Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
履歴暗号鍵が実際に消えるのは `page.set()` 冒頭の `history.clear()` が走った瞬間だからである
(受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
アプリの `/logout` 導線は 3 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte` /
`components/molecules/RecentAuthRecoveryNotice.svelte`) で
いずれも `router.post` = Inertia visit のため、正常完了時にこの条件を満たす
(この不変条件は `tests/js/architecture/logout-call-site-inventory.test.ts` が固定する)。
**ログアウト導線を非 Inertia 経路 (JSON 204 で完結する XHR 等) で新設すると、
この条件が崩れて経路 C の保証が外れる。**

`clearHistory` の発行契機は**ログアウトだけではない**。セッション期限切れと
他デバイスからの強制ログアウトはどちらも `AuthenticationException` として現れ、
`bootstrap/app.php` の render callback がそこでもフラグを積む
(着地の `/login` が Inertia 応答なので確実に消費される)。
これが保証するのは「**認証失敗を契機に、以後の戻るによる復元を無効化する**」ことであり、
**過去に遡って無効化するものではない** (保証範囲と保証外は「未対応事項」節に対で書く)。

> **観測上の注意**: `clearHistory` の効果は `sessionStorage` の `historyKey` が
> **空になること**ではなく、**旧鍵が破棄されて別の鍵に入れ替わること**である。
> `EncryptHistory` は guest 面 (`/login`) にもグローバル適用されるため、Inertia は
> 鍵を消した直後に着地ページ用の新しい鍵を採番して書き戻す (実測)。
> 効いているかを確かめるときは **null 判定ではなく「非 null かつ旧鍵と不一致」**を見ること
> (`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` がこの形で固定している)。
> ここで固定できるのは**挙動契約** (鍵が入れ替わり、戻っても過去の PII が描画されない) であって、
> 「旧鍵が二度と手に入らない」ことの暗号学的証明ではない。保証をこれより広く書かないこと。

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

### パスキー (WebAuthn) の保証範囲

**自動テストで保証しているのは「ceremony に入る前の分岐」だけ**である。

| 対象 | 保証手段 |
|------|---------|
| feature detection (`isPasskeySupported` / `canCreatePasskey`) | `tests/js/lib/passkeys.test.ts` (ユニット) |
| キャンセル / タイムアウトを騒がず畳むこと | 同上 |
| fetch のヘッダ契約 (`Accept: application/json` / CSRF) | 同上 |
| route の到達制御・認可・throttle・no-store | `tests/Feature/Auth/PasskeyRouteAccessTest.php` |
| **実 ceremony (認証器との往復)** | **自動化しない** — 下記 |

**実 ceremony は自動化しない**。jsdom は WebAuthn を実装せず、Playwright の
仮想認証器 (CDP `WebAuthn.addVirtualAuthenticator`) は Chromium 限定で、
本アプリの主戦場である **iOS Safari では原理的に再現できない**。
Chromium だけ緑にしても「iOS で使える」ことの証明にはならないため、
**片肺の自動化で安心を買わない**判断をした。

**非対応時のフォールバック契約** (現場端末は非対応 / 生体未設定が常態):

- 非対応ブラウザ: ログイン画面にパスキーボタンを**出さない**。設定画面は理由を出す
  (`passkey-unsupported`)。パスワード / ソーシャルログインの導線は常に残る。
- 対応だがプラットフォーム認証器が使えない: 設定画面に理由を出す
  (`passkey-not-creatable`)。**ボタンは disabled にしない** (押下時にエラーを出す。
  AGENTS.md 禁止事項 8)。
- ceremony 失敗 / キャンセル: ログイン画面はパスワード欄と SSO ボタンを残したまま
  同画面にエラーを出す (回復導線を消さない)。

**実機受入確認の対象に含める** (下記「再確認条件」と同じ運用)。確認シナリオ:
iOS Safari で (1) 登録 → (2) ログアウト → (3) パスキーでログイン → (4) 設定画面で再認証 →
(5) 削除、の 5 手。

### 実機受入確認の再確認条件

一度きりの確認では陳腐化する。**以下のいずれかに挙動変更が入ったら再実施する**:

- `resources/js/lib/bfcache-guard.ts` (bfcache guard 本体)
- `resources/css/app.css` の秘匿オーバーレイのスタイル (`#bfcache-guard-overlay` 周辺)
- プローブ endpoint (`routes/web.php` の `session.status` /
  `App\Http\Controllers\Auth\SessionStatusController` / `SessionStatusResource`)
- `resources/js/lib/passkeys.ts` (WebAuthn ラッパ本体。上記「パスキーの保証範囲」)

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
  現行の `/logout` 導線は 3 箇所ともに Inertia visit のため実運用では条件を満たすが、
  非 Inertia のログアウト導線を新設すると保証が外れる
  (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
  ただし **204 で完結したタブも、次に認証を要する Inertia visit を行った時点**で
  認証失敗契機の `clearHistory` により鍵を失う (保証条件そのものは不変。残存が縮んだだけ)。
- **別タブに残る Inertia 履歴は保証外 (判断済みで受容する)**。Inertia の履歴暗号鍵は
  `sessionStorage` = タブ単位のため、同一ブラウザの**別タブ**に残った履歴は復号できてしまう
  (例: タブ B でメンバー一覧を見た後に公開ページへ遷移 → タブ A でログアウト →
  端末を引き継いだ第三者がタブ B で「戻る」)。
  **塞がない理由**は「自前機構が要るから」ではなく、以下の 3 点:
  1. 鍵だけ捨てても**そのタブが今表示している PII は消えない**ため効果が薄い
     (別タブの脅威の主部は「戻るで出る過去の PII」ではなく「今出ている PII」)。
  2. 効果を出すには別タブの document を落とす必要があり、それは**回収可能な撮影成果を破棄する**。
     テイクのアップロードは presigned URL で S3 へ直接送るため、セッションが切れていても
     アップロードは継続でき再ログイン後に finalize できる。撮影を落とさないことは使命に直結する。
  3. 下記「認証失敗契機の `clearHistory`」により、別タブも**次にサーバと話した時点で**鍵を失う。
     残る露出は「二度と触られない放置タブ」に限られる。
  **運用上の補完**: 共有端末では「使い終わったらブラウザを閉じる」運用を案内する
  (ブラウザセッションが終われば `sessionStorage` ごと消える)。
  **再検討条件**: セッション失効の push 経路 (Reverb / Echo 等) を別目的で導入したとき /
  「全デバイスからログアウト」を UI 機能として提供するとき /
  bug-hunt・実機受入確認で複数タブ運用が実際に観測されたとき。
- **セッション期限切れ / 他デバイスからの強制ログアウトは、
  「アプリが認証失敗を検知した以降」の戻るについて保証する** (限定保証)。
  `bootstrap/app.php` の `AuthenticationException` render callback が `Inertia::clearHistory()` を
  積み、着地の `/login` (Inertia 応答) が消費する。契約は
  `tests/Feature/Security/InertiaHistoryGuardTest.php` が固定する。
  **保証しない範囲**: そのタブが**一度もサーバと話さないまま**戻る場合。
  このときタブは表示中の画面自体に PII を出しており、塞ぐには push か polling が要るため
  扱わない (別タブと同じ判断)。
  **`popstate` ごとの `session.status` プローブは採らない**:
  (1) 表示中の PII は塞げないため目的を達しない、
  (2) 通常の戻る/進むに毎回ネットワーク往復と秘匿オーバーレイが入り、プローブ失敗時は
      「再試行」で操作が塞がれる (現場の不安定な回線で**新しい詰み**を作る)。
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
