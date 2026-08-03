# AI-CUE 概念設計レビュー依頼 (auth-funnel-exits)

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

【補足: リポジトリ内の参照可能パス (read-only で確認してよい)】
- 対象コード: app/Support/Auth/EmailVerificationContinuation.php, app/Providers/FortifyServiceProvider.php (verifyEmailView), app/Http/Responses/Fortify/VerifyEmailResponse.php, routes/web.php (169 行目の auth+verified group / 357 行目の onboarding.checkout), resources/js/pages/Auth/*.svelte, resources/js/components/templates/AuthLayout.svelte, tests/js/architecture/page-shell-structure.test.ts
- bug-hunt レポート: devnotes/20260803-203721-bug-hunt/report.md, devnotes/20260803-203721-bug-hunt/shard-2/shard-report.md

---

## 概念設計

# 概念設計: 認証ファネルの離脱導線 (auth-funnel-exits)

対象 finding: bug-hunt run `20260803-203721` の **F-2-01 (High)** / **F-2-02 (High)**
(出典: `devnotes/20260803-203721-bug-hunt/report.md` L118-137、shard レポート
`devnotes/20260803-203721-bug-hunt/shard-2/shard-report.md#F-01,#F-02`)

## 背景・課題

### F-2-01: 認証待ち画面の「あとで認証する（プラン選択へ進む）」が構造的に常に無効

- `app/Support/Auth/EmailVerificationContinuation::resolveUrl()` (L35-52) は
  「session に組織 id があり、その組織に所属している」だけを条件に
  `route('onboarding.checkout')` を返す。
- `onboarding.checkout` は `routes/web.php:169` の `Route::middleware(['auth', 'verified'])`
  グループ内 (`routes/web.php:357`) にあるため、**メール未認証ユーザーは Laravel 標準の
  `verified` により必ず `verification.notice` へ差し戻される**。
- つまり **ボタンの表示条件 (membership) と踏破条件 (verified) が食い違っている**。
  edge case ではなく恒常的に無効な導線であり、差し戻しが無言なので
  ユーザーには「ボタンが壊れている」ようにしか見えない (bug-hunt H1: 説明なしリダイレクト)。
- 該当 UI: `resources/js/pages/Auth/VerifyEmail.svelte:51-60` (`data-testid=verify-email-continue`)。

**なぜ生まれたか (P7 の設計を辿った結果)**: `devnotes/20260717-0035-aigenba-billing-parity/detailed-design.md:1619`
「P7 新規登録経路（`?plan=` handoff + verify **ソフトゲート**継続）」で、移植元 aigenba の
「登録 → verify notice は素通りできるソフトゲート」という前提のまま CTA を verbatim 移植した。
一方 AI-CUE 側の `onboarding.checkout` は P3/P4 で `['auth','verified']` グループ内に置かれており、
**移植元の前提 (ソフトゲート) と移植先の route 配置 (ハードゲート) が矛盾したまま結線された**のが原因。
P7 の設計書にも「未認証で checkout に到達できること」の検証は含まれていない
(`RegisterVerifyFlowTest` は continueUrl の**値**しか固定していない)。

### F-2-02: パスワードリセットの無効トークン画面に離脱導線が一つもない

- `resources/js/pages/Auth/ResetPassword.svelte` (全 59 行) は `AuthLayout` の
  `{#snippet footer()}` を**一切渡していない**。兄弟の `ForgotPassword.svelte:48-52` /
  `Login.svelte:80-88` / `Register.svelte:164-169` は渡している。
- `AuthLayout.svelte:32` のヘッダ「AI-CUE」は `<p>` でリンクではない。
- 結果、期限切れ・使用済みリンクを踏んだユーザーは「同じエラーが出るだけの行き止まり」に入り、
  ブラウザバック以外の離脱手段がない (bug-hunt H2)。古いメールの再クリックというありふれた操作で到達する。

**横断調査の結果 (AuthLayout を import する全 8 ページ)**:

| ページ | footer 導線 | 判定 |
|---|---|---|
| `Auth/Login.svelte` | 登録 / パスワードをお忘れの方 | OK |
| `Auth/Register.svelte` | ログイン | OK |
| `Auth/ForgotPassword.svelte` | ログインに戻る | OK |
| `Debug/Login.svelte` | 通常のログインページへ | OK (local 専用) |
| **`Auth/ResetPassword.svelte`** | **なし** | **欠落 = F-2-02** |
| **`Auth/TwoFactorChallenge.svelte`** | **なし** | **同種の欠落** (コードもリカバリコードも手元にないユーザーが 2FA チャレンジを中断できない) |
| **`Auth/ConfirmRecentAuth.svelte`** | **なし** (`canSatisfy=false` の分岐でだけ `/forgot-password` が出る) | **同種の欠落** (step-up を中止して元の画面/ダッシュボードへ戻れない) |
| `Auth/VerifyEmail.svelte` | なし。ただし本文に「ログアウト」ボタン (実質の離脱導線) | 導線はある (規約上の扱いを決める必要あり) |

= **1 ファイル修正では再発を止められない**。欠落は 3 ページに及び、
「AuthLayout を使うページは離脱導線を持つ」という**規約と機械強制が存在しない**ことが真因。

## 改善アイデア

2 件は「認証ファネルで、ユーザーが前に進めなくなったときに画面から抜けられるか」という
同一主題なので **1 設計 / 1 TODO** にまとめる (実装単位は施策 A / B の 2 つに分ける)。

### 施策 A (F-2-01): 「メール認証が先」を正とし、踏破できない CTA を撤去する

**判断: `verified` ゲートの外に出すのではなく、CTA を消して理由を説明する。** 根拠は 3 点:

1. **安全側に倒せない**。`onboarding.checkout` の先にある操作は
   (a) `POST /onboarding/activate-personal` = Personal(無料)の即時有効化で、
   `PersonalPlanService::activate()` が `grantSignupGrant()` (`PersonalPlanService.php:162`) により
   **無料チケット (`billing.signup_grant_tickets`) を組織へ付与する**、
   (b) `POST /billing/checkout` = Stripe Checkout の開始。
   メール到達性の検証前にこれらへ到達可能にすると、**使い捨てアドレスで無料枠を刈れる**
   (現状これを止めているゲートは email verification のみ)。「導線の質向上」のために
   課金・付与の入口を未認証へ開けるのは割に合わない。
2. **「認証前にプランを見たい」需要は公開面が既に満たしている**。
   `Guest/Pricing.svelte` (`/pricing`) は guest でも全プラン・料金・チケット単価・FAQ を出す。
   認証前限定の checkout プレビュー画面を新設するのはオーバーエンジニアリング (思考原則 2)。
3. **P7 の価値 (プラン意図の継続) は失われない**。
   `App\Http\Responses\Fortify\VerifyEmailResponse` は verify 完了時に continuation を解決して
   `onboarding.checkout` へ着地させており、この経路は**現に機能している**
   (`RegisterVerifyFlowTest` の「verify 完了で onboarding.checkout へ redirect」で固定済み)。
   壊れているのは「**認証前に**飛ばそうとする部分」だけである。

具体:

- `Auth/VerifyEmail` の Inertia prop を `continueUrl: string|null` → **`continuesToCheckout: bool`** に
  置き換える (旧 prop は同じ変更で消す = 後方互換の並走を残さない。思考原則 3)。
- `VerifyEmail.svelte` から「あとで認証する（プラン選択へ進む）」ボタンを撤去し、
  代わりに **「認証が完了すると、そのままプラン選択に進みます」という説明**を出す
  (`continuesToCheckout` が true のときだけ = 招待経由で continuation を張らないユーザーに嘘をつかない)。
- 「無言の差し戻し」への対処は**着地画面が理由を持つ**方針で行う。これは本リポジトリの既存規約
  (`routes/web.php` 課金ゲートのコメント「middleware は error flash を積まない = 遮断理由は
  着地ページが持つ」) と同型であり、GET に対する新しい flash 機構は作らない。
  アプリ内から `onboarding.checkout` を指すリンクは他に存在しないため
  (grep 済み)、CTA 撤去後に未認証で差し戻されるのは URL 直打ち / ブックマークのみになる。

### 施策 B (F-2-02 + 横断): AuthLayout の離脱導線を規約化し、機械強制する

- **規約**: 「`AuthLayout` を使うページは、**その手順を完了できないユーザーが別の入口へ抜けられる導線**を
  `{#snippet footer()}` に必ず 1 つ以上持つ (`TextLink` で表現する)」。
- 欠落 3 ページに footer を追加する。行き先は**未認証/未検証状態でも実際に踏破できる先**に限る
  (新しい行き止まりを作らないため):
  - `ResetPassword`: 「新しいリセットリンクをリクエスト」(`/forgot-password`) +「ログインに戻る」(`/login`)
  - `TwoFactorChallenge`: 「ログインをやり直す」(`/login`) — 2FA チャレンジ中はまだ未ログインのため到達可
  - `ConfirmRecentAuth`: 「キャンセルしてダッシュボードへ戻る」(`/dashboard`) — 本画面のユーザーは
    auth+verified 済みのため到達可
- **機械強制**: `tests/js/architecture/page-shell-structure.test.ts` に
  「AuthLayout を import するページは footer snippet を持ち、その中に `TextLink` を含む」契約を追加する。
  同ファイルには既に `AppLayout` ページ向けの同型契約 (`PageContainer`/`PageHeader`/`PageContent` 必須 +
  理由必須 allowlist) があり、**外枠テンプレートの構造契約を 1 ファイルに集約する**形になる。
  `VerifyEmail` は「本文の『ログアウト』が離脱導線 (POST 遷移のため footer の TextLink では表現しない)」を
  理由に allowlist へ登録する (既存の `PAGECONTENT_ALLOWLIST` と同じ reason 必須方式)。
- **ドキュメント**: `DESIGN.md` の Do's and Don'ts に 2 行 (Do: 認証系画面は離脱導線を footer に置く /
  Don't: 踏破できない導線を表示しない = 表示条件と到達条件を一致させる)。
  `docs/architecture.md` §サブスク契約 Checkout とオンボーディング着地 (P7/P9) に
  「`onboarding.checkout` はメール認証済みが前提。verify notice に checkout への CTA は出さず、
  認証完了後に `VerifyEmailResponse` が着地させる」を追記する。

## 期待効果

- **使命への貢献**: 「専門知識ゼロの現場作業者でも使える」ためには、**詰まったときに画面から抜けられる**ことが
  最低条件。認証ファネルはアプリの最初の関門であり、ここでの行き止まりは
  SOP → シナリオ → 撮影という価値提供の入口をそのまま失う。
- **恒常的に失敗する導線がゼロになる** (F-2-01)。押しても何も起きないボタンによる離脱・不信を除去する。
- **行き止まり画面がゼロになる** (F-2-02 + 2 ページの同種欠落)。
- **再発が構造的に止まる**: 新しい Auth ページが footer 無しで追加されると architecture テストが落ちる。

## 実装方針（概要）

| # | 施策 | 主な変更 |
|---|---|---|
| A | verify notice の CTA 撤去 + 説明化 | `FortifyServiceProvider::verifyEmailView`、`EmailVerificationContinuation` (bool 判定 API 追加)、`VerifyEmail.svelte`、関連テスト、`docs/architecture.md` |
| B | AuthLayout 離脱導線の規約化 | `ResetPassword.svelte` / `TwoFactorChallenge.svelte` / `ConfirmRecentAuth.svelte` に footer 追加、`page-shell-structure.test.ts` に契約追加、各ページの vitest、`DESIGN.md` |

- **テストファースト**: 先に (1) 「未認証で `onboarding.checkout` を GET すると `verification.notice` へ差し戻される」
  という**この bug の根本原因を仕様として固定する Feature テスト**、(2) 「`/email/verify` の props に
  `continueUrl` が存在しない」、(3) architecture テスト、(4) 各ページの footer 描画テストを書き、
  fail を確認してから実装する。
- **DB 変更・route 追加なし**。session キー・middleware 構成も変えない。

## 制約・前提

- `EmailVerificationContinuation` の session ライフサイクル (remember → forget) と
  `VerifyEmailResponse` の着地契約は**変更しない** (施策 A は「認証前の表示」だけを直す)。
- membership 確認 (relation 経由 fetch = IDOR 防御) は単一出典を維持する。
  bool 判定を別実装で書き直さない。
- `Billing/Index` の `continueUrl` prop は `OnboardingReturnResolver` 由来の**別物**であり触らない。
- UI 追加は DS token / atom 経由のみ (`TextLink`)。素の `<a>` は書かない (DESIGN.md)。
- 禁止事項 #8 (必須条件未充足を理由に disabled) に抵触しないこと。
  施策 A は「押せないボタン」ではなく「**そもそも出さない + 理由を説明する**」で解く。

## スコープ外

- **未検証ユーザー向けの公開面 CTA の分岐**: `Welcome.svelte` / `Guest/Pricing.svelte` は
  `page.isAuthenticated` のとき `/dashboard` `/billing/plans` へ誘導するが、
  メール未検証ユーザーはこれらでも `verified` により無言で差し戻される (F-2-01 と同species)。
  修正には共有 props への検証状態の追加とマーケ面の分岐が要り、別 TODO 相当。
  **本設計では新たにこの罠を増やさない** (VerifyEmail から `/pricing` へリンクしない) ことだけを守る。
- プラン変更経路の欠落 (F-3-01)、bfcache/Inertia 履歴 (F-4-01) など他 finding。
- `AuthLayout` ヘッダの「AI-CUE」をリンク化するか否か。footer の明示導線で目的は満たされ、
  ワードマークの暗黙リンクは affordance が弱い。今回は入れない (思考原則 2)。

