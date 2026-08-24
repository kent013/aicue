# 詳細設計レビュー Round 2: Round 1 指摘への対応

Round 1 の Critical 3 件・Warning 4 件・Suggestion 2 件すべてに対応しました。対応の要点と、修正済みの詳細設計書全文（+ 現行コード抜粋は Round 1 と同一のため省略）を示します。

## 対応マトリクス

### [Critical] checksum カラム長の不整合 → 対応
migration を `string('checksum_sha256', 44)`（base64 固定・44 文字）に統一。hex 記述なし。Request `size:44` / `Sha256Checksum`（デコード後 32 bytes 検証）と整合。

### [Critical] createPresignedRequest の期限型 → 対応
`createPresignedRequest($command, $expiresAt)`（CarbonImmutable = DateTimeInterface）へ変更。`TakeObjectStorageTest` で期限・署名クエリ・`x-amz-checksum-sha256` 署名ヘッダを実 SDK オブジェクトで固定検証。

### [Critical] scopeBindings 推論への単独依存 → 対応（二重防御の明文化 + 挙動テスト）
- relation 名 = route param 推論名の一致は既存規約（フェーズ1 の VideoManual::manuals() 命名理由）として routes コメントに明記。
- 全書き込み Service は tx 内で `$project->manuals()->…->cuts()->…->takes()` の連鎖 firstOrFail 再解決を必須（施策4/5/6/8 が実装）→ 推論が外れても cross-parent 404。
- 施策7 テスト計画に「capture 全 nested route の cross-parent 組合せを実 HTTP で 404 検証」を追加（推論名変更はこれらの Feature テストが fail して検出）。

### [Warning] organization() relation 不在 → 追加（施策1）
### [Warning] claim 後 headObject 失敗の期限考慮 → 期限内 pending 戻し / 期限切れ released + 422 に統一（施策5 + テスト）
### [Warning] DeleteTakeObjectsJob の重複 path → dispatch 前に array_unique（施策9）
### [Warning] CSRF 再取得の HTML GET → 専用軽量 endpoint `GET /app/csrf-cookie`（204）を新設。再発行失敗は再試行せず 419 を返しキュー保留 + UI 通知（施策7/10）

### [Suggestion] checkAddition の overflow ガード → 採用（PHP_INT_MAX 跨ぎを超過扱い）
### [Suggestion] 検出 4 の代入式検出 → 採用（配列キー + プロパティ代入の token パターン検出を追加）

## 依頼

修正済み詳細設計書（下記全文）を再レビューし、各施策判定と全体判定（APPROVED / CHANGES_REQUESTED）を出してください。日本語で出力してください。

---

## 詳細設計書（修正済み全文）

# 詳細設計: 撮影PWA（presigned アップロード + テイク管理 + 容量 Quota）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

### セキュリティ不変条件（AGENTS.md より・アプリ都合で緩めない）

1. tenant キー不信（`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`）
2. 子は親に属する（nested route 不整合は認可より前に 404。`NestedRouteIdorDefenseTest` 登録必須）
3. cross-org 不可（relation / org-scoped 解決経由のみ）
4. untrusted 文字列は UserInput 型経由でのみ prompt へ（本フィーチャは LLM 非使用）
5. 権限判定は `laratrust_team_id` 明示
6. PII は CipherSweet（本フィーチャで PII 追加なし）
7. 課金の冪等性（予約は reserve→commit/release の 2 フェーズ思想を容量予約にも適用）
8. 外部 URL 取得は SSRF 検査経由（本フィーチャの S3 アクセスは自組織 config 固定エンドポイントのみ・ユーザ入力 URL なし）

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase はグローバル適用**（個別 `DatabaseTransactions` 禁止）・`--parallel`
- **テストデータは必ず Factory**（`Model::create()` 手組み禁止）。新モデルは Factory 作成 + `docs/architecture.md` / `docs/factories.md` 追記必須
- **DTO + JsonResource** パターン。Service は連想配列を受けない・返さない
- アーリーリターン推奨 / `composer fix`・`pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- フロントは DS token のみ・Lucide のみ・disabled 禁止・atomic 単方向 import

## 概念設計リファレンス

[devnotes/20260711-0345-capture-pwa/conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー合議済み。D1〜D12 の設計判断を本書が実装粒度に落とす）

## 施策一覧

| # | 施策名 | 主変更ファイル | 段 | 優先度 |
|---|--------|------------|----|--------|
| 1 | DB スキーマ（予約テーブル + downloaded_at） | migration ×2, `app/Models/TakeUploadReservation.php`, `app/Models/{Take,Cut}.php`, Factory | A | 高 |
| 2 | Quota 拡張（MaxStorageBytes + checkAddition + JSON render） | `app/Enums/QuotaKey.php`, `config/quota.php`, `app/Services/Billing/QuotaService.php`, `bootstrap/app.php`, `app/Http/Resources/Billing/QuotaExceededResource.php` | A | 高 |
| 3 | ストレージ基盤（TakeObjectStorage / StorageUsageService / config） | `app/Services/Capture/{TakeObjectStorage,StorageUsageService}.php`, `config/capture.php`, `config/queue.php` | A | 高 |
| 4 | presigned upload-url 発行（予約 + チケット） | `app/Services/Capture/TakeUploadService.php`, `app/Http/Controllers/Capture/TakeUploadUrlController.php`, Request/DTO/Resource | B | 高 |
| 5 | テイク登録（チケット検証 + HeadObject + 冪等） | `app/Services/Capture/TakeRegistrationService.php`, `app/Http/Controllers/Capture/CaptureTakeController.php`, Request/DTO/Resource | B | 高 |
| 6 | テイク管理（adopt / PATCH / DELETE / DL ACK + ロック規約拡張） | `app/Services/Capture/CaptureTakeService.php`, `tests/Architecture/ScenarioWritePathInventoryTest.php` | B | 高 |
| 7 | 撮影 routes + 画面 Controller + Policy | `routes/web.php`, `app/Http/Controllers/Capture/CaptureManualController.php`, `app/Policies/{ProjectPolicy,TakePolicy}.php`, `tests/Architecture/NestedRouteIdorDefenseTest.php` | B | 高 |
| 8 | sync（一括同期・照合専用） | `app/Services/Capture/CaptureSyncService.php`, `app/Http/Controllers/Capture/CaptureSyncController.php`, Request/DTO/Resource | C | 中 |
| 9 | S3 掃除（削除 Job + 孤児掃除 cron + manual 削除連携） | `app/Jobs/Capture/DeleteTakeObjectsJob.php`, `routes/console.php`, `app/Services/Manual/VideoManualService.php`, `app/Services/Capture/StaleUploadReservationSweeper.php` | C | 中 |
| 10 | PWA フロント（撮影ナビ + アップロードキュー + SW） | `resources/js/pages/Capture/*`, `resources/js/components/features/capture/*`, `resources/js/lib/capture/*`, `resources/js/types/capture.ts`, `public/{capture-sw.js,manifest.webmanifest}` | D | 高 |

---

## 施策1: DB スキーマ（take_upload_reservations + takes.downloaded_at）

### 変更箇所

- 新規: `database/migrations/2026_07_11_000100_create_take_upload_reservations_table.php`
- 新規: `database/migrations/2026_07_11_000200_add_downloaded_at_to_takes_table.php`
- 新規: `app/Models/TakeUploadReservation.php` / `app/Enums/Capture/TakeUploadReservationStatus.php` / `database/factories/TakeUploadReservationFactory.php`
- 変更: `app/Models/Cut.php`（`uploadReservations()` relation 追加）
- 変更: `app/Models/Take.php`（`downloaded_at` cast/`@property` 追加。fillable には入れない = サーバ打刻）
- 変更: `database/factories/TakeFactory.php`（`downloaded()` state 追加）
- 追記: `docs/architecture.md` / `docs/factories.md`

### 波及変更

- TypeScript 型定義: なし（施策10 で新設）
- API Resource/DTO: なし（施策4-5 で新設）
- テストファイル: `tests/Architecture/MassAssignmentSafetyTest.php` は既存の deny-by-default 走査で新モデルを自動検査（`cut_id` / `organization_id` は `MassAssignmentProtectedKeys` 登録済みのため fillable に入れないだけで green）

### 現行コード

なし（新規）。`takes` テーブルは `2026_07_10_000400_create_takes_table.php` で作成済み（`(cut_id, client_take_id)` UNIQUE あり）。

### 変更後コード

```php
// create_take_upload_reservations_table.php
Schema::create('take_upload_reservations', function (Blueprint $table): void {
    $table->id();
    // cut 配下の予約 (サーバ導出・protected)。cut 削除で予約も無効化 (S3 掃除は cron が拾う)
    $table->foreignId('cut_id')->constrained()->cascadeOnDelete();
    // bytes_pending の org 集計用の非正規化キー (サーバ導出・protected。join 4 段を避ける)
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->string('client_take_id', 26);          // 端末生成 ULID (照合用)
    $table->string('video_path');                  // サーバ生成 S3 キー
    $table->unsignedBigInteger('size_bytes');      // クライアント申告 → HeadObject で確定照合
    $table->string('content_type', 100);
    $table->string('checksum_sha256', 44);         // blob SHA-256 の base64 表現 (44 文字固定)。presign 署名で内容を固定 (D2b)
    $table->string('status', 20)->default('pending'); // string enum + アプリ層 cast (既存規約)
    $table->timestamp('expires_at');               // チケット TTL と同値
    $table->timestamps();
    $table->index(['organization_id', 'status', 'expires_at']); // bytes_pending 集計・stale 掃除
    $table->index(['cut_id', 'client_take_id']);
});

// add_downloaded_at_to_takes_table.php
Schema::table('takes', function (Blueprint $table): void {
    // DL 済み ACK (概念設計 D6)。打刻は POST .../downloaded 経由のみ (サーバ側)
    $table->timestamp('downloaded_at')->nullable();
});
```

```php
// app/Enums/Capture/TakeUploadReservationStatus.php
enum TakeUploadReservationStatus: string
{
    case Pending = 'pending';       // 予約中 (bytes_pending に計上)
    case Verifying = 'verifying';   // POST takes が claim 中 (外部 I/O 中。cron は fresh なら触れない)
    case Completed = 'completed';   // POST takes 成功 (以降 takes.size_bytes が真実源)
    case Released = 'released';     // 拒否・冪等重複・stale 掃除で解放
}
```

```php
// app/Models/TakeUploadReservation.php (抜粋)
/**
 * テイクアップロード予約 (概念設計 D2/D3 の真実源)。
 * - cut_id / organization_id は保護キーのため $fillable 外 (relation / forceFill で代入)
 * - bytes_pending = org 単位の pending & 未失効 size_bytes 合計 (StorageUsageService)
 */
class TakeUploadReservation extends Model
{
    /** @var list<string> */
    protected $fillable = ['client_take_id', 'video_path', 'size_bytes', 'content_type', 'checksum_sha256', 'expires_at'];

    protected function casts(): array
    {
        return [
            'status' => TakeUploadReservationStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cut, $this> */
    public function cut(): BelongsTo { return $this->belongsTo(Cut::class); }

    /** @return BelongsTo<Organization, $this> 集計用非正規化キーの relation (可読性・型補助) */
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
}
```

`Cut::uploadReservations(): HasMany<TakeUploadReservation, $this>` を追加。`TakeUploadReservationFactory` は `CutFactory` 連鎖 + `organization_id` は cut→manual→project→org を辿って導出する `configure()`（または `forCut(Cut $cut, Organization $org)` state）で与える。

### PHPStan適合チェック
- [x] Model の `@property` を全カラム分宣言（Take/Cut と同型）
- [x] casts() の戻り値型 `array<string, string>`
- [x] relation の generics（`HasMany<TakeUploadReservation, $this>`）
- [x] DTO 化は施策4 以降（本施策は Model のみ）

### テスト計画
- [ ] 新規: `tests/Feature/Capture/TakeUploadReservationModelTest.php` — Factory 生成・casts・relation・`(cut_id, client_take_id)` の予約検索
- [ ] 既存 Architecture: `MassAssignmentSafetyTest`（自動）— fillable に保護キー不含
- [ ] `TakeFactory::downloaded()` state の動作

### リスク
- 予約テーブルの肥大: completed/released 行が蓄積する。stale 掃除 cron（施策9）で released 化した行は一定期間後に物理削除する（同 cron 内。30 日）。

---

## 施策2: Quota 拡張（MaxStorageBytes + checkAddition + JSON レンダリング）

### 変更箇所

- 変更: `app/Enums/QuotaKey.php`（case + label）
- 変更: `config/quota.php`（free / standard に `max_storage_bytes`）
- 変更: `app/Services/Billing/QuotaService.php`（`checkAddition()` 追加）
- 変更: `bootstrap/app.php`（`QuotaExceededException` の expectsJson 分岐）
- 新規: `app/Http/Resources/Billing/QuotaExceededResource.php`

### 波及変更

- TypeScript 型定義: なし（エラー shape は `{ code, message }`。施策10 の fetch ラッパが解釈）
- API Resource/DTO: `QuotaExceededResource` 新設（`InsufficientTicketsResource` と同型）
- テストファイル: `tests/Architecture/QuotaKeyConfigInvariantTest.php`（enum⇔config 集合整合を自動検証 — case 追加と config 追加を同時に行えば green）

### 現行コード

```php
// QuotaService (抜粋)
public function check(Organization $organization, QuotaKey $key, int $currentCount): void
{
    $limit = $this->limits($organization)[$key->value] ?? null;
    if ($limit === null) { return; }
    if ($currentCount >= $limit) { throw QuotaExceededException::forLimit($key, $limit); }
}
```

```php
// bootstrap/app.php (抜粋)
$exceptions->render(function (QuotaExceededException $exception, Request $request) {
    return $request->is('api/*') ? null : back()->with('error', $exception->getMessage());
});
```

### 変更後コード

```php
// QuotaKey 追記
case MaxStorageBytes = 'max_storage_bytes';
// label(): self::MaxStorageBytes => '保存容量',

// config/quota.php plans 追記 (初期値。プラン設計で調整可能)
'free' => [..., 'max_storage_bytes' => 1 * 1024 * 1024 * 1024],      // 1 GiB
'standard' => [..., 'max_storage_bytes' => 50 * 1024 * 1024 * 1024], // 50 GiB
```

```php
// QuotaService 追加メソッド
/**
 * 加算量つき上限チェック (容量セマンティクス。概念設計 D3)。
 * check() の「件数が上限に達したら拒否 (current >= limit)」に対し、こちらは
 * 「加算後合計が上限を超過するなら拒否 (current + addition > limit)」。
 * limits に key が無ければ無制限。判定窓口の一元化規約 (docs 07 §4) を維持する。
 */
public function checkAddition(Organization $organization, QuotaKey $key, int $current, int $addition): void
{
    $limit = $this->limits($organization)[$key->value] ?? null;
    if ($limit === null) {
        return;
    }
    // int overflow ガード: 加算が PHP_INT_MAX を跨ぐ場合は超過として扱う (意図を型/算術で明示)
    if ($current > PHP_INT_MAX - $addition || $current + $addition > $limit) {
        throw QuotaExceededException::forLimit($key, $limit);
    }
}
```

```php
// bootstrap/app.php (InsufficientTicketsException と同じ 3 分岐へ)
$exceptions->render(function (QuotaExceededException $exception, Request $request) {
    if ($request->is('api/*')) {
        return null; // ApiExceptionRenderer に委譲
    }
    if ($request->expectsJson()) {
        // 撮影 PWA の XHR (upload-url 等) は 422 + JsonResource (back() の 302 を返さない)
        return QuotaExceededResource::make($exception)->response($request)->setStatusCode(422);
    }
    return back()->with('error', $exception->getMessage());
});
```

`QuotaExceededResource::toArray()` は `{ code: 'quota_exceeded', message: string }`（`InsufficientTicketsResource` と同構造・`$wrap = null`）。

### PHPStan適合チェック
- [x] `checkAddition` 引数・戻り値の型明示（int / void）
- [x] Resource の `@property-read QuotaExceededException $resource` + `toArray` の array shape
- [x] config 値は `config()->integer()` / `Assert::integer`（既存 limits() の検証を通る）

### テスト計画
- [ ] 新規: `tests/Feature/Billing/QuotaCheckAdditionTest.php` — 境界（current+addition == limit は許可 / > limit は例外 / key 未定義は無制限 / override 反映）
- [ ] 新規: XHR で QuotaExceededException → 422 JSON `{code: 'quota_exceeded'}`、web フォームでは従来どおり back+flash（回帰）
- [ ] 既存: `QuotaKeyConfigInvariantTest`（自動）

### リスク
- 既存 `check()` 呼び出しへの影響なし（追加メソッドのみ・既存挙動不変）。

---

## 施策3: ストレージ基盤（TakeObjectStorage / StorageUsageService / config / media queue）

### 変更箇所

- 新規: `app/Services/Capture/TakeObjectStorage.php`
- 新規: `app/Services/Capture/StorageUsageService.php`
- 新規: `app/DataTransferObjects/Capture/{PresignedUploadData,ObjectMetadataData}.php`
- 新規: `config/capture.php`
- 変更: `config/queue.php`（`database-media` connection）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: 上記 DTO 新設
- テストファイル: なし（本施策のテストは新規）

### 現行コード

なし（新規）。`config/filesystems.php` の `s3` disk は設定済み。テンプレに presign 実装は無い。

### 変更後コード

```php
// app/DataTransferObjects/Capture/PresignedUploadData.php
/** presigned PUT の発行結果 (D11: 戻り値 DTO 固定) */
final readonly class PresignedUploadData
{
    /** @param array<string, string> $headers クライアントが PUT に付ける署名対象ヘッダ */
    public function __construct(
        public string $url,
        public array $headers,
        public CarbonImmutable $expiresAt,
    ) {}
}

// app/DataTransferObjects/Capture/ObjectMetadataData.php
/** HeadObject の照合対象メタデータ */
final readonly class ObjectMetadataData
{
    public function __construct(
        public int $contentLength,
        public ?string $contentType,
        public ?string $checksumSha256, // HeadObject (ChecksumMode=ENABLED)。互換実装で欠落時 null
    ) {}
}
```

```php
// app/Services/Capture/TakeObjectStorage.php
/**
 * テイク動画 S3 オブジェクト操作の集約点 (概念設計 D11)。presign / HeadObject /
 * 署名 GET / 削除はすべて本クラス経由 (テストでは container mock + Storage::fake('s3'))。
 *
 * presigned PUT は SDK の createPresignedRequest を直接使い ContentType / ContentLength /
 * **ChecksumSHA256** を署名対象に含める (temporaryUploadUrl は署名できないため)。
 * S3 は本文がチェックサムと一致しない PUT を拒否するため、この URL で置ける内容は
 * 申告ハッシュの 1 通りに固定される = 登録後の再 PUT 差し替え防止 (概念設計 D2b)。
 */
class TakeObjectStorage
{
    public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
    {
        $adapter = Storage::disk('s3');
        Assert::isInstanceOf($adapter, AwsS3V3Adapter::class);
        $client = $adapter->getClient();
        $command = $client->getCommand('PutObject', [
            'Bucket' => config()->string('filesystems.disks.s3.bucket'),
            'Key' => $path,
            'ContentType' => $contentType,
            'ContentLength' => $sizeBytes,
            'ChecksumSHA256' => $checksumSha256, // x-amz-checksum-sha256 として署名される
        ]);
        // 期限は DateTimeInterface で渡す (int timestamp は SDK 実装依存の誤解を招くため。
        // 期限・署名クエリは TakeObjectStorageTest が固定検証する)
        $request = $client->createPresignedRequest($command, $expiresAt);

        return new PresignedUploadData(
            url: (string) $request->getUri(),
            headers: [
                'Content-Type' => $contentType,
                'x-amz-checksum-sha256' => $checksumSha256,
            ],
            expiresAt: $expiresAt,
        );
    }

    /** オブジェクトが存在しなければ null (PUT 未完了)。ChecksumMode=ENABLED で ChecksumSHA256 も取得 */
    public function headObject(string $path): ?ObjectMetadataData { /* HeadObject → DTO */ }

    /** 採用テイク再生用の署名 GET URL (temporaryUrl) */
    public function temporaryPlaybackUrl(string $path): string { /* TTL は config capture */ }

    public function delete(string $path): void { /* Storage::disk('s3')->delete */ }

    public function exists(string $path): bool { /* stale 掃除用 */ }
}
```

```php
// app/Services/Capture/StorageUsageService.php
/**
 * 容量 Quota の使用量集計 (§10.8-4 の真実源)。カウンタキャッシュは持たない (二重帳簿禁止)。
 */
class StorageUsageService
{
    /** takes.size_bytes の org 合計 (takes→cuts→video_manuals→projects→custom_teams join) */
    public function bytesUsed(Organization $organization): int
    {
        return (int) Take::query()
            ->join('cuts', 'cuts.id', '=', 'takes.cut_id')
            ->join('video_manuals', 'video_manuals.id', '=', 'cuts.video_manual_id')
            ->join('projects', 'projects.id', '=', 'video_manuals.project_id')
            ->join('custom_teams', 'custom_teams.id', '=', 'projects.custom_team_id')
            ->where('custom_teams.organization_id', $organization->id)
            ->sum('takes.size_bytes');
    }

    /**
     * 予約中の org 合計 (Quota 占有分):
     * - pending かつ未失効
     * - verifying は**全件** (claim 中に集計から消えて上限超過を許さない。概念設計 D3。
     *   stale verifying は cron が released 化して解放する)
     */
    public function bytesPending(Organization $organization): int
    {
        return (int) TakeUploadReservation::query()
            ->where('organization_id', $organization->id)
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $q) => $q
                    ->where('status', TakeUploadReservationStatus::Pending)
                    ->where('expires_at', '>', now()))
                    ->orWhere('status', TakeUploadReservationStatus::Verifying);
            })
            ->sum('size_bytes');
    }
}
```

```php
// config/capture.php (新規)
return [
    'upload_ticket_ttl_minutes' => 30,          // 予約 expires_at = チケット TTL (§10.8-4 の「一定時間」)
    'max_take_bytes' => 500 * 1024 * 1024,      // 1 テイク上限 500 MiB (バリデーション用)
    'allowed_video_content_types' => ['video/mp4', 'video/webm', 'video/quicktime'],
    'playback_url_ttl_minutes' => 60,           // 採用テイク署名 GET URL の TTL
    'released_reservation_retention_days' => 30, // released/completed 行の物理削除猶予
];

// config/queue.php 追記 (database-analysis と同型)
// メディア掃除専用 (DeleteTakeObjectsJob)。運用契約: worker は
// `php artisan queue:work database-media` を必須登録 (docs/architecture.md)
'database-media' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => 'media',
    'retry_after' => 300,
    'after_commit' => false,
],
```

### PHPStan適合チェック
- [x] `AwsS3V3Adapter` narrowing は `Assert::isInstanceOf`
- [x] sum() の戻り値は `(int)` cast + 集計クエリに PHPDoc
- [x] DTO は readonly + コンストラクタプロパティ・array shape 明示

### テスト計画
- [ ] 新規: `tests/Feature/Capture/StorageUsageServiceTest.php` — org 分離（他 org の takes/予約を混ぜて集計が漏れない）、**pending 未失効 + verifying 全件**を計上、期限切れ pending・released・completed は不算入
- [ ] 新規: `tests/Feature/Capture/TakeObjectStorageTest.php` — presign の**署名パラメータ配線を固定**（生成 URL/署名ヘッダに `x-amz-checksum-sha256` と Content-Type/ContentLength が含まれる。S3Client は偽エンドポイント設定・ネットワーク非到達）+ HeadObject コマンドに `ChecksumMode=ENABLED` が渡ること（Codex 概念レビュー Round 5 指摘: mock のみでは配線ミスを見逃すため実 SDK オブジェクトで固定）
- [ ] `config/capture.php` 値の読み出し（`config()->integer()` 経由）

### リスク
- bytes_used の 4 段 join は takes 増加で重くなる。upload-url 発行時のみ実行（読み取り頻度低）で v1 は許容。将来は org 単位 index（cuts.video_manual_id 等は FK index 済み）+ 実測で判断。

---

## 施策4: presigned upload-url 発行（Quota 予約 + 署名チケット）

### 変更箇所

- 新規: `app/Services/Capture/TakeUploadService.php`
- 新規: `app/Services/Capture/UploadTicketCodec.php`（Crypt 封入/復号の集約点）
- 新規: `app/Http/Controllers/Capture/TakeUploadUrlController.php`
- 新規: `app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php`
- 新規: `app/DataTransferObjects/Capture/{TakeUploadInput,UploadTicketClaims,TakeUploadTicketData}.php`
- 新規: `app/Http/Resources/Capture/TakeUploadTicketResource.php`

### 波及変更

- TypeScript 型定義: `resources/js/types/capture.ts` の `UploadTicket` interface（施策10）
- API Resource/DTO: 上記新設
- テストファイル: 新規 Feature（下記）

### 現行コード

なし（新規）。見本: `SourceDocumentService`（サーバ生成 path）、`VideoManualService`（行ロック tx）。

### 変更後コード

```php
// app/DataTransferObjects/Capture/Sha256Checksum.php
/** SHA-256 チェックサム値オブジェクト (D2b)。base64 正当性 + デコード後 32 bytes を生成時保証 */
final readonly class Sha256Checksum
{
    private function __construct(public string $base64) {}

    public static function fromBase64(string $value): self
    {
        $decoded = base64_decode($value, strict: true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new InvalidArgumentException('SHA-256 チェックサム (base64) が不正です');
        }
        return new self($value);
    }
}

// app/DataTransferObjects/Capture/TakeUploadInput.php
/** upload-url リクエストの検証済み入力 (Service は配列を受けない) */
final readonly class TakeUploadInput
{
    public function __construct(
        public string $clientTakeId,   // ULID (26 桁)
        public int $sizeBytes,
        public string $contentType,
        public Sha256Checksum $checksum, // D2b: presign 署名で内容を固定
    ) {}
}

// app/DataTransferObjects/Capture/UploadTicketClaims.php
/**
 * 署名チケットの封入 shape (§10.8-7)。**検証専用** — cut/org の解決には決して使わない。
 * fromArray/toArray で Crypt 封入の直列化を型に閉じる。
 */
final readonly class UploadTicketClaims
{
    public function __construct(
        public int $reservationId,
        public int $cutId,          // 一致検証のみ (route cut と比較)
        public string $clientTakeId,
        public int $sizeBytes,
        public string $contentType,
        public string $checksumSha256, // D2b (base64)
        public string $videoPath,
        public int $expiresAtTimestamp,
    ) {}
    /** @return array{r: int, c: int, t: string, s: int, m: string, h: string, p: string, e: int} */
    public function toArray(): array { /* 短縮キー直列化 */ }
    /** 復号直後の未検証動的値の唯一の型境界: 各キーの存在・型・範囲を Assert で検証 (概念設計 D2) */
    public static function fromArray(array $payload): self { /* Assert で全キー検証 */ }
}
```

```php
// app/Services/Capture/UploadTicketCodec.php
/**
 * チケットの封緘/開封。Crypt::encryptString (AEAD = 改竄検出) を用い、
 * 復号失敗 (DecryptException)・shape 不正・期限切れは null を返す (呼び出し側で 422)。
 */
class UploadTicketCodec
{
    public function seal(UploadTicketClaims $claims): string
    {
        return Crypt::encryptString(json_encode($claims->toArray(), JSON_THROW_ON_ERROR));
    }

    public function open(string $ticket): ?UploadTicketClaims { /* decrypt → fromArray → 期限検査 */ }
}
```

```php
// app/Services/Capture/TakeUploadService.php
/**
 * presigned PUT URL + 署名チケット発行 (§10.3 / §10.8-4,-7 / 概念設計 D2,D3)。
 * 直列化点: Organization 行ロック (check→reserve の TOCTOU 防止)。
 */
class TakeUploadService
{
    public function __construct(
        private readonly QuotaService $quota,
        private readonly StorageUsageService $usage,
        private readonly TakeObjectStorage $storage,
        private readonly UploadTicketCodec $codec,
    ) {}

    public function issue(Organization $organization, Project $project, VideoManual $manual, Cut $cut, TakeUploadInput $input): TakeUploadTicketData
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(config()->integer('capture.upload_ticket_ttl_minutes'));

        $reservation = DB::transaction(function () use ($organization, $project, $manual, $cut, $input, $expiresAt): TakeUploadReservation {
            /** @var Organization $lockedOrg */
            $lockedOrg = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み経路で再解決 (cross は 404)。manual 状態 guard も同時に行う
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->firstOrFail();
            if (! in_array($lockedManual->status, [VideoManualStatus::Ready, VideoManualStatus::Published], true)) {
                throw ValidationException::withMessages([
                    'manual' => ['このマニュアルは現在撮影できません（解析中・書き出し中）。'],
                ]);
            }
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();

            // Quota: bytes_used + bytes_pending + size が上限を超えるなら 422 (QuotaExceededException)
            $this->quota->checkAddition(
                $lockedOrg,
                QuotaKey::MaxStorageBytes,
                current: $this->usage->bytesUsed($lockedOrg) + $this->usage->bytesPending($lockedOrg),
                addition: $input->sizeBytes,
            );

            // S3 キーはサーバ生成 (SourceDocumentService と同じ規約)
            $path = sprintf(
                'projects/%d/manuals/%d/cuts/%d/takes/%s.%s',
                $lockedManual->project_id, $lockedManual->id, $lockedCut->id,
                (string) Str::ulid(), self::extensionFor($input->contentType),
            );

            $reservation = $lockedCut->uploadReservations()->make([
                'client_take_id' => $input->clientTakeId,
                'video_path' => $path,
                'size_bytes' => $input->sizeBytes,
                'content_type' => $input->contentType,
                'expires_at' => $expiresAt,
            ]);
            $reservation->forceFill(['organization_id' => $lockedOrg->id])->save();

            return $reservation;
        });

        // presign は外部 I/O のため tx 外 (ロック保持時間を最小化)。checksum を署名条件に含める (D2b)
        $presigned = $this->storage->presignUpload(
            $reservation->video_path, $input->contentType, $input->sizeBytes, $input->checksum->base64, $expiresAt,
        );
        $ticket = $this->codec->seal(UploadTicketClaims::fromReservation($reservation));

        return new TakeUploadTicketData($presigned, $ticket, $reservation->client_take_id);
    }
}
```

予約 insert（tx 内）では `checksum_sha256` も fill する（`$input->checksum->base64`）。

```php
// app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php — rules (ProhibitsProtectedKeys 併用)
return array_merge([
    'client_take_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'], // ULID
    'size_bytes' => ['required', 'integer', 'min:1', 'max:'.config()->integer('capture.max_take_bytes')],
    'content_type' => ['required', 'string', Rule::in(config()->array('capture.allowed_video_content_types'))],
    // base64(32bytes) = 44 文字。toTakeUploadInput() で Sha256Checksum::fromBase64 により厳密検証
    'checksum_sha256' => ['required', 'string', 'size:44', 'regex:%^[A-Za-z0-9+/]{43}=$%'],
], $this->protectedKeyMissingRules());
```

```php
// app/Http/Controllers/Capture/TakeUploadUrlController.php (骨子)
public function store(StoreTakeUploadUrlRequest $request, Project $project, VideoManual $manual, Cut $cut, TakeUploadService $uploads): TakeUploadTicketResource
{
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
    Gate::authorize('create', [Take::class, $project]);          // TakePolicy::create → ProjectPolicy::capture

    return TakeUploadTicketResource::make(
        $uploads->issue($organization, $project, $manual, $cut, $request->toTakeUploadInput()),
    );
}
```

`TakeUploadTicketResource::toArray()` → `{ upload_url, headers, ticket, client_take_id, expires_at }`（`$wrap = null`）。

### PHPStan適合チェック
- [x] 全 Service メソッドに引数/戻り値型・DTO 返却（配列返却なし）
- [x] `firstOrFail()` 後の `@var` narrowing（既存 Service と同型）
- [x] `config()->integer()` / `config()->array()` の typed accessor
- [x] `UploadTicketClaims::fromArray` は `Assert` で mixed→型确定

### テスト計画
- [ ] 新規: `tests/Feature/Capture/TakeUploadUrlTest.php`
  - 発行成功で pending 予約が作成され bytes_pending に計上される
  - bytes_used + pending + size > limit で 422 JSON `{code: 'quota_exceeded'}`（予約は作られない）
  - 境界: 加算後 == limit は成功
  - manual が draft / analyzing / rendering で 422
  - `cut_id` / `organization_id` / `video_path` を payload 直送で 422（protected + サーバ生成）
  - cross-org {project} 404 / cross-project {manual} 404 / cross-manual {cut} 404
  - 権限: project_member 可・org member（非 project member）403・別 org 404
  - content_type 非許可 / size 超過 / **checksum_sha256 形式不正（base64 44 文字でない・デコード 32 bytes でない）** で 422
  - `TakeObjectStorage` は mock（presign 引数 = 予約行の path/type/size/**checksum** を検証）

### リスク
- Organization 行ロックの競合: 同 org の全 upload-url が直列化されるが、tx 内は集計 2 クエリ + insert のみで短時間。presign を tx 外に出して保持時間を最小化。

---

## 施策5: テイク登録（POST takes = チケット検証 + HeadObject + 冪等）

### 変更箇所

- 新規: `app/Services/Capture/TakeRegistrationService.php`
- 新規: `app/Http/Requests/Capture/StoreCaptureTakeRequest.php`
- 新規: `app/DataTransferObjects/Capture/{TakeRegistrationInput,CaptureTakeData}.php`
- 新規: `app/Http/Resources/Capture/CaptureTakeResource.php`
- 新規: `app/Exceptions/Capture/CaptureConflictException.php` + `app/Enums/Capture/CaptureConflictType.php` + `app/Http/Resources/Capture/CaptureConflictResource.php`（409 JSON 契約。`ScenarioConflictException` と同じ「render() が JsonResource を返す」構造）
- 変更: `app/Http/Controllers/Capture/CaptureTakeController.php`（store。施策6 と同 Controller）

### 波及変更

- TypeScript 型定義: `types/capture.ts` の `CaptureTake`（施策10）
- API Resource/DTO: 上記新設
- テストファイル: 新規 Feature（下記）

### 現行コード

なし（新規）。`Take` model は fillable 定義済み（sort_order / status は fillable 外）。

### 変更後コード

```php
// app/Services/Capture/TakeRegistrationService.php (骨子)
/**
 * テイク登録 (§10.8-7 検証専用チケット / §10.8-4 予約確定 / (cut_id, client_take_id) 冪等)。
 * 処理順序は概念設計 D4 の確定契約:
 * 1. チケット開封 (改竄 → 422) + claims.cut_id === route cut (不一致 → 404)
 * 2. 予約行を $cut->uploadReservations() から再取得 (無ければ 404) + claims 全フィールド一致検証
 * 3. 冪等ショートカット (既存 Take × 予約状態の 3 分岐。登録済み動画を誤削除しない)
 * 4. 予約 claim (pending → verifying の原子的 UPDATE。cron と競合しない)
 * 5. HeadObject 三点照合 (size / content_type / ChecksumSHA256)
 * 6. tx: VideoManual 行ロック → sibling shift + Take insert (先頭) + 予約 completed
 */
class TakeRegistrationService
{
    public function register(Project $project, VideoManual $manual, Cut $cut, TakeRegistrationInput $input): TakeRegistrationResult
    {
        $claims = $this->codec->open($input->ticket);
        if ($claims === null) {
            throw ValidationException::withMessages(['ticket' => ['アップロードチケットが無効です。再取得してください。']]);
        }
        if ($claims->cutId !== $cut->id || $claims->clientTakeId !== $input->clientTakeId) {
            // チケットの cut/take 対応が URL と不一致 → 存在を漏らさず 404 (§10.8-7)
            throw (new ModelNotFoundException)->setModel(TakeUploadReservation::class, [$claims->reservationId]);
        }
        /** @var TakeUploadReservation $reservation */
        $reservation = $cut->uploadReservations()->whereKey($claims->reservationId)->firstOrFail();
        $this->assertClaimsMatchReservation($claims, $reservation); // 全フィールド一致 (防御的)

        // 3. 冪等ショートカット (D4-1): 既存 Take × 予約の関係で分岐
        $existing = $cut->takes()->where('client_take_id', $input->clientTakeId)->first();
        if ($existing !== null) {
            return $this->resolveDuplicate($existing, $reservation);
        }
        // completed なのに Take 不在 = 整合性異常 (D2)。削除せず 409 で調査可能な状態を残す
        if ($reservation->status === TakeUploadReservationStatus::Completed) {
            throw new CaptureConflictException(CaptureConflictType::ReservationInconsistent);
        }

        // 4. 予約 claim (D4-2): 外部 I/O 中に DB ロックを持たない。cron は fresh verifying に触れない
        $claimed = TakeUploadReservation::query()
            ->whereKey($reservation->id)
            ->where('status', TakeUploadReservationStatus::Pending)
            ->where('expires_at', '>', now())
            ->update(['status' => TakeUploadReservationStatus::Verifying]);
        if ($claimed === 0) {
            $reservation->refresh();
            if ($reservation->status === TakeUploadReservationStatus::Verifying) {
                // fresh verifying = 別リクエストが検証中 → 409 (処理中・リトライ可能。422 と区別)
                throw new CaptureConflictException(CaptureConflictType::RegistrationInFlight);
            }
            // released / 期限切れ pending → 422 (upload-url 再取得)
            throw ValidationException::withMessages(['ticket' => ['アップロードチケットの有効期限が切れています。再取得してください。']]);
        }

        // 5. HeadObject 三点照合 (D2b/D4-3)
        $head = $this->storage->headObject($reservation->video_path);
        if ($head === null) {
            // PUT 未完了: 期限内なら pending へ戻し再送可能に (Quota 占有は継続)。
            // claim 後に期限超過した予約は released へ倒して 422 (再取得を促す。曖昧な再試行を残さない)
            if ($reservation->expires_at->isFuture()) {
                $reservation->forceFill(['status' => TakeUploadReservationStatus::Pending])->save();
            } else {
                $reservation->forceFill(['status' => TakeUploadReservationStatus::Released])->save();
            }
            throw ValidationException::withMessages(['ticket' => ['アップロードが完了していません。']]);
        }
        $checksumMismatch = $head->checksumSha256 !== null && $head->checksumSha256 !== $reservation->checksum_sha256;
        if ($head->contentLength !== $reservation->size_bytes
            || ($head->contentType !== null && $head->contentType !== $reservation->content_type)
            || $checksumMismatch) {
            $this->discard($reservation, deleteObject: true); // §10.8-7: 不一致は削除・拒否 (released 化)
            throw ValidationException::withMessages(['ticket' => ['アップロード内容が申告と一致しません。']]);
        }

        // 6. 確定 tx
        return DB::transaction(function () use ($project, $manual, $cut, $input, $reservation): TakeRegistrationResult {
            // 共有ロック直列化 (sort_order 先頭採番の競合防止。cuts は書かない)
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();

            $lockedCut->takes()->increment('sort_order'); // 既存を後ろへ (先頭 = 0)
            $take = $lockedCut->takes()->make([
                'client_take_id' => $reservation->client_take_id,
                'video_path' => $reservation->video_path,
                'size_bytes' => $reservation->size_bytes,   // 予約 = HeadObject 照合済み確定値
                'duration_ms' => $input->durationMs,        // クライアント申告 (表示用)
                'captured_at' => $input->capturedAt,
            ]);
            $take->forceFill(['status' => TakeStatus::Ready, 'sort_order' => 0])->save();

            $reservation->forceFill(['status' => TakeUploadReservationStatus::Completed])->save();

            return TakeRegistrationResult::created($take);
        });
        // ※ insert の unique 衝突 (並行二重送信) は QueryException を catch し
        //    resolveDuplicate() へフォールバック (テストで固定)
    }

    /**
     * 既存 Take 発見時の分岐 (D4-1。登録済み動画を誤削除しない):
     * - 同一 completed 予約からの再送 (video_path 一致): 何も削除せず 200
    * - 別の pending/verifying 予約 (video_path 不一致): 予約 released + その予約のオブジェクトのみ削除して 200
     * - completed なのに path/checksum 矛盾: 削除せず 409 (整合性異常)
     */
    private function resolveDuplicate(Take $existing, TakeUploadReservation $reservation): TakeRegistrationResult { /* 上記 3 分岐 */ }
}
```

Controller は `created` なら 201 / `existing` なら 200 で `CaptureTakeResource` を返す（`TakeRegistrationResult` は `{take, wasCreated}` の小 DTO）。

```php
// StoreCaptureTakeRequest — rules
return array_merge([
    'ticket' => ['required', 'string', 'max:2048'],
    'client_take_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
    'duration_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
    'captured_at' => ['nullable', 'date'],
], $this->protectedKeyMissingRules());
```

`CaptureTakeData::fromTake(Take $take, ?string $playbackUrl = null)` → `{ id, client_take_id, status, size_bytes, duration_ms, comment, captured_at, sort_order, downloaded: bool, playback_url: string|null }`。

### PHPStan適合チェック
- [x] `TakeRegistrationResult` DTO（named constructor + readonly）
- [x] `head->contentType` null 許容の照合分岐
- [x] tx クロージャの戻り値型宣言・`@var` narrowing
- [x] increment はロック下で実行（race 説明コメント）

### テスト計画
- [ ] 新規: `tests/Feature/Capture/TakeRegistrationTest.php`
  - 正常登録: status=ready・sort_order=0（既存が +1）・予約 completed・bytes_pending 減
  - チケット改竄（別文字列/復号不能）422
  - 別 cut への流用（cut A で発行 → cut B の URL に POST）404
  - HeadObject 不存在 422（期限内: 予約は pending へ戻る = 再送可能・Quota 占有継続 / claim 後に期限超過: released へ倒す）
  - size / content_type / **ChecksumSHA256** 不一致 → `TakeObjectStorage::delete` 呼び出し + 予約 released + 422（三点照合）
  - **completed チケット再送: 200 既存返却 + `TakeObjectStorage::delete` が呼ばれない**（登録済み動画を消さない）
  - 別予約による重複（pending/verifying・別 path）: 200 既存 + その予約 released + **その予約のオブジェクトのみ**削除
  - completed だが Take 不在 / path 矛盾 → 409（削除なし）
  - **fresh verifying への再送 → 409（処理中）** / stale 判定は cron 側（施策9）
  - 期限切れチケット 422
  - `cut_id` payload 直送 422（protected）
  - cross-org/project/manual/cut 404、権限（project_member 可・非メンバー 403）
- [ ] unique 制約 race: 同時 POST を模し、insert 時 `QueryException`（unique violation）を catch → resolveDuplicate へフォールバックする経路
- [ ] claim と Quota: **verifying 中の予約が bytesPending に計上され続ける**（claim 直後に upload-url を発行しても上限を超えない）

### リスク
- HeadObject と PUT の整合: S3 は strong consistency（2020-12 以降）のため PUT 直後の Head は最新。MinIO 等の互換実装でも同様。
- ContentType が Head で欠落する互換実装 → null 時は照合スキップ（presign 署名で既に強制済みの二重防御という位置づけ）。

---

## 施策6: テイク管理（adopt / PATCH / DELETE / DL ACK + 共有ロック規約拡張）

### 変更箇所

- 新規: `app/Services/Capture/CaptureTakeService.php`
- 新規: `app/Http/Requests/Capture/UpdateCaptureTakeRequest.php`
- 変更: `app/Http/Controllers/Capture/CaptureTakeController.php`（update / destroy / adopt / markDownloaded）
- 新規: `app/Http/Resources/Capture/CaptureCutResource.php` + `app/DataTransferObjects/Capture/CaptureCutData.php`
- 変更: `tests/Architecture/ScenarioWritePathInventoryTest.php`（検出 4: `adopted_take_id` 書き込み走査）
- 追記: `AGENTS.md` ドメイン固有規約 1 / `docs/architecture.md`（採用 API が inventory 準拠になった旨）

### 波及変更

- TypeScript 型定義: `types/capture.ts`（施策10）
- API Resource/DTO: 上記新設
- テストファイル: `ScenarioWritePathInventoryTest` の allowlist（`Services/Capture/CaptureTakeService.php` / `Models/Cut.php`（relation 宣言）/ `DataTransferObjects/Capture/CaptureCutData.php`（読み取り直列化））

### 現行コード

`ScenarioWritePathInventoryTest` は検出 1〜3（scenario_version / status 書き込み / materialize 経路）のみ。`adopted_take_id` の識別子走査は未実装。

### 変更後コード

```php
// app/Services/Capture/CaptureTakeService.php (骨子)
/**
 * テイクの採用・並べ替え・コメント・削除・DL ACK (概念設計 D5, D6)。
 * adopted_take_id (cuts 列) の書き込みは共有ロック規約に従い VideoManual 行ロック tx 内のみ。
 * 経路は ScenarioWritePathInventoryTest 検出 4 が deny-by-default で固定する。
 */
class CaptureTakeService
{
    /** 採用 (§10.3 adopt)。cross-cut は 404、ready 前 422、analyzing/rendering 中 409 */
    public function adopt(Project $project, VideoManual $manual, Cut $cut, Take $take): Cut
    {
        return DB::transaction(function () use ($project, $manual, $cut, $take): Cut {
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            if ($lockedManual->status === VideoManualStatus::Rendering) {
                throw new ScenarioConflictException(ScenarioConflictType::Rendering, $lockedManual->scenario_version);
            }
            if ($lockedManual->status === VideoManualStatus::Analyzing) {
                throw new ScenarioConflictException(ScenarioConflictType::Analyzing, $lockedManual->scenario_version);
            }
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();
            // 採用テイクは cut->takes() 経由でのみ解決 (cross-cut = 404。§フェーズ1 将来必須条件)
            /** @var Take $lockedTake */
            $lockedTake = $lockedCut->takes()->whereKey($take->id)->firstOrFail();
            if ($lockedTake->status !== TakeStatus::Ready) {
                throw ValidationException::withMessages(['take' => ['このテイクはまだ採用できません（処理中/失敗）。']]);
            }
            $lockedCut->forceFill(['adopted_take_id' => $lockedTake->id])->save();

            return $lockedCut;
        });
    }

    /** コメント・並べ替え (position = cut 内 0 始まり)。sort_order はサーバ再採番 */
    public function update(Project $project, VideoManual $manual, Cut $cut, Take $take, CaptureTakeUpdateInput $input): Take { /* 行ロック tx + renumber */ }

    /** 削除。DL 済み (downloaded_at 非 null) は 422。採用中なら null 化 + S3 削除 Job */
    public function delete(Project $project, VideoManual $manual, Cut $cut, Take $take): void
    {
        $paths = DB::transaction(function () use ($project, $manual, $cut, $take): array {
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();
            /** @var Take $lockedTake */
            $lockedTake = $lockedCut->takes()->whereKey($take->id)->firstOrFail();
            if ($lockedTake->downloaded_at !== null) {
                throw ValidationException::withMessages(['take' => ['ダウンロード済みのテイクは削除できません。']]);
            }
            if ($lockedCut->adopted_take_id === $lockedTake->id) {
                // §10.8-4: 採用テイクが消えたら null 化 (DB nullOnDelete は最終防波堤)
                $lockedCut->forceFill(['adopted_take_id' => null])->save();
            }
            $paths = array_values(array_filter([$lockedTake->video_path, $lockedTake->thumbnail_path]));
            $lockedTake->delete();

            return $paths;
        });

        DeleteTakeObjectsJob::dispatch($paths); // tx 成功後に media queue へ
    }

    /**
     * DL 済み ACK (冪等。初回のみ打刻)。概念設計 D6: 署名 ACK トークン方式。
     * 詳細 GET が採用テイクの署名 DL URL と同時に発行した DownloadAckClaims
     * (take_id + user_id + 期限。Crypt 封緘・DL URL と同 TTL) を検証する:
     * - 復号不能 / 期限切れ / claims.take_id !== route take / claims.userId !== 現ユーザ → 422
     * - 検証成功: downloaded_at 未設定なら now() を打刻 (再送は no-op = 冪等)
     * 「現在採用中か」の動的検証はしない (DL→ACK 間の採用変更 race を排除。
     *  ACK トークンは採用テイクの DL URL としか一緒に発行されないため濫用も不能)
     */
    public function markDownloaded(User $user, Project $project, VideoManual $manual, Cut $cut, Take $take, string $ackToken): Take { /* codec 検証 + 行ロック tx + 初回 forceFill */ }
}
```

```php
// app/DataTransferObjects/Capture/DownloadAckClaims.php — ACK トークン封入 shape (検証専用)
final readonly class DownloadAckClaims
{
    public function __construct(
        public int $takeId,
        public int $userId,
        public int $expiresAtTimestamp,
    ) {}
    // UploadTicketClaims と同じ decoder 規約 (fromArray = Assert 全キー検証)。封緘/開封は
    // UploadTicketCodec に sealAck()/openAck() を追加 (payload 種別キーで相互流用を防ぐ)
}
```

```php
// ScenarioWritePathInventoryTest 追記 (検出 4)
/** 検出 4 の allowlist: 識別子/配列キー 'adopted_take_id' の出現 (書き込み経路の deny-by-default) */
private const ADOPTED_TAKE_ID_ALLOWED = [
    'Services/Capture/CaptureTakeService.php',        // adopt / 削除時 null 化 (行ロック tx 内)
    'Models/Cut.php',                                  // relation 宣言 (belongsTo 第 2 引数)
    'DataTransferObjects/Capture/CaptureCutData.php',  // 読み取り shape の直列化のみ
];
// findViolations() に 'adopted_take_id' 走査を追加: 検出 1 (scenario_version) と同じ
// 識別子/配列キー出現検出に加え、書き込み形 (`['adopted_take_id' => ...]` の配列キーと
// `->adopted_take_id =` のプロパティ代入) を検出 2 と同型の token パターンで個別検出し
// 抜け漏れを減らす (読み取り専用ファイルの allowlist 誤登録を書き込み形検出が補完)
```

`UpdateCaptureTakeRequest`: `comment` (nullable string max:2000) / `position` (nullable integer min:0) + `protectedKeyMissingRules()`（`sort_order` は入力名から排除 = 受けない）。adopt は body なしのため FormRequest 不要（plain `Request`）。downloaded は `MarkTakeDownloadedRequest`（`ack_token` required string max:2048 + `protectedKeyMissingRules()`）。

### PHPStan適合チェック
- [x] 全メソッド DTO/Model 戻り値（配列なし）
- [x] `array_filter` 後の `array_values` で list 化（paths の list<string> 型）
- [x] ScenarioConflictException 再利用（既存 409 契約と同 shape）

### テスト計画
- [ ] 新規: `tests/Feature/Capture/CaptureTakeManagementTest.php`
  - adopt: 正常（adopted_take_id 反映）/ **cross-cut 404**（cut B の take id を cut A の URL で）/ ready 前 422 / analyzing・rendering 中 409 / cross-org 404 / 権限
  - PATCH: comment 更新・position 並べ替え（サーバ再採番）・`sort_order` 直送 422
  - DELETE: 通常削除で `DeleteTakeObjectsJob` dispatch（`Queue::fake`）/ **DL 済み 422** / **採用中削除で null 化**
  - downloaded ACK: 有効トークンで打刻・**冪等（再送で timestamp 不変）**・**トークン不正/期限切れ/take 不一致/別ユーザ 422**・アップロードチケットを ACK に流用 422（payload 種別キー）・採用変更後も DL 時トークンで ACK 可能（race 解消の検証）
- [ ] Architecture: `ScenarioWritePathInventoryTest` 検出 4 — allowlist 外ファイルに `adopted_take_id` 書き込みを置くと fail する自己検証（既存検出 1〜3 と同型のテストケース追加）

### リスク
- adopt と scenario 保存（cut 削除）の並走: 双方 VideoManual 行ロックで直列化済み。cut が先に消えた場合 adopt は firstOrFail → 404。
- `CutFactory`/シナリオ経路への影響なし（adopted_take_id は本 Service 以外書かないことをテストが強制）。

---

## 施策7: 撮影 routes + 画面 Controller + Policy

### 変更箇所

- 変更: `routes/web.php`（`/app` group 追加）
- 新規: `app/Http/Controllers/Capture/CaptureManualController.php`（index / show / home）
- 新規: `app/DataTransferObjects/Capture/{CaptureManualSummaryData,CaptureManualDetailData}.php`
- 変更: `app/Policies/ProjectPolicy.php`（`capture()` 追加）
- 新規: `app/Policies/TakePolicy.php`
- 変更: `tests/Architecture/NestedRouteIdorDefenseTest.php`（inventory 追記）

### 波及変更

- TypeScript 型定義: `types/capture.ts`（施策10 で対保守）
- API Resource/DTO: 上記 DTO 新設
- テストファイル: `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` は「web の {project} route は project.in-route-org 必須」を deny-by-default 検証 — 新 group に middleware を付けることで自動 green（付け漏れは fail）

### 現行コード

`routes/web.php` L299-389（業務 route group）。`ProjectPolicy`（canManageProject / memberRole）。

### 変更後コード

```php
// routes/web.php — 認証済み group 内・業務 group の後に追加
/*
| 撮影 PWA (/app/*。§10.8-3 ルート分離)。web ガード + セッション + CSRF。
| データ API も /api/v1 (機械用) に混ぜずここに置く。GET は Inertia、書き込みは XHR JSON。
| {project} guard は業務 group と同じ 2 層 (middleware + inline)。
| {manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は scopeBindings
| (Project::manuals / VideoManual::cuts / Cut::takes。relation 名は route param の
|  推論名と一致させる既存規約 = VideoManual.php の manuals() 命名理由と同じ)。
| ★二重防御: scopeBindings の relation 推論に単独依存しない。全書き込み Service は
| tx 内で $project->manuals()->…->cuts()->…->takes() の連鎖再解決 (firstOrFail) を必須とし
| (施策4/5/6/8 の各 Service 実装がこれを行う)、推論が外れても cross-parent は 404 に落ちる。
| 挙動担保は各エンドポイントの cross-org/project/manual/cut 404 Feature テスト
| (relation 推論名が変わった場合もこれらが fail して検出する)。
*/
Route::middleware(['require-active-subscription', 'project.in-route-org'])
    ->prefix('app')->as('capture.')->group(function (): void {
        // PWA エントリ (manifest start_url)。current org の先頭 project へ redirect
        Route::get('/', [CaptureManualController::class, 'home'])->name('home');
        // CSRF cookie 再発行 (419 リトライ用の軽量 GET。web group を通るだけで
        // XSRF-TOKEN cookie が更新される。204 = 仕様固定 endpoint、body なし)
        Route::get('/csrf-cookie', fn (): \Illuminate\Http\Response => response()->noContent())
            ->name('csrf-cookie');
        Route::get('/projects/{project}/manuals', [CaptureManualController::class, 'index'])
            ->name('manuals.index');
        Route::scopeBindings()->group(function (): void {
            Route::get('/projects/{project}/manuals/{manual}', [CaptureManualController::class, 'show'])
                ->name('manuals.show');
            Route::post('/projects/{project}/manuals/{manual}/sync', [CaptureSyncController::class, 'store'])
                ->name('manuals.sync');
            Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url', [TakeUploadUrlController::class, 'store'])
                ->name('takes.upload-url');
            Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes', [CaptureTakeController::class, 'store'])
                ->name('takes.store');
            Route::patch('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}', [CaptureTakeController::class, 'update'])
                ->name('takes.update');
            Route::delete('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}', [CaptureTakeController::class, 'destroy'])
                ->name('takes.destroy');
            Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt', [CaptureTakeController::class, 'adopt'])
                ->name('takes.adopt');
            Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded', [CaptureTakeController::class, 'markDownloaded'])
                ->name('takes.downloaded');
        });
    });
```

```php
// ProjectPolicy 追加
/** 撮影 (take の capture/upload/adopt): 管理権限者または project メンバー (§10.5 撮影者) */
public function capture(User $user, Project $project): bool
{
    if ($this->canManageProject($user, $project)) {
        return true;
    }
    $organization = $project->organization;
    if ($organization === null || $user->organizationRole($organization) === null) {
        return false; // cross-org 不変条件
    }

    return $project->memberRole($user) !== null; // Admin / Member どちらも撮影可
}
```

```php
// app/Policies/TakePolicy.php — 全 ability を親 (ProjectPolicy::capture) へ委譲 (直 fetch 禁止)
class TakePolicy
{
    public function __construct(private readonly ProjectPolicy $projectPolicy) {}

    /** 作成 (upload-url / POST takes): 対象 Take が無いため Project を追加引数に取る */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->capture($user, $project);
    }

    public function update(User $user, Take $take): bool { return $this->captureVia($user, $take); }
    public function delete(User $user, Take $take): bool { return $this->captureVia($user, $take); }
    public function adopt(User $user, Take $take): bool { return $this->captureVia($user, $take); }
    public function markDownloaded(User $user, Take $take): bool { return $this->captureVia($user, $take); }

    private function captureVia(User $user, Take $take): bool
    {
        $project = $take->cut?->videoManual?->project;

        return $project !== null && $this->projectPolicy->capture($user, $project);
    }
}
```

```php
// NestedRouteIdorDefenseTest inventory 追記 (全て ScopeBindings)
'capture.manuals.show' => $s,
'capture.manuals.sync' => $s,
'capture.takes.upload-url' => $s,
'capture.takes.store' => $s,
'capture.takes.update' => $s,
'capture.takes.destroy' => $s,
'capture.takes.adopt' => $s,
'capture.takes.downloaded' => $s,
```

`CaptureManualController::index`: 撮影対象（status ∈ ready/published）の manual を category/検索 (`q`) で絞り込み、`CaptureManualSummaryData`（id/title/status/category/進捗 = cuts_total・cuts_adopted・cuts_with_takes/updated_at）の typed array を Inertia props で返す。`show`: `CaptureManualDetailData::fromManual()` が cuts（sort/parent 順）+ 各 cut の takes（`CaptureTakeData`）+ **採用テイクのみ** `TakeObjectStorage::temporaryPlaybackUrl()` と **`download_ack_token`**（`DownloadAckClaims` 封緘・DL URL と同 TTL。施策6 の ACK 検証対象）を付与（§10.3 / 概念設計 D6）。`Gate::authorize('view', $manual)`（読み取りは撮影者含む org member）。`home`: current org の先頭 project の `capture.manuals.index` へ redirect（project 無しは 404）。

`Take::cut` / `Cut::videoManual` / `VideoManual::project` の BelongsTo は既存。TakePolicy は Laravel の Policy auto-discovery（Models\Take → Policies\TakePolicy）で解決。

### PHPStan適合チェック
- [x] DTO typed array の PHPDoc（`list<CaptureManualSummaryData>` → `toArray()` array shape）
- [x] Policy の nullsafe 連鎖（`?->`）+ null ガード
- [x] Controller 戻り値型（Response / JsonResource / RedirectResponse）

### テスト計画
- [ ] 新規: `tests/Feature/Capture/CaptureManualBrowsingTest.php` — index の絞り込み（category/q/status スコープ）、draft/analyzing の非表示、show の props shape（採用テイクのみ playback_url、非採用は null）、cross-org 404、撮影者 read 可
- [ ] 新規: `tests/Feature/Capture/CapturePolicyTest.php` — org owner/admin・project_admin・project_member = capture 可 / 非 project member の org member = create 403（読み取りは可）/ 他 org = 404
- [ ] Architecture: `NestedRouteIdorDefenseTest`（inventory 追記で green。未登録なら fail する deny-by-default が担保）・`ProjectRouteCurrentOrgGuardTest`（自動）
- [ ] IDOR 実挙動（scopeBindings 推論への非依存担保）: capture 全 nested route について cross-parent 組合せ（別 project の manual / 別 manual の cut / 別 cut の take）を実 HTTP で叩き 404 を検証（施策4/5/6/8 の Feature テストに分担して網羅。relation 推論名の変更はこれらのテストが検出する）
- [ ] 既存回帰: `/projects/...`（PC ルート）に影響なし

### リスク
- `capture.home` の「先頭 project」規約は v1 の単一 Default Project 前提。複数 project 化の際は選択画面に差し替え（redirect 先変更のみで局所）。

---

## 施策8: sync（一括同期・照合専用）

### 変更箇所

- 新規: `app/Services/Capture/CaptureSyncService.php`
- 新規: `app/Http/Controllers/Capture/CaptureSyncController.php`
- 新規: `app/Http/Requests/Capture/SyncCaptureTakesRequest.php`
- 新規: `app/DataTransferObjects/Capture/{CaptureSyncInput,ClientTakeFingerprint,CaptureSyncResultData}.php`
- 新規: `app/Http/Resources/Capture/CaptureSyncResultResource.php`

### 波及変更

- TypeScript 型定義: `types/capture.ts` の `SyncResult`
- API Resource/DTO: 上記新設
- テストファイル: 新規 Feature

### 現行コード

なし（新規）。

### 変更後コード

```php
// SyncCaptureTakesRequest — rules (入力名は保護キーと別名の `cut`。ネスト位置の cut_id も missing)
return array_merge([
    'takes' => ['present', 'array', 'max:500'],
    'takes.*.cut' => ['required', 'integer'],
    'takes.*.client_take_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
    'takes.*.cut_id' => ['missing'], // ネスト位置の保護キー直送も 422
], $this->protectedKeyMissingRules());
```

```php
// CaptureSyncService (骨子)
/**
 * 一括同期 (§10.3 / §10.8-8)。**読み取り専用** — 本エンドポイントは何も書かない。
 * payload の cut/client_take_id は照合専用: manual の relation 集合と突き合わせ、
 * (a) manual に属さない cut 参照 → 404 (存在を漏らさない)
 * (b) サーバ未登録の fingerprint → pending_upload として返す (クライアントが D2-D4 経路で送信)
 * (c) 登録済み fingerprint → 現在のサーバ状態を返す (冪等: 何度呼んでも同じ)
 */
class CaptureSyncService
{
    public function reconcile(VideoManual $manual, CaptureSyncInput $input): CaptureSyncResultData
    {
        $cutIds = $manual->cuts()->pluck('id');
        foreach ($input->fingerprints as $fp) {
            if (! $cutIds->contains($fp->cutId)) {
                throw (new ModelNotFoundException)->setModel(Cut::class, [$fp->cutId]);
            }
        }
        $existing = Take::query()
            ->whereIn('cut_id', $cutIds)
            ->get(['id', 'cut_id', 'client_take_id', 'status', 'sort_order'])
            ->keyBy(fn (Take $t): string => $t->cut_id.':'.$t->client_take_id);

        $pendingUpload = array_values(array_filter(
            $input->fingerprints,
            fn (ClientTakeFingerprint $fp): bool => ! $existing->has($fp->cutId.':'.$fp->clientTakeId),
        ));

        return new CaptureSyncResultData(
            pendingUpload: $pendingUpload,               // 新規テイクのみ送信 (doc/05 §5.3)
            manual: CaptureManualDetailData::fromManual($manual), // サーバ状態スナップショット
        );
    }
}
```

### PHPStan適合チェック
- [x] `CaptureSyncInput::fromValidated()` が mixed 配列を `Assert` で `list<ClientTakeFingerprint>` へ確定
- [x] Collection generics（`Collection<string, Take>`）
- [x] Resource の array shape 明示

### テスト計画
- [ ] 新規: `tests/Feature/Capture/CaptureSyncTest.php`
  - 未登録 fingerprint が pending_upload で返る / 登録済みは返らない（**新規テイクのみ**）
  - **冪等**: 同 payload 連続 2 回で同一応答・DB 書き込みゼロ（`assertDatabaseCount` 不変）
  - 他 manual の cut id 混入 → 404（tenant キー不信）
  - `takes.*.cut_id` 直送 422 / 空 takes 配列は全量スナップショットのみ返す
  - cross-org/project 404・権限

### リスク
- fingerprint 500 件上限は端末内テイク数として十分（1 現場マニュアルの cut × テイクは高々数百）。超過時は 422 で分割送信を促す。

---

## 施策9: S3 掃除（DeleteTakeObjectsJob + 孤児掃除 cron + manual 削除連携）

### 変更箇所

- 新規: `app/Jobs/Capture/DeleteTakeObjectsJob.php`
- 新規: `app/Services/Capture/StaleUploadReservationSweeper.php`
- 変更: `routes/console.php`（`capture:release-stale-upload-reservations` コマンド + Schedule）
- 変更: `app/Services/Manual/VideoManualService.php`（delete 時の S3 キー収集 → Job dispatch）
- 追記: `docs/architecture.md`（media queue worker 運用契約）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Manual/VideoManualCrudTest`（既存の削除テストに Job dispatch 検証を追加）

### 現行コード

```php
// VideoManualService::delete (現行)
public function delete(Project $project, VideoManual $manual): void
{
    DB::transaction(function () use ($project, $manual): void {
        $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
        /** @var VideoManual $lockedManual */
        $lockedManual = $locked->manuals()->whereKey($manual->id)->firstOrFail();
        $lockedManual->delete();
    });
}
```

### 変更後コード

```php
// app/Jobs/Capture/DeleteTakeObjectsJob.php
/**
 * S3 オブジェクト削除 (media queue。§10.8-4)。payload は S3 キーの list のみ
 * (モデル/org 値を持たない = payload 不信任。RunManualAnalysis と同じ規約)。
 * 冪等: 既に無いキーの削除は no-op。
 */
class DeleteTakeObjectsJob implements ShouldQueue
{
    public int $tries = 3; // 削除は冪等のため再試行可 (backoff 60s)

    /** @param list<string> $paths */
    public function __construct(public readonly array $paths)
    {
        $this->onConnection('database-media');
    }

    public function handle(TakeObjectStorage $storage): void
    {
        foreach ($this->paths as $path) {
            $storage->delete($path);
        }
    }
}
```

```php
// VideoManualService::delete (変更後) — cascade で消える takes / source_documents の S3 キーを収集
public function delete(Project $project, VideoManual $manual): void
{
    $paths = DB::transaction(function () use ($project, $manual): array {
        $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
        /** @var VideoManual $lockedManual */
        $lockedManual = $locked->manuals()->whereKey($manual->id)->firstOrFail();
        $takePaths = Take::query()
            ->whereIn('cut_id', $lockedManual->cuts()->select('id'))
            ->get(['video_path', 'thumbnail_path'])
            ->flatMap(fn (Take $t): array => array_filter([$t->video_path, $t->thumbnail_path]))
            ->all();
        $documentPaths = $lockedManual->sourceDocuments()->pluck('file_path')->all();
        $lockedManual->delete(); // cuts / takes / source_documents は FK cascade

        return array_values([...$takePaths, ...$documentPaths]);
    });

    if ($paths !== []) {
        DeleteTakeObjectsJob::dispatch(array_values(array_unique($paths))); // 重複キーの無駄 I/O を除去
    }
}
```

```php
// StaleUploadReservationSweeper (骨子)
/**
 * 孤児掃除 (§10.8-4 / 概念設計 D7): 回収対象は
 * (a) expires_at 超過の pending 予約
 * (b) stale な verifying 予約 (updated_at が 15 分超過 = 登録リクエストの異常終了)
 * を released 化し (bytes_pending 解放)、S3 に PUT 済みだが未登録のオブジェクトを削除する。
 * **fresh な verifying には触れない** (登録処理の claim 契約と競合しない)。
 * 加えて released/completed の古い行 (retention 超過) を物理削除する。冪等。
 */
public function sweep(): int
{
    $stale = TakeUploadReservation::query()
        ->where(function (Builder $query): void {
            $query->where(fn (Builder $q) => $q
                ->where('status', TakeUploadReservationStatus::Pending)
                ->where('expires_at', '<=', now()))
                ->orWhere(fn (Builder $q) => $q
                    ->where('status', TakeUploadReservationStatus::Verifying)
                    ->where('updated_at', '<', now()->subMinutes(15)));
        })
        ->limit(500) // 1 回の I/O 上限
        ->get();
    foreach ($stale as $reservation) {
        $reservation->forceFill(['status' => TakeUploadReservationStatus::Released])->save();
        if ($this->storage->exists($reservation->video_path)) {
            $this->storage->delete($reservation->video_path); // 未登録オブジェクトの孤児削除
        }
    }
    TakeUploadReservation::query()
        ->whereIn('status', [TakeUploadReservationStatus::Released, TakeUploadReservationStatus::Completed])
        ->where('updated_at', '<', now()->subDays(config()->integer('capture.released_reservation_retention_days')))
        ->delete();

    return $stale->count();
}
```

```php
// routes/console.php 追記 (billing:release-stale-reservations と同型)
Artisan::command('capture:release-stale-upload-reservations', function (StaleUploadReservationSweeper $sweeper) {
    $released = $sweeper->sweep();
    $this->info("released {$released} stale upload reservation(s)");
})->purpose('期限切れのテイクアップロード予約を解放し S3 孤児オブジェクトを削除する');

Schedule::command('capture:release-stale-upload-reservations')->everyTenMinutes()->onOneServer()->withoutOverlapping();
```

### PHPStan適合チェック
- [x] Job payload は `list<string>`（PHPDoc + readonly）
- [x] flatMap/filter 後の list 化
- [x] sweep() 戻り値 int

### テスト計画
- [ ] 新規: `tests/Feature/Capture/StaleReservationSweepTest.php` — 期限切れ pending → released + `exists` 真のとき `delete` 呼び出し / 未失効 pending は不変 / **stale verifying（updated_at 15 分超過）→ released** / **fresh verifying は不変（登録処理と競合しない）** / retention 超過の released 行の物理削除 / 冪等（2 回実行）
- [ ] 新規: `tests/Feature/Capture/DeleteTakeObjectsJobTest.php` — paths 分の `delete` 呼び出し（storage mock）
- [ ] 既存拡張: manual 削除で takes/source_documents の S3 キーが Job に渡る（`Queue::fake`）
- [ ] cron 登録: `Schedule` に載っていることの smoke（既存 billing cron テストと同型があれば踏襲）

### リスク
- sweep と POST takes の race: 予約 released 化直後に登録が来ると 422（期限切れ扱い）でクライアントは upload-url 再取得 → 再アップロード。データ破壊はない（S3 キーは再発行で別 ULID）。
- exists/delete の I/O 回数: stale 予約は通常少数。バッチ上限（1 回 500 件）を sweep に入れる。

---

## 施策10: PWA フロント（撮影ナビ + アップロードキュー + SW）

### 変更箇所

- 新規: `resources/js/pages/Capture/Index.svelte` / `resources/js/pages/Capture/Show.svelte`
- 新規: `resources/js/components/features/capture/`（`CutNavigator.svelte`, `CameraRecorder.svelte`, `CaptureFileFallback.svelte`, `TakeStrip.svelte`, `TakeListItem.svelte`, `TakeCommentDialog.svelte`, `UploadQueueBar.svelte`）
- 新規: `resources/js/lib/capture/{http.ts,upload-queue.ts,camera.ts,idb.ts}`
- 新規: `resources/js/types/capture.ts`
- 新規: `public/capture-sw.js` / `public/manifest.webmanifest`
- 変更: `resources/views/app.blade.php`（manifest link + SW 登録は Capture ページ mount 時に限定）
- 新規: `tests/js/lib/capture/*.test.ts`（Vitest）

### 波及変更

- TypeScript 型定義: `types/capture.ts`（PHP DTO と対保守。`types/manual.ts` の対コメント規約を踏襲）
- API Resource/DTO: なし（施策4-8 の shape を消費）
- テストファイル: Vitest 新規 + `tests/js/architecture/atomic-import-graph.test.ts`（自動 — features/capture は単方向 import 規約に従えば green）

### 現行コード

なし（新規）。見本: `features/manual/ScenarioEditor.svelte`（XHR + csrf.ts）、`pages/Manuals/Show.svelte`（Inertia props interface）。

### 変更後コード（キー部分）

```ts
// resources/js/types/capture.ts (PHP: App\DataTransferObjects\Capture\* と対で保守)
export type TakeStatus = "uploading" | "processing" | "ready" | "failed";

export interface CaptureTake {
    id: number;
    client_take_id: string;
    status: TakeStatus;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    downloaded: boolean;
    /** 採用テイクのみ非 null (§10.3) */
    playback_url: string | null;
    /** 採用テイクのみ非 null。DL 完了時に POST .../downloaded へ送る署名 ACK トークン (D6) */
    download_ack_token: string | null;
}

export interface CaptureCut {
    id: number;
    type: "step" | "point";
    parent_cut_id: number | null;
    scene: string;
    shot_type: "hiki" | "yori";
    shooting_point: string | null;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    adopted_take_id: number | null;
    takes: CaptureTake[];
}

export interface UploadTicket {
    upload_url: string;
    headers: Record<string, string>;
    ticket: string;
    client_take_id: string;
    expires_at: string;
}
```

```ts
// resources/js/lib/capture/http.ts — 419 リトライ付き共通 fetch (§10.8-3)
import { csrfToken } from "@/lib/csrf";

/** 書き込み系 XHR。419 (CSRF 失効) は cookie 再取得 (軽量 GET /app/csrf-cookie) → 1 回だけ再送 */
export async function captureFetch(url: string, init: RequestInit = {}, retried = false): Promise<Response> {
    const response = await fetch(url, {
        ...init,
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-XSRF-TOKEN": csrfToken(),
            ...(init.headers ?? {}),
        },
    });
    if (response.status === 419 && !retried) {
        // 専用の軽量 endpoint (204・HTML レンダリングなし)。失敗 (非 2xx/network error) は
        // リトライせず元の 419 を返す (呼び出し側 = upload-queue がキュー保留 + UI 通知)
        try {
            const refresh = await fetch("/app/csrf-cookie", { credentials: "same-origin" });
            if (!refresh.ok) return response;
        } catch {
            return response;
        }
        return captureFetch(url, init, true);
    }
    return response;
}
```

```ts
// resources/js/lib/capture/camera.ts — MediaRecorder 対応判定 (§10.8-3 フォールバック必須)
export function supportsMediaRecorder(): boolean {
    return typeof window.MediaRecorder !== "undefined"
        && typeof navigator.mediaDevices?.getUserMedia === "function"
        && ["video/mp4", "video/webm"].some((t) => window.MediaRecorder.isTypeSupported?.(t) ?? false);
}
```

```ts
// resources/js/lib/capture/upload-queue.ts (骨子)
// 即時アップロード優先 (概念設計 D9):
// enqueue(blob, meta) → SHA-256 算出 (crypto.subtle.digest → base64。D2b)
//   → オンラインなら即 processOne (upload-url → S3 PUT → POST takes → dequeue)
// 失敗/オフライン時のみ IndexedDB に残し、visibilitychange / online / SW message で resume()
// - S3 PUT は presign headers をそのまま付ける (Content-Type / x-amz-checksum-sha256)
// - POST takes は captureFetch (CSRF/419 共通処理)
// - 409 registration_in_flight は指数 backoff で有界リトライ (fresh verifying = 処理中)
// - 422 quota_exceeded はキュー停止 + UI 通知 (disabled にはせず、送信時エラー表示)
// - (cut, client_take_id) はキュー生成時に ULID 採番 → 再送しても冪等
```

- **Capture/Show.svelte**: props は `{ project, manual: CaptureManualDetail }`。cut リスト（手順/急所ラベルは走査で派生・doc/10 §10.1）、行タップで撮影パネル（`supportsMediaRecorder()` 真なら `CameraRecorder`、偽なら `CaptureFileFallback` = `<input type="file" accept="video/*" capture="environment">`）。TakeStrip（先頭 = 採用候補・DL 済みは枠線区別・並べ替え・コメント・削除・採用ボタン）。採用テイクの `playback_url` DL 完了時に downloaded ACK POST。sync ボタン（アップロードダイアログ）で `pending_upload` 分のみ送信。
- **SW（public/capture-sw.js）**: 静的アセットの stale-while-revalidate + `message` イベントで `resume-uploads` をページへ broadcast（アップロード実行主体はページ側 JS = セッション cookie/XSRF が自然に効く。概念設計 D9）。ビルド依存を増やさない素の JS（vite-plugin-pwa は導入しない）。
- **manifest.webmanifest**: `start_url: "/app"`, `display: "standalone"`, アイコンは既存ロゴ流用。
- DS token / Lucide（`Camera`, `Video`, `Upload`, `Check`, `GripVertical`, `Trash2`, `Pencil`, `Lightbulb` 等）のみ。**disabled 禁止**: 録画未対応・quota 超過等は押下時にエラー toast/インライン表示。

### PHPStan適合チェック
- N/A（フロント）。`pnpm typecheck` strict で担保（`types/capture.ts` を Resource shape と対保守）

### テスト計画
- [ ] Vitest: `camera.test.ts` — MediaRecorder 有無・isTypeSupported 分岐でフォールバック判定
- [ ] Vitest: `http.test.ts` — 419 → cookie 再取得 GET → 再送 1 回（fetch mock）/ 2 連続 419 は失敗返却
- [ ] Vitest: `upload-queue.test.ts` — 即時アップロード成功で IndexedDB に残らない / 失敗時に永続化・resume で再送 / quota エラーで停止 / **SHA-256 base64 算出**（既知ベクトル）/ 409 registration_in_flight の有界 backoff
- [ ] Vitest: 採用テイクの DL 完了 → `download_ack_token` を付けて ACK POST（fetch mock）
- [ ] コンポーネント: `TakeStrip` の並べ替え・採用・DL 済み削除ボタン押下時のエラー表示（disabled でない）
- [ ] `pnpm build`（SW/manifest を含め green）

### リスク
- iOS Safari の MediaRecorder は 14.5+ で利用可だが実機差があるため、フォールバックを常時提供（§10.7-3 の実機検証項目は実装フェーズの手動確認チェックリストに含める）。
- IndexedDB eviction: 即時アップロード優先設計で保持時間を最小化（概念設計 D9）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental（4 TODO 連番: A 基盤（施策1-3）→ B API core（施策4-7）→ C 同期・掃除（施策8-9）→ D PWA フロント（施策10）） |
| 判断根拠 | 施策間に強い依存（B は A のスキーマ/Quota/ストレージ抽象に、D は B/C の API 契約に依存）があり並行 worktree の競合リスクが高い。各段は単独で `composer test` green にできる粒度 |
| 競合リスク | `routes/web.php` / `bootstrap/app.php` / `config/queue.php` は他フィーチャと衝突しやすいが、いずれも追記型変更。`ScenarioWritePathInventoryTest` の検出 4 追加はシナリオ関連の並行変更と要調整 |

## 使命・禁止事項 最終チェック

- 全施策は「スマホでナビ撮影 → 標準化マニュアル動画」の中核体験に直結（使命整合）
- テスト無し完了なし（各施策にテスト計画 + Architecture inventory 登録を含む）/ DTO+JsonResource 徹底 / disabled UI なし / `redirect()->intended()` 不使用 / LLM 非使用（Prism 規約非該当）
- セキュリティ不変条件: 保護キー（cut_id 等）payload 拒否・nested route IDOR inventory・cross-org 404・課金 2 フェーズ思想の容量予約を全施策に織り込み済み
