<?php

declare(strict_types=1);

use Tests\Support\ExternalSeam\ExternalSeamRule;
use Tests\Support\ExternalSeam\ExternalSeamScanner;
use Tests\Support\ExternalSeam\ExternalSeamSite;
use Tests\Support\ScanScopeKind;

/*
 * `ExternalSeamScanner` の性質を合成ソースで固定する unit テスト。
 *
 * ★負のコントロール (検出**しない**こと) を主眼に置く。規則を接頭辞走査へ緩めると
 *   Stripe 例外 14 クラスや値オブジェクトを拾って目録が肥大し信号が死ぬため、
 *   「拾わないこと」がこの走査器の中心的な性質である。
 * ★合成ソースは実ファイル (GatewayFailureClassifier / StripePriceCatalogEntry /
 *   SocialAccountService) の import 節を写して作る (実際には起きない形を検査しないため)。
 */

/** @return list<string> 規則の value 一覧 */
function externalSeamRuleValues(ExternalSeamSite ...$sites): array
{
    return array_map(static fn (ExternalSeamSite $site): string => $site->rule->value, $sites);
}

test('走査器: Cashier::stripe() を payment_client_call として検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services\Billing;
    use Laravel\Cashier\Cashier;
    final class Probe
    {
        public function go(): mixed
        {
            return Cashier::stripe()->checkout->sessions->create([]);
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
        ->and($result->suppressed)->toBe([])
        ->and($result->adopted[0]->class)->toBe('App\Services\Billing\Probe')
        ->and($result->adopted[0]->callable)->toBe('go');
});

test('走査器: 完全修飾の \Laravel\Cashier\Cashier::stripe() も検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services\Billing;
    final class Probe
    {
        public function go(): mixed
        {
            return \Laravel\Cashier\Cashier::stripe();
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
        ->and($result->suppressed)->toBe([]);
});

test('走査器: import だけで決済名前空間を知るファイルの ->stripe() を検出する', function (): void {
    // `use Stripe\StripeClient;` があるだけで型参照も構築もしない。
    // `use` は site ではないため、ReferenceScanResult::$imports を見なければ必ず落ちる。
    $source = <<<'PHP'
    <?php
    namespace App\Services\Billing;
    use Stripe\StripeClient;
    final class Probe
    {
        public function go(object $organization): mixed
        {
            return $organization->stripe();
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
        ->and($result->suppressed)->toBe([]);
});

test('走査器: 決済名前空間をまったく知らないファイルの ->stripe() は抑制コレクションへ入る', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services\Unrelated;
    final class Probe
    {
        public function go(object $client): mixed
        {
            return $client->stripe();
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toBe([])
        ->and(externalSeamRuleValues(...$result->suppressed))->toBe([ExternalSeamRule::PaymentClientCall->value]);
});

test('走査器: new Stripe\StripeClient を payment_client_construction として検出する', function (): void {
    // ★見本は完全修飾と import の 2 形で書く。`namespace App\Services\Billing;` の中で
    //   `new Stripe\StripeClient(...)` と書くと PHP は
    //   `App\Services\Billing\Stripe\StripeClient` を指すので、決済 client の見本にならない
    //   (部分修飾名を解決するようになって初めてこの取り違えが見える)。
    $source = <<<'PHP'
    <?php
    namespace App\Services\Billing;
    use Stripe\StripeClient;
    final class Probe
    {
        public function go(): mixed
        {
            return new StripeClient(['api_key' => 'sk_test']);
        }

        public function goQualified(): mixed
        {
            return new \Stripe\StripeClient(['api_key' => 'sk_test']);
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([
        ExternalSeamRule::PaymentClientConstruction->value,
        ExternalSeamRule::PaymentClientConstruction->value,
    ]);
});

test('走査器: Stripe\HttpClient\CurlClient の new は検出しない', function (): void {
    // 大域 setter の pin (ExternalClientTimeoutServiceProvider) は T126 の
    // stripe_global_setter 規則が正本。責務が交わらないことの証明。
    $source = <<<'PHP'
    <?php
    namespace App\Providers;
    use Stripe\HttpClient\CurlClient;
    use Stripe\Stripe;
    final class Probe
    {
        public function go(): void
        {
            $client = new CurlClient([CURLOPT_CONNECTTIMEOUT => 3]);
            Stripe::setHttpClient($client);
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toBe([])
        ->and($result->suppressed)->toBe([]);
});

test('走査器: Stripe 例外クラスの import だけでは検出しない', function (): void {
    // App\Support\Billing\GatewayFailureClassifier の import 節を写した合成ソース。
    $source = <<<'PHP'
    <?php
    namespace App\Support\Billing;
    use Stripe\Exception\ApiConnectionException;
    use Stripe\Exception\AuthenticationException;
    use Stripe\Exception\BadMethodCallException as StripeBadMethodCallException;
    use Stripe\Exception\CardException;
    use Stripe\Exception\IdempotencyException;
    use Stripe\Exception\InvalidArgumentException as StripeInvalidArgumentException;
    use Stripe\Exception\InvalidRequestException;
    use Stripe\Exception\PermissionException;
    use Stripe\Exception\RateLimitException;
    use Stripe\Exception\SignatureVerificationException;
    use Stripe\Exception\TemporarySessionExpiredException;
    use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
    use Stripe\Exception\UnknownApiErrorException;
    use Laravel\Cashier\Exceptions\IncompletePayment;
    use Throwable;
    final class Probe
    {
        public function classify(Throwable $error): string
        {
            return match (true) {
                $error instanceof CardException => 'card',
                $error instanceof RateLimitException => 'rate_limit',
                $error instanceof ApiConnectionException => 'connection',
                $error instanceof AuthenticationException => 'auth',
                $error instanceof IdempotencyException => 'idempotency',
                $error instanceof InvalidRequestException => 'invalid_request',
                $error instanceof PermissionException => 'permission',
                $error instanceof SignatureVerificationException => 'signature',
                $error instanceof TemporarySessionExpiredException => 'session',
                $error instanceof UnknownApiErrorException => 'unknown_api',
                $error instanceof StripeBadMethodCallException => 'bad_method',
                $error instanceof StripeInvalidArgumentException => 'invalid_argument',
                $error instanceof StripeUnexpectedValueException => 'unexpected_value',
                $error instanceof IncompletePayment => 'incomplete',
                default => 'other',
            };
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toBe([])
        ->and($result->suppressed)->toBe([]);
});

test('走査器: Stripe 値オブジェクト (Price / StripeObject) の参照だけでは検出しない', function (): void {
    // App\DataTransferObjects\Billing\StripePriceCatalogEntry の import 節を写した合成ソース。
    $source = <<<'PHP'
    <?php
    namespace App\DataTransferObjects\Billing;
    use Stripe\Price as StripePrice;
    use Stripe\StripeObject;
    final readonly class Probe
    {
        public static function fromStripe(StripePrice $price, StripeObject $recurring): self
        {
            return new self();
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toBe([])
        ->and($result->suppressed)->toBe([]);
});

test('走査器: Socialite facade の静的呼び出しは 1 site として検出する', function (): void {
    // receiver の NameReference のみを canonical にしている = 二重検出しないことの証明。
    $source = <<<'PHP'
    <?php
    namespace App\Http\Controllers\Auth;
    use Laravel\Socialite\Facades\Socialite;
    final class Probe
    {
        public function redirect(): mixed
        {
            return Socialite::driver('google')->redirect();
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))
        ->toBe([ExternalSeamRule::SocialiteFacadeReference->value]);
});

test('走査器: Socialite Contracts の型参照は検出しない', function (): void {
    // App\Services\Auth\SocialAccountService の import 節を写した合成ソース。
    $source = <<<'PHP'
    <?php
    namespace App\Services\Auth;
    use Laravel\Socialite\Contracts\User as SocialiteUser;
    final class Probe
    {
        public function resolve(SocialiteUser $socialUser): ?string
        {
            return $socialUser->getEmail();
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toBe([]);
});

test('走査器: Http facade を alias / 完全修飾の両形で 1 site ずつ検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades\Http;
    final class Probe
    {
        public function aliased(): mixed
        {
            return Http::asForm()->post('https://example.test');
        }

        public function qualified(): mixed
        {
            return \Illuminate\Support\Facades\Http::connectTimeout(3)->get('https://example.test');
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([
        ExternalSeamRule::HttpFacadeReference->value,
        ExternalSeamRule::HttpFacadeReference->value,
    ])
        ->and($result->adopted[0]->callable)->toBe('aliased')
        ->and($result->adopted[1]->callable)->toBe('qualified');
});

test('走査器: Mail / Notification facade を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Actions;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Notification;
    final class Probe
    {
        public function send(): void
        {
            Mail::to('user@example.test')->send(new \stdClass());
            Notification::route('mail', 'user@example.test')->notify(new \stdClass());
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([
        ExternalSeamRule::MailFacadeReference->value,
        ExternalSeamRule::MailFacadeReference->value,
    ]);
});

test('走査器: コメント・文字列リテラル中の目印を検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    final class Probe
    {
        // Cashier::stripe() を直接呼ぶのは禁止 (このコメントは検出されない)
        public function note(): string
        {
            return 'Socialite::driver は禁止';
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toBe([])
        ->and($result->suppressed)->toBe([]);
});

test('走査器: グループ use と alias を解決する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades\{Http, Mail};
    use Laravel\Socialite\Facades\Socialite as SocialiteFacade;
    final class Probe
    {
        public function go(): void
        {
            Http::get('https://example.test');
            Mail::to('user@example.test');
            SocialiteFacade::driver('google');
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([
        ExternalSeamRule::HttpFacadeReference->value,
        ExternalSeamRule::MailFacadeReference->value,
        ExternalSeamRule::SocialiteFacadeReference->value,
    ]);
});

test('走査器: 同名別 namespace の facade を誤検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Support\Http;
    final class Probe
    {
        public function go(): mixed
        {
            return Http::get('https://example.test');
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toBe([]);
});

test('走査器: 匿名クラス・ファイルスコープの site を scopeKind で区別する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades\Http;
    $probe = new class
    {
        public function go(): mixed
        {
            return Http::get('https://anonymous.test');
        }
    };
    Http::get('https://file-scope.test');
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toHaveCount(2)
        ->and($result->adopted[0]->scopeKind)->toBe(ScanScopeKind::AnonymousClass)
        ->and($result->adopted[0]->class)->toBeNull()
        ->and($result->adopted[1]->scopeKind)->toBe(ScanScopeKind::FileScope);
});

test('走査器: 文字列補間を含むメソッド本体でも scope 追跡が壊れない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades\Http;
    final class Probe
    {
        public function label(string $name): string
        {
            return "prefix {$name} suffix";
        }

        public function go(): mixed
        {
            return Http::get('https://example.test');
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toHaveCount(1)
        ->and($result->adopted[0]->scopeKind)->toBe(ScanScopeKind::NamedClass)
        ->and($result->adopted[0]->class)->toBe('App\Services\Probe')
        ->and($result->adopted[0]->callable)->toBe('go');
});

test('走査器: 先頭要素を import した部分修飾名 (Facades\Http) を検出する', function (): void {
    // T_NAME_QUALIFIED は先頭要素を import 表で置き換えて解決する。
    // 解決しなかった頃はこの形が目録に出ず、外部到達点が無言で見逃されていた (T226 で是正)。
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

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::HttpFacadeReference->value])
        ->and($result->adopted[0]->class)->toBe('App\Services\Probe')
        ->and($result->adopted[0]->callable)->toBe('go');
});

test('走査器: 先頭要素を import した部分修飾名の Cashier\Cashier::stripe() を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services\Billing;
    use Laravel\Cashier;
    final class Probe
    {
        public function go(): mixed
        {
            return Cashier\Cashier::stripe();
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
        ->and($result->suppressed)->toBe([]);
});

test('走査器: 受け手を静的に決められない ::stripe() は fail-closed で採用する', function (): void {
    // 受け手が変数の静的呼び出しは FQCN を確定できない。**未解決を黙って候補から外さない**
    // (規約 (b))。決済 client の取り出しを変数経由に書き換えるだけで目録を抜けられては困る。
    $source = <<<'PHP'
    <?php
    namespace App\Services\Billing;
    final class Probe
    {
        public function go(string $gateway): mixed
        {
            return $gateway::stripe();
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
        ->and($result->suppressed)->toBe([]);
});

test('走査器: 名前空間相対の部分修飾名を外部到達点と取り違えない', function (): void {
    // 先頭要素の import が無い部分修飾名は**現在の名前空間の下**に解決される。
    // `App\Services\Billing\Cashier\Cashier` は決済 facade ではないので採用しない。
    $source = <<<'PHP'
    <?php
    namespace App\Services\Billing;
    final class Probe
    {
        public function go(): mixed
        {
            return Cashier\Cashier::stripe();
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    // `stripe` はメソッド名一致でも拾う規則を持たない (静的呼び出しは receiver 一致が要る)。
    expect($result->adopted)->toBe([])
        ->and($result->suppressed)->toBe([]);
});

test('走査器: 同名 alias (use ... as Http) を解決する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use App\Support\Client as Http;
    final class Probe
    {
        public function go(): mixed
        {
            return Http::get('https://example.test');
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect($result->adopted)->toBe([]);
});

test('走査器: 同一クラスに Http と Mail がある場合は 2 種類の site を返す', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Services;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Mail;
    final class Probe
    {
        public function go(): void
        {
            Http::get('https://example.test');
            Mail::to('user@example.test');
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([
        ExternalSeamRule::HttpFacadeReference->value,
        ExternalSeamRule::MailFacadeReference->value,
    ])
        ->and($result->adopted[0]->class)->toBe('App\Services\Probe')
        ->and($result->adopted[1]->class)->toBe('App\Services\Probe');
});

test('走査器: Stripe 例外だけを import するファイルの ->stripe() は fail-closed で採用する', function (): void {
    // 抑制解除は「決済名前空間を知っているか」で判定するため、Stripe 例外の import だけでも
    // 抑制は外れて adopted になる。**これは意図した fail-closed** である
    // (抑制 = 偽陰性の口なので、迷ったら採用して目録登録を要求する側へ倒す)。
    // 偽陽性が実際に出たら規則側で分離する (entry 登録で黙らせない)。
    $source = <<<'PHP'
    <?php
    namespace App\Support\Billing;
    use Stripe\Exception\CardException;
    final class Probe
    {
        public function go(object $client): mixed
        {
            return $client->stripe();
        }

        public function classify(CardException $error): string
        {
            return 'card';
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
        ->and($result->suppressed)->toBe([]);
});
