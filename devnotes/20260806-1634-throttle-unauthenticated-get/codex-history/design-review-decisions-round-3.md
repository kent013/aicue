# 対応マトリクス: design-review Round 3 (詳細設計レビューの最終ラウンド)

Round 3 の判定は CHANGES_REQUESTED (残 Critical 1 件)。
本セッションのレビュー予算 (最大 3 ラウンド) に従い、**指摘を設計へ反映して確定**する
(4 ラウンド目は実施しない)。反映内容は下記のとおりで、指摘の実質は解消している。

## [Critical] 施策 9-6 のソース走査では境界を deny-by-default で保証できない

- 判断: **対応する** (指摘どおり behavioral proof へ差し替え。ソース走査は補助に降格)
- 根拠: 指摘が正しい。`stateless(` の完全一致走査では
  表記ゆれ / helper 経由 / provider 生成側での stateless 化を検出できない。
  また Round 2 で私が挙げた「intent 検証で空振りする」という反論は、
  **セッション B 側に正しい intent を持たせれば回避できる** (controller が短絡せず
  `Socialite::driver()->user()` まで進むので、止まるのは state 照合だけになる)。
  Codex の指摘のとおりで、私の反論は誤りだった。
- 対応内容: 9-6 を 2 セッションの behavioral proof に差し替え、9-6b としてソース走査を残した。
  - セッション A で `social.redirect` → `Location` から state を控える
  - `flushSession()` して セッション B を作り、B 自身の redirect で intent と state を持たせる
  - B のセッションで **A の state** を付けて callback
  - **外向き HTTP が 0 件**であること (核心) + 成功経路 (dashboard / 認証成立) へ進まないこと
- **実装可能性の裏取り** (設計に明記): Socialite のファサードごと mock すると
  state 照合の実装まで消えるため使えない。代わりに
  `SocialiteManager` が Laravel `Manager` の driver キャッシュを持つことを利用し、
  テストから `Socialite::driver('google')->setHttpClient($mockGuzzle)` しておけば
  controller 内で解決される driver は同一インスタンスになり、mock client が使われる。
  Guzzle `MockHandler` + `Middleware::history()` で外向き要求件数を数える。
  必要な import と helper (`stateFromRedirect()`)、
  既存 `SocialAuthTest` との違い (ファサードを mock しない) も設計に記載した。
- 併せて段階分けの本数を「前提テスト 8 本 (9-1〜9-7 + 9-6b)」に更新した。

## Round 3 で APPROVE と明示された施策

施策 1〜8・10 (= 9 以外のすべて)。
