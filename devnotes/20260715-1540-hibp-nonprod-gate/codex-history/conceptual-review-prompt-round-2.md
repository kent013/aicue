# 概念設計レビュー Round 2

Round 1 の指摘への対応を反映しました。対応マトリクスと更新後の概念設計を示します。

## 対応サマリー

### [Critical] 準本番(staging 等)の扱いが本文と述語で不一致 / 未知 env で HIBP が静かに外れる → 対応
- **allowlist(ON にする env 列挙)から denylist(OFF にする既知の開発/テスト env 列挙)へ反転**しました。
- HIBP は既定 ON(fail-secure)。`PWNED_CHECK_DISABLED_ENVIRONMENTS = ['local','testing','bughunt.local']` と `fake_externals=true` のときのみ OFF。
- 未知 env(staging/preprod/qa/review 等の実運用・準本番ミラー)は既定 ON に倒れる。
- これは FakeExternalsServiceProvider の「fake は allowlist で倒す=未知 env では fake しない(安全側)」と**対称**の判断(fake の安全側=しない、HIBP の安全側=する)。本文の曖昧語「等」を廃し OFF リストを明示。

### [Warning] 期待効果の受け入れ基準 → 対応
- テスト計画に「非本番では uncompromised 非付与=HIBP HTTP 経路が構造的に発生しない」を成功条件として追加。Feature で `Http::preventStrayRequests()`+`Http::assertNothingSent()` により POST が外部 HIBP を呼ばないことを固定(POST 実時間閾値は flaky のため不採用、外部呼び出し 0 回を基準に)。

### [Warning] rule() の reflection 検査が脆い → 対応
- **主テスト面を public 述語 `shouldCheckPwned()` の振る舞い検査に据える**。rule() の reflection は配線確認の補助に降格。

### [Warning] env 分類を確定してから着手 → 対応
- denylist 定数 `PWNED_CHECK_DISABLED_ENVIRONMENTS` を SSOT として確定。

### [Suggestion] bughunt.local が host に見える → 対応
- `bughunt.local` は APP_ENV 値(host ではない)、判定は APP_ENV のみ依存と注記。

### [Suggestion] 効果は非本番の速度改善に限定 → 対応
- 期待効果を「非本番限定の速度・決定性改善(本番 UX 不変)」に明確化。

## 確認したい点

1. denylist への反転で Critical(未知 env の静かな無効化)が解消されているか。
2. 述語 2(fake_externals 分岐)は denylist と通常一致し冗長だが、brief 必須要件「fake_externals=true では付与しない」を述語レベルで first-class に固定する意図で残置している。この判断は妥当か(削除すべきか)。
3. その他 CHANGES_REQUESTED を残す論点があるか。

---

## 更新後の概念設計(全文)

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

HIBP 照合(`uncompromised()`)の付与判定を「**ユニットテスト時のみ除外**」から「**既知の開発/テスト環境でのみ除外(それ以外は既定 ON)**」へ反転させる。判定は **fail-secure(安全側=照合 ON が既定)** な denylist で行う:

- **本番(production)**: HIBP 有効(不変条件。config/env/fake_externals で無効化できない fail-secure)
- **既知の開発/テスト環境(`local` / `testing` / `bughunt.local`)**: 無効(遅延・外部依存・flaky・fake-externals 契約)
- **`config('testing.fake_externals') === true`**: 無効(外部 fake 契約に従う)
- **上記いずれにも該当しない env(`staging` / `preprod` / `qa` / `review` 等の実運用・準本番ミラー)**: **既定で有効**(安全側)

> **allowlist ではなく denylist を採る理由(fail-secure)**: 「HIBP を ON にする env を列挙(allowlist)」すると、新設の準本番 env(別名ミラー)で HIBP が**静かに OFF** になり、検証代表性とセキュリティ安全側の双方を損なう。逆に「OFF にする開発/テスト env を列挙(denylist)」すれば、未知 env は既定 ON となり安全側に倒れる。これは `FakeExternalsServiceProvider` が「fake は allowlist で倒す=未知 env では fake しない(安全側)」とするのと**対称**の判断(fake の安全側は「しない」、HIBP 照合の安全側は「する」)。
>
> **env 名の明確化**: `bughunt.local` は `APP_ENV` の値(host 名ではない)。判定は `App::environment()`(=`APP_ENV`)のみに依存し、`APP_URL`/host には依存しない。

判定は `PasswordPolicy` 内の**単一述語** `shouldCheckPwned(): bool` に集約し、`rule()` はこの述語を参照するだけにする(分岐の SSOT 化)。無効化する env の列挙は定数 `PWNED_CHECK_DISABLED_ENVIRONMENTS` を SSOT とする。

### 述語ロジック(fail-secure 順序)

```php
/** HIBP 照合を無効化する既知の開発/テスト環境 (fail-secure: 未知 env は既定 ON)。 */
private const array PWNED_CHECK_DISABLED_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

public static function shouldCheckPwned(): bool
{
    // 1. 本番は不変条件: 無条件で HIBP 有効。denylist にも fake_externals にも左右されない。
    //    production では ProductionEnvGuard が fake_externals=true を deploy 時に fail-fast で
    //    拒否するため、本番で本判定後に HIBP が外れる経路は存在しない (多層防御)。
    if (App::environment('production')) {
        return true;
    }

    // 2. 外部 fake を有効化した検証環境 (bughunt/local) は外部 fake 契約に従い無効。
    //    fake_externals の allowlist (local/testing/bughunt.local) は denylist と一致するため
    //    通常は冗長だが、brief 必須要件「fake_externals=true では付与しない」を述語レベルで
    //    first-class に固定し、fake allowlist が将来変わっても契約を保つ (defense in depth)。
    if (config('testing.fake_externals') === true) {
        return false;
    }

    // 3. 既知の開発/テスト env のみ無効。未知 env (staging/preprod/qa/review 等の実運用・準本番
    //    ミラー) は既定 ON = fail-secure。
    return ! App::environment(self::PWNED_CHECK_DISABLED_ENVIRONMENTS);
}
```

- `App::runningUnitTests()`(env=`testing`)は述語 3 の denylist に含まれ `false` → **既存の runningUnitTests ケースを包含**する。

## 期待効果

- **使命への貢献**: 開発・ブラウザテスト・bug-hunt の実行を高速化・決定化し、現場向け機能(SOP→シナリオ→撮影)の改善サイクルを阻害しない。F-4-01 の根因(遅延)を除去して UX 検証の信頼性を回復する。**効果は非本番限定の速度・決定性改善であり、本番 UX は不変**(uncompromised 有効のまま)。
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
- **今必要なものだけ作る**(思考原則2 / 禁止事項6): 環境ごとの ON/OFF トグルという要件は存在しない。将来必要になった時点で `PWNED_CHECK_DISABLED_ENVIRONMENTS` 定数を config 参照へ差し替えれば足りる(拡張点は述語 1 箇所に閉じている)。

## 制約・前提

- **セキュリティ不変条件(必須)**: production では必ず `uncompromised()` が有効。述語 1 を最初に評価する fail-secure 順序により、fake_externals=true が混入しても本番では HIBP が外れない。加えて `ProductionEnvGuard` が本番で `fake_externals=true` を deploy 時に fail-fast で拒否する(既存不変条件)。両者をテストで固定する。
- 既存 Architecture/Feature テスト(パスワードバリデーション)非退行。
- PHPStan level 10 適合(`bool` 戻り値・null 安全)。
- 既存テスト `tests/Unit/Support/PasswordPolicyTest.php` の「非テスト環境で uncompromised を含む」ケースは `app()->instance('env', 'production')` で production を模擬しており、新設計でも成立(production→true)。既存テストは削除・上書きせず拡充する。

## テスト計画(概要)

**主テスト面は public 述語 `shouldCheckPwned()` の振る舞い検査**に置く(Laravel 内部への結合を避ける)。`rule()` の `uncompromised` reflection 検査は「述語結果が付与に正しく配線されているか」を確認する**補助**に留める。いずれも実 HIBP 照会は起こさない。

`tests/Unit/Support/PasswordPolicyTest.php` に以下を追加/拡充:

1. **`shouldCheckPwned()` env matrix**(主):
   - production → true
   - staging → true(未知 env=既定 ON の代表)
   - preprod / review 等の未知 env → true(fail-secure denylist の代表を 1 つ)
   - local / testing(runningUnitTests) / bughunt.local → false
   - fake_externals=true(非本番)→ false
2. **本番不変条件(fail-secure)**: production では `config('testing.fake_externals')=true` を注入しても `shouldCheckPwned()` は true(述語単体で本番が無効化されない)。あわせて `ProductionEnvGuard` が本番で `fake_externals=true` を violation にする既存不変条件を参照(HIBP を外せる経路が無いことを二重固定。既存 `ProductionEnvGuardTest` が担保)。
3. **配線(補助)**: `rule()` が述語 true 時に `uncompromised` プロパティ true、false 時に false(reflection、外部照会なし)。
4. **HIBP 呼び出し 0 回の構造保証**(効果の受け入れ基準): 非本番(既定 testing)では rule に `uncompromised` が付与されない=HIBP HTTP 経路が構造的に発生しないことを (3) で固定。加えて Feature レベルで登録/リセット/変更 POST が外部 HIBP を呼ばないことを `Http::preventStrayRequests()` + `Http::assertNothingSent()` 相当で固定する(既存パスワードバリデーション Feature テストに non-regression として組み込む。POST 実時間の閾値測定は flaky のため採らず、外部呼び出し 0 回を成功条件とする)。
5. 既存 `describe()` / `rules()` / `Password::defaults()` 配線テストは非退行。

## スコープ外

- HIBP のタイムアウト/リトライ/キャッシュ等の外部呼び出し堅牢化(本番挙動は不変のまま。別施策)。
- パスワード強度ルール本体(min12 + mixedCase + numbers)の変更。
- 環境別 HIBP トグルの config 駆動化という将来拡張(必要時に `PWNED_CHECK_DISABLED_ENVIRONMENTS` を config 参照へ差し替え)。
- 他の外部サービス fake 化方針(fake_externals / fake_llm / fake_storage)の変更。

