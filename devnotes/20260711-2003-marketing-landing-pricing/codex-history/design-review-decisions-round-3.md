# 対応マトリクス: design-review Round 3（APPROVED）

Round 3 で全施策 APPROVE・全体判定 APPROVED。残 Critical / Warning なし。追加対応事項なし。

## 最終確認（使命・禁止事項チェック / app-design 2-5）

- 使命寄与: LP は North Star（SOP 起点・思考ゼロ・編集ゼロ）の獲得入口、料金表は転換、リチャージは analyze/render の燃料補給でマニュアル作成フローを課金で止めない（継続）— 全施策が使命に寄与。
- 禁止事項: `response()->json()` 直書きなし（Inertia + back）、`redirect()->intended()` 不使用、disabled 不使用（role-aware 案内 + 押下時エラー）、テスト計画は全施策に付随（冪等・fail-closed・認可・保護キー・描画）、PHPStan lv10 の shape/Assert 方針を各施策に明記。
- セキュリティ不変条件: tenant キー不信（payload は count/attempt_token のみ・metadata org_ref は照合専用）、cross-org 不可（current org 解決 + re-read の org ガード）、課金冪等（webhook 冪等マシン + retryable failure + 台帳 UNIQUE + pending dedup）。
