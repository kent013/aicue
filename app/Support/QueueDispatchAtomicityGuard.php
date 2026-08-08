<?php

declare(strict_types=1);

namespace App\Support;

use App\DataTransferObjects\Support\QueueDispatchAtomicityViolation;
use App\Enums\Support\QueueAtomicityRule;
use RuntimeException;

/**
 * キュー投入の原子性の前提を起動時に fail-closed 検査する SSOT (AG-114 確定 2)。
 *
 * 【なぜ必要か】業務 tx の内側でキュー投入を行う設計 (確定 1) は
 * 「キューの実体が業務 DB と同一トランザクションに乗る」ことを前提にする。この前提は
 * config と env で簡単に崩れ、**テストは全部緑のまま原子性だけ消える**。
 *
 * 【検査する項目 (AG-126「使っている機能に応じて選ぶ」)】
 * - R1 DatabaseDriver / R2 SameDatabaseConnection / R3 AfterCommitDisabled:
 *   参照接続 (既定接続 + onConnection でリテラル pin された 3 種) について
 * - R4 SyncAfterCommitEnabled: sync 接続の実行順序の保存 (config/queue.php の当該コメント参照)
 * - R5 ProductionAsyncDriver: production の既定接続が sync だと job が HTTP リクエスト内で
 *   インライン実行され、原子性・非同期化・worker 分離がすべて失われるため拒否する
 *
 * 【検査しない項目とその根拠】
 * - **Bus::batch / Bus::chain の束台帳**: `app/` 配下に使用が 0 件のため対象外。
 *   導入するときは `config('queue.batching')` の接続一致検査を本 guard に追加すること
 * - **beanstalkd / sqs / redis / deferred / background / failover**: config に定義があるだけで
 *   どの job からも参照されていない (参照集合に入らない)
 *
 * 【保証しないもの (誇張しない)】
 * - 見るのは **config の値だけ**である。`connection` 名の一致は「同一トランザクションに乗る」
 *   ことの**代理検査**にすぎず、異なる PDO インスタンス / connection resolver の差し替え /
 *   同名で別サーバを指す構成までは検査しない
 * - 「dispatch が業務 tx の内側にあること」自体は検査しない (behavioral テストの担当)
 */
class QueueDispatchAtomicityGuard
{
    /**
     * `onConnection('...')` でリテラル pin されている接続名。
     * 正本は QueuedJobLeaseInventoryTest の QUEUED_JOB_LEASE_INVENTORY で、
     * そちらが deny-by-default で全 ShouldQueue クラスの接続を固定している。
     * この定数と実際の pin 集合の drift は同テストの対称差テストが閉じる
     * (guard 単体では閉じない)。
     *
     * @var list<string>
     */
    public const PINNED_CONNECTIONS = ['database-analysis', 'database-render', 'database-media'];

    /**
     * config を読んで違反の一覧を返す (純関数的。例外を投げない)。
     *
     * ★ 想定外の型・空文字はすべて**違反として報告**し、報告した対象は以降の offset 参照を
     *   行わず早期 continue / return する (fail-closed)。空文字へ丸めて比較を続けると、
     *   R2 の「connection が null = 既定 DB なので OK」の判定が比較対象不在のまま通る。
     *
     * @return list<QueueDispatchAtomicityViolation>
     */
    public function violations(bool $isProduction): array
    {
        $connections = config('queue.connections');
        if (! is_array($connections)) {
            return [new QueueDispatchAtomicityViolation(
                QueueAtomicityRule::ConfigUnreadable,
                '-',
                $this->display($connections),
                'queue.connections が配列ではありません (キュー接続を 1 つも検査できない = fail-closed)。',
            )];
        }

        $defaultQueue = config('queue.default');
        $defaultDatabase = config('database.default');

        $violations = [];
        if (! is_string($defaultQueue) || $defaultQueue === '') {
            $violations[] = new QueueDispatchAtomicityViolation(
                QueueAtomicityRule::ConfigUnreadable,
                '-',
                $this->display($defaultQueue),
                'queue.default が非空文字列ではありません (既定キュー接続を特定できない)。',
            );
        }
        if (! is_string($defaultDatabase) || $defaultDatabase === '') {
            $violations[] = new QueueDispatchAtomicityViolation(
                QueueAtomicityRule::ConfigUnreadable,
                '-',
                $this->display($defaultDatabase),
                'database.default が非空文字列ではありません (業務 DB 接続を特定できない)。',
            );
        }
        // 既定値が取れないなら以降の比較は無意味なので打ち切る (fail-closed)
        if ($violations !== []) {
            return $violations;
        }

        // ここまでで両方 string かつ非空であることが確定している
        /** @var non-empty-string $defaultQueue */
        /** @var non-empty-string $defaultDatabase */

        // R4: sync 接続。**未定義・非配列・driver が sync でない場合も違反**。
        // driver を見ないと「sync という名前の database 接続」で R4 を通せてしまう。
        $sync = $connections['sync'] ?? null;
        if (! is_array($sync)) {
            $violations[] = new QueueDispatchAtomicityViolation(
                QueueAtomicityRule::SyncAfterCommitEnabled,
                'sync',
                $this->display($sync),
                'queue.connections.sync が配列として定義されていません '
                .'(テスト・dev レーンの実行順序 (after_commit=true) を検査できない)。',
            );
        } elseif (($sync['driver'] ?? null) !== 'sync' || ($sync['after_commit'] ?? null) !== true) {
            $violations[] = new QueueDispatchAtomicityViolation(
                QueueAtomicityRule::SyncAfterCommitEnabled,
                'sync',
                'driver='.$this->display($sync['driver'] ?? null)
                .' after_commit='.$this->display($sync['after_commit'] ?? null),
                'queue.connections.sync は driver=sync かつ after_commit=true でなければなりません '
                .'(業務 tx 内 dispatch がその場でインライン実行され、pipeline の startJob が'
                .'自分自身のロック下で成立してしまう)。',
            );
        }

        // R5: production の既定接続 driver
        if ($isProduction) {
            $defaultDriver = $this->driverOf($connections, $defaultQueue);
            if ($defaultDriver !== 'database') {
                $violations[] = new QueueDispatchAtomicityViolation(
                    QueueAtomicityRule::ProductionAsyncDriver,
                    $defaultQueue,
                    $this->display($defaultDriver),
                    "production の既定キュー接続 ({$defaultQueue}) の driver が database ではありません "
                    .'(sync だと job が HTTP リクエスト内でインライン実行され、原子性・非同期化・'
                    .'worker 分離がすべて失われる)。',
                );
            }
        }

        // R1〜R3: 参照集合 = [既定接続] ∪ PINNED_CONNECTIONS (重複除去)。
        // ★ **除外は接続「名」で判定する**。driver === 'sync' で除外すると、
        //   `database-analysis.driver = sync` にした構成が R1〜R3 を全部 skip して通ってしまう
        //   (R4 が見るのは connections.sync だけのため)。
        foreach ($this->referencedConnections($defaultQueue) as $name) {
            if ($name === 'sync') {
                continue; // sync 接続の契約は R4 / R5 が担う
            }

            $config = $connections[$name] ?? null;
            if (! is_array($config)) {
                $violations[] = new QueueDispatchAtomicityViolation(
                    QueueAtomicityRule::DatabaseDriver,
                    $name,
                    $this->display($config),
                    "キュー接続 {$name} の定義が配列として存在しません (原子性の前提を検査できない)。",
                );

                continue; // 以降の offset 参照をしない
            }

            $driver = $config['driver'] ?? null;
            if ($driver !== 'database') {
                $violations[] = new QueueDispatchAtomicityViolation(
                    QueueAtomicityRule::DatabaseDriver,
                    $name,
                    $this->display($driver),
                    "キュー接続 {$name} の driver が database ではありません "
                    .'(業務 DB と同一トランザクションに jobs 行を乗せられない)。',
                );

                continue;
            }

            // R2 は三分岐 (null = 既定 DB 接続 / 非空 string = 一致判定 / それ以外 = 不正)
            $connection = $config['connection'] ?? null;
            if ($connection === null) {
                // 既定 DB 接続を使う = 許可
            } elseif (is_string($connection) && $connection !== '') {
                if ($connection !== $defaultDatabase) {
                    $violations[] = new QueueDispatchAtomicityViolation(
                        QueueAtomicityRule::SameDatabaseConnection,
                        $name,
                        $connection,
                        "キュー接続 {$name} の DB 接続 ({$connection}) が業務 DB "
                        ."({$defaultDatabase}) と異なります (別トランザクションになる)。",
                    );
                }
            } else {
                $violations[] = new QueueDispatchAtomicityViolation(
                    QueueAtomicityRule::SameDatabaseConnection,
                    $name,
                    $this->display($connection),
                    "キュー接続 {$name} の connection は null か非空文字列でなければなりません。",
                );
            }

            // R3 はキー欠落も非 bool も違反 (厳密比較)
            $afterCommit = array_key_exists('after_commit', $config) ? $config['after_commit'] : null;
            if ($afterCommit !== false) {
                $violations[] = new QueueDispatchAtomicityViolation(
                    QueueAtomicityRule::AfterCommitDisabled,
                    $name,
                    $this->display($afterCommit),
                    "キュー投入接続 {$name} の after_commit は false を明示してください "
                    .'(true だと投入が commit 後へずれ「commit したのに未投入」の窓が復活する)。',
                );
            }
        }

        return $violations;
    }

    /**
     * 違反があれば起動を止める (fail-closed)。
     */
    public function enforce(bool $isProduction): void
    {
        $violations = $this->violations($isProduction);
        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            "Queue dispatch atomicity violations:\n- ".implode("\n- ", array_map(
                static fn (QueueDispatchAtomicityViolation $violation): string => $violation->message,
                $violations,
            ))
        );
    }

    /**
     * R1〜R3 の参照集合 (既定接続 + pin 済み接続。重複除去・順序は宣言順)。
     *
     * @return list<string>
     */
    private function referencedConnections(string $defaultQueue): array
    {
        return array_values(array_unique([$defaultQueue, ...self::PINNED_CONNECTIONS]));
    }

    /**
     * 接続名から driver を取り出す (取れなければ null)。
     *
     * @param  array<mixed>  $connections
     */
    private function driverOf(array $connections, string $name): ?string
    {
        $config = $connections[$name] ?? null;
        if (! is_array($config)) {
            return null;
        }

        $driver = $config['driver'] ?? null;

        return is_string($driver) ? $driver : null;
    }

    /** 実測値を表示用の文字列へ正規化する (DTO へ mixed を漏らさないため)。 */
    private function display(mixed $value): string
    {
        if (is_array($value)) {
            return 'array('.count($value).')';
        }
        if (is_object($value)) {
            return 'object('.$value::class.')';
        }

        return var_export($value, true);
    }
}
