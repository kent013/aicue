# 対応マトリクス: design-review Round 5

## [Warning] M9: 自己テストが mutation #18 を検出できない (PHP の copy-on-write)

- 判断: **対応する (指摘は正しい。実装したら偽グリーンになっていた)**
- 根拠: `capture()` が配列を返すと、返却後に listener が参照先のローカル `$records` へ
  追記しても copy-on-write により呼び出し側の配列は増えない。したがって
  `$active = false` を削除しても「capture 後に records が増えない」テストは緑のままになる。
- 対応内容: Codex の提案どおり **可変 collector オブジェクト
  `JobQueueingTransactionRecords`** を導入し、`capture()` がそれを返す形へ変更した。
  - `record(string $job, int $level)` は `active` が真のときだけ追記する
  - `finally` で `$collector->active = false`
  - 自己テストは capture 前後で **同一 collector の `all()` 件数**を比較する
  - `only()` は `$collector->all()` を渡す形に変更 (assert API の array shape は維持)
  - mutation #18 の記述もこの形に合わせた
  collector は**テスト Support 内部だけの機構**であり過剰な DTO 化には当たらない
  (Codex も同旨)。

## [Suggestion] 不活性 listener が保持するのはクラス名と整数だけにせよ

- 判断: **対応する (既にその設計だが明示する)**
- 対応内容: collector の docblock に
  「保持するのは**クラス名 (string) と深さ (int) だけ**で、job payload そのものは保持しない
  (不活性 listener が長生きするため)」を明記した。

## [Suggestion] 保証しないもの 13 番と 16 番の重複

- 判断: **対応する**
- 対応内容: 13 番を**観測原理**、16 番を**pin 接続選択の注意** (13 番の運用上の注意) として整理した。

## その他 (Codex が APPROVE した施策)

M1 / M2 / M3 / M4 / M5 / M6 / M7 / M8 は Round 5 で APPROVE。
mutation #1〜#17・#19 も意図した検査点に対応していると確認された。
