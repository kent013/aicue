<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PasskeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * vendor モデル (Laravel\Passkeys\Passkey) の app サブクラス。
 *
 * 差し替える理由:
 *   1. Factory の置き場所 (AGENTS.md: テストデータは必ず Factory で生成 / 新規モデルは Factory 必須)
 *   2. アプリ側の型として route binding / DTO で扱えるようにする
 *
 * 差し替えは PasskeyServiceProvider::register() の Passkeys::usePasskeyModel() で行う。
 * credential 本体 (公開鍵 / signature counter) は vendor の cast (json) が扱う。
 *
 * カラムの型は vendor の Laravel\Passkeys\Passkey が class docblock で宣言しているが、
 * larastan の model property 解決は継承元の docblock を引き継がないため、
 * サブクラス側で明示する (vendor の宣言と一致させること)。
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $credential_id
 * @property array<string, mixed> $credential
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $authenticator
 *
 * @use HasFactory<PasskeyFactory>
 */
final class Passkey extends BasePasskey
{
    /** @use HasFactory<PasskeyFactory> */
    use HasFactory;

    /** vendor と同一テーブル (publish 済み migration の passkeys) */
    protected $table = 'passkeys';

    protected static function newFactory(): PasskeyFactory
    {
        return PasskeyFactory::new();
    }
}
