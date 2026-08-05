# 対応マトリクス: design-review Round 1

## C1 [Critical] ロールバック R2 の `git reset --hard <BASE_SHA>`

- 判断: **対応する**
- 根拠: 指摘のとおり。task branch 内であっても `--hard` は「そのとき作業ツリーにあった
  未コミットの変更」を消しうる。ロールバック手順は**復元操作であって破壊操作であってはならない**。
- 対応内容: R2 を書き換えた。
  - 原則: **`git revert <commit>`**（履歴を残す非破壊のロールバック）
  - 補助: 直前コミットのみを取り消すなら `git reset --soft HEAD^`（作業ツリーに触れない）
  - `git reset --hard` は**「人間の明示承認がある場合のみ」**と明記し、既定手順から外した

## C2 [Critical] 既存 DB のとき `COMMENT` が付かず unlabeled のまま残る

- 判断: **対応する**
- 根拠: `ensure-test-db.php` は `pg_database` に base が既にあれば
  `exit 0` するため（現行 L40-43）、**既に作られている生存 base DB には provenance が付かない**。
  この状態のまま `--include-unlabeled` を持つと、現役 DB が掃除候補に混じる。
- 対応内容: 既存パスでも **接続できていれば `COMMENT ON DATABASE` を更新してから exit** する
  設計に変更（作成時・既存時の両方でラベルを付け直す = 冪等なラベリング）。

## C2 [Critical] `COMMENT` 失敗を warning で握る方針と `--include-unlabeled` の組み合わせが危険

- 判断: **対応する**（ただし提案された「COMMENT 失敗時に DB を DROP」ではなく、
  **危険な機構そのものを廃止する**方向で対応する）
- 根拠: 指摘の危険は正しい。ただし提案どおり
  「COMMENT 失敗時に作成した base DB を削除して失敗」にすると、
  **`ensure-test-db.php` に DROP DDL を持ち込むことになり、
  『DROP の実行責務を既存ファイルから分散させない』という本設計の中核方針を壊す**
  （かつ、テスト実行の前処理が権限設定の差で落ちるようになり、偽赤を増やす）。
  危険の本体は「**provenance が無い DB を、フラグ 1 つで一括 DROP できてしまうこと**」なので、
  そのフラグを廃止するのが正しい修正である。
- 対応内容:
  - **`--include-unlabeled`（一括フラグ）を廃止**し、**`--include-hash=<hash>`（複数指定可）**へ置き換える。
    unlabeled は**人間が hash を 1 つずつ明示的に名指ししたときだけ** DROP 候補になる
  - これにより「権限不足で provenance が付かなかった現役 DB」が
    **フラグの巻き添えで落ちる経路が構造的に消える**
  - 現存 17 個（5 hash 群）の初回回収は、dry-run 出力を人間が読んで
    `--include-hash` を 5 回指定する運用になる（明示性が上がる）
  - `COMMENT` 失敗は引き続き best-effort warning とし、dry-run 出力の
    「所有元を確認できない hash」節で必ず強調表示する

## C2 [Warning] canonical token に `include_unlabeled` が含まれていない

- 判断: **対応する**
- 対応内容: token の canonical JSON に **`include_hashes`（昇順）** と
  **`classifier_version`（分類ロジックのバージョン）** を追加する。
  分類規則を変更したら `classifier_version` を上げ、**古い token では apply できない**ようにする
  （承認文脈の一部として正しく効かせる）。

## C2 [Warning] `--protect-hash` の形式検証と dry-run の情報量

- 判断: **対応する**
- 対応内容:
  - `--protect-hash` / `--include-hash` の値は `^[0-9a-f]{8}$` を強制し、
    不正なら即エラー（テスト T-C2-16 で固定）
  - dry-run 出力に **hash → provenance path / live path の対応表**を必ず併記する
    （人間が cross-clone を判断できる材料を出す）

## C2 [Warning] エージェントが dry-run token を読んで `--apply` する余地

- 判断: **対応する**
- 対応内容: `--apply` の運用契約に
  **「LLM / エージェントは `--apply` を実行しない。ユーザー自身が実行するか、
  ユーザーが明示的に承認したときのみ」**を明記し、
  スクリプトの usage / `AGENTS.md` / `scripts/README.md` の 3 箇所に同じ文言を置く。

## B2 [Warning] G2 が AGENTS.md 全体検索で形骸化しうる

- 判断: **対応する**
- 根拠: 指摘のとおり。新規ゲートを最初から緩く作る理由がない。
  G1 で採用したマーカー方式と揃えるのが一貫している。
- 対応内容: `<!-- VERIFICATION_COMMANDS:BEGIN -->` / `<!-- VERIFICATION_COMMANDS:END -->` を
  **AGENTS.md と `.claude/skills/app-implement/SKILL.md` の両方**に入れ、
  **その範囲だけ**を照合対象にする。マーカーが各ファイルにちょうど 1 組あることも検査する（V0）。

## B2 [Warning] AGENTS.md の不変条件 9/10 が長すぎて runbook 化している

- 判断: **対応する**
- 根拠: AGENTS.md §セキュリティ不変条件は「1 行で言い切って参照先を示す」体裁であり
  （既存 1〜8 はいずれも 2〜3 行）、提案した 9/10 だけが 8 行になるのは体裁を壊す。
- 対応内容: 9/10 を**既存項目と同じ密度**へ圧縮し、middleware 名・順序契約の詳細は
  `docs/app-integration-guide.md` §7 を正本として参照させる。

## B1 [Warning] `screens.md` の「GET×inertia」見出しと実態のずれ

- 判断: **対応する**（列追加ではなく見出し + 注記で対応）
- 根拠: 指摘は妥当。ただし 4 列目を足すと既存 55 行すべてを書き換えることになる。
  `coverage/correlate.py` が依存しているのは **operations.md の 5 列だけ**で
  screens.md は解析していないため列追加自体は可能だが、
  Codex の修正案も「**列か注記**」を許容している。差分を最小にする注記方式を選ぶ。
- 対応内容:
  - 見出しを `## 画面一覧` → `## GET × web 一覧 (画面 + 画面に付随する JSON GET)` へ
  - 冒頭の説明に、**非 Inertia の JSON GET を明示列挙**する
    （`capture.csrf-cookie` / `session.status` / `passkey.*-options` 3 本）
  - パスキー options の扱いを説明する節は当初案どおり追加

## B1 [Suggestion] W16 は `runScript` ではなく `runLines` で実行行を見る

- 判断: **対応する**
- 根拠: `runScript` はコメント行も連結するため、
  「`# bug-hunt-inventory-check.sh は将来入れる`」というコメントで green になる。
  既存の `runLines()` ヘルパがあるので採用しない理由がない。
- 対応内容: W16 を `runLines(job(workflow, "php")).some(l => l.includes("scripts/bug-hunt-inventory-check.sh"))` へ変更する。

## ゲート [Warning] P2「PCRE 100 件以上」/ N2「index 500 件以上」の閾値が将来の偽赤になりうる

- 判断: **対応する**
- 根拠: 指摘のとおり。リポジトリ規模に連動する固定閾値は、
  「正しい状態なのに落ちる」偽赤の芽になる（本バッチが減らそうとしているものと同種）。
- 対応内容:
  - P2: 100 → **20**（現状値から大きく下げる）。加えて
    **「`tests/Architecture/` 配下から 1 件以上抽出できること」**という代表ファイル検査を併用
  - P3: 300 → **50** + 代表ファイル（`tests/Architecture/GlobalTestLockInventoryTest.php`）が
    走査対象に含まれること
  - N2: 500 → **50** + 代表 path（`AGENTS.md` と `composer.json`）が index に含まれること
  - 「代表が含まれること」の方が規模非依存で、走査経路のミスを確実に捕まえる

## A1 [Suggestion] 完全な PCRE parser ではないこと / `\\R` と `\R` の扱い

- 判断: **対応する**
- 対応内容:
  - docblock に「完全な PCRE parser ではない（escaped delimiter / 文字クラス内 delimiter は
    厳密に扱わない）。射程は `\R` の `/u` 欠落検出に限定する」を明記
  - テストに **P12（double-quoted `"/\\R/"` = PHP 文字列としては `\R` を含む → 検出）** と
    **P13（`'/\\\\R/'` = リテラルのバックスラッシュ + R = 改行クラスではない → 非検出）** を追加
  - 判定対象は「**PHP の文字列リテラル評価後の値**」であることを明記する

## A2 [Suggestion] `ps` 不在時の probe 全体の期待挙動を明文化

- 判断: **対応する**
- 対応内容: 施策 A2 に「`ps` 不在時の期待挙動」節を追加する。
  `_gtl_probe_process_group()` は `ps` が無い環境では
  「値が取れない = best-effort として通す」挙動になるため、
  **`verify-global-test-lock.sh` の `HAVE_PS=0` では C25 を skip し、skip 数として必ず報告する**
  （skip を隠さない既存方針と揃える）ことを明記する。

## C1 [Warning] `core.precomposeunicode` を受入条件・rollback に含めない

- 判断: **対応する**
- 対応内容: 手順 6 を「**任意の補助手順**」として分離し、受入条件（V-C1〜V-C7）と
  ロールバック（R1〜R4）から外す。恒久対策は index 正規化 + `GitIndexNormalizationTest` に限定する。

## C1 [Warning] V-C4 は blob 集合ではなく「NFC path → blob」の map で比較する

- 判断: **対応する**
- 根拠: 指摘のとおり。同一 blob が複数 path にある本リポジトリ
  （2 回×42 / 3 回×3 / 4 回×7 / 6 回×3）では、**集合比較は path 消失を検出できない**。
- 対応内容: V-C4 を
  「施策前後で **NFC 正規化した path → blob の map が完全一致**すること」へ変更する
  （197 entry を NFC 正規化すると 139 個の key になり、施策後の 139 entry と 1:1 で一致するはず）。

## D1 [Warning] lint 差分が大きい場合の分割基準

- 判断: **対応する**
- 対応内容: 「`pnpm lint` の指摘が**コード修正 5 ファイルを超える**なら、
  D1 を `undici` のみに縮小し、`eslint-plugin-better-tailwindcss` の pin 上げは
  別 TODO へ分離する」という判断基準を明記する（`overrides` は採らない）。
