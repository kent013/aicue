<?php

declare(strict_types=1);

namespace Tests\Support\Retention;

use App\Console\Commands\Capture\PurgeUploadReservationsCommand;
use App\Console\Commands\PurgeInquiriesCommand;
use App\Support\Account\AccountDeletionGrace;
use App\Support\Idempotency\IdempotencyRetention;
use Spatie\LaravelCipherSweet\Observers\ModelObserver;

/**
 * 実スキーマの全表を保持期限の区分へ分類した台帳。
 *
 * ★**除外一覧を持たない**。基盤の表 (migrations / cache / sessions / jobs) も
 *   区分の 1 つとして必ずここに載る。除外の口を作ると、そこへ名前を足すだけで
 *   検査から逃げられる。
 *
 * ★**年数・起算点・purger の配線は書かない**。課金 7 年の正本は
 *   App\Enums\Billing\BillingRetentionTarget、各バッチの期限は各 config の解決点クラスであり、
 *   ここに写すと二重管理になる。ここが持つのは「区分」「根拠」「保持者の名前」だけである。
 *
 * 実スキーマとの両方向の集合等価は
 * tests/Feature/Retention/RetentionTableClassificationTest.php が deny-by-default で強制する。
 */
final class RetentionTableRegistry
{
    /**
     * 宣言の並び (**表名をキーにした連想配列にしない**)。
     *
     * 連想配列にすると同じ表を 2 回書いても後の 1 件で上書きされ、**二重宣言が消えてしまう**。
     * 並びのまま返し、キー化と二重宣言の検出は gate 側の純関数が行う。
     *
     * @return list<RetentionTableEntry>
     */
    public static function entries(): array
    {
        return [
            // --- 課金取引の記録 (7 年。年数と実処理の正本は BillingRetentionTarget) ---
            RetentionTableEntry::billingRecord(
                'subscriptions',
                '継続課金契約そのものの取引記録。契約終了日を起算点にして保持年数の満了で消す対象である',
            ),
            RetentionTableEntry::billingRecord(
                'subscription_items',
                '継続課金の明細 (価格と数量) の取引記録。親契約の終了日を起算点にする子の記録である',
            ),
            RetentionTableEntry::billingRecord(
                'stripe_webhook_events',
                '決済事業者からの通知そのものの記録。処理完了の時点をもって取引の決着とみなす課金記録である',
            ),
            RetentionTableEntry::billingRecord(
                'billing_checkout_sessions',
                '継続課金の申込手続きの記録。完了時刻が取引の成立日時になる課金記録である',
            ),
            RetentionTableEntry::billingRecord(
                'ticket_checkout_sessions',
                'チケット買い切り購入の決済手続きの記録。完了時刻が取引の成立日時になる課金記録である',
            ),
            RetentionTableEntry::billingRecord(
                'ticket_auto_recharge_attempts',
                '自動買い足しの課金試行の記録。決着時刻をもって取引の終了とみなす課金記録である',
            ),
            RetentionTableEntry::billingRecord(
                'ticket_ledger_entries',
                'チケット残高の取引台帳。追記しか行わないため物理削除ではなく繰越で決着させる課金記録である',
            ),

            // --- 定期実行が消す ---
            RetentionTableEntry::scheduledDeletion(
                'inquiries',
                '公開問い合わせの本文と連絡先を保持する。閉じた行と迷惑と判定した行を保持日数の超過で消す',
                PurgeInquiriesCommand::class,
                'inquiry:purge',
            ),
            RetentionTableEntry::scheduledDeletion(
                'idempotency_keys',
                '同じ要求の二重処理を防ぐための鍵の記録。保持時間を過ぎた行を日次で物理削除する',
                IdempotencyRetention::class,
                'idempotency:prune',
            ),
            RetentionTableEntry::scheduledDeletion(
                'mcp_idempotency_keys',
                'MCP 側の同じ要求の二重処理を防ぐ鍵の記録。保持時間を過ぎた行を同じ日次実行で消す',
                IdempotencyRetention::class,
                'idempotency:prune',
            ),
            RetentionTableEntry::scheduledDeletion(
                'take_upload_reservations',
                '撮影テイクのアップロード予約。解放済みと登録済みの行を保持日数の超過で物理削除する',
                PurgeUploadReservationsCommand::class,
                'capture:purge-upload-reservations',
            ),
            RetentionTableEntry::scheduledDeletion(
                'users',
                '利用者アカウント。**退会予約が入った行だけ**が猶予期間の経過後に物理削除される '
                .'(予約の無い行に期限は無い = 表の中で行ごとに寿命が違う)',
                AccountDeletionGrace::class,
                'account:purge-deletion-requests',
            ),

            // --- 親と一緒に消える ---
            RetentionTableEntry::deletedWithParent(
                'custom_teams',
                '組織の中のチーム。所属先の組織が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'projects',
                'チームに属する案件。所属先のチームが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'organization_user',
                '組織と利用者の所属関係。組織か利用者のどちらかが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'project_members',
                '案件と利用者の参加関係。案件か利用者のどちらかが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'organization_invitations',
                '組織へのメンバー招待。招待元の組織が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'role_user',
                '利用者への役割の割り当て。役割かチームが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'permission_user',
                '利用者への権限の直接付与。権限かチームが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'social_accounts',
                '外部の認証事業者と利用者の結び付き。利用者が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'passkeys',
                'パスキーの公開鍵と識別子。持ち主の利用者が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'api_keys',
                '機械向けの API 鍵。発行元の組織が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'notifications',
                '画面に出す組織向けの通知。宛先の組織が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'items',
                '案件に属する見本のリソース。所属先の案件が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'categories',
                '案件の中の分類。所属先の案件が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'video_manuals',
                '動画マニュアル本体。所属先の案件が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'source_documents',
                '取り込んだ作業手順書。元になった動画マニュアルが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'cuts',
                '撮影の単位となるカット。所属先の動画マニュアルが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'takes',
                '撮影したテイクの記録。所属先のカットが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'analysis_jobs',
                '手順書の解析ジョブの記録。対象の動画マニュアルが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'render_jobs',
                '動画合成ジョブの記録。対象の動画マニュアルが消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'ticket_reservations',
                'チケットの予約。予約元の組織が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'ticket_auto_recharges',
                'チケットの自動買い足しの設定。設定元の組織が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'organization_quotas',
                '組織ごとの利用上限。対象の組織が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'billing_notifications',
                '課金に関する通知の送信記録。宛先の組織が消えると連鎖削除で一緒に消える',
            ),
            RetentionTableEntry::deletedWithParent(
                'blind_indexes',
                '暗号化した列を検索するための索引。外部キーを持たず、CipherSweet の観測者が '
                .'親モデルの Eloquent 削除に合わせて同じ索引行を消す (連動はアプリ側にある)',
                ModelObserver::class,
            ),

            // --- 基準データ (期限を持たない) ---
            RetentionTableEntry::referenceData(
                'plans',
                '提供する料金プランの定義。個人には紐づかず、運用者が入れ替えるまで残る',
            ),
            RetentionTableEntry::referenceData(
                'plan_prices',
                '料金プランごとの価格の定義。個人には紐づかず、運用者が入れ替えるまで残る',
            ),
            RetentionTableEntry::referenceData(
                'ticket_volume_prices',
                'チケットのまとめ買いの価格表。個人には紐づかず、運用者が入れ替えるまで残る',
            ),
            RetentionTableEntry::referenceData(
                'roles',
                '権限の役割の定義。個人には紐づかず、運用者が入れ替えるまで残る',
            ),
            RetentionTableEntry::referenceData(
                'permissions',
                '個々の権限の定義。個人には紐づかず、運用者が入れ替えるまで残る',
            ),
            RetentionTableEntry::referenceData(
                'permission_role',
                '役割と権限の対応。どちらも基準データであり、運用者が入れ替えるまで残る',
            ),

            // --- 基盤が寿命を持つ ---
            RetentionTableEntry::frameworkManaged(
                'migrations',
                'データベースの構造変更の適用記録。フレームワークが構造の更新のたびに書き足す',
            ),
            RetentionTableEntry::frameworkManaged(
                'cache',
                '一時的な計算結果の置き場。各項目の有効期限をキャッシュの実装が持つ',
            ),
            RetentionTableEntry::frameworkManaged(
                'cache_locks',
                '排他制御のための一時的な鍵。保持時間の満了で解放されるようキャッシュの実装が管理する',
            ),
            RetentionTableEntry::frameworkManaged(
                'jobs',
                '実行待ちの処理の行列。取り出して実行し終えた時点でキューの実装が消す',
            ),
            RetentionTableEntry::frameworkManaged(
                'job_batches',
                'まとめて実行する処理の進捗。キューの実装が一括処理の完了とともに扱う',
            ),
            RetentionTableEntry::frameworkManaged(
                'failed_jobs',
                '失敗した処理の記録。フレームワークの掃除コマンドで運用者が取り除く',
            ),
            RetentionTableEntry::frameworkManaged(
                'sessions',
                'ログイン中の利用者の一時的な状態。セッションの有効期限の設定が寿命を決める',
            ),
            RetentionTableEntry::frameworkManaged(
                'password_reset_tokens',
                'パスワード再設定の一時的な合言葉。発行から短時間で期限切れになる仕組みが寿命を決める',
            ),

            // --- 未確定 (隠さずここへ載せる。件数と表名は gate が現在値ちょうどで pin する) ---
            RetentionTableEntry::undecided(
                'organizations',
                '組織そのもの。連鎖削除の親を持たず、組織の行を消す経路が app 配下に 1 つも無い。'
                .'契約終了後にこの行をいつ消すか (あるいは残すか) が未決である',
            ),
            RetentionTableEntry::undecided(
                'teams',
                '権限判定の適用範囲を表す行。外部キーを 1 本も持たず、行を消す経路も app 配下に無い。'
                .'組織の決着が決まるまでこの表の期限も決まらない',
            ),
            RetentionTableEntry::undecided(
                'llm_call_logs',
                'AI 呼び出しの費用と件数の記録。組織と利用者への外部キーが空値化のため、'
                .'退会や組織の削除の後も行が残る。費用分析に必要な期間が未決である',
            ),
            RetentionTableEntry::undecided(
                'security_audit_events',
                '認証と権限に関わる操作の証跡。利用者への外部キーが空値化のため退会後も行が残る。'
                .'監査に必要な保持期間が未決である',
            ),
            RetentionTableEntry::undecided(
                'model_audits',
                'モデルの変更履歴の証跡。多態の関連で外部キーを持たないため親の削除に連動しない。'
                .'保持期間が未決である',
            ),
            RetentionTableEntry::undecided(
                'email_suppressions',
                '送達に失敗したメールアドレスの抑制一覧。アドレスそのものを保持するが、'
                .'抑制を続ける必要期間が未決である',
            ),
            RetentionTableEntry::undecided(
                'admin_users',
                '運用者アカウント。外部キーを持たず、退任したときの扱いが手順として決まっていない',
            ),
            RetentionTableEntry::undecided(
                'oauth_access_tokens',
                '機械向けの接続に使う利用資格。失効と期限切れの行を誰がいつ消すかが未決である',
            ),
            RetentionTableEntry::undecided(
                'oauth_refresh_tokens',
                '利用資格を取り直すための合言葉。失効と期限切れの行を誰がいつ消すかが未決である',
            ),
            RetentionTableEntry::undecided(
                'oauth_auth_codes',
                '利用資格と引き換える短命の合言葉。使用済みと期限切れの行を誰がいつ消すかが未決である',
            ),
            RetentionTableEntry::undecided(
                'oauth_device_codes',
                '入力端末を持たない機器向けの合言葉。使用済みと期限切れの行を誰がいつ消すかが未決である',
            ),
            RetentionTableEntry::undecided(
                'oauth_clients',
                '接続してくる機械側の登録。廃止した登録をいつ消すかが未決である',
            ),
            RetentionTableEntry::undecided(
                'oauth_sessions',
                '機械向け接続の許諾の記録。利用者と組織の削除には連鎖するが、'
                .'生きている利用者の失効済みの許諾を誰がいつ消すかが未決である',
            ),
        ];
    }
}
