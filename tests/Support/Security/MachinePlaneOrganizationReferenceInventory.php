<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 機械経路の入口ごとの「組織をどう確定するか」の台帳 (家系裁定 AG-047 / 不変条件 I14)。
 *
 * ★**キーが入口の唯一の SoT** である (解決点 DTO は入口 ID を持たない)。
 * ★deny-by-default。`MachinePlaneEntryPoints::all()` と**完全一致**していなければ赤になる
 *   (未登録・余剰・重複のどれでも落ちる)。
 * ★`NotOrganizationScoped` は「解決点が 0 件であることを**検査した**」という宣言であり、
 *   理由 30 文字以上を gate が強制する。
 * ★**全組織を走査する定期実行は `NotOrganizationScoped`** である。外部入力で組織を
 *   選ばないので「組織を指す参照」が存在しない (I14 が問題にするのは指し方である)。
 */
final class MachinePlaneOrganizationReferenceInventory
{
    /**
     * @return array<string, MachinePlaneEntryClassification>
     */
    public static function all(): array
    {
        return [
            // ── api (組織は API キー / OAuth token の帰属から確定する) ─────────────
            'api:api.v1.version' => new NotOrganizationScoped(
                '未認証の公開エンドポイント。API 互換性の宣言だけを返し、組織に属するデータを一切読まない。'
            ),
            'api:api.v1.me' => new OrganizationScoped([
                new OrganizationResolutionPoint('show:organization', OrganizationReferenceProvenance::ActorDerived),
            ]),
            'api:api.v1.me.session.revoke' => new OrganizationScoped([
                new OrganizationResolutionPoint('destroy:organization', OrganizationReferenceProvenance::ActorDerived),
            ]),
            'api:api.v1.projects.index' => new OrganizationScoped([
                new OrganizationResolutionPoint('index:organization', OrganizationReferenceProvenance::ActorDerived),
            ]),
            'api:api.v1.projects.show' => new OrganizationScoped([
                new OrganizationResolutionPoint('show:organization', OrganizationReferenceProvenance::ActorDerived),
                new OrganizationResolutionPoint('show:project', OrganizationReferenceProvenance::RelationScoped, 'show:organization'),
            ]),
            'api:api.v1.projects.items.index' => new OrganizationScoped([
                new OrganizationResolutionPoint('index:organization', OrganizationReferenceProvenance::ActorDerived),
                new OrganizationResolutionPoint('index:project', OrganizationReferenceProvenance::RelationScoped, 'index:organization'),
            ]),
            'api:api.v1.projects.items.store' => new OrganizationScoped([
                new OrganizationResolutionPoint('store:organization', OrganizationReferenceProvenance::ActorDerived),
                new OrganizationResolutionPoint('store:project', OrganizationReferenceProvenance::RelationScoped, 'store:organization'),
            ]),
            'api:api.v1.projects.items.update' => new OrganizationScoped([
                new OrganizationResolutionPoint('update:organization', OrganizationReferenceProvenance::ActorDerived),
                new OrganizationResolutionPoint('update:project', OrganizationReferenceProvenance::RelationScoped, 'update:organization'),
                new OrganizationResolutionPoint('update:item', OrganizationReferenceProvenance::RelationScoped, 'update:project'),
            ]),
            'api:api.v1.projects.items.destroy' => new OrganizationScoped([
                new OrganizationResolutionPoint('destroy:organization', OrganizationReferenceProvenance::ActorDerived),
                new OrganizationResolutionPoint('destroy:project', OrganizationReferenceProvenance::RelationScoped, 'destroy:organization'),
                new OrganizationResolutionPoint('destroy:item', OrganizationReferenceProvenance::RelationScoped, 'destroy:project'),
            ]),

            // ── mcp (組織は access token の帰属 = consent から確定する) ────────────
            'mcp:whoami' => new OrganizationScoped([
                new OrganizationResolutionPoint('handle:organization', OrganizationReferenceProvenance::ActorDerived),
            ]),
            'mcp:list-projects' => new OrganizationScoped([
                new OrganizationResolutionPoint('handle:organization', OrganizationReferenceProvenance::ActorDerived),
            ]),
            'mcp:show-project' => new OrganizationScoped([
                new OrganizationResolutionPoint('handle:organization', OrganizationReferenceProvenance::ActorDerived),
                new OrganizationResolutionPoint('handle:project', OrganizationReferenceProvenance::RelationScoped, 'handle:organization'),
            ]),
            'mcp:list-items' => new OrganizationScoped([
                new OrganizationResolutionPoint('handle:organization', OrganizationReferenceProvenance::ActorDerived),
                new OrganizationResolutionPoint('handle:project', OrganizationReferenceProvenance::RelationScoped, 'handle:organization'),
            ]),

            // ── filament (組織は {record} の内部主キーから確定する) ────────────────
            'filament:resource:App\Filament\Resources\OrganizationResource' => new OrganizationScoped([
                new OrganizationResolutionPoint('record', OrganizationReferenceProvenance::PrimaryKeyBinding),
            ]),
            'filament:resource:App\Filament\Resources\AdminUserResource' => new NotOrganizationScoped(
                '管理コンソールの管理者アカウントを扱う面。AdminUser は組織に属さない別の主体である。'
            ),
            'filament:resource:App\Filament\Resources\InquiryResource' => new NotOrganizationScoped(
                '公開問い合わせフォームの受信箱。問い合わせは未認証の来訪者から来るので組織に属さない。'
            ),
            'filament:resource:App\Filament\Resources\LlmCallLogResource' => new NotOrganizationScoped(
                'LLM 呼び出しの記録を横断で読む面。組織を選ぶ入力を持たず、全件を一覧・集計するだけである。'
            ),
            'filament:resource:App\Filament\Resources\ModelAuditResource' => new NotOrganizationScoped(
                'モデル監査の記録を横断で読む面。組織を選ぶ入力を持たず、全件を一覧するだけである。'
            ),
            'filament:resource:App\Filament\Resources\PlanResource' => new NotOrganizationScoped(
                '料金プランのマスタ。プランは組織に属さない全体設定であり、組織を選ぶ入力を持たない。'
            ),
            'filament:resource:App\Filament\Resources\SecurityAuditEventResource' => new NotOrganizationScoped(
                'セキュリティ監査の記録を横断で読む面。組織を選ぶ入力を持たず、全件を一覧するだけである。'
            ),
            'filament:resource:App\Filament\Resources\UserResource' => new NotOrganizationScoped(
                '利用者アカウントの面。組織を選ぶ入力を持たず、利用者そのものを主キーで開くだけである。'
            ),

            // ── console (組織を選ぶのは内部 id の引数だけ。定期実行は全件走査) ──────
            'console:billing:mark-stripe-customer-redacted' => new OrganizationScoped([
                new OrganizationResolutionPoint('handle:organization-id', OrganizationReferenceProvenance::PrimaryKeyBinding),
            ]),
            'console:dev:pipeline-smoke' => new OrganizationScoped([
                new OrganizationResolutionPoint('handle:organization-id', OrganizationReferenceProvenance::PrimaryKeyBinding),
                new OrganizationResolutionPoint('handle:project', OrganizationReferenceProvenance::RelationScoped, 'handle:organization-id'),
            ]),
            'console:account:purge-deletion-requests' => new NotOrganizationScoped(
                '期限の来た退会予約を全件走査して執行する定期実行。組織を選ぶ外部入力を持たない。'
            ),
            'console:billing:detect-orphan-billing-organizations' => new NotOrganizationScoped(
                'Owner 不在かつ課金中の組織を全件走査で検知して報告する。組織を選ぶ外部入力を持たない。'
            ),
            'console:billing:ensure-portal-configuration' => new NotOrganizationScoped(
                '決済事業者側の Portal 設定を宣言どおりに揃える。アプリの組織を 1 件も読まない。'
            ),
            'console:billing:purge-retention-expired' => new NotOrganizationScoped(
                '保持期限の切れた課金記録を全件走査で削除する定期実行。組織を選ぶ外部入力を持たない。'
            ),
            'console:billing:reconcile-auto-recharge' => new NotOrganizationScoped(
                '滞留したオートリチャージ試行を全件走査で突き合わせる。組織を選ぶ外部入力を持たない。'
            ),
            'console:billing:reconcile-schedules' => new NotOrganizationScoped(
                '決済事業者側のスケジュールを全件走査で突き合わせる。組織を選ぶ外部入力を持たない。'
            ),
            'console:billing:reconcile-subscription-status' => new NotOrganizationScoped(
                '契約状態の射影を全件走査で突き合わせる定期実行。組織を選ぶ外部入力を持たない。'
            ),
            'console:billing:send-billing-reminders' => new NotOrganizationScoped(
                '更新予告の通知を全件走査で送る定期実行。組織を選ぶ外部入力を持たない。'
            ),
            'console:billing:sync-stripe-prices' => new NotOrganizationScoped(
                '決済事業者の価格をマスタへ同期する。プランは組織に属さない全体設定である。'
            ),
            'console:billing:verify-stripe-prices' => new NotOrganizationScoped(
                '決済事業者の価格とマスタの整合を確かめるだけ。組織を 1 件も読まない。'
            ),
            'console:bughunt:inventory-scan' => new NotOrganizationScoped(
                'route 表と実装の機械事実を走査して目録の素を出す。組織のデータを 1 件も読まない。'
            ),
            'console:help:build' => new NotOrganizationScoped(
                'ヘルプ文書を取り込んで生成物へ書き出すだけ。組織のデータを 1 件も読まない。'
            ),
            'console:capture:purge-upload-reservations' => new NotOrganizationScoped(
                '期限切れのアップロード予約を全件走査で解放する定期実行。組織を選ぶ外部入力を持たない。'
            ),
            'console:admin:create' => new NotOrganizationScoped(
                '管理コンソールの管理者を作る。AdminUser は組織に属さない別の主体である。'
            ),
            'console:admin:reset-mfa' => new NotOrganizationScoped(
                '管理者の多要素認証を解除する。AdminUser は組織に属さない別の主体である。'
            ),
            'console:cli:client' => new NotOrganizationScoped(
                'CLI 用の OAuth クライアントを作る。クライアントは組織ではなくアプリ全体に属する。'
            ),
            'console:auth:prune-email-promotions' => new NotOrganizationScoped(
                '期限切れのメール昇格の確認待ちを期限だけで削除する定期実行。組織を選ぶ外部入力を持たない。'
            ),
            'console:enterprise-sso:prune-login-attempts' => new NotOrganizationScoped(
                '期限切れの企業 SSO ログイン試行を期限だけで削除する定期実行。組織を選ぶ外部入力を持たない。'
            ),
            'console:idempotency:prune' => new NotOrganizationScoped(
                '期限切れの冪等キーを全件走査で削除する定期実行。組織を選ぶ外部入力を持たない。'
            ),
            'console:inquiry:purge' => new NotOrganizationScoped(
                '保持期限の切れた問い合わせを削除する。問い合わせは組織に属さない。'
            ),
            'console:inspire' => new NotOrganizationScoped(
                'フレームワーク同梱の見本 command を framework へ登録し直しただけ。データを一切読まない。'
            ),
            'console:mail:unsuppress' => new NotOrganizationScoped(
                '配信抑止リストから宛先を外す。抑止はメールアドレス単位で組織に属さない。'
            ),
            'console:operations:check-mail-config' => new NotOrganizationScoped(
                'メール設定の健全性を確かめるだけ。アプリのデータを 1 件も読まない。'
            ),
            'console:operations:llm-cost-report' => new NotOrganizationScoped(
                'LLM の費用を横断で集計して出力する。組織を選ぶ外部入力を持たず全件を集計する。'
            ),
            'console:production:preflight' => new NotOrganizationScoped(
                '本番投入前の設定検査。設定だけを見てアプリのデータを 1 件も読まない。'
            ),
            'console:work:recover-stuck' => new NotOrganizationScoped(
                '滞留した仕事を全件走査で回収する定期実行。組織を選ぶ外部入力を持たない。'
            ),
        ];
    }
}
