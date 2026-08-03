# 対応マトリクス: design-review Round 2

Codex (gpt-5.3-codex / high) の再判定は 施策 3 = APPROVE、施策 5 = REQUEST_CHANGES、
全体 = CHANGES_REQUESTED。Critical 0 件 / Warning 1 件。
Round 1 で見送った 2 件の Suggestion は「見送りは妥当」と追認された。

## [Warning] (施策 5) 属性値の判定不足 — `novalidate={false}` / `novalidate={cond}` が合格してしまう

- 判断: **対応する**
- 根拠: 指摘は正しい。`name === "novalidate"` の存在だけを見ると、実行時に属性が消えうる
  動的束縛を合格にしてしまい、「native validation に依存しない」という不変条件に偽陰性が残る。
- 裏取り (svelte 5.56.3 で実測):
  | 記述 | `Attribute.value` |
  |---|---|
  | `<form novalidate>` | `true` (boolean shorthand) |
  | `<form novalidate={false}>` | 式ノード (object) |
  | `<form novalidate={cond}>` | 式ノード (object) |
  | `<form novalidate="novalidate">` | `[Text]` |
  → `value === true` の一致で「静的に必ず付く」ものだけを合格にできる。
- 対応内容:
  1. 判定条件に `a.value === true` を追加した。
  2. 検査を `formViolationsInSource(source, label)` として **source ベースに分離**し、
     ファイル I/O から切り離した (Codex 提案)。
  3. **検出器の自己テスト**を `it.each` で追加した:
     `<form novalidate>` = 合格 / `<form>` = 違反 / `<form novalidate={false}>` = 違反 /
     `<form novalidate={cond}>` = 違反 / `<script>` 内文字列の `"<form>"` を誤検出しない。
  4. 実測した属性値の形を詳細設計に記載した (次の読者が判定条件の根拠を追えるように)。

## [見送り追認] `bg-*` 禁止ルール / Browser E2E / 先行実装の `$effect` 維持

- 判断: **維持** (Codex が「妥当」と追認)
- 対応内容: 変更なし。
