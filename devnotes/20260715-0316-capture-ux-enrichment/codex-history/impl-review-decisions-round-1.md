# 対応マトリクス: impl-review Round 1

Codex (gpt-5.3-codex, high) 全体判定: **APPROVED**。Critical 指摘なし。以下は Warning/Suggestion への対応。

## [Warning] requestPause() が canPauseResume を再利用せず supportsPauseResume() を再呼びしていて一貫性が弱い
- 判断: 対応する
- 根拠: ボタン出し分け (`phase === "recording" && canPauseResume`) と要求ハンドラのガードが同一判定になり読みやすい。canPauseResume は module 初期化時の一度評価で十分 (能力は実行中に変化しない)。
- 対応内容: `requestPause()` のガードを `if (!canPauseResume) return;` に変更。挙動は不変 (未対応端末はそもそもボタン非表示)。

## [Warning] R2-Critical/R3-2/R4-2 の「全ケース」網羅が提示差分上は代表ケース中心に見える
- 判断: 一部対応 (代表ケースで危険経路は網羅済みと判断しつつ、stale onresume を追加)
- 根拠: 主要な危険経路 (stale onpause after stop、resume pending 中の stale onpause、タイムアウト復旧+遅延イベント二重遷移、durationMs の pause 区間除外) は既にテスト済み。R4-2 の handle 自己同定は「タイムアウト復旧」テストで、多重押下ガードは専用テストで exercise 済み。追加コストの低い stale onresume (idle 到達後) のみ独立テスト化する。
- 対応内容: 「stale onresume: idle 到達後に onresume が来ても timer/phase を復活させない」テストを追加。

## [Suggestion] recoverPhaseFromRecorderState の timer 責務コメント / supportsPauseResume の prototype=null ケース
- 判断: 見送る
- 根拠: 既存コメントで「stopping 中は onstop に委ねる」「state を真実源に同期」を明記済みで意図は追える。prototype=null は `?.` で false になり実害なし。今必要なものだけ作る原則によりテスト追加は見送り。

## 再検証
- pnpm typecheck / pnpm lint / capture 関連 vitest (camera / GridOverlay / CameraRecorder) 全 green を再確認。
