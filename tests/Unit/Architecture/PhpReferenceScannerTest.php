<?php

declare(strict_types=1);

use Tests\Support\PhpReferenceScanner;
use Tests\Support\ReceiverResolution;
use Tests\Support\ReferenceKind;
use Tests\Support\ReferenceSite;

/*
 * 中立走査器 `PhpReferenceScanner` の**名前解決**を合成ソースで固定する (T226)。
 *
 * ★負例 (わざと部分修飾で書いた参照を解決できること) と
 *   正例 (名前空間相対の同名クラスを外部クラスと取り違えないこと) の**両方向**を置く
 *   (`AGENTS.md` の共通規約 (c))。
 * ★受け手を静的に決められない静的呼び出しは `ReceiverResolution::Unresolved` として返る。
 *   **無言で候補から外さない**ことがこの走査器の契約である (同 (b))。
 *   利用側でそれがどう効くかは `ExternalSeamScannerTest` /
 *   `ExternalClientBoundaryScannerTest` が押さえている。
 * ★期待値は PHP 自身の名前解決規則と同じである (`namespace` ブロックごとの import 表の
 *   作り直し / `use` は宣言より前の参照に効かないこと、はいずれも php 8.4 で実測した)。
 */

/**
 * 名前参照 / 構築の site 名だけを取り出す。
 *
 * @param  list<ReferenceSite>  $sites
 * @return list<string>
 */
function referenceNames(array $sites): array
{
    return array_values(array_map(
        static fn (ReferenceSite $site): string => $site->name,
        array_filter(
            $sites,
            static fn (ReferenceSite $site): bool => $site->kind === ReferenceKind::NameReference
                || $site->kind === ReferenceKind::Construction,
        ),
    ));
}

/**
 * 静的呼び出しの site を「メソッド名 => 受け手の解決状態」で取り出す。
 *
 * @param  list<ReferenceSite>  $sites
 * @return list<array{name: string, resolution: string, receiver: string|null}>
 */
function staticCallReceivers(array $sites): array
{
    return array_values(array_map(
        static fn (ReferenceSite $site): array => [
            'name' => $site->name,
            'resolution' => $site->receiver->resolution->name,
            'receiver' => $site->receiver->isResolved() ? $site->receiver->fqcn() : null,
        ],
        array_filter($sites, static fn (ReferenceSite $site): bool => $site->kind === ReferenceKind::StaticCall),
    ));
}

// ── 部分修飾名の解決 (負例: 従来は解決できず見逃していた形) ─────────────

test('先頭要素を import した部分修飾名を完全修飾名まで解決する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades;
    final class Probe
    {
        public function go(): mixed
        {
            return Facades\Http::get('https://example.test');
        }
    }
    PHP;

    $result = PhpReferenceScanner::references('app/Services/Probe.php', $source);

    expect(referenceNames($result->sites))->toBe(['Illuminate\Support\Facades\Http'])
        ->and(staticCallReceivers($result->sites))->toBe([
            ['name' => 'get', 'resolution' => 'Resolved', 'receiver' => 'Illuminate\Support\Facades\Http'],
        ]);
});

test('別名で import した先頭要素の部分修飾名を解決する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades as F;
    final class Probe
    {
        public function go(): mixed
        {
            return new F\Http();
        }
    }
    PHP;

    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
        ->toBe(['Illuminate\Support\Facades\Http']);
});

test('グループ use で取り込んだ先頭要素に部分修飾名を続ける形を解決する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Aws\{S3, Sns};
    final class Probe
    {
        public function go(): mixed
        {
            return new S3\S3Client([]);
        }
    }
    PHP;

    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
        ->toBe(['Aws\S3\S3Client']);
});

test('import の無い部分修飾名は現在の名前空間の下に解決する (正例: 取り違えない)', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    final class Probe
    {
        public function go(): mixed
        {
            return new Aws\Bridge();
        }
    }
    PHP;

    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
        ->toBe(['App\Services\Aws\Bridge']);
});

test('名前空間を持たないファイルの部分修飾名はそのまま大域の名前になる', function (): void {
    $source = <<<'PHP'
    <?php
    $client = new Aws\Bridge();
    PHP;

    expect(referenceNames(PhpReferenceScanner::references('routes/web.php', $source)->sites))
        ->toBe(['Aws\Bridge']);
});

test('名前空間相対の名前 (namespace\Foo) を解決して site にする', function (): void {
    // 従来は `T_NAME_RELATIVE` を 1 件も emit していなかった = 無言の取りこぼし。
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    final class Probe
    {
        public function go(): mixed
        {
            return new namespace\Helper();
        }
    }
    PHP;

    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
        ->toBe(['App\Services\Helper']);
});

test('完全修飾名は先頭の区切りだけを落とす (従来どおり)', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    final class Probe
    {
        public function go(): mixed
        {
            return new \Aws\S3\S3Client([]);
        }
    }
    PHP;

    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
        ->toBe(['Aws\S3\S3Client']);
});

// ── import 表の作り方 ─────────────────────────────────────────────────

test('import 表は namespace ブロックごとに作り直す', function (): void {
    // php 8.4 実測: 前のブロックの `use ... as Sub;` は次のブロックの `Sub\Y` を解決しない。
    $source = <<<'PHP'
    <?php
    namespace First { use Aws\S3 as Sub; }
    namespace Second {
        final class Probe
        {
            public function go(): mixed
            {
                return new Sub\S3Client([]);
            }
        }
    }
    PHP;

    expect(referenceNames(PhpReferenceScanner::references('app/Probe.php', $source)->sites))
        ->toBe(['Second\Sub\S3Client']);
});

test('use function / use const は同名でもクラスの import 表を上書きしない', function (): void {
    // クラスの別名表へ関数名が入ると `S3\S3Client` が別名側で解決され、外部到達点を見逃す。
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Aws\S3;
    use function App\Support\s3 as S3;
    use const App\Support\S3 as S3;
    final class Probe
    {
        public function go(): mixed
        {
            return new S3\S3Client([]);
        }
    }
    PHP;

    $result = PhpReferenceScanner::references('app/Services/Probe.php', $source);

    expect($result->imports)->toBe(['s3' => 'Aws\S3'])
        ->and(referenceNames($result->sites))->toBe(['Aws\S3\S3Client']);
});

test('グループ use の内側の function / const も別名表へ入れない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Aws\{S3, function Support\s3 as Sns, const Support\SNS as Sns};
    final class Probe
    {
        public function go(): mixed
        {
            return new S3\S3Client([]);
        }
    }
    PHP;

    $result = PhpReferenceScanner::references('app/Services/Probe.php', $source);

    expect($result->imports)->toBe(['s3' => 'Aws\S3'])
        ->and(referenceNames($result->sites))->toBe(['Aws\S3\S3Client']);
});

test('グループ use は function / const 要素の**次**のクラス要素を取りこぼさない', function (): void {
    // ★要素ごとの種別フラグを区切りで戻し忘れると、typed 要素より後ろのクラス import が
    //   丸ごと落ちて部分修飾名を解決できなくなる (前の test だけでは検出できない向き)。
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Aws\{function Support\s3 as Helper, S3, const Support\SNS as Marker, Sns};
    final class Probe
    {
        public function go(): mixed
        {
            return [new S3\S3Client([]), new Sns\SnsClient([])];
        }
    }
    PHP;

    $result = PhpReferenceScanner::references('app/Services/Probe.php', $source);

    expect($result->imports)->toBe(['s3' => 'Aws\S3', 'sns' => 'Aws\Sns'])
        ->and(referenceNames($result->sites))->toBe(['Aws\S3\S3Client', 'Aws\Sns\SnsClient']);
});

test('import の無い短縮名でも new の直後なら現在の名前空間の下に解決する', function (): void {
    // ★ファイル自身の名前空間が対象と同じ場合の見逃しを塞ぐ
    //   (`namespace Stripe;` の中の `new StripeClient()` は `Stripe\StripeClient` である)。
    $source = <<<'PHP'
    <?php
    namespace Stripe;
    final class Probe
    {
        public function go(): mixed
        {
            return new StripeClient(['api_key' => 'sk_test']);
        }
    }
    PHP;

    expect(referenceNames(PhpReferenceScanner::references('app/Probe.php', $source)->sites))
        ->toBe(['Stripe\StripeClient']);
});

test('new self / new static は名前解決の対象にしない', function (): void {
    // `App\Services\self` のような実在しない FQCN を作らない (偽陽性の元になる)。
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    final class Probe
    {
        public function go(): self
        {
            return new self();
        }

        public function late(): static
        {
            return new static();
        }
    }
    PHP;

    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
        ->toBe([]);
});

test('クラス本体の use (trait 取り込み) は import 表を上書きしない', function (): void {
    // 上書きすると `billable => 'Billable'` になり、ファイル先頭の import が持つ FQCN を失う
    // (= trait 経由の参照が丸ごと消える fail-open)。
    $source = <<<'PHP'
    <?php
    namespace App\Models;
    use Laravel\Cashier\Billable;
    final class Organization
    {
        use Billable;

        public function go(): Billable
        {
            return $this;
        }
    }
    PHP;

    $result = PhpReferenceScanner::references('app/Models/Organization.php', $source);

    expect($result->imports)->toBe(['billable' => 'Laravel\Cashier\Billable'])
        ->and(referenceNames($result->sites))->toBe(['Laravel\Cashier\Billable']);
});

// ── 静的呼び出しの受け手 (fail-closed) ────────────────────────────────

test('受け手を静的に決められない静的呼び出しは未解決として返す', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    final class Probe extends Base
    {
        public function go(string $gateway): void
        {
            $gateway::make();
            static::make();
            parent::make();
        }
    }
    PHP;

    expect(staticCallReceivers(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))->toBe([
        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
    ]);
});

test('式の結果を受け手にした静的呼び出しも未解決として返す', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    final class Probe
    {
        public function go(): void
        {
            factory()::make();
            (new Registry())::make();
        }
    }
    PHP;

    expect(staticCallReceivers(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))->toBe([
        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
    ]);
});

test('self:: は囲みのクラスへ、import の無い短縮名は現在の名前空間の下へ解決する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    final class Probe
    {
        public function go(): void
        {
            self::make();
            Registry::make();
        }
    }
    PHP;

    expect(staticCallReceivers(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))->toBe([
        ['name' => 'make', 'resolution' => 'Resolved', 'receiver' => 'App\Services\Probe'],
        ['name' => 'make', 'resolution' => 'Resolved', 'receiver' => 'App\Services\Registry'],
    ]);
});

test('trait 本体の self:: は未解決にする (取り込んだクラスを指すため)', function (): void {
    // ★trait のコードは利用クラスへ展開されるので `self` は trait 自身ではない。
    //   複数のクラスが同じ trait を取り込めるため、走査時点では 1 つに決まらない。
    //   trait 自身の FQCN として解決すると、利用側は未解決として拾えず無言の見逃しになる。
    $source = <<<'PHP'
    <?php
    namespace App\Support;
    trait UsesGateway
    {
        public function run(): void
        {
            self::setHttpClient(null);
        }
    }
    final class Direct
    {
        public function run(): void
        {
            self::setHttpClient(null);
        }
    }
    PHP;

    expect(staticCallReceivers(PhpReferenceScanner::references('app/Support/UsesGateway.php', $source)->sites))->toBe([
        ['name' => 'setHttpClient', 'resolution' => 'Unresolved', 'receiver' => null],
        ['name' => 'setHttpClient', 'resolution' => 'Resolved', 'receiver' => 'App\Support\Direct'],
    ]);
});

test('受け手を持たない種別の receiver は Absent で、完全修飾名を取り出すと例外になる', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    final class Probe
    {
        public function go(object $client): mixed
        {
            return $client->send();
        }
    }
    PHP;

    $sites = PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites;
    $methodCalls = array_values(array_filter(
        $sites,
        static fn (ReferenceSite $site): bool => $site->kind === ReferenceKind::MethodCall,
    ));

    expect($methodCalls)->toHaveCount(1)
        ->and($methodCalls[0]->receiver->resolution)->toBe(ReceiverResolution::Absent)
        ->and(static fn (): string => $methodCalls[0]->receiver->fqcn())->toThrow(LogicException::class);
});
