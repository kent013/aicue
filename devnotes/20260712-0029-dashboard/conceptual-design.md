# 概念設計: dashboard（進行中ジョブ / 最近のマニュアル / 残高）

## 背景・課題

ログイン直後の着地点 `/dashboard` は、現状 `routes/web.php` の inline closure（`Inertia::render('Dashboard')`）と 52 行の雛形 `Dashboard.svelte`（「まだコンテンツがありません」の EmptyState のみ）のまま。T001〜T008 でマニュアル作成・AI 解析・レンダ・撮影 PWA・通知センター・チケット課金が実装済みなのに、ログイン直後に「今どこにいて次に何をすべきか」が一切見えない。

- 解析/レンダの進行状況は各マニュアルの Show 画面まで潜らないと見えない
- 撮影者は「どのマニュアルに採用待ちのカットがあるか」を知る導線がない
- チケット残高・容量は `/purchase-tickets` `/billing` まで行かないと見えない（残高不足に気づくのがジョブ失敗時になる）

AI-CUE の使命は「思考ゼロ・編集ゼロ」。ログイン直後の画面が「次にすべき 1 アクション」を提示しないのは使命との不整合である。

## 改善アイデア

inline closure を廃し、`DashboardController`（薄い・Service 委譲）+ `Dashboard/DashboardService`（サーバ集計）+ DTO で実ダッシュボードを実装する。v1 は**固定レイアウト**で以下の 5 ブロックに絞る（過剰にしない）:

| # | ブロック | 内容 | 導線 |
|---|---------|------|------|
| 1 | 進行中ジョブ | status が analyzing / rendering のマニュアルを進捗付きで表示（AnalysisJob / RenderJob の queued・running の progress + ジョブ updated_at の「最終更新」表示 = スナップショットの stale さを明示） | `projects.manuals.show`（「詳細で最新の進捗を確認」導線として明示。既存ポーリングパネルへ。ダッシュボード自体はポーリングしない。完了の気づきは T008 通知ベルと整合） |
| 2 | 最近のマニュアル | updated_at 降順に 5 件（status バッジ・カテゴリ） | show / edit（編集者のみ edit） |
| 3 | 未読通知サマリ | 未読数 + 通知一覧への導線。**shared props `notifications.unreadCount`（T008 ベルと同一 closure）をフロントで再利用**し、サーバ側の二重集計を作らない | `notifications.index` |
| 4 | チケット残高 + 容量 | `TicketLedgerService::balance`、`StorageUsageService::occupiedBytes` + `QuotaService::limits`（max_storage_bytes）の使用率。残高が `billing.ticket_low_balance_threshold` 未満なら購入 CTA を強調 | `billing.tickets.show`（T007） |
| 5 | クイックアクション | 新規マニュアル作成 / カテゴリ管理 / 撮影 PWA を開く | `projects.manuals.create` / `projects.categories.index` / `capture.home` |

### ロール差（編集者 / 撮影者）

サーバが `role: 'editor' | 'shooter' | 'viewer'` を導出して props で渡す（判定は既存 Policy と同じ経路 = `$user->can('update', $project)` → editor、`$user->can('capture', $project)` → shooter、いずれも不可の組織メンバー → viewer。`laratrust_team_id` 明示判定は ProjectPolicy 内に既に集約済みで、それに委譲する）:

- **編集者**: 上記 1〜5 をフル表示（企画/解析/レンダ中心）
- **撮影者**: ブロック 2 の代わりに「**撮影対象**」= ready/published かつ 採用テイクなしの Cut（`whereDoesntHave('adoptedTake')`）を持つマニュアルを未採用カット数付きで表示し、撮影 PWA（`capture.manuals.show`）へ直行させる。編集者専用のクイックアクション（新規作成/カテゴリ管理）は**非表示**（disabled 禁止規約に従い、権限がないものはそもそも描画しない）
- **編集者にも撮影対象ブロックは併記**する（編集者は撮影も可能 = ProjectPolicy::capture の実装事実に合わせる）が、レイアウト上の主役は 1・2

### 表示組織の解決規則（current org null ≠ 組織なし）

`current_organization_id` は「所属組織 0 件」以外でも null になり得る（`OrganizationMembershipService::removeMember` が current org からの除名時に null 化し「次回アクセス時に選び直す」とコメントしているが、**選び直す実装は現状どこにも存在しない**）。この選び直しを**再利用可能な専用 Service `App\Services\Organization\CurrentOrganizationResolver`（新規）に集約**し、ダッシュボードを v1 の唯一の呼び出し元とする（他画面への展開は後続。将来 `ResolvesCurrentOrganization` trait からも利用可能な配置）。

解決契約（`resolve(User): ?Organization`）— **表示の安全性は「読み出し時の所属再確認」で担保し、書き込みは best-effort の冪等修復**とする:

1. `current_organization_id` 非 null → `$user->organizations()->whereKey($id)->first()`（pivot relation 経由）で**所属を再確認してから**返す。所属が確認できない dangling id（除名との競合残滓）は「未設定」として 2 へ倒す = **非所属 org のデータを描画する経路を構造的に持たない**（cross-org 不変条件は読み出し側で常に成立）
2. 未設定/dangling かつ所属組織あり → `$user->organizations()->orderBy('organizations.id')` で決定的に候補を選び、**単一の条件付き UPDATE で自己修復**: `WHERE id = :user AND (current_organization_id IS NULL OR current_organization_id = :観測した dangling 値) AND EXISTS(所属 pivot)` を満たすときのみ設定（原子的。除名 tx が先に commit していれば EXISTS が偽になり修復しない。current が観測後に別 org へ変更されていたら WHERE 不一致で上書きしない）。**UPDATE の成否にかかわらず relation キャッシュを破棄して User を DB から fresh 再取得**し、その最新値に対して 1 と同じ「所属再確認つき読み出し」を行って返す（再確認は 1 回のみ。それでも解決不能なら null = 無限再試行しない）
3. 所属組織 0 件（または競合で候補消滅）→ null（画面は組織作成 CTA の setup 表示 = `organizations.create`）

`removeMember` との競合はいずれの順序でも: (a) 除名先行 → EXISTS 偽で修復せず setup/別 org 表示、(b) 修復先行 → removeMember が detach + current null 化（同一 tx）で回収、(c) removeMember がメモリ上の stale 値比較で null 化をスキップする残余 window は dangling id となり得るが、**1 の所属再確認により描画には決して現れず**、次回 resolve の 2 が dangling を修復する。この読み出し安全性（「非所属 org を current に持つユーザーに当該 org のデータを描画しない」）を Feature テストで固定する。

追加 Feature テスト（Resolver の競合契約を固定する）:
- 「org はあるが current null → 自己修復して 200 + 当該 org のデータ表示」
- 「current が非所属 org を指す（手動作成の dangling）→ 当該 org のデータを描画しない」
- 「候補 membership が UPDATE 前に消失（EXISTS 偽）→ current を設定しない」
- 「観測後に current が別 org へ変更済み → その変更を上書きしない」
- 「条件付き UPDATE が 0 件 → fresh 再取得した最新状態で解決する（1 回のみ・解決不能なら null）」

### 状態分岐（詰み防止）

`/dashboard` はログイン直後の着地点なので**どの状態でも 404 / redirect ループにしない**:

- 所属組織 0 件 → setup 表示（上記 3）
- org はあるが project なし（provisioning は project を作らない）→ プロジェクト作成 CTA。表示判定は既存 `ProjectPolicy::create`（`$user->can('create', [Project::class, $organization])` = organizationRole の `laratrust_team_id` 明示判定に委譲。ad hoc 判定を書かない）。権限なしメンバーには案内文のみ
- project はあるがマニュアル 0 件 → 「最初のマニュアルを作成」CTA を明示した空状態（編集者）/ 「撮影対象はまだありません」（撮影者）
- サブスク未契約 org → ダッシュボードは表示し（課金ゲート外に置く。通知センターと同じ理由 = 失効中でも状況把握と復帰導線が必要）、購読導線の callout を出す。callout / 残高 CTA の遷移先は **`billing.index` / `billing.tickets.show` に固定**する。両 route は `routes/web.php` で `require-active-subscription` group の**外**に登録済み（route コメントで「未契約でも checkout に到達できることを保証」と明文化されている既存事実）= 未契約時も dead-end / redirect loop にならない。この不変条件は DashboardTest で「未契約 org: dashboard 200 + CTA 遷移先 route も 200」として固定する。業務 route（manuals 等）への遷移は既存 middleware が billing へ誘導する（ダッシュボード側で二重実装しない）

## 期待効果

- **使命への貢献**: 「次に何をすべきか」（解析待ち→撮影待ち→レンダ待ち→公開）を一画面で提示し、思考ゼロの日常導線を完成させる。T001〜T008 の機能が初めて 1 つの動線に束なる
- 撮影者がログイン→撮影対象把握→PWA 起動まで 2 クリックになる（現状は導線なし）
- 残高/容量の可視化により、**残高不足と容量逼迫の早期気づきを増やす**（UI では低残高警告と高使用率警告を別個の表示として扱う。事後発覚 = ジョブ実行時エラー / アップロード拒否の頻度低減が狙い）
- 通知・ジョブ進捗の「今」が見えることで、ポーリング画面への過剰な張り付きを減らす

## 実装方針（概要）

### バックエンド

- `routes/web.php`: inline closure を `DashboardController` に差し替え（`auth`+`verified` group 内・**課金ゲート外**のまま。route param なし = NestedRouteIdorDefenseTest inventory 対象外、tenant キー payload なし）
- `app/Http/Controllers/DashboardController.php`（新規・薄い）: `ResolvesCurrentOrganization` は使わない（current org なしで 404 にしないため）。`CurrentOrganizationResolver::resolve()`（新規・上記「表示組織の解決規則」）で表示組織を nullable に解決 → `DefaultProjectResolver::resolve()`（v1 単一 Default Project 規約の既存 SSOT）→ `DashboardService` に委譲
- `app/Services/Organization/CurrentOrganizationResolver.php`（新規）: 所属再確認つき読み出し + 条件付き UPDATE による自己修復（上記契約）
- `app/Services/Dashboard/DashboardService.php`（新規）: 全ブロックのサーバ集計。**固定本数のクエリで N+1 なし**:
  - 進行中: `$project->manuals()->whereIn('status', [Analyzing, Rendering])` + 進行中ジョブは `AnalysisJob` / `RenderJob` を `whereIn('video_manual_id', $ids)` + `whereIn('status', [Queued, Running])` の 2 クエリで引き当て（in-flight は manual×操作種別あたり 1 本の既存不変条件 = §10.8-8 を利用）
  - 最近: `$project->manuals()->with('category')->orderByDesc('updated_at')->limit(5)`
  - 撮影対象: `whereIn('status', [Ready, Published])` + `whereHas('cuts', whereDoesntHave('adoptedTake'))` + `withCount` で未採用カット数（`CaptureManualController::index` の relation 経由規約を踏襲）
  - 残高/容量: 既存 `TicketLedgerService::balance` / `StorageUsageService::occupiedBytes` / `QuotaService::limits` を**そのまま呼ぶ**（二重実装しない）
- `app/DataTransferObjects/Dashboard/` に**ブロック単位の DTO を分割**して新規作成（`DashboardPageData` を頂点に `InProgressManualData` / `RecentManualData` / `ShootingTargetData` / `BillingSummaryData` 等。nullable 状態を各 DTO で明示し、ページ全体を 1 つの巨大 typed array に押し込まない）。typed array（`toArray(): array{...}`）で Inertia props の shape を固定（PHPStan lv10）
- 権限: 集計は relation 経由の org / project スコープのみ（cross-org 構造的不可）。ロール導出は ProjectPolicy 委譲（`laratrust_team_id` 明示判定を再実装しない）
- `response()->json()` 直書きなし（Inertia render のみ）、`redirect()->intended()` 不使用

### フロントエンド

- `resources/js/pages/Dashboard.svelte` を全面書き換え（AppLayout + StatCard / Card / EmptyState の既存 atom/molecule を再利用。DS token のみ・Lucide のみ・atomic 単方向 import 遵守）
- `resources/js/types/dashboard.ts`（新規）: props の TS interface（PHP typed array と対で保守）。ページの型契約は **`DashboardProps`（ページ固有 props）と既存 `SharedProps` の合成**として 1 箇所に定義する（`page.props as unknown as (DashboardProps & SharedProps)` の既存パターン。未読数は SharedProps 側の `notifications.unreadCount` を型経由で参照 = 契約を 2 系統に割らない）
- 未読通知タイルは shared props `notifications.unreadCount` を参照（ベルと同源。サーバ二重集計なし）
- 空状態は EmptyState + 作成 CTA。**disabled ボタンは一切作らない**（権限がない導線は非描画）

### テスト

- Pest Feature `tests/Feature/DashboardTest.php`: 進行中ジョブ/最近マニュアル/撮影対象/残高/容量の集計正当性、**cross-org のマニュアル・ジョブが混入しない**、ロール別 props（editor/shooter/viewer）、空状態（org なし/project なし/マニュアル 0 件）、**org はあるが current org null → 自己修復して 200**、未契約 org でも 200 + CTA 遷移先（billing.tickets.show / billing.index）も未契約で 200、ゲスト 302（既存挙動維持）
- 既存テスト: `/dashboard` を参照するテスト（SmokeTest / TwoFactorEnforcementTest / InvitationTest 等）は「200 で表示される」前提のため**互換維持**（org なしユーザーでも 200 を返す設計はこの互換のため）
- Vitest: カード/スタットタイル描画・ロール別表示・空状態・`disabled` 属性が存在しないこと

## 制約・前提

- 既存フェーズ 1〜T008 の規約を踏襲: Controller 薄い + Service 委譲、Inertia typed array + TS interface、保護キー不信、親委譲 Policy、org-scoped 解決、DS token / Lucide / atomic 階層
- v1 = 単一 Default Project（`DefaultProjectResolver` が SSOT。複数 project 化の際は resolver 差し替えのみ）
- **業務データは読み取り専用**（シナリオ整合ロック規約・Quota 予約規約の対象外。既存の書き込み Service を一切触らない）。**唯一の例外は current organization の冪等な整合修復**（`CurrentOrganizationResolver` 経由の条件付き UPDATE のみ。この書き込み経路と読み出し安全性は Feature テストに登録する）
- 通知未読数は shared props と同源（HandleInertiaRequests の closure）。ダッシュボード専用の通知クエリは追加しない
- ジョブ進捗のリアルタイム更新（ポーリング/WebSocket）はダッシュボードでは行わない（詳細は manuals.show の既存ポーリングパネルが担う。二重化しない）
- 実装は「バックエンド集計（Controller/Service/DTO + Pest）」→「フロント表示（Svelte + Vitest）」の順にコミットを分け、レビュー単位を小さく保つ（詳細設計の実装モードに明記する）

## スコープ外

- カスタマイズ可能なウィジェット配置・並べ替え
- 期間集計グラフ / 分析ダッシュボード（作成数推移等）
- ダッシュボード上での自動ポーリング / リアルタイム進捗更新
- 複数プロジェクトの切替 UI（v1 は単一 Default Project）
- 新規 permission / QuotaKey の追加（既存の判定・キーのみ使用）
