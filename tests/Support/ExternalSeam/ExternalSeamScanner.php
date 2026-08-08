<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use Tests\Support\PhpReferenceScanner;
use Tests\Support\ReferenceKind;
use Tests\Support\ReferenceScanResult;
use Tests\Support\ReferenceSite;

/**
 * 「決済 / 外部ログイン / captcha・市場データ / メール送信」の外部到達点を静的に走査する純関数群。
 *
 * ★**接頭辞走査をしない**。`Stripe\` を素の接頭辞で走査すると
 *   `App\Support\Billing\GatewayFailureClassifier` が import する Stripe 例外 14 クラスと
 *   `App\DataTransferObjects\Billing\StripePriceCatalogEntry` の値オブジェクト参照を拾い、
 *   目録が肥大して信号が死ぬ。規則は **client の取得・構築**に限定する。
 *
 * ★**ファイル保存 (AWS / Flysystem) と LLM (Prism) は本走査器の対象外**。
 *   前者は `Tests\Support\ExternalClientBoundaryScanner` + T126 目録、
 *   後者は `Tests\Support\Prompts\PrismDirectDispatchScanner` + PromptGuardrailTest が正本。
 *   ここで重ねて走査すると**同じ到達事実が 2 箇所で宣言される**(本設計が最も避けたい形)。
 *
 * ★`Stripe\HttpClient\CurlClient` の `new` (`ExternalClientTimeoutServiceProvider` の大域 pin) は
 *   `PAYMENT_CLIENT_CONSTRUCTION_EXACT` の完全一致に当たらないため検出しない。
 *   大域 setter は T126 の `stripe_global_setter` 規則が正本であり続ける (責務が交わらない)。
 *
 * ★**保証範囲を誇張しない**: 検出できるのは下記 5 規則の**静的な出現**だけである。
 *   文字列キーの container 解決だけで型名も呼び出しも出さない経路は検出できない。
 *   走査根は `app/` のみで、`routes/` / `config/` は見ない。
 */
final class ExternalSeamScanner
{
    /** Stripe client を取り出す static 呼び出しの receiver (完全一致)。 */
    private const string CASHIER_FACADE = 'Laravel\\Cashier\\Cashier';

    /** client 取得のメソッド名 (receiver 非依存で拾い、後段で抑制する)。 */
    private const string CLIENT_ACCESSOR = 'stripe';

    /** client 構築とみなす FQCN (**完全一致**。接頭辞にしない)。 */
    private const array PAYMENT_CLIENT_CONSTRUCTION_EXACT = ['Stripe\\StripeClient'];

    /** `->stripe()` の抑制解除条件になる名前空間接頭辞。 */
    private const array PAYMENT_NAMESPACES = ['Laravel\\Cashier\\', 'Stripe\\'];

    /** facade 参照規則 (FQCN 完全一致 => 規則)。 */
    private const array FACADE_RULES = [
        'Laravel\\Socialite\\Facades\\Socialite' => ExternalSeamRule::SocialiteFacadeReference,
        'Illuminate\\Support\\Facades\\Http' => ExternalSeamRule::HttpFacadeReference,
        'Illuminate\\Support\\Facades\\Mail' => ExternalSeamRule::MailFacadeReference,
        'Illuminate\\Support\\Facades\\Notification' => ExternalSeamRule::MailFacadeReference,
    ];

    /**
     * 規則ごとの走査対象シンボル (**gate が委譲済み名前空間の混入を検査するための test 専用 API**)。
     *
     * ★private const を Reflection で覗く形にしない (実装詳細への依存を作らない)。
     *
     * @return array<string, list<string>> 規則の value => シンボル一覧
     */
    public static function ruleSymbols(): array
    {
        /** @var array<string, list<string>> $facades */
        $facades = [];
        foreach (self::FACADE_RULES as $fqcn => $rule) {
            $facades[$rule->value][] = $fqcn;
        }

        return $facades + [
            ExternalSeamRule::PaymentClientCall->value => [self::CASHIER_FACADE, self::CLIENT_ACCESSOR],
            ExternalSeamRule::PaymentClientConstruction->value => self::PAYMENT_CLIENT_CONSTRUCTION_EXACT,
        ];
    }

    public static function scan(string $relativePath, string $phpSource): ExternalSeamScanResult
    {
        $result = PhpReferenceScanner::references($relativePath, $phpSource);

        // 抑制解除の判定は **site の名前 ∪ import の FQCN** で行う
        // (`use Stripe\StripeClient;` だけを持つファイルは site を 1 つも出さないため、
        //  site だけを見ると判定を落とす)。
        $hasPaymentNamespace = self::hasPaymentNamespace($result);

        $adopted = [];
        $suppressed = [];

        foreach ($result->sites as $reference) {
            $site = self::classify($reference);
            if ($site === null) {
                continue;
            }

            // `->stripe()` は receiver 非依存の名前一致なので、同一ファイルに決済名前空間の
            // 参照が無ければ「同名の無関係な API」とみなして落とす。
            // ★落とした件数は捨てずに `suppressed` へ積む (gate が 0 件を固定する)。
            if ($site->rule === ExternalSeamRule::PaymentClientCall
                && $reference->kind === ReferenceKind::MethodCall
                && ! $hasPaymentNamespace
            ) {
                $suppressed[] = $site;

                continue;
            }

            $adopted[] = $site;
        }

        return new ExternalSeamScanResult($adopted, $suppressed);
    }

    /** ディレクトリ配下を走査して 1 つの結果へ畳む。 */
    public static function scanDirectory(string $absoluteRoot, string $relativeRoot): ExternalSeamScanResult
    {
        $adopted = [];
        $suppressed = [];
        foreach (PhpReferenceScanner::phpFiles($absoluteRoot, $relativeRoot) as $relative => $source) {
            $result = self::scan($relative, $source);
            array_push($adopted, ...$result->adopted);
            array_push($suppressed, ...$result->suppressed);
        }

        return new ExternalSeamScanResult($adopted, $suppressed);
    }

    private static function classify(ReferenceSite $reference): ?ExternalSeamSite
    {
        // 決済: client の取得 (static / method の両方)
        if ($reference->name === self::CLIENT_ACCESSOR
            && (($reference->kind === ReferenceKind::StaticCall && $reference->receiver === self::CASHIER_FACADE)
                || $reference->kind === ReferenceKind::MethodCall)
        ) {
            return self::site($reference, ExternalSeamRule::PaymentClientCall, self::CLIENT_ACCESSOR);
        }

        // 決済: client の構築 (完全一致)
        if ($reference->kind === ReferenceKind::Construction
            && in_array($reference->name, self::PAYMENT_CLIENT_CONSTRUCTION_EXACT, true)
        ) {
            return self::site($reference, ExternalSeamRule::PaymentClientConstruction, $reference->name);
        }

        // facade 参照。
        // ★**canonical は `NameReference` のみ**。`Socialite::driver()` は receiver が
        //   NameReference、メソッドが StaticCall として **2 site 出る**ため、両方を見ると
        //   1 つの呼び出しが 2 件に数えられる。receiver は alias 経由でも完全修飾でも
        //   必ず NameReference として現れるので、NameReference だけで取りこぼしは発生しない。
        if ($reference->kind === ReferenceKind::NameReference
            && array_key_exists($reference->name, self::FACADE_RULES)
        ) {
            return self::site($reference, self::FACADE_RULES[$reference->name], $reference->name);
        }

        return null;
    }

    /** ファイルが決済名前空間を知っているか (site の名前 ∪ import の FQCN で判定)。 */
    private static function hasPaymentNamespace(ReferenceScanResult $result): bool
    {
        $names = array_map(static fn (ReferenceSite $site): string => $site->name, $result->sites);
        foreach (array_merge($names, array_values($result->imports)) as $name) {
            foreach (self::PAYMENT_NAMESPACES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function site(ReferenceSite $reference, ExternalSeamRule $rule, string $symbol): ExternalSeamSite
    {
        return new ExternalSeamSite(
            path: $reference->path,
            line: $reference->line,
            rule: $rule,
            symbol: $symbol,
            scopeKind: $reference->scopeKind,
            class: $reference->class,
            callable: $reference->callable,
        );
    }
}
