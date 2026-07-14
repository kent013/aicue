# 対応マトリクス: design-review Round 1

全体判定は **APPROVED**（Critical なし）。S1/S2 とも APPROVE。Warning/Suggestion を詳細設計に反映済み。

## [Warning] (S2) confirm ボタンを全体 screen で引くと将来曖昧化
- 判断: 対応する
- 根拠: 同名ボタン追加時の脆弱性。
- 対応内容: テスト計画に `const dialog = screen.getByTestId("take-delete-dialog"); within(dialog).getByRole(...)` を必須化として明記。

## [Warning] (S2) 「キャンセルで未発火」の即時アサーションが非同期に弱い
- 判断: 対応する
- 根拠: 非同期揺らぎ耐性。
- 対応内容: `await waitFor(() => expect(fetchMock).not.toHaveBeenCalled())` へ変更 + `queryByTestId("take-delete-dialog")).not.toBeInTheDocument()` の close 確認を追加。リスク欄にも追記。

## [Suggestion] (S2) 422 更新テストで URL/メソッドも検証
- 判断: 対応する
- 根拠: 回帰耐性向上。
- 対応内容: 422 テストに fetch URL/method 検証を追記。

## [Suggestion] (S1) confirmDelete でクローズ時に deleteLabel を空へ
- 判断: 対応する
- 根拠: 再オープン時の古い文言混入を防ぐ。
- 対応内容: `confirmDelete` 末尾で `deleteLabel = ""` を追加。

## [Suggestion] (S1) deleteLabel を formatTakeLabel 関数へ切り出し
- 判断: 見送る
- 根拠: 現状ラベルは `テイク {index+1}` の 1 箇所生成のみ。抽出は YAGNI（AGENTS.md 思考原則 #2: 今必要なものだけ）。将来フィルタ/並び替え表示仕様が入る時点で導入すれば足りる。
- 対応内容: 変更なし。requestDelete 内でラベル確定を維持。

## [Suggestion] (S1) 422 後の再試行手段を設計書に明記
- 判断: 対応する
- 根拠: 実装者間の解釈差防止。
- 対応内容: テスト計画注に「失敗後の再試行は削除ボタン再押下 → 再度確認ダイアログ」を明記。
