# 対応マトリクス: conceptual-review Round 1

全体判定: APPROVED (Round 1)。Critical なし。Warning 2 件を反映して精度を上げる。

## [Warning] 期待効果「空振り read-all を構造的に排除」は言い過ぎ (観点4)
- 判断: 対応する
- 根拠: 別タブの古い画面・手動 POST では依然到達可能。UI 導線の空振り排除に限定するのが正確。
- 対応内容: 概念設計の期待効果を「通常操作経路からの空振り read-all を排除 (別タブ/手動 POST は
  依然到達可能)」に修正済み。使命貢献も「周辺 UX の摩擦低減」と位置づけを明記。

## [Warning] shared prop 衝突原因の温存で将来の誤参照リスク (観点5)
- 判断: 対応する
- 根拠: shared `notifications.unreadCount` とページ prop `notifications` の衝突を今回根治しないため、
  保守者が shared prop を再参照できると誤認しうる。
- 対応内容: 概念設計の実装方針に「Index は shared `notifications.unreadCount` を参照しない」ことと
  JSDoc へ衝突理由を短く残す旨を明記。詳細設計・実装の JSDoc にも反映する。

## [Suggestion] 一覧取得と件数取得のスナップショット差分許容の明示
- 判断: 対応する (軽微だが明記)
- 対応内容: 概念設計に整合の前提 (トランザクション整合は要求せず一瞬のズレは無害) を追記。

## その他 [Suggestion]
- 使命貢献を過大評価しない位置づけ、型安全 (unreadCount 必須 prop・undefined 逃がさない)、
  スコープ妥当性はいずれも設計方針と一致。追加対応不要 (詳細設計で型必須を担保)。
