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

> **G3 の扱い (詳細設計フェーズで得た訂正を含む)**: これはアカウント面固有ではなく、
> ドロワーのダッシュボード / プロジェクト / 請求 / CLI セットアップ / MCP セットアップ
> **すべて**に共通する。共有 nav の再設計は別タスクであり、本設計では扱わない (§6 スコープ外)。
> 本設計が担うのは「**新しく開く画面は自分で復路を閉じる**」ことだけである。
>
> **訂正**: 「復路が OS のバックジェスチャだけ」は言い過ぎだった。`pages/Dashboard.svelte` L328 に
> `href="/app"` の**「撮影アプリを開く」ボタン (testId `qa-capture`) が既にある**。よって
> `/settings` からの復路は「ドロワー → ダッシュボード → 撮影アプリを開く」の **2 ホップ**で成立し、
> 行き止まりではない。G3 は「遠い」問題であって「詰み」ではなく、スコープ外にする判断が補強される。

## 2. 改善アイデア

**`/app/account` に撮影 PWA 専用のアカウント確認画面を 1 枚作る。**
doc/05 が挙げる 4 項目 (ID / 表示名 / メール / ログアウト) だけを持ち、それ以外は持たない。

### 入口と復路 (Round 1 [Critical] を受けて確定)

- **入口**: `pages/Capture/Index.svelte` (撮影マニュアル一覧) の見出しを `PageHeader` →
  `PageHeaderSection` に置き換え、その actions (`children`) に `TextLink`「アカウント」を 1 本置く。
  `Capture/Show.svelte` には置かない (既に「一覧へ戻る」「マニュアル詳細へ」の 2 本があり、
  狭幅端末で 3 本目が折り返す。撮影中にアカウントを確かめる場面は想定しない)。
- **復路**: `/app` (`capture.home`) 1 本。`capture.home` は current org の**既定 project** の
  撮影一覧へ redirect する既存 route であり、PWA の `start_url` そのものである。
  正確な契約は「**`capture.home` が解決する撮影一覧へ戻る**」であって「元の一覧へ戻る」ではない
  (v1 は単一 Default Project 前提なので現状は一致する。複数 project 化したときは
  `capture.home` 側の選択画面差し替えと同時に読み直すこと)。
  - `/app/projects/{project}/account` にしない: この画面は project のデータを 1 つも表示しない。
    親を持たせると nested route IDOR inventory 登録と scopeBindings を負うだけで、意味も歪む。
  - `?return_to=` にしない: open redirect の検査を新設することになり、面が広い。
  - `history.back()` にしない: standalone の履歴状態に依存し、機械で固定できる契約にならない。

### 比較した 2 案

| 案 | 内容 | 採否 |
|---|---|---|
| 案 A: 専用画面を新設 | `GET /app/account` → `pages/Capture/Account.svelte`。表示名 / ログイン ID (メールアドレス) / 所属組織 / ログアウト / `/app` への復路 | **採用** |
| 案 B: 画面を作らず `AppLayout` のユーザーメニューに email を 1 行足すだけ | 変更 1〜2 ファイル。G1 も G2 も閉じる | 不採用 |
| 案 C: `AppLayout` に撮影文脈用の slot/prop を足して /app のときだけ表示を変える | 共有 chrome に文脈分岐を持ち込む | 不採用 |

**案 B の長所を先に認める**: 案 B は G1 (email が見えない) を閉じ、その結果として G2
(確認のために `/settings` へ行く) も実質的に閉じる。変更ファイル数は案 A より圧倒的に少ない。
「案 B では確認後も `/settings` が着地になる」という論法は成立しない。

**それでも案 B を採らない理由 — 置き場所が「識別子を読める場所」ではない**

現行 `AppLayout` の該当ブロックを実読した事実に基づく:

1. **`truncate` + `text-caption` の 2 行ブロックしか無い**。desktop は `style="width: 256px"` 固定の
   サイドバー、mobile は `w-64` (256px) のドロワーで、`px-2` と `size-9` のアイコン + `gap-2` を引くと
   文字に使える幅は **約 196px**。そこに `<p class="truncate text-caption …">` が並んでいる。
   `text-caption` は 12px (DESIGN.md L132)。実在する企業メール (`k.isitoya@example.co.jp` 級) は
   ここに収まらず**省略記号で切れる**。**先頭 20 数文字しか見えない識別子では
   「これは自分のアカウントか」を確かめられない** — 確認という目的そのものが達成できない。
2. **DESIGN.md の役割マッピングに反する**。DESIGN.md L132-134 は
   「本文/入力値/主要数値 → `text-body`、ラベル/補助情報/日時 → `text-caption`」と定めている。
   ログイン ID は補助情報ではなく**主要な識別値**であり `text-body` で出すべき値である。
   ドロワーのブロックは設計上 `text-caption` (補助情報) の場所であり、そこへ主要値を足すのは
   役割マッピングを崩す。幅を広げる / ramp を上げるという回避は **PC 全ページの共有 chrome の
   レイアウト変更**になり、案 B の唯一の長所 (小ささ) を失う。
3. **同じメニューに誤タップの隣接がある**。ドロワーは 組織切替ボタン群 → 個人設定 / 組織設定 /
   CLI セットアップ / MCP セットアップ → 詳細 (法務) → ログアウト の順に並ぶ縦スクロールである。
   共有端末の引き渡し時に「確認してログアウトする」だけをしたい利用者に、
   **組織切替と API 系セットアップが隣接した管理メニューを識別確認の手段として要求する**ことになる。
   使命の「専門知識ゼロの現場作業者」に対して要求水準が高い。
4. doc/05 §5.1 が独立した画面として要求していること。これは**主たる根拠ではなく裏付け**である
   (doc に書いてあるから作る、はしない)。

**案 C を採らない理由**: 共有 chrome に path/文脈の分岐を持ち込むことになり、
案 A を採る理由 (PC 面の回帰リスクを負わない) と衝突する。しかも表示位置は 256px の
truncate する列のままなので、上記 1. と 2. は 1 つも解決しない。

**案 A が「必要最小限」である根拠**: 新しい概念 (モデル / テーブル / DTO / TypeScript 型) を
1 つも増やさない。表示する値はすべて既存の共有 props
(`auth.user.name` / `auth.user.email` / `currentOrganization.name`) で、
サーバ側は表示専用の invokable controller と Inertia render 1 本だけである。

## 3. 画面の中身 (doc/05 の 4 項目に閉じる)

| 項目 | 出所 | 備考 |
|---|---|---|
| 表示名 | `shared.auth.user.name` | |
| ログイン ID (メールアドレス) | `shared.auth.user.email` | **1 フィールドとして出す**。存在しない `ユーザーID` を表示するために新しい概念を作らない (ブリーフの指示)。`auth.user.id` は**表示しない** — 内部 DB の主キーであり利用者にとって意味を持たない |
| 所属組織 | `shared.currentOrganization.name` | 複数組織に属する撮影者が、別組織のシナリオを撮ってしまう取り違えを防ぐ。組織**切替**は置かない (ドロワーの責務)。null なら**行ごと出さない** (偽の既定値を作らない。到達不能: §5-8) |
| ログアウト | `router.post("/logout")` (Inertia visit) | AppLayout と同じ形。**新しい呼び出し箇所として inventory に登録する** (§5) |
| 撮影に戻る | `/app` (`capture.home`) | 自分が開いた復路を自分で閉じる |
| (リンクではない説明文) | — | 「表示名・メールアドレスの変更は PC の個人設定から行います」。**`/settings` へのリンクは置かない** — G3 (復路の無い面へ出る) の入口を自分で新設しないため。変更したい人が黙って詰まることも避ける |

### メール確認バッジは置かない (前提検証の結果)

`/app/*` は外側 group の `verified` middleware の中にある (`routes/web.php` L190)。
**この画面に到達している時点で `emailVerified` は必ず true** であり、「未確認」バッジは到達不能な表示になる。
未確認ユーザー向けの案内は既に `AppLayout` の `EmailVerificationBanner` が担っている。
doc/05 の「メール確認」は §5.2 の本文 (「メールアドレスを確認」) のとおり **閲覧**の意味で読む。

## 4. 期待効果

- **使命への貢献**: 「専門知識ゼロの現場作業者でも」が使命の中心である。端末を共有する現場で
  「今この端末は誰のアカウントか」を確かめ、必要なら渡す前にログアウトする、という動作が
  **退会ボタンのある PC 設定画面を経由せずに**完結する。
- **アカウント確認・ログアウトの基本フローでは、不可逆操作 (退会・パスワード・2FA) を含む
  `/settings` を経由しなくて済む** (G2)。ドロワーの `個人設定` リンクは残るので
  「撮影者が `/settings` を踏めなくなる」わけではない。
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
   - **遮断中もログアウトできる**ことを実コードで確認した: 着地画面
     `pages/Onboarding/BillingRequired.svelte` と `pages/Onboarding/Checkout.svelte` は
     どちらも `AppLayout` を使っており、既存のログアウト導線を持つ。
     よって「未契約だからログアウトできない」という詰みは生じない。
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
   実装コメントとテスト名の両方で「送信中は再送しない」と書き、意図をコードに残す。
7. **current organization の解決は共有 prop と同じ述語で行う**。
   `SharedProps.currentOrganization` は nullable で、`HandleInertiaRequests` は
   「`current_organization_id` が指す組織に**非所属**なら null に倒す」実装になっている。
   controller は `ResolvesCurrentOrganization::resolveMemberCurrentOrganization()`
   (current org 解決 + 在籍 guard。未設定・非所属はどちらも**認可より前に 404**) を使い、
   サーバ側に同じ述語を置く。よって画面到達時の共有 prop は非 null が保証される。
   Svelte 側は防御的に null なら組織行を出さない (偽の既定値を作らない)。
8. **bug-hunt 目録** (AGENTS.md §bug-hunt): `web` group の route を足したので
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

1. 撮影マニュアル一覧 (`/app/projects/{id}/manuals`) から **1 タップ**で `/app/account` に到達し、
   表示名・ログイン ID (メール)・組織名が**省略なく**読め、ログアウトでき、
   `capture.home` が解決する撮影一覧へ戻れる。
2. ログアウト後にブラウザバックしても認証済み画面が復元されない (経路 C の既存保証が新導線でも成立する)。
3. 撮影者ロール (project_member) で 200 になる (編集者限定にしない)。
4. **AGENTS.md の `VERIFICATION_COMMANDS` マーカー区間に定義された全コマンドが green**
   (コマンド名を本設計へ写さない。写すと必ず食い違う)。

## 8. 実装スコープ (「1 route 足すだけ」ではない)

| 種別 | 対象 |
|---|---|
| route | `routes/web.php` の `/app` group に `GET /account` を 1 本 (`capture.account`) |
| controller | `app/Http/Controllers/Capture/CaptureAccountController.php` (新規・表示専用なので `__invoke` の単一アクション。current org の解決 + 在籍 guard と Inertia render のみ) |
| page | `resources/js/pages/Capture/Account.svelte` (新規) |
| 導線 | `resources/js/pages/Capture/Index.svelte` の見出しを `PageHeaderSection` 化し actions を 1 本追加 |
| JS architecture inventory | `tests/js/architecture/logout-call-site-inventory.test.ts` の `LOGOUT_CALL_SITE_INVENTORY` に 1 件追加 (+ 件数を書いた説明コメントの更新) |
| docs | `docs/supported-browsers.md` の「`/logout` 導線は 3 箇所」表記を 4 箇所へ (複数箇所) |
| bug-hunt 目録 | `.claude/skills/app-bug-hunt/inventory/annotations.toml` に `[routes."capture.account"]` を追加 → `screens.md` / `operations.md` を再生成 |
| テスト | Feature (`tests/Feature/Capture/`) + Vitest ページテスト (`tests/js/pages/CaptureAccount.test.ts`) + 既存 `CaptureIndex.test.ts` の導線追加分 |

固定する主契約 (Round 2 の指摘を反映):

- Feature: current org 所属で **200** / current org 未設定・非所属で **404** (認可より前) /
  組織名が画面 props から読める / 撮影者ロール (project_member) で 200。
- Vitest: 表示名・メールが省略なく描画される / `capture.home` への復路リンクがある /
  ログアウトが `router.post("/logout")` (Inertia visit) である / 送信中は再送しない /
  **`auth.user.id` を描画に使っていない**。
  - `auth.user.id` の不在は**サーバ側では主張しない**。`HandleInertiaRequests` が全ページへ
    共有しているため props からは消えず、「出さない」と書くと嘘になる。
    固定できるのは「描画に使っていない」ことだけである。

新しいモデル・テーブル・migration・Factory・DTO・TypeScript 型は**増やさない**
(表示する値はすべて既存の共有 props)。
