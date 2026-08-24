## レビュー前提

仮説は「各 gate が、解析不能・母集団追加・正本外参照を必ず赤にし、負のコントロールが実装本体を直接検証しているなら、正典 i1〜i22 に追従できる」です。

提供テキストのみで検証しました。コマンド実行・ファイル読み込み・書き込みは行っていません。設計書で言及された `app-design` スキルはこのセッションでは提供されていないため、記載された規約を直接レビュー基準として扱いました。

## 施策別判定

### S1 — REQUEST_CHANGES

[Warning] 固定検体を解析する入口が公開 API にありません。提示 API は実ファイルを直接読む `themeBlocks()` 等だけなので、`theme-map.test.ts` の任意入力を検査できません。

修正案: 純粋関数 `parseThemeMap(source, file)` を唯一の解析実装とし、実ファイル用関数はその薄いラッパーにしてください。固定検体も同じ純粋関数へ渡します。

[Warning] `@theme` の検出について、コメント・文字列・トップレベル判定の仕様が不足しています。単純な正規表現ではコメント中の `@theme` を拾う可能性があります。

修正案: コメント除去を含む字句走査を定義し、コメント内の `@theme`、トップレベル、ネスト、閉じないブロックを固定検体へ追加してください。

---

### S2 — REQUEST_CHANGES

[Critical] `ClassUsageScan` に全 class token の出力がありません。S3 は `border-*`、`ring-*`、radius、ramp、非 token 語を含む全参照を検査する必要がありますが、公開結果は pair・不完全数・未解決語だけです。このままではS3が2本目の走査器を書くことになります。

修正案: `ClassTokenOccurrence { file, literalId, raw, variants, important, utility, alpha }` のような共通出力を追加し、S3/S5/S7が同じ抽出結果から導出する構造にしてください。

[Critical] 走査根が `components/pages/lib` に固定され、docblockの「resources/js の走査分母」と一致しません。`resources/js/app.ts` や将来追加されるディレクトリから迂回できます。

修正案: `resources/js` 全体を再帰走査し、対象拡張子と除外対象を分類表から導出してください。新しい直下ディレクトリ・拡張子は未分類として失敗させます。

[Critical] 「同じ channel の不透明宣言が複数あれば判定不能」とありますが、`UndecidableReason` に対応する値がありません。設計どおり実装できない型定義です。

修正案: `multiple-foreground` / `multiple-background` を追加するか、勝敗を確定できる正式なモデルを設けてください。

[Warning] 状態の継承を固定する負例が不足しています。提示例は hover で前景・背景の両方を上書きするため、「片方だけ上書きした状態が基底のもう片方を継承する」実装不良を検出できません。

修正案: `text-text hover:bg-danger` と `bg-surface hover:text-danger` の期待ペアを追加してください。

[Warning] `incompleteOpaque.backgroundOnly > 0` などは安全不変条件ではなく現状件数です。コード改善で0件になった正常状態を赤にします。

修正案: 抽出器の固定検体で各分類分岐を点灯させ、実リポジトリに「不完全単位が必ず存在する」とは要求しないでください。

---

### S3 — REQUEST_CHANGES

[Critical] 契約表の完全一致方針と、`sm:text-center` / `!text-center` / `text-center/50` を同じ語として扱う計画が矛盾しています。特に `text-center/50` は正当な opacity 修飾ではなく、これを `text-center` として通すと未知 utility が静かに通ります。

修正案:

- variant、important、opacityを別々に解析する
- opacityは色 utilityにだけ許可する
- `NON_TOKEN_WORD_CONTRACT` は正規化前の有効な完全 token を対象にする
- `text-center/50` は負例として不合格にする

[Warning] `--app-sidebar-w` を class語と `var()` 参照で共通の無型契約表に入れると、別チャネルでの出現によって登録が生きているように見える恐れがあります。

修正案: 契約を `kind: "class-word" | "css-variable"` で判別可能にし、出現・冗長性をチャネル別に突き合わせてください。

---

### S4 — REQUEST_CHANGES

[Critical] 追加テストは `linearize()` を呼んでいないため、実装が0.03928のままでも緑です。正典 i13 の0.04045を固定できません。

修正案: 正規化済みの小数チャンネルを受ける純粋関数を切り出し、両しきい値の間にある `0.04` などで0.04045側の既知値を検査してください。8bit全値で結果が同じというテストは補助検査として残せます。

---

### S10 — REQUEST_CHANGES

[Warning] `designColors().get("primary")` と `cssColorTokens().get(...)` は `undefined` を返し得ます。文字列補間によって `"undefined"` になり、意図した解析失敗になりません。

修正案: `requiredMapValue(map, key)` のような例外を投げる共通ヘルパを使ってください。

[Critical] `primary-soft` が「primaryのRGBを12%」であることを固定していません。現状の値免除と提示テストでは、別のrgba値へ変わっても生成CSSとコントラストが偶然通れば検出できません。

修正案: rgbaを厳密に解析し、RGBが正本のprimaryと一致し、alphaが0.12であることを検査してください。二重修飾はその解析結果から実効alphaも検証します。

---

### S5 — REQUEST_CHANGES

[Critical] `bg-primary-soft` はCSS値自体にalphaを持ちますが、`ColorUse.alpha` は「修飾のalpha」としか定義されていません。さらに合成関数は `alphaHex` を要求する一方、実値は `rgba(...)` です。派生tokenをどのようにRGB＋alphaへ解決するかが欠けています。

修正案: 色を次の判別可能型へ正規化してください。

```ts
type ParsedColor =
    | { kind: "opaque"; rgb: Rgb }
    | { kind: "alpha"; rgb: Rgb; alpha: number };
```

class修飾のalphaとは別に保持し、合成時に統合してください。

[Critical] `bg-primary-soft/40` は静的に決定可能です。実効alphaは原則 `0.12 × 0.40` であり、これを `double-alpha` として判定不能台帳へ逃がすのは「静的に決められない形だけを例外にする」という i16 に反します。

修正案: S10で生成形を固定したうえで、二重alphaを合成対象として計算してください。本当に解析不能なCSS色表現だけを例外にします。

[Critical] `UNDECIDABLE_PAIR_LEDGER` の識別子が `(file, reason)` だけです。同じファイルに同じ理由の未解析箇所が増えても集合が変わらず、追加を検出できません。

修正案: 行番号を使わず、`file + reason + normalized literal/state/token` を安定識別子にするか、`file + reason + count` を完全一致で固定してください。

[Warning] 「実際に描かれるのは8bitへ丸めた値」という前提はブラウザ描画一般の保証としては強すぎます。

修正案: 「本gateが採用する近似モデル」と表現し、浮動小数合成との差で4.5境界を跨ぐペアがないことを別検査してください。

---

### S6 — REQUEST_CHANGES

値の選択と実測余裕自体は妥当です。

ただし [Warning] `--color-primary-soft` のprimary追随が機械保証されないため、「両方を直さないと赤」という説明が派生tokenについて成立しません。

修正案: S10にprimary-softのRGB・alpha同一性検査を追加したうえで実施してください。

[Suggestion] ブランド色変更は主要PWA画面の視覚確認対象を明記すると、逆引き表の目視確認が再現可能になります。

---

### S7 — REQUEST_CHANGES

[Critical] `DECLARED_CONTRAST_PAIRS` に現れたtokenを「役割分類済み」と数す設計は、役割分類の既定拒否を迂回できます。任意の新tokenを1組だけ登録すれば全色被覆を通せます。

また、`border` は「非テキスト境界」と「テキストを載せるhover背景」の複数用途を持ちます。免除と検査対象をtoken単位で排他にするのは、別物の用途を統合しています。

修正案: tokenごとの用途を複数分類できる台帳にしてください。例:

```ts
border: ["non-text-boundary", "declared-text-background"]
```

全tokenの役割分類と、個別ペアの妥当性は別の集合一致で検査します。

[Warning] 実施順はS7→S3ですが、S7のテスト計画はS3後に現れる `(surface, primary)` の赤を前提にしています。

修正案: S3→S7へ入れ替えるか、S7をborder分類とsurface分類に分割してください。

---

### S8 — REQUEST_CHANGES

[Warning] サブディレクトリ分類の再帰境界が不明です。`features` を除外した後にその子を走査しないのか、`atoms/icons` のような例外だけ再帰するのかが実装者依存になります。

修正案: 「直下を分類し、excludedでは再帰停止、documentedでは直下ファイルのみ」のように探索規則を明記し、未分類のネストを固定検体で落としてください。

[Warning] DragHandleの「disabledにしない」を禁止事項8の帰結とするのは過剰です。禁止事項8は「必須条件未充足を理由にボタンをdisabledにする」ことを禁じており、全コントロールのdisabledを禁止していません。

修正案: 「並べ替え不可時の表現は別途定義し、禁止事項8を一般的disabled禁止として扱わない」に訂正してください。

---

### S9 — REQUEST_CHANGES

[Critical] 状態遷移とテスト計画が矛盾しています。

- 実装説明: 直前の描画行が空行のときだけ字下げコード開始
- テスト説明: 空行なしの4空白行も落とす

両方を同時には満たせません。

修正案: CommonMark上で描画されないものを落とすのが目的なら、可能ならCommonMark parserのASTとsource rangeを利用してください。独自状態機械を維持する場合は、段落・リスト・空行の扱いを一意に定め、少なくとも次を別々に固定します。

- 空行後のindented code
- 段落継続行
- リスト配下のindented content
- code区間内のfence文字列
- EOFまで閉じない区間
- 行数保存

---

### S11 — REQUEST_CHANGES

[Critical] 本数が算術的に誤っています。既存4本に新規3本を追加するため、責務境界表は6本ではなく7本です。

修正案: 「4本→7本」に訂正してください。可能なら説明中の固定数自体をやめ、「下表の全検査」と表現し、表の双方向集合一致だけを正本にしてください。

---

### S12 — REQUEST_CHANGES

[Warning] D50の説明はコントラスト・逆向き被覆だけですが、`docs/design-system.md` には責務表、Markdown非描画領域、component parity、運用契約という別の変更理由も入ります。パス単位で採用時債務を解除するのに、登録理由が変更全体を説明していません。

修正案: D50の説明・再判定条件を共有文書の全変更へ広げるか、論理的に別の逸脱なら複数エントリへ分けてください。S4/S5/S7/S9/S11の修正後に保証文も同期させます。

## 横断評価

- PHPStan・DTO/JsonResource・Inertia Props・DB・tenant認可への直接変更はなく、この範囲の問題は見当たりません。
- Atomic Design上、新規アプリcomponentはありません。ただしS8のディレクトリ分類規則は明確化が必要です。
- 最大の後退リスクは、正規表現ベースのclass/CSS解析が解析不能を検出できず「候補なし」として落とすことです。
- テストファーストの意図は明確ですが、S4のように本体を呼ばない負例や、S9のように仕様と矛盾する負例は i18 の裏取りになりません。

## 全体判定

**CHANGES_REQUESTED**

特にブロッキングなのは、S2の共通走査出力と母集団、S4のしきい値を実装へ結び付ける検査、S5の派生alpha・二重alpha・台帳識別子、S7の用途分類、S9のMarkdown状態遷移、S11の検査本数です。これらを直せば、正典v1へ追従する方向性そのものは妥当です。