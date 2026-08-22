# 画面インベントリ (screens.md) — AI-CUE

> **このファイルは生成物である。手で編集しない。**
> 直し方: 割当ストーリー列は `.claude/skills/app-bug-hunt/stories/S*.md` の前付け
> (`covers_screens` / `covers_operations`) を、区分・理由・種別は
> `inventory/annotations.toml` を、散文は `inventory/notes-*.md` を直してから
> `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
> 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
> ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。

bug-hunt カバレッジの分母となる「画面」(GET × web セッション面) の一覧。全 73 件 (うち対象外 13 件)。

## GET × web 一覧 (画面 + 画面に付随する JSON GET)

| route (URL) | name | 種別 | 画面名 | 割当ストーリー | 区分 |
|---|---|---|---|---|---|
| go | app.entry | 画面 | - | S1 | 通常 |
| organizations/{organization}/billing | billing.index | 画面 | プランとお支払い | S5 | 通常 |
| organizations/{organization}/billing/plans | billing.plans | 画面 | プラン比較 | S5 | 通常 |
| organizations/{organization}/billing/purchase-tickets | billing.tickets.show | 画面 | チケットを購入 | S5 | 通常 |
| organizations/{organization}/app/account | capture.account | 画面 | アカウント | S3 | 通常 |
| organizations/{organization}/app/csrf-cookie | capture.csrf-cookie | JSON | - | S3 | 通常 |
| app | capture.entry | 画面 | - | S3 | 通常 |
| organizations/{organization}/app | capture.home | 画面 | - | S3 | 通常 |
| organizations/{organization}/app/projects/{project}/manuals | capture.manuals.index | 画面 | 撮影するマニュアルを選ぶ | S3 | 通常 |
| organizations/{organization}/app/projects/{project}/manuals/{manual} | capture.manuals.show | 画面 | - | S3 S7 | 通常 |
| organizations/{organization}/app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | 画面 | - | S3 S7 | 通常 |
| organizations/{organization}/app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail | capture.takes.thumbnail | 画面 | - | S3 | 通常 |
| contact | contact | 画面 | お問い合わせ | S1 | 通常 |
| contact/thanks | contact.thanks | 画面 | お問い合わせ完了 | S1 | 通常 |
| organizations/{organization}/dashboard | dashboard | 画面 | ダッシュボード | S1 | 通常 |
| debug/bfcache-trial | debug.bfcache-trial | 画面 | - | - | 外 |
| debug/bfcache-trial/away | debug.bfcache-trial.away | 画面 | - | - | 外 |
| debug/login | debug.login | 画面 | - | - | 外 |
| / | home | 画面 | - | S1 | 通常 |
| invitations/accept | invitations.accept | 画面 | 組織への招待 | S2 | 通常 |
| commerce-disclosure | legal.commerce-disclosure | 画面 | - | S1 | 通常 |
| privacy | legal.privacy | 画面 | - | S1 | 通常 |
| terms | legal.terms | 画面 | - | S1 | 通常 |
| login | login | 画面 | ログイン | S1 | 通常 |
| organizations/{organization}/manage/users | manage.users.index | 画面 | ユーザー管理 | S4 | 通常 |
| organizations/{organization}/notifications | notifications.index | 画面 | 通知 | S6 | 通常 |
| organizations/{organization}/onboarding/billing-required | onboarding.billing-required | 画面 | 課金手続き中です | S2 | 通常 |
| organizations/{organization}/onboarding/checkout | onboarding.checkout | 画面 | プランの選択 | S1 | 通常 |
| organizations/{organization}/api-keys | organizations.api-keys.index | 画面 | API キー | S4 | 通常 |
| organizations/{organization}/api-keys/sessions | organizations.api-keys.sessions.index | 画面 | 接続セッション | S4 | 通常 |
| organizations/create | organizations.create | 画面 | 組織の作成 | S4 | 通常 |
| organizations/{organization}/onboarding/cli | organizations.onboarding.cli | 画面 | CLI 導入ガイド | S4 | 通常 |
| organizations/{organization}/onboarding/mcp | organizations.onboarding.mcp | 画面 | MCP 導入ガイド | S4 | 通常 |
| organizations/{organization}/settings | organizations.settings | 画面 | 組織設定 | S4 | 通常 |
| passkeys/confirm/options | passkey.confirm-options | JSON | - | S6 | 通常 |
| passkeys/login/options | passkey.login-options | JSON | - | S1 | 通常 |
| user/passkeys/options | passkey.registration-options | JSON | - | S6 | 通常 |
| user/confirm-password | password.confirm | 画面 | パスワードの確認 | S6 | 通常 |
| user/confirmed-password-status | password.confirmation | JSON | - | - | 外 |
| forgot-password | password.request | 画面 | パスワードリセット | S1 | 通常 |
| reset-password/{token} | password.reset | 画面 | パスワードリセット | S1 | 通常 |
| pricing | pricing | 画面 | - | S5 | 通常 |
| organizations/{organization}/projects/{project}/categories | projects.categories.index | 画面 | カテゴリ管理 | S4 S7 | 通常 |
| organizations/{organization}/projects/create | projects.create | 画面 | プロジェクトの作成 | S4 | 通常 |
| organizations/{organization}/projects/{project}/edit | projects.edit | 画面 | プロジェクトの編集 | S4 S7 | 通常 |
| organizations/{organization}/projects | projects.index | 画面 | プロジェクト | S4 | 通常 |
| organizations/{organization}/projects/{project}/manuals/create | projects.manuals.create | 画面 | 動画マニュアルの作成 | S3 | 通常 |
| organizations/{organization}/projects/{project}/manuals/{manual}/cuts/{cut}/takes | projects.manuals.cuts.takes.index | 画面 | - | S3 | 通常 |
| organizations/{organization}/projects/{project}/manuals/{manual}/download | projects.manuals.download | 画面 | - | S3 S7 | 通常 |
| organizations/{organization}/projects/{project}/manuals/{manual}/edit | projects.manuals.edit | 画面 | - | S3 S7 | 通常 |
| organizations/{organization}/projects/{project}/manuals/{manual}/jobs/{analysisJob} | projects.manuals.jobs.show | 画面 | - | S3 S7 | 通常 |
| organizations/{organization}/projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback | projects.manuals.render-jobs.playback | 画面 | - | S3 S7 | 通常 |
| organizations/{organization}/projects/{project}/manuals/{manual}/render-jobs/{renderJob} | projects.manuals.render-jobs.show | 画面 | - | S3 S7 | 通常 |
| organizations/{organization}/projects/{project}/manuals/{manual} | projects.manuals.show | 画面 | - | S3 S7 | 通常 |
| organizations/{organization}/projects/{project} | projects.show | 画面 | - | S3 S7 | 通常 |
| recent-auth/confirm | recent-auth.confirm | 画面 | 本人確認 | S6 | 通常 |
| recent-auth/status | recent-auth.status | 画面 | - | S6 | 通常 |
| register | register | 画面 | アカウント登録 | S1 | 通常 |
| ai.txt | seo.ai | JSON | - | - | 外 |
| llms.txt | seo.llms | JSON | - | - | 外 |
| robots.txt | seo.robots | JSON | - | - | 外 |
| sitemap.xml | seo.sitemap | JSON | - | - | 外 |
| session/status | session.status | JSON | - | S6 | 通常 |
| settings | settings | 画面 | 設定 | S6 | 通常 |
| settings/security | settings.security | 画面 | セキュリティ設定 | S6 | 通常 |
| auth/{provider}/callback | social.callback | 画面 | - | - | 外 |
| auth/{provider}/redirect/{intent} | social.redirect | 画面 | - | - | 外 |
| two-factor-challenge | two-factor.login | 画面 | 2要素認証 | S1 | 通常 |
| user/two-factor-qr-code | two-factor.qr-code | JSON | - | - | 外 |
| user/two-factor-recovery-codes | two-factor.recovery-codes | JSON | - | - | 外 |
| user/two-factor-secret-key | two-factor.secret-key | JSON | - | - | 外 |
| email/verify | verification.notice | 画面 | メール認証 | S1 | 通常 |
| email/verify/{id}/{hash} | verification.verify | 画面 | - | S1 | 通常 |

## 対象外の理由

- `debug.bfcache-trial` — 履歴復元の実機受入確認のための検証ページであり製品の利用者が到達する画面ではないため分母に載せない
- `debug.bfcache-trial.away` — 履歴復元の実機受入確認で離脱先に使う検証ページであり製品の利用者が到達する画面ではないため分母に載せない
- `debug.login` — 開発環境専用のログイン補助画面であり探索は POST の debug.login-as で前提を組むため分母に載せない
- `password.confirmation` — 再認証が有効かどうかだけを返す状態問い合わせであり画面として開く経路ではないため分母に載せない
- `seo.ai` — 生成 AI のクローラ向けの機械可読 route であり人が操作する画面ではないため分母に載せない
- `seo.llms` — 生成 AI のクローラ向けの機械可読 route であり人が操作する画面ではないため分母に載せない
- `seo.robots` — クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない
- `seo.sitemap` — クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない
- `social.callback` — 外部の識別提供者から戻る受け口であり実際の識別提供者なしには到達できないため分母に載せない
- `social.redirect` — 外部の識別提供者へ出ていく遷移であり隔離した探索環境の外へ出てしまうため分母に載せない
- `two-factor.qr-code` — 第二要素の秘密を図として返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない
- `two-factor.recovery-codes` — 復旧コードを返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない
- `two-factor.secret-key` — 第二要素の秘密そのものを返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない

<!--
  screens.md の末尾へそのまま連結される散文。人が書く (生成器は中身を読まない)。
  **表を書かないこと** — notes-operations.md と同じ規則である。あちらは
  coverage/correlate.py が操作行として拾ってしまうのが直接の理由で、こちらは
  連結先ごとに規則が変わる方が事故のもとになるため同じ規則を課している。
  段 2 が表の混入を drift として拒否する。
-->

## 画面に関する既知の仕様 (散文)

**非 Inertia の GET (画面ではないが分母に載せているもの)**:
`capture.csrf-cookie` (撮影 PWA の CSRF cookie 発行) と `session.status`
(bfcache guard `resources/js/lib/bfcache-guard.ts` が pageshow 直後に叩く
セッション有効性プローブ。auth グループの**外**にあり guest でも 200 +
`authenticated: false`) は Inertia ページを返さないが、ブラウザ挙動の契約に
直結するためインベントリに残す (S3 / S6 で観測する)。
パスキーの `passkey.*-options` 3 本も同じ扱い (次節)。

## パスキー options endpoint の扱い (要検出)

`passkey.*-options` の 3 本は**画面ではなく WebAuthn の challenge を返す JSON GET**
(`capture.csrf-cookie` / `session.status` と同じ扱いで表に載せている)。
bug-hunt はこれらを**単独で開くのではなく**、S1/S6 のパスキー操作を UI から実走した
副作用として通過させる。加えて逸脱アイデアとして直叩きを行う:

- `passkey.registration-options` / `passkey.confirm-options` は `RequireRecentAuth` /
  auth の配下。**未ログイン・再認証切れで直叩きしたときに 401/302 で止まり、
  challenge が漏れない**こと。
- `passkey.login-options` は guest 配下。**メールアドレスを列挙できる応答差
  (存在するユーザーと存在しないユーザーで応答が変わる)** が出ないこと (存在オラクル)。
- 3 本とも `throttle:passkeys` 配下。連打時の 429 が**画面上で説明される**こと
  (無反応で詰まないこと。H4)。

## 課金ゲート着地 (P4 ゲート反転) の画面遷移

> 未契約組織は業務 route group に入れない (`require-active-subscription`)。遮断時の着地は
> **`manageBilling` 保持者 → `onboarding.checkout` / 非保持者 → `onboarding.billing-required`**
> (正本: `docs/billing-gate-inversion-runbook.md`、運用契約: `docs/architecture.md`
> §サブスク契約 Checkout とオンボーディング着地)。

- `onboarding.checkout` は**離脱ガード付き**: 契約済み (有効 sub / free personal) は
  `manageBilling` 保持者 → `billing.index` / 非保持メンバー → `dashboard` へ寄せる
  (非保持メンバーに操作できない請求画面を見せず業務入口へ着地させる。Q-2-01)。
  未契約で `manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
- `onboarding.billing-required` も同様に、利用可なら `dashboard`、`manageBilling` 保持者なら
  `onboarding.checkout` へ逃がす。**どちらの画面も「行き先のない詰み」を作らないこと**が契約で、
  ここでループ・403・空画面が出たら finding (H4/H10)。
- `?plan=` は org スコープ session へ積んで canonical URL へ 303 する (query が残らない)。
  リロードしても選択が消えない (peek) こと。

## ナビゲーション/レイアウト規約 (T069 左サイドバー、参照アプリ aigenba 準拠)

> ログイン後の全画面は `templates/AppLayout.svelte` の**左サイドバー型シェル**を共有する
> (設計正本: `devnotes/20260716-1757-login-sidebar-nav/`)。bug-hunt はこの構造規約への
> 準拠を横断ヒューリスティクス H11/H13 とあわせて全認証画面で検査する。

**左サイドバー nav 項目 (desktop 固定 / mobile ドロワー) — ここに出てよいもの:**
- ダッシュボード `/dashboard`(常時)、プロジェクト `/projects`(組織あり)、
  メンバー `/manage/users`(`canManageMembers`)、API キー `/organizations/{slug}/api-keys`(`canManageApiKeys`)、
  請求 `/billing`(組織あり)

**下部ユーザー/組織ポップアップ (SidebarUserMenu) — ここに出るべきもの (左 nav に出してはいけない):**
- **個人設定 `/settings`**、組織設定 `/organizations/{slug}/settings`、CLI/MCP セットアップ、
  法務(利用規約/プライバシー/特商法)、ログアウト、組織切替
- **規約 (要検出)**: 「個人設定 `/settings`」は**下部ポップアップ専用**。左サイドバー nav 項目としては
  出さない(T069 で設定はポップアップへ移動した)。左 nav に「設定」が重複掲載されていれば finding
  (H10 相当: 直前設計との矛盾 / 二重掲載)。
- 通知はベル(`notification-bell` / mobile `notification-bell-mobile`)単一導線。左 nav 項目にしない。

**ページ幅/レイアウト準拠 (要検出、H11/H13):**
- 各ページ本文はサイドバーのオフセット(desktop 256/64px、mobile 0)配下の `<main>` コンテナ内に収まり、
  **横スクロール・要素はみ出し・レイアウト幅非準拠が無い**こと。旧レイアウトの `max-w-6xl` 中央寄せを外したため、
  独自に幅を仮定していたページ(テーブル/ワイド要素)が新シェル幅に非準拠になっていないかを desktop/mobile で確認する。
- desktop(≥1024)/tablet(768)/mobile(375) で本文が破綻せず、サイドバー折りたたみ(64px)時も本文幅が追従すること。
