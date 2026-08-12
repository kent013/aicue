# bug-hunt 統合レポート — run-id 20260812-100645

- 実行日時: 2026-08-12 10:06 JST 開始 / 11:0x JST 完了 (JST)
- モード: `--all --coverage --parallel=4 --deviate --real-llm` (worktree `bughunt-20260812` 走行)
- 対象: 隔離 bughunt 環境 shard 1..4 (`:8011`..`:8014` / DB `bug_hunt_1`..`_4`)
- `verify-run`: **exit 0 (全シャード完遂・欠落なし)**
- インベントリ: 走行前 `bug-hunt-inventory-check.sh` で **drift なし** (screens.md / operations.md は route:list と整合)

## 総括

**Critical 0 / High 0 / Medium 3 / Low 1 / 要確認 2 (計 6 件)。**

- **IDOR / 認可漏れは検出されなかった** (S7 の cross-org 検査で GET/write とも一律 404、
  カテゴリ reorder の実在/不在 ID で応答差分なし = 存在オラクル不成立、protected keys 注入は全 422、
  署名 URL 改ざんは 403、ロール境界の 403 も正常)。
- 6 件すべて adjudication registry に**既存の該当なし** (`adjudication_status: none`) = 新規。
- **前回 run (20260811-003230) の High 2 件は回帰していない**ことを実機確認 (下記「回帰確認」)。

### カバレッジ (シャードの和集合)

| shard | ストーリー | 状態 |
|---|---|---|
| 1 | S3 core-journey → S7 authz-boundaries | 両方完走。画面 12/13 (未走行 2 = `render-jobs.playback` 単独 URL / `capture.csrf-cookie` 単独。いずれも他導線で間接確認済み)。操作 S3 14/15 + S7 越境 9/9 + ロール境界 6 + protected keys 3 |
| 2 | S1 guest-registration-funnel / S2 invitation-flow | 画面 **全走行・未走行なし**。操作は `debug.login-as` のみ skip (理由記載あり) |
| 3 | S4 org-project-management → S5 billing | S4 画面 11/11・操作 19/20 / S5 画面 4/4・操作 6/6 (skip は fake gateway 由来の構造的検証不能と 2FA 有効ユーザー不在によるレンダー未到達。いずれも理由記載あり) |
| 4 | S6 security-2fa-profile | 画面 9 件中 7 件走行 (passkey 登録系 2 件は headless に platform authenticator が無く skip)。操作 15 件中、passkey 実登録 3 件を除き実行 |

- **H7 未検証: 0 件** (4 シャードすべてで、全書き込み操作について feedback probe の
  肯定/陰性を確定させている)。
- **H13 (レスポンシブ)** は各シャードが代表画面で mobile 375×667 / tablet 768×1024 を確認済み。

### コード到達カバレッジ (C3 / pcov)

**pcov が有効に働き、前回 run で取れなかった code-reach が今回は取得できた。**

| 指標 | 値 |
|---|---|
| shard | 4 |
| 対象ファイル (`app/`) | 429 |
| 一度も到達しないファイル | 46 |
| uncovered 行 | 3752 |
| 参考 line_pct | **59.9%** |

未到達 46 ファイルの内訳は **Filament 管理画面 (17) / SEO 静的応答 (4) / webhook・provider・binder 系**が中心で、
browser story の対象外 (PLAT-01 等) が大半である。**顧客 UX 面で未到達だったのは
`AcceptInvitationInAppController` (アプリ内招待受諾) と `SessionStatusController`** の 2 本で、
前者は S2 がメール token 経路のみを走ったため、後者は bfcache プローブ用で通常操作に出ないため。
**次回の割り当て見直し候補**として記録する。

> operation-reach (`coverage/correlate.py`) は `.code-review-graph/graph.db` が worktree に
> 存在しないため今回は実行していない (機械突合の未実施を明記する)。

### 環境の制約 (この run で取れなかったもの)

- 決済 / SSO / S3 / captcha は fake。**実 Stripe / 実 IdP へは 1 度も出ていない**。
- LLM のみ実 Anthropic 接続 (既定 real-llm)。
- **`--hold-lock` の常駐が provision 完了時に終了した** (background 起動で stdin が即閉じるため)。
  他 run は走っていないので実害はないが、**「2 run 並走防止」は今回機能していない**。
  次回は foreground 常駐か別方式で保持すること。

---

## findings (severity 降順)

### F-1-02 (Medium) — 完成動画が公開された後も、直下に古いプレビューの「黒背景」警告が残る

- **species**: `broken_flow:video_manual:read:self` / oracle: `H10` (直前の操作結果と矛盾)
- **story/step**: S3-8 (`projects.manuals.show`)
- **症状**: 採用テイク 0 件の時点で 1 度プレビューを生成 → その後 20 カット全てにテイクを採用 →
  完成動画を生成して published になった後も、完成動画のダウンロード導線の**直下に**
  「このプレビューは 20 件のカットに使用できる採用テイクがないため、その区間が黒背景になっています。」
  が残り続ける。
- **阻害されたユーザージョブ**: 完成動画が本当に全カットを反映しているかの確認。
  実際には全カット採用済みで正しく生成されているのに、ユーザーは矛盾した表示を見る。
- **既知情報との関係**: aicue:T148 は `placeholder_cut_count` を「**生成物の説明であり現在状態から
  再計算しない**」と決めており、プレビュー側の値がそのまま残ること自体は設計どおりである。
  本件は**その値の提示のしかた**(完成動画の隣に、生成時点の注記だと分からない形で置く) の問題。
- **改善アクション候補**: プレビューが「いつ時点のものか」を明示する / カット構成・採用状況が変わったら
  「古くなった」旨を出す / 完成動画が published のときは旧プレビュー注記を畳む。
- **証跡**: `shard-1/screenshots/F-02-stale-preview-black-message.png`

### F-1-03 (Medium) — XHR の 404 に Eloquent の内部クラス名が漏れる

- **species**: `error_exposure:take:read:cross_tenant` / oracle: `H4`
- **症状**: 撮影 PWA (`/app/*`) へ `Accept: application/json` で叩くと、404 の body が
  `{"message":"No query results for model [App\\Models\\Take] 1"}` のように**内部クラス名を含む生の英語例外文**になる。
  ブラウザ経路の 404 は日本語の友好的なページで、**同じ 404 でも経路によって露出が非対称**。
- **親セッションでの追試 (実装確認)**: 統合時に実コードで裏を取った。
  `App\Exceptions\ApiExceptionRenderer` は `ModelNotFoundException` / `NotFoundHttpException` を
  固定文言へ collapse するが、**`shouldHandle()` が `$request->is('api/*')` に限定**されており、
  撮影 PWA の `/app/*` は対象外。よって Laravel 既定の JSON 化がそのまま出る。
  **`APP_DEBUG` は bughunt env で未設定 (= false) なので、デバッグ表示に起因する現象ではない**。
- **阻害されたユーザージョブ**: 直接は無い (UI 操作では到達しない)。API/セキュリティ的な硬さの問題。
- **改善アクション候補**: `/app/*` の XHR 経路にも既存の collapse を適用する
  (`ApiExceptionRenderer` の対象範囲を広げるか、同等の renderer を配線する)。
  **既に `api/*` で同じ処理を持っているため、新機構は要らない**。

### F-3-02 (Medium) — 請求先情報フォームの invalid 表示が、値を直しても再送信するまで消えない

- **species**: `validation_gap:billing_contact:update:self` / oracle: 兄弟フォームとの整合
- **症状**: `billing.contact.update` でメールアドレスに不正値 → エラー表示 → 値を正しく直しても
  エラー表示と `[invalid]` が残る (再送信するまで消えない)。
- **既知情報との関係**: 同一アプリ内の T041 / T044 で「入力し直した時点でエラーを消す」パターンが
  既に確立されており、**それと矛盾する**。
- **証跡**: `shard-3/screenshots/F-3-02-billing-contact-stale-invalid.png`

### F-1-01 (Low) — プロジェクト作成フォームでも同じ stale-invalid

- **species**: `validation_gap:project:create:self`
- **症状**: 必須エラー表示後に有効値を入力してもエラー表示が消えない。その状態で送信すると**成功する**
  (= 表示だけが古い)。
- **証跡**: `shard-1/shard-report.md#F-1-01`

> **F-1-01 と F-3-02 は同種 (stale-invalid) で、別シャード・別画面で独立に観測された。**
> 個別の画面修正ではなく、**フォームのバリデーション表示規約そのもの**を見る価値がある
> (どちらも「サーバ 422 で付いた invalid を、入力イベントで再評価していない」形)。
> TODO 化するなら 1 本にまとめるのが妥当。

### F-4-Q1 (要確認) — 退会予約 (凍結) 中の即時削除が 1 度だけ通り、アカウントが消えた

- **species**: `data_integrity:account_deletion:delete:self` / triage: `needs_spec`
- **観測**: `member-personal@example.com` が退会予約 (凍結) 有効のまま `settings.account.destroy` へ
  直 DELETE したところ**実際に削除され**、ログイン不能になった (DB users 11→10)。
- **再現しなかった**: 同一条件を `admin-personal@example.com` で 2 回クリーン再現したところ、
  **いずれも設計どおり 409 でブロック**された。
- **シャードの見立て**: 「ブラウザナビゲーションと同一タブ内 fetch の競合」または手法上のアーティファクト。
  ただし**実データ消失を伴う**ため severity を付けず要確認とした。
- **人手トリアージ推奨**。凍結 allowlist は `settings.account.destroy` を意図的に除外している
  (猶予の迂回口になるため) ので、**もし通る窓が実在するなら猶予期間の設計そのものに穴がある**。
  次アクション候補: 削除経路に「凍結中は 409」を behavioral テストで固定できているか確認し、
  競合 (予約 → 直 DELETE の並行) を明示的に検査する。

### F-3-01 (要確認) — メンバー削除で 403/404 が分かれ、同一組織内の id 存在を弱く推測できる

- **species**: `other:organization_member:delete:same_tenant` / triage: `needs_spec`
- **症状**: 組織メンバー DELETE が、権限不足時に「不在 id = 404 / 実在するが権限外 = 403」と応答が分かれる。
  member ロールでも同一組織内の user id の存在有無を弱く推測できる。
- **バグと断定しない根拠**: 同一組織内の話であり cross-tenant の存在秘匿とは層が違う。
  招待トークン系が採っている「すべて 404 へ collapse」との一貫性をどう考えるかは仕様判断。

---

## 回帰確認 (前回 run 20260811-003230 の High 2 件 + 直前の変更)

| 対象 | 結果 |
|---|---|
| **F-4-01** (2FA 必須組織で退会取消が黙って弾かれる) | **回帰なし**。`ALLOWED_ROUTE_NAMES` に `settings.account.deletion-request.destroy` が入り、取消 DELETE が 303 で `/settings` へ戻り toast「退会の予約を取り消しました。」が出ることを実機確認 (aicue:T149) |
| **F-1-01/F-2-01/F-2-02** (前回の preview 判断基準・状態別メッセージ・passkey 429) | 本 run で再観測されず |
| **aicue:T156** (コピー失敗時のフォールバック) | **意図どおり動作**。Clipboard API と `execCommand` の両方を失敗させると選択状態 + 手動コピー案内が出て、**5 秒経過後も消えない**ことを shard-4 が実機確認 |
| **aicue:T155** (撮影 PWA からの復路) | shard-1 の S3 走行で撮影 PWA ↔ マニュアル詳細の往復が成立 |

---

## Critical/High の TODO 候補

**Critical / High は 0 件**のため、この run からの即時 TODO 化候補は無い。
ただし以下 2 つは Medium ながら**まとめる価値がある**:

1. **フォームの stale-invalid を規約として直す** (F-1-01 + F-3-02)
   - 再現: shard-1 F-1-01 / shard-3 F-3-02
   - 阻害されるジョブ: 入力の正誤判断がつかず、送れば通るのに諦める
   - 候補: サーバ 422 由来の invalid を入力イベントで再評価する共通規約を FormField 側に置く
   - 関連: T041 / T044 で確立済みのパターンとの不整合
2. **XHR 404 のメッセージ collapse を `/app/*` にも適用** (F-1-03)
   - 候補: `ApiExceptionRenderer::shouldHandle()` の対象範囲、または同等 renderer の配線
   - **新機構は不要** (`api/*` で同じ collapse を既に持っている)

## 要確認 (仕様確認の質問リスト。バグとは分けている)

1. **退会予約 (凍結) 中の即時削除に、通る窓が実在するか** (F-4-Q1)。再現しなかったが実データが消えた。
2. **同一組織内のメンバー削除で 403/404 を分けてよいか** (F-3-01)。cross-tenant の存在秘匿とは層が違う。

## インベントリの drift

- **なし** (走行前チェックで確認済み。走行中も追加提案は出なかった)。
