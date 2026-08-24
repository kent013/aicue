<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Contracts\Auth\EmailPromotionStageBoundary;
use App\DataTransferObjects\Auth\VerifiedEmail;
use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Enums\SecurityEventType;
use App\Exceptions\Auth\EmailPromotionConflictException;
use App\Mail\EmailPromotionMail;
use App\Models\EmailPromotion;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use App\Support\EmailNormalizer;
use App\Support\EnterpriseSso\AttemptFingerprint;
use App\Support\Organization\OrganizationSlugConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;

/**
 * 企業 SSO でしか入れない利用者が、自分で使えるメールアドレスを持つための昇格。
 *
 * ## なぜ EnterpriseSso ではなく Auth の名前空間に置くか
 *
 * 正典 (laravel-claude-template) の設計判断をそのまま引き継ぐ。
 * 「メールでの引き当てを禁じる設計検査の走査範囲へ入れないための意図的な配置」である。
 *
 * ★**これは検査の回避ではない**。昇格フローも**メールで利用者を引かない** —
 *   引き当ての鍵は常に `Auth::id()` (自分自身) であり、メール文字列は
 *   「その利用者に紐づける値」としてしか現れない。走査から外すのは、
 *   **メール文字列を正当に扱う唯一の場所**を禁止語の走査へ巻き込まないためであって、
 *   引き当ての禁止を緩めるためではない。この主張は
 *   tests/Architecture/EmailPromotionIdentityGateTest (G5) が
 *   「メールから利用者を引く記法を持たない」「既存アカウントとの併合をしない」の
 *   2 点で固定する。
 *
 * ## 適用できる相手 (機能の名前に立ち返る)
 *
 * ★**メールを 1 件も持たない利用者だけ**が対象である。
 *   既にメールを持つ利用者へ開くと、監査と旧アドレスへの通知を持つ既存のメール変更経路
 *   ({@see UpdateUserProfileInformation}) を**迂回する第 2 の変更経路**に
 *   なってしまう。発行と確定の**両方**で、行ロックの下で `email === null` を要求する。
 *
 * ## トークンの一生
 *
 * | 項目 | 形 |
 * |---|---|
 * | トークン | **原文を保存せず指紋のみ** (用途ラベル `EmailPromotionToken`) |
 * | 結合 | `user_id` を持ち、確認時に**認証済みの利用者と一致**すること |
 * | 期限 | `expires_at` (`config('enterprise-sso.email_promotion.ttl_seconds')`) |
 * | 一回使用 | **消費 (行の削除) を先に commit する**。下記「なぜ 2 段に分けるか」を参照 |
 * | 再送 | 新しいトークンを発行したら**旧トークンを失効させる** (発行時の削除 + `user_id` の一意制約) |
 *
 * ## なぜ消費と適用を 2 段に分けるか
 *
 * 適用 (`users.email` の更新) は**メールの blind index の一意制約違反**になりうる。
 * 同じトランザクションの中で例外にすると**行の削除まで巻き戻り**、
 * 同じトークンを期限まで何度でも送れる (= 一回使用が成立しない)。
 * さらに pgsql は一度 SQL エラーが出るとトランザクション全体が aborted になるため、
 * 「捕まえて続きをやる」も同じトランザクションの中では**そもそも動かない**。
 *
 * したがって **第 1 段で消費を確定させ (commit)、第 2 段で適用する**。
 * 帰結として、衝突したトークンは**消費済みのまま失効する** (利用者はやり直す)。
 * これは「露出しても 1 回しか効かない」という本機構の狙いと同じ向きである。
 *
 * ★**第 2 段でも行ロックの下で `email === null` を再確認する**。
 *   第 1 段は commit してロックを手放しているので、2 段の間に別経路 (プロフィール更新など) が
 *   メールを入れていることがありうる。再確認しないと**その更新を黙って上書きする**。
 *   再確認で弾いた場合もトークンは消費済みのままにする (一回使用を保つ)。
 */
final readonly class EmailPromotionService
{
    /** メールの blind index の partial unique index 名 (`add_unique_to_blind_indexes_table` が正本)。 */
    private const string EMAIL_BLIND_INDEX_CONSTRAINT = 'blind_indexes_type_name_value_unique';

    /** PostgreSQL の unique_violation。 */
    private const string SQLSTATE_UNIQUE_VIOLATION = '23505';

    public function __construct(
        private SecurityEventRecorder $recorder,
        private EmailPromotionStageBoundary $stageBoundary,
    ) {}

    /**
     * 昇格を始める (確認メールを送る)。
     *
     * ★**再送も同じ入口**である。発行のたびに自分の古い行を消すので、旧トークンは失効する。
     *
     * @return bool true = 発行した / false = 対象外 (既にメールを持っている)
     */
    public function issue(User $user, string $email): bool
    {
        $normalized = EmailNormalizer::normalize($email);
        $token = AttemptFingerprint::newSecret();

        return DB::transaction(function () use ($user, $normalized, $token): bool {
            // ★行ロックの下で「メールを持たないこと」を確かめる (発行と確定の両方で見る)。
            //   ★**認証済みの自分自身のインスタンス起点**で引く (`$user->newQuery()`)。
            //     クラス起点の主キー同一性クエリで書かない — 対象は payload 由来の id ではなく
            //     常に `Auth::id()` であり、経路そのものを型と起点で固定する。
            $locked = $this->lockedSelf($user);
            if (! $locked instanceof User || $locked->email !== null) {
                return false;
            }

            // ★自分の未消費の行を消してから作る (利用者ごとに 1 件しか持てない)。
            $user->emailPromotions()->delete();

            $promotion = new EmailPromotion;
            $promotion->forceFill([
                'user_id' => $user->id,
                'token_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::EmailPromotionToken, $token),
                'email_encrypted' => $normalized,
                'expires_at' => now()->addSeconds(Config::integer('enterprise-sso.email_promotion.ttl_seconds')),
            ])->save();

            // ★**同一トランザクションの中で**キューへ投入する (AGENTS.md ドメイン規約 11)。
            //   `afterCommit` に依存しない — 行が巻き戻ればメールも投入されない。
            Mail::to($normalized)->send(new EmailPromotionMail($token));

            return true;
        });
    }

    /**
     * 確認トークンを消費して昇格を確定する。
     *
     * ★**確定してよいのは認証済みの本人だけ**である (`user_id` の結合を必ず照合する)。
     * ★確定では `email_verified_at` を**新しいメールを確認した時刻へ更新する**
     *   (「以前の値のまま」にしない = timestamp の意味を保つ)。
     *
     * @return bool true = 確定した / false = トークンが無効・期限切れ・他人のもの・対象外・
     *              **第 1 段の後に別経路でメールが入った** (その場合も**トークンは消費済み**である)
     *
     * @throws EmailPromotionConflictException 確認済みメールが既存利用者のものと重なった
     *                                         (★トークンは**消費済み**である)
     */
    public function confirm(User $user, #[SensitiveParameter] string $token): bool
    {
        $email = $this->consumeToken($user, $token);

        if ($email === null) {
            return false;
        }

        // ★第 1 段のトランザクションは閉じている。**ここが 2 段の継ぎ目**であり、
        //   本番実装は何もしない (継ぎ目の存在理由は EmailPromotionStageBoundary の docblock)。
        $this->stageBoundary->afterConsume($user);

        return $this->applyConfirmedEmail($user, $email);
    }

    /**
     * **第 1 段**: トークンを検査して消費を確定させる (ここで commit される)。
     *
     * ★**private である**。公開すると `confirm()` が担っているトークンの照合・期限・
     *   本人結合を迂回する第 2 の入口ができる (適用せずに他人の確認トークンを
     *   不可逆に消費する呼び方も書けてしまう)。2 段の継ぎ目を検査から捕まえる手段は
     *   {@see EmailPromotionStageBoundary} であって、段の公開ではない。
     *
     * @return VerifiedEmail|null null = トークンが無効・期限切れ・他人のもの・対象外
     */
    private function consumeToken(User $user, #[SensitiveParameter] string $token): ?VerifiedEmail
    {
        $fingerprint = AttemptFingerprint::of(FingerprintPurpose::EmailPromotionToken, $token);

        $email = DB::transaction(function () use ($user, $fingerprint): ?string {
            // ★行ロックの下で「メールを持たないこと」を再確認する (発行後に別経路で入った場合を弾く)。
            $locked = $this->lockedSelf($user);
            if (! $locked instanceof User || $locked->email !== null) {
                return null;
            }

            // ★relation 起点で引く (自分の行だけを見る = 他人のトークンでは何も起きない)。
            $promotion = $user->emailPromotions()
                ->where('token_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($promotion === null || $promotion->expires_at->isPast()) {
                return null;
            }

            $email = $promotion->email_encrypted;
            if (! is_string($email) || $email === '') {
                return null;
            }

            $promotion->delete();

            return $email;
        });

        return $email === null ? null : VerifiedEmail::afterConfirmation($email);
    }

    /**
     * 認証済みの自分自身の行を**ロックして**読み直す。
     *
     * ★**インスタンス起点**である (`$user->newQuery()`)。クラス起点の主キー同一性クエリで
     *   書かないのは、対象が payload 由来の id ではなく常に `Auth::id()` であることを
     *   経路の形そのもので示すためである (AGENTS.md セキュリティ不変条件 3)。
     */
    private function lockedSelf(User $user): ?User
    {
        $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();

        return $locked instanceof User ? $locked : null;
    }

    /**
     * ★`users.email` を書く**唯一の経路**である (昇格の側)。
     *
     * ★**ここでも行ロックの下で `email === null` を再確認する**。
     *   第 1 段 (消費) は commit してロックを手放しているので、その隙に別経路が
     *   メールを入れていることがありうる。再確認しないと**その更新を黙って上書きする**。
     *   上書きしないときはトークンを**消費済みのまま**にして false を返す
     *   (一回使用は保ったまま、他経路の結果を壊さない)。
     *
     * ★書き込みは**第 1 段で読み直した新しいインスタンス**に対して行う。
     *   呼び出し側が持っているインスタンスへ `forceFill()` すると、失敗したときに
     *   未保存の値がそのまま残る。
     *
     * ★**監査の記録も同じトランザクションの中で行う**。外に出すと、監査が失敗したときに
     *   「メールは変わったのに記録が無い」状態が残る (設計 E1 が要求する記録が成立しない)。
     *   記録できなければメールの変更ごと巻き戻す。
     *
     * ★**private である**。公開すると、トークンを 1 つも消費せずに任意の
     *   `VerifiedEmail` を適用できる入口になる (`VerifiedEmail` は
     *   「正当に消費した結果」であることを表せない値である)。
     *
     * @return bool true = 適用した / false = 第 1 段の後にメールが入ったので適用しない
     *
     * @throws EmailPromotionConflictException
     */
    private function applyConfirmedEmail(User $user, VerifiedEmail $email): bool
    {
        try {
            // ★**自分のトランザクション (savepoint) の中で**書く。
            //   pgsql は SQL エラーでトランザクション全体を aborted にするので、裸で書くと
            //   衝突が**呼び出し元のトランザクションまで巻き込む** (第 1 段の消費が使えなくなる)。
            //   savepoint の中なら巻き戻るのはこの 1 文だけである。
            $applied = DB::transaction(function () use ($user, $email): bool {
                $locked = $this->lockedSelf($user);

                if (! $locked instanceof User || $locked->email !== null) {
                    return false;
                }

                $locked->forceFill([
                    'email' => $email->value,
                    // ★**新しいメールを実際に確認した時刻**へ更新する (以前の値のままにしない)。
                    'email_verified_at' => now(),
                ])->save();

                // ★監査に残すのは**利用者と固定の事象種別だけ**である
                //   (トークンも平文のメールも載せない。既存の変更経路と同じ event type を使う)。
                // ★**同じトランザクションの中で**記録する。記録できなければメールの変更も巻き戻る
                //   (「変わったのに記録が無い」を作らない) ので `recordOrFail` を使う。
                $this->recorder->recordOrFail(
                    SecurityEventType::EmailChanged,
                    $locked,
                    ['source' => 'email_promotion'],
                );

                return true;
            });
        } catch (QueryException $e) {
            // ★変換してよいのは**メールの blind index の一意制約違反だけ**である。
            //   それ以外の一意制約違反と DB の障害は握り潰さず伝播させる。
            if ($this->isEmailBlindIndexConflict($e)) {
                throw new EmailPromotionConflictException('email is already taken by another user');
            }

            throw $e;
        }

        return $applied;
    }

    /**
     * メールの blind index の一意制約違反か。
     *
     * ★**制約名まで見る** (SQLSTATE だけで判定しない)。他の一意制約違反まで一様な応答へ
     *   畳むと、壊れていることが「よくある競合」として隠れる。
     * ★**保証範囲**: PostgreSQL の制約名が例外メッセージに現れることに依存する
     *   (本アプリは PostgreSQL 固定。準拠実装 {@see OrganizationSlugConstraintViolation})。
     */
    private function isEmailBlindIndexConflict(QueryException $e): bool
    {
        if (($e->errorInfo[0] ?? null) !== self::SQLSTATE_UNIQUE_VIOLATION) {
            return false;
        }

        return str_contains($e->getMessage(), self::EMAIL_BLIND_INDEX_CONSTRAINT);
    }
}
