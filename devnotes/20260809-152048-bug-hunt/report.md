# bug-hunt 統合レポート (run 20260809-152048)

- 実行日時: 2026-08-09 15:20:48 JST 〜 16:50 JST (JST)
- モード: **フルサイズ** = `--all --coverage --parallel=4 --deviate --real-llm`
- 走行形態: worktree (`.claude/worktrees/tasks/bughunt-20260809` / ブランチ `todo/bughunt-20260809`)
- shard 割当 (`stories_for_shard` 固定マップ, N=4):
  | shard | URL | DB | stories |
  |---|---|---|---|
  | 1 | http://127.0.0.1:8011 | bug_hunt_1 | S3 (中核ジャーニー) → S7 (認可境界) |
  | 2 | http://127.0.0.1:8012 | bug_hunt_2 | S1 (登録ファネル) → S2 (招待) |
  | 3 | http://127.0.0.1:8013 | bug_hunt_3 | S4 (組織/PJ/カテゴリ/ユーザー) → S5 (課金) |
  | 4 | http://127.0.0.1:8014 | bug_hunt_4 | S6 (2FA/プロフィール/セッション) |
- `verify-run --run-id 20260809-152048` → **exit=0 (全 shard 完遂、欠落なし)**
- Phase 1 インベントリ鮮度確認: `bug-hunt-inventory-check.sh` → **drift なし** (screens.md / operations.md は `route:list` と整合)

---

## 1. サマリ

### findings 件数 (4 shard 統合、route×症状で dedupe 済み — 重複なし)

| severity | 件数 | finding |
|---|---|---|
| Critical | **0** | — |
| High | **0** | — |
| Medium | **1** | F-1-03 (H13 撮影 PWA モバイルの自動スクロール欠如) |
| Low | **2** | F-1-02 (H14 video に aria-label なし) / F-2-01 (H14 プラン選択状態が ARIA に出ない) |
| 要確認 | **4** | F-1-01 / F-3-01 / F-4-01 (仕様質問) + F-3-02 (検証条件不足) |

- **H7 未検証: 0 件** (全 shard)。書き込み操作はすべて feedback probe の確定判定 (陽性) または
  persistent な UI 状態変化で結果フィードバックを確認した。
- **環境ハザード (EH): 0 件**。走行中に serve 停止・DB 断・500 連発は発生しなかった。
- **許可外の外部ドメインへの実リクエスト: 検知なし** (LLM = 実 Anthropic API のみ)。

### adjudication registry の consult 結果 (親のみ実施)

`ledger/validate_findings.py --adjudications ledger/adjudications.jsonl --annotate` に統合 findings 7 件を通した結果:

| 分類 | 件数 | 内訳 |
|---|---|---|
| (1) 未知 / actionable | **7** | 全件 `adjudication_status=none` |
| (2) known-accepted (既知の受容済み) | 0 | — |
| (3) ambiguous (要人手) | 0 | — |

過去 run の裁定にヒットしたものは無く、全件が新規である。

---

## 2. カバレッジ (screens.md / operations.md に対する 4 shard の**和集合**)

### 画面カバレッジ: **54 / 55**

| story | 走行 / 分母 | 担当 shard |
|---|---|---|
| S1 | 16 / 16 | 2 |
| S2 | 2 / 2 | 2 |
| S3 | 12 / 13 | 1 |
| S4 | 11 / 11 | 3 |
| S5 | 4 / 4 | 3 |
| S6 | 9 / 9 | 4 |
| S7 | (新規消化なし = S3/S4 の画面を B 視点で再走査する設計) | 1 |

**未走行 1 件**:
- `capture.csrf-cookie` (S3) — SPA 初期化で内部的に叩かれるのみで、単独で開ける可視 UI が無い。
  走行中にエラーは発生していない。**screens.md 側で「画面に付随する JSON GET」と注記済みの行**であり、
  分母から外すか「間接消化」を別カウントにするかの整理を推奨 (§6 参照)。

### 操作カバレッジ: **70 / 76** (`coverage/correlate.py` 突合と一致)

| story | 実行 / 分母 | 担当 shard |
|---|---|---|
| S1 | 9 / 11 | 2 |
| S2 | 6 / 7 | 2 |
| S3 | 15 / 15 | 1 |
| S4 | 18 / 20 | 3 |
| S5 | 7 / 7 | 3 |
| S6 | 15 / 16 | 4 |

**未実行 6 件 (すべて理由付き。無言 skip は 1 件のみ = §6 で是正提案)**:

| operation | story | 理由 | 分類 |
|---|---|---|---|
| `debug.login-as` | S1 | `routes/web.php` の `isLocal() \|\| runningUnitTests()` ガードにより bughunt.local では route 自体が未登録 (404)。**fail-safe 設計であり不具合ではない** | 環境上到達不能 |
| `passkey.login` (POST) | S1 | playwright-cli / headless Chromium に WebAuthn 仮想認証器 (CDP virtual authenticator) を操作する手段がなく credential 提出を再現できない。`passkey.login-options` (GET) 側は存在オラクル・throttle とも検証済み | ツール制約 |
| `passkey.store` | S6 | 同上 (プラットフォーム認証器なし。UI にも「この端末ではパスキーを作成できません」と表示される) | ツール制約 |
| `projects.members.store` | S4 | 検証中にロール変更テストの副作用で唯一の member ロールを admin に変えてしまい、割当可能な非管理者が残らなかった | 走行手順の副作用 |
| `organizations.api-keys.sessions.revoke` | S4 | OAuth 接続セッションが本走行では発生せず (CLI/MCP 実接続はスコープ外)、一覧が常に空で対象を作れない | 対象不在 |
| `invitations.accept-in-app` | S2 | **shard-2 レポートに言及がない = 実質的な無言 skip**。招待受諾はゲスト経路 (`invitations.accept.store`) のみ走行し、ログイン済みユーザーがアプリ内で受諾する経路が未検証 | **要フォロー** |

### 未実行 ∧ finding 多 (★cross、correlate.py の最優先枠)

`organizations.api-keys.sessions.revoke` / `projects.members.store` の 2 件が ★cross として挙がっているが、
これは **capability_tag (ORG-04 / MEM-04) 経由の fan-out による見かけ上の紐付け**であり、
当該 route を直接指す finding は存在しない。同様に「finding hotspot 35 件」も capability 経由の
広がりであって route ごとの実 finding 数ではない。**この run では hotspot 欄を優先度判断に使わないこと。**

### コード到達カバレッジ (code-reach / C3)

**収集できていない。** `--coverage` は指定したが本環境に **pcov 拡張が無い**ため、provision が
`warning: --coverage 指定だが pcov 拡張が無い — BUGHUNT_PCOV=1 のみ渡して続行 (middleware は no-op、実 coverage は出ない)`
を出して続行した。`coverage/merge_pcov.py` の入力となる shard JSONL は 1 件も生成されていない。
**この run に「コード到達カバレッジ n%」は存在しない**ので、そう読み替えないこと。

---

## 3. findings (severity 降順)

### F-1-03: 撮影 PWA (モバイル幅) でカット選択時に撮影パネルへ自動スクロールしない
- **severity: Medium (H13 レスポンシブ)** / story: S3-7 / shard: 1
- 再現手順:
  1. `owner-standard@example.com` / `password123` でログイン、`playwright-cli resize 375 667`
  2. `capture.manuals.show` (`/app/projects/1/manuals/1`) を開く。シナリオ一覧 (14+ 手順) が画面上部に出る
  3. 任意のカット行 (例「手順1」) をタップ
  4. 撮影パネル (ナレーション / 字幕 / 録画ボタン / テイク一覧) は一覧の**下**に追加されるが `window.scrollY` は 0 のまま
- 期待: カット選択時に撮影パネルが viewport 内に入る (`scrollIntoView`)、またはモバイルでは一覧⇔撮影パネルをタブ/ドロワー切替にする
- 実際: 毎回 14 件以上のシナリオ一覧を手動スクロールして撮影パネルまで降りる必要がある
- **阻害されたユーザージョブ**: 現場でスマホ片手に複数カットを連続して素早く撮影する — North Star の
  「ナビ撮影」中核体験で、カットを変えるたびに発生する
- 改善アクション候補: (a) カット選択時に撮影パネルへ `scrollIntoView`、(b) モバイルは一覧⇔パネルのタブ/オーバーレイ切替
- 証跡: `shard-1/screenshots/H13-capture-mobile375.png`, `shard-1/screenshots/H13-capture-panel-mobile375-viewport.png`
- 推定原因: 未調査
- 関連既知情報: なし

### F-1-02: 完成動画 / プレビューの `<video>` にアクセシブルネームが無い
- **severity: Low (H14 a11y)** / story: S3-8,9 / shard: 1
- 再現手順: `owner-standard@example.com` でログイン → プレビュー or 完成動画のある manual を開く →
  `document.querySelector('video').getAttribute('aria-label')` → `null`。snapshot (a11y ツリー) に video が現れない
- 期待: `aria-label="◯◯のプレビュー動画"` 等のアクセシブルネームがある
- 阻害されたユーザージョブ: スクリーンリーダー利用者が「動画が生成された / 再生できる」と認識するジョブ
- 改善アクション候補: video 要素に `aria-label` を付与
- 証跡: `shard-1/screenshots/preview-video-check.png` + 上記 eval

### F-2-01: `/onboarding/checkout?plan=` の事前選択が視覚のみでアクセシビリティツリーに伝わらない
- **severity: Low (H14 a11y)** / story: S1-5 / shard: 2
- 再現手順: 新規登録 → メール認証後 `http://127.0.0.1:8012/onboarding/checkout?plan=starter` を開く →
  Starter カードが青枠でハイライトされるが、snapshot に選択状態が出ない。DOM は `class="... border-primary"` のみで
  `aria-current` / `aria-selected` / `aria-pressed` いずれも無い
- 期待: 事前選択されたプランが支援技術でも「選択中」と判別できる
- 阻害されたユーザージョブ: `/pricing` の「このプランで始める」から誘導されたスクリーンリーダー利用者が、
  意図したプランが選ばれているか確認できないまま契約に進む
- 改善アクション候補: 選択中カードに `aria-current="true"` (または `role="radio" aria-checked`) を付与
- 証跡: `shard-2/screenshots/onboarding-checkout-plan-starter-param.png` +
  DOM eval `{"cls":"... border-primary","aria":null}`

---

## 4. 要確認 (仕様確認の質問リスト — severity は付けない)

### Q1 (F-1-01): プレビュー生成は採用テイク 0 件でも成功してよい仕様か
- story: S3-8 (逸脱) / shard: 1
- 観察: 採用テイク 0 件の状態で `POST .../preview` は **201 で成功**し render-job が完了して `<video>` が出る。
  一方、同じ状態で `POST .../render` は **明示エラー**で「採用テイクが未設定のカットがあります: 手順3、手順4…」と一覧表示する
- 問い: プレビューも未採用カットを事前チェックすべきか、それとも「プレビューは仮映像を許容する」設計か。
  現状ユーザーは「プレビューが通ったから撮影は十分」と誤解し、レンダー時に初めて 14 カット未撮影に気づきうる
- 推奨: 仕様が確定しているならプレビュー画面に未採用カット数の警告を出す。意図的なら screens.md / ストーリーに明記
- 証跡: `shard-1/screenshots/F-01-preview-no-takes.png`

### Q2 (F-3-01): オーナー移譲の「パスワードの再確認が必要」表記と実挙動の食い違い
- story: S4-1 (`organizations.transfer-ownership`) / shard: 3
- 観察: UI 文言は「この操作にはパスワードの再確認が必要です。」だが、ログイン直後に実行するとパスワード再入力は
  出ず、確認ダイアログ (Yes/No) のみで即完了した
- 判断: `app/Security/RecentAuthState.php` の recent-auth grace window (既定 900 秒) 内だったための
  設計通りの挙動である可能性が高く、**バグと断定しない**。ただし「一定時間経過後は本当に再確認を要求するか」は未検証
  (再現には長時間セッション維持か `recent_auth_at` 操作が必要で、wrapper 経由の DB 操作しか許されない走行では検証不能)
- 推奨: 文言を「直近に認証していない場合はパスワードの再確認が必要です」等に条件付きへ変えるか、仕様意図を確定させる

### Q3 (F-4-01): `config/fortify.php` のコメントと `FortifyServiceProvider` の実装の食い違い (doc drift 疑い)
- story: S6 / shard: 4
- 観察: `config/fortify.php` L162-168 のコメントは「残る 2FA 管理エンドポイント (enable/confirm/qr-code/secret-key) は
  step-up なしで到達可能」と書くが、`app/Providers/FortifyServiceProvider.php` L83-90 の `RECENT_AUTH_ROUTE_NAMES` は
  `two-factor.enable` / `two-factor.qr-code` / `two-factor.secret-key` に `recent-auth` を付与しており
  (`two-factor.confirm` のみ未付与)、コメントと実装が食い違って見える
- 判断: `two-factor.confirm` が `enable` 直後の同一フローとして freshness を共有する設計なら妥当。
  コメントが古い可能性がある。**AGENTS.md ドメイン規約 8 (2FA 面の step-up) の non-exemptible 6 本**に
  直結する箇所なので、コメント側を実装に合わせるべきか要確認
- 補足: recent-auth の実失効を伴うライブ再現は未実施 (失効イベントは passkey 登録/削除のみで、
  本環境は WebAuthn 認証器が使えない。900 秒実待機は shard 予算に対し過大)

### Q4 (F-3-02): T042 (タブレット幅での氏名 truncate) が検証条件不足で再現不能
- story: S4-4 / shard: 3
- 観察: tablet 768×1024 の `/manage/users` でシード氏名 ("Standard Owner" 等) が短く、truncate 条件を作れなかった。
  目視でのレイアウト崩れは無し
- 判断: **バグではなく検証条件不足**。長い氏名のユーザーを作る手段が招待フロー (メール受諾) 経由しかなく shard 時間内では未実施
- 推奨: `ManualTestSeeder` に長い氏名 (日本語フルネーム + 役職等) のユーザーを 1 名足すと、以後の run で T042 が機械的に踏める

### Q5 (shard-2 記録、finding 台帳外): パスキーログイン失敗時のエラー文言が状況で異なる
- 観察: 1 回目 (WebAuthn セレモニー自体が headless で失敗) は「パスキーの処理に失敗しました。時間をおいて再度お試しください。」、
  10 回目 (429) は「パスキーの認証を開始できませんでした。」。どちらも alert 表示で無反応・詰みではない
- 判断: 意図的な error taxonomy の可能性があり severity 未付与。429 時にこそ「時間をおいて」の案内が要るのでは、という UX 観点のみ

---

## 5. 「破壊を試みたが設計通り守られた」ことの記録 (finding ではない = 回帰の資産)

この run は **Critical / High が 0 件**だった。それは「探索が浅かった」からではなく、
以下を実機で能動的に破壊しにいった結果すべて fail-safe だったためである。次回 run の重複を避けるため記録する。

### S7 認可境界 (shard-1、S3 の状態を reseed せず利用)
- 組織 B から組織 A の URL 直叩き (`projects.show` / `manuals.show` / `manuals.edit` / `jobs.show` /
  `render-jobs.show` / `capture.manuals.show`) → **全て 404** (403 でも Blade エラーでもない)
- 組織 B から A の書き込み (`manuals.update` / `destroy` / `scenario.update` / `analyze` / `render` /
  `preview` / `source-documents.store`)、A の category (`update` / `destroy` / `reorder`)、
  撮影面 (`takes.adopt` / `destroy` / `upload-url` / `downloaded`) → **全て 404**
- **cross-cut 採用**: cut3 の adopt に cut2 の take id を渡す → 404 (`cut->takes()` 経由解決が機能)
- **存在オラクルなし**: B 自身の reorder に A の実在 category id を混ぜても、実在しない id (9999) と
  **メッセージ・構造とも完全に同一の 422**
- **ロール分離**: 撮影者 (`project_member`) で編集者専用操作 → **403** (404 ではなく権限不足として区別)。
  UI 側も編集者専用要素が非表示
- **tenant/protected キー注入**: `project_id` / `created_by` / `category_id` の直送 → **422**
  (`ProhibitsProtectedKeys`)。`category` 別名は許容という設計通りの差

### S6 セキュリティ (shard-4)
- **2FA (TOTP) を実機で完走**: QR のセットアップキーから RFC6238 を自前計算 → 有効化 → ログアウト →
  再ログインで challenge 通過。**ロックアウト (H2) は作れなかった**
- リカバリコードの single-use を実証 (再利用は正しく拒否)
- **パスワード変更が他セッションを無効化することを cookie 操作で実証** (明示ログアウトを経由しない旧セッションが無効化される)
- `passkey.destroy` の direct fetch IDOR: 999999 / 非数値 / bigint 超過 / 負数 / 1 の全パターンで **一貫して 404** (存在オラクルなし)
- `settings.password.store` の direct POST で current_password 検証迂回 → **422 で拒否**
- アカウント削除: 非オーナーは完走 → 再ログイン不可を確認。**最後のオーナーは孤児化防止ガード**が働き、
  削除ボタンが「オーナーを移譲する」導線付き警告に置き換わる (詰みなし)
- **組織 2FA 必須化の詰み検証**: オーナー自身が未設定だと必須化自体がブロックされ、
  有効化後はパスワード限定ユーザーが `/settings/security` へ強制誘導され、**その場で 2FA を設定すれば解放される**
  (ログアウト導線も残る = 逃げ場あり)
- bfcache 復元時の秘匿 (`session.status`) を Chromium で実証 (ログアウト後の「戻る」でダッシュボード中身が露出しない)

### S1/S2 (shard-2)
- メール認証リンクの user id 改竄 → **403** (他ユーザーを誤認証させられない)
- パスワードリセットのトークン使い回し → 拒否 (再リクエスト導線あり = 詰みなし)
- `passkey.login-options` の存在オラクル: 実在 / 非実在 / 空文字いずれも 200 + 同一シェイプ
- 招待: T055 (登録フォームへのメール自動入力) / T030 (招待先組織へ直接所属・個人組織を作らず特典二重付与なし) を確認
- 取消済み招待リンク → 「この招待リンクは使用できません」画面 (導線あり)。除名後の旧組織 URL → **404**
- `billing-required`: オーナー連絡先 (mailto) + お問い合わせ導線あり、403 や空画面ではない。
  **逆方向の離脱ガードも確認** (manageBilling 保持者の直叩き → `onboarding.checkout`、無限リダイレクトなし)

### S4/S5 (shard-3)
- `billing.plans` の変更不可 / 契約中プランの CTA は **disabled にせず押下時にエラー表示**
  (**AGENTS.md 禁止事項 8 に準拠**していることを全パターンで確認)
- `billing.checkout` 二重送信 → 「既に進行中の Checkout があります」で冪等
- `billing.contact.update` / `billing.auto-recharge.update` を manageBilling 無し member で **直 PATCH/POST → 403**
  (UI 非表示だけに依存していない)
- T041 / T044 の stale-invalid 即時解消 (エラー後に有効値を選ぶとエラーが消える) を確認
- cross-org の project / settings 直 URL → **一貫して 404**

---

## 6. インベントリ修正提案 (採用分のみ)

各 shard は「screens.md / operations.md と実装の乖離なし」と報告しており、`inventory-check.sh` も drift なし。
**正本の書き換えは行わない。** ただし次回以降の run 品質のため、以下 3 点を提案として記録する
(本 run では反映しない = 実装/正本への変更は app-design → app-implement の管轄)。

1. **`invitations.accept-in-app` (S2) が無言 skip になった**。ストーリーカード S2 の手順に
   「ログイン済みユーザーがアプリ内通知から受諾する経路」を明示ステップとして足すと、次回から機械的に踏める。
   (shard-4 の V-10 で通知経由の招待受諾が観測されているので、S2 と S6 のどちらに寄せるかの整理も要る)
2. **`capture.csrf-cookie` (S3) の分母の扱い**。screens.md は既に「画面に付随する JSON GET」と注記しているが、
   カバレッジ分母には入ったままで毎回 1 件の未走行が出る。**間接消化を別カウントにする**か注記を分母側にも効かせたい。
3. **`ManualTestSeeder` に長い氏名のユーザーを 1 名**足すと T042 (タブレット幅の truncate) が再現可能になる (Q4)。

---

## 7. TODO 候補 (Critical/High) — **なし**

本 run で Critical / High の finding は 0 件だった。
`app-design` → `app-todo-add` に渡す粒度で起票を推奨するのは **Medium 1 件**のみ:

### 起票候補: 撮影 PWA モバイルでカット選択時に撮影パネルへ自動スクロールしない (F-1-03, Medium/H13)
- 一行サマリ: モバイル幅の撮影画面でカットをタップしても撮影パネルが viewport に入らず、毎回手動スクロールが要る
- 再現手順: 本レポート §3 F-1-03
- 阻害されたユーザージョブ: 現場でスマホ片手に複数カットを連続撮影する (North Star の「ナビ撮影」中核体験)
- 改善アクション候補: カット選択時に撮影パネルへ `scrollIntoView` / モバイルは一覧⇔パネルのタブ切替
- 関連ファイル: 撮影 PWA の manual 詳細ページ (`capture.manuals.show` に対応する Svelte page) と CameraRecorder 周辺

a11y の Low 2 件 (F-1-02 / F-2-01) は同種 (状態・要素がアクセシビリティツリーに出ない) なので、
**1 本の a11y 改善 TODO にまとめる**のが妥当。

---

## 8. 走行環境に関する所見 (アプリの finding ではない)

この run では、**アプリのバグより先に走行基盤の前提不足に 3 回ぶつかった**。次回の摩擦を減らすため記録する。

1. **`setup-worktree.sh` が `.env.bughunt.local` をコピーしない**。SKILL.md Phase 0a は
   「setup-worktree.sh が `.env.bughunt.local` と Passport 鍵を親からコピーする」と書いているが、
   実際にコピーされるのは `storage/oauth-*.key` だけで `.env.bughunt.local` は来ない (手動 cp で対処)。
   **SKILL.md の記述か setup-worktree.sh の実装のどちらかが誤り**。
2. **`provision-all` 内の `php artisan optimize:clear` が dev DB に依存して落ちる**。
   ambient env のまま実行されるため `CACHE_STORE=database` → dev DB `app` の `cache` テーブルを
   delete しにいき、当該テーブルが無い環境では `SQLSTATE[42P01]` で `set -e` により provision 全体が死ぬ
   (今回は `CACHE_STORE=array` を被せて回避。serve は `env -i` なので shard へは漏れない)。
   **dev DB 防御の観点でも、この 1 行だけが ambient env で dev DB に触るのは設計と不整合**に見える。
3. **PostgreSQL の専用 role `bughunt` が未作成だった**。`.env.bughunt.local.example` が人手の前提設定として
   書いている手順で、`bug-hunt-shard.sh` は作らない。本 run ではユーザーの明示承認を得て
   `CREATE ROLE bughunt LOGIN NOCREATEDB NOSUPERUSER` + `REVOKE CONNECT ON DATABASE app FROM PUBLIC` を
   1 度だけ実行した (dev DB の中身には触れていない)。
4. **pcov 未導入**によりコード到達カバレッジは収集されていない (§2 末尾)。
5. **playwright-cli の既定ブラウザ解決**が本機 (aarch64) で壊れており、3 shard が独立に
   `.playwright/cli.config.json` (`browserName: chromium`) の設置 + `install-browser chromium` で自己解決した。
   毎 run 同じ対処が要るなら、skill 側で 1 回だけ用意する方が安い。
6. **`code-review-graph` が未インストール**だったため、`coverage/correlate.py` を走らせるために
   本 run 内で `uv tool install code-review-graph` → `code-review-graph build` を実施した
   (`.code-review-graph/` は `.gitignore` 済み)。

---

## 9. 成果物

| 内容 | パス |
|---|---|
| 本統合レポート | `devnotes/20260809-152048-bug-hunt/report.md` |
| manifest | `devnotes/20260809-152048-bug-hunt/manifest.json` |
| shard レポート | `devnotes/20260809-152048-bug-hunt/shard-{1,2,3,4}/shard-report.md` |
| findings 台帳 | `devnotes/20260809-152048-bug-hunt/shard-{1,2,3,4}/findings.jsonl` (計 7 件) |
| 証跡 screenshot | `devnotes/20260809-152048-bug-hunt/shard-{1,2,3,4}/screenshots/` (計 22 枚) |
| 操作到達カバレッジ | `devnotes/20260809-152048-bug-hunt/coverage-operation-reach.md` |
| executed 突合入力 | `devnotes/20260809-152048-bug-hunt/executed.json` |

## 10. adjudication 追記

本 run では **`ledger/adjudications.jsonl` への追記は行っていない**。
追記は「誤検知 / 意図的仕様 / won't-fix と**人が確定**したとき」に限られる規律であり、
要確認 4 件はいずれも仕様側の確認待ちで未確定のためである。Q1〜Q4 の仕様が確定した時点で、
確定したものだけを 1 行ずつ append すること (既存行は編集しない)。
