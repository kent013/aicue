<?php

declare(strict_types=1);

namespace App\Enums\Security;

/** 外部到達点の分類 (標準形 v1 が要求する 2 分類)。 */
enum ExternalSeamClassification: string
{
    /** 守る対象 (差し替え・監視の設計に含める到達点)。 */
    case Guarded = 'guarded';

    /**
     * 身元検査不要 (外向きの目印は出すが実際には外部へ出ない)。
     *
     * ★**現時点で使用できない**。検出規則を「client の取得・構築」と
     *   「外向き facade の参照」に絞った結果、検出 = 実到達となり母集団が 0 件のため、
     *   免除語彙 (`ExternalSeamExemption`) / 免除前提表 / 30 文字根拠検査を作っていない。
     *   使用する必要が出たら、それらをセットで新設すること
     *   (`ExternalSeamInventoryTest` が失敗メッセージで案内する)。
     */
    case Exempt = 'exempt';
}
