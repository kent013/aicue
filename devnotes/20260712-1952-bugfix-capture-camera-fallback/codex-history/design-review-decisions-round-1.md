# 対応マトリクス: design-review Round 1

## [Warning] 施策2: startRecording の多重クリック再入 (getUserMedia 待ち中の非同期再突入)
- 判断: 対応する
- 根拠: getUserMedia 待機中の連打で処理が並走し得るのは事実。UI disabled ではなく関数の早期 return で防ぐ提案は本リポジトリの disabled 禁止規約とも整合する。
- 対応内容: `let starting = false` の再入ガードを追加し、`startRecording` 冒頭で `if (starting || recording) return;`、本体全体を `try { ... } finally { starting = false; }` で包む (全 return 経路でガード解除)。`starting` は UI に出さないため `$state` にしない。再入ガードのテスト (「pending 中に 2 連打しても getUserMedia は 1 回」) も追加。

## [Warning] 施策4: 成功パス (onCaptured) の契約テストが 1 本もない
- 判断: 対応する
- 根拠: startRecording の分岐を大きく触るため、録画成功→onCaptured 発火の回帰検知点は必要。jsdom の HTMLMediaElement 未実装は `HTMLMediaElement.prototype.play` の stub で回避可能。
- 対応内容: CameraRecorder.test.ts に成功契約テストを 1 本追加 (MediaRecorder 最小クラス stub で `stop()` 時に `ondataavailable`→`onstop` を手動発火、getUserMedia は fake stream 解決、play は Promise.resolve stub)。`onCaptured(blob, "video/webm", durationMs)` の発火と `onCameraUnavailable` 不発火を検証。

## [Warning] 施策4: CaptureShow (c) が contentType 正規化 (`mimeType.split(";")[0]`) の回帰を拾えない
- 判断: 対応する
- 根拠: `handleCaptured` は録画経路と共有で、codecs 付き MIME の正規化はアップロード API 契約 (content_type) に直結する。
- 対応内容: (c) に `contentType: "video/mp4"` の明示検証を追加し、(d) として codecs 付き MIME (`video/webm;codecs=vp9`) → `contentType === "video/webm"` の正規化回帰テストを追加。

## [Suggestion] 施策1 (分類ヘルパ現状維持)・施策3 (静的/実行時分離、role=status、handleCaptured 共通化)・横断 (Inertia 使い分け、波及明示)
- 判断: 対応不要 (肯定的評価。設計変更なし)
