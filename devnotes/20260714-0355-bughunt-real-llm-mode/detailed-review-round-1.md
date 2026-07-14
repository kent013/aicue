以下、**提供された詳細設計テキストのみ**を対象にレビューします（実コード未読・コマンド未実行）。

**施策別判定**

- **施策1 `config/testing.php`**: **APPROVE**
  - [Suggestion] `env('TESTING_FAKE_LLM', false)` は `"false"` 文字列でも truthy になり得るため、将来事故防止に `filter_var(..., FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false` への統一を検討（既存 `fake_externals` と揃えるなら今回は据え置きでも可）。

- **施策2 `FakeExternalsServiceProvider::boot()`**: **APPROVE**
  - [Warning] 設計文中で `testing/local は allowlist から除外` とある一方、現行説明で `LLM_FAKE_ENVIRONMENTS=['bughunt.local']` 前提。ここはテスト名・docblock・定数の三者を完全一致させないと誤読しやすい。  
    **修正案**: docblock に「LLM fake 許可環境は `bughunt.local` のみ（定数を正本）」を1行で明示。
  - [Suggestion] `config('testing.fake_llm')` 参照は1回ローカル変数に受けると可読性向上。

- **施策3 `ProductionEnvGuard`**: **APPROVE**
  - [Suggestion] violation メッセージ文言を `TESTING_FAKE_* must be false...` で完全に同型化しておくと将来のテストメンテが容易。

- **施策4 `scripts/bug-hunt-shard.sh`**: **REQUEST_CHANGES**
  - [Critical] `main_env_get()` の `grep -E "^$1="` はキー名に正規表現メタ文字が入ると壊れうる設計。今回は定数キー運用でも関数としては脆い。  
    **修正案**: `awk -F= -v k="$1" '$1==k{print substr($0,index($0,"=")+1); exit}'` など**完全一致**で取得。
  - [Critical] `main_env_get()` のクォート除去は単純で、`.env` の `export KEY=...`・シングルクォート・エスケープ・`#` 含有値に弱い。実キーが誤読されると real-llm 誤判定。  
    **修正案**: 既存リポの dotenv ローダ方針に寄せるか、少なくとも `export ` 対応＋単/ダブルクォート両対応＋コメント除去条件（非クォート時のみ）を実装。
  - [Warning] `MODE_ENV` を `artisan_for_shard` にも注入する設計は「DB/seed 系にも秘密を配る」面積拡大。要件上許容でも最小権限原則に反する。  
    **修正案**: `serve`/`worker`/`tinker検証` のみに注入し、`migrate/seed` は除外（必要時のみ追加）。
  - [Warning] `assert_llm_key_present` を `provision-all` 前に1回だけ行うのは良いが、`provision` 単体でも同じチェック順序を固定しないと分岐差が出る。  
    **修正案**: `cmd_provision` 冒頭で `build_mode_env`→`assert_llm_key_present` を共通関数化して呼ぶ。
  - [Suggestion] self-test `[z3]` は「キー値不出力」だけでなく「キー名のみ出力可」を明文化すると運用事故を減らせる。

- **施策5 `.env.bughunt.local.example`**: **APPROVE**
  - [Suggestion] 「script 注入が正本」を冒頭に移して、説明コメントの読了前に誤設定されるリスクを低減。

- **施策6 `app-bug-hunt/SKILL.md`**: **APPROVE**
  - [Warning] 「LLM実接続のみ許可」の文言は SSRF/egress ガード誤解を招きやすい。  
    **修正案**: 「許可先は LLM プロバイダ API ドメインのみに限定、その他外部は従来どおり禁止」を明記。

- **施策7 `stories/S3-core-journey.md`**: **APPROVE**
  - [Suggestion] 429/5xx を「環境ハザード」と記録する際、再試行回数・待機秒の記録フォーマットを1行追加すると比較可能性が上がる。

- **施策8 テスト波及**: **APPROVE**
  - [Warning] `TestingFlagsDefaultTest` は phpunit 環境変数が将来追加された際に壊れやすい。  
    **修正案**: 「env未設定前提」をテスト名に明記、または `putenv('TESTING_FAKE_LLM'); putenv('TESTING_FAKE_STORAGE');` で明示クリアしてから検証。
  - [Suggestion] `FakeExternalsServiceProviderTest` に「`fake_llm=true` かつ `environment=bughunt.local` のみ install」をデータセット化すると網羅漏れ防止。

**全体判定**

- **CHANGES_REQUESTED**

主理由は**施策4（bash）に実装リスクが集中**しているためです。  
特に `main_env_get()` の取得ロジックは、dev DB 保護・production fail-secure と同等に「誤判定しないこと」が重要で、ここを堅牢化すれば全体はほぼ承認可能です。必要なら次ターンで、`main_env_get` の安全実装案（差分レベル）を具体化します。