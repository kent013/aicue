【アプリの使命 (North Star) — AGENTS.md より】
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ / PWA 撮影 / 自前 ffmpeg 合成 / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. response()->json() 直書き（DTO/JsonResource/Inertia）
5. LLM の Prism 直呼び（app/Prompts/ factory 経由のみ）
6. prompt 文字列のコード直書き（resources/prompts/*.yaml）
7. 操作系 POST 応答での redirect()->intended()
8. 必須未充足でボタン disabled にする UI

【ドメイン規約1（シナリオ整合の共有ロック規約）】
cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する。新書き込み経路は ScenarioWritePathInventoryTest 登録が必須。

【思考原則】仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。今必要なものだけ作る。

【ツール使用制限】コマンド実行・ファイル書き込みは行わず、テキスト分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel 12 + Svelte 5 + Inertia + TypeScript / PHPStan L10 / Pest / DTO+JsonResource / Laratrust RBAC の詳細設計をレビューしてください。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名・パターン・API）
3. PHPStan L10 適合性（型安全、generics、Assert/typed accessor）
4. テスト計画の網羅性（各施策に Pest、RefreshDatabase グローバル適用）
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response 使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TS 型・API Resource・テストが変更対象に含まれるか）
9. セキュリティ（認可・入力バリデーション・共有ロック規約・untrusted 文字列）
10. DESIGN.md 準拠（UI 変更を含む場合）— 本設計は UI 変更なし
11. Atomic Design 準拠（UI 変更を含む場合）— 本設計は UI 変更なし

【本設計固有の重点論点】
A. 導入/総括をサーバ側で materialize 前に list へ挿入し、既存 CutType(step/point) のまま通常 step として表現する方針の妥当性（独立 CutType はスコープ外）。
B. 文面確定を AnalysisPipeline::finalize の terminal tx 内で ScenarioBookendBuilder::wrap(lockedManual, generatedSteps) で行い、DB 既存 cuts を参照しない設計が共有ロック規約・再生成時の正しさを満たすか。
C. 要点再掲の 3 段抽出（point.subtitle_primary → step.subtitle_primary → 定型フォールバック）・件数 N・「／」連結・件数削減→文字 truncate のロジック正確性。
D. ScenarioWritePathInventoryTest への登録が「不要」という判断（builder は cuts/version/status を書かず、書き込みは従来どおり materializeIntoLockedManual 1 経路）の正しさ。
E. 既存テスト（cut 件数 2→4）の波及更新の網羅性、禁止事項3（既存テスト削除・上書き禁止）との整合。
F. MAX_STEPS(100) 到達時に +2 で 102 になる境界の扱い。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] 分類、Critical/Warning に修正案必須
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語

---

## 関連する現行コード（抜粋）

### app/Services/Manual/AnalysisPipeline.php::finalize（該当部）
```php
private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): bool
{
    return DB::transaction(function () use ($job, $generated): bool {
        $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== JobStatus::Running) { return false; }
        $project = $this->resolveProject($locked);
        $lockedManual = $project->manuals()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
        $this->scenarios->materializeIntoLockedManual($lockedManual, $generated->toScenarioSteps());
        $reservation = $locked->ticketReservation;
        Assert::notNull($reservation, 'startJob が必ず予約を付けている');
        $this->tickets->commit($reservation);
        $locked->status = JobStatus::Succeeded; $locked->progress = 100; $locked->save();
        return true;
    });
}
```

### app/Services/Manual/ScenarioService.php::materializeIntoLockedManual（前提: ロック済み tx 内のみ）
```php
public function materializeIntoLockedManual(VideoManual $lockedManual, array $steps): void
{
    if (DB::transactionLevel() === 0) { throw new LogicException('materialize はロック済みトランザクション内からのみ'); }
    if ($lockedManual->status !== VideoManualStatus::Analyzing) { throw new LogicException('materialize は analyzing 中のみ'); }
    $lockedManual->cuts()->get()->each->delete(); // 全置換
    $changed = true;
    foreach ($steps as $stepIndex => $stepInput) {
        $noExisting = new Collection;
        $step = $this->upsertCut($lockedManual, $noExisting, $stepInput, CutType::Step, null, $stepIndex, $changed);
        foreach ($stepInput->points as $pointIndex => $pointInput) {
            $this->upsertCut($lockedManual, $noExisting, $pointInput, CutType::Point, $step->id, $pointIndex, $changed);
        }
    }
    $lockedManual->forceFill(['scenario_version' => $lockedManual->scenario_version + 1, 'status' => VideoManualStatus::Ready])->save();
}
```
- upsertCut は fill で本文、forceFill で type/parent_cut_id/sort_order(=引数 index) を設定。

### ScenarioStepInput（既存 DTO）
```php
final readonly class ScenarioStepInput {
    /** @param list<ScenarioPointInput> $points */
    public function __construct(
        public ?int $id, public string $scene, public ShotType $shotType,
        public ?string $shootingPoint, public string $narration,
        public ?string $subtitlePrimary, public string $subtitleSecondary,
        public ?MaterialType $materialType, public ?int $staticDisplaySeconds,
        public array $points,
    ) {}
}
```
ScenarioPointInput は同構造（points なし）。GeneratedScenarioData::toScenarioSteps(): list<ScenarioStepInput> を返す。

### VideoManual: title は string(200) NOT NULL。ScenarioLimits: MAX_SUBTITLE_PRIMARY_CHARS=100, MAX_SUBTITLE_SECONDARY_CHARS=2000, MAX_STEPS=100。

### 既存テスト（波及対象）
- tests/Feature/Llm/CannedAnalysisPipelineTest.php: `expect($cuts)->toHaveCount(2)` / step+point を firstWhere。
- tests/Feature/Projects/AnalysisPipelineTest.php: 成功パスで `toHaveCount(2)`、再解析 all-replace で `count()->toBe(2)`。

---

## 詳細設計書（全文）

（以下 detailed-design.md）
</content>
# 詳細設計: scenario-intro-summary-cuts（AIシナリオ生成の導入/総括カット自動挿入）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」で、熟練者の暗黙知を動画マニュアルという形式知へ変換する（SECI）。v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項
1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて実装済み）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml`）
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### ドメイン規約1（シナリオ整合の共有ロック規約）
`cuts` / `video_manuals.scenario_version` / `video_manuals.status` を書き込む全経路は、対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する。新しい書き込み経路は `ScenarioWritePathInventoryTest` への登録が必須。

### コーディングルール
- PHPStan level 10（`composer phpstan`） / Pest（`composer test`、`--parallel`） / RefreshDatabase グローバル適用（個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成 / DTO 返却（配列返却なし）/ アーリーリターン / `declare(strict_types=1)` + 日本語コメント
- `composer fix`（Pint）/ `pnpm lint:fix`

## 概念設計リファレンス
- `devnotes/20260714-2037-scenario-intro-summary-cuts/conceptual-design.md`（Codex gpt-5.4 Round 4 APPROVED）

## 設計の要点（現状確認の結論）
- 導入/総括カットは**未実装**（`scenario-generation.yaml` は step/point のみ、`GeneratedScenarioData` は step/point 以外を拒否、materialize は前後挿入なし）。
- v1 データモデル（`doc/10 §10.1/§10.4`）は `CutType` を step/point に**意図的に限定**。独立 CutType 化・専用エディタ UI は**スコープ外**（後続）。
- 方針: **materialize 時にサーバ側で決定的に導入/総括カットを前後挿入**。LLM プロンプト・出力スキーマ・canned signature は**不変**。導入/総括は通常の `CutType::Step` / `ShotType::Hiki` トップレベルカット。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 構造値の config 追加 | `config/manual.php` | High |
| 2 | 定型文面の lang 追加 | `lang/ja/manual.php`（新規） | High |
| 3 | ScenarioBookendBuilder 新設 | `app/Services/Manual/ScenarioBookendBuilder.php`（新規） | High |
| 4 | AnalysisPipeline から builder 呼び出し | `app/Services/Manual/AnalysisPipeline.php` | High |
| 5 | Unit テスト（builder 抽出規則） | `tests/Unit/Manual/ScenarioBookendBuilderTest.php`（新規） | High |
| 6 | Feature テスト（materialize 不変条件） | `tests/Feature/Projects/ScenarioBookendMaterializeTest.php`（新規） | High |
| 7 | 既存テストの期待値更新（波及） | `tests/Feature/Llm/CannedAnalysisPipelineTest.php` / `tests/Feature/Projects/AnalysisPipelineTest.php` | High |

---

## 施策1: 構造値の config 追加

### 変更箇所
- `config/manual.php`（AI 解析設定ブロック）

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし / テストファイル: 施策5/6 が参照

### 変更後コード
```php
    // ── シナリオ導入/総括カット (概念設計 §改善アイデア) ──────────────
    // 総括カットの要点再掲に載せる最大件数 (先頭から)。
    'summary_recap_max_points' => 3,
    // 導入/総括の作業名補間で用いるタイトルの truncate 上限 (subtitle_primary=100 に収める)。
    'scenario_bookend_title_max_chars' => 60,
```

### PHPStan適合チェック
- [x] 読み出しは `config()->integer('manual.summary_recap_max_points')` 等の typed accessor（正整数保証）

### テスト計画
- [ ] 施策6 の Feature テストで既定値の挙動を間接検証。config 単体テストは作らない（値のみ）

### リスク
- なし（新規キー追加のみ。既存参照に影響なし）

---

## 施策2: 定型文面の lang 追加

### 変更箇所
- `lang/ja/manual.php`（新規。既存 `lang/ja/` は auth/validation 等のみで manual ドメイン用は未整備）

### 波及変更
- TypeScript 型定義: なし（サーバ側 DB コンテンツ）/ DTO: なし / テスト: 施策5/6 が同じキーで照合

### 変更後コード（新規ファイル）
```php
<?php

declare(strict_types=1);

// シナリオ導入/総括カットの定型文面 (DB の cut コンテンツ。プロンプトではないため resources/prompts 対象外)。
// :title は VideoManual->title を truncate した作業名。:points は決定的に抽出した要点再掲。
return [
    'bookend' => [
        'intro' => [
            'scene' => '作業全体の俯瞰（導入）',
            'narration' => 'この動画では「:title」の手順と注意点を示します。',
            'subtitle_primary' => ':title',
            'subtitle_secondary' => 'この動画では「:title」の手順と注意点を確認します。',
        ],
        'summary' => [
            'scene' => '作業全体の俯瞰（総括）',
            'narration' => '以上で「:title」は完了です。要点を振り返ります。',
            'subtitle_primary' => '要点の再確認',
            // 要点再掲あり
            'subtitle_secondary_recap' => '要点の再確認：:points',
            // 再掲元が無い場合のフォールバック (締めカット)
            'subtitle_secondary_fallback' => '以上で「:title」の作業は完了です。安全に留意して作業しましょう。',
        ],
    ],
];
```

### PHPStan適合チェック
- [x] lang 取得は `string` 確定の typed accessor（施策3 の `localizedString()` ヘルパ）で行い、`array|string` の緩さを閉じる

### テスト計画
- [ ] 施策5 でキー存在と補間結果を検証

### リスク
- 文言変更でテストが壊れないよう、テストは**同じ lang キー**で期待値を組み立てる（ハードコード文字列照合をしない）

---

## 施策3: ScenarioBookendBuilder 新設（中核）

### 変更箇所
- `app/Services/Manual/ScenarioBookendBuilder.php`（新規）

### 責務（Codex R2/R3 反映で一本化）
`wrap(VideoManual $lockedManual, array $generatedSteps): array` が `[導入] + $generatedSteps + [総括]` の `list<ScenarioStepInput>` を返す。文面組み立てのみを担い、DB 書き込み・トランザクション・ロックは持たない（呼び出し側 = `AnalysisPipeline::finalize` の terminal tx 内で実行）。**DB の既存 cuts は参照しない**（再掲元は引数 `$generatedSteps` のみ）。

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: 既存 `ScenarioStepInput`/`ScenarioPointInput` を再利用（新 DTO なし）
- テストファイル: 施策5（Unit）
- `ScenarioWritePathInventoryTest`: **登録不要**（builder は cuts/version/status を書かない。書き込みは従来どおり `materializeIntoLockedManual` の 1 経路のみ）

### 変更後コード（骨子）
```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\ScenarioStepInput;
use App\Enums\Manual\ShotType;
use App\Models\VideoManual;
use App\Support\Manual\ScenarioLimits;
use Illuminate\Support\Str;

/**
 * AI 生成シナリオの前後へ導入/総括カットを決定的に付与する (概念設計 §改善アイデア)。
 *
 * - 純関数的: DB / トランザクション / ロックに触れない。呼び出し側 (AnalysisPipeline::finalize の
 *   terminal tx 内) が locked manual と今回生成の steps を渡す。
 * - 追加カットは既存 CutType::Step / ShotType::Hiki のトップレベル step として表現する
 *   (v1 は独立 CutType を持たない。doc/10 §10.1 の step/point 限定を維持)。
 * - 総括の要点再掲は「今回生成の $generatedSteps」からのみ抽出する (DB 既存 cuts 不参照 =
 *   再生成時に旧シナリオを総括する事故を構造的に排除)。
 */
final class ScenarioBookendBuilder
{
    /**
     * @param  list<ScenarioStepInput>  $generatedSteps
     * @return list<ScenarioStepInput>  [導入, ...generatedSteps, 総括]
     */
    public function wrap(VideoManual $lockedManual, array $generatedSteps): array
    {
        $title = $this->truncatedTitle($lockedManual->title);

        $intro = $this->intro($title);
        $summary = $this->summary($title, $generatedSteps);

        return [$intro, ...$generatedSteps, $summary];
    }

    private function intro(string $title): ScenarioStepInput
    {
        return new ScenarioStepInput(
            id: null,
            scene: $this->line('manual.bookend.intro.scene'),
            shotType: ShotType::Hiki,
            shootingPoint: null,
            narration: $this->line('manual.bookend.intro.narration', ['title' => $title]),
            subtitlePrimary: $this->clamp(
                $this->line('manual.bookend.intro.subtitle_primary', ['title' => $title]),
                ScenarioLimits::MAX_SUBTITLE_PRIMARY_CHARS,
            ),
            subtitleSecondary: $this->line('manual.bookend.intro.subtitle_secondary', ['title' => $title]),
            materialType: null,
            staticDisplaySeconds: null,
            points: [],
        );
    }

    /** @param list<ScenarioStepInput> $generatedSteps */
    private function summary(string $title, array $generatedSteps): ScenarioStepInput
    {
        $recap = $this->recapLine($generatedSteps); // null = 再掲元なし
        $secondary = $recap !== null
            ? $this->line('manual.bookend.summary.subtitle_secondary_recap', ['points' => $recap])
            : $this->line('manual.bookend.summary.subtitle_secondary_fallback', ['title' => $title]);

        return new ScenarioStepInput(
            id: null,
            scene: $this->line('manual.bookend.summary.scene'),
            shotType: ShotType::Hiki,
            shootingPoint: null,
            narration: $this->line('manual.bookend.summary.narration', ['title' => $title]),
            subtitlePrimary: $this->line('manual.bookend.summary.subtitle_primary'),
            subtitleSecondary: $this->clamp($secondary, ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS),
            materialType: null,
            staticDisplaySeconds: null,
            points: [],
        );
    }

    /**
     * 要点再掲の抽出 (決定的・3 段):
     *  (i)  各 step 配下の point.subtitlePrimary 非空を深さ優先で収集
     *  (ii) (i) が 0 件なら top-level step.subtitlePrimary 非空を収集
     *  (iii)いずれも 0 件なら null (呼び出し側でフォールバック文面)
     * 先頭 N 件 (config、既定 3) を「／」連結。連結後に上限超過なら件数を減らし、
     * 1 件でも超過するなら最後に文字単位 truncate。
     *
     * @param  list<ScenarioStepInput>  $generatedSteps
     */
    private function recapLine(array $generatedSteps): ?string
    {
        $fromPoints = [];
        foreach ($generatedSteps as $step) {
            foreach ($step->points as $point) {
                if ($point->subtitlePrimary !== null && trim($point->subtitlePrimary) !== '') {
                    $fromPoints[] = trim($point->subtitlePrimary);
                }
            }
        }
        $candidates = $fromPoints;
        if ($candidates === []) {
            foreach ($generatedSteps as $step) {
                if ($step->subtitlePrimary !== null && trim($step->subtitlePrimary) !== '') {
                    $candidates[] = trim($step->subtitlePrimary);
                }
            }
        }
        if ($candidates === []) {
            return null;
        }

        $max = config()->integer('manual.summary_recap_max_points');
        $picked = array_slice($candidates, 0, max(1, $max));

        // 件数優先で上限に収める
        while (count($picked) > 1 && mb_strlen(implode('／', $picked)) > ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS) {
            array_pop($picked);
        }

        return implode('／', $picked); // 1 件超過は summary() の clamp が文字単位 truncate
    }

    private function truncatedTitle(string $title): string
    {
        return $this->clamp(
            trim($title),
            config()->integer('manual.scenario_bookend_title_max_chars'),
        );
    }

    private function clamp(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * lang 取得を string に確定させる typed accessor (PHPStan L10。__() は array|string を返しうる)。
     *
     * @param  array<string, string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        $value = trans($key, $replace);

        return is_string($value) ? $value : $key;
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型を `list<ScenarioStepInput>` で明示（PHPDoc）
- [x] null 安全: `subtitlePrimary` は `?string` を trim 前に null/空チェック
- [x] DTO を返す（配列直組みは `ScenarioStepInput` の list のみ）
- [x] `trans()` の `array|string` を `line()` で `string` に閉じる
- [x] `config()->integer(...)` で正整数を確定

### テスト計画（施策5 で実装）
- [ ] point 由来再掲: 先頭 N 件・順序・「／」連結
- [ ] point 0 件 → step subtitle_primary 由来へフォールバック
- [ ] 双方 0 件 → 定型フォールバック文面（recap キーでなく fallback キー）
- [ ] タイトル truncate（>max）
- [ ] subtitle_secondary が上限超過時の件数削減 + 文字 truncate
- [ ] intro/summary の shot_type=Hiki・points=[]・id=null

### リスク
- title に `:` 等 lang 置換記号が含まれても、`trans` の replace は key 名一致のみ置換するため誤展開しない（title は値側）。

---

## 施策4: AnalysisPipeline から builder 呼び出し

### 変更箇所
- `app/Services/Manual/AnalysisPipeline.php` `finalize()`（L194-226）

### 波及変更
- TypeScript 型定義: なし / DTO: なし
- テストファイル: 施策6/7

### 現行コード（該当部）
```php
    private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): bool
    {
        return DB::transaction(function () use ($job, $generated): bool {
            // ... locked manual を取得 ...
            $this->scenarios->materializeIntoLockedManual($lockedManual, $generated->toScenarioSteps());
            // ...
        });
    }
```

### 変更後コード
```php
    // コンストラクタに ScenarioBookendBuilder $bookend を DI 追加

    // finalize 内、locked manual 取得後:
            // 導入/総括カットを terminal tx 内 (locked manual 参照) で決定的に前後付与する。
            // 再掲元は今回生成の steps のみ (DB 既存 cuts 不参照)。
            $steps = $this->bookend->wrap($lockedManual, $generated->toScenarioSteps());
            $this->scenarios->materializeIntoLockedManual($lockedManual, $steps);
```

### PHPStan適合チェック
- [x] `wrap` は `list<ScenarioStepInput>` を返し、`materializeIntoLockedManual(VideoManual, list<ScenarioStepInput>)` の引数型に一致
- [x] 新規 DI は constructor property promotion（`private readonly ScenarioBookendBuilder $bookend`）

### テスト計画
- [ ] 施策6 の Feature テストで end-to-end 検証

### リスク
- 挿入は locked tx 内で実行されるため共有ロック規約に準拠。materialize 前の list 拡張のみで、書き込み経路は増えない（`ScenarioWritePathInventoryTest` 変更不要）。
- ステップ数上限: 生成 step が `ScenarioLimits::MAX_STEPS`(100) 到達時、導入/総括を足すと 102 になる。materialize は上限を強制しない（`GeneratedScenarioData` 側で 100 以内に検証済み、+2 の定型は DoS 対象外）。設計判断として +2 を許容（施策6 で境界を明記。必要なら将来 MAX_STEPS を実運用値へ見直し）。

---

## 施策5: Unit テスト（builder 抽出規則）

### 変更箇所
- `tests/Unit/Manual/ScenarioBookendBuilderTest.php`（新規）

### テスト計画
- [ ] `wrap()` 戻り値の先頭=導入・末尾=総括、中間=渡した steps がそのまま順序保持
- [ ] 導入: shot_type=Hiki, points=[], narration に作業名補間, subtitle_primary が 100 以内
- [ ] 総括(再掲あり): point.subtitle_primary を先頭 N 件「／」連結（config 既定 3、`config(['manual.summary_recap_max_points' => 2])` で件数可変も検証）
- [ ] 総括(step フォールバック): point 全欠時に top-level step.subtitle_primary 由来
- [ ] 総括(定型フォールバック): 双方欠時に fallback lang キー由来
- [ ] タイトル truncate（長い title）
- [ ] subtitle_secondary の上限超過 → 件数削減 → 文字 truncate
- [ ] VideoManual は Factory 生成（`VideoManual::factory()->make(['title' => ...])`。DB 不要なら make）
- [ ] 個別 `DatabaseTransactions` 不使用

### PHPStan適合チェック
- [x] Factory 生成のみ（`Model::create()` 手組み禁止）

---

## 施策6: Feature テスト（materialize 不変条件）

### 変更箇所
- `tests/Feature/Projects/ScenarioBookendMaterializeTest.php`（新規）

### テスト計画（不変条件固定 — 禁止事項1）
- [ ] **初回生成**: AnalysisPipeline 完走後、トップレベル cut の先頭=導入・末尾=総括、間に生成 step。`orderBy('sort_order')` で位置検証。期待値は builder と同じ lang キーで組み立て（文言変更耐性）
- [ ] **再生成**: 2 回目実行で cut が重複せず先頭1/末尾1 のみ（materialize 全置換）
- [ ] **手動 save 後の再生成**: `ScenarioService::save()` で編集後に再解析 → 全置換で再び先頭1/末尾1
- [ ] **point 不在フォールバック**: point を持たない生成応答で総括が fallback 文面
- [ ] shot_type=Hiki / parent_cut_id=null（導入・総括とも top-level）
- [ ] LLM は Prism fake（canned もしくは Prompt::fake で固定応答）
- [ ] `RefreshDatabase` グローバル適用に従う（個別 `DatabaseTransactions` 不使用）

### 実装メモ
- 既存 `tests/Feature/Projects/AnalysisPipelineTest.php` の成功パス構築（Prism fake セットアップ）を踏襲する。

### PHPStan適合チェック
- [x] Factory 生成（Organization/Project/VideoManual/SourceDocument/AnalysisJob）

---

## 施策7: 既存テストの期待値更新（波及）

### 変更箇所
- `tests/Feature/Llm/CannedAnalysisPipelineTest.php` L61-64
- `tests/Feature/Projects/AnalysisPipelineTest.php` L139-142, L349-359

### 波及内容
materialize 後の cut 総数が **2 → 4**（導入 step + 生成 step + 生成 point + 総括 step）になる。トップレベル step は 3 件（導入・生成 step・総括）、point は 1 件。

### 変更後（例: CannedAnalysisPipelineTest）
```php
    $cuts = $manual->cuts()->orderBy('sort_order')->get();
    expect($cuts)->toHaveCount(4); // 導入 + step + point + 総括
    $topLevel = $cuts->where('parent_cut_id', null)->values();
    expect($topLevel)->toHaveCount(3);
    // 先頭=導入 / 末尾=総括 (lang キーで照合)
    expect($topLevel->first()->narration)->toContain($manual->title);
    expect($topLevel->last()->shot_type)->toBe(ShotType::Hiki);
    // 生成 step / point は従来どおり存在
    $point = $cuts->firstWhere('type', CutType::Point);
    expect($point)->not->toBeNull();
```

### AnalysisPipelineTest L349-359（再解析 all-replace）
- `expect($manual->cuts()->count())->toBe(2)` → `toBe(4)` に更新（旧 cut id 消滅の主張は不変）。

### 注意（禁止事項3: 既存テスト削除・上書き禁止）
- 既存テストの**意図（succeeded / ready / version+1 / all-replace / stray 0）は保持**し、cut 件数の期待値のみ、意図した挙動変更に合わせて更新する（テストの削除・別物への差し替えはしない）。

### PHPStan適合チェック
- [x] 追加 import（`ShotType`）のみ

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 生成→materialize の中核経路（AnalysisPipeline / 新 Builder / config / lang）に閉じ、他の進行中施策と競合しにくい。既存テストの期待値更新を含むため単一 worktree で一括変更・検証するのが安全 |
| 競合リスク | `config/manual.php` への追記が他施策と衝突しうる（末尾追記で最小化）。`AnalysisPipeline::finalize` は本施策のみが触る想定 |

## 使命・禁止事項 最終チェック
- 使命寄与: 生成シナリオが常に「導入→手順/急所→総括」の教材構造を持ち、思考ゼロ・編集ゼロで標準化動画の型が揃う（SECI 形式知化）。
- 禁止事項: (1) 各施策にテスト。(2) PHPStan L10 typed accessor で widen 回避。(3) DB 破壊操作なし。(4) JSON 直書きなし。(5)(6) プロンプト不変・factory 経由不変。(7)(8) UI 変更なし。
- 共有ロック規約: 文面確定・materialize とも terminal tx（locked manual）内。書き込み経路は増えない。
</content>
