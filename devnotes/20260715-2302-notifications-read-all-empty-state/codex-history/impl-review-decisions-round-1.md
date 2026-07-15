# 対応マトリクス: impl-review Round 1

全体判定: CHANGES_REQUESTED。Critical 1 + Warning 3 + Suggestion 数件。

## [Critical] Index.svelte JSDoc が禁止事項 #8 準拠を断定しすぎ
- 判断: 対応する
- 根拠: #8 は「disabled にしない」であり「常時非表示必須」ではない。今回の非表示は F-4-01 の
  プロダクト判断。断定を避け根拠 (F-4-01) を明記すべき。
- 対応内容: JSDoc を「bug-hunt F-4-01 のプロダクト判断で非表示。#8 とも整合するが #8 が常時非表示を
  要求するわけではない」に修正。

## [Warning] Svelte 側の実行時値防御 (Math.max/Number)
- 判断: 反論する (コード変更なし)
- 根拠: 分岐は `{#if unreadCount > 0}` のみ。0・null・負値・NaN はいずれも `> 0` が false となり
  安全に非表示へ倒れる (表示すべき値=正の数のときだけ true)。Math.max/Number 変換は分岐結果を
  変えず冗長。詳細設計 Codex レビュー Round 1/2 でも `> 0` 固定を「0件・異常な負値の双方で安全」と
  承認済み。JSDoc に「0・null・負値・NaN は `> 0` false で安全に非表示」と明記して意図を固定。

## [Warning] 控訴 vitest ケース数の不整合 (6 vs 5)
- 判断: 反論する (記載は正確)
- 根拠: NotificationsIndex.test.ts の it() は 5 件 (空状態 / 未読あり押下 / 未読0非表示 / 未読あり表示 /
  一覧表示)。「通知行押下系」テストは本ファイルに存在しない (別ファイル NotificationListItem.test.ts が担当)。
  レビュアーが 6 件目を誤想定。実測どおり 5 passed で正しい。

## [Warning] Controller コメントが長く UI 事情へ寄る
- 判断: 対応する
- 対応内容: コメントを「未読数をページ表示制御用に渡す」+ 衝突理由 1 行 + Index.svelte JSDoc への
  ポインタに短縮。Controller は「何を渡すか」に集中。

## [Suggestion] 全 org 横断の unreadCount テスト追加
- 判断: 対応する (契約強化に有益・低コスト)
- 対応内容: `index: unreadCount は全 org 横断で自分宛未読を数える` を追加 (別組織由来の自分宛通知も
  カウントされることを検証)。既存の「自分宛のみ表示 (全 org 横断)」リストテストと同じ経路。

## [Suggestion] unreadCount=0 でも一覧は表示される組合せ / ViewModel 化
- 判断: 見送る
- 根拠: 一覧表示は notifications 配列基準、read-all は unreadCount 基準で独立していることは既存の
  「通知がある場合は一覧描画」+ 新規条件描画テストで実質担保。ViewModel/Resource 化は今回スコープ外
  (payload 3 要素で過剰設計を避ける = 禁止事項6)。
