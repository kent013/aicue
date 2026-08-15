<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

/**
 * 追跡されている PHP の置き場所を、禁止する文の検査に対してどう扱うかの分類。
 *
 * ★**3 つは排他**であり、どれにも分類していない置き場所が現れたら gate は赤になる。
 *   走査根を検査ファイルへ列挙するだけにすると、新しいディレクトリを足したときに
 *   **黙って走査対象から外れる**。
 */
enum ForbiddenStatementRootPolicy: string
{
    /** 走査する。例外の登録を一切許さない (アプリの実行経路そのもの)。 */
    case ScannedNoExemption = 'scanned_no_exemption';

    /** 走査する。理由付きの例外登録を許す (別プロセスで走る CLI と検体)。 */
    case ScannedWithExemption = 'scanned_with_exemption';

    /** 走査しない。理由の記載が必須。 */
    case Excluded = 'excluded';
}
