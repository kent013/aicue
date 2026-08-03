# 対応マトリクス: design-review Round 7 (APPROVED)

全 5 施策 (A/B/C/D/E) が **APPROVE**、全体判定 **APPROVED**。Critical / Warning はゼロ。

Codex 評: 「例外境界、remote 真実源、stale 判定、grace period、DTO/TS 整合、Controller の
エラー変換、テスト計画まで一貫している。実装へ進める状態」。

設計変更なし。

## 最終確認 (app-design Phase 2-5)

- **使命 (North Star) への寄与**: quota (プロジェクト数 / 保存容量) を上げる導線が実在するように
  なり、現場が作れるマニュアル動画の量的上限を利用者自身で外せる。アプリ自身の文言
  (「プランのアップグレードをご検討ください」「プラン変更をご利用ください」) が指す先ができる。
- **禁止事項**:
  - #1 テストなし完了報告 → 全施策にテスト計画 (層 0〜3 + endpoint + page + JS) を明記
  - #2 PHPStan widen なし (各施策に適合チェック節)
  - #4 `response()->json()` 不使用 (Inertia / redirect + flash)
  - #7 `redirect()->intended()` 不使用 (`redirect()->route('billing.index')`)
  - #8 CTA を disabled にしない (`canSwitchTo` / `PlanCard` の契約を変更しない)
- **セキュリティ不変条件**: #1 (`ProhibitsProtectedKeys` + 状態キーを payload から受けない) /
  #3 (current-org スコープ = route parameter なし) / #7 (決定的 idempotency key + remote 照合の
  二層 / webhook 単一 writer 維持) をいずれも設計に反映済み。
- **コーディングルール**: PHPStan level 10 / Pest (RefreshDatabase グローバル) / DTO / Factory・
  既存ヘルパ利用 / Pint・ESLint を各施策に明記。
