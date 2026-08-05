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
composer test:browser                  # 全 Browser テスト × 全レーン (既定 --processes=1 直列)
composer test:browser -- --filter=...  # pest 引数の追加 (両レーンへ渡る)
BROWSER_TEST_PROCESSES=3 composer test:browser  # 並列数の上書き
BROWSER_TEST_LANES=webkit composer test:browser # レーン限定 (chromium / webkit)
```

`composer test:browser` は `scripts/run-browser-test.sh` 経由で
`vendor/bin/pest -c phpunit.browser.xml` を呼ぶ。排他は **グローバルテストロック**
(`scripts/global-test-lock.sh` / `/tmp/global-test-lane-<uid>.d/lock`) に一本化されており、
`composer test` / `pnpm test` / `pnpm test:packages` を含む全テストレーンと
**同一 UID・同一マシン単位**で相互排他する (worktree をまたぐ)。旧 worktree-local な
flock は cross-worktree の相互破壊を防げないため廃止した。先行レーンがいる場合は
**待つ** (旧実装は待たずに即エラー終了していた)。並列数を未指定 (= nproc) に
すると同時起動で環境がハングし得るため既定 1 に固定している。

Browser lane は起動時に bug-hunt 環境のポート (`127.0.0.1:8010..8018`) を
best-effort で覗き、listen していれば **ロックを取る前に** fail-fast する。
bug-hunt はロック規約に参加しない (意図的に隔離された 8 並列基盤) ため、
非干渉は保証しない — TOCTOU のある guard であり、失敗モードが偽赤に留まる範囲で受容している。

### ブラウザレーン (Chromium + WebKit)

`composer test:browser` は **Chromium レーン → WebKit レーンの順で 2 回** pest を実行し、
**どちらかが失敗したら非ゼロで終わる** (先頭レーンの失敗で後続を飛ばさない)。
pest-plugin-browser の `--browser chrome` / `--browser safari` (= Playwright webkit) に対応する。

WebKit レーンは飾りではなく、iOS Safari (撮影 PWA の主戦場) に最も近い engine での回帰である。
**実行時間を理由に WebKit レーンを落とさないこと**。

ただし **bfcache 復元そのものはどちらのレーンでも再現できない** (実測):
Playwright は自動化インスペクタを接続した状態でブラウザを起動するため、
`no-store` の無い公開ページ間ですら「戻る」で復元されない。
そのため `tests/Browser/AuthenticatedPageBfcacheTest.php` のシナリオ 2〜4 は
**毎回ハーネスの再現能力を実測して skip** する (skip は合格ではなく「担保されていない」の表明)。
再現できる環境では `pageshow.persisted === true` を観測できない限り**失敗する**
正のコントロールが効く。保証範囲の全体像は `docs/supported-browsers.md`。

**一方 Inertia SPA のクライアント履歴復元 (`popstate`) は両レーンで再現できる。**
これは bfcache とは無関係の Inertia 内部機構 (history 暗号化 + `clearHistory`) であり、
ブラウザの page cache を必要としないためである。
`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` は
**skip 判定を持たない恒久回帰**で、ログアウト後の「戻る」で PII が一度も DOM に出現せず
`/login` に倒れることを Chromium / WebKit の両レーンで固定する
(空振り防止の正のコントロール: history state が `ArrayBuffer` であること /
一連の操作で JS 実行コンテキストが生存していること)。
bfcache 側 (`AuthenticatedPageBfcacheTest`) と同じファイルに混ぜないのは、
**再現可否が正反対**で「担保されていない」ことの表明が薄まるため。

pest 終了後に orphan 化した `playwright run-server` (node) はスクリプトが実行前後に掃除する。

### CI での実行

`.github/workflows/ci.yml` の `browser-tests` job が、**Chromium / WebKit の 2 レーンをそのまま**
実行する (レーン限定も並列度上書きもしない)。job は postgres service +
`pnpm build` + `pnpm exec playwright install --with-deps chromium webkit` を前提として
`composer test:browser` を呼ぶ。CI 専用の起動経路は作らない (T099: CI が検証するものと
開発者が走らせるものを同一に保つ)。

workflow 側で `BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` を設定する退行は
`tests/js/architecture/ci-workflow-inventory.test.ts` が、スクリプト側の契約は
`scripts/run-browser-test.contract.test.ts` が deny-by-default で止める。

### 前提

- **DB は Feature lane と同じ worktree 固有 pgsql テスト DB** (`<slug>_test_<worktree-hash>`)。
  `scripts/ci/ensure-test-db.php` が冪等に作成し、`tests/bootstrap.php` の単一点ガードが
  dev DB への接続を Laravel boot 前に fail-closed で拒否する (phpunit.xml と同一機構)。
- ブラウザは Playwright が独自 DL する: **`pnpm exec playwright install chromium webkit`**。
  system chromedriver は不要。
  **WebKit は Linux で共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を要求する**ため、
  devcontainer では **`sudo pnpm exec playwright install-deps webkit`**
  (または `playwright install --with-deps webkit`) を一度実行する。未導入だと WebKit レーンが
  "Host system is missing dependencies to run browsers" で全 fail する。
- 実ブラウザは `public/build` のビルド済アセットを読むため、UI 変更後は
  **`pnpm build` を先に実行する**こと (`withoutVite()` は Browser lane に適用されない)。

## テストの書き方

`tests/Browser/` 配下に置く。suite の配線は `tests/Pest.php` の Browser lane
(TestCase + RefreshDatabase + StrayLlmCallGuard + CannedPromptFake) と
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
2. **CannedPromptFake** (`app/Services/AI/Testing/`): `Prompt` 実行を SystemMessage の役割文
   (signature) 単位の決定論 canned response に差し替える (sequence 枯渇しない無限供給)。
   `CannedPromptFakeRegistrar` が `Prompt::installFake()` で beforeEach ごとにインストールする。
   この canned 機構は bughunt 実行時 (`FakeExternalsServiceProvider::boot`) とも共有される。

さらに `phpunit.browser.xml` が LLM provider API キーをダミー値で `<server force>` する
(guard が万一無効化された場合の最終防壁。phpunit.xml と同じ 3 プロバイダ)。

### canned response の追加

新しい Prompt を Browser テストから呼ぶ場合、
`app/Services/AI/Testing/CannedPromptResponses.php` の `map()` に
「system_prompt 固有の一意句 (signature) => canned response」を 1 行追加する。
どの signature にも一致しない (0 件) / 複数一致 (2 件以上) の Prompt から呼ばれると即
`RuntimeException` で fail-fast する (silent green 防止)。

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
- **他のテストレーンと同時実行できない**: 仕様 (グローバルテストロックで
  同一 UID・同一マシン単位に直列化している)。**エラーにはならず待つ**ので、
  待機の heartbeat が出ている間はそのまま待てばよい。
- **bug-hunt が走行中で起動できない**: `scripts/bug-hunt-shard.sh teardown` で
  bug-hunt 環境を落としてから再実行する。

## グローバルテストロックの手動復旧 runbook

排他の正本は `flock` **一点**であり、ロックは OS がプロセス消滅時に必ず解放する。
したがって「ロックが取れないまま永久に詰まる」ことは、保持者が実際に生きている場合しか起きない。

- **誰が握っているか調べる**: `cat /tmp/global-test-lane-$(id -u).d/owner`
  (sidecar。1 行目 = nonce、以降 `pid=` / `lane=` / `worktree=` / `since=` の key=value)。
  待機中の heartbeat は 30 秒ごとにこの内容を stderr へ出す。
- **保持者を止める**: 上記 `pid=` のプロセスへ `kill -TERM <pid>`。ライブラリが
  専用プロセスグループへシグナルを転送し、猶予 30 秒を過ぎたら SIGKILL する。
  **グループが空になるまでロックは解放されない**(残党と次のレーンを併走させないため)。
  空にならない場合は `still holding the lock: ... has survivors after SIGKILL` の警告に
  残存 pid が出るので、それを調べる (SIGKILL を生き延びるのは stuck IO = D state だけ)。
- **sidecar が残っているが誰も走っていない**: SIGKILL / クラッシュで trap が走らなかった場合に起きる。
  **何もしなくてよい** — sidecar は排他の正本ではなく、次の取得者がアトミックに上書きする。
  手で消す必要はない (消しても害はない)。
- **ロックファイルを消さない**: `lock` を `rm` しても排他は直らない (既存の保持者は
  inode 側のロックを持ち続ける)。保持者プロセスを止めるのが唯一の正しい手順。
- **並行挙動を疑うとき**: `bash scripts/verify-global-test-lock.sh` (実ロックには触れない。
  C01〜C24 の並行挙動を実プロセスで検証する)。
