# 対応マトリクス: design-review Round 3

## A1 [Warning] `\\ → \` の 1 パス復元は完全ではない (`\x5cR` / `\u{5c}R`)

- 判断: **対応する**（射程を明示的に限定し、テストで固定する）
- 根拠: 指摘のとおり。double-quoted の `"\x5cR"` / `"\u{5c}R"` は PHP 評価後 `\R` になる。
  ただしここまで復元すると **PHP の文字列評価器を再実装する**ことになり費用対効果が合わない。
  実測でリポジトリ内に該当記述は **0 件**（`rg '\\x5c|\\u\{5c\}' app tests scripts config database routes` → 0）。
- 対応内容:
  - docblock と設計に「**射程は `\R` を直接記述したリテラルに限る**。16 進 / Unicode
    エスケープは復元しない」と明記
  - **P15 を追加**: `"/\x5cR/"` を **意図的な見逃し**としてテストで固定
    （テスト名とコメントに「射程外」と書き、将来の実装者が「バグ」と誤認しないようにする）
  - テスト計画の参照を P1〜P14 → **P1〜P15** に更新

## A2 [Warning] C26 が「PATH から ps を外すだけ」では偽グリーンになる

- 判断: **対応する**
- 根拠: 指摘のとおり。`flock` / `sleep` / `tr` の不在で先に落ちても「非ゼロ終了」は満たす。
  終了コードだけを見る検証は、**何が原因で落ちたかを区別できない**。
- 対応内容: C26 の PASS 条件を 3 点に強化する。
  - (a) 一時 PATH ディレクトリに**必要なコマンドだけ** symlink し、`ps` **だけ**を置かない
  - (b) 終了コードに加えて **`_gtl_probe_process_group` 固有のメッセージ**
    （`job control で専用プロセスグループを作れない`）が stderr に出ることを検証
  - (c) probe に到達した証跡（acquire 開始マーカー）が出ていることを検査

## C1 [Warning] 手順 4 が blob だけを保存しており V-C4 を実行できない

- 判断: **対応する**（こちらの記述漏れ）
- 根拠: 指摘のとおり。`awk '{print $2}'` では path との対応が失われ、
  V-C4（path→値の map 比較）を実行できない。
- 対応内容:
  - 手順 0 と手順 4 の両方で **`NFC(path) → "<mode> <blob> <stage>"` の map** を
    **NUL 安全**（`git ls-files -s -z` + Python）に生成する
    （`index-map-before.txt` / `index-map-after.txt`）
  - **before の生成時に、同一 NFC key へ異なる値が現れたら即中止**する
    （blob 不一致 0 件の事前確認を map 生成側でも fail-fast させる）
  - V-C4 を「2 つの map を `diff` して差分 0」へ具体化（197 entry → 139 key / 139 entry → 139 key）

## C2 [Warning] `testDatabaseEnsurePlan()` は安全な COMMENT SQL を生成できない

- 判断: **対応する**（提案どおり action 列を返す設計へ変更）
- 根拠: 指摘は本質的に正しい。`pgsqlCommentDatabaseSql()` はリテラルクォートに
  `PDO::quote()` を要するのに、純関数は PDO を受け取らない。
  provenance path に `'` が含まれうる以上、独自連結は許容できない。
- 対応内容:
  - `enum TestDatabaseEnsureAction { case Create; case StampProvenance; }` を導入
  - `testDatabaseEnsurePlan(bool $exists): list<TestDatabaseEnsureAction>`
    （**PDO にも SQL にも触れない純関数**。`false` → `[Create, StampProvenance]` /
    `true` → `[StampProvenance]`）
  - 本体は `match` で action に応じて既存の `pgsqlCreateDatabaseSql()` /
    `pgsqlCommentDatabaseSql($pdo, ...)` を呼ぶ（**クォート責務は既存 SQL ビルダに残る**）
  - T-C2-17 を action 列の検証へ変更し、**T-C2-17b**（provenance に `'` を含む path が
    `PDO::quote()` で正しくクォートされる）を追加

## C2 [Warning] T-C2-2 が旧契約のまま / 関連記述の取り残し

- 判断: **対応する**（4 箇所すべて）
- 対応内容:
  - **T-C2-2**: `Orphan / shouldDrop = true` → **`Orphan / shouldDrop = false`**
    （`--include-hash` 指定なし。指定時の `true` は T-C2-20 が検証）と明記
  - **apply 契約 2-b**: 「unlabeled は一括フラグで落とせない」→
    「**`Orphan` / `Unlabeled` の両方**が一括フラグでは落とせない」
  - **PHPDoc `$includeHashes`**: 「unlabeled をこの hash に限り候補化」→
    「**Orphan / Unlabeled** をこの hash に限り候補化」
  - **token 説明**: 「どの unlabeled 群を…承認したか」→
    「**どの `Orphan` / `Unlabeled` 群**を…承認したか」

## B1 / B2 / D1

- 判断: **対応不要**（APPROVE。指摘なし）
