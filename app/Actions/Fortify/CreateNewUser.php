<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\MatchesInvitationEmail;
use App\Rules\UniqueEncryptedEmail;
use App\Services\Organization\OrganizationMembershipService;
use App\Services\Organization\OrganizationProvisioningService;
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
 * - 招待 (organization invitation) 経由の登録は、session の invitation_token を fail-secure に
 *   解決し、招待 email との一致を MatchesInvitationEmail rule で検証する。受諾可能なら本
 *   transaction 内で招待組織へ参加し、個人組織の自動生成はスキップする (招待組織を主所属に
 *   する)。受諾不能 (失効/取消/受諾済/不一致/既メンバー) なら個人組織生成へ fallback する。
 */
class CreateNewUser implements CreatesNewUsers
{
    public function __construct(
        private readonly OrganizationProvisioningService $provisioning,
        private readonly OrganizationMembershipService $membership,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        // session の招待 token を fail-secure に解決 (未ログインの招待リンク経由で保存される)
        $invitationToken = $this->resolveInvitationToken();

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
                    'consent_version' => config()->string('legal.consent_version'),
                ]);
                $user->save();

                // 招待 token 経由なら招待組織へ参加し、個人組織生成をスキップする。
                // 受諾不能 (失効/取消/不一致/既メンバー) なら null が返るので個人組織へ fallback。
                $joined = $invitationToken !== null
                    ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
                    : null;

                if ($joined === null) {
                    // 個人用組織を同一 transaction 内で原子的に生成する
                    // (user だけ存在し組織なしの中間状態を作らない)
                    $this->provisioning->provisionPersonalOrganization($user);
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

        // 登録が確定したので招待 token を session から落とす (terminal)
        if ($invitationToken !== null) {
            session()->forget('invitation_token');
        }

        return $user;
    }

    /**
     * session の `invitation_token` を fail-secure に取得する。
     *
     * session には任意の型が入りうるため、`is_string && !== ''` を満たさないものは不正値として
     * forget し null を返す (未ログインの招待リンク経路が put する。汚染された値で登録経路の
     * 型契約を壊さない)。
     */
    private function resolveInvitationToken(): ?string
    {
        $session = session();
        $raw = $session->get('invitation_token');

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        if ($raw !== null) {
            $session->forget('invitation_token');
        }

        return null;
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
