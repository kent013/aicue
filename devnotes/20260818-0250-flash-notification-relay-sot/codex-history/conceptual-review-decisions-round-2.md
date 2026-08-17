# 対応マトリクス: conceptual-review Round 2

Round 2 判定: **APPROVED** (残 Critical / Warning なし)。以下は承認と併せて出た補強事項。

## [Suggestion] 非文字列の session 値が null に正規化されることを Feature テストで固定する
- 判断: 対応する
- 根拠: 正規化は設計の主張の一部であり、書いた本人以外にはコードから読み取れない。
- 対応内容: 詳細設計のテスト計画へケースとして明記した。

## [Suggestion] `FLASH_KEYS` は `as const satisfies readonly FlashNotificationKind[]` にする
- 判断: 対応する
- 根拠: 要素型の制約 (union の部分集合であること) とリテラル列の保持を両立できる。
  `readonly FlashNotificationKind[]` だけだと要素がリテラル型でなくなる。
- 対応内容: 詳細設計の変更後コードへ反映した。

## [Suggestion] 正典との照合で差異が出たら、名前合わせでなく「どちらを正本へ寄せるか」を再評価する
- 判断: 対応する (申し送りとして記録)
- 対応内容: 詳細設計の「正典との後追い照合」節に、差異時の判断手順として明記した。
