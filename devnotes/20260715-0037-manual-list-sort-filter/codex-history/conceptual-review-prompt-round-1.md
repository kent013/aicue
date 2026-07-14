【アプリの使命 (North Star) — AGENTS.md より】

AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory 経由のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

【セキュリティ不変条件 — AGENTS.md より (抜粋)】
1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404
3. cross-org 不可: 組織を跨ぐ read/write をしない
5. 権限判定は常に laratrust_team_id を明示
6. PII(email/name)は CipherSweet。検索は whereBlind()(平文 where は hit しない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション(Laravel + Svelte)の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命(North Star)に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か(Laravel 12 + Svelte 5 + Inertia.js, PHPStan L10, Pest)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか(v1 スコープを尊重しているか)
7. 型安全性: DTO/typed array パターンに沿っているか。PHPStan level 10 を通せるか
8. セキュリティ不変条件: tenant キー不信 / cross-org 不可 / PII(CipherSweet) の扱い

特に諮りたい点:
- SOP キーワード検索を `source_documents.extracted_json`(json 型)へのテキスト部分一致(pgsql `::text ILIKE` を想定)で実装することの是非。pgsql 固定前提(dev/test とも pgsql)を許容してよいか、それとも SOP 検索自体を out-of-scope とすべきか。
- 「自作のみ」フィルタ + sort + SOP 検索 + 作成者/更新日表示 を PC と PWA の双方に入れる範囲が v1 として過大でないか。
- サムネイル表示を out-of-scope とした判断(v1 で manual 単位のサムネイル成果物が存在しない)の妥当性。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、conceptual-design.md 全文）

# 概念設計: manual-list-sort-filter (動画一覧の並べ替え・自作フィルタ・メタ表示・原稿検索)

出典: ユースケース・カバレッジ監査ギャップ #9 (Low〜Med)。doc/04 §4.2「動画一覧ページ」/ doc/05 §5.2「シナリオ選択画面」。

## 背景・課題

doc/04・doc/05 が要求する動画/シナリオ一覧の仕様と現状実装に乖離がある。

**doc/04 (PC 動画一覧ページ)**: 絞り込み(カテゴリ / 「自分が作成したタイトルのみ」/ 状態) / 検索(タイトル・作成者名) / 並べ替え(更新日・タイトルで昇順降順) / 一覧列に更新日。
**doc/05 (PWA シナリオ選択画面)**: カード表示(サムネイル / タイトル / カテゴリ / 作成者 / 更新日 / 撮影進捗) / 絞り込み(「自分が作ったシナリオ」/ カテゴリ) / 検索(タイトルや原稿のキーワード)。

**現状**:
- `ProjectController::show` (PC): 一覧は `created_at desc, id desc` 固定。フィルタは category / status / q(title like) のみ。作成者・更新日表示なし。並べ替え UI なし。自作フィルタなし。
- `CaptureManualController::index` (PWA): `updated_at desc` 固定。フィルタは category / q(title like) のみ。作成者・更新日表示なし。自作なし。原稿検索なし。

`video_manuals.created_by` (protected, サーバ導出) / `updated_at` は既存。`creator()` relation 定義済み。SOP は `source_documents.extracted_json` に AI 解析の抽出結果(統一 JSON)が write-only 監査スナップショットとして保存される。

## 改善アイデア (v1 スコープ内)

現行のフィルタ機構(GET クエリ + typed array props + paginate)を素直に拡張。新機構は作らない。

1. **並べ替え (PC のみ)**: `sort` パラメータ。allowlist = `updated_desc/updated_asc/title_asc/title_desc`。既定は現行踏襲(`created_at desc, id desc`)。allowlist 外は既定へフォールバック。PWA は doc が要求せず追加しない。
2. **「自分の作成分のみ」フィルタ (PC/PWA)**: `mine=1` で `where('created_by', $user->id)`。created_by は既存 protected カラムを read するのみ(payload から受け取らない)。
3. **メタ表示: 作成者・更新日 (PC/PWA)**: updated_at を行 props に追加。creator relation を eager load し creator.name を追加(User.name は CipherSweet PII だがモデル read で自動復号、表示は既存メンバー一覧と同流儀。N+1 は with('creator') で回避)。サムネイルは out-of-scope。
4. **原稿(SOP) キーワード検索 (PC/PWA)**: 現行 q(title LIKE)を「title OR SOP 抽出テキスト」に拡張。SOP テキスト = source_documents.extracted_json。実装は whereHas('sourceDocuments', ...) サブクエリで抽出 JSON を部分一致。extracted_json 未生成(解析前)の manual は SOP マッチ対象外。LIKE メタ文字は addcslashes でエスケープ、パラメータバインドで SQL インジェクション無し。DB 依存(pgsql の json cast/ilike)は whereRaw 1 箇所に閉じ込め divergence として明記。

## 期待効果
- 使命への貢献: 現場作業者が目的マニュアルへ最短到達。作成者/更新日メタ・自作フィルタは多数シナリオから自分の担当・最新版を素早く選ぶ導線。原稿検索は SOP 一語からマニュアルを引ける = SOP 起点の一貫性を一覧側でも担保。
- doc/04・doc/05 のカバレッジギャップ #9 を閉じる。既存機構の素直な拡張で後方互換の並走を残さない。

## 制約・前提
- 組織スコープ維持: `$project->manuals()` 経由(org-scoped project を認可前 404)。created_by フィルタは自ユーザー id のみ。
- tenant キー不信: created_by は payload から受け取らず auth user の id を使う。
- PII: 作成者 name は CipherSweet PII。表示は復号 read で可だが作成者名での検索は行わない(whereBlind は完全一致で部分一致に向かず、主眼は SOP/タイトル検索)。作成者名検索は out-of-scope。
- DTO: Inertia props は既存 typed array 流儀踏襲。ページネーション整合: PC は paginate(10)->withQueryString() 維持。
- DESIGN.md: UI 追加は既存 atom (Select/Input/Checkbox) と DS token のみ。

## スコープ外
1. サムネイル表示: v1 で manual 単位のサムネイル成果物が存在しない(thumbnail_path は take 単位のみ)。撮影進捗バッジで代替済み。
2. 作成者名でのキーワード検索: User.name は CipherSweet で部分一致不可。
3. PWA の並べ替え UI: doc/05 が要求せず。
4. 再生時間(total_length_ms)の一覧列: レンダ後派生値で監査ギャップ #9 の主眼外。
5. DL / 削除の一覧内アクション: 既存詳細画面導線を維持。
