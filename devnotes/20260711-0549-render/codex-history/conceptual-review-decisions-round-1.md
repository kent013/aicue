# 対応マトリクス: conceptual-review Round 1

## [Critical] `view` 権限のポーリング応答に署名 URL（output_url）を混ぜている
- 判断: 対応する
- 根拠: 指摘のとおり render/download ability の実質迂回。撮影者が preview 内容・完成動画の
  署名 URL を取得できるのは §10.5 の権限表（編集者=render/download）に反する
- 対応内容: ポーリング応答から output_url / output_path を全廃（進捗情報のみ）。
  成果物アクセスを専用 route + ability に分離:
  (a) preview 再生 = 新 route `GET .../render-jobs/{renderJob}/playback`（`render` ability、
      kind=preview の succeeded のみ 302 署名 URL。それ以外 404）
  (b) 完成 mp4 = 既存計画の download route（`download` ability）のみ。
  published のインライン再生は v1 スコープ外へ移動（§2/§7/§8/スコープ外を改訂）

## [Warning] queued 時点で rendering に倒すと、ワーカー滞留時に編集を長時間止める
- 判断: 対応する
- 根拠: enqueue 時遷移自体は排他の要（変えない）が、queued 滞留の回復 SLA は
  running と分けられる（queued は処理が始まっていない = 30 分待つ理由がない）
- 対応内容: `render:recover-stale-jobs` を 2 閾値化。queued は
  `render_queued_stale_after_minutes = 10` で failJob（→ manual を ready に復帰）、
  running は従来 30 分。遅延配送は pipeline 冒頭の queued guard で無害

## [Warning] DeleteTakeObjectsJob の流用は「別物の概念を似ているからで統合しない」に抵触
- 判断: 対応する
- 根拠: 現実装はキー配列削除のみだが、Take 概念に紐づく名前の job に render 出力を
  流し込むと将来 Take 固有副作用が入った時に事故る
- 対応内容: 専用の薄い `DeleteRenderOutputsJob`（S3 キー配列削除のみ・media queue・冪等）を
  新設。実装は同型だが概念を分離

## [Warning] preview の abuse 耐性（in-flight 1 本だけでは manual 跨ぎで負荷を積める）
- 判断: 対応する
- 対応内容: 契約として固定: (a) named rate limiter `render-trigger`（user+org 単位 6 回/分）を
  render/preview POST に適用、(b) org 単位の同時 preview 上限
  `render_max_inflight_previews_per_org = 3` をトリガー tx 内で検査（超過 409
  `RenderConflictType::OrgPreviewLimit`）

## [Warning] preview の version 不一致 fail の UX（単なる失敗に見える）
- 判断: 対応する
- 対応内容: error 文言を「編集中にシナリオが変更されたため、プレビューを作り直して
  ください」に固定し、フロントは failed + この文言で「作り直す」CTA を表示（§5/§8 改訂）

## [Warning] render/preview 出力の保持ポリシー未定義（ストレージ肥大）
- 判断: 対応する
- 対応内容: 「render / preview とも最新 succeeded 1 世代のみ保持」を契約化。
  finalize 時に旧世代キーを削除 job へ、失敗時はアップロード済み出力をベストエフォート
  削除（catch の後始末に追加）。テスト観点に世代 1 固定を追加

## [Warning] published 画面のインライン再生はスコープ拡大
- 判断: 対応する
- 対応内容: v1 は「完成動画の取得は download route のみ」。インライン再生をスコープ外へ

## [Warning] RenderJobResource に render/preview 両載せ + output_url の条件付き nullable で型が弱い
- 判断: 対応する（output_url 全廃により大半解消）
- 対応内容: output_url をポーリング shape から削除 → render/preview の応答フィールドは
  完全同型（id/kind/status/step/progress/error/manual_status）になり条件付き nullable が
  消滅。DTO は readonly + kind 判別子、TS は kind literal union。lang は FormRequest で
  `in:ja` を型として固定

## [Suggestion] enum backed 統一・lang の validator 固定
- 判断: 対応する（上記に包含。全 enum は backed string enum）
