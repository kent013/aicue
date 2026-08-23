<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

/**
 * 保存可能型 (`AssignableOrganizationSlug`) を**意図的に迂回する** slug 書き込みの目録。
 *
 * ★形式は **パス + 検出器が発行する rule ID + 件数 + 30 文字以上の理由**である。
 * ★**禁止している書き込み構文そのものを目録へ複写しない**。構文を文字列で持つと、
 *   その目録自身が書き込み検出器に拾われる (自己再帰)。ここが持つのは
 *   「どの検出規則の、どのファイルの、何件を許すか」だけである。
 * ★件数は**完全一致**である (増えても減っても赤)。
 * ★**ファイル全体を走査から外さない** — 登録した rule ID 以外の違反は、
 *   その 2 ファイルの中でも引き続き検出する。
 * ★登録できるのは `database/migrations` と `tests` だけである (`app/` には置けない)。
 */
final class OrganizationSlugWriteExemptions
{
    /**
     * パス => [rule ID => 件数, '__reason' => 理由]。
     *
     * @return array<string, array{rules: array<string, int>, reason: string}>
     */
    public static function all(): array
    {
        return [
            'database/migrations/2026_08_23_000100_constrain_organization_slug.php' => [
                'rules' => ['raw-sql-update' => 1],
                'reason' => '値オブジェクト導入**前**の既存行を正規化する一度きりの処理。'
                    .'型を通せる対象 (保存可能型で作られた値) がまだ存在しないため、'
                    .'ここだけは生 SQL で小文字化する。CHECK 制約はこの後に張る。',
            ],
            'tests/Feature/Organization/OrganizationSlugConstraintTest.php' => [
                'rules' => ['query-builder-update' => 1, 'force-fill' => 1],
                'reason' => 'CHECK 制約と一意制約が実際に効くことを確かめる負例。値オブジェクトを迂回して'
                    .'不正な値を書き込むことが検査の目的そのものであり、迂回しないと DB 側の制約を撃てない。',
            ],
            'tests/Architecture/OrganizationSlugWritePathTest.php' => [
                'rules' => ['raw-sql-update' => 1],
                'reason' => '検出器が生 SQL の UPDATE を拾えることを確かめる合成入力。検出したい構文を'
                    .'文字列として持つのが役目であり、これが無いと検出力の裏取りができない。',
            ],
            'tests/Unit/Services/Onboarding/SnippetBuilderTest.php' => [
                'rules' => ['force-fill' => 1],
                'reason' => '保存しない組み立て済みインスタンスを作るだけの単体テスト用の見本であり、'
                    .'organizations 表への書き込みは 1 度も起きない (DB に触れない層の検査)。',
            ],
        ];
    }
}
