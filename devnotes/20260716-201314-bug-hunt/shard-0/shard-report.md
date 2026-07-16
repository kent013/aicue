# bug-hunt report 20260716-201314 (shard-0 直列フォーカス走行)

- 焦点: **T069 左サイドバー移行後のナビ構造・レイアウト幅準拠**(ユーザー依頼)。screens.md「ナビゲーション/レイアウト規約」+ 横断ヒューリスティクス H10/H11/H13 を認証画面で検査。
- 対象画面: dashboard / projects / billing / manage/users / settings を desktop(1280×800) + mobile(375×812) で実操作。
- アカウント: `owner-free@example.com` / `password123`(ManualTestSeeder)。
- 環境: bughunt 隔離(:8010, DB bug_hunt, dev DB は env -i 遮断)。browser=firefox(ARM64: chrome チャネル非対応のため bundled firefox を使用)。real-llm 既定だが本走行は LLM 呼び出し面に踏み込まず非依存。
- ブラウザ: `@playwright/cli -s=bughunt0`。

## findings: High 0 / **Medium 2** / Low 0 / 要確認 0

### F-0-1: 「設定」が左サイドバー nav と下部ポップアップに二重掲載 (T069 規約違反)
- severity: **Medium** (H10 = 直前設計との矛盾 / 二重掲載。操作は可能だが導線の一貫性破綻)
- story/step: S1-5 (ログイン後シェル構造検証)
- 再現手順: `owner-free@example.com` でログイン → `/dashboard`。左サイドバー nav に「設定」(→ `/settings`) が項目として表示される。加えて下部の組織/ユーザーメニュー(トグル展開)内にも「個人設定」(→ `/settings`) が存在する。両者が同一 `/settings` を指す。
- 期待: screens.md「ナビゲーション/レイアウト規約」(T069, aigenba 準拠) では **個人設定 `/settings` は下部ポップアップ (SidebarUserMenu) 専用**。左サイドバー nav 項目としては出さない (T069 で設定はポップアップへ移動した規約)。
- 実際: 左 nav (`AppLayout.svelte` の `navItems`) に `{ href: "/settings", label: "設定" }` が残存し、SidebarUserMenu の「個人設定」と**二重掲載**。参照アプリ aigenba では左 nav に個人設定は無い。
- 阻害されたユーザージョブ: 致命ではないが、同一機能への導線が 2 箇所に分裂し「設定はどこか」の一貫したメンタルモデルを壊す。参照アプリとの UI 統一(T069 の目的)が未達。
- 改善アクション候補: `AppLayout.svelte` の `navItems` から `{ href:"/settings", label:"設定" }` を除去し、個人設定は SidebarUserMenu のみに一本化する(1 行削除 + AppLayout.test の「/settings が nav に無い」負例追加)。
- 証跡: screenshots/desktop-settings.png (左 nav に「設定」がハイライト), スナップショット (nav link e59 `/settings` + popup link e126 `/settings`)。
- 推定原因: **T069 実装時に navItems へ「設定」を含めたまま、SidebarUserMenu にも個人設定を置いた**(詳細設計のナビ表では設定を nav に含めていたが、aigenba は設定を nav に置かない。設計時の翻訳ミス = regression)。
- 関連既知情報: 設計 `devnotes/20260716-1757-login-sidebar-nav/detailed-design.md` の nav 表に「設定 /settings ログイン時」を含めており、この設計判断自体が aigenba と乖離していた。**本 run で更新した screens.md 規約が正**。

### F-0-2: ページ本文幅がページ間で不統一・左寄せで右側デッドスペース (新サイドバー幅への非準拠)
- severity: **Medium** (H11 = 視覚的一貫性/レイアウト。操作阻害はないが見た目が不揃い)
- story/step: S1-5 (ページ幅準拠検証)
- 再現手順: ログイン後、desktop 1280×800 で各ページを開き本文右端を測定。
  - `/settings`: 本文右端 x=960 → **右デッドスペース 320px**(内側 max-width が狭い ~672px 列を左寄せ)
  - `/billing`: 本文右端 x=1056 → 右デッドスペース 224px(~768px)
  - `/manage/users`: 本文右端 x=1248 → 右デッドスペース 32px(ほぼ全幅・テーブル)
- 期待: screens.md「ページ幅/レイアウト準拠」規約 = 各ページ本文が新シェル(サイドバーオフセット配下の 960px コンテンツ領域)に対し**一貫した幅方針**で収まる。
- 実際: 旧 `AppLayout` の `max-w-6xl mx-auto`(中央寄せ)を T069 で撤去したため、各ページが**独自の内側 max-width で左寄せ**になり、ページ間で本文幅が 672/768/~960px とバラつき、右側デッドスペースが 320/224/32px と大きく不揃い。中央寄せが無いため広い画面ほど左偏り+右余白が目立つ。
- 阻害されたユーザージョブ: 致命ではないが、画面遷移ごとに本文幅・余白が変わり視覚的まとまりを欠く(特に設定の 320px 右余白は「未完成」に見える)。T069 の「参照アプリ水準の UI 一貫性」目的に対する後退。
- 改善アクション候補: シェル側 (`AppLayout` の `<main>` コンテンツラッパ) に**統一コンテンツ幅方針**を定義する(例: 一貫した `max-w-*` + 中央寄せ、またはページ種別ごとの規約)。参照アプリ aigenba の content 幅方針に合わせるのが T069 の趣旨。**幅方針の決定は設計判断**のため app-design 案件化を推奨。
- 証跡: screenshots/desktop-settings.png(狭い左寄せ+大きな右余白) / desktop-billing.png(別幅) / desktop-manage-users.png(全幅)。測定値は上記。
- 推定原因: T069 で `max-w-6xl mx-auto` を落とし、代替の統一幅規約を設けなかった。各ページの旧来 max-width がそのまま露出。
- 関連既知情報: 設計 detailed-design.md「main: lg:[margin-left:var(--app-sidebar-w)] 内に children」— content 幅の統一規約は明記されていなかった(スコープ外扱い)。

## カバレッジ (フォーカス走行 = 全画面網羅ではない)
- 画面: dashboard / projects / billing / manage/users / settings を走行 (desktop+mobile)。ナビ構造・幅の横断規約に焦点。他の screens.md 画面 (capture 系 S3, 招待 S2, 認可境界 S7 等) は**本フォーカス走行では未走行**(全 --all 走行ではない)。
- 操作: 書き込み操作 (operations.md) は本 run では実行せず (ナビ/レイアウト構造の検査に限定)。
- UI/UX 検証: H10(二重掲載) → F-0-1。H11(幅不統一) → F-0-2。H13(mobile 375): dashboard/projects/billing/manage-users/settings で documentElement 横スクロールなし (375/375)。console error 0 / 4xx-5xx 0。
- **注記 (依頼への回答)**: ユーザー指摘の 2 点 (「設定がポップアップに移ったのに左メニューに残っている」「一部ページの横幅がレイアウト非準拠」) は **両方とも本 run で検出できた** (F-0-1 / F-0-2)。したがって「検出できなかった場合はメッセージをレポートに含める」条件には該当しないが、依頼趣旨に沿い両 finding を上記に明記した。

## TODO 候補 (app-design → app-todo-add に渡せる粒度)
1. **[Medium] 設定の二重掲載解消** (F-0-1): `AppLayout.svelte` navItems から「設定」を除去し個人設定は SidebarUserMenu のみに。テスト: AppLayout.test に「/settings は左 nav に出ない」負例追加。小さく確実 (regression fix)。
2. **[Medium] コンテンツ幅の統一規約** (F-0-2): シェルに一貫した content 幅 (max-width + 中央寄せ 等) を定義し全認証ページに適用。参照アプリ aigenba の幅方針に準拠。幅決定は設計判断のため app-design 推奨。
