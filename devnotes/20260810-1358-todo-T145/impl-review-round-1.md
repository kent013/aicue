レビュー仮説: PR-C3 は「公開文面を SSOT から描画すること」と「検査が節番号に依存せず赤化すること」が中核なので、差分はその2点を中心に確認しました。

**docs/architecture.md**

判定: 問題なし。

[Suggestion] 保証しないものの範囲は実装ノート・テスト docblock と揃っており、過大主張は見当たりません。T145 の追加も極小 PR の範囲内です。

**resources/views/legal/privacy.blade.php**

判定: 問題なし。

[Suggestion] 表示文面は SoT 付録 A と一致し、年数も `\App\Support\Legal\BillingRetention::years()` 由来です。`data-legal-retention="billing-records"` もあり、`consent_version` に触れていない点も制約通りです。XSS 面でも config 由来の int を Blade echo しているだけなので問題は見当たりません。

**tests/Architecture/BillingRetentionConfigSingleSourceTest.php**

判定: 概ね妥当。ただし検査の穴が少しあります。

[Warning] `BillingRetention` を alias import した呼び出しを検出できません。例: `use App\Support\Legal\BillingRetention as Retention; Retention::years();` は `ssotCall` に入らず、caller exact-fit inventory を迂回できます。`use` alias 解決までは難しくても、負のコントロールに alias ケースを足して検出方針を明示するか、alias を禁止・検出するほうが gate の「exact-fit」主張と合います。

[Warning] 共通要件の「自己参照コントロール」がやや弱いです。特に prose literal detector は raw source regex なので、gate ファイル自身を対象にすると fixture の `最長 7 年間` に反応します。現在は privacy blade だけを見るため実害は限定的ですが、「新設 gate に必ず同梱」の要件に厳密には届いていません。

**tests/Feature/Legal/PrivacyRetentionDeclarationTest.php**

判定: 実効性はあります。細部に改善余地があります。

[Warning] 年数検査が `str_contains($marker, (string) BillingRetention::years())` なので、`17年間` や `70年間` のように config 値を部分文字列として含む誤表示を通します。三者一致 gate としては、少なくとも `最長{$years}年間` 相当、または数字境界つき regex で「表示上の年数トークン」を見るほうが堅いです。

[Suggestion] `privacyRetentionHeadingText()` は `id="retention"` の要素テキストだけを見るため、`<p id="retention">保有期間</p>` でも通ります。SoT の「節見出し」を強めるなら `nodeName === 'H2'` も見るとよいです。

**全体判定**

APPROVED

Warning はありますが、PR-C3 の必須スコープである「文面追記」「SSOT 由来描画」「節番号に依存しない検査」「literal 7 の排除」「削除 mutation の赤化」は満たしています。alias 検出と年数の部分一致は、別 PR でもよいですが gate の質を上げるならこの PR 内で直す価値があります。