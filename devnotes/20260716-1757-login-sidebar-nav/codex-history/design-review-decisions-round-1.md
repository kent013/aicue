# 対応マトリクス: design-review Round 1（CHANGES_REQUESTED）

## S1 [Critical] id と slug の責務境界
- 判断: 対応する
- 対応: 切替候補の現在判定は `org.id === currentOrganization.id` に統一。slug は org-scoped URL 生成時に
  `currentOrganization.slug` からのみ参照（`OrganizationSummary` は slug を持たないため URL 生成に使わない）。
  設計に「id=切替/現在判定, slug=URL 生成」の責務境界を明記。

## S1 [Critical] isActive の過判定
- 判断: 対応する
- 対応: `isActive(href)` を「完全一致 + 明示的な配下許可ルート集合」に限定。prefix 許可は `/projects`
  （プロジェクト詳細 `/projects/{id}` を親 nav でアクティブ表示）のみ。他（/dashboard, /billing, /settings,
  /manage/users, /organizations/{slug}/api-keys）は完全一致。実装は
  `const PREFIX_ACTIVE = new Set(['/projects']); isActive = (h) => path === h || (PREFIX_ACTIVE.has(h) && path.startsWith(h + '/'))`。

## S1 [Warning] ゲスト時のメニュー DOM/イベント
- 判断: 対応する
- 対応: `showAccountNav = shared.auth?.user != null`。false 時は nav / 下部メニュー / ベル / mobile drawer の
  DOM とイベント登録（outside-click / Escape / focus）を **`{#if showAccountNav}` で完全バイパス**し、
  `{@render children()}` のみ描画。

## S1 [Suggestion] localStorage キー衝突
- 判断: 対応する
- 対応: キー名を `aicue:layout:sidebarOpen` にする（アプリ固有名前空間）。

## S1 追加: NotificationBell 配置と重複
- 判断: 対応する（Codex S6 [Warning] とも関連）
- 対応: 通知導線はベルのみ（nav 項目にしない）。ベルは **desktop シェルと mobile シェルにそれぞれ 1 個**
  配置し、testId を分ける（desktop `notification-bell` / mobile `notification-bell-mobile`）。同一シェル内では
  単一。テストは desktop シェルで `notification-bell` が 1 個であることを固定。

## S2 [Warning] icon 型
- 判断: 対応する
- 対応: helper に `export interface SidebarNavItem { href: string; label: string; icon: Component }`
  を module script で export（`import type { Component } from 'svelte'`。Svelte 5 のため `ComponentType`
  ではなく `Component`）。AppLayout の navItems はこの型に一致させる。

## S2 [Suggestion] onNavigate 既定
- 判断: 対応する
- 対応: `onNavigate?: () => void` の未指定時 no-op（`onclick={() => onNavigate?.()}` で吸収）。

## S3 [Critical] helper 側 null ガード
- 判断: 対応する
- 対応: `settingsHref` / `cliHref` / `mcpHref` を `string | null` 型にし、helper は
  **`{#if href}` のときのみリンク描画**する契約を明記（親が null を渡せば非表示 = 403 導線を作らない）。

## S3 [Warning] profileTestId 命名ずれ
- 判断: 対応する
- 対応: `profileTestId` → `settingsTestId` に改名（個人設定リンク先が `/settings` のため）。testId 値は
  `nav-settings`（AppLayout.test 互換）。後方互換の旧名は残さない。

## S3 [Suggestion] 法務リンク常時表示
- 判断: 対応する
- 対応: 法務（/terms, /privacy, /commerce-disclosure）は public のため認証状態非依存で常時表示。
  テスト観点に追加。

## S4 [Warning] 削除残骸
- 判断: 対応する
- 対応: `OrganizationSwitcher.svelte` に加え、専用テスト
  `tests/js/components/features/organizations/OrganizationSwitcher.test.ts`（存在確認済み）を同 PR 削除。
  barrel/index export・story・fixture の残骸を grep で確認し全撤去。

## S6 [Critical] 認可負例の不足
- 判断: 対応する
- 対応: AppLayout.test に負例を追加:
  1) `canManageMembers=false` → メンバー導線（/manage/users）非表示
  2) `canManageApiKeys=false` → API キー導線非表示
  3) `currentOrganization=null` → org-scoped 導線（プロジェクト/請求/組織設定/CLI/MCP）非表示
  （ダッシュボード・設定・法務・ログアウトは表示のまま）

## S6 [Warning] notification-bell 重複検証
- 判断: 対応する
- 対応: desktop シェル描画テストで `getAllByTestId('notification-bell').length === 1` を固定
  （mobile 用は `notification-bell-mobile` で別 testId のため衝突しない）。

## S6 [Suggestion] localStorage 再マウント復元
- 判断: 対応する
- 対応: `aicue:layout:sidebarOpen=false` を localStorage に置いた状態で再マウントし、折りたたみ状態が
  復元されることを検証。

## S7 [Critical] 存在確認のみは弱い
- 判断: 対応する
- 対応: Feature テスト「sidebar visibility contract」を追加し、`currentOrganizationProp` の shape 期待値を
  固定: (a) owner/admin で canManageMembers/canManageApiKeys=true, (b) 一般メンバーで false,
  (c) 未認証で currentOrganization=null, (d) 別組織で付与した権限が current org に漏れない（cross-org）1 ケース。

## S7 [Warning] 既存仕様依存の明文化
- 判断: 対応する
- 対応: テスト名/docblock に「sidebar visibility contract」を含め、UI 可視条件がこの shared prop に依存する
  ことを明示（将来の破壊を検知）。
