# 対応マトリクス: conceptual-review Round 3

## [Critical] フォーカス復帰のタイミング(onError 時は disabled で focus 不可)
- 判断: 対応する
- 根拠: `onError` 時点では `changingRole === true` のため remount 後の Select も disabled。disabled 要素へは focus できない。`changingRole` 解除は後続 `onFinish`。
- 対応内容: `roleRefocusMemberId: number | null` を追加。`onError` で remount トークン `+1` と `roleRefocusMemberId = member.id` を保存するのみ。`onFinish` で `changingRole = false` にした後、`roleRefocusMemberId` があれば `await tick()` → 当該 Select へ focus → クリア。成功時は `roleRefocusMemberId` を設定しない(復帰対象を残さない)。

## [Warning] 「制約・前提」の「Select は disabled にせず」が実装方針と矛盾
- 判断: 対応する
- 対応内容: 「必須条件未充足では disabled にせず、通信中のみ二重送信防止として disabled にする」へ表現修正。

## [Warning] フォーカス復帰の回帰テストが無い
- 判断: 対応する
- 対応内容: テスト計画に「拒否 → onFinish 後に `document.activeElement` が失敗行の Select であること」を検証するケースを追加(計6ケース)。

## [Suggestion] 各種(422退け/一方向value分析/{#key}/型安全)
- 判断: 見送る(支持済み、追加対応不要)
