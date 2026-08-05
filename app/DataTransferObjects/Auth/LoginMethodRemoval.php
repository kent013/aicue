<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

use App\Enums\Auth\LoginMethodRemovalKind;
use App\Models\Passkey;
use App\Models\User;
use Webmozart\Assert\Assert;

/**
 * 「今から何を除去しようとしているか」を表す閉じた variant。
 *
 * private constructor + 名前付き static factory で **不正状態を生成できない**ようにする
 * (provider 空文字、他人の passkey、種別と payload の不整合をコンストラクタで排除する)。
 *
 * 生成点は EnsureLoginMethodRemains::removalFor() と、不変条件検査 (テスト) のみ。
 */
final class LoginMethodRemoval
{
    private function __construct(
        public readonly LoginMethodRemovalKind $kind,
        public readonly ?Passkey $passkey = null,
        public readonly ?string $provider = null,
    ) {}

    /** 除去しない (現在状態の照会) */
    public static function none(): self
    {
        return new self(LoginMethodRemovalKind::None);
    }

    /** password の除去 (将来の password 削除 route 用) */
    public static function password(): self
    {
        return new self(LoginMethodRemovalKind::Password);
    }

    /** SSO 連携 1 件の解除 (将来の連携解除 route 用) */
    public static function social(string $provider): self
    {
        Assert::stringNotEmpty($provider);

        return new self(LoginMethodRemovalKind::Social, provider: $provider);
    }

    /**
     * passkey 1 件の削除。
     *
     * $passkey は **binder が対象 User に属することを 404 で確定させた後**に渡すこと
     * (App\Http\Routing\SelfScopedPasskeyBinder)。二重防御として所有を assert する
     * (fail-closed。他人の passkey を投影対象にすると「他人の credential を消せば
     * 自分の手段が残る」という誤判定になりうる)。
     */
    public static function passkey(Passkey $passkey, User $owner): self
    {
        $ownerKey = $owner->getKey();
        Assert::scalar($ownerKey);   // bigint PK。string 比較に落とすため型を確定させる

        Assert::true(
            (string) $passkey->user_id === (string) $ownerKey,
            'LoginMethodRemoval::passkey は対象 User 所有の passkey のみ受け付ける',
        );

        return new self(LoginMethodRemovalKind::Passkey, passkey: $passkey);
    }

    /** 全 passkey を除外した集合の評価用 (不変条件検査) */
    public static function allPasskeys(): self
    {
        return new self(LoginMethodRemovalKind::AllPasskeys);
    }
}
