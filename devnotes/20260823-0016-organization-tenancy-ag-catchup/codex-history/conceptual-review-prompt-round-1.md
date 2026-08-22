## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。


あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の前提 — 重要】
本設計は「家系の機能台帳 (lctl)」という外部の正典に対する追従設計である。
- 正典の裁定 (AG-036〜AG-047) は既にオーナーが確定させたものであり、**設計側が採否を選べる余地は無い**。
  「その裁定自体が妥当か」を論点にするのではなく、「確定した裁定へ最小のスコープで追従できているか」を見よ。
- 「aicue に割り当てられた未追従項目だけをスコープにする」ことが本設計の要件である。
  未確定項目・他リポジトリ待ち・他 feature 所有の項目をスコープに入れていたら [Critical] で指摘せよ。
  逆に、割り当てられた確定項目を落としていても [Critical] で指摘せよ。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: organization-tenancy-ag-catchup

家系の機能台帳 (lctl) feature `organization-tenancy` への追従。

| 項目 | 値 |
|---|---|
| 正典 feature revision | `46-c1830b632b4d` |
| 台帳 revision | `81f0e624363b0c707a424c0695253eb6d1536451` |
| aicue セル | `status: pending` / `version: t0` / `assessment: divergence_candidate` |
| 観測点 (台帳側) | `aicue@e7f954ed` 実読 (差分巡回 2026-08-18 夕) |
| 本設計の実測点 | 本リポジトリ HEAD (`2dc4e2ec`) |

---

## 背景・課題

### 正典が求めていること

`organization-tenancy` の正典は「1 つのサービスを複数の組織が使うための土台」であり、
基点はテンプレート t0。2026-08-05 のオーナー裁定 (AG-036〜AG-047) で **4 系統**が確定した。

| 系統 | 裁定 | 内容 |
|---|---|---|
| (1) 前段の帰属検証 | AG-036 | URL に現れた資源が現在の組織に属することを**入力検証より前**の段で確かめ、違えば 403 ではなく **404**。403 は資源の存在を認めてしまうため |
| (2) 現在組織の決め方 | AG-037 | 「いまどの組織として操作しているか」は **URL だけ**で決める。保持列と切替エンドポイントは撤去する。**2 方式の併存は認めない** |
| (3) 個人組織 | AG-038 | 個人組織を種別として区別するのをやめ、種別フラグを撤去する |
| (4) 識別名 (slug) | AG-039 / AG-039b / AG-039c / AG-046 / AG-047 | URL に人が読める識別名を出す。予約語 (理由 3 分類必須)・文字種と正規化・大小無視の一意性・改名 (30 日 5 回まで / 旧識別名は解放) ・**機械が使う経路は不変の内部識別子で組織を指す** |
| (追加) 権限判定 | AG-040 | 権限ライブラリのチーム厳格チェックを `true` に揃える (条件なしの確定) |

正典の現行実装は laravel-claude-template (`T104` / `@454943e`) と aigenba の 2 本で、
どちらも `implemented`。残る 4 本 (aicue / spirux / motivation / metamovics のうち
metamovics はテンプレート全量取り込みで implemented) が追従対象である。

### aicue セルの pending 理由 (台帳の記述をそのまま写す)

> 現在の組織の保持列・切替の route・種別フラグがそろって実在し AG-037 / AG-038 は未充足。
> 識別名は URL に出しているが予約語の設定も改名の経路も無く AG-039 系 / AG-046 / AG-047 も未充足。
> AG-040 だけが設定値の真で満たされている。
> 実読で新たに分かった点として、`app/Services/Organization/CurrentOrganizationResolver.php` は
> 「保持列が指す組織を所属の再確認で自己修復する」実装であり、**AG-037 が撤去を求めている当の方式を
> 強化する向きに育っている (追従の距離が縮まらずに広がる)**。

> 【領域深掘り 2026-08-17】現在の組織の保持列の読み書きは利用者モデル・サービス提供者・画面へ渡す
> 前段処理・通知の controller・組織の controller (書き込み)・切替の controller の 6 か所に分布し、
> 切替の経路も種別フラグも健在である。…
> **組織の経路の鍵が面によって割れている (切替だけ主キー、設定・更新・招待は識別名)。
> AG-037 の撤去でこの不揃いも同時に消える。**

### 本設計での実測 (台帳の観測を HEAD で裏取りした結果)

| 事実 | 実測 |
|---|---|
| `users.current_organization_id` | `database/migrations/2026_06_11_074000_create_organizations_tables.php` で定義。production コード 10 ファイルが読み書き (`User` / `AppServiceProvider` / `HandleInertiaRequests` / `NotificationCenterService` / `NotificationController` / `OrganizationProvisioningService` / `OrganizationMembershipService` / `CurrentOrganizationResolver` / `OrganizationController` / `OrganizationSwitchController`) |
| 切替 route | `POST organizations/{organization}/switch` (`organizations.switch`) が実在。**この 1 本だけ主キー binding**で、他の組織 route は `{organization:slug}` |
| 自己修復 | `CurrentOrganizationResolver::resolve()` / `heal()` が条件付き UPDATE で保持列を書き戻す |
| current 依存 controller | `ResolvesCurrentOrganization` trait の利用者が **23 クラス** (Projects 系 11 / Capture 系 4 / Billing 系 2 / Onboarding 系 3 / Dashboard / Admin / middleware 2) |
| 種別フラグ | `organizations.is_personal` 実在。`OrganizationProvisioningService::provisionPersonalOrganization()` の冪等判定がこのフラグ |
| 識別名 | `Str::slug($name) ?: 'org'` + `'-'.Str::lower(Str::random(6))` の**生成のみ**。文字種検証・長さ検証・予約語・正規化・大小無視の一意性・改名経路のすべてが 0 件 |
| 機械経路 (AG-047) | `routes/api.php` / `routes/ai.php` / `routes/console.php` / Filament に組織の URL 引数は 0 件 (`OrganizationRouteParamWebOnlyInvariantTest` が `{organization}` param を web+auth 限定に固定済み)。**実態は満たしているが、識別名で組織を指さないことを固定する検査が無い** |
| AG-040 | `config/laratrust.php` の `teams.strict_check` が真 (充足) |

### 問題の本質 (なぜ直すのか)

AI-CUE の使命は「現場に既にある SOP を起点に、AI が設計した動画シナリオをスマホ (PWA) で
ナビ撮影させ、専門知識ゼロの作業者でも標準化されたマニュアル動画を作れるようにする」ことである。

現行の「サーバ側に保存された最後に見た組織」方式は、この使命の**現場側**で直接害になる:

1. **共用端末で誤組織の手順書を撮る**。撮影 PWA (`start_url=/app`) は URL に組織を持たない。
   同じ端末を複数の作業者・複数の取引先組織で使い回すと、開いた瞬間に何が出るかは
   「サーバに残っている前回の値」で決まる。作業者は URL を読まないので気付けない。
2. **URL を共有できない**。「この手順書を見て」と送った URL が、受け手の保持列次第で
   別組織の画面 (または 404) になる。現場の申し送りが URL で完結しない。
3. **自己修復が事故を静かに拡大する**。`CurrentOrganizationResolver` は保持列が壊れていると
   「所属組織 id 昇順の先頭」へ勝手に付け替える。利用者から見ると**組織が黙って切り替わる**。
4. 台帳の言うとおり、この方向の実装が育つほど正典への追従距離は**広がる**。

---

## 改善アイデア

aicue に割り当てられた未追従項目 **AG-037 / AG-038 / AG-039 系 / AG-046 / AG-047** だけを
スコープにし、正典と同じ形へ揃える。5 系統。

### A. 識別名を値として確定させる (AG-039b / AG-039c)

- 識別名を**値オブジェクト 1 本**に集約する。文字種 (小文字英数字とハイフン)・長さ・
  先頭末尾ハイフン禁止・連続ハイフン禁止・大文字は小文字へ正規化、を 1 か所で持つ。
- 一意性は**大文字小文字を区別しない**。保存層の小文字強制と DB の小文字式 unique index の
  二重で担保する (アプリ層だけだと競合で抜ける)。
- 生成 (組織名からの導出) と利用者入力の両方が同じ値オブジェクトを通る。

### B. 予約語を持ち、ルート表との整合を機械検査する (AG-039)

- 予約語一覧を設定ファイルに置き、**各語に理由 3 分類のいずれかの記載を必須**にする
  (`route_conflict` = ルート衝突 / `authority_impersonation` = 権威の詐称 /
  `syntax_conflict` = 構文衝突)。
- 「識別名の位置に現れうる固定セグメントが予約語に登録されていること」を
  route 表から機械検査する Architecture 検査を新設する。**予約語を書き忘れた新 route は赤になる**。

### C. 改名経路を持ち、回数制限と旧識別名の扱いを確定する (AG-046)

- 改名 route を 1 本足す。**30 日あたり 5 回**まで。
- 最終権威は「組織行を行ロックした後の再判定」。事前判定は画面表示のための早期拒否にすぎない。
- **旧識別名は予約せず解放する** (履歴表に一意制約を張らない)。
- 施行時は全組織が残 5 回から始まる (導入前の改名は遡及計上できない)。テンプレートと
  aigenba も同じ制約を申し送りにしており、**緩い側の失敗として許容**する。

### D. 種別フラグを撤去する (AG-038)

- `organizations.is_personal` を落とす。
- 登録時の初期組織生成の冪等判定を「種別フラグの有無」から**「所属組織が 0 件かどうか」**へ置換する。
- 判定はトランザクション内で利用者行を**取り直して行ロック**し、ロック後のクエリで所属を数える
  (呼び出し側の読み込み済みリレーションに依存しない)。
- 呼び出しサイトを Architecture 検査で固定する。

### E. 現在組織を URL 単一方式にする (AG-037) — 本設計の主柱

- 業務面の route を**すべて `/organizations/{organization:slug}/` 配下へ移設**する。
  対象は dashboard / projects 系 / capture (撮影 PWA) 系 / billing 系 / onboarding 系 /
  notifications / manage (メンバー管理) の **約 57 本**。
- 組織の解決は既存の `MembershipScopedOrganizationBinder` (routing 層 = 入力検証より前) 1 本に
  寄せる。**AG-036 の形はすでに aicue が持っており、変わるのは「どこから org を取るか」だけ**。
- `users.current_organization_id` 列・`organizations.switch` route・`CurrentOrganizationResolver`・
  `ResolvesCurrentOrganization` の current 解決部を**同じ変更ですべて消す**
  (AGENTS.md 思考原則 3: 後方互換の並走を残さない)。
- 画面へ渡す組織文脈は URL の binding から導出する共有プロパティ 1 本にし、
  **組織 route 以外では `null`** になる。
- 組織文脈を持たない入口 (料金ページ / メール確認後 / ソーシャル登録後 / PWA の `start_url`) からの
  導線は、**状態を保存しない分岐 route 1 本**で受ける。所属が 1 組織ならその組織へ転送、
  複数なら組織を選ぶ画面、0 件なら組織作成へ。
  これは正典 (laravel-claude-template T104) が同じ問題に対して採った形をそのまま採る。
  **状態を保存しないので「保持列と切替エンドポイントの禁止」と矛盾しない。**

### F. 機械経路の識別子を固定する (AG-047)

- api / ai / console / Filament / MCP が組織を**識別名で指していない**ことを機械検査で固定する。
  aicue の実態はすでに満たしているので、**検査を足して固定するだけ**である
  (改名で解放される識別名を機械経路が握ると、別組織へ黙って作用する)。

---

## 期待効果

### 使命 (North Star) への貢献

- **現場端末の誤組織事故が構造的に消える**。撮影 PWA が開く画面は URL が決める。
  共用端末でも「サーバに残っていた前回の組織」が出ることはない。
  「思考ゼロ・編集ゼロ」を掲げる以上、作業者に「いま何組織を見ているか」を確認させないこと自体が要件である。
- **URL が申し送りになる**。「この手順書を撮って」を URL の共有で完結でき、
  受け手の状態に依存しない。現場の口伝を減らす方向に効く。
- **組織が黙って切り替わらない**。自己修復の撤去で、利用者が指示していない組織移動が消える。

### 台帳・保守面

- lctl の aicue セルが `pending` / `divergence_candidate` から前進する
  (implemented への格上げ判断はキュレーターの責務であり、本設計はその根拠を作るところまで)。
- 組織 route の鍵が識別名 1 本に揃う (切替だけ主キーという不揃いが route ごと消える)。
- 現在組織の分岐 (「未設定なら 404」「dangling なら自己修復」) が消え、
  テナント境界の判定点が routing 層 1 か所になる。

---

## 実装方針 (概要)

変更は 1 バッチ。**AG-037 は 2 方式の併存を認めない裁定**なので、
「保持列を残したまま URL 方式も足す」中間状態は作らない。

コミット順序だけは固定する (先に識別名を堅くしてから、識別名を全 URL の前置きにする):

```
A → B → C  (識別名: 値オブジェクト → 予約語 + route 整合検査 → 改名)
     ↓
D          (種別フラグ撤去。A〜C と独立だが provisioning を 1 回で書き換えるため先に済ませる)
     ↓
E          (route 移設 + 保持列/切替/自己修復の撤去。ここで初めて識別名が全 URL の前置きになる)
     ↓
F          (機械経路の識別子の固定検査)
     ↓
乖離台帳の更新 (D4 の書き換え / 新規登録 / 採用時債務の処理)
```

### 主な変更コンポーネント

| 層 | 変更 |
|---|---|
| 値 | `App\Support\Organization\OrganizationSlug` (新設) / `OrganizationSlugReservedWords` (新設) |
| 設定 | `config/organization-slug-reserved.php` (新設。理由 3 分類必須) |
| モデル | `Organization` (`is_personal` cast 撤去) / `User` (`currentOrganization` relation 撤去) / `OrganizationSlugRename` (新設) |
| Service | `OrganizationProvisioningService` (冪等判定の置換 + 識別名生成の値オブジェクト化) / `OrganizationSlugRenameLimiter` (新設) / `OrganizationMembershipService` (保持列書き込みの撤去) / `CurrentOrganizationResolver` (**削除**) |
| Routing | `routes/web.php` (業務面を組織配下へ移設) / `MembershipScopedOrganizationBinder` (据え置き) / `EnsureProjectBelongsToCurrentOrganization` → 組織 binding 由来へ改称・改修 |
| Controller | `ResolvesCurrentOrganization` を使う 23 クラスの org 取得元を route binding へ / `OrganizationSwitchController` (**削除**) / 組織選択の分岐 route (新設) / 改名 controller (新設) |
| Inertia / 前段 | `HandleInertiaRequests` の `currentOrganization` を binding 由来へ |
| フロント | `shared-props.ts` (型) / `AppLayout.svelte` (組織切替 UI の撤去・href 生成) / `SidebarUserMenu.svelte` / `Capture/Account.svelte` / 組織選択画面 (新設) / 改名 UI |
| migration | `drop is_personal` / `drop current_organization_id` / `organization_slug_renames` 作成 / 識別名の小文字式 unique index |
| 検査 | 予約語 × route 表整合 (新設) / 初期組織生成の呼び出しサイト固定 (新設) / 機械経路の組織識別子 (新設) / 改名制限 (新設) / 既存 `ProjectRouteCurrentOrgGuardTest` `TenantBoundaryOrderingTest` `NestedRouteIdorDefenseTest` の更新 |
| 乖離台帳 | `docs/template-divergence.md` D4 の書き換え + 新規登録 / `LedgerPins` の件数更新 / 採用時債務の処理 |

---

## 制約・前提

### 満たし続けるセキュリティ不変条件 (AGENTS.md §セキュリティ不変条件)

1. **tenant キー不信** — 組織・所有者・実行者のキーを payload から受け取らない。
   識別名は URL segment であって payload ではない。改名の入力は「新しい識別名」だけで、
   **どの組織かは URL の binding が決める**。
2. **子は親に属する** — nested route の不整合は認可より前に 404。
   移設後は `{organization}` → `{project}` → `{manual}` → `{cut}` → `{take}` の鎖になり、
   `NestedRouteIdorDefenseTest` の inventory 登録が必須。
3. **cross-org 不可** — 組織を跨ぐ read/write をしない。組織の解決は membership スコープの
   binder 経由のみ。`ModelDirectFetchInvariantTest` / `DirectFetchInventory` の分類が要る。
4. 窓口経由の prompt 防御 — 本設計は LLM 経路に触れない。
5. **権限判定は常に `laratrust_team_id` を明示** (strict_check=true。AG-040。既に充足)。
6. PII は CipherSweet — 本設計は PII 列に触れない。
7. 課金の冪等性 — 本設計は billing route の **URL だけ**を動かす。冪等機構には触れない。
8. SSRF 検査 — 触れない。
9. **変更系 route は認可を通る** — 移設で route が増減するので `ControllerAuthorizationGateTest` の
   deny-by-default に全数が乗る。**層 2 (テナント境界 = 404) は層 3 (認可 = 403) より前**。
10. **層 2 は binding の直後・FormRequest より前で閉じる** — 実行順の正本は
    `bootstrap/app.php` の priority list。`TenantBoundaryOrderingTest` /
    `ProjectRouteCurrentOrgGuardTest` の更新が必須。
11. キャッシュに入れるのは素のデータだけ — `AppServiceProvider` の
    保持列由来のキャッシュキーが消えるだけで、機構には触れない。

### 正典側の不変条件 (追従先)

- 現在の組織は **URL のみ**で決まる。保持列と切替エンドポイントは存在してはならない。
- 個人組織を種別として区別しない。初期組織生成の冪等判定は所属 0 件で行い、行ロックする。
- 識別名は小文字英数字とハイフン。先頭末尾と連続のハイフンは不可。大文字は小文字へ正規化。
  一意性は大小無視。
- 予約語は理由 3 分類の記載必須。識別名の位置に現れる固定セグメントは予約語であること。
- 改名は 30 日 5 回まで。旧識別名は予約せず解放。
- 機械が使う経路は不変の内部識別子で組織を指す。

### 既存アーキテクチャとの整合

- `MembershipScopedOrganizationBinder` は既に routing 層にあり、非メンバー・不在 id を等しく 404 にする。
  **AG-036 の形は既に持っている**。本設計はその適用範囲を業務面へ広げるだけである。
- `Organization::getRouteKeyName()` は現状 `id`。移設後は組織 route がすべて `{organization:slug}` に
  揃うので、`OrganizationRouteParamWebOnlyInvariantTest` の「routeKeyName は id」の pin を
  どう扱うかを詳細設計で決める (切替 route が消えると field 無指定 binding の利用者が居なくなる)。
- 撮影 PWA の `start_url` は `/app`。SW は `/capture-sw.js` (scope=`/`) で登録しており、
  **移設で SW の scope は壊れない**。`/app` は組織選択の分岐 route として残す。
- `docs/template-divergence.md` の **D4** (web `{project}` の org スコープ guard を middleware 層に置く)
  は current org 前提で書かれているため、同じ変更で書き換える。

### 破壊的変更としての扱い

- 旧 URL は route ごと消えて 404 になる。**並走も転送も置かない** (AGENTS.md 思考原則 3)。
- テンプレートは「本番利用者が居ないため代償なし」と書いているが、**aicue は自分で判断する**。
  v1 スコープであり、ブックマーク・共有 URL の移行コストを許容できるかは詳細設計の
  リスク欄で明示する。

---

## スコープ外

**裁定が確定していない項目・他リポジトリの責務・他 feature の所有物は入れない。**

| 項目 | 理由 |
|---|---|
| AG-036 (前段の帰属検証) | **aicue 形が標準形として採用された側**であり既に充足。本設計は org の取得元を変えるだけで、層の位置は動かさない |
| AG-040 (チーム厳格チェック) | `config/laratrust.php` の設定値が真で充足済み |
| AG-040 の配布物 (`TeamScopedRoleCheckInvariantTest` + 走査器) | 台帳が **「還流候補」** と書く未確定項目。aigenba にのみ実在し、他 5 本は設定値が真なだけ。裁定が「検査を配れ」と言っていない以上、先回りして作らない (思考原則 2) |
| 正典の版の呼び名 (t0 → t1 等) | 台帳が「未確定 (議題)」と明記。**キュレーターの責務**であり aicue が決めるものではない |
| lctl への書き込み (`append_event` / セル更新) | 設計エージェントの責務外。登録は後段 |
| 組織そのものを削除する route | 正典 boundary が「`OrganizationPolicy::delete` は 6 リポジトリ全数が持ち、route を持つのは aigenba と spirux の 2 本」と書く。**aicue に無いことは逸脱ではない** |
| 中間層 (CustomTeam) の階層変更 | aicue は既に Organization → CustomTeam → Project の 3 階層で正典と同形 |
| 招待の役割列 | 台帳が「auth-invitation-flow 側の事実なのでそちらへ寄せ、ここでは触れない」と明記 |
| 接続の取り消し層 / MCP 組織書き込みスコープ | 所有は `mcp-org-write-scope` feature。AG-125 の管轄 |
| 認証後の着地画面の組織アクセス状態 (4 値) | 所有は auth 側 (AG-113)。`auth-post-auth-redirect` / `auth-invitation-in-app-discovery` の管轄 |
| aigenba の `TemplatePolicy` 是正 | aigenba の責務 |
| シート課金同期・org-tree versioning・Filament 管理パネルの権限境界 | 正典 boundary が「含まない」と明記 |
| 撮影 PWA の manifest を組織別に動的生成する | `start_url` は分岐 route `/app` のままで足りる。動的 manifest は「あったら便利」(思考原則 2) |
| 旧 URL からの転送・並走 | 思考原則 3 (後方互換の並走を残さない) と正典の実装判断に従う |

---

## 成功条件 (どうなれば追従できたと判断するか)

1. `current_organization_id` が `app/` `routes/` `config/` `database/migrations/` の
   **撤去 migration 以外に 1 件も無い**。切替 route が 0 件。
2. `is_personal` が **撤去 migration 以外に 1 件も無い**。初期組織生成の冪等判定が所属 0 件 + 行ロック。
3. 識別名の値オブジェクト・理由分類必須の予約語設定・改名台帳・30 日 5 回の制限・改名 route が実在し、
   予約語と route 表の整合を機械検査が固定している。
4. 大小無視の一意性が DB index で担保されている。
5. 機械経路 (api / ai / console / Filament / MCP) が組織を識別名で指していないことを機械検査が固定している。
6. `composer test` / `composer phpstan` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` が全 green。
7. 乖離台帳 (`docs/template-divergence.md` / `LedgerPins` / 採用時債務一覧) が整合している。
