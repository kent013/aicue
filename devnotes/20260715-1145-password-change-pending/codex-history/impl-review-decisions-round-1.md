# 対応マトリクス: impl-review Round 1

Codex 判定: **CHANGES_REQUESTED**（差し戻し理由は 1 点）

## [Warning] `clearErrors()` 無引数の設計前提が将来拡張に弱い（Settings/Index.svelte）
- 判断: **対応する**
- 根拠: passwordForm は profileForm と別インスタンスのため、無引数 `clearErrors()` でも他フォームの errors は消せない（Codex の「他フォーム由来まで消す」懸念は厳密には非該当）。ただし「本フォームが所有するフィールドに限定してクリアする」という**意図の明示**は、将来フィールドが増えたときの過剰クリア回避と自己文書化に資する。trivial かつ無害で APPROVED への最短路。
- 対応内容:
  - `passwordForm.clearErrors("current_password", "password")` にスコープを限定（フォームが実際に所有する 2 フィールドのみ。存在しない `password_confirmation` は幻フィールドなので加えない）。
  - コメントに「本フォームが所有するフィールドに限定」と明記。
  - 回帰テスト（test 1）に `expect(clearMock).toHaveBeenCalledWith("current_password", "password")` を追加し、限定クリアの意図を固定。

## [Warning] reactiveUseForm が実 useForm と形状乖離（double 専用契約の明示）
- 判断: **見送り（現状のコメントで足りる）**
- 根拠: 既存 helper は元々「反応的 double」と冒頭 docstring で用途を明示済み。今回の additive 拡張にも「既存 consumer は post のみ参照で後方互換」とコメント済み。Codex 自身も「現時点の consumer には影響なし」と認めており、blocking ではない Warning。型名変更等の追加抽象化はオーバーエンジニアリング（禁止事項/思考原則）に触れるため入れない。

## [Suggestion] 各種
- 判断: 対応不要（肯定的評価。施策 1〜4 整合・禁止事項 8 整合・テスト網羅・DESIGN/Atomic 準拠すべて問題なしと確認された）。
