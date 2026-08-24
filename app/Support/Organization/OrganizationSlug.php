<?php

declare(strict_types=1);

namespace App\Support\Organization;

use App\Exceptions\Organization\InvalidOrganizationSlugException;
use Illuminate\Support\Str;

/**
 * 組織識別名の **構文** を表す不変の値オブジェクト (家系裁定 AG-039b / AG-039c)。
 *
 * ★不変条件は「構文的に妥当で正規化済み」だけである。**保存してよいことは意味しない** —
 *   予約語でないことは AssignableOrganizationSlug が担う (裁定 AG-039)。
 * ★正規化は **大文字を小文字へ倒すことだけ**である。前後の空白除去・記号の除去・連結は
 *   一切しない (矯正すると、利用者が入れた値と保存される値が黙って食い違う)。
 * ★長さの上限は organizations.slug 列 (varchar 255) に由来する。下限は「空でないこと」だけで、
 *   正典にも列にも根拠の無い最小長は設けない。
 */
final readonly class OrganizationSlug
{
    /** organizations.slug 列 (varchar 255) に由来する上限。 */
    public const int MAX_LENGTH = 255;

    /**
     * 小文字英数字とハイフン。先頭末尾はハイフン以外、連続ハイフンなし。
     * ★`^`/`$` ではなく `\A`/`\z` を使う — `$` は末尾の改行 1 文字を許すため、
     *   "acme\n" が通ってしまう。
     */
    public const string PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    private function __construct(public string $value) {}

    /**
     * 文字列から構文型を作る **唯一の検査点**。
     * 利用者入力も、組織名からの導出結果も、必ずここを通る。
     */
    public static function fromString(string $input): self
    {
        // 前後の空白は「不正な入力」として拒否する (黙って落とさない)
        $normalized = Str::lower($input);

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidOrganizationSlugException::tooLong($input);
        }
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw InvalidOrganizationSlugException::malformed($input);
        }

        return new self($normalized);
    }

    /**
     * 組織名から識別名を導出する。導出できなければ null を返す
     * (日本語名は Str::slug が空を返す)。**代替の識別名を決めるのは Service の責務**であり、
     * 値オブジェクトが 'org' のような値を捏造しない。
     */
    public static function deriveFromName(string $name): ?self
    {
        $candidate = trim(mb_substr(Str::slug($name), 0, self::MAX_LENGTH), '-');
        if ($candidate === '') {
            return null;
        }

        // ★切り詰め後の候補も必ず同じ検査点を通す (private constructor を直接呼ばない)
        try {
            return self::fromString($candidate);
        } catch (InvalidOrganizationSlugException) {
            return null;
        }
    }
}
