## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本グループ (F-2-02/F-2-03/F-2-01) の位置づけ: 組織はこの SOP・撮影データ・課金の管理単位。
組織のメンバー境界 (誰が入れるか / 誰を外せるか) が意図どおり閉じることは、機密の作業手順・映像資産を守る使命の前提である。

## 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (本グループは LLM 経路に触れない)
6. prompt 文字列のコード直書き (本グループ非該当)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. **必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)**
9. Artifact の使用禁止 (成果物はリポジトリ内ファイル)

セキュリティ不変条件 (関連): 子は親に属する=認可より前に 404 / 変更系は認可を通る /
層 2 (テナント境界 404) は層 3 (認可 403) より前 / PII は CipherSweet・whereBlind /
権限判定は laratrust_team_id 明示 / tenant キーを payload から受け取らない。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

Laravel + Svelte の改善実装のコードレビュアーとして、TODO T237 (招待受諾の宛先メール照合と
組織メンバーシップ認可の是正) の実装差分をレビューせよ。以下の観点で判定する:

1. **設計との一致性**: 添付した詳細設計書 (detailed-design.md) の施策 0〜8 と実装が一致しているか。
2. **正確性**: 宛先 email 照合のロジック、TOCTOU 封じ (ロック下再照合)、副作用境界。
3. **PHPStan 適合性**: `Assert::string` / narrow の妥当性、型安全。
4. **DTO/JsonResource パターン / 禁止事項**: `response()->json()` 直書きが無いか、禁止事項 8 (disabled 化) を守るか。
5. **テスト網羅性**: 各施策に対応する Feature/Svelte テストがあるか、既存テストの更新が仕様変更に正当か。
6. **セキュリティ**: email 照合の権威が Service (ロック下) にあるか、存在オラクル・cross-org 漏れが無いか。
7. **DESIGN.md 準拠**: color / radius / typography を token 経由で参照し hex 直書きを増やしていないか。
8. **Atomic Design 準拠**: resources/js/components の責務分離、SVG 直書き (Lucide 以外) を増やしていないか。

## 設計との差異 (実装者からの申告 — 重点的に検証せよ)

詳細設計 施策 1 の「波及変更」は、`joinOrganization` に新設する
`User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail()` を
`tests/Support/Security/DirectFetchInventory.php` へ登録することを「必須」としていた。
しかし実装時に `ModelDirectFetchInvariantTest` の走査器 (PrimaryKeyStaticQueryScanner) を確認したところ、
**型付きモデル引数 (`User $user`) は「証明済みモデル変数」として候補から除外される** ため、
この新規クエリは候補に上がらず、登録は不要 (むしろ登録すると「実在しない候補の裁定が残っている」
stale 検出テストが赤くなる) と判断し、**登録しなかった**。既存の
`OrganizationInvitation::query()->whereKey($invitation->id)` が同じ理由で未登録なのと同じ扱いである。
この判断が正しいか (特に「型付きモデル引数由来の主キー取得は認可漏れの逃げ道にならないか」) を検証せよ。

## 出力形式

ファイルごとに判定し、指摘を **[Critical] / [Warning] / [Suggestion]** に分類せよ。
最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示せよ。

---

## テスト結果サマリー

- `composer phpstan`: OK (No errors)
- `composer test` (全体・並列): 6353 passed / 0 failed / 2 skipped / 5 risky (assertions 30436)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: passed
- `pnpm test` (全体): 2356 passed
- `pnpm typecheck:packages` / `build:packages` / `test:packages`: passed
- `tests/Architecture/ModelDirectFetchInvariantTest` / `InvitationResolutionInventoryTest` /
  `MembershipWriteLockInventoryTest`: passed (30 tests)

## design system 参照 (resources/js の変更観点)

本 diff の resources/js 変更は **label 文字列と条件表示の切替のみ** で、
color / radius / typography token・hex (`#RRGGBB`) には一切触れていない
(`resources/css/tokens.css` の変更なし)。Accept.svelte / Admin/Users.svelte はいずれも
`pages/` 配下で、使用コンポーネント (Button / Card / PageHeader / Select / FormError) は
既存の atoms / molecules をそのまま利用し、新規 SVG 直書きは無い (アイコンは既存 Lucide の UserPlus)。

---

## 詳細設計書 (detailed-design.md)

（下部に実装差分を添付。詳細設計の全文は別ファイル
`devnotes/20260821-1522-bughunt-org-membership-authz/detailed-design.md` に存在するため、
必要に応じて読み込んでよい。以下に施策一覧を再掲する。）

- 施策 0: F-2-02 招待宛先判定の単一 domain predicate (OrganizationInvitation::isAddressedToEmail / isAddressedTo)
- 施策 1: F-2-02 招待受諾の宛先 email 照合 (acceptInvitation 早期照合 + joinOrganization ロック下再照合 = 権威)
- 施策 2: F-2-02 受諾確認画面の不一致分岐 (Controller show に recipientEmailMatches prop)
- 施策 3: F-2-02 Accept 画面 (recipientEmailMatches prop 分岐) + Svelte テスト
- 施策 4: F-2-02 解決経路目録の説明更新 (InvitationResolutionInventoryTest)
- 施策 5: F-2-02 Feature テスト (照合 + TOCTOU + 規則単一出典 + 回帰)
- 施策 6: F-2-03 除名/未割当 fail-closed リグレッションテスト (production 変更なし)
- 施策 7: F-2-01 option ラベル注記 (非 disabled。禁止事項 8 遵守)
- 施策 8: F-2-01 Svelte + Feature テスト

---

## 実装差分 (git diff HEAD)

```diff
diff --git a/app/Http/Controllers/Organizations/InvitationAcceptanceController.php b/app/Http/Controllers/Organizations/InvitationAcceptanceController.php
index 615ba399..50152466 100644
--- a/app/Http/Controllers/Organizations/InvitationAcceptanceController.php
+++ b/app/Http/Controllers/Organizations/InvitationAcceptanceController.php
@@ -20,7 +20,8 @@
 /**
  * 招待受諾。GET (確認画面) は guest 可 (未ログインは register へ誘導)。POST (受諾) は auth 必須。
  * verified は要求しない (招待された直後の未検証ユーザーも受諾できる)。
- * 招待 email とログインユーザーの email の一致はログイン後経路では要求しない仕様。
+ * 受諾には受諾者 email と招待の宛先 email の一致を要求する (権威は
+ * OrganizationMembershipService。GET は補助 UX として不一致を事前表示する)。
  */
 class InvitationAcceptanceController extends Controller
 {
@@ -70,9 +71,18 @@ public function show(Request $request, SeoManager $seo): Response|RedirectRespon
         $organization = $invitation->organization;
         Assert::isInstanceOf($organization, Organization::class);
 
+        // 宛先 email 照合 (補助 UX)。権威は Service (acceptInvitation + joinOrganization)。
+        // prop 名は「email が一致するか」だけを表す (受諾可否の全条件ではない)。
+        // 規則は OrganizationInvitation::isAddressedTo に集約 (Controller は独自比較式を持たない)。
+        // $request->user() は上の guest 分岐で早期 return 済みだが PHPStan L10 のため narrow する。
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+        $recipientEmailMatches = $invitation->isAddressedTo($user);
+
         return Inertia::render('Invitations/Accept', [
             'organizationName' => $organization->name,
             'token' => $token,
+            'recipientEmailMatches' => $recipientEmailMatches,
         ]);
     }
 
diff --git a/app/Models/OrganizationInvitation.php b/app/Models/OrganizationInvitation.php
index 0ca443c0..18ae5b9f 100644
--- a/app/Models/OrganizationInvitation.php
+++ b/app/Models/OrganizationInvitation.php
@@ -15,6 +15,7 @@
 use ParagonIE\CipherSweet\EncryptedRow;
 use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
 use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
+use Webmozart\Assert\Assert;
 
 /**
  * 組織招待。token は平文を保存せず sha256 ハッシュ (token_hash) のみ。
@@ -120,6 +121,34 @@ public function isRevoked(): bool
         return $this->revoked_at !== null;
     }
 
+    /**
+     * この招待が指定 email 宛かを判定する (**復号後インメモリ宛先比較の単一出典**)。
+     *
+     * email 同一性規則は scopeActivePendingForEmail (上の docblock) と同じ
+     * 「CipherSweet 復号後平文の大文字小文字を区別する厳密一致」である。正規化 (lowercase / trim) は
+     * 意図的に行わない (大小差は fail-secure に不一致へ倒す)。
+     *
+     * 保証範囲は「復号後インメモリ宛先比較の単一出典」であって「email 同一性規則すべての単一実装」では
+     * ない。受信者スコープ (scopeActivePendingForEmail) は blind index による DB 検索であり別レイヤ。
+     * 両者は同じ意図 (大小区別の厳密一致) で書かれているが、本 predicate を直接は使わない。
+     */
+    public function isAddressedToEmail(string $email): bool
+    {
+        $invited = $this->email; // CipherSweet 復号後。model に @property 注釈が無く PHPStan L10 は mixed と見る
+        Assert::string($invited);
+
+        return $invited === $email;
+    }
+
+    /** User 宛判定の薄いラッパ (呼び出し側の可読性。規則は isAddressedToEmail に集約)。 */
+    public function isAddressedTo(User $user): bool
+    {
+        $email = $user->email;
+        Assert::string($email);
+
+        return $this->isAddressedToEmail($email);
+    }
+
     /**
      * Active (受諾可能: 未受諾・未失効・期限内) な招待の query scope。
      *
diff --git a/app/Rules/MatchesInvitationEmail.php b/app/Rules/MatchesInvitationEmail.php
index d72b952f..fabfab09 100644
--- a/app/Rules/MatchesInvitationEmail.php
+++ b/app/Rules/MatchesInvitationEmail.php
@@ -43,7 +43,7 @@ public function validate(string $attribute, mixed $value, Closure $fail): void
             return;
         }
 
-        if ($invitation->email !== $value) {
+        if (! $invitation->isAddressedToEmail($value)) {
             $fail('招待されたメールアドレスと一致しません。招待メール記載のアドレスをご確認ください。');
         }
     }
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index 8394af71..5369260d 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -109,9 +109,13 @@ public function inviteMember(Organization $organization, User $invitedBy, string
     }
 
     /**
-     * 招待受諾。ログイン中ユーザーが受諾する (招待 email と user の email の一致は要求しない)。
+     * 招待受諾。ログイン中ユーザーが受諾する。
+     * **受諾者の email は招待の宛先 email と一致しなければならない**。register 経路
+     * (acceptInvitationIfValid) / アプリ内受諾 (acceptPendingInvitation) と同じ email 境界を
+     * token POST 経路にも適用する。email 同一性規則は acceptInvitationIfValid と同一
+     * (CipherSweet 復号後平文の厳密比較)。最終権威は joinOrganization のロック下再照合。
      *
-     * @throws ValidationException token 不正 / 取り消し済み / 失効 / 受諾済み / 既メンバー
+     * @throws ValidationException token 不正 / 取り消し済み / 失効 / 受諾済み / 宛先 email 不一致 / 既メンバー
      */
     public function acceptInvitation(string $plainToken, User $user): Organization
     {
@@ -130,6 +134,15 @@ public function acceptInvitation(string $plainToken, User $user): Organization
             throw ValidationException::withMessages(['token' => ['この招待は有効期限が切れています。']]);
         }
 
+        // 宛先 email の早期照合 (UX 用の明示メッセージ + 高速拒否)。生存判定 (取消/受諾済/失効) の後・
+        // 既メンバー判定の前に置き、どの分岐も join より前 = 状態を一切変えずに拒否する。
+        // 権威はロック下再照合 (joinOrganization) 側で、規則は OrganizationInvitation::isAddressedTo に集約。
+        if (! $invitation->isAddressedTo($user)) {
+            throw ValidationException::withMessages([
+                'token' => ['この招待は別のメールアドレス宛に送信されています。招待先のメールアドレスでログインし直してください。'],
+            ]);
+        }
+
         $organization = $invitation->organization;
         Assert::isInstanceOf($organization, Organization::class);
 
@@ -176,7 +189,8 @@ public function acceptInvitationIfValid(string $plainToken, User $user): ?Organi
         }
 
         // 招待 email と登録 email が一致しない場合は join しない
-        if ($invitation->email !== $user->email) {
+        // (email 同一性規則は OrganizationInvitation::isAddressedTo に集約)
+        if (! $invitation->isAddressedTo($user)) {
             return null;
         }
 
@@ -417,6 +431,19 @@ private function joinOrganization(OrganizationInvitation $invitation, Organizati
                 return false; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は受諾不能)
             }
 
+            // 1b. 宛先 email のロック下再照合 (最終権威。受諾中の email 変更 TOCTOU / stale user を封じる)。
+            //     ロック**読み**した User インスタンスで照合する ($user->fresh() は非ロック SELECT で
+            //     MVCC スナップショット版を返しうるため使わない)。users 行は lockForMembershipWrite が
+            //     canonical 順序で既にロック済みのため、同一行の lockForUpdate 再取得は no-op re-acquire
+            //     (新しいロック順序を作らない = デッドロックを導入しない。上の $locked 招待行 reload と同じ流儀)。
+            //     3 経路 (token / register / in-app) 共通コアに入るため全経路がロック下 email 境界を得る
+            //     (register / in-app は元から pre-lock で email 一致を保証済みのため挙動は不変)。
+            /** @var User $lockedUser */
+            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
+            if (! $locked->isAddressedTo($lockedUser)) {
+                return false; // 宛先不一致は受諾不能へ畳む (既存の false 契約と同じ neutral 扱い)
+            }
+
             // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role は変更しない。
             //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
             $joined = DB::table('organization_user')->insertOrIgnore([
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index c2da305f..f8178a18 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -55,12 +55,17 @@
         { value: "organization_member", label: "メンバー" },
     ];
 
-    /** ロール select の選択肢 (遷移コマンド 3 値。owner は enum 外 = 構造的に指定不可) */
-    const ROLE_OPTIONS: { value: ConsoleRole; label: string }[] = [
+    /**
+     * ロール select の選択肢 (遷移コマンド 3 値。owner は enum 外 = 構造的に指定不可)。
+     * hasDefaultProject が false のとき、編集者・撮影者は選べても割り当てはできない
+     * (サーバが validation で拒否)。禁止事項 8 に従い disabled にはせず、選択地点で
+     * 制約を可視化する注記サフィックスを付ける。管理者は無条件に選べるため付けない。
+     */
+    const roleOptions = $derived<{ value: ConsoleRole; label: string }[]>([
         { value: "admin", label: "管理者" },
-        { value: "editor", label: "編集者" },
-        { value: "shooter", label: "撮影者" },
-    ];
+        { value: "editor", label: hasDefaultProject ? "編集者" : "編集者（要プロジェクト）" },
+        { value: "shooter", label: hasDefaultProject ? "撮影者" : "撮影者（要プロジェクト）" },
+    ]);
 
     /** ロール select を出す行か (owner 行・自分の行はテキスト表示 = 現行 Settings と同じ流儀) */
     function canChangeRole(member: MemberRow): boolean {
@@ -353,7 +358,7 @@
                                                 {#if member.roleState === "unassigned"}
                                                     <option value="">未割当（選択してください）</option>
                                                 {/if}
-                                                {#each ROLE_OPTIONS as option (option.value)}
+                                                {#each roleOptions as option (option.value)}
                                                     <option value={option.value}>{option.label}</option>
                                                 {/each}
                                             </Select>
diff --git a/resources/js/pages/Invitations/Accept.svelte b/resources/js/pages/Invitations/Accept.svelte
index 0d2fa6e5..8a9c85d1 100644
--- a/resources/js/pages/Invitations/Accept.svelte
+++ b/resources/js/pages/Invitations/Accept.svelte
@@ -12,13 +12,21 @@
     interface Props {
         organizationName: string;
         token: string;
+        recipientEmailMatches: boolean;
     }
 
-    let { organizationName, token }: Props = $props();
+    let { organizationName, token, recipientEmailMatches }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
+    // 不一致時は組織名を含めない (非受信者への開示面を増やさない)
+    const description = $derived(
+        recipientEmailMatches
+            ? `「${organizationName}」に招待されています。受諾するとこの組織のメンバーになります。`
+            : "この招待は別のメールアドレス宛に送信されています。",
+    );
+
     const form = useForm({ token });
 
     function submit(event: SubmitEvent): void {
@@ -31,17 +39,24 @@
     <PageContainer>
         <PageHeader
             title="組織への招待"
-            description={`「${organizationName}」に招待されています。受諾するとこの組織のメンバーになります。`}
+            {description}
             icon={UserPlus}
             testId="accept-invitation-heading"
         />
         <PageContent>
             <Card padding="lg">
-                <form novalidate onsubmit={submit}>
-                    <Button type="submit" loading={form.processing} testId="accept-invitation-button">
-                        招待を受諾する
-                    </Button>
-                </form>
+                {#if recipientEmailMatches}
+                    <form novalidate onsubmit={submit}>
+                        <Button type="submit" loading={form.processing} testId="accept-invitation-button">
+                            招待を受諾する
+                        </Button>
+                    </form>
+                {:else}
+                    <p class="text-body" data-testid="accept-invitation-mismatch">
+                        招待メールを受け取ったアドレスでログインし直してください。画面右上のメニューから
+                        ログアウトし、招待メールのリンクをもう一度開いてください。
+                    </p>
+                {/if}
             </Card>
         </PageContent>
     </PageContainer>
diff --git a/tests/Architecture/InvitationResolutionInventoryTest.php b/tests/Architecture/InvitationResolutionInventoryTest.php
index e24b3af1..572ede08 100644
--- a/tests/Architecture/InvitationResolutionInventoryTest.php
+++ b/tests/Architecture/InvitationResolutionInventoryTest.php
@@ -68,7 +68,8 @@ function invitationResolutionInventory(): array
             .' DB を引かない。一覧・件数・受諾解決がすべてここを通る。'],
         'Services/Organization/OrganizationMembershipService.php#acceptInvitation' => [$token,
             'POST token 受諾。token_hash 照合で解決し、失効/期限/受諾済みを個別メッセージに'
-            .'出し分ける (token 保持者向けの既存契約)。'],
+            .'出し分ける。宛先 email の早期照合に加え、参加成立と同じ排他区間 (joinOrganization の'
+            .'ロック下) で再照合し、不一致は join しない (register 経路と同一の email 境界)。'],
         'Services/Organization/OrganizationMembershipService.php#acceptInvitationIfValid' => [$token,
             'register 経路の受諾。findActiveByPlainToken で解決し、招待 email と登録 email の'
             .'一致を要求する (MatchesInvitationEmail と対で二重防御)。'],
diff --git a/tests/Feature/Organization/ConsoleRoleTransitionTest.php b/tests/Feature/Organization/ConsoleRoleTransitionTest.php
index 06d3449d..e2d25f58 100644
--- a/tests/Feature/Organization/ConsoleRoleTransitionTest.php
+++ b/tests/Feature/Organization/ConsoleRoleTransitionTest.php
@@ -101,6 +101,31 @@ function createOrgWithDefaultProject(): array
     expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
 });
 
+test('T10: プロジェクト 0 件組織での editor/shooter 割当は back redirect + role error で拒否し状態を変えない', function (AdminConsoleRole $role): void {
+    // enforcement の権威はサーバ (applyConsoleRole)。Inertia フォームの検証エラーは 302 redirect back
+    // + session errors で返る (422 JSON ではない)。
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+
+    $response = $this->actingAs($owner)
+        ->from("/organizations/{$organization->slug}/settings")
+        ->patch("/organizations/{$organization->slug}/members/{$member->id}", [
+            'role' => $role->value,
+        ]);
+
+    $response->assertRedirect("/organizations/{$organization->slug}/settings");
+    $response->assertSessionHasErrors([
+        'role' => '編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。',
+    ]);
+    $response->assertSessionMissing('success');
+    // org role / project pivot を変更しない (DB assertion)
+    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
+    $this->assertDatabaseMissing('project_members', ['user_id' => $member->id]);
+})->with([
+    'editor' => [AdminConsoleRole::Editor],
+    'shooter' => [AdminConsoleRole::Shooter],
+]);
+
 test('endpoint 経由: editor コマンドで org ロールと pivot が 1 操作で揃う', function (): void {
     [$organization, $owner, $project] = createOrgWithDefaultProject();
     $member = attachOrganizationMember($organization);
diff --git a/tests/Feature/Organization/InvitationTest.php b/tests/Feature/Organization/InvitationTest.php
index 97aa81d9..a3e37b07 100644
--- a/tests/Feature/Organization/InvitationTest.php
+++ b/tests/Feature/Organization/InvitationTest.php
@@ -12,7 +12,9 @@
 use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Notifications\AnonymousNotifiable;
 use Illuminate\Support\Facades\Notification;
+use Illuminate\Validation\ValidationException;
 use Inertia\Testing\AssertableInertia;
+use Webmozart\Assert\Assert;
 
 /*
  * 組織招待 (送信 / 受諾 / 拒否系)。
@@ -75,8 +77,11 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     [$organization, $owner] = createOrganizationWithOwner();
     $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);
 
-    // 受諾するユーザーは別組織を現在組織に持つ (POST 受諾が現在組織を切り替えないことを固定するため)
-    [$otherOrg, $invitee] = createOrganizationWithOwner('受諾者の既存組織');
+    // 受諾するユーザーは別組織を現在組織に持つ (POST 受諾が現在組織を切り替えないことを固定するため)。
+    // email 照合の追加により受諾者 email を招待宛先に揃える。
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    [$otherOrg] = createOrganizationWithOwner('受諾者の既存組織');
+    $otherOrg->users()->attach($invitee);
     $invitee->forceFill(['current_organization_id' => $otherOrg->id])->save();
     $before = $invitee->current_organization_id;
 
@@ -97,14 +102,15 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     [$organization, $owner] = createOrganizationWithOwner('受諾テスト組織');
     $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);
 
-    $invitee = User::factory()->create();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
     $response = $this->actingAs($invitee)->get('/invitations/accept?token='.$token);
 
     $response->assertOk();
     $response->assertInertia(
         fn ($page) => $page->component('Invitations/Accept')
             ->where('organizationName', '受諾テスト組織')
-            ->where('token', $token),
+            ->where('token', $token)
+            ->where('recipientEmailMatches', true),
     );
 });
 
@@ -347,7 +353,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 test('有効な招待リンクの受諾確認画面は route 既定タイトルのまま', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $token = inviteAndCaptureToken($organization, $owner, 'valid-title@example.com', OrganizationRole::Admin);
-    $invitee = User::factory()->create();
+    $invitee = User::factory()->create(['email' => 'valid-title@example.com']);
 
     $this->actingAs($invitee)
         ->get('/invitations/accept?token='.$token)
@@ -515,7 +521,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     $project = Project::factory()->forOrganization($organization)->create();
     $token = inviteAndCaptureToken($organization, $owner, 'member@example.com', OrganizationRole::Member);
 
-    $invitee = User::factory()->create();
+    $invitee = User::factory()->create(['email' => 'member@example.com']);
     $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token])
         ->assertRedirect('/dashboard');
 
@@ -547,10 +553,12 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     [$organization, $owner] = createOrganizationWithOwner();
     $token = inviteAndCaptureToken($organization, $owner, 'idempotent@example.com', OrganizationRole::Admin);
 
-    $first = User::factory()->create();
+    // 1 人目は招待宛先 email に揃えて受諾成立させる
+    $first = User::factory()->create(['email' => 'idempotent@example.com']);
     $this->actingAs($first)->post('/invitations/accept', ['token' => $token]);
 
-    // 2 人目は事前検証 (isAccepted) で拒否される。受諾状態・membership が変化しないこと
+    // 2 人目は事前検証 (isAccepted) で拒否される (email 照合より前で弾かれる)。
+    // 受諾状態・membership が変化しないこと
     $second = User::factory()->create();
     $response = $this->actingAs($second)->post('/invitations/accept', ['token' => $token]);
 
@@ -564,8 +572,10 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     $project = Project::factory()->forOrganization($organization)->create();
     $token = inviteAndCaptureToken($organization, $owner, 'already@example.com', OrganizationRole::Member);
 
-    // 招待送信後に別経路で org へ参加済み (organization_user 行あり + Admin ロール)
-    $invitee = User::factory()->create();
+    // 招待送信後に別経路で org へ参加済み (organization_user 行あり + Admin ロール)。
+    // joinOrganization は共通コアで宛先 email をロック下再照合するため、受諾者 email を招待宛先に揃える
+    // (email 一致下で「既 attach は unique 違反にならず role を変えない」冪等契約を検証する)。
+    $invitee = User::factory()->create(['email' => 'already@example.com']);
     $organization->users()->attach($invitee);
     $invitee->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
 
@@ -588,3 +598,148 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     // 招待は受諾済みになる (再利用不能)
     expect($invitation->refresh()->isAccepted())->toBeTrue();
 });
+
+/*
+ * 宛先 email 照合 (F-2-02)。register 経路 / アプリ内受諾と同じ email 境界を token POST 経路へ適用する。
+ * 権威は Service (acceptInvitation の早期照合 + joinOrganization のロック下再照合)。
+ */
+
+test('T3: 別 email のログイン者の受諾確認画面は recipientEmailMatches=false (組織名を出さない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('照合確認組織');
+    $token = inviteAndCaptureToken($organization, $owner, 'invited@example.com', OrganizationRole::Admin);
+
+    $intruder = User::factory()->create(['email' => 'intruder@example.com']);
+    $response = $this->actingAs($intruder)->get('/invitations/accept?token='.$token);
+
+    $response->assertOk();
+    $response->assertInertia(
+        fn ($page) => $page->component('Invitations/Accept')
+            ->where('recipientEmailMatches', false),
+    );
+});
+
+test('T4: 別 email の直 POST 受諾は拒否され副作用が一切残らない (権威境界)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('照合 POST 組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $token = inviteAndCaptureToken($organization, $owner, 'invited@example.com', OrganizationRole::Admin);
+
+    $intruder = User::factory()->create(['email' => 'intruder@example.com']);
+    $before = $intruder->current_organization_id;
+
+    $response = $this->actingAs($intruder)->post('/invitations/accept', ['token' => $token]);
+
+    $response->assertRedirect('/dashboard');
+    $response->assertSessionHas('error');
+
+    // pivot 不在を DB assertion で直接確認する (organizationRole の null だけに依存しない)
+    $this->assertDatabaseMissing('organization_user', [
+        'organization_id' => $organization->id,
+        'user_id' => $intruder->id,
+    ]);
+    // 対象組織 laratrust_team_id の role_user に行が増えない (キャッシュ/relation リセット後に確認)
+    expect($intruder->fresh()?->organizationRole($organization))->toBeNull();
+    $this->assertDatabaseMissing('role_user', [
+        'user_id' => $intruder->id,
+        'team_id' => $organization->laratrust_team_id,
+    ]);
+    // 招待は未受諾のまま / project pivot / current_organization_id も不変
+    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeFalse();
+    expect($project->memberRole($intruder))->toBeNull();
+    expect($intruder->fresh()?->current_organization_id)->toBe($before);
+});
+
+test('T4b: 早期照合を stale 値で通過し、ロック読みの最新値で最終拒否する (TOCTOU)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('TOCTOU 組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);
+    $staleUser = User::factory()->create(['email' => 'invitee@example.com']);
+
+    // 別インスタンスを通常保存経路 (CipherSweet 経由) で更新。$staleUser は古い email のまま。
+    // 一括 update は暗号化・モデルイベントを迂回するため使わない。
+    $persisted = $staleUser->fresh();
+    Assert::isInstanceOf($persisted, User::class);
+    $persisted->email = 'changed@example.com';
+    $persisted->save();
+
+    // 早期照合は stale 値で通過し、最新の保存値では不一致になることを明示 assert (失敗理由の分離)
+    $invitation = OrganizationInvitation::query()->sole();
+    expect($invitation->isAddressedTo($staleUser))->toBeTrue();  // 古い email = 招待宛先
+    expect($invitation->isAddressedTo($persisted))->toBeFalse(); // 最新の保存値 = 不一致
+
+    // Service を直接呼ぶ (HTTP 経由だと認証ユーザーが DB から再解決され stale モデルを渡せない)
+    $thrown = null;
+    try {
+        app(OrganizationMembershipService::class)->acceptInvitation($token, $staleUser);
+    } catch (ValidationException $exception) {
+        $thrown = $exception;
+    }
+    expect($thrown)->not->toBeNull();
+    expect($thrown?->errors())->toHaveKey('token');
+
+    // 「早期照合が働いただけ」ではなく「最終照合がロック読みの最新値を使った」ことを DB 状態不変で分離証明する
+    $this->assertDatabaseMissing('organization_user', [
+        'organization_id' => $organization->id,
+        'user_id' => $staleUser->id,
+    ]);
+    $this->assertDatabaseMissing('role_user', [
+        'user_id' => $staleUser->id,
+        'team_id' => $organization->laratrust_team_id,
+    ]);
+    expect($invitation->refresh()->isAccepted())->toBeFalse();
+    expect($project->memberRole($staleUser))->toBeNull();
+    expect($staleUser->fresh()?->current_organization_id)->toBeNull();
+});
+
+test('T5: email 同一性規則は register 経路と token POST 経路で同一 (厳密比較・大小区別)', function (
+    string $relation,
+    bool $shouldJoin,
+): void {
+    $service = app(OrganizationMembershipService::class);
+
+    // 招待宛先 email から受諾者 email を導出する (email は全体で一意なので経路ごとに別の宛先を使う)。
+    //  - exact:    完全一致
+    //  - mismatch: 完全不一致
+    //  - case:     大文字小文字のみ相違 (先頭 1 文字を大文字化。case-sensitive fail-secure 規則の固定)
+    $userEmailFor = fn (string $invited): string => match ($relation) {
+        'exact' => $invited,
+        'mismatch' => 'different-'.$invited,
+        'case' => ucfirst($invited),
+    };
+
+    // register 経路 (acceptInvitationIfValid): 独立 fixture
+    [$orgRegister, $ownerRegister] = createOrganizationWithOwner('register 経路組織');
+    $invitedRegister = 'register-invited@example.com';
+    $tokenRegister = inviteAndCaptureToken($orgRegister, $ownerRegister, $invitedRegister, OrganizationRole::Member);
+    $userRegister = User::factory()->create(['email' => $userEmailFor($invitedRegister)]);
+    $resultRegister = $service->acceptInvitationIfValid($tokenRegister, $userRegister);
+
+    // token POST 経路 (acceptInvitation): 独立 fixture・別宛先 (同一招待を使い回さない)
+    [$orgToken, $ownerToken] = createOrganizationWithOwner('token 経路組織');
+    $invitedToken = 'token-invited@example.com';
+    $tokenToken = inviteAndCaptureToken($orgToken, $ownerToken, $invitedToken, OrganizationRole::Member);
+    $userToken = User::factory()->create(['email' => $userEmailFor($invitedToken)]);
+    $thrown = null;
+    $resultToken = null;
+    try {
+        $resultToken = $service->acceptInvitation($tokenToken, $userToken);
+    } catch (ValidationException $exception) {
+        $thrown = $exception;
+    }
+
+    if ($shouldJoin) {
+        expect($resultRegister)->not->toBeNull();
+        expect($orgRegister->users()->whereKey($userRegister->id)->exists())->toBeTrue();
+        expect($thrown)->toBeNull();
+        expect($resultToken)->not->toBeNull();
+        expect($orgToken->users()->whereKey($userToken->id)->exists())->toBeTrue();
+    } else {
+        expect($resultRegister)->toBeNull();
+        expect($orgRegister->users()->whereKey($userRegister->id)->exists())->toBeFalse();
+        expect($thrown)->not->toBeNull();
+        expect($orgToken->users()->whereKey($userToken->id)->exists())->toBeFalse();
+    }
+})->with([
+    '完全一致' => ['exact', true],
+    '完全不一致' => ['mismatch', false],
+    '大文字小文字のみ相違' => ['case', false],
+]);
diff --git a/tests/Feature/Organization/MemberRemovalAccessTest.php b/tests/Feature/Organization/MemberRemovalAccessTest.php
new file mode 100644
index 00000000..f843a0cd
--- /dev/null
+++ b/tests/Feature/Organization/MemberRemovalAccessTest.php
@@ -0,0 +1,108 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Enums\ProjectRole;
+use App\Models\Project;
+use App\Models\User;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * 除名 / 未割当の fail-closed リグレッション (production コードは変更しない。既存の正しい挙動を不変条件化)。
+ *
+ * この family の層分け (本リポジトリの不変条件):
+ *  - 層 2 (テナント境界) = 404: current-org が解決できない / binding が通らない
+ *  - 層 3 (認可)        = 403: binding は通るが membership / role が無い
+ *
+ * | 状態                                   | dashboard | projects | billing | manage/users |
+ * | 自然除名 (current=null)                | 200       | 404      | 404     | 404          |
+ * | stale (current=除名済み org)           | 200       | 403      | 403     | 403          |
+ * | 未割当 (attach 済み・current=org・role 無し) | 403   | 403      | 403     | 403          |
+ *
+ * 除名の証明は projects/billing/manage の 404/403 で行う (dashboard は current 未解決時に
+ * no-org 設定画面 200 を出すため、除名済み org のデータでないことの確認に留める)。
+ */
+
+test('T7: 自然除名で membership/role/pivot/current が掃除され、被除名者は org 業務 route に到達できない (層2=404)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('除名テスト組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    attachProjectMember($project, $member, ProjectRole::Member);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($owner)
+        ->delete("/organizations/{$organization->slug}/members/{$member->id}")
+        ->assertSessionHas('success');
+
+    // (1) organization_user の pivot 行が不在 (organizationRole の null だけに依存しない)
+    $this->assertDatabaseMissing('organization_user', [
+        'organization_id' => $organization->id,
+        'user_id' => $member->id,
+    ]);
+    // (3) 対象組織 laratrust_team_id の role_user 行が不在 (Laratrust キャッシュ reset 後に確認)
+    expect($member->fresh()?->organizationRole($organization))->toBeNull();
+    $this->assertDatabaseMissing('role_user', [
+        'user_id' => $member->id,
+        'team_id' => $organization->laratrust_team_id,
+    ]);
+    // (4) project_members pivot から消滅
+    $this->assertDatabaseMissing('project_members', [
+        'project_id' => $project->id,
+        'user_id' => $member->id,
+    ]);
+    // (5) current_organization_id が null 化 (当該 org を current にしていたため)
+    expect($member->fresh()?->current_organization_id)->toBeNull();
+
+    // (2) /manage/users (owner 閲覧) の members prop に被除名者が含まれない
+    $this->actingAs($owner)
+        ->get('/manage/users')
+        ->assertInertia(function (AssertableInertia $page) use ($member): void {
+            $page->component('Admin/Users');
+            /** @var list<array{id: int}> $members */
+            $members = $page->toArray()['props']['members'];
+            expect(array_column($members, 'id'))->not->toContain($member->id);
+        });
+
+    // (6) 被除名者で org 業務 route が 404 (層 2。current=null で org context が解決できない)
+    $removed = $member->fresh();
+    $this->actingAs($removed)->get('/projects')->assertNotFound();
+    $this->actingAs($removed)->get('/billing')->assertNotFound();
+    $this->actingAs($removed)->get('/manage/users')->assertNotFound();
+});
+
+test('T7b: 除名後に current-org を除名済み org へ戻しても membership 境界で拒否される (層3=403)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('stale current 組織');
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+
+    $this->actingAs($owner)
+        ->delete("/organizations/{$organization->slug}/members/{$member->id}")
+        ->assertSessionHas('success');
+
+    // current-org を除名済み組織へ明示的に戻す (binding は通るが membership/role は不在)
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    $stale = $member->fresh();
+
+    // 拒否が current-org 不在ではなく membership 境界で成立することを分離して固定する (層 3 = 403)
+    $this->actingAs($stale)->get('/projects')->assertForbidden();
+    $this->actingAs($stale)->get('/billing')->assertForbidden();
+    $this->actingAs($stale)->get('/manage/users')->assertForbidden();
+});
+
+test('T8: 未割当 (attach 済み・current=org・laratrust role 無し) は主要 route が fail-closed (層3=403)', function (): void {
+    // 検証した主要 route (dashboard/projects/billing/manage-users)。全 route 保証ではない。
+    [$organization] = createOrganizationWithOwner('未割当 fail-closed 組織');
+
+    // organization_user へ attach 済みだが Laratrust role を付与しない異常行 (並行受諾レースの自然な帰結)。
+    // current_organization_id は対象組織に設定する (拒否が current-org 不在ではなく role 不在で成立する)。
+    $unassigned = User::factory()->create();
+    $organization->users()->attach($unassigned);
+    $unassigned->forceFill(['current_organization_id' => $organization->id])->save();
+
+    expect($unassigned->fresh()?->organizationRole($organization))->toBeNull();
+
+    $this->actingAs($unassigned)->get('/dashboard')->assertForbidden();
+    $this->actingAs($unassigned)->get('/projects')->assertForbidden();
+    $this->actingAs($unassigned)->get('/billing')->assertForbidden();
+    $this->actingAs($unassigned)->get('/manage/users')->assertForbidden();
+});
diff --git a/tests/Feature/Organizations/OrganizationAccessRevocationTest.php b/tests/Feature/Organizations/OrganizationAccessRevocationTest.php
index d957f104..6395b908 100644
--- a/tests/Feature/Organizations/OrganizationAccessRevocationTest.php
+++ b/tests/Feature/Organizations/OrganizationAccessRevocationTest.php
@@ -256,7 +256,8 @@ function revocationAuditCount(): int
 
 test('招待受諾 (組織に入れる操作) では失効しない', function (): void {
     [$organization, $owner] = createOrganizationWithOwner('招待組織');
-    $invitee = User::factory()->create();
+    // 受諾には宛先 email との一致が要る (F-2-02) ため受諾者 email を招待宛先に揃える
+    $invitee = User::factory()->create(['email' => 'invitee@example.test']);
 
     $invitation = app(OrganizationMembershipService::class)
         ->inviteMember($organization, $owner, 'invitee@example.test', OrganizationRole::Member);
diff --git a/tests/js/pages/AdminUsers.test.ts b/tests/js/pages/AdminUsers.test.ts
index 4c16555a..577ff3d8 100644
--- a/tests/js/pages/AdminUsers.test.ts
+++ b/tests/js/pages/AdminUsers.test.ts
@@ -535,3 +535,41 @@ describe("Admin/Users ロール変更フィードバック", () => {
         await waitFor(() => expect(screen.getByTestId("member-role-2")).toHaveFocus());
     });
 });
+
+describe("Admin/Users ロール制約の可視化 (F-2-01)", () => {
+    it("T9a: hasDefaultProject=false では編集者/撮影者に注記が付き、管理者は素のまま", () => {
+        render(Users, { props: { ...baseProps, hasDefaultProject: false } });
+
+        // id=2 (editor) 行の select 選択肢を検査する
+        const select = screen.getByTestId("member-role-2");
+        const options = Array.from(select.querySelectorAll("option")).map(
+            (option) => option.textContent,
+        );
+        expect(options).toEqual(["管理者", "編集者（要プロジェクト）", "撮影者（要プロジェクト）"]);
+    });
+
+    it("T9b: hasDefaultProject=false でも制約付き option を選択して change を開始でき、disabled にしない (禁止事項 8)", async () => {
+        pageState.props = { appName: "AI-CUE", errors: {} };
+        render(Users, { props: { ...baseProps, hasDefaultProject: false } });
+
+        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
+        // ロール select も削除ボタンも disabled 属性を持たない (押下時にサーバがエラー表示する方針)
+        expect(select).not.toBeDisabled();
+        expect(screen.getByTestId("remove-member-2")).not.toBeDisabled();
+
+        // 制約付き option (shooter) を選択して change を開始できる (option が非 disabled の実挙動)
+        await fireEvent.change(select, { target: { value: "shooter" } });
+        expect(routerPatchMock).toHaveBeenCalledTimes(1);
+        expect(routerPatchMock.mock.calls[0][1]).toEqual({ role: "shooter" });
+    });
+
+    it("T9d: hasDefaultProject=true では注記なしの素のラベル (対の正例)", () => {
+        render(Users, { props: { ...baseProps, hasDefaultProject: true } });
+
+        const select = screen.getByTestId("member-role-2");
+        const options = Array.from(select.querySelectorAll("option")).map(
+            (option) => option.textContent,
+        );
+        expect(options).toEqual(["管理者", "編集者", "撮影者"]);
+    });
+});
diff --git a/tests/js/pages/InvitationsAccept.test.ts b/tests/js/pages/InvitationsAccept.test.ts
new file mode 100644
index 00000000..37eae167
--- /dev/null
+++ b/tests/js/pages/InvitationsAccept.test.ts
@@ -0,0 +1,49 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+
+/*
+ * 招待受諾画面の宛先 email 照合分岐 (F-2-02)。
+ *
+ * recipientEmailMatches prop で表示を切り替える:
+ *  - true:  受諾ボタン (accept-invitation-button) を出し、description に組織名を含める
+ *  - false: 受諾ボタン/フォームを出さず、案内文 (accept-invitation-mismatch) を出し、
+ *           description は「別のメールアドレス宛」で組織名を含めない (非受信者への開示面を増やさない)
+ */
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    page: { props: { appName: "AI-CUE" } },
+    useForm: () => ({ token: "", processing: false, post: vi.fn() }),
+}));
+
+const { default: InvitationsAccept } = await import("@/pages/Invitations/Accept.svelte");
+
+const ORG_NAME = "秘匿対象組織";
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("Invitations/Accept の宛先 email 照合", () => {
+    it("一致時: 受諾ボタンを表示し、不一致案内は出さず、description に組織名を含む", () => {
+        render(InvitationsAccept, {
+            props: { organizationName: ORG_NAME, token: "tok", recipientEmailMatches: true },
+        });
+
+        expect(screen.getByTestId("accept-invitation-button")).toBeInTheDocument();
+        expect(screen.queryByTestId("accept-invitation-mismatch")).toBeNull();
+        expect(screen.getByText(new RegExp(ORG_NAME))).toBeInTheDocument();
+    });
+
+    it("不一致時: 受諾ボタン/フォームを出さず案内文を表示し、description に組織名を含まない", () => {
+        render(InvitationsAccept, {
+            props: { organizationName: ORG_NAME, token: "tok", recipientEmailMatches: false },
+        });
+
+        expect(screen.queryByTestId("accept-invitation-button")).toBeNull();
+        expect(screen.getByTestId("accept-invitation-mismatch")).toBeInTheDocument();
+        expect(screen.getByText("この招待は別のメールアドレス宛に送信されています。")).toBeInTheDocument();
+        // 非受信者への開示面を増やさない: 組織名を画面に出さない
+        expect(screen.queryByText(new RegExp(ORG_NAME))).toBeNull();
+    });
+});

```
