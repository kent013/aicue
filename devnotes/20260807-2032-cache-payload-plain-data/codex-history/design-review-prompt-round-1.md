## アプリの使命（North Star）— 絶対遵守

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則（AGENTS.md）

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項（AGENTS.md）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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

【この件の性質（判断の前提）】
- **アプリコードの変更はゼロ**。追加するのは Architecture テスト 1 本 / Unit テスト 1 本 /
  既存 Feature テストへの 1 テスト追記 / 文書 2 箇所の訂正のみ。
- 概念設計は Codex conceptual-review Round 1 で APPROVED 済み。その Warning は本詳細設計に反映済み。
- **本レビューの主眼は「gate の静的走査ロジックが正しく動くか」と「空振り / 誤検出しないか」**。
  トークン走査の抜け・誤検出・PHPStan level 10 適合を重点的に見てほしい。

---

## 詳細設計書

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
| ヘルパ | `cache()->put(...)` / `cache(['k' => $v], 60)` | `cache(` 呼び出し。第 1 引数が `[` なら**その呼び出し自体が WRITE** |
| DI 変数 / プロパティ | `$this->cache->put(...)` / `$cache->put(...)` | 同一ファイル内の**型宣言**（promoted ctor param / プロパティ宣言 / 引数）から名前を収集 |
| コンテナ | `app('cache')->put(...)` / `resolve(Repository::class)` | callee が `app` / `resolve` / `make` かつ引数が `'cache'` / `'cache.store'` / 受け手型の `::class` |

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
| NON_WRITE | `get` `many` `getMultiple` `has` `missing` `pull` `forget` `delete` `deleteMultiple` `flush` `clear` `increment` `decrement` `getStore` `supportsTags` `getPrefix` `getDefaultDriver` `setDefaultDriver` `forgetDriver` `purge` `extend` `itemKey` `refreshEventDispatcher` | 計数のみ。`increment` / `decrement` は整数しか書けないため素データが構造的に保証される |
| CHAIN | `store` `driver` `tags` `resolve` | 受け手を保ったまま連鎖を辿る（`Cache::store('redis')->put(...)` を捕まえる） |
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
 *     宣言した role（write / read-only / lock-only）が実測と整合する
 *   - 検査 6: 実行時 config('cache.serializable_classes') === false、store 単位の上書きなし
 *   - 検査 6b: 語彙表の健全性（4 分類が互いに素 / 除外型が受け手型に混ざっていない）
 *   - 検査 7: 空振り検知（走査ファイル数 / メソッド呼び出し数 / 解決できたキャッシュ式が 0 でない）
 *   - 検査 8: 自己参照コントロール（本ファイル自身を走査して書き込み 0 件・面 hit なし）
 *   - 検査 9-14: 正負コントロール fixture
 *
 * ★この gate が保証しないもの（誇張しない）:
 *   - **payload の式が本当に素データか**は静的に判定しない。目録の `payload` 欄は人間の申告で、
 *     機械が保証するのは「申告なしに書き込み経路を増やせない」ことと「往復の単体テストが実在する」ことだけ
 *   - **変数によるメソッド動的ディスパッチ**（`$repo->{$method}(...)`）。静的に決定できない。
 *     literal 形（`->{'put'}(...)`）は検出する
 *   - **facade mock 経由の書き込み**（`Cache::shouldReceive('put')`）。TERMINAL で辿りを止めるため
 *     WRITE には数えない。ただしそのファイルは L3（面）に必ず現れるので無申告では追加できない
 *   - **docblock だけで型付けされた受け手**（`/** @var Repository $c */ $c->put(...)`）。
 *     型宣言のみを見る。これも L3 で面としては捕まる
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
    'deletemultiple', 'flush', 'clear', 'increment', 'decrement', 'getstore',
    'supportstags', 'getprefix', 'getdefaultdriver', 'setdefaultdriver',
    'forgetdriver', 'purge', 'extend', 'itemkey', 'refresheventdispatcher',
];

/** @var list<string> 受け手を保ったまま連鎖する API */
const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'tags', 'resolve'];

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
 * role: write = payload を書く（L2 にも登録が要る） / read-only = 読むだけ / lock-only = 排他だけ
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
 * 未 import の裸 `Cache` は Laravel の class alias（config/app.php の aliases 相当）で
 * facade に解決されうるため、**安全側に facade とみなす**（過剰検出は目録登録で解消できるが、
 * 見落としは本番でしか気付けない）。
 *
 * @param  array<string, string>  $useMap
 */
function cachePayloadResolveName(string $raw, array $useMap): string
{
    $name = ltrim($raw, '\\');
    if (isset($useMap[$name])) {
        return $useMap[$name];
    }
    if (str_contains($name, '\\')) {
        return $name;
    }
    if (strtolower($name) === 'cache') {
        return 'Illuminate\Support\Facades\Cache';
    }
    $head = strtok($name, '\\');

    return is_string($head) && isset($useMap[$head]) ? $useMap[$head] : $name;
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
 * 受け手（`::` / `->` の index）から連鎖を辿ってメソッド呼び出しを分類する。
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
        if ($nameIndex === null || ! $tokens[$nameIndex]->is(T_STRING)) {
            return $calls;
        }
        $open = cachePayloadNext($tokens, $nameIndex + 1);
        if ($open === null || $tokens[$open]->text !== '(') {
            return $calls; // プロパティ / 定数アクセス
        }

        $method = strtolower($tokens[$nameIndex]->text);
        $kind = match (true) {
            in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) => 'write',
            in_array($method, CACHE_PAYLOAD_NON_WRITE_METHODS, true) => 'non_write',
            in_array($method, CACHE_PAYLOAD_CHAIN_METHODS, true) => 'chain',
            in_array($method, CACHE_PAYLOAD_TERMINAL_METHODS, true) => 'terminal',
            default => 'unclassified',
        };
        $calls[] = ['method' => $tokens[$nameIndex]->text, 'line' => $tokens[$nameIndex]->line, 'kind' => $kind];

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
            $lower = strtolower($token->text);

            if (! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
                $surface = true; // use 文・型宣言・::class 参照でも「面」としては hit する
                $next = cachePayloadNext($tokens, $i + 1);
                if ($next !== null && $tokens[$next]->is(T_DOUBLE_COLON)) {
                    $after = cachePayloadNext($tokens, $next + 1);
                    if ($after !== null && $tokens[$after]->is(T_STRING)) {
                        $operatorIndex = $next; // Cache::put(...)
                    }
                }
            }

            if (! $isMemberName && $lower === 'cache') {
                $open = cachePayloadNext($tokens, $i + 1);
                if ($open !== null && $tokens[$open]->text === '(') {
                    $surface = true;
                    $firstArg = cachePayloadNext($tokens, $open + 1);
                    if ($firstArg !== null && $tokens[$firstArg]->text === '[') {
                        // cache(['k' => $v], $ttl) は書き込み形
                        $writes[] = ['relative' => $relative, 'line' => $token->line, 'method' => 'cache'];
                        $methods[] = 'cache';
                        $cacheCalls++;
                    } else {
                        $cacheCalls++;
                        $methods[] = 'cache';
                        $close = cachePayloadMatchingParen($tokens, $open);
                        $next = $close === null ? null : cachePayloadNext($tokens, $close + 1);
                        if ($next !== null && $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                            $operatorIndex = $next; // cache()->put(...)
                        }
                    }
                }
            }

            if (! $isMemberName && in_array($lower, ['app', 'resolve', 'make'], true)) {
                $open = cachePayloadNext($tokens, $i + 1);
                $firstArg = $open !== null && $tokens[$open]->text === '('
                    ? cachePayloadNext($tokens, $open + 1)
                    : null;
                $literal = $firstArg !== null && $tokens[$firstArg]->is(T_CONSTANT_ENCAPSED_STRING)
                    ? trim($tokens[$firstArg]->text, "'\"")
                    : null;
                if ($literal !== null && in_array($literal, ['cache', 'cache.store'], true)) {
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
        .'登録する / 読むだけなら role=read-only / Cache::lock しか使わないなら role=lock-only。'
        .'いずれも 30 文字以上の rationale が要ります。');
});

test('検査 5: 目録が宣言した role が実測と整合する', function (): void {
    $result = cachePayloadCollectAll();

    foreach (CACHE_PAYLOAD_SURFACE_INVENTORY as $path => $entry) {
        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$path}: rationale が短すぎます");
        expect(in_array($entry['role'], ['write', 'read-only', 'lock-only'], true))
            ->toBeTrue("{$path}: role は write / read-only / lock-only のいずれか");

        $methods = array_map('strtolower', $result['surfaces'][$path] ?? []);

        $hasWrite = false;
        foreach (array_keys(CACHE_PAYLOAD_WRITE_INVENTORY) as $writeKey) {
            if (str_starts_with($writeKey, $path.'::')) {
                $hasWrite = true;
                break;
            }
        }

        if ($entry['role'] === 'write') {
            expect($hasWrite)->toBeTrue("{$path}: role=write なのに書き込み目録に entry がありません");
        } else {
            expect($hasWrite)->toBeFalse("{$path}: role={$entry['role']} なのに書き込み目録に entry があります");
        }

        if ($entry['role'] === 'lock-only') {
            expect(array_values(array_diff($methods, ['lock', 'restorelock'])))->toBe([],
                "{$path}: role=lock-only なのに lock 以外のキャッシュ API を呼んでいます");
        }
    }
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
// 検査 9-14: 正負コントロール
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

- [ ] `composer test -- --filter=CachePayloadPlainDataGate` が緑（15 テスト）
- [ ] **mutation で赤化を確認**（下表 M1-M10。1 件ずつ注入 → 赤を確認 → revert）。
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

M1 / M7 / M8 / M10 に相当する保証は負のコントロール fixture として**恒久的に**テストへ残るため、
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

/** 正常系の素データ（cache に入る形そのもの）。 */
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
    // cache から戻る配列は driver によって数値が文字列化されうる（database store の JSON 経路）。
    // Assert::numeric を通す設計なので、復元後に float であることを固定する。
    $data = fxSnapshotPlainArray();
    $data['rate'] = '151.23';

    expect(FxSnapshotDto::fromArray($data)->rate)->toBe(151.23);
});
```

### PHPStan 適合チェック

- [x] `fxSnapshotPlainArray()` に `@return array{rate: float, pair: string, source: string, fetched_at: string}` を付ける
- [x] dataset のクロージャ引数に型を明示（`string $key, mixed $value`）
- [x] `toThrow` へ渡すのは `InvalidArgumentException::class`（`Webmozart\Assert` の例外型を**型まで**固定）
- [x] DTO を返している（配列返却の新設なし）

### テスト計画

- [ ] 新規テスト `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` — 往復一致 / 素データ性 /
      キー欠損 4 ケース / 不正値 6 ケース / 数値文字列の復元（計 13 assertion 群）
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
  番号衝突する。実装時に main の最新を取り込み、既存の最大番号 + 1 に採り直す
  （番号は「末尾に足す」という規約であって 11 という値に意味は無い。
  値が変わったら S1 の gate 冒頭コメントと S4 の guide 本文の参照も合わせる）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規テスト 2 本 + 既存テスト 1 本への追記 + 文書 2 箇所で完結し、アプリコードに依存も影響も無い。他の設計中タスクと共有する変更点が `AGENTS.md` の 1 節だけ |
| 競合リスク | **中（文書のみ）**。`AGENTS.md` セキュリティ不変条件の末尾追記と `docs/app-integration-guide.md` §7 は他タスクも触りうる。コードの競合はゼロ（`tests/Architecture/` の新規ファイル 1 本 + `tests/Unit/` の新規ファイル 1 本 + `ConfigHardeningTest` 末尾追記）。マージ時は main を取り込んで番号を採り直す |
| 実装順序 | S1 gate → mutation で赤化確認（M1-M10）→ S2 単体テスト → S3 pin → S4/S5 文書。**S1 の目録 `proof` が S2 のファイルを指すため、S1 と S2 は同一 PR で入れる** |
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

## 関連する現行コード（抜粋）

### app/Services/FxRateService.php（変更しない。唯一の cache 書き込み経路）

```php
final readonly class FxRateService
{
    public function resolve(): ?FxSnapshotDto
    {
        $cacheKey = 'fx_rate_usd_jpy_'.CarbonImmutable::now()->toDateString();

        try {
            /** @var mixed $cached */
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return FxSnapshotDto::fromArray($cached);
            }
        } catch (Throwable $e) {
            Log::warning('FxRate cache deserialization failed', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
            Cache::forget($cacheKey);
        }

        $fresh = $this->fetchFromFrankfurter();

        if ($fresh !== null) {
            Cache::put($cacheKey, $fresh->toArray(), CarbonImmutable::now()->endOfDay());
        }

        return $fresh;
    }

    private function fetchFromFrankfurter(): ?FxSnapshotDto
```

### app/DataTransferObjects/FxSnapshotDto.php（変更しない）

```php
 * @implements Arrayable<string, mixed>
 */
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

    /**
     * cache 等から復元する。欠損 / 不正値は Assert で例外化する
     * (呼び出し側 FxRateService が catch して cache を破棄する)。
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Assert::keyExists($data, 'rate');
        Assert::keyExists($data, 'pair');
        Assert::keyExists($data, 'source');
        Assert::keyExists($data, 'fetched_at');
        Assert::numeric($data['rate']);
        Assert::greaterThan($data['rate'], 0);
        Assert::stringNotEmpty($data['pair']);
        Assert::stringNotEmpty($data['source']);
        Assert::stringNotEmpty($data['fetched_at']);

        return new self(
            rate: (float) $data['rate'],
            pair: (string) $data['pair'],
            source: (string) $data['source'],
            fetchedAt: CarbonImmutable::parse((string) $data['fetched_at']),
        );
    }
}
```

### Cache::lock の実使用（9 か所のうち代表 3 つ）

```php
// app/Services/Billing/SubscriptionService.php:287

        try {
            $result = Cache::lock("billing:checkout:start:{$org->id}", 10)->block(
                5,
                fn (): CheckoutSessionDto => $this->startCheckoutLocked(
                    $org, $user, $plan, $basePrice, $successUrl, $cancelUrl, $attemptToken, $funding,
                ),
            );
            // Cache::lock()->block() は mixed を返すため型を絞る (TicketCheckoutService と同型)。
            Assert::isInstanceOf($result, CheckoutSessionDto::class);

            return $result;
// app/Services/Billing/TicketCheckoutService.php:58

        try {
            $redirect = Cache::lock("billing:ticket-checkout:{$organization->id}", self::LOCK_SECONDS)
                ->block(
                    self::LOCK_WAIT_SECONDS,
                    fn (): TicketCheckoutRedirect => $this->startCheckoutLocked($organization, $user, $count, $tier, $attemptToken),
                );
// app/Services/Billing/AutoRechargeService.php:180
        Assert::greaterThanEq($threshold, 0);

        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);

        try {
            /** @var TicketAutoRecharge $result */
            $result = $lock->block(5, function () use ($organization, $user, $enabled, $threshold, $max, $consent): TicketAutoRecharge {
```

### 誤検出してはいけない session()->put（app/ に 15 件。代表）

```php
        $at = $verifiedAt ?? time();

        session()->put('recent_auth_at', $at);
        session()->put('recent_auth_method', $method);
        session()->put('recent_auth_provider', $provider);

        // 権限上昇に伴う session fixation 対策。CSRF token は維持 (migrate, not regenerate)。

            return;
        }
        $this->session->put($key, $normalized->value);
    }

    public function peekForOrganization(Organization $organization): ?PlanCode
```

### config/cache.php（末尾。変更しない）

```php
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | This value determines the classes that can be unserialized from cache
    | storage. By default, no PHP classes will be unserialized from your
    | cache to prevent gadget chain attacks if your APP_KEY is leaked.
    |
    */

    'serializable_classes' => false,

];
```

### 見本 gate: tests/Architecture/CarbonOverflowArithmeticGateTest.php（走査部の抜粋）

```php

/**
 * 走査対象 (app/ + database/ + tests/) の PHP ファイル一覧。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function carbonOverflowScanTargets(): array
{
    $root = base_path();
    $files = [];
    foreach (['app', 'database', 'tests'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
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

    return $files;
}

/**
 * index 以降で最初の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function carbonOverflowNextSignificant(array $tokens, int $index): ?int
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
 * literal 文字列トークン (`'addMonth'` / `"addMonth"`) の中身を返す。literal でなければ null。
```

### tests/Feature/Config/ConfigHardeningTest.php（追記先の現状）

```php

// ========== cache: カスタム storage store は持たない ==========

test('cache.stores にカスタム storage store が存在しない', function (): void {
    // 'storage' driver は Laravel 13 の framework base config (LoadConfiguration の
    // mergeableOptions['cache']=['stores']) が実行時にマージするため config() では常に出る。
    // ここで固定したいのは「テンプレートの config/cache.php が独自に storage store を
    // 宣言していない」という不変条件なので、他の assertion と同じく config ファイルを直接評価する。
    $config = evaluateConfigFileWithEnv('cache.php', []);

    expect($config['stores']['storage'] ?? null)->toBeNull();
});
```

### docs/app-integration-guide.md 211-216 行（訂正対象）

```markdown
4. **untrusted 文字列は安全処理を経てのみ prompt に入る**(UserInput 型強制)
5. **権限判定は常に呼び出し側組織の team スコープに束縛**(team 明示 + strict_check=true)
6. **任意 class の逆シリアライズを許さない**(cache serializable_classes は既定 false。
   object cache が必要になったときだけ最小 allowlist)
7. **課金系の冪等性**: webhook は冪等マシン経由、消費は 2 フェーズ、通知は dedup_key。
   課金による利用可否の判定は `BillingAccess` 経由のみ(subscription 直参照の gate 分岐禁止。
```

### AGENTS.md 65-76 行（追記先。renumber 禁止の注意書きを含む）

```markdown
10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
> (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
> 相互参照するときは**番号ではなく項目名**で指すこと。既存の参照
> (`docs/app-integration-guide.md` の「§7 不変条件 8」/ stripe webhook migration の「不変条件 7」)
> を壊すため、どちらの側も renumber しない。

```
