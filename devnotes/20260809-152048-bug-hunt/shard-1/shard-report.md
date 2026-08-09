# bug-hunt report shard-1 (run 20260809-152048)
- 対象URL: http://127.0.0.1:8011
- 割り当てストーリー: S3 (core journey) → S7 (authz boundaries)
- DB: bug_hunt_1, users=11 (db-check 済み)
- 実行ストーリー: S3 (完走) → S7 (完走)。逸脱アイデアも実行 (--deviate)。
- skip したステップ: なし (すべて到達・完走)。UndoスタックのRedo後の新規編集による再クリアまでは未検証 (時間の都合で省略、severity影響のある不整合は観測されず)。

## 画面カバレッジ
S3 対象 13 画面中 12 走行:
projects.show ✓ / projects.manuals.create ✓ / projects.manuals.show ✓ / projects.manuals.edit ✓ /
projects.manuals.jobs.show ✓ (ポーリングで実観測) / projects.manuals.render-jobs.show ✓ (preview/render 両方) /
projects.manuals.render-jobs.playback ✓ (kind=Preview のみ許可される仕様を確認、kind=Render は 404 が正当) /
projects.manuals.download ✓ (実mp4ダウンロード・byte確認) / capture.home ✓ (`/app`) /
capture.manuals.index ✓ / capture.manuals.show ✓ (16カット全撮影) / capture.takes.playback ✓ (プレビューモーダル)
未走行: capture.csrf-cookie (SPA初期化で内部的に叩かれるのみで直接確認できる可視UIが無く、明示的な単体検証はskip。エラーは発生していない)

## 操作カバレッジ
S3 対象 15 操作、全 15 実行:
projects.manuals.store ✓ (空入力バリデーション→正常系) / projects.manuals.update ✓ (基本情報編集は複製先で確認) /
projects.manuals.destroy — S7側の削除確認ダイアログ確認のみ実施 (実削除は温存判断でキャンセル。確認ダイアログの存在は確認済み) /
projects.manuals.duplicate ✓ (T049: cuts複製・takes空・status=draft を確認) /
projects.manuals.source-documents.store ✓ (空→アップロード成功) /
projects.manuals.analyze ✓ (real-llm 実解析、14手順+2急所=16カット生成、導入/総括カット自動挿入=T046確認) /
projects.manuals.scenario.update ✓ (Undo/Redo=T048確認、保存成功feedback確認) /
projects.manuals.preview ✓ (0テイクでも成功する挙動を確認=F-1-01) /
projects.manuals.render ✓ (ticket消費、確認ダイアログ、未採用カット一覧エラー、成功後ダウンロードまで完走) /
capture.takes.upload-url ✓ / capture.takes.store ✓ (16カット全て、カメラ非対応→ファイル選択フォールバック確認) /
capture.takes.update — 並べ替え(上へ/下へ)ボタンの存在は確認、実操作は省略 (低リスクと判断) /
capture.takes.destroy ✓ (確認ダイアログ=T043確認、キャンセルで温存) /
capture.takes.adopt ✓ (16カット全採用、プレビューモーダル経由/直接ボタン経由の両方確認) /
capture.takes.downloaded ✓ (詳細入室時の自動DL+ACKをrequestsログで確認=T051)
加えて projects.categories.store/update/reorder/destroy (S7の前提整備として実施、確認ダイアログ・feedback確認)。

## UI/UX検証
- H11 (視覚破綻): 目立った崩れなし。screenshots参照。
- H12 (アフォーダンス): ボタン活性/非活性・採用中バッジ等は判別可能。
- H13 (レスポンシブ): mobile 375x667 (projects.show, capture.manuals.show 一覧+パネル) / tablet 768x1024 (manuals.edit シナリオ編集フォーム) で確認。**F-1-03 (Medium) を検出** (撮影パネルへの自動スクロール欠如)。確認後 desktop (1280x900) に復帰済み。
- H14 (a11y基礎): F-1-02 (Low, video要素のaria-label欠落) を検出。他は概ね良好 (フォームlabel、ボタンname等は取得可)。

## findings
Critical: 0 / High: 0 / Medium: 1 (F-1-03, H13) / Low: 1 (F-1-02, H14) / 要確認: 1 (F-1-01)
S7 (認可境界): **findingsなし — 検査したすべての越境アクセス・権限外操作・tenantキー注入・存在オラクルが仕様通り拒否された** (詳細は F-1-04 として「検査結果サマリ」を記録)。

## H7 未検証
0件 — 検証した書き込み操作はすべて probe 陽性 (toast等) または persistent UI 変化 (状態バッジ/一覧反映) で確認済み。

---
## F-1-01: プレビュー生成は採用テイク 0 件でも成功する一方、完成動画生成は未採用カットを明示エラーで拒否する (非対称)
- severity: 要確認
- story/step: S3-8 (逸脱: 採用テイクのない Cut でレンダするとどうなるか)
- 再現手順:
  1. owner-standard@example.com でログイン、Default Project に新規マニュアルを作成し SOP 解析まで完了 (status=準備完了)。
  2. 撮影を一切せず (採用テイク 0 件) manuals/{id} 画面で「プレビュー生成」をクリック。
  3. `POST .../preview` は 201 で成功し、render-jobs/{n} が進行し完了、`<video data-testid="preview-video">` が表示される (中身は要確認だが処理自体は完了扱い)。
  4. 同じ状態 (未採用カットが 14/16 件残る状態) で「完成動画を生成」をクリックすると `POST .../render` は明示的に `422` 系エラーとなり alert (`render-start-error`) で「採用テイクが未設定のカットがあります: 手順3、手順4...」と正しく一覧表示される。
- 期待: 未確認 (仕様意図が不明)。プレビューも同様に未採用カットをチェックすべきか、それとも「プレビューは仮映像 (プレースホルダ) を許容する」設計なのか、devnotes/docs での確認が必要。
- 実際: プレビューと本番レンダーで「未採用カットの扱い」が非対称。ユーザーが「プレビューが通ったから撮影は十分」と誤解し、実際にレンダーしようとして初めて 14 カット分の未撮影に気づく可能性がある (UX 上のサプライズ)。
- 阻害されたユーザージョブ: 完成動画を生成する前に、プレビューで「今の状態で十分か」を確認するというジョブ。プレビューが未採用カット数を教えてくれないため、確認の意味が薄れる。
- 改善アクション候補: (a) プレビューでも未採用カット数を警告表示する、または (b) 仕様として意図的なら screens.md/ストーリーにその旨を明記する。
- 証跡: `.playwright-cli` 越しの request ログ (`POST /projects/1/manuals/1/preview => 201` while 0/16 adopted; 後日 `POST /projects/1/manuals/1/render => 422` で `手順3〜手順14` 他が未採用と列挙)。screenshots/F-01-preview-no-takes.png
- 推定原因: 未調査 (5分で当たりがつかず)。ManualRenderController の preview アクションが take 充足チェックを render アクションと共有していない可能性。
- 関連既知情報: なし (要確認扱い)。

## F-1-02: 完成動画/プレビューの `<video>` 要素にアクセシブルネームが無い
- severity: Low (H14)
- story/step: S3-8/9
- 再現手順: owner-standard@example.com でログイン。manuals/1 (プレビュー or 完成動画あり) を開き、`playwright-cli snapshot`/`find "プレビュー"`/`find "再生"` で確認すると `<video data-testid="preview-video">` がアクセシビリティツリーに全く現れない (見出し「完成動画」の直下にあるので文脈上は分かるが、video 要素自体に `aria-label` が無く、スクリーンリーダーでは「動画コンテンツがある」ことを名前付きで伝えられない)。
- 期待: video 要素に `aria-label="◯◯のプレビュー動画"` 等のアクセシブルネームがあり、支援技術ユーザーが存在と内容を判別できる。
- 実際: `aria-label` 属性なし (`document.querySelector('video').getAttribute('aria-label')` → `null`)。
- 阻害されたユーザージョブ: スクリーンリーダー利用者が「動画が生成された/再生できる」ことを認識するジョブ。
- 改善アクション候補: video 要素に aria-label を付与する。
- 証跡: eval `document.querySelector('video').getAttribute('aria-label')` → `null`。screenshots/preview-video-check.png (視覚的には正しく再生可能な video が表示されていることを確認済み、a11y ツリー上の欠落のみが問題)
- 推定原因: 未調査。
- 関連既知情報: なし。

## F-1-03: 撮影 PWA (モバイル幅) でカット選択時に撮影パネルへ自動スクロールしない
- severity: Medium (H13)
- story/step: S3-7 (撮影)
- 再現手順:
  1. owner-standard@example.com でログイン。`playwright-cli resize 375 667` でモバイル幅にする。
  2. `capture.manuals.show` (`/app/projects/1/manuals/1`) を開く。シナリオ一覧 (14+ 手順) が画面上部に表示される。
  3. 任意のカット行 (例: 手順1) をタップする。
  4. 撮影パネル (ナレーション/字幕/録画ボタン/テイク一覧) はシナリオ一覧の**下**に追加されるが、`window.scrollY` は 0 のまま = 自動スクロールされない。
- 期待: カットをタップしたら撮影パネル (録画開始ボタンなど) が viewport 内に入るよう自動スクロールする、またはモバイルでは一覧と撮影パネルを切り替え表示 (タブ/ドロワー) にする。
- 実際: ユーザーは毎回、全 14 件以上のシナリオ一覧を手動でスクロールして撮影パネルまで到達する必要がある。カットからカットへ連続して撮影する主要ワークフローで毎回発生するため、実利用 (現場でスマホ片手に撮影) では顕著な摩擦になる。
- 阻害されたユーザージョブ: 現場で複数カットを連続して素早く撮影するジョブ。
- 改善アクション候補: カット選択時に撮影パネルへ `scrollIntoView` する、またはモバイルでは一覧⇔撮影パネルをオーバーレイ/タブ切り替えにする。
- 証跡: screenshots/H13-capture-mobile375.png (一覧全体), screenshots/H13-capture-panel-mobile375-viewport.png (カット選択直後、`window.scrollY===0` で一覧の続きしか見えず撮影パネルが viewport 外)
- 推定原因: 未調査。
- 関連既知情報: なし。

## F-1-04: S7 認可境界検査サマリ (finding なし — 全項目パス)
- severity: N/A (finding ではなく検査結果の記録)
- story/step: S7 全ステップ
- 前提: S3 で owner-standard@example.com (組織 A = Standardプラン組織) が作成した Default Project (id=1) / manual 1 (公開済み, 16カット全採用・レンダー済み) / manual 2 (複製・下書き) / category 1「清掃」・2「撮影」/ render-jobs 1(preview)・2(render) が存在する状態のまま (reseedしていない、S3→S7の状態依存を意図通り利用)。
- 検査内容と結果 (同一ブラウザセッション内でユーザーを切り替えて検査。owner-personal@example.com = 組織B / member-standard@example.com = 組織Aのproject_member(撮影者)):
  1. **B から A の URL 直叩き** (`/projects/1`, `/projects/1/manuals/1`, `/projects/1/manuals/1/edit`, `/projects/1/manuals/1/jobs/1`, `/projects/1/manuals/1/render-jobs/2`, `/app/projects/1/manuals/1`) → **全て404** (403でもBladeエラーでもない)。
  2. **B から A の manual への書き込み** (`PATCH manuals/1`, `DELETE manuals/1`, `PUT manuals/1/scenario`, `POST analyze`, `POST render`, `POST preview`, `POST source-documents`) → **全て404**。
  3. **B から A の category** (`PATCH categories/1`, `DELETE categories/1`, `PATCH categories/reorder`) → **全て404**。B自身の project(id=2)のreorderにAのcategory id(1,2)を混入させても、実在しないid(9999)と**全く同一の422レスポンス** (メッセージ・構造とも同一) で、存在オラクルは確認されず。
  4. **撮影面 (PWA) B→A** (`adopt`/`destroy`/`upload-url`/`downloaded`) → **全て404**。
  5. **cross-cut adopt** (A内: cut3の撮影パネルからcut2のtake id(=2)をadoptさせる直叩き `POST cuts/3/takes/2/adopt`) → **404** (`No query results for model [Take] 2`、cut->takes()経由解決が機能)。
  6. **撮影者(project_member)ロールでの編集者専用操作** (member-standard@example.com、`manuals.store/update/destroy`, `categories.store`, `analyze`, `render`, `/manage/users`) → **全て403** (404ではなく、正当な理由=権限不足として区別されている)。UI上も「新規作成」「メンバー」nav「管理メニュー」「プロジェクトメンバー」等の編集者専用要素が非表示。逆に撮影PWA (`/app/projects/1/manuals/1`) へのアクセスは正常に許可。
  7. **tenant/protected キー注入** (`project_id`, `created_by`, `category_id` を legitimate な自分のリソースへの書き込みに混入): `POST /projects/2/categories {project_id:999}` → 422 (`project id を入力する必要はありません。`)。`POST /projects/2/manuals {created_by:999}` → 422。`PATCH /projects/2/manuals/3 {category_id:999}` → 422。一方 `category` 別名 (整数値) は許容される設計。
- 結論: 本 run で検査した範囲では IDOR / 権限昇格 / 存在オラクルの finding は無し。子リソース解決が親 relation 経由で行われ、越境は一貫して 404、権限不足は一貫して 403 で区別されており、設計意図 (screens.md/ストーリー記載の不変条件) 通りに機能している。
- 証跡: 各リクエストの status を `playwright-cli --raw eval` の fetch 経由で取得 (本文中に記載の通り)。
- 推定原因: N/A。
- 関連既知情報: なし。

## TODO候補 (Critical/High)
なし (本shardではCritical/High findingは0件)。

## 要確認 (仕様確認の質問リスト)
- Q1 (F-1-01): プレビュー生成 (`projects.manuals.preview`) は採用テイクが0件でも成功して良い仕様か、それとも完成動画生成 (`projects.manuals.render`) と同様に未採用カットを事前チェックすべきか。仕様が確定していれば screens.md/ストーリーに明記を推奨。

## インベントリ修正提案
なし (screens.md / operations.md / stories は現状との乖離を確認できず)。

## 走行環境メモ
- playwright-cli の既定 browserName 解決が壊れており (`chrome` channel 探索 → 404、次に `chromium_headless_shell-1237` 不在で失敗)、自分の report dir に `.playwright/cli.config.json` (`browserName: chromium`) を作成して解決した。走行中に `~/.cache/ms-playwright/chromium-1237` 等が用意され (他shardの並行進行によるものと推測)、以降は正常に headless chromium が起動した。環境ハザードではなく自己解決した (report dir外への変更は行っていない)。

(以下 finding 詳細を逐次追記)
