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
