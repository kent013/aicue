# 対応マトリクス: conceptual-review Round 1

判定 CHANGES_REQUESTED。Critical 0 / Warning 4 / Suggestion 2。**すべて対応**(反論なし)。

## [Warning] 原因分析は概ね正しい (肯定)

- 判断: 対応不要。読みが妥当と確認された。

## [Warning] collapse 条件が粗い。`/app/*` に限定しない理由と影響範囲を書け

- 判断: **対応する**
- 対応内容: 条件を「`expectsJson()` かつ **機械向け経路でない**」に精緻化し、
  `api/*` / `oauth/*` (11 route) / `.well-known/*` (4 route) を**除外**すると明記した
  (機械クライアントへ日本語の人間向け文言を返さないため)。
  `/app/*` に限定しない理由も書いた — **同じ穴が web 面の XHR にも等しく開いており**、
  限定すると「撮影 PWA だけ直っている」新しい非対称を作るから。
  Filament / Livewire も条件に合えば掛かるが**404 の文言が日本語になるだけ**と明示。

## [Warning] 403/422/409 の棚卸しをせよ

- 判断: **対応する**
- 対応内容: 実査した — `abort(403,'…')` / `abort(422,'…')` で**クラス名・SQL・モデル名を
  出している箇所は 0 件**、422 は ValidationException の日本語 field message、
  409 は固定文言 (`FROZEN_MESSAGE` 等)。設計に記載し、
  **網羅的な監査ではない**ことも併記した。

## [Warning] `bootstrap/app.php` での配置契約を明記せよ

- 判断: **対応する**
- 対応内容: 「配置の契約」節を新設。`ApiExceptionRenderer` より後 / 条件に合う 404 のときだけ
  非 null / 401・402 とは status が違うので競合しない / Inertia エラー画面は respond 側の
  最終整形なので JSON 要求にしか反応しない本 callback とは競合しない、を明記した。

## [Suggestion] 封筒にしない判断は妥当 (肯定)

- 判断: 対応不要。

## [Suggestion] 判定は `NotFoundHttpException` / status 404 で行え

- 判断: **対応する**
- 対応内容: 「判定は例外クラスではなく status で行う」節を追加。
  `ModelNotFoundException` だけを見ると **Laravel が変換した後**の経路 (実際に漏れている経路) を
  取り逃がすため、`HttpExceptionInterface` の status 404 を条件にする。
  あわせて「文言つき `abort(404, …)` が 0 件」という前提を**テストで固定する**と決めた。
