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
