<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * `adoptedTake` relation を参照する app/ 配下ファイルの区分 (T148)。
 *
 * 「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
 * `AdoptedReadyTakeCoverage` ただ 1 ファイルである、という不変条件を
 * `AdoptedReadyTakeCriterionInventoryTest` が deny-by-default で機械検査する。
 * 区分は「統合してよい」の意味ではなく、**何のために触っているか**の記録である。
 */
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
