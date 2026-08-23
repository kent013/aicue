Round 1 の主要修正は進んでいますが、4件の Critical が残っています。特に撤去 route 名を `routes/` 内で再び見逃す経路があります。

### `tests/Support/LegacyUrl/LegacyUrlScanner.php`

- [Critical] `withoutRouteDefinitionUris()` が URI 以外の `->name()` / `->as()` も除外しています。次の撤去 route 名は検出されません。

```php
Route::get('/x', Handler::class)
    ->name('organizations.switch');
```

`organizations.switch` の直前が `->name(` なので、文字列リテラル自体が抽出結果から除去されます。「route 定義の URI 引数だけを外す」というdocblockとも一致しません。`name` / `as` は除外集合から外す必要があります。

- [Critical] canonical builder の import 判定が `str_contains()` だけなので、コメントや無関係な文字列でも前提を満たせます。

```ts
// "@/lib/org-url"
function orgUrl(slug, path) {
    return path;
}
const leaked = orgUrl(slug, "/projects");
```

`importsBuilder` は true、コメントを潰した後も実際の `orgUrl()` 呼び出しは残るため、旧 URL が免除されます。import 宣言から、取り込まれたローカル名まで構文的に解決する必要があります。

- [Warning] `legacy-script-source.txt` の `notCurrentOrgUrl()` は大文字の `C` により、修正前の小文字 `currentOrgUrl` パターンにも一致しません。識別子接頭辞の負例には `notcurrentOrgUrl()` や `myorgUrl()` のように、lookbehind がなければ実際に一致する形が必要です。

### `tests/Support/LegacyUrl/LegacyUrlAllowance.php`

- [Critical] 区分の前提が、許可対象となった個々の出現と結び付いていません。依然として同一ファイル内で同じ語・同じ件数の別用途へ置換できます。

  - `CanonicalCaptureEntry` は route 表に `/app` が存在することしか見ません。許可済みファイルの `/app` を `/app/projects/1` に置換しても、`matched` は同じ `/app` で件数も変わらず通ります。
  - `FilesystemPath` はリポジトリに `app/` が存在することしか見ず、その出現が本当にファイルパスか確認しません。
  - `StorageObjectKey` と `AbsenceAssertion` は、同じファイルのどこかに marker があれば通ります。
  - `OrganizationRelativePath` は consumer のどこかに module 名または `/organizations/` があれば通り、登録した値がその builder に渡されることを確認しません。

  したがって `kind` は判定に読まれるようにはなりましたが、対象出現の意味を制約する段階には達していません。少なくとも構文文脈を識別する rule ID、対象フィールド・シンボル、または occurrence と consumer の対応を固定する必要があります。

- [Critical] 特に `OrganizationRelativePath` は Round 1 の問題が残っています。`Dashboard.svelte` が module を import しているだけで、CTA の該当値を直接 `href` に使うよう変更しても precondition は green です。「名指しした利用側が実在する」だけでなく、登録対象の値が組織 URL builder を通ることを検査してください。

- [Warning] `preconditionViolation()` の5区分について、不適合な合成 entry が拒否される負例がありません。現在の実目録がすべて null になるテストだけでは、各 match arm が常に null を返す回帰を検出できません。重複キー例外についても同様です。

### `tests/Architecture/OrganizationRouteHandlerParameterTest.php`

- [Critical] 順序検査は改善しましたが、途中の route parameter の欠落を検出できません。

```php
// route parameters:
['organization', 'project', 'manual']

// handler:
function (
    Request $request,
    Organization $organization,
    Manual $manual,
): Response
```

現在の計算では `$declared = ['organization', 'manual']`、`$expected = ['organization', 'manual']` となり通ります。しかし実際には `project` の値が `$manual` へ位置割り当てされます。

handler が受ける route parameter は、route parameter 列の「任意の部分列」ではなく、少なくとも最後に受ける parameter まで欠落のないprefixである必要があります。負例にも「organization はあるが中間 parameter が欠落」の形を追加してください。

### `tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php`

- [Warning] `/app` を一般許可から戻した中心的な回帰に対し、「許可目録にない裸の `/app` が検出される」負例がまだありません。`/app/projects` だけでなく裸の形を明示的に固定すべきです。

- [Warning] `routes/` の負例は `redirect()` だけで、最重要の別台帳である `->name(removedRouteName)` を検査していません。この負例があれば、上記の `->name()` 除外問題を検出できました。

- [Warning] 区分前提テストは現行目録の正常系だけです。走査器共通規約 (c) に従い、5区分それぞれについて成立・不成立の両方向が必要です。

### `tests/Architecture/LegacyUrlSelfCheckPopulationTest.php`

- [Warning] 「本体とは別の素朴な数え方」と説明していますが、旧パスは本体の `matchesIn()` を再利用しています。抽出方式から独立した点は改善されていますが、root位置・境界判定の欠陥からは独立していません。説明を「抽出方式だけが独立」と狭めるか、自己検査用の独立した語彙出現カウンタにしてください。

### `tests/Support/LegacyUrl/LegacyUrlScanRoots.php`

- [Warning] symlink / NUL / 不正UTF-8の負例見送りには異論があります。追跡下 fixture は不要です。

  - symlink 判定は一時ディレクトリ内の symlink で検査可能
  - NUL / UTF-8 判定は内容検証を純関数へ切り出して合成文字列で検査可能

  `unresolved === []` は現在の母集団が正常であることを確認しますが、異常入力を正しく `unresolved` へ送れる検出力の裏取りではありません。少なくとも新設した分岐の代表的な負例は必要です。

### `tests/Support/SourceLiterals.php`

判定: 検出力の主張を発見的 script 構文の範囲外へ狭めた点は妥当です。ただし builder の安全な免除条件として利用する以上、module import の判定は上記のとおり発見的な部分文字列一致では不足します。

### `app/Http/Controllers/NotificationController.php`

判定: 指摘なし。残高通知の対象組織と URL 組織を一致させる修正は妥当です。

### `routes/web.php`

判定: closure が `Organization` を受ける修正自体は妥当です。残る問題は handler gate の中間parameter欠落検出です。

全体判定: CHANGES_REQUESTED