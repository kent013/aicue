# 対応マトリクス: impl-review Round 1

Codex (`gpt-5.6-sol` / reasoning=high) の全体判定は **APPROVED**。
Critical / Warning / Suggestion はいずれも 0 件だったため、修正対応は無い。

## 指摘の集計

| 分類 | 件数 | 対応 |
|---|---|---|
| Critical | 0 | — |
| Warning | 0 | — |
| Suggestion | 0 | — |

## 実装時に設計から外した 3 点への判定

いずれも Codex が「妥当」と判定した。判断を維持する。

### 逸脱 1: 無効化コメント 3 形式の選び直し
- 判断: 維持する
- 根拠: 詳細設計が挙げた HTML コメント 3 形式は、実測の結果この lint 構成では
  **対照条件 (noInlineConfig:false) でも 1 つも rule を無効化できなかった**
  (`svelte/comment-directive` を有効化していないため directive として解釈されない)。
  「元から解釈されていない文字列」を負例と称すると検出力が空振りする。
  設計書の施策 4 リスク欄が「B' が赤くなる = 形式を選び直せという信号」と予告していた分岐。
- 対応内容: DISABLE_FORMS を script ブロック内の JS コメント 3 形式
  (`/* eslint-disable */` / `/* eslint-disable svelte/no-at-html-tags */` /
  `/* eslint svelte/no-at-html-tags: "off" */`) に差し替え、
  「noInlineConfig:true では効かない / false では効く」の両方向を実測で固定した。
  併せて検査 B'' を新設し、HTML コメント形式が対照条件でも効かないことを固定した
  (将来 comment-directive を有効化したら B'' が赤くなり、その形式を DISABLE_FORMS へ
  移す信号になる)。

### 逸脱 2: 画面テストの「script が生えない」検査の書き方
- 判断: 維持する
- 根拠: 画面全体には Lucide アイコンの `<svg>` が実在するため、
  `container.querySelector("svg")` は置換後も非 null になり検査が成立しない。
- 対応内容: QR 要素の部分木に子要素が 1 つも無いこと (`qr.querySelectorAll("*")` が空) +
  画面全体に `<script>` が無いこと、の 2 点に変えた。
  部品テスト側 (`QrCodeImage.test.ts`) は container に Lucide が居ないので、
  `<svg>` / `<script>` の非存在を直接固定している。

### 逸脱 3: `containsRawHtmlSink()` の実装
- 判断: 維持する
- 根拠: 本 gate は自分自身を走査根に含めないので、字面を隠すための文字列連結
  (`"{" + "@html"`) を採る理由が無い。直接表記の方が検出契約を読み取りやすい。

## 乖離台帳の判断

Codex も「D11 は既存の `no-undef` 系逸脱の記録であり、正典追従の規則追加によって
件数や主張を変更する必要はない。台帳を動かさない判断は妥当」と支持した。
`LedgerPins` の 3 つの件数 pin (36 / 281 / 171) はいずれも変更しない。
