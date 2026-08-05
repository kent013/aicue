# Round 2: Round 1 指摘への対応

[Critical] 3 件・[Warning] 全件・[Suggestion] 全件を反映しました。実測で裏を取った上での判断です。

**自己修正 1 件**: Round 1 で頂いた alias 無効化ルール
「代入位置から候補位置までに再代入が 1 回でもあれば alias 無効」をそのまま採ると、
`$q = User::query(); if ($x) { $q = $other; } $q->whereKey($id);` が**検出されなくなり
fail-open** になることに気付きました。「再代入すれば gate を黙らせられる」= 最も安易な回避手段に
なるため、**再代入があっても alias を取り消さない** (過剰検出寄り = fail-closed) に倒しました。
この判断が妥当かご確認ください。

再レビューをお願いします。特に次を見てください:
1. 候補 key の構造 fingerprint 設計で、理由の横滑りが本当に防げるか
2. provenance 証明の 2 段構成 (モデル証明 + 保証済み provenance) が token_get_all で実装可能か。
   実装コストが跳ねるなら、どこまで後退させるのが安全側か
3. `predicateKind` 別の副条件分岐に漏れがないか
4. alias の fail 方向 (上記自己修正) の判断
5. 実装コスト全体が過大でないか (思考原則「今必要なものだけ作る」)

---

# 対応マトリクス: design-review Round 1

実測で裏を取ってから判断した。関連する出現数:

| pattern | app/ + routes/ の出現数 |
|---|---|
| `whereKeyNot` | 6 (すべて relation 起点 or 引数が model 由来) |
| `findMany` / `findOrNew` | **0** |
| `::destroy(` | 1 (**docblock 中のみ** = 実コード 0) |
| `whereRaw` / `whereIntegerInRaw` | **0** |
| `DB::table('…')` の対象 | `organizations` / `users` / `organization_user` / `project_members` / `ticket_ledger_entries` (model 対応) + `oauth_*` (Passport 内部) |

## [Critical] 候補 key がメソッド内出現順だけでは横滑りする

- 判断: **対応する**
- 根拠: 完全に正しい。同一メソッドで候補が 1 件増減すると後続 key が全てずれ、
  **既存の裁定理由が別候補へ横滑りしても人間が気付けない**。deny-by-default の意味が消える最悪の形。
- 対応内容: key に**構造 fingerprint** を入れる。
  `{path}#{method}#{rootKind}.{predicate}:{identity}#{ordinal}`
  例 `Http/Controllers/Projects/ProjectMemberController.php#store#User.findOrFail:$userId#1`
  - `rootKind`: `User` / `DB:users`
  - `predicate`: `findOrFail` / `whereKey` / `where:id:=`
  - `identity`: 正規化した引数 (`$userId` / `$dto->user_id` / `$this->renderJobId`。cast は除去)
  - `ordinal` は**衝突解消用の従属要素**であり主識別子にしない
  fingerprint が変われば「別の候補」として stale + 未分類の**両方**が出るので、
  理由の横滑りが構造的に起きない。

## [Critical] `DB::table()` の対象テーブルが無限定

- 判断: **対応する**
- 根拠: `DB::table('oauth_access_tokens')->where('id', …)` まで候補にすると Passport 内部まで
  分類対象になり、gate の主張 (`ModelDirectFetch`) と母集団がずれる。
- 対応内容: **`App\Models\*` に対応するテーブルだけ**を対象にする。
  `DirectFetchInventory::modelTables()` が `app/Models/` のモデルを列挙して `getTable()` から
  テーブル名集合を作り、走査器へ渡す。`oauth_*` は model を持たないため自動的に対象外。
  これにより実測 `DB::table` 25 件のうち対象は `organizations` / `users` /
  `organization_user` / `project_members` / `ticket_ledger_entries` の 10 件程度に絞られる。

## [Critical] `findMany` / `destroy` / `whereKeyNot` を単数 identity と混ぜている

- 判断: **対応する**
- 根拠: 正しい。`findMany($ids)` / `destroy($ids)` は複数 id、`whereKeyNot($id)` は除外条件で、
  単数前提の副条件 (identity 引数の provenance 判定等) と噛み合わない。
- 対応内容: candidate に **`predicateKind`** を持たせる:
  `SingleIdentity` / `MultiIdentity` / `IdentityExclusion` / `DestructiveIdentity`。
  case ごとの副条件を `predicateKind` に応じて分け、失敗メッセージも分ける。
  - `whereKeyNot` は **v1 スコープに残す** (実測 6 件だが全て relation 起点 / model 由来引数で
    候補化しないため追加コスト ~0。かつ `whereKeyNot($requestId)` は列挙ベクタとして実在しうる)
  - `findMany` / `findOrNew` / `destroy` は**実コード 0 件**だが文法に残す (文字列 1 個の追加でコスト 0、
    将来の混入を止める)

## [Warning] provenance 証明が詳細設計で概念設計より後退している

- 判断: **対応する (指摘どおり後退していた)**
- 根拠: 詳細設計 §3 の表は「型付き引数が `App\Models\*`」で除外するように読め、
  概念設計 §4-2(c) の「**保証済み provenance に属する場合のみ除外**」条件が落ちていた。
- 対応内容: 詳細設計の provenance 節を概念設計に合わせて書き直し、
  **型付き引数であることに加えて、その model が保証済み provenance (route binding / relation 起点 /
  本 gate 分類済み) から来ていること**を要求する。証明できない場合は候補に残す (fail-closed)。

## [Warning] alias 追跡の fail 方向が不明

- 判断: **対応する**
- 対応内容: 「**代入位置から候補位置までのトークン範囲に同名変数への再代入が 1 回でもあれば alias 無効**」
  と明記する。分岐の中か外かを判定しない = **過剰検出寄り**で deny-by-default に整合する。

## [Warning] `OwnerScopedQueryConstraint` の右辺 provenance 判定が過大 (`whereHas` ネスト closure)

- 判断: **対応する (v1 で `whereHas` を外す)**
- 根拠: 実測すると `whereHas` を必要とする候補は**存在しない**
  (`MembershipScopedOrganizationBinder` は identity 述語が動的列名 `where($field, $value)` のため
  そもそも候補化しない)。使わない機能を先に作るのは思考原則 2 違反。
- 対応内容: v1 の許可 signature を
  `where('organization_id'|'user_id'|'team_id'|'project_id', $model->getKey()|$model->id)` と
  `whereBelongsTo($model)` の 2 形に限定。`whereHas` は**必要になったとき fixture と一緒に足す**と明記。

## [Warning] `LocalOnlyDiagnostics` の route 照合が環境依存

- 判断: **対応する**
- 対応内容: route 走査だけに依存せず 2 段にする。
  (a) `routeName` の route がテスト環境に存在し `LocalOnly` middleware を持つ
  (テスト環境では `runningUnitTests()` により登録されるため成立する、と根拠をコメントに書く)、
  (b) **`routes/` 側の登録条件リテラル** (`isLocal` / `runningUnitTests`) の存在も併せて固定する。
  片方が環境差で崩れてももう片方が残る。

## [Warning] 債務 case の marker が文字列依存で弱い

- 判断: **対応する**
- 対応内容: marker を**定数リストで明示**する:
  `->organizations()->whereKey(` / `->users()->whereKey(` / `->members()->whereKey(` /
  `whereBelongsTo($organization` / `organizationRole(`。
  **`lockForUpdate` は marker に含めない** (競合制御であって所属検証ではない — Round 3 の指摘と一貫)。
  `verifiedBy` の呼び出し照合は static/instance 両形を受理する
  (`OrganizationMembershipService::transferOwnership` を `$this->membership->transferOwnership(` で
  呼ぶ形が実際の姿) と明記する。

## [Warning] app/Enums への追加は production autoload に入る

- 判断: **対応する (明記のみ)**
- 根拠: 既存 `ControllerAuthorizationExemption` と同じ位置に置く一貫性を優先する。
- 対応内容: 詳細設計に「テスト専用語彙だが、既存 enum との一貫性を優先し production autoload への
  混入を許容する」と明記した。

## [Suggestion] metadata の getter

- 判断: **対応する** (typo が runtime まで残るのは PHPStan level 10 の趣旨に反する)
- 対応内容: `actorSource()` / `enqueuedBy()` / `routeName()` / `commandSignature()` /
  `verifiedBy()` / `validationRule()` / `todoRef()` を生やし、case 不一致なら例外を投げる。

## [Suggestion] degenerate PASS 防止の下限を上げる

- 判断: **対応する**
- 対応内容: 「1 件以上」→ **`>= 20`**。走査器が部分的に壊れて候補が激減した場合も検知できる。

## [Suggestion] `whereRaw` の 0 件 assertion の pattern

- 判断: **対応する**
- 対応内容: `whereRaw` / `whereIntegerInRaw` の**呼び出し自体**を検出し、
  第 1 引数が文字列リテラルなら正規化して `(^|[.\s(])id\b` を見る。
  引数が非リテラルなら**無条件で fail** (中身が読めない = 範囲外経路が生えた合図)。


---

## 改訂後の詳細設計 (全文)

# 詳細設計: ModelDirectFetchInvariantTest (直 find 禁止 gate) の追従

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

> 本タスクは **Architecture テストの追加のみ**。4/5/6/7/8 は該当しない。
> 1 は本タスクの主題そのもの (走査器の Unit テストまで含めて完了)。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)、`RefreshDatabase` は `tests/Pest.php` でグローバル適用(個別 `DatabaseTransactions` 禁止)
- `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `vendor/bin/pint` / `composer fix`
- PHP 8.4 + Laravel 12

> **本タスクは DB を一切触らない** (静的走査 + route 走査のみ)。Factory / migration の追加は無い。

## 概念設計リファレンス

`devnotes/20260805-2311-model-direct-fetch-gate/conceptual-design.md`
(Codex レビュー 3 ラウンド消化済み。残存リスクは `codex-history/conceptual-review-decisions-round-3.md` 末尾)

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 分類 enum の追加 | `app/Enums/Security/DirectFetchJustification.php` (新規) | 高 |
| 2 | inventory エントリ型の追加 | `tests/Support/Security/DirectFetchJustificationEntry.php` (新規) | 高 |
| 3 | 走査器の追加 | `tests/Support/Security/PrimaryKeyStaticQueryScanner.php` (新規) | 高 |
| 4 | inventory 本体の追加 | `tests/Support/Security/DirectFetchInventory.php` (新規) | 高 |
| 5 | gate 本体の追加 | `tests/Architecture/ModelDirectFetchInvariantTest.php` (新規) | 高 |
| 6 | 走査器の Unit テスト | `tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php` (新規) | 高 |
| 7 | 規約ドキュメントへの gate 名登録 | `AGENTS.md` / `docs/app-integration-guide.md` / `docs/architecture.md` (変更) | 中 |

**アプリのコード (`app/Http`, `app/Services` 等) は 1 行も変更しない。**
施策 1 の enum は `app/Enums/Security/` に置くが、これは既存 `ControllerAuthorizationExemption` と同じ
「アプリが持つ裁定語彙」であり、振る舞いには一切関与しない (テストのみが参照)。

> **既知のトレードオフ**: テスト専用の語彙を production autoload (`app/`) に置くことになる。
> `tests/` 側へ置けば autoload は汚れないが、**既存の `ControllerAuthorizationExemption` と
> 位置が割れる**。本設計は**既存 enum との一貫性を優先**し、production autoload への混入を
> 許容する (enum 1 本・実行時参照 0 で実害が無いため)。

### 波及変更 (全施策共通)

- TypeScript 型定義: **なし** (frontend に波及しない)
- API Resource / DTO: **なし**
- Inertia Props: **なし**
- 既存テストの変更: **なし** (新規追加のみ。既存 Architecture テストとは母集団が交わらない)

---

## 施策 1: 分類 enum `DirectFetchJustification`

### 変更箇所

新規 `app/Enums/Security/DirectFetchJustification.php`

### 設計

`ControllerAuthorizationExemption` と同じ作法 —
**汎用に見える case ほど適用条件を狭く書く**。当てはまる case が無ければ「直すべきコード」である。

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * クラス起点の主キー同一性クエリ (ClassRootedPrimaryKeyQuery) が
 * 「テナントスコープ外で id からモデルを引いてよい」と裁定された理由の分類。
 *
 * `tests/Architecture/ModelDirectFetchInvariantTest.php` が deny-by-default で
 * 「候補でない」か「本 enum + 具体的根拠 + 構造化 field」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★ここに無い形は「例外に足す」のではなく「relation 起点に直す」が既定である。
 */
enum DirectFetchJustification: string
{
    /**
     * 同一クエリ内で所有者/テナントに閉じている。
     *
     * 適用条件 (全て満たすこと):
     * - identity 述語と**同じ chain** に所有者/テナント制約がある
     *   (`where('user_id'|'organization_id'|'team_id'|'project_id', …)` /
     *    `whereHas('users'|'organizations'|'projects', …)` / `whereBelongsTo(…)`)
     * - その制約の**右辺が解決済みモデル由来**である (request 由来の値では不可)
     * - 取得**後**に弾く形ではない (後段で弾くと 403/404 差で存在が漏れる)
     */
    case OwnerScopedQueryConstraint = 'owner_scoped_query_constraint';

    /**
     * identity が同一メソッド内のテナントスコープ済みクエリで確定している。
     *
     * 適用条件: 当該変数への代入が relation 起点 (`$organization->projects()->value('id')` 等) で、
     * 代入と使用の間に再代入が無い。
     */
    case IdDerivedFromTenantScopedQuery = 'id_derived_from_tenant_scoped_query';

    /**
     * identity が認証済み actor / 検証済み token claim 由来である。
     *
     * 適用条件 (全て満たすこと):
     * - identity が request payload・query string 由来で**ない**
     * - 同一メソッド内に request accessor が 1 つも無い
     * - `actorSource` を明示できる (どの middleware / claim が actor を確定したか)
     *
     * ★本 case のみ機械証明ができない (provenance のデータフロー解析は走査器の範囲外)。
     *   最終的に人手の根拠文に依存することを承知の上で使う。
     */
    case AuthenticatedActorScope = 'authenticated_actor_scope';

    /**
     * identity が enqueue 時にサーバが確定した job property である。
     *
     * 適用条件: `app/Jobs/**` 配下で identity が `$this->{…Id}`。
     * `enqueuedBy` に dispatch 元を書く。
     *
     * ★actor/token とは信頼境界が違う (過去のリクエストがシリアライズした値であり、
     *   dispatch 元が誤っていれば汚染されうる) ため AuthenticatedActorScope と分けている。
     */
    case QueuePayloadRehydration = 'queue_payload_rehydration';

    /** local 専用の診断経路。route 登録自体が local 限定で production から到達不能。 */
    case LocalOnlyDiagnostics = 'local_only_diagnostics';

    /** 人間の運用者が CLI で明示実行する。HTTP から到達不能。 */
    case OperatorInvokedConsoleCommand = 'operator_invoked_console_command';

    /**
     * **債務**: payload 由来 id を global に引いており、補償チェックが fetch の後段にある。
     *
     * 他の case が「fetch 時点でスコープが閉じている」のに対し、本 case は
     * 「引いた後で弾く」形であり**安全性の質が違う**。準拠形と同列に扱わないために分けてある。
     * 新規コードで本 case を使ってはならない (既存 2 件の可視化のためだけに存在する)。
     */
    case PayloadIdWithGlobalExistenceRuleDebt = 'payload_id_with_global_existence_rule_debt';
}
```

### PHPStan 適合チェック

- [x] backed enum (`string`)。戻り値型は不要
- [x] app → tests の import を作らない (docblock で参照のみ)

---

## 施策 2: inventory エントリ型 `DirectFetchJustificationEntry`

### 変更箇所

新規 `tests/Support/Security/DirectFetchJustificationEntry.php`

### 設計判断: 名前付きコンストラクタで「必須 field の抜け」を型で殺す

構造化 field は case ごとに異なる (`actorSource` / `enqueuedBy` / `routeName` /
`commandSignature` / `verifiedBy` / `validationRule` / `todoRef`)。
すべて nullable プロパティにして実行時に検査すると、**検査漏れがそのまま抜け道**になる。

そこで **case ごとの名前付きコンストラクタ**だけを public にし、コンストラクタは private にする。
「case を選んだ時点で必須 field が型として要求される」形にすれば、
実行時チェックより先にコンパイル (PHPStan) 段で止まる。

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use App\Enums\Security\DirectFetchJustification;
use Webmozart\Assert\Assert;

/**
 * 直 fetch 候補 1 件分の裁定エントリ。
 *
 * case ごとに必須の構造化 field が違うため、**名前付きコンストラクタ経由でのみ**生成できる。
 * nullable プロパティ + 実行時検査にすると検査漏れが抜け道になるため、
 * 「case を選んだ時点で必須 field が型として要求される」形にしてある。
 */
final readonly class DirectFetchJustificationEntry
{
    public const int REASON_MIN_LENGTH = 30;

    /**
     * @param  array<string, string>  $metadata  case 固有の構造化 field
     */
    private function __construct(
        public DirectFetchJustification $case,
        public string $reason,
        public array $metadata,
    ) {
        Assert::minLength($this->reason, self::REASON_MIN_LENGTH);
    }

    public static function ownerScopedQuery(string $reason): self
    {
        return new self(DirectFetchJustification::OwnerScopedQueryConstraint, $reason, []);
    }

    public static function idFromTenantScopedQuery(string $reason): self
    {
        return new self(DirectFetchJustification::IdDerivedFromTenantScopedQuery, $reason, []);
    }

    /** @param  'authenticated_user'|'validated_token_claim'|'passport_token_record'  $actorSource */
    public static function authenticatedActor(string $reason, string $actorSource): self
    {
        return new self(DirectFetchJustification::AuthenticatedActorScope, $reason, [
            'actorSource' => $actorSource,
        ]);
    }

    /** @param  string  $enqueuedBy  dispatch 元の `Class::method` */
    public static function queuePayload(string $reason, string $enqueuedBy): self
    {
        return new self(DirectFetchJustification::QueuePayloadRehydration, $reason, [
            'enqueuedBy' => $enqueuedBy,
        ]);
    }

    /** @param  string  $routeName  route 走査で LocalOnly middleware を照合する対象 */
    public static function localOnly(string $reason, string $routeName): self
    {
        return new self(DirectFetchJustification::LocalOnlyDiagnostics, $reason, [
            'routeName' => $routeName,
        ]);
    }

    public static function operatorConsole(string $reason, string $commandSignature): self
    {
        return new self(DirectFetchJustification::OperatorInvokedConsoleCommand, $reason, [
            'commandSignature' => $commandSignature,
        ]);
    }

    /**
     * **債務**エントリ。新規コードで使わない。
     *
     * @param  string  $verifiedBy      補償チェックを行う `Class::method`
     * @param  string  $validationRule  当該 id に掛けている validation rule (例 `exists:users,id`)
     * @param  string  $todoRef         後続 TODO の ID (例 `aicue:T120`)
     */
    public static function globalExistenceRuleDebt(
        string $reason,
        string $verifiedBy,
        string $validationRule,
        string $todoRef,
    ): self {
        return new self(DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt, $reason, [
            'verifiedBy' => $verifiedBy,
            'validationRule' => $validationRule,
            'todoRef' => $todoRef,
        ]);
    }

    // --- 構造化 field の accessor (typo を runtime まで持ち越さない) ---

    public function actorSource(): string { return $this->require('actorSource'); }
    public function enqueuedBy(): string { return $this->require('enqueuedBy'); }
    public function routeName(): string { return $this->require('routeName'); }
    public function commandSignature(): string { return $this->require('commandSignature'); }
    public function verifiedBy(): string { return $this->require('verifiedBy'); }
    public function validationRule(): string { return $this->require('validationRule'); }
    public function todoRef(): string { return $this->require('todoRef'); }

    /** 当該 case が持たない field を読んだら設定ミスとして落とす。 */
    private function require(string $key): string
    {
        Assert::keyExists($this->metadata, $key, $this->case->value.' は '.$key.' を持たない');

        return $this->metadata[$key];
    }
}
```

### PHPStan 適合チェック

- [x] `final readonly class` + private constructor
- [x] `array<string, string>` の型パラメータ明示
- [x] null 安全 (`Assert::minLength`)
- [x] `$actorSource` は literal union で型を絞る (PHPStan が誤値を検出)

---

## 施策 3: 走査器 `PrimaryKeyStaticQueryScanner`

### 変更箇所

新規 `tests/Support/Security/PrimaryKeyStaticQueryScanner.php`

### 責務

**「解析器 = 本 helper / 母集団走査と突合 = テスト」**という `AuthorizationMarkerScanner` と同じ分離。
走査器の positive/negative は施策 6 が恒久固定する。

### 公開シグネチャ

```php
final class PrimaryKeyStaticQueryScanner
{
    /**
     * ファイル 1 本から候補 (ClassRootedPrimaryKeyQuery) を抽出する。
     *
     * @param  string         $source         PHP ソース全文
     * @param  string         $relativePath   リポジトリ相対パス (候補 key の生成に使う)
     * @param  list<string>   $modelTables    `App\Models\*` に対応するテーブル名
     *                                        (`DB::table(...)` 起点の対象を絞る)
     * @return list<PrimaryKeyStaticQueryCandidate>
     */
    public static function candidates(string $source, string $relativePath, array $modelTables): array;

    /** 候補が「同一 chain に所有者/テナント制約 (右辺 provenance 込み)」を持つか。 */
    public static function hasOwnerScopedConstraint(PrimaryKeyStaticQueryCandidate $candidate): bool;

    /** 候補のメソッド本文に request accessor が 1 つも無いか (AuthenticatedActorScope の negative check)。 */
    public static function methodIsFreeOfRequestAccessors(PrimaryKeyStaticQueryCandidate $candidate): bool;

    /** 候補の identity 変数が、同一メソッド内で relation 起点クエリから代入されているか。 */
    public static function identityAssignedFromRelationQuery(PrimaryKeyStaticQueryCandidate $candidate): bool;

    /** ソース中に `whereRaw('id` / `whereIntegerInRaw('id'` があるか (範囲外経路の 0 件 assertion 用)。 */
    public static function containsRawPrimaryKeyPredicate(string $source): bool;

    /** 指定 `Class::method` の**メソッド本文だけ**を切り出す (債務 case の検証に使う)。 */
    public static function methodBody(string $source, string $methodName): ?string;
}
```

候補の値オブジェクト (同ファイル内 or 別ファイル):

```php
final readonly class PrimaryKeyStaticQueryCandidate
{
    public function __construct(
        /** 構造 fingerprint 入りの安定 key (下記「候補 key の設計」) */
        public string $key,
        public string $relativePath,
        public string $methodName,
        /** 述語の種別。case 副条件の適用先を分ける */
        public PrimaryKeyPredicateKind $predicateKind,
        /** identity 述語に渡された引数のソース断片 (例 `(int) $userId`) */
        public string $identityArgument,
        /** 候補式を構成する chain のトークン列 (副条件判定に使う) */
        public string $chainSource,
        /** 候補が属するメソッド本文 */
        public string $methodSource,
    ) {}
}

/**
 * 主キー述語の種別。
 *
 * `findMany($ids)` と `findOrFail($id)` を同じ扱いにすると、
 * identity 引数を単数前提で判定する副条件が破綻するため分けている。
 */
enum PrimaryKeyPredicateKind
{
    /** `find` / `findOrFail` / `findOrNew` / `whereKey` / `where('id', …)` / `firstWhere('id', …)` */
    case SingleIdentity;
    /** `findMany` / `whereIn('id', …)` */
    case MultiIdentity;
    /** `whereKeyNot` — 「同一性」ではなく除外条件 (列挙ベクタになりうる) */
    case IdentityExclusion;
    /** `destroy` — 取得ではなく削除 */
    case DestructiveIdentity;
}
```

### `predicateKind` 別の副条件適用

| `predicateKind` | 副条件の扱い |
|---|---|
| `SingleIdentity` | 全 case の副条件をそのまま適用 |
| `MultiIdentity` | identity provenance 判定は**配列変数**に対して行う。`$model->getKey()` 形の除外は適用しない |
| `IdentityExclusion` | 失敗メッセージを分ける (「除外条件に request 由来 id を使っている」) |
| `DestructiveIdentity` | 失敗メッセージを分ける (「取得でなく削除」)。債務 case の適用を**禁止**する |

**実測**: `findMany` / `findOrNew` / `destroy` は実コード **0 件** (`::destroy(` の 1 件は docblock 中)、
`whereKeyNot` は 6 件だが全て relation 起点 / 引数が model 由来のため候補化しない。
文法に残すコストは文字列 1 個ずつであり、将来の混入を止める価値がある。

### 候補 key の設計 (行番号を使わない・出現順を主識別子にしない)

```
{app 相対パス}#{メソッド名}#{rootKind}.{predicate}:{identity}#{ordinal}
```

例: `Http/Controllers/Projects/ProjectMemberController.php#store#User.findOrFail:$userId#1`

| 要素 | 内容 |
|---|---|
| `rootKind` | `User` (モデル短縮名) / `DB:users` (table 名) |
| `predicate` | `findOrFail` / `whereKey` / `where:id:=` |
| `identity` | 正規化した引数 (`$userId` / `$dto->user_id` / `$this->renderJobId`。cast `(int)` は除去) |
| `ordinal` | **衝突解消用の従属要素**。主識別子にしない |

行番号を key にすると無関係な編集で inventory が全崩れする。
**しかし「メソッド内の出現順」だけでも不十分**である: 同一メソッドで候補が 1 件増減すると
後続 key が全てずれ、**既存の裁定理由が別の候補へ横滑りしても人間が気付けない**
(deny-by-default の意味が消える最悪の形)。

構造 fingerprint を入れると、候補の中身が変われば **stale (テスト 2) と未分類 (テスト 1) の両方**が
同時に出るため、理由の横滑りが構造的に起こらない。

### 検出アルゴリズム (token_get_all)

1. `token_get_all($source)` → **コメント / docblock / inline HTML を除去**
   (文字列リテラルは「トークンとして残すが**内容は照合しない**」)
2. `use` 文と `namespace` を走査し、**`App\Models\*` に解決できるクラス短縮名の集合**を作る
3. メソッド境界 (`T_FUNCTION` から波括弧深さで対応する `}` まで) を確定する
4. メソッド本文内で **chain root** を探す:
   - `T_STRING`(Models 集合に含まれる) + `T_DOUBLE_COLON`
   - `\App\Models\…` の FQCN 直書き / 同一 namespace 参照
   - `self` / `static` (ファイルが `app/Models/` 配下のとき)
   - `T_NEW` + Models 集合のクラス
   - `DB` + `::table(` / `::connection(` → `->table(` — ただし **table 名が `$modelTables` に
     含まれる場合のみ**。`DB::table('oauth_access_tokens')` のような Passport 内部テーブルは
     `App\Models\*` を持たないため対象外になる (gate の主張 = `ModelDirectFetch` と母集団を揃える)
5. root から `;` または文末までを **chain** として切り出す (括弧深さで引数内の別 chain と区別)
6. chain 内に**主キー同一性述語**があるか判定 (§概念設計 4-2(b) の表)
   - `where` の 3 引数形は**第 2 引数が `'='` / `'in'` のときのみ**候補 (順序比較を除外)
   - array 形 `where(['id' => …])` / `where([['id','=',…]])` も対象
7. identity 引数を取り出し、**provenance 証明**を適用 (下記) → 証明できたら候補から外す
8. 残ったものを `PrimaryKeyStaticQueryCandidate` として返す

### provenance 証明 (概念設計 §4-2(c) の実装)

対象となる引数の形は `$var->getKey()` / `$var->id` / `$var->{snake}_id` のみ。
これらを候補から外すには **2 段の条件を両方**満たす必要がある。
どちらか一方でも満たさなければ**候補に残す (fail-closed)**。

**第 1 段: `$var` が Eloquent モデルであることの証明**

| 証明 | 判定方法 |
|---|---|
| 型付き引数が `App\Models\*` | 当該メソッドのシグネチャを走査し、`$var` の型宣言が Models 集合に含まれる |
| PHPDoc で明示 | 直上の `/** @var Project $x */` を照合 |
| 同一メソッド内で relation 起点クエリから代入 | `$var = $y->rel()->…` の代入を検出 |

**第 2 段: その `$var` が「保証済み provenance」に属することの証明**

| 保証済み provenance | 判定方法 |
|---|---|
| route binding で解決された model | メソッドが Controller / Middleware のハンドラで、`$var` が**型付き引数**である (= implicit binding。`NestedRouteIdorDefenseTest` + `TenantBoundaryOrderingTest` が別途保証) |
| relation 起点クエリの結果 | 第 1 段の 3 番目と同じ検出 |
| 本 gate で分類済みの式の結果 | `$var = <候補式>` の代入を検出 (当該候補が inventory 済みであることは gate 本体が保証) |

> **なぜ 2 段必要か** (概念設計 §4-2(c) / Codex Round 3 Critical):
> 「モデルであること」だけでは足りない。元モデルが `where('uuid', $requestUuid)` や slug で
> 解決されていれば、そのモデルはテナントに閉じていないのに `$model->id` は「モデル由来」に見える。
> **`$dto->user_id` のように `$dto` の型が証明できないものは第 1 段で候補に残る**
> (Codex Round 3 Critical)。

### builder alias 追跡 (概念設計 §4-3)

- `$var = <chain root 式>` の**単純代入**で `$var` を chain root として伝播する
- **fail 方向 (重要)**: **一度でも静的起点から代入された変数は、同一メソッド内での以降の使用を
  すべて候補として扱う。再代入があっても取り消さない。**
  分岐の内か外かは**判定しない**
- 引数渡し・プロパティ代入・メソッドをまたぐ伝播は**追跡しない**

> **なぜ「再代入で無効化」にしないか**: 無効化すると
> `$q = User::query(); if ($x) { $q = $other; } $q->whereKey($id);` が**検出されなくなる**。
> これは検出漏れ = **fail-open** であり、deny-by-default の趣旨に反する
> (「再代入すれば gate を黙らせられる」= 最も安易な回避手段になる)。
> 取り消さない側に倒すと、実際には安全なコードが候補化する誤検出が起きうるが、
> **誤検出は分類 1 行で解消でき、検出漏れは永久に気付けない**。非対称なのでこちらへ倒す。

### 走査器の限界 (docblock に明記する)

- **到達可能性を判定しない** (`if (false) { … }` 中の候補も候補になる)
- `whereRaw` / `whereIntegerInRaw` / 動的列名 (`where($col, $x)`) は**範囲外**
- alias 追跡は同一メソッド内の単純代入のみ
- `AuthenticatedActorScope` の provenance は機械証明できない
- 非 bracketed namespace (`namespace App\Foo;` 形式) を前提とする
  (`AuthorizationMarkerScanner` と同じ前提。Pint が強制している)

### PHPStan 適合チェック

- [x] `list<PrimaryKeyStaticQueryCandidate>` の generics 明示
- [x] `token_get_all` の戻り値 (`array<int, string|array{int, string, int}>`) を明示的に narrowing
- [x] `methodBody()` は `?string` を返し、呼び出し側で null 分岐

---

## 施策 4: inventory 本体 `DirectFetchInventory`

### 変更箇所

新規 `tests/Support/Security/DirectFetchInventory.php`

### 設計

`NestedRouteDefenseInventory` と同じく **静的クラス**に置く
(Pest のファイル読み込み順に依存する global 関数にしない)。

```php
final class DirectFetchInventory
{
    /** 走査対象。 */
    public static function scannedPaths(): array;   // ['app', 'routes']

    /**
     * `App\Models\*` に対応するテーブル名 (DB::table 起点の対象を絞る)。
     *
     * `app/Models/` の具象モデルを列挙し `getTable()` から導出する
     * (ハードコードするとモデル追加時に静かに漏れるため)。
     *
     * @return list<string>
     */
    public static function modelTables(): array;

    /**
     * 走査対象全体から抽出した候補。
     *
     * @return list<PrimaryKeyStaticQueryCandidate>
     */
    public static function candidates(): array;

    /**
     * 候補 key => 裁定エントリ。
     *
     * @return array<string, DirectFetchJustificationEntry>
     */
    public static function inventory(): array;
}
```

> `modelTables()` を**動的導出**にするのが要点。テーブル名をハードコードすると
> 新しいモデルを足したときに `DB::table('new_things')->where('id', $payloadId)` が
> **静かに母集団から漏れる**。

### 初期 inventory (実装時に走査器を流して確定する)

概念設計 §2-3 の実測では **33 件** (旧 syntactic フィルタ)。
型証明を要求する新フィルタでは増えるため、見積り **33〜40 件**。
群ごとの登録方針は次のとおり (代表例):

```php
// queue payload 再水和 (app/Jobs/** の 8 件前後)
'Jobs/Manual/RunManualRender.php#handle#1' => DirectFetchJustificationEntry::queuePayload(
    'render job id は RenderJobService::trigger() が採番し dispatch 時に確定させた値で、'
    .'HTTP 入力を経由しない。worker 側は再水和のみ行う',
    enqueuedBy: 'App\Services\Manual\RenderJobService::trigger',
),

// token / actor 由来 (8 件前後)
'Http/Middleware/ResolveApiActor.php#resolveOrganization#1' => DirectFetchJustificationEntry::authenticatedActor(
    'organization id は Passport の access token レコードに紐づく claim であり、'
    .'request payload からは受け取らない (resolve.api-actor が token を検証済み)',
    actorSource: 'passport_token_record',
),

// 同一クエリ内で所有者スコープ
'Http/Routing/SelfScopedPasskeyBinder.php#resolve#1' => DirectFetchJustificationEntry::ownerScopedQuery(
    '所有者スコープの where を解決クエリ自体に含めている (取得後に弾くと 403/404 差で存在が漏れるため)。'
    .'relation を使わないのは PasskeyUser interface が vendor 型で解決するため',
),

// テナントスコープ済みクエリで確定した id
'Services/Project/DefaultProjectResolver.php#resolveForUpdate#1' => DirectFetchJustificationEntry::idFromTenantScopedQuery(
    'id は直前の $organization->projects() で組織スコープ済み。HasManyThrough に lockForUpdate を'
    .'掛けると JOIN 先までロックするため単一テーブルの主キーロックに落としている',
),

// local 限定
'Http/Controllers/DebugLoginController.php#login#1' => DirectFetchJustificationEntry::localOnly(
    'local 専用のデバッグログイン。route 登録自体が isLocal/runningUnitTests 限定で、'
    .'production では route が存在しない',
    routeName: 'debug.login-as',
),

// 運用コマンド
'Console/Commands/ResetAdminMfaCommand.php#handle#1' => DirectFetchJustificationEntry::operatorConsole(
    '運用者が CLI で admin を名指しして MFA をリセットする保守コマンド。'
    .'HTTP から到達不能で scheduler / queue からも呼ばれない',
    commandSignature: 'admin:reset-mfa {id}',
),

// ★債務 (新規コードで使わない。既存 2 件のみ)
'Http/Controllers/Organizations/OrganizationOwnershipController.php#store#1'
    => DirectFetchJustificationEntry::globalExistenceRuleDebt(
        'payload の user_id を組織スコープ外で引いている。移譲先が組織メンバーであることの検証は'
        .'Service のロック下で行われるが、fetch 時点ではスコープが閉じていない',
        verifiedBy: 'App\Services\Organization\OrganizationMembershipService::transferOwnership',
        validationRule: 'exists:users,id',
        todoRef: '<TODO 登録後に採番>',
    ),
```

> **`todoRef`**: 後続 TODO (概念設計 §7-1) を先に `app-todo-add` で起票し、その ID を書く。
> 実装時点で未起票なら、実装 PR 内で TODO を起票してから ID を埋める
> (プレースホルダのままコミットしない — 債務が追跡不能になる)。

---

## 施策 5: gate 本体 `ModelDirectFetchInvariantTest`

### 変更箇所

新規 `tests/Architecture/ModelDirectFetchInvariantTest.php`

### テスト一覧 (Pest)

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | 全候補が inventory に明示分類されている (未知は fail) | `app/**` + `routes/*.php` を走査し、候補 key が inventory に無ければ fail |
| 2 | inventory の key は現存候補 (stale 検出) | inventory にあって候補に無い key を fail (双方向整合) |
| 3 | `OwnerScopedQueryConstraint` の機械副条件 | 同一 chain の所有者制約 + 右辺 provenance を照合 (**v1 の許可 signature は 2 形のみ**。下記) |
| 4 | `IdDerivedFromTenantScopedQuery` の機械副条件 | identity 変数が relation 起点クエリから代入されている |
| 5 | `AuthenticatedActorScope` の機械副条件 | 同一メソッドに request accessor が無い + `actorSource` が既定値集合 |
| 6 | `QueuePayloadRehydration` の機械副条件 | `app/Jobs/**` 配下 + identity が `$this->{…Id}` + `enqueuedBy` あり |
| 7 | `LocalOnlyDiagnostics` の機械副条件 | **2 段**: (a) `routeName` の route が現存し `LocalOnly` middleware を持つ、(b) `routes/` に登録条件リテラル (`isLocal` / `runningUnitTests`) が実在する |
| 8 | `OperatorInvokedConsoleCommand` の機械副条件 | `app/Console/Commands/` 配下 + `commandSignature` あり |
| 9 | **債務 case の機械副条件** | `verifiedBy` の `Class::method` が実在し、**当該メソッド本文**に membership/tenant marker (定数リスト) がある。かつ**呼び出し側が exact method を呼んでいる** |
| 10 | 債務 case の増殖防止 | `PayloadIdWithGlobalExistenceRuleDebt` の件数が **2 以下**であること |
| 11 | 範囲外経路の 0 件固定 | `whereRaw` / `whereIntegerInRaw` の呼び出しを検出し、第 1 引数が文字列なら `(^\|[.\s(])id\b` を照合。**非リテラル引数は無条件 fail** |
| 12 | **degenerate PASS 防止** | 走査器が現行コードベースから**候補を 20 件以上検出する**こと |

### テスト 3 の許可 signature (v1)

```
where('organization_id'|'user_id'|'team_id'|'project_id', $model->getKey()|$model->id)
whereBelongsTo($model)
```

`whereHas(…)` は **v1 では受理しない**。実測すると `whereHas` を必要とする候補は存在せず
(`MembershipScopedOrganizationBinder` は identity 述語が動的列名 `where($field, $value)` のため
そもそも候補化しない)、ネスト closure 内の右辺 provenance 判定は実装コストが跳ねる。
**必要になったとき fixture と一緒に足す** (思考原則 2)。

### テスト 9 の membership/tenant marker (定数リスト)

```
->organizations()->whereKey(     ->users()->whereKey(     ->members()->whereKey(
whereBelongsTo($organization     organizationRole(
```

**`lockForUpdate` は marker に含めない** — ロックは競合制御であって所属検証ではない
(Codex 概念 Round 3 / 詳細 Round 1 の指摘と一貫)。
`verifiedBy` の呼び出し照合は **static / instance の両形**を受理する
(実際の姿は `$this->membership->transferOwnership(` であり `Class::method` 表記とは一致しない)。

### テスト 11 / 12 の意図 (下限を置く理由)

`whereRaw` の第 1 引数が非リテラルなら**中身が読めない** = 範囲外経路が生えた合図として fail させる。
テスト 12 の下限を「1 件以上」でなく **20 件**にするのは、走査器が**部分的に**壊れて
候補が激減した場合 (例: chain 切り出しの regression) も検知するため。

### テスト 10 (債務の増殖防止) の意図

`PayloadIdWithGlobalExistenceRuleDebt` は green にできてしまう case なので、
**放置が常態化するリスク**がある (Codex Round 3 Warning)。
件数に上限 2 を置くことで、3 件目を足そうとした瞬間に CI が落ち、
「debt を増やす」判断がレビューの俎上に必ず乗る。

### テスト 12 (degenerate PASS 防止) の意図

`ScenarioWritePathInventoryTest` の同名テストと同じ思想。
走査器が壊れて**何も検出しなくなった**とき、テスト 1/2 は両方 green になり
**gate が静かに無力化する**。「現行コードベースに候補が一定数実在すること」を固定して防ぐ。

### 実装スケッチ (テスト 1)

```php
test('クラス起点の主キー同一性クエリが全て inventory に明示分類されている (未知は fail)', function (): void {
    $inventory = DirectFetchInventory::inventory();
    $violations = [];

    foreach (DirectFetchInventory::candidates() as $candidate) {
        if (! array_key_exists($candidate->key, $inventory)) {
            $violations[] = $candidate->key.' ('.$candidate->identityArgument.' で引いている)';
        }
    }

    expect($violations)->toBe([],
        'テナントスコープ外で id からモデルを引いている箇所があります。'
        .'まず relation 起点 ($organization->users()->whereKey(...)) に直せないか検討し、'
        .'直せない場合のみ DirectFetchInventory へ DirectFetchJustification + 具体的根拠を登録してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

失敗メッセージは**「例外に足せ」ではなく「まず直せ」を先に言う**
(`NestedRouteIdorDefenseTest` の失敗メッセージと同じ作法)。

### テスト計画チェック

- [x] 個別の `DatabaseTransactions` を使っていない (DB を触らない)
- [x] Factory 不要 (静的走査 + route 走査のみ)
- [x] 既存テストの削除・上書きなし

---

## 施策 6: 走査器の Unit テスト

### 変更箇所

新規 `tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php`
(既存 `tests/Unit/Architecture/AuthorizationMarkerScannerTest.php` と同じ場所・同じ流儀)

### positive fixture (**検出されなければならない** — 概念設計 §8-2)

| # | fixture | 塞ぐ抜け道 |
|---|---|---|
| 1 | `User::query()->where('id', $payloadId)->firstOrFail()` | 述語アンカー |
| 2 | `$q = User::query(); $q->where('id', $payloadId)->first()` | builder alias |
| 3 | Service メソッドが scalar `$userId` を受け `User::findOrFail($userId)` | Service 委譲 |
| 4 | `User::query()->where('users.id', $id)` | qualified 列 |
| 5 | `User::query()->where(['id' => $id])` | array 形 |
| 6 | `User::destroy($id)` | destroy |
| 7 | `DB::table('users')->where('id', $payloadId)` | DB::table |
| 8 | `\App\Models\User::query()->whereKey($id)` | FQCN 起点 |
| 9 | `DB::table('users as u')->where('u.id', $id)` | alias 付き qualified |
| 10 | `User::whereId($id)` / `User::query()->where('id', '=', $id)` / `User::query()->whereIn('users.id', $ids)` | 文法バリエーション |
| 11 | `(new User())->newQuery()->whereKey($id)` | `new` 起点 |
| 12 | **`User::query()->whereKey($dto->user_id)`** (`$dto` の型が証明できない) | **provenance フィルタの誤除外** |
| 13 | `User::findMany($ids)` / `User::query()->whereIn('id', $ids)` が `MultiIdentity` として検出される | `predicateKind` の分岐 |
| 14 | `User::whereKeyNot($requestId)` が `IdentityExclusion` として検出される | 除外条件による列挙 |
| 15 | `DB::table('users')->where('id', $id)` は検出し、`DB::table('oauth_access_tokens')->where('id', $id)` は**検出しない** | `modelTables` による絞り込み |
| 16 | `$q = User::query(); if ($x) { $q = $other; } $q->whereKey($id)` が**検出される** | alias が再代入で取り消されない (fail-closed) |
| 17 | `$q = User::query(); $q = $other; $q->whereKey($id)` が**検出される** | 「再代入すれば黙る」回避を許さない |

### negative fixture (**検出してはならない**)

| fixture | 理由 |
|---|---|
| `$organization->users()->whereKey($id)` | relation 起点 |
| 型付き引数 `Project $project` の `Project::whereKey($project->id)->lockForUpdate()` | provenance 証明あり |
| `$manual->renderJobs()->where('id', '>', $cursor)` | 順序比較 (主キー同一性でない) |
| `Plan::query()->where('code', $code)` | 主キーでない |
| **docblock 中の `Foo::destroy()`** | コメント除去 (実在の誤検出例) |
| `DB::table('oauth_access_tokens')->where('id', $tokenId)` | `modelTables` 外 (Passport 内部) |
| `$q = $other->users(); $q->whereKey($id)` | 静的起点から代入されていない (relation 起点) |
| `SomeOtherPackage\User::find($id)` (Models 集合に無い) | import 裏取り |

### `outOfScope_*` fixture (「保証」ではなく「既知の範囲外」)

| fixture |
|---|
| `User::query()->whereRaw('id = ?', [$id])` |
| `User::query()->where($col, $id)` (動的列名) |

> 名前を `outOfScope_` 接頭辞にすることで、「検出しないことを保証している」と
> 誤読されないようにする (Codex Round 3 Warning)。範囲外の実コード出現は
> 施策 5 のテスト 11 が 0 件 assertion で検知する。

---

## 施策 7: 規約ドキュメントへの gate 名登録

### 変更箇所

| ファイル | 変更内容 |
|---|---|
| `AGENTS.md` セキュリティ不変条件 **3** | 末尾に `(ModelDirectFetchInvariantTest)` を追記 |
| `docs/app-integration-guide.md` §7 不変条件 **3** | 同上 + 「新規 route を足すときのチェックリスト」に 1 行追加 |
| `docs/architecture.md` L38 付近 | 既存の gate 列挙 (`ProjectRouteCurrentOrgGuardTest / NestedRouteIdorDefenseTest`) に併記 |

### 重要: 番号を振り直さない

AGENTS.md の注意書きどおり、**AGENTS.md §セキュリティ不変条件の番号と
`docs/app-integration-guide.md` §7 の番号は 1:1 対応しない**。
本施策は不変条件 3 (両者とも「cross-org 不可」) に**追記するだけ**で、
**どちらの側も renumber しない** (既存の相互参照が壊れるため)。

### 波及変更

- `docs/TODO.md`: **触らない** (登録は `app-todo-add` スキルの責務)
- `docs/template-divergence.md`: 変更不要 (テンプレートからの逸脱ではなく、テンプレート t1 への**追従**)

---

## 段階分け

### このタスクでやる

施策 1〜7 すべて。分割すると「enum だけあって gate が無い」中途半端な状態が main に入るため、
**1 コミット単位 (1 worktree) で完結させる**。

### 後続 TODO 候補 (このタスクではやらない)

1. **payload 由来 `user_id` 2 箇所の org 相対化 + `exists:users,id` の見直し**
   — 振る舞い変更 (403/404/422 の変化) を伴うため別 TODO。
   本タスクの `todoRef` field がこの TODO を指すので、**本タスクの実装前に起票**しておく。
2. **`whereRaw` / 動的列名の検出** — 現状 0 件のため作らない (施策 5 テスト 11 が見張る)。
3. **c2c 台帳への `status_reported` 書き戻し** — main マージ + push 後。
   `refs` は `aicue@<commit>` 形式が必須。

---

## リスク

| リスク | 影響 | 緩和 |
|---|---|---|
| **走査器の誤検出で無関係な箇所が候補化** | 実装者が意味の無い分類を強いられ inventory が形骸化 | import 裏取り + コメント除去 + 等価/IN 限定。施策 6 の negative fixture で固定 |
| **走査器の検出漏れで gate が静かに無力化** | 最悪の失敗モード | 施策 5 テスト 12 (degenerate PASS 防止) + 施策 6 の positive fixture 12 種 |
| **初期 inventory が想定より大幅に多い** | 実装コストが膨らむ | 見積り 33〜40。**50 件を大きく超えたら分類粒度を再検討**し、設計に戻ること (実装者への申し送り) |
| **provenance 証明器の実装が過度に複雑化** | 実装が終わらない | 証明手段を「型付き引数のみ」に絞る後退が可能。**fail-closed 側への後退なので安全** (候補が増えるだけ) |
| 債務 case の放置 | cross-org 存在オラクルが残り続ける | `todoRef` 必須 + 件数上限 2 (施策 5 テスト 10) |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規ファイル 6 本 + docs 追記のみで、既存コードの変更が無い。他 TODO と競合するのは `AGENTS.md` / `docs/app-integration-guide.md` の追記 3 行だけで、conflict しても解決は自明 |
| 競合リスク | 低。ただし**他タスクが `app/` に新しい直 fetch を足すと本タスクの inventory が不足して fail する**。main マージ時に走査器を再実行して差分を取り込むこと |

