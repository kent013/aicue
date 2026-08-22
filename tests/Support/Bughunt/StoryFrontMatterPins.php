<?php

declare(strict_types=1);

namespace Tests\Support\Bughunt;

/**
 * シナリオカードの書式契約の自己テストに対する固定値 (不変の scalar / 配列定数だけを持つ)。
 *
 * ★**解析・ファイル I/O・プロセス実行を一切持たない**。値の置き場所を 1 か所にするための型である。
 *   Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
 *   固定値はクラス定数として置く (`Tests\Support\TemplateDivergence\LedgerPins` と同じ理由・同じ作法)。
 * ★**これは免除の一覧ではない**。個別の検査を名指しして無効化する仕組みは本機構のどこにも無い。
 */
final class StoryFrontMatterPins
{
    /** インスタンス化しない (定数の置き場)。 */
    private function __construct() {}

    /**
     * 活きている検査の件数の下限 (実測値)。
     *
     * ★**下限**である (上振れは許す)。減ることだけを禁じ、検査を削って緑にする道を塞ぐ。
     */
    public const int MIN_TESTS = 82;

    /**
     * 中核の負例。名前だけでなく `... ok` の成功表示まで照合して skip 逃げを塞ぐ。
     *
     * @var list<string>
     */
    public const array CORE_NEGATIVES = [
        'test_ac_01_rejects_quoted_scalar',
        'test_ac_01_rejects_duplicate_key',
        'test_ac_01_rejects_key_out_of_canonical_order',
        'test_ac_02_rejects_unknown_lane',
        'test_ac_03_rejects_gap_in_card_numbers',
        'test_ac_04_rejects_removed_family_surface',
        'test_ac_05_rejects_card_missing_from_inventory',
        'test_ac_06_rejects_reassigned_family_surface',
        'test_ac_07_rejects_dependency_cycle',
        'test_ac_08_rejects_reseed_with_dependency',
        'test_ac_09_rejects_parallel_depending_on_serial',
        'test_ac_10_rejects_steps_in_not_applicable_card',
        'test_ac_11_rejects_heading_mismatch',
        'test_ac_12_rejects_legacy_meta_section',
        'test_ac_13_rejects_duplicate_array_element',
        'test_ac_15_rejects_missing_purpose_section',
    ];
}
