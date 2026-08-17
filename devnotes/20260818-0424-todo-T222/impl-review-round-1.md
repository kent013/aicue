## ファイル別判定

### `app/Http/Middleware/EnsureAccountNotPendingDeletion.php`

判定: 問題なし。

`reflash()` を通知限定の中継へ置換しており、`new_api_key` を延命しない設計と一致しています。Controller/DTO/認可境界にも影響しません。

### `app/Http/Middleware/HandleInertiaRequests.php`

判定: 問題なし。

共有キーを `FlashNotificationRelay::NOTIFICATION_KEYS` から導出しつつ、`visitKey` を中継対象外として維持しています。Inertia 共有 prop なので JsonResource 不使用も妥当です。型の widen もありません。

### `app/Http/Middleware/RequireActiveSubscription.php`

判定: 問題なし。

課金ゲートの跳ね返りで通知だけを延命する実装になっています。既存の return resolver や redirect 先にも不要な変更はありません。

### `app/Support/Http/FlashNotificationRelay.php`

判定: 問題なし。

正典どおりの実装で、以下が成立しています。

- 通知キーだけを `keep()`
- `new_api_key` を対象外に維持
- `errors` は空 allowlist により fail-closed
- 名前付き error bag は中継しない
- PHPStan 向けの型注釈は契約型の表現であり、widen/ignore ではない

JSON 応答や Prism 呼び出しもありません。

### `resources/js/lib/stores/flash-to-toast.ts`

判定: 問題なし。

`FLASH_KEYS` の export と説明追加だけで、実行時挙動、CSS、DS token、Atomic Design への影響はありません。

### `tests/Architecture/FlashNotificationRelayDriftTest.php`

判定: 問題なし。

共有側の実出力、リテラル書き手、allowlist 件数を固定しています。走査器には正例・負例があり、全件ゼロになる degenerate PASS も防げています。走査範囲の限界も正確に記述されています。

### `tests/js/architecture/flash-keys-sync.test.ts`

判定: 実装内容は問題なし。ただし実行確認が必要です。

抽出不能時に例外へ倒れ、0件同士の偽陽性を防止しています。集合比較も妥当です。

### `tests/Feature/Inertia/FlashNotificationRelayBounceTest.php`

判定: 要修正。

[Warning] 通知中継の実測が `success` だけなので、将来 `relayTo()` が例えば `keep([self::SUCCESS])` に変わっても、PHP/TS drift gateと本Featureテストがすべて通ります。「現在の実装が `NOTIFICATION_KEYS` を渡している」ことはテスト上の不変条件にはなりません。

2 middleware × 4キーに増やす必要はありません。既存の2本のE2Eは代表値のまま残し、`Store` を使った独立テストで `NOTIFICATION_KEYS` を dataset 化し、各キーについて「1 hop残る・次で失効する」を固定すれば十分です。

一方、次の観測点は適切です。

- 本物の要求境界で flash 世代を作っている
- 跳ね返り直後のsessionで `new_api_key` を検査している
- `reflash()` では実際に赤になった
- default/named/non-`ViewErrorBag` の各境界を検査している

## 全体

[Critical] 必須検証が完了していません。提示結果では次が未完了です。

- `composer test`
- `pnpm test` — 新しいTS drift gateの実装後実行を含む
- `pnpm test:packages` — 結果の記載なし

したがって、現時点では「全greenで完了」というリポジトリ規約を満たしたと判断できません。上記Featureテストの不足を閉じ、必須コマンドの完走結果を確認する必要があります。

**全体判定: CHANGES_REQUESTED**