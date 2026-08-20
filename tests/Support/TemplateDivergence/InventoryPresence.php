<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 採用時債務一覧のパスの在り方 (3 値)。
 *
 * ★**2 つの真偽値 (「残っているか」「regular file か」) にすると矛盾した組み合わせを
 *   作れてしまう** — 「存在しないが regular file である」は実在状態として不可能なのに、
 *   引数としては渡せてしまう。共通規約 (b) の「解決できない形を落とす」に反するので、
 *   **型として矛盾を作れない形**にした。
 *
 * 掃除の判定では **symlink も残置**である (`NonRegularFile` に入る)。
 * 壊れた symlink も残置なので `Absent` ではない。
 */
enum InventoryPresence
{
    /** パスがどんな形でも存在しない (掃除済みの状態)。 */
    case Absent;

    /** 通常ファイルとして存在する (債務が残っている間の正しい状態)。 */
    case RegularFile;

    /** 存在はするが通常ファイルでない (symlink / 壊れた symlink / ディレクトリ等)。 */
    case NonRegularFile;

    /**
     * ファイルシステムの状態から写す (写像を 1 か所に閉じる)。
     *
     * `file_exists()` は壊れた symlink に false を返すので `is_link()` を or で足す。
     */
    public static function fromPath(string $path): self
    {
        if (is_link($path)) {
            return self::NonRegularFile;
        }
        if (! file_exists($path)) {
            return self::Absent;
        }

        return is_file($path) ? self::RegularFile : self::NonRegularFile;
    }

    /** パスが何らかの形で残っているか。 */
    public function exists(): bool
    {
        return $this !== self::Absent;
    }
}
