<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

/**
 * 旧 URL 残存検査における置き場所・形式の分類 (排他 4 分類のうち 3 つ)。
 *
 * ★4 つ目の「未分類」は case を持たない。分類できなかったことを enum の値で表すと、
 *   その値を持ったまま判定を続けられてしまう (集めるだけで判定に使わない形になる)。
 *   未分類は `LegacyUrlScanRoots::population()` が**別の配列**へ理由付きで積み、
 *   利用側 gate が 1 件でもあれば落とす。
 */
enum LegacyUrlScanClass: string
{
    /** 走査する (旧 URL・撤去 route 名が 1 件も無いことを固定する)。 */
    case Scanned = 'scanned';

    /** 走査しない (理由必須)。 */
    case NotScanned = 'not_scanned';

    /**
     * 自己検査専用 (名指し + 件数)。
     *
     * ★検出したい語を**わざと持つ**のが役目のファイル (負例 fixture と抽出器の自己テスト)。
     *   rule ID では表せないので、`LegacyUrlSelfCheckPopulationTest` が
     *   ファイル名と検出語の一致件数を完全一致で pin する。
     */
    case SelfCheckOnly = 'self_check_only';
}
