# 対応マトリクス: impl-review Round 2

## [Critical] audit-gate.ts: 入れ子の error-bearing entry が 0 件相当へ落ちる

- 判断: **反論する (ただしテストで不変条件を固定する)**
- 根拠: 指摘の前提「normalizer が欠落フィールドを空値へ落とすなら、未受容 high として
  検出されず偽グリーンになる」が **事実と異なる**ことを実測で確認した。
  3 ケースすべてで `evaluate()` は **exitCode=1** を返す (gate は fail する):

  | 入力 | 正規化結果 | gate |
  |---|---|---|
  | `{"advisories":{"vendor/pkg":[{"error":"unavailable"}]}}` | `{id:"", package:"vendor/pkg", severity:"high"}` | exitCode=1 |
  | `{"advisories":[{"error":"boom"}]}` | `{id:"", package:"", severity:"high"}` | exitCode=1 |
  | `{"dependencies":[{"name":"x","vulns":[{"error":"boom"}]}]}` | `{id:"", package:"x", severity:"high"}` | exitCode=1 |

  理由は 2 段の既存 fail-safe:
  1. `normalizeNpmSeverity` / `normalizeComposerSeverity` は **unknown → high** を返す
     (pip は severity を持たないので常に high)。壊れた entry は必ず high advisory になる。
  2. id 欠損 advisory は `AcceptedAdvisorySchema.id = z.string().min(1)` により
     **accept-risk で黙らせることが構造的に不可能**。逃げ道が無い。

  つまり「要素が壊れている」は 0 件ではなく **未受容 high** として現れ、fail-closed が成立している。
- 対応内容: shape 層に advisory 要素の必須フィールド検証は**追加しない**。
  判定 (severity 決定・受容可否) は判定層の責務であり、shape 層へ持ち込むと
  判定ロジックの二重管理になる (設計の責務境界 = 「shell は非空判定 / TS は JSON 妥当性と schema」
  を壊す)。代わりに、この fail-closed 性が **load-bearing なのに未テストだった**点を認め、
  回帰テスト 2 本を追加した:
  - 3 ecosystem すべてで「入れ子 error → 1 件の high (id 空) → exitCode=1」を固定
  - id 欠損 advisory が accept-risk schema を通らないことを固定
  これにより、将来 unknown-severity の既定を low へ変える等の退行が即座に赤くなる。

## [Suggestion] global-test-lock.sh の race を TODO 化すべき

- 判断: **対応する (devnotes に既知事項として記録 + 報告で申し送る)**
- 根拠: 「最終報告だけでは埋もれる」は妥当な懸念。
  ただし `docs/TODO.md` への登録は `app-todo-add` スキルの契約上
  概念設計・詳細設計の存在が前提であり、設計なしの直接追記は規約違反になる。
- 対応内容: `devnotes/20260805-1329-todo-T104/known-issue-global-test-lock-race.md` に
  再現条件・影響範囲・修正案を記録してコミットし、最終報告で TODO 化を明示的に推奨する。
