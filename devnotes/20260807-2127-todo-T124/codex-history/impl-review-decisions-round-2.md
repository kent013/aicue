# 対応マトリクス: impl-review Round 2

## [Warning] precheck の根拠記述が「保持できる画面状態」を誇張している (Security.svelte / docs/architecture.md)

- 判断: **対応する**
- 根拠: 指摘のとおり実装と食い違っていた。`enableTwoFactor()` の precheck が守るのは
  enrollment の**開始**操作であり、成立後の分岐では直後に `resetEnrollmentAssets()` が走る。
  この時点で QR / セットアップキー / 入力中コードは**まだ存在しない**ため、
  「それらが失われる」は起こり得ない (素材取得後の鮮度切れは
  `loadEnrollmentAssets()` の 409 分岐が別途担当している)。
  保証範囲を誇張した記述は、本リポジトリが最も嫌う偽の安心を生む。
- 対応内容: 2 箇所の根拠記述を「**設定画面から離脱せず**、再認証成立後に開始操作を
  その場で再開できる」へ狭めた。併せて「守っているのは離脱の回避であって
  QR / 入力中コードの保持ではない (この時点で素材はまだ存在しない。素材取得**後**の
  鮮度切れは 409 分岐が担当する)」という但し書きを明示的に足し、
  次に読む人が同じ誤解をしないようにした。
  - `resources/js/pages/Settings/Security.svelte` の `enableTwoFactor` docblock
  - `docs/architecture.md` §2FA 面の step-up (recent-auth) 契約 §クライアント側 (enrollment 動線)
- 実装 (precheck を enable の前段に置く順序) は**変えていない**。Codex も
  「T125 後も precheck 自体を残す判断は妥当」と述べており、争点は記述の範囲だけだった。
