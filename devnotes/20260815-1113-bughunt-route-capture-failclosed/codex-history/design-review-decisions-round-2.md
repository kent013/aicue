# 対応マトリクス: design-review Round 2

## [Critical] 施策3: 「サイズが 2 回連続一致」は terminate 完了の確認になっていない

- 判断: **対応する (指摘が正しい)**
- 根拠: サイズの静止は「これから書き込みが来ない」ことを証明しない。
  0 のまま 2 回観測 → truncate → 遅れて `/login` の行が追記される順序が実際に成立する。
- 対応内容: **同期点を「疎通確認の行が現れたこと」に変える**。
  1. ヘルスチェック成功後、`{run}-{shard}.jsonl` に**行が 1 件以上現れるまで待つ** (上限 5 秒)。
  2. 上限内に現れない、または `.error` が現れたら **provision を失敗させる** (`die`)。
     これは配線が死んでいる状態を**走行前に**落とすことになり、
     「黙って何も記録しない」状態の検出点が 1 つ増える (fail-closed の強化)。
  3. 行を確認してから truncate し、探索エージェントへ引き渡す。
  4. dryrun は serve を起動しないので待たず、初期化だけ行う (関数を 2 つに分ける)。
  5. 自己テストは、背景で遅延追記した行を待って空にすることと、
     行が来ない場合に非 0 で落ちることの両方を確認する。

## [Warning] 施策1: `load_executed()` のコンテナ型検証が不足 (traceback で落ちる)

- 判断: **対応する**
- 根拠: `data` が dict でない / `shards` が list でない / 行が dict でない場合、
  `.get()` や反復で `AttributeError` / `TypeError` になり、`main()` の捕捉対象外なので
  終了コード規約 (1 / 3) から外れて traceback で落ちる。
  非 hashable な `status` は `status not in VALID_STATUSES` 自体が `TypeError` になる。
- 対応内容: root を `dict`、`shards` / `executed_routes` を `list`、各行を `dict` として検証し、
  `run_id` と各 shard は非空 `str` を要求する (暗黙の `str()` 変換をやめる)。
  `status` は `isinstance(status, str)` を確認してから集合照合する。
  **JSON 構文エラー・I/O エラーは 1、構文上は読めるが schema 違反なら 3** に統一する。
  root / shards / row の各不正形にテストを追加する。

## [Warning] 施策5: stale 語彙 gate が自己検出する

- 判断: **対応する (パターンを狭める)**
- 根拠: 既存 `test_naming_no_stale.py` は `EXCLUDE_NAMES` で**自ファイルを既に除外している**ので
  自己検出は起きない。ただし裸の `skipped` を禁止語にすると、
  `unittest` の `skipTest` や無関係な英文コメントを巻き込む偽陽性が出る。
- 対応内容: パターンを**語彙表記に限定**する:
  `skipped_blocked_count` と、status 語彙としての `skipped`
  (`ok|blocked|skipped` の並び / 引用符付きの `'skipped'` `"skipped"`)。
  自ファイル除外が効いていることをテストで確認する (既存機構への依存を明示する)。

## [Suggestion] 施策4: 一時ファイルは `--out` と同じディレクトリに作る

- 判断: **対応する**
- 根拠: 別ファイルシステムだと `os.replace()` が失敗し、atomic rename の前提が崩れる。
- 対応内容: 設計へ「一時ファイルは `--out` と同じディレクトリに作る」と明記する。

## [Suggestion] 施策2: 順序テストの違反表示に URI と method を含める

- 判断: **対応する**
- 対応内容: 違反メッセージを `{route名 または '(無名)'} [{method} {uri}]: {middleware} が記録器より後ろ` にする。
