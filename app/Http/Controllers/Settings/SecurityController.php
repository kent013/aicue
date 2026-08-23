<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\DataTransferObjects\Auth\PasskeyListItemDto;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\PasskeyListItemResource;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\PasskeyLoginPolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * セキュリティ設定画面 (GET /settings/security)。
 *
 * 2FA / ソーシャル連携 / パスキーの管理面。route closure から抽出したのは
 * passkey 一覧の組み立てで DI (PasskeyLoginPolicy) が必要になり、
 * closure に積み増すと「Controller は薄く」の作法から外れるため。
 *
 * ★メールアドレスの昇格 (T253 / E1) の導線もここが供給する。
 *   **メールを持たない利用者だけ**に出す — 既にある人の変更は
 *   監査と旧アドレスへの通知を持つプロフィール更新の経路が担う。
 */
final class SecurityController extends Controller
{
    public function __construct(
        private readonly PasskeyLoginPolicy $passkeyLoginPolicy,
    ) {}

    public function __invoke(Request $request): InertiaResponse
    {
        $user = $request->user();
        // admin guard 併用のため user() は User|AdminUser の union。narrowing する
        $isUser = $user instanceof User;

        return Inertia::render('Settings/Security', [
            'socialProviders' => array_keys(config()->array('template.social_providers')),
            'linkedProviders' => $isUser ? $user->socialAccounts()->pluck('provider')->all() : [],
            'passkeys' => $isUser ? $this->passkeyList($request, $user) : [],
            // TOTP 有効ユーザーには「ログインには使えないが再認証には使える」旨を出すための判別子。
            // 判定は PasskeyLoginPolicy に集約 (login 認可 / inventory と同一条件)。
            'passkeyLoginAvailable' => $isUser && $this->passkeyLoginPolicy->allowsPasskeyLogin($user),
            // ★メールアドレスの昇格 (T253 / E1) の導線を出すかどうか。
            //   企業 SSO でしか入れない利用者は使えるメールを 1 件も持たないので、
            //   **メールが無いときだけ**この面を出す (既にある人は既存の変更経路を使う)。
            'canPromoteEmail' => $isUser && $user->email === null,
        ]);
    }

    /**
     * Inertia prop 用の passkey 一覧。
     *
     * `Resource::collection()` にせず **DTO を Resource で包んで resolve() した plain array**
     * を渡す (PHPStan と Inertia の prop 解決の双方で安定するため)。
     *
     * @return list<array<string, mixed>>
     */
    private function passkeyList(Request $request, User $user): array
    {
        $items = [];

        // App\Models\Passkey 型で扱うため relation ではなくモデルを user_id スコープで引く
        // (PasskeyUser interface の宣言により relation は vendor 型で解決されるため。
        //  User モデルの該当コメント参照)。
        $passkeys = Passkey::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($passkeys as $passkey) {
            $items[] = PasskeyListItemResource::make(
                new PasskeyListItemDto(
                    id: $passkey->id,
                    name: $passkey->name,
                    // vendor が @property-read string|null $authenticator を宣言している
                    // (AAGUID から解決。不明なら null)
                    authenticator: $passkey->authenticator,
                    lastUsedAt: $passkey->last_used_at?->toIso8601String(),
                    createdAt: $passkey->created_at?->toIso8601String(),
                ),
            )->toArray($request);
        }

        return $items;
    }
}
