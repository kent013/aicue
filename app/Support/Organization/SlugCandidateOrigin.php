<?php

declare(strict_types=1);

namespace App\Support\Organization;

/**
 * 識別名の候補の**由来**。一意衝突したときの遷移を由来ごとに決めるために型で持つ
 * (`?string` を後から再解釈すると、毎回同じ導出値で再試行して同じ衝突を繰り返す)。
 */
enum SlugCandidateOrigin
{
    /** 利用者が明示した。衝突したら**即 422** (黙って代替を作らない)。 */
    case Requested;

    /** 組織名から導出した。衝突したら Fallback へ**1 回だけ**遷移する。 */
    case Derived;

    /** `org-{12 文字乱数}`。衝突したら新しい乱数で最大 3 回まで。 */
    case Fallback;
}
