# design-review Round 2

Round 1 の全体判定 APPROVED を受け、指摘された Warning 2 件を同 PR で反映しました。

## [Warning] 施策1: dirty クリア用 $effect の将来耐性 → 対応
- テスト計画に名前付き不変条件を追加:
  「保存直後は dirty=false でも justSaved=true を維持する」
  （`applySaved` 後に `scenario-saved-indicator` が残ることを固定し、将来 dirty 算出が変わっても
  意図せぬ消去が混入しないことを回帰で担保）。

## [Warning] 施策2: 新規 testId の test inventory 明文化 → 対応
- 詳細設計に「testId インベントリ」節を追加。新設は `preview-start-error` /
  `preview-purchase-link` の 2 つで、既存 `render-start-error` / `render-purchase-link` /
  `render-error` / `preview-error` と同列の回帰監視対象として明記。RenderPanel.test.ts の
  網羅ケース（誤帰属防止・共存）で両 testId を検証。

他の Suggestion は維持です。以上で Round 1 の Warning は解消と考えます。残懸念が無ければ
最終 APPROVED をお願いします。
