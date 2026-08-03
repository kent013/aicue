# 対応マトリクス: conceptual-review Round 1

## [Critical] 「生キー message 単独露出がないこと」の検証面が誤っている (error bag は内部キーを保持する)
- 判断: 対応する
- 根拠: 指摘の通り。error bag のキーは仕様上 `message` のままであり、問題は「ユーザーに表示される文言」。
- 対応内容: テスト計画を「表示文言」検証に限定。`assertInvalid(['message' => 'お問い合わせ内容'])` 等で
  エラーメッセージ本文に日本語ラベルが含まれること、本文が「messageは必須項目です。」でないことを検証する
  (エラー bag のキー自体は検証対象外と明記)。概念設計の実装方針表を修正。

## [Warning] inline validate が機械的ガードの対象外のままでは "systemic fix" として不十分 (観点1/観点6と同旨)
- 判断: 対応する
- 根拠: 今回の実害 (`reason`) はまさに inline validate 由来。FormRequest への refactor は「文言のみ・ロジック変更なし」
  のスコープを逸脱するため行わないが、inventory ベースの deny-by-default は静的走査で実現できる。
- 対応内容: `ValidationAttributeCoverageTest` に第 2 検査を追加。`app/Http/Controllers/**` と `app/Actions/**` を
  静的走査して `validate(` / `Validator::make(` のルール配列キーを抽出し、attributes 登録を要求する
  (除外は構造化 ALLOWLIST)。FormRequest への寄せ替えはスコープ外と明記。

## [Warning] FormRequest 全件 instantiate は route param / auth / input 依存で壊れやすい
- 判断: 対応する (方式を明確化)
- 対応内容: 事前調査で route 依存の rules() は 4 クラス (Store/UpdateCategoryRequest, Store/UpdateVideoManualRequest)
  のみで、いずれも `$project instanceof Project ? $project->id : 0` の null-safe パターンのため
  route 未バインドでも安全に呼べることを確認済み (`StoreInquiryRequest` の `$this->ip()` も
  `Request::create()` の既定 REMOTE_ADDR で解決)。instantiate 方式を維持しつつ、
  呼べないクラスが将来出た場合の逃げ道を `array<class-string, string>` の
  「instantiate 除外 inventory (理由必須)」として型付きで用意する。

## [Warning] トップレベルキー収集ではネスト規則 (`steps.*.title` 等) を取りこぼす
- 判断: 対応する
- 対応内容: 収集対象を「rules 配列の全キー」に変更。数値セグメントを `*` に正規化した dotted/wildcard キーを
  そのまま attributes と照合する。

## [Warning] env invariant が「先行定義必須」だと意図的な外部注入参照まで禁止する
- 判断: 対応する
- 対応内容: テストの目的を「自己参照 (`VAR="${VAR}"`) と前方参照の禁止」に絞り、
  意図的な外部環境変数参照を許容する ALLOWLIST (`ファイル => 変数名 => 理由`) を持たせる。

## [Warning] `.env.bughunt.local` の手作業修正が運用依存のまま
- 判断: 一部対応 / 一部反論
- 根拠: `.env.bughunt.local` は本リポジトリの作業コピー内に実在し (gitignore 済みなだけ)、
  実装フェーズで example と同時に直接編集する。よって「各開発環境に散在するファイルの運用頼み」ではない。
  provision フローへの APP_NAME 妥当性チェック追加は bug-hunt 基盤 (テンプレート同梱・fail-secure ガード) への
  ロジック変更であり、「文言/翻訳のみ・ロジック変更しない」という本 fix のスコープと
  思考原則 2 (今必要なものだけ作る) に反するため見送る。
- 対応内容: 概念設計に「実装ステップとして `.env.bughunt.local` を同時編集し、
  `scripts/bug-hunt-shard.sh provision` 再実行 + home タイトル確認で検証する」ことを明記。
  example との drift は今回追加する EnvExampleInvariantTest (example 側) + 実ファイル編集で解消し、
  恒久 drift 検知は将来 bug-hunt 基盤改善として別トピックに委ねる旨をスコープ外に記載。

## [Warning] attributes 大量追加で UI ラベルと翻訳ラベルがズレるリスク
- 判断: 対応する
- 対応内容: 詳細設計で「フィールド => Svelte フォーム label => attributes ラベル」の対応表を作り、
  既存 UI ラベルを正とする方針を明記する。validation.php ヘッダコメントにもその規約を 1 行追記する。

## [Warning] ALLOWLIST を文字列連結で持つと stringly-typed
- 判断: 対応する
- 対応内容: `array<class-string, array<string, string>>` (クラス => キー => 理由) の構造化マップにする。

## [Suggestion] 優先順位の明示 / F-01 効果の射程表現 / 内部項目の自然な日本語 / 補助関数の戻り値型
- 判断: 採用 (軽微)
- 対応内容: 概念設計の期待効果を「bug-hunt 環境の信頼回復 + 全環境での env 自己参照事故の恒久防止」に精緻化。
  内部寄り項目もユーザー可読な日本語 (例: `attempt_token => 操作トークン`) を与える。補助関数は `array<string, string>` 固定。
