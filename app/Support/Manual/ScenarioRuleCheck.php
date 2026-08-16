<?php

declare(strict_types=1);

namespace App\Support\Manual;

use App\DataTransferObjects\Manual\ScenarioReportData;
use App\DataTransferObjects\Manual\ScenarioRuleFindingData;
use App\Enums\Manual\ScenarioRuleCode;
use App\Models\Cut;
use Illuminate\Support\Collection;

/**
 * シナリオ規約検査 (決定的・純関数)。**DB に触らない** (呼び出し側が取得済み cuts を渡す)。
 *
 * 判定は表示のための材料であり、**制御フローには使わない** (保存・撮影・レンダを止めない)。
 * 規則は doc/03 §3.3 のプロンプト規約に対応し、偽陽性を出さない範囲でのみ機械化する。
 *
 * 数え方:
 * - stepCount = `parent_cut_id === null` の cut 数 (ScenarioBookendBuilder が付ける
 *   導入/総括カットも識別子が無いのでここに含まれる)
 * - pointCount = 親を**この cut 集合の中のトップレベル cut として解決できた**子 cut の数
 * - **数えない cut は 2 種類** (どちらも pointCount にも規約検査にも入れない。
 *   位置を「手順 N-M」として表記できない = 表示できない指摘を出さないため):
 *   (1) 孤児 cut = `parent_cut_id` が非 null だが同じ集合に親が居ない
 *   (2) 三層目の cut = 親は居るがその親自身も子である
 *   DB 制約と保存経路の二層構造から実際には発生しないが、防御的に明示する。
 */
final class ScenarioRuleCheck
{
    /** 指摘 1 件あたりに載せる位置の上限 (画面が長くならないための表示上の都合) */
    public const int MAX_POSITIONS_PER_CODE = 5;

    /** ナレーションの許容終端 (丁寧体)。「〜してはいけません」「〜が必要です」を偽陽性にしない */
    private const array POLITE_ENDINGS = ['ます', 'ません', 'ました', 'ましょう', 'です', 'でした'];

    /**
     * 終端判定の前に落とす末尾の空白・句点 (Unicode 対応の正規表現)。
     *
     * ★ `rtrim($s, "。.!！")` は使えない。`rtrim` の charlist は**バイト単位**で解釈されるため、
     *   マルチバイト文字を渡すとその構成バイトが個別に剥がされ、UTF-8 文字列を壊しうる。
     */
    private const string TRAILING_MARKS_PATTERN = '/[\s。．.!！]+$/u';

    /**
     * @param  Collection<int, Cut>  $orderedCuts  sort_order (同値なら id) 昇順で取得済みの全 cut
     */
    public static function run(Collection $orderedCuts): ScenarioReportData
    {
        /** @var list<Cut> $topLevel */
        $topLevel = [];
        /** @var array<int, list<Cut>> $childrenByParent */
        $childrenByParent = [];
        foreach ($orderedCuts as $cut) {
            $parentId = $cut->parent_cut_id;
            if ($parentId === null) {
                $topLevel[] = $cut;

                continue;
            }
            $childrenByParent[$parentId][] = $cut;
        }

        // code ごとの累積 (件数は全件、位置は先頭 MAX_POSITIONS_PER_CODE 件のみ保持する)
        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, list<array{step: int, point: int|null}>> $positions */
        $positions = [];
        $record = static function (Cut $cut, int $step, ?int $point) use (&$counts, &$positions): void {
            foreach (self::violationsOf($cut) as $code) {
                $key = $code->value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                if (count($positions[$key] ?? []) < self::MAX_POSITIONS_PER_CODE) {
                    $positions[$key][] = ['step' => $step, 'point' => $point];
                }
            }
        };

        $pointCount = 0;
        $stepNumber = 0;
        foreach ($topLevel as $step) {
            $stepNumber++;
            $record($step, $stepNumber, null);

            $pointNumber = 0;
            foreach ($childrenByParent[$step->id] ?? [] as $point) {
                $pointNumber++;
                $pointCount++;
                $record($point, $stepNumber, $pointNumber);
            }
        }

        // 出力順は enum の宣言順に固定する (画面の並びが実データで揺れない)
        $findings = [];
        foreach (ScenarioRuleCode::cases() as $code) {
            $count = $counts[$code->value] ?? 0;
            if ($count === 0) {
                continue;
            }
            $findings[] = new ScenarioRuleFindingData($code, $count, $positions[$code->value] ?? []);
        }

        return new ScenarioReportData(
            verdict: null, // 所見は LLM 由来なので呼び出し側 (ScenarioReportBuilder) が合流させる
            stepCount: count($topLevel),
            pointCount: $pointCount,
            findings: $findings,
        );
    }

    /**
     * 1 cut が該当する指摘コード (**同一 cut が複数 code に載りうる**)。
     *
     * @return list<ScenarioRuleCode>
     */
    private static function violationsOf(Cut $cut): array
    {
        $codes = [];
        $narration = $cut->narration;
        if (trim($narration) === '') {
            $codes[] = ScenarioRuleCode::NarrationMissing;
        } elseif (! self::endsPolitely($narration)) {
            // ナレーションが空のときは文体を問わない (空であることが唯一の指摘)
            $codes[] = ScenarioRuleCode::NarrationNotPolite;
        }
        if (str_contains($narration, 'ください')) {
            $codes[] = ScenarioRuleCode::NarrationDirective;
        }

        $primary = $cut->subtitle_primary;
        if ($primary !== null && self::looksLikeSentence($primary)) {
            $codes[] = ScenarioRuleCode::SubtitlePrimarySentence;
        }
        if (trim($cut->subtitle_secondary) === '') {
            $codes[] = ScenarioRuleCode::SubtitleSecondaryMissing;
        }

        return $codes;
    }

    /** ナレーションが丁寧体で終わっているか (末尾の空白・句点を落として判定) */
    private static function endsPolitely(string $narration): bool
    {
        $trimmed = preg_replace(self::TRAILING_MARKS_PATTERN, '', $narration) ?? $narration;
        foreach (self::POLITE_ENDINGS as $ending) {
            if (str_ends_with($trimmed, $ending)) {
                return true;
            }
        }

        return false;
    }

    /** 字幕① が名称・数値ではなく文に見えるか (句点または丁寧体の語を含む) */
    private static function looksLikeSentence(string $subtitlePrimary): bool
    {
        return str_contains($subtitlePrimary, '。')
            || str_contains($subtitlePrimary, 'ます')
            || str_contains($subtitlePrimary, 'です');
    }
}
