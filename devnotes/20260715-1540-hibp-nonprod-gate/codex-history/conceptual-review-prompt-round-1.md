# アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

# セキュリティ不変条件(アプリ都合で緩めない)

1. tenant キー不信 / 2. 子は親に属する(404 先行) / 3. cross-org 不可 / 4. untrusted 文字列は UserInput 型経由 / 5. 権限判定は laratrust_team_id 明示 / 6. PII は CipherSweet + whereBlind / 7. 課金の冪等性 / 8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解してから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか。特に**セキュリティ不変条件**(production で HIBP=uncompromised が必ず有効)が守られているか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: PHPStan level 10 を通せるか

【本設計のセキュリティ上の要点】
- production では uncompromised() が**必ず**有効でなければならない(本番で誤って無効化されない fail-secure)。
- fake_externals は ProductionEnvGuard により production で true になれない(deploy 時 fail-fast)。
- 判定を PasswordPolicy 内の単一述語 shouldCheckPwned() に集約し、production 分岐を最初に評価する順序で fail-secure を担保する設計。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260715-1540-hibp-nonprod-gate/conceptual-design.md の全文。リポジトリの実ファイルも読んでよい)

# 概念設計: HIBP(uncompromised)照合を非本番環境で無効化 (hibp-nonprod-gate)

## 背景・課題

bug-hunt run 20260715-084108 の F-4-01「登録/リセット/変更フォームの POST が 10〜14 秒かかる」の根因(基盤)対応。

HIBP = "Have I Been Pwned"(漏洩パスワード照合サービス, `api.pwnedpasswords.com`)。Laravel の `Password::uncompromised()` ルールは、パスワード SHA-1 プレフィックスを HIBP の k-Anonymity API へ HTTPS で問い合わせ、漏洩既知のパスワードを弾く。

現状 `app/Support/PasswordPolicy::rule()`(`app/Support/PasswordPolicy.php:32`):

```php
return App::runningUnitTests() ? $rule : $rule->uncompromised();
```

**ユニットテスト実行時のみ** HIBP 照合を外し、それ以外(**local 開発 / bughunt.local / feature ブラウザテスト**)では実 HIBP へ HTTPS リクエストが飛ぶ。これが以下を生む:

1. **遅延**: フォーム送信のたびに 10〜14 秒の外部 round-trip(F-4-01 の根因)
2. **外部依存・非決定性**: 開発/ブラウザテストが外部サービスの可用性・レイテンシに依存し flaky 化
3. **禁止事項との不整合**: AGENTS.md bug-hunt 節「fake-externals: bughunt は外部を fake する」の原則(隔離 bughunt 環境で外部へ実リクエストを出さない)に反する

参照実装(aigenba)は非本番で HIBP を Off にしている(先人の知恵)。

## 改善アイデア

HIBP 照合(`uncompromised()`)の付与判定を「**ユニットテスト時のみ除外**」から「**実運用環境でのみ付与**」へ反転させる。

- **本番(production)**: HIBP 有効(不変条件。config/env/fake_externals で無効化できない fail-secure)
- **staging 等の実運用ミラー環境**: 本番同等に有効(実データに近い漏洩照合を維持 = 安全側)
- **非本番(local / testing / bughunt.local)**: 無効(遅延・外部依存・flaky・fake-externals 契約)
- **`config('testing.fake_externals') === true`**: 無効(外部 fake 契約に従う)

判定は `PasswordPolicy` 内の**単一述語** `shouldCheckPwned(): bool` に集約し、`rule()` はこの述語を参照するだけにする(分岐の SSOT 化)。

### 述語ロジック(fail-secure 順序)

```php
public static function shouldCheckPwned(): bool
{
    // 1. 本番は不変条件: 無条件で HIBP 有効。fake_externals/config では無効化できない。
    //    production では ProductionEnvGuard が fake_externals=true を deploy 時に fail-fast で
    //    拒否するため、本番で本判定後に HIBP が外れる経路は存在しない (多層防御)。
    if (App::environment('production')) {
        return true;
    }

    // 2. 非本番で外部 fake を有効化した検証環境 (bughunt/local) は外部 fake 契約に従い無効。
    if (config('testing.fake_externals') === true) {
        return false;
    }

    // 3. staging 等の実運用ミラー環境は本番同等に有効 (安全側)。
    //    local / testing / bughunt.local (fake_externals off) は述語 3 が false となり無効。
    return App::environment('staging');
}
```

- `App::runningUnitTests()`(env=testing)は述語 1・3 のいずれにも該当せず `false` → **既存の runningUnitTests ケースを包含**する。

## 期待効果

- **使命への貢献**: 開発・ブラウザテスト・bug-hunt の実行を高速化・決定化し、現場向け機能(SOP→シナリオ→撮影)の改善サイクルを阻害しない。F-4-01 の根因(遅延)を除去して UX 検証の信頼性を回復する。
- **具体的改善**:
  - 登録/リセット/変更フォーム POST の 10〜14 秒遅延を非本番で解消
  - 開発/feature テストが外部 HIBP の可用性・レイテンシに非依存化(flaky 排除)
  - AGENTS.md bug-hunt「外部を fake する」原則との整合
  - 分岐が単一述語に集約され、環境判定の SSOT 化(将来の環境追加が 1 箇所)

## 実装方針(概要)

- `app/Support/PasswordPolicy.php`
  - `shouldCheckPwned(): bool` を public static で追加(上記 fail-secure 順序)。
  - `rule()` を `$rule = ...->numbers(); return self::shouldCheckPwned() ? $rule->uncompromised() : $rule;` に変更。
  - `App::runningUnitTests()` の直接依存を廃し、環境ベースの明快な述語へ置換。docblock を実態に更新。
- 波及: `Password::defaults()`(`AppServiceProvider`)/ 登録(CreateNewUser)/ リセット(ResetUserPassword)/ 変更(UpdateUserPassword)/ 管理者作成(CreateAdminCommand)はすべて `PasswordPolicy::rule()` 経由のため**本番挙動は不変**(uncompromised 有効のまま)。呼び出し側のコード変更は不要。
- テスト: `tests/Unit/Support/PasswordPolicyTest.php` を環境マトリクスで拡充(下記)。

### config 化の是非(検討して見送り)

brief は「可能なら config 化(`config('security.check_pwned_passwords')`)」を任意提案とする。本設計では**新規 config キーを追加しない**単一述語方式を採る。根拠:

- **不変条件の保護**: 本番で HIBP を env/config で無効化できてはならない(セキュリティ不変条件)。config knob を設けても本番では必ず override する必要があり、「本番だけ効かない knob」は誤用面を増やすだけで実益がない。
- **今必要なものだけ作る**(思考原則2 / 禁止事項6): 環境ごとの ON/OFF トグルという要件は存在しない。将来必要になった時点で述語 3 の `staging` 判定を config 参照へ差し替えれば足りる(拡張点は述語 1 箇所に閉じている)。

## 制約・前提

- **セキュリティ不変条件(必須)**: production では必ず `uncompromised()` が有効。述語 1 を最初に評価する fail-secure 順序により、fake_externals=true が混入しても本番では HIBP が外れない。加えて `ProductionEnvGuard` が本番で `fake_externals=true` を deploy 時に fail-fast で拒否する(既存不変条件)。両者をテストで固定する。
- 既存 Architecture/Feature テスト(パスワードバリデーション)非退行。
- PHPStan level 10 適合(`bool` 戻り値・null 安全)。
- 既存テスト `tests/Unit/Support/PasswordPolicyTest.php` の「非テスト環境で uncompromised を含む」ケースは `app()->instance('env', 'production')` で production を模擬しており、新設計でも成立(production→true)。既存テストは削除・上書きせず拡充する。

## テスト計画(概要)

`tests/Unit/Support/PasswordPolicyTest.php` に以下を追加/拡充(実 HIBP 照会は起こさず、`shouldCheckPwned()` 述語の直接検証 + `rule()` の reflection 検査で固定):

1. `shouldCheckPwned()` matrix:
   - production → true(uncompromised 付与)
   - staging → true
   - local / testing(runningUnitTests) / bughunt.local → false(非付与)
   - fake_externals=true(非本番)→ false(非付与)
2. **本番不変条件**: production では `fake_externals=true` を config に注入しても `shouldCheckPwned()` は true(本判定単体の fail-secure)。あわせて `ProductionEnvGuard` が本番で `fake_externals=true` を violation にする既存不変条件を参照(HIBP を外せる経路が無いことを二重固定)。
3. `rule()` が述語 true 時に `uncompromised` プロパティ true、false 時に false(reflection、外部照会なし)。
4. 既存 `describe()` / `rules()` / `Password::defaults()` 配線テストは非退行。

## スコープ外

- HIBP のタイムアウト/リトライ/キャッシュ等の外部呼び出し堅牢化(本番挙動は不変のまま。別施策)。
- パスワード強度ルール本体(min12 + mixedCase + numbers)の変更。
- staging を config 駆動トグル化する将来拡張(必要時に述語 3 を差し替え)。
- 他の外部サービス fake 化方針(fake_externals / fake_llm / fake_storage)の変更。

