# 対応マトリクス: design-review Round 1

全体判定: APPROVED (施策1 APPROVE / 施策2 REQUEST_CHANGES)。Critical なし。Warning 2 件 (いずれも
施策2 テスト実装詳細) を詳細設計に反映する。

## [Warning] reactiveUseForm の processing がリアクティブでない
- 判断: 対応する
- 根拠: 将来 `loading={confirmForm.processing}` の遷移検証を追加する際に固定値だと詰まる。
  getter/$state 化は後方互換 (既存利用箇所は読み取りのみ)。
- 対応内容: reactiveUseForm に `processing` の `$state`/getter 化 + `setProcessing(bool)` 公開を
  波及変更・テスト戦略に追記。任意の processing 遷移テスト(補)も追加。

## [Warning] 誤コード表示テストが errors.code 直接代入のみで submit→onError 経路が抜ける
- 判断: 対応する
- 根拠: 実運用に近い経路 (submit → post の onError 発火 → UI 反映) を固定すべき、という指摘は妥当。
- 対応内容: テスト計画 (b) を「confirmForm.post の options.onError?.({ code }) 駆動で errors.code に
  反映し、getByText + aria-invalid を検証」に変更。直接代入は補助アサートに降格。

## [Suggestion] 根本原因診断・errorBag 追加・const 化・TS 型前提はいずれも妥当
- 判断: 対応不要 (肯定)
- 根拠: 施策1 は APPROVE。診断・修正・型前提の妥当性を追認。

次ラウンド: 上記 2 Warning を反映済みとして施策2 の APPROVE を確認する。
</content>
