# T129 mutation 赤化確認

詳細設計 `devnotes/20260807-1235-inertia-error-screen-contract/detailed-design.md` S6 の
mutation 表 (M1〜M17) に対する実施記録。

- 実行スクリプト: [`mutation-run.sh`](./mutation-run.sh) (このディレクトリ。一時スクリプト)
- 実行日時: 2026-08-07 (JST) / worktree `.claude/worktrees/tasks/T129`
- 実行コマンドの形: `composer test -- <テストファイル>` / `pnpm vitest run <テストファイル>`

## 復旧の担保

mutation の revert に **`git checkout` は使わない**。本タスクの変更ファイルは

- 新規ファイル (untracked) … `git checkout` が効かず mutation が残留する
- 既存ファイル (tracked) … `git checkout` すると **本タスクの変更ごと** HEAD に戻る

の 2 種類が混在するため、スクリプト冒頭で全対象ファイルを `/tmp/T129-mutation-snapshot` へ
コピーし、各 mutation の後に `cp` で丸ごと戻す方式にした。
最後に snapshot と全ファイルを `diff -q` して差分ゼロを確認している (実行結果: 差分 0 件)。
加えて実行後に `composer phpstan` = OK / `vendor/bin/pint --test` = passed を再確認した。

## M1〜M3 (恒久化済み)

目録の壊し方 (stale / 根拠 30 文字未満 / cap 超過・floor 未満) は
`tests/Architecture/InertiaErrorScreenContractTest.php` の
`負のコントロール: 壊れた目録で inventory 検出器が点灯する` が
純関数 `inertiaErrorScreenInventoryViolations()` に壊れた目録 fixture を渡して恒久固定している
(実ファイルを壊さずに mutation 相当を再現できるため、手作業の 1 回きりにしない)。

## M4〜M17 (手作業で 1 度ずつ実施)

| # | mutation の内容 | 実行したテスト | 結果 (赤化したテスト) |
|---|----------------|--------------|--------------------|
| M4 | `InertiaExceptionRenderer::passthroughReason()` の `StaleAssetVersion` 分岐を削除 | `tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php` | **6 failed** / 11 passed: `stale version の X-Inertia は差し替えない` / `version ヘッダ欠落の X-Inertia は差し替えない` / `version ヘッダが空文字の X-Inertia は差し替えない` / `現 version が空 (version resolver が null) なら差し替えない` / `version resolver が throw しても原応答が完全一致で残り、例外は report される` / `素通し理由 enum の全 case が実際に生成される (死んだ分類を作らない)` |
| M5 | `bootstrap/app.php` に 2 本目の `$exceptions->respond()` を追加 | `tests/Architecture/InertiaErrorScreenContractTest.php` / `tests/Feature/Errors/ErrorPagesTest.php` | **各 1 failed**: `例外応答の最終整形スロットを奪う登録は bootstrap/app.php の 1 箇所だけ` (2 件検出) / `it serves the operator-facing template for admin 404 over HTTP (respond callback regression guard)` (admin 分離が黙って死ぬのを振る舞い側でも検出) |
| M6 | `bootstrap/app.php` に `\Inertia\Inertia::render('Error', [])` を直書き | `tests/Architecture/InertiaErrorScreenContractTest.php` | **1 failed**: `bootstrap/ に Inertia::render を直書きしない (ページ実在 gate の網から外れるため)` |
| M7 | `resources/js/pages/Error.svelte` を削除 | `tests/Architecture/InertiaRenderPageExistsInvariantTest.php` / `tests/js/architecture/inertia-eager-error-page.test.ts` | **PHP 1 failed**: `Inertia render の literal 参照先ページが全て実在する` (`app/Exceptions/InertiaExceptionRenderer.php:125 → resources/js/pages/Error.svelte (不存在)`) / **JS 2 failed**: `eager 解決の対象は Error ページちょうど 1 件` / `Error は遅延 loader を 1 度も呼ばずに解決される` |
| M8 | `resources/js/inertia.ts` の `{ eager: true }` を外す (`EAGER_PAGES` を空に) | `tests/js/architecture/inertia-eager-error-page.test.ts` | **2 failed**: `eager 解決の対象は Error ページちょうど 1 件` / `Error は遅延 loader を 1 度も呼ばずに解決される` |
| M9 | `InertiaExceptionRenderer::render()` の try/catch を外す | `tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php` | **1 error**: `version resolver が throw しても原応答が完全一致で残り、例外は report される` (例外 `manifest が読めない` がそのまま貫通) |
| M10 | 差し替え応答の `Retry-After` ヘッダ再設定を削除 | `tests/Feature/Errors/InertiaErrorScreenTest.php` | **1 failed**: `429 は retryAfterSeconds を props に載せ Retry-After ヘッダも保持する` (`Header [Retry-After] not present`) |
| M11 | `ErrorScreenDestinations::for()` の D1 分岐を削除 | `tests/Feature/Errors/InertiaErrorScreenTest.php` / `tests/Unit/Http/ErrorScreenDestinationsTest.php` | **Unit で 1 failed**: `419 は認証状態にかかわらずログインへ倒れる (D1 が D2 より先) with data set "(true)"` (`/login` を期待して `/dashboard`)。**Feature 側は green のまま** — 詳細は下記「M11 の注記」 |
| M12 | `RetryAfterSeconds::parse()` の負数 / 非数値判定を削除 | `tests/Unit/Http/RetryAfterSecondsTest.php` / `tests/Feature/Api/ApiRetryAfterContractTest.php` | **Unit 6 failed** (`負数は解釈しない` ×2 / `HTTP-date と任意文字列は解釈しない` ×4) / **Feature 3 failed** (`Retry-After が HTTP-date のとき details を出さない` / `負数のとき details を出さない` / `ヘッダも本文と同じ解釈になる`) |
| M13 | `$authenticated` の短絡を戻す (`for($status, $request->user() !== null)` に直書き) | `tests/Feature/Errors/InertiaErrorScreenTest.php` | **1 failed**: `419 は user resolver が例外を投げても Error 画面になる (認証状態を評価しない)` (user resolver が throw して fail-safe に落ち、Error ページが返らない) |
| M14 | `catch` の `report($e)` を削除 | `tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php` | **1 failed**: `version resolver が throw しても原応答が完全一致で残り、例外は report される` (`The expected [RuntimeException] exception was not reported.`) |
| M15 | `ApiExceptionRenderer::extraHeaders()` の `Retry-After` 正規化を外す | `tests/Feature/Api/ApiRetryAfterContractTest.php` | **1 failed**: `Retry-After ヘッダも本文と同じ解釈になる` (本文からは消えた HTTP-date がヘッダに残る) |
| M16 | `ErrorScreenCachePolicy::apply()` の呼び出しを削除 | `tests/Feature/Errors/InertiaErrorScreenTest.php` | **2 failed**: `Error 応答のキャッシュ表現契約 (no-store + private + Vary) を満たす` / `認証済みでも同じキャッシュ表現契約を満たす` (`no-cache, private` のまま) |
| M17 | `ErrorScreenCachePolicy` の `addCacheControlDirective` を `headers->set('Cache-Control', …)` に戻す | `tests/Unit/Http/ErrorScreenCachePolicyTest.php` | **1 failed**: `既存の directive を落とさない` (`must-revalidate` が消える) |

## M11 の注記 (設計の期待と実測の差)

詳細設計 S6 の表は M11 で `「認証済みでも 419 はログイン」テスト` (Feature) が赤くなることを
期待していたが、**実測では Feature テストは green のまま**で、Unit テスト
(`ErrorScreenDestinationsTest` の D1 ケース) だけが赤くなった。

理由は D1 が **2 箇所で実装されている**ため:

1. `InertiaExceptionRenderer::render()` — 419 では `$authenticated` の算出自体を短絡して `false` を渡す
   (M13 が固定する「引数評価順の罠」回避。user resolver を呼ばないことが目的)
2. `ErrorScreenDestinations::for()` — 419 なら認証状態に関わらず guest 導線を返す
   (この関数単体で契約が閉じる)

Feature 経路では 1 が先に効くため、2 を削っても最終的な戻り先は `/login` のままになる。
これは「二重に守っている」ことの帰結であり、**mutation はテスト側で確実に検出されている**
(Unit テストが赤くなる)。どちらか一方を消せば必ずどこかのテストが赤くなる状態にはある。
