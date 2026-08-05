# 対応マトリクス: design-review Round 2

## C2 [Critical] `--include-hash` だけでは「細工された labeled foreign DB」の経路が残る

- 判断: **対応する**（提案どおり `Orphan` も明示指定制にする）
- 根拠: 指摘は正しい。現設計では
  - 別クローンの生存 DB の provenance を**存在しないパスへ書き換える**
  - あるいはその path が**現在のコンテナ / namespace から見えない**（bind mount の差など）

  のいずれでも分類は `Foreign` ではなく **`Orphan`** になり、`Orphan → shouldDrop = true` なので
  `--include-hash` なしで DROP 対象に入ってしまう。
  「現在のクローンから生存を否定できない hash」は**すべて明示指定制**にするのが正しい。
  これは「**分類は説明のために行い、削除可否を分類だけで自動決定しない**」という
  一段強い原則へ設計を寄せることでもある（AGENTS.md 禁止事項 3 の趣旨にも合う）。
- 対応内容:
  - 優先順位表の 4・5 を変更:
    ```
    4. Orphan    → shouldDrop = (hash ∈ --include-hash)
    5. Unlabeled → shouldDrop = (hash ∈ --include-hash)
    ```
    = **`--include-hash` で名指ししない限り 1 件も落ちない**
  - `Protected` / `Live` は `--include-hash` に指定されても **DROP しない**（保護が優先）と明記
  - dry-run 出力の「DROP 対象」欄は `--include-hash` 指定分のみになる旨を反映
  - テスト追加: T-C2-19（細工された provenance の foreign が指定なしで落ちない）/
    T-C2-20（Orphan は指定時のみ落ちる）/ T-C2-21（Protected・Live は指定されても落ちない）/
    T-C2-22（provenance path が見えないケースを Orphan として保護する）

## C2 [Warning] T-C2-17/18 が「生成 SQL の検証」だけでは分岐と例外経路を証明できない

- 判断: **対応する**（PDO 境界を注入可能な関数へ分離する）
- 根拠: 指摘のとおり。「両分岐が実際に stamp を呼ぶ」「例外時に exit 0 で続行する」は
  SQL 文字列の検証では証明できない。PDO を fake するのは筋が悪いので、
  **PDO に触れない形へ境界を切る**。
- 対応内容: 2 つの関数へ分離し、どちらも PDO 無しで単体テストできるようにする。
  - `testDatabaseEnsurePlan(bool $exists, string $base, string $provenance): list<string>`
    — **純関数**。存在するときは `[COMMENT]`、しないときは `[CREATE, COMMENT]` を返す。
    「**両分岐とも COMMENT を含む**」をテストで固定する
  - `pgsqlStampProvenance(callable(string): mixed $exec, string $sql): bool`
    — `$exec` を注入する best-effort 実行器。`Throwable` を捕まえて `false` を返し stderr へ warning。
    **例外を投げるクロージャ**と**成功するクロージャ**の 2 本で例外経路を直接テストできる
  - `ensure-test-db.php` 本体は「plan を作って exec に流す」だけになる

## A1 [Warning] P13 の PHP 文字列評価が誤っている

- 判断: **対応する**（指摘が正しい。こちらの記述ミス）
- 根拠: PHP の単一引用符では `'/\\R/'` は評価後 `/\R/` = **PCRE の改行クラス**であり、
  **検出対象**である。非検出になるのは評価後に `\\R` となる `'/\\\\R/'` の方。
- 対応内容:
  - P13 を「PHP ソース上の `'/\\\\R/'`（評価後 `/\\R/`）→ **非検出**」に修正
  - **P14 を新設**: 「PHP ソース上の `'/\\R/'`（評価後 `/\R/`）→ **検出**」
  - 抽出器の**復元規則**を明記:
    「`\\` → `\` の 1 パスだけを畳み、それ以外のエスケープは畳まない
    （single-quoted は追加で `\'` → `'`）。`\R` は PHP のエスケープ列ではないため
    single/double のどちらでもそのまま残る = この 1 パスで必要十分」
  - テスト計画の参照を「P1〜P11」→「**P1〜P14**」に更新

## A2 [Warning] `ps` 不在時の説明が現行コードと一致しない

- 判断: **対応する**（設計側の記述を実挙動に合わせ、さらに contract を追加する）
- 根拠: 指摘のとおり。現行 `_gtl_probe_process_group()` は 3 回とも `pgid` が空なら
  ループを抜けて `_gtl_die` する。したがって**ロック機構は `ps` を必須としている**のが実挙動で、
  「`ps` 不在なら通す」と書いた前回の記述は誤り。
- 対応内容:
  - 記述を「**`ps` 不在ではロック取得が fail する（現行挙動。本施策はこれを変更しない）**」へ訂正
  - `|| pgid=""` が strict 検証を弱めない理由を明示:
    厳格判定は `_gtl_probe_process_group()`（取得時 1 回・3 回リトライ・空なら `_gtl_die`）にあり、
    `global_test_lock_run()` 側は元から best-effort。**責務分担は変わらない**
  - **C26 を追加**: `PATH` から `ps` を外した環境で `global_test_lock_acquire` が
    **失敗する**ことを検証する（`|| pgid=""` を入れても strict 検証が生きていることの正コントロール）
  - `verify-global-test-lock.sh` の `HAVE_PS=0` では C25 / C26 を skip し、**skip 数として必ず報告**する

## C1 [Warning] 適用直後の `git status` 期待値が矛盾している

- 判断: **対応する**（指摘が正しい。こちらの記述ミス）
- 根拠: `git rm --cached` 後の staged deletion は porcelain で `D ` と出る。
  「D でもなく」と書いたのは誤り。
- 対応内容: 機械判定へ置き換える:
  - `^D ` で始まる行が **ちょうど 58 件**
  - **列 2（unstaged）が空白でない行が 0 件**
  - `^\?\?` の行が **0 件**
  - `find doc/reference -type f | wc -l` が **139 のまま**（作業ツリー無傷）

## B2 [Suggestion] テスト計画の参照を V0〜V7 に更新

- 判断: **対応する**
- 対応内容: B2 のテスト計画の「V1〜V6」を「**V0〜V7**」に更新。

## B1 / D1

- 判断: **対応不要**（APPROVE。指摘なし）
