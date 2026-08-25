<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\MatchesInvitationEmail;
use App\Rules\UniqueEncryptedEmail;
use App\Services\Onboarding\IntendedPlanResolver;
use App\Services\Organization\OrganizationMembershipService;
use App\Services\Organization\OrganizationProvisioningService;
use App\Support\Auth\InvitationContinuation;
use App\Support\Legal\LegalConsent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Webmozart\Assert\Assert;

/**
 * メール + パスワード登録。
 *
 * - email は CipherSweet 暗号化カラムのため unique rule では検証できない。
 *   UniqueEncryptedEmail rule (blind index 照合) で検証し、衝突時は手段を開示しない
 *   中立メッセージの 422 を返す (アカウント列挙対策)。
 * - 利用規約同意はサーバ側でも必須 (`accepted`)。UI 側はボタンを disabled にせず
 *   押下時にこのエラーを表示する (DESIGN.md §Do's and Don'ts)。
 * - 同意の証跡 (terms_accepted_at / consent_version) は $fillable 外のため forceFill で
 *   初回 INSERT 時点で記録する。
 * - 招待 (organization invitation) 経由の登録は、招待の継続 (InvitationContinuation) を
 *   fail-secure に解決し、招待 email との一致を MatchesInvitationEmail rule で検証する。
 *   受諾可能なら本 transaction 内で招待組織へ参加し、個人組織の自動生成はスキップする
 *   (招待組織を主所属にする)。join 成立時は同一 tx 内で email_verified_at を付与する
 *   (正典 v1 i16 — 招待メール URL の所持 = 受信箱の所有の証明)。
 *   受諾不能 (失効/取消/受諾済/組織論理削除/不一致/既メンバー) なら個人組織生成へ
 *   fallback し、verified は付与しない。
 * - 料金表由来のプラン意図 (`intended_plan`) は validation rules に足さない (無効値でも登録は
 *   通す = 422 で止めない)。値は IntendedPlanResolver が PlanCode allowlist に照合し、
 *   不在 / 無効 / 改ざんはすべて pending forget に倒す (stale pending の誤 promote 防止)。
 *   pending → org-scoped への移送は RegisterResponse が行う。
 */
class CreateNewUser implements CreatesNewUsers
{
    public function __construct(
        private readonly OrganizationProvisioningService $provisioning,
        private readonly OrganizationMembershipService $membership,
        private readonly IntendedPlanResolver $intendedPlanResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        // 処理中の HTTP リクエストに紐づく session を 1 回だけ取得し、resolve と forget に
        // 同じインスタンスを渡す (CreateNewUser は Fortify の RegisteredUserController からのみ
        // HTTP 文脈で呼ばれる。session 未起動なら framework が例外を投げる = fail-fast)。
        // session の招待 token の解決 (型衛生 + 汚染値破棄) は継続クラスに集約する (正典 v1 i11)
        $session = request()->session();
        $invitationToken = InvitationContinuation::resolve($session);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new UniqueEncryptedEmail(message: 'このメールアドレスではアカウントを作成できません。'),
                // 招待 token がある場合のみ、招待 email との一致を検証する (通常登録では素通り)
                new MatchesInvitationEmail($invitationToken),
            ],
            // 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
            // 確認入力 (confirmed) は使わない (表示トグル + リセット導線 + SSO で代替)
            'password' => ['required', 'string', Password::default()],
            'terms_accepted' => ['accepted'],
        ], [
            'terms_accepted.accepted' => '利用規約への同意が必要です。',
        ])->validate();

        // 料金表 → /register?plan= のプラン意図を pending に書き込む (常に書き換える規約)。
        // validate 通過後・tx 前に 1 回だけ呼ぶ (422 で止めた入力の意図は保持しない)。
        $this->intendedPlanResolver->rememberPendingFromForm($input);

        $name = $validated['name'];
        $email = $validated['email'];
        $password = $validated['password'];
        Assert::string($name);
        Assert::string($email);
        Assert::string($password);

        try {
            $user = DB::transaction(function () use ($name, $email, $password, $invitationToken): User {
                $user = (new User([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]))->forceFill([
                    'terms_accepted_at' => now(),
                    'consent_version' => LegalConsent::version(),
                ]);
                $user->save();

                // 招待 token 経由なら招待組織へ参加し、個人組織生成をスキップする。
                // 受諾不能 (失効/取消/不一致/既メンバー) なら null が返るので個人組織へ fallback。
                $joined = $invitationToken !== null
                    ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
                    : null;

                if ($joined === null) {
                    // 個人用組織を同一 transaction 内で原子的に生成する
                    // (user だけ存在し組織なしの中間状態を作らない)。
                    //
                    // 初回 signup grant はここでは付与しない (P6/F2)。付与契機はプラン有効化時
                    // (free = PersonalPlanService::activate / paid = customer.subscription.created)
                    // であり、marker (organizations.signup_tickets_granted_at) の先取と付与は
                    // その経路の同一 tx に閉じている。**marker 設定だけをここに残してはならない**
                    // (付与されない marker 済み org = 永久に付与を受けられない org になる)。
                    $this->provisioning->provisionInitialOrganization($user);
                } else {
                    // 招待経由の登録は email 確認済みとして作成する (正典 v1 i16 / 裁定 AG-214)。
                    // join 成立 = 有効招待 + 宛先一致のロック下再照合を通過 = 招待メール URL の所持
                    // = 受信箱の所有の証明。前提 (i13) は MatchesInvitationEmail rule +
                    // acceptInvitationIfValid の事前照合 + joinOrganization のロック下再照合の三重。
                    // 同一 tx 内で立てるため、Fortify の Registered event (create() return 後に発火) の
                    // SendEmailVerificationNotification は hasVerifiedEmail() を見て確認メールを送らない。
                    // Illuminate\Auth\Events\Verified は発火しない — あの event の意味論は
                    // 「確認フローを完了した」であり登録時付与とは別 (framework の markEmailAsVerified()
                    // 自体も event を発火しない)。aicue に Verified の listener は存在しない (2026-08-25 実測)。
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // validation を通り抜けた INSERT race (blind_indexes の partial unique が止める)。
            // transaction は rollback 済なので、外側で email 起因か再確認する。
            if ($this->emailAlreadyRegistered($email)) {
                $this->rejectExistingEmail();
            }

            throw $e; // email 起因でない unique 違反は握り潰さず再送
        }

        // 登録が確定したので招待 token を継続から落とす (terminal — 正典 v1 i14)
        if ($invitationToken !== null) {
            InvitationContinuation::forget($session);
        }

        return $user;
    }

    /**
     * UniqueEncryptedEmail rule と同一の blind index 照合 (検知パリティ)。
     * INSERT race 後の再確認専用 (事前チェックは validation の rule が担う)。
     *
     * @phpstan-impure
     */
    private function emailAlreadyRegistered(string $email): bool
    {
        return User::whereBlind('email', 'email_index', $email)->exists();
    }

    /**
     * 既存 email 衝突: 手段を開示しない中立メッセージで 422 を返す。
     *
     * @throws ValidationException
     */
    private function rejectExistingEmail(): never
    {
        throw ValidationException::withMessages([
            'email' => ['このメールアドレスではアカウントを作成できません。'],
        ]);
    }
}
