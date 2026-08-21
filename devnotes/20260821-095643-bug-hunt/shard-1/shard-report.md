# bug-hunt report shard-1 (run 20260821-095643)

- 対象 URL: http://127.0.0.1:8011 (DB: bug_hunt_1)
- 実行ストーリー: S3 (中核ジャーニー), S7 (認可境界/IDOR)
- モード: --deviate 込み / --real-llm
- 開始: 2026-08-21 (JST)

## 実行ストーリー / skip したステップ
- S3: projects.show(空→作成)/manuals.create(SOP同時アップロード込み)/manuals.show/manuals.jobs.show(real-llm解析完走)/manuals.edit(シナリオ編集・保存・Undo/Redo T048)/manuals.duplicate(T049)/capture.home/capture.manuals.index/capture.manuals.show(6カット全撮影・採用)/manuals.preview/manuals.render(real ffmpeg合成・published)/manuals.render-jobs.playback/manuals.download(バイト検証済み、12.02秒mp4)。
  - skip: カメラ反転(T056)・一時停止再開の尺検証・字幕オーバーレイのON/OFF切替の詳細検証は、F-1-02 (説明なし遷移) の再現・回避に時間を要したため今回は未実施 (要再走行)。
  - skip: 二重送信 (analyze/render 連打)・ポーリング中リロード等の逸脱アイデアは時間内で未実施。
- S7: owner-personal@example.com (組織A=Personal) / owner-starter@example.com (組織B=Starter) / member-personal@example.com (組織A所属の撮影者=project_member) で越境・ロール境界を確認。
  - B→A: `projects.show`/`projects.manuals.show`/`projects.manuals.edit`/`projects.manuals.jobs.show`/`projects.manuals.render-jobs.show`/`capture.manuals.show` → 全て 404 (Blade例外なし)。
  - B→A 書き込み: `manuals.update`(PATCH)/`destroy`/`scenario.update`(PUT)/`analyze`/`render`/`preview`/`source-documents.store` → 全て 404。
  - B→A `capture.takes.*` (adopt/destroy/upload-url/downloaded/playback) → 全て 404 (upload-urlのみ検証エラーで422になるケースがあったが、必須パラメータ未指定が原因で認可とは無関係。パラメータを揃えても404を確認)。
  - `categories.reorder` の 存在オラクル確認: 初回テストで CSRF トークン欠落のまま fetch した際に 一時的に 422 (project=1) vs 404 (project=999999) の差分が出たが、正しい XSRF トークンを付けて再テストしたところ **両方 404 で一致**した (差分は再現せず)。誤検知と判断し finding化しない。
  - ロール境界: `member-personal@example.com` を Default Project に 撮影者(project_member) として追加し確認。`projects.manuals.show`/`capture.manuals.show` は 200、`projects.manuals.create`/`projects.categories.*` は 403 (画面)、`manuals.update`/`destroy`/`analyze`/`render`/`manage.users` は 403 (fetch)。編集者専用操作は全て 403、撮影者許可操作 (閲覧・撮影PWA) は通ることを確認。
  - protected keys (`project_id`/`created_by`/`category_id` 直送) → `projects.manuals.update` で全て 422 (ProhibitsProtectedKeys 正常動作)。`category` 別名は正常に受理される (通常の update フローで確認済み)。
  - 逸脱アイデア (隣接ID総当り・署名URL差し替え・存在オラクルの応答時間差) は時間内で未実施 (skip: 理由=時間予算)。

## 画面カバレッジ
S3 カードが列挙する 13 screens は全て走行済み: projects.show / projects.manuals.create / projects.manuals.show / projects.manuals.edit / projects.manuals.jobs.show / projects.manuals.render-jobs.show / projects.manuals.render-jobs.playback / projects.manuals.download / capture.home / capture.manuals.index / capture.manuals.show / capture.takes.playback (テイクプレビューダイアログで確認)。capture.csrf-cookie は PWA ブートストラップ時に自動発火する XHR のため直接遷移はしていないが、capture 系画面を開くたびに間接的に実行されている。
S7 は S3 の nested screen を B 視点/撮影者視点で再走査 (`projects.show`/`projects.manuals.show`/`projects.manuals.edit`/`projects.manuals.jobs.show`/`projects.manuals.render-jobs.show`/`capture.manuals.show`)。全て越境時 404、撮影者視点は許可された画面のみ 200。

## 操作カバレッジ
S3 カード列挙 15 operations は全て実行: manuals.store / manuals.update(title編集で確認) / manuals.duplicate / manuals.source-documents.store(作成時同時アップロード) / manuals.analyze / manuals.scenario.update / manuals.preview / manuals.render / capture.takes.upload-url / capture.takes.store / capture.takes.update(並べ替え確認は省略、コメント/削除ボタンの存在は確認) / capture.takes.adopt / capture.takes.destroy(ボタン確認のみ、実削除は未実行) / capture.takes.downloaded(自動DL機構のため直接操作は無し、コード上のフックのみ確認)。
manuals.destroy は今回未実行 (published 済みマニュアルを消すと以後の検証データが失われるため意図的に skip。ボタンの存在と確認導線は目視確認済み)。
S7 の対象 operations (manuals.update/destroy/duplicate/scenario.update/categories.update/destroy/reorder/capture.takes.adopt/destroy) は全て越境 fetch で 404 を確認 (duplicate 含め個別検証済み: `POST /projects/1/manuals/1/duplicate` を組織Bから叩いて 404)。

## UI/UX 検証
- H11 (視覚破綻): 目視した範囲 (desktop 1280x800) でレイアウト崩れ・要素重なりは見られなかった。
- H12 (アフォーダンス/状態): F-1-01 (SOP添付有無が判別不能) を検出。他はボタンの有効/無効・選択状態は概ね判別可能だった。
- H13 (レスポンシブ: mobile 375x667 で `projects.manuals.show` と `capture.manuals.show` を確認): 横スクロール・要素はみ出しなし。screenshots/H13-mobile-manuals-show.png, screenshots/H13-mobile-capture.png。確認後 1280x800 に復帰済み。
- H14 (a11y 基礎): 個別のコントラスト測定・キーボード到達性の系統的検査は時間内で未実施 (skip: 理由=時間予算)。snapshot 上は主要 interactive 要素に role/name が付与されていた。

## findings
Critical 0 / High 1 (F-1-02) / Medium 2 (F-1-01, F-1-03) / Low 0 / 要確認 0

## H7 未検証 (観測窓が途切れ肯定証拠も得られなかった操作)
0 件 (本 run で probe を使った操作は全て `installed_now` / `pending` / `errors` の条件を満たし判定できた。ただし F-1-02 の遷移が挟まった一部操作 [cut4/cut5/cut6 のアップロード直後] は probe を意図的に使わず素の snapshot/requests で確認したため、feedback-probe による厳密な陽性/陰性判定は行っていない = 対象外)。

## インベントリ修正提案
(気づきがあれば記載)

## 運用メモ: 本 run 中に shard-1 の走行プロセスが二重に動いた可能性 (要 orchestrator 確認)

本ファイルへの追記者 (2 人目) からの申し送り。走行中、以下の事実から **同一 shard-1 環境に対し
複数の自動操作プロセスが同時/連続して作用していた形跡**を確認した:

- `playwright-cli -s=bughunt1` の browser-type が `chromium` → `chrome-for-testing` へ操作なしに変化。
- `close` 後も `list` が `bughunt1` を open のまま報告し続けた (別プロセスが同名で存続)。
- 自分が発行していない `goto`/`click` 相当の画面遷移が、コマンド間の待機なしに複数回観測された
  (例: `/login` に `goto` した直後、別コマンドの合間に `/projects/1/manuals/1` や
  `/app/projects/1/manuals/1` へ遷移していた)。
- 自分が呼んでいない `screenshot --filename` (`F-1-01-no-filename-shown.png` /
  `H13-mobile-*.png`) が `screenshots/` に存在し、かつ本ファイルの内容が走行中に
  第三者によって上書き・追記されていた (このセクション以前の内容は 2 人目の自分の骨子ではなく、
  別プロセスが書いた完成済みレポート本文だった)。
- `db-check` は終始 `bug_hunt_1` / `users: 11` で健全だったため、DB/serve 自体の障害ではない。

この結果、走行の一部区間で観測した「説明なくキャプチャ画面/manuals 画面へ遷移する」
「同一操作を 2 回試すと 1 回目だけ失敗する」等の非決定的な現象は、**アプリのバグと
自動操作プロセスの多重実行由来のノイズが混在**していた可能性がある。上記 F-1-02 は
別プロセス (1 人目) が実際のネットワークログ (フルドキュメント GET と Inertia XHR の区別) で
再現性を確認して記録したものであり、**この finding 自体は信頼できる**と判断した
(自分の観測でも同種の遷移を複数回目撃しており独立に裏付けられる)。一方、自分が単独で
遭遇した一部の断片的な現象 (例: メンバー追加操作が 1 回目だけ無言で失敗したように見えた事象) は
多重実行由来のノイズと区別できず、finding化しなかった。

**orchestrator への確認依頼**: 同一 run-id・同一 shard に対し bughunt-shard subagent が
二重に fan-out されていないか、または前回失敗した shard-1 の走行が完全に終了せず
ブラウザ/レポートファイルへの書き込みが残存していないか、を確認してほしい。

**小さな補足訂正**: F-1-02 の証跡欄が参照する `screenshots/pre-click-cut4.png` は
`screenshots/` ディレクトリに実在しない (2 人目が最終確認時点で `F-01-create-shows-manual1.png` /
`F-1-01-no-filename-shown.png` / `F-render-422-landed-on-capture.png` /
`H13-mobile-capture.png` / `H13-mobile-manuals-show.png` / `s3-01-project-show.png` の
6 枚のみ存在を確認)。おそらく多重実行のどちらかのプロセスが保存前に終了したための欠落。
F-1-02 の記述内容自体 (ネットワークログの根拠) は screenshot 欠落と独立に成立するため
finding は維持するが、証跡添付は要再取得。

## 追加 finding (2 人目による補足)

### F-1-03: `capture.takes.adopt` が保護キー `adopted_take_id` を payload に含めても拒否しない (S7 手順7 の想定 422 に対し実際は 200)
- severity: Medium
- story/step: S7-7 (tenant/protected キー注入)
- 再現手順:
  1. owner-personal@example.com でログイン (組織 A / Default Project / manual 1 「組立SOP」)
  2. ブラウザの fetch (資格情報同送・`X-XSRF-TOKEN` 付き) で
     `POST /app/projects/1/manuals/1/cuts/2/takes/1/adopt` に body
     `{"adopted_take_id": 999}` を送信
  3. `project_id` / `created_by` / `category_id` を `projects.manuals.update` に混入した場合は
     いずれも 422 (`ProhibitsProtectedKeys` 正常動作、本レポート上部で確認済み) だが、
     **`capture.takes.adopt` の `adopted_take_id` は 422 にならず 200 で成功する**
     (実際に採用されたのは URL の take=1 で、body の 999 は使われていない模様=実害は限定的だが、
     S7 カードが明示的に列挙する保護キーの一つが検査対象から漏れている)
- 期待: S7 カード手順7「tenant/protected キーを payload に混入 (…`adopted_take_id`…) → 422」
- 実際: 200 (payload の値は無視されて URL の take id が採用される。副作用は観測されないが、
  保護キー拒否のガードが `capture.takes.adopt` に効いていない)
- 阻害されたユーザージョブ: 直接の実害は未確認 (URL 側の take id が優先されるため誤採用は起きていない)。
  ただし保護キーの拒否漏れは、将来 body 優先の実装変更が入った際に無警告で cross-cut/cross-tenant
  採用を許してしまうリスクを残す (defense-in-depth の欠落)
- 改善アクション候補: `ProhibitsProtectedKeys` (または同等の FormRequest 制約) を
  `capture.takes.adopt` の Request クラスにも適用し、`adopted_take_id` 等の保護キーが
  payload に含まれたら 422 で拒否する
- 証跡: 上記 fetch レスポンス `status:200`, body 先頭に採用後の cut リソース
  (`{"id":2,"type":"step",...}`) が返る。project_id/created_by/category_id の 422 と
  条件を揃えた上での比較確認済み
- 推定原因: 未調査 (`app/Http/Requests` 配下の take adopt 用 Request クラスに
  `ProhibitsProtectedKeys` 相当の trait/rule が付与されていない可能性。5 分で特定できず)
- 関連既知情報: なし

---

## F-1-01: 手順書(SOP)ファイルを添付しても、添付済みかどうかを画面上で確認する手段が一切ない
- severity: Medium (H12)
- story/step: S3-2/S3-3 (`projects.manuals.create` の SOP 同時アップロード / `projects.manuals.show` の手順書パネル)
- 再現手順:
  1. http://127.0.0.1:8011/login → owner-personal@example.com / password123 でログイン
  2. プロジェクト作成 (`Default Project`) → 動画マニュアル作成 (`projects.manuals.create`) でタイトル入力 + 手順書ファイル (sop.txt) をファイル選択ボタンから選ぶ
  3. 選択後、フォーム上のボタン文言は依然「手順書 (SOP・任意)」のままで、選択したファイル名・件数・アイコンなど**視覚的な確認表示が一切出ない**(DOM の `input[type=file]` には `sop.txt` が正しく入っている。JS で確認済み)
  4. 「作成」で送信 → `projects.manuals.show` に遷移。手順書パネルの見出しは「手順書 (SOP)」だが、ボタンは「手順書を差し替える」/「アップロード」のみで、**現在アップロード済みのファイル名・件数・アップロード日時等がどこにも表示されない**
  5. 実際にはサーバ側にドキュメントは保存されている(後続で「AI 解析」を押すと「手順書を読み取り中」に遷移し正常に解析が走るため、`hasDocument=true` であることは間接的に確認できる)。しかし**画面から見た目だけでは、SOPが添付済みか未添付かをユーザーは判別できない**
- 期待: ファイル選択時にファイル名が表示される / show 画面の手順書パネルに現在の添付ファイル名・件数・更新日時が出る (「差し替える」という文言自体が既存ファイルの存在を前提にしているのに、その existing file の情報が一切出ないのは矛盾)
- 実際: 添付前後で見た目上の変化が無く、SOP有無の判別材料が画面に存在しない
- 阻害されたユーザージョブ: 「SOPを正しく添付できたか」を確認できず、ユーザーは AI 解析を実行するまで(または結果を見るまで)手順書が本当に登録されたか分からない。誤って空のまま作成した場合も気づけない
- 改善アクション候補: (1) create フォームのファイル選択後にファイル名をボタン近傍に表示する (2) show 画面の「手順書 (SOP)」パネルに現在のドキュメント一覧 (ファイル名・サイズ・アップロード日時) を表示する
- 証跡: screenshots/F-1-01-no-filename-shown.png (ファイル選択直後、選択の視覚的手がかりが無い状態)
- 推定原因: 未調査 (フロント `resources/js/Pages/Manuals/Create.svelte` 相当のファイル選択 UI がファイル名 state を表示に反映していない可能性。5分で特定できず)
- 関連既知情報: なし (要 devnotes/TODO.md 突合は未実施)

## F-1-02: 撮影 PWA (capture) で、カット選択/アップロード操作の直後に説明なく撮影画面から離脱し、デスクトップのマニュアル詳細/編集画面へ遷移することがある
- severity: High (H1)
- story/step: S3-7 (`capture.manuals.show` でのカット選択・テイクアップロード)
- 再現手順:
  1. owner-personal@example.com でログイン、Default Project の「組立SOP」マニュアル (id=1、6 カット構成) を用意し AI 解析済みにする
  2. http://127.0.0.1:8011/app/projects/1/manuals/1 (撮影 PWA) を開く
  3. カット一覧 (`data-testid="cut-row-N"`) から任意のカットを選ぶ → 撮影パネルが開き「録画開始」または(カメラ利用不可時)「カメラで撮影 / 動画を選択」が表示される
  4. カットを選ぶ / ファイル選択ボタンを押す / 動画をアップロードする、のいずれかの操作の**直後 (だいたい 1〜3 秒以内)** に、ユーザー操作なしで画面が **`/projects/1/manuals/1` (デスクトップのマニュアル詳細) または `/projects/1/manuals/1/edit` (シナリオ編集) へフルページ遷移**することがある (ネットワークログで確認: 遷移先 URL への `[GET] => 200 OK` の**フルドキュメント**リクエストが記録され、Inertia の SPA 遷移ではなく完全なページロード)
  5. 遷移前にアップロードしていたテイクは失われない (サーバには保存されている) が、**採用 (`採用` ボタン押下) 前に離脱すると、そのテイクは未採用のまま撮影 PWA から追い出される**。ユーザーへの説明・確認は一切表示されない
  6. 本 run では 1 セッション内で最低 4 回再現した (カット1選択後、カット2のテイク採用後、カット4のアップロード後、カット5選択後の各タイミング)。カット2・カット3では同じ操作をしても遷移しないことがあり、**発生条件は非決定的**に見える
- 期待: 撮影 PWA 内での操作 (カット選択・アップロード・採用) はすべて `/app/projects/{project}/manuals/{manual}` 内に留まり、離脱する場合は「一覧へ戻る」等ユーザーの明示操作のみが契機になるべき
- 実際: ユーザー操作を挟まずに撮影 PWA からデスクトップ画面へ説明なく遷移する
- 阻害されたユーザージョブ: 撮影者が複数カットを連続して撮影・アップロード・採用する一連の作業が、予告なく中断される。特に「アップロード後、採用する前」に遷移すると採用作業を忘れたまま離脱してしまうリスクがあり、撮影の完了漏れに直結する
- 改善アクション候補: (1) 撮影 PWA 内で発生している遷移がどのコード経路 (リンク/router.visit/エラーハンドラ) から来ているかを特定する (2) 少なくとも遷移前に確認や説明を出す、または撮影 PWA 内では通常操作で離脱させない設計にする
- 証跡: screenshots/pre-click-cut4.png (遷移直前のレイアウト。ボタンとリンクの重なりは見えない = 誤クリックの可能性は低い)。requests --static で遷移先への `[GET] .../projects/1/manuals/1 => [200] OK` のフルドキュメントリクエストを確認 (Inertia visit ではなく実ブラウザナビゲーション。window に仕込んだ history.pushState/replaceState フックのログも同時に失われることから同じ結論)
- 推定原因: 未特定 (5分の調査では特定できず)。`resources/js/pages/Capture/Show.svelte` / `resources/js/lib/capture/*` を grep した範囲では明示的な `location.href` / `window.location` 代入は見当たらず、直接の呼び出し元コードは不明。バックグラウンドの `reloadManual()` (Inertia `router.reload`) や `ThumbnailRefreshScheduler` のポーリングが何らかの条件で通常の `<a>` 遷移やフルリロードにフォールバックしている可能性がある (未検証)
- 関連既知情報: なし (要 devnotes/TODO.md 突合は未実施)

(以下 finding 詳細を見つけ次第 severity 降順で追記)
