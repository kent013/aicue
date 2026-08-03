## Critical
- なし

## Warning
- `app/Providers/FortifyServiceProvider.php:94`  
  `getByName($name)?->middleware('recent-auth')` は「既存 middleware 配列へ追加」なので、将来この `booted` コールバックが複数回評価される実行コンテキスト（長寿命プロセスでの再 boot 相当）だと `recent-auth` が重複付与される可能性があります。  
  失敗シナリオ: 同一リクエストで middleware が二重評価され、`recent-auth` 実装次第で余計なセッション更新やログノイズが増える。  
  （現状の通常HTTP運用では実害は低く、ブロッカーではありません。`array_unique` 相当のガードや「未付与時のみ追加」にしておくと堅いです）

## Suggestion
- `resources/js/pages/Settings/Security.svelte:46` / `resources/js/pages/Organizations/Settings.svelte` 側の recent-auth ガード  
  `guardWithRecentAuth` は `withRecentAuth` の失敗（ネットワーク例外など）時に UI へ明示エラーを出していません。  
  失敗シナリオ: `/recent-auth/status` が一時失敗したとき、ユーザー視点では「押しても何も起きない」に見える。  
  提案: `withRecentAuth` が reject した場合の共通トースト（例: 「再認証状態を確認できませんでした」）を追加。

- `tests/js/pages/SettingsSecurity.test.ts:150` 付近  
  precheck 経由は `toHaveBeenCalledWith("/recent-auth/status", expect.anything())` で固定できていますが、「`GET /user/two-factor-recovery-codes` が stale では呼ばれない」の確認は最後の describe のみです。  
  失敗シナリオ: 将来 `regenerate` 経路で stale 時に誤って GET を叩く回帰が入っても、このブロック単体では検出漏れ。  
  提案: 再生成 stale ケースにも「requestedUrls に `/user/two-factor-recovery-codes` が無い」断言を追加。

- `resources/js/pages/Organizations/Settings.svelte:100`  
  `NO_TRANSFER_CANDIDATES` と実際のエラー文をテンプレ連結で組み立てており、将来文言変更でテスト期待値とズレやすいです。  
  失敗シナリオ: i18n/文言修正時に「案内文とエラー文の揺れを防ぐ」という意図が崩れる。  
  提案: エラー文も定数化（`NO_TRANSFER_CANDIDATES_ERROR`）して、UI/テストで同一参照に寄せる。

全体として、今回の2件（F-10/F-12）は**不変条件を実際に固定できるテスト付きで改善できており、マージ可能**です。特に `TwoFactorRecoveryCodesStepUpTest` の stale/fresh 両面検証と、`RecentAuthRouteTest` allowlist 追加の組み合わせは有効です。