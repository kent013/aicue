# 対応マトリクス: impl-review Round 1

Codex 全体判定: CHANGES_REQUESTED (Critical なし。Warning×2 / Suggestion×3)

## [Warning] register 専用前提がコード上で暗黙 → 将来再利用時の current 上書きリスク
- 判断: 対応する (DocBlock 明示化)
- 根拠: 設計 detailed-design.md リスク節が「docblock に register 専用を明記して防御」を既に方針化。
  一方で新規メソッド追加 (acceptInvitationForRegistration) は設計が明示的に却下済み
  (「今必要なものだけ作る」)。Codex も命名強化 or DocBlock のいずれかで可としており、
  設計の意思決定 (DocBlock) を採用する。
- 対応内容: acceptInvitationIfValid() の DocBlock に「本メソッドは register 経路専用。
  join 成立時に参加組織を無条件で current_organization_id に確定する副作用を持つ。
  ログイン中経路から再利用してはならない (既存 current を無条件上書きするため)」を明記。

## [Warning] 既メンバー null return 経路で current を触らない
- 判断: 見送る (現仕様で意図通り)
- 根拠: Codex 自身「今回要件に照らすと実害は小さい」「現時点では意図通り」。既メンバー fallback は
  個人組織パス (provision) が current を据えるため未設定にはならない。POST 受諾非変更テスト (2-6) で固定済み。
- 対応内容: なし (DocBlock 明示化でカバー)。

## [Suggestion] トランザクション境界 / 原子性
- 判断: 対応不要 (設計上問題なしと Codex 確認)
- 根拠: 外側登録 tx 内で savepoint 後 forceFill、失敗時は外側 tx ごと rollback。整合性懸念低。

## [Suggestion] テスト網羅性 / [Suggestion] セキュリティ
- 判断: 対応不要 (十分と Codex 確認)
