# 詳細設計: still-image-cut-capture (静止画カットの撮影と反映)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg /
> 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(本施策は LLM を呼ばないため非該当)
6. prompt 文字列のコード直書き(本施策は非該当)
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
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
- フロントは Svelte 5 runes + DS token のみ。アイコンは `@lucide/svelte` のみ。
  component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import

### 本施策で特に効くドメイン固有規約

- **規約 1 (シナリオ整合の共有ロック)**: 本施策は `cuts` / `video_manuals.status` / `scenario_version` を**書かない**。
  新しい書き込み経路は増えない (`ScenarioWritePathInventoryTest` の inventory は無変更)。
- **規約 2 (容量 Quota の予約)**: 静止画も**同じ**予約経路を通る。`pending`(INSERT 時に明示代入)→`verifying`(CAS)
  →`completed`/`released`(CAS)。新しい予約状態も別経路も作らない。
- **規約 12 (採用テイク充足判定の単一化)**: `AdoptedReadyTakeCoverage` の述語には**触らない**。
  本施策が足す `EffectiveMaterialType` は「ready かどうか」を一切見ない別の式である。

## 概念設計リファレンス

`devnotes/20260816-1758-still-image-cut-capture/conceptual-design.md` (Codex conceptual-review Round 6 で APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | `takes.material_type` 列の追加と Content-Type 分類器 (`MaterialType` enum は**既存**。`takes` にも適用するだけで enum 自体は無変更) | migration(新), `app/Support/Capture/TakeMaterialClassifier.php`(新), `app/Models/Take.php`, `database/factories/TakeFactory.php` | 高 |
| S2 | 静止画の受け入れ (presign 許可・整合 422・登録時の確定) | `config/capture.php`, `app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php`, `app/Http/Requests/Capture/StoreCaptureTakeRequest.php`, `app/Services/Capture/TakeUploadService.php`, `app/Services/Capture/TakeRegistrationService.php` | 高 |
| S3 | 実効素材種別と静止画尺の単一化 | `app/Services/Manual/EffectiveMaterialType.php`(新), `app/Services/Manual/StillDisplayDuration.php`(新), `app/Services/Manual/RenderPipeline.php`, `app/Services/Manual/RenderJobService.php`, `app/DataTransferObjects/Manual/Render/RenderClipSpec.php`, `app/Services/Render/FfmpegVideoComposer.php`, `config/manual.php` | 高 |
| S4 | ffmpeg / ffprobe への `-max_alloc` 一律付与 | `config/manual.php`, `app/Services/Render/FfmpegVideoComposer.php`, `app/Services/Capture/FfmpegTakeThumbnailExtractor.php`, `tests/Architecture/FfmpegProcessLaunchInventoryTest.php`(新) | 高 |
| S5 | 撮影 PWA / PC の静止画撮影・アップロード | `resources/js/lib/capture/still-encode.ts`(新), `resources/js/lib/capture/camera.ts`, `resources/js/components/features/capture/CameraRecorder.svelte`, `.../CaptureFileFallback.svelte`, `resources/js/pages/Capture/Show.svelte`, `resources/js/components/features/manual/TakeFileUpload.svelte`, `resources/js/types/capture.ts`, `resources/js/types/manual.ts` | 高 |
| S6 | 静止画テイクの表示とサムネイル | `app/Services/Capture/TakeThumbnailExtractor.php`, `.../FfmpegTakeThumbnailExtractor.php`, `.../TakeThumbnailPipeline.php`, `app/DataTransferObjects/Capture/CaptureTakeData.php`, `app/DataTransferObjects/Manual/SelectableTakeData.php`, `.../TakeStrip.svelte`, `.../TakePreviewDialog.svelte`, `.../TakePreviewPanel.svelte` | 中 |
| S7 | 素材登録状況 (未登録 / 動画登録済 / 静止画登録済) の表示 | `app/DataTransferObjects/Manual/CutTakeSummaryData.php`, `app/DataTransferObjects/Manual/ScenarioStepData.php`, `app/DataTransferObjects/Manual/ScenarioPointData.php`, `app/DataTransferObjects/Capture/CaptureCutData.php`, `app/DataTransferObjects/Manual/TakeSelectionPageData.php`, `resources/js/components/features/manual/ScenarioEditor.svelte`, `resources/js/types/manual.ts` | 中 |
| S8 | 通しのテストとドキュメント | `tests/**`, `docs/architecture.md` | 高 |

---

## S1: `takes.material_type` 列の追加と Content-Type 分類器

> **`app/Enums/Manual/MaterialType.php` は既存で、`cuts.material_type` が既に使っている。
> 本施策は enum を新設しない** — `takes` にも同じ enum を適用するだけである
> (計画と実体は別概念だが値域は同じ)。

### 変更箇所

- 新規: `database/migrations/2026_08_16_120000_add_material_type_to_takes_table.php`
- 新規: `app/Support/Capture/TakeMaterialClassifier.php`
- `app/Models/Take.php` (docblock `@property` / `casts()`)
- `database/factories/TakeFactory.php` (L24-39 definition / state 追加)

### 波及変更

- TypeScript 型定義: `resources/js/types/capture.ts` の `CaptureTake`、`resources/js/types/manual.ts` の
  `SelectableTake` に `material_type` を足す (S5 / S6 で実施)
- API Resource/DTO: `CaptureTakeData` / `SelectableTakeData` (S6 で実施)
- テストファイル: `TakeFactory` を使うすべてのテストは既定 `video` のまま影響なし。
  `tests/Feature/Capture/CaptureManualBrowsingTest.php` の**キー集合の契約**は S6 で更新する

### 現行コード

```php
// database/migrations/2026_07_10_000400_create_takes_table.php (抜粋)
$table->string('video_path');
$table->string('thumbnail_path')->nullable();
$table->bigInteger('size_bytes');
$table->integer('duration_ms')->nullable();
$table->string('status');
```

```php
// app/Models/Take.php (抜粋)
protected function casts(): array
{
    return [
        'status' => TakeStatus::class,
        'captured_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'thumbnail_size_bytes' => 'integer',
    ];
}
```

```php
// database/factories/TakeFactory.php (抜粋)
'video_path' => 'takes/'.fake()->uuid().'.mp4',
'thumbnail_path' => null,
'thumbnail_size_bytes' => null,
'size_bytes' => fake()->numberBetween(100_000, 50_000_000),
'duration_ms' => fake()->numberBetween(1_000, 60_000),
'status' => TakeStatus::Ready->value,
```

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Enums\Manual\MaterialType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * takes.material_type: 登録された素材の**実体**種別 (cuts.material_type は計画で別概念)。
     *
     * 3 段で入れる:
     * 1. nullable で追加 (既存行を壊さない)
     * 2. 既存行を 'video' で backfill — presign は今まで
     *    capture.allowed_video_content_types しか通していないため、既存テイクは全件動画である
     * 3. NOT NULL 化 (DB default は置かない = INSERT 時の明示代入を強制する。
     *    ドメイン規約 1(ii)/2 と同じ理由で、default に依存すると migration 変更で意味が黙って変わる)
     */
    public function up(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->string('material_type')->nullable()->after('video_path');
        });

        DB::table('takes')->whereNull('material_type')
            ->update(['material_type' => MaterialType::Video->value]);

        Schema::table('takes', function (Blueprint $table): void {
            $table->string('material_type')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->dropColumn('material_type');
        });
    }
};
```

```php
<?php

declare(strict_types=1);

namespace App\Support\Capture;

use App\Enums\Manual\MaterialType;
use InvalidArgumentException;

/**
 * 申告 Content-Type → 素材種別の写像 (**この写像を書いてよい唯一の場所**)。
 *
 * 許可集合の正本は config/capture.php の 2 キー
 * (`allowed_video_content_types` / `allowed_still_content_types`) である。
 * 本クラスは「許可集合のどちら側か」だけを答え、許可・不許可の判断はしない
 * (未許可の値は FormRequest の Rule::in で既に落ちている。到達したら整合性異常として fail-loud)。
 *
 * **保証しないもの**: これは**申告**の分類であって、S3 に置かれたバイト列の実際の形式を保証しない。
 * 実体検証は行わない (docs/architecture.md §撮影 PWA の「保証しないもの」)。
 */
final class TakeMaterialClassifier
{
    public static function fromContentType(string $contentType): MaterialType
    {
        if (in_array($contentType, config()->array('capture.allowed_video_content_types'), true)) {
            return MaterialType::Video;
        }
        if (in_array($contentType, config()->array('capture.allowed_still_content_types'), true)) {
            return MaterialType::Still;
        }

        // ★ Assert::true(false, …) にしない — 静的解析が never へ絞れるかが stub 依存になり、
        //   「戻り値が無い経路」が型の上で残りうる。throw なら構造的に消える
        throw new InvalidArgumentException("未許可の Content-Type です: {$contentType}");
    }

    /** S3 キーの拡張子 (許可集合と 1 対 1。TakeUploadService::extensionFor から移設) */
    public static function extensionFor(string $contentType): string
    {
        // 2 メソッドで形を揃える (default => throw で戻り値欠落を構造的に消す)
        return match ($contentType) {
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new InvalidArgumentException("未許可の Content-Type です: {$contentType}"),
        };
    }
}
```

```php
// app/Models/Take.php
/**
 * ...
 * - material_type は**サーバ確定値** ($fillable 外)。予約行の content_type から
 *   TakeMaterialClassifier が導き、INSERT 時に forceFill で明示代入する。
 *   cuts.material_type (計画) とは別概念で、値域だけを共有する
 *
 * @property MaterialType $material_type
 */
protected function casts(): array
{
    return [
        'status' => TakeStatus::class,
        'material_type' => MaterialType::class,
        'captured_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'thumbnail_size_bytes' => 'integer',
    ];
}
```

```php
// database/factories/TakeFactory.php
public function definition(): array
{
    return [
        'cut_id' => Cut::factory(),
        'client_take_id' => (string) Str::ulid(),
        'video_path' => 'takes/'.fake()->uuid().'.mp4',
        // 既定は動画 (既存テイクは全件動画。既存テストの意味を変えない)
        'material_type' => MaterialType::Video->value,
        'thumbnail_path' => null,
        // ... 以下現行どおり
    ];
}

/** 静止画テイク (画像キー + duration_ms は null) */
public function still(): static
{
    return $this->state(fn (): array => [
        'video_path' => 'takes/'.fake()->uuid().'.jpg',
        'material_type' => MaterialType::Still->value,
        'duration_ms' => null,
    ]);
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`MaterialType` / `string`)
- [x] null 安全 (`match` の `default => throw` で戻り値欠落を**構造的に**消す。
      `Assert::true(false, …)` に依存しない = stub 依存の `never` 推論を当てにしない)
- [x] DTO を返している (配列返却なし)
- [x] Generics の型パラメータが正しい (`Factory<Take>` は既存のまま)
- [x] `config()->array()` は `array<mixed>` を返すため `in_array(..., true)` で比較する
      (`config()` の生値をコマンドや型付き引数へ直接流さない)

### テスト計画

- [ ] 新規 `tests/Unit/Capture/TakeMaterialClassifierTest.php` —
      許可済み video 3 種 → `Video` / 許可済み still 2 種 → `Still` / 未許可 → 例外
- [ ] 新規 `tests/Feature/Capture/TakeMaterialTypeMigrationTest.php` は**作らない**
      (migration 単体テストは既存に前例が無い)。代わりに `TakeFactory` の既定が `Video` であることを
      既存の Factory テスト方針に沿って `tests/Unit/…` で 1 件だけ固定する
- [ ] `Take::$fillable` に `material_type` が**含まれない**ことを既存 `MassAssignmentSafetyTest` の
      枠内で確認する (保護キー一覧には入れない — `material_type` は tenant/ownership キーではないため。
      payload 拒否は S2 の `['missing']` ルールが担う)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **backfill の前提が崩れると誤分類になる**。前提は「presign が動画 Content-Type しか通していない」ことで、
  これは `StoreTakeUploadUrlRequest` の `Rule::in(config('capture.allowed_video_content_types'))` から機械的に言える。
  前提を `docs/architecture.md` に記録する。
- `nullable(false)->change()` は `doctrine/dbal` 非依存の Laravel 11+ ネイティブ change を使う。
  **pgsql / sqlite の両方で通ることを実際のテストレーンで確認する** (テスト DB は pgsql)。

---

## S2: 静止画の受け入れ (presign 許可・整合 422・登録時の確定)

### 変更箇所

- `config/capture.php` (L24 付近に 2 キー追加)
- `app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php` (rules L30-40)
- `app/Http/Requests/Capture/StoreCaptureTakeRequest.php` (rules L28-38)
- `app/Services/Capture/TakeUploadService.php` (issue L38-109 / extensionFor L111-123)
- `app/Services/Capture/TakeRegistrationService.php` (finalize L144-181)

### 波及変更

- TypeScript 型定義: なし (`UploadTicket` の shape は変わらない)
- API Resource/DTO: `TakeUploadInput` は変更なし (`contentType` が既にある)
- テストファイル: `tests/Feature/Capture/TakeUploadUrlTest.php` /
  `tests/Feature/Capture/TakeRegistrationTest.php` 系に still ケースを追加 (S8)

### 現行コード

```php
// config/capture.php
'max_take_bytes' => 500 * 1024 * 1024, // 500 MiB
'allowed_video_content_types' => ['video/mp4', 'video/webm', 'video/quicktime'],
```

```php
// app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php
'size_bytes' => ['required', 'integer', 'min:1', 'max:'.config()->integer('capture.max_take_bytes')],
'content_type' => ['required', 'string', Rule::in(config()->array('capture.allowed_video_content_types'))],
```

```php
// app/Services/Capture/TakeUploadService.php
$path = sprintf(
    'projects/%d/manuals/%d/cuts/%d/takes/%s.%s',
    $lockedManual->project_id, $lockedManual->id, $lockedCut->id,
    (string) Str::ulid(), self::extensionFor($input->contentType),
);
```

```php
// app/Services/Capture/TakeRegistrationService.php (finalize)
$take = $lockedCut->takes()->make([
    'client_take_id' => $reservation->client_take_id,
    'video_path' => $reservation->video_path,
    'size_bytes' => $reservation->size_bytes,
    'duration_ms' => $input->durationMs,
    'captured_at' => $input->capturedAt,
]);
$take->forceFill(['status' => TakeStatus::Ready, 'sort_order' => 0])->save();
```

### 変更後コード

```php
// config/capture.php
// 1 テイクの上限サイズ (動画)
'max_take_bytes' => 500 * 1024 * 1024, // 500 MiB

// presigned PUT を許可する動画 Content-Type
'allowed_video_content_types' => ['video/mp4', 'video/webm', 'video/quicktime'],

// presigned PUT を許可する静止画 Content-Type。
// WebP / HEIC は入れない — クライアントが canvas で JPEG へ再エンコードして送るため不要であり、
// ffmpeg のビルド依存 (デコーダの有無) を持ち込まない。
'allowed_still_content_types' => ['image/jpeg', 'image/png'],

// 1 テイクの上限サイズ (静止画)。動画と同じ 500 MiB を許す理由が無い。
// ★ これは**バイト数**の上限であり、画素数は縛らない (画素数の防波堤は
//    manual.ffmpeg_max_alloc_bytes = S4)。
'max_still_bytes' => 16 * 1024 * 1024, // 16 MiB
```

```php
// app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php
public function rules(): array
{
    return array_merge([
        'client_take_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
        // 上限はまず**両者の最大**で受け、種別ごとの上限は下の after フックで判定する
        // (Rule::in を通る前に size の上限を種別で切り替えられないため)
        'size_bytes' => ['required', 'integer', 'min:1', 'max:'.self::maxAllowedBytes()],
        'content_type' => ['required', 'string', Rule::in(self::allowedContentTypes())],
        'checksum_sha256' => ['required', 'string', 'size:44', 'regex:%^[A-Za-z0-9+/]{43}=$%'],
        // サーバ生成キー / サーバ確定値 (payload から受けない)
        'video_path' => ['missing'],
        'material_type' => ['missing'],
    ], $this->protectedKeyMissingRules());
}

/**
 * 種別ごとのバイト上限 (静止画に 500 MiB を許さない)。
 * content_type が確定した後でないと判定できないため after フックで見る。
 *
 * 要 import: `use Illuminate\Validation\Validator;`
 *
 * @return array<int, callable(Validator): void>
 */
public function after(): array
{
    return [
        function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return; // 型が確定していない段階では判定しない
            }
            $limit = TakeMaterialClassifier::fromContentType($this->string('content_type')->value())
                === MaterialType::Still
                    ? config()->integer('capture.max_still_bytes')
                    : config()->integer('capture.max_take_bytes');
            if ($this->integer('size_bytes') > $limit) {
                $validator->errors()->add('size_bytes', '選択したファイルのサイズが上限を超えています。');
            }
        },
    ];
}

/** @return list<string> */
private static function allowedContentTypes(): array
{
    /** @var list<string> $video */
    $video = array_values(config()->array('capture.allowed_video_content_types'));
    /** @var list<string> $still */
    $still = array_values(config()->array('capture.allowed_still_content_types'));

    return [...$video, ...$still];
}

private static function maxAllowedBytes(): int
{
    return max(
        config()->integer('capture.max_take_bytes'),
        config()->integer('capture.max_still_bytes'),
    );
}
```

```php
// app/Http/Requests/Capture/StoreCaptureTakeRequest.php (rules に 1 行追加)
'video_path' => ['missing'],
'size_bytes' => ['missing'],
'status' => ['missing'],
'sort_order' => ['missing'],
'material_type' => ['missing'], // 素材種別はサーバが予約行の content_type から確定する
```

```php
// app/Services/Capture/TakeUploadService.php (issue の tx 内、ロック済み cut 再解決の直後)
/** @var Cut $lockedCut */
$lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();

// 素材種別の整合 (概念設計「受け入れは非対称」):
// - still カット: 画像も動画も受ける (動画は先頭フレーム抽出で従来どおり合成できる)
// - video / 未指定カット: 動画のみ。画像は 422 で押下時にエラー表示 (禁止事項 8 の通り
//   ボタンを disabled にはしない)。入口で止めるのは「指示と違う素材で容量を消費させない」ため。
// ★ 一方でレンダ側は take の実体を優先する (EffectiveMaterialType)。採用後に
//   cut.material_type を video へ戻す編集ができるので、入口検証だけでは不整合を防げないためである。
$material = TakeMaterialClassifier::fromContentType($input->contentType);
if ($material === MaterialType::Still && $lockedCut->material_type !== MaterialType::Still) {
    throw ValidationException::withMessages([
        'content_type' => ['このカットは動画で撮影する設定です。静止画を使う場合はシナリオ編集で素材を「静止画」に変更してください。'],
    ]);
}

$path = sprintf(
    'projects/%d/manuals/%d/cuts/%d/takes/%s.%s',
    $lockedManual->project_id,
    $lockedManual->id,
    $lockedCut->id,
    (string) Str::ulid(),
    TakeMaterialClassifier::extensionFor($input->contentType), // private static から移設
);
```

```php
// app/Services/Capture/TakeRegistrationService.php (finalize)
// 素材種別は**予約行の content_type**から導く (チケット偽装で差し替えられない)。
$material = TakeMaterialClassifier::fromContentType($reservation->content_type);

$take = $lockedCut->takes()->make([
    'client_take_id' => $reservation->client_take_id,
    'video_path' => $reservation->video_path,
    'size_bytes' => $reservation->size_bytes,
    // 静止画に尺は無い。クライアント申告があっても捨てる (表示・尺ゲートの両方で嘘をつかせない)
    'duration_ms' => $material === MaterialType::Still ? null : $input->durationMs,
    'captured_at' => $input->capturedAt,
]);
// material_type は保護された確定値のため forceFill で**INSERT 時に明示代入**する
// (ドメイン規約 1(ii)/2 と同じ理由。DB default を置いていないので、ここが唯一の設定点である)
$take->forceFill([
    'status' => TakeStatus::Ready,
    'sort_order' => 0,
    'material_type' => $material,
])->save();
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`after(): array` は Laravel の契約に合わせ `array<int, callable>`)
- [x] `config()->array()` の戻りを `array_values()` + `@var list<string>` で narrow してから spread
- [x] `config()->integer()` のみを使い、生 `config()` を比較に流さない
- [x] DTO を返している (`TakeUploadTicketData` / `TakeRegistrationResult` は変更なし)
- [x] `ValidationException::withMessages()` は既存経路と同型 (`response()->json()` を足さない)

### テスト計画

- [ ] `tests/Feature/Capture/TakeUploadUrlTest.php` 追記:
  - still カット + `image/jpeg` → 200 (チケット発行 / S3 キーが `.jpg`)
  - video カット + `image/jpeg` → 422 (`content_type` にエラー)
  - `material_type` を payload に入れると 422 (`missing`)
  - `image/jpeg` で `max_still_bytes` 超過 → 422 / 同サイズを `video/mp4` で送ると通る (上限の非対称)
  - `image/webp` → 422 (allowlist 外)
- [ ] `tests/Feature/Capture/TakeRegistrationTest.php` 追記:
  - 画像で登録 → `takes.material_type === still` / `duration_ms === null`
    (`duration_ms` を payload で送っても null になる)
  - 動画で登録 → `video` (回帰)
  - 予約が `completed` になり `bytes_pending` が解放されること (既存の Quota 契約が静止画でも同じ)
- [ ] `tests/Feature/Capture/TakeQuotaTest.php` (既存名は実装時に確認) —
      静止画も `checkAddition` を通ることを 1 件で固定
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`after()` フックの導入**: 既存 FormRequest に `after()` の前例が無い場合、
  実装時に既存の作法 (`withValidator` / rules 内クロージャ) を確認して合わせる。
  どちらでも「押下を受けてからエラーを出す」挙動は同じ。
- **`max_still_bytes` とクライアント既定値の関係**: クライアントは長辺 1920 / q0.85 で再エンコードするため
  通常 1 MB 未満。16 MiB は「正規経路が絶対に踏まない上限」として置く。
- 画像を許可しても**画素数は縛られない**。防波堤は S4 の `-max_alloc` であり、両者は別の軸である
  (この非対称を `docs/architecture.md` に書く)。

---

## S3: 実効素材種別と静止画尺の単一化

### 変更箇所

- 新規: `app/Services/Manual/EffectiveMaterialType.php`
- 新規: `app/Services/Manual/StillDisplayDuration.php`
- `config/manual.php` (L63 付近)
- `app/Services/Manual/RenderPipeline.php` (`clipSpecFor` L245-282 / **`downloadSources` L379-392**)
- `app/Services/Manual/RenderJobService.php` (`assertTotalSourceDurationWithinLimit` L445-464)
- `app/DataTransferObjects/Manual/Render/RenderClipSpec.php` (`$takeVideoPath` → `$takeSourcePath`)
- `app/Services/Render/FfmpegVideoComposer.php` (`sourceFor` の呼び先 / docblock)

### 波及変更

- TypeScript 型定義: なし (マニフェストはサーバ内部の DTO で、フロントへ出ない)
- API Resource/DTO: `RenderClipSpec` のフィールド名変更 (内部 DTO。外部契約なし)
- テストファイル: `tests/Unit/Render/RenderManifestTest.php` / `tests/Unit/Render/FfmpegVideoComposerTest.php` /
  `tests/Feature/Manual/RenderPipelineTest.php` / `tests/Feature/Manual/RenderTriggerTest.php` の
  `takeVideoPath:` 名前付き引数を全件更新

### 現行コード

```php
// app/Services/Manual/RenderPipeline.php
$take = $cut->adoptedTake;
Assert::notNull($take, 'isMissing() が false なら採用テイクは必ず存在する');

$isStill = $cut->material_type === MaterialType::Still;

return new RenderClipSpec(
    cutId: $cut->id,
    label: $label,
    source: $isStill ? RenderClipSource::TakeStill : RenderClipSource::TakeVideo,
    takeVideoPath: $take->video_path,
    stillDisplaySeconds: $isStill
        ? ($cut->static_display_seconds ?? config()->integer('manual.preview_placeholder_seconds'))
        : null,
    subtitlePrimary: $cut->subtitle_primary,
    subtitleSecondary: $cut->subtitle_secondary,
);
```

```php
// app/Services/Manual/RenderJobService.php
private function assertTotalSourceDurationWithinLimit(array $ordered): void
{
    $defaultMs = config()->integer('manual.render_default_take_duration_ms');
    $totalMs = 0;
    foreach ($ordered as $entry) {
        $cut = $entry->cut;
        if ($cut->material_type === MaterialType::Still && $cut->static_display_seconds !== null) {
            $totalMs += $cut->static_display_seconds * 1000;

            continue;
        }
        $totalMs += $cut->adoptedTake->duration_ms ?? $defaultMs;
    }
    // ...
}
```

```php
// app/Services/Manual/RenderPipeline.php (downloadSources)
private function downloadSources(RenderManifest $manifest, string $workDir): array
{
    $localSources = [];
    foreach ($manifest->clips as $index => $clip) {
        if ($clip->takeVideoPath === null) {
            continue;
        }
        $localPath = "{$workDir}/src{$index}.mp4";
        $this->storage->downloadToLocal($clip->takeVideoPath, $localPath);
        $localSources[$clip->cutId] = $localPath;
    }

    return $localSources;
}
```

```php
// app/DataTransferObjects/Manual/Render/RenderClipSpec.php
public function __construct(
    public int $cutId,
    public string $label,
    public RenderClipSource $source,
    /** S3 キー (Placeholder は null) */
    public ?string $takeVideoPath,
    /** TakeStill のみ (静止画の表示秒数) */
    public ?int $stillDisplaySeconds,
    public ?string $subtitlePrimary,
    public string $subtitleSecondary,
) {}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\MaterialType;
use App\Models\Cut;
use App\Models\Take;

/**
 * 「このカットを**実際に**どちらの素材として合成するか」を決める式の**唯一の所在**。
 *
 * 実体優先である: cut の計画が `still` でなくても、採用テイクの実体が画像なら `Still` を返す。
 * 理由は、**採用した後に編集者がシナリオ編集で cut.material_type を `video` へ戻せる**ためで、
 * 入口 (presign 422) でも採用 API でもこの状態は防げない。画像を動画クリップ経路
 * (`FfmpegVideoComposer::planTakeVideo()` = ffprobe で尺を測る) に流すと必ず壊れるので、
 * 「画像が動画クリップとして合成される道」を構造的に消す。
 *
 * **採用テイクは引数で受ける** (このクラスは `adoptedTake` relation を読まない)。
 * したがって `AdoptedTakeReferenceInventory` の登録は増えない。
 *
 * **ready 判定は一切しない** — 「採用済みかつ ready か」の述語は
 * `AdoptedReadyTakeCoverage` の専権である (ドメイン規約 12)。本クラスは呼ばれる時点で
 * 採用テイクが確定していることを前提にする。
 */
final class EffectiveMaterialType
{
    public static function of(Cut $cut, Take $adoptedTake): MaterialType
    {
        return $cut->material_type === MaterialType::Still
            || $adoptedTake->material_type === MaterialType::Still
                ? MaterialType::Still
                : MaterialType::Video;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\Cut;

/**
 * 静止画カットの表示秒を決める式の**唯一の所在**。
 *
 * 編集者が `cuts.static_display_seconds` を指定していればそれを使い、未指定なら
 * `config('manual.default_still_display_seconds')` を使う。
 *
 * ★ 以前は `RenderPipeline` が `manual.preview_placeholder_seconds`
 *   (= 採用テイク欠落 cut のプレースホルダ尺) を流用していた。これは別概念であり、
 *   プレースホルダ尺を変えると完成動画の静止画尺まで黙って変わる状態だった。撤去済み。
 * ★ **クランプしない**。異常値を黙って別の値へ変えると設定ミスが隠れる。
 *   既定値が編集画面の入力範囲 (1〜60) に収まっていることは ConfigHardeningTest が pin する。
 *
 * **doc/02 §2.2 の「ナレーション尺より短ければナレーション尺が優先」は v1 では実装しない。**
 * v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という属性が存在しないためである
 * (doc/09 の v1 尺算出も `cut_length = material_ms` / 静止画は `static_display_seconds*1000`)。
 * 再検討の条件は「TTS を導入してナレーション音声の実尺が確定したとき」で、
 * そのときの変更点は本クラス 1 か所に閉じる。
 */
final class StillDisplayDuration
{
    public static function secondsFor(Cut $cut): int
    {
        return $cut->static_display_seconds
            ?? config()->integer('manual.default_still_display_seconds');
    }
}
```

```php
// config/manual.php
'preview_placeholder_seconds' => 3,           // 採用テイク欠落 cut のプレースホルダ尺
// 静止画カットの表示秒 (cuts.static_display_seconds 未指定時)。
// 編集画面の入力範囲 (1〜60) の内側に置く。env() は持たせない (運用で変える値ではない)
'default_still_display_seconds' => 5,
// ffmpeg / ffprobe の 1 回あたり heap 確保上限 (バイト)。S4 参照
'ffmpeg_max_alloc_bytes' => 536_870_912,      // 512 MiB
```

```php
// app/Services/Manual/RenderPipeline.php (clipSpecFor の後半)
$take = $cut->adoptedTake;
Assert::notNull($take, 'isMissing() が false なら採用テイクは必ず存在する');

// 実効素材種別の式は EffectiveMaterialType が唯一の所在 (ここに書き直さない)。
// 尺ゲート (RenderJobService) も同じ 2 クラスを呼ぶ = ゲートとレンダで尺が食い違わない
$isStill = EffectiveMaterialType::of($cut, $take) === MaterialType::Still;

return new RenderClipSpec(
    cutId: $cut->id,
    label: $label,
    source: $isStill ? RenderClipSource::TakeStill : RenderClipSource::TakeVideo,
    takeSourcePath: $take->video_path,
    stillDisplaySeconds: $isStill ? StillDisplayDuration::secondsFor($cut) : null,
    subtitlePrimary: $cut->subtitle_primary,
    subtitleSecondary: $cut->subtitle_secondary,
);
```

```php
// app/Services/Manual/RenderJobService.php
private function assertTotalSourceDurationWithinLimit(array $ordered): void
{
    $defaultMs = config()->integer('manual.render_default_take_duration_ms');
    $totalMs = 0;
    foreach ($ordered as $entry) {
        $cut = $entry->cut;
        $take = $cut->adoptedTake;
        // ここへ来る時点で採用テイクは確定している (充足判定 = AdoptedReadyTakeCoverage が先に 422 を出す)
        Assert::notNull($take, '充足判定を通った cut には採用テイクが必ず存在する');

        // ★ レンダ (RenderPipeline::clipSpecFor) と**同じ 2 クラス**を通す。
        //   片方だけ実効判定を持つと、cut=video/take=still の組み合わせで
        //   ゲート 60 秒 / レンダ 5 秒という新しい二重管理が生まれる
        $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
            ? StillDisplayDuration::secondsFor($cut) * 1000
            : ($take->duration_ms ?? $defaultMs);
    }

    if ($totalMs > config()->integer('manual.render_max_total_source_ms')) {
        throw ValidationException::withMessages([
            'takes' => ['動画の合計尺が上限を超えています。マニュアルを分割してください。'],
        ]);
    }
}
```

```php
// app/Services/Manual/RenderPipeline.php (downloadSources)
/**
 * 素材のローカル取得。
 *
 * ★ ローカル名から拡張子を落とす (旧: `src{$index}.mp4`)。
 *   拡張子は**現時点で既に嘘**である — `video/webm` / `video/quicktime` のテイクも
 *   `.mp4` という名前で落ちており、合成は最初から **ffmpeg の内容プローブ**に依存している。
 *   画像素材を足すにあたって嘘を増やす理由が無いので、名前から拡張子ごと外す。
 *   前例は TakeThumbnailPipeline の `"{$workDir}/source"` (同じく拡張子なしで ffmpeg に渡す)。
 *
 * @return array<int, string> cutId => ローカルパス
 */
private function downloadSources(RenderManifest $manifest, string $workDir): array
{
    $localSources = [];
    foreach ($manifest->clips as $index => $clip) {
        if ($clip->takeSourcePath === null) {
            continue;
        }
        $localPath = "{$workDir}/src{$index}";
        $this->storage->downloadToLocal($clip->takeSourcePath, $localPath);
        $localSources[$clip->cutId] = $localPath;
    }

    return $localSources;
}
```

```php
// app/DataTransferObjects/Manual/Render/RenderClipSpec.php
public function __construct(
    public int $cutId,
    /** 手順N / 急所N-M (派生。エラー表示・ログ用) */
    public string $label,
    public RenderClipSource $source,
    /**
     * 素材の S3 キー (Placeholder は null)。
     * ★ TakeStill には**画像**が入りうるため「動画のパス」という名前にしない
     *   (旧名 takeVideoPath)。compose 側は種別で分岐せず、この 1 本を入力に取る
     */
    public ?string $takeSourcePath,
    /** TakeStill のみ (静止画の表示秒数) */
    public ?int $stillDisplaySeconds,
    public ?string $subtitlePrimary,
    public string $subtitleSecondary,
) {}
```

```php
// app/Services/Render/FfmpegVideoComposer.php (planTakeStill の docblock を直すだけ。処理は無変更)
/**
 * 静止画クリップ (素材の**先頭フレーム**を stillDisplaySeconds 尺で保持 + 無音声)。
 *
 * ★ 入力契約: 「1 枚目のフレームを取り出せる入力」であれば動画でも画像でもよい。
 *   1 段目の `-frames:v 1` は画像入力でも 1 枚の PNG を出すため、
 *   動画テイク由来の still と画像テイク由来の still を**同じ経路**で扱える。
 *   「画像なら中間 PNG 化を省く」最適化はしない (通る経路を 2 本にすると片方だけ壊れる形を作る)。
 * ★ 2 段目 (`-loop 1 -i frame{n}.png`) が読むのはサーバ生成 PNG だが、その画素数は
 *   **入力素材と同じ**である。画素数の防波堤は S4 の -max_alloc が全コマンドに一律で掛ける。
 */
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`MaterialType` / `int`)
- [x] null 安全 (`Assert::notNull($take, …)` で `?Take` を `Take` に絞る。述語の再実装ではない)
- [x] DTO を返している (`RenderClipSpec`)
- [x] `config()->integer()` のみ使用
- [x] `RenderClipSpec` の名前付き引数を**全呼び出し元で**更新する (漏れは PHPStan が unknown named argument で落とす)

### テスト計画

- [ ] 新規 `tests/Unit/Manual/EffectiveMaterialTypeTest.php` — 4 組み合わせ
      (`cut.material_type=still` × `take.material_type=still` → Still /
      `still` × `video` → Still / `video` × `video` → Video /
      `video` × `still` → **Still**) + `cut.material_type=null` × `take.material_type=video` → Video
- [ ] 新規 `tests/Unit/Manual/StillDisplayDurationTest.php` — 指定あり → その値 / 未指定 → config 既定
- [ ] `tests/Unit/Render/RenderManifestTest.php` 更新 — `takeSourcePath` へ改名 + 画像キーのケース追加
- [ ] `tests/Feature/Manual/RenderPipelineTest.php` — `downloadSources()` が
      **拡張子なしのローカル名** (`src0`) で `downloadToLocal` を呼ぶこと (Fake ストレージで固定)
- [ ] `tests/Feature/Manual/RenderPipelineTest.php` 追記 —
      C1 (still/still) のマニフェストが `TakeStill` + `stillDisplaySeconds = 既定 5` になる
- [ ] 新規 `tests/Feature/Manual/StillMaterialConsistencyTest.php` —
      **C5**: still カットで画像を登録・採用 → シナリオ編集で `material_type` を `video` へ戻す →
      (a) マニフェストが `TakeStill` になる (b) **尺ゲートが `secondsFor()*1000` を加算する**
      (`duration_ms ?? 60_000` に落ちない) の 2 点
- [ ] `tests/Feature/Manual/RenderTriggerTest.php` — 尺ゲートの回帰 (video 側の計算が変わっていないこと)
- [ ] `preview_placeholder_seconds` を変えても静止画クリップの尺が変わらないことを 1 件で固定
      (流用が撤去されたことの behavioral な証拠)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`RenderClipSpec` の改名は機械的だが漏れると赤になる**。名前付き引数のみで構築されているため、
  PHPStan と Pest の両方が漏れを検出する (silent break にならない)。
  **参照点は 3 つ** — `RenderPipeline::clipSpecFor()` (2 箇所) / `RenderPipeline::downloadSources()` /
  `FfmpegVideoComposer::sourceFor()` は `$clip->cutId` しか見ないため無変更。
- **ローカル名から拡張子を外す変更**は ffmpeg の内容プローブに依存する形を**明示化**するだけで、
  挙動は変わらない (現状も `.webm` / `.mov` を `.mp4` という名前で渡している)。
  ただし `Process::fake()` の引数列を見ているテストは名前が変わるため更新が要る。
- **`assertTotalSourceDurationWithinLimit` の分岐が変わる**。現行は
  「still かつ `static_display_seconds !== null`」のときだけ秒数を使い、未指定 still は
  `duration_ms ?? 60_000` に落ちていた。変更後は未指定 still も 5 秒になるため、
  **これまで 60 秒として数えられていたカットが 5 秒になる = 尺ゲートが緩む方向に動く**。
  これは意図した是正 (レンダの実尺と一致させる) だが、**挙動変更なので `docs/architecture.md` に明記する**。

---

## S4: ffmpeg / ffprobe への `-max_alloc` 一律付与

### 変更箇所

- `config/manual.php` (S3 で追加済みの `ffmpeg_max_alloc_bytes`)
- `app/Services/Render/FfmpegVideoComposer.php` (`runFfmpeg` L200-212 / `probeDurationMs` L215-236 /
  `hasAudioStream` L239-256)
- `app/Services/Capture/FfmpegTakeThumbnailExtractor.php` (`attempt` の Process 引数)
- 新規: `tests/Architecture/FfmpegProcessLaunchInventoryTest.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Unit/Render/FfmpegVideoComposerTest.php` /
  `tests/Unit/Render/FfmpegVideoComposerSmokeTest.php` /
  `tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php` (既存名は実装時に確認) の
  引数列アサーションを更新

### 現行コード

```php
// app/Services/Render/FfmpegVideoComposer.php
private function runFfmpeg(string $workDir, array $arguments, string $stage): void
{
    $binary = config()->string('manual.render_ffmpeg_binary');
    $result = Process::path($workDir)
        ->timeout(self::ENCODE_TIMEOUT_SECONDS)
        ->run([$binary, ...$arguments]);
    // ...
}

private function probeDurationMs(string $path): int
{
    $binary = config()->string('manual.render_ffprobe_binary');
    $result = Process::timeout(self::PROBE_TIMEOUT_SECONDS)->run([
        $binary, '-v', 'error',
        '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1',
        $path,
    ]);
    // ...
}
```

```php
// app/Services/Capture/FfmpegTakeThumbnailExtractor.php
$result = Process::timeout(config()->integer('capture.thumbnail_ffmpeg_timeout_seconds'))
    ->run([
        config()->string('manual.render_ffmpeg_binary'),
        '-nostdin', '-y',
        '-protocol_whitelist', 'file',
        '-ss', sprintf('%.3f', $seekMs / 1000),
        '-i', $source,
        // ...
    ]);
```

### 変更後コード

```php
// app/Support/Media/FfmpegSafetyArguments.php (新規)
<?php

declare(strict_types=1);

namespace App\Support\Media;

/**
 * ffmpeg / ffprobe の安全境界引数 (**バイナリの直後**に置く)。
 *
 * `-max_alloc` は 1 回の heap 確保の上限。**画素数爆弾**(小さいファイルで巨大な画素数を宣言する
 * 画像) が media キューの worker を OOM で落とし、キューを共有する他組織のサムネイル生成まで
 * 遅延させることを防ぐ。バイト数の上限 (`capture.max_still_bytes`) では止まらない別の軸である。
 *
 * ★ 配置は「最初の -i より前」ではなく **バイナリ直後** に統一する。
 *   ffprobe は入力を -i ではなく**位置引数**で受けるため、-i を基準にすると検査が空振りする。
 *
 * **保証しないもの**: プロセス全体の RSS 上限でも、同時実行数の上限でもない。
 * worker のメモリ cgroup 制限は本リポジトリに存在しない (デプロイ定義が無いため新設もしない)。
 */
final class FfmpegSafetyArguments
{
    /** @return list<string> */
    public static function all(): array
    {
        // config()->integer() で int を確定させてから明示的に文字列化する
        // (未型付けの config() 値をコマンド配列へ流さない = list<string> を保つ)
        return ['-max_alloc', (string) config()->integer('manual.ffmpeg_max_alloc_bytes')];
    }
}
```

```php
// app/Services/Render/FfmpegVideoComposer.php
private function runFfmpeg(string $workDir, array $arguments, string $stage): void
{
    $binary = config()->string('manual.render_ffmpeg_binary');
    $result = Process::path($workDir)
        ->timeout(self::ENCODE_TIMEOUT_SECONDS)
        ->run([$binary, ...FfmpegSafetyArguments::all(), ...$arguments]);
    // ... 以下現行どおり
}

private function probeDurationMs(string $path): int
{
    $binary = config()->string('manual.render_ffprobe_binary');
    $result = Process::timeout(self::PROBE_TIMEOUT_SECONDS)->run([
        $binary, ...FfmpegSafetyArguments::all(),
        '-v', 'error',
        '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1',
        $path,
    ]);
    // ... 以下現行どおり
}

// hasAudioStream も同じ形で 1 行挿入する
```

```php
// app/Services/Capture/FfmpegTakeThumbnailExtractor.php
$result = Process::timeout(config()->integer('capture.thumbnail_ffmpeg_timeout_seconds'))
    ->run([
        config()->string('manual.render_ffmpeg_binary'),
        ...FfmpegSafetyArguments::all(),
        '-nostdin', '-y',
        '-protocol_whitelist', 'file',
        '-ss', sprintf('%.3f', $seekMs / 1000),
        '-i', $source,
        // ... 以下現行どおり
    ]);
```

```php
// tests/Architecture/FfmpegProcessLaunchInventoryTest.php (新規・骨子)
//
// 守る不変条件: app/ から起動する ffmpeg / ffprobe プロセスは 1 本残らず
//   FfmpegSafetyArguments::all() をバイナリ直後に持つ。
//
// 検査 1 (母集団の pin): app/ 配下で 'manual.render_ffmpeg_binary' /
//   'manual.render_ffprobe_binary' を参照するファイルを走査し、
//   **現行 2 ファイルと完全一致**することを assert する (増減のどちらでも赤)。
//     - Services/Render/FfmpegVideoComposer.php
//     - Services/Capture/FfmpegTakeThumbnailExtractor.php
// 検査 2 (併記の pin): その 2 ファイルが FfmpegSafetyArguments を import していること。
//
// ★ 保証範囲を誇張しない: これは字句走査であり、動的に組み立てたコマンド配列や
//   vendor 内部からのプロセス起動には沈黙する。「引数の並び」の実体は
//   下の Unit テスト (Process::fake の引数列) が固定する。
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`list<string>`)
- [x] null 安全 (`config()->integer()` は int を返す。null 分岐なし)
- [x] DTO を返している (該当なし。`list<string>` は Process の契約型)
- [x] Generics の型パラメータが正しい (`list<string>` の spread で `list<string>` を保つ)

### テスト計画

- [ ] `tests/Unit/Render/FfmpegVideoComposerTest.php` 更新 — **全 5 コマンド** (静止画抽出 / 静止画ループ /
      動画クリップ / プレースホルダ / concat) と **ffprobe 2 コマンド** について、
      `argv[1] === '-max_alloc'` かつ `argv[2] === (string) config('manual.ffmpeg_max_alloc_bytes')`
- [ ] `tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php` 更新 — 同じ 2 添字を固定
      (**位置引数形式の ffprobe を含む母集団**であることをコメントで明記)
- [ ] 新規 `tests/Architecture/FfmpegProcessLaunchInventoryTest.php` — 上記 2 検査
- [ ] `tests/Architecture/ConfigHardeningTest.php` に `manual.ffmpeg_max_alloc_bytes = 536_870_912` と
      `manual.default_still_display_seconds = 5` を完全一致で pin (後者は 1〜60 の範囲内であることも)
- [ ] ffmpeg は `Process::fake()` のみ。**実バイナリに依存しない** (既存 `FfmpegVideoComposerTest` の作法)

### リスク

- **正当な素材を誤って落とす可能性**。512 MiB は 48MP のスマホ写真 (≒195 MB) と 4K 動画フレーム (≒33 MB) の
  どちらも通す。止まるのは 1.1 億画素超 (約 11600×11600 以上) の 1 回確保で、
  正規経路のクライアントは長辺 1920 へ再エンコードして送るため到達しない。
- `-max_alloc` は ffmpeg のグローバルオプションであり、**ffprobe も同じ名前で受ける**。
  実バイナリでの受理は `FfmpegVideoComposerSmokeTest` (実バイナリがある環境でのみ動く既存 smoke) で確認する。
  無い環境では skip される既存の作法に従う。

---

## S5: 撮影 PWA / PC の静止画撮影・アップロード

### 変更箇所

- 新規: `resources/js/lib/capture/still-encode.ts`
- `resources/js/lib/capture/camera.ts` (`supportsStillCapture()` 追加)
- `resources/js/components/features/capture/CameraRecorder.svelte` (`mode` props + シャッター)
- `resources/js/components/features/capture/CaptureFileFallback.svelte` (accept 切替 + 正規化)
- `resources/js/pages/Capture/Show.svelte` (mode の受け渡し / `showRecorder` の条件)
- `resources/js/components/features/manual/TakeFileUpload.svelte` (accept 切替 + 正規化)
- `resources/js/types/capture.ts` / `resources/js/types/manual.ts`
- `app/DataTransferObjects/Capture/CaptureCutData.php` (cut の `material_type` を出す。S7 と同時)

### 波及変更

- TypeScript 型定義: `CaptureCut.material_type` / `TakeSelectionCut.material_type` を追加
- API Resource/DTO: `CaptureCutData::toArray()` / `TakeSelectionPageData::toArray()` の shape に 1 キー追加
- テストファイル: `tests/js/components/features/capture/CameraRecorder.test.ts` (既存名は実装時に確認) /
  `tests/Feature/Capture/CaptureManualBrowsingTest.php` (キー集合の契約) を更新

### 現行コード

```svelte
<!-- resources/js/components/features/capture/CameraRecorder.svelte (操作行の idle 分岐) -->
{#if phase === "idle"}
    <Button variant="primary" onclick={startRecording} testId="start-recording">
        <Circle class="size-4" aria-hidden="true" />
        録画開始
    </Button>
    <button type="button" aria-label="カメラを切り替え" onclick={flipCamera} data-testid="flip-camera">
        <SwitchCamera class="size-5" aria-hidden="true" />
    </button>
{:else}
```

```svelte
<!-- resources/js/components/features/capture/CaptureFileFallback.svelte -->
if (!file.type.startsWith("video/")) { ... }
<input type="file" accept="video/*" ... />
```

```ts
// resources/js/lib/capture/camera.ts
export function supportsMediaRecorder(): boolean {
    return (
        typeof window.MediaRecorder !== "undefined" &&
        typeof navigator.mediaDevices?.getUserMedia === "function" &&
        ["video/mp4", "video/webm"].some((type) => window.MediaRecorder.isTypeSupported?.(type) ?? false)
    );
}
```

### 変更後コード

```ts
// resources/js/lib/capture/still-encode.ts (新規)
/**
 * 静止画テイクのエンコード規約 (**この 2 値の唯一の所在**)。
 * シャッター経路とファイル正規化経路の両方がここから読む (component に直書きしない)。
 *
 * PHP config には置かない — サーバはこの 2 値をまったく使わず、サーバが強制するのは
 * capture.max_still_bytes (バイト数) だけである。使わない値を props で往復させると
 * 二重管理になる。既定値の出力は通常 1 MB 未満で max_still_bytes (16 MiB) に十分収まる。
 */
export const STILL_MAX_EDGE = 1920;
export const STILL_JPEG_QUALITY = 0.85;
export const STILL_CONTENT_TYPE = "image/jpeg";

/** 長辺 STILL_MAX_EDGE に収まる描画寸法 (縮小のみ。拡大はしない) */
export function fitWithinMaxEdge(width: number, height: number): { width: number; height: number } {
    const longest = Math.max(width, height);
    if (longest <= STILL_MAX_EDGE || longest === 0) return { width, height };
    const scale = STILL_MAX_EDGE / longest;
    return { width: Math.round(width * scale), height: Math.round(height * scale) };
}

/**
 * 任意の描画可能ソース (HTMLVideoElement / HTMLImageElement) を JPEG blob へ再エンコードする。
 *
 * ★ **失敗は必ず `null` で返す (reject しない)**。`drawImage()` は tainted canvas 等で throw し、
 *   `toBlob()` も実装によっては throw しうる。呼び出し側に `.catch()` を配って回ると必ず漏れるので、
 *   **契約をこの 1 か所で閉じる** (canvas 2d 取得不可 / 寸法 0 / 例外 / toBlob が null = すべて null)。
 *   呼び出し側は null を見たら**原本を送らずエラー表示する**。
 */
export async function encodeStillJpeg(
    source: CanvasImageSource,
    naturalWidth: number,
    naturalHeight: number,
): Promise<Blob | null> {
    try {
        const size = fitWithinMaxEdge(naturalWidth, naturalHeight);
        if (size.width === 0 || size.height === 0) return null;
        const canvas = document.createElement("canvas");
        canvas.width = size.width;
        canvas.height = size.height;
        const context = canvas.getContext("2d");
        if (context === null) return null;
        context.drawImage(source, 0, 0, size.width, size.height);

        return await new Promise((resolve) => {
            try {
                canvas.toBlob((blob) => resolve(blob), STILL_CONTENT_TYPE, STILL_JPEG_QUALITY);
            } catch {
                resolve(null);
            }
        });
    } catch {
        return null;
    }
}

/**
 * ファイル選択で選ばれた画像を正規化する (再エンコード)。
 * - 断定できること: 出力 JPEG は **EXIF を持たない** ので、サーバ/ffmpeg 側で向きを解釈する必要が無い。
 *   寸法上限も同時に効く。
 * - 断定しないこと: 「<img> デコード時にブラウザが必ず EXIF 向きを適用する」とは書かない
 *   (デコード API とブラウザで差がある)。向きが正しく反映されることは Browser lane
 *   (Chromium + WebKit の 2 レーン契約) で向き付き fixture を使って確認する事項として扱う。
 */
export function normalizeStillFile(file: File): Promise<Blob | null> {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        let settled = false;
        const finish = (value: Blob | null): void => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            URL.revokeObjectURL(url);
            resolve(value);
        };
        const timer = setTimeout(() => finish(null), 5_000);
        image.onload = () => {
            // encodeStillJpeg は reject しない契約だが、二重に閉じる (未処理 rejection を残さない)
            void encodeStillJpeg(image, image.naturalWidth, image.naturalHeight)
                .then(finish)
                .catch(() => finish(null));
        };
        image.onerror = () => finish(null);
        image.src = url;
    });
}
```

```ts
// resources/js/lib/capture/camera.ts (追加)
/**
 * 静止画撮影に必要な能力 (getUserMedia のみ。**MediaRecorder は要らない**)。
 * supportsMediaRecorder() を静止画にも流用すると、MediaRecorder 非対応端末で
 * 撮れるはずの写真まで file input へ落ちてしまう。
 */
export function supportsStillCapture(): boolean {
    return typeof navigator.mediaDevices?.getUserMedia === "function";
}
```

```svelte
<!-- resources/js/components/features/capture/CameraRecorder.svelte -->
<script lang="ts">
    // Props に 1 本追加
    interface Props {
        // ... 既存
        /**
         * 撮影モード (T18x: 静止画カット対応)。**既定は従来どおり "video"**。
         * "still" では MediaRecorder を一切使わず、phase は idle のまま
         * 「録画開始」の位置にシャッターを出す。
         * ★ phase マシン・stream 管理・flip・grid・字幕 overlay・カメラ喪失時の
         *   フォールバック委譲には**一切触れない** (T186 の layout props と同じ作法)。
         */
        mode?: "video" | "still";
    }
    let { /* ... */ mode = "video" }: Props = $props();

    const isStillMode = $derived(mode === "still");

    /**
     * 静止画の撮影。live preview の現在フレームを 1 枚取り出して親へ渡す。
     * ImageCapture API は iOS Safari が未対応で、撮影 PWA の主戦場が iOS Safari のため canvas 経路を採る。
     *
     * ★ 再入ガードと active 通知は **startRecording() と完全に同じ形**にする
     *   (独自フラグを増やさない)。`starting` は「grant 待ちの窓でも preview を開けない」ための
     *   公開 active の構成要素であり、`acquirePreviewStream()` 側には starting の
     *   再入ガードが無いので、先に立てても stream 取得は塞がらない
     *   (現行 startRecording が `starting = true` → `syncActive()` → `acquirePreviewStream()` の順)。
     */
    async function shootStill(): Promise<void> {
        if (starting || resuming || phase !== "idle") return;
        starting = true;
        syncActive(); // 押下時点で active=true (取得中に preview を開かせない)
        try {
            error = null;
            if (stream === null && !(await acquirePreviewStream())) return;
            if (video === null) return;
            // encodeStillJpeg は reject しない契約 (失敗は null)
            const blob = await encodeStillJpeg(video, video.videoWidth, video.videoHeight);
            if (blob === null || blob.size === 0) {
                error = "写真を取得できませんでした。もう一度お試しください。";
                return;
            }
            await onCaptured(blob, STILL_CONTENT_TYPE, null);
        } catch {
            // onCaptured (アップロード) 側の失敗を未処理 rejection にしない (録画経路の onstop と同じ)
            error = "撮影データの処理に失敗しました。もう一度お試しください。";
        } finally {
            starting = false;
            syncActive();
        }
    }
</script>

<!-- 操作行の idle 分岐 -->
{#if phase === "idle"}
    {#if isStillMode}
        <Button variant="primary" onclick={shootStill} testId="shoot-still">
            <Camera class="size-4" aria-hidden="true" />
            写真を撮る
        </Button>
    {:else}
        <Button variant="primary" onclick={startRecording} testId="start-recording">
            <Circle class="size-4" aria-hidden="true" />
            録画開始
        </Button>
    {/if}
    <!-- カメラ反転は両モード共通 (現行のまま) -->
{:else}
```

`onCaptured` の型は `(blob: Blob, mimeType: string, durationMs: number | null) => void | Promise<void>` へ広げる
(親 `Capture/Show.svelte` の `handleCaptured` は既に `number | null` を受ける)。

```svelte
<!-- resources/js/pages/Capture/Show.svelte -->
<script lang="ts">
    // cut の計画で撮影モードを決める (撮影者に判断させない = 使命)
    const captureMode = $derived(selectedCut?.material_type === "still" ? "still" : "video");
    // 静止画は MediaRecorder を必要としない
    const canCapture = $derived(
        typeof window !== "undefined" &&
            (captureMode === "still" ? supportsStillCapture() : supportsMediaRecorder()),
    );
    const showRecorder = $derived(canCapture && cameraUnavailableReason === null);
</script>

<CameraRecorder
    ...
    mode={captureMode}
/>
...
<CaptureFileFallback
    material={captureMode}
    onCaptured={(blob, contentType) => handleCaptured(blob, contentType, null)}
/>
```

```svelte
<!-- resources/js/components/features/capture/CaptureFileFallback.svelte -->
<script lang="ts">
    interface Props {
        /** cut の計画。still なら画像を選ばせ、正規化してから親へ渡す */
        material?: "video" | "still";
        onCaptured: (blob: Blob, contentType: string) => void | Promise<void>;
    }
    let { material = "video", onCaptured }: Props = $props();

    const accept = $derived(material === "still" ? "image/*" : "video/*");

    async function handleChange(): Promise<void> {
        const file = input?.files?.[0];
        if (!file) return;
        const expected = material === "still" ? "image/" : "video/";
        if (!file.type.startsWith(expected)) {
            error = material === "still"
                ? "画像ファイルを選択してください。"
                : "動画ファイルを選択してください。";
            return;
        }
        if (material === "video") {
            await onCaptured(file, file.type.split(";")[0]);
            return;
        }
        // 静止画は必ず再エンコードして送る (寸法上限 + EXIF を落とす)
        const normalized = await normalizeStillFile(file);
        if (normalized === null) {
            error = "画像を読み込めませんでした。別のファイルをお試しください。";
            return; // ★ 原本は送らない
        }
        await onCaptured(normalized, STILL_CONTENT_TYPE);
    }
</script>
```

`TakeFileUpload.svelte` (PC) も同じ規則で `material` props を受け、
still のときは `readDurationMs()` の尺チェックを**行わず** (画像に尺は無い) 正規化して送る。

### PHPStan適合チェック

該当なし (フロント施策)。TypeScript 側は `pnpm typecheck` で:

- [x] `mode` / `material` は literal union で受ける (`string` にしない)
- [x] `onCaptured` の `durationMs` は `number | null` (静止画で `0` を送らない)
- [x] `encodeStillJpeg` の戻りは `Blob | null` で、null 分岐を呼び出し側に強制する

### テスト計画

- [ ] 新規 `tests/js/lib/capture/still-encode.test.ts` —
      `fitWithinMaxEdge` (縮小 / 等倍 / 0 除算) / `encodeStillJpeg` が canvas 取得失敗で null /
      `toBlob` が null を返したら null
- [ ] `tests/js/components/features/capture/CameraRecorder.test.ts` 更新 —
      `mode="still"` で `shoot-still` が出て `start-recording` が**出ない** /
      押下で `onCaptured(blob, "image/jpeg", null)` が呼ばれる / **phase は idle のまま**
      (録画モードの既存テストが 1 件も変わらないこと = 回帰の証明)
- [ ] 新規 `tests/js/components/features/capture/CaptureFileFallback.test.ts` —
      `material="still"` で accept が `image/*` / 正規化失敗時に**原本を送らない**
- [ ] Browser lane (Chromium + WebKit の 2 レーン契約) —
      向き情報付き JPEG の fixture を選択したとき、出力の縦横が期待どおりになる
- [ ] `tests/js/pages/Capture/Show.test.ts` (既存名は実装時に確認) —
      still カット選択でシャッターが出る / video カットで録画ボタンが出る

### リスク

- **`CameraRecorder` は既に 647 行で phase マシンが複雑**。`mode` は
  「表示の分岐 + `shootStill` の 1 経路」だけを足し、既存の `phase` / `recorder` /
  `pendingOperation` には触れない。触っていないことを既存テストが 1 件も変わらないことで示す。
- **`starting` を shootStill でも立てる**のは preview 排他 (`captureActive`) を録画と同じにするため。
  立て忘れると getUserMedia 取得中に `TakePreviewDialog` が開いて stream を横取りする。
  **現行コードを確認済み**: `acquirePreviewStream()` → `acquireStream()` には `starting` の
  再入ガードが無い (`stream ??= await getUserMedia(...)` のみ) ため、先に立てても取得は塞がらない。
- iOS Safari の `canvas.toBlob` は対応済みだが、`getUserMedia` の grant 前は `videoWidth === 0` になる。
  `fitWithinMaxEdge` の 0 分岐と `blob.size === 0` の分岐で詰ませない。

---

## S6: 静止画テイクの表示とサムネイル

### 変更箇所

- `app/Services/Capture/TakeThumbnailExtractor.php` (interface のシグネチャ)
- `app/Services/Capture/FfmpegTakeThumbnailExtractor.php` (`extract` の seek 方針)
- `app/Services/Capture/TakeThumbnailPipeline.php` (`extract` 呼び出し)
- `app/DataTransferObjects/Capture/CaptureTakeData.php` (`material_type` 追加)
- `app/DataTransferObjects/Manual/SelectableTakeData.php` (`material_type` 追加)
- `resources/js/components/features/capture/TakeStrip.svelte` (プレースホルダのアイコン)
- `resources/js/components/features/capture/TakePreviewDialog.svelte` (`<img>` 出し分け)
- `resources/js/components/features/manual/TakePreviewPanel.svelte` (`<img>` 出し分け)

### 波及変更

- TypeScript 型定義: `CaptureTake.material_type` / `SelectableTake.material_type`
- API Resource/DTO: 上記 2 DTO の `toArray()` 戻り型 docblock
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` のキー集合契約、
  `tests/js/components/features/capture/TakeStrip.test.ts` 等

### 現行コード

```php
// app/Services/Capture/FfmpegTakeThumbnailExtractor.php
public function extract(string $localVideoPath, string $localThumbnailPath): void
{
    $seekMs = config()->integer('capture.thumbnail_seek_ms');

    $failure = $this->attempt($localVideoPath, $localThumbnailPath, $seekMs);
    if ($failure !== null && $seekMs > 0) {
        $failure = $this->attempt($localVideoPath, $localThumbnailPath, 0);
    }
    if ($failure !== null) {
        throw new TakeThumbnailExtractionException($failure);
    }
}
```

```php
// app/DataTransferObjects/Capture/CaptureTakeData.php (toArray)
'has_thumbnail' => $this->take->status === TakeStatus::Ready
    && $this->take->thumbnail_path !== null,
```

### 変更後コード

```php
// app/Services/Capture/TakeThumbnailExtractor.php (interface)
/**
 * 素材種別を受け取り、seek 方針を実装側が決める。
 * 静止画に「1 秒地点」は存在しないため、種別を知らずに seek を決められない。
 */
public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void;
```

```php
// app/Services/Capture/FfmpegTakeThumbnailExtractor.php
public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void
{
    // 静止画に「1 秒地点」は無い。seek=0 の 1 回で決める
    // (動画既定の 1000ms を当てると 1 回目が必ず空振りし、無駄な ffmpeg 実行が 1 回増える)。
    if ($material === MaterialType::Still) {
        $failure = $this->attempt($localSourcePath, $localThumbnailPath, 0);
        if ($failure !== null) {
            throw new TakeThumbnailExtractionException($failure);
        }

        return;
    }

    $seekMs = config()->integer('capture.thumbnail_seek_ms');
    $failure = $this->attempt($localSourcePath, $localThumbnailPath, $seekMs);
    if ($failure !== null && $seekMs > 0) {
        // 尺が seek より短いと 1 フレームも出力されない。先頭で 1 回だけ再試行する
        $failure = $this->attempt($localSourcePath, $localThumbnailPath, 0);
    }
    if ($failure !== null) {
        throw new TakeThumbnailExtractionException($failure);
    }
}
```

```php
// app/Services/Capture/TakeThumbnailPipeline.php (run の中)
$this->storage->downloadToLocal($take->video_path, $source);
$this->extractor->extract($source, $thumbnail, $take->material_type);
```

```php
// app/DataTransferObjects/Capture/CaptureTakeData.php
/**
 * @return array{id: int, client_take_id: string, status: string, material_type: string,
 *   size_bytes: int, duration_ms: int|null, comment: string|null, captured_at: string|null,
 *   sort_order: int, downloaded: bool, has_thumbnail: bool, playback_url: string|null,
 *   download_ack_token: string|null}
 */
public function toArray(): array
{
    return [
        'id' => $this->take->id,
        'client_take_id' => $this->take->client_take_id,
        'status' => $this->take->status->value,
        // 登録された素材の実体 (NOT NULL)。cut 側の計画 (nullable) とは別のキーである。
        // UI はこの値で <video> と <img> を出し分ける
        'material_type' => $this->take->material_type->value,
        // ... 以下現行どおり
    ];
}
```

```svelte
<!-- resources/js/components/features/capture/TakePreviewDialog.svelte -->
<!--
  ★ 読み込み失敗の受け皿を必ず置く。素材種別は**申告 Content-Type からの分類**であり
    実体の形式を保証しないため (docs/architecture.md の非保証)、
    still と申告された実体がデコードできない場合に「何も出ない」状態を作らない。
    <video> 側には足さない (既存挙動を変えないため。非対称は意図的)。
-->
{#if take.material_type === "still"}
    {#if imageFailed}
        <p class="p-6 text-center text-caption text-text-secondary" role="status" data-testid="take-preview-unavailable">
            このテイクはプレビューできません。
        </p>
    {:else}
        <img
            src={playbackUrl ?? undefined}
            alt={`${cutLabel} のテイク`}
            class="w-full"
            onerror={() => (imageFailed = true)}
            data-testid="take-preview-image"
        />
    {/if}
{:else}
    <!-- svelte-ignore a11y_media_has_caption -->
    <video ... data-testid="take-preview-video"></video>
{/if}
```

`imageFailed` は `$state(false)`。**`{#key}` に頼ってリセットしない** —
`{#key}` は DOM を作り直すが `<script>` の `$state` は再生成されないため、前のテイクの失敗が残る。
`take.id` (と `playbackUrl`) の変化を `$effect` で監視して明示的に `false` へ戻す。

`TakePreviewPanel.svelte` (PC) も同じ分岐 + 同じ受け皿。`TakeStrip.svelte` の**未生成プレースホルダ**は
`Film` の代わりに still で `Image` アイコンを出す (寸法は変えない = レイアウトが跳ねない)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`extract(): void`)
- [x] null 安全 (`$take->material_type` は NOT NULL + enum cast なので `?->` 不要)
- [x] DTO を返している (`CaptureTakeData` / `SelectableTakeData`)
- [x] interface のシグネチャ変更は実装 1 件のみ (`FfmpegTakeThumbnailExtractor`) で、
      Fake が無いことを実装時に確認する (あれば同時に更新)

### テスト計画

- [ ] `tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php` —
      `MaterialType::Still` で `-ss 0.000` の**1 回だけ**実行される (再試行しない) /
      `Video` は現行どおり 1000ms → 失敗時 0ms の 2 回
- [ ] `tests/Feature/Capture/TakeThumbnailTest.php` (既存名は実装時に確認) —
      静止画テイクでもサムネイルが生成され `has_thumbnail=true` になる
- [ ] `tests/Feature/Capture/CaptureManualBrowsingTest.php` — takes のキー集合に `material_type` が入る
- [ ] `tests/js/components/features/capture/TakePreviewDialog.test.ts` —
      still で `take-preview-image` / video で `take-preview-video` /
      `<img>` の error 発火で `take-preview-unavailable` に差し替わる /
      テイクを切り替えると失敗状態がリセットされる
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`TakeThumbnailExtractor` interface の変更は破壊的**。実装は 1 件だが、テスト用の Fake があれば同時に直す。
  漏れは PHPStan が interface 不一致で落とす。
- 静止画テイクのサムネイルも `thumbnail_size_bytes` として**事後計上**される (既存の受容済み挙動)。
  静止画のサムネイルは小さいので影響は動画より小さい。

---

## S7: 素材登録状況 (未登録 / 動画登録済 / 静止画登録済) の表示

### 変更箇所

- `app/DataTransferObjects/Manual/CutTakeSummaryData.php`
- `app/DataTransferObjects/Manual/TakeSelectionPageData.php` (cut の `material_type`)
- `app/DataTransferObjects/Capture/CaptureCutData.php` (cut の `material_type`)
- `app/DataTransferObjects/Manual/ScenarioStepData.php` / `ScenarioPointData.php` (`?string` → `?MaterialType`)
- `resources/js/components/features/manual/ScenarioEditor.svelte` (動画列のバッジ)
- `resources/js/types/manual.ts` / `resources/js/types/capture.ts`

### 波及変更

- **eager load の列制限は無い (確認済み・追加変更は不要)**。`adoptedTake` を読む 3 箇所は
  いずれも列を絞っていないため、`material_type` は自動的に載る:
  - `app/Services/Manual/CutSequencer.php:26` — `->with('adoptedTake')`
  - `app/Http/Controllers/Projects/VideoManualController.php:238` — `->with('adoptedTake')`
  - `app/DataTransferObjects/Manual/TakeSelectionPageData.php:38` — `->loadMissing('adoptedTake')`
  `adoptedTake:id,status` のような select 制限は 1 件も存在しない (実装時に再確認は不要)。
- TypeScript 型定義: `CutTakeSummary.adopted.material_type` / `TakeSelectionCut.material_type` /
  `CaptureCut.material_type`
- API Resource/DTO: `ScenarioResource` の戻り型 docblock (`material_type` の型表記は `string|null` のまま)
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts` /
  `tests/Feature/Projects/ScenarioUpdateTest.php` / `tests/js/pages/ManualsEdit.test.ts`

### 現行コード

```php
// app/DataTransferObjects/Manual/CutTakeSummaryData.php
public function __construct(
    public int $cutId,
    public int $takesCount,
    public ?int $adoptedId,
    public ?string $adoptedStatus,
) {}

/**
 * @return array{cut_id: int, takes_count: int, adopted: array{id: int, status: string}|null}
 */
public function toArray(): array
{
    return [
        'cut_id' => $this->cutId,
        'takes_count' => $this->takesCount,
        'adopted' => $this->adoptedId === null || $this->adoptedStatus === null
            ? null
            : ['id' => $this->adoptedId, 'status' => $this->adoptedStatus],
    ];
}
```

```svelte
<!-- resources/js/components/features/manual/ScenarioEditor.svelte (動画列) -->
<p class="mt-1 flex items-center gap-2 text-caption text-text">
    <span data-testid="video-cell-count">テイク {summary?.takes_count ?? 0} 件</span>
    {#if summary?.adopted}
        <Badge tone="primary" testId="video-cell-adopted">採用済み</Badge>
    {/if}
</p>
```

### 変更後コード

```php
// app/DataTransferObjects/Manual/CutTakeSummaryData.php
/**
 * シナリオ編集画面「動画」列の 1 カット分。
 *
 * 採用テイクは `adopted` キーで返す — 採用テイク外部キーの識別子は
 * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
 * 表示のために security gate の allowlist を広げないための命名である。
 *
 * ★ **素材登録状況 (doc/02 §2.4 の 3 値) の材料をここで出す**。
 *   判定に使うのは「採用テイクが在るか」と「その material_type」の 2 つだけで、
 *   **ready 判定 (AdoptedReadyTakeCoverage の述語) は再実装しない** (ドメイン規約 12)。
 */
public function __construct(
    public int $cutId,
    public int $takesCount,
    public ?int $adoptedId,
    public ?string $adoptedStatus,
    public ?MaterialType $adoptedMaterialType,
) {}

public static function fromCut(Cut $cut): self
{
    $takesCount = $cut->getAttribute('takes_count');
    Assert::integer($takesCount, 'withCount(takes) 済みの cut を渡してください');
    $adopted = $cut->adoptedTake;

    return new self(
        cutId: $cut->id,
        takesCount: $takesCount,
        adoptedId: $adopted?->id,
        adoptedStatus: $adopted?->status->value,
        adoptedMaterialType: $adopted?->material_type,
    );
}

/**
 * @return array{cut_id: int, takes_count: int,
 *   adopted: array{id: int, status: string, material_type: string}|null}
 */
public function toArray(): array
{
    // id / status / material_type は同時に決まる (すべて null か、すべて非 null)
    if ($this->adoptedId === null || $this->adoptedStatus === null || $this->adoptedMaterialType === null) {
        return ['cut_id' => $this->cutId, 'takes_count' => $this->takesCount, 'adopted' => null];
    }

    return [
        'cut_id' => $this->cutId,
        'takes_count' => $this->takesCount,
        'adopted' => [
            'id' => $this->adoptedId,
            'status' => $this->adoptedStatus,
            'material_type' => $this->adoptedMaterialType->value,
        ],
    ];
}
```

```php
// app/DataTransferObjects/Manual/ScenarioStepData.php / ScenarioPointData.php
public ?MaterialType $materialType,   // ?string から狭める
// fromCut: materialType: $cut->material_type,
// toArray: 'material_type' => $this->materialType?->value,
```

```svelte
<!-- resources/js/components/features/manual/ScenarioEditor.svelte (動画列) -->
<p class="mt-1 flex items-center gap-2 text-caption text-text">
    <span data-testid="video-cell-count">テイク {summary?.takes_count ?? 0} 件</span>
    <!--
      素材登録状況 (doc/02 §2.4 の 3 値)。「採用テイクが在るか」と「その素材種別」だけで決める。
      ready かどうかは別軸なので、ここでは言わない (充足の告知は既存の詳細画面 props が担当)。
    -->
    {#if summary?.adopted}
        <Badge tone="primary" testId="video-cell-material">
            {summary.adopted.material_type === "still" ? "静止画登録済" : "動画登録済"}
        </Badge>
    {:else}
        <Badge tone="neutral" testId="video-cell-material">未登録</Badge>
    {/if}
</p>
```

```ts
// resources/js/types/manual.ts
export type CutMaterialType = "video" | "still";

export interface CutTakeSummary {
    cut_id: number;
    takes_count: number;
    /** 採用テイク。material_type は**実体** (NOT NULL) */
    adopted: { id: number; status: SelectableTakeStatus; material_type: CutMaterialType } | null;
}

export interface TakeSelectionCut {
    // ...
    /** カットの**計画** (未指定あり)。ファイル選択の accept と撮影 UI の出し分けに使う */
    material_type: CutMaterialType | null;
    adopted: { id: number; status: SelectableTakeStatus; material_type: CutMaterialType } | null;
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`toArray()` の array shape を更新)
- [x] null 安全 (3 つの null を**同時に**判定してアーリーリターン。個別 null 合体で shape が崩れない)
- [x] DTO を返している
- [x] `ScenarioStepData` / `ScenarioPointData` を `?MaterialType` に狭めた結果、
      `ScenarioResource` の docblock (`material_type: string|null`) は**変わらない** (JSON は文字列のまま)

### テスト計画

- [ ] `tests/Feature/Projects/ScenarioEditPropsTest.php` (既存名は実装時に確認) —
      未採用 → `adopted === null` / 動画採用 → `material_type === "video"` /
      静止画採用 → `material_type === "still"`
- [ ] `tests/js/components/features/manual/ScenarioEditor.test.ts` —
      3 状態でバッジの文言が `未登録` / `動画登録済` / `静止画登録済` になる
- [ ] `tests/Feature/Projects/ScenarioUpdateTest.php` — `material_type` の往復が
      `MaterialType` 経由になっても JSON の値が変わらないこと (回帰)
- [ ] `tests/js/pages/ManualsEdit.test.ts` — props 型変更の追随
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`adopted` の shape 変更は 2 画面 (シナリオ編集 / PC テイク選択) に波及する**。
  TS 側の型を先に変えれば `pnpm typecheck` が全参照点を挙げる。
- **`未登録` バッジを新設すると「採用テイクはあるが processing 中」も「動画登録済」と出る**。
  これは意図どおり (素材登録状況は「登録されたか」であって「使えるか」ではない)。
  「使えるか」の告知は既存の詳細画面 props (`AdoptedReadyTakeCoverage` 由来) が別に持っており、
  **2 つの軸を 1 つのバッジに混ぜない**。

---

## S8: 通しのテストとドキュメント

### 変更箇所

- `tests/Feature/Manual/StillMaterialConsistencyTest.php` (新規。C1〜C5 の通し)
- `docs/architecture.md` (§撮影 PWA / §レンダ に追記)

### 波及変更

- なし (テストとドキュメントのみ)

### テスト計画 (組み合わせ表)

| # | cut.material_type | take.material_type | 固定する内容 |
|---|---|---|---|
| C1 | still | still | presign 200 (S3 キー `.jpg`) / 登録で `material_type=still` `duration_ms=null` / サムネイル生成 / マニフェスト `TakeStill` + `stillDisplaySeconds` 非 null / `<img>` プレビュー |
| C2 | still | video | **既存挙動そのまま** (先頭フレーム抽出 / `<video>` プレビュー) |
| C3 | video | video | **既存挙動そのまま** (`TakeVideo` / 尺ゲートが `duration_ms` を使う) |
| C4 | video または未指定 | still を上げようとする | presign が 422 (**予約行が作られない = 容量を消費しない**) |
| C5 | video (採用後に still→video へ編集) | still | マニフェストが `TakeStill` **かつ** 尺ゲートが `secondsFor()*1000` を加算する |

#### 誤申告 (申告 Content-Type と実体の不一致) の扱い — 2 方向で非対称

**この非対称を取り違えない**:

| 誤申告の向き | 起きること | 判定 |
|---|---|---|
| `image/jpeg` と申告して**動画**バイト列を置く | `material_type=still` → `planTakeStill()` の `-frames:v 1` が**動画からも 1 枚出す** = **C2 とまったく同じ経路で成功する** | **害が無い**。テストの題材にしない |
| `video/mp4` と申告して**画像**バイト列を置く | `material_type=video` → `planTakeVideo()` → `probeDurationMs()` の ffprobe が `format=duration` を数値で返せない → `RenderCompositionException` | **失敗ジョブになる**。これが固定すべき挙動 |

- [ ] **デコード不能・尺不明の素材は failed job になる**: `video/mp4` 申告 + 画像バイト列のテイクを
      採用したレンダが `failed` で終わる。ffmpeg / ffprobe は `Process::fake()` で非 0 終了 (または
      非数値出力) を返させる (**実バイナリに依存しない**)
- [ ] **壊れた成果物を出さない**: 失敗ジョブの `output_path` が null のまま /
      `video_manuals.status` が `rendering` に残らない
- [ ] **後続ジョブが処理可能**: 失敗ジョブの後に別の render job が正常に完了できる

**失敗地点ごとにテスト契約を分ける (現行の制御フローを読んで確定)**。
`RenderPipeline::run()` は
`startJob → buildManifest → downloadSources → compose → updateProgress → assertStillOwned
→ storage->upload → $uploadedKey = ... → finalize` の順で、`finally` の削除条件は
`$uploadedKey !== null` である。したがって:

| 失敗地点 | `$uploadedKey` | 固定する内容 |
|---|---|---|
| `compose()` (今回の題材 = ffprobe が尺を返さない) | **null のまま** | `upload()` が**呼ばれない** / `status=failed` / `output_path === null` / `video_manuals.status` が `rendering` に残らない |
| `finalize()` (既存契約の回帰) | 非 null | `finally` で `delete($uploadedKey)` が呼ばれる |
| `upload()` の途中 | **null のまま** | **保証しない** (下記) |

- [ ] compose 失敗のテストで**「孤児削除」を期待しない** (この地点では `upload()` 自体が未実行のため
      検証できない)。孤児削除は `finalize()` 失敗の**別テスト**で固定する
      (**本施策が作る挙動ではない**ので、既存テストが同等のものを持っていれば新設しない。実装時に確認する)
- [ ] **`upload()` 途中失敗の部分オブジェクトは保証しない**。`$uploadedKey` への代入は
      `upload()` の**次の行**にあるため、途中失敗した PUT は `finally` の削除対象にならない。
      これは本施策が作る問題ではなく現行構造の性質であり、`docs/architecture.md` に
      **未軽減リスクとして記録する** (`-max_alloc` の残余と同じ扱い。誇張しない)
- [ ] Quota: 静止画の presign → 登録 → `bytes_pending` 解放 → `bytes_used` 加算 の 1 巡

### ドキュメント (`docs/architecture.md`)

追記する内容 (**保証しないものを誇張せずに書く**):

1. **素材種別の 2 層**: `cuts.material_type` = 計画 (nullable) / `takes.material_type` = 実体 (NOT NULL)。
   実効判定は `EffectiveMaterialType` が唯一の所在で、**実体優先**である理由 (採用後に計画を戻せる)。
2. **受け入れの非対称**: 入口 (presign) は video カットへの画像を 422 で止める / レンダは実体優先で受ける。
   理由 (容量を無駄にしない / 既にあるデータで詰ませない)。
3. **静止画の表示秒**: `StillDisplayDuration` が唯一の所在。`preview_placeholder_seconds` の流用を撤去した。
   **挙動変更**: 未指定 still の尺ゲート計上が 60 秒 → 5 秒になる (レンダの実尺と一致させる是正)。
4. **doc/02 §2.2 のナレーション尺優先は v1 では実装しない**。理由 (TTS が無く比較対象が存在しない) と
   再検討条件 (TTS 導入時)、変更点が `StillDisplayDuration` 1 か所に閉じること。
5. **保証しないもの**:
   - `takes.material_type` は**申告 Content-Type からの分類**であり、S3 のバイト列の実際の形式を保証しない。
     誤申告の帰結は**向きによって非対称**である:
     「still と申告して動画を置いた」場合は先頭フレーム抽出で**成功しうる** (C2 と同じ経路。害は無い)。
     「video と申告して画像を置いた」場合は ffprobe が尺を取れず**失敗ジョブ**になる。
     また `<img>` プレビューは実体がデコード不能なら壊れ得るため、UI に読み込み失敗の受け皿を置いている。
     同期の実体検証 (S3 GET + ffprobe) は採らない (登録は一括再送される経路であり、
     数十 MB のダウンロードを同期で挟むと 409 の窓が開く)。
   - `-max_alloc` は**1 回の heap 確保**の上限であって、プロセス全体の RSS 上限でも同時実行数の上限でもない。
     worker のメモリ cgroup 制限は本リポジトリに存在せず、本施策でも新設しない
     (デプロイ定義が無いため)。**未軽減リスクとして記録する**。
   - `FfmpegProcessLaunchInventoryTest` は字句走査であり、動的に組み立てたコマンド配列や
     vendor 内部からのプロセス起動には沈黙する。
   - **レンダ成果物の `upload()` が途中で失敗したときの部分オブジェクトは削除されない**。
     `RenderPipeline::run()` の `$uploadedKey` への代入は `upload()` の**次の行**にあり、
     `finally` の後始末は「アップロードが完了したが succeeded に到達しなかった」場合しか拾わない。
     これは本施策が作る問題ではなく現行構造の性質である (**未軽減**)。
   - migration の backfill は「既存テイクは全件動画」という前提に立つ。根拠は
     presign が `allowed_video_content_types` しか通していないこと。
6. **サムネイル**: 静止画テイクも対象に含める (一覧に原本を貼らないため)。seek は still で 0 固定。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 8 施策が 1 本の鎖 (schema → 受け入れ → レンダ整合 → 撮影 → 表示) で、途中で切ると「静止画を撮れるがレンダで壊れる」状態を main に置くことになる。同一ブランチで (1) サーバ [S1〜S4] → (2) PWA/PC 撮影 [S5] → (3) 表示 [S6/S7] → (4) 通し [S8] の順に積み、**完了報告は S8 の通しテストが緑になってから**行う。 |
| 競合リスク | `RenderPipeline` / `RenderJobService` はドメイン規約 1 の inventory 対象ファイルだが、本施策は `cuts` / `video_manuals` を**書かない**ので inventory の変更は不要。`CameraRecorder.svelte` は T186 (横持ち全画面) が直近で触っているため、`mode` props は `layout` props と同じ「表示分岐のみ」の作法に揃えて衝突面を最小化する。`config/manual.php` / `config/capture.php` は他タスクと衝突しやすいので、キー追加は末尾ではなく意味の近い位置へ 1 か所ずつ入れる。 |

### 実装順序 (チェックリスト)

1. [ ] S1 migration + Model + Factory + 分類器 (+ Unit テスト)
2. [ ] S4 `-max_alloc` (S2 で画像を受け入れる**前**に防波堤を入れる)
3. [ ] S2 presign / 登録 (+ Feature テスト C4 を含む)
4. [ ] S3 `EffectiveMaterialType` / `StillDisplayDuration` / `takeSourcePath` 改名 (+ C1/C2/C3/C5)
5. [ ] S6 サムネイル seek + DTO の `material_type`
6. [ ] S5 撮影 PWA / PC のシャッター・正規化
7. [ ] S7 素材登録状況の表示
8. [ ] S8 通しテスト + `docs/architecture.md`
9. [ ] `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
       `pnpm typecheck` / `pnpm test` / `pnpm build` が全 green
