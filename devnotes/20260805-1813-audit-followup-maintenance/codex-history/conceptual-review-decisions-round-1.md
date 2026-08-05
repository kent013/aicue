# 対応マトリクス: conceptual-review Round 1

## [Warning] 施策 4: 孤児テスト DB 回収は「禁止事項 3」に近い領域

- 判断: **対応する**
- 根拠: 指摘のとおり `--apply` を用意する時点で DROP 経路である。「コマンドが安全か」ではなく
  「誰がどの条件で apply できるか」を設計に書けという指摘は正しい。特に
  **確認トークン**の提案は「dry-run で人間が読んだ集合」と「apply が実際に落とす集合」の
  一致を機械的に保証する。これは安全性の実質的な向上であり、over-engineering ではない。
- 対応内容:
  - `--apply` は**人間の明示指示がある場合のみ**実行可と概念設計に明記
  - dry-run が対象一覧 + `--confirm=<token>` を出力し、`--apply` はそのトークン一致を要求する
    (token = 対象 DB 名を昇順連結した文字列の sha1 先頭 8 桁 = 集合が変われば必ず不一致)
  - 出力に「DROP 対象 / 除外した DB と除外理由 / 生存 worktree hash 一覧」を必ず含める
  - denylist に `bug_hunt` / `bug_hunt_1..8` を明示追加
    (allowlist regex `^app_test_[0-9a-f]{8}(_test_[0-9]+)?$` で既に構造的に除外されるが、
    「bug-hunt 環境の DB は絶対に触らない」を意図として明示する二重防御)
  - setup/teardown-worktree.sh と**同一の lock ファイル** (`.claude/worktrees/.setup.lock`) を取る
    (worktree 作成中に「まだ DB を作っただけで worktree が無い」瞬間を孤児と誤判定する race を塞ぐ)

## [Warning] 施策 5: 「git rm --cached は working tree を触らないから安全」は論拠として不十分

- 判断: **対応する**
- 根拠: 指摘のとおり。`--cached` は「今この瞬間のローカル作業ツリーを壊さない」ことしか言えず、
  コミット後の他環境 checkout での消失を説明していない。安全性の本当の根拠は
  「落とす entry の内容が、残す entry に同一 blob で保存されていること」である。
- 対応内容: 実測で前提を検証したうえで論拠を書き換えた。**実測結果 (2026-08-05 18:13 時点)**:
  - index entry 197 / NFC 正規化衝突グループ **58 / 全グループがサイズ 2**
  - **blob が異なるグループ 0 件** (= 落とす NFD entry の内容は必ず NFC 側に同一 blob で残る)
  - **NFC 形の entry を持たないグループ 0 件** (= 「NFD 側にしか無い内容」は存在しない)
  - NFD entry 58 件の内訳: `doc/reference/mockups` 57 / `doc/reference/scenarios` 1。
    **コード/テストから参照されている `doc/reference/sample-sop/` は 1 件も含まれない**
    (`tests/Unit/Manual/SopTextExtractorTest.php:38` が参照する唯一の実コード依存は無関係)
  - 197 − 58 = **139** = 作業ツリーの実体数と一致
  この 4 つを**実装時の事前確認 (fail-fast) として手順に組み込む**。1 つでも崩れたら中止する。
- 追加対応:
  - 削除対象 manifest を `devnotes/{dir}/nfd-index-entries.txt` として残す
  - `core.precomposeunicode` はローカル設定でしかない点を明記し、**リポジトリ恒久対策は
    `GitIndexNormalizationTest` (gate) の方**であると位置づけを訂正する
  - 受入条件に `git status --porcelain=v1 -uall` の空 + 正規化衝突 0 を追加

## [Warning] 施策 5 → 施策 4 の順序依存は概ね正しいが、表現が強すぎる

- 判断: **対応する**
- 根拠: 指摘のとおり。施策 4 の純関数・テスト・dry-run は施策 5 と独立に実装できる。
  真に必要なのは「apply と完了判定の前に 5 が終わっていること」だけ。
- 対応内容: 「グループ C の内部順序 5 → 4 は必須」を
  「**実装順は 4(純関数・テスト・dry-run) → 5 → 4(apply) を許容する。
  必須なのは 4 の apply と『孤児 0』の完了判定より前に 5 が完了していること**」へ緩和。

## [Warning] gate 化条件 (a) は例外を許すべき

- 判断: **対応する**
- 根拠: 指摘のとおり。AGENTS.md のセキュリティ不変条件は「実害が出る前に Architecture テストで
  強制する」方針で運用されており、条件 (a) をそのまま書くと本リポジトリの既存方針と矛盾する。
- 対応内容: gate 条件に例外節を追加 —
  「ただしセキュリティ不変条件・破壊的操作の guard・課金冪等性・cross-org 防止など
  **発生時の被害が回復不能または大きいもの**は、ドリフト実績が無くても gate 化してよい」。

## [Suggestion] C を C1〜C4 に段階化

- 判断: **対応する**
- 根拠: グループ C だけ破壊リスクの質が違う (git index / DB DROP)。段階を明示すると
  「どこで人間の確認が入るか」が設計上明確になり、実装者が勝手に apply へ進めない。
- 対応内容: C1 (検証 + manifest) / C2 (index 整理 + gate) / C3 (孤児 dry-run) /
  C4 (人間確認後の apply) を概念設計に明記。

## [Suggestion] 定量目標に「確認方法」を足す

- 判断: **対応する**
- 対応内容: 定量目標の表に「確認コマンド」と「対象外としたもの (= 残ってよいもの)」の列を追加。

## [Suggestion] 使命との整合性 / 実現可能性 / 型安全性

- 判断: **見送る** (指摘なしの確認事項)
- 根拠: いずれも [Suggestion] で修正要求を含まない。
