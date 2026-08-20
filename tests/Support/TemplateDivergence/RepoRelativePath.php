<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 指紋台帳のキーと登録簿の対象パスに使える「リポジトリ相対の単一ファイルパス」の判定 (純関数)。
 *
 * 正典 (laravel-claude-template) は同じ判定を `SharedPathRules::isValidRepoRelativePath()` に
 * 持つが、`SharedPathRules` は**提供元の `git ls-files` を分類する規則表**であり、
 * 本リポジトリは母集合の出典を正典が公開する指紋台帳のキーに置いたため分類規則そのものを
 * 使わない。規則表ごと持ち込むと使われない資産になるので、書式判定だけを本クラスへ切り出した
 * (この差は `docs/template-divergence.md` D33 に登録済み)。
 *
 * **判定できない形は false を返す** (呼び出し側が違反にする)。黙って候補から外さない
 * (静的検査の共通規約 (b))。次の 8 形を明示的に落とす:
 *  1. 空文字 / 2. 絶対パス (`/` 始まり) / 3. 要素が空 (`a//b`) /
 *  4. `.` を要素に含む / 5. `..` を要素に含む / 6. NUL を含む /
 *  7. 末尾が `/` (ディレクトリ表記) / 8. 制御文字を含む
 *
 * **保証しないもの**: 実在・追跡状態・regular file かどうかは見ない (書式だけを見る)。
 * 実在と種別は利用側 (突合 gate の F7 / F13) が git index と `is_file` / `is_link` で判定する。
 * Windows 形式の区切り (`\`) やドライブ表記も「単なる 1 文字」として扱う
 * (本リポジトリの追跡パスは POSIX 区切りだけである)。
 */
final class RepoRelativePath
{
    /** インスタンス化しない (純関数のみ)。 */
    private function __construct() {}

    public static function isValid(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/')) {
            return false;
        }

        // NUL は制御文字の集合に含まれるが、切り詰め攻撃の入口なので独立して落とす
        if (str_contains($path, "\0") || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
