**AGENTS.md — REQUEST_CHANGES**

[Warning] `AGENTS.md:375`  
「非 cached 起動 = `route:cache` 生成時」という等号が不正確です。fail-fast は `route:cache` 生成時だけでなく、route cache が無い通常起動でも効きます。  
「非 cached 起動、特に `route:cache` 生成時」のように、同一視せず書いてください。

**app/Providers/FortifyServiceProvider.php — APPROVED**

指摘なし。Round 1 の旧 `nameCache` 説明は適切に否定され、feature flag で spec を絞る実装も設計通りです。

**app/Providers/PasskeyServiceProvider.php — APPROVED**

指摘なし。Round 1 の `withAlias()` コメントは過剰な断定が弱められており、`Route::bind()` と middleware 後付けの cached 起動差分も実態に合っています。

**app/Support/Http/RouteMiddlewareBinder.php — REQUEST_CHANGES**

[Warning] `app/Support/Http/RouteMiddlewareBinder.php:34`  
「fail-fast が効くのはここ」は過剰限定です。cached 起動では skip されますが、cache 無しの通常起動でも route 名欠落時には `RuntimeException` が出ます。  
本番デプロイ上の重要地点が `route:cache` 生成時である、という表現に留めるのが正確です。

**docs/app-integration-guide.md — REQUEST_CHANGES**

[Warning] `docs/app-integration-guide.md:318`  
§7b の「fail-fast が効くのは非 cached 起動 = `php artisan route:cache` 生成時」も同じく不正確です。非 cached 起動全般と route cache 生成時を等号で結ばないでください。

[Warning] `docs/app-integration-guide.md:456`  
§7c の「fail-fast が効くのはここだけ」も、cache 無し通常起動での fail-fast を隠します。T120 の説明としては cached 起動では skip、deploy gate として重要なのは cache 生成時、と整理してください。

**tests/Architecture/PostBootRouteMutationInventoryTest.php — APPROVED**

指摘なし。allowlist 外検出と negative control があり、検査範囲の限界も誇張されていません。

**tests/Feature/Security/RouteMiddlewareBinderTest.php — APPROVED**

指摘なし。lazy resolver、cached 起動の回帰、negative control、memo 破棄、順序保持まで押さえられています。

**全体判定: CHANGES_REQUESTED**