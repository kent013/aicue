【アプリの使命 (North Star) — AGENTS.md より】
<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項 — AGENTS.md より】
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

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【補足文脈】
- 本件はテストレーンの検査機構であり、UI・DTO・JsonResource・Inertia の変更を含まない (観点 5/6/10/11 は該当なしで構わない)。
- 「家系」= 同一テンプレート (laravel-claude-template) から派生した 6 リポジトリ群。共有台帳 (lctl) の裁定 AG-151 が正典 v2 = 「静的層 + 実行時層」の 2 層を確定済みで、2 層にするか否かは再検討の対象ではない。
- 概念設計は別途 5 ラウンドの合議で APPROVED 済み。本レビューは詳細設計 (実装可能性・型安全・テスト網羅・後退リスク) に集中してほしい。

---

## 詳細設計書

# 詳細設計: キャッシュ素データ規約の実行時層 (正典 v2 追従)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン**推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- `declare(strict_types=1)` + 日本語コメント（git 追跡下の PHP 全数が対象）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **走査器・gate を新設・変更するときに同じ PR で揃える 4 点**（AGENTS.md）:
  負例と正例 / 解決できない形を落とす分岐 / 空振り検知 / docblock に走査対象と保証しないもの

## 概念設計リファレンス

`devnotes/20260818-1757-cache-runtime-plain-data-guard/conceptual-design.md`（Round 5 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S0 | 露出の計測 | `devnotes/20260818-1757-cache-runtime-plain-data-guard/runtime-exposure.md` (新規) | 最高 |
| S1 | 値検査器と例外 | `tests/Support/Cache/PlainDataInspector.php` / `CachePayloadViolation.php` (新規) | 高 |
| S2 | guard 付き受け皿と manager | `tests/Support/Cache/PlainDataGuardedRepository.php` / `PlainDataGuardedCacheManager.php` (新規) | 高 |
| S3 | guard 本体 (結線・accumulator・macro pin) | `tests/Support/Cache/PlainDataCacheGuard.php` (新規) | 高 |
| S4 | 起動前結線と全レーンの後始末 | `tests/TestCase.php` / `tests/Pest.php` | 高 |
| S5 | 実行時層の振る舞い検査 | `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php` (新規) | 高 |
| S6 | 結線の pin (gate) | `tests/Architecture/CacheGuardWiringGateTest.php` (新規) | 高 |
| S7 | 静的層の訂正 + L4 (境界迂回) + 役割追加 | `tests/Architecture/CachePayloadPlainDataGateTest.php` | 高 |
| S8 | 露出の是正 | 計測結果に依存 | 高 |
| S9 | 同梱パッケージのオブジェクトキャッシュを設定で閉じる | `config/prism-prompt.php` / `tests/Feature/Config/ConfigHardeningTest.php` | 中 |
| S10 | 規約の明文化 | `AGENTS.md` / `docs/app-integration-guide.md` / `docs/architecture.md` | 中 |
| S11 | テンプレートとの差の登録 | `docs/template-divergence.md` | 中 |

実装順は概念設計の「実装順」に従う（S0 → S1〜S6 → S8 → S7 → S9〜S11）。

---

## S0: 露出の計測

### 変更箇所

- 新規: `devnotes/20260818-1757-cache-runtime-plain-data-guard/runtime-exposure.md`

### 手順

1. S1〜S4 を先に書く（計測のためだけの仮実装は作らない。**本実装をそのまま使う**）
2. `composer test` と `composer test:browser` を各 1 回走らせる
3. 失敗した test 名・ファイル・違反メッセージ（`OBJECT_FOUND(<クラス>)` 等）を**全件**転記する
4. 出所を `app` / `tests` / `vendor` に分類し、概念設計の判断基準を当てる

### 前提と禁止

- **guard に「違反を許す計測モード」を足さない**（足せば一時免除になる）
- `phpunit.xml` / `phpunit.browser.xml` に `stopOnFailure` / `stopOnError` の指定が**無い**ことを
  確認済み（既定は継続実行）。**途中終了した実行は未計測として扱い、工程を完了にしない**

### テスト計画

- 本施策自体はテストを持たない（記録）。ただし**この記録が無いまま S8 を完了にしない**

### リスク

- 露出が 10 ファイル以上なら実装を止めて設計へ差し戻す（概念設計の判断基準 4）

---

## S1: 値検査器と例外

### 変更箇所

- 新規: `tests/Support/Cache/PlainDataInspector.php`
- 新規: `tests/Support/Cache/CachePayloadViolation.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: S5 が正負コントロールを持つ

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

/**
 * キャッシュへ書き込まれる値が**素のデータ**かを再帰検査する純関数。
 *
 * 素のデータ = 配列 / 文字列 / 数値 / 真偽値 / null だけで構成された値。
 * DTO・Eloquent モデル・Collection・列挙型・日時オブジェクト・クロージャ・resource は違反である
 * (AGENTS.md セキュリティ不変条件 11 / lctl 裁定 AG-107・AG-151)。
 *
 * ## 違反の種別
 *
 * - `OBJECT_FOUND` / `RESOURCE_FOUND` — 規約そのものの違反
 * - `LIMIT_EXCEEDED` — **規約違反ではなく「検査器が素のデータであることを証明できなかった」**
 *   ことを表す。自己参照配列 (`$v['self'] = &$v;`) は素朴な再帰走査を停止させないため、
 *   深さ・ノード数の上限を置き、超過は fail-closed で違反として返す
 *   (証明できない値を通すと guard の意味が消える)。
 *
 * ## 上限値の根拠
 *
 * - 深さ 32: `json_decode` の既定深さ 512 より十分浅く、キャッシュ payload としては 32 段でも異常に深い
 * - ノード 10000: 1 件のキャッシュ entry としては十分大きい
 *
 * 境界の直前・直後は tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が pin する。
 *
 * ## 保証しないもの
 *
 * - **値の意味**は見ない (素のデータであれば内容は問わない)
 * - 配列のキーは見ない (PHP は配列キーを int|string に限るので、キーがオブジェクトになる形は無い)
 */
final class PlainDataInspector
{
    /** 走査の最大深さ (配列の入れ子段数)。超過は LIMIT_EXCEEDED。 */
    public const int MAX_DEPTH = 32;

    /** 走査の最大ノード数 (根の値を 1 と数える)。超過は LIMIT_EXCEEDED。 */
    public const int MAX_NODES = 10000;

    /**
     * 値が素のデータかを再帰検査し、違反を返す (空配列 = 素のデータ)。
     *
     * @return list<string> "<パス> = <種別>(<詳細>)" の形
     */
    public static function violations(mixed $value, string $path = 'value'): array
    {
        /** @var list<string> $violations */
        $violations = [];
        $nodes = 0;

        self::walk($value, $path, 0, $violations, $nodes);

        return $violations;
    }

    /**
     * @param  list<string>  $violations
     */
    private static function walk(mixed $value, string $path, int $depth, array &$violations, int &$nodes): void
    {
        $nodes++;
        if ($nodes > self::MAX_NODES) {
            if (! self::alreadyReportedLimit($violations, 'nodes')) {
                $violations[] = $path.' = LIMIT_EXCEEDED(nodes)';
            }

            return;
        }

        if (is_object($value)) {
            $violations[] = $path.' = OBJECT_FOUND('.$value::class.')';

            return;
        }

        if (is_resource($value)) {
            $violations[] = $path.' = RESOURCE_FOUND('.get_resource_type($value).')';

            return;
        }

        if (! is_array($value)) {
            // null / bool / int / float / string は素のデータ。
            return;
        }

        if ($depth + 1 > self::MAX_DEPTH) {
            $violations[] = $path.' = LIMIT_EXCEEDED(depth)';

            return;
        }

        foreach ($value as $key => $element) {
            self::walk(
                $element,
                $path.'['.(is_int($key) ? (string) $key : "'".$key."'").']',
                $depth + 1,
                $violations,
                $nodes,
            );

            if ($nodes > self::MAX_NODES) {
                return;
            }
        }
    }

    /**
     * @param  list<string>  $violations
     */
    private static function alreadyReportedLimit(array $violations, string $kind): bool
    {
        foreach ($violations as $violation) {
            if (str_ends_with($violation, 'LIMIT_EXCEEDED('.$kind.')')) {
                return true;
            }
        }

        return false;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use RuntimeException;

/**
 * キャッシュへ素のデータでない値を書き込もうとした / 受け皿の境界を迂回したときに投げる。
 *
 * 書き込み呼び出しの**中で** throw されるため、失敗は書き込み元のテストへ帰属する
 * (「読み出しで壊れる」形の弱い検出にしない)。呼び出し元が握り潰しても
 * PlainDataCacheGuard の accumulator に残り、afterEach で必ず赤くなる。
 */
final class CachePayloadViolation extends RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public static function forWrite(string $method, string $key, array $violations): self
    {
        return new self(
            "Cache::{$method}('{$key}') に素のデータでない値が渡されました:".PHP_EOL
            .'  '.implode(PHP_EOL.'  ', $violations).PHP_EOL
            .'キャッシュに入れてよいのは配列 / 文字列 / 数値 / 真偽値 / null だけです。'
            .'読み出し側がアプリのコードで組み立て直せる形 (例: DTO なら toArray()) にしてください。'
            .'規約: AGENTS.md セキュリティ不変条件 11 / '
            .'静的層: tests/Architecture/CachePayloadPlainDataGateTest.php / '
            .'実行時層: tests/Support/Cache/PlainDataGuardedRepository.php'
            .' (LIMIT_EXCEEDED は「guard が素のデータであることを証明できなかった」ことを表す。'
            .'値を小さくするか、キャッシュに入れる形を見直すこと)',
        );
    }

    public static function forBoundary(string $operation, string $detail): self
    {
        return new self(
            "キャッシュ受け皿の境界を迂回しました: {$operation} ({$detail})。".PHP_EOL
            .'受け皿 (Illuminate\Cache\Repository) を跨いで保管先へ届く経路は、'
            .'実行時層が値を見られないため使えません。'
            .'規約: AGENTS.md セキュリティ不変条件 11 / lctl 裁定 AG-151 の境界迂回の hard fail',
        );
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`list<string>` / `self` / `void`）
- [x] null 安全（`mixed` は `is_object` → `is_resource` → `is_array` の順で絞る。
      **この順序が load-bearing** — `is_array` を先に見ると object が漏れる形にはならないが、
      `is_object` を最後にすると `Closure` が `is_array` にも `is_resource` にも当たらず
      「素のデータ」に落ちる分岐を書きかねない）
- [x] DTO を返している（配列返却なし。ここは検査結果の文字列一覧なので DTO 不要）
- [x] Generics の型パラメータが正しい（`list<string>` の参照渡しに `@param list<string>`）

### テスト計画

S5 に置く（正例 / 負例 / 上限の直前直後 / 自己参照）。

### リスク

- `is_resource()` は閉じたリソースに false を返す。閉じたリソースは `get_debug_type()` が
  `resource (closed)` を返し `is_object` にも当たらないため、**「その他」として違反にする分岐が要る**。
  → 上のコードは `is_array` でない残りを素のデータとして通してしまうので、
  **実装時に `is_scalar($value) || $value === null` を明示して、それ以外を `UNKNOWN_TYPE` 違反にする**
  （fail-closed）。S5 の負例に閉じたリソースを入れる

---

## S2: guard 付き受け皿と manager

### 変更箇所

- 新規: `tests/Support/Cache/PlainDataGuardedRepository.php`
- 新規: `tests/Support/Cache/PlainDataGuardedCacheManager.php`

### vendor 実読で確定した前提（Laravel 12 / `vendor/laravel/framework`）

`Illuminate\Cache\Repository` の**値を運ぶ公開 API の合流**:

| 入口 | 合流先 |
|---|---|
| `set($key, $value, $ttl)` | `put()` |
| `setMultiple($values, $ttl)` | `putMany()` |
| `remember($key, $ttl, $cb)` | `rememberWithWarmth()` → `put()` |
| `rememberWithWarmth()` | `put()` |
| `sear()` | `rememberForever()` → `forever()` |
| `rememberForever()` | `forever()` |
| `flexible()` | `putMany()` |
| `offsetSet()` / `$cache[$k] = $v` | `put()` |
| `putMany($values, null)` | `putManyForever()` → `forever()` |
| `touch($key, $ttl)` | 値を運ばない（`store->touch()`） |
| `increment` / `decrement` | 整数のみ（store 直行） |

→ **末端は `put` / `add` / `forever` / `putMany` の 4 つ**。ここだけ override すればよい。

`Illuminate\Cache\CacheManager` の driver 生成はすべて `repository(Store $store, array $config = [])` を
通る（`createArrayDriver` / `createDatabaseStore` / `createFileDriver` / `memo` など）。
`Cache::extend()` の独自 creator だけが `repository()` を通らないので、**S7 の L4 が 0 件で pin する**。

`Repository::tags($names)` は `new TaggedCache($this->store, ...)` を**素で生成**する
（`TaggedCache extends Repository`）。guard 付き受け皿を継承しても、そこから先の書き込みは
検査を通らない。かつ**本番の database store は `supportsTags()` が false でタグ非対応**。
よって `tags()` は境界迂回として throw する。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Cache\Repository;
use UnitEnum;

/**
 * キャッシュ書き込みの**値の実体**を検査する受け皿 (テスト実行時層)。
 *
 * ## なぜ受け皿 (Repository) 境界なのか (イベント購読ではない)
 *
 * `Illuminate\Cache\Events\KeyWritten` の購読は**差し替え可能な境界**であり、
 * テスト本体の `Event::fake()` や store 設定の `'events' => false` で無効化できる。
 * `Illuminate\Cache\Repository` の書き込みメソッドはイベント層より下にあるため、
 * どちらの影響も受けない。
 *
 * ## なぜ 4 メソッドで足りるのか (vendor 実読で確認済み)
 *
 * set → put / setMultiple → putMany / remember → rememberWithWarmth → put /
 * sear → rememberForever → forever / flexible → putMany / offsetSet → put /
 * putMany($v, null) → putManyForever → forever。
 * 合流が将来変わったら CachePayloadPlainDataGuardTest の実 API 経由テストが落ちる。
 *
 * ## tags() を throw する理由
 *
 * vendor の `tags()` は `new TaggedCache($this->store, ...)` を素で生成するため、
 * 継承しても以降の書き込みが検査を通らない。加えて本番の保管方式 (database store) は
 * タグ非対応 (`supportsTags()` が false) なので、タグを使う書き方は本番で例外になる。
 *
 * ## 許可一覧を持たない
 *
 * vendor の書き込みも対象に含める。`config/cache.php` の `serializable_classes => false` の下では
 * **誰が入れたかに関わらず**オブジェクトを入れれば本番の読み出しが失敗するため、
 * vendor の検出は誤検出ではなく本番の潜在バグの発見である (lctl 裁定 AG-107「例外を作らない」)。
 */
final class PlainDataGuardedRepository extends Repository
{
    /**
     * {@inheritDoc}
     */
    public function put($key, $value, $ttl = null)
    {
        if (is_array($key)) {
            // vendor と同じく `$key` が配列なら putMany 形 (値の実体は $key 側)。
            $this->assertPlainData('put', '(many)', $key);

            return parent::put($key, $value, $ttl);
        }

        $this->assertPlainData('put', self::describeKey($key), $value);

        return parent::put($key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function add($key, $value, $ttl = null)
    {
        $this->assertPlainData('add', self::describeKey($key), $value);

        return parent::add($key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function forever($key, $value)
    {
        $this->assertPlainData('forever', self::describeKey($key), $value);

        return parent::forever($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function putMany(array $values, $ttl = null)
    {
        $this->assertPlainData('putMany', '(many)', $values);

        return parent::putMany($values, $ttl);
    }

    /**
     * {@inheritDoc}
     *
     * タグ付きキャッシュは受け皿を跨ぐため使えない (クラス docblock 参照)。
     */
    public function tags($names)
    {
        PlainDataCacheGuard::reportBoundary('tags', self::describeKey($names));
    }

    /**
     * 素のデータでなければ **`parent::` を呼ぶ前に** 記録して例外にする
     * (書き込み元のテストへ失敗を帰属させる)。
     */
    private function assertPlainData(string $method, string $key, mixed $value): void
    {
        PlainDataCacheGuard::inspect($method, $key, $value);
    }

    /** 失敗メッセージ用のキー表現 (キーは string / UnitEnum / 配列を取り得る)。 */
    private static function describeKey(mixed $key): string
    {
        if (is_string($key)) {
            return $key;
        }

        if ($key instanceof UnitEnum) {
            return $key::class.'::'.$key->name;
        }

        return get_debug_type($key);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Arr;

/**
 * すべての cache driver を PlainDataGuardedRepository で包むテスト用 CacheManager。
 *
 * vendor の組み込み driver 生成 (`createArrayDriver()` 等) はいずれも `repository()` を
 * 通るため、ここ 1 箇所の override で array / database / file いずれにも guard が効く。
 * `Cache::extend()` の独自 creator は `repository()` を通る保証が無いため、
 * 静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php の L4) が
 * `Cache::extend()` 自体を 0 件で pin して口を塞いでいる。
 *
 * **本クラスは Illuminate\Contracts\Cache\Store を参照してよい唯一のサイトである**
 * (vendor 互換シグネチャの要求)。`$store` は
 * `new PlainDataGuardedRepository($store, ...)` の第 1 引数以外に現れてはならず、
 * その構造条件は同 gate が機械検査する (store を外へ流出させると受け皿を迂回できる)。
 */
final class PlainDataGuardedCacheManager extends CacheManager
{
    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $config
     * @return PlainDataGuardedRepository
     */
    public function repository(Store $store, array $config = [])
    {
        $repository = new PlainDataGuardedRepository($store, Arr::only($config, ['store']));

        // vendor CacheManager::repository() と同じ event dispatcher 設定を再現する。
        if ($config['events'] ?? true) {
            $this->setEventDispatcher($repository);
        }

        return $repository;
    }
}
```

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: S5（振る舞い）・S7（L3 面の目録に `guard-implementation` 役割で 3 ファイル追加）

### PHPStan 適合チェック

- [x] override は **vendor の宣言をそのまま写す**。実装時に
      `vendor/laravel/framework/src/Illuminate/Cache/Repository.php` と
      `.../CacheManager.php` を開いて可視性・引数名・既定値・戻り値を 1 文字ずつ合わせる
      （親より狭い可視性にすると PHP の致命的エラー、型を狭めると LSP 違反で PHPStan が落ちる）
- [x] `mixed` の絞り込みは `PlainDataInspector` 側で完結
- [x] `Arr::only($config, ['store'])` の戻り値は `array<string, mixed>`

### テスト計画

- S5 が実 API 経由で 13 形（`put` / `add` / `forever` / `putMany` / `set` / `setMultiple` /
  `remember` / `rememberForever` / `sear` / `flexible` / `rememberWithWarmth` /
  `offsetSet` / `??=`）を叩く
- `tags()` の hard fail の負例
- `Event::fake()` の後でも効くことの負例

### リスク

- `rememberWithWarmth` は Laravel 12 に存在することを実読で確認済み。将来の版で消えたら
  S5 が「未定義メソッド」で落ちる = 気付ける

---

## S3: guard 本体

### 変更箇所

- 新規: `tests/Support/Cache/PlainDataCacheGuard.php`

### 責務

| 公開メソッド | 役割 |
|---|---|
| `registerBeforeBootstrap(Application $app): void` | **accumulator と macro 状態の初期化 → `cache` の extender 登録**。bootstrap の前に呼ぶ |
| `assertInstalled(Application $app): void` | 結線が効いていることの確認（beforeEach）。accumulator に触らない |
| `inspect(string $method, string $key, mixed $value): void` | 値検査。違反は記録し**その場で例外**も投げる |
| `reportBoundary(string $operation, string $detail): never` | 境界迂回。記録して例外 |
| `flushAndFailIfStray(): void` | afterEach。記録があれば例外。`finally` で必ず後始末 |
| `reset(): void` | afterEach の `finally`。accumulator の消去と macro の復元 |
| `drainForAssertion(): list<string>` | 意図的違反テスト用（`StrayLlmCallGuard` と同じ） |
| `inspectedCount(): int` | 空振り検知（guard が実際に値を見た回数） |

### 結線の順序（load-bearing）

`registerBeforeBootstrap()` は次の順で行う。

1. **accumulator を空にする**（前テストが異常終了して afterEach が走らなかった場合の残骸を消す）
2. **`Repository::$macros` を検査して復元する**（残骸があれば違反として記録してから空へ戻す）
3. **`$app->extend('cache', …)` を登録する**
4. （呼び出し側が）`bootstrap()` を呼ぶ

★ 1 と 2 を Pest の `beforeEach` へ置いてはならない。結線が bootstrap 前に入る以上、
**起動中に記録された違反が beforeEach の初期化で消える**。provider が例外を握り潰した場合、
accumulator の記録が唯一の証拠である。

### extender の中身と fail-closed

```php
$app->extend('cache', function (mixed $manager, Application $app): PlainDataGuardedCacheManager {
    // ★受け取った実体が素の CacheManager ちょうどでなければ落とす。
    //   独自 creator の登録口 (Cache::extend()) は静的層 L4 が 0 件で pin しているので、
    //   引き継ぐべき状態は無い。想定外の実体を黙って捨てない。
    if ($manager::class !== CacheManager::class) {
        throw new RuntimeException(
            'cache binding が想定外の実体でした: '.get_debug_type($manager).'。'
            .'PlainDataCacheGuard の結線前提 (素の Illuminate\Cache\CacheManager) が崩れている。'
        );
    }

    return new PlainDataGuardedCacheManager($app);
});
```

`Container::extend()` は binding がまだ無くても登録できる（`$this->extenders[$abstract][] = $closure`）。
その後 `CacheServiceProvider::register()` が `singleton('cache', …)` しても、
`bind()` の `dropStaleInstances()` が消すのは instances と aliases だけで **extenders は残る**
（`vendor/laravel/framework/src/Illuminate/Container/Container.php` 実読）。

### `assertInstalled()` の中身（空振り検知）

```php
public static function assertInstalled(Application $app): void
{
    $manager = $app->make('cache');
    if (! $manager instanceof PlainDataGuardedCacheManager) {
        throw new RuntimeException('キャッシュ guard が結線されていません (…);
    }

    // ★RateLimiter は起動中に cache を解決するので、guard 付き受け皿を握っているはずである。
    //   握っていなければ「起動前結線が壊れた」ことの証拠になる。**読むだけで書き換えない**。
    if ($app->resolved(RateLimiter::class)) {
        $property = new ReflectionProperty(RateLimiter::class, 'cache');   // 不在なら ReflectionException
        $repository = $property->getValue($app->make(RateLimiter::class));
        if (! $repository instanceof PlainDataGuardedRepository) {
            throw new RuntimeException('RateLimiter が guard 付きでない受け皿を握っています (…)');
        }
    }
}
```

- `ReflectionProperty` はプロパティが無ければ `ReflectionException` を投げる = **その場で例外**
  （pin の空振り防止）。`hasProperty()` で握り潰さない
- **書き戻しはしない**ので復元契約は不要。二重 install / reset の複数回呼び出しも安全

### macro の pin

```php
/** Repository::$macros が空であることを検査し、空でなければ違反として記録したうえで復元する。 */
private static function pinAndRestoreMacros(): void
{
    $reflection = new ReflectionClass(Repository::class);
    if (! $reflection->hasProperty('macros')) {
        throw new RuntimeException(
            'Illuminate\Cache\Repository::$macros が存在しません。macro 経由の迂回 pin が'
            .'空振りしている。vendor を読み直して pin を作り直すこと。'
        );
    }

    $property = $reflection->getProperty('macros');
    $macros = $property->getValue();
    if (is_array($macros) && $macros !== []) {
        self::$violations[] = 'MACRO_REGISTERED('.implode(', ', array_map(strval(...), array_keys($macros))).')';
    }
    if (! is_array($macros)) {
        throw new RuntimeException('Repository::$macros が配列ではありません: '.get_debug_type($macros));
    }

    $property->setValue(null, []);
}
```

- `registerBeforeBootstrap()`（起動前）と `flushAndFailIfStray()`（afterEach）の**両方**で呼ぶ
- **保証しないもの**: 同一テストの中で登録し、使い、`flushMacros()` で消す形は検出できない

### accumulator と例外の二重化

```php
public static function inspect(string $method, string $key, mixed $value): void
{
    self::$inspected++;

    $violations = PlainDataInspector::violations($value);
    if ($violations === []) {
        return;
    }

    self::$violations[] = "{$method}('{$key}'): ".implode(' / ', $violations);

    throw CachePayloadViolation::forWrite($method, $key, $violations);
}
```

- **記録してから throw する**。`FxRateService` のように読み書きを `try/catch` で囲む実装が
  例外を握り潰しても、afterEach で必ず赤くなる（既存 2 guard と同じ設計）

### `flushAndFailIfStray()` / `reset()`

```php
public static function flushAndFailIfStray(): void
{
    try {
        if (self::$violations === []) {
            return;
        }

        throw new RuntimeException(
            'Plain-data cache violation detected during test execution. '
            .'キャッシュに入れてよいのは素のデータだけ (AGENTS.md セキュリティ不変条件 11 / '
            .'lctl 裁定 AG-107・AG-151)。'.PHP_EOL.self::summarize(self::$violations)
        );
    } finally {
        self::reset();
    }
}

public static function reset(): void
{
    self::$violations = [];
    self::pinAndRestoreMacros();   // 記録は捨てるが状態は必ず既定へ戻す
}
```

★`reset()` の中の macro 復元は**記録を伴わない**（flush の直後なので二重記録しない）ように
実装を分ける。詳細は実装時に `restoreMacros()`（復元のみ）と `pinMacros()`（検査 + 復元）へ分割する。

### PHPStan 適合チェック

- [x] `ReflectionProperty::getValue()` は `mixed`。`is_array()` で絞ってから使う
- [x] `array_map(strval(...), array_keys($macros))` で `list<string>` にする
- [x] `$app->make('cache')` は `mixed`。`instanceof` で絞る
- [x] static プロパティに `@var list<string>` を付ける

### テスト計画

S5 に置く（下記）。

### リスク

- `--parallel` の worker 間で accumulator は共有されない（プロセス内 static）。既存 2 guard と同じ

---

## S4: 起動前結線と全レーンの後始末

### 変更箇所

- `tests/TestCase.php`（`createApplication()` の override を追加）
- `tests/Pest.php`（3 レーンの afterEach に flush / reset を追加、beforeEach に assertInstalled）

### 現行コード（`tests/TestCase.php`）

```php
abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase 後に DatabaseSeeder (Role 等の参照データ) を流す。
     */
    protected bool $seed = true;
}
```

### 変更後コード（`tests/TestCase.php`）

```php
abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase 後に DatabaseSeeder (Role 等の参照データ) を流す。
     */
    protected bool $seed = true;

    /**
     * アプリを生成する。**bootstrap の直前**にキャッシュ guard を結線するために override する。
     *
     * ★Pest の beforeEach では遅い。起動 (bootstrap) 中の書き込みは、
     *   vendor 由来だと静的層の走査根 (app / routes / database / tests) にも入らないため、
     *   結線が遅れると 2 層とも沈黙する穴になる。
     *
     * ★vendor 実装との差は tests/Architecture/CacheGuardWiringGateTest.php が固定する
     *   (WithCachedConfig / WithCachedRoutes の不使用 + vendor 本体の未知の文を落とす検査)。
     */
    #[\Override]
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        PlainDataCacheGuard::registerBeforeBootstrap($app);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
```

- vendor の本体にある `WithCachedConfig` / `WithCachedRoutes` の分岐は**写さない**。
  本リポジトリは両 trait を 1 件も使っていない（走査で確認済み）。
  使い始めたら S6 の gate が赤くなる

### 変更後コード（`tests/Pest.php`、3 レーン共通の追加）

```php
// beforeEach（各レーン）
        // キャッシュ guard は createApplication() の bootstrap 前に結線済み。
        // ここでは**結線が効いていること**だけを確認する (accumulator には触らない。
        // 触ると起動中に記録された違反が消える)。
        PlainDataCacheGuard::assertInstalled($this->app);

// afterEach（各レーン、既存の try の中）
            PlainDataCacheGuard::flushAndFailIfStray();

// afterEach の finally（各レーン）
            PlainDataCacheGuard::reset();
```

- **3 つの guard を順に flush する**。既存コメントの「同時発生時は先に throw した guard の
  詳細だけが表示される」という説明を 3 本立てに更新する

### 波及変更

- テストファイル: S6 が結線を pin、S7 の L3 面に `tests/TestCase.php` は現れない
  （受け手型を参照しないため）

### PHPStan 適合チェック

- [x] `createApplication()` の戻り値は `Illuminate\Foundation\Application`。
      vendor は `@return \Illuminate\Foundation\Application` の docblock だけを持つので、
      **戻り値型を宣言してよい**（狭めていない）
- [x] `require` の戻り値は `mixed`。`Application` であることを `instanceof` で絞ってから使う
      （絞れなければ例外 = fail-closed）
- [x] `#[\Override]` を付ける

### テスト計画

- S6 が結線を静的に pin
- S5 の「起動中の書き込み」負例が結線の実効を固定

### リスク

- Laravel 更新で vendor の `createApplication()` に処理が増えたら override が食い違う
  → S6 の trip-wire が赤くなる（未知の文を落とす fail-closed 走査）

---

## S5: 実行時層の振る舞い検査

### 変更箇所

- 新規: `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php`

### 検査項目（すべて必須）

| # | 検査 | 種別 |
|---|---|---|
| 1 | `Event::fake()` の後でも guard が効く | 負例 |
| 2 | オブジェクトを渡すと例外: `put` / `add` / `forever` / `putMany` | 負例 |
| 3 | 同: `set` / `setMultiple` / `remember` / `rememberForever` / `sear` / `flexible` / `rememberWithWarmth` | 負例（合流の実証） |
| 4 | 同: `offsetSet`（`$cache[$k] = $v`）/ `??=` | 負例（合流の実証） |
| 5 | クロージャを渡すと例外（Closure もオブジェクト） | 負例 |
| 6 | 素のデータは通る（配列 / 文字列 / 整数 / 浮動小数 / 真偽値 / null / 入れ子） | 正例 |
| 7 | 違反メッセージに method / key / 違反パスと種別 / AGENTS.md への参照が載る | 正例 |
| 8 | `PlainDataInspector`: object / resource / **閉じたリソース** / Closure / 日時 / Collection を違反にする | 負例 |
| 9 | `PlainDataInspector`: 素のデータは違反にならない | 正例 |
| 10 | 深さ 32 は通り 33 は `LIMIT_EXCEEDED(depth)` | 境界 |
| 11 | ノード 10000 は通り 10001 は `LIMIT_EXCEEDED(nodes)` | 境界 |
| 12 | 自己参照配列は `LIMIT_EXCEEDED` になる（停止する） | 境界 |
| 13 | `tags()` は境界迂回として例外になる | 負例 |
| 14 | **macro を登録 → macro 経由で保管先へ直接書き込む → flush で違反になる** | 負例 |
| 15 | **起動中（provider の `boot()`）の書き込みを、provider が握り潰しても afterEach で捕まえる** | 負例 |
| 16 | アプリ側が握り潰しても accumulator に残る（`try { … } catch (Throwable) {}` の形） | 負例 |
| 17 | `reset()` を複数回呼んでも安全 / `drainForAssertion()` の後は次テストへ漏れない | 後始末 |
| 18 | `inspectedCount()` が 0 でない（guard が実際に値を見ている＝空振り検知） | 空振り検知 |

### 実キャッシュ書き込みの集約（静的層との整合）

本ファイルの実キャッシュ書き込みは**ヘルパ関数 1 つに集約する**。静的層の L2 目録は
`パス::メソッド名` 粒度なので、テストの並べ替えで目録がずれないようにするためである。

```php
/**
 * guard 付き受け皿へ**実 API 経由**で書き込む (合流の実証用)。
 *
 * remember / rememberForever / sear / set / setMultiple / flexible /
 * rememberWithWarmth / offsetSet は vendor 実装が put / add / forever / putMany へ
 * 合流する。その合流が将来変わったら本テストが落ちる (guard の被覆が静かに減らない)。
 */
function cachePayloadGuardWrite(string $method, string $key, mixed $value): void
{
    $cache = Cache::store('array');

    match ($method) {
        'put' => $cache->put($key, $value, 60),
        'add' => $cache->add($key, $value, 60),
        'forever' => $cache->forever($key, $value),
        'putMany' => $cache->putMany([$key => $value], 60),
        'set' => $cache->set($key, $value, 60),
        'setMultiple' => $cache->setMultiple([$key => $value], 60),
        'remember' => $cache->remember($key, 60, fn () => $value),
        'rememberForever' => $cache->rememberForever($key, fn () => $value),
        'sear' => $cache->sear($key, fn () => $value),
        'flexible' => $cache->flexible($key, [60, 120], fn () => $value),
        'rememberWithWarmth' => $cache->rememberWithWarmth($key, 60, fn () => $value),
        'offsetSet' => $cache[$key] = $value,
        'offsetCoalesce' => $cache[$key] ??= $value,
        default => throw new InvalidArgumentException("未知の書き込みメソッド: {$method}"),
    };
}
```

### 起動中の負例（検査 15）の組み方

通常のテスト用アプリへ provider を足すと bootstrap 中に落ちてテスト本体へ到達しない。
そこで**テストの中で第 2 のアプリを組み立てる**。

```php
test('起動中の書き込みは、provider が握り潰しても afterEach で捕まえる', function (): void {
    $container = Container::getInstance();
    $resolvedFacades = Facade::getFacadeApplication();

    try {
        $app = require base_path('bootstrap/app.php');
        expect($app)->toBeInstanceOf(Application::class);

        // ★TestCase::createApplication() と**同じ関数**を通す (施策 S6 の gate が pin する)。
        PlainDataCacheGuard::registerBeforeBootstrap($app);
        $app->register(BootTimeCacheWriteProbeProvider::class);
        $app->make(Kernel::class)->bootstrap();

        // provider の boot() が自分で握り潰したので bootstrap は完走する。
        $drained = PlainDataCacheGuard::drainForAssertion();
        expect($drained)->not->toBe([]);
        expect(implode(PHP_EOL, $drained))->toContain('OBJECT_FOUND');
    } finally {
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($resolvedFacades);
        // 第 2 のアプリで汚した accumulator / macro を必ず戻す
        PlainDataCacheGuard::reset();
    }
});
```

- `BootTimeCacheWriteProbeProvider` は `tests/Support/Cache/` に置き、`boot()` で
  `try { Cache::put('probe', new stdClass, 60); } catch (Throwable) { /* 握り潰す */ }` を行う
- **握り潰すこと自体が検査対象**なので、`catch` を消したらこのテストは別の理由で落ちる
  （bootstrap が例外になる）= どちらでも赤くなる

### PHPStan 適合チェック

- [x] `Cache::store('array')` は `Repository`。`$cache[$key] = $value` は `ArrayAccess`
- [x] `Facade::getFacadeApplication()` の戻り値の型を絞ってから復元する
- [x] `require` の戻り値を `instanceof Application` で絞る

### テスト計画

本施策がテストそのもの。**テストファーストで先に赤くしてから S1〜S4 を書く**。

### リスク

- 第 2 のアプリの生成でコンテナ・facade を汚す → `finally` で必ず復元する。
  復元漏れは後続テストが連鎖的に落ちるので気付ける（無音で緑にならない）

---

## S6: 結線の pin（gate）

### 変更箇所

- 新規: `tests/Architecture/CacheGuardWiringGateTest.php`

### 検査項目

| # | 検査 | 内容 |
|---|---|---|
| W1 | `tests/TestCase.php` の `createApplication()` が **`bootstrap()` より前**に `PlainDataCacheGuard::registerBeforeBootstrap()` を呼ぶ | 字句走査（`PhpToken`。コメント・文字列を落とす） |
| W2 | `tests/Pest.php` の **3 レーンすべて**の afterEach に `flushAndFailIfStray()` と `reset()` がある | 字句走査。既存 `StrayHttpEgressLaneGateTest` と同じ作法 |
| W3 | `tests/Pest.php` の 3 レーンすべての beforeEach に `assertInstalled()` がある | 同上 |
| W4 | `WithCachedConfig` / `WithCachedRoutes` を使うファイルが `tests/` に **0 件** | 追跡下の PHP 走査（`TrackedPhpSourceFiles` を使う） |
| W5 | vendor の `Illuminate\Foundation\Testing\TestCase::createApplication()` の本体が**既知の形だけ**で出来ている | 反射でソースを取り、字句に割って**未知の文が 1 つでもあれば落とす**（fail-closed） |
| W6 | 起動中の負例（S5 検査 15）が `TestCase::createApplication()` と**同じ関数**を呼ぶ | 字句走査で両方に `registerBeforeBootstrap` があることを確認 |
| W7 | 空振り検知 | 走査ファイル数 > 0 / W5 で取れた文の数 > 0 / 検出器が負例で反応すること |
| W8 | 負のコントロール | 合成入力（nowdoc）で「flush が無いレーン」「bootstrap の後で結線するコード」「未知の文が混ざった vendor 本体」を検出できること |

### W5 の作り方（fail-closed）

```php
$method = new ReflectionMethod(BaseTestCase::class, 'createApplication');
$source = 実ファイルの getStartLine()〜getEndLine() を取り出す;
$statements = PhpToken::tokenize($source) からコメント・空白を落として `;` / `}` で区切る;

// 既知の形 (現行 Laravel 12 の実体):
//   1. $app = require Application::inferBasePath().'/bootstrap/app.php';
//   2. $this->traitsUsedByTest = class_uses_recursive(static::class);
//   3. if (isset(CachedState::$cachedConfig, …)) { $this->markConfigCached($app); }
//   4. if (isset(CachedState::$cachedRoutes, …)) { $app->booting(…); }
//   5. $app->make(Kernel::class)->bootstrap();
//   6. return $app;
// これ以外の文が現れたら fail (未解決を落とす)。
```

- **保証範囲を docblock に書く**: 見るのは vendor の当該メソッドの本体だけ。
  `setUp()` / `refreshApplication()` の変更や、bootstrapper の増減は見ない
- **「文字列があるか」の確認にしない**。既知の形の集合と一致しない文があれば落とす

### PHPStan 適合チェック

- [x] `ReflectionMethod::getFileName()` は `string|false`。false なら例外（fail-closed）
- [x] `file()` の戻り値は `array<int,string>|false`

### テスト計画

W8 の負のコントロールを**先に書いて赤くする**（既存の抽出器を流用して最初から緑になる場合は、
負例が押さえる分岐を一時的に壊して赤を確認する）。

### リスク

- W5 は Laravel の更新のたびに人の手当てが要る。**それが目的**（override が静かに食い違わない）

---

## S7: 静的層の訂正 + L4（境界迂回）+ 役割追加

### 変更箇所

- `tests/Architecture/CachePayloadPlainDataGateTest.php`
  - 冒頭 docblock（L11-16 の誤った主張の削除、2 層構成の説明の追加）
  - `CACHE_PAYLOAD_NON_WRITE_METHODS` から `extend` を削除
  - `CACHE_PAYLOAD_CHAIN_METHODS` から `getstore` / `tags` を削除
  - `CACHE_PAYLOAD_BYPASS_METHODS`（新設）
  - `cachePayloadFollowChain()` の `$kind` に `bypass` を追加
  - `cachePayloadCollectFromSource()` に `new <受け手型>` の検出を追加
  - 検査 L4a / L4b / L4c（新設）と検査 6b の語彙健全性に BYPASS を追加
  - `CACHE_PAYLOAD_SURFACE_INVENTORY` に `guard-implementation` 役割と 3 ファイルを追加
  - `CACHE_PAYLOAD_WRITE_INVENTORY` に S5 のヘルパ由来の entry を追加（`kind` 欄を新設）
  - 既存の負のコントロール（`Cache::getStore()->put(...)` / `Cache::tags([...])->forever(...)`）を
    「書き込みの検出」から「迂回の検出」へ組み替える

### 現行コード（冒頭 10-16 行。**削除する**）

```php
 * ★なぜ静的検査か (実行時検出では捕まらない):
 *   テストレーンは phpunit.xml で CACHE_STORE=array、config/cache.php の array store は
 *   'serialize' => false。**オブジェクトを put してもそのまま返る = テストは緑になる**。
 *   本番は database store で serialize され、serializable_classes => false のため
 *   読み戻しは __PHP_Incomplete_Class になる。つまり「テストで再現しない本番専用の壊れ方」であり、
 *   実行時 detector (KeyWritten 購読等) は原理的にこの穴を塞げない。
```

### 変更後コード（冒頭）

```php
 * ★2 層構成のうち**静的層**がこのファイルである (lctl 裁定 AG-151 = 正典 v2)。
 *   - 静的層 (ここ) が保証するのは「**申告なしに書き込み経路を増やせない**」ことである。
 *     目録の payload 欄は**人間の申告**なので、書いた値が実際に素データかは保証しない
 *   - 実行時層 (tests/Support/Cache/PlainDataCacheGuard.php) が保証するのは
 *     「**テストが実行した書き込みの値が実際に素データである**」ことである。
 *     受け皿 (Illuminate\Cache\Repository) を包んで保管先へ渡す前の値を再帰検査するので、
 *     **直列化を一度も経由しない = テストレーンの array store でも同じように発火する**
 *   - どちらも他方を包含しない。vendor 由来の書き込みは静的層の走査根に入らず (実行時層だけが見る)、
 *     テストが 1 度も踏まない経路は実行時層に見えない (静的層だけが見る)
 *
 *   ※ 旧版のこの位置には「実行時 detector は原理的にこの穴を塞げない」という記述があったが、
 *     これは**書き込みイベントを購読する型の検出器にだけ当てはまる主張**で、
 *     受け皿を包んで値を見る型には当てはまらない。裁定 AG-151 が誤りとして棄却したので削除した。
```

### 新設する語彙

```php
/**
 * 受け皿 (Repository) を跨いで保管先 (Store) へ届く / 受け皿の生成そのものに割り込む API。
 * **0 件で pin する** (lctl 裁定 AG-151 の v2 要素 4「境界迂回の hard fail」)。
 *
 * - extend    独自 creator は CacheManager::repository() を通らないので実行時層の被覆から抜ける
 * - getStore / setStore  保管先を直接触る = 受け皿を跨ぐ
 * - tags      vendor の tags() は new TaggedCache(...) を素で生成するので guard が効かない。
 *             加えて本番の database store は supportsTags() が false でタグ非対応
 * - macro / mixin / flushMacros  Repository は Macroable を use しており、
 *             macro 内から $this->store へ直接到達できる (末端 4 メソッドを通らない)
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_BYPASS_METHODS = [
    'extend', 'getstore', 'setstore', 'tags', 'macro', 'mixin', 'flushmacros',
];
```

### `new <受け手型>` の検出

`cachePayloadReceiverNames()` は「型名の直後が `(` なら型宣言ではなく呼び出し / インスタンス化」
として**跨いでいる**（誤検出回避のための既存の分岐）。ここでは**跨ぐ前に `new` かどうかを見て**、
`new` なら迂回として記録する。

```php
// cachePayloadCollectFromSource() の中、受け手型を解決した直後
$prev = cachePayloadPrev($tokens, $i - 1);
if ($prev !== null && $tokens[$prev]->is(T_NEW)) {
    $bypasses[] = "{$relative}:{$token->line} → new {$token->text}()";
}
```

- 現状 `new Illuminate\Cache\Repository` / `new CacheManager` / `new TaggedCache` は **0 件**
- guard の実装は**自前のサブクラス**（`new PlainDataGuardedRepository(...)`）を生成するので
  受け手型に一致せず、迂回にならない。よって**免除目録を持たない 0 件 pin にできる**

### 検査 L4a / L4b / L4c

```php
test('検査 L4a: 受け皿の境界を迂回する API 呼び出しが 0 件である', function (): void {
    $result = cachePayloadCollectAll();
    expect($result['bypasses'])->toBe([], '…復旧手順…');
});

test('検査 L4b: 受け手型の直接生成が 0 件である', function (): void { … });

test('検査 L4c: guard 付き manager は $store を受け皿の第 1 引数以外へ流さない', function (): void {
    // tests/Support/Cache/PlainDataGuardedCacheManager.php を字句走査し、
    // `$store` の出現が (1) 型宣言の直後 (2) new PlainDataGuardedRepository( の第 1 引数
    // の 2 か所ちょうどであることを固定する。
    // ★store を外へ流出させると、受け皿を通さない書き込み経路を作れてしまう。
});
```

### 目録の追加

```php
// L3 面
    'tests/Support/Cache/PlainDataGuardedRepository.php' => [
        'role' => 'guard-implementation',
        'rationale' => '実行時層の受け皿。Illuminate\Cache\Repository を継承して末端 4 メソッドを検査する。キャッシュ API 呼び出しは持たない',
    ],
    'tests/Support/Cache/PlainDataGuardedCacheManager.php' => [
        'role' => 'guard-implementation',
        'rationale' => '実行時層の manager。Store 型を参照してよい唯一のサイトで、repository() を override して受け皿を差し替える',
    ],
    'tests/Support/Cache/PlainDataCacheGuard.php' => [
        'role' => 'guard-implementation',
        'rationale' => '実行時層の結線と accumulator。Repository::$macros の pin のために Repository を参照するだけで API は呼ばない',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php' => [
        'role' => 'write',
        'rationale' => '実行時層の振る舞い検査。意図的に違反する値を書いて guard が落とすことを固定する唯一のファイル',
    ],
```

`cachePayloadRoleViolations()` に `guard-implementation` を足す。規則は
**「キャッシュ API 呼び出しが 0 件であること」+「L2 目録に entry を持たないこと」**
（受け手型を参照するだけの実装であることの申告）。正負コントロールを検査 5b に追加する。

### L2 目録の `kind` 欄

```php
/**
 * kind = 'plain'        …素データを入れる本来の経路。proof は**配列往復を固定する単体テスト**
 *        'guard-selftest' …実行時層が違反を検出することを固定するための意図的な違反。
 *                          proof は**その検出を固定する振る舞い検査**
 */
const CACHE_PAYLOAD_WRITE_INVENTORY = [
    'app/Services/FxRateService.php::put' => [
        'kind' => 'plain',
        'count' => 1,
        …既存のまま…
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::put' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass / Closure 等) と素データの両方。guard が前者だけを落とすことを固定する',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '実行時層が「保管前の値を再帰検査して落とす」ことを実 API 経由で固定する唯一の場所。ここが無いと申告の裏取りが機械化されない',
    ],
    …add / forever / putMany / set / setMultiple / remember / rememberforever /
      sear / flexible / rememberwithwarmth も同様…
];
```

検査 3 を `kind` で分岐させる（`plain` は proof に「往復を固定する単体テスト」を要求、
`guard-selftest` は proof に「振る舞い検査」を要求。どちらも**実在を検査する**）。
`kind` が 2 値のどちらでもなければ落とす。

### 既存の負のコントロールの組み替え

- 「負のコントロール: コンテナ解決・getStore・literal 動的呼び出しの書き込みを検出する」の
  `Cache::getStore()->put('d', [1], 60);` は、**迂回として検出される**ことへ変える
- 「負のコントロール: facade / チェーン / ヘルパ / DI の書き込みを検出する」に含まれる
  `Cache::tags(['t'])->forever('f', [1]);` も同様

### PHPStan 適合チェック

- [x] `cachePayloadCollectAll()` の戻り値の array shape に `bypasses: list<string>` を足す
- [x] 目録の array shape に `kind: string` を足す

### テスト計画

- L4a / L4b / L4c の**負のコントロールを先に書く**（nowdoc の合成入力で
  `Cache::extend(...)` / `Cache::getStore()` / `$repo->tags([...])` / `Repository::macro(...)` /
  `new Repository($store)` を検出できること）
- 正のコントロール: `new PlainDataGuardedRepository($store, [])` を**迂回にしない**こと
- 検査 5b に `guard-implementation` の正負を追加
- 検査 7（空振り検知）に「迂回の検出器が負例で反応する」ことを追加

### リスク

- `extend` を NON_WRITE から外すと、既存の `Cache::extend` 呼び出しがあれば赤くなる
  → 走査で 0 件を確認済み（`app` / `routes` / `database` / `tests` に無い）
- `tags` を CHAIN から外すと、既存の使用があれば赤くなる
  → 使用は本 gate の fixture（nowdoc）1 件だけであることを確認済み

---

## S8: 露出の是正

### 変更箇所

- S0 の計測結果に依存する（現時点では `app/` `tests/` に既知の違反は無い）

### 判断基準（概念設計より）

1. `app/` → 必ず直す（素の配列にして入れ、読み戻しで組み立て直す。L2 目録へ登録）
2. `tests/` → 必ず直す
3. vendor 由来 → (a) 所有する設定で閉じる / (b) 使わない形へ直す /
   (c) どちらもできなければ**実装を完了にせず**設計へ差し戻し、台帳の議題として起こす。
   **guard 側に許可一覧を足す選択肢は取らない**
4. 10 ファイル以上 → 実装を止めて設計へ差し戻し、TODO を分割する

### テスト計画

- 是正した経路ごとに、素データであることを固定する単体テスト（往復）を用意し、
  L2 目録の `proof` に書く

---

## S9: 同梱パッケージのオブジェクトキャッシュを設定で閉じる

### 変更箇所

- `config/prism-prompt.php`（L90-94）
- `tests/Feature/Config/ConfigHardeningTest.php`

### 現行コード

```php
    'cache' => [
        'enabled' => env('PRISM_PROMPT_CACHE', true),
        'ttl' => 3600,
        'store' => null, // null = default cache driver
    ],
```

### 変更後コード

```php
    /*
    | ★enabled は false 固定 (env を介さない)。
    |   vendor の Kent013\PrismPrompt\PromptTemplate::fromYaml() は
    |   Cache::store(...)->put($cacheKey, $instance, $ttl) で **PromptTemplate オブジェクトそのもの**を
    |   キャッシュへ入れる。これは AGENTS.md セキュリティ不変条件 11 (キャッシュに入れるのは
    |   素のデータだけ) に反する。有効・無効を決める設定は本リポジトリが所有しているので、
    |   既定で閉じる。env で開け直せる形は残さない (開いた瞬間に規約違反になるため)。
    |   ※現行コードを確認した範囲では fromYaml() の呼び出し元が無く、観測できる挙動の変化は
    |     見込まれない。効果はパッケージ更新等で呼び出し元が生まれたときの fail-safe である。
    */
    'cache' => [
        'enabled' => false,
        'ttl' => 3600,
        'store' => null, // null = default cache driver
    ],
```

### `ConfigHardeningTest` への追加（既存の二段 pin と同じ形）

```php
// ========== prism-prompt: テンプレートのオブジェクトキャッシュを持たない ==========

test('config/prism-prompt.php は cache.enabled を false で宣言している', function (): void {
    // 宣言 pin (config ファイルを直接評価する。env でも開けられないことを見る)
    $config = evaluateConfigFileWithEnv('prism-prompt.php', ['PRISM_PROMPT_CACHE' => 'true']);
    expect($config['cache']['enabled'])->toBeFalse(
        'PromptTemplate::fromYaml() がオブジェクトをキャッシュへ入れるため、env で開けられてはならない');
});

test('prism-prompt.cache.enabled は実行時にも false', function (): void {
    // 実効値 pin
    expect(config('prism-prompt.cache.enabled'))->toBeFalse();
});
```

- `evaluateConfigFileWithEnv()` は既存 helper（`serializable_classes` の宣言 pin で使用中）。
  **env を与えても false のまま**であることを見るのが要点

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- `.env.example`: `PRISM_PROMPT_CACHE` の記述があれば削除する（`EnvExampleInvariantTest` が
  同期を見ているので、同じ変更で揃える）

### リスク

- 呼び出し元が生まれたときにテンプレート解析が毎回走る（性能）。
  現状 0 件なので影響なし。必要になったら**素の配列を入れる形**へ直してから開ける

---

## S10: 規約の明文化

### 変更箇所

- `AGENTS.md` セキュリティ不変条件 11
- `docs/app-integration-guide.md` §7 不変条件 6
- `docs/architecture.md`（新節「キャッシュ素データ規約の 2 層」）

### 現行コード（`AGENTS.md` 不変条件 11 の末尾）

```
    **テストは array store で緑になり本番 database store でだけ壊れる**ため、
    書き込み経路とキャッシュに触れるファイルは deny-by-default の目録で強制する
    (`CachePayloadPlainDataGateTest` / 宣言 pin は `ConfigHardeningTest`。
    guide §7 不変条件 6 と対応)
```

### 変更後コード

```
    強制は **2 層**である (家系の裁定 AG-151 = 正典 v2)。
    **静的層** (`CachePayloadPlainDataGateTest`) は書き込み経路とキャッシュに触れるファイルを
    deny-by-default の目録で強制し、受け皿の境界を迂回する書き方 (`Cache::extend` /
    `getStore` / `setStore` / `tags` / 受け手型の直接生成 / macro 登録) を 0 件で pin する。
    **実行時層** (`Tests\Support\Cache\PlainDataCacheGuard`) はテスト中のキャッシュ書き込みを
    受け皿の側で捕まえ、保管先へ渡す前の値を再帰検査する。結線はアプリ起動の前
    (`Tests\TestCase::createApplication()`) で、後始末は `tests/Pest.php` の全レーンが行う
    (`CacheGuardWiringGateTest` が deny-by-default で固定)。
    **「テストは array store なので実行時には捕まらない」は誤り** — 実行時層は直列化ではなく
    **値**を見るので、直列化しない保管方式でも同じように発火する。
    設定の宣言 pin は `ConfigHardeningTest`、実効値は静的 gate の検査 6。
    **保証しないものの正本は実行時層の docblock** であり、本書と guide には写さない
    (2 か所に書くと必ず食い違う)。guide §7 不変条件 6 と対応
```

- `docs/app-integration-guide.md` §7 不変条件 6 も同旨に直す
  （「静的検査で塞ぐ」→「静的層 + 実行時層の 2 層で塞ぐ」）
- `docs/architecture.md` に運用の説明（2 層の責務分担 / 露出したときの直し方）を書く。
  **保証しないものは書かない**（正本は docblock）

### テスト計画

- `docs` の記述は機械検査の対象外。**S6 / S7 のテストが緑であることが記述の裏付け**である
- AGENTS.md の検証コマンド節のマーカーは触らない

---

## S11: テンプレートとの差の登録

### 変更箇所

- `docs/template-divergence.md`（新規 **D30**）

### 登録するかの判断

laravel-claude-template の実装と本設計の差は次の 3 点。**いずれも「テンプレートより強い / 場所が違う」
差なので登録する**（書式の正本は同ファイルの規約節。`TemplateDivergenceLedgerFormatTest` が
登録メタ表 9 行・状態の値域・対象パスの実在と重複・件数の 3 点一致を機械強制する）。

| 差 | テンプレート | 本リポジトリ |
|---|---|---|
| 結線点 | Pest の beforeEach 相当 | **アプリ起動の前** (`Tests\TestCase::createApplication()`) |
| 境界迂回の扱い | `Cache::extend()` / `getStore()` / `new Repository` / `new CacheManager` を hard fail | 上記に加えて **`setStore` / `tags` / macro 系**も 0 件 pin |
| 目録の構造 | 書き込みサイトの全数申告目録 | 既存の L1〜L3 (語彙 / 書き込み経路 / 面) に **L4 (迂回)** を足す形 |

実装時に差が消えていたら登録しない（**差が無いのに登録しない**）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `tests/Pest.php` / `tests/TestCase.php` / 1455 行の既存 gate という**他の TODO も触りうる共有ファイル**を変更し、かつ全レーンの実行結果に影響する。段階を踏んで全レーン緑を確認しながら進める必要があるので、他の作業と混ぜない |
| 競合リスク | `tests/Pest.php`（他の guard 追加と衝突しうる）/ `tests/Architecture/CachePayloadPlainDataGateTest.php`（新しいキャッシュ書き込みが増えると目録が動く）/ `AGENTS.md`（並行トラックが同じ節を触る）。いずれも main へ合流する直前に `git pull --rebase origin main` で取り込み、目録の件数を再確認する |

## 完了条件

1. S0 の計測記録が devnotes に存在する
2. v2 の 4 要素がすべて満たされている（概念設計の対応表）
3. **AGENTS.md の `VERIFICATION_COMMANDS` 全件 green** + `composer test:browser`。
   **省略したコマンドがある状態で実装完了を報告しない**
4. lctl 台帳へ v2 として報告する準備ができている（**保証しないものを併記する**）。
   台帳への書き込み自体は本 TODO の範囲外

---

## 関連する現行コード

### tests/TestCase.php (全文)

```php
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase 後に DatabaseSeeder (Role 等の参照データ) を流す。
     */
    protected bool $seed = true;
}
```

### vendor: Illuminate\Foundation\Testing\TestCase::createApplication() (実体)

```php
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
```

### vendor: Illuminate\Container\Container::extend() (実体)

```php
    public function extend($abstract, Closure $closure)
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);

            $this->rebound($abstract);
        } else {
            $this->extenders[$abstract][] = $closure;

            if ($this->resolved($abstract)) {
                $this->rebound($abstract);
            }
        }
    }

```

### vendor: Illuminate\Cache\CacheManager::repository() (実体)

```php
     * @param  \Illuminate\Contracts\Cache\Store  $store
     * @param  array  $config
     * @return \Illuminate\Cache\Repository
     */
    public function repository(Store $store, array $config = [])
    {
        return tap(new Repository($store, Arr::only($config, ['store'])), function ($repository) use ($config) {
            if ($config['events'] ?? true) {
                $this->setEventDispatcher($repository);
            }
        });
    }

    /**
     * Set the event dispatcher on the given repository instance.
     *
     * @param  \Illuminate\Cache\Repository  $repository
     * @return void
     */
```

### vendor: Illuminate\Cache\Repository の該当メソッド宣言 (シグネチャ)

```php
public function put($key, $value, $ttl = null)
public function add($key, $value, $ttl = null)
public function forever($key, $value)
public function putMany(array $values, $ttl = null)
public function tags($names)
public function __call($method, $parameters)   // → $this->store->$method(...) へ素通し
protected static $unserializableClassHandler;
use InteractsWithTime, Macroable { … }
```

### tests/Pest.php (Feature/Unit レーンの現行 beforeEach / afterEach)

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
    })
    ->afterEach(function (): void {
        try {
            // stray call が記録されていれば test を fail させる (Service 層の
            // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
            //
            // ★2 つの guard は順に flush する。**同時発生時は先に throw した guard の
            //   詳細だけが表示される** (もう一方の accumulator は finally の reset で
            //   捨てられる)。test は既に赤いので「静かに緑」にはならず、検出目的は達成される。
            //   両方を集約する仕組みは入れない (今必要なものだけ作る)。
            StrayLlmCallGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::flushAndFailIfStray();
        } finally {
            // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
            StrayHttpRequestGuard::reset();
        }
    })
    ->in('Feature', 'Unit');

/*
| Architecture lane はファイル走査中心で DB を使わないが、HTTP 出口の既定拒否は
| **全レーン一律**にする (レーンごとに既定が違うと「どのレーンなら外へ出られるか」を
| 覚える必要が生まれ、gate も分岐だらけになる)。Tests\TestCase は
| Illuminate\Foundation\Testing\TestCase 継承で Laravel app 上を走るため install できる。
*/
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
        StrayHttpRequestGuard::install($this->app);
    })
    ->afterEach(function (): void {
        try {
            StrayHttpRequestGuard::flushAndFailIfStray();
        } finally {
            StrayHttpRequestGuard::reset();
        }
    })
    ->in('Architecture');
```

### tests/Support/StrayLlmCallGuard.php (同型の既存 guard。結線の慣行)

```php
/**
 * テスト中に未 fake の LLM 呼び出しを runtime で検知する PrismManager 差し替え guard。
 *
 * 仕組み:
 *  1. resolve() を override し、static accumulator に stray call を記録 → RuntimeException を throw。
 *  2. tests/Pest.php の beforeEach で install(), afterEach で flushAndFailIfStray() を呼ぶ。
 *  3. テスト本体で Prism::fake([...]) / Prompt::fake([...]) を呼ぶと PrismManager binding が
 *     上書きされ、または Prompt 層で short-circuit するため guard は無効化される。
 *  4. Service 層の try/catch fallback で例外が握り潰されても accumulator に残るため
 *     afterEach の flushAndFailIfStray() で必ず test を fail させる (= 主防御の核)。
 *
 * phpunit.xml の API キーダミー値強制 (OPENAI_API_KEY 等) は本 guard が万一
 * 無効化された場合の最終防壁 (tests/Feature/Config/PrismApiKeyDummyTest が到達を検証)。
 */
final class StrayLlmCallGuard extends PrismManager
{
    /** @var list<array{provider: string, providerConfig: array<string, mixed>}> */
    private static array $strayCalls = [];

    /**
     * Pest beforeEach から呼ぶ。前テストの残留状態を clear したうえで guard を install する。
     *
     * 順序:
     *  (1) accumulator を空にする (前テスト異常終了で残った記録を捨てる)
     *  (2) Prompt::stopFaking() で Kent013\PrismPrompt\Prompt::$fake static を reset
     *      (前テストの Prompt::fake が次テストにリークすると、本来 guard が catch すべき
     *       fake 漏れが Prompt 層で short-circuit して見逃される)
     *  (3) 既解決の PrismManager singleton を破棄
     *  (4) guard を PrismManager binding に差し込む
     */
    public static function install(Application $app): void
    {
        self::$strayCalls = [];
        Prompt::stopFaking();
        $app->forgetInstance(PrismManager::class);
        $app->instance(PrismManager::class, new self($app));
    }

    /**
     * Pest afterEach から呼ぶ。stray call が記録されていれば RuntimeException を throw して
     * test を fail させる。Service 層の try/catch fallback で例外が握り潰されても
     * このパスで必ず CI が赤くなるのが本 guard の存在意義。
     *
     * accumulator は finally で必ず clear する (process 内の後続テストへの二次被害を防ぐ)。
     */
    public static function flushAndFailIfStray(): void
    {
        try {
            if (self::$strayCalls === []) {
                return;
            }
            $summary = self::summarize(self::$strayCalls);
            throw new RuntimeException(
                'Stray LLM call detected during test execution. '
                .'Did you forget to call Prism::fake([...]) or Prompt::fake([...]) in the test body? '
                .PHP_EOL.$summary
            );
        } finally {
            self::$strayCalls = [];
        }
    }

    /**
     * accumulator を空に戻す。afterEach の finally から呼び、flushAndFailIfStray() が
     * throw した場合でも次テストへ残留状態を漏らさないことを保証する。
     */
    public static function reset(): void
    {
        self::$strayCalls = [];
    }

    /**
     * self-test 用 drain。意図的に stray call を発生させるテストで、global afterEach に
     * 到達する前に accumulator を取り出して clear する。
     *
     * @return list<array{provider: string, providerConfig: array<string, mixed>}>
     */
    public static function drainForAssertion(): array
    {
        $drained = self::$strayCalls;
        self::$strayCalls = [];

        return $drained;
    }

    /**
     * Prism 基盤を直接テストする Unit テストで guard 自体を opt-out するための helper。
     * Prism 基盤を直接テストする Unit テストでのみ使用する (通常テストでの opt-out 禁止)。
     */
    public static function uninstallForTest(Application $app): void
    {
        Prompt::stopFaking();
        $app->forgetInstance(PrismManager::class);
        self::$strayCalls = [];
    }

    /**
```

### tests/Architecture/CachePayloadPlainDataGateTest.php (冒頭の現行 docblock と語彙表)

```php
/*
 * Architecture invariant: **キャッシュに入れてよいのは素のデータだけ** (配列 / 文字列 / 数値 / 真偽値)。
 *
 * SoT = lctl 台帳 feature `cache-payload-plain-data` の標準形 v1 (裁定 2026-08-06) と
 * AGENTS.md セキュリティ不変条件 11 / docs/app-integration-guide.md §7 不変条件 6。
 *
 * ★なぜ静的検査か (実行時検出では捕まらない):
 *   テストレーンは phpunit.xml で CACHE_STORE=array、config/cache.php の array store は
 *   'serialize' => false。**オブジェクトを put してもそのまま返る = テストは緑になる**。
 *   本番は database store で serialize され、serializable_classes => false のため
 *   読み戻しは __PHP_Incomplete_Class になる。つまり「テストで再現しない本番専用の壊れ方」であり、
 *   実行時 detector (KeyWritten 購読等) は原理的にこの穴を塞げない。
 *
 * ★serializable_classes は **false 固定**であって「キーを消してよい」ではない:
 *   CacheManager は `config['cache.serializable_classes'] ?? null` を読み、各 store は
 *   `if ($this->serializableClasses !== null)` のときだけ allowed_classes を渡す。
 *   キーを消すと制限なしの unserialize() に戻る = **fail-open**。宣言の pin は
 *   tests/Feature/Config/ConfigHardeningTest.php (config ファイル直接評価) が担い、
 *   実行時の値はここで pin する。
 *
 * ★この gate が保証するもの:
 *   - 検査 1 (L1 語彙): キャッシュ受け手に対して呼ばれた API が全件 4 分類のどれかに属する。
 *     未分類は fail (Laravel が新しい書き込み API を足したときに黙って通さない)
 *   - 検査 2-3 (L2 書き込み経路): WRITE に分類された呼び出し箇所が目録と exact-fit。
 *     未登録も、実在しない登録も、件数のズレも fail。各 entry は payload の説明・
 *     往復を固定する単体テストのパス (実在検証つき)・30 文字以上の根拠を持つ
 *   - 検査 4-5 (L3 面): **キャッシュ記号に触れているファイル集合**が目録と exact-fit で、
 *     宣言した role (write / no-payload-write / lock-only / driver-handoff) が実測と整合する
 *     (規則自体も検査 5b で固定)
 *   - 検査 6: 実行時 config('cache.serializable_classes') === false、store 単位の上書きなし
 *   - 検査 5b: role 判定規則そのものの正負コントロール (実在ファイルの構成に依存させない)
 *   - 検査 6b: 語彙表の健全性 (4 分類が互いに素 / 除外型が受け手型に混ざっていない)
 *   - 検査 7: 空振り検知 (走査ファイル数 / メソッド呼び出し数 / 解決できたキャッシュ式が 0 でない)
 *   - 検査 8: 自己参照コントロール (本ファイル自身を走査して書き込み 0 件・面 hit なし)
 *   - 検査 9 以降: 正負コントロール fixture (facade / チェーン / ヘルパ / DI / コンテナ /
 *     getStore / literal 動的呼び出し / 完全修飾ヘルパ / 静的に判定できない形 /
 *     session・disk / lock / コメント)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **payload の式が本当に素データか**は静的に判定しない。目録の `payload` 欄は人間の申告で、
 *     機械が保証するのは「申告なしに書き込み経路を増やせない」ことと「往復の単体テストが実在する」ことだけ
 *   - **facade mock 経由の書き込み** (`Cache::shouldReceive('put')`)。TERMINAL で辿りを止めるため
 *     WRITE には数えない。ただしそのファイルは L3 (面) に必ず現れるので無申告では追加できない
 *   - **受け手そのものが動的に得られる形** (`$container->make($name)->put(...)` など、
 *     bind 名が変数)。受け手を解決できないので WRITE に数えない。L3 でも捕まらない
 *     (`app` / `resolve` / `make` の第 1 引数が literal のときだけ面として数えるため)。
 *     この形は実測 0 件で、通常のレビューで自明に不自然な書き方である
 *   ※ 受け手が cache と分かっている上での**動的メソッド名** (`->{$m}(...)` / `->$m(...)`) は
 *     素通りさせず `unclassified` として fail させる。literal 形 (`->{'put'}(...)`) は通常形と同じに分類する
 *   - **docblock だけで型付けされた受け手** (`@var Repository $c` の docblock を書いた直後に
 *     `$c->put(...)` する形)。型宣言 (引数 / プロパティ / promoted ctor param) のみを見る。
 *     ※同じファイルに対応する型の `use` があれば **L3 (面) には現れる**が、
 *       完全修飾 docblock だけで import も型宣言も無い形は **L3 でも捕まらない**。
 *       docblock 解析は行わない (実測 0 件)
 *   - `use A\{B, C};` のグループ use 構文は扱わない (実測 0 件。`NoNonCompoundGlobalUseTest` が
 *     別途 use の書き方を縛っている)
 *
 * 解析は PhpToken::tokenize (コメント・文字列リテラルは code token ではないので拾わない)。
 * regex にすると**この説明コメント自身**で偽赤になる。DB 不使用 (Architecture lane は TestCase のみ)。
 */

/**
 * 走査対象ディレクトリ (リポジトリルートからの相対)。
 *
 * tests/ を含めるのは、テストが array store の性質に守られて object を cache に入れても
 * 緑になるため (本番だけで壊れる書き方をテストが先に持ち込むのを防ぐ)。
 * 本 gate の fixture は nowdoc の中にあり code token ではないので自己汚染しない (検査 8 で固定)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_SCAN_DIRS = ['app', 'routes', 'database', 'tests'];

/**
 * 受け手として解決するキャッシュ型 (FQCN)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_RECEIVER_TYPES = [
    'Illuminate\Support\Facades\Cache',
    'Illuminate\Contracts\Cache\Repository',
    'Illuminate\Contracts\Cache\Factory',
    'Illuminate\Contracts\Cache\Store',
    'Illuminate\Cache\Repository',
    'Illuminate\Cache\CacheManager',
    'Illuminate\Cache\TaggedCache',
    'Psr\SimpleCache\CacheInterface',
];

/**
 * キャッシュ名前空間だが payload を持たないため受け手にしない型 (明示除外・理由付き)。
 *
 * @var array<string, string>
 */
const CACHE_PAYLOAD_EXCLUDED_TYPES = [
    'Illuminate\Contracts\Cache\Lock' => '排他オブジェクト。payload を持たない',
    'Illuminate\Contracts\Cache\LockProvider' => '排他の発行元。payload を持たない',
    'Illuminate\Contracts\Cache\LockTimeoutException' => '排他取得失敗の例外型。payload を持たない',
    'Illuminate\Cache\RateLimiter' => 'レート制限。ThrottleCoverageInventoryTest の担当',
    'Illuminate\Cache\RateLimiting\Limit' => 'レート制限の値オブジェクト',
];

/**
 * payload を書き込む API (全小文字)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_WRITE_METHODS = [
    'put', 'add', 'forever', 'remember', 'rememberforever', 'sear',
    'flexible', 'putmany', 'set', 'setmultiple',
];

/**
 * payload を書き込まない API (increment/decrement は整数のみ書けるため素データが構造的に保証される)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_NON_WRITE_METHODS = [
    'get', 'many', 'getmultiple', 'has', 'missing', 'pull', 'forget', 'delete',
    'deletemultiple', 'flush', 'clear', 'increment', 'decrement',
    'supportstags', 'getprefix', 'getdefaultdriver', 'setdefaultdriver',
    'forgetdriver', 'purge', 'extend', 'itemkey', 'refresheventdispatcher',
];

/**
 * 受け手を保ったまま連鎖する API。
 *
 * `getStore()` は `Illuminate\Contracts\Cache\Store` を返し **put / forever を持つ**ので
 * NON_WRITE ではなく CHAIN (`Cache::getStore()->put(...)` の抜けを塞ぐ)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'tags', 'resolve', 'getstore', 'getfacaderoot'];

/**
 * 受け手がキャッシュでなくなる terminal (以降の連鎖を辿らない)。
 *
 * `getFacadeRoot` はここに置かない — facade の**実体 (CacheManager)** を返すので
 * `Cache::getFacadeRoot()->put(...)` は本物の書き込みになる。CHAIN 側に置く
 * (impl-review Round 1 [Warning] 反映)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_TERMINAL_METHODS = [
    'lock', 'restorelock', 'shouldreceive', 'spy', 'partialmock', 'swap',
    'expects', 'shouldhavereceived', 'shouldnothavereceived',
];

/**
 * L2: キャッシュ **書き込み経路**の目録 (deny-by-default / exact-fit)。
 *
 * key   = `{リポジトリルートからの相対パス}::{メソッド名 (全小文字)}` (行番号は使わない。
 *         行がずれるたびに目録が壊れると gate が「邪魔だから消す」対象になるため)
 * count = そのファイル・そのメソッドの出現回数 (exact-fit。2 件目を足したら必ず落ちる)
 * payload = 実際に渡している式と、それが素データである理由
 * proof   = 往復を固定している単体テストのパス (**実在を検査する**)
 * rationale = 30 文字以上の具体的根拠
 *
 * 経路が 1 本しかない現状では専用 enum (app/Enums/Security/) + inventory クラス
 * (tests/Support/Security/) へ昇格させない (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
 *
 * @var array<string, array{count: int, payload: string, proof: string, rationale: string}>
 */
const CACHE_PAYLOAD_WRITE_INVENTORY = [
    'app/Services/FxRateService.php::put' => [
        'count' => 1,
        'payload' => 'FxSnapshotDto::toArray() の連想配列 (float 1 / string 3)。オブジェクトは渡さない',
        'proof' => 'tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php',
        'rationale' => '当日の USD/JPY レートを 1 日 cache する。読み戻しは is_array 検査 + FxSnapshotDto::fromArray() + 失敗時 Cache::forget() で標準形どおり',
    ],
];

```

### tests/Architecture/CachePayloadPlainDataGateTest.php (走査の中核: 連鎖の分類)

```php
    return $m[2];
}

/**
 * 受け手 (`::` / `->` の index) から連鎖を辿ってメソッド呼び出しを分類する。
 *
 * 動的メソッド呼び出しの扱い (`CarbonOverflowArithmeticGateTest` と揃える):
 *   - `->{'put'}(...)` (literal) は静的に決定できるので**通常形と同じに分類する**
 *   - `->{$m}(...)` / `->$m(...)` (変数形) は決定できない。**受け手が cache だと分かっている**以上
 *     素通りさせる理由が無いので `unclassified` として fail させる (実測 0 件)
 *
 * @param  list<PhpToken>  $tokens
 * @return list<array{method: string, line: int, kind: string}> kind: write|non_write|chain|terminal|unclassified
 */
function cachePayloadFollowChain(array $tokens, int $operatorIndex): array
{
    $calls = [];
    $index = $operatorIndex;

    while (true) {
        $nameIndex = cachePayloadNext($tokens, $index + 1);
        if ($nameIndex === null) {
            return $calls;
        }

        $rawName = null;
        $afterName = $nameIndex;

        if ($tokens[$nameIndex]->is(T_STRING)) {
            $rawName = $tokens[$nameIndex]->text;
        } elseif ($tokens[$nameIndex]->text === '{') {
            $inner = cachePayloadNext($tokens, $nameIndex + 1);
            $close = $inner === null ? null : cachePayloadNext($tokens, $inner + 1);
            if ($inner === null || $close === null || $tokens[$close]->text !== '}') {
                return $calls;
            }
            $afterName = $close;
            $rawName = $tokens[$inner]->is(T_CONSTANT_ENCAPSED_STRING)
                ? cachePayloadLiteralValue($tokens[$inner]->text)
                : null;
            if ($rawName === null) {
                // 変数形の動的ディスパッチ。受け手が cache と分かっているので素通りさせない
                $calls[] = ['method' => '{$dynamic}', 'line' => $tokens[$nameIndex]->line, 'kind' => 'unclassified'];

                return $calls;
            }
        } elseif ($tokens[$nameIndex]->is(T_VARIABLE)) {
            // `->$method(...)` 形も同様に判定不能
            $open = cachePayloadNext($tokens, $nameIndex + 1);
            if ($open !== null && $tokens[$open]->text === '(') {
                $calls[] = ['method' => '$dynamic', 'line' => $tokens[$nameIndex]->line, 'kind' => 'unclassified'];
            }

            return $calls;
        } else {
            return $calls;
        }

        $open = cachePayloadNext($tokens, $afterName + 1);
        if ($open === null || $tokens[$open]->text !== '(') {
            return $calls; // プロパティ / 定数アクセス
        }
        $nameIndex = $afterName;

        $method = strtolower($rawName);
        $kind = match (true) {
            in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) => 'write',
            in_array($method, CACHE_PAYLOAD_NON_WRITE_METHODS, true) => 'non_write',
            in_array($method, CACHE_PAYLOAD_CHAIN_METHODS, true) => 'chain',
            in_array($method, CACHE_PAYLOAD_TERMINAL_METHODS, true) => 'terminal',
            default => 'unclassified',
        };
        $calls[] = ['method' => $rawName, 'line' => $tokens[$nameIndex]->line, 'kind' => $kind];

        if ($kind !== 'chain') {
            return $calls;
        }

        $close = cachePayloadMatchingParen($tokens, $open);
        if ($close === null) {
            return $calls;
        }
        $next = cachePayloadNext($tokens, $close + 1);
        if ($next === null || ! $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            return $calls;
        }
        $index = $next;
    }
}

/**
```

### tests/Architecture/CachePayloadPlainDataGateTest.php (role 判定規則)

```php
    }
    if ($methods === []) {
        $violations[] = "role={$role} なのにキャッシュ API 呼び出しが 1 件もありません"
            .'(使わなくなったなら import ごと消す)';
    }

    if ($role === 'lock-only') {
        $extra = array_values(array_diff($methods, ['lock', 'restorelock']));
        if ($extra !== []) {
            $violations[] = 'role=lock-only なのに排他以外のキャッシュ API を呼んでいます: '.implode(', ', $extra);
        }

        return $violations;
    }

    if ($role === 'driver-handoff') {
        // 連鎖 (CHAIN) 分類のメソッドだけを許す。読み出し・書き込み・削除・排他・mock は
        // 1 件でも現れたら違反 (「解決して渡すだけ」という申告を裏切るため)。
        $extra = array_values(array_diff($methods, CACHE_PAYLOAD_CHAIN_METHODS));
        if ($extra !== []) {
            $violations[] = 'role=driver-handoff なのに解決 (連鎖) 以外のキャッシュ API を呼んでいます: '
                .implode(', ', $extra);
        }

        return $violations;
    }

    // no-payload-write: 任意 payload を書かない API と連鎖 API だけを許す
    // (TERMINAL の lock / mock は別 role・別責務なのでここには入れない)
    $allowed = array_merge(CACHE_PAYLOAD_NON_WRITE_METHODS, CACHE_PAYLOAD_CHAIN_METHODS, ['cache']);
    $extra = array_values(array_diff($methods, $allowed));
    if ($extra !== []) {
        $violations[] = 'role=no-payload-write なのに payload を書く / 排他・mock の API を呼んでいます: '
            .implode(', ', $extra);
    }
    // CHAIN だけで終わる形 (受け手を取り回しているのに何もしない) は role の意味を壊すので終端を要求する
    if (array_intersect($methods, array_merge(CACHE_PAYLOAD_NON_WRITE_METHODS, ['cache'])) === []) {
        $violations[] = 'role=no-payload-write なのに終端の操作 (読み出し・削除等) がありません';
    }

    return $violations;
}

/**
 * 走査対象全体の収集結果 (同一プロセス内で 1 度だけ計算する)。
 *
 * @return array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}
 */
function cachePayloadCollectAll(): array
{
    /** @var array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}|null $cached */
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $writeCounts = [];
    $writeSites = [];
    $unclassified = [];
    $surfaces = [];
    $cacheCalls = 0;
    $methodCalls = 0;
    $files = 0;

    foreach (cachePayloadScanTargets() as $target) {
        $source = file_get_contents($target['absolute']);
```

### config/prism-prompt.php (cache 節の現行)

```php
    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for caching parsed YAML templates.
    | Enabled by default in production for performance.
    | Set PRISM_PROMPT_CACHE=false in .env for development.
    |
    */
    'cache' => [
        'enabled' => env('PRISM_PROMPT_CACHE', true),
        'ttl' => 3600,
        'store' => null, // null = default cache driver
    ],

```

### vendor: Kent013\PrismPrompt\PromptTemplate::fromYaml() の該当部 (オブジェクトを入れている箇所)

```php
        // Store in cache
        if (self::isCacheEnabled()) {
            $cacheKey = self::getCacheKey($path);
            $ttl = config('prism-prompt.cache.ttl', 3600);
            Assert::integer($ttl);
            Cache::store(self::getCacheStore())->put($cacheKey, $instance, $ttl);
        }
```
