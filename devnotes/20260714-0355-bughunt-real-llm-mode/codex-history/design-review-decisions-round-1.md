# 対応マトリクス: design-review Round 1

## [Critical] 施策4 main_env_get: grep -E "^$1=" がキー名の正規表現メタ文字で壊れる
- 判断: 対応する
- 対応内容: awk による**完全一致**取得に変更（`export` 前置・任意空白を許容し、最初の一致行のみ）。

## [Critical] 施策4 main_env_get: クォート/export/コメント処理が脆弱で実キー誤読の恐れ
- 判断: 対応する
- 根拠: 実キー誤読は real-llm 判定を誤らせる（誤 fail-fast / 空キー通過）。dotenv 相当の堅牢化が必要。
- 対応内容: `export ` 前置対応、単/ダブルクォート両対応、**非クォート値のときのみ後置コメント除去**、前後空白除去。
  併せて Laravel `env()` が文字列 `"true"/"false"` を bool 正規化する事実（script が注入する literal `false` は
  `config('testing.fake_llm')===false` になる）を設計に明記。

## [Warning] 施策4 MODE_ENV を migrate/seed にも注入 = 秘密の配布面積拡大 (最小権限違反)
- 判断: 対応する
- 対応内容: env を 2 分割。`MODE_ENV`（フラグのみ = TESTING_FAKE_LLM/TESTING_FAKE_STORAGE）は serve/worker/
  実効 env 検証 tinker に注入。`LLM_KEY_ENV`（ANTHROPIC_API_KEY）は **serve/worker のみ**に注入（実 LLM を
  呼ぶプロセスに限定）。migrate/seed/verify には実キーを渡さない。verify tinker はフラグのみで config 写像を検証。

## [Warning] 施策4 provision 単体と provision-all で preflight 順序が分岐しうる
- 判断: 対応する
- 対応内容: `prepare_mode_and_preflight()`（= build_mode_env → assert_llm_key_present）に共通化し、
  cmd_provision 冒頭と cmd_provision_all のループ前で同一関数を呼ぶ。

## [Warning] 施策2 docblock/定数/テスト名の三者一致
- 判断: 対応する
- 対応内容: boot() docblock に「LLM fake 許可環境は bughunt.local のみ（定数 LLM_FAKE_ENVIRONMENTS が正本）」を明示。

## [Warning] 施策6 「LLM 実接続のみ許可」が egress/SSRF 誤解を招く
- 判断: 対応する
- 対応内容: 禁止事項 4 の文言を「許可先は LLM プロバイダ API ドメインのみに限定。その他の外部ドメインへの実
  リクエストは従来どおり禁止・検知で即中断」に精緻化。

## [Warning] 施策8 TestingFlagsDefaultTest が将来の phpunit env 追加で脆い
- 判断: 対応する
- 対応内容: テスト名に「env 未設定前提（config 既定）」を明記し、値 + `toBeBool()` を assert。config 既定を
  固定する意図をコメントで明示。

## [Suggestion] 群
- filter_var bool 化: Laravel env() が "true"/"false" を bool 正規化するため既存 (bool) パターンで正しい →
  据え置き（fake_externals と一貫）。理由を設計に明記。
- 施策4 [z3] に「キー名のみ出力は可」を明文化。施策5 冒頭に「script 注入が正本」を前置。
  施策7 に 429/5xx の再試行回数・待機秒フォーマット 1 行。→ いずれも反映。
