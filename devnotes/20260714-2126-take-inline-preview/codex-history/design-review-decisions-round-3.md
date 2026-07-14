# 対応マトリクス: design-review Round 3

Codex 判定: CHANGES_REQUESTED（S1/S3 APPROVE。S2/S4/S5 に Critical/Warning）

## [Critical] S2: cleanup が pause() のみでは src/デコード資源/接続が残る
- 判断: 対応する
- 根拠: Modal が DOM を保持する場合、pause だけでは資源解放にならない。正しい。
- 対応内容: `<video>` を `{#if open && take !== null}{#key take.id}` 下でのみ生成し、cleanup で完全 teardown（`pause()` + `removeAttribute("src")` + `load()`）。要素は open 中のみ存在＝再 open で新要素になり宣言的 src と競合しない。

## [Critical] S4: onstop 内 finalize/onCaptured が例外時 setRecording(false) 未到達で永久録画中
- 判断: 対応する
- 根拠: 正しい。禁止したのは start の無条件 finally であり、onstop 内は終了保証が必要。
- 対応内容: `onstop` 本体を `try { finalize } finally { setRecording(false) }` で終了通知を保証。

## [Warning] S4: safeStop の state 明確化・停止失敗で recording=true 残置
- 判断: 対応する
- 対応内容: 内部 phase（idle/recording/stopping）を導入。`recording` 通知 = phase==="recording"。
  camera 解放（releaseForPreview）は **phase !== "idle" で拒否**（active と stopping の両方で禁止）。
  safeStop は phase==="recording" のときのみ stop()（多重呼び出しガード）。stop() 例外時は fatal cleanup
  で phase=idle + notify(false) + releaseCamera（UI 復旧不能を防ぐ）。

## [Critical/Warning] S5: S2/S4 修正に伴うテスト追加
- 判断: 対応する
- 対応内容: 追加 — (a) close 後に src 除去/load() 実行・video 破棄、(b) onCaptured reject/throw でも録画状態解除、
  (c) stopping 中は preview camera 解放拒否、(d) safeStop 多重呼び出しで stop() 重複しない。
