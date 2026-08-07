全体判定: **CHANGES_REQUESTED**

**S1: GatewayFailureClass**
判定: **APPROVE**

指摘なし。`unknown` を写像不在専用に固定する設計は、運用契約と gate の役割が一致しています。

**S2: GatewayFailureClassifier**
判定: **REQUEST_CHANGES**

[Warning] `UnknownApiErrorException` の `null` status を一律 `provider_rejected` に倒す設計は、概念設計で確定済みなので蒸し返しません。ただし `getHttpStatus()` が戻り型宣言なしの vendor PHPDoc 依存で、実値が `int|null` 以外だった場合の PHPStan/runtime 防御が薄いです。  
修正案: `is_int($status)` で narrowing し、非 int は `ProviderRejected` に倒す形にしてください。PHPStan level 10 でも vendor PHPDoc の揺れに強くなります。

[Warning] `directMap()` の全 vendor 例外を「捕まえうるもの」として分類する方針はよいですが、`SignatureVerificationException` は webhook 署名検証用で gateway 消費経路の失敗分類とは責務がずれています。コメントでは「gateway 経路では発生しない」と書いており、分類表に入れる根拠が vendor 全件 gate 由来だけに見えます。  
修正案: `directMap()` コメントに「vendor 全件分類 gate のため、gateway 経路で通常発生しない Stripe 例外も観測語彙上は provider_rejected に分類する」と明記してください。

**S3: AutoRechargeService の観測統一**
判定: **REQUEST_CHANGES**

[Critical] `terminateInvoiceBestEffort()` の `report(new RuntimeException(... "{$failure['error_class']}"))` は例外クラス名を report message に入れます。設計上は `error_class` は許容されていますが、T131 の「原例外を report/previous しない」とは別に、report message がログ集計語彙になるなら `failure_class` と `error_class` の 2 値だけとはいえ、message 側に構造を埋め込むことになります。Feature テストが `fixture:` だけを否定すると、ここに予期しない文字列追加があっても通ります。  
修正案: report message の検査を `invoice id`、`failure_class`、`error_class` 以外の fixture/message を含まないだけでなく、期待フォーマット exact にしてください。可能なら report 用の文言を固定し、構造化ログ側だけに分類を持たせる方が堅いです。

[Warning] 成功時も `failure_class` / `error_class` を null で出す変更は schema 安定には有効ですが、既存の cleanup ログ契約が「失敗原因」を示す前提なら、成功ログにも同じキーが出ることでダッシュボード側の集計条件が変わります。  
修正案: S7 に「成功時は両キー null」と明記し、Feature テストでも cleanup event の成功・失敗両方のキー集合を固定してください。

**S4: テスト用 spy / fixture**
判定: **REQUEST_CHANGES**

[Critical] `GatewayFailureFixtures::throwableFor(GatewayFailureClass::LocalFailure)` の `new QueryException('pgsql', 'select 1', [], new PDOException(...))` は、Laravel 12 の実シグネチャ確認欄では第 5・第 6 引数もあるものの optional なので形はよいです。ただし `QueryException` の message 生成は previous の message を取り込む可能性があり、Feature 側で `fixture:` 非混入を検査する場合、この fixture をログ/report 経路に流すと意図せず赤くなる可能性があります。  
修正案: local failure fixture をログ sanitized 経路で使うテストでは、`context()` だけを見るか、message 非混入を検査するなら `fixture:` を previous に入れない専用 fixture に分けてください。

[Warning] `Tests\Support\FakeAutoRechargeGateway` の public API を `failOnTerminate` から `terminateFailure` に変更するのは妥当ですが、同一 PR 内の全参照更新を gate だけに頼ると見落としやすいです。  
修正案: Architecture test に `failOnTerminate` / `failOnResolveSubscriptionPaymentMethod` の文字列残存 0 件検査を追加してください。

**S5: deny-by-default gate**
判定: **REQUEST_CHANGES**

[Critical] 「ソース上の `GatewayFailureClassifier::context(` 出現回数 == catchSites 数」は脆いです。コメント、テスト用文字列、別文脈の呼び出しでも数が合えば green になります。逆に alias import や改行、helper wrapping で false positive になります。  
修正案: 最低限、対象を `app/Services/Billing/AutoRechargeService.php` に限定したうえで、各対象メソッドごとに `catch (Throwable` と `GatewayFailureClassifier::context(` の両方を検査してください。可能なら token parser / nikic/php-parser など既存依存があれば AST で見るべきです。

[Critical] `getMessage()` cap 0 を「観測目録クラス全体」に掛けると、gateway 以外の正当な `getMessage()` 追加も禁止します。現時点の目的には合いますが、設計上の保証対象は「gateway 例外の観測点」です。  
修正案: cap 0 を維持するなら、S5 の保証範囲に「AutoRechargeService では gateway 観測以外でも `getMessage()` を使わない設計制約を置く」と明記してください。そうでなければ対象 catch 周辺に限定する検査にしてください。

[Warning] vendor 例外母集団を `vendor/stripe/stripe-php/lib/Exception/*.php` 直下に固定する設計は、Stripe SDK 側が例外クラスをサブ名前空間へ追加した場合に「健全性テスト」で検知はできますが、分類集合一致の対象からは外れます。  
修正案: サブ名前空間が `OAuth` だけであることを fail させるだけでなく、OAuth を除く全サブ名前空間の具象例外が 0 件であることを明示してください。

**S6: Unit / Feature テスト**
判定: **REQUEST_CHANGES**

[Warning] `directMap()` そのものを dataset にする Unit は、分類器の表と期待値が同一ソースになるため、誤分類を検出しません。  
修正案: 代表例は独立 dataset で固定してください。`directMap()` 全 entry の「インスタンス化できる」検査は別テストとして有効ですが、分類期待値の正本にはしない方がよいです。

[Warning] `json_encode($context, JSON_THROW_ON_ERROR)` の戻りを直接 `not->toContain()` に渡す箇所は Pest/PHPStan 的には問題ない可能性が高いですが、array shape の検査としては過剰です。  
修正案: `implode("\n", array_map(...))` より、`expect($context)->not->toHaveKey('message')` と `expect($context)->not->toContain(...)` 相当のキー単位検査に寄せると意図が明確です。

**S7: 運用契約**
判定: **REQUEST_CHANGES**

[Critical] `AGENTS.md` へドメイン固有規約 7 を追加する設計ですが、提示された現行 `AGENTS.md` には既にドメイン固有規約 6 まであります。番号追加自体はよいものの、この会話冒頭の AGENTS.md と詳細設計内転記が同期対象になるため、実装時に番号衝突・重複追記が起きやすいです。  
修正案: 「既存末尾へ 7 として追加。既存 1-6 は renumber しない。既に同項目が存在する場合は追記ではなく更新」と実装手順に明記してください。

[Warning] docs の文言「この cleanup 経路では Stripe が生成した原メッセージはアプリのどこにも残らない」は保証を少し強く書きすぎです。`report()` の stack trace や framework/vendor 側の別ログ、queue failure 伝播はスコープ外です。  
修正案: 「この cleanup 経路で本サービスが出す構造化ログと report message には残らない」に弱めてください。

**総評**

方向性は概念設計と整合しています。特に、制御フローを変えず観測語彙だけを有界化する点、fake/real parity を fixture で固定する点、`unknown` を写像不在専用にする点は妥当です。

変更要求の中心は gate とテストの強度です。現設計は deny-by-default の形は整っていますが、文字列出現回数ベースの検査が多く、保証したい性質と検査している性質にずれがあります。ここをメソッド単位・独立期待値・文書上の保証範囲の明確化で締めれば、実装に進める設計になります。