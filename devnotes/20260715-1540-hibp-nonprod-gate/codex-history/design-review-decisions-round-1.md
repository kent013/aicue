# 対応マトリクス: design-review Round 1

## [Critical] 施策1: `shouldCheckPwned()` に `config('testing.fake_externals')` を直接入れるのは責務過拡張
- 判断: 対応する(設計変更 = fake_externals 分岐を削除)
- 根拠: Codex の指摘は正当で、さらに**fail-open な副作用**が潜んでいた。fake_externals の allowlist(`FakeExternalsServiceProvider::PAYMENT_FAKE_ENVIRONMENTS`)は HIBP denylist と**同一の `['local','testing','bughunt.local']`**。つまり fake_externals が実際に有効化され得る env はすべて既に denylist に含まれ、述語 3 で false になる → fake_externals 分岐は当該 env で完全に冗長。唯一分岐が効くのは「denylist 非該当 env(staging 等)で fake_externals=true」の場合だが、その env では FakeExternalsServiceProvider の allowlist 外のため fake は install されない(warning ログのみ)。にもかかわらず旧設計は staging で HIBP を静かに OFF にしてしまう = **fail-secure に反する fail-open**。
- 対応内容: **fake_externals 分岐を PasswordPolicy から削除**。判定を APP_ENV(環境 denylist)のみに限定し責務を「パスワード強度」に閉じる。brief 要件「fake_externals=true では付与しない」は、fake_externals が有効化され得る全 env(local/testing/bughunt.local)が denylist に含まれるため**推移的に満たされる**(テストで固定)。production 安全性は述語 1(production 先行 return true)+ 既存 ProductionEnvGuard で不変。

## [Warning] 施策1: 定数名 `PWNED_CHECK_DISABLED_ENVIRONMENTS` が host と誤認されやすい / docblock 長い
- 判断: 対応する
- 根拠: 妥当。
- 対応内容: 定数名を `PWNED_CHECK_DISABLED_APP_ENVS` へ改名(APP_ENV 値であることを名前で明示)。docblock 冒頭に「APP_ENV 値の denylist」一文に短縮し、長文 rationale は概念設計へ移譲。

## [Suggestion] 施策1: `rule()` の説明コメントが長くドリフトしやすい
- 判断: 対応する
- 対応内容: `rule()` docblock を 2-3 行に簡潔化。判定の rationale は `shouldCheckPwned()` に集約。

## [Warning] 施策2: `app()->instance('env', ...)` の復元手書きは漏れやすい
- 判断: 対応する
- 対応内容: テストファイル内に `withAppEnv(string $env, Closure $assertion): void` ヘルパーを置き、`finally` で必ず `'testing'` へ復元。全 env matrix テストをこのヘルパー経由に統一。

## [Warning] 施策2: reflection テストは Laravel 実装変更に脆い
- 判断: 対応する
- 対応内容: reflection は「配線確認の最小 1 本」に限定する旨を明文化。主判定は `shouldCheckPwned()` public API テスト。

## [Suggestion] 施策2: fail-secure テスト名に `fail-secure` を含める
- 判断: 対応する
- 対応内容: `production では fake_externals=true でも true` → テスト名に `fail-secure` を含める(かつ fake_externals 分岐削除後は「production は denylist に無関係に常に true」の趣旨に更新)。

## [Critical] 施策3: `Http::assertNothingSent()` は HIBP 以外の HTTP まで 0 件要求 → 偽陽性化
- 判断: 対応する
- 根拠: 妥当。設計意図は HIBP 呼び出し抑止に限定。
- 対応内容: 主アサーションを `Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'pwnedpasswords.com'))` に変更。`Http::preventStrayRequests()` は補助として残す(HIBP に限らず stray を検出する追加安全網。ただし主張の核は pwnedpasswords.com 不送出)。

## [Warning] 施策3: 登録成功条件を見ないと別要因の早期失敗でも通る
- 判断: 対応する
- 対応内容: `assertSessionHasNoErrors()` + 期待リダイレクト(登録成功導線)を追加し、シナリオ成立を固定。

## [Suggestion] 施策3: テスト名に `pwnedpasswords.com` を含める
- 判断: 対応する
- 対応内容: テスト名を「登録 POST は非本番で pwnedpasswords.com を呼ばない (F-4-01 非退行)」に更新。

## セキュリティ観点(核)
- 判断: 反映済み。責務分離(production 常時 ON / production の fake_externals は Guard 拒否 / PasswordPolicy は APP_ENV のみ)を fake_externals 分岐削除で強化。
