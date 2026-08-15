<?php

declare(strict_types=1);

use App\DataTransferObjects\Auth\SessionStatusDto;
use App\Http\Resources\Auth\SessionStatusResource;
use App\Support\Auth\SessionEpoch;

/*
 * bfcache 秘匿・再検証の「言語をまたぐ名前」の契約ずれ検査。
 *
 * PHP 側 (App\Support\Auth\SessionEpoch / SessionStatusResource) を正本として、
 * 画面側のファイルに同じ文字列が実在することを確かめる。cookie 名・ヘッダ名・
 * 共有 prop のキー・応答キー・印の書式は型検査が届かないため、片側だけ変えると
 * 静かに壊れる (常に読み直し、または常に不一致) 。
 *
 * **保証範囲を誇張しない**: これは**ファイル単位の語の実在検査**であり、
 * **使われ方が正しいことは保証しない**。同じ語がコメントや型宣言に残っていれば、
 * 実際に使う箇所だけを別名へ変えても緑のままである (実測: 宣言 1 行だけを改名した
 * 6 通りのうち赤くなったのは 3 通り。語をファイルから全消しすれば 6 通りとも赤くなる。
 * 記録は devnotes/20260816-0202-todo-T178/contract-sync-negative-control.md)。
 * 意味の正しさは vitest (tests/js/lib/bfcache-guard.test.ts の分岐) と Feature テスト
 * (tests/Feature/Auth/SessionStatusProbeTest.php の応答契約) が担う。
 */

/**
 * 監視対象ファイル (リポジトリルート相対)。
 *
 * @return array<string, string>
 */
function bfcacheContractWatchedFiles(): array
{
    return [
        'guard' => 'resources/js/lib/bfcache-guard.ts',
        'sharedProps' => 'resources/js/lib/shared-props.ts',
        'inertiaMiddleware' => 'app/Http/Middleware/HandleInertiaRequests.php',
        'trial' => 'resources/js/lib/debug/bfcache-trial.ts',
        'entrypoint' => 'resources/js/app.ts',
    ];
}

/**
 * その語が**識別子として**現れるか。
 *
 * 単なる部分文字列一致だと、片側だけを別名へ変えても (`session_epoch` →
 * `session_epoch_renamed`) 元の語を含んでしまい検査が赤くならない。前後を
 * 識別子文字でない位置に限ることで、名前の変更が必ず検出される。
 */
function bfcacheContractHasToken(string $haystack, string $token): bool
{
    $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($token, '/').'(?![A-Za-z0-9_])/u';

    return preg_match($pattern, $haystack) === 1;
}

/**
 * その語が**二重引用符で囲まれた文字列そのもの**として現れるか。
 *
 * cookie 名とヘッダ名は識別子文字でない `-` や `_` を含むため、識別子の境界だけでは
 * `X-Session-Epoch` → `X-Session-Epoch-Renamed` のような接尾辞付きの改名を見逃す。
 * 画面側ではこの 2 つを文字列リテラルとしてしか書けないので、引用符ごと照合する。
 */
function bfcacheContractHasQuotedLiteral(string $haystack, string $value): bool
{
    return str_contains($haystack, '"'.$value.'"');
}

function bfcacheContractFileContents(string $key): string
{
    $path = base_path(bfcacheContractWatchedFiles()[$key]);
    $contents = file_get_contents($path);

    expect($contents)->toBeString();

    return (string) $contents;
}

test('監視対象ファイルがすべて実在する (パス変更で検査が無言で空にならない)', function (): void {
    foreach (bfcacheContractWatchedFiles() as $key => $relative) {
        expect(file_exists(base_path($relative)))
            ->toBeTrue("監視対象 '{$key}' ({$relative}) が存在しない。パスを変えたなら本テストの一覧も直すこと");
    }
});

test('世代 cookie 名とヘッダ名が画面側の guard に実在する', function (): void {
    $guard = bfcacheContractFileContents('guard');

    expect(bfcacheContractHasQuotedLiteral($guard, SessionEpoch::COOKIE_NAME))
        ->toBeTrue('cookie 名 "'.SessionEpoch::COOKIE_NAME.'" が guard に文字列として無い')
        ->and(bfcacheContractHasQuotedLiteral($guard, SessionEpoch::HEADER_NAME))
        ->toBeTrue('ヘッダ名 "'.SessionEpoch::HEADER_NAME.'" が guard に文字列として無い');
});

test('共有 prop のキーがサーバ側 middleware と画面側の読み取りの両方に実在する', function (): void {
    // サーバ側は定数を参照する (文字列を書き写さない = ずれる余地を型で消す)。
    // 画面側は文字列でしか書けないので、こちらは値そのものの実在を見る。
    expect(bfcacheContractFileContents('inertiaMiddleware'))->toContain('SessionEpoch::SHARED_PROP_KEY')
        ->and(bfcacheContractHasToken(
            bfcacheContractFileContents('sharedProps'),
            SessionEpoch::SHARED_PROP_KEY,
        ))->toBeTrue('共有 prop のキーが画面側の読み取りに無い');
});

test('プローブ応答のキーがすべて画面側の guard に実在する', function (): void {
    // 応答キーは Resource を実際に toArray() して得る (文字列を検査側にも書くと
    // 正本が 2 か所になる)。キーが増えたら検査対象も自動で増える。
    $keys = array_keys(SessionStatusResource::make(new SessionStatusDto(
        authenticated: true,
        sessionEpochMatches: true,
    ))->toArray(request()));

    expect($keys)->not->toBeEmpty();

    $guard = bfcacheContractFileContents('guard');
    foreach ($keys as $key) {
        expect(bfcacheContractHasToken($guard, (string) $key))
            ->toBeTrue("応答キー '{$key}' が guard に無い");
    }
});

test('印の書式が画面側の 2 ファイルに実在する', function (): void {
    // PHP の正規表現から区切り・アンカー・修飾子を外して素の書式を得る。
    // 期待値と突き合わせてから使うので、外し方が壊れれば degenerate PASS にならず赤くなる。
    $pattern = trim(SessionEpoch::VALUE_PATTERN, '/^$D');

    expect($pattern)->toBe('[0-9a-f]{32}')
        ->and(bfcacheContractFileContents('guard'))->toContain($pattern)
        ->and(bfcacheContractFileContents('sharedProps'))->toContain($pattern);
});

test('ガードの状態値 reloading が検証ページの許可語彙に実在する', function (): void {
    // 検証ページの状態語彙が追随していないと、実機受入確認 (T085) で記録が拒否される。
    expect(bfcacheContractHasToken(bfcacheContractFileContents('trial'), 'reloading'))
        ->toBeTrue('検証ページの許可語彙に reloading が無い');
});

test('入口スクリプトが描画世代と現世代の読み取りを明示的に配線している', function (): void {
    // 既定任せ (readRenderedEpoch を渡さない) にすると常に読み直しになる。
    // 逆に描画世代の既定を cookie にすると同期判定が素通しになるため、
    // 2 つの出所を呼び出し側で名前付きで見せることを固定する。
    $entrypoint = bfcacheContractFileContents('entrypoint');

    expect(bfcacheContractHasToken($entrypoint, 'readRenderedEpoch'))
        ->toBeTrue('入口スクリプトが readRenderedEpoch を渡していない')
        ->and(bfcacheContractHasToken($entrypoint, 'readCurrentEpoch'))
        ->toBeTrue('入口スクリプトが readCurrentEpoch を渡していない');
});
