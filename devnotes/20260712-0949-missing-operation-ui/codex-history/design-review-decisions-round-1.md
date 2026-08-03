# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED (Critical 0 / Warning 2 = いずれも施策 2)

## [Warning] 施策2: 失敗レスポンスモック `{ ok: false, status: 500 }` は実装変更に脆い
- 判断: 対応する
- 根拠: fetchJson は現状 `response.ok` で throw するため動くが、実装が先に `json()` を読む形に変わるとモックが TypeError で別理由の失敗になる。テストの意図 (HTTP 失敗) を頑健に表現すべき。
- 対応内容: 失敗レスポンスに `json: () => Promise.resolve({})` を追加し、意図をコメントで明記 (detailed-design.md 施策 2 のテストコードを修正)。

## [Warning] 施策2: `as ReturnType<typeof lastVisitOptions>` の自己参照キャストは読みづらい
- 判断: 対応する
- 根拠: 型崩れ時の検知性の指摘は妥当。
- 対応内容: `interface InertiaVisitOptions { onStart?/onSuccess?/onError?/onFinish? }` を明示定義し、キャストと返り値型をそれに置換。

## [Suggestion] 施策1: onSuccess 内 async IIFE を関数に切り出す
- 判断: 対応する
- 対応内容: `handleRegenerateSuccess(): Promise<void>` に切り出し、`onSuccess: () => { void handleRegenerateSuccess(); }` に変更。

## [Suggestion] 施策2: processing 連動 (二重送信抑止) のケース追加
- 判断: 対応する
- 対応内容: 「POST 実行中 (onStart〜onFinish) は確認ボタンが aria-busy になる」ケースを追加 (Button atom は loading 中 `aria-busy` を立てることを確認済み)。

## [Suggestion] 施策3: 候補 0 人メッセージの定数化
- 判断: 対応する
- 対応内容: `NO_TRANSFER_CANDIDATES` 定数を導入し、案内文と押下時エラーで共有。トースト文言 (施策 1) は各 1 箇所使用のため定数化しない (根拠を設計判断メモに記載)。

## [Suggestion] 施策4: myId が取れる前提のケース (自分のみ member) の将来追加
- 判断: 見送る
- 根拠: 既存 OrganizationsSettings.test.ts は page 未モックで成立しており、page のモジュールモック導入は既存 6 ケースの前提 (実 page) を変えるリスクがある。`transferCandidates.length === 0` の分岐カバレッジは members: [] で同一。実環境近似 (自分のみ member で候補 0) の検証は bug-hunt 再走行 / Feature テスト側の守備範囲とする。
- 対応内容: 施策 4 の設計判断メモに理由を記載済み (members: [] を使う理由のコメントをテスト内に残す)。
