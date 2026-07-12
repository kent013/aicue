# 対応マトリクス: design-review Round 1

## [Critical] scrollIntoView の inline/behavior 未指定で UA 差異が残る
- 判断: 対応する
- 対応内容: `scrollIntoView({ block: "nearest", inline: "nearest", behavior: "auto" })` に全指定固定。Vitest は引数完全一致で検証する旨をテスト計画に明記

## [Critical] 401 / 419 リトライ失敗経路の回帰テスト不足
- 判断: 対応する
- 根拠: これらの経路も `showFailure()` 経由に書き換わるため回帰テスト必須 (禁止事項 1)
- 対応内容: テスト計画に「401 でセッション切れメッセージ表示」「419 自動リトライ後も 419 で同メッセージ表示」の 2 件を追加

## [Warning] 403 応答 body の message を捨てる
- 判断: 反論する (固定文言を維持)
- 根拠: 現状サーバの 403 は Laravel 既定文言 (英語 "This action is unauthorized.") であり、優先表示すると日本語 UI に英語が混入して UX が悪化する。また同レビューの横断指摘 ([Warning] 認可失敗の可観測化で内部状態を出しすぎない) とも整合する。サーバが意図した日本語 message を返す契約が将来できた時点で対応する方が「今必要なものだけ作る」に合致
- 対応内容: 設計の実装ノートに固定文言の根拠を明記し、固定文言を Vitest fixture (厳密一致) として登録する方針を追記

## [Warning] action snippet 常時受け渡しで空 mt-4 が恒常化
- 判断: 対応する
- 対応内容: トップレベル `{#snippet reloadAction()}` を宣言し、`action={failureView.showReloadCta ? reloadAction : undefined}` の条件付き prop 受け渡しに変更 (既存の空余白問題も同時に解消)。analyzing 競合テストで「空 action 余白を出さない」ことも検証

## [Warning] F-05: route 名変更時の config キー取り残し
- 判断: 対応する
- 対応内容: ManualPageTitleTest の URL 構築を `route()` 名前解決にする方針をテスト計画に明記 (route 名変更で fail する)

## [Warning] F-05: title/Inertia prop 同値確認が代表 1 画面のみ
- 判断: 対応する
- 対応内容: show / edit / capture.show の 3 画面 (動的 setPrivateTitle 経路すべて) で assertInertia 検証に拡張。create (静的 app_titles 経路) は SeoHeadCompositionTest が契約固定済みのため <title> 検証のみ

## [Warning] 403 文言の fixture 固定 (詳細漏えい防止)
- 判断: 対応する
- 対応内容: 403 固定文言の厳密一致テストをテスト計画に明記

## [Suggestion] assertNever の runtime throw が本番 UX を悪化させる
- 判断: 対応する
- 対応内容: `unreachableFailureView(value: never): FailureView` に変更 (型網羅は never 引数で担保しつつ、runtime 到達時は throw せず汎用 fallback view を返す)

## [Suggestion] タイトル接尾辞の i18n 化
- 判断: 見送る
- 根拠: v1 は日本語 UI 固定で、既存の app_titles / setPrivateTitle も全て日本語ハードコード。多言語化はアプリ横断の別施策
