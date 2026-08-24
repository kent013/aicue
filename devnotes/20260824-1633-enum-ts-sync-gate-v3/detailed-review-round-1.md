## レビュー前提

仮説は「正典 v3 へ広げる方向性は妥当だが、仮想 `.svelte` の型世界・母集団・負の対照が実装時に fail-open になっていないか」です。

成功条件を次のように置いて確認しました。

- 版管理下の対象が欠落しない
- 解決不能が「非候補」と混ざらない
- Svelte 本来のスコープと名前解決を壊さない
- 負例が設計した判定式で本当に不成立になる
- 各段階の「先に赤、実装後に緑」が記載どおり成立する

結論として、方向性と台帳処理は概ね妥当ですが、S2・S4・S5・S9・S11 に実装前に直すべき問題があります。

---

## S1: 母集団モジュール

判定: REQUEST_CHANGES

- [Critical] S9 で追加する `fixtures/svelte/Broken.svelte` と `DuplicateContext.svelte` は、唯一の除外根の外です。S2 の仕様では前者は parse 失敗、後者も Svelte parser が重複 script として失敗するため、本番 gate が恒久的に赤くなります。

  修正案: 不正な Svelte 入力は追跡ファイルにせず、テスト内の文字列として与えてください。追跡 fixture にするなら構文破壊見本の除外根へまとめ、除外根の説明・検証処理も `.ts` と `.svelte` の両方を扱う必要があります。

- [Warning] 「版管理下の `*.ts` 全数」と「`.d.ts` は母集団に入れない」が矛盾しています。`git ls-files -- '*.ts'` は `.d.ts` も返します。

  修正案: 「起点集合には `.d.ts` を含むが候補母集団からは `isDeclarationFile` で除く」または「列挙時点で `.d.ts` を除く」のどちらかに統一し、対応する集合検査も分離してください。

- [Warning] 改行区切りで `git ls-files` を読むと、改行を含む合法なパスを全数列挙できません。

  修正案: `git ls-files -z` を使い、NUL で分割してください。

- [Warning] 空の一時ディレクトリを `root` に渡す試験は「0 件」ではなく「Git repository でない」という別の失敗になります。

  修正案: 列挙結果を注入できる純関数へ分けるか、一時 Git repository に空の index を用意し、「正常終了したが対象 0 件」の分岐を検査してください。

---

## S2: `.svelte` の仮想 TS 化

判定: REQUEST_CHANGES

- [Critical] 文脈別の仮想ファイルを単純に独立した `.ts` として program に載せると、Svelte のスコープを再現できません。

  問題は二つあります。

  1. import/export のない各コンポーネントの instance script が TypeScript 上のグローバル script になり、別コンポーネント同士の宣言が衝突・結合する。
  2. Svelte では module script の宣言を instance script から参照できますが、別ファイル化するとその参照が解決できない。

  修正案: `svelte2tsx` 相当の変換と source map を利用するか、少なくとも「コンポーネント間は外部モジュールとして隔離」「module → instance の参照は維持」「instance → module の逆方向は不可」を満たす仮想化方式を設計してください。次の検査が必要です。

  - import/export のない二つのコンポーネントに同名宣言があっても干渉しない
  - instance script が module script の型別名を参照できる
  - module/instance の同名宣言は正しい shadowing になる
  - 別コンポーネントの型が証人や候補の型解決へ混入しない

- [Warning] 詳細設計へ持ち越したはずの `lang="ts"`、属性順、`context="module"`、`module`、`generics`、未知属性の扱いが未確定です。`src` だけを拒否しても条件を満たしません。

  修正案: script 属性の受理表を明示し、`root.instance/module` の `attributes` を検査してください。現在の130ファイルで実在する属性も測定対象に含めます。

- [Warning] 「`\n` 以外を空白化」だけでは、孤立した `\r`、Unicode の U+2028/U+2029、サロゲートペアを含む場合の位置保存が曖昧です。

  修正案: UTF-16 code unit 長を変えず、TypeScript が改行と認識する文字をすべて保存する実装にしてください。LF/CRLF/孤立CR/非BMP文字を含む行・列試験を追加します。

- [Warning] `parse()` は script だけでなく markup やテンプレート式も構文解析します。「目印の中は見ない」という記述だけでは、そこで構文が壊れた場合も失敗する実態が伝わりません。

  修正案: 「候補抽出はしないが、Svelte 全体の parse 成功は前提」と保証範囲に明記してください。

`parse(source, { modern: true })` と `content.start/end` を使う方向自体は妥当です。

---

## S3: program の起点拡大

判定: REQUEST_CHANGES

- [Critical] `packages/cli` をルートの `moduleResolution: bundler` で解決し、意味診断を見ない設計では、NodeNext 前提の参照が `any` や error type になり得ます。その結果、候補らしい型別名や計算キーが「文字列リテラルへ解決しなかった非候補」として静かに消えます。docblock で保証外にするだけでは、版管理下全数を型情報で走査するという主張と両立しません。

  修正案は次のどちらかです。

  - package ごとに最寄りの tsconfig で Program を作り、候補を統合する
  - 単一 Program を維持するなら、候補らしい構文の型が `any`、`unknown`、error type、未解決 symbol になった場合は解析不能として落とす

  少なくとも NodeNext 固有の import を経由する型別名・計算キーの fixture が必要です。

- [Warning] `program.getSourceFiles()` の非宣言ファイル集合を母集団と一致させる検査は成立しません。Program には依存ライブラリ、推移的 import、JSON/JS などが載る可能性があり、逆に `.d.ts` は非宣言集合から落ちます。

  修正案:

  - `program.getRootFileNames()` は期待する起点集合と比較する
  - 候補走査対象は population が返した正規化パス集合と仮想パス集合に限定する
  - `getSourceFiles()` 全体は母集団一致の根拠にしない

- [Warning] CompilerHost の `fileExists` / `readFile` / `getSourceFile` 差し替え自体は妥当ですが、パスの正規化・case sensitivity と delegate 前の仮想 map 照合を固定する必要があります。

  修正案: host の canonical path 規則に合わせた map key を使い、仮想ファイルの `SourceFile.fileName` が `virtualPaths` の key と完全一致する試験を追加してください。

---

## S4: 4形の候補走査と派生除外

判定: REQUEST_CHANGES

- [Critical] `as const` と `satisfies` を受理するとしながら、骨子では initializer を直接 `ArrayLiteralExpression` / `ObjectLiteralExpression` として扱っています。実際には `AsExpression`、`SatisfiesExpression`、`ParenthesizedExpression` に包まれます。

  修正案: wrapper を順に剥がす共通関数を作り、値の構文と明示型を別々に取得してください。特に `satisfies` の型は `checker.getTypeAtLocation(initializer)` ではなく、`SatisfiesExpression.type` を `getTypeFromTypeNode()` で解決します。

- [Warning] `getIndexInfoOfType`、`getPropertiesOfType`、`SymbolFlags.Optional` は利用可能な公開 API で、基本方針は妥当です。ただし派生判定では次の三集合の一致を明示する必要があります。

  - オブジェクトに実際に書かれたキー
  - 明示型の必須プロパティ
  - 非 `object-keys` 証人の値

  修正案: 三集合が完全一致したときだけ除外してください。意味診断を見ない以上、明示型に対する余剰キーや欠落キーを前提にしてはいけません。

- [Warning] `const-array` という名前なのに `let` / `var` も受理する条件になっています。

  修正案: 正典の「定数の配列」が const binding を意味するなら `NodeFlags.Const` を必須にしてください。可変配列も意図的に拾うなら shape 名と文書を変更します。

- [Warning] switch の式は構文的に正常なら `getText(source).trim()` が空になることは通常ありません。したがって「名前解決不能」分岐と故障注入8は、現在の形では実質的に到達不能です。

  修正案: fail-closed の対象を「型名も、許可した式形から安定した名前も得られない」に変更してください。任意の式テキストを名前として受けるなら、それは名前解決成功ではないため、保証を狭める必要があります。

- [Warning] 型別名や計算キーが error type に解決された場合を単なる非候補にすると、共通規約(b)に抵触します。

  修正案: 構文上候補になり得る形と、正常に非候補と判定した形と、解決不能の三値を分けてください。

非 `object-keys` だけを証人にする2パス方式は、自己・相互・3件循環を構造的に断つ設計として妥当です。

---

## S5: 規則2の論理和

判定: REQUEST_CHANGES

- [Critical] 共通規約(e)向けの負例が、記載された判定式では負例になりません。

  - `DraftJobStatus` → `[draft, job, statu]`
  - `NonJobStatus` → `[non, job, statu]`
  - `JobStatus` → `[job, statu]`

  いずれも主要語が一致し、共通語数も2なので、前二つは2bで成立します。

  修正案: 次のどちらかを選んでください。

  - `NonJobStatus` 等を本当に拒否するなら、否定語を明示的な拒否条件にする。ただし `DashboardJobStatus` を通しつつ `DraftJobStatus` を落とす一般則は別途必要です。
  - 現在の式を維持するなら、接頭辞・打ち消しの負例を「許可語を部分文字列として含むがトークン完全一致しない形」に変更する。例として `PrejobStatus`、`JobNonstatus` などを使い、分割結果もテストで固定します。

- [Warning] 単数化で `Status` は `statu`、`Statuses` は `statuse` になり、単数・複数が一致しません。

  修正案: 少なくとも `status/statuses`、`class/classes`、`policy/policies` の期待結果を先に固定し、規則順を修正してください。限定的な正規化であることは維持して構いません。

- [Warning] 2a では `switch:` を外しますが、2bで外すことが明記されていません。`:` 自体も区切り集合にありません。

  修正案: 両規則に共通する候補名取得処理で `switch:` を外してから分割してください。

- [Warning] `$` だけの識別子など、分割後の語列が空になる候補の扱いが未定義です。

  修正案: 宣言名から主要語を得られなければ解析不能として落とすか、保証外として明示してください。静かに名前不一致へ混ぜないことが必要です。

両側 `ceil(size/2)` の閾値と2a優先の排他順序自体は明確です。

---

## S6: 目録の受理範囲拡大

判定: REQUEST_CHANGES

- [Warning] 複数の TS root を導入した後、symlink 検査へ渡す「その行が一致した root」の選び方が骨子にありません。誤った root と比較すると拒否漏れまたは正常行の誤拒否になります。

  修正案: lexical containment で一致した root を一つに確定し、その同じ root を realpath 検査へ渡してください。`packages/cli/src` 内のファイルから外部へ抜ける symlink、`packages/cli/src` 自体が symlink の場合も負例に追加します。

- [Suggestion] `listPackageSrcRoots()` は順序を sort し、通常ディレクトリだけを返すと診断が安定します。

`resources/js` と `packages/*/src` だけを許し、`packages/*/tests` を拒否する境界は妥当です。

---

## S7: 前向き検査の `.svelte` 対応

判定: REQUEST_CHANGES

- [Warning] `.svelte` が `virtualPaths` に一件も存在しない場合、現在の骨子では「型別名がない」と「program/仮想化の欠落」が同じ結果になります。

  修正案: `.svelte` に対応する仮想単位が0件なら、まず「仮想単位が program に載っていない」として落としてください。仮想単位はあるが宣言が0件の場合だけ「型別名が見つからない」とします。

- [Warning] S2 の module/instance 間の名前解決が直るまでは、この前向き抽出も同じ不正確な型世界を共有します。

  修正案: S2 の修正後、module 宣言を instance 側の型別名が参照する前向き fixture を追加してください。

仮想単位をまたいで同名宣言の合計を数え、2件なら曖昧として落とす判断は妥当です。

---

## S8: 逆走査 gate の再整備

判定: REQUEST_CHANGES

- [Critical] 段6の「申告を7件へ整備すると緑」は成立しません。実測8件のうち既存免除は1件です。S8で合計7件へ増やしても、`ApiErrorCode` の1件は未登録・未免除のまま残ります。緑になるのはS11で登録と追加免除を入れた後です。

  修正案: 実装順序を変更するか、「段6では ApiErrorCode だけを残して赤を維持し、S11後に初めて統合 gate が緑になる」と明記してください。

- [Warning] 「現行1件→6件」と書きながら表は7件、pinも7です。

  修正案: 「新規6件を追加し、合計7件」に統一してください。

- [Warning] Notification の将来変化を「規則1→2a」とする説明は誤りです。2aは `switch:` を外しても `notification.type` と `notificationtype` を同一視しません。

  修正案: 単に「完全一致が崩れるため rule=`1` の申告が stale になる」と説明してください。2bへ移る保証を主張するなら、S5の分割規則を確定したうえで専用テストが必要です。

- [Warning] 除外根の全件構文破壊検査は、将来 `.svelte` を除外へ入れた場合に TypeScript の構文診断だけでは検証できません。

  修正案: 拡張子ごとに本番と同じ入口を使い、`.ts` は TS diagnostics、`.svelte` は `toVirtualUnits()` の parse 失敗で検証してください。

- [Warning] `ResolvedPhpEnum.line` の追加は、全 fixture builder、純関数テストの helper、手書きオブジェクトにも波及しますが変更対象に明記されていません。

  修正案: 型の全コンストラクタを波及変更一覧へ追加してください。

---

## S9: 検出器の自己検査

判定: REQUEST_CHANGES

- [Critical] `Broken.svelte` と `DuplicateContext.svelte` を通常の `fixtures/svelte` に追跡配置すると、本番母集団に入り、S2/S3の構築自体が必ず失敗します。

  修正案: 不正入力はテスト内文字列にしてください。正常な `Sample.svelte` だけを追跡 fixture に残すのが最小です。

- [Warning] 故障注入の実現方法が未確定です。特に2・4・6・7・8は「述語を差し替える」「見本を壊す」だけでは継続的な検査として再現できません。

  修正案: 各故障注入について、対象の純関数・注入点・期待するテスト名を表にしてください。production APIに任意の挙動差し替え口を増やすのではなく、派生判定、証人索引、申告監査、名前抽出を純関数へ分けて直接検査するのが安全です。

- [Warning] 故障注入8の「名前解決不能」はS4の現行骨子では到達不能です。

  修正案: 先に名前取得の受理形を限定し、限定外の式で確実に解析不能になる fixture を定義してください。

既存D1〜D18、E1〜E11を残す方針は適切です。

---

## S10: 前向き gate の負の対照

判定: REQUEST_CHANGES

- [Warning] 実装内容は妥当ですが、テストファーストの順序が逆です。段7でS6を実装した後、段10で新しい診断文を期待するテストを書いても最初から緑になります。

  修正案: S10の二つの負例をS6の実装前に追加し、S6と同じ赤→緑の単位として扱ってください。

- [Suggestion] 「これ以外は1行も変えない」としながら新しいテストを1件追加する記述は、「既存ケースは期待文言以外を変えない」に直すと正確です。

---

## S11: CLI の符号一覧

判定: REQUEST_CHANGES

- [Critical] `CliLocalErrorCode` という名前が役割と一致していません。列挙された符号はCLI内部で生成する符号ではなく、enum外のcontroller/API endpointが返すサーバ側符号です。

  修正案: `EndpointLocalApiErrorCode`、`NonCanonicalApiErrorCode` など、発生源を正しく表す名前にしてください。

- [Warning] `ApiErrorCode` は「広がるだけ」ではありません。`rate_limit_exceeded` を削除するため、型としては破壊的変更です。また、exportされた `API_ERROR_CODES` の削除はrepository内参照が0件でも、公開package利用者には実行時APIの削除になり得ます。

  修正案: packageのexport mapと公開契約を確認し、非公開ならその根拠を設計に記載してください。公開済みならversioningまたは移行方針が必要です。「後方互換を残さない」は外部利用者影響の確認を省略する根拠にはなりません。

- [Warning] `rate_limit_exceeded` を落としても429 fallbackで同じ分類になる、という重要な判断をテスト計画が直接固定していません。

  修正案: 次を追加してください。

  - `rate_limited` + 429 → `rate-limit`
  - 旧 `rate_limit_exceeded` + 429 → 未知コードとしてHTTP fallbackし `rate-limit`
  - 未知コード + 非429 → その状態番号に対応する分類
  - `insufficient_ability`、`actor_not_resolvable`、idempotency系の期待分類

- [Warning] S11の最初の赤も記載どおりになりません。存在しない `CanonicalApiErrorCode` を目録へ先に足すと、「古い値との不一致」ではなく「宣言が見つからない」で落ちます。

  修正案: 最初の赤はCLIの `rate_limited` 分類テストにするか、旧値を持つ `CanonicalApiErrorCode` を先に定義したうえで値不一致を確認する、と順序を正確に書いてください。

`rate_limit_exceeded` の分岐を残さず `rate_limited` へ置換する判断自体は、429 fallbackの契約と公開互換性が上記テストで固定されれば妥当です。

---

## S12: 乖離台帳

判定: APPROVE

D50を新設し、変更済みファイルを採用時債務に残さない処理は3-0段と整合しています。D50の理由、維持する不変条件、対象パスも具体的です。

- [Suggestion] 件数46→47、148→147は設計時点の値として扱い、実装開始時とmerge直前に現物から再計算してください。
- [Suggestion] D50には「正本レーンが `pnpm test` でありcomposer側ではない」ことも維持条件として入れると、レーンの非対称が台帳から追跡できます。

---

## S13: 文書更新

判定: APPROVE

正典v3を転載せず、aicue固有の受理範囲と除外だけを書く方針は適切です。

- [Suggestion] S8のdocblockと `docs/architecture.md` に「同じ内容を複製する」のではなく、docblockには実装に密着した短い保証範囲、architecture側には理由と全体像を置き、相互参照してください。
- [Suggestion] S2修正後は「Svelte全体のparse成功を要求するが、script外の値は候補抽出しない」という区別を文書にも反映してください。

---

## AGENTS.md 共通規約への適合評価

- (a) 完全修飾名・名前解決: TypeChecker利用の方向は妥当。ただし異なるtsconfigを単一の型世界で読むため、解決失敗の扱いが不足しています。
- (b) fail-closed: S3のerror type、S4の候補らしい未解決形、S7の仮想単位欠落が解決済み非候補と混ざる可能性があります。
- (c) 両方向の負例: S5の接頭辞・打ち消し負例が実際には正例になるため、現状は不適合です。
- (d) 収集結果の利用: `virtualPaths`、候補、差分、exemptionの用途は概ね明確です。ただしprogram全sourceと候補母集団を混同しない分離が必要です。
- (e) トークン完全一致: 区切り宣言はありますが、負例3形のうち2形が数式と矛盾しているため不適合です。
- 新設変更時の4点: 正例・空振り・docblockは計画されていますが、S9の不正fixtureが本番を壊し、複数の「先に赤」が実際の実装順と一致していません。

## 全体判定

CHANGES_REQUESTED

最優先の修正は次の5点です。

1. 不正な `.svelte` fixtureを本番母集団へ入れない
2. Svelteのコンポーネント間隔離とmodule→instance名前解決を正しくモデル化する
3. `as const` / `satisfies` のwrapperと解決不能型を正しく扱う
4. S5の負例と単数化を判定式に一致させる
5. S8〜S11の赤→緑順序とCLI公開互換性を整理する

これらを直せば、正典v3への追従方針、2パスの循環遮断、乖離台帳の処理は採用可能です。