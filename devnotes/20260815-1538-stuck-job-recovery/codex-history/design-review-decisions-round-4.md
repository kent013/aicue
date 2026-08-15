# 対応マトリクス: design-review Round 4

## [Critical] 施策 8/10: 新分類の適用条件が撮影アップロード stream の構造に合わない
- 判断: 対応する (指摘の修正案 1 = 2 つの形を定義する、を採る)
- 根拠: 撮影アップロードの entryPoint は `Stream::recover` 自身で、その呼び出し元は sweeper。
  「entryPoint の呼び出し元が stream の `candidateIds()` / `recover()` だけ」という条件は
  この形では成立しない。指摘のとおり、そのままでは gate を通らない
- 対応内容: `RecoveryFetchShape` (2 値: `DomainService` / `StreamInternal`) を entry に持たせ、
  形ごとの封じ込め検査を定義した:
  - `DomainService`: entryPoint のメソッド名が `app/` に現れるファイルが、宣言ファイルと
    申告 stream のファイルの 2 つだけ
  - `StreamInternal`: private ヘルパのメソッド名が `app/` に現れるファイルが、
    その stream のファイル 1 つだけ
  撮影アップロードの実装を無理に Service へ移す案は採らない (回収の中身が
  「解放 + 孤児削除」だけで、対応するドメイン Service が無いため。器だけ作らない)

## [Warning] 施策 10: 「すべて機械検査する」と「動的呼び出しには沈黙する」が矛盾
- 判断: 対応する
- 根拠: 指摘のとおり、受信側クラスの型解決に依存する検査では deny-by-default にならない
- 対応内容: 検査を**メソッド名が現れるファイル集合**という決定可能な形に変えた
  (型推論に依存しない = 「解決できないから素通し」が起きない)。そのうえで
  保証しないもの (文字列で組み立てた動的呼び出し / `app/` 外からの呼び出し) を
  case の docblock と失敗メッセージに明記する、とリスク欄を書き換えた。
  entryPoint の名前が用途固有であること (衝突すると偽陽性になる) もリスクへ追記

## [Warning] 施策 3/10: 必須監視対象から `deferred` と `cleanup-failed` が抜けている
- 判断: 対応する (重要な指摘。**`deferred` は `errors` に出ない**ため、
  errors=0 のまま webhook の再実行が失敗し続ける状態を見逃す)
- 対応内容: `routes/console.php` のコメントと `docs/architecture.md` の監視対象を
  **5 つ** (`errors` / `deferred` / `escalated` / `cleanup-failed` / `limit-reached`) にし、
  `deferred` が `errors` に出ないことを明記。コマンド出力テストに
  「監視語彙 5 つが必ず出力に含まれる」を追加した

## [Suggestion] 施策 9: StreamInternal 形の変異テストを足す
- 判断: 対応する
- 対応内容: 変異テストに「StreamInternal 形の private ヘルパを別ファイルから呼ぶと赤くなる」を追加
