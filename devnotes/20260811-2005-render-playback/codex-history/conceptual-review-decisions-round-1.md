# 対応マトリクス: conceptual-review Round 1

## [Critical] 5. kind 別 ability に分岐する順序を明記せよ
- 判断: **対応する**(一部反論)
- 根拠: 指摘のとおり、既存の一律 `Gate::authorize('render', $manual)` のままでは
  「完成動画の受け取りは `download` ability」という設計が成立しない。
- 対応内容: 概念設計 §改善アイデア 2 に順序を明記した (層 2 の 404 三段 → kind から ability を
  写して authorize → kind=render のみ published 404 → current 照合 404)。
  **反論**: 提案コードの `else { abort(404); }` は採らない。`RenderKind` は
  `Preview` / `Render` の 2 値 enum であり、`match ($renderJob->kind) { ... }` で書けば
  網羅性は型で保証され、到達不能な dead branch を作らない (PHPStan level 10 でも
  不要分岐は指摘対象になりうる)。詳細設計では match 式で ability 名を写す形にする。

## [Critical] 8. project / manual / renderJob の所属確認 (層 2) を曖昧にするな
- 判断: **対応する**
- 根拠: セキュリティ不変条件 2/10 の要求そのもの。設計書に書いていないと実装で落ちる。
- 対応内容: 概念設計 §制約・前提 に三段の担保機構 (middleware + inline guard /
  `Route::scopeBindings()` / `video_manual_id` inline 再検査) を実コードの語彙で明記した。

## [Warning] 1. 期待効果が過大 (撮影者は観られないまま)
- 判断: **対応する**
- 根拠: 事実そのとおり。誇張は本リポジトリの規約 (保証しないものを明記する) に反する。
- 対応内容: 期待効果を「編集者がアプリ内で確認できる」に限定し、撮影者への視聴開放は
  完了条件に含めないと明記した。

## [Warning] 2. DL ボタンの表示条件から canManage を外すな
- 判断: **対応する**(専用 props の新設は見送り)
- 根拠: `canManage` は既に props にあり UI もそれで分岐している。
  `canDownloadFinishedVideo` を新設するのは同じ意味の値の二重管理であり思考原則 2 に反する。
- 対応内容: 条件を `finishedJob !== null && canManage` と明記。新 props は作らない。

## [Warning] 3. CurrentRenderArtifact の責務境界が曖昧
- 判断: **対応する**
- 根拠: 妥当。published / ability を service に混ぜると「成果物選択」以外の意味が入り、
  名前が役割を示さなくなる (思考原則: 機能の名前に立ち返れ)。
- 対応内容: メソッドを `currentSucceeded(VideoManual, RenderKind): ?RenderJob` の 1 本に限定。
  published 判定と ability 判定は呼び出し側に置く。

## [Warning] 4. route 側でも current job との同一性を確認せよ
- 判断: **対応する**(元設計の意図を明文化)
- 対応内容: 順序 ④ として「`currentSucceeded()` の結果と同一行か」を照合すると明記。

## [Warning] 5. published 404 は authorize の後に置け (download と同順)
- 判断: **対応する**
- 対応内容: 順序 ② → ③ の並びで明記。

## [Warning] 6. Architecture gate の対象を絞れ
- 判断: **対応する**
- 対応内容: 「`JobStatus::Succeeded` + 最新 1 件取得を併用した `render_jobs` からの成果物選択」を
  母集団とし、exact-fit の目録で `CurrentRenderArtifact` に限定すると明記した。

## [Warning] 7. output_path の非 null 性を呼び出し側で再確認せよ
- 判断: **対応する**(詳細設計で具体化)
- 対応内容: service は `?RenderJob` を返し、署名 URL 発行の直前に
  `$path = $job->output_path; if ($path === null) { abort(404); }` を置く (専用 VO は作らない)。

## [Warning] 8. inline disposition で開く経路が増えることをテストで固定せよ
- 判断: **対応する**
- 対応内容: 指摘のテストケース 5 種を詳細設計のテスト計画に入れる。

## [Suggestion] 3 / 6 / 7
- 判断: 参考として受領 (設計変更なし)。
