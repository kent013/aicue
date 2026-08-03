# 対応マトリクス: design-review Round 1

全体判定: APPROVED (Round 1)。施策1・施策2 とも APPROVE。
Warning 1 件は対応し、詳細設計へ反映した。

## [Warning] 「password.confirm middleware 互換あり」との将来誤認リスク (施策2)
- 判断: 対応する
- 根拠: コメントだけでは実装変更 (誤って stamp を追加する等) を機械的に検出できない。
  回帰テストとして固定すれば、誤用実装が入った時点で fail する。
- 対応内容: 施策1のテスト計画に 4 本目
  「GET /user/confirm-password の救済 redirect は再認証の stamp をしない」を追加。
  `assertSessionMissing('auth.password_confirmed_at')` と
  `assertSessionMissing('recent_auth_at')` の両方を固定し、
  Fortify 互換 stamp と recent-auth 鮮度 stamp のどちらの誤付与も検出する。
  配置は同じ `tests/Feature/Auth/RecentAuthTest.php` の救済 redirect ブロック
  (レビュー案の FortifyResponseTest ではなく、対象挙動と同じブロックに置いて
  凝集を保つ。ファイルは異なるが趣旨は同一)。
  実コード上の注意コメント (middleware 互換を提供しない旨) は設計どおり維持。

## [Suggestion] 各種 (fail-first 方針・型注釈・影響範囲評価は現状維持で可)
- 判断: 見送る (現状維持が提案内容のため変更なし)
- 根拠: いずれも「修正案: なし」の肯定的評価。
- 対応内容: なし。
