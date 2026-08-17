Round 2 の [Critical] 2 件・[Warning] 5 件・[Suggestion] 1 件のすべてに対応しました (反論なし)。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

全 [Critical] 2 件・全 [Warning] 5 件に対応した。反論なし。

## [Critical] 施策 0: `REPO_ROOT` の計算が 1 階層不足
- 判断: **対応する**
- 根拠: 指摘のとおり。`os.path.dirname` 2 回では `.claude` で止まり、
  根拠パスの実在検査が `/workspace/.claude/app/...` を見て**全件緑になる** (検査が死ぬ)。
  現行 `test_spec_ledger.py:29` は `SKILL_ROOT.parents[2]` で正しく数えており、そちらが正である。
- 対応内容: `pathlib` に統一して `REPO_ROOT = SKILL_DIR.parents[2]` に直し、
  数え方をコメントで明記した。あわせて**テストの前提確認**として
  `REPO_ROOT / "AGENTS.md"` の実在を最初に assert する手順を足した
  (検査が別ディレクトリを見ていたら全件緑になってしまうため)。

## [Critical] 施策 2/3: `machine_projection_sha256` 自体が可変で pin されていない
- 判断: **対応する**
- 根拠: 指摘のとおり。台帳の hash も同時に更新すれば通るので、
  「既存行の機械項目を黙って書き換えるとテストが赤くなる」は成立していなかった。
- 対応内容: **三点一致**にした — テスト定数 `EXPECTED_MACHINE_PROJECTION_SHA256` /
  移行台帳の `provenance.machine_projection_sha256` / 現在の登録から計算した値。
  正規化方式も `json.dumps(..., sort_keys=True, ensure_ascii=False, separators=(",", ":"))` +
  UTF-8 + sha256 と完全に書き下した (実装差で hash が揺れないため)。
  「台帳の hash も同時に更新しても落ちる」ことを固定するテスト (32b) を足した。

## [Warning] 施策 0: 原子性テストが入力検証失敗しか扱っていない
- 判断: **対応する**
- 対応内容: 障害注入を足した — `os.replace` を例外に差し替えても sentinel が不変 (5b) /
  両経路の後に一時ファイルが残らない (5c) / 既存 mode を保つ・新規は 0644 (6)。

## [Warning] 施策 0: テスト本数の表記が不一致 (33 本 vs 表が 36)
- 判断: **対応する**
- 対応内容: 「36 契約」に統一し、実装完了時に実メソッド数を確定して報告すると明記した。

## [Warning] 施策 1: マーカー偽装防止が `context` にしか掛かっていない
- 判断: **対応する**
- 根拠: `scope_value` や `source_finding_ids` も未エスケープで出力されるので、
  改行 + マーカー接頭辞を入れれば項目境界を偽装できる。
- 対応内容: **生成物へ出す全文字列**へ規則を広げた。機械項目
  (`scope_kind` / `scope_value` / `source_finding_ids` の各要素 / `verdict` / run / commit /
  `supersedes`) は **CR / LF とマーカー接頭辞の両方を禁じる**。
  `narrative` だけは複数行 markdown なので改行を許し、マーカー接頭辞のみ禁じる。
  項目ごとの注入テストに分けた (テスト 8)。

## [Warning] 施策 1: 生成器が `supersedes` の不在・自己参照・循環を検証しない
- 判断: **対応する**
- 対応内容: 生成器でも 4 点 (形式 / 実在 / 自己参照でない / 循環でない) を `RenderError` にした。
  説明も「**照合器の検証を通過できる supersede 関係について**同じ `active` 算出を行う」へ限定した。
  テスト 12b を追加。

## [Warning] 施策 1: 非空文字列が whitespace-only を拒否するか不明
- 判断: **対応する**
- 対応内容: 「非空文字列は `value.strip() != ""` を意味する」を共通契約として明記し、
  `context` の全欄と移行台帳の全文字列に適用した。テスト 15 に空白だけのケースを足した。

## [Warning] 施策 2: `required_fragments` の集合比較は重複を見逃す
- 判断: **対応する**
- 対応内容: 台帳読み込み時に `(field, value)` の重複を拒否し (テスト 23b)、
  期待値との比較は**要素数を含めて**行うようにした。

## [Warning] 施策 6: 「黙って消えることはない」が保証範囲と矛盾
- 判断: **対応する**
- 対応内容: 「**正常に再生成すれば**必ず載る。再生成忘れは CI では検出されない」へ書き換えた。

## [Suggestion] 施策 3: `watch_globs` の後続タスクを「候補」で終わらせない
- 判断: **対応する**
- 対応内容: 「後続タスク (本設計から必ず起票する)」節を新設し、
  タイトル・テーマ・内容・優先度・モード・前提を確定させた。
  **本タスクを閉じる前に `docs/TODO.md` へ登録する**ことを条件として書いた。

---

## 修正後の詳細設計書 (全文)

# 詳細設計: bug-hunt の申し送り文書を生成物へ移し、経緯を登録の文脈項目へ寄せる

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書 (SOP) を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ (PWA) でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合 (OJT を撮って形式化する tebiki) と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作 4. `response()->json()` の直書き 5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き 7. 操作系 POST の `redirect()->intended()`
8. 必須条件未充足を理由にした disabled ボタン 9. Artifact の使用

### コーディングルール

- 本タスクは **PHP / TypeScript / Svelte を 1 行も変更しない**。変更対象は
  bug-hunt スキル同梱の **Python (stdlib のみ)** と **Markdown / JSON / JSONL** である。
  PHPStan level 10 / Pest / DTO / JsonResource / Factory の各規約は**適用対象が無い**
  (回避ではなく非該当)。無影響であることは検証コマンドで確認する。
- Python は **stdlib のみ**。外部依存を足さない (`ledger/` の既存資産と同じ制約)。
- 日本語コメント。
- **実装は worktree で行う** (`scripts/setup-worktree.sh <task-id>`。main 直接実装は禁止)。

## 概念設計リファレンス

`devnotes/20260817-1755-bughunt-handover-to-ledger/conceptual-design.md`
(Codex 概念レビュー Round 3 で APPROVED)

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|---|---|---|
| 0 | 腐り検知テストの差し替え (**先に置いて赤を確認**) | `ledger/test_spec_ledger.py` | 高 |
| 1 | 生成器の新設 | `ledger/render_spec_ledger.py` (新規) | 高 |
| 2 | 移行台帳の新設 | `ledger/spec-ledger-migration.json` (新規) | 高 |
| 3 | A-001 に `context` を足す (移行) | `ledger/adjudications.jsonl` | 高 |
| 4 | A-003 に `context` を足す (一次資料の範囲で) | `ledger/adjudications.jsonl` | 中 |
| 5 | `spec-ledger.md` を生成物へ置換 | `spec-ledger.md` | 高 |
| 6 | 運用文書の更新 | `ledger/README.md` | 高 |
| 7 | bug-hunt 節に 1 項足す | `AGENTS.md` | 中 |

**実装順**: 0 (stub を置いて代表 4 本の赤を確認) → 1 → 2 → 3 → 4 → 5 (生成器で出力) → 6 → 7 → 全緑。

### 波及変更 (全施策共通)

- TypeScript 型定義 / API Resource / DTO / Inertia Props: **なし**
- PHP テスト: **なし** (Architecture テストは `.claude/skills/` の Python を見ていない。
  `ForbiddenStatementTokenInvariantTest` の母集団は git 追跡下の `*.php` で、本タスクは PHP を増やさない)
- CI: **変更しない** (Python レーンの CI 配線は家系の裁定 AG-152 の別タスク)

---

## 共通契約: 用語と規則

### 有効性 (`active` / `superseded`) — 照合器と同じ規則にする

照合器は `validate_findings.py:583-584` で

```python
superseded = {a["supersedes"] for a in valid_adjs if a.get("supersedes")}
active = [a for a in valid_adjs if a.get("adjudication_id") not in superseded]
```

として **未 supersede の登録だけ**を照合に使う。生成物もこの規則をそのまま使い、
各項目に有効性を表示する。**「再起票しない」の案内は `active` の項目に限定する** —
`superseded` の項目は履歴であり、判断の正本は後継である。

**言い方を正確にする**: 生成器が主張するのは
「**照合器の検証を通過できる supersede 関係について、照合器と同じ `active` 算出を行う**」である。
壊れた supersede 関係を持ち込まれたときに照合器と食い違わないよう、
**生成器も次の 4 点を検証して `RenderError` に倒す** (照合器の全検証を重複させはしない):

1. `supersedes` の値が `^A-[0-9]{3,}$` の形であること
2. 指す先の登録が実在すること
3. 自己参照でないこと
4. 循環していないこと (`A → B → A` 等)

これが無いと、照合器は registry を無効化しているのに生成物だけが
誤った `active` / `superseded` を表示する、という食い違いが起こり得る。

### append-only 規約の適用範囲 (今回はっきりさせる)

- **抑制判断に関わる機械項目は append-only + supersede** (既存行を書き換えない)。
- **`context` は Git 履歴下で追記・訂正できる** (照合に一切関与しないため)。
  本移行が既存 3 行へ `context` を足せるのはこの限定による。
- この限定が空手形にならないよう、**移行時点の「機械項目だけの射影」の sha256** を
  **移行台帳とテスト定数の両方に**置き、
  「テスト定数 / 移行台帳 / 現在の登録から計算した値」の**三点一致**を要求する (施策 2)。
  移行台帳だけに置くと、機械項目を書き換えると同時に台帳の hash を更新すれば通ってしまう
  (Codex 詳細レビュー R2 [Critical])。三点にして初めて
  「既存行の機械項目を黙って書き換えるとテストが赤くなる」と言える。
- 射影の正規化は**完全に固定する**:
  `json.dumps(登録から context を除いた dict, sort_keys=True, ensure_ascii=False, separators=(",", ":"))`
  を UTF-8 で符号化して sha256。実装差で hash が揺れないよう `separators` まで書き下す。

### `context` の欄 (閉じた集合)

| 欄 | 型 | 必須 |
|---|---|---|
| `title` | 非空文字列。**CR / LF を含んではならない** (1 行であることを契約にする) | ○ |
| `spec_basis` | 非空文字列の非空配列。各要素の書式は施策 3 で定義 | ○ |
| `narrative` | 非空文字列 (markdown 可) | ○ |
| `reopen_condition` | 非空文字列 | — |

- **未知キーは拒否** (deny-by-default)。
- **非空文字列は `value.strip() != ""` を意味する** (空白だけの値は非空と認めない)。
  これは `context` の全欄と移行台帳の全文字列に適用する。
- **生成物へ出力される文字列はすべて**、機械マーカーの接頭辞 `<!-- entry:` を含んではならない。
  対象は `context` の 4 欄だけではなく、**項目の見出し行や箇条書きに出る機械項目も含む** —
  `scope.scope_kind` / `scope.scope_value` / `source_finding_ids` の各要素 / `verdict` /
  `adjudicated_at_run` / `adjudicated_at_commit` / `supersedes`。
  これらは **CR / LF も禁じる** (改行を入れられると行頭からマーカー行を偽装できるため)。
  `title` の CR / LF 禁止もこの規則の一部である。
  (`narrative` は複数行の markdown なので改行は許すが、マーカー接頭辞は禁じる。
  `narrative` は行頭が本文であって項目境界の解析対象ではない。)

---

## 施策 0: 腐り検知テストの差し替え

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py` (全面差し替え。現行 196 行)

### 現行 (何を守っていて、なぜ役目を終えるか)

| 現行テスト | 見ているもの | 差し替え後 |
|---|---|---|
| `test_required_fields_present` | 確定項目が 9 欄を持つ | **消える** (欄は `context` の形として生成器が検証) |
| `test_evidence_paths_exist` | 根拠欄のパスが実在する | **強化して残す** (対象が `context.spec_basis`) |
| `test_registry_cross_reference_resolves` | 「A-NNN 登録済」の相互参照 | **消える** (相互参照という概念が無くなる) |

3 本とも**文書 → registry の向き**しか見ておらず、逆向き (registry に載ったのに文書に無い) を
検出できない。これが実測 1 (A-002 / A-003 が文書に無い) を見逃した機序である。

### 変更後: テスト一覧 (36 契約)

`staged(...)` ヘルパで入力 2 点 (`adjudications.jsonl` / `spec-ledger-migration.json`) を
一時ディレクトリへ写し、必要なら壊してから生成器へ渡す。**現物は絶対に書き換えない**
(出力を伴うテストは一時ディレクトリの sentinel を `--output` で指す)。

**A. 生成物であること**

| # | テスト | 固定する事実 |
|---|---|---|
| 1 | `test_generated_output_matches_committed_file` | `build()` の結果が現物と byte 一致 |
| 2 | `test_check_passes_on_committed_file` | `--check` が exit 0 |
| 3 | `test_manual_edit_is_detected` | sentinel を 1 語書き換えると exit 1。**stderr に再生成コマンドが含まれる** |
| 4 | `test_check_fails_when_output_is_absent` | 出力が無ければ exit 1 |
| 5 | `test_render_is_atomic_on_input_validation_failure` | 入力を壊して生成を走らせても **sentinel の sha256 が不変** |
| 5b | `test_render_is_atomic_when_replace_fails` | `os.replace` を例外に差し替えても (障害注入) sentinel が不変 |
| 5c | `test_render_leaves_no_temp_file_behind` | 上の 2 経路の後に出力ディレクトリへ `.spec-ledger.*.tmp` が残らない |
| 6 | `test_output_mode_is_preserved_or_0644` | 既存 sentinel の mode (例 0640) を保つ。**新規出力は 0644** (`mkstemp` の 0600 を引き継がない) |

**B. 掲載の完全性 (概念設計 Critical 1 の機械化)**

| # | テスト | 固定する事実 |
|---|---|---|
| 7 | `test_every_adjudication_id_is_listed_exactly_once` | 生成物の**機械マーカー** `<!-- entry: A-NNN -->` から抽出した id の多重集合が registry の id 集合と一致し、各 1 回 |
| 8 | `test_entry_marker_cannot_be_forged` | `context` の 4 欄**および**出力に出る機械項目 (`scope_kind` / `scope_value` / `source_finding_ids` の要素 / `verdict` / run / commit / `supersedes`) に `<!-- entry: A-999 -->` や CR / LF を入れると `RenderError` (項目ごとの注入テストを分ける) |
| 9 | `test_title_with_newline_is_rejected` | `title` に CR / LF があれば `RenderError` |
| 10 | `test_entry_without_context_is_still_listed` | `context` を持たない登録を足した写しでも掲載され、`経緯は未記入` の印が付く |
| 11 | `test_active_and_superseded_are_labelled_like_the_matcher` | 有効性の判定が `validate_findings` の `active` 算出と一致する (同じ入力で集合比較) |
| 12 | `test_supersede_relations_are_rendered_deterministically` | 同じ id を supersede する登録を 2 件にした写しで、両方の id が**昇順**で表示される |
| 12b | `test_broken_supersede_relations_are_rejected` | `supersedes` が不正形式 / 実在しない id / 自己参照 / 循環のとき `RenderError` |
| 13 | `test_ids_are_sorted_numerically` | `A-999` と `A-1000` を含む写しで数値順に並ぶ (文字列順ではない) |

**C. `context` の検証と fail-closed 境界**

| # | テスト | 固定する事実 |
|---|---|---|
| 14 | `test_unknown_context_key_is_rejected` | 許可外キーで `RenderError` |
| 15 | `test_context_field_type_and_emptiness_rejected` | `title` 空 / **空白だけ** / `narrative` 非文字列 / `spec_basis` 空配列 / 要素が空または空白だけ / `reopen_condition` 空 → いずれも `RenderError` |
| 16 | `test_schema_broken_context_does_not_affect_the_matcher` | **JSON として妥当**なまま `context` の形だけ壊した入力で、`validate_adjudications()` は error 0、`build()` は `RenderError` |
| 17 | `test_json_syntax_error_fails_both` | **JSON 構文**を壊した入力では、照合器も従来どおり fail-closed になり、生成器も失敗する (境界の正確な位置を固定する) |
| 18 | `test_duplicate_json_keys_are_rejected` | `object_pairs_hook` で重複キーを拒否 |
| 19 | `test_non_finite_numbers_are_rejected` | `NaN` / `Infinity` / `-Infinity` を拒否 |
| 20 | `test_duplicate_adjudication_id_is_rejected_by_renderer` | 生成器は照合器が走った前提に寄りかからない |
| 21 | `test_bad_adjudication_id_form_is_rejected` | `^A-[0-9]{3,}$` に合わない id を拒否 |
| 22 | `test_missing_machine_field_raises_render_error_not_key_error` | 生成に使う機械項目 (`verdict` / `scope` / `source_finding_ids` / `adjudicated_at_run` / `adjudicated_at_commit` / `review_after_days`) の欠落は `RenderError` になる |

**D. 移行台帳**

| # | テスト | 固定する事実 |
|---|---|---|
| 23 | `test_migration_manifest_matches_expected_semantics` | テスト側の定数 `EXPECTED_MIGRATION` と**完全一致** (鍵 / `key_kind` / `target` / `field_minimums` の値 / 必須断片の**列挙**。要素数も含めて比較)。**台帳を弱める変更をこのテストが赤にする** |
| 23b | `test_duplicate_required_fragment_is_rejected` | `(field, value)` が重複した台帳を拒否する |
| 24 | `test_block_count_change_fails` / `test_entries_count_mismatch_fails` | 件数の三点一致 (`block_count` / `len(entries)` / `EXPECTED_BLOCK_COUNT`) |
| 25 | `test_duplicate_key_in_manifest_fails` / `test_unknown_key_does_not_resolve` | 鍵の重複と解決不能を拒否 |
| 26 | `test_integer_fields_reject_bool_and_non_positive` | `version` / `block_count` / `field_minimums` の各値で `True` / `0` / `-1` / `"900"` / `None` を拒否 |
| 27 | `test_field_below_minimum_fails` | `narrative` または `reopen_condition` を削ると `RenderError` (痩せの検出) |
| 28 | `test_required_fragment_missing_fails` | 必須断片を消すと `RenderError` |
| 29 | `test_fragment_is_searched_only_in_its_declared_field` | `reopen_condition` の断片が `narrative` に紛れていても通らない |
| 30 | `test_fragment_identifier_boundary` | `T095` を要求して本文が `T0950` なら不一致 / 「`T095` の実装フェーズ」「\`T095\`」は一致 / `xT095` `T095-extra` は不一致 |
| 31 | `test_provenance_shape_and_heading_count` | `provenance` の必須キー・型、`source_block_headings` の件数が `block_count` と一致・一意・非空 |
| 32 | `test_machine_projection_sha256_is_pinned_in_three_places` | 各登録の**機械項目だけの射影** (context を除いた正規化 JSON) の sha256 が、**テスト定数 `EXPECTED_MACHINE_PROJECTION_SHA256` / 移行台帳の `provenance.machine_projection_sha256` / 現在の登録から計算した値**の三点で一致する |
| 32b | `test_machine_field_change_turns_red` | 写しの機械項目を書き換え、台帳の hash も同時に更新しても、テスト定数と食い違うので落ちる |
| 33 | `test_manifest_shape_is_rejected_when_not_a_single_object` | 配列 / 不在ファイルを拒否 |

**E. 既存方針の継承 / 構造的保証**

| # | テスト | 固定する事実 |
|---|---|---|
| 34 | `test_spec_basis_references_are_well_formed_and_exist` | `context.spec_basis` の**全要素**について先頭トークンが所定形式であること (形式不正は「対象外」ではなく**失敗**)、絶対パス・`..` を拒否、`resolve()` 後に `REPO_ROOT` 配下の**通常ファイル**であること。行番号・アンカーは見ない |
| 35 | `test_spec_basis_rejects_traversal_and_escape` | 絶対パス / `..` / symlink による外部脱出 / 形式不正の 4 ケースが失敗する |
| 36 | `test_matcher_source_never_names_the_handover_files` | `validate_findings.py` の本文に `spec-ledger` / `spec_ledger` / `render_spec_ledger` / `spec-notes` が 1 つも現れない |

> 表の番号は**契約の通し番号** (36 契約) であり、実装時のテストメソッド数はこれ以上になる。
> `#15` `#24` `#25` `#26` `#35` はケースごとに分けることを**推奨**する
> (失敗時にどの契約が壊れたか分かるため)。**実装完了時に実際のテスト件数を確定して報告する。**
>
> テストの前提確認として `REPO_ROOT / "AGENTS.md"` が実在することを最初に assert する
> (根拠パスの実在検査が別ディレクトリを見ていたら全件緑になってしまうため)。

### テスト計画 (fail-first の確認手順)

1. `render_spec_ledger.py` に**最小 stub** (`class RenderError(Exception)` と
   `def build(...): raise RenderError("not implemented")`) だけを置く。
2. 差し替えたテストを走らせ、**代表 4 本**が意図した assertion で赤くなることを記録する:
   `test_every_adjudication_id_is_listed_exactly_once` (完全性) /
   `test_schema_broken_context_does_not_affect_the_matcher` (fail-closed 境界) /
   `test_required_fragment_missing_fails` (移行) /
   `test_manual_edit_is_detected` (drift)。
3. 施策 1-5 を入れて全緑にする。

### リスク

- 既存 70 本 (`test_validate_findings.py` を含む) を壊さないこと。差し替えるのは
  `test_spec_ledger.py` の 3 本だけである。

---

## 施策 1: 生成器 `render_spec_ledger.py`

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py` (新規)

### 定数

```python
HERE = pathlib.Path(__file__).resolve().parent
SKILL_DIR = HERE.parent
# .claude/skills/app-bug-hunt -> parents[0]=skills / parents[1]=.claude / parents[2]=リポジトリルート。
# 現行 test_spec_ledger.py:29 と同じ数え方である (os.path.dirname を 2 回にすると .claude で止まる)。
REPO_ROOT = SKILL_DIR.parents[2]
ADJUDICATIONS_PATH = os.path.join(HERE, "adjudications.jsonl")
MIGRATION_PATH = os.path.join(HERE, "spec-ledger-migration.json")
OUTPUT_PATH = os.path.join(SKILL_DIR, "spec-ledger.md")

# 移行元 spec-ledger.md の実ブロック数 (`^#### ` のうちコードフェンス外のもの)。
# 2026-08-17 の実測で 1 件 (F-1-02)。もう 1 つの `####` は初回登録テンプレートの
# フェンス内なので移行対象ではない。件数を pin しないと「1 件に痩せても通る」検査になる。
EXPECTED_BLOCK_COUNT = 1

# 経緯の欄。閉じた集合で、未知キーは拒否する (deny-by-default)。
CONTEXT_KEYS = ("title", "spec_basis", "narrative", "reopen_condition")
CONTEXT_REQUIRED = ("title", "spec_basis", "narrative")

# 移行台帳の語彙。どちらも現時点で 1 語である。
# 参照実装 (aigenba) は run 修飾つき finding id を鍵にするが、aicue の source_finding_ids は
# run 修飾を持たず、F-3-01 が A-002 と A-003 の両方に現れるため一意に解決できない。
# 一意性を validator が強制している識別子は adjudication_id だけなので、それを鍵にする。
MIGRATION_KEY_KINDS = ("adjudication_id",)
MIGRATION_TARGETS = ("adjudications",)

# 生成に使う機械項目 (欠けたら RenderError。KeyError で落とさない)。
RENDERED_MACHINE_FIELDS = ("verdict", "scope", "source_finding_ids",
                           "adjudicated_at_run", "adjudicated_at_commit", "review_after_days")

_ADJ_ID_RE = re.compile(r"^A-[0-9]{3,}$")
ENTRY_MARKER_PREFIX = "<!-- entry:"
```

### JSON の読み方 (strict)

```python
def _no_duplicate_keys(pairs):
    """重複キーを拒否する。json.loads の既定は後勝ちで、静かに片方を捨てるため。"""
    seen = {}
    for key, value in pairs:
        if key in seen:
            raise ValueError(f"duplicate key: {key!r}")
        seen[key] = value
    return seen


def _reject_non_finite(token):
    raise ValueError(f"non-finite number is not allowed: {token}")


def _loads(text):
    return json.loads(text, object_pairs_hook=_no_duplicate_keys,
                      parse_constant=_reject_non_finite)
```

`ValueError` / `json.JSONDecodeError` はすべて行番号つきの `RenderError` へ包む。

### 検証

`load_adjudications(path)`:

1. ファイル不在 / 実レコード 0 件 → error (`#` 始まりと空行は読み飛ばす)
2. 各行が object であること。parse error は行番号つき
3. `adjudication_id` が `^A-[0-9]{3,}$` に一致し、**重複しない**こと
4. `RENDERED_MACHINE_FIELDS` の最小 shape:
   `verdict` 非空文字列 / `scope` が `scope_kind`・`scope_value` を持つ dict /
   `source_finding_ids` が非空文字列の非空 list / `adjudicated_at_run`・
   `adjudicated_at_commit` 非空文字列 / `review_after_days` が正の int (bool 拒否) /
   `supersedes` はあるなら非空文字列。
   **照合器と同じ検証を全部は重複させない** — 生成で参照する項目だけを見る
5. `context` が無ければ通す
6. `context` があるなら: dict / `CONTEXT_KEYS` 以外のキーは error /
   `title` は非空文字列かつ CR・LF を含まない / `narrative` 非空文字列 /
   `spec_basis` は非空文字列の非空配列 / `reopen_condition` はあるなら非空文字列 /
   **どの値にも `ENTRY_MARKER_PREFIX` が現れてはならない**
7. **`spec_basis` のパス実在は検証しない** (実在検査を生成の必須条件にすると、
   通常のリファクタでファイルが動いただけで生成が止まる。実在検査はテスト 34 の担当)

`load_migration(path)`:

1. 単一 JSON object (配列は error) / 読めなければ error
2. `version` が正の int (bool 拒否)
3. `provenance` が dict で、必須キー
   (`source_file` / `source_commit` / `source_lines` / `source_block_headings` /
   `migrated_at` / `machine_projection_sha256` / `note`) をすべて持ち、型が正しいこと。
   `source_block_headings` は**非空文字列の list で、件数が `block_count` と一致し、一意**。
   `machine_projection_sha256` は `{adjudication_id: 64 桁 hex}` の非空 dict
4. `block_count` が int (bool 拒否) かつ `EXPECTED_BLOCK_COUNT` と一致
5. `entries` が list で長さ `block_count`
6. 各 entry: `key` 非空・一意 / `key_kind` ∈ `MIGRATION_KEY_KINDS` /
   `target` ∈ `MIGRATION_TARGETS` /
   `field_minimums` が `{欄名: 正の int}` の非空 dict (欄名 ∈ `CONTEXT_KEYS`、bool 拒否) /
   `required_fragments` が `{"field": 欄名, "value": 非空文字列}` の非空 list

`check_migration(migration, adjudications)`:

- 各 entry を `_resolve()` で**ちょうど 1 件**へ解決 (`adjudication_id` の完全一致)。
  解決先に `context` が無ければ error
- 欄ごとの本文を取り出す (`spec_basis` は要素を `"\n"` で連結した文字列として扱う)。
  `field_minimums` の各欄について `len(本文) >= 下限`。欄が無ければ error
- `required_fragments` の各断片が、**宣言された欄の中で**識別子境界つきに現れること
  (別の欄に偶然あっても通らない)
- `provenance.machine_projection_sha256` の各 id について、
  現在の登録から `context` を除いた射影 (`json.dumps(..., sort_keys=True, ensure_ascii=False)`) の
  sha256 が pin と一致すること。**pin に無い id は検査しない** (移行後に増えた登録のため)

```python
# 識別子を構成する文字。台帳が実際に使う識別子の文字集合に揃える
# (finding id `F-1-02` / TODO id `T095` / `feedback-probe.js`)。
# `-` と `.` を外すと `F-1-02` が `F-1-02-extra` の一部にも当たる。
# 日本語は含めない — 「T095 の実装フェーズ」のように直後へ日本語が続くのは正当な出現である。
_IDENT_CHARS = frozenset(
    "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_.-")


def fragment_present(fragment, text) -> bool:
    """断片が識別子の境界で現れるか。

    無境界の部分文字列一致だと、`T095` を要求しているのに本文へ `T0950` しか残っていない
    場合でも通ってしまう (短い参照が長い別参照へ誤って当たる)。
    断片の端が識別子文字のときだけ、その側に識別子文字が続かないことを要求する。
    """
    if not fragment:
        return False
    guard_left = fragment[0] in _IDENT_CHARS
    guard_right = fragment[-1] in _IDENT_CHARS
    i = text.find(fragment)
    while i >= 0:
        j = i + len(fragment)
        left_ok = not guard_left or i == 0 or text[i - 1] not in _IDENT_CHARS
        right_ok = not guard_right or j >= len(text) or text[j] not in _IDENT_CHARS
        if left_ok and right_ok:
            return True
        i = text.find(fragment, i + 1)
    return False
```

### 出力の構造

- 登録は `adjudication_id` の**数値部で昇順**に並べる (`A-999` < `A-1000`)
- 各項目は**機械マーカー**で始める。完全性の検査はこのマーカーだけを見る
  (見出しや本文の走査ではないので、`title` や `narrative` に見出し風の文字列があっても壊れない)

```
<!-- generated by .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py -->
<!-- DO NOT EDIT: 入力は ledger/adjudications.jsonl (登録一覧と経緯) と
     ledger/spec-ledger-migration.json (移行検査)。
     再生成: python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py -->

# bug-hunt 仕様台帳 (spec-ledger) — 裁定済み事象の可視化

**このファイルは生成物である。手で編集しない。**
経緯は `ledger/adjudications.jsonl` の `context` に書き、上のコマンドで再生成する。
手編集と再生成忘れは `--check` が検出する。**ただし CI では走らないので、
再生成を忘れたまま古い内容が残っている状態は起こり得る** (下の「この文書の限界」)。
運用手順の正本は `ledger/README.md` であり、本ファイルは「登録の可視化」だけを担う。

## 使い方 (bug-hunt 実行者へ)

- finding を起票する前に本台帳を検索すること。**有効性が `active` の項目に載っている事象は
  再起票しない** (「既知」と一行記録して次へ)。
- **`superseded` の項目は履歴である。判断の正本は後継の登録**であり、
  `superseded` を根拠に再起票を止めてはならない。
  照合器 (`validate_findings.py --annotate`) も `active` の登録だけを照合に使う。
- 同一事象が再発したと感じたら、**仕様根拠**を実コードで確認する。コードが台帳と乖離していれば
  regression の可能性があるので、その差分を根拠に新規 finding として起票してよい。

## この文書の限界

- 内容が最新である保証は無い。`--check` を人が走らせたときにだけ drift が分かる。
- 経緯の**正しさ**は機械が見ていない (形・全数性・痩せ・drift だけを見る)。

---

## 登録一覧 (adjudications.jsonl の可視化)

<!-- entry: A-001 -->
### A-001 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた

- 有効性: **active**
- 由来 finding: F-1-02
- 判定: false_positive / 対象面: route_name=projects.manuals.destroy
- 確定: run 20260803-203721 (commit 22d6d30) / 見直し期限: 180 日
- 仕様根拠: {spec_basis を ` ; ` で連結}
- 再オープン条件: {reopen_condition}

{narrative}

<!-- entry: A-002 -->
### A-002 — (経緯は未記入)

- 有効性: **superseded** (A-003 に差し替えられた。判断の正本は後継)
- 由来 finding: F-3-01
- 判定: intentional / 対象面: route_name=organizations.members.destroy
- 確定: run 20260812-100645 (commit 6d0cf1d) / 見直し期限: 180 日
- **経緯は未記入** (この登録には `context` が無い。書くときは `adjudications.jsonl` の
  当該行へ `context` を足して再生成する)

<!-- entry: A-003 -->
### A-003 — …

- 有効性: **active**
- 差し替え: A-002 を差し替えた
…

---

## 移行の全数性 (機械可読)

移行元 spec-ledger.md の全ブロックが上のどこかへ移ったことを機械が突き合わせる索引。
1 行 1 鍵。人向けの本文中の言及と取り違えないため、完全一致で比べる。

<!-- migration-keys:begin -->
- key: A-001
<!-- migration-keys:end -->
```

- 「差し替え」行は 2 方向を出す: 自分が supersede した id (`supersedes`) と、
  **自分を supersede している全 id を昇順で**並べたもの
  (照合器は「同じ id を supersede する登録が 2 件」を禁じていないので単数と仮定しない)

### CLI と原子性

| 呼び方 | 動作 |
|---|---|
| (引数なし) | 生成して原子的に書き、`wrote … (N chars)` を出す |
| `--check` | 生成結果と現物を比較。違えば unified diff (先頭 200 行) を stderr へ出し、**再生成コマンドを添えて** exit 1 |
| `--output` / `--migration` / `--adjudications` | テスト用の差し替え |

```python
def write_atomically(text, path):
    """同一ディレクトリの一時ファイルへ書いてから置換する。

    保証する: 通常の失敗 (検証エラー・書き込みエラー・置換エラー) では
              既存ファイルが 1 バイトも変わらないこと。一時ファイルを残さないこと。
    保証しない: 電源断・ファイルシステム破損に対する耐性 (fsync していない)。
    """
    directory = os.path.dirname(os.path.abspath(path))   # 別 FS を跨がないため必ず出力と同じ場所
    mode = os.stat(path).st_mode & 0o777 if os.path.exists(path) else 0o644
    fd, tmp = tempfile.mkstemp(dir=directory, prefix=".spec-ledger.", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as fh:
            fh.write(text)
        os.chmod(tmp, mode)          # mkstemp は 0600 を作るので、生成物の mode を明示する
        os.replace(tmp, path)
    except BaseException:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
```

入力の検証・本文の組み立てはすべて `write_atomically` を呼ぶ**前**に終える
(`build()` が例外を投げた時点でファイルには触れていない)。

### リスク

- 生成器が壊れると申し送りを更新できなくなる。→ 影響は文書生成に閉じており、
  **照合器と bug-hunt の走行には一切影響しない** (テスト 36 が構造的に固定)。

---

## 施策 2: 移行台帳 `spec-ledger-migration.json`

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/spec-ledger-migration.json` (新規)

### 変更後 (確定値。`<>` は移行 commit で埋める)

```json
{
  "version": 1,
  "block_count": 1,
  "provenance": {
    "source_file": ".claude/skills/app-bug-hunt/spec-ledger.md",
    "source_commit": "<移行 commit の親 sha>",
    "source_lines": "81-113",
    "source_block_headings": [
      "#### F-1-02 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた"
    ],
    "migrated_at": "<YYYY-MM-DD>",
    "machine_projection_sha256": {
      "A-001": "<context を除いた正規化 JSON の sha256>",
      "A-002": "<同上>",
      "A-003": "<同上>"
    },
    "note": "移行元はこの変更で生成物へ置き換わる。以後この台帳と再照合することはできないので、内容の同一性の確認は移行 commit で 1 度だけ人が行った。machine_projection_sha256 は移行時点の機械項目を pin したもので、以後の黙った書き換えを検出する。"
  },
  "entries": [
    {
      "key": "A-001",
      "key_kind": "adjudication_id",
      "target": "adjudications",
      "field_minimums": {
        "narrative": 437,
        "reopen_condition": 230
      },
      "required_fragments": [
        {"field": "narrative", "value": "feedback-probe.js"},
        {"field": "narrative", "value": "T095"},
        {"field": "reopen_condition", "value": "AUTO_DISMISS_MS"},
        {"field": "reopen_condition", "value": "installed_now"}
      ]
    }
  ]
}
```

- **下限文字数の根拠**: 施策 3 の本文を実測して `narrative` 486 文字 /
  `reopen_condition` 256 文字。それぞれ 9 割を切り捨てて 437 / 230 とした
  (実測ちょうどにすると誤字修正 1 文字で赤くなる。上限は設けない — 増えるのは問題ではない)。
- **断片の欄が分かれている理由**: `AUTO_DISMISS_MS` と `installed_now` は
  `narrative` ではなく `reopen_condition` にある。欄を分けずに `narrative` だけを探すと
  移行検査が必ず落ちる (Codex 詳細レビュー R1 [Critical])。
- **`heading_keyed_count` は持たない**。参照実装は run を特定できなかったブロックを
  `heading` 鍵で逃がすためにこの数を持つが、aicue の `key_kind` 語彙は 1 語しかないので
  常に 0 で退化する。代わりに `key_kind` の語彙が閉じていることをテスト 23 が固定する。

### 台帳自身が弱められないようにする

移行台帳を書き換えれば断片も下限も消せる。したがってテスト側に**期待値の定数**を置き、
台帳と**完全一致**で突き合わせる (テスト 23):

```python
EXPECTED_MIGRATION = {
    "A-001": {
        "key_kind": "adjudication_id",
        "target": "adjudications",
        "field_minimums": {"narrative": 437, "reopen_condition": 230},
        "required_fragments": {
            ("narrative", "feedback-probe.js"),
            ("narrative", "T095"),
            ("reopen_condition", "AUTO_DISMISS_MS"),
            ("reopen_condition", "installed_now"),
        },
    },
}
```

```python
EXPECTED_MACHINE_PROJECTION_SHA256 = {
    "A-001": "<64 桁 hex>",
    "A-002": "<64 桁 hex>",
    "A-003": "<64 桁 hex>",
}
```

下限を「以上」ではなく**完全一致**で見るのは、下げる変更をテストごと通されないためである。
`required_fragments` の比較は**要素数を含めて**行う (集合だけだと重複を見逃す)。
`EXPECTED_MACHINE_PROJECTION_SHA256` は移行台帳の `provenance.machine_projection_sha256` と
現在の登録から計算した値の**三点**で突き合わせる。

### リスク

- 台帳とテスト定数の二重管理になる。→ **意図した二重化**である
  (片方だけを弱める変更を赤にするのが目的)。README にその旨を書く。

---

## 施策 3: A-001 に `context` を足す (移行の本体)

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl` (A-001 の行。末尾に `context` を足す)

### 現行 (A-001。抜粋)

```json
{"adjudication_id": "A-001", "species_key": "other:video_manual:delete:self",
 "scope": {"scope_kind": "route_name", "scope_value": "projects.manuals.destroy"},
 "conditions": {...}, "symptom": {...}, "verdict": "false_positive",
 "rationale_ref": "devnotes/20260804-0021-ux-small-gaps/detailed-design.md",
 "source_finding_ids": ["F-1-02"], "adjudicated_at_run": "20260803-203721",
 "adjudicated_at_commit": "22d6d30", "watch_globs": [...], "review_after_days": 180}
```

### 変更後 (既存キーは 1 つも変えない。`context` を足すだけ)

```json
"context": {
  "title": "動画マニュアル削除後に「成功 flash が出ない」ように見えた",
  "spec_basis": [
    "app/Http/Controllers/Projects/VideoManualController.php:230-232 削除後 projects.show へ redirect し ->with('success', '動画マニュアルを削除しました')",
    "resources/js/lib/stores/toast.ts:23-29 success/info/warning は 4000ms で auto-dismiss、error のみ null = 自動消去しない",
    "resources/js/components/organisms/ToastContainer.svelte role=\"status\" + data-testid=\"toast-{type}\" で描画",
    "tests/Browser/FlashToastTest.php 着地マーカーと同一時間窓で toast-success が可視になることを Chromium / WebKit の 2 レーンで pin"
  ],
  "narrative": "**なぜ誤検知に見えたか**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**\n\n**driver 側の再発防止**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に feedback probe (`.claude/skills/app-bug-hunt/probes/feedback-probe.js`) を仕込み、直後に読む。「事後 snapshot に無い」を根拠に H7 を起票することを禁止した。回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。",
  "reopen_condition": "次のいずれか。(a) VideoManualController::destroy が ->with('success', ...) を落とした、(b) toast.ts の success 用 AUTO_DISMISS_MS が大幅に短縮された、(c) feedback probe が installed_now:false かつ seen(visible:true) / present_new ともに空を返した。**probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**"
}
```

### `spec_basis` の書式 (テスト 34 の契約)

**1 要素 = 先頭に参照 (パス。位置指定 `:123-125` や `#anchor` は任意)、空白、以降に説明**。
テストは**先頭の空白区切りトークン**を取り、次を**全要素について**要求する:

1. `^[\w./-]+\.(php|ts|js|svelte|md|json|ya?ml|py|sh)([:#][\w.-]*)*$` に一致すること
   (**一致しない要素は「対象外」ではなく失敗**)
2. 絶対パス (`/` 始まり) と `..` を含まないこと
3. `(REPO_ROOT / path).resolve()` が `REPO_ROOT` 配下にあること (symlink による脱出の拒否)
4. 解決先が**通常ファイル**であること (ディレクトリ・特殊ファイルを拒否)
5. 位置指定とアンカーは捨てて実在だけを見る (行番号は見ない。
   通常のリファクタで台帳テストが壊れる保守負債を作らないため。現行テストの判断を継承)

### 移行元との突合 (人が 1 度だけ行う。移行台帳では代替できない)

移行 commit で、旧 `spec-ledger.md` の 85-113 行と上の `context` を並べ、9 欄の行き先を確認する:

| 旧 9 欄 | 行き先 |
|---|---|
| 判定 | 既存 `verdict` (`false_positive`) |
| 根拠 (file:line) | `context.spec_basis` (4 件すべて) |
| なぜ誤検知に見えたか | `context.narrative` 前半 |
| driver 側の再発防止 | `context.narrative` 後半 |
| watch_globs | 既存 `watch_globs` (旧欄も「正本は registry」と書いていた) |
| review_after_days | 既存 `review_after_days` (180) |
| 確定した run_id | 既存 `adjudicated_at_run` / `adjudicated_at_commit` |
| 再オープン条件 | `context.reopen_condition` |
| 機械 registry | **消える** (registry そのものが正本になり問いが成立しない) |

### テスト計画

- テスト 1 / 7 / 27 / 28 / 30 / 32 / 34 が同時に効く。
- 手で追加確認 (照合器への無影響):

  ```bash
  cd .claude/skills/app-bug-hunt
  python3 ledger/validate_findings.py ledger/example.findings.jsonl \
      --adjudications ledger/adjudications.jsonl        # errors 0 のまま
  ```

### リスク

- JSONL の 1 行が長くなり読みにくい。→ 元から 1 行 = 1 登録の形式で、
  読む窓口は生成物の `spec-ledger.md` になるので許容する。

---

## 施策 4: A-003 に `context` を足す (一次資料の範囲で)

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl` (A-003 の行)

### 一次資料と、その扱い (経緯を後から創作しない)

| 資料 | 位置づけ |
|---|---|
| A-003 自身の `rationale_ref` | **当時 (2026-08-12) の判断そのもの**。`narrative` の核はここから採る |
| `devnotes/20260812-100645-bug-hunt/report.md` の F-3-01 節と「事後の決着」表 | 当時の run 成果物 (症状と「バグと断定しない根拠」) |
| `devnotes/20260812-100645-bug-hunt/findings-merged.jsonl` の F-3-01 | 当時の機械記録 (species / symptom_tokens / surface / observed_conditions) |
| `AGENTS.md` セキュリティ不変条件 9 | 判断の拠り所 (当時から現在まで同一) |
| `app/Http/Controllers/Organizations/OrganizationMemberController.php` / `app/Policies/OrganizationPolicy.php` | **実装時に確認した現行の実装**。当時の判断根拠ではない |

Codex 概念レビュー Round 3 の指摘どおり、最後の 1 行は「当時の根拠」と混ぜずに注記する。

### 変更後 (骨子)

```json
"context": {
  "title": "同一組織内のメンバー削除で 403 と 404 が分かれ、組織内の id 存在を弱く推測できる",
  "spec_basis": [
    "AGENTS.md#セキュリティ不変条件アプリ都合で緩めない 層 2 のテナント境界 404 は層 3 の認可 403 より前 (当時の判断の拠り所)",
    "devnotes/20260812-100645-bug-hunt/report.md 当該 run の F-3-01 節と事後の決着表 (当時の一次記録)",
    "devnotes/20260812-100645-bug-hunt/findings-merged.jsonl 当時の機械記録 (species / symptom_tokens / surface / observed_conditions)",
    "app/Http/Controllers/Organizations/OrganizationMemberController.php 実装時に確認した現行の実装 (当時の判断根拠ではない)",
    "app/Policies/OrganizationPolicy.php 実装時に確認した現行の実装 (当時の判断根拠ではない)"
  ],
  "narrative": "**当時の判断 (run 20260812-100645 / commit 6d0cf1d)**: 同一組織内で権限が足りなければ 403 が設計どおりであり、404 へ潰すと文書化済みの 3 層モデル (層 2 のテナント境界 = 404 は層 3 の認可 = 403 より前) に反する。cross-tenant の存在秘匿とは層が違うため、bug-hunt は「バグと断定しない」として needs_spec で挙げ、事後に intentional として登録した。\n\n**この経緯は 2026-08-17 の移行時に、当時の rationale_ref と run 成果物から起こしたものである** (2026-08-12 の時点では人間向けの申し送りが書かれていなかった)。当時確認されていない事実は足していない。",
  "reopen_condition": "次のいずれか。(a) 403 / 404 の分岐がテナント境界 (層 2) の判定より前で起きるようになった、(b) 同じ差が cross-org からも観測できるようになった、(c) 同一組織内の存在秘匿要件そのものが変わった (組織内でも id の存在を隠す方針になった)、(d) nested route の binding またはテナント境界 404 の実装が変わった。**(b)-(d) に対応する load-bearing なファイルは A-003 の watch_globs に入っていないため、これらの変化は照合器の invalidation では自動検知されない。**"
}
```

- `AGENTS.md#…` のアンカーは**実在する見出しに合わせる**こと
  (テスト 34 はパス部 `AGENTS.md` の実在しか見ないが、読み手のために正しく書く)。

### この施策を落とす条件

上の一次資料から `title` / `spec_basis` / `narrative` を復元できないと判断したら、
**施策 4 を丸ごと落とす**。掲載の完全性契約により A-003 は「経緯は未記入」の項目として
必ず現れるので欠落にはならない。移行台帳には A-003 を入れない
(移行元に A-003 のブロックは存在しないため。`block_count` は 1 のまま)。

### テスト計画

- テスト 7 (掲載) / 15 (context の形) / 34 (`spec_basis` の形式と実在)。
- A-002 は `context` を持たないままにする → テスト 10 の実データ側の裏づけになる。

### リスク

- 事後に書いた経緯が「当時の判断」と読まれる。→ `narrative` に**いつ・何から起こしたか**を明記する。

---

## 施策 5: `spec-ledger.md` を生成物へ置換

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/spec-ledger.md` (112 行 → 生成物)

### 消すもの (後方互換の並走を残さない)

| 現行 | 扱い |
|---|---|
| 1-19 行: registry との「対」の表と注記 | **消す** (対ではなくなる。役割は生成ヘッダが書く) |
| 22-35 行: 使い方 (bug-hunt 実行者へ) | 生成器の固定文へ移す。**有効性による限定を足す** |
| 37-47 行: 書式ルール | **消す** (書式は生成器が持つ) |
| 51-77 行: 初回登録テンプレート (9 欄) | **消す** (欄は `context` の形として生成器が検証する) |
| 81-113 行: run 20260803-203721 申し送り / F-1-02 | **A-001 の `context` へ移す** (施策 3) |

**残骸を残さない**ことは移行台帳 (施策 2) と `--check` の byte 比較 (テスト 1/2) が担保する。
参照実装 (aigenba) は移行後に旧手書きの残骸を 96 行残しており、この機構が無かったことが原因である。

### この施策が主張してよい保証 (Codex 詳細レビュー R1 [Critical] を反映)

> **正常に再生成された出力では**、全登録がちょうど 1 回掲載される。
> 再生成を忘れた状態は `--check` か `python3 -m unittest` を走らせたときに検出される。
> **CI が実行しないため、これは継続的なリポジトリ不変条件ではない。**

「登録したのに申し送りに無いことは起こらない」とは書かない (再生成忘れが起こり得るため)。

### 生成手順

```bash
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check   # exit 0 を確認
```

---

## 施策 6: `ledger/README.md` の更新

### 変更箇所

- 「構成」表 (8-13 行): `render_spec_ledger.py` と `spec-ledger-migration.json` を足す
- 運用ガード (c) 手順 6 (183-184 行): 書き換える
- 新設節「申し送りの生成物化」

### 現行 (運用ガード (c) 手順 6)

```
6. 人間可読の申し送り(「過去 run で SPEC / DOC と確定した事象を再起票しない」)は
   機械 registry の対として `.claude/skills/app-bug-hunt/spec-ledger.md` に書く。
```

### 変更後

```
6. 人間可読の申し送りは**別ファイルに手書きしない**。経緯は同じ行の `context` に書く
   (`title` / `spec_basis` / `narrative` / 任意の `reopen_condition`。
   キーはこの 4 つで閉じており、未知キーは生成器が拒否する)。書いたら再生成する:

   ```bash
   python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py
   ```

   `context` が無い登録も、**正常に再生成すれば**「経緯は未記入」として必ず載る
   (掲載の完全性契約)。ただし**再生成を忘れた状態は CI では検出されない**ので、
   登録を足したら必ず上のコマンドを走らせること。
```

### 新設節「申し送りの生成物化」に書くこと

1. **入力は 3 つに分かれる** — 登録一覧の入力は `adjudications.jsonl`、
   経緯本文の入力はその `context`、移行検査の入力は `spec-ledger-migration.json`。
2. **検証責務の二層分離**:

   | | 検証するもの | 失敗したときに起きること |
   |---|---|---|
   | `validate_findings.py` (照合器) | 抑制判断に要る機械項目 | registry 全体が無効 = 抑制が止まる (既存の挙動) |
   | `render_spec_ledger.py` (生成器) | `context` の形・移行台帳・断片・掲載の完全性 | 生成物を 1 バイトも書かずに落ちる |

   > **境界の正確な位置**: 照合器から隔離されているのは
   > 「**JSON として妥当なまま `context` の形だけが壊れている**」場合である。
   > **JSONL の構文そのものを壊した場合は従来どおり `json parse error` になり、
   > registry 全体が fail-closed で無効になる** (経緯の欄も同じ 1 行に載っているため)。
   > 構文エラーまで隔離したければ経緯を別ファイルへ分けるしかないが、
   > 本設計はその形を採らない (裁定が求めているのは「登録の文脈項目へ移す」ことである)。

3. **有効性の扱い**: 「再起票しない」は `active` の登録にだけ効く。
   `superseded` は履歴であり、照合器の annotate も `active` だけを見る。
4. **append-only の適用範囲**: 機械項目は append-only + supersede、
   `context` は Git 履歴下で追記・訂正できる。機械項目の黙った書き換えは
   移行台帳の `machine_projection_sha256` の pin がテストで検出する。
5. **検証コマンドと、その保証の限界**:

   ```bash
   python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check
   python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
   ```

   > **これらは CI では走らない。** `.github/workflows/ci.yml` が起動する bug-hunt 関連の検査は
   > 目録のドリフト検査 (`scripts/bug-hunt-inventory-check.sh`) だけで、`ledger/` と `coverage/` の
   > Python レーンはどの job からも実行されていない。したがって生成物のドリフトは
   > **人が上のコマンドを走らせたときにだけ**見つかる。
   > (Python レーンを CI へ載せることは家系の裁定 AG-152 が別途求めている。ここでは扱わない。)

6. **移行台帳の役割と保証しないもの**: 移行元は消えているので再照合はできない。
   見られるのは「移行時に決めた断片と下限文字数が以後も保たれること」だけである。
   台帳自身を弱める変更は `test_spec_ledger.py` の `EXPECTED_MIGRATION` が赤にする
   (**意図した二重管理**である)。

---

## 施策 7: `AGENTS.md` の bug-hunt 節に 1 項足す

### 変更箇所

- ファイル: `AGENTS.md` §bug-hunt (「目録は生成物 (T176)」の項の直後)

### 変更後 (追加する 1 項)

```markdown
- **申し送りも生成物**: `spec-ledger.md` は手で書かない。経緯は
  `ledger/adjudications.jsonl` の `context` (`title` / `spec_basis` / `narrative` /
  任意の `reopen_condition`。未知キーは拒否) に書き、
  `python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py` で再生成する。
  **正常に再生成された出力**では、経緯を書いていない登録も「経緯は未記入」として
  ちょうど 1 回載る。ただし**再生成忘れは CI では捕まらない**
  (`--check` と `python3 -m unittest` を人が走らせたときにだけ分かる)。
  `context` は**照合器 (`validate_findings.py`) が読まない** —
  **JSON として妥当なまま形だけ壊した**場合は抑制機構は止まらず、止まるのは生成だけである。
  **JSONL の構文を壊した場合は従来どおり registry 全体が fail-closed になる**。
  「再起票しない」の案内は有効性が `active` の登録にだけ効く (`superseded` は履歴)。
```

---

## 検証コマンド (実装完了の条件)

```bash
# 本タスクの本体
python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'   # 全緑
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check            # exit 0

# 照合器への無影響 (context 追加が registry を壊していないこと)
cd .claude/skills/app-bug-hunt && python3 ledger/validate_findings.py \
    ledger/example.findings.jsonl --adjudications ledger/adjudications.jsonl        # errors 0

# AGENTS.md の検証コマンド一式 (PHP / フロントは 1 行も変えていないことの確認)
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages

# bug-hunt 目録のドリフト検査 (無影響の確認)
bash scripts/bug-hunt-inventory-check.sh                                            # exit 0
```

## 実装モード

| 項目 | 内容 |
|---|---|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は `.claude/skills/app-bug-hunt/` の同梱物と `AGENTS.md` 1 項に閉じており、アプリコード・DB・依存関係に触れない |
| 実装場所 | **worktree** (`scripts/setup-worktree.sh <task-id>`)。main 直接実装は禁止 |
| 競合リスク | 同じ `adjudications.jsonl` を触る作業 (新しい bug-hunt run の adjudicate) と並行しない |

## 後続タスク (本設計から必ず起票する)

本タスクの完了報告と同時に、次の 1 件を `docs/TODO.md` へ登録する
(`app-todo-add` スキル経由。本設計ディレクトリを設計リンクとして引く)。
**登録しないまま本タスクを閉じない** — 閉じると A-001 の invalidation の穴が追跡から落ちる。

| 項目 | 内容 |
|---|---|
| タイトル | bug-hunt 裁定 A-001 の監視対象に toast.ts を足す (再オープン条件と watch_globs の食い違いを閉じる) |
| テーマ | test |
| 内容 | A-001 の再オープン条件 (b) は `resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS` を挙げているが、`watch_globs` に同ファイルが無く invalidation が発火しない。append-only 規約に従い A-001 を supersede する新登録を足して監視対象を揃える。移行台帳の鍵 (A-001) と `machine_projection_sha256` の pin も同時に更新する |
| 優先度 | Medium |
| モード | standalone |
| 前提 | 本タスク (申し送りの生成物化) が main に入っていること |

## 保証しないこと (誇張しない)

- **CI では 1 つも走らない**。生成物のドリフト・移行の痩せ・掲載の完全性は、
  人が `python3 -m unittest` か `--check` を走らせたときにだけ検出される
  (現行の `test_spec_ledger.py` も同じで、後退ではない)。
  したがって「**登録したのに申し送りに無い状態は起こらない**」とは言えない。
  言えるのは「**正常に再生成された出力では**全登録がちょうど 1 回載る」までである。
- **JSONL の構文エラーは照合器から隔離されない**。隔離されるのは
  「JSON として妥当なまま `context` の形だけが壊れている」場合に限る。
- **経緯の内容が正しいことは検証しない**。機械が見るのは形・全数性・痩せ・drift だけである。
- **`watch_globs` の実在は誰も検査しない** (A-002 が実在しないパスを持ったままである)。
  家系の台帳がこの不足を settle 送りにしているため本タスクでは触らない。
- **A-001 の invalidation の穴を閉じない**: A-001 の再オープン条件 (b) は
  `resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS` を挙げているが、
  A-001 の `watch_globs` にこのファイルは無い。したがって
  **`toast.ts` が変わっても照合器の invalidation は発火せず**、
  本物の退行を旧 false-positive として downrank し続けうる。
  直すには append-only 規約に従って A-001 を supersede する新しい登録が要り、
  移行台帳の鍵と経緯の置き場所が同時に動く。**移行と判断の変更を 1 つの変更に混ぜると
  どちらが原因で赤くなったか分からなくなる**ため、本タスクでは閉じない。
  TODO 登録時に後続タスクの候補として申し送る。
- **`spec_basis` のパス実在検査はテストの担当**であり、生成の必須条件ではない。
  テストを走らせない限り腐りは見つからない。
- **原子的書き込みは電源断まで耐えない** (`fsync` していない)。
  保証するのは「通常の失敗では既存ファイルが 1 バイトも変わらない」ことまでである。
</content>

---

改めて各施策の判定と全体判定 (APPROVED / CHANGES_REQUESTED) をお願いします。
