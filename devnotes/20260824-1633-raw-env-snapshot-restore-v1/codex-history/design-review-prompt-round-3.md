# Round 3: Round 2 の指摘への対応

Round 2 の [Critical] 2 件・[Warning] 3 件をすべて反映した (反論なし)。
対応マトリクスと、修正後の詳細設計の全文を送る。
残る争点があれば指摘し、無ければ全体判定を返してほしい。

# 対応マトリクス: design-review Round 2

## 施策 1

### [Critical] g-2 / g-3 が immutable writer の `$loaded` 状態を決定的に作れていない
- 判断: 対応する
- 根拠: 指摘のとおり。`$loaded` に `APP_LOCALE` が載っているかは**直前のテストが
  repository を捨てたか**に依存する。捨てた直後の repository は `$loaded` が空なので、
  注入済みの `zz` は「外部で定義済み」と判定され、口を呼ばなくても上書きされない
  = g-2 が実行順で結果を変える。
- 対応内容: 各ケースの中に **priming の 5 段**を明記した。
  (1) `APP_LOCALE` の 3 面を一旦未設定にする → (2) `forgetLaravelEnvRepository()` →
  (3) `LoadEnvironmentVariables` を実行 → (4) `.env.testing` の値が読み込まれたことを確認
  (この時点でその writer の `$loaded` に `APP_LOCALE` が載る) → (5) `with(...'zz'...)` を実行。
  そのうえで g-2 は口なしの再読み込みで `.env.testing` の値へ戻ること、
  g-3 は口ありで `zz` が残ることを見る。ケースの外側では**自前で退避した元の 3 面へ戻す**。

### [Critical] i-3 (復元の失敗の集約) は提示の実装では動的に検査できない
- 判断: 対応する (提示された 2 案のうち **構造検査へ変更する**案を採る)
- 根拠: 指摘のとおり。検証を通ったキーで `putenv()` を失敗させる状況は作れず、
  差し替えの seam を新設すると「本番では誰も使わない差し替え口」が増える
  (正典 q2 が motivation で同じ理由から避けた形)。
- 対応内容: i-3 を **h-3 (構造の固定)** へ移した。
  `restore()` が (a) `$this->state` を直接回す `foreach` をちょうど 1 件持ち、
  (b) `throw` がその `foreach` の本体範囲の**外**にちょうど 1 件あること
  (= 失敗を蓄積してループ後にだけ送出する形) を `RawEnvGuardStructure` で固定する。
  保証表の該当行も「**構造テストのみ。動的には未検証**」へ改めた。

### [Warning] `RawEnvGuardStructure` が適用ループを十分に同定していない / 再送出を見ていない
- 判断: 対応する
- 根拠: 空のループを `try` に残して適用を別の場所へ移しても通る、という指摘は正しい。
  また h-2 は「復元と再送出」を主張しているのに `throw` を見ていなかった。
- 対応内容: 走査 API に `staticCalls()` (`self::apply(` の位置) と `throwTokens()` を足し、
  判定を**メソッドごとの期待件数つき**で書き直した。
  - `with()`: `try` = 1 / `catch` = 1 / `finally` = 1。
    `$changes` を直接回す `foreach` = 1 (try 本体の内、catch・finally 本体の外)、
    その `foreach` の本体に `self::apply(` がちょうど 1 件、
    `finally` 本体に `$snapshot->restore(` がちょうど 1 件。
  - `captureAndClear()`: `try` = 1 / `catch` = 1 / `finally` = 0。
    `$keys` を直接回す `foreach` = 1 (try 本体の内)、その本体に `self::apply(` が 1 件、
    `catch` 本体に `$snapshot->restore(` が 1 件と `throw` が 1 件。
  - `restore()`: `$this->state` を直接回す `foreach` = 1、`throw` = 1 で
    その `foreach` の本体範囲の**外**にあること。
  自己検査の負例も「空のループを try に残して適用を外へ出した形」と
  「catch から `throw` を落とした形」を足した。

## 施策 2

### [Warning] 分割代入の区間内の面をすべて対象にすると誤検出する
- 判断: 対応する
- 根拠: `[$other[$_SERVER['K']]] = $v;` の `$_SERVER` は**添字を求めるための読み出し**であり、
  書き換え対象ではない。
- 対応内容: 判定を **lvalue の根**で行う形へ書き直した。区間の中の角括弧を
  「**添字の括弧**」(直前の有意トークンが変数 / `]` / `)` )と
  「**パターンの括弧**」(それ以外) に分け、面が
  (a) 自分と区間の根の間に**添字の括弧が 1 つも無い**こと、かつ
  (b) 要素の先頭位置にある (直前の有意トークンが `[` / `(` / `,`) こと、
  の両方を満たすときだけ `destructuring_target` にする。
  これで `[[$_ENV['K']]] = $v;` (入れ子のパターン) は検出し、
  `[$other[$_SERVER['K']]] = $v;` / `list($other[$_SERVER['K']])` は検出しない。
  正例 2 形・負例 2 形を自己検査へ追加した。

## 施策 3 / 施策 4
- 判定: APPROVE。変更なし。

## 施策 5

### [Warning] D50 の見出しと業務要件起因の説明が絶対表現のまま
- 判断: 対応する
- 対応内容: 見出しを
  「テストが触る生の環境変数 3 面を 1 つの部品へ集約し、**部品の外に現れる列挙済みの字句上の
  直接書き込み**を検査で止める」へ改め、業務要件起因の説明の末尾も同じ限定表現へ揃えた。

---

## 修正後の詳細設計 (全文)

# 詳細設計: raw-env-snapshot-restore v1 追従

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本設計はテスト基盤の変更であり、4 / 5 / 6 / 7 / 8 は発火しない。
> **1 (テストなしの完了報告)・2 (PHPStan の widen)・3 (dev DB への破壊操作)** が直接関係する。
> とくに **3 は本設計の動機そのもの**である — 現行の
> `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` はテスト実行中の親プロセスへ
> `DB_DATABASE=app` を立てており、その隣接ハザードを消すのが施策 4 である。
> **禁止事項 2 に触れないための方針**: 不正入力を拒否する検査を書くために
> 虚偽の `@var` や `@phpstan-ignore` は使わない。公開 API の宣言を
> 「静的に表現できる範囲」に留め、非空・書式・拒否キーは**実行時の契約**として検査する
> （`Webmozart\Assert` の本来の用途）。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（本設計は新モデルを追加しないので Factory の追加は無い）
- **DTO + JsonResource** パターン（本設計はテスト基盤なので HTTP 応答を作らない）
- **アーリーリターン** 推奨
- `declare(strict_types=1)` + 日本語コメント（git 追跡下の PHP 全数が対象。免除の登録簿は無い）
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

- [devnotes/20260824-1633-raw-env-snapshot-restore-v1/conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー Round 4 で APPROVED）
- 正典: 家系機能台帳 (lctl) feature `raw-env-snapshot-restore` の `design.md`（v1 / doc_sha `c4fa274ac84f` / 不変条件 i1–i12）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 3 面の退避・注入・復元を担う部品の新設と、その契約テスト (i1–i11) | `tests/Support/RawEnv/RawEnvChannels.php` (新) / `RawEnvSnapshot.php` (新) / `RawEnvGuardStructure.php` (新) / `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php` (新) / `tests/Unit/Architecture/RawEnvGuardStructureTest.php` (新) | 高 |
| 2 | 部品の外の直接の書き込みを止める走査器と gate の新設 (i12) | `tests/Support/RawEnv/RawEnvWriteKind.php` (新) / `RawEnvWriteSite.php` (新) / `RawEnvDirectWriteAllowance.php` (新) / `RawEnvDirectWriteScanner.php` (新) / `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php` (新) / `tests/Architecture/RawEnvDirectWriteGateTest.php` (新) | 高 |
| 3 | 既存 3 実装の部品への移送と同時削除 (i1 の完成) | `tests/Feature/Support/ProductionEnvGuardTest.php` / `tests/Feature/Config/ConfigHardeningTest.php` / `tests/Feature/Auth/PasskeyOriginDeclarationTest.php` | 高 |
| 4 | 拒否対象キーを親プロセスへ立てる検査の書き換え (i9 と i12 の両立) | `scripts/ci/pgsql_test_conn.php` / `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` | 高 |
| 5 | 乖離台帳・件数 pin・契約文書のゲート索引の整合 | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` / `docs/app-integration-guide.md` / `tests/Architecture/IntegrationGuideGateTableSyncTest.php` | 高 |

**5 つは 1 コミットで完結させる**（施策 3 を入れずに施策 2 を入れると gate が赤いまま、
施策 2 を入れずに施策 3 を入れると i1 が「入れた直後だけ成り立つ」性質のまま、
施策 5 を入れないと `TemplateDivergenceLedgerFormatTest` が赤いままになる）。

---

## 施策 1: 部品の新設と契約テスト

### 変更箇所

- 新規: `tests/Support/RawEnv/RawEnvChannels.php`
- 新規: `tests/Support/RawEnv/RawEnvSnapshot.php`
- 新規: `tests/Support/RawEnv/RawEnvGuardStructure.php`（構造の固定に使う純関数の走査器）
- 新規: `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php`
- 新規: `tests/Unit/Architecture/RawEnvGuardStructureTest.php`（構造走査器の自己検査）

### 波及変更

- TypeScript 型定義: なし（フロントに影響しない）
- API Resource/DTO: なし
- テストファイル: 施策 3 / 施策 4 で呼び出し側を全数書き換える（後述）

### 現行コード（集約前の 3 実装。すべて同じ変更で消す）

```php
// tests/Feature/Support/ProductionEnvGuardTest.php (L370-520 付近、関数 7 本)
function productionEnvGuardCaptureRaw(string $variable): array { /* 3 面の退避 */ }
function productionEnvGuardRestoreRaw(string $variable, array $state): void { /* 復元 */ }
function productionEnvGuardClearRaw(string $variable): void { /* 3 面消去 */ }
function productionEnvGuardRawSnapshot(?array $set = null): array { /* 静的な入れ物 */ }
function productionEnvGuardIsolateRawEnvironment(): void { /* 退避 + 消去 */ }
function productionEnvGuardRestoreRawEnvironment(): void { /* 復元 */ }
function withRawEnvironmentValue(string $variable, array $values, Closure $callback): void { /* 閉包 */ }

// tests/Feature/Config/ConfigHardeningTest.php (L20-59)
function evaluateConfigFileWithEnv(string $configFile, array $env): array
{
    $previous = [];
    foreach ($env as $key => $value) {
        $getenv = getenv($key);
        // ★ i3 違反: `?? null` は「存在するが値が null」を「存在しない」へ潰す
        $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, $getenv === false ? null : $getenv];
        // ★ i6 違反: 退避と適用が同一ループ (途中で失敗するとそこまでの変更が残る)
        if ($value === null) { unset($_SERVER[$key], $_ENV[$key]); putenv($key); }
        else { $_SERVER[$key] = $value; $_ENV[$key] = $value; putenv("{$key}={$value}"); }
    }
    try { /* config を評価 */ } finally { /* 復元 */ }
}

// tests/Feature/Auth/PasskeyOriginDeclarationTest.php (L31-72)
function evaluateFortifyConfigWithEnv(array $overrides): array
{
    foreach ($overrides as $key => $value) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key),
                        array_key_exists($key, $_SERVER), array_key_exists($key, $_ENV)];
        // ★ i6 違反: 退避と適用が同一ループ / ★ i7 未達: 面ごとの値を作れない
        $_SERVER[$key] = $value; $_ENV[$key] = $value; putenv("{$key}={$value}");
    }
    try { /* config を評価 */ } finally { /* 復元 */ }
}
```

### 変更後コード

#### `tests/Support/RawEnv/RawEnvChannels.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Support\RawEnv;

/**
 * 3 面 (`$_SERVER` / `$_ENV` / `putenv`) へ何を入れるかの指定 (不変の値オブジェクト)。
 *
 * ★**「指定しなかった」と「値が null」は別物である**。前者は「その面を明示的に未設定にする」
 *   という意味であり (正典 v1 の i7)、後者は「その面に null を入れる」である。
 *   したがって面ごとに「指定したか」を値と別に持つ。`?null` では表現できない。
 * ★**値の型を絞らない** (i3)。`$_SERVER` / `$_ENV` は mixed を持ちうるし、
 *   本リポジトリには非文字列 (配列) を入れて fail-closed を確かめる既存ケースがある。
 * ★**`putenv` 面だけは `string` に限る**。`putenv()` は文字列しか受け取れないので、
 *   非文字列がこの面へ到達する経路を型で消す (`sameOnAllSurfaces()` が `string` しか
 *   受け取らないのはこのためである。非文字列は `withServer()` / `withEnv()` からしか指定できない)。
 */
final class RawEnvChannels
{
    private function __construct(
        public readonly bool $serverSpecified,
        public readonly mixed $serverValue,
        public readonly bool $envSpecified,
        public readonly mixed $envValue,
        public readonly bool $processSpecified,
        public readonly string $processValue,
    ) {}

    /** 3 面とも未指定 (= 適用すると 3 面とも明示的に未設定になる)。 */
    public static function none(): self
    {
        return new self(false, null, false, null, false, '');
    }

    /** 3 面そろえて同じ文字列を入れる (最も普通の使い方)。 */
    public static function sameOnAllSurfaces(string $value): self
    {
        return new self(true, $value, true, $value, true, $value);
    }

    public function withServer(mixed $value): self
    {
        return new self(true, $value, $this->envSpecified, $this->envValue, $this->processSpecified, $this->processValue);
    }

    public function withEnv(mixed $value): self
    {
        return new self($this->serverSpecified, $this->serverValue, true, $value, $this->processSpecified, $this->processValue);
    }

    public function withProcess(string $value): self
    {
        return new self($this->serverSpecified, $this->serverValue, $this->envSpecified, $this->envValue, true, $value);
    }
}
```

#### `tests/Support/RawEnv/RawEnvSnapshot.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Support\RawEnv;

use Closure;
use Illuminate\Support\Env;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 生の環境変数 3 面 (`getenv()` / `$_ENV` / `$_SERVER`) の退避・注入・復元。
 *
 * ★**本リポジトリで 3 面を触ってよいのはこのクラスだけ**である
 *   (例外は自身の契約テストと `tests/bootstrap.php` の 2 つ。
 *    `RawEnvDirectWriteGateTest` が deny-by-default で強制する。
 *    ただし gate が見るのは**列挙した字句の書き込み形だけ**である。保証範囲は
 *    `RawEnvDirectWriteScanner` の docblock が正本)。
 * ★`RefreshDatabase` は**プロセスの環境変数を守らない**ので、テストが env を触ったら
 *   自分で元へ戻さないとテストプロセス全体へ漏れる。
 * ★3 面すべてを見るのは、Laravel の `env()` が **`$_SERVER` → `$_ENV` → `putenv`** の順に
 *   live で読むためである (実測: `Illuminate\Support\Env::getRepository()` が
 *   `RepositoryBuilder::createWithDefaultAdapters()` = `ServerConstAdapter` → `EnvConstAdapter`
 *   を作り、`$putenv` が真のとき末尾に `PutenvAdapter` を足す)。
 *   **この順序は注釈ではなく契約テストが実行時に固定する**。
 *
 * ── 2 通りの結び方 (正典 v1 の i5。択一ではない) ────────────────────────────
 *
 *  (a) 閉包を囲む形: `with()`。検証 → 退避 → `try { 適用 + 本体 } finally { 復元 }`
 *  (b) 退避を持ち回る形: `captureAndClear()` + `restore()`。
 *      検証 → 退避 → `try { 未設定化 } catch { 復元して再送出 }` → 呼び出し側が
 *      枠組みの後処理フック (`afterEach`) から `restore()` を呼ぶ
 *
 *  (b) は適用が終わった時点で呼び出し側へ戻るので `finally` を本体の終わりまで
 *  開いたままにできない。**適用の途中で失敗したときの巻き戻しはその場で行う**
 *  (失敗すると snapshot が呼び出し側へ返らない = 後処理フックも戻せないため)。
 *
 * ── 例外の契約 ─────────────────────────────────────────────────────
 *
 *  - キーの不正・拒否は第 1 段で `InvalidArgumentException`。**1 面も触っていない**。
 *  - `putenv()` の失敗は `RuntimeException`。
 *  - **復元は最初の失敗で止めない** — 全キーの 3 面を最後まで戻し、失敗したキーを集めて
 *    最後に 1 つの `RuntimeException` にする。
 *  - **本体の例外と復元の失敗が重なった場合**、表に出るのは復元の失敗で、
 *    本体の例外は `previous` に連結する (情報を落とさない)。
 *
 * ── 保証しないもの (誇張しない) ─────────────────────────────────────────
 *
 *  - **適用の途中で `putenv()` が失敗したときの巻き戻りと、復元が最初の失敗で止まらないことは
 *    動的には検査していない** (検証を通ったキーで `putenv()` を失敗させる状況をテストから作れず、
 *    失敗を注入する差し替え口は新設しない)。構造の固定
 *    (`RawEnvGuardStructure` を使う契約テスト) で代えている。
 *  - `$changes` / `$keys` に**現れないキーには一切触れない**。
 *  - 閉包の口は PHP の連想配列の性質で**数値だけのキーが整数へ畳まれる**ため拒否される。
 *    持ち回りの口は `list` なので畳み込みが起きず数値だけのキーも扱えるが、本リポジトリに需要は無い。
 *  - **本部品はテスト専用である**。`putenv()` はスレッド安全でないため本番の経路では使わない。
 */
final class RawEnvSnapshot
{
    /**
     * 差し替えを拒否するキーの接頭辞 (単一点の守りから導いた宣言。**許可一覧は持たない**)。
     *
     * `DB_` — `tests/bootstrap.php` は「pgsql lane の最終 `DB_DATABASE` が test DB か」を
     * Laravel boot 前に 1 回だけ fail-closed 検証する単一点ガードである
     * (`Tests\Support\Ci\TestDatabaseEnv::assertPgsqlTestDatabaseSafe()`)。
     * テスト実行中に DB 系 env を差し替えると、その検査の後ろを通ることになり
     * dev DB へ向く経路を作りうる (AGENTS.md 禁止事項 3)。
     *
     * @var list<non-empty-string>
     */
    public const array DENIED_KEY_PREFIXES = ['DB_'];

    /**
     * 差し替えを拒否するキー (完全一致)。
     *
     * `TEST_TOKEN` — paratest の作業単位の同定。Laravel の並列 DB 名
     * (`<base>_test_<TEST_TOKEN>`) がこれに乗っている。
     * `APP_CONFIG_CACHE` — `scripts/ci/ensure-test-db.php` が子プロセスへ渡す
     * 専用の設定キャッシュパス。親で立てると「通常経路では誰も生成しないはずの専用パス」の
     * 検査が意味を失う。
     *
     * @var list<non-empty-string>
     */
    public const array DENIED_KEYS = ['TEST_TOKEN', 'APP_CONFIG_CACHE'];

    /**
     * @param  list<array{key: string, serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>  $state
     */
    private function __construct(private readonly array $state) {}

    /**
     * 3 面を差し替えて閉包を実行し、**成否によらず**元の存在状態と値へ戻す。
     *
     * ★キーは `string` で受け、非空・書式・拒否は**実行時の契約**として第 1 段で検査する
     *   (`non-empty-string` を宣言すると、不正入力を拒否する検査そのものが書けなくなる)。
     *
     * @template TReturn
     *
     * @param  array<string, RawEnvChannels>  $changes
     * @param  Closure(): TReturn  $body
     * @return TReturn
     *
     * @throws InvalidArgumentException キーが不正 / 拒否対象 / process 値に NUL (第 1 段。1 面も触っていない)
     * @throws RuntimeException `putenv()` が失敗した場合 (復元は行われる)
     */
    public static function with(array $changes, Closure $body): mixed
    {
        // --- 第 1 段: 検証 (この時点では何も触らない) ---
        self::assertChangesAllowed($changes);

        /** @var list<string> $keys */
        $keys = array_keys($changes);

        // --- 第 2 段: 退避 (この時点でも何も変えない) ---
        $snapshot = self::capture($keys);

        // --- 第 3 段: 適用 + 本体 (適用途中の失敗も finally で巻き戻る) ---
        $bodyError = null;

        try {
            foreach ($changes as $key => $channels) {
                self::apply((string) $key, $channels);
            }

            return $body();
        } catch (Throwable $e) {
            $bodyError = $e;

            throw $e;
        } finally {
            $snapshot->restore($bodyError);
        }
    }

    /**
     * 指定キーの 3 面を退避し、そのうえで 3 面とも未設定にする。
     * 復元は呼び出し側が枠組みの後処理フックから `restore()` を呼んで行う。
     *
     * @param  list<string>  $keys
     *
     * @throws InvalidArgumentException キーが不正 / 拒否対象の場合 (1 面も触っていない)
     * @throws RuntimeException `putenv()` が失敗した場合 (**その場で巻き戻してから**送出する)
     */
    public static function captureAndClear(array $keys): self
    {
        self::assertKeysAllowed($keys);
        $snapshot = self::capture($keys);

        try {
            foreach ($keys as $key) {
                self::apply($key, RawEnvChannels::none());
            }
        } catch (Throwable $e) {
            $snapshot->restore($e);

            throw $e;
        }

        return $snapshot;
    }

    /**
     * 退避した 3 面を、元の存在状態と値へ戻す (面ごとに独立して戻す)。
     *
     * ★**最初の失敗で止めない**。全キーを最後まで戻してから、失敗したキーをまとめて例外にする。
     *
     * @param  Throwable|null  $previous  本体側で起きていた例外 (復元も失敗したときに連結する)
     *
     * @throws RuntimeException 1 つ以上のキーで `putenv()` が失敗した場合
     */
    public function restore(?Throwable $previous = null): void
    {
        /** @var list<string> $failed */
        $failed = [];

        foreach ($this->state as $saved) {
            $key = $saved['key'];

            if ($saved['serverExists']) {
                $_SERVER[$key] = $saved['server'];
            } else {
                unset($_SERVER[$key]);
            }

            if ($saved['envExists']) {
                $_ENV[$key] = $saved['env'];
            } else {
                unset($_ENV[$key]);
            }

            // `putenv('K=a=b')` は値 `a=b` を設定する (等号を含む値を壊さない。i4)
            $applied = is_string($saved['process'])
                ? putenv($key.'='.$saved['process'])
                : putenv($key);

            if ($applied === false) {
                $failed[] = $key;
            }
        }

        if ($failed !== []) {
            throw new RuntimeException(
                'putenv() failed while restoring env keys: '.implode(', ', $failed),
                0,
                $previous,
            );
        }
    }

    /**
     * **枠組みを作り直す直前に呼ぶ。** 3 面へ入れた値が `.env.testing` の値で
     * 上書きされるのを防ぐ (正典 v1 の i10)。
     *
     * phpdotenv の immutable writer は「既に定義済みの変数は上書きしない」を
     * **自分が書いたかどうか**で判定する (実装を実読:
     * `Dotenv\Repository\Adapter\ImmutableWriter::isExternallyDefined()` は
     * 「読めて、かつ `$loaded` に自分の記録が無い」ときだけ真を返す)。
     * その writer は `Illuminate\Support\Env::$repository` に**プロセス静的**で保持されるので、
     * 1 度目の boot で `.env.testing` が書いたキーは `$loaded` に載ったままになり、
     * **env を読み直すたびに `.env.testing` の値で上書きされる**。
     * repository を捨てると `$loaded` が空の writer が作り直され、
     * 3 面に在る値が「外部で定義済み」として尊重される。
     *
     * ★**依拠している副作用 (監視条件)**: `Env::enablePutenv()` は本来
     *   putenv アダプタを有効化する API だが、その実装が `static::$repository = null` を
     *   伴うことに依拠している (実測: laravel/framework の `Illuminate\Support\Env`)。
     *   本リポジトリは `disablePutenv()` を呼ばないので、副作用は repository の作り直しだけである。
     *   **上流の版を上げてこの副作用が消えたら、i10 の手段を再評価すること**
     *   (家系の正典 v1 の未決論点 q3)。副作用が生きていること自体は契約テスト (g-1〜g-3) が
     *   実行時に固定する — docblock の監視条件だけでは「緑のまま保証だけ失われる」を検出できない。
     */
    public static function forgetLaravelEnvRepository(): void
    {
        Env::enablePutenv();
    }

    /**
     * 閉包の口の第 1 段 (キーと process 値の検査。**1 面も触らない**)。
     *
     * @param  array<array-key, RawEnvChannels>  $changes
     */
    private static function assertChangesAllowed(array $changes): void
    {
        self::assertKeysAllowed(array_keys($changes));

        foreach ($changes as $key => $channels) {
            if (! $channels->processSpecified) {
                continue;
            }

            // putenv() は NUL を含む文字列で ValueError を投げる。適用の段まで持ち越さない。
            Assert::notContains(
                $channels->processValue,
                "\0",
                "env value for key [{$key}] must not contain a NUL byte (putenv() would throw).",
            );
        }
    }

    /**
     * 受け取ったキーをすべて検査する (第 1 段。**1 面も触らない**)。
     *
     * @param  list<array-key>  $keys
     */
    private static function assertKeysAllowed(array $keys): void
    {
        foreach ($keys as $key) {
            // PHP の連想配列は "0" のような数値だけのキーを整数へ畳む。
            // 畳まれて届いたら復元時に別のキーを触ることになるので拒否する (fail-closed)。
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'env key must be a string (PHP folds numeric-string array keys into integers): '.var_export($key, true)
                );
            }

            Assert::stringNotEmpty($key, 'env key must not be empty.');
            Assert::notContains($key, '=', "env key must not contain '=' (putenv syntax): {$key}");
            Assert::notContains($key, "\0", "env key must not contain a NUL byte: {$key}");

            foreach (self::DENIED_KEY_PREFIXES as $prefix) {
                Assert::false(
                    str_starts_with($key, $prefix),
                    "env key [{$key}] is denied: テスト DB の単一点ガード (tests/bootstrap.php) の前提を崩す。"
                    .'DB 接続を発生させない隔離された評価手段を設計フローで新設すること。',
                );
            }

            Assert::notInArray(
                $key,
                self::DENIED_KEYS,
                "env key [{$key}] is denied: 並列実行の作業単位の同定 / 専用の設定キャッシュパスの前提を崩す。",
            );
        }
    }

    /**
     * 3 面の現在の存在状態と値を退避する (第 2 段。**何も変えない**)。
     *
     * ★退避は**連想配列ではなくリスト**で持つ。キーで索く必要が無いうえ、
     *   連想配列にすると数値だけのキーが整数へ畳まれて復元先がずれる。
     *
     * @param  list<string>  $keys
     */
    private static function capture(array $keys): self
    {
        $state = [];
        foreach ($keys as $key) {
            $state[] = [
                'key' => $key,
                // 存在は値と別に持つ (`?? null` は「存在するが null」を潰す)
                'serverExists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'envExists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                // getenv() の false (未設定) と '' (空文字) を区別する
                'process' => getenv($key),
            ];
        }

        return new self($state);
    }

    /** 指定した面に値を置き、**指定しなかった面は明示的に未設定にする** (i7)。 */
    private static function apply(string $key, RawEnvChannels $channels): void
    {
        if ($channels->serverSpecified) {
            $_SERVER[$key] = $channels->serverValue;
        } else {
            unset($_SERVER[$key]);
        }

        if ($channels->envSpecified) {
            $_ENV[$key] = $channels->envValue;
        } else {
            unset($_ENV[$key]);
        }

        $applied = $channels->processSpecified
            ? putenv($key.'='.$channels->processValue)
            : putenv($key);

        if ($applied === false) {
            throw new RuntimeException("putenv() failed for env key [{$key}].");
        }
    }
}
```

#### `tests/Support/RawEnv/RawEnvGuardStructure.php`（構造の固定に使う純関数）

正典の未決論点 q2（適用途中の失敗を動的に作れない）を**構造の固定**で代えるための走査器。
**新しい走査ロジックなので AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」が
発火する**（負例と正例 / 解決できない形を落とす分岐 / 空振りの検査 / docblock）。

```php
/**
 * 指定したメソッドの本体が「適用のループが try の本体にあり、
 * 復元の呼び出しが finally (または catch) の本体にある」構造かを判定する純関数。
 *
 * ★**このテストは意図的に脆い**。`RawEnvSnapshot` の中身を書き換えると赤くなるのが正しい挙動である
 *   (「適用が try の外へ出ていないか」を人手のレビューに委ねないための pin)。
 *   赤くなったときは判定を緩めるのではなく、構造が本当に変わってよいのかを確認すること。
 * ★判定はトークン位置の比較だけで行い、行番号・インデント・整形 (Pint) には依存させない。
 *   構文解析ライブラリ (nikic/php-parser) は vendor に推移依存としてしか存在しないため使わない。
 *
 * ★**走査対象**: `ReflectionMethod` の開始行〜終了行で切り出した断片を、
 *   `<?php` を前置してから `token_get_all()` にかけたトークン列。
 *   (切り出した断片は `public static function …` から始まり PHP 開始タグを持たないため、
 *   前置しないと `T_INLINE_HTML` になる。)
 * ★**保証しないもの**: メソッド本体の外にある構造 / 呼び出し先の実装 /
 *   実行時に本当に巻き戻ることそのもの (それは動的には検査できない。だからこの走査がある)。
 */
final class RawEnvGuardStructure
{
    /** メソッド本体のトークン列を返す (fail-closed: メソッドが無ければ例外)。 */
    public static function methodTokens(string $class, string $method): array;

    /** キーワードの本体 `{ … }` のトークン範囲 [開き, 閉じ] を返す。 */
    public static function blockRange(array $tokens, int $keywordIndex): array;

    /** 指定 token id の出現位置をすべて返す。 */
    public static function findTokens(array $tokens, int $id): array;

    /** `foreach (<変数> as …)` の形でその変数を直接回している foreach の位置。 */
    public static function foreachOver(array $tokens, string $variable): array;

    /** `$var->method(` の形の呼び出し位置。 */
    public static function methodCalls(array $tokens, string $variable, string $method): array;

    /** `self::method(` の形の呼び出し位置。 */
    public static function staticCalls(array $tokens, string $method): array;

    /** `throw` の出現位置。 */
    public static function throwTokens(array $tokens): array;
}
```

**判定の手順（契約テストが行う。メソッドごとに期待件数まで書く）**

共通の前段: `methodTokens()` でトークン列を得る（メソッドが見つからない / ファイルが読めない
→ **例外**）。`blockRange()` は波括弧の対応が取れない場合に**例外**にする
（文字列補間の `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` は開き括弧として同列に数える）。
ブロックの件数は**完全一致**で見る（「存在する」だけを見ると、`try` の内と外の両方に
候補がある状態でも緑になる）。

| メソッド | `try` | `catch` | `finally` | 適用のループ | ループの中身 | 復元の呼び出し | 再送出 |
|---|---|---|---|---|---|---|---|
| `with()` | 1 | 1 | 1 | `$changes` を直接回す `foreach` が 1 件。`try` 本体の**内**、`catch` / `finally` 本体の**外** | その `foreach` 本体に `self::apply(` が**ちょうど 1 件** | `finally` 本体に `$snapshot->restore(` が 1 件 | （`catch` の `throw $e` は例外の連結のためであり本項の対象外） |
| `captureAndClear()` | 1 | 1 | **0** | `$keys` を直接回す `foreach` が 1 件。`try` 本体の内 | その `foreach` 本体に `self::apply(` が**ちょうど 1 件** | `catch` 本体に `$snapshot->restore(` が 1 件 | `catch` 本体に `throw` が**ちょうど 1 件** |
| `restore()` | 0 | 0 | 0 | `$this->state` を直接回す `foreach` が 1 件 | — | — | `throw` が 1 件で、その `foreach` 本体範囲の**外**にあること（＝失敗を蓄積してループ後にだけ送出する） |

`self::apply(` を見るのが load-bearing である — 空のループを `try` に残して実際の適用を
別の場所へ移す書き換えを、`foreach` の位置だけでは止められないためである。

**自己検査（`tests/Unit/Architecture/RawEnvGuardStructureTest.php`）**

| 群 | 入力（ナウドキュメントの合成ソース） | 期待 |
|---|---|---|
| 正例 1 | `try { foreach ($changes …) { self::apply(…); } } finally { $snapshot->restore(); }` | 適用が `try` 内・`self::apply(` がループ内・復元が `finally` 内と判定 |
| 正例 2 | `try { foreach ($keys …) { self::apply(…); } } catch (…) { $snapshot->restore($e); throw $e; }` | 復元と再送出が `catch` 内と判定 |
| 負例 1 | 適用の `foreach` を `try` の**外**へ出した形 | 判定が偽になる |
| 負例 2 | 復元の呼び出しを `finally` の**外**へ出した形 | 判定が偽になる |
| 負例 3 | **空のループを `try` に残し、`self::apply(` を `try` の外へ移した形** | `staticCalls()` がループ本体に 0 件 → 判定が偽になる |
| 負例 4 | `catch` から `throw` を落とした形 | `throwTokens()` が `catch` 本体に 0 件 → 判定が偽になる |
| 負例 5 | `throw` を復元のループの**中**へ入れた形（最初の失敗で止まる形） | 判定が偽になる |
| 負例 6 | `foreach (array_keys($changes) as …)`（直接回していない） | `foreachOver()` の候補に入らない（誤検出しない） |
| fail-closed 1 | `try` が 2 件ある入力 | 例外 |
| fail-closed 2 | 波括弧が閉じていない入力 | 例外 |
| fail-closed 3 | 対象メソッドが存在しない | 例外 |
| 母集団 | `foreach` が 1 件も無い入力 | 空を返す（**検出器は母集団の非空を契約としない**。非空は使う側が持つ） |

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`with()` は `@template TReturn` で `mixed` を絞る）
- [x] 公開 API のキーは `string`（`non-empty-string` を宣言すると不正入力の負例が書けなくなる）。
      非空・書式・拒否は実行時の契約（`Webmozart\Assert`）で、**虚偽の `@var` や ignore を使わない**
- [x] `mixed` が現れるのは値のフィールドの中だけで、口の引数・戻り値は型が付く
- [x] `putenv()` の戻り値 `false` を握り潰さない（`false` のまま扱う分岐が残らない）
- [x] `restore()` の `$failed` は `list<string>`
- [x] `Env::enablePutenv()` は `void` を返すので戻り値の未使用警告が出ない
- [x] `RawEnvGuardStructure` の戻り値は `list<int>` / `array{int, int}` /
      `list<array{id: int|null, text: string, line: int}>` で宣言する

### テスト計画（`tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php`）

正典 v1 の i11 が定める (a)–(f) に、i10 の実行時固定 (g) と構造の固定 (h) を足す。

| # | 検査 | 対応する正典項目 |
|---|---|---|
| a-1 | 3 面の存在状態と値が食い違う状態（`$_SERVER` 有 / `$_ENV` 無 / `putenv` 空文字）の往復 | i11 (a) |
| a-2 | 「存在するが値が null」を「存在しない」に潰さない（`$_SERVER[K] = null` の往復） | i11 (a) / i3 |
| a-3 | 非文字列（配列）を入れた面が同じ値のまま戻る | i3 |
| b-1 | 空文字・等号を含む値・未設定の往復（`putenv('K=a=b')` が値 `a=b` になる） | i11 (b) / i4 |
| b-2 | 元から未設定のキーは実行後も 3 面とも未設定に戻る | i11 (b) |
| c-1 | 本体が例外を投げても 3 面が復元される（閉包の口） | i11 (c) |
| d-1 | 不正キーで例外になる（空 / `=` 入り / NUL 入り / 整数へ畳まれたキー） | i11 (d) / i8 |
| d-2 | 拒否キーで例外になる（`DB_DATABASE` / `DB_CONNECTION` / `DB_URL` / `TEST_TOKEN` / `APP_CONFIG_CACHE`） | i11 (d) / i9 |
| d-3 | process 値に NUL があれば第 1 段で例外になる（`putenv()` の `ValueError` を持ち越さない） | i8 |
| d-4 | **拒否キーを 2 番目以降に置いても、先行キーの 3 面が 1 面も変わっていない**（閉包の口 / 持ち回りの口の両方） | i11 (d) / i6 |
| d-5 | 拒否されたとき本体（閉包）が 1 度も呼ばれていない | i11 (d) |
| e-1 | 同一キーの入れ子で、内側の復元が**外側の適用値**へ戻る（呼び出し前の状態へ飛ばない） | i11 (e) |
| f-1 | `env()` は 3 面とも設定なら `$_SERVER` を読む | i11 (f) / i2 |
| f-2 | `$_SERVER` だけ未設定なら `$_ENV` を読む | i11 (f) / i2 |
| f-3 | `$_SERVER` と `$_ENV` が未設定なら `putenv` 面を読む | i11 (f) / i2 |
| f-4 | 指定しなかった面が明示的に未設定になる（`none()->withServer(...)` で `$_ENV` / `getenv` が未設定） | i7 |
| g-0 | **前提の pin**: i10 のプローブキー（`APP_LOCALE`）が `.env.testing` に宣言されている | i10 |
| g-1 | `forgetLaravelEnvRepository()` の前後で `Env::getRepository()` のインスタンス同一性が変わる | i10 / q3 |
| g-2 | **口を呼ばずに** env を読み直すと、3 面へ入れた値が `.env.testing` の値へ**戻ってしまう**（機序の観測） | i10 |
| g-3 | **口を呼んでから** env を読み直すと、3 面へ入れた値が**維持される** | i10 |
| h-1 | **構造の固定（閉包の口）**: 適用の `foreach` が `try` の本体にあり、その本体に `self::apply(` が 1 件、`restore()` の呼び出しが `finally` の本体にある | q2 の代替 |
| h-2 | **構造の固定（持ち回りの口）**: 未設定化の `foreach` が `try` の本体にあり、その本体に `self::apply(` が 1 件、`restore()` の呼び出しと `throw` が `catch` の本体にある | q2 の代替 |
| h-3 | **構造の固定（復元）**: `restore()` の `throw` が復元のループの**外**にある（最初の失敗で止めず、全キーを戻してからまとめて送出する形） | 例外の契約 |
| i-1 | 持ち回りの口: `captureAndClear()` が 3 面を未設定にし、`restore()` で元へ戻る | i5 (b) |
| i-2 | `$changes` に現れないキーには一切触れない | i3 |

**g-2 / g-3 の作り方（機序を実際に通す。`$loaded` の状態を決定的に作る）**

`refreshApplication()` は `RefreshDatabase` のトランザクションを壊しうるので使わない。
代わりに **env の再読み込みだけ**を起こす。
さらに、**immutable writer の `$loaded` に `APP_LOCALE` が載っている状態を各ケースの中で作る**
（載っているかどうかは直前のテストが repository を捨てたかに依存するため、
priming をしないと実行順で結果が変わる）。

```php
$reload = static function (): void {
    (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables)->bootstrap(app());
};

// --- priming: この repository の writer が APP_LOCALE を $loaded に持つ状態を作る ---
$prime = static function () use ($reload): void {
    // (1) 3 面を一旦未設定にする / (2) 読み出し口を捨てる / (3) 読み直す
    RawEnvSnapshot::captureAndClear(['APP_LOCALE']);   // ★退避は使い捨て (外側で元へ戻すため)
    RawEnvSnapshot::forgetLaravelEnvRepository();
    $reload();
    // (4) .env.testing の値が読み込まれたことを確認する (= $loaded に載った)
    expect(env('APP_LOCALE'))->toBe('en');
};

// g-2: 口を呼ばないと .env.testing の値で上書きされる (機序の観測)
$prime();
RawEnvSnapshot::with(['APP_LOCALE' => RawEnvChannels::sameOnAllSurfaces('zz')], function () use ($reload): void {
    $reload();
    expect(env('APP_LOCALE'))->toBe('en');   // .env.testing の値へ戻された
});

// g-3: 口を呼べば注入値が維持される
$prime();
RawEnvSnapshot::with(['APP_LOCALE' => RawEnvChannels::sameOnAllSurfaces('zz')], function () use ($reload): void {
    RawEnvSnapshot::forgetLaravelEnvRepository();
    $reload();
    expect(env('APP_LOCALE'))->toBe('zz');   // 3 面の値が「外部で定義済み」として尊重された
});
```

- **ケースの外側では、自前で退避した元の 3 面へ戻す**（`beforeEach` / `afterEach` の自前の
  退避・復元が担う。priming の `captureAndClear()` の戻り値は使い捨てにしてよい）。
- プローブキーに `APP_LOCALE` を選ぶ理由: **`.env.testing` が実際に宣言していて**（機序が成立する）、
  かつ config は既にロード済みなので env を触っても**アプリの振る舞いに影響しない**。
  `DB_*` は拒否対象なので使えない。
- `.env.testing` の再読み込みで `DB_DATABASE` が `app_test_fallback` に変わることは無い —
  `tests/bootstrap.php` が Laravel boot 前に 3 面へ注入しており、
  immutable writer から見て「外部で定義済み」だからである（`$loaded` に載っていない）。
  この前提は本テストの docblock に書く。
- 後始末として `forgetLaravelEnvRepository()` を `afterEach` でもう一度呼び、
  repository の `$loaded` の状態を持ち越さない。

**保証範囲の明記（ファイル冒頭の docblock。表で書く）**

| 契約 | 担保の手段 |
|---|---|
| 第 1 段で拒否されたときは 1 面も書き換わらない | 動的テスト (d-4) |
| 本体が throw しても 3 面が復元される | 動的テスト (c-1) |
| **適用ループの途中で throw してもそこまでの変更が巻き戻る** | **構造テストのみ (h-1 / h-2)。動的には未検証** |
| **復元が最初の失敗で止まらず、全キーを戻してからまとめて例外になる** | **構造テストのみ (h-3)。動的には未検証** — `putenv()` を失敗させる状況をテストから作れず、失敗を注入する差し替え口は新設しない |
| 読み出しの優先順が `$_SERVER` → `$_ENV` → `putenv` である | 動的テスト (f-1〜f-3)。上流の adapter 構成が変われば赤くなる（望ましい fail） |
| `forgetLaravelEnvRepository()` が env の読み直しでの上書きを防ぐ | 動的テスト (g-1〜g-3)。上流が副作用を変えたら赤くなる |

**プローブキーの選び方（load-bearing）**: i10 用の `APP_LOCALE` を除き、
`.env.testing` / `phpunit.xml` / 実 shell が定義しない専用の接頭辞（`RAW_ENV_PROBE_`）を使い、
**値に phpdotenv の予約語**（`true` / `false` / `null` / `(true)` 等）**を使わない**
（`env()` がこれらを bool / null / `''` へ変換するため「文字列がそのまま返る」前提が崩れる）。

**このテストは検査対象である部品を使わずに 3 面を触る**（部品で作った状態を部品で確かめると
同語反復になるため）。したがって**前後の掃除を自前で持つ**が、
**単に unset して終える形にはしない** — `beforeEach` でプローブキー
（`RAW_ENV_PROBE_*` と `APP_LOCALE`）の 3 面の**元の存在状態と値**を素の
`array_key_exists()` / `getenv()` で退避し、`afterEach` でその状態へ戻す
（実行環境に元から在った値を壊さない）。これが i12 の許可 3 か所のうち
「部品の契約テスト」に当たる。

### リスク

- **`Env::enablePutenv()` の副作用が上流で変わる**（正典 q3）。→ g-1〜g-3 が赤くなる。
  docblock の監視条件に従って手段を再評価する。
- **プローブキーが実行環境に存在する**と f 系が揺れる。→ 専用接頭辞と、前後の退避・復元で潰す。
- **構造テストは意図的に脆い**（整形や実装の書き換えで赤くなる）。→ 赤くなったら判定を
  緩めるのではなく、構造が本当に変わってよいのかを確認する旨を docblock に書く。
- **`LoadEnvironmentVariables` の再実行が他のキーへ副作用を持つ**。→ 再実行は
  `.env.testing` の値を同じ値で書き直すだけである（外部で定義済みのキーは触らない）。
  ただし `forgetLaravelEnvRepository()` を呼んだ後の再実行は `$loaded` を作り直すので、
  `afterEach` で必ずもう一度口を呼んで状態を持ち越さない。

---

## 施策 2: 走査器と gate の新設 (i12)

### 変更箇所

- 新規: `tests/Support/RawEnv/RawEnvWriteKind.php`（string backed enum）
- 新規: `tests/Support/RawEnv/RawEnvWriteSite.php`（値オブジェクト）
- 新規: `tests/Support/RawEnv/RawEnvDirectWriteAllowance.php`（enum。許可の型付き分類）
- 新規: `tests/Support/RawEnv/RawEnvDirectWriteScanner.php`（純関数の走査器）
- 新規: `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php`（走査器の自己検査）
- 新規: `tests/Architecture/RawEnvDirectWriteGateTest.php`（gate 本体）

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 施策 3・施策 4 で全違反を解消してから gate を緑にする

### 走査器の設計

**走査は既存の `Tests\Support\PhpTokenScan::normalize()`（空白 / コメント / DocComment を
除いた添字連番のリスト）の上で行う。同じ正規化を 2 本持たない。**

#### 検出する形（`RawEnvWriteKind`）

| 値 | 形 | 例 |
|---|---|---|
| `element_assign` | 面の要素への代入（通常 / 複合 / `??=` / 前後置インクリメント / 多段添字） | `$_SERVER['K'] = …` / `$_ENV['K'] .= …` / `$_SERVER['K'] ??= …` / `$_ENV['K']++` / `$_SERVER['a']['b'] = …` |
| `element_unset` | 面の要素の削除 | `unset($_SERVER['K'], $_ENV['K'])` |
| `whole_assign` | 面そのものへの代入（複合代入を含む） | `$_SERVER = […]` / `$_ENV += […]` |
| `reference_taken` | 面 / 面の要素への参照の取得 | `&$_SERVER['K']` / `&$_ENV` |
| `destructuring_target` | 分割代入の左辺に面が現れる形 | `[$_SERVER['K']] = $v;` / `list($_ENV['K']) = $v;` |
| `putenv` | プロセス面への書き込み（両形） | `putenv('K=V')` / `putenv('K')` |
| `unresolved` | 分類できなかった出現（**必ず違反**。目録へ登録できない） | 後述 |

#### 関数名の解決（AGENTS.md 走査器共通規約 (a)）

`putenv` は**完全修飾名で突き合わせる**。短名一致は使わない（別名つき取り込み 1 つで
検査が黙るため）。ファイルごとに次を先に組み立てる:

1. `namespace` 宣言（**名前空間ごとに**取り込み対応表を持つ）
2. `use function` の取り込み対応表（`use function putenv;` / `use function putenv as alias;` /
   group use `use function Acme\{bar, baz as qux};` を解いた **別名 → 完全修飾名**）
3. そのファイルが**自分で `putenv` という名前の関数を宣言しているか**

判定:

- `T_NAME_FULLY_QUALIFIED` の `\putenv` → 一致
- 取り込み対応表で完全修飾名が `putenv` になる別名 → 一致
- 非修飾の `putenv`（名前空間の中でもグローバルへ fallback する）→ 一致。
  ただし **(3) が真なら「未解決」**（そのファイルのローカル関数を指す可能性があるため）
- `T_NAME_RELATIVE`（`namespace\putenv`）→ **グローバル名前空間のときだけ**一致
  （名前空間の中では `\Current\putenv` に解決されるため不一致）
- `T_NAME_QUALIFIED`（`Acme\putenv`）で完全修飾名が `putenv` にならないもの → 不一致
- 直前が `->` / `?->` / `::` / `function` / `new` / `const` → 不一致（メソッド・宣言・定数）
- 直後が `(` でない → 不一致
- **fail-closed**: `use function` の取り込みを完全修飾名へ解けない形 /
  **1 ファイルに `namespace` 宣言が 2 つ以上ある** / **波括弧つき `namespace { … }` を使っている**
  → そのファイルの `putenv` 相当の出現をすべて **`unresolved`** にする

#### 面（`$_SERVER` / `$_ENV`）の分類

前処理として **分割代入の対象範囲**に印を付ける:

- `[` の**対応する `]` の直後**が代入記号（`==` / `===` / `=>` ではない `=`）である `[` … `]`
- `T_LIST` の `(` … `)` の直後が `=` である区間

範囲の中では、さらに **lvalue の根**かどうかを見る（範囲に入っただけでは書き込みにしない）。
角括弧を 2 種に分ける — **添字の括弧**（直前の有意トークンが変数 / `]` / `)`）と
**パターンの括弧**（それ以外）。面が `destructuring_target` になるのは次の両方を満たすときだけ:

1. 面と範囲の根の間に**添字の括弧が 1 つも無い**こと
2. 面が要素の先頭位置にあること（直前の有意トークンが `[` / `(` / `,`）

これにより `[[$_ENV['K']]] = $v;`（入れ子のパターン）は検出し、
`[$other[$_SERVER['K']]] = $v;` / `list($other[$_SERVER['K']])` の `$_SERVER`
（添字を求めるための**読み出し**）は検出しない。

`T_VARIABLE` の `$_SERVER` / `$_ENV` について:

- 分割代入の対象範囲の中 → `destructuring_target`
- 直前が `&` → `reference_taken`
- `unset(` の引数リストの中で、**その面が書き換え対象の根にある**とき
  （直前の有意トークンが `(` または `,`）→ `element_unset`
  （`unset($other[$_SERVER['K']]);` の `$_SERVER` は根ではないので読み出し）
- 直後が `[` なら、**連続する添字の連鎖をすべて読み飛ばして**から次の有意トークンを見る:
  - 代入系（`=` / `.=` / `+=` / `-=` / `*=` / `/=` / `%=` / `**=` / `??=` / `|=` / `&=` / `^=` / `<<=` / `>>=`）→ `element_assign`
  - `++` / `--`（前置は直前で判定）→ `element_assign`
  - それ以外 → 読み出し（記録しない）
- 直後が `[` でない（面そのものの出現）:
  - 直後が代入系 → `whole_assign`
  - 直前が `(` または `,` かつ直後が `)` または `,` → 読み出し（値渡しの引数。記録しない）
  - `foreach` の頭で直後が `T_AS` → 読み出し（記録しない）
  - **それ以外 → `unresolved`**

#### 保証しないもの（正本は走査器の docblock。本設計にも要旨を写す）

- **可変関数呼び出し**（`$fn = 'putenv'; $fn('K=V');`）と
  **`call_user_func` 等の間接呼び出し**（`call_user_func('putenv', …)`）
- 名前を実行時に解決する書き込み（可変変数 / `extract()`）
- 面を**値渡しで受けた関数**が内部で書き換える形（`foo($_SERVER)` の呼び先）
- `Dotenv` のような**ライブラリ経由の間接的な書き込み**
- ヒアドキュメント / ナウドキュメントの本文（`token_get_all()` からは 1 トークンに見える。
  **実測で確認済み**であり、走査器の自己検査が負例をナウドキュメントで持てる理由でもある）
- 文字列リテラル・コメントの中の綴り
- `devnotes/` 配下（母集団から外している）

**したがって gate の主張は「部品の外に 3 面への直接の書き込みが 1 件も無い」ではなく、
「上に列挙した字句の書き込み形が、許可した 3 か所以外に 1 件も無い」である。**
D50・契約文書・部品と gate の docblock はこの**同じ限定表現**で統一する
（AGENTS.md 走査器共通規約 (b) の「保証範囲の外にする構文は docblock へ明記し、
その構文について検出力を主張しない」に従う）。

> **監視条件**: 可変関数呼び出しや `call_user_func` で 3 面へ書く形が実際に現れたら、
> **目録へ登録するのではなく検出規則を足す**。
> （文字列リテラル `'putenv'` を一律に未解決とする案は採らない — 走査器自身と
> `RawEnvWriteKind` がその文字列を持つため、許可 3 か所を増やさない限り
> 設計が自分自身を違反にしてしまう。）

### gate の設計（`tests/Architecture/RawEnvDirectWriteGateTest.php`）

```php
/** 許可の根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const RAW_ENV_DIRECT_WRITE_REASON_MIN_LENGTH = 30;

/** 走査対象ファイル数の床値 (走査が空振り 0 件でも「違反 0 件」で緑になるのを止める)。 */
const RAW_ENV_DIRECT_WRITE_SCANNED_FILE_FLOOR = 1900;

/**
 * 3 面へ直接書いてよい置き場の目録 (型付き + 具体的根拠必須 + 件数の完全一致)。
 *
 * ★**免除ではなく「1 件の事実」の登録**である。件数は完全一致で、増えても減っても赤になる。
 * ★`unresolved` は登録できない (G7 が別途赤にする)。
 *
 * @return array<string, array{
 *     allowance: RawEnvDirectWriteAllowance,
 *     counts: array<string, int>,
 *     reason: non-empty-string,
 * }>
 */
function rawEnvDirectWriteAllowances(): array
{
    return [
        'tests/Support/RawEnv/RawEnvSnapshot.php' => [
            'allowance' => RawEnvDirectWriteAllowance::ComponentItself,
            'counts' => [/* 実装時に実測して書く */],
            'reason' => '3 面の退避・注入・復元を担う部品そのもの。ここへ集約するために'
                .'他のすべての置き場から直接の書き込みを取り上げている (正典 v1 の i1)。',
        ],
        'tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php' => [
            'allowance' => RawEnvDirectWriteAllowance::ComponentContractTest,
            'counts' => [/* 実装時に実測して書く */],
            'reason' => '部品の契約テスト。検査対象である部品を使わずに 3 面の状態を作らないと'
                .'往復そのものを検査できない (部品で作った状態を部品で確かめると同語反復になる)。',
        ],
        'tests/bootstrap.php' => [
            'allowance' => RawEnvDirectWriteAllowance::PreFrameworkBootstrap,
            'counts' => ['element_assign' => 2, 'putenv' => 1],
            'reason' => '枠組みが立ち上がる前の足場。テスト DB 名を 3 面へ注入してから'
                .'単一点ガードを走らせる位置であり、autoload された部品を呼べる段階より前に動く。',
        ],
    ];
}
```

| # | 検査 | 目的 |
|---|---|---|
| G1 | 走査対象に列挙した書き込み形が無い（目録の登録分を差し引く） | 違反そのもの |
| G2 | 走査ファイル数が床値以上 | 空振りの検出（(b)「母集団が 0 件」と「違反が 0 件」の区別） |
| G3 | **走査対象数 + 除外数 = 追跡 PHP 総数** | どこにも分類されず黙って落ちるファイルが無いこと |
| G4 | 除外集合が `devnotes/` 配下と完全一致 | 除外が広がっていないこと |
| G5 | `devnotes/` に追跡 PHP が実在する | 除外の形骸化の検出 |
| G6 | 目録の登録先ファイルが実在する | 形骸化した登録を残さない |
| G7 | 目録に `unresolved` を登録していない | 未解決を免除で黙らせない（fail-closed の担保） |
| G8 | 目録の実測件数が登録件数と完全一致（増減とも赤） | 無断の増加を止める |
| G9 | 目録の根拠が 30 文字以上 | 「同上」を弾く |
| G10 | **許可パス集合が期待する 3 パスと完全一致** | 許可箇所を増やさない・入れ替えない |
| G11 | **各パスと `RawEnvDirectWriteAllowance` の対応が完全一致** | 分類の入れ替えを検出する |
| G12 | **`counts` のキーが既知の `RawEnvWriteKind` の値だけであること / 件数が正の整数であること** | 綴り間違いで登録が黙って無効化されるのを防ぐ |
| G13 | 代表パス（部品 / 契約テスト / `tests/bootstrap.php`）が母集団に実在する | 走査根が生きていること |

**走査根の単一出典**: `Tests\Support\TrackedPhpSourceFiles::all(base_path())` を使う
（git 追跡下の `*.php` から blade を除く）。同じ列挙を 2 本持たない。
除外は `devnotes/` 配下だけで、除外の判定と件数は G3〜G5 が固定する。

### 走査器の自己検査（`tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php`）

**負例・正例は fixture ファイルを置かず、ナウドキュメント（`<<<'PHP'`）のソース文字列を
走査器へ直接渡す。** fixture ファイルを置くと gate の母集団に入り、許可箇所を増やすことになる。
ナウドキュメントの本文は `token_get_all()` では 1 トークン
（`T_ENCAPSED_AND_WHITESPACE`）になり中の綴りが見えないため、**この自己検査ファイル自身は
gate に対して違反にならない**（実測で確認済み）。

| 群 | 入力 | 期待 |
|---|---|---|
| 正例 1（data provider） | 代入系 14 種を 1 回ずつ（`=` `.=` `+=` `-=` `*=` `/=` `%=` `**=` `??=` `\|=` `&=` `^=` `<<=` `>>=`） | すべて `element_assign` |
| 正例 2 | 前置 `++$_SERVER['K']` / 後置 `$_ENV['K']--` / 多段添字 `$_SERVER['a']['b'] = …` | `element_assign` |
| 正例 3 | `unset($_SERVER['K'], $_ENV['K']);` | `element_unset` を 2 件 |
| 正例 4 | `$_SERVER = [];` / `$_ENV += [];` | `whole_assign` |
| 正例 5 | `$r = &$_SERVER['K'];` / `$r = &$_ENV;` | `reference_taken` |
| 正例 6 | `[$_SERVER['K']] = $v;` / `list($_ENV['K']) = $v;` / `[[$_ENV['K']]] = $v;`（入れ子のパターン） | `destructuring_target` |
| 正例 7 | `putenv('K=V');` / `putenv('K');` | `putenv` |
| 正例 8 | `\putenv('K=V');` | `putenv`（完全修飾） |
| 正例 9 | `use function putenv as setRawEnv;` + `setRawEnv('K=V');` | `putenv`（別名解決） |
| 正例 10 | グローバル名前空間での `namespace\putenv('K=V');` | `putenv` |
| 負例 1 | `use function Acme\{putenv as p2};` + `p2('K=V');` | 検出しない（完全修飾名が `\putenv` にならない） |
| 負例 2 | 名前空間の中の `namespace\putenv('K=V');` | 検出しない（`\Current\putenv` に解決される） |
| 負例 3 | `$_SERVER['K'] ?? null` / `foreach ($_SERVER as $k => $v)` / `f($_SERVER)` | 検出しない |
| 負例 4 | `unset($other[$_SERVER['K']]);` | 検出しない（面は根にない） |
| 負例 4b | `[$other[$_SERVER['K']]] = $v;` / `list($other[$_SERVER['K']]) = $v;` | 検出しない（分割代入の範囲内だが lvalue の根ではなく、添字を求めるための読み出し） |
| 負例 5 | `$x->putenv('K=V');` / `X::putenv('K=V');` | 検出しない |
| 負例 6 | `myputenv('K=V');` / `not_putenv('K=V');` / `putenv_safe('K=V');` | 検出しない（**接頭辞・打ち消し・接尾辞の 3 形**。AGENTS.md 走査器共通規約 (e)） |
| 負例 7 | 文字列リテラル `'putenv($_SERVER)'` / コメント中の同じ綴り | 検出しない |
| 未解決 1 | 名前空間の中で `function putenv() {}` を宣言したうえでの非修飾呼び出し | `unresolved` |
| 未解決 2 | 1 ファイルに `namespace` 宣言が 2 つ / 波括弧つき `namespace { … }` | `unresolved` |
| 未解決 3 | `$_SERVER;`（単独の出現。読み出しとも書き込みとも決まらない） | `unresolved` |
| 母集団 | 空文字列 / PHP 開始タグだけ | 例外にせず 0 件を返す（**検出器は母集団の非空を契約としない**。非空は使う側の gate が持つ） |

### PHPStan 適合チェック

- [x] 走査器は純関数（`list<RawEnvWriteSite>` を返す。副作用なし）
- [x] `token_get_all()` の戻り値は `PhpTokenScan::normalize()` が
      `list<array{id: int|null, text: string, line: int}>` へ正規化済み
- [x] enum の値は `string` backed（目録のキーに使うため）
- [x] gate の目録は `array<string, array{allowance: …, counts: array<string,int>, reason: non-empty-string}>`

### リスク

- **走査器が拾いすぎる**（正常なコードを違反と断定する）。→ 負例で両方向を固定する。
  拾いすぎは「見逃す」より安全な側だが、誤検出は開発を止めるので負例を厚くする。
- **`unresolved` が既存コードで大量に出る**。→ 実測では 3 面の出現はすべて分類可能な形である
  （`tests/bootstrap.php` の `TestDatabaseEnv::pgsqlOverrideDatabase($_SERVER, …)` は
  「直前が `(`、直後が `,`」の読み出しに当たる）。実装時に全数を確認する。
- **床値が実態と乖離する**。→ 実測（追跡 PHP 2,114 − devnotes 22 = 2,092）に対し 1900 を置く。

---

## 施策 3: 既存 3 実装の部品への移送と同時削除

### 変更箇所

- `tests/Feature/Support/ProductionEnvGuardTest.php`（L353-520 付近の関数 7 本のうち 6 本を削除）
- `tests/Feature/Config/ConfigHardeningTest.php`（L14-59 の `evaluateConfigFileWithEnv()`）
- `tests/Feature/Auth/PasskeyOriginDeclarationTest.php`（L16-72 の `evaluateFortifyConfigWithEnv()`）

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 上記 3 本が変更対象そのもの。**呼び出し側のケース本文は意味を変えない**

### 変更後コード

#### `tests/Feature/Support/ProductionEnvGuardTest.php`

```php
use Tests\Support\RawEnv\RawEnvChannels;
use Tests\Support\RawEnv\RawEnvSnapshot;

// 残すのは 3 本だけ (対象変数の宣言と、フックをまたぐ退避の預け入れ / 取り出し)。
// 退避・復元・消去・閉包の 5 本は削除し、部品へ寄せる。

/**
 * フックをまたぐ退避の預け入れ / 取り出し (Pest の TestCase へ動的プロパティを生やさない)。
 *
 * ★`take()` は**必ず保存スロットを空にして**返す。`beforeEach` が退避の途中で失敗したとき、
 *   `afterEach` が前ケースの退避を再利用する経路を構造的に消すためである。
 * ★ここが持つのは**部品が作った退避の値**だけで、退避・復元のロジックは持たない。
 */
function productionEnvGuardStoreRawSnapshot(RawEnvSnapshot $snapshot): void
{
    productionEnvGuardRawSnapshotSlot($snapshot, false);
}

function productionEnvGuardTakeRawSnapshot(): ?RawEnvSnapshot
{
    return productionEnvGuardRawSnapshotSlot(null, true);
}

function productionEnvGuardRawSnapshotSlot(?RawEnvSnapshot $store, bool $take): ?RawEnvSnapshot
{
    static $slot = null;

    if ($store !== null) {
        $slot = $store;

        return null;
    }

    if (! $take) {
        return $slot;
    }

    $taken = $slot;
    $slot = null;

    return $taken;
}

/**
 * 二重判定の対象になる環境変数 (宣言が正本)。
 *
 * @return list<string>
 */
function productionEnvGuardFakeFlagVariables(): array
{
    return array_values(ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES);
}

beforeEach(function (): void {
    productionEnvGuardStoreRawSnapshot(
        RawEnvSnapshot::captureAndClear(productionEnvGuardFakeFlagVariables()),
    );
    // ... config baseline (変更なし)
});

afterEach(function (): void {
    productionEnvGuardTakeRawSnapshot()?->restore();
});

// 呼び出し側 (8 か所) は閉包の口を直接使う
test('config が false でも $_SERVER に true が残っていれば violation', function (): void {
    RawEnvSnapshot::with(
        ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()->withServer('true')],
        function (): void {
            $errors = (new ProductionEnvGuard)->violations();
            expect($errors)->toHaveCount(1);
            expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
            expect($errors[0])->toContain('$_SERVER');
        },
    );
});
```

#### `tests/Feature/Config/ConfigHardeningTest.php`

```php
use Tests\Support\RawEnv\RawEnvChannels;
use Tests\Support\RawEnv\RawEnvSnapshot;

/**
 * 指定の env 変数を差し替えて config ファイルを再評価する。
 *
 * ★退避・復元は `Tests\Support\RawEnv\RawEnvSnapshot` が持つ。ここは呼び出し側であって、
 *   同じ処理を書き直さない (正典 v1 の i1)。
 *
 * @param  array<string, string|null>  $env  null は 3 面とも未設定にする
 * @return array<string, mixed>
 */
function evaluateConfigFileWithEnv(string $configFile, array $env): array
{
    $changes = [];
    foreach ($env as $key => $value) {
        $changes[$key] = $value === null
            ? RawEnvChannels::none()
            : RawEnvChannels::sameOnAllSurfaces($value);
    }

    return RawEnvSnapshot::with($changes, function () use ($configFile): array {
        $config = require base_path("config/{$configFile}");
        expect($config)->toBeArray();

        /** @var array<string, mixed> $config */
        return $config;
    });
}
```

#### `tests/Feature/Auth/PasskeyOriginDeclarationTest.php`

```php
use Tests\Support\RawEnv\RawEnvChannels;
use Tests\Support\RawEnv\RawEnvSnapshot;

/**
 * 環境変数を差し替えて config/fortify.php を評価し、返り値を得る。
 *
 * ★3 面をそろえて埋めるのも、必ず元へ戻すのも `RawEnvSnapshot` が担う
 *   (「元が未設定なら空文字ではなく未設定で戻す」= 未宣言の意味を変えない、も部品側の契約)。
 *
 * @param  array<string, string>  $overrides
 * @return array<string, mixed>
 */
function evaluateFortifyConfigWithEnv(array $overrides): array
{
    $changes = array_map(
        static fn (string $value): RawEnvChannels => RawEnvChannels::sameOnAllSurfaces($value),
        $overrides,
    );

    return RawEnvSnapshot::with($changes, static function (): array {
        /** @var array<string, mixed> $config */
        $config = require base_path('config/fortify.php');

        return $config;
    });
}
```

### 書き換えで検証している不変条件を減らさないことの対応表

| ファイル | 現行が検証していること | 書き換え後 |
|---|---|---|
| `ProductionEnvGuardTest` | 3 面それぞれ独立に読まれること / 未設定・空文字・`false` の別 / 非文字列の fail-closed / 指定しなかった面の未設定化 | **同じ**（`RawEnvChannels` の面ごとの指定でそのまま表現できる。非文字列は `withServer(mixed)` で通る） |
| `ConfigHardeningTest` | env 既定分岐の値（`session.secure` / `app.locale` / `mass_assignment_strict` / `cache.serializable_classes` / `prism-prompt.cache.enabled` / fortify passkeys ブロック） | **同じ**。加えて「存在するが値が null」を消さなくなる（現行のバグの解消） |
| `PasskeyOriginDeclarationTest` | 宣言経路が接続元を正規形へ寄せること・空要素を残すこと・身元の識別子を正規化器へ通さないこと | **同じ** |

**旧関数名が復活しないことは、名前の目録ではなく gate の G1 が構造的に止める**
（許可 3 か所以外では 3 面へ書けないので、同じ実装をもう一度書くことができない）。
同じことを 2 か所で見張らない（思考原則 2）。

### PHPStan 適合チェック

- [x] `array_map` で `array<string, RawEnvChannels>` を作る際にキーが保存される
      （`array_map` はコールバック 1 本のときキーを保存する）
- [x] `RawEnvSnapshot::with()` の `@template TReturn` で戻り値の `array<string, mixed>` が通る
- [x] `productionEnvGuardRawSnapshotSlot()` の `static` 変数に `?RawEnvSnapshot` の型注釈を付ける
- [x] `productionEnvGuardFakeFlagVariables()` の `list<string>` が
      `captureAndClear(list<string>)` に合う（施策 1 の型を `list<string>` にしたため）

### テスト計画

- [x] 既存テストの削除は行わない（**ヘルパ関数の削除**であってケースの削除ではない）
- [x] 3 ファイルの既存ケースがすべて緑のままであること
- [x] `ConfigHardeningTest` の「存在するが値が null」の往復は**部品側の契約テスト (a-2)** が持つ
      （呼び出し側に往復検査を残すと i1 の集約が崩れる）
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`beforeEach` の途中で失敗したときに前ケースの退避が使われる**。→ `take()` が
  スロットを必ず空にするので、`afterEach` は「今のケースで預けた退避」以外を復元しない。
- **`evaluateConfigFileWithEnv()` の中で `expect()` が失敗したとき**も `finally` で復元される
  （現行と同じ）。

---

## 施策 4: 拒否対象キーを親プロセスへ立てる検査の書き換え

### 変更箇所

- `scripts/ci/pgsql_test_conn.php`（L228-255 の `pgsqlTestArtisanEnv()`）
- `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php`（L56-116 の 5 ケース）

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` が変更対象そのもの
- **`scripts/ci/ensure-test-db.php` は変更しない**（`pgsqlTestArtisanEnv()` の
  呼び出し位置も戻り値の形も変えないため）

### 現行コード

```php
// scripts/ci/pgsql_test_conn.php
function pgsqlTestArtisanEnv(string $projectRoot, string $database): array
{
    $conn = pgsqlTestConnValues($projectRoot);

    $inherited = [];
    foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);   // ← 親環境を直接読む
        if (is_string($value) && $value !== '') { $inherited[$key] = $value; }
    }

    return array_merge($inherited, [ /* 固定値 10 キー */ ]);
}
```

```php
// tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php
it('overrides a parent environment that already sets DB_DATABASE / DB_URL / APP_CONFIG_CACHE to a dev DB', function (): void {
    $keys = ['DB_DATABASE', 'DB_URL', 'APP_CONFIG_CACHE'];
    $originals = array_combine($keys, array_map(getenv(...), $keys));

    putenv('DB_DATABASE=app');                                   // ← 親プロセスへ dev DB 名
    putenv('DB_URL=pgsql://postgres:postgres@127.0.0.1:5432/app');
    putenv('APP_CONFIG_CACHE=/tmp/attacker-controlled-config.php');
    // ... finally で putenv 面だけ戻す ($_SERVER / $_ENV は触らない)
});
```

**この検査は現状ほぼ空振りである** — `pgsqlTestArtisanEnv()` は
`DB_DATABASE` / `DB_URL` / `APP_CONFIG_CACHE` を親から**読んでいない**（読むのは
`PATH` / `HOME` / `TMPDIR` と、`pgsqlTestConnValues()` 経由の `DB_HOST` 等だけ）。
つまり「固定値が勝つ」ことを、入力にその値が載っていない状態で確かめている。

### 変更後コード

```php
// scripts/ci/pgsql_test_conn.php

/**
 * 3 面を読み出し優先順 (`$_SERVER` → `$_ENV` → `getenv`) で 1 枚の配列へ合成する (純関数)。
 *
 * @param  array<string, mixed>  $server
 * @param  array<string, mixed>  $env
 * @param  array<string, mixed>  $process
 * @return array<string, mixed>
 */
function pgsqlTestMergeParentEnv(array $server, array $env, array $process): array
{
    // 後勝ちの array_merge なので、優先度の低い順に並べる。
    return array_merge($process, $env, $server);
}

/**
 * 親プロセスの環境を 3 面の優先順で 1 枚の配列へ読み出す。
 *
 * ★**読み出しだけ**を行う (3 面へ書き込まない)。
 * ★組み立てそのものは pgsqlTestComposeArtisanEnv() が純関数として持つ。
 *   分けてあるのは、テストが**親プロセスの環境を触らずに**組み立てを検査できるようにするためである
 *   (テストのために親へ dev DB 名を立てる、という危険を構造的に消す)。
 * ★`$_SERVER` は env 以外の項目 (`argv` 等) も持つが、組み立ては
 *   `PATH` / `HOME` / `TMPDIR` しか読まないので影響しない。
 *
 * @return array<string, mixed>
 */
function pgsqlTestParentEnv(): array
{
    $process = getenv();

    return pgsqlTestMergeParentEnv($_SERVER, $_ENV, is_array($process) ? $process : []);
}

/**
 * スキーマ更新の子プロセスへ渡す環境変数を **継承せず** 組み立てる (純関数)。
 *
 * @param  array<string, mixed>  $parentEnv  親プロセスの環境を表す配列
 * @param  array{host: string, port: string, username: string, password: string}  $conn
 * @return array<string, string>
 */
function pgsqlTestComposeArtisanEnv(string $projectRoot, string $database, array $parentEnv, array $conn): array
{
    $inherited = [];
    foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
        $value = $parentEnv[$key] ?? null;
        if (is_string($value) && $value !== '') {
            $inherited[$key] = $value;
        }
    }

    // 固定値が常に勝つ順で合成する。
    return array_merge($inherited, [
        'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => pgsqlTestConfigCachePath($projectRoot),
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => '',
        'DB_HOST' => $conn['host'],
        'DB_PORT' => $conn['port'],
        'DB_USERNAME' => $conn['username'],
        'DB_PASSWORD' => $conn['password'],
        'DB_DATABASE' => $database,
        'CACHE_STORE' => 'array',
    ]);
}

/** 実際の親環境と接続値から組み立てる (結線)。挙動は従来と同じ。 */
function pgsqlTestArtisanEnv(string $projectRoot, string $database): array
{
    return pgsqlTestComposeArtisanEnv(
        $projectRoot,
        $database,
        pgsqlTestParentEnv(),
        pgsqlTestConnValues($projectRoot),
    );
}
```

```php
// tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php (書き換え後の要点)

/** テスト用の接続値 (実 DB へはつながない)。 */
function fakePgsqlConnValues(): array
{
    return ['host' => '10.0.0.9', 'port' => '15432', 'username' => 'probe-user', 'password' => 'probe-pass'];
}

it('does not leak arbitrary environment variables into the child process env', function (): void {
    $env = pgsqlTestComposeArtisanEnv(
        __DIR__, 'app_test_8af22c44',
        ['SOME_SECRET' => 'leaked', 'PATH' => '/usr/bin'],
        fakePgsqlConnValues(),
    );

    expect($env)->not->toHaveKey('SOME_SECRET')->and($env['PATH'])->toBe('/usr/bin');
});

it('overrides a parent environment that already sets DB_DATABASE / DB_URL / APP_CONFIG_CACHE to a dev DB', function (): void {
    // ★親プロセスの環境は 1 面も触らない。危険な値は**組み立ての入力として**与える。
    $env = pgsqlTestComposeArtisanEnv(
        __DIR__, 'app_test_8af22c44',
        [
            'DB_DATABASE' => 'app',
            'DB_URL' => 'pgsql://postgres:postgres@127.0.0.1:5432/app',
            'APP_CONFIG_CACHE' => '/tmp/attacker-controlled-config.php',
        ],
        fakePgsqlConnValues(),
    );

    expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
        ->and($env['DB_URL'])->toBe('')
        ->and($env['APP_CONFIG_CACHE'])->toBe(pgsqlTestConfigCachePath(__DIR__));
});

it('merges the three surfaces with $_SERVER winning over $_ENV over getenv', function (): void {
    $merged = pgsqlTestMergeParentEnv(
        ['A' => 'server', 'B' => 'server'],
        ['A' => 'env', 'B' => 'env', 'C' => 'env'],
        ['A' => 'process', 'B' => 'process', 'C' => 'process', 'D' => 'process'],
    );

    expect($merged)->toBe(['A' => 'server', 'B' => 'server', 'C' => 'env', 'D' => 'process']);
});

it('wires the real parent environment and the resolved connection values into the composed env', function (): void {
    // ★結線そのものを見る (固定値だけを見ると、親環境や接続値を捨てても緑になる)。
    $parent = pgsqlTestParentEnv();
    $conn = pgsqlTestConnValues(__DIR__);
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
        $value = $parent[$key] ?? null;
        if (is_string($value) && $value !== '') {
            expect($env[$key])->toBe($value);          // 親環境由来であること
        } else {
            expect($env)->not->toHaveKey($key);
        }
    }

    expect($env['DB_HOST'])->toBe($conn['host'])       // 接続値由来であること
        ->and($env['DB_PORT'])->toBe($conn['port'])
        ->and($env['DB_USERNAME'])->toBe($conn['username'])
        ->and($env['DB_PASSWORD'])->toBe($conn['password']);
});
```

`carries over only PATH / HOME / TMPDIR` / `forces DB_URL empty` / `pins the computed base name`
の 3 ケースも、同じく `pgsqlTestComposeArtisanEnv()` を直接呼ぶ形へ移す（親環境に触らない）。
**`fakePgsqlConnValues()` は実際の接続値と重ならない値**にする
（重ねると「接続値を捨てても緑」になる経路が残る）。

### 保持する検証（失わないこと）

| 現行が検証していること | 書き換え後 |
|---|---|
| 継承するのは `PATH` / `HOME` / `TMPDIR` だけ | **同じ**（入力に他のキーを載せて確かめるので**検出力が上がる**） |
| 固定値が常に勝つ（`DB_DATABASE` / `DB_URL` / `APP_CONFIG_CACHE`） | **同じ**（現行は入力に載っていないので空振りだった。書き換え後は実際に載る） |
| `DB_URL` は空で固定 | 同じ |
| 組み立て結果が子プロセス起動へ渡ること | **既存の別ケースが押さえる**（「`$runArtisan` へ渡る引数列がちょうど 2 通り・この順序・それ以外は 1 度も渡らない」）。`pgsqlTestArtisanEnv()` の呼び出し位置も戻り値の形も変えないため、そのケースは無変更で残る |
| 結線（実際の親環境の読み出し + 接続値から組み立てること） | **新しいケース 2 本**で固定する（3 面の優先順の純関数検査 + 親環境・接続値の由来検査） |

### PHPStan 適合チェック

- [x] `getenv()`（引数なし）の戻り値は `array<string, string>|false` として扱う
      （`is_array()` で絞る）
- [x] `pgsqlTestMergeParentEnv()` / `pgsqlTestComposeArtisanEnv()` の入力は
      `array<string, mixed>`。値は `is_string()` で絞ってから使う
- [x] `pgsqlTestComposeArtisanEnv()` の戻り値は `array<string, string>`

### リスク

- **`scripts/ci/pgsql_test_conn.php` は CI と `setup-worktree.sh` が使う実運用スクリプトである**。
  → 変更は純関数の切り出しのみで、`pgsqlTestArtisanEnv()` の入出力は変えない。
  安全側の再検証（`TestDatabaseEnv::isDevDatabase()` / `isAllowedTestDatabase()`）は
  呼び出し側 `ensureTestDatabaseSchemaUpdated()` にあり、そこは無変更。
- **同ファイルは乖離台帳 D30 の対象パスに含まれる**。今回の変更は D30 が説明する不変条件
  （出自の記録と孤児の分類）を変えないので、**新規登録も記述の更新も行わない**。

---

## 施策 5: 乖離台帳・件数 pin・契約文書の整合

### 変更箇所

- `docs/template-divergence.md`（新規登録 **D50**。宣言行「登録エントリ: 46 件」→「47 件」）
- `tests/Support/TemplateDivergence/LedgerPins.php`（`DIVERGENCE_ENTRY_COUNT` 46 → 47）
- `docs/app-integration-guide.md`（§2「条件付きで発火するゲート」表へ 1 行追加）
- `tests/Architecture/IntegrationGuideGateTableSyncTest.php`
  （`INTEGRATION_GUIDE_GATE_TABLES` の `'#### 条件付きで発火するゲート' => 13` → `14`、
  docblock の「21 件」→「22 件」2 か所）

### 乖離台帳の確認段（app-design スキル 3-0）

`docs/template-fingerprints.json` のキーに在るかを実測した:

| パス | 指紋台帳のキー | 採用時債務 | 扱い |
|---|---|---|---|
| `tests/bootstrap.php` | **在る** | 無い | **変更しない**（テンプレートと一致した状態を保つ） |
| `scripts/ci/pgsql_test_conn.php` | 在る | 無い | 既に **D30** の対象パス。変更しても新規登録は不要 |
| `docs/app-integration-guide.md` | 在る | 無い | 既に **D42** の対象パス。変更しても新規登録は不要 |
| `tests/Architecture/IntegrationGuideGateTableSyncTest.php` | 在る | 無い | 同上（D42 の対象パス） |
| `tests/Feature/Support/ProductionEnvGuardTest.php` | 在らない | 無い | テンプレートと共有しない。登録不要 |
| `tests/Feature/Config/ConfigHardeningTest.php` | 在らない | 無い | 同上 |
| `tests/Feature/Auth/PasskeyOriginDeclarationTest.php` | 在らない | 無い | 同上 |
| `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` | 在らない | 無い | 同上 |
| 新設 11 ファイル | 在らない | 無い | **テンプレートに無い領域への上積み** → 登録する（記録の原則「迷ったら登録する」＋ `StrictTypesDeclarationGateTest` / `CacheGuardWiringGateTest` / `ArchBaselineTest` の先例） |

### D50 の登録内容（案）

```
## D50 テストが触る生の環境変数 3 面を 1 つの部品へ集約し、部品の外に現れる列挙済みの字句上の直接書き込みを検査で止める

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Support/RawEnv/RawEnvChannels.php` / `tests/Support/RawEnv/RawEnvSnapshot.php` / `tests/Support/RawEnv/RawEnvGuardStructure.php` / `tests/Support/RawEnv/RawEnvWriteKind.php` / `tests/Support/RawEnv/RawEnvWriteSite.php` / `tests/Support/RawEnv/RawEnvDirectWriteAllowance.php` / `tests/Support/RawEnv/RawEnvDirectWriteScanner.php` / `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php` / `tests/Unit/Architecture/RawEnvGuardStructureTest.php` / `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php` / `tests/Architecture/RawEnvDirectWriteGateTest.php` |
| 業務要件起因の説明 | 撮影 PWA の秘匿と本番構成の起動時 fail-fast を守る検査 (ProductionEnvGuardTest / ConfigHardeningTest / PasskeyOriginDeclarationTest) はすべて生の環境変数を差し替えて動く。テンプレートは 3 面の退避・復元をテストごとに書く形のままなので、取りこぼしが起きると守りの検査が実行順で通ったり落ちたりし、守りの主張そのものが信用できなくなる。家系の機能台帳が確定した正典 v1 (不変条件 i1-i12) へ追従して部品へ集約し、部品の外に現れる列挙済みの字句上の直接書き込みを検査で止める (検出しない構文の一覧は走査器の docblock が正本であり、この検査は網羅を主張しない) |
| 揃え続ける不変条件と保証機構 | 3 面の退避が存在と値を別に持ち型を絞らないこと / 検証 → 退避 → 適用 + 本体 → 復元 の 3 相であること / 単一点の守りが前提にするキーを拒否すること / 読み出しの優先順が $_SERVER → $_ENV → putenv であること (RawEnvSnapshotContractTest が実行時に固定) / 列挙した字句の書き込み形が許可 3 か所以外に現れないこと (RawEnvDirectWriteGateTest が deny-by-default で強制し、許可は部品自身とその契約テストと tests/bootstrap.php の 3 か所だけ・件数は完全一致で pin。検出しない構文の一覧は RawEnvDirectWriteScanner の docblock が正本) |
| 再判定の条件 | 家系の正典 v1 が改版されたとき / テンプレート側が同等の部品を採用して還流できるようになったとき / 上流の phpdotenv・Laravel が読み出し順か Env::enablePutenv() の副作用を変えたとき / 走査器が検出しない構文で 3 面へ書く形が実際に現れたとき |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1633-raw-env-snapshot-restore-v1/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

### 契約文書 §2 への追加行（案）

`#### 条件付きで発火するゲート` の表へ:

| ゲート | 発火条件 | 登録先 |
|---|---|---|
| `RawEnvDirectWriteGateTest` | テストが `putenv()` / `$_ENV` / `$_SERVER` を直接書き換えるとき | `Tests\Support\RawEnv\RawEnvSnapshot` 経由へ寄せる（許可 3 か所以外は登録できない） |

（実際の列構成は文書側の既存表に合わせる。列数はヘッダとの完全一致が強制される。
`getenv()` は**読み出し**であって書き込みではないので、発火条件には書かない。）

### 実装時に main の最新から再確定する 3 点（チェックリスト）

1. **D 番号**（本設計は 2026-08-24 時点の最大値 D49 の次として D50 を置いている）
2. **`LedgerPins::DIVERGENCE_ENTRY_COUNT`**（本設計は 46 → 47）と
   `docs/template-divergence.md` の宣言行「登録エントリ: N 件」の**両方**
3. **D50 の対象パス数**（本設計は 11 件。新設ファイルを増減させたら同時に直す）

> T255 / T256 / T258 も同じ台帳を触るため、どれが先にマージされても衝突する。

### PHPStan 適合チェック

- [x] `LedgerPins::DIVERGENCE_ENTRY_COUNT` は `public const int`（型付き定数）
- [x] `INTEGRATION_GUIDE_GATE_TABLES` は `array<string, int>`

### テスト計画

- [x] `TemplateDivergenceLedgerFormatTest`（宣言行・見出しの実数・`LedgerPins` の 3 点一致）が緑
- [x] `TemplateDivergenceFingerprintTest`（指紋台帳との突合）が緑
      — `tests/bootstrap.php` を変更しないので新たな不一致は生まれない
- [x] `IntegrationGuideGateTableSyncTest`（件数 pin / 実在 / 一意性）が緑

### リスク

- **D 番号と件数が他の TODO と衝突する**（T255 / T256 / T258 も同じ台帳を触る）。
  → 上のチェックリストで実装時に再確定する。

---

## 実装手順（テストファースト。**どのテストを先に赤くするか**）

思考原則 5 と「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」に従い、
**必ず赤を確認してから**本体を書く。

| 段 | 作業 | 何が赤くなるか |
|---|---|---|
| 1 | `RawEnvSnapshotContractTest` の a〜g 群を書く（部品はまだ無い） | クラス未定義で赤 |
| 2 | `RawEnvChannels` / `RawEnvSnapshot` を実装する | 段 1 が緑になる |
| 3 | `RawEnvGuardStructureTest`（正例 1 / 負例 3 / fail-closed 2 / 母集団 1）を書く | 走査器未定義で赤 |
| 4 | `RawEnvGuardStructure` を実装し、契約テストへ h-1 / h-2 を足す | 段 3 と h 群が緑になる |
| 5 | `RawEnvDirectWriteScannerTest`（正例 10 群 / 負例 7 群 / 未解決 3 群 / 母集団 1）を書く | 走査器未定義で赤 |
| 6 | `RawEnvWriteKind` / `RawEnvWriteSite` / `RawEnvDirectWriteScanner` を実装する | 段 5 が緑になる |
| 7 | `RawEnvDirectWriteAllowance` と `RawEnvDirectWriteGateTest`（G1〜G13。目録の `counts` は空）を書く | **G1 が 4 ファイル分の違反で赤** / G8 が件数不一致で赤 |
| 8 | 施策 3（3 ファイルの移送と旧実装の削除） | G1 の違反が 3 ファイル分減る |
| 9 | 施策 4（`scripts/ci/pgsql_test_conn.php` の切り出しと検査の書き換え） | G1 の違反が 0 になる |
| 10 | 目録の `counts` を実測して書き込む | G8 が緑になる |
| 11 | 施策 5（台帳・件数 pin・契約文書） | `TemplateDivergenceLedgerFormatTest` / `IntegrationGuideGateTableSyncTest` が緑になる |

**段 7 で「先に赤くする」ことの確認方法**: 段 8・9 の前に gate を走らせ、
違反として `ConfigHardeningTest` / `PasskeyOriginDeclarationTest` / `ProductionEnvGuardTest` /
`TestDatabaseSchemaUpdateTest` の 4 本が列挙されることを実際に見る
（この 4 本が**この gate の負例そのもの**である）。
段 10 の後に、目録の件数を 1 増やして赤くなること・1 減らして赤くなることも確認する
（完全一致であることの裏取り）。
**段 4 の構造テストは「素の main では赤にならない」種類の検査**なので、
実装直後に負例（適用の `foreach` を `try` の外へ一時的に出す）で赤を 1 度確認してから戻す。

## migration / 後方互換の扱い

- **DB migration は無い**（スキーマを触らない）。
- **後方互換の並走を残さない**（AGENTS.md 思考原則 3）:
  - `productionEnvGuardCaptureRaw` / `...RestoreRaw` / `...ClearRaw` /
    `...IsolateRawEnvironment` / `...RestoreRawEnvironment` / `withRawEnvironmentValue` の
    **6 関数は同じコミットで削除する**（別名の移行期間を置かない）。
  - `evaluateConfigFileWithEnv()` / `evaluateFortifyConfigWithEnv()` は
    **名前を残すが中身の退避・復元は消す**（呼び出し側のケース本文を変えないための薄い
    ラッパであり、退避・復元のロジックを持たない = i1 に反しない）。
  - `pgsqlTestArtisanEnv()` は**引数を増やさない**（既定引数で旧経路を残す形にしない）。
    親環境の読み出しは新しい 2 関数へ切り出し、組み立ては純関数へ移す。

## `docs/template-divergence.md` の登録 / 更新 / 削除の要否

| 操作 | 要否 | 対象 |
|---|---|---|
| 新規登録 | **要** | D50（新設 11 ファイル） |
| 既存登録の更新 | **不要** | D30（`scripts/ci/pgsql_test_conn.php`）/ D42（契約文書とゲート索引）はいずれも対象パスに含まれており、説明している不変条件は変わらない |
| 登録の削除 | 不要 | 解消する逸脱は無い |
| 件数 pin の更新 | **要** | `LedgerPins::DIVERGENCE_ENTRY_COUNT` 46 → 47 |
| 採用時債務の操作 | **不要** | 変更するどのパスも `adoption-debt.tsv` に無い（実測） |

## 最終検証（AGENTS.md の検証コマンド全数。全 green でコミット）

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

- フロントの変更は無いが、**検証コマンドの一覧は AGENTS.md が全数を要求している**ので
  すべて走らせる（`tests/js/architecture/verification-commands-doc-sync.test.ts` が
  この一覧と `package.json` の同期を強制している）。
- `composer test` は**ホスト全体で 1 本ずつ**しか走らない（グローバルテストロック）。
  待ち時間が出るのは正常で、30 秒ごとの heartbeat が出ている間はハングではない。
- 実装は **worktree**（`scripts/setup-worktree.sh <task-id>`）で行う（main 直接実装は禁止）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 5 施策が 1 つの意味単位で動く（施策 2 だけ入れると gate が赤いまま、施策 3 だけ入れると i1 が維持されない）。加えて `tests/Support` への部品追加・`scripts/ci` の CI スクリプト・乖離台帳の件数 pin という**衝突しやすい 3 領域**を同時に触る |
| 競合リスク | **T249（起動 probe の共通 runner への一元化）と同時進行しない** — 同じ `tests/Support` へ部品を足し、親プロセスの env に対する前提を両方が扱うため、着手が重なるとレビューが交錯する。**T255 / T256 / T258 とは `docs/template-divergence.md` の D 番号と `LedgerPins` の定数で衝突する** — どれが先にマージされても、実装時に「再確定する 3 点」のチェックリストを踏むこと |
