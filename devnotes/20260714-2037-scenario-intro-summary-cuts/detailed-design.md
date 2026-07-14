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
| 1 | 構造値の config 追加 + `ScenarioLimits` に材料化上限定数＋コメント | `config/manual.php` / `app/Support/Manual/ScenarioLimits.php` | High |
| 2 | 定型文面の lang 追加 | `lang/ja/manual.php`（新規） | High |
| 3 | ScenarioBookendBuilder 新設 | `app/Services/Manual/ScenarioBookendBuilder.php`（新規） | High |
| 4 | AnalysisPipeline から builder 呼び出し | `app/Services/Manual/AnalysisPipeline.php` | High |
| 4.5 | **手動保存の top-level 上限を材料化上限に整合（Codex R2 Critical）** | `app/Http/Requests/Projects/UpdateScenarioRequest.php` | High |
| 5 | Unit テスト（builder 抽出規則） | `tests/Unit/Manual/ScenarioBookendBuilderTest.php`（新規） | High |
| 6 | Feature テスト（materialize 不変条件 + 102 件の編集 round-trip） | `tests/Feature/Projects/ScenarioBookendMaterializeTest.php`（新規） | High |
| 7 | 既存テストの期待値更新（波及） | `tests/Feature/Llm/CannedAnalysisPipelineTest.php` / `tests/Feature/Projects/AnalysisPipelineTest.php` / `tests/Feature/Projects/ScenarioUpdateTest.php` | High |

---

## 施策1: 構造値の config 追加

### 変更箇所
- `config/manual.php`（AI 解析設定ブロック）

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし / テストファイル: 施策5/6 が参照

### 変更後コード
```php
    // ── シナリオ導入/総括カット (概念設計 §改善アイデア) ──────────────
    // 総括カットの要点再掲に載せる最大件数 (先頭から)。0 以下は builder が 1 件扱いに補正。
    'summary_recap_max_points' => 3,
    // 導入/総括の作業名補間で用いるタイトルの truncate 上限 (subtitle_primary=100 に収める)。
    'scenario_bookend_title_max_chars' => 60,
```

### 追加変更（Codex Critical F / R2 反映）
- `app/Support/Manual/ScenarioLimits.php` に新定数を追加:
  ```php
  /** LLM 生成 step の上限 (生成 DTO 検証が強制。DoS/桁 guard)。 */
  public const int MAX_STEPS = 100; // 既存 (コメントのみ更新)

  /** 手動保存で許容する top-level cut 総数上限 (生成 100 + 導入/総括 2 の materialized を再保存可能に)。 */
  public const int MAX_TOP_LEVEL_CUTS = self::MAX_STEPS + 2;
  ```
- **`MAX_STEPS` は生成 DTO 検証（`GeneratedScenarioData`）でのみ使う**（LLM が生成する step を 100 に保つ）。**`MAX_TOP_LEVEL_CUTS`(=102) は手動保存の top-level 上限**（施策4.5）。
- **正確な仕様（Codex R3 反映）**: 現モデルは定型カットを識別できないため、**手動保存で「通常手順 100 件 + 定型 2 件」という内訳は強制しない**。手動保存が保証するのは「top-level cut 総数 ≤ 102」のみ。「通常手順を厳密に 100 に保つ」には bookend 識別用の永続属性が必要で、それは独立種別なし方針（v1）の対象外。

### PHPStan適合チェック
- [x] 読み出しは `config()->integer('manual.summary_recap_max_points')` 等の typed accessor（正整数保証）

### テスト計画
- [ ] 施策6 の Feature テストで既定値の挙動を間接検証。config 単体テストは作らない（値のみ）

### リスク
- なし（新規キー追加＋コメントのみ。既存参照に影響なし）

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
- [x] lang 取得は `string` 確定の typed accessor（施策3 の `line()` ヘルパ）で行い、`array|string` の緩さを閉じる

### テスト計画
- [ ] 施策5 でキー存在と補間結果を検証（全利用キーの存在テストを含む）

### リスク（Codex 反映）
- 文言変更でテストが壊れないよう、テストは**同じ lang キー**で期待値を組み立てる（ハードコード文字列照合をしない）
- **キー欠落の静かな見逃し防止**: `line()` は未定義キー時にフォールバック文字列を返さず `LogicException`（fail-fast）にする（施策3）。lang 追加漏れをテスト/実行で即検出。

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
use Illuminate\Support\Facades\Lang;
use LogicException;
use Webmozart\Assert\Assert;

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
        $secondary = $this->summarySecondary($title, $generatedSteps);

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
     * 総括 subtitle_secondary の決定的組み立て（Codex R2 反映: lang 接頭辞込みの「完成文」で長さ判定）。
     *  - 再掲候補（3 段）: (i) point.subtitlePrimary 非空を深さ優先 → (ii) 0 件なら top-level
     *    step.subtitlePrimary 非空 → (iii) いずれも 0 件なら定型フォールバック文面。
     *  - 件数 N (config 既定 3、`max(1,$max)` で下限 1)。「／」連結し接頭辞付き完成文を作る。
     *  - **完成文（接頭辞込み）**が上限超過なら件数を減らす（>1 件のみ）。1 件でも超過なら最後に
     *    完成文を文字単位 truncate（接頭辞ごと収める）。
     *
     * @param  list<ScenarioStepInput>  $generatedSteps
     */
    private function summarySecondary(string $title, array $generatedSteps): string
    {
        $candidates = $this->recapCandidates($generatedSteps);
        if ($candidates === []) {
            return $this->clamp(
                $this->line('manual.bookend.summary.subtitle_secondary_fallback', ['title' => $title]),
                ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS,
            );
        }

        $n = max(1, config()->integer('manual.summary_recap_max_points'));
        $picked = array_slice($candidates, 0, $n);

        // 件数優先: 完成文（lang 接頭辞込み）で上限判定
        while (count($picked) > 1
            && mb_strlen($this->renderRecap($picked)) > ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS) {
            array_pop($picked);
        }

        // 1 件でも超過するなら完成文を文字単位 truncate
        return $this->clamp($this->renderRecap($picked), ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS);
    }

    /**
     * 要点再掲の完成文 (lang 接頭辞込み)。PHPStan L10 のため closure でなく typed メソッドに分離。
     *
     * @param  list<string>  $items
     */
    private function renderRecap(array $items): string
    {
        return $this->line(
            'manual.bookend.summary.subtitle_secondary_recap',
            ['points' => implode('／', $items)],
        );
    }

    /**
     * 再掲候補の決定的抽出（3 段の (i)(ii) まで。空なら空配列）。
     *
     * @param  list<ScenarioStepInput>  $generatedSteps
     * @return list<string>
     */
    private function recapCandidates(array $generatedSteps): array
    {
        $candidates = [];
        foreach ($generatedSteps as $step) {
            foreach ($step->points as $point) {
                $v = $this->normalize($point->subtitlePrimary);
                if ($v !== '') {
                    $candidates[] = $v;
                }
            }
        }
        if ($candidates !== []) {
            return $candidates;
        }
        foreach ($generatedSteps as $step) {
            $v = $this->normalize($step->subtitlePrimary);
            if ($v !== '') {
                $candidates[] = $v;
            }
        }

        return $candidates;
    }

    private function truncatedTitle(string $title): string
    {
        return $this->clamp(
            $this->normalize($title),
            config()->integer('manual.scenario_bookend_title_max_chars'),
        );
    }

    private function clamp(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /** 全角空白含めた前後空白除去 (Codex 反映。trim は全角空白を落とせない)。null は '' 扱い。 */
    private function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $result = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value);
        Assert::string($result); // preg エラー(null)を空文字で握りつぶさず異常を露出 (Codex 反映)

        return $result;
    }

    /**
     * lang 取得を string に確定させる typed accessor (PHPStan L10。__() は array|string を返しうる)。
     * 未定義キーは静かに見逃さず LogicException (fail-fast。lang 追加漏れを即検出。Codex 反映)。
     *
     * @param  array<string, string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        if (! Lang::has($key)) {
            throw new LogicException("シナリオ導入/総括の lang キーが未定義: {$key}");
        }
        $value = trans($key, $replace);
        Assert::string($value); // has() 済みで配列ノードではないことを型に閉じる

        return $value;
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

### MAX_STEPS 境界の明文化（Codex Critical F / R2 反映）
- `ScenarioLimits::MAX_STEPS`(100) = **LLM 生成/手動編集の「手順 step」上限**（`GeneratedScenarioData::fromLlmText` が強制。据え置き）。
- 導入/総括は**サーバ定型 2 カット**であり別枠。materialize 後・手動保存の **top-level cut 総数上限**は `MAX_TOP_LEVEL_CUTS = MAX_STEPS + 2`(=102)。
- **統合上の必須対応（施策4.5）**: 手動保存 `UpdateScenarioRequest` の `steps` 配列上限を `MAX_STEPS` → `MAX_TOP_LEVEL_CUTS` に整合させる。これがないと 102 件 materialize されたシナリオを編集画面から再保存できず 422 になる（タコツボ破綻。施策6 の round-trip テストで保証）。
- 生成 step を末尾から削る案は「実手順の欠落」を招き使命に反するため採らない。

---

## 施策4.5: 手動保存の top-level 上限を材料化上限に整合（Codex R2 Critical）

### 変更箇所
- `app/Http/Requests/Projects/UpdateScenarioRequest.php` L81

### 波及変更
- TS 型定義: なし / DTO: なし
- テストファイル: `tests/Feature/Projects/ScenarioUpdateTest.php` L505-512（上限超過 422 の境界を 101→103 に更新）

### 現行コード
```php
'steps' => ['present', 'array', 'max:'.ScenarioLimits::MAX_STEPS],
```

### 変更後コード
```php
// v1 は定型カットを識別できないため、手動保存の上限は「top-level cut 総数 ≤ 102」で表現する
// (生成 100 手順 + 導入/総括 2 の materialized をそのまま再保存できる)。内訳 (通常/定型) は強制しない。
'steps' => ['present', 'array', 'max:'.ScenarioLimits::MAX_TOP_LEVEL_CUTS],
```

### PHPStan適合チェック
- [x] 定数参照のみ（型変化なし）

### テスト計画
- [ ] `ScenarioUpdateTest` L505-512 の `array_fill(0, 101, ...)` を `MAX_TOP_LEVEL_CUTS + 1`(=103) に更新（102 は許容・103 で `steps` 422）。points 上限（21→`steps.0.points` 422）は不変
- [ ] 施策6 で 102 件 round-trip の正常系を保証

### リスク
- 手動オーサリングで最大 102 の top-level cut を作れる（従来 100）。DoS/桁 guard 上は無害（102≈100）。導入/総括を独立判別できない v1 制約に対する必要な整合であり、「通常手順を厳密に 100」に保つ保証は v1 では持たない（Codex R3 で明文化）。

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
- [ ] **全角空白のみ**の subtitle_primary は再掲元に採らない（`normalize()` 検証。Codex 反映）
- [ ] タイトル truncate（長い title）
- [ ] **subtitle_secondary 長さ（接頭辞込み完成文で判定 — Codex R2 反映）**: (a) 複数件で完成文が上限超過 → 件数削減、(b) 1 件でも完成文が上限超過 → 完成文を文字単位 truncate（接頭辞ごと ≤2000）。recap 本文単独でなく `subtitle_secondary_recap` 完成文が常に ≤2000 であることを検証
- [ ] **全利用 lang キーの存在テスト**（intro/summary 各キー。欠落時 `line()` が LogicException。Codex 反映）
- [ ] **`summary_recap_max_points=0 / -1` の防御テスト**（`max(1,$max)` で 1 件扱いになる仕様固定。Codex 反映）
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
- [ ] **再生成の再掲元が今回生成のみ（論点B 強化・Codex 反映）**: 1 回目と 2 回目で異なる point 文言を返し、2 回目後の総括再掲が 2 回目由来のみ（旧 cut 不参照）
- [ ] **MAX_STEPS 境界（Codex Critical F）**: 生成 step=100 の fake で完走 → トップレベル step=102（導入+100+総括）が全て materialize され切り捨て/reject なし
- [ ] **102 件の編集 round-trip（Codex R2 Critical / R3）**: 上記 102 件 materialize 後、全 102 top-level を payload 化し `PUT /projects/{p}/manuals/{m}/scenario` に expected_version 込みで `putJson` 送信 → **既存 `ScenarioUpdateTest` 成功パスと同じ応答契約**（`assertOk()` + `assertJsonPath('scenario_version', ...)`。当 endpoint は仕様固定 JSON）で検証し、その後 DB で 102 件・順序・version+1 を確認（`MAX_TOP_LEVEL_CUTS` 整合の証明）
- [ ] shot_type=Hiki / parent_cut_id=null（導入・総括とも top-level）/ 生成 point が中間 step にぶら下がる（親 ID 整合）
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

### 変更後（例: CannedAnalysisPipelineTest）— 件数だけでなく構造も固定（Codex E 反映）
```php
    $cuts = $manual->cuts()->orderBy('sort_order')->get();
    expect($cuts)->toHaveCount(4); // 導入 + step + point + 総括
    $topLevel = $cuts->where('parent_cut_id', null)->values();
    expect($topLevel)->toHaveCount(3);
    // 先頭=導入 / 末尾=総括: 位置・型・shot_type・親子を退行検出（件数のみに頼らない）
    expect($topLevel->first()->parent_cut_id)->toBeNull();
    expect($topLevel->first()->shot_type)->toBe(ShotType::Hiki);
    expect($topLevel->first()->narration)->toContain($manual->title);
    expect($topLevel->last()->parent_cut_id)->toBeNull();
    expect($topLevel->last()->shot_type)->toBe(ShotType::Hiki);
    // 生成 step / point は従来どおり存在し、point は中間 step にぶら下がる
    $generatedStep = $topLevel->get(1); // 導入(0) と 総括(2) の間
    $point = $cuts->firstWhere('type', CutType::Point);
    expect($point)->not->toBeNull();
    expect($point->parent_cut_id)->toBe($generatedStep->id);
```

### AnalysisPipelineTest L139-142（成功パス）/ L349-359（再解析 all-replace）
- 成功パス: `toHaveCount(2)` → `toHaveCount(4)` に更新し、上記と同種の位置・型・親子アサートを追加（導入=先頭 top-level、総括=末尾 top-level、生成 point は中間 step 配下）。
- 再解析 all-replace: `expect($manual->cuts()->count())->toBe(2)` → `toBe(4)`（旧 cut id 消滅の主張は不変）。

### ScenarioUpdateTest L505-512（施策4.5 波及）
- `array_fill(0, 101, scenarioStepPayload())` → `array_fill(0, ScenarioLimits::MAX_TOP_LEVEL_CUTS + 1, ...)`(=103) に更新（102 は許容・103 で 422）。points 上限（21→`steps.0.points` 422）は不変。

### 注意（禁止事項3: 既存テスト削除・上書き禁止）
- 既存テストの**意図（succeeded / ready / version+1 / all-replace / stray 0 / 上限超過 422）は保持**し、cut 件数・境界値の期待値のみ、意図した挙動変更に合わせて更新する（テストの削除・別物への差し替えはしない）。

### PHPStan適合チェック
- [x] 追加 import（`ShotType`）のみ
- [x] `$topLevel->get(1)` / `firstWhere` の null 可能性は既存方針に従い `Assert::isInstanceOf(..., Cut::class)` で閉じる（Codex R2 Suggestion）

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
