Round 2 の指摘 3 件 (Warning) と Suggestion 1 件に対応しました。対応マトリクスと、修正した施策 4 の全文を送ります。再レビューをお願いします。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

Codex 全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 3 / Suggestion 1)。
施策 1 / 2 / 3 / 5 / 6 は APPROVE、施策 4 のみ REQUEST_CHANGES。
Round 1 の Critical への反論は **成立と認められ、修正案は撤回された**
(「`newInstanceWithoutConstructor()` に寄せるべき」は撤回。通常のコンストラクタを採る)。

## [Warning] NI-3 の pin の記述が本文と規則表で一致していない (件数だけでは入れ替わりが素通りする)

- 判断: 対応する
- 根拠: 指摘のとおり。件数 pin ではモデル A が消えてモデル B が増えた入れ替わりが同数で通る。
  複数モデルが同じ表を指す形もあるので、モデルの一覧と表の一覧は別の不変条件である。
- 対応内容: NI-3 を「**走査した具象モデルの FQCN 一覧**と**そこから得た表名の一覧**を
  完全一致で pin する」へ直し、件数は一覧から導いて独立した定数を増やさないと明記した。
  本文 (Critical への回答ブロック) の記述も同じ言葉へ揃えた。

## [Warning] NI-7 のモデル由来除外を件数だけで pin するのは不十分

- 判断: 対応する
- 根拠: 指摘のとおり。「除外列が 1 本母集団へ戻り、別の列が誤って除外へ入る」置換が
  合計件数を変えずに通ってしまい、NI-7 の説明 (除外が無音で広がらない) より弱い。
- 対応内容: NI-7 を **3 つの一覧すべての完全一致** へ広げた —
  モデル由来 / モデルを持たない表由来 / 両者を統合したもの (いずれも `表名.列名`)。

## [Warning] Schema shape 欠落時に「列名を出す」は `name` 自体が欠けたときに成立しない

- 判断: 対応する
- 根拠: 指摘のとおり自己矛盾していた。fail-closed のときこそ診断が読めなければ意味が無い。
- 対応内容: 失敗メッセージを「表名 / `getColumns()` の中での添字 / 欠けているキーの一覧 /
  実際にあるキーの一覧」の 4 点にし、`name` があるときだけ列名を添える形へ直した。
  例文も設計に載せた。NC-8 も「`name` そのものが欠けた要素」で確認する形へ直した。

## [Suggestion] `new $fqcn()` の前提を負のコントロール / pin でも固定する

- 判断: 対応する (必須ではないが安く、守れるものが大きい)
- 根拠: 「各系統 1 件以上」では、`casts()` の畳み込み機序が変わって cast が**一斉に**空になる
  形を早く検出できない。読めていること自体を直接固定するほうが速い。
- 対応内容: NI-3 に**代表の cast 2 本を値ごと pin** する要求を足した
  (`AnalysisJob` の `step` → `AnalysisStep` / `RenderJob` の `step` → `RenderStep`)。
  保証しない範囲の記述もこれに合わせて直した。


---

## 修正後の施策 4 (全文)

## 施策 4: 検査 (`NullInitialStateColumnClassificationTest`)

置き場所は **Feature レーン** (`tests/Feature/InitialState/`)。実スキーマを引くため。
先例は `tests/Feature/Retention/RetentionTableClassificationTest.php` (同じ理由で Feature)。

### 母集団の作り方 (実スキーマ起点 — i5)

読み口は `DB::connection()->getSchemaBuilder()` (ファサードではなく具体の `Builder` を取る =
戻り値の shape 宣言がそのまま効き、型を緩めずに済む。`RetentionTableClassificationTest` と同じ)。

1. `getTables($builder->getCurrentSchemaName())` で現在のスキーマの base table 名を取る
2. 各表について `getColumns($schema.'.'.$table)` を引き、次をすべて満たす列を残す
   - `nullable === true`
   - `default === null`
   - `generation === null` (生成列を除く)
   - `auto_increment === false` (identity / serial を除く)
3. 残った列から母集団を 2 系統で作る
   - **(a) 時刻型**: `type_name` が `timestamp` / `timestamptz` / `date` のいずれか。
     ただし後述の「作成・更新時刻の除外」に該当する列は外す
   - **(b) 列挙 cast**: `app/Models` 配下の具象 Eloquent クラスが `getCasts()` で
     **`enum_exists()` かつ `BackedEnum` 実装**へ cast すると宣言している列
4. (a) ∪ (b) を `表名.列名` へ正規化し sort して母集団とする

**モデルと表の対応**: `app/Models` 配下の `*.php` から FQCN を組み、`ReflectionClass` で
`Illuminate\Database\Eloquent\Model` の**具象**サブクラスだけに絞り、
**通常のコンストラクタで** (`new $fqcn()`) インスタンス化して `getTable()` / `getCasts()` を引く
(クラス名からの推測をしない)。同じ表を指すモデルが複数あるときは **cast 宣言の和集合**を取る
(母集団が広がる側 = 見落としの出ない側へ倒す)。

> **`newInstanceWithoutConstructor()` は使えない** (Codex 詳細レビュー Round 1 の Critical への
> 回答)。Eloquent は `casts()` メソッドの戻り値を
> `HasAttributes::initializeHasAttributes()` の中で `$this->casts` へ畳み込み、この初期化は
> **コンストラクタからしか呼ばれない** (`Model::__construct` → `initializeTraits()`)。
> `getCasts()` が返すのは `$this->casts` だけなので、コンストラクタを飛ばすと本リポジトリの
> 全モデル (`protected function casts(): array` で宣言する形) の cast が**空になる** =
> (b) の系統が静かに 0 件へ縮む。これは i5 (空振りを合格にしない) がもっとも避けたい壊れ方である。
> vendor 実読の位置:
> `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php`
> の `initializeHasAttributes()` (`array_merge($this->casts, $this->casts())`) と
> `getCasts()` (`$this->casts` を返すだけ)。
>
> 代わりに**壊れ方を沈黙させない**形で受ける:
> - インスタンス化を `try`/`catch (Throwable)` で囲み、**捕まえたら FQCN と例外の型を出して
>   その場で fail** する (握り潰して母集団を縮めない)
> - **走査した具象モデルの FQCN 一覧**と**そこから得た表名の一覧**を NI-3 で
>   **完全一致** pin する (件数だけだと、1 件消えて 1 件増えた入れ替わりが素通りする。
>   複数モデルが同じ表を指す形もあるので、モデルと表は別の一覧として持つ)。
>   件数は一覧から導き、独立した定数を増やさない
> - **代表の cast を 2 本、値ごと pin** する
>   (`AnalysisJob` の `step` → `AnalysisStep`、`RenderJob` の `step` → `RenderStep`)。
>   `casts()` の畳み込み機序が変わって cast が一斉に空になる形は、件数でも一覧でも
>   捕まらないため、**読めていること自体**を直接固定する
> - この判断が Laravel 本体の実装に依存していることを検査の docblock に書き、
>   **Laravel を更新したら再確認する**と明記する (`ClaudeHooksWiringTest` と同じ作法)

**対象にする cast の形** (Codex 詳細レビュー Round 1 の Warning への対応。明文化する):

- 対象: cast の値が文字列で、`enum_exists($cast)` が真、かつ `BackedEnum` を実装するもの**だけ**
- 対象外 (**保証しない範囲に実名で書く**): `AsEnumCollection` / `AsEnumArrayObject` などの
  列挙の集まり、`:` を含む引数付きの cast 文字列 (`decimal:2` など)、`Castable` を実装する
  自前の cast クラス、値に列挙を持たない cast (`array` / `datetime` / `encrypted` 等)、
  **裏付けの値を持たない列挙 (`BackedEnum` でない `enum`)**

**Schema API の戻りの正規化** (同 Warning への対応): `getColumns()` の各要素は
`name` / `type_name` / `nullable` / `default` / `auto_increment` / `generation` を持つ想定だが、
**キーの存在を仮定しない**。正規化の純関数を 1 つ置き、必要なキーが欠けている要素は
**適合と判定せず fail する** (fail-closed。i6 の「走査で証明できない受け手は未解決として扱う」)。

失敗メッセージは **`name` 自体が欠けていても成立する形**にする (Codex Round 2 の指摘)。
出すのは 4 つ — 表名 / `getColumns()` の中での要素の位置 (添字) / 欠けているキーの一覧 /
実際にあるキーの一覧。`name` があるときだけ列名を添える。

```
users columns[3] (列名: 取得できず): 欠けているキー = name, generation / 実際のキー = type_name, nullable, default
```

**作成・更新時刻の除外** (Codex 概念レビュー Warning への対応。列名の一律一致で外さない):

- その表を持つモデルがあるとき: そのモデルが `usesTimestamps()` を満たし、かつ列名が
  そのモデルの `getCreatedAtColumn()` / `getUpdatedAtColumn()` と一致する場合だけ外す
- その表を持つモデルが無いとき (枠組み・外部パッケージ・中間表): 列名が
  `Model::CREATED_AT` / `Model::UPDATED_AT` の既定値と一致する場合だけ外す。
  **この経路で外れた件数を NI-7 が完全一致で pin する** (除外が無音で広がらない)
- `deleted_at` は**除外しない** (論理削除は初期状態の目印そのもの)

### 純関数と副作用の分離

合成入力で点灯させられるよう、判定はすべて純関数に切る (`RetentionTableClassificationTest` の流儀)。

```php
/**
 * 母集団の算出 (**純関数**)。
 *
 * @param  array<string, list<array{name: string, type_name: string, nullable: bool,
 *          default: string|null, auto_increment: bool, generation: array<string, mixed>|null}>>  $columnsByTable
 * @param  array<string, list<string>>  $enumCastColumns  表名 => BackedEnum へ cast された列名
 * @param  array<string, list<string>>  $lifecycleColumns 表名 => 除外する作成 / 更新時刻の列名
 * @return array{population: list<string>, temporal: list<string>, enumCast: list<string>,
 *          excludedLifecycle: list<string>}
 */
function nullInitialStatePopulation(
    array $columnsByTable,
    array $enumCastColumns,
    array $lifecycleColumns,
): array { /* ... */ }

/**
 * 母集団と台帳の突合 (**純関数**)。
 *
 * @param  list<string>  $population  '表名.列名'
 * @param  list<NullableStateColumnEntry>  $entries
 * @return array{unclassified: list<string>, phantom: list<string>, duplicated: list<string>}
 */
function nullInitialStateClassify(array $population, array $entries): array { /* ... */ }
```

### 規則

| # | 規則 | 落ちる条件 |
|---|---|---|
| NI-1 | 母集団と台帳が**両方向で集合一致**する | 未分類の列がある / 実在しない列が台帳に残っている |
| NI-2 | 同じ列の二重宣言が無く、根拠が 30 文字以上ある | 二重宣言 / 根拠が短い |
| NI-3 | **空振り検知**: 母集団が 0 件でない。かつ (a) 時刻型・(b) 列挙 cast の**各系統がそれぞれ 1 件以上**寄与している。かつ**走査した具象モデルの FQCN 一覧**と**そこから得た表名の一覧**が現在値ちょうど (完全一致)。かつ**代表の cast 2 本**が現在値ちょうど | 抽出が壊れて静かに縮んだとき / モデルが入れ替わったとき / cast の読み取り機序が変わったとき |
| NI-4 | 台帳の総件数が現在値ちょうど | 列が増減したのに pin を直していない |
| NI-5 | 「初期状態の目印」区分の列一覧が現在値ちょうど | 一族の列が無音で減った / 増えた |
| NI-6 | 「未確定」区分の列一覧が現在値ちょうど | 未確定が無音で増えた |
| NI-7 | 母集団から外した作成・更新時刻の列を **3 つの一覧すべてで完全一致** pin する — モデル由来 / モデルを持たない表由来 / 両者を統合したもの (いずれも `表名.列名`) | 除外がどちらの経路からでも無音で広がった / 件数が同じまま中身が入れ替わった |

**NI-1 が AG-191 の「既定値の後付けを赤にするスキーマ pin」の本体である。**
登録済みの列に DB 既定値を足すと、その列は母集団の条件 (`default === null`) から外れて
母集団から抜け、台帳側の登録が「実在しない登録」として残る → NI-1 が赤くなる。
**除外規則も CHECK 制約も足さずに、母集団の定義そのものから pin が出る。**
失敗メッセージは、この経路で赤くなったときに
「**この列に migration で DB 既定値を足していませんか。足すと新しい行は生まれた瞬間に
『済んだ』ことになります**」を名指しで出す。

### 負のコントロール (合成入力)

| # | 内容 |
|---|---|
| NC-1 | 台帳に無い列を母集団へ足すと NI-1 の「未分類」が点灯する |
| NC-2 | 実在しない列を台帳へ足すと NI-1 の「実在しない登録」が点灯する |
| NC-3 | **登録済みの列に DB 既定値が付いた状況**を合成すると母集団から抜け、NI-1 の「実在しない登録」が点灯する (AG-191 の pin の本体)。既定値の**表現ゆれ**に依存していないことを示すため、`now()` / `CURRENT_TIMESTAMP` / `'pending'` / `'pending'::character varying` / `0` / 空文字 の代表値すべてで点灯を確認する (判定は「`default` が `null` でないこと」だけであり、中身は見ない) |
| NC-4 | `usesTimestamps()` が false のモデル / 作成時刻列名を差し替えたモデルでは、`created_at` という名の列が母集団に**残る** (列名だけで外していないことの確認) |
| NC-5 | 同じ列を 2 回宣言すると NI-2 の二重宣言が点灯する |
| NC-6 | 母集団が空のとき NI-3 が落ちる (0 件を合格にしない) |
| NC-7 | BackedEnum ではない cast は (b) に入らず、BackedEnum の cast だけが入る。合成入力で確認するのは 組み込み (`array` / `datetime` / `encrypted`) / 引数付き (`decimal:2`) / 列挙の集まり (`AsEnumCollection:...`) / `Castable` 実装クラス / 裏付けの値を持たない列挙 の 5 形である |
| NC-8 | `getColumns()` の要素から必要なキーが欠けている合成入力では、適合と判定せず fail する (fail-closed)。**`name` そのものが欠けた要素**でも、表名・添字・欠けているキー・実際のキーが出ることを確認する |

### 保証しないもの (検査の docblock が正本 — i6)

- **列の意味が区分どおりかは見ない**。機械が見るのは集合の一致と根拠の長さだけであり、
  区分が正しいかは人間のレビュー対象である
- **母集団は時刻型と BackedEnum cast 列に限る**。nullable な文字列・数値・json・外部キーで
  「NULL = まだ」を表す列 (実例: `billing_checkout_sessions.funding_choice` /
  `render_jobs.output_path` / `cuts.adopted_take_id`) は母集団外であり、
  そこへ既定値を足しても**沈黙する**
- **(b) はモデルの宣言に依存する**。`app/Models` にモデルを持たない表 (枠組み・外部パッケージ・
  中間表) の状態語彙の列は見えない。ただし cast を外す変更は台帳側が「実在しない登録」になって
  赤くなるので、**片方向は閉じている**
- **(b) が拾う cast の形は 1 つだけ**である (文字列の cast で `enum_exists()` かつ
  `BackedEnum` 実装)。列挙の集まり (`AsEnumCollection` / `AsEnumArrayObject`)・
  引数付きの cast 文字列・`Castable` を実装する自前の cast・裏付けの値を持たない列挙は
  **見ない**。これらの形で状態語彙を持つ列が現れたら、母集団の設計から見直すこと
- **モデルから cast を読めるのは Laravel がコンストラクタで `casts()` を畳み込むから**である
  (`HasAttributes::initializeHasAttributes()`)。この畳み込みの実装が変われば (b) は静かに
  縮みうるため、**Laravel を更新したら本検査の前提を人手で再確認する**。
  走査したモデルの FQCN 一覧と表名の一覧を NI-3 が完全一致で pin するので入れ替わりも赤くなるが、
  「全モデルの cast が一斉に空になる」形は一覧では捕まらない
  (NI-3 の系統別 1 件以上と、代表の cast 2 本の pin が拾う)
- **最初から既定値を持って生まれた列は母集団外**である (v1 の担当。同じ事実を 2 か所で検査しない)。
  新しい列を最初から既定値付きで足す変更には沈黙する
- **既定値の中身は見ない**。`default === null` かどうかだけを見る
- **CHECK 制約・部分一意索引・排他制約は使わない** (i7 / AG-191)。列の組の整合や値域は保証しない
- **Factory / Seeder は走査域外**である (家系の未決論点 q3。本裁定の範囲外)
- 見るのは**現在のスキーマ**であり、`search_path` の健全性は前提であって保証ではない。
  S3 上の実体・ビュー・他スキーマの表は対象外である
- **アプリが実際にその列の NULL を読んで分岐していることは確かめない**。
  区分 `InitialStateMarker` は人の宣言である

---


