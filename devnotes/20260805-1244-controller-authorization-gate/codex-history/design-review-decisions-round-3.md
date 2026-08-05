# 対応マトリクス: design-review Round 3 (APPROVED)

全体判定 **APPROVED**。全 7 施策が APPROVE。
Critical / Warning は 0 件で、残りは Suggestion 3 件のみ。
いずれも「将来こうなったら見直せ」という予防的注記であり、コストが低いため全て反映した。

## [Suggestion] (施策 2) bracketed namespace では名前空間 import の波括弧深さが 1 になる
- 判断: **反映する（注記として）**
- 根拠: 指摘は正しい。ただし Codex 自身が「現行コードが非 bracketed namespace で
  統一されている限り対応不要」と述べているとおり、本リポジトリは
  `namespace App\Foo;` のセミコロン形式で統一されており（Pint も非 bracketed を強制）、
  bracketed namespace は 1 件も存在しない。
  今 対応ロジックを足すのは「今必要なものだけ作る」に反する。
- 対応内容: 深さ判定の直後に前提と再検討条件を注記し、
  `AuthorizationMarkerScanner` の docblock にも同じ注記を入れることを設計に明記した。

## [Suggestion] (施策 3) `gatherMiddleware()` は「宣言順」と書く方が正確（middleware priority で実行順が変わりうる）
- 判断: **反映する**
- 根拠: 正しい。当初「実行順の配列」と書いていたが、
  Laravel の `$middlewarePriority` が設定されると最終実行順は並べ替えられる。
  現行構成では検査対象の 3 つ（`resolve.api-actor` / `api.project-in-org` / `idempotent`）が
  priority リストに含まれないため宣言順 = 実行順だが、記述としては不正確だった。
- 対応内容: 「**宣言順**（group middleware → route middleware）の配列を返す」に修正し、
  priority 導入時に本テストの前提が変わる旨の注意書きを追加した。

## [Suggestion] (施策 5) ケース 16 で権限付与後に relation キャッシュが残ると偽陰性になりうる
- 判断: **反映する**
- 根拠: 妥当。`ProjectPolicy::canManageProject` は
  `$user->organizationRole($organization)` と `$project->memberRole($user)` を使い、
  後者は `project_members` の relation を経由する。
  同一リクエストではないため実害は出ない見込みだが、
  テストが落ちたときに「キャッシュ由来か本質か」を切り分けられなくなるのは避けたい。
- 対応内容: ケース 16 のコードに
  `$viewer->refresh();` と `$project->unsetRelations();` を追加し、
  意図（偽陰性の切り分け）をコメントで残した。

## 結論

詳細設計 **APPROVED (Round 3)**。実装フェーズへ進める状態。
