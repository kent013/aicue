<?php

declare(strict_types=1);

namespace App\Support\Smoke;

use App\Enums\Manual\JobStatus;
use App\Enums\Smoke\SmokeFailureClass;
use App\Enums\Smoke\SmokeStage;

/**
 * pipeline smoke の失敗分類器 (純関数)。
 * 配置と流儀は `App\Support\Billing\GatewayFailureClassifier` に合わせている。
 *
 * 判定順 (先に一致したものを返す):
 *  1. 段が成功 → null (分類しない)
 *  2. preflight → Preflight
 *  3. timeout ∧ queued → Wiring / 4. timeout ∧ running → StageTimeout
 *  5. render ∧ error_code → Render
 *  6. artifact ∧ 読めない → Storage / 7. artifact ∧ ffprobe 失敗 → Render
 *  8. llm-evidence ∧ 成功行あり ∧ 記録不完全 → Wiring
 *  9. LLM 起因になり得る段 ∧ (failure 行あり ∨ 成功行なし) → Llm
 * 10. それ以外 → Unknown
 */
final readonly class SmokeFailureClassifier
{
    /**
     * LLM が原因になり得る段 (`Llm` 分類の適用範囲を**この集合に閉じる**)。
     *
     * @var list<SmokeStage>
     */
    private const array LLM_ATTRIBUTABLE_STAGES = [SmokeStage::Analysis, SmokeStage::LlmEvidence];

    /**
     * 失敗の観測分類。**成功した段では null を返す**。
     *
     * @param  bool  $stageSucceeded  段が成功したか
     * @param  ?JobStatus  $jobStatus  観測したジョブ状態 (段によっては null)
     * @param  bool  $timedOut  待機上限に到達したか
     * @param  bool  $hasLlmFailureRow  この実行分に failure_reason 行があるか
     * @param  bool  $hasLlmSuccessRow  この実行分に成功行があるか
     * @param  bool  $llmRecordingIncomplete  成功行はあるが記録が不完全か (帰属欠落 or template 欠落)
     * @param  bool  $hasRenderErrorCode  render_jobs.error_code が非 null か
     * @param  bool  $outputReadable  出力オブジェクトを読み出せたか
     * @param  bool  $ffprobeFailed  ffprobe が非 0 終了したか
     */
    public static function classify(
        SmokeStage $stage,
        bool $stageSucceeded,
        ?JobStatus $jobStatus,
        bool $timedOut,
        bool $hasLlmFailureRow,
        bool $hasLlmSuccessRow,
        bool $llmRecordingIncomplete,
        bool $hasRenderErrorCode,
        bool $outputReadable,
        bool $ffprobeFailed,
    ): ?SmokeFailureClass {
        if ($stageSucceeded) {
            return null; // 成功時のリトライ痕 (failure_reason 行) を失敗として分類しない
        }

        return match (true) {
            $stage === SmokeStage::Preflight => SmokeFailureClass::Preflight,
            $timedOut && $jobStatus === JobStatus::Queued => SmokeFailureClass::Wiring,
            $timedOut && $jobStatus === JobStatus::Running => SmokeFailureClass::StageTimeout,
            $stage === SmokeStage::Render && $hasRenderErrorCode => SmokeFailureClass::Render,
            $stage === SmokeStage::Artifact && ! $outputReadable => SmokeFailureClass::Storage,
            $stage === SmokeStage::Artifact && $ffprobeFailed => SmokeFailureClass::Render,
            // LLM は動いているのにアプリ側の記録経路が欠けている = 配線の問題 (provider の問題ではない)
            $stage === SmokeStage::LlmEvidence && $hasLlmSuccessRow && $llmRecordingIncomplete => SmokeFailureClass::Wiring,
            in_array($stage, self::LLM_ATTRIBUTABLE_STAGES, true)
                && ($hasLlmFailureRow || ! $hasLlmSuccessRow) => SmokeFailureClass::Llm,
            default => SmokeFailureClass::Unknown,
        };
    }

    /**
     * 成功行ごとの観測を「**その成功行がすべて**帰属していた template」へ畳み込む (AND)。
     *
     * OR で畳むと「正しい行が 1 本あれば通る」になり、設計の
     * 「成功行がすべて `metadata_missing = false` ∧ 期待した organization / subject」を満たさない
     * (同じ template に正しい行と壊れた行が混在したときに見逃す)。
     *
     * DB 読み出しは呼び出し側 (コマンド) が行い、本関数は集合演算だけを行う
     * = DB なしの Unit テストで畳み込み規則を直接固定できる。
     *
     * @param  list<array{string, bool}>  $observations  成功行ごとの (prompt_template, 帰属が期待どおりか)
     * @return list<string> 出現順を保った template 名 (すべての行で帰属が期待どおりだったもの)
     */
    public static function fullyAttributedTemplates(array $observations): array
    {
        /** @var array<string, bool> $byTemplate */
        $byTemplate = [];
        foreach ($observations as [$template, $matched]) {
            $byTemplate[$template] = ($byTemplate[$template] ?? true) && $matched;
        }

        return array_keys(array_filter($byTemplate));
    }

    /**
     * 「LLM は成功しているのに記録が欠けている」の導出 (純関数。DB 読み出しは呼び出し側の責務)。
     *
     * 2 原因をまとめて 1 つの bool にする:
     *   - 必要 template の成功行が足りない (analysis は成功したのに記録が落ちた)
     *   - 成功行はあるが帰属 (organization / subject) が期待と違う
     *
     * ★ 呼び出し側の責務: `$succeededTemplates` / `$attributedTemplates` は
     *   **`$requiredTemplates` に限定した集合**であること (クエリに
     *   `->whereIn('prompt_template', $requiredTemplates)` を付ければ足りる)。
     *   対象外の template が混ざると本 smoke と無関係な行まで「不完全」と判定してしまう。
     *
     * @param  list<string>  $requiredTemplates  期待する prompt_template (3 段)
     * @param  list<string>  $succeededTemplates  この実行分の成功行が存在した template (required に限定)
     * @param  list<string>  $attributedTemplates  うち帰属が期待どおりだった template (required に限定)
     */
    public static function llmRecordingIncomplete(
        array $requiredTemplates,
        array $succeededTemplates,
        array $attributedTemplates,
    ): bool {
        if ($succeededTemplates === []) {
            return false; // 成功行が 1 行も無いのは「記録の不備」ではなく Llm 側の疑い
        }

        return array_diff($requiredTemplates, $succeededTemplates) !== []
            || array_diff($succeededTemplates, $attributedTemplates) !== [];
    }
}
