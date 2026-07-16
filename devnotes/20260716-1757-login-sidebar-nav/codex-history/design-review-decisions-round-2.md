# 対応マトリクス: design-review Round 2（CHANGES_REQUESTED → 残 S1/S6）

## S1 [Warning] NotificationBell 二重マウントの副作用
- 判断: 対応する（Codex 提示の「純表示なら二重マウント許容」選択肢を採用）
- 根拠: `NotificationBell.svelte` はソース確認済みで**完全な純表示**（props `unreadCount`/`testId`,
  `$derived` バッジ, `<Link>` のみ。`onMount`/`$effect`/fetch/store 購読なし）。副作用の二重実行は
  発生しない。これは aigenba が `SidebarNavItems` を desktop/mobile 両 aside に二重マウントするのと同型。
- 対応: 設計に二重マウントの根拠を明記。S6 に「render 後、副作用系（fetch/router）mock が未呼び出し」の
  assert を追加し純表示を固定。

## S1 [Warning] isActive 記述の二重化
- 判断: 対応する
- 対応: 旧記述「`page.url` の pathname と href の一致 or `startsWith(href + '/')`」を削除し、
  `PREFIX_ACTIVE`（`/projects` のみ prefix 許可）方式のみを canonical に一本化。

## S1 [Warning] path の導出未定義（query/hash）
- 判断: 対応する
- 対応: `const path = $derived(new URL(page.url, 'http://localhost').pathname)` を明記。query/hash を除いた
  pathname で完全一致するため `/settings?tab=security` も `/settings` として active 判定。

## S1 [Suggestion] ゲスト時記述の曖昧さ
- 判断: 対応する
- 対応: 「アカウント系 UI のみ非描画」と明確化。`ToastContainer` / 外側レイアウト / `children` は描画継続。

## S6 [Warning] 通知テストが 2 マウントを隠す
- 判断: 対応する（S1 の二重マウント根拠と整合）
- 対応: desktop `notification-bell`=1 / mobile `notification-bell-mobile`=1（合計 2 は意図的な viewport 別
  シェル配置）と明示し、加えて「NotificationBell が副作用を発火しない」ことを assert して隠蔽でないことを担保。

## S6 [Warning] 主要インタラクションの自動テスト不足
- 判断: 対応する
- 対応: (a) `org-switch-{id}` 押下で正しい id の `router.post('/organizations/{id}/switch')` 1 回,
  (b) mobile drawer 開閉（メニューボタン開き / 閉じるボタン・オーバーレイ閉じ）,
  (c) Escape で user menu 閉鎖 を AppLayout.test に追加。

## S6 [Suggestion] 法務リンク「常時表示」の限定
- 判断: 対応する
- 対応: 「認証済み user menu 内で常時表示。ゲスト時は user menu 自体非描画のため非表示」と限定。

## APPROVE 済み施策（S2/S3/S4/S5/S7）
- 変更なし。Round 2 で APPROVE。
