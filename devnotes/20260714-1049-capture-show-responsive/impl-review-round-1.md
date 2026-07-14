**レビュー結果（ファイル別）**

- `resources/js/pages/Capture/Show.svelte`
  - 判定: 設計施策1に一致。
  - `grid` に `grid-cols-1` を追加し、`lg:grid-cols-2` を維持しているため、モバイル/タブレットで 1 列・デスクトップで 2 列の意図を満たします。
  - 左右 pane に `min-w-0` を付与しており、Grid/Flex 子要素の最小幅クランプ不足による横 overflow の根因対策として妥当です。
  - `data-testid`（`capture-grid` / `capture-left-pane` / `capture-right-pane`）も施策1の「安定 testid」に一致。
  - 指摘: なし。

- `resources/js/components/features/capture/CutNavigator.svelte`
  - 判定: 設計施策2に一致。
  - shooting_point 行が `<p class="flex min-w-0 ...">` + `<span class="min-w-0 flex-1 truncate">...` + `MapPin shrink-0` へ変更され、ellipsis 発火条件を構造的に満たしています。
  - scene 行は既存 `truncate` のまま維持され、設計書の「scene は施策1で親幅確定後に復活、構造変更不要」に整合。
  - 指摘: なし。

- `tests/js/pages/CaptureShow.test.ts`
  - 判定: 設計施策3に一致。
  - jsdom 制約上「実 overflow」ではなく「構造回帰」を固定するテストとして適切（`grid-cols-1` と両 pane `min-w-0` を検証）。
  - テスト強度は過不足なく、実装詳細に過度依存せず意図に必要なクラスのみを確認できています。
  - 指摘: なし。

- `tests/js/components/features/capture/CutNavigator.test.ts`
  - 判定: 設計施策4に一致。
  - shooting_point の `<span>` 必須化、`min-w-0/flex-1/truncate` 同時要求、親 `<p>` の `flex min-w-0`、`svg.shrink-0` まで固定しており、付与先ずれの回帰検出力が高いです。
  - scene 行 `truncate` の維持確認も設計意図どおり。
  - 指摘: なし。

**観点別総評**

- 設計一致性: 施策1〜4すべて一致（施策1→2同一PR要件も満たす）。
- 正確性: overflow 是正ロジックは妥当。デスクトップ退行も `lg:grid-cols-2` 維持で回避。
- テスト網羅性: 「構造回帰で代替」という制約に沿っており、脆すぎ/緩すぎの懸念は小さい。
- DESIGN.md/Atomic Design: token/hex 変更なし、層違反なし、Lucide 継続で問題なし。
- 禁止事項/セキュリティ: 本差分範囲では抵触なし。

**指摘分類**

- [Critical] なし
- [Warning] なし
- [Suggestion] なし

**全体判定**

- **APPROVED**