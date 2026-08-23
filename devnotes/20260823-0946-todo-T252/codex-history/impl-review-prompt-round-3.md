Round 2 の指摘に対する対応を報告する。以下の対応マトリクスと修正内容を確認し、再レビューせよ。
これが 3 ラウンド目 (最終) である。出力形式は同じ (ファイルごとの判定 /
[Critical] [Warning] [Suggestion] 分類 / 最後に `APPROVED` または `CHANGES_REQUESTED` の 1 語)。

---

# 対応マトリクス: impl-review Round 2

## [Critical] 設計固有の PHPStan コマンドが 4 エラー残る

- 判断: **対応する** (Round 1 の反論を撤回し、0 エラーにした)
- 根拠: 指摘のとおり「既知の 4 エラーを 1 度確認すればよい」は受入条件の読み替えだった。
  また「消す手段は 3 つしかない」も証明されていなかった。**実際に測って確かめた**:

  | 形 | PHPStan level 10 |
  |---|---|
  | `arch('x')->expect(['sha1'])->not->toBeUsed()->ignoring([])` | **4 errors** |
  | `$e = arch('x')->expect(['sha1'])` | 1 error (`TestCall::expect()` 未定義) |
  | **`expect(['sha1'])->not->toBeUsed()->ignoring([])`** | **0 errors** |
  | 戻り値を `Pest\Arch\Contracts\ArchExpectation` で受ける関数境界 | 7 errors (悪化) |

  → エラー源は **`arch()` が返す `TestCall` だけ**であり、`expect()` 側は型が付く。
  `arch($description)` は `test($description)` を呼んで `TestCall` を返し、
  以降のチェーンを実行時 mixin (`Plugin::uses(Architectable::class)`) で解決する**糖衣**にすぎない。
- 対応内容: **禁止表明を `test($description, fn)` + `expect(...)` へ書き換えた**。

  ```php
  foreach (ArchBaseline::ruleIds() as $ruleId) {
      test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
          expect(ArchBaseline::symbolsOf($ruleId))
              ->not->toBeUsed()
              ->ignoring(ArchBaseline::exceptionsOf($ruleId));
      });
  }
  ```

  - **規則の description がテスト名になる点は変わらない** (設計が求めた「主張の弱さが
    テスト一覧から見える」性質を保つ)
  - **表明が実際に評価されることを実測で確かめた**: 一時プローブで
    `expect(['sha1'])->not->toBeUsed()->ignoring([])` (例外なし) を書くと
    `FakeObjectStore.php:200` を名指しして**赤くなり**、例外つきなら緑になった。
    到達可能性・検出力とも `arch()` 形と同じである
  - **`vendor/bin/phpstan analyse --level=10 tests/Support/Architecture
    tests/Architecture/ArchBaselineTest.php tests/Unit/Architecture/ArchBaselineScannerTest.php`
    → 0 errors**。抑止コメント・baseline・widen・設定ファイル変更はいずれも使っていない
  - 副産物として **`arch` は `tests/` 全数で 0 件の禁止名になった** (S4-2)。
    「ちょうど 1 件」を数えるより強い契約であり、`\arch(...)` や
    `use function Pest\arch as x;` で 2 本目を作る経路もまとめて塞ぐ
  - `EXPECTED_CHAIN_TOKENS` / `EXPECTED_CHAIN_HEADER_TOKENS` を新しい形へ更新し、
    チェーンの錨を `arch` の呼び出しから **`toBeUsed` の識別子 1 件** (`CHAIN_ANCHOR_NAME`) へ移した
    (`expect` は `tests/` 全数に何百件もあり錨にできない)。
    期待形の中での錨の位置は `EXPECTED_CHAIN_TOKENS` から `array_search` で引くので、
    **期待形の正本は 1 つのまま**である

## [Critical] S4-3b は波括弧なしの制御構文で迂回できる

- 判断: **対応する** (指摘は正しい。実際に迂回できた)
- 根拠: 指摘の形を gate へ実際に注入して確かめた。
  `if (false)` の直後に改行して `foreach` を書くと、
  `tokensBefore()` は期待形と一致し `braceDepthAt()` も 0 のままで、**S4-3b は緑だった**。
- 対応内容:
  - **S4-3c (実行時の登録確認) が本体の保証**であることを明示し、S4-3b の主張を
    「生成点が 1 つで全規則 ID をちょうど 1 周する形であること」まで**狭めた**
    (到達可能性は主張しない。`braceDepthAt()` の docblock にも同じ限界を書いた)
  - **注入で実測**: 波括弧なし `if (false)` を gate へ入れると
    **S4-3c が赤になり (79 → 72 tests、7 規則 ID すべてを missing として報告)**、
    取り除くと 79 tests 全緑に戻ることを確認した。`test()` 形へ書き換えた後も同じ結果である
  - 走査器の負例 **13c** に指摘の brace-less 形を追加し、
    「綴りは同一・深さも 0 のまま」という**限界そのもの**を固定した
    (共通規約 (b) の「保証範囲の外にする構文は明記し、検出力を主張しない」)

## [Warning] 13c の負例が波括弧つきだけ

- 判断: **対応する** (上と同じ修正に含まれる)
- 対応内容: 13c に brace-less `if (false)` を追加し、`braceDepthAt` が 0 のままであることを
  正例として固定した。あわせてテスト名を
  「波括弧つきの囲みは深さで見抜けるが、波括弧なしの制御構文は見抜けない」へ改めた。

## [解消済み] 7b / ProcessBarrier / ArchTokenStream

Round 1 の指摘どおり対応済みで、Round 2 で解消と判定された。追加変更なし。

## [その他] `pnpm test` の 1 件失敗

- 判断: **本 TODO の範囲外として親エージェントへ報告する** (Round 1 と同じ)
- 根拠: clean な main で同一の失敗を再現済み。本実装は `resources/js` を 1 行も触っていない。
  詳細設計も「アプリコード・`resources/` は 1 行も変更しない」と明記している。
- 対応内容: 別 TODO で追跡すべき先行破損として報告する。


---

## 修正後の実測 (すべて再取得した)

- `composer test` (全数・--parallel): **6763 tests / 6761 passed / 2 skipped / 0 failed** (32384 assertions)
- `vendor/bin/phpstan analyse --level=10 tests/Support/Architecture tests/Architecture/ArchBaselineTest.php tests/Unit/Architecture/ArchBaselineScannerTest.php`:
  **0 errors** (設定ファイルは変更していない。抑止コメント・baseline・widen も無し)
- `composer phpstan` (level 10 / app config database routes): **No errors**
- `vendor/bin/pint --test`: passed
- `composer test -- --filter=ArchBaseline`: **79 tests / 79 passed / 172 assertions**
- **迂回の注入実測** (Round 2 の指摘そのものの形):
  gate の `foreach` を `if (false)` + 改行 (波括弧なし) で囲むと
  **72 tests / 71 passed / 1 failed** になり、S4-3c が
  `['AB-1'…'AB-7']` の 7 件を missing として報告した。取り除くと 79 tests 全緑に戻る。
  S4-3b と S4-2 と S4-3 はこの注入では緑のままである (だから S4-3c が要る)。
- **表明が実際に評価されることの実測**: 一時プローブで例外なしの
  `expect(['sha1'])->not->toBeUsed()->ignoring([])` を書くと
  `app/Services/Storage/Fakes/FakeObjectStore.php:200` を名指しして赤くなり、
  `->ignoring([FakeObjectStore::class])` にすると緑になった。

---

## 修正後の全文 (変更した箇所)

### `tests/Architecture/ArchBaselineTest.php` — 禁止表明の生成点

```php
foreach (ArchBaseline::ruleIds() as $ruleId) {
    test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
        expect(ArchBaseline::symbolsOf($ruleId))
            ->not->toBeUsed()
            ->ignoring(ArchBaseline::exceptionsOf($ruleId));
    });
}
```

### `tests/Architecture/ArchBaselineTest.php` — docblock の PHPStan の節

```
 * ★**PHPStan は 0 エラーである**。`phpstan.neon` の `paths` は
 *   `app / config / database / routes` で `tests/` を含まない (既存方針。本 gate は変えない) が、
 *   新設 3 パスを `vendor/bin/phpstan analyse --level=10` へ**コマンドライン引数で**渡した
 *   確認も 0 エラーで通る。
 *   ★そのために **`arch()` の糖衣は使わない**。`arch($description)` は
 *   `test($description)` を呼んで `TestCall` を返し、以降のチェーンを実行時 mixin
 *   (`Pest\Arch\Autoload` の `Plugin::uses(Architectable::class)`) で解決するため
 *   **静的に型が付かず**、`TestCall::expect()` 未定義 + 以降 `mixed` の 4 エラーが必ず出る
 *   (本アプリのコードを 1 行も含まない `arch('x')->expect(['sha1'])->not->toBeUsed()->ignoring([])`
 *   の 2 行だけで再現する)。代わりに `test($description, fn)` の中で
 *   `expect(...)->not->toBeUsed()->ignoring(...)` を直接書くと、
 *   **規則の description がテスト名になる点は変わらないまま** PHPStan が 0 エラーになる
 *   (`expect()` 側は型が付く)。禁止事項である抑止コメント・baseline・`mixed` への widen は
 *   いずれも使っていない。
 *   ★副産物として `arch` は **`tests/` 全数で 0 件**の禁止名になった (S4-2)。
 *   「ちょうど 1 件」を数えるより強い契約である。
 *
```

### `tests/Support/Architecture/ArchBaseline.php` — 変更した定数

```php
    /**
     * S4 が **`tests/` 全数で 0 件**に固定する名前 (arch 表明を宣言する糖衣構文)。
     *
     * ★**`arch()` は使わない**。`arch($description)` は `test($description)` を呼んで
     *   `TestCall` を返し、以降のチェーンを実行時 mixin (`Plugin::uses(Architectable::class)`)
     *   で解決する糖衣であり、**静的に型が付かない**
     *   (`vendor/bin/phpstan analyse --level=10` が `TestCall::expect()` を未定義と報告し、
     *   以降が `mixed` に落ちる)。本ベースラインは代わりに
     *   `test($description, fn)` の中で `expect(...)->not->toBeUsed()->ignoring(...)` を書く。
     *   規則の description がテスト名になる点は変わらず、**PHPStan は 0 エラー**になる。
     * ★0 件に固定するのは「2 本目の表明を別の書き方で足す」経路を塞ぐためでもある。
     *   **「ちょうど 1 件」より強い契約**である (数えるのではなく存在させない)。
     *
     * @var list<string>
     */
    public const array FORBIDDEN_DECLARATION_FUNCTIONS = ['arch'];
    /**
     * チェーンの位置を特定する錨になる綴り。
     *
     * ★`arch` を禁止名にした (`FORBIDDEN_DECLARATION_FUNCTIONS`) ので、
     *   位置の特定は「`tests/` 全数でちょうど 1 件」の**メンバ名**に取る。
     *   `expect` は `tests/` 全数に何百件もあるため錨にできない。
     * ★この綴りは `SINGLE_MEMBER_NAMES` にも `EXPECTED_CHAIN_TOKENS` にも現れる。
     *   3 か所の整合は S4 が実測で突き合わせる。
     */
    public const string CHAIN_ANCHOR_NAME = 'toBeUsed';

    /**
     * S4 が照合する arch チェーンの**囲み**の期待トークン列 (`arch` の直前 11 個)。
     *
     * ★チェーンの綴りだけを pin すると、`if (false) { … }` のような**実行されない位置**へ
     *   丸ごと移して 7 本の表明を無効化できる (綴りは 1 文字も変わらない)。
     *   囲みの綴りと**波括弧の深さ 0** を併せて固定することで、
     *   「ファイル最上位で全規則 ID をちょうど 1 周する」形だけを許す。
     *
     * @var list<string>
     */
    public const array EXPECTED_CHAIN_HEADER_TOKENS = [
        'foreach', '(', 'ArchBaseline', '::', 'ruleIds', '(', ')', 'as', '$ruleId', ')', '{',
        'test', '(', 'ArchBaseline', '::', 'descriptionOf', '(', '$ruleId', ')', ',',
        'function', '(', ')', 'use', '(', '$ruleId', ')', ':', 'void', '{',
    ];

    /**
     * S4 が照合する arch チェーンの期待トークン列 (綴りの列。空白とコメントは除く)。
     *
     * ★**この定数が期待形の唯一の正本**である。gate 側に写しを持たない。
     *
     * @var list<string>
     */
    public const array EXPECTED_CHAIN_TOKENS = [
        'expect', '(', 'ArchBaseline', '::', 'symbolsOf', '(', '$ruleId', ')', ')',
        '->', 'not', '->',
        'toBeUsed', '(', ')',
        '->', 'ignoring', '(', 'ArchBaseline', '::', 'exceptionsOf', '(', '$ruleId', ')', ')', ';',
    ];
```

### `tests/Architecture/ArchBaselineTest.php` — S4-2 / 錨のヘルパ / S4-3 / S4-3b / S4-3c

```php
test('S4-2: arch 宣言の糖衣が tests/ 全数で呼び出しも取り込みも 0 件である', function (): void {
    // `arch()` は静的に型が付かない (docblock の PHPStan の節)。本ベースラインは
    // `test($description, fn)` + `expect(...)` で書き、`arch` は**存在させない**。
    // 「ちょうど 1 件」を数えるより強い契約であり、2 本目の表明を別の書き方で
    // 足す経路もまとめて塞ぐ。
    $sites = [];
    foreach (archBaselineTestSourceFiles() as $file) {
        $source = archBaselineReadSource($file['absolute']);
        foreach (ArchSurfaceScanner::functionNameSites($source, ArchBaseline::FORBIDDEN_DECLARATION_FUNCTIONS) as $site) {
            $sites[] = $site['status'].' '.$site['name'].' '.$file['relative'].':'.$site['line'];
        }
    }

    expect($sites)->toBe([]);
});

test('S4-2b: ignoring / toBeUsed の識別子が tests/ 全数で各 1 件、チェーン宿主ファイルにある', function (): void {
    // この 2 つは `->toBeUsed()` / `->ignoring(...)` の形でしか現れない**メンバ名**であり、
    // functionNameSites は「直前が `->` なら拾わない」契約なので必ず 0 件を返す。
    // 関数と同じ契約で束ねると gate が初日から赤くなるため、識別子検査へ回す。
    $sites = [];
    foreach (ArchBaseline::SINGLE_MEMBER_NAMES as $memberName) {
        $sites[$memberName] = [];
    }

    foreach (archBaselineTestSourceFiles() as $file) {
        $source = archBaselineReadSource($file['absolute']);
        foreach (ArchSurfaceScanner::identifierSites($source, ArchBaseline::SINGLE_MEMBER_NAMES) as $memberName => $found) {
            foreach ($found as $site) {
                $sites[$memberName][] = $file['relative'].':'.$site['line'];
            }
        }
    }

    foreach (ArchBaseline::SINGLE_MEMBER_NAMES as $memberName) {
        expect($sites[$memberName])->toHaveCount(1)
            ->and($sites[$memberName][0])->toStartWith(ArchBaseline::CHAIN_HOST_FILE.':');
    }
});

/**
 * チェーン宿主ファイル内の**唯一のチェーン開始位置** (`expect` の有意トークン添字)。
 *
 * ★`arch` を廃した (S4-2) ので、錨は `toBeUsed` の識別子 1 件に取る。
 *   `expect` は `tests/` 全数に何百件もあり、件数では一意にならないからである。
 *   期待形の中で `toBeUsed` が何番目に来るかは `EXPECTED_CHAIN_TOKENS` から引く
 *   (**期待形の正本は 1 つのまま**にする)。
 */
function archBaselineChainStartIndex(string $source): int
{
    $sites = ArchSurfaceScanner::identifierSites($source, [ArchBaseline::CHAIN_ANCHOR_NAME]);
    $anchors = $sites[ArchBaseline::CHAIN_ANCHOR_NAME];

    expect($anchors)->toHaveCount(1);

    $offset = array_search(ArchBaseline::CHAIN_ANCHOR_NAME, ArchBaseline::EXPECTED_CHAIN_TOKENS, true);
    Assert::integer($offset, '期待形のトークン列に錨となる綴りが無い (期待形と錨がずれている)');

    return $anchors[0]['index'] - $offset;
}

test('S4-3: 唯一のチェーンが期待形と完全一致する', function (): void {
    $host = dirname(__DIR__, 2).'/'.ArchBaseline::CHAIN_HOST_FILE;
    $source = archBaselineReadSource($host);

    // 行番号ではなく有意トークンの添字を使う (同じ行に複数の呼び出しがあると一意にならない)。
    expect(ArchSurfaceScanner::statementTokens($source, archBaselineChainStartIndex($source)))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS);
});

test('S4-3b: 唯一のチェーンが波括弧の外側を持たない foreach + test の直下にある', function (): void {
    // ★本条が固定するのは**生成点が 1 つで、全規則 ID をちょうど 1 周する形であること**だけである。
    //   **到達可能性は保証しない** — 波括弧を持たない制御構文で囲む形
    //   (`if (false)` の直後に改行して `foreach` を書く / `if (false): … endif;`) や
    //   先行する `return` は波括弧の深さに現れない (負例 13c がこの限界を固定している)。
    //   「7 本が実際に登録されたこと」は **S4-3c が実行時に Pest の登録簿へ問い合わせて**保証する。
    //   2 つは役割が違うので両方置く。
    $host = dirname(__DIR__, 2).'/'.ArchBaseline::CHAIN_HOST_FILE;
    $source = archBaselineReadSource($host);

    $start = archBaselineChainStartIndex($source);
    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);

    expect(ArchSurfaceScanner::tokensBefore($source, $start, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        // 囲みの外側 (`foreach` の位置) に開いたままの波括弧が無い = ファイル最上位
        ->and(ArchSurfaceScanner::braceDepthAt($source, $start - $headerLength))->toBe(0)
        // チェーン自身は foreach の `{` と closure の `{` の 2 段の中にある
        ->and(ArchSurfaceScanner::braceDepthAt($source, $start))->toBe(2);
});

test('S4-3c: 7 規則の禁止表明が実際に Pest へ登録されている', function (): void {
    // ★**これが「7 本が実際に効いている」ことの本体の保証**である。
    //   S4-3b (綴りと波括弧の深さ) は生成点が 1 つであることを固定するだけで、
    //   **到達可能性は証明しない** — 波括弧を持たない制御構文
    //   (`if (false)` の直後に改行して `foreach` を書く形 / `if (false): … endif;`) や
    //   先行する `return` は深さに現れないからである。
    //   したがって「登録されたか」は**実行時に Pest の登録簿へ問い合わせる**。
    //
    // ★vendor の内部表現 (`TestSuite::$tests` は public だが `TestRepository` は @internal)
    //   へ結合する。`composer update` で赤くなり得るのは仕様であり、
    //   そのときは問い合わせ方を更新する (検査を緩めるのは選択肢に入れない)。
    $factory = TestSuite::getInstance()->tests->get(__FILE__);

    // 登録簿にファイルが無い = 本ファイルのテストが 1 つも登録されていない (実行中なのでありえない)。
    Assert::notNull($factory, '本ファイルが Pest の登録簿に無い (登録の問い合わせ方が壊れている)');

    $descriptions = array_keys($factory->methods);

    $missing = array_values(array_filter(
        ArchBaseline::ruleIds(),
        static fn (string $ruleId): bool => ! in_array(ArchBaseline::descriptionOf($ruleId), $descriptions, true),
    ));

    // 空振り検査: 本テスト自身の description が取れていること
    // (取れないなら「7 本が無い」ではなく「問い合わせ方が壊れている」)。
    expect($descriptions)->toContain('S4-3c: 7 規則の禁止表明が実際に Pest へ登録されている')
        ->and($missing)->toBe([]);
});
```

### `tests/Unit/Architecture/ArchBaselineScannerTest.php` — チェーンのヘルパと 13c

```php
/** 期待形のチェーンを 1 本だけ含む合成ソース。 */
function archBaselineExpectedChainSource(): string
{
    return <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        foreach (ArchBaseline::ruleIds() as $ruleId) {
            test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
                expect(ArchBaseline::symbolsOf($ruleId))
                    ->not->toBeUsed()
                    ->ignoring(ArchBaseline::exceptionsOf($ruleId));
            });
        }
        PHP;
}

/**
 * 合成ソース内のチェーン開始位置 (`expect` の有意トークン添字) を取り出す。
 *
 * ★錨は `toBeUsed` の識別子 1 件。`arch` は禁止名になったので使えず、
 *   `expect` は件数で一意にならない。期待形の中での錨の位置は
 *   `EXPECTED_CHAIN_TOKENS` から引く (期待形の正本を 2 つにしない)。
 */
function archBaselineChainIndex(string $source): int
{
    $anchors = ArchSurfaceScanner::identifierSites($source, [ArchBaseline::CHAIN_ANCHOR_NAME])[ArchBaseline::CHAIN_ANCHOR_NAME];

    expect($anchors)->toHaveCount(1);

    $offset = array_search(ArchBaseline::CHAIN_ANCHOR_NAME, ArchBaseline::EXPECTED_CHAIN_TOKENS, true);

    expect($offset)->toBeInt();

    /** @var int $offset */
    return $anchors[0]['index'] - $offset;
}

test('13c: 波括弧つきの囲みは深さで見抜けるが、波括弧なしの制御構文は見抜けない', function (): void {
    // ★チェーンの綴りは 1 文字も変えずに 7 本の表明を無効化する形。
    //   綴り列の照合だけでは見抜けないので braceDepthAt が要る。
    $topLevel = archBaselineExpectedChainSource();

    $guarded = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        if (false) {
            foreach (ArchBaseline::ruleIds() as $ruleId) {
                test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
                    expect(ArchBaseline::symbolsOf($ruleId))
                        ->not->toBeUsed()
                        ->ignoring(ArchBaseline::exceptionsOf($ruleId));
                });
            }
        }
        PHP;

    // ★**波括弧を持たない制御構文では深さが増えない** — 本走査器の限界であり、
    //   ここで固定しておく。到達可能性の保証は静的な深さでは得られないので、
    //   gate は S4-3c (Pest の登録簿への実行時問い合わせ) で別に証明している。
    $braceless = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        if (false)
            foreach (ArchBaseline::ruleIds() as $ruleId) {
                test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
                    expect(ArchBaseline::symbolsOf($ruleId))
                        ->not->toBeUsed()
                        ->ignoring(ArchBaseline::exceptionsOf($ruleId));
                });
            }
        PHP;

    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);
    $topLevelHeader = archBaselineChainIndex($topLevel) - $headerLength;
    $guardedHeader = archBaselineChainIndex($guarded) - $headerLength;
    $bracelessHeader = archBaselineChainIndex($braceless) - $headerLength;

    // 囲みの**綴り**は 3 つとも同じ (だから綴り照合では見抜けない)。
    expect(ArchSurfaceScanner::tokensBefore($guarded, $guardedHeader + $headerLength, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        ->and(ArchSurfaceScanner::tokensBefore($braceless, $bracelessHeader + $headerLength, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        ->and(ArchSurfaceScanner::braceDepthAt($topLevel, $topLevelHeader))->toBe(0)
        ->and(ArchSurfaceScanner::braceDepthAt($guarded, $guardedHeader))->toBe(1)
        // ★ここが限界の実測: 波括弧なしの `if (false)` では深さが 0 のままである。
        ->and(ArchSurfaceScanner::braceDepthAt($braceless, $bracelessHeader))->toBe(0);
});
```

### `tests/Support/Architecture/ArchSurfaceScanner.php` — braceDepthAt の docblock (限界を明記)

```php
    /**
     * 指定位置の時点で**開いたままの波括弧の深さ**を返す。
     *
     * ★「チェーンが波括弧の外側を持たないこと」を固定するために使う。
     *   `if (false) { … }` のような**実行されない位置**へチェーンを移す形は、
     *   綴り列の照合だけでは見抜けない (チェーン自身の綴りは変わらない) ため、
     *   囲みの深さを別に測る。
     * ★**保証しないもの (到達可能性ではない)**: 波括弧を持たない制御構文
     *   (`if (false)` の直後に改行して文を書く形 / `if (…): … endif;` の代替構文) や
     *   先行する `return` / `exit` は**深さに現れない**。
     *   本メソッドは「囲む波括弧が何段あるか」しか答えない。
     *   到達可能性を要求する利用側は、**別の手段でそれを証明すること**
     *   (`ArchBaselineTest` は S4-3c で Pest の登録簿へ実行時に問い合わせている)。
     * ★文字列補間の `{$x}` / `${x}` も**開き波括弧として数える** (対応する `}` が
     *   単一文字トークンとして現れるので、数えないと深さがずれる)。
     * ★**深さが負になる場合を扱う分岐は持たない**。入力は `TOKEN_PARSE` を通っており
     *   波括弧の対応は構文として保証されるので、その状態は到達しない
     *   (到達できない結果を作らない = 共通規約 (d))。トークン化できない入力は
     *   `ArchTokenStream` が例外にする。
     */
```

### `docs/template-divergence.md` — D43 の記述の更新差分

- 「揃え続ける不変条件と保証機構」行: 「arch のチェーンが 1 本であること」→
  「禁止表明を作るチェーンが 1 本であること (S4 が tests 配下の追跡 PHP 全数を母集団に完全一致で照合し、
  7 本が実際に Pest へ登録されたことまで実行時に確かめる)」
- 比較表に 1 行追加: 「表明の書き方 | `arch()` の糖衣 | `test($description, fn)` の中で
  `expect(...)->not->toBeUsed()->ignoring(...)` (description がテスト名になる点は同じ。
  `arch()` は静的に型が付かず PHPStan level 10 が通らないため使わない)」
