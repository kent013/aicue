<?php

declare(strict_types=1);

namespace Tests\Support\InitialState;

/**
 * 実スキーマの「nullable かつ DB 既定値を持たない」列のうち、
 * 時刻型の列と BackedEnum へ cast された列を全数分類した台帳。
 *
 * ★**除外一覧を持たない**。母集団に入った列は必ずここに現れる
 *   (除外の口を作ると、そこへ名前を足すだけで検査から逃げられる)。
 *
 * ★区分は「その行が生まれた時点で、この列は必ず NULL か」の**1 つの問い**で決まる。
 *   必ず NULL なら「初期状態の目印」、生成時に値が入りうるなら「生成時に決まりうる値」、
 *   読んでも決められないなら「未確定」へ載せる (隠さない)。
 *
 * ★**DB 既定値を持つ状態列はここに載らない**。そちらは AGENTS.md ドメイン固有規約 1 (ii) /
 *   規約 2 と tests/Architecture/ScenarioWritePathInventoryTest.php の担当であり、
 *   同じ事実を 2 か所で検査しない。
 *
 * 実スキーマとの両方向の集合一致は
 * tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php が
 * deny-by-default で強制する (保証しないものの正本も同ファイルの docblock)。
 */
final class NullableStateColumnRegistry
{
    /**
     * 宣言の並び (**`表名.列名` をキーにした連想配列にしない**)。
     *
     * 連想配列にすると同じ列を 2 回書いても後の 1 件で上書きされ、**二重宣言が消えてしまう**。
     * 並びのまま返し、キー化と二重宣言の検出は gate 側の純関数が行う。
     *
     * @return list<NullableStateColumnEntry>
     */
    public static function entries(): array
    {
        return [
            // --- AI 解析 / レンダの進行段階 (状態語彙で「まだ / 済んだ」を表す) ---
            NullableStateColumnEntry::initialStateMarker(
                'analysis_jobs',
                'step',
                'AnalysisJobService::trigger() は行を作るとき status と関連しか代入せず、段階は '
                .'AnalysisPipeline の進捗書き込みが最初に入れる。NULL = まだどの段階にも入っていない',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'render_jobs',
                'step',
                'RenderJobService::trigger() は段階を代入せず、RenderPipeline の進捗書き込みが '
                .'最初に入れる。NULL = まだどの段階にも入っていない',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'render_jobs',
                'error_code',
                '失敗の分類はロック下の既存行へ書き戻される (RenderJobService::failJob)。'
                .'NULL = まだ失敗していない。既定値が付くと生まれた瞬間に失敗した行ができる',
            ),

            // --- API キー / OAuth セッション / パスキーの利用と失効 ---
            NullableStateColumnEntry::initialStateMarker(
                'api_keys',
                'last_used_at',
                'ApiKeyGuard が認証成功のたびに既存行へ打刻する。NULL = 発行後まだ一度も使われて '
                .'いない。既定値が付くと未使用のキーが使用済みに見える',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'api_keys',
                'revoked_at',
                '失効操作が既存行へ打刻し、以後の照合から外れる。NULL = まだ有効である。'
                .'既定値が付くと発行した瞬間に失効したキーができる',
            ),
            NullableStateColumnEntry::setAtCreation(
                'api_keys',
                'expires_at',
                'ApiKey の発行時に有効期限を決めて書き込む列で、NULL は無期限を意味する。'
                .'進行段階ではなく、行を作る側が決める属性である',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'oauth_sessions',
                'last_used_at',
                '接続が使われるたびに既存行へ打刻する。NULL = 接続を作った後まだ使われていない。'
                .'既定値が付くと未使用の接続が使用済みに見える',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'oauth_sessions',
                'revoked_at',
                '組織アクセスの失効の窓口がロック下の既存行へ打刻する。NULL = まだ有効な接続である。'
                .'既定値が付くと作った瞬間に失効した接続ができる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'passkeys',
                'last_used_at',
                'パスキーでの認証が成功するたびに既存行へ打刻する。NULL = 登録後まだ使われていない。'
                .'手段保持の判断材料になるため既定値を置いてはならない',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'oauth_device_codes',
                'user_approved_at',
                '機器認可の流れで利用者が承認した瞬間に既存行へ打刻される。NULL = まだ承認されて '
                .'いない。既定値が付くと発行した瞬間に承認済みの機器コードができる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'oauth_device_codes',
                'last_polled_at',
                '機器側が交換を試みるたびに既存行へ打刻される。NULL = まだ一度も問い合わせが来て '
                .'いない。既定値が付くと問い合わせの間隔の判断が狂う',
            ),
            NullableStateColumnEntry::setAtCreation(
                'oauth_device_codes',
                'expires_at',
                '機器コードの発行時に有効期限を決めて書き込む列である。'
                .'進行段階ではなく、発行する側が決める属性である',
            ),
            NullableStateColumnEntry::setAtCreation(
                'oauth_access_tokens',
                'expires_at',
                'アクセス手形の発行時に有効期限を決めて書き込む列である。'
                .'進行段階ではなく、発行する側が決める属性である',
            ),
            NullableStateColumnEntry::setAtCreation(
                'oauth_refresh_tokens',
                'expires_at',
                '更新用手形の発行時に有効期限を決めて書き込む列である。'
                .'進行段階ではなく、発行する側が決める属性である',
            ),
            NullableStateColumnEntry::setAtCreation(
                'oauth_auth_codes',
                'expires_at',
                '認可コードの発行時に有効期限を決めて書き込む列である。'
                .'進行段階ではなく、発行する側が決める属性である',
            ),

            // --- 組織 / 招待 / 利用者の節目 ---
            NullableStateColumnEntry::initialStateMarker(
                'organization_invitations',
                'accepted_at',
                '招待が受諾されるまで NULL で、受諾の瞬間にロック下の既存行へ一度だけ時刻が入る。'
                .'既定値が付くと発行した招待がすべて受諾済みになる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'organization_invitations',
                'revoked_at',
                '招待の取り消し操作が既存行へ打刻する。NULL = まだ取り消されていない。'
                .'既定値が付くと発行した瞬間に取り消された招待ができる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'organizations',
                'deleted_at',
                '論理削除の打刻であり、組織を作る時点では必ず NULL である。'
                .'既定値が付くと新しい組織が生まれた瞬間に削除済みとして扱われる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'organizations',
                'free_plan_activated_at',
                '個人プランの有効化がロック下の既存行へ打刻する。NULL = まだ有効化していない。'
                .'既定値が付くと契約していない組織が無料枠を持つ',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'organizations',
                'personal_declared_at',
                '個人利用の申告がロック下の既存行へ打刻する。NULL = まだ申告していない。'
                .'既定値が付くと申告していない組織が申告済みに見える',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'organizations',
                'signup_tickets_granted_at',
                '初回付与の重複防止の目印で、付与を確定した経路が既存行へ条件付きで打刻する。'
                .'NULL = まだ付与していない。既定値が付くと初回付与が永久に行われなくなる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'organizations',
                'stripe_customer_redacted_at',
                '決済事業者側の顧客情報を伏せた記録で、専用コマンドが既存行へ打刻する。'
                .'NULL = まだ伏せていない。既定値が付くと伏せていない組織が処理済みに見える',
            ),
            NullableStateColumnEntry::setAtCreation(
                'organizations',
                'trial_ends_at',
                '課金基盤が持つ試用期限の写しで、行を作る側が期限を決めて書き込む。'
                .'NULL は試用が設定されていないことを意味し、進行段階ではない',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'users',
                'two_factor_confirmed_at',
                '第二要素の登録を利用者が確認した瞬間に既存行へ打刻される。NULL = 未確認である。'
                .'既定値が付くと第二要素を持たない利用者が確認済みになり必須組織の判定が壊れる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'users',
                'deletion_requested_at',
                '退会予約の受付時に既存行へ打刻する (会員登録時は必ず NULL)。'
                .'NULL = 退会予約が無い。既定値が付くと登録した瞬間に退会予約が入る',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'users',
                'deletion_purge_after',
                '退会予約と同時に書かれる執行期限で、会員登録時は必ず NULL である。'
                .'既定値が付くと予約していない利用者が日次の物理削除の対象になりうる',
            ),
            NullableStateColumnEntry::setAtCreation(
                'users',
                'email_verified_at',
                'SSO 登録は身元提供者が検証した連絡先として生成時に時刻を書き込むため、'
                .'行が生まれた時点で必ず NULL とは言えない (通常の登録経路では NULL で始まる)',
            ),
            NullableStateColumnEntry::setAtCreation(
                'users',
                'terms_accepted_at',
                '会員登録の経路がいずれも同意時刻を生成時に書き込む。'
                .'進行段階ではなく、行を作る側が決める属性である',
            ),

            // --- 問い合わせ ---
            NullableStateColumnEntry::initialStateMarker(
                'inquiries',
                'closed_at',
                '状態が「対応済み」へ変わる更新のときだけ既存行へ打刻される (新規は打刻しない)。'
                .'NULL = まだ対応が終わっていない。既定値が付くと届いた瞬間に対応済みになる',
            ),
            NullableStateColumnEntry::setAtCreation(
                'inquiries',
                'terms_accepted_at',
                '問い合わせを作る行為そのものが同意なので、生成時に同意時刻を書き込む。'
                .'進行段階ではなく、行を作る側が決める属性である',
            ),
            NullableStateColumnEntry::setAtCreation(
                'inquiries',
                'source',
                '問い合わせがどの導線から届いたかを生成時に固定する分類である。'
                .'NULL は導線を記録していない古い行を意味し、進行段階ではない',
            ),

            // --- アプリ内通知 ---
            NullableStateColumnEntry::initialStateMarker(
                'notifications',
                'read_at',
                '既読操作が既存行へ打刻する。NULL = まだ読まれていない。'
                .'既定値が付くと届いた通知がすべて既読になり通知センターが機能しなくなる',
            ),

            // --- 決済事業者からの通知 ---
            NullableStateColumnEntry::initialStateMarker(
                'stripe_webhook_events',
                'processed_at',
                '終局の書き込みが条件付き更新で既存行へ打刻する。NULL = まだ処理していない。'
                .'既定値が付くと届いた通知が処理済みになり業務が丸ごと落ちる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'stripe_webhook_events',
                'recovery_reason',
                '滞留回収が拾い直した理由を既存行へ書く列で、受信時は必ず NULL である。'
                .'NULL = 回収の対象になっていない。既定値が付くと回収の観測が壊れる',
            ),

            // --- 継続課金の契約 ---
            NullableStateColumnEntry::initialStateMarker(
                'subscriptions',
                'past_due_since',
                '支払い遅延を観測した時刻で、書き込むのはロック下の既存行を更新する 1 箇所だけである。'
                .'NULL = 遅延していない。既定値が付くと契約した瞬間に猶予が始まる',
            ),
            NullableStateColumnEntry::setAtCreation(
                'subscriptions',
                'ends_at',
                '決済事業者の状態の写しを取り込む経路が解約予定日として書き込む値で、'
                .'生成時に非 NULL がありうる。NULL は解約予定が無いことを意味する',
            ),
            NullableStateColumnEntry::setAtCreation(
                'subscriptions',
                'trial_ends_at',
                '決済事業者の状態の写しを取り込む経路が試用期限として書き込む値で、'
                .'生成時に非 NULL がありうる。NULL は試用が無いことを意味する',
            ),
            NullableStateColumnEntry::setAtCreation(
                'subscriptions',
                'current_period_end',
                '決済事業者の状態の写しを取り込む経路が現在の期間の終わりとして書き込む値で、'
                .'生成時に非 NULL がありうる。進行段階ではなく外部が決めた値の写しである',
            ),

            // --- 契約手続き / チケット購入手続き ---
            NullableStateColumnEntry::initialStateMarker(
                'billing_checkout_sessions',
                'completed_at',
                '決済確定の通知を受けたときに既存行へ打刻する。NULL = まだ確定していない。'
                .'既定値が付くと申込を作った瞬間に成立した取引ができる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'billing_checkout_sessions',
                'pm_reuse_dispatched_at',
                '支払い手段の流用処理を投入したことの目印で、確定後に既存行へ打刻する。'
                .'NULL = まだ投入していない。既定値が付くと投入されないまま済んだことになる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'ticket_checkout_sessions',
                'completed_at',
                'チケット購入の決済確定を受けたときに既存行へ打刻する。NULL = まだ確定していない。'
                .'既定値が付くと購入手続きを作った瞬間に成立した取引ができる',
            ),

            // --- 課金の通知 ---
            NullableStateColumnEntry::initialStateMarker(
                'billing_notifications',
                'sent_at',
                '送信に成功したときだけ既存行へ打刻する条件付き更新である。NULL = まだ送っていない。'
                .'既定値が付くと予定した通知がすべて送信済みになり実際には届かなくなる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'billing_notifications',
                'failed_at',
                '送信に失敗したときだけ既存行へ打刻する。NULL = まだ失敗していない。'
                .'既定値が付くと予定した通知が最初から失敗扱いになる',
            ),

            // --- オートリチャージ ---
            NullableStateColumnEntry::setAtCreation(
                'ticket_auto_recharges',
                'consented_at',
                '同意の受付が設定行そのものを生成しながら同意時刻を書き込む。'
                .'進行段階ではなく、行を作る側が決める属性である',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'ticket_auto_recharges',
                'disabled_reason',
                '連続失敗などで自動購入を止めたときに既存行へ書く理由である。'
                .'NULL = 止められていない。既定値が付くと同意した瞬間に停止した設定ができる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'ticket_auto_recharge_attempts',
                'resolved_at',
                '自動購入の試行が決着したときに既存行へ打刻する。NULL = まだ決着していない。'
                .'既定値が付くと試行を作った瞬間に決着済みになり二重購入の防止が壊れる',
            ),

            // --- チケット台帳と予約 ---
            NullableStateColumnEntry::setAtCreation(
                'ticket_ledger_entries',
                'granted_at',
                '付与の行は生成時に付与時刻を書き込み、繰越の行は明示的に NULL で作る。'
                .'NULL は付与ではない行を意味し、進行段階ではない',
            ),
            NullableStateColumnEntry::setAtCreation(
                'ticket_ledger_entries',
                'expires_at',
                '台帳の行を作る時点で有効期限を決めて書き込む。'
                .'NULL は無期限の残高を意味し、進行段階ではない',
            ),
            NullableStateColumnEntry::setAtCreation(
                'ticket_ledger_entries',
                'source',
                '残高の出所を台帳の行の生成時に固定する分類である。'
                .'NULL は出所を記録していない古い行を意味し、進行段階ではない',
            ),
            NullableStateColumnEntry::setAtCreation(
                'ticket_reservations',
                'consume_source',
                '予約の生成時に「どの出所から消費するか」を固定する (予約の保存前に代入する)。'
                .'NULL は出所を固定していない古い行を意味し、進行段階ではない',
            ),
            NullableStateColumnEntry::setAtCreation(
                'ticket_reservations',
                'consume_expires_at',
                '予約の生成時に「どの期限の残高から消費するか」を固定する。'
                .'NULL は無期限の残高からの消費を意味し、進行段階ではない',
            ),

            // --- 料金表 (現行世代と同期状態) ---
            NullableStateColumnEntry::initialStateMarker(
                'plan_prices',
                'active_to',
                '新しい価格へ置き換えるときに旧行へ打刻する終了時刻で、作るときは明示的に NULL である。'
                .'NULL = まだ現行である。既定値が付くと作った瞬間に失効した価格ができる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'plan_prices',
                'synced_at',
                '決済事業者との同期が済んだ既存行へ打刻する。NULL = まだ同期していない。'
                .'本番では未同期の価格を弾く判断に使うため、既定値を置いてはならない',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'ticket_volume_prices',
                'active_to',
                '新しい価格帯へ置き換えるときに旧行へ打刻する終了時刻で、作るときは NULL である。'
                .'NULL = まだ現行である。既定値が付くと作った瞬間に失効した価格帯ができる',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'ticket_volume_prices',
                'synced_at',
                '決済事業者との同期が済んだ既存行へ打刻する。NULL = まだ同期していない。'
                .'本番では未同期の価格帯を弾く判断に使うため、既定値を置いてはならない',
            ),

            // --- 撮影テイクとカット ---
            NullableStateColumnEntry::initialStateMarker(
                'takes',
                'downloaded_at',
                '取り出しの操作がロック下の既存行へ打刻する。NULL = まだ取り出していない。'
                .'既定値が付くと撮ったばかりのテイクが取り出し済みに見える',
            ),
            NullableStateColumnEntry::setAtCreation(
                'takes',
                'captured_at',
                '撮影端末が申告した撮影時刻を、テイクの登録時にそのまま書き込む。'
                .'NULL は端末が時刻を申告しなかったことを意味し、進行段階ではない',
            ),
            // --- 企業 SSO (T253) ---
            NullableStateColumnEntry::initialStateMarker(
                'organization_oidc_connections',
                'verified_at',
                '接続先情報の取得に成功したときだけロック下の既存行へ打刻し、認証材料を'
                .'更新したら消す。NULL = まだ一度も確認できていない (既定値が付くと'
                .'登録した瞬間に確認済みの接続ができる)',
            ),
            NullableStateColumnEntry::initialStateMarker(
                'enterprise_identities',
                'last_login_at',
                '企業ログインが確定するたびに既存行へ打刻する。NULL = 身元は作られたが'
                .'まだ一度もログインが確定していない',
            ),

            NullableStateColumnEntry::setAtCreation(
                'cuts',
                'material_type',
                'シナリオを反映するときにカットの素材の種類として生成時に書き込む。'
                .'NULL は種類が決まっていないカットを意味し、進行段階ではない',
            ),
        ];
    }
}
