# 対応マトリクス: design-review Round 1

全体判定: APPROVED(Round 1、両施策 APPROVE)。Warning 2件に対応。

## [Warning] 施策1: 将来 clientError に別種メッセージが入ると過剰クリアの余地(種別化の提案)
- 判断: 反論する(現状維持) + ドキュメント補強
- 根拠: 現行 `clientError` は count 範囲バリデーション専用の単一用途 state であり、他種メッセージは存在しない。
  discriminated union(`type ClientError = {kind: "count_range"; ...}`)の導入は、今必要のない抽象化で
  AGENTS.md 思考原則「今必要なものだけ作る(オーバーエンジニアリング禁止)」・禁止事項の趣旨に反する。
  Codex 自身も「現スコープでは実害は低く incremental 方針として十分」と評価している。
- 対応内容: 種別化は行わない。代わりに施策1の `$effect` 直上コメントに「clientError は count 範囲
  バリデーション専用。将来別種エラーを載せる場合はクリア条件の再検討が必要」という不変条件を明記し、
  将来の保守者への注意喚起に留める(最小変更を維持)。

## [Warning] 施策2: serverErrors 優先系の非退行テストを1本追加
- 判断: 対応する
- 根拠: 低コストで価値が高い。`clientError` を消しても `serverErrors.count` があれば invalid が維持される
  ことを固定すれば、本 effect が clientError のみを対象とする(サーバエラー非対象)という設計契約を
  テストで担保でき、将来の回帰も防げる。
- 対応内容: 施策2 に3本目のテストを追加。`inertiaPage.props.errors.count` を与えた状態で有効値へ修正しても
  サーバエラー文言と invalid が残ることを検証する。inertiaPage.props はテストで実物を使う(既存モック方針)
  ため、Inertia の page store に errors を注入する手段を明記する。

## [Suggestion] idiomatic 観点($effect vs $derived)
- 判断: 見送る(現提案支持のため対応不要)
- 根拠: Codex は「イベント起点の局所 state を別 reactive 条件で消す要件なので $effect が idiomatic」
  「$derived 置換は attempted 導入で変更範囲が広がる」「incremental・局所修正には $effect が最適バランス」
  と現提案を明確に支持。概念設計 Round1 の代替案B検討と一致。
