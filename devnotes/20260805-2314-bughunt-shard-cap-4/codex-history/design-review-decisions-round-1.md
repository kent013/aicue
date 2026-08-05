# 対応マトリクス: design-review Round 1

全体判定: **CHANGES_REQUESTED** (Critical 2 / Warning 4 / Suggestion 4)。
Critical 2 件・Warning 4 件すべてに対応した (1 件は部分対応 + 根拠付き反論)。

## [Critical] 施策 2: `stories_for_shard` 完全性チェックが `set -e` 下で self-test を即死させる
- 判断: **対応する**
- 根拠: 指摘の通り。`$(stories_for_shard ...)` は未定義時 `die 1` で非ゼロ終了するため、
  `[[ -n "$(...)" ]] || t_fail` は `t_fail` に到達せず self-test プロセスごと落ちる。
  「テスト失敗として記録する」設計になっていない。
- 対応内容: 施策 2-d を Codex 提案の `rc` 分離形に差し替えた
  (`stories="$(...)" || rc=$?` → `[[ "${rc}" == 0 && -n "${stories}" ]] || t_fail`)。

## [Critical] 施策 5: `scripts/bug-hunt-shard.sh` を散文 scan 対象に含めると自己参照で偽陽性
- 判断: **対応する**
- 根拠: 指摘の通り。施策 1 で入れるコメントに `bug_hunt_5..8` / `DEV_DB_DENYLIST` /
  `cap <= 9` / `2..9` が意図的に含まれる。これらは検出パターンに当たり、
  「正しい説明を書くと赤くなる」設計になっていた。
- 対応内容: `CAP_ALLOCATION_DOCS` から `scripts/bug-hunt-shard.sh` を**外し**、
  スクリプトは**構造テスト専用** (cap 定数 / regex 導出 / manifest parser / `6-*`・`8-*` case 不在)
  に分離した。§施策 5 の定数・テストケースを書き直した。

## [Warning] 施策 5: `AGENTS.md` を scan するとを「守りが広い理由」が書けなくなる
- 判断: **対応する (方式を変更)**
- 根拠: 妥当。AGENTS.md は割り当てと守りの両方を説明する規約文書であり、
  「残留 `bug_hunt_5..8` を守る」と正しく書いた瞬間に赤くなるのは gate 設計の欠陥。
  ただし AGENTS.md を丸ごと除外すると、今回直した `:8011..8018` が再び腐る。
- 対応内容: 検出を**行単位 + 明示マーカー除外**に変更した。行に `cap-defense-ok` を含む場合は
  その行を除外する (c2c 台帳の `ref-ok` と同じ発想。除外がレビュー時に目視できる)。
  併せて「マーカーは守りの説明にのみ使う」ことをテストの docblock と AGENTS.md 側コメントに残す。
  これで AGENTS.md をスコープ限定せず全文 scan のまま維持できる。

## [Warning] 施策 1-d: manifest parser 側で cap の 1 桁性が未検証
- 判断: **対応する**
- 根拠: 妥当。bash 側 self-test だけで守っており、parser 単体では `[0-{cap}]` が壊れうる。
- 対応内容: Codex 提案の `re.fullmatch(r"[2-9]", cap)` fail-fast を施策 1-d に追加した。

## [Warning] 施策 1-c: `valid_parallel_n` の算術化で、cap を上げた瞬間 map 未定義 N が受理され exit 2 → die 1 になる
- 判断: **一部対応 + 反論**
- 根拠: 「列挙 (`2|4`) に戻す」案は採らない。cap の SSOT 化が本タスクの主目的であり、
  受理集合を再び手書き列挙に戻すと**同じ数字がまた 2 箇所になる** (今回直している問題そのもの)。
  実行時の exit code がずれるのは「cap を上げたのに story map を足していない」という
  **リリース前に必ず赤くなる**状態でのみ起きる (Architecture テストの map 完全性検査 +
  self-test [r] の 2 重で検出する)。運用に出ない失敗モードのために SSOT を崩す取引は割に合わない。
- 対応内容: 反論を設計本文に明記した上で、Codex の受入条件
  (「cap を上げる場合は `stories_for_shard` の追加が同一変更で必須」とコメント明記 +
  Architecture テストで受理集合と map 完全性を固定) は**そのまま採用**した。

## [Warning] 施策 2: self-test が `BUGHUNT_DB_PREFIX` の既定値に依存している
- 判断: **対応する**
- 根拠: 妥当。外部環境に `BUGHUNT_DB_PREFIX` が入っていると self-test が環境依存で赤くなる。
  self-test は「実資源に触れない自己検証」なので環境非依存であるべき。
- 対応内容: `cmd_self_test` の sandbox 初期化で `BUGHUNT_DB_PREFIX=bug_hunt` を固定し
  `SHARD_DB_RE` を再導出する手順を施策 2 に追加した。

## [Warning] `BUGHUNT_DB_PREFIX` を escape せず `SHARD_DB_RE` に埋めている (既存由来)
- 判断: **対応する (最小の検証を今回スコープに入れる)**
- 根拠: 既存由来の問題だが、**今回まさにその行を編集し「外から広げられない」と書く**ため、
  放置するとコメントが実態と食い違う (Codex の指摘通り)。
  `BUGHUNT_DB_PREFIX` に regex メタ文字が入ると「dev DB 防御の核」である allowlist が壊れる
  = セキュリティ不変条件に直接効く。検証 2 行で閉じられるので、
  「コメントを弱める」よりも「実態を強める」方を選ぶ。
- 対応内容: 施策 1-a に `^[a-z][a-z0-9_]*$` の fail-fast 検証を追加し、
  self-test に「不正 prefix でスクリプトが起動しないこと」の 1 アサーションを追加した。
  併せて後続 TODO 候補から外した (今回で閉じる)。

## [Suggestion] 4 件
- 施策 3・4 への肯定 (AGENTS.md の regex 写経廃止 / `findings.schema.json` の description のみ変更 /
  denylist・`DetectsBughuntDatabase` の据え置き / browser guard の据え置き)。設計変更なし。
