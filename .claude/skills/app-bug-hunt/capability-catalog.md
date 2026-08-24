# Capability Catalog (機能一覧・bug-hunt 正本) — AI-CUE

`ledger/findings.schema.json` の必須フィールド `capability_tag` と、stories/ カードが消化する
機能単位を指す **capability_id の語彙正本**。

> **本表は生成物ではない** (人が書く。テンプレート正典との差は `docs/template-divergence.md` D20)。
> ただし `capability_id 索引` の表については、**代表機構列の route 名が実在すること**と
> **id が重複しないこと**を `scripts/bug-hunt-inventory-check.sh` の段 4 が検査する
> (`*` で終わる記法は前方一致で 1 件以上。丸括弧の説明とパスは route 名候補にしない)。
> **網羅性は検査しない** (本表は overlay であり MECE を主張しないため)。

- これは「機構 (route / job / CLI) を **user-value で grouping した overlay**」であり **MECE ではない**
  (完全性を主張しない)。分母の正本は `screens.md` (画面) と `operations.md` (書き込み操作) の 2 つで、
  本表はその上に「利用者にとって何が達成できるか」を重ねたもの。
- `coverage/correlate.py` は route 名を持たない finding を `capability_tag` 経由で機構群へ
  ブロードキャストする (`via_capability`)。**id の粒度がそのまま相関の粒度**になる。

## 責務境界 (先に決める。id はこの境界に従って割り当てる)

AI-CUE の中核は **SOP → シナリオ → 撮影 → レンダ** の 4 段パイプライン (North Star: AGENTS.md §使命)。
capability_id を先に切ると「どの段の機能か」が run ごとにブレるため、**まず段の責務境界を固定する**。

| 段 | 責務 (この段が達成すること) | 主な書き込み対象 | 段の終端 (次段へ渡せる状態) |
|---|---|---|---|
| **SOP** (手順書取り込み) | 現場の作業手順書 (PDF/Excel) と最小メタ (タイトル / カテゴリ) を受け取り、AI 解析にかけられる器を用意する | `video_manuals` (status=draft) / `source_documents` | 解析可能 (手順書 ≥1 の draft) |
| **SCEN** (シナリオ設計) | 手順書を AI が分解し Cut (手順=step / 急所=point) ツリーを生成、人が編集して**撮影可能**にする | `cuts` / `video_manuals.scenario_version` / `status` (共有ロック規約) | status=ready (撮影に渡せる) |
| **CAP** (ナビ撮影 / PWA) | シナリオに従って cut ごとにテイクを撮影・アップロード・比較し、**cut ごとに 1 本採用する** | `takes` / `take_upload_reservations` / `cuts.adopted_take_id` | 必要な cut に採用テイクが揃う |
| **REN** (合成・配信) | 採用テイクとシナリオから ffmpeg で動画を合成し、完成物を再生・DL できる形で配信する | `render_jobs` / `video_manuals.status` (published) / 成果物 storage | published + 完成 mp4 の再生・DL 成立 |

> 実装側の不変条件は `docs/architecture.md` §シナリオ整合の共有不変条件 / §撮影 PWA、
> 思想の正本は `doc/03_AI解析とシナリオ生成.md` / `doc/06_撮影シナリオの設計思想.md`。

### 境界が曖昧になる機構の割当規則 (判断を run ごとにやり直さない)

| 機構 | 割当 | 根拠 |
|---|---|---|
| マニュアル複製 (`projects.manuals.duplicate`) | **SCEN-05** | cuts を作る = シナリオの**第 2 の起点**。SOP 段 (解析入力の用意) を経由しない |
| テイク採用 / テイク削除 (`capture.takes.adopt` / `destroy`) | **CAP-04** | 書く列は `cuts.adopted_take_id` だが、決めているのは「どのテイクを使うか」= 撮影の成果。シナリオ本文の意味は変えない |
| 撮影画面の字幕オーバーレイ | **CAP-02** | 撮影ガイド (焼き込みではない)。字幕**文言**の編集は SCEN-03 |
| チケット消費 (analyze 1 枚 / render N 枚) | 操作は **SCEN-01** / **REN-02**、会計不変条件は **BILL-06** | 「消費する操作の UX」と「残高の正しさ (reserve→commit/release)」は別の失敗モード。preview は非消費 = REN-01 |
| 容量 Quota (`max_storage_bytes`) | 予約時の拒否は **CAP-02**、残量の可視化は **QUO-01** | 予約判定はアップロードに内在、使用率表示は横断機能 (Dashboard) |
| 署名 URL | 発行した段に属す: upload=**CAP-02** / テイク再生=**CAP-03** / 完成動画 DL・再生=**REN-04** | 署名の失効・差し替え耐性は発行元の責務 |
| 編集画面 → 撮影画面の文脈リンク | **CAP-01** | 「撮影に入れるか」は撮影段の入口 (到達性) の問題 |
| PWA のセッション基盤 (`capture.csrf-cookie` / 復帰時の再表示) | **CAP-06** | 同一オリジン・セッション認証の PWA 固有面。個々の撮影操作とは別の失敗モード |

**段間の handoff は独立した観察点**である。各段の終端条件 (上表) が
**UI から読み取れず「次に何をすべきか」が分からない**なら、それは前段側の capability の finding として扱う
(例: ready なのに撮影導線が見えない → CAP-01 ではなく SCEN-03/SOP-05 側の提示不足)。

## 運用ルール

- 新機構が出たら (`scripts/bug-hunt-inventory-check.sh` のドリフト検知 / `php artisan route:list` の差分)、
  **既存 id へ紐付け / 新 id 追加 / dead-code 判定**のいずれかに落とす (LLM が案を出し、人が境界を確認する)。
- tag できない finding は `capability_tag=unknown`、機構はあるが未割当なら `unmapped`。**隠さない**
  (この 2 語は本表に**載せない**。finding 側の値としてのみ使い、triage で実 id へ解決する)。
- `unknown` が 2 run 連続で 20% を超えたら、探索ではなく**本表の整備**を優先する (add-back トリガ)。
- 1 finding に複数 capability が絡む場合は**主たる失敗が起きた段**の id を選ぶ (複数 tag は付けない)。
- フロント専用の機能 (クライアント状態・UI フィルタ) は、探索で実際に触れたものだけ追加する。

## capability_id 索引

### パイプライン (中核。S3 / S7 が主に消化する)

| id | 機能 (actor→outcome) | 代表機構 (route name) |
|---|---|---|
| SOP-01 | 編集者→動画マニュアル一覧の把握 (カテゴリ/状態/検索の絞り込み・並べ替え・自作フィルタ) | `projects.show` |
| SOP-02 | 編集者→マニュアル新規作成 (タイトル・カテゴリ + 手順書アップロード) | `projects.manuals.create` / `projects.manuals.store` |
| SOP-03 | 編集者→手順書の追加アップロード (抽出不可・短文の理由提示) | `projects.manuals.source-documents.store` |
| SOP-04 | 編集者→マニュアルのメタ更新・削除 | `projects.manuals.update` / `projects.manuals.destroy` |
| SOP-05 | 編集者→マニュアル詳細で現在地 (status) と次操作を把握 | `projects.manuals.show` |
| SCEN-01 | 編集者→AI 解析のトリガー (チケット予約 → status=analyzing) | `projects.manuals.analyze` |
| SCEN-02 | 編集者→解析ジョブの進捗追跡と失敗時の draft 復帰・理由提示 | `projects.manuals.jobs.show` |
| SCEN-03 | 編集者→シナリオ (Cut ツリー・本文・字幕) の編集と保存 (楽観ロック / 409 差分再取得) | `projects.manuals.edit` / `projects.manuals.scenario.update` |
| SCEN-04 | 編集者→編集中の Undo / Redo (保存前のローカル状態のみ) | (クライアント状態。route なし) |
| SCEN-05 | 編集者→既存シナリオを雛形に複製 (別名保存。takes は空・draft) | `projects.manuals.duplicate` |
| CAP-01 | 撮影者→撮影対象の一覧・入室 (進捗バッジ・自作フィルタ・編集画面からの文脈リンク) | `capture.home` / `capture.manuals.index` / `capture.manuals.show` |
| CAP-02 | 撮影者→テイクの撮影とアップロード (容量 Quota 予約・カメラ不可時のファイル選択フォールバック) | `capture.takes.upload-url` / `capture.takes.store` |
| CAP-03 | 撮影者→テイクの確認と整理 (インライン再生・字幕トグル・並べ替え・コメント) | `capture.takes.playback` / `capture.takes.update` |
| CAP-04 | 撮影者→テイクの採用・削除 (cut ごとに 1 本を確定する) | `capture.takes.adopt` / `capture.takes.destroy` |
| CAP-05 | 撮影者→採用済みテイクの端末ダウンロードと ACK | `capture.takes.downloaded` |
| CAP-06 | 撮影者→PWA セッション基盤 (同一オリジン・セッション認証、離脱/復帰時の表示と再認証) | `capture.csrf-cookie` |
| REN-01 | 編集者→プレビュー生成 (チケット非消費) で仕上がりを確認 | `projects.manuals.preview` |
| REN-02 | 編集者→本レンダ実行 (チケット N 消費 → rendering → published) | `projects.manuals.render` |
| REN-03 | 編集者→レンダジョブの進捗追跡と失敗理由の帰属提示 | `projects.manuals.render-jobs.show` |
| REN-04 | 編集者/撮影者→完成動画の再生・ダウンロード (署名 URL) | `projects.manuals.render-jobs.playback` / `projects.manuals.download` |

### 支援 (パイプライン外。S1/S2/S4/S5/S6 が主に消化する)

| id | 機能 (actor→outcome) | 代表機構 (route name) |
|---|---|---|
| PUB-01 | guest→公開面の閲覧と CTA 到達 (トップ / 料金 / 法務) | `home` / `pricing` / `legal.*` |
| PUB-02 | guest→問い合わせ送信 | `contact` / `contact.store` / `contact.thanks` |
| AUTH-01 | guest→新規登録 | `register` / `register.store` |
| AUTH-02 | guest→メール認証で verified 化 | `verification.notice` / `verification.send` / `verification.verify` |
| AUTH-03 | user→ログイン / ログアウト (2FA チャレンジ経由を含む) | `login.store` / `two-factor.login.store` / `logout` |
| AUTH-04 | guest→パスワードリセット完走 | `password.request` / `password.email` / `password.update` |
| AUTH-05 | guest→ソーシャルログイン / 連携 | `social.redirect` / `social.callback` |
| ORG-01 | user→組織作成 | `organizations.create` / `organizations.store` |
| ORG-02 | owner/admin→組織情報の閲覧・更新 | `organizations.settings` / `organizations.update` |
| ORG-03 | user→組織の選択 (組織文脈を持たない入口からの分岐と、選んだ組織のスコープ整合) | `app.entry` / `capture.entry` |
| ORG-04 | owner→オーナー移譲 | `organizations.transfer-ownership` |
| ORG-05 | owner→組織の 2FA 必須トグル | `organizations.two-factor-requirement.update` |
| ORG-06 | owner/admin→識別名の変更 (旧 URL の失効と回数上限) | `organizations.slug.update` |
| MEM-01 | owner/admin→メール招待の送信 | `organizations.invitations.store` |
| MEM-02 | owner/admin→未受諾招待の取消 | `organizations.invitations.revoke` |
| MEM-03 | guest/user→招待受諾の完走 (未ログイン時の登録合流・所属組織の確定) | `invitations.accept` / `invitations.accept.store` |
| MEM-04 | owner/admin→ロール変更 (編集者 / 撮影者) | `organizations.members.update` |
| MEM-05 | owner/admin→メンバー除名 | `organizations.members.destroy` |
| MEM-06 | owner/admin→メンバーの 2FA 解除 | `organizations.members.two-factor.reset` |
| MEM-07 | owner/admin→メンバー一覧の閲覧・管理導線 | `manage.users.index` |
| PROJ-01 | owner/admin→プロジェクト CRUD | `projects.index` / `projects.store` / `projects.update` / `projects.destroy` |
| PROJ-02 | owner/admin→プロジェクトメンバーの追加・除外 | `projects.members.store` / `projects.members.destroy` |
| PROJ-03 | owner/admin→カテゴリの CRUD と並べ替え (一覧の並びに反映) | `projects.categories.*` |
| PROJ-04 | owner/admin→サンプルリソース Item の CRUD (テンプレート見本。顧客価値ではない) | `projects.items.*` |
| BILL-01 | guest/user→料金表の閲覧 (表示と実課金の一致) | `pricing` |
| BILL-02 | owner→プラン申込 checkout (二重送信で二重課金しない) | `billing.checkout` |
| BILL-03 | owner→カスタマーポータルへの遷移 | `billing.portal` |
| BILL-04 | owner→チケットのスポット購入 (枚数入力 → 合計再計算) | `billing.tickets.show` / `billing.tickets.checkout` |
| BILL-05 | owner→プラン・チケット残高の閲覧 | `billing.index` |
| BILL-06 | (会計不変条件) チケット reserve→commit/release の 2 フェーズ整合 ※消費操作自体は SCEN-01 / REN-02 | (機構横断) |
| QUO-01 | user→容量 Quota の使用率把握と超過時の明示 ※予約時の拒否は CAP-02 | `dashboard` |
| SEC-01 | user→プロフィール更新 (メール変更は step-up 要求) | `user-profile-information.update` |
| SEC-02 | user→パスワード変更 | `user-password.update` |
| SEC-03 | user→2FA の有効化・確認 | `two-factor.enable` / `two-factor.confirm` |
| SEC-04 | user→2FA の無効化 (必須組織では拒否) | `two-factor.disable` |
| SEC-05 | user→リカバリコード再生成 | `two-factor.regenerate-recovery-codes` |
| SEC-06 | user→機微操作前の再認証 (confirm-password / recent-auth) | `password.confirm.store` / `recent-auth.confirm` / `recent-auth.password` |
| SEC-07 | user→アカウント削除 | `settings.account.destroy` |
| NOTI-01 | user→通知の閲覧・既読化・開封遷移 | `notifications.index` / `notifications.read` / `notifications.read-all` / `notifications.open` |
| AK-01 | owner/admin→API キーの発行・失効 | `organizations.api-keys.store` / `organizations.api-keys.revoke` |
| AK-02 | owner/admin→OAuth セッションの失効 | `organizations.api-keys.sessions.index` / `organizations.api-keys.sessions.revoke` |
| AK-03 | owner/admin→CLI / MCP セットアップ手順の取得 | `organizations.onboarding.cli` / `organizations.onboarding.mcp` |
| AK-04 | automation→REST API v1 / MCP 経由の操作 ※browser story 未整備 (現状は finding 側で `unmapped` になり得る) | `routes/api.php` / `routes/ai.php` |
| PLAT-01 | platform→管理パネル (Filament)。顧客 UX の対象外 | (admin panel) |
| PLAT-02 | dev→debug ログイン (非 production 専用。story の前提構築に使う) | `debug.login` / `debug.login-as` |

> **本表に載せないもの**: `unknown` / `unmapped` (finding 側の値)、テスト専用機構
> (fake storage 配信等)、SEO / robots 等の非対話面 (`screens.md` の OUT_OF_SCOPE で除外済み)。
