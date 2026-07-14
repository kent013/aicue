**全体判定: CHANGES_REQUESTED**

**レビュー結果（ファイル別）**
- `scripts/bug-hunt-shard.sh`
  - [Critical] `usage()` の表示範囲が `sed -n '2,54p'` に固定されており、追加したモード説明行（ヘッダコメント側）が表示範囲外に落ちるリスクがあります。設計では「usage にモード表を明記」が要件なので、実際に `usage` 出力へ `--real-llm/--fake-llm/--real-storage` が確実に出る実装（範囲拡張または固定行数依存の除去）が必要です。
  - [Warning] `cmd_provision_all()` の preflight だけ `is_dryrun || prepare_mode_and_preflight` でスキップしており、`cmd_provision()` 側は dryrun 条件なしで preflight 実行です。self-test で通っているため致命ではないですが、設計意図（共通化）としては挙動差をなくした方が安全です。
  - [Suggestion] `main_env_get()` は十分堅牢ですが、`.env` の `KEY=...` 前後空白（`KEY = value`）は拾わない実装です。運用で許容しない前提ならコメントで明示すると誤解を防げます。

- `tests/Feature/Config/TestingFlagsDefaultTest.php`
  - [Warning] 仕様意図は満たしていますが、`config(...)` を2回呼ぶより一度変数へ受けて `toBeFalse()->toBeBool()` 連鎖にすると読みやすさが上がります（品質上は問題なし）。
  - [Suggestion] テスト名は十分明確。現状維持で可。

- `app/Providers/FakeExternalsServiceProvider.php`
  - [Warning] 設計どおり `boot` 判定を `fake_llm` に分離できています。`LLM_FAKE_ENVIRONMENTS` の正本化コメントも一致。問題なし。
  - [Suggestion] docblock が厚めなので、将来の drift 防止として「register=Stripe / boot=LLM」の1行要約を先頭に置くと更に読みやすいです。

- `app/Support/ProductionEnvGuard.php`
  - [Warning] `fake_llm` / `fake_storage` guard 追加は設計一致。`violations()` の既存パターン維持も良好。
  - [Suggestion] なし。

- `config/testing.php`
  - [Warning] 3フラグ分離は設計一致、既定 false も適切。
  - [Suggestion] なし。

- `.env.bughunt.local.example`
  - [Warning] 「script注入が正本」明記、real-llm 必須キーの説明ともに設計一致。
  - [Suggestion] なし。

- `.claude/skills/app-bug-hunt/SKILL.md`
  - [Warning] 既定 real-llm、禁止事項4の改訂、モード表の反映はいずれも設計一致。
  - [Suggestion] なし。

- `.claude/skills/app-bug-hunt/stories/S3-core-journey.md`
  - [Warning] 非決定性・ハザード記録フォーマット追記は設計一致。
  - [Suggestion] なし。

- `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`
  - [Warning] `fake_llm` への置換、分離回帰テスト追加ともに良いです。register(Stripe)系を不変で残している点も適切。
  - [Suggestion] なし。

- `tests/Feature/Support/ProductionEnvGuardTest.php`
  - [Warning] baseline更新と2件の新規 violation テスト追加は設計一致。
  - [Suggestion] なし。

**総評**
- 設計8施策への適合は概ね高く、特に `fake_llm` 分離・production guard・self-test[z] 強化は良い実装です。  
- ただし **`usage()` の固定行数依存**は要件（モード説明の明示）を将来壊しやすい実装で、ここは修正推奨ではなく**修正必須**です。  
- これが解消されれば、他は大きな懸念なく **APPROVED** にできます。