# 対応マトリクス: design-review Round 1

全体判定: APPROVED（施策1 APPROVE / 施策2 APPROVE）。Warning 2 件を同 PR で反映。

## [Warning] 施策1: dirty クリア用 $effect の将来耐性 → 遷移不変条件をテストで固定
- 判断: 対応する
- 根拠: 妥当。dirty 算出変更で justSaved の意図せぬ消去が混入する余地を、名前付き回帰テストで封じる。
- 対応内容: テスト計画に「保存直後は dirty=false でも justSaved=true を維持する」を名前付きで追加。

## [Warning] 施策2: 新規 testId (preview-start-error / preview-purchase-link) の test inventory 明文化
- 判断: 対応する
- 根拠: 妥当。新 testId を既存 render-* と同列の回帰監視対象として明記し拾い漏れを防ぐ。
- 対応内容: 詳細設計の帰属マトリクス直後に「testId インベントリ」節を追加し、新設 2 testId を明記。
  RenderPanel.test.ts の網羅ケースで両 testId を検証（テスト計画に既記載）。

## [Suggestion] 各種（状態モデリング・a11y 方針・購入導線 source 局所化・DTO 非変更）
- 判断: 見送る（変更不要）
- 根拠: Codex が妥当と評価。維持。
