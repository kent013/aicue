# 全体判定: CHANGES_REQUESTED

方向性は妥当です。二重正本による実際の不整合を解消し、参照実装へ追従することは North Star へ間接的に貢献します。禁止事項への直接の抵触もありません。

ただし、現設計には実装不能な矛盾と、fail-closed 境界・掲載完全性・supersede の保証を実際より強く書いている箇所があります。特に以下は修正必須です。

- `narrative_min_chars: 0` は生成器自身の契約に違反する。
- 必須断片のうち `AUTO_DISMISS_MS` と `installed_now` は `narrative` ではなく `reopen_condition` にある。
- `context` の編集で JSON 構文を壊すと、照合器も registry 全体を無効化する。
- CI 非実行なので、未再生成の `spec-ledger.md` が残る状態は起こり得る。
- superseded 登録まで「再起票しない」と案内するのは危険。
- 移行台帳自体を弱めれば、断片・下限を削ってもテストが通る。
- 根拠パス検査は不正形式、絶対パス、`..` で回避できる。

## 施策 0: 腐り検知テストの差し替え

判定: REQUEST_CHANGES

- [Critical] `test_spec_basis_references_exist` は、不正な先頭トークンを「検査対象外」として通せます。また、現案の文字クラスは絶対パスと `..` を許し、`REPO_ROOT / "/etc/passwd"` はリポジトリ外を検査します。

  修正案: `spec_basis` の全要素について、先頭トークンが所定形式でなければ失敗させてください。絶対パスと `..` を拒否し、`resolve()` 後に `REPO_ROOT` 配下であることと通常ファイルであることを確認します。形式不正、絶対パス、traversal、symlink による外部脱出のテストを追加してください。

- [Critical] 見出し抽出による完全性検査は、`title` や生 Markdown の `narrative` が `### A-999 — ...` を含むと壊れます。`title` は「1 行」と説明されていますが、改行を拒否する契約がありません。

  修正案: `title` の CR/LF を拒否し、その他の表示フィールドでも予約見出し形式を拒否するか、エントリ境界を機械マーカーで囲って厳密に解析してください。予約見出し注入のテストを追加します。

- [Warning] 原子性テストで「現物の sha256」を監視する設計は、失敗した実装が追跡ファイルを破壊し得ます。

  修正案: 一時ディレクトリに sentinel 出力を作り、`--output` でそこだけを対象にしてください。入力検証失敗に加え、書き込み途中・`os.replace` 失敗時にも既存 sentinel が不変であることを確認します。

- [Warning] 「存在しない module の import error」は fail-first ではありますが、26 契約のどれが失敗するかは確認できません。

  修正案: 最小の stub を先に置き、少なくとも完全性、壊れた context、移行断片、drift の代表テストが意図した assertion で赤になることを記録してください。

## 施策 1: 生成器

判定: REQUEST_CHANGES

- [Critical] 「context を書き損ねても抑制機構は止まらない」は不正確です。JSON として妥当なまま context の型・未知キーだけを壊した場合は照合器に影響しませんが、引用符やエスケープを壊すと `json parse error` になり、現行の fail-closed 設計では registry 全体が無効になります。

  修正案: 全文書を「**JSON として parse 可能な context の schema 不備は照合器に影響しない**」へ限定してください。テストも次の両方を固定します。

  - parse 可能だが context schema が不正: matcher は error 0、renderer は失敗
  - JSON 構文が不正: matcher も fail-closed、renderer も失敗

  JSON 構文不備すら照合器から隔離したいなら、context を別ファイルに分離しない限り実現できません。

- [Critical] superseded 登録を含む全項目に対して「ここに載っている事象は再起票しない」と案内するのは危険です。将来、後継が判定や条件を変更した場合に、失効した旧登録が人間側の抑制根拠になります。

  修正案: 各項目に `active` / `superseded` を明示し、「再起票しない」の対象を active 登録だけに限定してください。superseded 項目は履歴であり、後継が判断の正本であると記載します。`--annotate` が実際にどの登録を対象にするかも、照合器の実装と一致させてください。

- [Critical] `mkstemp + os.replace` だけでは、temp が `/tmp` に作られた場合に別ファイルシステムとなり、置換できません。また `mkstemp` の mode 0600 が生成物へ引き継がれます。

  修正案: temp は出力ファイルと同じディレクトリに作り、UTF-8 で書いて flush/close 後に置換してください。既存ファイルの mode を継承するか、新規時は 0644 を明示し、例外時には temp を削除します。電源断耐性まで保証しないなら、その限界も明記します。

- [Warning] 生成器は本文に使用する機械項目を検証しません。`scope`、`source_finding_ids`、`review_after_days` などが欠けると、`RenderError` ではなく `KeyError` や不正表示になり得ます。

  修正案: 生成時に参照するフィールドの最小 shape を検証し、すべて `RenderError` に正規化してください。照合器と完全に同じ検証を重複させる必要はありません。

- [Warning] ID の文字列ソートは `A-1000` と `A-999` を正しく並べません。また生成器は matcher の ID 形式を検証しません。

  修正案: `^A-[0-9]{3,}$` を検証し、数値部分でソートします。1000 境界のテストを追加してください。

- [Warning] Python の `json.loads` は重複キーを後勝ちで処理します。

  修正案: 少なくとも `context`、移行台帳、移行 entry は `object_pairs_hook` で重複キーを拒否してください。`NaN` / `Infinity` も strict JSON として拒否するのが安全です。

## 施策 2: 移行台帳

判定: REQUEST_CHANGES

- [Critical] 例示 JSON の `narrative_min_chars: 0` は、「正の int」「0 を拒否」という設計・テストと正面から矛盾します。このままでは生成不能です。

  修正案: 実測後の具体的な正整数を詳細設計に確定値として記載してください。未確定なら、有効な JSON の完成例として提示しないでください。

- [Critical] `check_migration()` は断片を `context.narrative` だけで探しますが、提示された A-001 では `AUTO_DISMISS_MS` と `installed_now` は `reopen_condition` にしかありません。

  修正案: 断片をフィールド単位にしてください。例:

  ```json
  {"field": "narrative", "value": "T095"}
  {"field": "reopen_condition", "value": "installed_now"}
  ```

  `field` の語彙も閉じ、別フィールドに偶然現れて通らないようにします。

- [Critical] pin されるのはブロック数だけです。`required_fragments` を削り、`narrative_min_chars` を下げる変更を manifest と同時に行えば、痩せても全テストが通ります。

  修正案: A-001 の鍵、target、具体的な下限、必須断片集合をテストまたは生成器の期待定数として意味論的に pin してください。あるいは「manifest 自体が弱められないことは保証しない」と保証を縮小します。現状の「以後も痩せを監視する」という表現には前者が必要です。

- [Warning] `block_count` でも `True` は `1` として通り得ます。

  修正案: `version`、`block_count`、`narrative_min_chars` の全整数項目で bool を明示的に拒否し、それぞれテストしてください。

- [Warning] `provenance` は非空 object だけで通り、必要項目や `source_block_headings` と `block_count` の対応が検証されません。

  修正案: provenance の必須キー・型を閉じ、見出し数、一意性、空文字を検証してください。

## 施策 3: A-001 の移行

判定: REQUEST_CHANGES

- [Warning] `reopen_condition` が `toast.ts` の `AUTO_DISMISS_MS` 変更を再オープン条件にしていますが、A-001 の `watch_globs` に `toast.ts` がありません。照合器の自動 invalidation はこの変更を検知できず、本物の退行を旧 false-positive として downrank し続ける可能性があります。

  修正案: append-only 規約を守って新しい adjudication で A-001 を supersedeし、少なくとも `resources/js/lib/stores/toast.ts` を監視対象へ含めるのが安全です。別タスクにする場合は、本設計がその退行を構造的に防ぐとは主張せず、既知の限界として明記してください。

- [Warning] 既存行へ context を加える運用は、現在の「append-only + supersede」と文字どおりには両立しません。将来 context を後付けする手順も同じです。

  修正案: 「抑制判断に関わる機械項目は append-only、context は Git 履歴下で追記・訂正可能」と規約を限定するか、context 追加も supersede で行うかを明文化してください。今回の移行では機械項目の projection が不変であるテストを置くと安全です。

## 施策 4: A-003 の context

判定: REQUEST_CHANGES

一次資料と現行コードを分離する方針は適切です。ただし次を修正してください。

- [Warning] 再オープン条件が「層の順序が変わったとき」だけでは狭すぎます。同一組織内 ID の秘匿要件が変わった場合、cross-org から観測可能になった場合、nested binding の実装が変わった場合にも旧 intentional 判定は無効になります。

  修正案: 少なくとも「テナント境界より前の短絡」「cross-org からの観測」「同一組織内の存在秘匿要件変更」「nested route/binding の変更」を条件に含めてください。対応する load-bearing ファイルが `watch_globs` に含まれない場合、その限界も明記します。

- [Suggestion] `findings-merged.jsonl` を一次資料一覧に挙げていますが、context 骨子の `spec_basis` には含まれていません。実際に narrative の復元に使うなら参照へ追加し、使わないなら資料一覧から外すと追跡関係が明確になります。

## 施策 5: 生成物への置換

判定: REQUEST_CHANGES

- [Critical] 「登録したのに申し送りに無いことは起こらない」は、CI 非実行という前提と両立しません。registry 更新後に再生成を忘れれば、コミット済み生成物は古いまま残せます。

  修正案: 保証を次へ限定してください。

  > 正常に再生成された出力では全 adjudication がちょうど 1 回掲載される。未再生成は `--check` または unittest を実行した場合に検出される。現時点では CI が実行しないため、継続的なリポジトリ不変条件ではない。

  「起こらない」を維持したい場合は CI 配線が必要ですが、これは明示されたスコープ外です。

- [Critical] 「掲載の全数性」と「再起票禁止」を同一視しないでください。superseded 登録も全数掲載すべきですが、判断根拠として active ではありません。

  修正案: 全数掲載と有効性を別フィールドで表現し、使い方の文言を active 登録に限定してください。

## 施策 6: README 更新

判定: REQUEST_CHANGES

- [Critical] 「context を壊しても抑制機構は止まらない」は JSON 構文エラーを含むように読めます。

  修正案: 「JSON として妥当な context schema の不備」に限定し、JSONL の構文エラーは従来どおり registry 全体を fail-closed にする、と同じ段落で明記してください。

- [Warning] 「入力は adjudications.jsonl の context だけ」は不正確です。生成物は機械項目と移行 manifest も読みます。

  修正案: 「登録一覧の入力は adjudications.jsonl、経緯本文の入力はその context、移行検査の入力は migration manifest」と分けてください。

- [Warning] 検証コマンドが AGENTS.md の必須セットを満たしていません。

  修正案: `pnpm typecheck:packages`、`pnpm build:packages`、`pnpm test:packages` を実装完了条件へ追加してください。また実装は standalone であっても、リポジトリ規約どおり worktree 内で行うことを明記します。

## 施策 7: AGENTS.md 更新

判定: REQUEST_CHANGES

- [Critical] 追加文の「登録したのに申し送りに無いは起こらない」と「経緯を書き損ねても抑制機構は止まらない」は、どちらも保証過大です。

  修正案: 前者は「再生成された出力について」、後者は「JSON として妥当な context schema 不備について」に限定してください。CI 非実行と JSON parse error の fail-closed を同じ項目内で明示する必要があります。

## 期待効果とスコープの評価

仮説である「二重正本は片側更新によって腐る」は、A-002/A-003 の実測で十分に支持されています。生成器への一本化も合理的です。

一方、現状の期待効果は次の範囲に限定されます。

- 再生成した文書内の registry → 文書の欠落を防ぐ。
- 手動検証を実行した時点で drift と移行内容の一部を検出する。
- parse 可能な context schema 不備を matcher の抑制判断から分離する。

CI がなく、bug-hunt 実行者への導線も追加しないため、「常に同期される」「探索時間が確実に本物のバグへ向く」までは保証できません。ただし、裁定追従タスクとしてのスコープ自体は過大ではありません。上記の保証境界と実装矛盾を直せば、実現可能な設計です。