# 概念設計レビュー Round 5

R4 の残 [Warning] 2 件は Codex が「定義の受容判断」と位置づけたもの。**仕様として明示確定**しました。

## W4-1: no-op 保存で failure が stale 化する件 → 仕様として受容（option (a)）
staleness を **「失敗後に scenario 保存が成立した（`scenario_version` が進んだ）」= 保存世代基準**と定義。
「ユーザーが編集画面を開いて保存した＝当該 job を追い越して先へ進んだ」と解釈する意図的仕様。
no-op 保存でも実害が残るのはレンダ失敗の一部だが、再レンダ試行で即再表示されるため fail-safe。
実内容 revision の別設は本 finding（HIGH＝解析失敗→手動完成）に不均衡なため採らない。

## W5: take 採用/解除の未検出 → スコープ外・期待効果を限定
render/preview の stale 抑制の適用範囲を **「シナリオ保存が後続した失敗」に限定**すると明記（「完全抑制」は
主張しない）。take 採用のみ後続は version 不変で検出外だが fail-safe（表示）。`render_input_revision` 相当の
新設は過剰・scenario_version_changed 誤発火/楽観ロック競合を招くため不採用。

## Suggestion 反映
- preview 分岐の追加 lock も既存 failJob と同じ **job → manual** の順で取得（ロック順逆転回避）。
- snapshot 列は `video_manuals.scenario_version` と同じ **unsignedInteger nullable** に合わせる。

## 質問
上記 2 点を仕様として確定したことで、設計は APPROVED として差し支えないか。残る Critical/Warning があれば
指摘してください（なければ APPROVED を明示してください）。
