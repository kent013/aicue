# 概念設計レビュー Round 3: 改訂版全文の貼付

失礼しました。改訂後の概念設計の全文を以下に貼り付けます。Round 2 で示した対応マトリクスとあわせて整合性を確認し、全体判定（APPROVED / CHANGES_REQUESTED）を出してください。日本語で出力してください。

---

# 概念設計: 撮影PWA（presigned アップロード + テイク管理 + 容量 Quota）

## 背景・課題

AI-CUE の使命は「SOP → AI シナリオ → **スマホ（PWA）でナビ撮影** → 標準化マニュアル動画」。
フェーズ1〜T003 で Category / VideoManual / Cut / SourceDocument / シナリオ編集（scenario document 保存）/ AI 解析が実装済みだが、**Take はスキーマ先取りのみ（振る舞いゼロ）**で、使命の中核「撮影ハードルの肩代わり」が未達。

- 現場作業者がシナリオ（Cut 群）を見ながら撮影し、各 Cut にテイク（動画）を登録・採用する経路が存在しない。
- 動画は大容量のため、Web サーバ経由アップロードではなく **S3 presigned PUT 直アップロード**が必須。その場合サーバは「何がアップロードされたか」を信頼できないため、**署名チケット + HeadObject 照合**（doc/10 §10.8-7）と**容量 Quota の実計上**（§10.8-4）を同時に設計しないと、容量課金と S3 コストが破綻する。

本設計は doc/10 §10.3（撮影 PWA routes）・§10.5（max_storage_bytes）・§10.8-3/-4/-7 の確定仕様を実装に落とす。**§10.8 は §10.1〜§10.7 に優先する。**

**成功条件（Round 1 の評価軸）**: 1 つの VideoManual について「cut 表示 → 録画（またはファイル選択）→ presigned 直アップロード → テイク登録 → 採用」が単一端末で完結し、その全経路で容量 Quota・tenant 境界・冪等性がテストで担保されていること。

## 改善アイデア

### 全体フロー

```
[PWA] 一覧 GET /app/projects/{project}/manuals（絞り込み/検索）
  → 詳細 GET .../manuals/{manual}（cuts + 全 take メタ + 採用テイク署名 GET URL）
  → 撮影（MediaRecorder。不可なら <input capture> フォールバック）→ IndexedDB に一時保持
  → POST .../cuts/{cut}/takes/upload-url（Quota チェック + bytes_pending 予約 + presigned PUT URL + 署名チケット）
  → S3 へ直 PUT（Content-Type / サイズはチケット署名対象と一致させる）
  → POST .../cuts/{cut}/takes（署名チケット検証 + HeadObject 照合 + (cut_id, client_take_id) 冪等登録 → status=ready）
  → 管理: PATCH/DELETE .../takes/{take}（並べ替え・コメント・削除）、POST .../takes/{take}/adopt（採用）
  → 採用テイクの実 DL 完了で POST .../takes/{take}/downloaded（DL 済み ACK。以降サーバ削除不可）
  → POST .../manuals/{manual}/sync（照合専用 payload → 未登録 client_take_id を返す → 新規のみ上記フローで送信）
```

### エンドポイントと応答形式（画面 = Inertia / データ = JSON Resource を明確に分離）

| メソッド/パス（`/app/projects/{project}` 配下） | 応答 | 返却型 |
|---|---|---|
| GET `/manuals` | Inertia `Capture/Index` | props: `CaptureManualSummaryData[]`（typed array） |
| GET `/manuals/{manual}` | Inertia `Capture/Show` | props: `CaptureManualDetailData`（cuts + takes + 採用テイク署名 URL） |
| POST `/manuals/{manual}/cuts/{cut}/takes/upload-url` | JSON | `TakeUploadTicketResource`（presigned URL + チケット） |
| POST `/manuals/{manual}/cuts/{cut}/takes` | JSON | `CaptureTakeResource` |
| PATCH/DELETE `/manuals/{manual}/cuts/{cut}/takes/{take}` | JSON | `CaptureTakeResource` / 204 |
| POST `/manuals/{manual}/cuts/{cut}/takes/{take}/adopt` | JSON | `CaptureCutResource`（採用状態） |
| POST `/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded` | JSON | `CaptureTakeResource`（DL 済み ACK） |
| POST `/manuals/{manual}/sync` | JSON | `CaptureSyncResultResource`（未登録 client_take_id + サーバ状態スナップショット） |

`response()->json()` 直書きはしない（全 JSON 応答は JsonResource 経由）。GET（画面）は Inertia、書き込みと同期は XHR JSON という単純な二分で、shell と data の経路を混在させない。PC 管理 UI（`/projects/...`）とは URL 空間ごと分離（`/app/...` = 撮影 PWA 専用）。

### 設計判断（Decision 一覧）

**D1. ルート分離（§10.8-3）** — 撮影 PWA のデータ API は `/api/v1`（dual guard・機械用）とは別の **web ガード route group `/app/projects/{project}/...`** に分離する。既存 middleware をそのまま再利用: `auth` + `verified` + `require-active-subscription` + `project.in-current-org`（= EnsureProjectBelongsToCurrentOrganization。cross-org {project} を FormRequest より前に 404）+ `Route::scopeBindings()`。書き込みは CSRF 必須（Inertia の XSRF-TOKEN cookie）。画面シェルは Inertia ページ、データ endpoint は JSON（DTO + JsonResource。`response()->json()` 直書き禁止に従う）。全 nested route を `NestedRouteIdorDefenseTest` inventory に登録する。

**D2. アップロード予約行 + 署名チケット（§10.8-7 検証専用）** — 新テーブル `take_upload_reservations`（cut_id / organization_id はサーバ導出・protected、client_take_id / size_bytes / content_type / video_path（サーバ生成 S3 キー）/ status(pending|completed|released) / expires_at）を**真実源**とし、レスポンスの「署名チケット」は `Crypt::encryptString`（AEAD = 改竄不能）で reservation_id + cut_id + client_take_id + size + content_type + video_path + 有効期限を封入した**検証専用トークン**とする。`POST .../takes` では:
- cut は **URL の nested route（`$project->manuals()` → `$manual->cuts()` の scopeBindings）からのみ解決**。
- チケット復号値の cut_id が route の cut と不一致なら 404/422 で拒否（**チケット値を代入に使わない**）。予約行も `$cut->uploadReservations()` 経由で再取得（cross-cut は 404）。
- 期限切れ・released 済み・completed 済みチケットは拒否。

**D3. 容量 Quota 実計上（§10.8-4 / §10.5）** — `QuotaKey::MaxStorageBytes = 'max_storage_bytes'` を追加し `config/quota.php` の各プランに値を定義（free: 1 GiB / standard: 50 GiB。値は初期値、プラン設計で調整可能）。
- **bytes_used** = `takes.size_bytes` の org 単位合計（takes → cuts → video_manuals → projects → custom_teams の join。`StorageUsageService` に集約）。
- **bytes_pending** = pending 状態かつ未失効の `take_upload_reservations.size_bytes` の org 合計（org 列を予約行にサーバ導出で持つため単純 sum）。
- upload-url 発行時: **Organization 行を `lockForUpdate()`** した tx 内で Quota 判定 → 予約 insert（check→reserve の TOCTOU を行ロックで直列化）。判定は既存 `check()`（件数セマンティクス `current >= limit`）に容量加算用の **`QuotaService::checkAddition($org, MaxStorageBytes, current: bytes_used + bytes_pending, addition: $size)`**（`current + addition > limit` で `QuotaExceededException`）を追加して行う。「Quota 判定は QuotaService 経由のみ」の規約を維持しつつ、呼び出し側の `-1` 補正のような数式トリックを排する。
- `POST .../takes` 成功で予約を completed 化（= bytes_pending から自然に消え、確定分は takes.size_bytes で自動計上）。追加のカウンタテーブルは持たない（真実源 = 集計クエリ。二重帳簿を作らない）。

**D4. テイク登録の照合と冪等** — `POST .../takes` は HeadObject で ContentLength / ContentType をチケット・予約行と照合。**不一致は S3 オブジェクト削除 + 予約 released + 422 拒否**。オブジェクト不存在（PUT 未完了）は 422。`(cut_id, client_take_id)` UNIQUE（既存スキーマ）を冪等キーとし、既登録なら**既存 Take を 200 で返し、今回の予約は解放 + 今回アップロードされた S3 オブジェクトは削除**（応答喪失リトライ・二重送信で二重計上しない）。v1 はサーバ側トランスコード/サムネイル生成なし → 登録成功で `status=ready`（uploading/processing は将来の処理パイプライン用に温存）。duration_ms / captured_at はクライアント申告（非セキュリティメタデータ。size/content_type と異なり課金・検証に使わない）。sort_order はサーバ採番で**先頭**（doc/05: 新規テイクはリスト先頭）。

**D5. 採用・削除と共有ロック規約（AGENTS.md ドメイン固有規約 1）** — `cuts.adopted_take_id` の書き込み（adopt / 採用テイク削除時の null 化）は cuts テーブルへの書き込みのため、**VideoManual 行 `lockForUpdate()` 同一 tx 内**で行い、`ScenarioWritePathInventoryTest` のスキャナに **`adopted_take_id` 書き込み検出（検出 4）を追加**して経路を deny-by-default で固定する。
- adopt は `$cut->takes()` 経由でのみ take を解決（**cross-cut は 404** = IDOR）。`status != ready` の take は採用不可（422）。manual が analyzing / rendering 中は 409（シナリオ整合）。
- Take 削除: 採用中 take の削除は `adopted_take_id` を null 化。S3 オブジェクト削除は `DeleteTakeObjectsJob`（media queue）へ委譲（使用量は takes 行の削除で自動減算 = 再計算不要な集計方式）。

**D6. 「DL 済みは削除不可」のサーバ側表現（明示 ACK 方式）** — doc/05 の「サーバーからダウンロードしたテイクは削除不可」を `takes.downloaded_at`（nullable timestamp）で表現するが、**打刻は署名 URL 発行時ではなく、クライアントが実ダウンロード完了後に明示 ACK する専用エンドポイント（`POST .../takes/{take}/downloaded`）で行う**（URL 発行は実 DL を保証しないため。詳細画面を開いただけで削除不可になる誤動作を排除する）。
- `DELETE .../takes/{take}` は `downloaded_at` 非 null なら 422 拒否（他端末に配布済みの素材を消させない誤操作ガード）。
- 未 ACK の take（採用中含む）は削除可能。採用中 take の削除時は D5 の null 化がロック tx 内で発火（DB の `adopted_take_id nullOnDelete` FK は最終防波堤として既設）。
- ACK は冪等（再送で downloaded_at を進めない）。端末単位の配信台帳（take_device_deliveries）は v1 では過剰設計として持たない（複数端末の厳密な配信管理が必要になった時点で昇格）。

**D7. S3 掃除（孤児防止）** —
- **Take 削除 / manual 削除**: 削除対象の S3 キー（video_path / thumbnail_path）を収集し `DeleteTakeObjectsJob`（新設 queue connection `database-media`、queue 名 `media`）で削除。`VideoManualService::delete` も cascade で消える takes / source_documents の S3 キーを収集して同 Job に渡す（SourceDocumentService に既存コメントで予告されていた「ストレージ Quota フェーズの掃除」を本フィーチャで実装）。
- **孤児掃除 cron** `capture:release-stale-upload-reservations`（10 分毎）: `expires_at` 超過の pending 予約を released 化し（= bytes_pending 解放）、対応する S3 キーに**未登録オブジェクトが存在すれば削除**（PUT 完了したが POST takes に至らなかったケース）。routes/console.php に登録（billing:release-stale-reservations と同型）。

**D8. sync（一括同期・新規テイクのみ）** — `POST .../manuals/{manual}/sync` は端末が持つ `{cut, client_take_id}` ペアの一覧（**入力名は保護キー `cut_id` と別名の `cut`**。Category の `category` 入力名と同じ境界規約。ネスト位置にも `takes.*.cut_id => missing` を張る）を**照合専用 payload** として受け、サーバは manual の nested relation からのみ解決して「サーバ未登録の client_take_id 一覧」と「サーバ側テイク状態スナップショット」を返す。**このエンドポイント自体は書き込みしない**（登録は必ず D2-D4 の upload-url → PUT → POST takes 経路。manual に属さない cut 参照は照合不一致 = 404 で、代入には決して使わない）。Service 入力は連想配列でなく `CaptureSyncInput`（`list<ClientTakeFingerprint>`）の明示 DTO に固定。冪等性は `(cut_id, client_take_id)` UNIQUE で担保。

**D9. フロント PWA** —
- Inertia ページ: `Capture/Index.svelte`（シナリオ一覧・カテゴリ/検索絞り込み）、`Capture/Show.svelte`（撮影ナビ: シナリオ表示 + カメラ + テイク一覧・採用・並べ替え・コメント）。コンポーネントは `features/capture/` 配下（atomic 単方向 import 遵守・DS token のみ・Lucide のみ・disabled 禁止 = 押下時エラー表示）。
- **カメラ**: `MediaRecorder` 対応判定 → 非対応（iOS Safari 等）は `<input type="file" accept="video/*" capture="environment">` へ**自動フォールバック**（v1 必須要件）。
- **アップロードキュー（即時アップロード優先）**: 録画確定後は**オンラインなら即時にアップロード（upload-url → S3 PUT → POST takes）を開始し、成功したら blob を端末に残さない**。IndexedDB はオフライン/失敗時の一時バッファに限定する（iOS Safari の容量制約・eviction リスクを直視した設計）。IndexedDB への保存自体が失敗した場合はその場でエラー表示し再撮影/再選択導線へ戻す。端末保持中のテイク数・概算サイズを UI に明示する。キュー処理は共通クライアント `captureUploadClient` に集約し、**X-XSRF-TOKEN を常時付与、419 応答時は cookie 再取得（軽量 GET）→ 1 回リトライ**する共通 fetch ラッパを通す。
- **Service Worker**: 静的アセットのキャッシュ + 「フォアグラウンド復帰時にアップロードキューを再開」する同期トリガー（Background Sync API はブラウザ差が大きいため、v1 は visibilitychange / online イベント + SW メッセージの堅実な組合せ。同期実行主体はページ側 JS = セッション cookie / XSRF が自然に効く）。manifest.webmanifest でホーム画面追加に対応。

**D10. 認可（§10.5・Policy 親委譲）** — `ProjectPolicy::capture(User, Project)` を追加（= org owner/admin または project_admin または **project_member**）。`TakePolicy` を新設し全 ability を親（ProjectPolicy::capture）へ委譲（直 fetch 禁止）: 撮影者 = manual read（既存 VideoManualPolicy::view）+ take の upload/登録/並べ替え/コメント/削除/adopt。編集者も同権限を包含。
- adopt を撮影者に含めるのは doc/10 §10.5 の確定仕様（「撮影者 = take capture/upload/**adopt**」）であり、doc/05 の UX（現場でテイクを並べ替え・先頭 = 採用候補を決めるのは撮影者）とも一致する。capture_record / capture_curate の 2 分割は v1 では確定仕様に対する過剰分割として採らない（誤操作リスクは D6 の DL 済み削除不可 + D5 の ready 前採用不可 + ロック直列化で抑える。将来必要になれば ability 追加は Policy 局所変更で済む）。

**D11. ストレージ抽象（テスト可能性）** — presigned PUT URL 発行（`temporaryUploadUrl`）/ 署名 GET URL（`temporaryUrl`）/ HeadObject / オブジェクト削除を `TakeObjectStorage` 1 クラスに集約。戻り値は string/array でなく DTO（`PresignedUploadData` / `ObjectMetadataData`）に固定。Feature テストは `Storage::fake('s3')` + 本クラスの container mock（presign/HeadObject は fake 値）で**実 S3・実 API に一切触れない**。Job は `Queue::fake` で検証。

**D12. 型境界（PHPStan lv10 を最初から満たす）** — 「**Service は連想配列を受け取らない・返さない**」を原則化する。主要 DTO を先に定義: `TakeUploadTicketData`（チケット封入 shape）、`PresignedUploadData`、`ObjectMetadataData`、`TakeUploadInput`、`TakeRegistrationInput`、`CaptureSyncInput` / `ClientTakeFingerprint`、`CaptureManualSummaryData`、`CaptureManualDetailData` / `CaptureCutData` / `CaptureTakeData`、`CaptureSyncResultData`。JSON 応答は各 Resource（前掲表）でこれら DTO を直列化する（ScenarioDocumentData / ScenarioResource の既存パターンを踏襲）。

## 期待効果

- **使命への直接貢献**: 「シナリオを見ながらナビ撮影」という AI-CUE の中核体験（撮影判断の肩代わり）が初めて成立する。PC（シナリオ設計）↔ 現場スマホ（撮影）の分業ループが閉じる。
- **保存容量の上限制御を構造的に導入**: max_storage_bytes が実計上（bytes_used + bytes_pending）で機能し、動画ストレージコストがプラン上限で抑制される（予約 + 実計上 + 孤児掃除）。
- **セキュリティ不変条件の維持**: presigned 直アップロードという「サーバを迂回する経路」を、検証専用チケット + HeadObject 照合 + nested route 解決で既存の tenant 境界モデルに閉じ込める。

### 運用上の残課題（本フィーチャの範囲外として明記）

- プランのダウングレード等で**既に上限超過している組織**の扱い（v1: 新規 upload-url 発行が拒否されるのみ。既存データの強制削除・猶予通知は行わない）。
- 掃除 cron / media queue worker の失敗滞留の監視・アラート（運用手順は docs へ記載するが、監視基盤は対象外）。
- プラン変更時の使用量再評価 UI（使用量の表示自体は将来の設定画面へ）。

## 実装方針（概要）

**実装は 4 段の incremental TODO に分割**する（設計は一体 — チケット・Quota・登録は相互依存のため分割設計しない — が、実装・検証は段階分けして失敗の切り分けを容易にする）:

| 段 | 内容 | 完了条件 |
|---|---|---|
| A: 基盤 | DB（予約テーブル・downloaded_at）/ Enum / QuotaService::checkAddition / StorageUsageService / TakeObjectStorage / config | 単体 Feature テスト green |
| B: API core | upload-url / POST takes / adopt / PATCH/DELETE / downloaded ACK + Policy + routes + IDOR/ScenarioWritePath 登録 | 撮影 API 全経路の Feature テスト green |
| C: 同期・掃除 | sync / DeleteTakeObjectsJob / cron / manual 削除時の S3 掃除 | 冪等・掃除テスト green |
| D: PWA front | Capture ページ・カメラ/フォールバック・アップロードキュー・SW/manifest | Vitest + pnpm build green |

| レイヤ | 変更 |
|---|---|
| DB | `take_upload_reservations` 新設、`takes.downloaded_at` 追加 |
| Enum/Config | `QuotaKey::MaxStorageBytes`、`TakeUploadReservationStatus`、`config/quota.php` プラン値、`config/capture.php`（チケット TTL 等）、`config/queue.php` に `database-media`、`QuotaService::checkAddition`（容量加算判定）、bootstrap/app.php の QuotaExceededException render 拡張（expectsJson な XHR には back() でなく 422 JSON Resource を返す） |
| Model | `TakeUploadReservation`（+Factory）、`Cut::uploadReservations()`、`Take` に downloaded_at |
| Service | `Capture/TakeUploadService`（quota+予約+presign+チケット）、`Capture/TakeRegistrationService`（検証+HeadObject+冪等登録）、`Capture/TakeService`（update/delete/adopt。VideoManual 行ロック）、`Capture/StorageUsageService`、`Capture/TakeObjectStorage`、`Capture/CaptureSyncService` |
| HTTP | `Capture/` 配下 Controller 群 + FormRequest（ProhibitsProtectedKeys）+ DTO/JsonResource |
| Routes | `/app/projects/{project}/...` web group（既存 middleware 再利用）+ downloaded ACK route + IDOR inventory 登録 |
| Job/Cron | `DeleteTakeObjectsJob`（media queue）、`capture:release-stale-upload-reservations` |
| Policy | `ProjectPolicy::capture`、`TakePolicy`（親委譲） |
| Front | `Capture/Index|Show` ページ、`features/capture/*`、`captureUploadClient`（419 リトライ）、SW + manifest |
| Test | Feature（quota 予約/超過拒否・チケット改竄/不一致拒否・冪等・DL 済み削除不可・cross-cut adopt 404・ready 前採用不可・null 化・sync 冪等・cross-org/project 404・保護キー 422・権限・cron・使用量集計）+ Architecture 登録（IDOR / ScenarioWritePath 拡張 / MassAssignment / QuotaKey 整合）+ Vitest（フォールバック判定・419 リトライ） |

## 制約・前提

- doc/10 **§10.8 が §10.1〜§10.7 に優先**。テンプレの `/api/v1` は温存（撮影 API を混ぜない）。
- 既存フェーズ1規約を踏襲: 保護キーは `MassAssignmentProtectedKeys` + `ProhibitsProtectedKeys`（`cut_id` 等 payload 直送は 422）、Policy 親委譲、Project/VideoManual/Organization 行ロック直列化、scopeBindings + `NestedRouteIdorDefenseTest`、Inertia typed array + TS interface、PHPStan lv10、Pest（RefreshDatabase グローバル）。
- 共有ロック規約（AGENTS.md ドメイン固有規約 1）: `adopted_take_id` は cuts への書き込み → **本フィーチャで inventory テストの検出対象に昇格**させ、adopt / null 化経路を登録する。
- v1: 同一オリジン・セッション認証。単一 Default Project。トランスコードなし（アップロードされた mp4/webm をそのまま保管）。

## スコープ外（後続）

- レンダ（完成動画合成・RenderJob・COST_RENDER）
- 多言語 / TTS
- サーバ側サムネイル生成・トランスコード（thumbnail_path / processing ステータスはスキーマ温存）
- 全体プレビュー連結再生（doc/05 の [プレビュー]。v1 は撮影・登録・採用ループを閉じることを優先）
- 非採用・他端末由来テイクのサーバ再生 URL（詳細 GET の署名 URL は §10.3 どおり採用テイクのみ。必要になれば個別 playback-url endpoint を後続追加）
- Background Sync API のネイティブ活用（v1 はフォアグラウンド復帰時同期）
