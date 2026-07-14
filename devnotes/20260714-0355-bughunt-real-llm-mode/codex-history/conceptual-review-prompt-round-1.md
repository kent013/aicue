# レビュー依頼: 概念設計 (bughunt real-llm モード)

## アプリの使命 (North Star / AGENTS.md より)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。
- v1 スコープ: 字幕のみ / 撮影は PWA (同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md より)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き (`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件 (抜粋)

- 外部 fake は本番混入禁止 (ProductionEnvGuard が fail-fast)。
- 実キー等の秘密情報をログ/成果物に平文で残さない。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。Laravel/Svelte エコシステムに既存解があるなら使え。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が正しいと確認できてから調整せよ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション (Laravel 12 + Svelte 5 + Inertia) の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命 (North Star) に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か (Laravel 12 + Svelte 5 + Inertia.js)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか (特に「本番挙動は不変」の主張が守られるか、
   実キー注入の秘密漏洩リスク、dev DB 保護の env -i 隔離を壊さないか、既存テスト経路への波及)
6. スコープの適切さ: 過大または過小になっていないか (real-storage 骨子化 / ffmpeg スコープ外の妥当性)
7. 型安全性: DTO/JsonResource パターンや PHPStan level 10 に沿えるか (本 item は config/provider/script 中心)

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260714-0355-bughunt-real-llm-mode/conceptual-design.md の内容）

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
   - `fake_llm` (env `TESTING_FAKE_LLM`, 既定 **false** = real): LLM (Prism) fake を install するか。
   - `fake_storage` (env `TESTING_FAKE_STORAGE`, 既定 **true** = fake): S3 ストレージ fake トグル (骨子)。
   - `fake_externals` (既存) は **Stripe 課金 fake の capability flag として存置**する。
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
- **本番挙動は不変**: 変更は bughunt 基盤/スキルに閉じる。production の fake 判定は
  `ProductionEnvGuard` の fail-secure で従来どおり守られ、フラグ既定は本番安全側 (fake_llm=false は
  「LLM を fake しない」= 本番と同じ、fake_storage=true は bughunt 専用トグルで本番未設定なら config 既定)。

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
- 実キーの取り扱いはログ・manifest に平文で残さない (キー値を echo/manifest_update に渡さない)。

## スコープ外

- **実 S3 ストレージの実接続実装** (region/bucket 設定・presigned の実 S3 疎通)。本 item は
  `fake_storage` トグルの骨子 (フラグ + script フラグ + doc) までとし、`fake_storage=false` が
  filesystems.default をどう切り替えるかの実配線は別 opt-in item とする。
- **ffmpeg 不在** (Q1 の残件、レンダー実行環境の整備) は本 item のスコープ外。
- コード実装・TODO 登録 (本フローは設計のみ)。

