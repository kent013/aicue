# 使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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



## あなたの役割

Laravel 12 + Svelte 5 (runes) + Inertia のアプリ「AI-CUE」の**実装レビュアー**である。
TODO T183「テイクのサムネイル生成」の実装差分を、詳細設計書と突き合わせてレビューせよ。

## レビュー観点

1. **設計との一致性**: 詳細設計書 (S1〜S11) の意図どおりに実装されているか。逸脱があるなら、
   その逸脱が正当か (現行コード・規約優先の判断か、単なる実装漏れか)
2. **正確性**: 競合・冪等性・例外経路の穴。とくに
   - 結果の一回性 (条件付き UPDATE) と preflight (S3 PUT 直前の所有権再検証) の配置
   - キュー投入の原子性 (同一 tx 内 dispatch)
   - 決定的 S3 キーと「0 行更新でもオブジェクトを消さない」規律
   - 有界な再取得スケジューラ (タイマー/single-flight/停止条件) の状態遷移
3. **PHPStan level 10 適合性**: 型の widen / ignore / 不要な cast が無いか
4. **DTO / JsonResource パターン**: `response()->json()` 直書きが無いか。shape の一元管理
5. **テスト網羅性**: 各施策にテストがあるか。**保証していないことをテストが保証しているように
   見せていないか**。Factory 生成か (Model::create 手組み禁止)
6. **セキュリティ**: nested route の 404 が認可より前か / 状態秘匿 / ffmpeg 引数の安全境界 /
   payload 不信任 / 容量 Quota 計上
7. **DESIGN.md 準拠**: color / radius / typography は DS token 経由か。hex 直書き (`#RRGGBB`) を
   増やしていないか。token 値を変える diff なら `resources/css/tokens.css` と同一 diff で同期しているか
8. **Atomic Design 準拠**: `resources/js/components/` の階層 (atoms → molecules → organisms →
   features/{domain} → templates → pages) の単方向 import を守っているか。アイコンは
   `@lucide/svelte` のみで SVG 直書きを増やしていないか

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - Critical: 正しさ・安全性・規約違反 (必ず直すべき)
  - Warning: 直した方がよい (検討必須)
  - Suggestion: 好みの範囲
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書く
- 指摘は必ず**ファイル名と該当箇所**を添えて、根拠と再現条件を書くこと
- 「今必要なものだけ作る」原則があるため、機能追加の提案は Suggestion 以下に留めること


---


## 詳細設計書

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


## 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Capture/CaptureTakeData.php b/app/DataTransferObjects/Capture/CaptureTakeData.php
index 91568c1..e1a57fd 100644
--- a/app/DataTransferObjects/Capture/CaptureTakeData.php
+++ b/app/DataTransferObjects/Capture/CaptureTakeData.php
@@ -4,6 +4,7 @@
 
 namespace App\DataTransferObjects\Capture;
 
+use App\Enums\Manual\TakeStatus;
 use App\Models\Take;
 
 /**
@@ -29,7 +30,8 @@ public static function fromTake(Take $take, ?string $playbackUrl = null, ?string
     /**
      * @return array{id: int, client_take_id: string, status: string, size_bytes: int,
      *   duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
-     *   downloaded: bool, playback_url: string|null, download_ack_token: string|null}
+     *   downloaded: bool, has_thumbnail: bool, playback_url: string|null,
+     *   download_ack_token: string|null}
      */
     public function toArray(): array
     {
@@ -43,6 +45,13 @@ public function toArray(): array
             'captured_at' => $this->take->captured_at?->toIso8601String(),
             'sort_order' => $this->take->sort_order,
             'downloaded' => $this->take->downloaded_at !== null,
+            // サムネイルの**有無だけ**を出す (パスも署名 URL も出さない)。UI はこの 1 つで
+            // <img> とプレースホルダを出し分け、未生成テイクへ 404 のリクエストを出さない。
+            // ★ 述語は **GET .../thumbnail が 302 を返す条件と 1 対 1** にする
+            //   (ready でないテイクで true を返すと、必ず 404 になる <img> を描画してしまう)。
+            //   T154 の「秘匿境界は props 側」「props と endpoint は 1 対 1」と同じ作法
+            'has_thumbnail' => $this->take->status === TakeStatus::Ready
+                && $this->take->thumbnail_path !== null,
             'playback_url' => $this->playbackUrl,
             'download_ack_token' => $this->downloadAckToken,
         ];
diff --git a/app/Exceptions/Capture/TakeThumbnailExtractionException.php b/app/Exceptions/Capture/TakeThumbnailExtractionException.php
new file mode 100644
index 0000000..0f34486
--- /dev/null
+++ b/app/Exceptions/Capture/TakeThumbnailExtractionException.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Capture;
+
+use RuntimeException;
+
+/**
+ * サムネイル抽出の失敗 (ffmpeg 非 0 終了 / フレーム未生成 / 出力先の掃除失敗)。
+ *
+ * 失敗しても take は `ready` のままである (サムネイルは採用・レンダの必須条件ではない)。
+ * 本例外はジョブへ伝播し、再試行を使い切ると failed_jobs に残るだけになる。
+ */
+final class TakeThumbnailExtractionException extends RuntimeException {}
diff --git a/app/Http/Controllers/Capture/CaptureTakeController.php b/app/Http/Controllers/Capture/CaptureTakeController.php
index 3ee4cad..6846b5e 100644
--- a/app/Http/Controllers/Capture/CaptureTakeController.php
+++ b/app/Http/Controllers/Capture/CaptureTakeController.php
@@ -176,4 +176,47 @@ public function playback(
             ->away($storage->temporaryPlaybackUrl($take->video_path))
             ->withHeaders(['Cache-Control' => 'no-store, private']);
     }
+
+    /**
+     * テイクのサムネイル表示 (302 → S3 署名 URL)。撮影者/編集者 (capture ability)。
+     * doc/04 動画列 / doc/05 撮影後の下部サムネイル確認。
+     *
+     * 層の順序は playback と同一 (認可より前に 404):
+     * 1. {project} ∈ current org (project.in-current-org middleware + resolveOrganizationProject)
+     * 2. {manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は Route::scopeBindings()
+     * 3. 認可 (preview ability。動画の再生と同じ権限で見せる)
+     *
+     * 404 にするのは 2 つ: ready でないテイク (内部状態を存在有無として漏らさない) と、
+     * **サムネイル未生成** (生成前・生成失敗・過去分)。UI は has_thumbnail で出し分けるため
+     * 通常この 404 は起きないが、生成前の取得競合を安全側に倒すために閉じておく。
+     *
+     * 302 応答は Cache-Control: no-store, private (期限付き署名 URL の再利用防止)。
+     * ※ リダイレクト先の画像本体の cache までは保証しない (動画側と同じ扱い)。
+     */
+    public function thumbnail(
+        Request $request,
+        Project $project,
+        VideoManual $manual,
+        Cut $cut,
+        Take $take,
+        TakeObjectStorage $storage,
+    ): RedirectResponse {
+        $organization = $this->resolveCurrentOrganization($request);
+        // URL 整合 guard: 認可より前に 404
+        $this->resolveOrganizationProject($organization, $project);
+        Gate::authorize('preview', $take);
+
+        if ($take->status !== TakeStatus::Ready) {
+            abort(404);
+        }
+        // ローカル変数へ取ってから早期 return する (プロパティのままだと level 10 が narrowing を保持しない)
+        $path = $take->thumbnail_path;
+        if ($path === null) {
+            abort(404); // 未生成 (生成前 / 失敗 / 過去分)
+        }
+
+        return redirect()
+            ->away($storage->temporaryThumbnailUrl($path))
+            ->withHeaders(['Cache-Control' => 'no-store, private']);
+    }
 }
diff --git a/app/Http/Resources/Capture/CaptureTakeResource.php b/app/Http/Resources/Capture/CaptureTakeResource.php
index da7c86c..b559c6d 100644
--- a/app/Http/Resources/Capture/CaptureTakeResource.php
+++ b/app/Http/Resources/Capture/CaptureTakeResource.php
@@ -21,7 +21,8 @@ final class CaptureTakeResource extends JsonResource
     /**
      * @return array{id: int, client_take_id: string, status: string, size_bytes: int,
      *   duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
-     *   downloaded: bool, playback_url: string|null, download_ack_token: string|null}
+     *   downloaded: bool, has_thumbnail: bool, playback_url: string|null,
+     *   download_ack_token: string|null}
      */
     public function toArray(Request $request): array
     {
diff --git a/app/Jobs/Capture/GenerateTakeThumbnailJob.php b/app/Jobs/Capture/GenerateTakeThumbnailJob.php
new file mode 100644
index 0000000..3f29830
--- /dev/null
+++ b/app/Jobs/Capture/GenerateTakeThumbnailJob.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Jobs\Capture;
+
+use App\Services\Capture\TakeThumbnailPipeline;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+
+/**
+ * テイクのサムネイル生成 (薄い殻。本体は TakeThumbnailPipeline)。
+ *
+ * - payload は takeId のみ (モデル/org 値を payload に持たない = payload 不信任)
+ * - media queue (`database-media`。queue=media / retry_after=300) で流す。
+ *   運用契約: 本番/ステージングは `php artisan queue:work database-media --timeout=240` を
+ *   worker 定義に必須登録 (docs/architecture.md §撮影 PWA。既存の削除ジョブと同じ worker)
+ * - 時間予算の連鎖: ffmpeg 60 < $timeout 180 < worker --timeout 240 < retry_after 300
+ * - **失敗しても take は ready のまま**である (サムネイルは採用・レンダの必須条件ではない)。
+ *   最終失敗は failed_jobs に残るだけで、UI はプレースホルダへ degrade する
+ */
+class GenerateTakeThumbnailJob implements ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+
+    /** S3 / ffmpeg の一過性障害を吸収する (生成は冪等なので再試行して安全) */
+    public int $tries = 3;
+
+    /** @var list<int> 再試行間隔 (秒) */
+    public array $backoff = [60, 180];
+
+    /** worker の --timeout=240 より短く取り、強制終了より先に自前の finally へ入る余地を残す */
+    public int $timeout = 180;
+
+    public function __construct(public readonly int $takeId)
+    {
+        // メディア処理専用 connection (config/queue.php database-media)
+        $this->onConnection('database-media');
+    }
+
+    public function handle(TakeThumbnailPipeline $pipeline): void
+    {
+        $pipeline->run($this->takeId);
+    }
+}
diff --git a/app/Models/Take.php b/app/Models/Take.php
index 3982071..11f92a5 100644
--- a/app/Models/Take.php
+++ b/app/Models/Take.php
@@ -20,12 +20,15 @@
  * - sort_order はテイク登録 Service が採番するため $fillable 外
  * - downloaded_at はサーバ打刻 (POST .../downloaded の ACK トークン検証経由のみ) のため
  *   $fillable 外。非 null は削除不可 (概念設計 D6)
+ * - thumbnail_path / thumbnail_size_bytes は**サーバ生成の会計値**のため $fillable 外。
+ *   書き込みは TakeThumbnailPipeline の条件付き UPDATE (query builder) だけである
  *
  * @property int $id
  * @property int $cut_id
  * @property string $client_take_id
  * @property string $video_path
  * @property string|null $thumbnail_path
+ * @property int|null $thumbnail_size_bytes
  * @property int $size_bytes
  * @property int|null $duration_ms
  * @property TakeStatus $status
@@ -43,7 +46,6 @@ class Take extends Model
     protected $fillable = [
         'client_take_id',
         'video_path',
-        'thumbnail_path',
         'size_bytes',
         'duration_ms',
         'comment',
@@ -59,6 +61,10 @@ protected function casts(): array
             'status' => TakeStatus::class,
             'captured_at' => 'datetime',
             'downloaded_at' => 'datetime',
+            // 読み取り型を driver 依存にしない (DTO / Resource / PHPStan が int|null で安定する)。
+            // size_bytes 側には cast を足さない — 既存の比較箇所への影響を本タスクへ持ち込まないため。
+            // 非対称は意図的である
+            'thumbnail_size_bytes' => 'integer',
         ];
     }
 
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 52a909d..ba0a432 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -29,6 +29,8 @@
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use App\Services\Billing\StripeWebhookProcessor;
 use App\Services\Billing\TicketCheckoutGateway;
+use App\Services\Capture\FfmpegTakeThumbnailExtractor;
+use App\Services\Capture\TakeThumbnailExtractor;
 use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
 use App\Services\Mail\Sns\SnsSignatureVerifier;
 use App\Services\Render\FfmpegVideoComposer;
@@ -118,6 +120,9 @@ public function register(): void
         // 動画合成の抽象 (doc/09 §9.7)。v1 は ffmpeg 実装。テストは fake 実装へ swap する
         $this->app->bind(VideoComposer::class, FfmpegVideoComposer::class);
 
+        // テイクのサムネイル抽出の抽象。v1 は ffmpeg 実装。テストは fake 実装へ swap する
+        $this->app->bind(TakeThumbnailExtractor::class, FfmpegTakeThumbnailExtractor::class);
+
         // チケットスポット購入の Stripe Checkout 抽象 (T007)。テストは fake を bind する
         $this->app->bind(TicketCheckoutGateway::class, CashierTicketCheckoutGateway::class);
 
diff --git a/app/Services/Capture/Fakes/FakeTakeObjectStorage.php b/app/Services/Capture/Fakes/FakeTakeObjectStorage.php
index 53e2443..b1d4945 100644
--- a/app/Services/Capture/Fakes/FakeTakeObjectStorage.php
+++ b/app/Services/Capture/Fakes/FakeTakeObjectStorage.php
@@ -10,6 +10,7 @@
 use App\Services\Storage\Fakes\FakeObjectStore;
 use Aws\S3\S3Client;
 use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\Storage;
 use Illuminate\Support\Facades\URL;
 use RuntimeException;
 
@@ -53,6 +54,46 @@ public function temporaryPlaybackUrl(string $path): string
         );
     }
 
+    /**
+     * s3_fake disk 上の実体をローカルへコピーする
+     * (親と同じ readStream → ローカル書き込みの経路を fake disk で通す)。
+     */
+    public function downloadToLocal(string $path, string $localPath): void
+    {
+        $stream = Storage::disk(FakeObjectStore::DISK)->readStream($path);
+        if ($stream === null) {
+            throw new RuntimeException("fake storage にオブジェクトがありません: {$path}");
+        }
+
+        $this->copyStreamToLocalFile($stream, $localPath, $path);
+    }
+
+    /** sidecar (content_type) を必ず書く = fake の GET 配信 contract を満たす */
+    public function upload(string $localPath, string $path, string $contentType): void
+    {
+        $stream = fopen($localPath, 'rb');
+        if ($stream === false) {
+            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
+        }
+
+        try {
+            $this->store->putStreamWithMeta($path, $stream, $contentType);
+        } finally {
+            if (is_resource($stream)) {
+                fclose($stream);
+            }
+        }
+    }
+
+    public function temporaryThumbnailUrl(string $path): string
+    {
+        return URL::temporarySignedRoute(
+            'bughunt.storage.get',
+            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
+            ['key' => $path],
+        );
+    }
+
     public function delete(string $path): void
     {
         $this->store->delete($path);
diff --git a/app/Services/Capture/FfmpegTakeThumbnailExtractor.php b/app/Services/Capture/FfmpegTakeThumbnailExtractor.php
new file mode 100644
index 0000000..1e7bcdd
--- /dev/null
+++ b/app/Services/Capture/FfmpegTakeThumbnailExtractor.php
@@ -0,0 +1,84 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Capture;
+
+use App\Exceptions\Capture\TakeThumbnailExtractionException;
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+
+/**
+ * ffmpeg による 1 フレーム抽出 (実行は Process facade 経由。テストは Process::fake())。
+ *
+ * 安全境界 (入力は**利用者がアップロードした動画**である):
+ * - 引数は配列で渡す (シェル連結なし)。入力・出力ともサーバ生成のパスだけで、
+ *   利用者由来の文字列は 1 つも引数に入らない
+ * - `-nostdin` で標準入力待ちに落ちない / `Process::timeout()` で実行を有界にする
+ * - **`-protocol_whitelist file`** を明示し、細工されたファイルが外部参照を含む形式として
+ *   probe された場合でもローカルファイル以外へ到達しないようにする
+ *   (**観測事実**: 既存の `Render\FfmpegVideoComposer` はこの指定を持たない。入力の素性は同じだが、
+ *   新設する側を弱い方へ揃える理由はないため本実装には付ける。既存側の後追いは別タスク)
+ * - 出力寸法・品質は config 固定 = 巨大入力から巨大 JPEG を作らない。
+ *   同一入力・同一バイナリなら出力は決定的である (容量計上の前提)
+ */
+final class FfmpegTakeThumbnailExtractor implements TakeThumbnailExtractor
+{
+    public function extract(string $localVideoPath, string $localThumbnailPath): void
+    {
+        $seekMs = config()->integer('capture.thumbnail_seek_ms');
+
+        $failure = $this->attempt($localVideoPath, $localThumbnailPath, $seekMs);
+        if ($failure !== null && $seekMs > 0) {
+            // 尺が seek より短いと 1 フレームも出力されない。先頭で 1 回だけ再試行する
+            // (これ以上の探索はしない = 尺の推定に ffprobe を足さない)
+            $failure = $this->attempt($localVideoPath, $localThumbnailPath, 0);
+        }
+        if ($failure !== null) {
+            throw new TakeThumbnailExtractionException($failure);
+        }
+    }
+
+    /** @return string|null 失敗理由 (null = 成功) */
+    private function attempt(string $source, string $destination, int $seekMs): ?string
+    {
+        // ★ 実行の**前**に出力先を消す。`-y` は「既存があれば上書きしてよい」という許可であって、
+        //   ffmpeg が必ず書き直すことの保証ではない。1 回目が非 0 終了しつつ非空ファイルを残し、
+        //   2 回目が終了コード 0 のまま新しいフレームを出さない場合、下の実体検査が
+        //   **1 回目の残骸を成功と誤認する**。削除できないこと自体も失敗として扱う。
+        // ★ 素の `unlink()` を使わない — 失敗時に E_WARNING を出し、Laravel のエラーハンドラが
+        //   `ErrorException` へ変換する環境では下の `return` へ到達せず、
+        //   `TakeThumbnailExtractionException` への集約という契約から外れる。
+        //   `File::delete()` なら、判定が**戻り値だけで閉じる**。
+        if (File::isFile($destination) && ! File::delete($destination)) {
+            return "failed to remove stale thumbnail output: {$destination}";
+        }
+
+        $edge = config()->integer('capture.thumbnail_max_edge');
+        $result = Process::timeout(config()->integer('capture.thumbnail_ffmpeg_timeout_seconds'))
+            ->run([
+                config()->string('manual.render_ffmpeg_binary'),
+                '-nostdin', '-y',
+                '-protocol_whitelist', 'file',
+                '-ss', sprintf('%.3f', $seekMs / 1000),
+                '-i', $source,
+                '-frames:v', '1',
+                '-vf', "scale={$edge}:{$edge}:force_original_aspect_ratio=decrease",
+                '-q:v', (string) config()->integer('capture.thumbnail_jpeg_quality'),
+                '-f', 'image2',
+                $destination,
+            ]);
+
+        if (! $result->successful()) {
+            return 'ffmpeg failed (thumbnail): '.mb_substr($result->errorOutput(), 0, 2000);
+        }
+
+        // 非 0 終了しないまま 0 バイトを吐く場合がある (seek が尺を超えたとき) ため実体を検査する
+        $size = File::exists($destination) ? File::size($destination) : 0;
+        if ($size === 0) {
+            return "ffmpeg produced no frame (seek={$seekMs}ms)";
+        }
+
+        return null;
+    }
+}
diff --git a/app/Services/Capture/StorageUsageService.php b/app/Services/Capture/StorageUsageService.php
index 5068302..2a643e9 100644
--- a/app/Services/Capture/StorageUsageService.php
+++ b/app/Services/Capture/StorageUsageService.php
@@ -9,6 +9,7 @@
 use App\Models\Take;
 use App\Models\TakeUploadReservation;
 use Illuminate\Contracts\Database\Eloquent\Builder;
+use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
 
 /**
  * 容量 Quota の使用量集計 (doc/10 §10.8-4 の真実源)。
@@ -37,16 +38,41 @@ public function occupiedBytes(Organization $organization): int
         return $used > PHP_INT_MAX - $pending ? PHP_INT_MAX : $used + $pending;
     }
 
-    /** takes.size_bytes の org 合計 (takes→cuts→video_manuals→projects→custom_teams join) */
+    /**
+     * 動画本文 + サムネイルの org 合計 (takes→cuts→video_manuals→projects→custom_teams join)。
+     *
+     * ★ **サムネイルは予約 (take_upload_reservations) を経ない事後計上**である。
+     *   上限の強制点は presigned URL 発行時 (TakeUploadService::issue) のままで、
+     *   サムネイル生成が上限を跨ぐことはありうる (受容。超過表示は QuotaStatusDto が既に持つ)。
+     * ★ 合成を SQL 式でなく PHP 側で行うのは、`(int) …->sum('列名')` という
+     *   **既に PHPStan level 10 を通っている形**から外れないため
+     *   (生の式を sum() へ渡す形は新しい型の不確実性を持ち込む)。
+     * ★ overflow は occupiedBytes() と同じく上限側へ丸める。
+     */
     public function bytesUsed(Organization $organization): int
     {
-        return (int) Take::query()
+        $video = (int) $this->takesForOrganization($organization)->sum('takes.size_bytes');
+        $thumbnails = (int) $this->takesForOrganization($organization)->sum('takes.thumbnail_size_bytes');
+
+        return $video > PHP_INT_MAX - $thumbnails ? PHP_INT_MAX : $video + $thumbnails;
+    }
+
+    /**
+     * org 配下の takes を引く builder。
+     *
+     * ★ **呼び出しごとに新しい Builder を返す** (同一インスタンスを 2 回の集計で使い回すと
+     *   1 本目の集計が builder を汚し、2 本目の結果が変わる)。
+     *
+     * @return EloquentBuilder<Take>
+     */
+    private function takesForOrganization(Organization $organization): EloquentBuilder
+    {
+        return Take::query()
             ->join('cuts', 'cuts.id', '=', 'takes.cut_id')
             ->join('video_manuals', 'video_manuals.id', '=', 'cuts.video_manual_id')
             ->join('projects', 'projects.id', '=', 'video_manuals.project_id')
             ->join('custom_teams', 'custom_teams.id', '=', 'projects.custom_team_id')
-            ->where('custom_teams.organization_id', $organization->id)
-            ->sum('takes.size_bytes');
+            ->where('custom_teams.organization_id', $organization->id);
     }
 
     /**
diff --git a/app/Services/Capture/TakeObjectStorage.php b/app/Services/Capture/TakeObjectStorage.php
index 44695f5..a3aea78 100644
--- a/app/Services/Capture/TakeObjectStorage.php
+++ b/app/Services/Capture/TakeObjectStorage.php
@@ -12,6 +12,7 @@
 use Carbon\CarbonImmutable;
 use Illuminate\Filesystem\AwsS3V3Adapter;
 use Illuminate\Support\Facades\Storage;
+use RuntimeException;
 use Webmozart\Assert\Assert;
 
 /**
@@ -100,6 +101,61 @@ public function temporaryPlaybackUrl(string $path): string
         );
     }
 
+    /**
+     * テイク動画本文をローカル一時ファイルへ取得する (サムネイル生成の入力)。
+     *
+     * 面分類は Bulk (本文転送 = 所要時間がサイズに比例する)。**web 同期経路から呼ばない** —
+     * 呼び出し元は media queue の GenerateTakeThumbnailJob だけである。
+     * 実装は RenderObjectStorage::downloadToLocal と同型 (readStream → ローカル書き込み)。
+     */
+    public function downloadToLocal(string $path, string $localPath): void
+    {
+        $stream = Storage::disk('s3')->readStream($path);
+        if ($stream === null) {
+            throw new RuntimeException("S3 オブジェクトを読めません: {$path}");
+        }
+
+        $this->copyStreamToLocalFile($stream, $localPath, $path);
+    }
+
+    /**
+     * サーバ生成物 (サムネイル) を S3 へ PUT する。
+     *
+     * ★ `ContentType` を必ず指定する。指定しないと S3 が既定の binary/octet-stream を返し、
+     *   署名 GET へリダイレクトした先で `<img>` が描画できない
+     *   (`ContentType` は Flysystem AwsS3V3Adapter の受理オプションに含まれる)。
+     * 面分類は Bulk (本文転送)。**web 同期経路から呼ばない**。
+     */
+    public function upload(string $localPath, string $path, string $contentType): void
+    {
+        $stream = fopen($localPath, 'rb');
+        if ($stream === false) {
+            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
+        }
+
+        try {
+            Storage::disk('s3')->writeStream($path, $stream, ['ContentType' => $contentType]);
+        } finally {
+            if (is_resource($stream)) {
+                fclose($stream);
+            }
+        }
+    }
+
+    /**
+     * サムネイル表示用の署名 GET URL (TTL は動画再生と同じ capture.playback_url_ttl_minutes)。
+     *
+     * ★ `temporaryPlaybackUrl()` を流用しない。中身は同じ署名 URL 生成だが、
+     *   "playback" (再生) の語を静止画へ広げると public API の名前が実体と食い違う。
+     */
+    public function temporaryThumbnailUrl(string $path): string
+    {
+        return Storage::disk('s3')->temporaryUrl(
+            $path,
+            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
+        );
+    }
+
     /** オブジェクト削除 (存在しないキーは no-op = 冪等) */
     public function delete(string $path): void
     {
@@ -112,6 +168,35 @@ public function exists(string $path): bool
         return Storage::disk('s3')->exists($path);
     }
 
+    /**
+     * 読み出しストリームをローカルファイルへ写す (downloadToLocal の共通部)。
+     * fake も同じ処理を使うため protected に置く (面分類の対象は public メソッドのみ)。
+     *
+     * @param  resource  $stream
+     */
+    protected function copyStreamToLocalFile(mixed $stream, string $localPath, string $sourcePath): void
+    {
+        $local = fopen($localPath, 'wb');
+        if ($local === false) {
+            if (is_resource($stream)) {
+                fclose($stream);
+            }
+
+            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
+        }
+
+        try {
+            if (stream_copy_to_stream($stream, $local) === false) {
+                throw new RuntimeException("S3 オブジェクトのコピーに失敗しました: {$sourcePath}");
+            }
+        } finally {
+            fclose($local);
+            if (is_resource($stream)) {
+                fclose($stream);
+            }
+        }
+    }
+
     /**
      * s3 disk の S3Client (テストでは本メソッドを override して MockHandler client を注入する)。
      */
diff --git a/app/Services/Capture/TakeRegistrationService.php b/app/Services/Capture/TakeRegistrationService.php
index d53d5b3..e1db63a 100644
--- a/app/Services/Capture/TakeRegistrationService.php
+++ b/app/Services/Capture/TakeRegistrationService.php
@@ -11,6 +11,7 @@
 use App\Enums\Capture\TakeUploadReservationStatus;
 use App\Enums\Manual\TakeStatus;
 use App\Exceptions\Capture\CaptureConflictException;
+use App\Jobs\Capture\GenerateTakeThumbnailJob;
 use App\Models\Cut;
 use App\Models\Project;
 use App\Models\Take;
@@ -31,6 +32,7 @@
  * 4. 予約 claim (pending → verifying の原子的 UPDATE。cron と競合しない)
  * 5. HeadObject 三点照合 (size / content_type / ChecksumSHA256)
  * 6. tx: VideoManual 行ロック → sibling shift + Take insert (先頭) + 予約 completed (CAS)
+ *    + サムネイル生成ジョブの投入 (同一 tx 内。ドメイン固有規約 11)
  */
 class TakeRegistrationService
 {
@@ -168,6 +170,12 @@ private function finalize(Project $project, VideoManual $manual, Cut $cut, TakeR
             ]);
             $take->forceFill(['status' => TakeStatus::Ready, 'sort_order' => 0])->save();
 
+            // サムネイル生成の投入を**同一 tx 内**で行う (AGENTS.md ドメイン固有規約 11。
+            // afterCommit に依存しない)。保証するのは「take 行を作ったのに生成 job が投入されない窓」の
+            // 解消だけである (worker 停止 / ffmpeg 失敗 / S3 失敗ではサムネイルは付かない = 誇張しない)。
+            // 冪等再送 (resolveDuplicate) では投入しない — 既存テイクは登録時に投入済みである。
+            GenerateTakeThumbnailJob::dispatch($take->id); // media queue へ
+
             return TakeRegistrationResult::created($take);
         });
     }
diff --git a/app/Services/Capture/TakeThumbnailExtractor.php b/app/Services/Capture/TakeThumbnailExtractor.php
new file mode 100644
index 0000000..23bfe6c
--- /dev/null
+++ b/app/Services/Capture/TakeThumbnailExtractor.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Capture;
+
+use App\Exceptions\Capture\TakeThumbnailExtractionException;
+
+/**
+ * テイク動画から表示用サムネイル (JPEG) を 1 枚作る抽象 (v1 は ffmpeg 実装)。
+ *
+ * `Render\VideoComposer` と同じ作法で interface に切る = テストは実バイナリに依存せず
+ * container swap で fake を注入できる (AppServiceProvider が本番実装を bind する)。
+ */
+interface TakeThumbnailExtractor
+{
+    /**
+     * @param  string  $localVideoPath  ローカルへ落とした動画 (サーバ生成のパス)
+     * @param  string  $localThumbnailPath  出力先 (サーバ生成のパス)
+     *
+     * @throws TakeThumbnailExtractionException 抽出できなかった場合
+     */
+    public function extract(string $localVideoPath, string $localThumbnailPath): void;
+}
diff --git a/app/Services/Capture/TakeThumbnailPipeline.php b/app/Services/Capture/TakeThumbnailPipeline.php
new file mode 100644
index 0000000..ede7bdb
--- /dev/null
+++ b/app/Services/Capture/TakeThumbnailPipeline.php
@@ -0,0 +1,159 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Capture;
+
+use App\Enums\Manual\TakeStatus;
+use App\Enums\Security\ExternalCallKind;
+use App\Models\Cut;
+use App\Models\Take;
+use App\Models\VideoManual;
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Str;
+use Webmozart\Assert\Assert;
+
+/**
+ * サムネイル生成パイプライン (S3 GET → ffmpeg → S3 PUT → 条件付き UPDATE)。
+ *
+ * **結果の一回性** (AGENTS.md ドメイン固有規約 6):
+ * - 保証側の機構は**条件付き UPDATE** (`where status=ready and thumbnail_path is null`)。
+ *   0 行更新なら後続を行わない (= 先着したワーカーの結果を壊さない)
+ * - 取り消せない外部副作用 (S3 PUT) の**直前**に所有権の再検証 (`stillEligible`) を置く。
+ *   検証と PUT の間に自前の書き込みを挟まない
+ * - S3 キーは take の主キーから**決定的**に組む。重複配送された 2 つのワーカーは
+ *   同じキーへ同じ意味の PUT をするだけなので、敗者が勝者のオブジェクトを消す事故が起きない
+ *   (= 0 行更新のとき**オブジェクトを削除してはならない**)
+ * - work dir は **run() 実行ごとに一意** (take id + 実行ごとの UUID)。
+ *   ローカル作業領域まで決定的にすると、重複配送された 2 つのワーカーが互いの入力・出力を壊す
+ *
+ * **ロック**: VideoManual 行ロックは取らない。本パイプラインは `cuts` /
+ * `video_manuals.status` / `scenario_version` を 1 列も書かず (ドメイン固有規約 1 の対象外)、
+ * 単一行の条件付き UPDATE で足りる。バックグラウンドジョブが新しいロック順序の辺を作らない。
+ */
+class TakeThumbnailPipeline
+{
+    public function __construct(
+        private readonly TakeObjectStorage $storage,
+        private readonly TakeThumbnailExtractor $extractor,
+    ) {}
+
+    public function run(int $takeId): void
+    {
+        $take = Take::query()->find($takeId);
+        if ($take === null || ! $this->isEligible($take)) {
+            return; // 行が消えた / 既に生成済み / ready でない = 正常な no-op (再配送の冪等短絡)
+        }
+
+        // ★ 実行ごとに一意な作業領域。take id だけで決定的にすると重複配送で互いを壊す
+        $workDir = storage_path("app/capture/thumbnails/{$takeId}/".(string) Str::uuid());
+        File::ensureDirectoryExists($workDir);
+
+        try {
+            $source = "{$workDir}/source";
+            $thumbnail = "{$workDir}/thumbnail.jpg";
+
+            // S3 GET は冪等な読み取り / ffmpeg はローカル CPU = どちらも preflight の対象ではない
+            $this->storage->downloadToLocal($take->video_path, $source);
+            $this->extractor->extract($source, $thumbnail);
+
+            $size = File::isFile($thumbnail) ? File::size($thumbnail) : 0;
+            if ($size === 0) {
+                return; // extract が成功を返した以上ここには来ない (防御的)
+            }
+
+            // ★ S3 キーは preflight の**前**に確定させる。key が使うのは take / cut / manual /
+            //   project の識別子だけ (= 行の生存中に変化しない不変値) なので、preflight より前に
+            //   組んでも値は変わらない。こうすることで **preflight と PUT の間には
+            //   書き込みどころか読み取り (relation の遅延読み込み) も 1 つも無い**状態になる
+            $key = $this->thumbnailKeyFor($take);
+
+            // ★ preflight (裁定 AG-082 標準形 (2)): 取り消せない S3 PUT の直前で所有権を再検証する。
+            //   ここから PUT までの間に自前の書き込みを挟まない
+            if (! $this->stillEligible($take)) {
+                return;
+            }
+
+            $this->storage->upload($thumbnail, $key, 'image/jpeg');
+
+            // 結果の一回性: preflight と同じ述語を条件へ再掲する。
+            // 0 行 = 先着したワーカーか状態変化 → 何もしない (**オブジェクトは消さない**。
+            // キーが決定的なので、消すと勝者の実体を壊すことになる)
+            Take::query()
+                ->whereKey($take->getKey())
+                ->where('status', TakeStatus::Ready->value)
+                ->whereNull('thumbnail_path')
+                ->update([
+                    'thumbnail_path' => $key,
+                    'thumbnail_size_bytes' => $size,
+                ]);
+        } finally {
+            File::deleteDirectory($workDir); // 自分の作業領域だけを消す (他人のものには触れない)
+        }
+    }
+
+    /**
+     * S3 キー (take の主キーから決定的に組む。文字列加工を一切しない)。
+     * cut / manual は relation 経由で解決する (payload 不信任)。
+     *
+     * ★ 材料は**すべて行の生存中に変化しない識別子**である (take が別の cut へ移る経路も、
+     *   cut が別の manual へ移る経路も存在しない)。したがって preflight の前に確定させても、
+     *   再取得後のスナップショットで組んだ場合と同じ値になる。
+     */
+    private function thumbnailKeyFor(Take $take): string
+    {
+        // relation は nullable 型を返す (外部キーは非 null だが型では表せない)。
+        // 欠けていたら整合性異常なので fail-loud にする (キーを黙って崩さない)。
+        $cut = $take->cut;
+        Assert::isInstanceOf($cut, Cut::class, 'テイクの所属カットを解決できません');
+        $manual = $cut->videoManual;
+        Assert::isInstanceOf($manual, VideoManual::class, 'カットの所属マニュアルを解決できません');
+
+        return sprintf(
+            'projects/%d/manuals/%d/cuts/%d/takes/thumbnails/%d.jpg',
+            $manual->project_id,
+            $manual->id,
+            $cut->id,
+            $take->id,
+        );
+    }
+
+    /** 生成してよい状態か (ready かつ未生成)。純粋な述語 = 再検証と入口検査で同じ式を使う */
+    private function isEligible(Take $take): bool
+    {
+        return $take->status === TakeStatus::Ready && $take->thumbnail_path === null;
+    }
+
+    /**
+     * 所有権の再検証 (preflight suppression)。Billing の `AttemptOwnershipPreflight::stillPending()`
+     * と**同じ制御方式** (structured return = bool)。Manual の 2 パイプラインが使う
+     * `JobOwnershipLostException` は「ジョブ行の JobStatus」を語彙に持つため、
+     * ジョブ行を持たない本経路では流用しない (別物の概念を似ているからで統合しない)。
+     *
+     * @return bool PUT してよいか (false = 所有権喪失 → 呼び出し側が中断する)
+     */
+    private function stillEligible(Take $take): bool
+    {
+        // $take は型付き引数 (App\Models\Take) = 解決済みモデル由来の主キー
+        $fresh = Take::query()->whereKey($take->getKey())->first();
+        if ($fresh !== null && $this->isEligible($fresh)) {
+            return true; // アーリーリターン (正常系)
+        }
+
+        // Manual / Billing と**同じ必須 7 キー**で観測する (集計語彙を 1 本に保つ)。
+        // 本経路固有の追加キーは PII-free な thumbnail_present の 1 本だけ
+        Log::warning('サムネイル生成: 所有権を失ったため S3 への書き込みを中止しました', [
+            'event' => ExternalCallKind::LOG_EVENT,
+            'job_type' => Take::class,
+            'job_id' => $take->id,
+            'expected_status' => TakeStatus::Ready->value,
+            'actual_status' => $fresh?->status->value,
+            'stage' => 'thumbnail_upload',
+            'external_call' => ExternalCallKind::ObjectStoragePut->value,
+            'thumbnail_present' => $fresh?->thumbnail_path !== null,
+        ]);
+
+        return false;
+    }
+}
diff --git a/config/capture.php b/config/capture.php
index a17e42e..f3b64ae 100644
--- a/config/capture.php
+++ b/config/capture.php
@@ -31,4 +31,14 @@
     // verifying 予約を stale とみなす閾値 (登録リクエストの異常終了の回収。概念設計 D7)
     'stale_verifying_minutes' => 15,
 
+    // サムネイル生成 (テイク登録後に media queue の GenerateTakeThumbnailJob が 1 フレーム抽出する)
+    // 抽出位置。0 だと黒画面になりやすいので既定 1 秒。尺が足りなければ実装が 0 で 1 回だけ再試行する
+    'thumbnail_seek_ms' => 1000,
+    // 出力の長辺上限 (両辺に効く。巨大入力から巨大 JPEG を作らない)
+    'thumbnail_max_edge' => 640,
+    // JPEG 品質 (ffmpeg -q:v。小さいほど高品質・大きいほど低容量)
+    'thumbnail_jpeg_quality' => 5,
+    // ffmpeg 1 回の実行上限 (秒)。ジョブの $timeout=180 より十分短く取る
+    'thumbnail_ffmpeg_timeout_seconds' => 60,
+
 ];
diff --git a/database/factories/TakeFactory.php b/database/factories/TakeFactory.php
index 593a925..94fa146 100644
--- a/database/factories/TakeFactory.php
+++ b/database/factories/TakeFactory.php
@@ -28,6 +28,7 @@ public function definition(): array
             'client_take_id' => (string) Str::ulid(),
             'video_path' => 'takes/'.fake()->uuid().'.mp4',
             'thumbnail_path' => null,
+            'thumbnail_size_bytes' => null,
             'size_bytes' => fake()->numberBetween(100_000, 50_000_000),
             'duration_ms' => fake()->numberBetween(1_000, 60_000),
             'status' => TakeStatus::Ready->value,
@@ -43,6 +44,15 @@ public function forCut(Cut $cut): static
         return $this->state(fn () => ['cut_id' => $cut->id]);
     }
 
+    /** サムネイル生成済み (容量集計・一覧表示のテスト用) */
+    public function withThumbnail(int $sizeBytes = 40_000): static
+    {
+        return $this->state(fn (): array => [
+            'thumbnail_path' => 'takes/thumbnails/'.fake()->uuid().'.jpg',
+            'thumbnail_size_bytes' => $sizeBytes,
+        ]);
+    }
+
     /** DL 済み ACK 打刻済み (削除不可) の状態 (概念設計 D6) */
     public function downloaded(): static
     {
diff --git a/database/migrations/2026_08_16_000100_add_thumbnail_size_bytes_to_takes_table.php b/database/migrations/2026_08_16_000100_add_thumbnail_size_bytes_to_takes_table.php
new file mode 100644
index 0000000..8be8a16
--- /dev/null
+++ b/database/migrations/2026_08_16_000100_add_thumbnail_size_bytes_to_takes_table.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * サムネイルの実バイト数 (容量 Quota の集計対象)。
+     *
+     * - `takes.size_bytes` とは**別列**にする。size_bytes は
+     *   「予約 (take_upload_reservations.size_bytes) と HeadObject の ContentLength が
+     *   三点照合で一致した確定値」であり、その同一性が
+     *   StorageUsageService::occupiedBytes() の pending→used 読み取り順の根拠になっている。
+     *   事後に生成されるサムネイル分を足し込むとその根拠が読めなくなる。
+     * - 生成前 / 生成失敗のテイクは NULL (= 0 として集計する)。既存行も NULL のままでよい。
+     * - integer で足りる (出力は config で寸法・品質を固定した JPEG 1 枚)。
+     */
+    public function up(): void
+    {
+        Schema::table('takes', function (Blueprint $table): void {
+            $table->integer('thumbnail_size_bytes')->nullable()->after('thumbnail_path');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('takes', function (Blueprint $table): void {
+            $table->dropColumn('thumbnail_size_bytes');
+        });
+    }
+};
diff --git a/resources/js/components/features/capture/TakeStrip.svelte b/resources/js/components/features/capture/TakeStrip.svelte
index 108f7d7..df684cc 100644
--- a/resources/js/components/features/capture/TakeStrip.svelte
+++ b/resources/js/components/features/capture/TakeStrip.svelte
@@ -1,5 +1,5 @@
 <script lang="ts">
-    import { Check, ChevronDown, ChevronUp, Download, Pencil, Play, Trash2 } from "@lucide/svelte";
+    import { Check, ChevronDown, ChevronUp, Download, Film, Pencil, Play, Trash2 } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import TakeCommentDialog from "@/components/features/capture/TakeCommentDialog.svelte";
@@ -196,6 +196,31 @@
                 : ''}"
             data-testid={`take-item-${take.id}`}
         >
+            <!--
+              サムネイル (doc/04 動画列 / doc/05 撮影後の確認)。
+              生成は非同期なので、録画直後・生成失敗・過去分のテイクは has_thumbnail=false になる。
+              その場合は同じ寸法のプレースホルダを出し、枠の大きさを変えない
+              (生成完了後の再取得で同じ枠が画像へ置き換わる = レイアウトが跳ねない)。
+              画像は装飾 (行に「テイク N」の見出しが既にある) なので alt="" にする。
+            -->
+            {#if take.has_thumbnail}
+                <img
+                    src={takeUrl(take, "/thumbnail")}
+                    alt=""
+                    loading="lazy"
+                    decoding="async"
+                    class="size-12 shrink-0 rounded-md border border-border object-cover"
+                    data-testid={`take-thumbnail-${take.id}`}
+                />
+            {:else}
+                <div
+                    class="flex size-12 shrink-0 items-center justify-center rounded-md border border-border bg-neutral"
+                    data-testid={`take-thumbnail-placeholder-${take.id}`}
+                    aria-hidden="true"
+                >
+                    <Film class="size-4 text-text-secondary" aria-hidden="true" />
+                </div>
+            {/if}
             <div class="flex shrink-0 flex-col gap-1">
                 <Button
                     variant="ghost"
diff --git a/resources/js/lib/capture/thumbnail-refresh.ts b/resources/js/lib/capture/thumbnail-refresh.ts
new file mode 100644
index 0000000..e173d4e
--- /dev/null
+++ b/resources/js/lib/capture/thumbnail-refresh.ts
@@ -0,0 +1,112 @@
+import type { CaptureManualDetail } from "@/types/capture";
+
+/**
+ * サムネイル生成の完了を撮影画面へ反映するための**有界な**再取得スケジューラ。
+ *
+ * サムネイルはテイク登録の応答より後に出来るため、登録直後の has_thumbnail は必ず false になる。
+ * 放置すると画面を離れて戻るまで反映されない (doc/05 の撮影後確認が成立しない)。
+ *
+ * 設計上の制約 (無制限ポーリングにしない):
+ * - **監視するのはこの端末がこのセッションで登録した client_take_id だけ**。
+ *   画面差分で現れた ID は追わない = 別端末が同じマニュアルを撮っていても巻き込まれず、
+ *   **サムネイルを持たない過去分のテイクで再取得が走ることもない**
+ * - 停止条件は 4 つ: 監視集合が空 / 試行上限 / 画面が非表示 / stop()
+ * - 再取得中は次の再取得を始めない (single-flight。呼び出し側の reload も同じ 1 本を通す)
+ *
+ * **有界性の単位 (誇張しない)**: 試行予算は集合全体で 1 本持ち、**新しいテイクを watch した時点で
+ * リセットされる** (新しい録画には新しい予算を与えるのが意図)。したがって
+ * 「画面全体で最大 4 回」ではなく「**最後に監視集合へ追加されたテイクを起点に最大 4 回 (~29 秒)**」が
+ * 保証の単位である (既に監視中の ID を再度 watch しても予算は戻らない = 早期 return する。
+ * キュー再開で複数件を連続追加した場合は、**最後に追加された ID** を起点に集合全体の予算が更新される)。
+ * 撮影を続ける限り予算は更新され続けるが、撮影を止めれば必ず 4 回で停止する。
+ */
+const INTERVALS_MS = [2_000, 4_000, 8_000, 15_000] as const;
+
+export class ThumbnailRefreshScheduler {
+    private readonly watched = new Set<string>();
+    private attempt = 0;
+    private timer: ReturnType<typeof setTimeout> | null = null;
+    private running = false;
+    private paused = false;
+    private stopped = false;
+
+    /** @param reload 画面側の single-flight な再取得 (完了で解決する Promise を返す) */
+    constructor(private readonly reload: () => Promise<void>) {}
+
+    /** この端末が登録に成功したテイクを監視対象へ merge する (既存集合は消さない) */
+    watch(clientTakeId: string): void {
+        if (this.stopped || this.watched.has(clientTakeId)) return;
+        this.watched.add(clientTakeId);
+        this.attempt = 0; // 新しい録画には新しい試行予算を与える
+        // ★ 旧予算で予約済みの発火は持ち越さない。残しておくと「最後に watch した時点を起点に
+        //   最大 4 回」という保証の単位が崩れる (予約済みの 1 回ぶんだけ超える)。
+        this.clearTimer();
+        this.schedule();
+    }
+
+    /** 最新の manual で監視集合を更新する (完了後の最新スナップショットだけで判断する) */
+    sync(manual: CaptureManualDetail): void {
+        if (this.stopped) return;
+        for (const id of [...this.watched]) {
+            const take = manual.cuts
+                .flatMap((cut) => cut.takes)
+                .find((t) => t.client_take_id === id);
+            // 見つからない (削除された) / サムネイルが付いた → 監視終了
+            if (take === undefined || take.has_thumbnail) this.watched.delete(id);
+        }
+        if (this.watched.size === 0) {
+            this.clearTimer();
+            this.attempt = 0;
+            return;
+        }
+        this.schedule();
+    }
+
+    pause(): void {
+        this.paused = true;
+        this.clearTimer();
+    }
+
+    resume(): void {
+        this.paused = false;
+        this.schedule();
+    }
+
+    stop(): void {
+        this.stopped = true;
+        this.clearTimer();
+        this.watched.clear();
+    }
+
+    private schedule(): void {
+        if (this.stopped || this.paused || this.running || this.timer !== null) return;
+        if (this.watched.size === 0 || this.attempt >= INTERVALS_MS.length) return;
+
+        const delay = INTERVALS_MS[this.attempt];
+        this.attempt += 1;
+        this.timer = setTimeout(() => {
+            this.timer = null;
+            void this.run();
+        }, delay);
+    }
+
+    private async run(): Promise<void> {
+        if (this.stopped || this.paused) return;
+        this.running = true;
+        try {
+            await this.reload();
+        } catch {
+            // 失敗しても監視対象は消さない (残りの試行へ進む)
+        } finally {
+            this.running = false;
+        }
+        // 停止・unmount 後に到着した完了処理は状態を変更しない
+        if (!this.stopped) this.schedule();
+    }
+
+    private clearTimer(): void {
+        if (this.timer === null) return;
+        clearTimeout(this.timer);
+        this.timer = null;
+    }
+}
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 28a9f8b..43c40ed 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -23,8 +23,9 @@
         prefersReducedMotion,
     } from "@/lib/capture/panel-navigation";
     import { createIdbPendingStore } from "@/lib/capture/idb";
+    import { ThumbnailRefreshScheduler } from "@/lib/capture/thumbnail-refresh";
     import { generateClientTakeId, UploadQueue } from "@/lib/capture/upload-queue";
-    import type { PendingStore } from "@/lib/capture/upload-queue";
+    import type { PendingStore, UploadOutcome } from "@/lib/capture/upload-queue";
     import type { SharedProps } from "@/lib/shared-props";
     import type { CaptureManualDetail } from "@/types/capture";
 
@@ -86,10 +87,38 @@
         quotaMessage = queue.quotaMessage;
     }
 
-    function reloadManual(): void {
-        router.reload({ only: ["manual"] });
+    /* ---- manual 再取得は single-flight ----
+     * アップロード成功 / キュー再開 / 自動 DL / サムネイル反映の 4 経路が同じ 1 本を通る。
+     * 直列化しないと、古い応答での上書きと監視集合の判定ずれが起きる。 */
+    // ★ in-flight の Promise を**保持して返す**。即解決する Promise を返すと、
+    //   scheduler が「再取得が終わった」と誤認して古い manual のまま次の試行を消費する。
+    let inFlight: Promise<void> | null = null;
+    function reloadManual(): Promise<void> {
+        if (inFlight !== null) return inFlight; // 並行呼び出しには同じ Promise を返す
+        inFlight = new Promise<void>((resolve) => {
+            router.reload({
+                only: ["manual"],
+                // onFinish は成功・失敗・キャンセルのいずれでも呼ばれる契約に依存している
+                onFinish: () => {
+                    inFlight = null;
+                    resolve();
+                },
+            });
+        });
+
+        return inFlight;
     }
 
+    /* ---- サムネイル生成の有界な反映 (T183) ----
+     * この端末がこのセッションで登録したテイクだけを監視し、生成完了で画像へ差し替える。
+     * 停止条件・有界性の単位は lib/capture/thumbnail-refresh.ts の docblock が正本。 */
+    const thumbnails = new ThumbnailRefreshScheduler(reloadManual);
+
+    // reload 後の最新 manual だけで監視集合を更新する
+    $effect(() => {
+        thumbnails.sync(manual);
+    });
+
     /* ---- 撮影パネルへの視点/フォーカス移送 (F-1-03) ----
      * 1 カラム表示ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットをタップしても
      * 撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
@@ -155,7 +184,8 @@
                 capturedAt: new Date().toISOString(),
             });
             if (outcome.status === "uploaded") {
-                reloadManual();
+                thumbnails.watch(outcome.clientTakeId); // この端末が登録したテイクだけを監視する
+                void reloadManual();
             }
         } finally {
             uploading = false;
@@ -168,15 +198,25 @@
     // reload 後は downloaded=true で対象が空になるため再 DL は起きない (冪等)。
     async function runAutoDownload(): Promise<void> {
         const { changed } = await autoDownloader.run(manual);
-        if (changed) reloadManual();
+        if (changed) void reloadManual();
     }
 
     async function resumeUploads(): Promise<void> {
         uploading = true;
         try {
             const outcomes = await queue.resume();
-            if (outcomes.some((outcome) => outcome.status === "uploaded")) {
-                reloadManual();
+            // ★ キュー経由は**複数件**が一度に確定しうる。uploaded を 1 件も watch しないと、
+            //   最初の reload 時点で未生成だったテイクは以後まったく反映されない
+            //   (= オフライン撮影の主経路が取り残される)。
+            const uploaded = outcomes.filter(
+                (outcome): outcome is Extract<UploadOutcome, { status: "uploaded" }> =>
+                    outcome.status === "uploaded",
+            );
+            for (const outcome of uploaded) {
+                thumbnails.watch(outcome.clientTakeId);
+            }
+            if (uploaded.length > 0) {
+                void reloadManual(); // 件数によらず 1 回だけ (single-flight とも整合する)
             }
         } finally {
             uploading = false;
@@ -203,11 +243,18 @@
             if ("serviceWorker" in navigator) {
                 navigator.serviceWorker.removeEventListener("message", handleSwMessage);
             }
+            thumbnails.stop(); // unmount 後に再取得が走らないようにする
         };
     });
 
     function handleVisibility(): void {
-        if (document.visibilityState === "visible") void resumeUploads();
+        // 非表示の間は再取得を止める (停止条件の 1 つ)。復帰でキュー再開と一緒に再開する
+        if (document.visibilityState !== "visible") {
+            thumbnails.pause();
+            return;
+        }
+        thumbnails.resume();
+        void resumeUploads();
     }
 
     function handleOnline(): void {
diff --git a/resources/js/types/capture.ts b/resources/js/types/capture.ts
index 5a027b3..67f7db5 100644
--- a/resources/js/types/capture.ts
+++ b/resources/js/types/capture.ts
@@ -15,6 +15,8 @@ export interface CaptureTake {
     captured_at: string | null;
     sort_order: number;
     downloaded: boolean;
+    /** サムネイルが生成済みか。true のときだけ GET .../takes/{id}/thumbnail を表示に使う */
+    has_thumbnail: boolean;
     /** 採用テイクのみ非 null (doc/10 §10.3) */
     playback_url: string | null;
     /** 採用テイクのみ非 null。DL 完了時に POST .../downloaded へ送る署名 ACK トークン (D6) */
diff --git a/routes/web.php b/routes/web.php
index ebd567a..7bb4477 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -618,6 +618,8 @@
                     ->name('takes.downloaded');
                 Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback', [CaptureTakeController::class, 'playback'])
                     ->name('takes.playback');
+                Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail', [CaptureTakeController::class, 'thumbnail'])
+                    ->name('takes.thumbnail');
             });
         });
 });
diff --git a/tests/Architecture/JobExecutionDedupInventoryTest.php b/tests/Architecture/JobExecutionDedupInventoryTest.php
index 913fbc8..77196c7 100644
--- a/tests/Architecture/JobExecutionDedupInventoryTest.php
+++ b/tests/Architecture/JobExecutionDedupInventoryTest.php
@@ -12,6 +12,7 @@
 use App\Jobs\Billing\SetDefaultPaymentMethodJob;
 use App\Jobs\Billing\SyncBillingCustomerDetails;
 use App\Jobs\Capture\DeleteTakeObjectsJob;
+use App\Jobs\Capture\GenerateTakeThumbnailJob;
 use App\Jobs\Manual\DeleteRenderOutputsJob;
 use App\Jobs\Manual\RunManualAnalysis;
 use App\Jobs\Manual\RunManualRender;
@@ -24,6 +25,7 @@
 use App\Notifications\Billing\AutoRechargeFailedNotification;
 use App\Notifications\Billing\PaymentFailedNotification;
 use App\Notifications\Billing\RenewalReminderNotification;
+use App\Services\Capture\TakeThumbnailPipeline;
 use App\Services\Manual\AnalysisPipeline;
 use App\Services\Manual\RenderPipeline;
 use App\Support\JobExecution\AttemptOwnershipPreflight;
@@ -84,6 +86,17 @@ function jobDedupGuarantees(): array
             rationale: 'startJob / finalize が AnalysisPipeline と同型。S3 PUT は取り消せない'
                 .'外部副作用なので、updateProgress の後・upload の直前に preflight を置く。',
         ),
+        GenerateTakeThumbnailJob::class => new GuaranteeEntry(
+            mechanisms: [JobDedupGuarantee::ConditionalStatusUpdate],
+            preflights: [new PreflightCheckpoint(
+                TakeThumbnailPipeline::class, 'stillEligible',
+                ExternalCallKind::ObjectStoragePut, PreflightControlFlow::ReturnsBoolean,
+            )],
+            rationale: '結果の一回性は where status=ready and thumbnail_path is null の条件付き UPDATE が担う '
+                .'(0 行更新なら先着の結果を壊さない)。S3 キーは take の主キーから決定的に組むため、'
+                .'重複配送は同じキーへ同じ意味の PUT に収束し、敗者が勝者の実体を消すこともない。'
+                .'取り消せない S3 PUT の直前に structured return の preflight を置く。',
+        ),
         ExecuteAutoRechargeAttemptJob::class => new GuaranteeEntry(
             // ★軸の違う 2 本の保証を**両方**登録する
             mechanisms: [
@@ -153,6 +166,7 @@ function jobDedupRequiredExternalCalls(): array
     return [
         RunManualAnalysis::class => [ExternalCallKind::LlmCompletion],
         RunManualRender::class => [ExternalCallKind::ObjectStoragePut],
+        GenerateTakeThumbnailJob::class => [ExternalCallKind::ObjectStoragePut],
         ExecuteAutoRechargeAttemptJob::class => [
             ExternalCallKind::StripeInvoiceCreate,
             ExternalCallKind::StripeInvoicePay,
diff --git a/tests/Architecture/QueuedJobLeaseInventoryTest.php b/tests/Architecture/QueuedJobLeaseInventoryTest.php
index d197882..eef1669 100644
--- a/tests/Architecture/QueuedJobLeaseInventoryTest.php
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -9,6 +9,7 @@
 use App\Jobs\Billing\SetDefaultPaymentMethodJob;
 use App\Jobs\Billing\SyncBillingCustomerDetails;
 use App\Jobs\Capture\DeleteTakeObjectsJob;
+use App\Jobs\Capture\GenerateTakeThumbnailJob;
 use App\Jobs\Manual\DeleteRenderOutputsJob;
 use App\Jobs\Manual\RunManualAnalysis;
 use App\Jobs\Manual\RunManualRender;
@@ -65,6 +66,7 @@
     SetDefaultPaymentMethodJob::class => null,
     SyncBillingCustomerDetails::class => null,
     DeleteTakeObjectsJob::class => 'database-media',
+    GenerateTakeThumbnailJob::class => 'database-media',
     DeleteRenderOutputsJob::class => 'database-media',
     RunManualAnalysis::class => 'database-analysis',
     RunManualRender::class => 'database-render',
diff --git a/tests/Feature/Capture/CaptureManualBrowsingTest.php b/tests/Feature/Capture/CaptureManualBrowsingTest.php
index 0132fc0..f4b4f3b 100644
--- a/tests/Feature/Capture/CaptureManualBrowsingTest.php
+++ b/tests/Feature/Capture/CaptureManualBrowsingTest.php
@@ -184,7 +184,8 @@ function browsingContext(): array
     $take = $response->inertiaPage()['props']['manual']['cuts'][0]['takes'][0];
     expect(array_keys($take))->toBe([
         'id', 'client_take_id', 'status', 'size_bytes', 'duration_ms', 'comment',
-        'captured_at', 'sort_order', 'downloaded', 'playback_url', 'download_ack_token',
+        'captured_at', 'sort_order', 'downloaded', 'has_thumbnail', 'playback_url',
+        'download_ack_token',
     ]);
     $cutShape = $response->inertiaPage()['props']['manual']['cuts'][0];
     expect(array_keys($cutShape))->toBe([
@@ -222,3 +223,33 @@ function browsingContext(): array
 
     $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}")->assertOk();
 });
+
+/*
+|--------------------------------------------------------------------------
+| has_thumbnail (T183 / S8)
+|--------------------------------------------------------------------------
+|
+| props の述語は **GET .../thumbnail が 302 を返す条件と 1 対 1** である
+| (ready でないテイクで true を返すと、必ず 404 になる <img> を描画してしまう)。
+*/
+
+test('has_thumbnail は「ready かつ生成済み」のときだけ true になる', function (): void {
+    [, $owner, $project] = browsingContext();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $generated = Take::factory()->forCut($cut)->withThumbnail()->create(['sort_order' => 0]);
+    $pending = Take::factory()->forCut($cut)->create(['sort_order' => 1]);
+    // 生成済みだが ready ではない = endpoint は 404 を返すので false でなければならない
+    $notReady = Take::factory()->forCut($cut)->withThumbnail()->create([
+        'status' => 'processing',
+        'sort_order' => 2,
+    ]);
+
+    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");
+    $takes = collect($response->inertiaPage()['props']['manual']['cuts'][0]['takes'])
+        ->keyBy('id');
+
+    expect($takes[$generated->id]['has_thumbnail'])->toBeTrue();
+    expect($takes[$pending->id]['has_thumbnail'])->toBeFalse();
+    expect($takes[$notReady->id]['has_thumbnail'])->toBeFalse();
+});
diff --git a/tests/Feature/Capture/StorageUsageServiceTest.php b/tests/Feature/Capture/StorageUsageServiceTest.php
index 6e5543d..9edd20e 100644
--- a/tests/Feature/Capture/StorageUsageServiceTest.php
+++ b/tests/Feature/Capture/StorageUsageServiceTest.php
@@ -105,3 +105,54 @@ public function bytesUsed(Organization $organization): int
 
     expect($service->readOrder)->toBe(['pending', 'used']);
 });
+
+/*
+|--------------------------------------------------------------------------
+| サムネイル容量の事後計上 (T183 / S1)
+|--------------------------------------------------------------------------
+|
+| サムネイルは予約 (take_upload_reservations) を経ない事後計上であり、
+| bytes_used 側にだけ現れる (bytes_pending には対応物を持たない)。
+*/
+
+test('bytesUsed は thumbnail_size_bytes を加算する', function (): void {
+    [$organization, , , , $cut] = storageUsageContext();
+    Take::factory()->forCut($cut)->withThumbnail(40_000)->create(['size_bytes' => 1_000]);
+
+    expect(app(StorageUsageService::class)->bytesUsed($organization))->toBe(41_000);
+});
+
+test('thumbnail_size_bytes が NULL のテイクは 0 として数える (既存行の回帰)', function (): void {
+    [$organization, , , , $cut] = storageUsageContext();
+    $take = Take::factory()->forCut($cut)->create(['size_bytes' => 1_000]);
+    expect($take->thumbnail_size_bytes)->toBeNull();
+
+    expect(app(StorageUsageService::class)->bytesUsed($organization))->toBe(1_000);
+});
+
+test('bytesUsed を 2 回呼んでも同じ値になる (集計ごとに独立した builder を使う)', function (): void {
+    [$organization, , , , $cut] = storageUsageContext();
+    Take::factory()->forCut($cut)->withThumbnail(2_000)->create(['size_bytes' => 5_000]);
+
+    $service = app(StorageUsageService::class);
+    expect($service->bytesUsed($organization))->toBe(7_000);
+    expect($service->bytesUsed($organization))->toBe(7_000);
+});
+
+test('他組織のサムネイルは加算されない (join 条件の回帰)', function (): void {
+    [$organization, , , , $cut] = storageUsageContext();
+    Take::factory()->forCut($cut)->withThumbnail(1_000)->create(['size_bytes' => 2_000]);
+
+    [, , , , $otherCut] = storageUsageContext();
+    Take::factory()->forCut($otherCut)->withThumbnail(500_000)->create(['size_bytes' => 900_000]);
+
+    expect(app(StorageUsageService::class)->bytesUsed($organization))->toBe(3_000);
+});
+
+test('occupiedBytes はサムネイル分を含んだ bytes_used と bytes_pending の和になる', function (): void {
+    [$organization, , , , $cut] = storageUsageContext();
+    Take::factory()->forCut($cut)->withThumbnail(300)->create(['size_bytes' => 1_000]);
+    TakeUploadReservation::factory()->forCut($cut)->create(['size_bytes' => 700]);
+
+    expect(app(StorageUsageService::class)->occupiedBytes($organization))->toBe(2_000);
+});
diff --git a/tests/Feature/Capture/TakeObjectStorageTest.php b/tests/Feature/Capture/TakeObjectStorageTest.php
index b5b549a..22d9918 100644
--- a/tests/Feature/Capture/TakeObjectStorageTest.php
+++ b/tests/Feature/Capture/TakeObjectStorageTest.php
@@ -194,6 +194,57 @@ protected function client(): S3Client
     expect($url)->toContain('X-Amz-Signature=');
 });
 
+test('temporaryThumbnailUrl は config TTL の署名 GET URL を返す (playback と同じ TTL)', function (): void {
+    fakeS3DiskConfig();
+
+    $key = 'projects/1/manuals/2/cuts/3/takes/thumbnails/9.jpg';
+    $url = app(TakeObjectStorage::class)->temporaryThumbnailUrl($key);
+
+    expect($url)->toContain($key);
+    expect($url)->toContain('X-Amz-Signature=');
+    // TTL は動画再生と同じ config キーから引く (2 つの TTL を持たない)
+    expect($url)->toContain('X-Amz-Expires='.(config()->integer('capture.playback_url_ttl_minutes') * 60));
+});
+
+test('upload → downloadToLocal の往復が同一バイト列になり ContentType 付きで書かれる', function (): void {
+    Storage::fake('s3');
+    $storage = app(TakeObjectStorage::class);
+
+    $local = tempnam(sys_get_temp_dir(), 'thumb');
+    expect($local)->toBeString();
+    assert(is_string($local));
+    file_put_contents($local, 'jpeg-bytes-of-a-thumbnail');
+
+    $key = 'projects/1/manuals/2/cuts/3/takes/thumbnails/9.jpg';
+    $storage->upload($local, $key, 'image/jpeg');
+    expect(Storage::disk('s3')->exists($key))->toBeTrue();
+
+    $roundTrip = tempnam(sys_get_temp_dir(), 'thumb-back');
+    expect($roundTrip)->toBeString();
+    assert(is_string($roundTrip));
+    $storage->downloadToLocal($key, $roundTrip);
+
+    expect(file_get_contents($roundTrip))->toBe('jpeg-bytes-of-a-thumbnail');
+    // ★ 保証範囲: Storage::fake('s3') はローカル disk であり writeStream の option を
+    //   metadata として保持しない (mimeType は拡張子から導出される)。実 S3 の応答ヘッダに
+    //   Content-Type が載ることは本テストでは保証しない (fake 側の sidecar 検証が別にある)。
+    expect(Storage::disk('s3')->mimeType($key))->toBe('image/jpeg');
+
+    unlink($local);
+    unlink($roundTrip);
+});
+
+test('downloadToLocal は存在しないキーで例外を投げる (無音で 0 バイトを作らない)', function (): void {
+    Storage::fake('s3');
+    $target = tempnam(sys_get_temp_dir(), 'thumb-missing');
+    assert(is_string($target));
+
+    expect(fn () => app(TakeObjectStorage::class)->downloadToLocal('missing/key.mp4', $target))
+        ->toThrow(RuntimeException::class);
+
+    unlink($target);
+});
+
 test('config capture の値が typed accessor で読める', function (): void {
     expect(config()->integer('capture.upload_ticket_ttl_minutes'))->toBe(30);
     expect(config()->integer('capture.max_take_bytes'))->toBe(500 * 1024 * 1024);
@@ -201,4 +252,9 @@ protected function client(): S3Client
     expect(config()->integer('capture.playback_url_ttl_minutes'))->toBe(60);
     expect(config()->integer('capture.released_reservation_retention_days'))->toBe(30);
     expect(config()->integer('capture.stale_verifying_minutes'))->toBe(15);
+    expect(config()->integer('capture.thumbnail_seek_ms'))->toBe(1000);
+    expect(config()->integer('capture.thumbnail_max_edge'))->toBe(640);
+    expect(config()->integer('capture.thumbnail_jpeg_quality'))->toBe(5);
+    // 時間予算の連鎖: ffmpeg 60 < job timeout 180 < worker 240 < retry_after 300
+    expect(config()->integer('capture.thumbnail_ffmpeg_timeout_seconds'))->toBe(60);
 });
diff --git a/tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php b/tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php
index 1883199..e94d5db 100644
--- a/tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php
+++ b/tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php
@@ -74,6 +74,23 @@ public function exists(string $path): bool
 
             return true;
         }
+
+        public function downloadToLocal(string $path, string $localPath): void
+        {
+            $this->calls[] = __FUNCTION__;
+        }
+
+        public function upload(string $localPath, string $path, string $contentType): void
+        {
+            $this->calls[] = __FUNCTION__;
+        }
+
+        public function temporaryThumbnailUrl(string $path): string
+        {
+            $this->calls[] = __FUNCTION__;
+
+            return 'https://spy.invalid/thumbnail';
+        }
     };
     $spy->headResult = new ObjectMetadataData(
         contentLength: $reservation->size_bytes,
diff --git a/tests/Feature/Capture/TakeRegistrationTest.php b/tests/Feature/Capture/TakeRegistrationTest.php
index d2b6eb1..92c8870 100644
--- a/tests/Feature/Capture/TakeRegistrationTest.php
+++ b/tests/Feature/Capture/TakeRegistrationTest.php
@@ -7,6 +7,7 @@
 use App\Enums\Capture\TakeUploadReservationStatus;
 use App\Enums\Manual\TakeStatus;
 use App\Enums\ProjectRole;
+use App\Jobs\Capture\GenerateTakeThumbnailJob;
 use App\Models\Cut;
 use App\Models\Organization;
 use App\Models\Project;
@@ -18,6 +19,7 @@
 use App\Services\Capture\TakeObjectStorage;
 use App\Services\Capture\UploadTicketCodec;
 use Illuminate\Support\Carbon;
+use Illuminate\Support\Facades\Queue;
 use Illuminate\Support\Str;
 use Mockery\MockInterface;
 
@@ -421,3 +423,75 @@ function takesPayload(TakeUploadReservation $reservation, string $ticket, array
     $response->assertStatus(422);
     $response->assertJsonPath('code', 'quota_exceeded');
 });
+
+/*
+|--------------------------------------------------------------------------
+| サムネイル生成ジョブの投入 (T183 / S5)
+|--------------------------------------------------------------------------
+|
+| 投入するのは新規登録のときだけである (冪等再送では既に投入済み)。
+| 「業務 tx の内側で投入される」ことは TakeThumbnailQueueAtomicityTest が固定する
+| (Queue::fake では原子性を検証できない = AGENTS.md ドメイン固有規約 11)。
+*/
+
+test('新規登録は GenerateTakeThumbnailJob をちょうど 1 件投入する (payload は take id)', function (): void {
+    Queue::fake();
+    [, $owner, $project, $manual, $cut] = registrationContext();
+    [$reservation, $ticket] = reservationWithTicket($cut);
+    mockHeadObjectMatching($reservation);
+
+    $this->actingAs($owner)
+        ->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket))
+        ->assertCreated();
+
+    $take = $cut->takes()->where('client_take_id', $reservation->client_take_id)->sole();
+    Queue::assertPushed(GenerateTakeThumbnailJob::class, 1);
+    Queue::assertPushed(
+        GenerateTakeThumbnailJob::class,
+        fn (GenerateTakeThumbnailJob $job): bool => $job->takeId === $take->id,
+    );
+});
+
+test('冪等再送 (200 既存返却) では生成ジョブを 1 件も投入しない', function (): void {
+    Queue::fake();
+    [, $owner, $project, $manual, $cut] = registrationContext();
+    [$reservation, $ticket] = reservationWithTicket($cut);
+    $reservation->forceFill(['status' => TakeUploadReservationStatus::Completed])->save();
+    Take::factory()->forCut($cut)->create([
+        'client_take_id' => $reservation->client_take_id,
+        'video_path' => $reservation->video_path,
+    ]);
+    $mock = Mockery::mock(TakeObjectStorage::class);
+    $mock->shouldNotReceive('delete');
+    app()->instance(TakeObjectStorage::class, $mock);
+
+    $this->actingAs($owner)
+        ->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket))
+        ->assertOk();
+
+    Queue::assertNotPushed(GenerateTakeThumbnailJob::class);
+});
+
+test('確定 CAS に負けた登録 (422) では生成ジョブを投入しない', function (): void {
+    Queue::fake();
+    [, $owner, $project, $manual, $cut] = registrationContext();
+    [$reservation, $ticket] = reservationWithTicket($cut);
+    $mock = Mockery::mock(TakeObjectStorage::class);
+    $mock->shouldReceive('headObject')->andReturnUsing(function () use ($reservation): ObjectMetadataData {
+        TakeUploadReservation::query()->whereKey($reservation->id)
+            ->update(['status' => TakeUploadReservationStatus::Released]);
+
+        return new ObjectMetadataData(
+            contentLength: $reservation->size_bytes,
+            contentType: $reservation->content_type,
+            checksumSha256: $reservation->checksum_sha256,
+        );
+    });
+    app()->instance(TakeObjectStorage::class, $mock);
+
+    $this->actingAs($owner)
+        ->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket))
+        ->assertStatus(422);
+
+    Queue::assertNotPushed(GenerateTakeThumbnailJob::class);
+});
diff --git a/tests/Feature/Capture/TakeThumbnailEndpointTest.php b/tests/Feature/Capture/TakeThumbnailEndpointTest.php
new file mode 100644
index 0000000..28699f1
--- /dev/null
+++ b/tests/Feature/Capture/TakeThumbnailEndpointTest.php
@@ -0,0 +1,151 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+
+/*
+ * テイクのサムネイル配信 (T183 / S7): GET .../takes/{take}/thumbnail。
+ * 生成済み + ready のみ 302 で S3 署名 URL へ (Cache-Control: no-store, private)。
+ * 未生成 / 非 ready は 404 (状態秘匿) / 非 capture は 403 / IDOR は各 404。
+ */
+
+beforeEach(function (): void {
+    enableFakeStorage();
+});
+
+/**
+ * @return array{Organization, User, Project, VideoManual, Cut, Take}
+ */
+function takeThumbnailContext(string $takeStatus = 'ready', bool $withThumbnail = true): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $factory = Take::factory()->forCut($cut);
+    if ($withThumbnail) {
+        $factory = $factory->withThumbnail();
+    }
+    $take = $factory->create(['status' => $takeStatus]);
+
+    return [$organization, $owner, $project, $manual, $cut, $take];
+}
+
+function thumbnailPath(Project $project, VideoManual $manual, Cut $cut, Take $take): string
+{
+    return "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$take->id}/thumbnail";
+}
+
+test('生成済み ready テイクは 302 で署名 URL へリダイレクトし no-store かつ private を返す', function (): void {
+    [, $owner, $project, $manual, $cut, $take] = takeThumbnailContext();
+
+    $response = $this->actingAs($owner)->get(thumbnailPath($project, $manual, $cut, $take));
+
+    $response->assertStatus(302);
+    $location = $response->headers->get('Location');
+    expect($location)->not->toBeNull();
+    // 動画ではなくサムネイルのキーが載る (video_path を誤って渡していないこと)
+    expect($location)->toContain(urlencode((string) $take->thumbnail_path));
+    expect($location)->not->toContain(urlencode($take->video_path));
+
+    $cacheControl = $response->headers->get('Cache-Control');
+    expect($cacheControl)->toContain('no-store');
+    expect($cacheControl)->toContain('private');
+});
+
+test('署名 URL は別 take のサムネイルを使わない', function (): void {
+    [, $owner, $project, $manual, $cut, $take] = takeThumbnailContext();
+    $other = Take::factory()->forCut($cut)->withThumbnail()->create(['status' => 'ready']);
+
+    $location = $this->actingAs($owner)
+        ->get(thumbnailPath($project, $manual, $cut, $take))
+        ->headers->get('Location');
+
+    expect($location)->toContain(urlencode((string) $take->thumbnail_path));
+    expect($location)->not->toContain(urlencode((string) $other->thumbnail_path));
+});
+
+test('未生成 (thumbnail_path=null) は 404', function (): void {
+    [, $owner, $project, $manual, $cut, $take] = takeThumbnailContext(withThumbnail: false);
+
+    $this->actingAs($owner)
+        ->get(thumbnailPath($project, $manual, $cut, $take))
+        ->assertNotFound();
+});
+
+test('非 ready テイクは生成済みでも 404 (状態秘匿)', function (string $status): void {
+    [, $owner, $project, $manual, $cut, $take] = takeThumbnailContext($status);
+
+    $this->actingAs($owner)
+        ->get(thumbnailPath($project, $manual, $cut, $take))
+        ->assertNotFound();
+})->with(['uploading', 'processing', 'failed']);
+
+test('非 capture ユーザー (非 project member の org member) は 403', function (): void {
+    [$organization, , $project, $manual, $cut, $take] = takeThumbnailContext();
+    $orgMember = attachOrganizationMember($organization);
+    $orgMember->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($orgMember)
+        ->get(thumbnailPath($project, $manual, $cut, $take))
+        ->assertForbidden();
+});
+
+test('未認証はログインへリダイレクトする', function (): void {
+    [, , $project, $manual, $cut, $take] = takeThumbnailContext();
+
+    $this->get(thumbnailPath($project, $manual, $cut, $take))->assertRedirect('/login');
+});
+
+test('IDOR: project mismatch は 404 (認可より前)', function (): void {
+    [$organization, $owner, , $manual, $cut, $take] = takeThumbnailContext();
+    $otherProject = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)
+        ->get(thumbnailPath($otherProject, $manual, $cut, $take))
+        ->assertNotFound();
+});
+
+test('IDOR: manual mismatch は 404', function (): void {
+    [, $owner, $project, , $cut, $take] = takeThumbnailContext();
+    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+
+    $this->actingAs($owner)
+        ->get(thumbnailPath($project, $otherManual, $cut, $take))
+        ->assertNotFound();
+});
+
+test('IDOR: cut mismatch は 404', function (): void {
+    [, $owner, $project, $manual, , $take] = takeThumbnailContext();
+    $otherCut = Cut::factory()->forManual($manual)->create();
+
+    $this->actingAs($owner)
+        ->get(thumbnailPath($project, $manual, $otherCut, $take))
+        ->assertNotFound();
+});
+
+test('IDOR: take mismatch (別 cut 所属の take を別 cut の URL で) は 404', function (): void {
+    [, $owner, $project, $manual, $cut] = takeThumbnailContext();
+    $cutB = Cut::factory()->forManual($manual)->create();
+    $takeB = Take::factory()->forCut($cutB)->withThumbnail()->create(['status' => 'ready']);
+
+    $this->actingAs($owner)
+        ->get(thumbnailPath($project, $manual, $cut, $takeB))
+        ->assertNotFound();
+});
+
+test('IDOR: cross-org は 404', function (): void {
+    [, , $project, $manual, $cut, $take] = takeThumbnailContext();
+    [$otherOrg, $otherOwner] = createOrganizationWithOwner('別組織');
+    $otherOwner->forceFill(['current_organization_id' => $otherOrg->id])->save();
+
+    $this->actingAs($otherOwner)
+        ->get(thumbnailPath($project, $manual, $cut, $take))
+        ->assertNotFound();
+});
diff --git a/tests/Feature/Capture/TakeThumbnailGenerationTest.php b/tests/Feature/Capture/TakeThumbnailGenerationTest.php
new file mode 100644
index 0000000..35cf4f0
--- /dev/null
+++ b/tests/Feature/Capture/TakeThumbnailGenerationTest.php
@@ -0,0 +1,273 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\TakeStatus;
+use App\Enums\Security\ExternalCallKind;
+use App\Exceptions\Capture\TakeThumbnailExtractionException;
+use App\Jobs\Capture\GenerateTakeThumbnailJob;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\VideoManual;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Capture\TakeThumbnailExtractor;
+use App\Services\Capture\TakeThumbnailPipeline;
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Facades\Storage;
+
+/*
+ * サムネイル生成パイプライン (S3 GET → ffmpeg → S3 PUT → 条件付き UPDATE)。
+ *
+ * 固定する契約:
+ * - 決定的 S3 キー + image/jpeg + thumbnail_size_bytes = 出力サイズ
+ * - 冪等 (2 回目は extractor を呼ばない) / ready でない・削除済みは no-op
+ * - **preflight の配置**: 抽出中に所有権を失うと upload() が 1 回も呼ばれない
+ *   (目録 gate が保証しない「配置」を behavioral に固定する)
+ * - preflight 通過後・UPDATE 前に先着されたら UPDATE は 0 行で、**オブジェクトは消さない**
+ * - work dir は実行ごとに一意で、正常・異常いずれも finally で消える
+ */
+
+/** 抽出中の細工フックを持つ fake extractor (実 ffmpeg に触れない) */
+final class ThumbnailPipelineFakeExtractor implements TakeThumbnailExtractor
+{
+    public int $calls = 0;
+
+    /** 抽出中に呼ばれる hook (先着・削除等のインターリーブ細工用) */
+    public ?Closure $duringExtract = null;
+
+    /** 非 null なら extract がこの例外を投げる */
+    public ?Throwable $throws = null;
+
+    public string $bytes = 'jpeg-bytes-1234567890';
+
+    /** @var list<string> 実行ごとの作業ディレクトリ */
+    public array $workDirs = [];
+
+    public function extract(string $localVideoPath, string $localThumbnailPath): void
+    {
+        $this->calls++;
+        $this->workDirs[] = dirname($localThumbnailPath);
+        if ($this->duringExtract !== null) {
+            ($this->duringExtract)();
+        }
+        if ($this->throws !== null) {
+            throw $this->throws;
+        }
+        file_put_contents($localThumbnailPath, $this->bytes);
+    }
+}
+
+/** upload / downloadToLocal の呼び出しを記録する storage (実体は Storage::fake('s3')) */
+final class ThumbnailPipelineRecordingStorage extends TakeObjectStorage
+{
+    public int $downloadCalls = 0;
+
+    /** @var list<array{path: string, contentType: string}> */
+    public array $uploads = [];
+
+    /** upload の**直前**に呼ばれる hook (PUT〜UPDATE 間の先着を作る) */
+    public ?Closure $duringUpload = null;
+
+    public function downloadToLocal(string $path, string $localPath): void
+    {
+        $this->downloadCalls++;
+        parent::downloadToLocal($path, $localPath);
+    }
+
+    public function upload(string $localPath, string $path, string $contentType): void
+    {
+        $this->uploads[] = ['path' => $path, 'contentType' => $contentType];
+        if ($this->duringUpload !== null) {
+            ($this->duringUpload)();
+        }
+        parent::upload($localPath, $path, $contentType);
+    }
+}
+
+/**
+ * 生成対象のテイク一式 + container へ差し込んだ fake。
+ *
+ * @return array{Take, Cut, VideoManual, ThumbnailPipelineFakeExtractor, ThumbnailPipelineRecordingStorage}
+ */
+function thumbnailPipelineContext(string $status = 'ready'): array
+{
+    Storage::fake('s3');
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create(['status' => $status]);
+    Storage::disk('s3')->put($take->video_path, 'fake-take-video');
+
+    $extractor = new ThumbnailPipelineFakeExtractor;
+    $storage = new ThumbnailPipelineRecordingStorage;
+    app()->instance(TakeThumbnailExtractor::class, $extractor);
+    app()->instance(TakeObjectStorage::class, $storage);
+
+    return [$take, $cut, $manual, $extractor, $storage];
+}
+
+/** 対象テイクの決定的な S3 キー */
+function expectedThumbnailKey(Take $take, Cut $cut, VideoManual $manual): string
+{
+    return "projects/{$manual->project_id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/thumbnails/{$take->id}.jpg";
+}
+
+test('成功: 決定的キーで PUT し thumbnail_path / thumbnail_size_bytes を確定する', function (): void {
+    [$take, $cut, $manual, $extractor, $storage] = thumbnailPipelineContext();
+
+    app(TakeThumbnailPipeline::class)->run($take->id);
+
+    $key = expectedThumbnailKey($take, $cut, $manual);
+    $take->refresh();
+    expect($take->thumbnail_path)->toBe($key);
+    expect($take->thumbnail_size_bytes)->toBe(strlen($extractor->bytes));
+    expect($take->status)->toBe(TakeStatus::Ready);
+
+    expect($storage->uploads)->toHaveCount(1);
+    expect($storage->uploads[0]['path'])->toBe($key);
+    expect($storage->uploads[0]['contentType'])->toBe('image/jpeg');
+    expect(Storage::disk('s3')->exists($key))->toBeTrue();
+});
+
+test('冪等: 2 回目の実行は extractor も storage も呼ばず 1 回目の値を保つ', function (): void {
+    [$take, , , $extractor, $storage] = thumbnailPipelineContext();
+
+    app(TakeThumbnailPipeline::class)->run($take->id);
+    $first = $take->fresh();
+    expect($first?->thumbnail_path)->not->toBeNull();
+
+    app(TakeThumbnailPipeline::class)->run($take->id);
+
+    expect($extractor->calls)->toBe(1);
+    expect($storage->downloadCalls)->toBe(1);
+    expect($storage->uploads)->toHaveCount(1);
+    expect($take->fresh()?->thumbnail_path)->toBe($first?->thumbnail_path);
+});
+
+test('ready でないテイクでは extractor も storage も 1 回も呼ばれない', function (string $status): void {
+    [$take, , , $extractor, $storage] = thumbnailPipelineContext($status);
+
+    app(TakeThumbnailPipeline::class)->run($take->id);
+
+    expect($extractor->calls)->toBe(0);
+    expect($storage->downloadCalls)->toBe(0);
+    expect($storage->uploads)->toBe([]);
+    expect($take->fresh()?->thumbnail_path)->toBeNull();
+})->with(['uploading', 'processing', 'failed']);
+
+test('テイク行が削除済みなら no-op (例外を投げない)', function (): void {
+    [$take, , , $extractor, $storage] = thumbnailPipelineContext();
+    $takeId = $take->id;
+    $take->delete();
+
+    app(TakeThumbnailPipeline::class)->run($takeId);
+
+    expect($extractor->calls)->toBe(0);
+    expect($storage->uploads)->toBe([]);
+});
+
+test('preflight の配置: 抽出中にテイクが消えると upload が 1 回も呼ばれず抑止ログが出る', function (): void {
+    Log::spy();
+    [$take, , , $extractor, $storage] = thumbnailPipelineContext();
+    $takeId = $take->id;
+    $extractor->duringExtract = function () use ($takeId): void {
+        Take::query()->whereKey($takeId)->delete();
+    };
+
+    app(TakeThumbnailPipeline::class)->run($takeId);
+
+    expect($extractor->calls)->toBe(1);
+    expect($storage->uploads)->toBe([]); // 取り消せない S3 PUT は 1 回も起きない
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context) use ($takeId): bool {
+            return ($context['event'] ?? null) === ExternalCallKind::LOG_EVENT
+                && ($context['job_type'] ?? null) === Take::class
+                && ($context['job_id'] ?? null) === $takeId
+                && ($context['expected_status'] ?? null) === 'ready'
+                && array_key_exists('actual_status', $context) && $context['actual_status'] === null
+                && ($context['stage'] ?? null) === 'thumbnail_upload'
+                && ($context['external_call'] ?? null) === ExternalCallKind::ObjectStoragePut->value
+                && ($context['thumbnail_present'] ?? null) === false;
+        })
+        ->once();
+});
+
+test('preflight の配置: 抽出中に先着されると upload が呼ばれず先着の値が保たれる', function (): void {
+    [$take, , , $extractor, $storage] = thumbnailPipelineContext();
+    $takeId = $take->id;
+    $extractor->duringExtract = function () use ($takeId): void {
+        Take::query()->whereKey($takeId)->update([
+            'thumbnail_path' => 'winner/thumbnail.jpg',
+            'thumbnail_size_bytes' => 123,
+        ]);
+    };
+
+    app(TakeThumbnailPipeline::class)->run($takeId);
+
+    expect($storage->uploads)->toBe([]);
+    $take->refresh();
+    expect($take->thumbnail_path)->toBe('winner/thumbnail.jpg');
+    expect($take->thumbnail_size_bytes)->toBe(123);
+});
+
+test('preflight 通過後・UPDATE 前の先着では PUT は行われるが UPDATE が 0 行でオブジェクトも消さない', function (): void {
+    [$take, $cut, $manual, , $storage] = thumbnailPipelineContext();
+    $takeId = $take->id;
+    $storage->duringUpload = function () use ($takeId): void {
+        Take::query()->whereKey($takeId)->update([
+            'thumbnail_path' => 'winner/thumbnail.jpg',
+            'thumbnail_size_bytes' => 456,
+        ]);
+    };
+
+    app(TakeThumbnailPipeline::class)->run($takeId);
+
+    expect($storage->uploads)->toHaveCount(1);
+    $take->refresh();
+    // 先着の値が保たれる (0 行更新)
+    expect($take->thumbnail_path)->toBe('winner/thumbnail.jpg');
+    expect($take->thumbnail_size_bytes)->toBe(456);
+    // ★ キーが決定的なので敗者はオブジェクトを消してはいけない (消すと勝者の実体を壊す)
+    expect(Storage::disk('s3')->exists(expectedThumbnailKey($take, $cut, $manual)))->toBeTrue();
+});
+
+test('抽出失敗: take は ready のまま thumbnail_path は null で work dir が残らない', function (): void {
+    [$take, , , $extractor, $storage] = thumbnailPipelineContext();
+    $extractor->throws = new TakeThumbnailExtractionException('ffmpeg produced no frame (seek=0ms)');
+
+    expect(fn () => app(TakeThumbnailPipeline::class)->run($take->id))
+        ->toThrow(TakeThumbnailExtractionException::class);
+
+    $take->refresh();
+    expect($take->status)->toBe(TakeStatus::Ready);
+    expect($take->thumbnail_path)->toBeNull();
+    expect($storage->uploads)->toBe([]);
+    expect($extractor->workDirs)->toHaveCount(1);
+    expect(File::isDirectory($extractor->workDirs[0]))->toBeFalse(); // finally で消える
+});
+
+test('work dir は実行ごとに一意で、成功時も finally で消える', function (): void {
+    [$take, , , $extractor] = thumbnailPipelineContext();
+
+    app(TakeThumbnailPipeline::class)->run($take->id);
+    // 2 回目は冪等短絡するため、別テイクでもう 1 本走らせて一意性を見る
+    [$second, , , $secondExtractor] = thumbnailPipelineContext();
+    app(TakeThumbnailPipeline::class)->run($second->id);
+
+    expect($extractor->workDirs)->toHaveCount(1);
+    expect($secondExtractor->workDirs)->toHaveCount(1);
+    expect($extractor->workDirs[0])->not->toBe($secondExtractor->workDirs[0]);
+    expect(File::isDirectory($extractor->workDirs[0]))->toBeFalse();
+    expect(File::isDirectory($secondExtractor->workDirs[0]))->toBeFalse();
+});
+
+test('ジョブは薄い殻でパイプラインへ take id を渡すだけ', function (): void {
+    [$take, $cut, $manual] = thumbnailPipelineContext();
+
+    (new GenerateTakeThumbnailJob($take->id))->handle(app(TakeThumbnailPipeline::class));
+
+    expect($take->fresh()?->thumbnail_path)->toBe(expectedThumbnailKey($take, $cut, $manual));
+});
diff --git a/tests/Feature/Capture/TakeThumbnailQueueAtomicityTest.php b/tests/Feature/Capture/TakeThumbnailQueueAtomicityTest.php
new file mode 100644
index 0000000..ac9e4d7
--- /dev/null
+++ b/tests/Feature/Capture/TakeThumbnailQueueAtomicityTest.php
@@ -0,0 +1,97 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Capture\ObjectMetadataData;
+use App\DataTransferObjects\Capture\TakeRegistrationInput;
+use App\DataTransferObjects\Capture\UploadTicketClaims;
+use App\Jobs\Capture\GenerateTakeThumbnailJob;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\TakeUploadReservation;
+use App\Models\VideoManual;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Capture\TakeRegistrationService;
+use App\Services\Capture\UploadTicketCodec;
+use Illuminate\Support\Facades\DB;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の原子性 (サムネイル生成の投入経路。AGENTS.md ドメイン固有規約 11)
+|--------------------------------------------------------------------------
+|
+| 主契約は tx level 観測 (baseline + 1 以上)。rollback テストは補助であり、
+| dispatch の移設は検出しない (TakeDeletionQueueAtomicityTest と同じ但し書き)。
+| 保証するのは「take 行を作ったのに生成 job が投入されない窓」の解消だけで、
+| worker 停止 / ffmpeg 失敗 / S3 失敗ではサムネイルは付かない (誇張しない)。
+*/
+
+/**
+ * 登録直前まで整えた一式 (Service を直接呼ぶ = HTTP 層を挟まず tx level を観測する)。
+ *
+ * @return array{Project, VideoManual, Cut, TakeUploadReservation, TakeRegistrationInput}
+ */
+function thumbnailQueueAtomicityContext(): array
+{
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $reservation = TakeUploadReservation::factory()->forCut($cut)->create();
+    $reservation->refresh();
+    $ticket = app(UploadTicketCodec::class)->seal(UploadTicketClaims::fromReservation($reservation));
+
+    $mock = Mockery::mock(TakeObjectStorage::class);
+    $mock->shouldReceive('headObject')->andReturn(new ObjectMetadataData(
+        contentLength: $reservation->size_bytes,
+        contentType: $reservation->content_type,
+        checksumSha256: $reservation->checksum_sha256,
+    ));
+    app()->instance(TakeObjectStorage::class, $mock);
+
+    $input = new TakeRegistrationInput(
+        ticket: $ticket,
+        clientTakeId: $reservation->client_take_id,
+        durationMs: 5_000,
+        capturedAt: now()->toImmutable(),
+    );
+
+    return [$project, $manual, $cut, $reservation, $input];
+}
+
+test('テイク登録の GenerateTakeThumbnailJob は業務 tx の内側で投入される', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database-media.after_commit'))->toBeFalse();
+
+    [$project, $manual, $cut, , $input] = thumbnailQueueAtomicityContext();
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(TakeRegistrationService::class)->register($project, $manual, $cut, $input),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), GenerateTakeThumbnailJob::class);
+
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('登録の外側 tx が rollback すると take 行も生成 job も残らない', function (): void {
+    config()->set('queue.default', 'database');
+    [$project, $manual, $cut, , $input] = thumbnailQueueAtomicityContext();
+    $jobsBefore = DB::table('jobs')->count();
+
+    try {
+        DB::transaction(function () use ($project, $manual, $cut, $input): void {
+            app(TakeRegistrationService::class)->register($project, $manual, $cut, $input);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    expect(Take::query()->where('cut_id', $cut->id)->exists())->toBeFalse();
+    expect(DB::table('jobs')->count())->toBe($jobsBefore);
+});
diff --git a/tests/Feature/Storage/FakeStorageRouteTest.php b/tests/Feature/Storage/FakeStorageRouteTest.php
index 478ba2a..675c13e 100644
--- a/tests/Feature/Storage/FakeStorageRouteTest.php
+++ b/tests/Feature/Storage/FakeStorageRouteTest.php
@@ -128,6 +128,30 @@ function putObject(string $url, string $body, ?string $checksumHeader = null): T
     expect($partial->streamedContent())->toBe(substr($body, 0, 4));
 });
 
+test('fake の upload はサムネイルの sidecar content_type を書き temporaryThumbnailUrl の GET が image/jpeg を返す', function (): void {
+    unsetRealS3Region();
+    $key = 'projects/1/manuals/2/cuts/3/takes/thumbnails/9.jpg';
+    $local = tempnam(sys_get_temp_dir(), 'fake-thumb');
+    assert(is_string($local));
+    file_put_contents($local, 'jpeg-bytes');
+
+    fakeTakeStorage()->upload($local, $key, 'image/jpeg');
+
+    $response = test()->get(fakeTakeStorage()->temporaryThumbnailUrl($key));
+    $response->assertOk();
+    $response->assertHeader('Content-Type', 'image/jpeg');
+    expect($response->streamedContent())->toBe('jpeg-bytes');
+
+    // 生成物をローカルへ戻せる (サムネイル生成の入力取得と同じ経路)
+    $back = tempnam(sys_get_temp_dir(), 'fake-thumb-back');
+    assert(is_string($back));
+    fakeTakeStorage()->downloadToLocal($key, $back);
+    expect(file_get_contents($back))->toBe('jpeg-bytes');
+
+    unlink($local);
+    unlink($back);
+});
+
 test('未登録 object の GET は 404 (sidecar 欠損=未完了も 404)', function (): void {
     $getUrl = fakeTakeStorage()->temporaryPlaybackUrl('projects/1/manuals/2/cuts/3/takes/MISSING.mp4');
     test()->get($getUrl)->assertNotFound();
diff --git a/tests/Support/Routing/NestedRouteDefenseInventory.php b/tests/Support/Routing/NestedRouteDefenseInventory.php
index b65bc46..a0ad06c 100644
--- a/tests/Support/Routing/NestedRouteDefenseInventory.php
+++ b/tests/Support/Routing/NestedRouteDefenseInventory.php
@@ -64,6 +64,7 @@ public static function inventory(): array
             'capture.takes.adopt' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
             'capture.takes.downloaded' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
             'capture.takes.playback' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
+            'capture.takes.thumbnail' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
 
             // --- 業務 route (web) ---
             'projects.show' => $project,
diff --git a/tests/Support/Security/DirectFetchInventory.php b/tests/Support/Security/DirectFetchInventory.php
index 6fa9b65..56a148a 100644
--- a/tests/Support/Security/DirectFetchInventory.php
+++ b/tests/Support/Security/DirectFetchInventory.php
@@ -281,6 +281,12 @@ public static function inventory(): array
                 .'サーバ確定値で HTTP 入力を経由しない',
                 enqueuedBy: 'App\Jobs\Manual\RunManualRender::handle',
             ),
+            'Services/Capture/TakeThumbnailPipeline.php#run#Take.find:$takeId#1' => DirectFetchJustificationEntry::queuePayload(
+                'GenerateTakeThumbnailJob::handle が $this->takeId をそのまま渡す委譲先。id はテナント検証済みの'
+                .'登録 tx (TakeRegistrationService::finalize) がサーバ採番した主キーで HTTP 入力を経由しない。'
+                .'worker 側は再水和したうえで status / thumbnail_path を検査してから外部へ出る',
+                enqueuedBy: 'App\Jobs\Capture\GenerateTakeThumbnailJob::handle',
+            ),
 
             // --- テナントスコープ済みの解決から確定した id ---
             'Services/Billing/PersonalPlanService.php#activateWithinTransaction#Organization.findOrFail:$organizationId#1' => DirectFetchJustificationEntry::idFromTenantScopedQuery(
diff --git a/tests/Support/Storage/S3SurfaceInventory.php b/tests/Support/Storage/S3SurfaceInventory.php
index 9425b06..512a770 100644
--- a/tests/Support/Storage/S3SurfaceInventory.php
+++ b/tests/Support/Storage/S3SurfaceInventory.php
@@ -41,6 +41,18 @@ public static function all(): array
                     'surface' => S3OperationSurface::NoObjectRequest,
                     'rationale' => '署名 URL の文字列生成のみでオブジェクト API をまったく送らない',
                 ],
+                'downloadToLocal' => [
+                    'surface' => S3OperationSurface::Bulk,
+                    'rationale' => '本文転送であり所要時間がオブジェクトサイズに比例して伸びるサムネイル生成専用の取得',
+                ],
+                'upload' => [
+                    'surface' => S3OperationSurface::Bulk,
+                    'rationale' => '本文転送でありサムネイル生成ジョブ専用の PUT で web 同期経路からは呼ばない',
+                ],
+                'temporaryThumbnailUrl' => [
+                    'surface' => S3OperationSurface::NoObjectRequest,
+                    'rationale' => '署名 URL の文字列生成のみでオブジェクト API をまったく送らない',
+                ],
                 'delete' => [
                     'surface' => S3OperationSurface::Bulk,
                     'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作',
diff --git a/tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php b/tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php
new file mode 100644
index 0000000..d31d0aa
--- /dev/null
+++ b/tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php
@@ -0,0 +1,148 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Exceptions\Capture\TakeThumbnailExtractionException;
+use App\Services\Capture\FfmpegTakeThumbnailExtractor;
+use Illuminate\Process\PendingProcess;
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+
+/*
+ * ffmpeg 1 フレーム抽出のコマンド構造と再試行 (Process::fake。実 ffmpeg に触れない):
+ * - 安全境界の引数 (-nostdin / -protocol_whitelist file / 配列渡し)
+ * - 出力寸法・品質が config 固定
+ * - 尺不足で 0 バイトなら seek=0 で 1 回だけ再試行する
+ * - 実行前に出力先を消す (1 回目の残骸を成功と誤認しない)
+ *
+ * ★ 実バイナリの挙動差 (`-frames:v 1` + `-f image2` の出力有無) は本テストでは検出しない。
+ *   実バイナリでの通し確認は bug-hunt の pipeline-smoke (別基盤) の領域である。
+ */
+
+/** 一時作業ディレクトリ (実行ごとに一意) */
+function thumbnailWorkDir(): string
+{
+    $dir = sys_get_temp_dir().'/thumb-extract-'.uniqid();
+    mkdir($dir);
+
+    return $dir;
+}
+
+/**
+ * Process::fake + 実行コマンドの収集。$onRun は実行のたびに (回数, 出力先) で呼ばれ、
+ * 出力ファイルの生成/非生成を決定的に作る。
+ *
+ * @param  list<string>  $recorded  (参照で埋まる。1 要素 = 1 コマンドの space 連結)
+ * @param  callable(int, string): int  $onRun  戻り値 = 終了コード
+ */
+function fakeThumbnailFfmpeg(array &$recorded, callable $onRun): void
+{
+    $attempt = 0;
+    Process::fake(function (PendingProcess $process) use (&$recorded, &$attempt, $onRun) {
+        $command = $process->command;
+        $parts = is_array($command) ? array_map(strval(...), $command) : [(string) $command];
+        $recorded[] = implode(' ', $parts);
+        $attempt++;
+        $destination = $parts[count($parts) - 1];
+        $exitCode = $onRun($attempt, $destination);
+
+        return Process::result(output: '', errorOutput: $exitCode === 0 ? '' : 'ffmpeg boom', exitCode: $exitCode);
+    });
+}
+
+test('コマンド構造: 安全境界の引数と config 由来の寸法・品質を持つ', function (): void {
+    $recorded = [];
+    fakeThumbnailFfmpeg($recorded, function (int $attempt, string $destination): int {
+        file_put_contents($destination, 'jpeg');
+
+        return 0;
+    });
+    $workDir = thumbnailWorkDir();
+
+    app(FfmpegTakeThumbnailExtractor::class)->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg");
+
+    expect($recorded)->toHaveCount(1);
+    $line = $recorded[0];
+    expect($line)->toContain('-nostdin');
+    expect($line)->toContain('-protocol_whitelist file');
+    expect($line)->toContain('-frames:v 1');
+    expect($line)->toContain('-vf scale=640:640:force_original_aspect_ratio=decrease');
+    expect($line)->toContain('-q:v 5');
+    expect($line)->toContain('-f image2');
+    // -ss は config の thumbnail_seek_ms (1000) を秒へ変換した値
+    expect($line)->toContain('-ss 1.000');
+    // 引数はサーバ生成のパス 2 つだけ (利用者由来の文字列は 1 つも入らない)
+    expect($line)->toContain("-i {$workDir}/source");
+    expect($line)->toContain("{$workDir}/thumbnail.jpg");
+});
+
+test('尺不足で 1 回目が 0 バイトなら seek=0 で 1 回だけ再試行し、成功すれば例外を投げない', function (): void {
+    $recorded = [];
+    fakeThumbnailFfmpeg($recorded, function (int $attempt, string $destination): int {
+        if ($attempt === 1) {
+            file_put_contents($destination, ''); // 終了コード 0 のまま 0 バイト
+        } else {
+            file_put_contents($destination, 'jpeg');
+        }
+
+        return 0;
+    });
+    $workDir = thumbnailWorkDir();
+
+    app(FfmpegTakeThumbnailExtractor::class)->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg");
+
+    expect($recorded)->toHaveCount(2);
+    expect($recorded[0])->toContain('-ss 1.000');
+    expect($recorded[1])->toContain('-ss 0.000'); // 先頭へ倒した再試行 (これ以上の探索はしない)
+});
+
+test('2 回とも失敗すると TakeThumbnailExtractionException で stderr の先頭が入る', function (): void {
+    $recorded = [];
+    fakeThumbnailFfmpeg($recorded, fn (int $attempt, string $destination): int => 1);
+    $workDir = thumbnailWorkDir();
+
+    expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
+        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg"))
+        ->toThrow(TakeThumbnailExtractionException::class, 'ffmpeg failed (thumbnail): ffmpeg boom');
+
+    expect($recorded)->toHaveCount(2);
+});
+
+test('1 回目の残骸を成功と誤認しない (実行前に出力先を削除する)', function (): void {
+    // 1 回目: 非 0 終了しつつ**非空ファイルを残す** / 2 回目: 終了コード 0 のまま何も出さない。
+    // 実行前削除が無いと、2 回目の実体検査が 1 回目の残骸を見て「成功」と誤認する。
+    $recorded = [];
+    fakeThumbnailFfmpeg($recorded, function (int $attempt, string $destination): int {
+        if ($attempt === 1) {
+            file_put_contents($destination, 'broken-leftover');
+
+            return 1;
+        }
+
+        return 0; // 出力を作らない
+    });
+    $workDir = thumbnailWorkDir();
+
+    expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
+        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg"))
+        ->toThrow(TakeThumbnailExtractionException::class, 'ffmpeg produced no frame (seek=0ms)');
+});
+
+test('出力先を削除できない場合も失敗として扱う (OS 権限に依存させず File facade で作る)', function (): void {
+    // ★ 素の unlink() を使わない理由: 失敗時の E_WARNING を Laravel のエラーハンドラが
+    //   ErrorException へ変換する環境では「失敗理由を返す」契約から外れる。
+    //   File::delete() + 存在確認なら判定が戻り値だけで閉じるので、ここでは
+    //   File facade を差し替えて「削除が効かなかった」状況を決定的に作る。
+    $recorded = [];
+    fakeThumbnailFfmpeg($recorded, fn (int $attempt, string $destination): int => 0);
+
+    File::shouldReceive('isFile')->andReturnTrue();
+    File::shouldReceive('delete')->andReturnFalse();
+
+    expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
+        ->extract('/tmp/thumb-source', '/tmp/thumb-out.jpg'))
+        ->toThrow(TakeThumbnailExtractionException::class, 'failed to remove stale thumbnail output');
+
+    // 削除できなかった時点で ffmpeg を 1 回も起動しない
+    expect($recorded)->toBe([]);
+});
diff --git a/tests/Unit/Services/Storage/FakeStorageContractTest.php b/tests/Unit/Services/Storage/FakeStorageContractTest.php
index 9830b26..36682f0 100644
--- a/tests/Unit/Services/Storage/FakeStorageContractTest.php
+++ b/tests/Unit/Services/Storage/FakeStorageContractTest.php
@@ -26,6 +26,9 @@ function isOverriddenOn(string $fakeClass, string $method): bool
     'presignUpload',
     'headObject',
     'temporaryPlaybackUrl',
+    'downloadToLocal', // サムネイル生成の入力取得 (T183)
+    'upload',          // サムネイルの PUT (T183)
+    'temporaryThumbnailUrl',
     'delete',
     'exists',
     'client', // 実 S3 client を構築しない (fail-loud)
diff --git a/tests/js/components/features/capture/TakePreviewDialog.test.ts b/tests/js/components/features/capture/TakePreviewDialog.test.ts
index 987eb4a..a2e2fe8 100644
--- a/tests/js/components/features/capture/TakePreviewDialog.test.ts
+++ b/tests/js/components/features/capture/TakePreviewDialog.test.ts
@@ -20,6 +20,7 @@ function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
         captured_at: null,
         sort_order: 0,
         downloaded: false,
+        has_thumbnail: false,
         playback_url: null,
         download_ack_token: null,
         ...overrides,
diff --git a/tests/js/components/features/capture/TakeStrip.test.ts b/tests/js/components/features/capture/TakeStrip.test.ts
index 427de5c..2883fd5 100644
--- a/tests/js/components/features/capture/TakeStrip.test.ts
+++ b/tests/js/components/features/capture/TakeStrip.test.ts
@@ -22,6 +22,7 @@ function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
         captured_at: null,
         sort_order: 0,
         downloaded: false,
+        has_thumbnail: false,
         playback_url: null,
         download_ack_token: null,
         ...overrides,
@@ -358,3 +359,54 @@ describe("mobile 375px レイアウト構造 (F-1-05)", () => {
         expect(label.queryByText("DL 済み")).not.toBeInTheDocument();
     });
 });
+
+describe("サムネイル表示 (T183)", () => {
+    it("has_thumbnail=false ではプレースホルダを出し <img> を描画しない (404 を出さない)", () => {
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake({ has_thumbnail: false })]),
+            onChanged: vi.fn(),
+        });
+
+        expect(screen.getByTestId("take-thumbnail-placeholder-10")).toBeInTheDocument();
+        expect(screen.queryByTestId("take-thumbnail-10")).not.toBeInTheDocument();
+    });
+
+    it("has_thumbnail=true では配信 endpoint を src に持つ <img> を描画する", () => {
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake({ has_thumbnail: true })]),
+            onChanged: vi.fn(),
+        });
+
+        const img = screen.getByTestId("take-thumbnail-10");
+        expect(img.getAttribute("src")).toBe(
+            "/app/projects/1/manuals/2/cuts/3/takes/10/thumbnail",
+        );
+        // 行に「テイク N」の見出しがあるため画像は装飾 (alt="")
+        expect(img.getAttribute("alt")).toBe("");
+        expect(screen.queryByTestId("take-thumbnail-placeholder-10")).not.toBeInTheDocument();
+    });
+
+    it("false → true への props 更新で同じ take の枠が画像へ置き換わる", async () => {
+        const { rerender } = render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake({ has_thumbnail: false })]),
+            onChanged: vi.fn(),
+        });
+        expect(screen.getByTestId("take-thumbnail-placeholder-10")).toBeInTheDocument();
+
+        await rerender({
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake({ has_thumbnail: true })]),
+            onChanged: vi.fn(),
+        });
+
+        expect(screen.getByTestId("take-thumbnail-10")).toBeInTheDocument();
+        expect(screen.queryByTestId("take-thumbnail-placeholder-10")).not.toBeInTheDocument();
+    });
+});
diff --git a/tests/js/lib/capture/auto-download.test.ts b/tests/js/lib/capture/auto-download.test.ts
index 3ee4352..00a8548 100644
--- a/tests/js/lib/capture/auto-download.test.ts
+++ b/tests/js/lib/capture/auto-download.test.ts
@@ -24,6 +24,7 @@ function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
         captured_at: "2026-07-11T00:00:00Z",
         sort_order: 0,
         downloaded: false,
+        has_thumbnail: false,
         playback_url: "https://s3.example.test/take-11.mp4?sig=1",
         download_ack_token: "ack-token-11",
         ...overrides,
diff --git a/tests/js/lib/capture/thumbnail-refresh.test.ts b/tests/js/lib/capture/thumbnail-refresh.test.ts
new file mode 100644
index 0000000..79ac5c4
--- /dev/null
+++ b/tests/js/lib/capture/thumbnail-refresh.test.ts
@@ -0,0 +1,234 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { ThumbnailRefreshScheduler } from "@/lib/capture/thumbnail-refresh";
+import type { CaptureManualDetail, CaptureTake } from "@/types/capture";
+
+/*
+ * サムネイル反映の有界な再取得スケジューラ。
+ * - watch() していないテイクは追わない (過去分で無駄なポーリングをしない)
+ * - 2s → 4s → 8s → 15s の 4 回で止まる (試行上限)
+ * - 監視集合が空になったら止まる / pause / stop
+ * - single-flight: reload が解決するまで次を始めない
+ */
+
+function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
+    return {
+        id: 10,
+        client_take_id: "take-a",
+        status: "ready",
+        size_bytes: 1024,
+        duration_ms: 1000,
+        comment: null,
+        captured_at: null,
+        sort_order: 0,
+        downloaded: false,
+        has_thumbnail: false,
+        playback_url: null,
+        download_ack_token: null,
+        ...overrides,
+    };
+}
+
+function makeManual(takes: CaptureTake[]): CaptureManualDetail {
+    return {
+        id: 1,
+        title: "手順書",
+        status: "ready",
+        cuts: [
+            {
+                id: 3,
+                type: "step",
+                parent_cut_id: null,
+                scene: "準備",
+                shot_type: "hiki",
+                shooting_point: null,
+                narration: "準備します",
+                subtitle_primary: null,
+                subtitle_secondary: "準備",
+                adopted_take_id: null,
+                takes,
+            },
+        ],
+    };
+}
+
+beforeEach(() => {
+    vi.useFakeTimers();
+});
+
+afterEach(() => {
+    vi.useRealTimers();
+});
+
+describe("ThumbnailRefreshScheduler", () => {
+    it("watch() していなければ has_thumbnail=false のテイクがあっても再取得しない", async () => {
+        const reload = vi.fn(async () => {});
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+
+        scheduler.sync(makeManual([makeTake({ client_take_id: "old-take" })]));
+        await vi.advanceTimersByTimeAsync(60_000);
+
+        expect(reload).not.toHaveBeenCalled();
+    });
+
+    it("watch() 後は 2s → 4s → 8s → 15s の計 4 回で止まる (試行上限)", async () => {
+        const reload = vi.fn(async () => {});
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+        scheduler.watch("take-a");
+
+        await vi.advanceTimersByTimeAsync(1_999);
+        expect(reload).toHaveBeenCalledTimes(0);
+        await vi.advanceTimersByTimeAsync(1);
+        expect(reload).toHaveBeenCalledTimes(1);
+        await vi.advanceTimersByTimeAsync(4_000);
+        expect(reload).toHaveBeenCalledTimes(2);
+        await vi.advanceTimersByTimeAsync(8_000);
+        expect(reload).toHaveBeenCalledTimes(3);
+        await vi.advanceTimersByTimeAsync(15_000);
+        expect(reload).toHaveBeenCalledTimes(4);
+
+        // 予算を使い切ったら以後は発火しない
+        await vi.advanceTimersByTimeAsync(120_000);
+        expect(reload).toHaveBeenCalledTimes(4);
+    });
+
+    it("サムネイルが付いたら監視から外れ、空になったら再取得を止める", async () => {
+        const reload = vi.fn(async () => {});
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+        scheduler.watch("take-a");
+
+        await vi.advanceTimersByTimeAsync(2_000);
+        expect(reload).toHaveBeenCalledTimes(1);
+
+        scheduler.sync(makeManual([makeTake({ client_take_id: "take-a", has_thumbnail: true })]));
+        await vi.advanceTimersByTimeAsync(120_000);
+
+        expect(reload).toHaveBeenCalledTimes(1);
+    });
+
+    it("監視中のテイクが manual から消えた (削除された) 場合も監視から外れる", async () => {
+        const reload = vi.fn(async () => {});
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+        scheduler.watch("take-a");
+
+        await vi.advanceTimersByTimeAsync(2_000);
+        scheduler.sync(makeManual([]));
+        await vi.advanceTimersByTimeAsync(120_000);
+
+        expect(reload).toHaveBeenCalledTimes(1);
+    });
+
+    it("merge: 2 本目の watch() が 1 本目の監視を消さず、試行予算がリセットされる", async () => {
+        const reload = vi.fn(async () => {});
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+        scheduler.watch("take-a");
+
+        // 3 回発火させる (残り 1 回)
+        await vi.advanceTimersByTimeAsync(2_000 + 4_000 + 8_000);
+        expect(reload).toHaveBeenCalledTimes(3);
+
+        // 新しい ID で予算が戻る = 最後に追加された ID から数えて**ちょうど 4 回**
+        // (旧予算で予約済みだった発火は watch が持ち越さない)
+        scheduler.watch("take-b");
+        await vi.advanceTimersByTimeAsync(2_000 + 4_000 + 8_000 + 15_000);
+        expect(reload).toHaveBeenCalledTimes(7);
+        await vi.advanceTimersByTimeAsync(120_000);
+        expect(reload).toHaveBeenCalledTimes(7);
+
+        // 1 本目も監視され続けている (sync で両方が生き残る)
+        scheduler.sync(
+            makeManual([
+                makeTake({ id: 10, client_take_id: "take-a" }),
+                makeTake({ id: 11, client_take_id: "take-b", has_thumbnail: true }),
+            ]),
+        );
+        expect(reload).toHaveBeenCalledTimes(7); // 予算切れのため即時発火はしない
+    });
+
+    it("既に監視中の ID を再度 watch しても試行予算は戻らない", async () => {
+        const reload = vi.fn(async () => {});
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+        scheduler.watch("take-a");
+
+        await vi.advanceTimersByTimeAsync(2_000 + 4_000 + 8_000 + 15_000);
+        expect(reload).toHaveBeenCalledTimes(4);
+
+        scheduler.watch("take-a"); // 早期 return (予算は戻らない)
+        await vi.advanceTimersByTimeAsync(120_000);
+        expect(reload).toHaveBeenCalledTimes(4);
+    });
+
+    it("single-flight: 前回の reload が解決するまで次を始めない", async () => {
+        let resolveReload: (() => void) | null = null;
+        const reload = vi.fn(
+            () =>
+                new Promise<void>((resolve) => {
+                    resolveReload = resolve;
+                }),
+        );
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+        scheduler.watch("take-a");
+
+        await vi.advanceTimersByTimeAsync(2_000);
+        expect(reload).toHaveBeenCalledTimes(1);
+
+        // 解決するまでは次の試行が始まらない
+        await vi.advanceTimersByTimeAsync(120_000);
+        expect(reload).toHaveBeenCalledTimes(1);
+
+        expect(resolveReload).not.toBeNull();
+        (resolveReload as unknown as () => void)();
+        await vi.advanceTimersByTimeAsync(4_000);
+        expect(reload).toHaveBeenCalledTimes(2);
+    });
+
+    it("pause() 中は発火せず、resume() で残り試行だけ再開する", async () => {
+        const reload = vi.fn(async () => {});
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+        scheduler.watch("take-a");
+
+        scheduler.pause();
+        await vi.advanceTimersByTimeAsync(120_000);
+        expect(reload).toHaveBeenCalledTimes(0);
+
+        scheduler.resume();
+        // 予算は 1 回消費済みなので残りは 3 回
+        await vi.advanceTimersByTimeAsync(4_000 + 8_000 + 15_000);
+        expect(reload).toHaveBeenCalledTimes(3);
+        await vi.advanceTimersByTimeAsync(120_000);
+        expect(reload).toHaveBeenCalledTimes(3);
+    });
+
+    it("stop() 後に到着した reload の完了は再スケジュールしない", async () => {
+        let resolveReload: (() => void) | null = null;
+        const reload = vi.fn(
+            () =>
+                new Promise<void>((resolve) => {
+                    resolveReload = resolve;
+                }),
+        );
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+        scheduler.watch("take-a");
+
+        await vi.advanceTimersByTimeAsync(2_000);
+        expect(reload).toHaveBeenCalledTimes(1);
+
+        scheduler.stop();
+        (resolveReload as unknown as () => void)();
+        await vi.advanceTimersByTimeAsync(120_000);
+
+        expect(reload).toHaveBeenCalledTimes(1);
+        // stop 後の watch / sync も無効
+        scheduler.watch("take-c");
+        await vi.advanceTimersByTimeAsync(120_000);
+        expect(reload).toHaveBeenCalledTimes(1);
+    });
+
+    it("reload が reject しても監視対象を消さず、残り試行へ進む", async () => {
+        const reload = vi.fn(() => Promise.reject(new Error("network")));
+        const scheduler = new ThumbnailRefreshScheduler(reload);
+        scheduler.watch("take-a");
+
+        await vi.advanceTimersByTimeAsync(2_000 + 4_000 + 8_000 + 15_000);
+        expect(reload).toHaveBeenCalledTimes(4);
+    });
+});
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 89bd554..05ae528 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -12,14 +12,14 @@ import { VIDEO_MANUAL_STATUS_LABELS, type VideoManualStatus } from "@/types/manu
  * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
  */
 
-const { routerReloadMock, enqueueMock, autoDownloadRunMock, navigateToPanelMock } = vi.hoisted(
-    () => ({
+const { routerReloadMock, enqueueMock, resumeMock, autoDownloadRunMock, navigateToPanelMock } =
+    vi.hoisted(() => ({
         routerReloadMock: vi.fn(),
         enqueueMock: vi.fn(),
+        resumeMock: vi.fn(),
         autoDownloadRunMock: vi.fn(),
         navigateToPanelMock: vi.fn(),
-    }),
-);
+    }));
 
 // 撮影パネルへのナビゲーション (F-1-03) は panel-navigation.ts が副作用ごと担い、
 // その抑止契約は panel-navigation.test.ts が固定する。ここで固定するのは
@@ -58,9 +58,7 @@ vi.mock("@/lib/capture/upload-queue", async (importOriginal) => ({
     UploadQueue: class {
         quotaMessage: string | null = null;
         enqueue = enqueueMock;
-        async resume(): Promise<unknown[]> {
-            return [];
-        }
+        resume = resumeMock;
     },
 }));
 
@@ -111,6 +109,7 @@ function makeAdoptedManual(): CaptureManualDetail {
         captured_at: "2026-07-11T00:00:00Z",
         sort_order: 0,
         downloaded: false,
+        has_thumbnail: false,
         playback_url: "https://s3.example.test/take-900.mp4?sig=1",
         download_ack_token: "ack-900",
     };
@@ -145,7 +144,14 @@ const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();
 
 beforeEach(() => {
     routerReloadMock.mockReset();
+    // reload は Inertia の onFinish で解決する契約。既定では即座に完了させる
+    // (single-flight の in-flight が張り付いたままにならないようにする)
+    routerReloadMock.mockImplementation((options: { onFinish?: () => void }) => {
+        options.onFinish?.();
+    });
     enqueueMock.mockReset();
+    resumeMock.mockReset();
+    resumeMock.mockResolvedValue([]);
     enqueueMock.mockImplementation((item: { clientTakeId: string }) =>
         Promise.resolve({ status: "uploaded", clientTakeId: item.clientTakeId }),
     );
@@ -222,7 +228,10 @@ describe("Capture/Show カメラフォールバック", () => {
         expect(arg.blob).toBe(file);
         expect(arg.contentType).toBe("video/mp4");
         expect(arg.durationMs).toBeNull();
-        expect(routerReloadMock).toHaveBeenCalledWith({ only: ["manual"] });
+        expect(routerReloadMock).toHaveBeenCalledWith({
+            only: ["manual"],
+            onFinish: expect.any(Function),
+        });
     });
 
     it("(e) permission_denied 以外 (device_missing) は汎用の切替 notice を出す", async () => {
@@ -281,7 +290,10 @@ describe("Capture/Show 採用済みテイク自動 DL 結線 (T051)", () => {
         });
         expect(autoDownloadRunMock).toHaveBeenCalledWith(adoptedProps.manual);
         await vi.waitFor(() => {
-            expect(routerReloadMock).toHaveBeenCalledWith({ only: ["manual"] });
+            expect(routerReloadMock).toHaveBeenCalledWith({
+            only: ["manual"],
+            onFinish: expect.any(Function),
+        });
         });
     });
 
@@ -471,3 +483,47 @@ describe("Capture/Show マニュアル詳細への復路 (T155)", () => {
         },
     );
 });
+
+/*
+ * サムネイル反映の**ページ配線** (T183 / S10)。
+ *
+ * 有界性・停止条件そのものは thumbnail-refresh.test.ts が固定する。
+ * ここで見るのは「Show がどの outcome を watch へ渡し、reload を何回通したか」だけである
+ * (helper だけでは、将来 Show がキュー再開経路の watch を落としても緑のままになる)。
+ */
+describe("Capture/Show サムネイル反映の配線 (T183)", () => {
+    it("キュー再開で uploaded が複数でも reload は 1 回だけ通る (single-flight)", async () => {
+        stubCameraSupported(false);
+        resumeMock.mockResolvedValue([
+            { status: "uploaded", clientTakeId: "q1" },
+            { status: "uploaded", clientTakeId: "q2" },
+            { status: "queued", clientTakeId: "q3", reason: "offline" },
+        ]);
+
+        render(CaptureShow, { props: baseProps });
+        await fireEvent(window, new Event("online"));
+
+        await vi.waitFor(() => {
+            expect(resumeMock).toHaveBeenCalled();
+        });
+        await vi.waitFor(() => {
+            expect(routerReloadMock).toHaveBeenCalledTimes(1);
+        });
+    });
+
+    it("uploaded が 1 件も無いキュー再開では reload しない", async () => {
+        stubCameraSupported(false);
+        resumeMock.mockResolvedValue([
+            { status: "queued", clientTakeId: "q1", reason: "offline" },
+            { status: "quota_exceeded", clientTakeId: "q2", message: "上限です" },
+        ]);
+
+        render(CaptureShow, { props: baseProps });
+        await fireEvent(window, new Event("online"));
+
+        await vi.waitFor(() => {
+            expect(resumeMock).toHaveBeenCalled();
+        });
+        expect(routerReloadMock).not.toHaveBeenCalled();
+    });
+});

```

## design system 参照 (DESIGN.md 抜粋)

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
- 各 toast は `

## 触れた atomic ディレクトリ

- `resources/js/components/features/capture/TakeStrip.svelte` (features 層。atoms/Button・Badge を利用)
- `resources/js/pages/Capture/Show.svelte` (pages 層)
- `resources/js/lib/capture/thumbnail-refresh.ts` (lib。component 層ではない)



## 検証コマンド結果 (全て green)

- composer test: 5390 tests / 5388 passed / 2 skipped / 23160 assertions / 0 failed
- composer phpstan: level 10 No errors
- vendor/bin/pint --test: passed
- pnpm lint / pnpm typecheck: passed
- pnpm test: 141 files / 1586 tests passed
- pnpm build / pnpm typecheck:packages / pnpm build:packages / pnpm test:packages: passed
- scripts/bug-hunt-inventory-check.sh: exit 0 (画面 69 / 操作 79)

新規/更新したテスト:
- tests/Feature/Capture/TakeThumbnailGenerationTest.php (新規。preflight 配置の behavioral 固定を含む)
- tests/Feature/Capture/TakeThumbnailEndpointTest.php (新規。IDOR / 状態秘匿)
- tests/Feature/Capture/TakeThumbnailQueueAtomicityTest.php (新規。tx level 観測)
- tests/Unit/Capture/FfmpegTakeThumbnailExtractorTest.php (新規。Process::fake)
- tests/js/lib/capture/thumbnail-refresh.test.ts (新規。fake timers)
- 既存更新: StorageUsageServiceTest / TakeObjectStorageTest / FakeStorageRouteTest /
  FakeStorageContractTest / TakeRegistrationTest / TakeRegistrationS3SurfaceTest /
  CaptureManualBrowsingTest / TakeStrip.test.ts / CaptureShow.test.ts /
  各種 deny-by-default 目録 (JobExecutionDedupInventory / QueuedJobLease / DirectFetch /
  S3Surface / NestedRouteDefense)
