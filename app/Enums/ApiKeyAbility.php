<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * API キーの ability (Q8 決定: flat ability)。
 *
 * 階層なし (strict semantics): write は read を含意しない。包含が必要なら
 * 発行時に両方を付与する。アプリ固有の動詞付き ability ('items:run' 型) は
 * ここに case を追加する (docs/app-integration-guide.md §5)。
 */
enum ApiKeyAbility: string
{
    case Read = 'read';
    case Write = 'write';

    public function label(): string
    {
        return match ($this) {
            self::Read => '読み取り',
            self::Write => '書き込み',
        };
    }
}
