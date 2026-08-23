<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

/**
 * 旧 URL 検出の許可目録 (deny-by-default。**件数まで完全一致**)。
 *
 * ★形式は **パス + 検出規則 ID + 区分 + 件数 + 30 文字以上の理由**である。
 * ★**旧 URL の文字列そのものを目録へ写さない**。写すと目録自身が検出対象になり
 *   「自分を許可するための登録」という再帰が始まる。目録が持つのは
 *   「どの規則の、どのファイルの、何件を許すか」だけである。
 * ★**ファイル全体を走査から外さない**。登録した規則 ID 以外の検出は、
 *   同じファイルの中でも引き続き違反になる。
 * ★件数は完全一致である (増えても減っても赤)。減ったときも赤にするのは、
 *   許可の理由が消えたのに登録が残る状態を作らないためである。
 */
final class LegacyUrlAllowance
{
    /** インスタンス化しない (目録の置き場)。 */
    private function __construct() {}

    /**
     * 登録一覧。
     *
     * @return list<array{path: string, rule: string, kind: LegacyUrlAllowanceKind, count: int, reason: string}>
     */
    public static function entries(): array
    {
        return [
            [
                'path' => 'tests/Architecture/PromptDefenseWindowGateTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'count' => 2,
                'reason' => 'prompt factory の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => '.claude/skills/app-bug-hunt/coverage/correlate.py',
                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'count' => 1,
                'reason' => 'スタックトレースの絶対パスをリポジトリ相対へ畳む処理の説明文であり、'
                    .'指しているのは app/ ディレクトリのファイルパスで、画面の URL ではない。',
            ],
            [
                'path' => '.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py',
                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'count' => 1,
                'reason' => '対象外判定の見本に置いた管理画面の実装ディレクトリのパスであり、'
                    .'ファイルシステム上の位置を指す文字列で画面の URL ではない。',
            ],
            [
                'path' => 'docs/architecture.md',
                'rule' => LegacyUrlScanner::RULE_REMOVED_ROUTE_NAME,
                'kind' => LegacyUrlAllowanceKind::AbsenceAssertion,
                'count' => 1,
                'reason' => '撤去した切替 endpoint の route 名を「撤去済みである」と説明する 1 行であり、'
                    .'撤去の記録としてこの名前を書けないと、何を撤去したのかが文書から読めなくなる。',
            ],
            [
                'path' => 'doc/08_システムアーキテクチャ設計.md',
                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'count' => 1,
                'reason' => 'オブジェクトストレージのキー prefix の書式であり、画面の URL ではない。'
                    .'鍵は組織 id で始まる別の体系で、URL の組織セグメントとは無関係である。',
            ],
            [
                'path' => 'doc/09_詳細実装設計.md',
                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'count' => 1,
                'reason' => 'オブジェクトストレージに置くテイク動画の鍵の書式であり、画面の URL ではない。'
                    .'鍵は組織 id で始まる別の体系で、URL の組織セグメントとは無関係である。',
            ],
            [
                'path' => 'resources/js/types/dashboard.ts',
                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
                'count' => 3,
                'reason' => '課金 callout の CTA を持つ静的な表であり、画面から識別名を受け取れない。'
                    .'値は組織相対パスで、利用側 (Dashboard.svelte) が currentOrgUrl() で組織 URL へ写す。',
            ],
            [
                'path' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
                'count' => 3,
                'reason' => 'データセットが渡すのは組織 URL の**後ろに継ぐ suffix** であり、'
                    .'同じテストの中で "/organizations/{slug}" と連結してから要求している (単独の URL ではない)。',
            ],
            [
                'path' => 'tests/Unit/Services/Storage/FakeStorageKeyTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'count' => 1,
                'reason' => 'オブジェクトストレージの鍵 (保存先のパス) を組み立てる期待値であり、画面の URL ではないので組織セグメントを持たない',
            ],
        ];
    }

    /**
     * 許可の件数表 ("path\0rule" => count)。
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = [];
        foreach (self::entries() as $entry) {
            $counts[$entry['path']."\0".$entry['rule']] = $entry['count'];
        }

        return $counts;
    }
}
