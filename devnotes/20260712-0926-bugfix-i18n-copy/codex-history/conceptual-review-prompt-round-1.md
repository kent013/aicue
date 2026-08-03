# 役割・前提

【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

(以下は devnotes/20260712-0926-bugfix-i18n-copy/conceptual-design.md の全文。必要ならリポジトリ内の関連ファイル (.env.bughunt.local.example, lang/ja/validation.php, tests/Architecture/EnvExampleInvariantTest.php, tests/Architecture/FormRequestProhibitedKeyTest.php, app/Http/Requests/**) を読み込んで検証してよい)

# 概念設計: bugfix-i18n-copy — コピー崩れの修正 (F-01 APP_NAME 未展開 / F-02 未翻訳キー)

## 背景・課題

bug-hunt 走行 (devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md) で以下の Medium finding が報告された。

### F-01: サイト全域で `${APP_NAME}` プレースホルダが未展開のまま表示される

- 症状: 未ログインの home を含む全画面で、ブラウザタブタイトル・ヘッダーロゴ・フッター著作権表記 (`© 2026 ${APP_NAME}`) に生の `${APP_NAME}` 文字列が表示される。
- **調査で確定した根本原因**: `.env.bughunt.local` (および配布元の `.env.bughunt.local.example`) の 25 行目が
  `APP_NAME="${APP_NAME}"` と**自己参照**になっている。Laravel (vlucas/phpdotenv) の nested variable
  `${VAR}` は「同一ファイル内で先に定義された変数 or 実行環境の変数」しか解決できないため、
  bughunt 環境 (`APP_ENV=bughunt.local` で `.env.bughunt.local` のみロード) では未解決のまま
  リテラル `${APP_NAME}` が `config('app.name')` の値になる。
  - dev の `.env` は 1 行目で `APP_NAME="AI-CUE"` を定義しており、`MAIL_FROM_NAME="${APP_NAME}"` 等の
    後方参照は正しく解決される (dev では再現しない = 環境設定バグ)。
- **切り分け結果 (実バグ vs 環境設定)**: アプリコード側にテンプレ文字列の直書きは存在しない。
  - フロントは `HandleInertiaRequests::share()` の `appName => config('app.name')` を全ページで参照 (`resources/js/lib/shared-props.ts` の `appName: string`)。
  - Blade (errors/legal/mcp/メール) は `{{ config('app.name') }}` を参照。
  - `grep -rn '${APP_NAME}' resources/ app/ config/ lang/` → 0 件。
  - よって修正対象は「アプリコード」ではなく「リポジトリにコミットされている `.env.bughunt.local.example` が配布している自己参照定義」。example がバグを配布している以上、これは単なるローカル設定漏れではなくリポジトリ起因の再現性あるバグである。

### F-02: 複数フォームでバリデーションの未翻訳キー (英語フィールド名) が露出する (systemic)

- 症状: contact フォームの `message` (「**message**は必須項目です。」)、2FA 解除ダイアログの `reason`
  (「**reason**は必須項目です。」) など、英語の内部フィールド名がそのままエラー文言に露出。
- **調査で確定した根本原因**: `lang/ja/validation.php` の `'attributes'` 配列が
  `name / email / password / password_confirmation / current_password / terms_accepted` の 6 キーのみで、
  アプリの FormRequest / inline validate が使う他の全フィールドが未登録。
  `:attribute` placeholder に生のフィールド名が入る。
- 棚卸し結果 (全 `app/Http/Requests/**` の rules() + Controller inline validate の走査):
  未登録フィールドは `message, company_name, type, source, website, reason, role, token, enabled,
  user_id, plan_code, count, attempt_token, abilities, description, note, title, category, document,
  order, lang, expected_version, steps, comment, position, status, sort_order, takes, ticket,
  client_take_id, duration_ms, captured_at, video_path, size_bytes, content_type, checksum_sha256,
  downloaded_at, ack_token` など。`g-recaptcha-response` は FormRequest 側 `messages()` で個別文言済み。

いずれもロジックは正常だが、「壊れたテンプレート」感 (F-01) と多言語化漏れ (F-02) が
初見ユーザー・現場作業者の信頼を損なう。使命 (専門知識ゼロの現場作業者向けプロダクト) に照らし、
ユーザー向け文言の品質は North Star 体験の前提条件。

## 改善アイデア

### 施策 1 (F-01): bughunt env の APP_NAME 自己参照を解消し、env example の自己参照を invariant テストで恒久防止

1. `.env.bughunt.local.example` の `APP_NAME="${APP_NAME}"` を実値 `APP_NAME="AI-CUE"` に修正
   (dev `.env` の値と一致させる。コメントで「dev .env の APP_NAME と揃える」旨を注記)。
2. gitignore 済みの実ファイル `.env.bughunt.local` にも同修正を適用 (実装フェーズの運用手順として明記。コミット対象外)。
3. 再発防止: 既存の `tests/Architecture/EnvExampleInvariantTest.php` に
   「コミット対象の env ファイル (`.env.example` / `.env.bughunt.local.example` / `.env.testing`) 内の
   `${VAR}` 参照は、同一ファイル内でそれより前の行に `VAR=` の定義を持たなければならない」
   という自己参照/前方参照検出テストを追加する (ファイル走査のみ、アプリ起動不要)。

### 施策 2 (F-02): `lang/ja/validation.php` の attributes を全域棚卸しで補完し、カバレッジを Architecture テストで恒久化

1. 上記棚卸しで判明した全未登録フィールドに日本語ラベルを追加する
   (例: `message => お問い合わせ内容`, `reason => 理由`, `title => タイトル`, `count => 購入枚数`)。
   capture PWA / API 系の内部寄りフィールド (`attempt_token`, `checksum_sha256` 等) にも
   ラベルを与え、生キーが露出する経路を残さない。
   ネスト配列 (`steps.*` 等) は Laravel の primaryAttribute 解決 (数値 index → `*` 置換) を使い
   ワイルドカードキーで登録する。
2. 再発防止: 新規 Architecture テスト `ValidationAttributeCoverageTest` を追加。
   `FormRequestProhibitedKeyTest` と同じ deny-by-default inventory パターンで、
   `app/Http/Requests/**` の全 FormRequest を列挙 → コンテナ経由でインスタンス化して `rules()` の
   トップレベルキーを収集 → 各キー (数値 index を `*` に正規化) が
   `lang/ja/validation.php` の `attributes` に存在することを要求する。
   FormRequest 側 `messages()` で属性名を含まない個別文言を全 rule に定義済みのキー
   (`g-recaptcha-response` 等) は ALLOWLIST (クラス名+キー => 理由) で除外可能とする。
   `rules()` がリクエスト文脈に依存して安全に呼べないクラスも ALLOWLIST で除外できる逃げ道を用意する。
3. Controller inline validate (`reason`, `token`, `enabled`, `user_id`, `role`, `password`) は
   静的列挙が brittle なため機械カバレッジの対象外とし、今回の棚卸しで attributes へ登録して解消する
   (inline validate の網羅は Feature テストで担保)。

## 期待効果

- 使命への貢献: 公開 LP〜全画面のブランド表示 (AI-CUE) とフォームエラー文言が正しく日本語で表示され、
  「専門知識ゼロの現場作業者」への信頼性・分かりやすさという North Star の前提が回復する。
- F-01: bughunt 環境の再 provision 後、タイトル/ロゴ/フッターに AI-CUE が表示される。
  env example の自己参照混入は Architecture テストが機械的に再発防止する。
- F-02: contact / 2FA 解除を含む全フォームで「お問い合わせ内容は必須項目です。」のような
  ユーザー向け文言が返る。今後 FormRequest にフィールドを追加した際、attributes 未登録なら
  Architecture テストが fail し、翻訳漏れが CI で検出される。

## 実装方針（概要）

| 変更対象 | 内容 |
|---------|------|
| `.env.bughunt.local.example` (L25) | `APP_NAME="${APP_NAME}"` → `APP_NAME="AI-CUE"` + 注記コメント |
| `.env.bughunt.local` (gitignore 済み) | 同上 (実装フェーズの手作業。コミット対象外) |
| `lang/ja/validation.php` (`attributes`) | 棚卸し済み全フィールドの日本語ラベル追加 |
| `tests/Architecture/EnvExampleInvariantTest.php` | env example の `${VAR}` 前方定義 invariant テスト追加 |
| `tests/Architecture/ValidationAttributeCoverageTest.php` (新規) | FormRequest rules() キーの attributes カバレッジ invariant |
| `tests/Feature/Inquiry/ContactSubmissionTest.php` | 空 message 送信で「お問い合わせ内容」を含む日本語エラーが返り、生キー `message` 単独露出がないことを検証 (再現テスト) |
| `tests/Feature/Organizations/TwoFactorEnforcementTest.php` | reason 空欄で「理由は必須項目です。」相当のエラーになる再現テスト追加 |

PHP のロジック・ルート・DTO・フロントコンポーネントは一切変更しない (文言/翻訳/env のみ)。

## 制約・前提

- 既存フェーズの規約を踏襲: Architecture テストは既存の inventory パターン
  (`FormRequestProhibitedKeyTest` / `EnvExampleInvariantTest`) に倣う。
- `lang/ja/validation.php` ヘッダコメントの規約 (「laravel-lang/lang の publish 結果に追従。
  カスタマイズは 1/2 に限定」) に従い、attributes 配列のみを拡張する。
- `AppNameHardcodeTest` (slug ハードコード禁止) は APP_NAME (表示名) と別物であり抵触しない。
  env ファイルはそもそも走査対象外。
- bughunt 環境の反映は `scripts/bug-hunt-shard.sh provision` の再実行で行う (config cache は使っていない前提。
  使命/隔離ガードには触れない)。
- ラベル文言は既存 UI (Svelte フォームの label) と揃える。

## スコープ外

- F-03/F-06 (フィードバック欠落)、F-05/F-07 (課金/詰み) 等の他 finding。
- バリデーションロジック・ルールの変更、フォーム UI の変更。
- 多言語対応の拡張 (en ロケール整備等)。ja のみ。
- `lang/ja.json` (文レベル翻訳) の棚卸し (今回の finding は validation attributes に限定)。
