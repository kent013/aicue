# impl-review Round 3

Round 2 の [Critical] 1 件 / [Warning] 2 件をすべて対応した。判定をお願いする。

## 対応マトリクス (Round 2)

# 対応マトリクス: impl-review Round 2

## [Critical] case J は第 2 層が壊れたとき「実通信してから」赤くなる (回帰テスト自身が deny-by-default を破る)

- 判断: **対応する**
- 根拠: 指摘のとおり。M11 の実測ログがまさにその証拠で、第 2 層を外すと 1 本目が
  `api.frankfurter.dev` へ実際に送信され、301 リダイレクト先で framework 側の
  `StrayRequestException` が出ていた。「既定拒否を守るテストが既定拒否を破る」構造は
  本 TODO の目的に真っ向から反する。提案された全許可 fake は識別力を落とさずこれを解く。
- 対応内容:
  - case J の先頭に `Http::fake(['*' => Http::response('', 200)])` を追加。
    - 第 2 層あり → 最外側 middleware が stub より**先に** throw → 元 URL が accumulator に 1 件。
    - 第 2 層なし → stub が `'*'` に一致して 200 を返し、**例外も記録も送信も無い** → 赤。
  - これに伴い assertion の依存が redirect 挙動から切れたため、
    「元 URL 完全一致でなければ区別できない」旨の長いコメントは不要になったので簡潔化した
    (完全一致 assertion 自体は残す)。
  - S6 の「`'*'` fake でごまかさない」規律に対しては、詳細設計が認めている例外
    (「テストの主題が『外部呼び出しをしない』ことの検証で、どの URL であれ出たら異常な場合」)
    に該当する旨をコメントに明記した。
  - 再実測: baseline 10/10 緑 / M11 適用時 9/10 (case J のみ赤)。
    `mutation-evidence.md` §M11 を更新した。

## [Warning] `__invoke(callable $handler)` の callable signature 不足 (将来 tests を PHPStan 対象にしたとき)

- 判断: **対応する**
- 根拠: 詳細設計の「型注釈は解析対象に入っているかのように厳密に書く」方針に一致する指摘。
- 対応内容: `@param callable(RequestInterface, array<string, mixed>): mixed $handler` を追加。

## [Warning] `$m[1]` が PHPStan で shape narrowing されない (gate の LOOPBACK_HOSTS 1:1 テスト)

- 判断: **対応する**
- 根拠: 指摘のとおり Pest の `expect()` は静的解析上の narrowing にならない。
- 対応内容: `preg_match(...) !== 1` を明示分岐して `RuntimeException` を throw する形に変更し、
  そのあとで `$matches[1]` を参照するようにした (変数名も `$m` → `$matches` へ)。

## [参考] Browser lane 未実行の残余リスク

- 判断: **受容 (対応不能)**。
- 根拠: この環境には Playwright のブラウザバイナリが無く (`~/.cache/ms-playwright` 不在)、
  `composer test:browser` は本差分の有無に関わらず chromium / webkit 両レーンとも
  「Playwright is outdated」で全 14 本失敗する。差分起因ではない。
  Browser lane の配線自体は gate (`STRAY_HTTP_EGRESS_REQUIRED_LANES` に `Browser` を含む) が
  ソースレベルで強制しており、実動作確認だけが残余リスクとして残る。
  この事実は最終報告の blockers に明記する。


---

## 修正後の全文: tests/Feature/Support/StrayHttpRequestGuardTest.php

```php
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

```

## 参考: guard 本体と gate の現時点の差分 (HEAD からの累積)

```diff
diff --git a/tests/Architecture/StrayHttpEgressLaneGateTest.php b/tests/Architecture/StrayHttpEgressLaneGateTest.php
new file mode 100644
index 0000000..e65a420
--- /dev/null
+++ b/tests/Architecture/StrayHttpEgressLaneGateTest.php
@@ -0,0 +1,1004 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Finder\Finder;
+use Tests\Support\Security\StrayHttpEgressExemption;
+use Tests\Support\StrayHttpRequestGuard;
+
+/*
+ * Architecture invariant: テストレーンの HTTP 出口が既定拒否であること (deny-by-default)。
+ *
+ * 背景 (SoT = devnotes/20260807-1235-stray-http-egress-deny/conceptual-design.md):
+ * 裁定 AG-105 は「テストレーンの既定として Http::preventStrayRequests() を常時有効にする」
+ * を必須とし、「テスト内で局所的に張って外す形は既定と認めない」と明示している。
+ * 本 gate は tests/Pest.php をソース走査して**レーン既定であること**を機械強制する。
+ *
+ * ★解析は PhpToken でコメントを落としてから行う。文字列 grep にすると
+ *   「本 gate の説明コメント」自身や tests/Pest.php の日本語コメントで偽緑になる
+ *   (PcreUnicodeModifierGateTest / GlobalTestLockInventoryTest と同じ作法)。
+ *
+ * ★本 gate は「素の main では赤にならない」種類のテストである。空振りしていないことは
+ *   (a) fixture ベースの負のコントロール (下部) と
+ *   (b) 実装時の mutation 手順 (詳細設計 S4 §mutation) の 2 本で担保する。
+ */
+
+/** 既定配線が必須のレーン。 */
+const STRAY_HTTP_EGRESS_REQUIRED_LANES = ['Feature', 'Unit', 'Architecture', 'Browser'];
+
+/** opt-out 根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+const STRAY_HTTP_EGRESS_REASON_MIN_LENGTH = 30;
+
+/**
+ * exemption 件数の上限。**現在値ちょうど** (exact fit)。
+ * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに opt-out できる枠」
+ *   になる。exact fit なら次の 1 本が必ずこの数値を変える差分として現れる。
+ */
+const STRAY_HTTP_EGRESS_EXEMPTION_CAP = 1;
+
+/**
+ * 走査対象から外すファイル (走査器自身)。
+ * ★本 gate は検査語 (`allowStrayRequests` 等) をパターン文字列として持つため、
+ *   自分を走査すると必ず自己一致する。GlobalTestLockInventoryTest が
+ *   「ライブラリ本体は対象外」としたのと同じ扱い。
+ */
+const STRAY_HTTP_EGRESS_SCANNER_SELF = 'tests/Architecture/StrayHttpEgressLaneGateTest.php';
+
+/**
+ * userinfo 詐称で loopback を騙る URL (実測で許可パターンに glob 一致するもの)。
+ * ★`http://127.0.0.1:80@api.frankfurter.dev/` は userinfo が `127.0.0.1:80` で
+ *   **実ホストは `api.frankfurter.dev`**。guard の第 2 層がこれを stray に落とす契約。
+ */
+const STRAY_HTTP_EGRESS_SMUGGLED_URLS = [
+    'http://127.0.0.1:80@api.frankfurter.dev/',
+    'https://127.0.0.1:443@api.frankfurter.dev/v1/latest',
+    'http://localhost:9@evil.example/x',
+    'https://localhost:1@169.254.169.254/latest/meta-data/',
+    // ★`http://[::1]:1@evil.example/` は**そもそも URI としてパースできない**ため入れない
+    //   (Guzzle Uri が "Unable to parse URI" を投げる = リクエストを組み立てられない)。
+    //   すなわち `[::1]:*` パターン経由の userinfo 詐称は到達不能である。
+];
+
+/**
+ * opt-out 呼び出しを持つことが正しいと裁定したファイルの inventory
+ * (型付き + 具体的根拠必須、単一 source of truth)。
+ *
+ * @return array<string, array{StrayHttpEgressExemption, non-empty-string}>
+ */
+function strayHttpEgressOptOutExemptions(): array
+{
+    return [
+        'tests/Support/StrayHttpRequestGuard.php' => [
+            StrayHttpEgressExemption::GuardDefinitionSite,
+            'レーン既定 guard 本体。Http::allowStrayRequests() を呼ぶのは ALLOWED_URL_PATTERNS '
+            .'(loopback リテラルのみ) を設定するためであり、allowStrayRequests(null) や '
+            .'preventStrayRequests(false) は呼ばない = 既定拒否そのものは外していない。',
+        ],
+    ];
+}
+
+/**
+ * PHP ソースを **トークン列** へ落とす (純関数)。以降の解析はすべてこの列の上で行う。
+ *
+ * `PhpToken::tokenize()` した結果から `T_COMMENT` / `T_DOC_COMMENT` を取り除くだけ
+ * (空白は保持する — 位置関係の判定には使わないが、抜き出した本体を人間が読める形で
+ *  エラーメッセージに載せるため)。
+ *
+ * ★**文字列 grep も、正規化した文字列に対する括弧カウントもやめた**。
+ *   文字列に落とす方式は (a) literal 中の括弧で対応を誤認する、(b) literal 中の
+ *   `function` をキーワードと誤認する、(c) 名前と `(` の間の空白/コメントで判定を外す、
+ *   という 3 種類の穴を**個別に塞ぎ続ける**必要がある。トークン列で扱えば
+ *   文字列の中身は文字列系トークンの内側に保持され、構文上の補間境界は専用トークン
+ *   (`T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES`) で識別できる。
+ *   キーワードは `T_FUNCTION` / `T_STATIC` の**トークン ID** で一意に判定でき、
+ *   空白は「有意トークン」を辿るだけで自然に飛ばせる。穴の種類が構造的に消える。
+ *
+ * @return list<PhpToken>
+ */
+function strayHttpEgressTokens(string $source): array
+{
+    return array_values(array_filter(
+        PhpToken::tokenize($source),
+        static fn (PhpToken $token): bool => ! $token->is([T_COMMENT, T_DOC_COMMENT]),
+    ));
+}
+
+/**
+ * `$from` 以降で最初の**有意トークン** (`T_WHITESPACE` 以外) の index を返す (純関数)。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function strayHttpEgressNextSignificant(array $tokens, int $from): ?int
+{
+    $total = count($tokens);
+    for ($i = max($from, 0); $i < $total; $i++) {
+        if (! $tokens[$i]->is(T_WHITESPACE)) {
+            return $i;
+        }
+    }
+
+    return null;
+}
+
+/**
+ * `$openIndex` (開き括弧のトークン index) に対応する閉じ括弧の index を返す (純関数)。
+ * トークン列上で深度を数えるため、文字列**内容**の括弧は文字列系トークンの内側にあり影響しない。
+ *
+ * ★波括弧 (`{` / `}`) を数えるときは、**補間の開始トークンも開始側に含める**:
+ *
+ *     $token->text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)
+ *
+ *   補間の**終端は必ず単独の `}` トークン**であるのに対し、**開始側は 2 種類の専用トークン**に
+ *   分かれる。開始側を数え落とすと深度が片側だけ減り、**closure の終端を早く見つけてしまう**。
+ *
+ *   ★実測 (PHP 8.4.24) で確認した `text` の値 — ここが判断の分かれ目なので事実を残す:
+ *
+ *     "value={$json}"  → T_ENCAPSED_AND_WHITESPACE("value=") / T_CURLY_OPEN("{")
+ *                        / T_VARIABLE("$json") / }("}")
+ *     "value=${json}"  → T_ENCAPSED_AND_WHITESPACE("value=") / T_DOLLAR_OPEN_CURLY_BRACES("${")
+ *                        / T_STRING_VARNAME("json") / }("}")
+ *
+ *   すなわち `T_CURLY_OPEN` の `text` は `"{"` なので `text === '{'` でも偶然拾えるが、
+ *   `T_DOLLAR_OPEN_CURLY_BRACES` の `text` は `"${"` で拾えない。実際に深度が壊れるのは
+ *   後者 (`"${json}"` 形) である。前者を id でも判定するのは、text 一致に依存した暗黙の
+ *   前提を契約から消すため (将来 `text` の表現が変わっても壊れない)。
+ *
+ *   終了側 (単独 `}`) は通常どおり深度を 1 減らすだけでよい。
+ *   丸括弧 (`(` / `)`) の探索ではこの追加処理を行わない (補間に丸括弧の専用トークンは無い)。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @param  non-empty-string  $open  `(` または `{`
+ * @param  non-empty-string  $close  `)` または `}`
+ */
+function strayHttpEgressMatchingIndex(array $tokens, int $openIndex, string $open, string $close): ?int
+{
+    $braces = $open === '{';
+    $depth = 0;
+    $total = count($tokens);
+
+    for ($i = $openIndex; $i < $total; $i++) {
+        $token = $tokens[$i];
+
+        if ($token->text === $open
+            || ($braces && ($token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)))
+        ) {
+            $depth++;
+
+            continue;
+        }
+
+        if ($token->text === $close) {
+            $depth--;
+            if ($depth === 0) {
+                return $i;
+            }
+        }
+    }
+
+    return null;
+}
+
+/**
+ * トークン列を `pest()->extend(` 単位のチャンクへ分解する (純関数)。
+ * レーン名は `->in(` の引数にある `T_CONSTANT_ENCAPSED_STRING` から取る
+ * (文字列 grep ではなくトークンから取るので、コメント内の `->in('Feature')` に反応しない)。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @return list<array{lanes: list<string>, tokens: list<PhpToken>}>
+ */
+function strayHttpEgressLaneChunks(array $tokens): array
+{
+    $chunks = [];
+    $total = count($tokens);
+
+    for ($i = 0; $i < $total; $i++) {
+        if (! $tokens[$i]->is(T_STRING) || strtolower($tokens[$i]->text) !== 'pest') {
+            continue;
+        }
+        $paren = strayHttpEgressNextSignificant($tokens, $i + 1);
+        if ($paren === null || $tokens[$paren]->text !== '(') {
+            continue;
+        }
+
+        // 文の終端 (深度 0 の `;`) までを 1 チャンクとする。
+        // closure 本体の `;` は括弧/波括弧の内側にあるため深度 0 にならない。
+        $depth = 0;
+        $end = null;
+        for ($j = $i; $j < $total; $j++) {
+            $token = $tokens[$j];
+            if (in_array($token->text, ['(', '{', '['], true)
+                || $token->is(T_CURLY_OPEN)
+                || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)
+            ) {
+                $depth++;
+
+                continue;
+            }
+            if (in_array($token->text, [')', '}', ']'], true)) {
+                $depth--;
+
+                continue;
+            }
+            if ($depth === 0 && $token->text === ';') {
+                $end = $j;
+                break;
+            }
+        }
+        if ($end === null) {
+            continue;
+        }
+
+        /** @var list<PhpToken> $chunkTokens */
+        $chunkTokens = array_values(array_slice($tokens, $i, $end - $i + 1));
+        $chunks[] = [
+            'lanes' => strayHttpEgressLanesOf($chunkTokens),
+            'tokens' => $chunkTokens,
+        ];
+
+        $i = $end;
+    }
+
+    return $chunks;
+}
+
+/**
+ * チャンクの `->in('Feature', 'Unit')` からレーン名を取り出す (純関数)。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @return list<string>
+ */
+function strayHttpEgressLanesOf(array $tokens): array
+{
+    $total = count($tokens);
+
+    for ($i = 0; $i < $total; $i++) {
+        if (! $tokens[$i]->is(T_OBJECT_OPERATOR)) {
+            continue;
+        }
+        $name = strayHttpEgressNextSignificant($tokens, $i + 1);
+        if ($name === null || ! $tokens[$name]->is(T_STRING) || $tokens[$name]->text !== 'in') {
+            continue;
+        }
+        $paren = strayHttpEgressNextSignificant($tokens, $name + 1);
+        if ($paren === null || $tokens[$paren]->text !== '(') {
+            continue;
+        }
+        $close = strayHttpEgressMatchingIndex($tokens, $paren, '(', ')');
+        if ($close === null) {
+            continue;
+        }
+
+        $lanes = [];
+        for ($j = $paren + 1; $j < $close; $j++) {
+            if ($tokens[$j]->is(T_CONSTANT_ENCAPSED_STRING)) {
+                $lanes[] = trim($tokens[$j]->text, "'\"");
+            }
+        }
+
+        return $lanes;
+    }
+
+    return [];
+}
+
+/**
+ * chunk 内の `->{$hook}(...)` の**引数が直接 closure リテラルであること**を確認し、
+ * その本体トークン列を返す (純関数)。確認できなければ **null を返して fail-closed** にする。
+ *
+ * 契約:
+ *  1. `->` + `T_STRING($hook)` の並びを見つけ、その次の有意トークンが `(` であること。
+ *  2. `(` の**次の有意トークン**が `T_FUNCTION`、または `T_STATIC` に続く `T_FUNCTION` であること。
+ *     ★ここが要。「引数**内**のどこかにある `function` を拾う」実装だと
+ *       `->beforeEach(wrap(function () { install(...); }))` を配線済みと誤認する。
+ *  3. その `T_FUNCTION` に対応する closure 本体の `{` を
+ *     `strayHttpEgressMatchingIndex()` で閉じ、本体トークン列を返す。
+ *  4. closure の `}` の**次の有意トークン**が、1 で開いた `(` に対応する `)` であること
+ *     (= 引数は closure ちょうど 1 個。カンマ区切りの追加引数は**許可しない**)。
+ *
+ * ★アロー関数 `fn () => …` は**受け付けない** (null を返す)。
+ *   レーン配線は複数文 (install / flush + reset) を要するのでブロック本体が必須であり、
+ *   2 つの closure 形を両方パースする価値が無い (今必要なものだけ作る)。
+ *
+ * @param  list<PhpToken>  $tokens  chunk のトークン列
+ * @param  non-empty-string  $hook  'beforeEach' または 'afterEach'
+ * @return list<PhpToken>|null
+ */
+function strayHttpEgressHookBody(array $tokens, string $hook): ?array
+{
+    $total = count($tokens);
+
+    for ($i = 0; $i < $total; $i++) {
+        if (! $tokens[$i]->is(T_OBJECT_OPERATOR)) {
+            continue;
+        }
+        $name = strayHttpEgressNextSignificant($tokens, $i + 1);
+        if ($name === null || ! $tokens[$name]->is(T_STRING) || $tokens[$name]->text !== $hook) {
+            continue;
+        }
+
+        $paren = strayHttpEgressNextSignificant($tokens, $name + 1);
+        if ($paren === null || $tokens[$paren]->text !== '(') {
+            return null;
+        }
+        $parenClose = strayHttpEgressMatchingIndex($tokens, $paren, '(', ')');
+        if ($parenClose === null) {
+            return null;
+        }
+
+        $head = strayHttpEgressNextSignificant($tokens, $paren + 1);
+        if ($head === null) {
+            return null;
+        }
+        if ($tokens[$head]->is(T_STATIC)) {
+            $head = strayHttpEgressNextSignificant($tokens, $head + 1);
+            if ($head === null) {
+                return null;
+            }
+        }
+        if (! $tokens[$head]->is(T_FUNCTION)) {
+            return null;
+        }
+
+        // closure のシグネチャ (引数 / use / 戻り型) を読み飛ばし、本体の `{` を見つける。
+        $bodyOpen = null;
+        for ($j = $head + 1; $j < $parenClose; $j++) {
+            if ($tokens[$j]->text === '{') {
+                $bodyOpen = $j;
+                break;
+            }
+        }
+        if ($bodyOpen === null) {
+            return null;
+        }
+
+        $bodyClose = strayHttpEgressMatchingIndex($tokens, $bodyOpen, '{', '}');
+        if ($bodyClose === null) {
+            return null;
+        }
+
+        // 引数は closure ちょうど 1 個であること (追加引数を許可しない)
+        if (strayHttpEgressNextSignificant($tokens, $bodyClose + 1) !== $parenClose) {
+            return null;
+        }
+
+        /** @var list<PhpToken> $body */
+        $body = array_values(array_slice($tokens, $bodyOpen + 1, $bodyClose - $bodyOpen - 1));
+
+        return $body;
+    }
+
+    return null;
+}
+
+/**
+ * トークン列に `StrayHttpRequestGuard::{$method}(` の**呼び出し**があるか (純関数)。
+ *
+ * クラス名トークン (`T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`) →
+ * `T_DOUBLE_COLON` → `T_STRING($method)` → 次の有意トークンが `(` という並びで判定する。
+ * ★文字列 grep にしないのが load-bearing: literal 中の同名テキストは
+ *   `T_CONSTANT_ENCAPSED_STRING` 1 個なので一致しない = コメントや説明文で偽緑にならない。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @param  non-empty-string  $method
+ */
+function strayHttpEgressCallsGuard(array $tokens, string $method): bool
+{
+    $total = count($tokens);
+
+    for ($i = 0; $i < $total; $i++) {
+        $token = $tokens[$i];
+        if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+            continue;
+        }
+        $class = $token->text;
+        if ($class !== 'StrayHttpRequestGuard' && ! str_ends_with($class, '\\StrayHttpRequestGuard')) {
+            continue;
+        }
+
+        $colon = strayHttpEgressNextSignificant($tokens, $i + 1);
+        if ($colon === null || ! $tokens[$colon]->is(T_DOUBLE_COLON)) {
+            continue;
+        }
+        $name = strayHttpEgressNextSignificant($tokens, $colon + 1);
+        if ($name === null || ! $tokens[$name]->is(T_STRING) || $tokens[$name]->text !== $method) {
+            continue;
+        }
+        $paren = strayHttpEgressNextSignificant($tokens, $name + 1);
+        if ($paren !== null && $tokens[$paren]->text === '(') {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * レーン既定配線の違反一覧 (純関数)。
+ *
+ * 各チャンクについて:
+ *  - `beforeEach` hook body が `StrayHttpRequestGuard::install(` を**呼んで**いる
+ *  - `afterEach` hook body が `flushAndFailIfStray(` と `reset(` を呼んでいる
+ *  - hook body が null (hook が無い / 引数が closure リテラルでない / 追加引数がある) なら
+ *    **違反として扱う** (fail-closed。取り出せないものを「たぶん大丈夫」にしない)
+ * さらに STRAY_HTTP_EGRESS_REQUIRED_LANES が全て、**違反の無いチャンク**で覆われている。
+ *
+ * @param  list<array{lanes: list<string>, tokens: list<PhpToken>}>  $chunks
+ * @return list<string>
+ */
+function strayHttpEgressLaneViolations(array $chunks): array
+{
+    $violations = [];
+    $covered = [];
+
+    foreach ($chunks as $chunk) {
+        $label = $chunk['lanes'] === [] ? '(レーン不明)' : implode(',', $chunk['lanes']);
+        $chunkViolations = [];
+
+        $before = strayHttpEgressHookBody($chunk['tokens'], 'beforeEach');
+        if ($before === null) {
+            $chunkViolations[] = "[{$label}] beforeEach の closure リテラル本体を取り出せない "
+                .'(hook 不在 / closure リテラルでない / 追加引数あり) ため '
+                .'StrayHttpRequestGuard::install() の配線を確認できない';
+        } elseif (! strayHttpEgressCallsGuard($before, 'install')) {
+            $chunkViolations[] = "[{$label}] beforeEach の closure 本体で "
+                .'StrayHttpRequestGuard::install() を呼んでいない';
+        }
+
+        $after = strayHttpEgressHookBody($chunk['tokens'], 'afterEach');
+        if ($after === null) {
+            $chunkViolations[] = "[{$label}] afterEach の closure リテラル本体を取り出せない "
+                .'(hook 不在 / closure リテラルでない / 追加引数あり) ため '
+                .'StrayHttpRequestGuard::flushAndFailIfStray() / reset() の配線を確認できない';
+        } else {
+            if (! strayHttpEgressCallsGuard($after, 'flushAndFailIfStray')) {
+                $chunkViolations[] = "[{$label}] afterEach の closure 本体で "
+                    .'StrayHttpRequestGuard::flushAndFailIfStray() を呼んでいない';
+            }
+            if (! strayHttpEgressCallsGuard($after, 'reset')) {
+                $chunkViolations[] = "[{$label}] afterEach の closure 本体で "
+                    .'StrayHttpRequestGuard::reset() を呼んでいない';
+            }
+        }
+
+        if ($chunkViolations === []) {
+            foreach ($chunk['lanes'] as $lane) {
+                $covered[] = $lane;
+            }
+        }
+
+        foreach ($chunkViolations as $violation) {
+            $violations[] = $violation;
+        }
+    }
+
+    foreach (STRAY_HTTP_EGRESS_REQUIRED_LANES as $lane) {
+        if (! in_array($lane, $covered, true)) {
+            $violations[] = "必須レーン {$lane} が StrayHttpRequestGuard の既定配線で覆われていない";
+        }
+    }
+
+    return $violations;
+}
+
+/**
+ * 許可パターンが loopback ホストだけに閉じているかの違反一覧 (純関数)。
+ *
+ * 許容する形は `scheme://host` / `scheme://host/*` / `scheme://host:*` の 3 形のみ。
+ * host は 127.0.0.1 / localhost / [::1] に限る。
+ * これにより `http://127.0.0.1*` (末尾ワイルドカード) も `https://api.example.com/*` も弾かれる。
+ *
+ * @param  list<string>  $patterns
+ * @return list<string>
+ */
+function strayHttpEgressPatternViolations(array $patterns): array
+{
+    $violations = [];
+    foreach ($patterns as $pattern) {
+        if (preg_match('#^https?://(?:127\.0\.0\.1|localhost|\[::1\])(?:/\*|:\*)?$#u', $pattern) !== 1) {
+            $violations[] = "許可パターンが loopback に閉じていない: {$pattern}";
+        }
+    }
+
+    return $violations;
+}
+
+/**
+ * 1 ファイル分の opt-out 判定 (純関数。fixture でテストできる形に切り出す)。
+ *
+ * 検出対象 (**deny-by-default**):
+ *  - `allowStrayRequests` の呼び出し — 引数を問わず全件。
+ *    null 渡しは prevent 自体を OFF にし、配列渡しは既定の許可集合を**置換**する
+ *    (merge ではない: `Factory::allowStrayRequests` は `array_values($only)` 代入)。
+ *  - `preventStrayRequests` の呼び出しのうち **引数があるもの**全件。
+ *    ★`preventStrayRequests(false)` の literal だけを見ると
+ *      `preventStrayRequests($flag)` / `((bool) 0)` / `preventStrayRequests(prevent: false)` が
+ *      素通りする。**引数ゼロだけを許可**し (レーン既定と同値の重複宣言)、
+ *      有意トークンが 1 個でもあれば inventory 必須にする = 逃げ道を構造的に消す。
+ */
+function strayHttpEgressIsOptOutSource(string $source): bool
+{
+    $tokens = strayHttpEgressTokens($source);
+    $total = count($tokens);
+
+    for ($i = 0; $i < $total; $i++) {
+        $token = $tokens[$i];
+        if (! $token->is(T_STRING)) {
+            continue;
+        }
+        if ($token->text !== 'allowStrayRequests' && $token->text !== 'preventStrayRequests') {
+            continue;
+        }
+
+        $paren = strayHttpEgressNextSignificant($tokens, $i + 1);
+        if ($paren === null || $tokens[$paren]->text !== '(') {
+            continue;
+        }
+
+        if ($token->text === 'allowStrayRequests') {
+            return true;
+        }
+
+        $close = strayHttpEgressMatchingIndex($tokens, $paren, '(', ')');
+        if ($close === null) {
+            // 対応する `)` が取れない = 解析できない。fail-closed で opt-out 扱いにする。
+            return true;
+        }
+        if (strayHttpEgressNextSignificant($tokens, $paren + 1) !== $close) {
+            return true; // 引数が 1 個以上ある
+        }
+    }
+
+    return false;
+}
+
+/**
+ * tests/ 配下で opt-out 呼び出しを持つファイル一覧 (リポジトリルート相対、ソート済み)。
+ * Finder でファイルを集め `strayHttpEgressIsOptOutSource()` に渡すだけの薄い層。
+ * 走査器自身 (STRAY_HTTP_EGRESS_SCANNER_SELF) は除外する。
+ *
+ * @return list<string>
+ */
+function strayHttpEgressOptOutSites(): array
+{
+    $root = base_path();
+    $finder = Finder::create()->files()->in($root.'/tests')->name('*.php');
+
+    $sites = [];
+    foreach ($finder as $file) {
+        $relative = str_replace($root.'/', '', (string) $file->getRealPath());
+        if ($relative === STRAY_HTTP_EGRESS_SCANNER_SELF) {
+            continue;
+        }
+        $source = file_get_contents((string) $file->getRealPath());
+        expect($source)->toBeString("テストソースを読めない: {$relative}");
+        /** @var string $source */
+        if (strayHttpEgressIsOptOutSource($source)) {
+            $sites[] = $relative;
+        }
+    }
+
+    sort($sites);
+
+    return $sites;
+}
+
+test('tests/Pest.php の全レーンが StrayHttpRequestGuard を既定配線していること', function (): void {
+    $source = file_get_contents(base_path('tests/Pest.php'));
+    expect($source)->toBeString();
+    /** @var string $source */
+    $chunks = strayHttpEgressLaneChunks(strayHttpEgressTokens($source));
+
+    expect($chunks)->not->toBe([], 'tests/Pest.php から pest()->extend(...) チャンクを抽出できない');
+
+    $violations = strayHttpEgressLaneViolations($chunks);
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('許可 URL パターンが loopback ホストだけに閉じていること', function (): void {
+    $violations = strayHttpEgressPatternViolations(StrayHttpRequestGuard::ALLOWED_URL_PATTERNS);
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('許可判定が userinfo 詐称で loopback を騙る URL を拒否すること (第 2 層)', function (): void {
+    // ★`ALLOWED_URL_PATTERNS` の `:*` は Str::is() では任意文字列に展開されるため、
+    //   `http://127.0.0.1:80@api.frankfurter.dev/` (userinfo=127.0.0.1:80 / 実ホスト=外部) が
+    //   **glob 単体では一致してしまう**。glob には「以降に @ を含まない」を表現する手段が無いので、
+    //   guard はパース済みホストによる第 2 層を持つ契約になっている。
+    //   本 gate はその契約 (= 許可集合が実質 loopback に閉じていること) を機械強制する。
+    foreach (STRAY_HTTP_EGRESS_SMUGGLED_URLS as $url) {
+        expect(StrayHttpRequestGuard::matchesAllowedPattern($url))
+            ->toBeTrue("glob だけで弾けているなら第 2 層の前提が変わった: {$url}");
+        expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl($url))
+            ->toBeTrue("userinfo 詐称を第 2 層で拒否できていない: {$url}");
+    }
+
+    // 本物の loopback は通す (偽陽性側の固定)。第 2 層が「全部拒否」に退化していたらここが赤くなる。
+    foreach (['http://127.0.0.1', 'http://127.0.0.1:8010/x?y=1', 'https://localhost/health', 'http://[::1]:8080/x'] as $url) {
+        expect(StrayHttpRequestGuard::matchesAllowedPattern($url))->toBeTrue($url);
+        expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl($url))->toBeFalse($url);
+    }
+});
+
+test('LOOPBACK_HOSTS が ALLOWED_URL_PATTERNS のホスト部と 1:1 対応していること', function (): void {
+    // 片方だけ増やすと「pattern では許可されるが第 2 層で必ず落ちる」死んだ許可、または逆に
+    // 「第 2 層は通すが pattern に無い」無意味な host が生まれる。単一 source of truth を機械固定する。
+    $hosts = [];
+    foreach (StrayHttpRequestGuard::ALLOWED_URL_PATTERNS as $pattern) {
+        // ★Pest の expect() は PHPStan の shape narrowing にならないため、
+        //   `!== 1` を明示分岐して throw する (そのあとの $matches[1] が string に確定する)。
+        $matches = [];
+        if (preg_match('#^https?://(127\.0\.0\.1|localhost|\[::1\])#u', $pattern, $matches) !== 1) {
+            throw new RuntimeException("許可パターンからホスト部を取り出せない: {$pattern}");
+        }
+        $hosts[] = $matches[1];
+    }
+    $hosts = array_values(array_unique($hosts));
+    sort($hosts);
+
+    $declared = StrayHttpRequestGuard::LOOPBACK_HOSTS;
+    sort($declared);
+
+    expect($hosts)->toBe($declared);
+});
+
+test('opt-out 呼び出しを持つファイルが全て exemption inventory に登録済みであること (deny-by-default)', function (): void {
+    $registered = array_keys(strayHttpEgressOptOutExemptions());
+    $unregistered = array_values(array_diff(strayHttpEgressOptOutSites(), $registered));
+
+    expect($unregistered)->toBe([], implode(PHP_EOL, array_map(
+        static fn (string $path): string => "opt-out 呼び出しが inventory 未登録: {$path} "
+            .'(Http::fake([...]) で解くか、strayHttpEgressOptOutExemptions() へ理由付きで登録する)',
+        $unregistered,
+    )));
+});
+
+test('exemption inventory に実在しないファイルが残っていないこと (形骸化ガード)', function (): void {
+    $sites = strayHttpEgressOptOutSites();
+
+    foreach (strayHttpEgressOptOutExemptions() as $path => $entry) {
+        expect(file_exists(base_path($path)))->toBeTrue("inventory のファイルが実在しない: {$path}");
+        expect(in_array($path, $sites, true))
+            ->toBeTrue("inventory に登録されているが opt-out 呼び出しを持たない (登録を外すこと): {$path}");
+    }
+});
+
+test('exemption の根拠が 30 文字以上であること', function (): void {
+    foreach (strayHttpEgressOptOutExemptions() as $path => [$kind, $reason]) {
+        expect($kind)->toBeInstanceOf(StrayHttpEgressExemption::class);
+        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(
+            STRAY_HTTP_EGRESS_REASON_MIN_LENGTH,
+            "exemption の根拠が短すぎる ({$path}): {$reason}",
+        );
+    }
+});
+
+test('exemption 件数が上限 (exact fit) を超えていないこと', function (): void {
+    expect(count(strayHttpEgressOptOutExemptions()))
+        ->toBeLessThanOrEqual(
+            STRAY_HTTP_EGRESS_EXEMPTION_CAP,
+            'exemption を増やすには cap を明示的に引き上げる差分が必要 (再レビューの強制)',
+        );
+});
+
+/*
+ * 負のコントロール (実ファイルは書き換えない):
+ * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
+ */
+
+test('負のコントロール: install を持たないレーンを検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            $this->withoutVite();
+        })
+        ->afterEach(function (): void {
+            StrayHttpRequestGuard::flushAndFailIfStray();
+            StrayHttpRequestGuard::reset();
+        })
+        ->in('Feature', 'Unit', 'Architecture', 'Browser');
+    PHP;
+
+    $violations = strayHttpEgressLaneViolations(
+        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
+    );
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('install');
+});
+
+test('負のコントロール: install が afterEach 側にしかない配線を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            $this->withoutVite();
+        })
+        ->afterEach(function (): void {
+            StrayHttpRequestGuard::install($this->app);
+            StrayHttpRequestGuard::flushAndFailIfStray();
+            StrayHttpRequestGuard::reset();
+        })
+        ->in('Feature', 'Unit', 'Architecture', 'Browser');
+    PHP;
+
+    $violations = strayHttpEgressLaneViolations(
+        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
+    );
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('install');
+});
+
+test('負のコントロール: install が hook closure の外にある配線を検出する', function (): void {
+    // 「beforeEach と afterEach の間にあれば OK」という位置ベースの実装だと素通りする形。
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            $this->withoutVite();
+        })
+        ->use(StrayHttpRequestGuard::install($app))
+        ->afterEach(function (): void {
+            StrayHttpRequestGuard::flushAndFailIfStray();
+            StrayHttpRequestGuard::reset();
+        })
+        ->in('Feature', 'Unit', 'Architecture', 'Browser');
+    PHP;
+
+    $violations = strayHttpEgressLaneViolations(
+        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
+    );
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('install');
+});
+
+test('負のコントロール: flush はあるが reset が無い配線を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            StrayHttpRequestGuard::install($this->app);
+        })
+        ->afterEach(function (): void {
+            StrayHttpRequestGuard::flushAndFailIfStray();
+        })
+        ->in('Feature', 'Unit', 'Architecture', 'Browser');
+    PHP;
+
+    $violations = strayHttpEgressLaneViolations(
+        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
+    );
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('reset');
+});
+
+test('負のコントロール: 必須レーン (Architecture) が 1 つも覆われていない場合を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            StrayHttpRequestGuard::install($this->app);
+        })
+        ->afterEach(function (): void {
+            StrayHttpRequestGuard::flushAndFailIfStray();
+            StrayHttpRequestGuard::reset();
+        })
+        ->in('Feature', 'Unit', 'Browser');
+    PHP;
+
+    $violations = strayHttpEgressLaneViolations(
+        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
+    );
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('Architecture');
+});
+
+test('負のコントロール: コメント内の install 記述では配線と認めない', function (): void {
+    // ★これが無いと「// StrayHttpRequestGuard::install($this->app); を入れる予定」という
+    //   コメントだけで gate が緑になる (最も現実的な偽緑シナリオ)。
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            // StrayHttpRequestGuard::install($this->app);
+        })
+        ->afterEach(function (): void {
+            // StrayHttpRequestGuard::flushAndFailIfStray();
+            // StrayHttpRequestGuard::reset();
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $violations = strayHttpEgressLaneViolations(
+        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
+    );
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('install');
+});
+
+test('負のコントロール: 文字列リテラル中の install 記述では配線と認めない', function (): void {
+    // ★トークン ID ではなく文字列 grep で判定する実装だと、これが素通りする。
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            $todo = 'StrayHttpRequestGuard::install($this->app);';
+        })
+        ->afterEach(function (): void {
+            $todo = 'StrayHttpRequestGuard::flushAndFailIfStray(); StrayHttpRequestGuard::reset();';
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $violations = strayHttpEgressLaneViolations(
+        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
+    );
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('install');
+});
+
+test('負のコントロール: hook 引数がネストした closure の場合を配線と認めない', function (): void {
+    // ★「引数**内**のどこかにある function を拾う」実装だと素通りする。beforeEach に渡るのは
+    //   wrap(...) の戻り値であり、この closure が hook として登録される保証は無い。
+    //   引数が closure リテラルでない形 ($callback 変数渡し) も同様に fail-closed。
+    $wrapped = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(wrap(function (): void {
+            StrayHttpRequestGuard::install($this->app);
+        }))
+        ->afterEach(function (): void {
+            StrayHttpRequestGuard::flushAndFailIfStray();
+            StrayHttpRequestGuard::reset();
+        })
+        ->in('Feature', 'Unit', 'Architecture', 'Browser');
+    PHP;
+
+    $variable = str_replace(
+        "wrap(function (): void {\n        StrayHttpRequestGuard::install(\$this->app);\n    })",
+        '$callback',
+        $wrapped,
+    );
+
+    // アロー関数も受け付けない (ブロック本体が必須 = 契約どおり fail-closed)
+    $arrow = str_replace(
+        "wrap(function (): void {\n        StrayHttpRequestGuard::install(\$this->app);\n    })",
+        'fn () => StrayHttpRequestGuard::install($this->app)',
+        $wrapped,
+    );
+
+    // str_replace が空振りしていたら 3 形とも同じ入力になり、テストが空振りする
+    expect($variable)->not->toBe($wrapped);
+    expect($arrow)->not->toBe($wrapped);
+
+    foreach (['wrapped' => $wrapped, 'variable' => $variable, 'arrow' => $arrow] as $label => $source) {
+        $violations = strayHttpEgressLaneViolations(
+            strayHttpEgressLaneChunks(strayHttpEgressTokens($source)),
+        );
+        expect($violations)->not->toBe([], "hook 引数の形 ({$label}) を fail-closed にできていない");
+        expect(implode("\n", $violations))->toContain('install');
+    }
+});
+
+test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', function (): void {
+    // ★正しい配線が literal 由来の括弧で偽赤にならないこと (偽陽性側の固定)。
+    // ★`${json}` 形 (T_DOLLAR_OPEN_CURLY_BRACES) を必ず含める。`{$json}` 形だけだと
+    //   T_CURLY_OPEN の text が "{" のため補間開始を数え落とす実装でも緑になり、
+    //   この負のコントロールが空振りする。
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            $json = '{"enabled":true}';
+            $unbalanced = '} ) { (';
+            $interpolated = "value={$json}";
+            $legacyInterpolated = "value=${json}";
+            $doc = <<<'INNER'
+            { unbalanced brace in heredoc
+            INNER;
+            StrayHttpRequestGuard::install($this->app);
+        })
+        ->afterEach(function (): void {
+            StrayHttpRequestGuard::flushAndFailIfStray();
+            StrayHttpRequestGuard::reset();
+        })
+        ->in('Feature', 'Unit', 'Architecture', 'Browser');
+    PHP;
+
+    $violations = strayHttpEgressLaneViolations(
+        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
+    );
+    expect($violations)->toBe([], 'literal 由来の括弧で closure の終端を誤認している');
+});
+
+test('strayHttpEgressMatchingIndex: 補間の } を closure 終端と誤認しない', function (): void {
+    // ★アルゴリズムの核を単体で固定する。
+    //   補間開始トークンを開始側に数えない実装だと、返る index が補間の `}` になり
+    //   closure 本体が途中で切れる。
+    //
+    // ★入力は 2 形とも回す。**赤を出せるのは `${json}` 形だけ**である:
+    //   実測 (PHP 8.4.24) で T_CURLY_OPEN の text は "{" なので `{$json}` 形は
+    //   text 比較だけの実装でも偶然通る = それだけで固定すると空振りテストになる。
+    //   T_DOLLAR_OPEN_CURLY_BRACES の text は "${" で text 比較に掛からない。
+    //   両方入れるのは「2 形とも契約どおり」を示すため (前者は回帰の保険)。
+    $sources = [
+        'dollar-open-curly (この形だけが修正前の実装で赤くなる)' => '<?php function () { $a = "value=${json}"; guard(); }',
+        'curly-open' => '<?php function () { $a = "value={$json}"; guard(); }',
+    ];
+
+    foreach ($sources as $label => $source) {
+        $tokens = strayHttpEgressTokens($source);
+
+        $open = null;
+        foreach ($tokens as $i => $token) {
+            if ($token->text === '{') { // closure 本体の `{` (補間開始トークンより前にある)
+                $open = $i;
+                break;
+            }
+        }
+        expect($open)->not->toBeNull($label);
+        /** @var int $open */
+        $close = strayHttpEgressMatchingIndex($tokens, $open, '{', '}');
+        expect($close)->not->toBeNull($label);
+        /** @var int $close */
+
+        // 対応先は closure 末尾の `}` = その後ろに有意トークンが残らない
+        expect(strayHttpEgressNextSignificant($tokens, $close + 1))->toBeNull($label);
+        // 本体に guard() 呼び出しが含まれている (補間の } で切れていない)
+        // ★Pest の toContain() は可変長 needle でメッセージ引数を取らないため、
+        //   ラベルを失わないよう str_contains + toBeTrue(message) で書く。
+        $body = array_slice($tokens, $open + 1, $close - $open - 1);
+        $bodyText = implode('', array_map(static fn (PhpToken $t): string => $t->text, $body));
+        expect(str_contains($bodyText, 'guard'))
+            ->toBeTrue("{$label}: 補間の } を closure 終端と誤認し本体が途中で切れている");
+    }
+});
+
+test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) と外部ドメインを検出する', function (): void {
+    foreach (['http://127.0.0.1*', 'https://api.frankfurter.dev/*', '*', 'http://127.0.0.1.evil.example/*'] as $pattern) {
+        $violations = strayHttpEgressPatternViolations([$pattern]);
+        expect($violations)->not->toBe([], "許可パターン ({$pattern}) を検出できていない");
+        expect(implode("\n", $violations))->toContain('loopback に閉じていない');
+    }
+
+    // 正しい 3 形は違反にしない (偽陽性側の固定)
+    expect(strayHttpEgressPatternViolations([
+        'http://127.0.0.1', 'http://127.0.0.1/*', 'http://127.0.0.1:*', 'https://[::1]:*',
+    ]))->toBe([]);
+});
+
+test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', function (): void {
+    // ★literal `false` だけを見る実装だと variable / cast / named が素通りする。
+    $optOuts = [
+        'literal' => 'Http::preventStrayRequests(false);',
+        'variable' => 'Http::preventStrayRequests($flag);',
+        'cast' => 'Http::preventStrayRequests((bool) 0);',
+        'named' => 'Http::preventStrayRequests(prevent: false);',
+        'spaced-comment' => 'Http::preventStrayRequests /* 理由 */ (false);',
+        'nested-paren' => "Http::preventStrayRequests(str_contains(\$s, ')'));",
+        'allow-null' => 'Http::allowStrayRequests();',
+        'allow-array' => "Http::allowStrayRequests(['*']);",
+    ];
+    foreach ($optOuts as $label => $line) {
+        expect(strayHttpEgressIsOptOutSource("<?php\n{$line}\n"))
+            ->toBeTrue("opt-out ({$label}) を検出できていない");
+    }
+});
+
+test('負のコントロール: 名前と ( の間の空白/コメント・引数中の ) で opt-out 判定を誤らない', function (): void {
+    // 誤検出側 (false であるべきもの) を固定する。
+    // レーン既定と同値の重複宣言 (無引数) は opt-out ではない
+    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\n"))->toBeFalse();
+    // 空白・改行を跨いだ無引数も opt-out ではない
+    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests\n    (\n    );\n"))
+        ->toBeFalse();
+    // 無引数呼び出しの後ろに別の括弧があっても opt-out と誤検出しない
+    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\nfoo(bar());\n"))
+        ->toBeFalse();
+    // コメント内・文字列リテラル内の記述も opt-out ではない
+    expect(strayHttpEgressIsOptOutSource("<?php\n// Http::allowStrayRequests(['*']) は使わない\n"))
+        ->toBeFalse();
+    expect(strayHttpEgressIsOptOutSource("<?php\n\$doc = 'Http::allowStrayRequests([]) は禁止';\n"))
+        ->toBeFalse();
+});
diff --git a/tests/Support/StrayHttpRequestGuard.php b/tests/Support/StrayHttpRequestGuard.php
new file mode 100644
index 0000000..4df0a58
--- /dev/null
+++ b/tests/Support/StrayHttpRequestGuard.php
@@ -0,0 +1,304 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use Closure;
+use GuzzleHttp\Psr7\Uri;
+use Illuminate\Contracts\Foundation\Application;
+use Illuminate\Http\Client\Factory as HttpFactory;
+use Illuminate\Http\Client\StrayRequestException;
+use Illuminate\Support\Str;
+use InvalidArgumentException;
+use Psr\Http\Message\RequestInterface;
+use RuntimeException;
+
+/**
+ * テストレーンの HTTP 出口を既定拒否にし、握り潰されても検出できるようにする guard。
+ *
+ * 裁定 AG-105 の必須要件 (テストレーンの既定として preventStrayRequests を常時有効化 +
+ * 自機宛て loopback の明示許可) の実装。設計は devnotes/20260807-1235-stray-http-egress-deny/。
+ *
+ * 仕組み:
+ *  1. install() で Http Factory に preventStrayRequests + allowStrayRequests(loopback) を張る。
+ *  2. 同じ Factory の globalMiddleware に **自分自身** (invokable) を 1 本積む。
+ *     globalMiddleware は Guzzle handler stack の**最外側**に来る (stub handler は最内側) ため、
+ *     stub handler が同期 throw する StrayRequestException を確実に観測できる。
+ *  3. 観測した stray を static accumulator に記録してから **再 throw** する
+ *     (フレームワークの既定挙動を変えない)。
+ *  4. tests/Pest.php の afterEach が flushAndFailIfStray() を呼び、記録があれば test を fail させる。
+ *
+ * ★4 が本 guard の存在意義。FxRateService::fetchFromFrankfurter は catch (Throwable) で、
+ *   AwsSnsSignatureVerifier::certClient は catch (\Throwable) で例外を握り潰すため、
+ *   preventStrayRequests **だけ**では「fx_snapshot が null になる」等の挙動変化に化けて
+ *   テストが静かに緑のまま通る。accumulator があれば必ず赤くなる
+ *   (StrayLlmCallGuard で既に学習済みの失敗を繰り返さない)。
+ *
+ * **保証範囲**: Laravel HTTP client (`Illuminate\Http\Client`) 経由の出口**のみ**。
+ * 同一プロセス内でしか効かない。Socialite (Guzzle 直) / Stripe SDK / AWS SDK /
+ * Playwright ブラウザ自身の fetch / bug-hunt の別プロセス実行は**対象外**。
+ * さらに許可判定は**名前解決前の URL 文字列**照合なので、hosts / DNS の健全性
+ * (`localhost` が loopback に解決されること) は**保証しない** — それは前提である。
+ *
+ * ただし「URL 文字列の glob 照合だけ」では userinfo 詐称
+ * (`http://127.0.0.1:80@api.frankfurter.dev/`) が許可パターンを潜り抜けるため、
+ * **パース済みホストによる第 2 層** (`isSmuggledLoopbackUrl()`) を middleware で併用する。
+ */
+final class StrayHttpRequestGuard
+{
+    /**
+     * 自機宛て loopback の明示許可パターン (単一 source of truth)。
+     *
+     * ★`config('app.url')` の host は**含めない**。理由:
+     *  (1) Browser lane の in-process サーバは常に 127.0.0.1 に bind する
+     *      (pest-plugin-browser ServerManager::DEFAULT_HOST) ので loopback リテラルで足りる。
+     *  (2) その in-process サーバは boot 時に config('app.url') を**実行中に書き換える**ため、
+     *      beforeEach 時点の snapshot は Browser lane で古い値になる。
+     *  (3) APP_URL は環境依存 (.env は http://aicue.test、.env.example は http://localhost)。
+     *      許可集合を環境依存にすると Architecture gate が固定値を検査できず、
+     *      「開発者の .env 次第で外部ドメインが許可される」穴になる。
+     *
+     * ★`localhost` / `[::1]` を残す理由 (127.0.0.1 だけで足りるのでは、への回答):
+     *   Browser lane の in-process サーバは 127.0.0.1 で足りるが、テスト本体や将来の
+     *   fake 基盤が `localhost` 表記の自機 URL を組み立てることは普通に起きる
+     *   (config/mcp.php の allowed origins も `http://localhost` / `http://127.0.0.1` の
+     *    両方を持つ)。表記揺れで偽赤を出すコストの方が大きいので 3 ホストを持つ。
+     *
+     *   ★ただし判定機構の保証を誇張しない: `PendingRequest::isAllowedRequestUrl()` は
+     *   **名前解決前の URL 文字列**に対する `Str::is()` 照合である。したがって
+     *   `localhost` が外部 IP へ解決される環境では、この許可を通ったうえで
+     *   **実際に外部へ送信されうる**。つまり本 guard は
+     *   「`localhost` はテスト実行環境で loopback に解決される」を**前提として置いている**
+     *   だけであり、hosts / DNS の健全性は保証しない (保証範囲の注記にも明記する)。
+     *   その前提を置けないホスト名 (`aicue.test` のような任意のカスタムドメイン) は
+     *   **入れない** — 解決先の前提が置けず、許可集合も環境依存になるため。
+     *
+     * ★末尾ワイルドカード 1 本 (`http://127.0.0.1*`) にはしない。
+     *   Str::is() の glob では `http://127.0.0.1.evil.example/` まで通ってしまう。
+     *   「ポート無し」「:ポート」「/パス」の 3 形で 1 ホストを覆う。
+     *
+     * ★**この定数だけでは loopback を保証できない** (Codex impl-review Round 1 の Critical):
+     *   `:*` の `*` は Str::is() では任意文字列に展開されるため、**userinfo 詐称**
+     *   (`http://127.0.0.1:80@api.frankfurter.dev/` — userinfo が `127.0.0.1:80`、
+     *    実ホストは `api.frankfurter.dev`) が一致してしまう (PHP 実測で確認済み)。
+     *   glob には「ここから先に `@` を含まない」を表現する手段が無いので、
+     *   **パース済みホストによる第 2 層** (`isSmuggledLoopbackUrl()`) を guard 側に持つ。
+     *
+     * @var list<non-empty-string>
+     */
+    public const ALLOWED_URL_PATTERNS = [
+        'http://127.0.0.1',
+        'http://127.0.0.1/*',
+        'http://127.0.0.1:*',
+        'https://127.0.0.1',
+        'https://127.0.0.1/*',
+        'https://127.0.0.1:*',
+        'http://localhost',
+        'http://localhost/*',
+        'http://localhost:*',
+        'https://localhost',
+        'https://localhost/*',
+        'https://localhost:*',
+        'http://[::1]',
+        'http://[::1]/*',
+        'http://[::1]:*',
+        'https://[::1]',
+        'https://[::1]/*',
+        'https://[::1]:*',
+    ];
+
+    /**
+     * ALLOWED_URL_PATTERNS のホスト部と 1:1 対応する loopback ホストの正本。
+     *
+     * `isSmuggledLoopbackUrl()` の第 2 層判定に使う。PSR-7 の `Uri::getHost()` は
+     * IPv6 リテラルを角括弧付き (`[::1]`) で返すため、ここも角括弧付きで持つ (実測で確認)。
+     *
+     * @var list<non-empty-string>
+     */
+    public const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];
+
+    /** @var list<array{method: string, url: string}> */
+    private static array $strayRequests = [];
+
+    /**
+     * Pest beforeEach から呼ぶ。前テストの残留を clear したうえで guard を install する。
+     *
+     * 各テストの setUp() が refreshApplication() するため Factory は毎テスト新品だが、
+     * 「同一テスト内で 2 回呼ばれる」「将来 refreshApplication を経ないレーンが増える」
+     * ケースで middleware が二重登録されると同じ stray を 2 件記録してしまうので、
+     * **冪等**にしておく。
+     */
+    public static function install(Application $app): void
+    {
+        self::$strayRequests = [];
+
+        $factory = $app->make(HttpFactory::class);
+
+        // 既定拒否 + loopback だけを明示許可。allowStrayRequests(array) は置換なので、
+        // ここが許可集合の唯一の設定点になる。
+        $factory->preventStrayRequests();
+        $factory->allowStrayRequests(self::ALLOWED_URL_PATTERNS);
+
+        /** @var mixed $middleware */
+        foreach ($factory->getGlobalMiddleware() as $middleware) {
+            if ($middleware instanceof self) {
+                return; // 既に積まれている (冪等)
+            }
+        }
+
+        $factory->globalMiddleware(new self);
+    }
+
+    /**
+     * Pest afterEach から呼ぶ。stray が記録されていれば RuntimeException を throw して
+     * test を fail させる。アプリ側の try/catch で例外が握り潰されても、このパスで必ず赤くなる。
+     *
+     * accumulator は finally で必ず clear する (プロセス内の後続テストへの二次被害を防ぐ)。
+     */
+    public static function flushAndFailIfStray(): void
+    {
+        try {
+            if (self::$strayRequests === []) {
+                return;
+            }
+            throw new RuntimeException(
+                'Stray outbound HTTP request detected during test execution. '
+                .'Did you forget to call Http::fake([...]) in the test body? '
+                .'(test lanes deny outbound HTTP by default; only loopback is allowed)'
+                .PHP_EOL.self::summarize(self::$strayRequests)
+            );
+        } finally {
+            self::$strayRequests = [];
+        }
+    }
+
+    /**
+     * accumulator を空に戻す。afterEach の finally から呼び、flushAndFailIfStray() が
+     * throw した場合でも次テストへ残留を漏らさないことを保証する。
+     */
+    public static function reset(): void
+    {
+        self::$strayRequests = [];
+    }
+
+    /**
+     * self-test 用 drain。意図的に stray を発生させるテストで、global afterEach に
+     * 到達する前に accumulator を取り出して clear する。
+     *
+     * @return list<array{method: string, url: string}>
+     */
+    public static function drainForAssertion(): array
+    {
+        $drained = self::$strayRequests;
+        self::$strayRequests = [];
+
+        return $drained;
+    }
+
+    /**
+     * URL が ALLOWED_URL_PATTERNS のいずれかに glob 一致するか (純関数)。
+     * これは `PendingRequest::isAllowedRequestUrl()` が行う判定と**同じ意味論**であり、
+     * 「framework が loopback として通す URL 集合」を再現するために持つ。
+     */
+    public static function matchesAllowedPattern(string $url): bool
+    {
+        foreach (self::ALLOWED_URL_PATTERNS as $pattern) {
+            if (Str::is($pattern, $url)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /** ホスト名 (PSR-7 の `Uri::getHost()` 形式) が loopback リテラルか (純関数)。 */
+    public static function isLoopbackHost(string $host): bool
+    {
+        return in_array(strtolower($host), self::LOOPBACK_HOSTS, true);
+    }
+
+    /**
+     * 「許可パターンには一致するが、**パースした実ホストは loopback ではない**」URL か (純関数)。
+     *
+     * userinfo 詐称 (`http://127.0.0.1:80@api.frankfurter.dev/`) がこの形になる。
+     * glob だけの判定では framework が stray 扱いせず**実送信まで進んでしまう**ため、
+     * guard 側の第 2 層でこれを stray に落とす (Codex impl-review Round 1 の Critical)。
+     *
+     * ★この形の URL を `Http::fake()` で intercept したいケースは想定しない
+     *   (loopback を騙る外部ホストを意図的に叩くテストは存在しない)。仮に必要になったら
+     *   ここで fail するので、設計判断として必ず表面化する = fail-closed で正しい。
+     */
+    public static function isSmuggledLoopbackUrl(string $url): bool
+    {
+        if (! self::matchesAllowedPattern($url)) {
+            return false;
+        }
+
+        try {
+            $host = (new Uri($url))->getHost();
+        } catch (InvalidArgumentException) {
+            // パースできない URL が許可パターンに一致した = 想定外。fail-closed で拒否する。
+            return true;
+        }
+
+        return ! self::isLoopbackHost($host);
+    }
+
+    /**
+     * Guzzle global middleware 本体。handler stack の最外側に置かれ、
+     * 最内側の stub handler が同期 throw する StrayRequestException を観測する。
+     *
+     * ★`->otherwise()` (promise rejection) ではなく **try/catch** で捕える。
+     *   stub handler は promise を reject するのではなく同期 throw するため
+     *   (PendingRequest::buildStubHandler)。async / pool 経路でも、Guzzle Client が
+     *   rejection 化するのは本 middleware より**外側**なので try/catch で捕まる。
+     *
+     * @param  callable(RequestInterface, array<string, mixed>): mixed  $handler
+     */
+    public function __invoke(callable $handler): Closure
+    {
+        /**
+         * @param  array<string, mixed>  $options  Guzzle の転送オプション
+         */
+        return function (RequestInterface $request, array $options) use ($handler): mixed {
+            // 第 2 層: 許可パターンを userinfo 詐称で潜り抜ける URL を stray に落とす。
+            // framework の isAllowedRequestUrl() は URL 文字列の glob 照合しかしないため、
+            // ここで止めないと**実際に外部へ送信される**。
+            $url = (string) $request->getUri();
+            if (self::isSmuggledLoopbackUrl($url)) {
+                self::$strayRequests[] = [
+                    'method' => $request->getMethod(),
+                    'url' => $url,
+                ];
+
+                throw new StrayRequestException($url);
+            }
+
+            try {
+                return $handler($request, $options);
+            } catch (StrayRequestException $e) {
+                self::$strayRequests[] = [
+                    'method' => $request->getMethod(),
+                    'url' => (string) $request->getUri(),
+                ];
+
+                // フレームワークの既定挙動を変えない (記録するだけで握り潰さない)
+                throw $e;
+            }
+        };
+    }
+
+    /**
+     * @param  list<array{method: string, url: string}>  $requests
+     */
+    private static function summarize(array $requests): string
+    {
+        $lines = [];
+        foreach ($requests as $i => $request) {
+            $lines[] = sprintf('  [%d] %s %s', $i + 1, $request['method'], $request['url']);
+        }
+
+        return implode(PHP_EOL, $lines);
+    }
+}

```

---

## 再実測

- baseline: `composer test -- tests/Feature/Support/StrayHttpRequestGuardTest.php` → **10/10 passed**
- M11 (第 2 層 `isSmuggledLoopbackUrl` の事前拒否を `__invoke` から削除) →
  **9/10 (case J のみ赤)**。失敗メッセージは
  `userinfo 詐称が既定拒否を潜り抜けている / Failed asserting that false is true.`
  = fake が吸収して例外が出なくなったための赤であり、**外部への送信は発生しない**。
- `composer test` (全件): **3466 tests / 3464 passed / 2 skipped / 0 failed**
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: **passed**
- `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: **green**
- `composer test:browser`: 環境要因で実行不能 (Playwright ブラウザバイナリ未インストール。差分と無関係)

---

残っている指摘があれば挙げ、無ければ **全体判定: APPROVED** を 1 行で明示してほしい。
すでに決着済みの論点 (設計レビュー 6 ラウンドの確定事項、および Round 1/2 で対応済みの項目) は
蒸し返さないこと。
