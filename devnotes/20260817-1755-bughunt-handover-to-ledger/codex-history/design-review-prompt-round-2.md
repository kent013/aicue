Round 1 の指摘に対応しました。全 [Critical] (11 件) と全 [Warning] に対応し、反論は 1 件のみです (施策 3 の watch_globs / 別タスク化 + 既知の限界として明記)。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

すべての [Critical] と [Warning] に対応した。反論は 1 件のみ (施策 3 [Warning] 1、別タスク化)。

## 施策 0

### [Critical] `spec_basis` のパス検査が形式不正・絶対パス・`..`・symlink で回避できる
- 判断: **対応する**
- 対応内容: 検査を「一致したものだけ見る」から「**全要素が所定形式であること**」へ反転した。
  先頭トークンが所定形式でなければ失敗。絶対パスと `..` を拒否し、`resolve()` 後に
  `REPO_ROOT` 配下であることと**通常ファイル**であることを確認する。
  形式不正 / 絶対パス / traversal / symlink 脱出の 4 ケースをテストへ追加した。

### [Critical] 見出し抽出は `title` / `narrative` に `### A-999 — ` があると壊れる
- 判断: **対応する**
- 対応内容: 抽出を見出しから**機械マーカー** (`<!-- entry: A-NNN -->`) へ変えた。
  マーカーは生成器だけが書き、`context` の全値に対してマーカーの接頭辞
  (`<!-- entry:`) の混入を拒否する。併せて `title` の CR/LF を拒否する
  (1 行という説明を契約にする)。予約形式の注入テストを 2 本追加した。

### [Warning] 原子性テストが追跡ファイルを壊し得る
- 判断: **対応する**
- 対応内容: 一時ディレクトリの sentinel を `--output` で対象にする形へ変えた。現物には触れない。

### [Warning] import error による fail-first では 26 契約のどれが赤かを確認できない
- 判断: **対応する**
- 対応内容: 最小 stub (`RenderError` と空の `build()`) を先に置き、代表 4 本
  (完全性 / 壊れた context / 移行断片 / drift) が**意図した assertion で**赤くなることを
  記録する手順にした。

## 施策 1

### [Critical] 「context を壊しても抑制機構は止まらない」は JSON 構文エラーを含めて読める
- 判断: **対応する**
- 根拠: 指摘のとおり。`json parse error` は既存 validator が拾い、fail-closed で registry 全体を無効にする。
- 対応内容: 全文書 (詳細設計 / README / AGENTS.md) の文言を
  「**JSON として妥当なまま `context` の形だけが壊れている場合**は照合器に影響しない」へ限定した。
  テストを 2 本に分けた — (a) parse 可能・schema 不正 → 照合器 error 0 / 生成器 `RenderError`、
  (b) JSON 構文不正 → 照合器も fail-closed / 生成器も失敗。

### [Critical] superseded 登録まで「再起票しない」と案内するのは危険
- 判断: **対応する**
- 根拠: 照合器の annotate は `validate_findings.py:583-584` で
  **未 supersede の登録だけ**を照合対象にしている。生成物が全件を等価に見せると、
  失効した旧判断が人間側の抑制根拠になる。
- 対応内容: 各項目に **有効性 (`active` / `superseded`)** を出し、
  「再起票しない」の対象を `active` に限定した。`superseded` 項目には
  「履歴であり、判断の正本は後継」と明記する。判定規則は照合器の実装 (583-584 行) と同一にする。

### [Critical] `mkstemp + os.replace` の置き場所・mode・後始末
- 判断: **対応する**
- 対応内容: temp を**出力と同じディレクトリ**に作り (`dir=os.path.dirname(...)`)、
  UTF-8 で書いて close 後に `os.replace`、例外時は temp を削除、
  既存ファイルがあれば mode を継承し無ければ 0644 を明示する、と契約に書いた。
  **電源断耐性は保証しない**ことも明記した。

### [Warning] 生成器が本文に使う機械項目を検証していない (KeyError の危険)
- 判断: **対応する**
- 対応内容: 生成で参照する項目 (`verdict` / `scope.scope_kind` / `scope.scope_value` /
  `source_finding_ids` / `adjudicated_at_run` / `adjudicated_at_commit` / `review_after_days` /
  任意の `supersedes`) の最小 shape を生成器が検証し、すべて `RenderError` へ正規化する。

### [Warning] id の文字列ソートは `A-1000` と `A-999` を誤る
- 判断: **対応する**
- 対応内容: `^A-[0-9]{3,}$` を生成器が検証し、**数値部でソート**する。1000 境界のテストを追加した。

### [Warning] `json.loads` の重複キー後勝ち / `NaN` `Infinity`
- 判断: **対応する**
- 対応内容: `object_pairs_hook` で**重複キーを拒否**、`parse_constant` で `NaN` / `Infinity` /
  `-Infinity` を拒否する。適用範囲は生成器が読む全入力 (adjudications の各行と移行台帳)。

## 施策 2

### [Critical] `narrative_min_chars: 0` が自分の契約に反する
- 判断: **対応する**
- 対応内容: 実測して確定値を入れた (`narrative` 486 文字 → 下限 437 /
  `reopen_condition` 256 文字 → 下限 230。いずれも実測の 9 割を切り捨て)。

### [Critical] `AUTO_DISMISS_MS` と `installed_now` は `narrative` に無く `reopen_condition` にある
- 判断: **対応する**
- 根拠: 指摘のとおりで、現案のままなら移行検査が必ず落ちる (実装不能)。
- 対応内容: 断片を**フィールド単位**にした (`{"field": ..., "value": ...}`)。
  `field` の語彙は `context` の 4 欄で閉じる。下限文字数も同じくフィールド単位
  (`field_minimums`) にし、`narrative_min_chars` は廃止した。

### [Critical] 移行台帳自身を弱めれば痩せても通る
- 判断: **対応する**
- 対応内容: 移行台帳の**意味論をテスト側の定数で pin** した
  (`EXPECTED_MIGRATION`: 鍵 / `key_kind` / `target` / `field_minimums` の値 /
  必須断片の集合を**完全一致**で固定)。台帳を弱める変更はテストが赤くする。

### [Warning] `block_count` でも `True` が 1 として通る
- 判断: **対応する**
- 対応内容: 全整数項目 (`version` / `block_count` / `field_minimums` の各値) で
  `isinstance(x, bool)` を明示的に拒否し、それぞれテストする。

### [Warning] `provenance` の必須項目・見出し数の対応が未検証
- 判断: **対応する**
- 対応内容: `provenance` の必須キーと型を閉じ、`source_block_headings` の
  **件数が `block_count` と一致**すること・一意であること・空文字でないことを検証する。

## 施策 3

### [Warning] A-001 の `watch_globs` に `toast.ts` が無く、再オープン条件 (b) を invalidation が検知できない
- 判断: **反論せず、別タスクにする (既知の限界として明記)**
- 根拠: 指摘は正しい。ただし直すには append-only 規約に従って A-001 を supersede する
  新しい登録が要り、移行台帳の鍵 (A-001) と経緯の置き場所が同時に動く。
  移行と判断の変更を 1 つの変更に混ぜると、**どちらが原因で赤くなったのか分からなくなる**。
- 対応内容: 詳細設計の「保証しないこと」に、この invalidation の穴が
  **本タスクでは閉じないこと**を明記した。TODO 登録時に後続タスクの候補として申し送る。

### [Warning] 既存行への `context` 追加は append-only 規約と文字どおりには両立しない
- 判断: **対応する**
- 対応内容: 規約を限定して明文化した —
  「**抑制判断に関わる機械項目は append-only + supersede。`context` は Git 履歴下で追記・訂正できる**」。
  さらに、移行時点の**機械項目だけの射影の sha256** を移行台帳の `provenance` に pin し、
  テストが一致を確認する (= `context` の追加が機械項目を動かしていないことの機械的証明、
  かつ以後の「既存行の黙った書き換え」の検出)。

## 施策 4

### [Warning] 再オープン条件が狭すぎる
- 判断: **対応する**
- 対応内容: 「テナント境界より前の短絡」「cross-org からの観測」「同一組織内の存在秘匿要件の変更」
  「nested route / binding の変更」を条件へ足し、**対応する load-bearing ファイルが
  `watch_globs` に入っていない**ことも限界として書く。

### [Suggestion] `findings-merged.jsonl` が一次資料一覧にあるのに `spec_basis` に無い
- 判断: **対応する**
- 対応内容: `spec_basis` に `devnotes/20260812-100645-bug-hunt/findings-merged.jsonl` を足した。

## 施策 5

### [Critical] 「登録したのに申し送りに無いは起こらない」は CI 非実行と両立しない
- 判断: **対応する**
- 対応内容: 保証を「**正常に再生成された出力では**全登録がちょうど 1 回掲載される。
  再生成忘れは `--check` か unittest を走らせたときに検出される。
  CI が実行しないため、継続的なリポジトリ不変条件ではない」へ限定した (全文書で統一)。

### [Critical] 全数掲載と有効性を同一視しない
- 判断: **対応する** (施策 1 の Critical 2 と同じ修正)

## 施策 6

### [Critical] 「context を壊しても抑制機構は止まらない」の限定 / [Warning] 入力の説明が不正確
- 判断: **どちらも対応する**
- 対応内容: README の該当段落に JSON 構文エラーの扱いを併記し、入力を 3 つに分けて書いた
  (登録一覧の入力 = `adjudications.jsonl` / 経緯本文の入力 = その `context` /
  移行検査の入力 = 移行台帳)。

### [Warning] 検証コマンドが AGENTS.md の必須セットを満たしていない
- 判断: **対応する**
- 対応内容: `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を足し、
  **実装は worktree (`scripts/setup-worktree.sh <task-id>`) で行う**ことを明記した。

## 施策 7

### [Critical] AGENTS.md の追加文が保証過大
- 判断: **対応する**
- 対応内容: 「再生成された出力について」「JSON として妥当な `context` の形の不備について」の
  2 つの限定を同じ項目内に書き、CI 非実行も同じ項目に明示した。
</content>

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

### append-only 規約の適用範囲 (今回はっきりさせる)

- **抑制判断に関わる機械項目は append-only + supersede** (既存行を書き換えない)。
- **`context` は Git 履歴下で追記・訂正できる** (照合に一切関与しないため)。
  本移行が既存 3 行へ `context` を足せるのはこの限定による。
- この限定が空手形にならないよう、**移行時点の「機械項目だけの射影」の sha256** を
  移行台帳へ pin し、テストが一致を確認する (施策 2)。
  以後、既存行の機械項目を黙って書き換えるとテストが赤くなる。

### `context` の欄 (閉じた集合)

| 欄 | 型 | 必須 |
|---|---|---|
| `title` | 非空文字列。**CR / LF を含んではならない** (1 行であることを契約にする) | ○ |
| `spec_basis` | 非空文字列の非空配列。各要素の書式は施策 3 で定義 | ○ |
| `narrative` | 非空文字列 (markdown 可) | ○ |
| `reopen_condition` | 非空文字列 | — |

- **未知キーは拒否** (deny-by-default)。
- 全値について、生成器が使う機械マーカーの接頭辞 `<!-- entry:` の混入を拒否する
  (生成物の項目境界を本文から偽装できないようにする)。

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

### 変更後: テスト一覧 (33 本)

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
| 5 | `test_render_is_atomic_on_failure` | 入力を壊して生成を走らせても **sentinel の sha256 が不変** |
| 6 | `test_render_leaves_no_temp_file_behind` | 失敗後に出力ディレクトリへ `.spec-ledger.*.tmp` が残らない |

**B. 掲載の完全性 (概念設計 Critical 1 の機械化)**

| # | テスト | 固定する事実 |
|---|---|---|
| 7 | `test_every_adjudication_id_is_listed_exactly_once` | 生成物の**機械マーカー** `<!-- entry: A-NNN -->` から抽出した id の多重集合が registry の id 集合と一致し、各 1 回 |
| 8 | `test_entry_marker_cannot_be_forged_from_context` | `title` / `narrative` などに `<!-- entry: A-999 -->` を入れると `RenderError` |
| 9 | `test_title_with_newline_is_rejected` | `title` に CR / LF があれば `RenderError` |
| 10 | `test_entry_without_context_is_still_listed` | `context` を持たない登録を足した写しでも掲載され、`経緯は未記入` の印が付く |
| 11 | `test_active_and_superseded_are_labelled_like_the_matcher` | 有効性の判定が `validate_findings` の `active` 算出と一致する (同じ入力で集合比較) |
| 12 | `test_supersede_relations_are_rendered_deterministically` | 同じ id を supersede する登録を 2 件にした写しで、両方の id が**昇順**で表示される |
| 13 | `test_ids_are_sorted_numerically` | `A-999` と `A-1000` を含む写しで数値順に並ぶ (文字列順ではない) |

**C. `context` の検証と fail-closed 境界**

| # | テスト | 固定する事実 |
|---|---|---|
| 14 | `test_unknown_context_key_is_rejected` | 許可外キーで `RenderError` |
| 15 | `test_context_field_type_and_emptiness_rejected` | `title` 空 / `narrative` 非文字列 / `spec_basis` 空配列 / 要素が空 / `reopen_condition` 空 → いずれも `RenderError` |
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
| 23 | `test_migration_manifest_matches_expected_semantics` | テスト側の定数 `EXPECTED_MIGRATION` と**完全一致** (鍵 / `key_kind` / `target` / `field_minimums` の値 / 必須断片の集合)。**台帳を弱める変更をこのテストが赤にする** |
| 24 | `test_block_count_change_fails` / `test_entries_count_mismatch_fails` | 件数の三点一致 (`block_count` / `len(entries)` / `EXPECTED_BLOCK_COUNT`) |
| 25 | `test_duplicate_key_in_manifest_fails` / `test_unknown_key_does_not_resolve` | 鍵の重複と解決不能を拒否 |
| 26 | `test_integer_fields_reject_bool_and_non_positive` | `version` / `block_count` / `field_minimums` の各値で `True` / `0` / `-1` / `"900"` / `None` を拒否 |
| 27 | `test_field_below_minimum_fails` | `narrative` または `reopen_condition` を削ると `RenderError` (痩せの検出) |
| 28 | `test_required_fragment_missing_fails` | 必須断片を消すと `RenderError` |
| 29 | `test_fragment_is_searched_only_in_its_declared_field` | `reopen_condition` の断片が `narrative` に紛れていても通らない |
| 30 | `test_fragment_identifier_boundary` | `T095` を要求して本文が `T0950` なら不一致 / 「`T095` の実装フェーズ」「\`T095\`」は一致 / `xT095` `T095-extra` は不一致 |
| 31 | `test_provenance_shape_and_heading_count` | `provenance` の必須キー・型、`source_block_headings` の件数が `block_count` と一致・一意・非空 |
| 32 | `test_machine_projection_sha256_matches` | 各登録の**機械項目だけの射影** (context を除いた正規化 JSON) の sha256 が `provenance.machine_projection_sha256` の pin と一致する |
| 33 | `test_manifest_shape_is_rejected_when_not_a_single_object` | 配列 / 不在ファイルを拒否 |

**E. 既存方針の継承 / 構造的保証**

| # | テスト | 固定する事実 |
|---|---|---|
| 34 | `test_spec_basis_references_are_well_formed_and_exist` | `context.spec_basis` の**全要素**について先頭トークンが所定形式であること (形式不正は「対象外」ではなく**失敗**)、絶対パス・`..` を拒否、`resolve()` 後に `REPO_ROOT` 配下の**通常ファイル**であること。行番号・アンカーは見ない |
| 35 | `test_spec_basis_rejects_traversal_and_escape` | 絶対パス / `..` / symlink による外部脱出 / 形式不正の 4 ケースが失敗する |
| 36 | `test_matcher_source_never_names_the_handover_files` | `validate_findings.py` の本文に `spec-ledger` / `spec_ledger` / `render_spec_ledger` / `spec-notes` が 1 つも現れない |

> 表の番号は説明用の通し番号であり、実際のテストメソッド数は 36 本前後になる
> (`#15` `#24` `#25` `#26` `#35` は複数の assert を持つ 1 本ずつでも、
> ケースごとに分けても構わない。**分ける方を推奨**する — 失敗時にどの契約が壊れたか分かるため)。

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
HERE = os.path.dirname(os.path.abspath(__file__))
SKILL_DIR = os.path.dirname(HERE)
REPO_ROOT = os.path.dirname(os.path.dirname(SKILL_DIR))   # .claude/skills/app-bug-hunt -> repo root
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

下限を「以上」ではなく**完全一致**で見るのは、下げる変更をテストごと通されないためである。

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

   `context` を書かなくても登録は `spec-ledger.md` に「経緯は未記入」として載る
   (掲載の完全性契約)。**黙って消えることはない。**
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

改めて各施策の判定と全体判定 (APPROVED / CHANGES_REQUESTED) をお願いします。残る [Critical] [Warning] があれば指摘してください。
