# 対応マトリクス: design-review Round 4 (判定 APPROVED)

Critical / Warning は無い。

## [Suggestion] AC5 の「コミットされた」は検証コマンドの保証より強い

- 判断: 対応する
- 根拠: `git ls-files --error-unmatch` は追跡下であることを見るだけで、
  新規に stage しただけの未コミットのファイルでも成功する。
- 対応内容: AC5 の条件文を「**git 追跡下の** `red-first-evidence.md`」へ直した
  (保証範囲の注記と完全に一致させた)。
