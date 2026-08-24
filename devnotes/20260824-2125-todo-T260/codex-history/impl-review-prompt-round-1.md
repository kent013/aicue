## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはコードレビュアーとして Laravel + Svelte アプリ aicue の改善実装をレビューする。

## レビュー観点
1. **設計との一致性**: 詳細設計書の 5 施策・実装手順 11 段の意図どおりに実装されているか
2. **正確性**: 走査器 (RawEnvDirectWriteScanner / RawEnvGuardStructure) の判定に見逃し (fail-open) や誤検出があるか。とくに「解決できない形を落とす (fail-closed)」が守られているか
3. **PHPStan level 10 適合性**: 型の widen・虚偽の @var・@phpstan-ignore が無いか
4. **テスト網羅性**: 正例と負例が両方向で押さえられているか。「違反 0 件」と「母集団 0 件」の区別があるか
5. **セキュリティ**: 拒否キー (DB_ 接頭辞 / TEST_TOKEN / APP_CONFIG_CACHE) の判定に抜けが無いか。テストが親プロセスの環境を汚す経路が残っていないか
6. **保証範囲の記述**: docblock と乖離台帳 (D53) が「保証しないもの」を誇張せず書いているか。同じ内容を 2 か所に書いて食い違う形になっていないか
7. **DTO/JsonResource パターン / DESIGN.md / Atomic Design**: 本差分はテスト基盤のみでフロント変更が無いため該当しない (該当しないことの確認のみ)

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書く

---
## 詳細設計書

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

    /**
     * `foreach (<式> as …)` の形でその式を直接回している foreach の位置。
     *
     * ★式は**正規化済みのトークンの綴りの列**で渡す (`['$changes']` / `['$keys']` /
     *   `['$this', '->', 'state']`)。丸括弧を開いた最初の有意トークンから綴りが
     *   完全一致で連続し、次の有意トークンが `T_AS` であることを見る。
     *   `foreach (array_values($this->state) as …)` は最初の有意トークンが
     *   `array_values` なので候補に入らない (誤検出しない)。
     *
     * @param  list<string>  $expressionTexts
     * @return list<int>
     */
    public static function foreachOverExpression(array $tokens, array $expressionTexts): array;

    /** `$var->method(` の形の呼び出し位置。 */
    public static function methodCalls(array $tokens, string $variable, string $method): array;

    /** `self::method(` の形の呼び出し位置。 */
    public static function staticCalls(array $tokens, string $method): array;

    /** `new <クラス名>(` の形の生成位置。 */
    public static function constructions(array $tokens, string $class): array;

    /**
     * 制御フローのトークンの出現位置。
     *
     * ★受け付けるのは `T_THROW` / `T_RETURN` / `T_BREAK` / `T_CONTINUE` の 4 つだけで、
     *   それ以外の token id は**例外**にする (fail-closed。指定の綴り間違いで
     *   「0 件だから合格」になるのを防ぐ)。
     *
     * @return list<int>
     */
    public static function controlFlowTokens(array $tokens, int $tokenId): array;

    /** `$var[] =` の形の追加の位置。 */
    public static function variableAppends(array $tokens, string $variable): array;

    /**
     * `$var = <式>;` の形の代入の位置と右辺のトークンの綴りの列。
     *
     * @return list<array{index: int, rhs: list<string>}>
     */
    public static function variableAssignments(array $tokens, string $variable): array;

    /**
     * 指定位置の次のトークンから、深さ 0 の `;` までのトークンの綴りの列。
     *
     * ★`;` が見つからない場合は**例外** (fail-closed)。`throw $e;` の再送出の検査に使う。
     *
     * @return list<string>
     */
    public static function statementTokens(array $tokens, int $index): array;

    /** 各 `if` の [条件のトークン範囲, 本体のトークン範囲]。 */
    public static function ifBlocks(array $tokens): array;

    /**
     * 呼び出し / 生成の丸括弧の中を最上位のカンマで割り、各引数のトークン列を返す。
     *
     * ★括弧の対応が取れない / 引数の区切りが確定しない場合は**例外** (fail-closed)。
     *
     * @return list<list<string>>
     */
    public static function callArguments(array $tokens, int $callIndex): array;
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
| `restore()` | 0 | 0 | 0 | `$this->state` を直接回す `foreach` が 1 件 | 下の 5 条 | — | 下の 5 条 |

`self::apply(` を見るのが load-bearing である — 空のループを `try` に残して実際の適用を
別の場所へ移す書き換えを、`foreach` の位置だけでは止められないためである。

**`restore()` の 5 条**（「唯一の `throw` がループの外にある」だけでは、
ループ内で `break` して抜ける形や、失敗を蓄積せず無条件に送出する形が通ってしまう）:

1. 復元のループの本体に `throw` / `return` / `break` / `continue` が **1 件も無い**
2. `$failed[] = …` がループ本体に**ちょうど 1 件**ある
3. その追加が `$applied === false` の条件分岐の**本体**にある
   （`ifBlocks()` の条件範囲に `$applied` / `T_IS_IDENTICAL` / `false` が並ぶ `if`）
4. ループの**後**の `$failed !== []` の条件分岐の本体に、**メソッド唯一の `throw`** がある
5. その `throw` 以外に、メソッドを途中終了させるトークン（`return` / `throw`）が無い

**例外の連結も構造で固定する**（引数を落としても上の条件だけでは緑になるため）:

- `with()` の `$snapshot->restore(` の第 1 引数が `$bodyError`
- `captureAndClear()` の `$snapshot->restore(` の第 1 引数が `$e`
- `restore()` の `new RuntimeException(` の**第 3 引数が `$previous`**

引数の区切りが確定しない場合は `callArguments()` が**例外**にする（fail-closed）。

**`with()` の `catch` 本体も構造で固定する**。`restore($bodyError)` の引数だけを見ても、
`catch` の中で `$bodyError = null;` にする / 代入そのものを消す / 別の例外を送出する、
という退行が通ってしまうためである:

- `$bodyError = $e` の代入が `catch` 本体に**ちょうど 1 件**あり、右辺が `$e` であること
- `catch` 本体の唯一の `throw` が **`$e` を再送出する**こと
  （`statementTokens()` が返す綴りの列が `['$e']` であること）

**自己検査（`tests/Unit/Architecture/RawEnvGuardStructureTest.php`）**

| 群 | 入力（ナウドキュメントの合成ソース） | 期待 |
|---|---|---|
| 正例 1 | 本番と**同形**の合成入力: `try { foreach ($changes as $key => $channels) { self::apply($key, $channels); } } catch (Throwable $e) { $bodyError = $e; throw $e; } finally { $snapshot->restore($bodyError); }` | `try` / `catch` / `finally` が各 1 件・適用が `try` 内・`self::apply(` がループ内・`$bodyError = $e` が 1 件・`throw $e` の再送出・`restore($bodyError)` の引数まで、すべて期待どおりと判定 |
| 正例 2 | `try { foreach ($keys …) { self::apply(…); } } catch (…) { $snapshot->restore($e); throw $e; }` | 復元と再送出が `catch` 内と判定 |
| 負例 1 | 適用の `foreach` を `try` の**外**へ出した形 | 判定が偽になる |
| 負例 2 | 復元の呼び出しを `finally` の**外**へ出した形 | 判定が偽になる |
| 負例 3 | **空のループを `try` に残し、`self::apply(` を `try` の外へ移した形** | `staticCalls()` がループ本体に 0 件 → 判定が偽になる |
| 負例 4 | `catch` から `throw` を落とした形 | `controlFlowTokens(…, T_THROW)` が `catch` 本体に 0 件 → 判定が偽になる |
| 負例 5 | `throw` を復元のループの**中**へ入れた形（最初の失敗で止まる形） | 判定が偽になる |
| 負例 6 | `foreach (array_keys($changes) as …)` / `foreach (array_values($this->state) as …)`（直接回していない） | `foreachOverExpression()` の候補に入らない（誤検出しない） |
| 負例 7 | 復元のループの中で `break` して抜ける形 | 5 条 (1) に反する → 判定が偽になる |
| 負例 8 | 失敗を蓄積せず `throw` を無条件で置く形（`$failed[] =` が 0 件） | 5 条 (2) に反する → 判定が偽になる |
| 負例 9 | `restore(` の引数を落とした形 / `new RuntimeException(` の第 3 引数を落とした形 | 連結の検査に反する → 判定が偽になる |
| 負例 10 | `catch` の中で `$bodyError = null;` にした形 / 代入そのものを消した形 / `$e` ではなく別の例外を送出する形 | `catch` 本体の検査に反する → 判定が偽になる |
| fail-closed 4 | `controlFlowTokens()` に 4 つ以外の token id を渡す | 例外 |
| fail-closed 5 | `statementTokens()` の対象に `;` が無い入力 | 例外 |
| 正例 3 | `foreach ($this->state as $saved) { … }` | `foreachOverExpression(['$this','->','state'])` が 1 件を返す |
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
| h-1 | **構造の固定（閉包の口）**: 適用の `foreach` が `try` の本体にあり、その本体に `self::apply(` が 1 件、`restore($bodyError)` の呼び出しが `finally` の本体にあり、`catch` 本体に `$bodyError = $e` が 1 件と `$e` の再送出がある | q2 の代替 / 例外の契約 |
| h-2 | **構造の固定（持ち回りの口）**: 未設定化の `foreach` が `try` の本体にあり、その本体に `self::apply(` が 1 件、`restore()` の呼び出しと `throw` が `catch` の本体にある | q2 の代替 |
| h-3 | **構造の固定（復元）**: `restore()` が「ループ内で途中終了しない / 失敗を `$failed[]` へ蓄積する / 蓄積は `$applied === false` の分岐内 / ループ後の `$failed !== []` の分岐にメソッド唯一の `throw` / 他に途中終了が無い」の 5 条を満たし、例外の `previous` 連結が 3 か所とも構造として在ること | 例外の契約 |
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
| 4 | `RawEnvGuardStructure` を実装し、契約テストへ h-1 / h-2 / h-3 を足す | 段 3 と h 群が緑になる |
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


## 実装差分 (git diff)

```diff
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index 88c2fca1..1c45fcc0 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -147,6 +147,7 @@ #### 条件付きで発火するゲート
 | `SsrfPinBoundaryTest` | 外部 URL(特にユーザ入力由来)を取得するとき | `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。安全境界は `config/ssrf-pin.php` に pin する |
 | `DocumentTitleCoverageTest` | Inertia を render する GET named route を足すとき | ページ固有のタイトルを controller 供給メタか `config/seo.php` に持たせる(無いとサイト名だけになる) |
 | `InertiaRenderPageExistsInvariantTest` | 新しいページコンポーネントを足すとき | `resources/js/pages/` に実体を置く(literal 参照と 1:1。参照先が無いページは本番で白画面になる) |
+| `RawEnvDirectWriteGateTest` | テストが `putenv()` / `$_ENV` / `$_SERVER` を直接書き換えるとき | `Tests\Support\RawEnv\RawEnvSnapshot` 経由へ寄せる(許可 3 か所以外は登録できない。`getenv()` は読み出しなので対象外) |
 
 ### 組織識別名 (slug) を足す・変えるとき
 
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 5a435866..a663cf7f 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 49 件
+登録エントリ: 50 件
 
 ## 記録の原則
 
@@ -3038,3 +3038,59 @@ ### 関連
 - 実装: `tests/Architecture/ClaudeHooksWiringTest.php`
 - 設計: `devnotes/20260824-1014-claude-hooks-wiring-t3/`
 - 関連する登録: D18 (起動子と 2 本のスクリプト) / D34 (採用時債務の一覧)
+
+## D53 テストが触る生の環境変数 3 面を 1 つの部品へ集約し、部品の外に現れる字句として列挙した直接書き込みを検査で止める
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Support/RawEnv/RawEnvChannels.php` / `tests/Support/RawEnv/RawEnvSnapshot.php` / `tests/Support/RawEnv/RawEnvGuardStructure.php` / `tests/Support/RawEnv/RawEnvWriteKind.php` / `tests/Support/RawEnv/RawEnvWriteSite.php` / `tests/Support/RawEnv/RawEnvDirectWriteAllowance.php` / `tests/Support/RawEnv/RawEnvDirectWriteScanner.php` / `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php` / `tests/Unit/Architecture/RawEnvGuardStructureTest.php` / `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php` / `tests/Architecture/RawEnvDirectWriteGateTest.php` |
+| 業務要件起因の説明 | 撮影 PWA の秘匿と本番構成の起動時 fail-fast を守る検査 (ProductionEnvGuardTest / ConfigHardeningTest / PasskeyOriginDeclarationTest) はすべて生の環境変数を差し替えて動く。テンプレートは 3 面の退避・復元をテストごとに書く形のままなので、取りこぼしが起きると守りの検査が実行順で通ったり落ちたりし、守りの主張そのものが信用できなくなる。家系の機能台帳が確定した正典 v1 (不変条件 i1-i12) へ追従して部品へ集約し、部品の外に現れる字句として列挙した直接書き込みを検査で止める (検出しない構文の一覧は走査器の docblock が正本であり、この検査は網羅を主張しない) |
+| 揃え続ける不変条件と保証機構 | 3 面の退避が存在と値を別に持ち型を絞らないこと / 検証 → 退避 → 適用 + 本体 → 復元 の 3 相であること / 単一点の守りが前提にするキーを拒否すること / 読み出しの優先順が $_SERVER → $_ENV → putenv であること (RawEnvSnapshotContractTest が実行時に固定) / 列挙した字句の書き込み形が許可 3 か所以外に現れないこと (RawEnvDirectWriteGateTest が deny-by-default で強制し、許可は部品自身とその契約テストと tests/bootstrap.php の 3 か所だけ・件数は完全一致で pin。検出しない構文の一覧は RawEnvDirectWriteScanner の docblock が正本) |
+| 再判定の条件 | 家系の正典 v1 が改版されたとき / テンプレート側が同等の部品を採用して還流できるようになったとき / 上流の phpdotenv・Laravel が読み出し順か Env::enablePutenv() の副作用を変えたとき / 走査器が検出しない構文で 3 面へ書く形が実際に現れたとき |
+| 決めた日 | 2026-08-24 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260824-1633-raw-env-snapshot-restore-v1/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+テンプレートに無い領域への**上積み**である (指紋台帳のキーに 1 件も無い)。
+`tests/bootstrap.php` は指紋台帳のキーであり現在テンプレートと一致しているので**変更しない** —
+正典 v1 の i12 は同ファイルを許可する側であり、変更の必要が無い。
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 3 面の退避・復元 | テストごとにその場で書く (ファイル内の関数 / 評価ヘルパの中) | **1 つの部品** (`RawEnvSnapshot`) に集約し、2 通りの結び方 (閉包を囲む口 / 退避を持ち回る口) を提供する |
+| 「存在するが値が null」 | `?? null` 退避で「存在しない」へ潰れる書き方が残る | 存在と値を別に持ち、面ごとに独立して戻す |
+| 差し替えの安全 | 退避と適用が同一ループ (途中で失敗するとそこまでの変更が残る) | 検証 → 退避 → 適用 + 本体 → 復元 の 3 相。検証で拒否されたときは 1 面も触らない |
+| 拒否するキー | 持たない | 単一点の守りが前提にするキー (`DB_` 接頭辞 / `TEST_TOKEN` / `APP_CONFIG_CACHE`) を検証の段で拒否する。**例外の許可一覧は持たない** |
+| 読み出し順の固定 | 注釈で書く | **Laravel の `env()` を通した実行時の検査**で固定する (上流の既定が変わったら赤くなる) |
+| 部品の外の直接操作 | 検査しない | 追跡 PHP を母集団にした deny-by-default の gate で止める (許可は 3 か所・件数は完全一致で pin) |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **守りの検査の土台である**。3 面のうち 1 面でも戻し漏れると、あとから走る別のテストの入力が
+   静かに変わる。`RefreshDatabase` はプロセスの環境変数を守らないので、枠組みは肩代わりしない。
+2. **拒否と検査は対で要る**。拒否だけを入れると「拒否に当たるキーを触りたい検査」が部品を使えず
+   手書きへ逃げ、危険が見えない場所へ移るだけになる。実際、本アプリには
+   テスト実行中の親プロセスへ dev DB 名と攻撃者制御の設定キャッシュパスを立てる検査が在り
+   (AGENTS.md 禁止事項 3 の隣接ハザード)、同じ変更でその経路を消している。
+3. **テンプレートに同等の部品が無い**。家系の機能台帳で本アプリのセルは `update_pending`
+   (`pre-v1` → `v1`) であり、追従の順序として本アプリが先行する。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「テストが生の環境変数 3 面を触る経路は `Tests\Support\RawEnv\RawEnvSnapshot` の 1 本だけであり、
+> 走査器が列挙した字句の書き込み形は許可 3 か所以外に 1 件も現れない」
+
+- 部品の契約 (往復 / 3 相 / 拒否 / 読み出し順 / 読み出し口の作り直し) は
+  `RawEnvSnapshotContractTest` が実行時に固定する
+- 動的に作れない 2 点 (適用途中の巻き戻り / 復元が最初の失敗で止まらないこと) は
+  **構造の固定**で代え、契約テストの冒頭に「動的には未検証」と明記する
+- 走査器は正例・負例の両方向を自己検査で裏取りし、解決できない形は `unresolved` として
+  gate を失敗させる (免除で黙らせられない)
+
+### 関連
+
+- 実装: `tests/Support/RawEnv/` / `tests/Architecture/RawEnvDirectWriteGateTest.php`
+- 設計: `devnotes/20260824-1633-raw-env-snapshot-restore-v1/`
+- 関連する登録: D30 (`scripts/ci/pgsql_test_conn.php` の出自の記録) / D42 (契約文書のゲート索引)
diff --git a/scripts/ci/pgsql_test_conn.php b/scripts/ci/pgsql_test_conn.php
index 46d1c0d2..66ce71f7 100644
--- a/scripts/ci/pgsql_test_conn.php
+++ b/scripts/ci/pgsql_test_conn.php
@@ -227,11 +227,63 @@ function pgsqlTestConfigCachePath(string $projectRoot): string
  */
 function pgsqlTestArtisanEnv(string $projectRoot, string $database): array
 {
-    $conn = pgsqlTestConnValues($projectRoot);
+    return pgsqlTestComposeArtisanEnv(
+        $projectRoot,
+        $database,
+        pgsqlTestParentEnv(),
+        pgsqlTestConnValues($projectRoot),
+    );
+}
+
+/**
+ * 3 面を読み出し優先順 (`$_SERVER` → `$_ENV` → `getenv`) で 1 枚の配列へ合成する (純関数)。
+ *
+ * 後勝ちの array_merge なので、優先度の低い順に並べる。
+ *
+ * @param  array<string, mixed>  $server
+ * @param  array<string, mixed>  $env
+ * @param  array<string, mixed>  $process
+ * @return array<string, mixed>
+ */
+function pgsqlTestMergeParentEnv(array $server, array $env, array $process): array
+{
+    return array_merge($process, $env, $server);
+}
+
+/**
+ * 親プロセスの環境を 3 面の優先順で 1 枚の配列へ読み出す。
+ *
+ * ★**読み出しだけ**を行う (3 面へ書き込まない)。
+ * ★組み立てそのものは pgsqlTestComposeArtisanEnv() が純関数として持つ。
+ *   分けてあるのは、テストが**親プロセスの環境を触らずに**組み立てを検査できるようにするためである
+ *   (テストのために親へ dev DB 名を立てる、という危険を構造的に消す)。
+ * ★`$_SERVER` は env 以外の項目 (`argv` 等) も持つが、組み立ては
+ *   `PATH` / `HOME` / `TMPDIR` しか読まないので影響しない。
+ *
+ * @return array<string, mixed>
+ */
+function pgsqlTestParentEnv(): array
+{
+    $process = getenv();
 
+    return pgsqlTestMergeParentEnv($_SERVER, $_ENV, is_array($process) ? $process : []);
+}
+
+/**
+ * スキーマ更新の子プロセスへ渡す環境変数を **継承せず** 組み立てる (純関数)。
+ *
+ * 親環境は引数で受け取る。実際の親プロセスの環境を読むのは pgsqlTestParentEnv() の責務で、
+ * この関数は「入力に何が載っていても固定値が勝つ」ことだけを担う。
+ *
+ * @param  array<string, mixed>  $parentEnv  親プロセスの環境を表す配列
+ * @param  array{host: string, port: string, username: string, password: string}  $conn
+ * @return array<string, string>
+ */
+function pgsqlTestComposeArtisanEnv(string $projectRoot, string $database, array $parentEnv, array $conn): array
+{
     $inherited = [];
     foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
-        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
+        $value = $parentEnv[$key] ?? null;
         if (is_string($value) && $value !== '') {
             $inherited[$key] = $value;
         }
diff --git a/tests/Architecture/IntegrationGuideGateTableSyncTest.php b/tests/Architecture/IntegrationGuideGateTableSyncTest.php
index 096ef6ec..bade2a59 100644
--- a/tests/Architecture/IntegrationGuideGateTableSyncTest.php
+++ b/tests/Architecture/IntegrationGuideGateTableSyncTest.php
@@ -60,7 +60,7 @@
  * 契約文書本文には詳細を写さない):
  *
  *  1. **表の構成集合そのものは固定しない**。ある行を**別の実在するゲート名へ差し替える**ことは
- *     検出しない。21 件の期待集合を本ファイルへ写すと表と検査の 2 か所に同じ一覧を持つことになり、
+ *     検出しない。22 件の期待集合を本ファイルへ写すと表と検査の 2 か所に同じ一覧を持つことになり、
  *     必ず食い違う。**正本は文書側の表 1 か所**とし、ここは件数・実在・一意性に限る
  *     (`LedgerPins` の 3 定数や ForbiddenStatement の件数 pin と同じ作法)。
  *  2. 表に書かれた**発火条件・登録先の意味的な正確さ**は見ない。表が宣言する実装単位
@@ -72,7 +72,7 @@
  *     「ここに無いゲートは発火しない」とは読めない。
  *  5. ゲートの**中身**が生きているか (その検査が空振りしていないか) は各ゲート自身の責務である。
  *  6. 表の列のうち 2 列目以降は見ない (パス表記や別ゲート名を書いてよい欄である)。
- *  7. **ゲート母集団の全体件数は見ない**。本検査の不変条件は「表に載せた 21 件が実在すること」で
+ *  7. **ゲート母集団の全体件数は見ない**。本検査の不変条件は「表に載せた 22 件が実在すること」で
  *     あって「ゲートが N 本あること」ではないため、根拠の無い下限値は持たない。
  *  8. **CommonMark / GFM の完全な構文解析はしない**。上に書いた列 0 の限定文法だけを受理し、
  *     規格上は表・見出しとして描画されうる形 (3 スペースまでの字下げ、先頭 `|` の省略、
@@ -106,7 +106,7 @@
  */
 const INTEGRATION_GUIDE_GATE_TABLES = [
     '#### 新規リソースで必ず踏む Architecture ゲート' => 8,
-    '#### 条件付きで発火するゲート' => 13,
+    '#### 条件付きで発火するゲート' => 14,
 ];
 
 /** 1 列目のセルが満たすべき形 (バッククォート 1 対で囲まれた、末尾が Test の英数字)。 */
diff --git a/tests/Architecture/RawEnvDirectWriteGateTest.php b/tests/Architecture/RawEnvDirectWriteGateTest.php
new file mode 100644
index 00000000..5b5d8bbe
--- /dev/null
+++ b/tests/Architecture/RawEnvDirectWriteGateTest.php
@@ -0,0 +1,300 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\RawEnv\RawEnvDirectWriteAllowance;
+use Tests\Support\RawEnv\RawEnvDirectWriteScanner;
+use Tests\Support\RawEnv\RawEnvWriteKind;
+use Tests\Support\RawEnv\RawEnvWriteSite;
+use Tests\Support\TrackedPhpSourceFiles;
+
+/*
+ * Architecture invariant: 生の環境変数 3 面 (`$_SERVER` / `$_ENV` / `putenv`) への
+ * **直接の書き込み**は `Tests\Support\RawEnv\RawEnvSnapshot` へ集約されており、
+ * 部品の外には現れない (家系の正典 raw-env-snapshot-restore v1 の i12)。
+ *
+ * なぜ要るか: PHP では 1 つの環境変数がプロセスの中で 3 面に現れ、Laravel の `env()` は
+ * `$_SERVER` → `$_ENV` → `putenv` の順に **live で**読む。テストが 1 面だけ戻すと、
+ * 残った面の古い値が先に読まれ、あとから走る別のテストの入力が静かに変わる。
+ * 撮影 PWA の秘匿と本番構成の起動時 fail-fast を守る検査
+ * (`ProductionEnvGuardTest` / `ConfigHardeningTest` / `PasskeyOriginDeclarationTest`) は
+ * すべて 3 面を差し替えて動くので、その土台が揺れると**守りの主張そのものが信用できなくなる**。
+ *
+ * ★**この gate の主張は「3 面への直接の書き込みが 1 件も無い」ではない**。
+ *   「`RawEnvDirectWriteScanner` が列挙した**字句の書き込み形**が、許可した 3 か所以外に
+ *   1 件も無い」である。検出しない構文 (可変関数呼び出し / `call_user_func` /
+ *   値渡しで受けた先の書き換え / ライブラリ経由 / ヒアドキュメント本文) の一覧は
+ *   **走査器の docblock が正本**であり、ここには写さない (2 か所に書くと必ず食い違う)。
+ * ★母集団は `Tests\Support\TrackedPhpSourceFiles` (git 追跡下の `*.php` から blade を除く) から
+ *   **`devnotes/` 配下だけを除いたもの**である。除外が 1 つだけであることと、
+ *   除外が形骸化していないことは G3〜G5 が機械で見る。
+ *   追跡 PHP の**総数そのものは pin しない** — 無関係な PHP を 1 本足すだけで赤くなり、
+ *   守りたい性質 (黙って走査から落ちない) は恒等式のほうが強く固定できるため。
+ * ★`unresolved` は**目録へ登録できない** (G7)。未解決を免除で黙らせる経路を作らない。
+ *
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/** 許可の根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+const RAW_ENV_DIRECT_WRITE_REASON_MIN_LENGTH = 30;
+
+/** 走査対象ファイル数の床値 (走査が空振り 0 件でも「違反 0 件」で緑になるのを止める)。 */
+const RAW_ENV_DIRECT_WRITE_SCANNED_FILE_FLOOR = 1900;
+
+/** 母集団から外す唯一の置き場 (一時スクリプトの置き場であり実行経路にも CI にも載らない)。 */
+const RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX = 'devnotes/';
+
+/**
+ * 3 面へ直接書いてよい置き場の目録 (型付き + 具体的根拠必須 + 件数の完全一致)。
+ *
+ * ★**免除ではなく「1 件の事実」の登録**である。件数は完全一致で、増えても減っても赤になる。
+ * ★`unresolved` は登録できない (G7 が別途赤にする)。
+ *
+ * @return array<string, array{
+ *     allowance: RawEnvDirectWriteAllowance,
+ *     counts: array<string, int>,
+ *     reason: non-empty-string,
+ * }>
+ */
+function rawEnvDirectWriteAllowances(): array
+{
+    return [
+        'tests/Support/RawEnv/RawEnvSnapshot.php' => [
+            'allowance' => RawEnvDirectWriteAllowance::ComponentItself,
+            'counts' => ['element_assign' => 4, 'element_unset' => 4, 'putenv' => 4],
+            'reason' => '3 面の退避・注入・復元を担う部品そのもの。ここへ集約するために'
+                .'他のすべての置き場から直接の書き込みを取り上げている (正典 v1 の i1)。',
+        ],
+        'tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php' => [
+            'allowance' => RawEnvDirectWriteAllowance::ComponentContractTest,
+            'counts' => ['element_assign' => 7, 'element_unset' => 4, 'putenv' => 6],
+            'reason' => '部品の契約テスト。検査対象である部品を使わずに 3 面の状態を作らないと'
+                .'往復そのものを検査できない (部品で作った状態を部品で確かめると同語反復になる)。',
+        ],
+        'tests/bootstrap.php' => [
+            'allowance' => RawEnvDirectWriteAllowance::PreFrameworkBootstrap,
+            'counts' => ['element_assign' => 2, 'putenv' => 1],
+            'reason' => '枠組みが立ち上がる前の足場。テスト DB 名を 3 面へ注入してから'
+                .'単一点ガードを走らせる位置であり、autoload された部品を呼べる段階より前に動く。',
+        ],
+    ];
+}
+
+/**
+ * 母集団 (走査対象) と除外集合を分けて返す。
+ *
+ * @return array{scanned: list<array{absolute: string, relative: string}>, excluded: list<string>}
+ */
+function rawEnvDirectWritePopulation(): array
+{
+    $scanned = [];
+    $excluded = [];
+
+    foreach (TrackedPhpSourceFiles::all(base_path()) as $target) {
+        if (str_starts_with($target['relative'], RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX)) {
+            $excluded[] = $target['relative'];
+
+            continue;
+        }
+
+        $scanned[] = $target;
+    }
+
+    return ['scanned' => $scanned, 'excluded' => $excluded];
+}
+
+/**
+ * 走査対象を全数走査し、ファイルごとの検出結果を返す (読めないファイルは fail-closed)。
+ *
+ * @param  list<array{absolute: string, relative: string}>  $targets
+ * @return array<string, list<RawEnvWriteSite>>
+ */
+function rawEnvDirectWriteScanAll(array $targets): array
+{
+    $found = [];
+
+    foreach ($targets as $target) {
+        $source = file_get_contents($target['absolute']);
+
+        if ($source === false) {
+            // 無音 skip すると書き込みを見逃す (fail-open) ため、読めないファイルは落とす。
+            throw new RuntimeException("読み取れないファイルがあります: {$target['relative']}");
+        }
+
+        $sites = RawEnvDirectWriteScanner::scan($source);
+
+        if ($sites !== []) {
+            $found[$target['relative']] = $sites;
+        }
+    }
+
+    return $found;
+}
+
+/**
+ * 検出結果を種別ごとの件数へ畳む。
+ *
+ * @param  list<RawEnvWriteSite>  $sites
+ * @return array<string, int>
+ */
+function rawEnvDirectWriteCounts(array $sites): array
+{
+    $counts = [];
+
+    foreach ($sites as $site) {
+        $counts[$site->kind->value] = ($counts[$site->kind->value] ?? 0) + 1;
+    }
+
+    ksort($counts);
+
+    return $counts;
+}
+
+/**
+ * 違反の報告文 (直し方まで書く)。
+ *
+ * @param  array<string, list<RawEnvWriteSite>>  $violations
+ */
+function rawEnvDirectWriteFailureMessage(array $violations): string
+{
+    $lines = [];
+
+    foreach ($violations as $relative => $sites) {
+        $detail = implode(', ', array_map(
+            static fn (RawEnvWriteSite $site): string => "{$site->kind->value}@L{$site->line}({$site->subject})",
+            $sites,
+        ));
+        $lines[] = "  - {$relative}: {$detail}";
+    }
+
+    return '生の環境変数 3 面への直接の書き込みが部品の外にあります ('.count($violations).' ファイル):'
+        .PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL
+        .'Tests\Support\RawEnv\RawEnvSnapshot の with() / captureAndClear() + restore() へ寄せてください。'
+        .PHP_EOL.'(許可 3 か所を増やす選択肢は取らない。設計フローを通してから機構を変えること。)';
+}
+
+test('G1: 列挙した字句の書き込み形が許可 3 か所以外に 1 件も無い', function (): void {
+    $population = rawEnvDirectWritePopulation();
+    $found = rawEnvDirectWriteScanAll($population['scanned']);
+    $allowed = rawEnvDirectWriteAllowances();
+
+    $violations = array_diff_key($found, $allowed);
+
+    expect($violations)->toBe([], rawEnvDirectWriteFailureMessage($violations));
+});
+
+test('G2: 走査対象ファイル数が床値以上である (空振りの検出)', function (): void {
+    $population = rawEnvDirectWritePopulation();
+
+    expect($population['scanned'])->not->toBeEmpty()
+        ->and(count($population['scanned']))->toBeGreaterThanOrEqual(RAW_ENV_DIRECT_WRITE_SCANNED_FILE_FLOOR);
+});
+
+test('G3: 走査対象数 + 除外数 = 追跡 PHP 総数 (黙って落ちるファイルが無い)', function (): void {
+    $population = rawEnvDirectWritePopulation();
+    $tracked = TrackedPhpSourceFiles::all(base_path());
+
+    expect(count($population['scanned']) + count($population['excluded']))->toBe(count($tracked));
+});
+
+test('G4: 除外集合が devnotes/ 配下と完全一致する', function (): void {
+    $population = rawEnvDirectWritePopulation();
+
+    foreach ($population['excluded'] as $relative) {
+        expect(str_starts_with($relative, RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX))->toBeTrue(
+            "除外集合に devnotes/ 以外が入っています: {$relative}"
+        );
+    }
+
+    foreach ($population['scanned'] as $target) {
+        expect(str_starts_with($target['relative'], RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX))->toBeFalse(
+            "走査対象に devnotes/ が残っています: {$target['relative']}"
+        );
+    }
+});
+
+test('G5: devnotes/ に追跡 PHP が実在する (除外の形骸化の検出)', function (): void {
+    $population = rawEnvDirectWritePopulation();
+
+    expect($population['excluded'])->not->toBeEmpty();
+});
+
+test('G6: 目録の登録先ファイルが実在する', function (): void {
+    foreach (array_keys(rawEnvDirectWriteAllowances()) as $relative) {
+        expect(is_file(base_path($relative)))->toBeTrue("目録の登録先が実在しません: {$relative}");
+    }
+});
+
+test('G7: 目録に unresolved を登録していない', function (): void {
+    foreach (rawEnvDirectWriteAllowances() as $relative => $entry) {
+        expect(array_key_exists(RawEnvWriteKind::Unresolved->value, $entry['counts']))->toBeFalse(
+            "unresolved は免除で黙らせられません: {$relative}"
+        );
+    }
+});
+
+test('G8: 目録の実測件数が登録件数と完全一致する', function (): void {
+    $population = rawEnvDirectWritePopulation();
+    $found = rawEnvDirectWriteScanAll($population['scanned']);
+
+    foreach (rawEnvDirectWriteAllowances() as $relative => $entry) {
+        $actual = rawEnvDirectWriteCounts($found[$relative] ?? []);
+        $expected = $entry['counts'];
+        ksort($expected);
+
+        expect($actual)->toBe($expected, "目録の件数が実測と食い違っています: {$relative}");
+    }
+});
+
+test('G9: 目録の根拠が具体的である', function (): void {
+    foreach (rawEnvDirectWriteAllowances() as $relative => $entry) {
+        expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(
+            RAW_ENV_DIRECT_WRITE_REASON_MIN_LENGTH,
+            "根拠が短すぎます: {$relative}"
+        );
+    }
+});
+
+test('G10: 許可パス集合が期待する 3 パスと完全一致する', function (): void {
+    expect(array_keys(rawEnvDirectWriteAllowances()))->toBe([
+        'tests/Support/RawEnv/RawEnvSnapshot.php',
+        'tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php',
+        'tests/bootstrap.php',
+    ]);
+});
+
+test('G11: 各パスと許可の分類の対応が完全一致する', function (): void {
+    $actual = array_map(
+        static fn (array $entry): string => $entry['allowance']->value,
+        rawEnvDirectWriteAllowances(),
+    );
+
+    expect($actual)->toBe([
+        'tests/Support/RawEnv/RawEnvSnapshot.php' => RawEnvDirectWriteAllowance::ComponentItself->value,
+        'tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php' => RawEnvDirectWriteAllowance::ComponentContractTest->value,
+        'tests/bootstrap.php' => RawEnvDirectWriteAllowance::PreFrameworkBootstrap->value,
+    ]);
+});
+
+test('G12: 目録の counts のキーが既知の種別で、件数が正の整数である', function (): void {
+    $known = array_map(static fn (RawEnvWriteKind $kind): string => $kind->value, RawEnvWriteKind::cases());
+
+    foreach (rawEnvDirectWriteAllowances() as $relative => $entry) {
+        foreach ($entry['counts'] as $kind => $count) {
+            expect(in_array($kind, $known, true))->toBeTrue("未知の種別が登録されています: {$relative} / {$kind}");
+            expect($count)->toBeGreaterThan(0, "件数は正の整数である必要があります: {$relative} / {$kind}");
+        }
+    }
+});
+
+test('G13: 代表パスが母集団に実在する (走査根が生きていること)', function (): void {
+    $population = rawEnvDirectWritePopulation();
+    $relatives = array_map(
+        static fn (array $target): string => $target['relative'],
+        $population['scanned'],
+    );
+
+    foreach (array_keys(rawEnvDirectWriteAllowances()) as $relative) {
+        expect(in_array($relative, $relatives, true))->toBeTrue("母集団から代表パスが消えています: {$relative}");
+    }
+});
diff --git a/tests/Feature/Auth/PasskeyOriginDeclarationTest.php b/tests/Feature/Auth/PasskeyOriginDeclarationTest.php
index 05bb0784..a43f5117 100644
--- a/tests/Feature/Auth/PasskeyOriginDeclarationTest.php
+++ b/tests/Feature/Auth/PasskeyOriginDeclarationTest.php
@@ -2,6 +2,9 @@
 
 declare(strict_types=1);
 
+use Tests\Support\RawEnv\RawEnvChannels;
+use Tests\Support\RawEnv\RawEnvSnapshot;
+
 /*
  * 宣言経路 (環境変数 → config/fortify.php) が「許可する接続元」を正規形へ寄せることの
  * 端から端までの固定 (T216 施策 B)。
@@ -16,60 +19,30 @@
 /**
  * 環境変数を差し替えて config/fortify.php を評価し、返り値を得る。
  *
- * Laravel の env() は $_SERVER → $_ENV → putenv の 3 経路を見るため 3 つとも埋める
- * (tests/bootstrap.php が同じ作法を採っている)。**必ず finally で元へ戻す**
- * (元が未設定なら空文字ではなく unset で戻す = 「未宣言」の意味を変えないため)。
- * 設定ファイルの評価は副作用として fortify-options を同じ値で書き直すだけで、
- * 他への影響を持たない (Features::* は options を config へ書いて識別子を返す builder)。
+ * ★3 面 ($_SERVER / $_ENV / putenv) をそろえて埋めるのも、必ず元へ戻すのも
+ *   `Tests\Support\RawEnv\RawEnvSnapshot` が担う (「元が未設定なら空文字ではなく未設定で戻す」
+ *   = 未宣言の意味を変えない、も部品側の契約である)。ここは呼び出し側であって、
+ *   同じ処理を書き直さない (家系の正典 raw-env-snapshot-restore v1 の i1)。
+ *   Laravel の env() は 3 面を live に読むため 3 つとも埋める必要がある。
+ * ★設定ファイルの評価は副作用として fortify-options を同じ値で書き直すだけで、
+ *   他への影響を持たない (Features::* は options を config へ書いて識別子を返す builder)。
  *
  * @param  array<string, string>  $overrides
  * @return array<string, mixed>
  */
 function evaluateFortifyConfigWithEnv(array $overrides): array
 {
-    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false, 3: bool, 4: bool}> $saved */
-    $saved = [];
-
-    foreach ($overrides as $key => $value) {
-        $saved[$key] = [
-            $_SERVER[$key] ?? null,
-            $_ENV[$key] ?? null,
-            getenv($key),
-            array_key_exists($key, $_SERVER),
-            array_key_exists($key, $_ENV),
-        ];
-
-        $_SERVER[$key] = $value;
-        $_ENV[$key] = $value;
-        putenv("{$key}={$value}");
-    }
-
-    try {
+    $changes = array_map(
+        static fn (string $value): RawEnvChannels => RawEnvChannels::sameOnAllSurfaces($value),
+        $overrides,
+    );
+
+    return RawEnvSnapshot::with($changes, static function (): array {
         /** @var array<string, mixed> $config */
         $config = require base_path('config/fortify.php');
 
         return $config;
-    } finally {
-        foreach ($saved as $key => [$server, $env, $raw, $hadServer, $hadEnv]) {
-            if ($hadServer) {
-                $_SERVER[$key] = $server;
-            } else {
-                unset($_SERVER[$key]);
-            }
-
-            if ($hadEnv) {
-                $_ENV[$key] = $env;
-            } else {
-                unset($_ENV[$key]);
-            }
-
-            if ($raw === false) {
-                putenv($key);
-            } else {
-                putenv("{$key}={$raw}");
-            }
-        }
-    }
+    });
 }
 
 test('宣言経路が正規形へ寄せる (末尾スラッシュと既定 port と大文字)', function (): void {
diff --git a/tests/Feature/Config/ConfigHardeningTest.php b/tests/Feature/Config/ConfigHardeningTest.php
index 549a631a..5ff5c7f6 100644
--- a/tests/Feature/Config/ConfigHardeningTest.php
+++ b/tests/Feature/Config/ConfigHardeningTest.php
@@ -2,62 +2,46 @@
 
 declare(strict_types=1);
 
+use Tests\Support\RawEnv\RawEnvChannels;
+use Tests\Support\RawEnv\RawEnvSnapshot;
+
 /*
  * config 横断ハードニングの不変条件を固定する。
  *
  * env デフォルト分岐 ('fail-close' 等) は config() では検査できない
- * (phpunit.xml / .env の値が挿さるため)。$_SERVER / $_ENV / putenv を直接退避→復元して
+ * (phpunit.xml / .env の値が挿さるため)。3 面 ($_SERVER / $_ENV / putenv) を退避→差し替え→復元して
  * config ファイルを再評価する (Laravel の env() は ServerConst / EnvConst / Putenv の
  * 3 adapter を live に読むため、いずれか 1 つでも残ると .env.testing 値が漏れる)。
+ * 3 面の操作そのものは Tests\Support\RawEnv\RawEnvSnapshot が担う。
  */
 
 /**
  * 指定の env 変数を差し替えて config ファイルを再評価する。
  *
- * @param  array<string, string|null>  $env  null は unset
+ * ★退避・復元は `Tests\Support\RawEnv\RawEnvSnapshot` が持つ。ここは呼び出し側であって、
+ *   同じ処理を書き直さない (家系の正典 raw-env-snapshot-restore v1 の i1)。
+ *   部品は存在と値を別に持つので、「存在するが値が null」を「存在しない」へ潰さない。
+ *
+ * @param  array<string, string|null>  $env  null は 3 面とも明示的に未設定にする
  * @return array<string, mixed>
  */
 function evaluateConfigFileWithEnv(string $configFile, array $env): array
 {
-    $previous = [];
+    $changes = [];
+
     foreach ($env as $key => $value) {
-        $getenv = getenv($key);
-        $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, $getenv === false ? null : $getenv];
-        if ($value === null) {
-            unset($_SERVER[$key], $_ENV[$key]);
-            putenv($key);
-        } else {
-            $_SERVER[$key] = $value;
-            $_ENV[$key] = $value;
-            putenv("{$key}={$value}");
-        }
+        $changes[$key] = $value === null
+            ? RawEnvChannels::none()
+            : RawEnvChannels::sameOnAllSurfaces($value);
     }
 
-    try {
+    return RawEnvSnapshot::with($changes, function () use ($configFile): array {
         $config = require base_path("config/{$configFile}");
         expect($config)->toBeArray();
 
         /** @var array<string, mixed> $config */
         return $config;
-    } finally {
-        foreach ($previous as $key => [$serverValue, $envValue, $putenvValue]) {
-            if ($serverValue === null) {
-                unset($_SERVER[$key]);
-            } else {
-                $_SERVER[$key] = $serverValue;
-            }
-            if ($envValue === null) {
-                unset($_ENV[$key]);
-            } else {
-                $_ENV[$key] = $envValue;
-            }
-            if ($putenvValue === null) {
-                putenv($key);
-            } else {
-                putenv("{$key}={$putenvValue}");
-            }
-        }
-    }
+    });
 }
 
 // ========== session: secure cookie の production fail-close ==========
diff --git a/tests/Feature/Support/ProductionEnvGuardTest.php b/tests/Feature/Support/ProductionEnvGuardTest.php
index a9be5dbd..58bea0bc 100644
--- a/tests/Feature/Support/ProductionEnvGuardTest.php
+++ b/tests/Feature/Support/ProductionEnvGuardTest.php
@@ -5,13 +5,17 @@
 use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use App\Support\ProductionEnvGuard;
 use Laravel\Fortify\Features;
+use Tests\Support\RawEnv\RawEnvChannels;
+use Tests\Support\RawEnv\RawEnvSnapshot;
 
 beforeEach(function (): void {
     // ★実環境変数の二重判定 (T177) が入ったため、**テストの前提として 3 変数 × 3 経路を
     //   すべて未設定にする**。開発者の手元シェルや実行基盤に TESTING_FAKE_* が残っていると、
     //   本ファイルのほぼ全ケースが余分な violation で落ちる (ホスト環境依存になる)。
     //   原状復帰は afterEach が行う。
-    productionEnvGuardIsolateRawEnvironment();
+    productionEnvGuardStoreRawSnapshot(
+        RawEnvSnapshot::captureAndClear(productionEnvGuardFakeFlagVariables()),
+    );
 
     // production 必須項目の baseline (すべて有効値)。各テストで 1 項目ずつ崩す。
     config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
@@ -41,7 +45,7 @@
 });
 
 afterEach(function (): void {
-    productionEnvGuardRestoreRawEnvironment();
+    productionEnvGuardTakeRawSnapshot()?->restore();
 });
 
 test('全 production 必須項目が埋まっていれば violations は空', function (): void {
@@ -354,11 +358,12 @@
  * キャッシュが失われた起動で環境変数が読み直されて本番で偽物が立ちうる。
  * そこで設定値とは独立に $_SERVER / $_ENV / getenv() の 3 経路を見る。
  *
- * ★原値の退避と復元は下のヘルパへ集約し、すべてのケースが try/finally で戻す
- *   (putenv は空文字と未設定の差が環境で揺れるため、$_SERVER / $_ENV 側は
- *    unset() と = '' を明示的に作り分ける)。
- * ★**指定しなかった経路はテスト中だけ明示的に未設定化する**。実行環境に同じ変数が
- *   残っていると「経路ごとに独立に検査する」という前提が崩れ、違反件数がホスト依存になる。
+ * ★原値の退避と復元は `Tests\Support\RawEnv\RawEnvSnapshot` が担う (本ファイルは
+ *   3 面を直接触らない。正典 v1 の i1 = 1 つの部品への集約)。
+ *   putenv は空文字と未設定の差が環境で揺れるため、部品側が存在と値を別に持って戻す。
+ * ★**指定しなかった経路はテスト中だけ明示的に未設定化する** (`RawEnvChannels::none()` を
+ *   起点に指定した面だけを足す)。実行環境に同じ変数が残っていると
+ *   「経路ごとに独立に検査する」という前提が崩れ、違反件数がホスト依存になる。
  */
 
 /**
@@ -372,202 +377,109 @@ function productionEnvGuardFakeFlagVariables(): array
 }
 
 /**
- * 3 経路の原値を退避する。
+ * フックをまたぐ退避の預け入れ / 取り出し (Pest の TestCase へ動的プロパティを生やさない)。
  *
- * @return array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}
+ * ★`take()` は**必ず保存スロットを空にして**返す。`beforeEach` が退避の途中で失敗したとき、
+ *   `afterEach` が前ケースの退避を再利用する経路を構造的に消すためである。
+ * ★ここが持つのは**部品が作った退避の値**だけで、退避・復元のロジックは持たない
+ *   (3 面の操作は `Tests\Support\RawEnv\RawEnvSnapshot` へ集約してある。正典 v1 の i1)。
  */
-function productionEnvGuardCaptureRaw(string $variable): array
+function productionEnvGuardStoreRawSnapshot(RawEnvSnapshot $snapshot): void
 {
-    return [
-        'hadServer' => array_key_exists($variable, $_SERVER),
-        'server' => $_SERVER[$variable] ?? null,
-        'hadEnv' => array_key_exists($variable, $_ENV),
-        'env' => $_ENV[$variable] ?? null,
-        'putenv' => getenv($variable),
-    ];
+    productionEnvGuardRawSnapshotSlot($snapshot, false);
 }
 
-/**
- * 退避した原値へ 3 経路を戻す。
- *
- * @param  array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}  $state
- */
-function productionEnvGuardRestoreRaw(string $variable, array $state): void
+function productionEnvGuardTakeRawSnapshot(): ?RawEnvSnapshot
 {
-    if ($state['hadServer']) {
-        $_SERVER[$variable] = $state['server'];
-    } else {
-        unset($_SERVER[$variable]);
-    }
-
-    if ($state['hadEnv']) {
-        $_ENV[$variable] = $state['env'];
-    } else {
-        unset($_ENV[$variable]);
-    }
-
-    if ($state['putenv'] === false) {
-        putenv($variable);
-    } else {
-        putenv("{$variable}={$state['putenv']}");
-    }
+    return productionEnvGuardRawSnapshotSlot(null, true);
 }
 
-/** 3 経路をすべて未設定にする */
-function productionEnvGuardClearRaw(string $variable): void
+function productionEnvGuardRawSnapshotSlot(?RawEnvSnapshot $store, bool $take): ?RawEnvSnapshot
 {
-    unset($_SERVER[$variable], $_ENV[$variable]);
-    putenv($variable);
-}
+    /** @var ?RawEnvSnapshot $slot */
+    static $slot = null;
 
-/**
- * ケース間で共有する退避先 (Pest の TestCase へ動的プロパティを生やさない)。
- *
- * @param  array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}>|null  $set
- * @return array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}>
- */
-function productionEnvGuardRawSnapshot(?array $set = null): array
-{
-    /** @var array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}> $snapshot */
-    static $snapshot = [];
+    if ($store !== null) {
+        $slot = $store;
 
-    if ($set !== null) {
-        $snapshot = $set;
+        return null;
     }
 
-    return $snapshot;
-}
-
-/** テストの前提として対象変数の 3 経路をすべて未設定にする (原値は退避する) */
-function productionEnvGuardIsolateRawEnvironment(): void
-{
-    $snapshot = [];
-    foreach (productionEnvGuardFakeFlagVariables() as $variable) {
-        $snapshot[$variable] = productionEnvGuardCaptureRaw($variable);
-        productionEnvGuardClearRaw($variable);
+    if (! $take) {
+        return $slot;
     }
 
-    productionEnvGuardRawSnapshot($snapshot);
-}
-
-/** 退避しておいた原値へ戻す */
-function productionEnvGuardRestoreRawEnvironment(): void
-{
-    foreach (productionEnvGuardRawSnapshot() as $variable => $state) {
-        productionEnvGuardRestoreRaw($variable, $state);
-    }
-}
+    $taken = $slot;
+    $slot = null;
 
-/**
- * 指定した経路にだけ値を置き、**それ以外の経路は未設定にした状態で** callback を実行する。
- *
- * `$_SERVER` / `$_ENV` は mixed を持ちうるので値の型を絞らない
- * (非文字列を入れるケースも同じ復元経路に乗せる = 復元漏れを作らない)。
- *
- * @param  array{server?: mixed, env?: mixed, putenv?: string}  $values  設定する経路と値
- */
-function withRawEnvironmentValue(string $variable, array $values, Closure $callback): void
-{
-    $state = productionEnvGuardCaptureRaw($variable);
-    $hadServer = $state['hadServer'];
-    $hadEnv = $state['hadEnv'];
-    $originalServer = $state['server'];
-    $originalEnv = $state['env'];
-    $originalPutenv = $state['putenv'];
-
-    try {
-        // 指定されなかった経路は未設定にする (経路ごとの独立検査の前提を作る)。
-        if (array_key_exists('server', $values)) {
-            $_SERVER[$variable] = $values['server'];
-        } else {
-            unset($_SERVER[$variable]);
-        }
-
-        if (array_key_exists('env', $values)) {
-            $_ENV[$variable] = $values['env'];
-        } else {
-            unset($_ENV[$variable]);
-        }
-
-        if (array_key_exists('putenv', $values)) {
-            putenv("{$variable}={$values['putenv']}");
-        } else {
-            putenv($variable);
-        }
-
-        $callback();
-    } finally {
-        if ($hadServer) {
-            $_SERVER[$variable] = $originalServer;
-        } else {
-            unset($_SERVER[$variable]);
-        }
-
-        if ($hadEnv) {
-            $_ENV[$variable] = $originalEnv;
-        } else {
-            unset($_ENV[$variable]);
-        }
-
-        if ($originalPutenv === false) {
-            putenv($variable);
-        } else {
-            putenv("{$variable}={$originalPutenv}");
-        }
-    }
+    return $taken;
 }
 
 test('config が false でも $_SERVER に true が残っていれば violation', function (): void {
-    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => 'true'], function (): void {
-        $errors = (new ProductionEnvGuard)->violations();
-        expect($errors)->toHaveCount(1);
-        expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
-        expect($errors[0])->toContain('$_SERVER');
-    });
+    RawEnvSnapshot::with(
+        ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()->withServer('true')],
+        function (): void {
+            $errors = (new ProductionEnvGuard)->violations();
+            expect($errors)->toHaveCount(1);
+            expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
+            expect($errors[0])->toContain('$_SERVER');
+        });
 });
 
 test('config が false でも $_ENV に true が残っていれば violation', function (): void {
-    withRawEnvironmentValue('TESTING_FAKE_LLM', ['env' => 'true'], function (): void {
-        $errors = (new ProductionEnvGuard)->violations();
-        expect($errors)->toHaveCount(1);
-        expect($errors[0])->toContain('$_ENV');
-    });
+    RawEnvSnapshot::with(
+        ['TESTING_FAKE_LLM' => RawEnvChannels::none()->withEnv('true')],
+        function (): void {
+            $errors = (new ProductionEnvGuard)->violations();
+            expect($errors)->toHaveCount(1);
+            expect($errors[0])->toContain('$_ENV');
+        });
 });
 
 test('config が false でも getenv() に true が残っていれば violation', function (): void {
-    withRawEnvironmentValue('TESTING_FAKE_STORAGE', ['putenv' => 'true'], function (): void {
-        $errors = (new ProductionEnvGuard)->violations();
-        expect($errors)->toHaveCount(1);
-        expect($errors[0])->toContain('getenv()');
-    });
+    RawEnvSnapshot::with(
+        ['TESTING_FAKE_STORAGE' => RawEnvChannels::none()->withProcess('true')],
+        function (): void {
+            $errors = (new ProductionEnvGuard)->violations();
+            expect($errors)->toHaveCount(1);
+            expect($errors[0])->toContain('getenv()');
+        });
 });
 
 test('3 経路とも未設定なら violation は出ない', function (): void {
     // beforeEach が 3 変数 × 3 経路を未設定にしている。ここでは明示的に 1 変数を
     // 「どの経路も指定しない」形で通し、未設定が判定対象にならないことを固定する。
-    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', [], function (): void {
-        expect((new ProductionEnvGuard)->violations())->toBe([]);
-    });
+    RawEnvSnapshot::with(
+        ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()],
+        function (): void {
+            expect((new ProductionEnvGuard)->violations())->toBe([]);
+        });
 });
 
 test('無効と読める値 (false / 0 / 空文字) では violation は出ない', function (): void {
     foreach (['false', 'FALSE', '(false)', '0', 'off', 'no', 'null', ''] as $value) {
-        withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => $value], function () use ($value): void {
-            expect((new ProductionEnvGuard)->violations())->toBe([], "無効と読めるはずの値: '{$value}'");
-        });
+        RawEnvSnapshot::with(
+            ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()->withServer($value)],
+            function () use ($value): void {
+                expect((new ProductionEnvGuard)->violations())->toBe([], "無効と読めるはずの値: '{$value}'");
+            });
     }
 });
 
 test('解釈できない値 (maybe / 非文字列) は安全側で violation', function (): void {
-    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => 'maybe'], function (): void {
-        expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
-    });
+    RawEnvSnapshot::with(
+        ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()->withServer('maybe')],
+        function (): void {
+            expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
+        });
 
     // 非文字列 (配列) も黙って捨てず違反にする。
-    // ★退避と復元は同じヘルパに乗せる (原値があった場合の戻し漏れを作らない)。
-    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => ['true']], function (): void {
-        expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
-    });
+    // ★退避と復元は同じ部品に乗せる (原値があった場合の戻し漏れを作らない)。
+    RawEnvSnapshot::with(
+        ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()->withServer(['true'])],
+        function (): void {
+            expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
+        });
 });
 
 test('未設定 / 空文字 / false を別ケースとして固定する', function (): void {
@@ -577,12 +489,16 @@ function withRawEnvironmentValue(string $variable, array $values, Closure $callb
     expect((new ProductionEnvGuard)->violations())->toBe([]);
 
     // 空文字: 無効と読む
-    withRawEnvironmentValue($variable, ['server' => ''], function (): void {
-        expect((new ProductionEnvGuard)->violations())->toBe([]);
-    });
+    RawEnvSnapshot::with(
+        [$variable => RawEnvChannels::none()->withServer('')],
+        function (): void {
+            expect((new ProductionEnvGuard)->violations())->toBe([]);
+        });
 
     // 'false': 無効と読む
-    withRawEnvironmentValue($variable, ['server' => 'false'], function (): void {
-        expect((new ProductionEnvGuard)->violations())->toBe([]);
-    });
+    RawEnvSnapshot::with(
+        [$variable => RawEnvChannels::none()->withServer('false')],
+        function (): void {
+            expect((new ProductionEnvGuard)->violations())->toBe([]);
+        });
 });
diff --git a/tests/Support/RawEnv/RawEnvChannels.php b/tests/Support/RawEnv/RawEnvChannels.php
new file mode 100644
index 00000000..a945c242
--- /dev/null
+++ b/tests/Support/RawEnv/RawEnvChannels.php
@@ -0,0 +1,83 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\RawEnv;
+
+/**
+ * 3 面 (`$_SERVER` / `$_ENV` / `putenv`) へ何を入れるかの指定 (不変の値オブジェクト)。
+ *
+ * ★**「指定しなかった」と「値が null」は別物である**。前者は「その面を明示的に未設定にする」
+ *   という意味であり (家系の正典 raw-env-snapshot-restore v1 の i7)、後者は
+ *   「その面に null を入れる」である。したがって面ごとに「指定したか」を値と別に持つ。
+ *   `?null` では表現できない。
+ * ★**値の型を絞らない** (i3)。`$_SERVER` / `$_ENV` は mixed を持ちうるし、
+ *   本リポジトリには非文字列 (配列) を入れて fail-closed を確かめる既存ケースがある。
+ * ★**`putenv` 面だけは `string` に限る**。`putenv()` は文字列しか受け取れないので、
+ *   非文字列がこの面へ到達する経路を型で消す (`sameOnAllSurfaces()` が `string` しか
+ *   受け取らないのはこのためである。非文字列は `withServer()` / `withEnv()` からしか指定できない)。
+ * ★生成は `none()` / `sameOnAllSurfaces()` を起点にした派生だけである
+ *   (配列リテラルを受ける口は公開しない)。
+ */
+final class RawEnvChannels
+{
+    private function __construct(
+        public readonly bool $serverSpecified,
+        public readonly mixed $serverValue,
+        public readonly bool $envSpecified,
+        public readonly mixed $envValue,
+        public readonly bool $processSpecified,
+        public readonly string $processValue,
+    ) {}
+
+    /** 3 面とも未指定 (= 適用すると 3 面とも明示的に未設定になる)。 */
+    public static function none(): self
+    {
+        return new self(false, null, false, null, false, '');
+    }
+
+    /** 3 面そろえて同じ文字列を入れる (最も普通の使い方)。 */
+    public static function sameOnAllSurfaces(string $value): self
+    {
+        return new self(true, $value, true, $value, true, $value);
+    }
+
+    /** `$_SERVER` 面にだけ値を足す (他の面の指定は引き継ぐ)。 */
+    public function withServer(mixed $value): self
+    {
+        return new self(
+            true,
+            $value,
+            $this->envSpecified,
+            $this->envValue,
+            $this->processSpecified,
+            $this->processValue,
+        );
+    }
+
+    /** `$_ENV` 面にだけ値を足す (他の面の指定は引き継ぐ)。 */
+    public function withEnv(mixed $value): self
+    {
+        return new self(
+            $this->serverSpecified,
+            $this->serverValue,
+            true,
+            $value,
+            $this->processSpecified,
+            $this->processValue,
+        );
+    }
+
+    /** `putenv` 面にだけ値を足す (他の面の指定は引き継ぐ)。 */
+    public function withProcess(string $value): self
+    {
+        return new self(
+            $this->serverSpecified,
+            $this->serverValue,
+            $this->envSpecified,
+            $this->envValue,
+            true,
+            $value,
+        );
+    }
+}
diff --git a/tests/Support/RawEnv/RawEnvDirectWriteAllowance.php b/tests/Support/RawEnv/RawEnvDirectWriteAllowance.php
new file mode 100644
index 00000000..50d69b63
--- /dev/null
+++ b/tests/Support/RawEnv/RawEnvDirectWriteAllowance.php
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\RawEnv;
+
+/**
+ * 3 面へ直接書いてよい置き場の**型付きの分類**。
+ *
+ * ★**免除ではなく「1 件の事実」の登録**である。分類を新設するのは
+ *   「部品へ寄せられない構造上の理由」が新たに見つかったときだけで、
+ *   その判断はレビューで必ず見える (`RawEnvDirectWriteGateTest` の G11 が
+ *   パスと分類の対応を完全一致で固定する)。
+ */
+enum RawEnvDirectWriteAllowance: string
+{
+    /** 3 面の退避・注入・復元を担う部品そのもの。 */
+    case ComponentItself = 'component_itself';
+
+    /** 部品の契約テスト (部品を使わずに 3 面の状態を作らないと往復を検査できない)。 */
+    case ComponentContractTest = 'component_contract_test';
+
+    /** 枠組みが立ち上がる前の足場 (autoload された部品を呼べる段階より前に動く)。 */
+    case PreFrameworkBootstrap = 'pre_framework_bootstrap';
+}
diff --git a/tests/Support/RawEnv/RawEnvDirectWriteScanner.php b/tests/Support/RawEnv/RawEnvDirectWriteScanner.php
new file mode 100644
index 00000000..67d4a421
--- /dev/null
+++ b/tests/Support/RawEnv/RawEnvDirectWriteScanner.php
@@ -0,0 +1,673 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\RawEnv;
+
+use Tests\Support\PhpTokenScan;
+
+/**
+ * 生の環境変数 3 面 (`$_SERVER` / `$_ENV` / `putenv`) への**直接の書き込み**を
+ * 字句走査で列挙する純関数 (`RawEnvDirectWriteGateTest` の検出器)。
+ *
+ * 走査は既存の `Tests\Support\PhpTokenScan::normalize()` (空白 / コメント / DocComment を
+ * 除いた添字連番のリスト) の上で行う。**同じ正規化を 2 本持たない**。
+ *
+ * ── 検出する形 ──────────────────────────────────────────────────────
+ *
+ *  | 分類 | 形 |
+ *  |---|---|
+ *  | `element_assign` | 面の要素への代入 (通常 / 複合 / `??=` / 前後置インクリメント / 多段添字) |
+ *  | `element_unset` | 面の要素の削除 (`unset()` の引数の**根**にある面) |
+ *  | `whole_assign` | 面そのものへの代入 (複合代入を含む) |
+ *  | `reference_taken` | 面 / 面の要素への参照の取得 |
+ *  | `destructuring_target` | 分割代入の左辺の**根**に面が現れる形 |
+ *  | `putenv` | プロセス面への書き込み (両形 / 完全修飾 / 別名つき取り込み) |
+ *  | `unresolved` | 上のどれにも分類できなかった出現 (**必ず違反**) |
+ *
+ * ── 関数名の解決 (AGENTS.md 走査器共通規約 (a)) ──────────────────────────
+ *
+ *  `putenv` は**完全修飾名で突き合わせる**。短名一致は使わない (別名つき取り込み 1 つで
+ *  検査が黙るため)。ファイルごとに名前空間宣言と `use function` の取り込み対応表
+ *  (別名・group use を含む) を先に組み立て、
+ *  裸の呼び出し (名前空間の中でもグローバルへ fallback する) / 完全修飾 /
+ *  別名を解いた結果が `\putenv` になる呼び出しを検出する。
+ *  `T_NAME_RELATIVE` (`namespace\putenv`) は**グローバル名前空間のときだけ**一致する。
+ *
+ *  **fail-closed**: `use function` の取り込みを完全修飾名へ解けない形 /
+ *  1 ファイルに `namespace` 宣言が 2 つ以上ある / 波括弧つき `namespace { … }` を使っている /
+ *  そのファイルが自分で `putenv` という名前の関数を宣言している
+ *  → そのファイルの `putenv` 相当の出現をすべて `unresolved` にする。
+ *
+ * ── 保証しないもの (誇張しない。ここが正本) ────────────────────────────────
+ *
+ *  - **可変関数呼び出し** (`$fn = 'putenv'; $fn('K=V');`) と
+ *    **`call_user_func` 等の間接呼び出し** (`call_user_func('putenv', …)`)
+ *  - 名前を実行時に解決する書き込み (可変変数 / `extract()` / 文字列から呼び出す形)
+ *  - 面を**値渡しで受けた関数**が内部で書き換える形 (`foo($_SERVER)` の呼び先)
+ *  - `Dotenv` のような**ライブラリ経由の間接的な書き込み**
+ *  - ヒアドキュメント / ナウドキュメントの本文 (`token_get_all()` からは 1 トークンに見える。
+ *    **実測で確認済み**であり、走査器の自己検査が負例をナウドキュメントで持てる理由でもある)
+ *  - 文字列リテラル・コメントの中の綴り
+ *  - 走査根から外した置き場 (`devnotes/` 配下。除外の管理は gate 側の責務)
+ *
+ *  **したがってこの検出器を使う gate の主張は「部品の外に 3 面への直接の書き込みが 1 件も無い」
+ *  ではなく、「上に列挙した字句の書き込み形が、許可した置き場以外に 1 件も無い」である。**
+ *
+ * > **監視条件**: 可変関数呼び出しや `call_user_func` で 3 面へ書く形が実際に現れたら、
+ * > **目録へ登録するのではなく検出規則を足す**。
+ * > (文字列リテラル `'putenv'` を一律に未解決とする案は採らない — 走査器自身と
+ * >  `RawEnvWriteKind` がその文字列を持つため、許可を増やさない限り設計が自分自身を
+ * >  違反にしてしまう。)
+ *
+ * ★**母集団の非空は契約しない**。空入力でも例外にせず 0 件を返す
+ *   (非空を要求するのは検出器を**使う側**の gate である)。
+ */
+final class RawEnvDirectWriteScanner
+{
+    /** 走査対象の面 (変数として現れる 2 面)。 */
+    private const array SURFACE_VARIABLES = ['$_SERVER', '$_ENV'];
+
+    /** 代入系の演算子 (単一文字の `=` は id が null なので別に見る)。 */
+    private const array ASSIGNMENT_TOKEN_IDS = [
+        T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
+        T_MOD_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL, T_OR_EQUAL, T_AND_EQUAL,
+        T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
+    ];
+
+    /** 呼び出しではない (メソッド / 宣言 / 定数) ことを示す直前のトークン。 */
+    private const array NON_CALL_PREFIX_TOKEN_IDS = [
+        T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST,
+    ];
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * PHP ソース 1 本を走査し、3 面への書き込み (と未解決) をすべて返す。
+     *
+     * @return list<RawEnvWriteSite>
+     */
+    public static function scan(string $phpSource): array
+    {
+        $tokens = PhpTokenScan::normalize($phpSource);
+
+        if ($tokens === []) {
+            return [];
+        }
+
+        $pairs = self::bracketPairs($tokens);
+        $enclosingParen = self::enclosingParens($tokens);
+        $context = self::analyseFileContext($tokens);
+        $destructuring = self::destructuringRanges($tokens, $pairs);
+        $unsetRanges = self::unsetRanges($tokens, $pairs);
+
+        /** @var array<int, RawEnvWriteSite> $sites */
+        $sites = [];
+
+        foreach ($tokens as $index => $token) {
+            if ($token['id'] === T_VARIABLE && in_array($token['text'], self::SURFACE_VARIABLES, true)) {
+                $kind = self::classifySurface($tokens, $pairs, $enclosingParen, $destructuring, $unsetRanges, $index);
+
+                if ($kind !== null) {
+                    $sites[$index] = new RawEnvWriteSite($kind, $token['text'], $token['line']);
+                }
+
+                continue;
+            }
+
+            $kind = self::classifyFunctionCall($tokens, $context, $index);
+
+            if ($kind !== null) {
+                $sites[$index] = new RawEnvWriteSite($kind, $token['text'], $token['line']);
+            }
+        }
+
+        ksort($sites);
+
+        return array_values($sites);
+    }
+
+    /**
+     * ファイル全体の名前解決の文脈 (名前空間 / `use function` の対応表 / 解決不能の宣言)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{namespace: string, aliases: array<string, string>, unresolved: bool}
+     */
+    private static function analyseFileContext(array $tokens): array
+    {
+        $count = count($tokens);
+        $namespaces = [];
+        $braced = false;
+
+        foreach ($tokens as $index => $token) {
+            if ($token['id'] !== T_NAMESPACE) {
+                continue;
+            }
+
+            $cursor = $index + 1;
+            $name = '';
+
+            while ($cursor < $count && self::isNamePart($tokens[$cursor])) {
+                $name .= $tokens[$cursor]['text'];
+                $cursor++;
+            }
+
+            $namespaces[] = trim($name, '\\');
+
+            if ($cursor < $count && $tokens[$cursor]['id'] === null && $tokens[$cursor]['text'] === '{') {
+                $braced = true;
+            }
+        }
+
+        $unresolved = count($namespaces) >= 2 || $braced;
+        $namespace = $namespaces === [] ? '' : $namespaces[0];
+
+        $aliases = [];
+
+        for ($i = 0; $i + 1 < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_USE || $tokens[$i + 1]['id'] !== T_FUNCTION) {
+                continue;
+            }
+
+            $statement = [];
+
+            for ($j = $i + 2; $j < $count; $j++) {
+                if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ';') {
+                    break;
+                }
+
+                $statement[] = $tokens[$j];
+            }
+
+            if (! self::collectFunctionImports($statement, $aliases)) {
+                $unresolved = true;
+            }
+        }
+
+        // そのファイル自身が `putenv` という名前の関数を宣言していたら、非修飾の呼び出しは
+        // ローカル関数を指しうるので解決できない (fail-closed)。
+        for ($i = 0; $i + 1 < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_FUNCTION) {
+                continue;
+            }
+
+            if ($i > 0 && $tokens[$i - 1]['id'] === T_USE) {
+                continue;
+            }
+
+            if ($tokens[$i + 1]['id'] === T_STRING && strtolower($tokens[$i + 1]['text']) === 'putenv') {
+                $unresolved = true;
+            }
+        }
+
+        return ['namespace' => $namespace, 'aliases' => $aliases, 'unresolved' => $unresolved];
+    }
+
+    /**
+     * `use function …;` 1 文を対応表へ展開する (解けなければ false)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $statement
+     * @param  array<string, string>  $aliases
+     */
+    private static function collectFunctionImports(array $statement, array &$aliases): bool
+    {
+        $prefix = '';
+        $body = $statement;
+
+        foreach ($statement as $position => $token) {
+            if ($token['id'] === null && $token['text'] === '{') {
+                $prefix = '';
+
+                foreach (array_slice($statement, 0, $position) as $prefixToken) {
+                    $prefix .= $prefixToken['text'];
+                }
+
+                $body = [];
+
+                foreach (array_slice($statement, $position + 1) as $bodyToken) {
+                    if ($bodyToken['id'] === null && $bodyToken['text'] === '}') {
+                        break;
+                    }
+
+                    $body[] = $bodyToken;
+                }
+
+                break;
+            }
+        }
+
+        $entries = [[]];
+
+        foreach ($body as $token) {
+            if ($token['id'] === null && $token['text'] === ',') {
+                $entries[] = [];
+
+                continue;
+            }
+
+            $entries[count($entries) - 1][] = $token;
+        }
+
+        $resolved = true;
+
+        foreach ($entries as $entry) {
+            if ($entry === []) {
+                continue;
+            }
+
+            $alias = null;
+            $nameTokens = $entry;
+            $entryCount = count($entry);
+
+            if ($entryCount >= 3 && $entry[$entryCount - 2]['id'] === T_AS) {
+                $alias = $entry[$entryCount - 1]['text'];
+                $nameTokens = array_slice($entry, 0, $entryCount - 2);
+            }
+
+            $name = '';
+
+            foreach ($nameTokens as $nameToken) {
+                if (! self::isNamePart($nameToken)) {
+                    $resolved = false;
+
+                    break 2;
+                }
+
+                $name .= $nameToken['text'];
+            }
+
+            $fullyQualified = trim($prefix.$name, '\\');
+
+            if ($fullyQualified === '') {
+                $resolved = false;
+
+                break;
+            }
+
+            $segments = explode('\\', $fullyQualified);
+            $alias ??= $segments[count($segments) - 1];
+            $aliases[strtolower($alias)] = $fullyQualified;
+        }
+
+        return $resolved;
+    }
+
+    /**
+     * 関数呼び出しの位置が `\putenv` を指すか (指せないなら未解決)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array{namespace: string, aliases: array<string, string>, unresolved: bool}  $context
+     */
+    private static function classifyFunctionCall(array $tokens, array $context, int $index): ?RawEnvWriteKind
+    {
+        $token = $tokens[$index];
+
+        if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
+            return null;
+        }
+
+        $next = $tokens[$index + 1] ?? null;
+
+        if ($next === null || $next['id'] !== null || $next['text'] !== '(') {
+            return null;
+        }
+
+        $previous = $index > 0 ? $tokens[$index - 1] : null;
+
+        if ($previous !== null && in_array($previous['id'], self::NON_CALL_PREFIX_TOKEN_IDS, true)) {
+            return null;
+        }
+
+        $text = $token['text'];
+        $lowered = strtolower($text);
+        $segments = explode('\\', trim($lowered, '\\'));
+        $lastSegment = $segments[count($segments) - 1];
+
+        $isAliasOfPutenv = $token['id'] === T_STRING
+            && isset($context['aliases'][$lowered])
+            && strtolower($context['aliases'][$lowered]) === 'putenv';
+
+        // `putenv` 相当の綴りを持つ呼び出しかどうか (未解決の判定にも使う母集団)。
+        $isCandidate = $lastSegment === 'putenv' || $isAliasOfPutenv;
+
+        if (! $isCandidate) {
+            return null;
+        }
+
+        if ($context['unresolved']) {
+            return RawEnvWriteKind::Unresolved;
+        }
+
+        return match ($token['id']) {
+            T_NAME_FULLY_QUALIFIED => trim($lowered, '\\') === 'putenv' ? RawEnvWriteKind::Putenv : null,
+            T_NAME_RELATIVE => $context['namespace'] === '' ? RawEnvWriteKind::Putenv : null,
+            T_NAME_QUALIFIED => null,
+            default => self::classifyUnqualifiedCall($context, $lowered),
+        };
+    }
+
+    /**
+     * 非修飾の呼び出しを取り込み対応表とグローバル fallback で解決する。
+     *
+     * @param  array{namespace: string, aliases: array<string, string>, unresolved: bool}  $context
+     */
+    private static function classifyUnqualifiedCall(array $context, string $lowered): ?RawEnvWriteKind
+    {
+        if (isset($context['aliases'][$lowered])) {
+            return strtolower($context['aliases'][$lowered]) === 'putenv' ? RawEnvWriteKind::Putenv : null;
+        }
+
+        // 名前空間の中でも、非修飾の関数呼び出しはグローバルへ fallback する。
+        return $lowered === 'putenv' ? RawEnvWriteKind::Putenv : null;
+    }
+
+    /**
+     * 面の変数 1 件を分類する (読み出しなら null)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<int, int>  $pairs
+     * @param  array<int, int>  $enclosingParen
+     * @param  list<array{int, int}>  $destructuring
+     * @param  list<array{int, int}>  $unsetRanges
+     */
+    private static function classifySurface(
+        array $tokens,
+        array $pairs,
+        array $enclosingParen,
+        array $destructuring,
+        array $unsetRanges,
+        int $index,
+    ): ?RawEnvWriteKind {
+        $previous = $index > 0 ? $tokens[$index - 1] : null;
+        $next = $tokens[$index + 1] ?? null;
+        // 分割代入のパターンでは `[` も要素の先頭になるが、引数リストでは `(` / `,` だけである。
+        $atElementHead = $previous !== null
+            && $previous['id'] === null
+            && in_array($previous['text'], ['[', '(', ','], true);
+        $atArgumentHead = $previous !== null
+            && $previous['id'] === null
+            && in_array($previous['text'], ['(', ','], true);
+
+        foreach ($destructuring as $range) {
+            if ($index <= $range[0] || $index >= $range[1]) {
+                continue;
+            }
+
+            // 範囲に入っただけでは書き込みにしない。lvalue の根にあるときだけ対象にする。
+            if ($atElementHead && ! self::isInsideIndexBracket($tokens, $pairs, $range, $index)) {
+                return RawEnvWriteKind::DestructuringTarget;
+            }
+
+            return null;
+        }
+
+        if ($previous !== null
+            && ($previous['id'] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
+                || ($previous['id'] === null && $previous['text'] === '&'))
+        ) {
+            return RawEnvWriteKind::ReferenceTaken;
+        }
+
+        foreach ($unsetRanges as $range) {
+            if ($index > $range[0] && $index < $range[1]
+                && $atArgumentHead
+                && ($enclosingParen[$index] ?? null) === $range[0]
+            ) {
+                return RawEnvWriteKind::ElementUnset;
+            }
+        }
+
+        if ($next !== null && $next['id'] === null && $next['text'] === '[') {
+            $cursor = $index + 1;
+
+            while (isset($tokens[$cursor]) && $tokens[$cursor]['id'] === null && $tokens[$cursor]['text'] === '[') {
+                if (! isset($pairs[$cursor])) {
+                    return RawEnvWriteKind::Unresolved;
+                }
+
+                $cursor = $pairs[$cursor] + 1;
+            }
+
+            $after = $tokens[$cursor] ?? null;
+
+            if ($after !== null && (self::isAssignmentOperator($after) || in_array($after['id'], [T_INC, T_DEC], true))) {
+                return RawEnvWriteKind::ElementAssign;
+            }
+
+            if ($previous !== null && in_array($previous['id'], [T_INC, T_DEC], true)) {
+                return RawEnvWriteKind::ElementAssign;
+            }
+
+            return null;
+        }
+
+        if ($next !== null && self::isAssignmentOperator($next)) {
+            return RawEnvWriteKind::WholeAssign;
+        }
+
+        if ($next !== null && $next['id'] === T_AS) {
+            return null;
+        }
+
+        if ($atArgumentHead
+            && $next !== null
+            && $next['id'] === null
+            && in_array($next['text'], [')', ','], true)
+        ) {
+            return null;
+        }
+
+        return RawEnvWriteKind::Unresolved;
+    }
+
+    /**
+     * 面が分割代入の範囲の中で「添字の括弧」に囲まれているか (囲まれていれば読み出し)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<int, int>  $pairs
+     * @param  array{int, int}  $range
+     */
+    private static function isInsideIndexBracket(array $tokens, array $pairs, array $range, int $index): bool
+    {
+        for ($i = $range[0] + 1; $i < $index; $i++) {
+            if (! self::isIndexBracket($tokens, $i)) {
+                continue;
+            }
+
+            $close = $pairs[$i] ?? null;
+
+            if ($close !== null && $close > $index) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 分割代入の対象範囲 (パターンの括弧 … その直後が代入記号)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<int, int>  $pairs
+     * @return list<array{int, int}>
+     */
+    private static function destructuringRanges(array $tokens, array $pairs): array
+    {
+        $ranges = [];
+
+        foreach ($tokens as $index => $token) {
+            $open = null;
+
+            if ($token['id'] === null && $token['text'] === '[' && ! self::isIndexBracket($tokens, $index)) {
+                $open = $index;
+            } elseif ($token['id'] === T_LIST) {
+                $candidate = $index + 1;
+
+                if (isset($tokens[$candidate]) && $tokens[$candidate]['id'] === null && $tokens[$candidate]['text'] === '(') {
+                    $open = $candidate;
+                }
+            }
+
+            if ($open === null || ! isset($pairs[$open])) {
+                continue;
+            }
+
+            $after = $tokens[$pairs[$open] + 1] ?? null;
+
+            if ($after !== null && $after['id'] === null && $after['text'] === '=') {
+                $ranges[] = [$open, $pairs[$open]];
+            }
+        }
+
+        return $ranges;
+    }
+
+    /**
+     * `unset(` の引数リストの範囲。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<int, int>  $pairs
+     * @return list<array{int, int}>
+     */
+    private static function unsetRanges(array $tokens, array $pairs): array
+    {
+        $ranges = [];
+
+        foreach ($tokens as $index => $token) {
+            if ($token['id'] !== T_UNSET) {
+                continue;
+            }
+
+            $open = $index + 1;
+
+            if (isset($tokens[$open], $pairs[$open]) && $tokens[$open]['id'] === null && $tokens[$open]['text'] === '(') {
+                $ranges[] = [$open, $pairs[$open]];
+            }
+        }
+
+        return $ranges;
+    }
+
+    /**
+     * 丸括弧・角括弧の対応表 (開きの添字 => 閉じの添字)。対応の取れない出現は載せない。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array<int, int>
+     */
+    private static function bracketPairs(array $tokens): array
+    {
+        /** @var list<array{char: string, index: int}> $stack */
+        $stack = [];
+        $pairs = [];
+
+        foreach ($tokens as $index => $token) {
+            if ($token['id'] === T_ATTRIBUTE) {
+                $stack[] = ['char' => ']', 'index' => $index];
+
+                continue;
+            }
+
+            if ($token['id'] !== null) {
+                continue;
+            }
+
+            if ($token['text'] === '(') {
+                $stack[] = ['char' => ')', 'index' => $index];
+            } elseif ($token['text'] === '[') {
+                $stack[] = ['char' => ']', 'index' => $index];
+            } elseif (in_array($token['text'], [')', ']'], true)) {
+                $top = array_pop($stack);
+
+                if ($top !== null && $top['char'] === $token['text']) {
+                    $pairs[$top['index']] = $index;
+                }
+            }
+        }
+
+        return $pairs;
+    }
+
+    /**
+     * 各トークンを直接囲んでいる開き丸括弧の添字。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array<int, int>
+     */
+    private static function enclosingParens(array $tokens): array
+    {
+        /** @var list<int> $stack */
+        $stack = [];
+        $enclosing = [];
+
+        foreach ($tokens as $index => $token) {
+            if ($token['id'] === null && $token['text'] === ')') {
+                array_pop($stack);
+            }
+
+            if ($stack !== []) {
+                $enclosing[$index] = $stack[count($stack) - 1];
+            }
+
+            if ($token['id'] === null && $token['text'] === '(') {
+                $stack[] = $index;
+            }
+        }
+
+        return $enclosing;
+    }
+
+    /**
+     * その `[` が「添字の括弧」か (直前が変数 / `]` / `)` なら添字、それ以外はパターン)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function isIndexBracket(array $tokens, int $index): bool
+    {
+        $token = $tokens[$index];
+
+        if ($token['id'] !== null || $token['text'] !== '[') {
+            return false;
+        }
+
+        $previous = $index > 0 ? $tokens[$index - 1] : null;
+
+        if ($previous === null) {
+            return false;
+        }
+
+        if ($previous['id'] === T_VARIABLE || $previous['id'] === T_STRING) {
+            return true;
+        }
+
+        return $previous['id'] === null && in_array($previous['text'], [']', ')'], true);
+    }
+
+    /**
+     * 代入系の演算子か (単一文字の `=` は id が null なので別に見る)。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function isAssignmentOperator(array $token): bool
+    {
+        if ($token['id'] === null) {
+            return $token['text'] === '=';
+        }
+
+        return in_array($token['id'], self::ASSIGNMENT_TOKEN_IDS, true);
+    }
+
+    /**
+     * 名前の一部として扱うトークンか。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function isNamePart(array $token): bool
+    {
+        return in_array(
+            $token['id'],
+            [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR],
+            true,
+        );
+    }
+}
diff --git a/tests/Support/RawEnv/RawEnvGuardStructure.php b/tests/Support/RawEnv/RawEnvGuardStructure.php
new file mode 100644
index 00000000..1c7ed708
--- /dev/null
+++ b/tests/Support/RawEnv/RawEnvGuardStructure.php
@@ -0,0 +1,783 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\RawEnv;
+
+use InvalidArgumentException;
+use ReflectionMethod;
+use RuntimeException;
+use Tests\Support\PhpTokenScan;
+
+/**
+ * メソッド本体の**構造**を字句の位置関係だけで判定する純関数の走査器。
+ *
+ * 家系の正典 raw-env-snapshot-restore v1 の未決論点 q2 —
+ * 「適用の途中で `putenv()` が失敗したときの巻き戻り」と
+ * 「復元が最初の失敗で止まらないこと」は**動的には作れない**
+ * (検証を通ったキーで `putenv()` を失敗させる状況をテストから作れず、
+ *  失敗を注入する差し替え口を新設すると「本番では誰も使わない差し替え口」が増える) —
+ * を、**構造の固定**で代えるために置く。
+ *
+ * ★**この判定は意図的に脆い**。`RawEnvSnapshot` の中身を書き換えると赤くなるのが正しい挙動である
+ *   (「適用が try の外へ出ていないか」を人手のレビューに委ねないための pin)。
+ *   赤くなったときは判定を緩めるのではなく、**構造が本当に変わってよいのか**を確認すること。
+ * ★判定はトークン位置の比較だけで行い、行番号・インデント・整形 (Pint) には依存させない。
+ *   構文解析ライブラリ (nikic/php-parser) は vendor に推移依存としてしか存在しないため使わない。
+ *
+ * ── 走査対象 ────────────────────────────────────────────────────────
+ *
+ *  `ReflectionMethod` の開始行〜終了行で切り出した断片を `<?php` を前置してから
+ *  `Tests\Support\PhpTokenScan::normalize()` にかけたトークン列
+ *  (空白・コメント・DocComment を除いた添字連番のリスト)。
+ *  切り出した断片は `public static function …` から始まり PHP 開始タグを持たないため、
+ *  前置しないと `T_INLINE_HTML` になる。同じ正規化を 2 本持たない。
+ *
+ * ── 保証しないもの (誇張しない) ─────────────────────────────────────────
+ *
+ *  - **メソッド本体の外にある構造**は見ない (呼び出し元・親クラス・trait)。
+ *  - **呼び出し先の実装**は見ない (`self::apply()` の中で何が起きるかは対象外)。
+ *  - **実行時に本当に巻き戻ることそのもの**は検査していない。それは動的には検査できず、
+ *    だからこの走査がある。「構造がこの形である」以上のことを主張しない。
+ *  - `if` の本体が波括弧で囲まれていない形 (`if (…) $x = 1;`) は**受理せず例外**にする
+ *    (本リポジトリは Pint が波括弧を強制するため母集団に現れない)。
+ *  - **母集団の非空は契約しない**。候補が 0 件でも例外にせず空を返す
+ *    (非空を要求するのは検出器を**使う側**の gate / 契約テストである)。
+ */
+final class RawEnvGuardStructure
+{
+    /** 制御フローとして受け付ける token id (これ以外は fail-closed で例外)。 */
+    private const array CONTROL_FLOW_TOKEN_IDS = [T_THROW, T_RETURN, T_BREAK, T_CONTINUE];
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * メソッド本体のトークン列を返す (fail-closed: メソッドが無い / 読めなければ例外)。
+     *
+     * @param  class-string  $class
+     * @return list<array{id: int|null, text: string, line: int}>
+     */
+    public static function methodTokens(string $class, string $method): array
+    {
+        if (! method_exists($class, $method)) {
+            throw new RuntimeException("method not found: {$class}::{$method}()");
+        }
+
+        $reflection = new ReflectionMethod($class, $method);
+        $file = $reflection->getFileName();
+        $start = $reflection->getStartLine();
+        $end = $reflection->getEndLine();
+
+        if ($file === false || $start === false || $end === false) {
+            throw new RuntimeException("method source is not available: {$class}::{$method}()");
+        }
+
+        $lines = file($file);
+
+        if ($lines === false) {
+            throw new RuntimeException("method source file is not readable: {$file}");
+        }
+
+        return self::tokenize(implode('', array_slice($lines, $start - 1, $end - $start + 1)));
+    }
+
+    /**
+     * メソッド本体の断片 (PHP 開始タグを持たない) をトークン列へ正規化する。
+     *
+     * @return list<array{id: int|null, text: string, line: int}>
+     */
+    public static function tokenize(string $methodSource): array
+    {
+        return PhpTokenScan::normalize('<?php '.$methodSource);
+    }
+
+    /**
+     * 指定 token id の出現位置をすべて返す。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<int>
+     */
+    public static function findTokens(array $tokens, int $id): array
+    {
+        $found = [];
+        foreach ($tokens as $index => $token) {
+            if ($token['id'] === $id) {
+                $found[] = $index;
+            }
+        }
+
+        return $found;
+    }
+
+    /**
+     * 指定 token id が**ちょうど 1 件**であることを要求し、その本体の範囲を返す (fail-closed)。
+     *
+     * 「存在する」だけを見ると、`try` の内と外の両方に候補がある状態でも緑になる。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{int, int} [開き波括弧の添字, 閉じ波括弧の添字]
+     */
+    public static function soleBlockRange(array $tokens, int $id): array
+    {
+        $found = self::findTokens($tokens, $id);
+
+        if (count($found) !== 1) {
+            throw new RuntimeException(
+                'expected exactly one occurrence of token id '.$id.', found '.count($found)
+            );
+        }
+
+        return self::blockRange($tokens, $found[0]);
+    }
+
+    /**
+     * キーワードの本体 `{ … }` のトークン範囲を返す (対応が取れなければ例外)。
+     *
+     * 文字列補間の `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` は開き括弧として同列に数える。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{int, int}
+     */
+    public static function blockRange(array $tokens, int $keywordIndex): array
+    {
+        $count = count($tokens);
+        $open = null;
+
+        for ($i = $keywordIndex + 1; $i < $count; $i++) {
+            if (self::isBraceOpen($tokens[$i])) {
+                $open = $i;
+
+                break;
+            }
+        }
+
+        if ($open === null) {
+            throw new RuntimeException('block body not found after token index '.$keywordIndex);
+        }
+
+        $depth = 0;
+
+        for ($i = $open; $i < $count; $i++) {
+            if (self::isBraceOpen($tokens[$i])) {
+                $depth++;
+
+                continue;
+            }
+
+            if ($tokens[$i]['id'] === null && $tokens[$i]['text'] === '}') {
+                $depth--;
+
+                if ($depth === 0) {
+                    return [$open, $i];
+                }
+            }
+        }
+
+        throw new RuntimeException('unbalanced braces starting at token index '.$open);
+    }
+
+    /**
+     * 添字が指定範囲の**内側**にあるか (境界の波括弧そのものは含まない)。
+     *
+     * @param  array{int, int}  $range
+     */
+    public static function isWithin(array $range, int $index): bool
+    {
+        return $index > $range[0] && $index < $range[1];
+    }
+
+    /**
+     * 指定範囲の内側にある添字だけを残す。
+     *
+     * @param  list<int>  $indexes
+     * @param  array{int, int}  $range
+     * @return list<int>
+     */
+    public static function indexesWithin(array $indexes, array $range): array
+    {
+        return array_values(array_filter(
+            $indexes,
+            static fn (int $index): bool => self::isWithin($range, $index),
+        ));
+    }
+
+    /**
+     * `foreach (<式> as …)` の形でその式を**直接**回している foreach の位置。
+     *
+     * ★式は**正規化済みのトークンの綴りの列**で渡す (`['$changes']` / `['$keys']` /
+     *   `['$this', '->', 'state']`)。丸括弧を開いた最初のトークンから綴りが完全一致で連続し、
+     *   次のトークンが `T_AS` であることを見る。
+     *   `foreach (array_values($this->state) as …)` は最初のトークンが `array_values` なので
+     *   候補に入らない (誤検出しない)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $expressionTexts
+     * @return list<int>
+     */
+    public static function foreachOverExpression(array $tokens, array $expressionTexts): array
+    {
+        if ($expressionTexts === []) {
+            throw new InvalidArgumentException('foreach expression must not be empty (fail-closed).');
+        }
+
+        $count = count($tokens);
+        $found = [];
+
+        foreach (self::findTokens($tokens, T_FOREACH) as $index) {
+            $cursor = $index + 1;
+
+            if ($cursor >= $count || $tokens[$cursor]['id'] !== null || $tokens[$cursor]['text'] !== '(') {
+                continue;
+            }
+
+            $cursor++;
+            $matched = true;
+
+            foreach ($expressionTexts as $text) {
+                if ($cursor >= $count || $tokens[$cursor]['text'] !== $text) {
+                    $matched = false;
+
+                    break;
+                }
+
+                $cursor++;
+            }
+
+            if (! $matched || $cursor >= $count || $tokens[$cursor]['id'] !== T_AS) {
+                continue;
+            }
+
+            $found[] = $index;
+        }
+
+        return $found;
+    }
+
+    /**
+     * `$var->method(` の形の呼び出しの**開き丸括弧**の位置。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<int>
+     */
+    public static function methodCalls(array $tokens, string $variable, string $method): array
+    {
+        $count = count($tokens);
+        $found = [];
+
+        for ($i = 0; $i + 3 < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $variable) {
+                continue;
+            }
+
+            if (! in_array($tokens[$i + 1]['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
+                continue;
+            }
+
+            if ($tokens[$i + 2]['id'] !== T_STRING || $tokens[$i + 2]['text'] !== $method) {
+                continue;
+            }
+
+            if ($tokens[$i + 3]['id'] === null && $tokens[$i + 3]['text'] === '(') {
+                $found[] = $i + 3;
+            }
+        }
+
+        return $found;
+    }
+
+    /**
+     * `self::method(` の形の呼び出しの**開き丸括弧**の位置。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<int>
+     */
+    public static function staticCalls(array $tokens, string $method): array
+    {
+        $count = count($tokens);
+        $found = [];
+
+        for ($i = 0; $i + 3 < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_STRING || $tokens[$i]['text'] !== 'self') {
+                continue;
+            }
+
+            if ($tokens[$i + 1]['id'] !== T_DOUBLE_COLON) {
+                continue;
+            }
+
+            if ($tokens[$i + 2]['id'] !== T_STRING || $tokens[$i + 2]['text'] !== $method) {
+                continue;
+            }
+
+            if ($tokens[$i + 3]['id'] === null && $tokens[$i + 3]['text'] === '(') {
+                $found[] = $i + 3;
+            }
+        }
+
+        return $found;
+    }
+
+    /**
+     * `new <クラス名>(` の形の生成の**開き丸括弧**の位置。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<int>
+     */
+    public static function constructions(array $tokens, string $class): array
+    {
+        $count = count($tokens);
+        $found = [];
+
+        for ($i = 0; $i + 2 < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_NEW) {
+                continue;
+            }
+
+            $name = $tokens[$i + 1]['text'];
+
+            if ($name !== $class && ! str_ends_with($name, '\\'.$class)) {
+                continue;
+            }
+
+            if ($tokens[$i + 2]['id'] === null && $tokens[$i + 2]['text'] === '(') {
+                $found[] = $i + 2;
+            }
+        }
+
+        return $found;
+    }
+
+    /**
+     * 制御フローのトークンの出現位置。
+     *
+     * ★受け付けるのは `T_THROW` / `T_RETURN` / `T_BREAK` / `T_CONTINUE` の 4 つだけで、
+     *   それ以外の token id は**例外**にする (fail-closed。指定の綴り間違いで
+     *   「0 件だから合格」になるのを防ぐ)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<int>
+     */
+    public static function controlFlowTokens(array $tokens, int $tokenId): array
+    {
+        if (! in_array($tokenId, self::CONTROL_FLOW_TOKEN_IDS, true)) {
+            throw new InvalidArgumentException(
+                'controlFlowTokens() accepts only T_THROW / T_RETURN / T_BREAK / T_CONTINUE, got '.$tokenId
+            );
+        }
+
+        return self::findTokens($tokens, $tokenId);
+    }
+
+    /**
+     * `$var[] =` の形の追加の位置 (変数トークンの添字)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<int>
+     */
+    public static function variableAppends(array $tokens, string $variable): array
+    {
+        $count = count($tokens);
+        $found = [];
+
+        for ($i = 0; $i + 3 < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $variable) {
+                continue;
+            }
+
+            if ($tokens[$i + 1]['text'] !== '[' || $tokens[$i + 2]['text'] !== ']') {
+                continue;
+            }
+
+            if ($tokens[$i + 3]['id'] === null && $tokens[$i + 3]['text'] === '=') {
+                $found[] = $i;
+            }
+        }
+
+        return $found;
+    }
+
+    /**
+     * `$var = <式>;` の形の代入の位置と右辺のトークンの綴りの列。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<array{index: int, rhs: list<string>}>
+     */
+    public static function variableAssignments(array $tokens, string $variable): array
+    {
+        $count = count($tokens);
+        $found = [];
+
+        for ($i = 0; $i + 1 < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $variable) {
+                continue;
+            }
+
+            if ($tokens[$i + 1]['id'] !== null || $tokens[$i + 1]['text'] !== '=') {
+                continue;
+            }
+
+            $found[] = ['index' => $i, 'rhs' => self::statementTokens($tokens, $i + 1)];
+        }
+
+        return $found;
+    }
+
+    /**
+     * 指定位置の次のトークンから、深さ 0 の `;` までのトークンの綴りの列。
+     *
+     * ★`;` が見つからない場合は**例外** (fail-closed)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<string>
+     */
+    public static function statementTokens(array $tokens, int $index): array
+    {
+        $count = count($tokens);
+        $depth = 0;
+        $texts = [];
+
+        for ($i = $index + 1; $i < $count; $i++) {
+            $text = $tokens[$i]['text'];
+
+            if (self::isBraceOpen($tokens[$i]) || ($tokens[$i]['id'] === null && in_array($text, ['(', '['], true))) {
+                $depth++;
+            } elseif ($tokens[$i]['id'] === null && in_array($text, ['}', ')', ']'], true)) {
+                $depth--;
+            } elseif ($tokens[$i]['id'] === null && $text === ';' && $depth === 0) {
+                return $texts;
+            }
+
+            $texts[] = $text;
+        }
+
+        throw new RuntimeException('statement terminator not found after token index '.$index);
+    }
+
+    /**
+     * 各 `if` の [条件のトークン範囲, 本体のトークン範囲]。
+     *
+     * ★条件の丸括弧が閉じない / 本体が波括弧でない形は**例外** (fail-closed)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<array{condition: array{int, int}, body: array{int, int}}>
+     */
+    public static function ifBlocks(array $tokens): array
+    {
+        $count = count($tokens);
+        $blocks = [];
+
+        foreach (self::findTokens($tokens, T_IF) as $index) {
+            $open = $index + 1;
+
+            if ($open >= $count || $tokens[$open]['id'] !== null || $tokens[$open]['text'] !== '(') {
+                throw new RuntimeException('if condition is not parenthesised at token index '.$index);
+            }
+
+            $close = self::matchingParen($tokens, $open);
+            $body = self::blockRange($tokens, $close);
+
+            if ($body[0] !== $close + 1) {
+                throw new RuntimeException('if body is not a brace block at token index '.$index);
+            }
+
+            $blocks[] = ['condition' => [$open + 1, $close - 1], 'body' => $body];
+        }
+
+        return $blocks;
+    }
+
+    /**
+     * 呼び出し / 生成の丸括弧の中を最上位のカンマで割り、各引数のトークンの綴りの列を返す。
+     *
+     * ★`$callIndex` は**開き丸括弧の添字**である。括弧の対応が取れない場合は**例外** (fail-closed)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return list<list<string>>
+     */
+    public static function callArguments(array $tokens, int $callIndex): array
+    {
+        if ($tokens[$callIndex]['id'] !== null || $tokens[$callIndex]['text'] !== '(') {
+            throw new RuntimeException('callArguments() expects the index of an opening parenthesis.');
+        }
+
+        $close = self::matchingParen($tokens, $callIndex);
+        $arguments = [];
+        $current = [];
+        $depth = 0;
+
+        for ($i = $callIndex + 1; $i < $close; $i++) {
+            $text = $tokens[$i]['text'];
+
+            if ($tokens[$i]['id'] === null && in_array($text, ['(', '['], true)) {
+                $depth++;
+            } elseif (self::isBraceOpen($tokens[$i])) {
+                $depth++;
+            } elseif ($tokens[$i]['id'] === null && in_array($text, [')', ']', '}'], true)) {
+                $depth--;
+            } elseif ($tokens[$i]['id'] === null && $text === ',' && $depth === 0) {
+                $arguments[] = $current;
+                $current = [];
+
+                continue;
+            }
+
+            $current[] = $text;
+        }
+
+        if ($current !== []) {
+            $arguments[] = $current;
+        }
+
+        return $arguments;
+    }
+
+    /**
+     * 「適用のループが指定ブロックの内側にあり、その本体に指定の静的呼び出しがちょうど 1 件ある」か。
+     *
+     * ★静的呼び出しをループ本体で数えるのが load-bearing である — 空のループを `try` に残して
+     *   実際の適用を別の場所へ移す書き換えを、`foreach` の位置だけでは止められない。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $loopExpression
+     * @param  array{int, int}  $blockRange
+     */
+    public static function applyLoopIsGuarded(array $tokens, array $loopExpression, array $blockRange, string $staticMethod): bool
+    {
+        $loops = self::foreachOverExpression($tokens, $loopExpression);
+
+        if (count($loops) !== 1) {
+            return false;
+        }
+
+        $body = self::blockRange($tokens, $loops[0]);
+
+        if (! self::isWithin($blockRange, $loops[0]) || ! self::isWithin($blockRange, $body[1])) {
+            return false;
+        }
+
+        return count(self::indexesWithin(self::staticCalls($tokens, $staticMethod), $body)) === 1;
+    }
+
+    /**
+     * 復元が「ループ内で途中終了せず失敗を蓄積し、ループの後の 1 か所だけで送出する」構造か。
+     *
+     * 5 条 (「唯一の `throw` がループの外にある」だけでは、ループ内で `break` して抜ける形や、
+     * 失敗を蓄積せず無条件に送出する形が通ってしまう):
+     *
+     *  1. 復元のループの本体に `throw` / `return` / `break` / `continue` が 1 件も無い
+     *  2. `$accumulator[] = …` がループ本体にちょうど 1 件ある
+     *  3. その追加が `$flagVariable === false` の条件分岐の**本体**にある
+     *  4. ループの**後**の `$accumulator !== []` の条件分岐の本体に、**メソッド唯一の `throw`** がある
+     *  5. その `throw` 以外に、メソッドを途中終了させるトークン (`return` / `throw`) が無い
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $loopExpression
+     */
+    public static function restoreStructureIsDeferred(array $tokens, array $loopExpression, string $accumulator, string $flagVariable): bool
+    {
+        $loops = self::foreachOverExpression($tokens, $loopExpression);
+
+        if (count($loops) !== 1) {
+            return false;
+        }
+
+        $body = self::blockRange($tokens, $loops[0]);
+
+        // (1) ループ本体で途中終了しない
+        foreach (self::CONTROL_FLOW_TOKEN_IDS as $id) {
+            if (self::indexesWithin(self::controlFlowTokens($tokens, $id), $body) !== []) {
+                return false;
+            }
+        }
+
+        // (2) 失敗をループ本体で蓄積する
+        $appends = self::indexesWithin(self::variableAppends($tokens, $accumulator), $body);
+
+        if (count($appends) !== 1) {
+            return false;
+        }
+
+        // (3) 蓄積が `$applied === false` の分岐の本体にある
+        $blocks = self::ifBlocks($tokens);
+        $failureBranches = array_values(array_filter(
+            $blocks,
+            fn (array $block): bool => self::conditionMatches($tokens, $block['condition'], $flagVariable, T_IS_IDENTICAL, 'false'),
+        ));
+
+        if (count($failureBranches) !== 1 || ! self::isWithin($failureBranches[0]['body'], $appends[0])) {
+            return false;
+        }
+
+        // (4) ループの後の `$failed !== []` の分岐に、メソッド唯一の throw がある
+        $throws = self::controlFlowTokens($tokens, T_THROW);
+
+        if (count($throws) !== 1 || $throws[0] < $body[1]) {
+            return false;
+        }
+
+        $reportBranches = array_values(array_filter(
+            $blocks,
+            fn (array $block): bool => self::conditionMatches($tokens, $block['condition'], $accumulator, T_IS_NOT_IDENTICAL, '['),
+        ));
+
+        if (count($reportBranches) !== 1 || ! self::isWithin($reportBranches[0]['body'], $throws[0])) {
+            return false;
+        }
+
+        // (5) 他に途中終了が無い
+        return self::controlFlowTokens($tokens, T_RETURN) === [];
+    }
+
+    /**
+     * 指定ブロックの中で `$var->method(…)` がちょうど 1 件あり、指定位置の引数が期待の綴り列か。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array{int, int}  $blockRange
+     * @param  list<string>  $expected
+     */
+    public static function methodCallArgumentMatches(
+        array $tokens,
+        array $blockRange,
+        string $variable,
+        string $method,
+        int $argumentIndex,
+        array $expected,
+    ): bool {
+        $calls = self::indexesWithin(self::methodCalls($tokens, $variable, $method), $blockRange);
+
+        if (count($calls) !== 1) {
+            return false;
+        }
+
+        return (self::callArguments($tokens, $calls[0])[$argumentIndex] ?? null) === $expected;
+    }
+
+    /**
+     * 指定ブロックの中で `$var = <式>;` がちょうど 1 件あり、右辺が期待の綴り列か。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array{int, int}  $blockRange
+     * @param  list<string>  $expected
+     */
+    public static function variableAssignmentMatches(array $tokens, array $blockRange, string $variable, array $expected): bool
+    {
+        $assignments = array_values(array_filter(
+            self::variableAssignments($tokens, $variable),
+            fn (array $assignment): bool => self::isWithin($blockRange, $assignment['index']),
+        ));
+
+        if (count($assignments) !== 1) {
+            return false;
+        }
+
+        return $assignments[0]['rhs'] === $expected;
+    }
+
+    /**
+     * 指定ブロックの中の**唯一の** `throw` が、期待の綴り列を送出するか。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array{int, int}  $blockRange
+     * @param  list<string>  $expected
+     */
+    public static function soleThrowMatches(array $tokens, array $blockRange, array $expected): bool
+    {
+        $throws = self::indexesWithin(self::controlFlowTokens($tokens, T_THROW), $blockRange);
+
+        if (count($throws) !== 1) {
+            return false;
+        }
+
+        return self::statementTokens($tokens, $throws[0]) === $expected;
+    }
+
+    /**
+     * `new <クラス名>(…)` がちょうど 1 件あり、指定位置の引数が期待の綴り列か。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $expected
+     */
+    public static function constructionArgumentMatches(array $tokens, string $class, int $argumentIndex, array $expected): bool
+    {
+        $constructions = self::constructions($tokens, $class);
+
+        if (count($constructions) !== 1) {
+            return false;
+        }
+
+        return (self::callArguments($tokens, $constructions[0])[$argumentIndex] ?? null) === $expected;
+    }
+
+    /**
+     * 条件のトークン範囲が「変数 + 比較演算子 + 綴り」を含むか。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array{int, int}  $condition
+     */
+    private static function conditionMatches(array $tokens, array $condition, string $variable, int $operatorId, string $rightText): bool
+    {
+        $hasVariable = false;
+        $hasOperator = false;
+        $hasRight = false;
+
+        for ($i = $condition[0]; $i <= $condition[1]; $i++) {
+            if ($tokens[$i]['id'] === T_VARIABLE && $tokens[$i]['text'] === $variable) {
+                $hasVariable = true;
+            }
+
+            if ($tokens[$i]['id'] === $operatorId) {
+                $hasOperator = true;
+            }
+
+            if ($tokens[$i]['text'] === $rightText) {
+                $hasRight = true;
+            }
+        }
+
+        return $hasVariable && $hasOperator && $hasRight;
+    }
+
+    /**
+     * 開き丸括弧に対応する閉じ丸括弧の添字 (対応が取れなければ例外)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function matchingParen(array $tokens, int $openIndex): int
+    {
+        $count = count($tokens);
+        $depth = 0;
+
+        for ($i = $openIndex; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== null) {
+                continue;
+            }
+
+            if ($tokens[$i]['text'] === '(') {
+                $depth++;
+            } elseif ($tokens[$i]['text'] === ')') {
+                $depth--;
+
+                if ($depth === 0) {
+                    return $i;
+                }
+            }
+        }
+
+        throw new RuntimeException('unbalanced parentheses starting at token index '.$openIndex);
+    }
+
+    /**
+     * 開き波括弧か (文字列補間の開き括弧も同列に数える)。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function isBraceOpen(array $token): bool
+    {
+        if ($token['id'] === null) {
+            return $token['text'] === '{';
+        }
+
+        return in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
+    }
+}
diff --git a/tests/Support/RawEnv/RawEnvSnapshot.php b/tests/Support/RawEnv/RawEnvSnapshot.php
new file mode 100644
index 00000000..e74b8fc9
--- /dev/null
+++ b/tests/Support/RawEnv/RawEnvSnapshot.php
@@ -0,0 +1,356 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\RawEnv;
+
+use Closure;
+use Illuminate\Support\Env;
+use InvalidArgumentException;
+use RuntimeException;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 生の環境変数 3 面 (`getenv()` / `$_ENV` / `$_SERVER`) の退避・注入・復元。
+ *
+ * ★**本リポジトリで 3 面を触ってよいのはこのクラスだけ**である
+ *   (例外は自身の契約テストと `tests/bootstrap.php` の 2 つ。
+ *    `RawEnvDirectWriteGateTest` が deny-by-default で強制する。
+ *    ただし gate が見るのは**列挙した字句の書き込み形だけ**である。保証範囲は
+ *    `RawEnvDirectWriteScanner` の docblock が正本)。
+ * ★`RefreshDatabase` は**プロセスの環境変数を守らない**ので、テストが env を触ったら
+ *   自分で元へ戻さないとテストプロセス全体へ漏れる。
+ * ★3 面すべてを見るのは、Laravel の `env()` が **`$_SERVER` → `$_ENV` → `putenv`** の順に
+ *   live で読むためである (実測: `Illuminate\Support\Env::getRepository()` が
+ *   `RepositoryBuilder::createWithDefaultAdapters()` = `ServerConstAdapter` → `EnvConstAdapter`
+ *   を作り、`$putenv` が真のとき末尾に `PutenvAdapter` を足す)。
+ *   **この順序は注釈ではなく契約テストが実行時に固定する**。
+ *
+ * ── 2 通りの結び方 (家系の正典 raw-env-snapshot-restore v1 の i5。択一ではない) ──
+ *
+ *  (a) 閉包を囲む形: `with()`。検証 → 退避 → `try { 適用 + 本体 } finally { 復元 }`
+ *  (b) 退避を持ち回る形: `captureAndClear()` + `restore()`。
+ *      検証 → 退避 → `try { 未設定化 } catch { 復元して再送出 }` → 呼び出し側が
+ *      枠組みの後処理フック (`afterEach`) から `restore()` を呼ぶ
+ *
+ *  (b) は適用が終わった時点で呼び出し側へ戻るので `finally` を本体の終わりまで
+ *  開いたままにできない。**適用の途中で失敗したときの巻き戻しはその場で行う**
+ *  (失敗すると snapshot が呼び出し側へ返らない = 後処理フックも戻せないため)。
+ *
+ * ── 例外の契約 ─────────────────────────────────────────────────────
+ *
+ *  - キーの不正・拒否は第 1 段で `InvalidArgumentException`。**1 面も触っていない**。
+ *  - `putenv()` の失敗は `RuntimeException`。
+ *  - **復元は最初の失敗で止めない** — 全キーの 3 面を最後まで戻し、失敗したキーを集めて
+ *    最後に 1 つの `RuntimeException` にする。
+ *  - **本体の例外と復元の失敗が重なった場合**、表に出るのは復元の失敗で、
+ *    本体の例外は `previous` に連結する (情報を落とさない)。
+ *
+ * ── 使い方 ─────────────────────────────────────────────────────────
+ *
+ *  **同時に触るキーは 1 回の操作で渡す** (単一キーの操作を入れ子にして分けない)。
+ *  分けると、内側のキーが拒否された時点で外側のキーは既に適用済みになり、
+ *  「検証の段では何も触らない」が呼び出し側の書き方で崩れる。
+ *
+ * ── 保証しないもの (誇張しない) ─────────────────────────────────────────
+ *
+ *  - **適用の途中で `putenv()` が失敗したときの巻き戻りと、復元が最初の失敗で止まらないことは
+ *    動的には検査していない** (検証を通ったキーで `putenv()` を失敗させる状況をテストから作れず、
+ *    失敗を注入する差し替え口は新設しない)。構造の固定
+ *    (`RawEnvGuardStructure` を使う契約テストの h 群) で代えている。
+ *  - `$changes` / `$keys` に**現れないキーには一切触れない**。
+ *  - 閉包の口は PHP の連想配列の性質で**数値だけのキーが整数へ畳まれる**ため拒否される。
+ *    持ち回りの口は `list` なので畳み込みが起きず数値だけのキーも扱えるが、本リポジトリに需要は無い。
+ *  - **本部品はテスト専用である**。`putenv()` はスレッド安全でないため本番の経路では使わない。
+ */
+final class RawEnvSnapshot
+{
+    /**
+     * 差し替えを拒否するキーの接頭辞 (単一点の守りから導いた宣言。**許可一覧は持たない**)。
+     *
+     * `DB_` — `tests/bootstrap.php` は「pgsql lane の最終 `DB_DATABASE` が test DB か」を
+     * Laravel boot 前に 1 回だけ fail-closed 検証する単一点ガードである
+     * (`Tests\Support\Ci\TestDatabaseEnv::assertPgsqlTestDatabaseSafe()`)。
+     * テスト実行中に DB 系 env を差し替えると、その検査の後ろを通ることになり
+     * dev DB へ向く経路を作りうる (AGENTS.md 禁止事項 3)。
+     *
+     * @var list<non-empty-string>
+     */
+    public const array DENIED_KEY_PREFIXES = ['DB_'];
+
+    /**
+     * 差し替えを拒否するキー (完全一致)。
+     *
+     * `TEST_TOKEN` — paratest の作業単位の同定。Laravel の並列 DB 名
+     * (`<base>_test_<TEST_TOKEN>`) がこれに乗っている。
+     * `APP_CONFIG_CACHE` — `scripts/ci/ensure-test-db.php` が子プロセスへ渡す
+     * 専用の設定キャッシュパス。親で立てると「通常経路では誰も生成しないはずの専用パス」の
+     * 検査が意味を失う。
+     *
+     * @var list<non-empty-string>
+     */
+    public const array DENIED_KEYS = ['TEST_TOKEN', 'APP_CONFIG_CACHE'];
+
+    /**
+     * @param  list<array{key: string, serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>  $state
+     */
+    private function __construct(private readonly array $state) {}
+
+    /**
+     * 3 面を差し替えて閉包を実行し、**成否によらず**元の存在状態と値へ戻す。
+     *
+     * ★キーは `string` で受け、非空・書式・拒否は**実行時の契約**として第 1 段で検査する
+     *   (`non-empty-string` を宣言すると、不正入力を拒否する検査そのものが書けなくなる)。
+     *
+     * @template TReturn
+     *
+     * @param  array<string, RawEnvChannels>  $changes
+     * @param  Closure(): TReturn  $body
+     * @return TReturn
+     *
+     * @throws InvalidArgumentException キーが不正 / 拒否対象 / process 値に NUL (第 1 段。1 面も触っていない)
+     * @throws RuntimeException `putenv()` が失敗した場合 (復元は行われる)
+     */
+    public static function with(array $changes, Closure $body): mixed
+    {
+        // --- 第 1 段: 検証 (この時点では何も触らない) ---
+        self::assertChangesAllowed($changes);
+
+        /** @var list<string> $keys */
+        $keys = array_keys($changes);
+
+        // --- 第 2 段: 退避 (この時点でも何も変えない) ---
+        $snapshot = self::capture($keys);
+
+        // --- 第 3 段: 適用 + 本体 (適用途中の失敗も finally で巻き戻る) ---
+        $bodyError = null;
+
+        try {
+            foreach ($changes as $key => $channels) {
+                self::apply((string) $key, $channels);
+            }
+
+            return $body();
+        } catch (Throwable $e) {
+            $bodyError = $e;
+
+            throw $e;
+        } finally {
+            $snapshot->restore($bodyError);
+        }
+    }
+
+    /**
+     * 指定キーの 3 面を退避し、そのうえで 3 面とも未設定にする。
+     * 復元は呼び出し側が枠組みの後処理フックから `restore()` を呼んで行う。
+     *
+     * @param  list<string>  $keys
+     *
+     * @throws InvalidArgumentException キーが不正 / 拒否対象の場合 (1 面も触っていない)
+     * @throws RuntimeException `putenv()` が失敗した場合 (**その場で巻き戻してから**送出する)
+     */
+    public static function captureAndClear(array $keys): self
+    {
+        self::assertKeysAllowed($keys);
+        $snapshot = self::capture($keys);
+
+        try {
+            foreach ($keys as $key) {
+                self::apply($key, RawEnvChannels::none());
+            }
+        } catch (Throwable $e) {
+            $snapshot->restore($e);
+
+            throw $e;
+        }
+
+        return $snapshot;
+    }
+
+    /**
+     * 退避した 3 面を、元の存在状態と値へ戻す (面ごとに独立して戻す)。
+     *
+     * ★**最初の失敗で止めない**。全キーを最後まで戻してから、失敗したキーをまとめて例外にする。
+     *
+     * @param  Throwable|null  $previous  本体側で起きていた例外 (復元も失敗したときに連結する)
+     *
+     * @throws RuntimeException 1 つ以上のキーで `putenv()` が失敗した場合
+     */
+    public function restore(?Throwable $previous = null): void
+    {
+        /** @var list<string> $failed */
+        $failed = [];
+
+        foreach ($this->state as $saved) {
+            $key = $saved['key'];
+
+            if ($saved['serverExists']) {
+                $_SERVER[$key] = $saved['server'];
+            } else {
+                unset($_SERVER[$key]);
+            }
+
+            if ($saved['envExists']) {
+                $_ENV[$key] = $saved['env'];
+            } else {
+                unset($_ENV[$key]);
+            }
+
+            // `putenv('K=a=b')` は値 `a=b` を設定する (等号を含む値を壊さない)。
+            $applied = is_string($saved['process'])
+                ? putenv($key.'='.$saved['process'])
+                : putenv($key);
+
+            if ($applied === false) {
+                $failed[] = $key;
+            }
+        }
+
+        if ($failed !== []) {
+            throw new RuntimeException(
+                'putenv() failed while restoring env keys: '.implode(', ', $failed),
+                0,
+                $previous,
+            );
+        }
+    }
+
+    /**
+     * **枠組みを作り直す直前に呼ぶ。** 3 面へ入れた値が `.env.testing` の値で
+     * 上書きされるのを防ぐ (正典 v1 の i10)。
+     *
+     * phpdotenv の immutable writer は「既に定義済みの変数は上書きしない」を
+     * **自分が書いたかどうか**で判定する (実装を実読:
+     * `Dotenv\Repository\Adapter\ImmutableWriter::isExternallyDefined()` は
+     * 「読めて、かつ `$loaded` に自分の記録が無い」ときだけ真を返す)。
+     * その writer は `Illuminate\Support\Env::$repository` に**プロセス静的**で保持されるので、
+     * 1 度目の boot で `.env.testing` が書いたキーは `$loaded` に載ったままになり、
+     * **env を読み直すたびに `.env.testing` の値で上書きされる**。
+     * repository を捨てると `$loaded` が空の writer が作り直され、
+     * 3 面に在る値が「外部で定義済み」として尊重される。
+     *
+     * ★**依拠している副作用 (監視条件)**: `Env::enablePutenv()` は本来
+     *   putenv アダプタを有効化する API だが、その実装が `static::$repository = null` を
+     *   伴うことに依拠している (実測: laravel/framework の `Illuminate\Support\Env`)。
+     *   本リポジトリは `disablePutenv()` を呼ばないので、副作用は repository の作り直しだけである。
+     *   **上流の版を上げてこの副作用が消えたら、i10 の手段を再評価すること**
+     *   (家系の正典 v1 の未決論点 q3)。副作用が生きていること自体は契約テスト (g-1〜g-3) が
+     *   実行時に固定する — docblock の監視条件だけでは「緑のまま保証だけ失われる」を検出できない。
+     */
+    public static function forgetLaravelEnvRepository(): void
+    {
+        Env::enablePutenv();
+    }
+
+    /**
+     * 閉包の口の第 1 段 (キーと process 値の検査。**1 面も触らない**)。
+     *
+     * @param  array<array-key, RawEnvChannels>  $changes
+     */
+    private static function assertChangesAllowed(array $changes): void
+    {
+        self::assertKeysAllowed(array_keys($changes));
+
+        foreach ($changes as $key => $channels) {
+            if (! $channels->processSpecified) {
+                continue;
+            }
+
+            // putenv() は NUL を含む文字列で ValueError を投げる。適用の段まで持ち越さない。
+            Assert::notContains(
+                $channels->processValue,
+                "\0",
+                "env value for key [{$key}] must not contain a NUL byte (putenv() would throw).",
+            );
+        }
+    }
+
+    /**
+     * 受け取ったキーをすべて検査する (第 1 段。**1 面も触らない**)。
+     *
+     * @param  list<array-key>  $keys
+     */
+    private static function assertKeysAllowed(array $keys): void
+    {
+        foreach ($keys as $key) {
+            // PHP の連想配列は "0" のような数値だけのキーを整数へ畳む。
+            // 畳まれて届いたら復元時に別のキーを触ることになるので拒否する (fail-closed)。
+            if (! is_string($key)) {
+                throw new InvalidArgumentException(
+                    'env key must be a string (PHP folds numeric-string array keys into integers): '
+                    .var_export($key, true)
+                );
+            }
+
+            Assert::stringNotEmpty($key, 'env key must not be empty.');
+            Assert::notContains($key, '=', "env key must not contain '=' (putenv syntax): {$key}");
+            Assert::notContains($key, "\0", 'env key must not contain a NUL byte.');
+
+            foreach (self::DENIED_KEY_PREFIXES as $prefix) {
+                Assert::false(
+                    str_starts_with($key, $prefix),
+                    "env key [{$key}] is denied: テスト DB の単一点ガード (tests/bootstrap.php) の前提を崩す。"
+                    .'DB 接続を発生させない隔離された評価手段を設計フローで新設すること。',
+                );
+            }
+
+            Assert::notInArray(
+                $key,
+                self::DENIED_KEYS,
+                "env key [{$key}] is denied: 並列実行の作業単位の同定 / 専用の設定キャッシュパスの前提を崩す。",
+            );
+        }
+    }
+
+    /**
+     * 3 面の現在の存在状態と値を退避する (第 2 段。**何も変えない**)。
+     *
+     * ★退避は**連想配列ではなくリスト**で持つ。キーで索く必要が無いうえ、
+     *   連想配列にすると数値だけのキーが整数へ畳まれて復元先がずれる。
+     *
+     * @param  list<string>  $keys
+     */
+    private static function capture(array $keys): self
+    {
+        $state = [];
+        foreach ($keys as $key) {
+            $state[] = [
+                'key' => $key,
+                // 存在は値と別に持つ (`?? null` は「存在するが null」を潰す)
+                'serverExists' => array_key_exists($key, $_SERVER),
+                'server' => $_SERVER[$key] ?? null,
+                'envExists' => array_key_exists($key, $_ENV),
+                'env' => $_ENV[$key] ?? null,
+                // getenv() の false (未設定) と '' (空文字) を区別する
+                'process' => getenv($key),
+            ];
+        }
+
+        return new self($state);
+    }
+
+    /** 指定した面に値を置き、**指定しなかった面は明示的に未設定にする** (i7)。 */
+    private static function apply(string $key, RawEnvChannels $channels): void
+    {
+        if ($channels->serverSpecified) {
+            $_SERVER[$key] = $channels->serverValue;
+        } else {
+            unset($_SERVER[$key]);
+        }
+
+        if ($channels->envSpecified) {
+            $_ENV[$key] = $channels->envValue;
+        } else {
+            unset($_ENV[$key]);
+        }
+
+        $applied = $channels->processSpecified
+            ? putenv($key.'='.$channels->processValue)
+            : putenv($key);
+
+        if ($applied === false) {
+            throw new RuntimeException("putenv() failed for env key [{$key}].");
+        }
+    }
+}
diff --git a/tests/Support/RawEnv/RawEnvWriteKind.php b/tests/Support/RawEnv/RawEnvWriteKind.php
new file mode 100644
index 00000000..73f9f495
--- /dev/null
+++ b/tests/Support/RawEnv/RawEnvWriteKind.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\RawEnv;
+
+/**
+ * 生の環境変数 3 面への**書き込みの形**の分類 (`RawEnvDirectWriteScanner` の出力語彙)。
+ *
+ * ★値は目録のキーに使うので `string` backed である。
+ * ★`Unresolved` は「分類できなかった出現」であり**必ず違反**になる。
+ *   目録へ登録して黙らせることはできない (`RawEnvDirectWriteGateTest` の G7 が別途赤にする)。
+ */
+enum RawEnvWriteKind: string
+{
+    /** 面の要素への代入 (通常 / 複合 / `??=` / 前後置インクリメント / 多段添字)。 */
+    case ElementAssign = 'element_assign';
+
+    /** 面の要素の削除 (`unset($_SERVER['K'])`)。 */
+    case ElementUnset = 'element_unset';
+
+    /** 面そのものへの代入 (複合代入を含む)。 */
+    case WholeAssign = 'whole_assign';
+
+    /** 面 / 面の要素への参照の取得 (`&$_SERVER['K']`)。 */
+    case ReferenceTaken = 'reference_taken';
+
+    /** 分割代入の左辺に面が現れる形 (`[$_SERVER['K']] = $v;`)。 */
+    case DestructuringTarget = 'destructuring_target';
+
+    /** プロセス面への書き込み (`putenv('K=V')` / `putenv('K')` の両形)。 */
+    case Putenv = 'putenv';
+
+    /** 分類できなかった出現 (fail-closed。目録へ登録できない)。 */
+    case Unresolved = 'unresolved';
+}
diff --git a/tests/Support/RawEnv/RawEnvWriteSite.php b/tests/Support/RawEnv/RawEnvWriteSite.php
new file mode 100644
index 00000000..2cbb3e4e
--- /dev/null
+++ b/tests/Support/RawEnv/RawEnvWriteSite.php
@@ -0,0 +1,20 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\RawEnv;
+
+/**
+ * 3 面への書き込み (と分類できなかった出現) 1 件の位置と分類 (不変の値オブジェクト)。
+ *
+ * ★`subject` は書き込まれた面の綴り (`$_SERVER` / `$_ENV` / `putenv` の呼び出しの綴り) である。
+ *   違反を報告するときに「どの面か」を人が読める形で出すためだけに持つ。
+ */
+final class RawEnvWriteSite
+{
+    public function __construct(
+        public readonly RawEnvWriteKind $kind,
+        public readonly string $subject,
+        public readonly int $line,
+    ) {}
+}
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index f7c18ea4..29d4c251 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 49;
+    public const int DIVERGENCE_ENTRY_COUNT = 50;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
diff --git a/tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php b/tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php
new file mode 100644
index 00000000..e28a798b
--- /dev/null
+++ b/tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php
@@ -0,0 +1,288 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\RawEnv\RawEnvDirectWriteScanner;
+use Tests\Support\RawEnv\RawEnvWriteSite;
+
+/*
+ * `Tests\Support\RawEnv\RawEnvDirectWriteScanner` の自己検査 (走査器の検出力の裏取り)。
+ *
+ * ★AGENTS.md「静的検査 (gate) と走査器の共通規約」(c)(e) に従い、**正例と負例の両方向**を固定する。
+ *   負例には**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く (`myputenv` / `not_putenv` /
+ *   `putenv_safe`)。素の部分文字列一致で書くとこの 3 形まで一緒に消えて検出漏れになる、が
+ *   本リポジトリの実測である。
+ * ★**負例・正例は fixture ファイルを置かず、ナウドキュメント (`<<<'PHP'`) のソース文字列を
+ *   走査器へ直接渡す**。fixture ファイルを置くと `RawEnvDirectWriteGateTest` の母集団に入り、
+ *   許可箇所を増やすことになる。ナウドキュメントの本文は `token_get_all()` では 1 トークン
+ *   (`T_ENCAPSED_AND_WHITESPACE`) になり中の綴りが見えないため、**この自己検査ファイル自身は
+ *   gate に対して違反にならない** (実測で確認済み)。
+ * ★**母集団の非空は契約しない**。空入力でも例外にせず 0 件を返す
+ *   (非空を要求するのは検出器を**使う側**の gate である)。
+ */
+
+/**
+ * 走査結果を種別の綴りの列へ落とす (位置ではなく分類の裏取りに使う)。
+ *
+ * @return list<string>
+ */
+function rawEnvScannerKinds(string $source): array
+{
+    return array_map(
+        static fn (RawEnvWriteSite $site): string => $site->kind->value,
+        RawEnvDirectWriteScanner::scan($source),
+    );
+}
+
+// ── 正例 ──
+
+test('正例 1: 代入系 14 種はすべて element_assign として検出される', function (string $operator): void {
+    $source = "<?php\n\$_SERVER['K'] {$operator} 'v';\n";
+
+    expect(rawEnvScannerKinds($source))->toBe(['element_assign']);
+})->with(['=', '.=', '+=', '-=', '*=', '/=', '%=', '**=', '??=', '|=', '&=', '^=', '<<=', '>>=']);
+
+test('正例 2: 前置・後置インクリメントと多段添字も element_assign', function (): void {
+    $source = <<<'PHP'
+    <?php
+    ++$_SERVER['K'];
+    $_ENV['K']--;
+    $_SERVER['a']['b'] = 'v';
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['element_assign', 'element_assign', 'element_assign']);
+});
+
+test('正例 3: unset の引数に並んだ面は 2 件とも element_unset', function (): void {
+    $source = <<<'PHP'
+    <?php
+    unset($_SERVER['K'], $_ENV['K']);
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['element_unset', 'element_unset']);
+});
+
+test('正例 4: 面そのものへの代入は whole_assign', function (): void {
+    $source = <<<'PHP'
+    <?php
+    $_SERVER = [];
+    $_ENV += [];
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['whole_assign', 'whole_assign']);
+});
+
+test('正例 5: 面と面の要素への参照の取得は reference_taken', function (): void {
+    $source = <<<'PHP'
+    <?php
+    $r = &$_SERVER['K'];
+    $s = &$_ENV;
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['reference_taken', 'reference_taken']);
+});
+
+test('正例 6: 分割代入の左辺に現れる面は destructuring_target', function (): void {
+    $source = <<<'PHP'
+    <?php
+    [$_SERVER['K']] = $v;
+    list($_ENV['K']) = $v;
+    [[$_ENV['K']]] = $v;
+    PHP;
+
+    expect(rawEnvScannerKinds($source))
+        ->toBe(['destructuring_target', 'destructuring_target', 'destructuring_target']);
+});
+
+test('正例 7: putenv は両形とも検出される', function (): void {
+    $source = <<<'PHP'
+    <?php
+    putenv('K=V');
+    putenv('K');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['putenv', 'putenv']);
+});
+
+test('正例 8: 完全修飾の呼び出しも検出される', function (): void {
+    $source = <<<'PHP'
+    <?php
+    \putenv('K=V');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['putenv']);
+});
+
+test('正例 9: 別名つき取り込みを解いて検出される', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Probe;
+
+    use function putenv as setRawEnv;
+
+    setRawEnv('K=V');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['putenv']);
+});
+
+test('正例 10: グローバル名前空間の namespace\\putenv は検出される', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace\putenv('K=V');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['putenv']);
+});
+
+// ── 負例 (誤検出しない) ──
+
+test('負例 1: 完全修飾名が putenv にならない別名は検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Probe;
+
+    use function Acme\{putenv as p2};
+
+    p2('K=V');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
+test('負例 2: 名前空間の中の namespace\\putenv は検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Probe;
+
+    namespace\putenv('K=V');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
+test('負例 3: 面の読み出しは検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    $a = $_SERVER['K'] ?? null;
+    foreach ($_SERVER as $k => $v) {
+    }
+    f($_SERVER);
+    g($_ENV, $_SERVER);
+    $b = array_key_exists('K', $_ENV);
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
+test('負例 4: unset の中でも面が書き換え対象の根に無ければ検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    unset($other[$_SERVER['K']]);
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
+test('負例 4b: 分割代入の範囲内でも lvalue の根でなければ検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    [$other[$_SERVER['K']]] = $v;
+    list($other[$_SERVER['K']]) = $v;
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
+test('負例 5: 同名のメソッド呼び出しは検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    $x->putenv('K=V');
+    X::putenv('K=V');
+    $y?->putenv('K=V');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
+test('負例 6: 接頭辞・打ち消し・接尾辞つきの別識別子は検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    myputenv('K=V');
+    not_putenv('K=V');
+    putenv_safe('K=V');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
+test('負例 7: 文字列リテラルとコメントの中の綴りは検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    $a = 'putenv($_SERVER)';
+    // putenv('K=V'); $_SERVER['K'] = 'v';
+    /* $_ENV['K'] = 'v'; */
+    $b = "putenv";
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
+// ── 解決できない形は落とす (fail-closed) ──
+
+test('未解決 1: 自前で putenv を宣言したファイルの非修飾呼び出しは unresolved', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Probe;
+
+    function putenv(string $assignment): bool
+    {
+        return true;
+    }
+
+    putenv('K=V');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['unresolved']);
+});
+
+test('未解決 2: 名前空間宣言が 2 つ / 波括弧つきの名前空間は unresolved', function (): void {
+    $twoDeclarations = <<<'PHP'
+    <?php
+    namespace A;
+
+    putenv('K=V');
+
+    namespace B;
+
+    putenv('K=V');
+    PHP;
+
+    $braced = <<<'PHP'
+    <?php
+    namespace A {
+        putenv('K=V');
+    }
+    PHP;
+
+    expect(rawEnvScannerKinds($twoDeclarations))->toBe(['unresolved', 'unresolved'])
+        ->and(rawEnvScannerKinds($braced))->toBe(['unresolved']);
+});
+
+test('未解決 3: 読み出しとも書き込みとも決まらない単独の出現は unresolved', function (): void {
+    $source = <<<'PHP'
+    <?php
+    $_SERVER;
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe(['unresolved']);
+});
+
+// ── 母集団 ──
+
+test('母集団: 空入力でも例外にせず 0 件を返す', function (string $source): void {
+    expect(RawEnvDirectWriteScanner::scan($source))->toBe([]);
+})->with([
+    'empty string' => [''],
+    'open tag only' => ["<?php\n"],
+]);
diff --git a/tests/Unit/Architecture/RawEnvGuardStructureTest.php b/tests/Unit/Architecture/RawEnvGuardStructureTest.php
new file mode 100644
index 00000000..e410bef0
--- /dev/null
+++ b/tests/Unit/Architecture/RawEnvGuardStructureTest.php
@@ -0,0 +1,430 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\RawEnv\RawEnvGuardStructure;
+
+/*
+ * `Tests\Support\RawEnv\RawEnvGuardStructure` の自己検査 (走査器の検出力の裏取り)。
+ *
+ * ★AGENTS.md「静的検査 (gate) と走査器の共通規約」(c) に従い、**正例と負例の両方向**を固定する。
+ *   正例 (規定どおりの構造を通す) だけでは「常に真を返す判定」を検出できず、
+ *   負例 (退行した構造を落とす) だけでは「常に偽を返す判定」を検出できない。
+ * ★入力は**ナウドキュメント (`<<<'PHP'`) の合成ソース**である。fixture ファイルを置くと
+ *   `RawEnvDirectWriteGateTest` の母集団に入ってしまうため置かない。
+ *   (この判断が効くのは走査器の自己検査 `RawEnvDirectWriteScannerTest` の側だが、
+ *    同じ理由でこちらも合成入力に揃える。)
+ * ★**解決できない形は落とす** ((b))。fail-closed 群がその分岐を固定する。
+ * ★**母集団が空でも例外にしない**のは、本走査器が「入力を受け取って候補を返す再利用可能な
+ *   検出器」であり母集団の非空を契約としないためである。非空を要求するのは**使う側**
+ *   (`RawEnvSnapshotContractTest` の h 群) である。
+ */
+
+/** 閉包の口と同形の合成入力 (正例 1)。 */
+const RAW_ENV_STRUCTURE_WITH_SHAPE = <<<'PHP'
+public static function with(array $changes, Closure $body): mixed
+{
+    self::assertChangesAllowed($changes);
+    $keys = array_keys($changes);
+    $snapshot = self::capture($keys);
+    $bodyError = null;
+
+    try {
+        foreach ($changes as $key => $channels) {
+            self::apply((string) $key, $channels);
+        }
+
+        return $body();
+    } catch (Throwable $e) {
+        $bodyError = $e;
+
+        throw $e;
+    } finally {
+        $snapshot->restore($bodyError);
+    }
+}
+PHP;
+
+/** 持ち回りの口と同形の合成入力 (正例 2)。 */
+const RAW_ENV_STRUCTURE_CAPTURE_SHAPE = <<<'PHP'
+public static function captureAndClear(array $keys): self
+{
+    self::assertKeysAllowed($keys);
+    $snapshot = self::capture($keys);
+
+    try {
+        foreach ($keys as $key) {
+            self::apply($key, RawEnvChannels::none());
+        }
+    } catch (Throwable $e) {
+        $snapshot->restore($e);
+
+        throw $e;
+    }
+
+    return $snapshot;
+}
+PHP;
+
+/** 復元と同形の合成入力 (正例 3)。 */
+const RAW_ENV_STRUCTURE_RESTORE_SHAPE = <<<'PHP'
+public function restore(?Throwable $previous = null): void
+{
+    $failed = [];
+
+    foreach ($this->state as $saved) {
+        $applied = putenv($saved['key']);
+
+        if ($applied === false) {
+            $failed[] = $saved['key'];
+        }
+    }
+
+    if ($failed !== []) {
+        throw new RuntimeException('boom', 0, $previous);
+    }
+}
+PHP;
+
+// ── 正例 ──
+
+test('正例 1: 閉包の口と同形の構造をすべて期待どおりと判定する', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_WITH_SHAPE);
+    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
+    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);
+    $finally = RawEnvGuardStructure::soleBlockRange($tokens, T_FINALLY);
+
+    expect(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$changes'], $try, 'apply'))->toBeTrue()
+        ->and(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $finally, '$snapshot', 'restore', 0, ['$bodyError']))->toBeTrue()
+        ->and(RawEnvGuardStructure::variableAssignmentMatches($tokens, $catch, '$bodyError', ['$e']))->toBeTrue()
+        ->and(RawEnvGuardStructure::soleThrowMatches($tokens, $catch, ['$e']))->toBeTrue();
+});
+
+test('正例 2: 持ち回りの口と同形の構造で復元と再送出が catch 内と判定される', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_CAPTURE_SHAPE);
+    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
+    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);
+
+    expect(RawEnvGuardStructure::findTokens($tokens, T_FINALLY))->toBe([])
+        ->and(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$keys'], $try, 'apply'))->toBeTrue()
+        ->and(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $catch, '$snapshot', 'restore', 0, ['$e']))->toBeTrue()
+        ->and(RawEnvGuardStructure::indexesWithin(RawEnvGuardStructure::controlFlowTokens($tokens, T_THROW), $catch))->toHaveCount(1);
+});
+
+test('正例 3: $this->state を直接回す foreach を 1 件見つける', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_RESTORE_SHAPE);
+
+    expect(RawEnvGuardStructure::foreachOverExpression($tokens, ['$this', '->', 'state']))->toHaveCount(1);
+});
+
+test('正例 4: 復元と同形の構造が「途中終了せず蓄積してから 1 か所で送出する」と判定される', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_RESTORE_SHAPE);
+
+    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeTrue()
+        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, 'RuntimeException', 2, ['$previous']))->toBeTrue();
+});
+
+// ── 負例 ──
+
+test('負例 1: 適用の foreach を try の外へ出すと判定が偽になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public static function with(array $changes, Closure $body): mixed
+    {
+        foreach ($changes as $key => $channels) {
+            self::apply((string) $key, $channels);
+        }
+
+        try {
+            return $body();
+        } finally {
+            $snapshot->restore($bodyError);
+        }
+    }
+    PHP);
+
+    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
+
+    expect(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$changes'], $try, 'apply'))->toBeFalse();
+});
+
+test('負例 2: 復元の呼び出しを finally の外へ出すと判定が偽になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public static function with(array $changes, Closure $body): mixed
+    {
+        try {
+            foreach ($changes as $key => $channels) {
+                self::apply((string) $key, $channels);
+            }
+
+            return $body();
+        } finally {
+        }
+
+        $snapshot->restore($bodyError);
+    }
+    PHP);
+
+    $finally = RawEnvGuardStructure::soleBlockRange($tokens, T_FINALLY);
+
+    expect(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $finally, '$snapshot', 'restore', 0, ['$bodyError']))->toBeFalse();
+});
+
+test('負例 3: 空のループを try に残して適用を外へ移すと判定が偽になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public static function with(array $changes, Closure $body): mixed
+    {
+        try {
+            foreach ($changes as $key => $channels) {
+            }
+
+            self::apply('K', $channels);
+
+            return $body();
+        } finally {
+            $snapshot->restore($bodyError);
+        }
+    }
+    PHP);
+
+    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
+
+    expect(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$changes'], $try, 'apply'))->toBeFalse();
+});
+
+test('負例 4: catch から throw を落とすと判定が偽になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public static function captureAndClear(array $keys): self
+    {
+        try {
+            foreach ($keys as $key) {
+                self::apply($key, RawEnvChannels::none());
+            }
+        } catch (Throwable $e) {
+            $snapshot->restore($e);
+        }
+
+        return $snapshot;
+    }
+    PHP);
+
+    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);
+
+    expect(RawEnvGuardStructure::indexesWithin(RawEnvGuardStructure::controlFlowTokens($tokens, T_THROW), $catch))->toBe([])
+        ->and(RawEnvGuardStructure::soleThrowMatches($tokens, $catch, ['$e']))->toBeFalse();
+});
+
+test('負例 5: throw を復元のループの中へ入れると判定が偽になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function restore(?Throwable $previous = null): void
+    {
+        $failed = [];
+
+        foreach ($this->state as $saved) {
+            $applied = putenv($saved['key']);
+
+            if ($applied === false) {
+                $failed[] = $saved['key'];
+
+                throw new RuntimeException('boom', 0, $previous);
+            }
+        }
+    }
+    PHP);
+
+    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeFalse();
+});
+
+test('負例 6: 面を直接回していない foreach は候補に入らない', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function run(array $changes): void
+    {
+        foreach (array_keys($changes) as $key) {
+        }
+
+        foreach (array_values($this->state) as $saved) {
+        }
+    }
+    PHP);
+
+    expect(RawEnvGuardStructure::foreachOverExpression($tokens, ['$changes']))->toBe([])
+        ->and(RawEnvGuardStructure::foreachOverExpression($tokens, ['$this', '->', 'state']))->toBe([]);
+});
+
+test('負例 7: 復元のループの中で break して抜けると判定が偽になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function restore(?Throwable $previous = null): void
+    {
+        $failed = [];
+
+        foreach ($this->state as $saved) {
+            $applied = putenv($saved['key']);
+
+            if ($applied === false) {
+                $failed[] = $saved['key'];
+
+                break;
+            }
+        }
+
+        if ($failed !== []) {
+            throw new RuntimeException('boom', 0, $previous);
+        }
+    }
+    PHP);
+
+    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeFalse();
+});
+
+test('負例 8: 失敗を蓄積せず無条件に送出する形は判定が偽になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function restore(?Throwable $previous = null): void
+    {
+        $failed = [];
+
+        foreach ($this->state as $saved) {
+            $applied = putenv($saved['key']);
+        }
+
+        if ($failed !== []) {
+            throw new RuntimeException('boom', 0, $previous);
+        }
+    }
+    PHP);
+
+    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeFalse();
+});
+
+test('負例 9: 例外の連結の引数を落とすと判定が偽になる', function (): void {
+    $noPrevious = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function restore(?Throwable $previous = null): void
+    {
+        throw new RuntimeException('boom', 0);
+    }
+    PHP);
+
+    $noArgument = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public static function with(array $changes, Closure $body): mixed
+    {
+        try {
+            return $body();
+        } finally {
+            $snapshot->restore();
+        }
+    }
+    PHP);
+
+    $finally = RawEnvGuardStructure::soleBlockRange($noArgument, T_FINALLY);
+
+    expect(RawEnvGuardStructure::constructionArgumentMatches($noPrevious, 'RuntimeException', 2, ['$previous']))->toBeFalse()
+        ->and(RawEnvGuardStructure::methodCallArgumentMatches($noArgument, $finally, '$snapshot', 'restore', 0, ['$bodyError']))->toBeFalse();
+});
+
+test('負例 10: catch の中で本体の例外を握り潰すと判定が偽になる', function (): void {
+    $nulled = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public static function with(array $changes, Closure $body): mixed
+    {
+        try {
+            return $body();
+        } catch (Throwable $e) {
+            $bodyError = null;
+
+            throw $e;
+        } finally {
+            $snapshot->restore($bodyError);
+        }
+    }
+    PHP);
+
+    $dropped = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public static function with(array $changes, Closure $body): mixed
+    {
+        try {
+            return $body();
+        } catch (Throwable $e) {
+            throw new RuntimeException('replaced');
+        } finally {
+            $snapshot->restore($bodyError);
+        }
+    }
+    PHP);
+
+    $nulledCatch = RawEnvGuardStructure::soleBlockRange($nulled, T_CATCH);
+    $droppedCatch = RawEnvGuardStructure::soleBlockRange($dropped, T_CATCH);
+
+    expect(RawEnvGuardStructure::variableAssignmentMatches($nulled, $nulledCatch, '$bodyError', ['$e']))->toBeFalse()
+        ->and(RawEnvGuardStructure::variableAssignmentMatches($dropped, $droppedCatch, '$bodyError', ['$e']))->toBeFalse()
+        ->and(RawEnvGuardStructure::soleThrowMatches($dropped, $droppedCatch, ['$e']))->toBeFalse();
+});
+
+// ── 解決できない形は落とす (fail-closed) ──
+
+test('fail-closed 1: try が 2 件ある入力は例外になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function run(): void
+    {
+        try {
+        } finally {
+        }
+
+        try {
+        } finally {
+        }
+    }
+    PHP);
+
+    expect(fn (): array => RawEnvGuardStructure::soleBlockRange($tokens, T_TRY))
+        ->toThrow(RuntimeException::class);
+});
+
+test('fail-closed 2: 波括弧が閉じていない入力は例外になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function run(): void
+    {
+        try {
+    PHP);
+
+    $tryIndexes = RawEnvGuardStructure::findTokens($tokens, T_TRY);
+
+    expect(fn (): array => RawEnvGuardStructure::blockRange($tokens, $tryIndexes[0]))
+        ->toThrow(RuntimeException::class);
+});
+
+test('fail-closed 3: 対象メソッドが存在しなければ例外になる', function (): void {
+    expect(fn (): array => RawEnvGuardStructure::methodTokens(RawEnvGuardStructure::class, 'noSuchMethod'))
+        ->toThrow(RuntimeException::class);
+});
+
+test('fail-closed 4: 制御フロー以外の token id を渡すと例外になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_RESTORE_SHAPE);
+
+    expect(fn (): array => RawEnvGuardStructure::controlFlowTokens($tokens, T_IF))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('fail-closed 5: 文の終端が見つからない入力は例外になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function run(): void
+    {
+        throw $e
+    PHP);
+
+    $throwIndexes = RawEnvGuardStructure::findTokens($tokens, T_THROW);
+
+    expect(fn (): array => RawEnvGuardStructure::statementTokens($tokens, $throwIndexes[0]))
+        ->toThrow(RuntimeException::class);
+});
+
+// ── 母集団 ──
+
+test('母集団: foreach が 1 件も無い入力は例外にせず空を返す', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function run(): void
+    {
+        $x = 1;
+    }
+    PHP);
+
+    expect(RawEnvGuardStructure::foreachOverExpression($tokens, ['$changes']))->toBe([])
+        ->and(RawEnvGuardStructure::staticCalls($tokens, 'apply'))->toBe([])
+        ->and(RawEnvGuardStructure::variableAppends($tokens, '$failed'))->toBe([]);
+});
diff --git a/tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php b/tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php
index 991bdc92..a7dfc05c 100644
--- a/tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php
+++ b/tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php
@@ -14,9 +14,12 @@
  * ensure-test-db.php のスキーマ更新まわりを固定する Unit テスト。
  *
  * 固定する不変条件:
- *   1. pgsqlTestArtisanEnv() は環境を継承せず組み立てる (固定キーが常に勝つ / 許可した
- *      3 キーだけ継承する / DB_URL は空で固定する / 親環境の DB_DATABASE・DB_URL・
- *      APP_CONFIG_CACHE を上書きしても固定値が勝つ)
+ *   1. pgsqlTestComposeArtisanEnv() は環境を継承せず組み立てる (固定キーが常に勝つ / 許可した
+ *      3 キーだけ継承する / DB_URL は空で固定する / 入力に DB_DATABASE・DB_URL・
+ *      APP_CONFIG_CACHE が載っていても固定値が勝つ)。読み出しの優先順は
+ *      pgsqlTestMergeParentEnv() が、実際の親環境と接続値との結線は
+ *      pgsqlTestArtisanEnv() の 1 ケースが固定する
+ *      (**親プロセスの 3 面を 1 面も触らない**形で検査する)
  *   2. pgsqlTestConfigCachePath() は projectRoot からの一意な固定パスを返し、
  *      Laravel の既定パス (bootstrap/cache/config.php) とは異なる
  *   3. pgsqlTestMigrationFileNames() はパスから拡張子・ディレクトリを取り除く
@@ -53,22 +56,44 @@
  * `proc_open()` で起動する (DB へは接続しない)。
  */
 
-// ── pgsqlTestArtisanEnv(): 環境を継承しない子プロセス env ──
+// ── pgsqlTestComposeArtisanEnv(): 環境を継承しない子プロセス env ──
+//
+// ★**親プロセスの環境は 1 面も触らない**。危険な値は**組み立ての入力として**与える
+//   (テストのために親へ dev DB 名や攻撃者制御の設定キャッシュパスを立てる、という
+//    隣接ハザードを構造的に消す。AGENTS.md 禁止事項 3)。
+//   これは検出力が上がる方向でもある — 旧実装は親の DB_DATABASE / DB_URL /
+//   APP_CONFIG_CACHE を**そもそも読んでいない**ので、親へ立てて確かめる形は空振りだった。
+
+/**
+ * テスト用の接続値 (実 DB へはつながない。**実際の接続値と重ならない値**にする —
+ * 重ねると「接続値を捨てても緑」になる経路が残る)。
+ *
+ * @return array{host: string, port: string, username: string, password: string}
+ */
+function fakePgsqlConnValues(): array
+{
+    return ['host' => '10.0.0.9', 'port' => '15432', 'username' => 'probe-user', 'password' => 'probe-pass'];
+}
 
 it('does not leak arbitrary environment variables into the child process env', function (): void {
-    $original = getenv('SOME_SECRET');
-    putenv('SOME_SECRET=leaked');
+    $env = pgsqlTestComposeArtisanEnv(
+        __DIR__,
+        'app_test_8af22c44',
+        ['SOME_SECRET' => 'leaked', 'PATH' => '/usr/bin'],
+        fakePgsqlConnValues(),
+    );
 
-    try {
-        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
-        expect($env)->not->toHaveKey('SOME_SECRET');
-    } finally {
-        putenv($original === false ? 'SOME_SECRET' : "SOME_SECRET={$original}");
-    }
+    expect($env)->not->toHaveKey('SOME_SECRET')
+        ->and($env['PATH'])->toBe('/usr/bin');
 });
 
 it('carries over only PATH / HOME / TMPDIR from the parent environment', function (): void {
-    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+    $env = pgsqlTestComposeArtisanEnv(
+        __DIR__,
+        'app_test_8af22c44',
+        ['PATH' => '/usr/bin', 'HOME' => '/home/probe', 'TMPDIR' => '/tmp/probe', 'LANG' => 'C', 'SHELL' => '/bin/sh'],
+        fakePgsqlConnValues(),
+    );
 
     foreach (array_keys($env) as $key) {
         expect(in_array($key, ['PATH', 'HOME', 'TMPDIR'], true) || array_key_exists($key, [
@@ -77,16 +102,19 @@
             'DB_DATABASE' => true, 'CACHE_STORE' => true,
         ]))->toBeTrue("unexpected key leaked into artisan env: {$key}");
     }
+
+    expect($env)->not->toHaveKey('LANG')
+        ->and($env)->not->toHaveKey('SHELL');
 });
 
 it('forces DB_URL empty so that a URL-form connection string cannot override DB_DATABASE', function (): void {
-    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+    $env = pgsqlTestComposeArtisanEnv(__DIR__, 'app_test_8af22c44', [], fakePgsqlConnValues());
 
     expect($env['DB_URL'])->toBe('');
 });
 
 it('pins the computed base name as DB_DATABASE and APP_ENV as testing', function (): void {
-    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+    $env = pgsqlTestComposeArtisanEnv(__DIR__, 'app_test_8af22c44', [], fakePgsqlConnValues());
 
     expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
         ->and($env['APP_ENV'])->toBe('testing')
@@ -94,24 +122,52 @@
 });
 
 it('overrides a parent environment that already sets DB_DATABASE / DB_URL / APP_CONFIG_CACHE to a dev DB', function (): void {
-    $keys = ['DB_DATABASE', 'DB_URL', 'APP_CONFIG_CACHE'];
-    $originals = array_combine($keys, array_map(getenv(...), $keys));
+    $env = pgsqlTestComposeArtisanEnv(
+        __DIR__,
+        'app_test_8af22c44',
+        [
+            'DB_DATABASE' => 'app',
+            'DB_URL' => 'pgsql://postgres:postgres@127.0.0.1:5432/app',
+            'APP_CONFIG_CACHE' => '/tmp/attacker-controlled-config.php',
+        ],
+        fakePgsqlConnValues(),
+    );
 
-    putenv('DB_DATABASE=app');
-    putenv('DB_URL=pgsql://postgres:postgres@127.0.0.1:5432/app');
-    putenv('APP_CONFIG_CACHE=/tmp/attacker-controlled-config.php');
+    expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
+        ->and($env['DB_URL'])->toBe('')
+        ->and($env['APP_CONFIG_CACHE'])->toBe(pgsqlTestConfigCachePath(__DIR__));
+});
 
-    try {
-        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+it('merges the three surfaces with $_SERVER winning over $_ENV over getenv', function (): void {
+    $merged = pgsqlTestMergeParentEnv(
+        ['A' => 'server', 'B' => 'server'],
+        ['A' => 'env', 'B' => 'env', 'C' => 'env'],
+        ['A' => 'process', 'B' => 'process', 'C' => 'process', 'D' => 'process'],
+    );
 
-        expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
-            ->and($env['DB_URL'])->toBe('')
-            ->and($env['APP_CONFIG_CACHE'])->toBe(pgsqlTestConfigCachePath(__DIR__));
-    } finally {
-        foreach ($originals as $key => $value) {
-            putenv($value === false ? $key : "{$key}={$value}");
+    expect($merged)->toBe(['A' => 'server', 'B' => 'server', 'C' => 'env', 'D' => 'process']);
+});
+
+it('wires the real parent environment and the resolved connection values into the composed env', function (): void {
+    // ★結線そのものを見る (固定値だけを見ると、親環境や接続値を捨てても緑になる)。
+    $parent = pgsqlTestParentEnv();
+    $conn = pgsqlTestConnValues(__DIR__);
+    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+
+    foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
+        $value = $parent[$key] ?? null;
+
+        if (is_string($value) && $value !== '') {
+            expect($env[$key])->toBe($value);          // 親環境由来であること
+        } else {
+            expect($env)->not->toHaveKey($key);
         }
     }
+
+    expect($env['DB_HOST'])->toBe($conn['host'])       // 接続値由来であること
+        ->and($env['DB_PORT'])->toBe($conn['port'])
+        ->and($env['DB_USERNAME'])->toBe($conn['username'])
+        ->and($env['DB_PASSWORD'])->toBe($conn['password']);
 });
 
 // ── pgsqlTestConfigCachePath(): ensure 専用の非既定パス ──
diff --git a/tests/Unit/Support/Process/BootProbeRunnerTest.php b/tests/Unit/Support/Process/BootProbeRunnerTest.php
index 887d5a3e..1d2b64ec 100644
--- a/tests/Unit/Support/Process/BootProbeRunnerTest.php
+++ b/tests/Unit/Support/Process/BootProbeRunnerTest.php
@@ -3,6 +3,8 @@
 declare(strict_types=1);
 
 use Tests\Support\Process\BootProbeRunner;
+use Tests\Support\RawEnv\RawEnvChannels;
+use Tests\Support\RawEnv\RawEnvSnapshot;
 
 /*
 | 起動 probe の共通 runner (`Tests\Support\Process\BootProbeRunner`) の自己検査
@@ -130,15 +132,18 @@ function bootProbeDecodeReport(string $code, array $env = []): array
 }
 
 test('S1: 親の環境変数は子に現れない', function (): void {
-    putenv(BOOT_PROBE_SENTINEL_KEY.'=leaked');
-
-    try {
-        $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);
-
-        expect($report)->not->toHaveKey(BOOT_PROBE_SENTINEL_KEY, '親の env が子へ漏れている');
-    } finally {
-        putenv(BOOT_PROBE_SENTINEL_KEY);
-    }
+    // ★親プロセスの 3 面の退避・注入・復元は `Tests\Support\RawEnv\RawEnvSnapshot` が担う
+    //   (本ファイルは 3 面を直接触らない。正典 raw-env-snapshot-restore v1 の i1)。
+    //   `none()->withProcess()` は指定しなかった 2 面を明示的に未設定にするので、
+    //   「親のプロセス面にだけ在る値」という前提を作れる。
+    RawEnvSnapshot::with(
+        [BOOT_PROBE_SENTINEL_KEY => RawEnvChannels::none()->withProcess('leaked')],
+        function (): void {
+            $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);
+
+            expect($report)->not->toHaveKey(BOOT_PROBE_SENTINEL_KEY, '親の env が子へ漏れている');
+        },
+    );
 });
 
 test('S2: 許可した継承は規則どおり届く (親に無い鍵は子にも無い)', function (): void {
diff --git a/tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php b/tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php
new file mode 100644
index 00000000..4c4ce644
--- /dev/null
+++ b/tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php
@@ -0,0 +1,584 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
+use Illuminate\Support\Env;
+use Tests\Support\RawEnv\RawEnvChannels;
+use Tests\Support\RawEnv\RawEnvGuardStructure;
+use Tests\Support\RawEnv\RawEnvSnapshot;
+
+/*
+ * `Tests\Support\RawEnv\RawEnvSnapshot` の契約テスト
+ * (家系の正典 raw-env-snapshot-restore v1 の i11)。
+ *
+ * ★**このテストは検査対象である部品を使わずに 3 面を触る**。部品で作った状態を部品で
+ *   確かめると同語反復になるためである。したがって前後の掃除も自前で持つ
+ *   (`beforeEach` でプローブキーの 3 面の**元の存在状態と値**を素の `array_key_exists()` /
+ *   `getenv()` で退避し、`afterEach` でその状態へ戻す)。
+ *   これが `RawEnvDirectWriteGateTest` の許可 3 か所のうち「部品の契約テスト」に当たる。
+ *
+ * ★プローブキーは `.env.testing` / `phpunit.xml` / 実 shell が定義しない専用の接頭辞
+ *   (`RAW_ENV_PROBE_`) を使い、**値に phpdotenv の予約語** (`true` / `false` / `null` /
+ *   `(true)` 等) **を使わない** (`env()` がこれらを bool / null / '' へ変換するため
+ *   「文字列がそのまま返る」前提が崩れる)。
+ *   例外は i10 の機序を通すための `APP_LOCALE` で、これは `.env.testing` が実際に宣言している
+ *   ことが load-bearing である (g-0 が前提として pin する)。config は既にロード済みなので
+ *   env を触ってもアプリの振る舞いには影響しない。`DB_*` は部品の拒否対象なので使えない。
+ *
+ * ★`LoadEnvironmentVariables` の再実行で `DB_DATABASE` が `.env.testing` の
+ *   フォールバック値へ変わることは無い — `tests/bootstrap.php` が Laravel boot 前に
+ *   3 面へ注入しており、phpdotenv の immutable writer から見て「外部で定義済み」だからである
+ *   (writer の `$loaded` に載っていない)。
+ *
+ * ── この契約テストが保証するもの / しないもの ────────────────────────────────
+ *
+ * | 契約 | 担保の手段 |
+ * |---|---|
+ * | 第 1 段で拒否されたときは 1 面も書き換わらない | 動的テスト (d-4) |
+ * | 本体が throw しても 3 面が復元される | 動的テスト (c-1) |
+ * | 適用ループの途中で throw してもそこまでの変更が巻き戻る | **構造テストのみ (h-1 / h-2)。動的には未検証** |
+ * | 復元が最初の失敗で止まらず、全キーを戻してからまとめて例外になる | **構造テストのみ (h-3)。動的には未検証** |
+ * | 読み出しの優先順が `$_SERVER` → `$_ENV` → `putenv` である | 動的テスト (f-1〜f-3) |
+ * | `forgetLaravelEnvRepository()` が env の読み直しでの上書きを防ぐ | 動的テスト (g-1〜g-3) |
+ *
+ * 動的に検査していない 2 行は、`putenv()` を検証通過後に失敗させる状況をテストから作れず、
+ * 失敗を注入する差し替え口を新設しない (本番では誰も使わない差し替え口が増えるため) という
+ * 判断による (正典の未決論点 q2)。**動的に保証されたとは書かない**。
+ */
+
+/**
+ * 3 面の状態を退避・復元するプローブキー (宣言が正本)。
+ *
+ * @return list<non-empty-string>
+ */
+function rawEnvContractProbeKeys(): array
+{
+    return ['RAW_ENV_PROBE_ONE', 'RAW_ENV_PROBE_TWO', 'RAW_ENV_PROBE_THREE', 'APP_LOCALE'];
+}
+
+/**
+ * 1 キーの 3 面の状態を素の言語機能で読み出す (部品を使わない)。
+ *
+ * @return array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}
+ */
+function rawEnvContractRead(string $key): array
+{
+    return [
+        'serverExists' => array_key_exists($key, $_SERVER),
+        'server' => $_SERVER[$key] ?? null,
+        'envExists' => array_key_exists($key, $_ENV),
+        'env' => $_ENV[$key] ?? null,
+        'process' => getenv($key),
+    ];
+}
+
+/**
+ * プローブキー全数の 3 面を退避する。
+ *
+ * @return array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>
+ */
+function rawEnvContractCaptureProbes(): array
+{
+    $state = [];
+    foreach (rawEnvContractProbeKeys() as $key) {
+        $state[$key] = rawEnvContractRead($key);
+    }
+
+    return $state;
+}
+
+/**
+ * 退避した 3 面へ戻す (部品を使わない)。
+ *
+ * @param  array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>  $state
+ */
+function rawEnvContractRestoreProbes(array $state): void
+{
+    foreach ($state as $key => $saved) {
+        if ($saved['serverExists']) {
+            $_SERVER[$key] = $saved['server'];
+        } else {
+            unset($_SERVER[$key]);
+        }
+
+        if ($saved['envExists']) {
+            $_ENV[$key] = $saved['env'];
+        } else {
+            unset($_ENV[$key]);
+        }
+
+        if (is_string($saved['process'])) {
+            putenv($key.'='.$saved['process']);
+        } else {
+            putenv($key);
+        }
+    }
+}
+
+/**
+ * ケース間で退避を持ち回る入れ物 (Pest の TestCase へ動的プロパティを生やさない)。
+ *
+ * @param  array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>|null  $store
+ * @return array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>
+ */
+function rawEnvContractProbeSlot(?array $store = null): array
+{
+    /** @var array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}> $slot */
+    static $slot = [];
+
+    if ($store !== null) {
+        $slot = $store;
+    }
+
+    return $slot;
+}
+
+/**
+ * 3 面を直接埋める (部品を使わずに検査の前提状態を作る)。
+ */
+function rawEnvContractSeed(string $key, mixed $server, mixed $env, string $process): void
+{
+    $_SERVER[$key] = $server;
+    $_ENV[$key] = $env;
+    putenv($key.'='.$process);
+}
+
+/** 3 面を直接すべて未設定にする (部品を使わない)。 */
+function rawEnvContractClear(string $key): void
+{
+    unset($_SERVER[$key], $_ENV[$key]);
+    putenv($key);
+}
+
+/**
+ * 変数キーで change set を作る (数値だけのキーが整数へ畳まれる様子をそのまま入力にする)。
+ *
+ * @return array<string, RawEnvChannels>
+ */
+function rawEnvContractChangeWithKey(string $key): array
+{
+    return [$key => RawEnvChannels::none()];
+}
+
+/** env の読み直しだけを起こす (`refreshApplication()` は RefreshDatabase の tx を壊すので使わない)。 */
+function rawEnvContractReloadEnv(): void
+{
+    (new LoadEnvironmentVariables)->bootstrap(app());
+}
+
+/**
+ * i10 の機序を決定的に作る priming。
+ *
+ * immutable writer の `$loaded` に `APP_LOCALE` が載っている状態を**各ケースの中で**作る
+ * (載っているかどうかは直前のテストが repository を捨てたかに依存するため、
+ *  priming をしないと実行順で結果が変わる)。
+ */
+function rawEnvContractPrimeLoadedLocale(): void
+{
+    // 退避は使い捨てにしてよい (外側の afterEach が元の 3 面へ戻す)。
+    RawEnvSnapshot::captureAndClear(['APP_LOCALE']);
+    RawEnvSnapshot::forgetLaravelEnvRepository();
+    rawEnvContractReloadEnv();
+
+    expect(env('APP_LOCALE'))->toBe('en');
+}
+
+beforeEach(function (): void {
+    rawEnvContractProbeSlot(rawEnvContractCaptureProbes());
+    foreach (['RAW_ENV_PROBE_ONE', 'RAW_ENV_PROBE_TWO', 'RAW_ENV_PROBE_THREE'] as $key) {
+        rawEnvContractClear($key);
+    }
+});
+
+afterEach(function (): void {
+    rawEnvContractRestoreProbes(rawEnvContractProbeSlot());
+    // repository の `$loaded` の状態を次のケースへ持ち越さない。
+    RawEnvSnapshot::forgetLaravelEnvRepository();
+});
+
+// ── (a) 3 面の存在状態と値が食い違う状態の往復 ──
+
+test('a-1: 3 面の存在状態が食い違う状態を面ごとに独立して戻す', function (): void {
+    $key = 'RAW_ENV_PROBE_ONE';
+    $_SERVER[$key] = 'server-only';
+    putenv($key.'=');   // 空文字で設定 ($_ENV は未設定のまま)
+
+    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('replaced')], function () use ($key): void {
+        expect($_SERVER[$key])->toBe('replaced')
+            ->and($_ENV[$key])->toBe('replaced')
+            ->and(getenv($key))->toBe('replaced');
+    });
+
+    expect(rawEnvContractRead($key))->toBe([
+        'serverExists' => true,
+        'server' => 'server-only',
+        'envExists' => false,
+        'env' => null,
+        'process' => '',
+    ]);
+});
+
+test('a-2: 「存在するが値が null」を「存在しない」へ潰さない', function (): void {
+    $key = 'RAW_ENV_PROBE_TWO';
+    $_SERVER[$key] = null;
+
+    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('x')], function (): void {
+        // 本体は何もしない (往復そのものが検査対象)。
+    });
+
+    expect(array_key_exists($key, $_SERVER))->toBeTrue()
+        ->and($_SERVER[$key])->toBeNull();
+});
+
+test('a-3: 非文字列 (配列) を入れた面が同じ値のまま戻る', function (): void {
+    $key = 'RAW_ENV_PROBE_THREE';
+    $_ENV[$key] = ['nested' => ['deep']];
+
+    RawEnvSnapshot::with([$key => RawEnvChannels::none()->withServer('only-server')], function () use ($key): void {
+        expect(array_key_exists($key, $_ENV))->toBeFalse();
+    });
+
+    expect($_ENV[$key])->toBe(['nested' => ['deep']]);
+});
+
+// ── (b) 空文字・等号を含む値・未設定の往復 ──
+
+test('b-1: 等号を含む値と空文字の往復 (putenv の値が壊れない)', function (): void {
+    $key = 'RAW_ENV_PROBE_ONE';
+    putenv($key.'=a=b');
+
+    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('')], function () use ($key): void {
+        expect(getenv($key))->toBe('');
+    });
+
+    expect(getenv($key))->toBe('a=b');
+});
+
+test('b-2: 元から未設定のキーは実行後も 3 面とも未設定へ戻る', function (): void {
+    $key = 'RAW_ENV_PROBE_TWO';
+
+    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('temp')], function () use ($key): void {
+        expect(getenv($key))->toBe('temp');
+    });
+
+    expect(rawEnvContractRead($key))->toBe([
+        'serverExists' => false,
+        'server' => null,
+        'envExists' => false,
+        'env' => null,
+        'process' => false,
+    ]);
+});
+
+// ── (c) 本体の例外 ──
+
+test('c-1: 本体が例外を投げても 3 面が復元される', function (): void {
+    $key = 'RAW_ENV_PROBE_ONE';
+    rawEnvContractSeed($key, 'orig-server', 'orig-env', 'orig-process');
+
+    $thrown = null;
+
+    try {
+        RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('temp')], function (): void {
+            throw new DomainException('body failed');
+        });
+    } catch (DomainException $e) {
+        $thrown = $e;
+    }
+
+    expect($thrown)->toBeInstanceOf(DomainException::class)
+        ->and(rawEnvContractRead($key))->toBe([
+            'serverExists' => true,
+            'server' => 'orig-server',
+            'envExists' => true,
+            'env' => 'orig-env',
+            'process' => 'orig-process',
+        ]);
+});
+
+// ── (d) 検証で拒否する ──
+
+test('d-1: 不正なキーは第 1 段で拒否される', function (string $key): void {
+    expect(fn (): mixed => RawEnvSnapshot::with(
+        rawEnvContractChangeWithKey($key),
+        fn (): int => 1,
+    ))->toThrow(InvalidArgumentException::class);
+})->with([
+    'empty' => [''],
+    'contains equals' => ['RAW_ENV_PROBE_ONE=x'],
+    'contains NUL' => ["RAW_ENV_PROBE_ONE\0X"],
+    'numeric string folded into an integer key' => ['0'],
+]);
+
+test('d-2: 単一点の守りが前提にするキーは拒否される', function (string $key): void {
+    expect(fn (): mixed => RawEnvSnapshot::with(
+        rawEnvContractChangeWithKey($key),
+        fn (): int => 1,
+    ))->toThrow(InvalidArgumentException::class);
+
+    expect(fn (): RawEnvSnapshot => RawEnvSnapshot::captureAndClear([$key]))
+        ->toThrow(InvalidArgumentException::class);
+})->with([
+    'DB_DATABASE' => ['DB_DATABASE'],
+    'DB_CONNECTION' => ['DB_CONNECTION'],
+    'DB_URL' => ['DB_URL'],
+    'TEST_TOKEN' => ['TEST_TOKEN'],
+    'APP_CONFIG_CACHE' => ['APP_CONFIG_CACHE'],
+]);
+
+test('d-3: process 面の値に NUL があれば第 1 段で拒否される', function (): void {
+    expect(fn (): mixed => RawEnvSnapshot::with(
+        ['RAW_ENV_PROBE_ONE' => RawEnvChannels::none()->withProcess("a\0b")],
+        fn (): int => 1,
+    ))->toThrow(InvalidArgumentException::class);
+});
+
+test('d-4: 拒否キーを 2 番目に置いても先行キーの 3 面が 1 面も変わらない (閉包の口)', function (): void {
+    $key = 'RAW_ENV_PROBE_ONE';
+    rawEnvContractSeed($key, 'orig-server', 'orig-env', 'orig-process');
+
+    $changes = [
+        $key => RawEnvChannels::sameOnAllSurfaces('should-not-be-applied'),
+        'DB_DATABASE' => RawEnvChannels::sameOnAllSurfaces('app'),
+    ];
+
+    expect(fn (): mixed => RawEnvSnapshot::with($changes, fn (): int => 1))
+        ->toThrow(InvalidArgumentException::class);
+
+    expect(rawEnvContractRead($key))->toBe([
+        'serverExists' => true,
+        'server' => 'orig-server',
+        'envExists' => true,
+        'env' => 'orig-env',
+        'process' => 'orig-process',
+    ]);
+});
+
+test('d-4b: 拒否キーを 2 番目に置いても先行キーの 3 面が 1 面も変わらない (持ち回りの口)', function (): void {
+    $key = 'RAW_ENV_PROBE_ONE';
+    rawEnvContractSeed($key, 'orig-server', 'orig-env', 'orig-process');
+
+    expect(fn (): RawEnvSnapshot => RawEnvSnapshot::captureAndClear([$key, 'DB_DATABASE']))
+        ->toThrow(InvalidArgumentException::class);
+
+    expect(rawEnvContractRead($key))->toBe([
+        'serverExists' => true,
+        'server' => 'orig-server',
+        'envExists' => true,
+        'env' => 'orig-env',
+        'process' => 'orig-process',
+    ]);
+});
+
+test('d-5: 拒否されたとき本体は 1 度も呼ばれない', function (): void {
+    $calls = 0;
+
+    expect(fn (): mixed => RawEnvSnapshot::with(
+        ['DB_DATABASE' => RawEnvChannels::sameOnAllSurfaces('app')],
+        function () use (&$calls): int {
+            $calls++;
+
+            return 1;
+        },
+    ))->toThrow(InvalidArgumentException::class);
+
+    expect($calls)->toBe(0);
+});
+
+// ── (e) 入れ子 ──
+
+test('e-1: 同一キーの入れ子で内側の復元が外側の適用値へ戻る', function (): void {
+    $key = 'RAW_ENV_PROBE_ONE';
+    rawEnvContractSeed($key, 'orig', 'orig', 'orig');
+
+    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('outer')], function () use ($key): void {
+        RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('inner')], function () use ($key): void {
+            expect(getenv($key))->toBe('inner');
+        });
+
+        expect($_SERVER[$key])->toBe('outer')
+            ->and($_ENV[$key])->toBe('outer')
+            ->and(getenv($key))->toBe('outer');
+    });
+
+    expect(getenv($key))->toBe('orig');
+});
+
+// ── (f) 読み出しの優先順 ──
+
+test('f-1: 3 面とも設定なら env() は $_SERVER を読む', function (): void {
+    RawEnvSnapshot::with([
+        'RAW_ENV_PROBE_ONE' => RawEnvChannels::none()
+            ->withServer('from-server')
+            ->withEnv('from-env')
+            ->withProcess('from-process'),
+    ], function (): void {
+        expect(env('RAW_ENV_PROBE_ONE'))->toBe('from-server');
+    });
+});
+
+test('f-2: $_SERVER だけ未設定なら env() は $_ENV を読む', function (): void {
+    RawEnvSnapshot::with([
+        'RAW_ENV_PROBE_ONE' => RawEnvChannels::none()
+            ->withEnv('from-env')
+            ->withProcess('from-process'),
+    ], function (): void {
+        expect(env('RAW_ENV_PROBE_ONE'))->toBe('from-env');
+    });
+});
+
+test('f-3: $_SERVER と $_ENV が未設定なら env() は putenv 面を読む', function (): void {
+    RawEnvSnapshot::with([
+        'RAW_ENV_PROBE_ONE' => RawEnvChannels::none()->withProcess('from-process'),
+    ], function (): void {
+        expect(env('RAW_ENV_PROBE_ONE'))->toBe('from-process');
+    });
+});
+
+test('f-4: 指定しなかった面は明示的に未設定になる', function (): void {
+    $key = 'RAW_ENV_PROBE_ONE';
+    rawEnvContractSeed($key, 'orig', 'orig', 'orig');
+
+    RawEnvSnapshot::with([$key => RawEnvChannels::none()->withServer('only-server')], function () use ($key): void {
+        expect($_SERVER[$key])->toBe('only-server')
+            ->and(array_key_exists($key, $_ENV))->toBeFalse()
+            ->and(getenv($key))->toBeFalse();
+    });
+});
+
+// ── (g) env 読み出し口の作り直し (i10 / 正典 q3) ──
+
+test('g-0: 前提の pin — .env.testing が APP_LOCALE を宣言している', function (): void {
+    $declaration = file_get_contents(base_path('.env.testing'));
+
+    expect($declaration)->toBeString();
+    expect(preg_match('/^APP_LOCALE=en$/m', (string) $declaration))->toBe(1);
+});
+
+test('g-1: 口の前後で Env の repository のインスタンス同一性が変わる', function (): void {
+    $before = Env::getRepository();
+    RawEnvSnapshot::forgetLaravelEnvRepository();
+    $after = Env::getRepository();
+
+    expect($after)->not->toBe($before);
+});
+
+test('g-2: 口を呼ばずに読み直すと .env.testing の値で上書きされる (機序の観測)', function (): void {
+    rawEnvContractPrimeLoadedLocale();
+
+    RawEnvSnapshot::with(['APP_LOCALE' => RawEnvChannels::sameOnAllSurfaces('zz')], function (): void {
+        rawEnvContractReloadEnv();
+
+        expect(env('APP_LOCALE'))->toBe('en');
+    });
+});
+
+test('g-3: 口を呼んでから読み直すと 3 面へ入れた値が維持される', function (): void {
+    rawEnvContractPrimeLoadedLocale();
+
+    RawEnvSnapshot::with(['APP_LOCALE' => RawEnvChannels::sameOnAllSurfaces('zz')], function (): void {
+        RawEnvSnapshot::forgetLaravelEnvRepository();
+        rawEnvContractReloadEnv();
+
+        expect(env('APP_LOCALE'))->toBe('zz');
+    });
+});
+
+// ── (i) 持ち回りの口 ──
+
+test('i-1: captureAndClear() が 3 面を未設定にし restore() で元へ戻る', function (): void {
+    $key = 'RAW_ENV_PROBE_ONE';
+    rawEnvContractSeed($key, 'orig-server', 'orig-env', 'orig-process');
+
+    $snapshot = RawEnvSnapshot::captureAndClear([$key]);
+
+    expect(rawEnvContractRead($key))->toBe([
+        'serverExists' => false,
+        'server' => null,
+        'envExists' => false,
+        'env' => null,
+        'process' => false,
+    ]);
+
+    $snapshot->restore();
+
+    expect(rawEnvContractRead($key))->toBe([
+        'serverExists' => true,
+        'server' => 'orig-server',
+        'envExists' => true,
+        'env' => 'orig-env',
+        'process' => 'orig-process',
+    ]);
+});
+
+test('i-2: $changes に現れないキーには一切触れない', function (): void {
+    rawEnvContractSeed('RAW_ENV_PROBE_TWO', 'untouched', 'untouched', 'untouched');
+
+    RawEnvSnapshot::with(
+        ['RAW_ENV_PROBE_ONE' => RawEnvChannels::sameOnAllSurfaces('x')],
+        function (): void {
+            expect(rawEnvContractRead('RAW_ENV_PROBE_TWO'))->toBe([
+                'serverExists' => true,
+                'server' => 'untouched',
+                'envExists' => true,
+                'env' => 'untouched',
+                'process' => 'untouched',
+            ]);
+        },
+    );
+
+    expect(rawEnvContractRead('RAW_ENV_PROBE_TWO'))->toBe([
+        'serverExists' => true,
+        'server' => 'untouched',
+        'envExists' => true,
+        'env' => 'untouched',
+        'process' => 'untouched',
+    ]);
+});
+
+test('with() は本体の戻り値をそのまま返す', function (): void {
+    $result = RawEnvSnapshot::with(
+        ['RAW_ENV_PROBE_ONE' => RawEnvChannels::sameOnAllSurfaces('x')],
+        fn (): array => ['value' => getenv('RAW_ENV_PROBE_ONE')],
+    );
+
+    expect($result)->toBe(['value' => 'x']);
+});
+
+// ── (h) 構造の固定 (正典の未決論点 q2 の代替。動的には検査できない性質を構造で pin する) ──
+
+test('h-1: 閉包の口は「適用が try の中・復元が finally・本体の例外を連結して再送出」の構造である', function (): void {
+    $tokens = RawEnvGuardStructure::methodTokens(RawEnvSnapshot::class, 'with');
+    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
+    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);
+    $finally = RawEnvGuardStructure::soleBlockRange($tokens, T_FINALLY);
+
+    $loops = RawEnvGuardStructure::foreachOverExpression($tokens, ['$changes']);
+    expect($loops)->toHaveCount(1)
+        ->and(RawEnvGuardStructure::isWithin($catch, $loops[0]))->toBeFalse()
+        ->and(RawEnvGuardStructure::isWithin($finally, $loops[0]))->toBeFalse();
+
+    expect(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$changes'], $try, 'apply'))->toBeTrue()
+        ->and(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $finally, '$snapshot', 'restore', 0, ['$bodyError']))->toBeTrue()
+        ->and(RawEnvGuardStructure::variableAssignmentMatches($tokens, $catch, '$bodyError', ['$e']))->toBeTrue()
+        ->and(RawEnvGuardStructure::soleThrowMatches($tokens, $catch, ['$e']))->toBeTrue();
+});
+
+test('h-2: 持ち回りの口は「未設定化が try の中・復元と再送出が catch」の構造である', function (): void {
+    $tokens = RawEnvGuardStructure::methodTokens(RawEnvSnapshot::class, 'captureAndClear');
+    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
+    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);
+
+    expect(RawEnvGuardStructure::findTokens($tokens, T_FINALLY))->toBe([])
+        ->and(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$keys'], $try, 'apply'))->toBeTrue()
+        ->and(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $catch, '$snapshot', 'restore', 0, ['$e']))->toBeTrue()
+        ->and(RawEnvGuardStructure::indexesWithin(RawEnvGuardStructure::controlFlowTokens($tokens, T_THROW), $catch))->toHaveCount(1);
+});
+
+test('h-3: 復元は「途中終了せず蓄積し、ループの後で 1 度だけ送出する」構造で例外を連結する', function (): void {
+    $tokens = RawEnvGuardStructure::methodTokens(RawEnvSnapshot::class, 'restore');
+
+    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeTrue()
+        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, 'RuntimeException', 2, ['$previous']))->toBeTrue();
+});

```

## テスト結果

- `composer test` (全数 7820 tests): 本差分に関係する新規・変更テストはすべて green。
  - `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php`: 34 passed
  - `tests/Unit/Architecture/RawEnvGuardStructureTest.php`: 20 passed
  - `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php`: 36 passed
  - `tests/Architecture/RawEnvDirectWriteGateTest.php`: 13 passed
  - `tests/Feature/Support/ProductionEnvGuardTest.php`: 48 passed
  - `tests/Feature/Config/ConfigHardeningTest.php`: 21 passed
  - `tests/Feature/Auth/PasskeyOriginDeclarationTest.php`: 5 passed
  - `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php`: 32 passed
  - `tests/Unit/Support/Process/BootProbeRunnerTest.php`: 14 passed
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages`: すべて green

## 実装手順で実際に見た赤 (テストファースト)

- 段 1: 契約テストがクラス未定義で赤 (31 tests / 13 failed + 18 errors) → 段 2 で緑
- 段 3: 構造走査器の自己検査が赤 (20 tests / 全滅) → 段 4 で緑
- 段 4 の直後、`with()` の適用 foreach を try の外へ一時的に出して h-1 が赤になることを確認し戻した
- 段 5: 直接書き込み走査器の自己検査が赤 (36 tests / 全滅) → 段 6 で緑
- 段 7: gate の G1 が **5 ファイル**の違反で赤 (設計が予期した 4 本 +
  main に後から入った `tests/Unit/Support/Process/BootProbeRunnerTest.php`)。
  G8 も件数不一致で赤 → 段 8/9 で違反 0 件、段 10 で件数 pin を実測値へ
- 段 10 の後、目録の件数を 1 増やした場合・1 減らした場合の両方で G8 が赤くなることを確認した

## 設計からの意図的な差分 (レビューで見てほしい点)

1. **違反ファイルが 4 本ではなく 5 本だった**。設計執筆後に main へ入った T249 の
   `tests/Unit/Support/Process/BootProbeRunnerTest.php` が `putenv()` を親プロセスへ立てていたため、
   同じコミットで `RawEnvSnapshot::with()` へ移送した (許可 3 か所は増やしていない)。
2. **乖離台帳の D 番号は D50 ではなく D53**、`DIVERGENCE_ENTRY_COUNT` は 46→47 ではなく **49→50**、
   契約文書のゲート件数は 13→14 (docblock の 21 件→22 件) である。
   いずれも main の最新値から再確定した (設計の「実装時に再確定する 3 点」に従った)。
3. **`RawEnvGuardStructure` の API を設計の一覧より広げた**。設計は素の走査プリミティブだけを
   挙げていたが、判定 (「適用のループが try の中にあり、その本体に self::apply が 1 件」など) を
   契約テストと自己検査の 2 か所に書くと必ず食い違うため、判定そのものを純関数
   (`applyLoopIsGuarded` / `restoreStructureIsDeferred` / `*Matches`) として走査器側へ置き、
   両方のテストが同じ関数を呼ぶ形にした。
