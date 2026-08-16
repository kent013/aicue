# Round 2: 概念設計の修正版

Round 1 の指摘への対応マトリクスは `devnotes/20260816-2359-capture-account-screen/codex-history/conceptual-review-decisions-round-1.md` に保存済み。要点:

- **[Critical] project context 未定義**: 復路を **`/app` (`capture.home`) 1 本**に固定した。
  `capture.home` は current org の既定 project の撮影一覧へ redirect する既存 route であり、
  PWA の `start_url` そのもの。route parameter を持たず、open redirect も履歴依存も無い。
  提案の 3 案 (`/app/projects/{project}/account` / `?return_to=` / `history.back()`) はいずれも採らず、
  採らない理由を設計に明記した。
- **[Warning] 導線の置き場所**: `pages/Capture/Index.svelte` の見出しを `PageHeader` → `PageHeaderSection`
  へ変え、その actions (`children`) に `TextLink`「アカウント」を 1 本。`Capture/Show.svelte` には置かない。
- **[Warning] `/settings` 副導線**: **リンクごと削除**した。代わりにリンクではない説明文を置く。
  G3 をスコープ外にしながら自分で G3 の入口を新設するのは筋が通らないため。
- **[Warning] 未契約時のログアウト**: `pages/Onboarding/BillingRequired.svelte` /
  `Checkout.svelte` がどちらも `AppLayout` を使っていることを実読で確認。遮断中もログアウト可能。
- **[Warning] スコープの見積り**: §8「実装スコープ」を新設し、route / controller / page / 導線 /
  logout inventory / docs / bug-hunt inventory / テスト を列挙した。
- **[Warning] nullability**: controller で `resolveMemberCurrentOrganization()` を使い、
  共有 prop が null に倒れる述語 (非所属) と**同じ述語**をサーバ側に置く。Svelte 側は null なら
  組織行を出さない。`auth.user.id` は表示しない (内部主キー) ことも明記した。
- **[Warning] `loggingOut`**: 送信中の再送防止であることをコメントとテスト名で明示すると設計に書いた。

以下が修正後の概念設計の全文である。残る [Critical] / [Warning] があれば指摘し、無ければ
全体判定 APPROVED を明示してほしい。

---

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

### 入口と復路 (Round 1 [Critical] を受けて確定)

- **入口**: `pages/Capture/Index.svelte` (撮影マニュアル一覧) の見出しを `PageHeader` →
  `PageHeaderSection` に置き換え、その actions (`children`) に `TextLink`「アカウント」を 1 本置く。
  `Capture/Show.svelte` には置かない (既に「一覧へ戻る」「マニュアル詳細へ」の 2 本があり、
  狭幅端末で 3 本目が折り返す。撮影中にアカウントを確かめる場面は想定しない)。
- **復路**: `/app` (`capture.home`) 1 本。`capture.home` は current org の既定 project の
  撮影一覧へ redirect する既存 route であり、PWA の `start_url` そのものである。
  - `/app/projects/{project}/account` にしない: この画面は project のデータを 1 つも表示しない。
    親を持たせると nested route IDOR inventory 登録と scopeBindings を負うだけで、意味も歪む。
  - `?return_to=` にしない: open redirect の検査を新設することになり、面が広い。
  - `history.back()` にしない: standalone の履歴状態に依存し、機械で固定できる契約にならない。

### 比較した 2 案

| 案 | 内容 | 採否 |
|---|---|---|
| 案 A: 専用画面を新設 | `GET /app/account` → `pages/Capture/Account.svelte`。表示名 / ログイン ID (メールアドレス) / 所属組織 / ログアウト / `/app` への復路 | **採用** |
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
   表示名・ログイン ID (メール)・組織名が読め、ログアウトでき、`/app` へ戻れる。
2. ログアウト後にブラウザバックしても認証済み画面が復元されない (経路 C の既存保証が新導線でも成立する)。
3. 撮影者ロール (project_member) で 200 になる (編集者限定にしない)。
4. `pnpm test` / `composer test` / `pnpm typecheck` / `pnpm lint` / `composer phpstan` が緑。

## 8. 実装スコープ (「1 route 足すだけ」ではない)

| 種別 | 対象 |
|---|---|
| route | `routes/web.php` の `/app` group に `GET /account` を 1 本 (`capture.account`) |
| controller | `app/Http/Controllers/Capture/CaptureAccountController.php` (新規・Inertia render のみ) |
| page | `resources/js/pages/Capture/Account.svelte` (新規) |
| 導線 | `resources/js/pages/Capture/Index.svelte` の見出しを `PageHeaderSection` 化し actions を 1 本追加 |
| JS architecture inventory | `tests/js/architecture/logout-call-site-inventory.test.ts` の `LOGOUT_CALL_SITE_INVENTORY` に 1 件追加 (+ 件数を書いた説明コメントの更新) |
| docs | `docs/supported-browsers.md` の「`/logout` 導線は 3 箇所」表記を 4 箇所へ (複数箇所) |
| bug-hunt 目録 | `.claude/skills/app-bug-hunt/inventory/annotations.toml` に `[routes."capture.account"]` を追加 → `screens.md` / `operations.md` を再生成 |
| テスト | Feature (`tests/Feature/Capture/`) + Vitest ページテスト (`tests/js/pages/CaptureAccount.test.ts`) + 既存 `CaptureIndex.test.ts` の導線追加分 |

新しいモデル・テーブル・migration・Factory・DTO・TypeScript 型は**増やさない**
(表示する値はすべて既存の共有 props)。
