# bug-hunt shard-3 report (run 20260714-093524)

- shard: 3 / stories: S4 (組織・プロジェクト・カテゴリ・ユーザー管理), S5 (課金・チケット)
- URL: http://127.0.0.1:8013 / DB: bug_hunt_3 / browser session: bughunt3
- 主眼: F-02 (T033: member-role-update-feedback) 回帰確認 (最重要) + 前回中断分の補完
  (projects.edit/update, org switcher, manage.users) + S4/S5 常設ストーリー実走
- real-llm 走行 (--real-llm)

## 回帰確認サマリ (F-02 / T033) — 最重要

**修正確認: FIXED (回帰なし)**。`manage/users`(組織にプロジェクトが1つも無い状態、owner-free@example.com の
「Freeプラン組織」)で Free Admin(admin-free@example.com)のロールを 管理者→編集者 に変更したところ、
`PATCH /organizations/{slug}/members/{id}` は従来通り 303(Inertia redirect-back)で返るが、今回は:
1. combobox が権威値「管理者」に **確実に戻る**(選択後の値のまま残らない)。
2. combobox 直下に「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」がエラーとして表示され、
   combobox 自体が invalid 状態になる ([invalid] attr 確認)。
3. combobox にフォーカスが残る(remount 後もキーボード操作から離脱しない、[active] 確認)。
4. リロードしても状態は一貫(管理者のまま、エラーは消える=セッション flash なので妥当)。

「撮影者」への変更でも同じ挙動を確認 (screenshots/F02-regression-fixed-editor-reject.png)。
また、`projects.create`→`projects.store` でプロジェクトを1つ作成した後は、同じ Free Admin 行を
「編集者」に変更すると **成功**(エラーなし、combobox が編集者のまま確定)することも確認した。
以前の finding (`devnotes/20260714-005157-bug-hunt/shard-2/shard-report.md` F-02、
`devnotes/20260714-0216-member-role-update-feedback/` で修正実装)が意図通り解消されている。

**副次確認(良い設計の再確認、bug ではない)**: `projects.destroy` でその組織唯一のプロジェクト(default
project)を削除すると、そのプロジェクトの pivot で editor/shooter を割り当てられていたメンバー(Free Admin /
Multi Org User)は自動的に「未割当」へ戻り、`manage/users` は矛盾なく「プロジェクトがまだありません」注記を
再表示した。ダングリング状態が残らないことを確認 (H9/H10 系の健全性チェック)。

## 画面カバレッジ (S4/S5 全画面 走行済み)
- S4 screens: organizations.create ✅, organizations.settings ✅, organizations.api-keys.index ✅,
  organizations.api-keys.sessions.index ✅ (空状態), organizations.onboarding.cli ✅, organizations.onboarding.mcp ✅,
  manage.users.index ✅ (owner/admin/editor/403 の複数ロールで確認), projects.index ✅ (空状態→作成後→削除後の空状態),
  projects.create ✅, projects.edit ✅, projects.categories.index ✅ (owner/editor 両方で確認)
- S5 screens: pricing ✅ (未ログインでも到達可、CTA→register 誘導確認), billing.index ✅ (Free/Standard 組織で確認)
  + 副次で purchase-tickets (billing.tickets.show) も確認

## 操作カバレッジ
- S4 operations:
  - organizations.store ✅ (空値バリデーション→正常系で新組織作成、自動 switch + settings へ遷移)
  - organizations.update ✅ (組織名更新、成功トースト)
  - organizations.switch ✅ (成功トースト、current org 反映)
  - organizations.transfer-ownership ✅ (確認ダイアログ→本人確認(パスワード再入力)ダイアログ→成功。
    旧オーナーが管理者へ降格、新オーナーの行が「管理者（オーナー）」に反映されることを manage/users で確認)
  - organizations.two-factor-requirement.update ✅ (実行試行 → 「必須化するには、先にご自身の2段階認証を
    有効にしてください。」の適切な前提条件エラーで拒否。バグではなく正しいガード)
  - organizations.api-keys.store ✅ (発行、平文キー1度だけ表示を確認)
  - organizations.api-keys.revoke ✅ (確認ダイアログ→失効→「失効済み」反映)
  - organizations.api-keys.sessions.revoke — **skip (理由: OAuth 接続セッションが存在せず対象なし)**。
    空状態の説明文言は適切に表示されることを確認済み。
  - projects.store ✅ (空値バリデーション→正常系で作成、show へ遷移)
  - projects.update ✅ (名称変更、成功トースト+タイトル即時反映)
  - projects.destroy ✅ (確認ダイアログ→キャンセルで何も起きないことを確認→再実行→削除→一覧へ遷移+成功トースト)
  - projects.categories.store ✅ (空値バリデーション→重複名バリデーション→正常系2件作成)
  - projects.categories.update ✅ (名称変更、成功トースト)
  - projects.categories.destroy ✅ (確認ダイアログに「動画マニュアルは未分類になります」の明示文言、削除実行)
  - projects.categories.reorder ✅ (▲▼ 実行、順序反映を確認。動画一覧への反映は本プロジェクトに動画マニュアルが
    無いため未検証 — 理由: manual 作成は S3 領域でこのストーリーの前提外)
  - projects.members.store ✅ (成功トースト、一覧反映)
  - projects.members.destroy ✅ (確認ダイアログ「組織のメンバーシップは維持されます」の明示、削除実行)
  - projects.items.store ✅ (成功トースト)
  - projects.items.update ✅ (モーダル編集、成功トースト+一覧即時反映)
  - projects.items.destroy ✅ (確認ダイアログ、削除実行)
  - debug.login-as — **skip (理由: local-only debug 機能で S1 領域、routes/web.php で isLocal ガード済み。
    本ストーリーの主眼である組織/プロジェクト管理とは独立のため今回は追走せず)**
- S5 operations:
  - billing.checkout ✅ (Standard プランへの申込ボタン押下 → fake harness で `?fake_external=stripe` に
    ラウンドトリップ。既知の制約通りプラン反映は完走しない。二重クリックでも追加エラーは出ず 409 (Inertia::location
    の正規動作) が2回返るのみで実害は観測できず — fake harness 上限のため断定不可)
  - billing.portal ✅ (同様に `?fake_external=stripe` ラウンドトリップ。409 は Inertia::location の正規プロトコル
    でありバグではない ⚠️自己修正: 最初 409 を異常と誤認したが `Inertia::location()` は仕様上 409 Conflict +
    `X-Inertia-Location` ヘッダを返しクライアントが window.location へ完全ページ遷移する規約。実装
    `app/Http/Controllers/Billing/BillingController.php` で確認し誤検知を取り下げ)

## 認可境界 (deviate/H9)
- **manage/users への 403**: editor ロール (multi-org@example.com を Free 組織で編集者に設定) で直接
  `/manage/users` にアクセス → **403 Forbidden** (アプリ標準 403 ページ)。正しく拒否。
- **projects.categories.index への editor アクセス**: 同じ editor ロールで `/projects/1/categories` に
  アクセス → 200 (書き込みフォームも表示、write 可)。`app/Policies/CategoryPolicy.php` の docblock
  「編集者 (project_admin) = write 全可、撮影者 (project_member) = 閲覧のみ」の通りの意図的仕様であり
  バグではない (要確認としても記録しない)。
- **IDOR (組織切替後の直 URL アクセス)**: `bughunt-shard3-新組織-改`(新規作成した無関係な組織、project 0件)
  に切替た状態で Free 組織の `projects/1` に直接アクセス → **404 Not Found**(403 ではなく404。存在の
  非開示という適切な選択)。正しく保護されている。

## UI/UX 検証 (H11-H14)
- H12 (状態表現): F-01 参照 (下記)。invalid 状態が実際の値の妥当性と食い違う。
- H11/H13 (レスポンシブ): mobile 375×667 で manage/users・projects/1(show, full-page)・purchase-tickets を
  確認 → 崩れなし、横スクロールなし、操作要素到達可。tablet 768×1024 で manage/users を確認 → **F-02(UI)
  参照(下記、名前の過剰truncate)**。確認後 desktop (1280×900) に戻して以降の操作を継続。
- H14 (a11y 基礎): manage/users の invalid combobox に aria-describedby 相当のエラー文言接続、フォーカス
  復帰を確認 (F-02/T033 修正の一部、上記参照)。異常なし。

## findings

### F-01: チケット購入枚数の入力エラーが、値を有効な範囲に修正しても消えずに残る (stale invalid 状態)
- severity: Medium (H10 + H12)
- story/step: S5-2 (purchase-tickets, `billing.tickets.checkout` 直前の client-side validation)
- 再現手順:
  1. `owner-free@example.com` / `password123` でログイン (Free 組織, オーナー/管理者)。
  2. `http://127.0.0.1:8013/purchase-tickets` を開く。
  3. 「枚数」欄に範囲外の値 `1001` (上限 1000 超過) を入力し「購入手続きへ (Stripe)」を押下する。
     → クライアント側バリデーションで送信ブロックされ、入力欄が invalid になり
     「購入枚数は 1〜1000 の整数で入力してください」がエラー表示される (ここまでは正しい)。
  4. **送信し直さずに**、枚数欄を有効な値 `20` に書き換える(合計金額 `単価 ¥80 × 20 枚 = 合計 ¥1,600` は
     正しく再計算されて表示される)。
  5. しかし入力欄は **[invalid] のまま**、エラーメッセージ「購入枚数は 1〜1000 の整数で入力してください」も
     **消えずに表示され続ける**。
- 期待: 値が有効範囲に戻った時点でエラー表示と invalid 状態は消えるべき(合計金額が正しく再計算されている
  のと矛盾しない状態表示になるべき)。
- 実際: エラーメッセージと invalid マークが値の妥当性と無関係に残留し続ける。実際に「購入手続きへ」を
  押すと(この状態のまま)正常に `POST /purchase-tickets/checkout` が送信され成功する(機能的なブロックは
  無い)ため、**表示上のみ矛盾した状態**になる。ユーザーは「エラーが出ているから購入できない」と誤認し、
  有効な入力のまま操作を諦める可能性がある。
- 阻害されたユーザージョブ: チケットのまとめ買い(価格改定の恩恵を受けるための正しい枚数入力)を、
  実際にはエラーがないのに「エラーが出ている」と誤認して中断してしまう可能性。
- 改善アクション候補: `resources/js/pages/Billing/PurchaseTickets.svelte` の `clientError` を
  `$derived` 化するか、`countText` の変更を監視して `isValidCount` が true に戻った時点で
  `clientError = null` にリセットする(現状は `submit()` 関数内でのみ `clientError = null` にリセットして
  おり、ユーザーの入力修正だけでは連動しない)。
- 証跡: screenshots/F03-purchase-tickets-stale-invalid.png (ファイル名は先に採番したもので finding 番号と
  ずれるが同一 finding の証跡)。requests: 有効値のままの
  `POST /purchase-tickets/checkout => 409 Conflict`(これは `Inertia::location` の正規動作でエラーではない)。
- 推定原因: `resources/js/pages/Billing/PurchaseTickets.svelte` L36 `let clientError = $state<string | null>(null);`
  と L81-87 `submit()` 内でのみ `clientError = null` / エラー再設定。`countText` 変更時に `clientError` を
  同期的にクリアする reactivity が無い(`isValidCount` は `$derived` で正しく再計算されるが、`clientError`
  はそれを反映しない独立した state)。
- 関連既知情報: なし(新規)。F-02/T033(role update の stale UI state)と根本パターンは類似
  (「サーバ/計算は正しいのに表示側の state が古いまま残る」)だが、対象コンポーネント・実装は別。

### F-02: tablet 幅 (768px) で `manage/users` のメンバー名が過剰に truncate される (「Unverified User」→「Un...」)
- severity: Low〜Medium (H11 + H13, 見た目のみ・操作阻害なし)
- story/step: S4-4 (manage.users.index)
- 再現手順:
  1. 任意の組織オーナー/管理者でログインし `manage/users` を開く。
  2. ブラウザ幅を 768×1024 (tablet) にリサイズする。
  3. ロール未割当のメンバー行(名前の右に「未割当」バッジが付き、ロール combobox のプレースホルダが
     「未割当（選択してください）」という長い文字列になる行)を見る。
  4. 名前が「Unverified User」のような通常の長さの表示名でも、`min-w-0`+`truncate` の flex 収縮により
     **「Un...」まで切り詰められ判読不能になる**。アクセシビリティツリー上のテキストは
     `Unverified User`のまま保持されており(スクリーンリーダー等には正しく伝わる)、視覚的な truncate のみの
     問題。
  5. 同じ画面の「Free Owner」「Free Member」など(未割当バッジが付かない/combobox のプレースホルダが
     短い行)は truncate されず全文表示される。バッジ+長いプレースホルダの組み合わせが名前の表示幅を
     過剰に圧迫している。
- 期待: 一般的なタブレット幅 (768px, H13 の標準チェック幅) で通常の長さの表示名が判読可能な程度には
  表示されるべき(全角/半角問わず十数文字程度は許容されたい)。
- 実際: 「未割当」バッジ付きの行に限り名前が数文字まで切り詰められ、どのユーザーの行か視覚的に判別
  困難になる。
- 阻害されたユーザージョブ: 管理者が tablet 端末でメンバー一覧を確認する際、未割当メンバーが「誰か」を
  名前から視認できない(メールアドレスは別行にあるため完全に不可能ではないが、UX 低下)。
- 改善アクション候補: `resources/js/pages/Admin/Users.svelte` の行レイアウト
  (`flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4`)で、
  768px 前後の中間幅では名前+バッジ列を折り返す(`flex-wrap`)か、combobox 側に `min-width` を抑える
  余地を設ける。もしくは truncate 適用前に十分な `min-width` を名前列に確保する。
- 証跡: screenshots/H13-tablet-manage-users.png。
- 推定原因: `resources/js/pages/Admin/Users.svelte` L271-289 付近。名前 `<p class="truncate ...">` を含む
  `div.min-w-0` と、`sm:shrink-0` の役割(ロール combobox + 削除ボタン)を持つ actions 列が
  `sm:flex-row sm:justify-between` で並ぶ際、768px では combobox の内容(長いプレースホルダ文字列)幅が
  actions 列を広げ、`min-w-0` の名前列が過度に圧迫される。
- 関連既知情報: なし(新規)。severity は見た目のみで操作を阻害しないため Low〜Medium とした。

## Critical/High 要約 (TODO 候補)
今回の走行では Critical/High の新規 finding は無し。F-02/T033(旧 High)は **修正確認済み(FIXED)**。
新規 finding (F-01, F-02) はいずれも Medium 以下(表示の食い違い・視覚 truncate)。

## 要確認 (バグと断定しない)
- なし(今回の走行で「要確認」に分類する事象は発生しなかった)。

## インベントリ修正提案
- なし。screens.md / operations.md は現行実装と整合していた。

## 環境ハザード
- なし。走行中、serve 断・500 連発・worktree 消失等の兆候は一切観測されなかった。

---
走行完了。上記の通り screens/operations は全項目を実行(skip 2件、理由明記)。
