/**
 * PHP の文字列付き列挙の発見の段と逆走査 (家系の裁定 AG-099 後半 / T225)。
 *
 * `enum-ts-sync.test.ts` は「目録 (`ENUM_TS_MIRRORS`) に登録した写しだけ」を見る検査で、
 * 登録し忘れた PHP 列挙・TS 宣言は 1 件も検査していなかった (`docs/template-divergence.md`
 * の D29 が記録していた欠落)。本ファイルは向きを変え、次の 2 段で「登録し忘れ」を
 * **既定拒否 (deny-by-default)** で炙り出す。
 *
 * ## 1. 発見の段 (全数走査 → 既定拒否の分類)
 *
 * `buildPhpEnumCatalog()` が `app/` 配下の git 追跡下の `*.php` を全数走査し、
 * 値集合を読めた PHP の文字列付き列挙 (`resolved`) と、読めなかったもの (`unresolvable`)
 * に分ける。`resolved` の**すべて**が次のどちらか一方に分類されていることを固定する。
 *
 * - **登録済み** (`ENUM_TS_MIRRORS` に php パスがある)
 * - **対象外の理由つき** (`PHP_ENUM_EXEMPTIONS` に登録がある。TS 側に写しを作らない
 *   意図的な判断で、理由を 30 文字以上で書く)
 *
 * `unresolvable` の**すべて**が `KNOWN_UNRESOLVABLE_PHP_ENUMS` に登録されていることを
 * 固定する (本 gate 専用の字句走査器では値集合を読み切れないと分かっている残余)。
 *
 * どの分類にも入らない PHP 列挙が 1 件でもあれば赤くする (**既定拒否**)。
 * 逆に、分類の登録先が実際にはその分類でなくなった (stale) ときも赤くする
 * (登録が実態と食い違ったまま残るのを防ぐ)。
 *
 * ## 2. 逆走査 (未登録候補の検出。2 規則)
 *
 * `collectTsUnionCandidates()` が `resources/js/` 配下の文字列リテラル型だけの union に
 * 解決する型別名を全数走査し、`findUnregisteredMirrorCandidates()` が
 * 未登録 (`ENUM_TS_MIRRORS` に無い) の宣言を PHP の母集団と突き合わせて次の 2 規則で拾う。
 *
 * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全一致する未登録の宣言 = 登録漏れの疑い
 * - **規則 2 (名前対応 + 値の交差)**: 名前が厳密に対応し値が交差するが完全一致ではない
 *   未登録の宣言 = 片方だけ値を足してズレた写しの疑い
 *
 * 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS` に登録された分だけ許す
 * (意図的に登録しない判断を明示する)。未登録の候補が 1 件でもあれば赤くする。
 *
 * **保証しないもの (誇張しない)**:
 * - 名前も対応せず値も完全一致しない drift 済みの写しは検出できない (規則の意図した限界)
 * - 緩い名前対応 (部分集合・ファイル名を名前に混ぜる形) は採らない。実測 (家系の記録) で
 *   偽陽性が支配的になるため、名前対応は「一致 / +s / +es / +values」の厳密な形だけを見る
 * - `.svelte` の中の宣言・定数配列・switch の case ラベルは走査しない
 *   (`collectTsUnionCandidates` は `type X = …` のトップレベル宣言だけを見る。
 *   `.d.ts` も対象外)
 * - PHP 側の母集団は `php-enum-catalog.ts` の docblock が明記する範囲に限る
 *   (走査器が読み切れない字句を含むファイルは、生のソースに `enum` の語が
 *   無ければ母集団から外れる。あれば安全側に倒して `unresolvable` へ回る)
 *
 * 正本のレーンは `pnpm test`。詳細は `docs/architecture.md`
 * §PHP 列挙と TypeScript 値域の同期。
 */
import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { createMirrorProgram, REPO_ROOT, type MirrorProgram } from "../support/enum-ts-sync/program";
import { buildPhpEnumCatalog, type PhpEnumCatalog } from "../support/enum-ts-sync/php-enum-catalog";
import { collectTsUnionCandidates, type TsUnionCandidate } from "../support/enum-ts-sync/ts-candidates";
import { findUnregisteredMirrorCandidates } from "../support/enum-ts-sync/reverse-sweep";
import { ENUM_TS_MIRRORS, registeredPhpPaths, registeredTsKeys } from "../support/enum-ts-sync/mirror-inventory";

interface PhpEnumExemption {
    /** リポジトリルートからの PHP 列挙ファイルの相対パス。 */
    readonly path: string;
    /** TS 側に写しを作らない理由 (30 文字以上)。 */
    readonly reason: string;
}

/**
 * 「対象外の理由つき」に分類する PHP の文字列付き列挙。
 * ここに無く、かつ `ENUM_TS_MIRRORS` にも無い `resolved` エントリが 1 件でもあれば
 * 発見の段が赤くなる (既定拒否)。
 */
const PHP_ENUM_EXEMPTIONS = [
    { path: "app/DataTransferObjects/EnterpriseSso/VerifyOutcome.php", reason: "接続の確認の結果 4 値。運営画面へは値ではなく enum が持つ日本語の文言を渡すため、TS 側に値域の写しを要さない" },
    { path: "app/Enums/EnterpriseSso/ConnectionTransitionRejection.php", reason: "接続の管理操作を拒否した理由。画面へは値ではなく enum が持つ日本語の文言をエラーとして渡すため、TS 側で値による分岐をしない" },
    { path: "app/Enums/EnterpriseSso/FingerprintPurpose.php", reason: "一時値の指紋の用途ラベル (domain separation の実体)。サーバ内部の鍵導出にだけ使い、画面へは値も名前も渡らない" },
    { path: "app/Enums/EnterpriseSso/OidcSigningAlgorithm.php", reason: "ID トークン署名方式の許可集合。検証の内部判定にだけ使い、画面は接続の状態だけを見る (顧客が選ぶ項目ではない)" },
    { path: "app/Enums/EnterpriseSso/RejectionReason.php", reason: "企業 SSO の拒否理由の内部コード。利用者への応答は理由によらず一様であり、区別はログにしか出ない" },
    { path: "app/Enums/EnterpriseSso/TokenEndpointAuthMethod.php", reason: "token endpoint の client 認証方式。IdP の広告から選ぶ内部判定であり、顧客が入力も選択もしない" },
    { path: "app/Auth/Context/ApiActorKind.php", reason: "認証コンテキストの内部判別 (api_key/user_token)。ログと認可判定にのみ使い、画面へ値として渡さない" },
    { path: "app/DataTransferObjects/Manual/Render/RenderClipSource.php", reason: "レンダーパイプライン内部でクリップの取得元を表す区分。フロントは個別のフラグで結果を受け取り、この値そのものは渡らない" },
    { path: "app/Enums/Account/AccountDeletionFreezeAllowance.php", reason: "退会凍結中に許可する route 名相当の内部許可リスト。ガード判定にのみ使い、画面には表示しない" },
    { path: "app/Enums/AccountDeletionBlockReason.php", reason: "退会ブロックの内部理由コード。画面には理由ごとの案内文をサーバ側で確定して渡すだけである" },
    { path: "app/Enums/ApiErrorCode.php", reason: "公開 API のエラーコード語彙。TS 側はコードで分岐せず HTTP 状態とエラー文言だけを見る" },
    { path: "app/Enums/ApiKeyAbility.php", reason: "API キー権限 (read/write) の内部語彙。管理画面はチェックボックスの選択状態だけを見る" },
    { path: "app/Enums/Auth/AuthMethodChangeEvent.php", reason: "認証手段変更メール通知の内部分類 (T110)。件名・本文はサーバ側で確定して送るだけで画面へは一切渡らない" },
    { path: "app/Enums/Auth/EmailVerificationGateContext.php", reason: "メール確認ゲートの発生元コンテキスト。内部のルーティング判定にのみ使う語彙である" },
    { path: "app/Enums/Billing/AutoRechargeAttemptStatus.php", reason: "自動追加購入試行の内部状態機械。画面は結果の通知種別 (BillingFeedbackKind) 経由でしか見ない" },
    { path: "app/Enums/Billing/AutoRechargeDisabledReason.php", reason: "自動追加購入停止の内部理由。通知本文はサーバ側で文言を確定して送る" },
    { path: "app/Enums/Billing/BillingNotificationStatus.php", reason: "課金通知の配信状態 (queued/sent/failed) を表す内部語彙。画面には配信結果を見せない" },
    { path: "app/Enums/Billing/BillingNotificationType.php", reason: "課金通知バッチが内部で使う通知種別。画面に出る通知分類は BillingFeedbackKind 側の語彙である" },
    { path: "app/Enums/Billing/BillingReminderDispatchResult.php", reason: "リマインダー送信バッチの内部結果。運用ログにのみ残り画面へは出ない" },
    { path: "app/Enums/Billing/BillingRetentionExclusion.php", reason: "課金記録の保持期限からの除外対象を表す内部語彙 (D23)。バッチ処理の内部でのみ使う" },
    { path: "app/Enums/Billing/BillingRetentionTarget.php", reason: "課金記録の保持期限の対象を表す内部語彙 (D23)。バッチ処理の内部でのみ使う" },
    { path: "app/Enums/Billing/EntitlementDeniedReason.php", reason: "権利否認の内部理由。画面には否認された結果だけが渡り、理由コードは渡らない" },
    { path: "app/Enums/Billing/GatewayFailureClass.php", reason: "決済ゲートウェイ失敗の観測語彙 (ドメイン固有規約 7)。ログにのみ残り画面へは出ない" },
    { path: "app/Enums/Billing/HandledStripeWebhookEvent.php", reason: "処理対象にする Stripe webhook イベント名の内部許可リスト。サーバ内部の分岐にのみ使う" },
    { path: "app/Enums/Billing/PersonalPlanIneligibleReason.php", reason: "個人プランへの変更が不適格である内部理由。画面には可否だけが渡る" },
    { path: "app/Enums/Billing/PlanPriceKind.php", reason: "プラン価格の内部種別 (base/seat)。画面は金額と数量だけを見て種別コードは見ない" },
    { path: "app/Enums/Billing/ScheduleSetupStatus.php", reason: "定期発行スケジュール設定の内部状態機械。バッチ処理の内部でのみ使う" },
    { path: "app/Enums/Billing/SignupFundingChoice.php", reason: "サインアップ時の資金調達方式を表す内部の選択肢。オンボーディングの内部ロジックにのみ使う" },
    { path: "app/Enums/Billing/SubscriptionState.php", reason: "購読状態の内部状態機械。画面は OnboardingBillingState 経由でしか状態を見ない" },
    { path: "app/Enums/Billing/SubscriptionSwapOutcome.php", reason: "プラン変更処理の内部結果を表す。運用ログにのみ残り画面へは出ない" },
    { path: "app/Enums/Billing/TicketCheckoutSessionStatus.php", reason: "チケット購入セッションの内部状態。画面は購入完了/失敗の結果だけを見る" },
    { path: "app/Enums/Billing/TicketLedgerKind.php", reason: "チケット台帳の内部種別 (reserve/commit/release 等)。バッチと監査ログの内部でのみ使う" },
    { path: "app/Enums/Billing/TicketReservationStatus.php", reason: "チケット予約の内部状態 (reserve→commit/release の 2 フェーズ)。内部の排他制御にのみ使う" },
    { path: "app/Enums/Billing/TicketSource.php", reason: "チケット発行元 (月次/購入) の内部種別。台帳の内部集計にのみ使う" },
    { path: "app/Enums/Billing/WebhookEventStatus.php", reason: "webhook イベント処理の内部状態機械。運用ログにのみ残る" },
    { path: "app/Enums/Billing/WebhookRecoveryReason.php", reason: "webhook 再送理由の内部語彙。運用ログにのみ残り画面へは出ない" },
    { path: "app/Enums/Billing/WebhookReplaySafety.php", reason: "webhook 再送の安全性を表す内部判定。バッチ処理の内部でのみ使う" },
    { path: "app/Enums/Billing/WebhookStaleClaimOutcome.php", reason: "滞留 webhook の claim 処理結果を表す内部語彙。運用ログにのみ残る" },
    { path: "app/Enums/Capture/CaptureConflictType.php", reason: "撮影登録の競合種別を表す内部語彙。画面向けの衝突種別は Manual 側の ScenarioConflictType / AnalysisConflictType が別に持つ" },
    { path: "app/Enums/Capture/TakeUploadReservationStatus.php", reason: "アップロード予約の内部状態機械 (ドメイン固有規約 2)。画面はアップロード進捗の表示だけを見る" },
    { path: "app/Enums/CheckoutIntent.php", reason: "チェックアウト意図を表す内部種別。画面はリダイレクト先で結果を判断する" },
    { path: "app/Enums/CheckoutSessionStatus.php", reason: "チェックアウトセッションの内部状態機械。画面は完了/失敗の結果だけを見る" },
    { path: "app/Enums/EmailSuppressionReason.php", reason: "メール抑制 (bounce/complaint) の内部理由。運用ログにのみ残る" },
    { path: "app/Enums/EmailTrustLevel.php", reason: "メールアドレスの信頼度を表す内部判定。認可ロジックの内部でのみ使う" },
    { path: "app/Enums/Http/InertiaErrorScreenPassthrough.php", reason: "エラー画面を通過させるかどうかの内部判定語彙。ミドルウェアの内部分岐にのみ使う" },
    { path: "app/Enums/Idempotency/IdempotencyState.php", reason: "冪等キーの内部状態機械 (ドメイン固有規約 10)。画面は完了/未完了の結果だけを見る" },
    { path: "app/Enums/Inquiry/InquirySource.php", reason: "問い合わせ受付経路を表す内部語彙。管理側の集計にのみ使い画面へは出ない" },
    { path: "app/Enums/Inquiry/InquiryStatus.php", reason: "問い合わせ対応状況の内部状態。管理画面はサーバ側で組み立てた一覧表示だけを受け取る" },
    { path: "app/Enums/Inquiry/InquiryType.php", reason: "問い合わせ種別の内部語彙。管理側の振り分けにのみ使い画面へは出ない" },
    { path: "app/Enums/LlmCostGroupBy.php", reason: "LLM コスト集計の内部グルーピングキー。管理画面はサーバ側で集計済みの結果を受け取る" },
    { path: "app/Enums/Manual/AnalysisFailureReason.php", reason: "解析失敗理由の内部語彙。画面には理由ごとの案内文をサーバ側で確定して渡す" },
    { path: "app/Enums/Manual/CutType.php", reason: "カット種別 (step/point) の内部判定。カット編集の内部ロジックにのみ使う" },
    { path: "app/Enums/Manual/LlmOutputInvalidReason.php", reason: "LLM 出力不正の内部理由。画面には再試行可否の結果だけが渡る" },
    { path: "app/Enums/Manual/ShotType.php", reason: "ショット種別 (hiki/yori) の内部語彙。台本表示は文言化済みの値を受け取るだけである" },
    { path: "app/Enums/Mcp/ToolName.php", reason: "MCP ツール名の内部登録名。Web UI からは呼ばれない CLI/MCP 専用の語彙である" },
    { path: "app/Enums/OAuth/CliOAuthScope.php", reason: "CLI OAuth スコープの内部語彙。認可判定にのみ使い画面へは出ない" },
    { path: "app/Enums/OAuth/OAuthClientKind.php", reason: "OAuth クライアント種別の内部判定。認可ロジックの内部でのみ使う" },
    { path: "app/Enums/Organization/SlugReservationReason.php", reason: "組織識別名の予約理由の 3 分類 (家系裁定 AG-039)。設定ファイルの読み込み検査とレビューのための語彙で、画面には拒否の文言だけが渡る" },
    { path: "app/Enums/ProjectRole.php", reason: "プロジェクトロールの内部判定。画面は権限の有無を真偽値として受け取るだけである" },
    { path: "app/Enums/ProviderCapability.php", reason: "認証プロバイダの能力分類の内部語彙。認可ロジックの内部でのみ使う" },
    { path: "app/Enums/QuotaKey.php", reason: "Quota 種別の内部キー。画面は使用量と上限の数値だけを受け取る" },
    { path: "app/Enums/Recovery/NonRecoveryScheduleReasonKind.php", reason: "滞留回収をスケジュールしない理由の内部語彙 (ドメイン固有規約 14)。運用ログにのみ残る" },
    { path: "app/Enums/Recovery/RecoveryOutcome.php", reason: "滞留回収結果の内部語彙 (ドメイン固有規約 14)。運用ログにのみ残る" },
    { path: "app/Enums/Recovery/RecoveryStream.php", reason: "滞留回収対象ストリームの内部語彙 (ドメイン固有規約 14)。運用ログにのみ残る" },
    { path: "app/Enums/Security/AdoptedTakeReferenceKind.php", reason: "採用テイク充足判定 (ドメイン固有規約 12) が内部で使う分類語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/ApiWriteScopeExemption.php", reason: "API 変更系スコープ検査の免除申告に使う内部語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/ControllerAuthorizationExemption.php", reason: "認可 gate の免除申告に使う内部語彙 (セキュリティ不変条件 9)。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/DirectFetchJustification.php", reason: "クラス起点の主キー同一性クエリの許可理由を表す内部語彙 (セキュリティ不変条件 3)。目録だけが参照する" },
    { path: "app/Enums/Security/ExternalCallKind.php", reason: "外部到達点の目録 (ドメイン固有規約 9) が使う内部分類語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/ExternalSeamClassification.php", reason: "外部到達点の目録が使う内部分類語彙 (guarded/exempt)。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/ExternalSeamDimension.php", reason: "外部到達点の目録が使う内部分類の軸を表す語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/ExternalSeamKind.php", reason: "外部到達点の目録が使う外部サービス種別の内部語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/GatewayFailureObservationExemption.php", reason: "決済ゲートウェイ失敗観測の免除申告に使う内部語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/IdempotencyWiringExemption.php", reason: "冪等キー配線検査の免除申告に使う内部語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/InlineThrottleBucketRationale.php", reason: "流量制限バケット判定の内部根拠語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/InvitationResolutionScope.php", reason: "招待解決の作用域を表す内部語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/JobDedupExemption.php", reason: "ジョブ重複実行検査の免除申告に使う内部語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/JobDedupGuarantee.php", reason: "ジョブ結果の一回性を担保する機構の内部分類語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/NestedRouteDefenseMode.php", reason: "nested route のテナント境界防御方式を表す内部語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/OrgAccessRevocationExemption.php", reason: "組織アクセス失効検査の免除申告に使う内部語彙 (ドメイン固有規約 16)" },
    { path: "app/Enums/Security/OrgAccessRevocationReason.php", reason: "組織アクセス失効の理由を表す内部語彙 (ドメイン固有規約 16)。運用ログにのみ残る" },
    { path: "app/Enums/Security/RecoveryFetchShape.php", reason: "滞留回収の取得経路の形を表す内部語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/RenderArtifactSelectionKind.php", reason: "レンダ成果物選択式 (ドメイン固有規約 13) が使う内部分類語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/RescueRouteGateKind.php", reason: "救済 route の関門通過可否を表す内部分類語彙。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Security/ThrottleCoverageExemption.php", reason: "流量制限の付与検査の免除申告に使う内部語彙 (ドメイン固有規約 5)" },
    { path: "app/Enums/Security/TwoFactorStepUpExemption.php", reason: "2FA step-up 検査の免除申告に使う内部語彙 (ドメイン固有規約 8)" },
    { path: "app/Enums/SecurityEventType.php", reason: "セキュリティ監査ログのイベント種別 (21 件)。画面には出さず監査ログにのみ残る" },
    { path: "app/Enums/Smoke/SmokeFailureClass.php", reason: "smoke テストの内部失敗分類。テスト結果のログにのみ残る" },
    { path: "app/Enums/Smoke/SmokeStage.php", reason: "smoke テストの内部ステージ分類。テスト結果のログにのみ残る" },
    { path: "app/Enums/Storage/ExternalClientBoundaryExemption.php", reason: "外部 storage クライアント境界検査の免除申告に使う内部語彙" },
    { path: "app/Enums/Storage/S3OperationSurface.php", reason: "S3 操作面の内部分類語彙。SSRF 検査など Architecture テストの目録だけが参照する" },
    { path: "app/Enums/Support/QueueAtomicityRule.php", reason: "キュー投入原子性判定の内部語彙 (ドメイン固有規約 11)。Architecture テストの目録だけが参照する" },
    { path: "app/Enums/TwoFactorStatus.php", reason: "2FA 状態の内部判定。画面は有効/無効の真偽値と個別の案内文だけを見る" },
    { path: "app/Services/Help/HelpArtifactState.php", reason: "ヘルプ生成物の鮮度の内部語彙 (up_to_date/stale/missing/orphan)。artisan コマンドの報告にのみ使い画面へは出ない" },
    { path: "app/Services/Marketing/ContactDestinationKind.php", reason: "マーケティング問い合わせの送信先を表す内部種別。バッチ処理の内部でのみ使う" },
] as const satisfies readonly PhpEnumExemption[];

/** `PHP_ENUM_EXEMPTIONS` の件数の pin。増えても減っても赤くする。 */
const EXPECTED_EXEMPTION_COUNT = 95;

interface UnresolvablePhpEnumEntry {
    readonly path: string;
    readonly reason: string;
}

/**
 * 本 gate 専用の字句走査器では値集合を読み切れないと分かっている PHP の文字列付き列挙。
 * `catalog.unresolvable` に現れる path はここに登録された分だけ許す。
 */
const KNOWN_UNRESOLVABLE_PHP_ENUMS = [
    {
        path: "app/Enums/Security/DeletionPathSeamExemption.php",
        reason: "case を 1 件も持たない (0 件) ため、本走査器では値集合を抽出できない",
    },
    {
        path: "app/Enums/Security/RescueRouteGateDisposition.php",
        reason: "case の値が middleware の FQCN (逆斜線を含む文字列) で、本走査器の受理文法 (逆斜線を拒む) に一致しない",
    },
    {
        path: "app/Mcp/Servers/AppMcpServer.php",
        reason: "ヒアドキュメントを含み走査器で読み切れない。docblock に「enum」の語が出るが自身は enum を宣言していない (安全側に倒した意図した過剰検出)",
    },
] as const satisfies readonly UnresolvablePhpEnumEntry[];

const EXPECTED_UNRESOLVABLE_COUNT = 3;

interface ReverseSweepExemption {
    /** 一致した PHP 列挙のパス。 */
    readonly php: string;
    /** 未登録の TS 宣言のファイル。 */
    readonly file: string;
    /** 未登録の TS 宣言の名前。 */
    readonly declaration: string;
    readonly rule: 1 | 2;
    /** 登録しない理由 (30 文字以上)。 */
    readonly reason: string;
}

/**
 * 逆走査が見つける候補のうち、意図的に登録しないものの一覧。
 * `(php, file, declaration, rule)` の組が完全一致したものだけを免除する
 * (php パスまで固定するので、たまたま同じ値集合を持つ**別の** PHP 列挙が現れたときは
 * 新しい候補として検出され続ける)。
 */
const REVERSE_SWEEP_EXEMPTIONS = [
    {
        php: "app/Enums/Manual/TakeStatus.php",
        file: "resources/js/types/manual.ts",
        declaration: "SelectableTakeStatus",
        rule: 1,
        reason: "「選択できるテイクの状態」という部分集合の意図の宣言。今は TakeStatus と値が完全一致するが、意図は部分集合なので登録しない",
    },
] as const satisfies readonly ReverseSweepExemption[];

const EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT = 1;

const reverseSweepKey = (php: string, file: string, declaration: string, rule: number): string =>
    `${php}|${file}|${declaration}|${rule}`;

let catalog: PhpEnumCatalog | undefined;
let mirrorProgram: MirrorProgram | undefined;
let tsCandidates: readonly TsUnionCandidate[] | undefined;

const requireCatalog = (): PhpEnumCatalog => {
    if (catalog === undefined) throw new Error("catalog が初期化されていません");
    return catalog;
};

const requireTsCandidates = (): readonly TsUnionCandidate[] => {
    if (tsCandidates === undefined) throw new Error("tsCandidates が初期化されていません");
    return tsCandidates;
};

beforeAll(() => {
    catalog = buildPhpEnumCatalog();
    mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
    tsCandidates = collectTsUnionCandidates(mirrorProgram);
}, 300_000);

describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否の分類)", () => {
    it("走査が空振りしていない (母集団が空でない)", () => {
        const { resolved, unresolvable } = requireCatalog();
        expect(resolved.length).toBeGreaterThan(0);
        expect(resolved.length + unresolvable.length).toBeGreaterThan(0);
    });

    it("目録の件数が pin と一致する", () => {
        expect(PHP_ENUM_EXEMPTIONS).toHaveLength(EXPECTED_EXEMPTION_COUNT);
        expect(KNOWN_UNRESOLVABLE_PHP_ENUMS).toHaveLength(EXPECTED_UNRESOLVABLE_COUNT);
    });

    it("exemption の登録は実在・重複無し・app/ 配下の .php・reason が 30 文字以上", () => {
        const seen = new Set<string>();
        for (const entry of PHP_ENUM_EXEMPTIONS) {
            expect(entry.path.startsWith("app/")).toBe(true);
            expect(entry.path.endsWith(".php")).toBe(true);
            expect(fs.existsSync(path.join(REPO_ROOT, entry.path))).toBe(true);
            expect(seen.has(entry.path)).toBe(false);
            seen.add(entry.path);
            expect(entry.reason.length).toBeGreaterThanOrEqual(30);
        }
    });

    it("resolved はすべて『登録済み』か『対象外の理由つき』のどちらか一方に分類される", () => {
        const registered = registeredPhpPaths();
        const exempt = new Set<string>(PHP_ENUM_EXEMPTIONS.map((e) => e.path));

        const unclassified: string[] = [];
        const doubleClassified: string[] = [];
        for (const enumRow of requireCatalog().resolved) {
            const inRegistered = registered.has(enumRow.path);
            const inExempt = exempt.has(enumRow.path);
            if (!inRegistered && !inExempt) unclassified.push(enumRow.path);
            if (inRegistered && inExempt) doubleClassified.push(enumRow.path);
        }

        expect(unclassified, `未分類の PHP 列挙 (登録するか PHP_ENUM_EXEMPTIONS へ理由付きで登録すること):\n${unclassified.join("\n")}`).toEqual([]);
        expect(doubleClassified, `登録済みと対象外の両方に分類された PHP 列挙 (どちらか一方にすること):\n${doubleClassified.join("\n")}`).toEqual([]);
    });

    it("exemption の登録先が stale になっていない (今も resolved かつ未登録のままである)", () => {
        const registered = registeredPhpPaths();
        const resolvedPaths = new Set(requireCatalog().resolved.map((r) => r.path));

        const stale = PHP_ENUM_EXEMPTIONS.filter(
            (e) => !resolvedPaths.has(e.path) || registered.has(e.path),
        ).map((e) => e.path);

        expect(stale, `PHP_ENUM_EXEMPTIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale.join("\n")}`).toEqual([]);
    });

    it("unresolvable はすべて KNOWN_UNRESOLVABLE_PHP_ENUMS に登録されている", () => {
        const known = new Set<string>(KNOWN_UNRESOLVABLE_PHP_ENUMS.map((e) => e.path));
        const unknown = requireCatalog().unresolvable.filter((u) => !known.has(u.path));

        // 収集した抽出失敗の理由 (reason) を判定の失敗メッセージへ接続する
        // (収集するだけで誰も参照しない出力を作らない。共通規約 (d))。
        expect(
            unknown,
            `未登録の抽出不能 PHP 列挙 (KNOWN_UNRESOLVABLE_PHP_ENUMS へ理由付きで登録すること):\n${unknown
                .map((u) => `${u.path}: ${u.reason}`)
                .join("\n")}`,
        ).toEqual([]);
    });

    it("KNOWN_UNRESOLVABLE_PHP_ENUMS の登録は実在・重複無し・reason が 30 文字以上", () => {
        const seen = new Set<string>();
        for (const entry of KNOWN_UNRESOLVABLE_PHP_ENUMS) {
            expect(entry.path.startsWith("app/")).toBe(true);
            expect(entry.path.endsWith(".php")).toBe(true);
            expect(fs.existsSync(path.join(REPO_ROOT, entry.path))).toBe(true);
            expect(seen.has(entry.path)).toBe(false);
            seen.add(entry.path);
            expect(entry.reason.length).toBeGreaterThanOrEqual(30);
        }
    });

    it("KNOWN_UNRESOLVABLE_PHP_ENUMS の登録先が stale になっていない (今も unresolvable のままである)", () => {
        const actual = new Set(requireCatalog().unresolvable.map((u) => u.path));
        const stale = KNOWN_UNRESOLVABLE_PHP_ENUMS.filter((e) => !actual.has(e.path)).map((e) => e.path);

        expect(stale, `KNOWN_UNRESOLVABLE_PHP_ENUMS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale.join("\n")}`).toEqual([]);
    });
});

describe("PHP ⇔ TS 値域の逆走査 (未登録候補の検出)", () => {
    it("TS 側の候補走査が空振りしていない (母集団が空でない)", () => {
        expect(requireTsCandidates().length).toBeGreaterThan(0);
    });

    it("逆走査で見つかる候補は REVERSE_SWEEP_EXEMPTIONS に登録された分だけである", () => {
        const registered = registeredTsKeys();
        const found = findUnregisteredMirrorCandidates(
            requireCatalog().resolved,
            requireTsCandidates(),
            (file, name) => registered.has(`${file}::${name}`),
        );

        const exemptKeys = new Set(
            REVERSE_SWEEP_EXEMPTIONS.map((e) => reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
        );

        const unexempted = found.filter(
            (f) => !exemptKeys.has(reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)),
        );

        expect(
            unexempted,
            `未登録のミラー候補が見つかりました (登録するか REVERSE_SWEEP_EXEMPTIONS へ理由付きで登録すること):\n${unexempted
                .map((f) => `規則${f.rule} ${f.php.path} <-> ${f.candidate.file}::${f.candidate.name}${f.nameMatch !== null ? ` (${f.nameMatch})` : ""}`)
                .join("\n")}`,
        ).toEqual([]);
    });

    it("REVERSE_SWEEP_EXEMPTIONS の件数が pin と一致し、登録先が実在・重複無し・reason が 30 文字以上", () => {
        expect(REVERSE_SWEEP_EXEMPTIONS).toHaveLength(EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT);

        const seen = new Set<string>();
        for (const entry of REVERSE_SWEEP_EXEMPTIONS) {
            expect(fs.existsSync(path.join(REPO_ROOT, entry.php))).toBe(true);
            expect(fs.existsSync(path.join(REPO_ROOT, entry.file))).toBe(true);
            const key = reverseSweepKey(entry.php, entry.file, entry.declaration, entry.rule);
            expect(seen.has(key)).toBe(false);
            seen.add(key);
            expect(entry.reason.length).toBeGreaterThanOrEqual(30);
        }
    });

    it("REVERSE_SWEEP_EXEMPTIONS の登録先が stale になっていない (今も候補として検出され続けている)", () => {
        const registered = registeredTsKeys();
        const found = findUnregisteredMirrorCandidates(
            requireCatalog().resolved,
            requireTsCandidates(),
            (file, name) => registered.has(`${file}::${name}`),
        );
        const foundKeys = new Set(found.map((f) => reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)));

        const stale = REVERSE_SWEEP_EXEMPTIONS.filter(
            (e) => !foundKeys.has(reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
        );

        expect(
            stale,
            `REVERSE_SWEEP_EXEMPTIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale.map((e) => `${e.php} <-> ${e.file}::${e.declaration}`).join("\n")}`,
        ).toEqual([]);
    });
});
