# 対応マトリクス: conceptual-review Round 1

全体判定: APPROVED(Round 1)。Warning 2件と有用な Suggestion を概念設計へ反映する。

## [Warning] テスト方針が本文に明示されていない(禁止事項1)
- 判断: 対応する
- 根拠: 禁止事項1「テストなしの実装完了報告」。小変更でもテスト必須。
- 対応内容: 概念設計に「テスト方針」節を追加。vitest で「範囲外入力→押下でエラー表示→有効値へ修正→エラーが消え invalid が外れる」を検証する旨、および FormField の aria-invalid 解除確認を明記。

## [Warning] serverErrors 残留は本設計では解消しない(スコープ整理の明記)
- 判断: 対応する(明記のみ)
- 根拠: 本件は clientError の stale のみが対象。serverErrors(full POST 往復由来)のクリア戦略は別件で、混同を避けるため明示すべき。
- 対応内容: スコープ外節に「本修正は clientError の stale state のみ対象。serverErrors のクリア戦略は別件」と明記。

## [Suggestion] effect 本文に clientError の有無も条件に入れると意図が明確
- 判断: 対応する
- 根拠: `if (clientError !== null && isValidCount)` は挙動差は小さいがレビュー容易性が上がり、不要な代入も避けられる。
- 対応内容: 実装方針の $effect を `if (clientError !== null && isValidCount) clientError = null;` に更新。

## [Suggestion] その他(使命整合・実現可能性・型安全性・スコープ・a11y)
- 判断: 見送る(肯定的評価のため対応不要)
- 根拠: いずれも設計を支持する内容。a11y は詳細設計のテスト計画で担保する。
