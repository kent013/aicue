# 対応マトリクス: impl-review Round 1

全体判定: APPROVED (Round 1)。Critical / Warning は 0 件。

## [Suggestion] load_route_list() 側に timeout がなく Python 単独実行では停止時間を制限できない
- 判断: 見送る
- 根拠: 詳細設計「施策 C リスク」で既知として明記済み (実装不変のため触らない。Pest 結線側の
  外側 timeout 120 秒が覆い、前段テストの 60 秒 timeout が boot 不能の大半を先に読める赤で
  捉える)。Codex 自身もブロッカーではないと明記。correlate.py の挙動変更は本 TODO の
  スコープ外 (変更は docblock のみという設計宣言を破らない)。
- 対応内容: なし (将来 correlate.py 本体を触る施策が立つ場合の候補として本記録に残す)。

## 検証状況への注記 (指摘ではない)
- 「完了・マージ判定には全コマンドの green 確認が必要」→ そのとおり運用する。
  フルスイート (composer test / pint / pnpm 系) の完了を確認してから Phase B (コミット) へ進む。
