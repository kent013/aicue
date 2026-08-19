# 対応マトリクス: design-review Round 3

セッション ID (resume 用): `01a017d6-062a-7ff3-939c-83934cac13cb`(Round 1 から継続)

## 施策1: 接続 resolver — APPROVE (Suggestion 2件)

### [Suggestion] docblock 内のパス誤記 (`scripts/ci/setup-worktree.sh`)
- 判断: **対応する**
- 対応内容: 実際のパス `scripts/setup-worktree.sh` へ修正した。

### [Suggestion] 「この専用パスを書くのはこのスクリプト自身の子プロセスだけ」の不正確さ
- 判断: **対応する**
- 対応内容: 「通常経路では誰も生成しないはずの専用パス」という表現へ改めた。

## 施策2: callable 注入型オーケストレーション — APPROVE

- 判断: 見送る(APPROVE のみ)

## 施策3: Architecture テスト — APPROVE

- 判断: 見送る(APPROVE のみ)

## 施策4: Unit テスト — REQUEST_CHANGES

### [Warning] 回帰テストの生成 PHP コードに AGENTS.md 禁止事項 (`echo` 文) 違反
- 判断: **対応する**
- 根拠: 別プロセスで実行させるために生成するコードも「書いている」コードである以上、
  AGENTS.md 禁止事項(echo / goto / global / 開始タグ付き出力記法の禁止)の対象になる。
  機械検出(字句走査)を回避できることと規約上許可されることは別、という指摘は正しい。
- 対応内容: `echo "OK";` を `fwrite(STDOUT, 'OK');` へ置き換えた。

### [Suggestion] ensure-test-db.php を個別に require しない理由の説明が古い
- 判断: **対応する**
- 対応内容: Round 3 で require_once へ統一したため「二重ロードで fatal になる」という
  理由づけはもう成立しない。「重複した読み込み宣言を置かない」というスタイル上の理由へ
  書き改めた。

### [Suggestion] 回帰テストの説明が不正確 (「本テストファイル自身を経由」)
- 判断: **対応する**
- 対応内容: 別プロセスが実際に require_once するのは `pgsql_test_conn.php` /
  `drop-test-db.php` / `ensure-test-db.php` の 3 本であることを明記した。

## 施策5: provenance plan — APPROVE

- 判断: 見送る(APPROVE のみ)

## 施策6: GlobalTestLock gate — APPROVE

- 判断: 見送る(APPROVE のみ)

## 施策7: D30/D33 — APPROVE (Suggestion 1件)

### [Suggestion] `scripts/ci/setup-worktree.sh` の誤記
- 判断: **対応する**(施策1 と同じ対応)

## 施策8: worktree 文書 — APPROVE

- 判断: 見送る(APPROVE のみ)

## 施策9: setup 文言 — APPROVE

- 判断: 見送る(APPROVE のみ)

## Round 3 総括

Warning 1 件(禁止事項違反)と Suggestion 4 件、全てに対応した。Round 4 プロンプトで
全文を再送し、最終確認を依頼する。
