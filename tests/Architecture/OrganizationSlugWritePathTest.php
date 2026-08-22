<?php

declare(strict_types=1);

use Tests\Support\Architecture\OrganizationSlugWriteExemptions;
use Tests\Support\Architecture\OrganizationSlugWriteScanner;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * `organizations.slug` を書ける経路は保存可能型を受ける 1 本だけ (家系裁定 AG-039 / I8)。
 *
 * 識別名の規則 (文字種・長さ・予約語) は値オブジェクト 1 本に集約してある。生文字列や
 * 構文型 (`OrganizationSlug`) をそのまま保存できる道が 1 本でも残ると、その道からは
 * 規則を通らない値が入る。
 *
 * ## 走査根と判定
 *
 * git 追跡下の PHP 全数 (`TrackedPhpSourceFiles`)。`OrganizationSlugWriteScanner` が
 * 書き込み site を rule ID 付きで全数抽出し、次のいずれでもない site を違反とする:
 *
 *  1. そのファイルが保存可能型 (`AssignableOrganizationSlug`) を参照している (= 型を通す経路)
 *  2. `OrganizationSlugWriteExemptions` に **パス + rule ID + 件数**で登録がある
 *
 * 目録の件数は完全一致 (増えても減っても赤)。**ファイル全体を外さない** —
 * 登録した rule ID 以外の違反は同じファイルの中でも検出する。
 *
 * ## 保証しないもの (誇張しない)
 *
 * 検出器の docblock に書いた**保証範囲の外**の構文 (変数に組み立てた配列を渡す形・
 * 動的キー・動的メソッド名・実行時に連結した SQL) には**無言で効かない**。
 * 本 gate の主張はその構文を除いた範囲に狭めてある。
 */

test('走査根が空でない', function (): void {
    expect(TrackedPhpSourceFiles::all(base_path()))->not->toBeEmpty();
});

test('slug 書き込みの母集団が空でない (検出器が壊れたら赤にする)', function (): void {
    expect(OrganizationSlugWriteScanner::sites(TrackedPhpSourceFiles::all(base_path())))->not->toBeEmpty();
});

test('保存可能型を通らない slug 書き込みは目録に登録されたものだけ (件数まで完全一致)', function (): void {
    $files = TrackedPhpSourceFiles::all(base_path());
    $sources = [];
    foreach ($files as $file) {
        $sources[$file['relative']] = (string) file_get_contents($file['absolute']);
    }

    $exemptions = OrganizationSlugWriteExemptions::all();
    $violations = [];
    /** @var array<string, array<string, int>> $observed */
    $observed = [];

    foreach (OrganizationSlugWriteScanner::sites($files) as $site) {
        $path = $site['path'];
        if (OrganizationSlugWriteScanner::sourceUsesAssignableType($sources[$path])) {
            continue; // 保存可能型を通す経路
        }

        if (! array_key_exists($path, $exemptions)
            || ! array_key_exists($site['rule'], $exemptions[$path]['rules'])) {
            $violations[] = "{$path}:{$site['line']} ({$site['rule']})";

            continue;
        }
        $observed[$path][$site['rule']] = ($observed[$path][$site['rule']] ?? 0) + 1;
    }

    expect($violations)->toBe([]);

    // 件数の完全一致 (増えても減っても赤)
    $expected = [];
    foreach ($exemptions as $path => $entry) {
        $expected[$path] = $entry['rules'];
    }
    ksort($observed);
    ksort($expected);
    expect($observed)->toBe($expected);
});

test('目録に置けるのは database/migrations と tests だけ / 理由は 30 文字以上', function (): void {
    $badPath = [];
    $shortReason = [];
    foreach (OrganizationSlugWriteExemptions::all() as $path => $entry) {
        if (! str_starts_with($path, 'database/migrations/') && ! str_starts_with($path, 'tests/')) {
            $badPath[] = $path;
        }
        if (mb_strlen($entry['reason']) < 30) {
            $shortReason[] = $path;
        }
    }

    expect($badPath)->toBe([]);
    expect($shortReason)->toBe([]);
});

test('負例: 構文型・生文字列を直接保存する合成入力を検出できる', function (): void {
    $forceFill = <<<'PHP'
        <?php
        $organization->forceFill(['slug' => $syntaxOnly->value])->save();
        PHP;
    expect(OrganizationSlugWriteScanner::sitesInSource($forceFill))
        ->toBe([['rule' => 'force-fill', 'line' => 2]]);

    $massAssignment = "<?php\n\$o = new Organization(['name' => \$n, 'slug' => 'acme']);\n";
    expect(array_column(OrganizationSlugWriteScanner::sitesInSource($massAssignment), 'rule'))
        ->toBe(['mass-assignment']);

    $builderUpdate = "<?php\nDB::table('organizations')->whereKey(1)->update(['slug' => 'Acme']);\n";
    expect(array_column(OrganizationSlugWriteScanner::sitesInSource($builderUpdate), 'rule'))
        ->toBe(['query-builder-update']);

    $rawSql = "<?php\nDB::statement('UPDATE organizations SET slug = lower(slug)');\n";
    expect(array_column(OrganizationSlugWriteScanner::sitesInSource($rawSql), 'rule'))
        ->toBe(['raw-sql-update']);

    $factory = "<?php\nclass F { public function definition(): array { return ['slug' => 'acme']; } }\n";
    expect(array_column(OrganizationSlugWriteScanner::sitesInSource($factory, isFactory: true), 'rule'))
        ->toBe(['factory-definition']);
});

test('負例の裏返し: 読み出し (画面 props) を書き込みと誤検出しない', function (): void {
    $props = <<<'PHP'
        <?php
        return Inertia::render('Organizations/Settings', [
            'organization' => ['id' => $organization->id, 'slug' => $organization->slug],
        ]);
        PHP;
    expect(OrganizationSlugWriteScanner::sitesInSource($props))->toBe([]);

    // Factory 以外の `return [...]` も書き込みではない
    expect(OrganizationSlugWriteScanner::sitesInSource("<?php\nreturn ['slug' => \$o->slug];\n"))->toBe([]);
});
