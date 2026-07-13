# 対応マトリクス: conceptual-review Round 4

技術的 Warning（時刻精度・skew・terminal 後 touch）は R4 で解消確認。残る 2 件は Codex 自身が
「定義の受容判断」と明言したもので、**仕様として明示的に確定**する。

## [Warning] 4-1 no-op 保存でも scenario_version++ し失敗を stale 化する
- 判断: **仕様として受容（Codex 提案の option (a)）**
- 根拠: staleness を「失敗後に **scenario 保存が成立した（version が進んだ）**」と定義する。ユーザーが
  編集画面を開いて保存した＝当該 job を追い越して先へ進んだ、と解釈するのは自然。no-op 保存でも実害が
  残るのはレンダ失敗の一部だが、再レンダ試行で即再表示されるため fail-safe。実内容 revision を別途設ける
  のは本 finding（HIGH＝解析失敗→手動完成）に不均衡（オーバーエンジニアリング）。
- 対応内容: 定義を「保存世代基準」と明記し、no-op 帰結を意図的仕様として設計に記載。

## [Warning] 5 take 採用/解除はレンダ入力の実変更だが version 非 bump で未検出
- 判断: **スコープ外として受容し、期待効果を限定**
- 根拠: HIGH 本丸外。`render_input_revision` 相当の新設は過剰。version を採用起点で bump すると
  scenario_version_changed 誤発火・楽観ロック競合を招く。
- 対応内容: 期待効果を「render/preview は**シナリオ保存が後続した失敗**の stale 抑制に限定」と修正
  （「完全抑制」は主張しない）。残存エッジは fail-safe（表示）で明示済み。

## [Suggestion] 3 preview 分岐の lock 取得順
- 判断: **対応する**
- 対応内容: preview 分岐の追加 lock も既存 failJob と同じ **job → manual** の順で取得しロック順逆転を回避、と明記。

## [Suggestion] 7 snapshot 列の型
- 判断: **対応する**
- 対応内容: `video_manuals.scenario_version` と同じ **unsignedInteger nullable** に合わせる旨を明記。
