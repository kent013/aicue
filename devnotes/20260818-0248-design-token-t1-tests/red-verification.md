# 感度確認の記録 (T221)

`tokens.css` の現在の中身は既に正しいので、施策 3・4 のテストは**書いた瞬間に緑**になる。
「fail を見ていない gate」を避けるため、**故障を 1 件ずつ注入して狙った assertion が
赤くなること**を実測した。

- 実行スクリプト: `devnotes/20260818-0248-design-token-t1-tests/run-red-verification.sh`
  (一時スクリプト。`scripts/` へ昇格しない)
- 生ログ: `devnotes/20260818-0248-design-token-t1-tests/red-verification-raw.txt`
  (`.gitignore` が `*.log` を除外するため、拡張子を `.txt` にして残してある)
- 走らせた母集団: `tests/js/styles/` の 3 本 + `tests/js/architecture/contrast-invariant.test.ts`
- 注入した故障は**すべて戻してある** (基準の緑に復帰していることは末尾の確認で示す)

なお、施策 5 (`design-system-docs.test.ts`) は**本物の Red から始めている** —
`docs/design-system.md` に `## 検査の責務境界` が無い状態でテストを書き、
節の非空検査と検査目録の集合一致の 2 本が赤になることを確認してから施策 6 の文書を入れた。

## 想定と実測

本記録は **Codex 実装レビュー (Round 1・2) の修正を入れた最終コードで取り直したもの**である。
修正はいずれも走査の絞りを**強める**方向なので反応の向きは変わらないが、
記録が最終コードのものであることを担保するために再実行した
(初回実測との差は基準の件数が 117 → 130 に増えたことと、R5 が 1 件から 2 件になったことだけ)。

基準 (R0) は **4 ファイル 130 件すべて緑**である。以下は基準からの差分。

> 最終コードでは、この実測の**後**に Round 3 のレビュー対応で負の fixture を 1 件足したため、
> 同じ 4 ファイルの件数は **131 件**になっている (`final-check` の実測)。
> 足したのは「最小断片が元々空白を含む位置にコメントを置いても繋がらない」という
> 固定 fixture で、R1〜R6 の反応には影響しない。

| # | 注入した故障 | 想定した assertion (詳細設計) | 実際に落ちた assertion | 一致 |
|---|---|---|---|---|
| R0 | なし (基準) | 全緑 | 全緑 (130 passed) | ○ |
| R1 | `app.css` から `@import './tokens.css'` を消す | F (経路の層のアンカー 4 件 + `.bg-primary`) + G (先頭 2 行) | 同じ 6 件。密閉の層 (A〜E) は全緑 | ○ |
| R2 | `tokens.css` の `@theme {` を `:root {` に変える | A の色 / A の radius / A の font / C / D / F。B と E と G は緑 | 同じ 39 件 (A 色 13 + A 派生 1 + A radius 3 + A font 1 + C 14 + D 2 + F アンカー 4 + F `.bg-primary` 1)。B・E・G は緑 | ○ |
| R3 | `tokens.css` の `@utility text-body` を消す | B の text-body + 施策 4 の `@utility text-*` 集合一致 | 上記 2 件に加え、既存の parity 2 件 (`text-body` の値一致 / font-weight の値域) も赤。計 4 件 | ○ (+ 既存検査も反応) |
| R4 | `tokens.css` の `--color-danger` の値だけ変える | A の danger + 既存 parity の value parity | 上記 2 件に加え、F の「生成された自前トークンの値はすべて DESIGN.md と一致する」も赤。計 3 件 | ○ (+ 経路の層も反応) |
| R5 | `docs/design-system.md` の節を 1 つ改名する | 施策 5 の節検査 | 2 件 (節の非空 + その節の規範の最小断片。最小断片はレビュー対応で足した検査) | ○ (+ 最小断片も反応) |
| R6 | `tests/js/styles/` にダミーの `.test.ts` を置く | 施策 5 の集合一致 | 同じ 1 件 (責務境界表の 1 列目と実在する検査ファイルが集合一致する) | ○ |

### 想定と実測がずれた点 (設計より検出が広かった)

いずれも**設計の予測より 1 件多く赤くなった**方向のずれで、設計を直す必要は無いと判断した
(検出力が想定を下回った箇所は 1 つも無い)。

- **R3**: 設計は新設分だけを挙げていたが、既存の `canonical-source-parity` の
  `text-body` 値一致と font-weight 値域も赤になった。`cssRamp()` が `@utility` 不在で
  例外を投げるためで、重複ではなく段が違う (テキスト一致 ⇔ 生成 CSS)
- **R4**: 設計は A の danger と parity の value parity を挙げていたが、
  経路の層 (F) の「生成された自前トークンの値はすべて DESIGN.md と一致する」も赤になった。
  実 app.css の生成 CSS に `--color-danger` が出ているためで、設計の
  「母集団は要求しないが出ていれば値を見る」という意図どおりの挙動である

### 設計の予測が当たった重要な点

- **R2 で B と E が緑のまま**であること。`@utility` は `@theme` の破損と独立に残り、
  `.rounded-*` は Tailwind 既定テーマの `--radius-md` を参照し続けるので
  「変数を参照している」だけの検査では破損を検出できない。
  **A の値検査が唯一の検出点である**という設計の主張が実測で裏づけられた
- **R2 で `contrast-invariant` が緑のまま**であること。同検査は DESIGN.md しか読まないので
  `tokens.css` の破損には沈黙する (家系の正典リポジトリでは同検査が `tokens.css` の
  `@theme` を文字列で切り出しているため赤になる、と報告されているが、
  本リポジトリの実装は共有パーサ `design-md.ts` 経由で DESIGN.md だけを読む形なので挙動が違う)

### 一時的な注入ではなく固定 fixture で留めた負のコントロール

Codex レビューで足りないと指摘された負のコントロールのうち、
「壊れた入力を読ませたときのヘルパの挙動」に当たるものは**リポジトリのファイルを
一時的に壊す感度確認ではなく、固定 fixture として恒久的に留めた**
(毎回の `pnpm test` で走り、後から絞りを緩めると赤くなるので、こちらの方が強い)。

| 負のコントロール | 置き場所 |
|---|---|
| 4 空白字下げの偽の fence 終端 | `design-system-docs.test.ts` の `RENDER_FIXTURE` |
| 情報文字列にバッククォートを含む無効な開始 fence | 同上 |
| 行内コード中のコメントを取り除いた跡で前後が繋がらないこと | 同上 (最小断片との照合) |
| 条件つき at-rule の中の `@layer theme` | `tokens.test.ts` の `HELPER_FIXTURE` |
| 条件つき at-rule の中にしかない utility | 同上 |
| 打ち消す条件 (`@media (hover: none)`) の中の hover 宣言 | 同上 |
| 条件文が同じで at-rule の種類が違う形 (`@supports (hover: hover)`) | 同上 |
| 実在しない utility の selector が 0 件になること | 同上 (空振り防止) |

### 注入した故障を戻したことの確認

`run-red-verification.sh` は EXIT trap で必ず復元する。実行後の `git status` は
本バッチの意図した変更 (検査 2 本の新設・既存 3 ファイルへの追記・文書 2 件) だけを示し、
`resources/css/` は 1 バイトも変わっていない。

## 生ログの読み方

`red-verification.log` は注入ごとに `== R<N> …` の見出しを持ち、その下に
vitest の失敗テスト名 (`×` 行) と件数の要約が並ぶ。
