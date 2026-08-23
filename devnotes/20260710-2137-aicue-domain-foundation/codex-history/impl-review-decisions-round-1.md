# impl-review (T001 存在オラクル修正) Round 1 対応マトリクス

Codex 出力: `../impl-review-round-1.md` (gpt-5.3-codex / high / one-shot)

## Critical

なし (Codex 明言)。

## Warning

| # | 指摘 | 判断 | 根拠 |
|---|------|------|------|
| W1 | `project.in-route-org` を業務 route group 全体に付与しているため、`{project}` を持たない route にも middleware が乗る (trait 将来変更での副作用化・過剰適用リスク) | **見送る (現状維持)** | handle() は `$project instanceof Project` の narrowing を **resolveCurrentOrganization() より前**に行うため、{project} 非保持 route では DB/org 解決を一切走らせない完全 no-op。group 一括付与は「将来 project 配下 route を追加した開発者が付け忘れる」リスク (= 本修正が塞いだ順序ハザードの再発経路) を構造的に消すための意図的選択で、ProjectRouteCurrentOrgGuardTest の deny-by-default とセットで機能する。限定付与に変えると『route 追加時に sub-group へ入れ忘れ → Architecture テストで fail → 手で付与』の手戻りが常態化し、一括付与の保守コスト (no-op 1 分岐) より高い。Codex 自身も「現時点のセキュリティ破綻ではない」と認定 |

## Suggestion

| # | 指摘 | 判断 | 根拠 |
|---|------|------|------|
| S1 | ProjectRouteCurrentOrgGuardTest に web 側 {project} route の allowlist / 対象グループ判定を追加 (将来の admin/* 等の別コンテキスト誤検知防止) | **見送る (YAGNI)** | 現在 {project} を持つ非 API route は業務 group のみで、将来別コンテキストの {project} route が増えた場合はテストが**意図的に fail** して「その route の org 境界をどう守るか」の判断を強制する — それが deny-by-default の狙い。事前 allowlist は空許可リストの温床になる |
| S2 | same-org 正常系が従来通り通る明示ケースを 1 本追加 (middleware 導入による誤 404 回帰の固定) | **対応済みと判断 (追加なし)** | CategoryCrudTest / VideoManualCrudTest / CategoryReorderTest / ProjectShowManualsTest / ItemCrudTest 等の全 happy-path (owner/編集者の 302 success・撮影者の閲覧 200) が本 middleware を通過して green — same-org 誤 404 の回帰はスイート全体で既に固定されている。重複テストは追加しない |

## 結論

Critical なし・Warning は logic-driven に見送り。マージ判定へ進む。
