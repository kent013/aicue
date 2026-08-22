<?php

declare(strict_types=1);

/*
 * SSRF 判定の完全区間分類への追従の回帰 gate（家系の feature ssrf-pin-boundary の
 * aicue セル target_version。手本 spirux@a41aabbd）。
 *
 * ## なぜ SsrfPinBoundaryTest と別ファイルなのか
 *
 * 隣の SsrfPinBoundaryTest は「config/ssrf-pin.php の pin 値」と「境界で拒否できること」を
 * 固定するが、そこで拒否されるのは IP literal / 許可外スキーム / 許可外ポートであり、
 * いずれも**分類層より前**で決まる。つまり**判定層が何を拒否するかは 1 件も見ていない**。
 * 本 gate はその層だけを見る。加えて SsrfPinBoundaryTest は採用時債務パスなので、
 * 触ると債務の整理が連鎖する（`tests/Support/TemplateDivergence/adoption-debt.tsv`）。
 *
 * ## 何を固定するか
 *
 * package `kent013/laravel-ssrf-pin` は ^0.4 で判定を反転した — 列挙型の拒否リストから、
 * IANA Special-Purpose Address Registry を写した**完全区間分類**へ変わり、
 * 「公開到達可能と分類できた IP だけを許可」する既定拒否になった。
 * ^0.2 / ^0.3 の列挙型拒否では、列挙に無い特殊用途アドレス 8 区間が
 * 「拒否規則に該当しない = 許可」として素通りしていた（本リポジトリの vendor v0.2.0 で実測）。
 * 本 gate はその 8 区間が拒否されること、従来から拒否していた区分が緩んでいないこと、
 * 公開到達可能なアドレスは通ること、混在応答が拒否されることを固定する。
 *
 * ## 判定を再実装しない
 *
 * deny 規則の本体は共有パッケージ側にある（家系の不変条件）。本 gate は
 * **package の判定結果を観測するだけ**で、CIDR も区間表もアプリ側に持たない。
 *
 * ## 本 gate が保証しないもの（誇張しない）
 *
 *  1. **登録簿の陳腐化は検知しない**。R1 が見るのは「導入した版の中の登録簿が
 *     変わったか」だけで、IANA 側の更新は 1 度も参照しない。パッケージを更新しなければ
 *     緑のままである。定期の見直しは上流（kent013/laravel-ssrf-pin）と家系の巡回の責務。
 *  2. **区間分割の完全性は検証しない**。隙間・重複・覆い漏れの検査は package が
 *     load 時に行う（崩れていれば例外）。ここで写して二重管理にしない。
 *  3. **実到達性は検証しない**。DNS 応答はすべて FakeDnsResolver の固定値で、
 *     外向き通信は 1 度も起きない（全レーンで StrayHttpRequestGuard が既定拒否）。
 *  4. **呼び出し側の経路は見ない**。SNS 証明書取得が SSRF 検査を通ることは
 *     tests/Architecture/SnsCertificateFetchContractTest.php と
 *     tests/Feature/Mail/SnsCertificateFetcherTest.php の担当である。
 */

use Kent013\SsrfPin\Contracts\DnsResolverInterface;
use Kent013\SsrfPin\Dtos\UrlSafetyDecision;
use Kent013\SsrfPin\Enums\SsrfDenyReason;
use Kent013\SsrfPin\Testing\FakeDnsResolver;
use Kent013\SsrfPin\UrlSafetyInspector;

/**
 * 判定に使う登録簿の版（IANA Special-Purpose Address Registry の発行日）。
 * package v0.4.1 同梱の resources/ip-classification.json の registry_version。
 */
const SSRF_CLASSIFICATION_REGISTRY_VERSION = '2025-10-09';

/** 観測用の host。IP literal ではなく**名前**であることが本質（下の docblock 参照）。 */
const SSRF_PROBE_HOST = 'probe.ssrf-pin.test';

/**
 * 分類層の判定を「host → DNS 応答」経由で観測する。
 *
 * ★**IP literal URL を使ってはならない。** config/ssrf-pin.php の `deny_ip_literals` は
 *   true で、`inspect()` は IP literal を**分類より前に** `IpLiteralNotAllowed` で切る。
 *   IP literal で書くと 8 区間を 1 つも検査しないまま緑になる（偽グリーン）。
 *   手本の spirux はこのキーを true にしていないのでそのまま写せない。
 *
 * ★**bind と forgetInstance は、どちらも resolve より前でなければならない。**
 *   `SsrfPinServiceProvider::register()` は `UrlSafetyInspector` を `singleton()` で
 *   登録しているので、`DnsResolverInterface` を bind しただけでは
 *   **既に解決済みの instance は作り直されない** = 前のケースの DNS 応答で判定してしまう。
 *   `forgetInstance()` を必ず挟む（`tests/Pest.php::bindSnsDnsResolver()` と同じ作法）。
 *   なお `bind` と `forgetInstance` の**相互の順序は問わない**（入れ替えても等価）。
 *   本質は**両方が `app(UrlSafetyInspector::class)` より前**にあることだけである。
 *   この差し替えが実際に効いていることは R2（負のコントロール）が固定する。
 *
 * ★`UrlSafetyInspector` 自体は差し替えない（`ExternalFakeDeclaration::neverSwapped()` が
 *   「偽物にすると内部宛ての取得が通る」として禁じている）。差し替えるのは**その依存**である。
 *
 * @param  list<string>  $ipv4  A レコードの応答
 * @param  list<string>  $ipv6  AAAA レコードの応答
 */
function ssrfProbe(array $ipv4, array $ipv6 = []): UrlSafetyDecision
{
    app()->bind(
        DnsResolverInterface::class,
        fn (): DnsResolverInterface => new FakeDnsResolver(
            [SSRF_PROBE_HOST => $ipv4],
            [SSRF_PROBE_HOST => $ipv6],
        ),
    );
    app()->forgetInstance(UrlSafetyInspector::class);

    return app(UrlSafetyInspector::class)->inspect('https://'.SSRF_PROBE_HOST.'/probe');
}

/** IPv4 を A レコードとして、IPv6 を AAAA レコードとして振り分ける。 */
function ssrfProbeSingle(string $ip): UrlSafetyDecision
{
    return str_contains($ip, ':') ? ssrfProbe([], [$ip]) : ssrfProbe([$ip]);
}

/*
|--------------------------------------------------------------------------
| S1: ^0.2 / ^0.3 の列挙型拒否が素通りさせていた IANA 特殊用途 8 区間
|--------------------------------------------------------------------------
| ★ケースを畳まない。区間名がケース名として読め、期待理由が個別に書かれていること。
|   本 gate は aicue が第二層（package 契約検査）を持たない以上、
|   「入った版が実際に何を備えているか」を見る唯一の検査である。
|   1 件そっと削る変更がレビューで見えなければならない。
*/
test('S1 素通りしていた IANA 特殊用途 8 区間を拒否する', function (string $ip): void {
    $decision = ssrfProbeSingle($ip);

    expect($decision->allowed)->toBeFalse("expected deny for {$ip}")
        ->and($decision->reason)->toBe(SsrfDenyReason::NotGloballyReachable, "for {$ip}");
})->with([
    'TEST-NET-1 (192.0.2.0/24)' => '192.0.2.1',
    'TEST-NET-2 (198.51.100.0/24)' => '198.51.100.7',
    'TEST-NET-3 (203.0.113.0/24)' => '203.0.113.5',
    '6to4 relay anycast (192.88.99.0/24)' => '192.88.99.1',
    'IPv6 documentation (2001:db8::/32)' => '2001:db8::1',
    'IPv6 6to4 (2002::/16)' => '2002::1',
    'IPv6 documentation new (3fff::/20)' => '3fff::1',
    'SRv6 SIDs (5f00::/16)' => '5f00::1',
]);

/*
|--------------------------------------------------------------------------
| S2: 従来から拒否していた区分が緩んでいないこと
|--------------------------------------------------------------------------
| 判定の反転（列挙型 → 完全区間分類）で、既に塞がっていた宛先が開くことがあってはならない。
| 理由コードまで固定するのは、区間の分類が別カテゴリへずれた形も検出するためである。
*/
test('S2 判定の反転で従来の拒否が緩んでいない', function (string $ip, SsrfDenyReason $reason): void {
    $decision = ssrfProbeSingle($ip);

    expect($decision->allowed)->toBeFalse("expected deny for {$ip}")
        ->and($decision->reason)->toBe($reason, "for {$ip}");
})->with([
    'loopback' => ['127.0.0.1', SsrfDenyReason::Loopback],
    'private 10/8' => ['10.0.0.5', SsrfDenyReason::PrivateRange],
    'link local (IMDS)' => ['169.254.169.254', SsrfDenyReason::LinkLocal],
    'CGNAT' => ['100.64.0.1', SsrfDenyReason::PrivateRange],
    'IPv6 ULA' => ['fc00::1', SsrfDenyReason::PrivateRange],
    'IPv6 link local' => ['fe80::1', SsrfDenyReason::LinkLocal],
]);

/*
|--------------------------------------------------------------------------
| S3: 正のコントロール
|--------------------------------------------------------------------------
| ★これが無いと「何かの理由で常に deny になる壊れ方」（config の取り違え・
|   分類表の読み込み失敗など）で全ケースが緑になる。
*/
test(
    'S3 正のコントロール: 公開到達可能なアドレスは通る',
    /**
     * @param  list<string>  $ipv4
     * @param  list<string>  $ipv6
     */
    function (array $ipv4, array $ipv6): void {
        expect(ssrfProbe($ipv4, $ipv6)->allowed)->toBeTrue('expected allow');
    },
)->with([
    'public v4 のみ' => [['93.184.216.34'], []],
    'public v6 のみ' => [[], ['2606:2800:220:1:248:1893:25c8:1946']],
    // ★両 family が揃っていても通ること。これが無いと「AAAA があると必ず deny」
    //   という壊れ方で S4 の family 交差ケースが緑になってしまう。
    'public v4 + public v6' => [['93.184.216.34'], ['2606:2800:220:1:248:1893:25c8:1946']],
]);

/*
|--------------------------------------------------------------------------
| S4: 応答の全件検査（A レコードと AAAA レコードを跨いで効くこと）
|--------------------------------------------------------------------------
| inspect() は A + AAAA を 1 つの集合へ畳んでから**全件**を分類し、
| 1 件でも非公開なら拒否する。ここが緩むと、攻撃者は
| **公開 IP を 1 つ混ぜるだけで**通せる。
|
| ★**family を跨ぐケースを必ず持つ。** A レコード内の 2 件だけを見る形では、
|   「A が 1 件でも公開なら AAAA を無視する」という後退を検出できない
|   (攻撃者は A に公開 IP、AAAA に内部アドレスを置けばよい)。
|
| ★**AAAA 内の複数応答も最後まで検査されること**を固定する。A 側にだけ
|   「公開 + 特殊用途」を置くと、「AAAA は先頭 1 件しか分類しない」という後退が
|   全ケースをすり抜ける。4 ケースの内訳は
|   「A 内の複数」「A→AAAA 交差」「AAAA→A 交差」「AAAA 内の複数」で、
|   *どちらの family でも、どの位置にあっても*非公開が 1 件あれば拒否されることを覆う。
*/
test(
    'S4 公開 IP が混ざっていても非公開が 1 件あれば拒否する',
    /**
     * @param  list<string>  $ipv4
     * @param  list<string>  $ipv6
     */
    function (array $ipv4, array $ipv6): void {
        $decision = ssrfProbe($ipv4, $ipv6);

        expect($decision->allowed)->toBeFalse('expected deny')
            ->and($decision->reason)->toBe(SsrfDenyReason::NotGloballyReachable);
    },
)->with([
    'A 内で 公開 + 特殊用途' => [['93.184.216.34', '192.0.2.1'], []],
    '公開 A + 特殊用途 AAAA' => [['93.184.216.34'], ['2001:db8::1']],
    '特殊用途 A + 公開 AAAA' => [['192.0.2.1'], ['2606:2800:220:1:248:1893:25c8:1946']],
    'AAAA 内で 公開 + 特殊用途' => [[], ['2606:2800:220:1:248:1893:25c8:1946', '2001:db8::1']],
]);

/*
|--------------------------------------------------------------------------
| R1: 判定に使われた登録簿の版
|--------------------------------------------------------------------------
| 安全境界の一部が**同梱の登録簿の内容**になった。上流が登録簿を更新すれば
| ここが赤くなる。**これは意図である** — 更新時に登録簿の差分と S1〜S4 の
| 全ケースを見直すための入口として置く。
|
| ★config/ssrf-pin.php へ registry_version を足す代わりの手当である
|   （同ファイルは pin 値 5 つ維持の対象かつ採用時債務パスなので触らない）。
| ★**陳腐化の検知ではない**（上の「保証しないもの」1 を参照）。
*/
test('R1 判定に使われた分類表の登録簿の版が pin されている', function (): void {
    expect(app(UrlSafetyInspector::class)->classificationRegistryVersion())
        ->toBe(SSRF_CLASSIFICATION_REGISTRY_VERSION);
});

/*
|--------------------------------------------------------------------------
| R2: 負のコントロール（この gate 自身の実効性）
|--------------------------------------------------------------------------
| ssrfProbe() の 3 段手順（bind → forgetInstance → resolve）が本当に効いていることを
| **同一テストの中で 2 回呼んで**固定する。forgetInstance を落とすと
| 2 回目が 1 回目の singleton で判定され、この test が落ちる。
| これが無いと、差し替えが効かなくなっても S1 が「1 件目の応答」で緑になる形の
| 偽グリーンに気付けない。
*/
test('R2 負のコントロール: DNS 応答の差し替えが singleton を貫いて効く', function (): void {
    expect(ssrfProbe(['93.184.216.34'])->allowed)->toBeTrue('1 回目: 公開到達可能')
        ->and(ssrfProbe(['192.0.2.1'])->allowed)->toBeFalse('2 回目: TEST-NET-1');
});
