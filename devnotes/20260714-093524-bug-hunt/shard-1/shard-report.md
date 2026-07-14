# bug-hunt shard-1 report (run 20260714-093524)

- shard: 1 (real-llm mode, 3rd run)
- URL: http://127.0.0.1:8011
- DB: bug_hunt_1 (users: 8 at start)
- ストーリー: S3 (core journey, real-LLM), S7 (authz boundaries)
- 主眼: S3 中核チェーンを実 LLM で通す。既知の未修正ギャップ (ffmpeg 無し→render失敗の可能性 / S3 fake storage) は finding化しない。LLM由来401は環境ハザードとして報告。F-1-1(T032) stale alert regression確認。

## 実行ステータス (随時更新)
- 開始: db-check OK (users=8)
- ブラウザ環境問題: playwright-cli default が `/opt/google/chrome/chrome` (未インストール) を探して起動失敗。
  `.playwright/cli.config.json` に `{"browser":{"browserName":"chromium", "launchOptions":{"chromiumSandbox":false}}}`
  を作成 (過去 shard-0 と同じ設定を踏襲) して解消。環境ハザードではなく shard 側の設定不足だったため自己解決。
- ログイン: owner-standard@example.com (Standardプラン, ticket残高100) で成功。プロジェクト無かったため
  「Default Project」を作成 (projects.store)。
- manual作成 (projects.manuals.store): 「コーヒーメーカー清掃手順」+ SOP (テキスト) アップロード → 下書き状態、
  作成成功flash確認。
- **AI解析 (projects.manuals.analyze) 実LLM成功**: 押下 → status=analyzing(解析中)「作業を分解中」→
  「シナリオを生成中」ポーリング(約45秒) → status=準備完了(ready)。console error 0件、network 4xx/5xx 0件、
  401 一切なし。**real-llm 配線は正常に機能している**(以前の Q1 401 ブロックは解消)。証跡:
  screenshots/S3-analyze-ready.png
- **capture.takes.upload-url = 既知ギャップ (b) の実態確認**: 撮影面 (`/app/projects/1/manuals/1`) で
  カット1にテイクをアップロードしようとしたところ、`POST .../cuts/1/takes/upload-url` が **500** で失敗。
  laravel.log: `InvalidArgumentException: Missing required client configuration options: region:
  ... at aws/aws-sdk-php/ClientResolver.php:1338` (App\Services\Capture\TakeObjectStorage::client() が
  常に `Storage::disk('s3')` の実 S3Client を生成しようとし、region 未設定で即クラッシュ)。
  `config/testing.php` の `fake_storage` docblock に「実 S3 接続の実配線は本 item スコープ外
  (consumer 未実装 = inert)」と明記されており、TESTING_FAKE_STORAGE=true でも TakeObjectStorage には
  一切配線されていない。→ **本走行の主眼メモにあった「(b) take upload系はfake挙動」という想定と異なり、
  実際には fake ではなく実S3クライアント初期化がクラッシュする**。既知/未修正ギャップ (b) の実態として
  finding化はしないが、**bug-hunt環境でS3ストーリーの撮影〜完成動画チェーンが検証不能**という運用上の
  制約として記録する。この結果、テイクアップロード以降 (adopt/sync/preview実写/render/download/S7の
  cross-cut adopt・take系IDOR) は空撮影データでの代替確認に留める。
  証跡: console error, network POST 500 (上記)
- **F-1-1 (T032) stale alert regression: PASS**。手順: 手動編集後 (未保存/保存済み双方) →
  プレビュー生成 (ffmpeg欠如で失敗、alert表示) → 完成動画生成 (採用テイク不足で422、alert追加) →
  「AI 解析」を再実行 (再解析確認ダイアログ経由) → 実LLMで再解析成功 (準備完了) → **再解析完了後は
  上記2つの alert がともにクリアされ、残留していない**ことを確認。scenario_version スナップショット
  方式による stale alert 制御は real-llm 走行でも正しく機能している (regression 無し)。
- H13 (レスポンシブ) mobile 375 / tablet 768 実施: manuals.show (mobile, ボタン文言折返し軽微),
  capture.manuals.show (mobile+tablet, 横スクロールバグ = F-1-3 Highとして記録)。desktop に復帰済み。
- S3 完走: projects.show→manuals.create/store→source-documents(作成時アップロード)→analyze(実LLM)→
  jobs.show ポーリング→ready→manuals.edit(シナリオ編集/保存)→manuals.show(preview/render試行、
  render事前バリデーション確認)→capture.home/capture.manuals.index/capture.manuals.show→
  takes.upload-url(既知ギャップ(b)実態=500、既知ギャップとして記録・finding化せず)。
  render/adopt/sync/playback/downloadは footage 未確保のため未完走 (既知ギャップ(b)由来、skip理由: 上記)。
- S3: 完了 (一部 skip、理由記載済み)
- S7 cross-org read: owner-free@example.com (org B, プロジェクト無し) から org A (owner-standard) の
  project/manual/jobs/render-jobs/capture-manuals を直叩き → 全て **404** (projects.show,
  manuals.show, manuals.edit, jobs.show, render-jobs.show, capture.manuals.show)。
- S7 cross-org write (fetch直叩き, X-XSRF-TOKEN付与): manuals.update(PATCH)/destroy/scenario.update/
  analyze/render/preview/source-documents.store/categories.update/destroy/categories.reorder(PATCH)/
  capture.manuals.sync/takes.upload-url/takes.update/takes.destroy/takes.adopt/takes.downloaded
  → **すべて404** (存在オラクルなし。405が出たのは HTTP method 誤り (POSTではなくPATCH) による
  ルーティング層の結果で org 混入とは無関係、検証しなおして404を確認済み)。
- S7 protected keys (owner-standard, 自プロジェクト内): `projects.manuals.store` に `project_id:999` →
  **422** (「project id を入力する必要はありません」)。`category_id`+`created_by` 同時混入 → **422**
  (両方拒否)。`category: null` (許容エイリアス) → 200 (作成成功、"Category Alias Test" 副生成)。
  期待通り (§10.8-7 に合致)。
- S7 project role (撮影者=member-standard@example.com を project 1 に project_member として追加):
  `projects.manuals.create` (画面) `/projects/1/categories` `/manage/users` → **403** (Blade/Inertia
  エラー画面、生スタックトレースなし)。`projects.show` は 200 (閲覧可、管理メニュー/メンバー管理UIは
  非表示で編集者専用導線が隠れている = 適切)。書き込み系 fetch 直叩き:
  `PATCH manuals.update`→403, `DELETE manuals.destroy`→403, `POST analyze`→403, `POST render`→403,
  `POST preview`→403 (全て編集者専用操作として正しく403)。
  - 観察 (finding化せず): `PUT scenario.update` は 422 (ProhibitsProtectedKeys/バリデーション)。
    `UpdateScenarioRequest::authorize()` が常時 `true` を返し、`Gate::authorize('update', $manual)` は
    コントローラ内でバリデーション成功後に呼ばれる設計 (Laravel Form Request の一般的パターン)。
    そのため撮影者でも「入力形状が不正」な間は 422 が先に出て 403 に到達しない。有効な shot_type 等を
    完全に揃えれば最終的に 403 になるはずだが、本走行では enum 値の組み合わせを特定しきれず未実証
    (5分調査で確定できず「未調査」)。データ漏洩は無い (バリデーションエラー文言は入力形状のみ、
    他組織/他ユーザーのデータは含まない) ため IDOR としては扱わない。
- S7 categories 実データでの越境確認: owner-standard で category「清掃手順」作成 (id=1) →
  member-standard(撮影者) で `PATCH/DELETE .../categories/1` → **403** (project内・権限外)。
  owner-free (org B) で同 `PATCH/DELETE/GET .../categories/1` → **403ではなく全て404** (cross-org、
  存在オラクルなし)。項目3 (categories.reorder の id 混入で422/404差分オラクルにならないか) は
  reorder のペイロード形状 (`{ids:[...]}` という仮定) が実装と一致せず 422 (バリデーションエラー) に
  終始し、混入 id による組織越境の差分は未確認 (5分調査でペイロード形状を特定できず「未調査」)。
- S7 cross-cut adopt (項目5) / 撮影面の隣接ID越境 (項目4) は **skip**: F-1-3 (既知ギャップ(b):
  takes.upload-url が500)によりテイクレコードを一切作成できず、実データでの cut→take adopt 越境
  再現が不可能なため。理由: takes 系エンドポイントは cross-org (org B から org A の
  `/app/projects/1/manuals/1/cuts/1/takes/*`) は直叩きで**全て404**であることは確認済み
  (前掲のcross-org write一括テストに含む)。実データでの「cut Xのtakeをcut Yのadoptに渡す」動作は
  未実施。
- S7: 完了 (一部 skip、理由記載済み)

## 総合カバレッジまとめ
- S3: projects.show, manuals.create/store/show/edit, jobs.show, capture.home(/app)/manuals.index/show
  を実走。render-jobs.show/playback/download は takes.upload-url 500 (既知ギャップ) により未到達 (skip、
  理由: 既知ギャップ(b))。
- S7: projects.*/manuals.*/categories.* の cross-org 404、project role (撮影者) の 403、protected keys
  422 を実データで確認。capture.takes.* の cross-org 404 は確認、実データでの cross-cut adopt / 隣接ID
  総当りは skip (理由: 既知ギャップ(b)でテイク作成不可)。

## 画面カバレッジ
走行: projects.show, projects.manuals.create, projects.manuals.show, projects.manuals.edit,
projects.manuals.jobs.show, capture.home(/app), capture.manuals.index, capture.manuals.show,
projects.categories (画面), manage.users (403確認のみ)。
未走行: projects.manuals.render-jobs.show / render-jobs.playback / projects.manuals.download
(理由: takes.upload-url 500 の既知ギャップによりrenderが常に事前バリデーション422で停止し
render-job自体が作られないため到達不能。S3総本-1シャード内では既知ギャップ由来の未走行として記録)。

## 操作カバレッジ
実行: projects.store, projects.manuals.store, projects.manuals.source-documents.store(作成時同梱),
projects.manuals.analyze(実LLM、初回+再解析2回), projects.manuals.scenario.update,
projects.manuals.preview, projects.manuals.render(事前バリデーション422まで), projects.categories.store,
projects.categories.update/destroy(403/404確認), projects.members(add)。
未実行 (skip、理由: 既知ギャップ(b)でテイクレコード作成不可): capture.takes.store,
capture.takes.update, capture.takes.destroy, capture.takes.adopt, capture.takes.downloaded,
capture.manuals.sync。capture.takes.upload-url は実行したが500で失敗 (既知ギャップとして記録)。
projects.manuals.update/destroy は自組織内での正常系実行はせず (削除確認は403/404の認可確認のみ)。

## UI/UX 検証 (H11-H14)
- H11 (視覚破綻): manuals.create のファイル選択ボタンはブラウザネイティブ (Choose File 英語表記) だが
  OS標準UIのため対象外。大きな崩れは検出せず。
- H12 (アフォーダンス/状態): シナリオ編集の「未保存の変更があります」インジケータは適切に機能。
  analyze/render の確認ダイアログ (再解析/生成前) は明確。F-1-2 (2つの alert 同時表示で紐付け不明) を検出。
- H13 (レスポンシブ): mobile 375×667 / tablet 768×1024 で manuals.show, capture.manuals.show を確認。
  manuals.show はモバイルでボタン文言が窮屈に折返す軽微な崩れ (finding化せず、実害小)。
  capture.manuals.show は **F-1-3 (High)**: 横スクロール発生・シーン説明文が画面外に切れる
  (mobile 375/tablet 768 両方で再現、scrollWidth 853px)。desktop (1280x800) に復帰済み。
- H14 (a11y基礎): 深掘りは時間の都合上簡易確認に留めた (フォームlabel関連付け・ボタンaria-labelは
  snapshotのrole/name取得から概ね良好。コントラスト/focusリングの精査は未実施、要確認)。

## findings サマリ (最終更新時に severity 別集計)
- Critical: 0
- High: 1 (F-1-3)
- Medium: 2 (F-1-1, F-1-2)
- Low: 0
- 要確認: 0

## 環境ギャップ観察 (finding化せず、既知/運用制約として記録)
- 既知ギャップ(a) ffmpeg未導入: プレビュー/レンダーのジョブが `sh: 1: exec: ffmpeg: not found` で失敗。
  ユーザー向けメッセージは「書き出しに失敗しました。時間をおいて再実行してください。」で生スタックトレース
  なし (H4は満たさない、graceful failure)。
- 既知ギャップ(b) take upload: `capture.takes.upload-url` が実装上 `Storage::disk('s3')` を直接呼び
  region未設定で 500 crash (fake_storage トグルが consumer未実装=inert のため)。想定されていた
  「fake挙動」ではなく実クラッシュだった点を明記 (本走行の主眼メモとの差異)。この結果、S3の撮影〜
  完成動画チェーンの後半 (adopt/sync/render本番/playback/download) と、S7のcross-cut adopt IDOR実データ
  検証が本シャードでは実施不能だった。

## Critical/High 要約 (TODO候補)
- F-1-3 (High): 撮影画面 (capture.manuals.show) がモバイル375px/タブレット768pxで横スクロールし、
  シーン/撮影ポイント説明文が画面外に切れて読めない。再現: `/app/projects/1/manuals/1` を resize 375x667。
  阻害ジョブ: 現場スマホ撮影者が撮影対象を正しく把握できない。改善: 該当flexコンテナに min-w-0 付与 or
  line-clamp+全文表示導線。関連ファイル: 撮影画面のカット一覧コンポーネント (Svelte、未特定)。

---

(finding 詳細は逐次以下に追記)

## F-1-3: 撮影画面 (capture.manuals.show) がモバイル幅 (375px) で横スクロールし、シーン説明文が画面外に切れる
- severity: High (H13: レスポンシブ。撮影 PWA はモバイル端末での利用が前提のため可読性阻害が深刻)
- story/step: S3-7 (capture.manuals.show)
- 再現手順: owner-standard@example.com でログイン → `/app/projects/1/manuals/1` (撮影画面) を開く →
  `playwright-cli resize 375 667` でモバイル幅に変更 → シナリオ一覧の各カット説明文を確認。
- 期待: モバイル幅でも横スクロールなしで全文が折り返し表示される、または `truncate` する場合は
  タップ/展開で全文を読める導線がある。
- 実際: `document.documentElement.scrollWidth` = 853px (viewport 375px) と横スクロールが発生。
  原因要素は `class="truncate text-body"` の `<p>` (シーン/撮影ポイント説明文)。Tailwind の `truncate`
  (text-overflow:ellipsis + overflow:hidden + whitespace:nowrap) が効いておらず、親 flex コンテナに
  `min-width:0` が無いためテキストが省略されずに右へ突き抜けている (典型的な flexbox truncation バグ)。
  結果、説明文の後半 (急所の要点情報を含む) が画面外に流れて読めない。横スクロールしても親要素の
  padding 分ずれて全文を追いにくい。
- 阻害されたユーザージョブ: 現場で手元のスマートフォンから撮影する撮影者 (project_member) が、
  「何を撮ればいいか」の説明文 (シーン・撮影ポイント) を画面内で読み切れず、横スクロール操作を都度
  強いられる。急所の安全上の注意点が見切れるケースもあり得る。
- 改善アクション候補: 説明文コンテナの親 flex 要素に `min-w-0` を付与し `truncate` を機能させるか、
  `line-clamp` + 「全文表示」導線、または折り返し表示 (`whitespace-normal break-words`) に変更する。
- 証跡: screenshots/H13-mobile-capture-show.png, H13-mobile-capture-hscroll.png (横スクロール後)
- 推定原因: 撮影画面のカット一覧アイテムの flex レイアウト (`flex shrink-0` な兄弟要素と `truncate` な
  `<p>` の組み合わせに `min-w-0` 欠落。フロント該当コンポーネント未特定 (5分調査で該当 Svelte ファイル
  未特定、要フロント調査)。
- 関連既知情報: なし (新規)

## F-1-2: 完成動画パネルに前回(プレビュー)失敗アラートと今回(レンダー検証)エラーが積み上がって表示される
- severity: Medium (H10: 文言・状態が直前の操作結果と矛盾/紐付け不明)
- story/step: S3-8 (逸脱: 採用テイクのないカットでレンダー)
- 再現手順: `/projects/1/manuals/1` (準備完了状態、テイク未アップロード) →「プレビュー生成」をクリック
  (ffmpeg未導入のため書き出し失敗、既知ギャップ(a)) → 赤い alert「書き出しに失敗しました。時間をおいて
  再実行してください。」が表示される → 続けて「完成動画を生成」→確認ダイアログ「生成する」をクリック →
  422 (採用テイク未設定のカット一覧を示す新しい alert が追加表示)。
- 期待: 新しい操作(レンダー)のエラーが表示されたら、無関係になった前回(プレビュー)のエラー表示は
  クリアされる、または「どのジョブ/操作の結果か」が明示される。
- 実際: 2つの赤い alert ボックスが同時に積み上がって表示され、上が今回のレンダーバリデーションエラー
  (採用テイク未設定)、下が前回のプレビュー失敗 (ffmpeg起因) だが、見た目上は同種のエラーとして並び、
  どちらがどの操作に対応するか画面上の手がかりがない。
- 阻害されたユーザージョブ: ユーザーが「今何を直せば良いか」を誤認する可能性 (2つ目のエラーが今回の
  操作の追加情報なのか、無関係な残留情報なのか判別できない)。
- 改善アクション候補: 新規アクション実行時に前回の異なる操作由来の alert をクリアする、または
  各 alert に対象操作名 / 発生時刻を明記する。
- 証跡: screenshots/S3-render-two-alerts.png
- 推定原因: 未調査 (プレビュー失敗の render-job エラー state とレンダーの事前バリデーションエラー state が
  別々にコンポーネントへ積まれ、片方がクリアされない可能性)
- 関連既知情報: T032 (scenario stale alert 制御) と近縁だが対象が異なる (今回は render-job / preview 系)。
  新規として記録。

## F-1-1: シナリオ更新 (scenario.update) 成功時に flash/toast 通知が出ない
- severity: Medium (H7: destructive/update操作の結果フィードバック欠如)
- story/step: S3-6
- 再現手順: owner-standard@example.com でログイン → Default Project → 解析済みマニュアル
  (`/projects/1/manuals/1/edit`) を開く → 任意の手順のナレーション欄を編集 → 「未保存の変更があります」
  表示を確認 → 「シナリオを更新」をクリック。
- 期待: マニュアル作成時 (`動画マニュアルを作成しました`) と同様に、更新成功を示す flash/toast が表示される。
- 実際: PUT `/projects/1/manuals/1/scenario` は 200 で返り、「未保存の変更があります」インジケータは消えて
  保存自体は成功している (リロード後も編集内容が保持) が、画面上に成功を明示するフィードバックが一切出ない。
  ページ最上部にも scroll 後の画面にも toast なし。
- 阻害されたユーザージョブ: 保存が成功したのか失敗したのか(ネットワークが遅い環境など)ユーザーが確信を持てず、
  再度「シナリオを更新」を連打する・ページを離れることを恐れる、といった不要な不安/二度手間を招く。
- 改善アクション候補: manuals.store と同じ flash コンポーネントを scenario.update 成功時にも発火させる
  (「シナリオを更新しました」等)。
- 証跡: screenshots/S3-scenario-save-flash-top.png (保存直後、成功メッセージなし)
- 推定原因: 未調査 (フロント側のsuccessハンドラがmanual作成時のみflashを積んでいる可能性)
- 関連既知情報: なし (新規)

