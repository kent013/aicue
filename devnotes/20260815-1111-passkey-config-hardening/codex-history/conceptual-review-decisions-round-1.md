# 対応マトリクス: conceptual-review Round 1

## [Critical] 「導出鍵が APP_KEY と同一なら起動を止める」と「既存本番では現行 APP_KEY を入れれば維持できる」が矛盾する
- 判断: 対応する（判定式そのものを差し替える）
- 根拠: 指摘のとおり、値の一致で「未宣言」を判定するのは**代理検査**であり、
  「現行 `APP_KEY` と同じ値を意図して宣言した」正当な移行を弾いてしまう。
  守りたい不変条件は「導出鍵が `APP_KEY` から**独立して宣言されている**こと」であって
  「値が違うこと」ではない。移行用の期限付き flag を足すのは機構の追加 (オーバーエンジニアリング) で、
  判定式を正しくすれば flag は要らなくなる。
- 対応内容: `config/passkeys.php` に `user_handle_secret_declared`
  (= `PASSKEYS_USER_HANDLE_SECRET` が非空で宣言されたか) を持たせ、validator はこの真偽値を見る。
  既存の `trusted_hosts.raw_wildcard_suffixes` / `trustedproxy.raw_proxies` と同じ
  「config 段の事実を起動時検査へ expose する」作法に一致する。
  これにより「現行 `APP_KEY` の値をそのまま宣言する」移行が**そのまま通り**、
  以後の `APP_KEY` ローテートでパスキーが失効しなくなる。

## [Critical] 本番停止条件が増える = 破壊的運用変更。AGENTS.md の運用要件に書け
- 判断: 対応する
- 根拠: `TRUSTED_PROXIES` (T108) が同じ性質で AGENTS.md に運用要件として書かれている。同じ場所に同じ形で書くのが作法。
- 対応内容: AGENTS.md の運用要件へ 1 段落追加 (初回デプロイ前に設定が要ること /
  既存パスキーがある場合は現行 `APP_KEY` の値を宣言すれば維持できること) を実装方針に明記した。

## [Warning] mergeConfigFrom で vendor 既定キーが消えるリスク
- 判断: 対応する（検査を足す）
- 根拠: 実測では `mergeConfigFrom` は上位キー単位の `array_merge` で、アプリ側 3 キー以外の
  vendor 既定は残る。ただし「残ること」に依存する設計なので、依存の事実は検査で固定すべき。
  vendor config を全キー複写する案は、キーが増えたときに追従漏れで古い既定が固まるため採らない。
- 対応内容: `PasskeyPackageContractTest` に「vendor 既定キー (timeout / guard / middleware /
  management_middleware / redirect) が残る」検査を追加する。

## [Warning] feature flag の名前が不明
- 判断: 対応する
- 根拠: 曖昧な記述は実装者が別の判定を書く。
- 対応内容: `Features::enabled(Features::passkeys())`
  (`config/fortify.php` の `Features::passkeys([...])` が唯一の有効化点) と明記した。

## [Warning] 許可する接続元の CSV の許容形式が曖昧
- 判断: 対応する
- 根拠: 形式が曖昧だと運用者ごとに違う値が入り、検査が形骸化する。
- 対応内容: 許容形式を「trim のみ許可 / scheme は小文字 `https` (本番) / path・query・fragment・
  userinfo・末尾スラッシュは違反 / 空要素は config 段で落とす」と固定した。
  空要素を落とすことだけ raw 値の expose を持たない理由も明記した
  (空要素の脱落は**誤った値を隠せない**。隠せるのは「全部空」だけで、それは空検査が捕まえる)。

## [Warning] validator の入力型を厳密に
- 判断: 対応する
- 対応内容: validator の引数を `string` / `list<string>` / `bool` に固定し、
  `mixed` からの絞り込みは `ProductionEnvGuard` 側 (既存 `stringList()` を再利用) に置くと明記した。

## [Warning] 使命の主張が広い / 期待効果で「env 明示必須と同等」と読めないようにする
- 判断: 対応する
- 対応内容: 期待効果を「認証手段の可用性・継続性」と「`APP_URL` の危険な値を起動時に検出する」に絞った。

## [Warning] 依存更新後の検証コマンド
- 判断: 対応する
- 対応内容: 実装方針に検証コマンド (`composer test` / `composer phpstan` / `vendor/bin/pint --test`) を明記した。

## [Warning] 版 pin は composer.lock / composer.json の両方
- 判断: 対応する（設計の当初案どおり）
- 対応内容: 変更なし。両方を見る根拠を設計本文へ明示した。
