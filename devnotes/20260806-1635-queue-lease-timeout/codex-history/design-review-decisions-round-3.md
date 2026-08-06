# 対応マトリクス: design-review Round 3 (詳細設計の打ち切りラウンド)

> オーケストレータ指示により詳細設計の Codex 合議も**最大 3 ラウンド**。
> Round 3 の Critical 2 / Warning 1 はいずれも局所的な規定の直しなのでその場で反映した。残置ゼロ。

## [Critical] 施策 4: クラススコープの pop 条件に off-by-one がある

- 判断: **対応する** (指摘のとおり。メソッド終端の `}` で誤って pop しうる)
- 対応内容: **`}` の処理順序を規定で固定**する:
  ```
  「}」トークンを見たとき:
    1. スタック最上位の bodyDepth === 現在の braceDepth なら、その「}」はクラス終端 → pop
    2. その後 braceDepth--
  ```
  (push 側は「クラス宣言直後の `{` を処理して braceDepth++ した後の値」を `bodyDepth` とする、
  と明示する。push と pop で基準を揃える。)

## [Critical] 施策 4: 明示的な `public ?int $timeout = null` が規則 2 を素通りする

- 判断: **対応する** (指摘のとおり。許容形「正の int デフォルト値のみ」と
  `declaredJobTimeout()` の「明示 null は未宣言と同じ扱い」が矛盾していた)
- 対応内容: `declaredJobTimeout()` の規定を変更する:
  - `array_key_exists('timeout', $defaults) === false` → `null` (未宣言。正常)
  - 宣言されている値が **`null` / 非 int / 0 以下 → Assert で fail**
    (「`$timeout` は正の int デフォルト値を持つ宣言に限る」)
  - PHPDoc も「値が null → fail」に直す

## [Warning] 施策 3: `extractBashFunction()` の実装規定が不足 (`${...}` を関数ブロックと誤認しうる)

- 判断: **対応する**
- 対応内容: 波括弧カウントをやめ、**行単位の抽出規則**を規定する
  (対象スクリプトの整形規約 = Pint 相当の bash 整形が入っており、
  関数定義は必ず `名前() {` で始まり列頭 `}` で終わる):
  - 開始行: `/^start_shard_workers\(\)[ \t]*\{$/`
  - 終了行: 開始行より後で**最初に現れる** `/^\}$/` (列頭)
  - 開始行が 0 件または 2 件以上 → fail / 終了行が見つからない → fail
