## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)



【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【この設計に固有の論点 — 必ず判定を書くこと】
- 本番で「利用者ハンドルの導出鍵が APP_KEY と同一なら起動を止める」ことは妥当か。過剰か。
- relying party id / 許可する接続元を「env で明示必須」にせず「APP_URL 由来の解決値を検査する」方針は妥当か。
- 版 pin の検査を composer.lock の解決値で行うか、composer.json の制約で行うか、両方か。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: passkey-config-hardening

## 背景・課題

パスキー (WebAuthn) は **単独でログインできる強い資格**であり、その正しさは 3 つの設定値に依存する。

| 値 | 役割 | 現状 (HEAD 実測) |
|----|------|------------------|
| relying party id (身元の識別子) | パスキーがどのドメインに束縛されるか | vendor 既定 `parse_url(config('app.url'), PHP_URL_HOST)` |
| allowed origins (許可する接続元) | どの接続元からの WebAuthn 手続きを受け入れるか | vendor 既定 `[config('app.url')]` |
| user handle secret (利用者ハンドルの導出鍵) | 利用者ハンドル (`hash_hmac`) の鍵 | vendor 既定 `env('PASSKEYS_USER_HANDLE_SECRET', config('app.key'))` |

実測した現状:

- `config/passkeys.php` は**アプリ側に存在しない**。3 値ともパッケージ既定
  (`vendor/laravel/passkeys/config/passkeys.php`) のまま。
- `.env.example` に `PASSKEY` を含む行は **0 件**。
  代わりに「パスキーは専用の env を持たない」という説明段落が置かれている。
- `ProductionEnvGuard` は 13 項目を本番起動時に fail-fast 検査するが、**パスキーの 3 値は 1 つも見ていない**。
- `docs/auth-security-mechanisms.md` §5 の「運用上の注意」に
  「`APP_KEY` をローテートすると利用者ハンドルが変わり登録済みパスキーが**全件無効**になる。
  鍵ローテートを行う場合は `PASSKEYS_USER_HANDLE_SECRET` 相当の固定値を
  `config/passkeys.php` に持たせる**設計変更が必要**」と、必要な設計変更が未着手のまま記録されている。

このため次の設定事故が **起動時には検出できず、利用者がパスキーを使う瞬間まで表面化しない**:

1. `APP_URL` が本番で誤っている / scheme が `http` / host が `localhost` のまま → RP ID と許可する接続元が
   まとめて誤り、登録は成功するのに検証が全件失敗する (あるいは意図しないドメインにパスキーが束縛される)。
2. `APP_URL` が host を持たない値 → `Config::string('passkeys.relying_party_id')` が
   手続きの実行時に例外になり **500** になる (起動時ではない)。
3. `PASSKEYS_USER_HANDLE_SECRET` 未宣言のまま `APP_KEY` をローテート →
   **登録済みパスキーが全件無効**。利用者から見ると「昨日まで使えた指紋認証が今日から通らない」。

もう 1 つの課題が **版の固定**である。`laravel/passkeys` は `laravel/fortify` の推移依存として入っており
(実測: 制約 `^1.37` → `laravel/fortify v1.37.2` → その要求 `laravel/passkeys ^0.2.0` → 解決値 **v0.2.1**)、
`composer.json` に**直接の要求が無い**。しかしアプリは `Laravel\Passkeys\*` を
Provider / Response / binder / 契約検査など **10 ファイル以上で直接 import している**。
`laravel/passkeys` は 0.x であり semver の後方互換保証が無いため、
`0.3.0` が入ると設定キー名・契約インタフェース・route 名が予告なく変わりうる。
既存の契約検査 `tests/Architecture/PasskeyPackageContractTest.php` は 9 本あるが、
**どの版に対して検証済みなのかを固定する検査を持たない**。

## 改善アイデア

**施策 A (設定の明示と本番 fail-fast)**

1. アプリ側 `config/passkeys.php` を新設し、上記 3 値を**明示的に宣言**する
   (vendor の `mergeConfigFrom` は上位キー単位でアプリ側が勝つため、この 3 キーだけを持つ最小のファイルにする)。
   既定値は現行と同じく `APP_URL` / `APP_KEY` からの導出を残しつつ、
   `PASSKEYS_RELYING_PARTY_ID` / `PASSKEYS_ALLOWED_ORIGINS` / `PASSKEYS_USER_HANDLE_SECRET` の env で上書きできるようにする。
2. `app/Support/PasskeyConfigValidator.php` (純粋クラス・`final`・`RuntimeException`) を新設し、
   `ProductionEnvGuard::violations()` から呼ぶ。**`TrustedProxiesConfigValidator` / `TrustedHostsConfigValidator` と完全に同形**。
   本番で次のいずれかなら起動を止める:
   - RP ID が空 / host 形式でない / ラベルが 1 つだけ (`localhost` 等) / IPv4 リテラル
   - 許可する接続元が空 / `https://host[:port]` 形式でない / path・query を含む / `http` scheme
   - 接続元の host が RP ID と一致せず、その下位ドメインでもない (WebAuthn が要求する関係)
   - 利用者ハンドルの導出鍵が空、または **`APP_KEY` と同一** (= 未宣言。鍵ローテートで全件無効になる状態)
3. `.env.example` に 3 つのキーを追記し、既存の「専用の env を持たない」段落を書き換える。
   `tests/Architecture/EnvExampleInvariantTest.php` の作法に合わせ、
   **本番で未宣言なら起動が止まるキー** (`PASSKEYS_USER_HANDLE_SECRET`) の提示を検査で固定する。

**施策 B (版 pin)**

4. `composer.json` に `laravel/passkeys` の直接要求を追加する
   (直接 import しているパッケージは直接要求するのが Composer の作法。
   家系 6 本のうち aicue だけがこれを持たない)。
5. `tests/Architecture/PasskeyPackageContractTest.php` に版 pin の検査を 2 本足す。
   **`composer.lock` の解決値**と **`composer.json` の制約**の両方を見る (根拠は後述)。

## 期待効果

- 使命への貢献: 撮影 PWA (同一オリジン・セッション認証) の主戦場はスマホであり、
  パスキーは現場作業者が最も摩擦なくログインできる手段である。
  **設定事故で「昨日まで使えた指紋認証が通らない」状態を作らない**ことは、
  「思考ゼロ」で現場が使い続けられることの前提になる。
- 設定事故の検出時点が「利用者が認証しようとした瞬間 (本番・個別ユーザー)」から
  「デプロイ前の起動時 (`production:preflight` で機械判定)」へ前倒しになる。
- `APP_KEY` ローテートとパスキーの生存が**分離**される (現在は連動していて、
  ローテート = 全パスキー無効という地雷が docs に記録されたまま放置されている)。
- パッケージが 0.3 系に上がったとき、契約検査 9 本の前提を再確認する前に
  **無言で入ることがなくなる**。

## 実装方針（概要）

| # | 変更 | 種別 |
|---|------|------|
| A-1 | `config/passkeys.php` 新設 (3 キー) | 新規 |
| A-2 | `app/Support/PasskeyConfigValidator.php` 新設 | 新規 |
| A-3 | `app/Support/ProductionEnvGuard.php` に検査を追加 (feature flag が有効なときだけ) | 変更 |
| A-4 | `.env.example` の追記・既存段落の書き換え | 変更 |
| A-5 | `docs/auth-security-mechanisms.md` §5 運用上の注意の更新 / `AGENTS.md` の運用要件に 1 段落 | 変更 |
| B-1 | `composer.json` に `laravel/passkeys` の直接要求 (+ `composer.lock` の更新) | 変更 |
| B-2 | `tests/Architecture/PasskeyPackageContractTest.php` に版 pin 検査 2 本 | 変更 |

テスト (施策ごとに必須):

- `tests/Unit/Support/PasskeyConfigValidatorTest.php` (新規): 純粋クラスの全検査の正常系・異常系。
- `tests/Feature/Support/ProductionEnvGuardTest.php`: baseline に有効値を足し、1 項目ずつ崩す検査を追加。
  feature flag が無効なら検査ごと skip されることも固定する。
- `tests/Feature/Config/ConfigHardeningTest.php`: 既存 helper `evaluateConfigFileWithEnv()` で
  `config/passkeys.php` の env 派生 (未設定時の `APP_URL` 導出 / env 明示時の優先 / CSV 分割) を固定。
- `tests/Architecture/EnvExampleInvariantTest.php`: `PASSKEYS_USER_HANDLE_SECRET=` の提示を固定。
- `tests/Architecture/PasskeyPackageContractTest.php`: 版 pin 2 本 + 設定 3 キーが config cache 往復後も残ること。

## 制約・前提

- **既存の作法に寄せる**のが最優先。`TrustedProxiesConfigValidator` (純粋クラス + `ProductionEnvGuard` からの
  try/catch 写像 + `production:preflight` からの再利用 + `.env.example` の提示 + Unit/Feature テスト) が完成した型として
  既にあるため、パスキーもそこへ**そのまま**乗せる。新しい機構は作らない。
- `laravel/passkeys` の設定キー名は 0.2 系の契約である。施策 B の版 pin はこの前提の保護でもある
  (アプリ側 `config/passkeys.php` は「vendor と同じキー名」でしか効かないため、
  キー名が変わると**無言で既定へ戻る**)。
- 本リポジトリに**デプロイ定義は無い** (AGENTS.md)。したがって本施策も「人手で守る運用要件」が 1 つ増える。
  存在しないデプロイ基盤のための preflight 機構は**作らない** (既存 `production:preflight` に相乗りするだけ)。
- 既に本番でパスキーが登録されている環境では、`PASSKEYS_USER_HANDLE_SECRET` に
  **現行 `APP_KEY` の値をそのまま**入れれば既存パスキーは維持される。この手順を docs に書く。

## スコープ外

- ledger の施策 3b (パッケージ側の削除処理を参照する箇所) — 本件の範囲外。
- 施策 3a (所有者 FK の文字列比較) / 施策 4 (2FA 許可一覧) — 実装済み。
- `laravel/passkeys` の `timeout` / `guard` / `middleware` / `redirect` 等、
  事故が観測されていないキーの明示化 (今必要なものだけ作る)。
- パスキー専用の runbook 新設 (`docs/auth-security-mechanisms.md` §5 が正本のまま)。
- RP ID の public suffix 判定 (`TrustedHostsConfigValidator` と同じ理由で format 検査に留める)。
