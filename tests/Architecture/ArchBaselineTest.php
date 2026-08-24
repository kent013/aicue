<?php

declare(strict_types=1);

use App\Services\Manual\SopTextExtractor;
use App\Services\Storage\Fakes\FakeObjectStore;
use App\Support\ProductionEnvGuard;
use App\Support\QueueDispatchAtomicityGuard;
use Pest\Arch\Repositories\ObjectsRepository;
use Pest\Arch\Support\Composer;
use Pest\Arch\Support\PhpCoreExpressions;
use Pest\ArchPresets\Laravel;
use Pest\ArchPresets\Php;
use Pest\ArchPresets\Security;
use Pest\Factories\Attribute;
use Pest\Factories\TestCaseMethodFactory;
use Pest\TestSuite;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Support\Architecture\ArchBaseline;
use Tests\Support\Architecture\ArchSurfaceScanner;
use Tests\Support\Architecture\GlobalFunctionCallScanner;
use Tests\Support\Architecture\VendorArchPresetReader;
use Tests\Support\TrackedPhpSourceFiles;
use Webmozart\Assert\Assert;

/*
 * Pest arch のベースラインを**規則ごとに分解して**持つ gate。
 *
 * vendor の preset を一括で使うと、1 つの例外クラスが 97 語彙すべての免除になる。
 * 本 gate は同じ語彙集合を 7 規則へ割り、**例外を持つ規則の対象シンボルを 1 個に限る**
 * ことで、`ignoring` の波及半径を定義上 1 シンボルへ閉じる。
 * 規則・語彙・例外の正本は {@see ArchBaseline} の定数だけであり、
 * ここに写しを持たない。
 *
 * ─────────────────────────────────────────────────────────────
 * 保証しないもの (誇張しない。ここが正本)
 * ─────────────────────────────────────────────────────────────
 *
 * 1. **走査域**: Pest arch は `App\` / `Database\Factories\` / `Database\Seeders\` の
 *    3 根だけを見る (`Pest\Arch\Support\Composer::userNamespaces()` は `tests/` を除外する)。
 *    `.blade.php` / `resources/js/` も対象外である。
 *
 * 2. **検出できる語彙は 97 のうち一部である**。Pest が依存側の層を作れるのは
 *    `Pest\Arch\Support\PhpCoreExpressions::getClass($v) !== null` または
 *    `function_exists($v) && (new ReflectionFunction($v))->getName() === $v`
 *    を満たす語彙だけで、**それ以外は層が空 = その規則は落ちようがない**。
 *    **活性判定は常に実行環境依存である** (polyfill / ユーザー定義関数 / 拡張・
 *    パッケージの有無で変わる)。設計時点の実測は「コア構文 5 + 実在関数 27 + 不活性 65」で、
 *    不活性のうち `mysql_*` 14 + `ereg` + `eregi` + `create_function` は
 *    **PHP 8 の標準環境に組み込みが存在しない**もの、`xdebug_*` 40 + `ray` `ds` `ddd` `trap` は
 *    **拡張・パッケージの有無で変わる**もの。**件数は pin しない** (環境差だけで
 *    赤くなる検査を作らないため)。分類を再計算するには 97 語彙に対して
 *    上記 2 述語をそのまま評価すればよい (S1 の 3 条目が同じ述語を使っている)。
 *
 * 3. **綴りの大小を変えた呼び出しを Pest arch は検出しない** (`SHA1(` は見えない)。
 *    層の名前 (`ArchBaseline::RULES` の綴り) と AST の綴りを `===` で突き合わせるため。
 *
 * 4. **S2 と S4 で大小の扱いが逆である**。S2 (`GlobalFunctionCallScanner`) は区別する
 *    — Pest の粒度に揃えて「Pest が検出する使用」だけを証明にするため。
 *    S4 (`ArchSurfaceScanner`) は無視する — 迂回口を塞ぐため。
 *    **理由が逆なので混同しないこと**。
 *
 * 5. **S4 が保証しない構文**: `ReflectionMethod` / `ReflectionFunction` 経由の反射呼び出し /
 *    可変関数 / 文字列連結で組み立てた名前 / それ以外の未知の間接実行経路。
 *    **この構文について検出力を主張しない**。
 *
 * 6. **静的プロパティ参照 (`self::$x`) は動的メンバとして数えない** (意図的な対象外)。
 *    メンバ名が綴りとして確定しているためで、理由は `ArchSurfaceScanner` の docblock にある。
 *
 * 7. **`ArchBaseline::DYNAMIC_MEMBER_INVENTORY` は安全の証明ではない**。
 *    受容した未解決箇所の在庫であり、**同一ファイル内での置換は検出しない**。
 *
 * 8. **Pest の解析単位はクラスではなくファイルである**。1 ファイルにつき最初の 1 個しか
 *    オブジェクトにならず、2 つ目以降のクラスの名前参照は**最初のオブジェクトの依存として
 *    帰属する**。したがって **`->ignoring(X::class)` は実質「X を含むファイル全体」を免除する**。
 *    例外クラスと同じファイルに別のクラスを足すと、その規則の対象シンボルについては
 *    一緒に免除される (規則の対象シンボルは 1 個なので波及は 1 語彙に閉じるが、
 *    **「クラス単位で免除している」とは書けない**)。
 *
 * 9. **既存の `ForbiddenStatementTokenInvariantTest` / SSRF 検査 / LLM 防御の代替ではない**。
 *    対象語彙も走査域も方式も別である。
 *
 * 10. **`tests/` 配下で禁止する名前**: `arch` (0 件) / `preset` (0 件) /
 *     `call_user_func` / `call_user_func_array` / `forward_static_call` /
 *     `forward_static_call_array` / `fromCallable` (いずれも 0 件) と
 *     **セグメントが完全一致する**メソッド名・関数名・`use` の取り込みを作らないこと。
 *     `ignoring` / `toBeUsed` は**本ファイルの 1 件だけ**が許される。
 *     違反すれば S4 が即座に赤くなる。
 *
 * ★**vendor の内部 API へ結合している**。S3 の第 6/7 条は
 *   `Pest\Arch\Support\Composer` / `Pest\Arch\Repositories\ObjectsRepository` (`@internal`) を、
 *   S5 は preset の**ソース表現**を読む。`composer update` で赤くなり得るのは**仕様**であり、
 *   そのときはベースラインを更新する (検査を緩めるのは選択肢に入れない)。
 *   ★これらの `use` は S4 の取り込み検査に**当たらない**。取り込み判定は名前トークンの
 *   **末尾セグメント**だけを見るので、`Pest\Arch\Support\Composer` は `Composer` として
 *   照合される (`Arch` は記号を取り込まない中間セグメントである)。
 *   Pint の `fully_qualified_strict_types` が完全修飾参照を `use` へ書き換えるため、
 *   この形は避けようがない。詳しくは `ArchSurfaceScanner::importedNames()` の docblock。
 *
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
 * ★**本ファイルの自己検査だけでは守れない穴がある**。Pest のテストファイルは
 *   素のスクリプトなので、最上位を短絡させると**禁止表明 7 本も S1〜S5 も
 *   丸ごと登録されなくなる**。そのとき自己検査 (S4-3c を含む) も一緒に消えるため、
 *   **本ファイルの中からは原理的に検出できない**。短絡の形は 2 つあり、
 *   どちらも `tests/Unit/Architecture/ArchBaselineScannerTest.php` の
 *   **外部自己検査 (テスト 38)** が見張る:
 *     (a) **実行を打ち切る形** (最上位に `return;` を 1 行) →
 *         `ArchSurfaceScanner::topLevelAbortSites()` が **0 件**を要求する
 *     (b) **宣言を条件付きにして飛ばす形** (ファイル全体を `if (false): … endif;` で囲む) →
 *         打ち切りトークンも波括弧も 1 つも増えないので (a) にも波括弧の深さの検査にも
 *         現れない。`ArchSurfaceScanner::topLevelControlStructureSites()` が
 *         **ちょうど 1 件 (チェーン自身の `foreach`)** を要求することでだけ捕まる
 *   実測: (a) を注入すると本ファイルのテストが全滅し (41 → 0) テスト 38 が赤、
 *   (b) を注入しても同じく全滅し、テスト 38 が「最上位の制御構造が 2 件」で赤になる。
 *   ★**登録は残したまま評価だけ止める形**もある: `test(…)` を閉じたあとに
 *   `->skip()` / `->todo()` を後置すると、ヘッダーも表明も最上位の構造も 1 文字も変わらず、
 *   description は登録されるので S4-3c の missing も空になる。この形は
 *   **生成文の後置トークンを exact-fit で閉じる** (`EXPECTED_CHAIN_FOOTER_TOKENS`。
 *   S4-3 と外部のテスト 38 の両方が照合する) ことと、S4-3c が
 *   **新品の factory との差分比較**で実行修飾の不在を実行時に確かめることの 2 つで塞ぐ。
 *   ★**ファイル単位で実行を止める形**もある: 最上位に
 *   `beforeEach(fn () => $this->markTestSkipped())` を 1 つ置くと、
 *   **本ファイルの全テストが登録も生成もされたまま skip される**
 *   (実測: 41 本が skip され、**失敗 0 件**になった = Pest の出力に赤が 1 つも出ない)。
 *   この形は綴り・波括弧の深さ・後置・各テストの登録内容の
 *   **どれ 1 つも変えない**ので、上記のどの層にも現れない。
 *   外部自己検査 (テスト 38) の「**最上位の素の関数呼び出しは `test` だけ**」
 *   (`EXPECTED_TOP_LEVEL_CALL_NAMES`) がこれを閉じる。`uses()` / `pest()` / `describe()` など
 *   他のファイル単位の入口も同じ 1 つの契約に含まれる (禁止名の一覧を持たない)。
 *   **その外部検査自身が同じ手口で短絡された場合は検出しない** (検査を見張る検査は
 *   無限に続くので置かない)。最後の砦は git のレビューである。
 *
 * 負例・正例の置き場: 3 走査器は `tests/Unit/Architecture/ArchBaselineScannerTest.php`、
 * S3 の述語 (接頭辞衝突・包含・正規化) は本ファイル内の合成入力 (末尾)。
 */

// ---------------------------------------------------------------------------
// A. 禁止表明 (7 本を単一の生成点から)
// ---------------------------------------------------------------------------

foreach (ArchBaseline::ruleIds() as $ruleId) {
    test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
        expect(ArchBaseline::symbolsOf($ruleId))
            ->not->toBeUsed()
            ->ignoring(ArchBaseline::exceptionsOf($ruleId));
    });
}

// ---------------------------------------------------------------------------
// 純関数 (S3 の述語。合成入力で両方向を固定する)
// ---------------------------------------------------------------------------

/**
 * 例外クラス名が、走査域の他クラス名の**真の接頭辞**になっているか (純関数)。
 *
 * `ignoring` の除外は `str_starts_with($object->name, $exclude)` の前方一致なので、
 * `A\Foo` を例外に載せると `A\FooDouble` も `A\Foo\Baz` も黙って除外される。
 *
 * @param  list<string>  $exceptionNames  例外に登録した完全修飾クラス名
 * @param  list<string>  $allClassNames  走査域の全完全修飾クラス名
 * @return list<string> 衝突の説明 (空なら衝突なし)
 */
function archBaselineProperPrefixCollisions(array $exceptionNames, array $allClassNames): array
{
    $collisions = [];
    foreach ($exceptionNames as $exceptionName) {
        foreach ($allClassNames as $className) {
            if ($className !== $exceptionName && str_starts_with($className, $exceptionName)) {
                $collisions[] = "{$exceptionName} は {$className} の真の接頭辞である";
            }
        }
    }

    return $collisions;
}

/**
 * 例外クラスのうち、Pest が実際に構築するオブジェクト名の集合に**無い**ものを返す (純関数)。
 *
 * 比較は**大小を変換せず厳密**に行う。クラス名は PHP では大小無視だが、
 * Pest の突き合わせが `===` である以上こちらも同じ厳密さに揃えるのが正しい。
 *
 * @param  list<string>  $exceptionNames
 * @param  list<string>  $objectNames
 * @return list<string>
 */
function archBaselineMissingFromPestObjects(array $exceptionNames, array $objectNames): array
{
    return array_values(array_filter(
        $exceptionNames,
        static fn (string $name): bool => ! in_array($name, $objectNames, true),
    ));
}

/**
 * オブジェクト名の集合を正規化する (重複排除 + 昇順)。
 *
 * ★`Composer::userNamespaces()` に包含関係のある prefix が将来含まれると同じオブジェクトが
 *   複数回列挙され、床値を**重複で**満たしてしまう。第 6 条 / 第 7 条 / 床値検査は
 *   **すべてこの正規化済み集合を使う**。
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function archBaselineNormalizeObjectNames(array $names): array
{
    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

// ---------------------------------------------------------------------------
// 走査根 (母集団)
// ---------------------------------------------------------------------------

/**
 * Pest 自身が構築するオブジェクト名の集合 (正規化済み)。
 *
 * ★**PSR-4 のパスからクラス名を推測する自前の列挙は採らない** (1 ファイルに複数クラス /
 *   ファイル名とクラス名の不一致 / namespace 宣言が期待パスと違う / 条件付き宣言を取りこぼす)。
 *   Pest のオブジェクト集合は **`ignoring` が実際に前方一致で除外する対象そのもの**なので、
 *   母集団と判定対象が同一で**定義上ずれようがない**。
 *
 * @return list<string>
 */
function archBaselinePestObjectNames(): array
{
    $names = [];
    foreach (Composer::userNamespaces() as $namespace) {
        foreach (ObjectsRepository::getInstance()->allByNamespace($namespace) as $object) {
            $names[] = $object->name;
        }
    }

    return archBaselineNormalizeObjectNames($names);
}

/**
 * S4 の母集団 (`tests/` 配下の git 追跡 PHP 全数)。
 *
 * 走査根の単一出典は `Tests\Support\TrackedPhpSourceFiles` (同じ列挙を 2 本持たない)。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function archBaselineTestSourceFiles(): array
{
    return array_values(array_filter(
        TrackedPhpSourceFiles::all(dirname(__DIR__, 2)),
        static fn (array $file): bool => str_starts_with($file['relative'], 'tests/'),
    ));
}

/**
 * 走査対象のファイルを読む (読めなければ**無言で外さず**赤にする)。
 */
function archBaselineReadSource(string $absolutePath): string
{
    $source = file_get_contents($absolutePath);
    Assert::string($source, "走査対象のファイルを読めない: {$absolutePath}");

    return $source;
}

/**
 * 全例外クラスの完全修飾名 (規則をまたいだ和集合)。
 *
 * @return list<string>
 */
function archBaselineExceptionClasses(): array
{
    $names = [];
    foreach (ArchBaseline::ruleIds() as $ruleId) {
        foreach (ArchBaseline::exceptionsOf($ruleId) as $exception) {
            $names[] = $exception;
        }
    }

    return array_values(array_unique($names));
}

// ---------------------------------------------------------------------------
// S1: 期待値の pin
// ---------------------------------------------------------------------------

test('S1-1: 規則ごとの対象シンボル数が pin と完全一致する', function (): void {
    $measured = [];
    foreach (ArchBaseline::ruleIds() as $ruleId) {
        $measured[$ruleId] = count(ArchBaseline::symbolsOf($ruleId));
    }

    expect($measured)->toBe(ArchBaseline::SYMBOL_COUNT_PINS);
});

test('S1-2: 和集合の語彙数が TOTAL_SYMBOL_COUNT と一致する', function (): void {
    expect(ArchBaseline::allSymbols())->toHaveCount(ArchBaseline::TOTAL_SYMBOL_COUNT);
});

test('S1-3: 実効対象集合が非空である (gate 全体が実効ゼロになっていない)', function (): void {
    // vendor と**同じ述語**で活性を判定する。件数は pin しない
    // (xdebug の有無で 40 件動くため。環境差だけで赤くなる検査を作らない)。
    $active = array_values(array_filter(
        ArchBaseline::allSymbols(),
        static fn (string $symbol): bool => PhpCoreExpressions::getClass($symbol) !== null
            || (function_exists($symbol) && (new ReflectionFunction($symbol))->getName() === $symbol),
    ));

    expect($active)->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// S2: 逆向き証明 (例外登録が腐っていないこと)
// ---------------------------------------------------------------------------

test('S2: 各例外クラスは対象シンボルを実際に素の関数呼び出しで使っている', function (): void {
    $unused = [];

    foreach (ArchBaseline::ruleIds() as $ruleId) {
        $symbols = ArchBaseline::symbolsOf($ruleId);

        foreach (ArchBaseline::exceptionsOf($ruleId) as $exception) {
            $fileName = (new ReflectionClass($exception))->getFileName();
            // 解決できなければ**無言で外さず**赤にする (fail-closed)。
            expect($fileName)->toBeString();

            /** @var string $fileName */
            $counts = GlobalFunctionCallScanner::countCallsInFile($fileName, $symbols);

            if (array_sum($counts) < 1) {
                $unused[] = "{$ruleId} の例外 {$exception} は対象シンボルを 1 度も呼んでいない";
            }
        }
    }

    expect($unused)->toBe([]);
});

// ---------------------------------------------------------------------------
// S3: 構造契約
// ---------------------------------------------------------------------------

test('S3-1: 例外を持つ規則の対象シンボルはちょうど 1 個である', function (): void {
    $violations = [];
    foreach (ArchBaseline::ruleIds() as $ruleId) {
        if (ArchBaseline::exceptionsOf($ruleId) === []) {
            continue;
        }
        if (count(ArchBaseline::symbolsOf($ruleId)) !== 1) {
            $violations[] = $ruleId;
        }
    }

    expect($violations)->toBe([]);
});

test('S3-2: 規則 ID の集合が SYMBOL_COUNT_PINS のキー集合と一致する', function (): void {
    expect(ArchBaseline::ruleIds())->toBe(array_keys(ArchBaseline::SYMBOL_COUNT_PINS));
});

test('S3-3: 語彙が全規則を通じて重複しない', function (): void {
    $total = 0;
    foreach (ArchBaseline::ruleIds() as $ruleId) {
        $total += count(ArchBaseline::symbolsOf($ruleId));
    }

    expect(count(ArchBaseline::allSymbols()))->toBe($total);
});

test('S3-4: 語彙がすべて小文字である', function (): void {
    // 大文字混じりの綴りは vendor 側で層が空になり**黙って無効化される**。
    $uppercase = array_values(array_filter(
        ArchBaseline::allSymbols(),
        static fn (string $symbol): bool => mb_strtolower($symbol) !== $symbol,
    ));

    expect($uppercase)->toBe([]);
});

test('S3-5: 例外クラスが実在する', function (): void {
    $missing = array_values(array_filter(
        archBaselineExceptionClasses(),
        static fn (string $name): bool => ! class_exists($name),
    ));

    expect($missing)->toBe([]);
});

test('S3-6: すべての例外クラスが Pest のオブジェクト名集合に含まれる', function (): void {
    expect(archBaselineMissingFromPestObjects(archBaselineExceptionClasses(), archBaselinePestObjectNames()))
        ->toBe([]);
});

test('S3-7: 例外クラス名が他のオブジェクト名の真の接頭辞になっていない', function (): void {
    expect(archBaselineProperPrefixCollisions(archBaselineExceptionClasses(), archBaselinePestObjectNames()))
        ->toBe([]);
});

test('S3-8: 各規則の rationale が 30 文字以上ある', function (): void {
    $tooShort = [];
    foreach (ArchBaseline::ruleIds() as $ruleId) {
        if (mb_strlen(ArchBaseline::rationaleOf($ruleId)) < 30) {
            $tooShort[] = $ruleId;
        }
    }

    expect($tooShort)->toBe([]);
});

test('S3-9: 動的メンバ目録の rationale が 30 文字以上で count が 1 以上である', function (): void {
    $violations = [];
    foreach (ArchBaseline::dynamicMemberInventory() as $path => $entry) {
        if (mb_strlen($entry['rationale']) < 30 || $entry['count'] < 1) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});

test('S3-10: description が空でなく規則 ID を含む', function (): void {
    $violations = [];
    foreach (ArchBaseline::ruleIds() as $ruleId) {
        $description = ArchBaseline::descriptionOf($ruleId);
        if ($description === '' || ! str_contains($description, $ruleId)) {
            $violations[] = $ruleId;
        }
    }

    expect($violations)->toBe([]);
});

// ---------------------------------------------------------------------------
// S4: サーフェスの pin (母集団 = tests/ 配下の git 追跡 PHP 全数)
// ---------------------------------------------------------------------------

test('S4-1: preset の一括使用が tests/ 全数で 0 件である', function (): void {
    $sites = [];
    foreach (archBaselineTestSourceFiles() as $file) {
        $source = archBaselineReadSource($file['absolute']);
        foreach (ArchSurfaceScanner::identifierSites($source, [ArchBaseline::FORBIDDEN_PRESET_NAME]) as $found) {
            foreach ($found as $site) {
                $sites[] = $file['relative'].':'.$site['line'];
            }
        }
    }

    expect($sites)->toBe([]);
});

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
    $start = archBaselineChainStartIndex($source);

    // ★**後置まで閉じる**。表明の文だけを pin すると `})->skip();` の後置で
    //   「登録はされるが評価されない」状態を作れる (綴りも登録簿も一致したまま)。
    $afterStatement = $start + count(ArchBaseline::EXPECTED_CHAIN_TOKENS);

    expect(ArchSurfaceScanner::statementTokens($source, $start))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS)
        ->and(ArchSurfaceScanner::tokensAfter($source, $afterStatement, count(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS)))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS);
});

test('S4-3b: 唯一のチェーンが波括弧の外側を持たない foreach + test の直下にある', function (): void {
    // ★本条が固定するのは**生成点が 1 つで、全規則 ID をちょうど 1 周する形であること**だけである。
    //   **到達可能性は保証しない** — 波括弧を持たない制御構文で囲む形
    //   (`if (false)` の直後に改行して `foreach` を書く / `if (false): … endif;`) や
    //   先行する `return` は波括弧の深さに現れない (負例 13c がこの限界を固定している)。
    //   「7 本が実際に登録されたこと」は **S4-3c が実行時に Pest の登録簿へ問い合わせて**保証する。
    //   2 つは役割が違うので両方置く。
    //   ★**S4-3c にも届かない形が 1 つある**: 囲みが**本ファイル全体**に及ぶと S4-3c 自身が
    //   登録されず走らない。そこは外部自己検査 (テスト 38) の
    //   「最上位の制御構造はちょうど 1 件」が受け持つ。
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

test('S4-3c: 7 規則の禁止表明が実際に Pest へ登録され、実行修飾を 1 つも持たない', function (): void {
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
    //
    // ★**本条が検出できない形がある**: 本ファイルの最上位に `return;` を置く / 本ファイル全体を
    //   `if (false): … endif;` で囲む、のどちらでも**本条自身が登録されない**ので走らない。
    //   そこは `tests/Unit/Architecture/ArchBaselineScannerTest.php` の
    //   **外部自己検査 (テスト 38)** が `topLevelAbortSites()` (打ち切り 0 件) と
    //   `topLevelControlStructureSites()` (最上位の制御構造ちょうど 1 件) の 2 本で見張る。
    //   役割が違うので両方要る。
    $factory = TestSuite::getInstance()->tests->get(__FILE__);

    // 登録簿にファイルが無い = 本ファイルのテストが 1 つも登録されていない (実行中なのでありえない)。
    Assert::notNull($factory, '本ファイルが Pest の登録簿に無い (登録の問い合わせ方が壊れている)');

    $descriptions = array_keys($factory->methods);

    $missing = array_values(array_filter(
        ArchBaseline::ruleIds(),
        static fn (string $ruleId): bool => ! in_array(ArchBaseline::descriptionOf($ruleId), $descriptions, true),
    ));

    // ★**「登録されている」だけでは足りない**。`test(…)` を閉じたあとに `->skip()` /
    //   `->todo()` を後置すると、description は登録されたまま closure が実行されなくなる。
    //   静的には後置トークンの exact-fit (S4-3 の `EXPECTED_CHAIN_FOOTER_TOKENS`) で塞いだが、
    //   ここでは**実行時にも**「7 本の登録内容が生まれたままの状態か」を見る。
    //   比較は**新品の factory との差分**で行う (deny-by-default)。修飾の名前を並べた
    //   許可・拒否一覧を持たないので、Pest が**公開プロパティに載る**修飾を増やしても勝手に効く。
    //   ★**保証しないもの**: `get_object_vars()` が呼び出し位置から見えるのは**公開プロパティだけ**
    //   である。vendor が将来**非公開**の修飾状態を足したら、本条はそれを見ない
    //   (見るなら Reflection か `(array)` キャストが要る)。現行の Pest は
    //   修飾状態を公開プロパティと公開コレクションに置いているのでこの差は出ない。
    //   `description` / `closure` / `filename` は 7 本ごとに違って当然なので比べない。
    //   ★配列の比較は `==` (緩い) を使う。`chains` 等は毎回別インスタンスの
    //   `HigherOrderMessageCollection` なので `===` では必ず不一致になるが、
    //   `==` はクラスと **private を含む全プロパティ**を再帰比較するため、
    //   「空のまま」かどうかを正しく判定できる。
    //   ★**本条が保証するのは個々の `TestCaseMethodFactory` の状態だけ**である。
    //   ファイル単位の hook (`beforeEach`) や `uses()` のように**factory の外から**
    //   実行を止める形はここに現れない (登録内容は新品のままになる)。
    //   そちらは外部自己検査 (テスト 38) の「最上位の素の関数呼び出しは `test` だけ」が閉じる。
    //   ★`attributes` だけは新品と比べられない。Pest は description から
    //   `#[Test]` と `#[TestDox(description)]` を必ず 2 個作るからである。
    //   そこで**その 2 個ちょうど**を期待形として持つ (`->group()` / `->depends()` /
    //   `->with()` / `->only()` はいずれもここへ追加するので、増えれば赤くなる)。
    $pristine = new TestCaseMethodFactory(__FILE__, null);
    $ignoredFields = ['description' => true, 'closure' => true, 'filename' => true, 'attributes' => true];
    $pristineFields = array_diff_key(get_object_vars($pristine), $ignoredFields);

    $modified = [];
    foreach (ArchBaseline::ruleIds() as $ruleId) {
        $description = ArchBaseline::descriptionOf($ruleId);
        $method = $factory->methods[$description] ?? null;
        if ($method === null) {
            continue; // 未登録は $missing 側が報告する
        }

        $expectedAttributes = [
            new Attribute(Test::class, []),
            new Attribute(TestDox::class, [$description]),
        ];

        if (array_diff_key(get_object_vars($method), $ignoredFields) != $pristineFields) {
            $modified[] = $ruleId.' (登録内容が新品と違う)';
        }

        if ($method->attributes != $expectedAttributes) {
            $modified[] = $ruleId.' (属性が Test + TestDox の 2 個ではない)';
        }
    }

    // 空振り検査: 本テスト自身の description が取れていること
    // (取れないなら「7 本が無い」ではなく「問い合わせ方が壊れている」)。
    expect($descriptions)->toContain('S4-3c: 7 規則の禁止表明が実際に Pest へ登録され、実行修飾を 1 つも持たない')
        ->and($missing)->toBe([])
        ->and($modified)->toBe([]);
});

test('S4-4: 動的メンバ参照が目録とファイル別件数まで exact-fit である', function (): void {
    $measured = [];
    foreach (archBaselineTestSourceFiles() as $file) {
        $source = archBaselineReadSource($file['absolute']);
        $sites = ArchSurfaceScanner::dynamicMemberSites($source);
        if ($sites === []) {
            continue;
        }

        $measured[$file['relative']] = count($sites);
    }

    $expected = [];
    foreach (ArchBaseline::dynamicMemberInventory() as $path => $entry) {
        $expected[$path] = $entry['count'];
    }

    ksort($measured);
    ksort($expected);

    expect($measured)->toBe($expected);
});

test('S4-5: callable 経由の実行語彙が tests/ 全数で呼び出しも取り込みも 0 件である', function (): void {
    $sites = [];
    foreach (archBaselineTestSourceFiles() as $file) {
        $source = archBaselineReadSource($file['absolute']);
        foreach (ArchSurfaceScanner::functionNameSites($source, ArchBaseline::FORBIDDEN_CALLABLE_FUNCTIONS) as $site) {
            $sites[] = $site['status'].' '.$site['name'].' '.$file['relative'].':'.$site['line'];
        }
    }

    expect($sites)->toBe([]);
});

test('S4-6: fromCallable の識別子が tests/ 全数で 0 件である', function (): void {
    $sites = [];
    foreach (archBaselineTestSourceFiles() as $file) {
        $source = archBaselineReadSource($file['absolute']);
        foreach (ArchSurfaceScanner::identifierSites($source, [ArchBaseline::FORBIDDEN_CALLABLE_METHOD]) as $found) {
            foreach ($found as $site) {
                $sites[] = $file['relative'].':'.$site['line'];
            }
        }
    }

    expect($sites)->toBe([]);
});

// ---------------------------------------------------------------------------
// S5: vendor preset との集合一致
// ---------------------------------------------------------------------------

test('S5: 7 規則の和集合が vendor 3 preset の禁止語彙の和集合と一致する', function (): void {
    $php = VendorArchPresetReader::forbiddenSymbolsOf(Php::class);
    $security = VendorArchPresetReader::forbiddenSymbolsOf(Security::class);
    $laravel = VendorArchPresetReader::forbiddenSymbolsOf(Laravel::class);

    $union = array_values(array_unique([...$php, ...$security, ...$laravel]));
    sort($union);

    // 3 集合がそれぞれ非空で代表語彙を含む (抽出が壊れて空になったことを捕まえる)。
    expect($php)->not->toBeEmpty()
        ->and($security)->not->toBeEmpty()
        ->and($laravel)->not->toBeEmpty()
        ->and($php)->toContain('var_dump')
        ->and($security)->toContain('sha1')
        ->and($laravel)->toContain('env')
        ->and($union)->toBe(ArchBaseline::allSymbols());
});

// ---------------------------------------------------------------------------
// 母集団が空でないことの検査 (共通規約 (b) の 3 番目)
// ---------------------------------------------------------------------------

test('母集団: 規則と各規則の語彙が空でない', function (): void {
    expect(ArchBaseline::ruleIds())->not->toBeEmpty();

    foreach (ArchBaseline::ruleIds() as $ruleId) {
        expect(ArchBaseline::symbolsOf($ruleId))->not->toBeEmpty();
    }
});

test('母集団: S4 の走査根が 700 本以上あり代表パスを含む', function (): void {
    $relatives = array_map(
        static fn (array $file): string => $file['relative'],
        archBaselineTestSourceFiles(),
    );

    expect(count($relatives))->toBeGreaterThanOrEqual(700)
        ->and($relatives)->toContain('tests/Pest.php')
        ->and($relatives)->toContain('tests/TestCase.php')
        ->and($relatives)->toContain(ArchBaseline::CHAIN_HOST_FILE);
});

test('母集団: Pest のオブジェクト名集合が 500 件以上あり 3 つの走査根がいずれも生きている', function (): void {
    $names = archBaselinePestObjectNames();

    $perRoot = [];
    foreach (['App\\', 'Database\\Factories\\', 'Database\\Seeders\\'] as $root) {
        $perRoot[$root] = count(array_filter(
            $names,
            static fn (string $name): bool => str_starts_with($name, $root),
        ));
    }

    expect(count($names))->toBeGreaterThanOrEqual(500)
        ->and($perRoot['App\\'])->toBeGreaterThanOrEqual(1)
        ->and($perRoot['Database\\Factories\\'])->toBeGreaterThanOrEqual(1)
        ->and($perRoot['Database\\Seeders\\'])->toBeGreaterThanOrEqual(1);
});

// ---------------------------------------------------------------------------
// S3 の述語の負例・正例 (gate 内の合成入力)
// ---------------------------------------------------------------------------

test('31: 同接頭辞の別クラスは衝突として検出する', function (): void {
    expect(archBaselineProperPrefixCollisions(['A\Foo'], ['A\Foo', 'A\FooDouble']))->toHaveCount(1);
});

test('32: 名前空間の区切りをまたぐ前方一致も衝突として検出する', function (): void {
    // `str_starts_with` は区切りを見ないので、これも実際に巻き込まれる。
    expect(archBaselineProperPrefixCollisions(['A\Foo'], ['A\Foo', 'A\Foo\Baz']))->toHaveCount(1);
});

test('33: 無関係なクラス名は衝突として検出しない', function (): void {
    expect(archBaselineProperPrefixCollisions(['A\Foo'], ['A\Foo', 'A\Bar']))->toBe([]);
});

test('34: Pest のオブジェクト名集合が例外クラス 4 種の代表を含む (空振り検査)', function (): void {
    $names = archBaselinePestObjectNames();

    expect($names)->toContain(FakeObjectStore::class)
        ->and($names)->toContain(SopTextExtractor::class)
        ->and($names)->toContain(ProductionEnvGuard::class)
        ->and($names)->toContain(QueueDispatchAtomicityGuard::class);
});

test('35: 実際の例外集合はすべて Pest のオブジェクト名集合に含まれる', function (): void {
    expect(archBaselineMissingFromPestObjects(archBaselineExceptionClasses(), archBaselinePestObjectNames()))
        ->toBe([]);
});

test('36: 集合に無い例外クラスを混ぜた合成入力では落ちる', function (): void {
    // 代表クラス pin では将来の 5 件目・6 件目を守れない。
    // 「任意の例外クラスが集合に無ければ赤」を述語のレベルで固定する。
    $exceptions = [...archBaselineExceptionClasses(), 'A\NotInPest'];

    expect(archBaselineMissingFromPestObjects($exceptions, archBaselinePestObjectNames()))
        ->toBe(['A\NotInPest']);
});

test('37: オブジェクト名集合の正規化で重複が 1 件に畳まれる', function (): void {
    // ★テスト側に array_unique() / sort() を**複写しない** (写しを持つと
    //   正規化を変えたときテストだけ通る)。gate 本体と同じ関数を呼ぶ。
    expect(archBaselineNormalizeObjectNames(['A\Foo', 'A\Foo', 'A\Bar']))->toBe(['A\Bar', 'A\Foo']);
});
