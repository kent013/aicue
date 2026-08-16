【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【本件固有の注意】
- リポジトリは /workspace にある。必要なら実ファイルを読んで前提を検証してよい。
- 本設計はブリーフの前提 (「撮影 PWA に導線が無い」) を現行コードで検証し、誤りとして訂正した上で
  「それでも作る」という結論に至っている。**作らない (should_implement=false) が正しいのではないか**という
  観点も含めて評価してほしい。

---

## 概念設計

# 概念設計: capture-account-screen (撮影 PWA のアカウント確認導線)

## 0. ブリーフの前提検証 (現行コードを読んだ結果)

ブリーフは「撮影 PWA (/app/*) 専用の画面や導線が無い」と書いていたが、**導線は既にある**。
以下は現行コードを実読して確かめた事実である。

| # | ブリーフの前提 | 実際 | 判定 |
|---|---|---|---|
| P1 | doc/05 §5.1/§5.2 にアカウント画面 (ID/表示名/メール確認・ログアウト) がある | `doc/05_スマホアプリ機能仕様.md` L22 (画面一覧) / L67-68 (§5.2「ログイン中のユーザー ID・表示名・メールアドレスを確認。ログアウトでログイン画面へ」) | **正しい** |
| P2 | /app 専用の画面が無い | `resources/js/pages/Capture/` は `Index.svelte` と `Show.svelte` の 2 枚のみ。アカウント面は無い | **正しい** |
| P3 | /app 専用の**導線**が無い | **誤り**。`pages/Capture/Index.svelte` も `Show.svelte` も `components/templates/AppLayout.svelte` を使っており、モバイルでは左上ハンバーガー → ドロワーに「組織名 / 表示名 / 組織切替 / 個人設定 / 組織設定 / CLI セットアップ / MCP セットアップ / 詳細(法務) / **ログアウト**」が出る (`_helpers/SidebarUserMenu.svelte`)。ログアウトは `AppLayout.svelte` の `router.post("/logout")` 一本 | **訂正が必要** |
| P4 | standalone 起動で到達できるか | **到達できる**。`public/manifest.webmanifest` は `start_url=/app` / `display=standalone` で **`scope` 宣言を持たない** = 既定 scope は `/`。よって `/settings` へのナビゲーションも同一 standalone 窓に留まる (この読みは `docs/architecture.md` §撮影 PWA の運用契約 に既出で、「実機観測がない」と明記されている前提と同じ) | **到達できる** |
| P5 | 課金ゲートとの関係 | `/app/*` group は `require-active-subscription` の**中** (`routes/web.php` L608)。`/settings` は**外** (L211、`auth`/`verified`/`not-pending-deletion` group 直下)。遮断中は `/app` 自体に入れないので、/app 内の新画面をゲート内に置いても非対称は生じない | **確認済み** |

### P3 の帰結 — 「ログアウトできない」は誤りである

したがって本タスクは「ログアウト手段が無い」問題ではない。**作らないという結論もありえた**。
それでも作る側に倒す根拠を、以下 3 つのギャップとして具体的に述べる。

## 1. 埋めるギャップ (何が足りないのか)

### G1. メールアドレス (= ログイン ID) が /app のどこにも出ていない

- `HandleInertiaRequests` は `auth.user` に `name` / `email` / `emailVerified` を**全ページへ共有済み**
  (`app/Http/Middleware/HandleInertiaRequests.php` L54-59、型は `resources/js/lib/shared-props.ts`)。
- しかし `AppLayout` のドロワーが出すのは **組織名と表示名だけ**で、email は出さない。
- email を見る唯一の面は `/settings` の**プロフィール変更フォームの入力欄**である。
  つまり「今どのアカウントで入っているか」を確かめるには、値を書き換えられるフォームまで行くことになる。
- 本アプリのログイン ID はメールアドレスであり (doc の言う `ユーザーID` は存在しない)、
  **doc/05 が挙げる 4 項目のうち「ID」と「メール」の実体である 1 項目が /app から欠けている**。

### G2. `/settings` は現場向けの面ではない

`resources/js/pages/Settings/Index.svelte` (524 行) が持つのは、プロフィール変更フォーム /
パスワード初回設定 / セキュリティ (2FA・パスキー) への導線 / **退会 (DangerZone・即時削除と予約の 2 系統)** /
退会ブロッカーの組織一覧 / 別組織の課金導線である。
現場作業者が「自分のアカウントを確認する」ためだけに踏む面として、情報量が多く、かつ
**不可逆操作 (退会) が同じ画面にある**。

### G3. `/settings` から /app へ戻る可視導線が無い

`AppLayout` の nav 項目は ダッシュボード / プロジェクト / メンバー / API キー / 請求 で、
**撮影 (/app) への項目が 1 つも無い**。standalone 起動には URL バーが無いため、
`/settings` へ出た後の復路は OS のバックジェスチャ (iOS のエッジスワイプ / Android の戻るボタン) だけになる。

> **G3 の扱い**: これはアカウント面固有ではなく、ドロワーのダッシュボード / プロジェクト / 請求 /
> CLI セットアップ / MCP セットアップ **すべて**に共通する。共有 nav の再設計は別タスクであり、
> 本設計では扱わない (§6 スコープ外)。本設計が担うのは「**新しく開く画面は自分で復路を閉じる**」ことだけである。

## 2. 改善アイデア

**`/app/account` に撮影 PWA 専用のアカウント確認画面を 1 枚作る。**
doc/05 が挙げる 4 項目 (ID / 表示名 / メール / ログアウト) だけを持ち、それ以外は持たない。

### 比較した 2 案

| 案 | 内容 | 採否 |
|---|---|---|
| 案 A: 専用画面を新設 | `GET /app/account` → `pages/Capture/Account.svelte`。表示名 / ログイン ID (メールアドレス) / 所属組織 / ログアウト / 撮影一覧への復路 / 個人設定への副導線 | **採用** |
| 案 B: 画面を作らず AppLayout のユーザーメニューに email を表示するだけ | 変更 1 ファイル。G1 は閉じる | 不採用 |

**案 B を採らない理由**: G1 は閉じるが G2 が残る。現場作業者がアカウントを確かめたいときの着地が
引き続き「退会ボタンのある PC 設定画面」になる。また email をドロワーへ足すと **PC 面の全ページ**の
共有 chrome を変えることになり、変更の波及が「撮影 PWA のアカウント確認」というタスクより広くなる。
1 画面 + 1 route の追加のほうが波及が小さく、責務も名前どおりになる。

**案 A が「必要最小限」である根拠**: 新しい概念 (モデル / テーブル / DTO / 型) を 1 つも増やさない。
表示する値はすべて既存の共有 props (`auth.user.name` / `auth.user.email` / `currentOrganization.name`) で、
サーバ側は Inertia render 1 本しか足さない。

## 3. 画面の中身 (doc/05 の 4 項目に閉じる)

| 項目 | 出所 | 備考 |
|---|---|---|
| 表示名 | `shared.auth.user.name` | |
| ログイン ID (メールアドレス) | `shared.auth.user.email` | **1 フィールドとして出す**。存在しない `ユーザーID` を表示するために新しい概念を作らない (ブリーフの指示) |
| 所属組織 | `shared.currentOrganization.name` | 複数組織に属する撮影者が、別組織のシナリオを撮ってしまう取り違えを防ぐ。組織**切替**は置かない (ドロワーの責務) |
| ログアウト | `router.post("/logout")` (Inertia visit) | AppLayout と同じ形。**新しい呼び出し箇所として inventory に登録する** (§5) |
| 撮影一覧へ戻る | `/app/projects/{project}/manuals` | 自分が開いた復路を自分で閉じる |
| 個人設定 (PC 向け) を開く | `/settings` | 変更操作は既存面へ委譲する = 面を 2 本に増やさない副導線 |

### メール確認バッジは置かない (前提検証の結果)

`/app/*` は外側 group の `verified` middleware の中にある (`routes/web.php` L190)。
**この画面に到達している時点で `emailVerified` は必ず true** であり、「未確認」バッジは到達不能な表示になる。
未確認ユーザー向けの案内は既に `AppLayout` の `EmailVerificationBanner` が担っている。
doc/05 の「メール確認」は §5.2 の本文 (「メールアドレスを確認」) のとおり **閲覧**の意味で読む。

## 4. 期待効果

- **使命への貢献**: 「専門知識ゼロの現場作業者でも」が使命の中心である。端末を共有する現場で
  「今この端末は誰のアカウントか」を確かめ、必要なら渡す前にログアウトする、という動作が
  **退会ボタンのある PC 設定画面を経由せずに**完結する。
- 撮影者が踏む面から不可逆操作 (退会・パスワード・2FA) が消える (G2)。
- doc/05 §5.1 の画面一覧と実装の差分が 1 つ埋まる。

## 5. 制約・前提 (既存の不変条件との整合)

1. **ログアウト導線の非 Inertia 化禁止** (AGENTS.md ドメイン規約 3 / 経路 C)。
   新画面のログアウトは `router.post("/logout")` の Inertia visit にし、
   `tests/js/architecture/logout-call-site-inventory.test.ts` の `LOGOUT_CALL_SITE_INVENTORY` へ登録する。
   同テストは登録ファイルに `fetch(` / `axios(` が無いことも検査するので、
   **新画面に fetch/axios を書かない**。`docs/supported-browsers.md` の「3 箇所」表記も 4 箇所へ更新する
   (同テストの説明が更新を要求している)。
2. **課金ゲート** (ドメイン規約 4)。`/app/account` は `/app` group = `require-active-subscription` の中に置く。
   group の外に置いてよいのは「契約するために未契約組織が到達できなければならない導線」だけで、
   アカウント確認はそれに当たらない。遮断中は `/app` 全体に入れないので導線としての矛盾も生じない
   (遮断時の着地は既存の `onboarding.*`、`/settings` はゲート外なので個人設定は引き続き到達可能)。
3. **`/app/*` は撮影 PWA 専用ではない** (`docs/architecture.md`)。PC のテイク選択画面が
   `capture.takes.*` を共用している。よって **/app へ PWA 固有の middleware を足さない**。
   本設計は route を 1 本足すだけで middleware を触らない。
4. **route parameter を持たない** ため IDOR 面が無い (`NestedRouteIdorDefenseTest` の母集団に入らない)。
   GET のみなので `ControllerAuthorizationGateTest` (変更系) の母集団にも入らない。
   throttle 保護対象群 (未認証で到達しうる変更系 / `api/`・`oauth/` / 認証面の変更系) にも当たらない。
5. **DS / Atomic Design**: 既存 atoms・molecules (Card / TextLink / Button / PageHeader 系 /
   PageContainer / PageContent) と `@lucide/svelte` のみ。hex 直書きをしない。
   `page-shell-structure.test.ts` の外枠契約 (AppLayout → PageContainer → PageHeader系 → PageContent) に従う。
6. **禁止事項 8**: 条件未充足を理由に disabled にするボタンを作らない。本画面のボタンは
   ログアウトのみで、二重送信ガード (`loggingOut`) は AppLayout と同じ形にする
   (これは「送信中の再送防止」であり必須条件未充足の disabled ではない)。
7. **bug-hunt 目録** (AGENTS.md §bug-hunt): `web` group の route を足したので
   `.claude/skills/app-bug-hunt/inventory/annotations.toml` に `[routes."capture.account"]` を 1 行足して
   `screens.md` / `operations.md` を再生成する。足さないと `bug-hunt-inventory-check.sh` がドリフト検出で落ちる。

## 6. スコープ外

- **G3 の本体 (共有 nav に /app への項目が無いこと)**。ドロワーのダッシュボード / プロジェクト / 請求 /
  CLI セットアップ / MCP セットアップ すべてに共通する問題で、PC 側 nav (`AppLayout.navItems`) の
  再設計になる。本設計は新画面の復路だけを閉じ、既存導線の挙動を変えない。
- ドロワーの `個人設定` リンクを /app 文脈で差し替えること。`AppLayout` は PC と共用で、
  path による出し分けを共有 chrome に持ち込むと PC 面の回帰リスクを負う。
- 組織切替・プロフィール変更・パスワード・2FA・退会。すべて既存 `/settings` 系の責務。
- 新しいユーザー ID 概念の導入 (ブリーフの明示指示により禁止)。
- PWA manifest の `scope` 宣言。standalone の窓の扱いは既存の未検証前提のままで、本設計は変えない。

## 7. 成功と判断する条件

1. `/app/projects/{id}/manuals` から 1 タップで `/app/account` に到達し、表示名・ログイン ID (メール)・
   組織名が読め、ログアウトでき、撮影一覧へ戻れる。
2. ログアウト後にブラウザバックしても認証済み画面が復元されない (経路 C の既存保証が新導線でも成立する)。
3. 撮影者ロール (project_member) で 200 になる (編集者限定にしない)。
4. `pnpm test` / `composer test` / `pnpm typecheck` / `pnpm lint` / `composer phpstan` が緑。
