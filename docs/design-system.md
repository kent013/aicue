# Design System 運用ガイド

## Canonical source の宣言

| 役割 | 真実のファイル |
|------|---------------|
| **設計仕様 (canonical)** | `/DESIGN.md` |
| **トークン実装写像 (mirror)** | `/resources/css/tokens.css` |
| **Tailwind エントリ** | `/resources/css/app.css` (`@import "./tokens.css"`) |
| **禁止パターン定義** | `/tests/js/support/ds-purity.ts` |
| **運用ガイド (本書)** | `/docs/design-system.md` |

DESIGN.md が唯一の真実。tokens.css はその実装写像であり、独自に値を変えてはいけない。
drift は `tests/js/styles/canonical-source-parity.test.ts` が機械検出する。

## 検査の責務境界

本節で責務境界を管理するデザイントークン検査は**下表に挙げたものがすべてである**
(DS purity 系など、トークンの値以外を見る検査は本節の管理対象ではない)。
数字は書かない — 表そのものを機械で実体と突き合わせているので、本数の記述は
「表と実体が一致していること」に何も足さないまま必ず陳腐化する。
**どれが何を見ているか**を混同しないこと — 見ている写像の段が違うので、
片方を消すと別の壊れ方が見えなくなる。

| 検査 | 見ている写像 | 代表的に検出する壊れ方 |
|------|------------|--------------------|
| `tests/js/styles/canonical-source-parity.test.ts` | DESIGN.md (正本) ⇔ tokens.css (宣言) のテキスト | 片方だけ更新した PR / トークンの増減 / 検査の母集団の取りこぼし |
| `tests/js/styles/tokens.test.ts` | tokens.css (宣言) ⇒ Tailwind 生成 CSS | `@theme` が解釈されない / utility 名が解決しない / app.css が tokens.css を取り込んでいない |
| `tests/js/styles/design-system-docs.test.ts` | 本書の構造 ⇔ 検査ファイルの実体 | 運用契約の節の消失 / 表と実体の食い違い |
| `tests/js/architecture/contrast-invariant.test.ts` | DESIGN.md の色値 ⇒ コントラスト比 (不透明ペアと半透明ペアの合成、実装からの逆向き被覆) | 読めない色の組合せ / 役割宣言を書かずに新しい前景 × 背景の組を足す |
| `tests/js/styles/theme-map.test.ts` | 写像パーサそのもの (固定検体) | `@theme` の検出・宣言の抽出・色表現の解析の退行 |
| `tests/js/styles/class-usage.test.ts` | 走査器そのもの (固定検体) と `resources/js` の解析診断 | 状態単位の分解の退行 / 未対応入口の deny の空振り |
| `tests/js/styles/token-reference-closure.test.ts` | 参照側 (resources/js / resources/css) ⇒ tokens.css の宣言集合 | token 名の綴り誤りが無スタイルとして静かに消える / 写像の外の色語 (Tailwind 既定の white 等) の混入 |
| `tests/js/styles/component-doc-parity.test.ts` | DESIGN.md §Components ⇔ resources/js/components の部品ファイル | 文書に載らない部品が増える / 節だけ残って実装が消える |

**この表は機械で実体と突き合わせている**。`tests/js/styles/` に検査を足したら本表にも行を足す
(足さないと `design-system-docs.test.ts` が落ちる)。逆に検査を消したら行も消す。
別の場所へ足す検査は `design-system-docs.test.ts` の `EXTERNAL_GATE_FILES` へ明示登録する。

本書の検査は、**規範判定対象外領域**を落としてから節と表を見る。
落とすのは HTML コメントと囲みコードの 2 つ(前者は読者に描画されず、後者は描画されるが
規範の本文として数えない。まとめてこう呼ぶ)。
落とす判定は Markdown の fence 規則に寄せてあり (字下げした偽の終端や、
情報文字列にバッククォートを含む無効な開始行では区間が閉じない・開かない)、
コメントを取り除いた跡には**規範の最小断片には使わない制御文字**を目印として残すので、
コメントを挟んだ 2 つの断片が検査の上でだけ繋がることはない。
**行頭から 3 空白までで始まる正規の囲みコード記法以外の位置に、
記号を 3 個以上連続させて書くことは禁じる**(引用やリストの中の囲みコード記法、
行の途中の連続記号、記号 3 個以上の行内コードを含む)。書かれていたら検査自体を失敗させる。
加えて**タブと 4 個以上連続した半角空白も検査自体を失敗させる**
(字下げによるコードは書かず、囲みコード記法を使うこと)。
字下げコードの位置を近似で判定すると見出し直後や引用の中の形を取りこぼし、
そこへ規範の断片を退避させられる。タブを禁じたうえで 4 連続空白を拒否すれば、
引用やリストの記号が何段入れ子になっていても字下げコードは書けないので、
**字下げについては引用やリストの文法を一切扱わずに見逃しを 0 にできる**。
ただし**完全な Markdown 解析ではない** — HTML 要素による非表示は見ていない。
そのうえで節ごとに**規範の最小断片** (`design-system-docs.test.ts` の
`SECTION_CONTRACT_PHRASES`) が本文に在ることを求めるので、契約の一文を消したり
描画されない領域へ移したりすると赤になる。**文言を直すときは同じ PR で最小断片も直す**
(それが「契約を変えた」ことの可視化になる)。

保証しないもの: Vite のビルド・アセット配信・ブラウザでの適用は**下表のどれも**見ていない。
文書側で見ているのは節の構造・表の実体・最小断片までで、**周りの説明が骨抜きになったことは
検出できない**。
DESIGN.md frontmatter の `spacing:` は**値も tokens.css への実装写像の有無も検査していない**
(未検査であることは `tests/js/styles/inventory.ts` の `FRONTMATTER_SECTION_OWNERS` に
理由・解消条件・追跡先つきで宣言してある)。

## トークン変更時の運用契約

トークン(color / font / radius / typography ramp)を変更する PR は以下を**同一 PR 内で**更新する:

- [ ] `/DESIGN.md` の該当 token の値および `tailwind:` 行
- [ ] `/resources/css/tokens.css` の `@theme` / `@utility` 該当ブロック
- [ ] `/tests/js/styles/inventory.ts`(トークンの追加・削除時。parity と生成 CSS 検査の母集団を兼ねる)
- [ ] テーマ由来の制約を変える場合は `/tests/js/support/ds-purity.ts` の THEME_PATTERNS
- [ ] トークンの**値**を変える場合は `contrast-invariant.test.ts` の不透明ペアと**半透明ペア(合成)**の両方が緑であること(ソフト背景の色は面の上での合成後の値で判定される)

片方だけ更新する PR は merge しない(parity テストが落ちる)。

## テーマの差し替え方(テンプレート派生アプリ向け)

既定テーマ(Slate × Blue)は**色値だけ**差し替えれば変えられる:

1. `DESIGN.md` frontmatter の colors と本文の色記述を更新
2. `tokens.css` の `--color-*` を同じ値に更新
3. parity テスト green を確認(**contrast-invariant の合成検査も含む**。
   状態色を明るい段に戻すとソフト背景側で落ちる)

制約体系(影なし / rounded 3 段 / weight 400-500 / ramp 必須)を変えるテーマにする場合は、
`ds-purity.ts` の **THEME_PATTERNS** を DESIGN.md と同期して書き換える。
**UNIVERSAL_PATTERNS(raw palette 禁止・hex 直書き禁止・arbitrary z 禁止・静的 inline style 禁止)
はテーマに依存しないため、どのテーマでも変更しない。**

## 新規 domain 色トークン追加の必須条件(4 条件)

以下を**すべて**満たさない限り却下する(aigenba P6 の運用実証より:
3 度の追加提案がすべて「opacity 修飾 + atom 化」で代替できた):

1. 同一 token が **3 component / 3 page 以上**で同じ意味として使われる
2. 既存の最小色構成(brand 2 + neutral 系 + state 3)と意味の重複がない
3. atom の variant 拡張 + opacity 修飾(`/10`, `/12`, `/30` 等)で表現不能である
4. DESIGN.md + tokens.css + inventory.ts + 本書を同一 PR で更新する

単一 component の識別色は file-scoped allowlist(permanent)で運用する。

## file-scoped allowlist の運用

`ds-purity.ts` の `FILE_SCOPED_ALLOWLIST` は出荷時 2 件
(`components/atoms/Avatar.svelte` と `components/atoms/Toggle.svelte`。
いずれも `rounded-full` を真円 UI の恒久例外として `lifecycle: permanent` で登録)。
例外を足すときは 7 フィールド(file / patterns / reason / owner_phase /
remove_condition / reason_classes / lifecycle)を必ず埋める。`transitional` は
撤去条件必須、`permanent` は brand 色・真円 UI(`rounded-full`)等の恒久例外のみ。

`patterns` は**区切り文字で分割した class トークンとの完全一致**で照合する。
変種の修飾や重要度の修飾が付いた形(`sm:rounded-full` / `!rounded-full`)は
**別のトークン**なので自動では免罪されず、要るならそれ自体を 1 行足して登録する。
登録した文字列が単一の class トークンとして成立していること(= 登録した瞬間に
死んでいる例外を作らないこと)は `ds-purity.test.ts` が機械で確かめる。

## コンポーネント追加時のチェックリスト

- [ ] 配置層(atoms / molecules / organisms / features / templates)。迷ったら下の層へ
- [ ] スタイルは DS token と ramp のみ(ds-purity green)
- [ ] variant→class は `Record<Variant, string> satisfies` で網羅保証
- [ ] DESIGN.md §Components に意味論・使い分けを追記
- [ ] vitest(render + 型制約 `@ts-expect-error`)を同 PR で追加
- [ ] アイコンは `@lucide/svelte` のみ(inline SVG 禁止)
