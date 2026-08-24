仮説どおり、走査器の検出力を迂回できる経路と、許可目録が構文文脈を固定できていない問題があります。現状の「旧 URL ゼロ」という保証は成立しません。

### `tests/Support/LegacyUrl/LegacyUrlScanner.php`

- [Critical] `orgUrl()` / `currentOrgUrl()` の許可判定が生ソースへの正規表現だけで、呼び出しを名前解決していません。次の旧 URL は検出されません。

```ts
// currentOrgUrl(
const href = "/projects";
```

コメントは `SourceLiterals` では除外されますが、許可判定側の `[^()]*` は改行とコードを跨いで一致します。また、ローカルで同名関数を再定義した場合や `notcurrentOrgUrl("/projects")` のような接尾辞一致でも除外されます。canonical な `@/lib/org-url` の関数呼び出しであることと、対象引数位置を構文的に確認しない限り、規則 3 は明確な抜け道です。

- [Critical] `/app` の変更は妥当ではありません。設計が許していたのは「正規入口として名指しされた出現」であって、すべての裸の `/app` ではありません。現在は任意の PHP・JS・文書へ `/app` を直書きしても通り、「導線は route helper 経由だけ」という不変条件が消えています。さらに `/app/` も許可されますが、正規 route が `/app` であることとの同一性も固定されていません。許可目録は旧文字列を保持せず、パス・文脈 rule ID・件数だけで表現できるため、再帰を理由に撤去する必要はありません。

- [Critical] 詳細設計の「query/hash を除いた正規化済み path」を実装していません。現在は生文字列への部分検索なので、`/x#/projects` や `?next=/billing` を path として数える一方、絶対 URL はすべて除外します。特に自アプリの `https://app.example.com/projects` も外部サービス URL と区別されず見逃します。gate は「旧 URL ゼロ」と主張しているため、相対 URL だけの検査へ保証を狭めるか、自アプリ host を識別して path を解析する必要があります。

- [Critical] 撤去 route 名は `str_contains()` により、1 chunk・1 行につき最大1件しか数えません。同じリテラルや許可済み行へ2個目を追加しても件数が増えず、exact-fit を迂回できます。

- [Warning] `/app/?query` と `/app/#fragment` は「配下セグメントあり」と誤判定されます。`hasSubPathAfter()` は `/` の後が `?`・`#` でないことを確認していません。

### `tests/Support/SourceLiterals.php`

- [Critical] script 抽出器の「構文解析でなくても見逃し側には倒れない」という保証は事実と一致しません。例えば有効な JavaScript の次の旧 URLを見逃します。

```ts
const quotePattern = /["]/; const href = "/projects";
```

正規表現中の `"` を文字列開始と読み、実際の `href` の開始引用符を終了引用符として消費するためです。Svelte の引用符なし属性なども見逃し側に倒れます。保証範囲の誇張なので Critical です。既存の parser を使うか、未解決構文として gate を失敗させる必要があります。

- [Warning] PHP の補間を `{$}` へ畳む処理自体は、提示された単純な `{organization}/{slug}` 形では明確な見逃しを確認できません。ただし、複雑な補間・エスケープ・連結後の path 正規化は保証されません。特に scanner 側の「正規化済み path」不足とは分けて扱うべきです。

### `tests/Support/LegacyUrl/LegacyUrlAllowance.php`

- [Critical] 許可キーが `path + 抽出方式 rule ID` だけなので、対象パターンと構文位置を固定できません。たとえば許可済み PHP ファイル内のファイルシステムパスを、同じ件数の `redirect('/projects')` に置き換えても通ります。詳細設計が要求する「対象パターン完全一致」「構文文脈まで識別する rule ID」を満たしていません。

- [Critical] `kind` は判定で一度も使用されていません。`FilesystemPath`、`AbsenceAssertion`、`OrganizationRelativePath` のどれを指定しても許可効果が同じであり、共通規約 (d) の「集めた結果を判定に使わない」に該当します。現状では3区分は制約ではなく説明ラベルです。

- [Critical] `OrganizationRelativePath` は利用側が `currentOrgUrl()` を通すことを機械検査していません。`resources/js/types/dashboard.ts` の値が別の未変換 consumer から使われても目録は green のままです。特にこの区分は「なんとなく直せない」を入れる口になっています。

- [Warning] 同一 `path + rule` の重複登録を拒否せず、`counts()` で後勝ち上書きされます。目録の一意性を固定すべきです。

- [Warning] 提示された目録は9エントリありますが、検証結果は「許可目録は7件」となっており、検証証跡と差分が一致していません。

### `tests/Support/LegacyUrl/LegacyUrlAllowanceKind.php`

- [Critical] enum の説明が約束する区分別契約を利用側が検査していないため、現状の型は安全性を増やしていません。少なくとも各区分に対応する対象・consumer・構文条件を検証する必要があります。

### `tests/Support/LegacyUrl/LegacyUrlOccurrence.php`

- [Critical] `ruleId` が「構文文脈まで識別する安定 ID」と説明されていますが、実際の値は `php-string-literal` や `script-string-literal` というファイル種別だけです。同一ファイル内での別構文への置換を防げず、docblock と実装が乖離しています。

### `tests/Support/LegacyUrl/LegacyUrlScanRoots.php`

- [Critical] `routes/` 全体の除外は穴になります。解決済み route 表で検証できるのは最終 URI ですが、次は検査できません。

  - route closure 内の `redirect('/projects')`
  - route default・middleware 引数などに置かれた旧 URL
  - 組織配下の有効な URIに再利用された `organizations.switch` という撤去 route 名

  特に撤去 route 名の台帳は route 表の組織 prefix 検査では代替できません。「PHP 全層を走査する」という設計からの逸脱を `OrganizationScopedRouteCoverageTest` だけでは補完できないため、routes 全体ではなく route URI リテラルの構文文脈だけを除外すべきです。

- [Warning] `patch` / `err` の除外理由は「devnotes 配下だけ」と説明していますが、実装は拡張子だけで全リポジトリから除外します。たとえば `resources/example.patch` も未分類にならず黙って除外されます。

- [Warning] symlink、NUL、不正 UTF-8、通常ファイルでないケースには fail-closed 分岐がありますが、その分岐を発火させる負例が提示されたテストにありません。

### `tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php`

- [Critical] gate は「旧 URL が1件もない」と主張しますが、scanner が明記している連結 URL、絶対 URL、script parser の未解決構文を除く形へ保証を狭めていません。共通規約 (b) が禁じる「走査器の限界だけを書き、利用側 gate は全数保証を主張する」状態です。

- [Critical] 許可判定が一致語・行・構文文脈を比較せず、`path + broad rule ID + count` だけです。同じ件数で別の旧 URLへ置換するケースが正例テストにありません。

- [Warning] 詳細設計が要求した Blade、JSON/webmanifest の検出正例・非検出正例がありません。分類表への割り当て確認は抽出器の検出力確認の代わりにはなりません。

- [Warning] `orgUrl()` 許可が効きすぎない負例、同一行での route 名重複、絶対 URL、query/hash 正規化についても負例が不足しています。

### `tests/Architecture/LegacyUrlSelfCheckPopulationTest.php`

- [Critical] 撤去 route 名を1行1件で数えるため、既存行へ同じ語を追加しても件数が変わりません。

- [Warning] 自己検査件数を本体の `matchesIn()` で数えており、独立した語彙個数の pin になっていません。絶対 URLなど本体が見逃す形を fixture へ追加しても件数が変化しません。

### `tests/Architecture/OrganizationRouteHandlerParameterTest.php`

- [Critical] `organization` 引数が「どこかにある」ことしか確認せず、位置ずれ防止という機能名を満たしません。次の handler は検査を通りますが、route parameter の割り当てはずれます。

```php
public function show(
    Request $request,
    string $notification,
    Organization $organization,
): Response
```

route parameter の並びに対応する位置を検査する必要があります。

- [Critical] closure も Laravel の route dependency resolution を通るため、「位置の契約が違う」という保証外説明は妥当ではありません。closure route に同じ不具合を作れてしまい、保証範囲の誇張になっています。

- [Warning] 合成負例は引数名の有無しか検査せず、実際の失敗要因である順序違いを裏取りしていません。

### `tests/Architecture/fixtures/legacy-url/allowed-paths.md`

- [Critical] 裸の `/app` を無条件の許可例にしたことで、詳細設計の「許可目録にない裸の `/app` を検出する」という負例を逆転させています。正規入口の実在箇所だけを exact-fit で許す形へ戻す必要があります。

### `tests/Architecture/fixtures/legacy-url/legacy-paths.md`

判定: 上記 matcher 自体の問題を除き、root・閉じ記号・句読点の基本ケースは妥当です。

### `tests/Architecture/fixtures/legacy-url/legacy-php-source.txt`

判定: コメントと PHP リテラルを分ける基本ケースは妥当です。ただし複雑な補間、エスケープ、同一リテラル内の重複 route 名を追加すべきです。

### `tests/Architecture/fixtures/legacy-url/legacy-script-source.txt`

- [Warning] canonical builder の正常例だけで、コメントによる偽の builder 呼び出し、同名関数の shadowing、識別子接尾辞、正規表現リテラルによる parser state 崩れがありません。

### `app/Http/Controllers/NotificationController.php`

- [Warning] manual 通知だけは通知 organization と URL organization の不一致を検査しますが、`TicketBalanceLow` は無条件に現在の URL organization の課金画面へ送ります。全組織横断の通知一覧から、組織Bの残高通知を組織Aの URLで開くと組織Aの課金画面へ遷移します。権限漏れではありませんが、通知の対象と操作先が食い違います。

### `app/Http/Controllers/Auth/SocialAuthController.php`

判定: 指摘なし。Owner 組織への限定と `app.entry` への着地は、提示されたテナンシー方針と整合しています。

### `app/Http/Responses/Fortify/RegisterResponse.php`

判定: 指摘なし。初期組織を Owner 関係で識別する変更は、`is_personal` 撤去後の意図として妥当です。

### `app/Http/Middleware/RequireActiveSubscription.php`

判定: 指摘なし。URL binding を唯一の組織源とし、所属による層2の404と役割による層3の403を分離できています。

### `app/Providers/AppServiceProvider.php`

判定: 指摘なし。binding 前の識別名を limiter key に使う判断は説明された middleware priority と整合します。

### `resources/js/components/features/manual/TakePickerList.svelte`

判定: 指摘なし。

### `resources/js/components/features/manual/TakePreviewPanel.svelte`

判定: 指摘なし。

### その他の新規 DTO・enum

`LegacyUrlExtractionMode.php`、`LegacyUrlScanClass.php`、`LegacyUrlScanPopulation.php`、`LegacyUrlScannedFile.php` は単体の型設計に問題はありません。ただし上記の scanner・目録の保証不足を型では補えていません。

全体判定: CHANGES_REQUESTED