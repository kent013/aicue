# 対応マトリクス: conceptual-review Round 3 (概念設計フェーズの最終ラウンド)

Round 3 で **Critical は 0 件**。残った Warning 2 件はいずれも受け入れて本文へ反映した。
本タスクのレビュー上限は 3 ラウンドのため、概念設計の合議はここで打ち切る。

## [Warning] キー規約テストが「メールはハッシュ化する」与件を保証していない (観点 2)
- 判断: **対応する**
- 根拠: 正しい。`^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*:` は形式しか見ないため
  `login:email:user@example.com` を通過させてしまう。
  AG-096 の与件「メールをキーにするときは認証側と同じ正規化関数を使い、**ハッシュ化してから使う**」を
  機械で保証できていなかった。
- 対応内容: §4-D に検査 3 を追加。email を扱う 5 limiter
  (`login` / `inquiry` / `password-reset-request` / `password-reset-submit` / `account-register`) は
  scenario ごとに「キーに平文 email も正規化済み email も**含まれない**」かつ
  「`EmailHash::compute($email)` の値を**含む**」を検証する。§8 検証表 #6 も更新。

## [Warning] binder の完全一致条件が `ThrottleRequestsWithRedis` と矛盾する (観点 3)
- 判断: **対応する**
- 根拠: 正しい。方針 A では `is_a(..., ThrottleRequests::class, true)` で Redis 版も throttle と認めるのに、
  binder 側だけ `ThrottleRequests::class.':'.$limiter` の文字列完全一致にしていた。
  Redis 版で焼かれた route cache では同じ limiter でも「別 limiter」と判定され cached 起動が落ちる。
- 対応内容: §4-C の no-op 条件を「entry を `{class}:{params}` に分解し、
  class 部が `is_a($class, ThrottleRequests::class, true)` かつ params 部が期待 limiter 名と完全一致」に修正。

## [Suggestion] 「全分岐」→「inventory で宣言した全 scenario」と表現すべき (観点 5)
- 判断: **対応する (保証範囲の正確化)**
- 対応内容: §4-D と §8 の表現を「inventory で宣言した全 scenario」に統一した。
  未実行分岐の自動発見はしていない (できない) ことを表現上も曖昧にしない。

## 残件 (レビュー上限到達により設計へ明記)
- なし (Round 3 の指摘はすべて反映済み。Critical は Round 2 で解消済み)。
