# 対応マトリクス: impl-review Round 1

Codex (gpt-5.3-codex / high) の全体判定は **APPROVED**。Critical / Warning はゼロ。
Suggestion 2 件の扱いを以下に記録する。

## [Suggestion] `CI=true` バイパス不在は満たしているが、層 2 静的検査に「`CI` 分岐禁止」を追加するとさらに堅い

- 判断: **対応する**
- 根拠: 概念設計は「**`CI=true` によるバイパス分岐を作らない**」を非交渉の方針として明記しているが、
  実装時点ではそれを機械で固定する手段が無く、「今は無い」ことしか保証できていなかった。
  AGENTS.md 禁止事項 1 (不変条件は Architecture テストへの登録まで含めて実装済み) に照らすと、
  この不変条件だけ登録漏れになっていた。追加コストは純関数 1 本 + 負のコントロール 1 本で小さい。
- 対応内容:
  - `tests/Architecture/GlobalTestLockInventoryTest.php` に
    `globalTestLockCiBypassViolations()` (純関数) と定数
    `GLOBAL_TEST_LOCK_NO_CI_BYPASS_SCRIPTS` を追加。
    検査対象は lane 3 本 + ラッパ + **ライブラリ本体** (`scripts/global-test-lock.sh`)。
    バイパスを入れるとしたらライブラリが最有力なので、`GLOBAL_TEST_LOCK_GUARDED_SCRIPTS`
    (trap / exec fd を正当に持つため除外している) とは別のリストにした。
  - 検査は `globalTestLockCodeLines()` の出力 (行頭コメントを除去した実行行) に対して行う。
    実装が「CI を特別扱いしない」方針を**コメントで説明できる**ようにするため。
  - 負のコントロール 2 方向 (`if [ "${CI:-}" = "true" ]` を検出する / コメント内の
    `${CI}` は違反にしない) を追加。
  - 結果: 層 2 は 12 tests → **14 tests / 49 assertions** に増えて全 green。

## [Suggestion] `C19` は `/dev/tcp` 非対応 bash での skip 条件を将来明示しておくと移植性が上がる

- 判断: **見送る**
- 根拠: 「`/dev/tcp` が使えないシェルでは検査を skip して続行する (guard であって保証ではない)」は
  実装対象である `scripts/run-browser-test.sh` のヘッダコメントに既に明記されており、
  設計 (概念設計 §bug-hunt 併走時の残余リスク) とも一致している。
  層 1 の C19 が skip するのは「`python3` が無い / `:8010..8018` を bind できない」の
  2 条件で、いずれも実行時に `[SKIP]` 行として理由つきで出力される
  (スイートは skip 数を必ず集計に出す = 偽グリーンにならない)。
  `/dev/tcp` 無効ビルドは aicue の一次開発環境 (devcontainer) にも CI (ubuntu-latest) にも
  存在せず、今それ用の分岐を足すのは「今必要なものだけ作る」に反する。
  移植性の要求が実際に生じたとき (素の macOS / 制限ビルド bash の採用時) に足す。
- 対応内容: コード変更なし。本マトリクスに判断根拠を残す。
