# 対応マトリクス: impl-review Round 2

Codex 全体判定: CHANGES_REQUESTED (施策1〜3 承認、施策4 未完了)。残 Warning 2 件に対応した。

## [Warning] CaptureShow.test.ts: 承認済み設計の観測範囲を実装側で狭めている / before-event でなく後結合 collector
- 判断: 対応する (観測範囲を狭めるのではなく、設計が要求する live 観測を別テストで満たす)
- 根拠: Codex の指摘は妥当。JS mock collector は `<Link>` / form helper が内部の別 router 参照を
  使う場合を捕捉できず、docblock で範囲を狭めるだけでは設計との不一致は解消しない。
- 対応内容: JS collector テスト自体は「アプリ自コードの programmatic 入口」の回帰として残す
  (範囲は docblock で正直に明示)。その上で、**設計が求める live 観測 (`<Link>` を含む実遷移を
  実ブラウザで観測) を `tests/Browser/CaptureAppBoundaryTest.php` で満たした**。実 Playwright で
  クリーンな単一セッションを走らせ、Performance API の navigation/resource entry と location で
  /app 離脱の有無を実観測する。これにより観測範囲を狭めていない (実 `<Link>` を含む document 遷移も
  ブラウザ実測で捕捉される)。

## [Warning] phase-a-investigation.md: 必須調査 (Playwright 実観測) が未実施 = 分岐 (c) に未到達
- 判断: 対応する
- 根拠: 「観測を試みていない」状態では分岐 (c) を主張できないとの指摘は妥当。設計は
  「証拠の正本はネットワーク最終 response」であり Playwright 実観測を Phase A の必須手順としている。
- 対応内容: **実ブラウザ観測を実施した** (`tests/Browser/CaptureAppBoundaryTest.php`。
  Chromium / WebKit 両レーンで green、8 assertions/レーン)。観測結果:
  (1) document は /app 配下に留まる (navigation entry の /app 外 0 件)、
  (2) fetch/XHR は /app 配下のみ (外部 0 件)、
  (3) 唯一の /app 外リンク (PC 詳細 = T155) は anchor href として存在するが自動遷移しない。
  devnotes を「調査手段 (1)静的走査 + (2)live 観測」の 2 段構成に更新し、live 観測結果と
  分岐 (c) (アプリ起因の自動 /app 離脱を再現できず・ハーネス起因とは断定しない) を記録した。
  分類基準は「/app 離脱が現れた場合に区別する枠組み」として残し、本観測では 0 件だった旨を明記。
