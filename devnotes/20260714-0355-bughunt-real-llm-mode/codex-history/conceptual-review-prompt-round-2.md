# 概念設計レビュー Round 2 — 指摘への対応報告

Round 1 の指摘に対し、以下のとおり概念設計を修正しました。対応マトリクスの要点:

## [Critical] fake_storage 既定値 true の不整合 → 対応 (設計修正)
- `config/testing.php` の `fake_storage` **config 既定を false** (production 安全側) に変更。fake_llm と対称
  (dangerous=true / default=false / guard requires false)。
- ユーザー確定事項の「bughunt の S3 デフォルト = fake」は **`.env.bughunt.local` が明示 `TESTING_FAKE_STORAGE=true`
  を出荷**することで達成 (config 既定ではなく bughunt env の明示値で fake を効かせる)。`--real-storage` は
  false 注入。
- production 未設定時の評価値 (= false) と guard 条件 (production で fake_llm=false ∧ fake_storage=false 必須) を
  設計書に明記。

## [Warning] fake_externals consumer inventory → 対応 (棚卸し追記)
- grep 実施。consumer は 4 箇所のみ: (a) FakeExternalsServiceProvider の Stripe gateway bind、(b) 同 LLM boot
  (本 item で fake_llm へ移管)、(c) BughuntBillingSeeder、(d) BughuntOAuthSeeder。Captcha は
  `app()->instance(RecaptchaVerifier::class,...)`、mail は `MAIL_MAILER=log`、SSO は専用 fake なし。
  → LLM を fake_llm へ移すと fake_externals は「Stripe + bughunt 2 seeder」専用となり「LLM のみ real、他 fake」が
  厳密成立。設計書に inventory を追記。

## [Warning] 実キー秘密ハードニング → 対応
- 「ANTHROPIC_API_KEY は xtrace 無効化区間でのみ読み、値をログ/stderr/manifest/self-test に出さない。欠落時
  メッセージはキー名のみ」を設計に固定。

## [Warning] --real-storage 先出しのスコープ誤認 → 一部対応 (フラグ維持・意味明示)
- ユーザー確定事項がトグル骨子を要求のためフラグは残す。ただし「real 接続の実配線はスコープ外 = 現状は
  TESTING_FAKE_STORAGE=false を注入するのみで consumer 未実装 (inert)。将来 item で filesystems.default 切替を
  配線」と doc/SKILL に明記。

## [Warning] real-llm 成立条件 = worker 注入完了 → 対応
- 「AI 解析ジョブは worker で走るため、real-llm は serve だけでなく worker env への実キー注入まで完了して初めて
  成立」と本文固定。

## [Warning] real-llm × parallel のレート制限混線 → 対応 (運用注記)
- 「Anthropic 側失敗 (429/5xx) は環境ハザードとして UX バグと区別。並列はレート制限が疑われれば shard 数を抑える」
  を SKILL 注記。

## [Warning] 「bughunt に閉じる」表現 → 対応
- 「変更点は app-wide (config/testing.php・provider・guard) だが、実効は bughunt.local と script 注入 flag に限定」に修正。

## [Warning] テスト観点 → 対応
- production fail-fast (fake_llm/fake_storage)・bughunt.local∧fake_llm=true install・fake_llm=false no-install・
  config 既定固定を明示。

## [Suggestion] 成功条件 journey / bool 前提 → 対応
- 検証 journey (S3: SOP 取込→シナリオ生成→解析待ち→失敗リカバリ) を成功条件に。config accessor は bool cast 前提を
  テストで固定。

---

修正後の概念設計全文を再掲します。全体判定 (APPROVED / CHANGES_REQUESTED) を再度お願いします。残 Critical/Warning が
あれば具体的な修正案を添えてください。

## 修正後 概念設計

# 概念設計: bughunt real-llm モード (既定) と fake-llm / real-storage オプション

## 背景・課題

- AI-CUE は **LLM 駆動プロダクト**である。North Star の中核チェーン (SOP 抽出 → シナリオ生成 →
  作業分解 → 撮影 → レンダー) は LLM (Prism/Anthropic) の実挙動に品質が依存する。
- 直前の TODO T035 で bughunt 実行時に LLM の canned fake (`CannedPromptFakeRegistrar` →
  `Prompt::installFake`) を配線したが、install 条件が **`config('testing.fake_externals') === true`**
  になっている。bughunt 既定は `TESTING_FAKE_EXTERNALS=true` (`.env.bughunt.local.example`) のため、
  現状 **bughunt は常に fake-llm** で走る (`FakeExternalsServiceProvider::boot`)。
- 探索的 bug-hunt は本来「回帰テストでは見つからない UX 破綻」を実挙動で発見する仕組みである。
  LLM を fake すると AI 中核チェーンの実挙動 (生成品質・エラー分岐・待ち時間・失敗リカバリ UX) を
  検証できず、bug-hunt の使命 (dogfooding による UX 品質検証) を果たせない。
- 前回 bug-hunt の Q1「LLM 401 (空キーで実 API に飛んだ)」の恒久対応も兼ねる。

## 改善アイデア

bughunt の走行モードを **real-llm (既定)** に切り替え、fake-llm を opt-in にする。**LLM のみ real**、
その他外部 (Stripe / Captcha / SSO / mail / S3) は fake のまま維持する。

1. **fake の判定軸を「外部一括」から「系統別」へ分離する**。
   `config/testing.php` に新フラグを 2 本追加:
   - `fake_llm` (env `TESTING_FAKE_LLM`, **config 既定 false** = real): LLM (Prism) fake を install するか。
   - `fake_storage` (env `TESTING_FAKE_STORAGE`, **config 既定 false** = 本番安全側): S3 ストレージ fake トグル (骨子)。
   - `fake_externals` (既存) は **Stripe 課金 fake の capability flag として存置**する。

   > **config 既定と bughunt 実効既定の分離 (Codex R1 Critical 反映)**: 両フラグとも **config 既定は
   > false** (production 未設定時に fake が真にならない = `ProductionEnvGuard` と対称・fail-secure)。
   > ユーザー確定事項の「**bughunt の S3 デフォルト = fake**」は、**`.env.bughunt.local` が明示的に
   > `TESTING_FAKE_STORAGE=true` を出荷**することで達成する (config レイヤの既定値ではなく bughunt env の
   > 明示値で fake を効かせる)。fake_llm は bughunt でも未設定 (=false=real) が既定で、`--fake-llm` 指定時のみ
   > script が `TESTING_FAKE_LLM=true` を注入する。
   >
   > **fake_externals consumer inventory (Codex R1 Warning 反映、grep 実施済み)**: `config('testing.fake_externals')`
   > の consumer は (a) `FakeExternalsServiceProvider` の Stripe gateway bind (register)、(b) 同 LLM boot
   > (本 item で `fake_llm` へ移管)、(c) `BughuntBillingSeeder`、(d) `BughuntOAuthSeeder` の 4 箇所のみ。
   > Captcha は `app()->instance(RecaptchaVerifier::class, ...)` でテスト内 bind (fake_externals 非依存)、
   > mail は `MAIL_MAILER=log`、SSO は専用 fake なし。よって LLM 条件を `fake_llm` へ移すと `fake_externals` は
   > 「Stripe gateway + bughunt 2 seeder」専用となり、「LLM のみ real、その他 (Stripe/Captcha/SSO/mail/S3) は
   > fake 維持」が厳密に成立する (bughunt seeder 2 種は billing/oauth で LLM と直交=不変)。
2. **`FakeExternalsServiceProvider::boot()` の LLM fake install 条件を
   `fake_externals` から `fake_llm` へ差し替える**。env allowlist (`LLM_FAKE_ENVIRONMENTS=['bughunt.local']`)
   は維持。既定 (fake_llm off) では install しない = real LLM。Stripe fake の `register()` 経路は
   `fake_externals` 依存のまま**不変**。
3. **`scripts/bug-hunt-shard.sh` にモードフラグを追加**する:
   - `--real-llm` (既定) / `--fake-llm` / `--real-storage` (既定 fake)。
   - real-llm (既定): 親リポジトリ `.env` の `ANTHROPIC_API_KEY` を **env -i 隔離後の serve/worker env へ
     注入**する。**キーが空/未設定なら provision を fail-fast** (明確なメッセージ + `--fake-llm` 案内)。
   - `--fake-llm`: serve/worker env に `TESTING_FAKE_LLM=true` を渡す (実キー注入・要求はしない)。
   - `--real-storage`: `TESTING_FAKE_STORAGE=false` を渡す。既定は fake storage を維持。
     **real 接続の実配線はスコープ外 = 現状 `--real-storage` は env flag を false にするのみで consumer 未実装
     (inert)**。将来 item で `filesystems.default` 切替を配線する旨を doc/SKILL に明記し「使える機能」に
     見えないようにする (Codex R1 Warning 反映)。
   - **秘密ハードニング (Codex R1 Warning 反映)**: `ANTHROPIC_API_KEY` は `set -x` (xtrace) 無効化区間で
     のみ読み、値をログ・stderr・manifest・self-test 出力に決して出さない。欠落時の fail-fast メッセージも
     **キー名のみ**で値は出さない。
4. **ドキュメント/スキル整合**: `.env.bughunt.local.example` にフラグ説明追記、
   `app-bug-hunt/SKILL.md` の禁止事項 4 とモード表を real-llm 前提に改訂、
   `stories/S3-core-journey.md` を実 AI 走行前提へ更新。
5. **production 防御**: `ProductionEnvGuard` に `fake_llm` / `fake_storage` の fail-secure guard を追加
   (production で危険な組合せにならないことを既存 `fake_externals` と同様に固定)。

## 期待効果

- **使命への貢献**: bug-hunt が AI 中核チェーンの実挙動を検証できるようになり、LLM 由来の UX 破綻
  (生成失敗時の詰み・待ち時間の無反応・生成結果の矛盾) を dogfooding で発見できる。これは
  「回帰テストでは見つからない UX 破綻の発見」という bug-hunt の存在意義そのものの回復である。
- **事故防止**: real-llm 既定で実キー未設定なら fail-fast することで、空キーで実 API に飛んで 401 に
  なる事故 (Q1) を構造的に塞ぐ。fake-llm への退避導線 (`--fake-llm`) も明示する。
- **本番挙動は不変 (実効の限定)**: 変更点は app-wide (`config/testing.php`・provider・guard) だが、
  **実効は bughunt.local と script 注入 flag に限定**される (Codex R1 Warning 反映で表現を精緻化)。
  production の fake 判定は `ProductionEnvGuard` の fail-secure で守られ、両フラグとも config 既定 false =
  production 未設定時の評価値は false (fake が真にならない)。guard は production で `fake_llm=false` かつ
  `fake_storage=false` を必須化する (fake_externals と同じ fail-secure)。
- **real-llm の成立条件 = worker 注入完了 (Codex R1 Warning 反映)**: AI 解析ジョブ (`RunManualAnalysis` 等) は
  queue worker (`database-analysis` 等) プロセスで走るため、real-llm は **serve だけでなく worker env への
  実キー注入まで完了して初めて成立**する (serve のみだと「ブラウザは通るがジョブで 401」が再発する)。

## 実装方針（概要）

| コンポーネント | 変更概要 |
|---|---|
| `config/testing.php` | `fake_llm` (既定 false) / `fake_storage` (既定 true) を追加。docblock 更新 |
| `app/Providers/FakeExternalsServiceProvider.php` | `boot()` の LLM fake 条件を `fake_llm` へ。docblock を real-llm 既定へ更新。`register()` (Stripe) は不変 |
| `app/Support/ProductionEnvGuard.php` | `fake_llm` / `fake_storage` の production fail-secure guard 追加 |
| `scripts/bug-hunt-shard.sh` | モードフラグ (`--real-llm`/`--fake-llm`/`--real-storage`) + real-llm 実キー注入 + fail-fast + self-test 分岐 |
| `.env.bughunt.local.example` | フラグ説明 + ANTHROPIC_API_KEY (親 .env 由来で serve に注入) の記載 |
| `.claude/skills/app-bug-hunt/SKILL.md` | 既定引数・禁止事項 4・モード表・環境前提を real-llm 前提へ |
| `.claude/skills/app-bug-hunt/stories/S3-core-journey.md` | 実 AI 応答前提へ期待更新 |
| テスト | `config/testing.php` 既定固定 / `FakeExternalsServiceProvider` boot 条件 (fake_llm) / `ProductionEnvGuard` guard / self-test モード分岐 |

### 実キー注入とフェイルファストの設計 (中核)

- Anthropic キーは `config/prism.php` の `prism.providers.anthropic.api_key` = env `ANTHROPIC_API_KEY`。
  worktree では `setup-worktree.sh` が親 `.env` をコピー済みのため、リポジトリルート `.env` から読める。
- provision (real-llm 既定): `.env` (bughunt env ではなく親 dotenv) から `ANTHROPIC_API_KEY` を読み、
  serve と queue worker (AI 解析は `database-analysis` worker で走るため worker への注入が本質) の
  `env -i` 行に `ANTHROPIC_API_KEY=<値>` を明示注入する。空/未設定なら provision を die。
- AI 解析ジョブ (`RunManualAnalysis` 等) は queue worker プロセスで実行されるため、serve だけでなく
  **worker env への注入が必須**。serve は同期経路 (フォーム送信のバリデーション等) をカバー。

## 制約・前提

- **本番挙動は不変** (bughunt 基盤/スキルの変更に閉じる)。
- LLM のみ real。**Stripe / Captcha / SSO / mail / S3 は fake 維持**。
- env allowlist (`LLM_FAKE_ENVIRONMENTS=['bughunt.local']`) と `env -i` 隔離 (dev DB 保護) は不変。
- 既存の Browser lane (`tests/Pest.php` の `CannedPromptFakeRegistrar` install) と Feature/Unit の
  Prism fake 経路・`StrayLlmCallGuard` を壊さない (これらは `fake_externals`/`fake_llm` に依存せず、
  テスト内で明示的に fake を install するため影響なし)。
- 実キーの取り扱いはログ・manifest に平文で残さない (キー値を echo/manifest_update に渡さない。
  xtrace 無効区間で読む)。
- **成功条件 (検証 journey、Codex R1 Suggestion 反映)**: real-llm 化の成功は、S3 コアジャーニー
  (SOP 取込 → シナリオ生成 → 解析待ち → 失敗リカバリ) で **fake では見えない待機/失敗 UX を実 AI 応答で
  観測できること**。config accessor は bool cast 前提をテストで固定する (mixed 分岐を避ける)。
- **運用注記 (real-llm × parallel、Codex R1 Warning 反映)**: real-llm はレート制限・待ち時間・API コストを
  伴う。Anthropic 側の失敗 (429/5xx) は環境ハザードとして UX バグと区別して記録する。並列 shard は既定の
  ままだが、レート制限が疑われる場合は shard 数を抑える運用を SKILL に注記する。

## スコープ外

- **実 S3 ストレージの実接続実装** (region/bucket 設定・presigned の実 S3 疎通)。本 item は
  `fake_storage` トグルの骨子 (フラグ + script フラグ + doc) までとし、`fake_storage=false` が
  filesystems.default をどう切り替えるかの実配線は別 opt-in item とする。
- **ffmpeg 不在** (Q1 の残件、レンダー実行環境の整備) は本 item のスコープ外。
- コード実装・TODO 登録 (本フローは設計のみ)。

