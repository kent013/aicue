# 詳細設計レビュー Round 3

Round 2 で残った Warning 2 件（S3 ヘルパ配置 / S4 キャンセル時の pending 破棄）を対応しました。

## [Warning] S3: `freshRecentAuthSession()` の定義場所 → `tests/Pest.php` に確定
テストファイル内宣言（ロード順依存 / 再宣言衝突）を避け、既存グローバルヘルパ群（`createOrganizationWithOwner` 等）と同じ **`tests/Pest.php` に一度だけ**定義する:

```php
// tests/Pest.php
function freshRecentAuthSession(): array
{
    return ['recent_auth_at' => now()->timestamp];
}
```

新規 `TwoFactorDisableStepUpTest` と `TwoFactorEnforcementTest.php` L315-324 の双方が参照する。

## [Warning] S4: 再認証キャンセル時に destructive closure (`pendingAction`) が残置 → `$effect` で破棄
`RecentAuthModal` は `open = $bindable` + `onConfirmed` のみでキャンセル callback がないため、再認証モーダルが閉じたら pending を破棄する `$effect` を追加（disable/regenerate 共通 shared state に一括適用）:

```svelte
$effect(() => {
    // 再認証モーダルが閉じたら pending の destructive closure を破棄 (キャンセル時の残置防止)。
    // resumePendingAction は `const a = pendingAction; pendingAction = null; a?.();` と
    // action をローカル退避してから null 化するため、本 effect と二重でも resume が先に action を握り安全。
    if (!recentAuthOpen) {
        pendingAction = null;
    }
});
```

- 順序安全性を上記コメントで明記。初期マウント（open=false）でも走るが pendingAction は null で無害、open=true では分岐に入らない。
- 確認項目に「キャンセル後に pending が残らず自動再開しない」を追加。regenerate 側の潜在残置も同時に解消。

---

上記で Round 2 の Warning 2 件は解消されたと考えます。残る Critical/Warning があれば指摘してください。なければ全体 APPROVED をお願いします。
