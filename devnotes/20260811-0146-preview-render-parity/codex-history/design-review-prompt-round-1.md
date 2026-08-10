【アプリの使命 (North Star) — AGENTS.md より転記】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【思考原則 — AGENTS.md より転記】

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

【禁止事項 — AGENTS.md より転記】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク (RefreshDatabase グローバル適用 + --parallel)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠: `atoms/molecules/organisms/features/templates/pages` の責務分離・単方向 import に沿った配置か。アイコンは Lucide 前提で SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【追加の判断材料】
- 本件は bug-hunt で**実ブラウザ再現済み**の finding F-1-01 (High) への対応である。
- 概念設計は同一セッションの前段で APPROVED 済み (プレビューはブロックせず告知する第三の道)。
- 「過剰に作らない」(思考原則 2) を重視する。一般化・機構追加の提案は、finding を閉じるのに
  必要かどうかを明示して判断すること。

---

## 詳細設計書

# 詳細設計: preview-render-parity (プレビューと完成生成の判断基準を揃える)

> 対応 finding: bug-hunt run `20260811-003230` **F-1-01 (High)** (実ブラウザで再現済み)。
> 概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex Round 2 で APPROVED)
> 実査ブリーフ: [`recon-brief.md`](./recon-brief.md)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より転記）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

> 本設計は **禁止事項 8 が最重要**である。「未撮影があるからプレビューを押させない」は
> 本設計の否定であり、実装がそちらへ倒れたら設計違反として差し戻す。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）/ **RefreshDatabase** はグローバル適用・`--parallel` 実行
  （個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- `composer fix`（Pint）/ `pnpm lint:fix`
- フロントは Svelte 5 runes + **DESIGN.md token のみ**(hex 直書きを増やさない)、
  component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 前提として確認した現行コード（実在確認済み）

| ファイル | 位置 | 事実 |
|---|---|---|
| `app/Services/Manual/RenderJobService.php` | L363-377 | render の 422 判定 `assertAllCutsHaveAdoptedReadyTakes()` |
| 同上 | L126-168 | `triggerPreview()`。**採用テイクの判定を持たない** |
| `app/Services/Manual/RenderPipeline.php` | L240-273 | `clipSpecFor()`。preview のみ `RenderClipSource::Placeholder` |
| 同上 | L280-347 | `finalize()` (terminal tx。`render_jobs` → `video_manuals` の順でロック) |
| 同上 | L437-443 | `updateProgress()` は `where status=running` の条件付き UPDATE |
| `app/Services/Render/FfmpegVideoComposer.php` | L148-163 | 黒背景プレースホルダ (`color=black`, `preview_placeholder_seconds`=3 秒) |
| `app/Services/Manual/CutSequencer.php` | L24-49 | 表示順 + ラベル (`手順N` / `急所N-M`)、`adoptedTake` を eager load |
| `app/Http/Controllers/Projects/VideoManualController.php` | L113-153 | `Manuals/Show` の props (`render` キー) |
| `resources/js/components/features/manual/RenderPanel.svelte` | 全体 | プレビュー/完成の UI。ボタンは disabled にしない方針が既にコメントで明示 |
| `app/DataTransferObjects/Manual/RenderJobData.php` | 全体 | 201 / ポーリング / props 共用の DTO |
| `app/Models/RenderJob.php` | L45-62 | `$fillable` を持たず明示代入のみ。cast 宣言 |
| `tests/Architecture/ScenarioWritePathInventoryTest.php` | L83-107 | `adopted_take_id` の deny-by-default ファイル allowlist (**本設計の gate の先例**) |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 判定の単一化 (`AdoptedReadyTakeCoverage`) | `app/Services/Manual/AdoptedReadyTakeCoverage.php` (新) / `app/DataTransferObjects/Manual/TakeCoverageData.php` (新) / `RenderJobService.php` / `RenderPipeline.php` | High |
| 2 | 事前告知 (props + UI 注記) | `VideoManualController.php` / `resources/js/types/manual.ts` / `pages/Manuals/Show.svelte` / `features/manual/RenderPanel.svelte` | High |
| 3 | 事後説明 (`placeholder_cut_count`) | migration (新) / `RenderJob.php` / `RenderJobFactory.php` / `RenderManifest.php` / `RenderResult.php` / `RenderPipeline.php` / `RenderJobData.php` / `types/manual.ts` / `RenderPanel.svelte` | High |
| 4 | 再発防止 (Architecture gate) | `app/Enums/Security/AdoptedTakeReferenceKind.php` (新) / `app/Support/Security/AdoptedTakeReferenceInventory.php` (新) / `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` (新) | High |
| 5 | ドキュメント同期 | `docs/architecture.md` / `AGENTS.md` (ドメイン規約) | Medium |

---

## 施策 1: 判定の単一化 (`AdoptedReadyTakeCoverage`)

### 変更箇所

- 新規: `app/Services/Manual/AdoptedReadyTakeCoverage.php`
- 新規: `app/DataTransferObjects/Manual/TakeCoverageData.php`
- 変更: `app/Services/Manual/RenderJobService.php` (L84-85, L363-377)
- 変更: `app/Services/Manual/RenderPipeline.php` (L240-258)

### 波及変更

- TypeScript 型定義: 施策 2 で `TakeCoverageProps` を追加（本施策単体では無し）
- API Resource/DTO: `TakeCoverageData` 新設。既存 Resource の shape 変更は無し
- テストファイル: `tests/Feature/Manual/RenderTriggerTest.php` は **422 の文言・キーを変えないため更新不要**
  （変更が必要になったらそれは設計違反 = 契約を壊している合図）

### 現行コード

```php
// RenderJobService.php L363-377
private function assertAllCutsHaveAdoptedReadyTakes(array $ordered): void
{
    $missing = [];
    foreach ($ordered as $entry) {
        $take = $entry->cut->adoptedTake;
        if ($take === null || $take->status !== TakeStatus::Ready) {
            $missing[] = $entry->label;
        }
    }
    if ($missing !== []) {
        throw ValidationException::withMessages([
            'takes' => ['採用テイクが未設定のカットがあります: '.implode('、', $missing)],
        ]);
    }
}

// RenderPipeline.php L240-247 (抜粋)
$take = $cut->adoptedTake;
if ($take === null || $take->status !== TakeStatus::Ready) {
    if ($job->kind === RenderKind::Render) { throw new LogicException(...); }
    return new RenderClipSpec(source: RenderClipSource::Placeholder, ...);
}
```

**同じ式が 2 ファイルに複製され、preview トリガーには存在しない** — これが F-1-01 の構造的原因。

### 変更後コード

```php
// app/DataTransferObjects/Manual/TakeCoverageData.php (新規)
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

/**
 * 採用テイクの充足状況 (「採用済みかつ ready のテイクを持つカットが揃っているか」)。
 * render の 422 と、詳細画面の事前告知が**同じ値**を見るための唯一の shape。
 *
 * ★ props 用の toProps() はラベルを PROP_LABEL_LIMIT 件で打ち切るが、
 *   missingCount は**常に全件数**である (件数を打ち切ると嘘になる)。
 */
final readonly class TakeCoverageData
{
    /** props に載せるラベルの上限 (全 67 カット分の文字列を毎描画で送らない) */
    public const int PROP_LABEL_LIMIT = 10;

    /**
     * @param  list<string>  $missingLabels  未充足カットの表示ラベル (CutSequencer の表示順)
     */
    public function __construct(
        public int $totalCuts,
        public array $missingLabels,
    ) {}

    public function missingCount(): int
    {
        return count($this->missingLabels);
    }

    /**
     * @return array{total_cuts: int, missing_count: int, missing_labels: list<string>}
     */
    public function toProps(): array
    {
        return [
            'total_cuts' => $this->totalCuts,
            'missing_count' => $this->missingCount(),
            'missing_labels' => array_slice($this->missingLabels, 0, self::PROP_LABEL_LIMIT),
        ];
    }
}
```

```php
// app/Services/Manual/AdoptedReadyTakeCoverage.php (新規)
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Render\OrderedCut;
use App\DataTransferObjects\Manual\TakeCoverageData;
use App\Enums\Manual\TakeStatus;
use App\Models\Cut;
use App\Models\VideoManual;

/**
 * 「採用済みかつ ready のテイクを持つか」の**唯一の判定**。
 *
 * render (422 でブロック) と preview (ブロックせず告知) は**制裁が違うだけで基準は同じ**である。
 * 基準がファイルをまたいで複製されると再び乖離する (bug-hunt F-1-01 の構造的原因) ため、
 * 述語 isMissing() をここ 1 箇所に閉じ、`AdoptedReadyTakeCriterionInventoryTest` が
 * deny-by-default で「他ファイルが同じ判定を書き直していないこと」を機械検査する。
 *
 * 読み取り専用 (cuts / takes / status を 1 バイトも書かない)。
 */
final class AdoptedReadyTakeCoverage
{
    /**
     * 唯一の述語。**この式を他所へ写経しない**。
     *
     * 前提: $cut は adoptedTake を eager load 済みで呼ぶこと
     * (CutSequencer::orderedWithLabels が `with('adoptedTake')` を張っている)。
     * lazy load でも結果は同じだが N+1 になる。
     */
    public static function isMissing(Cut $cut): bool
    {
        $take = $cut->adoptedTake;

        return $take === null || $take->status !== TakeStatus::Ready;
    }

    /** 表示順カット列からの集計 (トリガー tx が既に持っている列を再利用する経路) */
    public static function fromOrdered(array $ordered): TakeCoverageData
    {
        $missing = [];
        foreach ($ordered as $entry) {
            if (self::isMissing($entry->cut)) {
                $missing[] = $entry->label;
            }
        }

        return new TakeCoverageData(totalCuts: count($ordered), missingLabels: $missing);
    }

    /** manual からの集計 (詳細画面 props の経路) */
    public static function for(VideoManual $manual): TakeCoverageData
    {
        return self::fromOrdered(CutSequencer::orderedWithLabels($manual));
    }
}
```

`fromOrdered` の PHPDoc には `@param list<OrderedCut> $ordered` を付ける (PHPStan level 10)。

```php
// RenderJobService.php — 判定を委譲し、422 の**文言と例外キーは一字も変えない**
private function assertAllCutsHaveAdoptedReadyTakes(array $ordered): void
{
    $coverage = AdoptedReadyTakeCoverage::fromOrdered($ordered);
    if ($coverage->missingCount() === 0) {
        return; // アーリーリターン
    }

    throw ValidationException::withMessages([
        'takes' => ['採用テイクが未設定のカットがあります: '.implode('、', $coverage->missingLabels)],
    ]);
}
```

```php
// RenderPipeline.php clipSpecFor() — 述語のみ委譲 (分岐の意味は変えない)
private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderClipSpec
{
    if (AdoptedReadyTakeCoverage::isMissing($cut)) {
        if ($job->kind === RenderKind::Render) {
            throw new LogicException("render job {$job->id}: 採用テイク欠落 ({$label})");
        }

        return new RenderClipSpec(cutId: $cut->id, label: $label,
            source: RenderClipSource::Placeholder, takeVideoPath: null, stillDisplaySeconds: null,
            subtitlePrimary: $cut->subtitle_primary, subtitleSecondary: $cut->subtitle_secondary);
    }

    $take = $cut->adoptedTake;
    Assert::notNull($take, 'isMissing() が false なら採用テイクは必ず存在する');
    // ... 以降は現行どおり ($take->video_path 等)
}
```

> `Assert::notNull` を挟むのは PHPStan level 10 のため（述語が false でも静的には
> `?Take` のまま）。**述語の再実装ではない**（`TakeStatus::Ready` を参照しない）。

`triggerPreview()` は**判定を追加しない**（ブロックしないので判定不要）。preview が使う
coverage は詳細画面 props 側 (施策 2) と manifest 側 (施策 3) で消費される。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`bool` / `TakeCoverageData`）
- [x] null 安全（`Assert::notNull` で `?Take` を絞る。`@phpstan-ignore` は使わない）
- [x] DTO を返している（配列返却は `toProps()` のみで shape を array-shape 型で宣言）
- [x] Generics: `@param list<OrderedCut>` / `@param list<string>` / `@return array{...}`

### リスク

- `RenderJobService::trigger()` は `$ordered` を 1 回だけ作り `fromOrdered` に渡すため
  **クエリは増えない**。詳細画面 props (施策 2) では `for()` が cuts + adoptedTake の
  2 クエリを増やす（67 カット規模で無視できる。N+1 は `with('adoptedTake')` で回避済み）。
- 422 の文言・キーを変えないので既存クライアント (RenderPanel の alert) に影響なし。

---

## 施策 2: 事前告知 (props + RenderPanel の注記)

### 変更箇所

- `app/Http/Controllers/Projects/VideoManualController.php` L134-149 (`render` props)
- `resources/js/types/manual.ts` (`RenderProps` / `TakeCoverageProps`)
- `resources/js/pages/Manuals/Show.svelte` L130-138 (props 受け渡し)
- `resources/js/components/features/manual/RenderPanel.svelte` (Props + 注記の描画)

### 波及変更

- TypeScript 型定義: `TakeCoverageProps` 新設、`RenderProps.coverage` 追加（**必須**フィールド）
- API Resource/DTO: `TakeCoverageData::toProps()`（Inertia props であり JSON API ではない）
- テストファイル: `tests/js/pages/ManualsShow.test.ts`（props 配線）/
  `tests/js/components/features/manual/RenderPanel.test.ts`（描画）/
  `tests/Feature/Manual/PreviewCoverageParityTest.php`（props の内容）

### 変更後コード

```php
// VideoManualController::show() の 'render' キー
'render' => [
    'job' => ...,
    'previewJob' => ...,
    'playbackJobId' => ...,
    // 未撮影 (採用済み ready テイクを持たない) カットの充足状況。
    // render の 422 と同じ述語から出す = 判断基準を 1 箇所に置く (F-1-01)
    'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
],
```

```ts
// resources/js/types/manual.ts
/** PHP: App\DataTransferObjects\Manual\TakeCoverageData::toProps() と対 */
export interface TakeCoverageProps {
    /** カット総数 */
    total_cuts: number;
    /** 未撮影カット数 (**打ち切らない全件数**) */
    missing_count: number;
    /** 未撮影カットの表示ラベル (先頭 10 件で打ち切られる。件数は missing_count が正) */
    missing_labels: string[];
}

export interface RenderProps {
    job: RenderJobProps | null;
    previewJob: RenderJobProps | null;
    playbackJobId: number | null;
    /** 描画時点のスナップショット (常に最新ではない。最新の実績は job.placeholder_cut_count) */
    coverage: TakeCoverageProps;
}
```

```svelte
<!-- RenderPanel.svelte: preview ブロックの先頭 (ボタン群の下・進捗の上) -->
{#if canManage && coverage.missing_count > 0}
    <div data-testid="preview-coverage-note">
        <Alert type="warning" title="未撮影のカットがあります">
            {coverage.missing_count} / {coverage.total_cuts} 件のカットにテイクが採用されていません
            ({missingLabelSummary})。プレビューは生成できますが、該当区間は黒背景になります。
            完成動画の生成にはすべてのカットの撮影・採用が必要です。
        </Alert>
    </div>
{/if}
```

```ts
// 先頭 10 件 + 残数の要約 (props 側で打ち切られている前提を UI 側にも書く)
const missingLabelSummary = $derived(
    coverage.missing_labels.length < coverage.missing_count
        ? `${coverage.missing_labels.join("、")} ほか ${coverage.missing_count - coverage.missing_labels.length} 件`
        : coverage.missing_labels.join("、"),
);
```

- **ボタンは `disabled` にしない / 確認ダイアログも足さない**（禁止事項 8・概念設計 判断 1）。
- 色・余白は既存 `Alert` atom (`type="warning"`) と既存 utility class のみ。
  **hex 直書きを 1 つも増やさない**。新規 atom / molecule も作らない
  （Atomic Design の層をまたがない = `features/manual` 内で完結）。

### PHPStan適合チェック

- [x] `toProps()` の array-shape が宣言されている（Inertia props に `mixed` を渡さない）
- [x] `AdoptedReadyTakeCoverage::for()` は `VideoManual` を受け `TakeCoverageData` を返す

### リスク

- **props は描画時点のスナップショット**。別タブ・別ユーザーの撮影で古くなる
  （押下は止めないので詰みにはならない）。「常に最新」とは書かない。
- `coverage` を必須フィールドにするため、`Manuals/Show` を描画する他経路があれば
  そこも props を足す必要がある → `InertiaRenderPageExistsInvariantTest` の対象外なので
  **実装時に `grep "Manuals/Show"` で経路が 1 本 (`VideoManualController::show`) だけであることを確認する**。

---

## 施策 3: 事後説明 (`render_jobs.placeholder_cut_count`)

### 変更箇所

- 新規 migration: `database/migrations/2026_08_11_xxxxxx_add_placeholder_cut_count_to_render_jobs_table.php`
- `app/Models/RenderJob.php`（`@property` + cast）
- `database/factories/RenderJobFactory.php`（既定 `null`）
- `app/DataTransferObjects/Manual/Render/RenderManifest.php`（`placeholderCutCount()` メソッド）
- `app/DataTransferObjects/Manual/Render/RenderResult.php`（`placeholderCutCount` フィールド）
- `app/Services/Manual/RenderPipeline.php` L110-114（`RenderResult` 生成）/ L316-319（finalize の書き込み）
- `app/DataTransferObjects/Manual/RenderJobData.php`（DTO フィールド + `toArray()`）
- `resources/js/types/manual.ts`（`RenderJobProps`）/ `RenderPanel.svelte`（注記）

### 波及変更

- TypeScript 型定義: `RenderJobProps.placeholder_cut_count: number | null`（**必須**）
- API Resource/DTO: `RenderJobResource` の shape に 1 キー追加（201 / ポーリング 200 の両方）
- テストファイル: `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`
  （応答 shape の期待値に新キー）、`tests/Feature/Manual/RenderPipelineTest.php`
  （`RenderResult` 生成の検証がある場合）、`tests/js/components/.../RenderPanel.test.ts`
  （`renderJobBody()` ヘルパに新キー）

### 変更後コード

```php
// migration
public function up(): void
{
    Schema::table('render_jobs', function (Blueprint $table): void {
        // その動画が実際に含んだプレースホルダ (黒背景) クリップ数。
        // null = 「その動画について言えることが無い」(既存行 / queued / running / finalize 未到達の failed)。
        // 索引は張らない (検索経路が無く、常に単一行の表示に使う)。
        $table->unsignedInteger('placeholder_cut_count')->nullable()->after('output_path');
    });
}

public function down(): void
{
    Schema::table('render_jobs', function (Blueprint $table): void {
        $table->dropColumn('placeholder_cut_count');
    });
}
```

```php
// RenderManifest (クリップ列から導出する = 二重管理を作らない)
/** プレースホルダ (黒背景) に落ちたクリップ数。読み取り一貫性の確定点である clips から導く */
public function placeholderCutCount(): int
{
    return count(array_filter(
        $this->clips,
        static fn (RenderClipSpec $clip): bool => $clip->source === RenderClipSource::Placeholder,
    ));
}
```

```php
// RenderResult に 1 フィールド追加 (生成は RenderPipeline::run の 1 箇所のみ)
public function __construct(
    public string $outputPath,
    public array $clipDurationsMs,
    public int $totalDurationMs,
    /** manifest 由来。**現在の manual 状態から数え直さない** (生成物の説明であるため) */
    public int $placeholderCutCount,
) {}
```

```php
// RenderPipeline::run() L110-114
$result = new RenderResult(
    outputPath: $manifest->outputKey,
    clipDurationsMs: $composed->clipDurationsMs,
    totalDurationMs: $composed->totalDurationMs,
    placeholderCutCount: $manifest->placeholderCutCount(),
);

// RenderPipeline::finalize() — job 行ロック済みの terminal tx 内 (L316 付近)
$locked->status = JobStatus::Succeeded;
$locked->progress = 100;
$locked->output_path = $result->outputPath;
$locked->placeholder_cut_count = $result->placeholderCutCount; // manifest 由来の実績値
$locked->save();
```

**書き込み位置を finalize にする理由（ロック順序）**: 値が確定するのは buildManifest だが、
そこは `video_manuals` を先にロックしている。同 tx で `render_jobs` を UPDATE すると
グローバル順 `render_jobs → video_manuals` の**逆順取得**になり、`finalize` / `failJob` と
循環待ちを構成しうる。finalize は既に `render_jobs → video_manuals` の正順でロック済みなので、
そこに 1 列足すのが唯一の順序安全な置き場である（`updateProgress` の条件付き UPDATE と同様に、
terminal 化後の書き戻しも起きない = finalize の `status !== Running` guard が先に return する）。

```php
// RenderJobData
public function __construct(
    ...,
    public ?int $placeholderCutCount,   // 追加
) {}

// fromJob(): placeholderCutCount: $job->placeholder_cut_count
// toArray(): 'placeholder_cut_count' => $this->placeholderCutCount,
// @return array{..., placeholder_cut_count: int|null} に更新 (Resource の docblock も同じ)
```

```svelte
<!-- RenderPanel.svelte: 再生中プレビューの説明 (video の直上) -->
{#if playbackNote !== null}
    <p class="text-caption text-text-secondary" data-testid="preview-placeholder-note">
        このプレビューは {playbackNote} 件のカットが未撮影のため、その区間が黒背景になっています。
    </p>
{/if}
```

```ts
/**
 * 再生対象の動画そのものについて言える件数だけを出す。
 * 条件を厳しくしているのは「別世代の job の件数で今の動画を説明しない」ため
 * (説明できないときは**黙る**。誤った説明より無表示を選ぶ)。
 */
const playbackNote = $derived(
    preview !== null &&
    preview.id === playbackId &&
    preview.status === "succeeded" &&
    preview.placeholder_cut_count !== null &&
    preview.placeholder_cut_count > 0
        ? preview.placeholder_cut_count
        : null,
);
```

### PHPStan適合チェック

- [x] 追加フィールドは `?int`（DB nullable と一致）。cast は `'placeholder_cut_count' => 'integer'`
- [x] `RenderJobData::toArray()` / `RenderJobResource::toArray()` の array-shape docblock を同時更新
- [x] `RenderManifest::placeholderCutCount()` は `int` を返す（`array_filter` の closure に型宣言）

### リスク

- **既存行はすべて `null`**（backfill しない）。UI は `null` で注記を出さないため、
  過去のプレビューには何も表示されない = **嘘をつかない側に倒す**（0 と null を同一視しない）。
- `RenderJobResource` の shape に 1 キー増える。**同一オリジン XHR の自前クライアントのみ**が
  消費者であり外部公開 API ではない（`routes/web.php` の web group）。
- `RenderResult` のコンストラクタ引数追加は名前付き引数で呼ばれており生成箇所は 1 つ。

---

## 施策 4: 再発防止 (Architecture gate)

### 変更箇所

- 新規: `app/Enums/Security/AdoptedTakeReferenceKind.php`
- 新規: `app/Support/Security/AdoptedTakeReferenceInventory.php`
- 新規: `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`

### 不変条件（この gate が守るもの）

> **「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
> `AdoptedReadyTakeCoverage` ただ 1 ファイルである。**
> `adoptedTake` に触れる app/ 配下のファイルは、区分と 30 文字以上の根拠を付けて
> 目録に登録しなければならない（deny-by-default）。

### 区分 enum

```php
enum AdoptedTakeReferenceKind: string
{
    /** 判定式 (adoptedTake と TakeStatus::Ready の同居) の実体。**1 ファイルのみ** */
    case Canonical = 'canonical';
    /** 判定を AdoptedReadyTakeCoverage へ委譲し、自前の ready 判定を持たない参照 */
    case DelegatedToCoverage = 'delegated_to_coverage';
    /** relation 宣言・eager load 指定など、判定を含まない構造上の参照 */
    case RelationWiring = 'relation_wiring';
    /**
     * ready 状態を見ない別基準 (「採用テイクが紐づいているか」だけを数える面)。
     * 統合してよいという意味ではなく、**別概念として意図的に残していること**の記録。
     */
    case DifferentCriterion = 'different_criterion';
}
```

### 目録（実装時の初期値。現行コードの実在確認済み）

| ファイル (app/ 相対) | 区分 | 根拠の要点 |
|---|---|---|
| `Services/Manual/AdoptedReadyTakeCoverage.php` | Canonical | 判定式の実体。render の 422 と preview の告知が同じ述語を通るための唯一の場所 |
| `Services/Manual/CutSequencer.php` | RelationWiring | 表示順の取得で `with('adoptedTake')` を張るのみ。判定式を持たない |
| `Services/Manual/RenderJobService.php` | DelegatedToCoverage | 尺上限計算で `adoptedTake->duration_ms` を読むだけ。充足判定は coverage へ委譲済み |
| `Services/Manual/RenderPipeline.php` | DelegatedToCoverage | clipSpecFor が `isMissing()` を呼ぶ。素材パス取得のため take 実体を読む |
| `Models/Cut.php` | RelationWiring | belongsTo relation の宣言 |
| `DataTransferObjects/Capture/CaptureManualDetailData.php` | DifferentCriterion | 撮影ナビの表示用に採用テイクを読む。ready 判定はしない |
| `Http/Controllers/Capture/CaptureManualController.php` | DifferentCriterion | `whereHas('adoptedTake')` の件数集計。ready を見ない別基準 |
| `Services/Dashboard/DashboardService.php` | DifferentCriterion | `whereDoesntHave('adoptedTake')` の撮影待ち集計。ready を見ない別基準 |
| `Console/Commands/Development/PipelineSmokeCommand.php` | DifferentCriterion | bug-hunt の通し確認で未採用件数を数えるのみ |

> 実装時に `rg -n "adoptedTake" app/` を再実行し、**列挙が実在と一致すること**を確認してから
> 目録を確定する（この表は設計時点のスナップショットである）。

### テスト（`tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`）

走査は `Tests\Support\PhpTokenScan::normalize()` を使う（既存の token 走査基盤。
コメント・docblock 内の出現は数えない）。検出は 2 系統:

- **検出 A（参照の母集団）**: 識別子 `adoptedTake`（プロパティフェッチ）または
  文字列リテラル `'adoptedTake'`（`with` / `whereHas` / `whereDoesntHave` / `doesntHave` 引数）を
  含む app/ 配下の .php
- **検出 B（判定式の同居）**: 検出 A に該当し、**かつ** `TakeStatus::Ready` を含むファイル

テストケース:

| # | テスト名 | 検証 |
|---|---|---|
| 1 | `adoptedTake を参照する app/ のファイルはすべて目録に登録されている` | 検出 A ∖ 目録 = ∅（deny-by-default） |
| 2 | `目録の全エントリが実在の参照を持つ` | 目録 ∖ 検出 A = ∅（**exact-fit**。stale entry で常時緑になるのを防ぐ） |
| 3 | `走査母集団が空でない` | 検出 A の件数 ≥ 5（**負のコントロール**。走査が壊れて 0 件になったら fail） |
| 4 | `ready 判定を持てるのは Canonical の 1 ファイルだけ` | 検出 B == `{Services/Manual/AdoptedReadyTakeCoverage.php}` |
| 5 | `判定式の同居ファイルが 0 件なら fail する` | 検出 B の件数 == 1（**規則が空振りしていないことの保証**。4 と分ける） |
| 6 | `目録の根拠は 30 文字以上ある` | 全エントリの rationale 長 |
| 7 | `Canonical 区分の登録は 1 件だけである` | 目録側の cap（exact-fit を目録側からも閉じる） |

### mutation で赤化を確認する手順（実装時に必ず実行し、結果を PR に残す）

| # | 変異 | 期待して赤くなるテスト |
|---|---|---|
| M1 | `RenderPipeline::clipSpecFor()` の `isMissing()` 呼び出しを元の `$take === null \|\| $take->status !== TakeStatus::Ready` に戻す | 検出 B が 2 ファイルになり **ケース 4** が fail |
| M2 | 新ファイル `app/Services/Manual/Dummy.php` に `$cut->adoptedTake` を 1 行書く（目録には足さない） | **ケース 1** が fail |
| M3 | 目録から `Models/Cut.php` を残したまま `Cut.php` の relation 名を変える | **ケース 2** が fail |
| M4 | 走査ルート を存在しないディレクトリへ差し替える | **ケース 3・5** が fail（空振り検出） |
| M5 | `AdoptedReadyTakeCoverage::isMissing()` の条件から `status !== Ready` を落とす | `PreviewCoverageParityTest` の「採用済みだが ready でないテイクは未撮影として数える」が fail |
| M6 | `triggerPreview()` に render と同じ 422 を足す | `PreviewCoverageParityTest` の「preview は 201」が fail |
| M7 | `finalize` を manifest 由来ではなく現在状態からの再計算に変える | `RenderPlaceholderCountTest` の「生成後に採用しても件数が変わらない」が fail |
| M8 | `VideoManualController::show` から `coverage` を落とす | Feature の props テストと `RenderPanel.test.ts` が fail |

---

## 施策 5: ドキュメント同期

- `docs/architecture.md` §レンダジョブの運用契約 に小節を追加:
  「**採用テイク充足判定の単一化と告知契約**」— 述語の所在、render=422 / preview=告知の非対称の
  理由、`placeholder_cut_count` の値契約表、**保証しないもの**。
- `AGENTS.md` ドメイン固有規約に 1 項追加（既存規約 1 の隣）:
  「採用済み ready 判定は `AdoptedReadyTakeCoverage` のみ。新しい参照は
  `AdoptedTakeReferenceInventory` へ区分 + 根拠付きで登録（deny-by-default）」。
  **番号は末尾に追加**し既存番号を renumber しない（相互参照を壊さないため）。

---

## テスト計画（全体）

> **テストファースト**（思考原則 5）: F-1-01 の再現テスト（下記 A-2 / A-3）を先に書き、
> **赤を確認してから**実装に入る。

### A. `tests/Feature/Manual/PreviewCoverageParityTest.php`（新規）

fixture は Factory のみ（`Cut::factory()` / `Take::factory()`、既存 `renderTriggerContext()` と
同型のヘルパを本ファイル内に持つ。`RefreshDatabase` はグローバル適用のため個別宣言しない）。

| # | テストケース名 | 検証 |
|---|---|---|
| A-1 | `render は未採用カットがあると 422 で未採用カットを列挙する` | 既存契約の回帰（土台） |
| A-2 | `preview は未採用カットがあっても 201 で受け付ける（ブロックしない）` | **F-1-01 の第三の道** |
| A-3 | `render 422 の列挙件数と詳細画面 coverage の missing_count が一致する` | **乖離しないことの核**（同一 fixture・同一時点） |
| A-4 | `詳細画面 props に total_cuts / missing_count / missing_labels が載る` | Inertia props（`assertInertia`） |
| A-5 | `すべて採用済みなら missing_count は 0 でラベルは空になる` | 正常系（注記を出さない条件） |
| A-6 | `採用済みだが status が ready でないテイクは未撮影として数える` | 基準の同一性（別基準との差） |
| A-7 | `未撮影が 11 件のとき missing_labels は 10 件で missing_count は 11 になる` | 打ち切りの契約（件数は打ち切らない） |
| A-8 | `撮影者 (project_member) には coverage 注記の対象 props が返るが操作は 403 のまま` | 既存の権限境界を壊していないこと |

### B. `tests/Feature/Manual/RenderPlaceholderCountTest.php`（新規）

| # | テストケース名 | 検証 |
|---|---|---|
| B-1 | `succeeded な preview に生成時のプレースホルダ件数が記録される` | `RenderPipeline::run()` を直接実行（`Process::fake()` + composer fake） |
| B-2 | `succeeded な render の placeholder_cut_count は 0 になる` | render は欠落し得ない |
| B-3 | `queued / running / failed の placeholder_cut_count は null のまま` | 値契約 |
| B-4 | `プレビュー生成後にテイクを採用しても記録済み件数は変わらない` | **再計算禁止**の behavioral 固定 |
| B-5 | `ポーリング応答と詳細画面 props に placeholder_cut_count が載る` | DTO 波及 |

### C. `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`（新規）

施策 4 の 7 ケース（負のコントロール・exact-fit を含む）。

### D. フロント（vitest）

- `tests/js/components/features/manual/RenderPanel.test.ts`（既存を更新。**削除・上書きはしない**）
  - D-1 `未撮影があるとプレビュー近傍に注記を出す`
  - D-2 `未撮影があってもプレビュー生成ボタンは disabled にならない`（禁止事項 8）
  - D-3 `未撮影が 0 件なら注記を出さない`
  - D-4 `再生対象プレビューの placeholder_cut_count>0 なら動画の上に注記を出す`
  - D-5 `placeholder_cut_count が null なら注記を出さない（0 と同一視しない）`
  - D-6 `最新 preview job と再生対象 job が別世代なら注記を出さない`
  - D-7 `missing_labels が打ち切られているとき「ほか N 件」を出す`
- `tests/js/pages/ManualsShow.test.ts`（既存を更新）
  - D-8 `render.coverage が RenderPanel へ渡る`

### E. Browser lane（**Chromium + WebKit の 2 レーン契約**。`tests/Browser/PreviewCoverageNoticeTest.php` 新規）

UI を変えるため必須（`docs/testing-browser.md`。実行時間を理由に WebKit を落とさない）。

- E-1 `未撮影があるマニュアルの詳細画面で、プレビュー生成前に注記が見える`
- E-2 `注記が出ていてもプレビュー生成ボタンは押下可能である`（`disabled` 属性の不在を assert）

> Browser lane では実 ffmpeg を回さない（プレビューの完了までは追わない）。
> E は**押す前の告知と押下可能性**のみを対象とし、生成物の説明 (D-4) は vitest 側で固定する。

### F. 既存テストへの追随（削除・上書きは行わない）

- `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`: 応答 shape に
  `placeholder_cut_count` を追加
- `tests/Feature/Manual/RenderPipelineTest.php`: `RenderResult` 生成箇所があれば引数追加
- `tests/Unit/Render/FfmpegVideoComposer*Test.php`: `RenderManifest` の**コンストラクタは変えない**
  ため影響なし（`placeholderCutCount()` はメソッド）

### 検証コマンド（全 green でコミット）

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm test` / `pnpm build` / `composer test:browser`

---

## 保証しないもの（誇張しない）

1. **事前告知は描画時点のスナップショット**である。別タブ・別ユーザー・別デバイスの撮影で
   古くなる。押下を止めないため詰みは作らないが「常に最新」ではない。
2. **自然言語メッセージの文意は機械照合しない**。テストが固定するのは
   `data-testid` の存在・件数・`disabled` 属性の不在までであり、文面の妥当性は人のレビュー責任。
3. **gate は静的走査**であり、`adoptedTake` を文字列変数経由で組み立てる参照
   （`$rel = 'adopted'.'Take'`）、動的プロパティアクセス、`Take` を別経路で引いて
   status を判定するコード（`Take::query()->where(...)`）には**沈黙する**。
   検出 B も「同一ファイル内に `TakeStatus::Ready` が出現するか」という近似であり、
   別ファイルへ切り出して同じ判定を書く経路は検出できない。
4. **`placeholder_cut_count` が語るのは「プレースホルダに落ちたクリップ数」だけ**である。
   その動画が実用に足るか（品質）は何も語らない。既存行は `null` のままで backfill しない。
5. **プレースホルダ映像自体は変えない**（黒背景 + 字幕は意図的な仕様）。
   「未撮影」テロップの焼き込みは行わない。
6. **ダッシュボード / 撮影ナビの撮影待ちカウントとの差は残る**
   （`whereDoesntHave('adoptedTake')` は「採用済みだが ready でないテイク」を撮影済みとして数える）。
   本設計は統合せず `DifferentCriterion` として記録するだけである。
7. Browser lane は**告知の可視性と押下可能性**のみを見る。実 ffmpeg 合成・黒画面の
   目視確認は staging worker での運用確認（`docs/architecture.md` の既存運用項目）に委ねる。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | migration（`render_jobs` 列追加）+ 共有 DTO（`RenderJobData` / `RenderResult`）+ Architecture gate 追加を含み、レンダ系ファイルの広い範囲に触れる。並走中の他 2 設計（同 run の別 finding）と同時にマージすると衝突・レビュー困難になる |
| 競合リスク | 同 bug-hunt run の他設計は 2FA middleware / dashboard props 系で**ファイル重複は無い見込み**。ただし `resources/js/types/manual.ts` と `RenderPanel.svelte` に触る設計が他に無いことをマージ前に確認する |

---

## 関連する現行コード（抜粋。実在確認済み）

### app/Services/Manual/RenderJobService.php (抜粋: triggerPreview / 判定)
```php
    /**
     * 完成レンダのトリガー (§10.8-8 冪等 + 残高事前チェック + enqueue)。
     *
     * - 実行可能状態: ready のみ (published は編集で ready に戻してから = §10.8-6)
     * - render 冪等: 同一 manual の in-flight kind=render は 1 つ → 409 (preview は妨げない)
     * - 採用テイク欠落は 422 (スキップしない: 標準化された成果物の完全性)
     * - 尺上限ソフトゲート 422 (§10.8-1: TTL 内 commit)・残高事前チェック 402
     * - $actor はジョブ実行者 (通知宛先の導出用)。web 経路では必ず存在するが、
     *   将来の CLI 経路に備え nullable (未指定時は triggered_by NULL = creator のみ宛先)
     */
    public function trigger(Project $project, VideoManual $manual, ?User $actor = null): RenderJob
    {
        $job = DB::transaction(function () use ($project, $manual, $actor): RenderJob {
            // 共有ロック規約: status を書くため VideoManual 行ロック (親 relation 経由 = 子∈親も担保)
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VideoManualStatus::Ready) {
                throw new RenderConflictException(RenderConflictType::StatusNotRenderable);
            }
            if ($this->hasInFlight($locked, RenderKind::Render)) {
                throw new RenderConflictException(RenderConflictType::InFlight);
            }

            $ordered = CutSequencer::orderedWithLabels($locked);
            $this->assertAllCutsHaveAdoptedReadyTakes($ordered);
            $this->assertTotalSourceDurationWithinLimit($ordered);

            // 残高事前チェック (reserve はジョブ開始時 = §10.5。ここは fail-fast の入口ゲート)。
            // 判定は表示 clamp 済みの balance() ではなく真値 availableTrueBalance() を使う
            // (返金債務で負に振れた出所を clamp が隠すと誤判定になる)
            $cost = config()->integer('manual.render_ticket_cost');
            $balance = $this->tickets->availableTrueBalance($this->resolveOrganization($project));
            if ($balance < $cost) {
                throw InsufficientTicketsException::forReserve($cost, $balance);
            }

            // job 作成 (kind/status/scenario_version は明示代入) + rendering へ
            $job = $locked->renderJobs()->make();
            $job->kind = RenderKind::Render;
            $job->status = JobStatus::Queued;
            $job->scenario_version = $locked->scenario_version; // §10.8-6 スナップショット
            if ($actor !== null) {
                $job->triggeredBy()->associate($actor); // Auth 導出のみ (保護キー。payload 直送は 422)
            }
            $job->save();

            $locked->forceFill(['status' => VideoManualStatus::Rendering])->save();

            // キュー投入は**業務 tx の内側**で行う (AG-114 確定 1)。payload は job id のみ。
            // jobs 行が同一 tx に乗るため「保存済み・未投入」が構造的に消える。
            // 前提は QueueDispatchAtomicityGuard が起動時に fail-closed 検査する。
            RunManualRender::dispatch($job->id);

            return $job;
        });

        return $job;
    }

    /**
     * プレビューのトリガー (チケット非消費・manual status 遷移なし・org 上限直列化)。
     *
     * org 同時 preview 上限は Organization 行ロックで直列化する (reserve と同じ手法。
     * ロック順 video_manuals → organizations はグローバル順の部分列)。
     */
    public function triggerPreview(Project $project, VideoManual $manual, ?User $actor = null): RenderJob
    {
        $job = DB::transaction(function () use ($project, $manual, $actor): RenderJob {
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

            // 状態 guard: analyzing / rendering は 409 (cuts が動く最中)。draft は cuts 不在 = 422
            if (in_array($locked->status, [VideoManualStatus::Analyzing, VideoManualStatus::Rendering], true)) {
                throw new RenderConflictException(RenderConflictType::StatusNotPreviewable);
            }
            if (! $locked->cuts()->exists()) {
                throw ValidationException::withMessages(['scenario' => ['シナリオがありません。']]);
            }
            // preview 冪等 (同一 manual の in-flight preview は 1 つ)
            if ($this->hasInFlight($locked, RenderKind::Preview)) {
                throw new RenderConflictException(RenderConflictType::InFlight);
            }

            // org 上限: Organization 行ロックで直列化 (reserve の残高判定と同じ直列化点)
            $organization = $this->resolveOrganization($project);
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $limit = config()->integer('manual.render_max_inflight_previews_per_org');
            if ($this->orgInFlightPreviewCount($organization) >= $limit) {
                throw new RenderConflictException(RenderConflictType::OrgPreviewLimit);
            }

            $job = $locked->renderJobs()->make();
            $job->kind = RenderKind::Preview;
            $job->status = JobStatus::Queued;
            $job->scenario_version = $locked->scenario_version;
            if ($actor !== null) {
                $job->triggeredBy()->associate($actor); // Auth 導出のみ (preview は通知対象外だが監査用に記録)
            }
            $job->save();

            // キュー投入は**業務 tx の内側**で行う (AG-114 確定 1)。
            RunManualRender::dispatch($job->id);

            return $job; // manual status は変更しない (編集と並走)
        });

        return $job;
    }
    }

    /**
     * 採用テイク検証 (欠落 = 422。スキップしない: 標準化された成果物の完全性)。
     * adopted_take_id NULL または採用テイクが ready でないカットの表示ラベル一覧を message に含める。
     *
     * @param  list<OrderedCut>  $ordered
     */
    private function assertAllCutsHaveAdoptedReadyTakes(array $ordered): void
    {
        $missing = [];
        foreach ($ordered as $entry) {
            $take = $entry->cut->adoptedTake;
            if ($take === null || $take->status !== TakeStatus::Ready) {
                $missing[] = $entry->label;
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'takes' => ['採用テイクが未設定のカットがあります: '.implode('、', $missing)],
            ]);
        }
    }

    /**
     * 尺上限ソフトゲート (§10.8-1: TTL 内 commit)。クライアント申告値ベースで、
     * ハード保証はジョブ timeout が担う。duration_ms NULL は保守的な既定尺で代用する。
     *
     * @param  list<OrderedCut>  $ordered
     */
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

        if ($totalMs > config()->integer('manual.render_max_total_source_ms')) {
            throw ValidationException::withMessages([
                'takes' => ['動画の合計尺が上限を超えています。マニュアルを分割してください。'],
            ]);
        }
    }

```

### app/Services/Manual/RenderPipeline.php (抜粋: run / buildManifest / clipSpecFor / finalize / updateProgress)
```php
    public function run(int $renderJobId): void
    {
        $job = RenderJob::query()->findOrFail($renderJobId);
        $workDir = null;
        $uploadedKey = null;

        try {
            if (! $this->startJob($job)) {
                return; // 重複配送 / stale 回復後の遅延配送 → no-op
            }

            $manifest = $this->buildManifest($job);

            // compose (DB 外・ロック外)
            $workDir = $this->makeWorkDir($job);
            $localSources = $this->downloadSources($manifest, $workDir);
            $composed = $this->composer->compose(
                $manifest,
                $localSources,
                $workDir,
                fn (int $composedClips, int $totalClips) => $this->onClipComposed($job, $composedClips, $totalClips),
            );
            $this->updateProgress($job, RenderStep::Concat, 90);

            // ★ preflight suppression (裁定 AG-082 標準形 (2)): S3 PUT の直前で所有権を再検証する。
            //   updateProgress() という**自前の書き込みの後**に置くことが要点
            //   (書き込みの前に検証すると、書き込み中の接続断で旧担当が PUT できる窓が開く)。
            //   ffmpeg compose / S3 GET の前には置かない — ローカル CPU と冪等な読み取りであり、
            //   取り消せない外部副作用を持たないため (docs/architecture.md の残余窓 3)。
            $this->assertStillOwned($job, RenderStep::Concat);

            // upload → finalize (terminal tx)
            $this->storage->upload($composed->localPath, $manifest->outputKey);
            $uploadedKey = $manifest->outputKey;

            $result = new RenderResult(
                outputPath: $manifest->outputKey,
                clipDurationsMs: $composed->clipDurationsMs,
                totalDurationMs: $composed->totalDurationMs,
            );
            if ($this->finalize($job, $result)) {
                $uploadedKey = null; // succeeded に到達した出力は正 (後始末しない)
                // succeeded 到達時のみ・terminal tx の commit 後に通知 (kind=render のみ。
                // finalize が $job->refresh() 済み。preview は通知しない)
                if ($job->kind === RenderKind::Render) {
                    $this->notifications->notifyRenderFinished($job);
                }
            }
        } catch (JobOwnershipLostException $exception) {
            // preflight suppression: 既に terminal 化されている = 自分は旧担当。
            // failJob も通知もチケット release も呼ばない。$uploadedKey は null のままなので
            // finally の後始末は work dir の削除だけを行う (孤児オブジェクトを作らずに降りる)。
            // return ではなく catch で受けるのは、片付け経路 (finally) を 1 本に保つため。
            Log::warning('レンダジョブの所有権を失ったため出力アップロードを中止しました', $exception->logContext());
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->errorCodeFor($exception), $this->userMessageFor($exception));
        } finally {
            // アップロード済みで succeeded 未達 (失敗 / stale 先勝ち) の出力はベストエフォート削除
            if ($uploadedKey !== null) {
                try {
                    $this->storage->delete($uploadedKey);
                } catch (Throwable $cleanupException) {
                    report($cleanupException); // 孤児オブジェクトは reconcile 対象外のため記録だけ残す
                }
            }
            if ($workDir !== null) {
                File::deleteDirectory($workDir);
            }
        }
    }
    /**
     * マニフェスト構築 tx (読み取り一貫性の確定点。version 固定 §10.8-6)。
     * - version 不一致 → RenderScenarioChangedException → failJob(scenario_version_changed)
     * - preview 追加 guard: analyzing/rendering に遷移していたら同例外 (トリガー後の競合防御)
     * - render の採用テイク欠落は起き得ない (trigger 422 + rendering 中 adopt/delete 409) が
     *   防御的に例外 → failJob。preview は欠落 cut を Placeholder に落とす
     */
    private function buildManifest(RenderJob $job): RenderManifest
    {
        return DB::transaction(function () use ($job): RenderManifest {
            $project = $this->resolveProject($job);
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()
                ->whereKey($job->video_manual_id)->lockForUpdate()->firstOrFail();

            if ($lockedManual->scenario_version !== $job->scenario_version) {
                throw RenderScenarioChangedException::versionMismatch();
            }
            if ($job->kind === RenderKind::Preview
                && in_array($lockedManual->status, [VideoManualStatus::Analyzing, VideoManualStatus::Rendering], true)) {
                throw RenderScenarioChangedException::manualBusy();
            }

            $clips = [];
            foreach (CutSequencer::orderedWithLabels($lockedManual) as $entry) {
                $clips[] = $this->clipSpecFor($job, $entry->cut, $entry->label);
            }
            if ($clips === []) {
                throw new LogicException("render job {$job->id}: クリップが 0 件です (cuts 不在)");
            }

            return new RenderManifest(
                renderJobId: $job->id,
                kind: $job->kind,
                scenarioVersion: $job->scenario_version,
                outputKey: $this->outputKeyFor($lockedManual, $job),
                clips: $clips,
            );
        });
    }

    /** カット 1 枚分のクリップ仕様 (欠落は render=防御例外 / preview=Placeholder) */
    private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderClipSpec
    {
        $take = $cut->adoptedTake;
        if ($take === null || $take->status !== TakeStatus::Ready) {
            if ($job->kind === RenderKind::Render) {
                // trigger 422 + rendering 排他により起き得ない。防御的に fail させる
                throw new LogicException("render job {$job->id}: 採用テイク欠落 ({$label})");
            }

            return new RenderClipSpec(
                cutId: $cut->id,
                label: $label,
                source: RenderClipSource::Placeholder,
                takeVideoPath: null,
                stillDisplaySeconds: null,
                subtitlePrimary: $cut->subtitle_primary,
                subtitleSecondary: $cut->subtitle_secondary,
            );
        }

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
    }

    /**
    private function finalize(RenderJob $job, RenderResult $result): bool
    {
        $succeeded = DB::transaction(function () use ($job, $result): bool {
            // ロック 1: job 行 (stale 回復 cron との直列化点)
            /** @var RenderJob $locked */
            $locked = RenderJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== JobStatus::Running) {
                return false; // stale 回復 cron が先勝ち → complete も commit もしない (無課金 succeeded 排除)
            }

            // ロック 2: manual 行 (共有ロック規約。親 relation 経由再解決 = 子∈親も担保)
            $project = $this->resolveProject($locked);
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()
                ->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

            if ($locked->kind === RenderKind::Render) {
                // 防御的再検証 (違反 → LogicException → 全体 rollback → failJob)
                if ($lockedManual->status !== VideoManualStatus::Rendering
                    || $lockedManual->scenario_version !== $locked->scenario_version) {
                    throw new LogicException(
                        "render job {$locked->id}: finalize guard 違反 (status/version が rendering 開始時と不一致)",
                    );
                }
                // cut_length_ms / total_length_ms / published はロック済み manual 前提メソッドで反映
                $this->jobs->completeRenderIntoLockedManual($lockedManual, $result);

                // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部)。
                // commit-wins: TTL 超過や stale releaser 先着 (Released) でも生存 hold は課金する
                // (二重課金は consume:{id} の UNIQUE が防ぐ)。失効 monthly hold のみ no-charge。
                // 戻り値 (TicketCommitResult) は可観測性のためのもので分岐には使わない
                $reservation = $locked->ticketReservation;
                Assert::notNull($reservation, 'startJob が必ず予約を付けている');
                $this->tickets->commit($reservation);
            }

            $locked->status = JobStatus::Succeeded;
            $locked->progress = 100;
            $locked->output_path = $result->outputPath;
            $locked->save();

            // 旧世代 (同 manual・同 kind・output_path 非 NULL・id < 自分の succeeded)
            $oldJobIds = RenderJob::query()
                ->where('video_manual_id', $locked->video_manual_id)
                ->where('kind', $locked->kind->value)
                ->where('status', JobStatus::Succeeded->value)
                ->whereNotNull('output_path')
                ->where('id', '<', $locked->id)
                ->get()
                ->map(static fn (RenderJob $old): int => $old->id)
                ->all();

            // 旧世代 output の削除投入を **terminal tx の内側**で行う (AG-114 確定 1)。
            // 削除 job は冪等のため重複無害。喪失時の回収役 (render:reconcile-outputs) は
            // 別要因 (worker 異常終了) のために残す。
            foreach ($oldJobIds as $oldJobId) {
                DeleteRenderOutputsJob::dispatch($oldJobId);
            }

            return true;
        });

        if ($succeeded) {
            $job->refresh();
        }

        return $succeeded;
    }
    /**
     * step/progress の表示用更新 (AnalysisPipeline::writeProgress と同型)。
     *
     * ★ **条件付き UPDATE (`where status=running`)**。compose は最大 25 分走り、
     *   `onClipComposed()` から高頻度に呼ばれるため、terminal 化後の書き戻しが
     *   最も起きやすい経路である (「failed なのに progress=62」を作らない)。
     * ★ `Builder::update()` は `updated_at` を自動付与する (stale 判定の
     *   「最終 step 更新時刻」という意味は従来どおり。ただし terminal 行では動かない)。
     * ★ AnalysisPipeline::writeProgress と違い cast の正規化を挟まないのは、ここで書く 2 列が
     *   **cast 適用後と同一表現のスカラー** (`RenderStep` の backing value と int) だけだからである。
     *   配列 / 日時など cast で表現が変わる列をここへ足すときは、あちらと同じく
     *   `forceFill()->getAttributes()` を通すこと。
     */
    private function updateProgress(RenderJob $job, RenderStep $step, int $progress): void
    {
        RenderJob::query()
            ->whereKey($job->getKey())
            ->where('status', JobStatus::Running->value)
            ->update(['step' => $step->value, 'progress' => $progress]);
    }

```

### app/Http/Controllers/Projects/VideoManualController.php (抜粋: show)
```php
    /** 詳細 (撮影者も閲覧可) */
    public function show(Request $request, Project $project, VideoManual $manual, SeoManager $seo, VideoManualService $manuals): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('view', $manual);

        // 動的固有名の per-page タイトル (noindex 維持。projects.show の参考実装踏襲)
        $seo->setPrivateTitle($manual->title);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $category = $manual->category;

        // stale な失敗 (失敗確定後に scenario 保存が成立) は job=null で抑制する (T032 / F-1-1)
        $analysisJob = $manuals->displayAnalysisJob($manual);
        $renderJob = $manuals->displayRenderJob($manual);
        $previewJob = $manuals->displayPreviewJob($manual);

        return Inertia::render('Manuals/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'manual' => [
                'id' => $manual->id,
                'title' => $manual->title,
                'status' => $manual->status->value,
                'category' => $category === null
                    ? null
                    : ['id' => $category->id, 'name' => $category->name],
                'created_at' => $manual->created_at?->format('Y-m-d H:i') ?? '',
            ],
            // AI 解析パネル (最新 job + 手順書有無)。AnalysisJobData::toArray() と対
            'analysis' => [
                'job' => $analysisJob === null
                    ? null
                    : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
                'hasDocument' => $manual->sourceDocuments()->exists(),
            ],
            // レンダパネル (最新 render job / 最新 preview job / 再生可能 preview)。RenderProps と対
            'render' => [
                'job' => $renderJob === null
                    ? null
                    : RenderJobData::fromJob($renderJob, $manual)->toArray(),
                'previewJob' => $previewJob === null
                    ? null
                    : RenderJobData::fromJob($previewJob, $manual)->toArray(),
                // playbackJobId は succeeded preview のみを見るため staleness 抑制の対象外 (不変)
                'playbackJobId' => $manual->renderJobs()
                    ->where('kind', RenderKind::Preview->value)
                    ->where('status', JobStatus::Succeeded->value)
                    ->whereNotNull('output_path')
                    ->latest('id')
                    ->value('id'),
            ],
            'canManage' => $user->can('update', $manual),
            'categories' => $this->categoryOptions($project), // 複製ダイアログのカテゴリ選択肢 (既存 helper 再利用)
        ]);
    }
```

### app/DataTransferObjects/Manual/RenderJobData.php (全文) / CutSequencer.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;
use App\Enums\Manual\VideoManualStatus;
use App\Models\RenderJob;
use App\Models\VideoManual;

/**
 * RenderJob の表示 shape (show props / ポーリング応答 / トリガー 201 の共通 DTO)。
 * **成果物 URL / output_path は持たない** (ポーリングと成果物アクセスの権限分離 = 概念設計 §7)。
 * TS 側 types/manual.ts の RenderJobProps と対で保守する。
 */
final readonly class RenderJobData
{
    public function __construct(
        public int $id,
        public RenderKind $kind,
        public JobStatus $status,
        public ?RenderStep $step,
        public ?int $progress,
        public ?string $error,
        public ?RenderErrorCode $errorCode,
        public VideoManualStatus $manualStatus,
    ) {}

    public static function fromJob(RenderJob $job, VideoManual $manual): self
    {
        return new self(
            id: $job->id,
            kind: $job->kind,
            status: $job->status,
            step: $job->step,
            progress: $job->progress,
            error: $job->error,
            errorCode: $job->error_code,
            manualStatus: $manual->status,
        );
    }

    /**
     * @return array{id: int, kind: string, status: string, step: string|null, progress: int|null,
     *   error: string|null, error_code: string|null, manual_status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'step' => $this->step?->value,
            'progress' => $this->progress,
            'error' => $this->error,
            'error_code' => $this->errorCode?->value,
            'manual_status' => $this->manualStatus->value,
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Render\OrderedCut;
use App\Enums\Manual\CutType;
use App\Models\Cut;
use App\Models\VideoManual;

/**
 * カットの表示順 (step を sort_order 順 → 直後に配下 point を sort_order 順) と
 * 表示ラベル (手順N / 急所N-M) の導出。レンダのトリガー検証・マニフェスト構築が共用する
 * (読み取り専用。cuts / status / version は書かない)。
 */
final class CutSequencer
{
    /**
     * adoptedTake を eager load した表示順カット列を返す (行ロック済み manual の tx 内で呼ぶ)。
     *
     * @return list<OrderedCut>
     */
    public static function orderedWithLabels(VideoManual $manual): array
    {
        $cuts = $manual->cuts()->with('adoptedTake')->orderBy('sort_order')->orderBy('id')->get();

        /** @var list<Cut> $steps */
        $steps = $cuts->filter(fn (Cut $cut): bool => $cut->type === CutType::Step)->values()->all();
        /** @var array<int, list<Cut>> $pointsByParent */
        $pointsByParent = [];
        foreach ($cuts as $cut) {
            if ($cut->type === CutType::Point && $cut->parent_cut_id !== null) {
                $pointsByParent[$cut->parent_cut_id][] = $cut;
            }
        }

        $ordered = [];
        foreach ($steps as $stepIndex => $step) {
            $stepNumber = $stepIndex + 1;
            $ordered[] = new OrderedCut($step, "手順{$stepNumber}");
            foreach ($pointsByParent[$step->id] ?? [] as $pointIndex => $point) {
                $pointNumber = $pointIndex + 1;
                $ordered[] = new OrderedCut($point, "急所{$stepNumber}-{$pointNumber}");
            }
        }

        return $ordered;
    }
}
```

### resources/js/components/features/manual/RenderPanel.svelte (抜粋: props と preview ブロック)
```svelte
    /**
     * レンダパネル (完成動画生成・プレビュー生成・進捗ポーリング・DL 導線)。概念設計 §8。
     * - 起動は POST .../render / .../preview (XHR)。402/409/422 は押下時にサーバの
     *   メッセージを表示 (必須未充足でもボタンは disabled にしない = DESIGN.md)
     * - ポーリングは 1 コンポーネント内で scheduler 1 本 (render/preview を別タイマーで
     *   追わない)。単一 interval が追跡中 job id 集合を順に fetch し、終端条件のみ kind 別分岐
     *   (render: succeeded → router.reload() / preview: succeeded → <video> 表示)
     * - failed + error_code=scenario_version_changed は「作り直す」CTA (preview 再 POST)
     */
    interface Props {
        projectId: number;
        manualId: number;
        manualStatus: VideoManualStatus;
        job: RenderJobProps | null;
        previewJob: RenderJobProps | null;
        playbackJobId: number | null;
        canManage: boolean;
    }

    let { projectId, manualId, manualStatus, job, previewJob, playbackJobId, canManage }: Props =
        $props();

    // 作業状態 (props から一度だけ seed し、以後は XHR 応答で更新する)
    // svelte-ignore state_referenced_locally
    let renderJob = $state<RenderJobProps | null>(job);
    // svelte-ignore state_referenced_locally
    let preview = $state<RenderJobProps | null>(previewJob);
    // svelte-ignore state_referenced_locally
    let playbackId = $state<number | null>(playbackJobId);
    // svelte-ignore state_referenced_locally
    let status = $state<VideoManualStatus>(manualStatus);
    let starting = $state(false);
    // 起動失敗の表示モデル (message + 402 残高不足時の購入導線)。402 (残高不足) のときのみ
    // showPurchaseLink=true (code 厳格一致。他エラーで誤表示しない)。
    type StartError = { message: string; showPurchaseLink: boolean };
    // 起動失敗は render/preview 独立に保持する (共有だと後発が先発を上書きし帰属が崩れる)
    let renderStartError = $state<StartError | null>(null);
    let previewStartError = $state<StartError | null>(null);
    let sessionExpiredMessage = $state<string | null>(null);
    let confirmingRender = $state(false);

    const isInFlight = (target: RenderJobProps | null): boolean =>
        target?.status === "queued" || target?.status === "running";

    const rendering = $derived(status === "rendering" || isInFlight(renderJob));
    const previewInFlight = $derived(isInFlight(preview));
    const failedRenderJob = $derived(renderJob?.status === "failed" ? renderJob : null);

<Card padding="lg">
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-h3">完成動画</h2>
        {#if canManage && !rendering}
            <div class="flex items-center gap-2">
                <Button
                    variant="secondary"
                    onclick={() => void start("preview")}
                    loading={starting && !confirmingRender}
                    testId="preview-button"
                >
                    <Play class="size-4" />
                    プレビュー生成
                </Button>
                {#if status === "ready"}
                    <Button onclick={requestRender} testId="render-button">
                        <Clapperboard class="size-4" />
                        完成動画を生成
                    </Button>
                {/if}
            </div>
        {/if}
    </div>

    {#if canManage}
        <div class="mt-6 flex flex-col gap-2">
            {#if previewInFlight}
                <div
                    class="flex items-center gap-2 text-body text-text-secondary"
                    data-testid="preview-progress"
                >
                    <LoaderCircle class="size-4 animate-spin" />
                    <span>プレビューを生成中 ({preview?.progress ?? 0}%)</span>
                </div>
            {:else if failedPreviewJob}
                <div data-testid="preview-error">
                    <Alert type="danger" title="プレビューの生成に失敗しました">
                        {failedPreviewJob.error ?? "プレビューの生成に失敗しました。"}
                    </Alert>
                </div>
                {#if failedPreviewJob.error_code === "scenario_version_changed"}
                    <div>
                        <Button
                            variant="secondary"
                            onclick={() => void start("preview")}
                            testId="preview-retry-button"
                        >
                            <Play class="size-4" />
                            プレビューを作り直す
                        </Button>
                    </div>
                {/if}
            {/if}
            {#if previewStartError}
                <div data-testid="preview-start-error">
                    <Alert type="danger" title="プレビューの生成を開始できませんでした">
                        {previewStartError.message}
                        {#if previewStartError.showPurchaseLink}
                            <span class="ml-1">
                                <TextLink href="/purchase-tickets" testId="preview-purchase-link">
                                    チケットを購入する
                                </TextLink>
                            </span>
                        {/if}
                    </Alert>
                </div>
            {/if}
            {#if playbackId !== null && !previewInFlight}
                <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
                <!-- aria-label は固定文言でよい: playbackId の供給源は初期値 (Controller が
                     kind=Preview ∧ status=Succeeded で抽出) と poll の preview 分岐だけで、
                     render job が入る経路が無い (完成動画と取り違わない)。 -->
                <video
                    controls
                    preload="metadata"
                    class="w-full rounded-md bg-neutral"
                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackId}/playback`}
                    aria-label="プレビュー動画"
                    data-testid="preview-video"
                ></video>
            {/if}
        </div>
    {/if}
</Card>
```

### tests/Architecture/ScenarioWritePathInventoryTest.php (抜粋: 先例となる deny-by-default 走査)
```php
<?php

declare(strict_types=1);

/*
 * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン固有規約 1) の書き込み経路 inventory。
 *
 * 「cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
 *   対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する」
 *
 * 経路 (メソッド粒度。docs/architecture.md と対):
 * | 経路 | 書いてよいもの |
 * |---|---|
 * | ScenarioService::save() | cuts / scenario_version / status (rendering·analyzing guard 付き) |
 * | ScenarioService::materializeIntoLockedManual() | cuts / scenario_version / status (analyzing→ready のみ) |
 * | AnalysisJobService::trigger() | status (draft·ready→analyzing のみ) |
 * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定。scenario_version は snapshot 読みのみ) |
 * | VideoManualService::displayXxxJob() | 書き込みなし (stale 判定で scenario_version を読むのみ) |
 * | VideoManualService::duplicate() | cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
 *   lockForUpdate して一貫読み取り。複製 manual の INSERT 時に status=Draft / scenario_version=0 を
 *   明示代入する (新規行生成 = lockForUpdate 前だが、その tx が生成した排他的新規行・同一 tx 内反映で
 *   既存行への並行書き込みではない)。検出 1 (scenario_version) は SCENARIO_VERSION_ALLOWED、
 *   検出 2 (status) は STATUS_WRITE_ALLOWED に登録済み。検出 4 (adopted_take_id) は複製しないため非対象 |
 * | RenderJobService::trigger() | status (ready→rendering のみ。scenario_version はスナップショット読み) |
 * | RenderJobService::failJob() | status (rendering→ready のみ。kind=render に限る) |
 * | RenderJobService::completeRenderIntoLockedManual() | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ) |
 * (RenderPipeline は VideoManualStatus を直接書かない = 全て RenderJobService メソッド経由。
 *  buildManifest/finalize の scenario_version は guard 読みのみ)
 *
 * deny-by-default の token ベース静的走査 (PrismDirectDispatchScanner と同じ token_get_all 流儀。
 * コメント/docblock/文字列リテラル**内容**中の出現は無視する)。走査対象: app/ 配下の .php。
 *
 * 検出 1: 識別子/配列キー 'scenario_version' の出現 → allowlist 外のファイルなら fail
 * 検出 2: 書き込み形 `'status' => ... VideoManualStatus::...` / `->status = ... VideoManualStatus::...`
 *         (`VideoManualStatus::class` = cast 宣言は書き込みでないため除外) → allowlist 外なら fail
 * 検出 3: materializeIntoLockedManual の宣言は ScenarioService.php のみ、
 *         呼び出しは AnalysisPipeline.php のみ (ScenarioService 自身の中の呼び出しも fail =
 *         ファイル単位 allowlist の抜け穴を塞ぐ)
 * 検出 5: completeRenderIntoLockedManual の宣言は RenderJobService.php のみ、
 *         呼び出しは RenderPipeline.php (terminal tx) のみ (検出 3 と同型)
 */
final class ScenarioWritePathScanner
{
    /**
     * 検出 1 の allowlist (app/ 相対パス)。ScenarioDocumentData は読み取り shape の直列化のみ。
     * CaptureTakeService は adopt の 409 (ScenarioConflictException) に current_version を
     * 載せるための読み取りのみ (書き込みは検出 2 が別途 deny する)。
     */
    private const SCENARIO_VERSION_ALLOWED = [
        'Services/Manual/ScenarioService.php',
        'DataTransferObjects/Manual/ScenarioDocumentData.php',
        'Services/Capture/CaptureTakeService.php',
        // レンダ: trigger のスナップショット読み / buildManifest・finalize の guard 読み /
        // casts 宣言 (書き込みは検出 2 が別途 deny する)
        'Services/Manual/RenderJobService.php',
        'Services/Manual/RenderPipeline.php',
        'Models/RenderJob.php',
        // bug-hunt 専用の通し確認コマンド。analysis 段の成功条件 (scenario_version >= 1) を
        // **読み取るだけ**で、書き込みは 1 箇所も持たない (書き込みは検出 2 が別途 deny する)。
        'Console/Commands/Development/PipelineSmokeCommand.php',
        // T032: failJob が失敗確定時の scenario_version を job にスナップショット読みする
        // (書き込むのは scenario_version_at_terminal であり scenario_version ではない)
        'Services/Manual/AnalysisJobService.php',
        // VideoManualService は 2 理由で許可: (1) T032 stale alert 判定 (displayXxxJob) が
        // manual.scenario_version を read (read-only)。(2) T066 duplicate() が複製 manual の
        // INSERT 時に scenario_version=0 を明示 write (新規行生成 + 同一 tx。既存行への並行 write ではない)
        'Services/Manual/VideoManualService.php',
    ];

    /** 検出 2 の allowlist (app/ 相対パス) */
    private const STATUS_WRITE_ALLOWED = [
        'Services/Manual/ScenarioService.php',
        'Services/Manual/AnalysisJobService.php',
        // trigger: ready→rendering / failJob: rendering→ready / complete...: rendering→published。
        // RenderPipeline は VideoManualStatus を直接書かない (全て Service メソッド経由)
        'Services/Manual/RenderJobService.php',
        // T066: duplicate() が複製 manual の INSERT 時に status=Draft を明示代入
        // (新規行生成 + 同一 tx。既存行への並行書き込みではないためロック規約の趣旨に整合)
        'Services/Manual/VideoManualService.php',
    ];

    /**
     * 検出 4a の allowlist: 識別子/配列キー 'adopted_take_id' の出現 (読み書き問わず)。
     * - CaptureTakeService: adopt / 削除時 null 化 (VideoManual 行ロック tx 内 = 唯一の書き込み経路)
     * - Cut.php: relation 宣言 (belongsTo 第 2 引数)
     * - CaptureCutData: 読み取り shape の直列化のみ
     * - MassAssignmentProtectedKeys: 保護キー台帳 (文字列リストのみ)
     */
    private const ADOPTED_TAKE_ID_ALLOWED = [
        'Services/Capture/CaptureTakeService.php',
        'Models/Cut.php',
        'DataTransferObjects/Capture/CaptureCutData.php',
        'Support/Security/MassAssignmentProtectedKeys.php',
    ];

    /**
     * 検出 4b の allowlist: 書き込み形 (`['adopted_take_id' => ...]` 配列キー /
     * `->adopted_take_id =` プロパティ代入)。CaptureCutData の配列キー出現は toArray() の
     * 読み取り直列化 (`'adopted_take_id' => $cut->adopted_take_id`) で、token パターンでは
     * 書き込み (forceFill の配列キー) と区別できないため allowlist に含める
     * (検出 4a が出現ファイル自体を 4 ファイルに固定しているため、新規ファイルへの
     * 書き込みはどちらの検出でも fail する)。
     */
    private const ADOPTED_TAKE_ID_WRITE_ALLOWED = [
        'Services/Capture/CaptureTakeService.php',
        'DataTransferObjects/Capture/CaptureCutData.php',
    ];

    /**
     * @return array<string, list<string>> 検出種別 => 違反ファイル (app/ 相対パス)
```
