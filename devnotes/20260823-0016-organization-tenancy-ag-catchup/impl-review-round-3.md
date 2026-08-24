Round 2 の主要な検出漏れは修正されていますが、許可目録の「構文文脈 exact-fit」と import 解決に Critical が残っています。どちらも施策10の中核なので、別 TODO ではなく本 PR で修正すべきです。

### `tests/Support/LegacyUrl/LegacyUrlScanner.php`

- [Critical] `importedOrganizationUrlBuilders()` は、canonical module から import された名前をすべて builder とみなしています。import 元の名前が `orgUrl` / `currentOrgUrl` かを確認していません。

```ts
import { currentOrganizationSlug as passthrough } from "@/lib/org-url";

const target = passthrough("/projects");
```

`passthrough` も `$builderNames` に入り、旧 URL が免除されます。import specifier は「import 元の名前」と「local alias」を分け、元が許可された2関数の場合だけ local alias を登録する必要があります。

- [Critical] import 検出はコメントを除外しましたが、文字列リテラル内の偽 import を除外していません。

```ts
const example = 'import { orgUrl } from "@/lib/org-url"';

function orgUrl(slug, path) {
    return path;
}

const leaked = orgUrl(slug, "/projects");
```

正規表現は `example` の内容にも一致します。import 宣言を構文として抽出するか、少なくとも文字列・テンプレート・コメント外にある行頭 import だけを解析してください。この2形の負例も本 PR に必要です。

### `tests/Support/LegacyUrl/LegacyUrlAllowance.php`

- [Critical] path 全体を追加したことで `/app` と `/app/projects` は区別できましたが、元設計が要求する「構文文脈まで識別する exact-fit」にはまだ達していません。同一ファイル内で同じ path を別の用途へ移しても通ります。

たとえば `public/manifest.webmanifest` を次のように変えても、path・root・件数は同じなので `CanonicalCaptureEntry` が通ります。

```json
{
  "start_url": "/wrong",
  "unrelated": "/app"
}
```

設計で例示された `manifest-start-url` のように、JSON key、PHP call、テスト要求先などの構文文脈を固定する必要があります。`CanonicalCaptureEntry` の全登録を単に「path が `/app`」で許すのは広すぎます。

- [Critical] 同じ問題は他の区分にも残っています。

  - `FilesystemPath`: 同じ path を URL 用の変数へ移しても実在ディレクトリなら通る
  - `StorageObjectKey`: marker がファイル内の別位置に残っていれば、旧 URL に置換しても通る
  - `AbsenceAssertion`: 「撤去」の語が別位置にあれば、route 名を現役の例へ移しても通る
  - `OrganizationRelativePath`: symbol と module 名が同じ consumer 内にあるだけで、値を直接 `href` に渡しても通る

  特に `OrganizationRelativePath` は壊れた導線を許可目録が隠し得るため、本 PR で閉じる必要があります。完全な汎用データフロー解析までは不要ですが、対象が2種類に限定されているので、次のどちらかで十分です。

  - 対象 consumer ごとの構文・呼び出しを名指しで検査する
  - 実際に生成される CTA/request URL が組織 prefix を持つことを focused test で固定し、その検査を許可登録の根拠として名指しする

- [Critical] class docblock の「同じファイル・同じ件数で別の旧 URLへ置き換えても通らない」は、同一root・同一pathを別構文へ移すケースでは成立しません。保証範囲の誇張です。構文文脈をキーへ加えるか、主張を狭めるだけでなく、元設計からの逸脱として明示する必要があります。ただし manifest や組織相対導線については、主張を狭めるだけでは不変条件が失われるため本 PR での検査が必要です。

### `tests/Support/LegacyUrl/LegacyUrlOccurrence.php`

- [Critical] occurrence は path まで保持するようになりましたが、構文文脈を保持していません。そのため許可目録は「どの `/app` か」を識別できません。最低限、抽出器が取得した安定した context IDを持たせる必要があります。

例:

- `manifest:start_url`
- `php:argument:<call-name>:<argument-index>`
- `route:name`
- `test:request-target`
- `markdown:link-destination`

すべてを汎用化する必要はなく、許可目録に登録する32件の文脈だけを deny-by-default で分類する形でも構いません。

### `tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php`

- [Warning] 5区分の成立・不成立テストは改善されていますが、いずれも「同じpathを別構文へ移す」今回残っている抜け道を検査していません。少なくとも次を負例として追加すべきです。

  - manifest の `/app` を `start_url` 以外のkeyへ移す
  - canonical test内の `/app` を無関係な変数へ移す
  - `BILLING_CALLOUTS` を直接 URL として使い、別位置に builder 呼び出しを残す
  - storage markerを残したまま、対象pathを通常リンクへ移す

### `tests/Architecture/OrganizationRouteHandlerParameterTest.php`

判定: 指摘なし。prefix一致と中間欠落の負例により、実測した位置ずれを適切に固定できています。

### `tests/Support/LegacyUrl/LegacyUrlScanRoots.php`

判定: 指摘なし。NUL・不正UTF-8・symlink の fail-closed 分岐は、実母集団と同じ入口を通る負例で裏取りされています。

### `tests/Architecture/LegacyUrlSelfCheckPopulationTest.php`

判定: 指摘なし。独立性を「抽出方式だけ」と正確に狭めた説明は妥当です。

### 別 TODO に送ってよい限界

以下は利用側 gate が明確に保証から除いており、今回の施策10を偽っていないため、別 TODO で構いません。

- script 抽出を正式な TypeScript/Svelte parserへ置き換えること
- 実行時連結、絶対 URL、query/hash 内 URLの検出
- 自己検査用 matcherを本体のroot位置判定から完全に独立させること
- `OrganizationRelativePath` の汎用的なデータフロー解析

ただし、現在目録にある具体的な `OrganizationRelativePath` の導線が安全であることを focused test で固定する作業は、本 PR の許可根拠そのものなので別 TODOには送れません。

### `tests/Support/LegacyUrl/LegacyUrlAllowanceKind.php`

- [Suggestion] `StorageObjectKey` のdocblockが存在しない `STORAGE_KEY_PREFIX` を参照しています。実装の正本である `STORAGE_KEY_MARKERS` に合わせてください。

全体判定: CHANGES_REQUESTED