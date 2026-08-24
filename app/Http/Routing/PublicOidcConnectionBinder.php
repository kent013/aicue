<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Models\OrganizationOidcConnection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;

/**
 * 公開の企業ログイン導線 (`/enterprise/{connection}/redirect`) の `{connection}` を解決する
 * explicit binder。
 *
 * ## 何を担うか
 *
 *  1. **入力の正規化** — 識別名の書式に合わない値は DB へ触れる前に落とす
 *     (pgsql へ長大な文字列や制御文字を渡さない)
 *  2. **解決はフレームワークの binding 規約に委ねる** —
 *     {@see OrganizationOidcConnection::resolveRouteBinding()} を通す。
 *     アプリ側で `where('login_slug', …)` を書かないので、
 *     「クラス起点の非主キー一意列によるモデル解決」を 1 件も増やさない
 *     (`ModelDirectFetchInvariantTest` の provenance 前提を崩さない)
 *  3. **応答の一様化** — **不在の識別名と、実在するが使えない接続 (Draft / Verified /
 *     Disabled) を同じ `ModelNotFoundException` に畳む**。
 *     分けると「429 / 404 になるまでの違い」が接続の実在オラクルになる。
 *     route 側の `missing()` がこれを受けて、利用者には**同じ**案内を返す
 *
 * ## 担わないもの
 *
 * 識別名は**全体で一意な公開の値**であり、推測されてよい。
 * 推測可能性に依存した防御はここに無い — 防御は接続の状態 (Active か) と、
 * state / PKCE / ブラウザ結合が担う。
 *
 * `{connection}` は {@see RouteBindingTypes::CUSTOM_BINDER} 分類である
 * (識別名は数値ではないので `Route::pattern` の型制約を掛けられない)。
 * {@see NormalizesRouteBindingInput} はその分類を型で宣言する marker である。
 */
final class PublicOidcConnectionBinder implements NormalizesRouteBindingInput
{
    /** DB の `login_slug` 列 (varchar 64) と対。 */
    private const int MAX_LENGTH = 64;

    /** 登録時の書式 (StoreSsoConnectionRequest と同じ形)。 */
    private const string SLUG_PATTERN = '/\A[a-z0-9][a-z0-9-]*[a-z0-9]\z/';

    /**
     * @throws ModelNotFoundException<OrganizationOidcConnection>
     */
    public function bind(mixed $value, ?Route $route = null): OrganizationOidcConnection
    {
        if (! is_string($value) || strlen($value) > self::MAX_LENGTH
            || preg_match(self::SLUG_PATTERN, $value) !== 1
        ) {
            throw $this->notFound();
        }

        $connection = (new OrganizationOidcConnection)->resolveRouteBinding($value, 'login_slug');

        // ★不在と「使えない状態」を**同じ例外**に畳む (実在オラクルを作らない)。
        if (! $connection instanceof OrganizationOidcConnection
            || $connection->status !== OidcConnectionStatus::Active
        ) {
            throw $this->notFound();
        }

        return $connection;
    }

    /** @return ModelNotFoundException<OrganizationOidcConnection> */
    private function notFound(): ModelNotFoundException
    {
        /** @var ModelNotFoundException<OrganizationOidcConnection> $exception */
        $exception = (new ModelNotFoundException)->setModel(OrganizationOidcConnection::class);

        return $exception;
    }
}
