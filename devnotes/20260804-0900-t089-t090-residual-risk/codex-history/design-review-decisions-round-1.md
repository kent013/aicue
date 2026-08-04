# 対応マトリクス: design-review Round 1

Codex 判定: CHANGES_REQUESTED（施策 1 / 4 が REQUEST_CHANGES、他 6 施策は APPROVE）
[Critical] 1 / [Warning] 5 / [Suggestion] 5

## [Critical] 施策 4: `exceededLabels` の promoted property が `array` のままだと L10 で shape が崩れる

- 判断: **対応する**
- 根拠: 妥当。`@phpstan-type QuotaStatusShape` は `list<string>` を宣言しているのに、
  promoted property の宣言型は `array` なので、PHPStan は `array<int|string, mixed>` に
  広げて `toArray()` の戻り値が shape と一致しない可能性がある。
- 対応内容: promoted property に `/** @var list<string> */` を付け、
  コンストラクタ引数の `@param list<string>` と揃える。
  `build()` の `$exceeded` は `array_values()` で正規化してから渡す（append のみだが、
  将来 `unset`/フィルタが入っても list 契約が崩れないようにする）。

## [Warning] 施策 1-a: 既存 render callback 群の非回帰テストが不足

- 判断: **一部反論する / 一部対応する**
- 根拠 (反論部分): 追加する callback は
  (1) 第 1 引数の型が `AuthenticationException` なので `firstClosureParameterTypes` により
      **他の例外では一切呼ばれない**、
  (2) 常に `null` を返すので `renderViaCallbacks` は必ず次の callback へ進む
  — の 2 点により、`QuotaExceededException` / `InsufficientTicketsException` /
  catch-all / `respond()` の挙動を**構造的に変えられない**。
  それらの 3 面 (web / expectsJson / api/*) 回帰テストを新設するのは、
  変更していない経路に対するテストの水増しであり思考原則 2 に反する
  (既存テストが既にそれぞれの経路を固定している)。
- 対応内容 (対応部分): ただし**実在する 1 つの干渉点**は固定する価値がある。
  `api/*` の `AuthenticationException` は catch-all の `ApiExceptionRenderer` が JSON 化するが、
  `Accept: application/json` を伴わない `api/*` リクエストでは `expectsJson()` が偽になりうる。
  そのため guard の第 1 条件 `$request->is('api/*')` が効いていることを
  **T4b** として追加する（`api/*` の 401 でフラグが積まれないこと）。

## [Warning] 施策 1-b: T5 は Filament 実装差分に依存して不安定

- 判断: **対応する（分離する）**
- 根拠: 妥当。Filament の `Authenticate` は override で `unauthenticated()` を呼ぶが、
  将来 `abort(403)` などに変わると本テストが落ちる。契約の本体を 3rd party に依存させない。
- 対応内容: T5 を 2 本に分ける。
  - **T5（契約・素の `auth` route）**: 認証失敗で積まれたフラグは
    次の Inertia 応答で **1 度だけ**消費される（one-shot）。Filament に依存しない。
  - **T6（補助スモーク・`/admin`）**: 非 Inertia 面の認証失敗でもフラグが積まれる
    （docblock が主張する「安全側の偽陽性」の裏付け）。
    **テスト名とコメントに「Filament の挙動が変わったら docblock と一緒に更新する」**と書き、
    契約テストではなく文書の裏付けであることを明示する。

## [Warning] 施策 4-a: 超過判定を `>` にすると「上限ちょうどで新規作成不可」が警告に出ない

- 判断: **反論する**
- 根拠:
  1. `QuotaService::check()` は `current >= limit` で拒否するため、`>=` を警告条件にすると
     **starter / personal（`max_projects = 1`）の全組織が、プロジェクトを 1 つ作った時点から
     恒常的に警告を表示する**ことになる。それはプランの設計どおりの正常状態であり、
     警告の意味が失われる（本当の超過が埋もれる）。
  2. 「失敗前の気づき」は**警告ではなく使用量表示**が担う。本設計では quota カードを
     `1 / 1` 形式に変えるため、上限到達は常に読み取れる（現状は上限のみで読み取れない）。
  3. 失敗した瞬間の気づきは施策 4-3（`QuotaExceededException` の文言に回復先を追加）が担う。
  4. `atLimit` を第 3 の表示状態として増やすのは UI 状態の水増し（思考原則 2）。
- 対応内容: `>` を維持する。ただし**この判断を DTO の docblock に理由付きで固定**し
  （既に記載済み）、テストで「上限ちょうどでは `exceededLabels` が空」を
  **回帰防止として明示的に固定**する（既に T 計画に含む）。

## [Warning] 施策 4-b: DTO rename の波及漏れリスク

- 判断: **対応する**
- 根拠: 妥当。rename は typecheck / phpstan で大半は捕まるが、
  Inertia props は連想配列で渡るため**キー名の取りこぼしは静的解析で捕まらない**。
- 対応内容: `tests/Feature/Billing/BillingQuotaStatusTest.php` に
  「`/billing` の `page.quotas` が **6 キー厳密一致**であること」を assert するテストを追加する
  （`maxProjects` / `maxMembers` / `maxStorageGb` / `projectsUsed` / `storageUsedBytes` /
  `exceededLabels`）。TS 側は `pnpm typecheck` が `QuotaStatusShape` との対を保証する。

## [Warning] 施策 8: `tests/Architecture` に DB seed 依存テストを増やすと不安定

- 判断: **対応する（配置を変える）**
- 根拠: 妥当かつ、リポジトリの規約に反していた。`tests/Pest.php` のコメントは
  「**Architecture はファイル走査中心のため DB を使わない (TestCase のみ)**」と明記しており、
  `RefreshDatabase` は Feature / Unit にしか適用されない。
  Architecture に `Plan::query()` を置くと DB 前提が揃わない。
- 対応内容: テストを **`tests/Feature/Billing/PlanQuotaCoverageTest.php`（新規）** へ移す。
  `tests/Architecture/QuotaKeyConfigInvariantTest.php` は現状のまま（config 走査のみ）に保つ。

## [Suggestion] 群

- 施策 2「docs に実装参照点を併記」: **対応する**（`bootstrap/app.php` /
  `LogoutResponse` / `bfcache-guard.ts` の 3 点を経路 C の表に明記）。
- 施策 3「再検討条件に追跡先を紐づける」: **対応する**（TODO ID は本フェーズの責務外なので、
  代わりに本 devnotes ディレクトリを参照先として書く）。
- 施策 5「再開放前提の検証責務を 1 行」: **対応する**
  （`billing:ensure-portal-configuration --verify` が現状 `subscription_update` しか
  検証していないこと = 再開放時は verify の拡張も必須、と書く）。
- 施策 6「enum 側に意図コメントを寄せる」: **見送る**。`PlanCode::requiresStripeCheckout()` には
  既に「Personal は free、Enterprise は問い合わせ営業」という意図コメントがある。
  テスト側の期待値コメントと二重管理になる。
