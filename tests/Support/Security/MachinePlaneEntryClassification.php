<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 機械経路の入口 1 件の分類 (家系裁定 AG-047)。
 *
 * ★「解決点が 0 件であることを検査した入口」と「解決点を持つ入口」を**型で分ける**。
 *   1 つの enum に混ぜると「まだ分類していない」と「0 件だと確かめた」の区別が消える。
 */
abstract readonly class MachinePlaneEntryClassification {}
