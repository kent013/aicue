# Browser (E2E) テスト — pest-plugin-browser

E2E (Browser) テストに **`pestphp/pest-plugin-browser`** (Pest 4 / Playwright / in-process サーバ) を使う。
旧 Laravel Dusk (別プロセスの `php artisan serve` を HTTP で叩く方式) は採用しない。

**本書の守備範囲**: Browser テストの規約 (セットアップ / Page Object / fake / dev-DB 保護) を
記述する。個別テストファイル (`tests/Browser/*Test.php`) の一覧や各テストの責務は管理対象外で、
実ファイルの存在と PHPDoc を一次ソースとする。

## なぜ in-process か

pest-plugin-browser はテストプロセス**自身の** HttpKernel を Playwright (chromium) で叩く。
別プロセスのサーバを持たないため、Feature テストと同じ仕組みがそのまま効く:

- `RefreshDatabase` のトランザクションがブラウザリクエストにも効く
- `$this->actingAs($user)` で認証できる (Dusk の `loginAs` / テスト専用ログイン route は不要)
- fake は **テストプロセス内で** container bind / `Prompt::fake` / `Http::fake` すれば effective
  (プロセス境界越しの ServiceProvider bind が不要)

## 実行

```bash
composer test:browser                  # 全 Browser テスト (既定 --processes=1 直列)
composer test:browser -- --filter=...  # pest 引数の追加
BROWSER_TEST_PROCESSES=3 composer test:browser  # 並列数の上書き
```

`composer test:browser` は `scripts/run-browser-test.sh` 経由で
`vendor/bin/pest -c phpunit.browser.xml` を呼ぶ。`composer test` (Feature pgsql lane) と
同一 lock file (`storage/framework/testing/test.lock`) の flock で相互排他し、
共有する pgsql テスト DB / chromium 資源の奪い合いを防ぐ。並列数を未指定 (= nproc) に
すると chromium の同時起動で環境がハングし得るため既定 1 に固定している。

pest 終了後に orphan 化した `playwright run-server` (node) はスクリプトが実行前後に掃除する。

### 前提

- **DB は Feature lane と同じ worktree 固有 pgsql テスト DB** (`<slug>_test_<worktree-hash>`)。
  `scripts/ci/ensure-test-db.php` が冪等に作成し、`tests/bootstrap.php` の単一点ガードが
  dev DB への接続を Laravel boot 前に fail-closed で拒否する (phpunit.xml と同一機構)。
- chromium は Playwright が独自 DL する: `pnpm exec playwright install chromium`
  (Linux で依存ライブラリも入れる場合は `--with-deps`)。system chromedriver は不要。
- 実ブラウザは `public/build` のビルド済アセットを読むため、UI 変更後は
  **`pnpm build` を先に実行する**こと (`withoutVite()` は Browser lane に適用されない)。

## テストの書き方

`tests/Browser/` 配下に置く。suite の配線は `tests/Pest.php` の Browser lane
(TestCase + RefreshDatabase + StrayLlmCallGuard + BrowserPromptFake) と
`phpunit.browser.xml` が担う。既定 `phpunit.xml` の testsuite には含まれないため、
`composer test` からは実行されない。

```php
test('ゲストがトップページを表示できる', function (): void {
    visit('/')
        ->assertPathIs('/')
        ->assertNoJavaScriptErrors();
});
```

### Page Object (selector 定数 final class)

テストが増えてきたら selector / URL は `tests/Browser/Pages/` の final class に集約する。
pest-plugin-browser は `@foo` を `[data-testid="foo"]` にネイティブ解決するが、selector は
`public const string` 定数で持ち、操作 method を `static` で提供する final class に統一する
(継承による振る舞い共有はしない)。

```php
final class SettingsPage
{
    public const string URL = '/settings';
    public const string DELETE_SUBMIT = '[data-testid="delete-account-submit"]';
}
```

- 遷移後の安定化は plugin の assertion auto-retry (`assertVisible` / `assertPathIs`) が wait を兼ねる。
- 安定セレクタには DB id ベースの `data-testid` を採用する
  (並び順 index / button text 検索の fragile な経路を避ける)。例: `item-card-{item.id}`。

## LLM fake (in-process)

Browser lane では LLM 呼び出しを二層で遮断する (`tests/Pest.php` の Browser lane が配線):

1. **StrayLlmCallGuard** (Feature/Unit と共通): 未 fake の LLM 呼び出しは accumulator に
   記録され afterEach で fail する。
2. **BrowserPromptFake** (`app/Services/AI/Testing/`): `Prompt` 実行を prompt class 単位の
   決定論 canned response に差し替える (sequence 枯渇しない無限供給)。
   `BrowserPromptFakeRegistrar` が `Prompt::installFake()` で beforeEach ごとにインストールする。

さらに `phpunit.browser.xml` が LLM provider API キーをダミー値で `<server force>` する
(guard が万一無効化された場合の最終防壁。phpunit.xml と同じ 3 プロバイダ)。

### canned response の追加

新しい Prompt を Browser テストから呼ぶ場合、
`app/Services/AI/Testing/BrowserCannedResponses.php` の `map()` に 1 行追加する。
未登録の Prompt から呼ばれると即 `RuntimeException` で fail-fast する (silent green 防止)。

キーの注意: `Prompt::load()` を使う factory (例: `App\Prompts\ExampleSummaryPrompt`) は
generic な `TextPrompt` を実行するため、記録される prompt class は `TextPrompt::class` になる。
prompt 単位で応答を分けたい場合は専用サブクラス (`class FooPrompt extends TextPrompt`) を
定義し、そのクラス名で登録する。

失敗系 (LLM schema 違反、Prism タイムアウト等) は Browser ではなく Feature テストで
`Prism::fake()` に fail response を仕込む方が確実かつ高速。

## 役割分担

| 層 | 責務 |
|----|------|
| **Browser (pest-plugin-browser)** | UI / ユーザーフロー回帰 |
| **Feature** | API 契約 / DTO / JsonResource / 認可ポリシー / ジョブ dispatch 検証 (`Queue::fake()`) |
| **Unit** | 純粋ロジック |

Browser の役割は UI / ユーザーフロー回帰に限定する。

## トラブルシュート

- **白画面 / dev アセットを読む**: `composer dev` の vite dev server が `public/hot` を
  出していると実ブラウザが dev アセットを読む。Browser lane の beforeEach が hot file を
  無効化するが、`pnpm build` 済の `public/build` があることを確認する。
- **`Could not find a response for the request` / canned 未登録例外**: 上記
  「canned response の追加」を参照。
- **orphan playwright run-server**: `scripts/run-browser-test.sh` が pest 終了後に掃除するが、
  残った場合は `pkill -f 'playwright/cli.js run-server'`。
- **`composer test` と同時実行できない**: 仕様 (同一 pgsql テスト DB を共有するため
  test.lock で排他)。先行する test の終了を待つ。
