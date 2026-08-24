# 対応マトリクス: design-review Round 2

すべて「対応する」。うち 3 件は設計そのものを変更した。

## [Critical] S2: module/instance を同一スコープへ平坦化すると逆方向参照と shadowing を誤解決する
- 判断: 対応する (提案の 2 = fail-closed 方式を採用)
- 根拠: 指摘のとおり。平坦化で残る食い違いは 2 つ (module から実体側の宣言が前方参照で見える /
  同名の最上位束縛が shadowing でなく重複宣言になる)。現物では該当 0 件であることを実測した
  (module script を持つのは `atoms/Alert.svelte` と `templates/_helpers/SidebarNavItems.svelte` の
  2 本で、中身はどちらも型の宣言だけで実体側を参照しない)。
- 対応内容: **保証外にせず不合格条件にした**。
  - 検査 A (構築時): module 範囲と実体範囲の最上位束縛名の交わりが空でなければ例外
  - 検査 B (gate): module 範囲の識別子の解決先が実体範囲の中なら例外
  食い違いの表 (Svelte 本来 / 平坦化した TS / 対処) を設計へ入れ、
  「module から実体は見えない」負例を必須にした (テスト内の文字列で与える)。
  実体 → module の参照は正しいので許すことも明記した。

## [Critical] S7: 素の `const X = ["a"]` は `string[]` に広げられるので TypeChecker から読めない
- 判断: 対応する (**設計を変更**。共有モジュールを新設)
- 根拠: 指摘のとおり。`as const` が無い配列は型検査器の上でリテラルが残らない。
- 対応内容: **S3b「値の構文抽出を 1 本に切り出す」** を新設し、
  `tests/js/support/enum-ts-sync/ts-literal-values.ts` に
  `unwrapInitializer` / `readConstArrayLiteralValues` / `readResolvedStringLiteralUnion` /
  `readObjectLiteralKeys` / `readSwitchCaseValues` を置いて **S4 と S7 が共有**する形にした
  (正典 i4「抽出器を 2 本持たない」)。配列の値は**構文から読む**と明記し、
  `satisfies` 付きでも同じであることも書いた。施策一覧・変更ファイル一覧・
  波及変更・後方互換の節にも追加した。

## [Critical] S11-b: `CliOAuthScope` の値域と「既定で要求するスコープ」は別概念
- 判断: 対応する (**設計を変更**。ただし専用の検査ファイルは作らない形にした)
- 根拠: 指摘は正しい。完全一致で登録すると「サーバにスコープを足したら道具も要求する」方向へ
  引っ張られ、最小権限に反する (AGENTS.md 思考原則 4)。
- 対応内容: 指摘の意図は採るが、**新しい architecture test ファイルは作らない** —
  ドメイン固有規約 19 が「個別の同期テストのファイルを増やさない (増殖を止めるのが本 gate の
  目的)」と定めているためである。代わりに**目録の行に `relation` (`"equal"` / `"subset"`) を
  足す**形にした (S7 の決めたこと 2)。
  - `DEFAULT_CLI_SCOPES` は `relation: "subset"` で登録する (TS ⊆ PHP だけを固定)
  - サーバ側の追加では赤くならず、道具がサーバに無い値を要求したときだけ赤くなる
  - 余分な 2 値 (`evaluations:run` / `pages:bulk`) は subset 違反として赤くなり、削除する
  - 逆走査は登録済みとして扱うので、**申告は要らない** (指摘の案より抑制が 1 件少ない)
  - `subset` が逃げ道になり得る (完全一致の写しを subset と偽れる) 限界を docblock に書く
  件数は指摘の概算と異なり `EXPECTED_MIRROR_COUNT` 31 / PHP 対象外 93 /
  逆走査の申告 8 になる (理由は上のとおり)。実装時に現物から数え直すことも明記済み。

## [Critical/Warning] S4: `Any | Unknown` だけでは「解決不能」と「正しく any へ解決」を分けられない
- 判断: 対応する (提案の 1 つ目 = 契約の側を変える)
- 対応内容: 呼び名を `unresolvable` → **`indeterminate` (判定保留)** に変え、
  契約を「解決できなかったものに加えて、候補かどうかを確定できない `any` / `unknown` も含む」に
  広げた。申告も `KNOWN_INDETERMINATE_TS_DECLARATIONS` / `EXPECTED_INDETERMINATE_TS_COUNT` へ改名。
  試験に「別名越しの明示 `any` (`type Dynamic = any; type X = Dynamic;`)」と
  「`any` 型の変数を計算キーにした対応表」を足した。
  `type X = any` のように構文が `any` そのものなら正常な非候補であることも明記した。

## [Warning] S5: 新しい単数化も `cases → cas` / `responses → respons` / `uses → us` と誤変換する
- 判断: 対応する (提案の 1 つ目 = 候補集合方式)
- 対応内容: 「1 つの正規形へ畳む」形をやめ、**語ごとに候補形の集合 `forms(w)` を作り、
  集合が交われば同じ語とみなす**形へ変更した。`cas` / `respons` / `us` は候補形にすぎず
  **正規形として採用しない**。主要語の一致も語袋の一致数も「対応する」で判定する。
  期待値を対応する 8 組 (`status`⇔`statuses` / `case`⇔`cases` / `response`⇔`responses` /
  `use`⇔`uses` ほか) と対応しない 2 組 (`status`⇔`state` / `code`⇔`codec`) で固定する。
  過剰検出の向きへ倒している (`us` と `uses` は対応する) ことも明記した。

## [Suggestion] S1: shallow clone でも `git ls-files` は使える
- 判断: 対応する
- 対応内容: リスク欄を「前提は git の作業ツリーと索引が使えることだけ」に直した。

## [Warning] S3: `.svelte` の所有者が常に root program になっている
- 判断: 対応する
- 対応内容: 「所有者の判定は `.ts` と `.svelte` で同じ規則を使う」と明記し、
  パッケージ配下の仮想 `.svelte` はそのパッケージの program へ載せる形にした。
  見本の木での試験も足した。

## [Warning] S3: 「ちょうど 1 本の program に載る」の意味が曖昧
- 判断: 対応する
- 対応内容: 3 層に分けた — (1) 所有者への割当はちょうど 1 件、(2) 起点としての所属もちょうど 1 件、
  (3) 推移的な取り込みで別の program にも現れることは**許す**。
  「候補走査は所有者の program 上の `SourceFile` だけを使う」「`getSourceFiles()` 全体を
  母集団の一致根拠にしない」と固定した。

## [Warning] S3: canonical key と生の `fileName` は一致するとは限らない
- 判断: 対応する
- 対応内容: 照合を**両側 `getCanonicalFileName()` を通してから**行う形に直し、
  テストの主張も `getCanonicalFileName(SourceFile.fileName)` と鍵の一致へ変えた。

## [Warning] S4: `EnumLiteral` の拒否を 4 形のどれに適用するか曖昧
- 判断: 対応する
- 対応内容: **4 形すべてに適用する**と明記し、形ごとの期待結果を表にした
  (`const-array` は要素が識別子になるので `not-a-catalogue`、ほかは非候補 / 受理しない)。

## [Warning] S8: 段 6 で追加する申告の件数の説明が揺れている
- 判断: 対応する (提案の 2 つ目を採用)
- 対応内容: **段 6 ではドリフトでない 6 件だけ**を追加し、段 9 で登録 2 件 + 申告 1 件を扱う形に
  統一した。実装の順序の表に「段 6 と段 9 の内訳」の節を足し、
  9 件 = 6 件 (段 6) + 3 件 (段 9) の対応を明示した。

## [Warning] S8: S11-b の変更で最終件数が変わる
- 判断: 対応する
- 対応内容: 件数の表 (8 つの pin) を実装の順序の節へ置き、
  `relation: "subset"` を採った結果の値へ揃えた
  (`MIRROR_COUNT` 31 / PHP 対象外 93 / 逆走査の申告 8 / 判定保留 3 / 除外根 1 / 台帳 47・147)。

## [Warning] S9: 「差し替え口を増やさない」と表の「述語を渡す」が矛盾する
- 判断: 対応する
- 対応内容: 本番の入口 (`collectTsCandidates`) は戦略を固定し、
  自己検査は**純関数へ入力のデータを渡して**判定を突く形へ書き直した。
  表の「与える入力」列をすべて**データ**にし、
  故障注入の実体は「本体を一時的に壊して赤を確認する」ことであると明記した。

## [Warning] S9: 故障注入の件数の呼び方
- 判断: 対応する
- 対応内容: 「**8 カテゴリ + 境界試験 2 件**」に統一し、表にも `1'` / `4'` / `8'` を並べた。

## [Warning] S11-a: 新しい正規符号の期待分類が決まっていない
- 判断: 対応する
- 対応内容: `defaultStatus()` と `dispatchKindFromStatus()` の対応から 4 値の分類を確定し、
  表にした (`insufficient_ability` → `auth` / `actor_not_resolvable` → `auth` /
  `idempotency_in_progress` → `conflict` / `idempotency_indeterminate` → `conflict`)。
  「符号の経路と状態番号の経路のどちらでも同じ分類になる」ことも書いた。

## [Suggestion] S11-a: 最初の赤を純関数の試験にする
- 判断: 対応する
- 対応内容: 最初の赤を `dispatchKindFromCode("rate_limited")` の直接試験へ変更した
  (「符号が効いたのか状態番号が効いたのか」が曖昧にならない)。
  不自然な `rate_limited + 非 429` の組は落とし、
  応答の単位では「旧綴り + 429 でも失敗の種類が変わらない」ことの固定に置き換えた。

## [Warning] S11-b: 道具側の単体で期待 4 値を複製しかねない
- 判断: 対応する (`relation: "subset"` により解消)
- 対応内容: subset の検査は**目録と PHP 走査器・TS 抽出器を共有する既存の gate**が行うので、
  期待値を道具側へ複製しない。道具側の単体試験は「OAuth 要求へ渡す集合の振る舞い」だけを持つ。

## [Warning] S13: 誤った設計が文書化される / Svelte の書き方
- 判断: 対応する
- 対応内容: S13 を書き直し、`docs/architecture.md` に
  「許可する値域と、そこから選んだ集合は別の概念である」ことと、
  「`.svelte` は 1 本へ平坦化し、再現できない 2 つは**保証外ではなく不合格条件**として塞ぐ」ことを
  書くと明記した。3 か所の書き分けも表にした。
