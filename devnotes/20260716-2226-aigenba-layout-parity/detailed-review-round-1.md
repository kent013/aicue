## 全体判定
**REQUEST_CHANGES**

概念との整合は高く、S1→S4 の順序・「混在期間なし」方針も妥当です。  
ただし、**アーキテクチャテスト設計の検出漏れリスク**と、**S4 の「BE変更なし想定」との記述整合**にクリティカルな曖昧さがあります。ここを潰せば実装着手可能です。

## 施策別レビュー

- **S1: primitive 移植 + PageContent 是正** → **APPROVE（条件付き）**
  - [Warning] `PageHeaderSection` の `description` が `truncate` 固定だと、長文説明が常時省略され UX 劣化の恐れ。aigenba parity優先なら許容だが、ページ説明運用長を確認推奨。
  - [Suggestion] `Component` 型は方針通り良い。`Component<any>` を避ける明記も適切。
  - [Suggestion] `showBreadcrumbs = breadcrumbs.length > 1` は意図明確で良い（重複見出し回避）。

- **S2: AppLayout padding 移譲** → **APPROVE**
  - [Suggestion] `EmailVerificationBanner` 外側余白維持の明文化は良い。`app-main` の test contract をどこで担保するか（AppLayout側orページ側）だけ追記するとレビュー容易。

- **S3: 24ページ移行 + Architecture テスト** → **REQUEST_CHANGES**
  - [Critical] 現行の識別子ベース regex 方式だと、`<svelte:component this={PageContent}>` や条件レンダリング変形で誤検知/検知漏れ余地あり。**少なくとも「通常タグ利用を必須化」か「検査対象構文を明示」**が必要。
  - [Critical] `PageContainer padding={false} 禁止` は良いが、**import alias時の属性検査仕様**（例 `import PC from ...`）を明記していない。識別子解決ロジック定義が必要。
  - [Warning] `AdminMenuNav` 不使用検査を同テストへ同居させるのは責務混在。将来の失敗原因切り分けが悪化しやすい（別 architecture test 推奨）。

- **S4: AdminMenuNav 撤去 + カテゴリ導線** → **REQUEST_CHANGES**
  - [Critical] 「BE変更なし想定」としつつ「不要prop除去」「必要なら最小prop追加」と記述が揺れている。**今回PRの契約（完全FEのみ or inertia props変更を許容）を固定**してください。
  - [Warning] `Projects/Show` のカテゴリリンク条件を「project update権限時」としているが、カテゴリ閲覧権限と一致しない可能性。既存ポリシーの実権限キーに合わせる明記が必要。
  - [Suggestion] 導線追加位置は「操作セクション内の一貫位置（例: primary actions末尾）」まで固定するとUI差分レビューが安定。

## 横断観点

- [Critical] **テストファーストの運用定義不足**：red確認対象が architecture test中心で、ページ単体の red→green 手順が曖昧。最低限「S1単体テスト赤化→実装→緑化」「S3 arch赤化→移行→緑化」をチェックリスト化すべき。
- [Warning] `page-content-usage` の名称と責務が拡張後に乖離（PageContainer/PageHeader/AdminMenuNavまで検査）。テスト名の再設計推奨。
- [Suggestion] 24ページ一覧（対象ファイル固定リスト）を設計書に添付すると、移行漏れ・レビュー漏れを機械/人間双方で減らせます。

## 要修正（最小）

- `S3` に **検査仕様の厳密化**を追記  
  - import識別子抽出対象（alias含む）  
  - 使用判定構文（通常タグのみ等）  
  - `padding={false}` 禁止の検査対象明文化
- `S4` に **PR境界の固定**を追記  
  - FEのみで完結するのか  
  - inertia prop追加/削除を許容するのか（許容なら「BEロジック変更なし」と明記）
- architecture test を **責務別に分割**（推奨）  
  - layout構造契約  
  - deprecated import（AdminMenuNav禁止）

この3点が反映されれば、全体は **APPROVE 相当**です。