# 対応マトリクス: impl-review Round 2

## [Critical] `queryResultVariables()` が任意の静的呼び出し (`Payload::ids()`) を受理する
- 判断: 対応する
- 根拠: Round 1 の修正 (「クラス起点のみ」) では「クラス起点 = 任意クラスの `::`」であり、
  指摘のとおり `IdDerivedFromSameMethodQuery` の副条件が素通りする。
- 対応内容: 走査時の完全な文脈 (import / namespace / modelTables) を使う **instance メソッド**へ移し、
  `staticRootAt()` (= `App\Models\*` / `DB::table(<model table>)` 起点) を通ることと
  `chainEndsWithExecutor()` (実行結果であること) の両方を要求するようにした。
  結果は候補に `queryResultVariables` として持たせ、副条件ヘルパはそれを参照する。
  モデルを持たないテーブルの走査 (`DB::table('oauth_access_tokens')`) も「同一メソッド内のクエリ」
  ではあるので、DB ファサード起点は modelTables で絞らず受理する (実在の 1 件がこれ)。

## [Critical] `provenModelVariables()` の relation 起点判定が任意 object の chain を受理する
- 判断: 対応する
- 根拠: `$dto = $input->payload()->dto();` で `$dto->user_id` が「モデル由来」に化け、
  候補が **inventory 登録すら要求されずに消える**。指摘のとおり最悪の fail-open。
- 対応内容: relation 起点の代入は**基底変数が既にモデルと証明されている場合のみ**受理する。
  証明済み変数が増えるたびに再走査する不動点ループにして
  (`$a = $model->rel()->first(); $b = $a->rel()->first();` に対応)、Unit fixture を追加した。

## [Critical] `identityAssignedFromRelationQuery()` にも同じ問題
- 判断: 対応する
- 対応内容: 同じく基底変数が `provenModelVariables` に入っていることを要求する。
  Unit fixture (`$input->payload()->value('id')` は false / `$project->manuals()->value('id')` は true) を追加。

## [Critical] 動的列名 descriptor が値引数を含まず `array_unique()` で潰れる
- 判断: 対応する
- 根拠: 通常 inventory の fingerprint 方針 (構造 fingerprint + ordinal) と揃っておらず、
  同一メソッド内の別呼び出しへ裁定理由が横滑りする。
- 対応内容: descriptor を `…#{scope}#{root}.{predicate}:{column}=>{value}#{ordinal}` にし、
  `array_unique()` をやめて ordinal を振る。inventory の key も更新。Unit fixture を追加。

## [Critical] 動的列名の array 形が inventory を素通りする
- 判断: 対応する
- 対応内容: array 形 where の解析を `arrayFormEntries()` (列 / 演算子 / 値へ分解) に切り出し、
  述語検出と動的列名走査の**両方**が同じ分解を使うようにした。
  `where([$column => $x])` / `where([[$column, '=', $x]])` を動的列名として列挙する。

## [Critical] array 形の否定演算子 (`!=` / `<>`) が検出されない
- 判断: 対応する
- 対応内容: `arrayFormEntries()` の演算子を `=` / `in` / `!=` / `<>` / `not in` へ拡張し、
  否定形は `IdentityExclusion` として分類する。Unit fixture を追加。

## [Critical] raw SQL の `id` 判定が大文字小文字を区別する
- 判断: 対応する
- 対応内容: 引用符を潰したうえで SQL 断片を小文字化してから照合する。Unit fixture (`whereRaw('ID = ?')`) を追加。

## [Warning] `literalIsInsideGuardedBlock()` が guard を条件式に紐づけていない / 否定を受理する
- 判断: 対応する
- 対応内容: `T_IF` + 条件の括弧範囲を取り、**条件式の中に guard がある**ことと
  **条件に `!` が無い**ことを要求してから、直後の `{ … }` を対応付ける。
  Unit fixture (guard が代入だけの `if (true)` / `if (! app()->isLocal())` は false) を追加。

## [Warning] 直接形 `QueuePayloadRehydration` が dispatch を検証していない
- 判断: 対応する
- 対応内容: `enqueuedBy` のメソッド本文が `{JobClass}::dispatch(` を含むことを必須にした
  (実在する 8 件すべてが満たす)。

## [Warning] `todoRef` のファイル形式が任意の既存ファイルを受理する
- 判断: 対応する
- 対応内容: ファイル形式を `devnotes/{dir}/follow-up-todo.md` に限定した
  (`AGENTS.md` 等では通らない)。`aicue:T<番号>` 形式は従来どおり `docs/TODO.md` /
  `docs/TODO-closed.md` への実在を要求する。

## [Warning] 動的列名 descriptor が値側を識別しない (inventory 側)
- 判断: 対応する (上記 descriptor 変更と同一)

## [Critical] Unit fixture 不足
- 判断: 対応する
- 対応内容: 指摘の 7 種すべてに fixture を追加した
  (`Payload::ids()` 走査元 / 任意 object chain の provenance / 動的列名の associative・nested array /
  同一 descriptor の複数呼び出し / array 形の `!=`・`<>` / `whereRaw('ID = ?')` / 否定 local guard)。
