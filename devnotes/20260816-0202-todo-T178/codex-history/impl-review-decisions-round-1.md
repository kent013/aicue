# 対応マトリクス: impl-review Round 1

## [Critical] bootstrap/app.php — `encryptCookies(except:)` を二重に呼んで既存の `ses/*` 除外を上書きしている

- 判断: **反論する**
- 根拠: 事実誤認である。`bootstrap/app.php` に `encryptCookies` の呼び出しは
  **1 箇所しかない** (`grep -n 'encryptCookies' bootstrap/app.php` → 340 行目のみ)。
  `ses/*` が登録されているのは**その直前の `validateCsrfTokens(except:)`** (331 行目) であって
  cookie 暗号化の除外一覧ではない。差分では両者が近接して現れるため取り違えたものと考える。
  `ses/*` を cookie 暗号化の除外へ移すと SES webhook の CSRF 免除が消え、
  cookie 名として意味を成さない値が除外一覧に入る。
- 対応内容: 変更しない。Round 2 で該当行を引用して提示する。
  実測の裏づけとして、平文の世代 cookie を突き合わせる
  `tests/Feature/Auth/SessionEpochCookieTest.php` が緑であること (= 除外が効いている) と、
  SES webhook の Feature テストを含む `composer test` 全体が緑であることを添える。

## [Warning] guest が正しい印を送ると `sessionEpochMatches: true` になるのは設計とずれている

- 判断: **反論する** (設計どおりであり、名前の意味も保つ)
- 根拠: 詳細設計 S3 の controller コードは
  `authenticated: $request->user() !== null` と
  `sessionEpochMatches: SessionEpoch::matches(...)` を**独立に**組み立てており、
  実装はその通りである。設計のテスト計画にある「guest:
  `{authenticated: false, sessionEpochMatches: false}`」は**印を運ばない** guest 要求の
  期待値で、既存テストはその形のまま緑である (ヘッダを付けていない)。
  `sessionEpochMatches` は「**要求が運んだ印がこのセッションの世代と一致するか**」という
  1 つの事実を表す名前であり、ここに認証状態を畳み込むと名前が事実と食い違う
  (2 つの事実を 1 つの真偽値に混ぜることになる)。開示の判定は画面側が
  `authenticated` を先に見て未認証を `/login` へ倒すため、
  `true` になっても開示には一切到達しない (`probeSessionStatus` の分岐順で固定済み)。
  印は APP_KEY 由来なので、guest が正しい印を送れること自体が新しい漏れにもならない。
- 対応内容: 実装は変えない。意図が読み手に伝わるよう、
  「guest が正しい印を運んでも `authenticated` は false のまま」というテストを
  既に置いてある (`tests/Feature/Auth/SessionStatusProbeTest.php`)。

## [Warning] `SessionEpochSharedPropTest` の再生成テストが prop と cookie の同値を見ていない

- 判断: **対応する**
- 根拠: 妥当な指摘である。詳細設計 S2 のテスト計画は「セッション ID が要求中に
  再生成される経路でも prop と cookie が同値」= **遅延評価が効いていることの behavioral な固定**
  を求めているのに、cookie 側しか見ていなかった。即値へ戻しても赤にならない弱いテストだった。
- 対応内容: テストの中だけで「セッションを再生成してから Inertia 応答を返す」route を登録し、
  prop と cookie を直接突き合わせる形へ書き換えた。
  cookie だけを見る旧アサーションは「ログイン応答の世代 cookie は再生成後のセッション ID 由来になる」
  という別テストとして残した (本番経路の確認)。
  **負のコントロールを実測**: `Inertia::always(fn () => …)` を即値へ戻すと当該テストが赤になることを
  確認した (記録は `contract-sync-negative-control.md`)。
  詳細設計へ補正 7 として追記した。

## [Suggestion] `bfcacheContractHasToken()` はハイフン連結の改名を検出できない

- 判断: **対応する**
- 根拠: そのとおりで、`X-Session-Epoch` → `X-Session-Epoch-Renamed` は `-` が識別子文字でないため
  境界照合を素通りする。cookie 名とヘッダ名は画面側で**文字列としてしか書けない**ので、
  引用符ごとの完全一致に寄せれば負のコントロールが強くなる。
- 対応内容: `bfcacheContractHasQuotedLiteral()` を足し、cookie 名とヘッダ名の 2 行を
  二重引用符ごとの完全一致に切り替えた。属性アクセスや型宣言として現れる
  共有 prop のキー・応答キー・状態値・配線の関数名は引用符が付かないため境界照合のままにした。
  改名 2 通りがどちらも赤になることを実測し、記録と設計の補正 3・4 を更新した。
