# 対応マトリクス: design-review Round 1（CHANGES_REQUESTED; Critical2 + Warning）

## S1 [Warning] テスト観点が desktop() のみ
- 判断: 対応する
- 対応: `AppLayout.test.ts` に **mobile シェル**でも `/settings` が nav 項目として出ないことを 1 ケース追加
  （mobile drawer の nav に `nav-item-/settings` が無い）。desktop/mobile 両シェルで負例を固定。

## S2 [Warning] デフォルト幅ポリシー不在
- 判断: 対応する（必須 prop は維持し、標準値の運用規約を明文化）
- 対応: `PageContent` は既定値を持たない（必須 prop 維持）。**「認証ページ本文の標準幅は 2xl、例外
  （3xl/4xl/7xl 等）は理由付き」**という運用規約を、Architecture テスト（page-content-usage）の
  先頭コメント + PageContent の docblock に明文化する。

## S2 [Warning] data-testid 固定付与の DOM 契約ロックイン
- 判断: 対応する（testId 任意化）
- 対応: `testId?: string`（既定 `"page-content"`）prop にする。表示テストは class assertion（`mx-auto` +
  `max-w-*`）を主とし、testId は補助（既定値で代表テストは動く）。

## S3 [Critical] Architecture テストの `<PageContent` 正規表現が不安定（別名/改行/属性順）
- 判断: 対応する
- 対応: 検査を「固定文字列 `<PageContent`」依存から**import 識別子ベース**に変える:
  1) `import <IDENT> from "@/components/templates/PageContent.svelte"` の **識別子 `<IDENT>` を capture**。
  2) テンプレート側は `<IDENT`（開始タグ）の出現を検査（別名 import・属性順・改行に頑健）。
  3) 失敗メッセージを **「PageContent import 不足 / import はあるが未使用 / allowlist 対象外」** に分離表示。
  既存 `atomic-import-graph.test.ts` の fs 走査 + import 正規表現方式に合わせ、走査前に HTML/JS コメントを
  除去（Codex R4 Suggestion）。

## S3 [Critical] soft check（AppLayout 直下〜PageContent 間 max-w 禁止）の曖昧さ
- 判断: 対応する（soft check をスコープから外す）
- 対応: soft check（top-level max-w 直書き残存検出）は Svelte テンプレート上の「直下」判定が曖昧で
  誤検知/見逃しの両リスクがあるため**今回スコープから外し、コードレビュー観点へ格下げ**する。
  Architecture テストの強制は「AppLayout 使用ページ（allowlist 除く）は PageContent を import かつ使用」
  までに限定する。内側 max-w 除去は移行時の実装 + 代表表示テスト + レビューで担保。

## S3 [Warning] allowlist 追加手続き未定義
- 判断: 対応する
- 対応: `page-content-usage.test.ts` 先頭に「**allowlist 追加時は理由コメント必須**（無理由追加禁止）」の
  規約コメントを置く。現行 allowlist は `Capture/Show`（2 カラム grid レコーダー、ワイド意図）1 件。

## S3 [Warning] 「既存実効幅維持」と 7xl 上限導入の文言不整合
- 判断: 対応する（文言統一）
- 対応: 全幅ページへの `7xl` 付与は「**実効上ほぼ全幅を維持（超広幅ビューポートのみキャップ + 中央寄せ）**」
  と設計文言を統一。通常ビューポート（〜1280 等）では見た目不変であることを明記。

## S3 [Suggestion] Manuals/Edit 二段 max-w のテスト名
- 判断: 対応する
- 対応: Manuals/Edit（外側 4xl + 内側区画）の表示テスト名に「二段 max-w 構造」を残す。

## S1 [Suggestion] 群 / S2 [Suggestion] MAX_W union
- 妥当。変更なし。
