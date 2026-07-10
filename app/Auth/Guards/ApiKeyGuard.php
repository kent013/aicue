<?php

declare(strict_types=1);

namespace App\Auth\Guards;

use App\Models\ApiKey;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Webmozart\Assert\Assert;

/**
 * 組織スコープ API キー (Bearer "{slug}_{prefix8}_{secret40}") の認証 Guard。
 *
 * 認証主体は User ではなく ApiKey モデル自体 (Authenticatable 実装)。
 * `Gate::forUser($apiKey)` で scope-based 認可が機能する。
 *
 * 認証成功時は下流互換のため request attributes に api_key / organization を注入する
 * (ResolvesApiOrganization / RequireApiKeyAbility / IdempotentRequest / rate limiter が参照)。
 *
 * 検証手順:
 * 1. Bearer token を "{slug}_{prefix8}_{secret40}" の regex で形式検証
 * 2. 非機密 prefix ("{slug}_{prefix8}") で active な候補を indexed lookup
 * 3. secret を Argon2id (password_verify) で照合
 *
 * 不正形式 / 候補 0 件 / secret 不一致のいずれの経路でも固定ダミー hash で必ず
 * 1 回 password_verify を走らせ、有効経路との timing 差を縮小する。
 */
final class ApiKeyGuard implements Guard
{
    use GuardHelpers;

    /**
     * timing attack 緩和用の固定ダミー Argon2id hash。
     *
     * password_verify() は算出時間が比較的一定だが、無効 key 経路でも必ず 1 回
     * verify を走らせることで有効 key 経路との timing 差をさらに縮小する。
     * Argon2id 形式として妥当なこと (`password_get_info()['algo'] === PASSWORD_ARGON2ID`)
     * を ApiKeyGuardTest で固定する。
     */
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0MTIzNDU2$+0Hhrp+KFrvXgJOKSftGbWQtGeaVp94FA3bJNy1l7Xw';

    /**
     * `last_used_at` 更新の間引き間隔 (分)。
     *
     * 認証ヒット毎に UPDATE を走らせると高頻度アクセス時に write hot spot になるため、
     * 最後の更新から下記分数経っていない場合は UPDATE をスキップする
     * (タイムスタンプ精度は分単位で十分: アクティブ判定 / 監査用途のみ)。
     */
    private const LAST_USED_UPDATE_THRESHOLD_MINUTES = 5;

    public function __construct(private Request $request) {}

    /**
     * リクエスト差し替え時に認証状態をリセットする
     * (AuthManager がリクエスト毎に guard インスタンスをキャッシュするため、
     * AppServiceProvider の登録時に $app->refresh('request', ...) で配線する)。
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
        $this->user = null;
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->request->bearerToken();
        if (! is_string($token) || preg_match($this->bearerRegex(), $token, $matches) !== 1) {
            // 不正形式でも timing 差を縮小するため固定ダミー hash で 1 回 verify を実行
            password_verify(is_string($token) ? $token : '', self::DUMMY_HASH);

            return null;
        }

        $prefix = $matches[1];
        $secret = $matches[2];

        /** @var list<ApiKey> $candidates */
        $candidates = ApiKey::query()
            ->active()
            ->where('key_prefix', $prefix)
            ->get()
            ->all();

        if ($candidates === []) {
            // 候補 0 件でもダミー hash で timing を消費
            password_verify($secret, self::DUMMY_HASH);

            return null;
        }

        foreach ($candidates as $candidate) {
            if (password_verify($secret, $candidate->key_hash)) {
                $this->touchLastUsedAtIfStale($candidate);

                // 下流 (controller / middleware / rate limiter) 互換の attributes 注入
                $this->request->attributes->set('api_key', $candidate);
                $this->request->attributes->set('organization', $candidate->organization);

                $this->user = $candidate;

                return $candidate;
            }
        }

        // 候補はあったが secret 不一致の経路でも固定ダミー hash で timing 差を縮小
        password_verify($secret, self::DUMMY_HASH);

        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return $this->user() !== null;
    }

    /**
     * Bearer token 形式 "{slug}_{prefix8}_{secret40}" の regex
     * (capture 1 = "{slug}_{prefix8}" の key_prefix、capture 2 = secret)。
     * Str::random は [A-Za-z0-9] のみを生成する。
     */
    private function bearerRegex(): string
    {
        $slug = config('template.slug');
        Assert::stringNotEmpty($slug);

        return '/^('.preg_quote($slug, '/').'_[A-Za-z0-9]{'.ApiKey::PREFIX_RANDOM_LENGTH.'})'
            .'_([A-Za-z0-9]{'.ApiKey::SECRET_RANDOM_LENGTH.'})$/';
    }

    /**
     * `last_used_at` を間引き更新する。
     *
     * 直近 LAST_USED_UPDATE_THRESHOLD_MINUTES 分以内に更新済なら UPDATE をスキップし、
     * 高頻度アクセス時の write 負荷を抑制する (未使用 = null なら常に記録する)。
     */
    private function touchLastUsedAtIfStale(ApiKey $key): void
    {
        $now = Carbon::now();
        $lastUsed = $key->last_used_at;

        if ($lastUsed !== null
            && $lastUsed->diffInMinutes($now, absolute: true) < self::LAST_USED_UPDATE_THRESHOLD_MINUTES) {
            return;
        }

        // 監査用 best-effort 更新 (イベント発火不要のため quietly)
        $key->forceFill(['last_used_at' => $now])->saveQuietly();
    }
}
