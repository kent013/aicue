## 全体判定: APPROVED

### 施策1〜5: APPROVE

Round 3までの承認内容を維持します。安定キーによる再解決、no-op境界、undo/redo・dirty整合、回帰テスト8件、負のコントロールの設計に未解決の問題はありません。

### 施策6: APPROVE

修正後の判定順は正しいです。

1. 名前付き `function` 宣言を処理して `continue`
2. 文レベルのarrow定義で `lastOpenerWasNamed = false`
3. `runSettled` 呼び出しを収集

これにより以下が成立します。

- `function runSettled(...)` の宣言行は数えない
- `runSettled(() => ...)` は `ARROW_DEFINITION` に一致せず、通常どおり数える
- 1行・複数行の文レベルarrow関数からの直接呼び出しは `fromNamedFunction: false` になる
- 現行コードでは8件を収集し、すべて `fromNamedFunction: true` になる
- 字句走査の保証外も明示され、検査能力を誇張していない

Critical / Warning はありません。提示された詳細設計で実装へ進めます。