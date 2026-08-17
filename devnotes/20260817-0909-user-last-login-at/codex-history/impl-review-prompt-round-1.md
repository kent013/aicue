# アプリの使命

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# ## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel 12 + Svelte 5 (Inertia) アプリのコードレビュアーである。
TODO T203「最終ログイン日時の記録と表示」の実装差分をレビューせよ。

## レビュー観点
1. 詳細設計との一致性 (逸脱があるなら、その逸脱が正当か)
2. 正確性 (境界条件・null・タイムゾーン・cross-org 混入・N+1)
3. PHPStan level 10 適合性 (型を緩めて黙らせていないか)
4. DTO / JsonResource パターン (response()->json() の直書きが無いか)
5. テスト網羅性 (主張とテストが 1:1 か。テストデータは Factory か。テストの骨抜きが無いか)
6. セキュリティ (テナント境界・PII・認可)
7. **DESIGN.md 準拠**: DESIGN.md が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (#RRGGBB) を増やしていないか。token 値を変更する diff は resources/css/tokens.css と同一 diff 内で同期しているか
8. **Atomic Design 準拠**: resources/js/components/ は atoms/molecules/organisms/templates の責務分離に従う。階層を逆流していないか。アイコンは Lucide を使い SVG 直書きを増やしていないか

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に「## 全体判定: APPROVED」または「## 全体判定: CHANGES_REQUESTED」を必ず 1 行で書く

---

## 詳細設計書

# 詳細設計: user-last-login-at (最終ログイン日時の記録と表示)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応する Factory の作成も施策に含める**（本設計では施策 E）
- **DTO + JsonResource** パターン（AGENTS.md 参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex conceptual-review Round 1 で **APPROVED**）
- 対応マトリクス: [codex-history/conceptual-review-decisions-round-1.md](./codex-history/conceptual-review-decisions-round-1.md)

**概念設計の中心判断（本詳細設計はこれを前提にする）**:
`users.last_login_at` **カラムを新設しない**。`security_audit_events` の `event_type='login'` 行
（`RecordSecurityEvent` が既に `Illuminate\Auth\Events\Login` を購読して書いている）から導出する。
**記録経路を 1 本も新設しない / 列を 1 本も足さない / 変更系 route を 1 本も足さない。**

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 最終ログインの一括取得サービス | `app/Services/Security/LastLoginLookup.php`（新規） | High |
| B | Inertia props への追加 | `app/DataTransferObjects/Admin/MemberRowData.php` / `app/Http/Controllers/Admin/UserManagementController.php` | High |
| C | TS 型と画面表示 | `resources/js/types/admin.ts` / `resources/js/pages/Admin/Users.svelte` | High |
| D | 監査表の索引の置き換え | `database/migrations/2026_08_17_000100_replace_security_audit_events_user_event_index.php`（新規。**列は足さない**） | Medium |
| E | `SecurityAuditEventFactory` の新設 | `database/factories/SecurityAuditEventFactory.php`（新規） / `app/Models/SecurityAuditEvent.php` / `docs/factories.md` | High |
| F | 保持期間台帳への依存の明記 | `tests/Support/Retention/RetentionTableRegistry.php`（**区分は変えない**） | Medium |
| G | テスト | `tests/Feature/Admin/UserManagementPageTest.php` / `tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php`（新規） / `tests/js/pages/AdminUsers.test.ts` | High |

### 波及変更の全体像（インターフェース変更の影響）

`MemberRowData` は Inertia props の構造体であり、TS 側 `MemberRow` と**対で保守する**契約が
両ファイルの doc comment に明記されている。フィールドを 1 つ足すと以下が連動する。

| 層 | 影響 | 施策 |
|---|---|---|
| TypeScript 型定義 | `resources/js/types/admin.ts` の `MemberRow` に `lastLoginAt` | C |
| Inertia Props | `Admin/Users.svelte` の `members` 経由（Props interface 自体は `MemberRow[]` のままで変更不要） | C |
| API Resource / DTO | `MemberRowData`（Inertia 専用 DTO。JsonResource は経由しない = API 露出なし） | B |
| テストファイル (PHP) | `UserManagementPageTest`（既存の shape assertion に列が増える） | G |
| テストファイル (TS) | `AdminUsers.test.ts` の `membersFixture` **4 行すべて**に `lastLoginAt` を足す（型必須なので足さないと `pnpm typecheck` が落ちる） | G |
| Browser lane | **影響なし**（`/manage/users` の Browser テストは存在しない）。ただし **Vitest 用の DOM 契約は 1 つ増える**（`data-testid="member-last-login-{id}"`） | C / G |
| Filament | **影響なし**（`SecurityAuditEventResource` の表示は変えない） | — |

---

## 数える経路の確定（全施策の前提。概念設計 §3 の実装版）

導出元は `security_audit_events` の `login` 行 = `Illuminate\Auth\Events\Login` の発火である。
その発火集合を実コードで確定した（推測ではなく `SessionGuard` と各呼び出し元の実読）。
**各行に対応する検査を施策 G に持たせる**（主張とテストを 1:1 にする）。

| 経路 | `Login` 発火 | 数えるか | 根拠 | 検査 |
|---|---|---|---|---|
| パスワード（Fortify） | する | **数える** | `SessionGuard::login()` → `fireLoginEvent()` (L573) | G-1 テスト 6 |
| 2FA チャレンジ完了 | する | **数える** | 2FA 完了時に初めて `login()` が呼ばれる | G-1 テスト 9 の対照 |
| 2FA 待ちで止まった状態 | **しない** | **数えない** | セッションが確立していない = まだ入っていない | G-1 テスト 9 |
| パスキー（WebAuthn） | する | **数える** | `PasskeyLoginController::store()` の `$guard->login()` | 既存の passkey ログインテストが行を作る（追加検査は置かない） |
| SSO（Socialite） | する | **数える** | `SocialAuthController` L111 / L139 の `Auth::login(..., remember: true)` | 同上 |
| **remember me による自動復元** | する（`viaRemember=true`） | **数える** | `SessionGuard::user()` L197-202 が `fireLoginEvent($user, true)` を呼ぶ | G-1 テスト 7 |
| 新規登録直後の自動ログイン | する | **数える** | Fortify の登録後 login | G-1 テスト 11 |
| **招待受諾** | **受諾そのものは発火しない** | **前段の登録ログインとして数える** | `InvitationAcceptanceController::show` は未ログインなら token を session へ退避して `register` へ誘導し、`store`（受諾）は **auth 必須**。よって受諾の瞬間に `Login` は無く、その前段の登録の自動ログインが記録される。結果として「参加した時刻」が最終ログインになり、意図どおりである | G-1 テスト 11 |
| local 限定デバッグログイン | する | 数える（実害なし） | `DebugLoginController` は `LocalOnly` middleware の背後 = production に存在しない | 検査を置かない |
| **Filament 管理画面（`admin` guard）** | する | **数えない** | `$event->user` は `App\Models\AdminUser`。これは `Illuminate\Foundation\Auth\User` を直接継承する**別クラス**で `App\Models\User` の派生ではない。`RecordSecurityEvent::asUser()` が `null` に丸めるため `user_id` が付かず、利用者の行には**構造的に**混ざらない | G-1 テスト 10 |
| API キー（`api-key` guard） | しない | **数えない** | 機械アクセスであって人が入った事実ではない。`ApiKeyGuard` は `Login` を発火しない（実読で確認）。活動は `api_keys.last_used_at` が別に持つ | 検査を置かない（発火しないものは検査できない） |
| OAuth トークン（`mcp-oauth` / `api-oauth`） | しない | **数えない** | 同上。`oauth_sessions.last_used_at` が別に持つ | 同上 |
| セッション継続中の通常リクエスト | しない | **数えない** | 「最終ログイン」はセッション確立の時刻であり最終活動時刻ではない | G-2（クエリ数）が間接的に守る |
| `logout` / `login_failed` / その他の種別 | — | **数えない** | 種別が `login` 以外 | G-1 テスト 4 |

### remember me を数える判断（既存 listener と意図的に違える）

`app/Listeners/Auth/StampRecentAuthOnLogin.php` は `viaRemember()` が true の Login を**除外**する。
本設計は**除外しない**。問いが違うからである。

- あちらの問い: 「**たった今、資格情報を提示したか**」（機微操作の step-up を免除してよいか）。
  cookie の自動復元は資格情報の提示ではないので除外が正しい。
- こちらの問い: 「**この人は最後にいつこのシステムに入ったか**」（休眠の検出）。
  cookie で自動的に入ったのも「入った」である。除外すると
  「毎日使っているのに半年前から未ログインに見える人」が生まれ、機能の名前が果たすべき役割を裏切る。

**同じ `Login` イベントを 2 つの機能が別の条件で読む**。統合してはいけない 2 概念である（思考原則 4）。

### 「最終ログイン」であって「最終活動」ではない

remember me を使う利用者は cookie の寿命の間ログイン行が増えないため、値は最終活動より古くなりうる。
**これは仕様である**（doc/02 §2.4 の項目名は `最終ログイン日時`）。最終活動を出す機能は作らない。

---

## 施策 A: 最終ログインの一括取得サービス

### 変更箇所

- 新規ファイル: `app/Services/Security/LastLoginLookup.php`
- 置き場所の根拠: `app/Services/Security/` は既に `SecurityEventRecorder`（**書き込みの唯一の窓口**）を持つ。
  本クラスはその表の**読み取り**であり、同じ名前空間に置くのが自然。
  `SecurityEventRecorder` に読み取りメソッドを生やさない（窓口の責務は「記録」であり、
  読み取りを混ぜると窓口の意味が薄まる。思考原則 4）。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし（本クラスは DTO を返さず `array<int, CarbonImmutable>` の写像を返す。§型の根拠を参照）
- テストファイル: `tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php`（新規。施策 G）

### 現行コード

存在しない（新規）。参考にする既存の「1 クエリで写像を作る」流儀は
`UserManagementController::index` の `$pivotRoles` 構築（L42-53）。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 「この利用者は最後にいつこのシステムに入ったか」の読み取り。
 *
 * **記録点を増やさない**: 出所は security_audit_events の `login` 行だけである
 * (書き込みの窓口は SecurityEventRecorder。本クラスは読み取り専用で 1 行も書かない)。
 * users に last_login_at 列を持たない理由は
 * devnotes/20260817-0909-user-last-login-at/conceptual-design.md §2 が正本。
 *
 * **数える対象**: `Illuminate\Auth\Events\Login` が発火したセッション確立すべて
 * (パスワード / 2FA 完了 / パスキー / SSO / remember me による自動復元 / 登録直後)。
 * remember me を**除外しない**ことが App\Listeners\Auth\StampRecentAuthOnLogin との
 * 意図的な差である (あちらの問いは「たった今資格情報を提示したか」で、本クラスの問いは
 * 「最後に入ったのはいつか」。同じイベントを別条件で読む 2 概念であり統合しない)。
 * 機械アクセス (API キー / OAuth トークン) は Login を発火しないため構造的に入らない。
 *
 * ⚠ **前提 1**: users プロバイダを持つセッション系 guard は現在 `web` だけである。
 * 新しいセッション guard / loginUsingId / impersonation / magic-link を足すときは
 * 数え方を読み直すこと (StampRecentAuthOnLogin の ⚠ 注記と同じ性質の前提に立っている)。
 *
 * ⚠ **前提 2 (guard で絞らない理由)**: 本クラスは `metadata.guard` を見ない。
 * Filament の管理画面 (`admin` guard) のログインが混ざらないのは、
 * App\Models\AdminUser が App\Models\User の派生ではない**別クラス**であり、
 * RecordSecurityEvent::asUser() が null を返して `user_id` が付かないためである
 * (= 構造で保証されている)。JSON 列 `metadata` への述語で絞る形は採らない。理由は 3 つ:
 * (1) 索引が効かなくなる、
 * (2)「どの guard を数えるか」の定義が記録側と読み取り側の 2 か所に分かれて食い違う、
 * (3) **本クラスが数えたいのは「web guard のログイン」ではなく
 *     「App\Models\User について発生したログイン」である**。guard 名で絞ると、
 *     将来 users provider の上に正当に追加されたセッション guard を**無言で除外する**。
 * **この前提は Feature テストで固定してある** (AdminUser が User を継承する変更が入れば赤くなる)。
 *
 * ⚠ **保証しないもの**: 値は「最終**ログイン**」であって「最終**活動**」ではない。
 * remember me の cookie が生きている間は再ログインが起きないため、値は
 * 実際の利用より古くなりうる (仕様。doc/02 §2.4 の項目名に従う)。
 * また security_audit_events の保持期間は未確定であり、将来 purger が入れば
 * 古い値から失われる (この依存は RetentionTableRegistry の根拠文に記録してある)。
 */
final class LastLoginLookup
{
    /**
     * 利用者 id の集合に対する最終ログイン時刻の写像を **1 クエリ**で作る。
     *
     * 行ごとに問い合わせない (N+1 を作らない)。ログイン記録の無い利用者は
     * **キーごと現れない** (null を詰めない = 呼び出し側が `?? null` で受ける)。
     *
     * @param  list<int>  $userIds
     * @return array<int, CarbonImmutable>
     */
    public function forUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return []; // 空集合に whereIn を投げない (アーリーリターン)
        }

        $rows = SecurityAuditEvent::query()
            ->select('user_id')
            ->selectRaw('max(occurred_at) as last_login_at')
            ->whereIn('user_id', $userIds)
            ->where('event_type', SecurityEventType::Login->value)
            ->groupBy('user_id')
            // 集計列にはモデルの casts が効かない (occurred_at の cast は別名には伝播しない)。
            // driver 差 (string / DateTime) を SQL 層で吸収せず、framework の cast で閉じる。
            ->withCasts(['last_login_at' => 'immutable_datetime'])
            ->get();

        /** @var array<int, CarbonImmutable> $map */
        $map = [];
        foreach ($rows as $row) {
            $userId = $row->getAttribute('user_id');
            // bigint の PHP 表現は driver 設定で int / integer-string に揺れる。
            // numeric ではなく integerish で受ける (numeric は 1.5 のような float も通してしまう)
            Assert::integerish($userId);

            $lastLoginAt = $row->getAttribute('last_login_at');
            // 集計値が null になるのは group が成立しない場合だけなので、ここは常に日時である。
            // 黙って捨てず instanceof で narrowing する (mixed を外へ出さない = level 10 対応)
            Assert::isInstanceOf($lastLoginAt, CarbonImmutable::class);

            $map[(int) $userId] = $lastLoginAt;
        }

        return $map;
    }
}
```

### 型の根拠（Codex conceptual Round 1 [Warning] への対応）

`max(occurred_at)` の値はモデルの `casts()` が効かない（cast は実列 `occurred_at` に対してのみ定義され、
別名 `last_login_at` には伝播しない）。driver によって `string` / `DateTime` に揺れるため、
**framework 標準の `Builder::withCasts()`**（`vendor/laravel/framework/.../Eloquent/Builder.php` L1976）で
`immutable_datetime` に固定する。自前で `CarbonImmutable::parse()` の分岐を書かない
（思考原則 1: フレームワークのレンジ内でやる）。
`Assert::isInstanceOf` は narrowing と fail-loud を同時に満たす（黙って捨てる `else` を作らない）。

> **注意（誤読を防ぐ）**: immutable になるのは**別名 `last_login_at` だけ**である。
> `SecurityAuditEvent::casts()` の `occurred_at` は `'datetime'`（**mutable** Carbon）のままで、
> 本設計はそれを変えない。「監査モデルは immutable を返す」と読まないこと。

### セキュリティ不変条件との突き合わせ

| 不変条件 | 判定 |
|---|---|
| 3. cross-org 不可 / クラス起点の主キー同一性クエリ | **母集団に入らない**。本クラスのクエリは `whereIn('user_id', …)` であり、`SecurityAuditEvent` の**主キー**に対する同一性述語（`find` / `whereKey` / `where('id', …)`）を 1 つも持たない。`PrimaryKeyStaticQueryScanner` の `OWNER_COLUMNS` は `user_id` を**テナント絞り込み側**として扱う。よって `DirectFetchInventory` への登録は不要（実装時に `composer test` で確認する） |
| cross-org の実質 | `$userIds` の出所は**呼び出し側が org relation から取った集合だけ**である（施策 B）。本クラス自身は org を知らないので、org を跨ぐ集合を渡すと跨いで返す。**この責務境界を doc comment に書かない**（書くと「渡す側が正しければ安全」という弱い保証を強い保証のように読ませる）代わりに、施策 B 側で relation 経由の集合しか渡らないことを固定し、テストで cross-org 混入を検査する（施策 G のテスト 5） |
| 9. 変更系 route は認可を通る | **母集団が増えない**（GET のみ。route を足さない） |
| 6. PII は CipherSweet | `security_audit_events` に PII 列は無い。`user_id` / `event_type` / `occurred_at` のみ触る |
| 11. キャッシュに入れるのは素のデータだけ | **キャッシュを使わない**（毎回クエリする。1 クエリで足りるため） |

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`array<int, CarbonImmutable>`）
- [x] null 安全（`Webmozart\Assert\Assert` で `mixed` を narrowing。`mixed` を外へ出さない）
- [x] DTO を返している（値の写像を返す。`response()->json()` を書かない）
- [x] Generics の型パラメータが正しい（`@param list<int>` / `@return array<int, CarbonImmutable>`）
- [x] `declare(strict_types=1)` + `final`

### リスク

- **行数の増加に対する読み取り性能**。`security_audit_events` は保持期間未確定 = 単調増加が確定している。
  1 利用者あたりの login 行は年単位で数千行になりうる。→ **施策 D で索引を張り替える**。
- **`withCasts` の挙動が将来の Laravel で変わる可能性**。→ 施策 G のテスト 1（値が ISO8601 で props に出る）が
  実 DB 経由で固定するため、変わればテストが落ちる。

---

## 施策 B: Inertia props への追加

### 変更箇所

- `app/DataTransferObjects/Admin/MemberRowData.php`（全体）
- `app/Http/Controllers/Admin/UserManagementController.php` (L32-79)

### 波及変更

- TypeScript 型定義: `resources/js/types/admin.ts` の `MemberRow`（施策 C）
- API Resource/DTO: `MemberRowData` 自身。**JsonResource は経由しない**（Inertia 専用）
- テストファイル: `tests/Feature/Admin/UserManagementPageTest.php`（施策 G）

### 現行コード（MemberRowData）

```php
final readonly class MemberRowData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleState,       // MemberRoleState value
        public string $roleLabel,
        public string $twoFactorStatus, // disabled|pending|enabled
        public bool $isSelf,
    ) {}

    public static function fromUser(User $user, ?OrganizationRole $orgRole, ?ProjectRole $projectRole, int $currentUserId): self
    {
        $state = MemberRoleState::derive($orgRole, $projectRole);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            twoFactorStatus: $user->twoFactorStatus()->value,
            isSelf: $user->id === $currentUserId,
        );
    }
}
```

### 変更後コード（MemberRowData）

```php
/**
 * ユーザー管理画面 (Admin/Users) のメンバー 1 行分。TS 側 types/admin.ts の MemberRow と対で保守。
 * 表示状態 (roleState) は org ロール × Default Project pivot から毎回導出する (概念設計 D2(a))。
 * email は CipherSweet 復号値。本画面は manageMembers 権限者しか到達できない (403) ため
 * 行レベルの可視性分岐は持たない (PII 可視性は画面到達境界で担保)。
 *
 * lastLoginAt は「最後にいつ入ったか」であり、users の列ではなく security_audit_events の
 * login 行から導出する (App\Services\Security\LastLoginLookup)。**履歴は持たない**。
 * 記録が無い利用者は null で、UI は「記録なし」と表示する — 「一度も入っていない」と
 * 断定しないのは、導出元の保持期間が未確定で将来 purge されうるためである。
 */
final readonly class MemberRowData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleState,       // MemberRoleState value
        public string $roleLabel,
        public string $twoFactorStatus, // disabled|pending|enabled
        public bool $isSelf,
        public ?string $lastLoginAt,    // ISO8601 (オフセット付き) / 記録が無ければ null
    ) {}

    public static function fromUser(
        User $user,
        ?OrganizationRole $orgRole,
        ?ProjectRole $projectRole,
        int $currentUserId,
        ?CarbonImmutable $lastLoginAt,
    ): self {
        $state = MemberRoleState::derive($orgRole, $projectRole);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            twoFactorStatus: $user->twoFactorStatus()->value,
            isSelf: $user->id === $currentUserId,
            // オフセット付きで出す。toDateTimeString() は使わない —
            // 端末側 Intl が UTC を現地時刻として解釈し 9 時間ずれる
            lastLoginAt: $lastLoginAt?->toIso8601String(),
        );
    }
}
```

**引数を末尾に足す**（既存の位置引数の順序を変えない = 呼び出し側の破壊を最小にする）。
`?CarbonImmutable` を**必須引数**にする（既定値 `null` を与えない）ことで、
将来 `fromUser` の呼び出し元が増えたときに「渡し忘れて全員 null」が静かに起きない。

### 現行コード（UserManagementController::index、抜粋）

```php
$members = [];
foreach ($organization->users()->get() as $member) {
    $members[] = MemberRowData::fromUser(
        $member,
        $member->organizationRole($organization),
        $pivotRoles[$member->id] ?? null,
        $user->id,
    );
}
```

### 変更後コード（UserManagementController::index、抜粋）

```php
public function index(
    Request $request,
    DefaultProjectResolver $defaultProjects,
    LastLoginLookup $lastLogins,   // ← DI で受ける (container 解決。new しない)
): Response {
    $organization = $this->resolveCurrentOrganization($request);
    Gate::authorize('manageMembers', $organization); // 撮影者・一般メンバーは 403

    // …（$pivotRoles の構築は現行のまま）…

    // メンバー集合は org relation 経由でのみ解決する (cross-org 越境不能)
    $organizationMembers = $organization->users()->get();

    // 最終ログインは行ごとに引かず、id 集合に対して 1 クエリで写像を作る (N+1 を作らない)。
    // 渡す id 集合は上の relation の結果そのものなので、他組織の利用者は構造的に入らない。
    // pluck() は Collection<int, mixed> に落ちて list<int> の narrowing が自己申告になるため、
    // 型が閉じる array_map + array_values で作る (型を緩めて黙らせない = 禁止事項 2)
    $memberIds = array_values(array_map(
        static fn (User $member): int => $member->id,
        $organizationMembers->all(),
    ));
    $lastLoginMap = $lastLogins->forUserIds($memberIds);

    $members = [];
    foreach ($organizationMembers as $member) {
        $members[] = MemberRowData::fromUser(
            $member,
            $member->organizationRole($organization),
            $pivotRoles[$member->id] ?? null,
            $user->id,
            $lastLoginMap[$member->id] ?? null,
        );
    }

    // …（invitations / Inertia::render は現行のまま）…
}
```

**変更点は 3 つだけ**: (1) DI 引数を 1 つ足す、(2) `$organization->users()->get()` を変数に束ねて
2 度引かない、(3) 写像を作って `fromUser` へ渡す。認可・組織解決・招待の取得は**一切触らない**。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`Response`。既存のまま）
- [x] null 安全（`$lastLoginMap[$member->id] ?? null` で不在キーを明示的に null へ）
- [x] DTO を返している（`MemberRowData`。`response()->json()` 無し）
- [x] Generics の型パラメータが正しい（`array_values(array_map(static fn (User $member): int => …))` で
      `list<int>` が型として閉じる。`@var` の自己申告に頼らない — **型を緩めて黙らせない**）

### リスク

- `fromUser` のシグネチャ変更は**呼び出し元が 1 か所しかない**（`UserManagementController`。
  実読で確認済み）ため波及は小さい。
- `$organization->users()->get()` を 2 度呼ばない形に変える副次効果としてクエリが 1 本減る
  （現行は relation を 1 回だけ呼んでいるので実際には増減なし。**性能改善を主張しない**）。

---

## 施策 C: TS 型と画面表示

### 変更箇所

- `resources/js/types/admin.ts` (L12-20)
- `resources/js/pages/Admin/Users.svelte` (L296-311 のメンバー情報ブロック / import 節)

### 波及変更

- TypeScript 型定義: 本施策そのもの
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/AdminUsers.test.ts` の `membersFixture` **4 行すべて**（施策 G）。
  `lastLoginAt` は optional にしないので、足さなければ `pnpm typecheck` が落ちる（意図的）

### 現行コード（types/admin.ts）

```ts
export interface MemberRow {
    id: number;
    name: string;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    twoFactorStatus: "disabled" | "pending" | "enabled";
    isSelf: boolean;
}
```

### 変更後コード（types/admin.ts）

```ts
export interface MemberRow {
    id: number;
    name: string;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    twoFactorStatus: "disabled" | "pending" | "enabled";
    isSelf: boolean;
    /**
     * 最終ログイン日時 (ISO8601、オフセット付き)。記録が無ければ null。
     * 出所は security_audit_events の login 行 (users の列ではない)。
     * null は「一度も入っていない」と「記録が残っていない」を区別しない。
     */
    lastLoginAt: string | null;
}
```

### 現行コード（Admin/Users.svelte、メンバー情報ブロック）

```svelte
<div class="min-w-0 sm:min-w-40">
    <div class="flex items-center gap-2">
        <p class="truncate text-body">{member.name}</p>
        {#if member.twoFactorStatus === "enabled"}
            <Badge tone="success">2FA</Badge>
        {/if}
        {#if member.roleState === "unassigned"}
            <Badge tone="warning" testId={`unassigned-${member.id}`}>
                未割当
            </Badge>
        {/if}
    </div>
    <p class="truncate text-caption text-text-secondary">
        {member.email}
    </p>
</div>
```

### 変更後コード（Admin/Users.svelte）

import 節に 1 行足す:

```ts
import { formatDateTime } from "@/lib/date-format";
```

メンバー情報ブロック（メール行の直後に 1 行足す）:

```svelte
    <p class="truncate text-caption text-text-secondary">
        {member.email}
    </p>
    <!-- 最終ログイン。値の無い行は「記録なし」(「未ログイン」と断定しない — 導出元の
         security_audit_events は保持期間が未確定で、将来 purge されうるため)。
         表示は閲覧者の端末タイムゾーンで行う (date-format.ts の Intl 経由。DS token のみ) -->
    <p
        class="truncate text-caption text-text-secondary"
        data-testid={`member-last-login-${member.id}`}
    >
        最終ログイン {formatDateTime(member.lastLoginAt, "記録なし")}
    </p>
```

### 設計判断

- **`formatDateTime` の fallback 引数で null を吸収する**（Codex conceptual Round 1 の
  「`null` 分岐後にのみ `formatDateTime()` を呼ぶ」提案は採らない）。
  `resources/js/lib/date-format.ts` は「各ページに散在しがちな `toLocaleDateString('ja-JP')` 呼び出しと
  **null/不正値ハンドリングの SSoT**」と自ら宣言しており、呼び出し側で null 分岐を書くのは
  その SSoT を迂回することになる。`Billing/Index.svelte` の `formatDate(page.balance.nextExpireAt, "—")` が
  既存の準拠例である。
- **`formatDate` ではなく `formatDateTime`** を使う。休眠判定は日付粒度で足りるが、
  doc/02 §2.4 の項目名が `最終ログイン日時` であり、`PasskeySection` の「最終利用」（日付のみ）とは
  意味の粒度が違う（あちらは資格の使用痕跡、こちらは在籍の指標）。
- **DS token のみ**: `text-caption` / `text-text-secondary` は `DESIGN.md` L132 / L96 に定義済み。
  hex 直書き無し、新規 token 無し、新規 component 無し、アイコン追加無し。
- **atomic import 階層に影響なし**: `pages` から `lib` を import する形は既存
  （`Billing/Index.svelte` / `Capture/Index.svelte` と同一）。component 層を跨がない。
- **操作列には入れない**。読み取り情報は左の情報ブロックへ、操作は右の actions 列へ、という
  現行の分離を保つ。F-14（375px でのモバイル縦積み）のレイアウトは、情報ブロック内の
  `<p>` が 1 本増えるだけなので横幅に影響しない。

### リスク

- 行が 1 本増えることでモバイル（375px）のリスト密度が下がる。→ 情報ブロックは既に
  `flex-col` の縦積みで、追加は縦方向のみ。**横スクロールは発生しない**（F-14 の最悪幅構成は
  actions 列側で決まる）。
- `truncate` を付けるため、極端に長いロケール表現でも溢れない。

---

## 施策 D: 監査表の索引の置き換え

### 変更箇所

- 新規: `database/migrations/2026_08_17_000100_replace_security_audit_events_user_event_index.php`
- **`users` にも `security_audit_events` にも列を 1 本も足さない**

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（索引は挙動を変えない。`RetentionTableClassificationTest` は
  **表**単位の台帳であり列も索引も見ないため、台帳の件数・区分は不変）

### 現行コード

`database/migrations/2026_06_11_071300_create_security_audit_events_table.php`:

```php
$table->index(['user_id', 'event_type']);   // 既定名: security_audit_events_user_id_event_type_index
$table->index('occurred_at');
```

### 変更後コード

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * security_audit_events の複合索引を occurred_at まで伸ばす (**列は足さない**)。
 *
 * 用途: /manage/users の最終ログイン表示が
 *   select user_id, max(occurred_at) … where user_id in (…) and event_type = 'login' group by user_id
 * を撃つ。既存の ['user_id','event_type'] には集約対象の occurred_at が含まれないため、
 * 選択された実行計画では heap から値を取得する必要がある
 * (どの走査を選ぶかは統計情報しだいなので、実行計画を断定しない)。occurred_at まで索引に含めると
 * **集約に必要な値を索引から供給でき、heap 参照を減らせる (index-only scan の候補になる)**。
 *
 * ⚠ **計算量が定数になるわけではない**。group by は原則としてその利用者の login エントリを
 * 走査するため、履歴件数に対しては依然として線形である。「最大値の取得に効く」とは書かない。
 * 最新 1 件だけを索引順で取る形 (DISTINCT ON / LATERAL) が要るほど遅くなったら、
 * そのときに実測 (EXPLAIN ANALYZE, BUFFERS) を根拠に導出方式ごと設計し直す。
 * 先回りして今は導入しない (思考原則 2)。
 *
 * **追加ではなく置き換え**である。新索引は先頭 2 列が旧索引と同じなので、
 * `user_id, event_type` の**前方一致クエリでは代替できる** (B-tree の左端一致)。
 * 「旧索引の全用途を保証する」とは書かない (誇張しない)。並走を残さない (AGENTS.md 思考原則 3)。
 *
 * ⚠ **この migration は短時間の書き込み停止を許容する**。
 * pgsql の CREATE INDEX (非 CONCURRENTLY) は対象表に SHARE lock を取り、索引構築の間
 * INSERT を止める。本表へ INSERT するのは認証経路 (ログイン / ログアウト / ログイン失敗) なので、
 * **構築中はログインが待たされる**。現行の行数では体感できない長さだが、
 * **低トラフィックの時間帯に実行すること**。無停止が要件になった場合の作り直し方は
 * devnotes/20260817-0909-user-last-login-at/detailed-design.md §施策 D の「将来の見直し条件」。
 *
 * ⚠ **rollback (down) も同じ SHARE lock を取る**。切り戻しも同じ条件で実行すること。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table): void {
            // 先に新索引を張ってから旧索引を落とす (索引が 1 本も無い瞬間を作らない)。
            // 既定命名なので新索引は security_audit_events_user_id_event_type_occurred_at_index。
            $table->index(['user_id', 'event_type', 'occurred_at']);
            // 旧索引 security_audit_events_user_id_event_type_index を落とす。
            // 張った側 (2026_06_11_071300_create_security_audit_events_table.php) も配列指定なので
            // 既定命名で一致する。名前を直書きせず配列で指定する (2 か所に同じ文字列を持たない)
            $table->dropIndex(['user_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table): void {
            $table->index(['user_id', 'event_type']);
            $table->dropIndex(['user_id', 'event_type', 'occurred_at']);
        });
    }
};
```

### 既存行の default と backfill 方針（migration を追加するため明示する）

- **列を足さないので default は存在しない**。既存行に書き込む値も無い。
- **backfill は行わない / 必要ない**。`security_audit_events` の `login` 行は
  2026-06 の表作成以降**すべて記録済み**であり（`RecordSecurityEvent` が最初から購読している）、
  既存ユーザーの最終ログインは**索引を張り替えた瞬間から正しい値が出る**。
  これが実質の backfill にあたる（データ移行の作業は 0 件）。
- **一度もログインしていない既存ユーザー**（seeder 由来など）は写像にキーが現れず、
  props が `null` になり、画面は「記録なし」を表示する（施策 C）。

### 索引名（明示名を直書きしない）

- **索引名は Laravel の既定命名に任せる**（新: `security_audit_events_user_id_event_type_occurred_at_index`、
  旧: `security_audit_events_user_id_event_type_index`）。
- `dropIndex(['user_id','event_type'])` は配列指定から既定命名を再構成する。
  **張った側（`2026_06_11_071300_create_security_audit_events_table.php`）も配列指定**なので命名は一致する
  （実読で確認済み）。
- **明示名での drop は採らない**。落とす側だけ名前を直書きすると「同じ文字列を 2 か所に持つ」形になり、
  本リポジトリが繰り返し禁じている二重管理になる。読む人のために**コメントで既定名を示す**にとどめる。

### この migration が許容すること（停止許容の明示。Codex design-review Round 1 [Critical]）

**この migration は「短時間の書き込み停止」を明示的に許容する設計である。**

- pgsql の `CREATE INDEX`（非 `CONCURRENTLY`）は対象表に `SHARE` lock を取り、
  索引構築の間 **INSERT を止める**。`security_audit_events` へ INSERT するのは認証経路
  （ログイン / ログアウト / ログイン失敗）なので、**構築中はログイン処理が待たされる**。
- `DROP INDEX`（非 `CONCURRENTLY`）は `ACCESS EXCLUSIVE` lock を取り、その表の**読み書き両方**を
  一瞬止める。索引 1 本の削除は短時間だが、ゼロではない。
- **rollback（`down()`）も同じ性質の lock を取る**。切り戻しも同じ条件で扱う。

**運用条件（実行する人への要求）**:

1. **低トラフィックの時間帯に実行する**（ログインが数秒待たされても業務が止まらない時間帯）。
2. **行数が小さいうちに済ませる**。本表は保持期間未確定 = 単調増加が確定しているため、
   先送りするほど構築時間が伸びる。
3. 実行前に `select count(*) from security_audit_events` を見て、想定外に大きければ
   下の「将来の見直し条件」へ切り替える。

**`CONCURRENTLY` を今は使わない理由**:

- (a) `CREATE INDEX CONCURRENTLY` はトランザクション内で実行できず、
  Laravel の migration は pgsql で既定でトランザクションに包むため `public $withinTransaction = false;` が要る。
  そうすると**失敗時に途中状態（invalid index）が残り、人手の復旧手順が必要になる**。
  復旧手順を持たない機構を先に置くのは、運用の負債を増やすだけである。
- (b) 現時点で本表の行数は小さく、**リポジトリにデプロイ定義が存在しない**
  （AGENTS.md の route:cache 運用要件の注記）ため、無停止索引構築を要する運用条件が**まだ無い**。
  過剰な機構を先回りして作らない（思考原則 2）。

**将来の見直し条件**: 本表が百万行規模に達する、**または**無停止デプロイ基盤ができたときは、
`CREATE INDEX CONCURRENTLY` + `$withinTransaction = false` + **invalid index の検出と再実行の手順**を
セットで設計し直す。3 点を揃えずに `CONCURRENTLY` だけ入れないこと。

### PHPStan 適合チェック

- [x] `declare(strict_types=1)` あり
- [x] closure の引数・戻り値に型を明示（`function (Blueprint $table): void`）

### リスク

- **索引の張り替え中に認証経路の INSERT が待つ**（上の「許容すること」で明示的に受容した）。
  現行のデータ量では体感できない長さ。
- **rollback（`down()`）も同じ lock を取る**。切り戻しの最中も認証経路が待つ。
- **旧索引に依存する未知のクエリが遅くなる可能性**。→ 新索引は先頭 2 列が旧索引と同じなので、
  `user_id, event_type` の**前方一致の用途では代替できる**（B-tree の左端一致）。
  **それ以外の用途（索引だけで読み取りが完結する列構成に依存していた場合など）は保証しない**。
  本表に対するその種のクエリは実読の範囲では見つかっていないが、
  「旧索引の全用途を包含する」とは書かない（誇張しない）。
- **性能が上がるとは主張しない**。主張するのは
  「集約に要る `occurred_at` を索引から供給でき、heap 参照を減らせる（index-only scan の候補になる）」
  ことまでである。**利用者ごとの履歴件数に対する計算量は線形のままで、定数にはならない**
  （Codex Round 2 の指摘。「行数の増加に耐える」「最大値の取得に効く」という以前の表現は誤りだったので撤回した）。
- **性能を必須保証にしない**。必要になったら実データ相当の `EXPLAIN (ANALYZE, BUFFERS)` を
  判断材料にして、`DISTINCT ON` / LATERAL / 別の導出方式を改めて設計する。

---

## 施策 E: `SecurityAuditEventFactory` の新設

### 変更箇所

- 新規: `database/factories/SecurityAuditEventFactory.php`
- `app/Models/SecurityAuditEvent.php`（`HasFactory` trait の追加。現在は付いていない）
- `docs/factories.md`（Factory 一覧への追記。AGENTS.md 実装規約で必須）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 G が使う

### 現行コード

`SecurityAuditEvent` は `HasFactory` を使っていない（実読で確認）。
既存テストは本モデルを**読むだけ**で、生成は「実際にログインさせる」形で行っている
（`SecurityAuditTrailCoverageTest` / `OwnershipTransferTest` 等）。

### 変更後コード

`app/Models/SecurityAuditEvent.php`:

```php
use Database\Factories\SecurityAuditEventFactory;   // @use の型名解決に必要
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SecurityAuditEvent extends Model
{
    /** @use HasFactory<SecurityAuditEventFactory> */
    use HasFactory;

    // …（既存のまま。casts() の occurred_at は 'datetime' のまま変えない）…
}
```

`database/factories/SecurityAuditEventFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityAuditEvent>
 *
 * 監査行そのものを作る factory。**アプリの記録経路ではない**
 * (本番の記録は App\Services\Security\SecurityEventRecorder の 1 本道のみ)。
 * 過去時刻の行 (「3 か月前のログイン」等) をテストで用意するために置く。
 */
class SecurityAuditEventFactory extends Factory
{
    protected $model = SecurityAuditEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_type' => SecurityEventType::Login->value,
            'metadata' => ['guard' => 'web'],
            'ip_address' => $this->faker->ipv4(),
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    /** 記録対象の利用者を指定する (user_id は所有権キーのため state で明示代入する) */
    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    /** 種別を差し替える (login 以外を数えないことの検査に使う) */
    public function ofType(SecurityEventType $type): self
    {
        return $this->state(fn (): array => ['event_type' => $type->value]);
    }

    /**
     * 発生時刻を指定する (最新の 1 件が選ばれることの検査に使う)。
     *
     * ⚠ 引数が CarbonImmutable なのは**呼び出し側の都合**である。
     * SecurityAuditEvent の casts() は occurred_at を 'datetime' (**mutable** Carbon) と
     * 宣言しており、モデルから読み戻した値は mutable のままである
     * (「監査モデルが immutable を返す」と読まないこと。immutable になるのは
     *  LastLoginLookup が withCasts で作る別名 last_login_at だけである)。
     */
    public function occurredAt(CarbonImmutable $at): self
    {
        return $this->state(fn (): array => ['occurred_at' => $at]);
    }
}
```

`docs/factories.md` の一覧へ 1 行追記:

```
| `SecurityAuditEventFactory` | SecurityAuditEvent | `forUser($user)`, `ofType(SecurityEventType)`, `occurredAt(CarbonImmutable)`。既定は `login` / `now()` / guard=web。**本番の記録経路ではない** (記録の窓口は SecurityEventRecorder) |
```

### 設計判断

- `user_id` は `$fillable` 外の所有権キーだが、**Factory は `$fillable` を経由しない**
  （`Model::newInstance()` → `forceFill` 相当）ため state で直接指定してよい。
  これは `TakeUploadReservationFactory` が `organization_id` を state で入れているのと同じ流儀。
- **`SecurityEventRecorder` を factory から呼ばない**。窓口は本番の記録経路であり、
  テストの都合で過去時刻を注入できるようにすると窓口の契約（`occurred_at = now()`）が緩む。

### PHPStan 適合チェック

- [x] `@extends Factory<SecurityAuditEvent>` を宣言
- [x] `definition()` の戻り値型 `array<string, mixed>`
- [x] state closure の戻り値型を明示

### リスク

- `HasFactory` の追加はモデルの振る舞いを変えない（trait はメソッドを増やすだけ）。

---

## 施策 F: 保持期間台帳への依存の明記

### 変更箇所

- `tests/Support/Retention/RetentionTableRegistry.php` の `security_audit_events` entry の**根拠文のみ**

### 波及変更

- **区分は `undecided` のまま変えない**。よって `RetentionTableClassificationTest` の
  `RETENTION_TABLE_COUNT`（63）も `RETENTION_UNDECIDED_TABLES`（`security_audit_events` を含む）も**変えない**
- テストファイル: **新規テストを作らない**（下記の重要な発見を参照）

### 重要な発見: トリップワイヤは既に存在する

概念設計 §2-4 決着 3(b) は「区分そのものを pin するトリップワイヤを置く」としたが、
**実装済みだった**。`tests/Feature/Retention/RetentionTableClassificationTest.php` の
`RC-8`（L461-474）が `RETENTION_UNDECIDED_TABLES` を**現在値ちょうどで pin** しており、
その一覧に `security_audit_events` が含まれている（L66）。

したがって、誰かが `security_audit_events` に保持期間を決めて区分を動かした瞬間、
RC-8 が「未確定の表の一覧が変わりました」で落ち、その定数を書き換えるレビューが必ず発生する。
**新しい gate を足さない**（同じ事実を 2 か所で検査しない。思考原則 2 / AGENTS.md「二重検査を作らない」）。
本施策がやるのは、その瞬間に読まれる根拠文へ**依存の事実を書き足すこと**だけである。

### 現行コード

```php
RetentionTableEntry::undecided(
    'security_audit_events',
    '認証と権限に関わる操作の証跡。利用者への外部キーが空値化のため退会後も行が残る。'
    .'監査に必要な保持期間が未決である',
),
```

### 変更後コード

```php
RetentionTableEntry::undecided(
    'security_audit_events',
    '認証と権限に関わる操作の証跡。利用者への外部キーが空値化のため退会後も行が残る。'
    .'監査に必要な保持期間が未決である。'
    .'なおこの表の login 行は /manage/users の最終ログイン表示の唯一の出所であり、'
    .'期限を決めて古い行を消すと、休眠の判定に必要な古い値から先に失われる。'
    .'期限を決めるときは devnotes/20260817-0909-user-last-login-at/ を読み直すこと',
),
```

### PHPStan 適合チェック

- [x] 根拠文は 30 文字以上（`RetentionTableEntry::RATIONALE_MIN_LENGTH` = 30。既に大幅に超過）
- [x] 型の変更なし（`undecided()` の呼び出し形は不変）

### リスク

- 根拠文の変更だけなので機械検査は落ちない（RC-3 は長さのみを見る）。
- `devnotes/` へのパス参照は将来ディレクトリが消えると死ぬ。→ devnotes はコミット対象であり
  削除しない運用（AGENTS.md「設計・TODO・devnotes の運用」）。

---

## 施策 G: テスト

### 変更箇所

- `tests/Feature/Admin/UserManagementPageTest.php`（既存 150 行に追記）
- `tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php`（新規）
- `tests/js/pages/AdminUsers.test.ts`（既存 504 行。fixture 更新 + テスト追加）

### テストファースト（AGENTS.md 思考原則 5）

実装前に **G-1 と G-2 を書いて fail を確認**してから施策 A〜C に入る
（`lastLoginAt` が props に無いので `assertInertia` の `where` が落ちる）。

### G-1. Feature: props の値（`UserManagementPageTest.php` に追記）

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | login 記録のあるメンバーは lastLoginAt に ISO8601 が載る | `SecurityAuditEventFactory` で `login` 行を作り、props の `members.*.lastLoginAt` が `CarbonImmutable` の `toIso8601String()` と一致すること（**オフセット付き**であることも合わせて固定する。`toDateTimeString()` への退行を検出する） |
| 2 | login 記録の無いメンバーは lastLoginAt が null | 招待受諾直後などを想定。`->where('members.0.lastLoginAt', null)` |
| 3 | 複数の login 行があれば**最新**が選ばれる | 3 か月前 / 昨日 / 1 年前 の 3 行を作り、昨日が返ること |
| 4 | `login` 以外の種別は数えない | `logout` / `login_failed` / `password_changed` の行しか無いメンバーは null になること（`ofType()` state を使う） |
| 5 | **他組織のメンバーの login 行が混ざらない** | 別組織のユーザーに login 行を作り、当組織の一覧に影響しないこと。加えて**同一 id 帯の取り違えが無い**こと（cross-org 不変条件の behavioral 検査） |
| 6 | **実際のログインで値が入る**（配線の通し確認） | factory ではなく `POST /login` を実行し、その後 `/manage/users` の props に時刻が載ること。`RecordSecurityEvent` → `SecurityEventRecorder` → 導出 の鎖が繋がっていることを固定する（施策 A が「既存の記録に乗る」という前提の behavioral な担保） |
| 7 | **remember me による自動ログイン復元も数える** | **実 HTTP で recaller 経路を踏む**。`viaRemember` を除外していないことの固定であり、**`StampRecentAuthOnLogin` とは逆の扱い**であることを守る（手順は下記） |
| 8 | 認可境界は既存のまま | 既存の「org Member は 403」テストが `lastLoginAt` の露出も同時に塞いでいることを確認（**新規テストは足さない**。403 なら props ごと存在しない） |
| 9 | **2FA 未完了では数えない** | 2FA を有効にした利用者がパスワードだけ通過し `two-factor-challenge` へ回された時点では `login` 行が**増えない**こと。チャレンジを完了させると増えること（対照）。「セッションが確立していない = まだ入っていない」の固定 |
| 10 | **Filament の admin ログインが混ざらない** | `admin` guard でログインしても、`user_id` の付いた `login` 行が増えないこと。`App\Models\AdminUser` が `App\Models\User` の派生になる変更が入れば赤くなる（施策 A の前提 2 の behavioral な固定） |
| 11 | **招待経由で参加した利用者は参加時刻が最終ログインになる** | 招待 URL を未ログインで開く → `register` へ誘導 → 登録（自動ログイン）→ 受諾、の鎖を通す。**そのあと owner（または org Admin）として認証し直してから** `/manage/users` を開き、招待された利用者の行の `lastLoginAt` にその時刻が載ることを検査する。**招待された本人のまま開いてはいけない** — 参加直後は org Member であり既存認可で 403 になるため props を検査できない（Codex Round 2 の指摘）。「受諾そのものは `Login` を発火しないが、前段の登録ログインで数えられる」ことの固定 |

### テスト 7（remember me）の実装手順

Codex Round 1 の [Critical] を受けて、**弱い代替案（handler の直接呼び出し）は採らない**。
実 HTTP の recaller 経路を踏む。実現可能性は vendor の実読で確認済み:

1. `POST /login` を `remember` 付きで実行してログインする（1 回目の `login` 行が入る）。
2. `$user->fresh()->remember_token` を読む。
3. セッションを捨てる（`$this->flushSession()`）。
4. recaller cookie を組み立てて 2 回目のリクエストを撃つ。
   - cookie 名: `Auth::guard('web')->getRecallerName()`（`SessionGuard` L884 に public で存在）
   - 値: `"{$user->id}|{$rememberToken}|{$user->getAuthPassword()}"`
   - 渡し方: `$this->withCookie($name, $value)`（テストヘルパ側が暗号化して載せる。
     `MakesHttpRequests` L251）
5. 2 回目のリクエストで `SessionGuard::user()` が recaller 経路に入り
   `fireLoginEvent($user, true)`（L197-202）が発火して **2 本目の `login` 行が入る**こと、
   および `/manage/users` の `lastLoginAt` が 2 回目の時刻になることを検査する。

**この検査が守るもの**: 「recaller 復元でも監査行が増える」という配線そのもの。
listener の内部条件だけを見る形にすり替えない。

### G-2. Feature: クエリ数の行数非依存（新規 `UserManagementLastLoginQueryCountTest.php`）

`tests/Feature/Capture/CaptureManualListQueryCountTest.php` の流儀をそのまま踏襲する。

- メンバー 1 人の組織と 10 人の組織で `/manage/users` を叩き、**発行クエリ数が同じ**であること
- 計測前に暖機の GET を 1 回撃つ / fixture 生成は `DB::flushQueryLog()` で計測外にする
- **同一利用者（owner）で行数だけを変えて比較する**（権限差でクエリ数が変わるため）
- **メンバー数以外の条件を両ケースで揃える**（Codex Round 1 の Suggestion）:
  招待は両ケースとも **0 件** / Default Project は両ケースとも **不在** /
  ロールは全員同一 / 全メンバーに `login` 行を 1 本ずつ持たせる。
  こうしないと招待件数や pivot の有無でクエリ数が動き、
  「最終ログインが N+1 になった」以外の理由で赤/緑が変わって検査の意味が薄れる
- これが落ちる = 誰かが `LastLoginLookup` を行ごとに呼ぶ形へ戻した、という意味になる

### G-3. Vitest: 表示（`AdminUsers.test.ts`）

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | fixture 更新 | `membersFixture` の **4 行すべて**に `lastLoginAt` を足す（1 件は `null` にする）。型必須なので足さないと `pnpm typecheck` が落ちる |
| 2 | 値のある行は日時を表示する | `member-last-login-{id}` に `formatDateTime` の結果（`2026/…` 形式）が含まれること |
| 3 | 値が null の行は「記録なし」を表示する | 「未ログイン」という語が**出ない**ことも合わせて固定する（§4-3 の文言判断の退行検出） |
| 4 | 既存の描画テストが壊れていない | メンバー一覧・招待中・追加フォームの既存アサーションがそのまま緑 |

> **Vitest はブラウザのタイムゾーンに依存する**。`formatDateTime` は `Intl` で
> 実行環境の TZ に変換するため、期待値を固定文字列でハードコードしない
> （`formatDateTime(fixtureValue)` の戻り値と比較するか、`2026/` の部分一致で見る）。

### G-4. 走らせる検証コマンド（AGENTS.md の検証コマンド節）

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

（AGENTS.md の `VERIFICATION_COMMANDS` マーカー節の全 10 件と一致させる。
`tests/js/architecture/verification-commands-doc-sync.test.ts` が package.json との同期を強制している）

- `RefreshDatabase` はグローバル適用済み。**個別 `DatabaseTransactions` を書かない**
- テストレーンはホスト全体のグローバルロックで直列化される。heartbeat が出ている間は kill しない

### 既存テストへの影響（削除・上書きをしない）

- `UserManagementPageTest` の既存 shape assertion は `where('members.0.roleState', 'owner')` 等の
  **フィールド単位**なので、フィールドが増えても落ちない（`missing('categoriesUrl')` も無関係）
- `AdminUsers.test.ts` は fixture に必須フィールドが増えるため**型エラーで落ちる**。
  これは意図した波及であり、fixture を直すことで解消する（テストの削除・骨抜きはしない）

---

## 使命・禁止事項チェック（最終確認）

| 項目 | 判定 |
|---|---|
| 使命への寄与 | 直接の生産活動ではない**運用支援**。招待した現場作業者が実際に入れているかを組織管理者が確認できるようにする（オンボーディング不全が無音で放置されない）。概念設計 §6 で「撮影体験そのものの改善ではない」と正直に位置づけ済み |
| 禁止事項 1（テストなし完了） | 施策 G の **G-1 に記載した全ケース**（新規追加 10 件 + 既存テストの再利用 1 件 = テスト 8「org Member は 403」）と、**G-2**（クエリ数の行数非依存 1 件）、**G-3 に記載した全ケース**（Vitest。新規 2 件 + fixture 更新 + 既存アサーションの維持）。テストファーストで G-1 / G-2 の fail を先に見る |
| 禁止事項 2（PHPStan widen） | `withCasts` + `Assert` で `mixed` を narrowing。`@phpstan-ignore` / baseline を使わない |
| 禁止事項 3（dev DB 破壊） | migration は索引の張り替えのみ。`migrate:fresh` を使わない |
| 禁止事項 4（`response()->json()`） | Inertia props + DTO のみ。JsonResource も API 露出も無し |
| 禁止事項 5・6（LLM / prompt） | 該当なし（LLM に触れない） |
| 禁止事項 7（`redirect()->intended()`） | 該当なし（GET のみ） |
| 禁止事項 8（disabled UI） | 操作を 1 つも足さない |
| 禁止事項 9（Artifact） | 成果物はすべて `devnotes/` 配下のファイル |
| 思考原則 2（今必要なものだけ） | 列 0 本 / 記録経路 0 本 / 新規 gate 0 本（既存 RC-8 を使う）/ 絞り込み UI 無し |
| 思考原則 3（並走を残さない） | 索引は追加ではなく**置き換え** |
| 思考原則 4（別概念を統合しない） | 最終ログイン ≠ recent-auth（remember me の扱いを意図的に逆にする）/ ≠ ModelAudit / ≠ 最終活動 / ≠ API キーの last_used_at |
| セキュリティ不変条件 3（cross-org） | current-org 解決のみ・org relation 由来の id 集合のみ・テスト 5 で behavioral に固定 |
| セキュリティ不変条件 9（変更系の認可） | 変更系 route を足さないため母集団不変 |
| DESIGN.md 準拠 | 既存 DS token（`text-caption` / `text-text-secondary`）のみ。hex 直書き無し・token 変更無し |
| Atomic Design 準拠 | 新規 component 無し。`pages` から `lib` の import は既存流儀と同一。アイコン追加無し |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 A〜G が**1 つの意味単位**で動く。`MemberRowData` のシグネチャ変更（B）は TS 型（C）と Vitest fixture（G）を**同時に**直さないと `pnpm typecheck` が落ち、`LastLoginLookup`（A）が無いと B がコンパイルできず、Factory（E）が無いと Feature テスト（G）が書けない。分割すると必ず赤いままの中間状態が生まれる。一方で他ドメイン（撮影 / シナリオ / 課金）へは 1 行も触れないため、独立したブランチで完結する |
| 競合リスク | **低い**。`Admin/Users.svelte` / `MemberRowData` / `UserManagementController` を同時に触る他タスクが無ければ衝突しない。`security_audit_events` の migration を触る他タスクがある場合のみ migration ファイル名（timestamp）の調整が要る。`RetentionTableRegistry` は根拠文 1 か所のみの変更で、区分・件数を動かさないため他タスクの台帳変更と行単位で衝突しにくい |
| 実装順序 | G-1/G-2 のテストを書いて fail を確認 → E（Factory）→ A（Lookup）→ B（DTO/Controller）→ C（TS/Svelte）→ G-3（Vitest）→ D（索引）→ F（台帳根拠文）→ 全検証コマンド |


---

## 実装側からの申告 (設計からの逸脱と、その理由)

1. **施策 G-2 (クエリ数テスト) の測り方を変えた**。詳細設計は「1 人の組織と 10 人の組織で
   **総クエリ数**が同じであること」としていたが、実測したところ **総数は本実装の前から行数に比例していた**
   (1 人 = 14 本 / 10 人 = 41 本)。増分は Laratrust が `User::organizationRole()` の中で
   利用者ごとに roles を引くためで、本 TODO とは無関係の既存性質である。
   総数で測ると「最終ログインが N+1 になった」以外の理由で赤/緑が動くため、
   **security_audit_events に触れたクエリの本数が 1 本であること**を測る形に変えた
   (実測: 1 人でも 10 人でも 1 本)。テストファイルの冒頭コメントにこの理由を書いてある。

2. **施策 G-1 テスト 1 の期待値のタイムゾーン**。`security_audit_events.occurred_at` は
   タイムゾーンを持たない `timestamp` 列なので、`Asia/Tokyo` で作った時刻を保存すると
   壁時計だけが残り、読み戻しはアプリ既定 tz (UTC) になる。この検査の対象は
   「props にオフセット付きで出るか」であって列の tz 保持ではないため、
   期待値もアプリ既定 tz で作る形にした (オフセットが付いていることは正規表現でも固定した)。

3. **施策 G-1 テスト 7 / 9 でテスト側の認証状態を明示的に捨てている**
   (`Auth::forgetGuards()`)。テストプロセスでは guard が解決済み User を保持したままなので、
   session を空にしただけでは `SessionGuard::user()` が早期 return して recaller 経路を踏まない
   (実測で確認した。付けないと remember me の検査が偽陽性で緑になる)。
   テスト 9 は「未完了の利用者」と「完了させた利用者」を別人にして、最後に 1 回だけ props を見る形にした
   (途中で `actingAs($owner)` を挟むと、その後の POST /login が既に認証済みとして扱われるため)。

4. 施策 F は根拠文だけを変え、区分・件数は動かしていない (既存の RC-8 が pin しているため
   新しい gate を足さない、という設計判断のとおり)。

## 検証結果 (すべて green)
- `composer test`: 5599 tests / 5597 passed / 2 skipped (skip は既存)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 160 files / 1965 tests passed
- `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed

---

## 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Admin/MemberRowData.php b/app/DataTransferObjects/Admin/MemberRowData.php
index cd20e8c..e5c3890 100644
--- a/app/DataTransferObjects/Admin/MemberRowData.php
+++ b/app/DataTransferObjects/Admin/MemberRowData.php
@@ -8,12 +8,18 @@
 use App\Enums\OrganizationRole;
 use App\Enums\ProjectRole;
 use App\Models\User;
+use Carbon\CarbonImmutable;
 
 /**
  * ユーザー管理画面 (Admin/Users) のメンバー 1 行分。TS 側 types/admin.ts の MemberRow と対で保守。
  * 表示状態 (roleState) は org ロール × Default Project pivot から毎回導出する (概念設計 D2(a))。
  * email は CipherSweet 復号値。本画面は manageMembers 権限者しか到達できない (403) ため
  * 行レベルの可視性分岐は持たない (PII 可視性は画面到達境界で担保)。
+ *
+ * lastLoginAt は「最後にいつ入ったか」であり、users の列ではなく security_audit_events の
+ * login 行から導出する (App\Services\Security\LastLoginLookup)。**履歴は持たない**。
+ * 記録が無い利用者は null で、UI は「記録なし」と表示する — 「一度も入っていない」と
+ * 断定しないのは、導出元の保持期間が未確定で将来 purge されうるためである。
  */
 final readonly class MemberRowData
 {
@@ -25,10 +31,21 @@ public function __construct(
         public string $roleLabel,
         public string $twoFactorStatus, // disabled|pending|enabled
         public bool $isSelf,
+        public ?string $lastLoginAt,    // ISO8601 (オフセット付き) / 記録が無ければ null
     ) {}
 
-    public static function fromUser(User $user, ?OrganizationRole $orgRole, ?ProjectRole $projectRole, int $currentUserId): self
-    {
+    /**
+     * $lastLoginAt は**既定値を持たない必須引数**である。
+     * 既定 null を与えると、将来 fromUser の呼び出し元が増えたときに
+     * 「渡し忘れて全員 記録なし」が静かに起きるためである。
+     */
+    public static function fromUser(
+        User $user,
+        ?OrganizationRole $orgRole,
+        ?ProjectRole $projectRole,
+        int $currentUserId,
+        ?CarbonImmutable $lastLoginAt,
+    ): self {
         $state = MemberRoleState::derive($orgRole, $projectRole);
 
         return new self(
@@ -39,6 +56,9 @@ public static function fromUser(User $user, ?OrganizationRole $orgRole, ?Project
             roleLabel: $state->label(),
             twoFactorStatus: $user->twoFactorStatus()->value,
             isSelf: $user->id === $currentUserId,
+            // オフセット付きで出す。toDateTimeString() は使わない —
+            // 端末側 Intl が UTC を現地時刻として解釈し 9 時間ずれる
+            lastLoginAt: $lastLoginAt?->toIso8601String(),
         );
     }
 }
diff --git a/app/Http/Controllers/Admin/UserManagementController.php b/app/Http/Controllers/Admin/UserManagementController.php
index 6c4e23a..e2fb274 100644
--- a/app/Http/Controllers/Admin/UserManagementController.php
+++ b/app/Http/Controllers/Admin/UserManagementController.php
@@ -12,6 +12,7 @@
 use App\Models\OrganizationInvitation;
 use App\Models\User;
 use App\Services\Project\DefaultProjectResolver;
+use App\Services\Security\LastLoginLookup;
 use Illuminate\Database\Eloquent\Relations\Pivot;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
@@ -29,8 +30,11 @@ class UserManagementController extends Controller
 {
     use ResolvesCurrentOrganization;
 
-    public function index(Request $request, DefaultProjectResolver $defaultProjects): Response
-    {
+    public function index(
+        Request $request,
+        DefaultProjectResolver $defaultProjects,
+        LastLoginLookup $lastLogins,
+    ): Response {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('manageMembers', $organization); // 撮影者・一般メンバーは 403
 
@@ -52,8 +56,21 @@ public function index(Request $request, DefaultProjectResolver $defaultProjects)
             }
         }
 
+        // メンバー集合は org relation 経由でのみ解決する (cross-org 越境不能)
+        $organizationMembers = $organization->users()->get();
+
+        // 最終ログインは行ごとに引かず、id 集合に対して 1 クエリで写像を作る (N+1 を作らない)。
+        // 渡す id 集合は上の relation の結果そのものなので、他組織の利用者は構造的に入らない。
+        // pluck() は Collection<int, mixed> に落ちて list<int> の narrowing が自己申告になるため、
+        // 型が閉じる array_map + array_values で作る (型を緩めて黙らせない = 禁止事項 2)
+        $memberIds = array_values(array_map(
+            static fn (User $member): int => $member->id,
+            $organizationMembers->all(),
+        ));
+        $lastLoginMap = $lastLogins->forUserIds($memberIds);
+
         $members = [];
-        foreach ($organization->users()->get() as $member) {
+        foreach ($organizationMembers as $member) {
             // organizationRole null (attach 済みだが Laratrust ロール未付与の異常行) も
             // 非表示にせず「未割当」として可視化する (derive が null を Unassigned へ丸める。
             // 管理者はロール割当コマンドでこの行を修復できる = applyConsoleRole の修復経路)
@@ -62,6 +79,7 @@ public function index(Request $request, DefaultProjectResolver $defaultProjects)
                 $member->organizationRole($organization),
                 $pivotRoles[$member->id] ?? null,
                 $user->id,
+                $lastLoginMap[$member->id] ?? null,
             );
         }
 
diff --git a/app/Models/SecurityAuditEvent.php b/app/Models/SecurityAuditEvent.php
index f9c1d17..1925967 100644
--- a/app/Models/SecurityAuditEvent.php
+++ b/app/Models/SecurityAuditEvent.php
@@ -5,6 +5,8 @@
 namespace App\Models;
 
 use App\Enums\SecurityEventType;
+use Database\Factories\SecurityAuditEventFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
 
@@ -15,6 +17,9 @@
  */
 class SecurityAuditEvent extends Model
 {
+    /** @use HasFactory<SecurityAuditEventFactory> */
+    use HasFactory;
+
     /** @var list<string> */
     protected $fillable = [
         'event_type',
diff --git a/app/Services/Security/LastLoginLookup.php b/app/Services/Security/LastLoginLookup.php
new file mode 100644
index 0000000..fa85aa0
--- /dev/null
+++ b/app/Services/Security/LastLoginLookup.php
@@ -0,0 +1,95 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Security;
+
+use App\Enums\SecurityEventType;
+use App\Models\SecurityAuditEvent;
+use Carbon\CarbonImmutable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 「この利用者は最後にいつこのシステムに入ったか」の読み取り。
+ *
+ * **記録点を増やさない**: 出所は security_audit_events の `login` 行だけである
+ * (書き込みの窓口は SecurityEventRecorder。本クラスは読み取り専用で 1 行も書かない)。
+ * users に last_login_at 列を持たない理由は
+ * devnotes/20260817-0909-user-last-login-at/conceptual-design.md §2 が正本。
+ *
+ * **数える対象**: `Illuminate\Auth\Events\Login` が発火したセッション確立すべて
+ * (パスワード / 2FA 完了 / パスキー / SSO / remember me による自動復元 / 登録直後)。
+ * remember me を**除外しない**ことが App\Listeners\Auth\StampRecentAuthOnLogin との
+ * 意図的な差である (あちらの問いは「たった今資格情報を提示したか」で、本クラスの問いは
+ * 「最後に入ったのはいつか」。同じイベントを別条件で読む 2 概念であり統合しない)。
+ * 機械アクセス (API キー / OAuth トークン) は Login を発火しないため構造的に入らない。
+ *
+ * ⚠ **前提 1**: users プロバイダを持つセッション系 guard は現在 `web` だけである。
+ * 新しいセッション guard / loginUsingId / impersonation / magic-link を足すときは
+ * 数え方を読み直すこと (StampRecentAuthOnLogin の ⚠ 注記と同じ性質の前提に立っている)。
+ *
+ * ⚠ **前提 2 (guard で絞らない理由)**: 本クラスは `metadata.guard` を見ない。
+ * Filament の管理画面 (`admin` guard) のログインが混ざらないのは、
+ * App\Models\AdminUser が App\Models\User の派生ではない**別クラス**であり、
+ * RecordSecurityEvent::asUser() が null を返して `user_id` が付かないためである
+ * (= 構造で保証されている)。JSON 列 `metadata` への述語で絞る形は採らない。理由は 3 つ:
+ * (1) 索引が効かなくなる、
+ * (2)「どの guard を数えるか」の定義が記録側と読み取り側の 2 か所に分かれて食い違う、
+ * (3) **本クラスが数えたいのは「web guard のログイン」ではなく
+ *     「App\Models\User について発生したログイン」である**。guard 名で絞ると、
+ *     将来 users provider の上に正当に追加されたセッション guard を**無言で除外する**。
+ * **この前提は Feature テストで固定してある** (AdminUser が User を継承する変更が入れば赤くなる)。
+ *
+ * ⚠ **保証しないもの**: 値は「最終**ログイン**」であって「最終**活動**」ではない。
+ * remember me の cookie が生きている間は再ログインが起きないため、値は
+ * 実際の利用より古くなりうる (仕様。doc/02 §2.4 の項目名に従う)。
+ * また security_audit_events の保持期間は未確定であり、将来 purger が入れば
+ * 古い値から失われる (この依存は RetentionTableRegistry の根拠文に記録してある)。
+ */
+final class LastLoginLookup
+{
+    /**
+     * 利用者 id の集合に対する最終ログイン時刻の写像を **1 クエリ**で作る。
+     *
+     * 行ごとに問い合わせない (N+1 を作らない)。ログイン記録の無い利用者は
+     * **キーごと現れない** (null を詰めない = 呼び出し側が `?? null` で受ける)。
+     *
+     * @param  list<int>  $userIds
+     * @return array<int, CarbonImmutable>
+     */
+    public function forUserIds(array $userIds): array
+    {
+        if ($userIds === []) {
+            return []; // 空集合に whereIn を投げない (アーリーリターン)
+        }
+
+        $rows = SecurityAuditEvent::query()
+            ->select('user_id')
+            ->selectRaw('max(occurred_at) as last_login_at')
+            ->whereIn('user_id', $userIds)
+            ->where('event_type', SecurityEventType::Login->value)
+            ->groupBy('user_id')
+            // 集計列にはモデルの casts が効かない (occurred_at の cast は別名には伝播しない)。
+            // driver 差 (string / DateTime) を SQL 層で吸収せず、framework の cast で閉じる。
+            ->withCasts(['last_login_at' => 'immutable_datetime'])
+            ->get();
+
+        /** @var array<int, CarbonImmutable> $map */
+        $map = [];
+        foreach ($rows as $row) {
+            $userId = $row->getAttribute('user_id');
+            // bigint の PHP 表現は driver 設定で int / integer-string に揺れる。
+            // numeric ではなく integerish で受ける (numeric は 1.5 のような float も通してしまう)
+            Assert::integerish($userId);
+
+            $lastLoginAt = $row->getAttribute('last_login_at');
+            // 集計値が null になるのは group が成立しない場合だけなので、ここは常に日時である。
+            // 黙って捨てず instanceof で narrowing する (mixed を外へ出さない = level 10 対応)
+            Assert::isInstanceOf($lastLoginAt, CarbonImmutable::class);
+
+            $map[(int) $userId] = $lastLoginAt;
+        }
+
+        return $map;
+    }
+}
diff --git a/database/factories/SecurityAuditEventFactory.php b/database/factories/SecurityAuditEventFactory.php
new file mode 100644
index 0000000..1978dac
--- /dev/null
+++ b/database/factories/SecurityAuditEventFactory.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories;
+
+use App\Enums\SecurityEventType;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Factories\Factory;
+
+/**
+ * @extends Factory<SecurityAuditEvent>
+ *
+ * 監査行そのものを作る factory。**アプリの記録経路ではない**
+ * (本番の記録は App\Services\Security\SecurityEventRecorder の 1 本道のみ)。
+ * 過去時刻の行 (「3 か月前のログイン」等) をテストで用意するために置く。
+ */
+class SecurityAuditEventFactory extends Factory
+{
+    /**
+     * user 未指定なら UserFactory に連鎖する (親 Factory 連鎖の規約)。
+     * 既定は login / now() / guard=web。
+     *
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'user_id' => User::factory(),
+            'event_type' => SecurityEventType::Login->value,
+            'metadata' => ['guard' => 'web'],
+            'ip_address' => fake()->ipv4(),
+            'occurred_at' => CarbonImmutable::now(),
+        ];
+    }
+
+    /** 記録対象の利用者を指定する (user_id は所有権キーのため state で明示代入する) */
+    public function forUser(User $user): static
+    {
+        return $this->state(fn (): array => ['user_id' => $user->id]);
+    }
+
+    /** 種別を差し替える (login 以外を数えないことの検査に使う) */
+    public function ofType(SecurityEventType $type): static
+    {
+        return $this->state(fn (): array => ['event_type' => $type->value]);
+    }
+
+    /**
+     * 発生時刻を指定する (最新の 1 件が選ばれることの検査に使う)。
+     *
+     * ⚠ 引数が CarbonImmutable なのは**呼び出し側の都合**である。
+     * SecurityAuditEvent の casts() は occurred_at を 'datetime' (**mutable** Carbon) と
+     * 宣言しており、モデルから読み戻した値は mutable のままである
+     * (「監査モデルが immutable を返す」と読まないこと。immutable になるのは
+     *  LastLoginLookup が withCasts で作る別名 last_login_at だけである)。
+     */
+    public function occurredAt(CarbonImmutable $at): static
+    {
+        return $this->state(fn (): array => ['occurred_at' => $at]);
+    }
+}
diff --git a/database/migrations/2026_08_17_000100_replace_security_audit_events_user_event_index.php b/database/migrations/2026_08_17_000100_replace_security_audit_events_user_event_index.php
new file mode 100644
index 0000000..b42c5de
--- /dev/null
+++ b/database/migrations/2026_08_17_000100_replace_security_audit_events_user_event_index.php
@@ -0,0 +1,60 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * security_audit_events の複合索引を occurred_at まで伸ばす (**列は足さない**)。
+ *
+ * 用途: /manage/users の最終ログイン表示が
+ *   select user_id, max(occurred_at) … where user_id in (…) and event_type = 'login' group by user_id
+ * を撃つ。既存の ['user_id','event_type'] には集約対象の occurred_at が含まれないため、
+ * 選択された実行計画では heap から値を取得する必要がある
+ * (どの走査を選ぶかは統計情報しだいなので、実行計画を断定しない)。occurred_at まで索引に含めると
+ * **集約に必要な値を索引から供給でき、heap 参照を減らせる (index-only scan の候補になる)**。
+ *
+ * ⚠ **計算量が定数になるわけではない**。group by は原則としてその利用者の login エントリを
+ * 走査するため、履歴件数に対しては依然として線形である。「最大値の取得に効く」とは書かない。
+ * 最新 1 件だけを索引順で取る形 (DISTINCT ON / LATERAL) が要るほど遅くなったら、
+ * そのときに実測 (EXPLAIN ANALYZE, BUFFERS) を根拠に導出方式ごと設計し直す。
+ * 先回りして今は導入しない (思考原則 2)。
+ *
+ * **追加ではなく置き換え**である。新索引は先頭 2 列が旧索引と同じなので、
+ * `user_id, event_type` の**前方一致クエリでは代替できる** (B-tree の左端一致)。
+ * 「旧索引の全用途を保証する」とは書かない (誇張しない)。並走を残さない (AGENTS.md 思考原則 3)。
+ *
+ * ⚠ **この migration は短時間の書き込み停止を許容する**。
+ * pgsql の CREATE INDEX (非 CONCURRENTLY) は対象表に SHARE lock を取り、索引構築の間
+ * INSERT を止める。本表へ INSERT するのは認証経路 (ログイン / ログアウト / ログイン失敗) なので、
+ * **構築中はログインが待たされる**。現行の行数では体感できない長さだが、
+ * **低トラフィックの時間帯に実行すること**。無停止が要件になった場合の作り直し方は
+ * devnotes/20260817-0909-user-last-login-at/detailed-design.md の施策 D。
+ *
+ * ⚠ **rollback (down) も同じ SHARE lock を取る**。切り戻しも同じ条件で実行すること。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('security_audit_events', function (Blueprint $table): void {
+            // 先に新索引を張ってから旧索引を落とす (索引が 1 本も無い瞬間を作らない)。
+            // 既定命名なので新索引は security_audit_events_user_id_event_type_occurred_at_index。
+            $table->index(['user_id', 'event_type', 'occurred_at']);
+            // 旧索引 security_audit_events_user_id_event_type_index を落とす。
+            // 張った側 (2026_06_11_071300_create_security_audit_events_table.php) も配列指定なので
+            // 既定命名で一致する。名前を直書きせず配列で指定する (2 か所に同じ文字列を持たない)
+            $table->dropIndex(['user_id', 'event_type']);
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('security_audit_events', function (Blueprint $table): void {
+            $table->index(['user_id', 'event_type']);
+            $table->dropIndex(['user_id', 'event_type', 'occurred_at']);
+        });
+    }
+};
diff --git a/docs/factories.md b/docs/factories.md
index 774994b..74e45dc 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -39,6 +39,7 @@ ## Factory 一覧 (テンプレート同梱)
 | `EmailSuppressionFactory` | EmailSuppression | `bounce()`, `complaint()`, `forEmail(string $email)` (normalize + hash 込み) |
 | `LlmCallLogFactory` | LlmCallLog | `withFxSnapshot(float $rate = 154.32)`, `failed(string $reason = ...)`, `metadataMissing()` |
 | `ModelAuditFactory` | ModelAudit | — (auditable は Item 既定。派生アプリは state で上書き) |
+| `SecurityAuditEventFactory` | SecurityAuditEvent | `forUser($user)`, `ofType(SecurityEventType)`, `occurredAt(CarbonImmutable)`。既定は `login` / `now()` / guard=web。**本番の記録経路ではない** (記録の窓口は SecurityEventRecorder) |
 | `Billing\BillingNotificationFactory` | Billing/BillingNotification | `forOrganization($org)`, `reminder(?string $dedupKey = null)` (dedup_key 経路), `sent()`, `failed()` |
 | `Billing\TicketCheckoutSessionFactory` | Billing/TicketCheckoutSession | `forOrganization($org)`, `initiatedBy($user)`, `completed()`, `expired()`, `stale()` (pending のまま expires_at 過去) |
 | `Billing\TicketReservationFactory` | Billing/TicketReservation | `forOrganization($org)`, `legacy()` (P5 前の in-flight 予約 = `consume_*` null), `monthlyHold(?CarbonImmutable $consumeExpiresAt = null)`, `purchasedHold()`, `stale()` (reserved のまま TTL 超過) |
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index dc638a2..c2da305 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -17,6 +17,7 @@
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
+    import { formatDateTime } from "@/lib/date-format";
     import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
     import type { SharedProps } from "@/lib/shared-props";
     import type { ConsoleRole, InvitationRow, MemberRow } from "@/types/admin";
@@ -308,6 +309,15 @@
                                     <p class="truncate text-caption text-text-secondary">
                                         {member.email}
                                     </p>
+                                    <!-- 最終ログイン。値の無い行は「記録なし」(「未ログイン」と断定しない —
+                                         導出元の security_audit_events は保持期間が未確定で、将来 purge されうるため)。
+                                         表示は閲覧者の端末タイムゾーンで行う (date-format.ts の Intl 経由) -->
+                                    <p
+                                        class="truncate text-caption text-text-secondary"
+                                        data-testid={`member-last-login-${member.id}`}
+                                    >
+                                        最終ログイン {formatDateTime(member.lastLoginAt, "記録なし")}
+                                    </p>
                                 </div>
                                 <div class="flex flex-wrap items-center gap-2 sm:ml-auto sm:shrink-0 sm:justify-end">
                                     {#if canResetTwoFactor(member)}
diff --git a/resources/js/types/admin.ts b/resources/js/types/admin.ts
index 3309c69..61425cd 100644
--- a/resources/js/types/admin.ts
+++ b/resources/js/types/admin.ts
@@ -17,6 +17,12 @@ export interface MemberRow {
     roleLabel: string;
     twoFactorStatus: "disabled" | "pending" | "enabled";
     isSelf: boolean;
+    /**
+     * 最終ログイン日時 (ISO8601、オフセット付き)。記録が無ければ null。
+     * 出所は security_audit_events の login 行 (users の列ではない)。
+     * null は「一度も入っていない」と「記録が残っていない」を区別しない。
+     */
+    lastLoginAt: string | null;
 }
 
 /**
diff --git a/tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php b/tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php
new file mode 100644
index 0000000..f50a300
--- /dev/null
+++ b/tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php
@@ -0,0 +1,94 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\Organization;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * T203: /manage/users の最終ログイン表示が**行ごとのクエリを撃たない**ことを固定する。
+ *
+ * 守るのは 1 点だけ — App\Services\Security\LastLoginLookup が id 集合に対して
+ * security_audit_events を **1 回だけ**引くこと。誰かが行ごとに呼ぶ形へ戻したら赤くなる。
+ *
+ * **総クエリ数の同値では測らない**。この画面は Laratrust のロール判定を利用者ごとに行うため
+ * 総数はもともと行数に比例しており (実測)、総数で測ると「最終ログインが N+1 になった」以外の
+ * 理由で赤/緑が動いて検査の意味が薄れる。よって導出元の表を名指しで数える
+ * (tests/Feature/Capture/CaptureManualListQueryCountTest.php は総数で測れる画面なので
+ *  流儀が違う。ここで同じ形にすると嘘の保証になる)。
+ *
+ * 計測は「GET 1 回ぶん」に限り、fixture 生成は flushQueryLog で計測外にする。
+ * 初回リクエスト固有の初期化を混ぜないよう、計測前に暖機の GET を 1 回撃つ。
+ * 比較は**同一利用者 (owner) の行数違い**でのみ行い、メンバー数以外の条件
+ * (招待 0 件 / Default Project 不在 / 追加メンバーのロールは一律 Member /
+ *  全員が login 行を 1 本持つ) は両ケースで揃える。
+ */
+
+/** 組織へ Member を n 人足し、それぞれに login 行を 1 本ずつ持たせる */
+function addMembersWithLoginRow(Organization $organization, int $count): void
+{
+    foreach (range(1, $count) as $ignored) {
+        $member = attachOrganizationMember($organization, OrganizationRole::Member);
+        SecurityAuditEvent::factory()->forUser($member)->occurredAt(CarbonImmutable::now()->subDay())->create();
+    }
+}
+
+/**
+ * /manage/users を 1 回開き、その間に実行された SQL を返す。
+ *
+ * @return list<string>
+ */
+function measureUserManagementQueries(User $owner): array
+{
+    DB::enableQueryLog();
+    DB::flushQueryLog();
+    test()->actingAs($owner)->get('/manage/users')->assertOk();
+    $log = DB::getQueryLog();
+    DB::disableQueryLog();
+
+    return array_map(static fn (array $entry): string => (string) $entry['query'], $log);
+}
+
+/**
+ * security_audit_events に触れたクエリだけを取り出す。
+ *
+ * @param  list<string>  $queries
+ * @return list<string>
+ */
+function securityAuditEventQueries(array $queries): array
+{
+    return array_values(array_filter(
+        $queries,
+        static fn (string $query): bool => str_contains($query, 'security_audit_events'),
+    ));
+}
+
+test('最終ログインの導出クエリはメンバー数に依らず 1 本 (行ごとに引かない)', function (): void {
+    [$smallOrganization, $smallOwner] = createOrganizationWithOwner('メンバー 1 人の組織');
+    SecurityAuditEvent::factory()->forUser($smallOwner)->occurredAt(CarbonImmutable::now()->subDay())->create();
+
+    [$largeOrganization, $largeOwner] = createOrganizationWithOwner('メンバー 10 人の組織');
+    SecurityAuditEvent::factory()->forUser($largeOwner)->occurredAt(CarbonImmutable::now()->subDay())->create();
+    addMembersWithLoginRow($largeOrganization, 9);
+
+    // 招待 0 件 / Default Project 不在 は両ケースの既定 (createOrganizationWithOwner の初期状態)
+    expect($smallOrganization->invitations()->count())->toBe(0);
+    expect($largeOrganization->invitations()->count())->toBe(0);
+
+    measureUserManagementQueries($smallOwner); // 暖機
+    measureUserManagementQueries($largeOwner); // 暖機
+
+    $small = securityAuditEventQueries(measureUserManagementQueries($smallOwner));
+    $large = securityAuditEventQueries(measureUserManagementQueries($largeOwner));
+
+    expect($small)->toHaveCount(1);
+    expect($large)->toHaveCount(
+        1,
+        '最終ログインの導出が行ごとのクエリになりました (10 人の組織で '
+        .count($large)." 本)。\n".implode("\n", $large)
+    );
+});
diff --git a/tests/Feature/Admin/UserManagementPageTest.php b/tests/Feature/Admin/UserManagementPageTest.php
index a905e83..5c05155 100644
--- a/tests/Feature/Admin/UserManagementPageTest.php
+++ b/tests/Feature/Admin/UserManagementPageTest.php
@@ -4,16 +4,57 @@
 
 use App\Enums\OrganizationRole;
 use App\Enums\ProjectRole;
+use App\Enums\SecurityEventType;
+use App\Models\AdminUser;
 use App\Models\OrganizationInvitation;
 use App\Models\Project;
+use App\Models\SecurityAuditEvent;
 use App\Models\User;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\Auth;
+use Inertia\Testing\AssertableInertia;
+use PragmaRX\Google2FA\Google2FA;
 
 /*
  * 管理メニュー > ユーザー管理 (GET /manage/users)。
  * 読み取り専用画面 (書き込みは既存 organizations.* endpoint)。
  * PII (email) の可視性契約: manageMembers 権限者しか画面自体に到達できない (403 境界)。
+ *
+ * T203: 各行の lastLoginAt (最終ログイン日時) は users の列ではなく
+ * security_audit_events の login 行から導出する (App\Services\Security\LastLoginLookup)。
+ * 「何を数えるか」の主張は 1 つずつテストに対応させる (詳細設計 §数える経路の確定)。
  */
 
+/**
+ * owner として /manage/users を開き、利用者 id → lastLoginAt (ISO8601 or null) の写像を返す。
+ *
+ * @return array<int, string|null>
+ */
+function fetchMemberLastLogins(User $viewer): array
+{
+    $response = test()->actingAs($viewer)->get('/manage/users');
+    $response->assertOk();
+
+    /** @var list<array{id: int, lastLoginAt: string|null}> $members */
+    $members = AssertableInertia::fromTestResponse($response)->toArray()['props']['members'];
+
+    $map = [];
+    foreach ($members as $row) {
+        $map[$row['id']] = $row['lastLoginAt'];
+    }
+
+    return $map;
+}
+
+/** ある利用者の login 行の件数 */
+function loginRowCountFor(User $user): int
+{
+    return SecurityAuditEvent::query()
+        ->where('user_id', $user->id)
+        ->where('event_type', SecurityEventType::Login->value)
+        ->count();
+}
+
 test('org Owner は 200 + Admin/Users component で members/invitations shape を受け取る', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     OrganizationInvitation::factory()->forOrganization($organization)
@@ -148,3 +189,208 @@
 
     $this->actingAs($user)->get('/manage/users')->assertNotFound();
 });
+
+// ─────────────────── T203: 最終ログイン日時 (lastLoginAt) ───────────────────
+
+test('login 記録のあるメンバーは lastLoginAt に ISO8601 (オフセット付き) が載る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+
+    // occurred_at はタイムゾーンを持たない timestamp 列なので、期待値もアプリ既定 tz で作る
+    // (この検査の対象は「props にオフセット付きで出るか」であって列の tz 保持ではない)
+    $at = CarbonImmutable::parse('2026-05-04 10:08:00');
+    SecurityAuditEvent::factory()->forUser($member)->occurredAt($at)->create();
+
+    $lastLogins = fetchMemberLastLogins($owner);
+
+    // toDateTimeString() への退行 (オフセット欠落 = 端末側で 9 時間ずれる) を検出する
+    expect($lastLogins[$member->id])->toBe($at->toIso8601String());
+    expect($lastLogins[$member->id])->toMatch('/(Z|[+-]\d{2}:\d{2})$/');
+});
+
+test('login 記録の無いメンバーは lastLoginAt が null', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+
+    expect(fetchMemberLastLogins($owner)[$member->id])->toBeNull();
+});
+
+test('複数の login 行があれば最新が選ばれる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+
+    $latest = CarbonImmutable::now()->subDay();
+    SecurityAuditEvent::factory()->forUser($member)->occurredAt(CarbonImmutable::now()->subMonthsNoOverflow(3))->create();
+    SecurityAuditEvent::factory()->forUser($member)->occurredAt($latest)->create();
+    SecurityAuditEvent::factory()->forUser($member)->occurredAt(CarbonImmutable::now()->subYearNoOverflow())->create();
+
+    expect(fetchMemberLastLogins($owner)[$member->id])->toBe($latest->toIso8601String());
+});
+
+test('login 以外の種別は数えない (logout / login_failed / password_changed)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+
+    foreach ([SecurityEventType::Logout, SecurityEventType::LoginFailed, SecurityEventType::PasswordChanged] as $type) {
+        SecurityAuditEvent::factory()->forUser($member)->ofType($type)->create();
+    }
+
+    expect(fetchMemberLastLogins($owner)[$member->id])->toBeNull();
+});
+
+test('他組織のメンバーの login 行は混ざらない (cross-org)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+
+    [$otherOrganization, $otherOwner] = createOrganizationWithOwner('別の組織');
+    $otherMember = attachOrganizationMember($otherOrganization);
+    $otherAt = CarbonImmutable::now()->subDays(2);
+    SecurityAuditEvent::factory()->forUser($otherMember)->occurredAt($otherAt)->create();
+    SecurityAuditEvent::factory()->forUser($otherOwner)->occurredAt($otherAt)->create();
+
+    $lastLogins = fetchMemberLastLogins($owner);
+
+    // 当組織の一覧に他組織の利用者は現れず、当組織の行も他組織の値を貰わない
+    expect(array_keys($lastLogins))->not->toContain($otherMember->id);
+    expect(array_keys($lastLogins))->not->toContain($otherOwner->id);
+    expect($lastLogins[$member->id])->toBeNull();
+});
+
+test('実際のログイン (POST /login) で lastLoginAt に値が入る (記録経路の通し確認)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    expect(loginRowCountFor($member))->toBe(0);
+
+    $this->post('/login', ['email' => $member->email, 'password' => 'password'])
+        ->assertRedirect();
+    $this->assertAuthenticatedAs($member);
+
+    expect(loginRowCountFor($member))->toBe(1);
+
+    // 閲覧は owner として行う (member は manageMembers を持たず 403 になるため)
+    $this->flushSession();
+    expect(fetchMemberLastLogins($owner)[$member->id])->not->toBeNull();
+});
+
+test('remember me による自動復元も数える (StampRecentAuthOnLogin とは逆の扱い)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    // 1 回目: 資格情報を提示したログイン (remember 付き)
+    $this->post('/login', [
+        'email' => $member->email,
+        'password' => 'password',
+        'remember' => 'on',
+    ])->assertRedirect();
+    expect(loginRowCountFor($member))->toBe(1);
+
+    $rememberToken = $member->fresh()?->getRememberToken();
+    expect($rememberToken)->toBeString();
+
+    // セッションを捨て、recaller cookie だけで戻る (viaRemember の実経路)。
+    // forgetGuards も要る — テストプロセスでは guard が解決済み User を保持したままで、
+    // session を空にしただけでは SessionGuard::user() が早期 return して recaller を踏まない
+    $recallerName = Auth::guard('web')->getRecallerName();
+    $this->flushSession();
+    Auth::forgetGuards();
+    $this->withCookie(
+        $recallerName,
+        $member->id.'|'.$rememberToken.'|'.$member->getAuthPassword(),
+    )->get('/dashboard');
+
+    $this->assertAuthenticatedAs($member);
+    // recaller 復元でも監査行が増える = 「最後に入ったのはいつか」に反映される
+    expect(loginRowCountFor($member))->toBe(2);
+
+    $this->flushSession();
+    expect(fetchMemberLastLogins($owner)[$member->id])->not->toBeNull();
+});
+
+test('2FA 未完了 (challenge 手前) では数えず、完了させると数える', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    /** 2FA 準拠のメンバーを 1 人作る (パスワードは UserFactory 既定) */
+    $addTwoFactorMember = function () use ($organization): User {
+        $member = User::factory()->withTwoFactor()->create();
+        $organization->users()->attach($member);
+        $member->addRole(OrganizationRole::Member->value, $organization->laratrust_team_id);
+        $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+        return $member;
+    };
+
+    $pending = $addTwoFactorMember();
+    $completed = $addTwoFactorMember();
+
+    // パスワードだけ通過した時点ではセッションが確立していない = まだ入っていない
+    $this->post('/login', ['email' => $pending->email, 'password' => 'password'])
+        ->assertRedirect('/two-factor-challenge');
+    expect(loginRowCountFor($pending))->toBe(0);
+
+    // 対照: チャレンジまで完了させると login 行が生まれる
+    $this->flushSession();
+    Auth::forgetGuards();
+    $this->post('/login', ['email' => $completed->email, 'password' => 'password'])
+        ->assertRedirect('/two-factor-challenge');
+    $secret = decrypt((string) $completed->fresh()?->two_factor_secret);
+    $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
+        ->assertRedirect();
+    expect(loginRowCountFor($completed))->toBe(1);
+
+    $this->flushSession();
+    Auth::forgetGuards();
+    $lastLogins = fetchMemberLastLogins($owner);
+    expect($lastLogins[$pending->id])->toBeNull();
+    expect($lastLogins[$completed->id])->not->toBeNull();
+});
+
+test('Filament 管理画面 (admin guard) のログインは混ざらない', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $adminUser = AdminUser::factory()->create();
+
+    $before = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::Login->value)
+        ->whereNotNull('user_id')
+        ->count();
+
+    Auth::guard('admin')->login($adminUser);
+
+    // AdminUser は App\Models\User の派生ではないため asUser() が null に丸める =
+    // user_id の付いた login 行は 1 件も増えない (構造での保証)
+    expect(SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::Login->value)
+        ->whereNotNull('user_id')
+        ->count())->toBe($before);
+
+    // owner 自身の行にも影響しない
+    expect(fetchMemberLastLogins($owner)[$owner->id])->toBeNull();
+});
+
+test('招待経由で参加した利用者は参加 (登録の自動ログイン) 時刻が最終ログインになる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->createWithPlainToken(['email' => 'invitee@example.com']);
+
+    // 未ログインで招待 URL を開くと token が session へ退避され register へ誘導される
+    $this->get('/invitations/accept?token='.$token)->assertRedirect(route('register'));
+    $this->assertGuest();
+
+    // 登録 = 自動ログイン。受諾そのものは Login を発火しないので、ここが「参加した時刻」になる
+    $this->post('/register', [
+        'name' => '招待 花子',
+        'email' => 'invitee@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ])->assertRedirect();
+
+    $invitee = User::whereBlind('email', 'email_index', 'invitee@example.com')->firstOrFail();
+    expect($organization->users()->whereKey($invitee->id)->exists())->toBeTrue();
+
+    // 参加直後の本人は org Member = /manage/users は 403 なので owner として確認する
+    $this->flushSession();
+    expect(fetchMemberLastLogins($owner)[$invitee->id])->not->toBeNull();
+});
diff --git a/tests/Support/Retention/RetentionTableRegistry.php b/tests/Support/Retention/RetentionTableRegistry.php
index 01772ae..682f4af 100644
--- a/tests/Support/Retention/RetentionTableRegistry.php
+++ b/tests/Support/Retention/RetentionTableRegistry.php
@@ -279,7 +279,10 @@ public static function entries(): array
             RetentionTableEntry::undecided(
                 'security_audit_events',
                 '認証と権限に関わる操作の証跡。利用者への外部キーが空値化のため退会後も行が残る。'
-                .'監査に必要な保持期間が未決である',
+                .'監査に必要な保持期間が未決である。'
+                .'なおこの表の login 行は /manage/users の最終ログイン表示の唯一の出所であり、'
+                .'期限を決めて古い行を消すと、休眠の判定に必要な古い値から先に失われる。'
+                .'期限を決めるときは devnotes/20260817-0909-user-last-login-at/ を読み直すこと',
             ),
             RetentionTableEntry::undecided(
                 'model_audits',
diff --git a/tests/js/pages/AdminUsers.test.ts b/tests/js/pages/AdminUsers.test.ts
index 3ce1442..4c16555 100644
--- a/tests/js/pages/AdminUsers.test.ts
+++ b/tests/js/pages/AdminUsers.test.ts
@@ -1,6 +1,7 @@
 import { beforeEach, describe, expect, it, vi } from "vitest";
 import { fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";
 import Users from "@/pages/Admin/Users.svelte";
+import { formatDateTime } from "@/lib/date-format";
 import type { InvitationRow, MemberRow } from "@/types/admin";
 
 // router.patch をモックして visit options (第3引数) を捕捉し、page は errors を
@@ -55,6 +56,7 @@ const membersFixture: MemberRow[] = [
         roleLabel: "管理者（オーナー）",
         twoFactorStatus: "enabled",
         isSelf: true,
+        lastLoginAt: "2026-05-04T10:08:00+09:00",
     },
     {
         id: 2,
@@ -64,6 +66,7 @@ const membersFixture: MemberRow[] = [
         roleLabel: "編集者",
         twoFactorStatus: "enabled",
         isSelf: false,
+        lastLoginAt: "2026-05-01T09:30:00+09:00",
     },
     {
         // F-14 (モバイル横スクロール) の bug-hunt 実測の最悪幅構成を再現する行:
@@ -76,6 +79,8 @@ const membersFixture: MemberRow[] = [
         roleLabel: "未割当",
         twoFactorStatus: "enabled",
         isSelf: false,
+        // 記録が無い行 (「記録なし」表示の対象)
+        lastLoginAt: null,
     },
     {
         id: 4,
@@ -85,6 +90,7 @@ const membersFixture: MemberRow[] = [
         roleLabel: "撮影者",
         twoFactorStatus: "disabled",
         isSelf: false,
+        lastLoginAt: "2026-04-20T18:00:00+09:00",
     },
 ];
 
@@ -120,6 +126,27 @@ describe("Admin/Users", () => {
         expect(screen.getByTestId("invite-submit")).toBeInTheDocument();
     });
 
+    it("最終ログインの記録がある行は日時を表示する", () => {
+        render(Users, { props: baseProps });
+
+        // 期待値は fixture を同じ formatter に通して作る (Vitest の実行環境 TZ に依存させない)
+        const expected = formatDateTime(membersFixture[0].lastLoginAt, "記録なし");
+        expect(screen.getByTestId("member-last-login-1")).toHaveTextContent(
+            `最終ログイン ${expected}`,
+        );
+        expect(expected).not.toBe("記録なし");
+    });
+
+    it("最終ログインの記録が無い行は「記録なし」を表示し「未ログイン」とは書かない", () => {
+        render(Users, { props: baseProps });
+
+        // id=3 は lastLoginAt null の行
+        expect(screen.getByTestId("member-last-login-3")).toHaveTextContent("最終ログイン 記録なし");
+        // 「一度も入っていない」と断定する語彙への退行を検出する
+        // (導出元 security_audit_events は保持期間が未確定で、将来 purge されうるため)
+        expect(screen.queryByText(/未ログイン/)).toBeNull();
+    });
+
     it("owner 行と自分の行にはロール select を出さずラベル表示する", () => {
         render(Users, { props: baseProps });
 
@@ -184,6 +211,7 @@ describe("Admin/Users", () => {
                         roleLabel: "管理者（オーナー）",
                         twoFactorStatus: "enabled",
                         isSelf: true,
+                        lastLoginAt: "2026-05-04T10:08:00+09:00",
                     },
                     {
                         id: 2,
@@ -193,6 +221,7 @@ describe("Admin/Users", () => {
                         roleLabel: "編集者",
                         twoFactorStatus: "enabled",
                         isSelf: false,
+                        lastLoginAt: "2026-05-01T09:30:00+09:00",
                     },
                     {
                         id: 5,
@@ -202,6 +231,7 @@ describe("Admin/Users", () => {
                         roleLabel: "編集者",
                         twoFactorStatus: "pending",
                         isSelf: false,
+                        lastLoginAt: null,
                     },
                 ] satisfies MemberRow[],
             },
@@ -235,6 +265,7 @@ describe("Admin/Users", () => {
                         roleLabel: "管理者",
                         twoFactorStatus: "enabled",
                         isSelf: true,
+                        lastLoginAt: "2026-05-04T10:08:00+09:00",
                     },
                     {
                         id: 2,
@@ -244,6 +275,7 @@ describe("Admin/Users", () => {
                         roleLabel: "管理者",
                         twoFactorStatus: "enabled",
                         isSelf: false,
+                        lastLoginAt: "2026-05-01T09:30:00+09:00",
                     },
                     {
                         id: 3,
@@ -253,6 +285,7 @@ describe("Admin/Users", () => {
                         roleLabel: "撮影者",
                         twoFactorStatus: "enabled",
                         isSelf: false,
+                        lastLoginAt: null,
                     },
                 ] satisfies MemberRow[],
             },

```

---

## design system 参照 (DESIGN.md)

差分は resources/js/pages/Admin/Users.svelte と resources/js/types/admin.ts に触れる。
新規 component は無く、追加したのは `text-caption` / `text-text-secondary` を使う `<p>` 1 本だけである。

---
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
colors:
    primary: "#2563EB"
    primary-hover: "#1D4ED8"
    tertiary: "#0F766E"
    tertiary-hover: "#115E59"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#15803D"
    warning: "#B45309"
    danger: "#B91C1C"
typography:
    display:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 48px
        fontWeight: 500
        lineHeight: 1.2
        letterSpacing: 0.02em
    h1:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 32px
        fontWeight: 500
        lineHeight: 1.3
        letterSpacing: 0.02em
    h2:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 24px
        fontWeight: 500
        lineHeight: 1.4
    h3:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 18px
        fontWeight: 500
        lineHeight: 1.5
    body:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 16px
        fontWeight: 400
        lineHeight: 1.7
    caption:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 12px
        fontWeight: 400
        lineHeight: 1.5
rounded:
    sm: 4px
    md: 6px
    lg: 8px
spacing:
    xs: 4px
    sm: 8px
    md: 16px
    lg: 24px
    xl: 40px
---

# Design System

本ファイルが**デザインの canonical source**。`resources/css/tokens.css` はその実装写像であり、
独自に値を変えてはいけない(同期契約は `docs/design-system.md`)。

## Overview

テンプレート既定のニュートラルテーマ。中立的な青(#2563EB)を主役、teal(#0F766E)を強アクセント、
無彩のスレート(#F4F4F5)を背景に据える。**アプリ固有のテーマは frontmatter の色値と
tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。

## Colors

色は意味で割り当てる。順序や見た目の好みで使い分けない。

- **Primary(#2563EB)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
  1 画面の主要 CTA 以外には濫用しない。
  - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
- **Tertiary(#0F766E)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
  1 画面に 1 箇所が原則。
  - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
- **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
  - tailwind: `bg-neutral`
- **Surface(#FFFFFF)**: カード・モーダル・浮いた要素の背景。Neutral との明度差で奥行きを出す。
  - tailwind: `bg-surface`
- **Border(#E4E4E7)**: 区切り線、入力欄の枠。常に細く(1px)。
  - tailwind: `border-border`
- **Border Strong(#A1A1AA)**: 区切りの強調、ghost ボタンの枠。
  - tailwind: `border-border-strong`
- **Text Primary(#18181B)**: 本文・見出しの主たる色。純黒は使わない。
  - tailwind: `text-text`(`--color-text` を参照)
- **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
  - tailwind: `text-text-secondary`

### 状態色

- **Success(#15803D)**: 完了・正常・公開済み。
  - tailwind: `text-success`, `bg-success`, `border-success`
- **Warning(#B45309)**: 注意・確認が必要・保留。
  - tailwind: `text-warning`, `bg-warning`, `border-warning`
- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
  (Tertiary は前向きな強調、Danger は否定的なシグナル)。
  - tailwind: `text-danger`, `bg-danger`, `border-danger`

状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
検討すること**(追加条件は `docs/design-system.md` の 4 条件)。

## Typography

全ランプ Noto Sans JP。フォントウェイトは **400 と 500 の 2 階層のみ**(700 は使わない)。
コード・識別子・数値整列には `font-mono` を許可する(日本語 prose には使わない)。

### Typography ramp utility

各 ramp は `resources/css/tokens.css` の `@utility` で定義済。実装はこの utility を
そのまま class として適用する。**raw の `text-sm` / `font-bold` 等は禁止**(ds-purity が検出)。

- **text-display**: 48px / 500 / lh 1.2 / ls 0.02em — tailwind: `text-display`
- **text-h1**: 32px / 500 / lh 1.3 / ls 0.02em — tailwind: `text-h1`
- **text-h2**: 24px / 500 / lh 1.4 — tailwind: `text-h2`
- **text-h3**: 18px / 500 / lh 1.5 — tailwind: `text-h3`
- **text-body**: 16px / 400 / lh 1.7 — tailwind: `text-body`
- **text-caption**: 12px / 400 / lh 1.5 — tailwind: `text-caption`

役割マッピング: 本文/入力値/主要数値 → `text-body`、ラベル/補助情報/日時 → `text-caption`、
page タイトル → `text-h1`/`text-h2`、section/card 見出し → `text-h3`。
強調は `font-medium`(500)を上限とし、足りなければ weight を上げず ramp 昇格+余白+
色階層(text vs text-secondary)でコントラストを作る。

## Layout

8px ベースのスケール。要素間は `md (16px)` を基本に、セクション間は `xl (40px)`。
コンテナは最大幅 1080px を目安に、画面の左右に 32px の余白を確保する。

## Elevation & Depth

**`box-shadow` は使わない。** Neutral(背景)と Surface(カード)の明度差、および 1px の
ボーダーで階層を表現する。ホバー時も影を出さず、ボーダー色や文字色の変化で反応を示す。
グラデーション・scale 効果も使わない。

## Shapes

角丸 ramp は **`rounded-sm`(4px)/ `rounded-md`(6px)/ `rounded-lg`(8px)の 3 段のみ**。
DOM 役割で選ぶ(上から優先): カード・モーダル=`lg` / 中間 box(パネル・`<pre>`)=`md` /
ボタン・入力・バッジ等の小コントロール=`sm`。
素の `rounded`・`rounded-xl` 以上・任意値・方向別(`rounded-t-*` 等)は使わない。
完全円(`rounded-full`)はアバター/status dot/トグル等の**真に円形な UI に限る** ramp 外の例外で、
file-scoped allowlist で個別管理する。

## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。

### Button

実装: `components/atoms/Button.svelte`(仕様の真実は `Button.types.ts`)。

| variant | 用途 | スタイル要旨 |
|---------|------|------------|
| `primary` | 主要 CTA(1 画面 1 つ目安) | bg-primary + text-neutral |
| `tertiary` | 真に重要な前向き CTA(1 画面 1 箇所) | bg-tertiary + text-neutral |
| `ghost` | 補助・キャンセル | 透明 + border-border-strong、hover で primary 化 |
| `neutral` | 取消可能・UI-only の補助操作(一時停止等) | bg-neutral + 常時 border(境界確保) |
| `success` | 肯定操作(追加・承認・付与) | bg-success + text-neutral |
| `danger` | dialog/form の主破壊 CTA | bg-danger + text-neutral |
| `danger-outline` | section 単位の破壊(card 内の削除) | border-danger、hover で塗り |
| `danger-ghost` | dense な row/list 内の破壊アクション | text-danger + 透明、hover で淡い tint |

- **全 variant が border(透明 or 色)を持ち外形高さを統一する**
- danger 系は irreversible / destructive 操作専用(削除・revoke・移譲・再開不可の中断)。
  危険度ではなく**配置文脈**で 3 重みを選ぶ
- **anchor 対応**: `href` 指定で `<a>`(`inertia` 指定で Inertia Link)。anchor モードでは
  `type`/`disabled` は型レベルで禁止。`target="_blank"` には `rel="noopener noreferrer"` を自動補完
- **iconOnly**: `ghost` / `neutral` / `danger-ghost` のみ許可。`ariaLabel` が型で必須
- **disclosure**: button モード限定で `ariaExpanded` / `ariaControls` / `element`(bindable な
  `HTMLButtonElement` 参照)を受ける。ハンバーガー等のトグルはこれを使い素の `<button>` を書かない
- size: `sm`(caption)/ `md`(既定)/ `lg`(form 入力面との高さ整合限定)

### Input / Textarea / Select(入力系 atom)

実装: `components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte`。
見た目は `components/atoms/input-state.ts`(`INPUT_BASE_CLASSES` + `inputStateClass`)に集約し、
入力系 atom 間で統一する。`error` prop で danger 枠と `aria-invalid` が連動する。
`aria-describedby` 等は restProps で透過。Select の `<option>` 群は呼び出し側が
children snippet として記述する。Input の `type` は text 系に限定した union。
ラベル・エラー文言・`aria-describedby` の配線は FormField molecule の責務
(入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
PasswordInput molecule を使う。

- **`type` は入力補助であって検証手段ではない**。`email` / `tel` / `url` / `number` 等は
  モバイルキーボード・autofill・スクリーンリーダーの型アナウンスのために付ける。
  検証の正本はサーバ(日本語)と押下時の client エラーで、native constraint validation には
  依存しない(form 側で `novalidate`。§Do's and Don'ts)。`inputmode` は restProps で透過する
- **readonly は「編集できない」ことを面で示す**(`Input` / `Textarea` の `readonly` prop)。
  `bg-neutral` + `cursor-default`。ただし **disabled と同じ見た目にしない** — readonly の値は
  生きている(送信される・選択してコピーできる・フォーカスできる)ので、文字色は `text-text` の
  ままにし focus ring も維持する。disabled は `text-text-secondary` + `cursor-not-allowed` +
  フォーカス不可。`<select>` は HTML 仕様上 readonly を持たない(編集させないなら値を
  読み取り表示にする)
- 「編集させない値」の表現は 2 通り。**そのフォームの送信対象に含む / コピーさせたい**なら
  readonly input(例: 招待 email の prefill、権限が無い閲覧者への設定値提示)、
  **編集手段自体を出さない**なら読み取り表示(`<dl>` 等。例: 請求先情報カードの非管理者表示)。
  readonly input を選んだ場合、上記の見た目が付くことは atom が保証する

### Checkbox

実装: `components/atoms/Checkbox.svelte`。インラインラベル(右側)とエラー表示
(FormError 内包)を持つチェックボックス。ラベルは string のほか snippet でも受けられる
(利用規約リンク等を含める用)。複数行ラベルでもチェックボックスが 1 行目に揃う行揃えは
本 atom の責務。ページ側で素の `<input type="checkbox">` を書かない(§Do's and Don'ts)。

### FormError

実装: `components/atoms/FormError.svelte`。フィールド単位のエラー文言
(`text-caption text-danger`。message が無ければ何も描画しない)。FormField / Checkbox から
composition される前提の最小 atom。単体で使う場合、`aria-describedby` の配線は呼び出し側の
責務。ページ常在の通知は Alert、一時通知は Toast を使う。
**フィールドに紐づかない失敗(ceremony 失敗・端末非対応等)を FormError に流さない**
(原因と提示先が食い違い、「パスキー失敗がパスワード欄の赤字として出る」species のバグになる)。
非フィールド起因は Alert(§Alert)。

### Avatar

実装: `components/atoms/Avatar.svelte`。`src` があれば画像、無ければ `name` の先頭 1 文字
(大文字化。サロゲートペアも 1 文字扱い)をイニシャル表示する。アバターは真に円形な UI
のため `rounded-full` を使う ramp 外例外(Toggle と並び ds-purity の file-scoped allowlist
出荷時 2 件の 1 つ)。size: `sm` / `md`(既定)/ `lg`。

### Badge

実装: `components/atoms/Badge.svelte`(仕様の真実は `Badge.types.ts`)。状態・属性の
**結果表示**ラベル(操作は Button。action button と status badge は意味色を独立に判断する
— §色の意味的割り当てルール)。tone: `primary` / `tertiary` / `success` / `warning` /
`danger` / `neutral`(中立ラベル)。既定は soft(tone 色の淡い背景 + tone 色文字)、
`bordered` は tone 色 border を atom 内で付与する(呼び出し側から border を足さない)。
左アイコン 1 つを snippet で受け、size/色の責務は Badge 内 wrapper に閉じる。
小コントロールなので `rounded-sm`。size: `sm`(既定)/ `md`。

### Card

実装: `components/atoms/Card.svelte`。浮いた要素の基本サーフェス
(`bg-surface border border-border rounded-lg`。影を使わず明度差 + 1px border で階層を
表現する — §Elevation & Depth)。padding: `none`(table/list 等を内包し内側で個別に
padding を制御する箱用)/ `sm` / `md`(既定)/ `lg`。

### Spinner

実装: `components/atoms/Spinner.svelte`。LoaderCircle(@lucide/svelte)+ `animate-spin`。
色は currentColor 継承(置かれた文脈の文字色に従う)。既定は装飾扱い(`aria-hidden`)で、
単独のローディング表示に使うときだけ `label` を渡す(`role="status"` + sr-only で
読み上げ)。size: `sm` / `md`(既定)/ `lg` / `xl`。

### TextLink

実装: `components/atoms/TextLink.svelte`(仕様の真実は `TextLink.types.ts`)。
リンク風 `<a>` / `<button>` の手書きは禁止(§Do's and Don'ts)、本 atom を使う。
3 モードの discriminated union: (a) `href` のみ = Inertia Link(SPA 遷移)、
(b) `href` + `external` = ネイティブ `<a>` + 別タブ + `rel="noopener noreferrer"` +
末尾 ExternalLink アイコン(`icon` で差し替え可)、(c) `onclick` のみ = リンク風
`<button type="button">`。様式は `text-primary` + 下線(hover で下線が濃くなる)で 3 モード共通。

### Toggle

実装: `components/atoms/Toggle.svelte`(仕様の真実は `Toggle.types.ts`)。
オン/オフを**即時反映**する設定スイッチ(ネイティブ `<button>` + `role="switch"` +
`aria-checked`)。フォーム送信を伴う選択には使わない。`ariaLabel` は型レベルで必須。
トラックは On=`bg-primary` / Off=`bg-border-strong`、つまみは `bg-surface`(影なし、
明度差で表現)。`rounded-full` は真に円形な UI の例外として file-scoped allowlist で管理する。

### Modal

実装: `components/organisms/Modal.svelte`(仕様の真実は `Modal.types.ts`)。bits-ui Dialog のラップ。

- overlay は `bg-text/50`(墨色 50%。黒 hex を使わない)、本体は `bg-surface border border-border rounded-lg`
  (影が使えないためボーダーで背景と区別する)
- size: `sm`(max-w-md)/ `md`(max-w-lg 既定)/ `lg`(max-w-2xl)
- `processing` 中は ESC / overlay クリックでの close を抑止し、X ボタンを disabled にする(二重実行防止)
- title は `text-h3`。a11y 名は bits-ui `Dialog.Title` 経由で `aria-labelledby` に配線される

### ConfirmDialog

実装: `components/organisms/ConfirmDialog.svelte`(仕様の真実は `ConfirmDialog.types.ts`)。Modal の composition。

- `confirmVariant` は `primary` / `danger` の 2 値のみ。**irreversible / destructive な操作は danger**
  (§色の意味的割り当てルール)
- footer は Button atom(cancel=`ghost` / confirm=`confirmVariant`、processing 中は loading)
- confirm で自動 close しない(処理完了後に呼び出し側が `open=false` にする)。
  cancel / ESC / overlay / X は `onCancel` を発火して close
- `banner?: Snippet` は message 直上の任意スロット(サーバ validation エラーの Alert 等)。
  未指定なら描画されない(既存の出力は不変)

### Toast

実装: `components/organisms/ToastContainer.svelte` + `lib/stores/toast.ts`(addToast / dismissToast)。
Laravel flash の取り込みは `lib/stores/flash-to-toast.ts` の `consumeFlash`(visitKey で de-dup)。

- 上部中央 fixed(`top-6 left-1/2 -translate-x-1/2 z-50`)に縦 stack 表示。アプリで 1 箇所のみ mount する
  (mount するのは layout: AppLayout / AuthLayout / GuestLayout の 3 種。ページ側では mount しない)
- 自動消去: **success / info / warning = 4 秒、error = 手動閉じのみ**
- 消去境界: **layout(AppLayout / AuthLayout / GuestLayout)の初期化時に既存 toast を破棄**してから
  当該 visit の flash を消費する。= **layout が再初期化される遷移**では toast を持ち越さない
  (認証済み文脈の toast を未認証面へ出さない)。`preserveState` の visit / partial reload は
  layout を再初期化しないため toast は残る。別タブの既表示 toast の即時消去は保証しない
- 各 toast は `