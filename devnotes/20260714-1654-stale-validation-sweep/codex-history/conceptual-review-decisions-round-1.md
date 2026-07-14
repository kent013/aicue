# 対応マトリクス: conceptual-review Round 1

全体判定: **APPROVED** (Round 1)。Critical なし。Warning 2 / Suggestion 多数。
APPROVED のため合議ループは Round 1 で終了し、指摘は Phase 2 詳細設計へ織り込む方針で
概念設計を軽微修正した。

## [Warning] 「以後の bug-hunt で同種指摘が再発しない」は言い切りが強すぎる
- 判断: 対応する
- 根拠: 棚卸しは `setError` / 既知パターン起点であり、別実装の stale validation までは
  保証していない。過度な一般化は誤解を招く。
- 対応内容: 期待効果を「今回把握した client-set field エラーパターンについては掃討完了。
  再発防止は再現テストで機械的に担保」に弱めた (conceptual-design.md 期待効果節)。

## [Warning] client-error と server-error 共存時の挙動を明示せよ
- 判断: 対応する
- 根拠: 過剰クリア退行を将来防ぐには、共存時の期待挙動をテスト観点として固定すべき。
- 対応内容: 既にテスト計画に含む「serverErrors 非退行」を、詳細設計のテスト計画で
  (1) 無効送信後に有効値へ戻すと client-error だけ消える (2) server-error は client 側
  `$effect` では消えない、の 2 観点として明文化する (Phase 2 で反映)。

## [Suggestion] Settings 側の clientTargetError リセット境界を明記
- 判断: 対応する
- 根拠: 再 mount しないライフサイクル (再認証キャンセル等) で stale が残る余地の指摘は妥当。
- 対応内容: `transferOwnership` の `onFinish` で `clientTargetError = null` を明示する旨を
  概念設計・実装方針に追記した。

## [Suggestion] 使命の因果を「回復性担保・誤解低減」に絞る
- 判断: 一部対応 (見送り気味)
- 根拠: 期待効果節の主眼は既に「矛盾フィードバックの解消」。過度な広げ表現は避けるが、
  現状表現でも因果は通っている。詳細設計では簡潔さを優先し追加装飾はしない。

## [Suggestion] user_id の文字列比較契約をテストでも固定
- 判断: 対応する
- 根拠: `String(m.id) === form.user_id` の契約は select value が string である前提の要。
- 対応内容: 詳細設計のテスト計画に「select の option value は `String(id)`、比較も string」
  を前提として明記する。

## [Suggestion] 候補 0 人ケースの残留は正しい / スコープ適切 / 型安全
- 判断: 見送り (現状維持)
- 根拠: いずれも設計を肯定する指摘。追加変更不要。
