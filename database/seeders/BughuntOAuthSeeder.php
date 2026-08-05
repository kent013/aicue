<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OAuth\OAuthClientKind;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Webmozart\Assert\Assert;

/**
 * bug-hunt env 専用: CLI OAuth client / CLI session / legacy MCP token を seed する。
 *
 * 目的 (検証カバレッジ拡充):
 *  - public CLI OAuth client を 1 件作り `/api/v1/version` が `cli_oauth_client_id` を
 *    advertise できるようにする (= CLI の `login --no-browser` の client-id 解決)。
 *  - 代表 user に active な CLI session + legacy MCP token を付与し
 *    セッション revoke 導線 (sessions.destroy / legacy.destroy) を踏めるようにする。
 *
 * 三重 fail-secure: (1) `config('testing.fake_externals') === true`、(2) `app()->environment('bughunt.local')`、
 * (3) 接続先 DB 名が `^bug_hunt(_[1-8])?$` の全成立時のみ実行。いずれか欠ければ no-op
 * (production/dev DB で誤実行しても認証状態をばら撒かない fail-secure)。
 *
 * ★ 有効化条件: 本 seeder は OAuth 基盤 (Passport + oauth_sessions / CliOAuthScope / cli:client command) を
 *   前提にする。外部 fake 基盤 (config('testing.fake_externals')) が未導入のテンプレートでは
 *   第 1 ガードで常に no-op になり、provision から呼ばれても副作用を持たない (安全な同梱)。
 *
 * 冪等 = 「探索前提の active 状態を毎回回復する」。revoke 後の reseed でも active を再保証する。
 */
class BughuntOAuthSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;

    /** legacy MCP token の決定論 id (冪等キー)。char(80) PK に収まる固定値。 */
    private const string LEGACY_MCP_TOKEN_ID_PREFIX = 'bughunt-legacy-mcp-token';

    /** CLI session の決定論 UUID (冪等キー)。 */
    private const string CLI_SESSION_ID = '00000000-0000-4000-8000-000000000001';

    /** CLI session 配下 access token の決定論 id prefix。 */
    private const string CLI_ACCESS_TOKEN_ID_PREFIX = 'bughunt-cli-access-token';

    public function run(): void
    {
        // fail-secure 三軸: fake_externals かつ bughunt.local かつ DB 名 bug_hunt* の全成立時のみ。
        if (
            config('testing.fake_externals') !== true
            || ! app()->environment('bughunt.local')
            || ! $this->isBughuntDatabase()
        ) {
            $this->command->warn('BughuntOAuthSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');

            return;
        }

        // 代表 user: current organization を持つ最初のユーザー (ManualTestSeeder 投入前提)。
        // アプリのテストアカウント email 規則に依存しないよう関係で解決する。
        $user = $this->resolveRepresentativeUser();
        if (! $user instanceof User) {
            $this->command->warn('BughuntOAuthSeeder: current organization を持つ user が無いため skip。先に ManualTestSeeder を流すこと。');

            return;
        }

        $org = $user->currentOrganization;
        if (! $org instanceof Organization) {
            $this->command->warn('BughuntOAuthSeeder: 代表 user に current organization が無いため skip。');

            return;
        }

        $cliClientId = $this->seedCliClient();
        $this->seedCliSession($user, $org, $cliClientId);
        $this->seedLegacyMcpToken($user, $org);

        $this->command->info('BughuntOAuthSeeder: seeded CLI OAuth client + CLI session + legacy MCP token.');
    }

    /**
     * current organization を持つ代表ユーザーを 1 件解決する (email 規則非依存)。
     */
    private function resolveRepresentativeUser(): ?User
    {
        return User::query()
            ->whereNotNull('current_organization_id')
            ->orderBy('id')
            ->first();
    }

    /** @param list<string> $scopes */
    private function encodeScopes(array $scopes): string
    {
        return json_encode($scopes, JSON_THROW_ON_ERROR);
    }

    /**
     * public CLI OAuth client を 1 件保証する (= `cli:client` command が SoT)。
     *
     * public CLI client (`client_kind='cli' AND revoked=false AND secret IS NULL`) の件数で 3 分岐:
     *  - 0 件: `cli:client` を実行して作成
     *  - 1 件: 再利用
     *  - 2+ 件: 副作用なしで即 fail (= /version が null fallback する壊れた状態。bug-hunt seed は
     *    決定論前提のため自己修復より fail-fast で運用者に気づかせる。client を増やさない)
     */
    private function seedCliClient(): string
    {
        $ids = $this->publicCliClientIds();
        $count = count($ids);

        Assert::false($count >= 2, "public CLI client が複数 ({$count}) 存在し /version が null fallback する。bug-hunt DB を再 provision すること。");

        if ($count === 1) {
            $id = $ids[0];
            Assert::stringNotEmpty($id);

            return $id;
        }

        // 0 件 → command 実行で作成。
        $appName = is_string(config('app.name')) ? (string) config('app.name') : 'App';
        Artisan::call('cli:client', ['--name' => $appName.' CLI (bughunt)']);

        $afterIds = $this->publicCliClientIds();
        Assert::count($afterIds, 1, 'cli:client 実行後に public CLI client が一意にならなかった');
        Assert::stringNotEmpty($afterIds[0]);

        return $afterIds[0];
    }

    /**
     * public CLI client の id 一覧 (最大 2 件)。VersionInfoService の CLI client 解決と同一述語。
     *
     * @return list<string>
     */
    private function publicCliClientIds(): array
    {
        $rows = DB::table('oauth_clients')
            ->where('client_kind', OAuthClientKind::Cli->value)
            ->where('revoked', false)
            ->whereNull('secret')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->all();

        return array_values(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            $rows,
        ));
    }

    /**
     * active な CLI session + 配下 access token を保証する (revoke 後も active 回復)。
     * 既存 session 行が canonical 値と不一致なら delete → 再作成 (壊れた行の温存防止)。
     */
    private function seedCliSession(User $user, Organization $org, string $cliClientId): void
    {
        $existing = OauthSession::query()->find(self::CLI_SESSION_ID);

        $canonical = $existing instanceof OauthSession
            && $existing->user_id === $user->id
            && $existing->organization_id === $org->id
            && $existing->client_id === $cliClientId
            && $existing->client_kind === OAuthClientKind::Cli->value;

        if ($existing instanceof OauthSession && ! $canonical) {
            // 壊れた既存行 (別 user/org/client) は温存せず作り直す。
            $existing->delete();
            $existing = null;
        }

        if ($existing instanceof OauthSession) {
            // revoke 後の reseed で active 状態を回復する (冪等 = active 再保証)。
            if ($existing->revoked_at !== null) {
                $existing->forceFill(['revoked_at' => null])->save();
            }
        } else {
            OauthSession::createForClient($user, $org, $cliClientId, OAuthClientKind::Cli, self::CLI_SESSION_ID);
        }

        // 配下 access token を active で upsert。
        DB::table('oauth_access_tokens')->updateOrInsert(
            ['id' => $this->cliAccessTokenId()],
            [
                'user_id' => $user->id,
                'client_id' => $cliClientId,
                'organization_id' => $org->id,
                'session_id' => self::CLI_SESSION_ID,
                'name' => null,
                'scopes' => $this->encodeScopes(['cli:use', 'read']),
                'revoked' => false,
                'created_at' => now(),
                'updated_at' => now(),
                'expires_at' => now()->addYearNoOverflow(),
            ],
        );
    }

    /**
     * active な legacy MCP token (session_id NULL, client_kind='mcp') を保証する。
     */
    private function seedLegacyMcpToken(User $user, Organization $org): void
    {
        $mcpClientId = $this->ensureMcpClientId();

        DB::table('oauth_access_tokens')->updateOrInsert(
            ['id' => $this->legacyMcpTokenId()],
            [
                'user_id' => $user->id,
                'client_id' => $mcpClientId,
                'organization_id' => $org->id,
                'session_id' => null,
                'name' => null,
                'scopes' => $this->encodeScopes(['mcp:use']),
                'revoked' => false,
                'created_at' => now(),
                'updated_at' => now(),
                'expires_at' => now()->addYearNoOverflow(),
            ],
        );
    }

    /**
     * legacy MCP token の client を保証して id を返す。
     * MCP client (client_kind='mcp') が無ければ 1 件作り、既存があれば最古を再利用する。
     */
    private function ensureMcpClientId(): string
    {
        $existing = DB::table('oauth_clients')
            ->where('client_kind', OAuthClientKind::Mcp->value)
            ->orderBy('id')
            ->value('id');

        if (is_scalar($existing) && (string) $existing !== '') {
            return (string) $existing;
        }

        $client = Client::factory()->create();
        $clientId = $client->getKey();
        Assert::stringNotEmpty($clientId);
        DB::table('oauth_clients')->where('id', $clientId)->update(['client_kind' => OAuthClientKind::Mcp->value]);

        return $clientId;
    }

    /** char(80) PK に収まる決定論 access token id (CLI session 配下)。長さを保証。 */
    private function cliAccessTokenId(): string
    {
        $id = str_pad(self::CLI_ACCESS_TOKEN_ID_PREFIX, 80, '0');
        Assert::length($id, 80);

        return $id;
    }

    /** char(80) PK に収まる決定論 access token id (legacy MCP token)。長さを保証。 */
    private function legacyMcpTokenId(): string
    {
        $id = str_pad(self::LEGACY_MCP_TOKEN_ID_PREFIX, 80, '0');
        Assert::length($id, 80);

        return $id;
    }
}
