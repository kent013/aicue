# アプリの使命（North Star）

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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
- PHPStan level 10 (解析対象は app/config/database/routes。tests は対象外)
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
8. 波及変更の網羅性
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【本件固有の最重要観点】
本設計は「家系の機能台帳 (lctl) の正典 feature への追従設計」であり、
**実プロセス 2 本を barrier で同期させる並行テストのハーネス**を新設する。
アプリコードは 1 バイトも変更しない。次を厳しく見よ:
- 正典 v1 の 6 要素を過不足なく満たしているか。正典が「含まない」と書いたものへ広げていないか
- **開発 DB へ到達しうる穴が残っていないか** (子プロセスが DB へ接続する設計なので最重要)
- 「テストが緑のまま嘘になる」壊れ方が残っていないか
  (実は並行していない / 観測が無いのに通る / 合図が来ていないのに通る / 二重実行を見逃す)
- RefreshDatabase + paratest 並列 + pgsql 一本化という aicue の前提と衝突していないか
- 子が commit した行の後始末が漏れて後続テストを汚す経路が無いか
- デッドロック・ハングの可能性 (親子が互いを待つ形になっていないか)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力


---

## 詳細設計書

# 詳細設計: process-concurrency-harness-adoption

家系の機能台帳 lctl の feature `process-concurrency-test-harness`
(feature_revision `14-3117f6369f21` / canonical_version **v1**) への aicue 追従。

---

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
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

> 本設計に直接効くのは **1** (テスト必須) と **2** (PHPStan を緩めない) と **3** (dev DB 保護)。
> 4〜8 は UI / LLM / HTTP 応答の規約で、本設計は `app/` を触らないため該当しない。
> 9 は本設計の成果物を devnotes 配下のファイルとして出すことで満たす。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。ただし解析対象は `app / config / database / routes` で
  **`tests` は含まない**（`phpstan.neon`）。本設計は `phpstan.neon` を**変更しない**（理由は §スコープ外）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルの追加は無し（本設計はモデルを増やさないので Factory の新設も無い）
- **DTO + JsonResource** パターン（本設計は HTTP 応答を作らないので JsonResource は登場しない。
  子との受け渡しは値オブジェクト + 実行時 fail-closed 検証で担保する）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- 禁止する文（`echo` 等）の走査は `tests/` にも及ぶ。実行スクリプトの標準出力は `fwrite(STDOUT, …)` を使う
- 全 PHP ファイルに `declare(strict_types=1);`

---

## 概念設計リファレンス

- [devnotes/20260823-0017-process-concurrency-harness-adoption/conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー Round 3 で **APPROVED**）

### 正典 v1 の不変条件（全 6 要素）と本設計での実現先

| # | 正典が要求すること | 実現する施策 |
|---|---|---|
| (1) | フレームワークを自前で起動し、**引数で受けた ready / go ファイル**で親と同期してから対象処理を叩く実行スクリプト | 施策 1・施策 5 |
| (2) | フィクスチャを**テストの transaction の外**（別名の独立接続）に作り、**末尾で明示的に片付ける** | 施策 2 |
| (3) | **守りたい層以外を意図的に無効化**してから測る（子のキャッシュを配列固定にし、プロセス間でアプリ側ロックが共有されない状態で DB 層だけで守れることを確かめる） | 施策 4・施策 5 |
| (4) | 合図待ちのループで**ファイル状態のキャッシュを毎回捨てる** | 施策 1 |
| (5) | 待ちには**締切**を置き、超えたら例外にする | 施策 1・施策 4 |
| (6) | 重いので**実プロセス版は 1 本に絞り**、細かい分岐は同一プロセスのテストへ回す | 施策 6・施策 8 |

### 正典が「含まない」と明記しているもの（= 本設計のスコープ外の根拠）

- 何を並行で守るかという個別の不変条件（ハーネスは**証明する道具**であって主張ではない）
- **同一プロセス内の並行テスト**（DB の一意制約の実効性は証明できない**別層**）
- テスト実行を直列化して衝突を避ける仕組み（`global-test-lock` — こちらは逆に**意図的に衝突させる**）
- テストレーンの構成（`pest-lane-wiring` / `php-test-pgsql-lane`）

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 合図の待ち合わせ（barrier）と締切 | `tests/Support/Concurrency/ProcessBarrier.php`（新規）<br>`tests/Support/Concurrency/BarrierTimeoutException.php`（新規）<br>`tests/Support/Concurrency/ConcurrencyProtocolException.php`（新規） | 高 |
| 2 | transaction 外の検体置き場 | `tests/Support/Concurrency/OutOfTransactionFixtures.php`（新規） | 高 |
| 3 | 一次観測の型（fail-closed） | `tests/Support/Concurrency/ConcurrentProbeObservation.php`（新規） | 高 |
| 4 | 子の起動・遮断・回収・調停 | `tests/Support/Concurrency/ProbeEnvironment.php`（新規）<br>`tests/Support/Concurrency/ProbeProcess.php`（新規）<br>`tests/Support/Concurrency/SymfonyProbeProcess.php`（新規）<br>`tests/Support/Concurrency/ConcurrencyProbeRunner.php`（新規） | 高 |
| 5 | 実行スクリプト（子プロセスの本体） | `tests/Support/Concurrency/idempotency-claim-probe.php`（新規） | 高 |
| 6 | 見本テスト（実プロセス版は**この 1 本だけ**） | `tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php`（新規） | 高 |
| 7 | ハーネス自身の失敗経路の検査 | `tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php`（新規） | 高 |
| 8 | 既存テストの「保証しないこと」宣言の是正 | `tests/Feature/Api/IdempotencyConcurrentClaimTest.php`（docblock のみ） | 中 |
| 9 | 乖離台帳 D7 の再判定記録と文書追記 | `docs/template-divergence.md`<br>`docs/architecture.md` | 中 |

**アプリコード（`app/` / `routes/` / `config/` / `database/`）の変更は 0 件。**

---

## 施策 1: 合図の待ち合わせ（barrier）と締切

### 変更箇所

- 新規: `tests/Support/Concurrency/ProcessBarrier.php`
- 新規: `tests/Support/Concurrency/BarrierTimeoutException.php`
- 新規: `tests/Support/Concurrency/ConcurrencyProtocolException.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 7 が本部品の失敗経路を固定する

### 設計

合図は**ファイルの存在**で表す。正典 v1 の要素 (1)(4)(5) を 1 クラスに閉じる。

**規律 4 点**（うち 2 点は正典 v1 に無い家系の後発規律で、aigenba が持つもの）:

1. **子ごとに分ける**: `ready-{childId}` は子の数だけ作られ、`go` は 1 つだけ
2. **書きかけを見せない**: 合図は一時ファイルへ書いてから `rename()` する（同一 FS 上の rename は原子的）
3. **毎回 `clearstatcache()`**: 捨てないと合図に気付くのが遅れ、2 本の実行が重ならない（要素 (4)）
4. **締切は単調時計**: `hrtime(true)` で測る（壁時計は NTP 補正で戻りうる）

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * 実プロセス並行テストの合図の待ち合わせ (正典 v1 の要素 (1)(4)(5))。
 *
 * 合図は**ファイルの存在**で表す。規律は 4 つ:
 * 1. ready は**子ごと**に分ける (共有 ready だと片方だけ準備できた状態で go を出せてしまい、
 *    「全員の準備を確認してから同一の合図で解き放つ」という最重要前提が**緑のまま**壊れる)
 * 2. 合図は一時ファイルへ書いてから rename する (書きかけを相手に見せない)
 * 3. 待ちのループでは**毎回 clearstatcache() する** — 捨てないと合図に気付くのが遅れ、
 *    2 本の実行が重ならず並行テストの意味が消える (正典が名指しする作法)
 * 4. 締切は**単調時計** (hrtime) で測る (壁時計は補正で戻りうる)
 *
 * **保証しないもの**: 合図の順序関係だけを保証する。実際に処理が重なったかどうかは
 * 呼び出し側 (ConcurrencyProbeRunner) が entered / release の 3 段で構成する。
 */
final class ProcessBarrier
{
    /** 待ちのポーリング間隔 (マイクロ秒)。短くしすぎると CPU を食い、長くすると重なりが甘くなる */
    private const int POLL_INTERVAL_MICROSECONDS = 1_000;

    public function __construct(private readonly string $directory)
    {
        Assert::directory($directory);
    }

    /** 合図を置く (一時ファイル → rename の 2 段。書きかけを相手に見せない) */
    public function signal(string $name, string $payload = ''): void
    {
        $target = $this->path($name);
        $temporary = $target.'.'.bin2hex(random_bytes(8)).'.partial';

        if (file_put_contents($temporary, $payload) !== strlen($payload)) {
            throw new RuntimeException("合図を書き切れなかった: {$target}");
        }

        if (! rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException("合図を置けなかった: {$target}");
        }
    }

    /**
     * 合図が現れるまで待ち、その中身を返す。
     *
     * @param  (callable(): void)|null  $abortIf 待機中に毎周回呼ぶ中断条件
     *   (子の異常終了・二重実行の検出など。呼び先が例外を投げれば締切を待たずに抜ける)
     *
     * @throws BarrierTimeoutException 締切を超えた
     */
    public function await(string $name, float $timeoutSeconds, ?callable $abortIf = null): string
    {
        Assert::greaterThan($timeoutSeconds, 0.0);

        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

        while (true) {
            if ($abortIf !== null) {
                $abortIf();
            }

            // ★毎周回捨てる。捨てないと合図に気付くのが遅れ、2 本の実行が重ならない。
            clearstatcache(true, $this->path($name));

            if (is_file($this->path($name))) {
                $contents = file_get_contents($this->path($name));

                // 合図はあるのに読めない = 観測が成立していない。
                // 空の合図として通すと後続の照合が別の理由で落ちて原因が隠れる (fail-closed)。
                if ($contents === false) {
                    throw new RuntimeException("合図を読めなかった: {$name}");
                }

                return $contents;
            }

            if (hrtime(true) >= $deadline) {
                throw BarrierTimeoutException::waitingFor($name, $timeoutSeconds);
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    /** 名前が現れている子 ID を列挙する (entered-* の観測に使う) */
    public function present(string $prefix): array { /* clearstatcache + glob */ }

    public function path(string $name): string
    {
        return $this->directory.'/'.$name;
    }
}
```

`BarrierTimeoutException` は `RuntimeException` を継承し、`waitingFor(string $name, float $timeout): self`
の名前付きコンストラクタだけを持つ。
`ConcurrencyProtocolException` も `RuntimeException` 継承で、
`doubleExecution()` / `childDiedEarly()` / `observationMismatch()` を持つ
（**探している退行 (二重実行) を締切超過という紛らわしい形で出さない**ため型を分ける）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（`Webmozart\Assert\Assert` 使用）
- [x] `present()` は `list<string>` を返す（`@return list<string>` を付ける）
- [x] Generics の型パラメータが正しい（本部品に generics は無い）

> `tests` は PHPStan の解析対象外なので、これは**静的検査による保証ではなく規律**である。
> 実効的な保証は施策 7 の失敗経路検査が担う。

### テスト計画

- [x] 新規: 施策 7 の `ConcurrencyHarnessFailurePathTest` が本部品を対象に
      「現れない合図を待ち続けず締切で例外」「合図はあるのに読めないときは空の合図として通さず落ちる」
      「中断条件が成立したら締切を待たずに抜ける」を固定する
- [x] 個別の `DatabaseTransactions` を使わない（本部品は DB に触らない）

### リスク

- ポーリング間隔（1ms）が短すぎると CPU を食う。子 2 本・数秒の待ちなので許容範囲。
- `glob()` はディレクトリキャッシュの影響を受けないが、`clearstatcache()` は明示的に呼ぶ（規律の一貫性）。

---

## 施策 2: transaction 外の検体置き場

### 変更箇所

- 新規: `tests/Support/Concurrency/OutOfTransactionFixtures.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Concurrency/OutOfTransactionFixturesTest.php`（新規。施策 2 のテスト計画）

### 設計

`RefreshDatabase` が検体を**未コミットの transaction の中**に置くため、子プロセスからは見えない。
既定接続の設定を**複製した別名接続**を作り、**閉じた区間だけ**既定接続をそこへ差し替えて Factory を回し、
その接続の**明示トランザクションで commit** する（正典 v1 の要素 (2)）。

```php
/**
 * テストの transaction の外に検体を作る (正典 v1 の要素 (2))。
 *
 * `RefreshDatabase` は各テストを未コミットの transaction で包むため、
 * そこで作った行は**別プロセス・別接続からは見えない**。実プロセス並行テストは
 * 子から検体が見えなければ成立しないので、既定接続の設定を複製した**別名接続**を作り、
 * その接続の明示トランザクションで確定させる。
 *
 * ★**片付けは呼び出し側の責任**である。ここで作った行は `RefreshDatabase` の
 *   rollback では消えない。放置すると同一 worker の後続テストへ漏れるので、
 *   呼び出し側は finally で `cleanup()` を呼ぶこと。
 * ★既定接続の差し替えは**閉じた区間だけ**で、finally で必ず元へ戻す。
 *   接続キャッシュとの相互作用を避けるため、使用前に purge し、使用後は disconnect + purge する。
 */
final class OutOfTransactionFixtures
{
    public const string CONNECTION_NAME = 'concurrency_out_of_transaction';

    /**
     * 別名接続の上で $callback を実行し、その接続の明示トランザクションで確定させる。
     *
     * @template T
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function create(Closure $callback): mixed
    {
        $original = config('database.default');
        Assert::stringNotEmpty($original);

        self::register($original);

        try {
            config(['database.default' => self::CONNECTION_NAME]);

            return DB::connection(self::CONNECTION_NAME)->transaction($callback);
        } finally {
            config(['database.default' => $original]);
        }
    }

    /** 別名接続を登録する (既定接続設定の複製。座標は 1 文字も変えない) */
    private static function register(string $original): void
    {
        $base = config("database.connections.{$original}");
        Assert::isArray($base);

        config(["database.connections.".self::CONNECTION_NAME => $base]);
        DB::purge(self::CONNECTION_NAME);
    }

    /** 別名接続で読む (親の裏取り用。既定接続の transaction の中を見に行かない) */
    public static function connection(): ConnectionInterface
    {
        return DB::connection(self::CONNECTION_NAME);
    }

    /**
     * 呼び出し側が finally で呼ぶ。冪等 (何度呼んでも安全)。
     *
     * @param  \Closure(ConnectionInterface): void  $deletions 消す行を指定する
     */
    public static function cleanup(Closure $deletions): void
    {
        try {
            $deletions(self::connection());
        } finally {
            DB::disconnect(self::CONNECTION_NAME);
            DB::purge(self::CONNECTION_NAME);
        }
    }
}
```

### 採らなかった案（記録）

> **モデル / Factory 単位で接続を指定する Laravel 標準経路を優先する** — 採らない。
> Laravel の Factory には接続を指定する第一級 API が無く、検体は複数モデル
> （組織 / 利用者 / API キー）にまたがる。閉じた区間で既定を差し替えて `finally` で戻すほうが
> 指定漏れが構造的に起きない。家系の先行 2 例（aigenba / laravel-claude-template）も同じ形である。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`create()` は `@template T` で受け渡す）
- [x] null 安全（`Assert::stringNotEmpty` / `Assert::isArray`）
- [x] 配列返却をしない（呼び出し側が Eloquent モデルを受け取る）
- [x] Generics の型パラメータが正しい（`@template T` + `Closure(): T`）

### テスト計画

- [x] 新規: `tests/Feature/Concurrency/OutOfTransactionFixturesTest.php`
  - 「`create()` で作った行が**別接続から見える**」（= transaction の外に出ている）
  - 「`cleanup()` の後は別接続から見えない」（= 後続テストへ漏れない）
  - 「`create()` の中で例外が出ても**既定接続名が元へ戻る**」（finally の実効性）
  - 「別名接続の座標が既定接続と一致する」（別 DB を向いていない）
- [x] 個別の `DatabaseTransactions` を使わない（`RefreshDatabase` のグローバル適用のまま）
- [x] テストデータは Factory で生成する

### リスク

- 別名接続で作った行は `RefreshDatabase` の rollback で消えない → **片付け漏れが後続テストを汚す**。
  docblock で「片付けは呼び出し側の責任」と明記し、見本テストは `finally` で必ず `cleanup()` を呼ぶ。
- 既定接続の差し替え中に他のコードが `DB::` を触ると別名接続へ行く。
  区間を Factory 呼び出しだけに絞ることで最小化する。

---

## 施策 3: 一次観測の型（fail-closed）

### 変更箇所

- 新規: `tests/Support/Concurrency/ConcurrentProbeObservation.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 7 が本部品の fail-closed を固定する

### 設計

子が返す JSON は**外部入力**である。`tests` は PHPStan の解析対象外なので、
型の保証は**実行時の fail-closed 検証**で作る（この点は概念レビューで明示的に合意した境界）。

```php
/**
 * 子プロセス 1 本ぶんの一次観測。
 *
 * ★勝者の判定は**行の最終状態ではなくこの一次観測**で行う (正典・家系の作法)。
 *   行だけを見ると「2 本とも本処理を実行したが後着が上書きした」形と区別がつかない。
 * ★`fromDecodedJson()` は **fail-closed**。必須キーの欠落・型違い・**未知キー**の
 *   いずれでも例外にする (子と親のプロトコル退行を黙って受け入れない)。
 */
final readonly class ConcurrentProbeObservation
{
    /** 受理する JSON のキー (deny-by-default。ここに無いキーがあれば例外) */
    private const array REQUIRED_KEYS = [
        'child_id', 'nonce', 'http_status', 'handler_executions',
        'cache_default', 'cache_driver',
        'db_driver', 'db_host', 'db_port', 'db_database', 'db_username',
        'observed_go', 'entered_handler',
    ];

    private function __construct(
        public string $childId,
        public string $nonce,
        public int $httpStatus,
        public int $handlerExecutions,
        public string $cacheDefault,
        public string $cacheDriver,
        public string $dbDriver,
        public string $dbHost,
        public int $dbPort,
        public string $dbDatabase,
        public string $dbUsername,
        public bool $observedGo,
        public bool $enteredHandler,
    ) {}

    /** @throws ConcurrencyProtocolException 解釈できない観測は通さない */
    public static function fromDecodedJson(mixed $value): self { /* 全キー厳密検証 */ }

    /** 起動時の割り当てと自己申告が食い違ったら通さない */
    public function assertIdentity(string $expectedChildId, string $expectedNonce): void { /* … */ }
}
```

`fromDecodedJson()` の検証内容（すべて満たさなければ `ConcurrencyProtocolException`）:

1. `$value` が `array<string, mixed>` である
2. キー集合が `REQUIRED_KEYS` と**完全一致**する（欠落も余剰も不可）
3. 各値が期待するスカラー型である（`is_string` / `is_int` / `is_bool` を個別に確認。
   **`(int)` などのキャストで通さない** — 整数 cast の飽和で別の値が通る穴を家系が実際に踏んでいる）
4. `handler_executions >= 0` / `http_status` が 100〜599

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（`mixed` を受けて明示的に判定する。Assert ではなく専用例外へ倒す）
- [x] DTO（配列を素で回さない）
- [x] Generics の型パラメータが正しい（`@var list<string>` を `REQUIRED_KEYS` に付ける）

### テスト計画

- [x] 施策 7 が固定する:
  - 必須キーが 1 つ欠けたら例外
  - 未知キーが 1 つ増えたら例外
  - 型違い（`http_status` が文字列 `"409"`）で例外 = **キャストで通さない**
  - `assertIdentity()` が childId / nonce の食い違いを通さない

### リスク

- キー集合の完全一致は、子の出力を増やすたびに親も直す必要がある。
  これは**意図した硬さ**（プロトコル退行を黙って通さない）であり、緩めない。

---

## 施策 4: 子の起動・遮断・回収・調停

### 変更箇所

- 新規: `tests/Support/Concurrency/ProbeEnvironment.php`
- 新規: `tests/Support/Concurrency/ProbeProcess.php`（interface）
- 新規: `tests/Support/Concurrency/SymfonyProbeProcess.php`
- 新規: `tests/Support/Concurrency/ConcurrencyProbeRunner.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 7（`ProbeProcess` の偽物を差して回収規約を固定する）

### 設計 4-a: `ProbeEnvironment`（開発 DB への到達遮断）

**aicue でいちばん危険な部分**なので、判断を 1 クラスへ集める（子を起こさずに検査できる形にする）。

`tests/Support/ExternalFakes/FakeWiringProbeRunner.php` の 6 点規約を**明示的に踏襲**する
（docblock で名指しする。作法を 2 つに分岐させない）。

```php
/**
 * 子プロセスの設定の出所を作る (開発 DB への到達遮断の中心)。
 *
 * 作法は tests/Support/ExternalFakes/FakeWiringProbeRunner.php の 6 点規約を踏襲する:
 * env -i で環境を作り直す / 専用の一時 env ファイル 1 つだけを設定の出所にする /
 * ディレクトリ 0700・env ファイル 0600 を起動前に検査して違えば子を起こさない /
 * 締切つき実行 / 解釈できない子の出力は fail-closed / finally で必ず片付ける。
 *
 * ★相手 (FakeWiringProbeRunner) は **DB へ接続しないこと**が要件なので DB 座標を渡さない。
 *   こちらは**接続することが要件**なので、遮断の設計を独自に持つ。
 *   「似ているから」で共通基底へ寄せない (寄せると DB 遮断が片方の都合で緩む)。
 */
final class ProbeEnvironment
{
    /** 子の env ファイルへ書いてよいキー (deny-by-default) */
    public const array ALLOWED_ENV_FILE_KEYS = [
        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
        'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
        'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_SSLMODE',
        'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'MAIL_MAILER',
        'BCRYPT_ROUNDS',
    ];

    /** 子へ渡してよい**プロセス環境変数** (env -i で空にしたうえでこれだけ載せる) */
    public const array ALLOWED_PROCESS_ENV_KEYS = [
        'CONCURRENCY_PROBE_ENV_DIR',
        'CONCURRENCY_PROBE_ENV_FILE',
        'APP_CONFIG_CACHE',
    ];

    /**
     * 親の**実行時の実接続設定**から子の env 値を作る。
     *
     * ★値の出所は `config('database.connections.pgsql')` であり env の再読解ではない
     *   (親と子が同じ DB を見ることが構造的に保証される)。
     * ★`DB_URL` は**空文字で固定**する。キーを消すと子の .env 読み込みで復活する
     *   (家系のテンプレートが実装レビューで見つけた実在の穴)。
     *
     * @return array<string, string>
     * @throws RuntimeException 前提が崩れているとき (子を起こさせない)
     */
    public static function envFileValues(): array
    {
        $connection = config('database.default');
        Assert::same($connection, 'pgsql', 'このハーネスは pgsql レーンを前提にする');

        $config = config("database.connections.{$connection}");
        Assert::isArray($config);

        // ★前提検査: 親が DB_URL 主体で接続していると、設定配列の host/port/database は
        //   実効座標とは限らない (URL 解析結果が優先される)。その場合は子を起こさない。
        //   現行レーンは phpunit.xml が DB_URL を <server force value=""/> で空に固定しており
        //   前提は成立している。成立しなくなった日に赤くなる形にしておく。
        $url = $config['url'] ?? null;
        if (is_string($url) && $url !== '') {
            throw new RuntimeException(
                'このハーネスは個別キー接続のレーンを前提にする (DB_URL 主体の設定では'
                .'設定配列の host/port/database が実効座標とは限らないため子を起こさない)'
            );
        }

        $database = (string) ($config['database'] ?? '');

        // ★既存の単一点ガードを**親側でも**通す (allowlist 一致 + dev denylist)。
        //   落ちたら子を起こさない。
        TestDatabaseEnv::assertPgsqlTestDatabaseSafe($database);

        return [ /* APP_* / DB_* / CACHE_STORE=array / QUEUE_CONNECTION=sync / … */ ];
    }

    /** ディレクトリ 0700・env ファイル 0600 でなければ例外 (子を起こさない) */
    public static function assertSafePermissions(int $directoryMode, int $envFileMode): void { /* … */ }
}
```

**`APP_KEY` / `CIPHERSWEET_KEY` の扱い**: `FakeWiringProbeRunner` は使い捨ての値を生成しているが、
**本 probe は既存行（CipherSweet で暗号化された利用者の PII）を読む必要があるため、親の実鍵を渡す**。
そのぶん置き場所を守る（0700 / 0600、起動前の権限検査、`finally` での削除）。
この差分は docblock に**明記する**（相手と違う判断をした箇所を黙って作らない）。

### 設計 4-b: `ProbeProcess`（プロセス抽象）

回収規約を**検査可能**にするため、runner はプロセスを直に触らず抽象越しに扱う。

```php
interface ProbeProcess
{
    public function start(): void;
    public function isRunning(): bool;
    public function exitCode(): ?int;
    public function output(): string;
    public function errorOutput(): string;
    /** SIGTERM → 猶予 → SIGKILL → wait。必ず終了コードを取る */
    public function stopAndReap(float $graceSeconds): ?int;
}
```

`SymfonyProbeProcess` は `Symfony\Component\Process\Process` を包む唯一の実装。

> **保証の境界**: 施策 7 の失敗経路検査が主張するのは
> 「**runner がこの抽象へ停止・kill・wait を必ず要求すること**」までである。
> 実 OS プロセスに対するシグナルの実効性は**保証範囲外**とする
> （実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない）。

### 設計 4-c: `ConcurrencyProbeRunner`（調停）

概念設計の 6 段を実装する。**重なりを祈らずに構成する**のが本部品の核心。

```php
/**
 * 実プロセス 2 本を barrier で同期させて走らせ、一次観測を回収する。
 *
 * 段取り (概念設計 §「本当に重なった」を祈らずに構成する):
 *  1. 子ごとの ready を全員ぶん待つ
 *  2. go を 1 つ置く (ここで初めて 2 本が動き出す)
 *  3. entered-* を待つ (勝者がハンドラに入った)
 *  4. **反対側の out を待ち、その中身を検査する**
 *  5. 検査を通ったら release を置く (勝者がハンドラを完了する)
 *  6. 両方の終了を待ち、観測を回収する
 *
 * ★4 の検査を通す前に release しない。「出てきたから release して、あとから赤くする」形は
 *   結果的に赤にはなるがプロトコルの証拠が弱い。
 * ★3〜5 の待機中は**常に**「2 つ目の entered / 子の異常終了 / 全体の締切」を監視する。
 *   単一ファイルだけを待つブロッキングにすると、二重実行の即時検出という性質が失われる。
 */
final class ConcurrencyProbeRunner
{
    public const float DEFAULT_TIMEOUT_SECONDS = 30.0;
    private const float REAP_GRACE_SECONDS = 1.0;

    /**
     * @param  list<ProbeProcess>|null  $processes  施策 7 が偽物を差すための注入点
     * @return array<string, ConcurrentProbeObservation>  childId => 観測
     */
    public static function run(
        string $idempotencyKey,
        string $plainApiKey,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?array $processes = null,
    ): array { /* … */ }
}
```

**release の 5 条件**（1 つでも欠けたら release せずその場で失敗させる）:

1. `entered-*` が**ちょうど 1 子**ぶん
2. 反対側の `out` が**原子的に完成**している
3. その `out` の **nonce と childId が親の割り当てと一致**
4. その `out` の **HTTP status が 409**
5. その `out` の**ハンドラ実行回数が 0**

**中断条件**（締切を待たずに抜ける）:

- `entered-*` が **2 つ**現れた → `ConcurrencyProtocolException::doubleExecution()`
  （探している退行そのものなので、締切超過という紛らわしい形で出さない）
- 子が観測を出さずに終了した → `ConcurrencyProtocolException::childDiedEarly()`（`stderr` を添える）

**回収**（`finally` で必ず通る）:

1. 生きている子へ `stopAndReap(REAP_GRACE_SECONDS)`（SIGTERM → 猶予 → SIGKILL → wait）
2. 一時ディレクトリ（合図・出力・env ファイル）を再帰削除
3. これらは締切超過・JSON 解釈失敗・`Process` の例外のいずれでも通る

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`array<string, ConcurrentProbeObservation>`）
- [x] null 安全（`Assert` + `?int` の明示）
- [x] DTO を返している（配列の値は観測の値オブジェクト）
- [x] Generics の型パラメータが正しい（`list<ProbeProcess>` / `array<string, string>`）

### テスト計画

- [x] 新規: 施策 7 が `ProbeProcess` の偽物を差して以下を固定する
  - 現れない ready を待ち続けず**締切で例外**になる
  - 応答しない子は締切で**強制回収され**、runner が握った handle を残さない
  - `entered` が 2 つ出たら**締切を待たず**に「二重実行を検出」で落ちる
  - 子が観測を出さずに終わったら**観測なしのまま通さない**
  - `out` の検査（nonce / childId / 409 / ハンドラ 0）を通らなければ **release を置かない**
  - `ProbeEnvironment::envFileValues()` が `DB_URL` 非空のとき例外（子を起こさない）
  - `ProbeEnvironment::envFileValues()` が dev DB 名で例外（`assertPgsqlTestDatabaseSafe` 経由）
  - `assertSafePermissions()` が 0700 / 0600 以外を拒否する
- [x] 個別の `DatabaseTransactions` を使わない

### リスク

- **最大の危険は開発 DB への到達**。遮断は 8 段（概念設計 §開発 DB への到達遮断）で、
  そのうち親側 3 段（`DB_URL` 前提検査 / DB 名の allowlist 検査 / 権限検査）は
  **子を起こさずに単体検査できる**形にしてある。
- 実鍵（`APP_KEY` / `CIPHERSWEET_KEY`）を一時ファイルへ書く。
  `FakeWiringProbeRunner` は「一時ファイルは秘密を 1 つも持たない」を達成しているが、
  本 probe は暗号化 PII を読むため達成できない。**0700 / 0600 + 起動前検査 + finally 削除**で守り、
  この差分を docblock に明記する。

---

## 施策 5: 実行スクリプト（子プロセスの本体）

### 変更箇所

- 新規: `tests/Support/Concurrency/idempotency-claim-probe.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 6 が実プロセスで叩く

### 設計

正典 v1 の要素 (1)。**フレームワークを自前で起動**し、引数で受けた合図で親と同期してから
対象処理を 1 回だけ叩く。判断は runner へ寄せた**薄い入口**にする。

```php
<?php

declare(strict_types=1);

/*
 * 実プロセス並行テストの子 (正典 v1 の要素 (1))。
 *
 * ★責務は 5 つだけ: 設定の出所を固定する / 起動前に DB 座標を検査する / 準備完了を告げて合図を待つ /
 *   本番同等の middleware 列で要求を 1 回だけ投げる / 観測を JSON で書く。
 * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md)。
 * ★**マイグレーションを実行しない**。RefreshDatabase も使わない (スキーマは親のレーンが用意済み)。
 */

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';

// (1) 設定の出所を専用の一時 env ファイル 1 つへ固定する。**bootstrap より前**である。
//     env -i は親のプロセス環境を消すだけで、子がチェックアウトの .env を読むことは止められない。
//     遮断は「環境変数を消す」ではなく「設定の出所を差し替える」で作る。
$app->useEnvironmentPath($environmentDirectory);
$app->loadEnvironmentFrom($environmentFile);

// (2) **bootstrap の前・provider 登録の前**に DB 座標をもう一度検査する (親を信用しない)。
//     ここで落とせば、起動中に DB へ触る provider が動く前に非ゼロで死ぬ。
TestDatabaseEnv::assertPgsqlTestDatabaseSafe($databaseFromEnvFile);

$app->make(Kernel::class)->bootstrap();

// (3) probe route を**この子の app インスタンスへ**登録する。
//     ハンドラは**テスト側コード**なので、アプリコードを 1 バイトも触らずに待たせられる。
//     middleware 列は本番同等 (auth:api-key,api-oauth → resolve.api-actor → idempotent)。
Route::post($uri, function () use ($barrier, $childId, &$handlerExecutions): JsonResponse {
    $handlerExecutions++;

    // 勝者だけがここへ来る。入ったことを告げ、親の release を待つ。
    // これで敗者は**勝者の claim 行が processing のまま在る間に必ず claim へ到達する**。
    $barrier->signal("entered-{$childId}", $nonce);
    $barrier->await('release', $timeoutSeconds);

    return new JsonResponse(['data' => ['ok' => true]], 201);
})->middleware(['auth:api-key,api-oauth', 'resolve.api-actor', 'idempotent'])->name($routeName);

// (4) 準備完了を告げ、go を待つ (ここまでで起動コストを払い切っておく)。
$barrier->signal("ready-{$childId}", $nonce);
$barrier->await('go', $timeoutSeconds);
$observedGo = true;

// (5) 本番同等の HTTP kernel を通して 1 回だけ叩く (実サーバは立てない)。
$response = $httpKernel->handle(Request::create($uri, 'POST', … , [
    'HTTP_AUTHORIZATION' => "Bearer {$plainApiKey}",
    'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
]));

// (6) 観測を JSON で書く。**一時ファイル → rename** で原子的に完成させる。
fwrite(STDOUT, $json);
$barrier->signal("out-{$childId}", $json);
```

**なぜ HTTP kernel を通すのか**: `IdempotentRequest` は
「auth → resolve.api-actor → idempotent → controller」の順序契約を持ち、
`ApiActorContext` attribute が前提である（配線ミスは fail-closed で 500）。
middleware 単体を手で呼ぶと、この順序契約ごと迂回してしまい「本番と同じ経路」ではなくなる。
実サーバは立てず、`Kernel::handle()` でプロセス内の実 middleware 列を通す。

**route 名の一致が load-bearing**: `IdempotentRequest` のスコープは
`(api_key_id, route_name, key)` なので、2 子は**同じ route 名**で登録しなければ衝突しない。
route 名と URI は親が nonce 込みで決めて引数で渡す（`--parallel` でも他と衝突しない）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（クロージャに `: JsonResponse`）
- [x] null 安全（`Assert::stringNotEmpty` で引数を検証）
- [x] `response()->json()` の直書きをしない → **`new JsonResponse(...)`** を使う。
      これは HTTP エンドポイントの応答ではなく**テスト用の probe route の応答**であり、
      アプリの API 契約には現れない（`app/` 配下ではないので `PromptGuardrailTest` 等の走査根にも入らない）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] 施策 6 が実プロセスで叩く（このスクリプト自身の単体検査は作らない —
      実プロセスを起こすテストを増やすと正典の要素 (6) に反する）
- [x] 起動前検査で落ちる経路は施策 7 が `ProbeEnvironment` 側（子を起こさない層）で固定する

### リスク

- 子の起動コスト（autoload + bootstrap）を `ready` の**前**に払い切ることが重要。
  払い切らないと go の後に起動コストが乗り、2 本の到達時刻がばらつく。
- `Request::create()` の body と親が組む `request_hash` は**同一 body** でなければならない
  （違うと 409 conflict になり、測りたいものと別の分岐になる）。親が body を決めて引数で渡す。

---

## 施策 6: 見本テスト（実プロセス版は**この 1 本だけ**）

### 変更箇所

- 新規: `tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本施策そのもの

### 設計

**主張文**（測っていないものを主張しない）:

> 「準備完了を**全員ぶん**確認してから**同一の合図で同時に解き放った実プロセス 2 本**が、
> 同一 actor・同一 route・同一 Idempotency-Key・同一 body の書き込み要求を送ったとき、
> `IdempotentRequest` middleware が**本処理を通したのはちょうど 1 本**であり、
> もう 1 本は本処理を実行せずに `in_progress` (409) で弾かれる。
> **プロセス間で共有されるアプリ側ロックは 1 つも無い**状態で、である。」

```php
/*
 * 冪等キーの実行前 claim を**実プロセス 2 本**で証明する (正典 v1 の要素 (6): 実プロセス版はこの 1 本だけ)。
 *
 * 守られている実装 (App\Http\Middleware\IdempotentRequest::claim) は
 * 「unique 制約が唯一の調停者で、cache ロック等の補助機構は使わない」と宣言している。
 * 本テストはその宣言を実経路の証拠にする — 子の cache を配列固定にし
 * **プロセス間で共有されるアプリ側ロックが 1 つも無い**状態を作ってから測るので、
 * 「アプリ側のロックが効かなくても DB の一意制約だけで本処理が 1 回に収まる」まで言い切れる。
 *
 * ★細かい分岐 (再生 / conflict / 期限切れ再 claim / 順序) は**同一プロセス**の
 *   tests/Feature/Api/IdempotencyConcurrentClaimTest.php が持つ。ここへ足さない。
 */

test('実プロセス 2 本の同時 claim で本処理はちょうど 1 回だけ通る', function (): void {
    // 検体はテストの transaction の**外**に作る (子から見えなければ成立しない)
    [$organization, $owner, $apiKey, $plainKey] = OutOfTransactionFixtures::create(function (): array {
        [$organization, $owner] = createOrganizationWithOwner();
        [$apiKey, $plain] = issueApiKey($organization, $owner);

        return [$organization, $owner, $apiKey, $plain];
    });

    try {
        $observations = ConcurrencyProbeRunner::run(
            idempotencyKey: (string) Str::uuid(),
            plainApiKey: $plainKey,
        );

        // (1) 2 子とも go の観測**後**に要求を開始した
        expect($observations)->toHaveCount(2);
        foreach ($observations as $observation) {
            expect($observation->observedGo)->toBeTrue();
        }

        // (2) ハンドラ実行回数の**合計が 1** ← 一次観測。これが本テストの核心
        $executions = array_sum(array_map(fn ($o) => $o->handlerExecutions, $observations));
        expect($executions)->toBe(1);

        // (3) 敗者は completed の再生ではなく in_progress (409)
        $statuses = array_map(fn ($o) => $o->httpStatus, array_values($observations));
        sort($statuses);
        expect($statuses)->toBe([201, 409]);

        // (4) 2 子とも既定 cache driver が array (= プロセス間共有ロックの土台が不在)
        foreach ($observations as $observation) {
            expect($observation->cacheDefault)->toBe('array');
            expect($observation->cacheDriver)->toBe('array');
        }

        // (5) 2 子の実効 DB 座標が親の渡した値と一致した
        foreach ($observations as $observation) {
            expect($observation->dbDatabase)->toBe(DB::connection()->getDatabaseName());
        }

        // (6) 裏取り: 行は 1 本だけで completed (**別名接続で読む**)
        $rows = OutOfTransactionFixtures::connection()->table('idempotency_keys')->…;
        expect($rows)->toHaveCount(1);
        expect($rows[0]->state)->toBe(IdempotencyState::Completed->value);
    } finally {
        // 子が commit した行は RefreshDatabase の rollback では消えない。必ず片付ける。
        OutOfTransactionFixtures::cleanup(function ($connection) use ($organization): void {
            $connection->table('idempotency_keys')->…->delete();
            // 組織 / 利用者 / API キーも冪等に削除する (cascade を利用する)
        });
    }
});
```

### 施策 6 が**やらないこと**（要素 (6) の遵守）

実行中 / 再生 / conflict / 期限切れ再 claim / 順序といった分岐は
**同一プロセス**の `tests/Feature/Api/IdempotencyConcurrentClaimTest.php` に残す。
ここへ 2 本目の実プロセステストを足さない。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（観測は型付き値なのでプロパティ経由で読む）
- [x] DTO を返している（`ConcurrentProbeObservation`）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] バグ修正ではないので再現テストは不要。ただし**先に赤を見る**手順は踏む —
      「`release` の合図を親が置かない」状態で走らせて締切例外になることを確認してから
      本実装を通す（テストファーストの規約 / AGENTS.md 思考原則 5）
- [x] 既存テストの更新: 施策 8（docblock のみ）
- [x] 個別の `DatabaseTransactions` を使わない
- [x] テストデータは Factory で生成する（`createOrganizationWithOwner` / `issueApiKey` 経由）

### リスク

- 子 2 本の起動で数秒かかる（家系の実測: テンプレートで約 3 秒）。1 本に絞る限り許容範囲。
- 片付け漏れが同一 worker の後続テストを汚す → `finally` + 冪等な削除で塞ぐ。
- 同時接続は**最大 4 本**（親の既定接続 1 + 親の別名接続 1 + 子 2）。
  paratest はテストを worker へ分配するので、本テストは実行全体で 1 回・1 worker でのみ走る。

---

## 施策 7: ハーネス自身の失敗経路の検査

### 変更箇所

- 新規: `tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本施策そのもの

### 設計

ハーネスが**黙って緑になる**壊れ方を塞ぐ。
家系では aigenba とテンプレートが持ち、台帳の gates 欄が名指ししている層である。
**子プロセスを 1 本も起こさない**（`ProbeProcess` の偽物を差す / 純関数を直接叩く）。

| # | 固定する挙動 | 対象 |
|---|---|---|
| 1 | 現れない合図を待ち続けず**締切で例外**になる | `ProcessBarrier` |
| 2 | 合図はあるのに読めないときは**空の合図として通さず**落ちる | `ProcessBarrier` |
| 3 | 中断条件が成立したら**締切を待たずに**抜ける | `ProcessBarrier` |
| 4 | 応答しない子は**停止・kill・wait を要求され**、runner が握った handle を残さない | `ConcurrencyProbeRunner` + 偽 `ProbeProcess` |
| 5 | 子が観測を出さずに終わったら**観測なしのまま通さない** | `ConcurrencyProbeRunner` |
| 6 | `entered` が 2 つ出たら**締切を待たず**「二重実行を検出」で落ちる | `ConcurrencyProbeRunner` |
| 7 | `out` の検査（nonce / childId / 409 / ハンドラ 0）を通らなければ **release を置かない** | `ConcurrencyProbeRunner` |
| 8 | 子の自己申告と起動時の割り当てが食い違ったら**観測を通さない** | `ConcurrentProbeObservation::assertIdentity` |
| 9 | 必須キー欠落 / 未知キー / 型違いを**通さない**（キャストで救わない） | `ConcurrentProbeObservation::fromDecodedJson` |
| 10 | `DB_URL` が非空なら**子を起こさない** | `ProbeEnvironment::envFileValues` |
| 11 | dev DB 名なら**子を起こさない** | `ProbeEnvironment::envFileValues` |
| 12 | 0700 / 0600 以外の権限では**子を起こさない** | `ProbeEnvironment::assertSafePermissions` |

### 保証の境界（明記する）

> 本検査が主張するのは「**runner がプロセス抽象へ停止・kill・wait を要求すること**」までである。
> **実 OS プロセスに対するシグナルの実効性は保証範囲外**とする
> （実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全
- [x] 偽 `ProbeProcess` は interface を実装する（`mixed` を返さない）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] 本施策そのものがテスト
- [x] 個別の `DatabaseTransactions` を使わない（DB に触らない）

### リスク

- 偽物を差す注入点（`ConcurrencyProbeRunner::run()` の `?array $processes`）が
  本番経路と乖離する。既定値 `null` のときに実物を作る形にし、分岐を 1 か所に留める。

---

## 施策 8: 既存テストの「保証しないこと」宣言の是正

### 変更箇所

- `tests/Feature/Api/IdempotencyConcurrentClaimTest.php`（**冒頭 docblock のみ**。テスト本体は無変更）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本施策そのもの（既存テストの削除・上書きはしない）

### 現行コード

```php
/*
 * 冪等キーの「実行前 claim」契約 (T139)。
 * …
 * ★**保証しないこと**: PHP のテストは単一プロセスであり、真の並行 2 本は走らせていない。
 *   `RefreshDatabase` 下では全操作が同一接続・同一トランザクション内で見えるため、
 *   claim の commit も別接続からの可視性も検証していない。本番で後着から claim が
 *   見えるのは「middleware を包む外側 transaction が無い + pgsql の autocommit /
 *   read committed」という前提の帰結であって、テストによる保証ではない。
 */
```

### 変更後コード

```php
/*
 * 冪等キーの「実行前 claim」契約 (T139)。
 * …
 * ★**このテストが保証しないこと**: 単一プロセスであり、真の並行 2 本は走らせていない。
 *   細かい分岐 (再生 / conflict / 期限切れ再 claim / 順序) を決定的に固定するのが本テストの役割である。
 *
 * ★**実プロセス 2 本での裏取りは別にある**:
 *   tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php が
 *   barrier で同期させた実プロセス 2 本で「claim の commit が別接続から見えること」と
 *   「本処理がちょうど 1 回だけ通ること」を測っている。
 *   **埋まったのはこの 2 点だけ**である — 任意の production route や実ジョブの副作用まで
 *   保証したわけではない。
 */
```

### PHPStan適合チェック

- [x] コメントのみの変更（型に影響しない）

### テスト計画

- [x] 既存テストの削除・上書きをしない（docblock のみ）
- [x] 既存テストが引き続き緑であることを確認する

### リスク

- 保証範囲を**広く書きすぎる**と、次に読む人が「ここは証明済み」と誤読する。
  「埋まったのはこの 2 点だけ」と明示的に狭める。

---

## 施策 9: 乖離台帳 D7 の再判定記録と文書追記

### 変更箇所

- `docs/template-divergence.md`（**D7 の中に再判定の記録を追記**。登録の追加・削除はしない）
- `docs/architecture.md`（テスト機構の節へ 1〜2 行）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: **`tests/Support/TemplateDivergence/LedgerPins.php` は変更しない**（下記）

### 乖離台帳の確認（app-design Phase 3-0 の手順）

`docs/template-fingerprints.json` の `entries`（281 キー）を実読して確認した:

| パス | 共有パスか | 判断 |
|---|---|---|
| `tests/Support/Concurrency/*`（新設 11 本） | **無い** | テンプレートに無い領域への上積み。D 登録は不要（テンプレート側の同名機構と衝突しない新設のため）。ただし「登録するか迷ったら登録する」の原則に照らし、**新規登録はしない**判断の根拠を本節に残す |
| `tests/Feature/Concurrency/*`（新設 2 本） | **無い** | 同上 |
| `tests/Unit/Support/Concurrency/*`（新設 1 本） | **無い** | 同上 |
| `tests/Feature/Api/IdempotencyConcurrentClaimTest.php` | **無い** | docblock のみの変更。登録不要 |
| `docs/template-divergence.md` | **無い** | 登録簿そのもの |
| `docs/architecture.md` | **無い** | 登録不要 |
| `phpstan.neon` | **在る**（かつ**採用時債務**にも在る） | **触らない**（下記） |
| `tests/bootstrap.php` | **在る** | **触らない**（既存の `TestDatabaseEnv` を読むだけで変更しない） |

**`phpstan.neon` を触らない判断**:
家系のテンプレートは追従時に `phpstan.neon` へハーネスを追加している（テンプレートは `tests` を解析対象に持つ）。
一方 aicue の `phpstan.neon` の `paths` は `app / config / database / routes` で **`tests` を含まない**。
したがって**追加する意味がない**。加えて `phpstan.neon` は
`docs/template-fingerprints.json` の共有パスかつ `adoption-debt.tsv` の採用時債務（65 行目）に在るため、
変更すると「変更したまま債務に残す」が選べなくなり、
(1) 採用時の姿へ戻す / (2) テンプレートへ同期して債務から削る / (3) 意図的逸脱として登録を書き債務から削る
の三択を迫られる。**意味のない変更のためにこの三択を発生させない**のが最小である。

**D7 の扱い**（**完了扱いにしない。据え置いたうえで再判定の事実を記録する**）:

| D7 の欄 | どうするか |
|---|---|
| 状態 | **恒久のまま据え置き**（完了・削除にしない） |
| 対象パス / 揃え続ける不変条件 | **変更しない** |
| 再判定の記録（追記） | 「{実際に台帳を更新した日} 再判定。非トランザクションの検体置き場（`OutOfTransactionFixtures`）は導入したが、正典 v1 の要素 (6) により実プロセス版は 1 本に絞る。その 1 本は冪等 claim へ割り当てたため、preview 上限の実証は逐次境界のまま据え置く」 |
| 次回再判定の条件（更新） | 「実プロセス並行テストの本数制約を見直すとき、または preview 上限の直列化に退行が疑われたとき」 |

**`LedgerPins` の件数**: 登録の**追加も削除もしない**ので
`DIVERGENCE_ENTRY_COUNT`（36）/ `FINGERPRINT_POPULATION_COUNT`（281）/ `ADOPTION_DEBT_COUNT`（171）は
**いずれも変更しない**。

**`docs/architecture.md` への追記**（1〜2 行）:
実プロセス並行テストという新しい層が入り、しかも**子が DB へ接続する**（= 開発 DB 保護に関わる）ため、
機構の存在と保証範囲を 1 行で指せるようにする。

> 実プロセス並行テスト: `tests/Support/Concurrency` のハーネスが barrier で同期した実プロセス 2 本を走らせ、
> `tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php` が
> 「冪等 claim の本処理はちょうど 1 回」を実経路で固定する（実プロセス版はこの 1 本だけ）。
> 子の DB 座標は親の実接続設定から作られ、`TestDatabaseEnv::assertPgsqlTestDatabaseSafe()` を親子で 2 回通る。

### PHPStan適合チェック

- [x] 文書のみの変更（型に影響しない）

### テスト計画

- [x] 乖離台帳の形式検査（宣言行 / 見出しの実数 / `LedgerPins` の 3 点一致）が緑のままであることを確認する
      （件数を変えないので変化しないはず）
- [x] `composer test` 全体が緑

### リスク

- D7 を「解決済み」と誤読されると、preview 上限の subprocess 実証が入ったと勘違いされる。
  **「完了扱いにしない」と明記**して塞ぐ。

---

## スコープ外（正典が要求しない一般化・過大化はしない）

| # | やらないこと | 理由 |
|---|---|---|
| 1 | 既存の同一プロセス並行テスト 3 本をハーネスへ載せ替える | 正典の要素 (6) が「細かい分岐は同一プロセスのテストへ回す」と**同一プロセス側の存続を前提**にしている |
| 2 | 実プロセス並行テストを 2 本以上作る | 要素 (6) に反する |
| 3 | D7（org 同時 preview 上限）の実証を subprocess へ移す | #2 と同じ。据え置きの判断と根拠は登録簿へ記録する（施策 9） |
| 4 | 課金のチケット確保（`ticket-reserve-commit`）を実プロセスで裏取りする | 同上。道具ができるので**次の TODO で選べる**状態にはなる |
| 5 | 子プロセス数を N 本に一般化する / 任意の処理を叩ける汎用ハーネスにする | 「今必要なものだけ作る」。正典も 2 本立てを基本形として書いている |
| 6 | `FakeWiringProbeRunner` との共通基底の抽出 | 目的が逆（DB へ接続しない / する）。統合すると DB 遮断が緩む |
| 7 | `phpstan.neon` へのハーネス追加 | aicue は `tests` を解析対象に含めない。かつ共有パス + 採用時債務（施策 9） |
| 8 | アプリコード（`app/`）の変更 | 家系の先行 2 例が「本番コードは 1 バイトも変更していない」で達成済み。aicue も注入点を必要としない |
| 9 | 非トランザクションの独立テストレーン（別 suite / 別 CI ジョブ）の新設 | 検体を別名接続へ出すだけで足りる。レーン構成は正典の boundary が**含まないと明記** |
| 10 | ハーネスを見張る Architecture gate（目録検査）の新設 | 正典 gates 欄が「正典にはハーネス自体を見張る検査は無い」と明記 |
| 11 | 解決済み cache store の**具象クラス**の検査 | `getStore()` は `CachePayloadPlainDataGateTest` が境界迂回として deny-by-default で 0 件に固定している。`config('cache.default')` と `CacheManager::getDefaultDriver()` で足りる |
| 12 | `DB_URL` 主体の設定を実効座標へ展開する仕組み | 前提検査で fail-fast すれば足りる（オーバーエンジニアリング） |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) 新規ファイルが 12 本と多く、うち 4 本（`ProbeEnvironment` / `idempotency-claim-probe.php` / `ConcurrencyProbeRunner` / `OutOfTransactionFixtures`）は**開発 DB 保護**と**検体の commit**に関わるため、独立した worktree で赤を確認しながら進めたい。(2) 実プロセスを起こすテストは環境依存が出やすく、他施策と混ぜると切り分けが難しい。(3) `docs/` の変更（施策 9）が乖離台帳の形式検査に関わるため、単独のコミット列で追える形が望ましい |
| 競合リスク | **低**。`app/` を 1 バイトも触らないため、アプリ機能の改修とは衝突しない。触る既存ファイルは `tests/Feature/Api/IdempotencyConcurrentClaimTest.php`（docblock のみ）と `docs/template-divergence.md`（D7 の中）と `docs/architecture.md`（1〜2 行）の 3 本だけ。ただし**乖離台帳を触る別 TODO が並走すると `LedgerPins` の件数で衝突しうる**（本設計は件数を変えないので、衝突しても解決は容易） |

---

## 最終確認（使命・禁止事項チェック）

| 観点 | 確認 |
|---|---|
| 使命への寄与 | REST API v1 の write 経路で「同じ要求が同時に来ても本処理は一度だけ」が実経路の証拠を持つ。「思考ゼロ」の前提（作業者へ二重の指示が出ない）を支える土台である。ただし主張は middleware 契約までに狭め、撮影・レンダの二重実行防止は**帰結**として書き分けた |
| 禁止 1（テストなしの実装完了） | 全施策にテスト計画がある。ハーネス自身にも失敗経路の検査 12 件を付ける |
| 禁止 2（PHPStan の widen / baseline） | `phpstan.neon` を**変更しない**。ignoreErrors も足さない。`app/` を触らないので新規エラーも出ない |
| 禁止 3（dev DB への破壊操作） | 遮断は 8 段。うち親側 3 段は子を起こさずに単体検査できる。子はマイグレーションを実行しない |
| 禁止 4（`response()->json()` 直書き） | 該当なし（probe route の応答は `new JsonResponse(...)`。アプリの API 契約には現れない） |
| 禁止 5・6（LLM / prompt） | 該当なし（LLM を呼ばない） |
| 禁止 7・8（HTTP 応答 / UI） | 該当なし（UI を触らない） |
| 禁止 9（Artifact） | 成果物は本 devnotes 配下のファイルとして出力する |
| `DatabaseTransactions` の個別使用 | しない（`RefreshDatabase` のグローバル適用のまま。検体だけを別名接続で transaction の外へ出す） |
| Factory 使用 | 検体は `createOrganizationWithOwner` / `issueApiKey` 経由で Factory から作る |
| DESIGN.md / Atomic Design | 該当なし（UI / frontend の変更が 0 件） |


---

## 関連する現行コード

### app/Http/Middleware/IdempotentRequest.php (L28-L185 抜粋: docblock と claim)
```php

/**
 * Idempotency-Key middleware (REST API v1 の全 write エンドポイントに配線する)。
 *
 * **実行前 claim 方式**。本処理より先に `state = processing` の行を
 * `insertOrIgnore` で確保し、既存の unique 2 本 (api_key_id / user_id) を**唯一の調停者**に
 * する (cache ロック等の best-effort な二重機構は使わない)。決着は
 * `completed` / `indeterminate` の 2 つだけで、**release (再実行を許す) 経路は持たない**。
 *
 * | 状況 | 応答 |
 * |------|------|
 * | ヘッダ無し | 素通し (冪等行を作らない) |
 * | キーが 255 文字超 | 422 validation_failed (DB に触る前に弾く) |
 * | 初回 (claim 成功) | 本処理を実行。2xx JSON なら completed、それ以外は indeterminate |
 * | 同一キー + 同一 body + completed | 保存応答を再生 (`Idempotent-Replayed: true`) |
 * | 同一キー + 異なる body | 409 idempotency_conflict |
 * | 同一キー + processing | 409 idempotency_in_progress (本処理を実行しない) |
 * | 同一キー + indeterminate | 409 idempotency_indeterminate (本処理を実行しない) |
 *
 * ⚠ **契約変更 (破壊的)**: 4xx / 5xx で終わった要求の後、**同じキーは再利用できない**
 * (以前は再実行できた)。middleware は controller が副作用の前後どちらで失敗したかを
 * 知らないため、再実行せず新しいキーを要求する。契約の正本は `docs/api-idempotency.md`。
 *
 * スコープは actor 単位 × route: API キー actor は (api_key_id, route_name, key)、
 * OAuth user-token actor は (user_id, route_name, key)。同一 key でも別 route なら独立。
 * 保持期間は `config/idempotency.php` が唯一の正本 (クラス定数を復活させない)。
 *
 * 順序契約: auth → throttle → resolve.api-actor → api.project-in-org
 * → api-key.ability → idempotent → controller
 * (api_actor attribute が前提。配線ミスは fail-closed で 500 + report)。
 * **terminable にしない** (finalize は同一リクエストの応答確定前に完了させる)。
 */
class IdempotentRequest
{
    /** `idempotency_keys.key` は varchar(255)。DB に触る前にここで弾く */
    private const MAX_KEY_LENGTH = 255;

    /** claim の再試行回数 (期限切れ行の削除と再 claim の競合ぶん) */
    private const CLAIM_ATTEMPTS = 2;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '') {
            $response = $next($request);
            Assert::isInstanceOf($response, Response::class);

            return $response;
        }
        $key = trim($key);

        // キー長の検証。`key` 列は varchar(255) のため、255 超のヘッダをそのまま claim すると
        // INSERT が 22001 で落ち、本処理を実行しないまま 500 になる。
        // DB に触る前に 422 で弾き、副作用も冪等行も作らない。
        if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
            return ApiErrorResource::make(ApiError::fromCode(
                ApiErrorCode::ValidationFailed,
                details: ['errors' => ['Idempotency-Key' => [
                    'The Idempotency-Key header must not be longer than '.self::MAX_KEY_LENGTH.' characters.',
                ]]],
            ))->response()->setStatusCode(422);
        }

        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
        if (! $actor instanceof ApiActorContext) {
            // 配線ミス (resolve.api-actor middleware が前段に無い)。fail-closed で 500
            report(new LogicException(
                'IdempotentRequest middleware reached without ApiActorContext attribute. '
                .'Ensure resolve.api-actor middleware runs first.',
            ));

            return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::InternalServerError))
                ->response()->setStatusCode(500);
        }

        $routeName = $request->route()?->getName() ?? $request->path();
        $requestHash = $this->hashRequest($request);

        $outcome = $this->claim($actor, $routeName, $key, $requestHash);

        return match ($outcome->status) {
            IdempotencyClaimStatus::Claimed => $this->runAndFinalize($request, $next, $actor, $routeName, $key),
            IdempotencyClaimStatus::Replay => $this->replayResponse($outcome->rowOrFail()),
            IdempotencyClaimStatus::Conflict => $this->errorResponse(ApiErrorCode::IdempotencyConflict),
            IdempotencyClaimStatus::InProgress => $this->errorResponse(ApiErrorCode::IdempotencyInProgress),
            IdempotencyClaimStatus::Indeterminate => $this->errorResponse(ApiErrorCode::IdempotencyIndeterminate),
        };
    }

    /**
     * 実行**前**の claim。unique 制約が唯一の調停者で、cache ロック等の補助機構は使わない。
     *
     * 期限切れ行との競合があるため最大 2 回試行する。2 回とも決着しない場合は
     * **fail-closed** (本処理を実行せず 409 in_progress) にする。
     */
    private function claim(
        ApiActorContext $actor,
        string $routeName,
        string $key,
        string $requestHash,
    ): IdempotencyClaimOutcome {
        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            $now = CarbonImmutable::now();

            // insertOrIgnore: pgsql では `insert ... on conflict do nothing`。
            // 例外を投げないため、外側のトランザクションを巻き込まない。
            $inserted = IdempotencyKey::query()->insertOrIgnore([
                ...$this->ownershipColumns($actor),
                'route_name' => $routeName,
                'key' => $key,
                'request_hash' => $requestHash,
                'state' => IdempotencyState::Processing->value,
                'response_status' => null,
                'response_body' => null,
                'expires_at' => IdempotencyRetention::expiresAt($now),
                // query builder insert は timestamps を自動付与しないので明示する
                'created_at' => $now,
            ]);

            if ($inserted === 1) {
                return IdempotencyClaimOutcome::claimed();
            }

            $existing = $this->rowQuery($actor, $routeName, $key)->first();
            if ($existing === null) {
                continue; // 別リクエストが期限切れ行を消した直後。もう 1 回だけ試す
            }

            if ($existing->isExpired($now)) {
                // 期限切れ行の削除は **同一スコープ + expires_at 条件付き**で行う
                // (主キー同一性クエリを書かない = ModelDirectFetchInvariantTest の母集団に入らない。
                //  同時に、削除と削除の間に作られた新しい行を巻き込まない)
                $this->rowQuery($actor, $routeName, $key)
                    ->where('expires_at', '<=', $now)
                    ->delete();

                continue;
            }

            if ($existing->request_hash !== $requestHash) {
                return IdempotencyClaimOutcome::conflict($existing);
            }

            return match ($existing->state) {
                IdempotencyState::Processing => IdempotencyClaimOutcome::inProgress($existing),
                IdempotencyState::Completed => IdempotencyClaimOutcome::replay($existing),
                IdempotencyState::Indeterminate => IdempotencyClaimOutcome::indeterminate($existing),
            };
        }

        // 2 回とも決着しなかった = 期限切れ削除と再 claim が競り続けている。
        // ここで本処理を走らせると二重実行になりうるので実行しない (fail-closed)。
        return IdempotencyClaimOutcome::inProgress(new IdempotencyKey);
    }

```

### tests/Support/Ci/TestDatabaseEnv.php (全文)
```php
<?php

declare(strict_types=1);

namespace Tests\Support\Ci;

use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * テスト用 DB 名の決定ロジック (pgsql 一本化)。
 *
 * 安全軸: tests/bootstrap.php が pgsqlOverrideDatabase() の算出値
 *   (`<slug>_test_<worktree-hash>`) で DB_DATABASE を後勝ち上書きし、直後に
 *   assertPgsqlTestDatabaseSafe() で「最終 DB 名が test DB (allowlist 一致 + 非 dev)」を
 *   Laravel boot 前に fail-closed 検証する一点に集約する。shell / docker-compose から
 *   DB_DATABASE=<dev DB> が leak しても override + 単一点ガードで dev DB には到達しない。
 *
 * paratest 実行時は Laravel の ParallelTesting が base 名に更に `_test_<token>` を
 * 付与してプロセスごとに分離する (2 段分離)。
 *
 * NOTE: prefix / denylist の 'app' は config('template.slug') の既定値に対応する
 *   (本クラスは Laravel boot 前に走るため config() は使えない)。アプリ初期化時の
 *   init.sh 置換対象。テンプレート派生アプリ名をここへ直書きしないこと
 *   (AppNameHardcodeTest)。
 */
final class TestDatabaseEnv
{
    /** テスト DB 名 prefix。config('template.slug') 既定値 'app' + '_test_' (init.sh 置換対象)。 */
    public const TEST_DB_PREFIX = 'app_test_';

    /** 実テスト DB 名の許可パターン (base または paratest worker)。不可逆 DROP / bootstrap ガードの正の allow。 */
    public const TEST_DB_ALLOWLIST_PATTERN = '/^app_test_[0-9a-f]{8}(_test_[0-9]+)?$/';

    /**
     * dev DB 名の hard-deny 対象 (docker-compose の POSTGRES_DB / slug 既定値)。trim+lowercase 比較。
     *
     * `bug_hunt*` は allowlist regex でも構造的に除外されるが、
     * 「bug-hunt 環境の DB は絶対に触らない」(AGENTS.md §bug-hunt の dev DB 防御) という
     * 意図をコードに残す二重防御として明示列挙する。
     *
     * ★ bug-hunt の並列 cap は 4 (`scripts/bug-hunt-shard.sh` の `BUGHUNT_SHARD_CAP`) だが、
     *   本 denylist は**守る側**なので cap と同期させない。過去 cap=8 期に作られ得る
     *   残留 DB (`bug_hunt_5`..`bug_hunt_8`) を保護し続けるため、意図的に cap より広い。
     *   縮めると防御が後退する (`BughuntShardCapInvariantTest` が値を固定している)。
     */
    public const DEV_DB_DENYLIST = [
        'app',
        'bug_hunt',
        'bug_hunt_1',
        'bug_hunt_2',
        'bug_hunt_3',
        'bug_hunt_4',
        'bug_hunt_5',
        'bug_hunt_6',
        'bug_hunt_7',
        'bug_hunt_8',
    ];

    /**
     * 孤児 sweep の分類ロジックのバージョン。
     *
     * `--confirm` token の canonical JSON に含める。分類規則を変更したら**必ず上げる**こと
     * (古い token では apply できなくなる = 規則変更を人間の再承認なしに通過させない)。
     */
    public const CLASSIFIER_VERSION = 1;

    /** worktree root realpath の決定論的 8 桁 hash。別 worktree との DB 衝突を防ぐキー。 */
    public static function workrootHash(string $projectRoot): string
    {
        $real = realpath($projectRoot);
        Assert::string($real, 'projectRoot must resolve to a real path');

        return substr(sha1($real), 0, 8);
    }

    /**
     * pgsql base テスト DB 名 `<slug>_test_<hash>`。
     * 生成名が dev DB でない・allowlist 準拠であることを Assert する (理論破綻で fail-closed)。
     */
    public static function pgsqlBaseDatabase(string $projectRoot): string
    {
        $name = self::TEST_DB_PREFIX.self::workrootHash($projectRoot);

        if (self::isDevDatabase($name)) {
            throw new InvalidArgumentException("computed test DB name collided with a dev DB: {$name}");
        }
        Assert::true(self::isAllowedTestDatabase($name), "computed test DB name is not allowlisted: {$name}");

        return $name;
    }

    /**
     * pgsql のとき DB_DATABASE に強制すべき base 名。pgsql 以外 / 未設定は null。
     *
     * @param  array<string, mixed>  $server  $_SERVER 相当 (DB_CONNECTION を見て分岐)
     */
    public static function pgsqlOverrideDatabase(array $server, string $projectRoot): ?string
    {
        if (($server['DB_CONNECTION'] ?? null) !== 'pgsql') {
            return null;
        }

        return self::pgsqlBaseDatabase($projectRoot);
    }

    /**
     * 単一点 fail-closed ガード本体。pgsql lane で最終 DB_DATABASE が test DB
     * (allowlist 一致 + 非 dev) でなければ例外。tests/bootstrap.php から Laravel boot 前に呼ぶ。
     *
     * @throws InvalidArgumentException dev DB / 非 allowlist の場合
     */
    public static function assertPgsqlTestDatabaseSafe(string $effectiveDb): void
    {
        if (self::isDevDatabase($effectiveDb)) {
            throw new InvalidArgumentException("refusing to run pgsql tests against a dev DB: {$effectiveDb}");
        }
        Assert::true(self::isAllowedTestDatabase($effectiveDb), "effective pgsql test DB is not allowlisted: {$effectiveDb}");
    }

    /** DB 名が dev DB (variant 含む) か。前後空白・大小バリアントも塞ぐ。 */
    public static function isDevDatabase(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::DEV_DB_DENYLIST, true);
    }

    /** DB 名が test allowlist に一致するか (不可逆 DROP・bootstrap ガードの正の allow)。 */
    public static function isAllowedTestDatabase(string $name): bool
    {
        return preg_match(self::TEST_DB_ALLOWLIST_PATTERN, $name) === 1;
    }

    // ── 孤児テスト DB sweep (drop-test-db.php --orphans) の分類 ──

    /**
     * 孤児判定。**同一候補が複数条件を満たしても結果が一意**になるよう、
     * 以下の順に評価して最初に一致した分類で確定する:
     *
     *   1. Protected — hash が `--protect-hash` に含まれる          → shouldDrop = false (常に保護)
     *   2. Live      — hash が生存 worktree hash 集合に含まれる      → shouldDrop = false (常に保護)
     *   3. Foreign   — hash グループの provenance path が実在する    → shouldDrop = false (常に保護)
     *   4. Orphan    — hash グループの provenance path が実在しない  → shouldDrop = (hash ∈ includeHashes)
     *   5. Unlabeled — hash グループに provenance が無い            → shouldDrop = (hash ∈ includeHashes)
     *
     * - 1 が 2 より先: 明示保護は生存判定より強い (人間の意思を最優先)
     * - 2 が 3 より先: comment は書き換え可能な**分類材料**にすぎず、生存 worktree の突合が優先する
     *   (= comment を細工しても生存 DB は落とせない)
     * - 3 が 4 より先: path が実在する = 誰かが使っている可能性がある側へ倒す (fail-safe)
     * - 4 / 5 は「現在のクローンから生存を**否定できない**」群なので、**どちらも明示指定制**にする
     * - worker DB (`_test_N`) は base と同じ hash グループの分類を継承する (base の provenance が代表)
     *
     * **中核原則: 削除可否を分類だけで自動決定しない。**
     * `$includeHashes` (= `--include-hash`) で人間が 1 つずつ名指しした hash 以外は 1 件も落ちない。
     *
     * @param  list<TestDatabaseCandidate>  $candidates
     * @param  list<string>  $liveHashes  生存 worktree の hash
     * @param  list<string>  $protectedHashes  `--protect-hash`
     * @param  list<string>  $includeHashes  `--include-hash` (Orphan / Unlabeled をこの hash に限り候補化)
     * @param  (callable(string): bool)|null  $pathExists  provenance path の実在判定。
     *                                                     既定は `is_dir()`。**注入すると本メソッドは純関数になる**
     *                                                     (FS を触らずに Foreign/Orphan 分岐を固定できる)
     * @return list<TestDatabaseDecision>
     */
    public static function classifyTestDatabases(
        array $candidates,
        array $liveHashes,
        array $protectedHashes,
        array $includeHashes,
        ?callable $pathExists = null,
    ): array {
        $exists = $pathExists ?? static fn (string $path): bool => is_dir($path);

        $live = self::normalizeHashList($liveHashes, '--live hash');
        $protected = self::normalizeHashList($protectedHashes, '--protect-hash');
        $include = self::normalizeHashList($includeHashes, '--include-hash');

        // provenance は **base DB の comment のみ**を hash グループ全体の出自として扱う。
        // base 不在で worker だけ残っている群は provenance を持たない = Unlabeled になる。
        /** @var array<string, string> $groupProvenance */
        $groupProvenance = [];
        foreach ($candidates as $candidate) {
            if (! $candidate->isWorker && $candidate->provenancePath !== null && $candidate->provenancePath !== '') {
                $groupProvenance[$candidate->hash] = $candidate->provenancePath;
            }
        }

        $decisions = [];
        foreach ($candidates as $candidate) {
            $hash = $candidate->hash;
            $provenance = $groupProvenance[$hash] ?? null;
            $inherited = $candidate->isWorker ? ' (base の分類を継承)' : '';

            if (in_array($hash, $protected, true)) {
                $decisions[] = new TestDatabaseDecision(
                    $candidate,
                    TestDatabaseClassification::Protected,
                    "--protect-hash={$hash} で明示保護{$inherited}",
                    false,
                );

                continue;
            }
            if (in_array($hash, $live, true)) {
                $decisions[] = new TestDatabaseDecision(
                    $candidate,
                    TestDatabaseClassification::Live,
                    "生存 worktree の hash{$inherited}",
                    false,
                );

                continue;
            }
            if ($provenance !== null && $exists($provenance)) {
                $decisions[] = new TestDatabaseDecision(
                    $candidate,
                    TestDatabaseClassification::Foreign,
                    "provenance path が実在する (別クローンが生きている可能性): {$provenance}{$inherited}",
                    false,
                );

                continue;
            }

            $named = in_array($hash, $include, true);
            if ($provenance !== null) {
                $decisions[] = new TestDatabaseDecision(
                    $candidate,
                    TestDatabaseClassification::Orphan,
                    $named
                        ? "provenance path が不在で --include-hash={$hash} に名指しされている: {$provenance}{$inherited}"
                        : "provenance path が不在 (落とすには --include-hash={$hash} が必要): {$provenance}{$inherited}",
                    $named,
                );

                continue;
            }

            $decisions[] = new TestDatabaseDecision(
                $candidate,
                TestDatabaseClassification::Unlabeled,
                $named
                    ? "provenance ラベルなしで --include-hash={$hash} に名指しされている{$inherited}"
                    : "provenance ラベルなし (落とすには --include-hash={$hash} が必要){$inherited}",
                $named,
            );
        }

        return $decisions;
    }

    /**
     * `--apply` の confirm token。
     *
     * canonical JSON (キー順固定 / 要素は昇順 unique の JSON 配列) の SHA-256 **全長 64 桁**。
     * 区切りなしの連結は `["a_b","c"]` と `["a","b_c"]` を区別できないため、必ず JSON 配列にする。
     * `include_hashes` は「どの群を落とすことを人間が承認したか」= 承認文脈の一部なので含める。
     * `classifier_version` は「分類規則を変えたら古い token を無効化する」ために含める。
     *
     * @param  list<string>  $dropTargets  DROP 対象の DB 名
     * @param  list<string>  $liveHashes
     * @param  list<string>  $protectedHashes
     * @param  list<string>  $includeHashes
     */
    public static function orphanConfirmToken(
        array $dropTargets,
        array $liveHashes,
        array $protectedHashes,
        array $includeHashes,
    ): string {
        $sorted = static function (array $values): array {
            /** @var list<string> $values */
            $unique = array_values(array_unique($values));
            sort($unique, SORT_STRING);

            return $unique;
        };

        $canonical = json_encode([
            'classifier_version' => self::CLASSIFIER_VERSION,
            // キー名を 'orphans' にしないのは、実際の対象が Orphan だけでなく
            // Unlabeled も含む「--include-hash で名指しされた DROP 対象」だから。
            'drop_targets' => $sorted($dropTargets),
            'live_hashes' => $sorted($liveHashes),
            'protected' => $sorted($protectedHashes),
            'include_hashes' => $sorted($includeHashes),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    /**
     * hash 引数を検証して昇順 unique に正規化する。形式違反は即例外 (fail-closed)。
     *
     * @param  list<string>  $hashes
     * @return list<string>
     */
    private static function normalizeHashList(array $hashes, string $label): array
    {
        foreach ($hashes as $hash) {
            Assert::regex(
                $hash,
                TestDatabaseCandidate::HASH_PATTERN,
                "{$label} must be 8 lowercase hex chars: {$hash}",
            );
        }
        $unique = array_values(array_unique($hashes));
        sort($unique, SORT_STRING);

        return $unique;
    }
}

```

### tests/Support/ExternalFakes/FakeWiringProbeRunner.php (踏襲する 6 点規約の実在例。L1-L150)
```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 観測用スクリプト (fake-wiring-probe.php) を子プロセスで走らせる。
 *
 * 子の環境は**完全に作り直す** (親から引き継がない)。決め方は 3 段:
 * 1. プロセスの環境変数は `env -i` で空にしてから、必要な分だけを渡す
 *    (親のシェルに残った TESTING_FAKE_* に結果を左右されない。
 *     bug-hunt のスクリプトが DB 資格情報を遮断するときと同じ手である)
 * 2. 設定の出所は**専用の一時環境ファイル 1 つだけ**にする
 *    (`FAKE_WIRING_PROBE_ENV_DIR` / `…_FILE` で子へ渡し、子が
 *     `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定する)。
 *     親のチェックアウトの `.env` / `.env.bughunt.local` は**読ませない**
 *     = 実 Stripe / 外部ログイン / S3 の資格情報は子の設定に 1 つも入らない
 * 3. 設定キャッシュを無効化する。`APP_CONFIG_CACHE` を**存在しない一時パス**へ向け、
 *    キャッシュ無しの起動として観測する (共有の bootstrap/cache を作ったり消したりしない =
 *    並列実行と衝突しない)
 *
 * ★**親の実鍵を複写しない**。`APP_KEY` / `CIPHERSWEET_KEY` は起動のたびに
 *   **使い捨ての値をその場で生成する** (観測は解決と経路の組み立てだけで、既存データの
 *   復号も DB 接続もしないため実鍵は要らない)。これで一時ファイルは秘密を 1 つも持たない。
 * ★それでも置き場所は保護する: 専用の一時ディレクトリを 0700 で作り、環境ファイルは
 *   作成時点から 0600 にする。起動前に権限を確かめ、0600 でなければ**子を起こさずに失敗させる**。
 *   後片付けは finally で行い、timeout・JSON の解釈失敗・Process の例外でも必ず通る。
 *
 * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
 * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
 * 本番混入防止は ProductionEnvGuard の二重判定が受け持つ)。
 */
final class FakeWiringProbeRunner
{
    /**
     * 一時環境ファイルに書いてよいキー (deny-by-default)。
     * 実資格情報のキーは 1 つも無く、鍵の 2 つは使い捨ての生成値である。
     *
     * @var list<string>
     */
    public const array ALLOWED_ENV_FILE_KEYS = [
        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
        'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
    ];

    /**
     * 子プロセスへ渡してよい**プロセス環境変数**のキー (上とは別物なので定数を分ける)。
     * `env -i` で空にしたうえでこの 3 つだけを載せる。
     *
     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
     *   probe が自分で観測して返す。両方を突き合わせて初めて `env -i` の退行が映る。
     *
     * @var list<string>
     */
    public const array ALLOWED_PROCESS_ENV_KEYS = [
        'FAKE_WIRING_PROBE_ENV_DIR',
        'FAKE_WIRING_PROBE_ENV_FILE',
        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
        'APP_CONFIG_CACHE',
    ];

    /** 観測に使う自ホストの URL (実サーバは立てない。経路の組み立てにだけ使う) */
    private const string PROBE_APP_URL = 'http://127.0.0.1:65535';

    /** 環境ファイルの名前 (一時ディレクトリ内で固定) */
    private const string ENV_FILE_NAME = '.env.probe';

    /**
     * 観測を 1 回走らせる。
     *
     * @param  string|null  $baseDirectory  一時ディレクトリを作る親 (省略時は sys_get_temp_dir())
     * @return array{
     *     exitCode: int,
     *     output: array<string, mixed>,
     *     envFileValues: array<string, string>,
     *     directory: string,
     *     directoryMode: int,
     *     envFileMode: int,
     *     configCachePath: string,
     *     configCacheExists: bool,
     * }
     */
    public static function run(
        string $environment,
        bool $fakeExternals,
        bool $fakeStorage,
        bool $fakeLlm,
        ?string $baseDirectory = null,
        float $timeout = 120.0,
    ): array {
        $base = $baseDirectory ?? sys_get_temp_dir();
        $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700) || ! is_dir($directory)) {
            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$directory}");
        }

        try {
            chmod($directory, 0700);

            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
            self::writeEnvFile($envFilePath, $values);

            $directoryMode = self::mode($directory);
            $envFileMode = self::mode($envFilePath);

            // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
            self::assertSafePermissions($directoryMode, $envFileMode);

            $configCachePath = $directory.'/config-cache-absent.php';

            $process = new Process(
                [
                    'env', '-i',
                    'FAKE_WIRING_PROBE_ENV_DIR='.$directory,
                    'FAKE_WIRING_PROBE_ENV_FILE='.self::ENV_FILE_NAME,
                    'APP_CONFIG_CACHE='.$configCachePath,
                    PHP_BINARY,
                    self::probeScriptPath(),
                ],
                FakeClassCatalog::repoRoot(),
                null,
                null,
                $timeout,
            );
            $process->run();

            return [
                'exitCode' => $process->getExitCode() ?? -1,
                'output' => self::decode($process->getOutput()),
                'envFileValues' => $values,
                'directory' => $directory,
                'directoryMode' => $directoryMode,
                'envFileMode' => $envFileMode,
                'configCachePath' => $configCachePath,
                'configCacheExists' => file_exists($configCachePath),
            ];
        } finally {
            self::removeDirectory($directory);
        }
    }

    /**
     * 一時環境ファイルへ書く内容 (許可キー以外は 1 つも作らない)。
```

### tests/bootstrap.php (全文。DB 名の決定と単一点ガード)
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

### tests/Feature/Api/IdempotencyConcurrentClaimTest.php (L1-L70: 是正対象の docblock と probe route ヘルパ)
```php
<?php

declare(strict_types=1);

use App\Enums\Idempotency\IdempotencyState;
use App\Models\IdempotencyKey;
use App\Models\Project;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\Support\OAuthTestHelpers;

/*
 * 冪等キーの「実行前 claim」契約 (T139)。
 *
 * 旧実装は本処理の**後**に保存していたため、同一キーの並行 2 本が両方 controller を
 * 実行し、後着の unique 違反を握り潰していた。本テストは
 *   (a) claim 行が本処理より**前**に作られること (テスト 1)
 *   (b) 同一スコープの 2 本目の INSERT を unique が落とすこと (テスト 3)
 * を固定する。
 *
 * ★**保証しないこと**: PHP のテストは単一プロセスであり、真の並行 2 本は走らせていない。
 *   `RefreshDatabase` 下では全操作が同一接続・同一トランザクション内で見えるため、
 *   claim の commit も別接続からの可視性も検証していない。本番で後着から claim が
 *   見えるのは「middleware を包む外側 transaction が無い + pgsql の autocommit /
 *   read committed」という前提の帰結であって、テストによる保証ではない。
 */

/** report() 経路 (運用アラート) を観測する spy を差し込む */
function spyOnIdempotencyExceptionHandler(): MockInterface
{
    $handler = Mockery::spy(ExceptionHandler::class);
    app()->instance(ExceptionHandler::class, $handler);

    return $handler;
}

/**
 * IdempotentRequest::hashRequest() と同じ規則で request hash を組む
 * (メソッド + パス + body の sha256)。Factory で「同一 body の先行要求」を作るために使う。
 *
 * @param  array<string, mixed>  $payload
 */
function idempotencyRequestHashFor(string $method, string $path, array $payload): string
{
    return hash(
        'sha256',
        $method.'|'.$path.'|'.json_encode($payload, JSON_THROW_ON_ERROR),
    );
}

/**
 * `idempotent` を含む本番同等の middleware 列を持つ probe route を登録する。
 *
 * 実 route (items.store) では controller 実行中の観測や例外送出ができないため、
 * middleware の挙動だけを見たいテストで使う。URI はテストごとに固有にする
 * (`--parallel` でも衝突しないよう呼び出し側が suffix を渡す)。
 *
 * @param  Closure(): mixed  $handler
 */
function registerIdempotencyProbeRoute(string $suffix, Closure $handler): string
{
    $uri = "api/v1/__idempotency_probe_{$suffix}__";

    Route::post($uri, $handler)
        ->middleware(['auth:api-key,api-oauth', 'resolve.api-actor', 'idempotent'])
        ->name("api.v1.__idempotency_probe_{$suffix}__");

    return '/'.$uri;
```

### phpstan.neon (全文)
```
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 10
    paths:
        - app
        - config
        - database
        - routes
    excludePaths:
        - vendor
    ignoreErrors:
        # AppliesCriticalActionContextToAudit は派生アプリの Auditable モデル向けに
        # テンプレートが同梱する trait (テンプレート本体は Auditable モデルを同梱しない
        # ため使用箇所ゼロ)。派生アプリで使用された時点で通常解析される。
        # 実挙動は tests/Feature/Audit/ModelAuditGatingTest.php が検証している。
        -
            identifier: trait.unused
            path: app/Models/Concerns/AppliesCriticalActionContextToAudit.php

```

### database/migrations/2026_06_11_100100_create_idempotency_keys_table.php (unique 2 本)
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency-Key の保存先 (REST API v1 の write エンドポイント用)。
     *
     * - actor 単位 × route で一意: API キー actor は (api_key_id, route_name, key)、
     *   OAuth user-token actor は (user_id, route_name, key)。api_key_id / user_id は
     *   どちらか一方のみ非 NULL (IdempotentRequest middleware が actor 種別で書き分ける)
     * - request_hash はメソッド + パス + body の sha256 (同一 key + 別 body は 409)
     * - response_status / response_body に成功レスポンスを保存し、再送時に再生する
     * - expires_at 超過エントリは未使用扱い (TTL。index は掃除バッチ / lookup 用)
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('route_name');
            $table->string('key');
            $table->string('request_hash');
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();

            // NULL は unique 制約で常に distinct (pgsql / sqlite 共通) のため、
            // 2 本の unique が actor 種別ごとの一意性をそれぞれ担保する
            $table->unique(['api_key_id', 'route_name', 'key']);
            $table->unique(['user_id', 'route_name', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

```

### config/database.php の pgsql 接続
```php
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
```

### tests/Pest.php の該当箇所 (RefreshDatabase グローバル適用 / guard 群 / Factory ヘルパ)
```php

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Vite manifest 不在でも view が描画できるよう test では Vite をスタブする
        $this->withoutVite();

        // 未 fake の LLM 呼び出しを fail-fast させる guard。
        // (1) accumulator clear → (2) Prompt::stopFaking() → (3) PrismManager 差し替え
        // の 3 段で前テスト残留状態を一掃しつつ install する。テスト本体で
        // Prism::fake([...]) / Prompt::fake([...]) を呼ぶと guard は透過される。
        // Prism 基盤を直接テストする稀な Unit テストのみ
        // StrayLlmCallGuard::uninstallForTest($this->app) で opt-out できる。
        StrayLlmCallGuard::install($this->app);

        // 未 fake の外向き HTTP を fail-fast させる guard (裁定 AG-105)。
        // レーン既定として Http::preventStrayRequests() を常時 ON にし、
        // 自機宛て loopback だけを Http::allowStrayRequests([...]) で明示許可する。
        // テスト本体で Http::fake([...]) を呼ぶと該当 URL は透過する
        // (Factory::fake() は prevent フラグを reset しないため共存する)。
        StrayHttpRequestGuard::install($this->app);

        // キャッシュ guard は Tests\TestCase::createApplication() の bootstrap 前に結線済み。
        // ここでは**結線が効いていること**だけを確認する (accumulator には触らない。
        // 触ると起動中に記録された違反が消える)。
        PlainDataCacheGuard::assertInstalled($this->app);
    })
    ->afterEach(function (): void {
        try {
            // stray call が記録されていれば test を fail させる (Service 層の
            // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
            //
            // ★3 つの guard は順に flush する。**同時発生時は先に throw した guard の
            //   詳細だけが表示される** (他方の accumulator は finally の reset で
            //   捨てられる)。test は既に赤いので「静かに緑」にはならず、検出目的は達成される。
            //   すべてを集約する仕組みは入れない (今必要なものだけ作る)。
            StrayLlmCallGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::flushAndFailIfStray();
            PlainDataCacheGuard::flushAndFailIfStray();
        } finally {
            // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
            StrayHttpRequestGuard::reset();
            PlainDataCacheGuard::reset();
        }
    })
    ->in('Feature', 'Unit');

/*
| Architecture lane はファイル走査中心で DB を使わないが、HTTP 出口の既定拒否は
| **全レーン一律**にする (レーンごとに既定が違うと「どのレーンなら外へ出られるか」を

 * @return array{Organization, User} [organization, owner]
 */
function createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    if ($grandfatherFreePlan) {
        $organization->forceFill([
            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
            'free_plan_activated_at' => CarbonImmutable::now(),
        ])->save();
    }

    return [$organization, $owner];
}

/**
 * 滞留回収を 1 系列ぶん実行する (実際に回収する指定)。
 *

 * @param  list<string>  $abilities
 * @return array{ApiKey, string} [apiKey, plainKey]
 */
function issueApiKey(
    Organization $organization,
    User $createdBy,
    array $abilities = ['read', 'write'],
    string $name = 'テストキー',
): array {
    $generated = ApiKey::generatePlainKey();
    $apiKey = ApiKey::createForOrganization(
        $organization,
        $createdBy,
        $name,
        $abilities,
        $generated['prefix'],
        ApiKey::hashSecret($generated['secret']),
    );

    return [$apiKey, $generated['plain']];
}

```
