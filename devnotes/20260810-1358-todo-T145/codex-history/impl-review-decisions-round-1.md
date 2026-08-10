# 対応マトリクス: impl-review Round 1

Codex 全体判定は **APPROVED** (Critical 0)。ただし Warning 3 件は「gate が exact-fit を
名乗る以上ここは塞ぐべき」という筋の通った指摘であり、いずれも**検査ファイル内で完結**して
極小 PR のスコープを広げないため、この PR 内で対応した。

## [Warning] `BillingRetention` の alias import を検出できない (目録の迂回)

- 判断: **対応する**
- 根拠: `use ... as Retention;` で書けば呼び出し元目録を素通りできる。「exact-fit の目録」を
  名乗っている以上、この穴は主張と実態の食い違いそのものである (誇張しない原則)。
- 対応内容: `billingRetentionAliasNames()` を追加し、token 列から
  `use ...\BillingRetention as X;` の X を集めて呼び出し検出に含めた。
  - 負のコントロールに alias fixture を追加 (`ssotCall === 2` / alias 名 `['Retention']`)。
  - **mutation 実測**: 既存 caller を alias 形に書き換えても目録は緑のまま (消えない)。
    alias 経由で `years()` を呼ぶ新ファイルを足すと**検査 6 が赤** (未登録の呼び出し元)。

## [Warning] 自己参照コントロールが弱い (prose detector を gate 自身に当てていない)

- 判断: **対応する**
- 根拠: 本リポジトリの gate 書式は「自己参照コントロール」を必須要件として挙げている。
  prose detector は生ソース regex なので、gate 自身を母集団に入れると**自分の fixture で
  偽赤になる** — これは「母集団を privacy blade 1 本に限る」判断の裏返しであり、
  暗黙にせず検査として明示するのが正しい。
- 対応内容: 検査 7 の末尾で `BILLING_RETENTION_GATE_FILE` に prose detector を当て、
  **必ず点灯すること**を assert した。これで (1) 検出器が実ファイル相手に生きている
  (2) 母集団を 1 本に限った理由、が同時に固定される。

## [Warning] 年数検査が部分一致 (`17年` / `70年` を通す)

- 判断: **対応する**
- 根拠: 三者一致 gate の核心が「宣言された年数」の照合である以上、`17年` を `7年` と
  読む検出器では役目を果たさない。**偽緑 (誤表示を通す)** と **偽赤 (無関係な数字で落ちる)**
  の両方を生む。
- 対応内容: Architecture 側 (`billingRetentionProseYearLiterals`) と Feature 側
  (`privacyRetentionDeclaresYears`) の双方に**数字境界** `(?<![0-9０-９])…(?![0-9０-９])` を入れた。
  負のコントロールに `17年` / `70年` / `１７年` を追加。

## [Suggestion] `id="retention"` が見出し要素であることを見ていない

- 判断: **対応する** (コストがゼロに近く、SoT の「節見出し」という語に忠実になる)
- 対応内容: `privacyRetentionHeading()` が `h1`〜`h6` に限定して返すようにし、
  `<p id="retention">` を通さないことを負のコントロールで固定した。
  **`h2` 固定にはしない** — 見出しレベルの変更は文面の意味を変えないため、そこまで縛ると
  偽赤の元になる (「番号ではなく属性で照合する」という設計方針と同じ理由)。

## [Suggestion] docs/architecture.md・blade の記述は過大主張なし

- 判断: **対応不要** (指摘は肯定的評価)

## スコープ外として持ち帰らなかったもの

なし。Codex はスコープ拡大の提案を出していない。
