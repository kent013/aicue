# design-review Round 3

Round 2 の必須修正 2 点 (+ Suggestion 2 件) をすべて対応しました (反論・見送りゼロ)。
対応マトリクスと修正後の該当箇所を示します。再レビューをお願いします。

---

# 対応マトリクス: design-review Round 2

全体判定 **CHANGES_REQUESTED**。[Critical] ゼロ。必須修正 2 点 (+ Suggestion 1 件)。
**すべて対応した** (反論・見送りゼロ)。

## [Warning] S1: `localhost` の説明が許可機構の保証を実態より強く書いている
- 判断: **対応する**
- 根拠: 指摘は完全に正しい。`PendingRequest::isAllowedRequestUrl()` は
  `Str::is($pattern, $url)` = **名前解決前の URL 文字列**照合であり、
  DNS/hosts で `localhost` が外部 IP に解決される環境では、許可判定を通過したうえで
  実際に外部へ送信されうる。「解決先が loopback でなければそもそも到達しない」は
  **事実として誤り**であり、設計文書に嘘を残すことは裁定 AG-105 が最も嫌う
  「保証範囲を誇張する」に該当する。
- 対応内容:
  1. `ALLOWED_URL_PATTERNS` の docblock から誤った 1 文を削除し、
     「判定は名前解決前の URL 文字列照合である」「本 guard は
     『localhost はテスト実行環境で loopback に解決される』を**前提として置いている**だけで、
     hosts / DNS の健全性は保証しない」に書き換えた。
  2. クラス docblock の「保証範囲」に「hosts / DNS の健全性は保証しない — それは前提である」を追記。
  3. S5 の `AGENTS.md` 追記案の「保証範囲 (誇張しない)」にも同じ限定を 1 行追加。
  4. `localhost` / `[::1]` を残す判断自体は維持 (表記揺れによる偽赤コストの方が大きい)。
     一方で「前提を置けないホスト名 (`aicue.test` 等) は入れない」理由をこの文脈に接続した。

## [Warning] S4: closure 本体の抽出を生文字列の `{` / `}` カウントで行うのは安全でない
- 判断: **対応する** (指摘どおり `PhpToken` ベースへ変更)
- 根拠: 指摘のとおり。コメントを落としても
  `'{"enabled":true}'` / `"value={$value}"` / heredoc 本文の裸の `{` が残り、
  現行 `tests/Pest.php` で偶然成立しても**将来無関係な文字列を足しただけで gate が壊れる**。
  deny-by-default gate が「たまたま動いている」状態は、gate が無いのと同程度に危険。
- 対応内容: `strayHttpEgressCode()` の契約を「コメント除去」から
  **「構文的に安全な解析入力への正規化」**へ変更した:
  - `T_COMMENT` / `T_DOC_COMMENT` → text を空にする
  - `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` (補間文字列と
    heredoc/nowdoc の本文) / `T_INLINE_HTML` → text 中の `{` `}` `(` `)` を `_` に**置換**
    (消さずに置換するのは、レーン名 `'Feature'` などの中身を残しつつ構造だけ無害化し、
     オフセットを 1:1 に保つため)
  - それ以外はそのまま連結
  これにより残る括弧は**すべて構文上の括弧**になる。補間の `{$x}` は
  `T_CURLY_OPEN` + `}` という構文トークンの対なので深度が必ず戻り、誤認しない。
  深度カウント自体は純関数
  `strayHttpEgressBalancedInner(string $code, int $openOffset, string $open, string $close): ?string`
  に切り出し、closure 本体抽出と引数抽出の両方から使う。
  負のコントロール「closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない」
  (JSON literal / 不均衡 literal `'} ) { ('` / 補間 / nowdoc を全部含む fixture が
   違反ゼロになること) を追加した。

## [Warning] S4: `preventStrayRequests()` の引数判定も対応括弧を生文字列で探索するなら同じ問題
- 判断: **対応する**
- 根拠: 同上。`Http::preventStrayRequests(str_contains($s, ')'))` のように引数中に `)` が
  あると「次の `)`」実装は空引数と誤認し、**opt-out を見逃す** (deny-by-default の穴)。
- 対応内容: `strayHttpEgressIsOptOutSource()` の判定を
  「`preventStrayRequests` 直後の `(` から `strayHttpEgressBalancedInner()` で求めた
  **対応する** `)` までが空白のみか」に変更 (`allowStrayRequests(` は引数を問わず全件対象のまま)。
  負のコントロール「引数中の文字列に `)` を含む opt-out を誤判定しない」を追加
  (誤検出側「無引数呼び出しの後ろに別の括弧があっても false」も併せて固定)。

## [Suggestion] S4: 無引数 `preventStrayRequests()` を許可する判断は妥当。ただし将来 framework の既定引数の意味が変わるリスクを契約テストで固定せよ
- 判断: **対応する**
- 根拠: guard 自身が `preventStrayRequests()` を無引数で呼ぶため、既定値が反転したら
  レーン既定が**静かに無効化される**。まさに本設計が防ごうとしている「静かに緑」。
- 対応内容: S2 case A のコメントに「本ケースは**無引数** `preventStrayRequests()` が
  拒否を有効化するという vendor の既定引数の意味に対する契約テストでもある」を明記した
  (case A は guard 経由で install した状態を検査するため、追加のテストは不要)。

## [Suggestion] S2: 骨格一覧の case D 名が旧名のまま / `beforeEach(...)` が擬似コードと分かりにくい
- 判断: **対応する**
- 根拠: 実装者が骨格一覧をそのまま貼る事故を防ぐ。コストゼロ。
- 対応内容: 骨格一覧の case D を「(stray 判定を通過して送信段まで進む)」へ同期し、
  一覧の直前に「↓ 以下は擬似コード (テスト名の一覧)。実体は本節の後半に示す。」を追記した。

## Round 2 で APPROVE 済みの施策 (S2 / S3 / S5 / S6)
- 判断: **見送る** (対応不要)
- 根拠: Round 1 の指摘が解消したことを確認済みとの評価。追加変更は入れない。

---

## 修正後の該当箇所 (詳細設計書からの抜粋)

### S1: `ALLOWED_URL_PATTERNS` の docblock とクラス docblock の保証範囲

```php
 * **保証範囲**: Laravel HTTP client (`Illuminate\Http\Client`) 経由の出口**のみ**。
 * 同一プロセス内でしか効かない。Socialite (Guzzle 直) / Stripe SDK / AWS SDK /
 * Playwright ブラウザ自身の fetch / bug-hunt の別プロセス実行は**対象外**。
 * さらに許可判定は**名前解決前の URL 文字列**照合なので、hosts / DNS の健全性
 * (`localhost` が loopback に解決されること) は**保証しない** — それは前提である。
 */

```

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
     *    両方を持つ)。表記揺れで偽赤を出すコストの方が大きいので 3 ホストを持つ。
     *
     *   ★ただし判定機構の保証を誇張しない: `PendingRequest::isAllowedRequestUrl()` は
     *   **名前解決前の URL 文字列**に対する `Str::is()` 照合である。したがって
     *   `localhost` が外部 IP へ解決される環境では、この許可を通ったうえで
     *   **実際に外部へ送信されうる**。つまり本 guard は
     *   「`localhost` はテスト実行環境で loopback に解決される」を**前提として置いている**
     *   だけであり、hosts / DNS の健全性は保証しない (保証範囲の注記にも明記する)。
     *   その前提を置けないホスト名 (`aicue.test` のような任意のカスタムドメイン) は
     *   **入れない** — 解決先の前提が置けず、許可集合も環境依存になるため。
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

### S5: AGENTS.md 追記案の「保証範囲 (誇張しない)」

```markdown
- **保証範囲 (誇張しない)**: 効くのは **`Http::` を呼んだプロセス内**の Laravel HTTP client
  経由の出口**だけ**。以下には**無言で効かない** —
  bug-hunt (`scripts/bug-hunt-shard.sh` の別プロセス実行) /
  Socialite (Guzzle 直) / Stripe SDK / AWS SDK /
  Browser lane で Playwright のブラウザ自身が出す外部取得。
  また許可判定は**名前解決前の URL 文字列**照合なので、`localhost` が loopback に
  解決されることは**前提であって保証ではない** (hosts / DNS の健全性は対象外)。
  この非対称を対称に書かない (「テストは外部に一切出ない」と書くのは嘘になる)

```

### S4: 解析入力の正規化 / 対応括弧探索 / closure 本体抽出 / opt-out 判定

```php
/**
 * PHP ソースを **構文的に安全な解析入力** へ正規化する (純関数)。
 *
 * PhpToken::tokenize() を通し、
 *  (1) `T_COMMENT` / `T_DOC_COMMENT` の text を空にする
 *      (行頭 `//` の正規表現除去では行末コメントや docblock を取りこぼす)。
 *  (2) **文字列リテラル系トークン** (`T_CONSTANT_ENCAPSED_STRING` /
 *      `T_ENCAPSED_AND_WHITESPACE` = 補間文字列と heredoc/nowdoc の本文 /
 *      `T_INLINE_HTML`) の text 中の `{` `}` `(` `)` を `_` に置換する。
 *  それ以外のトークンは text をそのまま連結する。
 *
 * ★(2) が Codex design-review Round 2 の Warning への回答である。
 *   生文字列で波括弧を数えると
 *     $json = '{"enabled":true}';   // 括弧が閉じない literal
 *     $fixture = <<<'PHP' { PHP;    // heredoc 本文の裸の {
 *   のようなコードで closure の終端を誤認する。トークン種別で literal と分かるものの
 *   括弧だけを潰せば、残る `{` `}` `(` `)` は**すべて構文上の括弧**になる。
 *   補間中の `{$x}` は `T_CURLY_OPEN` + `}` という**構文トークンの対**なので、
 *   潰さなくても深度は必ず戻る (誤認しない)。
 *
 * ★括弧を消すのではなく `_` に**置換**するのは、文字列の中身 (レーン名 `'Feature'` など)
 *   を残したまま構造だけ無害化するため。オフセットも 1:1 で保たれる。
 */
function strayHttpEgressCode(string $source): string { /* PhpToken ベースの正規化 */ }

/**
 * `$openOffset` (開き括弧の位置) から対応する閉じ括弧までの**内側**を返す (純関数)。
 * 入力は strayHttpEgressCode() 済みなので、括弧はすべて構文上のものである。
 *
 * @param  non-empty-string  $open   `(` または `{`
 * @param  non-empty-string  $close  `)` または `}`
 */
function strayHttpEgressBalancedInner(string $code, int $openOffset, string $open, string $close): ?string { /* 深度カウント */ }

/**
 * tests/Pest.php のコードを `pest()->extend(` 単位のチャンクへ分解する (純関数)。
 *
 * @return list<array{lanes: list<string>, body: string}>
 */
function strayHttpEgressLaneChunks(string $code): array { /* … */ }

/**
 * `->beforeEach(` / `->afterEach(` の**引数 closure の本体**を切り出す (純関数)。
 *
 * ★オフセット前後関係だけで判定すると「beforeEach と afterEach の間だが closure の外」に
 *   書かれた install を配線と誤認する (Codex design-review Round 1 の Warning)。
 *   `$openOffset` (= `->beforeEach(` の `(` の位置) 以降で最初に現れる `{` を起点に
 *   `strayHttpEgressBalancedInner()` で本体を切り出し、その中に呼び出しがあることを要求する。
 *   入力が strayHttpEgressCode() で正規化済みなので、literal 由来の裸の括弧は混ざらない
 *   (Codex design-review Round 2 の Warning)。
 */
function strayHttpEgressClosureBody(string $code, int $openOffset): ?string { /* strayHttpEgressBalancedInner に委譲 */ }

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
 *      判定は「`preventStrayRequests` の直後の `(` から**対応する** `)` までが空白のみか」で、
 *      対応括弧の探索は `strayHttpEgressBalancedInner()` (深度カウント) を使う。
 *      ★単純な「次の `)` を探す」実装は、引数中の文字列や closure に `)` が含まれると
 *        終端を誤認する (Codex design-review Round 2 の Warning)。
 *
 * 走査は strayHttpEgressCode() で正規化した後に行う
 * (コメント内の説明で偽赤にせず、literal 由来の括弧で終端を誤認しない)。
 *
 * @return list<string>
 */
function strayHttpEgressOptOutSites(): array { /* Finder で tests/**\/*.php → strayHttpEgressCode() → 上記判定 */ }


```

### S4: 負のコントロール一覧 (2 本追加)

```php
test('負のコントロール: install を持たないレーンを検出する', /* … */);
test('負のコントロール: install が afterEach の後ろに来ている配線を検出する', /* … */);
test('負のコントロール: install が beforeEach closure の外にある配線を検出する', /* … */);
test('負のコントロール: flush はあるが reset が無い配線を検出する', /* … */);
test('負のコントロール: 必須レーン (Architecture) が 1 つも覆われていない場合を検出する', /* … */);
test('負のコントロール: コメント内の install 記述では配線と認めない', /* … */);
test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) を検出する', /* … */);
test('負のコントロール: 外部ドメインの許可パターンを検出する', /* … */);
test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', /* … */);
test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', /* … */);
test('負のコントロール: 引数中の文字列に ) を含む opt-out を誤判定しない', /* … */);

```

### S4: 追加した負のコントロールの中身

test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', function (): void {
    // ★生文字列で波括弧を数える実装だと、これらで closure の終端を誤認して
    //   「install が本体内に無い」と偽赤になる (Codex design-review Round 2 の Warning)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $json = '{"enabled":true}';
            $unbalanced = '} ) { (';
            $interpolated = "value={$json}";
            $doc = <<<'INNER'
            { unbalanced brace in heredoc
            INNER;
            StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit', 'Architecture', 'Browser');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressCode($fixture)),
    );
    expect($violations)->toBe([], 'literal 由来の括弧で closure の終端を誤認している');
});

test('負のコントロール: 引数中の文字列に ) を含む opt-out を誤判定しない', function (): void {
    // 「次の ) を探す」実装だと空引数と誤認して opt-out を見逃す
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests(str_contains(\$s, ')'));\n"))
        ->toBeTrue();
    // 逆に、無引数呼び出しの**後ろ**に別の括弧があっても opt-out と誤検出しない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\nfoo(bar());\n"))
        ->toBeFalse();
});
```



### S2: case A のコメント (無引数 preventStrayRequests の契約テスト) と骨格一覧の同期

```php
// ↓ 以下は擬似コード (テスト名の一覧)。実体は本節の後半に示す。
beforeEach(...);  // ← 本ファイル自身で guard を install する (レーン既定に依存しない)
test('case A: 未 fake の外向き HTTP は StrayRequestException + accumulator 記録', ...);
test('case B: Http::fake([限定 URL]) 併用時、fake 対象は透過し未 fake の別 URL は stray になる', ...);
test('case C: Http::fake([*]) を張れば stray にならない', ...);
test('case D: loopback (127.0.0.1) は stray にならない (stray 判定を通過して送信段まで進む)', ...);
test('case E: アプリ層の catch (Throwable) で握り潰しても accumulator に残る', ...);
test('case F: flushAndFailIfStray() は accumulator 非空で throw し finally で clear する', ...);
test('case G: install() は冪等 (2 回呼んでも 1 stray に対し記録は 1 件)', ...);
test('case H: 許可パターンは loopback ホストだけに一致する (127.0.0.1.evil.example を弾く)', ...);

```

```php
test('case A: 未 fake の外向き HTTP は StrayRequestException + accumulator 記録', function (): void {
    // ★本ケースは「**無引数** Http::preventStrayRequests() が拒否を有効化する」という
    //   vendor の既定引数の意味に対する契約テストでもある (guard は無引数で呼ぶ)。
    //   将来 framework が既定値を反転させたらここが赤くなる
    //   (Codex design-review Round 2 の指摘への対応)。
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


```

---

## 再レビューの依頼事項

1. Round 2 の必須修正 2 点が解消しているか。
2. `strayHttpEgressCode()` の正規化方式 (literal トークンの括弧のみ `_` へ置換し、コメントは空にする) に見落としが無いか。とくに:
   - `T_START_HEREDOC` / `T_END_HEREDOC` のラベル部に括弧が現れうるか (現実には識別子のみのはずだが、見落としがあれば指摘してほしい)
   - PHP 8.4 の property hooks / first-class callable syntax `foo(...)` など、新しい構文で括弧の対応が崩れる余地が無いか
   - 補間の `${expr}` (T_DOLLAR_OPEN_CURLY_BRACES) が PHP 8.4 では deprecated だが、トークンとしては依然 `}` と対になるという理解で正しいか
3. 残っている [Warning] があれば必ず修正案付きで挙げてください。無ければ全体判定 **APPROVED** を明示してください。
