# 対応マトリクス: design-review Round 3

## [Warning] S3: 素の `unlink()` は失敗時に E_WARNING を出し、例外化する環境では戻り値判定へ到達しない
- 判断: **対応する (指摘どおり)**
- 根拠: Laravel のエラーハンドラは警告を `ErrorException` へ変換する。その環境では
  `return "failed to remove …"` に到達せず、設計した `TakeThumbnailExtractionException` への
  集約・失敗理由の形式・Unit テストの契約から外れる (ジョブが失敗する点は同じでも、
  「失敗の形」が設計と食い違う)。
- 対応内容: `File::delete()` + **削除後の存在確認**で判定する形へ変更し、
  `Illuminate\Support\Facades\File` の import を追加した。
  判定が戻り値だけで閉じるため、警告の例外化に依存しなくなる。

## [Warning] (同上) 削除失敗テストが OS 権限に依存すると並列テストで不安定
- 判断: **対応する**
- 対応内容: テスト計画に「`File` facade を差し替えて『削除が効かなかった』状況を決定的に作る
  (OS の権限に依存させない)」と明記した。`--parallel` 実行でも再現性が壊れない形にする。

## Round 2 対応の評価 (指摘なし)
- 判断: 見送る (変更不要)
