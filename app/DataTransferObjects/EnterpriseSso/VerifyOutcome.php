<?php

declare(strict_types=1);

namespace App\DataTransferObjects\EnterpriseSso;

/**
 * `verify` の結果 (4 値)。
 *
 * ★**画面へは一様に出さない** — これは運営の操作の結果なので、
 *   「材料が変わったのでやり直してください」と**具体的に伝える**。
 *   存在を隠す必要があるのは未認証の経路であって、認可を通った運営操作ではない。
 */
enum VerifyOutcome: string
{
    /** Draft → Verified へ進んだ。 */
    case Verified = 'verified';

    /**
     * 同じ材料を別の要求が既に Verified にしていた。
     *
     * revision が一致している = 検証したのと同じ材料なので、これは競合ではなく**重複**である。
     * 遷移表に Verified → Verified を足さない代わりに「遷移しない成功」として扱う。
     */
    case AlreadyVerified = 'already_verified';

    /** 外向き取得の間に認証材料が変わった。結果は採用しない (Draft のまま)。 */
    case StaleCredentials = 'stale_credentials';

    /** 取得の間に接続が消えた (または組織の外へ出た)。 */
    case ConnectionGone = 'connection_gone';

    public function succeeded(): bool
    {
        return $this === self::Verified || $this === self::AlreadyVerified;
    }

    public function message(): string
    {
        return match ($this) {
            self::Verified => '接続先情報を確認しました。「有効化」を押すとログインに使えるようになります。',
            self::AlreadyVerified => 'この接続は既に確認済みです。',
            self::StaleCredentials => '確認中に接続の設定が変更されたため、結果を破棄しました。'
                .'もう一度「確認」を押してください。',
            self::ConnectionGone => '確認中にこの接続が削除されました。',
        };
    }
}
