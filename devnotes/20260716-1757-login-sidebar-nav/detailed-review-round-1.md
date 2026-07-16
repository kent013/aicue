以下、提示された詳細設計に対するレビューです（**テキストのみ分析**・実行/編集なし）。

**全体判定**
- **CHANGES_REQUESTED**

---

**S1 AppLayout をサイドバー型に全面刷新**: **REQUEST_CHANGES**
- [Critical] `OrganizationSummary` に `slug` が無い前提なのに、設計内で「組織切替候補 `organizations` を使い、現在組織は slug で判定/表示整合」を混在させており、実装時に比較キーが崩れるリスクがあります。  
  修正案: 切替候補側の現在判定は **`org.id === currentOrganization.id`** に統一し、slug は「org-scoped URL 生成時に currentOrganization からのみ参照」と明記。
- [Critical] `isActive` を `startsWith(href + '/')` の汎用判定だけにすると、`/organizations/{slug}/...` のような動的導線や `/billing` 配下将来拡張時に誤判定/過判定が起き得ます。  
  修正案: `isActive` 仕様を「完全一致 + 明示的な配下許可ルート集合（必要最小）」に寄せる（例: `/projects` のみ prefix 許可）。
- [Warning] ゲスト到達時「children のみ描画」は妥当ですが、`mobileMenuOpen/userMenuOpen` の開閉ハンドラが残ると不要なフォーカストラップや Esc ハンドリングが走る余地があります。  
  修正案: `showAccountNav === false` 時はメニュー関連 DOM/イベント登録を完全にバイパスする条件を明文化。
- [Suggestion] `sidebarOpen` の localStorage キー名をアプリ固有（例: `aicue:layout:sidebarOpen`）にして衝突回避を推奨。

---

**S2 SidebarNavItems helper 新規移植**: **APPROVE**
- [Warning] `icon` 型は `Component` 系の型差異で TS 推論が崩れやすいです。  
  修正案: 参照実装と同じ型定義（`Component` あるいは Lucide コンポーネント型）を明記し、`items` の型エイリアスを helper 側で export。
- [Suggestion] `onNavigate` 未指定時 no-op を明示して呼び出し側分岐を減らすと安全。

---

**S3 SidebarUserMenu helper 新規移植**: **REQUEST_CHANGES**
- [Critical] 「サイドバーから 403 導線を作らない」要件に対し、`settingsHref/cliHref/mcpHref` を親で null 制御する設計は良いが、helper 側で `href` の null 安全が曖昧です。  
  修正案: helper は **`href !== null` のときのみリンク描画**を契約として明記（型も `string | null`）、`#if` ガード必須。
- [Warning] `/settings` を `profileTestId` で扱う命名は意味ズレがあります。  
  修正案: `profileTestId` を `settingsTestId` に改名（後方互換を残さない方針に一致）。
- [Suggestion] 法務リンクは public のため、認証状態に依存せず常時表示で問題ないことをテスト観点に追加すると堅い。

---

**S4 OrganizationSwitcher 退役**: **APPROVE**
- [Warning] 削除時に import 残骸（`AppLayout` 以外の barrel/export, story, test fixture）漏れが起こりやすいです。  
  修正案: 設計に「未使用 export/テスト fixture の同PR削除」を明記。

---

**S5 headerActions prop 廃止**: **APPROVE**
- [Suggestion] `Props` 変更に伴う型エラーは歓迎（破壊的変更）なので、移行レイヤーを置かない方針を明記済みで良いです。

---

**S6 AppLayout.test.ts 更新**: **REQUEST_CHANGES**
- [Critical] 禁止事項「テストなし完了報告禁止」に対し、現行計画は主要表示確認中心で、**認可連動の負例**（見えないこと）の網羅が不足。  
  修正案: 少なくとも以下を追加  
  1) `canManageMembers=false` でメンバー導線非表示  
  2) `canManageApiKeys=false` で APIキー導線非表示  
  3) `currentOrganization=null` で org-scoped 導線（組織設定/CLI/MCP/請求）非表示
- [Warning] `notification-bell` を「1箇所のみ」とする要件に対して、mobile/desktop 同時DOM存在時の重複検証が必要。  
  修正案: viewport条件に依存しないテストでは `getAllByTestId('notification-bell').length === 1` を明示。
- [Suggestion] localStorage 永続テストは「再マウントで復元」まで見ると退行検知力が上がります。

---

**S7 capability shared prop 回帰確認/補強**: **REQUEST_CHANGES**
- [Critical] 「存在確認のみ」は弱く、今回UI可視条件の根幹変更に対して回帰防止が不十分です。  
  修正案: Featureテストに **shared props shape の期待値固定** を追加（`currentOrganization.canManageMembers/canManageApiKeys` が org 文脈で正しく変化、未認証時 null）。cross-org も 1 ケースを明示固定。
- [Warning] 「新規 shared prop は不要」は妥当だが、`HandleInertiaRequests` の既存仕様依存を明文化しないと将来破壊されやすいです。  
  修正案: テスト名に「sidebar visibility contract」等、用途を含める。

---

**観点別サマリ**
- 正確性/Null安全: `id` と `slug` の責務境界を明文化すれば改善。
- 既存整合性: 参照移植方針は妥当、ただし testId/命名ずれ要修正。
- PHPStan Lv10: サーバ変更ゼロ想定は良い。TS型契約（`href: string | null`）を厳密化すべき。
- DTO/JsonResource: 逸脱なし。
- Inertia Props vs API: 今回は Inertia props のみで正しい。
- セキュリティ: 403導線回避方針は良いが、**helper側 null ガードを仕様化**しないと崩れる。
- DESIGN/Atomic: 方向性は適合。helper配置も規約準拠。

必要なら、この設計を**そのまま実装可能な「修正後チェックリスト版（PR受入基準）」**に落として返します。