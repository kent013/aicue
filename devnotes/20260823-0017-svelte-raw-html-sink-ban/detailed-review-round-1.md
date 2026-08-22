## 全体判定: CHANGES_REQUESTED

正典 t1 の4点そろい、DTO/Inertia/API非変更、Atomic Design、DS token、スコープ境界の整理は妥当です。一方、設計どおり実装すると gate が確実に失敗する箇所と、新設走査器の規約違反が残っています。

以下は提供テキストに基づく静的レビューです。指示どおりコマンド実行・ファイル書き込みは行っていません。

### 施策1: lint規則の有効化 — APPROVE

`svelte/no-at-html-tags: "error"` の追加位置、許可一覧を設けない方針、既存 `no-undef` gate との責務分離はいずれも適切です。

- PHPStan、DTO/JsonResource、Inertia Propsへの波及はありません。
- 正典I1・I6を過不足なく満たしています。
- file-scoped overrideを技術的に禁止していなくても、施策4の直接走査が例外を許さないため防御は成立します。

### 施策2: QrCodeImage atom — REQUEST_CHANGES

[Critical] 新コンポーネント自身のコメントが施策4の直接走査に違反します。

施策4はコメントを含めて文字列 `{@html` を検出すると宣言していますが、提案された `QrCodeImage.svelte` のdocblockには、その文字列が複数回含まれます。そのためQRの実サイトを除去しても検査Cはgreenになりません。

修正案:

- `resources/js` 配下の `.svelte` コメントでは禁止構文の字面を使わず、「raw HTML directive」「生HTML挿入構文」などに言い換える。
- コメントも禁止する現在の単純走査を維持するなら、構文解析器の導入は不要です。
- 実装前に、提案する全 `.svelte` 本文を対象に同じ部分文字列検査を行い、コメントを含めて0件になることを確認する。

[Warning] `class` propを「万一のため」に残す判断は、今必要なものだけ作る原則と矛盾します。

現在の呼び出し箇所はwrapperで寸法・装飾を管理しており、`class`を渡していません。

修正案:

- 初版では `class` propを削除する。
- 将来実際に寸法差が必要になった時点で、任意classではなく`size`などのDS制約付きpropを検討する。
- DESIGN.mdと部品テストからも`class`を除く。

### 施策3: Security.svelteの置換 — APPROVE

`{#if qrSvg}` によって `string | null` が安全に絞り込まれ、必須の`svg: string`へ渡せます。wrapperの`role="img"`を外して`img.alt`をアクセシブルネームの単一正本にする判断も妥当です。

- page → atomのimportはAtomic Designの正方向です。
- 状態機械、API応答形、DTO、Inertia Propsには波及しません。
- 既存DS classのみで、hexやinline SVGの追加もありません。

ただし、施策2のコメント違反を解消しない限り、リポジトリ全体としてはgreenになりません。

### 施策4: raw HTML sink gate — REQUEST_CHANGES

[Critical] 直接走査Cの検出力を恒久的に裏取りする負例・正例がありません。

実装前に現行`Security.svelte`で赤を確認するだけでは、その違反を削除した後に検出器を壊してもテストがgreenになり得ます。B/B'はESLint規則の検出力であり、Cの文字列走査とは別の機構です。

これはAGENTS.mdの「(c) 負例と正例」および新設gateの4点そろいに未適合です。

修正案:

- `containsRawHtmlSink(source: string): boolean`などの純関数を切り出す。
- 合成入力で次を恒久テストする。

  - `{@html value}`を検出する負例
  - 通常の補間、`{@const}`、`{#if}`を検出しない正例
  - コメントも違反とする設計なら、コメント内の字面も検出すること

- 実ファイルの本文も同じ関数へ渡し、収集結果を最終判定に必ず使う。

[Warning] 検査Aが仮想ファイル1件だけでは「resources/js配下の全 `.svelte` でerror」を保証しません。

特定ファイル向けoverrideで規則をoffにしても、代表仮想パスだけなら見逃します。許可一覧を持たないという設計とも整合しません。

修正案:

- Cで収集した実在 `.svelte` 全件について`calculateConfigForFile()`を実行する。
- 各ファイルの実効severityがerrorであることを確認し、違反パスを一覧表示する。
- これにより収集した母集団をAとCの両方で判定に使えます。

[Warning] 「lint実行失敗はthrowされる」とするだけではfail-closedになりません。

ESLintは構文解析エラーをthrowせず、`LintResult.messages`のfatal messageとして返す場合があります。B'やDが対象ruleの0件だけを見ると、解析失敗を正常扱いできます。

修正案:

- 全`lintText()`結果について`fatalErrorCount === 0`かつ`message.fatal !== true`を確認する。
- ignored fileや設定未適用も正常扱いしない。
- そのうえで対象ruleの件数を判定する。

なお、走査根の存在確認、母集団非空、docblockの対象・保証外、結果の最終判定利用、B/B'の対照実験は適切です。(e)は許可語の否定照合を行わないため本gateには非該当です。

### 施策5: QrCodeImage部品テスト — REQUEST_CHANGES

[Warning] 検査項目3について、本文とリスク節の結論が矛盾しています。

一方では`encodeURIComponent(svg)`との完全一致、他方では接頭辞だけに留めるとしています。実装時にどちらを採るか不明確です。

修正案:

- 次の性質検査に確定する。

  1. `getAttribute("src")`が`data:image/svg+xml,`で始まる
  2. 接頭辞を除いたpayloadを`decodeURIComponent()`すると入力SVGと一致する
  3. `#`、`%`、非ASCIIを含む入力でも往復する
  4. render結果のcontainer内に`svg`・`script`要素が生成されない
  5. `alt`と`testId`が渡る

- 実装と同じ式による完全一致テストは不要です。
- 施策2の修正に従い、未使用の`class`検査も削除します。

### 施策6: 画面テスト — REQUEST_CHANGES

テスト内容自体は正典I4と後退防止を適切に覆っています。

[Warning] 実装順がテストファーストになっていません。

現在の手順は`Security.svelte`を置換した後で新規画面テストを追加しています。これは明示された思考原則とAGENTS.mdに反します。

修正案:

1. 現行画面に対して新規画面テストを書く。
2. `two-factor-qr`が`IMG`であるというassertionが失敗することを確認する。
3. その後で`Security.svelte`を置換する。
4. 成功・QR単独失敗・悪意あるSVG文字列の3経路をgreenにする。
5. 既存`SettingsSecurityTwoFactorConfirm.test.ts`と`SettingsSecurity.test.ts`も回帰確認する。

部品テストも同様に、コンポーネント実装より先に失敗を確認する順序へ直してください。

### 施策7: CSP依存のpin — REQUEST_CHANGES

[Warning] `/img-src[^;]*\bdata:/`はCSP sourceの完全一致になっていません。

例えば`https://data:443`のように、部分列として`data:`を含む別sourceでも一致し得ます。それでは`data:` scheme-sourceが許可されていることを厳密に固定できません。

修正案:

- CSPを`;`でdirectiveへ分割する。
- 対象directiveをASCII空白でtokenへ分割する。
- directive名が`img-src`の要素を取得し、source tokenに`data:`が完全一致で含まれることを検査する。
- PHPStan level 10に合わせ、抽出helperは`@return list<string>`などで戻り型を明示する。
- 既定構成とGTM構成の両方へ同じhelperを適用する。

2構成を同じテストで確認すること、`config/security.php`を変更しないこと、CSP配信機構そのものへスコープを広げないことは適切です。

### 施策8: DESIGN.md追記 — APPROVE

禁止の事実、代替部品、CSP依存、参照先を記録する内容として妥当です。正典の範囲を`innerHTML`などへ勝手に広げていません。

施策2に合わせ、未使用の`class` propだけは記載から削除してください。

## 正典・横断観点の結論

- 正典t1の4点そろい: 方針上は充足
- 過大化: なし
- 過小化: 直接走査Cの恒久的な検出力テストが不足
- PHPStan level 10: CSP抽出を型付きhelperにすれば問題なし
- DTO/JsonResource/Inertia: 応答契約を変更しないため対象外として妥当
- セキュリティ: sink除去の方向は正しいが、CSP sourceの部分一致は修正必須
- DESIGN/Atomic Design: 適合
- 最大の実装阻害: `QrCodeImage.svelte`のコメント内にある禁止文字列が、施策4の検査Cへ自己違反すること

上記Critical 2件とWarningを設計へ反映した後であれば、APPROVEDにできる構成です。