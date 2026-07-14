# 対応マトリクス: design-review Round 2

Round 2 判定: CHANGES_REQUESTED（S3/S4 に Warning 各 1、S1/S2/S5 APPROVE）。両 Warning を対応する。

## [Warning] S3: `freshRecentAuthSession()` の定義場所が不明（ロード順依存 / 再宣言リスク）
- 判断: 対応する
- 根拠: 指摘は正当。テストファイルに関数宣言すると、2 ファイルから使う場合ロード順依存（片方定義）か再宣言衝突（両方定義）になる。`tests/Pest.php` は既にグローバルなテストヘルパ関数（`createOrganizationWithOwner` / `attachOrganizationMember` 等）を一度だけ定義する正規の場所。
- 対応内容: S3 のヘルパ配置を **`tests/Pest.php` に一度だけ定義**へ確定（`function freshRecentAuthSession(): array { return ['recent_auth_at' => now()->timestamp]; }`）。新規 `TwoFactorDisableStepUpTest` と `TwoFactorEnforcementTest.php` L315-324 の双方から参照する。

## [Warning] S4: 再認証ダイアログをキャンセルした際に `pendingAction`（destructive closure）が破棄されない
- 判断: 対応する
- 根拠: 指摘は正当。`RecentAuthModal` は `open = $bindable` + `onConfirmed` のみで、キャンセル/close 用コールバックを持たない。現状 `pendingAction` は `resumePendingAction()`（onConfirmed 経由）でのみ null 化されるため、ユーザーが再認証をキャンセルすると disable の closure が残存する。次回別操作で `guardWithRecentAuth` が上書きするため即事故にはならないが、destructive action の残置は defense-in-depth 上望ましくない（regenerate 側も同じ潜在挙動）。
- 対応内容: Security.svelte に、再認証モーダルが閉じたら `pendingAction` を破棄する `$effect` を追加する（disable/regenerate 共通の shared state に一括適用）:
  ```svelte
  $effect(() => {
      // 再認証モーダルが閉じたら pending の destructive closure を破棄 (キャンセル時の残置防止)。
      // onConfirmed 経由の resume は action をローカルへ退避してから pendingAction を null 化するため、
      // 本 effect と二重でも安全 (resume が先に action を握っている)。
      if (!recentAuthOpen) {
          pendingAction = null;
      }
  });
  ```
  加えて S4 の確認項目・（任意）component テストに「キャンセル後に pending が残らない（再度別操作の前に自動実行されない）」を追加。resume と本 effect の順序安全性（resume がローカル退避してから null 化）を設計に明記。
