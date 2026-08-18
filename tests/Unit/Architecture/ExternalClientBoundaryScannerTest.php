<?php

declare(strict_types=1);

use Tests\Support\ExternalClientBoundaryScanner;
use Tests\Support\ScanScopeKind;

/*
 * 到達境界 scanner の**走査精度**を fixture 文字列で固定する (T126 施策 5)。
 *
 * ★目録 gate 本体 (ExternalClientTimeoutInventoryTest) は「実リポジトリの母集団」を見るため、
 *   走査ロジックの偽陽性 / 偽陰性そのものは検証できない。ここは純関数への fixture 入力で
 *   規則ごとの検出精度を固定する。DB は触らない。
 */

/**
 * @param  list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>  $sites
 * @return list<array{rule: string, name: string, class: string|null, scope: string}>
 */
function scannerSummary(array $sites): array
{
    return array_map(
        static fn (array $site): array => [
            'rule' => $site['rule'],
            'name' => $site['name'],
            'class' => $site['class'],
            'scope' => $site['scopeKind']->name,
        ],
        $sites,
    );
}

test('use ... as ... の alias を解決する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Aws\S3\S3Client as Bucket;
    class Sample { public function f(): Bucket { return $this->x; } }
    PHP;

    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
        ['rule' => 'imported_name_reference', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
    ]);
});

test('完全修飾名と import 済み short name の両方を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Aws\Sns\SnsClient;
    class Sample { public function f(): void { $a = \Aws\S3\S3Client::class; $b = SnsClient::class; } }
    PHP;

    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
        ['rule' => 'fqn_reference', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
        ['rule' => 'imported_name_reference', 'name' => 'Aws\Sns\SnsClient', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
    ]);
});

test('nullable / union / intersection の型宣言を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Illuminate\Contracts\Filesystem\Filesystem;
    use Aws\S3\S3Client;
    class Sample {
        private ?Filesystem $a = null;
        public function f(Filesystem|string $b, S3Client&\Countable $c): Filesystem|null { return $this->a; }
    }
    PHP;

    $names = array_column(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source), 'name');

    expect($names)->toBe([
        'Illuminate\Contracts\Filesystem\Filesystem', // property
        'Illuminate\Contracts\Filesystem\Filesystem', // union 引数
        'Aws\S3\S3Client',                            // intersection 引数
        'Illuminate\Contracts\Filesystem\Filesystem', // nullable 戻り値
    ]);
});

test('constructor property promotion と attribute の型を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Illuminate\Container\Attributes\Storage;
    use Aws\S3\S3Client;
    class Sample {
        public function __construct(
            #[Storage('s3')] private readonly S3Client $client,
        ) {}
    }
    PHP;

    $names = array_column(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source), 'name');

    expect($names)->toBe([
        'Illuminate\Container\Attributes\Storage',
        'Aws\S3\S3Client',
    ]);
});

test('匿名クラス内の site は AnonymousClass 帰属として外側クラスへ誤帰属しない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Aws\S3\S3Client;
    class Outer {
        public function f(): object {
            return new class { public function g(): S3Client { return $this->c; } };
        }
    }
    PHP;

    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Outer.php', $source)))->toBe([
        ['rule' => 'imported_name_reference', 'name' => 'Aws\S3\S3Client', 'class' => null, 'scope' => 'AnonymousClass'],
    ]);
});

test('コメント / 文字列リテラル中の Aws\\ を検出しない', function (): void {
    // 偽陽性の負のコントロール。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    /** Aws\S3\S3Client のことを説明する DocComment */
    class Sample {
        // Aws\Sns\SnsClient を将来使うかもしれない
        public function f(): string { return 'Aws\S3\S3Client'; }
    }
    PHP;

    expect(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source))->toBe([]);
});

test('use Aws\\… だけがあり参照 site が無いファイルは母集団に入らない', function (): void {
    // R1 (import) は alias マップ構築専用であり、それ自体では site にならない。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Aws\S3\S3Client;
    class Sample { public function f(): int { return 1; } }
    PHP;

    expect(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source))->toBe([]);
});

test('1 ファイルに複数の名前付きクラスがあるとき site は実際の scope のクラスへ帰属する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Aws\S3\S3Client;
    class First { public function f(): int { return 1; } }
    class Second { public function g(): S3Client { return $this->c; } }
    PHP;

    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Multi.php', $source)))->toBe([
        ['rule' => 'imported_name_reference', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Second', 'scope' => 'NamedClass'],
    ]);
});

test('文字列補間を含むメソッドの後でも scope 追跡が壊れない', function (): void {
    // `"{$x}"` の閉じ `}` は単一文字トークンとして現れるため、開き側 (T_CURLY_OPEN) を
    // depth に数えないと以降の site が FileScope へ落ちる (実測で発覚した回帰)。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Illuminate\Support\Facades\Storage;
    class Sample {
        public function f(string $key): string { return "prefix {$key} suffix"; }
        public function g(): void { Storage::disk('s3')->delete('x'); }
    }
    PHP;

    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
        ['rule' => 'imported_name_reference', 'name' => 'Illuminate\Support\Facades\Storage', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
        ['rule' => 'disk_call', 'name' => 'disk', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
    ]);
});

test('disk() の引数が変数なら dynamic として分類される', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Illuminate\Support\Facades\Storage;
    class Sample {
        public function f(string $name): void { Storage::disk($name)->delete('x'); }
        public function g(): void { Storage::disk('s3')->delete('x'); }
        public function h(): void { Storage::disk(self::DISK)->delete('x'); }
        public function i(): void { $this->disk()->delete('x'); }
    }
    PHP;

    $arguments = array_values(array_map(
        static fn (array $site): ?string => $site['diskArgument'],
        array_filter(
            ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source),
            static fn (array $site): bool => $site['rule'] === 'disk_call',
        ),
    ));

    expect($arguments)->toBe(['dynamic', 'static', 'static', 'none']);
});

test('getClient() は到達境界の参照が無いファイルでは母集団に入らない', function (): void {
    // 同名の無関係な API (OAuth の AuthCodeEntity::getClient() 等) を拾わないための条件。
    $unrelated = <<<'PHP'
    <?php
    namespace App\Gate;
    class Sample { public function f(object $entity): object { return $entity->getClient(); } }
    PHP;

    expect(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $unrelated))->toBe([]);

    $related = <<<'PHP'
    <?php
    namespace App\Gate;
    use Illuminate\Support\Facades\Storage;
    class Sample { public function f(): object { return Storage::disk('s3')->getClient(); } }
    PHP;

    expect(array_column(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $related), 'rule'))
        ->toBe(['imported_name_reference', 'disk_call', 'get_client_call']);
});

test('new による構築点は new_external_object として参照と区別される', function (): void {
    // 免除理由「構築点」と「DI で受け取るだけの消費点」を機械で分けるための種別。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Aws\Sns\SnsClient;
    class Sample {
        public function build(): SnsClient { return new SnsClient([]); }
        public function consume(SnsClient $client): void {}
        public function fqn(): object { return new \Aws\S3\S3Client([]); }
    }
    PHP;

    expect(array_map(
        static fn (array $site): array => [$site['rule'], $site['name']],
        ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source),
    ))->toBe([
        ['imported_name_reference', 'Aws\Sns\SnsClient'],  // 戻り値の型宣言
        ['new_external_object', 'Aws\Sns\SnsClient'],      // 構築点
        ['imported_name_reference', 'Aws\Sns\SnsClient'],  // 引数の型宣言 (消費点)
        ['new_external_object', 'Aws\S3\S3Client'],        // 完全修飾の構築点
    ]);
});

test('Stripe の大域 setter は Stripe 名前空間の receiver に限って検出される', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Stripe\ApiRequestor;
    use Stripe\Stripe;
    use Stripe\HttpClient\CurlClient;
    class Sample {
        public function f(): void {
            ApiRequestor::setHttpClient(new CurlClient);
            Stripe::setMaxNetworkRetries(0);
            CurlClient::instance();
            \App\Other\Registry::instance();
        }
    }
    PHP;

    expect(array_column(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/Sample.php', $source), 'name'))
        ->toBe(['setHttpClient', 'setMaxNetworkRetries', 'instance']);
});

test('グループ use を接頭辞ごと解決する', function (): void {
    // ★T138 の走査基盤抽出 (PhpReferenceScanner) で group use の接頭辞連結を直した際の回帰。
    //   抽出前は `use Aws\{S3\S3Client, ...}` の区切り `\` を落として
    //   `AwsS3\S3Client` と解決していた (= 検出漏れ)。app/ に group use が無いため
    //   T126 の母集団は変わらないが、docblock が謳う仕様との差を残さない。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Aws\{S3\S3Client, Sns\SnsClient};
    class Sample { public function f(SnsClient $s): S3Client { return new S3Client([]); } }
    PHP;

    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
        ['rule' => 'imported_name_reference', 'name' => 'Aws\Sns\SnsClient', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
        ['rule' => 'imported_name_reference', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
        ['rule' => 'new_external_object', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
    ]);
});

test('先頭要素を import した部分修飾名 (S3\S3Client) を解決して検出する', function (): void {
    // T226: 部分修飾名を解決しなかった頃は `S3\S3Client` のまま照合され、
    // 到達境界の接頭辞 `Aws\` に一致せず**無言で見逃されていた**。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    use Aws\S3;
    class Sample { public function f(): void { $client = new S3\S3Client([]); } }
    PHP;

    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
        ['rule' => 'new_external_object', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
    ]);
});

test('名前空間相対の部分修飾名を到達境界と取り違えない', function (): void {
    // 先頭要素の import が無い部分修飾名は**現在の名前空間の下**に解決される。
    // 解決しなかった頃は字面 `Aws\Bridge` が接頭辞 `Aws\` に一致し、
    // 自前クラスを到達境界として**誤検出**していた。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    class Sample { public function f(): void { $bridge = new Aws\Bridge(); } }
    PHP;

    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([]);
});

test('受け手を静的に決められない大域 setter は fail-closed で検出する', function (): void {
    // 受け手が変数 / 遅延静的束縛の静的呼び出しは FQCN を確定できない。
    // **未解決を黙って候補から外さない** (規約 (b))。変数経由に書き換えるだけで
    // プロセス大域状態への到達が目録から消えては困る。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    class Sample {
        public function f(string $requestor): void {
            $requestor::setHttpClient($this->client);
            static::setMaxNetworkRetries(0);
        }
    }
    PHP;

    expect(array_column(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/Sample.php', $source), 'name'))
        ->toBe(['setHttpClient', 'setMaxNetworkRetries']);
});

test('trait 本体の self:: 経由の大域 setter も fail-closed で検出する', function (): void {
    // trait のコードは利用クラスへ展開されるため `self` は静的に決まらない。
    // trait 自身へ解決してしまうと、この書き方で目録を抜けられる。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    trait UsesGateway {
        public function f(): void { self::setHttpClient($this->client); }
    }
    PHP;

    expect(array_column(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/UsesGateway.php', $source), 'name'))
        ->toBe(['setHttpClient']);
});

test('同じ名前空間の裸の受け手は解決され、大域 setter と取り違えない', function (): void {
    // import の無い短縮名の受け手は現在の名前空間の下に解決される
    // (`App\Gate\Registry`)。Stripe 名前空間ではないので検出しない。
    $source = <<<'PHP'
    <?php
    namespace App\Gate;
    class Sample { public function f(): void { Registry::instance(); } }
    PHP;

    expect(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/Sample.php', $source))->toBe([]);
});
