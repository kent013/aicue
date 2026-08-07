# 詳細設計レビュー Round 5

Round 4 の Warning 2 件を両方とも反映しました。反論はありません。

# 対応マトリクス: design-review Round 4

全体判定は **CHANGES_REQUESTED**（Critical 0 / Warning 2）。両方とも対応した。反論はゼロ。

## [Warning] S1-1: 追加した role 検証分岐が現データでは一度も実行されない

- 判断: **対応する**（指摘が正しい。空振り検知を自分の gate に適用し忘れていた）
- 根拠: `CACHE_PAYLOAD_SURFACE_INVENTORY` に該当 role の entry が 0 件なので、
  検査 5 に足した分岐は**実行されない**。実装を反転・削除しても全テストが緑のままで、
  これは本設計が繰り返し主張してきた「空振りしない検査」「正負コントロールで規則を固定する」に
  真っ向から反する。**自分の gate に自分の原則を適用していなかった**という指摘に完全に同意する。
- 対応内容: role 判定を純関数
  `cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry): list<string>` に切り出し、
  検査 5 はそれを呼ぶだけにした。加えて **検査 5b「role 判定規則そのものの正負コントロール」**を新設し、
  3 role すべての許可・拒否パターン（許可 6 / 拒否 10）を実在ファイルの構成に依存せず固定した。
  テスト本数は 21 → 22 になった。

## [Warning] S1-2: `read-only` という role 名と許可語彙が一致していない

- 判断: **対応する**（Codex の提案 2 = role 名の変更を採る）
- 根拠: `CACHE_PAYLOAD_NON_WRITE_METHODS` には `forget` / `flush` / `clear` / `increment` /
  `purge` など**読み出しではない操作**が含まれる。`Cache::flush()` だけを呼ぶファイルが
  「read-only」を名乗れるのは名前と実態の乖離であり、AGENTS.md 思考原則
  「機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している」に反する。
  本 gate の目的は **payload 制約**なので、role 名もその軸で切るのが正しい。
- 対応内容: role を `read-only` → **`no-payload-write`**（キャッシュに触れるが
  任意 payload を書く API を呼ばない）に改名し、定義コメント・fail message・
  検査 4 の復旧手順文言をすべて同期した。`lock-only` を独立に残す設計は維持
  （排他は payload 制約とは別の責務で、`JobExecutionDedupInventoryTest` 側の担当と接続するため）。
  現状の目録は `write` 1 件 + `lock-only` 4 件で、`no-payload-write` は 0 件のまま
  （規則は検査 5b が固定するので空振りしない）。

## S2 / S3 / S4 / S5: APPROVE（変更なし）

---

## 追加した純関数と検査 5 / 5b

```php
function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry): array
{
    if (! in_array($role, ['write', 'no-payload-write', 'lock-only'], true)) {
        return ["role は write / no-payload-write / lock-only のいずれか（宣言値: {$role}）"];
    }

    if ($role === 'write') {
        return $hasWriteEntry ? [] : ['role=write なのに書き込み目録に entry がありません'];
    }

    $violations = [];
    if ($hasWriteEntry) {
        $violations[] = "role={$role} なのに書き込み目録に entry があります";
    }
    if ($methods === []) {
        $violations[] = "role={$role} なのにキャッシュ API 呼び出しが 1 件もありません"
            .'（使わなくなったなら import ごと消す）';
    }

    if ($role === 'lock-only') {
        $extra = array_values(array_diff($methods, ['lock', 'restorelock']));
        if ($extra !== []) {
            $violations[] = 'role=lock-only なのに排他以外のキャッシュ API を呼んでいます: '.implode(', ', $extra);
        }

        return $violations;
    }

    // no-payload-write: 任意 payload を書かない API と連鎖 API だけを許す
    // （TERMINAL の lock / mock は別 role・別責務なのでここには入れない）
    $allowed = array_merge(CACHE_PAYLOAD_NON_WRITE_METHODS, CACHE_PAYLOAD_CHAIN_METHODS, ['cache']);
    $extra = array_values(array_diff($methods, $allowed));
    if ($extra !== []) {
        $violations[] = 'role=no-payload-write なのに payload を書く / 排他・mock の API を呼んでいます: '
            .implode(', ', $extra);
    }
    // CHAIN だけで終わる形（受け手を取り回しているのに何もしない）は role の意味を壊すので終端を要求する
    if (array_intersect($methods, array_merge(CACHE_PAYLOAD_NON_WRITE_METHODS, ['cache'])) === []) {
        $violations[] = 'role=no-payload-write なのに終端の操作（読み出し・削除等）がありません';
    }

    return $violations;
}

test('検査 5: 目録が宣言した role が実測と整合する', function (): void {
    $result = cachePayloadCollectAll();

    foreach (CACHE_PAYLOAD_SURFACE_INVENTORY as $path => $entry) {
        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$path}: rationale が短すぎます");

        $methods = array_map('strtolower', $result['surfaces'][$path] ?? []);

        $hasWrite = false;
        foreach (array_keys(CACHE_PAYLOAD_WRITE_INVENTORY) as $writeKey) {
            if (str_starts_with($writeKey, $path.'::')) {
                $hasWrite = true;
                break;
            }
        }

        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite))
            ->toBe([], "{$path}: 宣言した role が実測と整合しません");
    }
});

test('検査 5b: role 判定規則そのものの正負コントロール', function (): void {
    // ★実在ファイルの構成に依存せず判定規則を固定する。現状 no-payload-write の entry は 0 件なので、
    //   ここが無いと「実装を反転させても 21 テストが緑のまま」という穴が空く（design-review Round 4 反映）。
    expect(cachePayloadRoleViolations('write', ['get', 'forget', 'put'], true))->toBe([]);
    expect(cachePayloadRoleViolations('write', ['get'], false))->not->toBe([]);

    expect(cachePayloadRoleViolations('lock-only', ['lock'], false))->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', ['lock', 'restorelock'], false))->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', [], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', ['lock', 'get'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', ['lock'], true))->not->toBe([]);

    expect(cachePayloadRoleViolations('no-payload-write', ['get'], false))->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['store', 'get'], false))->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['forget'], false))->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', [], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['store'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['lock'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['put'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['get'], true))->not->toBe([]);

    expect(cachePayloadRoleViolations('unknown-role', ['get'], false))->not->toBe([]);
});
```

---

## 修正後の詳細設計書（全文）

# 詳細設計: cache-payload-plain-data (キャッシュ素データ規約の明文化と gate)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`。`tests/` も対象）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（本設計は DB を使わないためモデル生成そのものが無い）
- **DTO + JsonResource** パターン（本設計は HTTP 応答を作らない）
- **アーリーリターン** 推奨 / `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

- `devnotes/20260807-2032-cache-payload-plain-data/conceptual-design.md`（Codex conceptual-review Round 1 で **APPROVED**）
- 対応マトリクス: `codex-history/conceptual-review-decisions-round-1.md`
- 実査ブリーフ: `recon-brief.md`

## この設計が依拠する実測（2026-08-07 に /workspace で確認）

| # | 実測 | 根拠 |
|---|------|------|
| F1 | `config/cache.php:128` = `'serializable_classes' => false,` | ファイル確認 |
| F2 | キャッシュ書き込みは `app/Services/FxRateService.php:49` の 1 か所のみ | `grep -rn "Cache::" app/` |
| F3 | `Cache::lock()` は 9 か所（AutoRecharge 6 / Subscription 2 / TicketCheckout 1 / Reconcile 1） | 同上 |
| F4 | `Illuminate\Support\Facades\Cache` を import するのは 5 ファイルのみ | `grep -rln` |
| F5 | `cache()` ヘルパ・`Illuminate\Contracts\Cache\Repository` の DI は 0 件 | `grep -rn` |
| F6 | `->put(` は app/ に 16 件、うち 15 件 `session()->put` / 1 件 `disk()->put` | `grep -rn -- "->put(" app/` |
| F7 | `tests/Architecture/JobExclusionOrderingInvariantTest.php:10` は **コメント内**に `Cache::lock` と書いている | ファイル確認 |
| F8 | テストレーンは `phpunit.xml:50` で `CACHE_STORE=array`、`config/cache.php` の array store は `'serialize' => false` | ファイル確認 |
| F9 | `CacheManager.php:473` は `config['cache.serializable_classes'] ?? null` を読み、各 store は `if ($this->serializableClasses !== null)` の**ときだけ** `allowed_classes` を渡す（`FileStore.php:444` / `DatabaseStore.php:593`）。**キーを消すと制限なしの `unserialize()` に戻る = fail-open** | vendor 確認 |
| F10 | `tests/Feature/Config/ConfigHardeningTest.php` に `evaluateConfigFileWithEnv()` と「cache: カスタム storage store は持たない」節が既にある | ファイル確認 |

**F8 + F9 が本件の核心**: テストは array store（`serialize => false`）なのでオブジェクトを cache に入れても
**そのまま返ってきて緑になる**。本番は database store で `serialize()` され、`serializable_classes => false`
なので読み戻しは `__PHP_Incomplete_Class` になる。つまり**実行時検出（テスト実行中の観測）では原理的に捕まらない**
欠陥であり、静的検査を選ぶ理由そのものである。あわせて F9 により、pin が守るのは「false であること」だけでなく
「**キーが存在すること**」でもある（消すと fail-open）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | キャッシュ payload gate の新設（L1 語彙 / L2 書き込み経路 / L3 面 + 空振り検知 + 正負コントロール） | `tests/Architecture/CachePayloadPlainDataGateTest.php`（新規） | High |
| S2 | FxSnapshotDto 往復の単体テスト | `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php`（新規） | High |
| S3 | `serializable_classes` 宣言の pin | `tests/Feature/Config/ConfigHardeningTest.php`（追記） | High |
| S4 | guide §7 不変条件 6 の誤情報訂正 | `docs/app-integration-guide.md`（213-214 行を書き換え） | **Critical** |
| S5 | AGENTS.md セキュリティ不変条件 11 の追記 | `AGENTS.md`（末尾に追加） | High |

**アプリコード（`app/` / `config/` / `routes/` / `resources/`）の変更はゼロ。**

---

## S1: キャッシュ payload gate の新設

### 変更箇所

- 新規ファイル: `tests/Architecture/CachePayloadPlainDataGateTest.php`（想定 380-430 行）

### 波及変更

- TypeScript 型定義: なし（フロント差分ゼロ）
- API Resource/DTO: なし
- テストファイル: 本ファイルが新規。既存テストの変更なし
- アプリコード: なし

### 現行コード

該当なし（`tests/Architecture/` 全 71 ファイルにキャッシュ payload の検査は存在しない）。
作法の見本は `tests/Architecture/CarbonOverflowArithmeticGateTest.php`（PhpToken 走査 + 正負コントロール +
空振り検知）と `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php`（deny-by-default 目録 +
exact-fit + 免除の根拠 30 文字以上）。

### 母集団定義（設計の中心）

**受け手（receiver）を解決してからメソッド名を見る**。`->put(` を受け手なしで拾う方式は
F6 の 16 件中 15 件（`session()->put`）と `disk()->put` を巻き込むため採らない。
Cache facade だけを見る方式は `cache()` ヘルパと DI（F5 で現状 0 件 = 将来増える）が素通りするため採らない。

**受け手として解決するもの**

| 起点 | 例 | 実装 |
|------|-----|------|
| facade | `Cache::put(...)` / `\Cache::put(...)` | `use` 表で `Illuminate\Support\Facades\Cache` に解決、または未 import の裸 `Cache` |
| ヘルパ | `cache()->put(...)` / `cache(['k' => $v], 60)` / `cache($values, 60)` | `cache(` 呼び出し。**引数 0 個なら連鎖の起点**、第 1 引数が `[` なら**その呼び出し自体が WRITE**、文字列リテラルなら読み出し、**それ以外（変数・関数呼び出し）は静的に判定できないので `unclassified` = fail**（`cache($values, 60)` は配列なら書き込みになる。判定できない形は書かせない） |
| DI 変数 / プロパティ | `$this->cache->put(...)` / `$cache->put(...)` | 同一ファイル内の**型宣言**（promoted ctor param / プロパティ宣言 / 引数）から名前を収集 |
| コンテナ | `app('cache')->put(...)` / `resolve(Repository::class)->put(...)` | callee が `app` / `resolve` / `make` かつ第 1 引数が `'cache'` / `'cache.store'` の文字列リテラル、**または受け手型の `::class`** |

**受け手として解決しないもの（明示除外・理由付き）**

| 対象 | 理由 |
|------|------|
| `session()->put` / `$session->put` / `$request->session()->put`（15 件） | 受け手が Session。cache ではない |
| `$this->disk()->put`（FakeObjectStore） | 受け手が Filesystem |
| `Illuminate\Contracts\Cache\Lock` / `LockProvider` / `LockTimeoutException` | 排他オブジェクト・例外型。payload を持たない |
| `Illuminate\Cache\RateLimiter` / `RateLimiting\Limit` | レート制限。`ThrottleCoverageInventoryTest` の担当で母集団が交わらない |

**メソッド語彙の 4 分類（L1）**。キャッシュ受け手に対して呼ばれたメソッドは必ずどれかに属さねばならず、
**どこにも属さないものは fail** する。これにより「Laravel が新しい書き込み API を足したのに deny 表を
更新し忘れてすり抜ける」経路を塞ぐ。

| 分類 | メソッド | 扱い |
|------|---------|------|
| WRITE | `put` `add` `forever` `remember` `rememberForever` `sear` `flexible` `putMany` `set` `setMultiple` | 呼び出し箇所を L2 inventory と突き合わせる |
| NON_WRITE | `get` `many` `getMultiple` `has` `missing` `pull` `forget` `delete` `deleteMultiple` `flush` `clear` `increment` `decrement` `supportsTags` `getPrefix` `getDefaultDriver` `setDefaultDriver` `forgetDriver` `purge` `extend` `itemKey` `refreshEventDispatcher` | 計数のみ。`increment` / `decrement` は整数しか書けないため素データが構造的に保証される |
| CHAIN | `store` `driver` `tags` `resolve` `getStore` | 受け手を保ったまま連鎖を辿る（`Cache::store('redis')->put(...)` / `Cache::getStore()->put(...)` を捕まえる。`getStore()` の戻り値 `Store` は `put` / `forever` を持つ**書き込み可能な受け手**なので NON_WRITE ではなく CHAIN） |
| TERMINAL | `lock` `restoreLock` `shouldReceive` `spy` `partialMock` `swap` `expects` `shouldHaveReceived` `shouldNotHaveReceived` `getFacadeRoot` | 非書き込み。**以降の連鎖を辿らない**（Lock / Mockery の語彙はキャッシュ語彙ではない） |

`lock` を TERMINAL にすることが F3 の 9 か所を巻き込まないための要点であり、同時に
`Cache::lock('k', 10)->block(1, fn () => ...)` の `block` や `$lock->get()` を
「キャッシュの読み書き」と誤分類しない保証でもある（Codex conceptual-review Round 1 の Warning 対応）。

### 変更後コード

```php
<?php

declare(strict_types=1);

/*
 * Architecture invariant: **キャッシュに入れてよいのは素のデータだけ**（配列 / 文字列 / 数値 / 真偽値）。
 *
 * SoT = lctl 台帳 feature `cache-payload-plain-data` の標準形 v1（裁定 2026-08-06）と
 * AGENTS.md セキュリティ不変条件 11 / docs/app-integration-guide.md §7 不変条件 6。
 *
 * ★なぜ静的検査か（実行時検出では捕まらない）:
 *   テストレーンは phpunit.xml で CACHE_STORE=array、config/cache.php の array store は
 *   'serialize' => false。**オブジェクトを put してもそのまま返る = テストは緑になる**。
 *   本番は database store で serialize され、serializable_classes => false のため
 *   読み戻しは __PHP_Incomplete_Class になる。つまり「テストで再現しない本番専用の壊れ方」であり、
 *   実行時 detector（KeyWritten 購読等）は原理的にこの穴を塞げない。
 *
 * ★serializable_classes は **false 固定**であって「キーを消してよい」ではない:
 *   CacheManager は `config['cache.serializable_classes'] ?? null` を読み、各 store は
 *   `if ($this->serializableClasses !== null)` のときだけ allowed_classes を渡す。
 *   キーを消すと制限なしの unserialize() に戻る = **fail-open**。宣言の pin は
 *   tests/Feature/Config/ConfigHardeningTest.php（config ファイル直接評価）が担い、
 *   実行時の値はここで pin する。
 *
 * ★この gate が保証するもの:
 *   - 検査 1 (L1 語彙): キャッシュ受け手に対して呼ばれた API が全件 4 分類のどれかに属する。
 *     未分類は fail（Laravel が新しい書き込み API を足したときに黙って通さない）
 *   - 検査 2-3 (L2 書き込み経路): WRITE に分類された呼び出し箇所が目録と exact-fit。
 *     未登録も、実在しない登録も、件数のズレも fail。各 entry は payload の説明・
 *     往復を固定する単体テストのパス（実在検証つき）・30 文字以上の根拠を持つ
 *   - 検査 4-5 (L3 面): **キャッシュ記号に触れているファイル集合**が目録と exact-fit で、
 *     宣言した role（write / no-payload-write / lock-only）が実測と整合する（規則自体も検査 5b で固定）
 *   - 検査 6: 実行時 config('cache.serializable_classes') === false、store 単位の上書きなし
 *   - 検査 5b: role 判定規則そのものの正負コントロール（実在ファイルの構成に依存させない）
 *   - 検査 6b: 語彙表の健全性（4 分類が互いに素 / 除外型が受け手型に混ざっていない）
 *   - 検査 7: 空振り検知（走査ファイル数 / メソッド呼び出し数 / 解決できたキャッシュ式が 0 でない）
 *   - 検査 8: 自己参照コントロール（本ファイル自身を走査して書き込み 0 件・面 hit なし）
 *   - 検査 9 以降: 正負コントロール fixture（facade / チェーン / ヘルパ / DI / コンテナ /
 *     getStore / literal 動的呼び出し / 完全修飾ヘルパ / 静的に判定できない形 /
 *     session・disk / lock / コメント）
 *
 * ★この gate が保証しないもの（誇張しない）:
 *   - **payload の式が本当に素データか**は静的に判定しない。目録の `payload` 欄は人間の申告で、
 *     機械が保証するのは「申告なしに書き込み経路を増やせない」ことと「往復の単体テストが実在する」ことだけ
 *   - **facade mock 経由の書き込み**（`Cache::shouldReceive('put')`）。TERMINAL で辿りを止めるため
 *     WRITE には数えない。ただしそのファイルは L3（面）に必ず現れるので無申告では追加できない
 *   - **受け手そのものが動的に得られる形**（`$container->make($name)->put(...)` など、
 *     bind 名が変数）。受け手を解決できないので WRITE に数えない。L3 でも捕まらない
 *     （`app` / `resolve` / `make` の第 1 引数が literal のときだけ面として数えるため）。
 *     この形は実測 0 件で、通常のレビューで自明に不自然な書き方である
 *   ※ 受け手が cache と分かっている上での**動的メソッド名**（`->{$m}(...)` / `->$m(...)`）は
 *     素通りさせず `unclassified` として fail させる。literal 形（`->{'put'}(...)`）は通常形と同じに分類する
 *   - **docblock だけで型付けされた受け手**（`/** @var Repository $c */ $c->put(...)`）。
 *     型宣言（引数 / プロパティ / promoted ctor param）のみを見る。
 *     ※同じファイルに対応する型の `use` があれば **L3（面）には現れる**が、
 *       完全修飾 docblock だけ（`/** @var \Illuminate\Contracts\Cache\Repository $c */`）で
 *       import も型宣言も無い形は **L3 でも捕まらない**。docblock 解析は行わない（実測 0 件）
 *
 * 解析は PhpToken::tokenize（コメント・文字列リテラルは code token ではないので拾わない）。
 * regex にすると**この説明コメント自身**で偽赤になる。DB 不使用（Architecture lane は TestCase のみ）。
 */

/**
 * 走査対象ディレクトリ（リポジトリルートからの相対）。
 *
 * tests/ を含めるのは、テストが array store の性質に守られて object を cache に入れても
 * 緑になるため（本番だけで壊れる書き方をテストが先に持ち込むのを防ぐ）。
 * 本 gate の fixture は nowdoc の中にあり code token ではないので自己汚染しない（検査 8 で固定）。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_SCAN_DIRS = ['app', 'routes', 'database', 'tests'];

/**
 * 受け手として解決するキャッシュ型（FQCN）。
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
 * キャッシュ名前空間だが payload を持たないため受け手にしない型（明示除外・理由付き）。
 *
 * @var array<string, string>
 */
const CACHE_PAYLOAD_EXCLUDED_TYPES = [
    'Illuminate\Contracts\Cache\Lock' => '排他オブジェクト。payload を持たない',
    'Illuminate\Contracts\Cache\LockProvider' => '排他の発行元。payload を持たない',
    'Illuminate\Contracts\Cache\LockTimeoutException' => '例外型',
    'Illuminate\Cache\RateLimiter' => 'レート制限。ThrottleCoverageInventoryTest の担当',
    'Illuminate\Cache\RateLimiting\Limit' => 'レート制限の値オブジェクト',
];

/** @var list<string> payload を書き込む API（全小文字） */
const CACHE_PAYLOAD_WRITE_METHODS = [
    'put', 'add', 'forever', 'remember', 'rememberforever', 'sear',
    'flexible', 'putmany', 'set', 'setmultiple',
];

/** @var list<string> payload を書き込まない API（increment/decrement は整数のみ） */
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
 * NON_WRITE ではなく CHAIN（`Cache::getStore()->put(...)` の抜けを塞ぐ）。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'tags', 'resolve', 'getstore'];

/** @var list<string> 受け手がキャッシュでなくなる terminal（以降を辿らない） */
const CACHE_PAYLOAD_TERMINAL_METHODS = [
    'lock', 'restorelock', 'shouldreceive', 'spy', 'partialmock', 'swap',
    'expects', 'shouldhavereceived', 'shouldnothavereceived', 'getfacaderoot',
];

/**
 * L2: キャッシュ **書き込み経路**の目録（deny-by-default / exact-fit）。
 *
 * key   = `{リポジトリルートからの相対パス}::{メソッド名（全小文字）}`（行番号は使わない。
 *         行がずれるたびに目録が壊れると gate が「邪魔だから消す」対象になるため）
 * count = そのファイル・そのメソッドの出現回数（exact-fit。2 件目を足したら必ず落ちる）
 * payload = 実際に渡している式と、それが素データである理由
 * proof   = 往復を固定している単体テストのパス（**実在を検査する**）
 * rationale = 30 文字以上の具体的根拠
 *
 * 経路が 1 本しかない現状では専用 enum（app/Enums/Security/）+ inventory クラス
 * （tests/Support/Security/）へ昇格させない（AGENTS.md 思考原則 2「今必要なものだけ作る」）。
 *
 * @var array<string, array{count: int, payload: string, proof: string, rationale: string}>
 */
const CACHE_PAYLOAD_WRITE_INVENTORY = [
    'app/Services/FxRateService.php::put' => [
        'count' => 1,
        'payload' => 'FxSnapshotDto::toArray() の連想配列（float 1 / string 3）。オブジェクトは渡さない',
        'proof' => 'tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php',
        'rationale' => '当日の USD/JPY レートを 1 日 cache する。読み戻しは is_array 検査 + FxSnapshotDto::fromArray() + 失敗時 Cache::forget() で標準形どおり',
    ],
];

/**
 * L3: **キャッシュ記号に触れているファイル**の目録（exact-fit）。
 *
 * L1/L2 の静的解析には原理的な穴（変数動的ディスパッチ / docblock 型 / facade mock）がある。
 * 「新しいファイルがキャッシュに触れ始めたこと」自体を粗い網で捕まえ、穴を無申告で通さない。
 *
 * role: write = 任意 payload を書く（L2 にも登録が要る） /
 *       no-payload-write = キャッシュに触れるが任意 payload を書く API を呼ばない（読み出し / 削除 / flush 等） /
 *       lock-only = 排他だけ
 * ※「read-only」ではなく no-payload-write と呼ぶ。forget / flush を含む実態と名前を一致させるため
 *
 * @var array<string, array{role: string, rationale: string}>
 */
const CACHE_PAYLOAD_SURFACE_INVENTORY = [
    'app/Services/FxRateService.php' => [
        'role' => 'write',
        'rationale' => 'FX レートの当日 cache。素の配列を put し、読み戻しで DTO へ組み立て直す唯一の経路',
    ],
    'app/Services/Billing/AutoRechargeService.php' => [
        'role' => 'lock-only',
        'rationale' => 'org 単位のオートリチャージ排他に Cache::lock を使うのみ。payload は一切書かない',
    ],
    'app/Services/Billing/SubscriptionService.php' => [
        'role' => 'lock-only',
        'rationale' => 'checkout 開始 / プラン変更の二重実行を Cache::lock で抑止するのみ。payload は書かない',
    ],
    'app/Services/Billing/TicketCheckoutService.php' => [
        'role' => 'lock-only',
        'rationale' => 'チケット checkout の二重発行を Cache::lock で抑止するのみ。payload は書かない',
    ],
    'app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php' => [
        'role' => 'lock-only',
        'rationale' => '突合コマンドの多重起動を Cache::lock で抑止するのみ。payload は書かない',
    ],
];

/**
 * 走査対象の PHP ファイル一覧。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function cachePayloadScanTargets(): array
{
    $root = base_path();
    $files = [];
    foreach (CACHE_PAYLOAD_SCAN_DIRS as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getRealPath();
            if (! is_string($absolute)) {
                continue;
            }
            $files[] = [
                'absolute' => $absolute,
                'relative' => ltrim(str_replace($root, '', $absolute), '/'),
            ];
        }
    }
    sort($files);

    return $files;
}

/**
 * index 以降で最初の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadNext(array $tokens, int $index): ?int
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
 * index 以前で最後の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadPrev(array $tokens, int $index): ?int
{
    for ($i = $index; $i >= 0; $i--) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * `(` の対応する `)` の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadMatchingParen(array $tokens, int $open): ?int
{
    $depth = 0;
    $count = count($tokens);
    for ($i = $open; $i < $count; $i++) {
        if ($tokens[$i]->text === '(') {
            $depth++;
        } elseif ($tokens[$i]->text === ')') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * `use A\B\C;` / `use A\B\C as D;` から alias => FQCN の表を作る。
 * グループ use（`use A\{B, C};`）は本リポジトリに存在しないため扱わない（限界として明記）。
 *
 * @param  list<PhpToken>  $tokens
 * @return array<string, string>
 */
function cachePayloadUseMap(array $tokens): array
{
    $map = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_USE)) {
            continue;
        }
        $nameIndex = cachePayloadNext($tokens, $i + 1);
        if ($nameIndex === null || ! $tokens[$nameIndex]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue; // closure の use(...) など
        }
        $fqcn = ltrim($tokens[$nameIndex]->text, '\\');
        $alias = str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn;

        $asIndex = cachePayloadNext($tokens, $nameIndex + 1);
        if ($asIndex !== null && $tokens[$asIndex]->is(T_AS)) {
            $aliasIndex = cachePayloadNext($tokens, $asIndex + 1);
            if ($aliasIndex !== null && $tokens[$aliasIndex]->is(T_STRING)) {
                $alias = $tokens[$aliasIndex]->text;
            }
        }
        $map[$alias] = $fqcn;
    }

    return $map;
}

/**
 * ソース中の名前トークンを FQCN へ解決する。
 *
 * 未 import の裸 `Cache`、および `use Cache;`（root 名前空間の class alias を import した形）は
 * Laravel の class alias で facade に解決されるため、**安全側に facade とみなす**
 * （過剰検出は目録登録で解消できるが、見落としは本番でしか気付けない）。
 *
 * @param  array<string, string>  $useMap
 */
function cachePayloadResolveName(string $raw, array $useMap): string
{
    $name = ltrim($raw, '\\');
    if (isset($useMap[$name])) {
        $name = $useMap[$name];
    } elseif (! str_contains($name, '\\')) {
        $head = strtok($name, '\\');
        if (is_string($head) && isset($useMap[$head])) {
            $name = $useMap[$head];
        }
    }

    // 名前空間を持たない `Cache` は class alias 経由の facade（`use Cache;` を含む）
    if (! str_contains($name, '\\') && strtolower($name) === 'cache') {
        return 'Illuminate\Support\Facades\Cache';
    }

    return $name;
}

/**
 * 同一ファイル内で「キャッシュ型として宣言された名前」を集める。
 *
 * 型宣言（promoted ctor param / プロパティ宣言 / 引数）の直後の変数名を拾い、
 * 変数形（`$cache->`）とプロパティ形（`$this->cache->`）の両方の受け手名として扱う。
 * 同名の別型ローカル変数を巻き込む可能性はあるが、**安全側に倒す**（誤検出は目録で解消できる）。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $useMap
 * @return list<string> `$` を除いた名前
 */
function cachePayloadReceiverNames(array $tokens, array $useMap): array
{
    $names = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }
        if (! in_array(cachePayloadResolveName($tokens[$i]->text, $useMap), CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
            continue;
        }
        // 型宣言の直後（union / nullable / intersection を跨いで）最初に現れる変数
        $j = cachePayloadNext($tokens, $i + 1);
        while ($j !== null && (
            $tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            || in_array($tokens[$j]->text, ['|', '&', '?'], true)
        )) {
            $j = cachePayloadNext($tokens, $j + 1);
        }
        if ($j !== null && $tokens[$j]->is(T_VARIABLE)) {
            $names[] = ltrim($tokens[$j]->text, '$');
        }
    }

    return array_values(array_unique($names));
}

/**
 * literal 文字列トークン（`'put'` / `"put"`）の中身。literal でなければ null。
 */
function cachePayloadLiteralValue(string $raw): ?string
{
    if (preg_match('/\A[bB]?([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\z/', $raw, $m) !== 1) {
        return null;
    }

    return $m[2];
}

/**
 * 受け手（`::` / `->` の index）から連鎖を辿ってメソッド呼び出しを分類する。
 *
 * 動的メソッド呼び出しの扱い（`CarbonOverflowArithmeticGateTest` と揃える）:
 *   - `->{'put'}(...)`（literal）は静的に決定できるので**通常形と同じに分類する**
 *   - `->{$m}(...)` / `->$m(...)`（変数形）は決定できない。**受け手が cache だと分かっている**以上
 *     素通りさせる理由が無いので `unclassified` として fail させる（実測 0 件）
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
 * 1 ファイル分の収集（純関数。fixture 文字列にも同じ関数を当てられる）。
 *
 * `writes` は **構造体**で返す（文字列に畳んでから再パースすると `strrchr` 等で壊れるため）。
 * ヘルパの配列形 `cache([...], $ttl)` は method 名 `cache` として記録する。
 *
 * @return array{writes: list<array{relative: string, line: int, method: string}>, unclassified: list<string>, methods: list<string>, cacheCalls: int, methodCalls: int, surface: bool}
 */
function cachePayloadCollectFromSource(string $source, string $relative): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $useMap = cachePayloadUseMap($tokens);
    $receiverNames = cachePayloadReceiverNames($tokens, $useMap);

    $writes = [];
    $unclassified = [];
    $methods = [];
    $cacheCalls = 0;
    $methodCalls = 0;
    $surface = false;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // 空振り検知用: `->name(` 形のメソッド呼び出し総数（受け手を問わない）
        if ($token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            $nameIndex = cachePayloadNext($tokens, $i + 1);
            $open = $nameIndex === null ? null : cachePayloadNext($tokens, $nameIndex + 1);
            if ($nameIndex !== null && $tokens[$nameIndex]->is(T_STRING)
                && $open !== null && $tokens[$open]->text === '(') {
                $methodCalls++;
            }
        }

        $operatorIndex = null;

        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $prev = cachePayloadPrev($tokens, $i - 1);
            $isMemberName = $prev !== null
                && $tokens[$prev]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST]);
            $resolved = cachePayloadResolveName($token->text, $useMap);
            // ★グローバル関数の呼び出し名は先頭 `\` を落として比較する。
            //   `\cache([...], 60)` は T_NAME_FULLY_QUALIFIED（text = '\cache'）なので、
            //   素の text 比較だと**ヘルパ書き込みの完全修飾形が丸ごと素通り**する。
            //   名前空間を含む名前（`App\cache`）は別物なので除外する。
            $callable = strtolower(ltrim($token->text, '\\'));
            $isRootCallable = ! str_contains($callable, '\\');
            $lower = $isRootCallable ? $callable : '';

            if (! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
                $surface = true; // use 文・型宣言・::class 参照でも「面」としては hit する
                $next = cachePayloadNext($tokens, $i + 1);
                if ($next !== null && $tokens[$next]->is(T_DOUBLE_COLON)) {
                    // `Cache::put(...)` / `Cache::{'put'}(...)` を followChain に委ねる。
                    // `Repository::class` は followChain が T_CLASS を見て空を返すので無害
                    $operatorIndex = $next;
                }
            }

            if (! $isMemberName && $lower === 'cache') {
                $open = cachePayloadNext($tokens, $i + 1);
                if ($open !== null && $tokens[$open]->text === '(') {
                    $surface = true;
                    $cacheCalls++;
                    $methods[] = 'cache';
                    $firstArg = cachePayloadNext($tokens, $open + 1);
                    $close = cachePayloadMatchingParen($tokens, $open);

                    if ($firstArg === null || $close === null) {
                        // 壊れたソース。何もしない
                    } elseif ($firstArg === $close) {
                        // cache() は Repository を返す = 連鎖の起点
                        $next = cachePayloadNext($tokens, $close + 1);
                        if ($next !== null && $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                            $operatorIndex = $next; // cache()->put(...)
                        }
                    } elseif ($tokens[$firstArg]->text === '[' || $tokens[$firstArg]->is(T_ARRAY)) {
                        // cache([...], $ttl) は書き込み形
                        $writes[] = ['relative' => $relative, 'line' => $token->line, 'method' => 'cache'];
                    } elseif (! $tokens[$firstArg]->is(T_CONSTANT_ENCAPSED_STRING)) {
                        // ★cache($values, 60) は $values が配列なら書き込みになる。静的に決まらない形は
                        //   deny-by-default で fail させ、Cache::put(...) 等の明示形へ書き換えさせる
                        $unclassified[] = "{$relative}:{$token->line} → cache(<静的に判定できない第 1 引数>)";
                    }
                    // 文字列リテラル引数（cache('key') / cache('key', $default)）は読み出し
                }
            }

            if (! $isMemberName && in_array($lower, ['app', 'resolve', 'make'], true)) {
                $open = cachePayloadNext($tokens, $i + 1);
                $firstArg = $open !== null && $tokens[$open]->text === '('
                    ? cachePayloadNext($tokens, $open + 1)
                    : null;
                $isCacheBinding = false;

                if ($firstArg !== null && $tokens[$firstArg]->is(T_CONSTANT_ENCAPSED_STRING)) {
                    $isCacheBinding = in_array(trim($tokens[$firstArg]->text, "'\""), ['cache', 'cache.store'], true);
                } elseif ($firstArg !== null && $tokens[$firstArg]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    // app(Repository::class) 形。`::class` であることまで確認する
                    $colon = cachePayloadNext($tokens, $firstArg + 1);
                    $classToken = $colon === null ? null : cachePayloadNext($tokens, $colon + 1);
                    $isClassConst = $colon !== null && $tokens[$colon]->is(T_DOUBLE_COLON)
                        && $classToken !== null && strtolower($tokens[$classToken]->text) === 'class';
                    $isCacheBinding = $isClassConst && in_array(
                        cachePayloadResolveName($tokens[$firstArg]->text, $useMap),
                        CACHE_PAYLOAD_RECEIVER_TYPES,
                        true
                    );
                }

                if ($isCacheBinding) {
                    $surface = true;
                    $close = $open === null ? null : cachePayloadMatchingParen($tokens, $open);
                    $next = $close === null ? null : cachePayloadNext($tokens, $close + 1);
                    if ($next !== null && $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                        $operatorIndex = $next;
                    }
                }
            }
        }

        if ($operatorIndex === null && $token->is(T_VARIABLE)) {
            $name = ltrim($token->text, '$');
            if ($name === 'this') {
                $arrow = cachePayloadNext($tokens, $i + 1);
                $propIndex = $arrow !== null && $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                    ? cachePayloadNext($tokens, $arrow + 1)
                    : null;
                if ($propIndex !== null && $tokens[$propIndex]->is(T_STRING)
                    && in_array($tokens[$propIndex]->text, $receiverNames, true)) {
                    $after = cachePayloadNext($tokens, $propIndex + 1);
                    if ($after !== null && $tokens[$after]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                        $operatorIndex = $after; // $this->cache->put(...)
                        $surface = true;
                    }
                }
            } elseif (in_array($name, $receiverNames, true)) {
                $arrow = cachePayloadNext($tokens, $i + 1);
                if ($arrow !== null && $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                    $operatorIndex = $arrow; // $cache->put(...)
                    $surface = true;
                }
            }
        }

        if ($operatorIndex === null) {
            continue;
        }

        foreach (cachePayloadFollowChain($tokens, $operatorIndex) as $call) {
            $cacheCalls++;
            $methods[] = $call['method'];
            if ($call['kind'] === 'write') {
                $writes[] = ['relative' => $relative, 'line' => $call['line'], 'method' => $call['method']];
            } elseif ($call['kind'] === 'unclassified') {
                $unclassified[] = "{$relative}:{$call['line']} → ->{$call['method']}()";
            }
        }
    }

    return [
        'writes' => $writes,
        'unclassified' => $unclassified,
        'methods' => $methods,
        'cacheCalls' => $cacheCalls,
        'methodCalls' => $methodCalls,
        'surface' => $surface,
    ];
}

/**
 * L3 の role と実測メソッドの整合違反を返す（純関数。検査 5b の正負コントロールで規則自体を固定する）。
 *
 * role の意味:
 *   write            = 任意 payload を書く（L2 目録にも登録が要る）
 *   no-payload-write = キャッシュに触れるが**任意 payload を書く API を呼ばない**
 *                      （読み出し / 削除 / flush / increment などが該当。
 *                        「read-only」という名前は flush や forget を含む実態と合わないため使わない）
 *   lock-only        = 排他（`lock` / `restoreLock`）しか使わない
 *
 * @param  list<string>  $methods 実測メソッド（全小文字）
 * @return list<string> 違反理由。空なら整合
 */
function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry): array
{
    if (! in_array($role, ['write', 'no-payload-write', 'lock-only'], true)) {
        return ["role は write / no-payload-write / lock-only のいずれか（宣言値: {$role}）"];
    }

    if ($role === 'write') {
        return $hasWriteEntry ? [] : ['role=write なのに書き込み目録に entry がありません'];
    }

    $violations = [];
    if ($hasWriteEntry) {
        $violations[] = "role={$role} なのに書き込み目録に entry があります";
    }
    if ($methods === []) {
        $violations[] = "role={$role} なのにキャッシュ API 呼び出しが 1 件もありません"
            .'（使わなくなったなら import ごと消す）';
    }

    if ($role === 'lock-only') {
        $extra = array_values(array_diff($methods, ['lock', 'restorelock']));
        if ($extra !== []) {
            $violations[] = 'role=lock-only なのに排他以外のキャッシュ API を呼んでいます: '.implode(', ', $extra);
        }

        return $violations;
    }

    // no-payload-write: 任意 payload を書かない API と連鎖 API だけを許す
    // （TERMINAL の lock / mock は別 role・別責務なのでここには入れない）
    $allowed = array_merge(CACHE_PAYLOAD_NON_WRITE_METHODS, CACHE_PAYLOAD_CHAIN_METHODS, ['cache']);
    $extra = array_values(array_diff($methods, $allowed));
    if ($extra !== []) {
        $violations[] = 'role=no-payload-write なのに payload を書く / 排他・mock の API を呼んでいます: '
            .implode(', ', $extra);
    }
    // CHAIN だけで終わる形（受け手を取り回しているのに何もしない）は role の意味を壊すので終端を要求する
    if (array_intersect($methods, array_merge(CACHE_PAYLOAD_NON_WRITE_METHODS, ['cache'])) === []) {
        $violations[] = 'role=no-payload-write なのに終端の操作（読み出し・削除等）がありません';
    }

    return $violations;
}

/**
 * 走査対象全体の収集結果（同一プロセス内で 1 度だけ計算する）。
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
        if (! is_string($source)) {
            continue;
        }
        $files++;
        $collected = cachePayloadCollectFromSource($source, $target['relative']);
        $cacheCalls += $collected['cacheCalls'];
        $methodCalls += $collected['methodCalls'];

        foreach ($collected['writes'] as $write) {
            $writeSites[] = "{$write['relative']}:{$write['line']} → {$write['method']}()";
            $key = $write['relative'].'::'.strtolower($write['method']);
            $writeCounts[$key] = ($writeCounts[$key] ?? 0) + 1;
        }
        $unclassified = array_merge($unclassified, $collected['unclassified']);

        if ($collected['surface']) {
            $surfaces[$target['relative']] = $collected['methods'];
        }
    }

    ksort($writeCounts);
    ksort($surfaces);
    sort($writeSites);

    $cached = [
        'writeCounts' => $writeCounts,
        'writeSites' => $writeSites,
        'unclassified' => $unclassified,
        'surfaces' => $surfaces,
        'cacheCalls' => $cacheCalls,
        'methodCalls' => $methodCalls,
        'files' => $files,
    ];

    return $cached;
}

// ---------------------------------------------------------------------------
// 検査 1: L1 語彙
// ---------------------------------------------------------------------------

test('検査 1: キャッシュ受け手に対する未分類の API 呼び出しが無い', function (): void {
    $result = cachePayloadCollectAll();

    expect($result['unclassified'])->toBe([],
        'キャッシュ受け手に対して 4 分類（WRITE / NON_WRITE / CHAIN / TERMINAL）のどれにも属さない API が'
        .'呼ばれています。payload を書くなら CACHE_PAYLOAD_WRITE_METHODS へ、書かないなら'
        .'CACHE_PAYLOAD_NON_WRITE_METHODS へ**理由を添えて**分類してください。'
        .PHP_EOL.implode(PHP_EOL, $result['unclassified']));
});

// ---------------------------------------------------------------------------
// 検査 2-3: L2 書き込み経路
// ---------------------------------------------------------------------------

test('検査 2: キャッシュ書き込み経路が目録と exact-fit で一致する', function (): void {
    $result = cachePayloadCollectAll();

    $declared = [];
    foreach (CACHE_PAYLOAD_WRITE_INVENTORY as $key => $entry) {
        $declared[$key] = $entry['count'];
    }
    ksort($declared);

    expect($result['writeCounts'])->toBe($declared,
        'キャッシュ書き込み経路が目録と一致しません（deny-by-default）。'
        .'新しい経路を足したなら CACHE_PAYLOAD_WRITE_INVENTORY へ '
        .'count / payload / proof（往復を固定する単体テストのパス）/ rationale（30 文字以上）を'
        .'添えて登録してください。経路を消したなら目録からも消してください。'
        .PHP_EOL.'検出: '.implode(PHP_EOL, $result['writeSites']));
});

test('検査 3: 目録の各 entry が形式要件を満たす', function (): void {
    expect(CACHE_PAYLOAD_WRITE_INVENTORY)->not->toBe([]);

    foreach (CACHE_PAYLOAD_WRITE_INVENTORY as $key => $entry) {
        [$path, $method] = explode('::', $key, 2);

        expect($entry['count'])->toBeGreaterThanOrEqual(1, "{$key}: count は 1 以上");
        // key のメソッド名は全小文字。'cache' はヘルパの配列形 cache([...], $ttl) 専用の名前。
        expect(in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) || $method === 'cache')
            ->toBeTrue("{$key}: key のメソッドが WRITE 語彙にありません");
        expect(is_file(base_path($path)))->toBeTrue("{$key}: 対象ファイルが実在しません");
        expect(is_file(base_path($entry['proof'])))->toBeTrue(
            "{$key}: proof に指定した単体テスト {$entry['proof']} が実在しません。"
            .'キャッシュへ入れる配列は「往復が壊れないこと」を単体テストで固定してください');
        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
        expect(mb_strlen($entry['payload']))->toBeGreaterThanOrEqual(10, "{$key}: payload の説明が短すぎます");
    }
});

// ---------------------------------------------------------------------------
// 検査 4-5: L3 面
// ---------------------------------------------------------------------------

test('検査 4: キャッシュに触れるファイル集合が目録と exact-fit で一致する', function (): void {
    $result = cachePayloadCollectAll();

    $found = array_keys($result['surfaces']);
    $declared = array_keys(CACHE_PAYLOAD_SURFACE_INVENTORY);
    sort($found);
    sort($declared);

    expect($found)->toBe($declared,
        'キャッシュに触れるファイルの集合が目録と一致しません（deny-by-default）。'
        .'復旧手順: payload を書くファイルなら role=write で登録し **CACHE_PAYLOAD_WRITE_INVENTORY にも**'
        .'登録する / 読み出し・削除しかしないなら role=no-payload-write / Cache::lock しか使わないなら role=lock-only。'
        .'いずれも 30 文字以上の rationale が要ります。');
});

test('検査 5: 目録が宣言した role が実測と整合する', function (): void {
    $result = cachePayloadCollectAll();

    foreach (CACHE_PAYLOAD_SURFACE_INVENTORY as $path => $entry) {
        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$path}: rationale が短すぎます");

        $methods = array_map('strtolower', $result['surfaces'][$path] ?? []);

        $hasWrite = false;
        foreach (array_keys(CACHE_PAYLOAD_WRITE_INVENTORY) as $writeKey) {
            if (str_starts_with($writeKey, $path.'::')) {
                $hasWrite = true;
                break;
            }
        }

        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite))
            ->toBe([], "{$path}: 宣言した role が実測と整合しません");
    }
});

test('検査 5b: role 判定規則そのものの正負コントロール', function (): void {
    // ★実在ファイルの構成に依存せず判定規則を固定する。現状 no-payload-write の entry は 0 件なので、
    //   ここが無いと「実装を反転させても 21 テストが緑のまま」という穴が空く（design-review Round 4 反映）。
    expect(cachePayloadRoleViolations('write', ['get', 'forget', 'put'], true))->toBe([]);
    expect(cachePayloadRoleViolations('write', ['get'], false))->not->toBe([]);

    expect(cachePayloadRoleViolations('lock-only', ['lock'], false))->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', ['lock', 'restorelock'], false))->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', [], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', ['lock', 'get'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', ['lock'], true))->not->toBe([]);

    expect(cachePayloadRoleViolations('no-payload-write', ['get'], false))->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['store', 'get'], false))->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['forget'], false))->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', [], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['store'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['lock'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['put'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['get'], true))->not->toBe([]);

    expect(cachePayloadRoleViolations('unknown-role', ['get'], false))->not->toBe([]);
});

// ---------------------------------------------------------------------------
// 検査 6: serializable_classes の実行時 pin
// ---------------------------------------------------------------------------

test('検査 6: serializable_classes は実行時にも false（クラス許可一覧を持たない）', function (): void {
    // ★ここで false と null を区別することが本質。null / キー欠落だと Laravel は
    //   制限なしの unserialize() に戻る（CacheManager::serializableClasses() + 各 store）。
    expect(config('cache.serializable_classes'))->toBeFalse();

    /** @var array<string, mixed> $stores */
    $stores = config('cache.stores');
    foreach (array_keys($stores) as $store) {
        expect(config("cache.stores.{$store}.serializable_classes"))
            ->toBe(null, "store {$store} が serializable_classes を上書きしています");
    }
});

test('検査 6b: 語彙表が健全（4 分類は互いに素 / 除外型は受け手型と重ならない）', function (): void {
    // ★同じメソッドが 2 つの分類に入ると match の順序で暗黙に勝敗が決まり、
    //   「WRITE のつもりが NON_WRITE として素通り」が静かに起きる。互いに素であることを固定する。
    $groups = [
        'WRITE' => CACHE_PAYLOAD_WRITE_METHODS,
        'NON_WRITE' => CACHE_PAYLOAD_NON_WRITE_METHODS,
        'CHAIN' => CACHE_PAYLOAD_CHAIN_METHODS,
        'TERMINAL' => CACHE_PAYLOAD_TERMINAL_METHODS,
    ];
    $all = array_merge(...array_values($groups));
    expect(count($all))->toBe(count(array_unique($all)), '同じメソッドが複数の分類に属しています');
    foreach ($groups as $name => $methods) {
        expect($methods)->toBe(array_map('strtolower', $methods), "{$name} は全小文字で書くこと");
    }

    // 明示除外した型（Lock / RateLimiter 等）が受け手型に混ざっていないこと。
    // 混ざると Cache::lock の 9 か所が全部 fail する。
    expect(array_intersect(array_keys(CACHE_PAYLOAD_EXCLUDED_TYPES), CACHE_PAYLOAD_RECEIVER_TYPES))
        ->toBe([], '除外型が受け手型に混ざっています');
    foreach (CACHE_PAYLOAD_EXCLUDED_TYPES as $type => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(5, "{$type}: 除外理由を書くこと");
    }
});

// ---------------------------------------------------------------------------
// 検査 7-8: 空振り検知と自己参照コントロール
// ---------------------------------------------------------------------------

test('検査 7: 走査が空振りしていない', function (): void {
    $result = cachePayloadCollectAll();

    expect($result['files'])->toBeGreaterThan(0, '走査対象ファイルが 0 件（ディレクトリ構成の変更を疑う）');
    expect($result['methodCalls'])->toBeGreaterThan(0, 'メソッド呼び出しを 1 件も見ていない（token 走査が死んでいる）');
    expect($result['cacheCalls'])->toBeGreaterThan(0, 'キャッシュ受け手を 1 件も解決できていない（受け手解決が死んでいる）');
    expect($result['surfaces'])->not->toBe([], 'キャッシュに触れるファイルを 1 件も検出できていない');
});

test('検査 8: 自己参照コントロール（本 gate 自身は書き込み経路にも面にも現れない）', function (): void {
    $result = cachePayloadCollectAll();
    $self = 'tests/Architecture/CachePayloadPlainDataGateTest.php';

    // fixture は nowdoc（文字列トークン）なので code として走査されない。
    // 将来ここに code としてキャッシュ呼び出しを書いたら落ちる = 正しい挙動。
    expect(array_key_exists($self, $result['surfaces']))->toBeFalse();
    expect(array_filter($result['writeSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
});

// ---------------------------------------------------------------------------
// 正負コントロール（走査ロジックの固定）
// ---------------------------------------------------------------------------

test('負のコントロール: facade / チェーン / ヘルパ / DI の書き込みを検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Contracts\Cache\Repository;
    class Fixture {
        public function __construct(private readonly Repository $cache) {}
        public function run(Repository $other, $dto): void {
            Cache::put('a', [1], 60);
            Cache::add('b', 'x', 60);
            Cache::forever('c', 1);
            Cache::remember('d', 60, fn () => [1]);
            Cache::store('redis')->put('e', [1], 60);
            Cache::tags(['t'])->forever('f', [1]);
            cache()->put('g', [1], 60);
            cache(['h' => [1]], 60);
            $this->cache->put('i', [1], 60);
            $other->rememberForever('j', fn () => [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(10);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('負のコントロール: コンテナ解決・getStore・literal 動的呼び出しの書き込みを検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    use Illuminate\Support\Facades\Cache;
    class Fixture {
        public function run(): void {
            app(Repository::class)->put('a', [1], 60);
            resolve('cache')->forever('b', [1]);
            app('cache.store')->add('c', [1], 60);
            Cache::getStore()->put('d', [1], 60);
            Cache::{'put'}('e', [1], 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(5);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('負のコントロール: 完全修飾のヘルパ / コンテナ呼び出しも検出する', function (): void {
    // ★`\cache(...)` は T_NAME_FULLY_QUALIFIED（text = '\cache'）。先頭 `\` を落として
    //   比較しないと、ヘルパ書き込みの完全修飾形だけが素通りする（design-review Round 2 反映）。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    class Fixture {
        public function run(array $values): void {
            \cache(['a' => [1]], 60);
            \cache($values, 60);
            \app(Repository::class)->put('b', [1], 60);
            \app('cache')->forever('c', [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(3);       // \cache([...]) + \app(...)->put + \app('cache')->forever
    expect($result['unclassified'])->toHaveCount(1); // \cache($values, 60)
    expect($result['surface'])->toBeTrue();
});

test('正のコントロール: 名前空間付きの同名関数はヘルパと見なさない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function run(array $values): void {
            \App\Support\cache($values, 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeFalse();
});

test('負のコントロール: 静的に判定できない形は fail させる', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    class Fixture {
        public function __construct(private readonly Repository $cache) {}
        public function run(array $values, string $method): void {
            cache($values, 60);
            $this->cache->{$method}('k', $values, 60);
            $this->cache->$method('k', $values, 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    // cache($values, 60)（配列なら書き込み）/ 変数動的メソッド 2 形の計 3 件
    expect($result['unclassified'])->toHaveCount(3);
    expect($result['writes'])->toBe([]);
});

test('正のコントロール: cache() の読み出し形は書き込みに数えない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function run(): void {
            $a = cache('key');
            $b = cache('key', 'default');
            $c = cache()->get('key');
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('正のコントロール: use Cache; 形でも facade として解決する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Cache;
    class Fixture {
        public function run(): void {
            Cache::put('a', [1], 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(1);
    expect($result['surface'])->toBeTrue();
});

test('正のコントロール: session / disk の put を巻き込まない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function __construct(private readonly \Illuminate\Contracts\Session\Session $session) {}
        public function run($request): void {
            session()->put('recent_auth_at', 1);
            $this->session->put('k', 'v');
            $request->session()->put('invitation_token', 'x');
            $this->disk()->put('a/b', 'c');
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['surface'])->toBeFalse();
    expect($result['methodCalls'])->toBeGreaterThan(0); // 走査自体は生きている
});

test('正のコントロール: Cache::lock とその後続を書き込みに数えない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    class Fixture {
        public function run(): void {
            $lock = Cache::lock('billing:x', 10);
            $lock->get();
            $lock->release();
            Cache::lock('billing:y', 10)->block(1, fn () => 'done');
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
    expect(array_map('strtolower', $result['methods']))->toBe(['lock', 'lock']);
    expect($result['surface'])->toBeTrue(); // 面としては hit する（role=lock-only で登録が要る）
});

test('正のコントロール: コメント・文字列・nowdoc 中の記述を誤検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        /** 例: Cache::put('k', $dto, 60) と書いてはいけない */
        public function run(): void {
            // Cache::forever('k', $object);
            $doc = "Cache::put('k', $v, 60)";
            $here = <<<'INNER'
            Cache::add('k', new stdClass, 60);
            INNER;
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['surface'])->toBeFalse();
});

test('負のコントロール: 未知のキャッシュ API は未分類として検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    class Fixture {
        public function run(): void {
            Cache::putEverything('k', [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['unclassified'])->toHaveCount(1);
    expect($result['writes'])->toBe([]);
});

test('正のコントロール: 排他・レート制限の型は受け手にしない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Lock;
    use Illuminate\Cache\RateLimiter;
    class Fixture {
        public function __construct(private readonly Lock $lock, private readonly RateLimiter $limiter) {}
        public function run(): void {
            $this->lock->get();
            $this->limiter->hit('key', 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeFalse();
});
```

### PHPStan 適合チェック

- [x] 全ヘルパ関数に戻り値型を明示（`array{...}` の shape を `@return` に inline で書く。
      `@phpstan-type` の別名定義はクラス PHPDoc か設定ファイルが要るため、既存 gate と同じ inline shape に揃える）
- [x] `PhpToken::tokenize()` の戻り値は `/** @var list<PhpToken> */` で narrow（既存 gate と同形）
- [x] `file_get_contents` / `getRealPath` の `false` を `is_string` で早期 return
- [x] `config()` の戻り値 `mixed` は `expect()` へ渡すだけ。`array_keys()` に渡す前に
      `/** @var array<string, mixed> */` で narrow
- [x] `strrchr()` は `string|false` を返すため `(string)` cast 済み
- [x] DTO を返す関数は無い（静的検査ヘルパのみ。配列返却は Architecture テスト内の局所構造）
- [x] Generics は使用しない

### テスト計画

本施策そのものがテストである。受入条件は以下。

- [ ] `composer test -- --filter=CachePayloadPlainDataGate` が緑（22 テスト）
- [ ] **mutation で赤化を確認**（下表 M1-M13。1 件ずつ注入 → 赤を確認 → revert）。
      素の main では緑になる予防 gate なので、**赤を一度も見ずに完了報告しない**（禁止事項 1 / 思考原則 5）
- [ ] `composer phpstan` 緑 / `vendor/bin/pint --test` 緑
- [ ] 個別 `DatabaseTransactions` を使っていない（DB を一切使わない）

#### mutation チェックリスト（実装時に実行し、結果を実装報告に記録する）

| # | 注入する変更 | 期待する赤 |
|---|------------|-----------|
| M1 | `app/Support/Tmp.php` を新規作成し `Cache::put('k', new stdClass, 60);` を書く | 検査 2（未登録の書き込み経路）+ 検査 4（未登録の面） |
| M2 | `CACHE_PAYLOAD_WRITE_INVENTORY` の `count` を 2 にする | 検査 2（件数のズレ） |
| M3 | `CACHE_PAYLOAD_WRITE_INVENTORY` から FxRateService entry を削除 | 検査 2（未登録） |
| M4 | `proof` を実在しないパスに書き換える | 検査 3（proof 不在） |
| M5 | `config/cache.php` の `serializable_classes` を `[FxSnapshotDto::class]` にする | 検査 6 + S3 の宣言 pin |
| M6 | `config/cache.php` から `serializable_classes` 行を**削除**する | 検査 6（null は false ではない = fail-open の検出） |
| M7 | 新規ファイルで `Illuminate\Contracts\Cache\Repository` を DI し `$this->cache->put(...)` を書く | 検査 4（面の未登録）+ 検査 2 |
| M8 | `FxRateService` に `Cache::flexible('k', [1, 2], fn () => [])` を追加 | 検査 2（WRITE 語彙で拾われ未登録） |
| M9 | `CACHE_PAYLOAD_SCAN_DIRS` を `[]` にする | 検査 7（files = 0） |
| M10 | `app/Security/RecentAuthState.php` に `session()->put('x', 1);` を 1 行足す | **緑のまま**（誤検出しないことの確認。赤くなったら受け手解決が壊れている） |
| M11 | 新規ファイルで `cache($values, 60);`（変数の第 1 引数）を書く | 検査 1（静的に判定できない形として unclassified）+ 検査 4 |
| M12 | 新規ファイルで `Cache::getStore()->put('k', new stdClass, 60);` を書く | 検査 2（getStore が CHAIN なので put が拾われ未登録） |
| M13 | 新規ファイルで `\cache(['k' => new stdClass], 60);`（完全修飾ヘルパ）を書く | 検査 2（WRITE として拾われ未登録） |

M1 / M7 / M8 / M10 / M11 / M12 / M13 に相当する**分類の退行**は負のコントロール fixture として
**恒久的に**テストへ残る（例: `getstore` を CHAIN から外すと fixture の期待件数 5 が 4 になって落ちる）。
mutation は「実装時の一度きりの受入手順」であり、以後の回帰は fixture が担う。

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| 受け手解決の過剰検出（同名ローカル変数） | 無関係な `$cache->put()` が拾われる | 実測 0 件。拾われたら目録に登録すればよい（安全側に倒す設計であることを冒頭コメントに明記） |
| `use` グループ構文（`use A\{B, C};`）を扱わない | 面の見落とし | 実測 0 件。`NoNonCompoundGlobalUseTest` が別途 use の書き方を縛っている。限界としてコメントに明記 |
| L3 の摩擦（キャッシュを使うファイルが増えるたび申告） | 開発速度 | fail message に role 別の復旧手順を書く。5 年で 5 ファイルという実績から頻度は低い |
| 走査時間の増加 | CI 時間 | `app` + `routes` + `database` + `tests` の 1 パス。既存の同型 gate と同程度（秒未満〜数秒）。`static $cached` で 1 プロセス 1 回に抑える |
| 目録が「儀式」になる（登録さえすれば object を入れられる） | gate の形骸化 | `proof`（往復テストの実在）を必須にし、`payload` 欄で式を申告させる。**静的に payload の型は見ない**限界を冒頭に明記 |

---

## S2: FxSnapshotDto 往復の単体テスト

### 変更箇所

- 新規ファイル: `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし（`FxSnapshotDto` は変更しない）
- テストファイル: 本ファイルが新規。S1 の目録 `proof` から参照される（**S1 と同一 PR で入れる必要がある**）

### 現行コード

`app/DataTransferObjects/FxSnapshotDto.php`（変更しない）:

```php
final readonly class FxSnapshotDto implements Arrayable
{
    public function __construct(
        public float $rate,
        public string $pair,
        public string $source,
        public CarbonImmutable $fetchedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'pair' => $this->pair,
            'source' => $this->source,
            'fetched_at' => $this->fetchedAt->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        Assert::keyExists($data, 'rate');
        // … keyExists ×4 / numeric / greaterThan(0) / stringNotEmpty ×3
    }
}
```

テストは tests/ 全体で 0 件（`grep -rln "FxSnapshotDto" tests/` が 0 件）。

### 変更後コード

```php
<?php

declare(strict_types=1);

/*
 * FxSnapshotDto の **配列往復**を固定する（lctl 標準形 v1「配列への変換と復元の往復が
 * 壊れないことを単体テストで固定する。キャッシュ経路を通す必要はない」）。
 *
 * この DTO は FxRateService が cache へ入れる唯一の payload の作り手であり、
 * tests/Architecture/CachePayloadPlainDataGateTest.php の目録が proof として本ファイルを指す
 * （proof のファイルが消えたら gate が落ちる）。
 *
 * DB 不使用。Factory を使うモデルは登場しない。
 */

use App\DataTransferObjects\FxSnapshotDto;
use Carbon\CarbonImmutable;
use Webmozart\Assert\InvalidArgumentException;

/**
 * 正常系の素データ（cache に入る形そのもの）。
 *
 * @return array{rate: float, pair: string, source: string, fetched_at: string}
 */
function fxSnapshotPlainArray(): array
{
    return [
        'rate' => 151.23,
        'pair' => 'USDJPY',
        'source' => 'frankfurter',
        'fetched_at' => '2026-08-07T12:34:56+09:00',
    ];
}

test('toArray → fromArray の往復で値が一致する', function (): void {
    $original = new FxSnapshotDto(
        rate: 151.23,
        pair: 'USDJPY',
        source: 'frankfurter',
        fetchedAt: CarbonImmutable::parse('2026-08-07T12:34:56+09:00'),
    );

    $restored = FxSnapshotDto::fromArray($original->toArray());

    expect($restored->rate)->toBe($original->rate)
        ->and($restored->pair)->toBe($original->pair)
        ->and($restored->source)->toBe($original->source)
        // ISO8601 は秒精度。ミリ秒は仕様として落ちるため文字列で比較する
        ->and($restored->fetchedAt->toIso8601String())->toBe($original->fetchedAt->toIso8601String());
});

test('toArray は素のデータだけを返す（オブジェクトを含まない）', function (): void {
    $array = (new FxSnapshotDto(
        rate: 151.23,
        pair: 'USDJPY',
        source: 'frankfurter',
        fetchedAt: CarbonImmutable::parse('2026-08-07T12:34:56+09:00'),
    ))->toArray();

    // ★これが「キャッシュに入れてよいのは素のデータだけ」の DTO 側の表明。
    //   CarbonImmutable をそのまま載せる退行（本番の database store でだけ壊れる）を落とす。
    foreach ($array as $key => $value) {
        expect(is_scalar($value))->toBeTrue("{$key} が素のデータではありません");
    }
    expect(array_keys($array))->toBe(['rate', 'pair', 'source', 'fetched_at']);
});

test('fromArray は必須キーの欠損を拒否する', function (string $missing): void {
    $data = fxSnapshotPlainArray();
    unset($data[$missing]);

    expect(fn () => FxSnapshotDto::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with(['rate', 'pair', 'source', 'fetched_at']);

test('fromArray は不正値を拒否する', function (string $key, mixed $value): void {
    $data = fxSnapshotPlainArray();
    $data[$key] = $value;

    expect(fn () => FxSnapshotDto::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
    'rate が非数値' => ['rate', 'abc'],
    'rate が 0' => ['rate', 0],
    'rate が負' => ['rate', -1.5],
    'pair が空' => ['pair', ''],
    'source が空' => ['source', ''],
    'fetched_at が空' => ['fetched_at', ''],
]);

test('fromArray は数値文字列の rate を float として復元する', function (): void {
    // 永続化済みの古い payload や外部入力由来で rate が文字列になっていても、
    // Assert::numeric を通したうえで float に正規化されることを固定する。
    $data = fxSnapshotPlainArray();
    $data['rate'] = '151.23';

    expect(FxSnapshotDto::fromArray($data)->rate)->toBe(151.23);
});

test('fromArray は解釈できない fetched_at を例外にする', function (): void {
    // ★空文字は Assert::stringNotEmpty が弾くが、'not-a-date' は Assert を通過して
    //   CarbonImmutable::parse() が InvalidFormatException を投げる（実測で確認済み）。
    //   壊れた cache payload の代表ケースなので、Assert 側とは別テストとして固定する。
    //   振る舞い上の契約は「FxRateService が Throwable を catch して Cache::forget する」なので、
    //   どちらの例外型でも安全側に倒れる。
    $data = fxSnapshotPlainArray();
    $data['fetched_at'] = 'not-a-date';

    expect(fn () => FxSnapshotDto::fromArray($data))
        ->toThrow(Carbon\Exceptions\InvalidFormatException::class);
});
```

### PHPStan 適合チェック

- [x] `fxSnapshotPlainArray()` に `@return array{rate: float, pair: string, source: string, fetched_at: string}` を付ける
- [x] dataset のクロージャ引数に型を明示（`string $key, mixed $value`）
- [x] `toThrow` へ渡すのは `InvalidArgumentException::class`（`Webmozart\Assert` の例外型を**型まで**固定）
- [x] DTO を返している（配列返却の新設なし）

### テスト計画

- [ ] 新規テスト `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` — 往復一致 / 素データ性 /
      キー欠損 4 ケース / 不正値 6 ケース / 数値文字列の復元 / 解釈不能な fetched_at
      （dataset 展開後 **14 ケース**）
- [ ] `composer test -- --filter=FxSnapshotDto` が緑
- [ ] 個別 `DatabaseTransactions` を使わない（DB 不使用）
- [ ] Unit lane のグローバル guard（StrayLlmCallGuard / StrayHttpRequestGuard）に触れない
      （HTTP も LLM も呼ばない純粋な値変換のみ）

### リスク

- `Assert::numeric` は `'abc'` を弾くが `'0'` は numeric なので `greaterThan(0)` 側で弾かれる。
  順序依存があるためテストは**両方**をケースに持つ（`rate が 0` と `rate が非数値`）
- タイムゾーン: `toIso8601String()` はオフセット付き。テストは固定文字列 `+09:00` を使い、
  実行環境の TZ に依存しない

---

## S3: `serializable_classes` 宣言の pin

### 変更箇所

- `tests/Feature/Config/ConfigHardeningTest.php` の「cache: カスタム storage store は持たない」節（末尾）に追記

### 波及変更

- なし（既存 helper `evaluateConfigFileWithEnv()` を再利用）

### 現行コード

```php
// ========== cache: カスタム storage store は持たない ==========

test('cache.stores にカスタム storage store が存在しない', function (): void {
    $config = evaluateConfigFileWithEnv('cache.php', []);

    expect($config['stores']['storage'] ?? null)->toBeNull();
});
```

### 変更後コード

```php
// ========== cache: カスタム storage store は持たない ==========

test('cache.stores にカスタム storage store が存在しない', function (): void {
    $config = evaluateConfigFileWithEnv('cache.php', []);

    expect($config['stores']['storage'] ?? null)->toBeNull();
});

// ========== cache: 逆シリアライズの許可一覧を持たない ==========

test('config/cache.php は serializable_classes を false で宣言している', function (): void {
    // ★`false` と「キー欠落」は等価ではない。CacheManager は
    //   `config['cache.serializable_classes'] ?? null` を読み、各 store は
    //   `if ($this->serializableClasses !== null)` のときだけ allowed_classes を渡す。
    //   キーを消すと制限なしの unserialize() に戻る = fail-open。
    //   したがって「宣言が存在すること」と「値が false であること」を分けて固定する。
    $config = evaluateConfigFileWithEnv('cache.php', []);

    expect(array_key_exists('serializable_classes', $config))->toBeTrue(
        'serializable_classes の宣言が消えると Laravel は制限なしの unserialize() に戻る');
    expect($config['serializable_classes'])->toBeFalse(
        'クラス許可一覧は作らない（lctl 標準形 v1 / AGENTS.md セキュリティ不変条件 11）');
});
```

### PHPStan 適合チェック

- [x] `evaluateConfigFileWithEnv()` の戻り値は既存 docblock で `array<string, mixed>`。
      `array_key_exists` / `expect()` に渡すだけで narrow 不要
- [x] 新しい配列返却関数を作らない

### テスト計画

- [ ] 新規テスト 1 本（既存ファイルへの追記。**既存テストの削除・上書きはしない**）
- [ ] mutation M5 / M6 で赤化を確認
- [ ] `composer test -- --filter=ConfigHardening` が緑

### リスク

- S1 検査 6（実行時値の pin）と役割が重なるように見えるが、守る失敗モードが違う:
  S3 は「テンプレートの config ファイルから宣言が消える / 変わる」、S1 検査 6 は
  「provider や package が実行時に上書きする」。両方 1 行ずつなので統合しない
  （統合すると config ファイル評価と実行時解決のどちらかが検査対象から落ちる）

---

## S4: guide §7 不変条件 6 の誤情報訂正

### 変更箇所

- `docs/app-integration-guide.md` の 213-214 行（§7 チェックリストの項目 6）

### 波及変更

- **§7 の採番は動かさない**（AGENTS.md 71-75 行が renumber を禁止。既存参照
  「§7 不変条件 8」/ stripe webhook migration の「不変条件 7」が壊れるため）。
  項目 6 の**本文だけ**を差し替える
- テストファイル: なし（guide 本文を pin するテストは存在しない。`grep -rln app-integration-guide tests/` = 0 件）

### 現行コード

```markdown
6. **任意 class の逆シリアライズを許さない**(cache serializable_classes は既定 false。
   object cache が必要になったときだけ最小 allowlist)
```

後半「object cache が必要になったときだけ最小 allowlist」が **canonical v1 の裁定
（許可一覧は使わない・例外を作らない）と正面から矛盾する**。この記述を信じた実装者は
`serializable_classes` に class を足す方向へ誘導される。

### 変更後コード

```markdown
6. **任意 class の逆シリアライズを許さない / キャッシュに入れるのは素のデータだけ**:
   `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧は作らない
   (例外を作らない)。**キーごと消すのも不可** — Laravel は宣言が無いと制限なしの
   `unserialize()` に戻る(fail-open)。cache へ渡してよいのは配列 / 文字列 / 数値 / 真偽値だけで、
   オブジェクトは `toArray()` で素の配列にしてから入れ、読み戻しは `fromArray()` 等で
   **明示的に組み立て直して検査し、失敗したら `forget`** する
   (準拠実装: `App\Services\FxRateService` + `App\DataTransferObjects\FxSnapshotDto`)。
   **テストレーンは array store(`serialize => false`)なのでオブジェクトを入れても緑になる** —
   本番の database store でだけ壊れるため、静的検査で塞ぐ:
   キャッシュ書き込み経路とキャッシュに触れるファイルは
   `tests/Architecture/CachePayloadPlainDataGateTest.php` の目録へ登録必須(deny-by-default)。
   配列往復は `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` が固定する
```

### PHPStan 適合チェック

- 対象外（Markdown）

### テスト計画

- [ ] `grep -n "最小 allowlist" docs/app-integration-guide.md` が 0 件になること（実装時に目視 + grep）
- [ ] 番号 `6.` が動いていないこと（前後の 5. / 7. の番号が不変であること）を diff で確認

### リスク

- 誤訂正で他の参照を壊す可能性 → 番号を触らないので参照は壊れない。
  本文中に新しく参照を増やすのは gate / 単体テストのパス 2 つだけで、いずれも同 PR で実在する

---

## S5: AGENTS.md セキュリティ不変条件 11 の追記

### 変更箇所

- `AGENTS.md` の「セキュリティ不変条件(アプリ都合で緩めない)」節、既存 10 の直後（69 行目付近）に **11** を追加

### 波及変更

- 既存 1-10 の番号は**触らない**（71-75 行の採番注意書きが renumber を禁止）
- 採番注意書き（71-75 行）自体も**書き換えない**（既存の対応例の列挙であり、11 を足しても嘘にならない）
- テストファイル: なし（AGENTS.md 本文を pin するテストは
  `tests/js/architecture/verification-commands-doc-sync.test.ts` のみで、対象は
  `VERIFICATION_COMMANDS` マーカー区間。今回触らない）

### 現行コード

```markdown
10. **層 2 は binding の直後・FormRequest より前で閉じる**: …
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
```

### 変更後コード

```markdown
10. **層 2 は binding の直後・FormRequest より前で閉じる**: …
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)
11. **キャッシュに入れるのは素のデータだけ**: cache へ渡す値は配列 / 文字列 / 数値 / 真偽値に限る
    (オブジェクトを直接入れない)。読み戻しは `fromArray()` 等で**明示的に組み立て直して検査**し、
    失敗したら `forget` する(準拠実装 `FxRateService` + `FxSnapshotDto`)。
    `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧を作らず、
    **キーごと消さない**(宣言が無いと制限なしの `unserialize()` に戻る = fail-open)。
    **テストは array store で緑になり本番 database store でだけ壊れる**ため、
    書き込み経路とキャッシュに触れるファイルは deny-by-default の目録で強制する
    (`CachePayloadPlainDataGateTest` / 宣言 pin は `ConfigHardeningTest`。
    guide §7 不変条件 6 と対応)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
```

### PHPStan 適合チェック

- 対象外（Markdown）

### テスト計画

- [ ] `pnpm test` の `verification-commands-doc-sync.test.ts` が緑（マーカー区間を触っていないことの確認）
- [ ] 既存 1-10 の番号が diff に現れないこと

### リスク

- **並列作業との conflict**: 同節の末尾に追記する設計のため、他の設計が同じ節に 11 を足すと
  番号衝突する。実装時に main の最新を取り込み、既存の最大番号 + 1 に採り直す。
  番号は「末尾に足す」という規約であって 11 という値に意味は無い。
  **値が変わったら次の 3 箇所を必ず同期する**（実装時のチェックリスト）:
  1. `AGENTS.md` の新項目の番号そのもの
  2. `tests/Architecture/CachePayloadPlainDataGateTest.php` 冒頭コメントの
     「AGENTS.md セキュリティ不変条件 11」
  3. `tests/Feature/Config/ConfigHardeningTest.php` の追加テスト内メッセージ
     「AGENTS.md セキュリティ不変条件 11」
  （`docs/app-integration-guide.md` §7 の番号 6 は**動かさない**ので同期不要）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規テスト 2 本 + 既存テスト 1 本への追記 + 文書 2 箇所で完結し、アプリコードに依存も影響も無い。他の設計中タスクと共有する変更点が `AGENTS.md` の 1 節だけ |
| 競合リスク | **中（文書のみ）**。`AGENTS.md` セキュリティ不変条件の末尾追記と `docs/app-integration-guide.md` §7 は他タスクも触りうる。コードの競合はゼロ（`tests/Architecture/` の新規ファイル 1 本 + `tests/Unit/` の新規ファイル 1 本 + `ConfigHardeningTest` 末尾追記）。マージ時は main を取り込んで番号を採り直す |
| 実装順序 | S1 gate → mutation で赤化確認（M1-M13）→ S2 単体テスト → S3 pin → S4/S5 文書。**S1 の目録 `proof` が S2 のファイルを指すため、S1 と S2 は同一 PR で入れる** |
| 検証 | `composer test` / `composer phpstan` / `vendor/bin/pint --test`。フロント差分ゼロのため `pnpm` 系は無変更確認のみ（`pnpm test` は AGENTS.md 触りの回帰確認として 1 回走らせる） |

## 使命・禁止事項チェック

| 項目 | 判定 |
|------|------|
| 使命への寄与 | 間接。本番でだけ壊れる基盤欠陥（array store では再現しない）を CI で落とせる形にする |
| 禁止事項 1（テストなしの実装完了） | 施策そのものがテスト。加えて mutation で赤化を確認する手順を受入条件に含めた |
| 禁止事項 2（PHPStan の widen / baseline） | 該当なし。inline array shape で型を明示する |
| 禁止事項 3（dev DB 破壊操作） | 該当なし（DB を一切使わない） |
| 禁止事項 4-8 | 該当なし（HTTP 応答・LLM・prompt・UI いずれも触らない） |
| 禁止事項 9（Artifact） | 使用しない。成果物はリポジトリ内ファイル |
| 思考原則 2（今必要なものだけ） | inventory の enum 昇格を見送り、gate 内 const に留めた。実行時 detector も作らない |
| 思考原則 3（後方互換の並走を残さない） | guide §7-6 の旧記述（最小 allowlist）は**同じ PR で削除**する |
| 思考原則 5（テストファースト） | mutation チェックリストで赤を先に見る手順を明文化 |
| 既存テストの削除・上書き | しない（`ConfigHardeningTest` は追記のみ） |

---

## 確認してほしいこと

1. role 判定の純関数化と検査 5b により、3 role すべての規則が実在ファイルに依存せず固定されたか。
2. role 名の変更 (read-only → no-payload-write) が、gate 全体の語彙・fail message と整合しているか。
3. 残る指摘があれば挙げ、無ければ **全体判定 APPROVED** を明示してほしい。これで 5 ラウンド目なので、追加の作り込みを求める場合は AGENTS.md 思考原則 2 (今必要なものだけ作る) を上回る根拠を示してほしい。
