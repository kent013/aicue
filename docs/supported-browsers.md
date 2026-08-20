# サポート対象ブラウザ方針

AI-CUE が「どのブラウザで、どのレベルまで動作を保証しているか」の正本。

一次情報の最終確認日: 2026-08-20

> 上の行は `tests/Architecture/SupportedBrowsersDocFreshnessTest.php` が機械で読む
> (書式は `YYYY-MM-DD` 固定、行は本書に 1 行だけ)。本書はブラウザ挙動の一次情報
> (自動化ハーネスの版と起動スイッチ / 復元が再現しない原因 / 実機受入確認の実施状況) に
> 依存しており、時間で陳腐化する。**日付は「見直した」ことの自己申告であって、
> 内容が正しいことの担保ではない。**

**Inertia が描画する認証済み画面**が「ログアウト後に復元される」経路は 3 本あり、
それぞれ担当が違う。本書はその保証範囲を語るための前提として置く
(Filament 管理パネル `/admin` は Inertia でも web グループでもないため本書の対象外)。

| 経路 | 担当 | 何を保証するか |
|------|------|----------------|
| A: HTTP / disk / proxy cache、ブラウザの「戻る」用の一時保存 (bfcache) | `App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により **disk / proxy cache への残留を禁じる**。**bfcache へ格納するか・いつ捨てるかはブラウザの実装判断**であり、このヘッダで復元が止まることは保証しない |
| B: 真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + セッション世代の印 (`App\Support\Auth\SessionEpoch` / `App\Http\Middleware\IssueSessionEpochCookie`) + `session.status` プローブ (`App\Http\Controllers\Auth\SessionStatusController`) | **描画前に同期秘匿**し、**認証済みかつ描画世代が現世代と一致**したときだけ秘匿解除する (hard reload しない)。世代が違えば秘匿を維持したまま同じ URL を読み直す |
| C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory` (web グループ) + `Inertia::clearHistory()` の発行契機 2 つ: **ログアウト** (`App\Http\Responses\Fortify\LogoutResponse`) と **認証失敗** (`bootstrap/app.php` の `AuthenticationException` render callback) | 発行契機の後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |

> 経路 B / C の実装は上表の参照点が正本 (将来の差分レビューで担当実装を辿れるよう、
> 本書では実装ファイルを名指しする)。

経路 B の**開示 (秘匿の解除) に到達する経路はただ 1 本**である。復元直後の判定は 2 段で、
1 段目 (通信を待たない同期判定) は「読み直す」へしか到達しない:

1. **同期判定**: 描画世代 (Inertia 共有 prop `sessionEpoch`) と世代 cookie (`session_epoch`) を
   突き合わせる。どちらかが無い / 食い違うときは、プローブを 1 度も呼ばずに
   秘匿を維持したまま同じ URL を読み直す。一致してもここでは開示せず 2 段目へ進む。
2. **プローブ**: 描画世代を `X-Session-Epoch` ヘッダで送り、`authenticated` と
   `sessionEpochMatches` の両方が真のときだけ秘匿を解く。認証済みでも世代が違えば読み直し、
   未認証なら `/login` へ置換遷移、応答が読めなければ秘匿維持 + 再試行ボタンにする。
   **サーバは要求ヘッダの値だけを照合に使い、要求の Cookie ヘッダに載る世代 cookie は使わない。**

保証するのは「読み直しが完了して新しい文書が生成された場合、その文書は復元マーカー (秘匿属性) を
継承しない」ことまでである。読み直し自体が通信障害で完了しないことは塞がない
(既存の `/login` 置換遷移も同じ性質)。読み直しは 1 つの文書につき高々 1 回でループにはならない。

経路 C の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
`Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
履歴暗号鍵が実際に消えるのは `page.set()` 冒頭の `history.clear()` が走った瞬間だからである
(受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
アプリの `/logout` 導線は 4 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte` /
`components/molecules/RecentAuthRecoveryNotice.svelte` / `pages/Capture/Account.svelte`) で
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
(**保存禁止ヘッダが付いていても「戻る」で復元されうる環境がある。主戦場の iOS Safari を含む**)。

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
  と**その応答契約** (`authenticated` / `sessionEpochMatches` の 2 キー)
- セッション世代の印の供給元 (`App\Support\Auth\SessionEpoch` /
  `App\Http\Middleware\IssueSessionEpochCookie` / `HandleInertiaRequests` の
  共有 prop `sessionEpoch`)
- `resources/js/lib/passkeys.ts` (WebAuthn ラッパ本体。上記「パスキーの保証範囲」)

**docblock / コメントのみの変更はトリガに当たらない** (挙動が変わっていないため)。
不要な実機再確認を誘発しないよう、トリガは「挙動変更」に限る。

> **T178 (同期判定の前置) は挙動変更である**。guard 本体・プローブ応答契約・秘匿状態の語彙が
> 変わったため上記トリガに当たり、**T085 の実機受入確認は T178 のマージ後に実施する**
> (T178 は 2026-08-16 にマージ済み。実施結果は下記参照)。

記録先: `devnotes/<日付>-<topic>/` に日時・端末・iOS バージョン・実施シナリオ・結果を残す。
**本書には「いつ・何を確認したか」を書かない** (記録の二重管理を作らない)。

> **記録の出所を誇張しない**: 2026-08-20 にオーナーが「実機受入は OK (合格)」と一括報告した
> (項目別の個別記録は提供されていない)。記録は
> `devnotes/20260803-0053-aigenba-alignment/ios-acceptance.md` に置いた。
> 失効セッション経路 / 有効セッション経路それぞれの証跡 (スクリーンショット等) や
> 目視確認の詳細はオーナーの一括報告に含まれておらず、**この記録は詳細な証跡ファーストの
> 確認記録ではなく一括報告に基づく合格記録である**ことを明記してある。

### 検証ページ (`/debug/bfcache-trial`) — 手動確認の補助

上記の実機受入確認そのものを自動化するものではなく、**手動確認を補助する道具**として
`/debug/bfcache-trial` (検証ページ A) と相方ページ `/debug/bfcache-trial/away` (ページ B) を
`LocalOnly` + `auth` の背後 (debug 限定) に用意している。
設計は `devnotes/20260812-1931-bfcache-device-verification-page/`。

**T085 の完了条件は、失効セッション経路 / 有効セッション経路の 2 経路が両方 PASS すること**である。
どちらか片方のみ PASS した状態は T085 未完了として扱う。
**2026-08-20 のオーナー一括報告 (実機受入 OK) を根拠に T085 は完了 (Closed) とした**が、
一括報告は経路ごとの PASS/FAIL を個別に述べたものではない。詳細は
`devnotes/20260803-0053-aigenba-alignment/ios-acceptance.md` を参照。

証跡セットの構成:

| 経路 | 証跡 |
|---|---|
| 失効セッション経路 | `/login` 到達画面のスクリーンショット + stored report のスクリーンショットの **2 枚** |
| 有効セッション経路 | live observation のスクリーンショット **1 枚** |

失効セッション経路の軸 2 判定 `unauthenticated-redirected` は、**利用者が `/login` 到達を目視確認して
記録する manual confirmation を含む** (イベント列だけからリダイレクト成功を機械的に断定しない設計であり、
完全自動判定ではない)。この表現は `docs/TODO.md` の T085 の記述と揃えること
(片方だけ読んだ人が自動判定と誤解しないため)。

**T178 以降、失効セッション経路で検証ページが観測するのは「秘匿を維持したまま読み直す」**である
(世代 cookie が入れ替わっているため、同期判定がプローブより前に読み直しへ倒す)。
guard の秘匿状態には `reloading` が加わり、検証ページはこれを軸 2 の終端候補
`stale-session-reloaded` (目視確認待ち) として扱う。**合格終端は `unauthenticated-redirected` のままで、
T085 の完了条件は変わらない** — 目視確認の記録が入って初めて合格終端になる。
別の利用者としてアプリ画面に着地した試行は `/login` に着かず目視確認を記録できないので、
判定は目視確認待ちに留まり合格にならない (意図した安全側の挙動)。

トンネル運用規律 (実機からの到達には HTTPS トンネルが要る。`APP_ENV=local` のまま露出する運用のため、
誤公開時の影響を軽く見ない):

1. トンネルは検証中のみ起動する
2. Basic 認証 (`LocalOnly` middleware) の資格情報を他と使い回さない
3. 検証後はトンネルを停止する

**本検証では HTTPS 必須**である。`crypto.randomUUID()` は secure context を要求し、
使えない環境では検証ページ自体が「secure context が必要です」と表示して終了する
(沈黙で劣化させない設計)。撮影 PWA が `getUserMedia` / Service Worker のため
そもそも secure context が前提であることとも整合する。

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
  現行の `/logout` 導線は 4 箇所ともに Inertia visit のため実運用では条件を満たすが、
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
- **世代 cookie を画面側から読めない環境では、復元のたびに読み直しになる**。
  同期判定は現世代を読めないと「読み直す」へ倒すためで、**開示側へは倒れない**。
  体感は「戻ると再読込」になる。
- **非 Inertia 面 (Filament `/admin`) は経路 B / C の保証外**。独自 middleware stack を持ち
  web グループを経由せず、Inertia でも描画されない。したがって**セッション世代の印の配布経路も
  guard の入口も無い** (世代 cookie は web グループの middleware が発行し、guard は
  Inertia の入口スクリプトが登録するため)。管理面はサーバ側の保存禁止ヘッダのみで受容する。
  **スコープからの漏れではなく、受容した非対称である。**
- **非セキュアコンテキスト (`http://` の LAN IP 等) では経路 C が degrade する**。
  `window.crypto.subtle` が無い環境で Inertia は履歴を平文で保存する (`console.warn` のみ)。
  撮影 PWA は `getUserMedia` / Service Worker のためセキュアコンテキスト必須であり、
  degrade するのは中核機能が既に動かない環境に限られる。
- **横持ち全画面の撮影 UI は、自動レーンでは DOM 契約と条件分岐だけを固定している**。
  Browser レーン (Chromium + WebKit) が固定するのは「横持ちスマホ相当の context で
  全画面へ切り替わること」「前後ボタンでカットが移動すること」「全画面を終了して
  再入路から戻れること」「デスクトップ相当・高さ超過・細いポインタの 3 通りでは
  切り替わらないこと」までである。
  **「撮影ガイドの矩形が上下の字幕帯のいずれとも交差しないこと」は Chromium レーンだけが固定する** —
  Playwright WebKit (Linux) には `MediaRecorder` が無く (実測: `typeof window.MediaRecorder`
  が `"undefined"`)、撮影パネルがファイル選択フォールバックへ倒れて overlay が 1 つも
  描画されないため、当該テストは前提を明示して skip する
  (`tests/Browser/CaptureLandscapeFullscreenTest.php`)。
  **これはレーンの能力差であって iOS Safari 実機の性質ではない**。
  **実カメラを伴う挙動 (録画中に向きが変わったときの録画継続、CSS 全画面での
  カメラプレビューの見え方、iOS Safari の動的ツールバーと `h-dvh` の相互作用、
  端末の戻るジェスチャとスワイプの競合、`inert` 非対応環境でのフォーカス漏れ) は
  どちらのレーンでも再現していない**。これらは実機受入確認の対象である。
  依存する Web 機能と最低バージョン前提は
  `devnotes/20260816-1021-landscape-fullscreen-capture/detailed-design.md` の
  **「依存する Web 機能と最低バージョン前提」を正本とする** (版番号を本書に写さない)。

## Target — 到達目標 (未達)

| 目標 | 現状 |
|------|------|
| **bfcache 復元シナリオの恒久自動回帰** (Chromium: `--disable-back-forward-cache` を外せる launch-options 経路 / WebKit: page cache が効かない原因の特定、または別ハーネス) | **未達** — 現状は分岐ロジックの vitest のみ |
| iOS Safari 実機での受入確認を**定期的に**回す (再確認条件のトリガ運用) | 未着手 |
| Android Chrome 実機での撮影フロー確認 | 未着手 |

Target を Current に格上げするときは、**何をどう検証したか**を Current の表に書いてから行う。
