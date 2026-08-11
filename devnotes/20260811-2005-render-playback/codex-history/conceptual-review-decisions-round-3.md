# 対応マトリクス: conceptual-review Round 3

## [Warning] `finishedJob` の props 組み立て条件に認可 (download ability) が入っていない
- 判断: **対応する**
- 根拠: 指摘が正しい。`canManage` は UI の表示制御であって秘匿境界ではなく、
  Inertia 応答そのものは詳細画面を閲覧できる撮影者にも届く。
  「endpoint と同じ条件」と書いた以上、ability も揃っていなければ主張が成立しない。
- 対応内容: props は `status === Published` **かつ** `$user->can('download', $manual)` の
  ときだけ `finishedJob` を組み立てる、と概念設計に明記。新 props (`canDownload` 等) は
  増やさない (`finishedJob` の null / 非 null が判定結果そのもの)。
  Feature テスト「published を閲覧できるが download 権限のない利用者には `finishedJob=null`」を
  テスト計画に追加する。

## 併せて明記したこと (指摘外・スコープ宣言)
- 既存 `playbackJob` (preview) は現在も `render` ability 非保持者へ渡っている。
  **本 TODO では変えない**。新たに増やす露出だけを ability に揃える方針であり、
  `RenderJobData` は `output_path` も署名 URL も含まないため露出は「job の存在」に留まる。
  この非対称を「直した」と書かない (誇張しない)。
