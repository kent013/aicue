<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use Webmozart\Assert\Assert;

/**
 * 組織招待。token は平文を保存せず sha256 ハッシュ (token_hash) のみ。
 * email は CipherSweet 暗号化 + blind index。
 * token_hash / organization_id / invited_by_user_id は $fillable 外。
 * 取り消しは行削除ではなく revoked_at による論理失効 (spirux 方式)。
 * 招待が持つロールは**組織ロールのみ** (役割付き招待は裁定 AG-079 で撤去。
 * 編集者 / 撮影者は参加後に管理画面のロール割当コマンドで付与する)。
 */
class OrganizationInvitation extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    use UsesCipherSweet;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'role',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * 招待 token (平文) を生成する。URL 埋め込み用途のみで DB には保存しない。
     * DB には hashToken() の sha256 を token_hash 列に保存する。
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * 平文 token を at-rest 保存用の sha256 hash に変換する。
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * 平文 token から「受諾可能 (active: 未受諾・未失効・期限内)」な招待を解決する。
     * token_hash 照合 + scopeActive + 招待元組織の生存のみ (平文 email 検索は行わない =
     * 列挙面を広げない)。active でない (不在/失効/取消/受諾済/組織論理削除) 場合は null。
     *
     * 招待元組織の生存 (SoftDeletes の default scope) も active の条件に含める
     * (正典 v1 i7 — 論理削除済み組織宛は「active でない」へ畳む。scopeActivePendingForEmail の
     * whereHas('organization') と同じ意味論)。scopeActive 自体は招待行の状態だけを表す scope の
     * まま変えない (activePendingForEmail との条件重複を作らない)。
     *
     * MatchesInvitationEmail / acceptInvitationIfValid / register prefill resolver /
     * InvitationAcceptanceController::show が共有し、active 判定条件のドリフトを防ぐ単一解決口。
     * (POST 受諾 acceptInvitation() は revoked/accepted/expired を個別メッセージに出し分けるため
     *  本メソッドを使わない)
     */
    public static function findActiveByPlainToken(string $plainToken): ?self
    {
        // active の定義は scopeActive が単一の正 (未受諾・未失効・期限内: expires_at > now)。
        // isExpired()/isAccepted()/isRevoked() の個別判定と概念的に一致させ、ドリフトを防ぐ。
        return self::query()
            ->active()
            ->whereHas('organization')
            ->where('token_hash', self::hashToken($plainToken))
            ->first();
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('email')
            ->addBlindIndex('email', new BlindIndex('email_index'));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isExpired(): bool
    {
        /** @var Carbon $expiresAt */
        $expiresAt = $this->getAttribute('expires_at');

        return $expiresAt->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * この招待が指定 email 宛かを判定する (**復号後インメモリ宛先比較の単一出典**)。
     *
     * email 同一性規則は scopeActivePendingForEmail (上の docblock) と同じ
     * 「CipherSweet 復号後平文の大文字小文字を区別する厳密一致」である。正規化 (lowercase / trim) は
     * 意図的に行わない (大小差は fail-secure に不一致へ倒す)。
     *
     * 保証範囲は「復号後インメモリ宛先比較の単一出典」であって「email 同一性規則すべての単一実装」では
     * ない。受信者スコープ (scopeActivePendingForEmail) は blind index による DB 検索であり別レイヤ。
     * 両者は同じ意図 (大小区別の厳密一致) で書かれているが、本 predicate を直接は使わない。
     */
    public function isAddressedToEmail(string $email): bool
    {
        $invited = $this->email; // CipherSweet 復号後。model に @property 注釈が無く PHPStan L10 は mixed と見る
        Assert::string($invited);

        return $invited === $email;
    }

    /** User 宛判定の薄いラッパ (呼び出し側の可読性。規則は isAddressedToEmail に集約)。 */
    public function isAddressedTo(User $user): bool
    {
        $email = $user->email;
        Assert::string($email);

        return $this->isAddressedToEmail($email);
    }

    /**
     * Active (受諾可能: 未受諾・未失効・期限内) な招待の query scope。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * **受信者視点の単一解決口** — 「この email 宛の、いま受諾できる招待」の集合。
     *
     * アプリ内受諾 (invitations.accept-in-app) の解決・一覧・件数はすべてこの scope を
     * 再利用する (裁定 AG-113 の必須要素 (b)。2 つがずれると「件数は出るのに受諾できない」が起きる)。
     * 再利用の強制は InvitationResolutionInventoryTest が deny-by-default で行う。
     *
     * 3 条件は**すべて存在秘匿のためにある**:
     *  - active(): 期限切れ・取消済・受諾済を落とす
     *  - whereBlind: 宛先不一致を落とす (CipherSweet の blind index。平文 where は hit しない)
     *  - whereHas('organization'): 削除済み組織宛を落とす
     *    (Organization は SoftDeletes。default scope が効くため deleted_at 判定を手書きしない)
     * これらが**すべて同じ「0 件」に collapse する**ことが、呼び出し側で理由を出し分けずに
     * 一律 404 へ畳める根拠である (403 を返さない = 招待の存在を教えない)。
     *
     * ★email は**大文字小文字を区別する完全一致**である (email の blind index に
     *   Lowercase transformer を付けていない)。大小差のある宛先は 0 件 = 404 に倒れる
     *   (fail-secure)。従来のメール token 経路は token_hash 照合なので影響を受けず、
     *   そちらで受諾できる。
     * ★空文字 email での呼び出しは**呼び出し側が事前に弾く**契約
     *   (OrganizationMembershipService::pendingInvitationsQuery)。ここでは防御しない
     *   (guard を 2 箇所に置くと「どちらが正か」が曖昧になる)。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActivePendingForEmail(Builder $query, string $email): void
    {
        $query->active()
            ->whereBlind('email', 'email_index', $email)
            ->whereHas('organization');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
