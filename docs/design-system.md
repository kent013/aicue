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

## トークン変更時の運用契約

トークン(color / font / radius / typography ramp)を変更する PR は以下を**同一 PR 内で**更新する:

- [ ] `/DESIGN.md` の該当 token の値および `tailwind:` 行
- [ ] `/resources/css/tokens.css` の `@theme` / `@utility` 該当ブロック
- [ ] `/tests/js/styles/inventory.ts`(トークンの追加・削除時)
- [ ] テーマ由来の制約を変える場合は `/tests/js/support/ds-purity.ts` の THEME_PATTERNS

片方だけ更新する PR は merge しない(parity テストが落ちる)。

## テーマの差し替え方(テンプレート派生アプリ向け)

既定テーマ(Slate × Blue)は**色値だけ**差し替えれば変えられる:

1. `DESIGN.md` frontmatter の colors と本文の色記述を更新
2. `tokens.css` の `--color-*` を同じ値に更新
3. parity テスト green を確認

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
