【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (larastan。strict-rules / phpstan-webmozart-assert は未導入)
- Pest テストフレームワーク (RefreshDatabase はグローバル適用、--parallel 実行)
- DTO + JsonResource パターン
- Laratrust RBAC (Organization → Team → Project 階層)
- users.email は CipherSweet で暗号化 + blind index (平文 where は hit しない)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert 使用）
4. テスト計画の網羅性（各施策に Pest テスト。テストファーストの赤が本当に赤になるか）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、API Resource、テスト、Architecture 目録が変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、監査証跡の性質、PII の扱い）
10. 受け入れ条件が機械検証可能な形になっているか
11. 「保証しないもの」の宣言が実態と合っているか（誇張・過小がないか）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【補足】
本リポジトリ (aicue) は複数リポジトリで共有する機能台帳 (lctl) の追従先の 1 つで、
本件は台帳の裁定 AG-195 (2026-08-16) への追従である。裁定そのものへの賛否ではなく、
この追従設計として妥当かをレビューしてほしい。

---

## 詳細設計書

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
    expect(array_keys($decoded))->toBe(['old_email_hash', 'new_email_hash']);
    expect($decoded['old_email_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($decoded['new_email_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($decoded['old_email_hash'])->not->toBe($decoded['new_email_hash']);
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
| A3 | metadata のキーはちょうど 2 つで、値はいずれも `/^[0-9a-f]{64}$/` に一致し、互いに異なる | 同 2 本目 |
| A4 | メール変更 1 回につき `email_changed` 行はちょうど 1 件 | 同 1 本目 (`auditTrailCount`) |
| A5 | 記録経路の目録が緑のまま (map と enum の完全一致 / caller の名指し / covered_by の名指し) | `tests/Architecture/SecurityEventCoverageTest.php` 検査 1〜5 |
| A6 | 旧アドレスへの通知・確認済みの解除・氏名のみ変更の素通しに後退が無い | `tests/Feature/Auth/EmailChangeTest.php` / `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php` |
| A7 | 型を緩めずに level 10 を通る | `composer phpstan` がエラー 0 |
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


---

## 参考: 概念設計 (APPROVED 済み)

# 概念設計: メール変更の監査記録へ鍵つきハッシュ 2 値を載せる (aicue:T211)

## 目的と台帳の根拠

家系の機能台帳 lctl の feature `auth-email-change-protection` に対する
**裁定 AG-195 (2026-08-16)** への追従である。

裁定の本文 (台帳より):

> 家系統一で「載せる」。全リポジトリのメール変更の監査記録に、変更前後のアドレスの
> 鍵つきハッシュ (HMAC) 2 値を記録する。生アドレスと鍵なしハッシュは禁止。
> 正典形は aigenba の実装 (`app/Support/EmailHash.php` の HMAC(app.key) と、
> 記録側の `old_email_hash` / `new_email_hash` の 2 値)。専用鍵 (app.key と別の監査用鍵) への
> 切り出しは任意の改善として残す。

台帳における本リポジトリの状態は `aicue: update_pending` で、追従の中身は
「メール変更の監査記録へ HMAC ハッシュ 2 値を追加する (正典形は aigenba の EmailHash)」の
1 点だけである (旧アドレスへの通知・監査記録の存在・変更前の再認証という他の物差しは
充足済みのまま)。

先行して追従を終えた実装が家系に 1 本ある — laravel-claude-template:T129 が
2026-08-17 に同じ 2 値 (`old_email_hash` / `new_email_hash`) を追加し、既存の
`EmailHash` を再利用して 2 本目の算出も専用監査鍵も作らなかったことを台帳へ報告している。
本設計はその形に揃える。

なお、台帳へ書くときに単一リポジトリでのみ意味が通る ID
(T 番号など) は `<repo>:ID` の形にする規律があるため、本設計でも
`aicue:T211` / `laravel-claude-template:T129` のように書く。

## 背景・課題

`aicue` のメール変更の監査行は現在 metadata を 1 つも持たない (`null`)。
実装 (`app/Actions/Fortify/UpdateUserProfileInformation.php`) は

```php
$this->recorder->record(SecurityEventType::EmailChanged, $user);
```

と種別と利用者だけを記録し、契約テスト
(`tests/Feature/Security/SecurityAuditTrailCoverageTest.php`) が
`metadata` が `null` であることを固定している。

このため監査行から分かるのは「この利用者のメールアドレスが、この時刻に、この接続元から
変わった」ことまでで、**どのアドレスからどのアドレスへ変わったか**は残らない。
`users.email` は CipherSweet で暗号化されているが**上書きされる**ので、旧アドレスは
2 回目の変更や退会の後には復元できない。乗っ取りの調査で最も知りたい
「攻撃者がどの宛先へ書き換えたか」「本人が使っていた宛先はどれだったか」が
事後には追えない状態である。

一方で「監査行に個人情報を残さない」という要求も同時にある。監査表は追記専用で、
一度書いた値は前進のみの移行でしか消せない。裁定 AG-195 はこの交換関係を
**鍵つきハッシュ (HMAC) なら載せてよい**と決着させた — 鍵を持たない者には乱数であり、
鍵保持者でも復元はできず、手元の候補アドレスとの一致を確かめられるだけだからである。

## 改善アイデア

メール変更を記録する 1 箇所で、変更前後のアドレスの HMAC 値 2 つを監査 metadata に載せる。

- キー名は正典形と同じ `old_email_hash` / `new_email_hash` (家系で照合できるようにする)
- 値は既存の `App\Support\EmailHash::compute()` (HMAC-SHA256 / 鍵は `app.key`) の戻り値
- 平文アドレスは metadata に載せない (裁定の「生アドレスと鍵なしハッシュは禁止」を守る)
- **観測専用**である。この 2 値で分岐する処理は 1 つも作らない

## 既存資産の再利用可否 (実読で確認した)

`AGENTS.md` ドメイン固有規約 5 が定める流量制限のキー規約のために、本リポジトリには
既に `EmailNormalizer` → `EmailHash` の 2 クラスがある。実読した結果は次のとおりで、
**そのまま再利用できる**。

| クラス | 実体 | 判断 |
|---|---|---|
| `app/Support/EmailHash.php` | `hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'))` を返す。docblock に「単純 sha256 は辞書攻撃に弱いため HMAC を使う」「APP_KEY ローテーション時は前後の hash を突合できない」と明記済み | **再利用する**。正典形 (aigenba の `EmailHash`) と同じ HMAC(app.key) であり、2 本目の算出を作る理由が無い |
| `app/Support/EmailNormalizer.php` | `trim` + 小文字化のみ。`Str::transliterate()` は使わない旨を docblock が明記 | **呼び出さない**。`EmailHash` が内部で同値の正規化 (`mb_strtolower(trim())`) を再適用しており、呼び出し側で二重に掛ける意味が無い |

稼働実績も確認した — `EmailHash` は流量制限のキー生成
(`FortifyServiceProvider` / `AppServiceProvider`) と配信抑制の照合
(`Services/Mail/EmailSuppressionService`) で既に本番経路に乗っており、
`tests/Unit/Support/EmailHashTest.php` が hex 64 桁・正規化の収束・鍵つきであることを
固定している。

## 期待効果

- **使命への貢献**: 本アプリは現場の作業手順書と、そこから作った動画マニュアルという
  組織の資産を預かる。登録メールアドレスはパスワード再設定の宛先なので、ここを
  静かに書き換えられるとアカウントごと資産を奪える。奪われた後に
  「どの宛先へ移されたか」を追える状態にしておくことは、被害の把握と復旧の前提になる。
- **具体的な改善**: 乗っ取り調査で、手元の候補アドレス (通報された宛先・別の記録に
  残るアドレス) と監査行のハッシュを突き合わせられるようになる。退会後も監査行は
  残る (`user_id` は `nullOnDelete`) ため、退会を挟んだ追跡もできる。
- **家系との整合**: 台帳の `aicue` セルが `update_pending` → 追従済みへ進む。
  同じキー名で家系 6 リポジトリの監査行を同じ問いで読めるようになる。

## 実装方針 (概要)

変更は記録している 1 箇所だけである。

1. `UpdateUserProfileInformation::update()` で、**保存 (`forceFill()->save()`) の前に**
   旧アドレス・新アドレスの HMAC を算出する。
   算出を先に置く理由は、`EmailHash::compute()` が `config('app.key')` が文字列であることを
   `Assert` で要求しており、前提違反で例外になるなら**不可逆な状態変更 (メールアドレスの
   書き換え・確認済みの解除・旧アドレスへの通知) の前**に落ちるべきだからである。
2. 算出した 2 値を `record()` の metadata として渡す。
3. 実装のコメントを裁定の文言へ揃える — 現行の「平文 email は metadata に載せない」を
   「**生アドレスと鍵なしの変換値は載せない**」へ言い換え、載せる 2 値が
   観測専用であることを明記する。
4. 契約テストを「metadata が `null`」から「2 値ちょうどで、値が `EmailHash::compute()` と
   一致し、保存された JSON に平文アドレスが現れない」へ書き換える。

記録の窓口は `SecurityEventRecorder::record()` (best-effort) のままにする。
`recordOrFail()` へ変えない — `AGENTS.md` ドメイン固有規約 16 が
「失効以外に `recordOrFail()` を使わない (監査の失敗でログインを落とすことになる)」と
定めており、メール変更はその「失効」ではない。

## 制約・前提

- **観測専用の規律**: 2 値で分岐する処理を作らない。先例は退会の監査 metadata
  (`AccountDeletionAuditContext`) で、`docs/architecture.md` が
  「これは観測であって防御ではない」と明記している。本件も同じ扱いにする。
- **平文を載せない**: metadata に入るのは 64 桁の hex 2 本だけである。
- **記録経路の目録**: `tests/Architecture/SecurityEventCoverageTest.php` の
  `securityEventRecordingMap()` は `email_changed` を
  `caller = UpdateUserProfileInformation` / `covered_by =
  tests/Feature/Security/SecurityAuditTrailCoverageTest.php` として既に宣言している。
  記録経路のクラスも担保テストのファイルも変えないので、**目録は 1 行も動かない**。
- **表も列も増やさない**: `security_audit_events.metadata` は既存の nullable な JSON 列で、
  migration は不要である。保持期限の分類 (`RetentionTableRegistry`) も表単位なので動かない。
- **監査表は追記専用**: 裁定より前に記録された `email_changed` 行に 2 値は付かない。
  遡及付与はしない。

## スコープ外 (今回やらないこと)

| やらないこと | 理由 |
|---|---|
| 監査専用鍵 (`app.key` とは別の鍵) への切り出し | 裁定が「任意の改善として残す」と明記している。鍵を増やすと本番の必須環境変数と起動時検査 (`ProductionEnvGuard`) が増える。今必要なものだけ作る。**再評価の契機**は「`APP_KEY` のローテーション運用が具体化したとき」である (ローテートすると前後の監査行を突合できなくなるため、そのときに鍵の寿命を分けるかを決める。Codex 概念設計レビュー Round 1 の指摘への対応) |
| 変更判定の正規化 (大文字小文字だけの違いを「変更なし」と見る) | 台帳の 2026-08-16 の所見が指摘する別件で、影響先は監査ではなく「確認済みの解除」と「旧アドレスへの通知」である。別 TODO として起票するのが筋で、混ぜると受け入れ条件が 2 系統になる |
| メール変更の流量制限・変更後の再認証の鮮度失効 | 家系の他リポジトリが持つ上積みだが、裁定 AG-195 の対象ではない |
| 既存の `email_changed` 行への遡及付与 | 監査表は追記専用。過去の行に後から値を書くのは監査証跡の性質に反する |
| `EmailHash` を `EmailNormalizer` 経由に書き換える | 現行の内部再適用と結果が同値であり、流量制限のキー生成という稼働中の経路を触る理由が無い |
| 通知メール本文・画面への 2 値の表示 | 監査行の読み手は調査者であり、`Filament` の一覧は metadata 列を表示していない。露出面を増やさない |

## 保証しないもの (誇張しない)

- **復元はできない**。ハッシュは候補アドレスとの一致確認にしか使えない (鍵保持者でも同じ)。
- **`APP_KEY` をローテートすると前後の値を突合できない** (`EmailHash` の docblock が既に
  宣言している制約)。監査鍵を分けない以上、この制約は監査行にも及ぶ。
- **監査行が書けなくてもメール変更は成立する** (`record()` は best-effort)。本設計は
  この既存の意味を変えない。
- **変更判定が正規化されていないため、大文字小文字だけが違う入力も「変更」として記録され、
  そのとき 2 値は一致する**。一致すること自体に意味を持たせない (観測専用)。
- 平文アドレスの露出面 (旧アドレスへの通知メール・`users` 表) は本変更で増えも減りもしない。


---

## 関連する現行コード

### app/Actions/Fortify/UpdateUserProfileInformation.php

```php
<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Notifications\EmailChangedSecurityNotification;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Webmozart\Assert\Assert;

/**
 * プロフィール (name / email) 更新。
 *
 * メール変更時 (Q11 決定):
 * - 旧アドレスへセキュリティ通知を送る (新アドレスは旧保持者に非開示。乗っ取り検知導線)
 * - email_verified_at を null 化して新アドレスの再検証を要求する
 * - email の一意性は whereBlind で明示チェック (暗号化カラムのため unique rule 不可)
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ])->validateWithBag('updateProfileInformation');

        $name = $validated['name'];
        $email = $validated['email'];
        Assert::string($name);
        Assert::string($email);

        if ($email === $user->email) {
            $user->forceFill(['name' => $name])->save();

            return;
        }

        if ($this->emailTakenByOther($email, $user)) {
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには変更できません。'],
            ])->errorBag('updateProfileInformation');
        }

        $oldEmail = $user->email;

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
        ])->save();

        // 監査証跡。SecurityEventType::EmailChanged は enum に存在しながら記録経路が
        // 無かった (T108 S7 の SecurityEventCoverageTest が deny-by-default で検出)。
        // 通知 (検知導線) と監査ログ (事後追跡) は同じ事象の両輪なので同じ場所で記録する。
        // 平文 email は metadata に載せない (PII は CipherSweet 管理の users 側に閉じる)。
        $this->recorder->record(SecurityEventType::EmailChanged, $user);

        // 旧アドレスへの on-demand セキュリティ通知 (アカウントを持たない宛先にも送れる経路)
        Notification::route('mail', $oldEmail)
            ->notify(new EmailChangedSecurityNotification);

        $user->sendEmailVerificationNotification();
    }

    /**
     * @phpstan-impure
     */
    private function emailTakenByOther(string $email, User $user): bool
    {
        return User::whereBlind('email', 'email_index', $email)
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}

```

### app/Support/EmailHash.php

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Webmozart\Assert\Assert;

/**
 * email の keyed hash (HMAC-SHA256) 算出 helper。
 *
 * 単純 sha256 は辞書攻撃に弱いため、ログ・補助検索用には HMAC(app.key) で keyed hash を作る。
 * 平文 email をログに出さないための識別子として使う。
 *
 * 正規化の正本は EmailNormalizer である。本クラス内の mb_strtolower(trim(...)) は
 * 呼び出し漏れに対する防御的な再適用であり、canonical 化の定義を持つものではない。
 *
 * 制約: APP_KEY ローテーション時、前後の hash は突合不可になる。
 */
final class EmailHash
{
    public static function compute(string $email): string
    {
        $key = config('app.key');
        Assert::string($key);

        return hash_hmac('sha256', mb_strtolower(trim($email)), $key);
    }
}

```

### app/Services/Security/SecurityEventRecorder.php

```php
<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Services\OAuth\OrganizationAccessRevoker;

/**
 * security_audit_events への記録の唯一の窓口。
 *
 * 既定 ({@see record()}) は best-effort で、記録の失敗が主処理を巻き込まない。
 * 失効の監査だけは握り潰さない版 ({@see recordOrFail()}) を使う。
 */
class SecurityEventRecorder
{
    /**
     * 監査記録 (best-effort)。**既存の意味は変えない** — 記録の失敗で主処理を巻き込まない。
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(SecurityEventType $type, ?User $user, array $metadata = []): void
    {
        try {
            $this->write($type, $user, $metadata);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * 監査記録 (握り潰さない)。**書けなければ呼び出し元のトランザクションごと巻き戻る**。
     *
     * 「資格情報は失効したが、その事実が監査に残っていない」状態を作らないための版である。
     * 組織アクセスの失効 ({@see OrganizationAccessRevoker}) だけがこれを使う。
     * 認証系の記録 (ログイン失敗など) にこれを使ってはならない —
     * 監査の失敗でログインそのものを落とすことになるためである。
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordOrFail(SecurityEventType $type, ?User $user, array $metadata = []): void
    {
        $this->write($type, $user, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function write(SecurityEventType $type, ?User $user, array $metadata): void
    {
        $event = new SecurityAuditEvent([
            'event_type' => $type->value,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => request()->ip(),
            'occurred_at' => now(),
        ]);
        if ($user !== null) {
            $event->user()->associate($user);
        }
        $event->save();
    }
}

```

### tests/Architecture/SecurityEventCoverageTest.php (抜粋: email_changed の宣言と検査 3/4)

```php
/**
 * SecurityEventType の値 => 記録経路の宣言。
 *
 * `event`  : 購読するイベントクラス (RecordSecurityEvent が listener を張る)
 * `caller` : 直接 SecurityEventRecorder を呼ぶクラス
 * `covered_by` : その event_type が記録されることを担保するテストファイル
 *
 * @return array<string, array{event?: class-string, caller?: class-string, covered_by: string}>
 */
function securityEventRecordingMap(): array
{
    return [
        SecurityEventType::Login->value => [
            'event' => Login::class,
            'covered_by' => 'tests/Feature/Auth/AuthenticationTest.php',
        ],
        SecurityEventType::LoginFailed->value => [
            'event' => Failed::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::Logout->value => [
            'event' => Logout::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::PasswordReset->value => [
            'event' => PasswordReset::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        // T107 で users.password 確定の単一窓口が PasswordCredentialService に統合された
        // (変更 = PasswordChanged / 初回設定 = PasswordSet)
        SecurityEventType::PasswordChanged->value => [
            'caller' => PasswordCredentialService::class,
            'covered_by' => 'tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php',
        ],
        SecurityEventType::PasswordSet->value => [
            'caller' => PasswordCredentialService::class,
            'covered_by' => 'tests/Feature/Settings/PasswordSetupTest.php',
        ],
        SecurityEventType::TwoFactorEnabled->value => [
            'event' => TwoFactorAuthenticationConfirmed::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::TwoFactorDisabled->value => [
            'event' => TwoFactorAuthenticationDisabled::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::EmailChanged->value => [
            'caller' => UpdateUserProfileInformation::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::AccountDeleted->value => [
            'caller' => OrganizationMembershipService::class,
            'covered_by' => 'tests/Feature/Auth/AccountDeletionTest.php',
        ],
        SecurityEventType::AccountDeletionRequested->value => [
            'caller' => OrganizationMembershipService::class,
            'covered_by' => 'tests/Feature/Auth/AccountDeletionGraceTest.php',
        ],
        SecurityEventType::AccountDeletionCancelled->value => [
            'caller' => OrganizationMembershipService::class,
            'covered_by' => 'tests/Feature/Auth/AccountDeletionGraceTest.php',
        ],
        SecurityEventType::SocialAccountLinked->value => [
            'caller' => SocialAccountService::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::OwnershipTransferred->value => [
            'caller' => OrganizationMembershipService::class,
            'covered_by' => 'tests/Feature/Organization/OwnershipTransferTest.php',
        ],
        SecurityEventType::ApiKeyIssued->value => [
            'caller' => OrganizationApiKeyController::class,
            'covered_by' => 'tests/Feature/Api/ApiKeyTest.php',
        ],
        SecurityEventType::ApiKeyRevoked->value => [
            'caller' => OrganizationApiKeyController::class,
            'covered_by' => 'tests/Feature/Api/ApiKeyTest.php',
        ],
        SecurityEventType::AdminMfaReset->value => [
            'caller' => ResetAdminMfaCommand::class,
            'covered_by' => 'tests/Feature/Console/ResetAdminMfaCommandTest.php',
        ],
        SecurityEventType::OrgMemberTwoFactorReset->value => [
            'caller' => OrganizationMemberController::class,
            'covered_by' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
        ],
        SecurityEventType::PasskeyRegistered->value => [
            'event' => PasskeyRegistered::class,
            'covered_by' => 'tests/Feature/Auth/PasskeyAuditTrailTest.php',
        ],
        SecurityEventType::PasskeyDeleted->value => [
            'event' => PasskeyDeleted::class,
            'covered_by' => 'tests/Feature/Auth/PasskeyAuditTrailTest.php',
        ],
        SecurityEventType::OrganizationAccessRevoked->value => [
            'caller' => OrganizationAccessRevoker::class,
            'covered_by' => 'tests/Feature/Organizations/OrganizationAccessRevocationTest.php',
        ],
    ];
}
```
