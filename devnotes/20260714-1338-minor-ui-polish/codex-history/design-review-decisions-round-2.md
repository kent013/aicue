# 対応マトリクス: design-review Round 2

全体判定: **APPROVED** (Critical/Warning なし。Round 1 の Critical は Codex が撤回)。
以下は Suggestion のみ。テスト頑健化のため設計へ取り込み済み。

## [Suggestion/S1] スクショは操作ブロック最大幅の代表状態で取得・PR 明記
- 判断: 対応する
- 対応内容: テスト計画に「fixture id=3 相当 (2FA+未割当バッジ+2FA解除+未割当select+削除) の
  最大幅行で 768/834 スクショ取得、対象データ/権限/操作を PR に明記」を追記。

## [Suggestion/S1] `sm:justify-between` 不在も assert (逆戻り防止)
- 判断: 対応する
- 対応内容: `<li>` が `sm:justify-between` を持たないことの assertion を追記。

## [Suggestion/S2] 同名トグル 2 個 → within() スコープで順序依存回避
- 判断: 対応する
- 対応内容: 各入力の FormField/コンテナを `within()` でスコープする旨をテスト計画に明記。

## [Suggestion/S2] aria-describedby は参照先エラー要素の存在まで確認
- 判断: 見送る (実装時裁量)
- 根拠: 透過保持の回帰が主目的。エラー要素存在は FormField 既存テストが別途担保。実装時に
  余力があれば追加する旨をテスト計画の趣旨に含める (必須化はしない)。

## [Suggestion/S2] 送信テストで bind:value 更新も確認
- 判断: 対応する
- 対応内容: submit 検証に「入力後 formHolder.password.current_password/.password が更新される」
  を追記し bind:value 等価性を担保。
