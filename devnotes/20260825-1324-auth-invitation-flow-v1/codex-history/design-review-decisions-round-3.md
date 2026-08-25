# 対応マトリクス: design-review Round 3

全体判定 **APPROVED** (施策 A / B / C すべて APPROVE。Critical / Warning 残件なし)。

## [Suggestion] 施策 A: SQL 観測は二段階状態として実装すると明確
- 判断: 採用
- 対応内容: 施策 A テスト計画へ実装注意として追記 — one-shot 注入の callback を
  「injected (削除注入済み)」「1c の対象 SQL を観測済み」の二段階状態で管理する
  (または注入用 beforeExecuting と記録用 listener を分離する)。注入で callback 全体を
  inert にすると 1c の SQL を記録できないため。

## 最終確認 (app-design Phase 2-5: 使命・禁止事項チェック)
- 使命への寄与: 招待は組織 (現場チーム) へメンバーが入る唯一の導線。i7 は組織の存在を教える
  500 の口を塞ぎ、i16 は招待された現場作業者が登録直後に撮影ワークフローへ入るまでの
  ステップを 1 つ減らす。3 施策とも正典 v1 への追従で家系の形と揃う。
- 禁止事項: テストファースト (各施策で赤→緑の順序を明記) / 既存テストの削除・上書きなし
  (redirect 期待値の追随更新のみ、検証意図は不変) / response()->json() 直書きなし /
  PHPStan widen なし / DatabaseTransactions 個別使用なし / Artifact 不使用。
- コーディングルール: PHPStan L10 チェック各施策に記載 / Factory 使用 / 新モデルなし
  (Factory 追加不要) / DTO・JsonResource の変更なし / 検証コマンド 10 本全数。
- 乖離台帳の確認段 (Phase 3-0): 変更・新設パスは template-fingerprints.json の entries
  281 件に 1 件も該当せず、採用時債務一覧にも無い — 逸脱登録・LedgerPins 更新は発火しない
  (詳細設計書の「乖離台帳の確認段」節に記録済み)。
