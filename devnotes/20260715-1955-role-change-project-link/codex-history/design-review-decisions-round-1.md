# 対応マトリクス: design-review Round 1 (item3)

## [施策1 Suggestion] href 直書き → route()/Ziggy
- 判断: 反論
- 根拠: 既存の projects.create CTA は全て href 直書き (Projects/Index.svelte L41
  `href="/projects/create"`、Dashboard.svelte も同 URL 直書き)。コードベースに Ziggy 経由の
  慣行はなく、この 1 箇所だけ route helper を導入するのは一貫性を損なう。既存流儀に揃える。

## [施策1 Suggestion] コードコメント最小化
- 判断: 対応
- 対応内容: コメントを 1 行に短縮 (「詰まりの文脈から 1 ホップで作成画面へ」のみ)。

## [施策2 Warning] Inertia Link 実体依存は壊れやすい → Link を stub
- 判断: 反論
- 根拠: AdminUsers.test.ts は既に `...importOriginal` で Link 実体を使う mock 方針。ここだけ Link を
  stub すると同ファイル内で方針が二分し一貫性を損なう。href 属性は Link の mount 時に描画され、
  本テストは click せず href 属性のみ検証するため実体依存でも安定。実装時に render 成否を確認する。

## [施策2 Suggestion] 1 ケース 1 責務
- 判断: 対応済み
- 対応内容: 新規 2 テスト (リンク表示+href / 非表示) は既に責務分離。既存「案内文表示」テストは維持。

## [施策3 Critical] Policy 同値を Feature で固定するのは結合度が高い → 到達性の振る舞いテストへ
- 判断: 対応
- 対応内容: Policy 内部式の同値固定をやめ、reachability の behavioral テストへ変更。
  manageMembers を持つ Owner/Admin は GET /projects/create → 200、持たない Member は 403。
  実装詳細に過拘束せず「CTA の行き先が閲覧者集合と一致し 403 で詰まらない」不変条件のみ固定。

## [施策3 Warning] foreach の同値比較 + 具体値固定は診断性が低い → ロール別分割
- 判断: 対応
- 対応内容: reachability テストを owner/admin (200) と member (403) の 2 テストに分割。
