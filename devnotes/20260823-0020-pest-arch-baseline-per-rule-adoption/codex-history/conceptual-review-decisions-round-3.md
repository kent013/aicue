# 対応マトリクス: conceptual-review Round 3

## [Critical] 3. 可変メソッド呼び出し (`->{$method}(...)`) で `ignoring` を実行できる

- 判断: **対応する** (「保証外と書くだけ」では AGENTS.md 共通規約 (b) に反するという指摘は正しい)
- 根拠: 共通規約 (b) は
  「保証範囲の外にした構文で保護対象の操作を書ける場合、利用側 gate は
  **検出力の主張をその構文を除く形へ明示的に狭める**か、**未解決として失敗させる**かのどちらかにする」
  と定める。I3 は「リポジトリ全体で 1 箇所」と主張しているので、狭める側を選ぶと主張が空洞化する。
  **未解決として失敗させる側**を採る。
- **母集団の実測** (助言に従い先に測った。`git ls-files -- 'tests/*.php'` の 803 ファイルを
  `token_get_all()` で走査):
  - 動的メンバ名 (`->` / `?->` / `::` の直後が `{` または変数) の出現は **7 件 / 6 ファイル**。
    すべて正当な用途 (Factory の状態名・HTTP メソッド名・名前つきコンストラクタ名) である。

    | ファイル | 件数 | 用途 |
    |---|---|---|
    | `tests/Feature/Billing/BillingAccessStateTest.php` | 1 | Factory の状態名 |
    | `tests/Feature/Billing/BillingCheckoutSessionModelTest.php` | 2 | Factory の状態名 |
    | `tests/Feature/Invitations/AcceptInvitationInAppTest.php` | 1 | Factory の状態名 |
    | `tests/Feature/Invitations/PendingInvitationScopeTest.php` | 1 | Factory の状態名 |
    | `tests/Feature/Organizations/TwoFactorEnforcementTest.php` | 1 | HTTP メソッド名 |
    | `tests/Unit/Exceptions/AnalysisFailedExceptionTest.php` | 1 | 名前つきコンストラクタ名 |

  - `tests/Architecture/` には **0 件**である。
- 対応内容: S4 へ検査を 1 本足す。
  **`tests/` 配下の追跡 PHP 全数で動的メンバ名を列挙し、`ArchBaseline` が持つ目録と
  ファイル別件数まで exact-fit で一致すること** (増えても減っても赤)。
  目録の各行は **30 文字以上の根拠**を持つ (aicue の既存 deny-by-default 目録と同じ強度)。
  - **メソッド呼び出しとプロパティ参照を区別しない**。区別には波括弧の対応付けが要るところを、
    区別せず広く数える (拾いすぎる方向 = 安全。共通規約 (b))。
  - 「母集団を arch 語彙を含むファイルに絞る」案は**採らない**。
    語彙で母集団を絞ると `expect([...])->not->{$m}()` (`$m = 'toBeUsed'`) の形が
    どの語彙にも一致せず母集団から外れ、絞り込み自体が動的ディスパッチで破れるからである。
    測ってみると全数でも 7 件しか無いので、全数を母集団にする費用は小さい。

## [Warning] 3. `expect` の検査範囲が曖昧 (通常の Pest assertion と区別できない)

- 判断: **対応する** (指摘のとおり、識別子単位の pin では区別できない)
- 根拠: `expect(` は全 Feature/Unit テストに大量に現れるし、本 gate の自己検査 5 部自身も使う。
  識別子の件数を pin する形は成立しない。
- 対応内容: **識別子単位の pin をやめ、チェーン単位の完全一致照合に置き換える**。
  - `arch` 識別子の出現は `tests/` 全数で**ちょうど 1 件**、かつ `tests/Architecture/ArchBaselineTest.php` 内。
  - その 1 件から文末 `;` までの**トークン列が期待形と完全一致**する:

    ```
    arch ( ArchBaseline :: descriptionOf ( $ruleId ) )
      -> expect ( ArchBaseline :: symbolsOf ( $ruleId ) )
      -> not -> toBeUsed ( )
      -> ignoring ( ArchBaseline :: exceptionsOf ( $ruleId ) ) ;
    ```

  - `ignoring` / `toBeUsed` の識別子出現は `tests/` 全数でそれぞれ**ちょうど 1 件**
    (上のチェーンの中)、`preset` は **0 件**。
  - `expect` は**全数では数えない**。チェーン内での位置と引数が上の完全一致照合で固定される。
  - 正例・負例: 期待形どおりのチェーンが通ること / `->ignoring([Foo::class])` の直書き形が落ちること /
    チェーンを 2 本目に増やすと落ちること / `->not->toBeUsed()` を落としたチェーンが落ちること。

## [Warning] 5. vendor が接尾辞一致をやめたら I4 の意味上の契約が崩れる

- 判断: **対応する** (Codex の提案 1 = 最小案を採る)
- 根拠: 「Pest の検出集合の部分集合である」ことを保証し続けるには vendor の内部意味論への
  トリップワイヤが要り、スコープが増える。正典が求める逆向き証明は
  「**登録した例外クラスが対象シンボルを実使用しているか**」であって、
  「Pest がそれを検出するか」ではない。構文上の契約で正典の要求は満たせる。
- 対応内容: **I4 の契約を構文上の使用証明へ限定する** —
  「登録クラスのソースに、対象シンボルと**綴りがトークン完全一致する素の関数呼び出し**が
  1 件以上存在する」。vendor の接尾辞一致の話は**契約ではなく背景**として
  走査器の docblock に「なぜ `mysha1()` を数えないか」の理由の形で残す
  (Pest は拾うが、使用証明の偽陽性になるので数えない)。
  I4 の文言から「Pest の検出集合の部分集合」という保証の主張を落とした。

## [Warning] 6 / 7. `ArchSurfaceSite` の配置と型が未確定

- 判断: **対応する** (ファイル数を増やさない側を選ぶ)
- 根拠: 値オブジェクトを 1 本増やすほどの不変条件をコンストラクタに持たせる必要が無い。
  aicue には `ReferenceSite` のような値オブジェクトもあるが、本件の戻り値は
  走査器の内部で組み立ててすぐ照合に使うだけである。
- 対応内容: `ArchSurfaceScanner` の公開メソッドの戻り値を
  **型付き array shape の `list<>`** として明記した:
  - `identifierSites(string $relativePath, string $phpSource, string $identifier): list<array{line: int, index: int}>`
  - `statementTokens(string $phpSource, int $index): list<string>` (指定位置から文末 `;` までの綴り列)
  - `dynamicMemberSites(string $relativePath, string $phpSource): list<array{line: int}>`

  `token_get_all()` の生の戻り値は走査器の外へ出さない。成果物は **6 ファイルのまま**据え置く。
