# 多角監査 (cycle 2) — UI 一貫性 (UI Consistency)

対象: T102 (フロント baseline: eslint no-undef + noInlineConfig + contrast gate) /
T106 (passkey UI: PasskeySection / RecentAuthModal / ConfirmRecentAuth / Login 導線)
走査範囲: `resources/js/pages/**`, `resources/js/components/**`, `resources/css/tokens.css`, `DESIGN.md`
機械 gate 実行結果: `npx vitest run tests/js/architecture tests/js/styles` = **18 files / 117 tests all passed**
(= 以下の発見はすべて **現行 gate では検出されない** 種類の不整合)

### UI一貫性: INCONSISTENCY_FOUND

---

## 1. 発見事項

### F-1 [Critical] `passkeyAvailable` を渡していない RecentAuthModal 呼び出しが 6 件中 5 件 — passkey-only ユーザーが step-up で詰む

`RecentAuthModal` は `passkeyAvailable?: boolean` を **optional + 既定 false** で受ける
(`resources/js/components/organisms/RecentAuthModal.svelte:28-34`)。同 docblock は

> 「**サーバの `/recent-auth/status` が単一の源**。呼び出し側が独自に判定しない
> — 画面ごとに判定を持つと passkey しか持たないユーザーが特定画面でだけ詰む」

と明記しているが、実際に渡しているのは Security 画面のみ:

| 呼び出し側 | 行 | `passkeyAvailable` |
|---|---|---|
| `resources/js/pages/Settings/Security.svelte` | 602-609 (`:607`) | **渡している** |
| `resources/js/pages/Settings/Index.svelte` | 287-293 | 渡していない (アカウント削除) |
| `resources/js/pages/Organizations/Settings.svelte` | 336-342 | 渡していない (オーナー移譲) |
| `resources/js/pages/Organizations/ApiKeys/Index.svelte` | 303-309 | 渡していない (API キー発行/失効) |
| `resources/js/pages/Organizations/ApiKeys/Sessions.svelte` | 209-215 | 渡していない (接続セッション失効) |
| `resources/js/pages/Admin/Users.svelte` | 520-526 | 渡していない (管理者操作) |

5 画面とも `recentAuthStatus`(= `/recent-auth/status` の全フィールド。
`resources/js/lib/recent-auth.ts:22-34` に `passkeyAvailable` を持つ)を**既に保持しており**、
渡していないのは単なる配線漏れ。

**再現する詰み** (passkey-only ユーザー = password 無し / SSO 無し / passkey あり。
T106 の phantom password 撤去でこの母集団は実在する):

1. サーバは `passwordSet=false` / `availableProviders=[]` / `passkeyAvailable=true` /
   `canSatisfy=true` を返す (`app/Http/Controllers/Auth/ConfirmRecentAuthController.php:164-179`)
2. モーダルは `passkeyAvailable` 既定 false のため **パスキーボタンを描画しない**
   (`RecentAuthModal.svelte:171`)
3. `canSatisfy=true` なので回復導線ブロック (`:204`) にも入らない
4. `executableHere = passwordSet || providers.length>0 || (passkeyAvailable && passkeySupported)`
   = false (`:58-60`) → `:211-221` の「**このアカウントの再認証手段はパスキーのみですが、
   このブラウザはパスキーに対応しています/いません**」文言が出る。
   **対応ブラウザでも「非対応です」と表示され、しかも実行手段は 1 つも提示されない**
   = 文言が事実に反する + キャンセル以外の出口が無い(押し直しても同じモーダルが開く)

これは Codex 実装レビュー Round 1 の Critical
(`devnotes/20260805-1459-todo-T106/impl-review-round-1.md`:
「`passkeyAvailable` は Security page からだけ手渡しされており、他の recent-auth 利用画面では
passkey-only ユーザーが stale 状態から再認証できません。…**全モーダル利用箇所で同じ契約にすべき**」)
の **後半が未対応のまま APPROVED になっている**。Round 2 以降はサーバ側 (`RecentAuthStatusDto` /
`Resource` / `canSatisfy` 算入) と 2 画面 (Security / ConfirmRecentAuth) しか再検査されていない。

### F-2 [High] 踏破不能な回復 CTA が 2 本 — DESIGN.md 「表示条件と踏破条件が食い違う導線を出さない」違反

**(a) `RecentAuthModal.svelte:204-210`**: `canSatisfy=false` のとき
`<Button href="/forgot-password">パスワードを設定して再認証する</Button>` を出す。
`/forgot-password` は Fortify が `guest:web` 付きで登録しており
(`vendor/laravel/fortify/routes/routes.php:55-56`)、**モーダルを見ているのはログイン済みユーザー**なので
押すと `RedirectIfAuthenticated` に無言で弾かれてダッシュボードへ飛ぶ。
同じ罠は `resources/js/pages/Auth/ConfirmRecentAuth.svelte:22-24` が
「**/forgot-password へ直接リンクしない** — 踏破不能 CTA。bug-hunt F-2-01 と同 species」と
明示的に禁じており、全画面版は正しく「ログアウト → ログイン画面の『パスワードをお忘れの方』」
を案内している (`:182-192`)。**モーダル版だけが旧作法のまま残っている**。

**(b) `PasskeySection.svelte:166-169`**: `errors.login_method` 拒否時の Alert action として
`<Button href="/settings">パスワードを設定する</Button>` を出す。
遷移先 `/settings` の唯一のパスワード UI は「パスワード変更」フォームで
`current_password` が必須 (`resources/js/pages/Settings/Index.svelte:203-239` /
`app/Actions/Fortify/UpdateUserPassword.php:38-43`)。
このエラーに到達するのは **password を持たない passkey-only ユーザー**なので、
遷移先で必ず詰む。しかも Security 画面には「別のパスキーを登録」「SSO を連携」という
**踏破可能な代替導線が同じ card / 直下の card に存在する**のに、CTA だけが唯一の
踏破不能な先を指している。

**(c) 根因**: **「パスワード未設定ユーザーがパスワードを設定する」UI 経路がアプリに存在しない**
(`grep hasPassword|passwordSet` の結果、Settings は password 有無で分岐していない)。
サーバの `LoginMethodRequiredDto.settingsUrl` は `settings.security` を返すが
(`app/Http/Middleware/EnsureLoginMethodRemains.php:116-119`)、Inertia 側の拒否は
`back()->withErrors()` でメッセージのみを運ぶため `settingsUrl` は**どのクライアントからも
消費されていない** (`grep settingsUrl resources/js` = 0 件)。フロントは `/settings` を
ハードコードしており、サーバが指す `settings.security` とも食い違っている。

### F-3 [Medium] 同一クラスのエラー (WebAuthn ceremony 失敗) の提示方法が 3 通り

| 画面 | 提示 | 行 |
|---|---|---|
| `PasskeySection.svelte` | **Toast** (`addToast("error", …)`) | 66-70, 86, 91, 102 |
| `pages/Auth/Login.svelte` | **Alert (danger, インライン)** | 101-103 |
| `pages/Auth/ConfirmRecentAuth.svelte` | **FormError** | 154-156 |
| `components/organisms/RecentAuthModal.svelte` | **FormError / FormField error** | 150, 168 |

DESIGN.md §FormError は「FormError = **フィールド単位**のエラー」「ページ常在の通知は Alert、
一時通知は Toast」と役割を定義している。ceremony 失敗はフィールド起因ではないので
FormError 用途から外れ、また PasskeySection の Toast は「操作直後にその場に出すべき失敗」を
画面外(上部中央)へ飛ばしている。**同じ transport / 同じ失敗種別なのに 3 様式**は
transport 別 (Inertia 302+errors / 純 XHR 422 / form back+errors) を統一した T106 の設計意図
とも噛み合っていない。

なお transport 別の**拒否表示**そのもの (F-1/F-2 を除く) は既存作法と揃っている:
`errors.login_method` → Alert (`PasskeySection.svelte:161-172`) は
`errors.account` → Alert (`Settings/Index.svelte:261-263`) と同型で、
`Security.svelte:51-53` の `$page.props.errors` 読み出しも既存の `$derived` 作法どおり。

### F-4 [Medium] `PasskeySection` の押下時 client エラーが入力に追随しない (DESIGN.md §FormField の不変条件違反)

`PasskeySection.svelte:59, 72-77` は `nameError` を `$state` に保持し、
**押下時にのみ代入し、その後の入力で消さない**。名前を空で押した後に文字を入力しても
「パスキーの名前を入力してください。」が残る = DESIGN.md §FormField が canonical と宣言している

> 「押下時に出した client エラーは、その後の入力に追随させる (stale invalid を残さない)。
> …有効に戻ったら消え…**新規は `$derived` 形で書く**」

に反する。先例 (`Billing/PurchaseTickets.svelte:54-63` = `$effect` で有効化時にクリア /
`Organizations/Settings.svelte`) はいずれも不変条件を満たしている。**T106 が唯一の逸脱**。
`tests/js/pages/SettingsSecurityPasskey.test.ts` にも追随を確かめるケースが無い。

### F-5 [Medium] 設定タブナビが 2 ページで手書き重複 + `aria-current` 無し (共通コンポーネント未活用)

`resources/js/pages/Settings/Index.svelte:154-157` と
`resources/js/pages/Settings/Security.svelte:368-371` に、
まったく同一の `<nav aria-label="設定メニュー" class="mt-4 flex gap-4 border-b border-border pb-2">`
+ `TextLink` × 2 がコピーされている。用途は「ドメイン内のページ間 URL 遷移タブ」で、
これは既に `components/molecules/ApiKeyTabNav.svelte` が担う責務そのもの
(DESIGN.md §ApiKeyTabNav)。結果として:

- **現在地が視覚的にも支援技術的にも分からない** (`aria-current="page"` 無し・active スタイル無し。
  `grep aria-current resources/js` のヒットは Pagination / ApiKeyTabNav / AppLayout の 3 つだけ)
- 同種 UI で 2 つの見た目 (下線タブ vs リンク並び) が併存する

(T106 で Security 画面に passkey card が増え、この 2 ページ間の往復頻度が上がったことで
顕在化した既存負債。)

### F-6 [Low] Login 画面は非対応ブラウザで passkey 導線が「無言で消える」

`pages/Auth/Login.svelte:98` は `{#if passkeySupported}` でブロックごと隠す。
一方 `PasskeySection.svelte:64-71` は「非対応端末でも押下できる。押した結果として理由を出す」。
**同じ機能で真逆の作法**。Login 側はアカウント状態を知り得ないため非表示自体は妥当だが、
passkey-only ユーザーが非対応ブラウザで来ると「パスキーでログイン」が存在しないまま
パスワード欄だけが出て、**なぜ使えないのか / どうすれば良いのかが一切示されない**
(ConfirmRecentAuth:193-208 が同じ状況で説明+ログアウト導線を出しているのと非対称)。
1 行の caption で解消できる。

### F-7 [Low] `PasskeySection` の登録フローの細部

- `:106-108` `finally { registering = false }` は `router.post` を **await していない**ため
  ceremony 直後に loading が解除される。連打すると ceremony が多重に走る
  (他ページの作法は `onStart`/`onFinish` で processing を握る。同ファイルの削除側 `:128-135` は正しい)。
- `:101-103` `onError` はサーバ validation (name の 422 等) を**汎用 toast に潰す**ため、
  `FormField error={nameError}` に**サーバ由来のエラーが決して出ない**。
  他フォームは `form.errors.*` を FormField に流す (`Login.svelte:68-91` 等)。
- `:161-172` 拒否 Alert 表示時にフォーカス移動が無い (DESIGN.md Do:「バリデーションエラー表示 + フォーカス移動」。
  同一ページの `Security.svelte:252-254` はリカバリコードで focus 移動を実装済み)。

---

## 2. AGENTS.md 禁止事項 8 (必須条件未充足で disabled) の違反有無

**違反なし。** T106 追加分を含め、`resources/js/pages` / `components/features` 内の `disabled` は
すべて **処理中の多重送信ガード** (`editForm.processing` / `changingRole` / `isLoading`) に限定されており、
必須条件未充足を理由にした disabled は 1 件も無い。

- `PasskeySection.svelte:62-71`: 非対応端末でもボタンは活性、押下時に理由を提示 (規約準拠の見本)
- `PasskeySection.svelte:236` の `loading={registering}` は送信中表示 (F-7 の解除タイミング問題は別論点)
- `Billing/_helpers/PlanCard.svelte:80-91`: 変更不可でも活性 + 常時 caption で理由提示 (準拠)
- `ConfirmRecentAuth.svelte` / `RecentAuthModal.svelte`: disabled ではなく「非表示 + 理由文」で表現

ただし **F-2 は「禁止事項 8 の同根の Don't」(「表示条件と踏破条件が食い違う導線を出さない。
disabled 化でも代替しない」) に該当する**。disabled は使っていないが、押しても必ず失敗する CTA を
出しているため、規約の意図としては違反側にある。

## 3. passkey UI の評価 (詰み・無言の行き止まり)

| 経路 | 判定 |
|---|---|
| Login → passkey ログイン (対応ブラウザ) | OK。失敗してもパスワード欄と SSO を残す (`Login.svelte:98-133`)。2FA 有効時にログイン不可である旨を**押す前に**明示 (`:113-115`) |
| Login → 非対応ブラウザ | **説明なしで導線消滅** (F-6) |
| Security → パスキー登録 (非対応 / 生体未設定) | OK。`isPasskeySupported` と `canCreatePasskey` を分離し、それぞれ理由 + 回復手段 (画面ロック設定) を Alert 表示 (`PasskeySection.svelte:181-189`)。押下時にも再提示 (`:64-71`) |
| Security → 最後のパスキー削除 (拒否) | 拒否は明示されるが **回復 CTA が踏破不能** (F-2b) |
| ConfirmRecentAuth (全画面 step-up) | **OK。ここだけが完全**。`executableHere` でアカウント能力 (canSatisfy) と端末能力を分離し、`canSatisfy=false` / `!executableHere` の双方でログアウト経由の回復手順を提示 (`:182-209`) |
| RecentAuthModal (Security 画面) | OK (`passkeyAvailable` を受け取っているため) |
| **RecentAuthModal (他 5 画面)** | **詰み。パスキー再認証ボタンが出ず、事実に反する「このブラウザは非対応」文言が出て出口が無い** (F-1) |
| RecentAuthModal (`canSatisfy=false`) | 回復 CTA が **guest 限定 URL** で無言リダイレクト (F-2a) |

Codex Critical (Round 1: 全モーダル利用箇所で同一契約 / Round 2: 端末能力 `executableHere` の表現) のうち、
**`executableHere` の導入は 2 画面とも実装済みで妥当。しかし Round 1 の「全モーダル利用箇所」は未完了**
(6 件中 5 件が未配線)。結果として「詰みを潰す仕組みは作ったが、5 画面ではその仕組みが起動しない」状態。

## 4. token 体系からの逸脱

**逸脱なし (機械検証 + 手動確認とも)**。

- `resources/js` 配下に hex 直書き **0 件** (`grep -E '#[0-9a-fA-F]{3,8}'` = 0)。hex は
  `resources/css/tokens.css:14-31` のみ (DESIGN.md frontmatter の写像。`canonical-source-parity` が同期を固定)
- T106 追加分 (PasskeySection / Login / ConfirmRecentAuth の差分) は
  `text-h3` / `text-body` / `text-caption` / `text-text-secondary` / `text-primary` /
  `rounded-md` / `border-border` / `size-5` のみを使用。ramp 外の角丸・影・gradient・独自色なし
- `ds-purity` / `typography-invariant` / `shape-ramp-purity` / `contrast-invariant` /
  `svelte-no-undef-gate` / `canonical-source-parity` を実行して **117 tests all green**
- **T102 contrast gate の宣言済み穴**: `contrast-invariant.test.ts:26-38` が明示するとおり
  alpha 合成ペア (`bg-success/10` + `text-success`、`bg-text/70` + `text-surface`
  (`features/capture/SubtitleOverlay.svelte:33,43`)、`bg-primary-soft`) と非テキスト 1.4.11 は**未検査**。
  ただし `PENDING_CONTRAST_PAIRS` として宣言され「gate があるから守られている」誤読を防ぐ
  テストまで置かれており、**隠れた漏れではなく既知の宣言済み範囲外**。T106 は新しい色/合成を
  持ち込んでいないため、この穴の面積は増えていない
- 唯一の様式逸脱は F-5 の手書きタブナビ (token は正しく使っているが、molecule 化されていない)

---

## 5. 次サイクルの TODO 候補 (優先度つき)

| # | 優先度 | 内容 | 対象 |
|---|---|---|---|
| 1 | **Critical** | RecentAuthModal の `passkeyAvailable` を全呼び出し側で配線する。単なる 5 箇所の追記で終わらせず、**`passkeyAvailable` を必須 prop 化 (型で強制)** するか、モーダル自身が `RecentAuthStatus` オブジェクトを丸ごと受ける形へ変更し、`tests/js/architecture/` に **call-site inventory テスト** (`logout-call-site-inventory.test.ts` と同型の deny-by-default) を置いて再発を機械的に止める | `components/organisms/RecentAuthModal.svelte` + 5 pages |
| 2 | **High** | 踏破不能 CTA の除去: (a) モーダルの `/forgot-password` を ConfirmRecentAuth と同じ「ログアウト → 案内」へ揃える、(b) `PasskeySection` の「パスワードを設定する」を実際に踏破可能な導線 (別パスキー登録 / SSO 連携 / ログアウト経由のリセット) へ差し替える。あわせて **`page-shell-structure.test.ts` の「踏破可能な離脱導線」検査を AuthLayout 外 (モーダル/Alert の回復 CTA) にも拡張**できないか検討 | `RecentAuthModal.svelte:204-210`, `PasskeySection.svelte:166-169` |
| 3 | **High** | **パスワード未設定ユーザーがパスワードを設定できる UI 経路を新設**する (現状ゼロ。#2 の回復 CTA の行き先が無い根因)。`current_password` 必須の変更フォームと、未設定ユーザー向け設定フォームを `hasPassword` で出し分ける。サーバの `LoginMethodRequiredDto.settingsUrl` を実際に消費するか、使わないなら DTO から落とす | `Settings/Index.svelte`, `EnsureLoginMethodRemains` |
| 4 | Medium | passkey ceremony 失敗の提示様式を 1 つに統一する (推奨: 操作の直近に出る **Alert**)。DESIGN.md §Alert / §FormError / §Toast の役割定義に照らして「非フィールド起因の操作失敗は Alert」を規約として明文化し、4 箇所を揃える | `PasskeySection` / `Login` / `ConfirmRecentAuth` / `RecentAuthModal` |
| 5 | Medium | `PasskeySection` の `nameError` を DESIGN.md §FormField の canonical 形 (`提示開始 boolean` + `$derived` 文言) へ書き換え、入力追随のテストを追加する | `PasskeySection.svelte:58-77` |
| 6 | Medium | 設定タブナビを molecule 化する。`ApiKeyTabNav` を「ドメイン内ページ間タブ」の汎用 molecule へ一般化 (改名含む) して Settings 2 ページを載せ替え、`aria-current="page"` + active スタイルを得る | `Settings/Index.svelte:154`, `Settings/Security.svelte:368`, `molecules/ApiKeyTabNav.svelte` |
| 7 | Low | Login 画面で `!passkeySupported` のとき「このブラウザではパスキーを利用できません」の caption を出す (無言の欠落を無くす) | `Login.svelte:98` |
| 8 | Low | `PasskeySection` 登録フローの整理: `router.post` の `onStart`/`onFinish` で `registering` を握る、サーバ validation を FormField に流す、拒否 Alert へのフォーカス移動 | `PasskeySection.svelte:93-110, 161-172` |
| 9 | Low | contrast gate の `PENDING_CONTRAST_PAIRS` (alpha 合成 / 非テキスト 1.4.11) を解消する後続タスク。Badge soft・Alert・`bg-text/70` 字幕の実効コントラストを合成計算で検証する | `tests/js/architecture/contrast-invariant.test.ts` |

> 注: #1〜#3 は同一の失敗様式 (「アカウント能力はあるのに、その画面からは踏破できない」) の 3 変種であり、
> 1 つの設計 (recent-auth の回復導線を単一の molecule/組立規則に集約する) でまとめて閉じられる可能性が高い。
