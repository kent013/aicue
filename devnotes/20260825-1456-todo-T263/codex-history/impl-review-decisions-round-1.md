# 対応マトリクス: impl-review Round 1

## [Warning] InvitationContinuationKeySoTTest — fail-closed 分岐 (走査根不存在 / 読み取り失敗) の負例が無い
- 判断: 対応する
- 根拠: 設計のテスト計画が「読めないファイル・構文解析不能で fail する分岐」の検査を要求しており、
  現行の IC-4 は構文解析不能・復元不能しか固定していなかった。読み取り失敗分岐が continue へ
  弱体化しても緑のまま、走査根の改名でも黙る — 走査器規約 (c)「検出力は負例で裏取りする」の未充足。
- 対応内容: `invitationContinuationKeyLiteralScan(?string $appRoot = null, ?callable $readFile = null)`
  へ走査根と読み取り処理を引数に切り出し (省略時は実運用の値)、新設 IC-4b で
  (1) 存在しない走査根が `RuntimeException('走査根が存在しません')` になる
  (2) 読み取り失敗 (常に false を返す reader) が `RuntimeException('読めないファイルがあります')` になる
  の両負例を固定した。docblock にも切り出しの意図を追記。

## [Warning] InvitationContinuationTest — 「鍵が無い場合 forget を呼ばない」が偽グリーン
- 判断: 対応する
- 根拠: 初期状態が既に「鍵なし」のため、実装が無条件 forget へ退行しても事後状態は同じで緑のまま。
  設計が明記した契約 (null は forget を呼ばず null) を直接固定できていなかった。
- 対応内容: `SessionStore` を継承した spy (`InvitationContinuationForgetSpySession` —
  forget() の呼び出し回数を記録) を導入し、null ケースで `forgetCalls === 0` を直接 assert。
  併せて「有効 token の resolve は forget を呼ばない (非破壊)」の直接観測ケースも追加した。

## 検証
- composer test --filter=InvitationContinuation: 12 passed / 0 failed
- composer phpstan: No errors
- vendor/bin/pint --test: passed
