<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Enums\Idempotency\IdempotencyState;
use App\Http\Middleware\IdempotentRequest;
use App\Services\Mcp\McpIdempotencyService;
use App\Support\Idempotency\IdempotencyHeaders;

/*
 * 冪等契約の drift 検出 (deny-by-default)。
 *
 * config (実装の SoT) ⇔ docs/api-idempotency.md (契約文書) ⇔ ヘッダ名定数 ⇔ 状態 enum
 * ⇔ ApiErrorCode の 409 系 の 5 者が同じことを言っていることを機械固定する。
 *
 * ★**保証しないこと**: 本 gate はマーカー区間の 5 行しか読まない。文書の散文部分
 *   (決着写像表・クライアント向け指針) が実装とずれても検出しない。
 *   散文は人間のレビュー対象である (誇張しない)。
 */

/** 契約文書の絶対パス */
function idempotencyContractDocPath(): string
{
    return base_path('docs/api-idempotency.md');
}

/**
 * マーカー区間 (`IDEMPOTENCY_CONTRACT:BEGIN` .. `:END`) を `key => value` にパースする。
 * マーカーが欠けていれば例外 (黙って空配列を返さない = 検査が空振りしない)。
 *
 * @return array<string, string>
 */
function idempotencyContractMarkers(): array
{
    $path = idempotencyContractDocPath();
    if (! is_file($path)) {
        throw new RuntimeException("契約文書が存在しません: {$path}");
    }

    $content = (string) file_get_contents($path);
    $matched = preg_match(
        '/<!-- IDEMPOTENCY_CONTRACT:BEGIN -->(.*?)<!-- IDEMPOTENCY_CONTRACT:END -->/s',
        $content,
        $matches,
    );
    if ($matched !== 1) {
        throw new RuntimeException('docs/api-idempotency.md に IDEMPOTENCY_CONTRACT マーカー区間がありません');
    }

    $parsed = [];
    foreach (preg_split('/\R/u', $matches[1]) ?: [] as $line) {
        if (preg_match('/^-\s*([a-z_]+):\s*(.+?)\s*$/', trim($line), $kv) === 1) {
            $parsed[$kv[1]] = $kv[2];
        }
    }

    return $parsed;
}

/**
 * カンマ区切りのマーカー値を集合 (ソート済み list) に変換する。
 *
 * @return list<string>
 */
function idempotencyContractSet(string $value): array
{
    $items = array_values(array_filter(array_map('trim', explode(',', $value))));
    sort($items);

    return $items;
}

test('契約文書が存在しマーカー区間を持つ', function (): void {
    expect(is_file(idempotencyContractDocPath()))->toBeTrue();

    // マーカーごと消す差分は例外で赤くなる (VERIFICATION_COMMANDS マーカーと同じ運用)
    $markers = idempotencyContractMarkers();

    expect(array_keys($markers))->toEqualCanonicalizing([
        'retention_hours', 'replay_header', 'states', 'terminal_states', 'conflict_codes',
    ]);
});

test('マーカー区間の retention_hours は config と一致する', function (): void {
    expect(idempotencyContractMarkers()['retention_hours'])
        ->toBe((string) config('idempotency.retention_hours'));
});

test('config/idempotency.php は env() を使わない', function (): void {
    // 保持期間は公開契約であり環境ごとに変えてよい運用値ではない
    $source = (string) file_get_contents(config_path('idempotency.php'));

    expect(str_contains($source, 'env('))->toBeFalse(
        'config/idempotency.php で env() を使わないこと (環境差があると契約が環境依存の嘘になる)。',
    );
});

test('retention_hours は 24 に pin されている', function (): void {
    // 値そのものの pin。この数値を動かす差分は必ず本テストにも現れる
    expect(config('idempotency.retention_hours'))->toBe(24);
});

test('マーカー区間の replay_header は IdempotencyHeaders::REPLAYED と一致する', function (): void {
    expect(idempotencyContractMarkers()['replay_header'])->toBe(IdempotencyHeaders::REPLAYED);
});

test('マーカー区間の states は IdempotencyState の全 case と一致する', function (): void {
    $documented = idempotencyContractSet(idempotencyContractMarkers()['states']);
    $actual = array_map(static fn (IdempotencyState $s): string => $s->value, IdempotencyState::cases());
    sort($actual);

    expect($documented)->toBe($actual);
});

test('マーカー区間の terminal_states は completed / indeterminate の 2 つだけ', function (): void {
    // release (再実行を許す) 経路を持たないという要件そのものの pin
    expect(idempotencyContractSet(idempotencyContractMarkers()['terminal_states']))
        ->toBe(['completed', 'indeterminate']);
});

test('保持期間のクラス定数が復活していない (二重管理への逆戻り検出)', function (): void {
    foreach ([IdempotentRequest::class, McpIdempotencyService::class] as $class) {
        expect((new ReflectionClass($class))->hasConstant('TTL_HOURS'))->toBeFalse(
            "{$class} に TTL_HOURS 定数が復活しています。保持期間の SoT は config/idempotency.php です。",
        );
    }
});

test('マーカー区間の conflict_codes は ApiErrorCode の 409 系 case と一致する', function (): void {
    $documented = idempotencyContractSet(idempotencyContractMarkers()['conflict_codes']);

    $actual = array_values(array_map(
        static fn (ApiErrorCode $c): string => $c->value,
        array_filter(ApiErrorCode::cases(), static fn (ApiErrorCode $c): bool => $c->defaultStatus() === 409),
    ));
    sort($actual);

    expect($documented)->toBe($actual,
        '409 のコードを足したら docs/api-idempotency.md のマーカー区間にも書いてください '
        .'(文書だけ増やす / コードだけ増やす の両方向を検出します)。');
});
