# テストファーストの赤の実測 (aicue:T209)

詳細設計「テストファースト計画」の段 0 / 段 1 の失敗出力を分けて記録する。
実行コマンドはいずれも worktree 内の
`pnpm test tests/js/architecture/codex-model-consistency.test.ts`。

---

## 段 0: 判定器そのものに対するテストファースト

判定関数 (`validateOccurrences` / `validateAssignments` / `validateCanonTable`) を
**常に空配列を返すスタブ**にしたまま、負のコントロール 23 検体 (N-01〜N-23) を先に書いて走らせた。
23 検体すべてが「点灯すべき違反が返ってこない」で赤になる。

```
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-01': '旧世代の綴りを含む行'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-02': '接尾辞を落とした綴り'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-03': '直前に文字が続く綴り'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-04': '後ろに続く綴り'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-05': '末尾に区切りが残る綴り'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-06': '大文字の綴り'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-07': '期待より 1 件多い出現'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-08': '期待より 1 件少ない出現'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-09': '期待表に無いパスに正典綴りがある'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-10': 'コードフェンスの中に許可外の綴りがある'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-11': '節にモデル宣言があってラベル宣言が無い'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-12': '節にラベル宣言があってモデル宣言が無い'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-13': '同じ節にモデル宣言が 2 個'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-14': 'バッククォートで囲まれていない宣言'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-15': '用途と綴りの対応が期待と違う (入れ替え)'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-16': '期待していた割当が消えた'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-17': '未知の割当キーが増えた'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-18': '同じ複合キーが 2 回現れた'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-19': '正本の表が 2 行しかない'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-20': '正本の表が 4 行ある'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-21': '正本の見出しが 2 つある'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-22': '正本の見出しが 1 つも無い'
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 負のコントロール 'N-23': '表が正本の節の外へ移動した'
AssertionError: expected [] to not deeply equal []

Compared values have no visual difference.

 Test Files  1 failed (1)
      Tests  23 failed (23)
```

---

## 段 1: 現物に対するテストファースト

判定関数を実装し、**施策 1 (検査本体) だけ**を適用した時点 (スキル文書 4 本は旧綴りのまま) で走らせた。
層 1 / 層 2 / 層 3 の 3 本が赤、負の検体 23 件と正の検体 4 件と列挙系 2 件の計 29 件は緑になる。

```
 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 層 1: 正典 3 綴り以外が現れず、出現回数が期待表と一致する
AssertionError: Codex に指定するモデルの正典は裁定 AG-186 の 3 綴り (gpt-5.6-sol / gpt-5.6-terra / gpt-5.6-luna) である。綴りを変えるときは 正本 (.claude/skills/app-codex-vscode/SKILL.md) と本テストの期待表を同じ変更で直すこと。: expected [ …(14) ] to deeply equal []

- Expected
+ Received

- []
+ [
+   ".claude/skills/app-codex-review/SKILL.md:0: gpt-5.6-sol の出現回数が期待表と違います (期待 1 件 / 実測 0 件)",
+   ".claude/skills/app-codex-review/SKILL.md:100: 正典に無いモデル名 gpt-5.5 が現れています (許可: gpt-5.6-sol / gpt-5.6-terra / gpt-5.6-luna。裁定 AG-186)",
+   ".claude/skills/app-codex-vscode/SKILL.md:0: gpt-5.6-luna の出現回数が期待表と違います (期待 1 件 / 実測 0 件)",
+   ".claude/skills/app-codex-vscode/SKILL.md:0: gpt-5.6-sol の出現回数が期待表と違います (期待 1 件 / 実測 0 件)",
+   ".claude/skills/app-codex-vscode/SKILL.md:0: gpt-5.6-terra の出現回数が期待表と違います (期待 1 件 / 実測 0 件)",
+   ".claude/skills/app-codex-vscode/SKILL.md:36: 正典に無いモデル名 gpt-5.5 が現れています (許可: gpt-5.6-sol / gpt-5.6-terra / gpt-5.6-luna。裁定 AG-186)",
+   ".claude/skills/app-codex-vscode/SKILL.md:39: 正典に無いモデル名 gpt-5.5 が現れています (許可: gpt-5.6-sol / gpt-5.6-terra / gpt-5.6-luna。裁定 AG-186)",
+   ".claude/skills/app-design/SKILL.md:0: gpt-5.6-sol の出現回数が期待表と違います (期待 2 件 / 実測 0 件)",
+   ".claude/skills/app-design/SKILL.md:0: gpt-5.6-terra の出現回数が期待表と違います (期待 2 件 / 実測 0 件)",
+   ".claude/skills/app-design/SKILL.md:58: 正典に無いモデル名 gpt-5.5 が現れています (許可: gpt-5.6-sol / gpt-5.6-terra / gpt-5.6-luna。裁定 AG-186)",
+   ".claude/skills/app-design/SKILL.md:113: 正典に無いモデル名 gpt-5.5 が現れています (許可: gpt-5.6-sol / gpt-5.6-terra / gpt-5.6-luna。裁定 AG-186)",
+   ".claude/skills/app-design/SKILL.md:283: 正典に無いモデル名 gpt-5.5 が現れています (許可: gpt-5.6-sol / gpt-5.6-terra / gpt-5.6-luna。裁定 AG-186)",
+   ".claude/skills/app-implement/SKILL.md:0: gpt-5.6-sol の出現回数が期待表と違います (期待 1 件 / 実測 0 件)",
+   ".claude/skills/app-implement/SKILL.md:184: 正典に無いモデル名 gpt-5.5 が現れています (許可: gpt-5.6-sol / gpt-5.6-terra / gpt-5.6-luna。裁定 AG-186)",
+ ]

 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 層 2: 用途と綴りの対応が期待写像と完全一致する
AssertionError: 用途 (label) と綴りの対応が期待と食い違っています。割当の正本は .claude/skills/app-codex-vscode/SKILL.md の「利用可能モデル」節である。: expected [ …(3) ] to deeply equal []

- Expected
+ Received

- []
+ [
+   ".claude/skills/app-design/SKILL.md:0: 期待していた用途割当 .claude/skills/app-design/SKILL.md#design-review が見つかりません",
+   ".claude/skills/app-design/SKILL.md:113: 用途割当が期待と違います .claude/skills/app-design/SKILL.md#conceptual-review: 期待 gpt-5.6-terra / 実測 gpt-5.5",
+   ".claude/skills/app-implement/SKILL.md:184: 用途割当が期待と違います .claude/skills/app-implement/SKILL.md#impl-review: 期待 gpt-5.6-sol / 実測 gpt-5.5",
+ ]

 FAIL  tests/js/architecture/codex-model-consistency.test.ts > codex model consistency > 層 3: 正本の表が正典 3 綴りちょうどを持つ
AssertionError: 正本の「利用可能モデル」表が正典と一致しません。: expected [ …(2) ] to deeply equal []

- Expected
+ Received

- []
+ [
+   ".claude/skills/app-codex-vscode/SKILL.md:0: 正本の表の綴りが正典と一致しません (期待 gpt-5.6-luna / gpt-5.6-sol / gpt-5.6-terra / 実測 gpt-5.5)",
+   ".claude/skills/app-codex-vscode/SKILL.md:0: 正本の表の行数が違います (期待 3 行 / 実測 1 行)",
+ ]

 Test Files  1 failed (1)
      Tests  3 failed | 29 passed (32)
```

### 段 1 で分かった設計時に見えていなかった事実 (2 件)

1. **`scripts/codex` が `openai.chatgpt-` を 4 箇所持つ**。詳細設計の候補抽出
   (`/gpt-[A-Za-z0-9._-]*/gi`) は `chatgpt-` の中の `gpt-` を候補にしてしまい、
   「受理できなかった候補はすべて違反」の規則により `scripts/codex` が違反になる。
   実装では候補の先頭に **数字を要求** (`/gpt-[0-9][A-Za-z0-9._-]*/gi`) して別語の一部を
   候補から外した。前方の境界検査 (直前が識別子文字なら違反) は残るので、
   `xgpt-5.6-sol` は従来どおり違反である (N-03 が固定)。
   帰結として「`gpt-` に数字が続かない形のモデル名」には沈黙する
   (保証しないものに追記した)。
2. **`.claude/skills/app-design/SKILL.md` の入れ子コードフェンスが壊れていた**。
   詳細設計テンプレートを囲む外側のフェンス (旧 L198) が 3 バッククォートで、
   中に ` ```php ` の入れ子を持つため、CommonMark でも本検査でも **L243 で閉じたと読まれる**。
   その結果 §2-3 のモデル宣言・ラベル宣言 (L283 / L285) が「フェンスの中」に落ち、
   層 2 が `design-review` の割当を**見つけられない** (上の赤の 1 行目)。
   外側フェンスを 4 バッククォートにして入れ子を成立させた
   (文書が意図どおり描画されるようになる修正でもある)。

---

## 段 3: 施策 2〜5 適用後

施策 2 → 3 → 4 → 5 を適用したのち、同じコマンドで 32 件すべて緑になることを確認した
(この時点の検体は負 23 + 正 4 + 実ファイル 5 の 32 件)。

その後、実装レビュー Round 1 の指摘に対応して検体を負 27 + 正 5 に増やし
(合計 37 件)、下の全検証で緑を確認した。

### 実装中に踏んだ罠 (再発防止のため記録する)

例外目録の集計キーを**書き込み側と読み出し側で別々に組み立てていた**ため、
書き込み側の区切りに制御文字が紛れ込んでいたときに
「例外が 1 件も数えられていない」という形で静かに壊れた
(層 1 と正の検体 P-05 だけが落ち、違反の一覧には**例外の件数違いしか出ない** =
候補は正しく覆われているのに件数だけ 0 になる、という読み解きにくい赤になった)。
キーの組み立ては `exemptionKey()` の 1 か所に寄せて直した。

## 段 4: 全検証コマンドの実測 (すべて green)

| コマンド | 結果 |
|---|---|
| `composer test` | pest passed — 5631 tests / 5629 passed / 2 skipped / 24741 assertions |
| `composer phpstan` | No errors (987 files) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | 指摘なし |
| `pnpm typecheck` | 指摘なし |
| `pnpm test` | 160 files / **2002 tests** passed (本件で +35 件。旧 2 件 → 新 37 件) |
| `pnpm build` | built |
| `pnpm typecheck:packages` | 指摘なし |
| `pnpm build:packages` | 指摘なし |
| `pnpm test:packages` | 10 files / 106 tests passed |

---

## 段 5: 引き継ぎ再開エージェントによる追加対応 (Round 2 の未対応 Warning の発見 + Round 3)

前回の実装エージェントが利用上限で中断した時点で、実装レビュー Round 2 の
対応マトリクス (`codex-history/impl-review-decisions-round-2.md`) には
Round 2 の指摘 4 件中 3 件 (例外境界 Critical / A1 の記述 Warning / レビュー履歴
Warning / 検体件数 Suggestion) しか記載が無く、**層 3 の区切り行判定
(`TABLE_DELIMITER` がハイフン 1 文字の区切りセルを受理し、ヘッダーとの
セル数不一致も見ていない、という Warning) への対応が抜け落ちていた**
(コード側にも未反映)。

再開時に `node -e` で直接 `/^\|(?:[ \t]*:?-+:?[ \t]*\|)+$/.test("|-|-|")` および
`.test("|---|---|---|")` を実行し、いずれも `true` (旧正規表現が誤って受理する)
であることを実測してから、テストファーストで直した
(負の検体 N-29 / N-30 を追加。詳細設計「実装時に本設計から動かした点」#4)。

その後、セッションが失われた状態で実装レビュー Round 3 を実施した
(Codex CLI のセッション JSONL が `${TMPDIR:-/tmp}/codex-review/` に残っておらず、
Round 1・2 のセッションを継続できなかったため、Round 1・2 の指摘内容と
対応状況の要約をプロンプトに明示した新規セッションとして実行した。
詳細は `codex-history/impl-review-decisions-round-3.md`)。

Round 3 は Critical 0 件・Warning 3 件で CHANGES_REQUESTED:

- 層 3 がエスケープ済み `\|` を考慮しないため、ヘッダーにエスケープ済み `\|` を含めて
  区切り行のセル数を揃えると受理されてしまう (`isValidDelimiterRow` をセル数一致だけで
  判定していたための残存 fail-open)
- フェンス開始判定 (`FENCE_OPEN`) がインデント無制限で GFM (0〜3 スペース) より広く、
  4 スペース字下げの偽フェンスで本物の見出しを隠し、後続の別構造を正本として
  誤受理させられる
- 負のコントロール N-11〜N-14 が `not.toEqual([])` としか比較しておらず、
  診断生成コードを削除しても別経路の違反で緑のままになりうる

いずれも実測 (Node での正規表現・素朴分割の挙動確認、旧フェンス判定でのマスク結果の
再現) で fail-open を確認してから、テストファーストで直した。負の検体 N-31 / N-32 を
追加し、N-11〜N-14 には `expectSubstring` による診断文言の直接検証を追加した
(詳細は詳細設計「実装時に本設計から動かした点」#5〜#7)。

`app-implement` の合議終了条件 (Codex 判定が APPROVED になるまで最大 3 ラウンド) の
上限に達したため、Round 3 の Warning 3 件は対応後に自己検証 (テスト green の実測) で
確定させ、Round 4 でのセッション継続レビューは行っていない
(セッション自体が失われており技術的に不可能なため)。

## 段 6: 段 5 対応後の全検証コマンドの再実測 (すべて green)

段 5 の対応 (N-29〜N-32 の追加、N-11〜N-14 の `expectSubstring` 強化) は
`tests/js/architecture/codex-model-consistency.test.ts` のみへの変更であり、
PHP 側・他の TypeScript ファイルには影響しない。念のため全検証コマンドを再実測した。

| コマンド | 結果 |
|---|---|
| `pnpm vitest run tests/js/architecture/codex-model-consistency.test.ts` | 42 tests passed (負 32 + 正 5 + 実ファイル 5) |
| `composer test` | pest passed — 5631 tests / 5629 passed / 2 skipped / 24741 assertions (段 4 と同値。PHP 側は無変更) |
| `composer phpstan` | No errors (987 files) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | 指摘なし |
| `pnpm typecheck` | 指摘なし |
| `pnpm test` | 160 files / **2007 tests** passed (段 4 の 2002 件 + 本 Round の検体追加 5 件) |
| `pnpm build` | built |
| `pnpm typecheck:packages` | 指摘なし |
| `pnpm build:packages` | 指摘なし |
| `pnpm test:packages` | 10 files / 106 tests passed |
