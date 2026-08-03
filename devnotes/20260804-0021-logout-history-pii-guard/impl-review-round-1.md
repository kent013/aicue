提供された `git diff` と実測ログを前提に、設計7施策との一致・正確性・テスト不変条件の観点でレビューしました。

**ファイル別判定**
- `bootstrap/app.php:93`
  - 判定: ✅ 実装妥当
  - `EncryptHistory` の web group 追加は施策1に一致。経路A/B/Cの責務整理も過不足なし。
- `app/Http/Responses/Fortify/LogoutResponse.php:1`
  - 判定: ✅ 実装妥当
  - `Inertia::clearHistory()` 無条件実行＋`route('home')` 固定は、設計上の保証条件（「次の Inertia page 適用」）に整合。
  - `response()->json()` 直書きなし、Fortify既定204分岐の互換維持もOK。
- `app/Providers/FortifyServiceProvider.php:103`
  - 判定: ✅ 実装妥当
  - `LogoutResponseContract` の bind 追加は正しい配線。
- `tests/Feature/Security/InertiaHistoryGuardTest.php:1`
  - 判定: ✅ 実装妥当
  - `encryptHistory` / `clearHistory` / one-shot消費 / JSON 204分岐まで契約テスト化できており、空振りgreen回避もできています。
- `tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php:1`
  - 判定: ✅ 実装妥当
  - F-4-01再現を直接固定できており、正のコントロール（暗号化実効・same-document継続）も有効です。
  - [Suggestion] `click('メンバー')` / `click('通知を確認')` は文言変更に弱いので、可能なら `@testid` 優先に寄せると回帰耐性が上がります。
- `tests/js/architecture/logout-call-site-inventory.test.ts:1`
  - 判定: ✅ 実装妥当
  - deny-by-default inventory と `router.post('/logout')` 強制は施策7どおり。
  - [Suggestion] `fs.readdir(..., { recursive: true, withFileTypes: true })` の `parentPath` 依存は Node差異で壊れやすいことがあるため、既存テストの安定実装へ揃えるとより安全です。
- `resources/js/lib/bfcache-guard.ts:29`
  - 判定: ✅ 実装妥当
  - 挙動変更なしで経路B/C分担を明記できており、設計意図に一致。
- `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php:21`
  - 判定: ✅ 実装妥当
  - コメント補強として適切。保証主語の過剰拡大なし。
- `docs/supported-browsers.md:1`
  - 判定: ✅ 実装妥当
  - 3経路×3枚の網、保証条件、残存リスクの切り分けが明確で契約誤記を避けられています。
- `docs/testing-browser.md:49`
  - 判定: ✅ 実装妥当
  - bfcache(B) と Inertia popstate(C) の再現性差分を正しく分離。
- `AGENTS.md:198`
  - 判定: ✅ 実装妥当
  - ドメイン規約#3が実装・テスト名まで対応づけられており運用可能。

**指摘まとめ**
- [Critical] なし
- [Warning] なし
- [Suggestion] 2件（Browserテストの文言依存、ArchitectureテストのNode互換性耐性）

**全体判定**
- **APPROVED**