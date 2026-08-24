Round 1 の指摘に対する対応を報告します。改訂済みの概念設計全文を末尾に添付します。
全 Critical / Warning を解消したか確認し、全体判定（APPROVED / CHANGES_REQUESTED）を出してください。

## 対応マトリクス

- [Critical] organization_id の JSON 閉じ込め → **対応**: notifications テーブルに first-class の organization_id 列（nullable FK, cascadeOnDelete, index）を追加。標準 DatabaseChannel を薄く拡張した OrganizationScopedDatabaseChannel（buildPayload に organization_id をマージ）を container binding で差し替え。data は表示用 payload に限定。
- [Critical] payload が array<string, mixed> のまま / PHPStan level 10 → **対応**: NotificationType backed enum を単一の正とし、書き込み側は type ごとの payload DTO、読み出し側は NotificationListItemData DTO（enum で検証復元）へ正規化。生配列をページ/shared props に渡さないことを設計に明記。
- [Warning] current org 絞り込みで別 org の完了を見落とす → **対応**: 未読数・一覧を全 org 横断へ変更（通知は notifiable=自分で構造的に閉じるため cross-org read に当たらない）。一覧に org 名バッジ（作成時スナップショット）。organization_id 列は表示と open の遷移可否判定にのみ使用。
- [Warning] 遷移先が 402/404 で UX 途切れ → **対応**: GET /notifications/{notification}/open を新設し、既読化 + 遷移先解決をサーバ側で実施。開けない場合（manual 削除 / 通知 org ≠ current org / 遷移先なし）は一覧へ flash 付きで戻す。org 自動切替はしない。
- [Warning] commit 後〜insert 間の crash で欠落 → **部分対応（wording 明確化・outbox 見送り）**: worker のジョブ実行中 crash は recoverStale → failJob で失敗通知が発火するため、真の欠落窓は terminal commit 直後〜通知 insert の数 ms のみ。「at-most-once の補助通知（正は job ポーリング）」と設計に明示。outbox は「今必要なものだけ作る」原則で v1 見送り。
- [Warning] DTO 未定義で生配列が流れやすい → **対応**: 「型付きデータ契約」節を新設（上記 Critical と同じ）。
- [Warning] databaseType 文字列の PHP/Svelte ドリフト → **部分対応**: PHP 側は backed enum で一元化。TS codegen 基盤は本リポジトリに無く、この機能のための導入は過大。TS は literal union の手動ミラー + 未知 type の汎用フォールバック描画（Vitest で固定）でドリフト時も UI が壊れない構造にした。
- [Suggestion] ticket_balance_low の後続化 → **見送り**: フィーチャ要件に明示されており、TicketLedgerService::commit への数行のクロス検知フックで面積が小さい。402 中断の予防は使命に直結。

---
## 改訂版 概念設計
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
   新規 migration で追加。宛先は常に `User`（`Notifiable` 済）。`via() = ['database']` のみ。
   **`organization_id` は first-class column**（nullable FK, cascadeOnDelete, index）として追加し、
   org 文脈の判定を untyped JSON に依存させない。`data` は表示用 payload（jsonb）に限定する。
   標準 `DatabaseChannel` を薄く拡張した `OrganizationScopedDatabaseChannel`（`buildPayload` に
   `organization_id` をマージ）を container binding で差し替える（フレームワークの公式拡張点の範囲内）。
2. **通知タイプ**（PHP backed enum `NotificationType` を単一の正とし、`databaseType()` で enum 値を格納。
   クラス名を DB に漏らさない）:
   | type | 発火点 | 宛先 |
   |---|---|---|
   | `manual_analyzed`（succeeded/failed） | analysis_jobs の terminal 遷移 | manual 作成者 + ジョブ実行者 |
   | `manual_rendered`（succeeded/failed。kind=render のみ） | render_jobs の terminal 遷移 | 同上 |
   | `invitation_received` | 組織招待の作成（既存ユーザー宛のみ） | 招待 email に一致する既存 User |
   | `ticket_balance_low` | チケット消費 commit で閾値を跨いだとき | 組織の owner/admin |
3. **ベル UI + 一覧 + 既読化**:
   - AppLayout ヘッダーに Bell アイコン（Lucide）+ 未読数バッジ（molecule）。クリックで通知一覧
     ページ（`GET /notifications`）へ遷移（v1 はドロップダウンなし = フォーカス管理/状態を持たない最小構成）。
   - **未読数・一覧は全 org 横断**（自分宛の通知は全て見える。複数 org 所属ユーザーの見落とし防止）。
     一覧の各行に org 名バッジを表示（org 名は作成時に payload へスナップショット = 退会/削除後も表示可能）。
   - 一覧: 種別アイコン・本文・org バッジ・相対時刻・未読ハイライト。
   - **遷移はサーバ解決**: `GET /notifications/{notification}/open` が既読化 + 遷移先をサーバ側で解決して
     redirect する。開けない場合（対象 manual 削除済み / 通知の org が current org と不一致 / 遷移先なし）
     は一覧へ `back()->with('info', ...)` で戻す（cross-org 404 や dead link で UX を途切れさせない。
     org 不一致時は「組織を切り替えてください」の案内。自動 org 切替はしない = 驚き最小）。
   - 既読化: `POST /notifications/{notification}/read`（1 件）/ `POST /notifications/read-all`（一括）。
     応答は `back()` 系（`response()->json()` 直書きなし）。
4. **未読数は Inertia shared props**（`HandleInertiaRequests::share`）で全画面へ供給
   （`$user->unreadNotifications()->count()` の全 org 横断 1 クエリ。notifiable + read_at の index で担保）。
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
  し、ジョブ本流を絶対に壊さない。
- **送達保証は at-most-once の補助通知**と明示的に位置づける（正は従来どおり job ポーリング + 画面の
  status 表示。通知はその補完）。worker がジョブ実行中に落ちた場合は `recoverStale` が failJob 経由で
  失敗通知を発火するため実運用の欠落窓は「terminal commit 直後〜通知 insert」の数 ms のみ。この窓の
  ための outbox 台帳は v1 では作らない（思考原則 2: 今必要なものだけ作る）。
- **招待**: `OrganizationMembershipService::inviteMember()` の招待保存後、`User::whereBlind('email',
  'email_index', $email)`（CipherSweet 不変条件 6）で既存ユーザーを引き、居るときだけ通知。
- **残高低下**: `TicketLedgerService::commit()` の org 行ロック tx 内で「commit 前残高 ≥ 閾値 かつ
  commit 後残高 < 閾値」の**クロス判定**を行い、成立時のみ `DB::afterCommit()` で通知を登録
  （commit は pipeline の terminal tx 内から savepoint で呼ばれるため afterCommit が正着）。
  閾値は `config/billing.php` に `ticket_low_balance_threshold` を追加。クロス方式なので消費のたびに
  再通知されず、残高が回復して再度跨いだときだけ再通知される（dedup テーブル不要）。

### 型付きデータ契約（PHPStan level 10 / DTO 必須）

- **`App\Enums\Notification\NotificationType`（backed enum）が type の単一の正**。
  `databaseType()`・フロントへ渡す discriminant・種別別 payload の復元分岐すべてを enum から導出する。
- **書き込み側**: type ごとの payload DTO（例: `ManualJobPayload` / `InvitationReceivedPayload` /
  `TicketBalanceLowPayload`。`toArray(): array<string, int|string|bool|null>`）を Notification クラスが
  受け取り、`toDatabase()` は DTO 経由でのみ JSON 化する（`array<string, mixed>` を裸で流さない）。
- **読み出し側**: `DatabaseNotification` の生配列をページへ渡さない。
  `NotificationListItemData`（DTO）へ正規化（type enum で payload を検証復元）してから
  Inertia typed array（`list<array{...}>`）に変換する。shared props の未読数も含めて
  controller/middleware は DTO 経由の値のみ共有する。
- **フロント**: `NotificationItem` TS interface + type 文字列の literal union。未知 type は
  汎用フォールバック描画（アイコン + メッセージのみ）に落とし、enum と TS の一時的ドリフトでも
  UI が壊れない構造にする。

### 宛先・org スコープのサーバ導出（tenant/actor キーを payload 信頼しない）

- 通知の内容・宛先・`organization_id` 列は **DB relation からの再解決のみ**で組み立てる
  （job → manual → project → org）。queue payload・HTTP payload の値は使わない。
- ジョブ通知の宛先 = `manual.created_by`（作成者）∪ **ジョブ実行者**。実行者を導出可能にするため
  `analysis_jobs` / `render_jobs` に `triggered_by`（FK users, NULL, nullOnDelete）を追加し、
  trigger()/triggerPreview() で **Auth から明示代入**する（`MassAssignmentProtectedKeys` に登録 =
  payload 直送は 422 / $fillable 不含を Architecture テストが強制）。
- 送信時に宛先ユーザーの**組織所属を再確認**（退会済みユーザーへは送らない = cross-org 通知を作らない）。
- `organization_id` 列はサーバ導出で埋める（招待通知 = 招待元 org。org 削除で cascade 削除）。
  一覧/未読数は**自分宛（notifiable = 自分）で構造的に閉じる**ため org フィルタは行わず、
  `organization_id` は表示バッジと `open` の遷移可否判定（通知 org ≠ current org なら遷移せず案内）にのみ使う。
  通知は本人のみ読める自分のデータであり cross-org read には当たらない（org を跨ぐ書き込み・参照は発生しない）。

### ルート/認可（自分宛のみ・存在オラクル封じ）

- ルートは `auth + verified` 群に置き、`require-active-subscription` の**外**
  （サブスク失効中でも /billing 等でベルは機能させる。残高/課金系通知の受け皿として必要）:
  - `GET /notifications`（Inertia 一覧。ページネーション）
  - `GET /notifications/{notification}/open`（既読化 + サーバ解決 redirect。冪等）
  - `POST /notifications/{notification}/read` / `POST /notifications/read-all`
- `{notification}` は implicit binding を使わず **`$request->user()->notifications()->whereKey($id)->firstOrFail()`**
  で解決（他人の通知 = 構造的に 404。403 で存在を漏らさない）。所有スコープが relation で閉じるため
  新規 Policy は不要。1 param ルートのため `NestedRouteIdorDefenseTest` の inventory 対象外
  （2+ param 規約）だが、cross-user 404 は Feature テストで固定する。
- `open` の遷移先（manual 画面）は既存の `project.in-route-org` + scopeBindings 防御下の named route へ
  redirect するだけで、認可判断を通知側に複製しない（遷移先で 404/403 になるケースは open が事前に
  一覧へ戻して案内する = 二重防御）。
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
- 通知はあくまで**補助チャネル（at-most-once）**。ジョブ状態の正は analysis_jobs / render_jobs であり、
  既存ポーリング UI は変更しない。

## スコープ外（後続）

- リアルタイム push（WebSocket / SSE）・SPA 内の定期ポーリング
- メール / Slack 等マルチチャネル拡張、通知設定（ミュート）画面
- ベルのドロップダウン化（v1 は一覧ページ遷移）
- アプリ内での招待**受諾**（token 所持証明の設計変更を伴うため。v1 は気づき導線のみ）
- preview ジョブの通知
- 通知の保持期間・自動削除ポリシー
