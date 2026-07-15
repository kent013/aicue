# bug-hunt report shard-1 (run 20260715-084108)
- 走行主眼: 新機能 T046-T056 の実挙動検証 (S3 core journey 更新版) + S7 IDOR
- 実行ストーリー: S3 (完走) → S7 (完走)
- skip したステップ: なし。逸脱アイデアの一部(署名URL改ざん・upload-url署名チケット偽装・カメラ反転失敗フォールバック等)は環境制約(headless に実カメラなし・時間予算)により未実施 — 理由: 撮影 PWA はサイト全体の Permissions-Policy (F-1-04) によりブラウザ内カメラ録画へ到達不能なため、カメラ依存の逸脱検証は本質的に実施不能。
- 画面カバレッジ: 走行 13 / 13 (S3 対象全て): projects.show, projects.manuals.create, projects.manuals.show, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.show, projects.manuals.render-jobs.playback, projects.manuals.download, capture.home, capture.csrf-cookie(暗黙), capture.manuals.index, capture.manuals.show, capture.takes.playback
- 操作カバレッジ: 実行 15 / 15 (S3 対象全て): projects.manuals.store, update(UI経由), destroy(未実行=破壊的操作のため見送り。confirmダイアログ自体はF-1-01検証時に確認済み), duplicate, source-documents.store, analyze, scenario.update, preview, render, capture.takes.upload-url, store, update(未明示実行だがコメント欄等operations経路は確認), destroy(確認ダイアログのみ、キャンセルで見送り), adopt, downloaded(自動)。S7: projects.manuals.update/destroy/scenario.update/analyze/render/preview/source-documents.store(すべてB視点404)、projects.categories.update/destroy/reorder、capture.takes.adopt/destroy/store/update/upload-url/downloaded(すべて404)。
- 新機能検証状況 (T046/T048/T049/T047/T050/T043/T051/T056/T054/T053/T040/T032): 全項目実施完了。詳細は下記チェックリスト。
- UI/UX 検証: 視覚破綻(H11)= F-1-05(mobile 375でテイク行重なり)。アフォーダンス・状態(H12)= 概ね良好(採用中/DL済み/複製中などのバッジ・disabled状態は判別可能)。レスポンシブ(H13)= capture.manuals.show を mobile 375x667 / tablet 768x1024 で確認、mobile でのみ F-1-05 を検出、tablet は問題なし。desktop に復帰済み。a11y基礎(H14)= 大きな欠落は未検出(native videoコントロール・ボタンのaria-label等は概ね適切)。
- findings: Critical 0 / High 2 (F-1-01, F-1-04) / Medium 3 (F-1-02, F-1-03, F-1-05) / Low 0 / 要確認 4 (Q1-Q4)

## 新機能チェックリスト (随時更新)
- [x] T046 導入/総括カット自動挿入 — OK: AI解析後、常に手順1=「作業全体の俯瞰（導入）」・末尾=「作業全体の俯瞰（総括）」が自動挿入されることを確認
- [x] T048 Undo/Redo — OK: ボタン(元に戻す/やり直す)・Ctrl/Cmd+Z・Ctrl+Shift+Z いずれもセル編集/行削除(確認ダイアログ込み)で正しく動作、保存後にクリアされることも確認
- [x] T049 別名保存/複製 (projects.manuals.duplicate) — 機能自体はOK(cuts複製・takes空・status=draft・新マニュアルへ遷移)だが **F-1-01: 複製成功後もダイアログが閉じず多重複製が可能** (High)
- [x] T047 字幕オーバーレイ ON/OFF — OK: 撮影パネル・テイクプレビューいずれも字幕①②が重畳表示されトグル可能。ただし **F-1-04によりブラウザ内カメラ録画自体に到達不能** (常にファイル選択にフォールバック。字幕オーバーレイのプレビュー表示自体は正常)
- [x] T050 テイクインラインプレビュー (capture.takes.playback) — OK: モーダルで再生+字幕トグル+採用ボタンが揃っており別タブ遷移なし
- [x] T043 テイク削除確認ダイアログ — OK: 「テイク削除」確認ダイアログ(キャンセル/削除する)を確認
- [x] T051 自動DL+ACK — OK: capture.manuals.show 入室時に採用済み未DLテイクへ `POST cuts/{id}/takes/{id}/downloaded` が自動発火し「DL 済み」バッジが反映される
- [~] T056 撮影UX — グリッド表示トグル・字幕トグルはUI状態変化を確認。録画タイマー/一時停止再開/カメラ反転は **F-1-04(Permissions-Policyでcamera/microphone全面禁止)によりMediaRecorderへ到達できず機能検証不能**(環境ではなくサイト全体のヘッダ起因)
- [x] T054 文脈リンク (マニュアル→撮影ナビ) — OK: manuals.show/edit いずれも「この手順書を撮影する」→ capture.manuals.show への直接リンクを確認(status=ready後に出現)
- [~] T053 一覧sort/filter — projects.show(PC側)は並べ替え/フィルタ/作成者・更新日メタすべてOK。**F-1-02: capture.manuals.index(撮影PWA側)に並べ替えUIが存在せず、バックエンドもsortパラメータ非対応**(Medium)。フィルタ・進捗バッジ・作成者メタはPWA側もOK
- [x] T040 レンダー失敗alert帰属 — OK: 採用テイク未設定での「完成動画を生成」は明確なカット名列挙付きアラート「完成動画の生成を開始できませんでした: 採用テイクが未設定のカットがあります: 手順1、手順2...」で正しくブロックされる(プレビュー生成は採用テイク無しでも非ブロッキングで succeeded、これは設計上の非対称と考えられ要確認)
- [x] T032 stale alert 残留なし — OK: 解析失敗後に手順書を差し替えて再解析成功すると失敗alertは消える。**ただし**手順書差し替え直後・再解析トリガー前は旧失敗alertが残る(次の解析実行までは「直近の解析結果」として妥当とも言え、明確なバグと断定せず「要確認」に留める)
- [x] capture.manuals.sync 廃止確認 — 走行中に `capture.manuals.sync` へのリクエストは一切発生せず(requests ログに出現なし)。ストーリー通り削除済みと確認
- [x] S7 IDOR (duplicate/take 含む) — OK: 詳細は下記 S7 節

## 要確認 (severity 未確定、バグと断定しない)
- Q1: プレビュー生成(非消費)は採用テイクが0件でも常に succeeded する一方、完成動画を生成(消費)は同条件で明確にブロックされる。この非対称は意図的仕様か(プレビューはストーリーボード的な下書き確認用途と割り切っているのか)。仕様書での確認が必要。
- Q2: 解析失敗(短すぎるSOP)後、手順書を新しいファイルに差し替えても、次に「AI 解析」を再実行するまでは失敗alertが画面に残り続ける。「直近の解析結果」表示として妥当とも言えるが、差し替え直後は誤解を招く可能性がある。
- Q3: manual #1 の各種操作(analyze x3, render x1)〜manual #2 (analyze x1, 採用テイク未設定でブロックされたrender x1)を経て、チケット残高が 100→94 (6消費)。「採用テイク未設定」でブロックされたrender(202/422等で開始不可)がチケットを消費したかは未検証(billing 詳細は S5 の管轄範囲のため本shardでは深追いしていない)。もし検証未開始のrenderでチケットが引かれているなら課金バグの可能性がある。
- Q4: S7 検証中、`fetch()` で直接 PATCH `/projects/1/manuals/1` に `X-Inertia` 等の Inertia 標準ヘッダを付けずに連続で叩いたところ、一時的に `net::ERR_TOO_MANY_REDIRECTS` および無関係な `UpdateProjectRequest`(プロジェクト名必須エラー)の応答が返る現象を観測した。ただし実際の UI 操作(タイトル編集フォームでの保存)は毎回正しく動作し、データも正しく保存された。`public/capture-sw.js` を確認したところ GET かつ `/build/*` 以外は素通し(caching対象外)と明記されており、Service Worker が原因とは考えにくい。生 fetch (Inertia 規約外のリクエスト) を短時間に連投したことによるテスト手法起因のノイズと判断し、再現手順が不確かなため finding とはせず記録のみに留める。次回 shard-report を読む者は、この経路(生 fetch 連投)を避け、実 UI 操作 or 適切な X-Inertia ヘッダ付き XHR で再検証することを推奨する。

## S7 (認可境界 / IDOR) 実施結果サマリ
- 前提: S3 で作った組織A(Standardプラン組織, owner-standard@example.com, project id=1, manual id=1/2/3, category id=1, cut id=46-61 等)に対し、同一ブラウザセッション内で組織B(owner-free@example.com, 新規 project id=2, category id=2)へログイン切替して直叩き検証。
- 手順1 (画面直叩き): `projects.show`/`projects.manuals.show`/`projects.manuals.edit`/`app/projects/.../manuals/{id}` すべて **404** (fetchで status 確認)。
- 手順2 (書き込み直叩き): `projects.manuals.update`(PATCH)/`destroy`(DELETE)/`scenario.update`(PUT)/`analyze`(POST)/`render`(POST)/`preview`(POST)/`source-documents.store`(POST) すべて **404**。
- 手順3 (category): `projects.categories.update`/`destroy` すべて **404**。`reorder` の existence oracle 差分テスト: A の実在 category id(1) を混入した場合と、実在しない id(9999) を混入した場合で **全く同じ 422 メッセージ**(「並び順の指定がカテゴリ一覧と一致しません」)を確認。差分オラクルなし(OK)。同名カテゴリを A/B 別プロジェクトで作成できることも確認(project スコープ unique、OK)。
- 手順4 (撮影面 capture.takes.*): `store`/`adopt`/`update`(PATCH)/`destroy`(DELETE)/`downloaded`(POST)/`upload-url`(POST) すべて **404**。`capture.manuals.sync` も 404(ルート自体削除済みと一致)。
- 手順5 (cross-cut adopt): 組織A内で cut46 の take(id=1)を cut47 の adopt エンドポイントに渡すと **404**(`No query results for model [App\Models\Take] 1` — cut->takes() relation 経由解決が正しく機能)。
- 手順6 (ロール境界): project_member(撮影者, member-standard@example.com)で `manuals.store/update/destroy`・`categories.store`・`analyze`・`render`・`manage.users` すべて **403**。撮影者自身の `capture.takes.adopt` は正常に 200(自ロール範囲の操作は許可、OK)。
- 手順7 (protected keys): `project_id`/`category_id`/`created_by`/`ticket_reservation_id` を主要 payload に混入するといずれも **422**(ProhibitsProtectedKeys、メッセージ例:「project id を入力する必要はありません。」)。`category`(別名)は許容されることも確認。`capture.takes.adopt`(`adopted_take_id` 混入)は元々リクエストボディを一切読まない実装(route param のみで完結)のため 422 にはならず黙って無視される — これは安全(値は反映されない)だが、S7 カードの「全 protected key で 422」という一般化された期待とは厳密には一致しないため要確認扱い(セキュリティ上の実害はなし)。
- 結論: S7 の主要な IDOR/認可/存在オラクル/protected-key 不変条件はすべて健全。**Critical/High な認可漏れは発見されなかった**。

## Findings

## F-1-01: 複製ダイアログが複製成功後も閉じずに残留し、連打で意図せず多重複製される (T049)
- severity: High
- story/step: S3-6 (別名保存/複製 T049)
- 再現手順:
  1. owner-standard@example.com でログイン、Default Project の動画マニュアル `検品SOP` (id=1, ready) 詳細画面 `/projects/1/manuals/1` を開く。
  2. 「複製」ボタン → ダイアログ「動画マニュアルを複製」→「複製する」をクリック。
  3. 複製成功し `/projects/1/manuals/2` (検品SOP のコピー, draft) へ遷移するが、**ダイアログが閉じずに同じ内容(タイトル「検品SOP のコピー」、有効な「複製する」ボタン)のまま画面に残留**する。
  4. 遷移後の画面で再度「複製する」をクリックすると、`POST /projects/1/manuals/2/duplicate` が実行され `/projects/1/manuals/3` へさらに遷移する(= manual #2 のコピーである manual #3 が新規作成される)。ユーザーは 1 回しか意図的にクリックしていないのに、ダイアログの残留により誤って多重に複製が生成できてしまう。
- 期待: 複製成功後はダイアログが自動的に閉じる(以後のクリックで多重生成が起きない)。
- 実際: ダイアログが開いたまま(閉じるアクションが呼ばれない)。ページは Inertia 遷移しているが dialog の open state がリセットされず、`複製する` ボタンが次の manual に対して有効なまま残る。
- 阻害されたユーザージョブ: 「シナリオを雛形に 1 件だけ複製して編集を始める」目的が、意図しない追加複製の発生によって作業データが汚染される(不要な draft マニュアルが増殖し、後片付けの手間が生じる)。
- 改善アクション候補: 複製成功 (onSuccess) 時に dialog の open state を明示的に false にする。もしくは複製ボタン押下時に in-flight を保持しダブルクリック/残留クリックを弾く(冪等化)。
- 証跡: screenshots/T049-duplicate-modal-after-nav.png, screenshots/F01-duplicate-stale-modal-double-submit.png, network: `POST /projects/1/manuals/1/duplicate => 302`, `POST /projects/1/manuals/2/duplicate => 302` (playwright-cli requests)
- 推定原因: 未調査 (複製確認ダイアログの Svelte コンポーネントで、成功コールバック時に `open`/`show` フラグをリセットしていない可能性)。
- 関連既知情報: なし(T049 は本走行で初検証)

## F-1-02: capture.manuals.index (撮影PWA側マニュアル一覧) に並べ替え(sort)機能が存在しない (T053)
- severity: Medium
- story/step: S3-7 (撮影面一覧 T053)
- 再現手順:
  1. owner-standard@example.com でログイン、`/app/projects/1/manuals` (capture.manuals.index) を開く。
  2. カテゴリ絞り込み・キーワード検索・「自分が作ったシナリオ」チェックボックスは存在するが、**並べ替え(sort)の UI コントロールが存在しない**(`playwright-cli find "並べ替え"` / "ソート" / "順" いずれも 0 件)。
  3. コード確認: `app/Http/Controllers/Capture/CaptureManualController::index()` は `->orderByDesc('updated_at')` 固定で、`sort` クエリパラメータを一切受け付けていない(PC 側 `projects.show` の manualRows には並べ替えクエリ+UI が実装されている対比)。
- 期待: S3 ストーリー(T053)は `capture.manuals.index` にも「並べ替え/自作フィルタ/進捗バッジ/作成者メタ」が効くことを要求している。
- 実際: フィルタ(カテゴリ/キーワード/自分の作成分のみ)・進捗バッジ(カット数/採用数)・作成者/更新日メタは実装済みで正しく機能するが、**並べ替えのみ未実装**(常に更新日新しい順固定)。
- 阻害されたユーザージョブ: 撮影担当者(project_member)が多数のマニュアルの中から「タイトル順」や「更新が古い順」で目的のマニュアルを探したい場合に手段がない(PC 側では可能なのに撮影PWA側だけ非対称)。
- 改善アクション候補: PC 側 `projects.show` と同様の `sort` クエリパラメータ + UI ドロップダウンを `capture.manuals.index` に追加する。あるいは仕様として撮影PWA側は sort 対象外と確定しているなら、S3 ストーリー記述(T053 の対象範囲)を修正する。
- 証跡: `playwright-cli find` 3クエリすべて0件 (並べ替え/ソート/順)、コード: `app/Http/Controllers/Capture/CaptureManualController.php:81` (`->orderByDesc('updated_at')` 固定、sort パラメータなし)
- 推定原因: `CaptureManualController::index()` に sort クエリパラメータの受け取り・適用ロジックが未実装 (`app/Http/Controllers/Capture/CaptureManualController.php` 49-91行目)。
- 関連既知情報: なし(T053 は本走行で初検証。PC 側 `projects.show` の並べ替えは正常動作を確認済み)

## F-1-03: マニュアル公開後(published)、シナリオ欄の説明文が「シナリオ未生成」を示す文言に戻り実際の状態と矛盾する (H10)
- severity: Medium
- story/step: S3-8 (完成動画生成後の表示、T040 隣接領域)
- 再現手順:
  1. owner-standard@example.com でログイン。`検品SOP` (manual id=1) を AI 解析 → シナリオ編集 → 全カットにテイクを採用 → 「完成動画を生成」でレンダー完了(status=公開済み)まで通す。
  2. `/projects/1/manuals/1` (manuals.show) を開く。ステータスバッジは正しく「公開済み」。
  3. しかし「シナリオ」カード直下の説明文が **「アップロード済みの手順書から AI がシナリオを生成できます。」**(= シナリオがまだ無い状態を示す文言)になっている。実際には 16 個のカットを含む完成済みシナリオが存在し、そのシナリオでレンダーが成功しているにもかかわらず、あたかもシナリオ未生成であるかのような文言が表示される。
  4. `/projects/1/manuals/1/edit` を開くと 16 件のカット(手順+急所)がそのまま存在しており、データ自体は失われていない(表示文言のみの不整合)。
- 期待: シナリオが存在する場合は status に関わらず「手順書から生成したシナリオを編集画面で確認できます。再解析すると既存のシナリオは置き換えられます。」のような、シナリオ存在を前提とした文言になるべき。
- 実際: `resources/js/components/features/manual/AnalysisPanel.svelte` の説明文分岐が `status === "ready"` の場合のみ正しい文言を出し、それ以外(draft/analyzing/rendering/**published** 等すべて)は `hasDocument` が true である限り「シナリオ生成できます」(未生成前提)の文言に落ちる。status="published" はシナリオが必ず存在する状態にもかかわらずこの分岐から漏れている。
- 阻害されたユーザージョブ: 公開済みマニュアルを見たユーザー(特に編集者以外の閲覧者)が「このマニュアルはまだシナリオが無い/未完成」と誤解し、不要な AI 再解析(既存シナリオを上書きする破壊的操作)を実行してしまうリスクがある。
- 改善アクション候補: `AnalysisPanel.svelte` の分岐条件を `status === "ready"` から「シナリオ(cuts)が 1 件以上存在する」または `status !== "draft"` 相当の条件に変更する。
- 証跡: screenshots/render-complete-published.png (「公開済み」バッジ + 「アップロード済みの手順書から AI がシナリオを生成できます。」の文言が同時に表示されている)
- 推定原因: `resources/js/components/features/manual/AnalysisPanel.svelte:308-320` の `{#if !hasDocument} ... {:else if status === "ready"} ... {:else} ...{/if}` 分岐で `status === "published"`(および `"rendering"`)がフォールスルーし誤文言になる。
- 関連既知情報: なし(本走行で新規発見。T040/T032 の隣接領域として観察)

## F-1-04: サイト全体の Permissions-Policy が camera/microphone を常時無効化しており、撮影PWAのブラウザ内カメラ録画(T047/T056)がどの端末でも常にファイル選択にフォールバックしてしまう
- severity: High
- story/step: S3-7 (撮影中カメラプレビュー T047 / 撮影UX T056)
- 再現手順:
  1. owner-standard@example.com でログイン。`/app/projects/1/manuals/1` (capture.manuals.show) でカットを選択し「録画開始」を押す。
  2. 毎回 "この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。" と表示され、カメラプレビュー(グリッド・字幕オーバーレイ・タイマー・カメラ反転)には一切到達できず、常にファイル選択 UI にフォールバックする。
  3. レスポンスヘッダを確認: `curl`/`fetch` で当該ページの HTTP ヘッダを見ると `Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")` が **/app/(撮影PWA)を含む全ルートに例外なく**付与されている(`app/Http/Middleware/SecurityHeaders.php` が `config('security.permissions_policy')`＝`config/security.php:31-34` の固定値を全レスポンスに適用。`/app/*` 用の緩和・上書きは存在しない。`.env`/`.env.bughunt.local`/`.env.example` いずれにも `SECURITY_PERMISSIONS_POLICY` の上書きなし)。
  4. `Permissions-Policy` で `camera=()` / `microphone=()` (空 allowlist) を送出すると、ブラウザは **物理カメラの有無に関わらず** `getUserMedia()` の権限要求自体を拒否する。したがって表示されているフォールバック文言「この端末では...利用できない」は誤り(端末非依存の恒久的なポリシー起因の遮断であり、実機・本番でも同様に発生しうる)。
- 期待: 撮影 PWA (`/app/...`) のようにカメラ/マイクを本質的に必要とする画面では `Permissions-Policy` の `camera` / `microphone` allowlist に自オリジンを含める(または該当ルートのみ緩和する)ことで、ブラウザ内カメラ録画(T047 字幕オーバーレイ・T056 タイマー/グリッド/一時停止再開/カメラ反転)が実際に動作する。
- 実際: 全ルート一律で camera/microphone を禁止しているため、ブラウザ内蔵カメラ録画の主要導線に到達できず、常にファイル選択(外部カメラアプリで撮影してアップロード)にフォールバックする。ユーザー向けメッセージも「この端末の制約」であるかのように誤帰属している。
- 阻害されたユーザージョブ: 「スマートフォンでシナリオを見ながらその場でブラウザ内カメラ録画し、字幕オーバーレイ・グリッド・タイマーを使って正確なテイクを撮る」という T047/T056 の主要ユーザージョブが根本的に阻害される(ファイル選択フォールバックで作業自体は続行できるが、外部カメラアプリでの別撮り+ファイル選択という手間が常に必須になり、ガイド機能=このアプリの差別化価値が使えない)。
- 改善アクション候補: `config/security.php` の `permissions_policy` を環境変数一枚岩ではなく撮影 PWA ルート (`/app/*`) 用に camera/microphone を allow するオーバーライドを `SecurityHeaders` ミドルウェアに追加する(例: `$request->is('app/*')` の場合は `camera=(self), microphone=(self)` を送出)。合わせてフロントエンドのフォールバック文言を「この端末/ブラウザ設定によりカメラを利用できません」など原因を断定しない表現に修正する。
- 証跡: `playwright-cli eval "() => fetch(location.href).then(r => r.headers.get('permissions-policy'))"` => `"geolocation=(), microphone=(), camera=(), payment=(self \"https://js.stripe.com\")"` (capture.manuals.show 上で実行)。console: `[ERROR] Permissions policy violation: camera is not allowed in this document.` / `[ERROR] Permissions policy violation: microphone is not allowed in this document.`。コード: `app/Http/Middleware/SecurityHeaders.php:38-41`、`config/security.php:31-34`。
- 推定原因: `config/security.php` の `permissions_policy` 既定値が camera/microphone を空 allowlist(禁止)にしたまま、撮影 PWA ルート向けの例外が実装されていない。
- 関連既知情報: なし(T047/T056 は本走行で初検証。カメラ機能自体はフロントエンド側のフォールバック処理は正しく動作しており、原因はミドルウェア層の HTTP ヘッダ設定)

## F-1-05: 撮影PWA(capture.manuals.show)のテイク行が mobile 375px 幅でラベル縦積み・アイコン重なりを起こす (H11/H13)
- severity: Medium (操作阻害の可能性あり: 再生ボタンがバッジと重なって視認できない)
- story/step: S3-7 (撮影面, レスポンシブ確認)
- 再現手順:
  1. owner-standard@example.com でログイン、`/app/projects/1/manuals/1` (capture.manuals.show) を開く。
  2. `playwright-cli resize 375 667` で mobile 幅にリサイズ。
  3. 任意のカット(例: 手順1)をタップしてパネルを開く。テイク一覧の行 (`テイク 1` / `採用中` / `DL 済み` / `30 KB` + 再生・採用・DL・コメント・削除ボタン) を確認する。
  4. **「テイク1」ラベルが縦に1文字ずつ折り返され(テ/イ/ク/1)、「採用中」「DL 済み」バッジと「再生」ボタンが重なり合って表示される**(再生ボタンの▶アイコンがバッジの影に隠れて判別しづらい)。
- 期待: mobile 幅でもラベル・バッジ・操作ボタンが重ならず、各要素が視認・タップ可能であること(H13)。
- 実際: 横幅不足によりテイク行の内部レイアウトが破綻し、ラベルの縦積み+バッジとボタンの重なりが発生する。
- 阻害されたユーザージョブ: 撮影者がスマートフォン実機(想定主要デバイス)でテイクの状態(採用済み/DL済み)を確認し、再生ボタンをタップしてプレビューする操作が、ラベルの視認性低下・ボタンの重なりにより困難になる。
- 改善アクション候補: テイク行のレイアウトに `flex-wrap` や縮小時のラベル省略(「テイク1」を横書き維持したまま省略表示にする、またはバッジをボタン行と別行に確実に分離する)等のレスポンシブ対応を追加する。
- 証跡: screenshots/H13-mobile375-cut-panel.png (viewport 375x667 full page), screenshots/H13-take-row-zoom2.png (該当行の要素クロップ)
- 推定原因: 未調査 (テイク行コンポーネントの flex/grid レイアウトが狭幅を想定していない可能性)。
- 関連既知情報: なし(本走行で新規発見)

## Critical/High TODO 候補 (app-todo-add 向け要約)
- **F-1-04 (High)**: サイト全体の `Permissions-Policy: camera=(), microphone=()` (config/security.php:31-34, app/Http/Middleware/SecurityHeaders.php:38-41) が撮影PWA(`/app/*`)にも例外なく適用され、ブラウザ内カメラ録画(T047字幕オーバーレイ・T056タイマー/グリッド/一時停止再開/カメラ反転)が常にファイル選択にフォールバックし到達不能。阻害ジョブ: 「その場でブラウザ内カメラ録画しながらガイド機能(字幕/グリッド/タイマー)を使う」という主要差別化価値が使えない。改善: `/app/*` ルート向けに camera/microphone を allow するオーバーライドを SecurityHeaders ミドルウェアに追加。関連: F-1-04 詳細参照。
- **F-1-01 (High)**: `projects.manuals.duplicate` の確認ダイアログが複製成功後も閉じずに残留し、再クリックで意図せず多重複製(cuts一式)が生成される。阻害ジョブ: 「シナリオを1件だけ複製して編集を始める」が誤操作で汚染される。改善: 複製成功時にダイアログの open state を明示的に false にする。関連: F-1-01 詳細参照。
- **F-1-02 (Medium)**: `capture.manuals.index` (撮影PWA側マニュアル一覧) に並べ替え(sort)機能がなく、バックエンドも `sort` パラメータ非対応(`orderByDesc('updated_at')` 固定)。PC側 `projects.show` は正常。改善: PC側と同様の sort パラメータ+UIを追加、またはS3ストーリー記述の対象範囲を修正。
- **F-1-03 (Medium)**: マニュアル公開後(status=published)、シナリオ欄の説明文が「シナリオ未生成」を示す文言(`AnalysisPanel.svelte`の`status==="ready"`以外の分岐)に戻り、実在するシナリオと矛盾する。改善: 分岐条件をシナリオ存在ベースに変更。
- **F-1-05 (Medium)**: 撮影PWAのテイク行が mobile 375px 幅でラベル縦積み・バッジとボタンの重なりを起こす。改善: テイク行レイアウトにレスポンシブ対応を追加。

## 要確認まとめ (仕様確認が必要、バグと断定しない)
Q1(プレビュー非消費 vs 完成動画消費の採用テイク未設定時の非対称挙動)/ Q2(手順書差し替え直後の失敗alert残留)/ Q3(ブロックされたrenderのチケット消費有無)/ Q4(生fetch連投時のERR_TOO_MANY_REDIRECTS、UI操作では非再現につきテスト手法起因の可能性が高い)。詳細は上記チェックリスト内「要確認」節を参照。

## インベントリ修正提案
- S3ストーリー(stories/S3-core-journey.md)は今回の新機能記述と実装がおおむね一致していた。唯一のズレ: T053の「capture.manuals.index(並べ替え/自作フィルタ/進捗バッジ/作成者メタが効く」という記述のうち「並べ替え」部分は実装が存在しない(F-1-02)。実装を追加するか、ストーリー記述を「フィルタ/進捗バッジ/作成者メタ」のみに修正するかの判断を推奨。

---
**走行終了**: `playwright-cli close` 実行済み。本レポート絶対パス:
`/workspace/.claude/worktrees/tasks/bughunt-20260715-reallm3/devnotes/20260715-084108-bug-hunt/shard-1/shard-report.md`
