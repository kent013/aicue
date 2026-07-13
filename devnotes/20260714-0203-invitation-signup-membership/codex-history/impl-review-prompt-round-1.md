【アプリの使命 (North Star)】
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
本改善は、招待で組織へ参加したメンバーが登録直後から所属組織で作業を開始できるようにするオンボーディング整合修正。

【禁止事項 (AGENTS.md 正本)】
1. テストなしの実装完了報告 (不変条件は Architecture/Feature テスト登録まで含めて「実装済み」)
2. PHPStan エラーの widen (型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST 応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI

【セキュリティ不変条件】
tenant キー不信 (ownership/actor/tenant キーを payload から受け取らない)、子は親に属する (nested route 不整合は認可より前に 404)、cross-org 不可、権限判定は laratrust_team_id 明示、PII は CipherSweet、current_organization_id は mass-assignment 保護キー。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考えてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

Laravel + Svelte アプリの改善実装のコードレビュアー。以下の観点でレビューせよ:
- 設計との一致性 / 正確性 / PHPStan (level 10) 適合性
- DTO/JsonResource パターン準拠 (response()->json() 直書き禁止)
- テスト網羅性 (テストファースト、Factory 使用、RefreshDatabase グローバル、個別 DatabaseTransactions 禁止)
- セキュリティ (tenant キー不信、cross-org 分離、保護キーの forceFill)
- DESIGN.md 準拠 / Atomic Design 準拠 (今回の diff は resources/js/css を含まないため該当なし)

出力形式: ファイルごとに判定し、指摘は [Critical] / [Warning] / [Suggestion] に分類。末尾に全体判定 (APPROVED / CHANGES_REQUESTED) を必ず明記せよ。

---

## 詳細設計書 (抜粋)

本タスク T030 は bug-hunt 回帰 run F-01 (Critical / data_integrity) の修正。招待 token 経由の登録が users.current_organization_id を確定しないため、登録直後は共有プロップ currentOrganization (HandleInertiaRequests) が null を生読みし、ヘッダーが「組織を作成/選択」表示になる中間不整合が生じる。dashboard だけが CurrentOrganizationResolver の自己修復で招待先組織へ復帰するため「残高 10・ヘッダー未所属」という別ページ観測になる。

**施策 1**: register 専用メソッド `OrganizationMembershipService::acceptInvitationIfValid()` の join 成功直後 (return 直前) で、参加した招待組織を新規ユーザーの現在組織として確定する。
- 概念設計では CreateNewUser 側に置く案だったが、詳細レビューで register 専用メソッド内へ移した (join + 現在組織確定を 1 ユースケースに閉じる。個人組織パスが provision() 内で現在組織を据えるのと対称)。
- 無条件確定にする (=== null ガードを付けない)。理由: 本メソッドは register 専用で対象 user は登録直後 (現在組織未設定) のため「招待成立 ⇒ 現在組織 = 招待先」を不変条件として強制できる。provision() が === null ガードを持つのはログイン中の追加組織作成からも呼ばれ既存 current を保護する必要があるためで前提が異なる。
- 共通コア joinOrganization() (POST 受諾経路 acceptInvitation と共有) は変更しない。current_organization_id は mass-assignment 保護キーのため forceFill でサーバ導出値 ($organization->id) のみ代入。

**施策 2**: Feature テストで分岐 A/B の現在組織確定を排他かつ網羅で固定。
- 2-1 (InvitationTest): 招待成立で current_organization_id = 招待先組織 (現行で赤 → 施策1で緑)。
- 2-2 (InvitationTest): verification.notice (未検証到達可・自己修復非経由の Inertia ページ) の共有プロップ currentOrganization が招待先を指す (現行で赤 → 施策1で緑。dashboard を避けるのは自己修復で偽陰性になるため)。
- 2-4 (RegistrationTest): 通常登録で current = 個人組織 (現行でも緑。排他の証明)。
- 2-5 (InvitationTest): 無効 token fallback でも current = 個人組織 (現行でも緑。波及しないことのガード)。
- 2-6 (InvitationTest 既存受諾テスト強化): POST 受諾は current を切り替えない (register 専用前提の保護)。

テストファースト実施済み: 施策1適用前に 2-1/2-2 が赤 (current=null / prop=null) を確認 → 施策1適用後に緑。全体 1610 passed。

## 実装差分 (git diff)

```diff
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
@@ acceptInvitationIfValid() join 成功直後・return 直前
         $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));

+        // [register 経路限定] 参加した招待組織をこの新規ユーザーの「現在組織」として確定する。
+        // - 本メソッドは register 経路専用 (呼び出し元は CreateNewUser のみ。POST 受諾は acceptInvitation)。
+        // - 個人組織パスが provision() 内で現在組織を据えるのと対称に、join + 現在組織確定を 1 ユースケースに閉じる。
+        // - この user は登録直後で現在組織が未確定のため招待先組織を無条件に現在組織にする。
+        //   current_organization_id は mass-assignment 保護キーのためサーバ導出値を forceFill で明示代入 (tenant キー不信)。
+        $user->forceFill(['current_organization_id' => $organization->id])->save();
+
         return $organization;
     }
```

(施策2のテスト diff は上の設計要約に対応。RegistrationTest に current=個人組織 assertion 追加、InvitationTest に 2-1/2-2/2-5/2-6 の assertion 追加、AssertableInertia を import。)

参考: acceptInvitationIfValid() の前段は、token active 検証 → 招待 email と登録 email の一致検証 → $organization = $invitation->organization (Assert::isInstanceOf で narrowing) → 既メンバーなら null return → joinOrganization。joinOrganization は内部で DB::transaction (呼び出し元 tx 内では savepoint) を張り organization_user への insertOrIgnore + addRole を行う。呼び出し元 CreateNewUser::create() は登録全体を DB::transaction で包む。

## テスト結果

- composer test (--parallel): 1612 tests, 1610 passed, 2 skipped, 0 failed
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / test (525 passed) / build: 全 green

## レビュー依頼事項

1. 施策1の配置 (acceptInvitationIfValid 内・join 直後) と無条件確定の妥当性。register 専用前提が崩れるリスク。
2. トランザクション境界: joinOrganization の内部 tx 戻り後・登録 tx 内で forceFill する原子性に問題がないか。
3. 保護キー forceFill の使い方 (tenant キー不信) が守れているか。
4. テスト網羅性: 分岐 A/B の排他・網羅、テストファーストの赤/緑マップの妥当性。2-2 の観測点 (verification.notice) 選定の妥当性。
5. 見落としているエッジケース (既メンバー fallback で null return する経路では current を触らない点など)。
