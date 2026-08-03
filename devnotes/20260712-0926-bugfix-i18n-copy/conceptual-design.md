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
2. 本作業コピー内に実在する (gitignore 済みの) `.env.bughunt.local` も**同一実装ステップで直接編集**する
   (コミット対象外だが運用頼みにしない)。検証は `scripts/bug-hunt-shard.sh provision` 再実行 +
   home のタイトル/フッター表示確認で行う。
3. 再発防止: 既存の `tests/Architecture/EnvExampleInvariantTest.php` に
   「コミット対象の env ファイル (`.env.example` / `.env.bughunt.local.example` / `.env.testing`) 内の
   `${VAR}` 参照について、**自己参照 (`VAR="${VAR}"`) と前方参照 (同一ファイル内で後方定義) を禁止**する」
   テストを追加する (ファイル走査のみ、アプリ起動不要)。意図的に「実行環境からの外部注入」を期待する
   参照が将来必要になった場合は ALLOWLIST (`ファイル => 変数名 => 理由` の構造化マップ) で許容できる形にする。

### 施策 2 (F-02): `lang/ja/validation.php` の attributes を全域棚卸しで補完し、カバレッジを Architecture テストで恒久化

1. 上記棚卸しで判明した全未登録フィールドに日本語ラベルを追加する
   (例: `message => お問い合わせ内容`, `reason => 理由`, `title => タイトル`, `count => 購入枚数`)。
   **既存 UI (Svelte フォームの label 文言) を正とする対応表を詳細設計に含め**、UI ラベルと
   翻訳ラベルの語彙ズレを防ぐ (validation.php ヘッダコメントにもこの規約を追記)。
   capture PWA / API 系の内部寄りフィールド (`attempt_token`, `checksum_sha256` 等) にも
   ユーザー可読な日本語 (例: 操作トークン) を与え、生キーが露出する経路を残さない。
   ネスト配列 (`steps.*.title` 等) は Laravel の primaryAttribute 解決 (数値 index → `*` 置換) を使い
   ワイルドカードキーで登録する。
2. 再発防止 (FormRequest 面): 新規 Architecture テスト `ValidationAttributeCoverageTest` を追加。
   `FormRequestProhibitedKeyTest` と同じ deny-by-default inventory パターンで、
   `app/Http/Requests/**` の全 FormRequest を列挙 → コンテナ経由でインスタンス化して `rules()` の
   **全キー (トップレベルに限らず dotted/wildcard キーを含む)** を収集 → 各キー (数値 index を `*` に正規化) が
   `lang/ja/validation.php` の `attributes` に存在することを要求する。
   - route 依存の rules() (Store/UpdateCategoryRequest 等 4 クラス) は null-safe パターン
     (`$project instanceof Project ? $project->id : 0`) のため route 未バインドでも安全に呼べることを確認済み。
   - 除外は stringly-typed を避け、`array<class-string, array<string, string>>` (クラス => キー => 理由) の
     構造化 ALLOWLIST で持つ。FormRequest 側 `messages()` で属性名を含まない個別文言を全 rule に
     定義済みのキー (`g-recaptcha-response` 等) はここで除外する。
   - `rules()` が安全に呼べないクラスが将来出た場合の逃げ道は `array<class-string, string>`
     (クラス => 理由) の instantiate 除外 inventory として用意する。
3. 再発防止 (inline validate 面): 同テストに第 2 検査を追加する。**fail-closed (未検出・未解析を成功扱いしない)** を
   原則とし、二段階で検査する:
   1. **呼び出し検出**: 対象を Controllers/Actions に限定せず `app/` 配下の全 PHP ファイル
      (FormRequest 本体の `app/Http/Requests/**` は第 1 検査でカバー済みのため除外) を走査し、
      validation API 呼び出し (`->validate(` / `->validateWithBag(` / `Validator::make(`) を全件列挙する。
   2. **キー抽出 or inventory 強制**: 各呼び出しについてインラインのルール配列リテラルからキーを静的抽出する。
      抽出できた dotted/wildcard キー (数値 index は `*` 正規化) は attributes 登録を要求。
      **ルール配列が変数・メソッド分離等で静的抽出できない呼び出しは、理由付き inventory
      (`array<string, string>`: `ファイルパス@識別子 => 理由`) に登録されていない限り fail** させる。
   これにより「解析できなかった呼び出しがテスト成功になる」穴を塞ぎ、期待効果
   (フィールド追加時に attributes 未登録なら CI fail) を FormRequest / inline validate の両面で保証する。
   inline validate の FormRequest への寄せ替え (ロジック refactor) は本 fix のスコープ外とする。

## 期待効果

- 使命への貢献: 公開 LP〜全画面のブランド表示 (AI-CUE) とフォームエラー文言が正しく日本語で表示され、
  「専門知識ゼロの現場作業者」への信頼性・分かりやすさという North Star の前提が回復する。
  優先接触面は (1) 公開面 (home/contact/pricing) → (2) 認証・組織管理 → (3) 撮影 PWA の順。
- F-01: 直接効果は **bug-hunt 環境の表示信頼性回復** (dev/本番は現状 `.env` で正しく解決済み)。
  加えて env example の自己参照混入という事故クラス自体を Architecture テストが全環境向けに恒久防止する。
- F-02: contact / 2FA 解除を含む全フォームで「お問い合わせ内容は必須項目です。」のような
  ユーザー向け文言が返る。今後 FormRequest / inline validate にフィールドを追加した際、
  attributes 未登録なら Architecture テストが fail し、翻訳漏れが CI で検出される。

## 実装方針（概要）

| 変更対象 | 内容 |
|---------|------|
| `.env.bughunt.local.example` (L25) | `APP_NAME="${APP_NAME}"` → `APP_NAME="AI-CUE"` + 注記コメント |
| `.env.bughunt.local` (gitignore 済み) | 同一実装ステップで直接編集 (コミット対象外)。provision 再実行 + home 表示で検証 |
| `lang/ja/validation.php` (`attributes`) | 棚卸し済み全フィールドの日本語ラベル追加 (UI ラベル対応表準拠) + ヘッダコメントに規約追記 |
| `tests/Architecture/EnvExampleInvariantTest.php` | env example の `${VAR}` 自己参照/前方参照禁止 invariant テスト追加 (外部注入 ALLOWLIST 付き) |
| `tests/Architecture/ValidationAttributeCoverageTest.php` (新規) | FormRequest rules() 全キー + Controller/Action inline validate キーの attributes カバレッジ invariant (構造化 ALLOWLIST) |
| `tests/Feature/Inquiry/ContactSubmissionTest.php` | 空 message 送信で**表示文言**が「お問い合わせ内容は必須項目です。」となり、「messageは必須項目です。」でないことを検証 (エラー bag のキーは検証対象外) |
| `tests/Feature/Organizations/TwoFactorEnforcementTest.php` | reason 空欄送信で**表示文言**が「理由は必須項目です。」となることを検証する再現テスト追加 |

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
- バリデーションロジック・ルールの変更、フォーム UI の変更、inline validate の FormRequest への寄せ替え refactor。
- 多言語対応の拡張 (en ロケール整備等)。ja のみ。
- `lang/ja.json` (文レベル翻訳) の棚卸し (今回の finding は validation attributes に限定)。
- bug-hunt provision フローへの APP_NAME 妥当性チェック / example と実ファイルの恒久 drift 検知の組み込み
  (bug-hunt 基盤のロジック変更になるため。必要なら別トピックとして起案)。
