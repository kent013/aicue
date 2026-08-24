あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory → 窓口 (PromptDefense) → 実行単位 (GuardedPrompt) の 1 本道のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

加えて設計スキルの禁止事項: 既存テストの削除・上書き / やたらに複雑な案を提案する。

## 静的検査 (gate) と走査器の共通規約 (AGENTS.md より。本設計の中核)

対象: `tests/Support/` 配下の検出器 / gate の中に直接書かれた走査ロジック / それらを使う gate。次の 5 条を満たす。

- **(a) クラス参照は完全修飾名で突き合わせる**。use / group use / 別名つき取り込みを解いた完全修飾名で比べる。短名一致は別名つき取り込み 1 つで検査が黙る。構文解析ライブラリの使用は必須ではない (字句走査 + 取り込み対応表でよい)
- **(b) 解決できない形は落とす (fail-closed)**。拾いすぎる方向へ倒すのは可、見逃す方向は不可。具体的には 3 つ —
  - 未解決を解決済みと同じ値へ混ぜない。未解決だと判別できる結果か解析の失敗として返し gate を失敗させる。無言で候補から外さない
  - 保証範囲の外にする構文は docblock へ明記する。明記したなら、その構文について**検出力を主張しない**。保証範囲の外にした構文で保護対象の操作を書ける場合、利用側 gate は検出力の主張をその構文を除く形へ明示的に狭めるか、未解決として失敗させる
  - **「違反が 0 件」と「母集団が 0 件」を区別する**。適用対象は「母集団の非空が不変条件である gate」で、入力を受け取って候補を返す再利用可能な検出器は対象外 (その場合は使う側の gate が母集団の非空を持つ)
- **(c) 検出力は負例で裏取りする**。わざと違反させた入力を検出できることと、規定どおりの入力を誤検出しないことの両方向を固定する
- **(d) 集めた走査結果を判定に使わない形を作らない**
- **(e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する**。負例には最低でも接頭辞つき・打ち消しつき・接尾辞つきの 3 形を置く

走査器・gate を新設するとき同じ PR で揃える 4 点: (1) 負例と正例 (テストファーストで先に赤くする) (2) 解決できない形を落とす分岐 (3) 走査が空振りしていないことの検査 (母集団が空でないこと / 走査根が生きていること) (4) docblock に走査対象と保証しないものを書く。

置き方: git 追跡下の PHP 全数を母集団にする走査は `Tests\Support\TrackedPhpSourceFiles` を使う (同じ列挙を 2 本持たない)。負例の置き場は見本ファイル / 検出器の自己検査 / gate 内の合成入力の 3 通りとも認める。

検出力の主張の書き方: 「検査ファイルが実在する」と「検出力が裏取りされている」は別物。後者を主張する記述は根拠を同じ行に併記する。

## 思考原則

まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ (Laravel/Pest の公式作法を自前機構より先に確認する)。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。**今必要なものだけ作る (オーバーエンジニアリング禁止)**。**後方互換の並走を残さない**。**タコツボ実装を避ける**。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## 前提環境

- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし対象は app / config / database / routes で **tests/ を含まない**のが既存方針)
- Pest 4.7 テストフレームワーク (arch plugin 同梱)
- DTO + JsonResource パターン / Laratrust RBAC

## レビュー観点

1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert 使用）
4. テスト計画の網羅性（各施策に Pest テスト）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ（AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠 (UI 変更を含む場合)
11. Atomic Design 準拠 (UI 変更を含む場合)

**本設計は静的検査基盤の新設であり UI / HTTP / DB を一切触らない**ため、観点 5・6・10・11 は該当しない見込みである。代わりに**上記「静的検査 (gate) と走査器の共通規約」への適合**を最重要観点としてレビューせよ。

## 出力形式

- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: pest-arch-baseline-per-rule-adoption

> 概念設計は Round 6 で **APPROVED** (`conceptual-design.md` / `conceptual-review-round-6.md`)。
> 本書はその実装設計である。実測値の基準は main `2dc4e2ec`。

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

加えて `app-design` スキルの禁止事項: **既存テストの削除・上書き** / **やたらに複雑な案を提案する**。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。ただし `phpstan.neon` の対象は
  `app / config / database / routes` で **`tests/` を含まない**(既存方針。本設計は変えない)。
  代わりに新設パスを**コマンドライン引数で** 1 度 analyse する受入条件を持つ(後述)
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
  — **本設計の新設テストは DB を一切使わない**(Architecture / Unit レーンの純粋な静的検査)
- **テストデータは必ず Factory で生成**（本設計に該当なし。モデルを追加しない)
- **DTO + JsonResource** パターン（本設計に該当なし。HTTP 経路を触らない)
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `tests/Support/` の走査器は **AGENTS.md §静的検査 (gate) と走査器の共通規約 (a)〜(e)** に従う

## 概念設計リファレンス

- [`devnotes/20260823-0020-pest-arch-baseline-per-rule-adoption/conceptual-design.md`](./conceptual-design.md)
- 合議記録: `conceptual-review-round-{1..6}.md` / `codex-history/conceptual-review-decisions-round-{1..6}.md`

---

## 詳細設計フェーズで新たに判明した vendor の事実 (実読による)

概念設計は vendor の挙動について 2 か所推測に頼っていた。詳細設計にあたり
`vendor/pestphp/pest-plugin-arch/` と `vendor/ta-tikoma/phpunit-architecture-test/` を実読し、
**うち 1 つが誤り、もう 1 つは概念設計に無かった重大な限界**であることが分かった。
どちらも設計の骨子 (規則構成・自己検査 5 部・成果物) は変えないが、
**保証範囲の書き方**と**S2 の理由付け**を変える。

### V1. `toBeUsed` の使用判定は**接尾辞一致ではなく完全一致**である (概念設計の記述が誤り)

概念設計は S2 の背景として
「Pest 側の使用判定は `ObjectUses::getByName()` の**接尾辞一致**である。Pest は `mysha1()` まで拾う」
と書いていた。**これは誤りである。**

判定の実体は `PHPUnit\Architecture\Asserts\Dependencies\DependenciesAsserts::getObjectsWhichUsesOnLayerAFromLayerB()` で、

```php
if ($objectToSearch->name === $use) {   // ★完全一致
    $result[] = "$object->name <- $objectToSearch->name";
}
```

**`===` の完全一致**である。`getByName()` (接尾辞一致) が使われるのは
`ObjectDependenciesDescription::getDocBlockTypeWithNamespace()` (docblock 型の名前空間解決) だけで、
`toBeUsed` の判定経路には現れない。

- **帰結 (設計への影響)**: S2 (`GlobalFunctionCallScanner`) を「綴りがトークン完全一致する
  素の関数呼び出しだけを狭く数える」とした判断は**そのまま正しい**。
  ただし理由が変わる — 「Pest の接尾辞一致を真似ない」ではなく
  「**Pest の完全一致と同じ粒度に揃える**」である。`mysha1` の負例は
  「Pest は拾うが S2 は数えない」の負のコントロールではなく、
  **両者とも数えないことを固定する**負例になる。
- **本書での対応**: 概念設計の当該段落を訂正する (下記「概念設計への訂正の反映」)。

### V2. 97 語彙のうち **65 語彙は本環境では検出力を持たない** (概念設計に無かった限界)

`Pest\Arch\Repositories\ObjectsRepository::allByNamespace($name)` は、対象名から
「依存側の層」を作るときに次の順で解決する:

1. `PhpCoreExpressions::getClass($name) !== null` → 言語構文として AST ノードで検出
   (`die` `exit` `print` `eval` `shell_exec` `clone` `empty` `isset` `include`)
2. `function_exists($name)` かつ `(new ReflectionFunction($name))->getName() === $name` → 関数として検出
3. どちらでもない → PSR-4 の名前空間として解決を試み、**該当が無ければ空の層**

空の層は `assertDoesNotDependOn` で必ず 0 件になるため、**その語彙の規則は絶対に落ちない**。

実測 (Laravel をブートした状態で 97 語彙を分類):

| 分類 | 件数 | 語彙 |
|---|---|---|
| コア構文 (AST ノードで検出) | **5** | `die` `eval` `exit` `print` `shell_exec` |
| 実在関数 (名前完全一致で検出) | **27** | `array_rand` `assert` `dd` `debug_backtrace` `debug_print_backtrace` `debug_zval_dump` `dl` `dump` `env` `exec` `extract` `mb_parse_str` `md5` `mt_rand` `passthru` `phpinfo` `print_r` `rand` `sha1` `shuffle` `str_shuffle` `system` `tempnam` `uniqid` `unserialize` `var_dump` `var_export` |
| **不活性 (層が空 = 絶対に落ちない)** | **65** | `create_function` `ddd` `ds` `echo` `ereg` `eregi` `global` `goto` `mysql_*` 14 種 `ray` `trap` `xdebug_*` 40 種 |

規則別の内訳:

| 規則 | 語彙数 | うち検出力あり | 備考 |
|---|---|---|---|
| AB-1 | 56 | **9** | `xdebug_*` 40 / `echo` `goto` `global` `ds` `ray` `trap` が不活性 |
| AB-2 | 16 | **0** | `mysql_*` 14 + `ereg` + `eregi` は **PHP 8 で削除済み**。恒久的に不活性 |
| AB-3 | 4 | **3** | `ddd` は該当パッケージ未導入で不活性 |
| AB-4 | 18 | **17** | `create_function` は PHP 8 で削除済み |
| AB-5 / AB-6 / AB-7 | 1 / 1 / 1 | **3** | `sha1` `tempnam` `var_export` はいずれも検出力あり |

- **不活性は 2 種類ある**。混ぜてはならない:
  - **恒久的に不活性** (17 語彙): `mysql_*` 14 + `ereg` + `eregi` + `create_function`。
    PHP 8 で言語から削除されており、**将来も復活しない**
  - **環境依存で不活性** (48 語彙): `xdebug_*` 40 は xdebug 拡張が読み込まれれば活性化し、
    `ray` `ds` `ddd` `trap` は該当パッケージを入れれば活性化する。
    **同じコードでも実行環境で検出力が変わる**
- **設計判断**: 不活性語彙を規則から**外さない**。外すと I6 (vendor preset との集合一致) が壊れ、
  「vendor 更新で語彙が増えたら赤」という唯一の取りこぼし検出が失われる。
  **代わりに検出力を主張しない** (AGENTS.md §検出力の主張の書き方 /
  共通規約 (b) の「保証範囲の外にする構文は docblock へ明記する。明記したなら検出力を主張しない」)。
- **件数を pin しない**。活性/不活性の境界は `function_exists()` の実行時評価に依存し、
  xdebug の有無で 40 件動く。pin すると**環境差だけで赤くなる**検査になり、
  「検査を緩めることは選択肢に入れない」の逆 (検査を頻繁に書き換える圧力) を生む。
  代わりに **gate の docblock に分類の生成方法を書き、読者がその場で再計算できる**ようにする。

### V3. `arch()` + `foreach` の生成形は Pest に受理される (実測)

`arch()` は `TestCall` を返す通常のテスト宣言関数で、`Architectable` concern により
`->expect(...)->not->toBeUsed()->ignoring(...)` の高階チェーンが使える
(`vendor/pestphp/pest-plugin-arch/src/Autoload.php`)。
`foreach` から 7 本を宣言する形が Pest のテスト生成段を通ることは、
本設計のスパイクで **7 本の `__pest_evaluable_AB_{1..7}__*` メソッドが生成されるところまで実測**した。

### V4. `ignoring()` の除外は「名前の前方一致」である

`LayerFactory::make()` は `$options->exclude` の各値について
`str_starts_with($object->name, $exclude)` で層から除外し、`uses` からも同じ条件で除く。
`->ignoring(FakeObjectStore::class)` は完全修飾クラス名の前方一致なので、
**同じ接頭辞を持つ別クラス (`FakeObjectStoreDouble` 等) も一緒に除外される**。
本設計の例外 4 クラスにはそのような同接頭辞クラスは現在存在しないが、
**S3 (構造契約) に「例外クラス名が他の走査域クラス名の真の接頭辞になっていないこと」の検査を足す**
(下記 施策 5)。これは概念設計に無かった追加で、`ignoring` の波及半径を
I2 (対象シンボル 1 個) だけでなく**クラス側でも 1 個に閉じる**ためである。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|---|---|---|
| 1 | 値の置き場 `ArchBaseline` の新設 | `tests/Support/Architecture/ArchBaseline.php` (新規) | Critical |
| 2 | S2 用走査器 `GlobalFunctionCallScanner` の新設 | `tests/Support/Architecture/GlobalFunctionCallScanner.php` (新規) | Critical |
| 3 | S4 用走査器 `ArchSurfaceScanner` の新設 | `tests/Support/Architecture/ArchSurfaceScanner.php` (新規) | Critical |
| 4 | S5 用読取器 `VendorArchPresetReader` の新設 | `tests/Support/Architecture/VendorArchPresetReader.php` (新規) | Critical |
| 5 | gate `ArchBaselineTest` の新設 (禁止表明 7 本 + 自己検査 5 部) | `tests/Architecture/ArchBaselineTest.php` (新規) | Critical |
| 6 | 3 走査器の負例・正例 | `tests/Unit/Architecture/ArchBaselineScannerTest.php` (新規) | Critical |
| 7 | 乖離台帳 D40 の登録と件数 pin | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` | High |
| 8 | 概念設計の訂正 (V1 の記述誤り) | `devnotes/.../conceptual-design.md` | Medium |

**実装順序は 1 → 2,3,4 → 6 → 5 → 7 → 8**。テストファースト (思考原則 5) のため、
**6 (負例・正例) を先に書いて赤を確認してから** 2〜4 の本体を書く。
5 の gate は走査器が緑になってから足す。

---

## 施策 1: `tests/Support/Architecture/ArchBaseline.php` — 値の置き場

### 変更箇所

- ファイル: `tests/Support/Architecture/ArchBaseline.php` (新規)
- ディレクトリ `tests/Support/Architecture/` も新規 (現在不在)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 施策 5 / 6 が参照する (同じ PR 内)

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

/**
 * Pest arch ベースラインの**値の置き場** (不変の定数だけを持つ)。
 *
 * ★**解析・ファイル I/O・git 実行を一切持たない** (`LedgerPins` と同型)。
 * ★正典 v1 の「例外一覧の単一の置き場」に対応する。禁止語彙と例外の登録は
 *   **本クラスの定数だけが正本**であり、gate も走査器も値をここから読む。
 * ★**これは免除の一覧ではない**。`ignoring` に載る対象は
 *   「その 1 シンボルだけを見る規則」へ隔離され、波及半径は定義上 1 シンボルに閉じる。
 *
 * ★**保証しないもの (検出力を誇張しない)**:
 *   本クラスが列挙する 97 語彙のうち、Pest arch が実際に検出できるのは
 *   (1) `Pest\Arch\Support\PhpCoreExpressions` が扱う言語構文と
 *   (2) 実行時に `function_exists()` が真になる関数、の 2 つだけである。
 *   `mysql_*` 14 種 / `ereg` / `eregi` / `create_function` は **PHP 8 で削除済み**で
 *   恒久的に検出されない。`xdebug_*` 40 種 / `ray` / `ds` / `ddd` / `trap` は
 *   **拡張やパッケージの有無で検出力が変わる**。
 *   分類の再計算方法は `ArchBaselineTest` の docblock に書いてある。
 *   **件数は pin しない** (環境差だけで赤くなる検査を作らないため)。
 */
final class ArchBaseline
{
    /** インスタンス化しない (定数の置き場)。 */
    private function __construct() {}

    /**
     * 規則の正本。
     *
     * @var array<string, array{symbols: list<string>, exceptions: list<class-string>, rationale: string}>
     */
    public const array RULES = [
        'AB-1' => [
            'symbols' => [/* php preset のデバッグ・出力・実行制御系 56 語彙 */],
            'exceptions' => [],
            'rationale' => '診断出力・実行制御の語彙。アプリコードは Log ファサードと例外で診断するため例外を要しない',
        ],
        'AB-2' => [
            'symbols' => [/* mysql_* 14 + ereg + eregi = 16 語彙 */],
            'exceptions' => [],
            'rationale' => 'PHP 8 で言語から削除済みの手続き API。書けないので例外を要しない。集合一致のための受け皿であり検出力は主張しない',
        ],
        'AB-3' => [
            'symbols' => ['dd', 'ddd', 'env', 'exit'],
            'exceptions' => [],
            'rationale' => 'Laravel の開発補助。env() は config 層だけの作法で app 配下は config() 経由に統一済みのため例外を要しない',
        ],
        'AB-4' => [
            'symbols' => [/* security preset のうち例外不要な 18 語彙 */],
            'exceptions' => [],
            'rationale' => '暗号・乱数・任意コード実行の語彙。乱数は Str::random と CipherSweet 経由に統一済みで例外を要しない',
        ],
        'AB-5' => [
            'symbols' => ['sha1'],
            'exceptions' => [\App\Services\Storage\Fakes\FakeObjectStore::class],
            'rationale' => 'ローカル fake のロックファイル名生成に使う。暗号用途ではなく衝突しない一意名が要るだけである',
        ],
        'AB-6' => [
            'symbols' => ['tempnam'],
            'exceptions' => [\App\Services\Manual\SopTextExtractor::class],
            'rationale' => 'SOP 取込で表計算ファイルを一時ファイルへ落とす。生成直後に unlink する短命な経路である',
        ],
        'AB-7' => [
            'symbols' => ['var_export'],
            'exceptions' => [
                \App\Support\ProductionEnvGuard::class,
                \App\Support\QueueDispatchAtomicityGuard::class,
            ],
            'rationale' => '起動時 fail-fast の診断メッセージで実測値を人間に見せる。出力先は例外メッセージだけで応答本文へは出ない',
        ],
    ];

    /**
     * 規則ごとの対象シンボル数の pin (I5)。無断の増減で赤になる。
     *
     * @var array<string, int>
     */
    public const array SYMBOL_COUNT_PINS = [
        'AB-1' => 56, 'AB-2' => 16, 'AB-3' => 4, 'AB-4' => 18,
        'AB-5' => 1, 'AB-6' => 1, 'AB-7' => 1,
    ];

    /** 7 規則の和集合の語彙数 (= vendor 3 preset の禁止語彙の和集合)。 */
    public const int TOTAL_SYMBOL_COUNT = 97;

    /**
     * 名前が動的に決まるメンバ参照の目録 (ファイル => {count, rationale})。
     *
     * ★**これは arch の例外ではない**。「走査器が名前を解決できない形の在庫」であり、
     *   **人手で用途を確認して受容した未解決箇所**であって安全である証明ではない。
     * ★**同一ファイル内での置換は検出しない** (件数が変わらないため)。
     * ★**0 件を許容する** (全件が正当に除去された状態は望ましい)。
     *
     * @var array<string, array{count: int, rationale: string}>
     */
    public const array DYNAMIC_MEMBER_INVENTORY = [
        'tests/Feature/Billing/BillingAccessStateTest.php' => [
            'count' => 1,
            'rationale' => 'factory state 名をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Feature/Billing/BillingCheckoutSessionModelTest.php' => [
            'count' => 2,
            'rationale' => 'factory state 名をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Feature/Invitations/AcceptInvitationInAppTest.php' => [
            'count' => 1,
            'rationale' => 'factory state 名をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Feature/Invitations/PendingInvitationScopeTest.php' => [
            'count' => 1,
            'rationale' => 'factory state 名をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Feature/Organizations/TwoFactorEnforcementTest.php' => [
            'count' => 1,
            'rationale' => 'HTTP verb をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Unit/Exceptions/AnalysisFailedExceptionTest.php' => [
            'count' => 1,
            'rationale' => '名前付きコンストラクタをデータセットで回す形。arch のチェーンとは無関係な単体テストである',
        ],
    ];

    /**
     * S4 が照合する arch チェーンの期待トークン列 (綴りの列。空白とコメントは除く)。
     *
     * ★**この定数が期待形の唯一の正本**である。gate 側に写しを持たない。
     *
     * @var list<string>
     */
    public const array EXPECTED_CHAIN_TOKENS = [
        'arch', '(', 'ArchBaseline', '::', 'descriptionOf', '(', '$ruleId', ')', ')',
        '->', 'expect', '(', 'ArchBaseline', '::', 'symbolsOf', '(', '$ruleId', ')', ')',
        '->', 'not', '->', 'toBeUsed', '(', ')',
        '->', 'ignoring', '(', 'ArchBaseline', '::', 'exceptionsOf', '(', '$ruleId', ')', ')', ';',
    ];

    /** @return list<string> */
    public static function ruleIds(): array { /* array_keys(self::RULES) */ }

    public static function descriptionOf(string $ruleId): string { /* "{$ruleId}: …" */ }

    /** @return list<string> */
    public static function symbolsOf(string $ruleId): array { /* … */ }

    /** @return list<class-string> */
    public static function exceptionsOf(string $ruleId): array { /* … */ }

    /** @return list<string> 全規則の語彙の和集合 (重複なし・昇順) */
    public static function allSymbols(): array { /* … */ }
}
```

### 設計上の決定

- **`descriptionOf()` はテスト名になる**。規則 ID を含めることで一意性を担保する
  (一意性そのものは S3 が固定)。
- アクセサは未知の `$ruleId` で `Webmozart\Assert\Assert::keyExists()` により**例外**にする
  (無言で空配列を返さない = 共通規約 (b))。
- `const array` / `const int` は PHP 8.3+ の型付きクラス定数。既存 `LedgerPins` と同じ書き方。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`list<string>` / `list<class-string>` / `string`)
- [x] null 安全 (`Assert::keyExists()` で境界を閉じる)
- [x] DTO を返している — **該当なし** (値の置き場であり HTTP 応答を作らない)
- [x] Generics の型パラメータが正しい (`array<string, array{...}>` の shape を PHPDoc で固定)

### テスト計画

- [x] 本クラスは値だけなので専用テストを持たない。**契約は施策 5 の S1 / S3 が全部押さえる**
  (シンボル数 pin / 規則 ID 一意 / 語彙の全規則通じた重複なし / 例外クラス実在 /
  `rationale` 30 文字以上 / 例外を持つ規則のシンボルはちょうど 1 個)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (DB を一切使わない)

### リスク

- `RULES` の語彙を手で書き写すと**移植漏れ**が起きる。→ S5 (vendor preset との集合一致) が
  和集合の完全一致で落とすので、漏れは機械的に赤になる。
  **実装時は vendor ソースから抽出したリストを貼る** (手打ちしない)。

---

## 施策 2: `GlobalFunctionCallScanner` — S2 用 (狭く数える)

### 変更箇所

- ファイル: `tests/Support/Architecture/GlobalFunctionCallScanner.php` (新規)

### 波及変更

- TypeScript 型定義 / API Resource・DTO: **なし**
- テストファイル: 施策 6 が負例・正例を持つ

### 契約 (docblock に書く正本)

> 与えられた PHP ソースの中に、**指定した関数名と綴りがトークン完全一致する
> 素のグローバル関数呼び出し**が何件あるかを数える純関数。
>
> **倒す向きが他の走査と逆である**。本走査器の利用側 (S2) は「違反の検出」ではなく
> **「使用の証明」**なので、数えすぎ = 腐った登録の見逃し (危険)、
> 数え漏らし = 赤 (安全) になる。したがって**狭く数える**。
>
> **数える**: `sha1(` / `\sha1(`
> **数えない**: `->sha1(` / `?->sha1(` / `::sha1(` / `function sha1(` / `new sha1(` /
> 直前が識別子 / `mysha1(` / `sha1_file(`
> **保証しない (数えない = 赤へ倒す)**: 可変関数 (`$f='sha1'; $f()`) /
> 文字列経由の呼び出し / `.blade.php` / `tests/js/`
>
> ファイルが読めない・トークン化できない場合は**無言で 0 件にせず例外**にする。

### 実装方針

```php
/**
 * @param  list<string>  $functionNames  探す関数名 (グローバル関数の短名)
 * @return array<string, int>  関数名 => 件数 (0 件でもキーは残す)
 */
public static function countCalls(string $absolutePath, array $functionNames): array
```

1. `Assert::fileExists()` → `file_get_contents()` の失敗は `RuntimeException`
2. `token_get_all()` の結果から `T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` を落とした
   有意トークン列を作る
3. 各位置 `i` について:
   - `T_STRING` で綴りが対象名と**完全一致** → 候補
   - `T_NAME_FULLY_QUALIFIED` (`\sha1`) で先頭 `\` を除いた綴りが対象名と完全一致 → 候補
     (`T_NAME_QUALIFIED` = `Foo\sha1` は**別の関数**なので数えない)
   - 直後が `(` でなければ落とす
   - 直前が `T_OBJECT_OPERATOR` / `T_NULLSAFE_OBJECT_OPERATOR` / `T_DOUBLE_COLON` /
     `T_FUNCTION` / `T_NEW` / `T_CONST` / `T_STRING` のいずれかなら落とす
4. `array_fill_keys($functionNames, 0)` を初期値にして数える (**0 件でもキーを消さない**)

**接尾辞・接頭辞は原理的に混入しない** — トークンは `mysha1` / `sha1_file` を
1 つの `T_STRING` として返すので、綴りの完全一致で自動的に弾かれる。
負例はこれを**固定するため**に置く (共通規約 (e) の 3 形)。

### PHPStan 適合チェック

- [x] 戻り値の型 `array<string, int>` を明示
- [x] `Assert::fileExists()` / `Assert::string()` で境界を閉じる
- [x] `token_get_all()` の生の戻り値を**外へ出さない** (件数だけ返す)
- [x] Generics の型パラメータが正しい

### テスト計画

施策 6 で:
- [x] **正例**: `FakeObjectStore` の実ソースで `sha1` が 1 件以上
- [x] **正例**: `\sha1(` の完全修飾形を数える
- [x] **負例 (取り違え)**: メソッド宣言 / interface のメソッド宣言 / メソッド呼び出し /
  静的呼び出しを関数呼び出しと取り違えない。**現実の分岐**として
  `App\Services\Manual\SopTextExtractor::extract()` と
  `App\Services\Capture\TakeThumbnailExtractor::extract()` を使う
  (security preset の `extract` と綴りが一致する)
- [x] **負例 (語彙 3 形)**: 接頭辞つき (`mysha1` / `getenv`) / 接尾辞つき (`sha1_file`) /
  打ち消しつきが完全一致で弾かれる
- [x] **負例 (fail-closed)**: 存在しないパスで例外
- [x] **0 件のキーが消えないこと** (`allSymbols()` の一部が 0 件でも配列の形が崩れない)

### リスク

- 数え漏らしは S2 を赤にするだけで**穴にはならない**。
  I2 が blast radius を 1 シンボルに抑えているため、余った例外が隠せるのは
  「その 1 シンボルの、その 1 クラスでの使用」だけである。

---

## 施策 3: `ArchSurfaceScanner` — S4 用 (広く数え、名前を解決する)

### 変更箇所

- ファイル: `tests/Support/Architecture/ArchSurfaceScanner.php` (新規)

### 公開メソッドと戻り値の型 (Round 6 の [Suggestion] を反映)

```php
/**
 * 識別子トークンの完全一致で出現位置を返す。
 *
 * ★**コメント (`T_COMMENT` / `T_DOC_COMMENT`) と文字列リテラルの中身は数えない**。
 *   識別子ではないからである。これは形式的な注記ではなく**現に効いている分岐**で、
 *   素の文字列検索で数えると `preset` が 1 件 (`ForbiddenStatementTokenInvariantTest` の
 *   docblock)、callable 語彙が 2 件 (`CacheGuardWiringGateTest` /
 *   `JobDeferralTerminationGateTest` の docblock) 一致して S4 は初日から赤くなる。
 * ★この除外を共通規約 (b) の「未解決の黙殺」と取り違えないこと。
 *   語彙を説明する散文は実行経路ではない。
 *
 * @param  list<string>  $identifiers
 * @return array<string, list<array{line: int, index: int}>>
 */
public static function identifierSites(string $source, array $identifiers): array

/**
 * 指定した有意トークン位置から文末 `;` までの**綴り列**を返す (チェーンの完全一致照合用)。
 *
 * @return list<string>
 */
public static function statementTokens(string $source, int $index): array

/**
 * **メンバ名の綴りが静的に決まらない**参照の位置を返す。
 *
 * 動的とするのは次の 5 形:
 *   (i) `->{expr}` / (ii) `?->{expr}` / (iii) `::{expr}` /
 *   (iv) `->$var` / `?->$var` / (v) `::$var` が**直後に `(` を伴う**形
 *        (PHP の可変静的メソッド呼び出し `A::$m()`)
 *
 * ★**`(` を伴わない `::$var` は動的ではない**。`self::$violations` のような
 *   **静的プロパティ参照**で、メンバ名 (`violations`) は綴りとして確定している。
 *   混ぜると `tests/` 全数の実測が 7 件 / 6 ファイル → 52 件 / 14 ファイルへ膨らみ、
 *   増えた 45 件はすべて arch と無関係な静的プロパティ参照になる。
 * ★`->` 側は**メソッド呼び出しとプロパティ参照を区別しない** (広く数える)。
 *   区別するのは `::` 側だけで、判定を狭める唯一の場所である。
 *
 * @return list<array{line: int, index: int}>
 */
public static function dynamicMemberSites(string $source): array

/**
 * 対象関数への呼び出し位置を、**完全修飾関数名まで解決して**返す。
 *
 * 解決するもの (共通規約 (a)):
 *   - `T_STRING` の非修飾呼び出し
 *   - `T_NAME_FULLY_QUALIFIED` (`\call_user_func`) の正規化
 *   - `T_NAME_QUALIFIED` (`Foo\call_user_func`) の正規化
 *   - `use function A\B\call_user_func;` / `use function … as invoke;` /
 *     `use function A\{b, c as d};` (group use) を解いた別名経由の呼び出し
 *
 * ★**解決できない取り込み・呼び出し形は `status: 'unresolved'` として返す**。
 *   **無言で候補から外さない** (共通規約 (b))。利用側 gate は unresolved が
 *   1 件でもあれば失敗させる。
 * ★**名前空間内の非修飾呼び出しは安全側へ倒す** — PHP の関数解決は
 *   「現在の名前空間 → グローバル」の順で fallback するため、同名の名前空間関数が
 *   実在するかどうかで実際の呼び先が変わる。本走査器は**実在を調べずに候補として数える**。
 *   拾いすぎても 0 件 gate としては安全側 (fail-closed) に倒れる。
 *
 * @param  list<string>  $globalFunctionNames  完全修飾で照合する対象 (先頭 `\` 無し)
 * @return list<array{status: 'resolved', name: string, line: int}
 *              |array{status: 'unresolved', reason: string, line: int}>
 */
public static function resolvedFunctionCallSites(string $source, array $globalFunctionNames): array
```

### `resolvedFunctionCallSites()` の解決アルゴリズム

1. **取り込み表の構築**: ソース先頭から `T_USE` を走査し、`function` 修飾のある取り込みだけを拾う。
   - `use function A\B\c;` → alias `c` => `A\B\c`
   - `use function A\B\c as d;` → alias `d` => `A\B\c`
   - `use function A\{b, c as d};` (group use) → 展開して同様に登録
   - **`use function` に続く形が上記のどれにも当てはまらないとき** (可変長・動的生成など)
     → `unresolved` を 1 件積む (reason: `unparsable_use_function`)
2. **名前空間の把握**: `T_NAMESPACE` から現在の名前空間を取る。
   **1 ファイルに複数の名前空間宣言 (波括弧形) がある場合は `unresolved`**
   (reason: `multiple_namespaces`)。tests/ には現存しないが、fail-closed に倒す。
3. **呼び出し位置の判定**: 直後が `(` で、直前が `->` / `?->` / `::` / `function` / `new` /
   `const` / `T_STRING` のいずれでもない位置について:
   - `T_NAME_FULLY_QUALIFIED` → 先頭 `\` を落とした綴りが対象名と一致すれば `resolved`
   - `T_NAME_QUALIFIED` → 綴りをそのまま完全修飾名とみなし、対象名と一致すれば `resolved`
     (現在の名前空間との相対解決は行わない。**行わないことを docblock に書く**)
   - `T_STRING` →
     (a) 取り込み表に alias があればその完全修飾名で照合
     (b) 無ければ **グローバル関数とみなして**綴りで照合 (安全側へ倒す)
4. **`fromCallable` は本メソッドの対象外**。メソッド名なので
   `identifierSites($source, ['fromCallable'])` の別契約で 0 件を固定する。

### 母集団と走査根

- S4 の母集団は **`Tests\Support\TrackedPhpSourceFiles::all($root)` のうち
  `tests/` で始まる相対パス**。同じ列挙を 2 本持たない (AGENTS.md §本リポジトリでの置き方)。
- 実測 **803 本**。gate は**床値 + 代表パス**を pin する (施策 5)。

### PHPStan 適合チェック

- [x] 戻り値の型 (判別 union の `list<>`) を明示。`mixed` へ widen しない
- [x] `Assert` で境界 (ソース文字列・index 範囲) を閉じる
- [x] `token_get_all()` の生の戻り値を外へ出さない
- [x] Generics の型パラメータが正しい

### テスト計画 (施策 6)

- [x] **正例**: 期待形どおりのチェーンが `statementTokens()` で
      `ArchBaseline::EXPECTED_CHAIN_TOKENS` と一致する
- [x] **負例 (引数の出所)**: `->ignoring([Foo::class])` の直書き形 /
      チェーンを 2 本へ増やした形 / `->not->toBeUsed()` を落とした形が期待形照合で落ちる
- [x] **負例 (動的ディスパッチ)**: `->{$method}([Foo::class])` / `::{$m}()` / `->$m()` /
      `A::$m()` を `dynamicMemberSites()` が拾う
- [x] **正例 (取り違え防止)**: `self::$x` / `A::$prop` を**動的扱いしない**。
      `A::$m()` と `A::$m` を隣り合わせに置き、`(` の有無だけで分かれることを固定する
- [x] **負例 (callable)**: `\call_user_func(...)` (完全修飾) /
      `use function call_user_func as invoke; invoke(...)` (別名) を `resolved` として拾う
- [x] **負例 (fail-closed)**: 解けない `use function` 形 / 複数名前空間宣言で `unresolved` を返す
- [x] **正例 (誤検出しない)**: `mycall_user_func(...)` / `$obj->call_user_func(...)` /
      `Foo::call_user_func(...)` を拾わない
- [x] **コメント・文字列の除外**: docblock の中の `preset` / `call_user_func` を数えない

### リスク

- **走査器の自己参照**: 本走査器と gate 自身が `tests/` にあるため、
  **自分のソースに書いた語彙が自分の母集団に入る**。
  → 負例はすべて **nowdoc / heredoc の文字列**として与え、実コードとして書かない
  (施策 6 の非交渉の規約。既存 `FakeWiringSourceScannerTest` と同じ作法)。
- **命名の禁止**: `tests/` 配下に `preset` / `arch` / `ignoring` / `toBeUsed` /
  `call_user_func` / `call_user_func_array` / `forward_static_call` /
  `forward_static_call_array` / `fromCallable` と**完全一致する識別子**
  (メソッド名・関数名・定数名) を作ってはならない。S4 の件数 pin が壊れる。
  → gate の docblock に明記し、違反すれば S4 が即座に赤くなる (機械で守られる)。

---

## 施策 4: `VendorArchPresetReader` — S5 用 (fail-closed)

### 変更箇所

- ファイル: `tests/Support/Architecture/VendorArchPresetReader.php` (新規)

### 契約

```php
/**
 * vendor の Pest arch preset ソースから「禁止語彙の配列」を抽出する。
 *
 * ★入力元は `Pest\ArchPresets\{Php,Security,Laravel}` の**ソース**である。
 *   `class_exists()` で実在を確認 → `ReflectionClass::getFileName()` で解決する
 *   (**パスを直書きしない**)。
 * ★抽出定義: `expect(` の直後に始まる**配列リテラル**のうち、閉じ括弧の後に
 *   `->not->toBeUsed()` が続くものの文字列要素。
 *   `expect('App\Providers')->not->toBeUsed()` のような**文字列引数の形は対象外**である
 *   (層の指定であって禁止語彙ではない)。
 * ★**期待する配列の個数を pin する** (Php: 1 / Security: 1 / Laravel: 1)。
 *   0 個でも 2 個でも例外にする。
 * ★**vendor の公開 API ではなくソース表現に依存する**。`composer update` で赤くなり得るのは
 *   仕様であり、そのときはベースラインを更新する。
 *
 * @return list<string> 語彙 (重複なし・昇順)
 */
public static function forbiddenSymbols(string $presetClass): array
```

### 実装方針

- `token_get_all()` でソースをトークン化し、`expect` の `T_STRING` を起点に
  `(` → `[` … `]` → `)` → `->` `not` `->` `toBeUsed` `(` `)` の並びを追う
- 配列要素は `T_CONSTANT_ENCAPSED_STRING` のみを採る (可変要素があれば例外)
- 見つかった配列が pin と違う個数なら `RuntimeException`

### PHPStan 適合チェック

- [x] 戻り値 `list<string>` を明示
- [x] `Assert::classExists()` / `Assert::string($fileName)` で Reflection の境界を閉じる
- [x] 生のトークンを外へ出さない

### テスト計画 (施策 6)

- [x] **正例**: 3 preset から語彙が取れ、件数が `73 / 20 / 6` (実測値) である
- [x] **正例**: 和集合が 97 語彙になる (重複は `dump` / `ray` の 2 件だけ)
- [x] **負例 (fail-closed)**: 配列の無い合成ソース / 配列が 2 個ある合成ソース /
      要素に変数を含む合成ソースで例外になる
- [x] **母集団が空でない**: 3 preset とも語彙が 1 件以上

### リスク

- `composer update` で preset の書き方が変われば赤になる。これは**仕様**であり、
  そのとき `RULES` を更新する。docblock に明記する。
- **件数 `73 / 20 / 6` を pin するか**: pin しない。個数 pin (配列 1 個ずつ) と
  I6 の集合一致で十分で、語彙数まで二重に pin すると
  vendor 更新のたびに 2 か所直すことになる (`TOTAL_SYMBOL_COUNT` = 97 は
  `ArchBaseline` 側の pin として 1 か所だけ持つ)。

---

## 施策 5: `tests/Architecture/ArchBaselineTest.php` — gate

### 変更箇所

- ファイル: `tests/Architecture/ArchBaselineTest.php` (新規)
- 既存 131 本には**一切触れない**

### A. 禁止表明 (7 本を単一の生成点から)

```php
foreach (ArchBaseline::ruleIds() as $ruleId) {
    arch(ArchBaseline::descriptionOf($ruleId))
        ->expect(ArchBaseline::symbolsOf($ruleId))
        ->not->toBeUsed()
        ->ignoring(ArchBaseline::exceptionsOf($ruleId));
}
```

- **`preset(` は 1 度も呼ばない**
- `arch` / `ignoring` / `toBeUsed` の識別子出現は**リポジトリの `tests/` 全数でちょうど 1 件ずつ**
- この 1 件から文末 `;` までのトークン列が `ArchBaseline::EXPECTED_CHAIN_TOKENS` と完全一致する
- `arch()` が `TestCall` を返す通常のテスト宣言関数であることは vendor 実読 + スパイクで確認済み (V3)

### B. 自己検査 5 部

| 部 | 検査内容 | 落ちる条件 |
|---|---|---|
| **S1** 期待値の pin | 規則ごとの対象シンボル数が `SYMBOL_COUNT_PINS` と完全一致 / 和集合が `TOTAL_SYMBOL_COUNT` (97) | 語彙が無断で増減した |
| **S2** 逆向き証明 | 各例外クラスの実ソースを `GlobalFunctionCallScanner` で走査し、対象シンボルの**素の関数呼び出し**が 1 件以上 | 登録が腐った (使用をやめた / 改名した / そもそも使っていない) |
| **S3** 構造契約 | 下記 7 条 | 分解の規約が壊れた |
| **S4** サーフェスの pin | 下記 6 条 | 例外の置き場が二重化した / preset 一括使用が復活した / 生のクラス名を直書きした / 動的ディスパッチで綴りを回避した |
| **S5** vendor preset との集合一致 | 7 規則の和集合 == `VendorArchPresetReader` が 3 preset から抽出した語彙の和集合 | vendor 更新で語彙が増減した / 移植漏れ |

**S3 の 7 条**:

1. **例外を持つ規則の対象シンボルはちょうど 1 個** (I2 = 正典の核心)
2. 規則 ID が一意
3. 語彙が**全規則を通じて重複しない** (和集合の件数 == 各規則の件数の総和)
4. 例外クラスが**実在し** (`class_exists`)、**PSR-4 走査域内** (`App\` / `Database\Factories\` / `Database\Seeders\` のいずれかで始まる)
5. `rationale` が **30 文字以上**
6. `DYNAMIC_MEMBER_INVENTORY` の各 `rationale` が 30 文字以上
7. **例外クラス名が、走査域の他クラス名の真の接頭辞になっていない** (V4 への対応。
   `ignoring` は前方一致で除外するため、`FakeObjectStore` と `FakeObjectStoreDouble` が
   同時に存在すると後者も黙って除外される)

**S4 の 6 条** (母集団 = `tests/` 配下の git 追跡 PHP 全数):

1. `preset` の識別子出現が **0 件**
2. `arch` / `ignoring` / `toBeUsed` の識別子出現が**各ちょうど 1 件**、かつ
   その 1 件が `tests/Architecture/ArchBaselineTest.php` にある
3. `arch` の出現位置から文末までのトークン列が `EXPECTED_CHAIN_TOKENS` と**完全一致**
4. `dynamicMemberSites()` の結果が `DYNAMIC_MEMBER_INVENTORY` と
   **ファイル別件数まで exact-fit** (目録に無いファイルが 1 件でもあれば赤 /
   目録にあるのに実測 0 件でも赤)
5. `resolvedFunctionCallSites()` が callable 4 関数について
   **`resolved` 0 件かつ `unresolved` 0 件**
6. `fromCallable` の識別子出現が **0 件**

**母集団が空でないことの検査** (共通規約 (b) の 3 番目):

- `ArchBaseline::RULES` が空でない / 各規則の `symbols` が空でない
- vendor preset から抽出した語彙集合が 3 つとも空でない
- S4 の走査根 (`tests/` 配下の追跡 PHP) が **700 本以上** (床値) あり、
  **代表パス** (`tests/Pest.php` / `tests/TestCase.php` / `tests/Architecture/ArchBaselineTest.php`) が
  すべて母集団に含まれる
- **`DYNAMIC_MEMBER_INVENTORY` には非空を要求しない** (0 件は望ましい状態。
  走査器の検出力は**合成負例**で固定し、実コードの件数に依存させない)
- 例外クラスのソースファイルが解決できること (解決できなければ**無言で外さず**赤)

### gate の docblock に書くこと (保証しないものの正本)

1. **走査域**: Pest arch は `App\` / `Database\Factories\` / `Database\Seeders\` の 3 根だけを見る。
   `Tests\` は `Composer::userNamespaces()` が除外する。`.blade.php` / `resources/js/` も対象外
2. **検出できる語彙は 97 のうち一部である** (V2)。
   コア構文 5 + 実在関数 27 だけが層を持ち、**65 語彙は本環境で不活性**。
   うち `mysql_*` 14 + `ereg` + `eregi` + `create_function` の **17 語彙は恒久的に不活性**
   (PHP 8 で削除済み)、`xdebug_*` 40 + `ray` `ds` `ddd` `trap` の **48 語彙は環境依存**。
   **分類の再計算方法**: 各語彙 `$v` について
   `Pest\Arch\Support\PhpCoreExpressions::getClass($v) !== null || function_exists($v)`。
   **件数は pin しない** (環境差だけで赤くなる検査にしないため)
3. **S4 が保証しない構文**: `ReflectionMethod` / `ReflectionFunction` 経由の反射呼び出し
   (既存テストが `tests/` 全数で 41 件 / 25 ファイル正当に使用) と、
   それ以外の未知の間接実行経路。**この構文について検出力を主張しない**
4. **静的プロパティ参照 (`self::$x`) は動的メンバとして数えない** (意図的な対象外。理由は施策 3)
5. **`DYNAMIC_MEMBER_INVENTORY` は安全の証明ではない**。受容した未解決箇所の在庫であり、
   **同一ファイル内での置換は検出しない**
6. **既存の `ForbiddenStatementTokenInvariantTest` / SSRF 検査 / LLM 防御の代替ではない**。
   対象語彙も走査域も方式も別である
7. **`tests/` 配下に禁止する識別子名**: `preset` / `arch` / `ignoring` / `toBeUsed` /
   callable 5 語彙と完全一致するメソッド名・関数名・定数名を作らないこと

### PHPStan 適合チェック

- [x] `phpstan.neon` は**触らない** (既存方針。`adoption-debt.tsv` 凍結済みパスでもある)
- [x] 実装時に `vendor/bin/phpstan analyse --level=10 tests/Support/Architecture tests/Architecture/ArchBaselineTest.php tests/Unit/Architecture/ArchBaselineScannerTest.php` を
      **コマンドライン引数で** 1 度確認する (設定ファイルは変更しない)
- [x] `mixed` へ widen しない / 配列 shape を PHPDoc で固定

### テスト計画

- [x] **バグ修正ではない**ので再現テストは不要
- [x] 既存テストの更新: **なし** (131 本に触れない)
- [x] 新規テスト: `ArchBaselineTest` 自身が gate 兼テスト。
      **テストファースト**: 施策 6 を先に書いて赤を確認 → 走査器 → gate の順
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (DB を使わない)
- [x] **緑になることの実測**: `composer test` の Architecture レーンで全 7 規則が緑。
      本設計の事前実測では 3 根 892 ファイルに対し禁止語彙の使用は
      `sha1` 1 / `tempnam` 1 / `var_export` 3 の**計 5 件・4 クラス**だけで、
      すべて AB-5/6/7 の例外に載っている

### リスク

- **`->ignoring([])` (空配列) の挙動**: `GroupArchExpectation::ignoring()` は
  内部の各 expectation へ委譲するだけなので空配列は no-op になる (vendor 実読)。
  ただし**実装時に 7 本すべてが緑になることを実測して確認する** (推測で済ませない)
- **実行時間**: Pest arch は PSR-4 全ファイルを php-parser で読む。
  3 根 892 ファイル × 7 規則。`ObjectsRepository` が prefix 単位でキャッシュするので
  パースは 1 回だが、**Architecture レーンの実行時間が伸びる可能性がある**。
  実装時に計測し、体感で問題があれば規則数ではなく**レーン配置**で対処する
  (規則を束ね直すのは I2 を壊すので**選択肢に入れない**)
- **並列実行**: `composer test` は `--parallel`。arch テストは DB を使わないので
  並列化に伴う競合は無い

---

## 施策 6: `tests/Unit/Architecture/ArchBaselineScannerTest.php` — 検出力の裏取り

### 変更箇所

- ファイル: `tests/Unit/Architecture/ArchBaselineScannerTest.php` (新規)

### 非交渉の実装規約

**すべての負例・正例は nowdoc / heredoc の文字列としてソースを組み立てて走査器へ渡す。**
実コードとして書かない。理由は 2 つ:

1. 本ファイル自身が S4 の母集団 (`tests/` 全数) に入るため、
   実コードで `\call_user_func(...)` や `->{$m}()` を書くと**S4 が即座に赤くなる**
2. 合成入力なら「母集団が 0 件」と「違反が 0 件」を分離でき、
   実コードの件数に検出力を依存させない (共通規約 (b) の 3 番目)

既存の準拠実装: `tests/Unit/Architecture/FakeWiringSourceScannerTest.php` の
`fakeWiringScannerSource()` ヘルパ。同じ作法に揃える。

### テスト一覧 (施策 2〜4 の「テスト計画」を集約)

| # | 対象 | 種別 | 内容 |
|---|---|---|---|
| 1 | `GlobalFunctionCallScanner` | 正 | `FakeObjectStore` 実ソースで `sha1` >= 1 |
| 2 | 〃 | 正 | `\sha1(` (完全修飾) を数える |
| 3 | 〃 | 負 | メソッド宣言 / interface 宣言 / `->extract(` / `::extract(` を数えない (実クラス 2 本を使用) |
| 4 | 〃 | 負 | `mysha1` / `getenv` / `sha1_file` の 3 形を数えない |
| 5 | 〃 | 負 | 不在パスで例外 |
| 6 | 〃 | 正 | 0 件でも配列のキーが残る |
| 7 | `ArchSurfaceScanner::identifierSites` | 負 | docblock / 文字列内の `preset` `call_user_func` を数えない |
| 8 | `ArchSurfaceScanner::statementTokens` | 正 | 期待形が `EXPECTED_CHAIN_TOKENS` と一致 |
| 9 | 〃 | 負 | `->ignoring([Foo::class])` 直書き形が一致しない |
| 10 | 〃 | 負 | チェーン 2 本 / `->not->toBeUsed()` 欠落が一致しない |
| 11 | `dynamicMemberSites` | 負 | `->{$m}()` / `?->{$m}()` / `::{$m}()` / `->$m()` / `A::$m()` を拾う |
| 12 | 〃 | 正 | `self::$x` / `A::$prop` を拾わない (`A::$m()` と隣接配置) |
| 13 | 〃 | 正 | `->{'literal'}()` を拾う (綴りは確定するが、本走査器は `->` 側を区別しない = 広く数える) |
| 14 | `resolvedFunctionCallSites` | 負 | `\call_user_func(...)` を `resolved` で拾う |
| 15 | 〃 | 負 | `use function call_user_func as invoke; invoke(...)` を `resolved` で拾う |
| 16 | 〃 | 負 | group use `use function A\{call_user_func as f};` を解く |
| 17 | 〃 | 正 | `mycall_user_func(` / `->call_user_func(` / `::call_user_func(` を拾わない |
| 18 | 〃 | 負 | 解けない `use function` 形で `unresolved` を返す |
| 19 | 〃 | 負 | 波括弧形の複数名前空間宣言で `unresolved` を返す |
| 20 | 〃 | 正 | 名前空間内の非修飾 `call_user_func(` を安全側で `resolved` にする |
| 21 | `VendorArchPresetReader` | 正 | 3 preset の語彙が `73 / 20 / 6`、和集合 97 |
| 22 | 〃 | 負 | 配列なし / 配列 2 個 / 可変要素で例外 |

### PHPStan 適合チェック

- [x] ヘルパの戻り値型を明示
- [x] `mixed` を使わない

### リスク

- テスト 3 が参照する `TakeThumbnailExtractor::extract()` / `SopTextExtractor::extract()` は
  **実クラスのメソッド名に依存する**。改名されるとテストが壊れるが、それは
  「取り違えの負例が現実の分岐を使っている」ことの代償であり許容する
  (概念設計で確定済み)。壊れたら別の同名メソッドへ差し替える。

---

## 施策 7: 乖離台帳 D40 の登録と件数 pin

### 変更箇所

- `docs/template-divergence.md`: 末尾へ `## D40 …` を 1 件追記 + 冒頭の宣言行を `36 件` → `37 件`
- `tests/Support/TemplateDivergence/LedgerPins.php`: `DIVERGENCE_ENTRY_COUNT` `36` → `37`

### 採番の根拠 (main `2dc4e2ec` 実測)

| 値 | 実測 | 扱い |
|---|---|---|
| 登録済みの最大 D 番号 | **D39** | 番号は再利用しないので新番号は **D40** |
| 実エントリ数 (`## D<n>` 見出し 37 個 − 書式節の見本 `## D1 <逸脱の要約>` 1 個) | **36 件** | **37 件**へ |
| `LedgerPins::DIVERGENCE_ENTRY_COUNT` | 36 | **37** へ |
| `LedgerPins::FINGERPRINT_POPULATION_COUNT` | 281 | **据え置き** (新設 6 パスは 281 キーに不在。実測確認済み) |
| `LedgerPins::ADOPTION_DEBT_COUNT` | 171 | **据え置き** (新設パスは債務一覧に無い) |

> ⚠ **これらの値は実装着手時に main でもう一度読み直すこと**。
> 他 TODO のマージで動く値であり、本件そのものがその実例である
> (前任の設計は D37 / 36→37 と書いていたが、その後 D37〜D39 が登録されていた)。

### 登録メタ表 (9 行ちょうど・この順序)

| 行 | 値 |
|---|---|
| 対象パス | `tests/Architecture/ArchBaselineTest.php` / `tests/Support/Architecture/ArchBaseline.php` / `tests/Support/Architecture/ArchSurfaceScanner.php` / `tests/Support/Architecture/GlobalFunctionCallScanner.php` / `tests/Support/Architecture/VendorArchPresetReader.php` / `tests/Unit/Architecture/ArchBaselineScannerTest.php` |
| 業務要件起因の説明 | 家系の正典 v1 は禁止シンボルを規則ごとに分解して例外の波及半径を 1 シンボルに閉じることを求めるが、正典の 9 規則 102 シンボルという分解はテンプレート側の例外クラス構成から出た数である。本アプリの走査域で禁止語彙を実使用しているのは 3 語彙 4 クラスだけであり、母集団に対する正しい分解は例外なし 4 束 + 単独シンボル 3 本の 7 規則になる。正典の本数をそのまま写すと実体の無い規則が生まれる |
| 揃え続ける不変条件と保証機構 | 例外を持つ規則の対象シンボルがちょうど 1 個であること (`ArchBaselineTest` の S3) / 7 規則の語彙の和集合が vendor preset の禁止語彙集合と一致すること (S5。移植漏れと vendor 更新の両方を検出) / 例外の置き場が `ArchBaseline` 1 クラスに限られ arch のチェーンが 1 本であること (S4 が `tests/` 全数を母集団に完全一致で照合) |
| 再判定の条件 | 正典が per-rule 分解の規約そのものを変えたとき / Pest の preset 構成が変わり集合一致が取れなくなったとき / 本アプリで層分離規則 (`toOnlyBeUsedIn` 等) を導入するとき |
| 決めた日 | `2026-08-23` |
| 決めた人 | `開発者` |
| 根拠 | `devnotes/20260823-0020-pest-arch-baseline-per-rule-adoption/` |
| 状態 | `恒久` |
| 見直し期限 | `—` |

### エントリ本文に書くこと

- 正典との差は**規則の本数だけ**であり、規約 (分解の仕方・例外の置き場・自己検査 5 部) は写していること
- 語彙の側は I6 の集合一致で取りこぼしゼロを機械証明するので「本数が違う = 移植漏れ」にはならないこと
- **97 語彙のうち 65 語彙は本環境で検出力を持たない** (V2) こと。ただし
  集合一致のために規則からは外さない

### 波及変更

- `TemplateDivergenceLedgerFormatTest` が宣言行・見出しの実数・定数の**3 点一致**を強制するので、
  3 つを**同じコミット**で直す
- 対象パスは**全登録の和集合で重複しないこと**が要求される → 新設 6 パスは既存登録に無い (実測確認済み)
- `TemplateDivergenceFingerprintTest`: 新設パスは指紋台帳 281 キーに不在なので沈黙する。
  突合の等式は `{全登録の対象パス} ∩ {母集合}` を取るため、**母集合外の登録をしても 3b で落ちない**

### テスト計画

- [x] `composer test -- --filter=TemplateDivergence` 相当で形式検査と突合が緑
- [x] 対象パス 6 件がすべて実在すること (形式検査が強制)

### リスク

- 実装時点で D 番号や件数が動いている可能性が高い。**着手時に必ず読み直す** (上記の警告)

---

## 施策 8: 概念設計の訂正 (V1 の記述誤り)

### 変更箇所

- `devnotes/20260823-0020-pest-arch-baseline-per-rule-adoption/conceptual-design.md` の
  `GlobalFunctionCallScanner` の「背景 (契約ではない)」段落

### 変更内容

「Pest 側の使用判定は `ObjectUses::getByName()` の接尾辞一致である。Pest は `mysha1()` まで拾う」
→
「Pest 側の使用判定は `DependenciesAsserts::getObjectsWhichUsesOnLayerAFromLayerB()` の
**名前の完全一致**である (`$objectToSearch->name === $use`)。
`getByName()` の接尾辞一致は docblock 型の名前空間解決だけで使われ、`toBeUsed` の経路には現れない。
S2 が狭く数えるのは**Pest と同じ粒度に揃えるため**であり、`mysha1` は**両者とも数えない**」

### 波及変更

- 概念設計の負例の説明 1 行 (「`mysha1` は Pest は拾うが S2 は数えない負のコントロール」→
  「両者とも数えないことを固定する負例」)
- **設計判断そのものは変わらない** (狭く数える / 負例に 3 形を置く は据え置き)

### リスク

- なし (文書の事実訂正)。**検出範囲を変えないので AGENTS.md §走査器・gate を新設・変更するときに
  同じ PR で揃える 4 点の発火条件には当たらない**

---

## 実装モード

| 項目 | 内容 |
|---|---|
| 推奨モード | **standalone** |
| 判断根拠 | 新設 6 ファイルは既存コードから 1 行も参照されない完全な追加であり、施策 1〜6 は相互依存が強く分割すると赤のまま止まる中間状態が生まれる (テストファーストで走査器 → gate の順に緑にする必要がある)。一方で施策 7 は `docs/template-divergence.md` と `LedgerPins.php` という**他 TODO も触る共有ファイル**を変更するため、他の作業と並走させると衝突する。1 本の worktree で一気に通すのが最短 |
| 競合リスク | **中**。`docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` は他 TODO のマージで頻繁に動く。**worktree 作成直後と main マージ直前の 2 回、D 番号と件数 pin を読み直す**こと。アプリコード・既存 131 本には触れないので、そちら側の衝突は構造的に起きない |

---

## 変更ファイル一覧

| ファイル | 種別 |
|---|---|
| `tests/Support/Architecture/ArchBaseline.php` | 新規 |
| `tests/Support/Architecture/GlobalFunctionCallScanner.php` | 新規 |
| `tests/Support/Architecture/ArchSurfaceScanner.php` | 新規 |
| `tests/Support/Architecture/VendorArchPresetReader.php` | 新規 |
| `tests/Architecture/ArchBaselineTest.php` | 新規 |
| `tests/Unit/Architecture/ArchBaselineScannerTest.php` | 新規 |
| `docs/template-divergence.md` | 追記 (D40 + 宣言行) |
| `tests/Support/TemplateDivergence/LedgerPins.php` | 1 定数 (36 → 37) |
| `devnotes/20260823-0020-.../conceptual-design.md` | 訂正 (施策 8) |

**アプリコード (`app/` `routes/` `config/` `database/` `resources/`) は 1 行も変更しない。**
**既存 131 本の Architecture テストは 1 本も削除・置換しない。**
**`phpstan.neon` / `composer.json` / CI ワークフロー / `tests/Pest.php` / `docs/TODO.md` は触らない。**

## 検証コマンド

```
composer test        # Architecture / Unit レーンで新設 gate が緑
composer phpstan     # 既存対象 (tests/ は対象外) が緑のまま
vendor/bin/pint --test
vendor/bin/phpstan analyse --level=10 \
  tests/Support/Architecture tests/Architecture/ArchBaselineTest.php \
  tests/Unit/Architecture/ArchBaselineScannerTest.php   # 設定ファイルは変更しない
```

---

## 概念設計 (承認済み。参考)

# 概念設計: pest-arch-baseline-per-rule-adoption

> ✅ **本設計は Round 6 で全体判定 APPROVED に到達した** (`conceptual-review-round-6.md`)。
> Critical は Round 5 で 0 件になり、Round 6 は Critical 0 / Warning 0 / Suggestion のみ。
> 判定の推移と各ラウンドの対応は `codex-history/conceptual-review-decisions-round-{1..6}.md`。
>
> **実測値の基準コミット**: 本文の実測値はすべて **main `2dc4e2ec` (2026-08-22 / JST 2026-08-23)**
> で再測定した値である。Round 5 と Round 6 の間に、前任者の測定のうち 2 点を訂正した
> (`dynamicMemberSites()` の定義と実測件数の不整合 / 乖離台帳の D 番号と件数 pin の陳腐化)。
> 訂正の詳細は `codex-history/conceptual-review-decisions-round-5.md` の「Round 6 送付前の自己訂正」。

## 背景・課題

### 家系の正典と裁定

家系の機能台帳 lctl の feature `arch-baseline-pest` (canonical_version: v1、origin: aigenba、
gate: `laravel-claude-template:tests/Architecture/ArchBaselineTest.php`) は、
**Pest のアーキテクチャ検査 (arch API) を安全に使うための構成パターン**を定めている。

塞ぐ穴は 1 つである。Pest の既製規則セット (preset) は禁止シンボルを **1 本の表明へ束ねて**持つ:

```php
expect(['md5', 'sha1', 'uniqid', 'rand', /* … 20 語彙 */])->not->toBeUsed();
```

ここへ `->ignoring(FakeObjectStore::class)` を 1 個渡すと、**その 1 クラスが 20 語彙すべての
検査対象から外れる**。`sha1()` を使うために登録した例外が、同じクラスの中の `eval()` や
`unserialize()` まで無検査にする。**例外登録 1 件の波及半径がセット全体**になるのがこの穴で、
正典 v1 はこれを次の 3 要素で塞ぐ:

1. **規則ごとの分解**: preset へ一括で `ignoring` を渡さず、規則を 1 本ずつの `arch()` 表明に割る。
   **例外を要する対象は、その対象だけを見る規則へ分ける**(=例外つき規則の対象シンボルは 1 個)
2. **例外一覧の単一の置き場**: 全規則の禁止対象配列と例外許可リストを 1 クラス
   (`tests/Support/Architecture/ArchBaseline.php`) へ集約する
3. **自己検査 5 部**: 規則ごとの期待シンボル数の pin / 登録済み例外クラスが対象シンボルを
   **実使用していることの逆向き証明** / 構造契約 / 例外の形式検査とサーフェスの pin /
   vendor preset との集合一致

オーナー裁定 **AG-167 (2026-08-13)** は「spirux と aicue も本機構へ追従させ、家系 6/6 で機構を揃える。
**既存の自作 Architecture テスト群は維持したまま併存させる**」と定めた。
キュレーターは「両アプリは arch API 未使用なので前提が無い」として条件付き対象外を推奨したが、
オーナーは機構の統一を選んでいる (「導入により今後 arch API を使い始めた際の一括除外の穴も
最初から塞がれる」)。

### aicue の現状 (本設計での実測。**2026-08-22 / JST 2026-08-23 00:20 の HEAD `2dc4e2ec`**)

| 観測 | 値 | 確認方法 |
|---|---|---|
| Pest arch API の利用 | **0 件** | `tests/` 全体で `arch(` に一致する行はすべて `array_search(` の一部。`Pest\Arch` の取り込みも 0 件 |
| `tests/Support/Architecture/` | **不在** | ディレクトリごと存在しない |
| `ArchBaseline` を含むファイル | **0 件** | `git ls-files \| grep -i archbaseline` が空 |
| `tests/Architecture/*.php` | **131 本** | 全て自作のファイル走査 / リフレクション型 deny-by-default 目録 |
| `pestphp/pest` | `^4.7` (arch plugin 同梱) | `vendor/pestphp/pest-plugin-arch/` が実在 |
| `tests/Pest.php` の arch 記述 | **無し** | Architecture レーンは `->in('Architecture')` で TestCase だけを束ねている |
| `tests/` 配下の追跡 PHP (S4 の母集団) | **803 本** | `git ls-files 'tests/**/*.php'` |
| `arch` / `ignoring` / `toBeUsed` / `preset` の**識別子トークン** | 各 **0 件** | `token_get_all()` で `tests/` 803 本を走査。散文中の出現 (`preset` 1 件 / callable 系 2 件) はすべて `T_COMMENT` で識別子ではない |
| callable 実行語彙 5 種の識別子・完全修飾名 | **0 件** | 同上。`T_NAME_FULLY_QUALIFIED` / `T_NAME_QUALIFIED` でも 0 件 |
| 名前が動的に決まるメンバ参照 | **7 件 / 6 ファイル** | 同上 (定義は「塞ぐ (1)」。`tests/Architecture/` は 0 件) |

つまり aicue には**「穴の前提となる API 利用」自体がまだ無い**。
これは「入れる必要が無い」ではなく「**入れるなら最初から穴の無い形で入れる**」という状況である。
今 preset を素直に使い始めると、最初の例外登録の時点で正典が塞いだ穴をそのまま作ることになる。

### 禁止シンボルの実使用 (母集団の実測)

Pest の arch は **composer の PSR-4 名前空間**を走査根にし、`Composer::userNamespaces()` が
`<root>/tests` 配下のディレクトリを除外する (vendor 実装で確認)。
したがって aicue での走査域は **`App\` (app/) / `Database\Factories\` / `Database\Seeders\`** の 3 根であり、
`Tests\` は入らない。

この 3 根を `token_get_all()` ベースで走査した結果、
**php / security / laravel の 3 preset が禁止する全 97 語彙のうち、実使用があるのは 3 語彙・4 クラスだけ**だった:

| シンボル | 使用クラス | 用途 |
|---|---|---|
| `sha1` | `App\Services\Storage\Fakes\FakeObjectStore` | ローカル fake のロックファイル名生成 (暗号用途ではない) |
| `tempnam` | `App\Services\Manual\SopTextExtractor` | SOP 取込の一時ファイル |
| `var_export` | `App\Support\ProductionEnvGuard` / `App\Support\QueueDispatchAtomicityGuard` | 診断メッセージの値の可視化 |

**例外の母集団が極小である**ことが本設計の最大の追い風である。
「例外を要するシンボルは単独規則へ切り出す」という正典の規約を、
**実際に 3 本の単独規則を作るだけ**で完全に満たせる。

---

## 改善アイデア

**Pest arch API のベースラインを、正典 v1 の per-rule 形で新設する。**
既存 131 本には一切触れない (裁定どおり併存)。

### 中核となる不変条件 (これを機械で守る)

| # | 不変条件 | 守る機構 |
|---|---|---|
| I1 | **preset へ一括 `ignoring` を渡さない**。`tests/` 配下の追跡 PHP 全数で `preset` の識別子出現が 0 件 | S4 (サーフェスの pin。母集団は `tests/` 全数) |
| I2 | **例外を持つ規則の対象シンボルはちょうど 1 個** (= どの規則も他の規則の対象を隠さない) | S3 (構造契約) |
| I3 | **例外一覧は `ArchBaseline` 1 クラスにだけ在る**。arch のチェーンは **S4 が解決対象とする構文の中で 1 本**だけで、その**トークン列が期待形と完全一致**する。動的メンバ名は**未解決として落とし**、callable 経由の実行語彙は 0 件で固定する | S4 (母集団は `tests/` 全数。チェーンの完全一致照合 + 動的メンバ名の exact-fit 目録 + callable 実行語彙の deny-by-default) |
| I4 | **登録した例外は実在し、そのソースに対象シンボルと綴りがトークン完全一致する素の関数呼び出しが 1 件以上ある** (登録の腐敗検出。構文上の契約) | S2 (逆向き証明) |
| I5 | **規則ごとの対象シンボル数を pin する** (無断の増減で赤) | S1 (期待値の pin) |
| I6 | **vendor preset の語彙集合と、本ベースラインの語彙の和集合が一致する** | S5 (vendor preset との集合一致) |
| I7 | **アプリコード (`app/` `routes/` `config/` `database/` `resources/`) と既存 131 本の Architecture テストを 1 行も変更しない** | 変更対象を新設 6 ファイル + 乖離台帳 2 ファイルに限る |

I2 が正典の核心である。**例外を要する語彙を単独規則へ隔離すれば、`ignoring` の波及半径は
定義上ゼロになる** — 束ねられた他の語彙が存在しないからである。
I2 を機械で固定することで、将来「例外を足したいから既存の束へ ignoring を付ける」という
一番起きやすい退行が構造的に落ちる。

I1 / I3 は**自ファイルの検査では足りない**。別のテストファイルで `preset()->ignoring(...)` を
書けば同じ穴が復活するので、母集団は **`tests/` 配下の git 追跡 PHP 全数**にする。
さらに**件数の pin だけでも足りない** — 許可された 1 箇所の `ignoring` へ
`[SomeUnregisteredClass::class]` を直書きすれば件数は変わらないまま台帳を迂回できる。
そこで表明の生成を `foreach` 1 本へ閉じ、**チェーンのトークン列そのもの**を
期待形と完全一致で照合する (下記「A. 禁止表明」)。

**識別子の件数 pin だけでも、まだ足りない** — `->{$method}(...)` のような動的メンバ名を使えば
`ignoring` という綴りを 1 度も書かずに同じ操作ができる。
共通規約 (b) は「保証範囲の外にした構文で保護対象の操作を書ける場合は、
検出力の主張を狭めるか、**未解決として失敗させる**」と定める。
本設計は**費用ゼロで塞げる分は塞ぎ、残りは主張を狭める**という 2 段構えを採る:

- **塞ぐ (1)**: `tests/` 全数の**名前が動的に決まるメンバ参照**を exact-fit の目録で固定する
  (実測 **7 件 / 6 ファイル**。`tests/Architecture/` には 0 件)

  > **「動的」の定義 (概念設計で確定させる)**: 塞ぎたいのは
  > **メンバ名の綴りが静的に決まらない形**である。`->` / `?->` / `::` の直後を見て、
  > 次の 5 形を動的とする —
  > (i) `->{expr}` / (ii) `?->{expr}` / (iii) `::{expr}` / (iv) `->$var` / `?->$var` /
  > (v) `::$var` が**直後に `(` を伴う**形 (PHP の可変静的メソッド呼び出し `A::$m()`)。
  > **`::$var` が `(` を伴わない形は動的ではない** — それは `self::$violations` のような
  > **静的プロパティ参照**で、メンバ名 (`violations`) は綴りとして確定している。
  > この 1 形を混ぜると `tests/` 全数の実測が **7 件 / 6 ファイル → 52 件 / 14 ファイル**へ膨らみ、
  > 増えた 45 件はすべて arch と無関係な静的プロパティ参照になる。
  > 30 文字以上の根拠を 45 件書かせる目録は、正典が塞ごうとした「例外の膨張で
  > 検知が空洞化する」状態を自分で作る行為なので採らない。
  > **「広く数える」は無差別に数えることではなく、`->$var` (動的プロパティ) と
  > 可変静的メソッド呼び出しを取りこぼさないという意味である**
  > (メソッド呼び出しとプロパティ参照の区別は、`->` 側では**しない** = 広く数える)。
- **塞ぐ (2)**: `call_user_func` / `call_user_func_array` / `forward_static_call` /
  `forward_static_call_array` の 4 **関数**と、`fromCallable` という**メソッド名**の出現を
  **`tests/` 全数で 0 件**に固定する
  (実測でいずれも 0 件。**目録すら要らず既存テストへの影響もゼロ**)。

  > **走査契約 (概念設計で確定させる。実装方法は詳細設計へ送る)**:
  > 4 関数は素の識別子 (`T_STRING`) だけを見ない。
  > **完全修飾名 (`\call_user_func` = `T_NAME_FULLY_QUALIFIED`) と修飾名 (`T_NAME_QUALIFIED`) を
  > 正規化して完全修飾関数名として照合し、`use function` / group use / 別名つき取り込みを解いて
  > alias 経由の呼び出し (`use function call_user_func as invoke; invoke(...)`) も検出する**
  > (共通規約 (a))。
  > **解決できない取り込み・呼び出し形は未解決として失敗させる** (共通規約 (b))。
  > 走査結果は「解決済み関数名」と「未解決」を**判別できる形**で返し、
  > **未解決を黙って候補から外さない**。
  > `fromCallable` はメソッド名なので別契約 (メンバ名としての完全一致) で検出する。
  > 正例・負例に `\call_user_func(...)` と `use function ... as ...` を必ず置く。

> **I3 の保証範囲 (誇張しない)**: I3 が保証するのは、**識別子・完全修飾名・別名取り込みを解いて
> 解決できる静的なチェーンと、可変メンバ構文、および上記 callable 実行語彙まで**である。
> `ReflectionMethod` / `ReflectionFunction` 経由の反射呼び出し (既存テストが
> **`tests/` 全数で 41 件 / 25 ファイル**、うち `tests/Architecture/` で正当に使っており、
> 目録にすると本 gate と無関係な 41 件を握ることになる) と、
> それ以外の未知の間接実行経路からは同じ操作を書ける。
> **この構文について検出力を主張しない** (共通規約 (b) の「検出力の主張をその構文を除く形へ
> 明示的に狭める」側)。正典 v1 が塞ぐと定めたのは「preset へ一括 ignoring を渡す」という
> **人が普通に書く形**の穴であり、反射で arch の内部 API を叩く形は正典の想定外である。

### 規則の構成 (aicue の母集団に合わせた per-rule 分解)

例外の要否で 2 群に割る。**例外を持たない規則だけが複数語彙を束ねてよい** (束ねても
`ignoring` が無いので穴が生まれない)。

| 規則 ID | 対象 | 例外 |
|---|---|---|
| AB-1 | php preset のデバッグ / 出力 / 実行制御系の語彙 (`dump` `var_dump` `phpinfo` `debug_backtrace` `echo` `print` `goto` `global` `die` `trap` `ray` `ds` 等) | 無し |
| AB-2 | php preset の旧 `mysql_*` 手続き API 14 語彙 + `ereg` / `eregi` | 無し |
| AB-3 | laravel preset の開発補助語彙 (`dd` `ddd` `env` `exit`。php preset と重なる `dump` / `ray` は AB-1 が持つ) | 無し |
| AB-4 | security preset のうち例外不要な 17 語彙 (`md5` `uniqid` `rand` `mt_rand` `eval` `exec` `shell_exec` `system` `passthru` `unserialize` `extract` `dl` `assert` 等) | 無し |
| AB-5 | `sha1` **のみ** | `FakeObjectStore` |
| AB-6 | `tempnam` **のみ** | `SopTextExtractor` |
| AB-7 | `var_export` **のみ** | `ProductionEnvGuard` / `QueueDispatchAtomicityGuard` |

- **正典の「9 規則 102 シンボル」をそのまま写さない**。正典の 9 という数はテンプレートの母集団
  (テンプレート側の例外クラス構成) から出た数であり、aicue の母集団に対する正しい分解は 7 本である。
  正典が求めているのは**分解の規約**であって規則の本数ではない (「例外を要する対象は、
  その対象だけを見る規則へ分ける決まりにしてある」)。
  語彙の側は I6 (vendor preset との集合一致) で**取りこぼしゼロを機械で証明する**ので、
  「本数が違う = 移植漏れ」にはならない。
- 語彙集合の正本は **vendor preset の配列**である。ArchBaseline は語彙を 7 規則へ**分割して**持ち、
  自己検査が「7 規則の和集合 == php ∪ security ∪ laravel の禁止語彙」を突き合わせる。
  **preset の語彙が vendor 更新で増えたら、どの規則にも属さない語彙として赤になる**。

### 成果物 (新設 6 ファイル + 乖離台帳 2 ファイル)

走査ロジックは値の置き場から分離し、aicue の既存作法
(`tests/Support/` の純関数 + `tests/Unit/Architecture/` の自己検査) に揃える。

| ファイル | 役割 |
|---|---|
| `tests/Support/Architecture/ArchBaseline.php` | **値の置き場**。規則 ID => `{symbols, exceptions, rationale}` と、動的メンバ名の目録 (ファイル => `{count, rationale}`)。解析・ファイル I/O・git 実行を一切持たない (`LedgerPins` と同型) |
| `tests/Support/Architecture/GlobalFunctionCallScanner.php` | S2 用。**対象名と綴りがトークン完全一致する素の関数呼び出しだけを狭く数える**純関数 |
| `tests/Support/Architecture/ArchSurfaceScanner.php` | S4 用。識別子出現の列挙 / 文末までのトークン列切り出し / 動的メンバ名の列挙を返す純関数。**広く数える** |
| `tests/Support/Architecture/VendorArchPresetReader.php` | S5 用。vendor preset ソースから禁止語彙集合を抽出。fail-closed |
| `tests/Architecture/ArchBaselineTest.php` | gate。`foreach` 1 本からの `arch()` 表明 7 本 + 自己検査 5 部 |
| `tests/Unit/Architecture/ArchBaselineScannerTest.php` | 3 走査器の**負例と正例** |
| `docs/template-divergence.md` (追記) | 逸脱の登録 1 件 = **D40**。併せて冒頭の宣言行「登録エントリ: 36 件」→「37 件」 |
| `tests/Support/TemplateDivergence/LedgerPins.php` (1 定数) | `DIVERGENCE_ENTRY_COUNT` **36 → 37** |

> **採番の再確認 (main `2dc4e2ec` 実測)**: 登録済みの最大番号は **D39** で、実エントリは **36 件**
> (`## D<n>` 見出しは 37 個あるが、うち 1 個は書式節の見本 `## D1 <逸脱の要約>` である)。
> **番号は再利用しない**決まり (欠番は D9 / D29 / D36 で正常) なので新番号は **D40** になる。
> `LedgerPins::FINGERPRINT_POPULATION_COUNT` は **281 のまま変えない**
> (新設 6 パスは指紋台帳 `docs/template-fingerprints.json` の 281 キーに**不在**であることを実測確認済み)。
> `ADOPTION_DEBT_COUNT` (171) も変えない (新設パスは債務一覧に無い)。
> **これらの pin は実装着手時にもう一度 main で読み直す** — 他 TODO のマージで動きうる値である。

---

## 期待効果

### 使命への貢献

aicue の使命は「専門知識ゼロの現場作業者が標準化されたマニュアル動画を作れるようにする」ことであり、
本改善は直接には UI にも撮影フローにも触れない。**寄与は間接的だが構造的**である:

- aicue のセキュリティ不変条件 (AGENTS.md §セキュリティ不変条件 1〜11) は
  **131 本の deny-by-default 目録**という一点に依存している。
  「禁止したはずの書き方が検査を素通りする」形の穴は、この依存を静かに空洞化させる。
  撮影 PWA が依存する 3 枚セット (no-store / bfcache 秘匿 / Inertia 履歴暗号化) のように
  **壊れても画面上は何も起きない**保護ほど、機械の網の健全性そのものが品質になる。
- 正典が塞ぐのは「**検査は緑なのに穴が開いていた**」型の事故であり、これは AGENTS.md
  §静的検査 (gate) と走査器の共通規約が 5 条とも実測事故から出ていると明記している型と同じである。
  今 arch API を穴の無い形で入れておけば、将来 arch を使い始める時点で穴が生まれない。

### 具体的な改善見込み

- **Pest Arch が静的に解決できるシンボル使用に対する網が新設される** (禁止語彙 97)。
  既存 131 本には「禁止関数の網に相当する gate」が無い (lctl の観測どおり)。
  既存の `ForbiddenStatementTokenInvariantTest` は **`echo` / `goto` / `global` / 開始タグ付き出力記法の
  4 語彙だけ**を字句で見るもので、対象も方式も別物である (正典側も
  `forbidden-statement-token-gate` との関係を `distinct_from` として「統合しない」と宣言済み)。
- **例外登録の腐敗が検出できる**。`sha1` の使用をやめたのに例外登録が残る、
  クラスを改名したのに登録が古いまま、といった状態が赤になる (I4)。
  aicue の既存目録群と同じ「登録の腐りを落とす」思想を arch 側にも持ち込む。
- **家系 6/6 で機構が揃う** (裁定 AG-167 の達成)。

### 保証しないもの (誇張しない)

**保証範囲の異なる 2 つを混ぜない**。(a) は「禁止語彙の網の限界」、(b) は
「ベースライン構造を守る仕組みの迂回路」であり、読み手にとって意味がまったく違う。

**(a) Pest Arch の禁止語彙検出が保証しないもの**

- **97 語彙のすべてに検出力があるわけではない** (詳細設計フェーズの vendor 実読で判明。
  `detailed-design.md` の V2)。Pest が依存側の層を作れるのは
  `PhpCoreExpressions` が扱う言語構文 (5 語彙) と、実行時に `function_exists()` が真になる
  関数 (本環境で 27 語彙) だけで、**残り 65 語彙は層が空 = 規則が絶対に落ちない**。
  うち `mysql_*` 14 + `ereg` + `eregi` + `create_function` の 17 語彙は
  **PHP 8 で削除済みなので恒久的に不活性**、`xdebug_*` 40 + `ray` `ds` `ddd` `trap` の
  48 語彙は**拡張・パッケージの有無で変わる環境依存**である。
  それでも規則から外さないのは I6 (vendor preset との集合一致) を保つためであり、
  **検出力は主張しない**。
- 効くのは **Pest Arch が静的に解決できるシンボル使用**だけである。
  可変関数 (`$f = 'sha1'; $f()`)、`call_user_func('sha1')` のような文字列経由の呼び出し、
  外部プロセス、eval 内の綴りには**無言で効かない**。
- 走査域は **`App\` / `Database\Factories\` / `Database\Seeders\` の 3 根**だけである。
  `Tests\` は Pest arch 自身が除外するので**テスト側の禁止関数は 1 件も見ない**。
  `.blade.php` / `resources/js/` も対象外。
- **既存の token gate (`ForbiddenStatementTokenInvariantTest`) / SSRF 検査 / LLM 防御の代替ではない**。
  対象語彙も走査域も方式も別で、どちらか一方があれば他方が要らないという関係にはならない。

**(b) ベースライン構造を守る S4 が保証しないもの**

- `ReflectionMethod` / `ReflectionFunction` 経由の反射呼び出しからは、
  識別子として `ignoring` を書かずに同じ操作ができる。
  既存テストが `tests/` 全数で 41 件 / 25 ファイル正当に使っているため目録にはしない。
  **この構文について検出力を主張しない**。
- **静的プロパティ参照 (`self::$x`) は動的メンバとして数えない**。
  メンバ名が確定しているので迂回口ではないが、`::` の直後の変数を一律に動的と見なす
  実装にすると 45 件の無関係な登録を要求することになるため、
  **`(` を伴わない `::$var` は意図的に対象外**にしてある (詳細は「塞ぐ (1)」)。
- それ以外の未知の間接実行経路も同様である。
  塞いであるのは「識別子として書かれた静的なチェーン」「可変メンバ構文」
  「callable 実行語彙 5 種」の 3 つまでである。
- 動的メンバ名の目録は「**受容した未解決箇所**」であって安全の証明ではなく、
  **同一ファイル内での置換は検出しない**。
- 母集団は `tests/` 配下の git 追跡 PHP に限る。`tests/js/` と `.blade.php` は見ない。

---

## 実装方針（概要）

### `tests/Support/Architecture/ArchBaseline.php` — 値の置き場

- `final class`、インスタンス化しない (private コンストラクタ)。
- 規則の正本は `RULES` 定数 1 本。各規則は
  `{symbols: list<string>, exceptions: list<class-string>, rationale: string}`。
- `rationale` は **30 文字以上**を要求する (aicue の目録規約と同じ強度。例外の登録操作が
  レビューで必ず見えるようにする)。例外を持たない規則の `rationale` は「なぜこの束が
  例外を要しないか」を書く。
- アクセサは純関数 (`ruleIds()` / `descriptionOf()` / `symbolsOf()` / `exceptionsOf()` / `allSymbols()`)。
  **解析・ファイル I/O・git 実行を持たない**。
- 第 2 の定数として**動的メンバ名の目録** (`array<string, array{count: int, rationale: string}>`) を持つ。
  これは arch の例外ではなく「**走査器が解決できない形の在庫**」だが、
  正典の「値の置き場は 1 つ」に従い同じクラスへ置き、docblock で役割を分ける。
  各行は 30 文字以上の根拠を持つ。
  **意味を誇張しない** — この目録は「**人手で用途を確認して受容した未解決箇所**」であって
  安全であることの証明ではない。**同一ファイル内での置換は検出しない**
  (件数が変わらないため)。目録は **0 件を許容する** (全件が正当に除去された状態は望ましい状態であり、
  それを赤にすると不要な動的構文を残す圧力になる)。

### `tests/Architecture/ArchBaselineTest.php` — gate

**A. 禁止表明 (規則ごとに独立した `arch()` を、単一の生成点から作る)**

7 本を手書きせず、`ArchBaseline::ruleIds()` の **`foreach` 1 本**から生成する:

```php
foreach (ArchBaseline::ruleIds() as $ruleId) {
    arch(ArchBaseline::descriptionOf($ruleId))
        ->expect(ArchBaseline::symbolsOf($ruleId))
        ->not->toBeUsed()
        ->ignoring(ArchBaseline::exceptionsOf($ruleId));
}
```

- **`preset(` は 1 度も呼ばない**。規則は `ArchBaseline` から 1 本ずつ展開される。
- **`ignoring` の呼び出し箇所はリポジトリ全体で 1 つ**になる。
  これにより S4 は「`arch` 識別子の出現は `tests/` 全数でちょうど 1 件」に加えて
  「**その 1 件から文末 `;` までのトークン列が期待形と完全一致する**」まで固定できる:

  ```
  arch ( ArchBaseline :: descriptionOf ( $ruleId ) )
    -> expect ( ArchBaseline :: symbolsOf ( $ruleId ) )
    -> not -> toBeUsed ( )
    -> ignoring ( ArchBaseline :: exceptionsOf ( $ruleId ) ) ;
  ```

  件数 pin だけでは防げない「許可された口へ生のクラス名を直書きする」迂回が塞がる。
- 照合は**識別子単位ではなくチェーン単位**で行う。`expect(` は全テストに大量に現れるので
  件数 pin は成立しない — チェーン内での位置と引数が完全一致照合で固定されることで
  **語彙の直書き**も同時に塞がる。
- `arch()` は `TestCall` を返す通常のテスト宣言関数なので (vendor 実装
  `pest-plugin-arch/src/Autoload.php` で確認)、`foreach` の中から呼んでよい。
  テスト名は規則 ID を含むので一意になる (規則 ID の一意性は S3 が固定)。

**B. 自己検査 5 部**

| 部 | 検査 | 落ちる条件 |
|---|---|---|
| S1 期待値の pin | 規則ごとの対象シンボル数を定数で pin | 語彙が無断で増減した |
| S2 逆向き証明 | 各例外クラスのソースを `GlobalFunctionCallScanner` で走査し、対象シンボルの**素の関数呼び出し**が 1 件以上あること | 登録が腐った (使用をやめた / 改名した / そもそも使っていない) |
| S3 構造契約 | 例外を持つ規則の対象シンボルはちょうど 1 個 / 規則 ID は一意 / 語彙は全規則を通じて重複しない / 例外クラスは実在し PSR-4 走査域内 / `rationale` は 30 文字以上 | 分解の規約が壊れた |
| S4 サーフェスの pin | **`tests/` 配下の git 追跡 PHP 全数**を母集団に、(1) `preset` の識別子出現 0 件 / (2) `arch` `ignoring` `toBeUsed` の識別子出現が各ちょうど 1 件 / (3) `arch` の出現から文末までの**トークン列が期待形と完全一致** / (4) 動的メンバ名が**目録とファイル別件数まで exact-fit** / (5) callable 実行語彙 5 種の識別子出現 0 件 | 例外の置き場が二重化した / preset 一括使用が復活した / 生のクラス名を直書きした / 動的ディスパッチで綴りを回避した |
| S5 vendor preset との集合一致 | 7 規則の和集合 == php ∪ security ∪ laravel preset の禁止語彙集合 | vendor 更新で語彙が増減した / 移植漏れ |

### 3 つの走査器の設計方針

**`GlobalFunctionCallScanner` (S2 用) — 構文上の使用証明。狭く数える**

S2 は「違反の検出」ではなく「**使用の証明**」なので、**倒す向きが他の走査と逆**である。
数えすぎ = 腐った登録を見逃す (危険) / 数え漏らし = 赤 (安全)。

**契約は構文上のものに限定する** —
「登録クラスのソースに、対象シンボルと**綴りがトークン完全一致する素の関数呼び出し**が
1 件以上存在する」。**「Pest がその使用を検出する」ことは保証しない** (vendor の内部意味論に
契約をぶら下げないため)。

- 数える: `sha1(` / `\sha1(`
- 数えない: `->sha1(` / `?->sha1(` / `::sha1(` / `function sha1(` / `new sha1(` / 直前が識別子 /
  `mysha1(`
- 保証外 (数えない = 赤へ倒す): 可変関数・文字列経由の呼び出し
- ファイルが読めない / トークン化できない場合は**無言で 0 件にせず例外**
- 背景 (契約ではない。**詳細設計フェーズの vendor 実読で訂正済み**): Pest 側の使用判定は
  `PHPUnit\Architecture\Asserts\Dependencies\DependenciesAsserts::getObjectsWhichUsesOnLayerAFromLayerB()` の
  **名前の完全一致** (`$objectToSearch->name === $use`) である。
  `ObjectUses::getByName()` の接尾辞一致は **docblock 型の名前空間解決だけ**で使われ、
  `toBeUsed` の判定経路には現れない。
  したがって S2 が狭く数えるのは「Pest の接尾辞一致を真似ない」ためではなく、
  **Pest と同じ粒度に揃えるため**であり、`mysha1` は**両者とも数えない**。
  なお、この差で登録が保守的に余ることがあっても**穴にはならない** —
  I2 が blast radius を 1 シンボルに抑えているので、余った例外が隠せるのは
  「その 1 シンボルの、その 1 クラスでの使用」だけだからである。
  (訂正の経緯は `detailed-design.md` の V1)

**`ArchSurfaceScanner` (S4 用) — 広く数え、チェーンの形まで照合する**

こちらは「違反の検出」なので拾いすぎる方向へ倒す。

- `identifierSites()`: 識別子トークンの**完全一致**で出現位置を返す
  (部分文字列一致・正規表現の語境界に頼らない)。
  **コメント (`T_COMMENT` / `T_DOC_COMMENT`) と文字列リテラルの中身は識別子ではないので数えない**。
  これは形式的な注記ではなく**現に効いている分岐**である — 実測で `preset` は
  `ForbiddenStatementTokenInvariantTest` の docblock に 1 件、callable 語彙は
  `CacheGuardWiringGateTest` / `JobDeferralTerminationGateTest` の docblock に 2 件現れており、
  素の文字列検索で数えると S4 の「0 件」は初日から赤くなる。
  逆に**この除外を「解決できない形の黙殺」と取り違えない** — 語彙を説明する散文は
  実行経路ではないので、共通規約 (b) の未解決とは別物である
- `statementTokens()`: 指定位置から文末 `;` までの**綴り列**を返す (チェーンの完全一致照合用)
- `dynamicMemberSites()`: **メンバ名の綴りが静的に決まらない位置**を返す (定義は
  「塞ぐ (1)」の枠内に確定させた 5 形)。`->` / `?->` 側は
  **メソッド呼び出しとプロパティ参照を区別しない** (区別には波括弧の対応付けが要るところを、
  区別せず広く数える = 拾いすぎる方向 = 安全)。
  `::` 側だけは `(` の有無で**可変静的メソッド呼び出し (`A::$m()`) と
  静的プロパティ参照 (`self::$x`) を分ける** — 後者はメンバ名が確定しているので動的ではなく、
  混ぜると目録が 45 件の無関係な行で膨らむ。
  この分岐は**判定を狭める唯一の場所**なので、負例に `A::$m()` (拾う) と
  `self::$x` (拾わない) の**両方**を置いて固定する

戻り値は**型付き array shape の `list<>`** で返し (`list<array{line: int, index: int}>` /
`list<string>`)、`token_get_all()` の生の戻り値を走査器の外へ出さない。
値オブジェクトのファイルは増やさない。
保証しないもの (文字列経由の呼び出し・`.blade.php`・`tests/js/`) を docblock に明記する。

**`VendorArchPresetReader` (S5 用) — fail-closed**

- 入力元は `Pest\ArchPresets\{Php,Security,Laravel}` の**ソース**
  (`class_exists()` で実在を確認 → `ReflectionClass::getFileName()` で解決。パス直書きしない)。
- 抽出定義: `expect(` の直後に始まる配列リテラルのうち、閉じ括弧の後に `->not->toBeUsed()` が
  続くものの文字列要素。
- **期待する配列の個数を pin** (Php:1 / Security:1 / Laravel:1)。0 個でも 2 個でも赤。
- docblock に「**vendor の公開 API ではなくソース表現に依存する。`composer update` で赤くなり得るのは
  仕様であり、そのときはベースラインを更新する**」と明記する。

### 検出力の裏取り (AGENTS.md §静的検査の共通規約 (c))

`tests/Unit/Architecture/ArchBaselineScannerTest.php` が 3 走査器の**負例と正例**を持つ:

- 正例: `FakeObjectStore` の `sha1` を検出できる / preset ソースから語彙集合を取り出せる
- 負例 (取り違え): メソッド宣言・interface のメソッド宣言・メソッド呼び出し・静的呼び出しを
  関数呼び出しと取り違えない。**現実の分岐**として
  `App\Services\Manual\SopTextExtractor::extract()` と
  `App\Services\Capture\TakeThumbnailExtractor::extract()` を使う
  (security preset の `extract` と綴りが一致するため)
- 負例 (語彙): **接頭辞つき (`getenv` / `mysha1`) / 接尾辞つき (`sha1_file`) / 打ち消しつき**の 3 形が
  トークン完全一致で弾かれる (共通規約 (e))。`mysha1` は **Pest の完全一致判定と
  S2 の粒度が揃っている** (どちらも数えない) ことを固定する負のコントロールでもある
- 負例 (引数の出所): `->ignoring([Foo::class])` のような直書き形 / チェーンを 2 本へ増やした形 /
  `->not->toBeUsed()` を落とした形が、S4 の期待形照合で落ちる (Round 2 Critical の裏取り)
- 負例 (動的ディスパッチ): `->{$method}([Foo::class])` / `::{$m}()` / `->$m()` /
  `A::$m()` (可変静的メソッド呼び出し) を `dynamicMemberSites()` が拾い、
  目録に無いので S4 が落ちる (Round 3 Critical の裏取り)
- 正例 (動的ディスパッチの取り違え防止): `self::$x` / `A::$prop` のような
  **静的プロパティ参照を動的扱いしない**。`tests/` 実測 45 件がこの形で、
  拾うと目録が無関係な行で膨らむ (Round 6 前の自己訂正の裏取り。
  `A::$m()` と `A::$m` を隣り合わせに置いて `(` の有無だけで分かれることを固定する)
- 負例 (fail-closed): 読めないファイル / 期待する配列が見つからない preset ソースで例外になる

### 母集団が空でないことの検査 (共通規約 (b) の 3 番目)

- `ArchBaseline::RULES` が空でない / 各規則の `symbols` が空でない
- vendor preset から抽出した語彙集合が 3 つとも空でない
- S4 の走査根 (`tests/` 配下の追跡 PHP の一覧) が空でない (床値 + 代表パスを pin)
- **動的メンバ名の目録には非空を要求しない** (0 件は望ましい状態。
  走査器の検出力は**合成負例**で固定し、実コードの件数に依存させない)
- 例外クラスのソースファイルが解決できること (解決できなければ**無言で外さず**赤)

---

## 制約・前提

- **既存 131 本は 1 本も削除・置換しない** (裁定 AG-167 / `app-design` スキルの禁止事項 3 「既存テストの削除・上書き」)。
  アプリコード (`app/` `routes/` `config/` `database/` `resources/`) も 1 行も変更しない。
- **走査域は `App\` / `Database\Factories\` / `Database\Seeders\` の 3 根**。
  `Tests\` は Pest arch の `Composer::userNamespaces()` が除外するため入らない。
- **`phpstan.neon` は触らない**。aicue の PHPStan 対象は `app / config / database / routes` で
  **`tests/` を含まない**のが既存の方針であり、本設計はそれを変えない。
  加えて `phpstan.neon` は **採用時債務一覧 (`adoption-debt.tsv`) に凍結済み**のパスなので、
  触ると債務の扱い (戻す / 同期する / 逸脱登録する) の判断を巻き込む。**スコープ外**とする。
  代わりに型の受入条件を持つ (下記)。
- **型の受入条件** (「PHPStan level 10 を通せる」とは主張しない):
  - `mixed` や曖昧な配列へ widen しない
  - `RULES` の shape を PHPDoc で固定し、アクセサの戻り値まで型を一貫させる
  - 境界 (Reflection・token API・ファイル読み込み) は `Webmozart\Assert\Assert` で runtime に閉じる
  - 3 走査器の公開メソッドは**戻り値を正規化してから返す** (`list<string>` / 型付き array shape の `list<>`)。
    値オブジェクトのファイルは新設しない。
    `token_get_all()` の生の戻り値は走査器の外へ出さない
  - 実装時に `vendor/bin/phpstan analyse` へ新設パスを**コマンドライン引数で**渡して 1 度確認する
    (設定ファイルは変更しない)
- **`tests/Pest.php` は触らない**。arch 表明は Architecture レーンの通常のテストファイルとして走る。
- **乖離台帳**: 新設パスは `docs/template-fingerprints.json` のキーに**無い** (母集合 281 件に不在) ため
  突合 gate は現時点で沈黙する。ただし正典側には同名パスが実在し**内容は一致しない**ので、
  「登録するか迷ったら登録する」に従い `docs/template-divergence.md` へ **D40** を 1 件登録し、
  冒頭の宣言行 (36 件 → 37 件) と `LedgerPins::DIVERGENCE_ENTRY_COUNT` (36 → 37) を
  **同じ変更で**揃える (形式検査が宣言行・見出しの実数・定数の 3 点一致を強制する)。
  突合の等式は `{全登録の対象パス} ∩ {母集合}` を取るので、母集合外の登録は 3b (一致へ戻ったのに
  登録が残っている) で落ちない = 先回りの登録をしても安全である。

---

## スコープ外 (明示)

1. **層分離規則 (`toOnlyBeUsedIn` / `toOnlyUse` / `toBeUsedIn`) の導入**。
   実測で `App\Http\*` は `app/` 内の **12 ファイル以上**の他名前空間 (Exceptions / Enums /
   DataTransferObjects / Models / Auth) から使われており、Laravel preset の
   `expect('App\Http')->toOnlyBeUsedIn(['App\Http','App\Providers'])` を今入れると
   **巨大な allowlist を新設する**ことになる。それは正典が塞ごうとした「例外の膨張で
   検知が空洞化する」状態を自分で作る行為である。
   機構が入れば `RULES` へ 1 エントリ足すだけで後日 1 本ずつ追加できるので、
   **機構の導入と規則の拡張を分ける** (思考原則 2: 今必要なものだけ作る)。
2. **Laravel preset の構造契約 (`toHaveSuffix` / `toExtend` / `toImplement` / `toBeEnums` 等)**。
   これらは「禁止関数・層分離」のどちらでもなく、集合一致で健全性を証明できない
   (S5 の対象にならない) ため、同じ機構では守れない。
3. **既存 131 本の統廃合・移植**。裁定は併存を明示している。
4. **`docs/TODO.md` の変更** (本スキルの責務外)。
5. **CI ワークフロー・`composer.json` / `phpstan.neon` の変更**。
   新規テストは既存の Architecture レーンで走る。
6. **`AGENTS.md` §禁止事項への追記**。S4 が機械で固定するので文書への二重管理は避ける
   (詳細設計で最終判断する)。
7. **spirux 側の追従**。本設計は aicue のみを扱う。

---

## 関連する現行コード (抜粋)

### `tests/Support/TrackedPhpSourceFiles.php` (走査根の単一出典。新設走査器はこれを使う)

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

### `tests/Support/TemplateDivergence/LedgerPins.php` (値の置き場の準拠実装。`ArchBaseline` はこれと同型)

<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 逸脱の登録簿と指紋台帳の固定値 (不変の scalar 定数だけを持つ)。
 *
 * ★**解析・ファイル I/O・git 実行を一切持たない**。値の置き場所を 1 か所にするための型である。
 *   Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
 *   2 つの gate (形式検査と突合) が同じ値を読むにはクラス定数である必要がある。
 * ★**これは免除の一覧ではない**。個別のパスや D 番号を名指しして規則を免除する仕組みは
 *   本機構のどこにも無い。
 */
final class LedgerPins
{
    /** インスタンス化しない (定数の置き場)。 */
    private function __construct() {}

    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
    public const int DIVERGENCE_ENTRY_COUNT = 36;

    /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
    public const int FINGERPRINT_POPULATION_COUNT = 281;

    /**
     * 採用時債務の件数。
     *
     * ★機械が保証するのは**無断の増減の検出**までである (一覧と本定数を同じ変更で
     *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
     *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
     */
    public const int ADOPTION_DEBT_COUNT = 171;

    /**
     * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
     *
     * ★掃除の判定は**登録の存在**で行う (対象パスだけを見ると、一覧ファイルを消して
     *   対象パス欄から一覧パスだけを削り登録を残す、という中途半端な掃除が緑になる)。
     *   同定に使うので番号を pin する。
     *   ★**引退時に外すのは対象パスの 1 行だけで、登録そのものは残る** —
     *   一覧が 0 件になっても判定機構 (`AdoptionDebtInventory`) は残り続けるので、
     *   本アプリ固有の追加としての説明は要る (詳しくは同クラスの docblock)。
     */
    public const int ADOPTION_DEBT_DIVERGENCE_ID = 34;

    /** 取り込んだ正典台帳の generated_at_commit (指紋台帳の出自 pin)。 */
    public const string TEMPLATE_LEDGER_SOURCE_COMMIT = 'a078806b0574518ddc64966f60f7d536b1338b2f';

    /**
     * 取り込んだ正典台帳ファイル自身の sha256 (生成器の入力ガード)。
     *
     * 取得元は laravel-claude-template の `docs/template-fingerprints.json`
     * (読み取りコミット `0597a0c24d7fa7a054e3337704ccc97e4409b866` / 947 キー / 128420 バイト)。
     * 別の台帳を食わせるには生成器へ `--adopt-new-template-ledger` を明示する。
     */
    public const string TEMPLATE_LEDGER_SOURCE_SHA256 = '0c9add21dc79429f6d80e38cfeb95736af750bd760ee9584d2e2b8a1285c0c90';

    /** アプリ側の指紋台帳の置き場 (リポジトリ相対)。 */
    public const string FINGERPRINT_LEDGER_PATH = 'docs/template-fingerprints.json';
}

### vendor: `Pest\ArchPresets\Security` (S5 が読む対象の一例)

<?php

declare(strict_types=1);

namespace Pest\ArchPresets;

/**
 * @internal
 */
final class Security extends AbstractPreset
{
    /**
     * Executes the arch preset.
     */
    public function execute(): void
    {
        $this->expectations[] = expect([
            'md5',
            'sha1',
            'uniqid',
            'rand',
            'mt_rand',
            'tempnam',
            'str_shuffle',
            'shuffle',
            'array_rand',
            'eval',
            'exec',
            'shell_exec',
            'system',
            'passthru',
            'create_function',
            'unserialize',
            'extract',
            'mb_parse_str',
            'dl',
            'assert',
        ])->not->toBeUsed();
    }
}

### vendor: `Pest\Arch\Repositories\ObjectsRepository::allByNamespace()` (V2 の根拠)

```php
public function allByNamespace(string $namespace, bool $onlyUserDefinedUses = true): array
{
    if (PhpCoreExpressions::getClass($namespace) !== null) {
        return [FunctionDescription::make($namespace)];
    }

    if (function_exists($namespace) && (new ReflectionFunction($namespace))->getName() === $namespace) {
        return [FunctionDescription::make($namespace)];
    }

    $directoriesByNamespace = $this->directoriesByNamespace($namespace);

    if ($directoriesByNamespace === []) {
        return [];
    }
    // … PSR-4 のディレクトリからオブジェクトを作る
}
```

### vendor: `DependenciesAsserts::getObjectsWhichUsesOnLayerAFromLayerB()` (V1 の根拠)

```php
foreach ($layer as $object) {
    foreach ($object->uses as $use) {
        foreach ($layersToSearch as $layerToSearch) {
            if ($layer->equals($layerToSearch)) { continue; }
            foreach ($layerToSearch as $objectToSearch) {
                if ($objectToSearch->name === $use) {   // ★完全一致
                    $result[] = "$object->name <- $objectToSearch->name";
                }
            }
        }
    }
}
```

### vendor: `LayerFactory::make()` の除外 (V4 の根拠)

```php
$object->uses = new ObjectUses(array_values(array_filter(iterator_to_array($uses),
    function (string $use) use ($options): bool {
        foreach ($options->exclude as $exclude) {
            if (str_starts_with($use, $exclude)) { return false; }   // ★前方一致
        }
        return true;
    })));
// …
foreach ($options->exclude as $exclude) {
    $layer = $layer->excludeByNameStart($exclude);   // ★前方一致
}
```

### 実測データ (main `2dc4e2ec`。設計の pin の根拠)

- `tests/` 配下の git 追跡 PHP: **803 本**
- `tests/Architecture/*.php`: **131 本** (すべて自作。arch API は 1 件も使っていない)
- Pest arch の走査根 (`app/` + `database/factories/` + `database/seeders/`): **892 ファイル**
- そのうち 97 禁止語彙の使用: `sha1` 1 件 (`FakeObjectStore`) / `tempnam` 1 件 (`SopTextExtractor`) / `var_export` 3 件 (`ProductionEnvGuard` 2 / `QueueDispatchAtomicityGuard` 1) の**計 5 件・4 クラスのみ**
- `tests/` 全数の識別子トークン: `arch` / `ignoring` / `toBeUsed` / `preset` / callable 5 語彙は**すべて 0 件** (散文中の出現 3 件はすべて `T_COMMENT`)
- 名前が動的に決まるメンバ参照: **7 件 / 6 ファイル** (`::$prop` の静的プロパティ参照 45 件を除く)
- `ReflectionMethod` / `ReflectionFunction`: **41 件 / 25 ファイル**

---

## 本レビューで特に見てほしいこと

1. **共通規約 (b) との適合**: 65 語彙が不活性 (V2) である事実に対し、「規則から外さず検出力を主張しない / 件数を pin しない」という判断は妥当か。それとも「母集団が 0 件」に当たるので何らかの機械的な検査を足すべきか
2. **S4 の自己参照**: gate と走査器自身が母集団 (`tests/` 全数) に入る構造の危うさ。負例を nowdoc に閉じる規約と「禁止する識別子名」の docblock 明記で足りるか
3. **`resolvedFunctionCallSites()` の解決アルゴリズム**に見落としがないか (特に fail-closed の分岐)
4. **オーバーエンジニアリングになっていないか**。今必要なものだけ作れているか
5. 施策 5 の S3 に追加した第 7 条 (例外クラス名が他クラス名の真の接頭辞でないこと) は必要か、過剰か
