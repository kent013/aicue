# アプリの使命 (AGENTS.md より)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項 (AGENTS.md より)

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel 12 + Svelte 5 (runes) + Inertia.js の実装をレビューするコードレビュアーである。
対象は TODO T192 「静止画カットの撮影と反映」の実装差分である。

## レビュー観点

1. **詳細設計との一致性**: 設計書の各施策 (S1〜S8) が実装されているか。逸脱があるなら妥当か
2. **正確性**: 論理バグ・境界条件・並行性 (ロック順序 / CAS / 冪等性)・null 安全
3. **PHPStan level 10 適合**: 型の widen / @phpstan-ignore / baseline 化をしていないか
4. **DTO / JsonResource パターン**: response()->json() の直書きが無いか。array shape の docblock が実体と一致するか
5. **テスト網羅性**: 各施策にテストがあるか。degenerate PASS (何も検査していないのに緑) が無いか。
   テストデータが Factory 経由か。個別 DatabaseTransactions を使っていないか
6. **セキュリティ**: tenant キー不信 / 子は親に属する (404 が認可より前) / cross-org 不可 /
   mass assignment 保護キー / 署名 URL・保存パスを props に載せていないか
7. **DESIGN.md 準拠**: color / radius / typography は design token 経由。hex 直書き (#RRGGBB) を増やしていないか
8. **Atomic Design 準拠**: resources/js/components/ の atoms → molecules → organisms → features/{domain}
   → templates → pages の単方向 import。アイコンは @lucide/svelte のみ (SVG 直書きを増やさない)
9. **保証しないものを誇張していないか**: docs/architecture.md の追記が実装より強い保証を主張していないか

## 出力形式

ファイルごとに判定を書き、指摘は Critical / Warning / Suggestion に分類すること。
最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示すること。
指摘には必ず「なぜ問題か」と「どう直すか」を書くこと。

---

## 詳細設計書

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


## design system 参照 (DESIGN.md)

---
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
colors:
    primary: "#2563EB"
    primary-hover: "#1D4ED8"
    tertiary: "#0F766E"
    tertiary-hover: "#115E59"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#15803D"
    warning: "#B45309"
    danger: "#B91C1C"
typography:
    display:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 48px
        fontWeight: 500
        lineHeight: 1.2
        letterSpacing: 0.02em
    h1:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 32px
        fontWeight: 500
        lineHeight: 1.3
        letterSpacing: 0.02em
    h2:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 24px
        fontWeight: 500
        lineHeight: 1.4
    h3:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 18px
        fontWeight: 500
        lineHeight: 1.5
    body:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 16px
        fontWeight: 400
        lineHeight: 1.7
    caption:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 12px
        fontWeight: 400
        lineHeight: 1.5
rounded:
    sm: 4px
    md: 6px
    lg: 8px
spacing:
    xs: 4px
    sm: 8px
    md: 16px
    lg: 24px
    xl: 40px
---

# Design System

本ファイルが**デザインの canonical source**。`resources/css/tokens.css` はその実装写像であり、
独自に値を変えてはいけない(同期契約は `docs/design-system.md`)。

## Overview

テンプレート既定のニュートラルテーマ。中立的な青(#2563EB)を主役、teal(#0F766E)を強アクセント、
無彩のスレート(#F4F4F5)を背景に据える。**アプリ固有のテーマは frontmatter の色値と
tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。

## Colors

色は意味で割り当てる。順序や見た目の好みで使い分けない。

- **Primary(#2563EB)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
  1 画面の主要 CTA 以外には濫用しない。
  - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
- **Tertiary(#0F766E)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
  1 画面に 1 箇所が原則。
  - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
- **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
  - tailwind: `bg-neutral`
- **Surface(#FFFFFF)**: カード・モーダル・浮いた要素の背景。Neutral との明度差で奥行きを出す。
  - tailwind: `bg-surface`
- **Border(#E4E4E7)**: 区切り線、入力欄の枠。常に細く(1px)。
  - tailwind: `border-border`
- **Border Strong(#A1A1AA)**: 区切りの強調、ghost ボタンの枠。
  - tailwind: `border-border-strong`
- **Text Primary(#18181B)**: 本文・見出しの主たる色。純黒は使わない。
  - tailwind: `text-text`(`--color-text` を参照)
- **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
  - tailwind: `text-text-secondary`

### 状態色

- **Success(#15803D)**: 完了・正常・公開済み。
  - tailwind: `text-success`, `bg-success`, `border-success`
- **Warning(#B45309)**: 注意・確認が必要・保留。
  - tailwind: `text-warning`, `bg-warning`, `border-warning`
- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
  (Tertiary は前向きな強調、Danger は否定的なシグナル)。
  - tailwind: `text-danger`, `bg-danger`, `border-danger`

状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
検討すること**(追加条件は `docs/design-system.md` の 4 条件)。

## Typography

全ランプ Noto Sans JP。フォントウェイトは **400 と 500 の 2 階層のみ**(700 は使わない)。
コード・識別子・数値整列には `font-mono` を許可する(日本語 prose には使わない)。

### Typography ramp utility

各 ramp は `resources/css/tokens.css` の `@utility` で定義済。実装はこの utility を
そのまま class として適用する。**raw の `text-sm` / `font-bold` 等は禁止**(ds-purity が検出)。

- **text-display**: 48px / 500 / lh 1.2 / ls 0.02em — tailwind: `text-display`
- **text-h1**: 32px / 500 / lh 1.3 / ls 0.02em — tailwind: `text-h1`
- **text-h2**: 24px / 500 / lh 1.4 — tailwind: `text-h2`
- **text-h3**: 18px / 500 / lh 1.5 — tailwind: `text-h3`
- **text-body**: 16px / 400 / lh 1.7 — tailwind: `text-body`
- **text-caption**: 12px / 400 / lh 1.5 — tailwind: `text-caption`

役割マッピング: 本文/入力値/主要数値 → `text-body`、ラベル/補助情報/日時 → `text-caption`、
page タイトル → `text-h1`/`text-h2`、section/card 見出し → `text-h3`。
強調は `font-medium`(500)を上限とし、足りなければ weight を上げず ramp 昇格+余白+
色階層(text vs text-secondary)でコントラストを作る。

## Layout

8px ベースのスケール。要素間は `md (16px)` を基本に、セクション間は `xl (40px)`。
コンテナは最大幅 1080px を目安に、画面の左右に 32px の余白を確保する。

## Elevation & Depth

**`box-shadow` は使わない。** Neutral(背景)と Surface(カード)の明度差、および 1px の
ボーダーで階層を表現する。ホバー時も影を出さず、ボーダー色や文字色の変化で反応を示す。
グラデーション・scale 効果も使わない。

## Shapes

角丸 ramp は **`rounded-sm`(4px)/ `rounded-md`(6px)/ `rounded-lg`(8px)の 3 段のみ**。
DOM 役割で選ぶ(上から優先): カード・モーダル=`lg` / 中間 box(パネル・`<pre>`)=`md` /
ボタン・入力・バッジ等の小コントロール=`sm`。
素の `rounded`・`rounded-xl` 以上・任意値・方向別(`rounded-t-*` 等)は使わない。
完全円(`rounded-full`)はアバター/status dot/トグル等の**真に円形な UI に限る** ramp 外の例外で、
file-scoped allowlist で個別管理する。

## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。

### Button

実装: `components/atoms/Button.svelte`(仕様の真実は `Button.types.ts`)。

| variant | 用途 | スタイル要旨 |
|---------|------|------------|
| `primary` | 主要 CTA(1 画面 1 つ目安) | bg-primary + text-neutral |
| `tertiary` | 真に重要な前向き CTA(1 画面 1 箇所) | bg-tertiary + text-neutral |
| `ghost` | 補助・キャンセル | 透明 + border-border-strong、hover で primary 化 |
| `neutral` | 取消可能・UI-only の補助操作(一時停止等) | bg-neutral + 常時 border(境界確保) |
| `success` | 肯定操作(追加・承認・付与) | bg-success + text-neutral |
| `danger` | dialog/form の主破壊 CTA | bg-danger + text-neutral |
| `danger-outline` | section 単位の破壊(card 内の削除) | border-danger、hover で塗り |
| `danger-ghost` | dense な row/list 内の破壊アクション | text-danger + 透明、hover で淡い tint |

- **全 variant が border(透明 or 色)を持ち外形高さを統一する**
- danger 系は irreversible / destructive 操作専用(削除・revoke・移譲・再開不可の中断)。
  危険度ではなく**配置文脈**で 3 重みを選ぶ
- **anchor 対応**: `href` 指定で `<a>`(`inertia` 指定で Inertia Link)。anchor モードでは
  `type`/`disabled` は型レベルで禁止。`target="_blank"` には `rel="noopener noreferrer"` を自動補完
- **iconOnly**: `ghost` / `neutral` / `danger-ghost` のみ許可。`ariaLabel` が型で必須
- **disclosure**: button モード限定で `ariaExpanded` / `ariaControls` / `element`(bindable な
  `HTMLButtonElement` 参照)を受ける。ハンバーガー等のトグルはこれを使い素の `<button>` を書かない
- size: `sm`(caption)/ `md`(既定)/ `lg`(form 入力面との高さ整合限定)

### Input / Textarea / Select(入力系 atom)

実装: `components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte`。
見た目は `components/atoms/input-state.ts`(`INPUT_BASE_CLASSES` + `inputStateClass`)に集約し、
入力系 atom 間で統一する。`error` prop で danger 枠と `aria-invalid` が連動する。
`aria-describedby` 等は restProps で透過。Select の `<option>` 群は呼び出し側が
children snippet として記述する。Input の `type` は text 系に限定した union。
ラベル・エラー文言・`aria-describedby` の配線は FormField molecule の責務
(入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
PasswordInput molecule を使う。

- **`type` は入力補助であって検証手段ではない**。`email` / `tel` / `url` / `number` 等は
  モバイルキーボード・autofill・スクリーンリーダーの型アナウンスのために付ける。
  検証の正本はサーバ(日本語)と押下時の client エラーで、native constraint validation には
  依存しない(form 側で `novalidate`。§Do's and Don'ts)。`inputmode` は restProps で透過する
- **readonly は「編集できない」ことを面で示す**(`Input` / `Textarea` の `readonly` prop)。
  `bg-neutral` + `cursor-default`。ただし **disabled と同じ見た目にしない** — readonly の値は
  生きている(送信される・選択してコピーできる・フォーカスできる)ので、文字色は `text-text` の
  ままにし focus ring も維持する。disabled は `text-text-secondary` + `cursor-not-allowed` +
  フォーカス不可。`<select>` は HTML 仕様上 readonly を持たない(編集させないなら値を
  読み取り表示にする)
- 「編集させない値」の表現は 2 通り。**そのフォームの送信対象に含む / コピーさせたい**なら
  readonly input(例: 招待 email の prefill、権限が無い閲覧者への設定値提示)、
  **編集手段自体を出さない**なら読み取り表示(`<dl>` 等。例: 請求先情報カードの非管理者表示)。
  readonly input を選んだ場合、上記の見た目が付くことは atom が保証する

### Checkbox

実装: `components/atoms/Checkbox.svelte`。インラインラベル(右側)とエラー表示
(FormError 内包)を持つチェックボックス。ラベルは string のほか snippet でも受けられる
(利用規約リンク等を含める用)。複数行ラベルでもチェックボックスが 1 行目に揃う行揃えは
本 atom の責務。ページ側で素の `<input type="checkbox">` を書かない(§Do's and Don'ts)。

### FormError

実装: `components/atoms/FormError.svelte`。フィールド単位のエラー文言
(`text-caption text-danger`。message が無ければ何も描画しない)。FormField / Checkbox から
composition される前提の最小 atom。単体で使う場合、`aria-describedby` の配線は呼び出し側の
責務。ページ常在の通知は Alert、一時通知は Toast を使う。
**フィールドに紐づかない失敗(ceremony 失敗・端末非対応等)を FormError に流さない**
(原因と提示先が食い違い、「パスキー失敗がパスワード欄の赤字として出る」species のバグになる)。
非フィールド起因は Alert(§Alert)。

### Avatar

実装: `components/atoms/Avatar.svelte`。`src` があれば画像、無ければ `name` の先頭 1 文字
(大文字化。サロゲートペアも 1 文字扱い)をイニシャル表示する。アバターは真に円形な UI
のため `rounded-full` を使う ramp 外例外(Toggle と並び ds-purity の file-scoped allowlist
出荷時 2 件の 1 つ)。size: `sm` / `md`(既定)/ `lg`。

### Badge

実装: `components/atoms/Badge.svelte`(仕様の真実は `Badge.types.ts`)。状態・属性の
**結果表示**ラベル(操作は Button。action button と status badge は意味色を独立に判断する
— §色の意味的割り当てルール)。tone: `primary` / `tertiary` / `success` / `warning` /
`danger` / `neutral`(中立ラベル)。既定は soft(tone 色の淡い背景 + tone 色文字)、
`bordered` は tone 色 border を atom 内で付与する(呼び出し側から border を足さない)。
左アイコン 1 つを snippet で受け、size/色の責務は Badge 内 wrapper に閉じる。
小コントロールなので `rounded-sm`。size: `sm`(既定)/ `md`。

### Card

実装: `components/atoms/Card.svelte`。浮いた要素の基本サーフェス
(`bg-surface border border-border rounded-lg`。影を使わず明度差 + 1px border で階層を
表現する — §Elevation & Depth)。padding: `none`(table/list 等を内包し内側で個別に
padding を制御する箱用)/ `sm` / `md`(既定)/ `lg`。

### Spinner

実装: `components/atoms/Spinner.svelte`。LoaderCircle(@lucide/svelte)+ `animate-spin`。
色は currentColor 継承(置かれた文脈の文字色に従う)。既定は装飾扱い(`aria-hidden`)で、
単独のローディング表示に使うときだけ `label` を渡す(`role="status"` + sr-only で
読み上げ)。size: `sm` / `md`(既定)/ `lg` / `xl`。

### TextLink

実装: `components/atoms/TextLink.svelte`(仕様の真実は `TextLink.types.ts`)。
リンク風 `<a>` / `<button>` の手書きは禁止(§Do's and Don'ts)、本 atom を使う。
3 モードの discriminated union: (a) `href` のみ = Inertia Link(SPA 遷移)、
(b) `href` + `external` = ネイティブ `<a>` + 別タブ + `rel="noopener noreferrer"` +
末尾 ExternalLink アイコン(`icon` で差し替え可)、(c) `onclick` のみ = リンク風
`<button type="button">`。様式は `text-primary` + 下線(hover で下線が濃くなる)で 3 モード共通。

### Toggle

実装: `components/atoms/Toggle.svelte`(仕様の真実は `Toggle.types.ts`)。
オン/オフを**即時反映**する設定スイッチ(ネイティブ `<button>` + `role="switch"` +
`aria-checked`)。フォーム送信を伴う選択には使わない。`ariaLabel` は型レベルで必須。
トラックは On=`bg-primary` / Off=`bg-border-strong`、つまみは `bg-surface`(影なし、
明度差で表現)。`rounded-full` は真に円形な UI の例外として file-scoped allowlist で管理する。

### Modal

実装: `components/organisms/Modal.svelte`(仕様の真実は `Modal.types.ts`)。bits-ui Dialog のラップ。

- overlay は `bg-text/50`(墨色 50%。黒 hex を使わない)、本体は `bg-surface border border-border rounded-lg`
  (影が使えないためボーダーで背景と区別する)
- size: `sm`(max-w-md)/ `md`(max-w-lg 既定)/ `lg`(max-w-2xl)
- `processing` 中は ESC / overlay クリックでの close を抑止し、X ボタンを disabled にする(二重実行防止)
- title は `text-h3`。a11y 名は bits-ui `Dialog.Title` 経由で `aria-labelledby` に配線される

### ConfirmDialog

実装: `components/organisms/ConfirmDialog.svelte`(仕様の真実は `ConfirmDialog.types.ts`)。Modal の composition。

- `confirmVariant` は `primary` / `danger` の 2 値のみ。**irreversible / destructive な操作は danger**
  (§色の意味的割り当てルール)
- footer は Button atom(cancel=`ghost` / confirm=`confirmVariant`、processing 中は loading)
- confirm で自動 close しない(処理完了後に呼び出し側が `open=false` にする)。
  cancel / ESC / overlay / X は `onCancel` を発火して close
- `banner?: Snippet` は message 直上の任意スロット(サーバ validation エラーの Alert 等)。
  未指定なら描画されない(既存の出力は不変)

### Toast

実装: `components/organisms/ToastContainer.svelte` + `lib/stores/toast.ts`(addToast / dismissToast)。
Laravel flash の取り込みは `lib/stores/flash-to-toast.ts` の `consumeFlash`(visitKey で de-dup)。

- 上部中央 fixed(`top-6 left-1/2 -translate-x-1/2 z-50`)に縦 stack 表示。アプリで 1 箇所のみ mount する
  (mount するのは layout: AppLayout / AuthLayout / GuestLayout の 3 種。ページ側では mount しない)
- 自動消去: **success / info / warning = 4 秒、error = 手動閉じのみ**
- 消去境界: **layout(AppLayout / AuthLayout / GuestLayout)の初期化時に既存 toast を破棄**してから
  当該 visit の flash を消費する。= **layout が再初期化される遷移**では toast を持ち越さない
  (認証済み文脈の toast を未認証面へ出さない)。`preserveState` の visit / partial reload は
  layout を再初期化しないため toast は残る。別タブの既表示 toast の即時消去は保証しない
- 各 toast は `bg-surface` + type 別 border / アイコン色(success / primary(info)/ warning / danger)。
  アイコンは CircleCheck / Info / TriangleAlert / CircleX(@lucide/svelte)
- a11y: `role="status"`(error のみ `role="alert"`)

### Alert

実装: `components/atoms/Alert.svelte`。ページ内に常在するインライン通知ボックス
(一時通知は Toast、フィールド単位のエラーは FormField/FormError を使う)。

- type: `success` / `warning` / `danger` / `info`(info は primary を流用。Toast と同じ規約)
- 配色: ボーダー=状態色、見出し(title 任意)=状態色、本文=`text-text`、背景=`bg-surface`。
  テーマ色を面塗りに使わない。中間 box なので `rounded-md`
- `action` snippet(本文下の CTA)、`dismissible` + `onDismiss`(右上の X)を持つ
- a11y: **danger のみ `role="alert"`(assertive)**、他は `role="status"`(polite)
- **非フィールド起因の操作失敗は Alert**。フォームのフィールドに紐づかない失敗
  (WebAuthn ceremony 失敗・端末非対応・ネットワーク失敗など)は、操作したその場に残る
  Alert で出す。FormError は**フィールド単位**のエラー専用であり、Toast は「一時通知」なので、
  押した直後に読ませたい失敗理由を画面外(上部中央)へ飛ばさない

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+
ヘルプの複合 molecule。入力 atom を最小責務に保つため、ラベル・エラー文言・
`aria-describedby` の配線は本 molecule が担う(関心分離)。children snippet に
`{ id, describedBy, invalid }` を渡すので、呼び出し側はそれを入力 atom へ流し込む。
`required` は `*`(danger 色、`aria-hidden`)をラベルに付与する。フォームの入力欄は
本 molecule 経由で組む(AGENTS.md 実装規約)。

- **押下時に出した client エラーは、その後の入力に追随させる**(stale invalid を残さない)。
  ボタンを disabled にしない(§Do's and Don'ts / AGENTS.md 禁止事項 8)代わりに押下時にエラーを
  出すのだから、そのエラーは常に「今の入力」を説明していなければならない — 有効に戻ったら消え、
  無効の理由が変わったら文言も変わる。押下前には出さない。
  **canonical なのはこの不変条件であって実装形ではない**。実装は
  **「提示を開始したかの boolean」+ 文言は `$derived`** で組むのが既定(文言を `$state` で
  持つと同期漏れが起きる。`$effect` での状態同期はしない = Svelte 公式の指針)。
  先行実装(`Billing/PurchaseTickets.svelte` / `Organizations/Settings.svelte`)は `$effect` に
  よる連動クリアで**同じ不変条件を満たしており、そのまま許容する**(動いている仕組みを
  churn させない)。**新規は `$derived` 形で書く**
- サーバ由来の errors(`form.errors.*`)はこの追随の対象外。入力の変更で消さない

### DangerZone

実装: `components/molecules/DangerZone.svelte`。破壊的・取り返しのつかない操作
(アカウント削除等)を集約する警告セクション(presentational・状態なし)。
`border-danger/30` + 淡い danger 背景の枠に title(danger 色 `text-h3`)+ 任意の
description、children には danger 系 Button(card 内なら `danger-outline`)を置く。
`<section>` + `aria-labelledby` で region 境界に accessible name を紐付ける。
複数同居時は `idBase` で id 衝突を回避する。

### Divider

実装: `components/molecules/Divider.svelte`。区切り線の正規化(「または」セパレータ等)。
`label` 指定時は中央ラベル付き区切り(線は `aria-hidden`、ラベルは `bg-surface` で線を
切り抜く)、省略時は素の `<hr>`。余白は呼び出し側が class で渡す(`my-6` 等)。

### Pagination

実装: `components/molecules/Pagination.svelte`。前へ / ページ番号 / 次へのページ送り UI。
callback ベース(ページング state は親が持ち、`currentPage` / `totalPages` を受けて
`onChange(page)` を返す)で遷移手段を持たないため、全て `<button type="button">` で構成する
(Inertia 遷移かローカル state 更新かは呼び出し側裁量)。総ページ ≤ 7 は全番号表示、
超過時は先頭・末尾 + 現在ページ ± 1 の窓を出し、飛びに省略記号を挿入する最小実装。
`<nav>` ランドマーク + 現在ページに `aria-current="page"`。

### Tabs

実装: `components/molecules/Tabs.svelte`。**同一ページ内 section 切替**の WAI-ARIA タブバー
(tablist のみ。URL 遷移で切り替えるページ間タブは ApiKeyTabNav のような専用 molecule を
使う)。パネル本体の描画は呼び出し側責務(god component 回避)で、
`id="{idBase}-panel-{tab.id}"` / `role="tabpanel"` / `aria-labelledby` を id 生成規則に
揃えて配線する。キーボードは ←/→(端でラップしない)+ Home/End、自動アクティベーション +
roving tabindex(active のみ tabindex=0)。`active` は bindable、`idBase` は必須
(複数同居時の id 衝突回避)。

### PasswordInput

実装: `components/molecules/PasswordInput.svelte`。Input atom + 右端の Eye/EyeOff トグルで
`password` ↔ `text` を即時切替する(button トグル + `aria-pressed`)。`id` は必須
(トグルの `aria-controls` に結線)。label/error 配線は FormField 側が担う。
Auth 系のパスワード入力は素の `Input type="password"` ではなく本 molecule を使う。

### CodeSnippet

実装: `components/molecules/CodeSnippet.svelte`。コピー付きコードブロック
(API キー・リカバリコード・CLI コマンド等)。コピー処理(navigator.clipboard)は
component 内に内包し、成功「コピー完了」/失敗「コピー失敗」を 2 秒表示する。
`<pre>` は `rounded-md bg-neutral` + `font-mono text-caption`。

### StatCard

実装: `components/molecules/StatCard.svelte`。Card atom に label(`text-caption`)+
value(`text-h2`。weight でなく ramp 昇格で強調)+ 任意の subtext / Lucide icon
(`bg-primary-soft` の rounded-md box)を載せる統計カード。

### EmptyState

実装: `components/molecules/EmptyState.svelte`。リストやテーブルが空のとき、次の行動を
案内する空状態表示。`description`(必須)+ 任意の `title` / Lucide `icon`(装飾なので
`aria-hidden`、`size-10`)。`cta` は discriminated union で遷移(`kind: "link"` = Button
の anchor+inertia)と操作(`kind: "action"` = onclick)を型安全に出し分ける。`bordered`
で破線枠サーフェス(`border-dashed`。drop 領域や明示的な空 region 向け)。

### Breadcrumb

実装: `components/molecules/Breadcrumb.svelte`。`BreadcrumbItem[]`(`@/types/components`)を
`ChevronRight` 区切りで並べるパンくず。**`href` 省略の項目は現在位置**としてリンクにしない。
atom 非依存(Lucide アイコンのみ)。単体で置かず、通常は PageHeaderSection 経由で出す。

### PageHeader / PageHeaderSection

実装: `components/molecules/PageHeaderSection.svelte`(full feature)と
`components/molecules/PageHeader.svelte`(shorthand)。

- **PageHeaderSection**: `title` / `breadcrumbs` / `description` / `icon`(Lucide 互換
  `Component`)/ actions(`children` Snippet)を持つ詳細画面用ヘッダ。全幅バーは
  PageContainer の padding を打ち消す**負マージン契約**で敷き、サイドバーのロゴブロックと
  同じ高さに揃える。**パンくずは 2 件以上のときだけ出す**(1 件は h1 と二重提示になるため)。
- **PageHeader**: breadcrumbs / actions を使わないルート画面用の薄いラッパー。
  内部で PageHeaderSection を呼ぶだけ。**actions や breadcrumbs が要るなら
  PageHeaderSection を直接使う**(PageHeader に prop を足さない)。
- actions は children Snippet で渡す(旧 slot API は使わない)。

### NotificationBell

実装: `components/molecules/NotificationBell.svelte`。`/notifications` への Inertia link に
未読数バッジを重ねた通知ベル。未読数は shared props(`notifications.unreadCount`)を親が渡す。
**100 以上は `99+` に丸める**。v1 はドロップダウンを持たない最小構成(フォーカス管理・
開閉状態を持たない)。**通知はこのベルが単一導線**で、サイドバー nav 項目に重複掲載しない。
`data-testid` は既定 `notification-bell`(mobile は呼び出し側が `notification-bell-mobile`)。

### PricingPlanCard

実装: `components/molecules/PricingPlanCard.svelte`(仕様の真実は `PricingPlanCard.types.ts`)。
料金プランカード。**DTO 非依存**(primitive props)で、feature 文言と CTA は呼び出し側が
props / Snippet で供給する。

- `priceAmount` が **null = 基本料金を持たない = 「無料」表示**(0 も防御的に同一表示)。
- `priceCaption`(例: 「基本料金」)は表示価格が総額と誤解されるのを防ぐための価格直上の説明。
- `isHighlighted` で `border-primary` の強調枠(現在のプラン等)。
- `headerBadges`(header 右上)/ `footerCta`(card 下部)は Snippet 専用スロット。

### ApiKeyTabNav

実装: `components/molecules/ApiKeyTabNav.svelte`。API キー管理ドメインのページ間
(API キー ⇔ 接続セッション ⇔ 導入ガイド)を **URL 遷移**(Inertia `Link`)で切替えるタブナビ。
同一ページ内 section 切替の `molecules/Tabs.svelte` とは責務が異なる。`tabs`(label + href +
active)はページ側が組み立てる(どのタブを出すか・URL は呼び出し側責務)。active タブに
`aria-current="page"` を付与する。

### RecentAuthModal

実装: `components/organisms/RecentAuthModal.svelte`(Modal の composition)。機微操作
(API キー発行/失効・アカウント削除・オーナー移譲)の前に出す**同一画面の再認証(step-up)
モーダル**。パスワード設定済みは再入力 → `POST /recent-auth/password`(成功は XHR 204)、
再 SSO 可能な provider は `reauthUrl` へフルリダイレクト、パスキー登録済みは WebAuthn 検証。
認可の最終ゲートは各操作の recent-auth middleware で、本モーダルは UX 補助。

- **props 契約は `status: RecentAuthStatus | null` の 1 本**(`bind:open` / `onConfirmed` を除く)。
  `/recent-auth/status` の応答を field へ分解して手渡さない — field が増えるたびに配線漏れが
  生まれる(T106 で `passkeyAvailable` を足した際、6 呼び出し中 5 箇所が未配線のまま出荷され
  passkey-only ユーザーが 5 画面で詰んだ)。`tsc --noEmit` は `.svelte` テンプレートを型検査
  しないため、強制点は `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts`
  (deny-by-default。`status={recentAuthStatus}` の識別子・旧 prop 不在・`onStale` での代入まで検査)
- `status === null` は**状態不明**として扱い、空表示や事実に反する文言を出さず再読み込み導線を出す
- 再認証が成立しないユーザー(`canSatisfy=false` / この端末で実行不能)への回復導線は
  **`molecules/RecentAuthRecoveryNotice` に集約**する(下記)

### RecentAuthRecoveryNotice

実装: `components/molecules/RecentAuthRecoveryNotice.svelte`。再認証(step-up)が**この場では
成立しない**ユーザーに出す回復導線。全画面 confirm(`pages/Auth/ConfirmRecentAuth`)と
インラインモーダル(`organisms/RecentAuthModal`)の**両方が使う唯一の実装**(分けて持つと
片方だけ旧作法が残る)。

- `variant`: `no-satisfier`(アカウントに手段が無い)/ `not-executable-here`(手段はあるが
  この端末で実行できない = パスキー非対応ブラウザ)
- **`/forgot-password` へ直接リンクしない**。Fortify が `guest` middleware 付きで登録しており
  ログイン済みの本 UI 利用者はフォームに到達できない(踏破不能 CTA)。案内するのは
  「ログアウト → guest としてパスワード再設定」の経路だけ。アプリ内の初回設定
  (`POST /settings/password`)は recent-auth 必須なので、ここに来ているユーザーには使えない
- ログアウトは **Inertia visit(`router.post`)**(経路 C の保証条件。
  `tests/js/architecture/logout-call-site-inventory.test.ts` が inventory で固定)
- molecule 配置は構造的制約: 呼び出し元の RecentAuthModal は organism であり、
  atomic-import-graph 上 organism は features 層を import できない

## Do's and Don'ts

**Do**

- 背景は常に neutral、浮いた要素は surface(逆に使わない)
- 余白を多めにとる。色は Primary / Tertiary / 状態色 1 種までを目安に
- 操作の可否は**押した後のフィードバック**で伝える(バリデーションエラー表示+フォーカス移動)
- **認証フロー画面(`AuthLayout`)には離脱導線を footer に必ず置く**。その手順を完了できない
  ユーザー(リンク期限切れ・コード紛失・再認証手段なし)が別の入口へ抜けられる `TextLink` を
  `{#snippet footer()}` に 1 つ以上持つ。行き先は**その画面のユーザーの認証状態で実際に
  踏破できる先**に限る(`tests/js/architecture/page-shell-structure.test.ts` が機械強制。
  例外は理由付き allowlist)

**Don't**

- グラデーション・ドロップシャドウ・scale 効果を使わない
- Danger と Tertiary を同一 action cluster・隣接 CTA 群で併置しない(赤系・強調系の意味が混ざる)
- **必須条件未充足を理由にボタンを disabled でブロックしない**。ボタンは活性のまま、
  押下時に何が足りないかをエラー表示する(例: 利用規約同意チェック。
  disabled はユーザーに「なぜ押せないか」を伝えられない)
- **表示条件と踏破条件が食い違う導線を出さない**。押しても必ず失敗するボタン・リンク
  (認証・権限・ゲートで確実に弾かれる先を指すもの)は**出さずに、なぜ今は進めないかを
  文章で説明する**。disabled 化でも代替しない(上の Don't と同根。例: メール未認証画面から
  `verified` ゲート内の checkout へ進む CTA)
- ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)
- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
  検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
  native validation は submit より先に発火してブラウザロケール依存の文言で送信を止めるため、
  日本語 UI の検証経路に到達できなくなる(`tests/js/architecture/form-novalidate.test.ts` が機械検証)

## 色の意味的割り当てルール

- **danger** = irreversible な喪失・破壊(削除・revoke・unassign・移譲・再開不可の中断)。
  確認 dialog があっても操作自体が不可逆ならボタン色は danger
- **warning** = 注意喚起 / 保留 / 可逆な要確認状態
- **tertiary** = 前向きな強調のみ(1 画面 1 箇所)
- **primary** = ブランド中核 / 主要 CTA / 選択中
- **neutral / text-secondary** = 中立・取消可能・UI-only の補助操作

action button(操作)と status badge(結果表示)は意味色を**独立に判断**する。


## 触れた component 階層 (Atomic Design)

- `resources/js/components/atoms/` — Badge / Button (既存。本差分では変更なし)
- `resources/js/components/features/capture/` — CameraRecorder.svelte / CaptureFileFallback.svelte /
  TakePreviewDialog.svelte / TakeStrip.svelte
- `resources/js/components/features/manual/` — TakeFileUpload.svelte / TakePreviewPanel.svelte /
  ScenarioEditor.svelte
- `resources/js/pages/` — Capture/Show.svelte / Manuals/Takes.svelte
- `resources/js/lib/capture/` — still-encode.ts (新規) / camera.ts


## 実装差分 (git diff)

```diff
diff --git a/app/Console/Commands/Development/PipelineSmokeCommand.php b/app/Console/Commands/Development/PipelineSmokeCommand.php
index e6548ac..c027819 100644
--- a/app/Console/Commands/Development/PipelineSmokeCommand.php
+++ b/app/Console/Commands/Development/PipelineSmokeCommand.php
@@ -40,6 +40,7 @@
 use App\Services\Storage\Fakes\FakeObjectStore;
 use App\Support\BughuntDatabaseGuard;
 use App\Support\FakeStorageGate;
+use App\Support\Media\FfmpegSafetyArguments;
 use App\Support\Smoke\SmokeFailureClassifier;
 use Carbon\CarbonImmutable;
 use Illuminate\Console\Command;
@@ -318,7 +319,8 @@ private function failPreflight(CarbonImmutable $startedAt, string $reason): null
     private function probeBinary(string $binary): ?string
     {
         try {
-            $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([$binary, '-version']);
+            $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)
+                ->run([$binary, ...FfmpegSafetyArguments::all(), '-version']);
         } catch (Throwable) {
             return null;
         }
@@ -639,7 +641,7 @@ private function generateTakeVideo(string $workDir): string
     {
         $path = $workDir.'/take.mp4';
         $result = Process::path($workDir)->timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
-            config()->string('manual.render_ffmpeg_binary'), '-y',
+            config()->string('manual.render_ffmpeg_binary'), ...FfmpegSafetyArguments::all(), '-y',
             '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=30:duration='.self::TAKE_SECONDS,
             '-f', 'lavfi', '-i', 'sine=frequency=440:duration='.self::TAKE_SECONDS,
             '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p',
@@ -709,7 +711,7 @@ private function runArtifactStage(RenderJob $job, string $workDir): bool
         File::copy($store->absolutePath($outputPath), $local);
 
         $probe = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
-            config()->string('manual.render_ffprobe_binary'),
+            config()->string('manual.render_ffprobe_binary'), ...FfmpegSafetyArguments::all(),
             '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $local,
         ]);
         if (! $probe->successful()) {
diff --git a/app/DataTransferObjects/Capture/CaptureCutData.php b/app/DataTransferObjects/Capture/CaptureCutData.php
index a8881ac..3453950 100644
--- a/app/DataTransferObjects/Capture/CaptureCutData.php
+++ b/app/DataTransferObjects/Capture/CaptureCutData.php
@@ -59,9 +59,9 @@ public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?st
     /**
      * @return array{id: int, type: string, parent_cut_id: int|null, scene: string,
      *   shot_type: string, shooting_point: string|null, narration: string,
-     *   subtitle_primary: string|null, subtitle_secondary: string, adopted_take_id: int|null,
-     *   adopted_ready_take_id: int|null,
-     *   takes: list<array{id: int, client_take_id: string, status: string, size_bytes: int,
+     *   subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
+     *   adopted_take_id: int|null, adopted_ready_take_id: int|null,
+     *   takes: list<array{id: int, client_take_id: string, status: string, material_type: string, size_bytes: int,
      *     duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
      *     downloaded: bool, has_thumbnail: bool, playback_url: string|null,
      *     download_ack_token: string|null}>}
@@ -78,6 +78,8 @@ public function toArray(): array
             'narration' => $this->cut->narration,
             'subtitle_primary' => $this->cut->subtitle_primary,
             'subtitle_secondary' => $this->cut->subtitle_secondary,
+            // カットの**計画** (未指定あり)。撮影 UI の出し分け (シャッター / 録画) に使う
+            'material_type' => $this->cut->material_type?->value,
             'adopted_take_id' => $this->cut->adopted_take_id,
             // 通し再生が再生する対象。null = そのカットはプレースホルダになる
             // (「採用されていない」と「採用済みだが ready でない」を区別しない = 述語の意味そのまま)
diff --git a/app/DataTransferObjects/Capture/CaptureTakeData.php b/app/DataTransferObjects/Capture/CaptureTakeData.php
index e1a57fd..180b305 100644
--- a/app/DataTransferObjects/Capture/CaptureTakeData.php
+++ b/app/DataTransferObjects/Capture/CaptureTakeData.php
@@ -28,7 +28,7 @@ public static function fromTake(Take $take, ?string $playbackUrl = null, ?string
     }
 
     /**
-     * @return array{id: int, client_take_id: string, status: string, size_bytes: int,
+     * @return array{id: int, client_take_id: string, status: string, material_type: string, size_bytes: int,
      *   duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
      *   downloaded: bool, has_thumbnail: bool, playback_url: string|null,
      *   download_ack_token: string|null}
@@ -39,6 +39,9 @@ public function toArray(): array
             'id' => $this->take->id,
             'client_take_id' => $this->take->client_take_id,
             'status' => $this->take->status->value,
+            // 登録された素材の実体 (NOT NULL)。cut 側の計画 (nullable) とは別のキーである。
+            // UI はこの値で <video> と <img> を出し分ける
+            'material_type' => $this->take->material_type->value,
             'size_bytes' => $this->take->size_bytes,
             'duration_ms' => $this->take->duration_ms,
             'comment' => $this->take->comment,
diff --git a/app/DataTransferObjects/Manual/CutTakeSummaryData.php b/app/DataTransferObjects/Manual/CutTakeSummaryData.php
index d99c2db..6a8250d 100644
--- a/app/DataTransferObjects/Manual/CutTakeSummaryData.php
+++ b/app/DataTransferObjects/Manual/CutTakeSummaryData.php
@@ -4,6 +4,7 @@
 
 namespace App\DataTransferObjects\Manual;
 
+use App\Enums\Manual\MaterialType;
 use App\Models\Cut;
 use Webmozart\Assert\Assert;
 
@@ -15,6 +16,10 @@
  * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
  * 表示のために security gate の allowlist を広げないための命名である。
  * 読み取りは adoptedTake relation 経由で行う。
+ *
+ * **素材登録状況 (doc/02 §2.4 の 3 値) の材料をここで出す**。
+ * 判定に使うのは「採用テイクが在るか」と「その material_type」の 2 つだけで、
+ * **ready 判定 (AdoptedReadyTakeCoverage の述語) は再実装しない** (ドメイン固有規約 12)。
  */
 final readonly class CutTakeSummaryData
 {
@@ -29,6 +34,8 @@ public function __construct(
          * true のときだけ画像 URL (capture.takes.thumbnail) を張る = 404 を踏まない。
          */
         public bool $adoptedHasThumbnail,
+        /** 採用テイクの**実体**種別 (NOT NULL)。採用テイクが無いときは null */
+        public ?MaterialType $adoptedMaterialType,
     ) {}
 
     /** withCount('takes') + with('adoptedTake') 済みの cut から生成する */
@@ -46,26 +53,30 @@ public static function fromCut(Cut $cut): self
             // thumbnail_path は takes 表の列なので追加クエリは発生しない。
             // 採用テイクが無いときは null !== null = false へ落ちる (意味が一致する)
             adoptedHasThumbnail: $adopted?->thumbnail_path !== null,
+            adoptedMaterialType: $adopted?->material_type,
         );
     }
 
     /**
      * @return array{cut_id: int, takes_count: int,
-     *   adopted: array{id: int, status: string, has_thumbnail: bool}|null}
+     *   adopted: array{id: int, status: string, has_thumbnail: bool, material_type: string}|null}
      */
     public function toArray(): array
     {
+        // id / status / material_type は同時に決まる (すべて null か、すべて非 null)
+        if ($this->adoptedId === null || $this->adoptedStatus === null || $this->adoptedMaterialType === null) {
+            return ['cut_id' => $this->cutId, 'takes_count' => $this->takesCount, 'adopted' => null];
+        }
+
         return [
             'cut_id' => $this->cutId,
             'takes_count' => $this->takesCount,
-            // id と status は同時に決まる (両方 null か両方非 null)
-            'adopted' => $this->adoptedId === null || $this->adoptedStatus === null
-                ? null
-                : [
-                    'id' => $this->adoptedId,
-                    'status' => $this->adoptedStatus,
-                    'has_thumbnail' => $this->adoptedHasThumbnail,
-                ],
+            'adopted' => [
+                'id' => $this->adoptedId,
+                'status' => $this->adoptedStatus,
+                'has_thumbnail' => $this->adoptedHasThumbnail,
+                'material_type' => $this->adoptedMaterialType->value,
+            ],
         ];
     }
 }
diff --git a/app/DataTransferObjects/Manual/Render/RenderClipSpec.php b/app/DataTransferObjects/Manual/Render/RenderClipSpec.php
index 16e9b6e..752c742 100644
--- a/app/DataTransferObjects/Manual/Render/RenderClipSpec.php
+++ b/app/DataTransferObjects/Manual/Render/RenderClipSpec.php
@@ -15,8 +15,12 @@ public function __construct(
         /** 手順N / 急所N-M (派生。エラー表示・ログ用) */
         public string $label,
         public RenderClipSource $source,
-        /** S3 キー (Placeholder は null) */
-        public ?string $takeVideoPath,
+        /**
+         * 素材の S3 キー (Placeholder は null)。
+         * TakeStill には**画像**が入りうるため「動画のパス」という名前にしない (旧名 takeVideoPath)。
+         * compose 側は種別で分岐せず、この 1 本を入力に取る
+         */
+        public ?string $takeSourcePath,
         /** TakeStill のみ (静止画の表示秒数) */
         public ?int $stillDisplaySeconds,
         public ?string $subtitlePrimary,
diff --git a/app/DataTransferObjects/Manual/ScenarioPointData.php b/app/DataTransferObjects/Manual/ScenarioPointData.php
index b997041..2c8776a 100644
--- a/app/DataTransferObjects/Manual/ScenarioPointData.php
+++ b/app/DataTransferObjects/Manual/ScenarioPointData.php
@@ -4,6 +4,7 @@
 
 namespace App\DataTransferObjects\Manual;
 
+use App\Enums\Manual\MaterialType;
 use App\Models\Cut;
 
 /**
@@ -21,7 +22,7 @@ public function __construct(
         public string $narration,
         public ?string $subtitlePrimary,
         public string $subtitleSecondary,
-        public ?string $materialType,
+        public ?MaterialType $materialType,
         public ?int $staticDisplaySeconds,
     ) {}
 
@@ -35,7 +36,7 @@ public static function fromCut(Cut $cut): self
             narration: $cut->narration,
             subtitlePrimary: $cut->subtitle_primary,
             subtitleSecondary: $cut->subtitle_secondary,
-            materialType: $cut->material_type?->value,
+            materialType: $cut->material_type,
             staticDisplaySeconds: $cut->static_display_seconds,
         );
     }
@@ -55,7 +56,7 @@ public function toArray(): array
             'narration' => $this->narration,
             'subtitle_primary' => $this->subtitlePrimary,
             'subtitle_secondary' => $this->subtitleSecondary,
-            'material_type' => $this->materialType,
+            'material_type' => $this->materialType?->value,
             'static_display_seconds' => $this->staticDisplaySeconds,
         ];
     }
diff --git a/app/DataTransferObjects/Manual/ScenarioStepData.php b/app/DataTransferObjects/Manual/ScenarioStepData.php
index ea48f31..5981840 100644
--- a/app/DataTransferObjects/Manual/ScenarioStepData.php
+++ b/app/DataTransferObjects/Manual/ScenarioStepData.php
@@ -4,6 +4,7 @@
 
 namespace App\DataTransferObjects\Manual;
 
+use App\Enums\Manual\MaterialType;
 use App\Models\Cut;
 
 /**
@@ -21,7 +22,7 @@ public function __construct(
         public string $narration,
         public ?string $subtitlePrimary,
         public string $subtitleSecondary,
-        public ?string $materialType,
+        public ?MaterialType $materialType,
         public ?int $staticDisplaySeconds,
         public array $points,
     ) {}
@@ -37,7 +38,7 @@ public static function fromCut(Cut $cut, array $points): self
             narration: $cut->narration,
             subtitlePrimary: $cut->subtitle_primary,
             subtitleSecondary: $cut->subtitle_secondary,
-            materialType: $cut->material_type?->value,
+            materialType: $cut->material_type,
             staticDisplaySeconds: $cut->static_display_seconds,
             points: $points,
         );
@@ -61,7 +62,7 @@ public function toArray(): array
             'narration' => $this->narration,
             'subtitle_primary' => $this->subtitlePrimary,
             'subtitle_secondary' => $this->subtitleSecondary,
-            'material_type' => $this->materialType,
+            'material_type' => $this->materialType?->value,
             'static_display_seconds' => $this->staticDisplaySeconds,
             'points' => array_map(
                 static fn (ScenarioPointData $point): array => $point->toArray(),
diff --git a/app/DataTransferObjects/Manual/SelectableTakeData.php b/app/DataTransferObjects/Manual/SelectableTakeData.php
index 4804123..3d5c719 100644
--- a/app/DataTransferObjects/Manual/SelectableTakeData.php
+++ b/app/DataTransferObjects/Manual/SelectableTakeData.php
@@ -28,7 +28,7 @@ public static function fromTake(Take $take): self
     }
 
     /**
-     * @return array{id: int, status: string, size_bytes: int, duration_ms: int|null,
+     * @return array{id: int, status: string, material_type: string, size_bytes: int, duration_ms: int|null,
      *   comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool,
      *   has_thumbnail: bool}
      */
@@ -37,6 +37,8 @@ public function toArray(): array
         return [
             'id' => $this->take->id,
             'status' => $this->take->status->value,
+            // 登録された素材の実体 (NOT NULL)。UI はこの値で <video> と <img> を出し分ける
+            'material_type' => $this->take->material_type->value,
             'size_bytes' => $this->take->size_bytes,
             'duration_ms' => $this->take->duration_ms,
             'comment' => $this->take->comment,
diff --git a/app/DataTransferObjects/Manual/TakeSelectionPageData.php b/app/DataTransferObjects/Manual/TakeSelectionPageData.php
index c8daf06..26b2173 100644
--- a/app/DataTransferObjects/Manual/TakeSelectionPageData.php
+++ b/app/DataTransferObjects/Manual/TakeSelectionPageData.php
@@ -63,9 +63,9 @@ public static function fromCut(Project $project, VideoManual $manual, Cut $cut):
      * @return array{project: array{id: int, name: string},
      *   manual: array{id: int, title: string, status: string},
      *   cut: array{id: int, type: string, label: string, scene: string, narration: string,
-     *     subtitle_primary: string|null, subtitle_secondary: string,
-     *     adopted: array{id: int, status: string}|null},
-     *   takes: list<array{id: int, status: string, size_bytes: int, duration_ms: int|null,
+     *     subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
+     *     adopted: array{id: int, status: string, material_type: string}|null},
+     *   takes: list<array{id: int, status: string, material_type: string, size_bytes: int, duration_ms: int|null,
      *     comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool,
      *     has_thumbnail: bool}>}
      */
@@ -89,9 +89,16 @@ public function toArray(): array
                 'narration' => $this->cut->narration,
                 'subtitle_primary' => $this->cut->subtitle_primary,
                 'subtitle_secondary' => $this->cut->subtitle_secondary,
+                // カットの**計画** (未指定あり)。ファイル選択の accept 切替に使う
+                'material_type' => $this->cut->material_type?->value,
                 'adopted' => $adopted === null
                     ? null
-                    : ['id' => $adopted->id, 'status' => $adopted->status->value],
+                    : [
+                        'id' => $adopted->id,
+                        'status' => $adopted->status->value,
+                        // 採用テイクの**実体**種別 (NOT NULL)
+                        'material_type' => $adopted->material_type->value,
+                    ],
             ],
             'takes' => array_map(
                 static fn (SelectableTakeData $take): array => $take->toArray(),
diff --git a/app/Http/Requests/Capture/StoreCaptureTakeRequest.php b/app/Http/Requests/Capture/StoreCaptureTakeRequest.php
index fded401..11ac111 100644
--- a/app/Http/Requests/Capture/StoreCaptureTakeRequest.php
+++ b/app/Http/Requests/Capture/StoreCaptureTakeRequest.php
@@ -35,6 +35,8 @@ public function rules(): array
             'size_bytes' => ['missing'],
             'status' => ['missing'],
             'sort_order' => ['missing'],
+            // 素材種別はサーバが予約行の content_type から確定する
+            'material_type' => ['missing'],
         ], $this->protectedKeyMissingRules());
     }
 
diff --git a/app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php b/app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php
index c0b7f34..62ea057 100644
--- a/app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php
+++ b/app/Http/Requests/Capture/StoreTakeUploadUrlRequest.php
@@ -6,9 +6,12 @@
 
 use App\DataTransferObjects\Capture\Sha256Checksum;
 use App\DataTransferObjects\Capture\TakeUploadInput;
+use App\Enums\Manual\MaterialType;
 use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Support\Capture\TakeMaterialClassifier;
 use Illuminate\Foundation\Http\FormRequest;
 use Illuminate\Validation\Rule;
+use Illuminate\Validation\Validator;
 
 /**
  * presigned upload-url 発行 (POST .../cuts/{cut}/takes/upload-url)。
@@ -30,15 +33,61 @@ public function rules(): array
     {
         return array_merge([
             'client_take_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'], // ULID
-            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.config()->integer('capture.max_take_bytes')],
-            'content_type' => ['required', 'string', Rule::in(config()->array('capture.allowed_video_content_types'))],
+            // 上限はまず**両者の最大**で受け、種別ごとの上限は after フックで判定する
+            // (Rule::in を通る前に size の上限を種別で切り替えられないため)
+            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.self::maxAllowedBytes()],
+            'content_type' => ['required', 'string', Rule::in(self::allowedContentTypes())],
             // base64(32bytes) = 44 文字。toTakeUploadInput() で Sha256Checksum::fromBase64 により厳密検証
             'checksum_sha256' => ['required', 'string', 'size:44', 'regex:%^[A-Za-z0-9+/]{43}=$%'],
-            // サーバ生成キー (payload から受けない)
+            // サーバ生成キー / サーバ確定値 (payload から受けない)
             'video_path' => ['missing'],
+            'material_type' => ['missing'],
         ], $this->protectedKeyMissingRules());
     }
 
+    /**
+     * 種別ごとのバイト上限 (静止画に 500 MiB を許さない)。
+     * content_type が確定した後でないと判定できないため after フックで見る。
+     *
+     * @return array<int, callable(Validator): void>
+     */
+    public function after(): array
+    {
+        return [
+            function (Validator $validator): void {
+                if ($validator->errors()->isNotEmpty()) {
+                    return; // 型が確定していない段階では判定しない
+                }
+                $limit = TakeMaterialClassifier::fromContentType($this->string('content_type')->value())
+                    === MaterialType::Still
+                        ? config()->integer('capture.max_still_bytes')
+                        : config()->integer('capture.max_take_bytes');
+                if ($this->integer('size_bytes') > $limit) {
+                    $validator->errors()->add('size_bytes', '選択したファイルのサイズが上限を超えています。');
+                }
+            },
+        ];
+    }
+
+    /** @return list<string> */
+    private static function allowedContentTypes(): array
+    {
+        /** @var list<string> $video */
+        $video = array_values(config()->array('capture.allowed_video_content_types'));
+        /** @var list<string> $still */
+        $still = array_values(config()->array('capture.allowed_still_content_types'));
+
+        return [...$video, ...$still];
+    }
+
+    private static function maxAllowedBytes(): int
+    {
+        return max(
+            config()->integer('capture.max_take_bytes'),
+            config()->integer('capture.max_still_bytes'),
+        );
+    }
+
     public function toTakeUploadInput(): TakeUploadInput
     {
         return new TakeUploadInput(
diff --git a/app/Models/Take.php b/app/Models/Take.php
index 11f92a5..dda7f79 100644
--- a/app/Models/Take.php
+++ b/app/Models/Take.php
@@ -4,6 +4,7 @@
 
 namespace App\Models;
 
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\TakeStatus;
 use Database\Factories\TakeFactory;
 use Illuminate\Database\Eloquent\Factories\HasFactory;
@@ -22,11 +23,15 @@
  *   $fillable 外。非 null は削除不可 (概念設計 D6)
  * - thumbnail_path / thumbnail_size_bytes は**サーバ生成の会計値**のため $fillable 外。
  *   書き込みは TakeThumbnailPipeline の条件付き UPDATE (query builder) だけである
+ * - material_type は**サーバ確定値** ($fillable 外)。予約行の content_type から
+ *   TakeMaterialClassifier が導き、INSERT 時に forceFill で明示代入する。
+ *   cuts.material_type (計画) とは別概念で、値域だけを共有する
  *
  * @property int $id
  * @property int $cut_id
  * @property string $client_take_id
  * @property string $video_path
+ * @property MaterialType $material_type
  * @property string|null $thumbnail_path
  * @property int|null $thumbnail_size_bytes
  * @property int $size_bytes
@@ -59,6 +64,7 @@ protected function casts(): array
     {
         return [
             'status' => TakeStatus::class,
+            'material_type' => MaterialType::class,
             'captured_at' => 'datetime',
             'downloaded_at' => 'datetime',
             // 読み取り型を driver 依存にしない (DTO / Resource / PHPStan が int|null で安定する)。
diff --git a/app/Services/Capture/FfmpegTakeThumbnailExtractor.php b/app/Services/Capture/FfmpegTakeThumbnailExtractor.php
index 1e7bcdd..2ba803f 100644
--- a/app/Services/Capture/FfmpegTakeThumbnailExtractor.php
+++ b/app/Services/Capture/FfmpegTakeThumbnailExtractor.php
@@ -4,14 +4,16 @@
 
 namespace App\Services\Capture;
 
+use App\Enums\Manual\MaterialType;
 use App\Exceptions\Capture\TakeThumbnailExtractionException;
+use App\Support\Media\FfmpegSafetyArguments;
 use Illuminate\Support\Facades\File;
 use Illuminate\Support\Facades\Process;
 
 /**
  * ffmpeg による 1 フレーム抽出 (実行は Process facade 経由。テストは Process::fake())。
  *
- * 安全境界 (入力は**利用者がアップロードした動画**である):
+ * 安全境界 (入力は**利用者がアップロードした素材** = 動画または静止画である):
  * - 引数は配列で渡す (シェル連結なし)。入力・出力ともサーバ生成のパスだけで、
  *   利用者由来の文字列は 1 つも引数に入らない
  * - `-nostdin` で標準入力待ちに落ちない / `Process::timeout()` で実行を有界にする
@@ -24,15 +26,26 @@
  */
 final class FfmpegTakeThumbnailExtractor implements TakeThumbnailExtractor
 {
-    public function extract(string $localVideoPath, string $localThumbnailPath): void
+    public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void
     {
+        // 静止画に「1 秒地点」は無い。seek=0 の 1 回で決める
+        // (動画既定の 1000ms を当てると 1 回目が必ず空振りし、無駄な ffmpeg 実行が 1 回増える)
+        if ($material === MaterialType::Still) {
+            $failure = $this->attempt($localSourcePath, $localThumbnailPath, 0);
+            if ($failure !== null) {
+                throw new TakeThumbnailExtractionException($failure);
+            }
+
+            return;
+        }
+
         $seekMs = config()->integer('capture.thumbnail_seek_ms');
 
-        $failure = $this->attempt($localVideoPath, $localThumbnailPath, $seekMs);
+        $failure = $this->attempt($localSourcePath, $localThumbnailPath, $seekMs);
         if ($failure !== null && $seekMs > 0) {
             // 尺が seek より短いと 1 フレームも出力されない。先頭で 1 回だけ再試行する
             // (これ以上の探索はしない = 尺の推定に ffprobe を足さない)
-            $failure = $this->attempt($localVideoPath, $localThumbnailPath, 0);
+            $failure = $this->attempt($localSourcePath, $localThumbnailPath, 0);
         }
         if ($failure !== null) {
             throw new TakeThumbnailExtractionException($failure);
@@ -58,6 +71,7 @@ private function attempt(string $source, string $destination, int $seekMs): ?str
         $result = Process::timeout(config()->integer('capture.thumbnail_ffmpeg_timeout_seconds'))
             ->run([
                 config()->string('manual.render_ffmpeg_binary'),
+                ...FfmpegSafetyArguments::all(),
                 '-nostdin', '-y',
                 '-protocol_whitelist', 'file',
                 '-ss', sprintf('%.3f', $seekMs / 1000),
diff --git a/app/Services/Capture/TakeRegistrationService.php b/app/Services/Capture/TakeRegistrationService.php
index e1db63a..1626611 100644
--- a/app/Services/Capture/TakeRegistrationService.php
+++ b/app/Services/Capture/TakeRegistrationService.php
@@ -9,6 +9,7 @@
 use App\DataTransferObjects\Capture\UploadTicketClaims;
 use App\Enums\Capture\CaptureConflictType;
 use App\Enums\Capture\TakeUploadReservationStatus;
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\TakeStatus;
 use App\Exceptions\Capture\CaptureConflictException;
 use App\Jobs\Capture\GenerateTakeThumbnailJob;
@@ -17,6 +18,7 @@
 use App\Models\Take;
 use App\Models\TakeUploadReservation;
 use App\Models\VideoManual;
+use App\Support\Capture\TakeMaterialClassifier;
 use Illuminate\Database\Eloquent\ModelNotFoundException;
 use Illuminate\Database\UniqueConstraintViolationException;
 use Illuminate\Support\Facades\DB;
@@ -160,15 +162,25 @@ private function finalize(Project $project, VideoManual $manual, Cut $cut, TakeR
                 ]);
             }
 
+            // 素材種別は**予約行の content_type**から導く (チケット偽装で差し替えられない)
+            $material = TakeMaterialClassifier::fromContentType($reservation->content_type);
+
             $lockedCut->takes()->increment('sort_order'); // 既存を後ろへ (先頭 = 0。行ロック下で競合なし)
             $take = $lockedCut->takes()->make([
                 'client_take_id' => $reservation->client_take_id,
                 'video_path' => $reservation->video_path,
                 'size_bytes' => $reservation->size_bytes,   // 予約 = HeadObject 照合済み確定値
-                'duration_ms' => $input->durationMs,        // クライアント申告 (表示用)
+                // 静止画に尺は無い。クライアント申告があっても捨てる (表示・尺ゲートの両方で嘘をつかせない)
+                'duration_ms' => $material === MaterialType::Still ? null : $input->durationMs,
                 'captured_at' => $input->capturedAt,
             ]);
-            $take->forceFill(['status' => TakeStatus::Ready, 'sort_order' => 0])->save();
+            // material_type は保護された確定値のため forceFill で**INSERT 時に明示代入**する
+            // (ドメイン規約 1 (ii)/2 と同じ理由。DB default を置いていないので、ここが唯一の設定点である)
+            $take->forceFill([
+                'status' => TakeStatus::Ready,
+                'sort_order' => 0,
+                'material_type' => $material,
+            ])->save();
 
             // サムネイル生成の投入を**同一 tx 内**で行う (AGENTS.md ドメイン固有規約 11。
             // afterCommit に依存しない)。保証するのは「take 行を作ったのに生成 job が投入されない窓」の
diff --git a/app/Services/Capture/TakeThumbnailExtractor.php b/app/Services/Capture/TakeThumbnailExtractor.php
index 23bfe6c..ca0df95 100644
--- a/app/Services/Capture/TakeThumbnailExtractor.php
+++ b/app/Services/Capture/TakeThumbnailExtractor.php
@@ -4,10 +4,11 @@
 
 namespace App\Services\Capture;
 
+use App\Enums\Manual\MaterialType;
 use App\Exceptions\Capture\TakeThumbnailExtractionException;
 
 /**
- * テイク動画から表示用サムネイル (JPEG) を 1 枚作る抽象 (v1 は ffmpeg 実装)。
+ * テイク素材から表示用サムネイル (JPEG) を 1 枚作る抽象 (v1 は ffmpeg 実装)。
  *
  * `Render\VideoComposer` と同じ作法で interface に切る = テストは実バイナリに依存せず
  * container swap で fake を注入できる (AppServiceProvider が本番実装を bind する)。
@@ -15,10 +16,14 @@
 interface TakeThumbnailExtractor
 {
     /**
-     * @param  string  $localVideoPath  ローカルへ落とした動画 (サーバ生成のパス)
+     * 素材種別を受け取り、seek 方針を実装側が決める。
+     * 静止画に「1 秒地点」は存在しないため、種別を知らずに seek を決められない。
+     *
+     * @param  string  $localSourcePath  ローカルへ落とした素材 (サーバ生成のパス)
      * @param  string  $localThumbnailPath  出力先 (サーバ生成のパス)
+     * @param  MaterialType  $material  登録された素材の実体種別 (takes.material_type)
      *
      * @throws TakeThumbnailExtractionException 抽出できなかった場合
      */
-    public function extract(string $localVideoPath, string $localThumbnailPath): void;
+    public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void;
 }
diff --git a/app/Services/Capture/TakeThumbnailPipeline.php b/app/Services/Capture/TakeThumbnailPipeline.php
index ede7bdb..8b8f738 100644
--- a/app/Services/Capture/TakeThumbnailPipeline.php
+++ b/app/Services/Capture/TakeThumbnailPipeline.php
@@ -56,7 +56,7 @@ public function run(int $takeId): void
 
             // S3 GET は冪等な読み取り / ffmpeg はローカル CPU = どちらも preflight の対象ではない
             $this->storage->downloadToLocal($take->video_path, $source);
-            $this->extractor->extract($source, $thumbnail);
+            $this->extractor->extract($source, $thumbnail, $take->material_type);
 
             $size = File::isFile($thumbnail) ? File::size($thumbnail) : 0;
             if ($size === 0) {
diff --git a/app/Services/Capture/TakeUploadService.php b/app/Services/Capture/TakeUploadService.php
index ff1ff70..b4ac89d 100644
--- a/app/Services/Capture/TakeUploadService.php
+++ b/app/Services/Capture/TakeUploadService.php
@@ -8,6 +8,7 @@
 use App\DataTransferObjects\Capture\TakeUploadTicketData;
 use App\DataTransferObjects\Capture\UploadTicketClaims;
 use App\Enums\Capture\TakeUploadReservationStatus;
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\VideoManualStatus;
 use App\Enums\QuotaKey;
 use App\Models\Cut;
@@ -16,11 +17,11 @@
 use App\Models\TakeUploadReservation;
 use App\Models\VideoManual;
 use App\Services\Billing\QuotaService;
+use App\Support\Capture\TakeMaterialClassifier;
 use Carbon\CarbonImmutable;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Str;
 use Illuminate\Validation\ValidationException;
-use Webmozart\Assert\Assert;
 
 /**
  * presigned PUT URL + 署名チケット発行 (doc/10 §10.3 / §10.8-4,-7 / 概念設計 D2,D3)。
@@ -53,6 +54,19 @@ public function issue(Organization $organization, Project $project, VideoManual
             /** @var Cut $lockedCut */
             $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();
 
+            // 素材種別の整合 (受け入れは非対称):
+            // - still カット: 画像も動画も受ける (動画は先頭フレーム抽出で従来どおり合成できる)
+            // - video / 未指定カット: 動画のみ。画像は 422 で押下時にエラー表示 (禁止事項 8 の通り
+            //   ボタンを disabled にはしない)。入口で止めるのは「指示と違う素材で容量を消費させない」ため。
+            // 一方でレンダ側は take の実体を優先する (EffectiveMaterialType)。採用後に
+            // cut.material_type を video へ戻す編集ができるので、入口検証だけでは不整合を防げない。
+            if (TakeMaterialClassifier::fromContentType($input->contentType) === MaterialType::Still
+                && $lockedCut->material_type !== MaterialType::Still) {
+                throw ValidationException::withMessages([
+                    'content_type' => ['このカットは動画で撮影する設定です。静止画を使う場合はシナリオ編集で素材を「静止画」に変更してください。'],
+                ]);
+            }
+
             // Quota: bytes_used + bytes_pending + size が上限を超えるなら 422 (QuotaExceededException)。
             // 加算合成は occupiedBytes() (overflow 安全) に委譲し、呼び出し側で生加算しない。
             // occupiedBytes() は pending→used の読み取り順が並行制御上の不変条件
@@ -71,7 +85,7 @@ public function issue(Organization $organization, Project $project, VideoManual
                 $lockedManual->id,
                 $lockedCut->id,
                 (string) Str::ulid(),
-                self::extensionFor($input->contentType),
+                TakeMaterialClassifier::extensionFor($input->contentType),
             );
 
             $reservation = $lockedCut->uploadReservations()->make([
@@ -107,18 +121,4 @@ public function issue(Organization $organization, Project $project, VideoManual
 
         return new TakeUploadTicketData($presigned, $ticket, $reservation->client_take_id);
     }
-
-    /** 許可 Content-Type → S3 キー拡張子 (config capture.allowed_video_content_types と対で保守) */
-    private static function extensionFor(string $contentType): string
-    {
-        $extension = match ($contentType) {
-            'video/mp4' => 'mp4',
-            'video/webm' => 'webm',
-            'video/quicktime' => 'mov',
-            default => null,
-        };
-        Assert::notNull($extension, "未許可の Content-Type です: {$contentType}");
-
-        return $extension;
-    }
 }
diff --git a/app/Services/Manual/EffectiveMaterialType.php b/app/Services/Manual/EffectiveMaterialType.php
new file mode 100644
index 0000000..da7d9ab
--- /dev/null
+++ b/app/Services/Manual/EffectiveMaterialType.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\Enums\Manual\MaterialType;
+use App\Models\Cut;
+use App\Models\Take;
+
+/**
+ * 「このカットを**実際に**どちらの素材として合成するか」を決める式の**唯一の所在**。
+ *
+ * 実体優先である: cut の計画が `still` でなくても、採用テイクの実体が画像なら `Still` を返す。
+ * 理由は、**採用した後に編集者がシナリオ編集で cut.material_type を `video` へ戻せる**ためで、
+ * 入口 (presign 422) でも採用 API でもこの状態は防げない。画像を動画クリップ経路
+ * (`FfmpegVideoComposer::planTakeVideo()` = ffprobe で尺を測る) に流すと必ず壊れるので、
+ * 「画像が動画クリップとして合成される道」を構造的に消す。
+ *
+ * **採用テイクは引数で受ける** (このクラスは `adoptedTake` relation を読まない)。
+ * したがって `AdoptedTakeReferenceInventory` の登録は増えない。
+ *
+ * **ready 判定は一切しない** — 「採用済みかつ ready か」の述語は
+ * `AdoptedReadyTakeCoverage` の専権である (AGENTS.md ドメイン固有規約 12)。本クラスは呼ばれる時点で
+ * 採用テイクが確定していることを前提にする。
+ */
+final class EffectiveMaterialType
+{
+    public static function of(Cut $cut, Take $adoptedTake): MaterialType
+    {
+        return $cut->material_type === MaterialType::Still
+            || $adoptedTake->material_type === MaterialType::Still
+                ? MaterialType::Still
+                : MaterialType::Video;
+    }
+}
diff --git a/app/Services/Manual/RenderJobService.php b/app/Services/Manual/RenderJobService.php
index fdf8993..40b10b2 100644
--- a/app/Services/Manual/RenderJobService.php
+++ b/app/Services/Manual/RenderJobService.php
@@ -448,12 +448,16 @@ private function assertTotalSourceDurationWithinLimit(array $ordered): void
         $totalMs = 0;
         foreach ($ordered as $entry) {
             $cut = $entry->cut;
-            if ($cut->material_type === MaterialType::Still && $cut->static_display_seconds !== null) {
-                $totalMs += $cut->static_display_seconds * 1000;
-
-                continue;
-            }
-            $totalMs += $cut->adoptedTake->duration_ms ?? $defaultMs;
+            $take = $cut->adoptedTake;
+            // ここへ来る時点で採用テイクは確定している (充足判定 = AdoptedReadyTakeCoverage が先に 422 を出す)
+            Assert::notNull($take, '充足判定を通った cut には採用テイクが必ず存在する');
+
+            // レンダ (RenderPipeline::clipSpecFor) と**同じ 2 クラス**を通す。
+            // 片方だけ実効判定を持つと、cut=video/take=still の組み合わせで
+            // ゲート 60 秒 / レンダ 5 秒という新しい二重管理が生まれる
+            $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
+                ? StillDisplayDuration::secondsFor($cut) * 1000
+                : ($take->duration_ms ?? $defaultMs);
         }
 
         if ($totalMs > config()->integer('manual.render_max_total_source_ms')) {
diff --git a/app/Services/Manual/RenderPipeline.php b/app/Services/Manual/RenderPipeline.php
index 134b497..048da87 100644
--- a/app/Services/Manual/RenderPipeline.php
+++ b/app/Services/Manual/RenderPipeline.php
@@ -254,7 +254,7 @@ private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderCli
                 cutId: $cut->id,
                 label: $label,
                 source: RenderClipSource::Placeholder,
-                takeVideoPath: null,
+                takeSourcePath: null,
                 stillDisplaySeconds: null,
                 subtitlePrimary: $cut->subtitle_primary,
                 subtitleSecondary: $cut->subtitle_secondary,
@@ -266,16 +266,16 @@ private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderCli
         $take = $cut->adoptedTake;
         Assert::notNull($take, 'isMissing() が false なら採用テイクは必ず存在する');
 
-        $isStill = $cut->material_type === MaterialType::Still;
+        // 実効素材種別の式は EffectiveMaterialType が唯一の所在 (ここに書き直さない)。
+        // 尺ゲート (RenderJobService) も同じ 2 クラスを呼ぶ = ゲートとレンダで尺が食い違わない
+        $isStill = EffectiveMaterialType::of($cut, $take) === MaterialType::Still;
 
         return new RenderClipSpec(
             cutId: $cut->id,
             label: $label,
             source: $isStill ? RenderClipSource::TakeStill : RenderClipSource::TakeVideo,
-            takeVideoPath: $take->video_path,
-            stillDisplaySeconds: $isStill
-                ? ($cut->static_display_seconds ?? config()->integer('manual.preview_placeholder_seconds'))
-                : null,
+            takeSourcePath: $take->video_path,
+            stillDisplaySeconds: $isStill ? StillDisplayDuration::secondsFor($cut) : null,
             subtitlePrimary: $cut->subtitle_primary,
             subtitleSecondary: $cut->subtitle_secondary,
         );
@@ -374,17 +374,23 @@ private function outputKeyFor(VideoManual $manual, RenderJob $job): string
     /**
      * S3 から採用テイク素材を work dir へ取得する (cutId => local path。Placeholder cut は不在)。
      *
+     * ローカル名から拡張子を落としている (旧: `src{$index}.mp4`)。
+     * 拡張子は**以前から既に嘘**だった — `video/webm` / `video/quicktime` のテイクも
+     * `.mp4` という名前で落ちており、合成は最初から **ffmpeg の内容プローブ**に依存している。
+     * 画像素材を足すにあたって嘘を増やす理由が無いので、名前から拡張子ごと外す。
+     * 前例は TakeThumbnailPipeline の `"{$workDir}/source"` (同じく拡張子なしで ffmpeg に渡す)。
+     *
      * @return array<int, string>
      */
     private function downloadSources(RenderManifest $manifest, string $workDir): array
     {
         $localSources = [];
         foreach ($manifest->clips as $index => $clip) {
-            if ($clip->takeVideoPath === null) {
+            if ($clip->takeSourcePath === null) {
                 continue;
             }
-            $localPath = "{$workDir}/src{$index}.mp4";
-            $this->storage->downloadToLocal($clip->takeVideoPath, $localPath);
+            $localPath = "{$workDir}/src{$index}";
+            $this->storage->downloadToLocal($clip->takeSourcePath, $localPath);
             $localSources[$clip->cutId] = $localPath;
         }
 
diff --git a/app/Services/Manual/StillDisplayDuration.php b/app/Services/Manual/StillDisplayDuration.php
new file mode 100644
index 0000000..84cbb76
--- /dev/null
+++ b/app/Services/Manual/StillDisplayDuration.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\Models\Cut;
+
+/**
+ * 静止画カットの表示秒を決める式の**唯一の所在**。
+ *
+ * 編集者が `cuts.static_display_seconds` を指定していればそれを使い、未指定なら
+ * `config('manual.default_still_display_seconds')` を使う。
+ *
+ * 以前は `RenderPipeline` が `manual.preview_placeholder_seconds`
+ * (= 採用テイク欠落 cut のプレースホルダ尺) を流用していた。これは別概念であり、
+ * プレースホルダ尺を変えると完成動画の静止画尺まで黙って変わる状態だった。撤去済み。
+ *
+ * **クランプしない**。異常値を黙って別の値へ変えると設定ミスが隠れる。
+ *
+ * **doc/02 §2.2 の「ナレーション尺より短ければナレーション尺が優先」は v1 では実装しない。**
+ * v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という属性が存在しないためである
+ * (doc/09 の v1 尺算出も `cut_length = material_ms` / 静止画は `static_display_seconds*1000`)。
+ * 再検討の条件は「TTS を導入してナレーション音声の実尺が確定したとき」で、
+ * そのときの変更点は本クラス 1 か所に閉じる。
+ */
+final class StillDisplayDuration
+{
+    public static function secondsFor(Cut $cut): int
+    {
+        return $cut->static_display_seconds
+            ?? config()->integer('manual.default_still_display_seconds');
+    }
+}
diff --git a/app/Services/Render/FfmpegVideoComposer.php b/app/Services/Render/FfmpegVideoComposer.php
index 9a1da1e..d93ade0 100644
--- a/app/Services/Render/FfmpegVideoComposer.php
+++ b/app/Services/Render/FfmpegVideoComposer.php
@@ -9,6 +9,7 @@
 use App\DataTransferObjects\Manual\Render\RenderClipSpec;
 use App\DataTransferObjects\Manual\Render\RenderManifest;
 use App\Exceptions\Manual\RenderCompositionException;
+use App\Support\Media\FfmpegSafetyArguments;
 use Illuminate\Support\Facades\Process;
 use RuntimeException;
 use Webmozart\Assert\Assert;
@@ -114,7 +115,16 @@ private function planTakeVideo(RenderClipSpec $clip, array $localSources, string
     }
 
     /**
-     * 静止画クリップ (採用テイク先頭フレームを static_display_seconds 尺で保持 + 無音声)。
+     * 静止画クリップ (素材の**先頭フレーム**を stillDisplaySeconds 尺で保持 + 無音声)。
+     *
+     * 入力契約: 「1 枚目のフレームを取り出せる入力」であれば動画でも画像でもよい。
+     * 1 段目の `-frames:v 1` は画像入力でも 1 枚の PNG を出すため、
+     * 動画テイク由来の still と画像テイク由来の still を**同じ経路**で扱える。
+     * 「画像なら中間 PNG 化を省く」最適化はしない (通る経路を 2 本にすると片方だけ壊れる形を作る)。
+     *
+     * 2 段目 (`-loop 1 -i frame{n}.png`) が読むのはサーバ生成 PNG だが、その画素数は
+     * **入力素材と同じ**である。画素数の防波堤は FfmpegSafetyArguments の -max_alloc が
+     * 全コマンドに一律で掛ける。
      *
      * @param  array<int, string>  $localSources
      * @return array{list<string>, list<string>, string, int}
@@ -202,7 +212,7 @@ private function runFfmpeg(string $workDir, array $arguments, string $stage): vo
         $binary = config()->string('manual.render_ffmpeg_binary');
         $result = Process::path($workDir)
             ->timeout(self::ENCODE_TIMEOUT_SECONDS)
-            ->run([$binary, ...$arguments]);
+            ->run([$binary, ...FfmpegSafetyArguments::all(), ...$arguments]);
 
         if (! $result->successful()) {
             throw new RenderCompositionException(
@@ -216,7 +226,8 @@ private function probeDurationMs(string $path): int
     {
         $binary = config()->string('manual.render_ffprobe_binary');
         $result = Process::timeout(self::PROBE_TIMEOUT_SECONDS)->run([
-            $binary, '-v', 'error',
+            $binary, ...FfmpegSafetyArguments::all(),
+            '-v', 'error',
             '-show_entries', 'format=duration',
             '-of', 'default=noprint_wrappers=1:nokey=1',
             $path,
@@ -241,7 +252,8 @@ private function hasAudioStream(string $path): bool
     {
         $binary = config()->string('manual.render_ffprobe_binary');
         $result = Process::timeout(self::PROBE_TIMEOUT_SECONDS)->run([
-            $binary, '-v', 'error',
+            $binary, ...FfmpegSafetyArguments::all(),
+            '-v', 'error',
             '-select_streams', 'a',
             '-show_entries', 'stream=index',
             '-of', 'csv=p=0',
diff --git a/app/Support/Capture/TakeMaterialClassifier.php b/app/Support/Capture/TakeMaterialClassifier.php
new file mode 100644
index 0000000..bc1e0f4
--- /dev/null
+++ b/app/Support/Capture/TakeMaterialClassifier.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Capture;
+
+use App\Enums\Manual\MaterialType;
+use InvalidArgumentException;
+
+/**
+ * 申告 Content-Type → 素材種別の写像 (**この写像を書いてよい唯一の場所**)。
+ *
+ * 許可集合の正本は config/capture.php の 2 キー
+ * (`allowed_video_content_types` / `allowed_still_content_types`) である。
+ * 本クラスは「許可集合のどちら側か」だけを答え、許可・不許可の判断はしない
+ * (未許可の値は FormRequest の Rule::in で既に落ちている。到達したら整合性異常として fail-loud)。
+ *
+ * **保証しないもの**: これは**申告**の分類であって、オブジェクトストレージに置かれた
+ * バイト列の実際の形式を保証しない。実体検証は行わない
+ * (docs/architecture.md §撮影 PWA の「保証しないもの」)。
+ */
+final class TakeMaterialClassifier
+{
+    public static function fromContentType(string $contentType): MaterialType
+    {
+        if (in_array($contentType, config()->array('capture.allowed_video_content_types'), true)) {
+            return MaterialType::Video;
+        }
+        if (in_array($contentType, config()->array('capture.allowed_still_content_types'), true)) {
+            return MaterialType::Still;
+        }
+
+        // Assert::true(false, …) にしない — 静的解析が never へ絞れるかが stub 依存になり、
+        // 「戻り値が無い経路」が型の上で残りうる。throw なら構造的に消える
+        throw new InvalidArgumentException("未許可の Content-Type です: {$contentType}");
+    }
+
+    /** オブジェクトキーの拡張子 (許可集合と 1 対 1。TakeUploadService::extensionFor から移設) */
+    public static function extensionFor(string $contentType): string
+    {
+        // 2 メソッドで形を揃える (default => throw で戻り値欠落を構造的に消す)
+        return match ($contentType) {
+            'video/mp4' => 'mp4',
+            'video/webm' => 'webm',
+            'video/quicktime' => 'mov',
+            'image/jpeg' => 'jpg',
+            'image/png' => 'png',
+            default => throw new InvalidArgumentException("未許可の Content-Type です: {$contentType}"),
+        };
+    }
+}
diff --git a/app/Support/Media/FfmpegSafetyArguments.php b/app/Support/Media/FfmpegSafetyArguments.php
new file mode 100644
index 0000000..49d4ce9
--- /dev/null
+++ b/app/Support/Media/FfmpegSafetyArguments.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Media;
+
+/**
+ * ffmpeg / ffprobe の安全境界引数 (**バイナリの直後**に置く)。
+ *
+ * `-max_alloc` は 1 回の heap 確保の上限。**画素数爆弾** (小さいファイルで巨大な画素数を宣言する
+ * 画像) が media キューの worker を OOM で落とし、キューを共有する他組織のサムネイル生成まで
+ * 遅延させることを防ぐ。バイト数の上限 (`capture.max_still_bytes`) では止まらない別の軸である。
+ *
+ * 配置は「最初の -i より前」ではなく **バイナリ直後** に統一する。
+ * ffprobe は入力を -i ではなく**位置引数**で受けるため、-i を基準にすると検査が空振りする。
+ *
+ * **保証しないもの**: プロセス全体の RSS 上限でも、同時実行数の上限でもない。
+ * worker のメモリ cgroup 制限は本リポジトリに存在しない (デプロイ定義が無いため新設もしない)。
+ */
+final class FfmpegSafetyArguments
+{
+    /** @return list<string> */
+    public static function all(): array
+    {
+        // config()->integer() で int を確定させてから明示的に文字列化する
+        // (未型付けの config() 値をコマンド配列へ流さない = list<string> を保つ)
+        return ['-max_alloc', (string) config()->integer('manual.ffmpeg_max_alloc_bytes')];
+    }
+}
diff --git a/config/capture.php b/config/capture.php
index f3b64ae..99a50f4 100644
--- a/config/capture.php
+++ b/config/capture.php
@@ -16,12 +16,22 @@
     // 予約 expires_at = 署名チケット TTL (§10.8-4 の「一定時間」)
     'upload_ticket_ttl_minutes' => 30,
 
-    // 1 テイクの上限サイズ (バリデーション用)
+    // 1 テイクの上限サイズ (バリデーション用。動画)
     'max_take_bytes' => 500 * 1024 * 1024, // 500 MiB
 
     // presigned PUT を許可する動画 Content-Type
     'allowed_video_content_types' => ['video/mp4', 'video/webm', 'video/quicktime'],
 
+    // presigned PUT を許可する静止画 Content-Type。
+    // WebP / HEIC は入れない — クライアントが canvas で JPEG へ再エンコードして送るため不要であり、
+    // ffmpeg のビルド依存 (デコーダの有無) を持ち込まない。
+    'allowed_still_content_types' => ['image/jpeg', 'image/png'],
+
+    // 1 テイクの上限サイズ (静止画)。動画と同じ 500 MiB を許す理由が無い。
+    // これは**バイト数**の上限であり、画素数は縛らない (画素数の防波堤は
+    // manual.ffmpeg_max_alloc_bytes)。
+    'max_still_bytes' => 16 * 1024 * 1024, // 16 MiB
+
     // 採用テイク署名 GET URL (と DL 済み ACK トークン) の TTL
     'playback_url_ttl_minutes' => 60,
 
diff --git a/config/manual.php b/config/manual.php
index cda3f8c..df0dd0d 100644
--- a/config/manual.php
+++ b/config/manual.php
@@ -61,6 +61,11 @@
     'render_default_take_duration_ms' => 60_000,  // duration_ms NULL テイクの保守的代用値
     'render_max_inflight_previews_per_org' => 3,  // org 同時 preview 上限
     'preview_placeholder_seconds' => 3,           // 採用テイク欠落 cut のプレースホルダ尺
+    // 静止画カットの表示秒 (cuts.static_display_seconds 未指定時)。
+    // 編集画面の入力範囲 (1〜60) の内側に置く。env() は持たせない (運用で変える値ではない)
+    'default_still_display_seconds' => 5,
+    // ffmpeg / ffprobe の 1 回あたり heap 確保上限 (バイト)。画素数爆弾で worker を落とさない
+    'ffmpeg_max_alloc_bytes' => 536_870_912,      // 512 MiB
     'render_resolution' => '1920x1080',
     'render_fps' => 30,
     'render_ffmpeg_binary' => env('RENDER_FFMPEG_BINARY', 'ffmpeg'),
diff --git a/database/factories/TakeFactory.php b/database/factories/TakeFactory.php
index 94fa146..a0a7de5 100644
--- a/database/factories/TakeFactory.php
+++ b/database/factories/TakeFactory.php
@@ -4,6 +4,7 @@
 
 namespace Database\Factories;
 
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\TakeStatus;
 use App\Models\Cut;
 use App\Models\Take;
@@ -27,6 +28,8 @@ public function definition(): array
             'cut_id' => Cut::factory(),
             'client_take_id' => (string) Str::ulid(),
             'video_path' => 'takes/'.fake()->uuid().'.mp4',
+            // 既定は動画 (既存テイクは全件動画。既存テストの意味を変えない)
+            'material_type' => MaterialType::Video->value,
             'thumbnail_path' => null,
             'thumbnail_size_bytes' => null,
             'size_bytes' => fake()->numberBetween(100_000, 50_000_000),
@@ -44,6 +47,16 @@ public function forCut(Cut $cut): static
         return $this->state(fn () => ['cut_id' => $cut->id]);
     }
 
+    /** 静止画テイク (画像キー + duration_ms は null) */
+    public function still(): static
+    {
+        return $this->state(fn (): array => [
+            'video_path' => 'takes/'.fake()->uuid().'.jpg',
+            'material_type' => MaterialType::Still->value,
+            'duration_ms' => null,
+        ]);
+    }
+
     /** サムネイル生成済み (容量集計・一覧表示のテスト用) */
     public function withThumbnail(int $sizeBytes = 40_000): static
     {
diff --git a/database/migrations/2026_08_16_220000_add_material_type_to_takes_table.php b/database/migrations/2026_08_16_220000_add_material_type_to_takes_table.php
new file mode 100644
index 0000000..6a681a5
--- /dev/null
+++ b/database/migrations/2026_08_16_220000_add_material_type_to_takes_table.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\MaterialType;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * takes.material_type: 登録された素材の**実体**種別 (cuts.material_type は計画で別概念)。
+     *
+     * 3 段で入れる:
+     * 1. nullable で追加 (既存行を壊さない)
+     * 2. 既存行を 'video' で backfill — presign は今まで
+     *    capture.allowed_video_content_types しか通していないため、既存テイクは全件動画である
+     * 3. NOT NULL 化 (DB default は置かない = INSERT 時の明示代入を強制する。
+     *    ドメイン規約 1 (ii)/2 と同じ理由で、default に依存すると migration 変更で意味が黙って変わる)
+     */
+    public function up(): void
+    {
+        Schema::table('takes', function (Blueprint $table): void {
+            $table->string('material_type')->nullable()->after('video_path');
+        });
+
+        DB::table('takes')->whereNull('material_type')
+            ->update(['material_type' => MaterialType::Video->value]);
+
+        Schema::table('takes', function (Blueprint $table): void {
+            $table->string('material_type')->nullable(false)->change();
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('takes', function (Blueprint $table): void {
+            $table->dropColumn('material_type');
+        });
+    }
+};
diff --git a/docs/architecture.md b/docs/architecture.md
index 590b4ad..a705c9c 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1361,6 +1361,77 @@ ### 撮影 PWA の通し再生 (端末側連結再生) (T191)
   `blocked` (自動再生制限) から自動で抜けること (出口は利用者操作のみ) /
   実機での連続再生の滑らかさ (component テストが見るのは DOM 契約とイベント配線まで)。
 
+### 静止画カットの素材と実効判定 (T192)
+
+静止画カットを PWA / PC から撮影・アップロードし、完成動画へ載せるまでの契約。
+
+- **素材種別の 2 層**: `cuts.material_type` は**計画** (nullable。AI 解析と編集者が決める) で、
+  `takes.material_type` は**登録された素材の実体** (NOT NULL) である。別概念なので合流させない。
+  実体は**予約行の `content_type`** から `Support/Capture/TakeMaterialClassifier` が導き、
+  テイク登録の確定 tx が INSERT 時に明示代入する (payload からは受けない = `$fillable` 外)。
+  DB カラム default は置いていないので、ここが唯一の設定点である。
+- **実効判定は実体優先**: 「このカットを実際にどちらの素材として合成するか」は
+  `Services/Manual/EffectiveMaterialType` が唯一の所在で、**cut の計画が `video` でも
+  採用テイクの実体が画像なら `Still`** を返す。採用した後に編集者がシナリオ編集で
+  `cuts.material_type` を `video` へ戻せるため、入口検証でも採用 API でもこの状態は防げず、
+  画像を動画クリップ経路 (ffprobe で尺を測る) に流すと必ず壊れるからである。
+  レンダ (`RenderPipeline::clipSpecFor`) と尺ゲート (`RenderJobService`) が**同じクラス**を通るので、
+  ゲートとレンダで尺が食い違わない。**ready 判定は持たない** (`AdoptedReadyTakeCoverage` の専権)。
+- **受け入れの非対称**: 入口 (presign) は `still` カットなら画像も動画も受け、
+  `video` / 未指定カットへの画像は 422 で止める (指示と違う素材で容量を消費させない)。
+  一方レンダは実体優先で受ける (既にあるデータで詰ませない)。
+  バイト上限も非対称で、動画は `capture.max_take_bytes` (500 MiB) / 静止画は
+  `capture.max_still_bytes` (16 MiB)。**画素数は縛らない** (画素数の防波堤は下記 `-max_alloc`)。
+- **静止画の表示秒**: `Services/Manual/StillDisplayDuration` が唯一の所在で、
+  `cuts.static_display_seconds` があればその値、無ければ `manual.default_still_display_seconds` (5)。
+  以前 `RenderPipeline` が流用していた `manual.preview_placeholder_seconds`
+  (= 採用テイク欠落 cut のプレースホルダ尺) は別概念なので**撤去した**。
+  **挙動変更**: 秒未指定の静止画カットの尺ゲート計上が 60 秒 (`duration_ms` 欠落の保守的既定) から
+  5 秒になる = 上限が緩む方向に動く。これはレンダの実尺と一致させる是正である。
+- **doc/02 §2.2 の「ナレーション尺より短ければナレーション尺が優先」は v1 では実装しない**。
+  v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という属性が存在しないため
+  (doc/09 の v1 尺算出も静止画は `static_display_seconds*1000`)。再検討の条件は
+  「TTS を導入してナレーション音声の実尺が確定したとき」で、変更点は同クラス 1 か所に閉じる。
+- **サムネイル**: 静止画テイクも生成対象に含める (一覧に原本を貼らないため)。
+  seek は still で 0 固定 (「1 秒地点」が存在しないため、動画既定の 1000ms を当てると
+  1 回目が必ず空振りする)。素材種別は `TakeThumbnailExtractor::extract()` の引数で渡す。
+- **ffmpeg / ffprobe の安全境界**: `Support/Media/FfmpegSafetyArguments` が
+  `-max_alloc` (`manual.ffmpeg_max_alloc_bytes` = 512 MiB) を**バイナリ直後**に一律で付ける。
+  ffprobe は入力を位置引数で受けるため「最初の `-i` より前」を基準にすると検査が空振りする。
+  母集団は `tests/Architecture/FfmpegProcessLaunchInventoryTest` が pin する。
+- **クライアント側の正規化**: 撮影 PWA / PC のどちらも、静止画は canvas で JPEG へ
+  再エンコードしてから送る (`resources/js/lib/capture/still-encode.ts`。長辺 1920 / q0.85)。
+  出力は EXIF を持たないので、サーバ / ffmpeg 側で向きを解釈する必要が無い。
+  エンコードに失敗したら**原本は送らずエラー表示**する。
+
+#### 保証しないもの (誇張しない)
+
+1. **`takes.material_type` は申告 Content-Type からの分類**であり、オブジェクトストレージに
+   置かれたバイト列の実際の形式を保証しない。同期の実体検証 (GET + ffprobe) は採らない
+   (登録は一括再送される経路であり、数十 MB のダウンロードを同期で挟むと 409 の窓が開く)。
+   誤申告の帰結は**向きによって非対称**である:
+   「`image/jpeg` と申告して動画を置いた」場合は先頭フレーム抽出が動画からも 1 枚出すため
+   **成功しうる** (計画 still × 実体 video と同じ経路。害は無い) /
+   「`video/mp4` と申告して画像を置いた」場合は ffprobe が尺を取れず**失敗ジョブ**になる。
+   また `<img>` プレビューは実体がデコード不能なら壊れ得るため、UI に読み込み失敗の受け皿を置いている
+   (`<video>` 側には足していない = 非対称は意図的)。
+2. **`-max_alloc` は 1 回の heap 確保の上限**であって、プロセス全体の RSS 上限でも
+   同時実行数の上限でもない。worker のメモリ cgroup 制限は本リポジトリに存在せず、新設もしない
+   (デプロイ定義が無いため)。**未軽減リスクとして記録する**。
+3. `FfmpegProcessLaunchInventoryTest` は**字句走査**であり、動的に組み立てたコマンド配列や
+   vendor 内部からのプロセス起動には沈黙する。引数の並びの実体は Unit テスト
+   (`Process::fake` の引数列) が固定する。
+4. **レンダ成果物の `upload()` が途中で失敗したときの部分オブジェクトは削除されない**。
+   `RenderPipeline::run()` の `$uploadedKey` への代入は `upload()` の**次の行**にあり、
+   `finally` の後始末は「アップロードが完了したが succeeded に到達しなかった」場合しか拾わない。
+   これは本施策が作った問題ではなく現行構造の性質である (**未軽減**)。
+5. **migration の backfill は「既存テイクは全件動画」という前提に立つ**。根拠は presign が
+   `capture.allowed_video_content_types` しか通していなかったことで、前提が崩れた場合は誤分類になる。
+6. **EXIF 向きの解釈**。「`<img>` デコード時にブラウザが必ず EXIF 向きを適用する」ことは
+   断定しない (デコード API とブラウザで差がある)。断定できるのは出力 JPEG が EXIF を持たないことまで。
+7. **実機 (iOS Safari) での撮影・表示**。component テストが見るのは DOM 契約とイベント配線までである。
+
+
 ## 退会 (アカウント削除) の課金ガード (T115)
 
 - **不変条件**: 「**唯一 Owner** かつ (**他メンバーが残る** ∨ **生きた課金責務がある**) 組織」が
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index 77a022b..176e782 100644
--- a/resources/js/components/features/capture/CameraRecorder.svelte
+++ b/resources/js/components/features/capture/CameraRecorder.svelte
@@ -1,6 +1,7 @@
 <script lang="ts">
     import { onDestroy } from "svelte";
     import {
+        Camera,
         Captions,
         CaptionsOff,
         Circle,
@@ -28,6 +29,7 @@
         CameraUnavailableReason,
         FacingMode,
     } from "@/lib/capture/camera";
+    import { encodeStillJpeg, STILL_CONTENT_TYPE } from "@/lib/capture/still-encode";
     import type { LayoutMode } from "@/lib/capture/landscape-capture";
     import type { CaptureCut } from "@/types/capture";
 
@@ -48,7 +50,8 @@
      * paused も非 idle のため active=true を維持し preview 排他を保つ。
      */
     interface Props {
-        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void | Promise<void>;
+        /** 撮影データの引き渡し。静止画には尺が無いので durationMs は null になる */
+        onCaptured: (blob: Blob, mimeType: string, durationMs: number | null) => void | Promise<void>;
         /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
         onCameraUnavailable: (reason: CameraUnavailableReason) => void;
         /** 選択中カットの字幕 (撮影ガイド overlay 用。焼込ではない)。既定は空 (字幕なし) */
@@ -67,6 +70,15 @@
          * 非 null かつ非空へ絞る判定は本 component の内側 1 か所で行う。
          */
         shootingPoint?: CaptureCut["shooting_point"];
+        /**
+         * 撮影モード (静止画カット対応)。**既定は従来どおり "video"**。
+         * "still" では MediaRecorder を一切使わず、phase は idle のまま
+         * 「録画開始」の位置にシャッターを出す。
+         * 本 props は表示分岐と shootStill の 1 経路だけを足し、
+         * phase マシン・stream 管理・flip・grid・字幕 overlay・カメラ喪失時の
+         * フォールバック委譲には**一切触れない** (layout props と同じ作法)。
+         */
+        mode?: "video" | "still";
     }
 
     let {
@@ -77,8 +89,11 @@
         onCaptureActiveChange,
         layout = "inline",
         shootingPoint = null,
+        mode = "video",
     }: Props = $props();
 
+    const isStillMode = $derived(mode === "still");
+
     // --- 全画面レイアウト (表示のみ。phase マシンとは独立) ---
     const isFullscreen = $derived(layout === "fullscreen");
     /**
@@ -319,6 +334,40 @@
         }
     }
 
+    /**
+     * 静止画の撮影。live preview の現在フレームを 1 枚取り出して親へ渡す。
+     * ImageCapture API は iOS Safari が未対応で、撮影 PWA の主戦場が iOS Safari のため
+     * canvas 経路を採る。
+     *
+     * 再入ガードと active 通知は **startRecording() と完全に同じ形**にする (独自フラグを増やさない)。
+     * `starting` は「grant 待ちの窓でも preview を開けない」ための公開 active の構成要素であり、
+     * `acquirePreviewStream()` 側には starting の再入ガードが無いので、先に立てても stream 取得は
+     * 塞がらない (startRecording が `starting = true` → `syncActive()` → `acquirePreviewStream()` の順)。
+     */
+    async function shootStill(): Promise<void> {
+        if (starting || resuming || phase !== "idle") return;
+        starting = true;
+        syncActive(); // 押下時点で active=true (取得中に preview を開かせない)
+        try {
+            error = null;
+            if (stream === null && !(await acquirePreviewStream())) return;
+            if (video === null) return;
+            // encodeStillJpeg は reject しない契約 (失敗は null)
+            const blob = await encodeStillJpeg(video, video.videoWidth, video.videoHeight);
+            if (blob === null || blob.size === 0) {
+                error = "写真を取得できませんでした。もう一度お試しください。";
+                return;
+            }
+            await onCaptured(blob, STILL_CONTENT_TYPE, null);
+        } catch {
+            // onCaptured (アップロード) 側の失敗を未処理 rejection にしない (録画経路の onstop と同じ)
+            error = "撮影データの処理に失敗しました。もう一度お試しください。";
+        } finally {
+            starting = false;
+            syncActive();
+        }
+    }
+
     // 一時停止要求 (recording のみ)。pending 中 (種別を問わず) は多重押下ガードで拒否。
     function requestPause(): void {
         if (phase !== "recording" || pendingOperation !== null || recorder === null) return;
@@ -568,10 +617,17 @@
             : "flex items-center justify-center gap-3"}
     >
         {#if phase === "idle"}
-            <Button variant="primary" onclick={startRecording} testId="start-recording">
-                <Circle class="size-4" aria-hidden="true" />
-                録画開始
-            </Button>
+            {#if isStillMode}
+                <Button variant="primary" onclick={shootStill} testId="shoot-still">
+                    <Camera class="size-4" aria-hidden="true" />
+                    写真を撮る
+                </Button>
+            {:else}
+                <Button variant="primary" onclick={startRecording} testId="start-recording">
+                    <Circle class="size-4" aria-hidden="true" />
+                    録画開始
+                </Button>
+            {/if}
             <!-- カメラ反転 (idle のみ表示 = 文脈非該当時は非表示。disabled ではない) -->
             <button
                 type="button"
diff --git a/resources/js/components/features/capture/CaptureFileFallback.svelte b/resources/js/components/features/capture/CaptureFileFallback.svelte
index e50b24c..3fdcdcd 100644
--- a/resources/js/components/features/capture/CaptureFileFallback.svelte
+++ b/resources/js/components/features/capture/CaptureFileFallback.svelte
@@ -1,29 +1,54 @@
 <script lang="ts">
-    import { Video } from "@lucide/svelte";
+    import { Camera, Video } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
+    import { normalizeStillFile, STILL_CONTENT_TYPE } from "@/lib/capture/still-encode";
 
     /**
      * MediaRecorder 非対応環境 (iOS Safari 等) のフォールバック撮影 (概念設計 D9)。
      * OS ネイティブのカメラ/ファイル選択を capture 属性で起動する。
+     *
+     * カットの計画が静止画なら画像を選ばせ、**必ず再エンコードしてから**親へ渡す
+     * (寸法上限が効き、EXIF が落ちる)。正規化に失敗したときは原本を送らない。
      */
     interface Props {
-        onCaptured: (file: File) => void;
+        /** cut の計画。still なら画像を選ばせ、正規化してから親へ渡す */
+        material?: "video" | "still";
+        onCaptured: (blob: Blob, contentType: string) => void | Promise<void>;
     }
 
-    let { onCaptured }: Props = $props();
+    let { material = "video", onCaptured }: Props = $props();
     let input: HTMLInputElement | null = $state(null);
     let error = $state<string | null>(null);
 
-    function handleChange(): void {
+    const isStill = $derived(material === "still");
+    const accept = $derived(isStill ? "image/*" : "video/*");
+
+    async function handleChange(): Promise<void> {
         error = null;
         const file = input?.files?.[0];
-        if (!file) return;
-        if (!file.type.startsWith("video/")) {
-            error = "動画ファイルを選択してください。";
-            return;
+        try {
+            if (!file) return;
+            const expected = isStill ? "image/" : "video/";
+            if (!file.type.startsWith(expected)) {
+                error = isStill
+                    ? "画像ファイルを選択してください。"
+                    : "動画ファイルを選択してください。";
+                return;
+            }
+            if (!isStill) {
+                await onCaptured(file, file.type.split(";")[0]);
+                return;
+            }
+            // 静止画は必ず再エンコードして送る (寸法上限 + EXIF を落とす)
+            const normalized = await normalizeStillFile(file);
+            if (normalized === null) {
+                error = "画像を読み込めませんでした。別のファイルをお試しください。";
+                return; // 原本は送らない
+            }
+            await onCaptured(normalized, STILL_CONTENT_TYPE);
+        } finally {
+            if (input) input.value = "";
         }
-        onCaptured(file);
-        if (input) input.value = "";
     }
 </script>
 
@@ -31,18 +56,25 @@
     <input
         bind:this={input}
         type="file"
-        accept="video/*"
+        {accept}
         capture="environment"
         class="hidden"
         onchange={handleChange}
         data-testid="capture-file-input"
     />
     <Button variant="primary" onclick={() => input?.click()} testId="capture-file-button">
-        <Video class="size-5" aria-hidden="true" />
-        カメラで撮影 / 動画を選択
+        {#if isStill}
+            <Camera class="size-5" aria-hidden="true" />
+            カメラで撮影 / 画像を選択
+        {:else}
+            <Video class="size-5" aria-hidden="true" />
+            カメラで撮影 / 動画を選択
+        {/if}
     </Button>
     <p class="text-caption text-text-secondary">
-        この端末ではカメラアプリで撮影し、動画を選択してアップロードします。
+        {isStill
+            ? "この端末ではカメラアプリで撮影し、画像を選択してアップロードします。"
+            : "この端末ではカメラアプリで撮影し、動画を選択してアップロードします。"}
     </p>
     {#if error}
         <p class="text-caption text-danger" role="alert">{error}</p>
diff --git a/resources/js/components/features/capture/TakePreviewDialog.svelte b/resources/js/components/features/capture/TakePreviewDialog.svelte
index 5abf6ac..33a4a6f 100644
--- a/resources/js/components/features/capture/TakePreviewDialog.svelte
+++ b/resources/js/components/features/capture/TakePreviewDialog.svelte
@@ -37,6 +37,22 @@
 
     let video: HTMLVideoElement | undefined = $state();
     let subtitlesOn = $state(true);
+    /**
+     * <img> の読み込み失敗フラグ。素材種別は**申告 Content-Type からの分類**であり
+     * 実体の形式を保証しないため (docs/architecture.md の非保証)、
+     * still と申告された実体がデコードできない場合に「何も出ない」状態を作らない。
+     * <video> 側には足さない (既存挙動を変えないため。非対称は意図的)。
+     *
+     * {#key} には頼らない — DOM は作り直されても <script> の $state は再生成されないので、
+     * 前のテイクの失敗が残る。take の切り替えを $effect で監視して明示的に戻す。
+     */
+    let imageFailed = $state(false);
+    $effect(() => {
+        // take.id / playbackUrl の変化を購読して失敗状態をリセットする
+        void take?.id;
+        void playbackUrl;
+        imageFailed = false;
+    });
 
     // 再オープン時に字幕を初期 ON へ戻す (撮影 PWA は初期 ON。doc/05)。
     $effect(() => {
@@ -55,7 +71,7 @@
     // close / 採用成功で閉じる / take 差し替え / component 破棄を同一 cleanup で扱う。
     // effect 実行時の要素を固定し、差し替え時に新要素を誤 teardown しない。
     $effect(() => {
-        if (!open || take === null || video === undefined) return;
+        if (!open || take === null || take.material_type === "still" || video === undefined) return;
         const target = video;
         return () => teardownVideo(target);
     });
@@ -76,18 +92,38 @@
     <div class="flex flex-col gap-3">
         <div class="relative w-full overflow-hidden rounded-md bg-text/5">
             {#if open && take !== null}
-                {#key take.id}
-                    <!-- svelte-ignore a11y_media_has_caption -->
-                    <video
-                        bind:this={video}
-                        controls
-                        playsinline
-                        src={playbackUrl ?? undefined}
-                        class="w-full"
-                        aria-label={`${cutLabel} のテイク再生`}
-                        data-testid="take-preview-video"
-                    ></video>
-                {/key}
+                {#if take.material_type === "still"}
+                    {#if imageFailed}
+                        <p
+                            class="p-6 text-center text-caption text-text-secondary"
+                            role="status"
+                            data-testid="take-preview-unavailable"
+                        >
+                            このテイクはプレビューできません。
+                        </p>
+                    {:else}
+                        <img
+                            src={playbackUrl ?? undefined}
+                            alt={`${cutLabel} のテイク`}
+                            class="w-full"
+                            onerror={() => (imageFailed = true)}
+                            data-testid="take-preview-image"
+                        />
+                    {/if}
+                {:else}
+                    {#key take.id}
+                        <!-- svelte-ignore a11y_media_has_caption -->
+                        <video
+                            bind:this={video}
+                            controls
+                            playsinline
+                            src={playbackUrl ?? undefined}
+                            class="w-full"
+                            aria-label={`${cutLabel} のテイク再生`}
+                            data-testid="take-preview-video"
+                        ></video>
+                    {/key}
+                {/if}
             {/if}
 
             {#if subtitlesOn}
diff --git a/resources/js/components/features/capture/TakeStrip.svelte b/resources/js/components/features/capture/TakeStrip.svelte
index cbee559..e06ea82 100644
--- a/resources/js/components/features/capture/TakeStrip.svelte
+++ b/resources/js/components/features/capture/TakeStrip.svelte
@@ -1,6 +1,16 @@
 <script lang="ts">
     import { onMount } from "svelte";
-    import { Check, ChevronDown, ChevronUp, Download, Film, Pencil, Play, Trash2 } from "@lucide/svelte";
+    import {
+        Check,
+        ChevronDown,
+        ChevronUp,
+        Download,
+        Film,
+        Image,
+        Pencil,
+        Play,
+        Trash2,
+    } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import DragHandle from "@/components/atoms/DragHandle.svelte";
@@ -307,7 +317,12 @@
                     data-testid={`take-thumbnail-placeholder-${take.id}`}
                     aria-hidden="true"
                 >
-                    <Film class="size-4 text-text-secondary" aria-hidden="true" />
+                    <!-- 未生成プレースホルダのアイコンだけ素材種別で替える (寸法は同じ = 跳ねない) -->
+                    {#if take.material_type === "still"}
+                        <Image class="size-4 text-text-secondary" aria-hidden="true" />
+                    {:else}
+                        <Film class="size-4 text-text-secondary" aria-hidden="true" />
+                    {/if}
                 </div>
             {/if}
             <div class="flex shrink-0 flex-col gap-1">
diff --git a/resources/js/components/features/manual/ScenarioEditor.svelte b/resources/js/components/features/manual/ScenarioEditor.svelte
index 138bb94..b80d2d0 100644
--- a/resources/js/components/features/manual/ScenarioEditor.svelte
+++ b/resources/js/components/features/manual/ScenarioEditor.svelte
@@ -1104,8 +1104,17 @@
                         <span data-testid="video-cell-count">
                             テイク {summary?.takes_count ?? 0} 件
                         </span>
+                        <!--
+                          素材登録状況 (doc/02 §2.4 の 3 値)。「採用テイクが在るか」と
+                          「その素材種別」だけで決める。ready かどうかは別軸なので、ここでは
+                          言わない (充足の告知は既存の詳細画面 props が担当)。
+                        -->
                         {#if adopted !== null}
-                            <Badge tone="primary" testId="video-cell-adopted">採用済み</Badge>
+                            <Badge tone="primary" testId="video-cell-material">
+                                {adopted.material_type === "still" ? "静止画登録済" : "動画登録済"}
+                            </Badge>
+                        {:else}
+                            <Badge tone="neutral" testId="video-cell-material">未登録</Badge>
                         {/if}
                     </p>
                     <div class="mt-2">
diff --git a/resources/js/components/features/manual/TakeFileUpload.svelte b/resources/js/components/features/manual/TakeFileUpload.svelte
index fa36e7b..81ae96a 100644
--- a/resources/js/components/features/manual/TakeFileUpload.svelte
+++ b/resources/js/components/features/manual/TakeFileUpload.svelte
@@ -2,11 +2,13 @@
     import { Upload } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
+    import { normalizeStillFile, STILL_CONTENT_TYPE } from "@/lib/capture/still-encode";
     import {
         createMemoryPendingStore,
         generateClientTakeId,
         UploadQueue,
     } from "@/lib/capture/upload-queue";
+    import type { CutMaterialType } from "@/types/manual";
 
     /**
      * PC ローカル動画の追加アップロード (doc/04)。
@@ -18,10 +20,17 @@
         projectId: number;
         manualId: number;
         cutId: number;
+        /**
+         * カットの**計画**。still なら画像を選ばせ、正規化してから送る (尺チェックは行わない)。
+         * 未指定 (null) は従来どおり動画として扱う。
+         */
+        material?: CutMaterialType | null;
         onUploaded: () => void;
     }
 
-    let { projectId, manualId, cutId, onUploaded }: Props = $props();
+    let { projectId, manualId, cutId, material = null, onUploaded }: Props = $props();
+
+    const isStill = $derived(material === "still");
 
     // store を自前で保持するのは、queued (オフライン等) の Blob を PC 側に残さないため
     const store = createMemoryPendingStore();
@@ -72,17 +81,34 @@
         // どの経路を通っても input を空に戻す (同じファイルの再選択で change が出ない問題を避ける)
         try {
             if (!file) return;
-            if (!file.type.startsWith("video/")) {
-                error = "動画ファイルを選択してください。";
+            if (!file.type.startsWith(isStill ? "image/" : "video/")) {
+                error = isStill
+                    ? "画像ファイルを選択してください。"
+                    : "動画ファイルを選択してください。";
                 return;
             }
-            const durationMs = await readDurationMs(file);
-            // 押下は受けてからエラーを出す (disabled にしない)。
-            // 断定形にしない = サーバ強制ではないため「登録できません」とは書かない
-            if (durationMs !== null && durationMs > MAX_DURATION_MS) {
-                error =
-                    "動画の長さが 1 分を超えています。1 分以内に切り出してからアップロードしてください。";
-                return; // upload-url を呼ばない = quota を消費しない
+            // 送信ペイロード。静止画に尺は無いので readDurationMs を通さず、
+            // 必ず再エンコードして送る (寸法上限 + EXIF を落とす)
+            let blob: Blob = file;
+            let contentType = file.type.split(";")[0];
+            let durationMs: number | null = null;
+            if (isStill) {
+                const normalized = await normalizeStillFile(file);
+                if (normalized === null) {
+                    error = "画像を読み込めませんでした。別のファイルをお試しください。";
+                    return; // 原本は送らない
+                }
+                blob = normalized;
+                contentType = STILL_CONTENT_TYPE;
+            } else {
+                durationMs = await readDurationMs(file);
+                // 押下は受けてからエラーを出す (disabled にしない)。
+                // 断定形にしない = サーバ強制ではないため「登録できません」とは書かない
+                if (durationMs !== null && durationMs > MAX_DURATION_MS) {
+                    error =
+                        "動画の長さが 1 分を超えています。1 分以内に切り出してからアップロードしてください。";
+                    return; // upload-url を呼ばない = quota を消費しない
+                }
             }
             uploading = true;
             const clientTakeId = generateClientTakeId();
@@ -91,8 +117,8 @@
                 projectId,
                 manualId,
                 cutId,
-                blob: file,
-                contentType: file.type.split(";")[0],
+                blob,
+                contentType,
                 durationMs,
                 capturedAt: new Date().toISOString(),
             });
@@ -119,9 +145,13 @@
 </script>
 
 <Card padding="md" testId="take-file-upload">
-    <h2 class="text-body font-medium text-text">動画ファイルを追加</h2>
+    <h2 class="text-body font-medium text-text">
+        {isStill ? "画像ファイルを追加" : "動画ファイルを追加"}
+    </h2>
     <p class="mt-1 text-caption text-text-secondary">
-        PC にある動画を、このカットのテイクとして追加できます (1 分以内が目安です)。
+        {isStill
+            ? "PC にある画像を、このカットのテイクとして追加できます。"
+            : "PC にある動画を、このカットのテイクとして追加できます (1 分以内が目安です)。"}
     </p>
     <!--
       file input は視覚的に隠し、押下導線は Button atom に寄せる
@@ -131,7 +161,7 @@
     <input
         bind:this={input}
         type="file"
-        accept="video/*"
+        accept={isStill ? "image/*" : "video/*"}
         class="hidden"
         onchange={handleChange}
         data-testid="take-file-input"
@@ -144,7 +174,7 @@
             testId="take-file-select"
         >
             <Upload class="size-4" aria-hidden="true" />
-            動画ファイルを選ぶ
+            {isStill ? "画像ファイルを選ぶ" : "動画ファイルを選ぶ"}
         </Button>
     </div>
     {#if error}
diff --git a/resources/js/components/features/manual/TakePreviewPanel.svelte b/resources/js/components/features/manual/TakePreviewPanel.svelte
index 546d001..c02f041 100644
--- a/resources/js/components/features/manual/TakePreviewPanel.svelte
+++ b/resources/js/components/features/manual/TakePreviewPanel.svelte
@@ -43,6 +43,18 @@
 
     let error = $state<string | null>(null);
     let busy = $state(false);
+    /**
+     * <img> の読み込み失敗フラグ。素材種別は**申告 Content-Type からの分類**であり
+     * 実体の形式を保証しないため、still と申告された実体がデコードできない場合に
+     * 「何も出ない」状態を作らない。<video> 側には足さない (非対称は意図的)。
+     */
+    let imageFailed = $state(false);
+    $effect(() => {
+        void take?.id; // テイクを切り替えたら失敗状態を戻す
+        imageFailed = false;
+    });
+
+    const isStill = $derived(take?.material_type === "still");
 
     // ready 以外はサーバが 404 を返すため src を張らず <video> 自体を描かない
     // (無駄な要素とネットワーク要求を出さない)
@@ -100,17 +112,37 @@
 <Card padding="md" testId="take-preview-panel">
     <div class="relative w-full overflow-hidden rounded-md bg-text/5">
         {#if playbackUrl !== null && take !== null}
-            {#key take.id}
-                <!-- svelte-ignore a11y_media_has_caption -->
-                <video
-                    controls
-                    playsinline
-                    src={playbackUrl}
-                    class="w-full"
-                    aria-label={`${cut.label} のテイク ${(takeIndex ?? 0) + 1}`}
-                    data-testid="take-preview-video"
-                ></video>
-            {/key}
+            {#if isStill}
+                {#if imageFailed}
+                    <p
+                        class="p-6 text-center text-caption text-text-secondary"
+                        role="status"
+                        data-testid="take-preview-unavailable"
+                    >
+                        このテイクはプレビューできません。
+                    </p>
+                {:else}
+                    <img
+                        src={playbackUrl}
+                        alt={`${cut.label} のテイク ${(takeIndex ?? 0) + 1}`}
+                        class="w-full"
+                        onerror={() => (imageFailed = true)}
+                        data-testid="take-preview-image"
+                    />
+                {/if}
+            {:else}
+                {#key take.id}
+                    <!-- svelte-ignore a11y_media_has_caption -->
+                    <video
+                        controls
+                        playsinline
+                        src={playbackUrl}
+                        class="w-full"
+                        aria-label={`${cut.label} のテイク ${(takeIndex ?? 0) + 1}`}
+                        data-testid="take-preview-video"
+                    ></video>
+                {/key}
+            {/if}
             <SubtitleOverlay
                 primary={cut.subtitle_primary}
                 secondary={cut.subtitle_secondary}
@@ -132,7 +164,7 @@
         <p class="mt-2 text-caption text-text-secondary" data-testid="take-not-playable">
             {take === null
                 ? "左の一覧からテイクを選ぶと再生できます。"
-                : `このテイクはまだ再生できません（${TAKE_STATUS_LABELS[take.status]}）。`}
+                : `このテイクはまだ${isStill ? "表示" : "再生"}できません（${TAKE_STATUS_LABELS[take.status]}）。`}
         </p>
     {/if}
 
diff --git a/resources/js/lib/capture/camera.ts b/resources/js/lib/capture/camera.ts
index 3cedf4f..b4878c6 100644
--- a/resources/js/lib/capture/camera.ts
+++ b/resources/js/lib/capture/camera.ts
@@ -12,6 +12,15 @@ export function supportsMediaRecorder(): boolean {
     );
 }
 
+/**
+ * 静止画撮影に必要な能力 (getUserMedia のみ。**MediaRecorder は要らない**)。
+ * supportsMediaRecorder() を静止画にも流用すると、MediaRecorder 非対応端末で
+ * 撮れるはずの写真まで file input へ落ちてしまう。
+ */
+export function supportsStillCapture(): boolean {
+    return typeof navigator.mediaDevices?.getUserMedia === "function";
+}
+
 /** 録画に使う MIME type (mp4 優先。どちらも不可なら null) */
 export function preferredRecordingMimeType(): string | null {
     if (typeof window.MediaRecorder === "undefined") return null;
diff --git a/resources/js/lib/capture/still-encode.ts b/resources/js/lib/capture/still-encode.ts
new file mode 100644
index 0000000..4f3d5f9
--- /dev/null
+++ b/resources/js/lib/capture/still-encode.ts
@@ -0,0 +1,88 @@
+/**
+ * 静止画テイクのエンコード規約 (**この 3 値の唯一の所在**)。
+ * シャッター経路とファイル正規化経路の両方がここから読む (component に直書きしない)。
+ *
+ * PHP config には置かない — サーバはこの 3 値をまったく使わず、サーバが強制するのは
+ * capture.max_still_bytes (バイト数) だけである。使わない値を props で往復させると
+ * 二重管理になる。既定値の出力は通常 1 MB 未満で max_still_bytes (16 MiB) に十分収まる。
+ */
+export const STILL_MAX_EDGE = 1920;
+export const STILL_JPEG_QUALITY = 0.85;
+export const STILL_CONTENT_TYPE = "image/jpeg";
+
+/** 長辺 STILL_MAX_EDGE に収まる描画寸法 (縮小のみ。拡大はしない) */
+export function fitWithinMaxEdge(
+    width: number,
+    height: number,
+): { width: number; height: number } {
+    const longest = Math.max(width, height);
+    if (longest <= STILL_MAX_EDGE || longest === 0) return { width, height };
+    const scale = STILL_MAX_EDGE / longest;
+    return { width: Math.round(width * scale), height: Math.round(height * scale) };
+}
+
+/**
+ * 任意の描画可能ソース (HTMLVideoElement / HTMLImageElement) を JPEG blob へ再エンコードする。
+ *
+ * **失敗は必ず `null` で返す (reject しない)**。`drawImage()` は tainted canvas 等で throw し、
+ * `toBlob()` も実装によっては throw しうる。呼び出し側に `.catch()` を配って回ると必ず漏れるので、
+ * **契約をこの 1 か所で閉じる** (canvas 2d 取得不可 / 寸法 0 / 例外 / toBlob が null = すべて null)。
+ * 呼び出し側は null を見たら**原本を送らずエラー表示する**。
+ */
+export async function encodeStillJpeg(
+    source: CanvasImageSource,
+    naturalWidth: number,
+    naturalHeight: number,
+): Promise<Blob | null> {
+    try {
+        const size = fitWithinMaxEdge(naturalWidth, naturalHeight);
+        if (size.width === 0 || size.height === 0) return null;
+        const canvas = document.createElement("canvas");
+        canvas.width = size.width;
+        canvas.height = size.height;
+        const context = canvas.getContext("2d");
+        if (context === null) return null;
+        context.drawImage(source, 0, 0, size.width, size.height);
+
+        return await new Promise((resolve) => {
+            try {
+                canvas.toBlob((blob) => resolve(blob), STILL_CONTENT_TYPE, STILL_JPEG_QUALITY);
+            } catch {
+                resolve(null);
+            }
+        });
+    } catch {
+        return null;
+    }
+}
+
+/**
+ * ファイル選択で選ばれた画像を正規化する (再エンコード)。
+ * - 断定できること: 出力 JPEG は **EXIF を持たない** ので、サーバ/ffmpeg 側で向きを解釈する
+ *   必要が無い。寸法上限も同時に効く。
+ * - 断定しないこと: 「<img> デコード時にブラウザが必ず EXIF 向きを適用する」とは書かない
+ *   (デコード API とブラウザで差がある)。
+ */
+export function normalizeStillFile(file: File): Promise<Blob | null> {
+    return new Promise((resolve) => {
+        const url = URL.createObjectURL(file);
+        const image = new Image();
+        let settled = false;
+        const finish = (value: Blob | null): void => {
+            if (settled) return;
+            settled = true;
+            clearTimeout(timer);
+            URL.revokeObjectURL(url);
+            resolve(value);
+        };
+        const timer = setTimeout(() => finish(null), 5_000);
+        image.onload = () => {
+            // encodeStillJpeg は reject しない契約だが、二重に閉じる (未処理 rejection を残さない)
+            void encodeStillJpeg(image, image.naturalWidth, image.naturalHeight)
+                .then(finish)
+                .catch(() => finish(null));
+        };
+        image.onerror = () => finish(null);
+        image.src = url;
+    });
+}
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 2837948..da0eb10 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -16,7 +16,7 @@
     import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import { AdoptedTakeAutoDownloader } from "@/lib/capture/auto-download";
-    import { supportsMediaRecorder } from "@/lib/capture/camera";
+    import { supportsMediaRecorder, supportsStillCapture } from "@/lib/capture/camera";
     import type { CameraUnavailableReason } from "@/lib/capture/camera";
     import { buildCutLabels } from "@/lib/capture/cut-labels";
     import {
@@ -74,10 +74,16 @@
     const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
     /** 手順 N / 急所 N-M。CutNavigator の行ラベルと同じ導出元を共有する (二重管理を避ける) */
     const cutLabels = $derived(buildCutLabels(manual.cuts));
-    // 静的 feature-detect (従来) + 実行時失敗による上書き (F-03: doc/10 §10.8-3)
-    const canRecord = typeof window !== "undefined" && supportsMediaRecorder();
+    /** cut の計画で撮影モードを決める (撮影者に判断させない = 使命) */
+    const captureMode = $derived(selectedCut?.material_type === "still" ? "still" : "video");
+    // 静的 feature-detect (従来) + 実行時失敗による上書き (F-03: doc/10 §10.8-3)。
+    // 静止画は MediaRecorder を必要としないので判定を分ける
+    const canCapture = $derived(
+        typeof window !== "undefined" &&
+            (captureMode === "still" ? supportsStillCapture() : supportsMediaRecorder()),
+    );
     let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null);
-    const showRecorder = $derived(canRecord && cameraUnavailableReason === null);
+    const showRecorder = $derived(canCapture && cameraUnavailableReason === null);
     // 撮影 active (recording|stopping) と recorder 参照 (preview の資源競合制御。T050 / S4)
     let captureActive = $state(false);
     let recorderRef = $state<CameraRecorderType | null>(null);
@@ -700,6 +706,7 @@
                             }}
                             layout={fullscreenActive ? "fullscreen" : "inline"}
                             shootingPoint={selectedCut.shooting_point}
+                            mode={captureMode}
                         />
                     {:else}
                         {#if fallbackNotice !== null}
@@ -712,7 +719,9 @@
                             </p>
                         {/if}
                         <CaptureFileFallback
-                            onCaptured={(file) => handleCaptured(file, file.type, null)}
+                            material={captureMode}
+                            onCaptured={(blob, contentType) =>
+                                handleCaptured(blob, contentType, null)}
                         />
                     {/if}
                 </div>
diff --git a/resources/js/pages/Manuals/Takes.svelte b/resources/js/pages/Manuals/Takes.svelte
index 1837409..fc90975 100644
--- a/resources/js/pages/Manuals/Takes.svelte
+++ b/resources/js/pages/Manuals/Takes.svelte
@@ -79,6 +79,7 @@
                         projectId={project.id}
                         manualId={manual.id}
                         cutId={cut.id}
+                        material={cut.material_type}
                         onUploaded={refresh}
                     />
                 </div>
diff --git a/resources/js/types/capture.ts b/resources/js/types/capture.ts
index f5094ae..b12a756 100644
--- a/resources/js/types/capture.ts
+++ b/resources/js/types/capture.ts
@@ -5,10 +5,15 @@
 
 export type TakeStatus = "uploading" | "processing" | "ready" | "failed";
 
+/** PHP: App\Enums\Manual\MaterialType と値集合を一致させる */
+export type MaterialType = "video" | "still";
+
 export interface CaptureTake {
     id: number;
     client_take_id: string;
     status: TakeStatus;
+    /** 登録された素材の**実体** (NOT NULL)。UI はこの値で <video> と <img> を出し分ける */
+    material_type: MaterialType;
     size_bytes: number;
     duration_ms: number | null;
     comment: string | null;
@@ -33,6 +38,8 @@ export interface CaptureCut {
     narration: string;
     subtitle_primary: string | null;
     subtitle_secondary: string;
+    /** カットの**計画** (未指定あり)。撮影 UI (シャッター / 録画) の出し分けに使う */
+    material_type: MaterialType | null;
     adopted_take_id: number | null;
     /**
      * 通し再生が再生するテイクの id (サーバが `AdoptedReadyTakeCoverage` で決めた値)。
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 2c6da10..20e73ef 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -160,7 +160,7 @@ export interface ScenarioPoint {
     narration: string;
     subtitle_primary: string | null;
     subtitle_secondary: string;
-    material_type: "video" | "still" | null;
+    material_type: CutMaterialType | null;
     static_display_seconds: number | null;
 }
 
@@ -333,6 +333,9 @@ export interface ScenarioConflictBody {
 /** PHP: App\Enums\Manual\TakeStatus と値集合を一致させる (literal union) */
 export type SelectableTakeStatus = "uploading" | "processing" | "ready" | "failed";
 
+/** PHP: App\Enums\Manual\MaterialType と値集合を一致させる */
+export type CutMaterialType = "video" | "still";
+
 /** テイクの状態ラベル (UI 共通)。satisfies でキー漏れをコンパイル時検出する */
 export const TAKE_STATUS_LABELS = {
     uploading: "アップロード中",
@@ -353,6 +356,8 @@ export const TAKE_ADOPTABLE_BY_STATUS = {
 export interface SelectableTake {
     id: number;
     status: SelectableTakeStatus;
+    /** 登録された素材の**実体** (NOT NULL)。UI はこの値で <video> と <img> を出し分ける */
+    material_type: CutMaterialType;
     size_bytes: number;
     duration_ms: number | null;
     comment: string | null;
@@ -373,7 +378,9 @@ export interface TakeSelectionCut {
     narration: string;
     subtitle_primary: string | null;
     subtitle_secondary: string;
-    adopted: { id: number; status: SelectableTakeStatus } | null;
+    /** カットの**計画** (未指定あり)。ファイル選択の accept 切替に使う */
+    material_type: CutMaterialType | null;
+    adopted: { id: number; status: SelectableTakeStatus; material_type: CutMaterialType } | null;
 }
 
 /** PHP: TakeSelectionPageData::toArray() 全体と対 (Manuals/Takes の props) */
@@ -393,5 +400,7 @@ export interface CutTakeSummary {
         status: SelectableTakeStatus;
         /** サムネイル生成済みか。true のときだけ .../takes/{id}/thumbnail を表示に使う */
         has_thumbnail: boolean;
+        /** 採用テイクの**実体**種別 (NOT NULL)。素材登録状況バッジの文言に使う */
+        material_type: CutMaterialType;
     } | null;
 }
diff --git a/tests/Architecture/FfmpegProcessLaunchInventoryTest.php b/tests/Architecture/FfmpegProcessLaunchInventoryTest.php
new file mode 100644
index 0000000..d82c6fe
--- /dev/null
+++ b/tests/Architecture/FfmpegProcessLaunchInventoryTest.php
@@ -0,0 +1,76 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Media\FfmpegSafetyArguments;
+use Symfony\Component\Finder\Finder;
+
+/*
+ * 守る不変条件: app/ から起動する ffmpeg / ffprobe プロセスは 1 本残らず
+ * FfmpegSafetyArguments::all() (= -max_alloc) をバイナリ直後に持つ。
+ *
+ * 検査 1 (母集団の pin): app/ 配下で 'manual.render_ffmpeg_binary' /
+ *   'manual.render_ffprobe_binary' を参照するファイルを走査し、現行 2 ファイルと
+ *   完全一致することを assert する (増減のどちらでも赤になる)。
+ * 検査 2 (併記の pin): その 2 ファイルが FfmpegSafetyArguments を import していること。
+ *
+ * ★ 保証範囲を誇張しない: これは**字句走査**であり、動的に組み立てたコマンド配列や
+ *   vendor 内部からのプロセス起動には沈黙する。「引数の並び」の実体は Unit テスト
+ *   (Process::fake の引数列: FfmpegVideoComposerTest / FfmpegTakeThumbnailExtractorTest) が固定する。
+ */
+
+/**
+ * app/ 配下で ffmpeg / ffprobe バイナリ設定キーを参照するファイル (app/ 相対パス)。
+ *
+ * @return list<string>
+ */
+function ffmpegBinaryReferencingFiles(): array
+{
+    $files = [];
+    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
+        $contents = $file->getContents();
+        if (str_contains($contents, 'manual.render_ffmpeg_binary')
+            || str_contains($contents, 'manual.render_ffprobe_binary')) {
+            $files[] = str_replace(base_path('app').'/', '', $file->getPathname());
+        }
+    }
+    sort($files);
+
+    return $files;
+}
+
+test('ffmpeg / ffprobe を起動するファイルの母集団が pin されている', function (): void {
+    expect(ffmpegBinaryReferencingFiles())->toBe([
+        // 開発用の通し確認コマンド (合成素材を自分で作って自分で probe する)。
+        // 入力は利用者由来ではないが、経路ごとに例外を作らず同じ安全境界を通す
+        'Console/Commands/Development/PipelineSmokeCommand.php',
+        'Services/Capture/FfmpegTakeThumbnailExtractor.php',
+        'Services/Render/FfmpegVideoComposer.php',
+    ]);
+});
+
+test('母集団の全ファイルが FfmpegSafetyArguments を経由している', function (): void {
+    $missing = [];
+    foreach (ffmpegBinaryReferencingFiles() as $relative) {
+        $contents = (string) file_get_contents(base_path("app/{$relative}"));
+        if (! str_contains($contents, 'use App\Support\Media\FfmpegSafetyArguments;')
+            || ! str_contains($contents, 'FfmpegSafetyArguments::all()')) {
+            $missing[] = $relative;
+        }
+    }
+
+    expect($missing)->toBe([]);
+});
+
+test('安全境界引数はバイナリ直後に置く 2 要素である (-max_alloc + config 値)', function (): void {
+    expect(FfmpegSafetyArguments::all())->toBe([
+        '-max_alloc',
+        (string) config()->integer('manual.ffmpeg_max_alloc_bytes'),
+    ]);
+});
+
+test('母集団が空でない (degenerate PASS 防止)', function (): void {
+    // 上の「全ファイルが経由している」検査が、Finder が 1 件も返さないことで
+    // 緑になっていないことを示す。
+    expect(ffmpegBinaryReferencingFiles())->not->toBe([]);
+});
diff --git a/tests/Architecture/ManualEnumTsSyncInvariantTest.php b/tests/Architecture/ManualEnumTsSyncInvariantTest.php
index 6d79bd6..440ff2b 100644
--- a/tests/Architecture/ManualEnumTsSyncInvariantTest.php
+++ b/tests/Architecture/ManualEnumTsSyncInvariantTest.php
@@ -3,6 +3,7 @@
 declare(strict_types=1);
 
 use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\RenderConflictType;
 use App\Enums\Manual\RenderErrorCode;
 use App\Enums\Manual\RenderKind;
@@ -53,3 +54,7 @@ function extractTsUnionValues(string $typeName): array
     expect(fn (): array => extractTsUnionValues('NoSuchUnionName'))
         ->toThrow(RuntimeException::class, 'degenerate PASS');
 });
+
+test('CutMaterialType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
+    expect(extractTsUnionValues('CutMaterialType'))->toBe(TsUnionValues::enumStringValues(MaterialType::cases()));
+});
diff --git a/tests/Feature/Capture/CaptureManualBrowsingTest.php b/tests/Feature/Capture/CaptureManualBrowsingTest.php
index 9687c2a..a2680bd 100644
--- a/tests/Feature/Capture/CaptureManualBrowsingTest.php
+++ b/tests/Feature/Capture/CaptureManualBrowsingTest.php
@@ -183,15 +183,15 @@ function browsingContext(): array
 
     $take = $response->inertiaPage()['props']['manual']['cuts'][0]['takes'][0];
     expect(array_keys($take))->toBe([
-        'id', 'client_take_id', 'status', 'size_bytes', 'duration_ms', 'comment',
+        'id', 'client_take_id', 'status', 'material_type', 'size_bytes', 'duration_ms', 'comment',
         'captured_at', 'sort_order', 'downloaded', 'has_thumbnail', 'playback_url',
         'download_ack_token',
     ]);
     $cutShape = $response->inertiaPage()['props']['manual']['cuts'][0];
     expect(array_keys($cutShape))->toBe([
         'id', 'type', 'parent_cut_id', 'scene', 'shot_type', 'shooting_point',
-        'narration', 'subtitle_primary', 'subtitle_secondary', 'adopted_take_id',
-        'adopted_ready_take_id', 'takes',
+        'narration', 'subtitle_primary', 'subtitle_secondary', 'material_type',
+        'adopted_take_id', 'adopted_ready_take_id', 'takes',
     ]);
 });
 
diff --git a/tests/Feature/Capture/TakeRegistrationTest.php b/tests/Feature/Capture/TakeRegistrationTest.php
index 92c8870..5346e53 100644
--- a/tests/Feature/Capture/TakeRegistrationTest.php
+++ b/tests/Feature/Capture/TakeRegistrationTest.php
@@ -5,6 +5,7 @@
 use App\DataTransferObjects\Capture\ObjectMetadataData;
 use App\DataTransferObjects\Capture\UploadTicketClaims;
 use App\Enums\Capture\TakeUploadReservationStatus;
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\TakeStatus;
 use App\Enums\ProjectRole;
 use App\Jobs\Capture\GenerateTakeThumbnailJob;
@@ -495,3 +496,60 @@ function takesPayload(TakeUploadReservation $reservation, string $ticket, array
 
     Queue::assertNotPushed(GenerateTakeThumbnailJob::class);
 });
+
+/*
+ * 静止画テイクの登録: 素材種別は**予約行の content_type** から導く
+ * (チケット偽装で差し替えられない)。静止画に尺は無いので申告があっても捨てる。
+ */
+
+test('画像で登録すると material_type=still になり duration_ms は申告があっても null', function (): void {
+    [$organization, $owner, $project, $manual, $cut] = registrationContext();
+    $cut->forceFill(['material_type' => MaterialType::Still->value])->save();
+    [$reservation, $ticket] = reservationWithTicket($cut, [
+        'content_type' => 'image/jpeg',
+        'video_path' => "projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/".Str::ulid().'.jpg',
+    ]);
+    mockHeadObjectMatching($reservation);
+
+    $this->actingAs($owner)
+        ->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket, ['duration_ms' => 5_000]))
+        ->assertCreated()
+        ->assertJsonPath('duration_ms', null);
+
+    $take = $cut->takes()->sole();
+    expect($take->material_type)->toBe(MaterialType::Still);
+    expect($take->duration_ms)->toBeNull();
+    // Quota の 1 巡は静止画でも同じ経路 (pending 解放 → used 加算)
+    expect($reservation->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed);
+    expect(app(StorageUsageService::class)->bytesPending($organization))->toBe(0);
+    expect(app(StorageUsageService::class)->occupiedBytes($organization))->toBe($reservation->size_bytes);
+});
+
+test('動画で登録すると material_type=video のまま (回帰)', function (): void {
+    [, $owner, $project, $manual, $cut] = registrationContext();
+    [$reservation, $ticket] = reservationWithTicket($cut);
+    mockHeadObjectMatching($reservation);
+
+    $this->actingAs($owner)
+        ->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket))
+        ->assertCreated()
+        ->assertJsonPath('duration_ms', 5_000);
+
+    expect($cut->takes()->sole()->material_type)->toBe(MaterialType::Video);
+});
+
+test('material_type を payload に入れると 422 (サーバ確定値なので受け取らない)', function (): void {
+    [, $owner, $project, $manual, $cut] = registrationContext();
+    [$reservation, $ticket] = reservationWithTicket($cut);
+    mockHeadObjectMatching($reservation);
+
+    $this->actingAs($owner)
+        ->postJson(
+            takesPath($project, $manual, $cut),
+            takesPayload($reservation, $ticket, ['material_type' => 'video']),
+        )
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('material_type');
+
+    expect($cut->takes()->count())->toBe(0);
+});
diff --git a/tests/Feature/Capture/TakeThumbnailGenerationTest.php b/tests/Feature/Capture/TakeThumbnailGenerationTest.php
index 35cf4f0..9ca30b1 100644
--- a/tests/Feature/Capture/TakeThumbnailGenerationTest.php
+++ b/tests/Feature/Capture/TakeThumbnailGenerationTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\TakeStatus;
 use App\Enums\Security\ExternalCallKind;
 use App\Exceptions\Capture\TakeThumbnailExtractionException;
@@ -45,9 +46,13 @@ final class ThumbnailPipelineFakeExtractor implements TakeThumbnailExtractor
     /** @var list<string> 実行ごとの作業ディレクトリ */
     public array $workDirs = [];
 
-    public function extract(string $localVideoPath, string $localThumbnailPath): void
+    /** @var list<MaterialType> extract が受け取った素材種別 */
+    public array $materials = [];
+
+    public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void
     {
         $this->calls++;
+        $this->materials[] = $material;
         $this->workDirs[] = dirname($localThumbnailPath);
         if ($this->duringExtract !== null) {
             ($this->duringExtract)();
@@ -271,3 +276,30 @@ function expectedThumbnailKey(Take $take, Cut $cut, VideoManual $manual): string
 
     expect($take->fresh()?->thumbnail_path)->toBe(expectedThumbnailKey($take, $cut, $manual));
 });
+
+test('素材種別が extractor へ渡る (動画テイク)', function (): void {
+    [$take, , , $extractor] = thumbnailPipelineContext();
+
+    app(TakeThumbnailPipeline::class)->run($take->id);
+
+    expect($extractor->materials)->toBe([MaterialType::Video]);
+});
+
+test('静止画テイクもサムネイルが生成され、Still として extractor へ渡る', function (): void {
+    // 一覧に原本 (フル解像度の画像) を貼らないため、静止画も生成対象に含める。
+    Storage::fake('s3');
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->still()->create();
+    Storage::disk('s3')->put($take->video_path, 'fake-take-image');
+    $extractor = new ThumbnailPipelineFakeExtractor;
+    app()->instance(TakeThumbnailExtractor::class, $extractor);
+    app()->instance(TakeObjectStorage::class, new ThumbnailPipelineRecordingStorage);
+
+    app(TakeThumbnailPipeline::class)->run($take->id);
+
+    expect($extractor->materials)->toBe([MaterialType::Still]);
+    expect($take->refresh()->thumbnail_path)->toBe(expectedThumbnailKey($take, $cut, $manual));
+});
diff --git a/tests/Feature/Capture/TakeUploadUrlTest.php b/tests/Feature/Capture/TakeUploadUrlTest.php
index 1fd6b62..9a70765 100644
--- a/tests/Feature/Capture/TakeUploadUrlTest.php
+++ b/tests/Feature/Capture/TakeUploadUrlTest.php
@@ -4,6 +4,7 @@
 
 use App\DataTransferObjects\Capture\PresignedUploadData;
 use App\Enums\Capture\TakeUploadReservationStatus;
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\VideoManualStatus;
 use App\Enums\ProjectRole;
 use App\Models\Cut;
@@ -279,3 +280,92 @@ function uploadUrlPath(Project $project, VideoManual $manual, Cut $cut): string
     // enum → backing value の往復が効いていること (DB 実値も pending)
     expect(DB::table('take_upload_reservations')->where('id', $captured->id)->value('status'))->toBe('pending');
 });
+
+/*
+ * 静止画カットの受け入れ (受け入れは非対称):
+ * - still カット: 画像も動画も受ける (動画は先頭フレーム抽出で従来どおり合成できる)
+ * - video / 未指定カット: 動画のみ。画像は入口 (presign) で 422 = 容量を消費させない
+ */
+
+test('still カット + image/jpeg は 200 で、S3 キーが .jpg になる', function (): void {
+    [, $owner, $project, $manual, $cut] = uploadUrlContext();
+    $cut->forceFill(['material_type' => MaterialType::Still->value])->save();
+    mockPresign();
+
+    $this->actingAs($owner)
+        ->postJson(uploadUrlPath($project, $manual, $cut), uploadUrlPayload(['content_type' => 'image/jpeg']))
+        ->assertOk();
+
+    $reservation = $cut->uploadReservations()->sole();
+    expect($reservation->content_type)->toBe('image/jpeg');
+    expect($reservation->video_path)->toEndWith('.jpg');
+});
+
+test('video カットへ画像を上げようとすると 422 で、予約行が 1 件も作られない (容量を消費しない)', function (): void {
+    [, $owner, $project, $manual, $cut] = uploadUrlContext();
+    $cut->forceFill(['material_type' => MaterialType::Video->value])->save();
+    mockPresign();
+
+    $this->actingAs($owner)
+        ->postJson(uploadUrlPath($project, $manual, $cut), uploadUrlPayload(['content_type' => 'image/jpeg']))
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('content_type');
+
+    expect($cut->uploadReservations()->count())->toBe(0);
+});
+
+test('material_type 未指定カットも画像は 422 (計画が無いカットは動画のみ)', function (): void {
+    [, $owner, $project, $manual, $cut] = uploadUrlContext();
+    expect($cut->material_type)->toBeNull();
+    mockPresign();
+
+    $this->actingAs($owner)
+        ->postJson(uploadUrlPath($project, $manual, $cut), uploadUrlPayload(['content_type' => 'image/jpeg']))
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('content_type');
+});
+
+test('material_type を payload に入れると 422 (サーバ確定値なので受け取らない)', function (): void {
+    [, $owner, $project, $manual, $cut] = uploadUrlContext();
+    mockPresign();
+
+    $this->actingAs($owner)
+        ->postJson(uploadUrlPath($project, $manual, $cut), uploadUrlPayload(['material_type' => 'still']))
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('material_type');
+});
+
+test('バイト上限は種別で非対称: 同じサイズでも image は 422 / video は通る', function (): void {
+    [, $owner, $project, $manual, $cut] = uploadUrlContext();
+    $cut->forceFill(['material_type' => MaterialType::Still->value])->save();
+    mockPresign();
+    $overStill = config()->integer('capture.max_still_bytes') + 1;
+    expect($overStill)->toBeLessThanOrEqual(config()->integer('capture.max_take_bytes'));
+
+    $this->actingAs($owner)
+        ->postJson(uploadUrlPath($project, $manual, $cut), uploadUrlPayload([
+            'content_type' => 'image/jpeg',
+            'size_bytes' => $overStill,
+        ]))
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('size_bytes');
+
+    // 同じサイズを動画として送ると通る (静止画にだけ厳しい上限が効いている)
+    $this->actingAs($owner)
+        ->postJson(uploadUrlPath($project, $manual, $cut), uploadUrlPayload([
+            'content_type' => 'video/mp4',
+            'size_bytes' => $overStill,
+        ]))
+        ->assertOk();
+});
+
+test('allowlist 外の画像形式 (image/webp) は 422', function (): void {
+    [, $owner, $project, $manual, $cut] = uploadUrlContext();
+    $cut->forceFill(['material_type' => MaterialType::Still->value])->save();
+    mockPresign();
+
+    $this->actingAs($owner)
+        ->postJson(uploadUrlPath($project, $manual, $cut), uploadUrlPayload(['content_type' => 'image/webp']))
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('content_type');
+});
diff --git a/tests/Feature/Manual/RenderPipelineTest.php b/tests/Feature/Manual/RenderPipelineTest.php
index 00c5d1c..e17b72a 100644
--- a/tests/Feature/Manual/RenderPipelineTest.php
+++ b/tests/Feature/Manual/RenderPipelineTest.php
@@ -188,7 +188,11 @@ function renderTriggeredJob(?RenderJob $job): RenderJob
 });
 
 test('Still カット (material_type=still) は TakeStill としてマニフェストへ載る (秒指定 + 未指定 fallback)', function (): void {
+    // 未指定 still の尺は StillDisplayDuration が唯一の所在で、
+    // manual.preview_placeholder_seconds (プレースホルダ尺) の流用は撤去済みである。
+    // 別値を入れて「流用が復活していない」ことも同時に固定する。
     config()->set('manual.preview_placeholder_seconds', 3);
+    config()->set('manual.default_still_display_seconds', 5);
     [, , $project, $manual, $cut, , $fake] = renderPipelineContext(tickets: 0, trigger: false);
     // 1 本目: 秒指定あり
     $cut->forceFill([
@@ -214,7 +218,7 @@ function renderTriggeredJob(?RenderJob $job): RenderJob
     expect($still?->stillDisplaySeconds)->toBe(4);
     $fallback = $clips->firstWhere('cutId', $fallbackCut->id);
     expect($fallback?->source)->toBe(RenderClipSource::TakeStill);
-    expect($fallback?->stillDisplaySeconds)->toBe(3); // config fallback
+    expect($fallback?->stillDisplaySeconds)->toBe(5); // manual.default_still_display_seconds
     // Still でも採用テイク素材 (先頭フレーム抽出元) はローカル供給される
     expect($fake->lastSources)->toHaveKey($cut->id);
     expect($fake->lastSources)->toHaveKey($fallbackCut->id);
@@ -576,3 +580,16 @@ function renderTriggeredJob(?RenderJob $job): RenderJob
     expect(RenderJob::query()->whereKey($job->id)->exists())->toBeFalse();
     expect(Storage::disk('s3')->allFiles())->toBe([Take::query()->firstOrFail()->video_path]);
 });
+
+test('素材のローカル名は拡張子なし (src{index}) で落ちる', function (): void {
+    // 拡張子は以前から既に嘘だった (webm / mov も .mp4 という名前で落ちていた)。
+    // 画像素材を足すにあたって嘘を増やさないため、名前から拡張子ごと外している。
+    // 合成が ffmpeg の内容プローブに依存している事実を名前でも表す。
+    [, , $project, $manual, $cut, , $fake] = renderPipelineContext(tickets: 0, trigger: false);
+    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
+
+    app(RenderPipeline::class)->run($previewJob->id);
+
+    expect($fake->lastSources[$cut->id] ?? null)->not->toBeNull();
+    expect(basename((string) $fake->lastSources[$cut->id]))->toBe('src0');
+});
diff --git a/tests/Feature/Manual/ScenarioVideoColumnTest.php b/tests/Feature/Manual/ScenarioVideoColumnTest.php
index 0f52912..a748cd5 100644
--- a/tests/Feature/Manual/ScenarioVideoColumnTest.php
+++ b/tests/Feature/Manual/ScenarioVideoColumnTest.php
@@ -151,3 +151,40 @@
     // 本数の完全一致では固定しない (無関係な最適化で赤くしない)。増えないことだけを見る
     expect($large)->toBeLessThanOrEqual($small);
 });
+
+/*
+ * 素材登録状況 (未登録 / 動画登録済 / 静止画登録済) の材料。
+ * 判定に使うのは「採用テイクが在るか」と「その material_type」の 2 つだけで、
+ * ready かどうか (使えるか) は別軸である。
+ */
+
+test('未採用のカットは adopted が null (未登録)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    Take::factory()->forCut($cut)->create();
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
+        ->assertInertia(fn ($page) => $page->where('takeSummaries.0.adopted', null));
+});
+
+test('採用テイクの material_type が props に載る (動画 / 静止画)', function (string $state, string $expected): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = $state === 'still'
+        ? Take::factory()->forCut($cut)->still()->create()
+        : Take::factory()->forCut($cut)->create();
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
+        ->assertInertia(fn ($page) => $page
+            ->where('takeSummaries.0.adopted.material_type', $expected));
+})->with([
+    ['video', 'video'],
+    ['still', 'still'],
+]);
diff --git a/tests/Feature/Manual/StillMaterialConsistencyTest.php b/tests/Feature/Manual/StillMaterialConsistencyTest.php
new file mode 100644
index 0000000..18f2c0a
--- /dev/null
+++ b/tests/Feature/Manual/StillMaterialConsistencyTest.php
@@ -0,0 +1,175 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
+use App\DataTransferObjects\Manual\Render\RenderClipSource;
+use App\DataTransferObjects\Manual\Render\RenderManifest;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\MaterialType;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Manual\RenderJobService;
+use App\Services\Manual\RenderPipeline;
+use App\Services\Render\VideoComposer;
+use Illuminate\Process\PendingProcess;
+use Illuminate\Support\Facades\Process;
+use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Facades\Storage;
+use Illuminate\Validation\ValidationException;
+
+/*
+ * 静止画素材の通し (詳細設計 S8 の組み合わせ表)。
+ *
+ * C1 (still/still) / C2 (still/video) / C3 (video/video) は各所の既存テストが持つため、
+ * ここでは**この施策が新しく作った経路**だけを固定する:
+ * - C5: 採用後に cut.material_type を video へ戻しても、実体が画像なら
+ *       (a) マニフェストは TakeStill (b) 尺ゲートも静止画の尺で数える
+ * - 誤申告 (video と申告して画像を置く) は ffprobe が尺を取れず**失敗ジョブ**になる。
+ *   壊れた成果物を出さず、後続ジョブは処理できる
+ *
+ * 誤申告の帰結は**向きによって非対称**である。「still と申告して動画を置いた」場合は
+ * 先頭フレーム抽出で成功しうる (C2 と同じ経路で害が無い) ため、題材にしない。
+ */
+
+/** 実 ffmpeg に触れない composer (container swap で注入する。本ファイル専用) */
+final class StillConsistencyComposer implements VideoComposer
+{
+    public ?RenderManifest $lastManifest = null;
+
+    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
+    {
+        $this->lastManifest = $manifest;
+        $durations = [];
+        foreach ($manifest->clips as $index => $clip) {
+            $durations[$clip->cutId] = 1_000 * ($index + 1);
+            $onClipComposed($index + 1, count($manifest->clips));
+        }
+        $localPath = "{$workDir}/output.mp4";
+        file_put_contents($localPath, 'fake-mp4');
+
+        return new ComposedLocalVideo($localPath, $durations, (int) array_sum($durations));
+    }
+}
+
+/**
+ * cut の計画と take の実体を任意に組める文脈 (ticket 付与済み)。
+ *
+ * @return array{Organization, User, Project, VideoManual, Cut, Take}
+ */
+function stillConsistencyContext(?MaterialType $planned, MaterialType $actual): array
+{
+    Queue::fake();
+    Storage::fake('s3');
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 2,
+    ]);
+    $cut = Cut::factory()->forManual($manual)->create([
+        'material_type' => $planned?->value,
+        'static_display_seconds' => null,
+    ]);
+    $take = $actual === MaterialType::Still
+        ? Take::factory()->forCut($cut)->still()->create()
+        : Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+    Storage::disk('s3')->put($take->video_path, 'fake-take-bytes');
+    app(TicketLedgerService::class)->grant($organization, 5, 'テスト残高');
+
+    return [$organization, $owner, $project, $manual, $cut, $take];
+}
+
+test('C5: cut=video / take=still でもマニフェストは TakeStill になり、尺は既定の静止画尺になる', function (): void {
+    config()->set('manual.default_still_display_seconds', 5);
+    [, , $project, $manual, $cut] = stillConsistencyContext(MaterialType::Video, MaterialType::Still);
+    $fake = new StillConsistencyComposer;
+    app()->instance(VideoComposer::class, $fake);
+    $job = app(RenderJobService::class)->trigger($project, $manual);
+
+    app(RenderPipeline::class)->run($job->id);
+
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+    $clip = collect($fake->lastManifest?->clips ?? [])->firstWhere('cutId', $cut->id);
+    expect($clip?->source)->toBe(RenderClipSource::TakeStill);
+    expect($clip?->stillDisplaySeconds)->toBe(5);
+});
+
+test('C5: 尺ゲートも静止画の尺で数える (duration_ms 欠落の既定 60 秒に落ちない)', function (): void {
+    // 上限を 10 秒に絞る。旧実装は cut.material_type が video なので
+    // `duration_ms ?? render_default_take_duration_ms` = 60 秒として数え、ここで 422 になっていた。
+    // 実効判定を通す今は 5 秒として数えるためトリガーできる = レンダの実尺と一致する。
+    config()->set('manual.default_still_display_seconds', 5);
+    config()->set('manual.render_max_total_source_ms', 10_000);
+    config()->set('manual.render_default_take_duration_ms', 60_000);
+    [, , $project, $manual, $cut, $take] = stillConsistencyContext(MaterialType::Video, MaterialType::Still);
+    expect($take->duration_ms)->toBeNull();
+    expect($cut->material_type)->toBe(MaterialType::Video);
+    app()->instance(VideoComposer::class, new StillConsistencyComposer);
+
+    $job = app(RenderJobService::class)->trigger($project, $manual);
+
+    expect($job->status)->toBe(JobStatus::Queued);
+});
+
+test('尺ゲートの回帰: 動画テイクは従来どおり duration_ms で数える', function (): void {
+    config()->set('manual.render_max_total_source_ms', 4_000);
+    [, , $project, $manual] = stillConsistencyContext(MaterialType::Video, MaterialType::Video);
+    app()->instance(VideoComposer::class, new StillConsistencyComposer);
+
+    expect(fn () => app(RenderJobService::class)->trigger($project, $manual))
+        ->toThrow(ValidationException::class, '合計尺が上限を超えています');
+});
+
+test('video と申告して画像を置いたテイクは失敗ジョブになり、壊れた成果物を残さない', function (): void {
+    // material_type=video のまま実体が画像 → planTakeVideo → probeDurationMs の ffprobe が
+    // format=duration を数値で返せない。実バイナリには依存せず Process::fake で再現する。
+    [, , $project, $manual] = stillConsistencyContext(MaterialType::Video, MaterialType::Video);
+    Process::fake(function (PendingProcess $process) {
+        $command = $process->command;
+        $line = is_array($command) ? implode(' ', array_map(strval(...), $command)) : (string) $command;
+        if (str_contains($line, '-show_entries')) {
+            return Process::result(output: "N/A\n"); // 画像には尺が無い
+        }
+
+        return Process::result(output: '');
+    });
+    $job = app(RenderJobService::class)->trigger($project, $manual);
+
+    app(RenderPipeline::class)->run($job->id);
+
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    expect($job->output_path)->toBeNull();
+    // compose 失敗地点では upload() 自体が未実行 = 出力オブジェクトはそもそも生まれない
+    // (孤児削除は finalize 失敗の別契約であり、ここでは期待しない)
+    expect(Storage::disk('s3')->allFiles())->not->toContain(
+        "projects/{$manual->project_id}/manuals/{$manual->id}/renders/v2-{$job->id}.mp4",
+    );
+    // rendering に取り残さない (編集をブロックし続けない)
+    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
+});
+
+test('失敗ジョブの後でも別のレンダジョブは正常に完了できる', function (): void {
+    [, , $project, $manual] = stillConsistencyContext(MaterialType::Video, MaterialType::Video);
+    Process::fake(fn (PendingProcess $process) => Process::result(output: "N/A\n"));
+    $failing = app(RenderJobService::class)->trigger($project, $manual);
+    app(RenderPipeline::class)->run($failing->id);
+    expect($failing->refresh()->status)->toBe(JobStatus::Failed);
+
+    // 2 本目は正常な composer で走らせる (キューが詰まらないことの確認)
+    app()->instance(VideoComposer::class, new StillConsistencyComposer);
+    $second = app(RenderJobService::class)->trigger($project, $manual->refresh());
+
+    app(RenderPipeline::class)->run($second->id);
+
+    expect($second->refresh()->status)->toBe(JobStatus::Succeeded);
+    expect($second->output_path)->not->toBeNull();
+});
diff --git a/tests/Feature/Manual/TakeSelectionPageTest.php b/tests/Feature/Manual/TakeSelectionPageTest.php
index 2e2b355..a0126c3 100644
--- a/tests/Feature/Manual/TakeSelectionPageTest.php
+++ b/tests/Feature/Manual/TakeSelectionPageTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\TakeStatus;
 use App\Enums\ProjectRole;
 use App\Models\Cut;
@@ -173,3 +174,30 @@ function takeSelectionPath(Project $project, VideoManual $manual, Cut $cut): str
         ->get(takeSelectionPath($project, $manual, $cut))
         ->assertRedirect(route('onboarding.checkout'));
 });
+
+test('cut の計画 (material_type) と採用テイクの実体が props に載る', function (): void {
+    // cut 側は**計画** (未指定あり。ファイル選択の accept 切替に使う) /
+    // take 側は**実体** (NOT NULL。<video> と <img> の出し分けに使う)。別のキーである。
+    [, $owner, $project, $manual, $cut] = takeSelectionContext();
+    $cut->forceFill(['material_type' => MaterialType::Still->value])->save();
+    $take = Take::factory()->forCut($cut)->still()->create();
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+
+    $this->actingAs($owner)
+        ->get(takeSelectionPath($project, $manual, $cut))
+        ->assertInertia(fn ($page) => $page
+            ->where('cut.material_type', 'still')
+            ->where('cut.adopted.material_type', 'still')
+            ->where('takes.0.material_type', 'still'));
+});
+
+test('計画未指定 + 動画テイクでは cut.material_type が null / take は video', function (): void {
+    [, $owner, $project, $manual, $cut] = takeSelectionContext();
+    Take::factory()->forCut($cut)->create();
+
+    $this->actingAs($owner)
+        ->get(takeSelectionPath($project, $manual, $cut))
+        ->assertInertia(fn ($page) => $page
+            ->where('cut.material_type', null)
+            ->where('takes.0.material_type', 'video'));
+});
diff --git a/tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php b/tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php
index d31d0aa..3d89af1 100644
--- a/tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php
+++ b/tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\Enums\Manual\MaterialType;
 use App\Exceptions\Capture\TakeThumbnailExtractionException;
 use App\Services\Capture\FfmpegTakeThumbnailExtractor;
 use Illuminate\Process\PendingProcess;
@@ -14,6 +15,8 @@
  * - 出力寸法・品質が config 固定
  * - 尺不足で 0 バイトなら seek=0 で 1 回だけ再試行する
  * - 実行前に出力先を消す (1 回目の残骸を成功と誤認しない)
+ * - 安全境界の -max_alloc が**バイナリ直後**に付く (画素数爆弾で worker を落とさない)
+ * - 静止画は seek=0 の 1 回だけ (「1 秒地点」が存在しない)
  *
  * ★ 実バイナリの挙動差 (`-frames:v 1` + `-f image2` の出力有無) は本テストでは検出しない。
  *   実バイナリでの通し確認は bug-hunt の pipeline-smoke (別基盤) の領域である。
@@ -59,7 +62,7 @@ function fakeThumbnailFfmpeg(array &$recorded, callable $onRun): void
     });
     $workDir = thumbnailWorkDir();
 
-    app(FfmpegTakeThumbnailExtractor::class)->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg");
+    app(FfmpegTakeThumbnailExtractor::class)->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video);
 
     expect($recorded)->toHaveCount(1);
     $line = $recorded[0];
@@ -76,6 +79,55 @@ function fakeThumbnailFfmpeg(array &$recorded, callable $onRun): void
     expect($line)->toContain("{$workDir}/thumbnail.jpg");
 });
 
+test('安全境界の -max_alloc がバイナリ直後 (argv[1], argv[2]) に付く', function (): void {
+    // 配置を「最初の -i より前」ではなくバイナリ直後で固定する。ffprobe は入力を位置引数で
+    // 受けるため、-i を基準にすると同じ検査が別コマンドで空振りする。
+    $commands = [];
+    Process::fake(function (PendingProcess $process) use (&$commands) {
+        $command = $process->command;
+        $commands[] = is_array($command) ? array_map(strval(...), $command) : [(string) $command];
+        file_put_contents(is_array($command) ? (string) $command[count($command) - 1] : '', 'jpeg');
+
+        return Process::result(output: '', errorOutput: '', exitCode: 0);
+    });
+    $workDir = thumbnailWorkDir();
+
+    app(FfmpegTakeThumbnailExtractor::class)
+        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video);
+
+    expect($commands)->toHaveCount(1);
+    expect($commands[0][1])->toBe('-max_alloc');
+    expect($commands[0][2])->toBe((string) config()->integer('manual.ffmpeg_max_alloc_bytes'));
+});
+
+test('静止画は seek=0 の 1 回だけ実行する (再試行しない)', function (): void {
+    $recorded = [];
+    fakeThumbnailFfmpeg($recorded, function (int $attempt, string $destination): int {
+        file_put_contents($destination, 'jpeg');
+
+        return 0;
+    });
+    $workDir = thumbnailWorkDir();
+
+    app(FfmpegTakeThumbnailExtractor::class)
+        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Still);
+
+    expect($recorded)->toHaveCount(1);
+    expect($recorded[0])->toContain('-ss 0.000'); // 動画既定の 1000ms を当てない
+});
+
+test('静止画で失敗したら再試行せずそのまま例外にする', function (): void {
+    $recorded = [];
+    fakeThumbnailFfmpeg($recorded, fn (int $attempt, string $destination): int => 1);
+    $workDir = thumbnailWorkDir();
+
+    expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
+        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Still))
+        ->toThrow(TakeThumbnailExtractionException::class, 'ffmpeg failed (thumbnail): ffmpeg boom');
+
+    expect($recorded)->toHaveCount(1);
+});
+
 test('尺不足で 1 回目が 0 バイトなら seek=0 で 1 回だけ再試行し、成功すれば例外を投げない', function (): void {
     $recorded = [];
     fakeThumbnailFfmpeg($recorded, function (int $attempt, string $destination): int {
@@ -89,7 +141,7 @@ function fakeThumbnailFfmpeg(array &$recorded, callable $onRun): void
     });
     $workDir = thumbnailWorkDir();
 
-    app(FfmpegTakeThumbnailExtractor::class)->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg");
+    app(FfmpegTakeThumbnailExtractor::class)->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video);
 
     expect($recorded)->toHaveCount(2);
     expect($recorded[0])->toContain('-ss 1.000');
@@ -102,7 +154,7 @@ function fakeThumbnailFfmpeg(array &$recorded, callable $onRun): void
     $workDir = thumbnailWorkDir();
 
     expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
-        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg"))
+        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video))
         ->toThrow(TakeThumbnailExtractionException::class, 'ffmpeg failed (thumbnail): ffmpeg boom');
 
     expect($recorded)->toHaveCount(2);
@@ -124,7 +176,7 @@ function fakeThumbnailFfmpeg(array &$recorded, callable $onRun): void
     $workDir = thumbnailWorkDir();
 
     expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
-        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg"))
+        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video))
         ->toThrow(TakeThumbnailExtractionException::class, 'ffmpeg produced no frame (seek=0ms)');
 });
 
@@ -140,7 +192,7 @@ function fakeThumbnailFfmpeg(array &$recorded, callable $onRun): void
     File::shouldReceive('delete')->andReturnFalse();
 
     expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
-        ->extract('/tmp/thumb-source', '/tmp/thumb-out.jpg'))
+        ->extract('/tmp/thumb-source', '/tmp/thumb-out.jpg', MaterialType::Video))
         ->toThrow(TakeThumbnailExtractionException::class, 'failed to remove stale thumbnail output');
 
     // 削除できなかった時点で ffmpeg を 1 回も起動しない
diff --git a/tests/Unit/Capture/TakeMaterialClassifierTest.php b/tests/Unit/Capture/TakeMaterialClassifierTest.php
new file mode 100644
index 0000000..769598a
--- /dev/null
+++ b/tests/Unit/Capture/TakeMaterialClassifierTest.php
@@ -0,0 +1,61 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\MaterialType;
+use App\Models\Take;
+use App\Support\Capture\TakeMaterialClassifier;
+
+/*
+ * 申告 Content-Type → 素材種別の写像 (この写像を書いてよい唯一の場所)。
+ * 許可集合の正本は config/capture.php の 2 キーであり、本クラスは
+ * 「許可集合のどちら側か」だけを答える (許可・不許可の判断はしない)。
+ */
+
+test('許可済みの動画 Content-Type は Video に分類される', function (string $contentType): void {
+    expect(TakeMaterialClassifier::fromContentType($contentType))->toBe(MaterialType::Video);
+})->with(['video/mp4', 'video/webm', 'video/quicktime']);
+
+test('許可済みの静止画 Content-Type は Still に分類される', function (string $contentType): void {
+    expect(TakeMaterialClassifier::fromContentType($contentType))->toBe(MaterialType::Still);
+})->with(['image/jpeg', 'image/png']);
+
+test('未許可の Content-Type は例外 (到達したら整合性異常なので fail-loud)', function (): void {
+    expect(fn (): MaterialType => TakeMaterialClassifier::fromContentType('image/webp'))
+        ->toThrow(InvalidArgumentException::class, '未許可の Content-Type です: image/webp');
+});
+
+test('拡張子は許可集合と 1 対 1 で、未許可は例外', function (): void {
+    expect(TakeMaterialClassifier::extensionFor('video/mp4'))->toBe('mp4');
+    expect(TakeMaterialClassifier::extensionFor('video/webm'))->toBe('webm');
+    expect(TakeMaterialClassifier::extensionFor('video/quicktime'))->toBe('mov');
+    expect(TakeMaterialClassifier::extensionFor('image/jpeg'))->toBe('jpg');
+    expect(TakeMaterialClassifier::extensionFor('image/png'))->toBe('png');
+    expect(fn (): string => TakeMaterialClassifier::extensionFor('image/webp'))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('分類できる Content-Type は必ず拡張子も持つ (2 つの写像の母集団が一致する)', function (): void {
+    $allowed = [
+        ...config()->array('capture.allowed_video_content_types'),
+        ...config()->array('capture.allowed_still_content_types'),
+    ];
+    foreach ($allowed as $contentType) {
+        expect(is_string($contentType))->toBeTrue();
+        /** @var string $contentType */
+        expect(TakeMaterialClassifier::extensionFor($contentType))->not->toBe('');
+    }
+});
+
+test('TakeFactory の既定は Video / still() 状態は Still で尺を持たない', function (): void {
+    $video = Take::factory()->make();
+    expect($video->material_type)->toBe(MaterialType::Video);
+
+    $still = Take::factory()->still()->make();
+    expect($still->material_type)->toBe(MaterialType::Still);
+    expect($still->duration_ms)->toBeNull();
+});
+
+test('material_type は $fillable 外 (サーバ確定値なので payload から入らない)', function (): void {
+    expect((new Take)->getFillable())->not->toContain('material_type');
+});
diff --git a/tests/Unit/Manual/EffectiveMaterialTypeTest.php b/tests/Unit/Manual/EffectiveMaterialTypeTest.php
new file mode 100644
index 0000000..41f9112
--- /dev/null
+++ b/tests/Unit/Manual/EffectiveMaterialTypeTest.php
@@ -0,0 +1,73 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\MaterialType;
+use App\Models\Cut;
+use App\Models\Take;
+use App\Services\Manual\EffectiveMaterialType;
+
+/*
+ * 「このカットを実際にどちらの素材として合成するか」の式 (唯一の所在)。
+ *
+ * 実体優先である: 採用した後に編集者がシナリオ編集で cut.material_type を video へ戻せるため、
+ * 入口 (presign 422) でも採用 API でも「cut=video / take=still」の状態は防げない。
+ * この式は「画像が動画クリップ経路 (ffprobe で尺を測る) に流れる道」を構造的に消す。
+ *
+ * ready 判定は一切しない (AdoptedReadyTakeCoverage の専権。ドメイン固有規約 12)。
+ */
+
+/** DB を使わない (make で組む。式そのものの検査であり永続化は関係しない) */
+function effectiveMaterialCut(?MaterialType $planned): Cut
+{
+    return Cut::factory()->make(['material_type' => $planned?->value]);
+}
+
+function effectiveMaterialTake(MaterialType $actual): Take
+{
+    return $actual === MaterialType::Still
+        ? Take::factory()->still()->make()
+        : Take::factory()->make();
+}
+
+test('cut=still × take=still → Still', function (): void {
+    expect(EffectiveMaterialType::of(
+        effectiveMaterialCut(MaterialType::Still),
+        effectiveMaterialTake(MaterialType::Still),
+    ))->toBe(MaterialType::Still);
+});
+
+test('cut=still × take=video → Still (先頭フレーム抽出。従来挙動)', function (): void {
+    expect(EffectiveMaterialType::of(
+        effectiveMaterialCut(MaterialType::Still),
+        effectiveMaterialTake(MaterialType::Video),
+    ))->toBe(MaterialType::Still);
+});
+
+test('cut=video × take=video → Video (回帰)', function (): void {
+    expect(EffectiveMaterialType::of(
+        effectiveMaterialCut(MaterialType::Video),
+        effectiveMaterialTake(MaterialType::Video),
+    ))->toBe(MaterialType::Video);
+});
+
+test('cut=video × take=still → Still (実体優先。採用後に計画を戻しても壊れない)', function (): void {
+    expect(EffectiveMaterialType::of(
+        effectiveMaterialCut(MaterialType::Video),
+        effectiveMaterialTake(MaterialType::Still),
+    ))->toBe(MaterialType::Still);
+});
+
+test('cut=未指定 × take=video → Video', function (): void {
+    expect(EffectiveMaterialType::of(
+        effectiveMaterialCut(null),
+        effectiveMaterialTake(MaterialType::Video),
+    ))->toBe(MaterialType::Video);
+});
+
+test('cut=未指定 × take=still → Still (実体優先)', function (): void {
+    expect(EffectiveMaterialType::of(
+        effectiveMaterialCut(null),
+        effectiveMaterialTake(MaterialType::Still),
+    ))->toBe(MaterialType::Still);
+});
diff --git a/tests/Unit/Manual/StillDisplayDurationTest.php b/tests/Unit/Manual/StillDisplayDurationTest.php
new file mode 100644
index 0000000..73f3f68
--- /dev/null
+++ b/tests/Unit/Manual/StillDisplayDurationTest.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Cut;
+use App\Services\Manual\StillDisplayDuration;
+
+/*
+ * 静止画カットの表示秒を決める式 (唯一の所在)。
+ *
+ * 以前は RenderPipeline が manual.preview_placeholder_seconds (= 採用テイク欠落 cut の
+ * プレースホルダ尺) を流用していた。別概念なので撤去済みで、ここではその 2 値が
+ * 独立していることも固定する。
+ */
+
+test('cuts.static_display_seconds が指定されていればその値', function (): void {
+    $cut = Cut::factory()->make(['static_display_seconds' => 12]);
+
+    expect(StillDisplayDuration::secondsFor($cut))->toBe(12);
+});
+
+test('未指定なら manual.default_still_display_seconds', function (): void {
+    config()->set('manual.default_still_display_seconds', 7);
+    $cut = Cut::factory()->make(['static_display_seconds' => null]);
+
+    expect(StillDisplayDuration::secondsFor($cut))->toBe(7);
+});
+
+test('preview_placeholder_seconds を変えても静止画尺は変わらない (流用の撤去)', function (): void {
+    config()->set('manual.default_still_display_seconds', 5);
+    config()->set('manual.preview_placeholder_seconds', 41);
+    $cut = Cut::factory()->make(['static_display_seconds' => null]);
+
+    expect(StillDisplayDuration::secondsFor($cut))->toBe(5);
+});
+
+test('既定値は編集画面の入力範囲 (1〜60 秒) の内側にある', function (): void {
+    // 既定値が範囲外だと「編集画面では入力できない尺」が既定になり、
+    // 編集で直そうとしても同じ値へ戻せなくなる。
+    $default = config()->integer('manual.default_still_display_seconds');
+
+    expect($default)->toBeGreaterThanOrEqual(1);
+    expect($default)->toBeLessThanOrEqual(60);
+});
diff --git a/tests/Unit/Render/AssSubtitleWriterTest.php b/tests/Unit/Render/AssSubtitleWriterTest.php
index 75e88c3..d86e0a8 100644
--- a/tests/Unit/Render/AssSubtitleWriterTest.php
+++ b/tests/Unit/Render/AssSubtitleWriterTest.php
@@ -20,7 +20,7 @@ function subtitleClip(?string $primary, string $secondary): RenderClipSpec
         cutId: 1,
         label: '手順1',
         source: RenderClipSource::TakeVideo,
-        takeVideoPath: 'takes/x.mp4',
+        takeSourcePath: 'takes/x.mp4',
         stillDisplaySeconds: null,
         subtitlePrimary: $primary,
         subtitleSecondary: $secondary,
diff --git a/tests/Unit/Render/FfmpegVideoComposerSmokeTest.php b/tests/Unit/Render/FfmpegVideoComposerSmokeTest.php
index 418b7cd..444d1da 100644
--- a/tests/Unit/Render/FfmpegVideoComposerSmokeTest.php
+++ b/tests/Unit/Render/FfmpegVideoComposerSmokeTest.php
@@ -66,7 +66,7 @@ function smokeWorkDir(): string
                 cutId: 1,
                 label: '手順1',
                 source: RenderClipSource::Placeholder, // 素材ダウンロード不要 (黒背景 + 字幕)
-                takeVideoPath: null,
+                takeSourcePath: null,
                 stillDisplaySeconds: null,
                 subtitlePrimary: null,
                 subtitleSecondary: 'これは疎通確認用の日本語字幕です。', // libass + フォント解決を通す
diff --git a/tests/Unit/Render/FfmpegVideoComposerTest.php b/tests/Unit/Render/FfmpegVideoComposerTest.php
index d298fa3..a88392d 100644
--- a/tests/Unit/Render/FfmpegVideoComposerTest.php
+++ b/tests/Unit/Render/FfmpegVideoComposerTest.php
@@ -36,7 +36,7 @@ function takeVideoClip(int $cutId = 1): RenderClipSpec
         cutId: $cutId,
         label: '手順1',
         source: RenderClipSource::TakeVideo,
-        takeVideoPath: 'takes/src.mp4',
+        takeSourcePath: 'takes/src.mp4',
         stillDisplaySeconds: null,
         subtitlePrimary: null,
         subtitleSecondary: ATTACK_SUBTITLE,
@@ -49,7 +49,7 @@ function takeStillClip(int $cutId = 1, int $seconds = 4): RenderClipSpec
         cutId: $cutId,
         label: '手順1',
         source: RenderClipSource::TakeStill,
-        takeVideoPath: 'takes/src-still.mp4',
+        takeSourcePath: 'takes/src-still.mp4',
         stillDisplaySeconds: $seconds,
         subtitlePrimary: null,
         subtitleSecondary: ATTACK_SUBTITLE,
@@ -62,7 +62,7 @@ function placeholderClip(int $cutId = 2): RenderClipSpec
         cutId: $cutId,
         label: '手順2',
         source: RenderClipSource::Placeholder,
-        takeVideoPath: null,
+        takeSourcePath: null,
         stillDisplaySeconds: null,
         subtitlePrimary: null,
         subtitleSecondary: ATTACK_SUBTITLE,
@@ -263,3 +263,41 @@ function (): void {},
         function (): void {},
     ))->toThrow(RenderCompositionException::class, 'non-numeric');
 });
+
+test('全 ffmpeg / ffprobe コマンドの argv[1..2] が -max_alloc + config 値である', function (): void {
+    // 画素数爆弾 (小さいファイルで巨大な画素数を宣言する画像) で worker を落とさないための
+    // 安全境界。**バイナリ直後**に置くのは、ffprobe が入力を位置引数で受けるため
+    // 「最初の -i より前」を基準にすると検査が空振りするからである。
+    // 母集団は静止画抽出 / 静止画ループ / 動画クリップ / プレースホルダ / concat の 5 本 +
+    // ffprobe (尺 / 音声トラック) である。
+    $commands = [];
+    Process::fake(function (PendingProcess $process) use (&$commands) {
+        $command = $process->command;
+        $parts = is_array($command) ? array_map(strval(...), $command) : [(string) $command];
+        $commands[] = $parts;
+        $line = implode(' ', $parts);
+        if (str_contains($line, '-select_streams')) {
+            return Process::result(output: '');
+        }
+        if (str_contains($line, '-show_entries')) {
+            return Process::result(output: "2.500000\n");
+        }
+
+        return Process::result(output: '');
+    });
+    $workDir = composerWorkDir();
+
+    app(FfmpegVideoComposer::class)->compose(
+        composerManifest(takeVideoClip(1), takeStillClip(2), placeholderClip(3)),
+        [1 => "{$workDir}/src0", 2 => "{$workDir}/src1"],
+        $workDir,
+        function (): void {},
+    );
+
+    $expected = (string) config()->integer('manual.ffmpeg_max_alloc_bytes');
+    expect(count($commands))->toBeGreaterThanOrEqual(7);
+    foreach ($commands as $parts) {
+        expect($parts[1] ?? null)->toBe('-max_alloc');
+        expect($parts[2] ?? null)->toBe($expected);
+    }
+});
diff --git a/tests/Unit/Render/RenderManifestTest.php b/tests/Unit/Render/RenderManifestTest.php
index 81f53e1..3c0eed2 100644
--- a/tests/Unit/Render/RenderManifestTest.php
+++ b/tests/Unit/Render/RenderManifestTest.php
@@ -22,7 +22,7 @@ function renderManifestWithSources(array $sources): RenderManifest
             cutId: $index + 1,
             label: '手順'.($index + 1),
             source: $source,
-            takeVideoPath: $source === RenderClipSource::Placeholder ? null : 'takes/x.mp4',
+            takeSourcePath: $source === RenderClipSource::Placeholder ? null : 'takes/x.mp4',
             stillDisplaySeconds: null,
             subtitlePrimary: null,
             subtitleSecondary: 'テロップ',
diff --git a/tests/js/components/features/capture/CameraRecorder.test.ts b/tests/js/components/features/capture/CameraRecorder.test.ts
index 7e2c8d8..ea13173 100644
--- a/tests/js/components/features/capture/CameraRecorder.test.ts
+++ b/tests/js/components/features/capture/CameraRecorder.test.ts
@@ -1043,4 +1043,134 @@ describe("CameraRecorder 全画面レイアウトと撮影ガイド", () => {
 
         expect(previewContainer().contains(screen.getByTestId("start-recording"))).toBe(false);
     });
+    /*
+     * 静止画モード (mode="still")。MediaRecorder を一切使わず、phase は idle のまま
+     * 「録画開始」の位置にシャッターを出す。上の録画モードのテストが 1 件も変わっていないことが
+     * 「phase マシン・stream 管理に触れていない」ことの証拠になる。
+     */
+    describe("mode=still", () => {
+        /**
+         * jsdom の <video> は videoWidth/videoHeight を持たない (常に 0) ため、
+         * 「preview が映っている」状態を作る。0 のままだと encodeStillJpeg が
+         * 仕様どおり null を返し、撮影成功の経路に入らない。
+         */
+        function stubVideoDimensions(width: number, height: number): void {
+            vi.spyOn(HTMLVideoElement.prototype, "videoWidth", "get").mockReturnValue(width);
+            vi.spyOn(HTMLVideoElement.prototype, "videoHeight", "get").mockReturnValue(height);
+        }
+
+        /** canvas を差し替える (jsdom は toBlob / 2d context を持たない) */
+        function stubStillCanvas(blob: Blob | null): void {
+            vi.spyOn(document, "createElement").mockImplementation((tag: string) => {
+                if (tag !== "canvas") {
+                    return document.createElementNS(
+                        "http://www.w3.org/1999/xhtml",
+                        tag,
+                    ) as HTMLElement;
+                }
+                return {
+                    width: 0,
+                    height: 0,
+                    getContext: () => ({ drawImage: vi.fn() }),
+                    toBlob: (callback: (value: Blob | null) => void) => callback(blob),
+                } as unknown as HTMLElement;
+            });
+        }
+
+        it("シャッターを出し、録画開始ボタンは出さない", () => {
+            render(CameraRecorder, {
+                props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), mode: "still" },
+            });
+
+            expect(screen.getByTestId("shoot-still")).toBeInTheDocument();
+            expect(screen.queryByTestId("start-recording")).not.toBeInTheDocument();
+        });
+
+        it("押下で onCaptured(blob, 'image/jpeg', null) を呼び、phase は idle のまま", async () => {
+            const { stream } = fakeStream();
+            getUserMediaMock.mockResolvedValue(stream);
+            stubVideoDimensions(1280, 720);
+            stubStillCanvas(new Blob(["jpeg"], { type: "image/jpeg" }));
+            const onCaptured = vi.fn();
+
+            render(CameraRecorder, {
+                props: { onCaptured, onCameraUnavailable: vi.fn(), mode: "still" },
+            });
+            await fireEvent.click(screen.getByTestId("shoot-still"));
+
+            await vi.waitFor(() => {
+                expect(onCaptured).toHaveBeenCalledTimes(1);
+            });
+            const [blob, mimeType, durationMs] = onCaptured.mock.calls[0];
+            expect(blob).toBeInstanceOf(Blob);
+            expect(mimeType).toBe("image/jpeg");
+            expect(durationMs).toBeNull();
+            // 録画に入っていない = 停止ボタンは出ず、シャッターのまま
+            expect(screen.getByTestId("shoot-still")).toBeInTheDocument();
+            expect(screen.queryByTestId("stop-recording")).not.toBeInTheDocument();
+        });
+
+        it("撮影中は active を通知し、完了で false へ戻す (preview 排他は録画と同じ)", async () => {
+            const { stream } = fakeStream();
+            getUserMediaMock.mockResolvedValue(stream);
+            stubVideoDimensions(1280, 720);
+            stubStillCanvas(new Blob(["jpeg"], { type: "image/jpeg" }));
+            const onCaptureActiveChange = vi.fn();
+
+            render(CameraRecorder, {
+                props: {
+                    onCaptured: vi.fn(),
+                    onCameraUnavailable: vi.fn(),
+                    onCaptureActiveChange,
+                    mode: "still",
+                },
+            });
+            await fireEvent.click(screen.getByTestId("shoot-still"));
+
+            await vi.waitFor(() => {
+                expect(onCaptureActiveChange).toHaveBeenCalledWith(false);
+            });
+            expect(onCaptureActiveChange.mock.calls.map(([active]) => active)).toEqual([true, false]);
+        });
+
+        it("エンコードに失敗したら送らずエラー表示する", async () => {
+            const { stream } = fakeStream();
+            getUserMediaMock.mockResolvedValue(stream);
+            stubVideoDimensions(1280, 720);
+            stubStillCanvas(null);
+            const onCaptured = vi.fn();
+
+            render(CameraRecorder, {
+                props: { onCaptured, onCameraUnavailable: vi.fn(), mode: "still" },
+            });
+            await fireEvent.click(screen.getByTestId("shoot-still"));
+
+            await vi.waitFor(() => {
+                expect(screen.getByRole("alert")).toHaveTextContent(
+                    "写真を取得できませんでした。もう一度お試しください。",
+                );
+            });
+            expect(onCaptured).not.toHaveBeenCalled();
+        });
+
+        it("MediaRecorder が使えない端末でもシャッターは機能する (録画能力に依存しない)", async () => {
+            FakeMediaRecorder.supportedTypes = [];
+            const { stream } = fakeStream();
+            getUserMediaMock.mockResolvedValue(stream);
+            stubVideoDimensions(1280, 720);
+            stubStillCanvas(new Blob(["jpeg"], { type: "image/jpeg" }));
+            const onCaptured = vi.fn();
+            const onCameraUnavailable = vi.fn();
+
+            render(CameraRecorder, {
+                props: { onCaptured, onCameraUnavailable, mode: "still" },
+            });
+            await fireEvent.click(screen.getByTestId("shoot-still"));
+
+            await vi.waitFor(() => {
+                expect(onCaptured).toHaveBeenCalledTimes(1);
+            });
+            expect(onCameraUnavailable).not.toHaveBeenCalled();
+        });
+    });
 });
diff --git a/tests/js/components/features/capture/CaptureFileFallback.test.ts b/tests/js/components/features/capture/CaptureFileFallback.test.ts
new file mode 100644
index 0000000..d3b97b8
--- /dev/null
+++ b/tests/js/components/features/capture/CaptureFileFallback.test.ts
@@ -0,0 +1,104 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
+
+/*
+ * MediaRecorder 非対応環境のフォールバック撮影。
+ * カットの計画が静止画なら accept を image/* に切り替え、選ばれた画像は**必ず再エンコード**
+ * してから親へ渡す (寸法上限が効き EXIF が落ちる)。正規化に失敗したら原本は送らない。
+ */
+
+const normalizeStillFile = vi.hoisted(() => vi.fn());
+
+vi.mock("@/lib/capture/still-encode", async (importOriginal) => {
+    const actual = await importOriginal<typeof import("@/lib/capture/still-encode")>();
+    return { ...actual, normalizeStillFile };
+});
+
+/** file input に File を差し込んで change を発火する */
+async function selectFile(file: File): Promise<void> {
+    const input = screen.getByTestId("capture-file-input") as HTMLInputElement;
+    Object.defineProperty(input, "files", { value: [file], configurable: true });
+    await fireEvent.change(input);
+}
+
+beforeEach(() => {
+    normalizeStillFile.mockReset();
+});
+
+afterEach(() => {
+    cleanup();
+    vi.restoreAllMocks();
+});
+
+describe("CaptureFileFallback", () => {
+    it("既定 (video) は accept が video/* で、選んだファイルをそのまま渡す", async () => {
+        const onCaptured = vi.fn();
+        render(CaptureFileFallback, { props: { onCaptured } });
+
+        expect(screen.getByTestId("capture-file-input")).toHaveAttribute("accept", "video/*");
+
+        const file = new File(["mp4"], "a.mp4", { type: "video/mp4" });
+        await selectFile(file);
+
+        await vi.waitFor(() => expect(onCaptured).toHaveBeenCalledTimes(1));
+        expect(onCaptured.mock.calls[0][0]).toBe(file); // 動画は再エンコードしない
+        expect(onCaptured.mock.calls[0][1]).toBe("video/mp4");
+        expect(normalizeStillFile).not.toHaveBeenCalled();
+    });
+
+    it("material=still は accept が image/* になり、正規化した blob を image/jpeg で渡す", async () => {
+        const normalized = new Blob(["jpeg"], { type: "image/jpeg" });
+        normalizeStillFile.mockResolvedValue(normalized);
+        const onCaptured = vi.fn();
+        render(CaptureFileFallback, { props: { material: "still", onCaptured } });
+
+        expect(screen.getByTestId("capture-file-input")).toHaveAttribute("accept", "image/*");
+
+        const file = new File(["png"], "a.png", { type: "image/png" });
+        await selectFile(file);
+
+        await vi.waitFor(() => expect(onCaptured).toHaveBeenCalledTimes(1));
+        expect(onCaptured).toHaveBeenCalledWith(normalized, "image/jpeg");
+    });
+
+    it("正規化に失敗したら原本を送らずエラー表示する", async () => {
+        normalizeStillFile.mockResolvedValue(null);
+        const onCaptured = vi.fn();
+        render(CaptureFileFallback, { props: { material: "still", onCaptured } });
+
+        await selectFile(new File(["png"], "a.png", { type: "image/png" }));
+
+        await vi.waitFor(() => {
+            expect(screen.getByRole("alert")).toHaveTextContent(
+                "画像を読み込めませんでした。別のファイルをお試しください。",
+            );
+        });
+        expect(onCaptured).not.toHaveBeenCalled();
+    });
+
+    it("still で動画を選ぶとエラー (押下は受けてから理由を出す)", async () => {
+        const onCaptured = vi.fn();
+        render(CaptureFileFallback, { props: { material: "still", onCaptured } });
+
+        await selectFile(new File(["mp4"], "a.mp4", { type: "video/mp4" }));
+
+        await vi.waitFor(() => {
+            expect(screen.getByRole("alert")).toHaveTextContent("画像ファイルを選択してください。");
+        });
+        expect(onCaptured).not.toHaveBeenCalled();
+        expect(normalizeStillFile).not.toHaveBeenCalled();
+    });
+
+    it("video で画像を選ぶとエラー (回帰)", async () => {
+        const onCaptured = vi.fn();
+        render(CaptureFileFallback, { props: { onCaptured } });
+
+        await selectFile(new File(["png"], "a.png", { type: "image/png" }));
+
+        await vi.waitFor(() => {
+            expect(screen.getByRole("alert")).toHaveTextContent("動画ファイルを選択してください。");
+        });
+        expect(onCaptured).not.toHaveBeenCalled();
+    });
+});
diff --git a/tests/js/components/features/capture/CutNavigator.test.ts b/tests/js/components/features/capture/CutNavigator.test.ts
index 6f40a75..3557d4f 100644
--- a/tests/js/components/features/capture/CutNavigator.test.ts
+++ b/tests/js/components/features/capture/CutNavigator.test.ts
@@ -14,6 +14,7 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
         narration: "",
         subtitle_primary: null,
         subtitle_secondary: "",
+        material_type: null,
         adopted_take_id: null,
         adopted_ready_take_id: null,
         takes: [],
diff --git a/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts b/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts
index 8f23bfd..8ab43c8 100644
--- a/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts
+++ b/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts
@@ -29,6 +29,7 @@ function cut(id: number, readyTakeId: number | null): CaptureCut {
         narration: "",
         subtitle_primary: null,
         subtitle_secondary: `字幕 ${id}`,
+        material_type: null,
         adopted_take_id: readyTakeId,
         adopted_ready_take_id: readyTakeId,
         takes: [],
diff --git a/tests/js/components/features/capture/TakePreviewDialog.test.ts b/tests/js/components/features/capture/TakePreviewDialog.test.ts
index cd66a4e..14d4478 100644
--- a/tests/js/components/features/capture/TakePreviewDialog.test.ts
+++ b/tests/js/components/features/capture/TakePreviewDialog.test.ts
@@ -14,6 +14,7 @@ function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
         id: 10,
         client_take_id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
         status: "ready",
+        material_type: "video",
         size_bytes: 1024 * 1024,
         duration_ms: 4000,
         comment: null,
@@ -38,6 +39,7 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
         narration: "作業台の準備を行います",
         subtitle_primary: "STEP 1",
         subtitle_secondary: "作業台を準備する",
+        material_type: null,
         adopted_take_id: null,
         adopted_ready_take_id: null,
         takes: [],
@@ -228,4 +230,79 @@ describe("TakePreviewDialog", () => {
             ),
         );
     });
+    /*
+     * 静止画テイクは <video> ではなく <img> で出す。素材種別は**申告 Content-Type からの分類**
+     * であって実体の形式を保証しないため、読み込み失敗の受け皿を必ず置く
+     * (「何も出ない」状態を作らない)。<video> 側には足さない = 非対称は意図的。
+     */
+    describe("静止画テイク", () => {
+        function renderStill(id = 10) {
+            return render(TakePreviewDialog, {
+                open: true,
+                take: makeTake({ id, material_type: "still" }),
+                cut: makeCut(),
+                cutLabel: "手順1",
+                playbackUrl: `/signed/take-${id}`,
+                adopting: false,
+                error: null,
+                onAdopt: vi.fn(),
+                onClose: vi.fn(),
+            });
+        }
+
+        it("still は <img> を出し <video> を出さない", async () => {
+            renderStill();
+
+            const image = await screen.findByTestId("take-preview-image");
+            expect(image).toHaveAttribute("src", "/signed/take-10");
+            expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
+        });
+
+        it("video は従来どおり <video> (回帰)", async () => {
+            render(TakePreviewDialog, {
+                open: true,
+                take: makeTake(),
+                cut: makeCut(),
+                cutLabel: "手順1",
+                playbackUrl: "/signed/take-10",
+                adopting: false,
+                error: null,
+                onAdopt: vi.fn(),
+                onClose: vi.fn(),
+            });
+
+            expect(await screen.findByTestId("take-preview-video")).toBeInTheDocument();
+            expect(screen.queryByTestId("take-preview-image")).not.toBeInTheDocument();
+        });
+
+        it("読み込み失敗で受け皿に差し替わる", async () => {
+            renderStill();
+
+            await fireEvent.error(await screen.findByTestId("take-preview-image"));
+
+            await waitFor(() =>
+                expect(screen.getByTestId("take-preview-unavailable")).toHaveTextContent(
+                    "このテイクはプレビューできません。",
+                ),
+            );
+        });
+
+        it("テイクを切り替えると失敗状態がリセットされる", async () => {
+            const { rerender } = renderStill(10);
+            await fireEvent.error(await screen.findByTestId("take-preview-image"));
+            await screen.findByTestId("take-preview-unavailable");
+
+            await rerender({
+                take: makeTake({ id: 20, material_type: "still" }),
+                playbackUrl: "/signed/take-20",
+            });
+
+            await waitFor(() =>
+                expect(screen.getByTestId("take-preview-image")).toHaveAttribute(
+                    "src",
+                    "/signed/take-20",
+                ),
+            );
+        });
+    });
 });
diff --git a/tests/js/components/features/capture/TakeStrip.test.ts b/tests/js/components/features/capture/TakeStrip.test.ts
index 9eb939e..40338cc 100644
--- a/tests/js/components/features/capture/TakeStrip.test.ts
+++ b/tests/js/components/features/capture/TakeStrip.test.ts
@@ -16,6 +16,7 @@ function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
         id: 10,
         client_take_id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
         status: "ready",
+        material_type: "video",
         size_bytes: 1024 * 1024,
         duration_ms: 4000,
         comment: null,
@@ -40,6 +41,7 @@ function makeCut(takes: CaptureTake[], adopted: number | null = null): CaptureCu
         narration: "作業台の準備を行います",
         subtitle_primary: null,
         subtitle_secondary: "作業台を準備",
+        material_type: null,
         adopted_take_id: adopted,
         adopted_ready_take_id: adopted,
         takes,
diff --git a/tests/js/components/features/manual/ScenarioEditor.test.ts b/tests/js/components/features/manual/ScenarioEditor.test.ts
index c21980b..b5d41e2 100644
--- a/tests/js/components/features/manual/ScenarioEditor.test.ts
+++ b/tests/js/components/features/manual/ScenarioEditor.test.ts
@@ -1721,9 +1721,20 @@ describe("IME 変換中の構造操作は安定キーで解決する (T188)", ()
 describe("動画列のサムネイル表示条件 (T190)", () => {
     /** 採用テイクの要約 (step id=11 のカット) */
     function summary(
-        adopted: { id: number; status: "ready" | "processing"; has_thumbnail: boolean } | null,
+        adopted: {
+            id: number;
+            status: "ready" | "processing";
+            has_thumbnail: boolean;
+            material_type?: "video" | "still";
+        } | null,
     ) {
-        return [{ cut_id: 11, takes_count: 2, adopted }];
+        return [
+            {
+                cut_id: 11,
+                takes_count: 2,
+                adopted: adopted === null ? null : { material_type: "video" as const, ...adopted },
+            },
+        ];
     }
 
     function renderWith(takeSummaries: ReturnType<typeof summary>) {
@@ -1782,6 +1793,32 @@ describe("動画列のサムネイル表示条件 (T190)", () => {
         renderWith(summary({ id: 9, status: "ready", has_thumbnail: true }));
 
         expect(screen.getAllByTestId("video-cell-count")[0]).toHaveTextContent("テイク 2 件");
-        expect(screen.getAllByTestId("video-cell-adopted")[0]).toHaveTextContent("採用済み");
+        // 素材登録状況 (未登録 / 動画登録済 / 静止画登録済) のバッジへ置き換わった
+        expect(screen.getAllByTestId("video-cell-material")[0]).toHaveTextContent("動画登録済");
+    });
+    /*
+     * 素材登録状況 (doc/02 §2.4 の 3 値)。「採用テイクが在るか」と「その素材種別」だけで決める。
+     * ready かどうか (使えるか) は別軸なので、このバッジには混ぜない。
+     */
+    it("未採用は「未登録」バッジ", () => {
+        renderWith(summary(null));
+
+        expect(screen.getAllByTestId("video-cell-material")[0]).toHaveTextContent("未登録");
+    });
+
+    it("静止画テイクを採用していれば「静止画登録済」バッジ", () => {
+        renderWith(
+            summary({ id: 9, status: "ready", has_thumbnail: true, material_type: "still" }),
+        );
+
+        expect(screen.getAllByTestId("video-cell-material")[0]).toHaveTextContent("静止画登録済");
+    });
+
+    it("processing の採用テイクでも「登録済」と出す (登録されたか / 使えるかは別軸)", () => {
+        renderWith(
+            summary({ id: 9, status: "processing", has_thumbnail: false, material_type: "video" }),
+        );
+
+        expect(screen.getAllByTestId("video-cell-material")[0]).toHaveTextContent("動画登録済");
     });
 });
diff --git a/tests/js/lib/capture/auto-download.test.ts b/tests/js/lib/capture/auto-download.test.ts
index 2918de0..a6ce7be 100644
--- a/tests/js/lib/capture/auto-download.test.ts
+++ b/tests/js/lib/capture/auto-download.test.ts
@@ -18,6 +18,7 @@ function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
         id: 11,
         client_take_id: "01J0AUTODL",
         status: "ready",
+        material_type: "video",
         size_bytes: 1024,
         duration_ms: 4200,
         comment: null,
@@ -43,6 +44,7 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
         narration: "ドライバーでネジを締めます",
         subtitle_primary: null,
         subtitle_secondary: "",
+        material_type: null,
         adopted_take_id: takes[0]?.id ?? null,
         adopted_ready_take_id: takes[0]?.id ?? null,
         takes,
diff --git a/tests/js/lib/capture/cut-labels.test.ts b/tests/js/lib/capture/cut-labels.test.ts
index 6c1fef9..918dec2 100644
--- a/tests/js/lib/capture/cut-labels.test.ts
+++ b/tests/js/lib/capture/cut-labels.test.ts
@@ -29,6 +29,7 @@ function cut(id: number, type: "step" | "point"): CaptureCut {
         narration: "",
         subtitle_primary: null,
         subtitle_secondary: "",
+        material_type: null,
         adopted_take_id: null,
         adopted_ready_take_id: null,
         takes: [],
diff --git a/tests/js/lib/capture/scenario-preview.test.ts b/tests/js/lib/capture/scenario-preview.test.ts
index 92ae991..b3a5495 100644
--- a/tests/js/lib/capture/scenario-preview.test.ts
+++ b/tests/js/lib/capture/scenario-preview.test.ts
@@ -37,6 +37,7 @@ function cut(id: number, readyTakeId: number | null, type: "step" | "point" = "s
         narration: "",
         subtitle_primary: null,
         subtitle_secondary: `字幕 ${id}`,
+        material_type: null,
         adopted_take_id: readyTakeId,
         adopted_ready_take_id: readyTakeId,
         takes: [],
diff --git a/tests/js/lib/capture/still-encode.test.ts b/tests/js/lib/capture/still-encode.test.ts
new file mode 100644
index 0000000..8ecb8db
--- /dev/null
+++ b/tests/js/lib/capture/still-encode.test.ts
@@ -0,0 +1,143 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import {
+    encodeStillJpeg,
+    fitWithinMaxEdge,
+    normalizeStillFile,
+    STILL_CONTENT_TYPE,
+    STILL_JPEG_QUALITY,
+    STILL_MAX_EDGE,
+} from "@/lib/capture/still-encode";
+
+/*
+ * 静止画テイクのエンコード規約。
+ * 契約の要点は「失敗は必ず null で返す (reject しない)」で、呼び出し側に .catch() を
+ * 配って回らずに済むようにここ 1 か所で閉じている。
+ */
+
+afterEach(() => {
+    vi.restoreAllMocks();
+});
+
+/** canvas を差し替える (jsdom は toBlob / 2d context を持たない) */
+function stubCanvas(options: {
+    context: unknown;
+    toBlob?: (callback: (blob: Blob | null) => void, type?: string, quality?: number) => void;
+}): { calls: { type?: string; quality?: number }[] } {
+    const calls: { type?: string; quality?: number }[] = [];
+    vi.spyOn(document, "createElement").mockImplementation((tag: string) => {
+        if (tag !== "canvas") {
+            return document.createElementNS("http://www.w3.org/1999/xhtml", tag) as HTMLElement;
+        }
+        return {
+            width: 0,
+            height: 0,
+            getContext: () => options.context,
+            toBlob:
+                options.toBlob ??
+                ((callback: (blob: Blob | null) => void, type?: string, quality?: number) => {
+                    calls.push({ type, quality });
+                    callback(new Blob(["jpeg"], { type: STILL_CONTENT_TYPE }));
+                }),
+        } as unknown as HTMLElement;
+    });
+    return { calls };
+}
+
+describe("fitWithinMaxEdge", () => {
+    it("長辺が上限以下なら等倍のまま (拡大しない)", () => {
+        expect(fitWithinMaxEdge(640, 480)).toEqual({ width: 640, height: 480 });
+    });
+
+    it("長辺が上限を超えたら比率を保って縮小する", () => {
+        expect(fitWithinMaxEdge(3840, 2160)).toEqual({
+            width: STILL_MAX_EDGE,
+            height: Math.round((2160 * STILL_MAX_EDGE) / 3840),
+        });
+    });
+
+    it("縦長でも長辺基準で縮む", () => {
+        expect(fitWithinMaxEdge(2160, 3840)).toEqual({
+            width: Math.round((2160 * STILL_MAX_EDGE) / 3840),
+            height: STILL_MAX_EDGE,
+        });
+    });
+
+    it("寸法 0 でも 0 除算にならない", () => {
+        expect(fitWithinMaxEdge(0, 0)).toEqual({ width: 0, height: 0 });
+    });
+});
+
+describe("encodeStillJpeg", () => {
+    it("JPEG blob を規約どおりの type / quality で返す", async () => {
+        const { calls } = stubCanvas({ context: { drawImage: vi.fn() } });
+
+        const blob = await encodeStillJpeg({} as CanvasImageSource, 640, 480);
+
+        expect(blob).not.toBeNull();
+        expect(calls[0]).toEqual({ type: STILL_CONTENT_TYPE, quality: STILL_JPEG_QUALITY });
+    });
+
+    it("寸法 0 (grant 前の video など) では null (原本を送らせない)", async () => {
+        const blob = await encodeStillJpeg({} as CanvasImageSource, 0, 0);
+        expect(blob).toBeNull();
+    });
+
+    it("2d context を取れなければ null", async () => {
+        stubCanvas({ context: null });
+        expect(await encodeStillJpeg({} as CanvasImageSource, 640, 480)).toBeNull();
+    });
+
+    it("drawImage が throw しても reject せず null", async () => {
+        stubCanvas({
+            context: {
+                drawImage: () => {
+                    throw new Error("tainted");
+                },
+            },
+        });
+        expect(await encodeStillJpeg({} as CanvasImageSource, 640, 480)).toBeNull();
+    });
+
+    it("toBlob が null を返したら null", async () => {
+        stubCanvas({
+            context: { drawImage: vi.fn() },
+            toBlob: (callback) => callback(null),
+        });
+        expect(await encodeStillJpeg({} as CanvasImageSource, 640, 480)).toBeNull();
+    });
+
+    it("toBlob が throw しても reject せず null", async () => {
+        stubCanvas({
+            context: { drawImage: vi.fn() },
+            toBlob: () => {
+                throw new Error("unsupported");
+            },
+        });
+        expect(await encodeStillJpeg({} as CanvasImageSource, 640, 480)).toBeNull();
+    });
+});
+
+describe("normalizeStillFile", () => {
+    it("デコードできなければ null (原本を送らない)", async () => {
+        vi.stubGlobal("URL", {
+            ...URL,
+            createObjectURL: () => "blob:stub",
+            revokeObjectURL: () => undefined,
+        });
+        class FailingImage {
+            onload: (() => void) | null = null;
+            onerror: (() => void) | null = null;
+            naturalWidth = 0;
+            naturalHeight = 0;
+            set src(_value: string) {
+                queueMicrotask(() => this.onerror?.());
+            }
+        }
+        vi.stubGlobal("Image", FailingImage);
+
+        const file = new File(["not-an-image"], "x.jpg", { type: "image/jpeg" });
+        expect(await normalizeStillFile(file)).toBeNull();
+
+        vi.unstubAllGlobals();
+    });
+});
diff --git a/tests/js/lib/capture/thumbnail-refresh.test.ts b/tests/js/lib/capture/thumbnail-refresh.test.ts
index dc37b67..0ac8e45 100644
--- a/tests/js/lib/capture/thumbnail-refresh.test.ts
+++ b/tests/js/lib/capture/thumbnail-refresh.test.ts
@@ -15,6 +15,7 @@ function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
         id: 10,
         client_take_id: "take-a",
         status: "ready",
+        material_type: "video",
         size_bytes: 1024,
         duration_ms: 1000,
         comment: null,
@@ -44,6 +45,7 @@ function makeManual(takes: CaptureTake[]): CaptureManualDetail {
                 narration: "準備します",
                 subtitle_primary: null,
                 subtitle_secondary: "準備",
+                material_type: null,
                 adopted_take_id: null,
                 adopted_ready_take_id: null,
                 takes,
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 905238e..111a5f6 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -99,6 +99,7 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
         narration: "ドライバーでネジを締めます",
         subtitle_primary: null,
         subtitle_secondary: "",
+        material_type: null,
         adopted_take_id: null,
         adopted_ready_take_id: null,
         takes: [],
@@ -121,6 +122,7 @@ function makeAdoptedManual(): CaptureManualDetail {
         id: 900,
         client_take_id: "01J0ADOPT",
         status: "ready",
+        material_type: "video",
         size_bytes: 2048,
         duration_ms: 3000,
         comment: null,
@@ -192,6 +194,66 @@ async function selectCut(): Promise<void> {
     await fireEvent.click(screen.getByTestId("cut-row-101"));
 }
 
+/*
+ * 撮影モードは**カットの計画**で決める (撮影者に判断させない = 使命)。
+ * 静止画は MediaRecorder を必要としないため、能力判定も録画とは別軸である。
+ */
+describe("Capture/Show 撮影モードの出し分け", () => {
+    /** material_type=still のカット 1 枚だけを持つ props */
+    function stillProps() {
+        const cut = makeCut({ material_type: "still" });
+        return {
+            ...baseProps,
+            manual: { id: 5, title: "ネジ締め作業", status: "ready", cuts: [cut] },
+        };
+    }
+
+    it("still カットではシャッターが出て録画開始は出ない", async () => {
+        stubCameraSupported(true);
+
+        render(CaptureShow, { props: stillProps() });
+        await selectCut();
+
+        expect(screen.getByTestId("shoot-still")).toBeInTheDocument();
+        expect(screen.queryByTestId("start-recording")).not.toBeInTheDocument();
+    });
+
+    it("video カットでは従来どおり録画開始が出る (回帰)", async () => {
+        stubCameraSupported(true);
+
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+
+        expect(screen.getByTestId("start-recording")).toBeInTheDocument();
+        expect(screen.queryByTestId("shoot-still")).not.toBeInTheDocument();
+    });
+
+    it("MediaRecorder 非対応でも still カットならカメラを出す (getUserMedia があれば足りる)", async () => {
+        // supportsMediaRecorder() を静止画へ流用すると、撮れるはずの写真まで file input へ落ちる
+        vi.stubGlobal("MediaRecorder", undefined);
+        vi.stubGlobal("navigator", {
+            ...navigator,
+            mediaDevices: { getUserMedia: getUserMediaMock },
+        });
+
+        render(CaptureShow, { props: stillProps() });
+        await selectCut();
+
+        expect(screen.getByTestId("camera-preview")).toBeInTheDocument();
+        expect(screen.getByTestId("shoot-still")).toBeInTheDocument();
+    });
+
+    it("getUserMedia も無い端末では still でもファイル選択へ落ち、accept が image/* になる", async () => {
+        vi.stubGlobal("MediaRecorder", undefined);
+        vi.stubGlobal("navigator", { ...navigator, mediaDevices: undefined });
+
+        render(CaptureShow, { props: stillProps() });
+        await selectCut();
+
+        expect(screen.getByTestId("capture-file-input")).toHaveAttribute("accept", "image/*");
+    });
+});
+
 describe("Capture/Show カメラフォールバック", () => {
     it("(a) 静的 canRecord=false は file input のみ (notice を出さない)", async () => {
         stubCameraSupported(false);
diff --git a/tests/js/pages/ManualsEdit.test.ts b/tests/js/pages/ManualsEdit.test.ts
index 19c7f61..42702e4 100644
--- a/tests/js/pages/ManualsEdit.test.ts
+++ b/tests/js/pages/ManualsEdit.test.ts
@@ -145,7 +145,7 @@ describe("Manuals/Edit — 動画列", () => {
             },
         });
 
-        expect(screen.getByTestId("video-cell-adopted")).toHaveTextContent("採用済み");
+        expect(screen.getByTestId("video-cell-material")).toHaveTextContent("動画登録済");
     });
 
     it("未保存行 (手順を追加した直後) にはリンクが出ず、保存を促す文言が出る", async () => {
diff --git a/tests/js/pages/ManualsTakes.test.ts b/tests/js/pages/ManualsTakes.test.ts
index cfd15de..5164b79 100644
--- a/tests/js/pages/ManualsTakes.test.ts
+++ b/tests/js/pages/ManualsTakes.test.ts
@@ -55,6 +55,7 @@ function take(overrides: Partial<SelectableTake> = {}): SelectableTake {
     return {
         id: 101,
         status: "ready",
+        material_type: "video",
         size_bytes: 2 * 1024 * 1024,
         duration_ms: 12_000,
         comment: null,
@@ -74,6 +75,7 @@ const cut: TakeSelectionCut = {
     narration: "はじめに工具を準備します。",
     subtitle_primary: "トルク 12N・m",
     subtitle_secondary: "工具を準備する",
+    material_type: null,
     adopted: null,
 };
 
@@ -130,7 +132,14 @@ describe("Manuals/Takes — テイクの選択と採用", () => {
         render(Takes, {
             props: baseProps({
                 takes: [adopted, other],
-                cut: { ...cut, adopted: { id: adopted.id, status: "ready" as const } },
+                cut: {
+                    ...cut,
+                    adopted: {
+                        id: adopted.id,
+                        status: "ready" as const,
+                        material_type: "video" as const,
+                    },
+                },
             }),
         });
 

```

## テスト結果

- `composer test`: 5492 tests / 5490 passed / 0 failed / 2 skipped / 23723 assertions
- `composer phpstan`: OK (level 10, 969 files, no errors)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 155 files / 1922 tests passed
- `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed

## 実装時に設計から逸脱した点 (レビューで妥当性を判定してほしい)

1. 設計は `tests/Architecture/ConfigHardeningTest.php` へ config 値を pin すると書いているが、
   **このリポジトリに同テストは存在しない**。代わりに新設した
   `FfmpegProcessLaunchInventoryTest` が `manual.ffmpeg_max_alloc_bytes` を pin し、
   `StillDisplayDurationTest` が `default_still_display_seconds` の範囲 (1〜60) を固定した。
2. 設計は ffmpeg / ffprobe 起動の母集団を 2 ファイルとしていたが、実際には
   `app/Console/Commands/Development/PipelineSmokeCommand.php` も起動しており 3 ファイルだった。
   経路ごとの例外を作らず、同コマンドにも `FfmpegSafetyArguments` を適用して母集団 3 で pin した。
3. 設計の `CutTakeSummaryData` のコード例には既存の `adoptedHasThumbnail` が無かった (設計時より後の
   実装で入った)。既存フィールドを保持したまま `adoptedMaterialType` を追加した。
4. 設計は `CaptureFileFallback` の props を `onCaptured(blob, contentType)` に広げると書いており、
   現行の `onCaptured(file: File)` から変更した。呼び出し元は `Capture/Show.svelte` の 1 箇所のみ。
5. `TakePreviewPanel` の「まだ再生できません」文言は、静止画のときだけ「まだ表示できません」に切り替えた
   (動画側の既存文言・既存テストを変えないため)。
6. `ScenarioEditor` の動画列バッジは `video-cell-adopted` (「採用済み」) を
   `video-cell-material` (未登録 / 動画登録済 / 静止画登録済) へ**置き換えた** (並走させていない)。
