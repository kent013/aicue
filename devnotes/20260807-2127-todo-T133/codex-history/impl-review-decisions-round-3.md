# 対応マトリクス: impl-review Round 3

Round 3 で **APPROVED**。新規指摘は 0 件。

| ラウンド | 指摘 | 判断 | 結果 |
|---|---|---|---|
| R1 | [Critical] namespace alias 付き qualified name を解決できない | 対応する | 修正 + fixture + mutation M14 |
| R1 | [Critical] `app()->make('cache')->put(...)` を見落とす | 対応する | 修正 + fixture + mutation M15 |
| R1 | [Warning] `getFacadeRoot` が TERMINAL で後続の書き込みを追跡しない | 対応する | CHAIN へ移動 + fixture + mutation M16 |
| R2 | [Warning] DNF 型 (`(A&B)|C`) の括弧を越えられず受け手を見落とす | 対応する | 修正 + fixture 2 本 + mutation M17 |
| R3 | — | — | 指摘なし / APPROVED |

**反論・見送りにした指摘は 1 件も無い**。4 件すべてが「見落とし方向の穴」であり、
gate が自らの保証範囲として謳っている内容と実装の食い違いだったため、
値を弄るのではなく判定ロジックそのものを直した (思考原則: 仕組みが機能していない段階で値を弄らない)。

Round 3 で Codex が明示的に確認した点:

- DNF 型の両順序 (`(A&B)|C` / `C|(B&A)`) を捕捉できている
- 既存 `role=write` ファイル内での見落としが M17 で赤化確認されている
- 「直後が `(` なら呼び出し / インスタンス化」ガードにより**過剰検出の回帰**も
  正のコントロールで防がれている
- 冒頭コメントに明記した残存限界 (payload の式の型を静的に見ない / 束縛名が変数の
  コンテナ解決 / docblock 型 / group use / Mockery 系 TERMINAL) は
  **保証範囲を誇張しておらず**、今回のスコープとして合理的
