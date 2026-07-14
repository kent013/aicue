**全体判定: CHANGES_REQUESTED**

以下、施策ごとの判定です。結論として方向性は正しく、使命・既存制約にも整合していますが、**テスト設計に1点 Critical**、実装記述に数点 Warning があります。

**施策1: `Show.svelte` の `grid-cols-1` + `section min-w-0`**
- 判定: **APPROVE**
- [Suggestion] `grid-cols-1` と `lg:grid-cols-2` の併用根拠（`minmax(0,1fr)`）は妥当で、狭幅 overflow 対策として適切です。
- [Suggestion] 右カラム内の子（詳細カード群）で将来的に横溢れ要素が増える可能性があるため、将来の保守メモとして「横幅固定要素を置く場合は `min-w-0` を優先」の注記を devnotes に残すと回帰防止に有効です。

**施策2: `CutNavigator.svelte` の shooting_point 構造変更**
- 判定: **APPROVE**
- [Suggestion] `p > span` 構造＋`span.min-w-0.flex-1.truncate` は、flex 文脈での省略表示として正攻法です。
- [Warning] `scene` は `truncate` 維持のみで十分という判断は正しいですが、親コンテナ依存（施策1）で成立するため、PR本文に「単独適用不可（施策1とセット）」を明示してください。  
  - 修正案: 実装計画に「施策1→施策2の順で適用、同一PRでマージ必須」を追記。

**施策3: `CaptureShow.test.ts` 回帰テスト**
- 判定: **REQUEST_CHANGES**
- [Critical] 提示テストの `leftSection = screen.getByTestId("cut-navigator").closest("section")` は成立しません。`data-testid="cut-navigator"` は `ul` で、`section` はその祖先ですが、将来 DOM が 1段ラップされるだけで壊れやすく、かつ `:scope > section` 依存も brittle です。  
  - 修正案: レイアウトの検証対象に**安定した testid**を付与してください。  
    - `Show.svelte` に `data-testid="capture-grid"`, `data-testid="capture-left-pane"`, `data-testid="capture-right-pane"` を追加  
    - テストはそれらを直接取得し、class を検証  
    - これで構造変更耐性が上がり、失敗理由も明瞭になります。
- [Warning] 「変更前 fail を確認」は方針として良いですが、手順をテスト実行ログで保証しないとレビューで追跡できません。  
  - 修正案: devnotes に red→green のコマンド結果要約を残す（最低 `pnpm test -- CaptureShow`）。

**施策4: `CutNavigator.test.ts` 新規**
- 判定: **REQUEST_CHANGES**
- [Critical] `screen.getByText(makeCut().scene)` の書き方は、`makeCut()` を都度生成しており、将来 factory を変更したときにテスト意図が不鮮明になります。  
  - 修正案: `const cut = makeCut();` を先に定義し、`render`・`getByText` の双方で同じ `cut` を参照。
- [Warning] `className` の部分一致検証のみだと、重要クラスの付与先がずれても通る余地があります。  
  - 修正案: shooting_point 行は  
    - `const row = sp.closest("p")` が `min-w-0` を持つ  
    - `sp` が `SPAN` かつ `truncate` を持つ  
    の2段階で検証し、役割分担を固定化。
- [Suggestion] `MapPin` の `shrink-0` 維持も1アサーション入れると、意図（アイコン非圧縮）が仕様として残ります。

**観点別サマリ**
- 正確性/既存整合性: 概ね良好
- PHPStan L10: PHP変更なしで問題なし
- テスト網羅性: 方向は良いが **安定性改善が必要**
- DTO/JsonResource/Inertia: 抵触なし
- 副作用/波及: 低リスク
- セキュリティ不変条件: 影響なし
- DESIGN.md / Atomic Design: 逸脱なし（新規 SVG なし、層違反なし）

必要なら、この指摘を反映した**レビュー通過しやすいテストコード案（testid付き）**をそのまま貼れる形で作成します。