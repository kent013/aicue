【アプリの使命（North Star）— AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件 — AGENTS.md より（アプリ都合で緩めない）】
1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**(`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: `UrlSafetyInspector` / `PinnedHttpClient` を通し、境界は `config/ssrf-pin.php` に pin

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
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【参考: 既存コード文脈（設計の前提。必要ならリポジトリ /workspace のファイルを読んでよい）】
- 解析/レンダの状態機械: app/Services/Manual/AnalysisJobService.php・RenderJobService.php・AnalysisPipeline.php・RenderPipeline.php（terminal 遷移は行ロック + terminal guard で exactly-once。failJob は bool 返却。RenderPipeline::finalize は bool、AnalysisPipeline::finalize は現状 void）
- チケット: app/Services/Billing/TicketLedgerService.php（reserve→commit/release の 2 フェーズ。commit は pipeline の terminal tx 内から savepoint で呼ばれる）
- 招待: app/Services/Organization/OrganizationMembershipService.php（token は sha256 のみ保存・平文はメールのみ。受諾は token 所持証明でログインユーザーが行う）
- 課金メール通知台帳: app/Services/Billing/BillingNotificationDispatcher.php + billing_notifications テーブル（(type, invoice_id)/(type, dedup_key) UNIQUE の送達台帳。mail channel・Organization notifiable）
- Inertia 共有 props: app/Http/Middleware/HandleInertiaRequests.php
- User は Notifiable・CipherSweet（email 検索は whereBlind）。標準 notifications テーブルは未作成。
- ルーティング: routes/web.php（業務 route は require-active-subscription + project.in-route-org 群。/billing 等は auth+verified 直下）

---
## 概念設計
# 概念設計: notification-center（アプリ内通知センター）

## 背景・課題

AI-CUE の中核体験は「SOP をアップロード → AI 解析 → 撮影 → レンダ」の非同期パイプラインだが、
現状ユーザーがジョブの完了/失敗を知る手段は **マニュアル画面に留まっている間のポーリング**
（`GET .../jobs/{analysisJob}` / `.../render-jobs/{renderJob}`、doc/10 §10.3）だけである。

- 解析は最長 23 分・レンダは最長 25 分（job timeout）。画面を離れると完了も失敗も気づけない。
- 組織招待はメールのみ。既存ユーザーがアプリ内で招待の存在に気づく導線がない。
- チケット残高の低下は解析/レンダのトリガー時に 402 で初めて知る（先回りできない）。

「思考ゼロ・編集ゼロ」を掲げる使命に対し、**待ち時間の不安と見落とし**が現場のマニュアル作成
サイクルを止めている。

## 改善アイデア

**Laravel 標準の database notifications チャネル**を土台に、ユーザー宛のアプリ内通知センターを追加する。

1. **通知の格納**: 標準 `notifications` テーブル（uuid PK / notifiable morph / type / data / read_at）を
   新規 migration で追加（`data` は org スコープの JSON クエリのため `jsonb`）。宛先は常に `User`
   （`Notifiable` 済）。`via() = ['database']` のみ。
2. **通知タイプ**（`databaseType()` で安定文字列を格納。クラス名を DB に漏らさない）:
   | type | 発火点 | 宛先 |
   |---|---|---|
   | `manual_analyzed`（succeeded/failed） | analysis_jobs の terminal 遷移 | manual 作成者 + ジョブ実行者 |
   | `manual_rendered`（succeeded/failed。kind=render のみ） | render_jobs の terminal 遷移 | 同上 |
   | `invitation_received` | 組織招待の作成（既存ユーザー宛のみ） | 招待 email に一致する既存 User |
   | `ticket_balance_low` | チケット消費 commit で閾値を跨いだとき | 組織の owner/admin |
3. **ベル UI + 一覧 + 既読化**:
   - AppLayout ヘッダーに Bell アイコン（Lucide）+ 未読数バッジ（molecule）。クリックで通知一覧
     ページ（`GET /notifications`）へ遷移（v1 はドロップダウンなし = フォーカス管理/状態を持たない最小構成）。
   - 一覧: 種別アイコン・本文・相対時刻・未読ハイライト。クリックで既読化 + 対象（manual 画面）へ遷移。
   - 既読化: `POST /notifications/{notification}/read`（1 件）/ `POST /notifications/read-all`（一括）。
     応答は `back()` 系（`response()->json()` 直書きなし）。
4. **未読数は Inertia shared props**（`HandleInertiaRequests::share`）で全画面へ供給。
   ページ遷移/操作のたびに更新される（SSE/WS・SPA 内ポーリングは v1 スコープ外）。

### 既存 billing_notifications との関係（reconcile / 二重管理しない）

| 系 | 目的 | notifiable | チャネル | 冪等機構 |
|---|---|---|---|---|
| `billing_notifications`（既存） | Stripe 課金イベントの**メール送達台帳**（送信の 1 回性を UNIQUE で構造保証） | Organization（請求宛先メール） | mail | (type, invoice_id) / (type, dedup_key) UNIQUE |
| `notifications`（本設計） | ユーザーの**アプリ内 inbox** | User | database | 状態遷移 bool ゲート / 閾値クロス検知（下記） |

- 課金イベント（支払い失敗等）のメール通知系は**一切変更しない**。
- `ticket_balance_low` は課金イベントではなく**利用量イベント**（消費 commit 起点）。billing_notifications
  に行を作らず、アプリ内通知のみ。dedup は台帳ではなく「閾値クロス検知」（stateless）で行うため
  二重管理は発生しない。
- 既存メール通知（招待・セキュリティ・課金）は従来どおり送る。`invitation_received` のアプリ内通知は
  メールの**補完**（既存ユーザーへの気づき導線）であり置換ではない。

## 実装方針（概要）

### 発火配線（既存ジョブの振る舞いを壊さない・冪等）

通知の組み立て・送信は新設 `App\Services\Notification\NotificationCenterService` に集約する。
**冪等性は既存状態機械の「terminal 遷移が exactly-once」であることに乗せる**（行ロック + terminal
guard 済み）。遷移が実際に起きたときだけ・**terminal tx の commit 後に**通知する:

- **解析成功**: `AnalysisPipeline::finalize()` を `RenderPipeline::finalize()` と同型の **bool 返却**に変更
  （succeeded 到達 = true。stale 先勝ち no-op = false）。`run()` で true のときのみ通知。
- **解析失敗**: `AnalysisJobService::failJob()` の tx が true を返したときのみ、メソッド内 tx 後に通知
  （pipeline catch / `Job::failed` / `recoverStale` の全合流点を 1 箇所でカバー。terminal 済み no-op は通知しない）。
- **レンダ成功/失敗**: `RenderPipeline::run()`（finalize true）/ `RenderJobService::failJob()`（true かつ
  kind=render）で同様。**preview は通知しない**（ユーザーは画面上でポーリング中・ノイズ回避・status 遷移も無い）。
- terminal tx **内**には通知 insert を入れない（通知失敗が解析/レンダ結果を rollback する事故と、
  Postgres の tx abort（25P02）巻き込みを構造的に回避）。通知側の例外は Service 内で catch + `report()`
  し、ジョブ本流を絶対に壊さない。crash 時の欠落は許容（at-most-once。v1 で送達保証台帳は作らない）。
- **招待**: `OrganizationMembershipService::inviteMember()` の招待保存後、`User::whereBlind('email',
  'email_index', $email)`（CipherSweet 不変条件 6）で既存ユーザーを引き、居るときだけ通知。
- **残高低下**: `TicketLedgerService::commit()` の org 行ロック tx 内で「commit 前残高 ≥ 閾値 かつ
  commit 後残高 < 閾値」の**クロス判定**を行い、成立時のみ `DB::afterCommit()` で通知を登録
  （commit は pipeline の terminal tx 内から savepoint で呼ばれるため afterCommit が正着）。
  閾値は `config/billing.php` に `ticket_low_balance_threshold` を追加。クロス方式なので消費のたびに
  再通知されず、残高が回復して再度跨いだときだけ再通知される（dedup テーブル不要）。

### 宛先・org スコープのサーバ導出（tenant/actor キーを payload 信頼しない）

- 通知 payload（data）は **DB relation からの再解決のみ**で組み立てる（job → manual → project → org）。
  queue payload・HTTP payload の値は使わない。
- ジョブ通知の宛先 = `manual.created_by`（作成者）∪ **ジョブ実行者**。実行者を導出可能にするため
  `analysis_jobs` / `render_jobs` に `triggered_by`（FK users, NULL, nullOnDelete）を追加し、
  trigger()/triggerPreview() で **Auth から明示代入**する（`MassAssignmentProtectedKeys` に登録 =
  payload 直送は 422 / $fillable 不含を Architecture テストが強制）。
- 送信時に宛先ユーザーの**組織所属を再確認**（退会済みユーザーへは送らない = cross-org 通知を作らない）。
- `data.organization_id` をサーバ導出で埋め、一覧/未読数クエリは
  「current org の通知 OR organization_id null（= org 非依存の招待通知）」に絞る。
  他 org の通知は current org の画面に混ぜない（リンク先が cross-org 404 になる UX 破綻の防止）。

### ルート/認可（自分宛のみ・存在オラクル封じ）

- ルートは `auth + verified` 群に置き、`require-active-subscription` の**外**
  （サブスク失効中でも /billing 等でベルは機能させる。残高/課金系通知の受け皿として必要）:
  - `GET /notifications`（Inertia 一覧。ページネーション）
  - `POST /notifications/{notification}/read` / `POST /notifications/read-all`
- `{notification}` は implicit binding を使わず **`$request->user()->notifications()->whereKey($id)->firstOrFail()`**
  で解決（他人の通知 = 構造的に 404。403 で存在を漏らさない）。所有スコープが relation で閉じるため
  新規 Policy は不要。1 param ルートのため `NestedRouteIdorDefenseTest` の inventory 対象外
  （2+ param 規約）だが、cross-user 404 は Feature テストで固定する。
- mark-read/mark-all-read は `back()` で完結（`redirect()->intended()` 禁止事項の遵守）。

### フロント（DS token のみ・disabled 禁止・Atomic 階層）

- `molecules/NotificationBell.svelte`: Lucide `Bell` + 未読数バッジ（99+ 打ち切り）。shared props
  `notifications.unreadCount` を描画。AppLayout（template）ヘッダーに配置（下層 import = 単方向遵守）。
- `pages/Notifications/Index.svelte` + `features/notifications/NotificationListItem.svelte`:
  Inertia typed array + TS interface（`NotificationItem`）。種別ごとの表示文言はフロントで type 駆動に組む。
- 既読済みでも操作は常に可能（disabled を作らない）。空状態は既存 `EmptyState` molecule を再利用。

## 期待効果

- **使命への貢献**: 「AI に任せて待つ → 通知で戻る」の往復が成立し、解析/レンダの待ち時間が
  現場作業と並行できる（思考ゼロの体験を時間軸でも保証）。
- 解析/レンダ失敗の見落としがなくなり、復帰操作（再実行）への導線が最短化。
- 招待の気づき・残高低下の先回りにより、402/失効による作業中断を予防。
- 既存ポーリング UI・メール通知・billing_notifications 台帳は無変更（後退リスク最小）。

## 制約・前提

- 既存フェーズ 1 規約を踏襲: 薄い Controller + Service 委譲 / `declare(strict_types=1)` /
  保護キー forceFill・relation 明示代入 / scopeBindings + 認可前 404 / PHPStan level 10 / Pest +
  RefreshDatabase 並列 / Vitest + ds-purity + atomic-import-graph。
- `ScenarioWritePathInventoryTest` の対象（cuts / scenario_version / status の書き込み経路）は
  **一切増やさない**（通知は状態機械の外側・commit 後のフックのみ）。
- `AnalysisPipeline::finalize()` の bool 化は内部シグネチャ変更のみ（呼び出しは `run()` だけ）。
- 招待のアプリ内通知には**平文 token を含めない**（token 平文非保存の既存不変条件。受諾はメールの
  リンクから行う情報通知とし、リンク先遷移は持たない）。
- `notifications.data` の JSON クエリは pgsql 前提（テスト DB も pgsql）。

## スコープ外（後続）

- リアルタイム push（WebSocket / SSE）・SPA 内の定期ポーリング
- メール / Slack 等マルチチャネル拡張、通知設定（ミュート）画面
- ベルのドロップダウン化（v1 は一覧ページ遷移）
- アプリ内での招待**受諾**（token 所持証明の設計変更を伴うため。v1 は気づき導線のみ）
- preview ジョブの通知
- 通知の保持期間・自動削除ポリシー
