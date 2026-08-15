# Round 2: Round 1 の指摘への対応

Round 1 の [Critical] 4 件 / [Warning] 6 件に対する判断と対応を下に示す。
Critical のうち 3 件は対応済み、1 件は事実関係が違うため反論する (根拠つき)。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] 検査 D は fail-open で「単一窓口」と呼ぶには弱い

- 判断: **対応する** (呼び名と保証範囲の訂正 + 検出面の小幅な拡張)
- 根拠: 指摘のとおり。字句検査で見えるのは
  「`->update(` / `::update(` / `->forceFill(` の**引数**に失効列がある形」だけで、
  属性への直接代入・生 SQL・列名の動的組み立てには沈黙する。
  「単一窓口であることの証明」と読める呼び名は誇張であり、本リポジトリの
  「保証しないものを明記する」規約に反する。
- 対応内容:
  1. 受け手の判定に `T_DOUBLE_COLON` (静的呼び出し) を追加した。
  2. 検査名を「検査D: **検出できる書き方で**失効列へ書き込むのは allowlist のファイルだけ」に改めた。
  3. テスト冒頭の説明・失敗メッセージ・`docs/architecture.md`・詳細設計の 4 箇所を
     「単一窓口の証明ではなく、検出できる書き方に限った見張り」で揃えた
     (沈黙する 4 形を名指しで列挙)。

## [Critical] REST gate の正のコントロールが空疎化する

- 判断: **対応する**
- 根拠: 指摘のとおり。`violations` 全体が空であることを見ていたため、既存母集団側に
  違反があると対照テストまで一緒に赤くなり、「追加した route が違反にならないこと」を
  単独で証明できていなかった。
- 対応内容: 追加した route 名で始まる違反だけを抽出して空であることを見る形に変えた
  (理由もコメントに残した)。

## [Critical] McpAuthorizationChokePointTest の保証範囲の記述が実装と矛盾

- 判断: **対応する**
- 根拠: 指摘のとおり。矛盾していたのは**詳細設計の施策 7 リスク節**の
  「戻り値を捨てる形は落とせない」という一文で、検査 B が実際にはその形を落とす。
  誇張の逆 (過小申告) も読む人を誤らせるため訂正が要る。
- 対応内容:
  1. 詳細設計へ「実装時の訂正」を書き、落とせないのは
     「否定と throw の間に別の分岐を挟む変種」であると言い直した。
  2. テスト冒頭の説明も「条件の先頭で否定し、直後に throw する形だけを見る」に揃えた。
  3. あわせて検出器を厳しくした — 「近傍 10 トークンに `!` がある」ではなく
     **直近の `if` の条件が `(` `!` で始まる**ことを要求する形にし、
     `if ($a !== $b && $ctx->authorizeTool(...))` を否定と誤認しない負例を足した。

## [Critical] API キー書き込みテストの `postJson()` 第 3 引数は headers ではなく options

- 判断: **反論する** (ただしテストは別の理由で強化した)
- 根拠: Laravel 12 の `MakesHttpRequests::postJson($uri, array $data = [], array $headers = [], $options = 0)`
  は**第 3 引数が headers、第 4 引数が options** である。よって
  `Idempotency-Key` は実際に付いていた。
- 対応内容: とはいえ指摘の**主旨** (403 が別の理由で返っても緑になりうる) は正しいので、
  そちらへ対応した:
  1. **除名の前に同じ要求が 201 で通ることを先に確かめる**対照を足した
     (これが通る = 資格・テナント境界・冪等の各段は除名前に成立していた、の証拠)。
  2. 403 の `error.code` が `forbidden` であることまで見る。
  3. 副作用が 1 件だけであること (2 回目が作成されていないこと) も見る。
  4. 既存 `ItemAuthorizationTest` と同じく `Idempotency-Key` ヘッダ無しの形へ揃えた
     (この route は無しでも通るため、余計な差異を持ち込まない)。

## [Warning] 「外部 API の書き込み」の確認が読み取り (GET /me) だけ

- 判断: **対応する**
- 根拠: 設計の E 節は書き込みも要求している。読み取りだけだと
  「書き込み経路にだけ別の抜け道がある」場合を見逃す。
- 対応内容: OAuth トークン経路のテストを「除名前は読み取りも**作成**も通る →
  除名後は両方 401」に拡張し、副作用の件数も見るようにした。

## [Warning] 接続セッション一覧の assertion が `sessions.0` 固定

- 判断: **対応する**
- 根拠: 並び順が変わるとテストの意図が静かにずれる。
- 対応内容: `has('sessions', 1)` で**件数を先に固定してから**添字で見る形にした
  (件数が 1 なら添字は一意に定まる)。理由もコメントに書いた。

## [Warning] `applyConsoleRole` の「役割変更の後に失効」という説明が強い

- 判断: **対応する** (説明の追記)
- 根拠: Editor / Shooter コマンドでは実際の順序が
  「組織ロールの入れ替え → 失効 → プロジェクト pivot の更新」であり、
  「失効が最後」と読める書き方は正確ではない。
- 対応内容: `applyConsoleRole()` の説明文へ、実際の順序と
  「プロジェクト側の役割を失効の境界に入れていないので pivot 更新が後でよい」
  「後続が失敗すれば外側ごと巻き戻るので失効だけが残る中間状態は生まれない」を明記した。

## [Warning] 差分に文書更新が含まれていない (施策 9 未実装に見える)

- 判断: **反論する** (実装済み。Round 1 の差分の切り出し範囲の問題)
- 根拠: Round 1 で送った diff は `app/ tests/ routes/ resources/` に限定していた
  (スキルの既定の差分範囲)。文書は同じ変更の中で実装済みである。
- 対応内容: Round 2 のプロンプトへ `docs/` と `AGENTS.md` の差分を添えた。

## [Warning] `$reason` の等価な書き換え / 母集団件数 4 固定 / 依存閉包 allowlist

- 判断: **見送る**
- 根拠: いずれも「意図的に狭く固定している」ことの確認であり、
  現状の記述 (保証範囲の明記) で意図と一致している。
  母集団件数の完全一致は、セレクタが空振りしたときに必ず赤くする目的で選んだ形であり、
  route を増減させたときに数値も書き換えさせるのが狙いである。

## [Suggestion] `now()` の複数回呼び出し / anonymous subclass の引数更新

- 判断: **見送る** (指摘は「問題なし」の確認)


---

## 1. 文書 (施策 9) の差分 — Round 1 では送っていなかった範囲

Round 1 の diff は `app/ tests/ routes/ resources/` に限定していたため、文書が未実装に見えていた。
実際には同じ変更に含まれている。以下がその差分である。

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 84c916b..70a238d 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -742,3 +742,21 @@ ## ドメイン固有規約
       独立した監視対象である。保証しないもの (500 件上限は公平性を保証しない / S3 削除に
       失敗した孤児は自動では拾えない / 実行しない指定の候補件数は上界にすぎない) は
       `docs/architecture.md` §滞留回収の共通基盤 が正本
+15. **組織アクセスの失効の窓口と目録 (T174 / 家系の正典 v2)**: 組織の役割を書き込む経路は、
+    **その変更と同じトランザクションの中で** `Services/OAuth/OrganizationAccessRevoker` を呼ぶか、
+    `OrgAccessRevocationExemption` + 30 文字以上の根拠で免除目録へ登録する
+    (`OrganizationAccessRevocationChokePointTest` が deny-by-default で強制。
+    免除の件数は完全一致で pin する)。
+    - **境界は「役割を変える操作が成功したこと」**で、役割の集合の差分は取らない
+      (差分は役割キャッシュ依存になり、取りこぼすと通してしまう側へ倒れる)。
+      帰結として **昇格でも接続はやり直しになる** (既知の仕様)。
+    - 失効するのは 3 家族 (`oauth_sessions` / `oauth_access_tokens` と紐づく
+      `oauth_refresh_tokens` / 未交換の `oauth_auth_codes`) で**途中で打ち切らない**。
+      失効させないのは**組織の API キー**と**プロジェクト単位の役割**。
+    - 監査は握り潰さない (`SecurityEventRecorder::recordOrFail`)。書けなければ役割の変更ごと
+      巻き戻る。**失効 0 件でも 1 行残す**。`record()` (best-effort) と書き分け、
+      失効以外に `recordOrFail()` を使わない (監査の失敗でログインを落とすことになる)。
+    - **理由は観測であって制御ではない**。窓口が `$reason` を分岐に使っていないことを
+      同 gate が字句で固定する。
+    - 保証しないもの (発行との隙間 / API キーの読み取りが残ること / 静的検査の限界) は
+      `docs/architecture.md` §組織アクセスの失効 が正本。運用向けの説明は `docs/mcp-oauth.md`
diff --git a/docs/architecture.md b/docs/architecture.md
index e1db9b4..4d14d5d 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2280,3 +2280,71 @@ ### 保証しないもの (誇張しない。**本節が正本**)
   ffmpeg 側の字幕描画は本節の対象外
 - **trusted 変数の入口は存在しない**。作る必要が出たときの義務は
   `docs/template-divergence.md` D16 が正本
+
+## 組織アクセスの失効 (T174 / 家系の正典 v2)
+
+組織の中で誰かの役割が変わったとき、その人がその組織で持っている「人に委ねられた資格情報」を
+**その場で・同じひとまとまり (トランザクション) の中で**失効させる。
+
+### 境界
+
+失効の境界は **「役割を変える操作が成功したこと」** である。**役割の集合の差分は取らない**。
+差分を取ると権限ライブラリの役割キャッシュ (本番で 1 時間有効) に依存した判定になり、
+取りこぼしたときに通してしまう側へ倒れるためである。
+
+帰結として **昇格でも接続はやり直しになる**。これは代償を承知で選んだ既知の仕様であり、
+監査の理由 (`OrgAccessRevocationReason`) に「オーナー移譲の受け手」が独立した case として
+あるのは、この驚きをサポート時に 1 行で説明できるようにするためである。
+
+### 窓口と配線
+
+- 窓口は `app/Services/OAuth/OrganizationAccessRevoker.php` **ただ 1 本**。
+  呼び出し元のトランザクションの内側であることを実行時に検査する
+  (深さが 0 なら例外。説明文とテストだけに頼らない)。
+- 呼ぶのは `OrganizationMembershipService` の 4 経路
+  (`changeRole` / `removeMember` / `transferOwnership` / `normalizeOrganizationRole`)。
+  移譲は**譲り手と受け手の 2 回**呼ぶ。
+- 役割を書き込むのに呼ばない経路は `OrgAccessRevocationExemption` へ 30 文字以上の根拠付きで
+  登録する (既定拒否)。現在の登録は招待受諾 (`joinOrganization`) の 1 件だけで、
+  理由は「入れる操作の時点でその人がその組織で持つ資格情報は構造的に 0 件」である。
+
+### 失効する 3 家族 (途中で打ち切らない)
+
+| 家族 | 対象 | 落とすと起きること |
+|---|---|---|
+| 1 | `oauth_sessions` の未失効行 | 一覧・actor 解決の失効印が残らない |
+| 2 | `oauth_access_tokens` と紐づく `oauth_refresh_tokens` | 更新トークンで再発行できてしまう |
+| 3 | 未交換の `oauth_auth_codes` | 失効の直前に出た認可コードを後から交換できてしまう |
+
+家族 2 は **セッション id で絞らない** (セッション行を持たない古いトークンが生き残るため) し、
+**母集団を「未失効の利用トークン」に絞らない** (親が失効済みで子だけ未失効の不整合行を
+取り逃すため)。絞るのは件数を数える更新文の側だけである。
+
+### 監査
+
+`SecurityEventType::OrganizationAccessRevoked` を `recordOrFail` で記録する。
+書けなければ役割の変更ごと巻き戻る (「資格情報は失効したが監査に残っていない」状態を作らない)。
+**失効 0 件でも 1 行残す** — 記録が無いと「窓口が呼ばれなかったのか / 対象が無かったのか」を
+区別できないためである。metadata は組織 / 操作した人 / 理由 / 家族ごとの件数。
+
+### 保証しないもの (誇張しない。**本節が正本**)
+
+- **失効の選択と確定の間に新しい資格情報が発行される隙間は閉じていない**。
+  発行の経路は組織行・利用者行のロックを取らないためである。最後の拒否線は要求ごとの再評価
+  (`ResolveApiActor::contextFromUserToken` / `McpAuthorizationContext::for`) が受け持つ。
+- **組織の API キーは失効させない**。**発行した人が組織から外れても、その鍵の読み取り権限は残る**
+  (書き込みは `ProjectPolicy` の実行時評価で 403 になる)。この非対称を「防御がある」と丸めない。
+  鍵を止める手段は組織管理者による失効操作である。所属の再評価を足すと発行者の退職で
+  組織の自動連携が無言で止まるため、**別の判断として独立に起こす**。
+- **プロジェクト単位の役割は失効の境界に入れない**。トークンの結び付き先は組織であり、
+  その人はまだ組織のメンバーだからである。
+- **静的検査は「呼び出しの字句が在ること」と「その位置」までしか見ない**。
+  途中に早期 return や条件分岐を足せば、gate は緑のまま失効しない経路が生まれる。
+  実挙動は `tests/Feature/Organizations/OrganizationAccessRevocationTest.php` が担う。
+- 失効列の検査は「資格情報 4 表の名前を文字列で持つファイル」×
+  「`->update(` / `::update(` / `->forceFill(` の**引数**に失効列がある」の積で判定する。
+  **「窓口が 1 本であることの証明」ではなく「検出できる書き方に限った見張り」である**。
+  表の名前を字句として持たない経路・属性への直接代入・生 SQL・列名を変数で組み立てる形には
+  沈黙する。
+- **認可コードの交換時に所属を確認してはいない**。閉じているのは「失効の時点で未交換だった
+  コードを撃つ」ところまでである (後続の候補)。
diff --git a/docs/mcp-oauth.md b/docs/mcp-oauth.md
index 7f99fc6..905451e 100644
--- a/docs/mcp-oauth.md
+++ b/docs/mcp-oauth.md
@@ -78,6 +78,37 @@ ### token rotation / TTL
 - scope は `mcp:use` (MCP) + CLI user token 用 `CliOAuthScope` 群 (`cli:use` / `read` / `write` / `session.revoke`)。
   tool / ability 粒度の認可は runtime 再評価で行う (`Mcp/Auth/McpAuthorizationContext`)。
 
+### 組織の役割変更に同期した失効
+
+組織の中で誰かの役割が変わったら、**その変更と同じひとまとまり (トランザクション) の中で**
+その人のその組織における資格情報を失効させる (窓口は `app/Services/OAuth/OrganizationAccessRevoker.php`)。
+
+- **境界は「役割を変える操作が成功したこと」**。役割の集合の差分は取らない。差分で判断すると
+  権限ライブラリの役割キャッシュ (本番で 1 時間有効) に依存し、取りこぼしたときに通してしまう側へ倒れる。
+  帰結として **昇格でも接続はやり直しになる**。これは既知の仕様である
+  (監査の理由に `ownership_transferred_to` があるのはこの説明のため)。
+- **失効するもの (3 家族。途中で打ち切らない)**: `oauth_sessions` /
+  `oauth_access_tokens` と紐づく `oauth_refresh_tokens` / 未交換の `oauth_auth_codes`。
+  認可コードを落とすと「失効の直前に発行された code を失効の後に交換して新しいトークンを得る」
+  経路が残るため、3 家族目まで必ず撃つ。
+- **失効しないもの**: 組織の API キー (`api_keys`) と、プロジェクト単位の役割。
+- 監査は握り潰さない (`SecurityEventRecorder::recordOrFail`)。書けなければ役割の変更ごと巻き戻る。
+  **失効 0 件でも 1 行残す** (「対象が無かった」ことも監査上の事実である)。
+
+**保証しないもの**
+
+- 失効の選択と確定の間に新しい資格情報が発行される隙間は閉じていない
+  (発行の経路は組織行・利用者行のロックを取らない)。最後の拒否線は要求ごとの再評価
+  (`ResolveApiActor` / `McpAuthorizationContext`) である。
+- **API キーの残余リスク**: **発行した人が組織から外れても、その鍵の読み取り権限は残る**。
+  書き込みは認可 (`ProjectPolicy` が発行者の現在の組織ロールを評価する) で 403 になる。
+  この非対称を「防御がある」と丸めないこと。鍵を止める手段は組織管理者による失効操作
+  (API キー画面) である。所属の再評価を足すと発行者の退職で組織の自動連携が無言で止まるため、
+  **別の判断として独立に起こす**。
+- 静的検査 (`OrganizationAccessRevocationChokePointTest`) が固定するのは
+  「呼び出しの字句が在ること」と「その位置」までで、すべての制御経路で失効が走ることは
+  保証しない。実挙動は `tests/Feature/Organizations/OrganizationAccessRevocationTest.php` が担う。
+
 ### transport / Origin ガード
 
 - `EnforceMcpTransport` (`mcp.transport`): MCP Streamable HTTP の前提 (POST のみ / `Accept: application/json` /
@@ -96,6 +127,8 @@ ## 関連ファイル
 | `app/Passport/Grants/McpRefreshTokenGrant.php` | refresh_token grant 拡張。org/session 継承 + CLI セッション失効 / membership 再検証 |
 | `app/Passport/McpAuthCodeRepository.php` | auth code 発行時に consent 組織を書き込み、CLI は `oauth_sessions` を同トランザクションで作成 |
 | `app/Passport/McpAccessTokenRepository.php` | access token 発行時に継承された org/session を書き込み |
+| `app/Services/OAuth/OrganizationAccessRevoker.php` | 組織アクセス失効の唯一の窓口 (役割変更と同一トランザクション) |
+| `app/Services/Organization/OrganizationMembershipService.php` | 役割変更 4 経路。失効を同一トランザクション内で呼ぶ |
 | `app/Http/Middleware/McpConsentOrganizationBinder.php` | consent の `organization_id` 検証 (非 member を 403) と attribute バインド |
 | `app/Http/Middleware/VerifyMcpOrigin.php` | `mcp.origin`。Origin allowlist 検証 (fail-closed) |
 | `app/Http/Middleware/EnforceMcpTransport.php` | `mcp.transport`。POST + JSON 強制 |

```

---

## 2. Round 1 の指摘を受けて変更したファイルの現在の差分 (HEAD 比の全体)

対象は次の 5 ファイル:

- `app/Services/Organization/OrganizationMembershipService.php` (applyConsoleRole の順序の説明を追記)
- `tests/Architecture/McpAuthorizationChokePointTest.php` (否定の判定を厳しくした + 負例追加 + 説明の訂正)
- `tests/Architecture/RestWriteScopeRevalidationInvariantTest.php` (正のコントロールの空疎化を解消)
- `tests/Architecture/OrganizationAccessRevocationChokePointTest.php` (検査 D の呼び名・検出面・説明)
- `tests/Feature/Organizations/OrganizationAccessRevocationTest.php` (対照の追加 / 書き込み経路 / 件数固定)

```diff
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index c1d9d1d..8394af7 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -11,6 +11,7 @@
 use App\Enums\AccountDeletionBlockReason;
 use App\Enums\AdminConsoleRole;
 use App\Enums\OrganizationRole;
+use App\Enums\Security\OrgAccessRevocationReason;
 use App\Enums\SecurityEventType;
 use App\Models\Organization;
 use App\Models\OrganizationInvitation;
@@ -19,6 +20,7 @@
 use App\Notifications\OrganizationInvitationNotification;
 use App\Services\Billing\AccountDeletionBillingGuard;
 use App\Services\Notification\NotificationCenterService;
+use App\Services\OAuth\OrganizationAccessRevoker;
 use App\Services\Project\DefaultProjectResolver;
 use App\Services\Security\SecurityEventRecorder;
 use App\Support\Account\AccountDeletionGrace;
@@ -56,6 +58,7 @@ public function __construct(
         private readonly DefaultProjectResolver $defaultProjects,
         private readonly NotificationCenterService $notifications,
         private readonly AccountDeletionBillingGuard $billingGuard,
+        private readonly OrganizationAccessRevoker $accessRevoker,
     ) {}
 
     /**
@@ -442,11 +445,22 @@ private function joinOrganization(OrganizationInvitation $invitation, Organizati
      * (DB::transaction のネストは savepoint 扱いのため、changeRole の ValidationException は
      * そのまま外へ伝播し外側 tx ごと rollback される)。
      *
+     * 失効 (組織アクセスの資格情報) は**自分では呼ばない**。呼ぶと 1 操作で 2 回失効させる
+     * ことになるため、委譲先 (normalizeOrganizationRole / changeRole) が呼ぶ。
+     * よって Editor / Shooter コマンドでは順序が
+     * 「組織ロールの入れ替え → 失効 → プロジェクト側の pivot 更新」になる。
+     * 失効が最後でないのは、**プロジェクト側の役割を失効の境界に入れていない**からである
+     * (トークンの結び付き先は組織であり、pivot の更新は資格情報の広さを変えない)。
+     * 後続の pivot 更新が失敗すれば外側のひとまとまりごと巻き戻るので、
+     * 「失効だけが残る」中間状態は生まれない。
+     *
+     * @param  User|null  $actor  操作した人 (監査用。HTTP 外 = バッチ・コンソールは null が正常値)
+     *
      * @throws ValidationException 非メンバー / 最終 Owner 保護 / Default Project 不在
      */
-    public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role): void
+    public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role, ?User $actor): void
     {
-        DB::transaction(function () use ($organization, $target, $role): void {
+        DB::transaction(function () use ($organization, $target, $role, $actor): void {
             // canonical 共通ロック境界 (users 昇順 → organizations)。normalizeOrganizationRole の
             // 直接 addRole 経路も含めロック下で直列化する。
             $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
@@ -456,7 +470,7 @@ public function applyConsoleRole(Organization $organization, User $target, Admin
             if ($projectRole === null) {
                 // Admin コマンド: org ロール正規化 → stale pivot 掃除
                 // (org 配下 project に限定 = cross-org 不変条件)
-                $this->normalizeOrganizationRole($organization, $target, $role);
+                $this->normalizeOrganizationRole($organization, $target, $role, $actor);
                 $this->detachProjectMemberships($organization, $target);
 
                 return;
@@ -471,7 +485,7 @@ public function applyConsoleRole(Organization $organization, User $target, Admin
                 ]);
             }
 
-            $this->normalizeOrganizationRole($organization, $target, $role);
+            $this->normalizeOrganizationRole($organization, $target, $role, $actor);
             $project->members()->syncWithoutDetaching([
                 $target->id => ['role' => $projectRole->value],
             ]);
@@ -483,9 +497,14 @@ public function applyConsoleRole(Organization $organization, User $target, Admin
      * 「未割当」= MemberRoleState::derive(null, ...)) は changeRole が「非メンバー」として
      * 拒否するため、修復経路として addRole で直接付与する (管理画面から正規化できる契約)。
      *
+     * **本メソッドは applyConsoleRole が張ったトランザクションの内側でしか呼ばれない**
+     * (private かつ呼び出し元が 1 箇所)。修復の枝で失効を呼ぶのはこの前提に依存する。
+     *
+     * @param  User|null  $actor  操作した人 (監査用)
+     *
      * @throws ValidationException 非メンバー / 最終 Owner 保護 (changeRole 継承)
      */
-    private function normalizeOrganizationRole(Organization $organization, User $target, AdminConsoleRole $role): void
+    private function normalizeOrganizationRole(Organization $organization, User $target, AdminConsoleRole $role, ?User $actor): void
     {
         if ($target->organizationRole($organization) === null) {
             // 非 attach は changeRole と同じ契約で拒否 (第 1 層は Controller の URL 整合 guard = 404)
@@ -494,23 +513,39 @@ private function normalizeOrganizationRole(Organization $organization, User $tar
             }
             $target->addRole($role->organizationRole()->value, $organization->laratrust_team_id);
 
+            // 修復も役割の付与である。changeRole を経ない唯一の枝なので、ここにも置く
+            // (置かないと「管理画面から役割を直したのに古いトークンが生きている」経路が残る)。
+            $this->accessRevoker->revoke(
+                $organization,
+                $target,
+                OrgAccessRevocationReason::RoleChanged,
+                $actor,
+            );
+
             return;
         }
 
         // 同値なら changeRole 内で早期 return = 冪等。最終 Owner 保護も継承
-        $this->changeRole($organization, $target, $role->organizationRole());
+        $this->changeRole($organization, $target, $role->organizationRole(), $actor);
     }
 
     /**
      * ロール変更。Owner への昇格は transferOwnership のみが正規経路
      * (Controller 側のバリデーションが Owner 指定を拒否する)。
      *
+     * **役割の入れ替えの後、同じトランザクションの中で**その人のこの組織における
+     * 機械クライアント向け資格情報を失効させる (家系の正典 v2)。昇格でも切れる —
+     * 役割の集合の差分で判断すると、権限ライブラリの役割キャッシュ依存になり
+     * 取りこぼしたときに通してしまう側へ倒れるためである。
+     *
+     * @param  User|null  $actor  操作した人 (監査用。HTTP 外は null が正常値)
+     *
      * @throws ValidationException 非メンバー / 最後の Owner の降格
      */
-    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
+    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole, ?User $actor): void
     {
         // [TOCTOU 封じ] 事前チェックを撤廃し、検証をすべてロック取得後・ロック下で行う。
-        DB::transaction(function () use ($organization, $target, $newRole): void {
+        DB::transaction(function () use ($organization, $target, $newRole, $actor): void {
             // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
             $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
 
@@ -523,7 +558,7 @@ public function changeRole(Organization $organization, User $target, Organizatio
                 throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
             }
             if ($currentRole === $newRole) {
-                return; // 冪等
+                return; // 冪等 (何も変わっていないので失効もしない)
             }
             // Owner を降格させる場合は他に Owner がいることを要求 (Owner 不在の組織を作らない)
             if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $freshTarget)) {
@@ -533,18 +568,31 @@ public function changeRole(Organization $organization, User $target, Organizatio
             }
             $freshTarget->removeRole($currentRole->value, $organization->laratrust_team_id);
             $freshTarget->addRole($newRole->value, $organization->laratrust_team_id);
+
+            // 役割の入れ替えの**後**・同一トランザクション内
+            $this->accessRevoker->revoke(
+                $organization,
+                $freshTarget,
+                OrgAccessRevocationReason::RoleChanged,
+                $actor,
+            );
         });
     }
 
     /**
      * メンバー削除。Owner は削除不可 (先に transferOwnership が必要)。
      *
+     * 除名の**後**、同じトランザクションの中でその人のこの組織における機械クライアント向け
+     * 資格情報を失効させる (家系の正典 v2)。
+     *
+     * @param  User|null  $actor  操作した人 (監査用。HTTP 外は null が正常値)
+     *
      * @throws ValidationException 非メンバー / Owner
      */
-    public function removeMember(Organization $organization, User $target): void
+    public function removeMember(Organization $organization, User $target, ?User $actor): void
     {
         // [TOCTOU 封じ] 検証をロック取得後・ロック下で行う。
-        DB::transaction(function () use ($organization, $target): void {
+        DB::transaction(function () use ($organization, $target, $actor): void {
             // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
             $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
 
@@ -569,6 +617,14 @@ public function removeMember(Organization $organization, User $target): void
             if ($freshTarget->current_organization_id === $organization->id) {
                 $freshTarget->forceFill(['current_organization_id' => null])->save();
             }
+
+            // 除名の後・同一トランザクション内
+            $this->accessRevoker->revoke(
+                $organization,
+                $freshTarget,
+                OrgAccessRevocationReason::MemberRemoved,
+                $actor,
+            );
         });
     }
 
@@ -597,6 +653,10 @@ private function detachProjectMemberships(Organization $organization, User $targ
      * オーナー移譲。organization_user の両者の行を lockForUpdate で直列化し、
      * 並行移譲による Owner 0 人 / 2 人の中間状態を防ぐ (spirux 方式)。
      *
+     * 役割の入れ替えの後、同じトランザクションの中で**譲り手と受け手の両方**の
+     * 機械クライアント向け資格情報を失効させる (家系の正典 v2)。受け手は昇格だが、
+     * 役割の集合の差分で判断しないという設計判断の帰結として同じように切れる。
+     *
      * @throws ValidationException from が Owner でない / to が非メンバー / 自己移譲
      */
     public function transferOwnership(Organization $organization, User $from, User $to): void
@@ -646,6 +706,11 @@ public function transferOwnership(Organization $organization, User $from, User $
                 $freshTo->removeRole($toRole->value, $teamId);
             }
             $freshTo->addRole(OrganizationRole::Owner->value, $teamId);
+
+            // 役割の入れ替えの後・同一トランザクション内。操作した人は譲り手 ($freshFrom)。
+            // 受け手も切る (昇格でも切る = 差分で判断しないという設計判断の帰結)。
+            $this->accessRevoker->revoke($freshOrg, $freshFrom, OrgAccessRevocationReason::OwnershipTransferredFrom, $freshFrom);
+            $this->accessRevoker->revoke($freshOrg, $freshTo, OrgAccessRevocationReason::OwnershipTransferredTo, $freshFrom);
         });
 
         $this->recorder->record(SecurityEventType::OwnershipTransferred, $from, [
diff --git a/tests/Architecture/McpAuthorizationChokePointTest.php b/tests/Architecture/McpAuthorizationChokePointTest.php
new file mode 100644
index 0000000..26883c4
--- /dev/null
+++ b/tests/Architecture/McpAuthorizationChokePointTest.php
@@ -0,0 +1,224 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Mcp\Tools\AppMcpTool;
+use App\Services\Mcp\Auth\McpAuthorizationContext;
+use Tests\Support\PhpTokenScan;
+
+/*
+ * MCP 経路の認可の関門 invariant。
+ *
+ * 組織の役割変更に同期した失効 ({@see \App\Services\OAuth\OrganizationAccessRevoker}) は
+ * 「発行済みの資格情報を切る」側の防御である。切る前に届いた要求に対する最後の拒否線は
+ * **要求ごとの再評価**であり、MCP 側でそれを行うのが {@see McpAuthorizationContext} である。
+ * その関門が消えていないこと・業務処理より前にあること・結果を捨てていないことを固定する。
+ *
+ * ★**扱わないこと** (同じ目印を 2 本作らない):
+ *   - `AppMcpTool::handle()` が final であること / 登録 tool が handle() を再宣言しないことは
+ *     既存の `McpWriteToolIdempotencyEnforcementTest` が既に固定している。
+ *   - tool と enum の 1:1 対応は `ToolNameInvariantTest` の担当。
+ *   - 書き込み道具が増えたときの目印も `McpWriteToolIdempotencyEnforcementTest` が持つ。
+ *
+ * ★**保証範囲を誇張しない**: 見ているのは `handle()` の本文に現れる字句と、その順序、
+ *   および「条件の先頭で否定し、直後に throw する」形だけである。認可の**意味**
+ *   (どの道具にどの権限を割り当てるのが妥当か) は見ていない。
+ *   否定と throw の間に別の分岐を挟む変種にも沈黙する。
+ *   実挙動は `tests/Feature/Mcp/McpToolsTest.php` が担う。
+ */
+
+/** クラスのメソッド本文 (最初の `{` 以降) を素のソースとして取り出す。 */
+function mcpChokePointMethodBody(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $file = (string) $reflection->getFileName();
+    $lines = file($file, FILE_IGNORE_NEW_LINES);
+    expect($lines)->toBeArray();
+    /** @var list<string> $lines */
+    $source = implode(PHP_EOL, array_slice(
+        $lines,
+        $reflection->getStartLine() - 1,
+        $reflection->getEndLine() - $reflection->getStartLine() + 1,
+    ));
+    $brace = strpos($source, '{');
+
+    return $brace === false ? '' : substr($source, $brace);
+}
+
+/** ソース断片を「空白とコメントを除いた 1 本の文字列」へ畳む。 */
+function mcpChokePointCompact(string $phpFragment): string
+{
+    $text = '';
+    foreach (PhpTokenScan::normalize('<?php '.$phpFragment) as $token) {
+        $text .= $token['text'];
+    }
+
+    return $text;
+}
+
+/**
+ * 認可が業務処理より前に来ているかの検出 (負のコントロールから再利用するため純関数)。
+ *
+ * @return list<string>
+ */
+function mcpChokePointOrderViolations(string $label, string $rawBody): array
+{
+    $body = mcpChokePointCompact($rawBody);
+    $violations = [];
+
+    $context = strpos($body, 'McpAuthorizationContext::for(');
+    $authorize = strpos($body, 'authorizeTool(');
+    $run = strpos($body, 'runTool(');
+
+    if ($context === false) {
+        $violations[] = $label.': 認可コンテキストの解決 (McpAuthorizationContext::for) が無い';
+    }
+    if ($authorize === false) {
+        $violations[] = $label.': 認可の呼び出し (authorizeTool) が無い';
+    }
+    if ($run === false) {
+        $violations[] = $label.': 業務処理の呼び出し (runTool) が無い';
+    }
+    if ($violations !== []) {
+        return $violations;
+    }
+
+    if ($context > $run) {
+        $violations[] = $label.': 認可コンテキストの解決が業務処理より後にある';
+    }
+    if ($authorize > $run) {
+        $violations[] = $label.': 認可の判定が業務処理より後にある';
+    }
+
+    return $violations;
+}
+
+/**
+ * 「否定して throw する」形かの検出 (呼ぶだけ呼んで戻り値を捨てる形を落とす)。
+ *
+ * @return list<string>
+ */
+function mcpChokePointResultUseViolations(string $label, string $rawBody): array
+{
+    $tokens = PhpTokenScan::normalize('<?php '.$rawBody);
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]['text'] !== 'authorizeTool') {
+            continue;
+        }
+        if (($tokens[$i + 1]['text'] ?? '') !== '(') {
+            continue;
+        }
+
+        // 直近の `if` を探し、その条件が `(` `!` で始まっていることを要求する。
+        // 「近くのどこかに `!` がある」ではなく**条件の先頭が否定**であることを見る
+        // (`if ($a !== $b && $ctx->authorizeTool(...))` のような別の否定を誤認しない)。
+        $ifIndex = null;
+        for ($k = $i - 1; $k >= 0 && $k >= $i - 10; $k--) {
+            if ($tokens[$k]['id'] === T_IF) {
+                $ifIndex = $k;
+
+                break;
+            }
+        }
+        if ($ifIndex === null
+            || ($tokens[$ifIndex + 1]['text'] ?? '') !== '('
+            || ($tokens[$ifIndex + 2]['text'] ?? '') !== '!') {
+            continue;
+        }
+
+        // 呼び出しの括弧を閉じる
+        $depth = 0;
+        $close = null;
+        for ($j = $i + 1; $j < $count; $j++) {
+            $t = $tokens[$j]['text'];
+            if ($t === '(') {
+                $depth++;
+            } elseif ($t === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    $close = $j;
+
+                    break;
+                }
+            }
+        }
+        if ($close === null) {
+            continue;
+        }
+
+        // if の条件を閉じる `)` → `{` → `throw`
+        if (($tokens[$close + 1]['text'] ?? '') === ')'
+            && ($tokens[$close + 2]['text'] ?? '') === '{'
+            && ($tokens[$close + 3]['id'] ?? null) === T_THROW) {
+            return [];
+        }
+    }
+
+    return [$label.': 認可の結果を否定して throw する形になっていない (戻り値を捨てている)'];
+}
+
+test('検査A: 認可の文脈が差し替え不能で、所属と権限を毎回評価し直している', function (): void {
+    expect((new ReflectionClass(McpAuthorizationContext::class))->isFinal())->toBeTrue(
+        'McpAuthorizationContext を継承で差し替えられると認可の関門が迂回されます。');
+
+    $forBody = mcpChokePointCompact(mcpChokePointMethodBody(McpAuthorizationContext::class, 'for'));
+    expect(str_contains($forBody, 'isMemberOf('))->toBeTrue(
+        '所属の再評価が消えています (組織から外れた人のトークンが通るようになります)。');
+
+    $authorizeBody = mcpChokePointCompact(mcpChokePointMethodBody(McpAuthorizationContext::class, 'authorizeTool'));
+    expect(str_contains($authorizeBody, 'hasPermission('))->toBeTrue(
+        '権限の再評価が消えています。');
+    expect(str_contains($authorizeBody, 'laratrust_team_id'))->toBeTrue(
+        '権限判定は常に組織 (チーム) を明示すること (セキュリティ不変条件 5)。');
+});
+
+test('検査B: 基底の実行部は業務処理より先に認可する', function (): void {
+    $body = mcpChokePointMethodBody(AppMcpTool::class, 'handle');
+
+    expect(mcpChokePointOrderViolations('AppMcpTool::handle', $body))->toBe([],
+        '認可を業務処理より後に置くと、拒否されるべき呼び出しが副作用を起こしてから拒否されます。');
+});
+
+test('検査B2: 認可の結果を捨てていない (否定して throw する形)', function (): void {
+    $body = mcpChokePointMethodBody(AppMcpTool::class, 'handle');
+
+    expect(mcpChokePointResultUseViolations('AppMcpTool::handle', $body))->toBe([]);
+});
+
+test('検査C: 検出器の負例 (空振り防止)', function (): void {
+    // 1. 認可が業務処理より後にある
+    $late = '{ $r = $this->runTool($req, $ctx); $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); } }';
+    expect(mcpChokePointOrderViolations('fixture', $late))->toHaveCount(2);
+
+    // 2. 認可を呼んでいるが否定と throw が無い (戻り値を捨てている)
+    $ignored = '{ $ctx = McpAuthorizationContext::for($http); $ctx->authorizeTool($this->toolName());'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $ignored))->toHaveCount(1);
+    expect(mcpChokePointOrderViolations('fixture', $ignored))->toBe([]);
+
+    // 3. 否定はするが throw しない (握り潰す形)
+    $swallowed = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $ctx->authorizeTool($this->toolName())) { return Response::json([]); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $swallowed))->toHaveCount(1);
+
+    // 4. 正例 (検出器が何でも赤くするわけではないことの対照)
+    $ok = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointOrderViolations('fixture', $ok))->toBe([]);
+    expect(mcpChokePointResultUseViolations('fixture', $ok))->toBe([]);
+
+    // 5. 認可がまったく無い
+    $none = '{ return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointOrderViolations('fixture', $none))->toHaveCount(2);
+
+    // 6. 条件の先頭ではない位置に否定がある形 (別の否定を否定と誤認しない)
+    $otherNegation = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if ($a !== $b && $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $otherNegation))->toHaveCount(1);
+});
diff --git a/tests/Architecture/OrganizationAccessRevocationChokePointTest.php b/tests/Architecture/OrganizationAccessRevocationChokePointTest.php
new file mode 100644
index 0000000..93c654a
--- /dev/null
+++ b/tests/Architecture/OrganizationAccessRevocationChokePointTest.php
@@ -0,0 +1,563 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\OrgAccessRevocationExemption;
+use App\Enums\Security\OrgAccessRevocationReason;
+use App\Models\OauthSession;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\OAuth\OrganizationAccessRevoker;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Support\Facades\DB;
+use Tests\Support\PhpTokenScan;
+
+/*
+ * 組織アクセス失効の配線 invariant (既定拒否)。
+ *
+ * 「組織の役割を書き込む経路は、同じひとまとまりの中で失効の窓口
+ * ({@see OrganizationAccessRevoker}) を呼ぶ」を機械強制する。呼ばないものは
+ * 型付き分類 + 30 文字以上の根拠で免除目録へ登録させる。
+ *
+ * ★既存の `MembershipWriteLockInventoryTest` (ロック規約と役割付与の単一窓口) とは
+ *   役割を分ける。あちらは「ロックを取っているか」、本件は「失効を呼んでいるか」である。
+ *   同じファイルに混ぜると 1 本のテストが 2 つの契約を持ち、失敗の意味が読めなくなる。
+ *
+ * ★**保証範囲を誇張しない**: 本 gate が見ているのは
+ *   「メソッド本文に失効の呼び出しの字句が在ること」と
+ *   「その位置が最後の役割書き込みより後であること」だけである。
+ *   **すべての制御経路で失効が走ることは保証しない** — 途中に早期 return や条件分岐を
+ *   足せば、本 gate は緑のまま失効しない経路が生まれる。
+ *   実際に失効が起きることは `tests/Feature/Organizations/OrganizationAccessRevocationTest.php`
+ *   (振る舞いのテスト) が担う。
+ *   また母集団の抽出は字句ベースなので、変数経由の呼び出し (`$method = 'addRole';`) には
+ *   **沈黙する**。
+ *   失効列の検査 (検査D) は「資格情報 4 表の名前を文字列で持つファイル」×
+ *   「`->update(` / `::update(` / `->forceFill(` 等の**引数**に失効列がある」の積で判定する。
+ *   **「単一窓口であることの証明」ではなく「検出できる書き方に限った見張り」である**。
+ *   次のいずれにも**沈黙する**: 表の名前を字句として持たない経路 (Eloquent モデル越しの更新だけで
+ *   表名が出てこない形) / 属性への直接代入 (`$token->revoked = true; $token->save();`) /
+ *   生 SQL (`DB::statement` / `whereRaw`) / 列名を変数で組み立てる形。
+ */
+
+/** 役割の書き込み / 組織メンバーの除去とみなす字句 (母集団のセレクタ)。 */
+function orgRevocationRoleWriteMarkers(): array
+{
+    return ['addRole(', 'removeRole(', 'syncRoles(', 'users()->detach('];
+}
+
+/** 失効の呼び出しの字句。 */
+function orgRevocationRevokeMarker(): string
+{
+    return 'accessRevoker->revoke(';
+}
+
+/** 免除理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+function orgRevocationReasonMinLength(): int
+{
+    return 30;
+}
+
+/** 免除の**件数** (完全一致。増えても減っても赤くなる)。 */
+function orgRevocationExemptionCount(): int
+{
+    return 1;
+}
+
+/**
+ * 母集団メソッドの分類 (既定拒否。未分類は fail)。
+ *
+ * @return array<string, string> メソッド名 => 'revokes' | 'exempt'
+ */
+function orgRevocationClassification(): array
+{
+    return [
+        'changeRole' => 'revokes',
+        'removeMember' => 'revokes',
+        'transferOwnership' => 'revokes',
+        'normalizeOrganizationRole' => 'revokes',
+        'joinOrganization' => 'exempt',
+    ];
+}
+
+/**
+ * `revokes` 側の「ひとまとまり」の出所。
+ *
+ * 'self' = そのメソッド自身が `DB::transaction(` を張る。
+ * それ以外 = そのメソッドを呼ぶ側のメソッド名 (private が親のひとまとまりに乗る形)。
+ *
+ * @return array<string, string>
+ */
+function orgRevocationTransactionOwners(): array
+{
+    return [
+        'changeRole' => 'self',
+        'removeMember' => 'self',
+        'transferOwnership' => 'self',
+        'normalizeOrganizationRole' => 'applyConsoleRole',
+    ];
+}
+
+/** 免除目録 (deny-by-default)。 */
+function orgRevocationExemptions(): array
+{
+    return [
+        'joinOrganization' => OrgAccessRevocationExemption::JoinOrganization,
+    ];
+}
+
+/**
+ * ソースを「空白とコメントを除いた 1 本の文字列」へ畳む。
+ *
+ * 文字列リテラルの中身は残る (列名の照合に要る) が、コメント / docblock は消える。
+ * したがって説明文に `addRole(` や `$reason` と書いても検出には影響しない。
+ */
+function orgRevocationCompact(string $phpFragment): string
+{
+    $text = '';
+    foreach (PhpTokenScan::normalize('<?php '.$phpFragment) as $token) {
+        $text .= $token['text'];
+    }
+
+    return $text;
+}
+
+/** クラスのメソッド本文 (最初の `{` 以降) を素のソースとして取り出す。 */
+function orgRevocationMethodBody(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $file = $reflection->getFileName();
+    expect($file)->toBeString();
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    expect($start)->toBeInt();
+    expect($end)->toBeInt();
+
+    $lines = file((string) $file, FILE_IGNORE_NEW_LINES);
+    expect($lines)->toBeArray();
+    /** @var list<string> $lines */
+    $source = implode(PHP_EOL, array_slice($lines, $start - 1, $end - $start + 1));
+
+    $brace = strpos($source, '{');
+
+    // 抽象メソッド等で本文が無い形は本 gate の母集団に入らない (空文字を返す)
+    return $brace === false ? '' : substr($source, $brace);
+}
+
+/**
+ * 検出器の本体 (負のコントロールから再利用するため純関数にする)。
+ *
+ * @return list<string> 違反の説明 (空なら適合)
+ */
+function orgRevocationBodyViolations(string $label, string $rawBody): array
+{
+    $body = orgRevocationCompact($rawBody);
+    $violations = [];
+
+    // 最後の役割書き込みの位置
+    $lastWrite = null;
+    foreach (orgRevocationRoleWriteMarkers() as $marker) {
+        $offset = 0;
+        while (($pos = strpos($body, $marker, $offset)) !== false) {
+            $lastWrite = $lastWrite === null ? $pos : max($lastWrite, $pos);
+            $offset = $pos + 1;
+        }
+    }
+
+    if ($lastWrite === null) {
+        return [$label.': 役割の書き込みが 1 件も無い (母集団の抽出が壊れている)'];
+    }
+
+    // 最後の役割書き込みより後に失効の呼び出しがあること
+    $after = strpos($body, orgRevocationRevokeMarker(), $lastWrite);
+    if ($after === false) {
+        $violations[] = strpos($body, orgRevocationRevokeMarker()) === false
+            ? $label.': 失効の呼び出し ('.orgRevocationRevokeMarker().') が本文に無い'
+            : $label.': 失効の呼び出しが最後の役割書き込みより前にある '
+                .'(役割の入れ替えの途中で失敗すると失効だけが残る)';
+    }
+
+    return $violations;
+}
+
+/** `revoke()` の中の `$reason` 参照が「監査 metadata の値の位置」ちょうど 1 回であること。 */
+function orgRevocationReasonUsageViolations(string $label, string $rawBody): array
+{
+    $tokens = PhpTokenScan::normalize('<?php '.$rawBody);
+
+    $indexes = [];
+    foreach ($tokens as $i => $token) {
+        if ($token['id'] === T_VARIABLE && $token['text'] === '$reason') {
+            $indexes[] = $i;
+        }
+    }
+
+    if (count($indexes) !== 1) {
+        return [$label.': $reason の参照が '.count($indexes).' 回ある (監査 metadata の 1 回だけであること)'];
+    }
+
+    $i = $indexes[0];
+    $before2 = $tokens[$i - 2] ?? null;
+    $before1 = $tokens[$i - 1] ?? null;
+    $after1 = $tokens[$i + 1] ?? null;
+    $after2 = $tokens[$i + 2] ?? null;
+
+    $ok = $before2 !== null && $before2['id'] === T_CONSTANT_ENCAPSED_STRING
+        && trim($before2['text'], "'\"") === 'reason'
+        && $before1 !== null && $before1['id'] === T_DOUBLE_ARROW
+        && $after1 !== null && $after1['id'] === T_OBJECT_OPERATOR
+        && $after2 !== null && $after2['text'] === 'value';
+
+    if (! $ok) {
+        return [$label.": \$reason の唯一の参照が \"'reason' => \$reason->value\" の位置にない "
+            .'(理由は観測であって制御ではない)'];
+    }
+
+    return [];
+}
+
+/** 資格情報の 4 表 (この表の失効列だけが本 gate の対象)。 */
+function orgRevocationCredentialTables(): array
+{
+    return ['oauth_sessions', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes'];
+}
+
+/**
+ * 資格情報の 4 表の名前を文字列リテラルとして持つファイルか。
+ *
+ * ★列名 (`revoked` / `revoked_at`) だけで判定すると、API キーの失効
+ * (`api_keys.revoked_at`) や招待の取り消し (`organization_invitations.revoked_at`) まで
+ * 拾ってしまう。**別物の概念を列名の一致だけで統合しない**ため、表の名前と対にする。
+ */
+function orgRevocationTouchesCredentialTable(string $phpSource): bool
+{
+    foreach (PhpTokenScan::normalize($phpSource) as $token) {
+        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+        if (in_array(trim($token['text'], "'\""), orgRevocationCredentialTables(), true)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * `update([...])` / `forceFill([...])` の引数に失効列 (`revoked` / `revoked_at`) を
+ * 含むファイルか (= 失効列への書き込み)。
+ *
+ * 受け手はメソッド呼び出し (`->`) と静的呼び出し (`::`) の両方を見る。
+ * **どちらでもない書き方 (属性への直接代入など) には沈黙する** —
+ * これは検出であって網羅の証明ではない。
+ */
+function orgRevocationHasRevocationColumnWrite(string $phpSource): bool
+{
+    $tokens = PhpTokenScan::normalize($phpSource);
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+        if ($text !== 'update' && $text !== 'forceFill') {
+            continue;
+        }
+        $receiver = $tokens[$i - 1]['id'] ?? null;
+        if ($receiver !== T_OBJECT_OPERATOR && $receiver !== T_DOUBLE_COLON) {
+            continue;
+        }
+        if (($tokens[$i + 1]['text'] ?? '') !== '(') {
+            continue;
+        }
+
+        // 呼び出しの括弧が閉じるまでを引数の範囲とする
+        $depth = 0;
+        for ($j = $i + 1; $j < $count; $j++) {
+            $t = $tokens[$j]['text'];
+            if ($t === '(') {
+                $depth++;
+
+                continue;
+            }
+            if ($t === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    break;
+                }
+
+                continue;
+            }
+            if ($tokens[$j]['id'] === T_CONSTANT_ENCAPSED_STRING
+                && in_array(trim($t, "'\""), ['revoked', 'revoked_at'], true)) {
+                return true;
+            }
+        }
+    }
+
+    return false;
+}
+
+/** 資格情報 4 表の失効列へ書き込むファイルか (表の名前と失効列の書き込みの両方を持つ)。 */
+function orgRevocationWritesCredentialRevocation(string $phpSource): bool
+{
+    return orgRevocationTouchesCredentialTable($phpSource)
+        && orgRevocationHasRevocationColumnWrite($phpSource);
+}
+
+/**
+ * 失効列へ書き込んでよいファイル (allowlist)。
+ *
+ * @return array<string, string> 相対パス => 理由
+ */
+function orgRevocationWriteAllowlist(): array
+{
+    return [
+        'app/Services/OAuth/OrganizationAccessRevoker.php' => '本件の窓口 (ある組織におけるある利用者の資格情報をまとめて失効させる)',
+        'app/Models/OauthSession.php' => '画面 / CLI からの 1 セッションだけの失効。対象の広さが違うので窓口と統合しない',
+    ];
+}
+
+test('検査A: 役割を書き込むメソッドはすべて分類されている (未分類は fail)', function (): void {
+    $classification = orgRevocationClassification();
+    $reflection = new ReflectionClass(OrganizationMembershipService::class);
+
+    $population = [];
+    foreach ($reflection->getMethods() as $method) {
+        if ($method->getDeclaringClass()->getName() !== OrganizationMembershipService::class) {
+            continue;
+        }
+        $body = orgRevocationCompact(orgRevocationMethodBody(OrganizationMembershipService::class, $method->getName()));
+        foreach (orgRevocationRoleWriteMarkers() as $marker) {
+            if (str_contains($body, $marker)) {
+                $population[] = $method->getName();
+
+                break;
+            }
+        }
+    }
+
+    sort($population);
+    $declared = array_keys($classification);
+    sort($declared);
+
+    expect($population)->toBe($declared,
+        '役割を書き込むメソッドの集合と分類表が一致しません。新しい経路は '
+        .'失効を呼ぶ (revokes) か、免除目録へ登録する (exempt) かのどちらかに分類してください。');
+});
+
+test('検査A2: 分類の値は revokes / exempt のいずれかで、exempt は免除目録に登録されている', function (): void {
+    $violations = [];
+
+    foreach (orgRevocationClassification() as $method => $kind) {
+        if (! in_array($kind, ['revokes', 'exempt'], true)) {
+            $violations[] = "{$method}: 未知の分類 {$kind}";
+
+            continue;
+        }
+        if ($kind !== 'exempt') {
+            continue;
+        }
+        $exemption = orgRevocationExemptions()[$method] ?? null;
+        if (! $exemption instanceof OrgAccessRevocationExemption) {
+            $violations[] = "{$method}: exempt なのに免除目録に登録がありません";
+
+            continue;
+        }
+        if ($exemption->value !== 'OrganizationMembershipService::'.$method) {
+            $violations[] = "{$method}: 免除目録の case 値がメソッドを指していません ({$exemption->value})";
+        }
+        if (mb_strlen($exemption->rationale()) < orgRevocationReasonMinLength()) {
+            $violations[] = "{$method}: 免除の根拠が ".orgRevocationReasonMinLength().' 文字未満です';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査A3: 免除の件数が宣言値と一致する (増えても減っても検出する)', function (): void {
+    expect(count(orgRevocationExemptions()))->toBe(orgRevocationExemptionCount(),
+        '免除を増減させたら orgRevocationExemptionCount() も書き換えてください '
+        .'(件数の変化が必ず差分に現れるようにするため)。');
+    expect(count(OrgAccessRevocationExemption::cases()))->toBe(orgRevocationExemptionCount(),
+        'OrgAccessRevocationExemption の case 数と目録の件数が食い違っています (死んだ case の残置)。');
+});
+
+test('検査B: revokes に分類したメソッドは最後の役割書き込みより後で失効を呼ぶ', function (): void {
+    $violations = [];
+
+    foreach (orgRevocationClassification() as $method => $kind) {
+        if ($kind !== 'revokes') {
+            continue;
+        }
+        $body = orgRevocationMethodBody(OrganizationMembershipService::class, $method);
+        $violations = [...$violations, ...orgRevocationBodyViolations($method, $body)];
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査B2: revokes の失効は必ずひとまとまり (トランザクション) の内側にある', function (): void {
+    $violations = [];
+
+    foreach (orgRevocationTransactionOwners() as $method => $owner) {
+        $body = orgRevocationCompact(orgRevocationMethodBody(OrganizationMembershipService::class, $method));
+
+        if ($owner === 'self') {
+            if (! str_contains($body, 'DB::transaction(')) {
+                $violations[] = "{$method}: 自分でトランザクションを張る宣言なのに DB::transaction( が無い";
+            }
+
+            continue;
+        }
+
+        $ownerBody = orgRevocationCompact(orgRevocationMethodBody(OrganizationMembershipService::class, $owner));
+        if (! str_contains($ownerBody, 'DB::transaction(')) {
+            $violations[] = "{$method}: 呼び出し元 {$owner} が DB::transaction( を張っていない";
+        }
+        if (! str_contains($ownerBody, '->'.$method.'(')) {
+            $violations[] = "{$method}: 呼び出し元 {$owner} が {$method}() を呼んでいない (宣言が陳腐化)";
+        }
+    }
+
+    // 宣言表と分類表 (revokes) の集合が一致すること
+    $declared = array_keys(orgRevocationTransactionOwners());
+    $revokes = array_keys(array_filter(orgRevocationClassification(), static fn (string $k): bool => $k === 'revokes'));
+    sort($declared);
+    sort($revokes);
+    expect($declared)->toBe($revokes, 'revokes の集合とトランザクション出所の宣言表が一致していません。');
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査C: 検出器の負例 (空振り防止)', function (): void {
+    // 1. 失効の呼び出しが無い
+    $noRevoke = '{ $u->addRole($r, $team); }';
+    expect(orgRevocationBodyViolations('fixture', $noRevoke))->toHaveCount(1);
+    expect(orgRevocationBodyViolations('fixture', $noRevoke)[0])->toContain('が本文に無い');
+
+    // 2. 失効が役割書き込みより前にある
+    $before = '{ $this->accessRevoker->revoke($org, $u, $reason, $actor); $u->addRole($r, $team); }';
+    expect(orgRevocationBodyViolations('fixture', $before))->toHaveCount(1);
+    expect(orgRevocationBodyViolations('fixture', $before)[0])
+        ->toContain('失効の呼び出しが最後の役割書き込みより前にある');
+
+    // 3. 役割書き込みが 2 回あり、失効がその間にある
+    $between = '{ $u->removeRole($old, $team); $this->accessRevoker->revoke($org, $u, $reason, $actor);'
+        .' $u->addRole($new, $team); }';
+    expect(orgRevocationBodyViolations('fixture', $between))->toHaveCount(1);
+
+    // 4. 正例 (検出器が何でも赤くするわけではないことの対照)
+    $ok = '{ $u->removeRole($old, $team); $u->addRole($new, $team);'
+        .' $this->accessRevoker->revoke($org, $u, $reason, $actor); }';
+    expect(orgRevocationBodyViolations('fixture', $ok))->toBe([]);
+
+    // 5. コメントの中の呼び出しは数えない (正規化がコメントを落とすことの確認)
+    $comment = '{ $u->addRole($new, $team); // $this->accessRevoker->revoke(...) を後で足す'.PHP_EOL.'}';
+    expect(orgRevocationBodyViolations('fixture', $comment))->toHaveCount(1);
+});
+
+test('検査D: 検出できる書き方で失効列へ書き込むのは allowlist のファイルだけ', function (): void {
+    $allowlist = orgRevocationWriteAllowlist();
+    $violations = [];
+    $found = [];
+
+    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
+    foreach ($iterator as $file) {
+        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $relative = str_replace(base_path().'/', '', $file->getPathname());
+        $source = (string) file_get_contents($file->getPathname());
+        if (! orgRevocationWritesCredentialRevocation($source)) {
+            continue;
+        }
+        $found[] = $relative;
+        if (! array_key_exists($relative, $allowlist)) {
+            $violations[] = $relative.': 資格情報の失効列への書き込みは窓口 (OrganizationAccessRevoker) へ'
+                .'集約するか、対象の広さが違う理由を添えて allowlist へ登録してください';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+
+    sort($found);
+    $declared = array_keys($allowlist);
+    sort($declared);
+    expect($found)->toBe($declared, 'allowlist に現存しないファイルが残っています (stale 検出)。');
+});
+
+test('検査D2: 検出器の負例 (表示用の配列や別テーブルの失効は数えない)', function (): void {
+    $write = '<?php DB::table(\'oauth_access_tokens\')->whereIn(\'id\', $ids)->update([\'revoked\' => true]);';
+    expect(orgRevocationWritesCredentialRevocation($write))->toBeTrue();
+
+    $writeAt = '<?php DB::table(\'oauth_sessions\')->where(\'id\', $id)'
+        .'->update([\'revoked_at\' => now()]);';
+    expect(orgRevocationWritesCredentialRevocation($writeAt))->toBeTrue();
+
+    // 表示用の配列 (書き込みではない)
+    $display = '<?php DB::table(\'oauth_sessions\')->get(); return [\'revoked_at\' => $this->revokedAt];';
+    expect(orgRevocationWritesCredentialRevocation($display))->toBeFalse();
+
+    // 資格情報の表だが失効列ではない列の更新
+    $otherColumn = '<?php DB::table(\'oauth_access_tokens\')->where(\'id\', $id)->update([\'session_id\' => $id]);';
+    expect(orgRevocationWritesCredentialRevocation($otherColumn))->toBeFalse();
+
+    // 別テーブルの失効 (API キー / 招待の取り消し) は本 gate の対象外
+    $apiKey = '<?php $key->forceFill([\'revoked_at\' => now()])->save();';
+    expect(orgRevocationWritesCredentialRevocation($apiKey))->toBeFalse();
+
+    // 1 セッションだけの失効 (OauthSession) は allowlist 側なので検出されて構わない
+    expect(orgRevocationWritesCredentialRevocation(
+        (string) file_get_contents((new ReflectionClass(OauthSession::class))->getFileName() ?: ''),
+    ))->toBeTrue();
+});
+
+test('検査E: 窓口の revoke() は理由を監査 metadata にしか使わない', function (): void {
+    $body = orgRevocationMethodBody(OrganizationAccessRevoker::class, 'revoke');
+
+    expect(orgRevocationReasonUsageViolations('OrganizationAccessRevoker::revoke', $body))->toBe([],
+        '理由 ($reason) は観測であって制御ではありません。分岐に使うと理由の追加が挙動の変更になります。');
+});
+
+test('検査E2: 検出器の負例 (理由の使われ方)', function (): void {
+    // 別メソッドへ逃がす形 (回数は 1 だが位置が違う)
+    $delegated = '{ $this->applyRevocationPolicy($reason); }';
+    expect(orgRevocationReasonUsageViolations('fixture', $delegated))->toHaveCount(1);
+
+    // 分岐に使う形
+    $branch = '{ $x = match ($reason) { A => 1 }; }';
+    expect(orgRevocationReasonUsageViolations('fixture', $branch))->toHaveCount(1);
+
+    // metadata に固定文字列を入れ、別用途で 1 回使う形
+    $decoy = "{ \$m = ['reason' => 'fixed']; \$this->log(\$reason); }";
+    expect(orgRevocationReasonUsageViolations('fixture', $decoy))->toHaveCount(1);
+
+    // 正例 + 説明のコメントに $reason と書いてあっても緑
+    $ok = '{ // $reason は観測にしか使わない'.PHP_EOL
+        ."\$this->recorder->recordOrFail(\$type, \$u, ['reason' => \$reason->value]); }";
+    expect(orgRevocationReasonUsageViolations('fixture', $ok))->toBe([]);
+});
+
+test('検査G: ひとまとまりの外から窓口を呼ぶと実行時に拒否される', function (): void {
+    // ★このレーンに置く理由: Feature / Unit レーンは RefreshDatabase が全体を
+    //   トランザクションで包むため、深さ 0 の状態を作れず「外から呼ぶ」形を再現できない。
+    //   Architecture レーンは RefreshDatabase を使わないので深さ 0 のまま呼べる。
+    //   引数のモデルは検査の前に例外になるため保存不要 (DB に触れない)。
+    expect(DB::transactionLevel())->toBe(0);
+
+    expect(fn () => app(OrganizationAccessRevoker::class)->revoke(
+        new Organization,
+        new User,
+        OrgAccessRevocationReason::RoleChanged,
+        null,
+    ))->toThrow(InvalidArgumentException::class);
+});
+
+test('検査F: 失効の監査は握り潰さない版 (recordOrFail) を使う', function (): void {
+    $body = orgRevocationCompact(orgRevocationMethodBody(OrganizationAccessRevoker::class, 'revoke'));
+
+    expect($body)->toContain('->recordOrFail(');
+    expect(str_contains($body, '->record('))->toBeFalse(
+        '握り潰す版 (record) に差し替わると「資格情報は失効したが監査に残っていない」状態が'
+        .'静かに生まれます。書き分けを構造で固定します。',
+    );
+});
diff --git a/tests/Architecture/RestWriteScopeRevalidationInvariantTest.php b/tests/Architecture/RestWriteScopeRevalidationInvariantTest.php
new file mode 100644
index 0000000..8f75681
--- /dev/null
+++ b/tests/Architecture/RestWriteScopeRevalidationInvariantTest.php
@@ -0,0 +1,313 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\ApiKeyAbility;
+use App\Enums\OAuth\CliOAuthScope;
+use App\Enums\Security\ApiWriteScopeExemption;
+use App\Http\Controllers\Api\V1\Me\RevokeSessionController;
+use App\Http\Middleware\RequireApiKeyAbility;
+use App\Http\Middleware\ResolveApiActor;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\Str;
+
+/*
+ * 外部向け API (REST v1) の書き込み資格と、実行時の主体再評価の invariant (既定拒否)。
+ *
+ * 組織の役割変更に同期した失効 ({@see \App\Services\OAuth\OrganizationAccessRevoker}) は
+ * 「発行済みの資格情報を切る」側の防御である。切れなかった / 切る前の要求に対する
+ * 最後の拒否線は **要求ごとの再評価** であり、その再評価の実在をここで固定する。
+ *
+ * 検査は 3 つ:
+ *  A. `api.v1.` の変更系 route は書き込み資格をちょうど 1 本持つか、免除目録に登録されている
+ *  B. 免除の**前提**が実際に成立している (空疎な免除の禁止)
+ *  C. 主体の解決 (`ResolveApiActor`) の再評価が消えていない
+ *
+ * ★**扱わないこと** (二重管理の回避):
+ *   middleware の実行順序は `TenantBoundaryOrderingTest`、認証 guard の分類は
+ *   `ApiGuardAllowlistInvariantTest`、冪等キーの配線は `IdempotentRouteCoverageTest` の担当。
+ *
+ * ★**保証範囲を誇張しない**: 見ているのは名前が `api.v1.` で始まる route だけである。
+ *   web 側の変更系・`oauth/*`・MCP transport・将来別 prefix の機械向け API には**沈黙する**
+ *   (MCP 側は `McpAuthorizationChokePointTest` が別に担当する)。
+ *   検査 C は字句検査なので「呼んでいるが結果を使っていない」形は落とせない。
+ *   実挙動は `tests/Feature/Api/OAuthDualGuardTest.php` と
+ *   `tests/Feature/Organizations/OrganizationAccessRevocationTest.php` が担う。
+ */
+
+/** 変更系 HTTP メソッド。 */
+function restWriteScopeMutatingMethods(): array
+{
+    return ['POST', 'PUT', 'PATCH', 'DELETE'];
+}
+
+/**
+ * 母集団件数 (**完全一致**)。
+ *
+ * 余裕を持たせるとセレクタが壊れて母集団が減っても気づけない。
+ * route を増減させたらこの数値も書き換えること。
+ */
+function restWriteScopeRouteCount(): int
+{
+    return 4;
+}
+
+/** 免除の件数 (完全一致)。 */
+function restWriteScopeExemptionCount(): int
+{
+    return 1;
+}
+
+/** 免除理由の最低文字数。 */
+function restWriteScopeReasonMinLength(): int
+{
+    return 30;
+}
+
+/**
+ * 書き込み資格を持たないことが正しいと裁定した route の目録。
+ *
+ * @return array<string, ApiWriteScopeExemption>
+ */
+function restWriteScopeExemptions(): array
+{
+    return [
+        'api.v1.me.session.revoke' => ApiWriteScopeExemption::DedicatedSessionRevokeScope,
+    ];
+}
+
+/**
+ * 免除の**前提**の機械検査 (空疎な免除の禁止)。
+ *
+ * @return array<string, array{class: class-string, marker: string}>
+ */
+function restWriteScopePremises(): array
+{
+    return [
+        'api.v1.me.session.revoke' => [
+            'class' => RevokeSessionController::class,
+            // 専用資格を実際に見ていること
+            'marker' => 'CliOAuthScope::SessionRevoke',
+        ],
+    ];
+}
+
+/** 解決後 middleware 列 (文字列 entry のみ)。 */
+function restWriteScopeResolvedMiddleware(RoutingRoute $route): array
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+
+    return array_values(array_filter(
+        $router->gatherRouteMiddleware($route),
+        static fn (mixed $entry): bool => is_string($entry),
+    ));
+}
+
+/** 実効 middleware 列に含まれる「書き込み資格」の本数。 */
+function restWriteScopeWriteAbilityCount(RoutingRoute $route): int
+{
+    $count = 0;
+    foreach (restWriteScopeResolvedMiddleware($route) as $entry) {
+        if (! is_a(Str::before($entry, ':'), RequireApiKeyAbility::class, true)) {
+            continue;
+        }
+        if (Str::after($entry, ':') === ApiKeyAbility::Write->value) {
+            $count++;
+        }
+    }
+
+    return $count;
+}
+
+/** 実効 middleware 列に主体解決 (`resolve.api-actor`) があるか。 */
+function restWriteScopeHasActorResolution(RoutingRoute $route): bool
+{
+    foreach (restWriteScopeResolvedMiddleware($route) as $entry) {
+        if (is_a(Str::before($entry, ':'), ResolveApiActor::class, true)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/** @return list<RoutingRoute> 母集団 (名前が api.v1. で始まる変更系) */
+function restWriteScopeRoutes(): array
+{
+    $mutating = restWriteScopeMutatingMethods();
+    $selected = [];
+
+    foreach (Route::getRoutes() as $route) {
+        $name = $route->getName();
+        if ($name === null || ! str_starts_with($name, 'api.v1.')) {
+            continue;
+        }
+        if (array_intersect($mutating, $route->methods()) === []) {
+            continue;
+        }
+        $selected[] = $route;
+    }
+
+    return $selected;
+}
+
+/**
+ * 違反検出の本体 (負のコントロールから再利用するため関数に切り出す)。
+ *
+ * @return list<string>
+ */
+function restWriteScopeViolations(): array
+{
+    $exemptions = restWriteScopeExemptions();
+    $violations = [];
+
+    foreach (restWriteScopeRoutes() as $route) {
+        $name = (string) $route->getName();
+        $count = restWriteScopeWriteAbilityCount($route);
+
+        if ($count === 1) {
+            continue;
+        }
+        if ($count === 0 && array_key_exists($name, $exemptions)) {
+            continue;
+        }
+
+        $violations[] = $count === 0
+            ? "{$name}: 書き込み資格 (api-key.ability:write) が無く免除目録にも未登録"
+            : "{$name}: 書き込み資格が {$count} 本ある";
+    }
+
+    return $violations;
+}
+
+/** クラスのメソッド本文 (最初の `{` 以降) を素のソースとして取り出す。 */
+function restWriteScopeMethodBody(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $file = (string) $reflection->getFileName();
+    $lines = file($file, FILE_IGNORE_NEW_LINES);
+    expect($lines)->toBeArray();
+    /** @var list<string> $lines */
+    $source = implode(PHP_EOL, array_slice(
+        $lines,
+        $reflection->getStartLine() - 1,
+        $reflection->getEndLine() - $reflection->getStartLine() + 1,
+    ));
+    $brace = strpos($source, '{');
+
+    return $brace === false ? '' : substr($source, $brace);
+}
+
+test('母集団の件数が宣言値と一致する (セレクタの空振り検出)', function (): void {
+    expect(count(restWriteScopeRoutes()))->toBe(restWriteScopeRouteCount(),
+        'api.v1. の変更系 route の件数が宣言値と違います。route を増減させたら '
+        .'restWriteScopeRouteCount() も書き換えてください (セレクタが空振りしても気づけるようにするため)。');
+});
+
+test('検査A: 変更系 route は書き込み資格をちょうど 1 本持つか免除目録に登録されている', function (): void {
+    expect(restWriteScopeViolations())->toBe([],
+        '書き込み資格を配線するか、配線しないことが正しい理由を restWriteScopeExemptions() へ'
+        .'ApiWriteScopeExemption 付きで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, restWriteScopeViolations()));
+});
+
+test('検査A2: 免除の件数と根拠 (形骸化ガード)', function (): void {
+    expect(count(restWriteScopeExemptions()))->toBe(restWriteScopeExemptionCount());
+    expect(count(ApiWriteScopeExemption::cases()))->toBe(restWriteScopeExemptionCount(),
+        'ApiWriteScopeExemption の case 数と目録の件数が食い違っています (死んだ case の残置)。');
+
+    $violations = [];
+    foreach (restWriteScopeExemptions() as $name => $exemption) {
+        if (mb_strlen($exemption->rationale()) < restWriteScopeReasonMinLength()) {
+            $violations[] = "{$name}: 根拠が ".restWriteScopeReasonMinLength().' 文字未満です';
+        }
+    }
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査A3: 免除目録の key は現存する母集団 route (stale 検出)', function (): void {
+    $names = [];
+    foreach (restWriteScopeRoutes() as $route) {
+        $names[(string) $route->getName()] = true;
+    }
+
+    $stale = array_values(array_filter(
+        array_keys(restWriteScopeExemptions()),
+        static fn (string $name): bool => ! isset($names[$name]),
+    ));
+
+    expect($stale)->toBe([], '免除目録に現存しない route があります: '.implode(', ', $stale));
+});
+
+test('検査B: 免除の前提が実際に成立している (空疎な免除の禁止)', function (): void {
+    $violations = [];
+
+    foreach (restWriteScopeExemptions() as $name => $exemption) {
+        $premise = restWriteScopePremises()[$name] ?? null;
+        if ($premise === null) {
+            $violations[] = "{$name}: 免除の前提が宣言されていません";
+
+            continue;
+        }
+        $file = (new ReflectionClass($premise['class']))->getFileName();
+        $source = $file === false ? '' : (string) file_get_contents($file);
+        if (! str_contains($source, $premise['marker'])) {
+            $violations[] = "{$name}: {$premise['class']} が {$premise['marker']} を参照していません "
+                .'(免除の根拠が実装から消えています)';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査B2: 専用資格の case が実在する (前提の裏取り)', function (): void {
+    // 前提の marker が指す enum case が消えたら、marker の文字列照合だけでは気づけない
+    expect(CliOAuthScope::SessionRevoke->value)->toBe('session.revoke');
+});
+
+test('検査C: 主体の解決が所属とセッションを毎回再評価している', function (): void {
+    $body = restWriteScopeMethodBody(ResolveApiActor::class, 'contextFromUserToken');
+
+    expect(str_contains($body, 'isRevoked('))->toBeTrue(
+        'セッションの生存の再評価が消えています (失効済みセッションの token が通るようになります)。');
+    expect(str_contains($body, 'isMemberOf('))->toBeTrue(
+        '所属の再評価が消えています (組織から外れた人の token が通るようになります)。');
+});
+
+test('検査C2: 母集団の変更系 route はすべて主体解決を通る', function (): void {
+    $violations = [];
+
+    foreach (restWriteScopeRoutes() as $route) {
+        if (! restWriteScopeHasActorResolution($route)) {
+            $violations[] = (string) $route->getName().': resolve.api-actor が無い';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('負のコントロール: 書き込み資格の無い api.v1 変更系 route を検出する', function (): void {
+    Route::post('api/v1/__write_scope_negative_control__', fn (): string => 'ok')
+        ->name('api.v1.__write_scope_negative_control__');
+
+    expect(restWriteScopeViolations())
+        ->toContain('api.v1.__write_scope_negative_control__: 書き込み資格 (api-key.ability:write) が無く免除目録にも未登録');
+});
+
+test('正のコントロール: 書き込み資格つきの api.v1 変更系 route は違反にならない', function (): void {
+    Route::post('api/v1/__write_scope_positive_control__', fn (): string => 'ok')
+        ->middleware('api-key.ability:write')
+        ->name('api.v1.__write_scope_positive_control__');
+
+    // ★「violations 全体が空」で見ない。既存母集団側に違反があると、この対照テストまで
+    //   一緒に赤くなり「追加した route が違反にならないこと」を単独で証明できないためである。
+    $named = array_values(array_filter(
+        restWriteScopeViolations(),
+        static fn (string $violation): bool => str_starts_with($violation, 'api.v1.__write_scope_positive_control__'),
+    ));
+
+    expect($named)->toBe([]);
+});
diff --git a/tests/Feature/Organizations/OrganizationAccessRevocationTest.php b/tests/Feature/Organizations/OrganizationAccessRevocationTest.php
new file mode 100644
index 0000000..d957f10
--- /dev/null
+++ b/tests/Feature/Organizations/OrganizationAccessRevocationTest.php
@@ -0,0 +1,659 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\AdminConsoleRole;
+use App\Enums\OrganizationRole;
+use App\Enums\ProjectRole;
+use App\Enums\Security\OrgAccessRevocationReason;
+use App\Enums\SecurityEventType;
+use App\Models\ApiKey;
+use App\Models\OauthSession;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use App\Services\Security\SecurityEventRecorder;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+use Illuminate\Validation\ValidationException;
+use Inertia\Testing\AssertableInertia;
+use Tests\Support\OAuthTestHelpers;
+
+/*
+ * 組織の役割変更に同期したトークン失効 (家系の正典 v2) の振る舞い。
+ *
+ * 失効の境界は「役割を変える操作が成功したこと」であり、役割の集合の差分は取らない。
+ * その帰結として**昇格でも接続はやり直しになる**。ここではその仕様と、
+ * 失効する 3 家族 / 失効させないもの (組織の API キー・プロジェクト単位の役割) の
+ * 境界を固定する。
+ */
+
+/**
+ * 資格情報の 1 揃い (セッション / セッション付きトークン / セッション無しトークン /
+ * 更新トークン / 未交換の認可コード) を作る。
+ *
+ * `oauth_*` は Passport の vendor テーブルで Factory を持たない
+ * (`OauthSession` だけが自前モデル) ため、素の insert で組む。
+ *
+ * @return array{session: OauthSession, bound: string, orphan: string, refresh: string, code: string}
+ */
+function revocationCredentials(Organization $organization, User $user): array
+{
+    /** @var OauthSession $session */
+    $session = OauthSession::factory()->cli()->create([
+        'user_id' => $user->id,
+        'organization_id' => $organization->id,
+    ]);
+
+    $clientId = $session->client_id;
+
+    $bound = revocationInsertAccessToken($organization, $user, $clientId, $session->id);
+    $orphan = revocationInsertAccessToken($organization, $user, $clientId, null);
+    $refresh = revocationInsertRefreshToken($bound);
+    $code = revocationInsertAuthCode($organization, $user, $clientId);
+
+    return [
+        'session' => $session,
+        'bound' => $bound,
+        'orphan' => $orphan,
+        'refresh' => $refresh,
+        'code' => $code,
+    ];
+}
+
+function revocationInsertAccessToken(Organization $organization, User $user, string $clientId, ?string $sessionId, bool $revoked = false): string
+{
+    $id = Str::random(80);
+    DB::table('oauth_access_tokens')->insert([
+        'id' => $id,
+        'user_id' => $user->id,
+        'organization_id' => $organization->id,
+        'session_id' => $sessionId,
+        'client_id' => $clientId,
+        'scopes' => json_encode(['cli:use', 'read']),
+        'revoked' => $revoked,
+        'created_at' => now(),
+        'updated_at' => now(),
+        'expires_at' => now()->addDay(),
+    ]);
+
+    return $id;
+}
+
+function revocationInsertRefreshToken(string $accessTokenId, bool $revoked = false): string
+{
+    $id = Str::random(80);
+    DB::table('oauth_refresh_tokens')->insert([
+        'id' => $id,
+        'access_token_id' => $accessTokenId,
+        'revoked' => $revoked,
+        'expires_at' => now()->addDays(14),
+    ]);
+
+    return $id;
+}
+
+function revocationInsertAuthCode(Organization $organization, User $user, string $clientId, bool $revoked = false): string
+{
+    $id = Str::random(80);
+    DB::table('oauth_auth_codes')->insert([
+        'id' => $id,
+        'user_id' => $user->id,
+        'organization_id' => $organization->id,
+        'session_id' => null,
+        'client_id' => $clientId,
+        'scopes' => json_encode(['cli:use']),
+        'revoked' => $revoked,
+        'expires_at' => now()->addMinutes(10),
+    ]);
+
+    return $id;
+}
+
+/** アクセストークンが失効済みか。 */
+function revocationTokenIsRevoked(string $id): bool
+{
+    return (bool) DB::table('oauth_access_tokens')->where('id', $id)->value('revoked');
+}
+
+/** 更新トークンが失効済みか。 */
+function revocationRefreshIsRevoked(string $id): bool
+{
+    return (bool) DB::table('oauth_refresh_tokens')->where('id', $id)->value('revoked');
+}
+
+/** 認可コードが失効済みか。 */
+function revocationCodeIsRevoked(string $id): bool
+{
+    return (bool) DB::table('oauth_auth_codes')->where('id', $id)->value('revoked');
+}
+
+/** 直近の失効監査 (metadata 付き)。 */
+function revocationLatestAudit(): ?SecurityAuditEvent
+{
+    /** @var SecurityAuditEvent|null $event */
+    $event = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::OrganizationAccessRevoked->value)
+        ->orderByDesc('id')
+        ->first();
+
+    return $event;
+}
+
+/** 失効監査の件数。 */
+function revocationAuditCount(): int
+{
+    return SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::OrganizationAccessRevoked->value)
+        ->count();
+}
+
+// ---------------------------------------------------------------------------
+// A. 失効そのもの
+// ---------------------------------------------------------------------------
+
+test('降格すると 3 家族 (セッション / トークン / 認可コード) がまとめて失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('失効組織');
+    $member = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)
+        ->changeRole($organization, $member, OrganizationRole::Member, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
+    expect(revocationTokenIsRevoked($credentials['orphan']))->toBeTrue();
+    expect(revocationRefreshIsRevoked($credentials['refresh']))->toBeTrue();
+    expect(revocationCodeIsRevoked($credentials['code']))->toBeTrue();
+});
+
+test('昇格でも接続は切れる (役割の差分で判断しない仕様)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('昇格組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)
+        ->changeRole($organization, $member, OrganizationRole::Admin, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
+});
+
+test('同じ役割への変更 (冪等の早期 return) では失効しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('冪等組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)
+        ->changeRole($organization, $member, OrganizationRole::Member, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeFalse();
+    expect(revocationAuditCount())->toBe(0);
+});
+
+test('除名すると失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('除名組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
+    expect(revocationCodeIsRevoked($credentials['code']))->toBeTrue();
+});
+
+test('オーナー移譲では譲り手と受け手の両方が切れる (受け手も切れる)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('移譲組織');
+    $successor = attachOrganizationMember($organization, OrganizationRole::Admin);
+
+    $ownerCredentials = revocationCredentials($organization, $owner);
+    $successorCredentials = revocationCredentials($organization, $successor);
+
+    app(OrganizationMembershipService::class)->transferOwnership($organization, $owner, $successor);
+
+    expect($ownerCredentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($ownerCredentials['bound']))->toBeTrue();
+    expect($successorCredentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($successorCredentials['bound']))->toBeTrue();
+});
+
+test('修復経路 (役割未付与の行への直接付与) でも失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('修復組織');
+    Project::factory()->forOrganization($organization)->create();
+
+    // 異常行を再現: attach のみでロール未付与 (表示状態は「未割当」)
+    $broken = User::factory()->create();
+    $organization->users()->attach($broken);
+    $credentials = revocationCredentials($organization, $broken);
+
+    app(OrganizationMembershipService::class)
+        ->applyConsoleRole($organization, $broken, AdminConsoleRole::Shooter, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
+});
+
+test('プロジェクト側の割当だけが変わり組織ロールが同値なら失効しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('割当組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $member = attachOrganizationMember($organization);
+    attachProjectMember($project, $member, ProjectRole::Admin);
+    $credentials = revocationCredentials($organization, $member);
+
+    // editor → shooter は組織ロールが Member のまま (プロジェクト側の pivot だけ変わる)
+    app(OrganizationMembershipService::class)
+        ->applyConsoleRole($organization, $member, AdminConsoleRole::Shooter, $owner);
+
+    expect($project->memberRole($member))->toBe(ProjectRole::Member);
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationAuditCount())->toBe(0);
+});
+
+test('招待受諾 (組織に入れる操作) では失効しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('招待組織');
+    $invitee = User::factory()->create();
+
+    $invitation = app(OrganizationMembershipService::class)
+        ->inviteMember($organization, $owner, 'invitee@example.test', OrganizationRole::Member);
+    // 平文 token は保存されないため、既知の平文に対応する hash へ差し替える
+    $invitation->forceFill(['token_hash' => hash('sha256', 'join-token')])->save();
+
+    $this->actingAs($invitee)->post('/invitations/accept', ['token' => 'join-token'])
+        ->assertRedirect('/dashboard');
+
+    expect($organization->users()->whereKey($invitee->getKey())->exists())->toBeTrue();
+    // 免除の前提: 入れる操作では失効の窓口を呼ばない (監査が 1 行も増えない)
+    expect(revocationAuditCount())->toBe(0);
+});
+
+// ---------------------------------------------------------------------------
+// B. 家族ごとの独立性と網羅性
+// ---------------------------------------------------------------------------
+
+test('セッション行が 1 件も無くても、トークンと認可コードは失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('セッション無し組織');
+    $member = attachOrganizationMember($organization);
+
+    $client = OAuthTestHelpers::createMcpClient(name: 'セッション無し');
+    $clientId = (string) $client->getKey();
+    $token = revocationInsertAccessToken($organization, $member, $clientId, null);
+    $code = revocationInsertAuthCode($organization, $member, $clientId);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect(revocationTokenIsRevoked($token))->toBeTrue();
+    expect(revocationCodeIsRevoked($code))->toBeTrue();
+
+    $audit = revocationLatestAudit();
+    expect($audit?->metadata['revoked']['sessions'] ?? null)->toBe(0);
+    expect($audit?->metadata['revoked']['access_tokens'] ?? null)->toBe(1);
+});
+
+test('親のトークンが既に失効済みで更新トークンだけ未失効の不整合行も失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('不整合組織');
+    $member = attachOrganizationMember($organization);
+
+    $client = OAuthTestHelpers::createMcpClient(name: '不整合');
+    $clientId = (string) $client->getKey();
+    // 親は失効済み・子は未失効 (母集団を「未失効の親」に絞ると取り逃す形)
+    $parent = revocationInsertAccessToken($organization, $member, $clientId, null, revoked: true);
+    $refresh = revocationInsertRefreshToken($parent, revoked: false);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect(revocationRefreshIsRevoked($refresh))->toBeTrue();
+});
+
+test('他組織 / 他利用者の資格情報は 1 件も巻き添えにならない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('対象組織');
+    [$otherOrganization] = createOrganizationWithOwner('別組織');
+    $member = attachOrganizationMember($organization);
+    $bystander = attachOrganizationMember($organization);
+
+    // 同じ人の別組織ぶん
+    $otherOrganization->users()->attach($member);
+    $member->addRole(OrganizationRole::Member->value, $otherOrganization->laratrust_team_id);
+
+    $target = revocationCredentials($organization, $member);
+    $crossOrg = revocationCredentials($otherOrganization, $member);
+    $otherUser = revocationCredentials($organization, $bystander);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect(revocationTokenIsRevoked($target['bound']))->toBeTrue();
+    expect(revocationTokenIsRevoked($crossOrg['bound']))->toBeFalse();
+    expect($crossOrg['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationTokenIsRevoked($otherUser['bound']))->toBeFalse();
+    expect($otherUser['session']->fresh()?->revoked_at)->toBeNull();
+});
+
+test('除名の前に発行された認可コードは失効し、その後の交換が成立しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('認可コード組織');
+    $member = attachOrganizationMember($organization);
+
+    $pkce = OAuthTestHelpers::generatePkcePair();
+    $client = OAuthTestHelpers::createMcpClient(name: '認可コード確認');
+    $redirectUri = 'https://test.example/callback';
+
+    $this->actingAs($member);
+    $this->get(OAuthTestHelpers::buildAuthorizeUrl(
+        clientId: (string) $client->getKey(),
+        redirectUri: $redirectUri,
+        codeChallenge: $pkce['code_challenge'],
+    ));
+    $approve = $this->post('/oauth/authorize', [
+        'auth_token' => session('authToken'),
+        'organization_id' => $organization->id,
+    ]);
+    $code = OAuthTestHelpers::parseCallbackParams($approve)['code'] ?? '';
+    expect($code)->not->toBe('');
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+    Auth::forgetGuards();
+
+    $response = OAuthTestHelpers::exchangeTokenForm($this, [
+        'grant_type' => 'authorization_code',
+        'client_id' => (string) $client->getKey(),
+        'redirect_uri' => $redirectUri,
+        'code_verifier' => $pkce['code_verifier'],
+        'code' => $code,
+    ]);
+
+    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
+    expect($response->json('access_token'))->toBeNull();
+});
+
+// ---------------------------------------------------------------------------
+// C. ひとまとまりであること
+// ---------------------------------------------------------------------------
+
+/*
+ * 「ひとまとまりの外から窓口を呼ぶと例外になる」ことは**このレーンでは確認できない**。
+ * Feature / Unit レーンは RefreshDatabase が全体をトランザクションで包むため、
+ * トランザクションの深さが 0 の状態を作れないからである。
+ * その検査は tests/Architecture/OrganizationAccessRevocationChokePointTest.php に置く
+ * (Architecture レーンは RefreshDatabase を使わない)。
+ */
+
+test('役割変更が例外で失敗したら失効も巻き戻る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('巻き戻し組織');
+    $credentials = revocationCredentials($organization, $owner);
+
+    // 最後の Owner は降格できない (ロック下の検証で例外)
+    expect(fn () => app(OrganizationMembershipService::class)
+        ->changeRole($organization, $owner, OrganizationRole::Member, $owner))
+        ->toThrow(ValidationException::class);
+
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeFalse();
+    expect(revocationAuditCount())->toBe(0);
+});
+
+test('監査が書けないときは役割の変更ごと巻き戻る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('監査失敗組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    $this->partialMock(SecurityEventRecorder::class, function ($mock): void {
+        $mock->shouldReceive('recordOrFail')->andThrow(new RuntimeException('監査が書けない'));
+    });
+
+    expect(fn () => app(OrganizationMembershipService::class)
+        ->changeRole($organization, $member, OrganizationRole::Admin, $owner))
+        ->toThrow(RuntimeException::class);
+
+    expect($member->fresh()?->organizationRole($organization))->toBe(OrganizationRole::Member);
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeFalse();
+});
+
+// ---------------------------------------------------------------------------
+// D. 監査
+// ---------------------------------------------------------------------------
+
+test('失効が 0 件でも監査が 1 行残る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('0件組織');
+    $member = attachOrganizationMember($organization);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect(revocationAuditCount())->toBe(1);
+    $audit = revocationLatestAudit();
+    expect($audit?->metadata['revoked'] ?? null)->toBe([
+        'sessions' => 0,
+        'access_tokens' => 0,
+        'refresh_tokens' => 0,
+        'auth_codes' => 0,
+    ]);
+});
+
+test('監査に理由・操作した人・家族ごとの件数が入る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('監査組織');
+    $member = attachOrganizationMember($organization);
+    revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    $audit = revocationLatestAudit();
+    expect($audit)->not->toBeNull();
+    expect($audit?->user_id)->toBe($member->id);
+    expect($audit?->metadata['reason'] ?? null)->toBe(OrgAccessRevocationReason::MemberRemoved->value);
+    expect($audit?->metadata['actor_user_id'] ?? null)->toBe($owner->id);
+    expect($audit?->metadata['organization_id'] ?? null)->toBe($organization->id);
+    expect($audit?->metadata['revoked']['sessions'] ?? null)->toBe(1);
+    expect($audit?->metadata['revoked']['access_tokens'] ?? null)->toBe(2);
+    expect($audit?->metadata['revoked']['refresh_tokens'] ?? null)->toBe(1);
+    expect($audit?->metadata['revoked']['auth_codes'] ?? null)->toBe(1);
+});
+
+test('オーナー移譲の監査は譲り手と受け手で理由が分かれる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('移譲監査組織');
+    $successor = attachOrganizationMember($organization, OrganizationRole::Admin);
+
+    app(OrganizationMembershipService::class)->transferOwnership($organization, $owner, $successor);
+
+    $reasons = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::OrganizationAccessRevoked->value)
+        ->orderBy('id')
+        ->get()
+        ->map(fn (SecurityAuditEvent $event): mixed => $event->metadata['reason'] ?? null)
+        ->all();
+
+    expect($reasons)->toBe([
+        OrgAccessRevocationReason::OwnershipTransferredFrom->value,
+        OrgAccessRevocationReason::OwnershipTransferredTo->value,
+    ]);
+});
+
+// ---------------------------------------------------------------------------
+// E. 実際に使えなくなること (端から端まで)
+// ---------------------------------------------------------------------------
+
+test('除名の後はその人のトークンで外部 API の読み取りも書き込みも叩けない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('API組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $member = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $client = OAuthTestHelpers::createMcpClient(name: 'CLI クライアント');
+
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $member,
+        organization: $organization,
+        client: $client,
+    );
+
+    // 除名の前は読み取りも書き込みも通る (403/401 が除名以外の理由でないことの対照)
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->getJson('/api/v1/me')
+        ->assertOk();
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '除名前の作成'])
+        ->assertCreated();
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+    Auth::forgetGuards();
+
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->getJson('/api/v1/me')
+        ->assertUnauthorized();
+    Auth::forgetGuards();
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '失効後の作成'])
+        ->assertUnauthorized();
+
+    expect($project->items()->count())->toBe(1);
+});
+
+test('除名の後は更新トークンでの再発行が拒否される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('再発行組織');
+    $member = attachOrganizationMember($organization);
+    $client = OAuthTestHelpers::createMcpClient(name: '再発行クライアント');
+
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $member,
+        organization: $organization,
+        client: $client,
+    );
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+    Auth::forgetGuards();
+
+    $response = OAuthTestHelpers::exchangeTokenForm($this, [
+        'grant_type' => 'refresh_token',
+        'client_id' => (string) $client->getKey(),
+        'refresh_token' => $issued['refresh_token'],
+    ]);
+
+    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
+    expect($response->json('access_token'))->toBeNull();
+});
+
+test('除名の後はその人のトークンで MCP を叩けない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('MCP失効組織');
+    $member = attachOrganizationMember($organization);
+
+    config()->set('mcp.allowed_origins', ['https://claude.ai']);
+    config()->set('mcp.strict_transport', true);
+
+    $client = OAuthTestHelpers::createMcpClient(name: 'MCP クライアント');
+    $tokens = OAuthTestHelpers::exchangeForTokensUsing(
+        test: $this,
+        user: $member,
+        organization: $organization,
+        client: $client,
+        pkce: OAuthTestHelpers::generatePkcePair(),
+        redirectUri: 'https://test.example/callback',
+    );
+    Auth::forgetGuards();
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+    Auth::forgetGuards();
+
+    $this->withHeaders([
+        'Origin' => 'https://claude.ai',
+        'Authorization' => 'Bearer '.$tokens['access_token'],
+    ])->postJson('/api/v1/mcp', [
+        'jsonrpc' => '2.0',
+        'method' => 'tools/call',
+        'params' => ['name' => 'whoami', 'arguments' => []],
+        'id' => 1,
+    ])->assertUnauthorized();
+});
+
+test('接続セッション一覧に失効済みとして並ぶ', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('一覧確認組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    $this->actingAs($owner)
+        ->get("/organizations/{$organization->slug}/api-keys/sessions")
+        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
+            ->component('Organizations/ApiKeys/Sessions')
+            // ★件数を先に固定してから添字で見る (1 件しか無いので添字が一意に定まる。
+            //   件数を固定しないと、並び順が変わったときに別の行を見て緑になりうる)
+            ->has('sessions', 1)
+            ->where('sessions.0.id', $credentials['session']->id)
+            ->whereNot('sessions.0.revokedAt', null));
+});
+
+// ---------------------------------------------------------------------------
+// F. 失効させないものの境界 (誇張しないことの固定)
+// ---------------------------------------------------------------------------
+
+test('除名された発行者の API キーでも読み取りは通る (組織の資産として振る舞う)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('鍵読み取り組織');
+    $issuer = attachOrganizationMember($organization, OrganizationRole::Admin);
+    [, $plain] = issueApiKey($organization, $issuer, ['read', 'write']);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $issuer, $owner);
+
+    $this->withHeader('Authorization', "Bearer {$plain}")
+        ->getJson('/api/v1/me')
+        ->assertOk();
+});
+
+test('除名された発行者の API キーでの書き込みは認可で拒否される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('鍵書き込み組織');
+    $issuer = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $project = Project::factory()->forOrganization($organization)->create();
+    // ★write ability を必ず持たせる。持たせないと資格不足の 403 で緑になり、
+    //   認可の再評価を通っていない実装でも通ってしまう。
+    [, $plain] = issueApiKey($organization, $issuer, ['read', 'write']);
+
+    // ★除名の**前**に同じ要求が通ることを先に確かめる。これが無いと、403 が
+    //   除名以外の理由 (資格不足・冪等キー不足・テナント境界) で返っていても緑になる。
+    $this->withHeader('Authorization', "Bearer {$plain}")
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '除名前の作成'])
+        ->assertCreated();
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $issuer, $owner);
+
+    $this->withHeader('Authorization', "Bearer {$plain}")
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '失効後の作成'])
+        ->assertForbidden()
+        ->assertJsonPath('error.code', 'forbidden');
+
+    // 副作用が起きていないこと (1 件目だけが残る)
+    expect($project->items()->count())->toBe(1);
+});
+
+test('組織の API キーは失効の対象外 (窓口を呼んでも 1 行も変わらない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('鍵不変組織');
+    $member = attachOrganizationMember($organization);
+    [$apiKey] = issueApiKey($organization, $member, ['read']);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    /** @var ApiKey|null $fresh */
+    $fresh = ApiKey::query()->find($apiKey->getKey());
+    expect($fresh)->not->toBeNull();
+    expect($fresh?->revoked_at)->toBeNull();
+});
+
+test('プロジェクト単位の役割変更では失効しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('プロジェクト役割組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $member = attachOrganizationMember($organization);
+    attachProjectMember($project, $member, ProjectRole::Member);
+    $credentials = revocationCredentials($organization, $member);
+
+    // プロジェクト側のロール更新は store の再実行 (syncWithoutDetaching)
+    $this->actingAs($owner)
+        ->post("/projects/{$project->id}/members", [
+            'user_id' => $member->id,
+            'role' => ProjectRole::Admin->value,
+        ])
+        ->assertSessionHas('success');
+
+    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationAuditCount())->toBe(0);
+});

```

---

## 3. 検証結果 (対応後)

- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- 変更した 4 本のテスト: すべて green
  (McpAuthorizationChokePointTest 4 passed / RestWriteScopeRevalidationInvariantTest 10 passed /
   OrganizationAccessRevocationChokePointTest 12 passed /
   OrganizationAccessRevocationTest 25 passed)
- 全体は Round 1 の時点で `composer test` 5123 tests / 0 failed。対応後の全体実行はこの後に行う。

## 4. 反論の根拠 (postJson の第 3 引数)

`Illuminate\Foundation\Testing\Concerns\MakesHttpRequests::postJson()` の宣言は
`postJson($uri, array $data = [], array $headers = [], $options = 0)` であり、
**第 3 引数は headers、第 4 引数が options** である。したがって Round 1 の実装でも
`Idempotency-Key` は付いていた。ただし指摘の主旨 (403 が別の理由で返っても緑になりうる) は
正しいので、除名**前**に同じ要求が 201 で通る対照を足し、`error.code` と副作用の件数まで
見る形に強化した (この route は既存テストと同じく `Idempotency-Key` 無しでも通るため、
既存の書き方へ揃えた)。

---

上記を踏まえて再レビューし、最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書け。
