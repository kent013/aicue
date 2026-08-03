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

---

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

【補足】
本設計は bug-hunt が報告した 2 件の小さな UX 指摘 (F-1-02 / F-4-02) に対する概念設計である。
リポジトリは読み取り可能なので、設計中の事実主張 (ファイル:行の引用) が実際に正しいかを検証してほしい。
特に「F-1-02 の真因は manuals.destroy ではなく GuestLayout の flash 未消費である」という結論の妥当性、
および「ToastContainer の onDestroy(clearToasts) 撤去」が安全かを厳しく見てほしい。

---

## 概念設計

# 概念設計: ux-small-gaps (F-1-02 削除後の成功フィードバック / F-4-02 2FA 手動セットアップキー)

- 出典: `devnotes/20260803-203721-bug-hunt/report.md` (F-1-02 = Low / F-4-02 = 要確認)
- 担当 task_key: G
- 対象 shard レポート: `shard-1/shard-report.md#F-2`, `shard-4/shard-report.md#F-4-02`

## 背景・課題

bug-hunt run 20260803-203721 が「小さいが放置すべきでない」2 件を報告した。
いずれも**単発の症状ではなく規約の穴かどうか**を先に確定させる必要がある種類の指摘である。

### F-1-02 (Low): 動画マニュアル削除後のリダイレクト先に成功 flash が出ない

報告: `projects.manuals.destroy` → `projects.show` にリダイレクトされ一覧からは消えるが、
成功 toast が出ない。他操作 (作成・複製・SOP アップロード・シナリオ保存) では出る。

**裏取り (本設計で実施)** — 報告された推定原因「コントローラが flash を積んでいない」は**棄却**:

| 事実 | 根拠 |
|---|---|
| `manuals.destroy` はサーバ側で flash を積んでいる | `app/Http/Controllers/Projects/VideoManualController.php:230-232` (`redirect()->route('projects.show', $project)->with('success', '動画マニュアルを削除しました')`) |
| その契約は既に Feature テストで固定済み | `tests/Feature/Projects/VideoManualCrudTest.php:196-199` (`assertSessionHas('success')`) |
| 遷移先 `Projects/Show` は `AppLayout` を使い、`AppLayout` は `consumeFlash` + `ToastContainer` を持つ | `resources/js/pages/Projects/Show.svelte:19,298`, `resources/js/components/templates/AppLayout.svelte:47-49,190` |

**破壊的操作の横断確認 (指示された「1 箇所直して終わりにしない」の実施結果)**:

| 操作 | サーバ flash | 遷移先 layout | 成功フィードバック |
|---|---|---|---|
| `projects.destroy` | 有 (`ProjectController.php:411`) | AppLayout | 出る |
| `projects.manuals.destroy` | 有 (`VideoManualController.php:232`) | AppLayout | 出る (はず。下記 (a)) |
| `projects.categories.destroy` | 有 (`CategoryController.php:99`) | AppLayout (back) | 出る |
| `projects.items.destroy` | 有 (`ItemController.php:79`) | AppLayout (back) | 出る |
| `projects.members.destroy` | 有 (`ProjectMemberController.php:80`) | AppLayout (back) | 出る |
| `organizations.members.destroy` | 有 (`OrganizationMemberController.php:52`) | AppLayout (back) | 出る |
| `organizations.invitations.destroy` | 有 (`OrganizationInvitationController.php:48`) | AppLayout (back) | 出る |
| `organizations.api-keys.destroy` | 有 (`OrganizationApiKeyController.php:137`) | AppLayout (back) | 出る |
| `organizations.api-keys.sessions.destroy` | 有 (`OrganizationOauthSessionController.php:72`) | AppLayout (back) | 出る |
| `two-factor.disable` (Fortify) | 有 (`app/Http/Responses/Fortify/TwoFactorDisabledResponse.php:33`) | AppLayout (back) | 出る |
| `capture.takes.destroy` | 無 (`CaptureTakeController.php:95` = 204) | (遷移なし) | 一覧から即消える + 失敗は `role="alert"` に表示 (`TakeStrip.svelte`)。XHR API のため flash 対象外 |
| **`settings.account.destroy`** | **有 (`AccountController.php:36`)** | **GuestLayout (`/` = `Welcome.svelte`)** | **構造的に出ない ← 新規発見** |

→ **本当の欠落は `settings.account.destroy`**。`GuestLayout.svelte` は `AppLayout` /
`AuthLayout` と違い `consumeFlash` も `ToastContainer` も持たないため
(`resources/js/components/templates/GuestLayout.svelte` に該当 import 無し。
両者にはある: `AppLayout.svelte:22,28`, `AuthLayout.svelte:4-5,24,28`)、
**アプリで最も不可逆な操作の成功メッセージだけが誰にも消費されずに捨てられている**。

(a) `manuals.destroy` 自体は静的解析上は toast が出るはずで、**本設計では未再現**。
残る仮説は 2 つ:

- **H-a (実装の暗黙依存)**: `ToastContainer.svelte:26` の `onDestroy(() => clearToasts())`。
  この container は `AppLayout` の中 = **ページごとに mount/unmount される** (Inertia svelte
  adapter は非 preserveState visit で `key = Date.now()` を更新し、ページ配下を丸ごと再生成する:
  `node_modules/@inertiajs/svelte/dist/components/App.svelte` の `swapComponent` /
  `Render.svelte` の `{#key}`)。つまり「unmount = SPA 全体破棄等の稀ケース」というコメントの
  前提が事実と違い、**全ページ遷移で `clearToasts()` が走る**。
  現行 Svelte 5.56.3 では新 branch 生成 → 旧 branch 破棄 → user effect flush の順
  (`node_modules/svelte/src/internal/client/dom/blocks/branches.js` の `BranchManager#ensure`/`#commit`)
  なので結果的に toast は生き残るが、**正しさが Svelte 内部の破棄/フラッシュ順に依存**している
  (out-transition を 1 つ足すだけで `pause_effect` が遅延し順序が反転する)。
- **H-b (観測 artifact)**: success toast は 4 秒 auto-dismiss (`lib/stores/toast.ts:24`,
  DESIGN.md §Toast)。bug-hunt driver は 1 コマンド 1 プロセスの CLI ブラウザ操作のため、
  遷移後の snapshot が 4 秒を超えることがありうる。

H-a / H-b のどちらかを**推測で選ばない**。flash → toast のパイプラインには現在
**end-to-end のテストが 1 本も無い** (`tests/js/lib/flash-to-toast.test.ts` と
`tests/js/components/organisms/ToastContainer.test.ts` はストア単体のみ) ため、
まず再現テストを書いて事実を確定させる。

### F-4-02 (要確認): 2FA 有効化画面が QR のみで手動セットアップキーを出していない

**「QR のみで十分」と決めた設計文書は存在しない**ことを確認した。むしろ逆で、
`devnotes/20260712-0949-missing-operation-ui/conceptual-design.md:144` は
スコープ外節に「2FA セットアップキー手動入力表示等の a11y 改善 (shard-report H14 注記。**別課題**)」と
書いており、**意図的な仕様ではなく積み残し**である。

- フロントは QR しか取得していない: `resources/js/pages/Settings/Security.svelte:109-116`
  (`loadQrCode()` が `/user/two-factor-qr-code` のみを fetch)。
- backend は Fortify 標準の `GET /user/two-factor-secret-key` を持ち `{"secretKey": "..."}` を返す
  (`vendor/laravel/fortify/src/Http/Controllers/TwoFactorSecretKeyController.php`、
  route 定義 `vendor/laravel/fortify/routes/routes.php:166-168`)。**サーバは既にある**。
- QR は `{@html qrSvg}` をそのまま描画 (`Security.svelte:358-363`) で、
  アクセシブルネームが無い (H14)。
- 影響: カメラ不可環境 / QR 読み取り非対応の認証アプリ / スクリーンリーダー利用者は
  **2FA の enrollment を完了できない**。2FA 必須組織 (`BlockTwoFactorDisableForEnforcedOrganizations`)
  の存在を考えると、enrollment が詰むことは「詰み」に直結する。

## 改善アイデア

3 施策。いずれも既存機構の穴埋めで、新しい仕組みは作らない。

### 施策 A: `GuestLayout` に flash 取り込みを追加する (F-1-02 横断確認の実体)

`AuthLayout` と同一の 3 行 (`consumeFlash(page.props.flash)` の `$effect` + `<ToastContainer />`) を
`GuestLayout` に足す。`settings.account.destroy` の「アカウントを削除しました」が
着地先で表示されるようになる。3 レイアウトすべてが flash を消費する = **例外のない規約**になる。

### 施策 B: flash → toast のページ遷移生存を契約化する (F-1-02 の本体)

1. **再現テストを先に書く** (テストファースト)。`tests/Browser/` に
   「破壊的操作 → 別画面へリダイレクト → 着地先で成功 toast が見える」を 1 本追加し、
   **現行コードのまま実行して H-a / H-b を判定する**。
2. `ToastContainer.svelte` の `onDestroy(() => clearToasts())` を撤去する。
   toast ストアは module singleton であり、遷移をまたいで生きるのが flash-after-redirect の
   前提。auto-dismiss タイマーは `dismissToast` が個別に解除しており DOM 参照も持たないため
   リークしない。「毎遷移で全 toast を消す」という現行挙動は契約と逆向きで、
   正しさを Svelte 内部の順序に賭けている。
3. `ToastContainer` の unmount → 再 mount で toast が残ることを component テストで固定する。

### 施策 C: 2FA enrollment に手動セットアップキーと QR のアクセシブルネームを足す (F-4-02)

- `Security.svelte` の enrollment (confirming) 表示で `/user/two-factor-secret-key` も取得し、
  QR の下に既存 `CodeSnippet` molecule (コピー UI 同梱、API キー表示で実績あり) で表示する。
- QR は wrapper 要素に `role="img"` + `aria-label` を付ける (`{@html}` された svg 文字列に
  属性注入はしない = 文字列加工を作らない)。
- QR 取得と secret 取得は**独立に失敗を扱う** (片方が落ちても他方で enrollment を完了できる)。

## 期待効果

- **使命への貢献**: 「専門知識ゼロの現場作業者でも使える」ためには、
  操作の成否が毎回同じ形で返ることと、認証の enrollment がデバイス条件に依存しないことが前提。
  施策 A/B は「破壊的操作の成否が分かる」を例外なしにし、施策 C は
  「カメラが使えない現場端末・支援技術利用者でも 2FA を有効化できる」を成立させる。
- アカウント削除という最も不可逆な操作のフィードバック欠落 (新規発見) が解消する。
- flash → toast パイプラインに初めて end-to-end の回帰テストが付く。
- H14 (a11y) 指摘の解消。

## 実装方針（概要）

| 施策 | 変更 | 追加/更新テスト |
|---|---|---|
| A | `resources/js/components/templates/GuestLayout.svelte` | `tests/js/components/templates/GuestLayout.test.ts` (flash → toast)、`tests/Feature/Auth/AccountDeletionTest.php` に `assertSessionHas('success')` |
| B | `resources/js/components/organisms/ToastContainer.svelte`、`DESIGN.md` §Toast | `tests/Browser/FlashToastTest.php` (新規・再現)、`tests/js/components/organisms/ToastContainer.test.ts` |
| C | `resources/js/pages/Settings/Security.svelte` | `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` |

横断確認で「既に規約準拠だが assert が無い」ことが分かった破壊的操作
(`settings.account.destroy` / `organizations.api-keys.destroy` /
`organizations.api-keys.sessions.destroy`) には既存 Feature テストへ
`assertSessionHas('success')` を 1 行ずつ足し、規約を回帰から守る。

## 制約・前提

- **secret を画面に出すこと自体のリスク評価** (指示された論点):
  - `two-factor.qr-code` と `two-factor.secret-key` は Fortify の**同一 middleware**
    (`vendor/laravel/fortify/routes/routes.php:162-168`。本アプリは
    `features.twoFactorAuthentication.confirmPassword=false` のため実質 `auth` のみ) で、
    **同じ TOTP secret** を返す。QR は既にその secret をエンコードして常時表示している。
    → テキスト表示は**露出面を増やさない** (同一画面・同一セッション・同一 enrollment フェーズ)。
  - **recent-auth は課さない**。これは新しい判断ではなく既存の記録済み意思決定の踏襲:
    `devnotes/20260713-1653-twofa-recent-auth/conceptual-design.md:67` が
    「`two-factor.qr-code` / `two-factor.secret-key` は TOTP secret を露出するが、意味を持つのは
    enrollment (confirm 前) フェーズであり、確立済み第二要素の bypass ではない。
    enable/confirm の enrollment 再設計と一体で扱う」とスコープ外を明示している。
    ここで片方だけにゲートを足すと、記録済みの境界を設計レビューなしに動かすことになる。
  - **折りたたみ (details) にしない**。隠す実益が無い (QR が同じ秘密を出している) 一方、
    支援技術利用者にとっては一手増える = 施策の目的に反する。ショルダーハックの懸念は
    QR と同条件であり、テキストだけ隠しても対策にならない。
  - コピー UI は既存 `CodeSnippet` molecule を再利用する (新規 component を作らない)。
    clipboard 非対応環境では「コピー失敗」を出しつつテキストは選択可能なまま (既存挙動)。
- `AGENTS.md` 禁止事項 7 (`redirect()->intended()`) / 8 (disabled UI) には抵触しない。
- DESIGN.md が UI の canonical。Toast の表示時間・配色・role は**変えない**
  (思考原則: 仕組みが機能していない段階で値を弄らない)。変更するのは
  「toast は遷移をまたいで生存する」という契約の明文化のみ。
- Atomic Design: page (`Settings/Security.svelte`) から molecule (`CodeSnippet`) の import は
  単方向規約に適合。新規 component は作らない。
- Browser テストは Chromium + WebKit の 2 レーン契約 (`docs/testing-browser.md`)。
  実行前に `pnpm build` が必要 (ビルド済アセットを読むため)。

## スコープ外

- **2FA enrollment (`two-factor.enable` / `confirm`) の再設計**、および
  `two-factor.qr-code` / `two-factor.secret-key` への recent-auth 付与
  (`config/fortify.php` の TODO(template) と上記記録済み意思決定の対象。別課題)。
  なお「confirm 済みユーザーが再び secret-key を取得できる」という Fortify 既定の挙動は
  本変更で悪化も改善もしない (UI は confirming 状態でのみ呼ぶ)。
- toast の表示時間・スタイル・de-dup 方式の変更。
- `capture.takes.destroy` 等 XHR API の応答形式変更 (in-place フィードバックで足りている)。
- bug-hunt 側の観測手法 (4 秒 auto-dismiss を跨ぐ snapshot タイミング) の改善。
  `.claude/skills/app-bug-hunt/` は本設計の変更対象外。
- TODO 登録・実装 (本フェーズは設計のみ)。
