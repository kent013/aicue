# 対応マトリクス: impl-review Round 1

Codex Round 1 の全体判定は **CHANGES_REQUESTED** (Critical 0 / Warning 7 / Suggestion 2)。
**9 件すべてに対応した** (見送り 0 件)。

## [Warning] 1. S8 以降の追加を Architecture テストが禁止している

- 判断: **対応する**
- 根拠: 指摘のとおり。`test_readme_is_excluded_and_others_are_not()` の `len(names) == 7` は
  D7 (S8 以降を正規手続きで追加できる) と正面から矛盾する。AC-06 の pin を
  `PINNED_IDS` に限定して S8 を阻害しないように作ってあるのに、別テストが阻害していた。
- 対応内容: 件数の固定を削除し、「除外は閉じたリテラル 1 件だけで、他の `*.md` は全件が候補になる」
  ことの検査へ置き換えた (`glob("*.md") - EXCLUDED_FILENAMES == names`)。
  母集団の非空は `test_population_is_not_empty`、表 B との 1 対 1 は AC-05 が持つ。
  docstring に「件数を pin しない理由」を書いた。

## [Warning] 2. AC-06 の負例が「面の付け替え」を検出したことを証明していない

- 判断: **対応する**
- 根拠: 指摘のとおり。S1 だけのカード集合を 7 組の期待と比べていたので、S1 の面を
  正しい値へ戻しても不一致のまま落ちる = 共通規約 (c)「正しい理由で落ちる」を満たさない。
  この負例は `StoryFrontMatterPins::CORE_NEGATIVES` に pin されている中核なので影響が大きい。
- 対応内容: **実カード 7 枚のうち S1 の面だけを差し替える**形に直した。併せて
  **正の対照** (面を `signup_funnel` へ戻すと pin と一致する) を同じテストに置き、
  落ちた理由が面の付け替えであることを裏取りした。`assertEqual(6, len(others))` で
  母集団の取り違えも防いだ。

## [Warning] 3. 表 A / 表 B の構造検査が詳細設計より寛容

- 判断: **対応する**
- 根拠: 3 点とも実装が契約より緩かった。詳細設計は「マーカーごと。空行の位置も契約」と
  明記しており、実装がそれを検査していなかった。
- 対応内容: `marker_table()` に 3 つの判定を足した。
  (a) `text.index(begin) > text.index(end)` で **BEGIN/END の順序**を見る
  (b) 空行を除去せず `["", "", <表>, "", ""]` の**配置そのもの**を契約にし、
      表の中の空行も違反にする
  (c) 区切り行は各セルがちょうど `---` の**正準区切り行**に完全一致させる
  負例を 3 本追加した (`rejects_reversed_markers` /
  `rejects_blank_line_layout_change` / `rejects_non_canonical_separator_row`)。

## [Warning] 4. 制限文法の負例が全分岐を裏取りしていない

- 判断: **対応する**
- 根拠: 指摘のとおり。「主題に何らかの rejects がある」だけでは各不変条件の検出力を
  証明できない (共通規約 (c))。
- 対応内容: AC-01 に負例を 5 本追加した。
  - `rejects_malformed_key_value_separator` — コロン後の空白なし / 複数空白 / key 末尾空白 / コロンなし
  - `rejects_malformed_key_syntax` — 大文字・数字始まり・ハイフン・記号のみ
  - `rejects_malformed_array_syntax` — 区切りの揺れ 3 形 / ネスト / 角括弧なし / 閉じ忘れ
  - `rejects_yaml_structures` — 複数行スカラー (`|` `>`) / アンカー / 参照 / フローマップ / ネストマップ
  - `rejects_key_outside_type_sets` — 型集合への登録漏れ (下記 8 の裏取り)
  併せて `rejects_missing_delimiter` に「閉じる `---` が無い」を足した。
  **A5 を機械で閉じるために読み取り器も直した** — YAML の構造記号
  (`&` `*` `|` `>` `{` `}`) を値から締め出した。これを素のスカラーとして黙って受けると
  「アンカー・参照・複数行スカラーは書けない」と言えなくなるため。
  実カード 7 枚の全値を走査して、これらの文字を使っている値が 0 件であることを確認済み。

## [Warning] 5. `SKILL.md` と `scripts/README.md` の古い操作指示の残置

- 判断: **対応する** (当初は「設計が触らないと定めているので残置」としていた判断を撤回する)
- 根拠: 指摘のとおり。`story` を書くと exit 3 になるので静かな破損ではないが、
  **正規手順どおり操作すると必ず失敗する**状態であり、「割当の正本を一本化した」という
  完了条件に反する。採用時債務は「説明の無い食い違いを凍結する」ための一覧であって、
  運用契約を古いまま据え置く根拠にはならない。
- 対応内容: 両ファイルの該当箇所を更新し (SKILL.md 4 か所 / scripts/README.md 1 行)、
  乖離台帳の 3 択のうち**「登録を書いて債務から削る」**を採って D20 の対象パスへ移した。
  `ADOPTION_DEBT_COUNT` 168 → 166。D20 に「対象パスに運用文書 2 本を含める理由」の段を足し、
  **本エントリが説明するのは目録の生成方式に関わる記述だけである**ことを明記した
  (両ファイルの他の差分まで説明したことにしない)。

## [Warning] 6. correlate の終了コード 3 への写像がテストされていない

- 判断: **対応する**
- 根拠: 指摘のとおり。`parse_story_cell()` / `correlate()` が投げることだけを見ても
  `main()` の捕捉と写像は裏取りできず、catch を壊しても緑になる。
- 対応内容: `MainTest` に 2 本追加した。
  - `test_main_contract_violating_story_cell_returns_3` — 契約外セル 4 形
    (連続空白 / カンマ区切り / `S0` / 降順 / 重複) で `main()` が **3** を返し、
    **標準出力へ 1 バイトも出さない** (worklist 非出力) ことを固定する
  - `test_main_multi_value_story_cell_is_accepted` — 正の対照 (`S1 S4` は 0 で通る)
  前後空白の形は表ローダが `strip()` するので `main()` 経由では到達しない。
  その旨をコメントに書き、`parse_story_cell()` の単体検査が押さえていることを示した。

## [Warning] 7. `screens.md` に設計で宣言されていない意味変更が混入している

- 判断: **対応する** (指摘は正しいが、原因は本作業ではなかった)
- 根拠: 調査したところ、**main が既にドリフトしていた**。T240
  (`7d8d015b`「bughunt 要確認グループ対応 (Q-2-01 …)」) が**生成物 `screens.md` を直接編集**し、
  正本である `inventory/notes-screens.md` を更新していなかった。
  main で `python3 scripts/bug-hunt-inventory.py check` を走らせると
  `[生成物] 生成物が再生成の結果と一致しない: screens.md` で **exit 3** になる (実測)。
  つまり私の再生成は T240 の記述を**黙って消していた** — 指摘のとおり除外すべき差分だった。
- 対応内容: T240 の記述を**正本 `inventory/notes-screens.md` へ移して**再生成した。
  結果、`screens.md` の非表部分の差分は**生成通知の 4 行だけ**になり、T240 の内容
  (Q-2-01: `manageBilling` 非保持メンバーを `dashboard` へ寄せる) は保たれている。
  既存ドリフトの解消を本コミットに含める判断: 本作業は生成器そのものを作り替えており、
  ドリフトを残したままでは `generate` が走らせられない (段 3 の byte 一致が成立しない)。

## [Suggestion] 8. スカラー型の分類を fail-closed に

- 判断: **対応する**
- 根拠: 指摘のとおり。`SCALAR_KEYS` を宣言だけして使わない形は、共通規約 (d)
  「集めた走査結果を判定に使わない形を作らない」にも触れる。
- 対応内容: `elif key in SCALAR_KEYS:` にし、どの型集合にも属さない正準 key は
  「どの型集合にも登録されていない key である」として違反にした。
  負例 `test_ac_01_rejects_key_outside_type_sets` で `CANONICAL_KEYS` を一時的に
  拡張して検出分岐を裏取りしている。

## [Suggestion] 9. gate の docblock の「名指し 2 ファイル」が古い

- 判断: **対応する**
- 根拠: 指摘のとおり。読み取り器も名指しで複製するようになった。
- 対応内容: docblock を「名指しの 3 ファイル」へ直し、読み取り器のパスを列挙した。
