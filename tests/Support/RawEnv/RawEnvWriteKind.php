<?php

declare(strict_types=1);

namespace Tests\Support\RawEnv;

/**
 * 生の環境変数 3 面への**書き込みの形**の分類 (`RawEnvDirectWriteScanner` の出力語彙)。
 *
 * ★値は目録のキーに使うので `string` backed である。
 * ★`Unresolved` は「分類できなかった出現」であり**必ず違反**になる。
 *   目録へ登録して黙らせることはできない (`RawEnvDirectWriteGateTest` の G7 が別途赤にする)。
 */
enum RawEnvWriteKind: string
{
    /** 面の要素への代入 (通常 / 複合 / `??=` / 前後置インクリメント / 多段添字)。 */
    case ElementAssign = 'element_assign';

    /** 面の要素の削除 (`unset($_SERVER['K'])`)。 */
    case ElementUnset = 'element_unset';

    /** 面そのものへの代入 (複合代入を含む)。 */
    case WholeAssign = 'whole_assign';

    /** 面 / 面の要素への参照の取得 (`&$_SERVER['K']`)。 */
    case ReferenceTaken = 'reference_taken';

    /** 分割代入の左辺に面が現れる形 (`[$_SERVER['K']] = $v;`)。 */
    case DestructuringTarget = 'destructuring_target';

    /** プロセス面への書き込み (`putenv('K=V')` / `putenv('K')` の両形)。 */
    case Putenv = 'putenv';

    /** 分類できなかった出現 (fail-closed。目録へ登録できない)。 */
    case Unresolved = 'unresolved';
}
