# 対応マトリクス: design-review Round 2

全体判定: APPROVED（施策1・施策2 とも APPROVE、Round 1 Warning は解消済みと明言）。

指摘事項なし。追加対応なしで詳細設計を確定する。

## 最終確認（app-design Phase 2-5 使命・禁止事項チェック）
- 使命への寄与: 機微操作の再認証導線から 500 詰み画面を排除（現場作業者が詰まない）。
- 禁止事項: テストファースト（fail-first 4本）／PHPStan widen なし／`response()->json()`
  直書きなし／`redirect()->intended()` は操作系 POST で不使用（GET view の 302 のみ）。
- コーディングルール: Pest + Factory + グローバル RefreshDatabase、closure 戻り値型明示。
