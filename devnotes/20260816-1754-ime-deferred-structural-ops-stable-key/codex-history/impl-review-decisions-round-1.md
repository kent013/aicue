# 対応マトリクス: impl-review Round 1

- モデル: gpt-5.5 / reasoning=high / label=impl-review / sandbox=read-only
- 全体判定: **APPROVED** (Round 1)
- 指摘件数: Critical 0 / Warning 0 / Suggestion 0

## 指摘

Codex は 4 ファイル (`ScenarioEditor.svelte` / `ScenarioEditor.test.ts` /
`scenario-editor-deferred-ops-inventory.test.ts` / `negative-control.md`) すべてについて
Critical / Warning / Suggestion のいずれも「なし」と判定した。よって**対応を要する項目は無い**。

| 分類 | 件数 | 判断 | 根拠 |
|---|---|---|---|
| Critical | 0 | — | 指摘なし |
| Warning | 0 | — | 指摘なし |
| Suggestion | 0 | — | 指摘なし |

## Codex が明示的に肯定した点 (次に触る人への申し送り)

1. 3 経路とも実行時に `clientKey` で解決し直し、未解決なら `commitStructural` へ入らないため、
   誤削除・誤追加も、余分な履歴エントリ (undo 回数のずれ) も生じない。
2. `splice(-1, 1)` の窓は早期 return で閉じている。
3. PUT payload に触れていないため `clientKey` 混入の面は増えていない。
4. 新規 8 ケースは実装の写しではなく、index ずれ / 対象消滅 / 親消滅 / `splice(-1,1)` /
   drain 中断をそれぞれ独立に観測している。
5. 目録テストの「保証しないもの」は実測 (変種 d2 が**件数 pin で**発火した事実) と食い違わず、
   誇張が無い。

## 設計書からの逸脱

無し。施策 1-6 は詳細設計のとおり実装した。実装上の細部で設計書の記述より具体化した点は 2 つ:

1. **施策 4 のケース 4 の操作列**を「手順A 削除 → 手順A へ追加 → 手順B へ追加 **×2**」にした。
   設計書の案 (手順B へ 1 回) では、壊れた実装が「消えた手順の分を手順B へ足した」場合と
   区別できず (どちらも手順B が 3 件になる)、検出力がゼロになるため。実測でこの形が
   変種 (a) を検出することを確認済み (negative-control.md ケース 4)。
2. **T185 の D&D ヘルパを module scope へ持ち上げた** (設計書が「持ち上げるか describe を統合する」の
   2 案を許していたうちの前者)。既存ケースの本文は 1 行も変えていない。
