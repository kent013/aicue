# 対応マトリクス: design-review Round 3

## [Critical] 施策3: 再 provision 時に古い JSONL 行を同期点と誤認する

- 判断: **対応する (指摘が正しい)**
- 根拠: 既存の `{run}-{shard}.jsonl` が非空なら、今回の疎通確認の `terminate()` を待たずに
  配線確認が成功してしまい、truncate 後に今回の `/login` 行が遅れて追記される。
  Round 2 と同じ競合が再発する。`.error` の削除を再 provision で行う設計にしている以上、
  run ID の一意性だけには依存できない。
- 対応内容: 初期化を **prepare / finalize の 2 段**に分ける。
  1. `prepare_executed_capture` — **serve 起動より前**に既存 JSONL と `.error` を消す
  2. ヘルスチェック成功後、`assert_executed_capture_wired` で**今回の要求による行の出現**を待つ
     (prepare 済みなので、現れた行は必ず今回のもの)
  3. `finalize_executed_capture` — 行を確認してから空にし、manifest を更新する
  4. dryrun は prepare と finalize だけ (serve が居ないので待たない)
- 自己テスト追加: **古い行が事前に存在していても同期成功と誤認しない**
  (prepare が消してから待つので、背景からの遅延追記を実際に待つ)。

## [Warning] 施策1: 不正な `run_id` が schema 違反として分類されない

- 判断: **対応する**
- 対応内容: `Executed.invalid_row` を **`schema_error`** に改名し、
  `run_id` が非空 `str` でない場合もここへ理由を入れる。
  `validate_executed()` は `schema_error` を最優先で見て `executed_schema_invalid` を返す。
  テストに `run_id: null` / 空文字 / 数値 の 3 通りを追加する。

## [Warning] 施策2: `StartSession` の機序説明が実装と違う

- 判断: **対応する**
- 根拠: セッションの保存は `StartSession::terminate()` ではなく、
  `handleStatefulRequest()` が下流の応答を受け取った後、**応答の巻き戻り中**に
  `saveSession()` を呼んで行う。
- 対応内容: middleware のコメントと README の説明を
  「`StartSession::handleStatefulRequest()` の応答巻き戻し時に保存・世代更新され、
   その後 Kernel の terminate 処理で記録器が読む」へ直す。
  framework 内部実装への依存であることと、Feature テストで固定する方針は維持する。

## [Warning] 施策5: stale 語彙 gate が正当な回帰テスト fixture を検出する

- 判断: **対応する**
- 根拠: 旧 status 値を拒否するテストは、入力 fixture としてその文字列を必要とする。
  自ファイル除外だけでは `test_correlate.py` が引っかかる。
- 対応内容: パターンを 2 群に分ける。
  - `STALE_PATTERNS` (既存の旧 Stage 付番): 対象は従来どおり skill 配下の `.md` / `.py` 全部
  - `IMPLEMENTATION_ONLY_PATTERNS` (新規。旧 fail-open 文言 + 旧語彙):
    対象から **`test_*.py` を除外**する (負の対照 fixture が旧値を持つのは正当なため)。
    `.md` は全部対象に残す
  - 実装 (`correlate.py` 等) と README からは旧語彙を**完全に削除**する
    (`fixtures/executed.sample.json` も新しい形へ更新する)
  - gate 自身のテスト: 合成の実装ファイルでは検出し、`test_*.py` では検出しないことを確認する
