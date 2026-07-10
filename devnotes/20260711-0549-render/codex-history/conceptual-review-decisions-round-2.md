# 対応マトリクス: conceptual-review Round 2

## [Critical] org 単位 preview 上限が「件数を数えて 409」だけでは manual 間の同時リクエストを直列化できない
- 判断: 対応する
- 根拠: 指摘のとおり VideoManual 行ロックは manual 間の競合を防がない。上限検査 + job 作成の
  TOCTOU は Organization 行ロックでのみ塞げる（reserve の残高判定と同じ手法）
- 対応内容: triggerPreview は manual 行ロックの後に **Organization 行を lockForUpdate()** し、
  ロック下で org 全体の in-flight preview 数を検査してから job 作成。取得順
  `video_manuals → organizations` は既存グローバルロック順の部分列（循環待ちなし）と明記。
  「異なる manual への並行 trigger でも上限 3 を超えない」Feature テストを追加

## [Warning] NestedRouteIdorDefenseTest の対象が「4 route」のまま（playback 追加漏れ）
- 判断: 対応する
- 対応内容: 件数表記をやめ、登録 route 名 5 本を設計に明記
  （projects.manuals.render / .preview / .render-jobs.show / .render-jobs.playback / .download）

## [Warning] 「最新 1 世代保持」と playback の不整合（旧 succeeded job の output_path が削除済み実体を指す）
- 判断: 対応する
- 対応内容: 二段で整合させる:
  (a) playback の 302 条件を「同 manual・preview の**最新** succeeded job かつ output_path 非 NULL」に
  (b) 削除 job が S3 削除後に旧 job の **output_path を NULL 化**（削除済み実体を指し続けない）。
  削除 job は冪等（output_path NULL なら no-op）で失敗・再実行と整合

## [Warning] DeleteRenderOutputsJob へ任意の S3 キー配列を渡すのは削除範囲が広すぎる
- 判断: 対応する
- 対応内容: payload を **render job id** に変更。handle は job 行を再ロードし、
  (a) 「同 manual・同 kind の最新 succeeded ではない（世代交代済み）」を relation 経由で再検証、
  (b) output_path が manual 配下の期待 prefix であることを検証、してから S3 削除 +
  output_path NULL 化。最新 succeeded を指す id では no-op

## [Warning] preview の version 不一致を自由文 error で判定するのは型安全でない
- 判断: 対応する
- 対応内容: `render_jobs.error_code`（nullable）+ backed enum `RenderErrorCode`
  （scenario_version_changed / timeout / internal）を追加。DTO/Resource/TS 型に error_code を
  含め、フロント CTA は error_code の literal union で分岐。表示文言は error 列（サーバ確定）

## 指摘（テスト欄の stale 閾値が本文と不一致）
- 判断: 対応する
- 対応内容: テスト欄を「queued=10 分 / running=30 分の 2 閾値」に修正
