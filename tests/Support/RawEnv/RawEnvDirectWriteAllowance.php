<?php

declare(strict_types=1);

namespace Tests\Support\RawEnv;

/**
 * 3 面へ直接書いてよい置き場の**型付きの分類**。
 *
 * ★**免除ではなく「1 件の事実」の登録**である。分類を新設するのは
 *   「部品へ寄せられない構造上の理由」が新たに見つかったときだけで、
 *   その判断はレビューで必ず見える (`RawEnvDirectWriteGateTest` の G11 が
 *   パスと分類の対応を完全一致で固定する)。
 */
enum RawEnvDirectWriteAllowance: string
{
    /** 3 面の退避・注入・復元を担う部品そのもの。 */
    case ComponentItself = 'component_itself';

    /** 部品の契約テスト (部品を使わずに 3 面の状態を作らないと往復を検査できない)。 */
    case ComponentContractTest = 'component_contract_test';

    /** 枠組みが立ち上がる前の足場 (autoload された部品を呼べる段階より前に動く)。 */
    case PreFrameworkBootstrap = 'pre_framework_bootstrap';
}
