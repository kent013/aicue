# 対応マトリクス: conceptual-review Round 1

## [Critical] `/app/account` に project context が無いのに復路を `/app/projects/{project}/manuals` と書いている
- 判断: **対応する**
- 根拠: 指摘のとおり設計の穴。ただし提案 3 案はいずれも採らない。
  - `/app/projects/{project}/account` にすると nested route IDOR inventory 登録・scopeBindings・
    「project に属さない情報を project 配下に置く」という意味の歪みを負う。この画面は project の
    データを 1 つも表示しないので、親を持たせる理由が無い。
  - `?return_to=` は open redirect の検査を新設することになり、得るものに対して面が広い。
  - `history.back()` は standalone の履歴状態に依存し、テストできる契約にならない。
- 対応内容: **復路を `/app` (`capture.home`) 1 本に固定**する。`capture.home` は
  `DefaultProjectResolver` で current org の既定 project の一覧へ redirect する既存 route であり、
  PWA の `start_url` そのものである。route parameter を持たず、open redirect も履歴依存も無い。
  「撮影に戻る」という語もホームへ戻る意味と一致する。

## [Warning] 導線をどの UI に置くか未定義
- 判断: **対応する**
- 対応内容: `pages/Capture/Index.svelte` の見出しを `PageHeader` → `PageHeaderSection` に変え、
  その `children` (actions) に `TextLink`「アカウント」を 1 本置く。`Capture/Show.svelte` には置かない
  (既に「一覧へ戻る」「マニュアル詳細へ」の 2 本があり、狭幅で 3 本目が折り返す。撮影中に
  アカウントを確かめる場面は想定しない)。成功条件も「一覧画面から 1 タップ」に修正した。
  案 B (共有ドロワーへ email 表示) を退けた理由との整合は保たれる — 導線は `Capture/*` 側にだけ足し、
  PC と共用の `AppLayout` には触れない。

## [Warning] `/settings` への副導線が「戻れない PC 設定画面へ出す」問題を残す
- 判断: **対応する (副導線ごと削除)**
- 根拠: 指摘のとおり。G3 をスコープ外にしたまま自分で G3 の入口を新設するのは筋が通らない。
  `個人設定` は既に `AppLayout` のドロワーにあり、新画面が同じリンクを持つ必要は無い。
- 対応内容: `/settings` へのリンクを画面から削除し、代わりに**リンクではない説明文**
  (「表示名・メールアドレスの変更は PC の個人設定から行います」) を置く。
  これで「変更したい人が黙って詰まる」ことも避けつつ、復路の無い面への入口を新設しない。

## [Warning] 未契約 (課金ゲート遮断) 時にログアウトできるのか
- 判断: **対応する (根拠を明記)**
- 根拠: 実コードを確認した。遮断時の着地 `pages/Onboarding/BillingRequired.svelte` と
  `pages/Onboarding/Checkout.svelte` はどちらも `AppLayout` を使っており、既存のログアウト導線を持つ。
  よって遮断中もログアウトは可能で、専用画面が課金ゲート内にあることによる詰みは生じない。
- 対応内容: §5 制約に明記した。

## [Warning] 「1 route 足すだけ」はスコープを軽く見積もっている
- 判断: **対応する**
- 対応内容: §2 の表現を改め、実装スコープを列挙する節 (§8) を新設した
  (route / controller / page / 導線 / logout call-site inventory / docs/supported-browsers.md /
  bug-hunt inventory / Feature テスト / Vitest ページテスト)。

## [Warning] `currentOrganization.name` の nullability
- 判断: **対応する**
- 根拠: `SharedProps.currentOrganization` は nullable で、`HandleInertiaRequests` は
  「current_organization_id が指す組織に**非所属**なら null に倒す」実装になっている。
- 対応内容: controller で `resolveMemberCurrentOrganization()` (current org 解決 + 在籍 guard、
  非所属は認可より前に 404) を使い、共有 prop が null になる条件と**同じ述語**をサーバ側に置く。
  そのうえで Svelte 側は `currentOrganization` が null なら**組織行を出さない**
  (到達不能だが、偽の既定値を作らない)。`auth.user` も同様に null なら画面本体を描かない。
  なお `auth.user.id` は**表示しない** — 内部 DB の主キーであり利用者にとって意味を持たない。
  doc の言う「ユーザー ID」の実体はログイン ID = メールアドレスである。

## [Warning] `loggingOut` の disabled が禁止事項 8 の回避に見えないようにする
- 判断: **対応する**
- 対応内容: §5 に「二重送信ガードであり必須条件未充足の disabled ではない」と明記し、
  詳細設計のテスト名も「送信中は再送しない」で書く。

## [Suggestion] DTO を増やさない判断 / 「作らない」も成立する
- 判断: 維持 (方針は変えない)
- 根拠: Codex 自身が「作る判断は承認可能な方向」と述べている。G1 (メールが /app のどこにも無い) と
  G2 (確認のために退会ボタンのある面へ行く) は実コードで確認できた実在のギャップである。
