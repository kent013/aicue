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
