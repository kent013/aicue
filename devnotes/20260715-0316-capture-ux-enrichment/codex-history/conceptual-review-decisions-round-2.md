# 対応マトリクス: conceptual-review Round 2

## [Warning] flip: 一部モバイルはカメラ二重取得不可（新 stream 取得成功後に旧停止が成立しない）
- 判断: 対応する
- 根拠: 旧 stream が生きている間は新 `getUserMedia({facingMode})` が失敗し得る。「旧を維持したまま新取得」を前提化できない。
- 対応内容: flip 手順を 3 段の段階的縮退に変更:
  1. まず既存 video track の `applyConstraints({ facingMode })` を試す（同一 stream 維持=二重取得不要）。成功で終了。
  2. 不成立時のみ旧 stream を停止 → 新 facingMode で再取得。失敗時は**旧 facingMode で再取得して復旧**。
  3. 復旧にも失敗した場合のみ通常のカメラ不能フロー（transient エラー表示、idle 復帰）。それでも F-03（onCameraUnavailable/恒久フォールバック）へは倒さない（flip は付加操作であり録画本体を殺さない）。
- 「必ず旧 stream を維持したまま新 stream を取得できる」前提を撤回。

## [Warning] pause/resume の phase 確定は pause/resume イベント基準に
- 判断: 対応する
- 根拠: `pause()/resume()` を呼んだ直後に同期で phase を確定すると `recorder.state` とずれ得る。能力検査 + 同期例外処理だけでは不十分。
- 対応内容:
  - phase 確定は `recorder.onpause` / `recorder.onresume` イベントを基準にする。ボタン押下は「要求」であり、遷移はイベント到達で確定。
  - 操作要求中の**多重押下ガード**（pausing/resuming 相当の in-flight フラグ）を設ける。
  - `onerror` / 予期しない `onstop` / イベント未到達時にも `recorder.state` から UI phase を復旧できるようにする（recorder.state が "inactive" なら idle、"paused" なら paused、"recording" なら recording に同期）。
  - phase マシンは MediaRecorder イベント経由の遷移を単一ソースに集約（同期押下では phase を先に動かさない）。

## [Suggestion] supportsPauseResume() は存在確認にすぎない旨を明記
- 判断: 対応する
- 対応内容: 命名/コメントで「API 存在確認であり正常動作保証ではない。実行時失敗（InvalidStateError・イベント未到達）への退行が最終防御」と明記。実行時に pause/resume が失敗したら phase を recorder.state から復旧し、以降その take では従来 start/stop 挙動に倒す。
