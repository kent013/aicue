<?php

declare(strict_types=1);

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\StrayHttpRequestGuard;

/**
 * StrayHttpRequestGuard の self-test。
 *
 * 各テスト末尾で StrayHttpRequestGuard::drainForAssertion() を呼んで accumulator を
 * 空にしておかないと、tests/Pest.php の global afterEach (flushAndFailIfStray()) が
 * test 自身を fail させてしまう (StrayLlmCallGuardTest と同じ作法)。
 *
 * 本ファイルは laravel/framework の内部挙動 (handler stack の push 順 / 同期 throw /
 * fake() が prevent flag を保つこと) に対する**契約テスト**でもある。
 * framework 更新でこれらが崩れたらここが赤くなる。
 *
 * ★本ファイルは tests/Pest.php のレーン既定に**依存しない** (自分で install する)。
 *   理由:
 *    (1) 実装順序が S2 (自己検査) → S1 (guard) → S3 (レーン配線) なので、S3 の前に
 *        本ファイルを走らせる局面が必ずある。そのとき guard 未 install だと
 *        case A の Http::get('https://api.frankfurter.dev/...') が**実通信に進む**。
 *        これは「guard を作る作業のために外部へ出る」という本末転倒である。
 *    (2) 自己検査は「guard が install されていれば何が起きるか」の契約テストであり、
 *        その前提を自分で用意する方がテストとして自己完結する。
 *   S3 適用後は二重 install になるが、install() は冪等 (case G が固定する)。
 */
beforeEach(function (): void {
    StrayHttpRequestGuard::install($this->app);
});

test('case A: 未 fake の外向き HTTP は StrayRequestException + accumulator 記録', function (): void {
    // ★本ケースは「**無引数** Http::preventStrayRequests() が拒否を有効化する」という
    //   vendor の既定引数の意味に対する契約テストでもある (guard は無引数で呼ぶ)。
    //   将来 framework が既定値を反転させたらここが赤くなる。
    $threw = false;
    try {
        Http::get('https://api.frankfurter.dev/v1/latest');
    } catch (StrayRequestException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('api.frankfurter.dev');
    }

    expect($threw)->toBeTrue('レーン既定の preventStrayRequests が効いていない');

    $drained = StrayHttpRequestGuard::drainForAssertion();
    expect($drained)->toHaveCount(1)
        ->and($drained[0]['method'])->toBe('GET')
        ->and($drained[0]['url'])->toContain('api.frankfurter.dev');
});

test('case B: Http::fake([限定 URL]) 併用時、fake 対象は透過し未 fake の別 URL は stray になる', function (): void {
    // 既存テストの大半 (pwnedpasswords 限定 fake など) がこの形。
    // ★これが「レーン既定 ON と局所 Http::fake は無改修で共存する」の behavioral な根拠。
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

    $ok = Http::get('https://api.pwnedpasswords.com/range/AAAAA');
    expect($ok->status())->toBe(200);
    expect(StrayHttpRequestGuard::drainForAssertion())->toBe([]);

    $threw = false;
    try {
        Http::get('https://api.frankfurter.dev/v1/latest');
    } catch (StrayRequestException) {
        $threw = true;
    }
    expect($threw)->toBeTrue('fake を張ると prevent フラグが reset される = framework 前提が崩れた');
    expect(StrayHttpRequestGuard::drainForAssertion())->toHaveCount(1);
});

test('case C: Http::fake([*]) を張れば stray にならない', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $response = Http::get('https://api.frankfurter.dev/v1/latest');

    expect($response->status())->toBe(200);
    expect(StrayHttpRequestGuard::drainForAssertion())->toBe([]);
});

test('case D: loopback (127.0.0.1) は stray にならない (stray 判定を通過して送信段まで進む)', function (): void {
    // ★固定ポート (9 = discard 等) は「閉じている」前提が環境依存で flaky になる。
    //   OS に一時ポートを割り当てさせ、すぐ close して
    //   「ほぼ確実に待ち受けが無いポート」を得る。
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($probe)->not->toBeFalse("一時ポートを確保できない: {$errstr} ({$errno})");
    /** @var resource $probe */
    $name = stream_socket_get_name($probe, false);
    expect($name)->toBeString();
    /** @var string $name */
    $port = (int) Str::afterLast($name, ':');
    fclose($probe);

    $caught = null;
    try {
        Http::connectTimeout(1)->timeout(1)->get("http://127.0.0.1:{$port}/health");
    } catch (Throwable $e) {
        $caught = $e;
    }

    // ★assert の主眼は「StrayRequestException **ではない**」こと =
    //   許可判定を通過して実際の送信段まで進んだことの behavioral な証明。
    //   接続結果 (refuse / timeout / 何かが listen していて 200) は環境依存なので固定しない。
    expect($caught)->not->toBeInstanceOf(StrayRequestException::class);
    expect(StrayHttpRequestGuard::drainForAssertion())->toBe([]);
});

test('case E: アプリ層の catch (Throwable) で握り潰しても accumulator に残る', function (): void {
    // FxRateService::fetchFromFrankfurter / AwsSnsSignatureVerifier::certClient を再現。
    // ★本 guard の存在意義そのもの。preventStrayRequests 単体ではここが静かに緑になる。
    try {
        Http::get('https://api.frankfurter.dev/v1/latest');
    } catch (Throwable) {
        // swallow (production の可用性設計を模す)
    }

    expect(StrayHttpRequestGuard::drainForAssertion())
        ->toHaveCount(1, '握り潰されても accumulator は記録する (= afterEach で必ず赤くなる)');
});

test('case F: flushAndFailIfStray() は accumulator 非空で throw し finally で clear する', function (): void {
    try {
        Http::get('https://api.frankfurter.dev/v1/latest');
    } catch (Throwable) {
        // swallow
    }

    $threw = false;
    try {
        StrayHttpRequestGuard::flushAndFailIfStray();
    } catch (RuntimeException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('Stray outbound HTTP request detected');
    }
    expect($threw)->toBeTrue('flushAndFailIfStray must throw when accumulator is non-empty');

    // finally で accumulator が clear されていることを確認
    expect(StrayHttpRequestGuard::drainForAssertion())->toBe([]);
});

test('case G: install() は冪等 (2 回呼んでも 1 stray に対し記録は 1 件)', function (): void {
    StrayHttpRequestGuard::install($this->app);
    StrayHttpRequestGuard::install($this->app);

    try {
        Http::get('https://api.frankfurter.dev/v1/latest');
    } catch (Throwable) {
        // swallow
    }

    expect(StrayHttpRequestGuard::drainForAssertion())->toHaveCount(1);
});

test('case H: 許可パターンは loopback ホストだけに一致する', function (): void {
    $matches = static function (string $url): bool {
        foreach (StrayHttpRequestGuard::ALLOWED_URL_PATTERNS as $pattern) {
            if (Str::is($pattern, $url)) {
                return true;
            }
        }

        return false;
    };

    // 通すべきもの
    expect($matches('http://127.0.0.1'))->toBeTrue();
    expect($matches('http://127.0.0.1:8010/x/y?z=1'))->toBeTrue();
    expect($matches('http://localhost/health'))->toBeTrue();
    expect($matches('http://[::1]:8080/x'))->toBeTrue();

    // 通してはいけないもの (末尾ワイルドカード 1 本にしていたら全部 true になる)
    expect($matches('http://127.0.0.1.evil.example/'))->toBeFalse();
    expect($matches('http://localhost.evil.example/'))->toBeFalse();
    expect($matches('https://api.frankfurter.dev/v1/latest'))->toBeFalse();
    expect($matches('http://169.254.169.254/latest/meta-data/'))->toBeFalse();

    // ★userinfo 詐称は glob だけでは**通ってしまう**。これは pattern の欠陥ではなく
    //   Str::is() の表現力の限界であり、guard は第 2 層 (isSmuggledLoopbackUrl) で落とす。
    //   ここでは「glob 単体では防げない」という事実そのものを固定し、
    //   第 2 層を消したら case J が赤くなる構造にしておく。
    expect($matches('http://127.0.0.1:80@api.frankfurter.dev/'))
        ->toBeTrue('glob 照合だけで userinfo 詐称を弾けているなら第 2 層の前提が変わった (case J を見直すこと)');
    expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl('http://127.0.0.1:80@api.frankfurter.dev/'))
        ->toBeTrue();
    expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl('http://localhost:9@evil.example/x'))
        ->toBeTrue();

    // 本物の loopback は第 2 層でも通る (偽陽性側の固定)
    expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl('http://127.0.0.1:8010/x'))->toBeFalse();
    expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl('http://[::1]:8080/x'))->toBeFalse();
    expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl('http://localhost/health'))->toBeFalse();
    // 許可パターンに一致しない外部 URL は第 2 層の対象外 (framework 側が stray にする)
    expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl('https://api.frankfurter.dev/v1/latest'))
        ->toBeFalse();
});

test('case J: userinfo で loopback を騙る URL は stray として記録され送信されない', function (): void {
    // ★`http://127.0.0.1:80@api.frankfurter.dev/` は userinfo が `127.0.0.1:80`、
    //   実ホストは `api.frankfurter.dev` である (PHP 実測)。許可パターン
    //   'http://127.0.0.1:*' に glob 一致してしまうため、guard の第 2 層が無いと
    //   framework は stray 扱いせず**実際に外部へ送信する**。
    $smuggled = 'http://127.0.0.1:80@api.frankfurter.dev/';

    // ★全許可 fake を先に張る (S6 の「'*' fake でごまかさない」規律の**明示された例外**:
    //   このテストの主題は「どの URL であれ 1 本も出てはならない」ことの検証である)。
    //   これが load-bearing:
    //    - 第 2 層あり → 最外側 middleware が stub より先に throw する = 下の assert が成立。
    //    - 第 2 層なし → stub が '*' に一致して 200 を返し、例外も記録も出ずに**赤くなる**。
    //   fake が無いと、第 2 層が壊れたとき **回帰テスト自身が実際に外部へ送信して**しまう
    //   (redirect 先で framework 側の stray 例外が出るだけ) = 既定拒否を破る構造になる。
    Http::fake(['*' => Http::response('', 200)]);

    $threw = false;
    try {
        Http::connectTimeout(1)->timeout(1)->get($smuggled);
    } catch (StrayRequestException) {
        $threw = true;
    }

    expect($threw)->toBeTrue('userinfo 詐称が既定拒否を潜り抜けている');

    // 記録された URL は **userinfo 詐称の元 URL そのもの**であること
    // (第 2 層は stub より前で throw するため、書き換えの余地が無い)。
    $drained = StrayHttpRequestGuard::drainForAssertion();
    expect($drained)->toHaveCount(1)
        ->and($drained[0]['url'])->toBe($smuggled);
});

test('case I: async 経路でも stray が accumulator に記録される', function (): void {
    // Guzzle Client::requestAsync は同期 throw を rejection 化するが、それは本 middleware
    // より外側で起きるため try/catch で捕まる。async を使う呼び出しが将来増えたときに
    // 静かに素通りしないことを固定する。
    try {
        Http::async()->get('https://api.frankfurter.dev/v1/latest')->wait();
    } catch (Throwable) {
        // swallow
    }

    expect(StrayHttpRequestGuard::drainForAssertion())->not->toBe([]);
});
