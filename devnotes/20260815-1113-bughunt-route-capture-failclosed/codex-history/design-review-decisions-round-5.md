# 対応マトリクス: design-review Round 5

## [Warning] 施策1/5: 変更後コードのコメントに引用符付きの旧語彙が残っている

- 判断: **対応する**
- 根拠: 施策 5 で足す `IMPLEMENTATION_ONLY_PATTERNS` は引用符付きの旧語彙を検出するため、
  そのまま実装すると `correlate.py` が検出されて `test_naming_no_stale` が落ちる
  (設計が自分の gate に違反していた)。
- 対応内容: コメントを肯定形
  `# status の語彙は ok|blocked の 2 値だけを受け付ける。` に直した。
- 残る出現箇所を全数確認した:
  (a) 施策 1 の「改名する」説明 — devnotes 内の設計文書であり gate の走査対象外
      (`test_naming_no_stale.py` は `devnotes` を parts に含むパスを除外する)
  (b) 施策 5 の gate パターン定義 — `test_naming_no_stale.py` に入るが、
      同ファイルは既存の `EXCLUDE_NAMES` で自己除外される。
      この自己除外が効くことを確認する項目をテスト計画に持っている。
