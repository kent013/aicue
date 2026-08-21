# 対応マトリクス: impl-review Round 1

## [Warning] 表示開始後に「無効なままエラー理由だけが変化する」回帰テストが削除されている
- 判断: 対応する
- 根拠: 旧テスト「無効のまま別の無効理由に変えると文言が現在の理由へ追随する」(max 0→5) の
  カバレッジを、per-field 化に伴い落としていた。DESIGN.md §FormField「無効の理由が変わったら
  文言も変わる」契約と、設計の「既存テストのカバレッジ喪失なし」方針に反する。初回提示後に
  文言・aria-invalid を固定してしまう回帰が現在のテスト群を素通りする余地がある (正当な指摘)。
- 対応内容: max "0"(範囲外) 提示 → max "5"(threshold 以下=大小関係違反) へ変更しても無効のまま、
  (a) max spinbutton の accessible description が「開始残高より大きい値」へ更新される、
  (b) 同一 live region 要素の本文も同じ理由へ更新される、
  (c) max は引き続き aria-invalid="true"、threshold は aria-invalid が付かない、を検証する
  テストを AutoRechargeCard.test.ts に追加した (aria/live 版で移設・復元)。

## 実装本体 (ProfileUpdatedResponse.php / AutoRechargeCard.svelte)
- Codex 判定: 指摘なし (設計と整合)。変更不要。
