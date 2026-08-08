レビュー結果です。実装挙動そのものに Critical は見つけていませんが、今回の主目的が「誤った機序記述の是正」なので、残っている不正確な記述は修正対象です。

**AGENTS.md — REQUEST_CHANGES**

[Warning] [AGENTS.md](/workspace/.claude/worktrees/tasks/T135/AGENTS.md:375)  
ドメイン固有規約 5 に残っている「route 名が消えたら起動時 fail-fast」は、cached 起動では skip するという今回の契約と衝突します。  
「非 cached 起動 / route:cache 生成時に fail-fast。cached 起動では skip」のように限定してください。

**app/Providers/FortifyServiceProvider.php — APPROVED**

指摘なし。  
feature flag で spec を絞り、既存 helper を削除して binder 経由へ寄せた点は設計通りです。route:cache に関する旧説明も適切に否定されています。

**app/Providers/PasskeyServiceProvider.php — APPROVED**

[Suggestion] [app/Providers/PasskeyServiceProvider.php](/workspace/.claude/worktrees/tasks/T135/app/Providers/PasskeyServiceProvider.php:178)  
`withAlias()` 追加は妥当です。PHPStan level 10 の shape 推論を避けつつ `array<string, list<string>>` の契約を維持しており、付与順序も保たれています。  
ただしコメントの「型を緩めず」は少し強いので、「`mixed` 化や ignore に逃げず、公開契約を保ったまま具体 shape 推論を避ける」程度にするとより正確です。

**app/Support/Http/RouteMiddlewareBinder.php — REQUEST_CHANGES**

[Warning] [RouteMiddlewareBinder.php](/workspace/.claude/worktrees/tasks/T135/app/Support/Http/RouteMiddlewareBinder.php:15)  
docblock の「throttle の後付けは RouteThrottleBinder が担当する」は、実装と矛盾しています。`RouteMiddlewareBinder` は実際に `throttle:passkeys` を付与します。  
「RouteThrottleBinder は limiter 検証・二重付与検出が必要な throttle 後付けを担当し、こちらは既存挙動維持のため任意 alias を順序通り付ける。`throttle:passkeys` も alias として扱う」などに修正してください。

[Warning] [RouteMiddlewareBinder.php](/workspace/.claude/worktrees/tasks/T135/app/Support/Http/RouteMiddlewareBinder.php:65)  
「現行 2 経路は判定タイミングが異なる（Fortify = boot 内 / Passkey = booted 内）」「どちらのタイミングも変えない」は、現在の配線では不正確です。どちらの resolver も `attachOnBooted()` 内の booted callback で評価されます。  
ここは「resolver を `attachOnBooted()` 呼び出し時に前倒し評価しない。cached 起動では resolver も実行しない」とだけ書けば十分です。

**docs/app-integration-guide.md — REQUEST_CHANGES**

[Warning] [docs/app-integration-guide.md](/workspace/.claude/worktrees/tasks/T135/docs/app-integration-guide.md:317)  
§7b に残っている「route 名が消えていれば起動時に fail-fast」が §7c の説明とズレています。ここも AGENTS.md と同じく、fail-fast が効くのは非 cached 起動 / route:cache 生成時であり、cached 起動では skip と明記してください。

§7c 自体の機序説明、T120 の再発条件、stale cache の運用リスクの書き方は妥当です。

**tests/Architecture/PostBootRouteMutationInventoryTest.php — APPROVED**

指摘なし。  
allowlist 外検出と negative control があり、空振り green への対策も入っています。検査範囲を誇張していない点も良いです。

**tests/Feature/Security/RouteMiddlewareBinderTest.php — APPROVED**

指摘なし。  
T120 回帰、lazy resolver、非 cached の negative control、computedMiddleware 破棄、順序保持まで押さえられています。mutation 実測も新設テストが効いている証拠として十分です。

**全体判定: CHANGES_REQUESTED**

コード挙動・テスト網羅は概ね設計通りです。修正対象は主に docblock / 運用記述の精度で、特に `RouteMiddlewareBinder` が `throttle:passkeys` を扱う事実との矛盾は直してください。