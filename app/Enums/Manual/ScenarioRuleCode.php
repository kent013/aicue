<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * シナリオ規約検査の指摘コード (doc/03 §3.3 のプロンプト規約のうち機械検査できるもの)。
 *
 * **意図的に入れていない検査**: 「急所が 0 件の手順」は ScenarioBookendBuilder が付ける
 * 導入/総括カットが構造上必ず該当し (DB 上に識別子が無い)、全マニュアルで恒常的な
 * 偽陽性 2 件になるため入れない。
 * **閾値 (文字数上限等) を持つ検査も入れない** (根拠となる実データが無いため)。
 *
 * TS 側 resources/js/types/manual.ts の ScenarioRuleCode union と値集合を一致させる
 * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
 */
enum ScenarioRuleCode: string
{
    case NarrationMissing = 'narration_missing';
    case NarrationNotPolite = 'narration_not_polite';
    case NarrationDirective = 'narration_directive';
    case SubtitlePrimarySentence = 'subtitle_primary_sentence';
    case SubtitleSecondaryMissing = 'subtitle_secondary_missing';
}
