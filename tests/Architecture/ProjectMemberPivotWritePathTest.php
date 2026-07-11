<?php

declare(strict_types=1);

/*
 * project_members pivot の書き込み経路 inventory (deny-by-default。
 * ScenarioWritePathInventoryTest と同型の token ベース静的走査)。
 *
 * ロール遷移コマンド (applyConsoleRole) / 招待受諾 (joinOrganization) / removeMember の
 * pivot 掃除は OrganizationMembershipService に、プロジェクト個別のメンバー操作は
 * ProjectMemberController に閉じる。経路が増えると「org ロールと pivot の整合を 1 tx で
 * 保証する」契約 (概念設計 D2) が崩れるため、新規経路はここへの登録 + 設計判断を必須とする。
 *
 * 検出 A: 文字列リテラル 'project_members' の出現 (DB::table 直書き経路の deny)
 * 検出 B: `members()->attach|detach|sync|syncWithoutDetaching|toggle` の呼び出し形
 * いずれも allowlist 外の app/ コードに現れたら fail。
 */

final class ProjectMemberPivotWriteScanner
{
    /**
     * 検出 A の allowlist (app/ 相対パス)。
     * - Models/Project.php: belongsToMany の pivot テーブル名宣言 (書き込みではない)
     * - OrganizationMembershipService: detachProjectMemberships の素 delete (org relation 限定)
     */
    private const PROJECT_MEMBERS_LITERAL_ALLOWED = [
        'Models/Project.php',
        'Services/Organization/OrganizationMembershipService.php',
    ];

    /** 検出 B の allowlist (app/ 相対パス) */
    private const MEMBERS_WRITE_ALLOWED = [
        'Http/Controllers/Projects/ProjectMemberController.php',
        'Services/Organization/OrganizationMembershipService.php',
    ];

    /**
     * @return array<string, list<string>> 検出種別 => 違反ファイル (app/ 相対パス)
     */
    public static function findViolations(): array
    {
        $appDir = self::appDir();
        $violations = [
            'project_members_literal' => [],
            'members_relation_write' => [],
        ];

        foreach (self::phpFiles($appDir) as $path) {
            $relative = substr($path, strlen($appDir) + 1);
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException("Failed to read PHP source: {$path}");
            }

            if (self::containsProjectMembersLiteral($source)
                && ! in_array($relative, self::PROJECT_MEMBERS_LITERAL_ALLOWED, true)) {
                $violations['project_members_literal'][] = $relative;
            }
            if (self::containsMembersRelationWrite($source)
                && ! in_array($relative, self::MEMBERS_WRITE_ALLOWED, true)) {
                $violations['members_relation_write'][] = $relative;
            }
        }

        return $violations;
    }

    /** 検出 A: 文字列リテラル 'project_members' (コメント・docblock 内は無視) */
    private static function containsProjectMembersLiteral(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING
                && str_contains($token[1], 'project_members')) {
                return true;
            }
        }

        return false;
    }

    /** 検出 B: `members()->attach|detach|sync*|toggle` の呼び出し形 (token 列で判定) */
    private static function containsMembersRelationWrite(string $source): bool
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $writeMethods = ['attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle'];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'members') {
                continue;
            }
            // members ( ) -> {writeMethod} の並びを探す (間の空白/コメントはスキップ)
            $j = self::nextMeaningful($tokens, $i + 1);
            if ($j === null || $tokens[$j] !== '(') {
                continue;
            }
            $j = self::nextMeaningful($tokens, $j + 1);
            if ($j === null || $tokens[$j] !== ')') {
                continue;
            }
            $j = self::nextMeaningful($tokens, $j + 1);
            if ($j === null || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $j = self::nextMeaningful($tokens, $j + 1);
            if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING
                && in_array(strtolower($tokens[$j][1]), $writeMethods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private static function appDir(): string
    {
        $dir = realpath(__DIR__.'/../../app');
        if ($dir === false) {
            throw new RuntimeException('app directory not found');
        }

        return $dir;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}

test('project_members への書き込みは inventory (OrganizationMembershipService / ProjectMemberController) の外に現れない', function (): void {
    $violations = ProjectMemberPivotWriteScanner::findViolations();

    expect($violations['project_members_literal'])->toBe([]);
    expect($violations['members_relation_write'])->toBe([]);
});
