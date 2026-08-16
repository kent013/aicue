## Round 3 指摘への対応

残る 1 件 (S3 の削除失敗処理) に対応しました。`File::delete()` + 削除後の存在確認へ変更し、
`File` facade の import を追加しています。警告の例外化に依存せず戻り値だけで判定が閉じるため、
`TakeThumbnailExtractionException` への集約という契約が実行環境によらず成立します。
削除失敗テストを OS 権限に依存させない (File facade を差し替える) 方針もテスト計画へ明記しました。

# 対応マトリクス: design-review Round 3

## [Warning] S3: 素の `unlink()` は失敗時に E_WARNING を出し、例外化する環境では戻り値判定へ到達しない
- 判断: **対応する (指摘どおり)**
- 根拠: Laravel のエラーハンドラは警告を `ErrorException` へ変換する。その環境では
  `return "failed to remove …"` に到達せず、設計した `TakeThumbnailExtractionException` への
  集約・失敗理由の形式・Unit テストの契約から外れる (ジョブが失敗する点は同じでも、
  「失敗の形」が設計と食い違う)。
- 対応内容: `File::delete()` + **削除後の存在確認**で判定する形へ変更し、
  `Illuminate\Support\Facades\File` の import を追加した。
  判定が戻り値だけで閉じるため、警告の例外化に依存しなくなる。

## [Warning] (同上) 削除失敗テストが OS 権限に依存すると並列テストで不安定
- 判断: **対応する**
- 対応内容: テスト計画に「`File` facade を差し替えて『削除が効かなかった』状況を決定的に作る
  (OS の権限に依存させない)」と明記した。`--parallel` 実行でも再現性が壊れない形にする。

## Round 2 対応の評価 (指摘なし)
- 判断: 見送る (変更不要)

---

## 修正後の詳細設計書 (S3 抜粋 + 全文)

# 詳細設計: take-thumbnail-generation (テイクのサムネイル生成)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(本タスクは LLM を使わない)
6. prompt 文字列のコード直書き(本タスク該当なし)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント
- フロントは Svelte 5 runes + DS token のみ、アイコンは `@lucide/svelte` のみ、
  component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import

### 本タスクで特に効く既存規約

- **ドメイン固有規約 6** (ジョブの重複実行と結果の一回性): 全 `ShouldQueue` は
  `JobExecutionDedupInventoryTest` へ保証側 or 免除で登録。取り消せない外部副作用 (S3 PUT) の
  直前に preflight を置き、その後に自前の書き込みを挟まない。
- **ドメイン固有規約 11** (キュー投入の原子性): 業務状態の保存とキュー投入は同一 tx 内
  (`afterCommit` 禁止)。`Queue::fake()` では原子性を検証できない (実 `jobs` 表で見る)。
- **セキュリティ不変条件 2 / 10**: nested route の不整合は**認可より前に 404**。
  `NestedRouteDefenseInventory` への parameter 単位の登録が必須。
- **セキュリティ不変条件 3**: クラス起点の主キー同一性クエリは `DirectFetchInventory` へ分類登録。
- **T126 到達境界**: S3 adapter の public メソッドは `S3SurfaceInventory` で面分類。

## 概念設計リファレンス

- [devnotes/20260816-1021-take-thumbnail-generation/conceptual-design.md](./conceptual-design.md)
  (Codex `conceptual-review` Round 4 で **APPROVED**)
- 議論履歴: `codex-history/conceptual-review-prompt-round-{1..4}.md` /
  `codex-history/conceptual-review-decisions-round-{1..4}.md` / `conceptual-review-round-{1..4}.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | サムネイル容量列の追加と使用量集計への反映 | `database/migrations/*_add_thumbnail_size_bytes_to_takes_table.php` (新規) / `app/Models/Take.php` / `database/factories/TakeFactory.php` / `app/Services/Capture/StorageUsageService.php` | 高 |
| S2 | `TakeObjectStorage` にサムネイル用の 3 メソッドを新設 | `app/Services/Capture/TakeObjectStorage.php` / `app/Services/Capture/Fakes/FakeTakeObjectStorage.php` / `tests/Support/Storage/S3SurfaceInventory.php` | 高 |
| S3 | ffmpeg 抽出の差し替え可能な境界 | `app/Services/Capture/TakeThumbnailExtractor.php` (新規) / `app/Services/Capture/FfmpegTakeThumbnailExtractor.php` (新規) / `app/Exceptions/Capture/TakeThumbnailExtractionException.php` (新規) / `config/capture.php` / `app/Providers/AppServiceProvider.php` | 高 |
| S4 | 生成パイプラインと queue job (preflight + 条件付き UPDATE) | `app/Services/Capture/TakeThumbnailPipeline.php` (新規) / `app/Jobs/Capture/GenerateTakeThumbnailJob.php` (新規) | 高 |
| S5 | テイク登録の確定 tx からの投入 | `app/Services/Capture/TakeRegistrationService.php` | 高 |
| S6 | deny-by-default 目録への登録 | `tests/Architecture/JobExecutionDedupInventoryTest.php` / `tests/Architecture/QueuedJobLeaseInventoryTest.php` / `tests/Support/Security/DirectFetchInventory.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` (+ 再生成) | 高 |
| S7 | サムネイル配信 endpoint | `routes/web.php` / `app/Http/Controllers/Capture/CaptureTakeController.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` | 高 |
| S8 | `has_thumbnail` の DTO / Resource / TS 型への追加 | `app/DataTransferObjects/Capture/CaptureTakeData.php` / `app/Http/Resources/Capture/CaptureTakeResource.php` / `resources/js/types/capture.ts` | 高 |
| S9 | テイク一覧のサムネイル表示とプレースホルダ | `resources/js/components/features/capture/TakeStrip.svelte` | 中 |
| S10 | 撮影画面内の有界な自動反映 | `resources/js/lib/capture/thumbnail-refresh.ts` (新規) / `resources/js/pages/Capture/Show.svelte` | 中 |
| S11 | ドキュメント更新 | `docs/architecture.md` | 中 |

---

## S1: サムネイル容量列の追加と使用量集計への反映

### 変更箇所

- 新規 migration: `database/migrations/2026_08_16_000100_add_thumbnail_size_bytes_to_takes_table.php`
- `app/Models/Take.php` (L14-51 の docblock / `$fillable`)
- `database/factories/TakeFactory.php` (L24-38 の `definition()`)
- `app/Services/Capture/StorageUsageService.php` (L40-50 の `bytesUsed()`)

### 波及変更

- TypeScript 型定義: **なし** (バイト数はクライアントへ出さない。`has_thumbnail` は S8 の別値)
- API Resource/DTO: **なし** (`CaptureTakeData` はサムネイルのサイズを露出しない)
- テストファイル: `tests/Feature/Capture/StorageUsageServiceTest.php` (集計の期待値)、
  `tests/Feature/Capture/TakeUploadUrlTest.php` (Quota 判定の期待値に影響しうる箇所の確認)
- 削除経路: **変更なし**。テイク行の削除で列ごと消えるため解放は自動で整合する
  (`CaptureTakeService::delete()` / `VideoManualService` は `thumbnail_path` の回収済み実装のまま)
- 保持期限台帳 (`RetentionTableRegistry`): **変更なし** (表を足していない。台帳は表単位で列を見ない)

### 現行コード

```php
// app/Services/Capture/StorageUsageService.php L40-50
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
```

```php
// database/migrations/2026_07_10_000400_create_takes_table.php L18-35 (抜粋)
$table->string('video_path');
$table->string('thumbnail_path')->nullable();
$table->bigInteger('size_bytes');
```

### 変更後コード

```php
// database/migrations/2026_08_16_000100_add_thumbnail_size_bytes_to_takes_table.php (新規)
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * サムネイルの実バイト数 (容量 Quota の集計対象)。
     *
     * - `takes.size_bytes` とは**別列**にする。size_bytes は
     *   「予約 (take_upload_reservations.size_bytes) と HeadObject の ContentLength が
     *   三点照合で一致した確定値」であり、その同一性が
     *   StorageUsageService::occupiedBytes() の pending→used 読み取り順の根拠になっている。
     *   事後に生成されるサムネイル分を足し込むとその根拠が読めなくなる。
     * - 生成前 / 生成失敗のテイクは NULL (= 0 として集計する)。既存行も NULL のままでよい。
     * - integer で足りる (出力は config で寸法・品質を固定した JPEG 1 枚)。
     */
    public function up(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->integer('thumbnail_size_bytes')->nullable()->after('thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->dropColumn('thumbnail_size_bytes');
        });
    }
};
```

```php
// app/Models/Take.php

/**
 * ...
 * - thumbnail_path / thumbnail_size_bytes は**サーバ生成の会計値**のため $fillable 外。
 *   書き込みは TakeThumbnailPipeline の条件付き UPDATE (query builder) だけである
 *
 * @property string|null $thumbnail_path
 * @property int|null $thumbnail_size_bytes
 */

/** @var list<string> */
protected $fillable = [
    'client_take_id',
    'video_path',
    // 'thumbnail_path' を**外す** (下記参照)
    'size_bytes',
    'duration_ms',
    'comment',
    'captured_at',
];

/**
 * @return array<string, string>
 */
protected function casts(): array
{
    return [
        'status' => TakeStatus::class,
        'captured_at' => 'datetime',
        'downloaded_at' => 'datetime',
        // 読み取り型を driver 依存にしない (DTO / Resource / PHPStan が int|null で安定する)。
        // ★ size_bytes 側には cast を足さない — 既存の比較箇所への影響を本タスクへ持ち込まないため。
        //   非対称は意図的である
        'thumbnail_size_bytes' => 'integer',
    ];
}
```

> **`$fillable` から `thumbnail_path` を外す**。この列は撮影 PWA フェーズ前の schema 先取り時に
> fillable へ入ったまま**一度も mass assignment されていない** (`app/` 配下の書き込み経路はゼロで、
> `VideoManualService` / `CaptureTakeService` はどちらも読み取り)。本タスクで初めて書き込み経路が
> できるが、それは条件付き UPDATE であって fill ではない。サーバ生成の会計値 2 列を
> **同じ扱い (fillable 外)** に揃える (後方互換の並走を残さない = 思考原則 3)。
> Factory は `Factory::make()` が `Model::unguarded()` の中で実体化するため影響を受けない。

```php
// database/factories/TakeFactory.php
'thumbnail_path' => null,
'thumbnail_size_bytes' => null,

// ... 末尾に state を追加
/** サムネイル生成済み (容量集計・一覧表示のテスト用) */
public function withThumbnail(int $sizeBytes = 40_000): static
{
    return $this->state(fn (): array => [
        'thumbnail_path' => 'takes/thumbnails/'.fake()->uuid().'.jpg',
        'thumbnail_size_bytes' => $sizeBytes,
    ]);
}
```

```php
// app/Services/Capture/StorageUsageService.php
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * 動画本文 + サムネイルの org 合計 (takes→cuts→video_manuals→projects→custom_teams join)。
 *
 * ★ **サムネイルは予約 (take_upload_reservations) を経ない事後計上**である。
 *   上限の強制点は presigned URL 発行時 (TakeUploadService::issue) のままで、
 *   サムネイル生成が上限を跨ぐことはありうる (受容。超過表示は QuotaStatusDto が既に持つ)。
 * ★ 合成を SQL 式でなく PHP 側で行うのは、`(int) …->sum('列名')` という
 *   **既に PHPStan level 10 を通っている形**から外れないため
 *   (生の式を sum() へ渡す形は新しい型の不確実性を持ち込む)。
 * ★ overflow は occupiedBytes() と同じく上限側へ丸める。
 */
public function bytesUsed(Organization $organization): int
{
    $video = (int) $this->takesForOrganization($organization)->sum('takes.size_bytes');
    $thumbnails = (int) $this->takesForOrganization($organization)->sum('takes.thumbnail_size_bytes');

    return $video > PHP_INT_MAX - $thumbnails ? PHP_INT_MAX : $video + $thumbnails;
}

/**
 * org 配下の takes を引く builder。
 *
 * ★ **呼び出しごとに新しい Builder を返す** (同一インスタンスを 2 回の集計で使い回すと
 *   1 本目の集計が builder を汚し、2 本目の結果が変わる)。
 *
 * @return EloquentBuilder<Take>
 */
private function takesForOrganization(Organization $organization): EloquentBuilder
{
    return Take::query()
        ->join('cuts', 'cuts.id', '=', 'takes.cut_id')
        ->join('video_manuals', 'video_manuals.id', '=', 'cuts.video_manual_id')
        ->join('projects', 'projects.id', '=', 'video_manuals.project_id')
        ->join('custom_teams', 'custom_teams.id', '=', 'projects.custom_team_id')
        ->where('custom_teams.organization_id', $organization->id);
}
```

> `occupiedBytes()` は**無変更**。pending→used の読み取り順の不変条件は維持される
> (サムネイル分は `bytes_pending` 側に対応物を持たないため、順序の議論に影響しない)。
> 呼び出し元 3 者 (`TakeUploadService::issue` / `DashboardService::billingSummary` /
> `BillingController`) はいずれも `occupiedBytes()` 経由のため**変更不要**。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`int` / `EloquentBuilder<Take>`)
- [x] null 安全: `sum()` は全行 NULL のとき null を返しうるが `(int)` で 0 に落ちる (既存と同じ受け方)
- [x] DTO を返している (本施策は集計値の int。DTO 化の対象ではない)
- [x] Generics の型パラメータが正しい (`EloquentBuilder<Take>`。`Illuminate\Contracts\Database\Eloquent\Builder`
      は既存 `bytesPending()` のクロージャ引数で使われているため **alias で衝突を避ける**)

### テスト計画

- [x] 既存テスト `tests/Feature/Capture/StorageUsageServiceTest.php` の更新
- [ ] 新規: `bytesUsed は thumbnail_size_bytes を加算する` — `Take::factory()->withThumbnail(40_000)`
      と `size_bytes` の合計が返ること
- [ ] 新規: `thumbnail_size_bytes が NULL のテイクは 0 として数える` (既存テイクの回帰)
- [ ] 新規: `takesForOrganization は集計ごとに独立した builder を返す` —
      同一 org で `bytesUsed()` を 2 回呼んでも同じ値になること (builder 使い回しの検出)
- [ ] 新規: `他組織のサムネイルは加算されない` (join 条件の回帰)
- [ ] 既存: `TakeUploadUrlTest` の Quota 判定が緑のままであること
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`$fillable` から列を外す変更**は、見落とした fill 経路があると無音で値が入らなくなる。
  → 実装時に `rg "'thumbnail_path'" app/ database/` で書き込み経路が無いことを再確認する
  (設計時点の確認では `Take.php` の `$fillable` 宣言と `VideoManualService` の
  `get(['video_path','thumbnail_path'])` の 2 件のみ = fill 経路ゼロ)。
- **既存の Quota 系テストが赤くなる**可能性 (Factory に列が増えるため期待値がずれる)。
  → Factory 既定は `null` なので既存の期待値は変わらないはず。実行して確認する。
- サムネイル分だけ使用量が増えるため、**上限ぎりぎりの組織が超過表示になる**ことがある。
  これは事実の反映であり (実際に置いている)、既存の超過表示・422 拒否がそのまま受ける。

---

## S2: `TakeObjectStorage` にサムネイル用の 3 メソッドを新設

### 変更箇所

- `app/Services/Capture/TakeObjectStorage.php` (L94-113 の周辺へ 3 メソッド追加)
- `app/Services/Capture/Fakes/FakeTakeObjectStorage.php` (対応 override 3 件)
- `tests/Support/Storage/S3SurfaceInventory.php` (面分類 3 件)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/ExternalClientTimeoutInventoryTest.php` は
  `S3SurfaceInventory` を読むため**目録の更新だけで追随**する (テスト本体は変更しない)。
  `tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php` は「web 同期経路が `Bulk` を呼ばない」
  ことを behavioral に固定しており、新 `Bulk` メソッド 2 件が**登録経路から呼ばれない**ことを
  自動的に含むようになる (テスト本体の変更は不要。緑のままであることを確認する)。

### 現行コード

```php
// app/Services/Capture/TakeObjectStorage.php L94-113
/** 採用テイク再生用の署名 GET URL (TTL は config capture.playback_url_ttl_minutes) */
public function temporaryPlaybackUrl(string $path): string
{
    return Storage::disk('s3')->temporaryUrl(
        $path,
        now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
    );
}

/** オブジェクト削除 (存在しないキーは no-op = 冪等) */
public function delete(string $path): void
{
    Storage::disk('s3')->delete($path);
}
```

### 変更後コード

```php
// app/Services/Capture/TakeObjectStorage.php (追加。既存メソッドは無変更)

/**
 * テイク動画本文をローカル一時ファイルへ取得する (サムネイル生成の入力)。
 *
 * 面分類は Bulk (本文転送 = 所要時間がサイズに比例する)。**web 同期経路から呼ばない** —
 * 呼び出し元は media queue の GenerateTakeThumbnailJob だけである。
 * 実装は RenderObjectStorage::downloadToLocal と同型 (readStream → ローカル書き込み)。
 */
public function downloadToLocal(string $path, string $localPath): void
{
    $stream = Storage::disk('s3')->readStream($path);
    if ($stream === null) {
        throw new RuntimeException("S3 オブジェクトを読めません: {$path}");
    }

    $local = fopen($localPath, 'wb');
    if ($local === false) {
        throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
    }

    try {
        if (stream_copy_to_stream($stream, $local) === false) {
            throw new RuntimeException("S3 オブジェクトのコピーに失敗しました: {$path}");
        }
    } finally {
        fclose($local);
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}

/**
 * サーバ生成物 (サムネイル) を S3 へ PUT する。
 *
 * ★ `ContentType` を必ず指定する。指定しないと S3 が既定の binary/octet-stream を返し、
 *   署名 GET へリダイレクトした先で `<img>` が描画できない
 *   (`ContentType` は Flysystem AwsS3V3Adapter の受理オプションに含まれる)。
 * 面分類は Bulk (本文転送)。**web 同期経路から呼ばない**。
 */
public function upload(string $localPath, string $path, string $contentType): void
{
    $stream = fopen($localPath, 'rb');
    if ($stream === false) {
        throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
    }

    try {
        Storage::disk('s3')->writeStream($path, $stream, ['ContentType' => $contentType]);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}

/**
 * サムネイル表示用の署名 GET URL (TTL は動画再生と同じ capture.playback_url_ttl_minutes)。
 *
 * ★ `temporaryPlaybackUrl()` を流用しない。中身は同じ署名 URL 生成だが、
 *   "playback" (再生) の語を静止画へ広げると public API の名前が実体と食い違う。
 */
public function temporaryThumbnailUrl(string $path): string
{
    return Storage::disk('s3')->temporaryUrl(
        $path,
        now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
    );
}
```

```php
// app/Services/Capture/Fakes/FakeTakeObjectStorage.php (追加)

public function downloadToLocal(string $path, string $localPath): void
{
    // s3_fake disk 上の実体をローカルへコピーする (親と同じ readStream 経路を fake disk で通す)
    $stream = Storage::disk(FakeObjectStore::DISK)->readStream($path);
    if ($stream === null) {
        throw new RuntimeException("fake storage にオブジェクトがありません: {$path}");
    }
    // ... 親と同型のコピー処理
}

public function upload(string $localPath, string $path, string $contentType): void
{
    $stream = fopen($localPath, 'rb');
    if ($stream === false) {
        throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
    }
    try {
        // sidecar (content_type) を必ず書く = fake の GET 配信 contract を満たす
        $this->store->putStreamWithMeta($path, $stream, $contentType);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}

public function temporaryThumbnailUrl(string $path): string
{
    return URL::temporarySignedRoute(
        'bughunt.storage.get',
        now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
        ['key' => $path],
    );
}
```

```php
// tests/Support/Storage/S3SurfaceInventory.php (TakeObjectStorage の配列へ 3 件追加)
'downloadToLocal' => [
    'surface' => S3OperationSurface::Bulk,
    'rationale' => '本文転送であり所要時間がオブジェクトサイズに比例して伸びるサムネイル生成専用の取得',
],
'upload' => [
    'surface' => S3OperationSurface::Bulk,
    'rationale' => '本文転送でありサムネイル生成ジョブ専用の PUT で web 同期経路からは呼ばない',
],
'temporaryThumbnailUrl' => [
    'surface' => S3OperationSurface::NoObjectRequest,
    'rationale' => '署名 URL の文字列生成のみでオブジェクト API をまったく送らない',
],
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`void` / `string`)
- [x] null 安全: `readStream()` の `null` と `fopen()` の `false` を明示的に分岐している
- [x] DTO を返している (該当なし。副作用メソッドと文字列生成)
- [x] Generics: 該当なし

### テスト計画

- [ ] 新規: `tests/Feature/Capture/TakeObjectStorageThumbnailTest.php`
      — `Storage::fake('s3')` 上で `upload()` → `downloadToLocal()` の往復が同一バイト列になること
- [ ] 新規: `upload() が ContentType を付けて書く` — fake adapter (`FakeObjectStore` の sidecar) の
      `content_type` が `image/jpeg` であること
      - **保証範囲 (誇張しない)**: `Storage::fake('s3')` はローカル disk であり、
        `writeStream()` の option を metadata として保持しない (`mimeType()` は拡張子から導出される)。
        よってテストで固定できるのは **fake adapter の sidecar** までであり、
        **実 S3 の応答ヘッダに `Content-Type` が載ることは本タスクのテストでは保証しない**
        (option 名が Flysystem AwsS3V3Adapter の受理オプションに含まれることのコード読解までが根拠)
- [ ] 新規: `temporaryThumbnailUrl は capture.playback_url_ttl_minutes の期限を持つ`
- [ ] 既存: `tests/Architecture/ExternalClientTimeoutInventoryTest.php` (目録更新で緑)
- [ ] 既存: `tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php` (登録経路が新 Bulk を呼ばない)

### リスク

- `FakeTakeObjectStorage` の override 漏れがあると bug-hunt / testing 環境で**実 S3 へ出る**。
  → `client()` が fail-loud なので presign 系は落ちるが、`Storage::disk('s3')` 直呼びの
  `downloadToLocal` / `upload` は落ちない。**override 3 件を必ず入れる**こと。
  ExternalFakeDeclaration の risk 文言が指摘するとおり、abstract が具象クラスであるため
  bind が外れると無音で実 S3 を叩く。

---

## S3: ffmpeg 抽出の差し替え可能な境界

### 変更箇所

- 新規: `app/Services/Capture/TakeThumbnailExtractor.php` (interface)
- 新規: `app/Services/Capture/FfmpegTakeThumbnailExtractor.php`
- 新規: `app/Exceptions/Capture/TakeThumbnailExtractionException.php`
- `config/capture.php` (末尾へ 4 キー追加)
- `app/Providers/AppServiceProvider.php` (L119 の `VideoComposer` bind の隣)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規 Unit テスト (Process::fake)。Feature テストは container swap で fake を注入する

### 現行コード

```php
// app/Providers/AppServiceProvider.php L118-119
// 動画合成の抽象 (doc/09 §9.7)。v1 は ffmpeg 実装。テストは fake 実装へ swap する
$this->app->bind(VideoComposer::class, FfmpegVideoComposer::class);
```

```php
// app/Services/Render/FfmpegVideoComposer.php L128-130 (参考にする既存の静止画抽出)
// 先頭フレームの静止画化
$frameFile = "frame{$index}.png";
$this->runFfmpeg($workDir, ['-y', '-i', $source, '-frames:v', '1', $frameFile], 'still frame extract');
```

### 変更後コード

```php
// config/capture.php (末尾へ追加)

// サムネイル生成 (テイク登録後に media queue の GenerateTakeThumbnailJob が 1 フレーム抽出する)
// 抽出位置。0 だと黒画面になりやすいので既定 1 秒。尺が足りなければ実装が 0 で 1 回だけ再試行する
'thumbnail_seek_ms' => 1000,
// 出力の長辺上限 (両辺に効く。巨大入力から巨大 JPEG を作らない)
'thumbnail_max_edge' => 640,
// JPEG 品質 (ffmpeg -q:v。小さいほど高品質・大きいほど低容量)
'thumbnail_jpeg_quality' => 5,
// ffmpeg 1 回の実行上限 (秒)。ジョブの $timeout=180 より十分短く取る
'thumbnail_ffmpeg_timeout_seconds' => 60,
```

```php
// app/Services/Capture/TakeThumbnailExtractor.php (新規)
<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Exceptions\Capture\TakeThumbnailExtractionException;

/**
 * テイク動画から表示用サムネイル (JPEG) を 1 枚作る抽象 (v1 は ffmpeg 実装)。
 *
 * `Render\VideoComposer` と同じ作法で interface に切る = テストは実バイナリに依存せず
 * container swap で fake を注入できる (AppServiceProvider が本番実装を bind する)。
 */
interface TakeThumbnailExtractor
{
    /**
     * @param  string  $localVideoPath  ローカルへ落とした動画 (サーバ生成のパス)
     * @param  string  $localThumbnailPath  出力先 (サーバ生成のパス)
     *
     * @throws TakeThumbnailExtractionException 抽出できなかった場合
     */
    public function extract(string $localVideoPath, string $localThumbnailPath): void;
}
```

```php
// app/Services/Capture/FfmpegTakeThumbnailExtractor.php (新規)
<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Exceptions\Capture\TakeThumbnailExtractionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * ffmpeg による 1 フレーム抽出 (実行は Process facade 経由。テストは Process::fake())。
 *
 * 安全境界 (入力は**利用者がアップロードした動画**である):
 * - 引数は配列で渡す (シェル連結なし)。入力・出力ともサーバ生成のパスだけで、
 *   利用者由来の文字列は 1 つも引数に入らない
 * - `-nostdin` で標準入力待ちに落ちない / `Process::timeout()` で実行を有界にする
 * - **`-protocol_whitelist file`** を明示し、細工されたファイルが外部参照を含む形式として
 *   probe された場合でもローカルファイル以外へ到達しないようにする
 *   (**観測事実**: 既存の `Render\FfmpegVideoComposer` はこの指定を持たない。入力の素性は同じだが、
 *   新設する側を弱い方へ揃える理由はないため本実装には付ける。既存側の後追いは別タスク)
 * - 出力寸法・品質は config 固定 = 巨大入力から巨大 JPEG を作らない。
 *   同一入力・同一バイナリなら出力は決定的である (容量計上の前提)
 */
final class FfmpegTakeThumbnailExtractor implements TakeThumbnailExtractor
{
    public function extract(string $localVideoPath, string $localThumbnailPath): void
    {
        $seekMs = config()->integer('capture.thumbnail_seek_ms');

        $failure = $this->attempt($localVideoPath, $localThumbnailPath, $seekMs);
        if ($failure !== null && $seekMs > 0) {
            // 尺が seek より短いと 1 フレームも出力されない。先頭で 1 回だけ再試行する
            // (これ以上の探索はしない = 尺の推定に ffprobe を足さない)
            $failure = $this->attempt($localVideoPath, $localThumbnailPath, 0);
        }
        if ($failure !== null) {
            throw new TakeThumbnailExtractionException($failure);
        }
    }

    /** @return string|null 失敗理由 (null = 成功) */
    private function attempt(string $source, string $destination, int $seekMs): ?string
    {
        // ★ 実行の**前**に出力先を消す。`-y` は「既存があれば上書きしてよい」という許可であって、
        //   ffmpeg が必ず書き直すことの保証ではない。1 回目が非 0 終了しつつ非空ファイルを残し、
        //   2 回目が終了コード 0 のまま新しいフレームを出さない場合、下の実体検査が
        //   **1 回目の残骸を成功と誤認する**。削除できないこと自体も失敗として扱う。
        // ★ 素の `unlink()` を使わない — 失敗時に E_WARNING を出し、Laravel のエラーハンドラが
        //   `ErrorException` へ変換する環境では下の `return` へ到達せず、
        //   `TakeThumbnailExtractionException` への集約という契約から外れる。
        //   `File::delete()` + 存在確認なら、判定が戻り値だけで閉じる。
        if (is_file($destination)) {
            File::delete($destination);
            if (is_file($destination)) {
                return "failed to remove stale thumbnail output: {$destination}";
            }
        }

        $edge = config()->integer('capture.thumbnail_max_edge');
        $result = Process::timeout(config()->integer('capture.thumbnail_ffmpeg_timeout_seconds'))
            ->run([
                config()->string('manual.render_ffmpeg_binary'),
                '-nostdin', '-y',
                '-protocol_whitelist', 'file',
                '-ss', sprintf('%.3f', $seekMs / 1000),
                '-i', $source,
                '-frames:v', '1',
                '-vf', "scale={$edge}:{$edge}:force_original_aspect_ratio=decrease",
                '-q:v', (string) config()->integer('capture.thumbnail_jpeg_quality'),
                '-f', 'image2',
                $destination,
            ]);

        if (! $result->successful()) {
            return 'ffmpeg failed (thumbnail): '.mb_substr($result->errorOutput(), 0, 2000);
        }

        // 非 0 終了しないまま 0 バイトを吐く場合がある (seek が尺を超えたとき) ため実体を検査する
        $size = is_file($destination) ? filesize($destination) : false;
        if ($size === false || $size === 0) {
            return "ffmpeg produced no frame (seek={$seekMs}ms)";
        }

        return null;
    }
}
```

```php
// app/Exceptions/Capture/TakeThumbnailExtractionException.php (新規)
final class TakeThumbnailExtractionException extends RuntimeException {}
```

```php
// app/Providers/AppServiceProvider.php (L119 の直後へ追加)
// テイクのサムネイル抽出の抽象。v1 は ffmpeg 実装。テストは fake 実装へ swap する
$this->app->bind(TakeThumbnailExtractor::class, FfmpegTakeThumbnailExtractor::class);
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`void` / `?string`)
- [x] null 安全: `filesize()` の `false` を明示分岐 (`is_file()` 先行で warning も出さない)
- [x] DTO を返している (該当なし。失敗理由の内部表現は `?string` に閉じ、外へは例外で出る)
- [x] Generics: 該当なし
- [x] `config()->integer()` / `config()->string()` のみ使用 (生の `config()` 呼び出しを増やさない)

### テスト計画

- [ ] 新規 Unit: `tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php` (`Process::fake()`)
  - コマンド構造: `-protocol_whitelist file` / `-nostdin` / `-frames:v 1` /
    `scale={edge}:{edge}:force_original_aspect_ratio=decrease` が含まれること
  - 引数に**利用者由来の文字列が現れない**こと (渡したパス以外に外部入力が無いことの確認)
  - `-ss` が `capture.thumbnail_seek_ms` を秒へ変換した値であること
  - 1 回目が 0 バイト出力 → **seek=0 で 1 回だけ再試行**し、2 回目が成功なら例外を投げないこと
  - 2 回とも失敗 → `TakeThumbnailExtractionException` (メッセージに stderr の先頭が入る)
  - **残骸の誤認**: 1 回目が非 0 終了しつつ**非空ファイルを残し**、2 回目が終了コード 0 のまま
    新しい出力を作らない場合に**例外になる** (実行前削除が効いていることの回帰。
    これが無いと 1 回目の残骸を成功と誤認して壊れたサムネイルを PUT する)
  - **出力先を削除できない場合も失敗として扱われる**こと。
    テストは **OS の権限に依存させない** — `File` facade を差し替え (`File::shouldReceive('delete')`
    等) て「削除が効かなかった」状況を決定的に作る (`--parallel` でも再現性が壊れない)。
    素の `unlink()` を使わない理由 (警告の例外化で契約から外れる) もテストのコメントに残す
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (Unit は DB に触れない)

### リスク

- **実バイナリの挙動差**: `-frames:v 1` + `-f image2` の組み合わせは ffmpeg のバージョンで
  出力の有無が変わりうる。Unit テストは `Process::fake()` のためこれを検出しない。
  → 実バイナリでの通し確認は bug-hunt の `pipeline-smoke` (別基盤) の領域であり、
  本タスクでは**保証しない**と明記する。
- `-protocol_whitelist file` が既存 render 経路と非対称になる (既知・意図的)。

---

## S4: 生成パイプラインと queue job

### 変更箇所

- 新規: `app/Services/Capture/TakeThumbnailPipeline.php`
- 新規: `app/Jobs/Capture/GenerateTakeThumbnailJob.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/JobExecutionDedupInventoryTest.php` /
  `tests/Architecture/QueuedJobLeaseInventoryTest.php` / `tests/Support/Security/DirectFetchInventory.php`
  (S6 で登録) + 新規 Feature テスト

### 現行コード

```php
// app/Jobs/Capture/DeleteTakeObjectsJob.php L18-45 (同じ media queue の既存ジョブ = 形の見本)
class DeleteTakeObjectsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> 再試行間隔 (秒) */
    public array $backoff = [60, 180];

    public function __construct(public readonly array $paths)
    {
        $this->onConnection('database-media');
    }
    // ...
}
```

```php
// app/Services/Manual/RenderPipeline.php L98-107 (preflight の配置の見本)
// ★ preflight suppression (裁定 AG-082 標準形 (2)): S3 PUT の直前で所有権を再検証する。
$this->assertStillOwned($job, RenderStep::Concat);

// upload → finalize (terminal tx)
$this->storage->upload($composed->localPath, $manifest->outputKey);
```

### 変更後コード

```php
// app/Jobs/Capture/GenerateTakeThumbnailJob.php (新規)
<?php

declare(strict_types=1);

namespace App\Jobs\Capture;

use App\Services\Capture\TakeThumbnailPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * テイクのサムネイル生成 (薄い殻。本体は TakeThumbnailPipeline)。
 *
 * - payload は takeId のみ (モデル/org 値を payload に持たない = payload 不信任)
 * - media queue (`database-media`。queue=media / retry_after=300) で流す。
 *   運用契約: 本番/ステージングは `php artisan queue:work database-media --timeout=240` を
 *   worker 定義に必須登録 (docs/architecture.md §撮影 PWA。既存の削除ジョブと同じ worker)
 * - 時間予算の連鎖: ffmpeg 60 < $timeout 180 < worker --timeout 240 < retry_after 300
 * - **失敗しても take は ready のまま**である (サムネイルは採用・レンダの必須条件ではない)。
 *   最終失敗は failed_jobs に残るだけで、UI はプレースホルダへ degrade する
 */
class GenerateTakeThumbnailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** S3 / ffmpeg の一過性障害を吸収する (生成は冪等なので再試行して安全) */
    public int $tries = 3;

    /** @var list<int> 再試行間隔 (秒) */
    public array $backoff = [60, 180];

    /** worker の --timeout=240 より短く取り、強制終了より先に自前の finally へ入る余地を残す */
    public int $timeout = 180;

    public function __construct(public readonly int $takeId)
    {
        $this->onConnection('database-media');
    }

    public function handle(TakeThumbnailPipeline $pipeline): void
    {
        $pipeline->run($this->takeId);
    }
}
```

```php
// app/Services/Capture/TakeThumbnailPipeline.php (新規)
<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Enums\Manual\TakeStatus;
use App\Enums\Security\ExternalCallKind;
use App\Models\Take;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * サムネイル生成パイプライン (S3 GET → ffmpeg → S3 PUT → 条件付き UPDATE)。
 *
 * **結果の一回性** (AGENTS.md ドメイン固有規約 6):
 * - 保証側の機構は**条件付き UPDATE** (`where status=ready and thumbnail_path is null`)。
 *   0 行更新なら後続を行わない (= 先着したワーカーの結果を壊さない)
 * - 取り消せない外部副作用 (S3 PUT) の**直前**に所有権の再検証 (`stillEligible`) を置く。
 *   検証と PUT の間に自前の書き込みを挟まない
 * - S3 キーは take の主キーから**決定的**に組む。重複配送された 2 つのワーカーは
 *   同じキーへ同じ意味の PUT をするだけなので、敗者が勝者のオブジェクトを消す事故が起きない
 *   (= 0 行更新のとき**オブジェクトを削除してはならない**)
 * - work dir は **handle() 実行ごとに一意** (take id + 実行ごとの UUID)。
 *   ローカル作業領域まで決定的にすると、重複配送された 2 つのワーカーが互いの入力・出力を壊す
 *
 * **ロック**: VideoManual 行ロックは取らない。本パイプラインは `cuts` /
 * `video_manuals.status` / `scenario_version` を 1 列も書かず (ドメイン固有規約 1 の対象外)、
 * 単一行の条件付き UPDATE で足りる。バックグラウンドジョブが新しいロック順序の辺を作らない。
 */
class TakeThumbnailPipeline
{
    public function __construct(
        private readonly TakeObjectStorage $storage,
        private readonly TakeThumbnailExtractor $extractor,
    ) {}

    public function run(int $takeId): void
    {
        $take = Take::query()->find($takeId);
        if ($take === null || ! $this->isEligible($take)) {
            return; // 行が消えた / 既に生成済み / ready でない = 正常な no-op (再配送の冪等短絡)
        }

        // ★ 実行ごとに一意な作業領域。take id だけで決定的にすると重複配送で互いを壊す
        $workDir = storage_path("app/capture/thumbnails/{$takeId}/".(string) Str::uuid());
        File::ensureDirectoryExists($workDir);

        try {
            $source = "{$workDir}/source";
            $thumbnail = "{$workDir}/thumbnail.jpg";

            // S3 GET は冪等な読み取り / ffmpeg はローカル CPU = どちらも preflight の対象ではない
            $this->storage->downloadToLocal($take->video_path, $source);
            $this->extractor->extract($source, $thumbnail);

            $size = filesize($thumbnail);
            if ($size === false || $size === 0) {
                return; // extract が成功を返した以上ここには来ない (防御的)
            }

            // ★ S3 キーは preflight の**前**に確定させる。key が使うのは take / cut / manual /
            //   project の識別子だけ (= 行の生存中に変化しない不変値) なので、preflight より前に
            //   組んでも値は変わらない。こうすることで **preflight と PUT の間には
            //   書き込みどころか読み取り (relation の遅延読み込み) も 1 つも無い**状態になる
            $key = $this->thumbnailKeyFor($take);

            // ★ preflight (裁定 AG-082 標準形 (2)): 取り消せない S3 PUT の直前で所有権を再検証する。
            //   ここから PUT までの間に自前の書き込みを挟まない
            if (! $this->stillEligible($take)) {
                return;
            }

            $this->storage->upload($thumbnail, $key, 'image/jpeg');

            // 結果の一回性: preflight と同じ述語を条件へ再掲する。
            // 0 行 = 先着したワーカーか状態変化 → 何もしない (**オブジェクトは消さない**。
            // キーが決定的なので、消すと勝者の実体を壊すことになる)
            Take::query()
                ->whereKey($take->getKey())
                ->where('status', TakeStatus::Ready->value)
                ->whereNull('thumbnail_path')
                ->update([
                    'thumbnail_path' => $key,
                    'thumbnail_size_bytes' => $size,
                ]);
        } finally {
            File::deleteDirectory($workDir); // 自分の作業領域だけを消す (他人のものには触れない)
        }
    }

    /**
     * S3 キー (take の主キーから決定的に組む。文字列加工を一切しない)。
     * cut / manual / project は relation 経由で解決する (payload 不信任)。
     *
     * ★ 材料は**すべて行の生存中に変化しない識別子**である (take が別の cut へ移る経路も、
     *   cut が別の manual へ移る経路も存在しない)。したがって preflight の前に確定させても、
     *   再取得後のスナップショットで組んだ場合と同じ値になる。
     */
    private function thumbnailKeyFor(Take $take): string
    {
        $cut = $take->cut;
        $manual = $cut->videoManual;

        return sprintf(
            'projects/%d/manuals/%d/cuts/%d/takes/thumbnails/%d.jpg',
            $manual->project_id,
            $manual->id,
            $cut->id,
            $take->id,
        );
    }

    /** 生成してよい状態か (ready かつ未生成)。純粋な述語 = 再検証と入口検査で同じ式を使う */
    private function isEligible(Take $take): bool
    {
        return $take->status === TakeStatus::Ready && $take->thumbnail_path === null;
    }

    /**
     * 所有権の再検証 (preflight suppression)。Billing の `AttemptOwnershipPreflight::stillPending()`
     * と**同じ制御方式** (structured return = bool)。Manual の 2 パイプラインが使う
     * `JobOwnershipLostException` は「ジョブ行の JobStatus」を語彙に持つため、
     * ジョブ行を持たない本経路では流用しない (別物の概念を似ているからで統合しない)。
     *
     * @return bool PUT してよいか (false = 所有権喪失 → 呼び出し側が中断する)
     */
    private function stillEligible(Take $take): bool
    {
        // $take は型付き引数 (App\Models\Take) = 解決済みモデル由来の主キー
        $fresh = Take::query()->whereKey($take->getKey())->first();
        if ($fresh !== null && $this->isEligible($fresh)) {
            return true; // アーリーリターン (正常系)
        }

        // Manual / Billing と**同じ必須 7 キー**で観測する (集計語彙を 1 本に保つ)。
        // 本経路固有の追加キーは PII-free な thumbnail_present の 1 本だけ
        Log::warning('サムネイル生成: 所有権を失ったため S3 への書き込みを中止しました', [
            'event' => ExternalCallKind::LOG_EVENT,
            'job_type' => Take::class,
            'job_id' => $take->id,
            'expected_status' => TakeStatus::Ready->value,
            'actual_status' => $fresh?->status->value,
            'stage' => 'thumbnail_upload',
            'external_call' => ExternalCallKind::ObjectStoragePut->value,
            'thumbnail_present' => $fresh?->thumbnail_path !== null,
        ]);

        return false;
    }
}
```

> **`Take::cut` / `Cut::videoManual` relation の実在確認**: `Take::cut()` は既存
> (`app/Models/Take.php` L68)。`Cut` 側の manual relation 名は実装時に確認し、
> 無ければ `$take->cut->video_manual_id` から `VideoManual` を relation 経由で解決する
> (**クラス起点の主キー同一性クエリを増やさない**ため、relation で辿る形を守る)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`void` / `string` / `bool`)
- [x] null 安全: `find()` の null、`filesize()` の false、`$fresh?->status` の null 伝播を明示
- [x] DTO を返している (該当なし。副作用のみ)
- [x] Generics: 該当なし
- [x] `Take::query()->…->update([...])` の戻り値 (int) は使わない (0 行でも同じ動作なので分岐しない)

### テスト計画

- [ ] 新規 Feature: `tests/Feature/Capture/TakeThumbnailGenerationTest.php`
      (`Storage::fake('s3')` + container swap した fake extractor)
  - 成功: `thumbnail_path` が決定的キーになり `thumbnail_size_bytes` が出力サイズと一致する
  - S3 に `image/jpeg` として PUT されている
  - **冪等**: 同じ payload を 2 回実行しても 1 回目の値が保たれ、2 回目は extractor を呼ばない
  - `status != ready` のテイクでは extractor も storage も 1 回も呼ばれない
  - テイク行が削除済みなら no-op (例外を投げない)
  - **preflight の配置**: fake extractor の `$duringExtract` フックで抽出中にテイクを削除 →
    **`upload()` が 1 回も呼ばれない** / `thumbnail_path` が書かれない / 抑止ログが 1 行出る
    (`FakeRenderComposer::$duringCompose` と同じ細工。目録 gate が保証しない「配置」を behavioral に固定する)
  - **抽出中に先着された**: 抽出中に別ワーカーの結果を模して `thumbnail_path` を埋める →
    preflight が検出して **`upload()` が 1 回も呼ばれない** / 先着の値が保たれる
  - **preflight 通過後・UPDATE 前に先着された**: storage fake の `upload()` コールバックで
    `thumbnail_path` を埋める → PUT は行われるが **UPDATE が 0 行**で、
    **先着の値が保たれる** / **オブジェクトが削除されない** (決定的キーのため消してはいけない)
  - **抽出失敗**: extractor が例外 → take は `ready` のまま `thumbnail_path` は null、
    work dir が残らない
  - **work dir**: 実行ごとに異なるディレクトリを使い、正常・異常いずれでも `finally` で削除される
- [ ] 新規 Feature: `tests/Feature/Capture/TakeThumbnailQueueAtomicityTest.php`
      (`TakeDeletionQueueAtomicityTest` と同型。S5 と対)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **孤児オブジェクト**: preflight〜PUT 間、PUT〜UPDATE 間でワーカーが落ちると
  どの列からも参照されないサムネイルが残る (再試行が同じキーへ PUT して列を埋めれば自己修復)。
  回収コマンドは作らない (概念設計「保証しないもの」)。
- **記録バイト数と実オブジェクトのずれ**: 重複配送時、DB には勝者のサイズ、
  S3 には後着の実体が残りうる (数 KB。利用者は制御できない)。

---

## S5: テイク登録の確定 tx からの投入

### 変更箇所

- `app/Services/Capture/TakeRegistrationService.php` (L142-173 の `finalize()`)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Capture/TakeRegistrationTest.php` (投入の有無)、
  新規 `TakeThumbnailQueueAtomicityTest.php` (tx 内投入)

### 現行コード

```php
// app/Services/Capture/TakeRegistrationService.php L161-171
$lockedCut->takes()->increment('sort_order'); // 既存を後ろへ (先頭 = 0。行ロック下で競合なし)
$take = $lockedCut->takes()->make([
    'client_take_id' => $reservation->client_take_id,
    'video_path' => $reservation->video_path,
    'size_bytes' => $reservation->size_bytes,   // 予約 = HeadObject 照合済み確定値
    'duration_ms' => $input->durationMs,        // クライアント申告 (表示用)
    'captured_at' => $input->capturedAt,
]);
$take->forceFill(['status' => TakeStatus::Ready, 'sort_order' => 0])->save();

return TakeRegistrationResult::created($take);
```

### 変更後コード

```php
$lockedCut->takes()->increment('sort_order'); // 既存を後ろへ (先頭 = 0。行ロック下で競合なし)
$take = $lockedCut->takes()->make([
    'client_take_id' => $reservation->client_take_id,
    'video_path' => $reservation->video_path,
    'size_bytes' => $reservation->size_bytes,   // 予約 = HeadObject 照合済み確定値
    'duration_ms' => $input->durationMs,        // クライアント申告 (表示用)
    'captured_at' => $input->capturedAt,
]);
$take->forceFill(['status' => TakeStatus::Ready, 'sort_order' => 0])->save();

// サムネイル生成の投入を**同一 tx 内**で行う (AGENTS.md ドメイン固有規約 11。
// afterCommit に依存しない)。保証するのは「take 行を作ったのに生成 job が投入されない窓」の
// 解消だけである (worker 停止 / ffmpeg 失敗 / S3 失敗ではサムネイルは付かない = 誇張しない)。
// 冪等再送 (resolveDuplicate) では投入しない — 既存テイクは登録時に投入済みである。
GenerateTakeThumbnailJob::dispatch($take->id); // media queue へ

return TakeRegistrationResult::created($take);
```

> docblock の処理順序 6 にも「+ サムネイル生成ジョブの投入」を追記する。

> **「同一 tx 内投入」が成立する前提** (AGENTS.md ドメイン固有規約 11):
> `config/queue.php` の `database-media` は `driver => database` /
> `connection => env('DB_QUEUE_CONNECTION')` (= 業務 DB) / `after_commit => false` である。
> この 3 つ (driver=database / キュー DB 接続 = 業務 DB / after_commit=false / production の
> 既定接続が sync でない) は `App\Support\QueueDispatchAtomicityGuard` が
> **全環境の起動時に fail-closed で検査**しており、本設計はその前提の上に立つ。
> `->afterCommit()` を付けない (付けると `QueueDispatchAtomicityInventoryTest` の 0 件 pin が赤くなる)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (既存の `TakeRegistrationResult` のまま)
- [x] null 安全: `$take->id` は save 済みモデルの主キー (int)
- [x] DTO を返している (`TakeRegistrationResult`)
- [x] Generics: 該当なし

### テスト計画

- [ ] 既存テスト `tests/Feature/Capture/TakeRegistrationTest.php` の更新
  - 新規登録で `GenerateTakeThumbnailJob` が**ちょうど 1 件**投入される (payload = take id)
  - **冪等再送 (200)** では 1 件も投入されない
  - 予約 CAS に負けた場合 (422) は投入されない
- [ ] 新規: `tests/Feature/Capture/TakeThumbnailQueueAtomicityTest.php`
      — `TakeDeletionQueueAtomicityTest` と同型。**実 `jobs` 表**と `JobQueueing` の
      `DB::transactionLevel()` 観測で「action 直前の level + 1 以上」を固定する
      (`Queue::fake()` では原子性を検証できない = AGENTS.md ドメイン固有規約 11)
- [ ] 新規: 登録 tx が rollback したとき `jobs` 表に行が残らないこと
      (**ただし主契約は上の transactionLevel 観測**である。rollback テストは
      dispatch の移設を検出しない = 既存の但し書きと同じ扱いにする)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- 登録 tx が 1 行分重くなる (jobs への INSERT)。既存の削除経路が同じことを tx 内で
  行っており (`CaptureTakeService::delete`)、追加のロックは取らないため影響は小さい。

---

## S6: deny-by-default 目録への登録

### 変更箇所

- `tests/Architecture/JobExecutionDedupInventoryTest.php` (保証側 entry + 期待外部呼び出し map)
- `tests/Architecture/QueuedJobLeaseInventoryTest.php` (`QUEUED_JOB_LEASE_INVENTORY`)
- `tests/Support/Security/DirectFetchInventory.php` (queuePayload entry)
- `.claude/skills/app-bug-hunt/inventory/annotations.toml` + `screens.md` / `operations.md` の再生成

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 目録そのもの (テスト本体のロジックは変更しない)

### 現行コード

```php
// tests/Architecture/JobExecutionDedupInventoryTest.php L78-86
RunManualRender::class => new GuaranteeEntry(
    mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
    preflights: [new PreflightCheckpoint(
        RenderPipeline::class, 'assertStillOwned',
        ExternalCallKind::ObjectStoragePut, PreflightControlFlow::ThrowsOnLoss,
    )],
    rationale: '...',
),
```

```php
// tests/Architecture/QueuedJobLeaseInventoryTest.php L67
DeleteTakeObjectsJob::class => 'database-media',
```

### 変更後コード

```php
// tests/Architecture/JobExecutionDedupInventoryTest.php — jobDedupGuarantees() へ追加
GenerateTakeThumbnailJob::class => new GuaranteeEntry(
    mechanisms: [JobDedupGuarantee::ConditionalStatusUpdate],
    preflights: [new PreflightCheckpoint(
        TakeThumbnailPipeline::class, 'stillEligible',
        ExternalCallKind::ObjectStoragePut, PreflightControlFlow::ReturnsBoolean,
    )],
    rationale: '結果の一回性は where status=ready and thumbnail_path is null の条件付き UPDATE が担う '
        .'(0 行更新なら先着の結果を壊さない)。S3 キーは take の主キーから決定的に組むため、'
        .'重複配送は同じキーへ同じ意味の PUT に収束し、敗者が勝者の実体を消すこともない。'
        .'取り消せない S3 PUT の直前に structured return の preflight を置く。',
),

// jobDedupRequiredExternalCalls() へ追加
GenerateTakeThumbnailJob::class => [ExternalCallKind::ObjectStoragePut],
```

> **免除 cap は変更しない** (`jobDedupExemptionCap()` = 15 / case 別も据え置き)。
> 本ジョブは**保証側**の登録であり免除ではない。

```php
// tests/Architecture/QueuedJobLeaseInventoryTest.php — QUEUED_JOB_LEASE_INVENTORY へ追加
GenerateTakeThumbnailJob::class => 'database-media',
```

> 同テストは「その接続で動くジョブの明示的な `$timeout` が接続の `retry_after` を下回る」ことを
> 検査する。**180 < 300** で規則 2 を満たす。

```php
// tests/Support/Security/DirectFetchInventory.php — queuePayload 群へ追加
'Services/Capture/TakeThumbnailPipeline.php#run#Take.find:$takeId#1' => DirectFetchJustificationEntry::queuePayload(
    'take id はテナント検証済みの登録 tx (TakeRegistrationService::finalize) がサーバ採番した主キーで '
    .'HTTP 入力を経由しない。worker 側は再水和したうえで status / thumbnail_path を検査してから外部へ出る',
    enqueuedBy: 'App\Services\Capture\TakeRegistrationService::finalize',
),
```

> **entry key は実行時のスキャナ出力で確定させる**。`stillEligible()` 内の
> `Take::query()->whereKey($take->getKey())->first()` は「識別子が解決済みモデル由来
> (型付き引数 `Take $take`)」に当たるため provenance フィルタで**候補から落ちる想定**である。
> 目録は exact-fit (stale entry も fail) なので、**落ちないものを先回りで登録しない**。
> 実装時に `composer test -- --filter=ModelDirectFetchInvariant` を先に赤で確認し、
> 失敗メッセージが示す key をそのまま登録する。

```toml
# .claude/skills/app-bug-hunt/inventory/annotations.toml (capture.takes.playback の隣へ)
[routes."capture.takes.thumbnail"]
kind = "画面"
story = "S3"
kubun = "通常"
```

> 追記後に `python3 scripts/bug-hunt-inventory.py generate` で `screens.md` / `operations.md` を
> **再生成**する (表の行を手で書かない。AGENTS.md bug-hunt 節)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (目録は既存の型付き値オブジェクトを返す)
- [x] null 安全: 該当なし
- [x] DTO を返している (`GuaranteeEntry` / `DirectFetchJustificationEntry`)
- [x] Generics: 該当なし
- [x] 根拠はすべて 30 文字以上 (各 gate が機械検査する)

### テスト計画

- [ ] 既存: `JobExecutionDedupInventoryTest` (未分類 fail が消える / 期待種別集合が一致する /
      preflight の戻り型が `bool` = `ReturnsBoolean` と一致する)
- [ ] 既存: `QueuedJobLeaseInventoryTest` (対称差ゼロ / `$timeout` < `retry_after`)
- [ ] 既存: `ModelDirectFetchInvariantTest` (未分類ゼロ / stale ゼロ)
- [ ] 既存: `scripts/bug-hunt-inventory-check.sh` (exit 0)
- [ ] 既存: `QueueDispatchAtomicityInventoryTest` (`afterCommit` 系の 0 件 pin を壊さない)

### リスク

- `PreflightControlFlow::ReturnsBoolean` の期待戻り型は `bool`。メソッド名・戻り型を変えると
  gate が赤くなる (意図どおり)。

---

## S7: サムネイル配信 endpoint

### 変更箇所

- `routes/web.php` (L619-620 の `takes.playback` の隣)
- `app/Http/Controllers/Capture/CaptureTakeController.php` (L155-178 の `playback()` の隣)
- `tests/Support/Routing/NestedRouteDefenseInventory.php` (L66 の隣)

### 波及変更

- TypeScript 型定義: なし (URL はクライアントが組み立てる。S9 参照)
- API Resource/DTO: なし (302 応答。body を持たない)
- テストファイル: 新規 `TakeThumbnailEndpointTest.php` /
  既存 `NestedRouteIdorDefenseTest` `TenantBoundaryOrderingTest` (目録更新で追随) /
  既存 `ControllerAuthorizationGateTest` (**GET は母集団外**のため変更不要) /
  既存 `ThrottleCoverageInventoryTest` (認証済み web の GET は保護対象群に入らないため変更不要)

### 現行コード

```php
// routes/web.php L619-620
Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback', [CaptureTakeController::class, 'playback'])
    ->name('takes.playback');
```

```php
// app/Http/Controllers/Capture/CaptureTakeController.php L155-178
public function playback(
    Request $request, Project $project, VideoManual $manual, Cut $cut, Take $take,
    TakeObjectStorage $storage,
): RedirectResponse {
    $organization = $this->resolveCurrentOrganization($request);
    // URL 整合 guard: 認可より前に 404
    $this->resolveOrganizationProject($organization, $project);
    Gate::authorize('preview', $take);

    if ($take->status !== TakeStatus::Ready) {
        abort(404);
    }

    return redirect()
        ->away($storage->temporaryPlaybackUrl($take->video_path))
        ->withHeaders(['Cache-Control' => 'no-store, private']);
}
```

### 変更後コード

```php
// routes/web.php (scopeBindings group の中。playback の直後へ)
Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail', [CaptureTakeController::class, 'thumbnail'])
    ->name('takes.thumbnail');
```

```php
// app/Http/Controllers/Capture/CaptureTakeController.php (playback の直後へ)

/**
 * テイクのサムネイル表示 (302 → S3 署名 URL)。撮影者/編集者 (capture ability)。
 * doc/04 動画列 / doc/05 撮影後の下部サムネイル確認。
 *
 * 層の順序は playback と同一 (認可より前に 404):
 * 1. {project} ∈ current org (project.in-current-org middleware + resolveOrganizationProject)
 * 2. {manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は Route::scopeBindings()
 * 3. 認可 (preview ability。動画の再生と同じ権限で見せる)
 *
 * 404 にするのは 2 つ: ready でないテイク (内部状態を存在有無として漏らさない) と、
 * **サムネイル未生成** (生成前・生成失敗・過去分)。UI は has_thumbnail で出し分けるため
 * 通常この 404 は起きないが、生成前の取得競合を安全側に倒すために閉じておく。
 *
 * 302 応答は Cache-Control: no-store, private (期限付き署名 URL の再利用防止)。
 * ※ リダイレクト先の画像本体の cache までは保証しない (動画側と同じ扱い)。
 */
public function thumbnail(
    Request $request,
    Project $project,
    VideoManual $manual,
    Cut $cut,
    Take $take,
    TakeObjectStorage $storage,
): RedirectResponse {
    $organization = $this->resolveCurrentOrganization($request);
    // URL 整合 guard: 認可より前に 404
    $this->resolveOrganizationProject($organization, $project);
    Gate::authorize('preview', $take);

    if ($take->status !== TakeStatus::Ready) {
        abort(404);
    }
    $path = $take->thumbnail_path;
    if ($path === null) {
        abort(404); // 未生成 (生成前 / 失敗 / 過去分)
    }

    return redirect()
        ->away($storage->temporaryThumbnailUrl($path))
        ->withHeaders(['Cache-Control' => 'no-store, private']);
}
```

```php
// tests/Support/Routing/NestedRouteDefenseInventory.php (L66 の直後へ)
'capture.takes.thumbnail' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`RedirectResponse`)
- [x] null 安全: `?string $thumbnail_path` を**ローカル変数へ取ってから早期 return** で絞る
      (プロパティのままだと level 10 が narrowing を保持しない)
- [x] DTO を返している (302 応答のため body なし。`response()->json()` は使わない)
- [x] Generics: 該当なし

### テスト計画

- [ ] 新規: `tests/Feature/Capture/TakeThumbnailEndpointTest.php`
      (`TakePlaybackTest.php` を見本にする)
  - 生成済みテイク: 302 + `Location` が署名 URL + `Cache-Control: no-store, private`
  - **未生成 (thumbnail_path=null)**: 404
  - **ready でない** (uploading / processing / failed): 404
  - **cross-org / cross-project / cross-manual / cross-cut**: すべて 404 (認可より前)
  - 権限のないユーザー (preview ability なし): 403 (テナント境界を通った後の層)
  - 未認証: ログインへリダイレクト
- [ ] 既存: `NestedRouteIdorDefenseTest` / `TenantBoundaryOrderingTest` (目録更新で緑)
- [ ] 既存: `CaptureReturnPathTest` など route 数に依存するテストが無いことを確認
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- route が 1 本増えることで bug-hunt 目録のドリフト検査が赤くなる → S6 で注釈追加 + 再生成する。

---

## S8: `has_thumbnail` の DTO / Resource / TS 型への追加

### 変更箇所

- `app/DataTransferObjects/Capture/CaptureTakeData.php` (L29-49)
- `app/Http/Resources/Capture/CaptureTakeResource.php` (L21-29 の array shape docblock)
- `resources/js/types/capture.ts` (L8-22)

### 波及変更

- TypeScript 型定義: `CaptureTake` に `has_thumbnail: boolean` (**必須プロパティ**)
- API Resource/DTO: `CaptureTakeData::toArray()` の shape と、DTO / Resource **両方**の
  array-shape docblock
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` (L185-189 のキー集合)、
  `tests/js/components/features/capture/TakeStrip.test.ts` (`makeTake()` の既定値)

### 現行コード

```php
// app/DataTransferObjects/Capture/CaptureTakeData.php L29-49
/**
 * @return array{id: int, client_take_id: string, status: string, size_bytes: int,
 *   duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
 *   downloaded: bool, playback_url: string|null, download_ack_token: string|null}
 */
public function toArray(): array
{
    return [
        // ...
        'downloaded' => $this->take->downloaded_at !== null,
        'playback_url' => $this->playbackUrl,
        'download_ack_token' => $this->downloadAckToken,
    ];
}
```

```ts
// resources/js/types/capture.ts L8-22
export interface CaptureTake {
    // ...
    downloaded: boolean;
    /** 採用テイクのみ非 null (doc/10 §10.3) */
    playback_url: string | null;
    /** 採用テイクのみ非 null。DL 完了時に POST .../downloaded へ送る署名 ACK トークン (D6) */
    download_ack_token: string | null;
}
```

### 変更後コード

```php
// app/DataTransferObjects/Capture/CaptureTakeData.php
/**
 * @return array{id: int, client_take_id: string, status: string, size_bytes: int,
 *   duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
 *   downloaded: bool, has_thumbnail: bool, playback_url: string|null,
 *   download_ack_token: string|null}
 */
public function toArray(): array
{
    return [
        // ...
        'downloaded' => $this->take->downloaded_at !== null,
        // サムネイルの**有無だけ**を出す (パスも署名 URL も出さない)。UI はこの 1 つで
        // <img> とプレースホルダを出し分け、未生成テイクへ 404 のリクエストを出さない。
        // ★ 述語は **GET .../thumbnail が 302 を返す条件と 1 対 1** にする
        //   (ready でないテイクで true を返すと、必ず 404 になる <img> を描画してしまう)。
        //   T154 の「秘匿境界は props 側」「props と endpoint は 1 対 1」と同じ作法
        'has_thumbnail' => $this->take->status === TakeStatus::Ready
            && $this->take->thumbnail_path !== null,
        'playback_url' => $this->playbackUrl,
        'download_ack_token' => $this->downloadAckToken,
    ];
}
```

```php
// app/Http/Resources/Capture/CaptureTakeResource.php — toArray() の array shape docblock も同じ形へ更新
```

```ts
// resources/js/types/capture.ts
export interface CaptureTake {
    // ...
    downloaded: boolean;
    /** サムネイルが生成済みか。true のときだけ GET .../takes/{id}/thumbnail を表示に使う */
    has_thumbnail: boolean;
    playback_url: string | null;
    download_ack_token: string | null;
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (array shape を DTO / Resource の**両方**で更新する)
- [x] null 安全: `thumbnail_path !== null` の真偽値化のみ
- [x] DTO を返している (`CaptureTakeData` が shape の一元管理点。Resource は委譲するだけ)
- [x] Generics: 該当なし

### テスト計画

- [ ] 既存テスト `tests/Feature/Capture/CaptureManualBrowsingTest.php` の更新
      (キー集合の `toBe([...])` に `has_thumbnail` を**順序どおり**追加)
- [ ] 新規: 生成済み + ready で `has_thumbnail === true` / 未生成で `false` になること
- [ ] 新規: **`thumbnail_path` はあるが ready でない**テイクで `false` になること
      (props と endpoint の 302 条件が 1 対 1 であることの固定)
- [ ] 既存: `tests/js/.../TakeStrip.test.ts` の `makeTake()` に既定値 `has_thumbnail: false` を追加
      (型が必須のため、追加しないと `pnpm typecheck` が赤になる = 波及の検出点)

### リスク

- キー順序を固定しているテストがあるため、挿入位置を誤ると赤になる (意図どおりの検出)。

---

## S9: テイク一覧のサムネイル表示とプレースホルダ

### 変更箇所

- `resources/js/components/features/capture/TakeStrip.svelte` (L1-9 の import / L192-252 の行描画)

### 波及変更

- TypeScript 型定義: S8 の `has_thumbnail` に依存
- API Resource/DTO: なし
- テストファイル: `tests/js/components/features/capture/TakeStrip.test.ts`

### 現行コード

```svelte
<!-- resources/js/components/features/capture/TakeStrip.svelte L192-221 -->
{#each cut.takes as take, index (take.id)}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-2 rounded-md border border-border bg-surface px-3 py-2 sm:flex-nowrap {take.downloaded ? 'border-border-strong' : ''}"
        data-testid={`take-item-${take.id}`}>
        <div class="flex shrink-0 flex-col gap-1">
            <Button variant="ghost" size="sm" iconOnly ariaLabel="上へ" ... >
```

### 変更後コード

```svelte
<script lang="ts">
    import { Check, ChevronDown, ChevronUp, Download, Film, Pencil, Play, Trash2 } from "@lucide/svelte";
    // ...
</script>

{#each cut.takes as take, index (take.id)}
    <div class="..." data-testid={`take-item-${take.id}`}>
        <!--
          サムネイル (doc/04 動画列 / doc/05 撮影後の確認)。
          生成は非同期なので、録画直後・生成失敗・過去分のテイクは has_thumbnail=false になる。
          その場合は同じ寸法のプレースホルダを出し、枠の大きさを変えない
          (生成完了後の再取得で同じ枠が画像へ置き換わる = レイアウトが跳ねない)。
          画像は装飾 (行に「テイク N」の見出しが既にある) なので alt="" にする。
        -->
        {#if take.has_thumbnail}
            <img
                src={takeUrl(take, "/thumbnail")}
                alt=""
                loading="lazy"
                decoding="async"
                class="size-12 shrink-0 rounded-md border border-border object-cover"
                data-testid={`take-thumbnail-${take.id}`}
            />
        {:else}
            <div
                class="flex size-12 shrink-0 items-center justify-center rounded-md border border-border bg-neutral"
                data-testid={`take-thumbnail-placeholder-${take.id}`}
                aria-hidden="true"
            >
                <Film class="size-4 text-text-secondary" aria-hidden="true" />
            </div>
        {/if}
        <div class="flex shrink-0 flex-col gap-1">
            <!-- 既存の上下ボタン -->
```

> **DS / Atomic Design 準拠**: 色・角丸はすべて DS token (`border-border` / `bg-neutral` /
> `text-text-secondary` / `rounded-md`)。hex 直書きなし。アイコンは `@lucide/svelte` の `Film` のみ
> (SVG 直書きなし)。**専用 atom は作らない** — 使用箇所が 1 つだけで、
> 「あったら便利」な部品を先回りで作らない (AGENTS.md 思考原則 2)。
> 使用箇所が PC 面 (doc/04) にも増えた時点で atom へ引き上げる。

### PHPStan適合チェック

- 該当なし (フロントエンド)。`pnpm typecheck` / `pnpm lint` / ds-purity テストが検査する。

### テスト計画

- [ ] 既存テスト `tests/js/components/features/capture/TakeStrip.test.ts` の更新
- [ ] 新規: `has_thumbnail=false` のとき**プレースホルダ**が出て `<img>` が出ないこと
      (未生成テイクへ画像リクエストを出さないことの回帰)
- [ ] 新規: `has_thumbnail=true` のとき `<img>` の `src` が
      `/app/projects/{p}/manuals/{m}/cuts/{c}/takes/{t}/thumbnail` であること
- [ ] 新規: props が `false → true` に変わると**同じ take の枠が画像へ置き換わる**こと
      (S10 の受入条件と対。rerender で確認する)
- [ ] 既存: ds-purity / atomic-import-graph / svg-inline-allowlist が緑

### リスク

- 行の高さが増えて 1 画面あたりのテイク表示数が減る。48px は既存のボタン行 (2 段) と
  同程度のため実質増えない見込みだが、狭幅の実レイアウトは Vitest では見ない (jsdom)。

---

## S10: 撮影画面内の有界な自動反映

### 変更箇所

- 新規: `resources/js/lib/capture/thumbnail-refresh.ts`
- `resources/js/pages/Capture/Show.svelte` (L89-91 の `reloadManual` / L145-190 の呼び出し元 / `onMount`)

### 波及変更

- TypeScript 型定義: `CaptureManualDetail` / `CaptureTake` を読むだけ (S8 に依存)
- API Resource/DTO: **なし** (新しい endpoint も部分 props も足さない。既存の
  `router.reload({ only: ["manual"] })` をそのまま使う)
- テストファイル: 新規 `tests/js/lib/capture/thumbnail-refresh.test.ts`

### 現行コード

```svelte
<!-- resources/js/pages/Capture/Show.svelte L89-91 -->
function reloadManual(): void {
    router.reload({ only: ["manual"] });
}
```

```svelte
<!-- L145-162 (呼び出し元の 1 つ) -->
if (outcome.status === "uploaded") {
    reloadManual();
}
```

### 変更後コード

```ts
// resources/js/lib/capture/thumbnail-refresh.ts (新規)
import type { CaptureManualDetail } from "@/types/capture";

/**
 * サムネイル生成の完了を撮影画面へ反映するための**有界な**再取得スケジューラ。
 *
 * サムネイルはテイク登録の応答より後に出来るため、登録直後の has_thumbnail は必ず false になる。
 * 放置すると画面を離れて戻るまで反映されない (doc/05 の撮影後確認が成立しない)。
 *
 * 設計上の制約 (無制限ポーリングにしない):
 * - **監視するのはこの端末がこのセッションで登録した client_take_id だけ**。
 *   画面差分で現れた ID は追わない = 別端末が同じマニュアルを撮っていても巻き込まれず、
 *   **サムネイルを持たない過去分のテイクで再取得が走ることもない**
 * - 停止条件は 4 つ: 監視集合が空 / 試行上限 / 画面が非表示 / stop()
 * - 再取得中は次の再取得を始めない (single-flight。呼び出し側の reload も同じ 1 本を通す)
 *
 * **有界性の単位 (誇張しない)**: 試行予算は集合全体で 1 本持ち、**新しいテイクを watch した時点で
 * リセットされる** (新しい録画には新しい予算を与えるのが意図)。したがって
 * 「画面全体で最大 4 回」ではなく「**最後に監視集合へ追加されたテイクを起点に最大 4 回 (~29 秒)**」が
 * 保証の単位である (既に監視中の ID を再度 watch しても予算は戻らない = 早期 return する。
 * キュー再開で複数件を連続追加した場合は、**最後に追加された ID** を起点に集合全体の予算が更新される)。
 * 撮影を続ける限り予算は更新され続けるが、撮影を止めれば必ず 4 回で停止する。
 */
const INTERVALS_MS = [2_000, 4_000, 8_000, 15_000] as const;

export class ThumbnailRefreshScheduler {
    private readonly watched = new Set<string>();
    private attempt = 0;
    private timer: ReturnType<typeof setTimeout> | null = null;
    private running = false;
    private paused = false;
    private stopped = false;

    /** @param reload 画面側の single-flight な再取得 (完了で解決する Promise を返す) */
    constructor(private readonly reload: () => Promise<void>) {}

    /** この端末が登録に成功したテイクを監視対象へ merge する (既存集合は消さない) */
    watch(clientTakeId: string): void {
        if (this.stopped || this.watched.has(clientTakeId)) return;
        this.watched.add(clientTakeId);
        this.attempt = 0; // 新しい録画には新しい試行予算を与える
        this.schedule();
    }

    /** 最新の manual で監視集合を更新する (完了後の最新スナップショットだけで判断する) */
    sync(manual: CaptureManualDetail): void {
        if (this.stopped) return;
        for (const id of [...this.watched]) {
            const take = manual.cuts.flatMap((cut) => cut.takes).find((t) => t.client_take_id === id);
            // 見つからない (削除された) / サムネイルが付いた → 監視終了
            if (take === undefined || take.has_thumbnail) this.watched.delete(id);
        }
        if (this.watched.size === 0) {
            this.clearTimer();
            this.attempt = 0;
            return;
        }
        this.schedule();
    }

    pause(): void { this.paused = true; this.clearTimer(); }
    resume(): void { this.paused = false; this.schedule(); }
    stop(): void { this.stopped = true; this.clearTimer(); this.watched.clear(); }

    private schedule(): void {
        if (this.stopped || this.paused || this.running || this.timer !== null) return;
        if (this.watched.size === 0 || this.attempt >= INTERVALS_MS.length) return;

        const delay = INTERVALS_MS[this.attempt];
        this.attempt += 1;
        this.timer = setTimeout(() => {
            this.timer = null;
            void this.run();
        }, delay);
    }

    private async run(): Promise<void> {
        if (this.stopped || this.paused) return;
        this.running = true;
        try {
            await this.reload();
        } catch {
            // 失敗しても監視対象は消さない (残りの試行へ進む)
        } finally {
            this.running = false;
        }
        // 停止・unmount 後に到着した完了処理は状態を変更しない
        if (!this.stopped) this.schedule();
    }

    private clearTimer(): void {
        if (this.timer === null) return;
        clearTimeout(this.timer);
        this.timer = null;
    }
}
```

```svelte
<!-- resources/js/pages/Capture/Show.svelte -->
<script lang="ts">
    import { ThumbnailRefreshScheduler } from "@/lib/capture/thumbnail-refresh";

    /* ---- manual 再取得は single-flight ----
     * アップロード成功 / キュー再開 / 自動 DL / サムネイル反映の 4 経路が同じ 1 本を通る。
     * 直列化しないと、古い応答での上書きと監視集合の判定ずれが起きる。 */
    // ★ in-flight の Promise を**保持して返す**。即解決する Promise を返すと、
    //   scheduler が「再取得が終わった」と誤認して古い manual のまま次の試行を消費する。
    let inFlight: Promise<void> | null = null;
    function reloadManual(): Promise<void> {
        if (inFlight !== null) return inFlight; // 並行呼び出しには同じ Promise を返す
        inFlight = new Promise<void>((resolve) => {
            router.reload({
                only: ["manual"],
                // onFinish は成功・失敗・キャンセルのいずれでも呼ばれる契約に依存している
                onFinish: () => {
                    inFlight = null;
                    resolve();
                },
            });
        });

        return inFlight;
    }

    const thumbnails = new ThumbnailRefreshScheduler(reloadManual);

    // reload 後の最新 manual だけで監視集合を更新する
    $effect(() => {
        thumbnails.sync(manual);
    });

    // handleCaptured (単発) の成功時
    if (outcome.status === "uploaded") {
        thumbnails.watch(outcome.clientTakeId); // この端末が登録したテイクだけを監視する
        void reloadManual();
    }

    // resumeUploads (オフラインキューの再開。**複数件**が一度に確定しうる) の成功時。
    // ★ 現行は some() で「1 件でも uploaded なら reload」だけを行っている。そのままだと
    //   キュー経由で登録されたテイクが 1 つも watch されず、最初の reload 時点で未生成なら
    //   以後まったく反映されない (= オフライン撮影の主経路が取り残される)。
    const uploaded = outcomes.filter(
        (outcome): outcome is Extract<UploadOutcome, { status: "uploaded" }> =>
            outcome.status === "uploaded",
    );
    for (const outcome of uploaded) {
        thumbnails.watch(outcome.clientTakeId);
    }
    if (uploaded.length > 0) {
        void reloadManual(); // 件数によらず 1 回だけ (single-flight とも整合する)
    }

    // 既存の handleVisibility へ pause/resume を足す
    // onMount の cleanup で thumbnails.stop()
</script>
```

> `UploadOutcome` は `{ status: "uploaded"; clientTakeId: string }` (既存) なので
> **受け渡しの追加実装は不要**。`CaptureTake.client_take_id` は既に props に含まれており、
> サーバ側 take id を持ち回る必要がない。

### PHPStan適合チェック

- 該当なし (フロントエンド)。`pnpm typecheck` / `pnpm lint` / `pnpm test` が検査する。

### テスト計画

- [ ] 新規: `tests/js/lib/capture/thumbnail-refresh.test.ts` (`vi.useFakeTimers()`)
  - `watch()` していないとき、has_thumbnail=false のテイクがあっても**再取得しない**
    (過去分テイクで無駄なポーリングをしない回帰)
  - `watch()` 後、2s → 4s → 8s → 15s の計 4 回で止まる (**試行上限**)
  - サムネイルが付いたら監視集合から外れ、空になったら再取得を止める
  - 監視中のテイクが manual から消えた (削除された) 場合も外れる
  - **merge**: 2 本目の `watch()` が 1 本目の監視を消さない / 試行予算がリセットされる
  - **有界性の単位**: 3 回発火したあとに**新しい ID** を `watch()` すると予算が戻り、
    **最後に追加された ID から数えて 4 回で必ず止まる** (合計回数は 4 を超えうる = 仕様)。
    **既に監視中の ID を再度 watch しても予算は戻らない**
  - **single-flight**: 再取得が in-flight の間に呼ばれた `reload` は**同じ Promise を返す**
    (即解決しない = scheduler が古い manual のまま次の予算を消費しない)
  - **single-flight**: 前回の reload が解決するまで次を始めない
  - `pause()` 中は発火せず、`resume()` で残り試行だけ再開する
  - **オフラインキュー再開経路** (page 側の配線テスト):
    uploaded の outcome が**すべて**監視へ入る / `queued` や `quota_exceeded` は入らない /
    reload は uploaded 件数によらず**1 回だけ** /
    再開直後の reload 時点で未生成でも、その後の有界再取得で反映される
  - `stop()` 後に到着した reload の完了が**再スケジュールしない** (unmount 後の状態変更なし)
  - reload が reject しても監視対象を消さず、残り試行へ進む
- [ ] 新規 (画面統合): `tests/js/pages/Capture/*` もしくは `TakeStrip.test.ts` で
      `has_thumbnail` の `false → true` 反映を確認する (S9 と共有)
- [ ] 既存: `pnpm typecheck` / `pnpm lint` / atomic-import-graph が緑

### リスク

- **Inertia の `onFinish` が呼ばれない経路** (ページ遷移でキャンセルされた等) があると
  `reloading` が立ったままになり、以後の再取得が止まる。
  → 影響は「サムネイル反映が次回入室まで遅れる」だけで、機能の詰みは作らない。
  `onFinish` は成功・失敗・キャンセルのいずれでも呼ばれる契約に依存している旨をコメントへ書く。
- fake timer のテストは配線ミス (page 側で `sync` を呼び忘れる等) を検出しない。
  → S9 の props 反映テストと合わせて受入条件にする。

---

## S11: ドキュメント更新

### 変更箇所

- `docs/architecture.md` §撮影 PWA (presigned アップロード + 容量 Quota) の運用契約

### 波及変更

- テストファイル: なし (ドキュメント)

### 変更後コード (追記する箇所の要旨)

```markdown
- **サムネイル生成 (media queue)**: テイク登録の確定 tx が `Jobs/Capture/GenerateTakeThumbnailJob`
  を投入し、`Services/Capture/TakeThumbnailPipeline` が S3 GET → ffmpeg 1 フレーム抽出 →
  **S3 PUT の直前に所有権再検証 (preflight)** → 条件付き UPDATE
  (`where status=ready and thumbnail_path is null`) で `takes.thumbnail_path` /
  `takes.thumbnail_size_bytes` を確定する。S3 キーは take の主キーから決定的に組む。
  worker は削除ジョブと同じ `queue:work database-media --timeout=240` を共用する。
  時間予算は ffmpeg 60 < job 180 < worker 240 < retry_after 300。
- **サムネイルは容量 Quota に計上する (事後計上)**: `takes.thumbnail_size_bytes` を
  `StorageUsageService::bytesUsed()` が加算する。**予約 (bytes_pending) は経ない**ため、
  生成が上限を跨ぐことはありうる (上限の強制点は presigned URL 発行時のまま。
  超過は QuotaStatusDto の既存表示が受ける)。`takes.size_bytes` の意味 (三点照合の確定値) は不変。
- **保証しないもの**: 生成の成功 (失敗しても take は `ready` のまま) /
  過去分の一括バックフィル (行わない) / 孤児オブジェクトと work dir 残骸の自動回収 (行わない) /
  重複配送時に DB の記録バイト数と S3 の実体が完全一致すること /
  撮影画面での反映は有界 (**最後に監視集合へ追加されたテイクを起点に最大 4 回・~29 秒**の再取得。
  既に監視中の ID の再追加では予算は戻らず、キュー再開で複数件を追加した場合は最後の 1 件が起点になる。
  撮影を続ける限り予算は更新され、撮影を止めれば必ず停止する) であること。
```

### テスト計画

- [ ] `pnpm test`/`composer test` に影響しない (ドキュメント)
- [ ] `app-update-docs` 的な陳腐化検査は本タスクでは走らせない (別スキルの責務)

### リスク

- なし

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) migration を含む (列追加) ため、他タスクと同じ worktree で並行すると migration 順序と DB 状態が競合する。(2) deny-by-default の目録を 5 つ (dedup / lease / DirectFetch / S3 面分類 / IDOR) 更新するため、他タスクが同じ目録に触れると機械的な件数固定・exact-fit の衝突が起きる。(3) S1〜S10 は依存関係が一直線 (列 → storage → extractor → pipeline → 投入 → 目録 → endpoint → DTO → UI → 反映) で、部分適用しても価値が出ない (列だけ足しても書き込む経路が無い = 現状と同じ)。 |
| 競合リスク | `tests/Architecture/JobExecutionDedupInventoryTest.php` / `QueuedJobLeaseInventoryTest.php` / `tests/Support/Security/DirectFetchInventory.php` / `tests/Support/Storage/S3SurfaceInventory.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` は**新しいジョブや route を足す全タスクが触る**ファイルである。同時期に別のジョブ追加タスクが走る場合、マージ時に件数固定 (`jobDedupExemptionCap` 等) の再計算が要る。`.claude/skills/app-bug-hunt/inventory/annotations.toml` と生成物も同様。 |

## 実装順序 (推奨)

1. **S1** (列 + 集計) → テストを先に赤にしてから実装 (テストファースト)
2. **S2** (storage 3 メソッド + fake + 面分類)
3. **S3** (extractor + config + bind)
4. **S4** (pipeline + job) — preflight の配置を behavioral テストで先に赤化する
5. **S5** (登録からの投入) + **S6** (目録登録。ここで Architecture レーンが緑になる)
6. **S7** (endpoint) → **S8** (DTO/TS) → **S9** (UI) → **S10** (自動反映)
7. **S11** (docs) → 全検証コマンド (`composer test` / `composer phpstan` /
   `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`)

## 未解決点 (実装時に確定させる)

1. **`DirectFetchInventory` の entry key の実文字列**。スキャナの出力で確定させる
   (目録は exact-fit のため、先回りで余分な entry を書かない)。
2. **`Cut` → `VideoManual` の relation 名**。`TakeThumbnailPipeline::thumbnailKeyFor()` は
   relation で辿る (クラス起点の主キー同一性クエリを増やさない)。
3. **`Storage::disk('s3')->writeStream()` の `ContentType` オプション名**。
   Flysystem AwsS3V3Adapter の受理オプションであることを実装時に確認し、
   fake 側 (sidecar の `content_type`) と実 disk 側の**両方**で検証する。
