# 詳細設計レビュー Round 3

Round 2 の Warning 2 件と Suggestion 3 件を処理した。
Warning のうち Pint の 1 件だけは**実測を根拠に反論**している (下記マトリクス参照)。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

## [Warning] 施策2: 空コンストラクタ `private function __construct() {}` が Pint に適合しない
- 判断: **反論する** (根拠は実測)
- 根拠: 本リポジトリの Pint は laravel preset で、**この 1 行形をそのまま通す**。
  実測: 同じ書き方を持つ `tests/Support/TemplateDivergence/LedgerPins.php` に対し
  `vendor/bin/pint --test tests/Support/TemplateDivergence/LedgerPins.php` が
  `{"tool":"pint","result":"passed"}` を返す。同形は `tests/Support/` 配下に 10 件以上ある
  (`ArchTokenStream` / `SourceLiterals` / `ArchBaseline` / `StoryFrontMatterPins` 等)。
  波括弧を次行へ割ると**既存の全 Support クラスと書式が食い違う**ので採らない。
- 対応内容: 設計を変えず、施策 2 のコード直前に**書式の注記**として実測の根拠を書き足した
  (実装者が Round 2 の指摘を見て割る形に変えてしまわないようにするため)。

## [Warning] 施策7: 「列挙すべき経路が現時点で 0 件」が 3 経路列挙と矛盾して見える
- 判断: 対応する
- 根拠: 指摘のとおり。0 件なのは「宣言値が実効値にならない**例外経路**」であって、
  待ち予算を**読む経路** (3 本) ではない。このままだと「読む経路が無い」とも読める。
- 対応内容: 施策 7 のテスト計画を
  「**『宣言値が実効値にならない例外経路』が現時点で 0 件**であり、空の例外一覧を固定する
  検査は『今必要なものだけ作る』に反するため」へ書き換え、
  「0 件なのは例外経路であって読む経路ではない (読む経路は 3 本あり上の箇条で列挙)」を併記した。

## [Suggestion] 施策7 の名称が実態 (既存箇条の書き換え + 3 箇条追記) と合っていない
- 判断: 対応する
- 対応内容: 施策一覧・見出し・テストファースト手順の 3 か所を
  「実効性の運用契約の条件付き化と 3 箇条追記」へ改めた。

## [Suggestion] 想定規模の「既存 5 ファイル」は実際には 6 ファイル
- 判断: 対応する
- 対応内容: 実装モードの表で 6 ファイルを名前つきで列挙した
  (`PromptClientTimeoutInvariantTest.php` / `AnalysisBudget.php` /
  `AnalysisTokenBudgetInvariantTest.php` / `docs/template-divergence.md` /
  `LedgerPins.php` / `docs/architecture.md`)。

## [Suggestion] 「成果物はすべて devnotes/ 配下」は変更先 (tests/ docs/) と合わない
- 判断: 対応する
- 対応内容: 「Artifact は使用せず、成果物はすべてリポジトリ内のファイルとして出力する
  (設計は `devnotes/`、実装は `tests/` と `docs/`)」へ改めた。

## 施策1 / 3 / 4 / 5 / 6: APPROVE
- 判断: 変更なし

---

## 修正後の詳細設計書 (全文)

# 詳細設計: llm-prompt-wait-budget-v1 (LLM 待ち予算の読み取り規則を単一化する)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本施策はすべて `tests/` と `docs/` の変更であり、2・4・5・6・7・8 に触れる面を持たない。
> 1 に対しては「負例で検出力を裏取りした自己テスト」までを完了条件にする。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。ただし `phpstan.neon` の `paths` は
  `app` `config` `database` `routes` であり **`tests` を含まない**。本設計の変更は
  すべて `tests/` 配下なので**静的解析の対象外**である。
  「level 10 が通っている」を「本変更の型が保証されている」と読み替えない
  (代わりに PHPDoc の array shape と、呼び出し側に解釈を残さない構造で担保する)
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成（本設計では DB を使う施策は無い）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）
- `declare(strict_types=1)` + 日本語コメント（`StrictTypesDeclarationGateTest` が
  git 追跡下の PHP 全数を deny-by-default で強制する = 新規 PHP 2 本にも必須）
- PHP 8.4 + Laravel 12

### 本設計に固有の強制規約 (AGENTS.md)

- **「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の適用対象**である
  (判定条件と走査結果の使い方を変える)。
  (1) 負例と正例をテストファーストで先に赤くする / (2) 解決できない形を落とす分岐 /
  (3) 走査が空振りしていないことの検査 / (4) docblock に走査対象と保証しないものを書く。
- 「静的検査 (gate) と走査器の共通規約」5 条のうち適用されるのは
  **(b) fail-closed / (c) 負例での裏取り / (d) 使わない収集を作らない** の 3 条。
  (a) はクラス名・名前参照を解決しないので無関係、(e) は語彙一致を判定しないので無関係。
- 「後方互換の並走を残さない」(思考原則 3): 旧 3 実装は**同じ PR で削除する**。
  移行期間・feature flag・旧関数の残置はいずれも作らない。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) (Codex `gpt-5.6-terra` Round 3 で APPROVED)
- Codex 議論履歴: `conceptual-review-round-{1,2,3}.md` /
  `codex-history/conceptual-review-{prompt,decisions}-round-*.md`
- 正典 (lctl feature `llm-prompt-wait-budget` / canonical v1) の参照実装:
  `spirux:tests/Support/PromptWaitBudget.php` + `spirux:tests/Architecture/PromptYamlContractTest.php`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 検出器の自己テストと見本ファイル (先に赤くする) | `tests/Unit/Architecture/PromptWaitBudgetTest.php` (新規) / `tests/Architecture/fixtures/prompt-wait-budget/*.yaml` (新規 12 本) | 最高 |
| 2 | 単一読み取り器 `PromptWaitBudget` の新設 | `tests/Support/PromptWaitBudget.php` (新規) | 最高 |
| 3 | gate の書き換え (インライン判定の削除) と分母の到達証明 | `tests/Architecture/PromptClientTimeoutInvariantTest.php` | 高 |
| 4 | 下限式側の読み出しを読み取り器へ寄せる | `tests/Support/AnalysisBudget.php` | 高 |
| 5 | 素の配列参照を読み取り器へ寄せる | `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | 高 |
| 6 | 乖離台帳への登録 (D50) と件数 pin の更新 | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` | 高 (施策 3 と同一 PR 必須) |
| 7 | 実効性の運用契約の条件付き化と 3 箇条追記 | `docs/architecture.md` | 中 |

**波及の全体像 (施策横断)**

- TypeScript 型定義: **なし** (フロントに一切触れない)
- Inertia Props / API Resource / DTO: **なし** (アプリ実行コードに触れない)
- migration / DB: **なし**
- 既存テストファイルの更新: 施策 3 / 5 (書き換え)。
  **削除する既存テストは無い** (test の本数は施策 3 で 1 → 2 に増える)
- `resources/prompts/*.yaml` の内容: **変更しない** (値は 360 / 60 のまま)

---

## 施策 1: 検出器の自己テストと見本ファイル (先に赤くする)

### 変更箇所

- 新規: `tests/Unit/Architecture/PromptWaitBudgetTest.php`
- 新規: `tests/Architecture/fixtures/prompt-wait-budget/` に 12 本の見本 YAML

置き場の根拠: AGENTS.md「負例の置き場は 3 通りとも認める」のうち
**見本ファイル (`tests/Architecture/fixtures/`) + 検出器の自己検査 (`tests/Unit/Architecture/`)**
の組み合わせを採る (既存の `ForbiddenStatementScannerTest` /
`StrictTypesDeclarationScannerTest` と同じ置き方)。
見本を**実ファイル**にするのは、`violations()` が「ファイルを読む口」であり、
parse の委譲まで含めて 1 本の経路として裏取りするためである。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策が新設する 1 本のみ

### 見本ファイルの一覧 (12 本)

`tests/Architecture/fixtures/prompt-wait-budget/` 配下。**拡張子は `.yaml`** のままにする
(リポジトリ全数を走査する gate のうち `.yaml` を見るのは
`PasswordConfirmSurfaceAbsenceGateTest` だけで、その走査根は
`.github app bootstrap config lang resources routes scripts` の 8 つで **`tests` を含まない**。
`PromptYaml::paths()` の走査根は `resources/prompts` なので見本は分母に入らない)。

| ファイル | 中身 | 期待 |
|---|---|---|
| `missing-client-options.yaml` | `name: x` のみ | 違反 (未宣言) |
| `client-options-not-array.yaml` | `client_options: 300` | 違反 (非配列) |
| `missing-timeout.yaml` | `client_options:` に `retries: 1` だけ | 違反 (キー無し) |
| `zero.yaml` | `timeout: 0` | 違反 (非正) |
| `negative.yaml` | `timeout: -1` | 違反 (非正) |
| `numeric-string.yaml` | `timeout: "300"` | 違反 (非 int) |
| `float.yaml` | `timeout: 300.5` | 違反 (非 int) |
| `bool.yaml` | `timeout: true` | 違反 (非 int) |
| `null.yaml` | `timeout:` (空値) | 違反 (非 int) |
| `declared.yaml` | `timeout: 300` | **正例** (違反 0 件) |
| `broken.yaml` | 構文不正 (`client_options: [unclosed`) | 違反 (parse 不能) |
| `list-top-level.yaml` | `- a` / `- b` (最上位が list) | 違反 (非 map) |

### 自己テストの内容 (5 本の test)

```php
<?php

declare(strict_types=1);

use Tests\Support\PromptWaitBudget;

/*
 * `Tests\Support\PromptWaitBudget` の検出力を**両方向**で固定する (AGENTS.md 共通規約 (c))。
 *
 * ★本自己テストが本読み取り器の存在理由である。待ち予算の判定を各 gate へインラインで
 *   書くと「0 以下を見ていない実装」が混ざっても誰も気付けない
 *   (実際に AnalysisBudget::clientTimeoutSecondsFromYaml() が `timeout: 0` を通していた)。
 *
 * 見本ファイル: tests/Architecture/fixtures/prompt-wait-budget/
 */

/** 見本ディレクトリの絶対パス。 */
function promptWaitBudgetFixtureDir(): string
{
    return base_path('tests/Architecture/fixtures/prompt-wait-budget');
}

test('待ち予算の 9 類型の違反がラベルの集合として全部上がる', function (): void {
    $bad = [
        'missing-client-options.yaml', 'client-options-not-array.yaml', 'missing-timeout.yaml',
        'zero.yaml', 'negative.yaml', 'numeric-string.yaml', 'float.yaml', 'bool.yaml', 'null.yaml',
    ];

    // ★件数ではなく**ラベルの集合**で照合する (1 件取りこぼして別の 1 件を二重報告しても
    //   件数だけは一致してしまう = 偽の緑)。
    $labels = [];
    foreach ($bad as $name) {
        foreach (PromptWaitBudget::violations(promptWaitBudgetFixtureDir().'/'.$name, $name) as $violation) {
            $label = strstr($violation, ': ', true);
            expect($label)->not->toBeFalse("違反メッセージにラベルがありません: {$violation}");
            $labels[] = (string) $label;
        }
    }

    sort($labels);
    $expected = $bad;
    sort($expected);
    expect($labels)->toBe($expected);
});

test('正例 (正の整数を宣言した見本) を誤検出しない', function (): void {
    expect(PromptWaitBudget::violations(promptWaitBudgetFixtureDir().'/declared.yaml', 'declared.yaml'))
        ->toBe([]);
});

test('解決できない形は 3 種それぞれが別の分類で違反になる (fail-closed)', function (): void {
    // ★1 件だけ確かめて「解決不能形は落ちる」と主張しない。分岐は 3 つある。
    // ★**分類まで固定する**。ファイル不在が再び parse 失敗へ統合されても
    //   「違反が空でない」だけの照合では緑のままになる。
    //   pin するのはラベルの**安定部分**だけで、vendor の例外本文は pin しない。
    $unresolvable = [
        'ファイル不在' => [promptWaitBudgetFixtureDir().'/does-not-exist.yaml', 'prompt YAML が無い'],
        'parse 不能' => [promptWaitBudgetFixtureDir().'/broken.yaml', 'parse 失敗'],
        '最上位が map でない' => [
            promptWaitBudgetFixtureDir().'/list-top-level.yaml',
            'top-level が連想配列(map)でない',
        ],
    ];

    foreach ($unresolvable as $kind => [$path, $expectedFragment]) {
        $violations = PromptWaitBudget::violations($path, basename($path));
        expect($violations)->not->toBe([], "{$kind} が違反として上がっていません");

        $joined = implode(PHP_EOL, $violations);
        expect($joined)->toContain(basename($path));
        expect($joined)->toContain($expectedFragment, "{$kind} の分類が変わっています");
    }
});

test('requirePositive は違反があれば例外にする (違反を無視しない)', function (): void {
    expect(fn (): int => PromptWaitBudget::requirePositive(
        promptWaitBudgetFixtureDir().'/zero.yaml', 'zero.yaml',
    ))->toThrow(RuntimeException::class);
});

test('requirePositive は正常な見本から正の整数を返す', function (): void {
    expect(PromptWaitBudget::requirePositive(
        promptWaitBudgetFixtureDir().'/declared.yaml', 'declared.yaml',
    ))->toBe(300);
});
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`function promptWaitBudgetFixtureDir(): string`)
- [x] null 安全 (`strstr()` の `false` を `expect(...)->not->toBeFalse()` で明示的に落とす)
- [x] DTO を返している — **非該当** (テスト支援コードであり HTTP 応答を作らない)
- [x] Generics の型パラメータ — 非該当
- 備考: `tests` は `phpstan.neon` の `paths` 外。型注釈は IDE と将来の解析のために書く

### テスト計画

- [x] **これがテストファーストの「先に赤くする」段**である。施策 2 の前に書き、
      `Tests\Support\PromptWaitBudget` が存在しないことで **5 本すべてが赤**になることを確認する
- [x] 施策 2 の後は 5 本すべて緑
- [x] **負のコントロールの裏取り**: 施策 2 の完成後に読み取り器の
      `<= 0` 分岐を一時的に削り、`zero.yaml` / `negative.yaml` のラベルが集合から落ちて
      **1 本目の test が赤くなる**ことを確認する (確認したら戻す)。
      同じく `is_int()` を `is_numeric()` へ緩めると **`numeric-string` と `float` の 2 本**が
      集合から落ちて赤になる (`is_numeric(true)` と `is_numeric(null)` はどちらも `false` なので
      `bool.yaml` / `null.yaml` は違反のまま上がる — ここを「3 本落ちる」と書くと実測と食い違う)。
      実測の記録は `devnotes/20260824-1016-llm-prompt-wait-budget-v1/red-green-log.md` に残す
- [x] 個別の `DatabaseTransactions` を使っていない (DB を使わない)

### リスク

- 見本の `broken.yaml` は**意図的に構文が壊れた YAML** である。リポジトリに YAML linter は
  無い (CI / `pnpm lint` / prettier のいずれも `*.yaml` を検査しない) ので副作用は無いが、
  エディタが警告を出す。ファイル冒頭に「意図的に壊してある」コメントを置く
  (コメント行は parse 失敗の前に読まれないので判定には影響しない)

---

## 施策 2: 単一読み取り器 `PromptWaitBudget` の新設

### 変更箇所

- 新規: `tests/Support/PromptWaitBudget.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 1 (自己テスト) / 施策 3・4・5 (呼び出し側)

### 変更後コード

**書式の注記**: 空のコンストラクタは `private function __construct() {}` (1 行) で書く。
本リポジトリの Pint 設定 (laravel preset) はこの形を**通す** — 実測: 同じ書き方の
`tests/Support/TemplateDivergence/LedgerPins.php` に対し
`vendor/bin/pint --test` が `{"tool":"pint","result":"passed"}` を返す
(同形は `tests/Support/` 配下に 10 件以上ある)。波括弧を次行へ割る形にはしない。

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * prompt YAML が宣言する「LLM の待ち予算」(`client_options.timeout`) の**唯一の読み取り器**。
 *
 * lctl 機能台帳 feature `llm-prompt-wait-budget` の正典 v1 (単一読み取り器 + 検出器自己テスト形)。
 * 設計: devnotes/20260824-1016-llm-prompt-wait-budget-v1/
 *
 * 【走査対象】呼び出し側が渡した prompt YAML の絶対パス 1 本。
 *   分母の列挙 (再帰全数走査) は `Tests\Support\PromptYaml::paths()` の責務であり、
 *   本クラスは**列挙しない** (判定だけを持つ)。
 *
 * 【既定拒否】次はすべて違反である。
 *   1. `client_options` が無い (未宣言)
 *   2. `client_options` が配列でない
 *   3. `client_options.timeout` キーが無い
 *   4. `timeout` が `is_int()` でない (数値文字列 `"300"` / 小数 / 真偽値 / 空値を含む)
 *   5. `timeout` が `<= 0`
 *
 * 【公開面】用途の違う 2 口だけを公開し、**どちらも検査済みの結果しか返さない**。
 *   未検査の `timeout` を外へ出す口を作らないので、呼び出し側に
 *   `is_int()` / `> 0` の再判定 (= 第 4 の読み取り実装) が生まれない。
 *   - `violations()`: gate 用。違反ラベルの列 (空なら適合)
 *   - `requirePositive()`: 仕様値との突合用。違反が 1 件でもあれば例外、適合なら正の整数
 *   判定の純関数 `evaluate()` は private である (自己テストは公開 2 口を通して行う)。
 *
 * 【解決できない形は落とす (fail-closed)】3 段のどこでも**無言で null を返さない**。
 *   段 1: ファイルが無い → 独立したラベル。`Yaml::parseFile()` は不在も `ParseException`
 *         にするため、段 2 に混ぜると「構文が壊れている」と区別できなくなる。
 *         走査由来のパスでは起きないが `requirePositive()` は名前から組んだパスを受ける
 *         (prompt の改名で現実に起きる)。
 *   段 2: parse 不能 / 最上位が map でない → `PromptYaml::parseOrFail()` が積む既存の 2 ラベル。
 *         **本クラスは自前の `catch` を書かない** (分類は既存の共有ヘルパに従う)。
 *         ★ただし同ヘルパは `Yaml::parseFile()` の投げる `Throwable` をまとめて
 *         「parse 失敗」へ分類するため、**構文エラーと vendor 内部のエラーの区別までは
 *         保証しない** (ヘルパは採用時債務として凍結されており本 PR では変えない)。
 *   段 3: 上記 5 類型 → `evaluate()` が積む。
 *
 * 【保証しないもの (誇張しない)】
 *   - **宣言値が実効値であることは主張しない**。見るのは宣言の有無と型と正負だけである。
 *     実効性は 3 つの前提に依存し、**そのどれも本クラスは見ていない** —
 *     (i) `app/Prompts/` の factory が vendor の `$clientOptions` クラスプロパティを
 *     設定しないこと、(ii) `resources/prompts` を読む非 PHP の実装が無いこと、
 *     (iii) vendor の解決順序が「クラスプロパティ > YAML > config」であること。
 *     2026-08-24 に実読で確認した事実であり、機械では見ていない。
 *     崩れたときの手当ては `docs/architecture.md` §AI 解析ジョブの運用契約 に書いてある。
 *   - **待ち予算の値の妥当性は判定しない** (360 秒が適切かは本クラスの範囲外)。
 *   - **4 本目の読み取り実装が生まれることは機械では止めない**。字句走査では
 *     「読み取り実装」と失敗メッセージ中の文字列を区別できず、区別のために目録へ
 *     メッセージ側まで登録すると保護しないのに更新が要る目録になるため作らない。
 *     唯一の読み取り器であることは本 docblock の宣言とレビューが担う。
 *   - 段 2 のラベルは共有ヘルパが**絶対パス**で積む (段 1・段 3 は `$label`)。
 *     ラベル集合での照合は段 3 に対してのみ成立する。
 *   - **parse 段の失敗分類は `PromptYaml::parseOrFail()` に従う**。同ヘルパは
 *     `Yaml::parseFile()` の `Throwable` を parse 失敗へ分類するので、構文エラーと
 *     vendor 内部エラーの区別は保証しない。
 */
final class PromptWaitBudget
{
    /** インスタンス化しない (判定の置き場)。 */
    private function __construct() {}

    /**
     * 待ち予算の契約違反 (gate 用)。適合なら空配列。
     *
     * @param  string  $absolutePath  読む YAML の絶対パス
     * @param  string  $label  違反メッセージに出す識別子 (走査根からの相対パス等)
     * @return list<string>
     */
    public static function violations(string $absolutePath, string $label): array
    {
        return self::read($absolutePath, $label)['violations'];
    }

    /**
     * 仕様値との突合に使う正の整数。違反が 1 件でもあれば例外にする。
     *
     * ★`violations()` と**同じ private 判定**を通るので、「gate は落とすが突合は通る」
     *   食い違いが構造的に起きない。
     *
     * @throws RuntimeException 契約違反 (未宣言 / 型違い / 非正 / 解決不能)
     */
    public static function requirePositive(string $absolutePath, string $label): int
    {
        $result = self::read($absolutePath, $label);

        if ($result['violations'] !== []) {
            throw new RuntimeException(
                'LLM の待ち予算の宣言が契約を満たしていません: '.implode(' / ', $result['violations']),
            );
        }

        $timeout = $result['timeout'];
        if ($timeout === null) {
            // 到達しない (違反 0 件なら evaluate() は必ず int を返す)。
            // 到達したら読み取り器の不変条件が壊れているので黙って 0 を返さず落とす。
            throw new RuntimeException("待ち予算の読み取りが不整合です: {$label}");
        }

        return $timeout;
    }

    /**
     * 読み取りの 3 段 (ファイル存在 → parse → 判定)。
     *
     * @return array{timeout: int|null, violations: list<string>}
     */
    private static function read(string $absolutePath, string $label): array
    {
        if (! is_file($absolutePath)) {
            return self::rejected("{$label}: prompt YAML が無い ({$absolutePath})");
        }

        /** @var list<string> $parseErrors */
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($absolutePath, $parseErrors);
        if ($parsed === null) {
            return ['timeout' => null, 'violations' => $parseErrors];
        }

        return self::evaluate($parsed, $label);
    }

    /**
     * parse 済みの YAML から待ち予算と契約違反を返す (判定の純関数)。
     *
     * @param  array<string, mixed>  $parsed
     * @return array{timeout: int|null, violations: list<string>}
     */
    private static function evaluate(array $parsed, string $label): array
    {
        if (! array_key_exists('client_options', $parsed)) {
            return self::rejected("{$label}: client_options が無い (LLM の待ち予算が未宣言)");
        }

        // 配列 offset 式のままだと narrowing が保たれないためローカル変数へ移す
        $options = $parsed['client_options'];
        if (! is_array($options)) {
            return self::rejected(sprintf(
                '%s: client_options が配列ではない (%s)', $label, get_debug_type($options),
            ));
        }

        if (! array_key_exists('timeout', $options)) {
            return self::rejected("{$label}: client_options.timeout が無い (LLM の待ち予算が未宣言)");
        }

        $timeout = $options['timeout'];
        if (! is_int($timeout)) {
            return self::rejected(sprintf(
                '%s: client_options.timeout が整数ではない (%s)。数値文字列 ("300") も許さない',
                $label, get_debug_type($timeout),
            ));
        }

        if ($timeout <= 0) {
            return self::rejected(sprintf(
                '%s: client_options.timeout が %d (正の整数でなければならない)', $label, $timeout,
            ));
        }

        return ['timeout' => $timeout, 'violations' => []];
    }

    /** @return array{timeout: null, violations: list<string>} */
    private static function rejected(string $message): array
    {
        return ['timeout' => null, 'violations' => [$message]];
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (public 2 本は `array` / `int`、shape は PHPDoc)
- [x] null 安全: `requirePositive()` は `null` を黙って 0 に落とさず例外にする
      (spirux で実害が出た「`?? 0` で fail-open」の再発経路を型ではなく分岐で塞ぐ)
- [x] DTO を返している — 非該当 (テスト支援。array shape を PHPDoc で固定)
- [x] Generics — 非該当

### テスト計画

- [x] 施策 1 の自己テスト 5 本が緑になること
- [x] 新規テストは書かない (本施策は施策 1 のテストを緑にする実装側)
- [x] `declare(strict_types=1)` があること (`StrictTypesDeclarationGateTest` が強制)

### リスク

- `PromptYaml::parseOrFail()` に依存するため、同ヘルパの**採用時債務としての凍結**に縛られる
  (`adoption-debt.tsv` にあるので内容を変えられない)。今回は読むだけなので影響なし。
  将来ヘルパ側を直す必要が出たら、そのとき 3 択 (戻す / テンプレートへ同期 / 逸脱登録) を判断する

---

## 施策 3: gate の書き換えと分母の到達証明

### 変更箇所

- `tests/Architecture/PromptClientTimeoutInvariantTest.php` (全面書き換え。42 行)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイル自身
- **乖離台帳**: 本ファイルは `docs/template-fingerprints.json` のキーに在り、
  かつ現在テンプレートと**一致**している (app 側 sha256 `3e2cd834…` = 台帳の指紋)。
  書き換えると `TemplateDivergenceFingerprintTest` が
  「一致していた状態から新たに不一致になった、未登録かつ非債務のパス」として落とす。
  → **施策 6 (D50 登録 + 件数 pin) を同じ PR で必ず行う**

### 現行コード

```php
use Tests\Support\PromptYaml;

test('全 prompt YAML が client_options.timeout (>0) を宣言する', function (): void {
    $files = PromptYaml::paths();

    expect($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($file, $parseErrors);
        if ($parsed === null) {
            array_push($violations, ...$parseErrors);

            continue;
        }
        if (! array_key_exists('client_options', $parsed) || ! is_array($parsed['client_options'])) {
            $violations[] = "{$file}: client_options (map) がありません";

            continue;
        }
        $timeout = $parsed['client_options']['timeout'] ?? null;
        if (! is_int($timeout) || $timeout <= 0) {
            $violations[] = "{$file}: client_options.timeout は正の int で宣言してください";
        }
    }

    expect($violations)->toBe([], /* ... */);
});
```

### 変更後コード

```php
<?php

declare(strict_types=1);

use Tests\Support\PromptWaitBudget;
use Tests\Support\PromptYaml;

/*
 * LLM provider のハング対策として、全 prompt YAML が client_options.timeout (>0 の int) を
 * 宣言する不変条件を固定する。prism-prompt は YAML metadata の client_options を Prism
 * リクエストへ渡すため、これにより provider 無応答時に明示 timeout で打ち切られる。
 * 宣言を落とすと実効値は config/prism.php の request_timeout (30 秒) へ縮み、
 * 360 秒前提の時間 budget 連鎖 (AnalysisTimeBudgetInvariantTest) が黙って途中で切れる。
 *
 * 【走査対象】`PromptYaml::paths()` = resources/prompts 配下の *.yaml / *.yml を再帰全数
 *   (大文字拡張子も拾う)。0 件は失敗にする。
 * 【判定の正本】`Tests\Support\PromptWaitBudget` **1 箇所**である。
 *   待ち予算を読む検査 (本 gate / AnalysisTimeBudgetInvariantTest /
 *   AnalysisTokenBudgetInvariantTest) はすべて同じ読み取り器を参照する
 *   (同じ規則を 2 実装持つと、片方だけが緩んでも気付けない)。
 *   検出力の裏取り (負例 9 類型 + 正例 + 解決不能形 3 種) は
 *   tests/Unit/Architecture/PromptWaitBudgetTest.php が持つ。
 *
 * 【この gate が保証しないもの (誇張しない)】
 *  1. **宣言値が実効値であること**は見ない (読み取り器の docblock が正本)。
 *  2. **走査の再帰そのものの検出力は裏取りしていない**。`PromptYaml::paths()` は
 *     探索根を引数で受けず `base_path('resources/prompts')` を直接見るため、見本
 *     ディレクトリを食わせられない。テスト中に resources/prompts へ一時ファイルを作る形は
 *     同じ分母を見る他の 3 gate (PromptYamlContractTest / DefensiveInstructionsPresenceTest /
 *     PromptDefenseWindowGateTest) を汚すので採らない。**実データにも
 *     サブディレクトリが無い**ので、再帰が壊れても本 gate は気付かない。
 *  3. 到達証明は**「現在の列挙結果に既知 5 本が含まれること」**だけである。
 *     全数性そのものは `PromptYaml::paths()` の実装契約に依存する。
 *     既知 5 本は**いずれも resources/prompts 直下**にあるので、
 *     `paths()` が非再帰へ退行しても 5 本は取れて**緑のまま**になる
 *     (= 再帰性の退行は検出しない。上の 2 と同じ限界である)。
 *     新規 prompt が分母に入ることも本証明は保証しない (再帰全数走査の既定拒否が受け持つ)。
 */

/** 走査根 (resources/prompts) からの相対パス。違反ラベルに使う。 */
function promptWaitBudgetLabel(string $absolutePath): string
{
    $prefix = rtrim(base_path('resources/prompts'), '/').'/';
    $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $absolutePath);

    return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : $normalized;
}

/**
 * 現在の列挙結果に必ず含まれる既知の prompt (到達証明)。
 *
 * ★件数の pin ではなく**包含**である。新規 prompt の追加でこの一覧を直す必要は無く、
 *   既知の 1 本が消えた・改名された・走査根が別物になったときだけ赤くなる。
 *   意図した削除なら同じ PR でこの一覧を直す。
 * ★**再帰性の退行は検出しない** (5 本とも resources/prompts 直下にあるため)。
 */
const PROMPT_WAIT_BUDGET_REQUIRED_LABELS = [
    'example-summary.yaml',
    'scenario-generation.yaml',
    'sop-extract-media.yaml',
    'sop-extract.yaml',
    'work-decomposition.yaml',
];

test('走査の列挙結果に既知の prompt YAML が含まれる (分母の到達証明)', function (): void {
    $labels = array_map(promptWaitBudgetLabel(...), PromptYaml::paths());

    expect($labels)->not->toBeEmpty();

    $missing = array_values(array_diff(PROMPT_WAIT_BUDGET_REQUIRED_LABELS, $labels));

    expect($missing)->toBe([],
        '走査の列挙結果に既知の prompt YAML が含まれていません'
        .' (走査根の改名・移動、または既知ファイルの削除・改名)。'
        .PHP_EOL.'不足: '.implode(', ', $missing));
});

test('全 prompt YAML が client_options.timeout (>0 の int) を宣言する', function (): void {
    $files = PromptYaml::paths();

    // ★到達証明の test と重複するが**意図的に残す**。各不変条件の test を単独で
    //   フィルタ実行したときにも「分母 0 件で緑」にならないようにするため。
    expect($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        array_push($violations, ...PromptWaitBudget::violations($file, promptWaitBudgetLabel($file)));
    }

    expect($violations)->toBe([],
        'client_options.timeout invariant に違反があります (provider 無応答時に打ち切れない)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

**削除するもの (後方互換を残さない)**: インライン判定 3 分岐 (`client_options` の map 検査 /
`['timeout'] ?? null` / `is_int` && `> 0`) と、そこでの `parseOrFail` の直呼び。
`PromptYaml::parseOrFail()` の呼び出しは読み取り器の内側へ移る。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`promptWaitBudgetLabel(): string`)
- [x] null 安全 (`array_diff` / `array_map` はいずれも null を返さない)
- [x] DTO / Generics — 非該当

### テスト計画

- [x] **先に赤くする**: 到達証明の test を追加した直後に
      `PROMPT_WAIT_BUDGET_REQUIRED_LABELS` へ実在しない名前 (`sop-extract-v9.yaml`) を
      一時的に足し、赤になることを確認する (確認したら戻す)。
      これが AGENTS.md「既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を
      一時的に壊して赤を確認する」の実施である
- [x] 既存 test の**更新** (名前は据え置き。判定の中身を読み取り器へ委譲)
- [x] 新規 test: 「走査の列挙結果に既知の prompt YAML が含まれる (分母の到達証明)」
      — 走査根の改名・移動と既知ファイルの削除・改名を検出する。
      **再帰性の退行は検出しない** (既知 5 本がいずれも走査根の直下にあるため)
- [x] 既存の 5 本の prompt YAML は現状すべて適合しているので緑のまま
- [x] 個別の `DatabaseTransactions` を使っていない (Architecture lane は DB を使わない)

### リスク

- Pest のファイルスコープ関数・const は**テストファイル間で衝突しうる**。
  名前を `promptWaitBudgetLabel` / `PROMPT_WAIT_BUDGET_REQUIRED_LABELS` と長く取り、
  実装時に `grep -rn "promptWaitBudgetLabel\|PROMPT_WAIT_BUDGET_REQUIRED_LABELS" tests/` で
  0 件を確認する (施策 1 の `promptWaitBudgetFixtureDir` も同様)
- 到達証明は「既知 5 本」を literal で持つので、**prompt の意図的な削除・改名で赤くなる**。
  これは仕様である (削除するなら同じ PR でこの一覧を直す)
- 到達証明で**再帰性は守れない**。走査根の直下しか実体が無いためで、
  再帰まで裏取りするには探索根を引数で受ける検出器が要る = 採用時債務の
  `PromptYaml` の変更を伴う。本 PR では広げない (gate と D50 の docblock に限界として明記する)

---

## 施策 4: 下限式側の読み出しを読み取り器へ寄せる

### 変更箇所

- `tests/Support/AnalysisBudget.php` L41-64 (`clientTimeoutSecondsFromYaml()`) と
  `use` 2 行 (`Symfony\Component\Yaml\Yaml` / `Webmozart\Assert\Assert`)

これが正典の**構造依存要件**「依頼ごとの上限をジョブの持ち時間の下限式にも
同じ読み取り器で参照させる」への対応である。aicue の下限式は
`DEADLINE_SECONDS = STAGE_COUNT × CLIENT_TIMEOUT_SECONDS` と
`RunManualAnalysis::$timeout >= D + C + M₁ + S` で、その YAML 側の突合が本メソッドである。

### 波及変更

- 呼び出し側 2 本 (`AnalysisTimeBudgetInvariantTest` L60 / `AnalysisTokenBudgetInvariantTest` L58)
  は**シグネチャが変わらないので無変更**(`array<string, int>` を返す契約は維持)
- TypeScript 型定義 / API Resource / DTO: なし
- **`docs/template-fingerprints.json` のキーに無い** = テンプレートと共有しないファイル。
  乖離台帳の操作は不要 (`adoption-debt.tsv` にも無い)

### 現行コード

```php
use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

    public static function clientTimeoutSecondsFromYaml(): array
    {
        $timeouts = [];
        foreach (self::PROMPT_NAMES as $name) {
            $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
            Assert::isArray($yaml, "{$name}.yaml が map ではありません");
            Assert::keyExists($yaml, 'client_options', "{$name}.yaml に client_options がありません");

            $clientOptions = $yaml['client_options'];
            Assert::isArray($clientOptions, "{$name}.yaml の client_options が map ではありません");
            Assert::keyExists($clientOptions, 'timeout', "{$name}.yaml に client_options.timeout がありません");

            $timeout = $clientOptions['timeout'];
            Assert::integer($timeout, "{$name}.yaml の client_options.timeout が int ではありません");

            $timeouts[$name] = $timeout;
        }

        return $timeouts;
    }
```

**この実装の穴**: `Assert::integer()` までしか見ないので **`timeout: 0` と負数を通す**。
`AnalysisTimeBudgetInvariantTest` は `toBe(360)` で比較するので結果的に落ちるが、
落ちる理由が「値が違う」であって「非正である」ではない。
下限式が別の C を採る形へ変わった瞬間に穴が開く。

### 変更後コード

```php
/**
 * AI 解析の時間 budget 不変条件で使う「仕様値」と、prompt YAML からの実測読み出し。
 *
 * **CLIENT_TIMEOUT_SECONDS は仕様値であり、YAML から導出しない**。
 * これは意図的な重複である: YAML と仕様値を突き合わせることで初めて
 * 「YAML を勝手に変えた」ことを検出できる (YAML から導出すると同時変更で素通りする)。
 * 統一したのは**読み取り規則だけ**であって、値の出所は二重のままである。
 */

    /**
     * prompt YAML から読んだ client_options.timeout (プロンプト名 => 値)。
     *
     * ★読み取り規則の正本は `Tests\Support\PromptWaitBudget` 1 箇所である
     *   (未宣言 / 非配列 / キー無し / 非 int / 非正 をすべて例外にする)。
     *   ここに `Assert::integer()` 相当を書き戻さないこと — 以前の実装は
     *   `timeout: 0` を通していた。
     *
     * @return array<string, int>
     */
    public static function clientTimeoutSecondsFromYaml(): array
    {
        $timeouts = [];
        foreach (self::PROMPT_NAMES as $name) {
            $timeouts[$name] = PromptWaitBudget::requirePositive(
                resource_path("prompts/{$name}.yaml"),
                "{$name}.yaml",
            );
        }

        return $timeouts;
    }
```

`use Symfony\Component\Yaml\Yaml;` と `use Webmozart\Assert\Assert;` は**削除**する
(同ファイルの他の場所で使っていないことを実装時に確認する)。
`use Tests\Support\PromptWaitBudget;` は不要 — 同一 namespace `Tests\Support` にあるため。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`@return array<string, int>` 維持)
- [x] null 安全: `requirePositive()` が int を返すので narrowing 用のローカル変数退避も不要になる
- [x] DTO / Generics — 非該当

### テスト計画

- [x] 既存テストの更新は**不要** (シグネチャ不変)。既存 2 gate が緑のままであることを確認する:
      - `AnalysisTimeBudgetInvariantTest`「解析 3 プロンプトの client timeout が仕様値 (C) と一致する」
      - `AnalysisTokenBudgetInvariantTest`「解析プロンプト YAML の client timeout は時間 budget の仕様値 C と一致する」
- [x] 穴が閉じたことの裏取りは施策 1 の自己テスト (`zero.yaml` → `requirePositive()` が例外)
      が担う。**実 prompt YAML を一時的に壊して確認する形は採らない**
      (同じ分母を見る 4 gate が同時に赤くなり、何を確かめたのか分からなくなる)

### リスク

- `PROMPT_NAMES` の 3 本のいずれかが改名されると、以前は `Yaml::parseFile()` の
  `ParseException` (vendor 由来) で落ちていたのが、今後は
  `RuntimeException`「prompt YAML が無い」で落ちる。**どちらも赤になる**ので
  検出力は落ちない (メッセージが読みやすくなる)

---

## 施策 5: 素の配列参照を読み取り器へ寄せる

### 変更箇所

- `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` L128-137
  (最後の test「`sop-extract-media.yaml` の max_tokens / client timeout も解析 3 段と
  同じ仕様値に揃っている」) の client timeout 側 1 行

### 波及変更

- TypeScript 型定義 / API Resource / DTO: なし
- テストファイル: 本ファイル自身。**指紋台帳のキーに無い**ので乖離台帳の操作は不要
- `AnalysisBudget::PROMPT_NAMES` は**変えない** — `STAGE_COUNT = 3` と対の
  「解析パイプラインの 3 段」であり、OCR 変種 (`sop-extract-media`) を混ぜると
  `DEADLINE_SECONDS = 3C` の意味が崩れる (思考原則 4「別物を似ているからで統合しない」)

### 現行コード

```php
test('sop-extract-media.yaml の max_tokens / client timeout も解析 3 段と同じ仕様値に揃っている', function (): void {
    $yaml = Yaml::parseFile(resource_path('prompts/sop-extract-media.yaml'));
    expect($yaml)->toBeArray();
    expect($yaml['max_tokens'] ?? null)->toBe(OUTPUT_RESERVE_TOKENS);
    expect($yaml['client_options']['timeout'] ?? null)->toBe(AnalysisBudget::CLIENT_TIMEOUT_SECONDS);
});
```

**この実装の穴**: 未宣言 (`null`) と「宣言はあるが値が違う」が同じ失敗に潰れる。
`toBe()` の失敗メッセージからは「書き忘れ」なのか「値の不一致」なのか読めない。

### 変更後コード

```php
test('sop-extract-media.yaml の max_tokens / client timeout も解析 3 段と同じ仕様値に揃っている', function (): void {
    $yaml = Yaml::parseFile(resource_path('prompts/sop-extract-media.yaml'));
    expect($yaml)->toBeArray();
    expect($yaml['max_tokens'] ?? null)->toBe(OUTPUT_RESERVE_TOKENS);

    // 待ち予算の読み取り規則の正本は Tests\Support\PromptWaitBudget 1 箇所である
    // (未宣言・非正・非 int はここへ来る前に例外になる)。素の配列参照を書き戻さないこと。
    expect(PromptWaitBudget::requirePositive(
        resource_path('prompts/sop-extract-media.yaml'), 'sop-extract-media.yaml',
    ))->toBe(
        AnalysisBudget::CLIENT_TIMEOUT_SECONDS,
        'sop-extract-media.yaml の client_options.timeout が時間 budget の C と不一致',
    );
});
```

`use Tests\Support\PromptWaitBudget;` を追記する。`Yaml` の import は
`max_tokens` / provider / model 側でまだ使うので残す。

### PHPStan適合チェック

- [x] 戻り値の型 / null 安全 / DTO / Generics — 非該当 (test 本体)

### テスト計画

- [x] 既存テストの**更新**: 同ファイルの当該 test 1 本 (名前は据え置き)
- [x] 緑のまま (`sop-extract-media.yaml` は `timeout: 360` を宣言済み)
- [x] 施策 4 と併せて「待ち予算を読む実装が 1 本になった」ことを
      `grep -rn "client_options" tests/ | grep -v PromptWaitBudget` の結果が
      **メッセージ文字列とコメントだけ**になることで確認する (実装時の手順に含める)

### リスク

- 同ファイルには `AnalysisBudget::clientTimeoutSecondsFromYaml()` を使う test も在り、
  読み取り器を 2 度通ることになる (YAML を 2 回 parse する)。
  テストの実行時間として無視できる差であり、**キャッシュ層は作らない** (思考原則 2)

---

## 施策 6: 乖離台帳への登録 (D50) と件数 pin の更新

### 変更箇所

- `docs/template-divergence.md`: 宣言行「登録エントリ: 46 件」→「47 件」、末尾に `## D50` を追加
- `tests/Support/TemplateDivergence/LedgerPins.php`:
  `DIVERGENCE_ENTRY_COUNT = 46` → `47`

### 乖離台帳の確認段 (app-design スキル 3-0 の必須段)

`docs/template-fingerprints.json` の `entries` キーに**在るか**で共有ファイルかを判定した:

| 変更ファイル | 指紋台帳 | 採用時債務 | 判断 |
|---|---|---|---|
| `tests/Architecture/PromptClientTimeoutInvariantTest.php` | **在る** (sha `3e2cd834…`。現物と一致 = テンプレート準拠) | 無い | **D50 として登録が必要** |
| `tests/Support/PromptYaml.php` | 在る | **在る** (sha `e473358f…`。現物と一致) | **触らない**ので操作不要 |
| `tests/Architecture/PromptYamlContractTest.php` | 在る | **在る** | **触らない**ので操作不要 |
| `tests/Support/PromptWaitBudget.php` (新規) | 無い | 無い | テンプレートに無い領域への上積み。D50 の対象パスに**含める** (「登録するか迷ったら登録する」) |
| `tests/Unit/Architecture/PromptWaitBudgetTest.php` (新規) | 無い | 無い | 同上。D50 の対象パスに含める |
| `tests/Architecture/fixtures/prompt-wait-budget/*.yaml` (新規) | 無い | 無い | 見本データ。**対象パスには含めない**(登録メタ表の対象パスは「ファイルとして実在」する必要があり 12 本の列挙は台帳を読みにくくする。エントリ本文の節で言及する) |
| `tests/Support/AnalysisBudget.php` | 無い | 無い | 操作不要 |
| `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | 無い | 無い | 操作不要 |
| `docs/architecture.md` | 無い (アプリ固有) | 無い | 操作不要 |
| `tests/Support/TemplateDivergence/LedgerPins.php` | 無い | 無い | 件数 pin の更新のみ |

**採用時債務のパスは 1 本も変更しない**ので、突合 gate の `mutatedDebtPaths` は発火しない
(3 択の判断は不要)。

### 登録するエントリ (D50)

番号は `D50` を使う。台帳の最大番号は D49 (登録件数 46・欠番あり) で、
**番号は再利用しない / 詰めない**という規約に従う。

```markdown
## D50 LLM 待ち予算の宣言検査を、単一読み取り器 + 検出器自己テスト形へ差し替える

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/PromptClientTimeoutInvariantTest.php` / `tests/Support/PromptWaitBudget.php` / `tests/Unit/Architecture/PromptWaitBudgetTest.php` |
| 業務要件起因の説明 | 本アプリの AI 解析は 1 呼び出し 360 秒の待ち予算を前提に deadline (3C) / job timeout / retry_after / 予約 TTL の連鎖を組んでおり、prompt YAML の宣言が落ちると実効値が 30 秒へ縮んで解析が黙って途中で切れる。テンプレートの形は判定を gate ファイル内へインラインで持つため、同じ規則が時間 budget 側にも複製されて実際に緩い実装 (0 以下を通す) が生まれた。判定を 1 クラスへ切り出し、待ち予算を読む検査すべてがそれを参照する形にする |
| 揃え続ける不変条件と保証機構 | 全 prompt YAML が `client_options.timeout` を正の整数で宣言することは `PromptClientTimeoutInvariantTest` が既定拒否で固定する (テンプレートと同じ不変条件)。分母の全数性は既存の走査ヘルパ `PromptYaml::paths()` の実装契約に依存し、新設 test が裏取りするのは**現在の列挙結果に既知 5 本が含まれること**までである。判定の検出力は `tests/Unit/Architecture/PromptWaitBudgetTest.php` が負例 9 類型 + 正例 + 解決不能形 3 種 (分類つき) で裏取りする |
| 再判定の条件 | テンプレート側が判定の 1 クラス化を取り込んだとき (家系の正典 v1 が既にこの形なので、追従で差分が消える可能性がある)。または `resources/prompts` の走査ヘルパ (`PromptYaml`) をテンプレートへ同期する判断をしたとき |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1016-llm-prompt-wait-budget-v1/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-06-30 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 判定の置き場 | gate ファイル内のインライン判定 | `Tests\Support\PromptWaitBudget` 1 クラス (public 2 口・判定は private) |
| 検出力の裏取り | なし | 負例 9 類型 + 正例 + 解決不能形 3 種 (`tests/Unit/Architecture/PromptWaitBudgetTest.php`) |
| 分母の証明 | 非空のみ | 非空 + 既知 5 本の包含 (走査根の改名・移動と既知ファイルの削除で赤くなる。再帰性の退行は検出しない) |
| 時間 budget 側との関係 | 無関係 (テンプレートに解析パイプラインが無い) | 同じ読み取り器を `AnalysisBudget::clientTimeoutSecondsFromYaml()` が参照する |

### なぜ正当な差分か (logic-driven)
...(上表の「業務要件起因の説明」を展開)...

### 揃えている不変条件 (これは保証し続ける)
> 「resources/prompts 配下の prompt YAML は全数が client_options.timeout を
> 正の整数で宣言しており、未宣言・0 以下・整数でない値は検査で落ちる」

### 保証しないもの
- 宣言値が**実効値であること**は見ない (正本は読み取り器の docblock)
- **分母の全数性は走査ヘルパ `PromptYaml::paths()` の実装契約に依存する**。到達証明が
  裏取りするのは「現在の列挙結果に既知 5 本が含まれること」であり、5 本はいずれも
  走査根の直下にあるため**再帰性の退行では赤くならない** (走査ヘルパが探索根を
  引数で受けないので見本ディレクトリを食わせられない)
- parse 段の失敗分類は共有ヘルパに従う (構文エラーと vendor 内部エラーを区別しない)
- 4 本目の読み取り実装の再流入は機械では止めない

### 関連
- 実装: devnotes/20260824-1016-llm-prompt-wait-budget-v1/
- 見本ファイル: tests/Architecture/fixtures/prompt-wait-budget/ (12 本)
- 家系の正典: lctl feature `llm-prompt-wait-budget` canonical v1 (参照実装 spirux)
```

**状態を `監視中` にする理由**: 家系の正典 v1 がこの形であり、テンプレート側も
`update_pending` (同じ 3 点が不足) である。テンプレートが追従すれば逸脱は消えるので
「期限付きで能動的に見直す根拠」がある。見直し期限は **検査実行日 (基準日) から 400 日以内**という規約
(`DivergenceLedgerRules::MAX_REVIEW_WINDOW_DAYS`) に、実装が多少遅れても収まる
2027-06-30 とする (期限は「今日」との相対で判定されるため、上限ぎりぎりの日付を置かない)。

### 波及変更

- テストファイル: `TemplateDivergenceLedgerFormatTest` (宣言行 / 見出しの実数 / `LedgerPins`
  の 3 点一致を機械で見る) と `TemplateDivergenceFingerprintTest` (突合) が対象。
  **どちらも変更しない** — 通すために値を合わせるだけである
- TypeScript 型定義 / API Resource / DTO: なし

### PHPStan適合チェック

- [x] `LedgerPins` は `public const int` の値変更のみ (型は不変)

### テスト計画

- [x] **先に赤くする**: 施策 3 のファイル書き換え後、施策 6 を当てる前に
      `TemplateDivergenceFingerprintTest` が「未登録の新たな不一致」で赤くなることを確認する
      (これが登録の必要性の実測である)
- [x] `TemplateDivergenceLedgerFormatTest` が緑 (登録メタ表 9 行 / 値域 / 対象パスの実在と
      非重複 / 件数の 3 点一致)
- [x] `TemplateDivergenceFingerprintTest` が緑 (登録済みなので不一致は許容される)
- [x] 対象パス 3 本が既存エントリの対象パスと**重複していない**ことを確認する
      (実装時に `grep -n "PromptClientTimeoutInvariantTest\|PromptWaitBudget" docs/template-divergence.md`)

### リスク

- 件数 pin (46 → 47) を忘れると形式検査が赤くなる。**同じコミットで直す**
- D50 の対象パスへ新規 2 本を含めるかは「テンプレートに無い領域への上積み」の解釈判断である。
  登録簿の原則「登録するか迷ったら登録する」に従い含める (誤登録は削除で是正できるが
  登録漏れには気付けない)

---

## 施策 7: 実効性の運用契約の条件付き化と 3 箇条追記

### 変更箇所

`docs/architecture.md` §AI 解析ジョブの運用契約 の
「**LLM 呼び出しの実効タイムアウトは `resources/prompts/*.yaml` の `client_options.timeout`** である」
の箇条を **(a) 条件付きの言い方へ書き換え**、**(b) その直後に 3 箇条を追記する**。

**(a) 既存箇条の 1 行目の書き換え** (断定 → 前提つき。断定のままだと直後の
「前提は機械では見ていない」と読み手に矛盾して見える):

```diff
-- **LLM 呼び出しの実効タイムアウトは `resources/prompts/*.yaml` の `client_options.timeout`** である。
+- **下の 3 前提が成立する限り、LLM 呼び出しの実効タイムアウトは
+  `resources/prompts/*.yaml` の `client_options.timeout`** である。
   この値は `config/prism.php` の `request_timeout` (30s) を **上書きする**
   (prism-prompt の `Prompt::resolveClientOptions()` → Prism の `Anthropic::client()` の
   `withOptions()` が Guzzle option を後勝ちで書き換えるため)。解析の timeout を調整するときは
   `config/prism.php` ではなく prompt YAML を見ること
```

### 変更後コード (追記する 3 箇条)

```markdown
- **宣言の読み取り規則の正本は `Tests\Support\PromptWaitBudget` の 1 クラス**である
  (未宣言 / `client_options` 非配列 / `timeout` キー無し / 整数でない値 / 0 以下を
  すべて違反にする既定拒否)。待ち予算を読む検査は**次の 3 経路で全部**であり、
  いずれもこの 1 本を参照する —
  全数の宣言検査 (`PromptClientTimeoutInvariantTest`) /
  時間 budget の突合 (`AnalysisBudget::clientTimeoutSecondsFromYaml()` 経由で
  `AnalysisTimeBudgetInvariantTest` と `AnalysisTokenBudgetInvariantTest`) /
  OCR 変種の突合 (`AnalysisTokenBudgetInvariantTest` の `sop-extract-media.yaml` 検査)。
  同じ規則を 2 実装持つと片方だけが緩んでも気付けない (実測: 旧実装は `timeout: 0` を通していた)
- **上の「実効である」は 3 つの前提に依存し、機械では見ていない** —
  (i) `app/Prompts/` の factory が vendor の `$clientOptions` クラスプロパティを設定しない、
  (ii) `resources/prompts` を読む非 PHP の実装が無い、
  (iii) vendor の解決順序が「クラスプロパティ > YAML > config」である。
  2026-08-24 の実読で 3 つとも成立していることを確認した (batch/pool 経路も無い)
- **前提が崩れたときの手当て**: 宣言値が実効でない経路が生まれたら、その経路を本節へ
  列挙し、記述が消えたら赤くなる検査を置く (家系の正典が spirux で採っている形)。
  「宣言は在るのに効かない」状態を検査が緑で覆い隠すのが最悪の帰結なので、
  経路を作る PR で必ず同時に行う
```

### 波及変更

- 施策 5 の経路 (`AnalysisTokenBudgetInvariantTest` が `requirePositive()` を直接使う) も
  **列挙に含める** (2 経路と書くと施策 5 が抜ける)。
- テストファイル: `docs/architecture.md` の本節を pin する検査は無い
  (`QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest` /
  `RouteBindingCustomBinderDocSyncTest` はいずれも別の節を見る)。
  実装時に `grep -rn "AI 解析ジョブの運用契約" tests/` で 0 件を再確認する
- 指紋台帳のキーに無い (アプリ固有ファイル) ので乖離台帳の操作は不要

### PHPStan適合チェック

- [x] 非該当 (Markdown)

### テスト計画

- [x] 新規テストは書かない。**文書 pin の検査を新設しない**のは、
      **「宣言値が実効値にならない例外経路」が現時点で 0 件**であり、
      空の例外一覧を固定する検査は「今必要なものだけ作る」に反するため
      (概念設計 §正典要求 (5) の扱い に判断根拠を記載)。
      ★ここで 0 件なのは**例外経路**であって、待ち予算を**読む経路**ではない
      (読む経路は 3 本あり、上の箇条で列挙している)
- [x] `composer test` 全体が緑 (文書変更で赤くなる検査が無いことの確認を兼ねる)

### リスク

- 文書と docblock の 2 か所に実効性の話が載る。**役割を分ける**ことで食い違いを防ぐ —
  docblock は「読み取り器が主張しないこと」の正本、architecture.md は「運用契約と
  前提が崩れたときの導入条件」の正本。同じ文を写さない

---

## テストファースト手順 (実装順・どのテストを先に赤くするか)

| 段 | 作業 | 期待する状態 |
|---|---|---|
| 1 | 見本 12 本 + `tests/Unit/Architecture/PromptWaitBudgetTest.php` を書く (施策 1) | **赤** (`Tests\Support\PromptWaitBudget` が無い = Error) |
| 2 | `tests/Support/PromptWaitBudget.php` を書く (施策 2) | 段 1 の 5 本が**緑** |
| 3 | 負のコントロールの裏取り: `<= 0` 分岐を一時削除 → `is_int()` を `is_numeric()` へ緩める | それぞれ**赤** (ラベル集合が欠ける)。確認後に戻し、`red-green-log.md` へ記録 |
| 4 | gate の到達証明 test を追加 (施策 3 の前半) | **緑** (実データが揃っているため)。続けて `PROMPT_WAIT_BUDGET_REQUIRED_LABELS` へ架空の名前を足して**赤**を確認し戻す |
| 5 | gate 本体を読み取り器へ委譲 (施策 3 の後半。インライン判定を削除) | **緑** |
| 6 | `TemplateDivergenceFingerprintTest` だけを走らせる | **赤** (未登録の新たな不一致)。これが施策 6 の必要性の実測 |
| 7 | D50 登録 + `LedgerPins` 46 → 47 (施策 6) | `TemplateDivergence*Test` が**緑** |
| 8 | `AnalysisBudget::clientTimeoutSecondsFromYaml()` を委譲 (施策 4) | 既存 2 gate が**緑** |
| 9 | `AnalysisTokenBudgetInvariantTest` の素の配列参照を委譲 (施策 5) | **緑** |
| 10 | `docs/architecture.md` の既存箇条を条件付き化 + 3 箇条追記 (施策 7) | **緑** |
| 11 | `grep -rn "client_options" tests/ \| grep -v PromptWaitBudget` | 残るのは**メッセージ文字列とコメントだけ** (読み取り実装は 0 件) |
| 12 | `composer test` / `composer phpstan` / `vendor/bin/pint --test` | 全**緑** |
| 13 | `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 全**緑** (JS は無変更だが AGENTS.md の検証コマンドは全数走らせる) |

- 段 3 と段 4 の「一時的に壊して赤を見る」は AGENTS.md
  「既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して
  赤を確認する」の実施であり、**実測を devnotes に残す**ことまでが完了条件である
- **実 prompt YAML を壊して確認する形は採らない** (同じ分母を見る 4 gate が同時に赤くなり、
  何を確かめたのか分からなくなる)

## migration / 後方互換の扱い

- **DB migration は無い** (スキーマ・データに触れない)
- **後方互換の並走を残さない** (AGENTS.md 思考原則 3)。同じ PR で次の 3 実装を削除する:
  1. `PromptClientTimeoutInvariantTest` のインライン判定 3 分岐
  2. `AnalysisBudget::clientTimeoutSecondsFromYaml()` の `Webmozart\Assert` 版読み出し
     (`use` 2 行も削除)
  3. `AnalysisTokenBudgetInvariantTest` の素の配列参照 1 行
- 旧実装を残す移行期間・deprecated ラッパ・feature flag は**作らない**
- 削除する既存**テスト**は無い (test の本数は 1 本増える。禁止事項「既存テストの削除・上書き」に触れない)
- `resources/prompts/*.yaml` の値は不変 (360 / 60)。**運用への影響は無い**

## docs/template-divergence.md の登録/更新/削除の要否

| 判定 | 内容 |
|---|---|
| **登録が必要** | あり (D50 新設)。理由: `tests/Architecture/PromptClientTimeoutInvariantTest.php` が指紋台帳のキーに在り、現在テンプレートと一致しているため、書き換えると突合 gate が「未登録の新たな不一致」で落ちる |
| **更新が必要** | `LedgerPins::DIVERGENCE_ENTRY_COUNT` 46 → 47、宣言行「登録エントリ: 46 件」→「47 件」 |
| **削除が必要** | なし (解消する既存エントリは無い) |
| **採用時債務の 3 択** | 発生しない (`adoption-debt.tsv` のパスを 1 本も変更しない) |
| **指紋台帳の書き換え** | しない (突合 gate が赤いときに指紋台帳や債務一覧を書き換えて黙らせない、という規約に従う。書くのは登録である) |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1〜7 は 1 つの意味単位で動く。特に施策 3 (gate の書き換え) と施策 6 (D50 登録 + 件数 pin) は**同じコミットでなければ CI が赤くなる**。施策 2 が無いと施策 1・3・4・5 が成立せず、途中の状態でコミットできる切れ目が無い。他タスクと並行して部分マージする余地が無いので standalone とする |
| 競合リスク | Open な TODO は T249 (起動 probe の共通 runner への一元化) のみで対象ファイルが重ならない。ただし **`LedgerPins::DIVERGENCE_ENTRY_COUNT` と `docs/template-divergence.md` の末尾は、乖離登録を伴う任意の PR と競合する** (件数の 3 点一致があるため機械的に検出される)。同時期に別の逸脱登録が入った場合は番号 (D50 → 次の未使用番号) と件数を rebase 時に合わせる |
| 想定規模 | 新規 2 ファイル (`PromptWaitBudget.php` / `PromptWaitBudgetTest.php`) + 見本 12 本 / 既存 **6** ファイルの部分変更 (`PromptClientTimeoutInvariantTest.php` / `AnalysisBudget.php` / `AnalysisTokenBudgetInvariantTest.php` / `docs/template-divergence.md` / `LedgerPins.php` / `docs/architecture.md`)。medium |

## 使命・禁止事項の最終確認

- **使命への寄与**: SOP → シナリオ生成の 3 段と OCR 経路は 360 秒の待ち予算を前提に
  時間 budget 連鎖を組む。宣言が落ちると「思考ゼロ・編集ゼロ」の中核である AI 解析が
  provider 沈黙時に黙って途中で切れ、ワーカーが塞がって後続の解析まで詰まる。
  読み取り規則を 1 本にすると、どの検査から見ても同じ厳しさで落ちる
- **禁止事項 1 (テストなしの実装完了)**: 検出力を負例で裏取りした自己テストまでを完了条件にした
- **禁止事項 2 (PHPStan の widen / baseline)**: 該当なし (`tests` は解析対象外。
  型を緩める変更も無い)
- **禁止事項 3 (dev DB への破壊操作)**: 該当なし
- **禁止事項 4〜8**: アプリ実行コード・UI・route に触れないため該当なし
- **禁止事項 9 (Artifact の使用)**: Artifact は使用せず、成果物はすべてリポジトリ内の
  ファイルとして出力する (設計は `devnotes/`、実装は `tests/` と `docs/`)
- **スキル禁止事項 3 (既存テストの削除・上書き)**: 削除するテストは無い。
  書き換えるのは判定の実装であり、test 名と不変条件は据え置く
- **スキル禁止事項 5 (個別 `DatabaseTransactions`)**: 使わない
- **スキル禁止事項 6 (やたらに複雑な案)**: 正典が要求する 3 点 + 構造依存要件 1 点に限定し、
  正典要求 (5) は「該当構造なし」として文書節と検査の新設を退けた
