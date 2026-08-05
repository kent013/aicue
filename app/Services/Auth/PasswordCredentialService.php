<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * users.password の確定 (設定 / 変更) の単一窓口。
 *
 * 「確定後に何が起きるか」(監査記録・他デバイス失効) を 1 箇所に集約する。
 * 2 経路 (Fortify の変更 / 初回設定) に別々に書くと、片方だけ劣化する
 * (= 他デバイスのセッションが残る等のセキュリティ後退) ため統合する。
 *
 * **transaction 境界の設計**: transaction に入れるのは
 * 「ロック取得 → 前提の再確認 → password の保存」だけ。
 * best-effort な副作用 (監査記録 / DB session 行削除) は **commit 後**に実行する。
 * PostgreSQL は transaction 内で失敗した文があると以降 aborted 状態になり、
 * アプリ側で catch しても commit できない — best-effort のつもりの副作用が
 * 主処理 (パスワード保存) を巻き添えにする。既存 UpdateUserPassword もこれらを
 * transaction 外で行っており、その性質を保つ。
 */
final class PasswordCredentialService
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * 初回設定 (current_password 不要)。
     *
     * 呼び出し側の契約: **recent-auth (step-up) 済みであること** (route の middleware で強制)。
     * password 設定済みユーザーの迂回は fail-closed で拒否する
     * (current_password 必須の変更経路を骨抜きにしない)。
     *
     * @throws ValidationException
     */
    public function setInitial(User $user, string $plain): void
    {
        // transaction は「ロック → 再確認 → 保存」だけ (副作用は commit 後)
        $hash = DB::transaction(function () use ($user, $plain): string {
            // 同時 2 リクエストで両方が「未設定」と判定するのを防ぐ (TOCTOU)。
            // ロック取得順序は User 単位 (EnsureLoginMethodRemains と同型の作法)。
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->hasPassword()) {
                throw ValidationException::withMessages([
                    'password' => 'すでにパスワードが設定されています。パスワード変更フォームから変更してください。',
                ]);
            }

            $hash = Hash::make($plain);
            $locked->forceFill(['password' => $hash])->save();

            return $hash;
        });

        // **呼び出し元が持つインスタンス (= guard が保持している認証済み User) にも反映する**。
        // 保存したのはロック取得のために引き直した別インスタンスであり、これを怠ると
        // Auth::logoutOtherDevices() が guard 上の古い hash と照合して
        // InvalidArgumentException を投げる (パスワードは保存済みなのに 500 になる)。
        // 既に永続化済みなので dirty 扱いにはしない。
        $user->forceFill(['password' => $hash])->syncOriginalAttribute('password');

        $this->afterPersist($user, $plain, SecurityEventType::PasswordSet);
    }

    /**
     * 変更 (current_password の検証は Fortify 契約側 UpdateUserPassword が行う)。
     * 単一 UPDATE のため transaction は開かない (既存挙動を変えない)。
     */
    public function change(User $user, string $plain): void
    {
        $user->forceFill(['password' => Hash::make($plain)])->save();

        $this->afterPersist($user, $plain, SecurityEventType::PasswordChanged);
    }

    /**
     * 保存 **commit 後**の副作用: 監査記録 → 他デバイス失効 → DB session 行削除。
     * transaction 内では実行しない (上記の PostgreSQL 事情)。
     * best-effort なのは **監査記録と DB session 行削除**の 2 つ (どちらも内部で例外を握る)。
     * `Auth::logoutOtherDevices()` は例外を捕捉しない (失敗は 500 として表面化させる。
     * 他デバイス失効は correctness 側の要求であり、既存 UpdateUserPassword の挙動を維持する)。
     */
    private function afterPersist(User $user, string $plain, SecurityEventType $event): void
    {
        // 「そのユーザーが自分でパスワードを設定/変更したか」の監査証跡。
        // 記録失敗は report のみ (SecurityEventRecorder が内包する)。
        $this->recorder->record($event, $user);

        // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
        // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
        // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
        // web グループの AuthenticateSession による password_hash 照合が担保する (correctness の要)。
        // 渡すのは current_password ではなく保存直後の新 password。
        Auth::logoutOtherDevices($plain);

        $this->deleteOtherSessionRecords($user);
    }

    /**
     * 現在の session を除き、当該 user の DB session 行を削除する (session driver=database 時のみ)。
     *
     * correctness は AuthenticateSession が担うため best-effort: 失敗しても report して継続する
     * (パスワードの確定自体は成功しているため正常応答を維持する)。
     */
    private function deleteOtherSessionRecords(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        // session 未初期化文脈 (console/queue 等) では現在ID除外の前提が崩れるため何もしない。
        if (! session()->isStarted()) {
            return;
        }

        $connection = config('session.connection');
        $table = config('session.table', 'sessions');

        Assert::nullOrString($connection);
        Assert::string($table);

        try {
            DB::connection($connection)
                ->table($table)
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', session()->getId())
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
