# レビュー依頼: 撮影PWA 概念設計（Round 1）

【アプリの使命 (North Star) — AGENTS.md より】
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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

【セキュリティ不変条件 — AGENTS.md より(アプリ都合で緩めない)】
1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**(`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**

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

【参照可能な確定仕様・既存コード（読み込み許可）】
- /workspace/doc/10_実装仕様.md（特に §10.3 撮影 PWA routes・§10.5 max_storage_bytes・§10.8-3/-4/-7。**§10.8 は §10.1〜§10.7 に優先**）
- /workspace/doc/05_スマホアプリ機能仕様.md（PWA の UX 仕様）
- /workspace/app/Models/{Take,Cut,VideoManual}.php、/workspace/app/Services/Billing/QuotaService.php、/workspace/config/quota.php
- /workspace/app/Services/Manual/*.php（フェーズ1 の見本実装）、/workspace/routes/web.php
- /workspace/tests/Architecture/{NestedRouteIdorDefenseTest,ScenarioWritePathInventoryTest}.php

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、/workspace/devnotes/20260711-0345-capture-pwa/conceptual-design.md の全文）

# 概念設計: 撮影PWA（presigned アップロード + テイク管理 + 容量 Quota）

## 背景・課題

AI-CUE の使命は「SOP → AI シナリオ → **スマホ（PWA）でナビ撮影** → 標準化マニュアル動画」。
フェーズ1〜T003 で Category / VideoManual / Cut / SourceDocument / シナリオ編集（scenario document 保存）/ AI 解析が実装済みだが、**Take はスキーマ先取りのみ（振る舞いゼロ）**で、使命の中核「撮影ハードルの肩代わり」が未達。

- 現場作業者がシナリオ（Cut 群）を見ながら撮影し、各 Cut にテイク（動画）を登録・採用する経路が存在しない。
- 動画は大容量のため、Web サーバ経由アップロードではなく **S3 presigned PUT 直アップロード**が必須。その場合サーバは「何がアップロードされたか」を信頼できないため、**署名チケット + HeadObject 照合**（doc/10 §10.8-7）と**容量 Quota の実計上**（§10.8-4）を同時に設計しないと、容量課金と S3 コストが破綻する。

本設計は doc/10 §10.3（撮影 PWA routes）・§10.5（max_storage_bytes）・§10.8-3/-4/-7 の確定仕様を実装に落とす。**§10.8 は §10.1〜§10.7 に優先する。**

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
  → POST .../manuals/{manual}/sync（照合専用 payload → 未登録 client_take_id を返す → 新規のみ上記フローで送信）
```

### 設計判断（Decision 一覧）

**D1. ルート分離（§10.8-3）** — 撮影 PWA のデータ API は `/api/v1`（dual guard・機械用）とは別の **web ガード route group `/app/projects/{project}/...`** に分離する。既存 middleware をそのまま再利用: `auth` + `verified` + `require-active-subscription` + `project.in-route-org`（= EnsureProjectBelongsToRouteOrganization。cross-org {project} を FormRequest より前に 404）+ `Route::scopeBindings()`。書き込みは CSRF 必須（Inertia の XSRF-TOKEN cookie）。画面シェルは Inertia ページ、データ endpoint は JSON（DTO + JsonResource。`response()->json()` 直書き禁止に従う）。全 nested route を `NestedRouteIdorDefenseTest` inventory に登録する。

**D2. アップロード予約行 + 署名チケット（§10.8-7 検証専用）** — 新テーブル `take_upload_reservations`（cut_id / organization_id はサーバ導出・protected、client_take_id / size_bytes / content_type / video_path（サーバ生成 S3 キー）/ status(pending|completed|released) / expires_at）を**真実源**とし、レスポンスの「署名チケット」は `Crypt::encryptString`（AEAD = 改竄不能）で reservation_id + cut_id + client_take_id + size + content_type + video_path + 有効期限を封入した**検証専用トークン**とする。`POST .../takes` では:
- cut は **URL の nested route（`$project->manuals()` → `$manual->cuts()` の scopeBindings）からのみ解決**。
- チケット復号値の cut_id が route の cut と不一致なら 404/422 で拒否（**チケット値を代入に使わない**）。予約行も `$cut->uploadReservations()` 経由で再取得（cross-cut は 404）。
- 期限切れ・released 済み・completed 済みチケットは拒否。

**D3. 容量 Quota 実計上（§10.8-4 / §10.5）** — `QuotaKey::MaxStorageBytes = 'max_storage_bytes'` を追加し `config/quota.php` の各プランに値を定義（free: 1 GiB / standard: 50 GiB。値は初期値、プラン設計で調整可能）。
- **bytes_used** = `takes.size_bytes` の org 単位合計（takes → cuts → video_manuals → projects → custom_teams の join。`StorageUsageService` に集約）。
- **bytes_pending** = pending 状態かつ未失効の `take_upload_reservations.size_bytes` の org 合計（org 列を予約行にサーバ導出で持つため単純 sum）。
- upload-url 発行時: **Organization 行を `lockForUpdate()`** した tx 内で `QuotaService::check($org, MaxStorageBytes, bytes_used + bytes_pending + size - 1)` → 予約 insert（check→reserve の TOCTOU を行ロックで直列化。`-1` は check の `>= limit` 判定を「登録後合計が limit を**超過する**場合のみ拒否」に合わせる補正。判定は QuotaService 経由のみの規約を維持）。
- `POST .../takes` 成功で予約を completed 化（= bytes_pending から自然に消え、確定分は takes.size_bytes で自動計上）。追加のカウンタテーブルは持たない（真実源 = 集計クエリ。二重帳簿を作らない）。

**D4. テイク登録の照合と冪等** — `POST .../takes` は HeadObject で ContentLength / ContentType をチケット・予約行と照合。**不一致は S3 オブジェクト削除 + 予約 released + 422 拒否**。オブジェクト不存在（PUT 未完了）は 422。`(cut_id, client_take_id)` UNIQUE（既存スキーマ）を冪等キーとし、既登録なら**既存 Take を 200 で返し、今回の予約は解放 + 今回アップロードされた S3 オブジェクトは削除**（応答喪失リトライ・二重送信で二重計上しない）。v1 はサーバ側トランスコード/サムネイル生成なし → 登録成功で `status=ready`（uploading/processing は将来の処理パイプライン用に温存）。duration_ms / captured_at はクライアント申告（非セキュリティメタデータ。size/content_type と異なり課金・検証に使わない）。sort_order はサーバ採番で**先頭**（doc/05: 新規テイクはリスト先頭）。

**D5. 採用・削除と共有ロック規約（AGENTS.md ドメイン固有規約 1）** — `cuts.adopted_take_id` の書き込み（adopt / 採用テイク削除時の null 化）は cuts テーブルへの書き込みのため、**VideoManual 行 `lockForUpdate()` 同一 tx 内**で行い、`ScenarioWritePathInventoryTest` のスキャナに **`adopted_take_id` 書き込み検出（検出 4）を追加**して経路を deny-by-default で固定する。
- adopt は `$cut->takes()` 経由でのみ take を解決（**cross-cut は 404** = IDOR）。`status != ready` の take は採用不可（422）。manual が analyzing / rendering 中は 409（シナリオ整合）。
- Take 削除: 採用中 take の削除は `adopted_take_id` を null 化。S3 オブジェクト削除は `DeleteTakeObjectsJob`（media queue）へ委譲（使用量は takes 行の削除で自動減算 = 再計算不要な集計方式）。

**D6. 「DL 済みは削除不可」の サーバ側表現** — doc/05 の「サーバーからダウンロードしたテイクは削除不可」を、`takes.downloaded_at`（nullable timestamp）で表現する。詳細 GET が採用テイクの署名 GET URL を発行した時点で打刻（= 端末へ自動 DL された事実のサーバ側オラクル）。`DELETE .../takes/{take}` は `downloaded_at` 非 null なら 422 拒否。採用直後・未配信の take は削除可能（このとき D5 の null 化が発火）。

**D7. S3 掃除（孤児防止）** —
- **Take 削除 / manual 削除**: 削除対象の S3 キー（video_path / thumbnail_path）を収集し `DeleteTakeObjectsJob`（新設 queue connection `database-media`、queue 名 `media`）で削除。`VideoManualService::delete` も cascade で消える takes / source_documents の S3 キーを収集して同 Job に渡す（SourceDocumentService に既存コメントで予告されていた「ストレージ Quota フェーズの掃除」を本フィーチャで実装）。
- **孤児掃除 cron** `capture:release-stale-upload-reservations`（10 分毎）: `expires_at` 超過の pending 予約を released 化し（= bytes_pending 解放）、対応する S3 キーに**未登録オブジェクトが存在すれば削除**（PUT 完了したが POST takes に至らなかったケース）。routes/console.php に登録（billing:release-stale-reservations と同型）。

**D8. sync（一括同期・新規テイクのみ）** — `POST .../manuals/{manual}/sync` は端末が持つ `{cut_id, client_take_id}` ペアの一覧を**照合専用 payload** として受け、サーバは manual の nested relation からのみ解決して「サーバ未登録の client_take_id 一覧」と「サーバ側テイク状態スナップショット」を返す。**このエンドポイント自体は書き込みしない**（登録は必ず D2-D4 の upload-url → PUT → POST takes 経路。ID 改竄は照合不一致として無視 or 404 で、代入には決して使わない）。冪等性は `(cut_id, client_take_id)` UNIQUE で担保。

**D9. フロント PWA** —
- Inertia ページ: `Capture/Index.svelte`（シナリオ一覧・カテゴリ/検索絞り込み）、`Capture/Show.svelte`（撮影ナビ: シナリオ表示 + カメラ + テイク一覧・採用・並べ替え・コメント）。コンポーネントは `features/capture/` 配下（atomic 単方向 import 遵守・DS token のみ・Lucide のみ・disabled 禁止 = 押下時エラー表示）。
- **カメラ**: `MediaRecorder` 対応判定 → 非対応（iOS Safari 等）は `<input type="file" accept="video/*" capture="environment">` へ**自動フォールバック**（v1 必須要件）。
- **アップロードキュー**: 録画 blob + メタを IndexedDB に保存し、キュー処理（upload-url → S3 PUT → POST takes）を共通クライアント `captureUploadClient` に集約。**X-XSRF-TOKEN を常時付与し、419 応答時は cookie 再取得（軽量 GET）→ 1 回リトライ**する共通 fetch ラッパを設ける。
- **Service Worker**: 静的アセットのキャッシュ + 「フォアグラウンド復帰時にアップロードキューを再開」する同期トリガー（Background Sync API はブラウザ差が大きいため、v1 は visibilitychange / online イベント + SW メッセージの堅実な組合せ。同期実行主体はページ側 JS = セッション cookie / XSRF が自然に効く）。manifest.webmanifest でホーム画面追加に対応。

**D10. 認可（§10.5・Policy 親委譲）** — `ProjectPolicy::capture(User, Project)` を追加（= org owner/admin または project_admin または **project_member**）。`TakePolicy` を新設し全 ability を親（ProjectPolicy）へ委譲（直 fetch 禁止）: 撮影者 = manual read（既存 VideoManualPolicy::view）+ take の upload/登録/並べ替え/コメント/削除/adopt。編集者も同権限を包含。

**D11. ストレージ抽象（テスト可能性）** — presigned PUT URL 発行（`temporaryUploadUrl`）/ 署名 GET URL（`temporaryUrl`）/ HeadObject / オブジェクト削除を `TakeObjectStorage` 1 クラスに集約。Feature テストは `Storage::fake('s3')` + 本クラスの container mock（presign/HeadObject は fake 値）で**実 S3・実 API に一切触れない**。Job は `Queue::fake` で検証。

## 期待効果

- **使命への直接貢献**: 「シナリオを見ながらナビ撮影」という AI-CUE の中核体験（撮影判断の肩代わり）が初めて成立する。PC（シナリオ設計）↔ 現場スマホ（撮影）の分業ループが閉じる。
- **課金基盤の完成**: max_storage_bytes が実計上で機能し、動画ストレージコストがプラン上限で構造的に抑制される（予約 + 実計上 + 孤児掃除で漏れなし）。
- **セキュリティ不変条件の維持**: presigned 直アップロードという「サーバを迂回する経路」を、検証専用チケット + HeadObject 照合 + nested route 解決で既存の tenant 境界モデルに閉じ込める。

## 実装方針（概要）

| レイヤ | 変更 |
|---|---|
| DB | `take_upload_reservations` 新設、`takes.downloaded_at` 追加 |
| Enum/Config | `QuotaKey::MaxStorageBytes`、`TakeUploadReservationStatus`、`config/quota.php` プラン値、`config/capture.php`（チケット TTL 等）、`config/queue.php` に `database-media` |
| Model | `TakeUploadReservation`（+Factory）、`Cut::uploadReservations()`、`Take` に downloaded_at |
| Service | `Capture/TakeUploadService`（quota+予約+presign+チケット）、`Capture/TakeRegistrationService`（検証+HeadObject+冪等登録）、`Capture/TakeService`（update/delete/adopt。VideoManual 行ロック）、`Capture/StorageUsageService`、`Capture/TakeObjectStorage`、`Capture/CaptureSyncService` |
| HTTP | `Capture/` 配下 Controller 群 + FormRequest（ProhibitsProtectedKeys）+ DTO/JsonResource |
| Routes | `/app/projects/{project}/...` web group（既存 middleware 再利用）+ IDOR inventory 登録 |
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
