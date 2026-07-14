# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED**（Critical / Warning なし）。

## [Suggestion] RenderPanel.test.ts に source 別クリアの対称回帰を追加
- 内容: 「render 起動失敗表示中に render を再起動して成功したら render-start-error だけ消え、preview-start-error は残る」対称ケース。
- 判断: **対応する**
- 根拠: source 別クリア仕様（start() が該当 source のみクリア）の退行検知を強固にする。安価で価値が高く、S2 の核となる不変条件の裏面を固定できる。
- 対応内容: RenderPanel.test.ts に対称回帰テストを 1 件追加。preview 起動失敗を残したまま render 起動成功で render-start-error のみ消えることを検証。
