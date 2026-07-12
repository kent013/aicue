# 対応マトリクス: design-review Round 2

全体判定: CHANGES_REQUESTED (Warning 1 件のみ。Round 1 の Critical 2 / Warning 2 への対応は承認済み。
expectsJson の Critical は Codex 側が誤りを認め撤回)

## [Warning] `has_billing_access=false` 時の callout 文言が新しい判定条件と不整合
- 判断: 対応する
- 根拠: 指摘のとおり。施策 1 後の表示対象は「有償契約中だが支払い不健全」のみであり、
  旧文言「有効なサブスクリプションがありません。プランを契約すると…再開できます」は
  (1) 契約が存在する状態で「ありません」と表示し、(2) 復旧手段として新規契約を誘導する
  (二重契約リスク)、(3) 施策 2 の統一文言 (お支払い確認) と矛盾する。
- 対応内容: 施策 3 を更新。
  - callout 本文を「サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。」に変更
    (middleware BLOCKED_MESSAGE と同一の意味論。ダッシュボードは遮断でなく予告のため語尾のみ調整)
  - CTA ラベルを「プランを見る」→「お支払い方法を確認」(遷移先 /billing は維持 —
    billing ページに Customer Portal 導線がある)
  - 新規契約・チケット購入を復旧手段として案内しない
  - `Dashboard.test.ts` のテスト計画に新文言・CTA ラベルの固定を追加
  - DS 準拠維持 (Card / Button atom・token class のみ)
