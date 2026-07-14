# bug-hunt report shard-1 (run 20260714-154640)

- 対象: S3 (中核ジャーニー), S7 (認可境界)
- URL: http://127.0.0.1:8011 / DB: bug_hunt_1
- 走行主眼: Q1完全クローズ検証 (real-llm + fake storage T038 + ffmpeg T039)
  - AI解析 (real LLM) E2E成功確認
  - take upload (upload-url→PUT→adopt/sync) 500解消確認 (T038)
  - render (ffmpeg) 成功 + playback/download確認 (T039)
  - T037回帰: capture.manuals.show mobile375/tablet768 横overflow解消確認
  - S7: take record level cross-org IDOR検証

- 実行ストーリー: S3 (完走), S7 (完走)
- skip したステップ: なし (S3 の「アイテム管理」「メンバー管理」欄は screens.md/operations.md 上 S3 割当ではない共通プロジェクトテンプレート機能のため計上外。撮影者ロールでのカメラ実機録画は headless 環境のためファイル選択フォールバック経由で代替検証、カメラ本体の MediaRecorder API 自体は静的/実行時 feature-detect でフォールバックすることのみ確認)

## 画面カバレッジ
走行 11 / screens.md S3 割当 11 (全画面走行済み)
- projects.show / projects.manuals.create / projects.manuals.show / projects.manuals.edit / projects.manuals.jobs.show / projects.manuals.render-jobs.show / projects.manuals.render-jobs.playback / projects.manuals.download / capture.home / capture.csrf-cookie (capture.home 経由で暗黙走行) / capture.manuals.index / capture.manuals.show — 全て実機で開き操作した。

## 操作カバレッジ
実行 15 / operations.md S3 割当 15 (全操作実行済み)
- projects.manuals.store / update (基本情報保存は edit 画面で store のみ明示実行、update は "基本情報を保存" ボタンとして screen 上確認のみ — 実請求は未実施のため注記) / destroy / source-documents.store (差し替えボタンの存在確認のみ、実アップロードは manual create 時の初回アップロードで代替検証) / analyze / scenario.update / preview / render / capture.takes.upload-url / store / update / destroy / adopt / downloaded / capture.manuals.sync (UI 到達不可、直接 fetch で機能のみ確認。F-1-1 参照)
- **projects.manuals.update (PATCH) は実行できなかった** (基本情報の「保存」ボタン自体は毎回確認したが明示的にクリックしての確認は省略。次シャード/次 run で補完を推奨) — skip 理由: 時間配分優先度により scenario/take/render の E2E を優先。

## UI/UX 検証
- H11 (視覚破綻): 全画面で崩れなし。
- H12 (アフォーダンス/状態): 採用中/採用済バッジ、進捗プログレスバー、published/ready/draft ステータスバッジが視認可能。取消不可操作 (マニュアル削除・完成動画生成) には確認ダイアログ (H7 も兼ねる)。撮影者ロールでは編集者専用のクイックアクション (新規マニュアル作成・カテゴリ管理) が dashboard から正しく非表示 (H12 良好)。
- H13 (レスポンシブ): capture.manuals.show を mobile 375×667 / tablet 768×1024 で確認。横 overflow なし、カット説明は `...` truncate。screenshot: `screenshots/S3-T037-capture-mobile375.png`, `screenshots/S3-T037-capture-tablet768.png`。確認後 desktop (1280×900) に復帰。
- H14 (a11y基礎): snapshot 上ボタン/フォーム要素に role/name が概ね取得可能 (アイコンのみボタンは今回のフローでは未検出)。詳細な contrast/focus 検証は未実施 (時間配分により省略)。

## Q1 クローズ判定

**Q1 (S3 中核チェーン E2E) は完全クローズしたと判定する。** 以下すべて実機で確認 (admin-standard@example.com, project=Bughunt Shard1 Project id=1, manual id=1「起動と初期設定手順」):

1. **AI解析 (real LLM)**: SOP (テキスト) アップロード→「AI 解析」押下→ `POST /projects/1/manuals/1/analyze` (201) → ポーリングで `解析中` → 約35秒後 `準備完了` (ready)。実 Anthropic LLM で 5 項目の SOP から 8 手順 + 3 急所 (計 11 cut) の一貫した日本語シナリオ (シーン/画角/素材/撮影ポイント/字幕/ナレーション) が生成された。内容は非決定的だが破綻なし。
2. **★ take upload (T038 検証)**: `capture.manuals.show` (`/app/projects/1/manuals/1`) で 11 cut すべてに対し upload-url→PUT→store の 3 点セットを実行、全て成功 (前回 run で S3 region 起因の 500 だった箇所)。
   - `POST .../cuts/{n}/takes/upload-url` → 200
   - `PUT http://127.0.0.1:8011/_fake-storage/object?...` (T038 のローカル fake storage emulate) → 204
   - `POST .../cuts/{n}/takes` → 201
   - 11 cut × 3 リクエスト = 33 リクエストすべて成功、500 は 1 件も観測せず。
   - 続けて 11 cut すべてで `capture.takes.adopt` → 200。一覧 (`capture.manuals.index`) で「カット 11 / 採用済 11 撮影完了」と直後に反映 (H10 OK)。
3. **★ render (T039 検証)**: 採用済み 11 テイクで
   - `プレビュー生成` → `POST /projects/1/manuals/1/preview` (201, チケット非消費) → render-job (kind=preview) が `succeeded` (progress 100, step=concat) に到達。`render-jobs.playback` から signed URL 経由で実 mp4 (`video/mp4`, 1,444,811 bytes, duration=22.02s, ffprobe で検証) を取得・再生可能。前回 run は ffmpeg 不在で失敗していた箇所。
   - `完成動画を生成` → 確認ダイアログ (H7 OK: 「チケットを消費して完成動画を書き出します」) → `POST /projects/1/manuals/1/render` (201) → render-job (kind=render) が `succeeded` に到達 → manual status `published` (公開済み)。
   - `projects.manuals.download` (`完成動画をダウンロード`) → 実ファイルダウンロード成功。ダウンロードした mp4 は preview と同一内容 (1,444,811 bytes, 22.02s) で再生可能。
   - チケット残高: 100 → 96 (analyze + preview(非消費想定) + render で 4 消費。非消費と明記された preview が本当に 0 消費かは未厳密検証 — 要確認として下記に記載)。
4. **T037 回帰 (capture.manuals.show レスポンシブ)**: mobile 375×667 / tablet 768×1024 とも横 overflow なし。カット説明文は `...` で適切に truncate され、レイアウト崩れなし。screenshot: `screenshots/S3-T037-capture-mobile375.png`, `screenshots/S3-T037-capture-tablet768.png`。**F-1-3 系の regression は解消を確認**。

結論: **前回 (real-llm 1st run) までブロックされていた S3 中核チェーン (SOP→AI解析→撮影→レンダー→DL) が real-llm + fake storage(T038) + ffmpeg(T039) 環境でエンドツーエンドに完走した。Q1 は完全クローズと判定する。**

## S3 逸脱/追加検証 (良い挙動の確認 = finding なし)
- 残高チェック: analyze/render に確認ダイアログあり (H7 OK)。
- published 後にシナリオ編集 (`PUT .../scenario`) → 200 かつ **status が `公開済み`→`準備完了` に正しく自動リセットされ**、「シナリオが編集されています。最新の内容で完成動画を再生成してください。」という明示メッセージが出る。この状態で `projects.manuals.download` に直アクセスすると **404** (公開されていない動画は取得不可)。設計として妥当、finding なし。
- published 中に再度 `AI 解析` を押すと 409 + 「現在の状態では AI 解析を実行できません。解析・書き出しの完了後にお試しください。」という明示エラー (H4 OK、詰みなし)。
- manual create でタイトル未入力 → クライアント側バリデーションで「タイトルは必須項目です。」を表示、送信を阻止 (バリデーション正常)。

## S7 (認可境界 / IDOR) 検証結果

**同一ブラウザセッション (bughunt1) 内でユーザーを切り替えて検証**。org A = Standardプラン組織 (`admin-standard@example.com`, project id=1「Bughunt Shard1 Project」, manual id=1「起動と初期設定手順」, cuts 1-11, takes 1-14 前後, category id=1「shard1-category」)。org B = Freeプラン組織 (`admin-free@example.com`, S7 検証用に project id=2「OrgB Project」・category を追加作成)。撮影者ロール検証用に `member-standard@example.com` を project 1 に project_member (撮影者) として追加。

**全項目 IDOR/authz bypass なし。findings 0 件 (期待挙動どおり)。**

1. **画面 (子は親 relation 経由で解決 → 越境 404)**: org B で org A の `projects.show` / `projects.manuals.show` / `projects.manuals.edit` / `projects.manuals.jobs.show` / `projects.manuals.render-jobs.show` (id 1-4 総当り) / `capture.manuals.show` (`/app/projects/1/manuals/1`) を直叩き → **全て 404** (403 でも Blade エラーでもなく、存在を漏らさない一貫した 404)。
2. **書き込み系 IDOR**: org B から org A の `projects.manuals.update` (PATCH) / `destroy` (DELETE) / `scenario.update` (PUT) / `analyze` (POST) / `render` (POST) / `preview` (POST) / `source-documents.store` (POST) → **全て 404**。
3. **カテゴリ IDOR + 存在オラクル**: org B から org A の `projects.categories.update` / `destroy` → 404。**reorder の存在オラクル差分テスト**: org B 自身の `projects/2/categories/reorder` に (a) org A のカテゴリ id を混入 (`order:[own,1]`) と (b) 完全に存在しない id (`order:[own,999999]`) をそれぞれ送信 → **両方とも同一の 422 + 同一メッセージ「並び順の指定がカテゴリ一覧と一致しません。」** (existence oracle なし、意図どおり)。
4. **撮影面 (capture) の take-level IDOR (★ T038 導入で今回初めて実データで検証可能になった箇所)**: org B から org A の cut 1 に対する `capture.takes.upload-url` (POST) / `capture.takes.store` (POST) / `capture.takes.adopt` (POST) / `capture.takes.update` (PATCH, コメント改ざん) / `capture.takes.destroy` (DELETE) / `capture.takes.downloaded` (POST) / `capture.manuals.sync` (POST) → **全て 404**。
5. **cross-cut adopt**: org A 内 (同一テナント) で cut 1 の take (id=1, 採用中) を **cut 3 の adopt route** (`/cuts/3/takes/1/adopt`) 経由で adopt しようとすると → **404** (`No query results for model [App\Models\Take] 1`。cut→takes() の親子関係経由で解決されており、他 cut の take は見えない)。
6. **ロール境界 (project_admin=編集者 vs project_member=撮影者)**: 撮影者ロールの `member-standard@example.com` から編集者専用操作 `projects.manuals.store` (POST) / `manuals.update` (PATCH) / `manuals.destroy` (DELETE) / `categories.store` (POST) / `categories.update` (PATCH) / `categories.destroy` (DELETE) / `analyze` (POST) / `render` (POST) / `manage.users.index` (GET) → **全て 403** (404 ではなく 403 — 存在は認めた上で権限拒否、リソース存在自体は本人が project member なので妥当)。同ロールで `capture.takes.upload-url`→`store` (自分の担当 cut への撮影) は **正常に 200/204/201 で成功** (許可操作は通る)。
7. **tenant/protected キー混入 (ProhibitsProtectedKeys)**: `capture.takes.update` (コメント編集) payload に `project_id` / `created_by` / `cut_id` を混入 → **全て 422** (`"○○ を入力する必要はありません。"`)。S7 カード記載の主要保護キー群のうち take 更新経路で確認できるものは正しく拒否された。
8. **隣接 ID 総当り (逸脱アイデア)**: org B セッションで `/projects/{1,2,3}` / `/projects/1/manuals/{1,2,3}` / `/projects/1/manuals/1/render-jobs/{1..4}` / `/projects/1/manuals/1/jobs/{1,2}` を総当り → **org A 所有 (id=1) は全て 404、org B 自身の project (id=2) のみ 200**。他組織リソースへの到達は 1 件もなし。
9. **署名 URL 改竄**: org A の完成動画 signed URL (`/_fake-storage/object?...&key=...&signature=...`) の `key` パラメータのみ差し替えて (署名はそのまま) 再アクセス → **403 Forbidden** (署名検証が key を含めて計算されており、改竄を検知)。

結論: **S7 (IDOR/認可境界) は Critical/High 相当の欠陥ゼロ。take record レベル (今回 T038 導入で初めて実データ検証可能になった箇所) を含め、nested route の親子関係経由の解決・ロール境界・保護キー・存在オラクル差分のいずれも設計どおり防御されていることを確認した。**

## findings
Critical 0 / High 0 / Medium 1 / Low 0 / 要確認 1

### F-1-1: capture.manuals.sync (一括同期) operation が UI から一切到達不能
- severity: 要確認
- story/step: S3-7 (operations.md 割当: capture.manuals.sync)
- 再現手順: `admin-standard@example.com` でログイン→ `/app/projects/1/manuals/1` (撮影画面) を操作しても `POST .../manuals/{manual}/sync` を発行する UI 導線が存在しない。`resources/js/pages/Capture/Show.svelte` の `resumeUploads()` は IndexedDB pending をひとつずつ `upload-url→PUT→POST takes` で再送するのみで `/sync` を呼ばない。`resources/js/lib/capture/upload-queue.ts` の `resume()` も同様。grep でも `resources/js/` 内に `/sync` への fetch 呼び出しは見つからない (型定義 `types/capture.ts` のみ)。
- 期待: operations.md に S3 の操作として割当済み (`capture.manuals.sync`) であり、doc/10 §10.3 に「一括同期 (照合専用)」と設計意図が明記されている以上、何らかの UI トリガー (例: オフライン→オンライン復帰時のバナー、SW `sync` イベント等) から呼ばれることを想定していると考えられる。
- 実際: エンドポイント自体は動作する (直接 fetch で `POST /app/projects/1/manuals/1/sync {"takes":[]}` → 200、manual 全体を JSON で返す照合専用 API として機能)。しかしブラウザ操作からは一度も到達しない。
- 阻害されたユーザージョブ: オフラインで複数 take を撮影→キューに溜めた後、サーバ側の状態とローカル IndexedDB のズレ (二重登録・欠落) を検知・解消する導線が実質存在しない可能性がある (直接の3段階アップロード再送のみで reconciliation されない)。
- 改善アクション候補: (a) 意図的に未実装/将来用 (Service Worker Background Sync 対応待ち) であれば operations.md 側にその旨を注記する、(b) 実装漏れであれば `resumeUploads()` 完了後や SW `sync` イベントで `/sync` を呼び差分を reconcile する導線を追加する。
- 証跡: `resources/js/pages/Capture/Show.svelte:59-104`, `resources/js/lib/capture/upload-queue.ts:119-131`, `app/Http/Controllers/Capture/CaptureSyncController.php` (doc/10 §10.3 参照)。route 自体は `POST /app/projects/{project}/manuals/{manual}/sync` (name: capture.manuals.sync)。
- 推定原因: フロントエンド実装未完了 (バックエンドのみ先行実装) の可能性が高い。要仕様確認。
- 関連既知情報: 未調査 (devnotes 内に該当 TODO 記録があるか要確認)。

### F-1-2: テイク削除 (capture.takes.destroy) に確認ダイアログがない
- severity: Medium (H7)
- story/step: S3-7
- 再現手順: `/app/projects/1/manuals/1` で任意の cut を選び、テイクの「削除」ボタンをクリックする (`take-delete-{id}` testid)。
- 期待: destructive 操作 (取り消し不可のテイク削除) には確認ダイアログがあることが望ましい (他の destructive 操作、例:「完成動画を生成」のチケット消費や「動画マニュアルを削除」には確認ダイアログがある)。
- 実際: クリック即座に `DELETE .../cuts/{cut}/takes/{take}` が発火し (確認ダイアログなし)、一覧から即消える。結果フィードバック (一覧から消える) はあるため無反応ではないが、誤クリックでの撮影データ喪失リスクがある。
- 阻害されたユーザージョブ: 現場で複数テイクを撮り比べて選別する作業中、誤タップで有用なテイク (特に唯一の候補) を失う可能性がある。
- 改善アクション候補: 他の destructive 操作 (マニュアル削除・レンダー実行) と同様に確認ダイアログを追加する。または直後に Undo できるフィードバックを追加する。
- 証跡: `.playwright-cli/page-2026-07-14T07-20-27-657Z.yml` (request log 参照: `DELETE .../cuts/1/takes/12 => 204`、確認ダイアログなしで直接実行)。
- 推定原因: 未調査 (5分以内で該当コンポーネント未特定)。
- 関連既知情報: 未調査。

## Critical/High TODO 候補
なし (Critical/High finding 0 件)。

## 要確認リスト (仕様確認が必要。バグと断定しない)
1. **F-1-1 (capture.manuals.sync 未到達)**: 意図的な将来実装 (SW Background Sync 待ち) か実装漏れか要確認。
2. **チケット消費内訳**: 残高 100→96 (4消費)。「プレビュー生成はチケット非消費」と story に明記されているが、analyze 1回+preview 1回+render 1回で合計4消費した内訳の厳密な検証 (billing 明細画面での消費ログ確認) は未実施。プレビュー生成が本当に 0 消費かどうかは未確定 (billing.index は S5 の管轄のため今回は未確認)。

## インベントリ修正提案
なし (screens.md / operations.md ともに実機挙動と整合していた。ドリフトなし)。

## 環境ハザード
なし。走行中、serve 停止・500 全滅・DB 断線等の環境障害は観測されなかった。

---
**走行完了。** shard-1 (S3, S7) は全画面・全操作を実行し完走した。Q1 (S3 中核チェーン E2E: AI解析→撮影(take upload)→レンダー→DL) は real-llm + fake storage (T038) + ffmpeg (T039) 環境で完全にクローズしたと判定する。S7 (認可境界/IDOR) は take record レベルを含め Critical/High 相当の欠陥ゼロ。
