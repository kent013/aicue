# 概念設計: ux-small-gaps (F-4-02 2FA 手動セットアップキー / F-1-02 削除後の成功フィードバック)

- 出典: `devnotes/20260803-203721-bug-hunt/report.md` (F-1-02 = Low / F-4-02 = 要確認)
- 担当 task_key: G
- 対象 shard レポート: `shard-4/shard-report.md#F-4-02`, `shard-1/shard-report.md#F-2`
- 改訂履歴: Codex conceptual-review Round 1 (Critical 2 / Warning 6) → Round 2
  (Critical 2 / Warning 4) → Round 3 (Warning 6) を反映し、**Round 4 で APPROVED**
  (対応マトリクス: `codex-history/conceptual-review-decisions-round-{1,2,3}.md`、
  レビュー本文: `conceptual-review-round-{1,2,3,4}.md`)
- **事実検証の所在**: Codex は本環境でファイル読取手段を持てなかった (Round 1/2 とも回答冒頭で明言)。
  したがって本書の `ファイル:行` 主張はすべて **Claude 側が実ファイルを読んで確認**したものであり、
  Codex レビューは論理・設計観点のみを担っている (この非対称性を隠さず記録する)。

## 背景・課題

bug-hunt run 20260803-203721 が「小さいが放置すべきでない」2 件を報告した。
いずれも**単発の症状ではなく規約の穴かどうか**を先に確定させる必要がある種類の指摘である。

### F-4-02 (要確認 → **未実装と判定**): 2FA 有効化画面が QR のみで手動セットアップキーを出していない

指示どおり「QR のみで十分と決めた設計文書」を devnotes / docs から探した結果、
**そのような決定は存在しない**。逆に
`devnotes/20260712-0949-missing-operation-ui/conceptual-design.md:144` が
スコープ外節で「2FA セットアップキー手動入力表示等の a11y 改善 (shard-report H14 注記。**別課題**)」と
書いており、**意図した仕様ではなく積み残し**であることが確定した。

| 事実 | 根拠 |
|---|---|
| フロントは QR しか取得していない | `resources/js/pages/Settings/Security.svelte:109-116` (`loadQrCode()` が `/user/two-factor-qr-code` のみ fetch) |
| backend は secret key endpoint を持ち `{"secretKey": "..."}` を返す | `vendor/laravel/fortify/src/Http/Controllers/TwoFactorSecretKeyController.php`、route: `vendor/laravel/fortify/routes/routes.php:166-168` |
| QR / secret-key は **同一 middleware・同一秘密** | 同 routes.php:162-168 (`$twoFactorMiddleware`)。本アプリは `confirmPassword=false` のため実質 `auth` のみ |
| QR にアクセシブルネームが無い | `Security.svelte:358-363` (`{@html qrSvg}` を素の div に描画) |

影響: カメラ不可環境 / QR 読み取り非対応の認証アプリ / スクリーンリーダー利用者は
**2FA の enrollment を完了できない**。2FA 必須組織 (`BlockTwoFactorDisableForEnforcedOrganizations`)
が存在するため、enrollment が詰むことはそのまま利用の詰みに直結する。
→ **本設計の主施策**。

### F-1-02 (Low): 動画マニュアル削除後のリダイレクト先に成功 flash が出ない

報告: `projects.manuals.destroy` → `projects.show` にリダイレクトされ一覧からは消えるが、
成功 toast が出ない。他操作 (作成・複製・SOP アップロード・シナリオ保存) では出る。

**裏取り 1: 報告の推定原因「コントローラが flash を積んでいない」は棄却**

| 事実 | 根拠 |
|---|---|
| `manuals.destroy` はサーバ側で flash を積んでいる | `app/Http/Controllers/Projects/VideoManualController.php:230-232` |
| その契約は既に Feature テストで固定済み | `tests/Feature/Projects/VideoManualCrudTest.php:196-199` (`assertSessionHas('success')`) |
| 遷移先 `Projects/Show` は `AppLayout` を使い、`AppLayout` は `consumeFlash` + `ToastContainer` を持つ | `resources/js/pages/Projects/Show.svelte:19,298`、`resources/js/components/templates/AppLayout.svelte:47-49,190` |

**裏取り 2: 破壊的操作の横断確認**（指示された「1 箇所直して終わりにしない」の実施結果）

| 操作 | サーバ flash | 着地 layout | 成功フィードバック |
|---|---|---|---|
| `projects.destroy` | 有 (`ProjectController.php:411`) | AppLayout | 出る |
| `projects.manuals.destroy` | 有 (`VideoManualController.php:232`) | AppLayout | 静的解析上は出る (未再現。後述) |
| `projects.categories.destroy` | 有 (`CategoryController.php:99`) | AppLayout (back) | 出る |
| `projects.items.destroy` | 有 (`ItemController.php:79`) | AppLayout (back) | 出る |
| `projects.members.destroy` | 有 (`ProjectMemberController.php:80`) | AppLayout (back) | 出る |
| `organizations.members.destroy` | 有 (`OrganizationMemberController.php:52`) | AppLayout (back) | 出る |
| `organizations.invitations.destroy` | 有 (`OrganizationInvitationController.php:48`) | AppLayout (back) | 出る |
| `organizations.api-keys.destroy` | 有 (`OrganizationApiKeyController.php:137`) | AppLayout (back) | 出る |
| `organizations.api-keys.sessions.destroy` | 有 (`OrganizationOauthSessionController.php:72`) | AppLayout (back) | 出る |
| `two-factor.disable` (Fortify) | 有 (`app/Http/Responses/Fortify/TwoFactorDisabledResponse.php:33`) | AppLayout (back) | 出る |
| `capture.takes.destroy` | 無 (`CaptureTakeController.php:95` = 204) | 遷移なし | 一覧から即消える + 失敗は `role="alert"` (`TakeStrip.svelte`)。XHR API のため flash 規約の対象外 |
| **`settings.account.destroy`** | **有 (`AccountController.php:36`)** | **GuestLayout (`/` = `Welcome.svelte`)** | **構造的に出ない ← 横断確認で発見した独立の欠落** |

`GuestLayout.svelte` は `AppLayout` / `AuthLayout` と違い `consumeFlash` も `ToastContainer` も
持たない (前 2 者にはある: `AppLayout.svelte:22,28`、`AuthLayout.svelte:4-5,24,28`)。
そのため**アプリで最も不可逆な操作 (アカウント削除) の成功メッセージだけが誰にも消費されずに捨てられる**。
guest 面に着地する flash は横断確認の範囲ではこの 1 経路のみ
(`contact.store` は専用 Thanks 画面へ遷移し flash を使わない: `ContactController.php:57`)。

### F-1-02 の到達点 (確定 / 未確定の分離)

- **確定**: 初期仮説「`manuals.destroy` がサーバ flash を積んでいない」は**誤り**。
- **確定**: 横断確認により**別の**構造欠陥 `settings.account.destroy` を発見した
  (これは F-1-02 の真因ではなく、同 species の独立した欠落)。
- **未確定**: `manuals.destroy` で toast が見えなかった理由。残る仮説は 2 つ。
  - **H-a (実装の暗黙依存)**: `ToastContainer.svelte:26` の `onDestroy(() => clearToasts())`。
    この container は `AppLayout` の中 = **ページごとに mount/unmount される**
    (Inertia svelte adapter は非 preserveState visit で `key = Date.now()` を更新し
    ページ配下を丸ごと再生成する: `node_modules/@inertiajs/svelte/dist/components/App.svelte`
    の `swapComponent` と `Render.svelte` の `{#key}`)。
    つまり「unmount = SPA 全体破棄等の稀ケース」というコード上のコメントの前提が事実と違い、
    **全ページ遷移で `clearToasts()` が走っている**。
    現行 Svelte 5.56.3 では「新 branch 生成 → 旧 branch 破棄 → user effect flush」の順
    (`node_modules/svelte/src/internal/client/dom/blocks/branches.js` の
    `BranchManager#ensure` / `#commit`) なので結果的に toast は生き残るが、
    **正しさが Svelte 内部の破棄/フラッシュ順に依存**している (本リポジトリには現在
    svelte transition が 1 つも無いため `pause_effect` が同期破棄になっている。
    out-transition を 1 つ足すと順序が反転しうる)。
  - **H-b (観測 artifact)**: success toast は 4 秒 auto-dismiss (`lib/stores/toast.ts:24`,
    DESIGN.md §Toast)。bug-hunt driver は 1 コマンド 1 プロセスの CLI ブラウザ操作のため、
    遷移後の snapshot が 4 秒を超えることがありうる。
  - **どちらも非対称性 (「作成 (Create→Show) では出た」) を完全には説明できない**
    (報告中「出る」とされた操作のうち SOP アップロード・シナリオ保存は `back()` = 同一ページ応答
    だが、マニュアル作成は遷移を伴う)。**憶測で埋めない**。

flash → toast のパイプラインには現在 **end-to-end テストが 1 本も無い**
(`tests/js/lib/flash-to-toast.test.ts` / `tests/js/components/organisms/ToastContainer.test.ts` は
ストア単体のみ)。したがって **まず再現テストを書いて事実を確定させる**。

## 改善アイデア

3 施策。優先度順に C → A → B。いずれも既存機構の穴埋めで、新しい仕組みは作らない。

| 施策 | 内容 | 優先 | 理由 |
|---|---|---|---|
| **C** | 2FA enrollment に手動セットアップキー + QR のアクセシブルネーム | **主** | enrollment が完了できない = 機能の詰み。使命 (専門知識ゼロの現場作業者が使える) に直結 |
| **A** | `GuestLayout` に flash 取り込みを追加 | 補助 | 横断確認で発見した確定欠落。最も不可逆な操作のフィードバックが消える |
| **B** | flash → toast のページ遷移生存を**テストで確定**し、必要なら境界を正規化 | 補助 | F-1-02 本体。原因未確定のため、まず測る |

### 施策 C: 2FA enrollment に手動セットアップキーと QR のアクセシブルネームを足す (F-4-02)

- `Security.svelte` の enrollment (confirming) 表示で `/user/two-factor-secret-key` も取得し、
  QR の下に既存 `CodeSnippet` molecule (コピー UI 同梱・API キー表示で実績あり) で表示する。
  新規 component は作らない。
- QR は wrapper 要素に `role="img"` + `aria-label` を付ける
  (`{@html}` された svg 文字列への属性注入という文字列加工は行わない)。
- QR 取得と secret 取得は**独立に失敗を扱う**。片方が取得できれば enrollment は継続できるので、
  失敗文言は「その手段が使えない」ことのみを述べ、enrollment 全体の失敗に見せない。
  ただし**両方失敗した場合は「続行可能」と見せず、再試行可能な enrollment 取得失敗として提示**する。
- enrollment 素材 (`qrSvg` / `secretKey` / 各取得エラー) の**破棄経路を漏れなく定義**する:
  enrollment 開始時 (再試行を含む) / confirm 成功時 / 2FA 無効化成功時。
  component 破棄時はこれらが component ローカル state (module singleton ではない) のため
  自然に消える。

### 施策 A: 未認証 layout の flash 取り込みと「認証文脈の toast を持ち越さない」境界

**A-1**: `AuthLayout` と同一の構成 (`consumeFlash(page.props.flash)` の `$effect` +
`<ToastContainer />`) を `GuestLayout` に足す。`settings.account.destroy` の
「アカウントを削除しました」が着地先で表示されるようになり、
**3 レイアウトすべてが flash を消費する = 例外のない規約**になる。

**A-2 (A-1 と不可分)**: 未認証 layout (`GuestLayout` / `AuthLayout`) は
**初期化時に既存 toast を破棄してから、その visit の flash を消費する**
(clear → consume → 表示の順序を契約化する)。

- 理由: 認証済み画面の toast は氏名・組織名を含みうる
  (例: `「{氏名}」の 2 段階認証を解除しました` = `OrganizationMemberController.php:125`、
  `「{組織名}」に切り替えました` = `OrganizationSwitchController.php:28`)。
  これを guest / login 面へ持ち越すのは、同 run の F-4-01 (Critical: ログアウト後の PII 復元) と
  同 species の後退になる。
- **境界は「操作」ではなく「着地」に置く**。ログアウト操作をフックにする案は
  **アカウント削除がログアウトハンドラを通らない**、セッション失効・middleware 送還も通らない、と
  経路の列挙漏れを構造的に生む。着地側 (未認証 layout の初期化) に置けば列挙が不要になる。
- clear は component 初期化時の 1 回に限定する (`$effect` の再評価に載せない。
  載せると partial reload 等で client 側 toast を巻き込む)。
  本アプリは **Inertia SSR 未使用** (`resources/js/ssr.*` が無く、`vite.config.*` /
  `package.json` に ssr エントリも無い。`config/inertia.php` も存在しない) のため、
  module singleton をサーバサイドで共有する経路は無い。
- **保証範囲の限定**: 認証失効後、**次のサーバ遷移で未認証 layout に着地した時点**で
  認証文脈の toast を持ち越さない。**別タブの既表示 UI の即時消去は保証しない**
  (toast store はタブごとの JS 実行環境にある)。現行の `onDestroy(clearToasts)` も
  別タブ即時無効化は提供していないため後退ではない。BroadcastChannel 等の
  タブ間即時同期はスコープ外。

flash 種別のフィルタ (success のみ許可等) は**入れない**。guest 面に着地する flash は
横断確認の範囲で `settings.account.destroy` の success 1 経路のみであり、
種別ゲートは今必要のない機構 (思考原則 2)。

### 施策 B: flash → toast のページ遷移生存を契約化する (F-1-02 本体)

**B-1 (無条件・テストファースト)**: `tests/Browser/` に
「破壊的操作 → 別画面へリダイレクト → 着地先で成功 toast が見える」を 1 本追加し、
**現行コードのまま実行する**。成功条件は「リダイレクト着地後に
`data-testid="toast-success"` が可視」「Chromium / WebKit の両レーンで同結果」。
(4 秒後に消えることの確認は Browser では行わない。auto-dismiss は
`tests/js/lib/toast.test.ts:42-53` が fake timer で固定済みで、実時間 4 秒 × 2 レーンの
待機を足すのは重複検証。)

**B-1 の判定表 (結論の射程を限定する)**:

| 結果 | 言えること | 次の行動 |
|---|---|---|
| **制御条件を満たしたうえで一度も可視にならない** | H-a (ライフサイクル依存) を支持 | B-2 を適用して green 化 |
| その他の fail | **原因判定不能** (テスト実装・待機条件・4 秒経過でも fail しうる) | **B-2 を適用せず、テスト条件を調査する** |
| pass | **「自動テスト条件では未再現」まで**。H-b (観測 artifact) の確定ではない | B-2 は実施しない。B-1 を回帰の番人として残し、bug-hunt 観測が artifact だったかの確定 (遷移完了 → snapshot の実測時間) は open question として残す |

制御条件 (fail を H-a の支持と読んでよい条件):
(i) レスポンスに flash が存在する、(ii) 着地ページが mount 済み、
(iii) リダイレクト完了 → assertion 開始が 4 秒未満。

**B-2 (条件付き = B-1 が制御条件を満たして fail した場合のみ)**:
`ToastContainer.svelte` の `onDestroy(() => clearToasts())` を撤去する。

- 目的はメモリリーク対策ではなく**ライフサイクル境界の正規化**。
  「ページの unmount で全 toast を消す」は flash-after-redirect の前提と逆向きで、
  現状の正しさを Svelte 内部の破棄/フラッシュ順に賭けている。
- 撤去してもタイマーは残らない: auto-dismiss の `setTimeout` は `dismissToast(id)` を呼び、
  `dismissToast` が `clearTimeout` + `timers.delete(id)` を必ず行う
  (`resources/js/lib/stores/toast.ts:41-64`。手動 dismiss と同一経路)。
  `timers` は module private であり、残存を直接 assert するには内部状態を export する必要がある
  = テストのために設計を緩めることになるため追加しない。観測可能な振る舞い
  (自動消去・他 toast への非干渉) は `tests/js/lib/toast.test.ts:42-73` が固定済み。
- **PII 非持ち越しは施策 A-2 (着地側の境界) が担保する**ため、撤去しても後退しない。
  ログアウト操作をフックにする代替案は経路の列挙漏れを生むので採らない。
- `Toast` 型 / de-dup 方式 / 表示時間は変えない
  (visitId や createdAt を持たせる汎用 stale 制御は、今必要な境界が着地側の 1 つだけである以上
  オーバーエンジニアリング)。

### 実装順序 (中間状態を作らない)

1. **B-1** を現行コードで実施し、事実を記録する。
2. **C** (主施策) を実装する。
3. **A-1 + A-2** を同時に実装する (境界保護なしで GuestLayout に toast を出す中間状態を作らない)。
4. **B-2** の要否を B-1 の結果で判断する。

## テスト責務マトリクス (施策 × 層 × 固定する不変条件)

| 施策 | 層 | ファイル | 固定する不変条件 |
|---|---|---|---|
| C | JS component | `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` | 有効化開始で secret-key を fetch し画面にキーが出る / QR に `role="img"` + アクセシブルネームがある / secret 取得失敗でも QR で継続できる (逆も) / 不正 shape のレスポンスは取得失敗と同じ経路 / confirm 成功で secret が画面から消える |
| A-1 | JS component | `tests/js/components/templates/GuestLayout.test.ts` | `page.props.flash.success` が toast として描画される |
| A-1 | Feature (PHP) | `tests/Feature/Auth/AccountDeletionTest.php` | `settings.account.destroy` が `/` へ redirect し `success` flash を積む |
| A-1 | Feature (PHP) | 既存 api-key / OAuth session の destroy テスト | 破壊的操作の flash 規約 (`assertSessionHas('success')`) の回帰固定 |
| **A-2** | JS component | `tests/js/components/templates/GuestLayout.test.ts` / `AuthLayout` 用テスト | **着地前から存在する toast (認証文脈) は描画されない**、かつ**当該 visit の flash は描画される** (clear → consume の順序)。clear は**初回 mount のみ**で、再レンダー (props 更新) / partial reload では走らない |
| B-1 | Browser (Chromium + WebKit) | `tests/Browser/FlashToastTest.php` (新規) | 破壊的操作の redirect 先で success toast が可視 (= flash → toast が Inertia のページ遷移をまたぐ) |
| B-2 | JS component | `tests/js/components/organisms/ToastContainer.test.ts` | unmount → 再 mount で toast が残る (B-2 実施時のみ) |

## 期待効果

- **使命への貢献 (主: 施策 C)**: 「専門知識ゼロの現場作業者でも使える」ためには、
  認証の enrollment がデバイス条件 (カメラ / QR 読み取り) や支援技術の有無に依存しないことが前提。
  カメラの使えない現場端末・スクリーンリーダー利用者でも 2FA を有効化できるようになる。
- **施策 A**: アカウント削除という最も不可逆な操作のフィードバック欠落が解消し、
  「操作の成否が毎回同じ形で返る」規約が 3 レイアウトで例外なしになる。
- **施策 B**: F-1-02 の「自動テスト条件での再現可否」が確定する (推測で直さない)。副産物として
  flash → toast パイプラインに初の end-to-end 回帰テストが付く。
- H14 (a11y) 指摘の解消。

## 実装方針（概要）

| 施策 | 変更ファイル |
|---|---|
| C | `resources/js/pages/Settings/Security.svelte` |
| A-1 / A-2 | `resources/js/components/templates/GuestLayout.svelte`、`resources/js/components/templates/AuthLayout.svelte`、`DESIGN.md` §Toast (境界契約の明文化) |
| B-1 | (実装変更なし。テストのみ) |
| B-2 (条件付き = 制御条件下の fail 時のみ) | `resources/js/components/organisms/ToastContainer.svelte` |

横断確認で「既に規約準拠だが assert が無い」と判明した破壊的操作
(`settings.account.destroy` / `organizations.api-keys.destroy` /
`organizations.api-keys.sessions.destroy`) には既存 Feature テストへ
`assertSessionHas('success')` を 1 行ずつ足し、規約を回帰から守る。

## 制約・前提

- **secret を画面に出すこと自体のリスク評価** (指示された論点):
  - `two-factor.qr-code` と `two-factor.secret-key` は Fortify の**同一 middleware**
    (`vendor/laravel/fortify/routes/routes.php:162-168`) で**同じ TOTP secret** を返す。
    QR は既にその secret をエンコードして常時表示している。
    → テキスト表示は**新しい endpoint・新しい権限境界・新しい保存先を追加しない**。
    ただし可読性が上がる分の shoulder-surfing リスクは QR と同種で残る
    (「露出面はまったく増えない」とまでは言わない)。confirm 成功時に画面から破棄することで
    残置時間を enrollment 中に限定する。
  - **recent-auth は課さない**。これは新規判断ではなく記録済み意思決定の踏襲:
    `devnotes/20260713-1653-twofa-recent-auth/conceptual-design.md:67` が
    「`two-factor.qr-code` / `two-factor.secret-key` は TOTP secret を露出するが、意味を持つのは
    enrollment (confirm 前) フェーズであり、確立済み第二要素の bypass ではない。
    enable/confirm の enrollment 再設計と一体で扱う」とスコープ外を明示している。
    片方 (secret-key) にだけゲートを足すと、記録済みの境界を設計レビューなしに動かすことになる。
  - **折りたたみ (details) にしない**。隠す実益が無い (QR が同じ秘密を出している) 一方、
    支援技術利用者には一手増える = 施策の目的に反する。
  - コピー UI は既存 `CodeSnippet` molecule を再利用する (clipboard 非対応環境では
    「コピー失敗」表示 + テキストは選択可能なまま = 既存挙動)。
- **fetch レスポンスの実行時 narrowing**: `fetchJson<T>` の generic は型 assertion にすぎないため、
  戻りを `unknown` として受け、局所 type guard で `svg` / `secretKey` が**非空文字列である**ことを
  確認してから型付き値に代入する。満たさなければ取得失敗と同じ表示経路へ流す
  (HTTP 失敗 / 不正 shape / 通信失敗は表示が同じでも内部では識別できる形にする)。
- `AGENTS.md` 禁止事項 7 (`redirect()->intended()`) / 8 (disabled UI) には抵触しない。
- DESIGN.md が UI の canonical。Toast の表示時間・配色・role は**変えない**
  (仕組みが機能していない段階で値を弄らない)。B-2 実施時に明文化するのは
  「toast の消去境界はセッション終了である」という契約のみ。
- Atomic Design: page (`Settings/Security.svelte`) から molecule (`CodeSnippet`) の import は
  単方向規約に適合。新規 component は作らない。
- Browser テストは Chromium + WebKit の 2 レーン契約 (`docs/testing-browser.md`)。
  実行前に `pnpm build` が必要 (ビルド済アセットを読むため)。
- サーバ側の変更は無し (PHP の変更はテストへの assert 追加のみ) = PHPStan level 10 への影響なし。

## スコープ外

- **2FA enrollment (`two-factor.enable` / `confirm`) の再設計**、および
  `two-factor.qr-code` / `two-factor.secret-key` への recent-auth 付与
  (`config/fortify.php` の TODO(template) と上記記録済み意思決定の対象。別課題)。
  「confirm 済みユーザーが再び secret-key を取得できる」という Fortify 既定の挙動は
  本変更で悪化も改善もしない (UI は confirming 状態でのみ呼ぶ)。
- toast の表示時間・スタイル・de-dup 方式・`Toast` 型の変更。
- guest 面で表示してよい flash 種別のフィルタ機構。
- `capture.takes.destroy` 等 XHR API の応答形式変更 (in-place フィードバックで足りている)。
- bug-hunt 側の観測手法 (遷移完了 → snapshot までの実測時間の計測。4 秒 auto-dismiss を
  跨いでいないかの確認) の改善。`.claude/skills/app-bug-hunt/` は本設計の変更対象外。
  B-1 が pass した場合に F-1-02 を「観測 artifact」と断定するにはこの計測が要る = open question。
- TODO 登録・実装 (本フェーズは設計のみ)。
