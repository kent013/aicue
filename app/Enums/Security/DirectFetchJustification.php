<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * クラス起点の主キー同一性クエリ (ClassRootedPrimaryKeyQuery) が
 * 「テナントスコープ外で id からモデルを引いてよい」と裁定された理由の分類。
 *
 * `tests/Architecture/ModelDirectFetchInvariantTest.php` が deny-by-default で
 * 「候補でない」か「本 enum + 具体的根拠 + 構造化 field」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★ここに無い形は「例外に足す」のではなく「relation 起点に直す」が既定である。
 */
enum DirectFetchJustification: string
{
    /**
     * 同一クエリ内で所有者/テナントに閉じている。
     *
     * 適用条件 (全て満たすこと):
     * - identity 述語と**同じ chain** に所有者/テナント制約がある
     *   (`where('user_id'|'organization_id'|'team_id'|'project_id', …)` / `whereBelongsTo(…)`)
     *   ※ `whereHas(…)` は **v1 の gate では未対応**。必要になったら fixture と同時に足す
     * - その制約の**右辺が解決済みモデル由来**である (request 由来の値では不可)
     * - 取得**後**に弾く形ではない (後段で弾くと 403/404 差で存在が漏れる)
     */
    case OwnerScopedQueryConstraint = 'owner_scoped_query_constraint';

    /**
     * identity が同一メソッド内のテナントスコープ済みクエリで確定している。
     *
     * 適用条件: 当該変数への代入が relation 起点 (`$organization->projects()->value('id')` 等) で、
     * 代入と使用の間に再代入が無い。
     */
    case IdDerivedFromTenantScopedQuery = 'id_derived_from_tenant_scoped_query';

    /**
     * identity が認証済み actor / 検証済み token claim 由来である。
     *
     * 適用条件 (全て満たすこと):
     * - identity が request payload・query string 由来で**ない**
     * - 同一メソッド内に request accessor が 1 つも無い
     * - `actorSource` を明示できる (どの middleware / claim が actor を確定したか)
     *
     * ★本 case のみ機械証明ができない (provenance のデータフロー解析は走査器の範囲外)。
     *   最終的に人手の根拠文に依存することを承知の上で使う。
     */
    case AuthenticatedActorScope = 'authenticated_actor_scope';

    /**
     * identity が enqueue 時にサーバが確定した job property である。
     *
     * 適用条件: `app/Jobs/**` 配下で identity が job property であること。
     * predicateKind ごとに形が違う:
     *   - SingleIdentity … `$this->{…Id}`
     *   - MultiIdentity  … `$this->{…Ids}`
     *   - IdentityExclusion / DestructiveIdentity … **v1 では使用禁止**
     * `enqueuedBy` に dispatch 元を書く。
     *
     * ★actor/token とは信頼境界が違う (過去のリクエストがシリアライズした値であり、
     *   dispatch 元が誤っていれば汚染されうる) ため AuthenticatedActorScope と分けている。
     */
    case QueuePayloadRehydration = 'queue_payload_rehydration';

    /**
     * identity が「同一メソッド内で自身が発行した走査クエリ」の結果由来である。
     *
     * 想定は stale 回収 / 整合回復のような**全テナント横断の保守走査**
     * (`$ids = RenderJob::query()->where('status', …)->pluck('id')` の各要素を引き直す形)。
     *
     * 適用条件 (全て満たすこと):
     * - identity の基底変数が同一メソッド内のクエリ結果変数から `foreach` 束縛 / 代入されている
     * - 同一メソッド内に request accessor が 1 つも無い (HTTP 入力を経由しない)
     * - 「テナント横断で走査すること」が仕様である (cron / scheduler 経由の保守処理)
     *
     * ★テナント越しの参照を正当化する case ではない。**走査元のクエリの WHERE 条件は
     *   本 gate の主張範囲外**である (主キー同一性クエリではないため)。
     *   走査元が request 由来の条件で絞られているなら本 case を使ってはならない。
     */
    case IdDerivedFromSameMethodQuery = 'id_derived_from_same_method_query';

    /**
     * identity が同一クラス内の呼び出し元で確定し、private ヘルパへ引数で渡されている。
     *
     * 適用条件 (全て満たすこと):
     * - 当該メソッドが **private** である (クラス外から直接呼べない)
     * - identity が当該メソッドの**引数**である
     * - 同一メソッド内に request accessor が 1 つも無い
     * - `calledBy` に呼び出し元 `Class::method` を書き、そこで identity が
     *   解決済みモデルから確定していることを根拠文で示す
     *
     * ★呼び出し元の provenance は機械証明できない (メソッドをまたぐデータフロー解析は
     *   走査器の範囲外)。private + 引数 + request accessor 無しで濫用を抑えるが、
     *   最終的には人手の根拠文に依存する。public メソッドには使えない。
     */
    case IdSuppliedByInternalCaller = 'id_supplied_by_internal_caller';

    /**
     * identity が「滞留回収の候補列挙が返した主キー」である。
     *
     * 想定は滞留回収の標準形 (候補は主キーだけを返し、回収は id しか受け取らない形)。
     * 列挙と再取得が別メソッド・別クラスに分かれるため IdDerivedFromSameMethodQuery は使えず、
     * 公開の口が生の id を受け取るため IdSuppliedByInternalCaller の前提も満たさない。
     *
     * 適用条件 (すべて機械検査する):
     * - 主キークエリを含むメソッドが private で、identity がその引数である
     * - 同一メソッドに request accessor が 1 つも無い (HTTP 入力を経由しない)
     * - entry が申告する `entryPoint` (`Class::method`) が実在し、その本文が当該 private を呼ぶ
     * - 申告された系列が registry と回収の目録の両方に登録済みである
     * - **入口の形 (`RecoveryFetchShape`) ごとの封じ込め検査**を通る
     *
     * **保証しないもの**: 文字列で組み立てた動的な呼び出し (`$service->{$method}()`) と、
     * `app/` の外 (テスト等) からの呼び出しは対象外である。
     * 「回収以外から呼ばれないことが証明されている」とは書かない。
     * ただし封じ込めの検査は「メソッド名が現れるファイルの集合」という決定可能な形なので、
     * 「型を解決できないから沈黙する」という穴は無い (解決不能で素通しにはならない)。
     */
    case IdFromRecoveryCandidateEnumeration = 'id_from_recovery_candidate_enumeration';

    /** local 専用の診断経路。route 登録自体が local 限定で production から到達不能。 */
    case LocalOnlyDiagnostics = 'local_only_diagnostics';

    /** 人間の運用者が CLI で明示実行する。HTTP から到達不能。 */
    case OperatorInvokedConsoleCommand = 'operator_invoked_console_command';

    /**
     * **存在オラクル**: payload 由来 id を global に引いており、補償チェックが fetch の後段にある。
     *
     * 他の case が「fetch 時点でスコープが閉じている」のに対し、本 case は
     * 「引いた後で弾く」形であり**安全性の質が違う**。準拠形と同列に扱わないために分けてある。
     *
     * この形が残っていると何が起きるか (名前が名乗るべき実害):
     * **認証済みの組織管理者が番号を順に送るだけで、その番号の利用者・組織が実在するかを全数列挙できる**
     * — 存在しない id は validation error、実在するが権限が無い id は認可拒否となり、
     * 応答が分岐するため。cross-org の read/write は起きないが、情報漏れは実在する。
     *
     * 新規コードで本 case を使ってはならない (再発時の分類語彙としてのみ存在する)。
     *
     * > **命名の経緯 (c2c 裁定 AG-103 / 2026-08-06)**: 本 case はかつて
     * > `PayloadIdWithGlobalExistenceRuleDebt` (債務) という名前だった。オーナー裁定は
     * > 「『債務』という語が**実在する情報漏れを「返す予定のある借り」のように見せ**、
     * > 判断を鈍らせる。名前は露出の中身 (存在オラクル) を名乗ること」として改名を求めた。
     * > 借りではなく穴である。名前を戻さないこと。
     */
    case PayloadIdExistenceOracle = 'payload_id_existence_oracle';
}
