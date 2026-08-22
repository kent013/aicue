# 詳細設計レビュー Round 5

Round 4 の指摘 (Critical 0 / Warning 3) に**すべて対応した**。反論・見送りは 0 件である。

1. **V2 表の文言矛盾**: AB-2 / AB-4 の備考から「恒久的に不活性 / PHP 8 で削除済み」を外し、
   「PHP 8 標準環境に組み込みが無い。polyfill 等により活性化し得る」へ統一した。
2. **Pest オブジェクト集合の正規化**: `array_unique()` + `sort()` を明記し、
   S3 第 6 条 / 第 7 条 / 床値検査が**同じ正規化済み集合**を使うことを設計へ書いた。
   例外の包含判定は**大小を変換しない厳密比較**と定めた。
3. **正規化のテスト**: 施策 6 に No.37 を追加した。

設計方針の変更は無い (Round 4 の指摘どおり文言と正規化だけの修正である)。

---

## 対応マトリクス

# 対応マトリクス: design-review Round 4

## Round 4 の判定

- 全体判定: **CHANGES_REQUESTED**
- **[Critical] 0 件** / [Warning] 3 件 / [Suggestion] 0 件
- 施策別: **2 / 3 / 4 / 7 / 8 が APPROVE**、1 / 5 / 6 が REQUEST_CHANGES (いずれも Warning 1 件ずつ)
- Codex の総括: 「V6 の読み取りと S3 第 6 条の差し替えは妥当です。
  **設計上の本質的な問題は解消されています**。残件は文書内の矛盾 1 件と、
  Pest オブジェクト母集団を真の集合として扱うための正規化 1 件です。Critical はありません」
  「修正は小さく、**設計方針の再変更は不要です**」

**V6 の読み取りは全項目が正しいと確認された**:
「`findFirst()` はファイルを代表するオブジェクト名を決める / `$description->stmts` は
ファイル全体の AST を保持する / 依存収集はそのファイル全体の AST を走査する /
2 つ目以降のクラスの依存は最初のオブジェクトへ帰属する」。
帰結 (検出は落ちない / `ignoring` の実効単位はファイル / per-rule 分解により
別規則の語彙まで免除されることはない) も妥当と判定された。

**すべて対応した。反論・見送りは 0 件である。**

---

## [Warning] R4-1. V2 の規則別内訳表に「恒久的に不活性」が残っていて、本文と矛盾する

- 判断: **対応する**
- 根拠: 正しい。Round 2 の R2-5 対応で本文は
  「『恒久的に不活性』とは書かない — polyfill を入れれば活性化する」へ直したのに、
  **その直前の表の備考だけ直し忘れていた**。同一文書内の矛盾である。
- 対応内容: 表の備考 2 か所を書き換えた。
  - AB-2: 「PHP 8 で削除済み。恒久的に不活性」→
    「**PHP 8 標準環境に組み込みが無い**。polyfill 等により活性化し得る」
  - AB-4: 「`create_function` は PHP 8 で削除済み」→
    「`create_function` は PHP 8 標準環境に組み込みが無い (同上)」

## [Warning] R4-2. Pest オブジェクト集合が重複排除されていない

- 判断: **対応する**
- 根拠: 正しい。`Composer::userNamespaces()` に包含関係のある prefix が将来含まれると
  (例: `App\` と `App\Domain\` の両方が PSR-4 に登録される)、
  `allByNamespace()` が同じオブジェクトを複数回返し得る。
  包含検査と接頭辞衝突検査の**結果**はほぼ変わらないが、
  **床値 500 件の空振り検査が実数を表さなくなる** — 重複で水増しされた 500 件は
  「走査が生きている」ことの証拠にならない。これは共通規約 (b) の
  「母集団が空でないことの検査」を骨抜きにする。
- 対応内容: 収集後に `array_unique()` + `sort()` で**集合として正規化**し、
  **S3 第 6 条 (包含) / 第 7 条 (接頭辞衝突) / 床値検査がすべて同じ正規化済み集合を使う**ことを
  設計へ明記した。
  併せて例外の包含判定を**大小を変換しない厳密比較** (`in_array($name, $names, true)`) と定めた
  — クラス名は PHP では大小無視だが、**Pest の突き合わせが `===` である以上、
  こちらも同じ厳密さに揃えるのが正しい** (S2 が Pest の粒度に揃えているのと同じ理屈)。

## [Warning] R4-3. 正規化の契約を固定するテストが無い

- 判断: **対応する**
- 対応内容: 施策 6 に No.37 を追加した —
  `['A\Foo', 'A\Foo', 'A\Bar']` → `['A\Bar', 'A\Foo']`。
  **重複が 1 件として扱われ、床値が重複で水増しされない**ことを固定する。

---

## 改訂版 詳細設計書 (全文)

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
2. `function_exists($name)` **かつ** `(new ReflectionFunction($name))->getName() === $name`
   → 関数として検出。**第 2 条件を落とさないこと** — `getName()` は
   **宣言時の正規名**を返す。vendor preset が対象とする現行の組み込み関数・ヘルパでは
   それが小文字なので、実質「語彙が小文字で書かれていること」を要求する条件になる
   (**ユーザー定義関数一般については、宣言時の綴りが返るので小文字とは限らない**)
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
| AB-2 | 16 | **0** | `mysql_*` 14 + `ereg` + `eregi` は **PHP 8 標準環境に組み込みが無い**。polyfill 等により活性化し得る |
| AB-3 | 4 | **3** | `ddd` は該当パッケージ未導入で不活性 |
| AB-4 | 18 | **17** | `create_function` は PHP 8 標準環境に組み込みが無い (同上) |
| AB-5 / AB-6 / AB-7 | 1 / 1 / 1 | **3** | `sha1` `tempnam` `var_export` はいずれも検出力あり |

- **上記の分類はすべて「基準コミットの実行環境での実測」であって契約ではない**。
  **活性判定は常に実行環境依存**である — polyfill やユーザー定義関数で
  `function_exists()` が真になり得るし、拡張やパッケージの有無でも変わる。
  そのうえで、不活性 65 語彙は性質の違う 2 群に分かれる:
  - **PHP 8 の標準環境に組み込みが存在しない** (17 語彙): `mysql_*` 14 + `ereg` + `eregi` +
    `create_function`。**「恒久的に不活性」とは書かない** — polyfill を入れれば活性化する
  - **拡張・パッケージの有無で変わる** (48 語彙): `xdebug_*` 40 は xdebug 拡張が
    読み込まれれば活性化し、`ray` `ds` `ddd` `trap` は該当パッケージを入れれば活性化する
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

### V6. Pest の解析単位は**ファイル**である (オブジェクトは 1 ファイル 1 個、依存はファイル全体から集める)

Round 2 で「2 つ目のクラスの依存が無視されるのか最初のオブジェクトへ帰属するのか、
`findFirst()` だけでは確定しない」と指摘されたので、**vendor を最後まで読んで確定させた**。

1. `PHPUnit\Architecture\Elements\ObjectDescriptionBase::make()` は
   ファイル全体を parse した `$stmts` を保持したうえで、
   `findFirst()` で **最初の** `Class_` / `Trait_` / `Interface_` / `Enum_` を 1 つだけ取り、
   その名前を `ObjectDescription::$name` にする (`$description->stmts = $stmts` = **ファイル全体**)
2. `ObjectDependenciesDescription::make()` は
   `findInstanceOf($description->stmts, Node\Name::class)` で依存名を集める。
   走査対象は **1 で保持したファイル全体の AST** である

→ **確定した挙動**: 2 つ目以降のクラスは**独立したオブジェクトにならない**が、
その中の名前参照は**最初のオブジェクトの依存として帰属する**。

- **帰結 1 (検出は落ちない)**: 1 ファイルの 2 つ目のクラスで `sha1()` を呼んでも、
  最初のクラスの依存として**検出される**。見逃しにはならない
- **帰結 2 (例外の波及半径は「クラス」ではなく「ファイル」)**:
  `->ignoring(FakeObjectStore::class)` は、**そのファイル全体の使用**を除外する。
  同じファイルに 2 つ目のクラスを足してそこで `eval()` を呼んでも、
  AB-5 は `sha1` しか見ないので隠れないが、**AB-5 の対象である `sha1` については
  ファイル全体が免除される**。gate の docblock に「保証しないもの」として書く
- **帰結 3 (S3 第 7 条)**: 「例外クラスと同じファイルに `FakeObjectStoreDouble` を足して
  前方一致除外に巻き込ませる」形は**成立しない**。2 つ目のクラスは
  Pest のオブジェクト集合に入らないので、独立した除外対象にならない。
  したがって S3 第 7 条の母集団は **Pest のオブジェクト集合そのもの**でよく、
  それが**定義上ずれない**唯一の取り方である

### V5. Pest の使用判定は**大文字小文字を区別する** (`SHA1()` は検出されない)

Round 1 レビューの「関数名の大小差を実測で固定せよ」を受けて vendor を再読した。

- `ObjectsRepository::allByNamespace($v)` は `FunctionDescription::make($v)` を返し、
  同メソッドは **`name = $v` (渡した綴りそのもの)** を設定する
  (`(new ReflectionFunction($v))->getName()` はファイルパス解決にしか使われない)。
  vendor preset の語彙はすべて小文字なので**層側の名前は小文字**になる
  (`getName()` が返すのは**宣言時の正規名**であり、
  ユーザー定義関数一般について小文字だと主張しているのではない)
- `ObjectDependenciesDescription::make()` は AST の `Node\Name` を `toCodeString()` した
  **綴りそのまま**を `uses` に入れる。`SHA1(` と書けば `SHA1` が入る
  (`function_exists()` の絞り込みは大小無視なので**残ってしまう**)
- 突き合わせは `$objectToSearch->name === $use` の**完全一致**

→ **`SHA1($key)` は Pest arch に検出されない。** これは vendor の限界である。

**設計への反映 (意図的な非対称)**:

| | 大小の扱い | 理由 |
|---|---|---|
| **S2** (`GlobalFunctionCallScanner`。使用の証明) | **区別する** | Pest の粒度に揃える。`SHA1(` しか無いクラスの例外登録は S2 で赤になるが、Pest も検出しない以上**登録を消すのが正しい** |
| **S4** (`ArchSurfaceScanner`。サーフェスの pin) | **無視する** | PHP の関数呼び出しは大小無視で成立するので、`\CALL_USER_FUNC(` を見逃すと迂回口になる |

**この非対称は gate の docblock に理由付きで明記する** (読み手が必ずつまずくため)。
併せて `RULES` の語彙がすべて小文字であることを S3 に条として足す
(`allByNamespace()` の第 2 条件により、大文字混じりの綴りは**層が空になり黙って無効化される**)。

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

**実装順序**:

```
1 → 6 (走査器が未実装で赤いことを確認) → 2, 3, 4 → 6 (緑) → 5 → 7 → 8
```

テストファースト (思考原則 5) のため、**施策 6 の負例・正例を先に書いて赤を確認してから**
2〜4 の本体を書く。5 の gate は走査器が緑になってから足す。

---

## 施策 1: `tests/Support/Architecture/ArchBaseline.php` — 値の置き場

### 変更箇所

- ファイル: `tests/Support/Architecture/ArchBaseline.php` (新規)
- ディレクトリ `tests/Support/Architecture/` も新規 (現在不在)

### 波及変更

- TypeScript 型定義 / API Resource・DTO: **なし** (HTTP 経路もモデルも触らない)
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
 * ★**語彙はすべて小文字で書く**。vendor の `ObjectsRepository::allByNamespace()` は
 *   `function_exists($v) && (new ReflectionFunction($v))->getName() === $v` を関門にする。
 *   `getName()` が返すのは**宣言時の正規名**で、vendor preset が対象とする現行の
 *   組み込み関数・ヘルパではそれが小文字である。したがって
 *   **大文字混じりの綴りを書くと層が空になり黙って無効化される**。
 *   S3 が「語彙はすべて小文字」を機械で固定するのは、この
 *   **vendor 集合との一致を守るため**である
 *   (ユーザー定義関数一般について `getName()` が小文字を返すと主張しているわけではない)。
 *
 * ★**保証しないもの (検出力を誇張しない)**:
 *   本クラスが列挙する 97 語彙のうち、Pest arch が依存側の層を作れるのは
 *   (1) `Pest\Arch\Support\PhpCoreExpressions::getClass($v) !== null` の言語構文と
 *   (2) `function_exists($v) && (new ReflectionFunction($v))->getName() === $v` を満たす関数、
 *   の 2 つだけである。それ以外は層が空になり、**その語彙の規則は落ちようがない**。
 *   **活性判定は常に実行環境依存である** — polyfill やユーザー定義関数で
 *   `function_exists()` が真になり得るし、拡張やパッケージの有無でも変わる。
 *   基準コミット `2dc4e2ec` の実行環境での実測は「コア構文 5 + 実在関数 27 + 不活性 65」だが、
 *   **これは環境の観測値であって契約ではない**。
 *   **件数は pin しない** (環境差だけで赤くなる検査を作らないため)。
 *   分類の再計算方法は `ArchBaselineTest` の docblock に書いてある。
 */
final class ArchBaseline
{
    /** インスタンス化しない (定数の置き場)。 */
    private function __construct() {}

    /**
     * 規則の正本。
     *
     * `description` は arch のテスト名になる。**検出力を主張しない規則は
     * その旨を description に書く** (テスト一覧に出るため)。
     *
     * @var array<string, array{description: string, symbols: list<string>, exceptions: list<class-string>, rationale: string}>
     */
    public const array RULES = [
        'AB-1' => [
            'description' => 'AB-1: php preset のデバッグ・出力・実行制御系 56 語彙 (例外なし)',
            'symbols' => [/* vendor Php preset から mysql_* / ereg / eregi / var_export を除いた 56 語彙 */],
            'exceptions' => [],
            'rationale' => '診断出力・実行制御の語彙。アプリコードは Log ファサードと例外で診断するため例外を要しない',
        ],
        'AB-2' => [
            'description' => 'AB-2: PHP 8 標準環境に組み込みが存在しない手続き API 16 語彙 (vendor 集合との整合用。現環境では検出力を主張しない)',
            'symbols' => [/* mysql_* 14 + ereg + eregi = 16 語彙 */],
            'exceptions' => [],
            'rationale' => 'PHP 8 の標準環境には組み込みとして存在しないため書いても層が空になる。I6 の集合一致を保つための受け皿であり検出力は主張しない',
        ],
        'AB-3' => [
            'description' => 'AB-3: laravel preset の開発補助語彙 4 語彙 (例外なし)',
            'symbols' => ['dd', 'ddd', 'env', 'exit'],
            'exceptions' => [],
            'rationale' => 'Laravel の開発補助。env() は config 層だけの作法で app 配下は config() 経由に統一済みのため例外を要しない',
        ],
        'AB-4' => [
            'description' => 'AB-4: security preset のうち例外を要しない 18 語彙',
            'symbols' => [/* security preset から sha1 / tempnam を除いた 18 語彙 */],
            'exceptions' => [],
            'rationale' => '暗号・乱数・任意コード実行の語彙。乱数は Str::random と CipherSweet 経由に統一済みで例外を要しない',
        ],
        'AB-5' => [
            'description' => 'AB-5: sha1 のみ (例外 1 クラス)',
            'symbols' => ['sha1'],
            'exceptions' => [\App\Services\Storage\Fakes\FakeObjectStore::class],
            'rationale' => 'ローカル fake のロックファイル名生成に使う。暗号用途ではなく衝突しない一意名が要るだけである',
        ],
        'AB-6' => [
            'description' => 'AB-6: tempnam のみ (例外 1 クラス)',
            'symbols' => ['tempnam'],
            'exceptions' => [\App\Services\Manual\SopTextExtractor::class],
            'rationale' => 'SOP 取込で表計算ファイルを一時ファイルへ落とす。生成直後に unlink する短命な経路である',
        ],
        'AB-7' => [
            'description' => 'AB-7: var_export のみ (例外 2 クラス)',
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

    /** 7 規則の和集合の語彙数 (= vendor 3 preset の禁止語彙の和集合)。**総数 pin はここ 1 か所だけ**。 */
    public const int TOTAL_SYMBOL_COUNT = 97;

    /**
     * 名前が動的に決まるメンバ参照の目録 (ファイル => {count, rationale})。
     *
     * ★**これは arch の例外ではない**。「走査器が名前を解決できない形の在庫」であり、
     *   **人手で用途を確認して受容した未解決箇所**であって安全である証明ではない。
     * ★**同一ファイル内での置換は検出しない** (件数が変わらないため)。
     * ★**配列全体が空になることは許容する** (動的構文が 1 件も無い状態は望ましい)。
     *   ただし**登録行の `count` は 1 以上**でなければならない — `count: 0` の行は
     *   「かつて在ったが消えた」腐った登録である (S3 が固定)。
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
     * S4 が **`tests/` 全数でちょうど 1 件**に固定する名前 (**関数呼び出しとして**現れるもの)。
     *
     * ★`arch` だけがここに入る。`ignoring` / `toBeUsed` は
     *   `->toBeUsed()` / `->ignoring(...)` の形でしか現れない**メンバ名**なので、
     *   関数呼び出しの走査 (`functionNameSites()`) は**必ず 0 件を返す** —
     *   同じ契約で束ねると gate が初日から赤くなる。
     *
     * @var list<string>
     */
    public const array SINGLE_FUNCTION_NAMES = ['arch'];

    /**
     * S4 が **`tests/` 全数でちょうど 1 件**に固定する名前 (**メンバ名として**現れるもの)。
     *
     * ★走査は `identifierSites()` (識別子トークンの完全一致)。
     *   メンバ名を動的にして綴りを回避する形は `dynamicMemberSites()` の
     *   exact-fit 目録が別途塞ぐ。
     *
     * @var list<string>
     */
    public const array SINGLE_MEMBER_NAMES = ['ignoring', 'toBeUsed'];

    /**
     * S4 が **`tests/` 全数で 0 件**に固定する名前 (callable 経由の実行語彙)。
     *
     * `fromCallable` はメソッド名なので、走査契約は「呼び出し位置の末尾セグメント一致」
     * ではなく「メンバ名としての完全一致」で扱う (施策 3)。
     *
     * @var list<string>
     */
    public const array FORBIDDEN_CALLABLE_FUNCTIONS = [
        'call_user_func', 'call_user_func_array',
        'forward_static_call', 'forward_static_call_array',
    ];

    public const string FORBIDDEN_CALLABLE_METHOD = 'fromCallable';

    /** S4 が `tests/` 全数で 0 件に固定する名前 (preset の一括使用)。 */
    public const string FORBIDDEN_PRESET_NAME = 'preset';

    /** チェーンを 1 本だけ持つ gate ファイル (S4 が位置まで固定する)。 */
    public const string CHAIN_HOST_FILE = 'tests/Architecture/ArchBaselineTest.php';

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

    public static function descriptionOf(string $ruleId): string { /* RULES[$ruleId]['description'] */ }

    /** @return list<string> */
    public static function symbolsOf(string $ruleId): array { /* … */ }

    /** @return list<class-string> */
    public static function exceptionsOf(string $ruleId): array { /* … */ }

    /** @return list<string> 全規則の語彙の和集合 (重複なし・昇順) */
    public static function allSymbols(): array { /* … */ }
}
```

### 設計上の決定

- **`description` を明示のキーにする**。規則 ID から機械生成すると
  AB-2 のような「検出力を主張しない規則」を正直に名乗れない。テスト名として一覧に出るので、
  **主張の弱さがテスト一覧から見える**ことに価値がある。
- アクセサは未知の `$ruleId` で `Webmozart\Assert\Assert::keyExists()` により**例外**にする
  (無言で空配列を返さない = 共通規約 (b))。
- `const array` / `const int` / `const string` は PHP 8.3+ の型付きクラス定数。既存 `LedgerPins` と同じ書き方。

### PHPStan 適合チェック

- [ ] 戻り値の型が明示されている (`list<string>` / `list<class-string>` / `string`)
- [ ] null 安全 (`Assert::keyExists()` で境界を閉じる)
- [ ] DTO を返している — **該当なし** (値の置き場であり HTTP 応答を作らない)
- [ ] Generics の型パラメータが正しい (`array<string, array{...}>` の shape を PHPDoc で固定)

### テスト計画

- [ ] 本クラスは値だけなので専用テストを持たない。**契約は施策 5 の S1 / S3 が全部押さえる**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (DB を一切使わない)

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

### 公開 API (純関数 + 薄いラッパー)

```php
/**
 * @param  list<string>  $functionNames
 * @return array<string, int>  関数名 => 件数 (0 件でもキーを残す)
 */
public static function countCallsInSource(string $source, array $functionNames): array

/** ファイルを読んで countCallsInSource へ委譲するだけのラッパー。 */
public static function countCallsInFile(string $absolutePath, array $functionNames): array
```

**純関数を本体にする**理由は 2 つ — 施策 6 の合成負例 (nowdoc) を直接食わせられること、
走査ロジックがファイル I/O と混ざらないこと。

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
> 直前が識別子 / `mysha1(` / `not_sha1(` / `sha1_file(` / `Foo\sha1(`
>
> ★**大文字小文字を区別する**。`SHA1(` は数えない。これは
> **Pest 側の判定粒度に揃えるため**である — Pest は層の名前 (`RULES` の綴り = 小文字) と
> AST に書かれた綴りを `===` で突き合わせるので、`SHA1(` を**検出しない**
> (vendor 実読。`DependenciesAsserts::getObjectsWhichUsesOnLayerAFromLayerB()`)。
> したがって `SHA1(` しか無いクラスの例外登録は S2 で赤になるが、
> **それが正しい** — Pest が検出しない以上その例外登録は不要だからである。
> **S4 側は逆に大小を無視する** (施策 3)。理由が逆なので混同しないこと。
>
> **保証しない (数えない = 赤へ倒す)**: 可変関数 (`$f='sha1'; $f()`) /
> 文字列経由の呼び出し / `.blade.php` / `tests/js/`
>
> **トークン化できない入力は例外**にする (`token_get_all($source, TOKEN_PARSE)` +
> `ParseError` → 文脈付き `RuntimeException`)。**無言で 0 件を返さない**。

### 実装方針

1. `token_get_all($source, TOKEN_PARSE)` を `try` で囲み `ParseError` を
   `RuntimeException` へ変換する (**`TOKEN_PARSE` を付けないと不正構文が黙って通る**)
2. `T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` を落とした有意トークン列を作る
3. 各位置 `i` について:
   - `T_STRING` で綴りが対象名と**完全一致** → 候補
   - `T_NAME_FULLY_QUALIFIED` (`\sha1`) で先頭 `\` を除いた綴りが対象名と完全一致 → 候補
     (`T_NAME_QUALIFIED` = `Foo\sha1` は**別の関数**なので数えない)
   - 直後が `(` でなければ落とす
   - 直前が `T_OBJECT_OPERATOR` / `T_NULLSAFE_OBJECT_OPERATOR` / `T_DOUBLE_COLON` /
     `T_FUNCTION` / `T_NEW` / `T_CONST` / `T_STRING` のいずれかなら落とす
4. `array_fill_keys($functionNames, 0)` を初期値にして数える (**0 件でもキーを消さない**)

**接尾辞・接頭辞・打ち消しは原理的に混入しない** — トークンは `mysha1` / `not_sha1` /
`sha1_file` を 1 つの `T_STRING` として返すので、綴りの完全一致で自動的に弾かれる。
負例はこれを**固定するため**に置く (共通規約 (e) の 3 形)。

### PHPStan 適合チェック

- [ ] 戻り値の型 `array<string, int>` を明示
- [ ] `Assert::fileExists()` / `Assert::string()` で境界を閉じる (ラッパー側)
- [ ] `token_get_all()` の生の戻り値を**外へ出さない** (件数だけ返す)
- [ ] Generics の型パラメータが正しい

### テスト計画

施策 6 の表を参照 (No.1〜8)。

### リスク

- 数え漏らしは S2 を赤にするだけで**穴にはならない**。
  I2 が blast radius を 1 シンボルに抑えているため、余った例外が隠せるのは
  「その 1 シンボルの、その 1 クラスでの使用」だけである。

---

## 施策 3: `ArchSurfaceScanner` — S4 用 (広く数える。**名前解決はしない**)

### 変更箇所

- ファイル: `tests/Support/Architecture/ArchSurfaceScanner.php` (新規)

### 設計の要 (Round 1 [Critical] C1 / C2 への対応)

Round 1 で 2 つの Critical が出た:

- **C1**: `arch` / `ignoring` / `toBeUsed` を素の `T_STRING` 件数だけで pin していたので、
  `\arch(...)` や `use function Pest\arch as architectureRule;` で 2 本目の表明を作れた。
  → **`arch` 側にも callable 側と同じ走査契約を適用する**。
- **C2**: callable 側に用意していた「完全修飾名を解決する」走査契約は、PHP の名前解決規則を
  誤っており (`T_NAME_QUALIFIED` は現在の名前空間からの相対)、
  `T_NAME_RELATIVE` / カンマ区切り `use` / mixed group use / 複数 namespace が未設計だった。

C2 への対応として **「名前を解決する」設計そのものを捨てる**。
本 gate の契約は「**0 件**」または「**ちょうど 1 件**」という**件数**であって、
「どの関数が呼ばれているか」ではない。したがって
**末尾セグメントの一致で拾いすぎる方向へ倒せば、名前解決は 1 行も要らない**。

| 捨てた機構 | 代わりに何をするか |
|---|---|
| 取り込み対応表 (`use function` の alias 解決) | `use function …` 文に対象名の綴りが現れたら `import` として拾う。**`use` は静的構文なので、対象を取り込みつつ綴りが出ない書き方は存在しない** |
| 名前空間の把握と相対解決 | 何もしない。末尾セグメントだけを見る (`A\Foo\call_user_func()` も拾う = 拾いすぎ = 安全) |
| `T_NAME_RELATIVE` の特別扱い | 不要。末尾セグメントを取るだけ |
| 複数 namespace 宣言の検出 | 不要。判定に関与しない |
| 「未解決」という結果型 | 不要。解決の段が無いので到達しない。fail-closed は `TOKEN_PARSE` の例外と「拾いすぎる方向にしか倒れない」判定で担保する (共通規約 (d) が到達不能な結果型の収集を禁じる) |

**比較の単位は「名前トークンを `\` で割ったセグメントの、大小無視の完全一致」である**
(共通規約 (e))。部分文字列一致・正規表現の語境界には一切頼らない。

**これは「見逃す方向へ倒さない」(共通規約 (b)) を保ったまま設計を縮める変更である。**
`nikic/php-parser` の `NameResolver` を使う案 (Codex の提案) は採らない —
同ライブラリは `composer.json` に**宣言されていない推移的依存**であり、
既存 131 本の gate は 1 本も構文解析ライブラリを使っていない。
AGENTS.md 共通規約 (a) も「構文解析ライブラリの使用は必須ではない (裁定 AG-154 の (2))」と定める。

### 公開 API

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
 * ★開始位置が有意トークン列の範囲外のとき、および `;` に達する前に EOF になったときは
 *   **黙って空列や EOF までの列を返さず例外**にする (fail-closed)。
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
 *   区別するのは `::` 側だけで、**判定を狭める唯一の場所**である。
 *
 * @return list<array{line: int, index: int}>
 */
public static function dynamicMemberSites(string $source): array

/**
 * 対象の関数名が**呼び出し位置**または**関数取り込み**として現れる箇所を返す。
 *
 * **名前解決は一切行わない**。末尾セグメントの一致 (大小無視) で拾いすぎる方向へ倒す。
 *
 * - `call`: 直後が `(` の名前トークン (`T_STRING` / `T_NAME_QUALIFIED` /
 *   `T_NAME_FULLY_QUALIFIED` / `T_NAME_RELATIVE`) で、`\` で割った**末尾セグメント**が
 *   対象名と大小無視で一致するもの。直前が `->` / `?->` / `::` / `function` / `new` /
 *   `const` / 名前トークンのいずれかなら**メンバ名なので拾わない**
 * - `import`: `use` 文 (先頭から `;` まで) に現れる**名前トークンを `\` で割った
 *   各セグメント**のいずれかが、対象名と**大小無視の完全一致**をするもの。
 *   ★**部分文字列一致ではない** (共通規約 (e))。`use function A\mycall_user_func;` /
 *   `A\not_call_user_func` / `A\call_user_func_x` は**一致しない**。
 *   ★**構造を解かない**ので、カンマ区切り (`use function A\f, B\g as h;`)・
 *   group use (`use function A\{f, g as h};`)・mixed group use (`use A\{function f};`)・
 *   別名つきが**すべて同じ 1 本の規則で捕まる**。
 *   ★`use A\SomeClass;` のようなクラス取り込みも同じ規則にかかるが、
 *   対象名 (`arch` / callable 4 種) と完全一致するセグメントを持つクラス取り込みは
 *   そもそも作ってはならない (禁止する名前として docblock に明記する) ので問題にならない
 *
 * ★**`unresolved` は返さない**。名前解決の段が無い設計なので「解決できなかった状態」自体が
 *   存在しない。fail-closed は次の 2 つで担保する —
 *   (1) トークン化できない入力は `RuntimeException` (`TOKEN_PARSE`)、
 *   (2) 判定が**拾いすぎる方向にしか倒れない** (名前空間を解決しないので
 *   `A\B\call_user_func()` も拾う)。
 *   **到達できない結果型を収集しない** (共通規約 (d))。
 *   概念設計が要求した「未解決を判別できる形で返す」は、
 *   *名前解決を行う走査器*を前提にした条件であり、その前提ごと消えたことで満たされている
 *
 * ★**大文字小文字を無視する**。PHP の関数呼び出しは大小無視で成立するので、
 *   `\CALL_USER_FUNC(` を見逃すと迂回口になる。
 *   **S2 (`GlobalFunctionCallScanner`) は逆に大小を区別する** — あちらは
 *   「Pest が検出する使用の証明」なので Pest の粒度 (完全一致) に揃える必要がある。
 *   **この非対称は意図的である**。
 *
 * ★**保証しない**: 可変関数 (`$f = 'call_user_func'; $f()`) / 文字列連結で組み立てた名前 /
 *   `ReflectionFunction` 経由の呼び出し。**この構文について検出力を主張しない**。
 *
 * ★`call` は**有意トークン列上の `index` を必ず含む**。S4 はこの `index` を
 *   `statementTokens()` へ渡してチェーンを切り出すため、行番号だけでは実装できない
 *   (同じ行に複数の呼び出しがあると一意にならない)。
 *
 * @param  list<string>  $functionNames  セグメントの完全一致で照合する対象 (小文字)
 * @return list<array{status: 'call', name: string, line: int, index: int}
 *              |array{status: 'import', name: string, line: int}>
 */
public static function functionNameSites(string $source, array $functionNames): array
```

### すべての公開メソッドに共通する入力契約

`token_get_all($source, TOKEN_PARSE)` を使い、`ParseError` は
**文脈付き `RuntimeException` へ変換する**。無言で空配列を返さない。

### 母集団と走査根

- S4 の母集団は **`Tests\Support\TrackedPhpSourceFiles::all($root)` のうち
  `tests/` で始まる相対パス**。同じ列挙を 2 本持たない (AGENTS.md §本リポジトリでの置き方)。
- 実測 **803 本**。gate は**床値 + 代表パス**を pin する (施策 5)。

### PHPStan 適合チェック

- [ ] 戻り値の型 (判別 union の `list<>`) を明示。`mixed` へ widen しない
- [ ] `Assert` で境界 (index 範囲) を閉じる
- [ ] `token_get_all()` の生の戻り値を外へ出さない
- [ ] Generics の型パラメータが正しい

### テスト計画

施策 6 の表を参照 (No.9〜27)。

### リスク

- **走査器の自己参照**: 本走査器と gate 自身が `tests/` にあるため、
  **自分のソースに書いた語彙が自分の母集団に入る**。
  → 合成入力はすべて **nowdoc** で与え、実コードとして書かない
  (施策 6 の非交渉の規約。既存 `FakeWiringSourceScannerTest` と同じ作法)。
- **命名の禁止**: `tests/` 配下に `arch` / `ignoring` / `toBeUsed` / `preset` /
  callable 4 関数 / `fromCallable` と**セグメントが完全一致する名前**
  (メソッド名・関数名・`use` の取り込み) を作ってはならない。
  → gate の docblock に明記する。違反すれば S4 が即座に赤くなる (機械で守られる)。
- **拾いすぎの副作用**: セグメント一致なので、将来 `tests/` に
  `App\Support\Arch\arch()` のような自作関数を作ると S4 が赤くなる。
  これは**意図した挙動**であり、そのときは名前を変える (禁止する識別子名に含めてある)。

---

## 施策 4: `VendorArchPresetReader` — S5 用 (fail-closed)

### 変更箇所

- ファイル: `tests/Support/Architecture/VendorArchPresetReader.php` (新規)

### 公開 API (純関数 + 薄いラッパー)

```php
/**
 * preset ソースの文字列から「禁止語彙の配列」を抽出する純関数。
 *
 * @param  int  $expectedArrayCount  期待する配列リテラルの個数 (0 個でも超過でも例外)
 * @return list<string> 語彙 (重複なし・昇順)
 */
public static function forbiddenSymbolsFromSource(string $source, int $expectedArrayCount): array

/**
 * `Pest\ArchPresets\{Php,Security,Laravel}` の**ソース**から抽出する薄いラッパー。
 * `class_exists()` で実在を確認 → `ReflectionClass::getFileName()` で解決する
 * (**パスを直書きしない**)。
 */
public static function forbiddenSymbolsOf(string $presetClass): array
```

### 抽出定義 (docblock に書く正本)

> `expect(` の直後に始まる**配列リテラル**のうち、閉じ括弧の後に
> `->not->toBeUsed()` が続くものの文字列要素。
> `expect('App\Providers')->not->toBeUsed()` のような**文字列引数の形は対象外**である
> (層の指定であって禁止語彙ではない)。
>
> **配列要素の受け付け方 (fail-closed)**:
> - **単一引用符の `T_CONSTANT_ENCAPSED_STRING` だけ**を受け付ける
> - 解くエスケープは `\\` と `\'` の**2 つだけ**。それ以外のエスケープが現れたら例外
> - **キー付き要素 (`=>`) / spread (`...`) / 式 / ネストした配列 / 変数 /
>   二重引用符文字列 / ヒアドキュメントは、すべて例外**
> - 期待する配列の個数と実数が違えば例外 (0 個でも 2 個でも赤)
>
> ★**vendor の公開 API ではなくソース表現に依存する**。`composer update` で赤くなり得るのは
> **仕様**であり、そのときはベースラインを更新する。

### PHPStan 適合チェック

- [ ] 戻り値 `list<string>` を明示
- [ ] `Assert::classExists()` / `Assert::string($fileName)` で Reflection の境界を閉じる
- [ ] 生のトークンを外へ出さない
- [ ] `token_get_all($source, TOKEN_PARSE)` + `ParseError` → `RuntimeException`

### テスト計画

施策 6 の表を参照 (No.28〜30)。S3 の述語は No.31〜37。

**個別の語彙数 (`73 / 20 / 6`) は assert しない** (Round 1 [Warning] への対応)。
`ArchBaseline::TOTAL_SYMBOL_COUNT` = 97 が総数 pin の**唯一の置き場**で、
S5 は「3 集合の和集合 == `ArchBaseline::allSymbols()`」の集合一致だけを見る。

### リスク

- `composer update` で preset の書き方が変われば赤になる。これは**仕様**であり、
  そのとき `RULES` を更新する。docblock に明記する。

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
- `arch` / `ignoring` / `toBeUsed` の出現は**`tests/` 全数でちょうど 1 件ずつ**、
  かつその 1 件が `ArchBaseline::CHAIN_HOST_FILE` にある
- この 1 件から文末 `;` までのトークン列が `ArchBaseline::EXPECTED_CHAIN_TOKENS` と完全一致する
- `arch()` が `TestCall` を返す通常のテスト宣言関数であることは vendor 実読 + スパイクで確認済み (V3)

### B. 自己検査 5 部

| 部 | 検査内容 | 落ちる条件 |
|---|---|---|
| **S1** 期待値の pin | 下記 3 条 | 語彙が無断で増減した / 実効対象が空になった |
| **S2** 逆向き証明 | 各例外クラスの実ソースを `GlobalFunctionCallScanner::countCallsInFile()` で走査し、対象シンボルの**素の関数呼び出し**が 1 件以上 | 登録が腐った (使用をやめた / 改名した / そもそも使っていない) |
| **S3** 構造契約 | 下記 10 条 | 分解の規約が壊れた |
| **S4** サーフェスの pin | 下記 6 条 | 例外の置き場が二重化した / preset 一括使用が復活した / 生のクラス名を直書きした / 動的ディスパッチや完全修飾名・alias で綴りを回避した |
| **S5** vendor preset との集合一致 | 7 規則の和集合 == `VendorArchPresetReader` が 3 preset から抽出した語彙の和集合。加えて 3 集合が**それぞれ非空**で**代表語彙**を含む | vendor 更新で語彙が増減した / 移植漏れ / 抽出が壊れた |

**S1 の 3 条**:

1. 規則ごとの対象シンボル数が `SYMBOL_COUNT_PINS` と完全一致
2. 和集合の件数が `TOTAL_SYMBOL_COUNT` (97) と一致
3. **実効対象集合が非空**: 7 規則の和集合のうち、vendor と同じ述語
   `PhpCoreExpressions::getClass($v) !== null || (function_exists($v) && (new ReflectionFunction($v))->getName() === $v)`
   で活性と判定される語彙が**1 件以上**ある。
   **件数は pin しない** (xdebug の有無で 40 件動くため)。
   「1 件以上」なら環境差で揺れず、**gate 全体が実効ゼロになったこと**だけを捕まえる

**S3 の 10 条**:

1. **例外を持つ規則の対象シンボルはちょうど 1 個** (I2 = 正典の核心)
2. 規則 ID が一意
3. 語彙が**全規則を通じて重複しない** (和集合の件数 == 各規則の件数の総和)
4. **語彙がすべて小文字**である (大文字混じりは vendor 側で層が空になり黙って無効化される)
5. 例外クラスが**実在する** (`class_exists`)
6. **すべての例外クラス名が、Pest が実際に構築するオブジェクト名の集合に含まれる**
   (第 7 条と同じ母集団を使う)。
   これは**特定のクラスを名指しする pin ではなく、例外集合全体に対する一般化された検査**である —
   将来 5 件目の例外を足したとき、そのクラスが Pest の対象集合に入っていなければ
   **必ず落ちる**。
   ★**名前空間の接頭辞判定にも PSR-4 の実パス判定にもしない**。
   前者は classmap や非標準配置でも通り、後者は「パス配下にある」ことしか言えない。
   **Pest の集合に入っていること**が
   「Pest が実際に検査する対象である」という不変条件を**直接**証明する
   (Reflection でソースが読めることは S2 が別途閉じる)
7. **例外クラス名が、Pest が実際に構築するオブジェクト名の真の接頭辞になっていない**
   (V4 への対応。`ignoring` は `str_starts_with` の前方一致で除外するため、
   `FakeObjectStore` と `FakeObjectStoreDouble` が同時に存在すると後者も黙って除外される)
8. `rationale` が **30 文字以上**
9. `DYNAMIC_MEMBER_INVENTORY` の各 `rationale` が 30 文字以上、かつ各 `count` が **1 以上**
   (`count: 0` の行は腐った登録)
10. `description` が空でなく、規則 ID を含む

**第 7 条の実装** (Round 1 [Warning] / Round 2 [Critical] への対応):

```php
/**
 * 例外クラス名が、走査域の他クラス名の**真の接頭辞**になっているか (純関数)。
 *
 * @param  list<string>  $exceptionNames  例外に登録した完全修飾クラス名
 * @param  list<string>  $allClassNames   走査域の全完全修飾クラス名
 * @return list<string>  衝突の説明 (空なら衝突なし)
 */
function archBaselineProperPrefixCollisions(array $exceptionNames, array $allClassNames): array
```

- 判定は**純関数**として gate 内に置く。**合成負例を gate 内に置く**
  (共通規約 (c) が認める「gate 内の合成入力」):
  - 負例: `['A\Foo']` × `['A\Foo', 'A\FooDouble']` → 衝突 1 件
  - 正例: `['A\Foo']` × `['A\Foo', 'A\Bar']` → 衝突 0 件
  - 正例: `['A\Foo']` × `['A\Foo', 'A\Foo\Baz']` → **衝突 1 件**
    (`str_starts_with` は名前空間の区切りを見ないので、これも実際に巻き込まれる)
- 母集団は **Pest 自身が構築するオブジェクト名の集合をそのまま使う**
  (Round 2 [Critical] への対応):

  ```php
  $names = [];
  foreach (\Pest\Arch\Support\Composer::userNamespaces() as $namespace) {
      foreach (\Pest\Arch\Repositories\ObjectsRepository::getInstance()->allByNamespace($namespace) as $object) {
          $names[] = $object->name;
      }
  }

  // ★**集合として正規化してから使う**。`userNamespaces()` に包含関係のある prefix が
  //   将来含まれると同じオブジェクトが複数回列挙され、床値 500 件を**重複で**満たしてしまう。
  //   S3 第 6 条 (包含) / 第 7 条 (接頭辞衝突) / 床値検査は**すべてこの正規化済み集合を使う**。
  $names = array_values(array_unique($names));
  sort($names);
  ```

  例外の包含判定は**大小を変換せず厳密比較**で行う (`in_array($exceptionName, $names, true)`)。
  クラス名は PHP では大小無視だが、Pest の突き合わせが `===` である以上、
  **こちらも同じ厳密さに揃えるのが正しい** (S2 と同じ理屈)。

  **PSR-4 のパスからクラス名を推測する自前の列挙は採らない**。Round 2 の指摘どおり
  「1 ファイルに複数クラス / ファイル名とクラス名の不一致 / namespace 宣言が期待パスと違う /
  条件付き宣言」を取りこぼすからである。
  Pest のオブジェクト集合は**`ignoring` が実際に前方一致で除外する対象そのもの**なので、
  **定義上ずれようがない** (母集団と判定対象が同一)。
  `ObjectsRepository` は prefix 単位でキャッシュするので、arch 表明が既に読んだ結果を再利用する
  (パース費用は増えない)。
  vendor の `@internal` API への結合は増えるが、S5 が既に**vendor のソース表現**という
  より強い結合を持っており、そちらと同じく docblock に
  「`composer update` で赤くなり得るのは仕様」と明記する
- **母集団が空でないことを pin する**: 床値 (500 件以上) + 代表クラス
  (`App\Services\Storage\Fakes\FakeObjectStore` / `App\Support\ProductionEnvGuard` /
  `App\Support\QueueDispatchAtomicityGuard` / `App\Services\Manual\SopTextExtractor`) が
  すべて含まれること。**解決できなければ無言で外さず赤**
- ★**Pest は 1 ファイルにつき最初の 1 オブジェクトしか見ない** (V6)。
  したがって「同じファイルに 2 つ目のクラスを足して前方一致除外に巻き込ませる」形は
  **そもそも成立しない** (2 つ目のクラスは Pest のオブジェクト集合に入らない)。
  母集団を Pest 側から取ることで、この事実に**自動的に追随する**

**S4 の 6 条** (母集団 = `tests/` 配下の git 追跡 PHP 全数):

1. `preset` (`FORBIDDEN_PRESET_NAME`) の識別子出現が **0 件**
2. **`arch` (`SINGLE_FUNCTION_NAMES`) を `functionNameSites()` で検査する** —
   `call` **ちょうど 1 件**・`import` **0 件**、かつその `call` 1 件が `CHAIN_HOST_FILE` にある。
   **Round 1 [Critical] C1 への対応**: 素の識別子件数だけでは
   `\arch(...)` / `use function Pest\arch as x;` を見逃す
2b. **`ignoring` / `toBeUsed` (`SINGLE_MEMBER_NAMES`) は `identifierSites()` で
   各ちょうど 1 件**、かつ `CHAIN_HOST_FILE` にある。
   **Round 2 [Critical] への対応**: この 2 つは `->toBeUsed()` / `->ignoring(...)` の形でしか
   現れない**メンバ名**であり、`functionNameSites()` は「直前が `->` なら拾わない」契約なので
   **必ず 0 件を返す**。関数と同じ契約で束ねると gate が**初日から赤くなる**。
   メンバ名を動的にして綴りを回避する形は第 4 条 (動的メンバの exact-fit) が塞ぐので、
   分けても穴は開かない
3. **第 2 条で得た `arch` の `call` の `index`** から `statementTokens()` で切り出した
   トークン列が `EXPECTED_CHAIN_TOKENS` と**完全一致**する
   (行番号ではなく `index` を使う。同じ行に複数の呼び出しがあると一意にならない)
4. `dynamicMemberSites()` の結果が `DYNAMIC_MEMBER_INVENTORY` と
   **ファイル別件数まで exact-fit** (目録に無いファイルが 1 件でもあれば赤 /
   目録にあるのに実測が違えば赤)
5. `FORBIDDEN_CALLABLE_FUNCTIONS` 4 種が `functionNameSites()` で
   **`call` / `import` の 2 種とも 0 件**
6. `FORBIDDEN_CALLABLE_METHOD` (`fromCallable`) の識別子出現が **0 件**
   (メソッド名なので `identifierSites()` の別契約)

**母集団が空でないことの検査** (共通規約 (b) の 3 番目):

- `ArchBaseline::RULES` が空でない / 各規則の `symbols` が空でない
- vendor preset から抽出した語彙集合が 3 つとも空でない (S5 に含む)
- S4 の走査根 (`tests/` 配下の追跡 PHP) が **700 本以上** (床値) あり、
  **代表パス** (`tests/Pest.php` / `tests/TestCase.php` / `CHAIN_HOST_FILE`) が
  すべて母集団に含まれる
- S3 第 6/7 条の母集団 (Pest のオブジェクト名集合) が **500 件以上** + 代表クラス 4 件を含む
  (代表クラス pin は**空振り検査**であり、例外集合そのものの検査は S3 第 6 条が
  **一般化された包含**として持つ。2 つは役割が違うので両方置く)
- **`DYNAMIC_MEMBER_INVENTORY` には非空を要求しない** (0 件は望ましい状態。
  走査器の検出力は**合成負例**で固定し、実コードの件数に依存させない)
- 例外クラスのソースファイルが解決できること (解決できなければ**無言で外さず**赤)

### gate の docblock に書くこと (保証しないものの正本)

1. **走査域**: Pest arch は `App\` / `Database\Factories\` / `Database\Seeders\` の 3 根だけを見る。
   `Tests\` は `Composer::userNamespaces()` が除外する。`.blade.php` / `resources/js/` も対象外
2. **検出できる語彙は 97 のうち一部である** (V2)。
   Pest が依存側の層を作れるのは
   `PhpCoreExpressions::getClass($v) !== null`
   または `function_exists($v) && (new ReflectionFunction($v))->getName() === $v`
   を満たす語彙だけで、**それ以外は層が空 = 規則が落ちようがない**。
   **活性判定は常に実行環境依存である** (polyfill / ユーザー定義関数 / 拡張・パッケージの有無)。
   基準コミットの実行環境での実測は「コア構文 5 + 実在関数 27 + 不活性 65」で、
   不活性のうち `mysql_*` 14 + `ereg` + `eregi` + `create_function` は
   **PHP 8 の標準環境に組み込みが存在しない**もの、`xdebug_*` 40 + `ray` `ds` `ddd` `trap` は
   **拡張・パッケージの有無で変わる**もの。**件数は pin しない**
3. **綴りの大小を変えた呼び出しは Pest arch が検出しない** (`SHA1(` は見えない)。
   層の名前 (`RULES` の綴り) と AST の綴りを `===` で突き合わせるため
4. **S2 と S4 で大小の扱いが逆である**。S2 は区別する (Pest の粒度に揃えるため) /
   S4 は無視する (迂回防止のため)。**理由が逆なので混同しないこと**
5. **S4 が保証しない構文**: `ReflectionMethod` / `ReflectionFunction` 経由の反射呼び出し
   (既存テストが `tests/` 全数で 41 件 / 25 ファイル正当に使用) /
   可変関数 / 文字列連結で組み立てた名前 / それ以外の未知の間接実行経路。
   **この構文について検出力を主張しない**
6. **静的プロパティ参照 (`self::$x`) は動的メンバとして数えない** (意図的な対象外。理由は施策 3)
7. **`DYNAMIC_MEMBER_INVENTORY` は安全の証明ではない**。受容した未解決箇所の在庫であり、
   **同一ファイル内での置換は検出しない**
8. **Pest の解析単位はクラスではなくファイルである** (V6)。
   2 つ目以降のクラスは独立したオブジェクトにならず、その中の名前参照は
   **最初のオブジェクトの依存として帰属する**。したがって
   **`->ignoring(X::class)` は実質「X を含むファイル全体」を免除する**。
   例外クラスと同じファイルに別のクラスを足すと、その規則の対象シンボルについては
   一緒に免除される (規則の対象シンボルは 1 個なので波及は 1 語彙に閉じるが、
   **「クラス単位で免除している」とは書けない**)
9. **既存の `ForbiddenStatementTokenInvariantTest` / SSRF 検査 / LLM 防御の代替ではない**。
   対象語彙も走査域も方式も別である
10. **`tests/` 配下で禁止する名前**: `arch` / `ignoring` / `toBeUsed` / `preset` /
    callable 4 関数 / `fromCallable` と**セグメントが完全一致する**メソッド名・関数名・
    `use` の取り込みを作らないこと

### PHPStan 適合チェック

- [ ] `phpstan.neon` は**触らない** (既存方針。`adoption-debt.tsv` 凍結済みパスでもある)
- [ ] 実装時に `vendor/bin/phpstan analyse --level=10` へ新設パスを
      **コマンドライン引数で**渡して 1 度確認する (設定ファイルは変更しない)
- [ ] `mixed` へ widen しない / 配列 shape を PHPDoc で固定

### テスト計画

- [ ] **バグ修正ではない**ので再現テストは不要
- [ ] 既存テストの更新: **なし** (131 本に触れない)
- [ ] 新規テスト: `ArchBaselineTest` 自身が gate 兼テスト。
      **テストファースト**: 施策 6 を先に書いて赤を確認 → 走査器 → gate の順
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (DB を使わない)
- [ ] **緑になることの実測**: `composer test` の Architecture レーンで全 7 規則が緑。
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

### 入力の与え方 (Round 1 [Warning] への対応)

- **合成入力はすべて nowdoc** で組み立てて走査器の**純関数**へ渡す。実コードとして書かない。
  理由は 2 つ —
  1. 本ファイル自身が S4 の母集団 (`tests/` 全数) に入るため、
     実コードで `\call_user_func(...)` や `->{$m}()` を書くと**S4 が即座に赤くなる**
  2. 合成入力なら「母集団が 0 件」と「違反が 0 件」を分離でき、
     実コードの件数に検出力を依存させない (共通規約 (b) の 3 番目)
- **実コードとの結合確認だけは実ファイルを使う**。使うのは 3 本だけ —
  `FakeObjectStore` (S2 の正例) / `SopTextExtractor` と `TakeThumbnailExtractor`
  (取り違えの負例。security preset の `extract` と綴りが一致する現実の分岐)
- 既存の準拠実装: `tests/Unit/Architecture/FakeWiringSourceScannerTest.php` の
  `fakeWiringScannerSource()` ヘルパ。同じ作法に揃える

### テスト一覧

| # | 対象 | 種別 | 内容 |
|---|---|---|---|
| 1 | `GlobalFunctionCallScanner` | 正 | `FakeObjectStore` **実ファイル**で `sha1` >= 1 |
| 2 | 〃 | 正 | `\sha1(` (完全修飾) を数える |
| 3 | 〃 | 負 | メソッド宣言 / interface のメソッド宣言 / `->extract(` / `::extract(` を数えない (**実ファイル** 2 本を使用) |
| 4 | 〃 | 負 | **3 形** — 接頭辞つき `mysha1` / 打ち消しつき `not_sha1` / 接尾辞つき `sha1_file` を数えない |
| 5 | 〃 | 負 | `Foo\sha1(` (修飾名) を数えない (別の関数だから) |
| 6 | 〃 | 負 | **`SHA1(` を数えない** (大小を区別する。Pest の粒度に揃える) |
| 7 | 〃 | 負 | **不正な PHP ソース**で `RuntimeException` (`TOKEN_PARSE` の裏取り) / 不在パスで例外 |
| 8 | 〃 | 正 | 0 件でも配列のキーが残る |
| 9 | `identifierSites` | 負 | docblock / 文字列内の `preset` `call_user_func` を数えない |
| 10 | `statementTokens` | 正 | 期待形が `EXPECTED_CHAIN_TOKENS` と一致 |
| 11 | 〃 | 負 | `->ignoring([Foo::class])` 直書き形が一致しない |
| 12 | 〃 | 負 | `->not->toBeUsed()` 欠落形が一致しない |
| 13 | 〃 | 負 | 開始位置が範囲外 / `;` に達せず EOF で**例外** |
| 14 | `dynamicMemberSites` | 負 | `->{$m}()` / `?->{$m}()` / `::{$m}()` / `->$m()` / `A::$m()` を拾う |
| 15 | 〃 | 正 | `self::$x` / `A::$prop` を拾わない (**`A::$m()` と隣接配置**して `(` の有無だけで分かれることを固定) |
| 16 | 〃 | 正 | `->{'literal'}()` を拾う (`->` 側は区別しない = 広く数える) |
| 17 | `functionNameSites` | 負 | `\call_user_func(` (完全修飾) を `call` で拾う |
| 18 | 〃 | 負 | `A\B\call_user_func(` (修飾名) を `call` で拾う (**名前解決しない** = 拾いすぎ) |
| 19 | 〃 | 負 | `namespace\call_user_func(` (`T_NAME_RELATIVE`) を `call` で拾う |
| 20 | 〃 | 負 | **`\CALL_USER_FUNC(`** を `call` で拾う (大小無視) |
| 21 | 〃 | 負 | `use function A\call_user_func as invoke;` を `import` で拾う |
| 22 | 〃 | 負 | **カンマ区切り** `use function A\f, B\call_user_func as g;` を `import` で拾う |
| 23 | 〃 | 負 | **group use** `use function A\{f, call_user_func as g};` / **mixed group use** `use A\{function call_user_func};` を `import` で拾う |
| 24 | 〃 | 負 | **2 本目の `arch()`** — `\arch(...)` を足すと `call` が 2 件になる / `use function Pest\arch as x;` を足すと `import` が 1 件になる (**Round 1 [Critical] C1 の裏取り**) |
| 25 | 〃 | 正 | **呼び出し側の 3 形** — `mycall_user_func(` / `not_call_user_func(` / `call_user_func_x(` を拾わない |
| 25b | 〃 | 正 | **取り込み側の 3 形** — `use function A\mycall_user_func;` / `A\not_call_user_func` / `A\call_user_func_x` を `import` として拾わない (**セグメントの完全一致であって部分文字列一致ではないことの裏取り**。共通規約 (e)) |
| 26 | 〃 | 正 | `$obj->call_user_func(` / `Foo::call_user_func(` / `function call_user_func(` を拾わない (メンバ名・宣言) |
| 26b | 〃 | 正 | **`ignoring` / `toBeUsed` を `functionNameSites()` は 0 件で返す** (`->toBeUsed()` の形。**Round 2 [Critical] の裏取り** — S4 がこの 2 つを識別子検査へ回す理由を固定する) |
| 26c | `identifierSites` | 正 | 同じソースで `ignoring` / `toBeUsed` が**各 1 件**取れる (26b と対で、走査の使い分けを固定する) |
| 26d | `functionNameSites` | 正 | **`call.index` から `statementTokens()` で正しい文を切り出せる**。**同じ行に複数の名前呼び出しがある**ソースで一意に切り出せること (行番号では実装できないことの裏取り) |
| 27 | 〃 | — | **削除** (`unresolved` を戻り値から撤去したため。構文不正は No.7 と同じ `RuntimeException` へ統一) |
| 28 | `VendorArchPresetReader` | 正 | 3 preset が**非空**で**代表語彙** (`sha1` / `dump` / `env`) を含み、和集合が `ArchBaseline::allSymbols()` と一致 |
| 29 | 〃 | 負 | 配列なし / 配列 2 個 / 可変要素 / キー付き要素 / spread / ネスト配列 / 二重引用符文字列 / 未知エスケープで**例外** |
| 30 | 〃 | 正 | `\\` と `\'` のエスケープを正しく解く |
| 31 | S3 第 7 条の述語 (gate 内) | 負 | `['A\Foo']` × `['A\Foo', 'A\FooDouble']` → 衝突 1 件 |
| 32 | 〃 | 負 | `['A\Foo']` × `['A\Foo', 'A\Foo\Baz']` → 衝突 1 件 (`str_starts_with` は区切りを見ない) |
| 33 | 〃 | 正 | `['A\Foo']` × `['A\Foo', 'A\Bar']` → 衝突 0 件 |
| 34 | S3 第 6/7 条の母集団 (gate 内) | 正 | Pest のオブジェクト名集合が 500 件以上 + 代表クラス 4 件を含む (空振り検査) |
| 35 | S3 第 6 条の包含述語 (gate 内) | 正 | 例外 4 件がすべて Pest のオブジェクト名集合に含まれる |
| 36 | 〃 | 負 | 例外集合に `A\NotInPest` を混ぜた合成入力で**落ちる** (「任意の例外クラスが集合に無ければ赤」を固定する。代表クラス pin では将来の 5 件目を守れない) |
| 37 | オブジェクト集合の正規化 (gate 内) | 正 | `['A\Foo', 'A\Foo', 'A\Bar']` → `['A\Bar', 'A\Foo']` (**重複が 1 件として扱われ、床値が重複で水増しされない**ことを固定する) |

### PHPStan 適合チェック

- [ ] ヘルパの戻り値型を明示
- [ ] `mixed` を使わない

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

**AGENTS.md `<!-- VERIFICATION_COMMANDS -->` の全 10 コマンドを受入条件とする**
(変更が PHP だけであることは省略の理由にならない)。

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

加えて本設計固有の 1 本 (**設定ファイルは変更しない**。コマンドライン引数で渡すだけ):

```
vendor/bin/phpstan analyse --level=10 \
  tests/Support/Architecture \
  tests/Architecture/ArchBaselineTest.php \
  tests/Unit/Architecture/ArchBaselineScannerTest.php
```

---

## 本ラウンドで確認してほしいこと

残件が無ければ **APPROVED** を、あれば具体的な修正案を添えて指摘してほしい。
