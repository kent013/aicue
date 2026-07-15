# 対応マトリクス: conceptual-review Round 1

全体判定: **APPROVED**（Round 1 で承認）。Warning 2 件を設計に反映して確定する。

## [Warning] 期待効果「失敗誤認をゼロにする」は言い過ぎ
- 判断: 対応する
- 根拠: 通信断・タイムアウト・サーバ遅延そのものは残るため「ゼロ」は過大。妥当な表現へ修正する。
- 対応内容: conceptual-design.md の期待効果を「失敗誤認の主要因（前回エラー残留）を除去し、誤認リスクを有意に下げる」に修正。詳細設計にも同表現を継承。

## [Warning] テスト方針が clearErrors spy に寄りすぎ（回帰を取り逃す恐れ）
- 判断: 対応する
- 根拠: spy 検証は内部実装依存。UX が守られることを DOM レベルで確認するケースが必要という指摘は妥当。
- 対応内容: テスト計画に「前回エラー文言が submit 直後（pending 中）に DOM から消える」ユーザー視点ケースを追加する（clearErrors spy 検証と併存）。詳細設計のテスト計画へ明記。

## [Suggestion] 使命寄与の表現 / HIBP 別 item 優先度 / live region 将来検討
- 判断: 一部反映・一部見送り
- 根拠: 使命寄与は既に「信頼性の土台を守る改善」と整理済みで十分。HIBP 別 item の優先度は notes/scope_note に「優先度高め」と明記。live region 拡張は今回スコープ外（Codex も「今回はスコープ外でよい」と同意）。
- 対応内容: scope_note / notes に HIBP 別 item を「優先度高め」と記載。live region は将来検討として詳細設計のリスク欄に一言残す。
