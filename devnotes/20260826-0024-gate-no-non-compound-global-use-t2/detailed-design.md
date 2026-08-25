# 詳細設計: gate-no-non-compound-global-use-t2

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（本トピックは DB 非使用の Architecture テストのみ）
- **DTO + JsonResource** パターン（本トピックは該当なし — アプリ実行コードに触れない）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12。`declare(strict_types=1)` + 日本語コメント
- **AGENTS.md 走査器共通規約**: (b) fail-closed の維持 / (c) 負例による検出力の裏取り /
  変更時に同じ PR で揃える 4 点 (負例と正例の fail-first / 落とす分岐 / 空振り検査 / docblock)

## 概念設計リファレンス

`devnotes/20260826-0024-gate-no-non-compound-global-use-t2/conceptual-design.md`
(Codex 概念設計レビュー Round 2 で APPROVED)

家系正典: lctl feature `gate-no-non-compound-global-use` 正典 t2
(取得時 feature_revision: 26-48c9ef10b833)。参照実装: motivation@785ec04b
(`PhpLintOracle.php` / `NonCompoundGlobalUseScanner.php` — 機序のみ移し、構成は写さない)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 真値を取る子プロセスの言語環境を C に固定する (LC_ALL=C) + 配線検査 | `tests/Support/GlobalUse/PhpLintOracle.php` / `tests/Architecture/NoNonCompoundGlobalUseTest.php` | 高 |
| 2 | 識別子位置の namespace 字句を宣言と誤読しないガード + 検体 2 形 | `tests/Support/GlobalUse/NonCompoundGlobalUseScanner.php` / `tests/Architecture/fixtures/global-use/clean-namespace-identifier.php.txt` (新規) / `tests/Architecture/fixtures/global-use/detects-namespace-identifier.php.txt` (新規) / `tests/Architecture/NoNonCompoundGlobalUseTest.php` | 高 |
| 3 | 採用時債務の決着 (D54 登録 + 債務 1 行削除 + pin 更新) | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/adoption-debt.tsv` / `tests/Support/TemplateDivergence/LedgerPins.php` | 高 (施策 1・2 と同一変更必須) |

## 施策 1: 真値を取る子プロセスの言語環境を C に固定する (LC_ALL=C) + 配線検査

### 変更箇所
- ファイル: `tests/Support/GlobalUse/PhpLintOracle.php` (L23-49: docblock と Process 構築)
- ファイル: `tests/Architecture/NoNonCompoundGlobalUseTest.php` (配線検査 1 本の追加)

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: gate 本体 (`NoNonCompoundGlobalUseTest.php`) に配線検査を追加 (下記)

### 現行コード
```php
public static function inspect(string $absolutePath): array
{
    $process = new Process([
        PHP_BINARY,
        '-n',
        '-d', 'error_reporting=E_ALL',
        '-d', 'display_errors=1',
        '-d', 'log_errors=0',
        '-l',
        $absolutePath,
    ]);
    $process->run();
    // ...
```

### 変更後コード
```php
/**
 * `php -l` を起動する Process を組み立てる (inspect() が使う。配線検査からも観測できる)。
 *
 * ★子プロセスの言語環境は **`LC_ALL=C` に固定**する (家系の正典 t2)。
 *   警告文からの抽出 (WARNING_PATTERN) は英語の診断文に依存するため、
 *   英語以外の言語環境の開発機で真値の抽出が静かに壊れる (自己検査が空振りする方向)
 *   のを予防する。Symfony Process は明示 env を継承環境の上へ合成するので、
 *   他の環境変数の継承は保たれる。
 * ★**限界 (誇張しない)**: 機械保証は「本メソッドが返す Process の明示 env が
 *   LC_ALL=C の 1 変数ちょうどである」ことまで (gate 側の配線検査)。inspect() が
 *   本メソッドを経由することはコードレビューで見る。言語環境の差による出力差そのものは
 *   この開発機では観測できない (現行の PHP は診断文を翻訳しないため挙動差が出ない)。
 *   これは予防の固定である。
 */
public static function buildProcess(string $absolutePath): Process
{
    return new Process(
        [
            PHP_BINARY,
            '-n',
            '-d', 'error_reporting=E_ALL',
            '-d', 'display_errors=1',
            '-d', 'log_errors=0',
            '-l',
            $absolutePath,
        ],
        null,
        ['LC_ALL' => 'C'],
    );
}

public static function inspect(string $absolutePath): array
{
    $process = self::buildProcess($absolutePath);
    $process->run();
    // ... (以降は現行のまま)
```

gate 側の配線検査 (`NoNonCompoundGlobalUseTest.php` へ追加):
```php
test('php -l の Process 組み立てが LC_ALL=C を明示している (正典 t2)', function (): void {
    // 検査が保証するのは **builder (`buildProcess()`) の明示 env が LC_ALL=C の
    // 1 変数ちょうど**であることまで (それ以外の変数は実行時に継承へ合成される)。
    // ちょうどで比べることで、明示 env への無関係な変数の混入も止める。
    // inspect() が builder を経由することは機械保証の範囲外で、コードレビューで見る
    // (builder を迂回する `new Process` の直接生成はこの検査に映らない)。
    $process = PhpLintOracle::buildProcess(GLOBAL_USE_FIXTURE_DIR.'/clean-compound.php.txt');

    expect($process->getEnv())->toBe(['LC_ALL' => 'C']);
});
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`buildProcess(): Process`)
- [x] null安全 (nullable なし。既存の `getExitCode()` null 分岐は不変)
- [x] DTOを返している — 該当なし (既存の shape array 契約を変えない)
- [x] Genericsの型パラメータ — 該当なし

### テスト計画
- [x] fail-first (3 段。検査が env 未配線を検出する分岐そのものを赤で裏取りする):
  1. 既存テストが緑の状態で、**env を設定しない** `buildProcess()` を振る舞い変更なしで
     抽出する (`inspect()` から Process 構築を移すだけ。全テスト緑のまま)
  2. 配線検査を追加し、`getEnv()` が `[]` を返して `['LC_ALL' => 'C']` と不一致で
     **赤**になることを確認する
  3. `buildProcess()` へ明示 env `['LC_ALL' => 'C']` を追加して緑化する
- [x] 既存テストの維持: 見本 12 本 (施策 2 適用後 14 本) の照合・構文妥当性・空振り検知が
  すべて緑のまま (LC_ALL=C 環境でも警告文は同一)
- [x] 新規テスト: 「php -l の Process 組み立てが LC_ALL=C を明示している」 — 上記
- [x] 個別の `DatabaseTransactions` なし (DB 非使用)

### リスク
- Symfony Process の env 合成仕様 (明示 env が継承環境を上書きし、その他は継承) に依存する。
  仕様が変わって継承が失われると `PHP_BINARY` 直接指定のため実行自体は保たれるが、
  他変数起因の差異は出うる。配線検査は明示 env の内容を「ちょうど 1 変数」で pin するので、
  意図しない env の増殖はレビューに見える。
- `getEnv()` は「明示した env」を返す API であり、実行時に子へ渡る最終環境の観測ではない
  (最終環境は run 時に合成される)。また検査が縛るのは builder までで、`inspect()` が
  builder を迂回して `new Process` を直接生成する将来変更には映らない (テスト用 DI を
  足すほどの価値は無いと判断。迂回はコードレビューで見る)。この限界は docblock に明記する。

## 施策 2: 識別子位置の namespace 字句を宣言と誤読しないガード + 検体 2 形

### 変更箇所
- ファイル: `tests/Support/GlobalUse/NonCompoundGlobalUseScanner.php`
  (L85-119 の T_NAMESPACE 分岐、docblock、helper 追加)
- ファイル: `tests/Architecture/fixtures/global-use/clean-namespace-identifier.php.txt` (新規)
- ファイル: `tests/Architecture/fixtures/global-use/detects-namespace-identifier.php.txt` (新規)
- ファイル: `tests/Architecture/NoNonCompoundGlobalUseTest.php`
  (L55-68 の検体一覧 + L224-225 の件数 pin)

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: gate 本体の検体一覧・件数 pin の更新 (下記)。
  検体は `.php.txt` なので他 gate (`StrictTypesDeclarationGateTest` /
  `ForbiddenStatementTokenInvariantTest`) と `TrackedPhpSourceFiles`
  (`git ls-files -- '*.php'`) の母集団には入らない (実読で確認済み)

### 現行コード (走査器の T_NAMESPACE 分岐の先頭)
```php
for ($i = 0; $i < $count; $i++) {
    $token = $tokens[$i];

    if ($token->is(T_NAMESPACE)) {
        $declaration = self::readNamespaceDeclaration($tokens, $i);

        if ($declaration === null) {
            $unresolved[] = sprintf(...);
            continue;
        }
        // ...
```

現行の問題 (実読で確認):
- `const NAMESPACE = 'x';` → `readNamespaceDeclaration()` が `=` で形を読めず
  unresolved (php -l は警告を出さないので**偽の赤**)。
- `return self::NAMESPACE;` → 直後が `;` のため「名前なしのセミコロン形宣言」として
  **読めてしまい**、`$kind = KIND_SEMICOLON` / `$bodyDepth = 現在深さ` へ状態が壊れる。
  以降の非複合 import を静かに見逃し、`hasGlobalRegion` も false になる (**検出力の縮小**)。

### 変更後コード
```php
    if ($token->is(T_NAMESPACE)) {
        // ★`namespace` は**半予約語** — クラス定数名 (`const NAMESPACE`)・自クラス参照
        //   (`self::NAMESPACE`)・メソッド名など識別子の文脈でも tokenizer は同じ字句を返す。
        //   namespace の**宣言**は文の先頭にしか置けないので、直前の有意トークン
        //   (空白・コメント・docblock を除く) が「無い (ファイル先頭)・開始タグ・`;`・`}`」の
        //   いずれでもなければ識別子として読み飛ばす。
        // ★これは**宣言であることの確定ではなく候補抽出**である (`}` は class や制御構文も
        //   閉じる)。候補位置で宣言の形を成さないものは従来どおり unresolved (fail-closed)。
        if (! self::atStatementStart($tokens, $i)) {
            continue;
        }

        $declaration = self::readNamespaceDeclaration($tokens, $i);
        // ... (以降は現行のまま)
```

helper 2 本 (private static。nullable を返し呼び出し側で明示分岐):
```php
/**
 * その位置のトークンが文の先頭に立っているか (namespace 宣言が置ける位置か)。
 *
 * 宣言候補とみなす直前有意トークンの閉じた集合: 無い (ファイル先頭) / T_OPEN_TAG /
 * `;` / `}`。`null` (ファイル先頭) は実ファイルでは開始タグより前に T_NAMESPACE が
 * 来ないため検体では固定できない (防御的に候補へ含めるのみ)。
 *
 * @param  list<PhpToken>  $tokens
 */
private static function atStatementStart(array $tokens, int $index): bool
{
    $previous = self::previousSignificant($tokens, $index - 1);
    if ($previous === null) {
        return true;
    }

    $token = $tokens[$previous];

    return $token->is(T_OPEN_TAG) || $token->text === ';' || $token->text === '}';
}

/**
 * index 以前で最後の意味のあるトークンの添字 (nextSignificant の逆方向)。
 *
 * @param  list<PhpToken>  $tokens
 */
private static function previousSignificant(array $tokens, int $index): ?int
{
    for ($i = $index; $i >= 0; $i--) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}
```

クラス docblock へ追記 (検出力の主張の限定):
```
 * ★**識別子位置の namespace 字句は宣言と読まない** (家系の正典 t2)。`namespace` は
 *   半予約語で、定数名・自クラスの定数参照・メソッド名の宣言と呼び出しの文脈でも
 *   同じ字句になる。宣言は文の先頭にしか置けないという構文事実で読み飛ばす。
 *   検体で裏取りした識別子文脈はこの 3 種 (定数名 / 自クラスの定数参照 / メソッド名の
 *   宣言と呼び出し) である。それ以外の識別子文脈 (enum の case 名・trait の別名・
 *   名前つき引数など) も同じ位置判定で読み飛ばされるが、個別の検体では固定していない。
```

### 新規検体 2 本

`detects-namespace-identifier.php.txt` (検出側):
```php
<?php

// 検出: namespace は半予約語 — 定数名や自クラス参照の識別子位置に現れても宣言ではない。
// 名前空間の宣言が無いファイルなので、後段の非複合 use は php -l が警告する。
// (旧実装は const NAMESPACE を unresolved にし、self::NAMESPACE; を宣言と誤読して
//  以降の use を静かに見逃した — 偽の赤と検出漏れの両方向の負例である)
final class LockRegistry
{
    public const NAMESPACE = 'locks';

    public function name(): string
    {
        return self::NAMESPACE;
    }
}

use RuntimeException;
```
期待: php -l 警告 1 件 (`RuntimeException` @ `use` の行)。走査器も同一。unresolved 0 件。

`clean-namespace-identifier.php.txt` (無違反側):
```php
<?php

// 無違反: 名前つきの名前空間の中では、識別子位置の namespace 字句
// (定数名・自クラス参照・メソッド名の宣言と呼び出し) が現れても宣言ではない。
namespace App\Support;

final class AggregateRunLock
{
    public const string NAMESPACE = 'aggregate-run';

    public function namespace(): string
    {
        return self::NAMESPACE;
    }

    public function key(): string
    {
        return $this->namespace().':lock';
    }
}
```
期待: php -l 警告 0 件・構文妥当 (メソッド名 `namespace` は半予約語として合法)。
走査器も violations 0 / unresolved 0。

### gate 本体の更新
```php
const GLOBAL_USE_FIXTURES = [
    'detects-class' => true,
    'detects-function-const' => true,
    'detects-leading-backslash' => true,
    'detects-comma-list' => true,
    'detects-partial-alias' => true,
    'detects-bracketed-global' => true,
    'detects-bracketed-after-named' => true,
    'detects-namespace-identifier' => true,   // 追加 (正典 t2)
    'clean-compound' => false,
    'clean-aliased' => false,
    'clean-named-namespace' => false,
    'clean-bracketed-named' => false,
    'clean-trait-and-closure' => false,
    'clean-namespace-identifier' => false,    // 追加 (正典 t2)
];
// 件数 pin: 検出 7→8 / 無違反 5→6
expect(count(array_filter(GLOBAL_USE_FIXTURES)))->toBe(8);
expect(count(array_filter(GLOBAL_USE_FIXTURES, static fn (bool $d): bool => ! $d)))->toBe(6);
```
gate 冒頭コメントの見本数 (「見本 12 本 (検出 7 / 無違反 5)」) も 14 本 (8/6) へ更新する。

**検体ごとの真値非空の検査 (Codex 指摘の反映 — 検出側契約の個別化)**:
既存の「真値が空振りしていない」検査は検出側の警告**合計** > 0 しか見ないため、
新検体の真値と走査が両方 0 件でも他の検体の警告で緑になり得る。
`GLOBAL_USE_FIXTURES` の `true` は「php -l が警告を出す形」という契約なので、
同検査を**検体ごとの非空**へ強める (合計の床は非空の帰結になるので個別検査へ置き換える):
```php
test('検出側の各見本から真値が取れている (php -l の警告文の変化・見本の無力化を検知する)', function () use ($globalUseOracle): void {
    foreach (GLOBAL_USE_FIXTURES as $name => $detects) {
        if (! $detects) {
            continue;
        }

        expect($globalUseOracle[$name]['warnings'])->not->toBeEmpty(sprintf(
            "検出側の見本 %s から真値が 1 件も取れませんでした。…(既存の診断情報を維持)",
            $name,
        ));
    }
});
```
(検体一覧の完全一覧 pin と合わせて「一覧にある検出側検体すべてが個別に発火する」契約になる。
警告数を検体ごとに数で pin することは、既存の名前・行の**完全一致照合**が
真値との件数一致まで固定しているため二重になる — 追加しない)

**検体照合の観測性 (fail-first で両方向の差を同時に観測する)**:
検出側・無違反側の照合テストは現行では `unresolved === []` を先に検査するため、
旧実装での赤が unresolved で止まり、真値との不一致 (検出漏れ側) が同じ実行で観測できない。
1 検体分の観測を 1 つの構造比較へまとめる:
```php
// 検出側 (無違反側は expected の entries を [] にして同形)
expect([
    'unresolved' => $scanned['unresolved'],
    'entries' => globalUseSorted($scanned['violations']),
])->toBe([
    'unresolved' => [],
    'entries' => globalUseSorted($globalUseOracle[$name]['warnings']),
], '見本 '.$name.' の判定が真値と一致しません');
```

**追跡下ファイルの読み込み失敗の fail-closed 化 (Codex 指摘の反映)**:
`globalUseScanTrackedTree()` の現行分岐
```php
$source = file_get_contents($target['absolute']);
if (! is_string($source)) {
    continue;   // ← 読めなかった追跡下ファイルを無言で母集団から除外している (fail-open)
}
```
は AGENTS.md 走査器共通規約 (b)「無言で候補から外さない」に反する (既知の不適合だが、
本 PR は同関数を含む gate を変更するので同じ変更で直す)。読み込み失敗は
`RuntimeException` で走査ごと落とす (追跡下ファイルが読めない作業ツリーは異常であり、
unresolved に積んで続行する価値が無い):
```php
/**
 * @param  list<array{absolute: string, relative: string}>|null  $targets
 *         null なら追跡下 PHP 全数 (TrackedPhpSourceFiles::all())。
 *         読み込み失敗分岐の自己検査のためにだけ注入を許す。
 *         要素型は `TrackedPhpSourceFiles::all()` の戻り型
 *         `list<array{absolute: string, relative: string}>` と完全一致させる
 *         (同クラスを実読して確認済み。shape がずれると PHPStan level 10 が落とす)。
 */
function globalUseScanTrackedTree(?array $targets = null): array
{
    // ...
    foreach ($targets ?? TrackedPhpSourceFiles::all(base_path()) as $target) {
        $source = @file_get_contents($target['absolute']);
        if (! is_string($source)) {
            throw new RuntimeException(
                '走査対象を読めませんでした (fail-closed。追跡下のファイルが読めない作業ツリーは異常): '
                .$target['relative'],
            );
        }
        // ... (以降は現行のまま)
```
自己検査 (fail-first: 分岐を書く前にテストを置き、現行 `continue` で「例外にならず
0 ファイル走査で返る」赤を確認する):
```php
test('読めない走査対象は無言で除外せず走査ごと失敗する (fail-closed)', function (): void {
    expect(fn (): array => globalUseScanTrackedTree([
        ['absolute' => GLOBAL_USE_FIXTURE_DIR.'/does-not-exist.php', 'relative' => 'does-not-exist.php'],
    ]))->toThrow(RuntimeException::class);
});
```

### 宣言候補 4 形の正例カバレッジ (実読で確認済み)
- `T_OPEN_TAG` 直後 (コメント読み飛ばし後): `clean-named-namespace` の `namespace App;` /
  `clean-bracketed-named` / `detects-bracketed-global`
- `;` の後: `clean-named-namespace` の `namespace Bar;`
- `}` の後: `detects-bracketed-after-named` の 2 本目 `namespace {`
- 既存検体は開始タグと宣言の間にコメントを持つため、**直前有意トークンの判定が
  コメント・docblock を読み飛ばさないと既存検体の照合が赤くなる** (負例を兼ねる)

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`atStatementStart(): bool` / `previousSignificant(): ?int`)
- [x] null安全 (`previousSignificant()` は nullable を返し、呼び出し側で明示分岐)
- [x] DTOを返している — 該当なし (既存の shape array 契約を変えない)
- [x] Genericsの型パラメータ — `@param list<PhpToken>` を既存 helper と同形で明示。
  `globalUseScanTrackedTree()` の `$targets` は
  `@param list<array{absolute: string, relative: string}>|null` を明示し、
  `TrackedPhpSourceFiles::all()` の戻り型と完全一致させる

### テスト計画
- [x] fail-first: 検体 2 本 + 検体一覧 + 件数 pin + 照合テストの構造比較化を**先に**入れ、
  現行走査器で「検出側: unresolved 非空**かつ**真値照合の不一致 (真値 1 vs 走査 0) が
  同一の構造比較の差分に出る」「無違反側: unresolved 非空」の赤を確認してから
  ガードを実装する
- [x] fail-first (読み込み失敗分岐): 自己検査を先に置き、現行の `continue` (無言除外) で
  「例外にならず 0 ファイル走査で返る」赤を確認してから fail-closed 化する
- [x] 検出側契約の個別化: 「検出側の各見本から真値が取れている」検査 (検体ごとの非空) へ
  既存の合計床検査を置き換える
- [x] 既存テストの維持: 既存検体 12 本の照合がそのまま緑 (正しい宣言の退行なし)
- [x] 検出力と fail-closed の縮小なし: unresolved 経路は不変。ガードは php -l が警告を
  出さない位置の字句だけを読み飛ばす (検出側検体の真値一致で裏取り)。
  読み込み失敗は無言除外 (fail-open) から例外 (fail-closed) へ強まる方向
- [x] 走査の空振り検査 (母集団非空 / migrations / tests/Architecture の包含 / unresolved 空) は
  既存のまま生きている
- [x] 個別の `DatabaseTransactions` なし

### リスク
- ガードの判定を誤ると正しい宣言を識別子扱いして走査域が縮む方向の退行になる。
  既存検体 12 本 (宣言候補 4 形のうち 3 形をカバー) + 追加 2 形の照合で固定する。
- 構文不正な位置の namespace (例: ブロック内の宣言) はガードが読み飛ばして沈黙しうるが、
  そうした入力は php -l が構文エラーにするため追跡下の実ファイル (実行可能な PHP) には
  存在しない。検体側は構文妥当性検査 (終了コード) が弾く。docblock の
  「保証しないもの」に追記する。

## 施策 3: 採用時債務の決着 (D54 登録 + 債務 1 行削除 + pin 更新)

### 変更箇所
- ファイル: `docs/template-divergence.md` (D54 を追記、冒頭の「登録エントリ: 53 件」→ 54 件)
- ファイル: `tests/Support/TemplateDivergence/adoption-debt.tsv`
  (`tests/Architecture/NoNonCompoundGlobalUseTest.php` の 1 行を削除)
- ファイル: `tests/Support/TemplateDivergence/LedgerPins.php`
  (`DIVERGENCE_ENTRY_COUNT` 53→54 / `ADOPTION_DEBT_COUNT` 143→142)

### 背景 (乖離台帳の確認段の結論)
`tests/Architecture/NoNonCompoundGlobalUseTest.php` は指紋台帳
(`docs/template-fingerprints.json` L177) のキーであり、かつ採用時債務一覧に載っている
(現行内容の sha256 = 債務の凍結値 `f461c835…` を実測で一致確認)。施策 1・2 が本ファイルを
変更するため「変更したまま債務に残す」は選べない (突合 gate が `mutatedDebtPaths` で落とす)。
3 択のうち **(3) 意図的逸脱として登録を書き債務から削る** を採る — aicue は T180 で
走査器の置き場・走査母集団・自己検査の同居構成をテンプレートと意図的に違えており、
(1) 採用時の姿へ戻す / (2) テンプレートへ同期する はどちらも走査域が縮む退行になる。
施策 1〜3 は**同一変更 (同一 PR)** で行う。

なお新規検体 2 本と `tests/Support/GlobalUse/` の 2 クラスは指紋台帳のキーに無い
(grep で確認済み)。テンプレートの同名機構 (`tests/Support/Architecture/GlobalUse/`) とは
置き場が違う aicue 固有の上積みであり、その説明は D54 の本文が兼ねる (対象パス欄は
突合 gate の母集団と重複制約の都合で gate 本体 1 パスに限る)。

### D54 登録の起草 (実装時にこのまま追記する)

```markdown
## D54 非複合 global use gate を走査器分離 + 追跡下 PHP 全数の母集団で持つ

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/NoNonCompoundGlobalUseTest.php` |
| 業務要件起因の説明 | 本 gate は「手元では警告で済む書き方が CI では例外へ昇格し全テストが全滅する」事故の再発防止装置であり、撮影 PWA と課金を持つ本アプリの変更安全性 (リリース継続性) はその検出力に依存する。走査母集団をテンプレートの 6 root ファイルシステム走査へ戻すと、追跡下 PHP のうち root 外の置き場が走査域から落ちて再発検出が置き場依存になるため、追跡下 PHP 全数の単一出典 (`Tests\Support\TrackedPhpSourceFiles`) から取る (AGENTS.md「走査根の単一出典」規約) |
| 揃え続ける不変条件と保証機構 | 家系の正典 t2 の必須能力 (3 種 import の対象化 / 名前空間文脈の追跡 / 先頭バックスラッシュ正規化 / php -l 真値との検体照合と検体ごとの真値非空の検知 / php -l を起動する Process builder の明示 env LC_ALL=C の pin / 識別子位置の namespace 字句のガードの検体固定) を、gate 本体に同居する検査群が保証する。機械保証はこの列挙の範囲までで、走査域の網羅そのものは母集団の縮退検査 (非空 + 代表 2 置き場の包含 + unresolved 空 + 読めないファイルでの失敗) が担う |
| 再判定の条件 | テンプレートが追跡下全数の単一出典による母集団定義を採ったとき、または家系の正典が構成 (ファイル配置・自己検査の分離) まで規定したとき |
| 決めた日 | 2026-08-16 |
| 決めた人 | 開発者 |
| 根拠 | `T180` |
| 状態 | 恒久 |
| 見直し期限 | ― |

テンプレートは走査器 5 クラスを `tests/Support/Architecture/GlobalUse/` に置き、自己検査を
`NoNonCompoundGlobalUseScannerTest.php` として分離し、走査 root を app / bootstrap / config /
database / routes / tests の 6 root に固定する。aicue は走査器 2 クラスを
`tests/Support/GlobalUse/` に置き、自己検査 (検体照合・空振り検知・件数 pin) を gate 本体へ
同居させ、母集団を追跡下 PHP 全数 (`*.blade.php` 除く) の単一出典から取る (家系最広)。
正典は能力を要求するのであって構成を要求しない (裁定 AG-155 の但し書き: 走査 root の列挙は
リポジトリ構成依存として必須外)。採用時点ではこの食い違いに説明が無く採用時債務 (D34 の一覧)
として凍結されていたが、正典 t2 追従 (devnotes/20260826-0024-gate-no-non-compound-global-use-t2/)
で本ファイルを変更するのに伴い、同じ変更で債務から外して本登録に説明を移した。
```

(注: 登録メタ表は 9 行ちょうど・規定の順序。対象パスは実在し他登録と重複しないことを
`TemplateDivergenceLedgerFormatTest` が機械検査する)

### PHPStan適合チェック
- [x] LedgerPins は scalar 定数のみの変更 (型注釈 `public const int` は既存のまま)
- [x] その他該当なし (docs / tsv)

### テスト計画
- [x] `TemplateDivergenceLedgerFormatTest` が緑 (9 行メタ表 / 値域 / 件数 3 点一致 /
  対象パス実在・非重複)
- [x] `TemplateDivergenceFingerprintTest` が緑 (変更後の gate 本体が「登録のある食い違い」に
  分類され、債務の凍結照合から外れる)
- [x] fail-first: 施策 1・2 の変更を先に入れた時点で突合 gate が `mutatedDebtPaths` で
  赤になることを確認してから本施策を適用する (債務決着の検査が生きている裏取りを兼ねる)

### リスク
- `DIVERGENCE_ENTRY_COUNT` / `ADOPTION_DEBT_COUNT` は他タスクも触る共有 pin であり、
  並行タスクとの merge で件数がずれると赤になる (赤になる方向なので無音の壊れ方はしない)。
  マージ時に再計算して解消する。

## 実装手順 (テストファースト)

**突合 gate の赤の時点に注意**: `NoNonCompoundGlobalUseTest.php` は採用時債務の対象なので、
同ファイルへ最初の変更を入れた**手順 2 の時点**で `TemplateDivergenceFingerprintTest` が
`mutatedDebtPaths` で赤になり、手順 6 まで意図的に赤のままである (debt 決着の検査が
生きている裏取りを兼ねる)。以下の「緑」「赤」は対象 gate 単位の状態を指す。

1. env を設定しない `buildProcess()` を振る舞い変更なしで抽出 (**全体 green**)。
2. oracle 配線検査を追加 → `getEnv()` が `[]` で赤を確認 → 明示 env `['LC_ALL' => 'C']` を
   追加して配線検査は red→green。**この時点から突合 gate は `mutatedDebtPaths` で赤**
   (手順 6 まで続く)。
3. 読み込み失敗分岐の自己検査を追加 → 現行の `continue` (無言除外) で赤を確認 →
   `globalUseScanTrackedTree()` を fail-closed 化 (注入 seam + 例外) して自己検査は緑。
4. 検体 2 本 + 検体一覧 + 件数 pin + 照合テストの構造比較化 + 検体ごとの真値非空検査を追加
   → 赤を確認 (検出側: unresolved 非空と真値不一致が同一比較の差分に出る /
   無違反側: unresolved 非空)。
5. 走査器へ `atStatementStart()` / `previousSignificant()` ガードを実装、docblock 更新
   → 検体 14 本の照合が緑 (突合 gate 以外は全緑)。
6. D54 登録 + 債務 1 行削除 + LedgerPins 更新 → 突合 gate と形式検査が緑 (**全体 green**)。
7. AGENTS.md の検証コマンド全数で緑を確認: `composer test` / `composer phpstan` /
   `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
   (整形が要るときは実装中に `composer fix` を使い、最終検証は `vendor/bin/pint --test` を正本とする)。
8. 実装フェーズの完了時に lctl へ status_reported (implemented, t2) を追記する
   (refs は push 済みコミット必須。本設計のスコープ外だが忘れない)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1〜3 は相互依存する (検体と pin を入れた時点で gate が赤 → 走査器の修正で緑 / gate 本体の変更は債務決着なしに main へ入れられない)。分割マージすると中間状態で `composer test` が赤になる。全体で 8 ファイル (走査器 / oracle / gate 本体 / 新規検体 2 / 逸脱登録簿 / 債務一覧 / pin) と小さく、1 本の worktree で完結する |
| 競合リスク | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` は他の設計・実装タスクも触る共有台帳で、件数 pin が並行タスクと衝突しうる (衝突は赤になる方向で無音ではない)。`NoNonCompoundGlobalUseTest.php` と `tests/Support/GlobalUse/` は本トピック以外が触る見込みなし |
