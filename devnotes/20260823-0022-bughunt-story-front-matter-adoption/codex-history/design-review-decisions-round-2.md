# 対応マトリクス: design-review Round 2

Codex 判定: CHANGES_REQUESTED (gpt-5.6-sol / high)。施策別 APPROVE 6 / REQUEST_CHANGES 5。
Critical 4 / Warning 6 / Suggestion 2。**すべて対応した**。

## [Critical] 施策 4: AC-14 がまだ全数点呼になっていない (I 群が丸ごと漏れ / NON_MECHANICAL が未使用)

- 判断: **対応する**
- 根拠: 指摘が正確。`ADOPTED_INVARIANTS` 自体が手書きの一覧なので、そこから項目を落とせば点呼も気づかない。
  実際に提示した一覧から I 群が丸ごと抜けており、`INVENTORY_SIDE` に I1〜I5 を書いても
  点呼のループは `ADOPTED_INVARIANTS` しか走らないので意味を持っていなかった。I6 はどの集合にも無かった。
  `NON_MECHANICAL` も assert に使っていなかった。
- 対応内容: **58 項目の分割 (partition) を検査側が独立に持つ**形へ全面的に書き直した。
  - `ALL_INVARIANTS`（全 58 件。点呼の基準）を先に固定する。
  - 分類を `ADOPTED` / `DIFFERENCES` / `NOT_ADOPTED` の**互いに排他な 3 集合**にし、
    和が `ALL_INVARIANTS` と一致することを assert する。`len(ALL_INVARIANTS) == 58` も固定する。
  - 担い手を `STORY_SIDE` / `INVENTORY_SIDE` / `NON_MECHANICAL` の 3 集合にし、
    **集合同士の重複を許す**（B16 のように両側に現れる項目を表せる）。
  - `ADOPTED` の全件が担い手のいずれかに属すること、担い手集合に未知 ID が無いことを assert する。
  - `NON_MECHANICAL` を assert に使う（`("E5", "G6")` と完全一致）。

## [Critical] 施策 4: 検査メソッドの存在確認が実際の命名と一致しない

- 判断: **対応する**
- 根拠: 確定的な誤り。`AC-01` から作った `test_ac_01` は
  実際の `test_ac_01_rejects_quoted_scalar` と一致せず、`hasattr` が常に偽になる。
- 対応内容: 主題からテスト名を**推測しない**形へ直した。
  `SUBJECT_TO_TESTS` で主題 → テスト名の並びを明示対応させ、
  各名前が `callable` であること・各主題に `accepts`（正例）と `rejects`（負例）が
  1 本以上あることを assert する。

## [Critical] 施策 7: `終` の対象内化がレンダリング・対象外節まで波及していない

- 判断: **対応する**
- 根拠: 指摘が正確。`render_screens()` / `render_operations()` の「うち対象外」件数と
  `_out_of_scope_section()` が `KUBUN_NEEDS_REASON` をスコープ判定に使っている。
  `validate_assignment()` だけを直すと「割当必須なのに対象外件数へ入る」矛盾が生まれる。
- 対応内容: **`KUBUN_NEEDS_REASON` の全利用箇所の棚卸し表**を設計へ足し、
  「reason 要否」と「scope 判定」を分類した。規律を明記した —
  **`KUBUN_NEEDS_REASON` は reason 要否だけに使う。scope 判定はすべて `KUBUN_OUT_OF_SCOPE` に統一する。**
  統合テスト（`終` が通常の一覧・対象内件数へ入り、対象外節へ入らないこと）をテスト計画へ足した。

## [Critical] 施策 7: `load_assignment()` の非 optional 戻り値と「構築しない」契約が矛盾

- 判断: **対応する**
- 根拠: 指摘が正確。`tuple[Assignment, list[str]]` では違反時にも必ず `Assignment` を返す必要があり、
  空の Assignment を返すと呼び出し側の確認漏れで生成できてしまう。
- 対応内容: 戻り値を **`tuple[Assignment | None, list[str]]`** へ直し、
  違反が 1 件でもあれば `None` を返すこと、`_prepare()` / `run_check()` / `run_generate()` は
  `None` を受けたらレンダリングへ進まず目録を 1 バイトも書かないことを明記した。

## [Warning] 施策 7: 「`終` にstory を書けなかったのはスカラー模型の制約」という説明に根拠が無い

- 判断: **対応する（説明を訂正する）**
- 根拠: 指摘のとおり。単一値でも `終` に 1 枚割り当てることはできた。
  データ構造上自然に消える制約ではなく、**意図的な意味変更**である。
- 対応内容: 因果説明を次へ書き直した —
  「現行は `終` を割当の対象外としていたが、正典の『**`外` 以外は対象内**』へ**意図的に意味を変更する**。
  変更後の `終` は `reason` 必須かつカード割当必須になる。」
  あわせて全 consumer の波及確認（棚卸し表）を明記した。

## [Warning] 施策 7: 型だけの検査では生成器単体の fail-closed として不足

- 判断: **対応する**
- 根拠: 妥当。特に不正な `id: SX` は `int(card_id[1:])` で例外になり、違反として報告されない。
- 対応内容: `load_assignment()` が**自分が消費する項目**について見る範囲を表にした —
  `id` の形式（`fullmatch`）と一意性 / `applicability` の語彙 / `covers_*` の要素が文字列であること /
  route 名・capability id の形式（`fullmatch`）/ **配列内の重複**（`frozenset` 化する前に見る）。
  見ないもの（正準順序 / 表 A・表 B / `lane` / `priority` / `depends_on` / H1 / 旧メタ節）も明記した。

## [Warning] 施策 7: 終了コードの期待が「exit 3 または exit 2」では広すぎる

- 判断: **対応する**
- 根拠: 妥当。どちらでも合格すると終了コード契約の後退を検出できない。
- 対応内容: 原因別に固定した —
  形式違反・語彙違反・配列内重複・割当のドリフト → **exit 3** /
  `stories/` が無い・カードが読めない → **exit 2**（検査成立不能）。
  どちらでも生成物が 1 バイトも変わらないことを併せて確認する。

## [Warning] 施策 4: AC-15 が空節を許す

- 判断: **対応する**
- 根拠: 妥当。見出し数だけでは J2 の「散文を持つ」を保証しない。
- 対応内容: AC-15 の定義へ「見出しの直後から次の H2 見出しの直前までを取り、
  空白を除いて非空であること」を足し、`## 目的` / `## 逸脱アイデア` の**空節の負例**を計画へ加えた。

## [Warning] 施策 4: 「保証しないもの」と `NON_MECHANICAL` が 1 対 1 ではない

- 判断: **対応する（範囲を限定する）**
- 根拠: 妥当。表には I5 と ID なしの項目も含まれている。
- 対応内容: 「1 対 1 に対応するのは**採用と分類した非機械保証の 2 件（E5 / G6）だけ**」と範囲を明示し、
  I5 は分類が「差」で担い手が目録側であること、ID を持たない 4 件は機構全体の保証境界であって
  不変条件の分類ではないことを書き分けた。

## [Warning] 施策 5: `MIN_TESTS = 0` の置き忘れを検出する仕組みが無い

- 判断: **対応する**
- 根拠: 妥当。0 のままだと件数 pin が常に成功し、機構ごと無効になる。
- 対応内容: PHP 側に `expect(StoryFrontMatterPins::MIN_TESTS)->toBeGreaterThan(0);` の
  専用テストを足した（PHPDoc の `positive-int` だけでは実行時の 0 を防げない旨も明記）。

## [Warning] 施策 1: 本文が「差は 3 点だけ」のまま、表は 4 行

- 判断: **対応する**
- 対応内容: 「次の 4 点だけ」へ訂正した。

## [Warning] 施策 11: `EXPECTED_S7_ADDED_SCREENS` がプレースホルダーのまま

- 判断: **対応する（route 名を確定させた）**
- 根拠: 妥当。手作業で起こす S7 画面の誤割当を防ぐのが今回の強化の目的なので、
  空のままでは目的を果たさない。
- 対応内容: **11 件を route 名で列挙**した（`capture.manuals.show` / `capture.takes.playback` /
  `projects.categories.index` / `projects.edit` / `projects.manuals.download` /
  `projects.manuals.edit` / `projects.manuals.jobs.show` /
  `projects.manuals.render-jobs.playback` / `projects.manuals.render-jobs.show` /
  `projects.manuals.show` / `projects.show`)。
  全件が `annotations.toml` に実在し区分が `通常` であることを実測で確認した。
  あわせて現行カードの「新規消化はしない」という散文の意味を書き分けた —
  それは「目録の未割当を埋める新規消化が無い」であって「S7 が何も開かない」ではない。
  集計表の `N 件` も `11 件` へ確定させ、施策 9 の差分記述も「操作 9 件 + 画面 11 件」へ直した。

## [Suggestion] 施策 5: `CORE_NEGATIVES` の `test_ac_06_family_surface_pin` は名前上は負例でない

- 判断: **対応する**
- 対応内容: `test_ac_06_rejects_reassigned_family_surface` へ置き換えた（定数名と実態を一致させた）。

## [Suggestion] 施策 11: 手順節ハッシュの抽出境界を明文化せよ

- 判断: **対応する**
- 対応内容: 「`## 手順` の見出し行の次の行から、次に現れる H2 見出しの直前の行まで。
  末尾の空行は落とさない。次の H2 が無ければファイル末尾まで」と明文化し、
  旧メタ節が別の H2 節なのでこの境界に入らないことを明記した。
