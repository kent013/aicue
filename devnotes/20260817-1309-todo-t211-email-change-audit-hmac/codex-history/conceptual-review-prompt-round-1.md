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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足: この設計の位置づけ】
本リポジトリ (aicue) は複数リポジトリで共有する機能台帳 (lctl) の追従先の 1 つで、
本件は台帳の裁定 AG-195 (2026-08-16) への追従である。裁定の文言・正典形・
他リポジトリの追従状況は設計本文に引用してある。裁定そのものへの賛否ではなく、
**この追従設計として妥当か**をレビューしてほしい。

---

## 概念設計

（以下、devnotes/20260817-1309-todo-t211-email-change-audit-hmac/conceptual-design.md の内容）

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
| 監査専用鍵 (`app.key` とは別の鍵) への切り出し | 裁定が「任意の改善として残す」と明記している。鍵を増やすと本番の必須環境変数と起動時検査 (`ProductionEnvGuard`) が増える。今必要なものだけ作る |
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

## 参考: 現行コード (実読済みの抜粋)

### app/Actions/Fortify/UpdateUserProfileInformation.php (現行)

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

        // 監査証跡。平文 email は metadata に載せない (PII は CipherSweet 管理の users 側に閉じる)。
        $this->recorder->record(SecurityEventType::EmailChanged, $user);

        // 旧アドレスへの on-demand セキュリティ通知
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

### app/Support/EmailHash.php (現行・再利用対象)

```php
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

### app/Services/Security/SecurityEventRecorder.php (現行・変更しない)

```php
    /** @param array<string, mixed> $metadata */
    public function record(SecurityEventType $type, ?User $user, array $metadata = []): void
    {
        try {
            $this->write($type, $user, $metadata);
        } catch (\Throwable $e) {
            report($e);
        }
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
```

### tests/Feature/Security/SecurityAuditTrailCoverageTest.php (現行の該当テスト)

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

    // 平文 email を監査行に落とさない
    $event = SecurityAuditEvent::query()
        ->where('event_type', 'email_changed')
        ->firstOrFail();
    expect($event->user_id)->toBe($user->id)
        ->and($event->metadata)->toBeNull();
});
```
