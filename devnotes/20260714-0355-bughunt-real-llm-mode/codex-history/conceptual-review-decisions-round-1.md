# 対応マトリクス: conceptual-review Round 1

## [Critical] fake_storage 既定値 true と ProductionEnvGuard / 本番不変の不整合
- 判断: **対応する**
- 根拠: 施策1の「config 既定 true」と施策5の「production で fake_storage が真にならない guard」は
  リテラルには両立しない (既定 true なら production 未設定でも真 = guard 発火 or 無力化)。ユーザーの
  絶対遵守事項は「**bughunt の S3 デフォルト = fake**」であり、config レイヤの既定値までは固定していない。
- 対応内容: `config/testing.php` の `fake_storage` **config 既定を false (production 安全側)** に変更し、
  **bughunt の実効既定 fake は `.env.bughunt.local.example` が明示 `TESTING_FAKE_STORAGE=true` を出荷**する
  ことで達成する (`--real-storage` は false 注入)。fake_llm と対称 (dangerous=true, default=false,
  guard requires false)。production 未設定時の評価値と guard 条件を設計書に明記。

## [Critical] fake_storage 既定値のリスク (本番安全性直結)
- 判断: 対応する (上記と同一根本原因)
- 対応内容: 上記で解消。ProductionEnvGuard は fake_llm=false ∧ fake_storage=false を production で要求する
  (fake_externals と同じ fail-secure)。

## [Warning] fake_externals consumer inventory が未提示
- 判断: **対応する** (棚卸し実施済み)
- 根拠/対応: grep で確認。`config('testing.fake_externals')` の consumer は (a) FakeExternalsServiceProvider
  の Stripe gateway bind (register)、(b) LLM boot (本 item で fake_llm へ移管)、(c) BughuntBillingSeeder、
  (d) BughuntOAuthSeeder の 4 箇所のみ。Captcha は `app()->instance(RecaptchaVerifier::class, ...)` で
  テスト内 bind (fake_externals 非依存)、mail は `MAIL_MAILER=log`、SSO は専用 fake なし。よって LLM 条件を
  fake_llm へ移すと fake_externals は「Stripe gateway + bughunt 2 seeder」専用となり「LLM のみ real、他 fake」が
  厳密に成立する。この inventory を設計書へ追記。

## [Warning] 実キー注入の秘密漏洩ハードニング
- 判断: **対応する**
- 対応内容: 設計に禁止事項を固定「ANTHROPIC_API_KEY は xtrace (set -x) 無効化区間でのみ読む。ログ・stderr・
  manifest・self-test 出力に値を出さない。欠落時メッセージはキー名のみで値を出さない」。

## [Warning] --real-storage をフラグだけ先出しするスコープ誤認
- 判断: **一部対応 (フラグは維持、意味を明示)**
- 根拠: ユーザー確定事項が「real-storage は独立 opt-in トグル・骨子+doc まで」と明示的にトグル骨子を要求。
  よってフラグ自体は残す。ただし Codex 指摘に沿い「real 接続の実配線はスコープ外 = 現状 `--real-storage` は
  `TESTING_FAKE_STORAGE=false` を注入するのみで consumer 未実装 (inert)。将来 item で filesystems.default 切替を
  配線する」と doc/SKILL に明記し「使える機能」に見えないようにする。

## [Warning] real-llm 成立条件 = worker 注入完了
- 判断: 対応する
- 対応内容: 「real-llm の成立は serve だけでなく queue worker (database-analysis 等) への注入まで完了して初めて
  満たす。AI 解析ジョブは worker で走るため worker 注入が本質」と本文に固定。

## [Warning] real-llm × parallel のレート制限・コスト・失敗混線
- 判断: 対応する (運用注記)
- 対応内容: 「real-llm はレート制限・待ち時間・コストを伴う。Anthropic 側失敗は環境ハザードとして UX バグと
  区別して記録する。並列は既定のままだが、必要なら shard 数を抑える運用注記」を SKILL に追記。

## [Warning] 「bughunt に閉じる」表現が強すぎる
- 判断: 対応する
- 対応内容: 「変更点は app-wide (config/testing.php・provider・guard) だが、実効は bughunt.local と script
  注入 flag に限定」に表現修正。

## [Warning] テスト観点が薄い (guard / install 条件)
- 判断: 対応する
- 対応内容: テスト欄に production fail-fast (fake_llm/fake_storage)・bughunt.local∧fake_llm=true install・
  fake_llm=false no-install・config 既定固定を明示。

## [Suggestion] 成功条件 journey の具体化 / bool 前提の型固定
- 判断: 対応する (詳細設計で反映)
- 対応内容: 検証 journey (S3: SOP 取込→シナリオ生成→解析待ち→失敗リカバリ) を成功条件に。config accessor は
  bool cast 前提をテストで固定。
