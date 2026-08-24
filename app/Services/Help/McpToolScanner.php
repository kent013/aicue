<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Mcp\Tools\AppMcpTool;
use ReflectionClass;
use RuntimeException;

/**
 * `app/Mcp/Tools/` を走査して MCP ツールの具象クラスを列挙する。
 *
 * ★**走査根は 1 本** (`app/Mcp/Tools/` の直下)。git 追跡下 PHP 全数より狭いので
 *   `Tests\Support\TrackedPhpSourceFiles` は共用しない。**存在しない根は fail-fast** で落とす。
 * ★**基底クラスは `App\Mcp\Tools\AppMcpTool`** — 正典 (裁定 AG-100) が
 *   「移植時に各リポジトリの基底クラスへ差し替える 1 行」と名指しした箇所である。
 * ★**deny-by-default**: 走査根の直下に置いた具象クラスは、基底を継承していなければ**例外で止まる**。
 *   「走査対象から外す」口を持たない (外したければファイルを別の場所へ移すしかない)。
 * ★**母集団の非空は本走査器の契約である**。0 件は「違反 0 件」ではなく走査の破損なので例外にする。
 * ★**保証しないもの**:
 *   - 下位ディレクトリは見ない (`app/Mcp/Tools/` は現に平坦であり、階層を作る予定も無い)。
 *   - 1 ファイルに複数のクラスを書いた場合、ファイル名と同名のクラスしか見ない。
 *   - サーバへの登録有無は見ない。走査集合と登録集合の一致は
 *     `tests/Architecture/McpToolReferencePopulationTest.php` の担当である。
 *   - git 未追跡 (add 前) のファイルも走査する (gate の境界は commit / CI なので実効差は無い)。
 *   - 実体の検査は POSIX 前提である (`is_link()` / `realpath()`)。
 *   - **検査と読み込みの間の差し替え (TOCTOU) は防げない** (`HelpRepository` と同じ理由)。
 *     保証するのは静止状態で**起点から走査根までの経路**と各ファイルが symlink でないこと
 *     までである (走査根が canonical path であることを毎回検査する)。
 */
final class McpToolScanner
{
    private const string NAMESPACE_PREFIX = 'App\\Mcp\\Tools\\';

    /** @param non-empty-string $root `app/Mcp/Tools/` の絶対パス */
    public function __construct(private readonly string $root) {}

    /**
     * @return list<class-string<AppMcpTool>> クラス名の昇順
     *
     * @throws RuntimeException 走査根が無い / クラスを解決できない / 基底を継承しない / 母集団が空
     */
    public function concreteToolClasses(): array
    {
        if (! is_dir($this->root)) {
            throw new RuntimeException(
                "MCP ツールの走査根が存在しません: {$this->root} — ".
                'ディレクトリを移動・改名したなら McpToolScanner の配線を同じ変更で直すこと。',
            );
        }

        // ★走査根が canonical path であることを要求する。`is_dir()` / `scandir()` / autoload /
        //   Reflection の `realpath()` はすべて symlink を辿るので、経路のどこか 1 要素でも
        //   symlink だと外部のディレクトリを走査しながら「実体の一致」検査を通過してしまう
        //   (走査根が固定の first-party パスである前提が崩れる)。最終要素だけの `is_link()`
        //   では `app/Mcp` が symlink という形を見逃す。
        //   作業ツリー全体が symlink の先にある形は拒まない — 配線側が信頼する起点を
        //   `realpath()` で正規化してから組み立てるためである。
        $real = realpath($this->root);
        if ($real === false || $real !== $this->root) {
            throw new RuntimeException(
                "MCP ツールの走査根が canonical path ではありません: {$this->root} ".
                '(解決先: '.var_export($real, true).') — 起点から走査根までの経路に symlink がある。'.
                '信頼する起点を realpath で正規化してから組み立てること。',
            );
        }

        $entries = scandir($this->root);
        if ($entries === false) {
            throw new RuntimeException("MCP ツールの走査根を走査できません: {$this->root}");
        }

        $classes = [];

        foreach ($entries as $entry) {
            if (! str_ends_with($entry, '.php')) {
                continue;
            }

            $absolute = $this->root.'/'.$entry;
            if (! is_file($absolute) || is_link($absolute)) {
                throw new RuntimeException("MCP ツールの実体が通常ファイルではありません: {$absolute}");
            }

            $class = self::NAMESPACE_PREFIX.substr($entry, 0, -4);

            if (! class_exists($class)) {
                throw new RuntimeException(
                    "MCP ツールのクラスを解決できません: {$class} ({$absolute}) — ".
                    'ファイル名とクラス名 / namespace が一致しているか確認すること。',
                );
            }

            $reflection = new ReflectionClass($class);

            // ★走査したファイルと、autoload が解決したクラスの実体が同じであることを要求する。
            //   `class_exists()` は Composer autoload から**別のファイル**をロードしうるので、
            //   これを見ないと「一時 root の見本を走査しているつもりで本物を見ている」
            //   状態に気付けず、負例が空振りする (検出力の主張が崩れる)。
            $declaredIn = $reflection->getFileName();
            $declaredReal = $declaredIn === false ? false : realpath($declaredIn);
            $scannedReal = realpath($absolute);

            if ($declaredReal === false || $scannedReal === false || $declaredReal !== $scannedReal) {
                throw new RuntimeException(
                    "{$class} の実体が走査中のファイルと一致しません ".
                    '(走査: '.$absolute.' / 解決: '.var_export($declaredIn, true).') — '.
                    'ファイル名とクラス名 / namespace が一致しているか、'.
                    '同名クラスが別の場所から autoload されていないか確認すること。',
                );
            }

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! $reflection->isSubclassOf(AppMcpTool::class)) {
                throw new RuntimeException(
                    "{$class} は ".AppMcpTool::class.' を継承していません — '.
                    'app/Mcp/Tools/ 直下には MCP ツールだけを置くこと '.
                    '(補助クラスは別の namespace へ移すこと)。',
                );
            }

            /** @var class-string<AppMcpTool> $class */
            $classes[] = $class;
        }

        if ($classes === []) {
            throw new RuntimeException(
                "MCP ツールが 1 件も見つかりません: {$this->root} — ".
                '母集団が空なのは「違反 0 件」ではなく走査の破損である。',
            );
        }

        sort($classes, SORT_STRING);

        return $classes;
    }
}
