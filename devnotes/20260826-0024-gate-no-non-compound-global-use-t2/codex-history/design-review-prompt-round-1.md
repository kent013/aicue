# 詳細設計レビュー依頼 (Round 1)

【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら、設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel アプリケーションのテスト基盤 (Architecture gate) 改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Pest + PHPStan level 10
- 対象は複数リポジトリで共有する機能台帳 (lctl) の正典 t2 への追従。
  「グローバル名前空間での非複合名 import (`use RuntimeException;` 等) を禁止する
  Architecture gate」の走査器・真値取得部品・検体・gate 本体を更新する
- 変更はすべて `tests/` 配下 + docs (アプリ実行コード・UI・DB に触れない)
- AGENTS.md「静的検査 (gate) と走査器の共通規約」: (b) 解決できない形は fail-closed
  (見逃す方向へ倒すのは不可) / (c) 検出力は負例で裏取り (両方向) /
  変更時に同じ PR で揃える 4 点 (負例と正例の fail-first / 落とす分岐 / 空振り検査 / docblock) /
  検出力の主張は根拠を併記し、併記の無い記述は検出力未確認と読む (誇張しない)
- テンプレート共有ファイルの変更規律: 採用時債務 (adoption-debt.tsv) に載るパスは
  「変更したまま債務に残す」が選べない。逸脱登録簿 (docs/template-divergence.md) は
  9 行メタ表 (対象パス / 業務要件起因の説明 / 揃え続ける不変条件と保証機構 / 再判定の条件 /
  決めた日 / 決めた人 / 根拠 / 状態 / 見直し期限) をこの順序で持ち、
  `TemplateDivergenceLedgerFormatTest` と `TemplateDivergenceFingerprintTest` が機械検査する

【レビュー観点】
1. コードの正確性（PHP tokenizer の挙動、字句走査のロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、既存 helper のパターン、fail-closed 経路の維持）
3. PHPStan level 10 適合性（型安全性、nullable の明示分岐）
4. テスト計画の網羅性（fail-first の実現性、負例の両方向、空振り検査の維持、件数 pin）
5. 検出力・fail-closed の縮小が無いか（正典 t2 追従として過不足がないか）
6. 副作用・後退リスク（既存検体 12 本の退行、Symfony Process の env 合成への依存）
7. 波及変更の網羅性（gate 本体の pin・docblock・逸脱登録簿・LedgerPins が変更対象に含まれているか）
8. セキュリティ（子プロセス起動の引数、テスト専用部品の境界）
9. DESIGN.md / Atomic Design 準拠 — 本トピックは UI 変更を含まないため該当なし (確認のみ)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書
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
 * ★**限界 (誇張しない)**: 固定が効いていることの検査は「Process へ env が配線されている」
 *   ことの観測まで (gate 側の配線検査) であり、言語環境の差による出力差そのものは
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
test('真値を取る子プロセスの言語環境が C に固定されている (正典 t2)', function (): void {
    // inspect() が走らせる Process と同じ組み立てを観測する。明示 env は LC_ALL=C の
    // 1 変数ちょうど (それ以外は継承)。ちょうどで比べることで、明示 env への
    // 無関係な変数の混入 (継承を上書きして真値の条件を変える方向) も止める。
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
- [x] fail-first: 配線検査を先に追加し、env 未配線 (現行 `new Process([...])`) の状態で
  `getEnv()` が `[]` を返して赤になることを確認してから `buildProcess()` を実装する
- [x] 既存テストの維持: 見本 12 本 (施策 2 適用後 14 本) の照合・構文妥当性・空振り検知が
  すべて緑のまま (LC_ALL=C 環境でも警告文は同一)
- [x] 新規テスト: 「真値を取る子プロセスの言語環境が C に固定されている」 — 上記
- [x] 個別の `DatabaseTransactions` なし (DB 非使用)

### リスク
- Symfony Process の env 合成仕様 (明示 env が継承環境を上書きし、その他は継承) に依存する。
  仕様が変わって継承が失われると `PHP_BINARY` 直接指定のため実行自体は保たれるが、
  他変数起因の差異は出うる。配線検査は明示 env の内容を「ちょうど 1 変数」で pin するので、
  意図しない env の増殖はレビューに見える。
- `getEnv()` は「明示した env」を返す API であり、実行時に子へ渡る最終環境の観測ではない
  (最終環境は run 時に合成される)。この限界は docblock に明記する (誇張しない)。

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
- [x] Genericsの型パラメータ — `@param list<PhpToken>` を既存 helper と同形で明示

### テスト計画
- [x] fail-first: 検体 2 本 + 検体一覧 + 件数 pin を**先に**入れ、現行走査器で
  「検出側: unresolved 非空 + 真値照合の不一致 (真値 1 vs 走査 0)」
  「無違反側: unresolved 非空」の赤を確認してからガードを実装する
- [x] 既存テストの維持: 既存検体 12 本の照合がそのまま緑 (正しい宣言の退行なし)
- [x] 検出力と fail-closed の縮小なし: unresolved 経路は不変。ガードは php -l が警告を
  出さない位置の字句だけを読み飛ばす (検出側検体の真値一致で裏取り)
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
| 業務要件起因の説明 | 走査母集団はテンプレートの 6 root ファイルシステム走査ではなく、追跡下 PHP 全数の単一出典 (`Tests\Support\TrackedPhpSourceFiles`) から取る (AGENTS.md「走査根の単一出典」規約)。テンプレート形へ戻すと走査域が縮む退行になる |
| 揃え続ける不変条件と保証機構 | 家系の正典 t2 の必須能力 (3 種 import の対象化 / 名前空間文脈の追跡 / 先頭バックスラッシュ正規化 / php -l 真値との自己検査と空振り検知 / 子プロセス言語環境の C 固定 / 識別子位置の namespace 字句のガード) を、gate 本体に同居する検体照合の検査群が保証する |
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

1. 検体 2 本 + 検体一覧 + 件数 pin + oracle 配線検査を追加 → `composer test` で赤を確認
   (検出側: unresolved + 真値不一致 / 無違反側: unresolved / 配線検査: `getEnv()` が `[]`)。
   この時点で突合 gate (`TemplateDivergenceFingerprintTest`) も `mutatedDebtPaths` で赤。
2. `PhpLintOracle::buildProcess()` (LC_ALL=C) を実装 → 配線検査が緑。
3. 走査器へ `atStatementStart()` / `previousSignificant()` ガードを実装、docblock 更新
   → 検体 14 本の照合が緑。
4. D54 登録 + 債務 1 行削除 + LedgerPins 更新 → 突合 gate と形式検査が緑。
5. `composer test` / `composer phpstan` / `vendor/bin/pint --test` 全緑を確認
   (JS 側は非対象だが CI 同等の `pnpm lint` / `pnpm typecheck` / `pnpm test` も回して無影響を確認)。
6. 実装フェーズの完了時に lctl へ status_reported (implemented, t2) を追記する
   (refs は push 済みコミット必須。本設計のスコープ外だが忘れない)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1〜3 は相互依存する (検体と pin を入れた時点で gate が赤 → 走査器の修正で緑 / gate 本体の変更は債務決着なしに main へ入れられない)。分割マージすると中間状態で `composer test` が赤になる。全体で 7 ファイル程度と小さく、1 本の worktree で完結する |
| 競合リスク | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` は他の設計・実装タスクも触る共有台帳で、件数 pin が並行タスクと衝突しうる (衝突は赤になる方向で無音ではない)。`NoNonCompoundGlobalUseTest.php` と `tests/Support/GlobalUse/` は本トピック以外が触る見込みなし |


---

## 関連する現行コード (全文)

### tests/Support/GlobalUse/NonCompoundGlobalUseScanner.php
```php
<?php

declare(strict_types=1);

namespace Tests\Support\GlobalUse;

use PhpToken;

/**
 * PHP ソースから「グローバル名前空間での非複合名の import」を列挙する純関数。
 *
 * ★真値は **PHP 実行系の `php -l`** である。この走査器が違反と呼ぶ形は、
 *   `php -l` が「非複合名の use は効果が無い」と警告する形とちょうど同じでなければならない。
 *   一致していることは `PhpLintOracle` を使う自己検査が見本で固定する。
 *
 * ★**別名が付いた要素は違反ではない**。`use Foo as Bar;` に `php -l` は警告を出さない
 *   (別名が付いた import は実際に効くため)。要素ごとに別名の有無を持ち、
 *   付いていたら報告しない。
 *
 * ★**行番号の規則**: `php -l` は 1 つの use 文の中のどの要素についても
 *   「その文で最初に現れた名前トークンの行」で報告する (実測。例えば
 *   `use\n Foo as F,\n Bar;` の `Bar` は `Foo` の行で報告される)。
 *   照合できるように、走査器も 1 文の中では最初の名前トークンの行を共有する。
 *
 * ★**グローバル領域は 2 通りしかない** (実測で確定):
 *   (A) 名前空間の宣言がまったく無いファイルの全体
 *   (B) `namespace { … }` と書いた波括弧ブロックの中
 *   「波括弧ブロックを閉じた後の素のトップレベル」は言語が許さず
 *   (`No code may exist outside of namespace {}`)、セミコロン形の宣言は
 *   ファイル末尾までグローバルへ戻らない (名前なしのセミコロン形は構文として存在しない)。
 *   セミコロン形と波括弧形の混在も言語が許さない。よって追跡はこの 2 通りで足りる。
 *
 * ★**読めなかった宣言は黙って対象外にしない**。`namespace` の後が
 *   `;` でも `{` でもない形に当たったら `unresolved` として返し、gate を赤くする
 *   (fail-closed。静かに走査域が縮むのを防ぐ)。
 *
 * ★**保証しないもの (誇張しない)**: これは import 構文の完全なパーサではない。
 *   構文エラーになる入力に対する挙動は保証しない (見本は必ず構文として正しいことを
 *   自己検査が確かめる)。グループ use (`use A\B\{C, D};`) は前置きに必ず `\` を含むので
 *   非複合になりえず、中身は読まずに読み飛ばす。
 */
final class NonCompoundGlobalUseScanner
{
    /** 名前空間の宣言が無い。ファイル全体がグローバル領域である。 */
    private const string KIND_NONE = 'none';

    /** セミコロン形の宣言。以降ファイル末尾までグローバルへ戻らない。 */
    private const string KIND_SEMICOLON = 'semicolon';

    /** 波括弧形の宣言。ブロックの中だけがその名前空間である。 */
    private const string KIND_BRACKETED = 'bracketed';

    /**
     * 1 ファイル分の PHP ソースを走査する。
     *
     * @param  string  $source  PHP ソース
     * @param  string  $relative  失敗メッセージに載せる表示名
     * @return array{
     *     violations: list<array{name: string, line: int}>,
     *     hasGlobalRegion: bool,
     *     unresolved: list<string>,
     * }
     */
    public static function scan(string $source, string $relative): array
    {
        /** @var list<PhpToken> $tokens */
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);

        $violations = [];
        $unresolved = [];

        $kind = self::KIND_NONE;
        $namespaceName = '';
        $bodyDepth = 0;
        $blockOpenDepth = null;
        $depth = 0;

        // 名前なしの波括弧ブロック (`namespace { … }`) を 1 度でも開いたか。
        // ★グローバル領域の有無は「import を書ける場所があるか」で決める。
        //   セミコロン形の宣言より前の前置き部分も字面上はグローバルだが、
        //   そこに import は置けない (宣言は先頭の文でなければならない) ので数えない。
        $sawBracketedGlobal = false;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $declaration = self::readNamespaceDeclaration($tokens, $i);

                if ($declaration === null) {
                    $unresolved[] = sprintf(
                        '%s:%d → namespace 宣言の形を読めませんでした (前後のトークン: %s)',
                        $relative,
                        $token->line,
                        self::describeNeighbourhood($tokens, $i),
                    );

                    continue;
                }

                $namespaceName = $declaration['name'];

                if ($declaration['bracketed']) {
                    $kind = self::KIND_BRACKETED;
                    $blockOpenDepth = $depth;
                    $bodyDepth = $depth + 1;
                    $depth++; // 宣言の `{` はここで数える (下の波括弧処理へは渡さない)
                    $sawBracketedGlobal = $sawBracketedGlobal || $namespaceName === '';
                } else {
                    $kind = self::KIND_SEMICOLON;
                    $blockOpenDepth = null;
                    $bodyDepth = $depth;
                }

                $i = $declaration['cursor'];

                continue;
            }

            if ($token->text === '{') {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;

                if ($kind === self::KIND_BRACKETED && $blockOpenDepth !== null && $depth === $blockOpenDepth) {
                    // 波括弧ブロックを出た。次の宣言が来るまでコードは置けない領域である。
                    $namespaceName = '';
                    $bodyDepth = $depth;
                    $blockOpenDepth = null;
                }

                continue;
            }

            $isGlobalImportRegion = $namespaceName === ''
                && $depth === $bodyDepth
                && ($kind !== self::KIND_BRACKETED || $blockOpenDepth !== null);

            if (! $token->is(T_USE) || ! $isGlobalImportRegion) {
                continue;
            }

            $cursor = self::nextSignificant($tokens, $i + 1);
            if ($cursor === null) {
                continue;
            }

            // クロージャの `use ($x)` は import ではない
            if ($tokens[$cursor]->text === '(') {
                continue;
            }

            // `use function` / `use const` の修飾を読み飛ばす (同じ警告が出るため対象に含める)
            if ($tokens[$cursor]->is([T_FUNCTION, T_CONST])) {
                $next = self::nextSignificant($tokens, $cursor + 1);
                if ($next === null) {
                    continue;
                }
                $cursor = $next;
            }

            $i = self::collectUseStatement($tokens, $cursor, $violations);
        }

        return [
            'violations' => $violations,
            'hasGlobalRegion' => $kind === self::KIND_NONE || $sawBracketedGlobal,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * 1 つの use 文の import 要素を評価して violations へ積み、文末の添字を返す。
     *
     * @param  list<PhpToken>  $tokens
     * @param  list<array{name: string, line: int}>  $violations
     * @return int 走査を再開してよい添字 (文末の `;` / グループ use の `{` の直前)
     */
    private static function collectUseStatement(array $tokens, int $cursor, array &$violations): int
    {
        $count = count($tokens);

        $name = '';
        $aliased = false;
        $collecting = true;
        $statementLine = null;

        for ($j = $cursor; $j < $count; $j++) {
            $current = $tokens[$j];

            if ($current->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if ($current->text === ';') {
                self::flush($name, $aliased, $statementLine, $violations);

                return $j;
            }

            if ($current->text === ',') {
                self::flush($name, $aliased, $statementLine, $violations);
                $name = '';
                $aliased = false;
                $collecting = true;

                continue;
            }

            if ($current->is(T_AS)) {
                // この要素は import として実際に効く = 違反ではない
                $aliased = true;
                $collecting = false;

                continue;
            }

            // グループ use (`use A\B\{C, D};`) の前置きは必ず `\` を含むので非複合になりえない。
            // 中身は読まず、波括弧の対応は外側の深さ追跡に任せる。
            if ($current->text === '{') {
                return $j - 1;
            }

            if (! $collecting) {
                continue;
            }

            if ($current->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                $statementLine ??= $current->line;
                $name .= $current->text;
            }
        }

        return $count - 1;
    }

    /**
     * 収集済みの 1 要素を判定して violations へ積む。
     *
     * @param  list<array{name: string, line: int}>  $violations
     */
    private static function flush(string $name, bool $aliased, ?int $statementLine, array &$violations): void
    {
        if ($aliased || $statementLine === null) {
            return;
        }

        // 先頭の `\` は付いていても PHP は同じ警告を出す (実測) ので、除いてから段数を見る。
        $normalized = ltrim($name, '\\');

        if ($normalized === '' || str_contains($normalized, '\\')) {
            return;
        }

        $violations[] = ['name' => $normalized, 'line' => $statementLine];
    }

    /**
     * `namespace` トークンから宣言 1 つ分を読む。
     *
     * @param  list<PhpToken>  $tokens
     * @return array{name: string, bracketed: bool, cursor: int}|null cursor は宣言の最後 (`;` / `{`) の添字
     */
    private static function readNamespaceDeclaration(array $tokens, int $index): ?array
    {
        $cursor = self::nextSignificant($tokens, $index + 1);
        if ($cursor === null) {
            return null;
        }

        $name = '';
        while ($tokens[$cursor]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
            $name .= $tokens[$cursor]->text;
            $next = self::nextSignificant($tokens, $cursor + 1);
            if ($next === null) {
                return null;
            }
            $cursor = $next;
        }

        return match ($tokens[$cursor]->text) {
            ';' => ['name' => $name, 'bracketed' => false, 'cursor' => $cursor],
            '{' => ['name' => $name, 'bracketed' => true, 'cursor' => $cursor],
            default => null,
        };
    }

    /**
     * index 以降で最初の意味のあるトークンの添字。
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($i = $index; $i < $count; $i++) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * 読めなかった位置の前後 3 トークンの字面 (赤くなったときの切り分け用)。
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function describeNeighbourhood(array $tokens, int $index): string
    {
        $from = max(0, $index - 3);
        $to = min(count($tokens) - 1, $index + 3);

        $pieces = [];
        for ($i = $from; $i <= $to; $i++) {
            $pieces[] = trim($tokens[$i]->text);
        }

        return implode(' ', array_filter($pieces, static fn (string $piece): bool => $piece !== ''));
    }
}
```

### tests/Support/GlobalUse/PhpLintOracle.php
```php
<?php

declare(strict_types=1);

namespace Tests\Support\GlobalUse;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * `php -l` を真値として、非複合名の import の警告を取り出す。
 *
 * ★実行系は **いまテストを走らせている PHP そのもの** (`PHP_BINARY`) を使う。
 *   別の php を探しに行かないので「手元と CI で版が違うと結果が変わる」問題は起きない
 *   (その実行系が警告を出す形を、その実行系で検出できているかを見る検査になる)。
 * ★`-n` で php.ini を読ませない (opcache 等の状態に左右されない)。
 * ★警告は **標準出力**へ出る (実測)。標準エラーも合わせて返すのは、
 *   プロセスの起動失敗や実行環境側の異常が標準エラーにしか出ないことがあるためである。
 * ★`syntaxValid` の主判定は **終了コード**である (実測: 構文が正しければ警告が出ていても 0、
 *   構文エラーなら 255)。「構文エラーなし」の文言は診断用にだけ使い判定には使わない
 *   (文言は版で変わりうるが終了コードの意味は変わらない)。
 */
final class PhpLintOracle
{
    /** 警告文から名前と行を取り出す規則。文言が変わったら 0 件になるので空振り検知が要る。 */
    private const string WARNING_PATTERN = "/non-compound name '([^']+)' has no effect in .+ on line (\\d+)/";

    /**
     * 見本ファイルに対して `php -l` を **1 回だけ**実行し、結果を丸ごと返す。
     *
     * @return array{
     *     warnings: list<array{name: string, line: int}>,
     *     syntaxValid: bool,
     *     exitCode: int,
     *     stdout: string,
     *     stderr: string,
     * }
     */
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

        $exitCode = $process->getExitCode();
        if ($exitCode === null) {
            // null を 0 と読むと構文エラーを合格へ倒しかねないので例外にする (fail-closed)。
            throw new RuntimeException('php -l の終了コードを取得できませんでした: '.$absolutePath);
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        $matched = preg_match_all(self::WARNING_PATTERN, $stdout, $matches, PREG_SET_ORDER);
        if ($matched === false) {
            throw new RuntimeException('php -l の出力の照合に失敗しました: '.$absolutePath);
        }

        $warnings = [];
        foreach ($matches as $match) {
            $warnings[] = ['name' => $match[1], 'line' => (int) $match[2]];
        }

        return [
            'warnings' => $warnings,
            'syntaxValid' => $exitCode === 0,
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
```

### tests/Architecture/NoNonCompoundGlobalUseTest.php
```php
<?php

declare(strict_types=1);

use Tests\Support\GlobalUse\NonCompoundGlobalUseScanner;
use Tests\Support\GlobalUse\PhpLintOracle;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * Architecture invariant: **グローバル名前空間**にあるコードで非複合名の `use` を書かない。
 *
 * SoT = PHP の言語仕様であり、**真値は `php -l` の警告**である (家系の正典 t1)。
 *   Warning: The use statement with non-compound name 'X' has no effect
 * この警告が出る形は 3 種の取り込み (`use` / `use function` / `use const`) すべてで、
 * 先頭にバックスラッシュを付けた形でも同じである (実測)。
 * 逆に**別名が付いた形 (`use Foo as Bar;`) には警告が出ない** — 別名の付いた取り込みは
 * 実際に効くためで、これを違反として数えるのは偽陽性である。
 *
 * なぜ「出力が汚れるだけ」で済ませないか (実測):
 *   - この警告が set_error_handler に届くかは **環境依存** (opcache 状態 /
 *     ファイルの初回コンパイル時点)。同一 devcontainer で「届く」「届かない」両方を観測した
 *   - 届いた場合、Laravel の HandleExceptions::handleError は
 *     `error_reporting() & $level` (本アプリは -1) で **ErrorException を throw する**
 *   - migration は Migrator が実行時に require する = そこで throw されれば
 *     RefreshDatabase が死に **全テストが全滅する**
 * つまり「今日は raw output 汚染で済んでいるが、いつ全滅へ化けてもおかしくない非決定的な地雷」。
 *
 * 走査対象: git 追跡下の *.php (ただし *.blade.php を除く)。列挙は
 * `Tests\Support\TrackedPhpSourceFiles` に集約してある (同じ列挙を 2 本持たない。
 * 走査域の定義と限界は同クラスの docblock が正本)。
 * git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 * **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * **既知の限界**: 未追跡 (git add 前) のファイルは走査されない。gate が守る境界は
 * commit / CI であり、そこでは必ず追跡下にあるため実効性は損なわれない。
 *
 * allowlist は設けない: 非複合 global use に正当な用途は存在しない (常に無効な import)。
 *
 * ★**検出力の裏取り**: 見本 12 本 (検出 7 / 無違反 5) を `php -l` の警告と
 *   名前・行番号まで照合する。見本は `.php.txt` で置く — `.php` にすると
 *   本 gate 自身と `StrictTypesDeclarationGateTest` /
 *   `ForbiddenStatementTokenInvariantTest` の母集団に入り、
 *   **わざと違反させた見本で本番の gate が赤くなる** (`php -l` は拡張子を見ない)。
 * ★**照合の空振りも検知する**: `php -l` の警告文が将来変わると真値が 0 件になり、
 *   照合が「両方 0 件で一致」して静かに無力化する。真値の総数の床を別の検査で固定する。
 */

/** 見本の置き場所 (走査器の自己検査の入力)。 */
const GLOBAL_USE_FIXTURE_DIR = __DIR__.'/fixtures/global-use';

/**
 * 見本の完全な一覧。差し替え・こっそり削除で検出力が落ちるのを止める。
 *
 * @var array<string, bool> 見本名 => 検出側か (true = 警告が出る形)
 */
const GLOBAL_USE_FIXTURES = [
    'detects-class' => true,
    'detects-function-const' => true,
    'detects-leading-backslash' => true,
    'detects-comma-list' => true,
    'detects-partial-alias' => true,
    'detects-bracketed-global' => true,
    'detects-bracketed-after-named' => true,
    'clean-compound' => false,
    'clean-aliased' => false,
    'clean-named-namespace' => false,
    'clean-bracketed-named' => false,
    'clean-trait-and-closure' => false,
];

/**
 * 見本 1 本につき `php -l` を **1 回だけ**実行した結果。
 *
 * ★各検査の中から `inspect()` を呼ぶ形にすると、「同じ 1 回の結果を共有する」という
 *   契約が書いてあるだけになり、同じ見本を何度も実行しやすくなる。ここで 1 度だけ回す。
 *
 * @var array<string, array{
 *     warnings: list<array{name: string, line: int}>,
 *     syntaxValid: bool,
 *     exitCode: int,
 *     stdout: string,
 *     stderr: string,
 * }>
 */
$globalUseOracle = [];
foreach (array_keys(GLOBAL_USE_FIXTURES) as $globalUseFixtureName) {
    $globalUseOracle[$globalUseFixtureName] = PhpLintOracle::inspect(
        GLOBAL_USE_FIXTURE_DIR.'/'.$globalUseFixtureName.'.php.txt'
    );
}

/**
 * 名前と行の一覧を、両側で同じ規則に整列する。
 *
 * ★**集合にしない**。同じ名前・同じ行の警告が 2 回出る場合に、集合化すると
 *   走査器側の重複や欠落を隠してしまう。重複を保ったまま整列して比べる。
 *
 * @param  list<array{name: string, line: int}>  $entries
 * @return list<string>
 */
function globalUseSorted(array $entries): array
{
    $formatted = array_map(
        static fn (array $entry): string => sprintf('%d:%s', $entry['line'], $entry['name']),
        $entries,
    );
    sort($formatted);

    return $formatted;
}

/**
 * 見本を走査器に掛ける。
 *
 * @return array{
 *     violations: list<array{name: string, line: int}>,
 *     hasGlobalRegion: bool,
 *     unresolved: list<string>,
 * }
 */
function globalUseScanFixture(string $name): array
{
    $path = GLOBAL_USE_FIXTURE_DIR.'/'.$name.'.php.txt';
    $source = file_get_contents($path);

    if ($source === false) {
        throw new RuntimeException('見本を読めませんでした: '.$path);
    }

    return NonCompoundGlobalUseScanner::scan($source, $name.'.php.txt');
}

/**
 * git 追跡下全体の走査結果。
 *
 * @return array{
 *     violations: list<string>,
 *     globalRegionFiles: list<string>,
 *     unresolved: list<string>,
 *     totalFiles: int,
 * }
 */
function globalUseScanTrackedTree(): array
{
    $violations = [];
    $globalRegionFiles = [];
    $unresolved = [];
    $total = 0;

    foreach (TrackedPhpSourceFiles::all(base_path()) as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $total++;

        $scanned = NonCompoundGlobalUseScanner::scan($source, $target['relative']);

        if ($scanned['hasGlobalRegion']) {
            $globalRegionFiles[] = $target['relative'];
        }
        foreach ($scanned['violations'] as $violation) {
            $violations[] = sprintf('%s:%d → use %s;', $target['relative'], $violation['line'], $violation['name']);
        }
        $unresolved = array_merge($unresolved, $scanned['unresolved']);
    }

    return [
        'violations' => $violations,
        'globalRegionFiles' => $globalRegionFiles,
        'unresolved' => $unresolved,
        'totalFiles' => $total,
    ];
}

test('グローバル名前空間に非複合 use が存在しない', function (): void {
    $result = globalUseScanTrackedTree();

    expect($result['violations'])->toBe([],
        '非複合 global use を検出しました。PHP は「has no effect」warning を出し import は無効です。'
        .'use 文を削除して参照側を \\FQCN (例: \\RuntimeException) にしてください。'
        .PHP_EOL.implode(PHP_EOL, $result['violations']));
});

test('走査が空振りしていない (母集団と走査域が縮退していない)', function (): void {
    $result = globalUseScanTrackedTree();

    expect($result['totalFiles'])->toBeGreaterThan(0);

    // 件数の床は置かない (整理で自然に減ることは正常であり、本質でない赤を生む)。
    // 目的に直結するのは「グローバル領域を持つファイルが 1 本も無くなっていないこと」と
    // 「構造的に名前空間を持たない置き場がどちらも生きていること」である。
    expect($result['globalRegionFiles'])->not->toBeEmpty();

    $hasMigration = array_filter(
        $result['globalRegionFiles'],
        static fn (string $relative): bool => str_starts_with($relative, 'database/migrations/'),
    );
    $hasArchitectureTest = array_filter(
        $result['globalRegionFiles'],
        static fn (string $relative): bool => str_starts_with($relative, 'tests/Architecture/'),
    );

    expect($hasMigration)->not->toBeEmpty('database/migrations/ が走査域から落ちています');
    expect($hasArchitectureTest)->not->toBeEmpty('tests/Architecture/ が走査域から落ちています');

    // 読めなかった namespace 宣言は黙って対象外にしない (fail-closed)。
    expect($result['unresolved'])->toBe([], implode(PHP_EOL, $result['unresolved']));
});

test('見本の一覧が完全である (差し替え・削除で検出力が落ちない)', function (): void {
    $onDisk = glob(GLOBAL_USE_FIXTURE_DIR.'/*.php.txt');
    expect($onDisk)->toBeArray();

    $actual = array_map(
        static fn (string $path): string => basename($path, '.php.txt'),
        is_array($onDisk) ? $onDisk : [],
    );
    sort($actual);

    $expected = array_keys(GLOBAL_USE_FIXTURES);
    sort($expected);

    expect($actual)->toBe($expected);
    expect(count(array_filter(GLOBAL_USE_FIXTURES)))->toBe(7);
    expect(count(array_filter(GLOBAL_USE_FIXTURES, static fn (bool $d): bool => ! $d)))->toBe(5);
});

test('見本が構文として正しい (判定は終了コード)', function () use ($globalUseOracle): void {
    foreach ($globalUseOracle as $name => $inspection) {
        expect($inspection['syntaxValid'])->toBeTrue(sprintf(
            "見本 %s が構文として正しくありません。見本が parse error になると警告が 1 件も出ず、\n"
            ."検出力が落ちたのか見本が壊れたのかを切り分けられなくなります。\n"
            ."PHP_VERSION=%s PHP_BINARY=%s exitCode=%d\n--- stdout ---\n%s\n--- stderr ---\n%s",
            $name,
            PHP_VERSION,
            PHP_BINARY,
            $inspection['exitCode'],
            $inspection['stdout'],
            $inspection['stderr'],
        ));
    }
});

test('真値が空振りしていない (php -l の警告文の変化を検知する)', function () use ($globalUseOracle): void {
    $total = 0;
    $diagnostics = [];

    foreach (GLOBAL_USE_FIXTURES as $name => $detects) {
        if (! $detects) {
            continue;
        }
        $total += count($globalUseOracle[$name]['warnings']);
        $diagnostics[] = sprintf(
            "--- %s (exitCode=%d)\n--- stdout ---\n%s\n--- stderr ---\n%s",
            $name,
            $globalUseOracle[$name]['exitCode'],
            $globalUseOracle[$name]['stdout'],
            $globalUseOracle[$name]['stderr'],
        );
    }

    expect($total)->toBeGreaterThan(0, sprintf(
        "検出側の見本から真値が 1 件も取れませんでした。php -l の警告文が変わると、\n"
        ."照合が「両方 0 件で一致」して静かに無力化します。\n"
        ."PHP_VERSION=%s PHP_BINARY=%s\n%s",
        PHP_VERSION,
        PHP_BINARY,
        implode(PHP_EOL, $diagnostics),
    ));
});

test('検出側の見本で、走査器の判定が php -l の真値と名前・行まで一致する', function () use ($globalUseOracle): void {
    foreach (GLOBAL_USE_FIXTURES as $name => $detects) {
        if (! $detects) {
            continue;
        }

        $scanned = globalUseScanFixture($name);

        expect($scanned['unresolved'])->toBe([], implode(PHP_EOL, $scanned['unresolved']));
        expect(globalUseSorted($scanned['violations']))
            ->toBe(globalUseSorted($globalUseOracle[$name]['warnings']), '見本 '.$name.' の判定が真値と一致しません');
    }
});

test('無違反の見本で、真値も走査器も 0 件である', function () use ($globalUseOracle): void {
    foreach (GLOBAL_USE_FIXTURES as $name => $detects) {
        if ($detects) {
            continue;
        }

        $scanned = globalUseScanFixture($name);

        expect($globalUseOracle[$name]['warnings'])->toBe([], '見本 '.$name.' に php -l が警告を出しました');
        expect($scanned['unresolved'])->toBe([], implode(PHP_EOL, $scanned['unresolved']));
        expect(globalUseSorted($scanned['violations']))->toBe([], '見本 '.$name.' を誤検出しました');
    }
});
```

### 既存検体の代表 (clean-named-namespace.php.txt / detects-bracketed-after-named.php.txt)
```
<?php

// 無違反: セミコロン形の宣言はファイル末尾までグローバルへ戻らない。
// 続けてもう 1 つ宣言してもどちらも名前つきである。
namespace App;

use Foo;

namespace Bar;

use Baz;
---
<?php

// 検出: 名前つきの波括弧ブロックの中は対象外で、そのあとに置いたグローバルのブロックは対象。
namespace Bar {
    use Qux;
}

namespace {
    use Foo;
}
```
