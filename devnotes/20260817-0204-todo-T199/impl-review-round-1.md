提示 diff ベースでレビューしました。Critical / Warning はありません。

**ファイル別**
`.claude/skills/app-bug-hunt/inventory/annotations.toml`: 問題なし。`capture.account` の通常画面登録は設計通りです。

`.claude/skills/app-bug-hunt/screens.md`: 問題なし。件数と画面名が `config/seo.php` の静的タイトルに追随しています。

`app/Http/Controllers/Capture/CaptureAccountController.php`: 問題なし。`SeoManager::setPrivateTitle()` から `config('seo.app_titles')` へ寄せた逸脱は、静的タイトルと bug-hunt 目録の整合という理由があり正当です。current org の 404 境界も認可前に閉じています。

`config/seo.php`: 問題なし。静的 page title の置き場として妥当です。

`docs/supported-browsers.md`: 問題なし。logout 導線数の同期更新も適切です。

`resources/js/pages/Capture/Account.svelte`: 問題なし。共有 props のみを使い、メールは省略せず、ログアウトは Inertia `router.post` で実装されています。DS token / Lucide / Atomic import も逸脱なしです。

`resources/js/pages/Capture/Index.svelte`: 問題なし。`PageHeaderSection` への置換は actions 追加に合っています。

`routes/web.php`: 問題なし。`/app/account` は subscription gate 内、project parameter なしという設計判断と一致しています。

`tests/Feature/Capture/CaptureAccountScreenTest.php`: 問題なし。200 / 404 / guest / title の重要経路が押さえられています。

`tests/js/architecture/logout-call-site-inventory.test.ts`: 問題なし。deny-by-default 目録更新として妥当です。

`tests/js/pages/CaptureAccount.test.ts`: [Suggestion] `routerPostMock.mockImplementation()` を設定した後に `vi.clearAllMocks()` だけだと実装は残ります。現状は後続テストが logout を押さないため実害はありませんが、将来の追加に備えるなら `mockReset()` まで行うとより堅いです。

`tests/js/pages/CaptureIndex.test.ts`: 問題なし。

APPROVED