# 使命・禁止事項・セキュリティ不変条件（AGENTS.md より）

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件(アプリ都合で緩めない)

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
2. **子は親に属する**: nested route の不整合は認可より前に 404
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. PII(email/name)は CipherSweet。検索は `whereBlind()`
7. 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト（実装事実）】
- `/dashboard` は現状 `routes/web.php` の inline closure（auth+verified group 内、課金ゲート `require-active-subscription` の**外**）で雛形 `Dashboard.svelte` を render しているだけ
- 既存基盤: VideoManual(status: draft/analyzing/ready/rendering/published, scenario_version)、AnalysisJob/RenderJob(status: queued/running/succeeded/failed, progress, triggered_by)、Cut(adopted_take_id 保護キー, adoptedTake relation)、Take、Category、Notification(T008 NotificationCenterService。shared props `notifications.unreadCount` closure で全ページ供給済み)、TicketLedgerService::balance(org)、QuotaService::limits/checkAddition(max_storage_bytes)、StorageUsageService::occupiedBytes(org)、DefaultProjectResolver(v1 単一 Default Project の SSOT。organization->projects()->orderBy(id)->first())
- ロール: 組織 owner/admin または project_admin = 編集者（ProjectPolicy::update）、project_member = 撮影者（ProjectPolicy::capture）。判定は ProjectPolicy に集約済み（laratrust_team_id 明示）
- 組織 provisioning は Project を自動作成しない（org はあるが project なしの状態が正規に存在する）
- 既存テストは `/dashboard` が 200 を返すことに依存（TwoFactorEnforcementTest / SmokeTest 等）。ログインリダイレクト先も `/dashboard`
- in-flight ジョブは (manual, 操作種別) あたり 1 本の既存不変条件あり（doc/10 §10.8-8）
- フロント規約: DS token のみ / Lucide のみ / atoms→molecules→organisms→features→templates→pages の単方向 import / disabled ボタン禁止。StatCard / Card / EmptyState molecule は既存

## 概念設計

（以下、devnotes/20260712-0029-dashboard/conceptual-design.md の全文）

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
| 1 | 進行中ジョブ | status が analyzing / rendering のマニュアルを進捗付きで表示（AnalysisJob / RenderJob の queued・running の progress） | `projects.manuals.show`（既存ポーリングパネルへ。ダッシュボード自体はスナップショット表示でポーリングしない。完了の気づきは T008 通知ベルと整合） |
| 2 | 最近のマニュアル | updated_at 降順に 5 件（status バッジ・カテゴリ） | show / edit（編集者のみ edit） |
| 3 | 未読通知サマリ | 未読数 + 通知一覧への導線。**shared props `notifications.unreadCount`（T008 ベルと同一 closure）をフロントで再利用**し、サーバ側の二重集計を作らない | `notifications.index` |
| 4 | チケット残高 + 容量 | `TicketLedgerService::balance`、`StorageUsageService::occupiedBytes` + `QuotaService::limits`（max_storage_bytes）の使用率。残高が `billing.ticket_low_balance_threshold` 未満なら購入 CTA を強調 | `billing.tickets.show`（T007） |
| 5 | クイックアクション | 新規マニュアル作成 / カテゴリ管理 / 撮影 PWA を開く | `projects.manuals.create` / `projects.categories.index` / `capture.home` |

### ロール差（編集者 / 撮影者）

サーバが `role: 'editor' | 'shooter' | 'viewer'` を導出して props で渡す（判定は既存 Policy と同じ経路 = `$user->can('update', $project)` → editor、`$user->can('capture', $project)` → shooter、いずれも不可の組織メンバー → viewer。`laratrust_team_id` 明示判定は ProjectPolicy 内に既に集約済みで、それに委譲する）:

- **編集者**: 上記 1〜5 をフル表示（企画/解析/レンダ中心）
- **撮影者**: ブロック 2 の代わりに「**撮影対象**」= ready/published かつ 採用テイクなしの Cut（`whereDoesntHave('adoptedTake')`）を持つマニュアルを未採用カット数付きで表示し、撮影 PWA（`capture.manuals.show`）へ直行させる。編集者専用のクイックアクション（新規作成/カテゴリ管理）は**非表示**（disabled 禁止規約に従い、権限がないものはそもそも描画しない）
- **編集者にも撮影対象ブロックは併記**する（編集者は撮影も可能 = ProjectPolicy::capture の実装事実に合わせる）が、レイアウト上の主役は 1・2

### 状態分岐（詰み防止）

`/dashboard` はログイン直後の着地点なので**どの状態でも 404 / redirect ループにしない**:

- current org なし → 組織作成 CTA の setup 表示（`organizations.create`）
- org はあるが project なし（provisioning は project を作らない）→ プロジェクト作成 CTA（org owner/admin）/ 案内表示（member）
- project はあるがマニュアル 0 件 → 「最初のマニュアルを作成」CTA を明示した空状態（編集者）/ 「撮影対象はまだありません」（撮影者）
- サブスク未契約 org → ダッシュボードは表示し（課金ゲート外に置く。通知センターと同じ理由 = 失効中でも状況把握と復帰導線が必要）、購読導線の callout を出す。業務 route への遷移は既存 `require-active-subscription` middleware が billing へ誘導する（ダッシュボード側で二重実装しない）

## 期待効果

- **使命への貢献**: 「次に何をすべきか」（解析待ち→撮影待ち→レンダ待ち→公開）を一画面で提示し、思考ゼロの日常導線を完成させる。T001〜T008 の機能が初めて 1 つの動線に束なる
- 撮影者がログイン→撮影対象把握→PWA 起動まで 2 クリックになる（現状は導線なし）
- 残高/容量の可視化により、ジョブ実行時の残高不足エラー（事後発覚）を事前に予防する
- 通知・ジョブ進捗の「今」が見えることで、ポーリング画面への過剰な張り付きを減らす

## 実装方針（概要）

### バックエンド

- `routes/web.php`: inline closure を `DashboardController` に差し替え（`auth`+`verified` group 内・**課金ゲート外**のまま。route param なし = NestedRouteIdorDefenseTest inventory 対象外、tenant キー payload なし）
- `app/Http/Controllers/DashboardController.php`（新規・薄い）: `ResolvesCurrentOrganization` は使わない（current org なしで 404 にしないため）。user から currentOrganization を nullable に解決 → `DefaultProjectResolver::resolve()`（v1 単一 Default Project 規約の既存 SSOT）→ `DashboardService` に委譲
- `app/Services/Dashboard/DashboardService.php`（新規）: 全ブロックのサーバ集計。**固定本数のクエリで N+1 なし**:
  - 進行中: `$project->manuals()->whereIn('status', [Analyzing, Rendering])` + 進行中ジョブは `AnalysisJob` / `RenderJob` を `whereIn('video_manual_id', $ids)` + `whereIn('status', [Queued, Running])` の 2 クエリで引き当て（in-flight は manual×操作種別あたり 1 本の既存不変条件 = §10.8-8 を利用）
  - 最近: `$project->manuals()->with('category')->orderByDesc('updated_at')->limit(5)`
  - 撮影対象: `whereIn('status', [Ready, Published])` + `whereHas('cuts', whereDoesntHave('adoptedTake'))` + `withCount` で未採用カット数（`CaptureManualController::index` の relation 経由規約を踏襲）
  - 残高/容量: 既存 `TicketLedgerService::balance` / `StorageUsageService::occupiedBytes` / `QuotaService::limits` を**そのまま呼ぶ**（二重実装しない）
- `app/DataTransferObjects/Dashboard/DashboardPageData.php` ほか（新規）: typed array（`toArray(): array{...}`）で Inertia props の shape を固定（PHPStan lv10）
- 権限: 集計は relation 経由の org / project スコープのみ（cross-org 構造的不可）。ロール導出は ProjectPolicy 委譲（`laratrust_team_id` 明示判定を再実装しない）
- `response()->json()` 直書きなし（Inertia render のみ）、`redirect()->intended()` 不使用

### フロントエンド

- `resources/js/pages/Dashboard.svelte` を全面書き換え（AppLayout + StatCard / Card / EmptyState の既存 atom/molecule を再利用。DS token のみ・Lucide のみ・atomic 単方向 import 遵守）
- `resources/js/types/dashboard.ts`（新規）: props の TS interface（PHP typed array と対で保守）
- 未読通知タイルは shared props `notifications.unreadCount` を参照（ベルと同源）
- 空状態は EmptyState + 作成 CTA。**disabled ボタンは一切作らない**（権限がない導線は非描画）

### テスト

- Pest Feature `tests/Feature/DashboardTest.php`: 進行中ジョブ/最近マニュアル/撮影対象/残高/容量の集計正当性、**cross-org のマニュアル・ジョブが混入しない**、ロール別 props（editor/shooter/viewer）、空状態（org なし/project なし/マニュアル 0 件）、未契約 org でも 200、ゲスト 302（既存挙動維持）
- 既存テスト: `/dashboard` を参照するテスト（SmokeTest / TwoFactorEnforcementTest / InvitationTest 等）は「200 で表示される」前提のため**互換維持**（org なしユーザーでも 200 を返す設計はこの互換のため）
- Vitest: カード/スタットタイル描画・ロール別表示・空状態・`disabled` 属性が存在しないこと

## 制約・前提

- 既存フェーズ 1〜T008 の規約を踏襲: Controller 薄い + Service 委譲、Inertia typed array + TS interface、保護キー不信、親委譲 Policy、org-scoped 解決、DS token / Lucide / atomic 階層
- v1 = 単一 Default Project（`DefaultProjectResolver` が SSOT。複数 project 化の際は resolver 差し替えのみ）
- ダッシュボードは**読み取り専用**（書き込み経路なし = シナリオ整合ロック規約・Quota 予約規約の対象外。既存の書き込み Service を一切触らない）
- 通知未読数は shared props と同源（HandleInertiaRequests の closure）。ダッシュボード専用の通知クエリは追加しない
- ジョブ進捗のリアルタイム更新（ポーリング/WebSocket）はダッシュボードでは行わない（詳細は manuals.show の既存ポーリングパネルが担う。二重化しない）

## スコープ外

- カスタマイズ可能なウィジェット配置・並べ替え
- 期間集計グラフ / 分析ダッシュボード（作成数推移等）
- ダッシュボード上での自動ポーリング / リアルタイム進捗更新
- 複数プロジェクトの切替 UI（v1 は単一 Default Project）
- 新規 permission / QuotaKey の追加（既存の判定・キーのみ使用）
