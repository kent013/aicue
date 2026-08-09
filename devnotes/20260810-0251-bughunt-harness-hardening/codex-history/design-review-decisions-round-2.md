# 対応マトリクス: design-review (harness) Round 2

Critical 1 件 + Warning 6 件 + Suggestion 2 件。**全件対応**した（反論なし）。

## [Critical/実装計画] 手順 7 の実 DB `dropdb` 実走は禁止事項 3 に抵触する
- 判断: **対応する（最も重い指摘。自分の計画が規約違反だった）**
- 根拠: 指摘のとおり。wrapper・DB 名 guard・admin role を通っていても、
  **「dev DB への破壊操作をエージェント判断で実行しない」という上位制約は解除されない**。
  「guard を通るから安全」は、規約が禁じているのが**判断の主体**であることを見落としていた。
- 対応内容: 手順 7 を書き換えた。エージェントが自動で行う検証は
  **self-test と非破壊 dry-run (`BUGHUNT_SELFTEST_DRYRUN=1`) まで**に限定し、
  実 DB を伴う end-to-end 確認（`provision-all` → `teardown --drop-db`）は
  **ユーザーの明示承認後、またはユーザー自身が実施**すると明記した。
  実装レポートにも「self-test と dry-run までで確認した / 実機 end-to-end は未実施」と
  **正直に書く**ことを手順に含めた（「実物で確認した」と書かない）。

## [Warning/H-0] `TMPDIR` だけでは sandbox 化の退行に効かない
- 判断: **対応する**
- 根拠: 妥当。self-test が `mktemp` を使わなくなり `tmp/` や `devnotes/` を直接参照する退行では
  `TMPDIR` は無力。静的な文字列存在チェックも「実際にパス差し替えに使われている」ことは保証しない。
- 対応内容: **`BUGHUNT_SANDBOX` を Pest から明示的に渡す**形にし、
  `cmd_self_test()` 側の契約を「**未指定なら `mktemp -d`、指定済みならその sandbox を使う**」に変更した
  （実装対象に追加）。静的テストは廃し、代わりに
  **「外から与えた sandbox 配下に実際に成果物 (`tmp/bug-hunt`) が作られる」**ことを見る
  実挙動テストに置き換えた。これで隔離境界をテスト側が握れる。

## [Suggestion/H-0] `exec('rm -rf ...')` は `File::deleteDirectory()` へ
- 判断: **対応する**
- 対応内容: `Illuminate\Support\Facades\File` を使い、
  `File::makeDirectory` / `File::deleteDirectory` に置き換えた（Process facade を選んだ方針と整合）。

## [Warning/H-1] 新設した fail-closed 条件に専用テストが無い
- 判断: **対応する（重要）**
- 根拠: 指摘のとおり。**中核修正を削除しても既存 20 条件が全て緑になりうる**。
  テストが守っていない修正は、次の人に消される。
- 対応内容: 受入条件を **21〜24 の 4 件追加**し、self-test に (y7k)〜(y7n) を新設した:
  - 21/(y7k): `unknown > 0` → 失敗
  - 22/(y7l): `0 0 0` かつ `kill -0` 成功 → 失敗（fail-open の穴が塞がっている証拠）
  - 23/(y7m): `group_member_counts` の出力が不正（空 / 非数値）→ 失敗
  - 24/(y7n): 「1 回目 live=0 / 2 回目 live=1」→ 失敗
  あわせて `group_scan_once` に **3 値が非負整数であることの検査**を実装として入れた。

## [Warning/H-1] zombie を観測した経路に走査 race が残る
- 判断: **対応する**
- 根拠: 指摘のとおり。`live=0 zombie=1 unknown=0` は条件 (c) を通らないため、
  走査直後に同 PGID へ live member が現れても停止済みになる。
- 対応内容: `group_stopped` を **連続 2 回のスキャンがともに `live=0` のときだけ成功**に変えた
  （`group_scan_once` を内部関数として分離し、2 回呼ぶ）。
  同時に「これは TOCTOU の**証明**ではなく**窓を縮小する検出**である」とコメントに明記した。

## [Warning/H-1] `unknown` は対象 PGID 以外の不正行も数える（可用性の代償）
- 判断: **対応する（意図として明示する）**
- 根拠: パース不能時は pgrp を特定できないので安全側としては正しいが、
  **無関係な 1 プロセスの異常で全 shard の teardown が止まる**。
- 対応内容: 設計に「**可用性のトレードオフ**」として明記し、
  「安全性ではなく可用性の代償であり、意図的にそう倒している」と書いた。
  リスク欄も `cut -d` の記述（実装から消えた）を削除し、この注記に差し替えた。

## [Suggestion/H-1] (y7a) の記述が実装と合っていない
- 判断: **対応する**
- 対応内容: (y7a) を「**`parse_proc_stat_line` を fixture 文字列で直接叩く**」に書き換え、
  `') '` を含まない行で非 0 を返すケースも足した。

## [Warning/H-3] raw `dropdb` の正規表現はコマンド位置を網羅できていない
- 判断: **対応する（走査方式ごと変更）**
- 根拠: 指摘のとおり。`if dropdb` / `while dropdb` / `then dropdb` / `! dropdb` /
  `exec dropdb` / `env X=1 dropdb` を取りこぼす。
  かつ「変数経由も見落とさない」という自分の説明は**正規表現では実現できていなかった**。
- 対応内容: 方式を変えた ——
  1. 非コメント行のみ対象、2. **許可行を理由付き allowlist（行の内容で保持）で除外**、
  3. 残った行に**単語境界の `dropdb` / `createdb` が 1 つでもあれば赤**、
  4. 正当な理由が出たら理由付きで allowlist へ 1 行追加（deny-by-default）。
  あわせて**保証範囲を先に限定**した ——
  「これは **literal な直接呼び出しの検出**であって、変数展開・関数経由・`env` 経由・`eval` まで
  含めた証明ではない。そこまで見るには bash の AST 相当の解析が要る」。

## [Suggestion/H-4] 契約テストは位置引数で渡して injection を避ける
- 判断: **対応する**
- 対応内容: 起動形を
  `SETUP_WORKTREE_SOURCE_ONLY=1 bash -c 'source "$1"; provision_runtime_files "$2" "$3"' _ <script> <parent> <worktree>`
  に確定した（文字列連結をしない）。

## H-2 / H-4 の APPROVE を受領
- 追加対応なし。
