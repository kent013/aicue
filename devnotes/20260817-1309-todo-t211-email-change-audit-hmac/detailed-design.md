# 詳細設計: メール変更の監査記録へ鍵つきハッシュ 2 値を載せる (aicue:T211)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合 (OJT を撮って形式化する tebiki) と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen (型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き (`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。`RefreshDatabase` は `tests/Pest.php` でグローバル適用済み
  (個別の `DatabaseTransactions` 使用禁止)、`--parallel` 実行
- テストデータは必ず Factory で生成する
- `declare(strict_types=1)` + 日本語コメント
- コードフォーマット: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 目的と台帳の根拠

家系の機能台帳 lctl の feature `auth-email-change-protection` に対する
**裁定 AG-195 (2026-08-16)** への追従である。裁定は「全リポジトリのメール変更の監査記録に、
変更前後のアドレスの鍵つきハッシュ (HMAC) 2 値を記録する。生アドレスと鍵なしハッシュは禁止。
正典形は aigenba の `app/Support/EmailHash.php` (HMAC(app.key)) と、記録側の
`old_email_hash` / `new_email_hash` の 2 値」と定める。専用の監査鍵への切り出しは
「任意の改善」として明示的に残されている。

台帳の `aicue` セルは `update_pending` で、追従の中身は
「メール変更の監査記録へ HMAC ハッシュ 2 値を追加する (正典形は aigenba の EmailHash)」の 1 点。
先行事例は laravel-claude-template:T129 (2026-08-17 に同じ 2 値を追加し、既存の `EmailHash` を
再利用して 2 本目の算出も専用監査鍵も作らなかった旨を台帳へ報告済み)。

台帳の ID 表記規律 (単一リポジトリでのみ意味が通る ID は `<repo>:ID` の形で書く) に従い、
本書でも `aicue:T211` / `laravel-claude-template:T129` / `aicue:T108` と書く。

## 概念設計リファレンス

`devnotes/20260817-1309-todo-t211-email-change-audit-hmac/conceptual-design.md`
(Codex 概念設計レビュー Round 1 = APPROVED。対応マトリクスは同ディレクトリの
`codex-history/conceptual-review-decisions-round-1.md`)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | メール変更の監査 metadata へ鍵つきハッシュ 2 値を載せる | `app/Actions/Fortify/UpdateUserProfileInformation.php` | High |
| 2 | 監査 metadata の契約テストを「null」から「2 値ちょうど・生アドレス不在」へ | `tests/Feature/Security/SecurityAuditTrailCoverageTest.php` | High |

## 変更ファイル一覧

| 区分 | パス | 内容 |
|---|---|---|
| 変更 | `app/Actions/Fortify/UpdateUserProfileInformation.php` | ハッシュ 2 値の算出 (保存の前) と `record()` への引き渡し。宣言コメントを裁定の文言へ言い換え |
| 変更 | `tests/Feature/Security/SecurityAuditTrailCoverageTest.php` | 既存の「metadata は null」を「2 値ちょうど」へ書き換え + 生の JSON に平文が現れないことの検査を 1 本追加 |
| 新規 | (なし) | — |
| 削除 | (なし) | — |

**新規ファイルは 1 つも作らない**。migration も作らない
(`security_audit_events.metadata` は既存の nullable な JSON 列)。

## 施策 1: 監査 metadata へ鍵つきハッシュ 2 値を載せる

### 変更箇所

- ファイル: `app/Actions/Fortify/UpdateUserProfileInformation.php` (L1-88 のうち L58-70 と import)

### 波及変更

- **TypeScript 型定義**: なし。監査 metadata は Inertia props にも API 応答にも載らない
  (実読確認: `HandleInertiaRequests` は `user.email` を渡すが監査行は渡さない)
- **API Resource / DTO**: なし。`SecurityEventRecorder::record()` の既存シグネチャ
  (`array<string, mixed> $metadata`) に載る値であり、新しい DTO を作らない
  (`AccountDeletionAuditContext` のような DTO を作るのは、複数の呼び出し元へ
  「渡し忘れ」を禁じたいときの形。本件は呼び出し元が 1 つで、値も同じ 1 行で算出される)
- **migration / モデル**: なし (列も casts も既存のまま)
- **Filament**: なし (`SecurityAuditEventResource` の一覧に metadata 列は無い。実読確認)
- **Architecture 目録**:
  - `tests/Architecture/SecurityEventCoverageTest.php` の `securityEventRecordingMap()` は
    `email_changed` を `caller = UpdateUserProfileInformation` /
    `covered_by = tests/Feature/Security/SecurityAuditTrailCoverageTest.php` と宣言済み。
    記録経路のクラスも担保テストのファイルも変えないため**1 行も変更しない**
    (検査 3 の「`SecurityEventType::EmailChanged` を名指ししていること」、検査 4 の
    「covered_by の中で当該 case を名指ししていること」はどちらも維持される)
  - `tests/Architecture/AccountDeletionPathGateTest.php` の閉包は起点 3 つ
    (`PurgeDeletionRequestsCommand::handle` / `AccountController::destroy` /
    `OrganizationMembershipService::deleteAccount`) から辿る。本 Action はどの起点からも
    到達しないため `DELETION_PATH_CLOSURE` は動かない (実読確認)
  - `tests/Support/Retention/RetentionTableRegistry.php` は**表単位**の分類なので動かない
    (列も表も増えないため)
- **docs**: 変更しない。`docs/` にメール変更保護の節は無く、監査 metadata の内容は
  記録場所のコメントと契約テストが正本である (2 か所に書くと必ず食い違う、という
  本リポジトリの既存方針に合わせる)

### 現行コード

```php
        $oldEmail = $user->email;

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
        ])->save();

        // 監査証跡。SecurityEventType::EmailChanged は enum に存在しながら記録経路が
        // 無かった (aicue:T108 S7 の SecurityEventCoverageTest が deny-by-default で検出)。
        // 通知 (検知導線) と監査ログ (事後追跡) は同じ事象の両輪なので同じ場所で記録する。
        // 平文 email は metadata に載せない (PII は CipherSweet 管理の users 側に閉じる)。
        $this->recorder->record(SecurityEventType::EmailChanged, $user);
```

### 変更後コード

```php
        $oldEmail = $user->email;

        // 監査 metadata 用の鍵つきハッシュは**保存の前**に算出する。EmailHash は
        // config('app.key') が文字列であることを要求するため、前提が崩れているなら
        // 不可逆な状態変更 (アドレスの書き換え・確認済みの解除・旧アドレスへの通知) が
        // 起きる前に落ちるほうが安全である。
        $auditMetadata = [
            'old_email_hash' => EmailHash::compute($oldEmail),
            'new_email_hash' => EmailHash::compute($email),
        ];

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
        ])->save();

        // 監査証跡。SecurityEventType::EmailChanged は enum に存在しながら記録経路が
        // 無かった (aicue:T108 S7 の SecurityEventCoverageTest が deny-by-default で検出)。
        // 通知 (検知導線) と監査ログ (事後追跡) は同じ事象の両輪なので同じ場所で記録する。
        //
        // metadata には**生アドレスと鍵なしの変換値は載せない** (家系の裁定 AG-195)。
        // 載せる 2 値は HMAC-SHA256 (鍵は app.key) で、鍵を持たない者には乱数、
        // 鍵保持者でも復元はできず、手元の候補アドレスとの一致確認にだけ使える。
        // 乗っ取り調査で「どのアドレスからどのアドレスへ変わったか」を追うための値である
        // (users.email は上書きされるため、旧アドレスは他のどこにも残らない)。
        // ★**観測専用**である。この 2 値で分岐する処理は 1 つも作らない。
        $this->recorder->record(SecurityEventType::EmailChanged, $user, $auditMetadata);
```

import に `use App\Support\EmailHash;` を 1 行足す (`App\Services\...` と `App\Support\...` の
アルファベット順の位置へ入れる。Pint が整える)。

### なぜ「保存の前」に算出するか

`EmailHash::compute()` は `config('app.key')` が文字列であることを `Assert` で要求する。
保存の後に算出すると、前提が崩れているとき「メールアドレスは書き換わり、確認済みも外れ、
旧アドレスへ通知も飛んだのに、監査行だけが無い」状態になる。算出を前へ置けば、
不可逆な変更のどれも起きないまま例外になる (fail-closed)。
`record()` 自体は best-effort (`try`/`catch` + `report()`) のままなので、
**DB への書き込み失敗**は従来どおり主処理を巻き込まない — 前へ出したのは
「値を作れるか」の判定だけである。

### 記録の窓口を `recordOrFail()` へ変えない理由

`AGENTS.md` ドメイン固有規約 16 が「失効以外に `recordOrFail()` を使わない
(監査の失敗でログインを落とすことになる)」と定めており、`SecurityEventRecorder` の
docblock も同じ制限を書いている。メール変更はその「失効」ではないため `record()` のままとする。

### PHPStan 適合チェック

- [x] `EmailHash::compute(string $email): string` に渡す `$oldEmail` / `$email` はいずれも
      `string` に解決される。`$email` は同メソッド内の `Assert::string($email)` で確定済み、
      `$oldEmail = $user->email` は larastan のモデル属性解決で `string` になる
      (同じ形の既存コードが level 10 を通っている — `MemberRowData::fromUser()` の
      `email: $user->email` は `string` 引数へ渡しており、
      `OrganizationMembershipService::pendingInvitationsQuery()` は `$user->email` を
      `activePendingForEmail(string)` へ渡している)
- [x] `record()` の第 3 引数は `array<string, mixed>`。渡すのは
      `array{old_email_hash: string, new_email_hash: string}` で適合する
- [x] 戻り値の型・null 安全性に変更なし (メソッドは `void` のまま)
- [x] 型を緩める変更を 1 つも行わない (禁止事項 2)。
      もし level 10 が `$user->email` を `mixed` と判定した場合は、
      **同ファイルで既に使っている `Assert::string($oldEmail)` で narrowing する**
      (`@phpstan-ignore` も型の widen も使わない)

### リスク

| リスク | 評価 |
|---|---|
| 監査行に、退会後も残る値が 2 つ増える | 裁定 AG-195 が受容済み。載るのは鍵つきハッシュのみで復元不能 |
| `APP_KEY` ローテートで前後の監査行を突合できない | `EmailHash` の docblock が既に宣言している既知制約。監査専用鍵は今回作らない (再評価の契機は概念設計に記載) |
| `metadata` が `null` であることに依存している箇所が壊れる | 実読で依存は**契約テストの 1 箇所だけ**と確認済み (施策 2 で同時に直す)。アプリ側に `email_changed` の metadata を読む処理は無い |
| 大文字小文字だけが違う入力で 2 値が一致する | 変更判定が正規化されていないための既知の性質。観測専用なので一致に意味を持たせない。正規化の是正は本件のスコープ外 (別 TODO 候補) |

## 施策 2: 契約テストの書き換えと追加

### 変更箇所

- ファイル: `tests/Feature/Security/SecurityAuditTrailCoverageTest.php`
  (既存テスト「メールアドレス変更が email_changed として記録される」の書き換え + 1 本追加)

### 現行コード

```php
test('メールアドレス変更が email_changed として記録される', function (): void {
    $user = User::factory()->create(['email' => 'before@example.com']);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->put('/user/profile-information', [
            'name' => '変更後の名前',
            'email' => 'after@example.com',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()?->email)->toBe('after@example.com');
    expect(auditTrailCount(SecurityEventType::EmailChanged))->toBe(1);

    // 平文 email を監査行に落とさない (PII は CipherSweet 管理の users 側に閉じる)
    $event = SecurityAuditEvent::query()
        ->where('event_type', 'email_changed')
        ->firstOrFail();
    expect($event->user_id)->toBe($user->id)
        ->and($event->metadata)->toBeNull();
});
```

### 変更後コード

```php
test('メールアドレス変更が email_changed として記録され metadata に鍵つきハッシュ 2 値が残る', function (): void {
    $user = User::factory()->create(['email' => 'before@example.com']);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->put('/user/profile-information', [
            'name' => '変更後の名前',
            'email' => 'after@example.com',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()?->email)->toBe('after@example.com');
    expect(auditTrailCount(SecurityEventType::EmailChanged))->toBe(1);

    // 家系の裁定 AG-195: 変更前後のアドレスの鍵つきハッシュ 2 値を残す
    // (生アドレスと鍵なしの変換値は載せない)。キー名は家系で共通。
    $event = SecurityAuditEvent::query()
        ->where('event_type', 'email_changed')
        ->firstOrFail();
    expect($event->user_id)->toBe($user->id)
        ->and($event->metadata)->toBe([
            'old_email_hash' => EmailHash::compute('before@example.com'),
            'new_email_hash' => EmailHash::compute('after@example.com'),
        ]);
});

test('email_changed の監査 metadata は鍵つきハッシュだけで生アドレスを含まない', function (): void {
    $user = User::factory()->create(['email' => 'before@example.com']);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->put('/user/profile-information', [
            'name' => '変更後の名前',
            'email' => 'after@example.com',
        ])
        ->assertSessionHasNoErrors();

    // ★モデルの cast を通した配列ではなく、**DB に保存された JSON 文字列**を見る
    //   (cast 越しでは「保存された文字列に平文が混ざっていないこと」を言えないため)。
    $raw = DB::table('security_audit_events')
        ->where('event_type', 'email_changed')
        ->where('user_id', $user->id)
        ->value('metadata');
    expect($raw)->toBeString();
    $json = (string) $raw;

    // 局所部・ドメインのいずれも現れない。3 語とも hex (0-9a-f) 以外の文字を含むため、
    // 64 桁のハッシュ値に偶然含まれることはない ('o' / 't' / 'x' が hex ではない)。
    expect($json)->not->toContain('before')
        ->and($json)->not->toContain('after')
        ->and($json)->not->toContain('example.com');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    // ★2 値が互いに異なることは**検査しない**。変更判定が正規化を挟まないため、
    //   大文字小文字だけが違う変更では 2 値が一致し得る (観測専用なので一致に意味を持たせない)。
    expect(array_keys($decoded))->toBe(['old_email_hash', 'new_email_hash']);
    expect($decoded['old_email_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($decoded['new_email_hash'])->toMatch('/^[0-9a-f]{64}$/');
});
```

import に `use App\Support\EmailHash;` と `use Illuminate\Support\Facades\DB;` を足す。

`DB::table(...)` を使う点は目録に触れない — 主キー同一性のクラス起点クエリを見る
`ModelDirectFetchInvariantTest` の走査根は `app/` と `routes/` であり
(`DirectFetchInventory::sourceRoots()` を実読)、テスト側のクエリは母集団に入らない。
またこのクエリは `event_type` 条件であって主キー同一性でもない。

### テストファースト計画 (どのテストを先に赤にするか)

| 手順 | 内容 | 期待する結果 |
|---|---|---|
| 1 | 施策 2 のテスト 2 本を**先に**書く (実装は触らない) | **赤**。1 本目は `metadata` が `null` で期待配列と一致せず失敗、2 本目は `$raw` が `null` (`toBeString` で失敗) する。この 2 通りの失敗メッセージを実行ログに残す |
| 2 | 施策 1 を実装する | 上の 2 本が緑になる |
| 3 | `composer test` の全レーンを回す | 既存テストの後退が無いことを確認する (とくに `tests/Architecture/SecurityEventCoverageTest.php` の検査 1〜5、`tests/Feature/Auth/EmailChangeTest.php`、`tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php`) |

**既存テストの削除・アサーションの緩和はしない**。書き換えるのは
「metadata が `null` である」という**裁定によって覆った 1 つの期待値だけ**であり、
同テストの他の期待 (件数 1 / `user_id` 一致 / セッションエラー無し / 新アドレスへの反映) は
そのまま残す。

## 受け入れ条件 (機械検証可能)

| # | 条件 | 検証方法 |
|---|---|---|
| A1 | `email_changed` の監査行の `metadata` が `['old_email_hash' => EmailHash::compute(旧), 'new_email_hash' => EmailHash::compute(新)]` と**キー順まで一致**する | `SecurityAuditTrailCoverageTest` の 1 本目 (`toBe` は配列の順序も見る) |
| A2 | DB に保存された JSON 文字列に `before` / `after` / `example.com` のいずれも現れない | 同 2 本目 (`DB::table(...)->value('metadata')` の生値) |
| A3 | metadata のキーは `old_email_hash` / `new_email_hash` のちょうど 2 つで、値はいずれも `/^[0-9a-f]{64}$/` に一致する。**2 値が互いに異なることは条件にしない** (正規化を挟まない変更判定のため一致し得るため) | 同 2 本目 |
| A4 | メール変更 1 回につき `email_changed` 行はちょうど 1 件 | 同 1 本目 (`auditTrailCount`) |
| A5 | 記録経路の目録が緑のまま (map と enum の完全一致 / caller の名指し / covered_by の名指し) | `tests/Architecture/SecurityEventCoverageTest.php` 検査 1〜5 |
| A6 | 旧アドレスへの通知・確認済みの解除・氏名のみ変更の素通しに後退が無い | `tests/Feature/Auth/EmailChangeTest.php` / `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php` |
| A7 | 型を緩めずに level 10 を通る。`$user->email` が `string` に解決されず引数型で落ちた場合は、**同ファイルで既に使っている `Assert::string($oldEmail)` で narrowing して通す** (`@phpstan-ignore` / 型の widen / baseline は使わない) | `composer phpstan` がエラー 0 |
| A8 | 下記の全検証コマンドが green | 実行ログ |

## 全検証コマンド (すべて green であること)

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

(`AGENTS.md` の検証コマンド節と同一。フロント側は本変更で 1 行も動かないが、
規約どおり全数を回して green を確認する)

## 保証しないもの / やらないと決めたこと

| 事項 | 理由 |
|---|---|
| **ハッシュからアドレスを復元すること** | HMAC は一方向。鍵保持者でも復元できず、手元の候補アドレスとの一致確認にしか使えない (裁定の前提そのもの) |
| **`APP_KEY` ローテートをまたいだ突合** | 鍵が変われば同じアドレスでも別の値になる。`EmailHash` の docblock が宣言済みの制約で、監査鍵を分けない以上そのまま引き継ぐ |
| **裁定より前に記録された `email_changed` 行への遡及付与** | 監査表は追記専用。過去行を後から書き換えるのは監査証跡の性質に反する。したがって**古い行は 2 値を持たない**と読める状態になる |
| **監査行が必ず書かれること** | `record()` は best-effort のまま (`recordOrFail()` にしない)。監査の失敗でメール変更を落とさない、という既存の意味を変えない |
| **メール変更の流量制限 / 変更後の再認証の鮮度失効** | 家系の他リポジトリが持つ上積みだが、裁定 AG-195 の対象ではない。今必要なものだけ作る |
| **変更判定の正規化** | 大文字小文字だけの違いも「変更」として扱う現行の挙動は変えない。影響先は監査ではなく「確認済みの解除」と「旧アドレスへの通知」であり、混ぜると受け入れ条件が 2 系統になる。**帰結として 2 値が一致する監査行が生まれうる**が、観測専用なのでこの一致で分岐しない |
| **監査専用鍵 (`app.key` と別鍵) への切り出し** | 裁定が「任意の改善」と位置づけている。再評価の契機は「`APP_KEY` のローテーション運用が具体化したとき」 |
| **画面・通知・API への 2 値の露出** | 読み手は調査者であり、`Filament` の一覧に metadata 列は無い。露出面を増やさない |
| **他の `SecurityEventType` の metadata の見直し** | 本件は `email_changed` の 1 種別だけを扱う |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は 2 ファイル・実質 10 行程度で、記録場所も担保テストも他の施策と共有していない。migration もフロントの変更も無いため、他作業と並行しても衝突しにくい |
| 競合リスク | `UpdateUserProfileInformation` に触る他の作業 (変更判定の正規化を扱う TODO が将来立つ場合) とは同一メソッドを編集するため衝突しうる。その TODO が Open になっている間は順序をつける |
