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

## あなたの役割

コードレビュアーとして Laravel + Svelte の改善実装をレビューする。

レビュー観点:
- 設計との一致性 (詳細設計 devnotes/20260818-1757-cache-runtime-plain-data-guard/detailed-design.md との差、差がある場合その正当性)
- 正確性 (走査器の検出漏れ・偽陽性、fail-closed 分岐、境界条件)
- PHPStan level 10 適合性 (本リポジトリの解析対象は app/config/database/routes。tests/ は対象外)
- DTO / JsonResource パターン (本変更はテスト機構と設定・文書のみで、アプリ本体の変更は config/prism-prompt.php だけ)
- テスト網羅性 (AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」: 負例と正例 / 解決できない形を落とす分岐 / 空振り検知 / docblock に走査対象と保証しないもの)
- セキュリティ (キャッシュ経由の逆シリアライズ面が実際に狭まっているか、保証範囲の誇張が無いか)
- DESIGN.md 準拠 / Atomic Design 準拠 (本 diff は resources/js / resources/css を含まないため該当なし)

出力形式:
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書く

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

## 「素のデータ」の定義（本設計で統一する）

**配列 / 文字列 / 数値 / 真偽値 / `null`** の 5 種。

`null` を含める根拠は、家系の裁定 AG-151 の本文が実行時層の判定を
「素データ (配列・文字列・数値・真偽値・**null**) 以外なら違反として落とす」と定めている
ことである。本リポジトリの AGENTS.md 不変条件 11 と `docs/app-integration-guide.md` §7 不変条件 6 の
列挙は正典より狭いので、**同じ PR で正典に合わせる**（S10）。
実務上も `Cache::put($k, null)` / `remember` の callback が null を返す形は、PHP が
「保存された null」と「不在」を区別できず**クラス情報を一切運ばない**ため逆シリアライズの
攻撃面にならない。

> 家系では motivation が null を許可集合から外している（割れている論点である）。
> 本リポジトリは**正典の文言に合わせる**側を採る。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 値検査器と例外 | `tests/Support/Cache/PlainDataInspector.php` / `CachePayloadViolation.php` (新規) | 高 |
| S2 | guard 付き受け皿と manager | `tests/Support/Cache/PlainDataGuardedRepository.php` / `PlainDataGuardedCacheManager.php` (新規) | 高 |
| S3 | guard 本体 (結線・accumulator・macro pin) | `tests/Support/Cache/PlainDataCacheGuard.php` (新規) | 高 |
| S4 | 起動前結線と全レーンの後始末 | `tests/TestCase.php` / `tests/Pest.php` | 高 |
| S5 | 実行時層の振る舞い検査 | `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php` (新規) + `tests/Support/Cache/` の補助 4 本 (新規) | 高 |
| S6 | 結線の pin (gate) | `tests/Architecture/CacheGuardWiringGateTest.php` (新規) | 高 |
| S7 | 静的層の訂正 + L4 (境界迂回) + 役割追加 | `tests/Architecture/CachePayloadPlainDataGateTest.php` | 高 |
| S8 | 露出の計測と是正 (反復) | `devnotes/.../runtime-exposure.md` (新規) + 是正対象 | 高 |
| S9 | 同梱パッケージのオブジェクトキャッシュを設定で閉じる | `config/prism-prompt.php` / `tests/Feature/Config/ConfigHardeningTest.php` | 中 |
| S10 | 規約の明文化 | `AGENTS.md` / `docs/app-integration-guide.md` / `docs/architecture.md` | 中 |
| S11 | テンプレートとの差の登録 | `docs/template-divergence.md` | 中 |

### 実装順（一本化）

| 順 | 内容 | 完了条件 |
|---|---|---|
| 1 | **S5 / S6 / S7 の負例を先に書いて赤くする**（テストファースト。AGENTS.md 思考原則 5） | 期待した理由で赤いこと |
| 2 | S1 → S2 → S3 → S4 → **S7** を実装する | 新しい振る舞い検査・結線 gate・**既存の静的 gate** がすべて緑 |
| 3 | **S8 の反復**（計測 → 是正 → 再計測を違反 0 まで） | 全レーン緑 |
| 4 | S9 → S10 → S11 | `VERIFICATION_COMMANDS` 全件 green + `composer test:browser` |

★**S7 を S8 より後に置くことはできない**。S5 を足した時点で、既存の静的 gate が
新しい書き込み経路（`put` / `add` / `remember` …）・新しい面（probe provider・guard 実装）・
境界の自己テスト（`Cache::extend()` 等）・ArrayAccess 書き込みを検出して落ちる。
L2 / L3 / 語彙を変える S7 が入っていないと「S5 / S6 が緑」も「全レーンで計測」も成立しない。

★S8 の各 wave で書き込み経路を是正すると L2 目録が動く。
**S7 の目録は S8 の wave ごとに更新する**（S7 の完了は S8 の完了と同時になる）。

---

## S1: 値検査器と例外

### 変更箇所

- 新規: `tests/Support/Cache/PlainDataInspector.php`
- 新規: `tests/Support/Cache/CachePayloadViolation.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: S5 が正負コントロールを持つ

### 変更後コード（`PlainDataInspector`）

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

/**
 * キャッシュへ書き込まれる値が**素のデータ**かを再帰検査する純関数。
 *
 * 素のデータ = 配列 / 文字列 / 数値 / 真偽値 / null だけで構成された値
 * (家系の裁定 AG-151 が定めた許可集合。AGENTS.md セキュリティ不変条件 11 と同義)。
 * DTO・Eloquent モデル・Collection・列挙型・日時オブジェクト・クロージャ・resource は違反である。
 *
 * ## 違反の種別
 *
 * - `OBJECT_FOUND` / `RESOURCE_FOUND` — 規約そのものの違反
 * - `UNKNOWN_TYPE` — **上のどれにも当てはまらない型**。閉じた resource が代表例で、
 *   `is_resource()` は false を返すが `is_scalar()` にも当たらない。
 *   「分類できなかったものを素データとして通さない」ための fail-closed 分岐である
 * - `LIMIT_EXCEEDED` — **規約違反ではなく「検査器が素のデータであることを証明できなかった」**
 *   ことを表す。自己参照配列 (`$v['self'] = &$v;`) は素朴な再帰走査を停止させないため、
 *   深さ・ノード数の上限を置き、超過は fail-closed で違反として返す
 *
 * ## 上限値の根拠
 *
 * - 深さ 32: `json_decode` の既定深さ 512 より十分浅く、キャッシュ payload としては 32 段でも異常に深い
 * - ノード 10000: **根の値を 1 と数えた総ノード数**。1 件のキャッシュ entry としては十分大きい
 *
 * 境界の直前・直後は tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が pin する。
 *
 * ## 保証しないもの
 *
 * - **値の意味**は見ない (素のデータであれば内容は問わない)
 * - 配列のキーは見ない (PHP は配列キーを int|string に限るので、キーがオブジェクトになる形は無い)
 * - **保管先へ渡ったあとの変換**は見ない (store 側の直列化・圧縮は対象外)
 */
final class PlainDataInspector
{
    /** 走査の最大深さ (配列の入れ子段数)。超過は LIMIT_EXCEEDED。 */
    public const int MAX_DEPTH = 32;

    /** 走査の最大ノード数 (**根の値を 1 と数える**)。超過は LIMIT_EXCEEDED。 */
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

        // ★許可集合を**先に**判定して早期 return する (許可の定義を 1 か所に閉じる)。
        if ($value === null || is_scalar($value)) {
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
            // ★閉じた resource が代表例。is_resource() は false、is_scalar() も false。
            //   分類できないものを素データとして通さない (fail-closed)。
            $violations[] = $path.' = UNKNOWN_TYPE('.get_debug_type($value).')';

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

### 変更後コード（`CachePayloadViolation`）

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
            .' (LIMIT_EXCEEDED / UNKNOWN_TYPE は「guard が素のデータであることを証明できなかった」'
            .'ことを表す。値を小さくするか、キャッシュに入れる形を見直すこと)',
        );
    }

    public static function forBoundary(string $operation, string $detail): self
    {
        return new self(
            "キャッシュ受け皿の境界を迂回しました: {$operation} ({$detail})。".PHP_EOL
            .'受け皿 (Illuminate\Cache\Repository) を跨いで保管先へ届く経路は、'
            .'実行時層が値を見られないため使えません。'
            .'規約: AGENTS.md セキュリティ不変条件 11 / 家系の裁定 AG-151 の境界迂回の hard fail',
        );
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`list<string>` / `self` / `void`）
- [x] `mixed` の絞り込みは **許可集合 → object → resource → array → その他** の順。
      **この順序が load-bearing**（`Closure` は object で拾い、閉じた resource は最後の分岐で拾う）
- [x] DTO を返している（ここは検査結果の文字列一覧なので DTO 不要）
- [x] Generics の型パラメータが正しい（`@param list<string> $violations` の参照渡し）

### テスト計画（S5 に置く）

- 正例: 配列 / 文字列 / 整数 / 浮動小数 / 真偽値 / null / 入れ子配列
- 負例: object / Closure / 日時 / Collection / **開いた resource** / **閉じた resource** (`UNKNOWN_TYPE`)
- 境界: 深さ 32 は通り 33 は `LIMIT_EXCEEDED(depth)` / **根を含む総ノード** 10000 は通り 10001 は
  `LIMIT_EXCEEDED(nodes)` / 自己参照配列は停止して `LIMIT_EXCEEDED`

### リスク

- 許可集合に `null` を含めるのは AGENTS.md の現行記述より広い。**S10 で正典に合わせる**
  （設計だけで広げない）

---

## S2: guard 付き受け皿と manager

### 変更箇所

- 新規: `tests/Support/Cache/PlainDataGuardedRepository.php`
- 新規: `tests/Support/Cache/PlainDataGuardedCacheManager.php`

### vendor 実読で確定した前提（Laravel 12 / `vendor/laravel/framework`）

**(a) `Illuminate\Cache\Repository` の値を運ぶ公開 API の合流**

| 入口 | 合流先 |
|---|---|
| `set($key, $value, $ttl)` | `put()` |
| `setMultiple($values, $ttl)` | `putMany()` |
| `remember($key, $ttl, $cb)` | `rememberWithWarmth()` → `put()` |
| `rememberWithWarmth()` | `put()` |
| `sear()` | `rememberForever()` → `forever()` |
| `rememberForever()` | `forever()` |
| `flexible()` | `putMany()` |
| `offsetSet()` / `$cache[$k] = $v` / `??=` | `put()` |
| `putMany($values, null)` | `putManyForever()` → `forever()` |
| `touch($key, $ttl)` | 値を運ばない（`store->touch()`） |
| `increment` / `decrement` | 整数のみ（store 直行） |

→ **末端は `put` / `add` / `forever` / `putMany` の 4 つ**。

★ **この主張が成り立つのは「標準 `Repository` API の値の合流」についてだけ**である。
`Store` へ直接届く経路（`getStore()` の戻り値・具体 store の直接生成・`Store` 型の注入）の
完全性は**別問題**で、そちらは静的層の L4 が担う（S7）。

**(b) driver 生成はすべて `repository(Store $store, array $config = [])` を通る**
（`createArrayDriver` / `createDatabaseStore` / `createFileDriver` / `memo` など）。
**`Cache::extend()` の独自 creator だけが `repository()` を通らない**。これは実読で確定した事実である:

```php
// CacheManager::build()
if (isset($this->customCreators[$config['driver']])) {
    return $this->callCustomCreator($config);   // ← repository() を通さずに返す
}
$driverMethod = 'create'.ucfirst($config['driver']).'Driver';
…
// CacheManager::callCustomCreator()
return $this->customCreators[$config['driver']]($this->app, $config);   // creator の戻り値をそのまま返す (mixed)
```

`resolve()` は `build()` へ委譲するだけなので、独自 driver は必ずこの経路を通る。
S5 の実証テストは「creator が返した受け皿が guard 付きでないこと」を固定する
**trip-wire** として置く（前提が変わったら赤くなる）。

**(c) `Repository::tags($names)` は `new TaggedCache($this->store, ...)` を素で生成する**
（`TaggedCache extends Repository`）。継承しても以降の書き込みが検査を通らない。
かつ本番の database store は `supportsTags()` が false でタグ非対応。

**(d) `Repository` は `use InteractsWithTime, Macroable { __call as macroCall; }`**。
`__call()` は「macro があれば macro、無ければ `$this->store->$method(...)` へ素通し」。
macro の closure は Repository インスタンスへ束縛されるので `$this->store` へ直接到達できる。

**(e) `getStore()` は vendor 自身が正常系で呼ぶ**ので、**実行時に落としてはならない**。実読の根拠:

- `Illuminate\Cache\RateLimiter::withoutSerializationOrCompression()` (299 行) —
  `hit()` / `increment()` の経路。**流量制限を使うテストが全滅する**
- `Illuminate\Cache\Repository::flushLocks()` (805 行) — **自分自身で呼ぶ**
- `Illuminate\Console\Scheduling\CacheEventMutex` / `Illuminate\Console\CacheCommandMutex` /
  `Illuminate\Cache\Limiters\ConcurrencyLimiterBuilder` / `Illuminate\Cache\MemoizedStore`

→ **`getStore()` は静的層で 0 件 pin する**（正典 AG-151 が求めるのも静的な hard fail である）。
実行時に落とせないことは docblock の「保証しないもの」へ根拠つきで書く。
`setStore()` は vendor に呼び出し元が 0 件なので実行時にも落とす。

### 変更後コード（`PlainDataGuardedRepository`）

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
 * ★これは**標準 API の値の合流**についての主張であって、`Store` へ直接届く経路の
 *   完全性の主張ではない (そちらは静的層 L4 の担当)。
 *
 * ## 境界迂回として落とすもの
 *
 * - `tags()` — vendor の実装が `new TaggedCache($this->store, ...)` を素で生成するため、
 *   継承しても以降の書き込みが検査を通らない。加えて本番の保管方式 (database store) は
 *   タグ非対応 (`supportsTags()` が false) なので、タグを使う書き方は本番で例外になる
 * - `setStore()` — 受け皿の保管先を差し替える口 (vendor に呼び出し元 0 件)
 * - `__call()` — **macro かどうかに関わらず落とす**。macro の closure は `$this->store` へ
 *   直接到達でき、末端 4 メソッドを通らない (「同一テスト内で登録し、使い、消す」形も
 *   使用時点で捕まる)。macro でない素通しも `$this->store->$method(...)` へ届くので、
 *   **store 固有 API や将来追加される API が payload を運ばない保証は無い**。
 *   よって無条件で落とす (Codex 実装レビュー Round 2 の [Critical] 反映)
 *
 * ## 保証しないもの
 *
 * - **`getStore()` は落とさない**。vendor 自身が正常系で呼ぶためである — 実読の根拠:
 *   `Illuminate\Cache\RateLimiter::withoutSerializationOrCompression()` (hit/increment の経路) /
 *   `Illuminate\Cache\Repository::flushLocks()` (自己呼び出し) /
 *   `Illuminate\Console\Scheduling\CacheEventMutex` / `Illuminate\Console\CacheCommandMutex` /
 *   `Illuminate\Cache\Limiters\ConcurrencyLimiterBuilder` / `Illuminate\Cache\MemoizedStore`。
 *   よって「保管先を直接取得して書く」形を塞ぐのは**静的層 (L4) だけ**であり、
 *   vendor が `getStore()` 経由で書く値は実行時層に見えない
 * - `increment` / `decrement` は store 直行だが整数しか書けないので検査しない
 *
 * ## 許可一覧を持たない
 *
 * vendor の書き込みも対象に含める。`config/cache.php` の `serializable_classes => false` の下では
 * **誰が入れたかに関わらず**オブジェクトを入れれば本番の読み出しが失敗するため、
 * vendor の検出は誤検出ではなく本番の潜在バグの発見である (家系の裁定 AG-107「例外を作らない」)。
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
            PlainDataCacheGuard::inspect('put', '(many)', $key);

            return parent::put($key, $value, $ttl);
        }

        PlainDataCacheGuard::inspect('put', self::describeKey($key), $value);

        return parent::put($key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function add($key, $value, $ttl = null)
    {
        PlainDataCacheGuard::inspect('add', self::describeKey($key), $value);

        return parent::add($key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function forever($key, $value)
    {
        PlainDataCacheGuard::inspect('forever', self::describeKey($key), $value);

        return parent::forever($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function putMany(array $values, $ttl = null)
    {
        PlainDataCacheGuard::inspect('putMany', '(many)', $values);

        return parent::putMany($values, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function tags($names)
    {
        PlainDataCacheGuard::reportBoundary('tags', self::describeKey($names));
    }

    /**
     * {@inheritDoc}
     *
     * ★vendor の宣言は `public function setStore($store)` で **型宣言を持たない**
     *   (docblock に `@param \Illuminate\Contracts\Cache\Store $store` があるだけ)。
     *   忠実に写すので本クラスは `Store` 型を参照しない
     *   = 「Store 型を参照してよい唯一のサイトは manager の repository()」という主張と矛盾しない。
     */
    public function setStore($store)
    {
        PlainDataCacheGuard::reportBoundary('setStore', get_debug_type($store));
    }

    /**
     * {@inheritDoc}
     *
     * macro かどうかに関わらず落とす (クラス docblock「境界迂回として落とすもの」参照)。
     */
    public function __call($method, $parameters)
    {
        PlainDataCacheGuard::reportBoundary(
            static::hasMacro($method) ? 'macro' : 'storePassthrough',
            $method,
        );
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

### 変更後コード（`PlainDataGuardedCacheManager`）

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
 * `Cache::extend()` の独自 creator は `repository()` を通らない
 * (tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が実証する)。
 * よって静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php の L4) が
 * `Cache::extend()` を **通常経路 0 件 + GuardedBoundaryProbe の自己テストの exact-fit** で
 * pin して口を塞いでいる。
 *
 * **本クラスは Illuminate\Contracts\Cache\Store を参照してよい唯一のサイトである**
 * (vendor 互換シグネチャの要求)。`$store` は
 * `new PlainDataGuardedRepository($store, ...)` の第 1 引数以外に現れてはならず、
 * その構造条件は同 gate の L4c が機械検査する (store を外へ流出させると受け皿を迂回できる)。
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
- テストファイル: S5（振る舞い）・S7（L3 面の目録に `guard-implementation` 役割で追加）

### PHPStan 適合チェック

- [x] override は **vendor の宣言をそのまま写す**。実装時に
      `vendor/laravel/framework/src/Illuminate/Cache/Repository.php` と `.../CacheManager.php` を
      開いて可視性・引数名・既定値・戻り値を 1 文字ずつ合わせる
- [x] `tags()` / `setStore()` / `__call()` は `never` を返す `reportBoundary()` で終端するので、
      戻り値の型不一致は起きない（`@return never` を docblock に書く）
- [x] `Arr::only($config, ['store'])` の戻り値は `array<string, mixed>`

### テスト計画（S5）

- 実 API 経由で 13 形（`put` / `add` / `forever` / `putMany` / `set` / `setMultiple` /
  `remember` / `rememberForever` / `sear` / `flexible` / `rememberWithWarmth` /
  `$cache[$k] = $v` / `$cache[$k] ??= $v`）
- `tags()` / `setStore()` / macro 経由 / 素通し (`__call`) の hard fail
- `Event::fake()` の後でも効くこと
- **`Cache::extend()` の独自 creator が `repository()` を通らない**ことの実証

### リスク

- `__call()` を無条件に落とすので、**正当な非 payload の素通しがあれば赤くなる**。
  S8 の反復計測で出てきたら、**guard の中に無言の許可を作らず**、vendor 実読で用途を分類したうえで
  設計へ差し戻して判断する。完了条件に「未分類の `__call` が残っていないこと」を入れる
- `rememberWithWarmth` は Laravel 12 に存在することを実読で確認済み

---

## S3: guard 本体

### 変更箇所

- 新規: `tests/Support/Cache/PlainDataCacheGuard.php`

### 公開 API

| メソッド | 役割 |
|---|---|
| `registerBeforeBootstrap(Application $app): void` | **accumulator と `$inspected` の初期化 → macro の検査と復元 → `cache` の extender 登録**。bootstrap の前に呼ぶ |
| `assertInstalled(Application $app): void` | 結線が効いていることの確認（beforeEach）。**accumulator に触らない** |
| `inspect(string $method, string $key, mixed $value): void` | 値検査。違反は記録し**その場で例外**も投げる |
| `reportBoundary(string $operation, string $detail): never` | 境界迂回。記録して例外 |
| `flushAndFailIfStray(): void` | afterEach。macro を検査して記録 → accumulator を判定 → `finally` で後始末 |
| `reset(): void` | afterEach の `finally`。accumulator / `$inspected` の消去と macro の**復元のみ** |
| `drainForAssertion(): list<string>` | 意図的違反テスト用（`StrayLlmCallGuard` と同じ） |
| `inspectedCount(): int` | 空振り検知（guard が実際に値を見た回数） |

### 結線の順序（load-bearing）

`registerBeforeBootstrap()` は次の順で行う。

1. **accumulator と `$inspected` を初期化する**（前テストが異常終了して afterEach が
   走らなかった場合の残骸をここで消す）
2. **`Repository::$macros` を検査して復元する**（残骸があれば違反として記録してから空へ戻す）
3. **`$app->extend('cache', …)` を登録する**
4. （呼び出し側が）`bootstrap()` を呼ぶ

★ 1 と 2 を Pest の `beforeEach` へ置いてはならない。結線が bootstrap 前に入る以上、
**起動中に記録された違反が beforeEach の初期化で消える**。provider が例外を握り潰した場合、
accumulator の記録が唯一の証拠である。

### extender の中身（fail-closed）

```php
$app->extend('cache', function (mixed $manager, Application $app): PlainDataGuardedCacheManager {
    // ★受け取った実体が**素の** CacheManager ちょうどでなければ落とす。
    //   独自 creator の登録口 (Cache::extend()) は静的層 L4 が 0 件で pin しているので、
    //   引き継ぐべき状態は無い。想定外の実体を黙って捨てない。
    if (! $manager instanceof CacheManager || $manager::class !== CacheManager::class) {
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
（`Illuminate\Container\Container` 実読）。

### `assertInstalled()`（空振り検知・fail-closed）

```php
public static function assertInstalled(Application $app): void
{
    $manager = $app->make('cache');
    if (! $manager instanceof PlainDataGuardedCacheManager) {
        throw new RuntimeException('キャッシュ guard が結線されていません: '.get_debug_type($manager));
    }

    // ★RateLimiter は起動中に cache を解決する (AppServiceProvider::boot() が
    //   RateLimiter::for(...) を多数登録するため必ず解決される)。したがって
    //   「起動前に結線できていた」ことの証拠になる。**解決されていなければ前提が崩れたので落とす**。
    if (! $app->resolved(RateLimiter::class)) {
        throw new RuntimeException(
            'RateLimiter が起動中に解決されていません。起動前結線の前提 '
            .'(AppServiceProvider::boot() の名前付き制限登録) が崩れている。'
        );
    }

    // **読むだけで書き換えない**。プロパティが無ければ ReflectionException = その場で失敗。
    $repository = (new ReflectionProperty(RateLimiter::class, 'cache'))
        ->getValue($app->make(RateLimiter::class));

    if (! $repository instanceof PlainDataGuardedRepository) {
        throw new RuntimeException(
            'RateLimiter が guard 付きでない受け皿を握っています: '.get_debug_type($repository)
        );
    }
}
```

### macro の pin

```php
/**
 * Repository::$macros を**検査して記録し、既定へ戻す**。
 * registerBeforeBootstrap() と flushAndFailIfStray() の先頭で呼ぶ。
 */
private static function pinMacros(): void
{
    $macros = self::readMacros();
    if ($macros !== []) {
        self::$violations[] = 'MACRO_REGISTERED('.implode(', ', array_keys($macros)).')';
    }

    self::restoreMacros();
}

/** 記録せず既定へ戻すだけ (reset() から呼ぶ。flush の直後に二重記録しない)。 */
private static function restoreMacros(): void
{
    self::macrosProperty()->setValue(null, []);
}

/** @return array<string, mixed> */
private static function readMacros(): array
{
    $macros = self::macrosProperty()->getValue();
    if (! is_array($macros)) {
        throw new RuntimeException('Repository::$macros が配列ではありません: '.get_debug_type($macros));
    }

    /** @var array<string, mixed> $macros */
    return $macros;
}

private static function macrosProperty(): ReflectionProperty
{
    $reflection = new ReflectionClass(Repository::class);
    if (! $reflection->hasProperty('macros')) {
        throw new RuntimeException(
            'Illuminate\Cache\Repository::$macros が存在しません。macro 経由の迂回 pin が'
            .'空振りしている。vendor を読み直して pin を作り直すこと。'
        );
    }

    return $reflection->getProperty('macros');
}
```

**保証しないもの**: macro の pin は「登録が残っていること」を見る。
`__call()` の override が**使用時点**でも落とすので、
「同一テスト内で登録し、使い、消す」形も捕まる（S5 の負例で固定する）。

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

public static function reportBoundary(string $operation, string $detail): never
{
    self::$violations[] = "BOUNDARY_BYPASS({$operation}): {$detail}";

    throw CachePayloadViolation::forBoundary($operation, $detail);
}
```

**記録してから throw する**。`FxRateService` のように読み書きを `try/catch` で囲む実装が
例外を握り潰しても、afterEach で必ず赤くなる（既存 2 guard と同じ設計）。

### `flushAndFailIfStray()` / `reset()`

```php
public static function flushAndFailIfStray(): void
{
    try {
        self::pinMacros();   // ★検査して記録し復元する (説明と実装を一致させる)

        if (self::$violations === []) {
            return;
        }

        throw new RuntimeException(
            'Plain-data cache violation detected during test execution. '
            .'キャッシュに入れてよいのは素のデータだけ (AGENTS.md セキュリティ不変条件 11 / '
            .'家系の裁定 AG-107・AG-151)。'.PHP_EOL.self::summarize(self::$violations)
        );
    } finally {
        self::reset();
    }
}

/** accumulator と計測値を消し、macro を**記録せずに**既定へ戻す。 */
public static function reset(): void
{
    self::$violations = [];
    self::$inspected = 0;
    self::restoreMacros();
}
```

### PHPStan 適合チェック

- [x] `ReflectionProperty::getValue()` は `mixed`。`is_array()` で絞ってから使う
- [x] `array_keys($macros)` は `list<array-key>`。`implode` の前に `array_map(strval(...), …)` する
- [x] `$app->make('cache')` は `mixed`。`instanceof` で絞る
- [x] static プロパティに `@var list<string> $violations` / `@var int $inspected`
- [x] `reportBoundary()` は `never` を返す（PHPStan が到達不能を理解する）

### テスト計画（S5）

後始末の検査（`reset()` の複数回呼び出し / drain 後に次テストへ漏れない / `$inspected` が 0 に戻る）。

### リスク

- `--parallel` の worker 間で accumulator は共有されない（プロセス内 static）。既存 2 guard と同じ

---

## S4: 起動前結線と全レーンの後始末

### 変更箇所

- `tests/TestCase.php`（`createApplication()` の override を追加）
- `tests/Pest.php`（3 レーンの beforeEach に `assertInstalled`、afterEach に flush / reset）

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

vendor 本体を**忠実に写し**、`require` の後・`bootstrap()` の前に結線を 1 行挟むだけにする
（既知の処理を削らない = フレームワーク準拠）。

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
     * ★Pest の beforeEach では遅い。起動 (bootstrap) 中の書き込みは、vendor 由来だと
     *   静的層の走査根 (app / routes / database / tests) にも入らないため、
     *   結線が遅れると 2 層とも沈黙する穴になる。
     *
     * ★本体は vendor (Illuminate\Foundation\Testing\TestCase::createApplication()) の
     *   写しであり、**guard の結線 1 行だけを足している**。vendor 側が変わったら
     *   tests/Architecture/CacheGuardWiringGateTest.php の W5 (期待 token 列の完全一致) が
     *   赤くなるので、そのとき写し直す。
     */
    #[\Override]
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        assert($app instanceof Application);

        // ★ここが結線点。bootstrap() より前でなければならない。
        PlainDataCacheGuard::registerBeforeBootstrap($app);

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
}
```


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

- 既存コメント「★2 つの guard は順に flush する」を **3 本立て**に更新する

### 波及変更

- テストファイル: S6 が結線を pin。`tests/TestCase.php` は受け手型を参照しないので L3 面には現れない

### PHPStan 適合チェック

- [x] `createApplication()` の戻り値は `Illuminate\Foundation\Application`。
      vendor は docblock だけなので**戻り値型の宣言は許される**（狭めていない）
- [x] `require` の戻り値は `mixed`。`instanceof` で絞れなければ例外（fail-closed）
- [x] `$this->traitsUsedByTest` は親の `protected array`。`class_uses_recursive()` は
      `array<string, class-string>` を返すので型は合う
- [x] `#[\Override]` を付ける

### テスト計画

- S6 が結線を静的に pin / S5 の「起動中の書き込み」負例が結線の実効を固定

### リスク

- Laravel 更新で vendor の `createApplication()` が変わったら写しが古くなる → S6 の W5 が赤くなる

---

## S5: 実行時層の振る舞い検査

### 変更箇所

- 新規: `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php`
- 新規: `tests/Support/Cache/BootTimeCacheWriteProbeProvider.php`（起動中の負例で使う provider）
- 新規: `tests/Support/Cache/IsolatedApplicationProbe.php`（第 2 アプリの生成と隔離）
- 新規: `tests/Support/Cache/CachePayloadViolationAssertions.php`（意図的違反の共通 helper）
- 新規: `tests/Support/Cache/GuardedBoundaryProbe.php`（**境界 API を呼ぶ自己テストの唯一の置き場**）

### 境界の自己テストは 1 ファイルへ集約する（**静的層に必ず見えるようにするため**）

既存 scanner の受け手名は**型宣言**から作られる。`$cache = Cache::store('array');` のような
代入では受け手にならず、`PlainDataGuardedRepository` は受け手型に含まれず継承も解決しない。
そのままだと L4 の自己テスト目録が**実測 0 件**になり、exact-fit が落ちる。

そこで境界 API を呼ぶ自己テストを `tests/Support/Cache/GuardedBoundaryProbe.php` へ集約し、
受け皿は **`Illuminate\Cache\Repository` 型の引数**で受ける。

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;

/**
 * 境界迂回が hard fail することを固定するための**唯一の**呼び出し元。
 *
 * ★受け皿を `Illuminate\Cache\Repository` 型の**引数**で受けるのが load-bearing —
 *   静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) は型宣言から受け手名を作るため、
 *   ローカル変数へ代入する書き方だと L4 の自己テスト目録が実測 0 件になって exact-fit が落ちる。
 * ★境界 API を呼ぶ自己テストは**このファイルにだけ**置く (L4f が置き場所を名指しで固定する)。
 */
final class GuardedBoundaryProbe
{
    // ★`@return never` は付けない。引数の native 型は**通常の** Illuminate\Cache\Repository で、
    //   通常の Repository の tags() は値を返し得る。「guard 付きを渡したときに例外になる」ことは
    //   S5 の振る舞いテストが保証するのであって、静的なメソッド契約ではない
    //   (PHPStan が「never なのに到達可能」と判断しうるし、契約としても不正確である)。

    public static function callTags(Repository $cache): void { $cache->tags(['t']); }

    public static function callSetStore(Repository $cache): void { $cache->setStore(new ArrayStore); }

    public static function callUnknownMethod(Repository $cache): void { $cache->guardProbeUnknownMethod(); }

    /**
     * macro を登録して**使う**。guard の __call() が例外を投げるので、
     * **`finally` で必ず登録を消す** — 消さないと global afterEach の pinMacros() が
     * MACRO_REGISTERED を記録し、意図的負例が二重に失敗する。
     * 境界 API の呼び出しはこのファイルにしか置けない (L4f) ので、
     * テスト本体の finally から flushMacros() を呼ぶ形にはできない。
     */
    public static function callMacro(Repository $cache): void
    {
        Repository::macro('guardProbeMacro', fn (): bool => true);

        try {
            $cache->guardProbeMacro();
        } finally {
            Repository::flushMacros();
        }
    }

    /**
     * macro を**登録するだけ**で使わない (検査 16 用)。
     * 呼び出し側のテストが flushAndFailIfStray() を明示的に呼び、
     * MACRO_REGISTERED の記録と既定への復元を確認する。
     */
    public static function registerMacroWithoutUsing(): void
    {
        Repository::macro('guardProbeResidualMacro', fn (): bool => true);
    }

    /**
     * 独自 creator が CacheManager::repository() を通らないことの実証用。
     *
     * ★登録も解決も**引数の manager** に対して行う。facade へ登録して引数から解決すると、
     *   facade root と引数が別インスタンスだったときに「extend の前提」ではなく
     *   別インスタンスの問題で落ちる。CacheManager は scanner の受け手型なので
     *   静的 L4 の検出力は保たれる。
     */
    public static function resolveCustomDriver(CacheManager $manager): mixed
    {
        $manager->extend('guard-probe', fn (): Repository => new Repository(new ArrayStore));

        return $manager->store('guard-probe');
    }
}
```

- `$cache->guardProbeUnknownMethod()` は静的には **`unclassified`** になる。
  **検査 1 は自己テスト目録に登録された呼び出しを母集団から除く**規則にする
  （目録に載っていない未知 API は従来どおり落ちる）
- `Repository::macro(...)` / `Repository::flushMacros()` は受け手型への静的呼び出しなので
  既存の解決経路で検出される
- `new Repository(...)` / `new ArrayStore` は L4b の直接生成として検出される

### 意図的違反の共通 helper（**必須**）

意図的違反は accumulator に残るので、**そのままではグローバル afterEach の
`flushAndFailIfStray()` が再度落ちて全負例が失敗する**。次の helper で必ず drain する。

★**global function ではなくクラスの static メソッドにする**。PSR-4 は関数をオートロードしないので、
`tests/Support/` に global function を置いても呼べない（`files` autoload を足す判断はしない）。

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Closure;

/**
 * 意図的な違反を起こすテストのための共通 assertion。
 *
 * ★drain を忘れるとグローバル afterEach の flushAndFailIfStray() が二重に落ちて
 *   **すべての負例が失敗する**。単に消すのではなく**記録内容まで assert する**
 *   (「例外だけ別経路から出た」空振りを防ぐため)。
 */
final class CachePayloadViolationAssertions
{
    /**
     * (1) 例外が投げられること (2) accumulator にちょうど 1 件記録され期待する断片を含むこと
     * (3) drain 後に accumulator が空であること をまとめて検査する。
     *
     * @param  Closure(): mixed  $callback
     * @param  list<string>  $expectedFragments
     */
    public static function expectViolation(Closure $callback, array $expectedFragments): void
    {
        expect($callback)->toThrow(CachePayloadViolation::class);

        $drained = PlainDataCacheGuard::drainForAssertion();
        expect($drained)->toHaveCount(1);
        foreach ($expectedFragments as $fragment) {
            expect($drained[0])->toContain($fragment);
        }
        expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
    }
}
```

### 実キャッシュ書き込みの集約（静的層との整合）

本ファイルの実キャッシュ書き込みは**ヘルパ関数 1 つに集約する**。静的層の L2 目録は
`パス::メソッド名` 粒度なので、テストの並べ替えで目録がずれないようにするためである。

★**受け皿は型宣言の引数で受ける**。`$cache = Cache::store('array');` のようにローカル変数へ
代入すると、静的層は `$cache` を受け手名として解決できず（受け手名は**型宣言**から作られる）、
**書き込みが L2 に現れない** = 目録が申告を要求しなくなる。

★引数の型は **具体クラス `Illuminate\Cache\Repository`** にする。
`Illuminate\Contracts\Cache\Repository`（契約）は `ArrayAccess` を保証しないので、
契約型のままだと `$cache[$key] = $value;` が PHPStan level 10 で通らない。
呼び出し側で `Cache::store('array')` の結果を `instanceof` で絞ってから渡す。
具体クラスも静的層の受け手型なので、型宣言から受け手名が作られる（L2 の検出は維持される）。

```php
use Illuminate\Cache\Repository;

/**
 * guard 付き受け皿へ**実 API 経由**で書き込む (合流の実証用)。
 *
 * remember / rememberForever / sear / set / setMultiple / flexible /
 * rememberWithWarmth / ArrayAccess は vendor 実装が put / add / forever / putMany へ
 * 合流する。その合流が将来変わったら本テストが落ちる (guard の被覆が静かに減らない)。
 */
function cachePayloadGuardWrite(Repository $cache, string $method, string $key, mixed $value): void
{
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

### 検査項目（すべて必須）

| # | 検査 | 種別 |
|---|---|---|
| 1 | `Event::fake()` の後でも guard が効く | 負例 |
| 2 | オブジェクトを渡すと例外: `put` / `add` / `forever` / `putMany` | 負例 |
| 3 | 同: `set` / `setMultiple` / `remember` / `rememberForever` / `sear` / `flexible` / `rememberWithWarmth` | 負例（合流の実証） |
| 4 | 同: `$cache[$k] = $v` / `$cache[$k] ??= $v` | 負例（合流の実証） |
| 5 | クロージャを渡すと例外（Closure もオブジェクト） | 負例 |
| 6 | 素のデータは通る（配列 / 文字列 / 整数 / 浮動小数 / 真偽値 / **null** / 入れ子） | 正例 |
| 7 | 違反メッセージに method / key / 違反パスと種別 / AGENTS.md への参照が載る | 正例 |
| 8 | `PlainDataInspector`: object / **開いた resource** / **閉じた resource (`UNKNOWN_TYPE`)** / Closure / 日時 / Collection を違反にする | 負例 |
| 9 | `PlainDataInspector`: 素のデータは違反にならない | 正例 |
| 10 | 深さ 32 は通り 33 は `LIMIT_EXCEEDED(depth)` | 境界 |
| 11 | **根を含む総ノード** 10000 は通り 10001 は `LIMIT_EXCEEDED(nodes)` | 境界 |
| 12 | 自己参照配列は `LIMIT_EXCEEDED` になる（停止する） | 境界 |
| 13 | `tags()` は境界迂回として例外になる（`GuardedBoundaryProbe::callTags()` 経由） | 負例 |
| 14 | `setStore()` は境界迂回として例外になる（同上） | 負例 |
| 15 | **macro を登録 → macro 名で呼ぶ → 使用時点で境界迂回になる**（登録し、使い、`flushMacros()` で消しても捕まる） | 負例 |
| 15b | **macro でない未知メソッド（store 素通し）も境界迂回になる** | 負例 |
| 16 | **flush が残存 macro を検出する**（`GuardedBoundaryProbe::registerMacroWithoutUsing()` で登録だけしてから、テスト内で `flushAndFailIfStray()` を明示的に呼び、`RuntimeException` と `MACRO_REGISTERED` を確認する。**global afterEach へ残して確認することはできない** — そのテスト自身が落ちるため。全レーンから flush が呼ばれることは S6 が保証する） | 負例 |
| 17 | **provider の `boot()` で書き、provider が握り潰しても accumulator に残る** | 負例 |
| 18 | アプリ側が握り潰しても accumulator に残る（`try { … } catch (Throwable) {}` の形） | 負例 |
| 19 | **`Cache::extend()` の独自 creator は `repository()` を通らない**（`build()` が `callCustomCreator()` を返す実装の trip-wire） | 実証 |
| 20 | `reset()` を複数回呼んでも安全 / drain 後は次テストへ漏れない / `$inspected` が 0 に戻る | 後始末 |
| 21 | `inspectedCount()` が 0 でない（guard が実際に値を見ている＝空振り検知） | 空振り検知 |
| 22 | 第 2 アプリの後始末（**第 2 アプリの解決済みインスタンスが残らず、元の Application から再解決できる状態へ戻る**。facade の application が元へ戻っていること + 再解決した cache manager が guard 付きであることを見る。object identity の一致は見ない） | 後始末 |

### 検査 17（起動中の書き込み）の組み方

通常のテスト用アプリへ provider を足すと bootstrap 中に落ちてテスト本体へ到達しない。
そこで**テストの中で第 2 のアプリを組み立てる**。隔離は
`tests/Support/Cache/IsolatedApplicationProbe.php` に閉じる。

```php
/**
 * 第 2 のアプリを **TestCase::createApplication() と同じ結線経路**で組み立て、
 * コンテナと facade の状態を必ず元へ戻す。
 *
 * 退避と復元の順序 (固定):
 *   退避: Container::getInstance() → Facade::getFacadeApplication()
 *   復元 (finally): Facade::clearResolvedInstances() → Facade::setFacadeApplication(退避値)
 *         → Container::setInstance(退避値) → PlainDataCacheGuard::reset()
 *
 * ★戻すのは「**第 2 アプリの解決済みインスタンスを残さず、元の Application から
 *   再解決できる状態**」であって、元の解決済みインスタンスそのものではない
 *   (facade の解決済みインスタンスは消去して遅延再解決に任せる)。
 *
 * @template TReturn
 * @param  Closure(Application): TReturn  $callback
 * @return TReturn
 */
public static function run(Closure $callback): mixed
```

- 検査本体は `IsolatedApplicationProbe::run()` の中で
  `PlainDataCacheGuard::registerBeforeBootstrap($app)` →
  `$app->register(BootTimeCacheWriteProbeProvider::class)` →
  `$app->make(Kernel::class)->bootstrap()` を行い、
  `drainForAssertion()` に `OBJECT_FOUND` が入っていることを確認する
- **テスト名と主張**: 「provider が握り潰しても accumulator に残る」。
  **afterEach の結線そのものは S6 の gate が保証する**（分担を明記する）
- `BootTimeCacheWriteProbeProvider::boot()` は
  `try { Cache::put('probe', new stdClass, 60); } catch (Throwable) { /* 意図的に握り潰す */ }`。
  `catch` を消すと bootstrap が例外になって別の理由で赤くなる = どちらでも赤い

### PHPStan 適合チェック

- [x] `Cache::store('array')` の戻り値は `Illuminate\Contracts\Cache\Repository`。
      **呼び出し側で `instanceof Illuminate\Cache\Repository` に絞ってから** helper へ渡す
      （契約型は `ArrayAccess` を保証しないので、絞らないと添字代入が通らない）
- [x] `Facade::getFacadeApplication()` の戻り値の型を絞ってから復元する
- [x] `require` の戻り値を `instanceof Application` で絞る
- [x] `expectViolation()` は static メソッド（PSR-4 でオートロードされる）

### テスト計画

本施策がテストそのもの。**先に赤くしてから S1〜S4 を書く**。

### リスク

- 第 2 のアプリの生成でコンテナ・facade を汚す → `IsolatedApplicationProbe` が `finally` で復元し、
  検査 22 が復元を固定する

---

## S6: 結線の pin（gate）

### 変更箇所

- 新規: `tests/Architecture/CacheGuardWiringGateTest.php`

### 検査項目

| # | 検査 | 内容 |
|---|---|---|
| W1 | `tests/TestCase.php` の `createApplication()` が **`bootstrap()` より前**に `PlainDataCacheGuard::registerBeforeBootstrap()` を呼ぶ | 字句走査（`PhpToken`。コメント・文字列を落とす）。**位置関係**（token index の前後）を見る |
| W2 | `tests/Pest.php` の**期待するレーン集合ちょうど**の afterEach に `flushAndFailIfStray()` と `reset()` がある | `->in(...)` の引数集合が `{Feature, Unit}` / `{Architecture}` / `{Browser}` の 3 つちょうどであることを照合してから、各ブロックを検査 |
| W3 | 同じ 3 ブロックの beforeEach に `assertInstalled()` がある | 同上 |
| W5 | vendor の `Illuminate\Foundation\Testing\TestCase::createApplication()` の**正規化 token 列が期待値と完全一致**する | 反射でファイルと行範囲を取り、`<?php ` を前置して token 化し、空白・コメントを落とした列を pin |
| **W5b** | **`Tests\TestCase::createApplication()` が vendor の写しであること** | ローカルの正規化 token 列が「**vendor 期待列へ許可差分を挿入した列**」と完全一致すること |
| W6 | S5 の起動中の負例が `TestCase::createApplication()` と**同じ関数**を、**bootstrap より前**に呼ぶ | W1 と同じ抽出器を `IsolatedApplicationProbe` にも当てる |
| W7 | 空振り検知 | 走査ファイルが実在する / W5・W5b の token 数が 0 でない / 期待 token 群が**すべて 1 度ずつ**対応した / 検出器が負例で反応する |
| W8 | 負のコントロール | 合成入力（nowdoc）で「flush が無いレーン」「bootstrap の**後**で結線するコード」「レーン集合が違う」「token が 1 つ増えた vendor 本体」「token の順序が入れ替わった vendor 本体」「ローカルから `traitsUsedByTest` の代入 / cached 分岐 / `return $app` を消した本体」を検出できること |

### W5b が必要な理由（Codex 実装レビュー Round 2 の [Critical] 反映）

W5 は vendor 側の変更しか見ない。W1 は「結線が bootstrap より前にある」ことしか見ない。
**その 2 つだけだと、ローカルの写しから `$this->traitsUsedByTest` の代入・cached config 分岐・
cached routes 分岐・`return $app` を消しても両方とも緑のまま**である。

許可差分は次の 3 つだけに固定する。

1. 戻り値の fail-closed 確認（`if (! $app instanceof Application) { throw … }`）
2. `PlainDataCacheGuard::registerBeforeBootstrap($app);`
3. 戻り値型の宣言と `#[\Override]` 属性

検査は**二重**にする（位置まで固定するため）。

- (i) ローカルの期待 token 列を**定数として持ち、完全一致**で比較する
- (ii) **その定数から許可差分を取り除くと vendor 期待列に一致する**ことも検査する

部分列の除去だけだと**別の位置に同じ列を置いても通る**ので、(i) と (ii) を両方置く。

`#[\Override]` は **reflection で別途検査**する。
`ReflectionMethod::getStartLine()` から切り出したソースに属性行が含まれる保証が無いためである。

```php
expect((new ReflectionMethod(TestCase::class, 'createApplication'))->getAttributes(Override::class))
    ->toHaveCount(1);
```

### W5 の作り方（fail-closed）

```php
$method = new ReflectionMethod(BaseTestCase::class, 'createApplication');
$file = $method->getFileName();            // string|false → false なら例外
$lines = file($file);                      // array|false → false なら例外
$source = '<?php '.implode('', array_slice($lines, $start - 1, $end - $start + 1));
$tokens = array_values(array_filter(
    PhpToken::tokenize($source),
    fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]),
));
$normalized = array_map(fn (PhpToken $t): string => $t->text, $tokens);

expect($normalized)->toBe(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS);
```

- **文の分割はしない**（`;` / `}` の素朴な分割は if・closure・入れ子ブロックを壊す）
- 期待値は定数として gate 内に持つ。Laravel 更新で 1 token でも変われば赤くなる
- **保証範囲を docblock に書く**: 見るのは vendor の当該メソッドの本体だけ。
  `setUp()` / `refreshApplication()` の変更や bootstrapper の増減は見ない

### PHPStan 適合チェック

- [x] `ReflectionMethod::getFileName()` / `getStartLine()` / `getEndLine()` は `string|false` /
      `int|false`。false なら例外（fail-closed）
- [x] `file()` の戻り値は `array<int, string>|false`
- [x] `PhpToken::tokenize()` は `list<PhpToken>`

### テスト計画

W8 の負のコントロールを**先に書いて赤くする**（既存の抽出器を流用して最初から緑になる場合は、
負例が押さえる分岐を一時的に壊して赤を確認する）。

### リスク

- W5 は Laravel の更新のたびに人の手当てが要る。**それが目的**（写しが静かに古くならない）

---

## S7: 静的層の訂正 + L4（境界迂回）+ 役割追加

### 変更箇所

- `tests/Architecture/CachePayloadPlainDataGateTest.php`

### 変更内容の一覧

1. 冒頭 docblock（L11-16 の誤った主張の削除、2 層構成の説明の追加、保証範囲の更新）
2. `CACHE_PAYLOAD_WRITE_METHODS` に **`rememberwithwarmth`** を追加
3. `CACHE_PAYLOAD_NON_WRITE_METHODS` から **`extend`** を削除
4. `CACHE_PAYLOAD_CHAIN_METHODS` から **`getstore`** / **`tags`** を削除
5. `CACHE_PAYLOAD_BYPASS_METHODS`（新設）
5b. `CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY`（新設。迂回の自己テストの exact-fit 目録）
6. `cachePayloadIsStoreType()`（新設。具体 store の判定規則）
7. `cachePayloadFollowChain()` の `$kind` に `bypass` を追加
8. **ArrayAccess 書き込みの検出**（新設）
9. **`new <受け手型 / 具体 store>` の検出**（新設）
10. **受け手型・保管先型の継承・実装の宣言の検出**（L4d。新設。宣言句全体を解析する）
11. 検査 L4a / L4b / L4c / L4d / L4e / L4f（新設）と検査 6b の語彙健全性に BYPASS を追加
12. `CACHE_PAYLOAD_SURFACE_INVENTORY` に `guard-implementation` 役割と対象ファイルを追加
13. `CACHE_PAYLOAD_WRITE_INVENTORY` に `kind` 欄を新設し、S5 / probe provider の entry を追加
14. 既存の負のコントロールの組み替え

### 1. 冒頭 docblock（現行 10-16 行を削除して差し替え）

```php
 * ★2 層構成のうち**静的層**がこのファイルである (家系の裁定 AG-151 = 正典 v2)。
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
 *
 * ★L4 (境界迂回) を**静的層だけで塞ぐ**ものがある。とくに `getStore()` は
 *   vendor 自身が正常系で呼ぶため実行時には落とせない (RateLimiter の hit/increment 経路、
 *   Repository::flushLocks() の自己呼び出し、スケジューラの排他など)。
 *   よって「保管先を直接取得して書く」形を塞ぐのは**このファイルだけ**であり、
 *   vendor が getStore() 経由で書く値は 2 層とも見えない (保証しないもの)。
```

### 5. 新設する語彙

```php
/**
 * 受け皿 (Repository) を跨いで保管先 (Store) へ届く / 受け皿の生成そのものに割り込む API。
 * **通常経路は 0 件**で、実行時層の自己テストだけを名指しの目録へ exact-fit で登録する
 * (家系の裁定 AG-151 の v2 要素 4「境界迂回の hard fail」)。
 *
 * - extend    独自 creator は CacheManager::repository() を通らないので実行時層の被覆から抜ける
 *             (通らないことは tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が実証する)。
 *             判定は**通常経路 0 件 + GuardedBoundaryProbe の自己テストの exact-fit**である
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

### 5b. 境界迂回の自己テスト目録（新設。**必須**）

S5 の負例は `Cache::extend()` / `tags()` / `setStore()` / `macro()` / `flushMacros()` /
`new Repository(...)` / `new ArrayStore()` を**実際に呼ぶ**。L4 を素の「0 件」にすると
**負例を書いた瞬間に gate が落ちる**。そこで名指しの目録を持つ。

```php
/**
 * L4: 境界迂回の**自己テスト**の目録 (exact-fit)。
 *
 * key   = `{相対パス}::{メソッド名 (全小文字)}` / `{相対パス}::new {完全修飾名}`
 *         ★**完全修飾名で突き合わせる** (AGENTS.md 走査規約 (a))。短名では別名つき取り込みや
 *           同名の別クラスを区別できない。scanner が完全修飾名を解決できないときは
 *           目録照合へ進まず unclassified として落とす
 * count = 出現回数 (完全一致。1 件増えたら必ず落ちる)
 * rationale = 30 文字以上の具体的根拠
 *
 * ★登録できるのは **tests/Support/Cache/GuardedBoundaryProbe.php の 1 ファイルだけ**である
 *   (検査 L4f が名指しで固定する)。「tests/Support/Cache/ 配下すべて」にはしない —
 *   将来足した任意の補助ファイルが自己テストを名乗れてしまうため。
 * ★**動的呼び出しで走査を避ける形は採らない** (検出力の裏取りが弱くなるため)。
 *
 * @var array<string, array{count: int, rationale: string}>
 */
const CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY = [
    'tests/Support/Cache/GuardedBoundaryProbe.php::extend' => [
        'count' => 1,
        'rationale' => '独自 driver の creator が CacheManager::repository() を通らないことを実証する trip-wire。通らなくなったら L4 の根拠が変わる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::tags' => [
        'count' => 1,
        'rationale' => 'guard 付き受け皿の tags() が境界迂回として落ちることを固定する。落ちなくなると TaggedCache 経由の書き込みが素通りする',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::new Illuminate\Cache\ArrayStore' => [
        'count' => 2,
        'rationale' => 'setStore の引数と独自 creator の保管先として使う。保管先の直接生成が検出されることの自己確認も兼ねる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::macro' => [
        'count' => 2,
        'rationale' => 'macro 経由の到達が使用時点で落ちること (callMacro) と、残存 macro を flush が検出すること (registerMacroWithoutUsing) の 2 件',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::flushmacros' => [
        'count' => 1,
        'rationale' => 'callMacro の finally で必ず登録を消すための 1 件。消さないと global afterEach の macro pin が二重に落ちる',
    ],
    // setstore / guardprobeunknownmethod / new Illuminate\Cache\Repository も同様
];
```

検査 L4a / L4b は「検出した迂回 - 目録 = 空」かつ「目録 - 検出した迂回 = 空」かつ
**件数が完全一致**であることを見る（exact-fit）。
**目録の全 entry が実測で非空であり、件数まで exact-fit であること**も同時に検査する
（`count` が 2 以上の entry もあるので「1 度ずつ」とは言わない）。
検査 L4f は「目録の key のパスが `tests/Support/Cache/GuardedBoundaryProbe.php` **ちょうど**であること」と
「rationale が 30 文字以上であること」を見る。

**検査 1（未分類 API の deny-by-default）は、自己テスト目録に登録された呼び出しを母集団から除く。**
これが無いと `guardProbeUnknownMethod()`（`storePassthrough` の自己テスト）が未分類として落ちる。
目録に載っていない未知 API は従来どおり落ちる。

### 6. 具体 store の判定規則

```php
/**
 * 保管先 (Store) の型かどうかの判定規則。
 *
 * 解決した完全修飾名が
 *   (a) `Illuminate\Contracts\Cache\Store` である、または
 *   (b) `Illuminate\Cache\` で始まり `Store` で終わる (ArrayStore / DatabaseStore / FileStore /
 *       RedisStore / NullStore / MemoizedStore / StorageStore / FailoverStore …)
 * のとき保管先の型とみなす。
 *
 * ★**保証しないもの**: **走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に
 *   一致しない第三者の Store 実装**の直接生成・解決は検出しない
 *   (例: `new Vendor\Package\CacheBackend()` が vendor 内で Store を実装している形)。
 *   `Cache::extend()` の pin は **CacheManager 経由で第三者 Store の面を増やす経路**を閉じるが、
 *   **走査根の外の第三者 Store を直接生成する / 独自のコンテナ束縛で取得する経路までは
 *   保証しない** (「唯一の登録口」とは書かない)。
 *   規則そのものの正負は検査 L4e が固定する。
 */
function cachePayloadIsStoreType(string $fqcn): bool
```

### 8. ArrayAccess 書き込みの検出

`$cache[$key] = $value` / `$cache[$key] ??= $value` は**メソッド呼び出し走査では検出できない**。
受け手名の変数の直後が `[` … `]` で、その次の significant token が `=` または `??=` なら、
`offsetset` の書き込みとして記録する。

```php
// cachePayloadCollectFromSource() の T_VARIABLE 分岐に追加
if (in_array($name, $receiverNames, true)) {
    $bracket = cachePayloadNext($tokens, $i + 1);
    if ($bracket !== null && $tokens[$bracket]->text === '[') {
        $closeBracket = cachePayloadMatchingBracket($tokens, $bracket);   // 新設 (対応する `]`)
        $assign = $closeBracket === null ? null : cachePayloadNext($tokens, $closeBracket + 1);
        if ($assign !== null && in_array($assign->text ?? '', ['=', '??='], true)) {
            $surface = true;
            $writes[] = ['relative' => $relative, 'line' => $token->line, 'method' => 'offsetSet'];
            $methods[] = 'offsetset';
        } elseif ($closeBracket === null) {
            // ★対応する `]` を見つけられない = 解決できない形。見逃さずに落とす。
            $unclassified[] = "{$relative}:{$token->line} → \${$name}[…] (対応する ] を解決できない)";
        }
    }
}
```

- `offsetset` を `CACHE_PAYLOAD_WRITE_METHODS` へ追加する
- **保証しないもの**（docblock に書く）: 受け手名として解決できない変数
  （型宣言を持たないローカル変数）への添字代入は検出しない。既存の受け手解決の限界と同じ

### 9-10. `new` と継承・実装の宣言の検出

```php
// (9) 受け手型 / 保管先型の直接生成
//   ★目録の key は**解決済みの完全修飾名**で作る (別名つき取り込み・短名のままだと
//     自己テスト目録と exact-fit しない。AGENTS.md 走査規約 (a))。
$prev = cachePayloadPrev($tokens, $i - 1);
if ($prev !== null && $tokens[$prev]->is(T_NEW)) {
    $bypasses[] = "{$relative}:{$token->line} → new {$resolved}";
    $bypassCounts["{$relative}::new {$resolved}"] = ($bypassCounts["{$relative}::new {$resolved}"] ?? 0) + 1;
}

// (10) 受け手型 / 保管先型の継承・実装の宣言 (L4d)
//   ★任意の Repository サブクラスを作れば `new` の検出を逃れられる。
//     **宣言側で塞ぐ**方が確実なので、extends / implements を迂回として扱う。
//   ★直前 token だけを見る形では不十分 —
//     `class X implements SomeInterface, Store {}` の `Store` の直前は `,` である。
//     そこで T_EXTENDS / T_IMPLEMENTS を見つけたら**宣言句全体 (`{` まで)** を読み、
//     カンマ区切りの各名前を use 表で完全修飾名へ解決する。
//     **解決できない名前は候補から外さず fail させる** (未解決を落とす)。
foreach (cachePayloadInheritanceClause($tokens, $i, $useMap) as $declared) {
    if ($declared['resolved'] === null) {
        $unclassified[] = "{$relative}:{$declared['line']} → extends/implements <解決できない名前>";

        continue;
    }
    if (in_array($declared['resolved'], CACHE_PAYLOAD_RECEIVER_TYPES, true)
        || cachePayloadIsStoreType($declared['resolved'])) {
        $subclassDeclarations[] = "{$relative}:{$declared['line']} → {$declared['keyword']} {$declared['resolved']}";
    }
}
```

許すのは `tests/Support/Cache/PlainDataGuardedRepository.php`（`extends Repository`）と
`tests/Support/Cache/PlainDataGuardedCacheManager.php`（`extends CacheManager`）の
**名指し 2 件ちょうど**（exact-fit）。

**負例（最低 4 形。必須）**:

1. 2 番目の interface としての `Store`（`implements Countable, Store`）
2. 別名つき（`use Illuminate\Contracts\Cache\Store as CacheStore;` + `implements CacheStore`）
3. 完全修飾名（`implements \Illuminate\Contracts\Cache\Store`）
4. 複数行に分けた `implements`

**正例**: 無関係な interface だけの `implements`（`implements Countable, JsonSerializable`）を
迂回にしないこと。

### 11. 検査 L4a〜L4e

```php
test('検査 L4a: 受け皿の境界を迂回する API 呼び出しが自己テスト目録と exact-fit で一致する', function (): void { … });
test('検査 L4b: 受け手型 / 保管先型の直接生成が自己テスト目録と exact-fit で一致する', function (): void { … });
test('検査 L4f: 自己テスト目録の key は GuardedBoundaryProbe.php ちょうどにしか無い', function (): void { … });
test('検査 L4c: guard 付き manager は $store を受け皿の第 1 引数以外へ流さない', function (): void {
    // tests/Support/Cache/PlainDataGuardedCacheManager.php を字句走査し、
    // `$store` の出現が (1) 型宣言の直後 (2) new PlainDataGuardedRepository( の第 1 引数
    // の 2 か所ちょうどであることを固定する。
});
test('検査 L4d: 受け手型 / 保管先型の継承・実装が名指しの 2 件ちょうどである', function (): void { … });
test('検査 L4e: 保管先型の判定規則の正負コントロール', function (): void {
    expect(cachePayloadIsStoreType('Illuminate\Contracts\Cache\Store'))->toBeTrue();
    expect(cachePayloadIsStoreType('Illuminate\Cache\ArrayStore'))->toBeTrue();
    expect(cachePayloadIsStoreType('Illuminate\Cache\DatabaseStore'))->toBeTrue();
    expect(cachePayloadIsStoreType('Illuminate\Cache\Repository'))->toBeFalse();
    expect(cachePayloadIsStoreType('App\Support\Storage\ObjectStore'))->toBeFalse();
    expect(cachePayloadIsStoreType('Illuminate\Session\Store'))->toBeFalse();
});
```

### 12. L3 面の目録の追加と `guard-implementation` 役割

```php
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
    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php' => [
        'role' => 'write',
        'rationale' => '起動中の書き込みを guard が捕まえることを固定する見本 provider。boot() で意図的にオブジェクトを入れる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php' => [
        'role' => 'boundary-selftest',
        'rationale' => '境界迂回が hard fail することを固定する唯一の呼び出し元。L4 の自己テスト目録に登録できるのはこのファイルだけ',
    ],
```

`boundary-selftest` 役割の規則:

1. **L4 の自己テスト目録に entry を持つこと**
2. **L2 の書き込み目録に entry を持たないこと**（payload は書かない）
3. **パスが `tests/Support/Cache/GuardedBoundaryProbe.php` ちょうどであること**

`cachePayloadRoleViolations()` に `guard-implementation` を足す。規則は次の 3 つ。

1. **キャッシュ API 呼び出しが 0 件であること**（受け手型を参照するだけの実装である申告）
2. **L2 目録に entry を持たないこと**
3. **パスが `tests/Support/Cache/` 配下であること**
   （役割を任意のファイルが名乗って迂回実装の免除に使えないようにする。
   判定関数にパスを渡す形へ変更する）

`parent::put(...)` は受け手型の解決対象ではない（`parent` は受け手型に解決されない）ため、
規則 1 と衝突しない。`extends Repository` は**型参照**であって呼び出しではないので
`methods` は空のままである。正負コントロールを検査 5b に追加する。

### 13. L2 目録の `kind` 欄

```php
/**
 * kind = 'plain'          …素データを入れる本来の経路。proof は**配列往復を固定する単体テスト**
 *        'guard-selftest' …実行時層が違反を検出することを固定するための意図的な違反。
 *                           proof は**その検出を固定する振る舞い検査**
 */
const CACHE_PAYLOAD_WRITE_INVENTORY = [
    'app/Services/FxRateService.php::put' => [
        'kind' => 'plain',
        …既存のまま…
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::put' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass / Closure 等) と素データの両方。guard が前者だけを落とすことを固定する',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '実行時層が「保管前の値を再帰検査して落とす」ことを実 API 経由で固定する唯一の場所。ここが無いと申告の裏取りが機械化されない',
    ],
    // add / forever / putmany / set / setmultiple / remember / rememberforever /
    // sear / flexible / rememberwithwarmth / offsetset も同様 (各 count 1)
    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php::put' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '起動中に意図的に入れるオブジェクト (stdClass)。provider 自身が例外を握り潰す',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '起動 (bootstrap) 中の書き込みも guard が捕まえることを固定するための見本。結線点が beforeEach へ後退したら赤くなる',
    ],
];
```

検査 3 を `kind` で分岐させる（`plain` は proof に「往復を固定する単体テスト」を、
`guard-selftest` は proof に「振る舞い検査」を要求。どちらも**実在を検査する**）。
`kind` が 2 値のどちらでもなければ落とす。

### 14. 既存の負のコントロールの組み替え

- `Cache::getStore()->put('d', [1], 60);` は**迂回として検出される**ことへ変える
- `Cache::tags(['t'])->forever('f', [1]);` も同様
- 追加する負例: `Cache::extend('x', fn () => …)` / `$repo->macro('m', fn () => …)` /
  `new Repository($store)` / `new ArrayStore()` / `class X extends Repository {}` /
  `$cache['k'] = $obj;`
- 追加する正例: `new PlainDataGuardedRepository($store, [])` を**迂回にしない**こと
  （L4d が宣言側で塞いでいるので、生成そのものは自前クラスとして通る）

### PHPStan 適合チェック

- [x] `cachePayloadCollectAll()` の戻り値の array shape に
      `bypasses: list<string>` / `bypassCounts: array<string, int>` /
      `subclassDeclarations: list<string>` を足す
- [x] 目録の array shape に `kind: string` を足す
- [x] `cachePayloadRoleViolations()` の引数にパスを足す（全呼び出し元を直す）
- [x] `cachePayloadInheritanceClause()` の戻り値は
      `list<array{keyword: string, resolved: string|null, line: int}>`（未解決を `null` で返す）

### テスト計画

- L4a〜L4e の**負のコントロールを先に書く**（nowdoc の合成入力）
- 検査 5b に `guard-implementation` の正負（許可パス外で名乗ったら違反 / API 呼び出しがあれば違反）
- 検査 7（空振り検知）に「迂回の検出器・ArrayAccess の検出器が負例で反応する」ことを追加

### リスク

- `extend` を NON_WRITE から外すと既存の呼び出しがあれば赤くなる → 走査で 0 件を確認済み
- `tags` を CHAIN から外すと既存の使用があれば赤くなる → 使用は本 gate の fixture 1 件だけ
- `getstore` を CHAIN から外すと `Cache::getStore()->put(...)` の**書き込み検出**が消える。
  代わりに**迂回として落ちる**ので保護は弱くならない（負例で固定する）

---

## S8: 露出の計測と是正（反復）

### 変更箇所

- 新規: `devnotes/20260818-1757-cache-runtime-plain-data-guard/runtime-exposure.md`
- 是正対象は計測結果に依存する

### なぜ反復が要るか

guard は違反を記録した**その場で例外を投げる**ので、同じテストの中に複数の違反があっても
**最初の 1 件しか観測できない**。1 回の実行で「全件」は採れない。

### 手順

1. `composer test` と `composer test:browser` を走らせ、失敗した test 名・ファイル・
   違反メッセージを**その回 (wave) の分として**転記する
2. 出所を `app` / `tests` / `vendor` に分類し、下の判断基準で是正する
3. **1 に戻る**。違反が 0 になるまで繰り返す
4. `runtime-exposure.md` には**各 wave の結果と累積**を残す

### 記録する数

- **一意ファイル数**（差し戻し閾値の判定に使う）
- 違反サイト数（ファイル:行）
- 違反件数（延べ）

### 判断基準（概念設計より）

1. `app/` → 必ず直す（素の配列にして入れ、読み戻しで組み立て直す。L2 目録へ登録）
2. `tests/` → 必ず直す
3. vendor 由来 → (a) 所有する設定で閉じる / (b) 使わない形へ直す /
   (c) どちらもできなければ**実装を完了にせず**設計へ差し戻し、家系の台帳の議題として起こす。
   **guard 側に許可一覧を足す選択肢は取らない**
4. **累積の一意ファイル数が 10 以上**になったら実装を止めて設計へ差し戻し、TODO を分割する

### 前提

- `phpunit.xml` / `phpunit.browser.xml` に `stopOnFailure` / `stopOnError` の指定が**無い**ことを
  確認済み（既定は継続実行）。**途中終了した実行は未計測として扱う**
- **guard に「違反を許す計測モード」を足さない**（足せば一時免除になる）

### テスト計画

- 是正した経路ごとに、素データであることを固定する単体テスト（往復）を用意し、
  L2 目録の `proof` に書く

---

## S9: 同梱パッケージのオブジェクトキャッシュを設定で閉じる

### 変更箇所

- `config/prism-prompt.php`（L90-94）
- `tests/Feature/Config/ConfigHardeningTest.php`
- `.env.example`（`PRISM_PROMPT_CACHE` の記述があれば削除）

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
    // 宣言 pin (config ファイルを直接評価する。env を与えても開かないことを見る)
    $config = evaluateConfigFileWithEnv('prism-prompt.php', ['PRISM_PROMPT_CACHE' => 'true']);
    expect($config['cache']['enabled'])->toBeFalse(
        'PromptTemplate::fromYaml() がオブジェクトをキャッシュへ入れるため、env で開けられてはならない');
});

test('prism-prompt.cache.enabled は実行時にも false', function (): void {
    expect(config('prism-prompt.cache.enabled'))->toBeFalse();
});
```

### 追加の確認（保証範囲を限定する）

- **`.env.example` / 実行設定 (`phpunit.xml` / `phpunit.browser.xml` 等) / `config/` 本体**から
  `PRISM_PROMPT_CACHE` を除去する（死んだ設定を残すと「env で切り替えられる」という誤解を残す）。
  `.env.example` を変えたら `EnvExampleInvariantTest` の同期を同じ変更で揃える
- **「追跡下 0 件」は達成できない**。宣言 pin のテスト自身が
  `evaluateConfigFileWithEnv('prism-prompt.php', ['PRISM_PROMPT_CACHE' => 'true'])` として
  この語を持つし、本設計書にも残る。**検査を避けるために文字列を動的連結する形は採らない**

### リスク

- 呼び出し元が生まれたときにテンプレート解析が毎回走る（性能）。現状 0 件なので影響なし。
  必要になったら**素の配列を入れる形**へ直してから開ける

---

## S10: 規約の明文化

### 変更箇所

- `AGENTS.md` セキュリティ不変条件 11
- `docs/app-integration-guide.md` §7 不変条件 6
- `docs/architecture.md`（新節「キャッシュ素データ規約の 2 層」）

### 変更点

1. **素データの列挙に `null` を加える**（正典 AG-151 の文言に合わせる。本設計の冒頭「定義」参照）
2. 「静的検査で塞ぐ」→「**静的層 + 実行時層の 2 層で塞ぐ**」
3. **保証を過大に書かない** — とくに `getStore()` は静的層だけで塞いでいること、
   vendor が `getStore()` 経由で書く値は 2 層とも見えないことを書く

### 変更後コード（`AGENTS.md` 不変条件 11 の末尾）

```
    強制は **2 層**である (家系の裁定 AG-151 = 正典 v2)。
    **静的層** (`CachePayloadPlainDataGateTest`) は書き込み経路とキャッシュに触れるファイルを
    deny-by-default の目録で強制し、受け皿の境界を迂回する書き方 (`Cache::extend` /
    `getStore` / `setStore` / `tags` / 受け手型・保管先型の直接生成 / 継承 / macro 登録) を
    **通常経路は 0 件、実行時層の自己テストだけを名指しの目録へ exact-fit** で pin する。
    **実行時層** (`Tests\Support\Cache\PlainDataCacheGuard`) はテスト中のキャッシュ書き込みを
    受け皿の側で捕まえ、保管先へ渡す前の値を再帰検査する。結線はアプリ起動の前
    (`Tests\TestCase::createApplication()`) で、後始末は `tests/Pest.php` の全レーンが行う
    (`CacheGuardWiringGateTest` が deny-by-default で固定)。
    **「テストは array store なので実行時には捕まらない」は誤り** — 実行時層は直列化ではなく
    **値**を見るので、直列化しない保管方式でも同じように発火する。
    ただし **`getStore()` は実行時には落とせない** (vendor 自身が流量制限・排他の正常系で呼ぶ)
    ため、そこは静的層だけが塞ぐ。したがって
    **vendor が `getStore()` 経由で書く値は 2 層とも見えない**。
    設定の宣言 pin は `ConfigHardeningTest`、実効値は静的 gate の検査 6。
    **主要な境界の例外として `getStore()` だけをここにも記す**。
    網羅的な保証外一覧の正本は**実行時層の docblock**であり、本書と guide には写さない
    (2 か所に書くと必ず食い違う)。guide §7 不変条件 6 と対応
```

- 同項の冒頭の列挙を「配列 / 文字列 / 数値 / 真偽値 / **null**」に直す
- `docs/app-integration-guide.md` §7 不変条件 6 も同旨に直す
- `docs/architecture.md` に運用の説明（2 層の責務分担 / 露出したときの直し方 / 実装順）を書く。
  **保証しないものは書かない**（正本は docblock）

### テスト計画

- `docs` の記述は機械検査の対象外。**S6 / S7 のテストが緑であることが記述の裏付け**である
- AGENTS.md の検証コマンド節のマーカーは触らない

---

## S11: テンプレートとの差の登録

### 変更箇所

- `docs/template-divergence.md`（新規 **D30**）

### 登録するかの判断

laravel-claude-template の実装と本設計の差。**実装後に実在する差だけ**を登録する
（書式の正本は同ファイルの規約節。`TemplateDivergenceLedgerFormatTest` が
登録メタ表 9 行・状態の値域・対象パスの実在と重複・件数の 3 点一致を機械強制する）。

| 差 | テンプレート | 本リポジトリ |
|---|---|---|
| 結線点 | Pest の beforeEach 相当 | **アプリ起動の前** (`Tests\TestCase::createApplication()`) |
| 境界迂回の扱い | `Cache::extend()` / `getStore()` / `new Repository` / `new CacheManager` を hard fail | 上記に加えて **`setStore` / `tags` / macro 系 / 具体 store の生成 / 受け手型の継承**も対象。判定は **通常経路 0 件 + 自己テストの exact-fit** |
| 目録の構造 | 書き込みサイトの全数申告目録 | 既存の L1〜L3 に **L4 (迂回)** を足す形 |
| ArrayAccess 書き込み | 検出しない | `$cache[$k] = $v` を静的に検出する |

- `Cache::extend()` の迂回性の実証（S5 検査 19）で根拠が変わったら D30 の説明も同時に直す
- 実装時に差が消えていたら登録しない（**差が無いのに登録しない**）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `tests/Pest.php` / `tests/TestCase.php` / 1455 行の既存 gate という**他の TODO も触りうる共有ファイル**を変更し、かつ全レーンの実行結果に影響する。計測と是正の反復を挟むので他の作業と混ぜない |
| 競合リスク | `tests/Pest.php`（他の guard 追加と衝突しうる）/ `tests/Architecture/CachePayloadPlainDataGateTest.php`（新しいキャッシュ書き込みが増えると目録が動く）/ `AGENTS.md`（並行トラックが同じ節を触る）。main へ合流する直前に `git pull --rebase origin main` で取り込み、目録の件数を再確認する |

## 完了条件

1. S8 の計測記録（各 wave と累積）が devnotes に存在し、**違反が 0 になっている**
2. v2 の 4 要素がすべて満たされている（概念設計の対応表）
3. **未分類の `__call`（store 素通し）が残っていない**（無条件 hard fail のまま全レーンが緑である、
   または実測で出た用途を設計へ差し戻して分類済みである）
4. **AGENTS.md の `VERIFICATION_COMMANDS` 全件 green** + `composer test:browser`。
   **省略したコマンドがある状態で実装完了を報告しない**
5. 家系の台帳へ v2 として報告する準備ができている（**保証しないものを併記する** —
   とくに `getStore()` は静的層だけで塞いでいること、
   走査根の外で宣言された第三者 Store 実装は検出しないこと）。
   台帳への書き込み自体は本 TODO の範囲外


## 実装差分 (git diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 8c368e23..d65d1346 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -86,15 +86,30 @@ ## セキュリティ不変条件(アプリ都合で緩めない)
     `bootstrap/app.php` の **priority list**(route の宣言順ではない)
     (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
     `TenantBoundaryOrderingTest`)
-11. **キャッシュに入れるのは素のデータだけ**: cache へ渡す値は配列 / 文字列 / 数値 / 真偽値に限る
+11. **キャッシュに入れるのは素のデータだけ**: cache へ渡す値は
+    配列 / 文字列 / 数値 / 真偽値 / `null` に限る
     (オブジェクトを直接入れない)。読み戻しは `fromArray()` 等で**明示的に組み立て直して検査**し、
     失敗したら `forget` する(準拠実装 `FxRateService` + `FxSnapshotDto`)。
     `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧を作らず、
     **キーごと消さない**(宣言が無いと制限なしの `unserialize()` に戻る = fail-open)。
-    **テストは array store で緑になり本番 database store でだけ壊れる**ため、
-    書き込み経路とキャッシュに触れるファイルは deny-by-default の目録で強制する
-    (`CachePayloadPlainDataGateTest` / 宣言 pin は `ConfigHardeningTest`。
-    guide §7 不変条件 6 と対応)
+    強制は **2 層**である(家系の裁定 AG-151 = 正典 v2)。
+    **静的層** (`CachePayloadPlainDataGateTest`) は書き込み経路とキャッシュに触れるファイルを
+    deny-by-default の目録で強制し、受け皿の境界を迂回する書き方(`Cache::extend` /
+    `getStore` / `setStore` / `tags` / 受け手型・保管先型の直接生成 / 継承 / macro 登録)を
+    **通常経路は 0 件、実行時層の自己テストだけを名指しの目録へ exact-fit** で pin する。
+    **実行時層** (`Tests\Support\Cache\PlainDataCacheGuard`) はテスト中のキャッシュ書き込みを
+    受け皿の側で捕まえ、保管先へ渡す前の値を再帰検査する。結線はアプリ起動の前
+    (`Tests\TestCase::createApplication()`)で、後始末は `tests/Pest.php` の全レーンが行う
+    (`CacheGuardWiringGateTest` が deny-by-default で固定)。
+    **「テストは array store なので実行時には捕まらない」は誤り** — 実行時層は直列化ではなく
+    **値**を見るので、直列化しない保管方式でも同じように発火する。
+    ただし **`getStore()` は実行時には落とせない**(vendor 自身が流量制限・排他の正常系で呼ぶ)
+    ため、そこは静的層だけが塞ぐ。したがって
+    **vendor が `getStore()` 経由で書く値は 2 層とも見えない**。
+    設定の宣言 pin は `ConfigHardeningTest`、実効値は静的 gate の検査 6。
+    **主要な境界の例外として `getStore()` だけをここにも記す**。
+    網羅的な保証外一覧の正本は**実行時層の docblock**であり、本書と guide には写さない
+    (2 か所に書くと必ず食い違う)。guide §7 不変条件 6 と対応
 
 > **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
 > (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
diff --git a/config/prism-prompt.php b/config/prism-prompt.php
index ac0e8498..0725ce87 100644
--- a/config/prism-prompt.php
+++ b/config/prism-prompt.php
@@ -83,12 +83,20 @@
     |--------------------------------------------------------------------------
     |
     | Configuration for caching parsed YAML templates.
-    | Enabled by default in production for performance.
-    | Set PRISM_PROMPT_CACHE=false in .env for development.
+    |
+    | ★enabled は false 固定 (env を介さない)。
+    |   同梱パッケージの Kent013\PrismPrompt\PromptTemplate::fromYaml() は
+    |   Cache::store(...)->put($cacheKey, $instance, $ttl) で **PromptTemplate オブジェクトそのもの**を
+    |   キャッシュへ入れる。これは AGENTS.md セキュリティ不変条件 11 (キャッシュに入れるのは
+    |   素のデータだけ) に反する。有効・無効を決める設定は本リポジトリが所有しているので、
+    |   既定で閉じる。env で開け直せる形は残さない (開いた瞬間に規約違反になるため)。
+    |   ※現行コードを確認した範囲では fromYaml() の呼び出し元が無く、観測できる挙動の変化は
+    |     見込まれない。効果はパッケージ更新等で呼び出し元が生まれたときの fail-safe である。
+    |   宣言と実効値の二段 pin は tests/Feature/Config/ConfigHardeningTest.php。
     |
     */
     'cache' => [
-        'enabled' => env('PRISM_PROMPT_CACHE', true),
+        'enabled' => false,
         'ttl' => 3600,
         'store' => null, // null = default cache driver
     ],
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index 3e088a48..0fb799dc 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -229,14 +229,26 @@ ## 7. 守るべき不変条件(チェックリスト)
 6. **任意 class の逆シリアライズを許さない / キャッシュに入れるのは素のデータだけ**:
    `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧は作らない
    (例外を作らない)。**キーごと消すのも不可** — Laravel は宣言が無いと制限なしの
-   `unserialize()` に戻る(fail-open)。cache へ渡してよいのは配列 / 文字列 / 数値 / 真偽値だけで、
+   `unserialize()` に戻る(fail-open)。cache へ渡してよいのは
+   配列 / 文字列 / 数値 / 真偽値 / `null` だけで、
    オブジェクトは `toArray()` で素の配列にしてから入れ、読み戻しは `fromArray()` 等で
    **明示的に組み立て直して検査し、失敗したら `forget`** する
    (準拠実装: `App\Services\FxRateService` + `App\DataTransferObjects\FxSnapshotDto`)。
-   **テストレーンは array store(`serialize => false`)なのでオブジェクトを入れても緑になる** —
-   本番の database store でだけ壊れるため、静的検査で塞ぐ:
-   キャッシュ書き込み経路とキャッシュに触れるファイルは
-   `tests/Architecture/CachePayloadPlainDataGateTest.php` の目録へ登録必須(deny-by-default)。
+   強制は **2 層**である(家系の裁定 AG-151 = 正典 v2):
+   - **静的層** (`tests/Architecture/CachePayloadPlainDataGateTest.php`) —
+     キャッシュ書き込み経路とキャッシュに触れるファイルは目録へ登録必須(deny-by-default)。
+     受け皿の境界を迂回する書き方(`Cache::extend` / `getStore` / `setStore` / `tags` /
+     受け手型・保管先型の直接生成 / 継承 / macro 登録)は
+     **通常経路 0 件 + 実行時層の自己テストだけを名指しの目録へ exact-fit** で pin する
+   - **実行時層** (`Tests\Support\Cache\PlainDataCacheGuard`) —
+     テスト中のキャッシュ書き込みを受け皿の側で捕まえ、保管先へ渡す**前の値**を再帰検査する。
+     結線はアプリ起動の前(`Tests\TestCase::createApplication()`)、後始末は
+     `tests/Pest.php` の全レーン(`tests/Architecture/CacheGuardWiringGateTest.php` が固定)
+   **「テストは array store なので実行時には捕まらない」は誤り** — 実行時層は直列化ではなく
+   **値**を見るので、直列化しない保管方式でも同じように発火する。
+   ただし **`getStore()` は実行時には落とせない**(vendor 自身が流量制限・排他の正常系で呼ぶ)
+   ため、そこは静的層だけが塞ぐ。網羅的な保証外一覧の正本は**実行時層の docblock**であり、
+   本書と AGENTS.md には写さない。
    配列往復は `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` が固定する
 7. **課金系の冪等性**: webhook は冪等マシン経由、消費は 2 フェーズ、通知は dedup_key。
    課金による利用可否の判定は `BillingAccess` 経由のみ(subscription 直参照の gate 分岐禁止。
diff --git a/docs/architecture.md b/docs/architecture.md
index 99d803ba..67930924 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2853,3 +2853,70 @@ ### 保証しないもの (誇張しない)
 - **レーンの非対称**: 値集合の同期は `pnpm test` (CI の frontend job) でだけ走る。
   PHP としての妥当性は backend job (`composer test` / PHPStan)。
   **`composer test` だけでは値集合の同期は検証されない**。
+
+## キャッシュ素データ規約の 2 層 (T228 / 家系の裁定 AG-151 = 正典 v2)
+
+「キャッシュに入れるのは素のデータだけ」(AGENTS.md セキュリティ不変条件 11) は
+**静的層と実行時層の 2 層**で強制する。どちらも他方を包含しない。
+
+| 層 | 実体 | 保証すること |
+|---|---|---|
+| 静的層 | `tests/Architecture/CachePayloadPlainDataGateTest.php` | **申告なしに書き込み経路を増やせない**。境界を迂回する書き方が通常経路で 0 件である |
+| 実行時層 | `tests/Support/Cache/PlainDataCacheGuard.php` ほか 4 本 | **テストが実行した書き込みの値が実際に素データである** |
+
+- **静的層だけが見えるもの**: `tests/` `app/` にありながらテストが 1 度も踏まない書き込み。
+  実行時層は実行されないものを永久に見ない
+- **実行時層だけが見えるもの**: `vendor/` 配下からの書き込み。静的走査の母集団
+  (`app` / `routes` / `database` / `tests`) に入らないので、テストがその経路を踏んだときに
+  値を見られるのは実行時層だけである
+
+### 実行時層の仕組み
+
+受け皿 (`Illuminate\Cache\Repository`) を継承した `PlainDataGuardedRepository` が
+値の末端 4 メソッド (`put` / `add` / `forever` / `putMany`) を override し、
+保管先へ渡す**前の値**を `PlainDataInspector` で再帰検査する。
+糖衣 API (`set` / `setMultiple` / `remember` / `rememberForever` / `sear` / `flexible` /
+`rememberWithWarmth` / `$cache[$k] = $v`) は vendor 実装がこの 4 つへ合流するので、
+合流が将来変わったら `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php` が落ちる。
+
+**イベント購読 (`KeyWritten`) にはしない** — `Event::fake()` や store 設定の
+`'events' => false` で無効化できる差し替え可能な境界だからである。
+
+**結線はアプリ起動の前**である。`Tests\TestCase::createApplication()` が
+`bootstrap/app.php` を require した直後・`bootstrap()` の直前に
+`PlainDataCacheGuard::registerBeforeBootstrap()` を呼ぶ。Pest の beforeEach では遅く、
+起動中の書き込み (vendor 由来だと静的層の走査根にも入らない) が
+**2 層とも沈黙する穴**になる。
+
+**違反は「その場で例外」と「accumulator への記録」の両方**にする。アプリ側の
+`catch (Throwable)` で例外が消えても、afterEach の `flushAndFailIfStray()` で必ず赤くなる
+(既存の `StrayHttpRequestGuard` / `StrayLlmCallGuard` と同じ設計)。
+
+### 露出したときの直し方
+
+**免除目録は作らない**。出所ごとに次のとおり処理する。
+
+1. `app/` → 必ず直す。素の配列にして入れ、読み戻しで組み立て直す
+   (準拠実装 `FxRateService` + `FxSnapshotDto`)。あわせて静的層の L2 目録へ登録する
+2. `tests/` → 必ず直す (本番で壊れる書き方をテストが先取りしている状態である)
+3. vendor 由来 → (a) 本リポジトリが所有する設定でその機能を閉じる /
+   (b) その機能を使わない形へアプリを直す / (c) どちらもできなければ実装を完了にせず
+   家系の台帳の議題として起こす。**guard 側に許可一覧を足す選択肢は取らない**
+
+### 保管先への素通しの分類 (`__call`)
+
+`Illuminate\Cache\Repository` は `lock()` / `restoreLock()` を宣言しておらず、
+`Cache::lock(...)` は `Repository::__call()` の素通しで保管先へ届く。排他は payload を
+運ばないので、実行時層はこの 2 語彙**だけ**を名指しで通し、それ以外の素通しと
+macro 経由の呼び出しは境界迂回として落とす。許可を 2 か所で別々に育てないよう、
+静的層の TERMINAL 語彙との一致を同じ gate (検査 L4g) が固定する。
+
+### 設定で閉じたもの
+
+`config/prism-prompt.php` の `cache.enabled` は **`false` 固定** (env を介さない)。
+同梱パッケージの `PromptTemplate::fromYaml()` が `PromptTemplate` オブジェクトそのものを
+キャッシュへ入れるためで、有効・無効を決める設定を本リポジトリが所有している以上、
+既定で閉じるのが規約の帰結である。宣言と実効値の二段 pin は `ConfigHardeningTest`。
+
+> **保証しないものは本節に書かない**。正本は実行時層 (`PlainDataCacheGuard`) の docblock である
+> (2 か所に書くと必ず食い違う)。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 591e48d1..fd57cee3 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 28 件
+登録エントリ: 29 件
 
 ## 記録の原則
 
@@ -1698,3 +1698,61 @@ ### 関連
   `tests/js/architecture/enum-ts-sync-extractor.test.ts` /
   `tests/js/support/enum-ts-sync/`
 - 設計: `devnotes/20260817-1748-enum-ts-generic-sync-gate/`
+
+---
+
+## D30 キャッシュ素データ規約の実行時層を、アプリ起動の前に結線し境界迂回を正典より広く塞ぐ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Support/Cache/PlainDataCacheGuard.php` / `tests/Support/Cache/GuardedBoundaryProbe.php` / `tests/Architecture/CacheGuardWiringGateTest.php` |
+| 業務要件起因の説明 | 本アプリは起動時に名前付き流量制限を多数登録し、その時点で受け皿を握るため、Pest の beforeEach で結線すると起動中の書き込みが 2 層とも見えない穴になる。また同梱パッケージがオブジェクトをキャッシュへ入れる実装を持つため、受け皿を跨ぐ書き方を正典の 3 形より広く塞ぐ必要がある |
+| 揃え続ける不変条件と保証機構 | 結線がアプリ起動の前にあり全レーンが後始末すること (`CacheGuardWiringGateTest`)。受け皿を跨ぐ書き方が通常経路 0 件であること (`CachePayloadPlainDataGateTest` の検査 L4a-L4g) |
+| 再判定の条件 | 家系の正典が結線点と境界迂回の語彙を改めたとき / Laravel が `createApplication()` の本体を変えて写しが維持できなくなったとき |
+| 決めた日 | 2026-08-18 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260818-1757-cache-runtime-plain-data-guard/ |
+| 状態 | 監視中 |
+| 見直し期限 | 2027-02-14 |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 結線点 | Pest の beforeEach 相当 | アプリ起動の前 (`Tests\TestCase::createApplication()` の bootstrap 直前) |
+| 境界迂回の語彙 | 保管先の直接取得・受け皿の直接生成・拡張登録の 3 形 | 上記に加えて `setStore` / `tags` / macro 系 / 具体 store の生成 / 受け手型の継承・実装 |
+| 迂回の判定 | 0 件 | 通常経路 0 件 + 実行時層の自己テストだけを名指しの目録へ exact-fit |
+| 目録の構造 | 書き込みサイトの全数申告目録 | 既存の L1-L3 に L4 (迂回) を足す形 |
+| ArrayAccess 書き込み | 検出しない | `$cache[$k] = $v` を静的にも検出する |
+
+### なぜ正当な差分か (logic-driven)
+
+`AppServiceProvider::boot()` が名前付き流量制限を多数登録するため、`Illuminate\Cache\RateLimiter` は
+**起動中に** cache を解決して受け皿を握る。beforeEach で結線すると RateLimiter が握るのは
+guard の付いていない受け皿になり、起動中の書き込みは実行時層に見えない。
+vendor 由来の書き込みは静的層の走査根 (`app` / `routes` / `database` / `tests`) にも入らないので、
+**2 層とも沈黙する**。`Illuminate\Foundation\Testing\TestCase::createApplication()` は
+`bootstrap/app.php` を require したあと `bootstrap()` を呼ぶ間に**まだ起動していない `$app`** に
+触れる唯一の点なので、そこを override して結線する。
+
+境界迂回を広げたのは、`Repository::tags()` が `new TaggedCache($this->store, ...)` を素で生成して
+継承を素通りすること、`Repository` が `Macroable` を use しており macro の closure から
+`$this->store` へ直接到達できることを vendor 実読で確認したためである。
+どちらも実行時層の被覆から抜ける口であり、正典の 3 形には含まれていない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「テストが実行したキャッシュ書き込みの値は、保管先へ渡る前に素データであることを検査されている」
+
+- 結線がアプリ起動の前にあることと、全レーンが後始末することは `CacheGuardWiringGateTest` が固定する
+- vendor の `createApplication()` の写しは token 列の完全一致で pin するので、静かに古くならない
+- 受け皿を跨ぐ書き方は自己テスト目録と exact-fit で、1 件増えたら必ず赤くなる
+
+### 保証しないもの
+
+- 保証しないものの正本は `tests/Support/Cache/PlainDataCacheGuard.php` の docblock である
+  (本書と `docs/architecture.md` には写さない)
+
+### 関連
+
+- 実装: `tests/Support/Cache/` / `tests/TestCase.php` / `tests/Pest.php` /
+  `tests/Architecture/CachePayloadPlainDataGateTest.php`
+- 設計: `devnotes/20260818-1757-cache-runtime-plain-data-guard/`
diff --git a/tests/Architecture/CacheGuardWiringGateTest.php b/tests/Architecture/CacheGuardWiringGateTest.php
new file mode 100644
index 00000000..0f04fc2f
--- /dev/null
+++ b/tests/Architecture/CacheGuardWiringGateTest.php
@@ -0,0 +1,553 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Foundation\Testing\TestCase as VendorTestCase;
+use Tests\Support\Cache\IsolatedApplicationProbe;
+use Tests\TestCase;
+
+/*
+ * Architecture invariant: **キャッシュ素データ規約の実行時層が、アプリ起動の前に結線され、
+ * 全レーンで後始末されている**こと (家系の裁定 AG-151 = 正典 v2 の要素 2)。
+ *
+ * 実行時層そのもの (値の検査・境界迂回の hard fail) の振る舞いは
+ * tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が固定する。
+ * 本 gate が固定するのは**結線**である — 結線が beforeEach へ後退したり、
+ * どこかのレーンから flush が抜けたりすると、検査は緑のまま**検出だけが消える**。
+ *
+ * ★この gate が保証するもの:
+ *   - W1: Tests\TestCase::createApplication() が bootstrap() より**前**に
+ *     PlainDataCacheGuard::registerBeforeBootstrap() を呼ぶ (token 位置で判定)
+ *   - W2/W3: tests/Pest.php の**期待するレーン集合ちょうど** ({Feature, Unit} / {Architecture} /
+ *     {Browser}) の beforeEach に assertInstalled、afterEach に flushAndFailIfStray と reset がある
+ *   - W4: WithCachedConfig / WithCachedRoutes を使うテストが 0 件である
+ *     (使い始めると override が vendor と食い違う前提が崩れる)
+ *   - W5: vendor の Illuminate\Foundation\Testing\TestCase::createApplication() の
+ *     正規化 token 列が期待値と**完全一致**する (Laravel 更新で写しが静かに古くならない)
+ *   - W5b: ローカルの写しが「vendor 期待列 + 許可差分 3 つ」と**完全一致**する。
+ *     許可差分は (1) 戻り値の fail-closed 確認 (2) 結線 1 行 (3) 戻り値型と #[\Override] だけ
+ *   - W6: 起動中の負例 (IsolatedApplicationProbe) が **同じ関数**を bootstrap より前に呼ぶ
+ *   - W7: 空振り検知 (走査ファイルが実在 / token 数が 0 でない / 許可差分がすべて位置ごと一致 /
+ *     検出器が合成入力の負例に反応する)
+ *   - W8: 負のコントロール (flush が無いレーン / bootstrap の後で結線 / レーン集合違い /
+ *     vendor 本体の token 増減・順序入れ替え / ローカルから既知の文を削除)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - vendor 側の `setUp()` / `refreshApplication()` の変更や bootstrapper の増減は見ない。
+ *     見るのは `createApplication()` の**本体だけ**である
+ *   - tests/Pest.php の**実行時の**挙動は見ない (字句として書かれていることだけを見る)。
+ *     実際に flush が発火することは CachePayloadPlainDataGuardTest の負例が示す
+ *   - レーンを新設したことは W2/W3 のレーン集合 exact-fit で赤くなるが、
+ *     phpunit.xml の testsuite 構成そのものは見ない
+ *
+ * 解析は PhpToken::tokenize (コメント・文字列リテラルは code token ではないので拾わない)。
+ * regex にすると**この説明コメント自身**で偽赤になる。
+ */
+
+/**
+ * vendor の `Illuminate\Foundation\Testing\TestCase::createApplication()` の正規化 token 列。
+ * Laravel 更新で 1 token でも変わったら W5 が赤くなる。**それが目的**である。
+ *
+ * @var list<string>
+ */
+const CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS = [
+    'public', 'function', 'createApplication', '(', ')', '{', '$app', '=', 'require', 'Application',
+    '::', 'inferBasePath', '(', ')', '.', '\'/bootstrap/app.php\'', ';', '$this', '->', 'traitsUsedByTest',
+    '=', 'class_uses_recursive', '(', 'static', '::', 'class', ')', ';', 'if', '(',
+    'isset', '(', 'CachedState', '::', '$cachedConfig', ',', '$this', '->', 'traitsUsedByTest', '[',
+    'WithCachedConfig', '::', 'class', ']', ')', ')', '{', '$this', '->', 'markConfigCached',
+    '(', '$app', ')', ';', '}', 'if', '(', 'isset', '(', 'CachedState',
+    '::', '$cachedRoutes', ',', '$this', '->', 'traitsUsedByTest', '[', 'WithCachedRoutes', '::', 'class',
+    ']', ')', ')', '{', '$app', '->', 'booting', '(', 'fn', '(',
+    ')', '=>', '$this', '->', 'markRoutesCached', '(', '$app', ')', ')', ';',
+    '}', '$app', '->', 'make', '(', 'Kernel', '::', 'class', ')', '->',
+    'bootstrap', '(', ')', ';', 'return', '$app', ';', '}',
+];
+
+/**
+ * ローカルの `Tests\TestCase::createApplication()` の正規化 token 列。
+ *
+ * ★W5 は vendor 側の変更しか見ず、W1 は「結線が bootstrap より前にある」ことしか見ない。
+ *   その 2 つだけだと、ローカルの写しから `$this->traitsUsedByTest` の代入・cached config 分岐・
+ *   cached routes 分岐・`return $app` を消しても**両方とも緑のまま**になる。
+ *
+ * @var list<string>
+ */
+const CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS = [
+    'public', 'function', 'createApplication', '(', ')', ':', 'Application', '{', '$app', '=',
+    'require', 'Application', '::', 'inferBasePath', '(', ')', '.', '\'/bootstrap/app.php\'', ';', 'if',
+    '(', '!', '$app', 'instanceof', 'Application', ')', '{', 'throw', 'new', 'RuntimeException',
+    '(', '\'bootstrap/app.php が Application を返しませんでした\'', ')', ';', '}', 'PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(', '$app',
+    ')', ';', '$this', '->', 'traitsUsedByTest', '=', 'class_uses_recursive', '(', 'static', '::',
+    'class', ')', ';', 'if', '(', 'isset', '(', 'CachedState', '::', '$cachedConfig',
+    ',', '$this', '->', 'traitsUsedByTest', '[', 'WithCachedConfig', '::', 'class', ']', ')',
+    ')', '{', '$this', '->', 'markConfigCached', '(', '$app', ')', ';', '}',
+    'if', '(', 'isset', '(', 'CachedState', '::', '$cachedRoutes', ',', '$this', '->',
+    'traitsUsedByTest', '[', 'WithCachedRoutes', '::', 'class', ']', ')', ')', '{', '$app',
+    '->', 'booting', '(', 'fn', '(', ')', '=>', '$this', '->', 'markRoutesCached',
+    '(', '$app', ')', ')', ';', '}', '$app', '->', 'make', '(',
+    'Kernel', '::', 'class', ')', '->', 'bootstrap', '(', ')', ';', 'return',
+    '$app', ';', '}',
+];
+
+/**
+ * ローカルの写しに足してよい差分 (offset は**ローカル列の index**、tokens は挿入された列)。
+ *
+ * ここから挿入を取り除くと vendor 期待列に**完全一致**しなければならない。
+ * 部分列の除去だけだと別の位置に同じ列を置いても通るため、**位置まで固定する**。
+ *
+ * @var list<array{reason: string, offset: int, tokens: list<string>}>
+ */
+const CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS = [
+    [
+        'reason' => '戻り値型の宣言 (vendor は docblock だけなので狭めていない)',
+        'offset' => 5,
+        'tokens' => [':', 'Application'],
+    ],
+    [
+        'reason' => '戻り値の fail-closed 確認と、bootstrap 直前の結線 1 行',
+        'offset' => 19,
+        'tokens' => [
+            'if', '(', '!', '$app', 'instanceof', 'Application', ')', '{', 'throw', 'new',
+            'RuntimeException', '(', '\'bootstrap/app.php が Application を返しませんでした\'', ')', ';', '}',
+            'PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(', '$app', ')', ';',
+        ],
+    ],
+];
+
+/**
+ * tests/Pest.php で期待するレーン集合 (`->in(...)` の引数集合)。
+ *
+ * @var list<list<string>>
+ */
+const CACHE_GUARD_EXPECTED_LANES = [
+    ['Architecture'],
+    ['Browser'],
+    ['Feature', 'Unit'],
+];
+
+/**
+ * 空白・コメント・開始タグを落とした token の文字列列。
+ *
+ * @return list<string>
+ */
+function cacheGuardNormalizedTokens(string $source): array
+{
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize($source);
+
+    return array_values(array_map(
+        static fn (PhpToken $token): string => $token->text,
+        array_filter(
+            $tokens,
+            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]),
+        ),
+    ));
+}
+
+/**
+ * メソッド本体の正規化 token 列を反射で取り出す (fail-closed)。
+ *
+ * @return list<string>
+ */
+function cacheGuardMethodTokens(string $class, string $method): array
+{
+    $reflection = new ReflectionMethod($class, $method);
+
+    $file = $reflection->getFileName();
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    if ($file === false || $start === false || $end === false) {
+        throw new RuntimeException("{$class}::{$method}() の定義位置を解決できません (内部関数か eval)");
+    }
+
+    $lines = file($file);
+    if ($lines === false) {
+        throw new RuntimeException("{$file} を読めません");
+    }
+
+    return cacheGuardNormalizedTokens(
+        '<?php '.implode('', array_slice($lines, $start - 1, $end - $start + 1))
+    );
+}
+
+/**
+ * token 列 $needle が最初に現れる位置。無ければ null。
+ *
+ * @param  list<string>  $tokens
+ * @param  list<string>  $needle
+ */
+function cacheGuardSequencePosition(array $tokens, array $needle, int $from = 0): ?int
+{
+    $limit = count($tokens) - count($needle);
+    for ($i = $from; $i <= $limit; $i++) {
+        if (array_slice($tokens, $i, count($needle)) === $needle) {
+            return $i;
+        }
+    }
+
+    return null;
+}
+
+/**
+ * 「結線が bootstrap より**前**にある」ことの違反理由 (純関数。合成入力にも当てられる)。
+ *
+ * @return list<string>
+ */
+function cacheGuardBootstrapOrderViolations(string $source, string $label): array
+{
+    $tokens = cacheGuardNormalizedTokens($source);
+
+    $wiring = cacheGuardSequencePosition($tokens, ['PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(']);
+    $bootstrap = cacheGuardSequencePosition($tokens, ['->', 'bootstrap', '(', ')']);
+
+    $violations = [];
+    if ($wiring === null) {
+        $violations[] = "{$label}: PlainDataCacheGuard::registerBeforeBootstrap() の呼び出しがありません";
+    }
+    if ($bootstrap === null) {
+        $violations[] = "{$label}: ->bootstrap() の呼び出しがありません (走査対象を取り違えている)";
+    }
+    if ($wiring !== null && $bootstrap !== null && $wiring > $bootstrap) {
+        $violations[] = "{$label}: 結線が bootstrap() より後にあります (起動中の書き込みを見逃す)";
+    }
+
+    return $violations;
+}
+
+/**
+ * tests/Pest.php を `pest()->extend(TestCase::class)` 単位のレーンブロックへ割る。
+ *
+ * @return list<array{lanes: list<string>, tokens: list<string>}>
+ */
+function cacheGuardLaneBlocks(string $source): array
+{
+    $tokens = cacheGuardNormalizedTokens($source);
+    $starts = [];
+    $from = 0;
+    while (($position = cacheGuardSequencePosition($tokens, ['pest', '(', ')', '->', 'extend'], $from)) !== null) {
+        $starts[] = $position;
+        $from = $position + 1;
+    }
+
+    $blocks = [];
+    foreach ($starts as $index => $start) {
+        $end = $starts[$index + 1] ?? count($tokens);
+        $block = array_slice($tokens, $start, $end - $start);
+
+        $lanes = [];
+        $inPosition = cacheGuardSequencePosition($block, ['->', 'in', '(']);
+        if ($inPosition !== null) {
+            for ($i = $inPosition + 3; $i < count($block); $i++) {
+                if ($block[$i] === ')') {
+                    break;
+                }
+                if ($block[$i] === ',') {
+                    continue;
+                }
+                $lanes[] = trim($block[$i], "'\"");
+            }
+        }
+        sort($lanes);
+
+        $blocks[] = ['lanes' => $lanes, 'tokens' => $block];
+    }
+
+    return $blocks;
+}
+
+/**
+ * 1 レーンブロックの後始末の違反理由 (純関数。合成入力にも当てられる)。
+ *
+ * @param  list<string>  $block
+ * @return list<string>
+ */
+function cacheGuardLaneWiringViolations(array $block, string $label): array
+{
+    $violations = [];
+
+    $beforeEach = cacheGuardSequencePosition($block, ['->', 'beforeEach', '(']);
+    $afterEach = cacheGuardSequencePosition($block, ['->', 'afterEach', '(']);
+    if ($beforeEach === null) {
+        $violations[] = "{$label}: beforeEach がありません";
+    }
+    if ($afterEach === null) {
+        $violations[] = "{$label}: afterEach がありません";
+
+        return $violations;
+    }
+
+    $assertInstalled = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'assertInstalled', '(']);
+    if ($assertInstalled === null || $assertInstalled > $afterEach) {
+        $violations[] = "{$label}: beforeEach で PlainDataCacheGuard::assertInstalled() を呼んでいません";
+    }
+
+    $flush = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'flushAndFailIfStray', '(']);
+    if ($flush === null || $flush < $afterEach) {
+        $violations[] = "{$label}: afterEach で PlainDataCacheGuard::flushAndFailIfStray() を呼んでいません";
+    }
+
+    $reset = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'reset', '(']);
+    if ($reset === null || $reset < $afterEach) {
+        $violations[] = "{$label}: afterEach の finally で PlainDataCacheGuard::reset() を呼んでいません";
+    }
+
+    return $violations;
+}
+
+/** 走査対象を fail-closed で読む。 */
+function cacheGuardReadSource(string $relative): string
+{
+    $absolute = base_path($relative);
+    expect(is_file($absolute))->toBeTrue("{$relative} が実在しません (走査根の改名を疑う)");
+
+    $source = file_get_contents($absolute);
+    expect($source)->toBeString("{$relative} を読めません");
+
+    return (string) $source;
+}
+
+// ---------------------------------------------------------------------------
+// W1 / W6: 結線が bootstrap より前にある
+// ---------------------------------------------------------------------------
+
+test('W1: Tests\TestCase::createApplication() は bootstrap() より前に結線する', function (): void {
+    expect(cacheGuardBootstrapOrderViolations(cacheGuardReadSource('tests/TestCase.php'), 'tests/TestCase.php'))
+        ->toBe([]);
+});
+
+test('W6: 起動中の負例も同じ関数を bootstrap より前に呼ぶ', function (): void {
+    // ★負例が別経路で結線していたら「同じ結線を通った」ことの証明にならない。
+    $relative = 'tests/Support/Cache/IsolatedApplicationProbe.php';
+    expect(cacheGuardBootstrapOrderViolations(cacheGuardReadSource($relative), $relative))->toBe([]);
+
+    // 名前だけでなく**実在するメソッド**を指していることも確かめる (綴り間違いで空振りしない)。
+    expect(method_exists(IsolatedApplicationProbe::class, 'run'))->toBeTrue();
+});
+
+// ---------------------------------------------------------------------------
+// W2 / W3: 全レーンの結線と後始末
+// ---------------------------------------------------------------------------
+
+test('W2/W3: tests/Pest.php の期待レーン集合ちょうどが結線と後始末を持つ', function (): void {
+    $blocks = cacheGuardLaneBlocks(cacheGuardReadSource('tests/Pest.php'));
+
+    $lanes = array_map(static fn (array $block): array => $block['lanes'], $blocks);
+    $expected = CACHE_GUARD_EXPECTED_LANES;
+    usort($lanes, static fn (array $a, array $b): int => implode(',', $a) <=> implode(',', $b));
+
+    expect($lanes)->toBe($expected,
+        'tests/Pest.php のレーン構成が期待と一致しません。レーンを増減したなら '
+        .'CACHE_GUARD_EXPECTED_LANES も同じ変更で直し、新レーンにも guard の結線と後始末を入れてください。');
+
+    foreach ($blocks as $block) {
+        expect(cacheGuardLaneWiringViolations($block['tokens'], implode('+', $block['lanes'])))->toBe([]);
+    }
+});
+
+// ---------------------------------------------------------------------------
+// W4: vendor 追随の前提 (cached config / cached routes を使っていない)
+// ---------------------------------------------------------------------------
+
+test('W4: WithCachedConfig / WithCachedRoutes を使うテストが 0 件である', function (): void {
+    // ★使い始めると createApplication() の写しが vendor と食い違い、
+    //   cached 分岐の意味が変わる。使うときは override を写し直すこと。
+    $root = base_path('tests');
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
+    );
+
+    $users = [];
+    $files = 0;
+    foreach ($iterator as $file) {
+        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $absolute = $file->getRealPath();
+        if (! is_string($absolute) || $absolute === __FILE__) {
+            continue;
+        }
+        $files++;
+        $tokens = cacheGuardNormalizedTokens((string) file_get_contents($absolute));
+        foreach (['WithCachedConfig', 'WithCachedRoutes'] as $trait) {
+            if (cacheGuardSequencePosition($tokens, ['use', $trait, ';']) !== null) {
+                $users[] = ltrim(str_replace(base_path(), '', $absolute), '/');
+            }
+        }
+    }
+
+    expect($files)->toBeGreaterThan(0, 'tests/ の走査が空振りしている');
+    expect($users)->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// W5 / W5b: vendor 本体とローカルの写しの token 完全一致
+// ---------------------------------------------------------------------------
+
+test('W5: vendor の createApplication() の token 列が期待値と完全一致する', function (): void {
+    expect(cacheGuardMethodTokens(VendorTestCase::class, 'createApplication'))
+        ->toBe(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS,
+            'Laravel の createApplication() が変わりました。tests/TestCase.php の写しを'
+            .'読み直して更新し、本 gate の期待 token 列も同じ変更で直してください。');
+});
+
+test('W5b: ローカルの写しが vendor 期待列 + 許可差分と完全一致する', function (): void {
+    $local = cacheGuardMethodTokens(TestCase::class, 'createApplication');
+
+    // (i) ローカルの期待列と完全一致する (位置まで固定する)
+    expect($local)->toBe(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS,
+        'tests/TestCase.php の createApplication() が期待と一致しません。'
+        .'vendor の写しから文を消していないか確認してください。');
+
+    // (ii) 許可差分を取り除くと vendor 期待列に一致する
+    $stripped = $local;
+    foreach (array_reverse(CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS) as $insertion) {
+        expect(array_slice($local, $insertion['offset'], count($insertion['tokens'])))
+            ->toBe($insertion['tokens'], "許可差分「{$insertion['reason']}」が期待位置にありません");
+        array_splice($stripped, $insertion['offset'], count($insertion['tokens']));
+    }
+
+    expect($stripped)->toBe(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS,
+        '許可差分 (戻り値型 / fail-closed 確認 / 結線 1 行) 以外の変更が入っています。');
+
+    // #[\Override] は反射で別途見る (getStartLine から切り出したソースに属性行が入る保証が無い)。
+    expect((new ReflectionMethod(TestCase::class, 'createApplication'))->getAttributes(Override::class))
+        ->toHaveCount(1);
+});
+
+// ---------------------------------------------------------------------------
+// W7: 空振り検知
+// ---------------------------------------------------------------------------
+
+test('W7: 走査と検出器が空振りしていない', function (): void {
+    expect(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS)->not->toBe([]);
+    expect(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS)->not->toBe([]);
+    expect(cacheGuardMethodTokens(VendorTestCase::class, 'createApplication'))->not->toBe([]);
+    expect(cacheGuardMethodTokens(TestCase::class, 'createApplication'))->not->toBe([]);
+    expect(cacheGuardLaneBlocks(cacheGuardReadSource('tests/Pest.php')))->toHaveCount(3);
+
+    // 許可差分の合計が token 数の差と一致する (取りこぼした差分が無い)
+    $inserted = array_sum(array_map(
+        static fn (array $insertion): int => count($insertion['tokens']),
+        CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS,
+    ));
+    expect(count(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS) - count(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS))
+        ->toBe($inserted);
+
+    // 検出器が負例に反応する (実在ファイルの構成に依存させない)
+    expect(cacheGuardBootstrapOrderViolations('<?php $app->make(Kernel::class)->bootstrap();', 'probe'))
+        ->not->toBe([]);
+    expect(cacheGuardLaneWiringViolations(cacheGuardNormalizedTokens('<?php pest()->extend(TestCase::class);'), 'probe'))
+        ->not->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// W8: 負のコントロール
+// ---------------------------------------------------------------------------
+
+test('W8: 結線が bootstrap の後にある形を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    class Probe {
+        public function createApplication() {
+            $app = require 'bootstrap/app.php';
+            $app->make(Kernel::class)->bootstrap();
+            PlainDataCacheGuard::registerBeforeBootstrap($app);
+            return $app;
+        }
+    }
+    PHP;
+
+    expect(cacheGuardBootstrapOrderViolations($fixture, 'fixture'))->toHaveCount(1);
+});
+
+test('W8: 結線そのものが無い形を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    class Probe {
+        public function createApplication() {
+            $app = require 'bootstrap/app.php';
+            $app->make(Kernel::class)->bootstrap();
+            return $app;
+        }
+    }
+    PHP;
+
+    expect(cacheGuardBootstrapOrderViolations($fixture, 'fixture'))->toHaveCount(1);
+});
+
+test('W8: レーンから flush / reset / assertInstalled が抜けた形を検出する', function (): void {
+    $missingFlush = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            PlainDataCacheGuard::reset();
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $blocks = cacheGuardLaneBlocks($missingFlush);
+    expect($blocks)->toHaveCount(1);
+    expect($blocks[0]['lanes'])->toBe(['Feature', 'Unit']);
+    expect(cacheGuardLaneWiringViolations($blocks[0]['tokens'], 'fixture'))->toHaveCount(1);
+
+    $missingAssert = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+        })
+        ->afterEach(function (): void {
+            PlainDataCacheGuard::flushAndFailIfStray();
+            PlainDataCacheGuard::reset();
+        })
+        ->in('Architecture');
+    PHP;
+
+    $blocks = cacheGuardLaneBlocks($missingAssert);
+    expect(cacheGuardLaneWiringViolations($blocks[0]['tokens'], 'fixture'))->toHaveCount(1);
+});
+
+test('W8: レーン集合が違う形を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)->in('Feature');
+    pest()->extend(TestCase::class)->in('Unit');
+    PHP;
+
+    $lanes = array_map(static fn (array $block): array => $block['lanes'], cacheGuardLaneBlocks($fixture));
+    expect($lanes)->not->toBe(CACHE_GUARD_EXPECTED_LANES);
+});
+
+test('W8: vendor 本体の token 増減・順序入れ替えを検出する', function (): void {
+    $expected = CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS;
+
+    $added = $expected;
+    $added[] = ';';
+    expect($added)->not->toBe($expected);
+
+    $swapped = $expected;
+    [$swapped[6], $swapped[7]] = [$swapped[7], $swapped[6]];
+    expect($swapped)->not->toBe($expected);
+    expect(count($swapped))->toBe(count($expected)); // 数だけでは検出できないことの明示
+});
+
+test('W8: ローカルの写しから既知の文を消した形を検出する', function (): void {
+    // ★W5 (vendor 側) と W1 (順序) だけでは緑のまま通ってしまう改変を、W5b が捕まえる。
+    foreach ([
+        'traitsUsedByTest の代入' => ['$this', '->', 'traitsUsedByTest', '=', 'class_uses_recursive'],
+        'cached config 分岐' => ['WithCachedConfig', '::', 'class'],
+        'cached routes 分岐' => ['WithCachedRoutes', '::', 'class'],
+        'return $app' => ['return', '$app', ';'],
+    ] as $label => $needle) {
+        $position = cacheGuardSequencePosition(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS, $needle);
+        expect($position)->not->toBeNull("{$label} が期待列にありません");
+
+        $damaged = CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS;
+        array_splice($damaged, (int) $position, count($needle));
+
+        expect($damaged)->not->toBe(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS, "{$label}: 削除が反映されていない");
+    }
+});
diff --git a/tests/Architecture/CachePayloadPlainDataGateTest.php b/tests/Architecture/CachePayloadPlainDataGateTest.php
index bc74a21e..82fcb4ec 100644
--- a/tests/Architecture/CachePayloadPlainDataGateTest.php
+++ b/tests/Architecture/CachePayloadPlainDataGateTest.php
@@ -1,6 +1,7 @@
 <?php
 
 declare(strict_types=1);
+use Tests\Support\Cache\PlainDataGuardedRepository;
 
 /*
  * Architecture invariant: **キャッシュに入れてよいのは素のデータだけ** (配列 / 文字列 / 数値 / 真偽値)。
@@ -8,12 +9,25 @@
  * SoT = lctl 台帳 feature `cache-payload-plain-data` の標準形 v1 (裁定 2026-08-06) と
  * AGENTS.md セキュリティ不変条件 11 / docs/app-integration-guide.md §7 不変条件 6。
  *
- * ★なぜ静的検査か (実行時検出では捕まらない):
- *   テストレーンは phpunit.xml で CACHE_STORE=array、config/cache.php の array store は
- *   'serialize' => false。**オブジェクトを put してもそのまま返る = テストは緑になる**。
- *   本番は database store で serialize され、serializable_classes => false のため
- *   読み戻しは __PHP_Incomplete_Class になる。つまり「テストで再現しない本番専用の壊れ方」であり、
- *   実行時 detector (KeyWritten 購読等) は原理的にこの穴を塞げない。
+ * ★2 層構成のうち**静的層**がこのファイルである (家系の裁定 AG-151 = 正典 v2)。
+ *   - 静的層 (ここ) が保証するのは「**申告なしに書き込み経路を増やせない**」ことである。
+ *     目録の payload 欄は**人間の申告**なので、書いた値が実際に素データかは保証しない
+ *   - 実行時層 (tests/Support/Cache/PlainDataCacheGuard.php) が保証するのは
+ *     「**テストが実行した書き込みの値が実際に素データである**」ことである。
+ *     受け皿 (Illuminate\Cache\Repository) を包んで保管先へ渡す前の値を再帰検査するので、
+ *     **直列化を一度も経由しない = テストレーンの array store でも同じように発火する**
+ *   - どちらも他方を包含しない。vendor 由来の書き込みは静的層の走査根に入らず (実行時層だけが見る)、
+ *     テストが 1 度も踏まない経路は実行時層に見えない (静的層だけが見る)
+ *
+ *   ※ 旧版のこの位置には「実行時 detector は原理的にこの穴を塞げない」という記述があったが、
+ *     これは**書き込みイベントを購読する型の検出器にだけ当てはまる主張**で、
+ *     受け皿を包んで値を見る型には当てはまらない。裁定 AG-151 が誤りとして棄却したので削除した。
+ *
+ * ★L4 (境界迂回) を**静的層だけで塞ぐ**ものがある。とくに `getStore()` は
+ *   vendor 自身が正常系で呼ぶため実行時には落とせない (RateLimiter の hit/increment 経路、
+ *   Repository::flushLocks() の自己呼び出し、スケジューラの排他など)。
+ *   よって「保管先を直接取得して書く」形を塞ぐのは**このファイルだけ**であり、
+ *   vendor が getStore() 経由で書く値は 2 層とも見えない (保証しないもの)。
  *
  * ★serializable_classes は **false 固定**であって「キーを消してよい」ではない:
  *   CacheManager は `config['cache.serializable_classes'] ?? null` を読み、各 store は
@@ -33,7 +47,11 @@
  *     (規則自体も検査 5b で固定)
  *   - 検査 6: 実行時 config('cache.serializable_classes') === false、store 単位の上書きなし
  *   - 検査 5b: role 判定規則そのものの正負コントロール (実在ファイルの構成に依存させない)
- *   - 検査 6b: 語彙表の健全性 (4 分類が互いに素 / 除外型が受け手型に混ざっていない)
+ *   - 検査 6b: 語彙表の健全性 (5 分類が互いに素 / 除外型が受け手型に混ざっていない)
+ *   - 検査 L4a-L4f (境界迂回): 受け皿を跨いで保管先へ届く / 受け皿の生成に割り込む書き方
+ *     (`extend` / `getStore` / `setStore` / `tags` / `macro` / `mixin` / `flushMacros` /
+ *     受け手型・保管先型の直接生成 / 継承・実装の宣言) が、**通常経路 0 件 +
+ *     実行時層の自己テストの exact-fit** に収まっている
  *   - 検査 7: 空振り検知 (走査ファイル数 / メソッド呼び出し数 / 解決できたキャッシュ式が 0 でない)
  *   - 検査 8: 自己参照コントロール (本ファイル自身を走査して書き込み 0 件・面 hit なし)
  *   - 検査 9 以降: 正負コントロール fixture (facade / チェーン / ヘルパ / DI / コンテナ /
@@ -51,6 +69,10 @@
  *     この形は実測 0 件で、通常のレビューで自明に不自然な書き方である
  *   ※ 受け手が cache と分かっている上での**動的メソッド名** (`->{$m}(...)` / `->$m(...)`) は
  *     素通りさせず `unclassified` として fail させる。literal 形 (`->{'put'}(...)`) は通常形と同じに分類する
+ *   - **走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に一致しない第三者の
+ *     `Store` 実装**の直接生成・コンテナ束縛経由の取得 (`cachePayloadIsStoreType()` の限界)
+ *   - **受け手名として解決できない変数**への添字代入 (`$c['k'] = $v` の `$c` が型宣言を持たない形)。
+ *     既存の受け手解決の限界と同じ
  *   - **docblock だけで型付けされた受け手** (`@var Repository $c` の docblock を書いた直後に
  *     `$c->put(...)` する形)。型宣言 (引数 / プロパティ / promoted ctor param) のみを見る。
  *     ※同じファイルに対応する型の `use` があれば **L3 (面) には現れる**が、
@@ -110,30 +132,55 @@
  */
 const CACHE_PAYLOAD_WRITE_METHODS = [
     'put', 'add', 'forever', 'remember', 'rememberforever', 'sear',
-    'flexible', 'putmany', 'set', 'setmultiple',
+    'flexible', 'putmany', 'set', 'setmultiple', 'rememberwithwarmth', 'offsetset',
 ];
 
 /**
  * payload を書き込まない API (increment/decrement は整数のみ書けるため素データが構造的に保証される)。
  *
+ * `hasmacro` は macro 登録簿の**読み出し**であり、登録も呼び出しもしない
+ * (登録側の `macro` / `mixin` / `flushmacros` は BYPASS)。
+ *
  * @var list<string>
  */
 const CACHE_PAYLOAD_NON_WRITE_METHODS = [
     'get', 'many', 'getmultiple', 'has', 'missing', 'pull', 'forget', 'delete',
     'deletemultiple', 'flush', 'clear', 'increment', 'decrement',
     'supportstags', 'getprefix', 'getdefaultdriver', 'setdefaultdriver',
-    'forgetdriver', 'purge', 'extend', 'itemkey', 'refresheventdispatcher',
+    'forgetdriver', 'purge', 'itemkey', 'refresheventdispatcher', 'hasmacro',
 ];
 
 /**
  * 受け手を保ったまま連鎖する API。
  *
- * `getStore()` は `Illuminate\Contracts\Cache\Store` を返し **put / forever を持つ**ので
- * NON_WRITE ではなく CHAIN (`Cache::getStore()->put(...)` の抜けを塞ぐ)。
+ * `getStore()` / `tags()` はここに**置かない** — どちらも受け皿 (Repository) を跨いで
+ * 保管先へ届くので BYPASS である (L4)。辿って書き込みを数えるのではなく、
+ * 書き方そのものを 0 件で pin する。
+ *
+ * @var list<string>
+ */
+const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'resolve', 'getfacaderoot'];
+
+/**
+ * 受け皿 (Repository) を跨いで保管先 (Store) へ届く / 受け皿の生成そのものに割り込む API。
+ * **通常経路は 0 件**で、実行時層の自己テストだけを名指しの目録へ exact-fit で登録する
+ * (家系の裁定 AG-151 の v2 要素 4「境界迂回の hard fail」)。
+ *
+ * - extend    独自 creator は CacheManager::repository() を通らないので実行時層の被覆から抜ける
+ *             (通らないことは tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が実証する)。
+ *             判定は**通常経路 0 件 + GuardedBoundaryProbe の自己テストの exact-fit**である
+ * - getStore / setStore  保管先を直接触る = 受け皿を跨ぐ。`getStore()` は vendor 自身が
+ *             正常系で呼ぶため**実行時には落とせない** = ここが唯一の防壁である
+ * - tags      vendor の tags() は new TaggedCache(...) を素で生成するので guard が効かない。
+ *             加えて本番の database store は supportsTags() が false でタグ非対応
+ * - macro / mixin / flushMacros  Repository は Macroable を use しており、
+ *             macro 内から $this->store へ直接到達できる (末端 4 メソッドを通らない)
  *
  * @var list<string>
  */
-const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'tags', 'resolve', 'getstore', 'getfacaderoot'];
+const CACHE_PAYLOAD_BYPASS_METHODS = [
+    'extend', 'getstore', 'setstore', 'tags', 'macro', 'mixin', 'flushmacros',
+];
 
 /**
  * 受け手がキャッシュでなくなる terminal (以降の連鎖を辿らない)。
@@ -149,6 +196,107 @@
     'expects', 'shouldhavereceived', 'shouldnothavereceived',
 ];
 
+/**
+ * L4: 境界迂回の**自己テスト**の目録 (exact-fit)。
+ *
+ * key   = `{相対パス}::{メソッド名 (全小文字)}` / `{相対パス}::new {完全修飾名}`
+ *         ★**完全修飾名で突き合わせる** (AGENTS.md 走査規約 (a))。短名では別名つき取り込みや
+ *           同名の別クラスを区別できない
+ * count = 出現回数 (完全一致。1 件増えたら必ず落ちる)
+ * rationale = 30 文字以上の具体的根拠
+ *
+ * ★登録できるのは **tests/Support/Cache/GuardedBoundaryProbe.php の 1 ファイルだけ**である
+ *   (検査 L4f が名指しで固定する)。「tests/Support/Cache/ 配下すべて」にはしない —
+ *   将来足した任意の補助ファイルが自己テストを名乗れてしまうため。
+ * ★**動的呼び出しで走査を避ける形は採らない** (検出力の裏取りが弱くなるため)。
+ * ★本目録に載せた呼び出しは**検査 1 (未分類 API の deny-by-default) の母集団からも除く**。
+ *   実行時層は保管先への素通し (`__call`) を落とすので、その自己テストは
+ *   「4 分類のどれでもない API 名」を意図的に呼ぶことになるためである。
+ *   目録に載っていない未知 API は従来どおり落ちる。
+ *
+ * @var array<string, array{count: int, rationale: string}>
+ */
+const CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY = [
+    'tests/Support/Cache/GuardedBoundaryProbe.php::extend' => [
+        'count' => 1,
+        'rationale' => '独自 driver の creator が CacheManager::repository() を通らないことを実証する trip-wire。通らなくなったら L4 の根拠が変わる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::flushmacros' => [
+        'count' => 1,
+        'rationale' => 'callMacro の finally で必ず登録を消すための 1 件。消さないと global afterEach の macro pin が二重に落ちる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::guardprobemacro' => [
+        'count' => 1,
+        'rationale' => '登録した macro を実際に呼ぶ 1 件。実行時層の __call() が macro を使用時点で落とすことの負例になる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::guardprobeunknownmethod' => [
+        'count' => 1,
+        'rationale' => 'macro でない未知メソッド (保管先への素通し) を呼ぶ 1 件。名指しで分類していない素通しが落ちることの負例になる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::macro' => [
+        'count' => 2,
+        'rationale' => 'macro 経由の到達が使用時点で落ちること (callMacro) と、残存 macro を flush が検出すること (registerMacroWithoutUsing) の 2 件',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::new Illuminate\Cache\ArrayStore' => [
+        'count' => 2,
+        'rationale' => 'setStore の引数と独自 creator の保管先として使う。保管先の直接生成が検出されることの自己確認も兼ねる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::new Illuminate\Cache\Repository' => [
+        'count' => 1,
+        'rationale' => '独自 creator が返す素の受け皿。guard を通らない受け皿が実際に作れてしまうことを実証するために必要な 1 件',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::setstore' => [
+        'count' => 1,
+        'rationale' => '受け皿の保管先を差し替える口が境界迂回として落ちることを固定する。落ちなくなると guard 付き受け皿の中身を入れ替えられる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::tags' => [
+        'count' => 1,
+        'rationale' => 'guard 付き受け皿の tags() が境界迂回として落ちることを固定する。落ちなくなると TaggedCache 経由の書き込みが素通りする',
+    ],
+];
+
+/** L4 の自己テストを置いてよい唯一のファイル (相対パス)。 */
+const CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE = 'tests/Support/Cache/GuardedBoundaryProbe.php';
+
+/**
+ * L4d: 受け手型 / 保管先型の**継承・実装の宣言**を許す名指しの目録 (exact-fit)。
+ *
+ * key = `{相対パス}::{extends|implements} {完全修飾名}`。
+ * 任意の Repository サブクラスを作れば `new` の検出を逃れられるので、**宣言側で塞ぐ**。
+ *
+ * @var array<string, string>
+ */
+const CACHE_PAYLOAD_SUBCLASS_INVENTORY = [
+    'tests/Support/Cache/PlainDataGuardedRepository.php::extends Illuminate\Cache\Repository' => '実行時層の受け皿そのもの。値の末端 4 メソッドを override するには継承以外の手段が無い',
+    'tests/Support/Cache/PlainDataGuardedCacheManager.php::extends Illuminate\Cache\CacheManager' => '実行時層の manager そのもの。repository() を override して guard 付き受け皿を返すために継承する',
+];
+
+/**
+ * 保管先 (Store) の型かどうかの判定規則。
+ *
+ * 解決した完全修飾名が
+ *   (a) `Illuminate\Contracts\Cache\Store` である、または
+ *   (b) `Illuminate\Cache\` で始まり `Store` で終わる (ArrayStore / DatabaseStore / FileStore /
+ *       RedisStore / NullStore / MemoizedStore / StorageStore / FailoverStore …)
+ * のとき保管先の型とみなす。
+ *
+ * ★**保証しないもの**: **走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に
+ *   一致しない第三者の Store 実装**の直接生成・解決は検出しない
+ *   (例: `new Vendor\Package\CacheBackend()` が vendor 内で Store を実装している形)。
+ *   `Cache::extend()` の pin は **CacheManager 経由で第三者 Store の面を増やす経路**を閉じるが、
+ *   **走査根の外の第三者 Store を直接生成する / 独自のコンテナ束縛で取得する経路までは
+ *   保証しない** (「唯一の登録口」とは書かない)。
+ *   規則そのものの正負は検査 L4e が固定する。
+ */
+function cachePayloadIsStoreType(string $fqcn): bool
+{
+    if ($fqcn === 'Illuminate\Contracts\Cache\Store') {
+        return true;
+    }
+
+    return str_starts_with($fqcn, 'Illuminate\Cache\\') && str_ends_with($fqcn, 'Store');
+}
+
 /**
  * L2: キャッシュ **書き込み経路**の目録 (deny-by-default / exact-fit)。
  *
@@ -159,18 +307,114 @@
  * proof   = 往復を固定している単体テストのパス (**実在を検査する**)
  * rationale = 30 文字以上の具体的根拠
  *
+ * kind  = 'plain'          …素データを入れる本来の経路。proof は**配列往復を固定する単体テスト**
+ *         'guard-selftest' …実行時層が違反を検出することを固定するための意図的な違反。
+ *                            proof は**その検出を固定する振る舞い検査**
+ *
  * 経路が 1 本しかない現状では専用 enum (app/Enums/Security/) + inventory クラス
  * (tests/Support/Security/) へ昇格させない (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
  *
- * @var array<string, array{count: int, payload: string, proof: string, rationale: string}>
+ * @var array<string, array{kind: string, count: int, payload: string, proof: string, rationale: string}>
  */
 const CACHE_PAYLOAD_WRITE_INVENTORY = [
     'app/Services/FxRateService.php::put' => [
+        'kind' => 'plain',
         'count' => 1,
         'payload' => 'FxSnapshotDto::toArray() の連想配列 (float 1 / string 3)。オブジェクトは渡さない',
         'proof' => 'tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php',
         'rationale' => '当日の USD/JPY レートを 1 日 cache する。読み戻しは is_array 検査 + FxSnapshotDto::fromArray() + 失敗時 Cache::forget() で標準形どおり',
     ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::add' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass) と素データの両方。add() が末端として検査されることを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '値の末端 4 メソッドのうち add が保管前に検査されることを実 API 経由で固定する。ここが無いと申告の裏取りが機械化されない',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::flexible' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。flexible が putMany へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::forever' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass) と素データの両方。forever が末端として検査されることを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '値の末端 4 メソッドのうち forever が保管前に検査されることを実 API 経由で固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::offsetset' => [
+        'kind' => 'guard-selftest',
+        'count' => 2,
+        'payload' => '意図的な違反値 (stdClass) と素データの両方。$cache[$k] = $v と $cache[$k] ??= $v の 2 形',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => 'ArrayAccess 書き込みが put へ合流することを実 API 経由で固定する 2 件。静的層の添字代入検出とも対応する',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::put' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass / Closure 等) と素データの両方。guard が前者だけを落とすことを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '実行時層が「保管前の値を再帰検査して落とす」ことを実 API 経由で固定する唯一の場所。ここが無いと申告の裏取りが機械化されない',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::putmany' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass) を含む連想配列。putMany が末端として検査されることを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '値の末端 4 メソッドのうち putMany が保管前に検査されることを実 API 経由で固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::remember' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。remember が rememberWithWarmth 経由で put へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::rememberforever' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。rememberForever が forever へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::rememberwithwarmth' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。rememberWithWarmth が put へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::sear' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。sear が rememberForever 経由で forever へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::set' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。PSR-16 の set が put へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::setmultiple' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。PSR-16 の setMultiple が putMany へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php::put' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '起動中に意図的に入れるオブジェクト (stdClass)。provider 自身が例外を握り潰す',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '起動 (bootstrap) 中の書き込みも guard が捕まえることを固定するための見本。結線点が beforeEach へ後退したら赤くなる',
+    ],
 ];
 
 /**
@@ -183,7 +427,11 @@
  *       no-payload-write = キャッシュに触れるが任意 payload を書く API を呼ばない (読み出し / 削除 / flush 等) /
  *       lock-only = 排他だけ /
  *       driver-handoff = 受け手 (driver/store) を解決するだけで、読み出し・書き込み・削除の
- *       いずれも行わず他のコンポーネントへそのまま渡す (T215: キューワーカーへの cache 注入が該当)
+ *       いずれも行わず他のコンポーネントへそのまま渡す (T215: キューワーカーへの cache 注入が該当) /
+ *       guard-implementation = 実行時層の実装そのもの。受け手型を**参照するだけ**で
+ *       キャッシュ API は 1 件も呼ばない (tests/Support/Cache/ 配下でだけ名乗れる) /
+ *       boundary-selftest = 境界迂回が hard fail することを固定する唯一の呼び出し元
+ *       (CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE ちょうどでだけ名乗れる)
  * ※「read-only」ではなく no-payload-write と呼ぶ。forget / flush を含む実態と名前を一致させるため
  *
  * @var array<string, array{role: string, rationale: string}>
@@ -217,10 +465,34 @@
         'role' => 'lock-only',
         'rationale' => '突き合わせコマンドの多重起動を再現するため Cache::lock を先取するのみ。payload は書かない',
     ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php' => [
+        'role' => 'write',
+        'rationale' => '実行時層の振る舞い検査。意図的に違反する値を書いて guard が落とすことを固定する唯一のファイル',
+    ],
     'tests/Feature/Queue/DeferredRetryHorizonTest.php' => [
         'role' => 'driver-handoff',
         'rationale' => 'Worker::setCache() へ渡すため app(\'cache\')->driver() で driver を解決するだけで、読み出し・書き込み・削除のいずれも行わない。未処理例外の計数は framework 側が整数で行う',
     ],
+    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php' => [
+        'role' => 'write',
+        'rationale' => '起動中の書き込みを guard が捕まえることを固定する見本 provider。boot() で意図的にオブジェクトを入れる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php' => [
+        'role' => 'boundary-selftest',
+        'rationale' => '境界迂回が hard fail することを固定する唯一の呼び出し元。L4 の自己テスト目録に登録できるのはこのファイルだけ',
+    ],
+    'tests/Support/Cache/PlainDataCacheGuard.php' => [
+        'role' => 'guard-implementation',
+        'rationale' => '実行時層の結線と accumulator。Repository::$macros の pin のために Repository を参照するだけで API は呼ばない',
+    ],
+    'tests/Support/Cache/PlainDataGuardedCacheManager.php' => [
+        'role' => 'guard-implementation',
+        'rationale' => '実行時層の manager。Store 型を参照してよい唯一のサイトで、repository() を override して受け皿を差し替える',
+    ],
+    'tests/Support/Cache/PlainDataGuardedRepository.php' => [
+        'role' => 'guard-implementation',
+        'rationale' => '実行時層の受け皿。Illuminate\Cache\Repository を継承して末端 4 メソッドを検査する。キャッシュ API 呼び出しは持たない',
+    ],
 ];
 
 /**
@@ -315,6 +587,80 @@ function cachePayloadMatchingParen(array $tokens, int $open): ?int
     return null;
 }
 
+/**
+ * `[` の対応する `]` の index。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function cachePayloadMatchingBracket(array $tokens, int $open): ?int
+{
+    $depth = 0;
+    $count = count($tokens);
+    for ($i = $open; $i < $count; $i++) {
+        if ($tokens[$i]->text === '[') {
+            $depth++;
+        } elseif ($tokens[$i]->text === ']') {
+            $depth--;
+            if ($depth === 0) {
+                return $i;
+            }
+        }
+    }
+
+    return null;
+}
+
+/**
+ * `extends A` / `implements A, B` の宣言句を読み、カンマ区切りの各名前を解決して返す。
+ *
+ * ★直前 token だけを見る形では不十分 — `class X implements SomeInterface, Store {}` の
+ *   `Store` の直前は `,` である。そこで T_EXTENDS / T_IMPLEMENTS を見つけたら
+ *   **宣言句全体 (`{` まで)** を読む。**解決できない名前は候補から外さず `null` で返す**
+ *   (未解決を落とす = AGENTS.md 走査規約 (b))。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @param  array<string, string>  $useMap
+ * @return list<array{keyword: string, resolved: string|null, line: int}>
+ */
+function cachePayloadInheritanceClause(array $tokens, int $keywordIndex, array $useMap): array
+{
+    $keyword = strtolower($tokens[$keywordIndex]->text);
+    $declared = [];
+    $count = count($tokens);
+
+    for ($i = $keywordIndex + 1; $i < $count; $i++) {
+        $token = $tokens[$i];
+        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+            continue;
+        }
+        if ($token->text === '{' || $token->text === ';') {
+            break;
+        }
+        if ($token->text === ',') {
+            continue;
+        }
+        if ($token->is(T_IMPLEMENTS)) {
+            // `class X extends A implements B` の切り替え。implements 側は
+            // T_IMPLEMENTS を起点とする別の呼び出しが読むので、ここでは打ち切る (二重記録の防止)。
+            break;
+        }
+        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+            $declared[] = [
+                'keyword' => $keyword,
+                'resolved' => cachePayloadResolveName($token->text, $useMap),
+                'line' => $token->line,
+            ];
+
+            continue;
+        }
+
+        // 予期しない token (可変長の型構文など)。解決できない形として落とす。
+        $declared[] = ['keyword' => $keyword, 'resolved' => null, 'line' => $token->line];
+    }
+
+    return $declared;
+}
+
 /**
  * `use A\B\C;` / `use A\B\C as D;` から alias => FQCN の表を作る。
  * グループ use (`use A\{B, C};`) は本リポジトリに存在しないため扱わない (限界として冒頭に明記)。
@@ -449,7 +795,7 @@ function cachePayloadLiteralValue(string $raw): ?string
  *     素通りさせる理由が無いので `unclassified` として fail させる (実測 0 件)
  *
  * @param  list<PhpToken>  $tokens
- * @return list<array{method: string, line: int, kind: string}> kind: write|non_write|chain|terminal|unclassified
+ * @return list<array{method: string, line: int, kind: string}> kind: write|non_write|chain|terminal|bypass|unclassified
  */
 function cachePayloadFollowChain(array $tokens, int $operatorIndex): array
 {
@@ -506,6 +852,7 @@ function cachePayloadFollowChain(array $tokens, int $operatorIndex): array
             in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) => 'write',
             in_array($method, CACHE_PAYLOAD_NON_WRITE_METHODS, true) => 'non_write',
             in_array($method, CACHE_PAYLOAD_CHAIN_METHODS, true) => 'chain',
+            in_array($method, CACHE_PAYLOAD_BYPASS_METHODS, true) => 'bypass',
             in_array($method, CACHE_PAYLOAD_TERMINAL_METHODS, true) => 'terminal',
             default => 'unclassified',
         };
@@ -606,7 +953,7 @@ function cachePayloadContainerMakeChain(array $tokens, int $closeIndex, array $u
  * `writes` は **構造体**で返す (文字列に畳んでから再パースすると `strrchr` 等で壊れるため)。
  * ヘルパの配列形 `cache([...], $ttl)` は method 名 `cache` として記録する。
  *
- * @return array{writes: list<array{relative: string, line: int, method: string}>, unclassified: list<string>, methods: list<string>, cacheCalls: int, methodCalls: int, surface: bool}
+ * @return array{writes: list<array{relative: string, line: int, method: string}>, unclassified: list<string>, methods: list<string>, bypasses: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, cacheCalls: int, methodCalls: int, surface: bool}
  */
 function cachePayloadCollectFromSource(string $source, string $relative): array
 {
@@ -618,11 +965,23 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
     $writes = [];
     $unclassified = [];
     $methods = [];
+    /** @var list<string> $bypasses */
+    $bypasses = [];
+    /** @var array<string, int> $bypassCounts */
+    $bypassCounts = [];
+    /** @var list<string> $subclassDeclarations */
+    $subclassDeclarations = [];
     $cacheCalls = 0;
     $methodCalls = 0;
     $surface = false;
     $count = count($tokens);
 
+    /** 迂回 1 件を記録する (目録の key は解決済みの完全修飾名で作る)。 */
+    $recordBypass = function (string $key, string $site) use (&$bypasses, &$bypassCounts): void {
+        $bypasses[] = $site;
+        $bypassCounts[$key] = ($bypassCounts[$key] ?? 0) + 1;
+    };
+
     for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
 
@@ -636,6 +995,21 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
             }
         }
 
+        // L4d: 受け手型 / 保管先型の継承・実装の宣言 (宣言側で塞ぐ)。
+        if ($token->is([T_EXTENDS, T_IMPLEMENTS])) {
+            foreach (cachePayloadInheritanceClause($tokens, $i, $useMap) as $declared) {
+                if ($declared['resolved'] === null) {
+                    $unclassified[] = "{$relative}:{$declared['line']} → extends/implements <解決できない名前>";
+
+                    continue;
+                }
+                if (in_array($declared['resolved'], CACHE_PAYLOAD_RECEIVER_TYPES, true)
+                    || cachePayloadIsStoreType($declared['resolved'])) {
+                    $subclassDeclarations[] = "{$relative}::{$declared['keyword']} {$declared['resolved']}";
+                }
+            }
+        }
+
         $operatorIndex = null;
 
         if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
@@ -651,7 +1025,9 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
             $isRootCallable = ! str_contains($callable, '\\');
             $lower = $isRootCallable ? $callable : '';
 
-            if (! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
+            $isReceiverType = ! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true);
+
+            if ($isReceiverType) {
                 $surface = true; // use 文・型宣言・::class 参照でも「面」としては hit する
                 $next = cachePayloadNext($tokens, $i + 1);
                 if ($next !== null && $tokens[$next]->is(T_DOUBLE_COLON)) {
@@ -661,6 +1037,17 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
                 }
             }
 
+            // L4b: 受け手型 / 保管先型の**直接生成**。受け皿を自前で作られると
+            //      guard 付き manager を通らない受け皿が生まれる。
+            if (! $isMemberName
+                && ($isReceiverType || cachePayloadIsStoreType($resolved))
+                && $prev !== null && $tokens[$prev]->is(T_NEW)) {
+                $recordBypass(
+                    "{$relative}::new {$resolved}",
+                    "{$relative}:{$token->line} → new {$resolved}",
+                );
+            }
+
             if (! $isMemberName && $lower === 'cache') {
                 $open = cachePayloadNext($tokens, $i + 1);
                 if ($open !== null && $tokens[$open]->text === '(') {
@@ -734,6 +1121,20 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
                 if ($arrow !== null && $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                     $operatorIndex = $arrow; // $cache->put(...)
                     $surface = true;
+                } elseif ($arrow !== null && $tokens[$arrow]->text === '[') {
+                    // ArrayAccess 書き込み (`$cache['k'] = $v` / `$cache['k'] ??= $v`)。
+                    // メソッド呼び出し走査では検出できないので専用の分岐を持つ。
+                    $closeBracket = cachePayloadMatchingBracket($tokens, $arrow);
+                    $assign = $closeBracket === null ? null : cachePayloadNext($tokens, $closeBracket + 1);
+                    if ($assign !== null && in_array($tokens[$assign]->text, ['=', '??='], true)) {
+                        $surface = true;
+                        $cacheCalls++;
+                        $writes[] = ['relative' => $relative, 'line' => $token->line, 'method' => 'offsetSet'];
+                        $methods[] = 'offsetset';
+                    } elseif ($closeBracket === null) {
+                        // ★対応する `]` を見つけられない = 解決できない形。見逃さずに落とす。
+                        $unclassified[] = "{$relative}:{$token->line} → \${$name}[…] (対応する ] を解決できない)";
+                    }
                 }
             }
         }
@@ -745,18 +1146,36 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
         foreach (cachePayloadFollowChain($tokens, $operatorIndex) as $call) {
             $cacheCalls++;
             $methods[] = $call['method'];
+            $key = $relative.'::'.strtolower($call['method']);
+
             if ($call['kind'] === 'write') {
                 $writes[] = ['relative' => $relative, 'line' => $call['line'], 'method' => $call['method']];
+            } elseif ($call['kind'] === 'bypass') {
+                $recordBypass($key, "{$relative}:{$call['line']} → ->{$call['method']}()");
             } elseif ($call['kind'] === 'unclassified') {
-                $unclassified[] = "{$relative}:{$call['line']} → ->{$call['method']}()";
+                // ★実行時層は保管先への素通し (__call) を落とすため、その自己テストは
+                //   「4 分類のどれでもない API 名」を意図的に呼ぶ。自己テスト目録に
+                //   登録済みの呼び出しだけを迂回として数え、それ以外は従来どおり落とす。
+                if (array_key_exists($key, CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY)) {
+                    $recordBypass($key, "{$relative}:{$call['line']} → ->{$call['method']}()");
+                } else {
+                    $unclassified[] = "{$relative}:{$call['line']} → ->{$call['method']}()";
+                }
             }
         }
     }
 
+    sort($bypasses);
+    ksort($bypassCounts);
+    sort($subclassDeclarations);
+
     return [
         'writes' => $writes,
         'unclassified' => $unclassified,
         'methods' => $methods,
+        'bypasses' => $bypasses,
+        'bypassCounts' => $bypassCounts,
+        'subclassDeclarations' => $subclassDeclarations,
         'cacheCalls' => $cacheCalls,
         'methodCalls' => $methodCalls,
         'surface' => $surface,
@@ -777,18 +1196,59 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
  *                      T215: `Worker::setCache()` へ渡すためだけに `app('cache')->driver()` を呼ぶ形が該当)
  *
  * @param  list<string>  $methods  実測メソッド (全小文字)
+ * @param  string  $path  宣言されたファイル (役割を任意のファイルが名乗れないようにするため)
  * @return list<string> 違反理由。空なら整合
  */
-function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry): array
+function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry, string $path = ''): array
 {
-    if (! in_array($role, ['write', 'no-payload-write', 'lock-only', 'driver-handoff'], true)) {
-        return ["role は write / no-payload-write / lock-only / driver-handoff のいずれか (宣言値: {$role})"];
+    $known = ['write', 'no-payload-write', 'lock-only', 'driver-handoff', 'guard-implementation', 'boundary-selftest'];
+    if (! in_array($role, $known, true)) {
+        return ['role は '.implode(' / ', $known)." のいずれか (宣言値: {$role})"];
     }
 
     if ($role === 'write') {
         return $hasWriteEntry ? [] : ['role=write なのに書き込み目録に entry がありません'];
     }
 
+    if ($role === 'guard-implementation') {
+        // 実行時層の実装そのもの。受け手型を**参照するだけ**で API は呼ばない、という申告である。
+        $violations = [];
+        if ($hasWriteEntry) {
+            $violations[] = 'role=guard-implementation なのに書き込み目録に entry があります';
+        }
+        if ($methods !== []) {
+            $violations[] = 'role=guard-implementation なのにキャッシュ API を呼んでいます: '.implode(', ', $methods);
+        }
+        if (! str_starts_with($path, 'tests/Support/Cache/')) {
+            $violations[] = 'role=guard-implementation は tests/Support/Cache/ 配下でだけ名乗れます: '.$path;
+        }
+
+        return $violations;
+    }
+
+    if ($role === 'boundary-selftest') {
+        // 境界迂回が hard fail することを固定する唯一の呼び出し元。
+        $violations = [];
+        if ($hasWriteEntry) {
+            $violations[] = 'role=boundary-selftest なのに書き込み目録に entry があります (payload は書かない)';
+        }
+        if ($path !== CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE) {
+            $violations[] = 'role=boundary-selftest を名乗れるのは '.CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE." だけです: {$path}";
+        }
+        $registered = false;
+        foreach (array_keys(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY) as $key) {
+            if (str_starts_with($key, $path.'::')) {
+                $registered = true;
+                break;
+            }
+        }
+        if (! $registered) {
+            $violations[] = 'role=boundary-selftest なのに L4 の自己テスト目録に entry がありません';
+        }
+
+        return $violations;
+    }
+
     $violations = [];
     if ($hasWriteEntry) {
         $violations[] = "role={$role} なのに書き込み目録に entry があります";
@@ -838,11 +1298,11 @@ function cachePayloadRoleViolations(string $role, array $methods, bool $hasWrite
 /**
  * 走査対象全体の収集結果 (同一プロセス内で 1 度だけ計算する)。
  *
- * @return array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}
+ * @return array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, bypassSites: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, cacheCalls: int, methodCalls: int, files: int}
  */
 function cachePayloadCollectAll(): array
 {
-    /** @var array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}|null $cached */
+    /** @var array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, bypassSites: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, cacheCalls: int, methodCalls: int, files: int}|null $cached */
     static $cached = null;
     if ($cached !== null) {
         return $cached;
@@ -852,6 +1312,12 @@ function cachePayloadCollectAll(): array
     $writeSites = [];
     $unclassified = [];
     $surfaces = [];
+    /** @var list<string> $bypassSites */
+    $bypassSites = [];
+    /** @var array<string, int> $bypassCounts */
+    $bypassCounts = [];
+    /** @var list<string> $subclassDeclarations */
+    $subclassDeclarations = [];
     $cacheCalls = 0;
     $methodCalls = 0;
     $files = 0;
@@ -872,6 +1338,11 @@ function cachePayloadCollectAll(): array
             $writeCounts[$key] = ($writeCounts[$key] ?? 0) + 1;
         }
         $unclassified = array_merge($unclassified, $collected['unclassified']);
+        $bypassSites = array_merge($bypassSites, $collected['bypasses']);
+        $subclassDeclarations = array_merge($subclassDeclarations, $collected['subclassDeclarations']);
+        foreach ($collected['bypassCounts'] as $key => $bypassCount) {
+            $bypassCounts[$key] = ($bypassCounts[$key] ?? 0) + $bypassCount;
+        }
 
         if ($collected['surface']) {
             $surfaces[$target['relative']] = $collected['methods'];
@@ -880,13 +1351,19 @@ function cachePayloadCollectAll(): array
 
     ksort($writeCounts);
     ksort($surfaces);
+    ksort($bypassCounts);
     sort($writeSites);
+    sort($bypassSites);
+    sort($subclassDeclarations);
 
     $cached = [
         'writeCounts' => $writeCounts,
         'writeSites' => $writeSites,
         'unclassified' => $unclassified,
         'surfaces' => $surfaces,
+        'bypassSites' => $bypassSites,
+        'bypassCounts' => $bypassCounts,
+        'subclassDeclarations' => $subclassDeclarations,
         'cacheCalls' => $cacheCalls,
         'methodCalls' => $methodCalls,
         'files' => $files,
@@ -940,10 +1417,13 @@ function cachePayloadCollectAll(): array
         // key のメソッド名は全小文字。'cache' はヘルパの配列形 cache([...], $ttl) 専用の名前。
         expect(in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) || $method === 'cache')
             ->toBeTrue("{$key}: key のメソッドが WRITE 語彙にありません");
+        expect(in_array($entry['kind'], ['plain', 'guard-selftest'], true))
+            ->toBeTrue("{$key}: kind は plain / guard-selftest のいずれか (宣言値: {$entry['kind']})");
         expect(is_file(base_path($path)))->toBeTrue("{$key}: 対象ファイルが実在しません");
         expect(is_file(base_path($entry['proof'])))->toBeTrue(
-            "{$key}: proof に指定した単体テスト {$entry['proof']} が実在しません。"
-            .'キャッシュへ入れる配列は「往復が壊れないこと」を単体テストで固定してください');
+            "{$key}: proof に指定した検査 {$entry['proof']} が実在しません。"
+            .'kind=plain はキャッシュへ入れる配列の「往復が壊れないこと」を単体テストで、'
+            .'kind=guard-selftest は「実行時層が落とすこと」を振る舞い検査で固定してください');
         expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
         expect(mb_strlen($entry['payload']))->toBeGreaterThanOrEqual(10, "{$key}: payload の説明が短すぎます");
     }
@@ -984,7 +1464,7 @@ function cachePayloadCollectAll(): array
             }
         }
 
-        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite))
+        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite, $path))
             ->toBe([], "{$path}: 宣言した role が実測と整合しません");
     }
 });
@@ -1021,6 +1501,25 @@ function cachePayloadCollectAll(): array
     expect(cachePayloadRoleViolations('driver-handoff', ['driver'], true))->not->toBe([]);
 
     expect(cachePayloadRoleViolations('unknown-role', ['get'], false))->not->toBe([]);
+
+    // guard-implementation (T228): 受け手型を参照するだけ。API を 1 件でも呼んだら違反、
+    // 許可パス外で名乗っても違反 (任意のファイルが迂回実装の免除に使えないようにする)。
+    $guardPath = 'tests/Support/Cache/PlainDataGuardedRepository.php';
+    expect(cachePayloadRoleViolations('guard-implementation', [], false, $guardPath))->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', ['put'], false, $guardPath))->not->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', ['get'], false, $guardPath))->not->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', [], true, $guardPath))->not->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', [], false, 'app/Services/FxRateService.php'))
+        ->not->toBe([]);
+
+    // boundary-selftest (T228): 名指しの 1 ファイルだけが名乗れ、L4 の自己テスト目録に
+    // entry を持ち、L2 の書き込み目録には entry を持たない。
+    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], false, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE))
+        ->toBe([]);
+    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], true, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE))
+        ->not->toBe([]);
+    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], false, 'tests/Support/Cache/OtherProbe.php'))
+        ->not->toBe([]);
 });
 
 // ---------------------------------------------------------------------------
@@ -1040,13 +1539,14 @@ function cachePayloadCollectAll(): array
     }
 });
 
-test('検査 6b: 語彙表が健全 (4 分類は互いに素 / 除外型は受け手型と重ならない)', function (): void {
+test('検査 6b: 語彙表が健全 (5 分類は互いに素 / 除外型は受け手型と重ならない)', function (): void {
     // ★同じメソッドが 2 つの分類に入ると match の順序で暗黙に勝敗が決まり、
     //   「WRITE のつもりが NON_WRITE として素通り」が静かに起きる。互いに素であることを固定する。
     $groups = [
         'WRITE' => CACHE_PAYLOAD_WRITE_METHODS,
         'NON_WRITE' => CACHE_PAYLOAD_NON_WRITE_METHODS,
         'CHAIN' => CACHE_PAYLOAD_CHAIN_METHODS,
+        'BYPASS' => CACHE_PAYLOAD_BYPASS_METHODS,
         'TERMINAL' => CACHE_PAYLOAD_TERMINAL_METHODS,
     ];
     $all = array_merge(...array_values($groups));
@@ -1064,6 +1564,118 @@ function cachePayloadCollectAll(): array
     }
 });
 
+// ---------------------------------------------------------------------------
+// 検査 L4: 境界迂回の hard fail (正典 v2 の要素 4)
+// ---------------------------------------------------------------------------
+
+test('検査 L4a: 受け皿の境界を迂回する API 呼び出しと直接生成が自己テスト目録と exact-fit で一致する', function (): void {
+    $result = cachePayloadCollectAll();
+
+    $declared = [];
+    foreach (CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY as $key => $entry) {
+        $declared[$key] = $entry['count'];
+    }
+    ksort($declared);
+
+    expect($result['bypassCounts'])->toBe($declared,
+        '受け皿 (Illuminate\Cache\Repository) を跨いで保管先へ届く / 受け皿の生成に割り込む書き方は'
+        .'**通常経路 0 件**です (家系の裁定 AG-151 の境界迂回の hard fail)。'
+        .'Cache::extend / getStore / setStore / tags / macro / mixin / flushMacros / '
+        .'受け手型・保管先型の直接生成は、実行時層が値を見られない経路を作ります。'
+        .'実行時層の自己テストだけが CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY へ登録できます。'
+        .PHP_EOL.'検出: '.implode(PHP_EOL, $result['bypassSites']));
+});
+
+test('検査 L4b: 自己テスト目録の各 entry が形式要件を満たし実測で非空である', function (): void {
+    expect(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY)->not->toBe([]);
+    $result = cachePayloadCollectAll();
+
+    foreach (CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY as $key => $entry) {
+        expect($entry['count'])->toBeGreaterThanOrEqual(1, "{$key}: count は 1 以上");
+        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
+        expect($result['bypassCounts'][$key] ?? 0)->toBe($entry['count'],
+            "{$key}: 目録の件数と実測が一致しません (実在しない登録も、件数のズレも落とす)");
+    }
+});
+
+test('検査 L4c: guard 付き manager は $store を受け皿の第 1 引数以外へ流さない', function (): void {
+    // ★保管先を外へ流出させると、受け皿を迂回して書ける経路が生まれる。
+    //   `$store` の出現は (1) 型宣言の直後 (2) new PlainDataGuardedRepository( の第 1 引数
+    //   の 2 か所ちょうどでなければならない。
+    $relative = 'tests/Support/Cache/PlainDataGuardedCacheManager.php';
+    $source = file_get_contents(base_path($relative));
+    expect($source)->toBeString();
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize((string) $source);
+
+    $occurrences = [];
+    $count = count($tokens);
+    for ($i = 0; $i < $count; $i++) {
+        if (! $tokens[$i]->is(T_VARIABLE) || $tokens[$i]->text !== '$store') {
+            continue;
+        }
+        $prev = cachePayloadPrev($tokens, $i - 1);
+        $prevText = $prev === null ? '' : $tokens[$prev]->text;
+        $occurrences[] = $prevText;
+    }
+
+    expect($occurrences)->toBe(['Store', '('],
+        '$store は (1) `Store $store` の型宣言 (2) `new PlainDataGuardedRepository($store, …)` の'
+        .'第 1 引数 の 2 か所ちょうどでなければなりません。検出: '.implode(' / ', $occurrences));
+});
+
+test('検査 L4d: 受け手型 / 保管先型の継承・実装が名指しの目録と exact-fit で一致する', function (): void {
+    $result = cachePayloadCollectAll();
+
+    $declared = array_keys(CACHE_PAYLOAD_SUBCLASS_INVENTORY);
+    sort($declared);
+
+    expect($result['subclassDeclarations'])->toBe($declared,
+        '受け手型 / 保管先型を継承・実装すると `new` の検出を逃れて受け皿を自作できます。'
+        .'宣言側で塞ぐため CACHE_PAYLOAD_SUBCLASS_INVENTORY と exact-fit で一致させてください。');
+
+    foreach (CACHE_PAYLOAD_SUBCLASS_INVENTORY as $key => $rationale) {
+        expect(mb_strlen($rationale))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
+        expect(is_file(base_path(explode('::', $key, 2)[0])))->toBeTrue("{$key}: 対象ファイルが実在しません");
+    }
+});
+
+test('検査 L4e: 保管先型の判定規則の正負コントロール', function (): void {
+    expect(cachePayloadIsStoreType('Illuminate\Contracts\Cache\Store'))->toBeTrue();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\ArrayStore'))->toBeTrue();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\DatabaseStore'))->toBeTrue();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\MemoizedStore'))->toBeTrue();
+
+    expect(cachePayloadIsStoreType('Illuminate\Cache\Repository'))->toBeFalse();
+    expect(cachePayloadIsStoreType('App\Support\Storage\ObjectStore'))->toBeFalse();
+    expect(cachePayloadIsStoreType('Illuminate\Session\Store'))->toBeFalse();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\StoreFactory'))->toBeFalse();
+});
+
+test('検査 L4f: 自己テスト目録の key は GuardedBoundaryProbe.php ちょうどにしか無い', function (): void {
+    // ★「tests/Support/Cache/ 配下すべて」にはしない — 将来足した任意の補助ファイルが
+    //   自己テストを名乗れてしまうため。
+    expect(is_file(base_path(CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE)))->toBeTrue();
+
+    foreach (array_keys(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY) as $key) {
+        expect(explode('::', $key, 2)[0])->toBe(CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE,
+            "{$key}: 自己テスト目録に登録できるのは ".CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE.' だけです');
+    }
+});
+
+test('検査 L4g: 実行時層の素通し許可が静的層の排他語彙と一致する', function (): void {
+    // ★実行時層は `Repository::__call()` の素通しのうち排他 2 語彙だけを通す。
+    //   その許可を静的層の TERMINAL 語彙 (lock / restoreLock) と 1 対 1 に固定し、
+    //   2 か所で別々に育てられないようにする。
+    expect(PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS)->toBe(['lock', 'restorelock']);
+    expect(array_values(array_intersect(
+        CACHE_PAYLOAD_TERMINAL_METHODS,
+        PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS
+    )))->toBe(PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS,
+        '実行時層が素通しを許した語彙は、静的層が TERMINAL (payload を運ばない) と分類した語彙の'
+        .'部分集合でなければなりません');
+});
+
 // ---------------------------------------------------------------------------
 // 検査 7-8: 空振り検知と自己参照コントロール
 // ---------------------------------------------------------------------------
@@ -1075,6 +1687,24 @@ function cachePayloadCollectAll(): array
     expect($result['methodCalls'])->toBeGreaterThan(0, 'メソッド呼び出しを 1 件も見ていない (token 走査が死んでいる)');
     expect($result['cacheCalls'])->toBeGreaterThan(0, 'キャッシュ受け手を 1 件も解決できていない (受け手解決が死んでいる)');
     expect($result['surfaces'])->not->toBe([], 'キャッシュに触れるファイルを 1 件も検出できていない');
+    expect($result['bypassSites'])->not->toBe([], '境界迂回の検出器が 1 件も反応していない (L4 の走査が死んでいる)');
+    expect($result['subclassDeclarations'])->not->toBe([], '継承・実装の検出器が 1 件も反応していない (L4d の走査が死んでいる)');
+
+    // 検出器そのものが負例で反応することを合成入力で確かめる (実在ファイルの構成に依存させない)。
+    $probe = cachePayloadCollectFromSource(<<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Support\Facades\Cache;
+    use Illuminate\Contracts\Cache\Repository;
+    class Fixture {
+        public function run(Repository $cache, $obj): void {
+            Cache::getStore()->put('a', [1], 60);
+            $cache['k'] = $obj;
+        }
+    }
+    PHP, 'probe.php');
+    expect($probe['bypassCounts'])->toBe(['probe.php::getstore' => 1]);
+    expect($probe['writes'])->toHaveCount(1);
 });
 
 test('検査 8: 自己参照コントロール (本 gate 自身は書き込み経路にも面にも現れない)', function (): void {
@@ -1085,6 +1715,8 @@ function cachePayloadCollectAll(): array
     // 将来ここに code としてキャッシュ呼び出しを書いたら落ちる = 正しい挙動。
     expect(array_key_exists($self, $result['surfaces']))->toBeFalse();
     expect(array_filter($result['writeSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
+    expect(array_filter($result['bypassSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
+    expect(array_filter($result['subclassDeclarations'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
 });
 
 // ---------------------------------------------------------------------------
@@ -1115,7 +1747,10 @@ public function run(Repository $other, $dto): void {
     PHP;
 
     $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
-    expect($result['writes'])->toHaveCount(10);
+    // ★`Cache::tags(['t'])->forever(...)` は L4 で**迂回**になったので書き込みには数えない
+    //   (辿って数えるのではなく、書き方そのものを 0 件で pin する側へ移した)。
+    expect($result['writes'])->toHaveCount(9);
+    expect($result['bypassCounts'])->toBe(['fixture.php::tags' => 1]);
     expect($result['unclassified'])->toBe([]);
     expect($result['surface'])->toBeTrue();
 });
@@ -1138,7 +1773,10 @@ public function run(): void {
     PHP;
 
     $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
-    expect($result['writes'])->toHaveCount(5);
+    // ★`Cache::getStore()->put(...)` は L4 で**迂回**になった。書き込み検出は消えるが
+    //   保護は弱くならない (迂回として 0 件 pin されるため)。
+    expect($result['writes'])->toHaveCount(4);
+    expect($result['bypassCounts'])->toBe(['fixture.php::getstore' => 1]);
     expect($result['unclassified'])->toBe([]);
     expect($result['surface'])->toBeTrue();
 });
@@ -1211,6 +1849,8 @@ public function run(array $values, $store): void {
     $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
     expect($result['writes'])->toBe([]);
     expect($result['unclassified'])->toHaveCount(1); // cache($values, 60) だけ
+    // 受け手型の直接生成そのものは L4b の迂回として検出される
+    expect($result['bypassCounts'])->toBe(['fixture.php::new Illuminate\Cache\Repository' => 1]);
 });
 
 test('負のコントロール: app()->make(...) 経由のコンテナ解決も検出する', function (): void {
@@ -1433,6 +2073,169 @@ public function run(): void {
     expect($result['writes'])->toBe([]);
 });
 
+test('負のコントロール: 境界迂回の 7 語彙をすべて検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Support\Facades\Cache;
+    use Illuminate\Cache\Repository;
+    use Illuminate\Cache\CacheManager;
+    class Fixture {
+        public function run(Repository $cache, CacheManager $manager): void {
+            Cache::extend('x', fn () => null);
+            $cache->getStore();
+            $cache->setStore(null);
+            $cache->tags(['t']);
+            $manager->macro('m', fn () => null);
+            $manager->mixin(null);
+            $manager->flushMacros();
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['bypassCounts'])->toBe([
+        'fixture.php::extend' => 1,
+        'fixture.php::flushmacros' => 1,
+        'fixture.php::getstore' => 1,
+        'fixture.php::macro' => 1,
+        'fixture.php::mixin' => 1,
+        'fixture.php::setstore' => 1,
+        'fixture.php::tags' => 1,
+    ]);
+    expect($result['writes'])->toBe([]);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('負のコントロール: 受け手型 / 保管先型の直接生成を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\ArrayStore;
+    use Illuminate\Cache\Repository;
+    use Illuminate\Contracts\Cache\Store as CacheStore;
+    class Fixture {
+        public function run(): void {
+            $a = new Repository(new ArrayStore);
+            $b = new \Illuminate\Cache\DatabaseStore(null, 'cache', '');
+            $c = new \Illuminate\Cache\CacheManager(null);
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['bypassCounts'])->toBe([
+        'fixture.php::new Illuminate\Cache\ArrayStore' => 1,
+        'fixture.php::new Illuminate\Cache\CacheManager' => 1,
+        'fixture.php::new Illuminate\Cache\DatabaseStore' => 1,
+        'fixture.php::new Illuminate\Cache\Repository' => 1,
+    ]);
+});
+
+test('負のコントロール: 受け手型 / 保管先型の継承・実装を 4 形すべて検出する', function (): void {
+    // ★直前 token だけを見る形では 2 番目の interface を落とす。宣言句全体を読む。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Countable;
+    use Illuminate\Cache\Repository;
+    use Illuminate\Contracts\Cache\Store as CacheStore;
+    class Second implements Countable, \Illuminate\Contracts\Cache\Store {}
+    class Aliased implements CacheStore {}
+    class Fully implements \Illuminate\Contracts\Cache\Store {}
+    class Multiline implements
+        Countable,
+        CacheStore {}
+    class Inherited extends Repository {}
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['subclassDeclarations'])->toBe([
+        'fixture.php::extends Illuminate\Cache\Repository',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+    ]);
+});
+
+test('正のコントロール: 無関係な interface の implements は迂回にしない', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Countable;
+    use JsonSerializable;
+    class Fixture implements Countable, JsonSerializable {}
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['subclassDeclarations'])->toBe([]);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('負のコントロール: ArrayAccess 書き込みを 2 形とも検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\Repository;
+    class Fixture {
+        public function run(Repository $cache, $dto): void {
+            $cache['a'] = $dto;
+            $cache['b'] ??= $dto;
+            $read = $cache['c'];
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['writes'])->toHaveCount(2);
+    expect(array_map(fn (array $w): string => $w['method'], $result['writes']))
+        ->toBe(['offsetSet', 'offsetSet']);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('正のコントロール: 自己テスト目録に登録された未知 API だけを未分類から外す', function (): void {
+    // ★実行時層の自己テストは「4 分類のどれでもない API 名」を意図的に呼ぶ。
+    //   目録に載っている呼び出しだけを迂回として数え、載っていないものは従来どおり落とす。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\Repository;
+    class Fixture {
+        public function run(Repository $cache): void {
+            $cache->guardProbeUnknownMethod();
+        }
+    }
+    PHP;
+
+    $registered = cachePayloadCollectFromSource($fixture, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE);
+    expect($registered['unclassified'])->toBe([]);
+    expect($registered['bypassCounts'])
+        ->toBe([CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE.'::guardprobeunknownmethod' => 1]);
+
+    $unregistered = cachePayloadCollectFromSource($fixture, 'app/Demo/Fixture.php');
+    expect($unregistered['unclassified'])->toHaveCount(1);
+    expect($unregistered['bypassCounts'])->toBe([]);
+});
+
+test('正のコントロール: guard 付き受け皿の生成そのものは迂回にしない', function (): void {
+    // ★L4d が宣言側 (extends) で塞いでいるので、自前クラスの生成は通ってよい。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Tests\Support\Cache\PlainDataGuardedRepository;
+    class Fixture {
+        public function run($store): void {
+            $repository = new PlainDataGuardedRepository($store, []);
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['bypassCounts'])->toBe([]);
+    expect($result['surface'])->toBeFalse();
+});
+
 test('正のコントロール: 排他・レート制限の型は受け手にしない', function (): void {
     $fixture = <<<'PHP'
     <?php
diff --git a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
index 272fc3df..9483a142 100644
--- a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
+++ b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
@@ -34,7 +34,7 @@
  * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
  * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
  */
-const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 28;
+const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 29;
 
 /** 逸脱の登録簿の本文 (読めないことは不合格)。 */
 function templateDivergenceMarkdown(): string
diff --git a/tests/Feature/Cache/CachePayloadPlainDataGuardTest.php b/tests/Feature/Cache/CachePayloadPlainDataGuardTest.php
new file mode 100644
index 00000000..e22daa51
--- /dev/null
+++ b/tests/Feature/Cache/CachePayloadPlainDataGuardTest.php
@@ -0,0 +1,354 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * 実行時層 (キャッシュ素データ規約) の振る舞い検査。
+ *
+ * 静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) が保証するのは
+ * 「申告なしに書き込み経路を増やせない」ことだけで、目録の payload 欄は人間の申告である。
+ * ここで固定するのは「**テストが実行した書き込みの値が実際に素データである**」ことを
+ * 受け皿 (Illuminate\Cache\Repository) の側で機械的に検査できている、という実体である。
+ *
+ * ★意図的に違反を起こす検査は必ず CachePayloadViolationAssertions::expectViolation() を通す。
+ *   accumulator を drain しないと global afterEach の flushAndFailIfStray() が二重に落ちる。
+ */
+
+use Illuminate\Cache\Repository;
+use Illuminate\Contracts\Cache\Lock;
+use Illuminate\Contracts\Foundation\Application as ApplicationContract;
+use Illuminate\Support\Carbon;
+use Illuminate\Support\Collection;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\Event;
+use Illuminate\Support\Facades\Facade;
+use Tests\Support\Cache\CachePayloadViolation;
+use Tests\Support\Cache\CachePayloadViolationAssertions;
+use Tests\Support\Cache\GuardedBoundaryProbe;
+use Tests\Support\Cache\IsolatedApplicationProbe;
+use Tests\Support\Cache\PlainDataCacheGuard;
+use Tests\Support\Cache\PlainDataGuardedCacheManager;
+use Tests\Support\Cache\PlainDataGuardedRepository;
+use Tests\Support\Cache\PlainDataInspector;
+
+/**
+ * guard 付き受け皿へ**実 API 経由**で書き込む (合流の実証用)。
+ *
+ * remember / rememberForever / sear / set / setMultiple / flexible /
+ * rememberWithWarmth / ArrayAccess は vendor 実装が put / add / forever / putMany へ
+ * 合流する。その合流が将来変わったら本テストが落ちる (guard の被覆が静かに減らない)。
+ *
+ * ★受け皿は**型宣言の引数**で受ける。ローカル変数へ代入する書き方だと静的層が
+ *   受け手名を解決できず、書き込みが L2 目録に現れなくなる。
+ */
+function cachePayloadGuardWrite(Repository $cache, string $method, string $key, mixed $value): void
+{
+    match ($method) {
+        'put' => $cache->put($key, $value, 60),
+        'add' => $cache->add($key, $value, 60),
+        'forever' => $cache->forever($key, $value),
+        'putMany' => $cache->putMany([$key => $value], 60),
+        'set' => $cache->set($key, $value, 60),
+        'setMultiple' => $cache->setMultiple([$key => $value], 60),
+        'remember' => $cache->remember($key, 60, fn (): mixed => $value),
+        'rememberForever' => $cache->rememberForever($key, fn (): mixed => $value),
+        'sear' => $cache->sear($key, fn (): mixed => $value),
+        'flexible' => $cache->flexible($key, [60, 120], fn (): mixed => $value),
+        'rememberWithWarmth' => $cache->rememberWithWarmth($key, 60, fn (): mixed => $value),
+        'offsetSet' => $cache[$key] = $value,
+        'offsetCoalesce' => $cache[$key] ??= $value,
+        default => throw new InvalidArgumentException("未知の書き込みメソッド: {$method}"),
+    };
+}
+
+/**
+ * 名指しで分類した排他の素通し (`PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS`) を
+ * 実 API 経由で叩く。受け皿は**型宣言の引数**で受ける (静的層の受け手解決のため)。
+ */
+function cachePayloadGuardLock(Repository $cache, string $method): Lock
+{
+    return match ($method) {
+        'lock' => $cache->lock('guard-passthrough-lock', 1),
+        'restoreLock' => $cache->restoreLock('guard-passthrough-lock', 'guard-owner'),
+        default => throw new InvalidArgumentException("未知の排他メソッド: {$method}"),
+    };
+}
+
+/** guard 付き受け皿を具体クラスへ絞って取り出す (ArrayAccess を使うため契約型では足りない)。 */
+function cachePayloadGuardedRepository(): Repository
+{
+    $repository = Cache::store('array');
+    expect($repository)->toBeInstanceOf(PlainDataGuardedRepository::class);
+    assert($repository instanceof Repository);
+
+    return $repository;
+}
+
+// ---------------------------------------------------------------------------
+// 検査 1-7: 実 API 経由の値検査 (合流の実証を含む)
+// ---------------------------------------------------------------------------
+
+test('検査 1: Event::fake() の後でも guard が効く', function (): void {
+    Event::fake();
+
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), 'put', 'guard-event-fake', new stdClass),
+        ['put', 'guard-event-fake', 'OBJECT_FOUND(stdClass)'],
+    );
+});
+
+test('検査 2: 値の末端 4 メソッドがオブジェクトを落とす', function (string $method): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), $method, "guard-terminal-{$method}", new stdClass),
+        ['OBJECT_FOUND(stdClass)'],
+    );
+})->with(['put', 'add', 'forever', 'putMany']);
+
+test('検査 3: 糖衣 API も末端へ合流して落ちる', function (string $method): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), $method, "guard-sugar-{$method}", new stdClass),
+        ['OBJECT_FOUND(stdClass)'],
+    );
+})->with(['set', 'setMultiple', 'remember', 'rememberForever', 'sear', 'flexible', 'rememberWithWarmth']);
+
+test('検査 4: ArrayAccess 書き込みも末端へ合流して落ちる', function (string $method): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), $method, "guard-offset-{$method}", new stdClass),
+        ['OBJECT_FOUND(stdClass)'],
+    );
+})->with(['offsetSet', 'offsetCoalesce']);
+
+test('検査 5: クロージャも違反になる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), 'put', 'guard-closure', fn (): int => 1),
+        ['OBJECT_FOUND(Closure)'],
+    );
+});
+
+test('検査 6: 素のデータは通る', function (mixed $value): void {
+    $cache = cachePayloadGuardedRepository();
+    $key = 'guard-plain-'.md5(serialize($value));
+
+    cachePayloadGuardWrite($cache, 'put', $key, $value);
+
+    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+    expect($cache->get($key))->toBe($value);
+})->with([
+    [['a' => 1, 'b' => [true, false]]],
+    ['文字列'],
+    [42],
+    [1.5],
+    [true],
+    [null],
+    [[[[['深い']]]]],
+]);
+
+test('検査 7: 違反メッセージが method / key / 違反パスと種別 / 規約参照を持つ', function (): void {
+    $cache = cachePayloadGuardedRepository();
+
+    try {
+        cachePayloadGuardWrite($cache, 'add', 'guard-message', ['dto' => new stdClass]);
+        $this->fail('違反が検出されませんでした');
+    } catch (CachePayloadViolation $exception) {
+        expect($exception->getMessage())
+            ->toContain('add')
+            ->toContain('guard-message')
+            ->toContain("value['dto'] = OBJECT_FOUND(stdClass)")
+            ->toContain('AGENTS.md');
+    } finally {
+        PlainDataCacheGuard::drainForAssertion();
+    }
+});
+
+// ---------------------------------------------------------------------------
+// 検査 8-12: 値検査器そのもの (正負コントロールと境界)
+// ---------------------------------------------------------------------------
+
+test('検査 8: 値検査器が素データでない値を違反にする', function (): void {
+    expect(PlainDataInspector::violations(new stdClass))->toBe(['value = OBJECT_FOUND(stdClass)']);
+    expect(PlainDataInspector::violations(fn (): int => 1))->toBe(['value = OBJECT_FOUND(Closure)']);
+    expect(PlainDataInspector::violations(Carbon::parse('2026-08-18')))
+        ->toBe(['value = OBJECT_FOUND(Illuminate\Support\Carbon)']);
+    expect(PlainDataInspector::violations(new Collection([1, 2])))
+        ->toBe(['value = OBJECT_FOUND(Illuminate\Support\Collection)']);
+
+    $open = fopen('php://memory', 'r');
+    expect(PlainDataInspector::violations($open))->toBe(['value = RESOURCE_FOUND(stream)']);
+    if (is_resource($open)) {
+        fclose($open);
+    }
+
+    // 閉じた resource は is_resource() が false・is_scalar() も false =
+    // どの許可分岐にも当たらない。fail-closed で UNKNOWN_TYPE になる。
+    expect(PlainDataInspector::violations($open))->toBe(['value = UNKNOWN_TYPE(resource (closed))']);
+
+    // 入れ子の中の違反もパス付きで出る
+    expect(PlainDataInspector::violations(['a' => [0 => new stdClass]]))
+        ->toBe(["value['a'][0] = OBJECT_FOUND(stdClass)"]);
+});
+
+test('検査 9: 値検査器は素データを違反にしない', function (): void {
+    expect(PlainDataInspector::violations(['a' => 1, 'b' => 'x', 'c' => [true, null, 1.5]]))->toBe([]);
+    expect(PlainDataInspector::violations(null))->toBe([]);
+    expect(PlainDataInspector::violations([]))->toBe([]);
+});
+
+test('検査 10: 深さの境界 (32 は通り 33 は LIMIT_EXCEEDED)', function (): void {
+    $build = function (int $depth): array {
+        $value = ['leaf'];
+        for ($i = 1; $i < $depth; $i++) {
+            $value = [$value];
+        }
+
+        return $value;
+    };
+
+    expect(PlainDataInspector::violations($build(PlainDataInspector::MAX_DEPTH)))->toBe([]);
+    expect(PlainDataInspector::violations($build(PlainDataInspector::MAX_DEPTH + 1)))
+        ->toHaveCount(1)
+        ->and(PlainDataInspector::violations($build(PlainDataInspector::MAX_DEPTH + 1))[0])
+        ->toContain('LIMIT_EXCEEDED(depth)');
+});
+
+test('検査 11: ノード数の境界 (根を含む 10000 は通り 10001 は LIMIT_EXCEEDED)', function (): void {
+    // 根 (配列そのもの) を 1 と数えるので、要素数は MAX_NODES - 1 まで通る。
+    $ok = range(1, PlainDataInspector::MAX_NODES - 1);
+    $ng = range(1, PlainDataInspector::MAX_NODES);
+
+    expect(PlainDataInspector::violations($ok))->toBe([]);
+    expect(PlainDataInspector::violations($ng))->toBe(['value[9999] = LIMIT_EXCEEDED(nodes)']);
+});
+
+test('検査 12: 自己参照配列は停止して LIMIT_EXCEEDED になる', function (): void {
+    $value = ['a' => 1];
+    $value['self'] = &$value;
+
+    $violations = PlainDataInspector::violations($value);
+
+    expect($violations)->not->toBe([]);
+    expect(implode(' / ', $violations))->toContain('LIMIT_EXCEEDED');
+});
+
+// ---------------------------------------------------------------------------
+// 検査 13-16: 境界迂回の hard fail
+// ---------------------------------------------------------------------------
+
+test('検査 13: tags() は境界迂回として落ちる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => GuardedBoundaryProbe::callTags(cachePayloadGuardedRepository()),
+        ['BOUNDARY_BYPASS(tags)'],
+    );
+});
+
+test('検査 14: setStore() は境界迂回として落ちる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => GuardedBoundaryProbe::callSetStore(cachePayloadGuardedRepository()),
+        ['BOUNDARY_BYPASS(setStore)'],
+    );
+});
+
+test('検査 15: macro は使用時点で境界迂回として落ちる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => GuardedBoundaryProbe::callMacro(cachePayloadGuardedRepository()),
+        ['BOUNDARY_BYPASS(macro)', 'guardProbeMacro'],
+    );
+});
+
+test('検査 15b: macro でない未知メソッド (store 素通し) も境界迂回として落ちる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => GuardedBoundaryProbe::callUnknownMethod(cachePayloadGuardedRepository()),
+        ['BOUNDARY_BYPASS(storePassthrough)', 'guardProbeUnknownMethod'],
+    );
+});
+
+test('検査 15c: 名指しで分類した排他 2 語彙の素通しは通る', function (string $method): void {
+    // ★正のコントロール。`Illuminate\Cache\Repository` は lock() / restoreLock() を宣言せず、
+    //   `Cache::lock(...)` は __call() の素通しで保管先へ届く (vendor 実読)。
+    //   ここを塞ぐと role=lock-only の 6 ファイルが全滅する (S8 の計測で実測済み)。
+    //   排他は payload を運ばないので名指しで分類し、それ以外の素通しは検査 15b が落とす。
+    $lock = cachePayloadGuardLock(cachePayloadGuardedRepository(), $method);
+
+    expect($lock)->toBeInstanceOf(Lock::class);
+    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+})->with(['lock', 'restoreLock']);
+
+test('検査 16: flush が残存 macro を検出して既定へ戻す', function (): void {
+    GuardedBoundaryProbe::registerMacroWithoutUsing();
+
+    expect(fn () => PlainDataCacheGuard::flushAndFailIfStray())
+        ->toThrow(RuntimeException::class, 'MACRO_REGISTERED');
+
+    // flush の finally が reset() を通るので accumulator も macro も既定へ戻っている。
+    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+    expect(Repository::hasMacro('guardProbeResidualMacro'))->toBeFalse();
+});
+
+// ---------------------------------------------------------------------------
+// 検査 17-19: 握り潰しと結線の実体
+// ---------------------------------------------------------------------------
+
+test('検査 17: 起動 (bootstrap) 中の書き込みは provider が握り潰しても accumulator に残る', function (): void {
+    // ★afterEach で flush が呼ばれること自体は CacheGuardWiringGateTest の担当。
+    //   ここが固定するのは「結線がアプリ起動の前に入っているので起動中の書き込みも見える」ことである。
+    $original = Facade::getFacadeApplication();
+
+    $drained = IsolatedApplicationProbe::run(
+        fn (ApplicationContract $app): array => PlainDataCacheGuard::drainForAssertion()
+    );
+
+    expect(implode(' / ', $drained))->toContain('OBJECT_FOUND(stdClass)');
+
+    // 検査 22 (第 2 アプリの後始末) を同じ場所で固定する。
+    expect(Facade::getFacadeApplication())->toBe($original);
+    expect(Cache::store('array'))->toBeInstanceOf(PlainDataGuardedRepository::class);
+    expect(app('cache'))->toBeInstanceOf(PlainDataGuardedCacheManager::class);
+});
+
+test('検査 18: アプリ側が握り潰しても accumulator に残る', function (): void {
+    $cache = cachePayloadGuardedRepository();
+
+    try {
+        cachePayloadGuardWrite($cache, 'forever', 'guard-swallowed', new stdClass);
+    } catch (Throwable) {
+        // FxRateService と同じく握り潰す形を再現する
+    }
+
+    $drained = PlainDataCacheGuard::drainForAssertion();
+    expect($drained)->toHaveCount(1);
+    expect($drained[0])->toContain('OBJECT_FOUND(stdClass)');
+});
+
+test('検査 19: 独自 creator は CacheManager::repository() を通らない', function (): void {
+    // ★これは trip-wire である。通るようになったら L4 で extend を 0 件 pin する根拠が変わる。
+    $manager = app('cache');
+    expect($manager)->toBeInstanceOf(PlainDataGuardedCacheManager::class);
+    assert($manager instanceof PlainDataGuardedCacheManager);
+
+    $resolved = GuardedBoundaryProbe::resolveCustomDriver($manager);
+
+    expect($resolved)->toBeInstanceOf(Repository::class);
+    expect($resolved)->not->toBeInstanceOf(PlainDataGuardedRepository::class);
+});
+
+// ---------------------------------------------------------------------------
+// 検査 20-21: 後始末と空振り検知
+// ---------------------------------------------------------------------------
+
+test('検査 20: reset() は冪等で、drain 後は次テストへ漏れない', function (): void {
+    $cache = cachePayloadGuardedRepository();
+    cachePayloadGuardWrite($cache, 'put', 'guard-reset', ['ok']);
+
+    PlainDataCacheGuard::reset();
+    PlainDataCacheGuard::reset();
+
+    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+    expect(PlainDataCacheGuard::inspectedCount())->toBe(0);
+});
+
+test('検査 21: guard が実際に値を見ている (空振り検知)', function (): void {
+    $before = PlainDataCacheGuard::inspectedCount();
+
+    cachePayloadGuardWrite(cachePayloadGuardedRepository(), 'put', 'guard-inspected', ['ok']);
+
+    expect(PlainDataCacheGuard::inspectedCount())->toBeGreaterThan($before);
+});
diff --git a/tests/Feature/Config/ConfigHardeningTest.php b/tests/Feature/Config/ConfigHardeningTest.php
index 9126b5c3..549a631a 100644
--- a/tests/Feature/Config/ConfigHardeningTest.php
+++ b/tests/Feature/Config/ConfigHardeningTest.php
@@ -144,6 +144,25 @@ function evaluateConfigFileWithEnv(string $configFile, array $env): array
         'クラス許可一覧は作らない (lctl 標準形 v1 / AGENTS.md セキュリティ不変条件 11)');
 });
 
+// ========== prism-prompt: テンプレートのオブジェクトキャッシュを持たない (T228) ==========
+
+test('config/prism-prompt.php は cache.enabled を false で宣言している (env で開かない)', function (): void {
+    // ★同梱パッケージの PromptTemplate::fromYaml() は PromptTemplate オブジェクトそのものを
+    //   キャッシュへ入れる (AGENTS.md セキュリティ不変条件 11 に反する)。有効・無効を決める
+    //   設定は本リポジトリが所有しているので、env で開け直せる形を残さない。
+    $config = evaluateConfigFileWithEnv('prism-prompt.php', ['PRISM_PROMPT_CACHE' => 'true']);
+
+    expect($config['cache'])->toBeArray();
+    /** @var array<string, mixed> $cache */
+    $cache = $config['cache'];
+    expect($cache['enabled'])->toBeFalse(
+        'PromptTemplate::fromYaml() がオブジェクトをキャッシュへ入れるため、env で開けられてはならない');
+});
+
+test('prism-prompt.cache.enabled は実行時にも false', function (): void {
+    expect(config('prism-prompt.cache.enabled'))->toBeFalse();
+});
+
 // ========== fortify: passkeys ブロックの env 派生 (T166) ==========
 
 /*
diff --git a/tests/Pest.php b/tests/Pest.php
index 144da3a3..4e3c1cbd 100644
--- a/tests/Pest.php
+++ b/tests/Pest.php
@@ -26,6 +26,7 @@
 use Illuminate\Support\Str;
 use Kent013\PrismPrompt\Prompt;
 use Laravel\Cashier\Subscription;
+use Tests\Support\Cache\PlainDataCacheGuard;
 use Tests\Support\StrayHttpRequestGuard;
 use Tests\Support\StrayLlmCallGuard;
 use Tests\TestCase;
@@ -60,18 +61,24 @@
         // テスト本体で Http::fake([...]) を呼ぶと該当 URL は透過する
         // (Factory::fake() は prevent フラグを reset しないため共存する)。
         StrayHttpRequestGuard::install($this->app);
+
+        // キャッシュ guard は Tests\TestCase::createApplication() の bootstrap 前に結線済み。
+        // ここでは**結線が効いていること**だけを確認する (accumulator には触らない。
+        // 触ると起動中に記録された違反が消える)。
+        PlainDataCacheGuard::assertInstalled($this->app);
     })
     ->afterEach(function (): void {
         try {
             // stray call が記録されていれば test を fail させる (Service 層の
             // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
             //
-            // ★2 つの guard は順に flush する。**同時発生時は先に throw した guard の
-            //   詳細だけが表示される** (もう一方の accumulator は finally の reset で
+            // ★3 つの guard は順に flush する。**同時発生時は先に throw した guard の
+            //   詳細だけが表示される** (他方の accumulator は finally の reset で
             //   捨てられる)。test は既に赤いので「静かに緑」にはならず、検出目的は達成される。
-            //   両方を集約する仕組みは入れない (今必要なものだけ作る)。
+            //   すべてを集約する仕組みは入れない (今必要なものだけ作る)。
             StrayLlmCallGuard::flushAndFailIfStray();
             StrayHttpRequestGuard::flushAndFailIfStray();
+            PlainDataCacheGuard::flushAndFailIfStray();
         } finally {
             // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
             if (Prompt::isFaking()) {
@@ -79,6 +86,7 @@
             }
             StrayLlmCallGuard::reset();
             StrayHttpRequestGuard::reset();
+            PlainDataCacheGuard::reset();
         }
     })
     ->in('Feature', 'Unit');
@@ -93,12 +101,15 @@
     ->beforeEach(function (): void {
         $this->withoutVite();
         StrayHttpRequestGuard::install($this->app);
+        PlainDataCacheGuard::assertInstalled($this->app);
     })
     ->afterEach(function (): void {
         try {
             StrayHttpRequestGuard::flushAndFailIfStray();
+            PlainDataCacheGuard::flushAndFailIfStray();
         } finally {
             StrayHttpRequestGuard::reset();
+            PlainDataCacheGuard::reset();
         }
     })
     ->in('Architecture');
@@ -137,17 +148,21 @@
         // Browser lane と bughunt 実行時の両方で共有 (registrar 参照)。install() 内の
         // stopFaking の後に上書きインストールするのが load-bearing。
         app(CannedPromptFakeRegistrar::class)->install();
+
+        PlainDataCacheGuard::assertInstalled($this->app);
     })
     ->afterEach(function (): void {
         try {
             StrayLlmCallGuard::flushAndFailIfStray();
             StrayHttpRequestGuard::flushAndFailIfStray();
+            PlainDataCacheGuard::flushAndFailIfStray();
         } finally {
             if (Prompt::isFaking()) {
                 Prompt::stopFaking();
             }
             StrayLlmCallGuard::reset();
             StrayHttpRequestGuard::reset();
+            PlainDataCacheGuard::reset();
         }
     })
     ->in('Browser');
diff --git a/tests/Support/Cache/BootTimeCacheWriteProbeProvider.php b/tests/Support/Cache/BootTimeCacheWriteProbeProvider.php
new file mode 100644
index 00000000..10b76848
--- /dev/null
+++ b/tests/Support/Cache/BootTimeCacheWriteProbeProvider.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\ServiceProvider;
+use stdClass;
+use Throwable;
+
+/**
+ * 起動 (bootstrap) 中の書き込みを実行時層が捕まえることを固定するための見本 provider。
+ *
+ * `boot()` で意図的にオブジェクトをキャッシュへ入れ、**自分で例外を握り潰す**。
+ * 握り潰しても accumulator に記録が残ることを
+ * tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が確認する。
+ * `catch` を消すと bootstrap 自体が例外になって別の理由で赤くなる (どちらでも赤い)。
+ *
+ * ★この provider は `IsolatedApplicationProbe` が組み立てる**第 2 のアプリ**にだけ登録する。
+ *   通常のテスト用アプリへ足すと bootstrap 中に落ちてテスト本体へ到達しない。
+ */
+final class BootTimeCacheWriteProbeProvider extends ServiceProvider
+{
+    /** 起動中に意図的な違反を書き込むキー。 */
+    public const string PROBE_KEY = 'cache-guard-boot-probe';
+
+    public function boot(): void
+    {
+        try {
+            Cache::put(self::PROBE_KEY, new stdClass, 60);
+        } catch (Throwable) {
+            // 意図的に握り潰す (アプリ側の try/catch fallback の再現)
+        }
+    }
+}
diff --git a/tests/Support/Cache/CachePayloadViolation.php b/tests/Support/Cache/CachePayloadViolation.php
new file mode 100644
index 00000000..a8a6195a
--- /dev/null
+++ b/tests/Support/Cache/CachePayloadViolation.php
@@ -0,0 +1,45 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use RuntimeException;
+
+/**
+ * キャッシュへ素のデータでない値を書き込もうとした / 受け皿の境界を迂回したときに投げる。
+ *
+ * 書き込み呼び出しの**中で** throw されるため、失敗は書き込み元のテストへ帰属する
+ * (「読み出しで壊れる」形の弱い検出にしない)。呼び出し元が握り潰しても
+ * PlainDataCacheGuard の accumulator に残り、afterEach で必ず赤くなる。
+ */
+final class CachePayloadViolation extends RuntimeException
+{
+    /**
+     * @param  list<string>  $violations
+     */
+    public static function forWrite(string $method, string $key, array $violations): self
+    {
+        return new self(
+            "Cache::{$method}('{$key}') に素のデータでない値が渡されました:".PHP_EOL
+            .'  '.implode(PHP_EOL.'  ', $violations).PHP_EOL
+            .'キャッシュに入れてよいのは配列 / 文字列 / 数値 / 真偽値 / null だけです。'
+            .'読み出し側がアプリのコードで組み立て直せる形 (例: DTO なら toArray()) にしてください。'
+            .'規約: AGENTS.md セキュリティ不変条件 11 / '
+            .'静的層: tests/Architecture/CachePayloadPlainDataGateTest.php / '
+            .'実行時層: tests/Support/Cache/PlainDataGuardedRepository.php'
+            .' (LIMIT_EXCEEDED / UNKNOWN_TYPE は「guard が素のデータであることを証明できなかった」'
+            .'ことを表す。値を小さくするか、キャッシュに入れる形を見直すこと)',
+        );
+    }
+
+    public static function forBoundary(string $operation, string $detail): self
+    {
+        return new self(
+            "キャッシュ受け皿の境界を迂回しました: {$operation} ({$detail})。".PHP_EOL
+            .'受け皿 (Illuminate\Cache\Repository) を跨いで保管先へ届く経路は、'
+            .'実行時層が値を見られないため使えません。'
+            .'規約: AGENTS.md セキュリティ不変条件 11 / 家系の裁定 AG-151 の境界迂回の hard fail',
+        );
+    }
+}
diff --git a/tests/Support/Cache/CachePayloadViolationAssertions.php b/tests/Support/Cache/CachePayloadViolationAssertions.php
new file mode 100644
index 00000000..2c1363ab
--- /dev/null
+++ b/tests/Support/Cache/CachePayloadViolationAssertions.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Closure;
+
+/**
+ * 意図的な違反を起こすテストのための共通 assertion。
+ *
+ * ★drain を忘れるとグローバル afterEach の `flushAndFailIfStray()` が二重に落ちて
+ *   **すべての負例が失敗する**。単に消すのではなく**記録内容まで assert する**
+ *   (「例外だけ別経路から出た」空振りを防ぐため)。
+ * ★PSR-4 は関数をオートロードしないので、global function ではなくクラスの static メソッドにする。
+ */
+final class CachePayloadViolationAssertions
+{
+    /**
+     * (1) 例外が投げられること (2) accumulator にちょうど 1 件記録され期待する断片を含むこと
+     * (3) drain 後に accumulator が空であること をまとめて検査する。
+     *
+     * @param  Closure(): mixed  $callback
+     * @param  list<string>  $expectedFragments
+     */
+    public static function expectViolation(Closure $callback, array $expectedFragments): void
+    {
+        expect($callback)->toThrow(CachePayloadViolation::class);
+
+        $drained = PlainDataCacheGuard::drainForAssertion();
+        expect($drained)->toHaveCount(1);
+        foreach ($expectedFragments as $fragment) {
+            expect($drained[0])->toContain($fragment);
+        }
+        expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+    }
+}
diff --git a/tests/Support/Cache/GuardedBoundaryProbe.php b/tests/Support/Cache/GuardedBoundaryProbe.php
new file mode 100644
index 00000000..0aea112b
--- /dev/null
+++ b/tests/Support/Cache/GuardedBoundaryProbe.php
@@ -0,0 +1,85 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Cache\ArrayStore;
+use Illuminate\Cache\CacheManager;
+use Illuminate\Cache\Repository;
+
+/**
+ * 境界迂回が hard fail することを固定するための**唯一の**呼び出し元。
+ *
+ * ★受け皿を `Illuminate\Cache\Repository` 型の**引数**で受けるのが load-bearing —
+ *   静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) は型宣言から受け手名を作るため、
+ *   ローカル変数へ代入する書き方だと L4 の自己テスト目録が実測 0 件になって exact-fit が落ちる。
+ * ★境界 API を呼ぶ自己テストは**このファイルにだけ**置く (L4f が置き場所を名指しで固定する)。
+ */
+final class GuardedBoundaryProbe
+{
+    // ★`@return never` は付けない。引数の native 型は**通常の** Illuminate\Cache\Repository で、
+    //   通常の Repository の tags() は値を返し得る。「guard 付きを渡したときに例外になる」ことは
+    //   tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が保証するのであって、
+    //   静的なメソッド契約ではない。
+
+    public static function callTags(Repository $cache): void
+    {
+        $cache->tags(['t']);
+    }
+
+    public static function callSetStore(Repository $cache): void
+    {
+        $cache->setStore(new ArrayStore);
+    }
+
+    public static function callUnknownMethod(Repository $cache): void
+    {
+        $cache->guardProbeUnknownMethod();
+    }
+
+    /**
+     * macro を登録して**使う**。guard の `__call()` が例外を投げるので、
+     * **`finally` で必ず登録を消す** — 消さないと global afterEach の macro 検査が
+     * MACRO_REGISTERED を記録し、意図的負例が二重に失敗する。
+     * 境界 API の呼び出しはこのファイルにしか置けないので、
+     * テスト本体の finally から `flushMacros()` を呼ぶ形にはできない。
+     */
+    public static function callMacro(Repository $cache): void
+    {
+        Repository::macro('guardProbeMacro', fn (): bool => true);
+
+        try {
+            $cache->guardProbeMacro();
+        } finally {
+            Repository::flushMacros();
+        }
+    }
+
+    /**
+     * macro を**登録するだけ**で使わない (flush の残存 macro 検出用)。
+     * 呼び出し側のテストが `flushAndFailIfStray()` を明示的に呼び、
+     * MACRO_REGISTERED の記録と既定への復元を確認する。
+     */
+    public static function registerMacroWithoutUsing(): void
+    {
+        Repository::macro('guardProbeResidualMacro', fn (): bool => true);
+    }
+
+    /**
+     * 独自 creator が `CacheManager::repository()` を通らないことの実証用。
+     *
+     * ★登録も解決も**引数の manager** に対して行う。facade へ登録して引数から解決すると、
+     *   facade root と引数が別インスタンスだったときに「extend の前提」ではなく
+     *   別インスタンスの問題で落ちる。CacheManager は静的層の受け手型なので
+     *   L4 の検出力は保たれる。
+     */
+    public static function resolveCustomDriver(CacheManager $manager): mixed
+    {
+        config()->set('cache.stores.guard-probe', ['driver' => 'guard-probe']);
+
+        $manager->extend('guard-probe', fn (): Repository => new Repository(new ArrayStore));
+
+        return $manager->store('guard-probe');
+    }
+}
diff --git a/tests/Support/Cache/IsolatedApplicationProbe.php b/tests/Support/Cache/IsolatedApplicationProbe.php
new file mode 100644
index 00000000..122fb128
--- /dev/null
+++ b/tests/Support/Cache/IsolatedApplicationProbe.php
@@ -0,0 +1,68 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Closure;
+use Illuminate\Container\Container;
+use Illuminate\Contracts\Console\Kernel;
+use Illuminate\Foundation\Application;
+use Illuminate\Support\Facades\Facade;
+use RuntimeException;
+
+/**
+ * 第 2 のアプリを **Tests\TestCase::createApplication() と同じ結線経路**で組み立て、
+ * コンテナと facade の状態を必ず元へ戻す。
+ *
+ * 起動 (bootstrap) 中の書き込みを実行時層が捕まえることを固定するには、
+ * 起動が失敗しても走り続けられる**別のアプリ**が要る (通常のテスト用アプリへ
+ * 違反する provider を足すと bootstrap 中に落ちてテスト本体へ到達しない)。
+ *
+ * 退避と復元の順序 (固定):
+ *   退避: Container::getInstance() → Facade::getFacadeApplication()
+ *   復元 (finally): Facade::clearResolvedInstances() → Facade::setFacadeApplication(退避値)
+ *         → Container::setInstance(退避値) → PlainDataCacheGuard::reset()
+ *
+ * ★戻すのは「**第 2 アプリの解決済みインスタンスを残さず、元の Application から
+ *   再解決できる状態**」であって、元の解決済みインスタンスそのものではない
+ *   (facade の解決済みインスタンスは消去して遅延再解決に任せる)。
+ */
+final class IsolatedApplicationProbe
+{
+    /**
+     * @template TReturn
+     *
+     * @param  Closure(Application): TReturn  $callback
+     * @return TReturn
+     */
+    public static function run(Closure $callback): mixed
+    {
+        $container = Container::getInstance();
+        $facadeApplication = Facade::getFacadeApplication();
+
+        try {
+            $app = require Application::inferBasePath().'/bootstrap/app.php';
+            if (! $app instanceof Application) {
+                throw new RuntimeException(
+                    'bootstrap/app.php が Application を返しませんでした: '.get_debug_type($app)
+                );
+            }
+
+            // ★ここが結線点。Tests\TestCase::createApplication() と**同じ関数**を
+            //   bootstrap() より前に呼ぶ (CacheGuardWiringGateTest が同一性を pin する)。
+            PlainDataCacheGuard::registerBeforeBootstrap($app);
+
+            $app->register(BootTimeCacheWriteProbeProvider::class);
+
+            $app->make(Kernel::class)->bootstrap();
+
+            return $callback($app);
+        } finally {
+            Facade::clearResolvedInstances();
+            Facade::setFacadeApplication($facadeApplication);
+            Container::setInstance($container);
+            PlainDataCacheGuard::reset();
+        }
+    }
+}
diff --git a/tests/Support/Cache/PlainDataCacheGuard.php b/tests/Support/Cache/PlainDataCacheGuard.php
new file mode 100644
index 00000000..9fa1ee5f
--- /dev/null
+++ b/tests/Support/Cache/PlainDataCacheGuard.php
@@ -0,0 +1,264 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Cache\CacheManager;
+use Illuminate\Cache\RateLimiter;
+use Illuminate\Cache\Repository;
+use Illuminate\Contracts\Foundation\Application;
+use ReflectionClass;
+use ReflectionProperty;
+use RuntimeException;
+
+/**
+ * キャッシュ素データ規約の**実行時層**。テスト実行中のキャッシュ書き込みを受け皿の側で
+ * 捕まえ、保管先へ渡す**前の値**を再帰検査する (家系の裁定 AG-151 = 正典 v2 の要素 2)。
+ *
+ * ## 2 層のうちの実行時層である
+ *
+ * 静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) が保証するのは
+ * 「申告なしに書き込み経路を増やせない」ことだけで、目録の payload 欄は**人間の申告**である。
+ * 本 guard が保証するのは「**テストが実行した書き込みの値が実際に素データである**」ことである。
+ * 受け皿を包んで値を見るので、**直列化を一度も経由しない array store でも同じように発火する**。
+ *
+ * ## 結線はアプリ起動の**前**
+ *
+ * 結線点は `Tests\TestCase::createApplication()` の `bootstrap()` 直前である
+ * (`registerBeforeBootstrap()`)。Pest の beforeEach では遅い — 起動 (bootstrap) 中の
+ * 書き込みは、vendor 由来だと静的層の走査根 (app / routes / database / tests) にも
+ * 入らないため、結線が遅れると**2 層とも沈黙する穴**になる。
+ * `Illuminate\Container\Container::extend()` は binding がまだ無くても登録でき、
+ * `CacheServiceProvider::register()` の `singleton('cache', …)` は extenders を消さない
+ * (`bind()` の `dropStaleInstances()` が消すのは instances と aliases だけ) ので、
+ * `cache` の初回解決時に必ず guard 付き manager になる。
+ *
+ * ## 違反は「その場で例外」と「accumulator への記録」の両方
+ *
+ * アプリ側の `catch (Throwable)` (準拠実装 `FxRateService` が読み書きを握り潰す形を持つ) で
+ * 例外が消えても、afterEach の `flushAndFailIfStray()` で必ず赤くなる
+ * (既存の `StrayHttpRequestGuard` / `StrayLlmCallGuard` と同じ設計)。
+ *
+ * ## 保証しないもの (**正本はここ**。AGENTS.md / docs には写さない)
+ *
+ * - `bootstrap/app.php` を require し終える前に走るコードからの書き込み
+ *   (結線はその直後なので、起動中 = bootstrap の書き込みは**対象に入る**)
+ * - **`getStore()` 経由**で保管先へ直接書く形。vendor 自身が正常系で `getStore()` を呼ぶため
+ *   実行時には落とせない (`Illuminate\Cache\RateLimiter::withoutSerializationOrCompression()` /
+ *   `Repository::flushLocks()` / スケジューラの排他)。ここを塞ぐのは**静的層 (L4) だけ**であり、
+ *   **vendor が `getStore()` 経由で書く値は 2 層とも見えない**
+ * - **保管先へ素通しさせた排他 2 語彙 (`lock` / `restoreLock`) の先**
+ *   (`PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS`。排他は payload を運ばない、が根拠)
+ * - **走査根の外で宣言された第三者 `Store` 実装**を直接生成する / 独自のコンテナ束縛で得る経路
+ * - テストが 1 度も踏まない経路 (実行時層は実行されないものを見ない)
+ * - `--parallel` の worker をまたいだ違反の集約 (accumulator はプロセス内 static)
+ * - macro を**同一テスト内で登録し、使わずに、`flushMacros()` で消す**形
+ *   (使えば `__call()` が落とし、残せば flush の macro 検査が落とすが、
+ *    使わずに消された登録はどちらにも現れない)
+ */
+final class PlainDataCacheGuard
+{
+    /** @var list<string> */
+    private static array $violations = [];
+
+    /** guard が実際に値を検査した回数 (空振り検知用)。 */
+    private static int $inspected = 0;
+
+    /**
+     * アプリ生成の直後・`bootstrap()` の**前**に呼ぶ。
+     *
+     * 順序は load-bearing である。
+     *  1. accumulator と計測値を初期化する (前テストが異常終了して afterEach が走らなかった
+     *     場合の残骸をここで消す)
+     *  2. `Repository::$macros` を検査して既定へ戻す (残骸があれば違反として記録してから)
+     *  3. `cache` の extender を登録する
+     *
+     * ★1 と 2 を Pest の beforeEach へ置いてはならない。結線が bootstrap 前に入る以上、
+     *   **起動中に記録された違反が beforeEach の初期化で消える**。provider が例外を握り潰した
+     *   場合、accumulator の記録が唯一の証拠である。
+     */
+    public static function registerBeforeBootstrap(Application $app): void
+    {
+        self::$violations = [];
+        self::$inspected = 0;
+        self::pinMacros();
+
+        $app->extend('cache', function (mixed $manager, Application $app): PlainDataGuardedCacheManager {
+            // ★受け取った実体が**素の** CacheManager ちょうどでなければ落とす。
+            //   独自 creator の登録口 (Cache::extend()) は静的層 L4 が 0 件で pin しているので、
+            //   引き継ぐべき状態は無い。想定外の実体を黙って捨てない。
+            if (! $manager instanceof CacheManager || $manager::class !== CacheManager::class) {
+                throw new RuntimeException(
+                    'cache binding が想定外の実体でした: '.get_debug_type($manager).'。'
+                    .'PlainDataCacheGuard の結線前提 (素の Illuminate\Cache\CacheManager) が崩れている。'
+                );
+            }
+
+            return new PlainDataGuardedCacheManager($app);
+        });
+    }
+
+    /**
+     * 結線が効いていることの確認 (Pest の beforeEach)。**accumulator には触らない**。
+     */
+    public static function assertInstalled(Application $app): void
+    {
+        $manager = $app->make('cache');
+        if (! $manager instanceof PlainDataGuardedCacheManager) {
+            throw new RuntimeException('キャッシュ guard が結線されていません: '.get_debug_type($manager));
+        }
+
+        // ★RateLimiter は起動中に cache を解決する (AppServiceProvider::boot() が
+        //   RateLimiter::for(...) を多数登録するため必ず解決される)。したがって
+        //   「起動前に結線できていた」ことの証拠になる。**解決されていなければ前提が崩れたので落とす**。
+        if (! $app->resolved(RateLimiter::class)) {
+            throw new RuntimeException(
+                'RateLimiter が起動中に解決されていません。起動前結線の前提 '
+                .'(AppServiceProvider::boot() の名前付き制限登録) が崩れている。'
+            );
+        }
+
+        // **読むだけで書き換えない**。プロパティが無ければ ReflectionException = その場で失敗。
+        $repository = (new ReflectionProperty(RateLimiter::class, 'cache'))
+            ->getValue($app->make(RateLimiter::class));
+
+        if (! $repository instanceof PlainDataGuardedRepository) {
+            throw new RuntimeException(
+                'RateLimiter が guard 付きでない受け皿を握っています: '.get_debug_type($repository)
+            );
+        }
+    }
+
+    /**
+     * 書き込まれる値を検査する。違反は accumulator に記録し、**その場でも例外**を投げる。
+     */
+    public static function inspect(string $method, string $key, mixed $value): void
+    {
+        self::$inspected++;
+
+        $violations = PlainDataInspector::violations($value);
+        if ($violations === []) {
+            return;
+        }
+
+        self::$violations[] = "{$method}('{$key}'): ".implode(' / ', $violations);
+
+        throw CachePayloadViolation::forWrite($method, $key, $violations);
+    }
+
+    /**
+     * 受け皿の境界を迂回した呼び出しを記録して例外にする。
+     */
+    public static function reportBoundary(string $operation, string $detail): never
+    {
+        self::$violations[] = "BOUNDARY_BYPASS({$operation}): {$detail}";
+
+        throw CachePayloadViolation::forBoundary($operation, $detail);
+    }
+
+    /**
+     * Pest の afterEach。残存 macro を検査して記録し、accumulator に記録があれば fail させる。
+     */
+    public static function flushAndFailIfStray(): void
+    {
+        try {
+            self::pinMacros();
+
+            if (self::$violations === []) {
+                return;
+            }
+
+            throw new RuntimeException(
+                'Plain-data cache violation detected during test execution. '
+                .'キャッシュに入れてよいのは素のデータだけ (AGENTS.md セキュリティ不変条件 11 / '
+                .'家系の裁定 AG-107・AG-151)。'.PHP_EOL.self::summarize(self::$violations)
+            );
+        } finally {
+            self::reset();
+        }
+    }
+
+    /** accumulator と計測値を消し、macro を**記録せずに**既定へ戻す。 */
+    public static function reset(): void
+    {
+        self::$violations = [];
+        self::$inspected = 0;
+        self::restoreMacros();
+    }
+
+    /**
+     * 意図的に違反を起こすテスト用の drain (`StrayLlmCallGuard` と同じ)。
+     *
+     * @return list<string>
+     */
+    public static function drainForAssertion(): array
+    {
+        $drained = self::$violations;
+        self::$violations = [];
+
+        return $drained;
+    }
+
+    /** guard が実際に値を見た回数 (空振り検知)。 */
+    public static function inspectedCount(): int
+    {
+        return self::$inspected;
+    }
+
+    /**
+     * `Repository::$macros` を検査して記録し、既定へ戻す。
+     */
+    private static function pinMacros(): void
+    {
+        $macros = self::readMacros();
+        if ($macros !== []) {
+            self::$violations[] = 'MACRO_REGISTERED('
+                .implode(', ', array_map(strval(...), array_keys($macros))).')';
+        }
+
+        self::restoreMacros();
+    }
+
+    /** 記録せず既定へ戻すだけ (reset() から呼ぶ。flush の直後に二重記録しない)。 */
+    private static function restoreMacros(): void
+    {
+        self::macrosProperty()->setValue(null, []);
+    }
+
+    /** @return array<array-key, mixed> */
+    private static function readMacros(): array
+    {
+        $macros = self::macrosProperty()->getValue();
+        if (! is_array($macros)) {
+            throw new RuntimeException('Repository::$macros が配列ではありません: '.get_debug_type($macros));
+        }
+
+        return $macros;
+    }
+
+    private static function macrosProperty(): ReflectionProperty
+    {
+        $reflection = new ReflectionClass(Repository::class);
+        if (! $reflection->hasProperty('macros')) {
+            throw new RuntimeException(
+                'Illuminate\Cache\Repository::$macros が存在しません。macro 経由の迂回 pin が'
+                .'空振りしている。vendor を読み直して pin を作り直すこと。'
+            );
+        }
+
+        return $reflection->getProperty('macros');
+    }
+
+    /**
+     * @param  list<string>  $violations
+     */
+    private static function summarize(array $violations): string
+    {
+        return implode(PHP_EOL, array_map(
+            static fn (string $violation, int $index): string => '  ['.($index + 1).'] '.$violation,
+            $violations,
+            array_keys($violations),
+        ));
+    }
+}
diff --git a/tests/Support/Cache/PlainDataGuardedCacheManager.php b/tests/Support/Cache/PlainDataGuardedCacheManager.php
new file mode 100644
index 00000000..91f372d8
--- /dev/null
+++ b/tests/Support/Cache/PlainDataGuardedCacheManager.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Cache\CacheManager;
+use Illuminate\Contracts\Cache\Store;
+use Illuminate\Support\Arr;
+
+/**
+ * すべての cache driver を PlainDataGuardedRepository で包むテスト用 CacheManager。
+ *
+ * vendor の組み込み driver 生成 (`createArrayDriver()` 等) はいずれも `repository()` を
+ * 通るため、ここ 1 箇所の override で array / database / file いずれにも guard が効く。
+ * `Cache::extend()` の独自 creator は `repository()` を通らない
+ * (tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が実証する)。
+ * よって静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php の L4) が
+ * `Cache::extend()` を **通常経路 0 件 + GuardedBoundaryProbe の自己テストの exact-fit** で
+ * pin して口を塞いでいる。
+ *
+ * **本クラスは Illuminate\Contracts\Cache\Store を参照してよい唯一のサイトである**
+ * (vendor 互換シグネチャの要求)。`$store` は
+ * `new PlainDataGuardedRepository($store, ...)` の第 1 引数以外に現れてはならず、
+ * その構造条件は同 gate の L4c が機械検査する (store を外へ流出させると受け皿を迂回できる)。
+ */
+final class PlainDataGuardedCacheManager extends CacheManager
+{
+    /**
+     * {@inheritDoc}
+     *
+     * @param  array<string, mixed>  $config
+     * @return PlainDataGuardedRepository
+     */
+    public function repository(Store $store, array $config = [])
+    {
+        $repository = new PlainDataGuardedRepository($store, Arr::only($config, ['store']));
+
+        // vendor CacheManager::repository() と同じ event dispatcher 設定を再現する。
+        if ($config['events'] ?? true) {
+            $this->setEventDispatcher($repository);
+        }
+
+        return $repository;
+    }
+}
diff --git a/tests/Support/Cache/PlainDataGuardedRepository.php b/tests/Support/Cache/PlainDataGuardedRepository.php
new file mode 100644
index 00000000..5e384a3e
--- /dev/null
+++ b/tests/Support/Cache/PlainDataGuardedRepository.php
@@ -0,0 +1,188 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Cache\Repository;
+use UnitEnum;
+
+/**
+ * キャッシュ書き込みの**値の実体**を検査する受け皿 (テスト実行時層)。
+ *
+ * ## なぜ受け皿 (Repository) 境界なのか (イベント購読ではない)
+ *
+ * `Illuminate\Cache\Events\KeyWritten` の購読は**差し替え可能な境界**であり、
+ * テスト本体の `Event::fake()` や store 設定の `'events' => false` で無効化できる。
+ * `Illuminate\Cache\Repository` の書き込みメソッドはイベント層より下にあるため、
+ * どちらの影響も受けない。
+ *
+ * ## なぜ 4 メソッドで足りるのか (vendor 実読で確認済み)
+ *
+ * set → put / setMultiple → putMany / remember → rememberWithWarmth → put /
+ * sear → rememberForever → forever / flexible → putMany / offsetSet → put /
+ * putMany($v, null) → putManyForever → forever。
+ * 合流が将来変わったら CachePayloadPlainDataGuardTest の実 API 経由テストが落ちる。
+ * ★これは**標準 API の値の合流**についての主張であって、`Store` へ直接届く経路の
+ *   完全性の主張ではない (そちらは静的層 L4 の担当)。
+ *
+ * ## 境界迂回として落とすもの
+ *
+ * - `tags()` — vendor の実装が `new TaggedCache($this->store, ...)` を素で生成するため、
+ *   継承しても以降の書き込みが検査を通らない。加えて本番の保管方式 (database store) は
+ *   タグ非対応 (`supportsTags()` が false) なので、タグを使う書き方は本番で例外になる
+ * - `setStore()` — 受け皿の保管先を差し替える口 (vendor に呼び出し元 0 件)
+ * - `__call()` — macro は**無条件に**落とす。macro の closure は `$this->store` へ
+ *   直接到達でき、末端 4 メソッドを通らない (「同一テスト内で登録し、使い、消す」形も
+ *   使用時点で捕まる)。macro でない素通しは、**保管先の非 payload API として名指しで
+ *   分類した語彙だけ**を通し、それ以外は落とす (`STORE_PASSTHROUGH_METHODS`)
+ *
+ * ## 保管先への素通しを名指しで分類する理由 (deny-by-default)
+ *
+ * `Illuminate\Cache\Repository` は **`lock()` / `restoreLock()` を宣言していない**。
+ * `Cache::lock(...)` は `CacheManager::__call()` → `Repository::__call()` →
+ * `$this->store->lock(...)` の素通しで届く (vendor 実読)。本リポジトリはこの形を
+ * 6 ファイルで使っており (静的層の role=lock-only)、排他オブジェクトは payload を運ばない。
+ * よって「payload を運ばない排他 2 語彙**だけ**」を名指しで通し、それ以外の素通しは落とす。
+ * この 2 語彙が静的層の TERMINAL 語彙 (`lock` / `restorelock`) と一致していることは
+ * tests/Architecture/CacheGuardWiringGateTest.php が機械で固定する
+ * (許可を 2 か所で別々に育てられないようにするため)。
+ *
+ * ## 保証しないもの
+ *
+ * - **`getStore()` は落とさない**。vendor 自身が正常系で呼ぶためである — 実読の根拠:
+ *   `Illuminate\Cache\RateLimiter::withoutSerializationOrCompression()` (hit/increment の経路) /
+ *   `Illuminate\Cache\Repository::flushLocks()` (自己呼び出し) /
+ *   `Illuminate\Console\Scheduling\CacheEventMutex` / `Illuminate\Console\CacheCommandMutex` /
+ *   `Illuminate\Cache\Limiters\ConcurrencyLimiterBuilder` / `Illuminate\Cache\MemoizedStore`。
+ *   よって「保管先を直接取得して書く」形を塞ぐのは**静的層 (L4) だけ**であり、
+ *   vendor が `getStore()` 経由で書く値は実行時層に見えない
+ * - **素通しを許した 2 語彙の先**は見ない (`$this->store->lock(...)` が保管先で何をするかは
+ *   検査しない。排他は payload を持たない、が根拠である)
+ * - `increment` / `decrement` は store 直行だが整数しか書けないので検査しない
+ *
+ * ## 許可一覧を持たない (payload について)
+ *
+ * vendor の書き込みも対象に含める。`config/cache.php` の `serializable_classes => false` の下では
+ * **誰が入れたかに関わらず**オブジェクトを入れれば本番の読み出しが失敗するため、
+ * vendor の検出は誤検出ではなく本番の潜在バグの発見である (家系の裁定 AG-107「例外を作らない」)。
+ * 上の `STORE_PASSTHROUGH_METHODS` は**値を運ばない API の分類**であって、
+ * 「この呼び出し元なら値を見逃す」という許可ではない。
+ */
+final class PlainDataGuardedRepository extends Repository
+{
+    /**
+     * 保管先へ素通しさせる非 payload API (全小文字)。
+     *
+     * `Illuminate\Cache\Repository` が宣言しておらず、`__call()` 経由で
+     * `Illuminate\Contracts\Cache\LockProvider` へ届く排他 2 語彙だけである。
+     *
+     * @var list<string>
+     */
+    public const array STORE_PASSTHROUGH_METHODS = ['lock', 'restorelock'];
+
+    /**
+     * {@inheritDoc}
+     */
+    public function put($key, $value, $ttl = null)
+    {
+        if (is_array($key)) {
+            // vendor と同じく `$key` が配列なら putMany 形 (値の実体は $key 側)。
+            PlainDataCacheGuard::inspect('put', '(many)', $key);
+
+            return parent::put($key, $value, $ttl);
+        }
+
+        PlainDataCacheGuard::inspect('put', self::describeKey($key), $value);
+
+        return parent::put($key, $value, $ttl);
+    }
+
+    /**
+     * {@inheritDoc}
+     */
+    public function add($key, $value, $ttl = null)
+    {
+        PlainDataCacheGuard::inspect('add', self::describeKey($key), $value);
+
+        return parent::add($key, $value, $ttl);
+    }
+
+    /**
+     * {@inheritDoc}
+     */
+    public function forever($key, $value)
+    {
+        PlainDataCacheGuard::inspect('forever', self::describeKey($key), $value);
+
+        return parent::forever($key, $value);
+    }
+
+    /**
+     * {@inheritDoc}
+     */
+    public function putMany(array $values, $ttl = null)
+    {
+        PlainDataCacheGuard::inspect('putMany', '(many)', $values);
+
+        return parent::putMany($values, $ttl);
+    }
+
+    /**
+     * {@inheritDoc}
+     *
+     * @return never
+     */
+    public function tags($names)
+    {
+        PlainDataCacheGuard::reportBoundary('tags', self::describeKey($names));
+    }
+
+    /**
+     * {@inheritDoc}
+     *
+     * ★vendor の宣言は `public function setStore($store)` で **型宣言を持たない**
+     *   (docblock に `@param \Illuminate\Contracts\Cache\Store $store` があるだけ)。
+     *   忠実に写すので本クラスは `Store` 型を参照しない
+     *   = 「Store 型を参照してよい唯一のサイトは manager の repository()」という主張と矛盾しない。
+     *
+     * @return never
+     */
+    public function setStore($store)
+    {
+        PlainDataCacheGuard::reportBoundary('setStore', get_debug_type($store));
+    }
+
+    /**
+     * {@inheritDoc}
+     *
+     * macro は無条件に落とす。macro でない素通しは名指しで分類した非 payload API だけ通す
+     * (クラス docblock「境界迂回として落とすもの」/「保管先への素通しを名指しで分類する理由」)。
+     */
+    public function __call($method, $parameters)
+    {
+        if (self::hasMacro($method)) {
+            PlainDataCacheGuard::reportBoundary('macro', $method);
+        }
+
+        if (! in_array(strtolower($method), self::STORE_PASSTHROUGH_METHODS, true)) {
+            PlainDataCacheGuard::reportBoundary('storePassthrough', $method);
+        }
+
+        return parent::__call($method, $parameters);
+    }
+
+    /** 失敗メッセージ用のキー表現 (キーは string / UnitEnum / 配列を取り得る)。 */
+    private static function describeKey(mixed $key): string
+    {
+        if (is_string($key)) {
+            return $key;
+        }
+
+        if ($key instanceof UnitEnum) {
+            return $key::class.'::'.$key->name;
+        }
+
+        return get_debug_type($key);
+    }
+}
diff --git a/tests/Support/Cache/PlainDataInspector.php b/tests/Support/Cache/PlainDataInspector.php
new file mode 100644
index 00000000..9b897046
--- /dev/null
+++ b/tests/Support/Cache/PlainDataInspector.php
@@ -0,0 +1,134 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+/**
+ * キャッシュへ書き込まれる値が**素のデータ**かを再帰検査する純関数。
+ *
+ * 素のデータ = 配列 / 文字列 / 数値 / 真偽値 / null だけで構成された値
+ * (家系の裁定 AG-151 が定めた許可集合。AGENTS.md セキュリティ不変条件 11 と同義)。
+ * DTO・Eloquent モデル・Collection・列挙型・日時オブジェクト・クロージャ・resource は違反である。
+ *
+ * ## 違反の種別
+ *
+ * - `OBJECT_FOUND` / `RESOURCE_FOUND` — 規約そのものの違反
+ * - `UNKNOWN_TYPE` — **上のどれにも当てはまらない型**。閉じた resource が代表例で、
+ *   `is_resource()` は false を返すが `is_scalar()` にも当たらない。
+ *   「分類できなかったものを素データとして通さない」ための fail-closed 分岐である
+ * - `LIMIT_EXCEEDED` — **規約違反ではなく「検査器が素のデータであることを証明できなかった」**
+ *   ことを表す。自己参照配列 (`$v['self'] = &$v;`) は素朴な再帰走査を停止させないため、
+ *   深さ・ノード数の上限を置き、超過は fail-closed で違反として返す
+ *
+ * ## 上限値の根拠
+ *
+ * - 深さ 32: `json_decode` の既定深さ 512 より十分浅く、キャッシュ payload としては 32 段でも異常に深い
+ * - ノード 10000: **根の値を 1 と数えた総ノード数**。1 件のキャッシュ entry としては十分大きい
+ *
+ * 境界の直前・直後は tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が pin する。
+ *
+ * ## 保証しないもの
+ *
+ * - **値の意味**は見ない (素のデータであれば内容は問わない)
+ * - 配列のキーは見ない (PHP は配列キーを int|string に限るので、キーがオブジェクトになる形は無い)
+ * - **保管先へ渡ったあとの変換**は見ない (store 側の直列化・圧縮は対象外)
+ */
+final class PlainDataInspector
+{
+    /** 走査の最大深さ (配列の入れ子段数)。超過は LIMIT_EXCEEDED。 */
+    public const int MAX_DEPTH = 32;
+
+    /** 走査の最大ノード数 (**根の値を 1 と数える**)。超過は LIMIT_EXCEEDED。 */
+    public const int MAX_NODES = 10000;
+
+    /**
+     * 値が素のデータかを再帰検査し、違反を返す (空配列 = 素のデータ)。
+     *
+     * @return list<string> "<パス> = <種別>(<詳細>)" の形
+     */
+    public static function violations(mixed $value, string $path = 'value'): array
+    {
+        /** @var list<string> $violations */
+        $violations = [];
+        $nodes = 0;
+
+        self::walk($value, $path, 0, $violations, $nodes);
+
+        return $violations;
+    }
+
+    /**
+     * @param  list<string>  $violations
+     */
+    private static function walk(mixed $value, string $path, int $depth, array &$violations, int &$nodes): void
+    {
+        $nodes++;
+        if ($nodes > self::MAX_NODES) {
+            if (! self::alreadyReportedLimit($violations, 'nodes')) {
+                $violations[] = $path.' = LIMIT_EXCEEDED(nodes)';
+            }
+
+            return;
+        }
+
+        // ★許可集合を**先に**判定して早期 return する (許可の定義を 1 か所に閉じる)。
+        if ($value === null || is_scalar($value)) {
+            return;
+        }
+
+        if (is_object($value)) {
+            $violations[] = $path.' = OBJECT_FOUND('.$value::class.')';
+
+            return;
+        }
+
+        if (is_resource($value)) {
+            $violations[] = $path.' = RESOURCE_FOUND('.get_resource_type($value).')';
+
+            return;
+        }
+
+        if (! is_array($value)) {
+            // ★閉じた resource が代表例。is_resource() は false、is_scalar() も false。
+            //   分類できないものを素データとして通さない (fail-closed)。
+            $violations[] = $path.' = UNKNOWN_TYPE('.get_debug_type($value).')';
+
+            return;
+        }
+
+        if ($depth + 1 > self::MAX_DEPTH) {
+            $violations[] = $path.' = LIMIT_EXCEEDED(depth)';
+
+            return;
+        }
+
+        foreach ($value as $key => $element) {
+            self::walk(
+                $element,
+                $path.'['.(is_int($key) ? (string) $key : "'".$key."'").']',
+                $depth + 1,
+                $violations,
+                $nodes,
+            );
+
+            if ($nodes > self::MAX_NODES) {
+                return;
+            }
+        }
+    }
+
+    /**
+     * @param  list<string>  $violations
+     */
+    private static function alreadyReportedLimit(array $violations, string $kind): bool
+    {
+        foreach ($violations as $violation) {
+            if (str_ends_with($violation, 'LIMIT_EXCEEDED('.$kind.')')) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+}
diff --git a/tests/TestCase.php b/tests/TestCase.php
index b527b73a..9aaf6e55 100644
--- a/tests/TestCase.php
+++ b/tests/TestCase.php
@@ -4,7 +4,15 @@
 
 namespace Tests;
 
+use Illuminate\Contracts\Console\Kernel;
+use Illuminate\Foundation\Application;
+use Illuminate\Foundation\Testing\CachedState;
 use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
+use Illuminate\Foundation\Testing\WithCachedConfig;
+use Illuminate\Foundation\Testing\WithCachedRoutes;
+use Override;
+use RuntimeException;
+use Tests\Support\Cache\PlainDataCacheGuard;
 
 abstract class TestCase extends BaseTestCase
 {
@@ -12,4 +20,42 @@ abstract class TestCase extends BaseTestCase
      * RefreshDatabase 後に DatabaseSeeder (Role 等の参照データ) を流す。
      */
     protected bool $seed = true;
+
+    /**
+     * アプリを生成する。**bootstrap の直前**にキャッシュ guard を結線するために override する。
+     *
+     * ★Pest の beforeEach では遅い。起動 (bootstrap) 中の書き込みは、vendor 由来だと
+     *   静的層の走査根 (app / routes / database / tests) にも入らないため、
+     *   結線が遅れると 2 層とも沈黙する穴になる。
+     *
+     * ★本体は vendor (Illuminate\Foundation\Testing\TestCase::createApplication()) の
+     *   写しであり、**guard の結線 1 行と戻り値の fail-closed 確認だけを足している**。
+     *   vendor 側が変わったら tests/Architecture/CacheGuardWiringGateTest.php の
+     *   W5 / W5b (期待 token 列の完全一致) が赤くなるので、そのとき写し直す。
+     */
+    #[Override]
+    public function createApplication(): Application
+    {
+        $app = require Application::inferBasePath().'/bootstrap/app.php';
+
+        if (! $app instanceof Application) {
+            throw new RuntimeException('bootstrap/app.php が Application を返しませんでした');
+        }
+
+        PlainDataCacheGuard::registerBeforeBootstrap($app);
+
+        $this->traitsUsedByTest = class_uses_recursive(static::class);
+
+        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
+            $this->markConfigCached($app);
+        }
+
+        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
+            $app->booting(fn () => $this->markRoutesCached($app));
+        }
+
+        $app->make(Kernel::class)->bootstrap();
+
+        return $app;
+    }
 }

```

## 実装メモ (設計からの差分と根拠)

- 詳細設計 S2 は `Repository::__call()` を**無条件 hard fail** にしていたが、vendor 実読と実測
  (計測記録: devnotes/20260818-1757-cache-runtime-plain-data-guard/runtime-exposure.md の wave 0) の結果、
  `Illuminate\Cache\Repository` は `lock()` / `restoreLock()` を宣言しておらず
  `Cache::lock(...)` が `__call()` の素通しで保管先へ届くことが分かった。無条件 hard fail のままだと
  role=lock-only の 6 ファイルが全滅する (実測: ReconcileSubscriptionStatusTest 18 件中 16 件が失敗)。
  設計 S2 の「リスク」節と完了条件 3 が定めた手順に従い、guard に無言の許可を作らず
  `PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS` として排他 2 語彙を名指しで分類し、
  静的層の TERMINAL 語彙との一致を検査 L4g が pin する形にした。
- 設計 S7 の検査番号 L4a/L4b の割当を実装では L4a (迂回の exact-fit) / L4b (目録の形式と実測の非空) /
  L4c (store の流出) / L4d (継承・実装) / L4e (判定規則) / L4f (置き場所) / L4g (2 層の語彙一致) とした。
- 設計 S5 の検査 22 (第 2 アプリの後始末) は独立したテストにせず検査 17 の中で固定した
  (同じ run() 呼び出しの前後でしか観測できないため)。
- 設計 S6 の W5b は「ローカル期待列の完全一致」と「許可差分を除くと vendor 期待列に一致」の
  二重検査として実装した (許可差分は位置つきで pin)。
- PHPStan の解析対象に tests/ は含まれない (phpstan.neon の paths は app/config/database/routes)。
  設計の「tests/ も解析対象」という前提は本リポジトリでは成り立たない。

## テスト結果

- composer test: 5893 tests / 5890 passed / 0 failed (最終確認は下記の全コマンド)
- composer phpstan: No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / test (165 files 2224 tests) / build: passed
- pnpm typecheck:packages / build:packages / test:packages (10 files 106 tests): passed
- composer test:browser: chromium 32 passed 3 skipped / webkit 31 passed 4 skipped
