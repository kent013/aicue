<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Mcp\ToolName;
use App\Exceptions\Mcp\InvalidParamsException;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Values\Mcp\IdempotencyKey;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * MCP tool のテンプレートメソッド基底 (OAuth 対応)。
 *
 * 認証は HTTP 層の `auth:mcp-oauth` (Passport Bearer) が担い、認可
 * ({@see ToolName::requiredPermission()} 経由の runtime 再評価)、冪等性
 * (書き込み系のみ)、構造化ログを base で処理する。
 * 子クラスは `toolName()` と `runTool()` のみ実装する。
 *
 * handle() シグネチャは laravel/mcp の container->call 経由で以下が自動注入される:
 * - `\Laravel\Mcp\Request`    : MCP JSON-RPC arguments
 * - `\Illuminate\Http\Request`: HTTP Request (認可 context 取得用、Passport token 情報)
 */
abstract class AppMcpTool extends Tool
{
    public function __construct(
        protected readonly McpIdempotencyService $idempotency,
    ) {}

    public function shouldRegister(HttpRequest $httpRequest): bool
    {
        // guard 解決は `McpAuthorizationContext::resolveAuthenticatedUser` に一本化する。
        // Authorization ヘッダがある場合は `mcp-oauth` guard を必ず優先し、
        // default guard (session user) にはフォールバックしない。
        if (McpAuthorizationContext::resolveAuthenticatedUser($httpRequest) === null) {
            return false;
        }

        try {
            $ctx = McpAuthorizationContext::for($httpRequest);
        } catch (Throwable) {
            return false;
        }

        return $ctx->authorizeTool($this->toolName());
    }

    final public function handle(McpRequest $mcpRequest, HttpRequest $httpRequest): Response
    {
        $ctx = McpAuthorizationContext::for($httpRequest);

        if (! $ctx->authorizeTool($this->toolName())) {
            throw new AuthorizationException('Not permitted for this tool.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $mcpRequest->all();

        $idempotencyKey = null;
        if ($this->toolName()->isWriteTool()) {
            $idempotencyKey = $this->extractIdempotencyKey($mcpRequest);

            $replay = $this->idempotency->replay(
                organizationId: $ctx->organization->id,
                userId: $ctx->user->id,
                toolName: $this->toolName()->value,
                key: $idempotencyKey,
                payload: $payload,
            );
            if ($replay !== null) {
                $this->logInvocation($ctx, durationMs: 0, success: true, replay: true);

                return $this->toResponse($replay);
            }
        }

        $start = hrtime(true);
        try {
            $responsePayload = $this->runTool($mcpRequest, $ctx);
        } catch (Throwable $e) {
            $this->logInvocation(
                $ctx,
                durationMs: self::durationMs($start),
                success: false,
                errorCode: $e::class,
            );
            throw $e;
        }

        if ($idempotencyKey instanceof IdempotencyKey) {
            $this->idempotency->store(
                organizationId: $ctx->organization->id,
                userId: $ctx->user->id,
                toolName: $this->toolName()->value,
                key: $idempotencyKey,
                payload: $payload,
                response: $responsePayload,
            );
        }

        $this->logInvocation($ctx, durationMs: self::durationMs($start), success: true);

        return $this->toResponse($responsePayload);
    }

    abstract protected function toolName(): ToolName;

    /**
     * MCP server が tools/call で lookup する名前。`ToolName` enum の canonical 値
     * (e.g. `whoami`, `list-projects`) を返す。
     *
     * デフォルトの Primitive::name() は `Str::kebab(class_basename($this))` を返すため
     * `WhoamiTool` → `whoami-tool` になってしまい、tools/call との lookup が一致しない。
     * 本 override で enum canonical value に揃える。
     *
     * `#[\Override]` を付与し、laravel/mcp の Primitive::name() API が変化した際に
     * 早期に検知できるようにする。
     */
    #[\Override]
    public function name(): string
    {
        return $this->toolName()->value;
    }

    /**
     * 各子 tool の業務ロジック。返却は `array<string, mixed>` の正規化済 payload。
     * Response 化とログは base 側で行う。
     *
     * @return array<string, mixed>
     */
    abstract protected function runTool(McpRequest $request, McpAuthorizationContext $ctx): array;

    /** @param array<string, mixed> $payload */
    protected function toResponse(array $payload): Response
    {
        return Response::json($payload);
    }

    private function extractIdempotencyKey(McpRequest $request): IdempotencyKey
    {
        /** @var mixed $raw */
        $raw = $request->get('idempotency_key');
        if (! is_string($raw) || $raw === '') {
            throw new InvalidParamsException('idempotency_key is required for write tools.');
        }

        return new IdempotencyKey($raw);
    }

    /**
     * 必須 int パラメータを strict 型で取得する。
     * 不正型 / 範囲外は JSON-RPC -32602 InvalidParamsException。
     *
     * `filter_var(FILTER_VALIDATE_INT)` を使うことで:
     * - `1.5` / `1e5` / `"1 "` は reject (truncation 事故防止)
     * - `true` / `false` は reject (bool 暗黙変換しない)
     * - 整数文字列 `"123"` / int `123` は受理
     */
    protected function requireIntParam(
        McpRequest $request,
        string $name,
        ?int $min = null,
        ?int $max = null,
    ): int {
        /** @var mixed $raw */
        $raw = $request->get($name);
        if ($raw === null) {
            throw new InvalidParamsException("{$name} is required.");
        }
        if (is_bool($raw)) {
            throw new InvalidParamsException("{$name} must be an integer.");
        }
        $int = filter_var($raw, FILTER_VALIDATE_INT);
        if ($int === false) {
            throw new InvalidParamsException("{$name} must be an integer.");
        }
        if ($min !== null && $int < $min) {
            throw new InvalidParamsException("{$name} must be >= {$min}.");
        }
        if ($max !== null && $int > $max) {
            throw new InvalidParamsException("{$name} must be <= {$max}.");
        }

        return $int;
    }

    /**
     * オプションの int パラメータ。未指定は default、指定あれば strict 検証。
     */
    protected function optionalIntParam(
        McpRequest $request,
        string $name,
        int $default,
        ?int $min = null,
        ?int $max = null,
    ): int {
        /** @var mixed $raw */
        $raw = $request->get($name);
        if ($raw === null) {
            return $default;
        }

        return $this->requireIntParam($request, $name, $min, $max);
    }

    /**
     * List 系 tool 共通の page / per_page 抽出。
     *
     * page max=1000 で重い OFFSET 経由の DoS を抑制する (per_page 100 と組み合わせた
     * 防御的上限)。
     *
     * @return array{int, int} [page, perPage]
     */
    protected function paginationParams(McpRequest $request): array
    {
        $page = $this->optionalIntParam($request, 'page', default: 1, min: 1, max: 1000);
        $perPage = $this->optionalIntParam($request, 'per_page', default: 20, min: 1, max: 100);

        return [$page, $perPage];
    }

    /**
     * List 系 tool 共通のレスポンス整形。
     *
     * 戻り値構造:
     * `[$resourceKey => list<array<string, mixed>>, 'pagination' => array{page, per_page, total}]`
     *
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $paginator
     * @param  Closure(TItem): array<string, mixed>  $mapper  paginator->items() の各要素を array に変換
     * @return array<string, mixed>
     */
    protected function paginatedResponse(
        LengthAwarePaginator $paginator,
        string $resourceKey,
        int $page,
        int $perPage,
        Closure $mapper,
    ): array {
        $items = [];
        foreach ($paginator->items() as $item) {
            $items[] = $mapper($item);
        }

        return [
            $resourceKey => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $paginator->total(),
            ],
        ];
    }

    private static function durationMs(int $startHrTime): int
    {
        return (int) ((hrtime(true) - $startHrTime) / 1_000_000);
    }

    private function logInvocation(
        McpAuthorizationContext $ctx,
        int $durationMs,
        bool $success,
        bool $replay = false,
        ?string $errorCode = null,
    ): void {
        Log::info('mcp.tool.invoked', [
            'user_id' => $ctx->user->id,
            'organization_id' => $ctx->organization->id,
            'access_token_id' => $ctx->accessTokenId,
            'oauth_client_id' => $ctx->oauthClientId,
            'tool_name' => $this->toolName()->value,
            'duration_ms' => $durationMs,
            'success' => $success,
            'replay' => $replay,
            'error_code' => $errorCode,
        ]);
    }
}
