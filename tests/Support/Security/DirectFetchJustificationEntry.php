<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use App\Enums\Security\DirectFetchJustification;
use App\Enums\Security\RecoveryFetchShape;
use Webmozart\Assert\Assert;

/**
 * 直 fetch 候補 1 件分の裁定エントリ。
 *
 * case ごとに必須の構造化 field が違うため、**名前付きコンストラクタ経由でのみ**生成できる。
 * nullable プロパティ + 実行時検査にすると検査漏れがそのまま抜け道になるため、
 * 「case を選んだ時点で必須 field が型として要求される」形にしてある
 * (実行時チェックより先に PHPStan 段で止まる)。
 */
final readonly class DirectFetchJustificationEntry
{
    /** 根拠文の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
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

    public static function sameMethodQuery(string $reason): self
    {
        return new self(DirectFetchJustification::IdDerivedFromSameMethodQuery, $reason, []);
    }

    /** @param  string  $calledBy  呼び出し元の `Class::method` */
    public static function internalCaller(string $reason, string $calledBy): self
    {
        return new self(DirectFetchJustification::IdSuppliedByInternalCaller, $reason, [
            'calledBy' => $calledBy,
        ]);
    }

    /**
     * 滞留回収の候補列挙が返した主キーで行を取り直すエントリ。
     *
     * @param  string  $entryPoint  回収の入口 `Class::method` (この本文が当該 private を呼ぶ)
     * @param  string  $stream  回収の系列キー (registry と回収の目録の両方に実在すること)
     */
    public static function recoveryCandidate(
        string $reason,
        string $entryPoint,
        string $stream,
        RecoveryFetchShape $shape,
    ): self {
        return new self(DirectFetchJustification::IdFromRecoveryCandidateEnumeration, $reason, [
            'entryPoint' => $entryPoint,
            'stream' => $stream,
            'recoveryFetchShape' => $shape->value,
        ]);
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
     * **存在オラクル**エントリ。新規コードで使わない。
     *
     * 露出の中身: 認証済みの組織管理者が番号を順に送るだけで、その番号の利用者・組織が
     * 実在するかを全数列挙できる (存在しない id と権限が無い id で応答が分岐するため)。
     *
     * @param  string  $verifiedBy  補償チェックを行う `Class::method`
     * @param  string  $validationRule  当該 id に掛けている validation rule (例 `exists:users,id`)
     * @param  string  $todoRef  是正を追跡する成果物への参照。ModelDirectFetchInvariantTest が
     *                           **実在を機械検証する**ため、次のいずれかでなければならない:
     *                           (a) `aicue:T<番号>` 形式の TODO ID (docs/TODO.md か docs/TODO-closed.md に実在すること)
     *                           (b) リポジトリ相対のファイルパス (存在すること)
     */
    public static function payloadIdExistenceOracle(
        string $reason,
        string $verifiedBy,
        string $validationRule,
        string $todoRef,
    ): self {
        return new self(DirectFetchJustification::PayloadIdExistenceOracle, $reason, [
            'verifiedBy' => $verifiedBy,
            'validationRule' => $validationRule,
            'todoRef' => $todoRef,
        ]);
    }

    // --- 構造化 field の accessor (typo を runtime まで持ち越さない) ---

    public function actorSource(): string
    {
        return $this->require('actorSource');
    }

    public function enqueuedBy(): string
    {
        return $this->require('enqueuedBy');
    }

    public function calledBy(): string
    {
        return $this->require('calledBy');
    }

    public function routeName(): string
    {
        return $this->require('routeName');
    }

    public function commandSignature(): string
    {
        return $this->require('commandSignature');
    }

    public function entryPoint(): string
    {
        return $this->require('entryPoint');
    }

    public function stream(): string
    {
        return $this->require('stream');
    }

    public function recoveryFetchShape(): RecoveryFetchShape
    {
        return RecoveryFetchShape::from($this->require('recoveryFetchShape'));
    }

    public function verifiedBy(): string
    {
        return $this->require('verifiedBy');
    }

    public function validationRule(): string
    {
        return $this->require('validationRule');
    }

    public function todoRef(): string
    {
        return $this->require('todoRef');
    }

    /** 当該 case が持たない field を読んだら設定ミスとして落とす。 */
    private function require(string $key): string
    {
        Assert::keyExists($this->metadata, $key, $this->case->value.' は '.$key.' を持たない');

        return $this->metadata[$key];
    }
}
