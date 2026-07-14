# 詳細設計レビュー Round 2

Round 1 は全体 APPROVED（全施策 APPROVE）、Critical なし、Warning 2 件でした。両 Warning を設計へ取り込みましたので確認をお願いします。

## [Warning] S3: fresh session の時刻境界での不安定余地 → ヘルパ集約 + 境界非依存を明記

窓は `config('auth.recent_auth_timeout')`（既定 900 秒）で、`now()->timestamp` を入れた瞬間の elapsed は 0〜1 秒 = 窓の 0.1% 未満のため境界不安定は実運用上起きない（既存 `TwoFactorRecoveryCodesStepUpTest` が同一パターンで安定）。意図を明示するため fresh 値をヘルパへ集約:

```php
/** recent-auth を確実に満たす fresh session (窓 900s に対し elapsed≈0)。 */
function freshRecentAuthSession(): array
{
    return ['recent_auth_at' => now()->timestamp];
}
```

新規 `TwoFactorDisableStepUpTest` と、更新する `TwoFactorEnforcementTest.php` L315-324 の双方で `->withSession(freshRecentAuthSession())` に統一した。

## [Warning] S4: 二重モーダル（確認ダイアログ + recent-auth ダイアログ）の focus 崩れ → stale 時に確認ダイアログを先に閉じる

`disableTwoFactor()` を、共有 `guardWithRecentAuth` を非破壊のまま、ローカルに `withRecentAuth` を呼ぶ形へ変更し、`onStale` で `disableDialogOpen = false`（確認ダイアログを畳む）してから `recentAuthOpen = true` を開くようにした:

```svelte
function disableTwoFactor(): void {
    const action = () => {
        router.delete("/user/two-factor-authentication", {
            preserveScroll: true,
            onStart: () => { disabling = true; },
            onSuccess: () => { disableDialogOpen = false; confirming = false; qrSvg = null; recoveryCodes = []; },
            onFinish: () => { disabling = false; },
        });
    };
    void withRecentAuth({
        onFresh: action,
        onStale: (status) => {
            disableDialogOpen = false; // 二重モーダル回避: 確認ダイアログを閉じてから
            recentAuthStatus = status;
            pendingAction = action;
            recentAuthOpen = true;     // 再認証ダイアログを開く
        },
    });
}
```

- 共有 `guardWithRecentAuth` の挙動は変えない（regenerate は確認ダイアログを stale 時に閉じない挙動で成立しているため、helper 改修による波及を避けた）。
- 確認項目に「recent-auth ダイアログ表示時の focus trap が regenerate と同等」を追加。
- resume（再認証成功後）は `resumePendingAction()` が `action` を再実行 → disable → `onSuccess`。最終ゲートは server の recent-auth middleware。

---

上記で Round 1 の Warning 2 件は解消されたと考えます。残る Critical/Warning があれば指摘してください。なければ APPROVED をお願いします。

（設計全文は Round 1 から上記 2 点のみ差分。必要なら参照してください。）
