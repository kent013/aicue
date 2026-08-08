# 対応マトリクス: impl-review Round 3

全体判定: `CHANGES_REQUESTED` (Critical 0 / Warning 1 / Suggestion 1)

## [Warning] D5 の実行時代入が vendor の真偽値文脈と一致していない (`= 1` / `= 'yes'` がすり抜ける)
- 判断: **対応する**
- 根拠: 指摘が正しい。既定値側だけ `(bool)` 評価へ揃え、代入側は `true` リテラルのままだった。
  `$this->afterCommit = 1;` は**静的な truthy リテラル**であり「動的値の穴」では言い訳にならない。
  クラス docblock の「どの層からも迂回できない」とも整合していなかった。
- 対応内容: `detectAfterCommitAssignments()` の判定を
  「`->afterCommit` `=` **単一リテラル** `;`」の並びに限定したうえで、リテラルを真偽値評価する
  `truthyLiteral()` を追加した (`true` / 非ゼロ数値 / `'0'` 以外の非空文字列 = truthy、
  `false` / `null` / `0` / `''` / `'0'` = falsy、変数・式・定数 = 評価不能 → 検出しない)。
  負のコントロール 1 本 (`= 1` / `= 'yes'` / `= 2.5` の 3 件検出) と
  偽陰性コントロール 1 本 (`false` / `null` / `0` / `''` / `'0'` / `$flag` を 0 件) を追加。
  **評価不能な式を検出しないこと**は docblock と `docs/architecture.md` の
  「保証しないもの」へ明記した (完全な定数式評価は行わない)。

## [Suggestion] BillingCustomerSynchronizerTest の docblock が保証を誇張している
- 判断: **対応する**
- 根拠: 指摘が正しい。`BillingSyncDispatchInvariantTest` が閉じているのは
  「`SyncBillingCustomerDetails::dispatch` を書けるのは `BillingCustomerSynchronizer` だけ」で
  あって、`dispatchFor()` の**呼び出し元**が 2 本であることではない。第 3 の呼び出し元は
  機械的に検出されない。
- 対応内容: docblock を「**現時点で確認済みの 2 本**」へ改め、
  「第 3 の呼び出し元が増えても機械的には検出されない (設計 §保証しないもの 11 と同じ性質)」を
  明記した。**新たな Architecture inventory は作らない** — 設計は
  「dispatch が業務 tx の内側にあることの静的完全性は保証しない」を明示的に受容しており、
  ここだけ別建ての目録を作ると本 PR のスコープ (思考原則 2) を超えるため。
  必要になったら独立課題として設計する。
