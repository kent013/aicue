# conceptual-review Round 2

Round 1 の全指摘に対する対応マトリクスと、修正後の概念設計全文を提示します。再レビューし、全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。

## 対応マトリクス (要約)

- [Critical] 「生キー露出なし」の検証面: **対応**。テストを「表示文言」に限定。`assertInvalid(['message' => 'お問い合わせ内容'])` 等でエラーメッセージ本文を検証し、エラー bag のキー自体は検証対象外と明記した。
- [Warning] inline validate が deny-by-default の対象外: **対応**。`ValidationAttributeCoverageTest` に第 2 検査を追加し、`app/Http/Controllers/**`・`app/Actions/**` の `->validate([` / `Validator::make(` ルール配列キーを静的走査で抽出して attributes 登録を要求する。FormRequest への寄せ替え refactor は「文言のみ・ロジック変更なし」のスコープ外と明記。
- [Warning] FormRequest 全件 instantiate の脆さ: **対応 (方式明確化)**。事前調査で route 依存 rules() は 4 クラスのみ、全て `$project instanceof Project ? $project->id : 0` の null-safe パターンで route 未バインドでも安全に呼べることを確認。将来呼べないクラスが出た場合の instantiate 除外 inventory (`array<class-string, string>`、理由必須) を用意。
- [Warning] トップレベルキーのみでは取りこぼし: **対応**。収集対象を rules 配列の全キー (dotted/wildcard 含む) に変更、数値 index を `*` に正規化して照合。
- [Warning] env invariant が外部注入参照まで禁止: **対応**。目的を「自己参照・前方参照の禁止」に限定し、意図的な外部注入参照の ALLOWLIST (ファイル => 変数名 => 理由) を持たせる。
- [Warning] `.env.bughunt.local` 手作業の運用依存: **一部対応・一部反論**。同ファイルは本作業コピー内に実在する (gitignore なだけ) ため、実装ステップで example と同時に直接編集し、provision 再実行 + home 表示確認で検証する。provision フローへの APP_NAME 妥当性チェック追加は bug-hunt 基盤 (fail-secure ガード群) へのロジック変更でありスコープ外 (思考原則「今必要なものだけ作る」)。恒久 drift 検知は別トピックとしてスコープ外に明記した。
- [Warning] UI ラベルと翻訳ラベルの語彙ズレ: **対応**。詳細設計に「フィールド => Svelte label => attributes ラベル」対応表を含め、既存 UI ラベルを正とする規約を validation.php ヘッダに追記する。
- [Warning] ALLOWLIST の stringly-typed: **対応**。`array<class-string, array<string, string>>` の構造化マップに変更。
- [Suggestion] 優先順位明示 / F-01 効果の射程 / 内部項目の日本語化 / 補助関数の型: いずれも採用。

## 修正後の概念設計 (全文)

(devnotes/20260712-0926-bugfix-i18n-copy/conceptual-design.md — 変更点: 施策1の 2/3 項、施策2の 1/2/3 項、期待効果、実装方針表、スコープ外)

### 施策 1 (F-01) 抜粋 (修正後)

1. `.env.bughunt.local.example` の `APP_NAME="${APP_NAME}"` を実値 `APP_NAME="AI-CUE"` に修正 (dev `.env` の値と一致させる。コメントで「dev .env の APP_NAME と揃える」旨を注記)。
2. 本作業コピー内に実在する (gitignore 済みの) `.env.bughunt.local` も**同一実装ステップで直接編集**する (コミット対象外だが運用頼みにしない)。検証は `scripts/bug-hunt-shard.sh provision` 再実行 + home のタイトル/フッター表示確認で行う。
3. 再発防止: `tests/Architecture/EnvExampleInvariantTest.php` に「コミット対象の env ファイル (`.env.example` / `.env.bughunt.local.example` / `.env.testing`) 内の `${VAR}` 参照について、**自己参照 (`VAR="${VAR}"`) と前方参照 (同一ファイル内で後方定義) を禁止**する」テストを追加 (ファイル走査のみ)。意図的な「実行環境からの外部注入」参照は ALLOWLIST (ファイル => 変数名 => 理由) で許容できる形にする。

### 施策 2 (F-02) 抜粋 (修正後)

1. 棚卸しで判明した全未登録フィールドに日本語ラベルを追加。**既存 UI (Svelte フォームの label 文言) を正とする対応表を詳細設計に含め**、語彙ズレを防ぐ (validation.php ヘッダコメントにも規約追記)。内部寄りフィールドにもユーザー可読な日本語 (例: `attempt_token => 操作トークン`) を与える。ネスト配列 (`steps.*.title` 等) は primaryAttribute 解決 (数値 index → `*`) を使いワイルドカードキーで登録。
2. 再発防止 (FormRequest 面): 新規 `ValidationAttributeCoverageTest`。全 FormRequest をコンテナ経由で instantiate し `rules()` の**全キー** (dotted/wildcard 含む) を収集、数値 index を `*` に正規化して `lang/ja/validation.php` の attributes と照合。除外は `array<class-string, array<string, string>>` (クラス => キー => 理由) の構造化 ALLOWLIST (`g-recaptcha-response` は messages() で全 rule 個別文言済みのため除外)。instantiate 不能クラスの逃げ道は `array<class-string, string>` の除外 inventory。
3. 再発防止 (inline validate 面): 同テストに第 2 検査。`app/Http/Controllers/**`・`app/Actions/**` を静的走査して `->validate([` / `Validator::make(` のルール配列キーを抽出し attributes 登録を deny-by-default で要求 (構造化 ALLOWLIST 付き)。FormRequest への寄せ替えはスコープ外。

### 期待効果 (修正後)

- 優先接触面は (1) 公開面 (home/contact/pricing) → (2) 認証・組織管理 → (3) 撮影 PWA の順。
- F-01 の直接効果は **bug-hunt 環境の表示信頼性回復** (dev/本番は `.env` で解決済み)。加えて env 自己参照という事故クラス自体を Architecture テストで恒久防止。
- F-02: 全フォームでユーザー向け文言。FormRequest / inline validate へのフィールド追加時、attributes 未登録は CI で fail。

### テスト計画 (修正後)

- `tests/Feature/Inquiry/ContactSubmissionTest.php`: 空 message 送信で**表示文言**が「お問い合わせ内容は必須項目です。」となり「messageは必須項目です。」でないことを検証 (エラー bag のキーは検証対象外)。
- `tests/Feature/Organizations/TwoFactorEnforcementTest.php`: reason 空欄送信で**表示文言**が「理由は必須項目です。」となることを検証。
- Architecture: EnvExampleInvariantTest 追加分 + ValidationAttributeCoverageTest (新規)。

### スコープ外 (追記)

- inline validate の FormRequest への寄せ替え refactor。
- bug-hunt provision フローへの APP_NAME 妥当性チェック / 恒久 drift 検知 (bug-hunt 基盤のロジック変更のため別トピック)。
