# bug-hunt report shard-1 (run 20260812-100645)

- 対象 URL: http://127.0.0.1:8011 (DB: bug_hunt_1)
- 実行ストーリー: S3 (core-journey) → S7 (authz-boundaries)
- モード: --deviate 有効 / --coverage 有効 / real-llm
- 開始: db-check OK (db=bug_hunt_1, users=11)

## 進捗ログ
- S3 完走 (projects 作成 → manual 作成/SOP添付 → AI解析(real-llm, 20カット生成, T046導入/総括カット確認) → シナリオ編集/Undo/Redo(T048) → 保存 → 複製(T049) → 撮影PWA (T054文脈リンク, T047字幕トグル, グリッド, カメラ不可フォールバック, T051自動DL+ACK) → 全20カットにテイクアップロード/採用 → プレビュー生成 → 完成動画レンダー(render チケット消費、確認ダイアログ) → published → ダウンロード(byte有効なmp4確認) → manuals.update/destroy → categories 作成/reorder/削除確認 → H13 mobile/tablet 確認 実施
- S7 完走: A=owner-personal@example.com (Personal組織, project 1), B=owner-starter@example.com (Starter組織, project 2), member=member-personal@example.com (Personal組織 project_member/撮影者) の3アカウントで境界検査。
  - 手順1: B から A の projects.show/manuals.show/manuals.edit/manuals.jobs.show/render-jobs.playback(直URL)/capture.manuals.show を直叩き → 全て 404 (ページが見つかりません、Blade エラーなし)
  - 手順2: B から A の manual への write (PATCH/DELETE manuals, PUT scenario, POST analyze/render/preview/source-documents/duplicate) を fetch+CSRF で実行 → 全て 404
  - 手順3: B から A の category への write (PATCH/DELETE/reorder) → 全て 404。reorder に A の実在 category id を混ぜた場合と存在しない id を混ぜた場合で status(422)・応答時間(58ms/54ms)・メッセージが同一 → 存在オラクルなし
  - 手順4: B から A の capture.takes.* (upload-url/store/update/destroy/adopt/downloaded/playback) → 全て 404
  - 手順5: A 自身のセッション内で cut 1 の take を cut 2 の adopt に渡す (`POST cuts/2/takes/1/adopt`) → 404 で正しく拒否 (ただし F-1-03 参照: レスポンス body に内部クラス名が漏れる)
  - 手順6: member-personal (撮影者) で編集者専用操作 (manuals.store/update/destroy, categories.*, analyze, render, manage.users) を試行 → 全て 403 (JSON body は "This action is unauthorized." の汎用メッセージで内部漏れなし)。projects.show と capture.manuals.show (撮影) は 200 で正しくアクセス可
  - 手順7: category 別名 (`category: null`) は 200 受理、`category_id` 直送は 422 (ProhibitsProtectedKeys, "category id を入力する必要はありません")。project_id/created_by も同様に 422 で拒否確認
  - 逸脱: 署名付き fake-storage URL の key を A の manual 1→2 に書き換え (signature 据え置き) → 403 Forbidden (署名検証が有効)。B から project id 1/3/4/5 の隣接 ID 総当り → 全 404 (存在有無で応答差分なし)。B 自身の project 2 配下で manual id 1 (Aのもの) / 2 / 4 を直叩き → 404、B 自身の manual (id 3) のみ 200 (親子関係経由の解決を確認、グローバル ID lookup でないことを確認)
  - 結論: 認可境界の侵害 (IDOR) は検出されず。唯一の弱点は F-1-03 (404 JSON レスポンスでの内部クラス名漏え、認可自体は健全)

## 画面カバレッジ
- 走行 12 / screens.md 対象 (S3) 13: projects.show(○), projects.manuals.create(○), projects.manuals.show(○), projects.manuals.edit(○), projects.manuals.jobs.show(○ポーリング経由), projects.manuals.render-jobs.show(○ポーリング経由), projects.manuals.render-jobs.playback(△: render-jobs 個別 playback URL は明示未確認、manuals.download で完成動画再生は確認), projects.manuals.download(○), capture.home(○ `/app` 経由), capture.csrf-cookie(△ 明示未確認、SPA内で暗黙に発行されている前提), capture.manuals.index(○), capture.manuals.show(○), capture.takes.playback(○)
- 未走行/要確認: projects.manuals.render-jobs.playback の直接 URL 確認、capture.csrf-cookie の単独確認 (どちらも機能上は他導線で間接確認済み、skip 理由: 時間配分。実害を疑う所見なし)

## 操作カバレッジ
- 実行 14 / operations.md 対象 (S3) 15: projects.manuals.store(○), projects.manuals.update(○), projects.manuals.destroy(○), projects.manuals.duplicate(○), projects.manuals.source-documents.store(○), projects.manuals.analyze(○ real-llm), projects.manuals.scenario.update(○), projects.manuals.preview(○), projects.manuals.render(○ 20/20カット時に成功、19/20時は明確なエラーで拒否), capture.takes.upload-url(○), capture.takes.store(○), capture.takes.update(○ コメント), capture.takes.destroy(○ 試行→ダウンロード済みテイクのため業務ルールで422拒否、明確なメッセージ), capture.takes.adopt(○), capture.takes.downloaded(○ T051自動ACK)
- 追加確認 (S3外だが同一画面): projects.categories.store/update/reorder/destroy(すべて動作確認、削除は確認ダイアログのみでキャンセルしデータ保持)
- S7 越境確認 9/9: projects.manuals.update(○404), projects.manuals.destroy(○404), projects.manuals.duplicate(○404), projects.manuals.scenario.update(○404), projects.categories.update(○404), projects.categories.destroy(○404), projects.categories.reorder(○404,オラクルなし), capture.takes.adopt(○404, cross-cutも404), capture.takes.destroy(○404)
- S7 ロール境界: manuals.store/update/destroy/analyze/render/categories.*/manage.users を撮影者(project_member)で試行 → 全 403。projects.show/capture.manuals.show は 200 (期待通り)
- S7 protected keys: project_id/created_by/category_id 直送 → 422、category 別名 → 200 許容 (期待通り)

## UI/UX 検証 (H11-H14)
- H11 (視覚破綻): 目立った崩れなし (desktop/mobile/tablet とも)
- H12 (アフォーダンス): 概ね明確。採用中/DL済み/未撮影などバッジで状態表現。テイク削除ボタンは常に active だが業務ルールで拒否されるケースあり (F候補未満、既知の設計と判断)
- H13 (レスポンシブ): mobile 375x667 (manuals.show) / tablet 768x1024 (capture.manuals.show) を確認、崩れ・オーバーフローなし。screenshots/H13-mobile-manual-show.png, screenshots/H13-tablet-capture.png
- H14 (a11y): 主要ボタンに aria-label/testid あり、role=dialog/alert/status が適切に使われている。個別のコントラスト測定は未実施 (skip: ツール制約)

## findings
- Critical 0 / High 0 / Medium 2 (F-1-02:H10, F-1-03:H4寄り) / Low 1 (F-1-01) / 要確認 0
- IDOR/認可境界 (S7 全項目): 侵害なし。侵入テストの結果は上記「進捗ログ」に記録

## H7 未検証
- 0 件 (すべての書き込み操作で feedback probe の陽性証拠、または視覚的な状態変化 [採用中/DL済み/コメント表示等] による肯定証拠を得た)

## Critical/High TODO 候補
- 該当なし (Critical/High findingsなし)

## Medium/Low 要対応候補 (TODO化検討)
- F-1-02 (Medium/H10): 公開済み完成動画の直下に古い黒背景プレビュー警告が矛盾したまま残留。再現: `devnotes/20260812-100645-bug-hunt/shard-1/screenshots/F-02-stale-preview-black-message.png`。改善: プレビューのカバレッジ算出をテイク採用状態の変化時に再評価する、または生成時刻を明示
- F-1-03 (Medium/H4寄り): JSON 404 レスポンスに `App\Models\Take` 等の内部クラス名が漏れる (HTML 404 は適切に日本語化済みなのと非対称)。改善: 例外ハンドラで `ModelNotFoundException` の JSON レンダリングも汎用メッセージに統一
- F-1-01 (Low): プロジェクト作成フォームでエラー表示後の入力でエラーが消えない (他フォームは正常にクリアされる非対称)。screenshots/F-01-project-create-stale-error.png

## インベントリ修正提案
- (無ければ「なし」)

## 環境ハザード (EH)
- (無ければ「なし」)

---
(以下 finding 詳細を severity 降順で逐次追記)

## F-1-01: プロジェクト作成フォームで、必須エラー表示後に有効な値を入力してもエラー表示が消えない
- severity: Low
- story/step: S3-1 (projects.create, `/projects/create`)
- 再現手順:
  1. http://127.0.0.1:8011/projects/create を開く (owner-personal@example.com / password123 でログイン済み)
  2. 「プロジェクト名」を空のまま「作成」を押す → 「プロジェクト名は必須項目です。」と表示され `[invalid]` になる
  3. 「プロジェクト名」に "Default Project" と入力する (blur せず)
  4. 入力後も「プロジェクト名は必須項目です。」のエラー文言と `[invalid]` 状態が消えないまま残る
  5. その状態のまま「作成」を押すと実際には成功し `/projects/1` へ遷移、toast「プロジェクトを作成しました」も出る (feedback probe で確認)
- 期待: 有効な値を入力した時点でエラー表示が消える (S3 カードが同種のパターンを他フォームで明示的に要求している: 「タイトル必須エラー表示後に入力すると即座にエラーがクリアされる」)
- 実際: エラー文言が入力後も残留し、ユーザーはまだ無効だと誤認しうる (実際には送信すれば成功する) → 誤ってフォームを諦める / 無駄な再確認をするリスク
- 阻害されたユーザージョブ: プロジェクト作成という単純な操作で、入力の正誤判断がつかず自信を持って送信できない
- 改善アクション候補: クライアント側バリデーション表示を input イベントで再評価し、有効になった時点でエラー表示をクリアする
- 証跡: screenshots/F-01-project-create-stale-error.png
- 推定原因: 未調査 (フロントの validation state が blur/submit 時のみ再評価され、input イベントでは再評価されない実装と推測)
- 関連既知情報: なし (要 TODO 化候補)

## F-1-02: 完成動画が published になった後も、直下に「20件のカットに採用テイクがなく黒背景」という古いプレビューと矛盾する文言が残留する
- severity: Medium (H10)
- story/step: S3-8 (projects.manuals.show, `/projects/1/manuals/1`)
- 再現手順:
  1. owner-personal@example.com / password123 でログイン、Default Project (id=1) にマニュアル「手順書テスト」(id=1) を作成、AI 解析で 20 カット生成
  2. 一度だけ「プレビュー生成」を実行 (このとき採用テイク 0 件のため、20/20 カットが黒背景になる警告とプレビュー動画が表示される)
  3. その後、撮影PWA (`/app/projects/1/manuals/1`) で 20 カット全てにテイクをアップロード・採用する
  4. マニュアル詳細に戻り「完成動画を生成」→ render 完了 (status=公開済み)、完成動画のダウンロードリンクが表示される
  5. その直下に、手順2で生成した古いプレビュー動画と「このプレビューは 20 件のカットに使用できる採用テイクがないため、その区間が黒背景になっています。」という文言がそのまま残っている
- 期待: 全カットにテイクが採用され本編が公開された後は、古い「黒背景警告」文言がそのまま表示され続けない (少なくとも「このプレビューは生成時点のものです」等、現在の状態と矛盾しないよう区別されるべき)
- 実際: 公開済みの完成動画のすぐ下に「20件のカットに採用テイクがない」という文言が残り、ユーザーが本当に全カット撮影済みか混乱する (実際には全カット採用済みで動画も正しく生成されている)
- 阻害されたユーザージョブ: 完成動画が本当に全カットを反映しているかの確認 (撮影完了の達成感・信頼性が損なわれる)
- 改善アクション候補: プレビューはカット構成やテイク採用状況が変わった時点で「古くなった」旨を明示するか、再生成を促す導線を出す。または黒背景警告文言をプレビュー生成時点のスナップショットとして日時を明示する
- 証跡: screenshots/F-02-stale-preview-black-message.png (完成動画ダウンロードボタンの直下に矛盾する文言と黒背景プレビューが見える)
- 推定原因: 未調査 (プレビューの「黒背景カット一覧」文言がプレビュー生成時点のサーバレスポンスをそのままキャッシュ/保持し、その後のテイク採用状態を再評価していないと推測)
- 関連既知情報: なし

## F-1-03: 404 の JSON レスポンスに Eloquent の内部クラス名を含む生の例外文が漏れる (ブラウザ表示の 404 ページは適切に日本語化されているのと非対称)
- severity: Medium (H4 寄り、実害は限定的だが情報漏えい)
- story/step: S7-5 (cross-cut adopt 越境確認、および一般の 404 API レスポンス)
- 再現手順:
  1. owner-personal@example.com / password123 でログイン。project 1 / manual 1 に 20 カットあり、cut 1 の take id=1 が採用済み
  2. 認証済みセッションのまま、fetch で `POST /app/projects/1/manuals/1/cuts/2/takes/1/adopt` (cut 1 の take を cut 2 の adopt に渡す。Accept: application/json, X-Requested-With: XMLHttpRequest 付き) を叩く
  3. レスポンスは正しく `404` だが、body が `{"message": "No query results for model [App\\Models\\Take] 1"}` という Eloquent の生例外文
  4. 同様に単純な存在しない ID (`GET /app/projects/1/manuals/99999` に同ヘッダ) でも `{"message": "No query results for model [App\\Models\\VideoManual] 99999"}` が返る
  5. 一方、ブラウザで直接 URL 遷移した 404 (`/projects/1` を B から開く等) は「ページが見つかりません」という日本語の友好的なエラーページになっており、JSON API 経路とブラウザ経路で情報漏えいの非対称がある
- 期待: 404 の JSON レスポンスも内部クラス名 (`App\Models\Take` 等) や生の英語例外文を含まない、汎用化されたエラーメッセージを返す
- 実際: `App\Models\Take` / `App\Models\VideoManual` のような内部実装のネームスペース構造がそのまま露出する。認可バウンダリ自体 (404 で弾かれる) は破られていないが、内部構造の探索材料を攻撃者に与える
- 阻害されたユーザージョブ: 直接の UI 操作では到達しない経路 (API 直叩きが必要) のため、通常ユーザーの体験には影響しない。API/セキュリティ的な硬さの問題
- 改善アクション候補: `App\Exceptions\Handler::render()` 等で `ModelNotFoundException` を JSON レスポンス時も汎用メッセージ (例: 「リソースが見つかりません」) に変換する。既に HTML 経路では実施済みのため、JSON 経路にも同じマッピングを適用すればよい
- 証跡: (screenshot 不可、fetch レスポンス文字列を上記に転記。再現コマンドは shard 側 `/tmp/cross_cut_test.js` 相当の fetch)
- 推定原因: 未調査 (JSON/XHR リクエスト時は Handler のデフォルト `ModelNotFoundException` レンダリングがそのまま素通りし、HTML 経路のみカスタムの 404 ビューが使われている構成と推測)
- 関連既知情報: なし


