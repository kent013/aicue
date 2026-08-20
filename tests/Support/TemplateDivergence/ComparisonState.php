<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 指紋台帳のキー 1 件に対する working tree の状態 (3 値)。
 *
 * `role: app` の比較ドメインは**指紋台帳のキー集合のみ**なので、
 * 「現在 shared だが台帳に無い (= 子アプリによる追加)」という状態は持たない
 * (詳細設計 §0 変更 1。追加は逸脱ではなく拡張である)。
 */
enum ComparisonState
{
    /** 内容一致。 */
    case Matched;
    /** 内容相違。 */
    case ContentMismatch;
    /** git 追跡から消えた (削除)。 */
    case MissingCurrent;
}
