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
     * 導入/総括の定型文面を解決する固定ロケール。
     * v1 は Japanese 単一ロケールの動画マニュアル (North Star) であり、この文面は UI i18n ではなく
     * 「動画に載る日本語ドメインコンテンツ」。materialize は DB 書き込み経路のため、ambient な
     * APP_LOCALE (テストは en) に依存させず、文面が存在する ja に pin して決定性・堅牢性を担保する。
     */
    private const string CONTENT_LOCALE = 'ja';

    /**
     * @param  list<ScenarioStepInput>  $generatedSteps
     * @return list<ScenarioStepInput> [導入, ...generatedSteps, 総括]
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
        if (! Lang::has($key, self::CONTENT_LOCALE)) {
            throw new LogicException("シナリオ導入/総括の lang キーが未定義: {$key}");
        }
        $value = trans($key, $replace, self::CONTENT_LOCALE);
        Assert::string($value); // has() 済みで配列ノードではないことを型に閉じる

        return $value;
    }
}
