## Round 2: Round 1 指摘への対応

Round 1 の指摘に対する対応は以下（対応マトリクス要約）。概念設計を改訂しました。全体判定の再評価をお願いします。

### [Critical] 3. org スコープ導線の出し分け
- 対応（一部反論）: サーバ真実へ寄せる方針は採用。ただし bespoke NavigationItemDTO（href/visible を
  サーバ完全解決）は作らない。参照アプリ aigenba が本番採用している **capability 真偽 shared prop**
  （`canViewCurrentBilling`/`canManageCurrentApiKeys`/`canManageCurrentMembers`/`canCreateOrganization`）
  方式に合わせる。AI-CUE では `currentOrganization` プロップに型付き boolean（`canViewBilling` 等）を
  追加。org-scoped 項目は `currentOrganization != null && <capability>` の二重条件でのみ描画し、href は
  slug から組む（slug 不在時は項目ごと非表示 → null 連結・403/404 導線を作らない）。リンク先が
  サーバ policy と一致することは詳細設計で各項目明記。
- 反論根拠: ユーザー方針「基本コピー・参照側に寄せる」。専用 DTO は参照から乖離し過剰。

### [Warning] 1/4. 効果の誇大
- 対応: 期待効果を二段化。第1段=UI 一貫性・保守性・DRY・認可契約明示（確実）、第2段=業務導線改善
  （Projects 起点の到達短縮、計測前提の仮説）。誇大表現を撤回。

### [Warning] 2. サーバ側テスト未明記
- 対応: Feature テストを追加（未認証で capability=null、権限なしで
  `canViewBilling`/`canManageApiKeys`/`canManageMembers` が false）。

### [Warning] 5-a. 通知の二重化
- 対応: 通知は NotificationBell を単一導線とし、`/notifications` の nav 項目は作らない。

### [Warning] 5-b. 全面置換の横断回帰
- 対応: 回帰観点を 7 点列挙（desktop 折りたたみ、mobile drawer、banner/toast 積み上げ、org 切替、
  logout、未認証時非表示、popover/drawer focus・キーボード）。

### [Warning] 6. スコープ二層化
- 対応: (A) UI shell 置換 と (B) navigation 共有プロップ契約（capability 追加）に分離明記。

### [Warning] 7. 型安全性
- 対応: 追加 prop は `CurrentOrganization` interface に明示 boolean フィールドとして型定義。
  サーバ `currentOrganizationProp` の array shape docblock と TS を 1:1 同期。

### 追加の自発判断
- 独自 `OrganizationSwitcher.svelte`（切替 + org リンク束）は退役し、aigenba 同様「org nav＝サイドバー」
  「org 切替＝下部メニュー」に分離。他利用箇所の波及は詳細設計で grep 確認。

---

## 改訂後 概念設計（全文）

（下記は devnotes/20260716-1757-login-sidebar-nav/conceptual-design.md の現行内容）

# 概念設計: login-sidebar-nav（ログイン後レイアウトの左サイドバー統一）

> Round 1 レビュー反映済み（capability shared prop 契約の分離 / 効果の二段化 / 通知導線の一本化 /
> 回帰観点の列挙 / OrganizationSwitcher 退役方針）。

## 背景・課題

AI-CUE のログイン後レイアウト (`resources/js/components/templates/AppLayout.svelte`) は
**上部ヘッダー型**の独自実装で、ヘッダー右側に OrganizationSwitcher / NotificationBell /
設定 / ログアウトを横並び常設している。コメントにも「サイドバー/Team/Project ナビは後続 Phase」
と明記され、ナビゲーションの本実装が未了のまま暫定ヘッダーで運用されている。

一方、姉妹アプリ **aigenba / spirux**（同じ laravel-claude-template 由来・同じ DS）は
**左サイドバー型**（desktop 固定サイドバー + 折りたたみ + モバイルドロワー + 下部に組織/ユーザー
メニュー）で確立しており、UI 体験がプロダクト間で分岐している。

ユーザー方針: **「UI は基本的に参照アプリ（aigenba/spirux）に合わせる。AI-CUE 独自に作った
UI があれば、その独自版は削除して参照側へ寄せる」**。

## 改善アイデア

aigenba の 3 コンポーネントを AI-CUE へ移植し、上部ヘッダー型 AppLayout を置き換える:

- `templates/AppLayout.svelte` — 左サイドバーシェルに全面刷新
- `templates/_helpers/SidebarNavItems.svelte` — nav item リストの stateless 表示 helper（新規移植）
- `templates/_helpers/SidebarUserMenu.svelte` — 下部ポップアップ（個人/組織設定・法務・ログアウト）の
  stateless 表示 helper（新規移植）

移植は「基本コピー、AI-CUE に無い依存だけ差し替え」で行う:

| aigenba 依存 | AI-CUE での扱い |
|---|---|
| `BrandLogo` molecule | 無し → テキスト `appName` 代替 |
| inline toast（`createToastTimer` + `TOAST_*`） | 既存 `ToastContainer` + `consumeFlash` を再利用（独自 toast は移植しない） |
| `QuotaExceededModal` / `QuotaWarningBanner` | 該当機能なし → 移植しない（別施策） |
| cookie org フォールバック / `present-flash` / `types/*` | 既存 `shared-props.ts` / `flash-to-toast.ts` に寄せる。`currentOrganization` shared prop があるため cookie fallback 不要 |
| org 切替 UI（aigenba は下部メニュー内インライン） | 下部メニューにインライン移植（下記「OrganizationSwitcher 退役」参照） |
| `EmailVerificationBanner` | 既存（利用） |
| `NotificationBell` molecule | 既存（サイドバー内に配置。通知の**単一**導線） |

## スコープ（二層に分離）

### (A) UI shell 置換
上部ヘッダー型 AppLayout → 左サイドバー型 AppLayout + 2 helper への全面刷新。
未使用の `headerActions` prop 廃止（全 24 ページで未使用を grep 確認済み。後方互換の並走を残さない）。
独自 `OrganizationSwitcher.svelte` の退役（下記）。

### (B) navigation 共有プロップ契約（capability 追加）
org スコープ導線の出し分けをサーバ真実に寄せるため、aigenba と同一の **capability 真偽 shared prop**
を `currentOrganization` プロップに型付き boolean で追加する（bespoke NavigationItemDTO は作らない
= 参照準拠）。既存 `canManageMembers` / `canManageApiKeys` は流用し、不足分（請求閲覧 `canViewBilling`）
を追加。org-scoped 項目は `currentOrganization != null && <capability>` の二重条件でのみ描画し、href は
`currentOrganization.slug` から組む（slug 不在時は項目ごと非表示 → null 連結・403/404 導線を作らない）。

## ナビ項目（AI-CUE 実ルートへ翻訳）

| ラベル | href | 表示条件 | アイコン(Lucide) |
|---|---|---|---|
| ダッシュボード | `/dashboard` | ログイン時 | House |
| プロジェクト | `/projects` | ログイン時 | FolderKanban |
| メンバー | `/manage/users` | `currentOrganization.canManageMembers` | UserPlus |
| API キー | `/organizations/{slug}/api-keys` | `currentOrganization.canManageApiKeys` | KeyRound |
| 請求 | `/billing` | `currentOrganization.canViewBilling` | CreditCard |
| 設定 | `/settings` | ログイン時 | Settings |

- **通知は nav 項目にしない**（NotificationBell を単一の主導線とし二重化を避ける）。
- href が org-slug 依存なのは API キーのみ（`/billing` `/manage/users` はフラット route。ただし
  capability で出し分ける）。org-scoped 項目は slug 不在時に非表示。

下部ユーザーメニュー（SidebarUserMenu）のリンク（AI-CUE 実ルートに存在するもののみ）:
- 個人設定 → `/settings`（aigenba の `/profile` は AI-CUE に無い）
- 組織設定 → `/organizations/{slug}/settings`（`canManageMembers` 等の管理ロール時）
- CLI / MCP セットアップ → `/organizations/{slug}/onboarding/cli` `/mcp`（`canManageApiKeys` 時）
- 法務: 利用規約 `/terms`・プライバシー `/privacy`・特定商取引法 `/commerce-disclosure`
- ログアウト → POST `/logout`
- （AI-CUE に無い `/help`・運営会社外部リンクは出さない）

## OrganizationSwitcher 退役

AI-CUE 独自 `features/organizations/OrganizationSwitcher.svelte` は「組織切替 + 組織設定/メンバー/
API キー/請求/料金のリンク束」を内包する。aigenba では「org nav 項目＝サイドバー nav」「org 切替＝
下部メニュー」に分離されているため、ユーザー方針に従い本コンポーネントは退役し:
- org 切替 → 下部メニュー内にインライン移植（aigenba 相当）
- org 設定/メンバー/API キー/請求 → サイドバー nav・SidebarUserMenu に移す

詳細設計で `OrganizationSwitcher` の**他利用箇所（AppLayout 以外からの参照）**を grep で確認し、
波及を施策に明示する。

## 期待効果（二段）

**第1段（確実）**
- UI 一貫性: ログイン後ナビを姉妹アプリ（aigenba/spirux）水準に統一。
- 保守性: 同一構造・helper 分割に揃い、以後の UI 変更を参照アプリと同期取込可能（テンプレ系譜の
  分岐を減らす）。
- DRY: nav item / user menu の desktop/mobile 重複描画を stateless helper に集約。
- 認可契約の明示化: org 導線の可視性をサーバ真実の capability prop に一本化。

**第2段（仮説・計測前提）**
- 業務導線改善: `/projects` 起点で manual/capture へ到達する主要業務の開始までのタップ数/迷いの
  減少。効果は今後の IA 検討または計測で検証する（本 PR では立証しない）。

## 制約・前提

- Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical、ds-purity テストが hex 直書き検出）。
  移植コードに非 token 色・hex 直書きを持ち込まない（aigenba も同 DS のため基本適合するが要検証）。
- component 階層の単方向 import 厳守。helper は `templates/_helpers/` 配下。アイコンは
  `@lucide/svelte` のみ・SVG 直書き新設なし。
- 認可の表示条件は capability shared prop（サーバが真実）で出し分け、リンク先はサーバ policy と一致。
- バックエンド変更は `HandleInertiaRequests` の `currentOrganization` プロップへの capability boolean
  追加のみ（Inertia プロップ経由。JsonResource 不要な shared props の範疇）。array shape docblock と
  `shared-props.ts` の型を 1:1 同期。

## 回帰観点（全面置換の横断影響）

最低限、以下を JS/Feature テストまたは手動確認で担保する:
1. desktop サイドバー 展開/折りたたみ（localStorage 永続）
2. mobile drawer 開閉 + オーバーレイ
3. `EmailVerificationBanner` / flash→toast の積み上がり（重複描画なし）
4. org 切替（下部メニュー）
5. logout POST `/logout`
6. 未認証（ゲスト到達ページ）で nav / 設定 / ログアウトを描画しない
7. popover / drawer の focus 管理・キーボード（Escape / outside click）

## テスト計画（概要）

- JS: `tests/js/components/templates/AppLayout.test.ts` を新構造へ更新。上記回帰観点のうち
  ナビ存在・未認証時非表示・logout POST・org 切替トリガー・折りたたみを代表検証。
- Feature（サーバ）: `currentOrganization` の capability prop 出し分けを検証（未認証で null、
  権限なしで `canViewBilling`/`canManageApiKeys`/`canManageMembers` が false）。

## スコープ外

- 個々のページ内容（Dashboard / Projects 等）の再設計（シェルのみ）。
- Quota 警告バナー / QuotaExceededModal の移植（billing 機能が要るため別施策）。
- BrandLogo（ロゴ molecule）新規作成（テキスト appName 代替。ロゴ導入は別途）。
- spirux 固有差分の取り込み（aigenba を代表参照とする）。
- org-slug ベースへの全ルート移行（現行フラットルート維持、nav の href のみ合わせる）。

