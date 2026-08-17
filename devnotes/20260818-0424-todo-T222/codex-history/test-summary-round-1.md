## テストファースト (実装前の fail 確認)

### tests/Architecture/FlashNotificationRelayDriftTest.php (実装前)
tests=11 passed=9 errors=2
- 「Inertia が共有する flash キー集合が … 一致する」→ Class "App\Support\Http\FlashNotificationRelay" not found
- 「flash 書き手のキーはすべて …」→ 同上
- 走査器の自己検証 (正例 3 / 負例 5) と allowlist 件数の 9 本は実装前から緑 (走査器は SoT に依存しないため)

### tests/js/architecture/flash-keys-sync.test.ts (実装前)
FAIL — ENOENT: app/Support/Http/FlashNotificationRelay.php が無い (0 件一致の偽の緑にならず fail に倒れた)

### tests/Feature/Inertia/FlashNotificationRelayBounceTest.php (実装前 = reflash() のまま)
tests=5 passed=0 failed=5
- 課金ゲートの跳ね返り → `Session has unexpected key [new_api_key]` (= reflash() が API キー平文を延命していた)
- 凍結の跳ね返り → 同上
- 検証エラー (default bag) → `Session has unexpected key [errors]`
- 名前付き error bag → `Session has unexpected key [errors]`
- errors に ViewErrorBag でない値 → 当初は HTTP 経由で書いていたが、Laravel の Store::save() が
  保存前に errors を ViewErrorBag として直列化する (prepareErrorBagForSerialization) ため
  **要求境界を跨げず 500** になった。よってこの 1 本だけ relayTo() を直に呼ぶ形へ書き換えた

## 実装後の検証コマンド

| コマンド | 結果 |
|---|---|
| `composer phpstan` (level 10) | OK (989 ファイル・エラー 0) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | OK |
| `pnpm typecheck` | OK |
| `pnpm build` | OK |
| `pnpm typecheck:packages` / `pnpm build:packages` | OK |
| `composer test` (全数) | 実行中 (ホスト全体のグローバルテストロック待ち) |
| `pnpm test` (全数) | 実行待ち |
