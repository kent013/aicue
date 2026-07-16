## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ（Laravel/Svelte エコシステムの既存解を使う）。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから微調整せよ。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

補足コンテキスト:
- 参照アプリ aigenba は同じ laravel-claude-template 由来・同じ DS。左サイドバー型 AppLayout（desktop 固定サイドバー + 折りたたみ + モバイルドロワー + 下部に組織/ユーザーメニュー）を持つ。本設計はそれを AI-CUE に移植し、AI-CUE 独自の上部ヘッダー型 AppLayout を廃する。
- ユーザーの明示方針: 「UI は基本的に参照アプリに合わせる。独自に作った UI があれば削除して参照側へ寄せる」。
- AI-CUE の frontend 規約: Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical、ds-purity テストが hex 直書きを検出）。component 階層は atoms→molecules→organisms→features→templates→pages の単方向 import（テストが強制）。アイコンは @lucide/svelte のみ。
- headerActions prop は全 AppLayout 利用 24 ページで未使用（grep 済み）。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: login-sidebar-nav（ログイン後レイアウトの左サイドバー統一）

## 背景・課題

AI-CUE のログイン後レイアウト (`resources/js/components/templates/AppLayout.svelte`) は
**上部ヘッダー型**の独自実装で、ヘッダー右側に OrganizationSwitcher / NotificationBell /
設定 / ログアウトを横並び常設している。コメントにも「サイドバー/Team/Project ナビは後続 Phase」
と明記され、ナビゲーションの本実装が未了のまま暫定ヘッダーで運用されている。

一方、姉妹アプリ **aigenba / spirux**（同じ laravel-claude-template 由来・同じ DS）は
**左サイドバー型**のナビゲーション（desktop 固定サイドバー + 折りたたみ + モバイルドロワー +
下部に組織/ユーザーメニュー）で確立しており、UI 体験がプロダクト間で分岐している。

ユーザー方針: **「UI は基本的に参照アプリ（aigenba/spirux）に合わせる。AI-CUE 独自に作った
UI があれば、その独自版は削除して参照側へ寄せる」**。したがって本件は「上部ヘッダー型 AppLayout
を廃し、aigenba の左サイドバー構成を移植する」ことがゴール。

## 改善アイデア

aigenba の 3 コンポーネントを AI-CUE へ移植し、上部ヘッダー型 AppLayout を置き換える:

- `templates/AppLayout.svelte` — 左サイドバーシェル（desktop 固定 + 折りたたみ + モバイルドロワー +
  下部に組織スイッチャー/ユーザーメニュー）に全面刷新
- `templates/_helpers/SidebarNavItems.svelte` — nav item リストの stateless 表示 helper（新規移植）
- `templates/_helpers/SidebarUserMenu.svelte` — 下部ポップアップ（個人/組織設定・法務リンク・
  ログアウト）の stateless 表示 helper（新規移植）

移植にあたり AI-CUE に存在しない参照側依存は持ち込まず、AI-CUE の既存機構へ最小翻訳する
（＝「基本コピー、無い依存だけ差し替え」）:

| aigenba 依存 | AI-CUE での扱い |
|---|---|
| `BrandLogo` molecule | AI-CUE に無し → テキスト `appName` で代替 |
| inline toast（`createToastTimer` + `TOAST_*`） | AI-CUE 既存の `ToastContainer` + `consumeFlash` を再利用 |
| `QuotaExceededModal` / `QuotaWarningBanner` | AI-CUE に該当機能が無い → 移植しない |
| cookie ベースの org フォールバック / `present-flash` / `types/Flash` | 既存 `shared-props.ts` / `flash-to-toast.ts` に寄せる。AI-CUE は `currentOrganization` shared prop があるため cookie fallback 不要 |
| org 切替 UI | AI-CUE 既存 `OrganizationSwitcher` を下部メニュー内で再利用 |
| `EmailVerificationBanner` / `NotificationBell` | AI-CUE に既存（そのまま利用） |

ナビ項目は AI-CUE の実ルートに翻訳する:

| ラベル | href | 表示条件 |
|---|---|---|
| ダッシュボード | `/dashboard` | 常時 |
| プロジェクト | `/projects` | 常時 |
| 通知 | `/notifications` | 常時 |
| 請求 | `/billing` | currentOrganization あり & 閲覧権限 |
| API キー | `/organizations/{slug}/api-keys` | currentOrganization.canManageApiKeys |
| 設定 | `/settings` | ログイン時 |

下部ユーザーメニューのリンクも AI-CUE 実ルートに合わせる（個人設定 `/settings`、組織設定
`/organizations/{slug}/settings`、CLI/MCP `/organizations/{slug}/onboarding/cli|mcp`、
法務 `/terms` `/privacy` `/commerce-disclosure`、ログアウト POST `/logout`）。AI-CUE に無い
`/help`・運営会社外部リンクは出さない。

## 期待効果

- 使命への貢献（間接）: ナビゲーションの一貫性・発見性を姉妹アプリ水準に引き上げ、プロジェクト/
  マニュアル/撮影導線への到達を改善。暫定ヘッダーで宙づりの本実装ナビを確定させる。
- 保守性: aigenba/spirux と同一構造・helper 分割に揃い、以後の UI 変更を参照アプリと同期取込可能。
- DRY: nav item / user menu の desktop/mobile 重複描画を stateless helper に集約。

## 実装方針（概要）

1. AppLayout.svelte を aigenba 版構造で全面書き換え。ただし AI-CUE 既存機構
   （consumeFlash/ToastContainer/OrganizationSwitcher/shared-props.ts 型）へ配線し、無い依存
   （Quota/BrandLogo/cookie fallback/独自 toast）は落とす。
2. _helpers/SidebarNavItems.svelte・_helpers/SidebarUserMenu.svelte を移植（Lucide のみ依存）。
3. nav 項目・user menu リンクを AI-CUE 実ルート + shared prop 表示条件に翻訳。
4. shared prop 不足分（請求閲覧可否 canViewBilling 等）を HandleInertiaRequests の
   currentOrganization プロップに追加し、shared-props.ts の型も同期。既存 canManageMembers/
   canManageApiKeys は流用。
5. 廃止: 上部ヘッダー型レイアウト実装、未使用 headerActions prop。
6. テスト tests/js/components/templates/AppLayout.test.ts を新構造へ更新。常設ナビの存在・
   非ログイン時非表示・ログアウト POST の回帰は維持。

## 制約・前提

- Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical、ds-purity テスト検出）。hex 直書き・
  非 token 色を持ち込まない。
- component 階層の単方向 import 厳守。helper は templates/_helpers/ 配下。アイコンは @lucide/svelte のみ。
- 認可の表示条件は shared prop（サーバが真実）で出し分け、リンク先はサーバ policy と一致
  （サイドバーから 403 へ到達させない）。
- バックエンド変更は shared prop 追加のみ（Inertia プロップ経由）。

## スコープ外

- 個々のページ内容の再設計（シェルのみ）。
- Quota 警告バナー / QuotaExceededModal の移植（別施策）。
- BrandLogo の新規作成（テキスト appName 代替）。
- spirux 固有差分の取り込み（aigenba を代表参照）。
- org-slug ベースへの全ルート移行（現行フラットルート維持、nav href のみ合わせる）。
