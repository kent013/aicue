# 対応マトリクス: conceptual-review Round 3

## [Warning] 施策 1 冒頭に旧仕様 (既定値補完) が残り 施策 1-b と矛盾 (観点 4)
- 判断: 対応する
- 根拠: 指摘どおりの記述矛盾。
- 対応内容: 「`fetchRecentAuthStatus()` が欠損を既定値で埋めて型を確定させる」を
  「strict parse により検証済みの値だけを `RecentAuthStatus` として返す (詳細は施策 1-b)」へ置換した。

## [Warning] delegated 経路の HTTP 遷移の記述が矛盾 (観点 3)
- 判断: 対応する (かつ実装上の穴を 1 つ発見したので同批で閉じる)
- 根拠: 指摘を受けて実装を確認したところ、より深い問題が判明した。
  `RequireRecentAuth` は Inertia の非 GET / `expectsJson()` に **409 +
  `RecentAuthRequiredResource` (`code: recent_auth_required`, `redirect`)** を返すが、
  **この 409 を拾って `redirect` へ遷移するクライアントが存在しない**
  (`grep recent_auth_required resources/js` = 0 件)。一方 `withRecentAuth` の delegated 分岐は
  「再認証が必要な場合は確認ページへ移動します。」と toast で予告している = 予告だけして誰も移動させない
  無言失敗。strict parse 化 (施策 1-b) は delegated への流入を増やすため、放置できない。
- 対応内容: 「施策 1-c: delegated の着地を実装事実に合わせて閉じる」を追加。
  409 の `code` 厳格一致で自分宛て応答だけを拾い `router.visit(redirect)` する
  **単一ハンドラ**を `lib/recent-auth.ts` に置き、アプリ初期化 1 箇所で配線する
  (他の 409 契約 `scenario_conflict` / `two_factor_required` を誤食しないことが要件)。
  302 になるのは非 XHR の通常遷移経路であることも明記し、両者の区別を固定した。
  テストは「malformed status → delegated → 409 → confirm 画面へ visit」を JS で 1 本通す。

## [Warning] JS contract テストは provider 要素まで検査対象にする (観点 7)
- 判断: 対応する
- 根拠: 妥当。provider 要素の欠落は「SSO ボタンが出ない」= 今回と同 species の詰み。
- 対応内容: strict parse の受入条件に
  (a) top-level 全 field の欠損・型不一致、(b) `availableProviders` の非配列、
  (c) 要素の `provider` / `capability` / `reauthUrl` の欠損・型不一致 を含めた。

## [Suggestion] null 分岐の「再読み込み案内」を押せる導線にする (観点 5)
- 判断: 対応する
- 根拠: 文言だけの案内は「回復導線」と呼べない (本批の主題そのもの)。
- 対応内容: null 分岐に `router.reload()` を実行する「再読み込み」ボタンを置く形に修正した。
