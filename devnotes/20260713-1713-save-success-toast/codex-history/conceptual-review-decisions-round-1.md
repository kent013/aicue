# 対応マトリクス: conceptual-review Round 1

全体判定: **APPROVED**（Critical 0 / Warning 1 / 残りは Suggestion）。

## [Warning] 再生成成功 toast 直後に GET 失敗 error toast が矛盾して見えるリスク
- 判断: **対応する**
- 根拠: happy path の二重は解消されるが、GET 失敗エッジで success + error が並ぶと
  「再生成の成否」が矛盾して読める余地がある。文言で「成功対象＝再生成」「失敗対象＝表示取得」を
  明示すれば別事象と伝わり、正本一貫（サーバ flash = 成功）を崩さず解消できる。
- 対応内容:
  1. `Security.svelte` の GET 失敗 error toast 文言を、再生成成功と表示失敗が別事象と分かる表現へ調整
     （例:「リカバリコードは再生成されましたが、新しいコードの表示取得に失敗しました。
     『リカバリコードを表示』から再取得してください。旧コードは既に無効です。」）。
     詳細設計の該当施策に明記。
  2. この GET 失敗エッジの vitest ケースを追加（success toast は client から出さない／error toast のみ client、
     文言に「再生成されました」と「表示取得に失敗」の双方が含まれる）。

## [Suggestion] 使命寄与の表現を「土台の補強」に寄せる
- 判断: 対応する（表現調整）
- 根拠: 過大主張を避ける。概念設計は既に「基礎 UX の補強」寄りだが、詳細設計でも同トーンを維持。
- 対応内容: 詳細設計の効果記述を定性表現（二重送信抑止は定性効果）に留める。

## [Suggestion] 処理を Controller に漏らさず Response contract に閉じる
- 判断: 対応する（設計方針として明記）
- 根拠: 既存パターン踏襲。Controller 追加・変更はしない。
- 対応内容: 詳細設計で「Controller 変更なし・Response contract 実装のみ」を明示。

## [Suggestion] wantsJson 時のレスポンス形状まで Feature test で固定
- 判断: 対応する
- 根拠: XHR/API 契約の後退防止。既存 FortifyResponseTest の方針と一致。
- 対応内容: 3 操作それぞれ web(success flash) と wantsJson(JSON 200 形状) の両ケースをテスト計画に含める。

## [Suggestion] 横展開を同 PR に混ぜない / DTO を無理に持ち込まない
- 判断: 対応する（スコープ据え置き）
- 根拠: 既にスコープ外に明記済み。
- 対応内容: 変更なし。
