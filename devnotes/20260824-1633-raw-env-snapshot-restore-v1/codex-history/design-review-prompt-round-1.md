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

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【本件の性質 (前提)】
本件はアプリ本体ではなく**テスト基盤**の改善であり、家系の機能台帳 (lctl) が確定した正典 v1
(不変条件 i1-i12) への追従である。UI/frontend の変更は無いので観点 10 / 11 は該当しない。
正典の不変条件は動かせない前提として扱ってほしい。
概念設計は別セッションで APPROVED 済みで、本レビューは**詳細設計 (実装可能性の水準)** が対象である。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

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
| 1 | 3 面の退避・注入・復元を担う部品の新設と、その契約テスト (i1–i11) | `tests/Support/RawEnv/RawEnvChannels.php` (新) / `tests/Support/RawEnv/RawEnvSnapshot.php` (新) / `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php` (新) | 高 |
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
- 新規: `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php`

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
 *    `RawEnvDirectWriteGateTest` が deny-by-default で強制する)。
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
 * ── 保証しないもの (誇張しない) ─────────────────────────────────────────
 *
 *  - **適用の途中で `putenv()` が失敗したときの巻き戻りは動的には検査していない**
 *    (検証を通ったキーで `putenv()` を失敗させる状況をテストから作れない)。
 *    構造の固定で代えている (契約テストの構造検査)。
 *  - `$overrides` / `$keys` に**現れないキーには一切触れない**。
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
     * @param  list<array{key: non-empty-string, serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>  $state
     */
    private function __construct(private readonly array $state) {}

    /**
     * 3 面を差し替えて閉包を実行し、**成否によらず**元の存在状態と値へ戻す。
     *
     * @template TReturn
     *
     * @param  array<non-empty-string, RawEnvChannels>  $changes
     * @param  Closure(): TReturn  $body
     * @return TReturn
     *
     * @throws InvalidArgumentException キーが不正 / 拒否対象の場合 (第 1 段。1 面も触っていない)
     * @throws RuntimeException `putenv()` が false を返した場合 (復元は行われる)
     */
    public static function with(array $changes, Closure $body): mixed
    {
        // --- 第 1 段: 検証 (この時点では何も触らない) ---
        self::assertKeysAllowed(array_keys($changes));

        /** @var list<non-empty-string> $keys */
        $keys = array_keys($changes);

        // --- 第 2 段: 退避 (この時点でも何も変えない) ---
        $snapshot = self::capture($keys);

        // --- 第 3 段: 適用 + 本体 (適用途中の失敗も finally で巻き戻る) ---
        try {
            foreach ($changes as $key => $channels) {
                self::apply((string) $key, $channels);
            }

            return $body();
        } finally {
            $snapshot->restore();
        }
    }

    /**
     * 指定キーの 3 面を退避し、そのうえで 3 面とも未設定にする。
     * 復元は呼び出し側が枠組みの後処理フックから `restore()` を呼んで行う。
     *
     * @param  list<non-empty-string>  $keys
     *
     * @throws InvalidArgumentException キーが不正 / 拒否対象の場合 (1 面も触っていない)
     * @throws RuntimeException `putenv()` が false を返した場合 (**その場で巻き戻してから**送出する)
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
            $snapshot->restore();

            throw $e;
        }

        return $snapshot;
    }

    /** 退避した 3 面を、元の存在状態と値へ戻す (面ごとに独立して戻す)。 */
    public function restore(): void
    {
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
                throw new RuntimeException("putenv() failed while restoring env key [{$key}].");
            }
        }
    }

    /**
     * **`refreshApplication()` の直前に呼ぶ。** 3 面へ入れた値が `.env.testing` の値で
     * 上書きされるのを防ぐ (正典 v1 の i10)。
     *
     * phpdotenv の immutable writer は「既に定義済みの変数は上書きしない」を
     * **自分が書いたかどうか**で判定し、その writer は `Illuminate\Support\Env::$repository` に
     * **プロセス静的**で保持される。したがって 1 度目の boot で `.env.testing` が書いたキーは
     * 作り直しのたびに `.env.testing` の値で上書きされる。repository を捨てると
     * 空の writer が作り直され、3 面に在る値が「外部で定義済み」として尊重される。
     *
     * ★**依拠している副作用 (監視条件)**: `Env::enablePutenv()` は本来
     *   putenv アダプタを有効化する API だが、その実装が `static::$repository = null` を
     *   伴うことに依拠している (実測: laravel/framework の `Illuminate\Support\Env`)。
     *   本リポジトリは `disablePutenv()` を呼ばないので、副作用は repository の作り直しだけである。
     *   **上流の版を上げてこの副作用が消えたら、i10 の手段を再評価すること**
     *   (家系の正典 v1 の未決論点 q3)。副作用が生きていること自体は契約テスト (g) が
     *   実行時に固定する — docblock の監視条件だけでは「緑のまま保証だけ失われる」を検出できない。
     */
    public static function forgetLaravelEnvRepository(): void
    {
        Env::enablePutenv();
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
     * @param  list<non-empty-string>  $keys
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

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`with()` は `@template TReturn` で `mixed` を絞る）
- [x] null 安全（キー検査は `Webmozart\Assert\Assert` / 非文字列キーは明示的に例外）
- [x] `mixed` が現れるのは値のフィールドの中だけで、口の引数・戻り値は型が付く
- [x] `putenv()` の戻り値 `false` を握り潰さない（`false` のまま扱う分岐が残らない）
- [x] Generics の型パラメータ（`array<non-empty-string, RawEnvChannels>` / `list<...>`）が正しい
- [x] `Env::enablePutenv()` は `void` を返すので戻り値の未使用警告が出ない

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
| d-1 | 拒否キー（`DB_DATABASE` / `TEST_TOKEN` / `APP_CONFIG_CACHE` / 空 / `=` 入り / NUL 入り / 整数キー）で例外になる | i11 (d) / i8 / i9 |
| d-2 | **拒否キーを 2 番目以降に置いても、先行キーの 3 面が 1 面も変わっていない**（閉包の口 / 持ち回りの口の両方） | i11 (d) / i6 |
| d-3 | 拒否されたとき本体（閉包）が 1 度も呼ばれていない | i11 (d) |
| e-1 | 同一キーの入れ子で、内側の復元が**外側の適用値**へ戻る（呼び出し前の状態へ飛ばない） | i11 (e) |
| f-1 | `env()` は 3 面とも設定なら `$_SERVER` を読む | i11 (f) / i2 |
| f-2 | `$_SERVER` だけ未設定なら `$_ENV` を読む | i11 (f) / i2 |
| f-3 | `$_SERVER` と `$_ENV` が未設定なら `putenv` 面を読む | i11 (f) / i2 |
| f-4 | 指定しなかった面が明示的に未設定になる（`RawEnvChannels::none()->withServer(...)` で `$_ENV` / `getenv` が未設定） | i7 |
| g-1 | `forgetLaravelEnvRepository()` の前後で `Env::getRepository()` のインスタンス同一性が変わる | i10 / q3 |
| g-2 | 口を呼んだあと `env()` が 3 面へ入れた値を読み、復元後は元の値へ戻る | i10 |
| h-1 | **構造の固定（閉包の口）**: 適用の `foreach` が `try` の本体にあり、`restore()` の呼び出しが `finally` の本体にある | q2 の代替 |
| h-2 | **構造の固定（持ち回りの口）**: 未設定化の `foreach` が `try` の本体にあり、`restore()` の呼び出しと再送出が `catch` の本体にある | q2 の代替 |
| i-1 | 持ち回りの口: `captureAndClear()` が 3 面を未設定にし、`restore()` で元へ戻る | i5 (b) |
| i-2 | `$changes` に現れないキーには一切触れない | i3 |

**保証範囲の明記（ファイル冒頭の docblock。表で書く）**

| 契約 | 担保の手段 |
|---|---|
| 第 1 段で拒否されたときは 1 面も書き換わらない | 動的テスト (d-2) |
| 本体が throw しても 3 面が復元される | 動的テスト (c-1) |
| **適用ループの途中で throw してもそこまでの変更が巻き戻る** | **構造テストのみ (h-1 / h-2)。動的には未検証** |
| 読み出しの優先順が `$_SERVER` → `$_ENV` → `putenv` である | 動的テスト (f-1〜f-3)。上流の adapter 構成が変われば赤くなる（望ましい fail） |
| `forgetLaravelEnvRepository()` が repository を作り直す | 動的テスト (g-1)。上流が副作用を変えたら赤くなる |

**プローブキーの選び方（load-bearing）**: `.env.testing` / `phpunit.xml` / 実 shell が
定義しない専用の接頭辞（`RAW_ENV_PROBE_`）を使い、**値に phpdotenv の予約語
（`true` / `false` / `null` / `(true)` 等）を使わない**（`env()` がこれらを bool / null / `''` へ
変換するため「文字列がそのまま返る」前提が崩れる）。

**このテストは検査対象である部品を使わずに 3 面を触る**ので、前後の掃除を自前で持つ
（`beforeEach` と `afterEach` の両方でプローブキーを 3 面とも消す。`afterEach` だけだと
前のテストが落ちたときの残骸を引き継ぐ）。これが i12 の許可 3 か所のうち
「部品の契約テスト」に当たる。

### リスク

- **`Env::enablePutenv()` の副作用が上流で変わる**（正典 q3）。→ g-1 が赤くなる。
  docblock の監視条件に従って手段を再評価する。
- **プローブキーが実行環境に存在する**と f 系が揺れる。→ 専用接頭辞と、前後の掃除で潰す。
- **構造テストは意図的に脆い**（整形や実装の書き換えで赤くなる）。→ 赤くなったら判定を
  緩めるのではなく、構造が本当に変わってよいのかを確認する旨を docblock に書く。

---

## 施策 2: 走査器と gate の新設 (i12)

### 変更箇所

- 新規: `tests/Support/RawEnv/RawEnvWriteKind.php`（enum）
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
| `element_assign` | 面の要素への代入（通常 / 複合 / `??=` / 前後置インクリメント） | `$_SERVER['K'] = …` / `$_ENV['K'] .= …` / `$_SERVER['K'] ??= …` / `$_ENV['K']++` |
| `element_unset` | 面の要素の削除 | `unset($_SERVER['K'], $_ENV['K'])` |
| `whole_assign` | 面そのものへの代入 | `$_SERVER = […]` |
| `reference_taken` | 面 / 面の要素への参照の取得 | `&$_SERVER['K']` / `&$_ENV` |
| `putenv` | プロセス面への書き込み（両形） | `putenv('K=V')` / `putenv('K')` |
| `unresolved` | 分類できなかった出現（**必ず違反**。目録へ登録できない） | 後述 |

#### 関数名の解決（AGENTS.md 走査器共通規約 (a)）

`putenv` は**完全修飾名で突き合わせる**。短名一致は使わない（別名つき取り込み 1 つで
検査が黙るため）。ファイルごとに次を先に組み立てる:

1. `namespace` 宣言の有無
2. `use function` の取り込み対応表（`use function putenv;` / `use function putenv as alias;` /
   group use `use function Foo\{bar, baz as qux};` を解いた **別名 → 完全修飾名**）
3. そのファイルが**自分で `putenv` という名前の関数を宣言しているか**

判定:

- `T_NAME_FULLY_QUALIFIED` の `\putenv` → 一致
- 取り込み対応表で完全修飾名が `putenv` になる別名 → 一致
- 非修飾の `putenv`（名前空間の中でもグローバルへ fallback する）→ 一致。
  ただし **(3) が真なら「未解決」**（そのファイルのローカル関数を指す可能性があるため）
- `T_NAME_QUALIFIED`（`Foo\putenv`）で完全修飾名が `putenv` にならないもの → 不一致
- 直前が `->` / `?->` / `::` / `function` / `new` / `const` → 不一致（メソッド・宣言・定数）
- 直後が `(` でない → 不一致
- **`use function` の取り込みを完全修飾名へ解けない形** / **呼び出し先が変数・式** → **未解決**

#### 面（`$_SERVER` / `$_ENV`）の分類

`T_VARIABLE` の `$_SERVER` / `$_ENV` について:

- 直前が `&` → `reference_taken`
- `unset(` の引数リストの中（`T_UNSET` の直後の丸括弧を深さで追跡）→ `element_unset`
- 直後が `[` なら、**連続する添字の連鎖をすべて読み飛ばして**から次の有意トークンを見る:
  - 代入系（`=` / `.=` / `+=` / `-=` / `*=` / `/=` / `%=` / `**=` / `??=` / `|=` / `&=` / `^=` / `<<=` / `>>=`）→ `element_assign`
  - `++` / `--`（前置は直前で判定）→ `element_assign`
  - それ以外 → 読み出し（記録しない）
- 直後が `[` でない（面そのものの出現）:
  - 直後が `=`（`==` / `===` / `=>` ではない）→ `whole_assign`
  - 直前が `(` または `,` かつ直後が `)` または `,` → 読み出し（値渡しの引数。記録しない）
  - `foreach` の頭で直後が `as` → 読み出し（記録しない）
  - **それ以外 → `unresolved`**

#### 保証しないもの（正本は走査器の docblock。本設計にも要旨を写す）

- 名前を実行時に解決する書き込み（可変変数 / `extract()` / 文字列から関数を呼ぶ形）
- 面を**値渡しで受けた関数**が内部で書き換える形（`foo($_SERVER)` の呼び先）
- **分割代入の左辺**に面の要素を置く形（`[$_SERVER['K']] = …`）
- `Dotenv` のような**ライブラリ経由の間接的な書き込み**
- ヒアドキュメント / ナウドキュメントの本文（`token_get_all()` からは 1 トークンに見える。
  **実測で確認済み**であり、走査器の自己検査が負例をナウドキュメントで持てる理由でもある）
- `devnotes/` 配下（母集団から外している）

これらの構文で保護対象の操作を書ける場合があるため、**gate は検出力の主張を
「字句として現れる上記の書き込み形」に明示的に狭める**（AGENTS.md 走査器共通規約 (b) の
「保証範囲の外にする構文は docblock へ明記し、その構文について検出力を主張しない」に従う）。

### gate の設計（`tests/Architecture/RawEnvDirectWriteGateTest.php`）

```php
/** 許可の根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const RAW_ENV_DIRECT_WRITE_REASON_MIN_LENGTH = 30;

/** 走査対象ファイル数の床値 (走査が空振り 0 件でも「違反 0 件」で緑になるのを止める)。 */
const RAW_ENV_DIRECT_WRITE_SCANNED_FILE_FLOOR = 1900;

/** 許可する置き場の件数。**3 か所ちょうど** (正典 v1 の i12 が許す (a)(b)(c))。 */
const RAW_ENV_DIRECT_WRITE_ALLOWANCE_COUNT = 3;

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
| G1 | 走査対象に 3 面への直接の書き込みが無い（目録の登録分を差し引く） | 違反そのもの |
| G2 | 走査ファイル数が床値以上 | 空振りの検出（(b)「母集団が 0 件」と「違反が 0 件」の区別） |
| G3 | **走査対象数 + 除外数 = 追跡 PHP 総数** | どこにも分類されず黙って落ちるファイルが無いこと |
| G4 | 除外集合が `devnotes/` 配下と完全一致 | 除外が広がっていないこと |
| G5 | `devnotes/` に追跡 PHP が実在する | 除外の形骸化の検出 |
| G6 | 目録の登録先ファイルが実在する | 形骸化した登録を残さない |
| G7 | 目録に `unresolved` を登録していない | 未解決を免除で黙らせない（fail-closed の担保） |
| G8 | 目録の実測件数が登録件数と完全一致（増減とも赤） | 無断の増加を止める |
| G9 | 目録の根拠が 30 文字以上 | 「同上」を弾く |
| G10 | 目録の登録件数がちょうど 3 | 許可箇所を増やさない |
| G11 | 代表パス（部品 / 契約テスト / `tests/bootstrap.php`）が母集団に実在する | 走査根が生きていること |

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
| 正例 1 | `$_SERVER['K'] = 'v';` / `$_ENV['K'] .= 'v';` / `$_SERVER['K'] ??= 'v';` / `$_ENV['K']++;` | `element_assign` を検出 |
| 正例 2 | `unset($_SERVER['K'], $_ENV['K']);` | `element_unset` を 2 件検出 |
| 正例 3 | `$_SERVER = [];` | `whole_assign` |
| 正例 4 | `$r = &$_SERVER['K'];` | `reference_taken` |
| 正例 5 | `putenv('K=V');` / `putenv('K');` | `putenv` |
| 正例 6 | `\putenv('K=V');` | `putenv`（完全修飾） |
| 正例 7 | `use function putenv as setRawEnv;` + `setRawEnv('K=V');` | `putenv`（別名解決） |
| 正例 8 | `use function Acme\{putenv as p2};` + `p2('K=V');` | **不一致**（完全修飾名が `\putenv` にならない） |
| 負例 1 | `$_SERVER['K'] ?? null` / `foreach ($_SERVER as $k => $v)` / `f($_SERVER)` | 検出しない |
| 負例 2 | `$x->putenv('K=V');` / `X::putenv('K=V');` | 検出しない |
| 負例 3 | `myputenv('K=V');` / `not_putenv('K=V');` / `putenv_safe('K=V');` | 検出しない（**接頭辞・打ち消し・接尾辞の 3 形**。AGENTS.md 走査器共通規約 (e)） |
| 負例 4 | 文字列リテラル `'putenv($_SERVER)'` / コメント中の同じ綴り | 検出しない |
| 未解決 1 | `$fn = 'putenv'; $fn('K=V');` | `unresolved` |
| 未解決 2 | 名前空間の中で `function putenv() {}` を宣言したうえでの非修飾呼び出し | `unresolved` |
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

// 残すのは 2 本だけ (対象変数の宣言と、フックをまたぐ退避の入れ物)。
// 退避・復元・消去・閉包の 5 本は削除し、部品へ寄せる。

/**
 * ケース間で共有する退避先 (Pest の TestCase へ動的プロパティを生やさない)。
 * ★ここが持つのは**部品が作った退避の値**だけで、退避・復元のロジックは持たない。
 */
function productionEnvGuardRawSnapshot(?RawEnvSnapshot $set = null): ?RawEnvSnapshot
{
    static $snapshot = null;

    if ($set !== null) {
        $snapshot = $set;
    }

    return $snapshot;
}

beforeEach(function (): void {
    productionEnvGuardRawSnapshot(
        RawEnvSnapshot::captureAndClear(productionEnvGuardFakeFlagVariables()),
    );
    // ... config baseline (変更なし)
});

afterEach(function (): void {
    productionEnvGuardRawSnapshot()?->restore();
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
 * @param  array<non-empty-string, string|null>  $env  null は 3 面とも未設定にする
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
 * @param  array<non-empty-string, string>  $overrides
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

### PHPStan 適合チェック

- [x] `array_map` で `array<non-empty-string, RawEnvChannels>` を作る際にキーが保存される
      （`array_map` はコールバック 1 本のときキーを保存する）
- [x] `RawEnvSnapshot::with()` の `@template TReturn` で戻り値の `array<string, mixed>` が通る
- [x] `productionEnvGuardRawSnapshot()` の `static` 変数に `?RawEnvSnapshot` の型注釈を付ける

### テスト計画

- [x] 既存テストの削除は行わない（**ヘルパ関数の削除**であってケースの削除ではない）
- [x] 3 ファイルの既存ケースがすべて緑のままであること
- [x] `ConfigHardeningTest` の「存在するが値が null」の往復は**部品側の契約テスト (a-2)** が持つ
      （呼び出し側に往復検査を残すと i1 の集約が崩れる）
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`ProductionEnvGuardTest` の `beforeEach` が例外を投げると `afterEach` の
  `restore()` が古い snapshot を使う**。→ `captureAndClear()` は適用途中の失敗を
  その場で巻き戻してから送出するので、失敗時に `productionEnvGuardRawSnapshot()` は
  更新されない（前ケースの snapshot が残る）。前ケースの snapshot での復元は
  同じキー集合の元の状態へ戻すだけなので害はないが、**この性質を関数の docblock に書く**。
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
 * 親プロセスの環境を 3 面の優先順 (`$_SERVER` → `$_ENV` → `getenv`) で 1 枚の配列へ読み出す。
 *
 * ★**読み出しだけ**を行う (3 面へ書き込まない)。
 * ★組み立てそのものは pgsqlTestComposeArtisanEnv() が純関数として持つ。
 *   分けてあるのは、テストが**親プロセスの環境を触らずに**組み立てを検査できるようにするためである
 *   (テストのために親へ dev DB 名を立てる、という危険を構造的に消す)。
 *
 * @return array<string, mixed>
 */
function pgsqlTestParentEnv(): array
{
    $fromGetenv = getenv();

    return array_merge(is_array($fromGetenv) ? $fromGetenv : [], $_ENV, $_SERVER);
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
// tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php (書き換え後)

/** テスト用の接続値 (実 DB へはつながない)。 */
function fakePgsqlConnValues(): array
{
    return ['host' => '127.0.0.1', 'port' => '5432', 'username' => 'postgres', 'password' => 'postgres'];
}

it('does not leak arbitrary environment variables into the child process env', function (): void {
    $env = pgsqlTestComposeArtisanEnv(
        __DIR__,
        'app_test_8af22c44',
        ['SOME_SECRET' => 'leaked', 'PATH' => '/usr/bin'],
        fakePgsqlConnValues(),
    );

    expect($env)->not->toHaveKey('SOME_SECRET')
        ->and($env['PATH'])->toBe('/usr/bin');
});

it('overrides a parent environment that already sets DB_DATABASE / DB_URL / APP_CONFIG_CACHE to a dev DB', function (): void {
    // ★親プロセスの環境は 1 面も触らない。危険な値は**組み立ての入力として**与える。
    $env = pgsqlTestComposeArtisanEnv(
        __DIR__,
        'app_test_8af22c44',
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

it('wires the real parent environment and connection values into the composed env', function (): void {
    // 結線そのものの薄い固定 (親環境は触らない。固定キーが戻り値に載っていることだけを見る)。
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    expect($env['APP_ENV'])->toBe('testing')
        ->and($env['DB_CONNECTION'])->toBe('pgsql')
        ->and($env['DB_DATABASE'])->toBe('app_test_8af22c44')
        ->and($env['DB_URL'])->toBe('')
        ->and($env['APP_CONFIG_CACHE'])->toBe(pgsqlTestConfigCachePath(__DIR__));
});
```

`carries over only PATH / HOME / TMPDIR` / `forces DB_URL empty` / `pins the computed base name`
の 3 ケースも、同じく `pgsqlTestComposeArtisanEnv()` を直接呼ぶ形へ移す（親環境に触らない）。

### 保持する検証（失わないこと）

| 現行が検証していること | 書き換え後 |
|---|---|
| 継承するのは `PATH` / `HOME` / `TMPDIR` だけ | **同じ**（入力に他のキーを載せて確かめるので**検出力が上がる**） |
| 固定値が常に勝つ（`DB_DATABASE` / `DB_URL` / `APP_CONFIG_CACHE`） | **同じ**（現行は入力に載っていないので空振りだった。書き換え後は実際に載る） |
| `DB_URL` は空で固定 | 同じ |
| 組み立て結果が子プロセス起動へ渡ること | **既存の別ケースが押さえる**（「`$runArtisan` へ渡る引数列がちょうど 2 通り・この順序・それ以外は 1 度も渡らない」）。`pgsqlTestArtisanEnv()` の呼び出し位置も戻り値の形も変えないため、そのケースは無変更で残る |
| 結線（実際の親環境の読み出し + 接続値から組み立てること） | **新しい薄い 1 ケース**で固定する |

### PHPStan 適合チェック

- [x] `getenv()`（引数なし）の戻り値は `array<string, string>|false` として扱う
      （`is_array()` で絞る）
- [x] `pgsqlTestComposeArtisanEnv()` の `$parentEnv` は `array<string, mixed>`。
      値の型は `is_string()` で絞ってから使う
- [x] 戻り値は `array<string, string>`

### リスク

- **`scripts/ci/pgsql_test_conn.php` は CI と `setup-worktree.sh` が使う実運用スクリプトである**。
  → 変更は純関数の切り出しのみで、`pgsqlTestArtisanEnv()` の入出力は変えない。
  安全側の再検証（`TestDatabaseEnv::isDevDatabase()` / `isAllowedTestDatabase()`）は
  呼び出し側 `ensureTestDatabaseSchemaUpdated()` にあり、そこは無変更。
- **`pgsqlTestParentEnv()` が `$_SERVER` の非 env 項目（`argv` 等）を含む**。
  → 組み立ては `PATH` / `HOME` / `TMPDIR` しか読まないので影響しない。docblock に書く。
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
| 新設 9 ファイル | 在らない | 無い | **テンプレートに無い領域への上積み** → 登録する（記録の原則「迷ったら登録する」＋ `StrictTypesDeclarationGateTest` / `CacheGuardWiringGateTest` / `ArchBaselineTest` の先例） |

### D50 の登録内容（案）

```
## D50 テストが触る生の環境変数 3 面を 1 つの部品へ集約し、部品の外の直接の書き込みを検査で止める

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Support/RawEnv/RawEnvChannels.php` / `tests/Support/RawEnv/RawEnvSnapshot.php` / `tests/Support/RawEnv/RawEnvWriteKind.php` / `tests/Support/RawEnv/RawEnvWriteSite.php` / `tests/Support/RawEnv/RawEnvDirectWriteAllowance.php` / `tests/Support/RawEnv/RawEnvDirectWriteScanner.php` / `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php` / `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php` / `tests/Architecture/RawEnvDirectWriteGateTest.php` |
| 業務要件起因の説明 | 撮影 PWA の秘匿と本番構成の起動時 fail-fast を守る検査 (ProductionEnvGuardTest / ConfigHardeningTest / PasskeyOriginDeclarationTest) はすべて生の環境変数を差し替えて動く。テンプレートは 3 面の退避・復元をテストごとに書く形のままなので、取りこぼしが起きると守りの検査が実行順で通ったり落ちたりし、守りの主張そのものが信用できなくなる。家系の機能台帳が確定した正典 v1 (不変条件 i1-i12) へ追従して部品へ集約し、部品の外の直接の書き込みを検査で止める |
| 揃え続ける不変条件と保証機構 | 3 面の退避が存在と値を別に持ち型を絞らないこと / 検証 → 退避 → 適用 + 本体 → 復元 の 3 相であること / 単一点の守りが前提にするキーを拒否すること / 読み出しの優先順が $_SERVER → $_ENV → putenv であること (RawEnvSnapshotContractTest が実行時に固定) / 部品の外に 3 面への直接の書き込みが無いこと (RawEnvDirectWriteGateTest が deny-by-default で強制し、許可は部品自身とその契約テストと tests/bootstrap.php の 3 か所だけ・件数は完全一致で pin) |
| 再判定の条件 | 家系の正典 v1 が改版されたとき / テンプレート側が同等の部品を採用して還流できるようになったとき / 上流の phpdotenv・Laravel が読み出し順か Env::enablePutenv() の副作用を変えたとき |
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
| `RawEnvDirectWriteGateTest` | テストが `getenv()` / `$_ENV` / `$_SERVER` を直接書き換えるとき | `Tests\Support\RawEnv\RawEnvSnapshot` 経由へ寄せる（許可 3 か所以外は登録不可） |

（実際の列構成は文書側の既存表に合わせる。列数はヘッダとの完全一致が強制される。）

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
  → 実装時に main の最新から **D 番号と `DIVERGENCE_ENTRY_COUNT` を再確定する**。
  本設計の D50 と 47 は 2026-08-24 時点の値である。

---

## 実装手順（テストファースト。**どのテストを先に赤くするか**）

思考原則 5 と「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」に従い、
**必ず赤を確認してから**本体を書く。

| 段 | 作業 | 何が赤くなるか |
|---|---|---|
| 1 | `RawEnvSnapshotContractTest` を書く（a-1〜i-2。部品はまだ無い） | クラス未定義で赤 |
| 2 | `RawEnvChannels` / `RawEnvSnapshot` を実装する | 段 1 が緑になる |
| 3 | `RawEnvDirectWriteScannerTest` を書く（正例 8 / 負例 4 / 未解決 3 / 母集団 1） | 走査器未定義で赤 |
| 4 | `RawEnvWriteKind` / `RawEnvWriteSite` / `RawEnvDirectWriteScanner` を実装する | 段 3 が緑になる |
| 5 | `RawEnvDirectWriteGateTest` を書く（G1〜G11。目録は 3 か所だが件数は空） | **G1 が 4 ファイル分の違反で赤** / G8 が件数不一致で赤 |
| 6 | 施策 3（3 ファイルの移送と旧実装の削除） | G1 の違反が 3 ファイル分減る |
| 7 | 施策 4（`scripts/ci/pgsql_test_conn.php` の切り出しと検査の書き換え） | G1 の違反が 0 になる |
| 8 | 目録の件数を実測して書き込む | G8 が緑になる |
| 9 | 施策 5（台帳・件数 pin・契約文書） | `TemplateDivergenceLedgerFormatTest` / `IntegrationGuideGateTableSyncTest` が緑になる |

**段 5 で「先に赤くする」ことの確認方法**: 段 6・7 の前に gate を走らせ、
違反として `ConfigHardeningTest` / `PasskeyOriginDeclarationTest` / `ProductionEnvGuardTest` /
`TestDatabaseSchemaUpdateTest` の 4 本が列挙されることを実際に見る
（この 4 本が**この gate の負例そのもの**である）。
段 8 の後に、目録の件数を 1 増やして赤くなること・1 減らして赤くなることも確認する
（完全一致であることの裏取り）。

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
    親環境の読み出しは新しい 1 関数へ切り出し、組み立ては純関数へ移す。

## `docs/template-divergence.md` の登録 / 更新 / 削除の要否

| 操作 | 要否 | 対象 |
|---|---|---|
| 新規登録 | **要** | D50（新設 9 ファイル） |
| 既存登録の更新 | **不要** | D30（`scripts/ci/pgsql_test_conn.php`）/ D42（契約文書とゲート索引）はいずれも対象パスに含まれており、説明している不変条件は変わらない |
| 登録の削除 | 不要 | 解消する逸脱は無い |
| 件数 pin の更新 | **要** | `LedgerPins::DIVERGENCE_ENTRY_COUNT` 46 → 47 |
| 採用時債務の操作 | **不要** | 変更するどのパスも `adoption-debt.tsv` に無い（実測） |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 5 施策が 1 つの意味単位で動く（施策 2 だけ入れると gate が赤いまま、施策 3 だけ入れると i1 が維持されない）。加えて `tests/Support` への部品追加・`scripts/ci` の CI スクリプト・乖離台帳の件数 pin という**衝突しやすい 3 領域**を同時に触る |
| 競合リスク | **T249（起動 probe の共通 runner への一元化）と同時進行しない** — 同じ `tests/Support` へ部品を足し、親プロセスの env に対する前提を両方が扱うため、着手が重なるとレビューが交錯する。**T255 / T256 / T258 とは `docs/template-divergence.md` の D 番号と `LedgerPins` の 3 定数で衝突する** — どれが先にマージされても、実装時に main の最新から D 番号と件数を再確定すること |

## 関連する現行コード

### tests/bootstrap.php (全文。i12 が許す「枠組み起動前の足場」)
```php
<?php

declare(strict_types=1);

use Tests\Support\Ci\TestDatabaseEnv;

/*
|--------------------------------------------------------------------------
| PHPUnit Bootstrap (pgsql 一本化)
|--------------------------------------------------------------------------
|
| 1) vendor autoloader を読み込む。
| 2) test 用 DB 名を `<slug>_test_<worktree-hash>` に決定し、DB_DATABASE を
|    $_SERVER / $_ENV / putenv の 3 経路へ注入する。Laravel の env() は
|    $_SERVER → $_ENV → getenv の順で見るため 3 経路全て埋める。
| 3) 最終 DB 名が test DB (allowlist 一致 + 非 dev) であることを Laravel boot 前に
|    fail-closed 検証する (単一点ガード)。shell / docker-compose から
|    DB_DATABASE=<dev DB> が leak しても dev DB を wipe しない安全装置。
|
| <hash> は project root の realpath から sha1 先頭 8 文字。別 worktree からの
| `composer test` 並走でも DB 名が衝突しない。同一 worktree 内の paratest worker
| 間は Laravel が `<base>_test_<TEST_TOKEN>` へ自動展開するためここでは扱わない。
|
| phpunit.xml の <server force> ではなく bootstrap で env を埋めるのは、phpunit.xml
| が静的記述で worktree hash を持てないため。適用順 (load-bearing): phpunit の
| TextUI\Application は PhpHandler::handle() (<server> を $_SERVER に代入) を
| BootstrapLoader より先に実行するため、ここで $_SERVER['DB_CONNECTION'] は
| phpunit.xml の force 済み値 (pgsql) を反映している。Laravel の phpdotenv は
| immutable adapter で既存 env を尊重する (.env.testing が後から上書きしない)。
|
| base DB の作成は scripts/ci/ensure-test-db.php (run-test.sh / CI が test 前に実行)
| が担う。アプリ名は DB 名に含めない (TestDatabaseEnv の prefix は template slug 由来)。
*/

require __DIR__.'/../vendor/autoload.php';

$projectRoot = realpath(__DIR__.'/..') ?: dirname(__DIR__);

$override = TestDatabaseEnv::pgsqlOverrideDatabase($_SERVER, $projectRoot);

if ($override !== null) {
    $_SERVER['DB_DATABASE'] = $override;
    $_ENV['DB_DATABASE'] = $override;
    putenv("DB_DATABASE={$override}");
}

// 単一点 fail-closed ガード: pgsql lane の最終 DB_DATABASE が test DB でなければ
// Laravel boot 前に停止する (override 不発・env leak が生存した場合もここで捕捉)。
if (($_SERVER['DB_CONNECTION'] ?? null) === 'pgsql') {
    $effective = (string) ($_SERVER['DB_DATABASE'] ?? '');
    try {
        TestDatabaseEnv::assertPgsqlTestDatabaseSafe($effective);
    } catch (Throwable $e) {
        fwrite(STDERR, "[db-safety] FATAL: {$e->getMessage()}\n");
        exit(1);
    }
    unset($effective);
}

unset($projectRoot, $override);
```

### tests/Feature/Support/ProductionEnvGuardTest.php (退避・復元・注入の関数群と代表的な呼び出し側)
```php
<?php

declare(strict_types=1);

use App\Support\ExternalFakes\ExternalFakeDeclaration;
use App\Support\ProductionEnvGuard;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    // ★実環境変数の二重判定 (T177) が入ったため、**テストの前提として 3 変数 × 3 経路を
    //   すべて未設定にする**。開発者の手元シェルや実行基盤に TESTING_FAKE_* が残っていると、
    //   本ファイルのほぼ全ケースが余分な violation で落ちる (ホスト環境依存になる)。
    //   原状復帰は afterEach が行う。
    productionEnvGuardIsolateRawEnvironment();

    // production 必須項目の baseline (すべて有効値)。各テストで 1 項目ずつ崩す。
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    config(['ciphersweet.providers.string.key' => str_repeat('a', 64)]);
    config(['cashier.webhook.secret' => 'whsec_valid']);
    config(['session.secure' => true]);
    config(['app.debug' => false]);
    config(['security.hsts.enabled' => true]);
    config(['security.csp.enabled' => true]);
    config(['debug.login.user' => '']);
    config(['debug.login.password' => '']);
    config(['testing.fake_externals' => false]);
    config(['testing.fake_llm' => false]);
    config(['testing.fake_storage' => false]);
    config(['trusted_hosts.exact_hosts' => ['app.example.com']]);
    config(['trusted_hosts.wildcard_suffixes' => []]);
    config(['trusted_hosts.raw_wildcard_suffixes' => []]);
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    config(['trustedproxy.raw_proxies' => ['10.0.0.0/8']]);
    // パスキー設定 (T166)。**読み出し元が 2 系統に分かれる**ので取り違えないこと:
    // 実効値は passkeys.* (Fortify の上書き後)、検査専用キーは fortify.passkeys.*。
    config(['passkeys.relying_party_id' => 'app.example.com']);
    config(['passkeys.allowed_origins' => ['https://app.example.com']]);
    config(['passkeys.user_handle_secret' => str_repeat('a', 32)]);
    config(['fortify.passkeys.raw_allowed_origins' => ['https://app.example.com']]);
    config(['fortify.passkeys.user_handle_secret_declared' => true]);
});

afterEach(function (): void {
    productionEnvGuardRestoreRawEnvironment();
});

test('全 production 必須項目が埋まっていれば violations は空', function (): void {
    expect((new ProductionEnvGuard)->violations())->toBe([]);
});

/* ... 中略 (config ベースラインを崩す通常ケースが続く) ... */
/*
 * 実環境変数の二重判定 (T177 施策 3)。
 *
 * 設定キャッシュを作った環境と出荷先が食い違うと、キャッシュ上は false でも、
 * キャッシュが失われた起動で環境変数が読み直されて本番で偽物が立ちうる。
 * そこで設定値とは独立に $_SERVER / $_ENV / getenv() の 3 経路を見る。
 *
 * ★原値の退避と復元は下のヘルパへ集約し、すべてのケースが try/finally で戻す
 *   (putenv は空文字と未設定の差が環境で揺れるため、$_SERVER / $_ENV 側は
 *    unset() と = '' を明示的に作り分ける)。
 * ★**指定しなかった経路はテスト中だけ明示的に未設定化する**。実行環境に同じ変数が
 *   残っていると「経路ごとに独立に検査する」という前提が崩れ、違反件数がホスト依存になる。
 */

/**
 * 二重判定の対象になる環境変数 (宣言が正本)。
 *
 * @return list<string>
 */
function productionEnvGuardFakeFlagVariables(): array
{
    return array_values(ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES);
}

/**
 * 3 経路の原値を退避する。
 *
 * @return array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}
 */
function productionEnvGuardCaptureRaw(string $variable): array
{
    return [
        'hadServer' => array_key_exists($variable, $_SERVER),
        'server' => $_SERVER[$variable] ?? null,
        'hadEnv' => array_key_exists($variable, $_ENV),
        'env' => $_ENV[$variable] ?? null,
        'putenv' => getenv($variable),
    ];
}

/**
 * 退避した原値へ 3 経路を戻す。
 *
 * @param  array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}  $state
 */
function productionEnvGuardRestoreRaw(string $variable, array $state): void
{
    if ($state['hadServer']) {
        $_SERVER[$variable] = $state['server'];
    } else {
        unset($_SERVER[$variable]);
    }

    if ($state['hadEnv']) {
        $_ENV[$variable] = $state['env'];
    } else {
        unset($_ENV[$variable]);
    }

    if ($state['putenv'] === false) {
        putenv($variable);
    } else {
        putenv("{$variable}={$state['putenv']}");
    }
}

/** 3 経路をすべて未設定にする */
function productionEnvGuardClearRaw(string $variable): void
{
    unset($_SERVER[$variable], $_ENV[$variable]);
    putenv($variable);
}

/**
 * ケース間で共有する退避先 (Pest の TestCase へ動的プロパティを生やさない)。
 *
 * @param  array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}>|null  $set
 * @return array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}>
 */
function productionEnvGuardRawSnapshot(?array $set = null): array
{
    /** @var array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}> $snapshot */
    static $snapshot = [];

    if ($set !== null) {
        $snapshot = $set;
    }

    return $snapshot;
}

/** テストの前提として対象変数の 3 経路をすべて未設定にする (原値は退避する) */
function productionEnvGuardIsolateRawEnvironment(): void
{
    $snapshot = [];
    foreach (productionEnvGuardFakeFlagVariables() as $variable) {
        $snapshot[$variable] = productionEnvGuardCaptureRaw($variable);
        productionEnvGuardClearRaw($variable);
    }

    productionEnvGuardRawSnapshot($snapshot);
}

/** 退避しておいた原値へ戻す */
function productionEnvGuardRestoreRawEnvironment(): void
{
    foreach (productionEnvGuardRawSnapshot() as $variable => $state) {
        productionEnvGuardRestoreRaw($variable, $state);
    }
}

/**
 * 指定した経路にだけ値を置き、**それ以外の経路は未設定にした状態で** callback を実行する。
 *
 * `$_SERVER` / `$_ENV` は mixed を持ちうるので値の型を絞らない
 * (非文字列を入れるケースも同じ復元経路に乗せる = 復元漏れを作らない)。
 *
 * @param  array{server?: mixed, env?: mixed, putenv?: string}  $values  設定する経路と値
 */
function withRawEnvironmentValue(string $variable, array $values, Closure $callback): void
{
    $state = productionEnvGuardCaptureRaw($variable);
    $hadServer = $state['hadServer'];
    $hadEnv = $state['hadEnv'];
    $originalServer = $state['server'];
    $originalEnv = $state['env'];
    $originalPutenv = $state['putenv'];

    try {
        // 指定されなかった経路は未設定にする (経路ごとの独立検査の前提を作る)。
        if (array_key_exists('server', $values)) {
            $_SERVER[$variable] = $values['server'];
        } else {
            unset($_SERVER[$variable]);
        }

        if (array_key_exists('env', $values)) {
            $_ENV[$variable] = $values['env'];
        } else {
            unset($_ENV[$variable]);
        }

        if (array_key_exists('putenv', $values)) {
            putenv("{$variable}={$values['putenv']}");
        } else {
            putenv($variable);
        }

        $callback();
    } finally {
        if ($hadServer) {
            $_SERVER[$variable] = $originalServer;
        } else {
            unset($_SERVER[$variable]);
        }

        if ($hadEnv) {
            $_ENV[$variable] = $originalEnv;
        } else {
            unset($_ENV[$variable]);
        }

        if ($originalPutenv === false) {
            putenv($variable);
        } else {
            putenv("{$variable}={$originalPutenv}");
        }
    }
}

test('config が false でも $_SERVER に true が残っていれば violation', function (): void {
    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => 'true'], function (): void {
        $errors = (new ProductionEnvGuard)->violations();
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
        expect($errors[0])->toContain('$_SERVER');
    });
});

test('config が false でも $_ENV に true が残っていれば violation', function (): void {
    withRawEnvironmentValue('TESTING_FAKE_LLM', ['env' => 'true'], function (): void {
        $errors = (new ProductionEnvGuard)->violations();
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('$_ENV');
    });
});

test('config が false でも getenv() に true が残っていれば violation', function (): void {
    withRawEnvironmentValue('TESTING_FAKE_STORAGE', ['putenv' => 'true'], function (): void {
        $errors = (new ProductionEnvGuard)->violations();
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('getenv()');
    });
});

test('3 経路とも未設定なら violation は出ない', function (): void {
    // beforeEach が 3 変数 × 3 経路を未設定にしている。ここでは明示的に 1 変数を
    // 「どの経路も指定しない」形で通し、未設定が判定対象にならないことを固定する。
    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', [], function (): void {
        expect((new ProductionEnvGuard)->violations())->toBe([]);
    });
});

test('無効と読める値 (false / 0 / 空文字) では violation は出ない', function (): void {
    foreach (['false', 'FALSE', '(false)', '0', 'off', 'no', 'null', ''] as $value) {
        withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => $value], function () use ($value): void {
            expect((new ProductionEnvGuard)->violations())->toBe([], "無効と読めるはずの値: '{$value}'");
        });
    }
});

```

### tests/Feature/Config/ConfigHardeningTest.php (冒頭のヘルパと代表的な呼び出し側)
```php
<?php

declare(strict_types=1);

/*
 * config 横断ハードニングの不変条件を固定する。
 *
 * env デフォルト分岐 ('fail-close' 等) は config() では検査できない
 * (phpunit.xml / .env の値が挿さるため)。$_SERVER / $_ENV / putenv を直接退避→復元して
 * config ファイルを再評価する (Laravel の env() は ServerConst / EnvConst / Putenv の
 * 3 adapter を live に読むため、いずれか 1 つでも残ると .env.testing 値が漏れる)。
 */

/**
 * 指定の env 変数を差し替えて config ファイルを再評価する。
 *
 * @param  array<string, string|null>  $env  null は unset
 * @return array<string, mixed>
 */
function evaluateConfigFileWithEnv(string $configFile, array $env): array
{
    $previous = [];
    foreach ($env as $key => $value) {
        $getenv = getenv($key);
        $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, $getenv === false ? null : $getenv];
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        } else {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        $config = require base_path("config/{$configFile}");
        expect($config)->toBeArray();

        /** @var array<string, mixed> $config */
        return $config;
    } finally {
        foreach ($previous as $key => [$serverValue, $envValue, $putenvValue]) {
            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            if ($putenvValue === null) {
                putenv($key);
            } else {
                putenv("{$key}={$putenvValue}");
            }
        }
    }
}

// ========== session: secure cookie の production fail-close ==========

test('production 模擬で SESSION_SECURE_COOKIE 未設定なら session.secure=true (fail-close)', function (): void {
    $config = evaluateConfigFileWithEnv('session.php', [
        'APP_ENV' => 'production',
        'SESSION_SECURE_COOKIE' => null,
    ]);

    expect($config['secure'])->toBeTrue();
});

test('local 模擬で SESSION_SECURE_COOKIE 未設定なら session.secure=false', function (): void {
    $config = evaluateConfigFileWithEnv('session.php', [
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
    ]);

    expect($config['secure'])->toBeFalse();
/* ... 中略 ... */
});

// ========== prism-prompt: テンプレートのオブジェクトキャッシュを持たない (T228) ==========

test('config/prism-prompt.php は cache.enabled を false で宣言している (env で開かない)', function (): void {
    // ★同梱パッケージの PromptTemplate::fromYaml() は PromptTemplate オブジェクトそのものを
    //   キャッシュへ入れる (AGENTS.md セキュリティ不変条件 11 に反する)。有効・無効を決める
    //   設定は本リポジトリが所有しているので、env で開け直せる形を残さない。
    $config = evaluateConfigFileWithEnv('prism-prompt.php', ['PRISM_PROMPT_CACHE' => 'true']);

    expect($config['cache'])->toBeArray();
    /** @var array<string, mixed> $cache */
    $cache = $config['cache'];
    expect($cache['enabled'])->toBeFalse(
        'PromptTemplate::fromYaml() がオブジェクトをキャッシュへ入れるため、env で開けられてはならない');
});

test('prism-prompt.cache.enabled は実行時にも false', function (): void {
    expect(config('prism-prompt.cache.enabled'))->toBeFalse();
});

// ========== fortify: passkeys ブロックの env 派生 (T166) ==========

/*
 * パスキーの宣言点は config/fortify.php の passkeys ブロックただ 1 つである
 * (FortifyServiceProvider::configurePasskeys() が passkeys.* を無条件に上書きするため)。
 * env からの導出規則を固定する。
 *
 * 注: config/fortify.php の features は Features::passkeys(['confirmPassword' => false]) を
 * 評価する = fortify-options.passkeys へ書き込む副作用がある。書き込まれる値は本番 config と
 * 同一なのでテストへの影響は無い。
 */

/**
 * config/fortify.php を env 指定で再評価し passkeys ブロックを返す。
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function evaluateFortifyPasskeysWithEnv(array $env): array
{
    $config = evaluateConfigFileWithEnv('fortify.php', $env + [
        'APP_URL' => 'https://app.example.com',
        'PASSKEYS_RELYING_PARTY_ID' => null,
        'PASSKEYS_ALLOWED_ORIGINS' => null,
        'PASSKEYS_USER_HANDLE_SECRET' => null,
    ]);

    expect($config['passkeys'])->toBeArray();

    /** @var array<string, mixed> $passkeys */
    $passkeys = $config['passkeys'];

    return $passkeys;
}

```

### tests/Feature/Auth/PasskeyOriginDeclarationTest.php (ヘルパ部分)
```php
<?php

declare(strict_types=1);

/*
 * 宣言経路 (環境変数 → config/fortify.php) が「許可する接続元」を正規形へ寄せることの
 * 端から端までの固定 (T216 施策 B)。
 *
 * ★実効値だけを見る検査では**検出力が弱い** — 手元の APP_URL が既に正規形なら、
 *   config/fortify.php から正規化器の呼び出しを外しても緑のままになりうる。
 *   ソース文字列の包含で代用するのも不十分である (呼び出しを消してコメントに残す /
 *   戻り値を採用しない書き方でも通る)。
 *   そこで**宣言経路そのものを再評価し、返ってきた配列**を見る。
 */

/**
 * 環境変数を差し替えて config/fortify.php を評価し、返り値を得る。
 *
 * Laravel の env() は $_SERVER → $_ENV → putenv の 3 経路を見るため 3 つとも埋める
 * (tests/bootstrap.php が同じ作法を採っている)。**必ず finally で元へ戻す**
 * (元が未設定なら空文字ではなく unset で戻す = 「未宣言」の意味を変えないため)。
 * 設定ファイルの評価は副作用として fortify-options を同じ値で書き直すだけで、
 * 他への影響を持たない (Features::* は options を config へ書いて識別子を返す builder)。
 *
 * @param  array<string, string>  $overrides
 * @return array<string, mixed>
 */
function evaluateFortifyConfigWithEnv(array $overrides): array
{
    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false, 3: bool, 4: bool}> $saved */
    $saved = [];

    foreach ($overrides as $key => $value) {
        $saved[$key] = [
            $_SERVER[$key] ?? null,
            $_ENV[$key] ?? null,
            getenv($key),
            array_key_exists($key, $_SERVER),
            array_key_exists($key, $_ENV),
        ];

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require base_path('config/fortify.php');

        return $config;
    } finally {
        foreach ($saved as $key => [$server, $env, $raw, $hadServer, $hadEnv]) {
            if ($hadServer) {
                $_SERVER[$key] = $server;
            } else {
                unset($_SERVER[$key]);
            }

            if ($hadEnv) {
                $_ENV[$key] = $env;
            } else {
                unset($_ENV[$key]);
            }

            if ($raw === false) {
                putenv($key);
            } else {
                putenv("{$key}={$raw}");
            }
        }
    }
}

test('宣言経路が正規形へ寄せる (末尾スラッシュと既定 port と大文字)', function (): void {
    $config = evaluateFortifyConfigWithEnv([
        'PASSKEYS_ALLOWED_ORIGINS' => 'HTTPS://App.Example.com:443/',
    ]);

    expect(data_get($config, 'passkeys.allowed_origins'))->toBe(['https://app.example.com'])
```

### tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php (env を触る 5 ケース)
```php
// ── pgsqlTestArtisanEnv(): 環境を継承しない子プロセス env ──

it('does not leak arbitrary environment variables into the child process env', function (): void {
    $original = getenv('SOME_SECRET');
    putenv('SOME_SECRET=leaked');

    try {
        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
        expect($env)->not->toHaveKey('SOME_SECRET');
    } finally {
        putenv($original === false ? 'SOME_SECRET' : "SOME_SECRET={$original}");
    }
});

it('carries over only PATH / HOME / TMPDIR from the parent environment', function (): void {
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    foreach (array_keys($env) as $key) {
        expect(in_array($key, ['PATH', 'HOME', 'TMPDIR'], true) || array_key_exists($key, [
            'APP_ENV' => true, 'APP_CONFIG_CACHE' => true, 'DB_CONNECTION' => true, 'DB_URL' => true,
            'DB_HOST' => true, 'DB_PORT' => true, 'DB_USERNAME' => true, 'DB_PASSWORD' => true,
            'DB_DATABASE' => true, 'CACHE_STORE' => true,
        ]))->toBeTrue("unexpected key leaked into artisan env: {$key}");
    }
});

it('forces DB_URL empty so that a URL-form connection string cannot override DB_DATABASE', function (): void {
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    expect($env['DB_URL'])->toBe('');
});

it('pins the computed base name as DB_DATABASE and APP_ENV as testing', function (): void {
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
        ->and($env['APP_ENV'])->toBe('testing')
        ->and($env['DB_CONNECTION'])->toBe('pgsql');
});

it('overrides a parent environment that already sets DB_DATABASE / DB_URL / APP_CONFIG_CACHE to a dev DB', function (): void {
    $keys = ['DB_DATABASE', 'DB_URL', 'APP_CONFIG_CACHE'];
    $originals = array_combine($keys, array_map(getenv(...), $keys));

    putenv('DB_DATABASE=app');
    putenv('DB_URL=pgsql://postgres:postgres@127.0.0.1:5432/app');
    putenv('APP_CONFIG_CACHE=/tmp/attacker-controlled-config.php');

    try {
        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

        expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
            ->and($env['DB_URL'])->toBe('')
            ->and($env['APP_CONFIG_CACHE'])->toBe(pgsqlTestConfigCachePath(__DIR__));
    } finally {
        foreach ($originals as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }
});

// ── pgsqlTestConfigCachePath(): ensure 専用の非既定パス ──

it('returns a fixed config cache path derived from the project root', function (): void {
    expect(pgsqlTestConfigCachePath('/workspace'))->toBe('/workspace/bootstrap/cache/ensure-test-db-schema-update.config-cache.php');
```

### scripts/ci/pgsql_test_conn.php (pgsqlTestConnValues / pgsqlTestArtisanEnv)
```php
/**
 * テスト lane と同一優先順位で DB 接続値を解決する。
 *
 * @return array{host: string, port: string, username: string, password: string}
 */
function pgsqlTestConnValues(string $projectRoot): array
{
    // shell env を尊重しつつ .env.testing で補完する (Laravel testing lane と同じ immutable 挙動)
    if (is_file($projectRoot.'/.env.testing') && class_exists(Dotenv\Dotenv::class)) {
        Dotenv\Dotenv::createImmutable($projectRoot, '.env.testing')->safeLoad();
    }

    $env = static function (string $key, string $default): string {
        $v = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($v) && $v !== '' ? $v : $default;
    };

    return [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => $env('DB_PORT', '5432'),
        'username' => $env('DB_USERNAME', 'postgres'),
        'password' => $env('DB_PASSWORD', 'postgres'),
    ];
}

/**
/**
 * スキーマ更新の子プロセスへ渡す環境変数を **継承せず** 組み立てる。
 *
 * 継承しないのが要点である: この devcontainer では shell に dev DB 名が export されており、
 * 素直に継承すると更新が dev DB へ当たる (AGENTS.md 禁止事項 3)。
 * DB 接続先は pgsqlTestConnValues() で解決した値をそのまま渡し、phpunit 本体と
 * 同じ PostgreSQL を見ることを保つ。
 *
 * URL 形の接続指定は DB_URL 1 つだけを空で固定する — config/database.php が読む URL 形の
 * キーは env('DB_URL') だけであり、読み手のいないキーを足すと「効いているつもりの設定」が
 * 増えるだけだからである。
 *
 * **この関数単独は安全な実行境界にならない**。渡された `$database` をそのまま
 * `DB_DATABASE` に固定するだけであり、`$database` が dev DB かどうかの判定は行わない。
 * 呼び出し側 (`ensureTestDatabaseSchemaUpdated()`) が、この関数を呼ぶ**直前**に
 * `TestDatabaseEnv::isDevDatabase()` / `isAllowedTestDatabase()` を再検証する契約になっている。
 *
 * @return array<string, string>
 */
function pgsqlTestArtisanEnv(string $projectRoot, string $database): array
{
    $conn = pgsqlTestConnValues($projectRoot);

    $inherited = [];
    foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
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

/**
 * database/migrations のファイル名一覧 (拡張子・ディレクトリ抜き) を返す。
```

### tests/Support/TrackedPhpSourceFiles.php (走査根の単一出典)
```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * git 追跡下の PHP ソースファイル (blade を除く) を列挙する純関数。
 *
 * ★同じ列挙を 2 本持たない。`NoNonCompoundGlobalUseTest` (既存) と
 *   `StrictTypesDeclarationGateTest` の両方がここを使う。
 * ★git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 *   **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * ★`*.blade.php` は**規則の段階で母集団に入れない**。blade はテンプレートであり
 *   先頭が PHP コードではない (PHP としては `<?php` より前に出力が始まる) ため、
 *   PHP ソースファイルに課す規約の対象にならない。免除ではなく対象外である。
 * ★**保証しないもの**: (a) 未追跡 (git add 前) のファイルは列挙されない。
 *   gate が守る境界は commit / CI であり、そこでは必ず追跡下にある。
 *   (b) 拡張子が `.php` でない PHP ファイル (`artisan` など) は列挙されない。
 *   (c) git が無い環境では**沈黙して空を返さず例外にする** (fail-open 防止)。
 * ★利用側は「自分が期待する母集団」を必ず pin すること (床値 + 代表パス)。
 *   共用したことで一方の都合の変更が他方の走査域を黙って変えるのを防ぐ。
 */
final class TrackedPhpSourceFiles
{
    /**
     * @param  string  $root  git worktree の root (絶対パス)
     * @return list<array{absolute: string, relative: string}> relative の昇順
     */
    public static function all(string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--', '*.php'], $root);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
                .$process->getErrorOutput()
            );
        }

        $files = [];
        foreach (explode("\0", $process->getOutput()) as $relative) {
            if ($relative === '' || str_ends_with($relative, '.blade.php')) {
                continue;
            }
            $absolute = $root.'/'.$relative;
            if (! is_file($absolute)) {
                continue; // 削除済みだが index に残っている等
            }
            $files[] = ['absolute' => $absolute, 'relative' => $relative];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

        return $files;
    }
}
```

### tests/Support/PhpTokenScan.php (トークン正規化の単一出典)
```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースの静的走査で共有する `token_get_all()` の正規化 (純関数)。
 *
 * ★同じ正規化を 2 本持たない。`QueuedJobLeaseInventoryTest` (既存) と
 *   `ExternalClientBoundaryScanner` (T126) の両方がここを使う。
 * ★Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
 *   `Tests\Support\QueueLeaseConfig` と同じくクラスの static メソッドへ集約する。
 */
final class PhpTokenScan
{
    /**
     * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する。
     *
     * 単一文字トークン (`{` / `}` / `;` など) は `id => null` で表現し、
     * 行番号は直前トークンの行を引き継ぐ (単一文字トークンは行情報を持たないため)。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function normalize(string $phpSource): array
    {
        $normalized = [];
        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

                continue;
            }

            $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
            $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
        }

        return $normalized;
    }
}
```

### 先例: tests/Architecture/ForbiddenStatementTokenInvariantTest.php (目録・件数 pin・空振り検査の作法)
```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\ForbiddenStatement\ForbiddenStatementExemption;
use Tests\Support\ForbiddenStatement\ForbiddenStatementKind;
use Tests\Support\ForbiddenStatement\ForbiddenStatementRootPolicy;
use Tests\Support\ForbiddenStatement\ForbiddenStatementScanner;
use Tests\Support\ForbiddenStatement\ForbiddenStatementSite;

/*
 * Architecture invariant: 禁止する文 (出力する文 / 飛び越す文 / 大域を持ち込む文 /
 * 開始タグ付きの出力記法) を書かない。
 *
 * 設計は devnotes/20260815-1537-forbidden-statement-token-gate/ が正本。
 * 家系の機能台帳 (lctl feature: forbidden-statement-token-gate) の移植である。
 *
 * なぜ字句 (トークン) 走査なのか: pest-plugin-arch はクラス / 関数の参照しか見えず、
 * これらは「文」なので原理的に拾えない。既製 preset の同名規則は構文木の扱い上ほぼ働かない。
 *
 * ★**隣接 gate との関係 (統合しない)**: `NoNonCompoundGlobalUseTest` は
 *   「namespace 宣言の無いファイルの非複合 use」という別の不変条件を、
 *   `*.blade.php` を除いた母集団に対して見る。本 gate は blade を**含めて**走査する
 *   (開始タグで開いた区間が見えるため。除外すると開始タグ付き出力記法の禁止に穴が残る)。
 *   母集団が違うので `Tests\Support\TrackedPhpSourceFiles` は共用しない —
 *   同クラスの docblock は blade を「免除ではなく規則の段階で対象外」と宣言しており、
 *   そこを広げると既存 2 gate の走査域が黙って変わる。列挙の**作法**だけを揃える。
 *
 * ★**保証範囲を誇張しない**: 効くのは字句として現れる 4 語彙だけである。
 *   名前の解決が要る出力 (書式つき出力 / 変数の内容の表示 / 標準出力への書き込み) や、
 *   テンプレートの地の文に埋め込まれた区間には**無言で効かない**
 *   (限界の完全な記述は `ForbiddenStatementScanner` の docblock が正本)。
 *
 * ★**この gate は「素の main では赤にならない」種類のテストである。**
 *   空振りしていないことは (a) `tests/Unit/Architecture/ForbiddenStatementScannerTest.php` の
 *   正例 / 取りこぼし対照と、(b) 実装時に踏んだ fail-first 手順 (設計 S5 §実装時に必ず踏む手順) の
 *   2 本で担保する。加えて G2 が走査ファイル数の床値を機械的に固定する。
 */

/** 例外・除外の根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const FORBIDDEN_STATEMENT_REASON_MIN_LENGTH = 30;

/**
 * 例外の登録件数。**現在値ちょうど** (exact fit。`<=` ではなく `===` で照合する)。
 * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに書ける枠」になる。
 * ★減った場合も赤にする (登録を消したなら、この値を変える差分が要る)。
 */
const FORBIDDEN_STATEMENT_EXEMPTION_COUNT = 1;

/**
 * 走査対象ファイル数の床値。
 * ★走査が空振り (0 件) でも「違反 0 件」で緑になってしまうのを止める。
 *   実測 1552 (追跡 PHP 1567 − 除外 devnotes 15) に対し余裕を持たせて 1400 を置く。
 */
const FORBIDDEN_STATEMENT_SCANNED_FILE_FLOOR = 1400;

/**
 * 置き場所の分類 (単一の出典)。
 *
 * ★どれにも分類していない置き場所が現れたら G4 が赤になる。走査根を列挙するだけにすると、
 *   新しいディレクトリを足したときに**黙って走査対象から外れる**。
 *
 * @return array<string, array{ForbiddenStatementRootPolicy, string}>
 *                                                                    キーは最上位ディレクトリ名 (リポジトリ直下は空文字列)。
 *                                                                    第 2 要素は理由 (ScannedNoExemption は空文字列でよい)。
 */
function forbiddenStatementRootPolicies(): array
{
    return [
        '' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'app' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'bootstrap' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'config' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'database' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'lang' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'public' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'resources' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],

        'routes' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'scripts' => [
            ForbiddenStatementRootPolicy::ScannedWithExemption,
            'artisan を通さず別プロセスで起動される運用スクリプトが置かれる。'
            .'標準出力が人間への唯一の伝達手段になる場合がある。',
        ],
        'tests' => [
            ForbiddenStatementRootPolicy::ScannedWithExemption,
            '別プロセスで起動される検体が置かれる。親プロセスへ結果を返す手段が'
            .'標準出力しかない場合がある。',
        ],
        'devnotes' => [
            ForbiddenStatementRootPolicy::Excluded,
            '設計時の調査に使う一時スクリプトの置き場所であり (AGENTS.md「一時スクリプトは '
            .'devnotes へ」)、アプリの実行経路にも CI にも載らない。恒久化するときは '
            .'scripts/ へ移すので、そこで本 gate の対象になる。',
        ],
    ];
}

/**
 * 禁止する文を書くことが正しいと裁定したファイルの目録
 * (型付き + 具体的根拠必須 + 件数の完全一致、単一の出典)。
 *
 * ★**例外に登録されたファイルも全語彙を走査する** (skip しない)。差し引けるのは
 *   ここに登録した (パス, 語彙) の組だけで、登録の無い語彙が現れたら 1 件残らず違反になる。
 *
 * @return array<string, array{
 *     exemption: ForbiddenStatementExemption,
 *     counts: array<string, int>,
 *     reason: non-empty-string,
 * }> counts のキーは ForbiddenStatementKind の値
 */
function forbiddenStatementExemptions(): array
{
    return [
        'scripts/ci/drop-test-db.php' => [
            'exemption' => ForbiddenStatementExemption::StandaloneCliStdout,
            'counts' => [ForbiddenStatementKind::EchoStatement->value => 23],
            'reason' => 'worktree のテスト DB を回収する運用スクリプト。artisan を通さない素の PHP '
                .'として php scripts/ci/drop-test-db.php で起動され、Laravel の Console 出力機構を'
                .'持たない。既定 dry-run の分類結果を人間へ提示することがこのスクリプトの機能そのもの'
                .'であり、HTTP 応答の組み立て経路には載らない。',
        ],
    ];
}

/**
 * 相対パスの最上位ディレクトリ名 (リポジトリ直下は空文字列)。
 */
function forbiddenStatementRootOf(string $relative): string
```
