<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/**
 * 走査対象ファイルの内容の分類 (バイナリ判定と UTF-8 検証の単一出典が返す値)。
 *
 * ★`RemovedSurfaceScanTargets::classifyContents()` **だけ**がこの値を作る。
 *   同じ判定を 2 本持たないための型である。
 */
enum ContentClassification
{
    /** NUL を含まず UTF-8 として妥当 (実走査母集団へ入る)。 */
    case Text;

    /** NUL バイトを含む (母集団から外すが、利用側 gate は 0 件を要求する)。 */
    case Binary;

    /** NUL は無いが UTF-8 として不正 (未解決として gate を落とす)。 */
    case InvalidUtf8;
}
