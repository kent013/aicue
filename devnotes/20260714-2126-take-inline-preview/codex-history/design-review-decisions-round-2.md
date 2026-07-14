# 対応マトリクス: design-review Round 2

Codex 判定: CHANGES_REQUESTED（S1/S3 APPROVE。S2/S4/S5 に Critical）

## [Critical] S2: `take?.id` の $effect が初回でも teardown / 宣言的 src と手動 DOM 除去の競合
- 判断: 対応する（Codex の宣言的アプローチを採用）
- 根拠: 手動 `removeAttribute("src")` は Svelte の宣言的 `src={playbackUrl}` と競合し、初回/差し替え後に空になる恐れ。正しい。
- 対応内容: `<video>` を `{#key take?.id}` で囲み take 変更時に要素を再生成。teardown は `$effect` の cleanup に寄せ、close/採用成功/take 差し替え/component 破棄を同一 cleanup で扱う。手動 teardownVideo は cleanup（要素破棄前の pause）としてのみ使用。

## [Critical] S4: start 例外の finally で setRecording(false) は成功時も false に戻す
- 判断: 対応する
- 根拠: finally は成功時も走る。バグ。正しい。
- 対応内容: `setRecording(false)` は catch 経路のみ（実際には start 前に recording=false のままなので不要）。finally は `starting` リセットのみ。`setRecording(true)` は `recorder.start()` 成功直後のみ。

## [Critical] S4: onerror/track ended で recording を直接 false 化すると MediaRecorder が recording のまま解放され得る
- 判断: 対応する
- 根拠: 録画データ破壊の恐れ。正しい。
- 対応内容: 録画終了通知は原則 `recorder.onstop` に集約（唯一の setRecording(false) 発火点）。`onerror`/track `onended` は**安全停止（recorder.stop()）を開始**し、onstop 到達で setRecording(false)。停止不能時のみ明示的失敗状態へ。releaseForPreview の `recording` ガードは MediaRecorder の active window と厳密一致。

## [Warning] S4: acquirePreviewStream 失敗時に wasActiveBeforePreview=false 先行で再試行不能
- 判断: 対応する
- 対応内容: `wasActiveBeforePreview=false` は取得成功後に確定（失敗時は true のまま=再試行可能）。

## [Critical/Warning] S5: S2/S4 修正に伴うテスト追加
- 判断: 対応する
- 対応内容: 追加 — (a) 初回 open 後に video src が残る、(b) take 差し替え後に新 src で再生可能、(c) MediaRecorder error/track ended 中に camera 解放が実行されない、(d) stream 再取得失敗後に再試行できる、(e) start 成功時に終了前 `onRecordingChange(false)` が発火しない。
