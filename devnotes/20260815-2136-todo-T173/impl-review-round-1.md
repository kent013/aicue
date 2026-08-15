**前提**

コマンド実行は禁止されているため、提示 diff と提示されたテスト結果だけを対象にレビューしました。独自の再実行はしていません。

**AGENTS.md**

[Warning] `追跡下に直接書かれた route:cache が無い` という説明が、実装の実態より少し強いです。  
`RouteCacheExemptionPremiseTest.php` は `tests/Feature/Security/RouteThrottleBinderTest.php` をファイル丸ごと除外しているため、「追跡下の直接リテラルを全て見る」とは言えません。除外を既知の 1 リテラルだけに絞るか、文書側で名指し除外の存在を明記してください。

**docs/app-integration-guide.md**

[Warning] AGENTS.md と同じく、検査範囲の説明がファイル丸ごと除外を反映していません。  
特に security guide は保証範囲の正本なので、現在のままだと「直接書かれた文字列まで検出する」と読める一方で、除外ファイル内の将来の `route:cache` 実行記述は沈黙します。

**docs/template-divergence.md**

[Warning] D19 の方向性と担い手表は設計どおりですが、`RouteCacheExemptionPremiseTest` の保証範囲に「名指し除外ファイル全体は見ない」が入っていません。  
D19 は逸脱判断の正本なので、テスト実装を occurrence-level allowlist に直すか、保証範囲へ明記する必要があります。

**tests/Architecture/RouteCacheExemptionPremiseTest.php**

[Warning] `ROUTE_CACHE_PREMISE_SCAN_EXEMPTIONS` がファイル単位なので、`RouteThrottleBinderTest.php` に将来 `Artisan::call('route:cache')` のような実行記述が追加されても検出されません。  
これは「deny-by-default」の粒度を落としています。既知の説明リテラルだけを許す形、例えば該当ファイルの検出件数・needle・周辺文字列を pin して、それ以外の増加を fail させる実装に寄せるべきです。自己ファイル除外は検出器の fixture を持つため許容できますが、外部テストファイルの丸ごと除外は blind spot になります。

**tests/Feature/Security/RouteCacheBakedProtectionTest.php**

指摘なし。  
検査 1 は `prepareForSerialization()` を挟み、複製 route collection を compile し、元 route の不変性も見ているため、単なる `compile()` の同語反復ではありません。保証しない範囲も docblock に明記されています。

検査 2 の自己証明も、同一プロセス内の `setCompiledRoutes()` の効き方を確認するには十分です。`CompiledRouteCollection` 化、対象 route の middleware 差分、HTTP 要求を差し替え後に初実行する点が押さえられています。

**全体判定: CHANGES_REQUESTED**

主設計と RouteCacheBakedProtectionTest は妥当です。修正が必要なのは、`RouteCacheExemptionPremiseTest` の外部ファイル丸ごと除外と、それに対する文書上の保証範囲のずれです。