# 対応マトリクス: conceptual-review Round 1

## [Critical] (観点 4/8) SSO の許可 provider 集合が母集団外 — `social_providers` に provider を足しても目録差分が出ない

- 判断: **対応する**
- 根拠: 正しい指摘。外部到達には *コード到達点* (どのクラスが外へ出るか) と *宛先集合* (どこへ出るか) の 2 次元がある。`SocialAuthController` を exact-fit で固定しても、`config/template.php` の `social_providers` に IdP を足せば新しい宛先が無言で増える。これは「登録し忘れが永久に見えなくなる」形そのもの。
- 対応内容:
  - §2-1 論点 1 に**「検知は種別 × 次元で数える」**を追加。
  - ただし新目録で provider を再宣言はしない。既存 `tests/Architecture/SocialProviderTrustPolicyTest.php` を実読して確認: `socialProvidersConfig()` を回して provider ごとに `capability` / `email_trust` の**明示宣言を必須**にし、`social_providers` が空なら fail する **deny-by-default** になっている。provider 追加は必ずここで止まる。よって **3 本目の委譲先**として機械結線する (object_storage / llm と同じ形)。
  - 3 箇所目の宣言を作らない理由は、本設計の最大論点である二重管理の回避と同一。
  - §3 期待効果に「SSO の宛先集合について、provider 追加が既存 gate で必ず宣言を要求され、その結線が機械で切れないことが保証される」を明記。

## [Critical] (観点 8) 走査根が `app/` のみ = 「外部到達点」の名前が実態より広い

- 判断: **一部対応する / 一部反論する**
- 根拠:
  - 対応する部分: 名前と実態のズレは実在する。定義を先に置くべき (「機能の名前に立ち返れ」)。
  - 反論する部分: `routes/` / `config/` への走査一般化は今回作らない。実測で `routes/` に外部到達コードは 1 行も無く (`Http::` / `Socialite::` / `Cashier::stripe()` いずれも 0 件)、`config/` が持つのは**宛先の値**であってコードではない。宛先次元で今すぐ問題になるのは SSO だけであり、それは委譲で閉じた。他種別の宛先 (Stripe account / SES region / 為替 URL) まで一般化するのは「あったら便利」で AGENTS.md 思考原則 2 に反する。
- 対応内容: §6 に **「目録の実体は『app/ のコード到達点 + 明示的に委譲した宛先集合』である」**という定義を置き、docs と gate 冒頭にも同じ定義を先出しする。§7 に「config 走査の一般化」をスコープ外として明記。

## [Warning] (観点 5) `->stripe()` の同一ファイル抑制が偽陰性を作る

- 判断: **対応する** (指摘より強い形で)
- 根拠: `Organization` を受け取るだけで Cashier / Stripe を import しないクラスが `$organization->stripe()` を呼ぶと抑制されて消える。Codex の提案 (23 site の expected set を固定) は行番号・件数の固定になり整形で壊れる脆い gate になるため採らない。
- 対応内容: **抑制した site 数が app/ 全走査で 0 件であること**を gate の assert にする。抑制が実際に働いた瞬間に赤くなるので、抑制規則が「静かに効いている」状態を構造的に作らせない。これは件数固定より安定し、かつ偽陰性の口を直接塞ぐ。

## [Warning] (観点 1/6/10) 期待効果が保証範囲より強く読める / 未遮断のまま「標準形 v1 達成」は弱い

- 判断: **対応する**
- 根拠: 妥当。「安心して回せる」「登録なしでは CI を通らない」は、SSO のブラウザ外部遷移が残る以上、書きすぎ。
- 対応内容: §3 冒頭に **「本 PR の位置づけは検知 v1 である (遮断ではない)」**を明記し、効果を検知の範囲へ限定。§2-3 と §7 に、bug-hunt の SSO 遮断は独立 TODO (`bughunt-sso-egress`) として起票すると明記。§6 の 1 項目目も「本 PR では変わらない」と断定形に直した。

## [Warning] (観点 2) `testing.fake_externals` の説明と実態のズレ

- 判断: **対応する** (ただし前提を訂正)
- 根拠: 指摘は正しいが、前提が逆。実読で確認したところ、**追跡対象の `.env.bughunt.local.example` は「Stripe 課金 fake の capability flag」としか書いていない** (captcha も SSO も書いていない)。「LLM/Stripe/Captcha/SSO 等」と書いてあるのは git 管理外の `.env.bughunt.local` の方で、こちらは**実態より広い** (SSO fake は存在しない)。概念設計 §4 の記述はこの管理外ファイルを根拠にしていたので訂正した。
- 対応内容: example と `config/testing.php` の docblock を「Stripe 課金 gateway + captcha 検証器を fake 化する。**SSO は fake しない**」へ是正するのを同一 PR のスコープに含めた。

## [Warning] (観点 3) 実現可能性 — `Socialite::driver()` 検出だけでは provider 追加を捕捉できない

- 判断: **対応する** (Critical 1 と同一の対応で解消)

## [Suggestion] (観点 7) inventory の entry を value object にする

- 判断: **対応する**
- 根拠: 安価で、既に repo 内に作法 (`ExternalFakeBinding` / `GatewayObservationEntry`) がある。array shape だと `rationale` の書き忘れが実行時 assert まで落ちない。
- 対応内容: §4 S2 に `readonly` value object (`ExternalSeamEntry` / `ExternalSeamExemptionEntry`) を明記。

## [Suggestion] (観点 9) 別事実で両目録に載るケースを失敗メッセージに明記

- 判断: **対応する**
- 対応内容: §2-1 論点 1 に「gate の失敗メッセージにも明記する」を追加。
