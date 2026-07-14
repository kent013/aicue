# 対応マトリクス: design-review Round 1

全体判定 **APPROVED**。全施策 APPROVE。Warning 2 件を設計へ取り込んで解消する。

## [Warning] S3: `withSession(['recent_auth_at' => time()])` の時刻境界での不安定余地
- 判断: 対応する（明確化 + 共有ヘルパ化）
- 根拠: 実運用上、`RecentAuthWindow` の窓は 900 秒で、`time()` を入れた瞬間の elapsed は 0〜1 秒 = 窓の 0.1% 未満。並列テストでも境界不安定は現実的に起きない（既存 `TwoFactorRecoveryCodesStepUpTest` が同一パターンで安定稼働中）。ただし指摘のとおり「確実に fresh」を意図が読める形にするため、fresh 値をヘルパ関数へ集約し全テストで再利用する。
- 対応内容: S3 に「fresh 値は `now()->timestamp` を返す `freshRecentAuthSession()` ヘルパ（テストファイル冒頭 or 既存テスト helper）へ集約し、`->withSession(freshRecentAuthSession())` で統一注入」を明記。窓が 900 秒で境界非依存である旨も添える。

## [Warning] S4: 確認ダイアログ + recent-auth ダイアログの二重モーダルでフォーカス遷移が崩れる可能性
- 判断: 対応する
- 根拠: disable は無効化確認ダイアログ（`disableDialogOpen`）を経て `disableTwoFactor()` を呼ぶため、stale 時に recent-auth ダイアログが重なる。regenerate 側は `regenerateDialogOpen` + `recentAuthOpen` で既に共存するが、focus trap の優先順位を暗黙依存にしない。
- 対応内容: S4 で「stale 検知時に先に `disableDialogOpen = false` して確認ダイアログを閉じてから recent-auth ダイアログを開く」挙動を明記（`guardWithRecentAuth` の `onStale` に相当する呼び出し前後で確認ダイアログを畳む）。加えて確認項目に「recent-auth ダイアログ表示時の focus trap が regenerate と同等であること」を追加。resume 後の再無効化は `onSuccess` で `disableDialogOpen` を閉じる既存挙動を維持。

## [Suggestion] 各種
- 判断: 取り込み済み / 任意
- 対応内容: fail-fast 運用（Architecture テスト依存）・テストファースト・多層防御は既に設計に明記済み。追加変更なし。
