# 対応マトリクス: design-review Round 1

## [Critical] public suffix を見ないため `co.uk` のような身元の識別子を通す
- 判断: **一部対応する（PSL 依存の追加は反論、限界の明示と記録は対応）**
- 根拠:
  1. **脅威モデルが違う**。身元の識別子を設定するのは攻撃者ではなく運用者であり、
     public suffix を置いた場合の結果は「パスキーが 1 件も使えない」であって権限昇格ではない。
     ブラウザ側は PSL を見るため、誤設定は最初の手続きで必ず失敗する（無言で通ることはない）。
  2. `TrustedHostsConfigValidator` が**同じ判断**を明文で下している
     （「public suffix の wildcard を弾くには PSL が必要だが、運用者が意図的に設定する config に対しては
     過剰なため scope 外とする」）。ここだけ別の作法にすると、同じ性質の 2 つの検査が別々の思想で動く。
  3. PSL 実装 (`jeremykendall/php-domain-parser` 等) は**外部データの更新運用**を伴う依存であり、
     supply-chain 運用 (accepted-advisories / audit gate) の対象が 1 つ増える。得るものに対して重い。
  4. `PASSKEYS_RELYING_PARTY_ID` を allowlist に限定する案は、
     allowlist の正本をどこに置くかという新しい設定を作ることになり、問題を 1 段ずらすだけ。
- 対応内容: Codex が示した最低ラインをそのまま採る。
  validator の docblock と `docs/auth-security-mechanisms.md` に
  「PSL を持たないので WebAuthn の完全な妥当性検査ではない」と明記し、
  `co.uk` が通ることを **既知の限界としてテストで固定**した
  （後に PSL を入れたらこのテストが赤くなり、設計変更が可視化される）。

## [Critical] origin 正規表現が IPv6 リテラルを構文で reject するのにメッセージが曖昧
- 判断: 対応する
- 根拠: 「production では DNS 名のみ」という設計意図がメッセージから読み取れないのは、
  運用者が原因を誤解する。方針自体（IP / localhost を拒否）は Codex も妥当と判定している。
- 対応内容: メッセージを
  `Each origin must be "https://dns-name[:port]" ... IPv4/IPv6 literals and bracketed hosts are not accepted`
  に変更し、validator docblock と docs にも「対象は DNS 名のみ」と明記した。

## [Warning] `Features::passkeys()` の戻り値（options 付き配列か文字列か）を取り違えると無効化テストが誤判定になる
- 判断: 対応する
- 根拠: 実測した。`Features::passkeys(array $options = [])` は options が空なら**副作用なく `'passkeys'` を返す**だけで、
  options を渡したときだけ `fortify-options.passkeys` を書き換える。
  `Features::enabled($f)` は `in_array($f, config('fortify.features', []))`。
  つまり無効化は `fortify.features` から `'passkeys'` を外すのが正しく、
  `Features::passkeys([...])` を無効化に使うと `fortify-options` を汚染する。
- 対応内容: テスト計画に「現行 config で enabled であること」と
  「`fortify.features` から passkeys を外すと 0 件になること」の 2 本を明記し、
  無効化の具体手順（`array_diff`）と `Features::passkeys([...])` を使わない理由を書いた。

## [Warning] `stringList()` が非 string を黙って落とし設定破損を黙殺する
- 判断: 対応する
- 根拠: production guard の目的（設定破損をデプロイ前に落とす）からすると、指摘のとおり黙殺は筋が悪い。
  ただし既存の `stringList()` を書き換えると trusted hosts / trusted proxies の挙動まで変わる。
  あちらは「config 段の silent drop を raw 値で表面化する」設計で破損の扱い方が違うため、変えない。
- 対応内容: `ProductionEnvGuard` に `isStringList(mixed): bool`
  （`@phpstan-assert-if-true list<string>`）を足し、passkeys の 2 つの列については
  非 string / 非 list を **violation** にした（有効値と併存していても落ちる）。
  既存 `stringList()` は変更しない。テストも 2 本追加した。

## [Warning] `isDnsName()` が `192.168.001.001` のような IP 風ホストを通す
- 判断: 対応する
- 根拠: 実測どおり `filter_var(FILTER_VALIDATE_IP)` は先頭ゼロを IP と認めないため、
  現行案では DNS 名として通ってしまう。全数字の TLD は存在しないので、
  末尾ラベルに英字を要求すれば依存なしで弾ける（punycode `xn--p1ai` は英字を含むので通る）。
- 対応内容: `isDnsName()` の末尾に「末尾ラベルは英字を 1 文字以上含む」検査を追加し、
  負のコントロール dataset に `192.168.001.001` / `example-.com` / `.example.com` / `2001:db8::1` を足した。

## [Warning] `.env.example` の空欄が production で必ず fail-fast することが伝わりにくい
- 判断: 対応する
- 対応内容: 「この行は空のプレースホルダである。production では必ず値を入れること（空欄のままだと起動しない）」を
  コメントへ明記した。

## [Warning] 制約検査 `str_starts_with('^0.2.')` が `~0.2.1` / `0.2.*` を落とす
- 判断: 対応する（許容表現を設計で明示する）
- 根拠: 指摘のとおり「範囲として同等な表現」を落とす。ただし `composer/semver` を足して範囲判定するのは、
  得るものが表記の自由度だけなので依存に見合わない。
  composer.json の既存 20 件超がすべて caret であり、表記を 1 つに揃える方が
  「pin が緩んだか」を目視 1 行で判定できる。
- 対応内容: 許容表現を **caret のみ（`^0.2` / `^0.2.1`）** と設計に明記し、
  検査を `str_starts_with($constraint, '^0.2')` に緩めた（patch 位置の指定有無を許す）。

## [Warning] 「v0.2.1 に対して検証済み」は patch 更新で表現がズレる
- 判断: 対応する
- 対応内容: テストのコメントを「**0.2 系に対して検証する契約**」に書き換えた。

## [Warning] docs にも PSL 未検査の限界を明記せよ
- 判断: 対応する
- 対応内容: `docs/auth-security-mechanisms.md` の変更後テキストに、
  PSL 未検査・DNS 名のみ対象・完全な妥当性検査ではない旨を追記した。

## [Warning] `APP_URL=not-a-url` のテストを足せ（施策 1）
- 判断: 対応する
- 対応内容: 施策 1 のテスト計画に追加した。
