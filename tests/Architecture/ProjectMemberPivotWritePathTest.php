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
 *
 * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
 * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である。
 * 走査根 app/ の移動や token 判定の綻びで検出が 0 件になると、経路が増えても違反ゼロで緑になる。
 * 「空振り検査」ケースが (1) 走査した PHP ファイルの非空 (2) allowlist の各ファイルが
 * 実際に検出されていること を固定し、その直後の負のコントロールが
 * 「走査根を差し替えると検出が空になる」ことを示す。
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
     * @return array{project_members_literal: list<string>, members_relation_write: list<string>}
     */
    public static function allowlists(): array
    {
        return [
            'project_members_literal' => self::PROJECT_MEMBERS_LITERAL_ALLOWED,
            'members_relation_write' => self::MEMBERS_WRITE_ALLOWED,
        ];
    }

    /**
     * 走査根配下で検出したファイルを allowlist で絞らずに返す (空振り検査用)。
     *
     * @param  string|null  $rootDirectory  走査根の絶対パス (null = app/)
     * @return array{project_members_literal: list<string>, members_relation_write: list<string>}
     */
    public static function findDetections(?string $rootDirectory = null): array
    {
        $root = $rootDirectory ?? self::appDir();
        $detections = [
            'project_members_literal' => [],
            'members_relation_write' => [],
        ];

        foreach (self::phpFiles($root) as $path) {
            $relative = substr($path, strlen($root) + 1);
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException("Failed to read PHP source: {$path}");
            }

            if (self::containsProjectMembersLiteral($source)) {
                $detections['project_members_literal'][] = $relative;
            }
            if (self::containsMembersRelationWrite($source)) {
                $detections['members_relation_write'][] = $relative;
            }
        }

        return $detections;
    }

    /**
     * 走査した PHP ファイル (絶対パス)。走査根が実在しなければ空を返す。
     *
     * @return list<string>
     */
    public static function scannedFiles(?string $rootDirectory = null): array
    {
        return self::phpFiles($rootDirectory ?? self::appDir());
    }

    /**
     * 検出種別 => 違反ファイル (app/ 相対パス)。2 種別を必ず返す。
     *
     * @return array{project_members_literal: list<string>, members_relation_write: list<string>}
     */
    public static function findViolations(): array
    {
        $allowlists = self::allowlists();
        $detections = self::findDetections();

        $violations = [
            'project_members_literal' => array_values(array_diff(
                $detections['project_members_literal'],
                $allowlists['project_members_literal'],
            )),
            'members_relation_write' => array_values(array_diff(
                $detections['members_relation_write'],
                $allowlists['members_relation_write'],
            )),
        ];

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

    public static function appDir(): string
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
        if (! is_dir($dir)) {
            return [];
        }

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

test('空振り検査: 走査の母集団が空でなく、allowlist の各ファイルが実際に検出されている', function (): void {
    // (1) 走査根が生きていること
    $scanned = ProjectMemberPivotWriteScanner::scannedFiles();
    expect($scanned)->not->toBe([], '走査根 app/ に PHP ファイルがありません');
    expect(count($scanned))->toBeGreaterThanOrEqual(400); // 床値 (実測 827 件)

    // (2) 検出そのものが生きていること。allowlist は「検出されるが許す」ファイルなので、
    //     検出結果に現れないなら token 判定が壊れている (違反ゼロは検出停止でも成立する)。
    $detections = ProjectMemberPivotWriteScanner::findDetections();
    foreach (ProjectMemberPivotWriteScanner::allowlists() as $kind => $allowed) {
        foreach ($allowed as $relative) {
            // `toContain()` は可変長引数なので理由は第 2 引数に渡せない (渡すと検索語が増える)
            expect(in_array($relative, $detections[$kind], true))->toBeTrue(
                "検出 {$kind} が allowlist の {$relative} を拾えていません (走査が空振りしています)",
            );
        }
    }
});

test('負のコントロール: 走査根を差し替えると検出が空になる', function (): void {
    // 上の検査が空振りしていないことの裏取り。走査根の改名・移動を模して
    // 一致するもののないディレクトリ / 実在しないパスを渡すと検出が 0 件になる。
    expect(ProjectMemberPivotWriteScanner::findDetections(base_path('config')))->toBe([
        'project_members_literal' => [],
        'members_relation_write' => [],
    ]);
    expect(ProjectMemberPivotWriteScanner::scannedFiles(base_path('app-renamed')))->toBe([]);
});
