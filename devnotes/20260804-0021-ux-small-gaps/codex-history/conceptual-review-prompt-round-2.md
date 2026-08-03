# 概念設計レビュー Round 2

Round 1 の指摘への対応を反映した改訂版です。

**重要 (Round 1 の前提訂正)**: あなたには **ファイル読み込みは許可されています**
(`--sandbox read-only`。コマンド実行・書き込みのみ禁止)。Round 1 では実ファイル検証が
未了とのことでしたが、Round 2 では以下の主張を**実ファイルを読んで検証**してください。

- `resources/js/components/templates/GuestLayout.svelte` に `consumeFlash` / `ToastContainer` が
  無いこと (対して `AuthLayout.svelte` / `AppLayout.svelte` にはあること)
- `app/Http/Controllers/Settings/AccountController.php` が `redirect()->route('home')->with('success', ...)` を返し、
  `routes/web.php` の `home` が `Welcome.svelte` (= `GuestLayout`) を描画すること
- `resources/js/pages/Settings/Security.svelte` が secret-key endpoint を呼んでいないこと
- `resources/js/components/organisms/ToastContainer.svelte:26` の `onDestroy(() => clearToasts())`
- `resources/js/components/molecules/CodeSnippet.svelte` が再利用可能なコピー付きコードブロックであること
- 横断確認表 (破壊的操作 × flash) に見落としが無いか (`routes/web.php` の delete route と
  各 controller を突き合わせてください)

## Round 1 指摘への対応マトリクス

# 対応マトリクス: conceptual-review Round 1

Codex (gpt-5.4 / medium) は「ファイル読み込みは許可」と伝えていたが実ファイル検証を行わずに
返答した (返答冒頭に明記あり)。事実主張の検証は Round 2 で明示的に依頼する。

## [Critical] 4-1: 「F-1-02 の真因 = GuestLayout」は飛躍

- 判断: **対応する**
- 根拠: 指摘のとおり。原文でも `(a)` で「manuals.destroy は未再現」と書いていたが、
  「**本当の欠落は** `settings.account.destroy`」という表現が「F-1-02 の真因確定」と読める。
  実際に確定したのは「初期仮説 (サーバが flash を積んでいない) の棄却」と
  「横断確認による**別の**構造欠陥の発見」の 2 点のみ。
- 対応内容: 「F-1-02 の到達点」節を新設し、確定/未確定を分離して記述。
  施策 A の位置づけを「横断確認で発見した独立の欠落の修正」と明記した。

## [Critical] 3-1 / 5-1: `onDestroy(clearToasts)` 撤去の安全性論拠が弱い / guest 面への stale toast 持ち越し

- 判断: **対応する (設計を分割し、撤去を条件付き + 境界の明示に変更)**
- 根拠: 指摘 5-1 は具体的な後退リスクとして妥当。現行の
  `onDestroy(clearToasts)` は「毎ページ遷移で clear」という粗い挙動だが、
  **ログアウト → `/` (GuestLayout) 遷移で認証文脈の toast (例:
  `「{氏名}」の 2 段階認証を解除しました` / `「{組織名}」に切り替えました`) を
  guest 面へ持ち越さない**という副次効果を持っている。施策 A で GuestLayout が
  toast を描画するようになると、この副次効果が初めて意味を持つ。
  同 run の F-4-01 (Critical: ログアウト後の PII 復元) と同じ species を作ってはならない。
- 対応内容:
  - 施策 B を **B-1 (再現テスト・無条件)** と **B-2 (ライフサイクル正規化・条件付き)** に分割。
  - B-2 を実施する場合は「unmount で clear」ではなく
    **ログアウト境界 (`AppLayout` の logout ハンドラ) で明示 clear** に置き換える
    = 境界を「ページの unmount」から「セッションの終了」へ正規化する。
  - Codex 案 2 (toast に source/createdAt/visitId を持たせて stale を捨てる) は
    **却下**。今必要な境界は「セッション終了」1 つだけで、汎用 stale 制御機構は
    思考原則 2 (今必要なものだけ) に反する。`Toast` 型も変えない (指摘 7-3 も同時に解消)。

## [Warning] 6-1: 原因未確定のまま仕組みを変えている / 段階分離せよ

- 判断: **対応する**
- 根拠: 妥当。ただし TODO を 2 本に割ると B-1 の結果待ちで実装セッションが分断される。
- 対応内容: 同一 TODO 内の **判定ゲート**として表現する。
  「B-1 を先に書いて現行コードで実行 → fail なら B-2 を適用して green 化 /
  pass なら B-2 を実施せず、その事実 (F-1-02 は観測 artifact だった) を記録する」。
  実装者は 1 セッションで判定・分岐できる。

## [Warning] 1-1: 優先順位を C 主 / A・B 補助に

- 判断: **対応する**
- 対応内容: 施策の並びを C → A → B に変更し、優先度と理由を表で明示。

## [Warning] 2-2 / 3-1(後半): テスト責務を施策と 1:1 に

- 判断: **対応する**
- 対応内容: 「テスト責務マトリクス」を概念設計に追加 (層 × 固定する不変条件)。

## [Warning] 4-2: 「manuals.destroy だけ落ちる」理由の説明が弱い

- 判断: **一部対応 (説明を追加しつつ、説明しきれないことを明記)**
- 根拠: 報告中の「出る」操作の多くは `back()` = 同一ページ応答 (SOP アップロード /
  シナリオ保存) で、ページ丸ごとの再生成を伴わない。一方
  マニュアル作成 (Create → Show) は遷移を伴い toast が出たと報告されており、
  H-b (観測 artifact) だけでは非対称性を完全には説明できない。
- 対応内容: この非対称性を「未解決」と明記したうえで、
  **だからこそ B-1 (再現テスト) を先に置く**という論理に接続した。憶測で埋めない。

## [Warning] 5-2: 「露出面を増やさない」は言い過ぎ

- 判断: **対応する**
- 対応内容: 「新しい endpoint・新しい権限境界・新しい保存先を追加しない。
  ただし可読性が上がる分の shoulder-surfing リスクは QR と同種で残る」に修正。
  併せて **confirm 成功時に `secretKey` も破棄する** (既存の `qrSvg = null` と対にする) を
  施策に含める。UI copy での注意喚起は入れない (画面上に既に QR がある以上、
  テキストだけに注意書きを付けるのは非対称で価値が薄い)。

## [Warning] 5-3: QR / secret の片方失敗時の UI が曖昧

- 判断: **対応する**
- 対応内容: 「片方が取得できれば enrollment は継続可能」を UI 文言レベルで明示する方針を追記。

## [Warning] 7-2: QR / secret のレスポンス型を分けて明示

- 判断: **対応する**
- 対応内容: 詳細設計で `TwoFactorQrCodeResponse` / `TwoFactorSecretKeyResponse` を
  型 alias として明示する (既存 `fetchJson<T>` の T に渡す)。

## [Suggestion] 1-2 / 4-3 / 6-2 / 7-1

- 判断: **見送る (現状維持でよいという趣旨のため対応不要)**

---

## 改訂版 概念設計 (全文)

# 概念設計: ux-small-gaps (F-4-02 2FA 手動セットアップキー / F-1-02 削除後の成功フィードバック)

- 出典: `devnotes/20260803-203721-bug-hunt/report.md` (F-1-02 = Low / F-4-02 = 要確認)
- 担当 task_key: G
- 対象 shard レポート: `shard-4/shard-report.md#F-4-02`, `shard-1/shard-report.md#F-2`
- Round 2 改訂: Codex conceptual-review Round 1 の Critical 2 / Warning 6 を反映
  (対応マトリクス: `codex-history/conceptual-review-decisions-round-1.md`)

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
- confirm 成功時に `qrSvg` と併せて **`secretKey` も破棄**する (画面残置を作らない)。

### 施策 A: `GuestLayout` に flash 取り込みを追加する

`AuthLayout` と同一の構成 (`consumeFlash(page.props.flash)` の `$effect` + `<ToastContainer />`) を
`GuestLayout` に足す。`settings.account.destroy` の「アカウントを削除しました」が着地先で
表示されるようになり、**3 レイアウトすべてが flash を消費する = 例外のない規約**になる。

flash 種別のフィルタ (success のみ許可等) は**入れない**。guest 面に着地する flash は
横断確認の範囲で `settings.account.destroy` の success 1 経路のみであり、
種別ゲートは今必要のない機構 (思考原則 2)。認証文脈の toast が guest 面へ持ち越される懸念は
施策 B-2 の「セッション終了境界での明示 clear」で扱う (下記)。

### 施策 B: flash → toast のページ遷移生存を契約化する (F-1-02 本体)

**B-1 (無条件・テストファースト)**: `tests/Browser/` に
「破壊的操作 → 別画面へリダイレクト → 着地先で成功 toast が見える」を 1 本追加し、
**現行コードのまま実行して H-a / H-b を判定する**。これが F-1-02 の唯一の判別器であり、
同時に flash → toast パイプライン初の end-to-end 回帰テストになる。

**B-2 (条件付き = B-1 が fail した場合のみ)**: toast のライフサイクル境界を
**「ページの unmount」から「セッションの終了」へ正規化**する。

- `ToastContainer.svelte` の `onDestroy(() => clearToasts())` を撤去する
  (目的はメモリリーク対策ではなく**境界の正規化**。auto-dismiss タイマーは
  `dismissToast` が個別に解除し DOM 参照も持たないため、撤去してもリークしない)。
- 代わりに `AppLayout` のログアウトハンドラで `clearToasts()` を明示的に呼ぶ。
  現行の「毎ページ遷移で clear」は粗いが、**ログアウト → `/` (GuestLayout) で
  認証文脈の toast (例: `「{氏名}」の 2 段階認証を解除しました`) を guest 面へ持ち越さない**
  という副次効果を持っており、施策 A で GuestLayout が toast を描画するようになると
  この副次効果が初めて意味を持つ。同 run の F-4-01 (Critical: ログアウト後の PII 復元) と
  同じ species を新たに作らないため、境界を消すのではなく**明示的な境界に置き換える**。
- `Toast` 型 / de-dup 方式 / 表示時間は変えない
  (toast に visitId や createdAt を持たせる汎用 stale 制御は、今必要な境界が
  「セッション終了」1 つだけである以上オーバーエンジニアリング)。

**B-1 が pass した場合**: `ToastContainer` には手を入れず、F-1-02 は
「観測 artifact (4 秒 auto-dismiss を跨いだ snapshot) だった」と結論して事実を記録する。
B-1 のテストが以後の回帰 (Svelte の破棄順が変わる / transition が入る) を検出する番人になる。

## テスト責務マトリクス (施策 × 層 × 固定する不変条件)

| 施策 | 層 | ファイル | 固定する不変条件 |
|---|---|---|---|
| C | JS component | `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` | 有効化開始で secret-key を fetch し画面にキーが出る / QR に `role="img"` + アクセシブルネームがある / secret 取得失敗でも QR で継続できる (逆も) / confirm 成功で secret が画面から消える |
| A | JS component | `tests/js/components/templates/GuestLayout.test.ts` | `page.props.flash.success` が toast として描画される |
| A | Feature (PHP) | `tests/Feature/Auth/AccountDeletionTest.php` | `settings.account.destroy` が `/` へ redirect し `success` flash を積む |
| A | Feature (PHP) | 既存 api-key / OAuth session の destroy テスト | 破壊的操作の flash 規約 (`assertSessionHas('success')`) の回帰固定 |
| B-1 | Browser (Chromium + WebKit) | `tests/Browser/FlashToastTest.php` (新規) | 破壊的操作の redirect 先で success toast が可視 (= flash → toast が Inertia のページ遷移をまたぐ) |
| B-2 | JS component | `tests/js/components/organisms/ToastContainer.test.ts` | unmount → 再 mount で toast が残る (B-2 実施時のみ) |
| B-2 | JS component | `tests/js/components/templates/AppLayout.test.ts` | ログアウト実行で toast が全消去される (B-2 実施時のみ) |

## 期待効果

- **使命への貢献 (主: 施策 C)**: 「専門知識ゼロの現場作業者でも使える」ためには、
  認証の enrollment がデバイス条件 (カメラ / QR 読み取り) や支援技術の有無に依存しないことが前提。
  カメラの使えない現場端末・スクリーンリーダー利用者でも 2FA を有効化できるようになる。
- **施策 A**: アカウント削除という最も不可逆な操作のフィードバック欠落が解消し、
  「操作の成否が毎回同じ形で返る」規約が 3 レイアウトで例外なしになる。
- **施策 B**: F-1-02 の事実が確定する (推測で直さない)。副産物として
  flash → toast パイプラインに初の end-to-end 回帰テストが付く。
- H14 (a11y) 指摘の解消。

## 実装方針（概要）

| 施策 | 変更ファイル |
|---|---|
| C | `resources/js/pages/Settings/Security.svelte` |
| A | `resources/js/components/templates/GuestLayout.svelte` |
| B-1 | (実装変更なし。テストのみ) |
| B-2 (条件付き) | `resources/js/components/organisms/ToastContainer.svelte`、`resources/js/components/templates/AppLayout.svelte`、`DESIGN.md` §Toast |

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
- bug-hunt 側の観測手法 (4 秒 auto-dismiss を跨ぐ snapshot タイミング) の改善。
  `.claude/skills/app-bug-hunt/` は本設計の変更対象外。
- TODO 登録・実装 (本フェーズは設計のみ)。
