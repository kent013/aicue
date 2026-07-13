**レビュー結果**

**`app/Providers/FortifyServiceProvider.php`**
- **判定**: ✅ 問題なし
- **設計一致**: `RECENT_AUTH_ROUTE_NAMES` に `two-factor.disable` を1件追加のみで、設計意図どおり最小変更。
- **正確性**: コメントで「enforced org は web group 側で 422 先行」を明記しており、順序認識が正しい。
- **PHPStan観点**: `@var list<string>` のまま維持、型劣化なし。
- **DTO/Resource観点**: 新規レスポンス直書きなし。

**`config/fortify.php`**
- **判定**: ✅ 問題なし
- **設計一致**: TODO コメントが「disable 対応済み」に追従しており整合。
- **補足**: 実装影響はなくドキュメント同期として適切。

**`resources/js/pages/Settings/Security.svelte`**
- **判定**: ✅ 問題なし
- **設計一致**: `disable` を `withRecentAuth` でラップし、stale 時に確認ダイアログを閉じる実装は設計どおり。
- **セキュリティ/正確性**:
  - `$effect` で `recentAuthOpen === false` 時に `pendingAction = null` は、キャンセル後の destructive closure 残置防止として有効。
  - `resumePendingAction` がローカル退避→null化の契約なら、race 的にも二重実行抑止の説明に整合。
- **Atomic/DS**: pages 層で既存 helper / modal 再利用のみ。新規 atom/molecule・hex 追加なし。

**`tests/Architecture/RecentAuthRouteTest.php`**
- **判定**: ✅ 問題なし
- **テスト網羅**: allowlist へ `two-factor.disable` 追加で「付与漏れ検知」の目的を満たす。

**`tests/Feature/Auth/TwoFactorDisableStepUpTest.php`**
- **判定**: ✅ 問題なし
- **テスト網羅**:
  - stale XHR: `409 + recent_auth_required`
  - stale Inertia: `409`
  - stale 通常: `302 -> recent-auth.confirm`
  - fresh: disable 成功
  という必要分岐を押さえている。
- **正確性**: 「2FAを無効化しない/する」の事後状態検証あり。

**`tests/Feature/Organizations/TwoFactorEnforcementTest.php`**
- **判定**: ✅ 問題なし
- **設計一致**: 非 enforced org の self-disable ケースのみ fresh セッション付与。enforced 側不変方針に合致。

**`tests/Pest.php`**
- **判定**: ✅ 問題なし
- **PHPStan観点**: `@return array{recent_auth_at: int}` が明示され、共有ヘルパ化も妥当。

**`tests/js/pages/SettingsSecurity.test.ts`**
- **判定**: ✅ 問題なし
- **テスト網羅**:
  - fresh: DELETE 1回
  - stale: recent-auth modal 表示 + confirm dialog close（重複モーダル回避）
  - stale→cancel: pending破棄後、別操作resumeでDELETE不発
  - stale→success: pending DELETE 1回再開
  を満たしており、設計要求を十分カバー。

**指摘分類**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**: なし（現状で要件に対して十分）

**全体判定**
- **APPROVED**