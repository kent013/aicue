## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: コードレビュアー

あなたは Laravel 12 + Svelte 5 (runes) + Inertia.js の改善実装をレビューするコードレビュアーである。
対象は TODO **T148「プレビューと完成生成の判断基準を揃える」** の実装差分。

## レビュー観点

1. **設計との一致性**: 詳細設計 (下記) の施策 1〜5 が実装されているか。設計が明示的に禁じたこと
   (preview の 422 ブロック / ボタンの disabled 化 / 確認ダイアログ追加 / placeholder_cut_count の
   事後再計算 / null と 0 の同一視 / backfill) を実装がやっていないか。
2. **正確性**: ロック順序 (グローバル順 render_jobs → video_manuals → ticket_reservations →
   organizations) を壊していないか。terminal tx の guard、条件付き UPDATE、preview と render の
   非対称が正しいか。props の破壊的変更 (playbackJobId → playbackJob) に全消費者が追随しているか。
3. **PHPStan level 10 適合性**: 型の widen / @phpstan-ignore / baseline を使っていないか
   (`composer phpstan` は green である)。
4. **DTO / JsonResource パターン**: response()->json() 直書きをしていないか、array-shape の docblock が
   実体と一致しているか。
5. **テスト網羅性**: 施策ごとにテストがあるか。**バグ修正のテストファースト** (finding の再現条件を
   テストで作り修正前に赤を確認) が成立しているか。deny-by-default の Architecture gate が
   exact-fit・負のコントロールを持つか。**テストが「保証している」と言えないことを言っていないか**。
6. **セキュリティ**: 認可・テナント境界を弱めていないか。coverage props が権限のない主体へ
   余分な情報を漏らさないか。
7. **DESIGN.md 準拠**: color / radius / typography は token 経由か。hex 直書き (#RRGGBB) を増やしていないか。
8. **Atomic Design 準拠**: `atoms → molecules → organisms → features/{domain} → templates → pages` の
   単方向 import を守っているか。features/manual 内で完結しているか。アイコンは Lucide か。

## 出力形式

- ファイルごとに判定 (OK / 要修正) を述べる
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - [Critical] = 設計違反・不変条件の破壊・バグ・セキュリティ・テストの嘘
  - [Warning] = 品質上直すべきだが致命的でないもの
  - [Suggestion] = 好みの範囲
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

**指摘は具体的に**: 「どのファイルのどの行が」「なぜ問題か」「どう直すか」を書くこと。
差分に無いことを推測で断定しない。

---

# user

## 元の finding (実ブラウザで再現済み)

bug-hunt run 20260811-003230 F-1-01 (High):
67 カット中 1 カットだけテイクを採用した状態で「プレビュー生成」を押すと、約 201 秒の
**全編黒画面の動画が警告なしで生成完了**する。一方、姉妹機能の完成動画生成 (render) は
同じ状態を **422 で明示ブロック**し未採用カットを列挙する。
**同じ前提条件に対して片方は止め、片方は黙って壊れた成果物を出す**。

## 詳細設計書 (Codex 合議 APPROVED 済み)

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
// VideoManualController::show() の 'render' キー (施策 3 の playbackJob 置換を織り込んだ最終形)
'render' => [
    'job' => ...,
    'previewJob' => ...,
    'playbackJob' => ...,   // 施策 3 で playbackJobId から置換 (旧キーは残さない)
    // 「使用できる採用テイクがない」カットの充足状況。
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
    /** 使用できる採用テイクがないカット数 (**打ち切らない全件数**) */
    missing_count: number;
    /** 該当カットの表示ラベル (先頭 10 件で打ち切られる。件数は missing_count が正) */
    missing_labels: string[];
}

export interface RenderProps {
    job: RenderJobProps | null;
    previewJob: RenderJobProps | null;
    /** 再生できるプレビュー (施策 3 で playbackJobId から置換。旧キーは残さない) */
    playbackJob: RenderJobProps | null;
    /** 描画時点のスナップショット (常に最新ではない。生成物の実績は playbackJob.placeholder_cut_count) */
    coverage: TakeCoverageProps;
}
```

#### 文言の正確さ（Round 2 [Warning] 対応）

述語は `adoptedTake === null || status !== Ready` であり、`TakeStatus` は
`uploading / processing / ready / failed` の 4 値を持つ。つまり
**「まだ撮っていない」だけでなく「採用済みだがアップロード中・処理中・失敗」も同じ扱い**になる。
よって「未撮影」「テイクが採用されていません」と断定する文言は事実と食い違う。
**告知文は述語の意味 (=「使用できる採用テイクがない」) をそのまま言う**。

```svelte
<!-- RenderPanel.svelte: preview ブロックの先頭 (ボタン群の下・進捗の上) -->
{#if canManage && coverage.missing_count > 0}
    <div data-testid="preview-coverage-note">
        <Alert type="warning" title="プレビューに黒背景の区間があります">
            {coverage.missing_count} / {coverage.total_cuts} 件のカットに、撮影・処理が完了した
            採用テイクがありません ({missingLabelSummary})。プレビューは生成できますが、
            該当区間は黒背景になります。完成動画の生成には、すべてのカットで撮影・処理が完了した
            採用テイクが必要です。
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
- `database/factories/RenderJobFactory.php`（既定 `null` + state の明示。下記）
- `app/DataTransferObjects/Manual/Render/RenderManifest.php`（`placeholderCutCount()` メソッド）
- `app/DataTransferObjects/Manual/Render/RenderResult.php`（`placeholderCutCount` フィールド）
- `app/Services/Manual/RenderPipeline.php` L110-114（`RenderResult` 生成）/ L316-319（finalize の書き込み）
- `app/DataTransferObjects/Manual/RenderJobData.php`（DTO フィールド + `toArray()`）
- `resources/js/types/manual.ts`（`RenderJobProps`）/ `RenderPanel.svelte`（注記）

### 波及変更

- TypeScript 型定義: `RenderJobProps.placeholder_cut_count: number | null`（**必須**）、
  `RenderProps.playbackJobId: number | null` → **`playbackJob: RenderJobProps | null` へ置換**
- API Resource/DTO: `RenderJobResource` の shape に 1 キー追加（201 / ポーリング 200 の両方）
- Svelte Props: `RenderPanel` の `playbackJobId` → `playbackJob`（`Manuals/Show.svelte` も同時変更）
- テストファイル: `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`
  （応答 shape の期待値に新キー / props の `playbackJob`）、
  `tests/Feature/Manual/RenderPipelineTest.php`（`RenderResult` 生成の検証がある場合）、
  `tests/js/components/.../RenderPanel.test.ts`（`renderJobBody()` ヘルパに新キー・props 名変更）、
  `tests/js/pages/ManualsShow.test.ts`（props 配線）

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
// RenderJobFactory (Round 1 [Warning] 対応: 「アプリが作った succeeded」と「legacy 行」を fixture で区別する)
// definition(): 'placeholder_cut_count' => null,

/** 成功確定の状態 (output_path 付き)。アプリ生成後は必ず件数を持つ = 既定 0 */
public function succeeded(string $outputPath, int $placeholderCutCount = 0): static
{
    return $this->state(fn () => [
        'status' => JobStatus::Succeeded->value,
        'progress' => 100,
        'output_path' => $outputPath,
        'placeholder_cut_count' => $placeholderCutCount,
    ]);
}

/** 本変更**以前**から在る succeeded 行の再現 (placeholder_cut_count は null)。UI の null 分岐用 */
public function legacySucceeded(string $outputPath): static
{
    return $this->succeeded($outputPath)->state(fn () => ['placeholder_cut_count' => null]);
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

#### 再生対象 job の props 化（Round 1 [Warning] 対応。**追加ではなく置換**）

現行の props は `playbackJobId: number | null`（最新 succeeded preview の **id だけ**）で、
注記の出所（`previewJob` = 最新 preview job）と**別世代になりうる**。
そこで **`playbackJobId` を `playbackJob: RenderJobProps | null` へ置き換える**
（両方は残さない = 思考原則 3「後方互換の並走を残さない」）。

```php
// VideoManualController::show() の 'render' キー (playbackJobId を置換)
$playbackJob = $manual->renderJobs()
    ->where('kind', RenderKind::Preview->value)
    ->where('status', JobStatus::Succeeded->value)
    ->whereNotNull('output_path')
    ->latest('id')
    ->first(); // 従来は ->value('id') だった (クエリ本数は増えない)

'render' => [
    'job' => ...,
    'previewJob' => ...,
    // 再生できるプレビューの DTO。動画 URL と注記が**同一オブジェクト**から出る
    'playbackJob' => $playbackJob === null ? null : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
    'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
],
```

```svelte
<!-- RenderPanel.svelte: 再生ブロック全体を playbackJob の null 検査の内側に置く
     (既存の表示条件 !previewInFlight も維持する。TS の null 安全もここで閉じる) -->
{#if playbackJob !== null && !previewInFlight}
    {#if playbackNote !== null}
        <p class="text-caption text-text-secondary" data-testid="preview-placeholder-note">
            このプレビューは {playbackNote} 件のカットに使用できる採用テイクがないため、
            その区間が黒背景になっています。
        </p>
    {/if}
    <video
        src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackJob.id}/playback`}
        ...
    ></video>
{/if}
```

```ts
// ローカル state も job オブジェクトで持つ (poll の preview 成功分岐は playbackJob = body)
let playbackJob = $state<RenderJobProps | null>(playbackJobProp);

/** 再生している動画**そのもの**の実績値だけを出す (別世代の値で説明しない) */
const playbackNote = $derived(
    playbackJob !== null &&
    playbackJob.placeholder_cut_count !== null &&
    playbackJob.placeholder_cut_count > 0
        ? playbackJob.placeholder_cut_count
        : null,
);
```

> この置換により「最新 preview job と再生対象が別世代」という穴が**条件分岐ではなく構造で**消える。
> `playbackJob` は必ず succeeded かつ `output_path` 非 NULL の preview であり、
> 注記はその行の `placeholder_cut_count` からしか出ない。

### PHPStan適合チェック

- [x] 追加フィールドは `?int`（DB nullable と一致）。cast は `'placeholder_cut_count' => 'integer'`
- [x] `RenderJobData::toArray()` / `RenderJobResource::toArray()` の array-shape docblock を同時更新
- [x] `RenderManifest::placeholderCutCount()` は `int` を返す（`array_filter` の closure に型宣言）

### リスク

- **既存行はすべて `null`**（backfill しない）。UI は `null` で注記を出さないため、
  過去のプレビューには何も表示されない = **嘘をつかない側に倒す**（0 と null を同一視しない）。
- `playbackJobId` → `playbackJob` の置換は **props の破壊的変更**である。旧キーを残さないため
  （思考原則 3）、`Manuals/Show.svelte` / `RenderPanel.svelte` / TS 型 / 既存テストを
  **同一 PR ですべて追随**させる（片方だけ直すと型エラーで即座に落ちる = 検出可能）。
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
| M5 | `AdoptedReadyTakeCoverage::isMissing()` の条件から `status !== Ready` を落とす | `PreviewCoverageParityTest` の「採用済みだが ready でないテイクも数える」が fail |
| M6 | `triggerPreview()` に render と同じ 422 を足す | `PreviewCoverageParityTest` の「preview は 201」が fail |
| M7 | `finalize` を manifest 由来ではなく現在状態からの再計算に変える | `RenderPlaceholderCountTest` の「生成後に採用しても件数が変わらない」が fail |
| M8 | `VideoManualController::show` から `coverage` を落とす | Feature の props テストと `RenderPanel.test.ts` が fail |
| M9 | 注記を `playbackJob` ではなく最新 `preview` job の値から出すように戻す | `RenderPanel.test.ts` D-6 が fail |

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
| A-6 | `採用済みだが status が ready でないテイクも missing として数える` | 基準の同一性（`whereDoesntHave('adoptedTake')` 系の別基準との差。uploading/processing/failed の 3 状態で検証） |
| A-7 | `missing が 11 件のとき missing_labels は 10 件で missing_count は 11 になる` | 打ち切りの契約（件数は打ち切らない） |
| A-8 | `撮影者 (project_member) には coverage 注記の対象 props が返るが操作は 403 のまま` | 既存の権限境界を壊していないこと |

### B. `tests/Feature/Manual/RenderPlaceholderCountTest.php`（新規）

| # | テストケース名 | 検証 |
|---|---|---|
| B-1 | `succeeded な preview に生成時のプレースホルダ件数が記録される` | **fixture を明示**: n カット中 k カットが「未採用 or 採用テイクが ready でない」manual に preview job を作り `RenderPipeline::run()` を直接実行（`Process::fake()` + `VideoComposer` の fake 実装 `app/Services/Render/Fakes`）。`render_jobs.placeholder_cut_count === k` |
| B-1b | `RenderManifest::placeholderCutCount() は clips から数える`（`tests/Unit/Render/RenderManifestTest.php` 新規） | 値の出所が clips ただ 1 つであること（DB も現在状態も見ない） |
| B-2 | `succeeded な render の placeholder_cut_count は 0 になる` | render は欠落し得ない |
| B-3 | `queued / running / failed の placeholder_cut_count は null のまま` | 値契約 |
| B-4 | `プレビュー生成後にテイクを採用しても記録済み件数は変わらない` | **再計算禁止**の behavioral 固定 |
| B-5 | `ポーリング応答と詳細画面 props に placeholder_cut_count が載る` | DTO 波及 |

### C. `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`（新規）

施策 4 の 7 ケース（負のコントロール・exact-fit を含む）。

### D. フロント（vitest）

- `tests/js/components/features/manual/RenderPanel.test.ts`（既存を更新。**削除・上書きはしない**）
  - D-1 `missing_count>0 でプレビュー近傍に注記を出す`
  - D-2 `missing_count>0 でもプレビュー生成ボタンは disabled にならない`（禁止事項 8）
  - D-3 `missing_count が 0 なら注記を出さない`
  - D-4 `playbackJob.placeholder_cut_count>0 なら動画の上に注記を出す`
  - D-5 `placeholder_cut_count が null なら注記を出さない（0 と同一視しない）`
  - D-6 `注記と動画 URL は同一の playbackJob から出る`（最新 preview が別世代でも
    再生中の動画の値だけを使う。props 置換で構造的に保証されることの回帰）
  - D-7 `missing_labels が打ち切られているとき「ほか N 件」を出す`
  - D-8 `preview 成功のポーリング応答で playbackJob が更新され注記も追随する`
- `tests/js/pages/ManualsShow.test.ts`（既存を更新）
  - D-9 `render.coverage と render.playbackJob が RenderPanel へ渡る`

### E. Browser lane（**Chromium + WebKit の 2 レーン契約**。`tests/Browser/PreviewCoverageNoticeTest.php` 新規）

UI を変えるため必須（`docs/testing-browser.md`。実行時間を理由に WebKit を落とさない）。

- E-1 `採用テイクが揃っていないマニュアルの詳細画面で、プレビュー生成前に注記が見える`
- E-2 `注記が出ていてもプレビュー生成ボタンは押下可能である`
  — **クリックしない**（Round 1 [Warning] 対応）。`disabled` 属性・`aria-disabled` の**不在**と
  可視であることのみを assert する。Browser lane に ffmpeg / storage は無く、
  クリックすると環境次第で `RunManualRender` の実行経路へ進みうるため。

> Browser lane では実 ffmpeg を回さない（プレビューの完了までは追わない）。
> E は**押す前の告知と押下可能性**のみを対象とし、生成物の説明 (D-4) は vitest 側で固定する。

### F. 既存テストへの追随（削除・上書きは行わない）

- `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`: 応答 shape に
  `placeholder_cut_count` を追加
- `tests/Feature/Manual/RenderPipelineTest.php`: `RenderResult` 生成箇所があれば引数追加
- `tests/Unit/Render/FfmpegVideoComposer*Test.php`: `RenderManifest` の**コンストラクタは変えない**
  ため影響なし（`placeholderCutCount()` はメソッド）

### 検証コマンド（全 green でコミット）

AGENTS.md の `VERIFICATION_COMMANDS` 全量 + Browser lane:

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
`pnpm build:packages` / `pnpm test:packages` / **`composer test:browser`**（UI 変更のため必須）

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


## mutation 実測記録 (設計の予測とずれた箇所を含む)

# T148 mutation 実測記録

詳細設計 `devnotes/20260811-0146-preview-render-parity/detailed-design.md` §「mutation で赤化を確認する手順」の
M1〜M9 を実施した記録。**入れた mutation はすべて元に戻した** (最終 diff に mutation は残っていない。
実施後に対象テスト群を再実行し全 green を確認済み)。

**設計の予測と実測がずれた箇所は辻褄を合わせず記録する** (M7)。

| # | 変異 | 設計の予測 | 実測 | 一致 |
|---|---|---|---|---|
| M1 | `RenderPipeline::clipSpecFor()` の `isMissing()` 呼び出しを元の `$take === null \|\| $take->status !== TakeStatus::Ready` に戻す | 検出 B が 2 ファイルになりケース 4 が fail | ケース 4 が fail (`Services/Manual/RenderPipeline.php` が検出 B に混入) | ✅ |
| M2 | 新ファイル `app/Services/Manual/DummyProbe.php` に `$cut->adoptedTake` を 1 行書く (目録に足さない) | ケース 1 が fail | ケース 1 が fail (`Services/Manual/DummyProbe.php` が未登録として検出) | ✅ |
| M3 | 目録に `Models/Cut.php` を残したまま relation 名を `adoptedTakeRenamed` へ変える | ケース 2 が fail | ケース 2 が fail (stale entry `Models/Cut.php`) | ✅ |
| M4 | 走査ルートを参照を持たないディレクトリ (`app/Enums/Manual`) へ差し替える | ケース 3・5 が fail | ケース 2・3・5・8 が fail (**設計の予測より広く赤くなる**。exact-fit のケース 2 と免除の stale 検査ケース 8 も同時に落ちる = 空振り検出としてはより強い) | ⚠ 予測より広い |
| M5 | `AdoptedReadyTakeCoverage::isMissing()` から `status !== Ready` を落とす | `PreviewCoverageParityTest` A-6 が fail | A-6 (uploading/processing/failed の 3 データセット) + A-3 が fail | ✅ |
| M6 | `triggerPreview()` に render と同じ 422 を足す | A-2「preview は 201」が fail | A-2 が fail | ✅ |
| M7 | `finalize` を manifest 由来ではなく現在状態からの再計算に変える | `RenderPlaceholderCountTest` B-4「生成後に採用しても件数が変わらない」が fail | **1 回目は全 green (予測はずれ)**。詳細は下記 | ❌ → 対処後 ✅ |
| M8 | `VideoManualController::show` から `coverage` を落とす | Feature の props テストと `RenderPanel.test.ts` が fail | `PreviewCoverageParityTest` が 8 件 fail、`ManualsShow.test.ts` が 10 件 fail | ✅ |
| M9 | 注記を `playbackJob` ではなく最新 `preview` job の値から出すように戻す | `RenderPanel.test.ts` D-6 が fail | D-4 と D-6 が fail | ✅ |

## M7: 設計の予測と実測のずれ (辻褄を合わせずに記録)

**設計の予測**: 「finalize を現在状態からの再計算に変えると B-4 が fail する」。

**実測 (1 回目)**: `RenderPlaceholderCountTest` は **7 件すべて green のまま**だった。

**原因**: B-4 が固定していたのは「**finalize が終わった後**にテイクを採用しても記録済みの値が
書き換わらない」ことだけである。finalize 時点での再計算は、その時点の現在状態が manifest 時点と
一致していれば同じ値を出すため、B-4 では区別できない。**設計が「再計算禁止」の behavioral 固定と
みなしていたテストは、実際には「読み取り時の遅延再計算の禁止」しか固定していなかった。**

**対処**: fake composer に `duringCompose` hook を足し、**buildManifest の後・finalize の前**に
テイクを採用するテスト B-4b を追加した (manifest 由来なら 2、finalize 時点の再計算なら 1 になる
fixture)。B-4 は「読み取り時の再計算禁止」の固定として残し、削除も上書きもしていない。

**再実測**: 同じ M7 変異で `B-4b: 合成中に採用しても記録されるのは manifest 時点の件数である
(finalize での再計算禁止)` が `Failed asserting that 1 is identical to 2.` で fail した。
変異を戻すと 7 → 8 件すべて green。

## 設計からの逸脱 (実装時に判明した実在との差)

**検出 B の期待集合**: 設計は「検出 B == `{Services/Manual/AdoptedReadyTakeCoverage.php}`」(厳密に 1 件) と
書いていたが、実装時に `rg -n "adoptedTake" app/` と `rg -n "TakeStatus::" app/` を再実行したところ、
`Console/Commands/Development/PipelineSmokeCommand.php` が**本変更以前から**両者を同一ファイルに
持っていた (L576 の `doesntHave('adoptedTake')` による未採用件数集計と、L630 の
「登録したテイク自身が ready か」の確認。**両者は同じ式ではない**)。

設計どおり「厳密に 1 件」を assert すると、この既存の無関係な同居で常時赤になる。判定を弱めず、
かつ既存の同居を許すために、**名指し免除 + 機械検査される前提**の形にした
(`ThrottleExemptionPremiseTest` / `IdempotencyExemptionPremiseTest` と同じ流儀):

- `COOCCURRENCE_EXEMPT` に 30 文字以上の根拠付きで 1 件だけ登録する
- ケース 8 が前提を機械検査する — 免除ファイルは **`->adoptedTake` のプロパティフェッチ形を
  一切持たない** (= relation の実体を参照しないので in-memory の採用テイク ready 判定を書けない)
- ケース 8 は stale 免除も落とす (免除対象が検出 B から外れたら免除ごと削除させる)

保証しないもの: 免除ファイル内で DB 形の判定
(`whereHas('adoptedTake', fn ($q) => $q->where('status', ...))`) を書かれた場合は検出できない。
前提が閉じるのは in-memory 形だけである (テスト冒頭のコメントに明記済み)。


## 実装差分 (git diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 21bc14d..2c5ffda 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -534,3 +534,18 @@ ## ドメイン固有規約
       gate が固定するのは「commit 後ずらしの機構を使っていないこと」までで、
       既知経路が実際に tx 内で投入していることは behavioral test が固定する
     - 詳細は `docs/architecture.md` §キュー投入の原子性
+12. **採用テイク充足判定の単一化 (T148)**: 「採用済みかつ ready のテイクを持つか」の判定式を
+    書いてよいのは `Services/Manual/AdoptedReadyTakeCoverage` **ただ 1 ファイル**である
+    (述語 `isMissing(Cut)`)。`adoptedTake` を参照する `app/` 配下のファイルは
+    `AdoptedTakeReferenceInventory` へ区分 (`AdoptedTakeReferenceKind`) と 30 文字以上の根拠付きで
+    登録する (`AdoptedReadyTakeCriterionInventoryTest` が deny-by-default + exact-fit で強制)。
+    - **制裁だけが非対称で基準は同じ**: render は 422 でブロックし、preview は**ブロックしない**
+      (未撮影は制作途中の正常な状態)。代わりに詳細画面 props が押す前に同じ件数を告知する。
+      **必須条件未充足を理由にボタンを disabled にしない / 確認ダイアログも足さない** (禁止事項 8)
+    - **告知文は述語の意味をそのまま言う**。`TakeStatus` は 4 値あるため「未撮影」と断定せず
+      「撮影・処理が完了した採用テイクがありません」と書く
+    - **`render_jobs.placeholder_cut_count` は生成物の説明**であり現在状態から再計算しない
+      (出所は buildManifest の clips)。既存行/queued/running/failed=null、succeeded preview=実数、
+      succeeded render=0。**`null` を `0` と同一視しない / backfill しない**
+    - 値契約・ロック順序上の書き込み位置・保証しないものは
+      `docs/architecture.md` §採用テイク充足判定の単一化と告知契約 が正本
diff --git a/app/DataTransferObjects/Manual/Render/RenderManifest.php b/app/DataTransferObjects/Manual/Render/RenderManifest.php
index 497e39e..1e96460 100644
--- a/app/DataTransferObjects/Manual/Render/RenderManifest.php
+++ b/app/DataTransferObjects/Manual/Render/RenderManifest.php
@@ -23,4 +23,17 @@ public function __construct(
         public string $outputKey,
         public array $clips,
     ) {}
+
+    /**
+     * プレースホルダ (黒背景) に落ちたクリップ数。
+     * 値の出所は**読み取り一貫性の確定点である clips ただ 1 つ**であり、DB も現在の manual 状態も
+     * 見ない (生成物の説明であるため。生成後に採用しても件数は動かない = T148)。
+     */
+    public function placeholderCutCount(): int
+    {
+        return count(array_filter(
+            $this->clips,
+            static fn (RenderClipSpec $clip): bool => $clip->source === RenderClipSource::Placeholder,
+        ));
+    }
 }
diff --git a/app/DataTransferObjects/Manual/Render/RenderResult.php b/app/DataTransferObjects/Manual/Render/RenderResult.php
index 60b7280..d0feb56 100644
--- a/app/DataTransferObjects/Manual/Render/RenderResult.php
+++ b/app/DataTransferObjects/Manual/Render/RenderResult.php
@@ -17,5 +17,7 @@ public function __construct(
         public string $outputPath,
         public array $clipDurationsMs,
         public int $totalDurationMs,
+        /** manifest 由来のプレースホルダ件数。**現在の manual 状態から数え直さない** (生成物の説明) */
+        public int $placeholderCutCount,
     ) {}
 }
diff --git a/app/DataTransferObjects/Manual/RenderJobData.php b/app/DataTransferObjects/Manual/RenderJobData.php
index 0dfa5d8..6de2df0 100644
--- a/app/DataTransferObjects/Manual/RenderJobData.php
+++ b/app/DataTransferObjects/Manual/RenderJobData.php
@@ -28,6 +28,12 @@ public function __construct(
         public ?string $error,
         public ?RenderErrorCode $errorCode,
         public VideoManualStatus $manualStatus,
+        /**
+         * 生成物に含まれたプレースホルダ (黒背景) クリップ数。
+         * null = その動画について言えることが無い (未完了 / T148 以前の succeeded 行)。
+         * **null を 0 と同一視しない** (0 は「黒背景ゼロで生成された」という積極的な事実)。
+         */
+        public ?int $placeholderCutCount,
     ) {}
 
     public static function fromJob(RenderJob $job, VideoManual $manual): self
@@ -41,12 +47,14 @@ public static function fromJob(RenderJob $job, VideoManual $manual): self
             error: $job->error,
             errorCode: $job->error_code,
             manualStatus: $manual->status,
+            placeholderCutCount: $job->placeholder_cut_count,
         );
     }
 
     /**
      * @return array{id: int, kind: string, status: string, step: string|null, progress: int|null,
-     *   error: string|null, error_code: string|null, manual_status: string}
+     *   error: string|null, error_code: string|null, manual_status: string,
+     *   placeholder_cut_count: int|null}
      */
     public function toArray(): array
     {
@@ -59,6 +67,7 @@ public function toArray(): array
             'error' => $this->error,
             'error_code' => $this->errorCode?->value,
             'manual_status' => $this->manualStatus->value,
+            'placeholder_cut_count' => $this->placeholderCutCount,
         ];
     }
 }
diff --git a/app/DataTransferObjects/Manual/TakeCoverageData.php b/app/DataTransferObjects/Manual/TakeCoverageData.php
new file mode 100644
index 0000000..a374127
--- /dev/null
+++ b/app/DataTransferObjects/Manual/TakeCoverageData.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+/**
+ * 採用テイクの充足状況 (「採用済みかつ ready のテイクを持つカットが揃っているか」)。
+ * render の 422 と、詳細画面の事前告知が**同じ値**を見るための唯一の shape。
+ *
+ * ★ props 用の toProps() はラベルを PROP_LABEL_LIMIT 件で打ち切るが、
+ *   missingCount は**常に全件数**である (件数を打ち切ると嘘になる)。
+ */
+final readonly class TakeCoverageData
+{
+    /** props に載せるラベルの上限 (カット数が多い manual の全ラベルを毎描画で送らない) */
+    public const int PROP_LABEL_LIMIT = 10;
+
+    /**
+     * @param  list<string>  $missingLabels  未充足カットの表示ラベル (CutSequencer の表示順)
+     */
+    public function __construct(
+        public int $totalCuts,
+        public array $missingLabels,
+    ) {}
+
+    public function missingCount(): int
+    {
+        return count($this->missingLabels);
+    }
+
+    /**
+     * @return array{total_cuts: int, missing_count: int, missing_labels: list<string>}
+     */
+    public function toProps(): array
+    {
+        return [
+            'total_cuts' => $this->totalCuts,
+            'missing_count' => $this->missingCount(),
+            'missing_labels' => array_slice($this->missingLabels, 0, self::PROP_LABEL_LIMIT),
+        ];
+    }
+}
diff --git a/app/Enums/Security/AdoptedTakeReferenceKind.php b/app/Enums/Security/AdoptedTakeReferenceKind.php
new file mode 100644
index 0000000..48b43d2
--- /dev/null
+++ b/app/Enums/Security/AdoptedTakeReferenceKind.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * `adoptedTake` relation を参照する app/ 配下ファイルの区分 (T148)。
+ *
+ * 「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
+ * `AdoptedReadyTakeCoverage` ただ 1 ファイルである、という不変条件を
+ * `AdoptedReadyTakeCriterionInventoryTest` が deny-by-default で機械検査する。
+ * 区分は「統合してよい」の意味ではなく、**何のために触っているか**の記録である。
+ */
+enum AdoptedTakeReferenceKind: string
+{
+    /** 判定式 (adoptedTake と TakeStatus::Ready の同居) の実体。**1 ファイルのみ** */
+    case Canonical = 'canonical';
+
+    /** 判定を AdoptedReadyTakeCoverage へ委譲し、自前の ready 判定を持たない参照 */
+    case DelegatedToCoverage = 'delegated_to_coverage';
+
+    /** relation 宣言・eager load 指定など、判定を含まない構造上の参照 */
+    case RelationWiring = 'relation_wiring';
+
+    /**
+     * ready 状態を見ない別基準 (「採用テイクが紐づいているか」だけを数える面)。
+     * 統合してよいという意味ではなく、**別概念として意図的に残していること**の記録。
+     */
+    case DifferentCriterion = 'different_criterion';
+}
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index 95334d7..4fd31c2 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -18,6 +18,7 @@
 use App\Models\Project;
 use App\Models\User;
 use App\Models\VideoManual;
+use App\Services\Manual\AdoptedReadyTakeCoverage;
 use App\Services\Manual\VideoManualService;
 use App\Support\Seo\SeoManager;
 use Illuminate\Http\RedirectResponse;
@@ -109,6 +110,16 @@ public function show(Request $request, Project $project, VideoManual $manual, Se
         $analysisJob = $manuals->displayAnalysisJob($manual);
         $renderJob = $manuals->displayRenderJob($manual);
         $previewJob = $manuals->displayPreviewJob($manual);
+        // 再生できるプレビュー (最新 succeeded preview)。**id だけでなく行そのもの**を props に載せる:
+        // 動画 URL と「黒背景が何カット分か」の注記が同一オブジェクトから出るため、
+        // 最新 preview job と再生対象が別世代になる穴が構造的に消える (T148)。
+        // succeeded preview のみを見るため staleness 抑制の対象外 (不変)。
+        $playbackJob = $manual->renderJobs()
+            ->where('kind', RenderKind::Preview->value)
+            ->where('status', JobStatus::Succeeded->value)
+            ->whereNotNull('output_path')
+            ->latest('id')
+            ->first();
 
         return Inertia::render('Manuals/Show', [
             'project' => [
@@ -139,13 +150,13 @@ public function show(Request $request, Project $project, VideoManual $manual, Se
                 'previewJob' => $previewJob === null
                     ? null
                     : RenderJobData::fromJob($previewJob, $manual)->toArray(),
-                // playbackJobId は succeeded preview のみを見るため staleness 抑制の対象外 (不変)
-                'playbackJobId' => $manual->renderJobs()
-                    ->where('kind', RenderKind::Preview->value)
-                    ->where('status', JobStatus::Succeeded->value)
-                    ->whereNotNull('output_path')
-                    ->latest('id')
-                    ->value('id'),
+                'playbackJob' => $playbackJob === null
+                    ? null
+                    : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
+                // 「使用できる採用テイクがない」カットの充足状況。render の 422 と**同じ述語**から出す
+                // = 判断基準を 1 箇所に置く (bug-hunt F-1-01)。描画時点のスナップショットであり
+                // 常に最新ではない (押下は止めないので詰みにはならない)。
+                'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
             ],
             'canManage' => $user->can('update', $manual),
             'categories' => $this->categoryOptions($project), // 複製ダイアログのカテゴリ選択肢 (既存 helper 再利用)
diff --git a/app/Http/Resources/Manual/RenderJobResource.php b/app/Http/Resources/Manual/RenderJobResource.php
index 5bcd666..a828f30 100644
--- a/app/Http/Resources/Manual/RenderJobResource.php
+++ b/app/Http/Resources/Manual/RenderJobResource.php
@@ -22,7 +22,8 @@ final class RenderJobResource extends JsonResource
 
     /**
      * @return array{id: int, kind: string, status: string, step: string|null, progress: int|null,
-     *   error: string|null, error_code: string|null, manual_status: string}
+     *   error: string|null, error_code: string|null, manual_status: string,
+     *   placeholder_cut_count: int|null}
      */
     public function toArray(Request $request): array
     {
diff --git a/app/Models/RenderJob.php b/app/Models/RenderJob.php
index f42cea0..3f57eaa 100644
--- a/app/Models/RenderJob.php
+++ b/app/Models/RenderJob.php
@@ -33,6 +33,8 @@
  * @property int|null $ticket_reservation_id
  * @property int|null $triggered_by
  * @property string|null $output_path
+ * @property int|null $placeholder_cut_count 生成物に含まれたプレースホルダ (黒背景) クリップ数。
+ *                                           null = その動画について言えることが無い (既存行 / queued / running / finalize 未到達の failed)
  * @property string|null $error
  * @property RenderErrorCode|null $error_code
  * @property int|null $scenario_version_at_terminal
@@ -54,6 +56,7 @@ protected function casts(): array
             'status' => JobStatus::class,
             'step' => RenderStep::class,
             'progress' => 'integer',
+            'placeholder_cut_count' => 'integer',
             'scenario_version' => 'integer',
             'error_code' => RenderErrorCode::class,
         ];
diff --git a/app/Services/Manual/AdoptedReadyTakeCoverage.php b/app/Services/Manual/AdoptedReadyTakeCoverage.php
new file mode 100644
index 0000000..fe4d425
--- /dev/null
+++ b/app/Services/Manual/AdoptedReadyTakeCoverage.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\DataTransferObjects\Manual\Render\OrderedCut;
+use App\DataTransferObjects\Manual\TakeCoverageData;
+use App\Enums\Manual\TakeStatus;
+use App\Models\Cut;
+use App\Models\VideoManual;
+
+/**
+ * 「採用済みかつ ready のテイクを持つか」の**唯一の判定**。
+ *
+ * render (422 でブロック) と preview (ブロックせず告知) は**制裁が違うだけで基準は同じ**である。
+ * 基準がファイルをまたいで複製されると再び乖離する (bug-hunt F-1-01 の構造的原因) ため、
+ * 述語 isMissing() をここ 1 箇所に閉じ、`AdoptedReadyTakeCriterionInventoryTest` が
+ * deny-by-default で「他ファイルが同じ判定を書き直していないこと」を機械検査する。
+ *
+ * 読み取り専用 (cuts / takes / status を 1 バイトも書かない)。
+ */
+final class AdoptedReadyTakeCoverage
+{
+    /**
+     * 唯一の述語。**この式を他所へ写経しない**。
+     *
+     * TakeStatus は uploading / processing / ready / failed の 4 値を持つため、
+     * 本述語が真になるのは「まだ撮っていない」だけではない
+     * (採用済みだがアップロード中・処理中・失敗も含む = 「使用できる採用テイクがない」)。
+     *
+     * 前提: $cut は adoptedTake を eager load 済みで呼ぶこと
+     * (CutSequencer::orderedWithLabels が `with('adoptedTake')` を張っている)。
+     * lazy load でも結果は同じだが N+1 になる。
+     */
+    public static function isMissing(Cut $cut): bool
+    {
+        $take = $cut->adoptedTake;
+
+        return $take === null || $take->status !== TakeStatus::Ready;
+    }
+
+    /**
+     * 表示順カット列からの集計 (トリガー tx が既に持っている列を再利用する経路)。
+     *
+     * @param  list<OrderedCut>  $ordered
+     */
+    public static function fromOrdered(array $ordered): TakeCoverageData
+    {
+        $missing = [];
+        foreach ($ordered as $entry) {
+            if (self::isMissing($entry->cut)) {
+                $missing[] = $entry->label;
+            }
+        }
+
+        return new TakeCoverageData(totalCuts: count($ordered), missingLabels: $missing);
+    }
+
+    /** manual からの集計 (詳細画面 props の経路) */
+    public static function for(VideoManual $manual): TakeCoverageData
+    {
+        return self::fromOrdered(CutSequencer::orderedWithLabels($manual));
+    }
+}
diff --git a/app/Services/Manual/RenderJobService.php b/app/Services/Manual/RenderJobService.php
index 3bf519d..a5e01e7 100644
--- a/app/Services/Manual/RenderJobService.php
+++ b/app/Services/Manual/RenderJobService.php
@@ -12,7 +12,6 @@
 use App\Enums\Manual\RenderConflictType;
 use App\Enums\Manual\RenderErrorCode;
 use App\Enums\Manual\RenderKind;
-use App\Enums\Manual\TakeStatus;
 use App\Enums\Manual\VideoManualStatus;
 use App\Exceptions\Billing\InsufficientTicketsException;
 use App\Exceptions\Manual\RenderConflictException;
@@ -358,22 +357,22 @@ private function hasInFlight(VideoManual $locked, RenderKind $kind): bool
      * 採用テイク検証 (欠落 = 422。スキップしない: 標準化された成果物の完全性)。
      * adopted_take_id NULL または採用テイクが ready でないカットの表示ラベル一覧を message に含める。
      *
+     * 判定式そのものは持たない (AdoptedReadyTakeCoverage へ委譲)。render の 422 と
+     * preview の事前告知は**制裁が違うだけで基準は同じ**であり、式を写経すると再び乖離する
+     * (bug-hunt F-1-01)。
+     *
      * @param  list<OrderedCut>  $ordered
      */
     private function assertAllCutsHaveAdoptedReadyTakes(array $ordered): void
     {
-        $missing = [];
-        foreach ($ordered as $entry) {
-            $take = $entry->cut->adoptedTake;
-            if ($take === null || $take->status !== TakeStatus::Ready) {
-                $missing[] = $entry->label;
-            }
-        }
-        if ($missing !== []) {
-            throw ValidationException::withMessages([
-                'takes' => ['採用テイクが未設定のカットがあります: '.implode('、', $missing)],
-            ]);
+        $coverage = AdoptedReadyTakeCoverage::fromOrdered($ordered);
+        if ($coverage->missingCount() === 0) {
+            return;
         }
+
+        throw ValidationException::withMessages([
+            'takes' => ['採用テイクが未設定のカットがあります: '.implode('、', $coverage->missingLabels)],
+        ]);
     }
 
     /**
diff --git a/app/Services/Manual/RenderPipeline.php b/app/Services/Manual/RenderPipeline.php
index 96b8417..134b497 100644
--- a/app/Services/Manual/RenderPipeline.php
+++ b/app/Services/Manual/RenderPipeline.php
@@ -14,7 +14,6 @@
 use App\Enums\Manual\RenderErrorCode;
 use App\Enums\Manual\RenderKind;
 use App\Enums\Manual\RenderStep;
-use App\Enums\Manual\TakeStatus;
 use App\Enums\Manual\VideoManualStatus;
 use App\Enums\Security\ExternalCallKind;
 use App\Exceptions\Billing\InsufficientTicketsException;
@@ -111,6 +110,7 @@ public function run(int $renderJobId): void
                 outputPath: $manifest->outputKey,
                 clipDurationsMs: $composed->clipDurationsMs,
                 totalDurationMs: $composed->totalDurationMs,
+                placeholderCutCount: $manifest->placeholderCutCount(),
             );
             if ($this->finalize($job, $result)) {
                 $uploadedKey = null; // succeeded に到達した出力は正 (後始末しない)
@@ -236,11 +236,15 @@ private function buildManifest(RenderJob $job): RenderManifest
         });
     }
 
-    /** カット 1 枚分のクリップ仕様 (欠落は render=防御例外 / preview=Placeholder) */
+    /**
+     * カット 1 枚分のクリップ仕様 (欠落は render=防御例外 / preview=Placeholder)。
+     *
+     * 「使用できる採用テイクがあるか」の判定は **AdoptedReadyTakeCoverage が唯一の所在**である
+     * (ここで式を書き直すと render の 422 と preview の扱いが再び乖離する = bug-hunt F-1-01)。
+     */
     private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderClipSpec
     {
-        $take = $cut->adoptedTake;
-        if ($take === null || $take->status !== TakeStatus::Ready) {
+        if (AdoptedReadyTakeCoverage::isMissing($cut)) {
             if ($job->kind === RenderKind::Render) {
                 // trigger 422 + rendering 排他により起き得ない。防御的に fail させる
                 throw new LogicException("render job {$job->id}: 採用テイク欠落 ({$label})");
@@ -257,6 +261,11 @@ private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderCli
             );
         }
 
+        // 述語が false なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
+        // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
+        $take = $cut->adoptedTake;
+        Assert::notNull($take, 'isMissing() が false なら採用テイクは必ず存在する');
+
         $isStill = $cut->material_type === MaterialType::Still;
 
         return new RenderClipSpec(
@@ -316,6 +325,10 @@ private function finalize(RenderJob $job, RenderResult $result): bool
             $locked->status = JobStatus::Succeeded;
             $locked->progress = 100;
             $locked->output_path = $result->outputPath;
+            // 生成物の説明 (manifest 由来の実績値)。書き込み位置が finalize なのはロック順序の要請で、
+            // 値が確定する buildManifest は video_manuals を先にロックしているため、そこで
+            // render_jobs を UPDATE するとグローバル順 render_jobs → video_manuals の逆順取得になる。
+            $locked->placeholder_cut_count = $result->placeholderCutCount;
             $locked->save();
 
             // 旧世代 (同 manual・同 kind・output_path 非 NULL・id < 自分の succeeded)
diff --git a/app/Support/Security/AdoptedTakeReferenceInventory.php b/app/Support/Security/AdoptedTakeReferenceInventory.php
new file mode 100644
index 0000000..cb1c192
--- /dev/null
+++ b/app/Support/Security/AdoptedTakeReferenceInventory.php
@@ -0,0 +1,76 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Security;
+
+use App\Enums\Security\AdoptedTakeReferenceKind;
+
+/**
+ * `adoptedTake` relation を参照する app/ 配下ファイルの目録 (deny-by-default。T148)。
+ *
+ * 守る不変条件:
+ *   「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
+ *   `Services/Manual/AdoptedReadyTakeCoverage.php` ただ 1 ファイルである。
+ *
+ * 強制は `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`
+ * (exact-fit: 未登録の参照も、参照実体を失った stale entry も fail させる)。
+ */
+final class AdoptedTakeReferenceInventory
+{
+    /**
+     * app/ 相対パス => [区分, 根拠 (30 文字以上)]。
+     *
+     * @return array<string, array{kind: AdoptedTakeReferenceKind, rationale: string}>
+     */
+    public static function entries(): array
+    {
+        return [
+            'Services/Manual/AdoptedReadyTakeCoverage.php' => [
+                'kind' => AdoptedTakeReferenceKind::Canonical,
+                'rationale' => '判定式の実体。render の 422 と preview の事前告知・Placeholder 分岐が'
+                    .'同じ述語 isMissing() を通るための唯一の場所 (bug-hunt F-1-01 の再発防止)。',
+            ],
+            'Services/Manual/CutSequencer.php' => [
+                'kind' => AdoptedTakeReferenceKind::RelationWiring,
+                'rationale' => '表示順カット列の取得で with(adoptedTake) の eager load を張るだけで、'
+                    .'ready 判定も採用有無の判定も持たない (N+1 回避のための構造上の参照)。',
+            ],
+            'Services/Manual/RenderJobService.php' => [
+                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
+                'rationale' => '充足判定は AdoptedReadyTakeCoverage へ委譲済みで、残る参照は'
+                    .'尺上限ソフトゲートが採用テイクの duration_ms を読む 1 箇所だけである。',
+            ],
+            'Services/Manual/RenderPipeline.php' => [
+                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
+                'rationale' => 'clipSpecFor が isMissing() を呼んで Placeholder 分岐を決め、'
+                    .'非欠落側でのみ素材パス (video_path) 取得のため take 実体を読む。',
+            ],
+            'Models/Cut.php' => [
+                'kind' => AdoptedTakeReferenceKind::RelationWiring,
+                'rationale' => 'adoptedTake の belongsTo relation 宣言そのもの。'
+                    .'判定式は一切持たず、参照の起点を提供するだけのモデル定義である。',
+            ],
+            'DataTransferObjects/Capture/CaptureManualDetailData.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => '撮影ナビの表示用に採用テイクの実体を読むだけで ready 判定はしない。'
+                    .'撮影中の端末に「今どれを採用しているか」を見せる別概念の面である。',
+            ],
+            'Http/Controllers/Capture/CaptureManualController.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => 'whereHas(adoptedTake) による採用済みカット数の集計。'
+                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。',
+            ],
+            'Services/Dashboard/DashboardService.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => 'whereDoesntHave(adoptedTake) による撮影待ち件数の集計。'
+                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。',
+            ],
+            'Console/Commands/Development/PipelineSmokeCommand.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => 'bug-hunt のパイプライン通し確認で未採用カット件数を数えるだけの'
+                    .'開発用コマンド。ready 状態は見ず、レンダの充足判定には関与しない。',
+            ],
+        ];
+    }
+}
diff --git a/database/factories/RenderJobFactory.php b/database/factories/RenderJobFactory.php
index 1925b1a..fc5ce99 100644
--- a/database/factories/RenderJobFactory.php
+++ b/database/factories/RenderJobFactory.php
@@ -33,6 +33,7 @@ public function definition(): array
             'scenario_version' => 0,
             'ticket_reservation_id' => null,
             'output_path' => null,
+            'placeholder_cut_count' => null,
             'error' => null,
             'error_code' => null,
         ];
@@ -60,16 +61,29 @@ public function running(): static
         ]);
     }
 
-    /** 成功確定の状態 (output_path 付き) */
-    public function succeeded(string $outputPath): static
+    /**
+     * 成功確定の状態 (output_path 付き)。
+     * アプリが生成した succeeded 行は必ず件数を持つため既定は 0 (黒背景なしで生成された)。
+     */
+    public function succeeded(string $outputPath, int $placeholderCutCount = 0): static
     {
         return $this->state(fn () => [
             'status' => JobStatus::Succeeded->value,
             'progress' => 100,
             'output_path' => $outputPath,
+            'placeholder_cut_count' => $placeholderCutCount,
         ]);
     }
 
+    /**
+     * T148 **以前**から在る succeeded 行の再現 (placeholder_cut_count は null)。
+     * backfill しない契約 (null は 0 と同一視しない) の UI 分岐を検証するための fixture。
+     */
+    public function legacySucceeded(string $outputPath): static
+    {
+        return $this->succeeded($outputPath)->state(fn () => ['placeholder_cut_count' => null]);
+    }
+
     /** 失敗確定の状態 */
     public function failed(
         RenderErrorCode $code = RenderErrorCode::Internal,
diff --git a/database/migrations/2026_08_11_021500_add_placeholder_cut_count_to_render_jobs_table.php b/database/migrations/2026_08_11_021500_add_placeholder_cut_count_to_render_jobs_table.php
new file mode 100644
index 0000000..c3e5bba
--- /dev/null
+++ b/database/migrations/2026_08_11_021500_add_placeholder_cut_count_to_render_jobs_table.php
@@ -0,0 +1,28 @@
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
+    public function up(): void
+    {
+        Schema::table('render_jobs', function (Blueprint $table): void {
+            // その動画が実際に含んだプレースホルダ (黒背景) クリップ数。
+            // null = 「その動画について言えることが無い」(既存行 / queued / running / finalize 未到達の failed)。
+            // **null を 0 と同一視しない** (0 = 黒背景ゼロで生成された、という積極的な事実)。
+            // 索引は張らない (検索経路が無く、常に単一行の表示に使う)。
+            $table->unsignedInteger('placeholder_cut_count')->nullable()->after('output_path');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('render_jobs', function (Blueprint $table): void {
+            $table->dropColumn('placeholder_cut_count');
+        });
+    }
+};
diff --git a/docs/architecture.md b/docs/architecture.md
index 7454eb4..0663e7b 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -608,6 +608,66 @@ ### レンダジョブの運用契約
 - ローカル/テストの検証: パイプラインの同期実行は `RenderPipeline::run()` の直接呼び出し +
   fake `VideoComposer` (container swap)、dispatch の検証は `Queue::fake()`
 
+#### 採用テイク充足判定の単一化と告知契約 (T148)
+
+「採用済みかつ ready のテイクを持つか」の判定は **`Services/Manual/AdoptedReadyTakeCoverage`
+の 1 ファイルだけ**が持つ。以前は同じ式が `RenderJobService` と `RenderPipeline` に複製され、
+**preview のトリガーには存在しなかった**ため、完成動画生成が 422 でブロックする状態を
+プレビューは黙って通し、全編黒画面の動画を警告なしで出していた (bug-hunt F-1-01)。
+
+- **述語はカット単位で切り出す** (`AdoptedReadyTakeCoverage::isMissing(Cut)`)。集計 API だけを
+  共有すると manifest 側 (Placeholder 分岐) で式が再実装されるため、**3 消費者
+  (render の 422 / 詳細画面 props / manifest の Placeholder 分岐) がすべて同じ述語を通る**。
+- **制裁だけが非対称で、基準は同じ**である。render は 422 でブロックする (標準化された成果物の
+  完全性)。preview は**ブロックしない** — 未撮影は制作途中の正常な状態であり、preview は
+  チケット非消費で manual status も触らない「途中経過を見る」機能だからである。
+  代わりに詳細画面 props (`render.coverage`) が**押す前に**同じ件数を告知する。
+  **必須条件未充足を理由にボタンを disabled にしない / 確認ダイアログも足さない**
+  (AGENTS.md 禁止事項 8)。
+- **告知文は述語の意味をそのまま言う**。`TakeStatus` は uploading / processing / ready / failed の
+  4 値を持つため、述語が真になるのは「まだ撮っていない」だけではない。よって
+  「未撮影」と断定せず「撮影・処理が完了した採用テイクがありません」と書く。
+- **`render_jobs.placeholder_cut_count` の値契約** (生成物の説明であり、現在状態からの
+  再計算はしない。値の出所は buildManifest が確定した clips ただ 1 つ):
+
+  | 行の状態 | 値 |
+  |---|---|
+  | 本列の追加以前から在る行 | `null` (**backfill しない**) |
+  | queued / running / finalize 未到達の failed | `null` |
+  | succeeded な preview | 実際にプレースホルダへ落ちたクリップ数 |
+  | succeeded な render | `0` (render は欠落し得ない) |
+
+  **`null` を `0` と同一視しない**。`0` は「黒背景ゼロで生成された」という積極的な事実であり、
+  `null` は「その動画について言えることが無い」である。UI は `null` では何も表示しない。
+- **書き込み位置は `finalize`** である。値が確定するのは `buildManifest` だが、そこは
+  `video_manuals` を先にロックしているため、同 tx で `render_jobs` を UPDATE すると
+  グローバル順の**逆順取得**になる。`finalize` は既に `render_jobs → video_manuals` の正順で
+  ロック済みなので、そこに 1 列足すのが唯一の順序安全な置き場である。
+- **再生対象は id ではなく job そのものを props に載せる** (`render.playbackJob`)。動画 URL と
+  「黒背景が何カット分あったか」の注記が同一オブジェクトから出るため、最新 preview job と
+  再生中の動画が別世代になる穴が条件分岐ではなく構造で消える。
+- **機械強制**: `adoptedTake` を参照する `app/` 配下のファイルは
+  `Support/Security/AdoptedTakeReferenceInventory` へ区分 (`AdoptedTakeReferenceKind`) と
+  30 文字以上の根拠付きで登録する (deny-by-default・exact-fit)。判定式の同居
+  (`adoptedTake` 参照と `TakeStatus::Ready` の同一ファイル出現) を許されるのは Canonical 1 件と
+  名指し免除だけで、免除には「relation の実体を参照しない」機械検査される前提が付く
+  (`tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`)。
+- **保証しないもの (誇張しない)**:
+  - 事前告知は**描画時点のスナップショット**である。別タブ・別ユーザー・別デバイスの撮影で
+    古くなる (押下を止めないため詰みは作らないが「常に最新」ではない)。
+  - gate は静的走査であり、文字列変数経由の relation 名・動的プロパティアクセス・
+    `Take` を別経路で引いて status を見るコードには**沈黙する**。判定式の同居検出も
+    「同一ファイル内に `TakeStatus::Ready` が出るか」という近似で、別ファイルへ切り出して
+    同じ判定を書く経路は検出できない。
+  - `placeholder_cut_count` が語るのは**プレースホルダに落ちたクリップ数だけ**で、
+    その動画が実用に足るか (品質) は何も語らない。
+  - **プレースホルダ映像自体は変えない** (黒背景 + 字幕は意図的な仕様)。
+  - ダッシュボード / 撮影ナビの撮影待ちカウント (`whereDoesntHave('adoptedTake')`) との差は
+    残る (あちらは「採用済みだが ready でないテイク」を撮影済みとして数える別基準)。
+    統合せず `DifferentCriterion` として記録するだけである。
+  - Browser lane が見るのは**告知の可視性と押下可能性**のみで、実 ffmpeg 合成・黒画面の
+    目視確認は staging worker での運用確認に委ねる。
+
 ## サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運用契約
 
 課金ゲート反転 (P4) 後、未契約組織は業務 route group に入れない。**遮断された先の着地**と
diff --git a/resources/js/components/features/manual/RenderPanel.svelte b/resources/js/components/features/manual/RenderPanel.svelte
index fa70baf..8b9018c 100644
--- a/resources/js/components/features/manual/RenderPanel.svelte
+++ b/resources/js/components/features/manual/RenderPanel.svelte
@@ -8,7 +8,7 @@
     import Card from "@/components/atoms/Card.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import { csrfToken } from "@/lib/csrf";
-    import type { RenderJobProps, VideoManualStatus } from "@/types/manual";
+    import type { RenderJobProps, TakeCoverageProps, VideoManualStatus } from "@/types/manual";
     import { RENDER_STEP_LABELS } from "@/types/manual";
 
     /**
@@ -19,6 +19,9 @@
      *   追わない)。単一 interval が追跡中 job id 集合を順に fetch し、終端条件のみ kind 別分岐
      *   (render: succeeded → router.reload() / preview: succeeded → <video> 表示)
      * - failed + error_code=scenario_version_changed は「作り直す」CTA (preview 再 POST)
+     * - 事前告知 (coverage) / 事後説明 (playbackJob.placeholder_cut_count) は**別概念**:
+     *   前者は「今から作ると黒背景が出る」、後者は「今再生している動画に黒背景があった」。
+     *   どちらもボタンを止めない (必須条件未充足を理由に disabled にしない = 禁止事項 8)
      */
     interface Props {
         projectId: number;
@@ -26,12 +29,21 @@
         manualStatus: VideoManualStatus;
         job: RenderJobProps | null;
         previewJob: RenderJobProps | null;
-        playbackJobId: number | null;
+        playbackJob: RenderJobProps | null;
+        coverage: TakeCoverageProps;
         canManage: boolean;
     }
 
-    let { projectId, manualId, manualStatus, job, previewJob, playbackJobId, canManage }: Props =
-        $props();
+    let {
+        projectId,
+        manualId,
+        manualStatus,
+        job,
+        previewJob,
+        playbackJob: playbackJobProp,
+        coverage,
+        canManage,
+    }: Props = $props();
 
     // 作業状態 (props から一度だけ seed し、以後は XHR 応答で更新する)
     // svelte-ignore state_referenced_locally
@@ -39,7 +51,7 @@
     // svelte-ignore state_referenced_locally
     let preview = $state<RenderJobProps | null>(previewJob);
     // svelte-ignore state_referenced_locally
-    let playbackId = $state<number | null>(playbackJobId);
+    let playbackJob = $state<RenderJobProps | null>(playbackJobProp);
     // svelte-ignore state_referenced_locally
     let status = $state<VideoManualStatus>(manualStatus);
     let starting = $state(false);
@@ -66,6 +78,25 @@
     const needsRegenerate = $derived(
         status === "ready" && renderJob?.status === "succeeded",
     );
+    /**
+     * 事前告知の要約ラベル。missing_labels は props 側で先頭 10 件に打ち切られているため、
+     * 打ち切られていることを UI 側でも明示する (件数は missing_count が正)。
+     */
+    const missingLabelSummary = $derived(
+        coverage.missing_labels.length < coverage.missing_count
+            ? `${coverage.missing_labels.join("、")} ほか ${
+                  coverage.missing_count - coverage.missing_labels.length
+              } 件`
+            : coverage.missing_labels.join("、"),
+    );
+    /** 再生している動画**そのもの**の実績値だけを出す (null は 0 と同一視しない = 何も言わない) */
+    const playbackNote = $derived(
+        playbackJob !== null &&
+            playbackJob.placeholder_cut_count !== null &&
+            playbackJob.placeholder_cut_count > 0
+            ? playbackJob.placeholder_cut_count
+            : null,
+    );
     // ポーリング対象の job id 集合 (id のみに依存を狭め、応答更新で再購読しない)
     const pollKey = $derived(
         [
@@ -126,7 +157,8 @@
             } else {
                 preview = body;
                 if (body.status === "succeeded") {
-                    playbackId = body.id;
+                    // 注記と動画 URL は同一オブジェクトから出す (別世代の値で説明しない)
+                    playbackJob = body;
                 }
             }
         };
@@ -324,6 +356,16 @@
 
     {#if canManage}
         <div class="mt-6 flex flex-col gap-2">
+            {#if coverage.missing_count > 0}
+                <!-- 事前告知: プレビューは生成できる (ボタンは止めない) が黒背景になることを先に伝える。
+                     TakeStatus は 4 値あるため「未撮影」と断定せず述語の意味をそのまま言う。 -->
+                <div data-testid="preview-coverage-note">
+                    <Alert type="warning" title="プレビューに黒背景の区間があります">
+                        {coverage.missing_count} / {coverage.total_cuts}
+                        件のカットに、撮影・処理が完了した採用テイクがありません ({missingLabelSummary})。プレビューは生成できますが、該当区間は黒背景になります。完成動画の生成には、すべてのカットで撮影・処理が完了した採用テイクが必要です。
+                    </Alert>
+                </div>
+            {/if}
             {#if previewInFlight}
                 <div
                     class="flex items-center gap-2 text-body text-text-secondary"
@@ -365,16 +407,26 @@
                     </Alert>
                 </div>
             {/if}
-            {#if playbackId !== null && !previewInFlight}
+            {#if playbackJob !== null && !previewInFlight}
+                {#if playbackNote !== null}
+                    <!-- 事後説明: 注記と動画 URL は同一の playbackJob から出る (別世代の値で説明しない) -->
+                    <p
+                        class="text-caption text-text-secondary"
+                        data-testid="preview-placeholder-note"
+                    >
+                        このプレビューは {playbackNote}
+                        件のカットに使用できる採用テイクがないため、その区間が黒背景になっています。
+                    </p>
+                {/if}
                 <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
-                <!-- aria-label は固定文言でよい: playbackId の供給源は初期値 (Controller が
+                <!-- aria-label は固定文言でよい: playbackJob の供給源は初期値 (Controller が
                      kind=Preview ∧ status=Succeeded で抽出) と poll の preview 分岐だけで、
                      render job が入る経路が無い (完成動画と取り違わない)。 -->
                 <video
                     controls
                     preload="metadata"
                     class="w-full rounded-md bg-neutral"
-                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackId}/playback`}
+                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackJob.id}/playback`}
                     aria-label="プレビュー動画"
                     data-testid="preview-video"
                 ></video>
diff --git a/resources/js/pages/Manuals/Show.svelte b/resources/js/pages/Manuals/Show.svelte
index 0f5d821..daa6b5f 100644
--- a/resources/js/pages/Manuals/Show.svelte
+++ b/resources/js/pages/Manuals/Show.svelte
@@ -133,7 +133,8 @@
                 manualStatus={manual.status}
                 job={render.job}
                 previewJob={render.previewJob}
-                playbackJobId={render.playbackJobId}
+                playbackJob={render.playbackJob}
+                coverage={render.coverage}
                 {canManage}
             />
 
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 5be606c..135c588 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -237,6 +237,22 @@ export interface RenderJobProps {
     error: string | null;
     error_code: RenderErrorCode | null;
     manual_status: VideoManualStatus;
+    /**
+     * 生成物に含まれたプレースホルダ (黒背景) クリップ数。
+     * null = その動画について言えることが無い (未完了 / T148 以前の succeeded 行)。
+     * **null を 0 と同一視しない** (0 は「黒背景ゼロで生成された」という積極的な事実)。
+     */
+    placeholder_cut_count: number | null;
+}
+
+/** PHP: App\DataTransferObjects\Manual\TakeCoverageData::toProps() と対 */
+export interface TakeCoverageProps {
+    /** カット総数 */
+    total_cuts: number;
+    /** 使用できる採用テイクがないカット数 (**打ち切らない全件数**) */
+    missing_count: number;
+    /** 該当カットの表示ラベル (先頭 10 件で打ち切られる。件数は missing_count が正) */
+    missing_labels: string[];
 }
 
 /** PHP: App\Enums\Manual\RenderConflictType と対 (値集合同期テストあり) */
@@ -259,8 +275,16 @@ export interface RenderProps {
     job: RenderJobProps | null;
     /** 最新 kind=preview の job (無ければ null) */
     previewJob: RenderJobProps | null;
-    /** 再生可能な最新 succeeded preview の job id (無ければ null) */
-    playbackJobId: number | null;
+    /**
+     * 再生可能な最新 succeeded preview の job (無ければ null)。
+     * 動画 URL と黒背景の注記が同一オブジェクトから出る (別世代の値で説明しないため)。
+     */
+    playbackJob: RenderJobProps | null;
+    /**
+     * 採用テイクの充足状況 (描画時点のスナップショット。常に最新ではない)。
+     * 生成物の実績は playbackJob.placeholder_cut_count が語る (別概念なので混ぜない)。
+     */
+    coverage: TakeCoverageProps;
 }
 
 /** PHP: App\Enums\Manual\ScenarioConflictType と対 (discriminated union) */
diff --git a/tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php b/tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php
new file mode 100644
index 0000000..6bc51f5
--- /dev/null
+++ b/tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php
@@ -0,0 +1,309 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\AdoptedTakeReferenceKind;
+use App\Support\Security\AdoptedTakeReferenceInventory;
+use Tests\Support\PhpTokenScan;
+
+/*
+ * 採用テイク充足判定の単一化 (T148 / bug-hunt F-1-01) の deny-by-default 目録。
+ *
+ * 不変条件:
+ *   「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
+ *   `Services/Manual/AdoptedReadyTakeCoverage.php` ただ 1 ファイルである。
+ *   `adoptedTake` に触れる app/ 配下のファイルは、区分と 30 文字以上の根拠を付けて
+ *   `AdoptedTakeReferenceInventory` へ登録しなければならない。
+ *
+ * 走査は PhpTokenScan::normalize() ベース (コメント / docblock 内の出現は数えない)。
+ *
+ * 検出 A (参照の母集団): 識別子 `adoptedTake` (プロパティフェッチ) または
+ *   文字列リテラル 'adoptedTake' (with / whereHas / whereDoesntHave / doesntHave の引数) を含む .php
+ * 検出 A' (プロパティフェッチ形): `->adoptedTake` / `?->adoptedTake` のみ
+ * 検出 B (判定式の同居): 検出 A に該当し、**かつ** `TakeStatus::Ready` を含むファイル
+ *
+ * 検出 B の期待集合は Canonical 1 ファイル + 名指し免除だけである。免除には
+ * **機械検査される前提** (検出 A' を持たない = relation の実体を一度も参照しないため
+ * in-memory の採用テイク ready 判定を書きようがない) が付く。
+ *
+ * 保証しないもの (誇張しない):
+ * - 静的走査であり、文字列変数経由の relation 名 (`$rel = 'adopted'.'Take'`)・動的プロパティ
+ *   アクセス・`Take::query()->where('status', ...)` の別経路には**沈黙する**
+ * - 検出 B は「同一ファイル内に TakeStatus::Ready が出現するか」という近似であり、
+ *   別ファイルへ切り出して同じ判定を書く経路は検出できない
+ * - 免除ファイル内で DB 形の判定 (`whereHas('adoptedTake', fn ($q) => $q->where('status', ...))`)
+ *   を書かれた場合は検出できない (前提が閉じるのは in-memory 形だけである)
+ */
+final class AdoptedTakeCriterionScanner
+{
+    /**
+     * 検出 B の名指し免除 (app/ 相対パス => 30 文字以上の根拠)。
+     *
+     * 「同一ファイル内に adoptedTake 参照と TakeStatus::Ready が同居する」だけの近似では
+     * 拾ってしまう既存の無関係な同居を、前提付きで明示的に許す枠。
+     * `criterionExemptionPremiseHolds()` が機械的に前提を検査する。
+     */
+    public const COOCCURRENCE_EXEMPT = [
+        'Console/Commands/Development/PipelineSmokeCommand.php' => '未採用カット数の集計 (doesntHave の文字列リテラル) と、撮影段で登録した'
+            .'テイク自身の ready 確認が同一ファイルに並ぶだけで、両者は同じ式ではない。'
+            .'プロパティフェッチ形の参照を一度も持たないため採用テイクの ready 判定を書けない。',
+    ];
+
+    /** @return list<string> 検出 A に該当する app/ 相対パス (昇順) */
+    public static function referencingFiles(): array
+    {
+        return self::scan(static fn (array $tokens): bool => self::hasAnyReference($tokens));
+    }
+
+    /** @return list<string> 検出 A' (プロパティフェッチ形) に該当する app/ 相対パス */
+    public static function propertyFetchFiles(): array
+    {
+        return self::scan(static fn (array $tokens): bool => self::hasPropertyFetch($tokens));
+    }
+
+    /** @return list<string> 検出 B (判定式の同居) に該当する app/ 相対パス */
+    public static function criterionFiles(): array
+    {
+        return self::scan(static fn (array $tokens): bool => self::hasAnyReference($tokens)
+            && self::hasReadyStatusReference($tokens));
+    }
+
+    /**
+     * 免除の前提: そのファイルは relation の実体を一度も参照しない
+     * (= in-memory の「採用テイクが ready か」を書きようがない)。
+     */
+    public static function criterionExemptionPremiseHolds(string $relative): bool
+    {
+        return ! in_array($relative, self::propertyFetchFiles(), true);
+    }
+
+    /** @param  list<array{id: int|null, text: string, line: int}>  $tokens */
+    public static function hasAnyReference(array $tokens): bool
+    {
+        foreach ($tokens as $token) {
+            if ($token['id'] === T_STRING && $token['text'] === 'adoptedTake') {
+                return true;
+            }
+            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING
+                && trim($token['text'], "'\"") === 'adoptedTake') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /** @param  list<array{id: int|null, text: string, line: int}>  $tokens */
+    public static function hasPropertyFetch(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count - 1; $i++) {
+            $operator = $tokens[$i]['id'];
+            if ($operator !== T_OBJECT_OPERATOR && $operator !== T_NULLSAFE_OBJECT_OPERATOR) {
+                continue;
+            }
+            if ($tokens[$i + 1]['id'] === T_STRING && $tokens[$i + 1]['text'] === 'adoptedTake') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * `TakeStatus::Ready` の参照 (部分修飾・完全修飾も末尾セグメントで判定する)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function hasReadyStatusReference(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count - 2; $i++) {
+            $token = $tokens[$i];
+            if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+                continue;
+            }
+            $segments = explode('\\', ltrim($token['text'], '\\'));
+            if (end($segments) !== 'TakeStatus') {
+                continue;
+            }
+            if ($tokens[$i + 1]['id'] !== T_DOUBLE_COLON) {
+                continue;
+            }
+            if ($tokens[$i + 2]['id'] === T_STRING && $tokens[$i + 2]['text'] === 'Ready') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * @param  callable(list<array{id: int|null, text: string, line: int}>): bool  $matches
+     * @return list<string>
+     */
+    private static function scan(callable $matches): array
+    {
+        $appDir = self::appDir();
+        $found = [];
+        foreach (self::phpFiles($appDir) as $path) {
+            $source = file_get_contents($path);
+            if ($source === false) {
+                throw new RuntimeException("Failed to read PHP source: {$path}");
+            }
+            if ($matches(PhpTokenScan::normalize($source))) {
+                $found[] = substr($path, strlen($appDir) + 1);
+            }
+        }
+        sort($found);
+
+        return $found;
+    }
+
+    public static function appDir(): string
+    {
+        $appDir = realpath(__DIR__.'/../../app');
+        if (! is_string($appDir)) {
+            throw new RuntimeException('app/ ディレクトリを解決できません');
+        }
+
+        return $appDir;
+    }
+
+    /** @return list<string> */
+    public static function phpFiles(string $dir): array
+    {
+        $files = [];
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
+        );
+        /** @var SplFileInfo $file */
+        foreach ($iterator as $file) {
+            if ($file->isFile() && $file->getExtension() === 'php') {
+                $files[] = $file->getPathname();
+            }
+        }
+        sort($files);
+
+        return $files;
+    }
+
+    /** @return list<string> Canonical 区分に登録された app/ 相対パス */
+    public static function canonicalFiles(): array
+    {
+        $files = [];
+        foreach (AdoptedTakeReferenceInventory::entries() as $relative => $entry) {
+            if ($entry['kind'] === AdoptedTakeReferenceKind::Canonical) {
+                $files[] = $relative;
+            }
+        }
+        sort($files);
+
+        return $files;
+    }
+}
+
+test('ケース 1: adoptedTake を参照する app/ のファイルはすべて目録に登録されている', function (): void {
+    $registered = array_keys(AdoptedTakeReferenceInventory::entries());
+    $unregistered = array_values(array_diff(AdoptedTakeCriterionScanner::referencingFiles(), $registered));
+
+    expect($unregistered)->toBe([],
+        'adoptedTake を参照する新しいファイルは AdoptedTakeReferenceInventory へ区分 + 根拠付きで'
+        .'登録してください (deny-by-default): '.implode(', ', $unregistered));
+});
+
+test('ケース 2: 目録の全エントリが実在の参照を持つ (exact-fit)', function (): void {
+    $referencing = AdoptedTakeCriterionScanner::referencingFiles();
+    $stale = array_values(array_diff(array_keys(AdoptedTakeReferenceInventory::entries()), $referencing));
+
+    expect($stale)->toBe([],
+        '参照実体を失った目録エントリは削除してください (残置すると gate が常時緑になる): '
+        .implode(', ', $stale));
+});
+
+test('ケース 3: 走査母集団が空でない (負のコントロール)', function (): void {
+    expect(AdoptedTakeCriterionScanner::phpFiles(AdoptedTakeCriterionScanner::appDir()))->not->toBeEmpty();
+    expect(count(AdoptedTakeCriterionScanner::referencingFiles()))
+        ->toBeGreaterThanOrEqual(5, '走査が壊れて母集団が縮んでいます (規則が空振りしている)');
+});
+
+test('ケース 4: ready 判定を同居させてよいのは Canonical と名指し免除だけである', function (): void {
+    $allowed = array_merge(
+        AdoptedTakeCriterionScanner::canonicalFiles(),
+        array_keys(AdoptedTakeCriterionScanner::COOCCURRENCE_EXEMPT),
+    );
+    $violations = array_values(array_diff(AdoptedTakeCriterionScanner::criterionFiles(), $allowed));
+
+    expect($violations)->toBe([],
+        '「採用済みかつ ready のテイクを持つか」の判定式は AdoptedReadyTakeCoverage だけが持てます。'
+        .'判定は isMissing() へ委譲してください: '.implode(', ', $violations));
+});
+
+test('ケース 5: Canonical ファイルは実際に判定式を持つ (規則の空振り防止)', function (): void {
+    $criterion = AdoptedTakeCriterionScanner::criterionFiles();
+
+    expect($criterion)->not->toBeEmpty();
+    foreach (AdoptedTakeCriterionScanner::canonicalFiles() as $canonical) {
+        expect(in_array($canonical, $criterion, true))->toBeTrue(
+            "Canonical 登録の {$canonical} に判定式がありません (検出規則が空振りしています)");
+    }
+});
+
+test('ケース 6: 目録の根拠は 30 文字以上ある', function (): void {
+    foreach (AdoptedTakeReferenceInventory::entries() as $relative => $entry) {
+        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30,
+            "{$relative} の根拠が短すぎます (30 文字以上)");
+    }
+    foreach (AdoptedTakeCriterionScanner::COOCCURRENCE_EXEMPT as $relative => $rationale) {
+        expect(mb_strlen($rationale))->toBeGreaterThanOrEqual(30,
+            "{$relative} の免除根拠が短すぎます (30 文字以上)");
+    }
+});
+
+test('ケース 7: Canonical 区分の登録は 1 件だけである', function (): void {
+    expect(AdoptedTakeCriterionScanner::canonicalFiles())
+        ->toBe(['Services/Manual/AdoptedReadyTakeCoverage.php']);
+});
+
+test('ケース 8: 検出 B の免除は「relation の実体を参照しない」前提を満たす', function (): void {
+    $criterion = AdoptedTakeCriterionScanner::criterionFiles();
+
+    foreach (AdoptedTakeCriterionScanner::COOCCURRENCE_EXEMPT as $relative => $rationale) {
+        // stale な免除を残さない (免除対象が検出 B から外れたら免除ごと消す)
+        expect(in_array($relative, $criterion, true))->toBeTrue(
+            "{$relative} は検出 B に該当しません。免除エントリを削除してください");
+        expect(AdoptedTakeCriterionScanner::criterionExemptionPremiseHolds($relative))->toBeTrue(
+            "{$relative} が adoptedTake の実体を参照し始めました。免除の前提が崩れています");
+    }
+});
+
+test('scanner 自己検証: コメント / docblock 内の出現は数えない', function (): void {
+    $source = <<<'PHP'
+<?php
+// $cut->adoptedTake と TakeStatus::Ready はコメント
+/** 'adoptedTake' も docblock */
+class Example {}
+PHP;
+    $tokens = PhpTokenScan::normalize($source);
+
+    expect(AdoptedTakeCriterionScanner::hasAnyReference($tokens))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::hasPropertyFetch($tokens))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::hasReadyStatusReference($tokens))->toBeFalse();
+});
+
+test('scanner 自己検証: プロパティフェッチ / 文字列リテラル / ready 参照を検出する', function (): void {
+    $fetch = PhpTokenScan::normalize('<?php $take = $cut->adoptedTake;');
+    $nullsafe = PhpTokenScan::normalize('<?php $s = $cut?->adoptedTake?->status;');
+    $literal = PhpTokenScan::normalize("<?php \$q->whereDoesntHave('adoptedTake');");
+    $ready = PhpTokenScan::normalize('<?php $b = $t->status !== TakeStatus::Ready;');
+    $qualified = PhpTokenScan::normalize('<?php $b = \App\Enums\Manual\TakeStatus::Ready;');
+    $otherCase = PhpTokenScan::normalize('<?php $b = TakeStatus::Failed;');
+
+    expect(AdoptedTakeCriterionScanner::hasPropertyFetch($fetch))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasPropertyFetch($nullsafe))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasPropertyFetch($literal))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::hasAnyReference($literal))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasReadyStatusReference($ready))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasReadyStatusReference($qualified))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasReadyStatusReference($otherCase))->toBeFalse();
+});
diff --git a/tests/Browser/PreviewCoverageNoticeTest.php b/tests/Browser/PreviewCoverageNoticeTest.php
new file mode 100644
index 0000000..9657dca
--- /dev/null
+++ b/tests/Browser/PreviewCoverageNoticeTest.php
@@ -0,0 +1,114 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\TakeStatus;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\VideoManual;
+
+/*
+|--------------------------------------------------------------------------
+| プレビューの事前告知 (T148 / bug-hunt F-1-01)
+|--------------------------------------------------------------------------
+|
+| 採用テイクが揃っていない状態で「プレビュー生成」を押すと、警告なしで全編黒画面の
+| 動画が生成完了していた (完成動画生成は同条件を 422 でブロックする)。
+| 修正の要点は「preview を止めること」ではなく「押す前に同じ基準で知らせること」なので、
+| 実ブラウザで見るのは次の 2 点だけである:
+|   E-1 注記が押す前に見えている
+|   E-2 注記が出ていてもボタンは押下可能である (禁止事項 8: disabled にしない)
+|
+| **クリックしない**: Browser lane には ffmpeg も object storage も無く、押すと
+| RunManualRender の実行経路へ進みうる。押下可能性は disabled / aria-disabled の
+| 不在と可視性で判定する。生成物の説明 (placeholder_cut_count の注記) は vitest 側で固定する。
+|
+| 撮影 PWA と同じく業務 route は require-active-subscription group 内なので
+| contractPaidPlan を通さないと /billing-required へ着地する。
+|
+| 実ブラウザは public/build のビルド済アセットを読むため、UI 変更後は先に pnpm build する。
+|
+*/
+
+/**
+ * 採用テイクが揃っていない ready manual (3 カット中 2 カットが未充足)。
+ *
+ * @return array{Project, VideoManual}
+ */
+function previewCoverageNoticeFixture(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization);
+
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'created_by' => $owner->id,
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 2,
+    ]);
+
+    // 1 枚目: 採用済み ready (充足)
+    $adopted = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($adopted)->create(['duration_ms' => 5_000]);
+    $adopted->forceFill(['adopted_take_id' => $take->id])->save();
+
+    // 2 枚目: 未採用 / 3 枚目: 採用済みだが processing (「未撮影」だけではないことの実例)
+    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+    $processing = Cut::factory()->forManual($manual)->withSortOrder(2)->create();
+    $processingTake = Take::factory()->forCut($processing)->create([
+        'duration_ms' => 5_000,
+        'status' => TakeStatus::Processing->value,
+    ]);
+    $processing->forceFill(['adopted_take_id' => $processingTake->id])->save();
+
+    test()->actingAs($owner);
+
+    return [$project, $manual];
+}
+
+test('E-1: 採用テイクが揃っていないマニュアルの詳細画面で、プレビュー生成前に注記が見える', function (): void {
+    [$project, $manual] = previewCoverageNoticeFixture();
+
+    $page = visit("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertPathIs("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertNoJavaScriptErrors();
+
+    $page->assertVisible('[data-testid="preview-coverage-note"]');
+
+    $note = $page->text('[data-testid="preview-coverage-note"]');
+    // 件数は述語どおり 2 / 3 (未採用 1 + 採用済みだが processing 1)
+    expect($note)->toContain('2');
+    expect($note)->toContain('3');
+    // TakeStatus は 4 値あるため「未撮影」と断定せず述語の意味をそのまま言う
+    expect($note)->toContain('撮影・処理が完了した採用テイクがありません');
+    expect($note)->toContain('黒背景');
+});
+
+test('E-2: 注記が出ていてもプレビュー生成ボタンは押下可能である (禁止事項 8)', function (): void {
+    [$project, $manual] = previewCoverageNoticeFixture();
+
+    $page = visit("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertPathIs("/projects/{$project->id}/manuals/{$manual->id}");
+
+    // クリックはしない (ffmpeg / storage が無いレーンで実行経路へ進めない)。
+    // 押下可能性は「可視 + disabled 属性・aria-disabled の不在」で判定する。
+    $page->assertVisible('[data-testid="preview-button"]');
+
+    expect($page->script(<<<'JS'
+        (() => {
+            const el = document.querySelector('[data-testid="preview-button"]');
+            if (el === null) return null;
+            return {
+                disabledProp: el.disabled === true,
+                disabledAttr: el.hasAttribute('disabled'),
+                ariaDisabled: el.getAttribute('aria-disabled') === 'true',
+            };
+        })()
+    JS))->toMatchArray([
+        'disabledProp' => false,
+        'disabledAttr' => false,
+        'ariaDisabled' => false,
+    ]);
+});
diff --git a/tests/Feature/Manual/PreviewCoverageParityTest.php b/tests/Feature/Manual/PreviewCoverageParityTest.php
new file mode 100644
index 0000000..48a4690
--- /dev/null
+++ b/tests/Feature/Manual/PreviewCoverageParityTest.php
@@ -0,0 +1,200 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\TakeStatus;
+use App\Enums\Manual\VideoManualStatus;
+use App\Enums\ProjectRole;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Support\Facades\Queue;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * プレビューと完成生成の判断基準の同一性 (T148 / bug-hunt F-1-01)。
+ *
+ * 再現していた症状: 採用テイクが揃っていない状態で
+ * - 完成動画生成 (render) は 422 で未充足カットを列挙してブロックする
+ * - プレビュー (preview) は**何も知らせずに**黒背景だらけの動画を出す
+ * 「同じ前提条件に対して片方は止め、片方は黙って壊れた成果物を出す」ことが finding の核。
+ *
+ * 本テストが固定する契約:
+ * - preview は**ブロックしない** (未撮影は制作途中の正常な状態。ボタンも止めない)
+ * - ただし詳細画面 props が render の 422 と**同じ述語・同じ件数**を事前告知する
+ * - 判定は AdoptedReadyTakeCoverage 1 箇所を通る (件数が乖離しない)
+ */
+
+/**
+ * 編集者 (owner) + ready manual + 採用済み ready テイク付きの step カット 1 枚。
+ *
+ * @return array{Organization, User, Project, VideoManual, Cut}
+ */
+function previewCoverageContext(int $tickets = 3): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 2,
+    ]);
+    $cut = Cut::factory()->forManual($manual)->create();
+    previewCoverageAdopt($cut);
+    if ($tickets > 0) {
+        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
+    }
+
+    return [$organization, $owner, $project, $manual, $cut];
+}
+
+/** cut にテイクを作成して採用する (status は指定可能 = 述語の 4 値差を作る) */
+function previewCoverageAdopt(Cut $cut, TakeStatus $status = TakeStatus::Ready): Take
+{
+    $take = Take::factory()->forCut($cut)->create([
+        'duration_ms' => 5_000,
+        'status' => $status->value,
+    ]);
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+
+    return $take;
+}
+
+/** 詳細画面の render props を取り出す */
+function previewCoverageRenderProps(Project $project, VideoManual $manual, User $actor): array
+{
+    $props = [];
+    test()->actingAs($actor)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertOk()
+        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
+            /** @var array<string, mixed> $render */
+            $render = $page->toArray()['props']['render'];
+            $props = $render;
+        });
+
+    return $props;
+}
+
+test('A-1: render は未充足カットがあると 422 で未充足カットを列挙する', function (): void {
+    Queue::fake();
+    [, $owner, $project, $manual] = previewCoverageContext();
+    Cut::factory()->forManual($manual)->withSortOrder(1)->create(); // 未採用
+
+    $response = $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/render",
+    );
+
+    $response->assertUnprocessable()->assertJsonValidationErrors(['takes']);
+    expect($response->json('errors.takes.0'))->toContain('手順2');
+});
+
+test('A-2: preview は未充足カットがあっても 201 で受け付ける (ブロックしない)', function (): void {
+    Queue::fake();
+    [, $owner, $project, $manual] = previewCoverageContext();
+    Cut::factory()->forManual($manual)->withSortOrder(1)->create(); // 未採用
+
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/preview",
+    )->assertCreated();
+});
+
+test('A-3: render 422 の列挙件数と詳細画面 coverage の missing_count が一致する', function (): void {
+    Queue::fake();
+    [, $owner, $project, $manual] = previewCoverageContext();
+    // 未充足 3 件 (未採用 2 + 採用済みだが processing 1)
+    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+    Cut::factory()->forManual($manual)->withSortOrder(2)->create();
+    previewCoverageAdopt(
+        Cut::factory()->forManual($manual)->withSortOrder(3)->create(),
+        TakeStatus::Processing,
+    );
+
+    $response = $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/render",
+    );
+    $response->assertUnprocessable();
+    $message = (string) $response->json('errors.takes.0');
+    $enumerated = substr_count($message, '、') + 1;
+
+    $render = previewCoverageRenderProps($project, $manual, $owner);
+
+    expect($enumerated)->toBe(3);
+    expect($render['coverage']['missing_count'])->toBe($enumerated);
+    expect($render['coverage']['total_cuts'])->toBe(4);
+});
+
+test('A-4: 詳細画面 props に total_cuts / missing_count / missing_labels が載る', function (): void {
+    [, $owner, $project, $manual] = previewCoverageContext();
+    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+
+    $render = previewCoverageRenderProps($project, $manual, $owner);
+
+    expect($render['coverage'])->toBe([
+        'total_cuts' => 2,
+        'missing_count' => 1,
+        'missing_labels' => ['手順2'],
+    ]);
+});
+
+test('A-5: すべて充足なら missing_count は 0 でラベルは空になる', function (): void {
+    [, $owner, $project, $manual] = previewCoverageContext();
+
+    $render = previewCoverageRenderProps($project, $manual, $owner);
+
+    expect($render['coverage']['missing_count'])->toBe(0);
+    expect($render['coverage']['missing_labels'])->toBe([]);
+    expect($render['coverage']['total_cuts'])->toBe(1);
+});
+
+test('A-6: 採用済みだが ready でないテイクも missing として数える', function (TakeStatus $status): void {
+    [, $owner, $project, $manual] = previewCoverageContext();
+    previewCoverageAdopt(
+        Cut::factory()->forManual($manual)->withSortOrder(1)->create(),
+        $status,
+    );
+
+    $render = previewCoverageRenderProps($project, $manual, $owner);
+
+    expect($render['coverage']['missing_count'])->toBe(1);
+    expect($render['coverage']['missing_labels'])->toBe(['手順2']);
+})->with([
+    'uploading' => TakeStatus::Uploading,
+    'processing' => TakeStatus::Processing,
+    'failed' => TakeStatus::Failed,
+]);
+
+test('A-7: missing が 11 件のとき missing_labels は 10 件で missing_count は 11 になる', function (): void {
+    [, $owner, $project, $manual] = previewCoverageContext();
+    foreach (range(1, 11) as $index) {
+        Cut::factory()->forManual($manual)->withSortOrder($index)->create();
+    }
+
+    $render = previewCoverageRenderProps($project, $manual, $owner);
+
+    expect($render['coverage']['missing_count'])->toBe(11);
+    expect($render['coverage']['missing_labels'])->toHaveCount(10);
+    expect($render['coverage']['total_cuts'])->toBe(12);
+});
+
+test('A-8: 撮影者にも coverage は返るが preview / render の起動は 403 のまま', function (): void {
+    Queue::fake();
+    [$organization, , $project, $manual] = previewCoverageContext();
+    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    attachProjectMember($project, $member, ProjectRole::Member);
+
+    $render = previewCoverageRenderProps($project, $manual, $member);
+    expect($render['coverage']['missing_count'])->toBe(1);
+
+    $this->actingAs($member)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/preview",
+    )->assertForbidden();
+    $this->actingAs($member)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/render",
+    )->assertForbidden();
+});
diff --git a/tests/Feature/Manual/RenderPlaceholderCountTest.php b/tests/Feature/Manual/RenderPlaceholderCountTest.php
new file mode 100644
index 0000000..9c8f8fe
--- /dev/null
+++ b/tests/Feature/Manual/RenderPlaceholderCountTest.php
@@ -0,0 +1,193 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
+use App\DataTransferObjects\Manual\Render\RenderManifest;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\RenderErrorCode;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Manual\RenderJobService;
+use App\Services\Manual\RenderPipeline;
+use App\Services\Render\VideoComposer;
+use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Facades\Storage;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * 事後説明 render_jobs.placeholder_cut_count (T148 / bug-hunt F-1-01)。
+ *
+ * 「生成された動画に黒背景の区間が何カット分あったか」を**生成物の説明**として記録する。
+ * 値契約: 既存行 / queued / running / failed = null、succeeded preview = 実数、
+ * succeeded render = 0。**null を 0 と同一視しない / 現在状態から再計算しない**。
+ */
+
+/** 本ファイル専用の fake composer (実 ffmpeg に触れない。RenderPipelineTest とは別クラス) */
+final class PlaceholderCountComposer implements VideoComposer
+{
+    public ?RenderManifest $lastManifest = null;
+
+    /** compose 中 (buildManifest 後・finalize 前) に呼ばれる hook。状態変化のインターリーブ細工用 */
+    public ?Closure $duringCompose = null;
+
+    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
+    {
+        $this->lastManifest = $manifest;
+        if ($this->duringCompose !== null) {
+            ($this->duringCompose)($manifest);
+        }
+        $durations = [];
+        foreach ($manifest->clips as $index => $clip) {
+            $durations[$clip->cutId] = 1_000;
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
+ * ready manual + 採用済み ready テイク付き step 1 枚 + fake composer。
+ *
+ * @return array{Organization, User, Project, VideoManual, Cut, PlaceholderCountComposer}
+ */
+function placeholderCountContext(int $tickets = 3): array
+{
+    Queue::fake();
+    Storage::fake('s3');
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 2,
+    ]);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+    Storage::disk('s3')->put($take->video_path, 'fake-take-video');
+    if ($tickets > 0) {
+        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
+    }
+
+    $fake = new PlaceholderCountComposer;
+    app()->instance(VideoComposer::class, $fake);
+
+    return [$organization, $owner, $project, $manual, $cut, $fake];
+}
+
+test('B-1: succeeded な preview に生成時のプレースホルダ件数が記録される', function (): void {
+    [, , $project, $manual] = placeholderCountContext(tickets: 0);
+    // 4 カット中 3 カットが未充足 (1 枚目のみ採用済み ready)
+    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+    Cut::factory()->forManual($manual)->withSortOrder(2)->create();
+    Cut::factory()->forManual($manual)->withSortOrder(3)->create();
+
+    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
+    app(RenderPipeline::class)->run($previewJob->id);
+
+    $previewJob->refresh();
+    expect($previewJob->status)->toBe(JobStatus::Succeeded);
+    expect($previewJob->placeholder_cut_count)->toBe(3);
+});
+
+test('B-2: succeeded な render の placeholder_cut_count は 0 になる', function (): void {
+    [, , $project, $manual] = placeholderCountContext();
+
+    $job = app(RenderJobService::class)->trigger($project, $manual);
+    app(RenderPipeline::class)->run($job->id);
+
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Succeeded);
+    expect($job->placeholder_cut_count)->toBe(0);
+});
+
+test('B-3: queued / running / failed の placeholder_cut_count は null のまま', function (): void {
+    [, , , $manual] = placeholderCountContext(tickets: 0);
+
+    $queued = RenderJob::factory()->forManual($manual)->preview()->create();
+    $running = RenderJob::factory()->forManual($manual)->preview()->running()->create();
+    $failed = RenderJob::factory()->forManual($manual)->preview()
+        ->failed(RenderErrorCode::Internal)->create();
+
+    expect($queued->refresh()->placeholder_cut_count)->toBeNull();
+    expect($running->refresh()->placeholder_cut_count)->toBeNull();
+    expect($failed->refresh()->placeholder_cut_count)->toBeNull();
+});
+
+test('B-4: プレビュー生成後にテイクを採用しても記録済み件数は変わらない', function (): void {
+    [, , $project, $manual] = placeholderCountContext(tickets: 0);
+    $missing = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+
+    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
+    app(RenderPipeline::class)->run($previewJob->id);
+    expect($previewJob->refresh()->placeholder_cut_count)->toBe(1);
+
+    // 生成後に採用しても「生成物の説明」は書き換わらない (再計算禁止)
+    $take = Take::factory()->forCut($missing)->create(['duration_ms' => 1_000]);
+    $missing->forceFill(['adopted_take_id' => $take->id])->save();
+
+    expect($previewJob->refresh()->placeholder_cut_count)->toBe(1);
+});
+
+test('B-4b: 合成中に採用しても記録されるのは manifest 時点の件数である (finalize での再計算禁止)', function (): void {
+    [, , $project, $manual, , $fake] = placeholderCountContext(tickets: 0);
+    $missingA = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+    Cut::factory()->forManual($manual)->withSortOrder(2)->create();
+
+    // buildManifest (件数確定) の**後**・finalize の**前**に採用してしまう。
+    // manifest 由来なら 2、finalize 時点の現在状態から数え直すと 1 になる。
+    $fake->duringCompose = function () use ($missingA): void {
+        $take = Take::factory()->forCut($missingA)->create(['duration_ms' => 1_000]);
+        $missingA->forceFill(['adopted_take_id' => $take->id])->save();
+    };
+
+    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
+    app(RenderPipeline::class)->run($previewJob->id);
+
+    expect($previewJob->refresh()->status)->toBe(JobStatus::Succeeded);
+    expect($previewJob->placeholder_cut_count)->toBe(2);
+});
+
+test('B-5: ポーリング応答と詳細画面 props に placeholder_cut_count が載る', function (): void {
+    [, $owner, $project, $manual] = placeholderCountContext(tickets: 0);
+    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+
+    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
+    app(RenderPipeline::class)->run($previewJob->id);
+
+    $this->actingAs($owner)->getJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$previewJob->id}",
+    )->assertOk()->assertJson(['placeholder_cut_count' => 1]);
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('render.playbackJob.id', $previewJob->id)
+            ->where('render.playbackJob.placeholder_cut_count', 1));
+});
+
+test('B-6: 本変更以前からの succeeded 行 (legacy) は null のままで backfill しない', function (): void {
+    [, $owner, $project, $manual] = placeholderCountContext(tickets: 0);
+    $legacy = RenderJob::factory()->forManual($manual)->preview()
+        ->legacySucceeded("projects/{$project->id}/manuals/{$manual->id}/previews/v2-1.mp4")
+        ->create();
+
+    expect($legacy->refresh()->placeholder_cut_count)->toBeNull();
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('render.playbackJob.placeholder_cut_count', null));
+});
diff --git a/tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php b/tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php
index 0c9baeb..e5c3277 100644
--- a/tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php
+++ b/tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php
@@ -65,6 +65,8 @@ function artifactAccessMember(Organization $organization, Project $project): Use
         'error' => null,
         'error_code' => null,
         'manual_status' => 'ready',
+        // T148: 生成物の説明。running なので「言えることが無い」= null
+        'placeholder_cut_count' => null,
     ]);
 });
 
diff --git a/tests/Feature/Projects/ManualStaleJobDisplayTest.php b/tests/Feature/Projects/ManualStaleJobDisplayTest.php
index b5c819a..92e7889 100644
--- a/tests/Feature/Projects/ManualStaleJobDisplayTest.php
+++ b/tests/Feature/Projects/ManualStaleJobDisplayTest.php
@@ -110,7 +110,7 @@ function staleDisplayContext(int $scenarioVersion = 1, VideoManualStatus $status
             ->where('analysis.job.status', JobStatus::Succeeded->value));
 });
 
-test('preview 独立: preview 失敗が stale でも playbackJobId は succeeded preview を維持', function (): void {
+test('preview 独立: preview 失敗が stale でも playbackJob は succeeded preview を維持', function (): void {
     [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 2);
     // 古い succeeded preview (再生可能) と、その後の失敗 preview (stale)
     $playable = RenderJob::factory()->forManual($manual)->preview()
@@ -123,7 +123,8 @@ function staleDisplayContext(int $scenarioVersion = 1, VideoManualStatus $status
     $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
         ->assertInertia(fn (Assert $page) => $page
             ->where('render.previewJob', null)
-            ->where('render.playbackJobId', $playable->id));
+            // T148: playbackJobId (id だけ) から playbackJob (行そのもの) へ置換済み
+            ->where('render.playbackJob.id', $playable->id));
 });
 
 test('統合: ScenarioService::save の実経路 (no-op 保存) で version++ すると解析失敗が stale 化', function (): void {
diff --git a/tests/Unit/Render/RenderManifestTest.php b/tests/Unit/Render/RenderManifestTest.php
new file mode 100644
index 0000000..81f53e1
--- /dev/null
+++ b/tests/Unit/Render/RenderManifestTest.php
@@ -0,0 +1,59 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\Render\RenderClipSource;
+use App\DataTransferObjects\Manual\Render\RenderClipSpec;
+use App\DataTransferObjects\Manual\Render\RenderManifest;
+use App\Enums\Manual\RenderKind;
+
+/*
+ * RenderManifest::placeholderCutCount() (T148)。
+ * 値の出所は**マニフェストの clips ただ 1 つ**であり、DB も現在の manual 状態も見ない
+ * (マニフェストは読み取り一貫性の確定点 = 生成物の説明の唯一の根拠)。
+ */
+
+/** @param  list<RenderClipSource>  $sources */
+function renderManifestWithSources(array $sources): RenderManifest
+{
+    $clips = [];
+    foreach ($sources as $index => $source) {
+        $clips[] = new RenderClipSpec(
+            cutId: $index + 1,
+            label: '手順'.($index + 1),
+            source: $source,
+            takeVideoPath: $source === RenderClipSource::Placeholder ? null : 'takes/x.mp4',
+            stillDisplaySeconds: null,
+            subtitlePrimary: null,
+            subtitleSecondary: 'テロップ',
+        );
+    }
+
+    return new RenderManifest(
+        renderJobId: 1,
+        kind: RenderKind::Preview,
+        scenarioVersion: 2,
+        outputKey: 'previews/v2-1.mp4',
+        clips: $clips,
+    );
+}
+
+test('placeholderCutCount は clips の Placeholder 件数を数える', function (): void {
+    $manifest = renderManifestWithSources([
+        RenderClipSource::TakeVideo,
+        RenderClipSource::Placeholder,
+        RenderClipSource::TakeStill,
+        RenderClipSource::Placeholder,
+    ]);
+
+    expect($manifest->placeholderCutCount())->toBe(2);
+});
+
+test('Placeholder が無ければ 0 を返す', function (): void {
+    $manifest = renderManifestWithSources([
+        RenderClipSource::TakeVideo,
+        RenderClipSource::TakeStill,
+    ]);
+
+    expect($manifest->placeholderCutCount())->toBe(0);
+});
diff --git a/tests/js/components/features/manual/RenderPanel.test.ts b/tests/js/components/features/manual/RenderPanel.test.ts
index 3ee2bb2..e584a7c 100644
--- a/tests/js/components/features/manual/RenderPanel.test.ts
+++ b/tests/js/components/features/manual/RenderPanel.test.ts
@@ -32,7 +32,8 @@ const baseProps = {
     manualStatus: "ready" as const,
     job: null,
     previewJob: null,
-    playbackJobId: null,
+    playbackJob: null,
+    coverage: { total_cuts: 1, missing_count: 0, missing_labels: [] },
     canManage: true,
 };
 
@@ -46,6 +47,7 @@ function renderJobBody(overrides: Partial<RenderJobProps> = {}): RenderJobProps
         error: null,
         error_code: null,
         manual_status: "rendering",
+        placeholder_cut_count: null,
         ...overrides,
     };
 }
@@ -222,7 +224,10 @@ describe("RenderPanel", () => {
 
     it("再生可能な preview があれば <video> を playback route で表示する", () => {
         render(RenderPanel, {
-            props: { ...baseProps, playbackJobId: 33 },
+            props: {
+                ...baseProps,
+                playbackJob: renderJobBody({ id: 33, kind: "preview", status: "succeeded" }),
+            },
         });
 
         const video = screen.getByTestId("preview-video");
@@ -389,4 +394,187 @@ describe("RenderPanel", () => {
             "完成動画の生成を開始できませんでした",
         );
     });
+
+    // --- T148 (bug-hunt F-1-01): 事前告知 (coverage) と事後説明 (placeholder_cut_count) ---
+
+    it("D-1: missing_count>0 でプレビュー近傍に事前告知を出す", () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                coverage: { total_cuts: 4, missing_count: 3, missing_labels: ["手順2", "手順3", "手順4"] },
+            },
+        });
+
+        const note = screen.getByTestId("preview-coverage-note");
+        expect(note).toHaveTextContent("3");
+        expect(note).toHaveTextContent("4");
+        expect(note).toHaveTextContent("手順2、手順3、手順4");
+        // 述語は「未撮影」ではなく「撮影・処理が完了した採用テイクがない」ことを言う
+        expect(note).toHaveTextContent("撮影・処理が完了した採用テイクがありません");
+    });
+
+    it("D-2: missing_count>0 でもプレビュー生成ボタンは disabled にならない (禁止事項 8)", () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                coverage: { total_cuts: 2, missing_count: 1, missing_labels: ["手順2"] },
+            },
+        });
+
+        const previewButton = screen.getByTestId("preview-button");
+        expect(previewButton).not.toBeDisabled();
+        expect(previewButton).not.toHaveAttribute("aria-disabled", "true");
+        const renderButton = screen.getByTestId("render-button");
+        expect(renderButton).not.toBeDisabled();
+        expect(renderButton).not.toHaveAttribute("aria-disabled", "true");
+    });
+
+    it("D-3: missing_count が 0 なら事前告知を出さない", () => {
+        render(RenderPanel, { props: baseProps });
+
+        expect(screen.queryByTestId("preview-coverage-note")).not.toBeInTheDocument();
+    });
+
+    it("D-4: playbackJob.placeholder_cut_count>0 なら動画の上に事後説明を出す", () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                playbackJob: renderJobBody({
+                    id: 33,
+                    kind: "preview",
+                    status: "succeeded",
+                    placeholder_cut_count: 2,
+                }),
+            },
+        });
+
+        expect(screen.getByTestId("preview-placeholder-note")).toHaveTextContent("2");
+        expect(screen.getByTestId("preview-video")).toBeInTheDocument();
+    });
+
+    it("D-5: placeholder_cut_count が null なら事後説明を出さない (0 と同一視しない)", () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                playbackJob: renderJobBody({
+                    id: 33,
+                    kind: "preview",
+                    status: "succeeded",
+                    placeholder_cut_count: null,
+                }),
+            },
+        });
+
+        expect(screen.queryByTestId("preview-placeholder-note")).not.toBeInTheDocument();
+        expect(screen.getByTestId("preview-video")).toBeInTheDocument();
+    });
+
+    it("D-5b: placeholder_cut_count が 0 なら事後説明を出さない", () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                playbackJob: renderJobBody({
+                    id: 33,
+                    kind: "preview",
+                    status: "succeeded",
+                    placeholder_cut_count: 0,
+                }),
+            },
+        });
+
+        expect(screen.queryByTestId("preview-placeholder-note")).not.toBeInTheDocument();
+    });
+
+    it("D-6: 事後説明と動画 URL は同一の playbackJob から出る (最新 preview が別世代でも)", () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                // 最新 preview job は別世代 (失敗済み・件数 9)
+                previewJob: renderJobBody({
+                    id: 44,
+                    kind: "preview",
+                    status: "failed",
+                    error: "書き出しに失敗しました。",
+                    error_code: "internal",
+                    manual_status: "ready",
+                    placeholder_cut_count: 9,
+                }),
+                playbackJob: renderJobBody({
+                    id: 33,
+                    kind: "preview",
+                    status: "succeeded",
+                    placeholder_cut_count: 2,
+                }),
+            },
+        });
+
+        expect(screen.getByTestId("preview-video")).toHaveAttribute(
+            "src",
+            "/projects/1/manuals/5/render-jobs/33/playback",
+        );
+        const note = screen.getByTestId("preview-placeholder-note");
+        expect(note).toHaveTextContent("2");
+        expect(note).not.toHaveTextContent("9");
+    });
+
+    it("D-7: missing_labels が打ち切られているとき「ほか N 件」を出す", () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                coverage: {
+                    total_cuts: 12,
+                    missing_count: 11,
+                    missing_labels: [
+                        "手順2",
+                        "手順3",
+                        "手順4",
+                        "手順5",
+                        "手順6",
+                        "手順7",
+                        "手順8",
+                        "手順9",
+                        "手順10",
+                        "手順11",
+                    ],
+                },
+            },
+        });
+
+        expect(screen.getByTestId("preview-coverage-note")).toHaveTextContent("ほか 1 件");
+    });
+
+    it("D-8: preview 成功のポーリング応答で playbackJob が更新され事後説明も追随する", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(
+                200,
+                renderJobBody({
+                    id: 77,
+                    kind: "preview",
+                    status: "succeeded",
+                    manual_status: "ready",
+                    placeholder_cut_count: 5,
+                }),
+            ),
+        );
+
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                previewJob: renderJobBody({
+                    id: 77,
+                    kind: "preview",
+                    status: "running",
+                    manual_status: "ready",
+                }),
+            },
+        });
+
+        await waitFor(() => {
+            expect(screen.getByTestId("preview-video")).toHaveAttribute(
+                "src",
+                "/projects/1/manuals/5/render-jobs/77/playback",
+            );
+        });
+        expect(screen.getByTestId("preview-placeholder-note")).toHaveTextContent("5");
+    });
 });
diff --git a/tests/js/pages/ManualsShow.test.ts b/tests/js/pages/ManualsShow.test.ts
index 887e99c..09c0a5a 100644
--- a/tests/js/pages/ManualsShow.test.ts
+++ b/tests/js/pages/ManualsShow.test.ts
@@ -13,7 +13,12 @@ const baseProps = {
         created_at: "2026-07-10 12:00",
     },
     analysis: { job: null, hasDocument: false },
-    render: { job: null, previewJob: null, playbackJobId: null },
+    render: {
+        job: null,
+        previewJob: null,
+        playbackJob: null,
+        coverage: { total_cuts: 1, missing_count: 0, missing_labels: [] },
+    },
     canManage: true,
     categories: [
         { id: 1, name: "準備作業" },
@@ -147,4 +152,39 @@ describe("Manuals/Show", () => {
         expect(screen.queryByTestId("source-document-upload")).toBeNull();
         expect(screen.getByTestId("analysis-progress")).toBeInTheDocument();
     });
+
+    // --- T148 (bug-hunt F-1-01): render props の配線 ---
+
+    it("D-9: render.coverage と render.playbackJob が RenderPanel へ渡る", () => {
+        render(Show, {
+            props: {
+                ...baseProps,
+                manual: { ...baseProps.manual, status: "ready" as VideoManualStatus },
+                render: {
+                    job: null,
+                    previewJob: null,
+                    playbackJob: {
+                        id: 33,
+                        kind: "preview" as const,
+                        status: "succeeded" as const,
+                        step: null,
+                        progress: 100,
+                        error: null,
+                        error_code: null,
+                        manual_status: "ready" as VideoManualStatus,
+                        placeholder_cut_count: 2,
+                    },
+                    coverage: { total_cuts: 3, missing_count: 2, missing_labels: ["手順2", "手順3"] },
+                },
+            },
+        });
+
+        // coverage は事前告知へ、playbackJob は動画 URL と事後説明へ流れる
+        expect(screen.getByTestId("preview-coverage-note")).toHaveTextContent("手順2、手順3");
+        expect(screen.getByTestId("preview-video")).toHaveAttribute(
+            "src",
+            "/projects/1/manuals/5/render-jobs/33/playback",
+        );
+        expect(screen.getByTestId("preview-placeholder-note")).toHaveTextContent("2");
+    });
 });

```

## テスト結果 (実行済み・全 green)

- `composer phpstan` : No errors (level 10)
- `composer test` : 4429 tests / 4427 passed / 0 failed / 2 skipped
- `vendor/bin/pint --test` : passed
- `pnpm lint` / `pnpm typecheck` : passed
- `pnpm test` (vitest) : 130 files / 1309 tests passed
- `pnpm build` : ok
- `pnpm typecheck:packages` / `build:packages` / `test:packages` : passed (10 files / 106 tests)
- `composer test:browser` : **Chromium + WebKit の 2 レーンとも passed** (各 24 tests / 21 passed / 3 skipped)。
  新規 `tests/Browser/PreviewCoverageNoticeTest.php` の 2 件が実際に実行されたことを
  `--filter=PreviewCoverageNotice` で個別確認済み (2 passed / 15 assertions)。

### テストファーストの証跡

実装前に再現テスト (PreviewCoverageParityTest / RenderPlaceholderCountTest / RenderManifestTest) を
書いて実行し、**18 件中 15 件が赤** (A-3〜A-8 の props 不在 = `Undefined array key "coverage"`、
B-1/B-2/B-4/B-5 の `placeholder_cut_count` 不在、`legacySucceeded()` / `placeholderCutCount()` の未定義) で
あることを確認してから実装に入った。green だった 3 件は既存契約 (A-1 の render 422 / A-2 の preview 201) である。

## design system 参照 (diff が resources/js を含むため)

DESIGN.md §Alert:
- 実装: `components/atoms/Alert.svelte`。ページ内に常在するインライン通知ボックス
- type: `success` / `warning` / `danger` / `info`
- 配色: ボーダー=状態色、見出し(title 任意)=状態色、本文=`text-text`、背景=`bg-surface`。中間 box なので `rounded-md`
- a11y: danger のみ `role="alert"`(assertive)、他は `role="status"`(polite)

DESIGN.md §Typography: `text-caption` = 12px/400/lh1.5 (ラベル/補助情報/日時)、`text-body` = 本文
DESIGN.md §Colors: `text-warning` / `border-warning` などの状態色 token

触れた atomic ディレクトリ: `resources/js/components/features/manual/` のみ
(AnalysisPanel.svelte / DuplicateManualDialog.svelte / **RenderPanel.svelte** / ScenarioEditor.svelte /
SourceDocumentUpload.svelte / insufficient-tickets.ts)。
新規 atom / molecule / organism は 1 つも作っていない。使用したのは既存 `atoms/Alert.svelte` と
既存 utility class のみ。

## 特に見てほしい点

1. 検出 B の「名指し免除 + 機械検査される前提」が、設計の「厳密に 1 ファイル」より弱くなっていないか
   (mutation 記録の「設計からの逸脱」節)。
2. M7 の予測ずれに対する対処 (B-4b の追加) が、本当に「finalize での再計算禁止」を固定できているか。
3. `playbackJobId` → `playbackJob` の破壊的変更に追随漏れがないか。
4. props の `coverage` が撮影者 (project_member) にも返ることの是非。
