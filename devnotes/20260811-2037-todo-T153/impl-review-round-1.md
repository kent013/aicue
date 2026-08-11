**ファイルごとの判定**
- `.env.bughunt.local.example` → 指摘あり
- `tests/Feature/Auth/FakeSocialiteWiringTest.php` → 指摘あり
- `app/Http/Controllers/Auth/SocialAuthController.php` → OK
- `app/Providers/FakeExternalsServiceProvider.php` → OK
- `app/Services/Auth/SocialiteDriverResolver.php` → OK
- `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php` → OK
- `app/Services/Auth/Fakes/FakeSocialiteProvider.php` → OK
- `config/testing.php` → OK
- `docs/architecture.md` → OK
- `AGENTS.md` → OK
- `scripts/bug-hunt-shard.sh` → OK
- `tests/Architecture/ExternalSeamInventoryTest.php` → OK
- `tests/Feature/Security/ThrottleExemptionPremiseTest.php` → OK
- `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php` → OK
- `tests/Support/ExternalSeam/ExternalSeamInventory.php` → OK

**指摘**
[Warning] `.env.bughunt.local.example:57-59` のコメントが施策 8 と矛盾しています。  
ここでは「TESTING_FAKE_* の実効値は script 注入が正本」「コピー忘れでも既定は崩れない」と書かれていますが、設計は `TESTING_FAKE_EXTERNALS` を script 注入しないことで `.env.bughunt.local` の欠落を provision で検出する方針です。このままだと運用者が `TESTING_FAKE_EXTERNALS` も注入保証されると誤読します。  
修正案: コメントを `TESTING_FAKE_LLM` / `TESTING_FAKE_STORAGE` と `TESTING_FAKE_EXTERNALS` で分けてください。例: `TESTING_FAKE_EXTERNALS は .env.bughunt.local 側で true 必須。scripts/bug-hunt-shard.sh は provision 時に実効値を検証し、欠落時は fail-fast する。`

[Warning] `tests/Feature/Auth/FakeSocialiteWiringTest.php:181-197` の #9 は、テスト名の「Socialite に触れず」を実証していません。  
現状は intent 不在時に login redirect / guest であることだけを見ています。仮に callback が先に resolver を呼び、その後 login に戻る実装へ壊れても、このテストは通り得ます。設計上は「intent 不在なら Socialite に触れない」ことが確認対象なので、driver 呼び出しを検出してください。  
修正案: `$enableSsoFake()` 後に `SocialiteDriverResolver::class` を、`driver()` が呼ばれたら例外を投げるテスト用 subclass / fake に bind し、その状態で callback 直叩きが login redirect になることを確認する。

**その他**
Socialite `Factory` への直接 bind、IdP 風スタブ画面、新規 Architecture gate、SSO fake の `local` allowlist 混入、除外時 warning ログはいずれも見当たりません。`response()->json()` 直書き、PHPStan の widen / ignore / baseline もこの diff にはありません。UI 変更もありません。

全体判定: CHANGES_REQUESTED