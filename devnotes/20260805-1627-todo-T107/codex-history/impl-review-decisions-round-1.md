# 対応マトリクス: impl-review Round 1

## [Critical] RecentAuthStatusContractTest が SocialAccount を手組みしている (Factory 規約違反)

- 判断: **対応する**
- 根拠: 詳細設計「テストデータは必ず Factory で生成 (`Model::create()` 手組み禁止)」に反する。
  調査したところ **`SocialAccountFactory` はそもそも存在しなかった** (設計書は「既存の social account factory」を
  前提にしていたが、これが誤り)。AGENTS.md 実装規約「新規モデル追加時は Factory の追加と
  `docs/factories.md` への追記が必須」の観点でも、既存モデルの Factory 欠落は埋めるべき穴である。
- 対応内容:
  - `database/factories/SocialAccountFactory.php` を新設
    (既定 provider は `google` = `config('template.social_providers')` に capability 宣言があり
     recent-auth の step-up satisfier として数えられる provider。`provider(string)` state を用意)
  - `app/Models/SocialAccount.php` に `HasFactory` を追加 (PHPDoc `@use HasFactory<SocialAccountFactory>` 付き)
  - `docs/factories.md` の Factory 一覧に `SocialAccountFactory` の行を追記
  - contract テストを `SocialAccount::factory()->for($user)->create(['provider' => 'google'])` へ書き換え
  - 既存の `LoginMethodRetentionTest` / `RecentAuthTest` の手組みヘルパは本 TODO のスコープ外
    (既存テストの書き換えは差分を無闇に広げるため見送り。新規分のみ規約準拠にした)

## [Warning] PasskeySection: createPasskeyCredential() が throw すると registering が true のまま残る

- 判断: **対応する**
- 根拠: 施策 11 の目的は「登録ボタンが loading のまま固まらない (詰まない)」ことそのものであり、
  outcome 経路だけ守って throw 経路を落とすのは不変条件の穴。指摘のとおり。
- 対応内容:
  - `startCeremonyAndPost()` の `await createPasskeyCredential()` を `try/catch` で包み、
    catch 時に `operationError` (Alert) を出して `registering = false` に戻す
  - 回帰テストを追加:
    `tests/js/pages/SettingsSecurityPasskey.test.ts`
    「ceremony が throw しても Alert を出して loading が固まらない」
    (Alert の描画・`aria-busy` 解除・POST しないことを固定)

## 再検証

- `composer phpstan`: OK (783 files)
- `pnpm typecheck` / `pnpm lint`: OK
- `pnpm test tests/js/pages/SettingsSecurityPasskey.test.ts`: 28 passed
- 全レーン再実行は Round 2 送付前に実施
