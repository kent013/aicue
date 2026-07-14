# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED**（Critical 該当なし）。Warning は 2 件とも設計で承認済みのトレードオフであり、コード変更なしで合議終了。

## [Warning] fc-match の `grep -qi 'Noto Sans CJK'` は将来の distro 側 naming 変更に脆い可能性
- 判断: 見送る（対応しない）
- 根拠: Codex 自身が「現時点では設計要件を満たしており問題なし」と明記。ubuntu runner の fontconfig family 表記は安定しており、naming が変われば fc-match がフォールバック扱いになり fail-fast が発火する（＝退行が顕在化する）ため、過剰な将来対応は不要（AGENTS.md 思考原則「今必要なものだけ作る」）。
- 対応内容: 変更なし。

## [Warning] 静的ガード正規表現は apt リスト 1 行化リファクタ時にテスト更新が必要
- 判断: 見送る（設計で承認済み）
- 根拠: detailed-design 施策 3 リスク節および design-review R2 が明示的に「静的ガードとして許容」と結論済み。独立行が消えれば test が fail し更新要否が顕在化する設計であり、サイレント破綻はしない。
- 対応内容: 変更なし。

## Suggestion 群
- すべて肯定的評価（追加位置の妥当性・fail-fast・型 narrowing・シェル安全性・work dir 後始末・skip guard の役割分離・既存テスト非破壊）。対応不要。
