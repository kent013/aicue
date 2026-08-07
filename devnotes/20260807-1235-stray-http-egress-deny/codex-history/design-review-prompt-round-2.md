# design-review Round 2

Round 1 の指摘 (Critical 0 / Warning 4 / Suggestion 2) をすべて**対応**しました (反論・見送りゼロ)。
以下に対応マトリクスと、詳細設計書の**修正後の該当箇所**を示します。
再レビューをお願いします。

---

# 対応マトリクス: design-review Round 1

全体判定 **CHANGES_REQUESTED**。[Critical] はゼロ。[Warning] 4 件・[Suggestion] 2 件。
**Warning は 4 件すべて対応した** (反論・見送りゼロ)。

## [Warning] S2 の self-test が tests/Pest.php のレーン既定に依存しており、実装順序 S2 → S1 → S3 と噛み合わない (S3 前に実通信するリスク)
- 判断: **対応する**
- 根拠: 指摘のとおり致命的。実装順序を「テストファースト」で S2 → S1 → S3 と定めた以上、
  S3 適用前に本ファイルを走らせる局面が必ず存在する。そのとき guard 未 install なら
  case A の `Http::get('https://api.frankfurter.dev/...')` は**実際に外へ出る**。
  「HTTP 出口を塞ぐ作業のために外部へ出る」は本末転倒であり、設計の欠陥。
  さらに自己検査は「guard が install されていれば何が起きるか」の契約テストなので、
  前提を自分で用意する方がテストとして自己完結する。
- 対応内容: `detailed-design.md` S2 の骨格に
  `beforeEach(function (): void { StrayHttpRequestGuard::install($this->app); });` を追加し、
  「レーン既定に依存しない」理由 (2 点) と「S3 後は二重 install になるが install() は冪等
  (case G が固定)」をコメントとして明記した。骨格のテスト一覧にも `beforeEach(...)` を追記。

## [Warning] case D の `127.0.0.1:9` は環境依存で flaky になりうる
- 判断: **対応する**
- 根拠: 「port 9 が閉じている」は OS / コンテナ / inetd 設定に依存する強い前提で、
  `--parallel` 実行の CI で偽赤を生む。加えて、そもそも case D の主眼は
  「**stray 判定を通過したか**」であって「接続がどう失敗したか」ではない。
  `ConnectionException` を固定していたのは主眼の取り違えだった。
- 対応内容: (1) `stream_socket_server('tcp://127.0.0.1:0')` で OS に一時ポートを割り当てさせ、
  `stream_socket_get_name()` でポート番号を取って close する形に変更。
  (2) assert を `expect($caught)->not->toBeInstanceOf(StrayRequestException::class)` に変更し、
  例外型を固定しない (接続成功でも成立する = close 後の再割当 TOCTOU にも耐える)。
  (3) テスト名を「(stray 判定を通過して送信段まで進む)」に改め、主眼を明示。
  (4) リスク表を書き換え (`ConnectionException` 前提の行を削除し、TOCTOU 耐性の行を追加)。
  (5) 不要になった `use Illuminate\Http\Client\ConnectionException;` を削除。

## [Warning] S4 の opt-out 検出が `preventStrayRequests(false)` の literal に寄りすぎ (逃げ道が残る)
- 判断: **対応する**
- 根拠: 指摘どおり。`preventStrayRequests($flag)` / `((bool) 0)` / 名前付き引数
  `prevent: false` はすべて既定拒否を外せる。deny-by-default gate が
  「特定の書き方だけ」を見るのは自己矛盾で、gate の存在意義を損なう。
  Codex が提示した「無引数だけを許可し、それ以外は inventory 必須」が最も単純かつ安全。
- 対応内容: `strayHttpEgressOptOutSites()` の契約を
  「`preventStrayRequests` の直後の `(` から対応する `)` までが**空白のみ**なら許可、
  引数が 1 文字でもあれば opt-out として inventory 必須」へ変更。
  `allowStrayRequests(` は引数を問わず全件対象 (null は prevent OFF、配列は許可集合の**置換**で
  merge ではないため、区別しない)。
  1 ファイル分の判定を純関数 `strayHttpEgressIsOptOutSource(string $source): bool` に切り出し、
  fixture でテストできる形にした。負のコントロール
  「preventStrayRequests の非 literal opt-out を書き方によらず検出する」を追加
  (literal / variable / cast / named / allow-null / allow-array の 6 形 + 無引数とコメントの
  非検出 2 形)。mutation 手順に M9 を追加。

## [Warning] gate が「install / flush / reset が本当に beforeEach / afterEach closure 内にあるか」を強く保証しない
- 判断: **対応する** (負のコントロール追加に留めず、判定ロジック自体を強化する)
- 根拠: Codex の修正案は「負のコントロールを足せば最低限固定できる」だったが、
  負のコントロールは**純関数が壊れた入力を検出できること**しか示さない。
  判定ロジックがオフセット前後関係のままなら、実ファイルで
  「beforeEach と afterEach の間だが closure の外」に install を書いた瞬間に偽緑になる。
  gate の目的 (レーン既定であることの保証) に対して弱すぎるので、ロジックを直す。
- 対応内容: 純関数 `strayHttpEgressClosureBody(string $code, int $openOffset): string` を新設し、
  `->beforeEach(` / `->afterEach(` 以降で最初に現れる `{` から**波括弧の対応を数えて**
  closure 本体を切り出す。`strayHttpEgressLaneViolations()` の契約を
  「install は beforeEach の**closure 本体内**」「flush / reset は afterEach の
  **closure 本体内**」へ変更した。負のコントロール
  「install が beforeEach closure の外にある配線を検出する」(欠落形と closure 外形の 2 fixture)
  を追加し、mutation 手順に M8 を追加。

## [Suggestion] S1: `localhost` 許可を残す理由を定数コメントに明示する
- 判断: **対応する**
- 根拠: 「127.0.0.1 で足りるなら `localhost` は名前解決依存の余計な許可では」という問いは
  次の担当も必ず持つ。答えを定数の隣に置かないと、いずれ削られて偽赤を生む。
- 対応内容: `ALLOWED_URL_PATTERNS` の docblock に理由を追記
  (表記揺れによる偽赤コストの方が大きい / 解決先が loopback でなければそもそも到達しない /
   `aicue.test` のような任意カスタムドメインは**入れない** = 許可集合を環境依存にしない)。

## [Suggestion] S3: 2 guard の flush で片方の詳細が落ちる旨をコメントに明記する
- 判断: **対応する**
- 根拠: 将来の調査効率に直結する 1 行。コストゼロ。
- 対応内容: `tests/Pest.php` の afterEach コメントを
  「**同時発生時は先に throw した guard の詳細だけが表示される**」と明示する形に書き換えた
  (Feature/Unit lane と Browser lane の両方)。

---

## 修正後の該当箇所 (詳細設計書からの抜粋)

### S1: `ALLOWED_URL_PATTERNS` の docblock (localhost を残す理由を追記)

```php
    /**
     * 自機宛て loopback の明示許可パターン (単一 source of truth)。
     *
     * ★`config('app.url')` の host は**含めない**。理由:
     *  (1) Browser lane の in-process サーバは常に 127.0.0.1 に bind する
     *      (pest-plugin-browser ServerManager::DEFAULT_HOST) ので loopback リテラルで足りる。
     *  (2) その in-process サーバは boot 時に config('app.url') を**実行中に書き換える**ため、
     *      beforeEach 時点の snapshot は Browser lane で古い値になる。
     *  (3) APP_URL は環境依存 (.env は http://aicue.test、.env.example は http://localhost)。
     *      許可集合を環境依存にすると Architecture gate が固定値を検査できず、
     *      「開発者の .env 次第で外部ドメインが許可される」穴になる。
     *
     * ★`localhost` / `[::1]` を残す理由 (127.0.0.1 だけで足りるのでは、への回答):
     *   Browser lane の in-process サーバは 127.0.0.1 で足りるが、テスト本体や将来の
     *   fake 基盤が `localhost` 表記の自機 URL を組み立てることは普通に起きる
     *   (config/mcp.php の allowed origins も `http://localhost` / `http://127.0.0.1` の
     *    両方を持つ)。`localhost` は名前解決に依存するが、**解決先が loopback でなければ
     *   そもそも到達しない**うえ、hosts を書き換えて `localhost` を外部へ向ける環境は
     *   本リポジトリの前提外である。表記揺れで偽赤を出すコストの方が大きいので 3 ホストを持つ。
     *   一方で `aicue.test` のような**任意のカスタムドメインは入れない** (解決先が
     *   loopback である保証が無く、許可集合が環境依存になるため)。
     *
     * ★末尾ワイルドカード 1 本 (`http://127.0.0.1*`) にはしない。
     *   Str::is() の glob では `http://127.0.0.1.evil.example/` まで通ってしまう。
     *   「ポート無し」「:ポート」「/パス」の 3 形で 1 ホストを覆う。
     *
     * @var list<non-empty-string>
     */
    public const ALLOWED_URL_PATTERNS = [
        'http://127.0.0.1',
        'http://127.0.0.1/*',
        'http://127.0.0.1:*',
        'https://127.0.0.1',
        'https://127.0.0.1/*',
        'https://127.0.0.1:*',
        'http://localhost',
        'http://localhost/*',
        'http://localhost:*',
        'https://localhost',
        'https://localhost/*',
        'https://localhost:*',
        'http://[::1]',
        'http://[::1]/*',
        'http://[::1]:*',
        'https://[::1]',
        'https://[::1]/*',
        'https://[::1]:*',
    ];


```

### S2: 自己検査の beforeEach (レーン既定に依存しない) と case D の書き換え

```php
/**
 * ★本ファイルは tests/Pest.php のレーン既定に**依存しない**。
 *
 * 理由 (Codex design-review Round 1 の Warning):
 *  (1) 実装順序が S2 (自己検査) → S1 (guard) → S3 (レーン配線) なので、S3 の前に
 *      本ファイルを走らせる局面が必ずある。そのとき guard 未 install だと
 *      case A の `Http::get('https://api.frankfurter.dev/...')` が**実通信に進む**。
 *      これは「guard を作る作業のために外部へ出る」という本末転倒である。
 *  (2) 自己検査は「guard が install されていれば何が起きるか」の契約テストであり、
 *      その前提を自分で用意する方がテストとして自己完結する。
 *  S3 適用後は二重 install になるが、install() は冪等 (case G が固定する)。
 */
beforeEach(function (): void {
    StrayHttpRequestGuard::install($this->app);
});

beforeEach(...);  // ← 本ファイル自身で guard を install する (レーン既定に依存しない)

```

```php
test('case D: loopback (127.0.0.1) は stray にならない (stray 判定を通過して送信段まで進む)', function (): void {
    // ★固定ポート (9 = discard 等) は「閉じている」前提が環境依存で flaky になる
    //   (Codex design-review Round 1 の Warning)。OS に一時ポートを割り当てさせ、
    //   すぐ close して「ほぼ確実に待ち受けが無いポート」を得る。
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


```

### S2: リスク表 (case D 関連の書き換え)

| framework 更新で handler stack の push 順が変わり、globalMiddleware が stub handler より内側になる | stray を観測できず accumulator が空 = 偽グリーン | S2 case E (握り潰し貫通) が behavioral に固定する。順序が変わった瞬間に赤くなる |
| `Http::fake()` が将来 prevent flag を reset するようになる | レーン既定が各テストの `Http::fake()` で無効化される | S2 case B が「fake 併用時も未 fake URL は stray になる」ことを固定する |
| `->retry(n)` を使う呼び出しで同じ stray が n 件記録される | 失敗メッセージが冗長になるだけ | 仕様として受容 (件数を summarize に出す)。dedupe は「今必要なものだけ作る」に反するため入れない |
| middleware が同一プロセスで積み上がる | 同じ stray を複数記録 | `install()` の冪等化 + S2 case G |

---

## S2: guard の自己検査 (framework 前提の behavioral 固定)

### 変更箇所

- ファイル: `tests/Feature/Support/StrayHttpRequestGuardTest.php` (**新規**)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource / DTO: **なし**
- テストファイル: 本ファイル自体が新規テスト。既存テストの更新は**なし**
  (`tests/Feature/Support/StrayLlmCallGuardTest.php` は触らない)

### 現行コード

存在しない (新規)。同型の見本は `tests/Feature/Support/StrayLlmCallGuardTest.php` (115 行、case A〜F)。

### 変更後コード (骨格)

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
 */

/**
 * ★本ファイルは tests/Pest.php のレーン既定に**依存しない**。
 *
 * 理由 (Codex design-review Round 1 の Warning):
 *  (1) 実装順序が S2 (自己検査) → S1 (guard) → S3 (レーン配線) なので、S3 の前に
 *      本ファイルを走らせる局面が必ずある。そのとき guard 未 install だと
 *      case A の `Http::get('https://api.frankfurter.dev/...')` が**実通信に進む**。
 *      これは「guard を作る作業のために外部へ出る」という本末転倒である。
 *  (2) 自己検査は「guard が install されていれば何が起きるか」の契約テストであり、
 *      その前提を自分で用意する方がテストとして自己完結する。
 *  S3 適用後は二重 install になるが、install() は冪等 (case G が固定する)。
 */
beforeEach(function (): void {
    StrayHttpRequestGuard::install($this->app);
});

beforeEach(...);  // ← 本ファイル自身で guard を install する (レーン既定に依存しない)
test('case A: 未 fake の外向き HTTP は StrayRequestException + accumulator 記録', ...);
test('case B: Http::fake([限定 URL]) 併用時、fake 対象は透過し未 fake の別 URL は stray になる', ...);
test('case C: Http::fake([*]) を張れば stray にならない', ...);
test('case D: loopback (127.0.0.1) は stray にならない (ConnectionException まで到達する)', ...);
test('case E: アプリ層の catch (Throwable) で握り潰しても accumulator に残る', ...);
test('case F: flushAndFailIfStray() は accumulator 非空で throw し finally で clear する', ...);
test('case G: install() は冪等 (2 回呼んでも 1 stray に対し記録は 1 件)', ...);
test('case H: 許可パターンは loopback ホストだけに一致する (127.0.0.1.evil.example を弾く)', ...);
test('case I: async 経路でも stray が accumulator に記録される', ...);
```

主要ケースの中身:

```php
test('case A: 未 fake の外向き HTTP は StrayRequestException + accumulator 記録', function (): void {
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

test('case D: loopback (127.0.0.1) は stray にならない (stray 判定を通過して送信段まで進む)', function (): void {
    // ★固定ポート (9 = discard 等) は「閉じている」前提が環境依存で flaky になる
    //   (Codex design-review Round 1 の Warning)。OS に一時ポートを割り当てさせ、
    //   すぐ close して「ほぼ確実に待ち受けが無いポート」を得る。
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

### PHPStan 適合チェック

> ⚠ `tests/` は `phpstan.neon` の `paths` 外 (再掲)。以下は手動チェックリスト。

- [x] 各 `test()` closure に `: void` を明示
- [x] `Throwable` を `use` せずグローバル参照する場合は `\Throwable` を書かない
      (`NoNonCompoundGlobalUseTest` の規約に合わせ、既存 `StrayLlmCallGuardTest` と同じく
       グローバル名前空間の `Throwable` / `RuntimeException` はそのまま書く)
- [x] `drainForAssertion()` の戻り値 shape に依存する assertion のみ書く (配列添字は
      `['method']` / `['url']` の 2 キーに限定)
- [x] DTO 返却は非該当

### テスト計画

**本施策自体がテスト**。実装順序として**最初に書き、赤を確認してから S1 に入る** (思考原則 5)。

- [ ] **赤の確認 (テストファースト)**: S1 実装前に本ファイルを追加し
      `vendor/bin/pest tests/Feature/Support/StrayHttpRequestGuardTest.php` を実行 →
      `Tests\Support\StrayHttpRequestGuard` が存在せず fatal で赤になることを確認
- [ ] 新規テスト: `tests/Feature/Support/StrayHttpRequestGuardTest.php` の case A〜I
      (上表の 9 ケース)
- [ ] 既存テスト `tests/Feature/Support/StrayLlmCallGuardTest.php` は**変更しない**
      (禁止事項 3: 既存テストの削除・上書き)。同ファイルは既に `beforeEach` で
      `Http::fake(['*' => …])` を張っているため、レーン既定 ON でも無改修で緑のまま
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (本ファイルは DB に触れない)
- [ ] Factory は使わない (モデルを作らない)

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| case D の loopback 接続が環境によって即座に refuse されずタイムアウト待ちになる | テストが 1 秒程度遅くなる | `connectTimeout(1)->timeout(1)` で上限を 1 秒に固定。全体で無視できる |
| case D で確保した一時ポートが close 後に別プロセスへ再割当される (TOCTOU) | 接続が成功して例外が出ない | assert の主眼を「`StrayRequestException` **ではない**」に置いたため、接続が成功しても成立する。`--parallel` 実行下でも安定する |
| CI コンテナで loopback 送信自体が禁止されている | 例外型が変わる | 型を固定していない (`StrayRequestException` でないことだけを見る) ので影響しない |
| case A/B/E/I が「実際に外へ出る」 | 外部到達 | 出ない。stray として遮断されるので socket は開かない |



### S3: tests/Pest.php の afterEach コメント (同時発生時の挙動を明示)

```php
    ->afterEach(function (): void {
        try {
            // stray が記録されていれば test を fail させる。
            // ★2 つの guard は順に flush する。**同時発生時は先に throw した guard の
            //   詳細だけが表示される** (もう一方の accumulator は finally の reset で
            //   捨てられる)。test は既に赤いので「静かに緑」にはならず、検出目的は達成される。
            //   両方を集約する仕組みは入れない (今必要なものだけ作る)。
            StrayLlmCallGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::flushAndFailIfStray();
        } finally {
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
            StrayHttpRequestGuard::reset();
        }
    })

```

### S4: closure 内包検査 / opt-out 検出強化 / 追加した負のコントロール

```php
/**
 * `->beforeEach(` / `->afterEach(` の**引数 closure の本体**を切り出す (純関数)。
 *
 * ★オフセット前後関係だけで判定すると「beforeEach と afterEach の間だが closure の外」に
 *   書かれた install を配線と誤認する (Codex design-review Round 1 の Warning)。
 *   `$openOffset` 以降で最初に現れる `{` から**波括弧の対応を数えて**本体を切り出し、
 *   その中に呼び出しがあることを要求する。
 *   入力は strayHttpEgressCode() 済み (コメント除去済み) なので、`{` が
 *   コメント/文字列由来で紛れる余地は実用上ない。
 */
function strayHttpEgressClosureBody(string $code, int $openOffset): string { /* 波括弧の対応で切り出す */ }

/**
 * レーン既定配線の違反一覧 (純関数)。
 *
 * 各チャンクについて:
 *  - `->beforeEach(` の **closure 本体内**に `StrayHttpRequestGuard::install(` がある
 *  - `->afterEach(` の **closure 本体内**に `StrayHttpRequestGuard::flushAndFailIfStray(` がある
 *  - 同じ closure 本体内に `StrayHttpRequestGuard::reset(` がある
 *  - `->beforeEach(` / `->afterEach(` がそもそも存在する
 * さらに STRAY_HTTP_EGRESS_REQUIRED_LANES が全て、いずれかのチャンクで覆われている。
 *
 * @param  list<array{lanes: list<string>, body: string}>  $chunks
 * @return list<string>
 */
function strayHttpEgressLaneViolations(array $chunks): array { /* … */ }


```

```php
/**
 * tests/ 配下で opt-out 呼び出しを持つファイル一覧 (リポジトリルート相対、ソート済み)。
 *
 * 検出対象 (**deny-by-default**):
 *  - `allowStrayRequests(` — 引数を問わず全件。
 *    null 渡しは prevent 自体を OFF にし、配列渡しは既定の許可集合を**置換**する
 *    (merge ではない: Factory::allowStrayRequests は array_values($only) 代入)。
 *    どちらもレーン既定を壊しうるので区別せず全部登録対象にする。
 *  - `preventStrayRequests(` に **引数がある**呼び出し全件。
 *    ★`preventStrayRequests(false)` の literal だけを見ると
 *      `preventStrayRequests($flag)` / `preventStrayRequests((bool) 0)` /
 *      `preventStrayRequests(prevent: false)` が素通りする
 *      (Codex design-review Round 1 の Warning)。
 *      **無引数 `preventStrayRequests()` だけを許可**し (レーン既定と同値の重複宣言)、
 *      引数が 1 文字でもあれば inventory 必須にする = 逃げ道を構造的に消す。
 *      判定は「`preventStrayRequests` の直後の `(` から対応する `)` までが空白のみか」。
 *
 * 走査は strayHttpEgressCode() でコメントを除去した後に行う
 * (コメント内の説明で偽赤にしない)。
 *
 * @return list<string>
 */
function strayHttpEgressOptOutSites(): array { /* Finder で tests/**\/*.php → strayHttpEgressCode() → 上記判定 */ }


```

```php
test('tests/Pest.php の全レーンが StrayHttpRequestGuard を既定配線していること', /* … */);
test('許可 URL パターンが loopback ホストだけに閉じていること', /* … */);
test('opt-out 呼び出しを持つファイルが全て exemption inventory に登録済みであること (deny-by-default)', /* … */);
test('exemption inventory に実在しないファイルが残っていないこと (形骸化ガード)', /* … */);
test('exemption の根拠が 30 文字以上であること', /* … */);
test('exemption 件数が上限 (exact fit) を超えていないこと', /* … */);

/*
 * 負のコントロール (実ファイルは書き換えない):
 * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
 */
test('負のコントロール: install を持たないレーンを検出する', /* … */);
test('負のコントロール: install が afterEach の後ろに来ている配線を検出する', /* … */);
test('負のコントロール: install が beforeEach closure の外にある配線を検出する', /* … */);
test('負のコントロール: flush はあるが reset が無い配線を検出する', /* … */);
test('負のコントロール: 必須レーン (Architecture) が 1 つも覆われていない場合を検出する', /* … */);
test('負のコントロール: コメント内の install 記述では配線と認めない', /* … */);
test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) を検出する', /* … */);
test('負のコントロール: 外部ドメインの許可パターンを検出する', /* … */);
test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', /* … */);
```


```

負のコントロールの中身 (代表 2 本):

```php
test('負のコントロール: コメント内の install 記述では配線と認めない', function (): void {
    // ★これが無いと「// StrayHttpRequestGuard::install($this->app); を入れる予定」という
    //   コメントだけで gate が緑になる (最も現実的な偽緑シナリオ)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            // StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            // StrayHttpRequestGuard::flushAndFailIfStray();
            // StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $chunks = strayHttpEgressLaneChunks(strayHttpEgressCode($fixture));
    $violations = strayHttpEgressLaneViolations($chunks);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) を検出する', function (): void {
    $violations = strayHttpEgressPatternViolations(['http://127.0.0.1*']);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('loopback に閉じていない');
});

test('負のコントロール: install が beforeEach closure の外にある配線を検出する', function (): void {
    // ★オフセット前後関係だけで判定する実装だと、これが「配線あり」で素通りする。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $this->withoutVite();
        })
        ->use(SomethingElse::class)
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;
    // install はどこにも無い形と、beforeEach と afterEach の「間」にある形の両方を見る
    $outside = str_replace(
        "->use(SomethingElse::class)",
        "->use(StrayHttpRequestGuard::install(\$app))",
        $fixture,
    );

    foreach (['none' => $fixture, 'outside-closure' => $outside] as $label => $source) {
        $violations = strayHttpEgressLaneViolations(
            strayHttpEgressLaneChunks(strayHttpEgressCode($source)),
        );
        expect($violations)->not->toBe([], "配線欠落 ({$label}) を検出できていない");
        expect(implode("\n", $violations))->toContain('install');
    }
});

test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', function (): void {
    // ★literal `false` だけを見る実装だと、下 3 つが素通りする。
    $optOuts = [
        'literal' => 'Http::preventStrayRequests(false);',
        'variable' => 'Http::preventStrayRequests($flag);',
        'cast' => 'Http::preventStrayRequests((bool) 0);',
        'named' => 'Http::preventStrayRequests(prevent: false);',
        'allow-null' => 'Http::allowStrayRequests();',
        'allow-array' => "Http::allowStrayRequests(['*']);",
    ];
    foreach ($optOuts as $label => $line) {
        expect(strayHttpEgressIsOptOutSource("<?php\n{$line}\n"))
            ->toBeTrue("opt-out ({$label}) を検出できていない");
    }

    // レーン既定と同値の重複宣言 (無引数) は opt-out ではない = 誤検出しない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\n"))->toBeFalse();
    // コメント内の記述も opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\n// Http::allowStrayRequests(['*']) は使わない\n"))
        ->toBeFalse();
});
```

> `strayHttpEgressIsOptOutSource(string $source): bool` は
> `strayHttpEgressOptOutSites()` が 1 ファイル分の判定に使う純関数として切り出す
> (fixture でテストできる形にするため。`strayHttpEgressOptOutSites()` は
> Finder でファイルを集めてこの純関数に渡すだけの薄い層にする)。



### S4: mutation 手順 (M8 / M9 を追加)

| # | mutation (一時変更 → 必ず復元) | 期待して赤くなるもの |
|---|------|------|
| M1 | `tests/Pest.php` の Feature/Unit lane から `StrayHttpRequestGuard::install($this->app);` を削除 | gate「全レーンが既定配線」 |
| M2 | 同 install 行を `->afterEach(` の後ろへ移動 | gate「全レーンが既定配線」(順序違反) |
| M3 | `ALLOWED_URL_PATTERNS` に `'https://api.frankfurter.dev/*'` を追加 | gate「許可パターンが loopback に閉じている」 |
| M4 | `ALLOWED_URL_PATTERNS` の `'http://127.0.0.1:*'` を `'http://127.0.0.1*'` に変更 | gate「許可パターン」 + S2 case H |
| M5 | `tests/Feature/Security/AuthThrottleCoverageTest.php` に `Http::allowStrayRequests(['*']);` を追加 | gate「opt-out が inventory 登録済み」 |
| M6 | guard の `__invoke` から `self::$strayRequests[] = …` を削除 | S2 case A / E / I (握り潰し貫通) |
| M7 | inventory から `tests/Support/StrayHttpRequestGuard.php` を削除 / 架空パスを追加 | gate「未登録」/ gate「形骸化ガード」 |
| M8 | `tests/Pest.php` の install 行を `beforeEach` closure の外 (`->use(...)` の直後など) へ移動 | gate「全レーンが既定配線」(closure 内包検査) |
| M9 | `tests/Feature/Security/ThrottleExemptionPremiseTest.php` の `Http::preventStrayRequests();` を `Http::preventStrayRequests($flag);` に変更 | gate「opt-out が inventory 登録済み」(非 literal 検出) |


---

## 再レビューの依頼事項

1. Round 1 の Warning 4 件が実際に解消しているか (特に S4 の closure 内包検査と opt-out 検出は、負のコントロールを足すだけでなく**判定ロジック自体**を直しています)。
2. 修正によって新たに生まれた穴が無いか。とくに:
   - S2 の `beforeEach` 自前 install と S3 のレーン既定が二重になる件 (install() の冪等性に依存)
   - case D の「例外型を固定しない」assert が、逆に「stray として弾かれたのに緑になる」ことは無いか (`StrayRequestException` は `RuntimeException` の直接のサブクラスであり、`not->toBeInstanceOf(StrayRequestException::class)` は stray のときに必ず失敗する、という理解で正しいか)
   - `strayHttpEgressClosureBody()` の波括弧カウントが、closure 本体内の文字列リテラルやヒアドキュメント中の `{` `}` で壊れないか (入力はコメント除去済みだが文字列リテラルは残る)。壊れうるなら PhpToken ベースの深度カウントへ寄せるべきか
   - 無引数 `preventStrayRequests()` を許可し続ける判断 (既存 5 箇所を残す) が deny-by-default の穴にならないか
3. 全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
