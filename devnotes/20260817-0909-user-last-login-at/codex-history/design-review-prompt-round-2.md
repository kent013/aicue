# Round 2: Round 1 の指摘への対応

Round 1 の指摘 (Critical 2 / Warning 7 / Suggestion 6) をすべて捌きました。
下に (1) 対応マトリクス、(2) 修正後の詳細設計書の全文 を置きます。

**Round 1 で反論した点が 2 つあります。判定してください:**

1. **Filament の admin guard の扱い** — Codex は「(i) 構造で保証してテストする」か
   「(ii) `metadata.guard = 'web'` で絞る」の二択を提示しました。**(i) を採り、(ii) を採りません**。
   理由は (a) JSON 列 `metadata` への述語は施策 D で張る索引の外に出て、性能のために索引を
   張り替える設計と矛盾する、(b)「どの guard を数えるか」の定義が記録側 (RecordSecurityEvent) と
   読み取り側 (LastLoginLookup) の 2 か所に分かれて食い違う、の 2 点です。
   構造側の保証は実読で確認済みです — `App\Models\AdminUser` は
   `Illuminate\Foundation\Auth\User` を直接継承する別クラスで `App\Models\User` の派生ではなく、
   `RecordSecurityEvent::asUser()` が null を返すため `user_id` が付きません。
   前提が崩れたら赤くなるよう G-1 テスト 10 を追加しました。**この判断は妥当ですか。**

2. **索引の明示名での drop を採らない** — 「レビュー耐性を上げるなら明示名で drop」の提案に対し、
   落とす側だけ名前を直書きすると「同じ文字列を 2 か所に持つ」二重管理になるため、
   **コメントで既定名を示すにとどめ、drop は配列指定のまま**にしました。
   張った側の migration も配列指定であることは実読で確認済みです。**この判断は妥当ですか。**

その他の指摘はすべて設計に反映しました (対応内容は下記マトリクスを参照)。
**残っている Critical / Warning があれば指摘してください。無ければ全体判定を APPROVED にしてください。**

---

## 対応マトリクス

# 対応マトリクス: design-review Round 1

全体判定 **CHANGES_REQUESTED**。施策 D と G が REQUEST_CHANGES、A/B/C/E/F は APPROVE。
Critical 2 件 / Warning 7 件 / Suggestion 6 件。全件の判断を記録する。

---

## [Critical] 施策 D: migration の停止許容と lock の扱いが設計に書かれていない

- 判断: **対応する**
- 根拠: 妥当な指摘。「`CONCURRENTLY` を使わない」という判断は書いたが、
  **その判断が何を許容することなのか**（= 索引構築の間、認証系 INSERT が待つ）を
  migration 自身のコメントと実行条件として書いていなかった。
  「使わない理由」だけあって「使わないと何が起きるか」が無い設計は、
  実行する人が判断できない。
- 対応内容: 施策 D を全面的に書き直した。
  (1) migration の doc comment に「短時間の認証系 INSERT の待ちを許容する」ことを明記、
  (2) 実行条件（低トラフィック時間帯に実行する / 行数が小さいうちに済ませる）を
  「運用条件」節として独立させ、
  (3) 無停止が要件になった場合に何が追加で要るか（`$withinTransaction = false` +
  `CREATE INDEX CONCURRENTLY` + invalid index の復旧手順）を将来の見直し条件として明記、
  (4) rollback (`down()`) も同じ lock を取ることをリスクに追加した。

## [Critical] 施策 G テスト 7: remember me の代替案が弱い

- 判断: **対応する（代替案を撤去して、実 HTTP 経路に一本化する）**
- 根拠: 完全に正しい指摘である。「handler を直接呼ぶ」に落とすと、
  **守りたい不変条件（recaller 復元で `Login` が発火し、監査行が増える）そのものが検査されない**。
  それは検査対象を「listener が guard 名を見ないこと」へすり替えているだけで、
  AGENTS.md の「保証範囲を誇張しない」に反する。
  実現可能性も確認した — `SessionGuard::getRecallerName()` が公開されており、
  テストの `withCookie()` は既定で暗号化されて渡るため、
  ログイン応答後に `remember_token` を読んで recaller cookie を組み立て、
  セッションを捨てた 2 回目のリクエストで踏める。**書ける**ので落とす理由が無い。
- 対応内容: テスト 7 を「実 HTTP で recaller 経路を踏む」形に確定し、
  組み立て手順（recaller の値の形 `id|remember_token|password`）を設計に書いた。
  弱い代替案の記述は**削除**した。

## [Warning] 施策 B: `pluck('id')->all()` の PHPStan level 10 での型

- 判断: **対応する**
- 根拠: 指摘のとおり。`pluck()` の戻り型は `Collection<int, mixed>` に落ちやすく、
  `list<int>` の narrowing は `@var` の自己申告になる。禁止事項 2（型を緩めて黙らせる）の
  境界に近い書き方を最初から避けるべきである。
- 対応内容: 施策 B の変更後コードを Codex の提案どおり
  `array_values(array_map(static fn (User $member): int => $member->id, …))` に差し替えた。

## [Warning] 施策 D: 「新索引が旧索引を完全に包含する」は言い過ぎ

- 判断: **対応する**
- 根拠: 正しい。B-tree の左端一致で代替できるのは
  「先頭列から連続した前置き集合に対する述語」であり、
  索引の「全用途」（例: 索引だけで完結する読み取りの列構成、統計の取られ方）を
  保証する言い方ではない。本リポジトリは「保証範囲を誇張しない」を規約として持つ。
- 対応内容: 「完全に含む」→「`user_id, event_type` の前方一致クエリでは代替できる」に
  表現を下げ、リスク節の「等価かそれ以上」という書き方も
  「前方一致の用途では等価。それ以外は保証しない」に直した。

## [Warning] 施策 D: 既定命名への依存をコメントで固定せよ

- 判断: **対応する（コメントで固定する。明示名での drop は採らない）**
- 根拠: 既存 migration が配列指定で張っているため既定命名であることは実読で確定している
  （`security_audit_events_user_id_event_type_index`）。
  ただし読む人がそれを再確認しなくて済むようコメントに書く価値はある。
  **明示名を直書きする形は採らない** — 張った側が既定命名なので、
  落とす側だけ名前を直書きすると「2 か所に同じ文字列を持つ」形になり、
  本リポジトリが繰り返し禁じている二重管理になる。
- 対応内容: migration のコメントに既定名を明記し、
  「張った側も配列指定なので命名は一致する」と根拠を書いた。

## [Warning] 施策 D: rollback も lock を取る

- 判断: **対応する**
- 根拠: そのとおり。
- 対応内容: リスク節に追加した。

## [Warning] 施策 E: factory の `CarbonImmutable` が「モデルが immutable を返す」と読めてしまう

- 判断: **対応する**
- 根拠: 正しい。`SecurityAuditEvent::casts()` は `occurred_at => 'datetime'`（mutable Carbon）であり、
  施策 A が immutable を得ているのは**別名列への `withCasts` の効果**である。
  factory の説明が誤読を生むと、後から「モデルも immutable だ」と思って
  `CarbonImmutable` 前提のコードが書かれる。
- 対応内容: `occurredAt(CarbonImmutable $at)` の doc comment に
  「引数の型は呼び出し側の都合であり、モデルから読み戻した `occurred_at` は
  cast `datetime` により mutable Carbon である」と明記した。

## [Warning] 施策 G: 2FA 途中離脱を数えないことの検査が無い

- 判断: **対応する**
- 根拠: 設計 §3 で「2FA 待ちは数えない」と主張しているのに、それを固定する検査が無かった。
  主張とテストの対応が欠けている（禁止事項 1 の趣旨）。
- 対応内容: G-1 にテスト 9 を追加した
  （パスワードだけ通過して 2FA challenge 未完了の状態では login 行が増えないこと）。

## [Warning] 施策 G: Filament の admin guard が混ざらないことの検査が無い

- 判断: **対応する（設計は「guard で絞らない」を維持し、前提をテストで固定する）**
- 根拠: Codex は「admin guard を数えない」ことを
  (i) 構造で保証してテストする、か (ii) `metadata.guard = 'web'` で絞る、の二択で求めた。
  **(i) を採る**。理由は 2 つある。
  - (ii) は JSON 列への述語になり、施策 D で張る索引が効かなくなる
    （絞り込みが索引の外へ出る）。性能のために索引を張り替える設計と矛盾する。
  - (ii) は「どの guard を数えるか」の定義を `RecordSecurityEvent`（記録側）と
    `LastLoginLookup`（読み取り側）の**2 か所**に持つことになる。食い違いの温床である。
  構造側の保証は実読で確認済み: `App\Models\AdminUser` は
  `Illuminate\Foundation\Auth\User` を直接継承する**別クラス**であり
  `App\Models\User` の派生ではない。したがって `RecordSecurityEvent::asUser()` が
  `null` を返し、admin guard の login 行には `user_id` が付かない。
  この前提が崩れる（AdminUser が User を継承する等）と静かに混ざるので、テストで固定する。
- 対応内容: G-1 にテスト 10 を追加した
  （Filament の admin ログイン後、`user_id` の付いた login 行が増えないこと）。
  施策 A の doc comment にもこの前提を明記した。

## [Warning] 施策 G: 招待受諾フローの扱いが曖昧

- 判断: **対応する（設計の記述を具体化し、テストを 1 件足す）**
- 根拠: 指摘は妥当だが、事実関係は既に実読済みだった。
  `InvitationAcceptanceController::show` は未ログインなら token を session に退避して
  `register` へ誘導し、`store`（受諾）は **auth 必須**である。
  つまり受諾の瞬間に `Login` は発火せず、その前段の**登録の自動ログイン**が数えられる。
  結果として「招待された人の最終ログイン = 参加した時刻」になり、意図どおりである。
  ただしこの鎖は設計の文章にしか無く、検査が無かった。
- 対応内容: §3 の経路表の「招待受諾」行を、この鎖が読めるように書き直し、
  G-1 にテスト 11（招待経由で登録・参加した利用者は、参加時刻が最終ログインとして出る）を追加した。

## [Suggestion] 施策 A: `Assert::numeric` より `Assert::integerish`

- 判断: **対応する**
- 根拠: 正しい。`numeric` は `1.5` のような float も通す。ID の narrowing としては
  `integerish` が意図に一致する。`vendor/webmozart/assert/src/Assert.php` L107 に実在を確認した。
- 対応内容: 施策 A のコードを `Assert::integerish($userId)` に差し替えた。

## [Suggestion] 施策 A: `select('user_id')` + `selectRaw('max(...)')` に分ける

- 判断: **対応する**
- 根拠: SQL 断片を最小にする方が読みやすく、列名の混入経路も狭い。
- 対応内容: 差し替えた。

## [Suggestion] 施策 E: `HasFactory` の PHPDoc に factory の import が要る

- 判断: **対応する**
- 根拠: `@use HasFactory<SecurityAuditEventFactory>` の型名解決に `use` が要る
  （`User` モデルが `Database\Factories\UserFactory` を import しているのと同じ）。
- 対応内容: 施策 E の変更後コードに `use Database\Factories\SecurityAuditEventFactory;` を足した。

## [Suggestion] 施策 G-2: 件数差で揺れない fixture にせよ

- 判断: **対応する**
- 根拠: 招待件数や pivot ロールの件数が両ケースで違うと、
  「最終ログインが N+1 になった」以外の理由でクエリ数が動き、検査の意味が薄れる。
- 対応内容: G-2 の条件に「招待は両ケースとも 0 件 / Default Project は両ケースとも不在 /
  変えるのはメンバー数だけ」を明記した。

## [Suggestion] 施策 C: `data-testid` が DOM 契約を増やす

- 判断: **見送る（変更しない）**
- 根拠: 認識しておく程度で十分、という指摘自体に同意する。
  既存の同ファイルが `member-role-{id}` / `remove-member-{id}` / `unassigned-{id}` と
  同じ流儀で testId を持っており、ここだけ持たない方が不揃いになる。

## [Suggestion] 施策 B / F への肯定的コメント

- 判断: **変更不要**


---

## 修正後の詳細設計書 (全文)

# 詳細設計: user-last-login-at (最終ログイン日時の記録と表示)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応する Factory の作成も施策に含める**（本設計では施策 E）
- **DTO + JsonResource** パターン（AGENTS.md 参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex conceptual-review Round 1 で **APPROVED**）
- 対応マトリクス: [codex-history/conceptual-review-decisions-round-1.md](./codex-history/conceptual-review-decisions-round-1.md)

**概念設計の中心判断（本詳細設計はこれを前提にする）**:
`users.last_login_at` **カラムを新設しない**。`security_audit_events` の `event_type='login'` 行
（`RecordSecurityEvent` が既に `Illuminate\Auth\Events\Login` を購読して書いている）から導出する。
**記録経路を 1 本も新設しない / 列を 1 本も足さない / 変更系 route を 1 本も足さない。**

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 最終ログインの一括取得サービス | `app/Services/Security/LastLoginLookup.php`（新規） | High |
| B | Inertia props への追加 | `app/DataTransferObjects/Admin/MemberRowData.php` / `app/Http/Controllers/Admin/UserManagementController.php` | High |
| C | TS 型と画面表示 | `resources/js/types/admin.ts` / `resources/js/pages/Admin/Users.svelte` | High |
| D | 監査表の索引の置き換え | `database/migrations/2026_08_17_000100_replace_security_audit_events_user_event_index.php`（新規。**列は足さない**） | Medium |
| E | `SecurityAuditEventFactory` の新設 | `database/factories/SecurityAuditEventFactory.php`（新規） / `app/Models/SecurityAuditEvent.php` / `docs/factories.md` | High |
| F | 保持期間台帳への依存の明記 | `tests/Support/Retention/RetentionTableRegistry.php`（**区分は変えない**） | Medium |
| G | テスト | `tests/Feature/Admin/UserManagementPageTest.php` / `tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php`（新規） / `tests/js/pages/AdminUsers.test.ts` | High |

### 波及変更の全体像（インターフェース変更の影響）

`MemberRowData` は Inertia props の構造体であり、TS 側 `MemberRow` と**対で保守する**契約が
両ファイルの doc comment に明記されている。フィールドを 1 つ足すと以下が連動する。

| 層 | 影響 | 施策 |
|---|---|---|
| TypeScript 型定義 | `resources/js/types/admin.ts` の `MemberRow` に `lastLoginAt` | C |
| Inertia Props | `Admin/Users.svelte` の `members` 経由（Props interface 自体は `MemberRow[]` のままで変更不要） | C |
| API Resource / DTO | `MemberRowData`（Inertia 専用 DTO。JsonResource は経由しない = API 露出なし） | B |
| テストファイル (PHP) | `UserManagementPageTest`（既存の shape assertion に列が増える） | G |
| テストファイル (TS) | `AdminUsers.test.ts` の `membersFixture` **4 行すべて**に `lastLoginAt` を足す（型必須なので足さないと `pnpm typecheck` が落ちる） | G |
| Browser lane | **影響なし**（`/manage/users` の Browser テストは存在しない。DOM 契約を新設しない） | — |
| Filament | **影響なし**（`SecurityAuditEventResource` の表示は変えない） | — |

---

## 数える経路の確定（全施策の前提。概念設計 §3 の実装版）

導出元は `security_audit_events` の `login` 行 = `Illuminate\Auth\Events\Login` の発火である。
その発火集合を実コードで確定した（推測ではなく `SessionGuard` と各呼び出し元の実読）。
**各行に対応する検査を施策 G に持たせる**（主張とテストを 1:1 にする）。

| 経路 | `Login` 発火 | 数えるか | 根拠 | 検査 |
|---|---|---|---|---|
| パスワード（Fortify） | する | **数える** | `SessionGuard::login()` → `fireLoginEvent()` (L573) | G-1 テスト 6 |
| 2FA チャレンジ完了 | する | **数える** | 2FA 完了時に初めて `login()` が呼ばれる | G-1 テスト 9 の対照 |
| 2FA 待ちで止まった状態 | **しない** | **数えない** | セッションが確立していない = まだ入っていない | G-1 テスト 9 |
| パスキー（WebAuthn） | する | **数える** | `PasskeyLoginController::store()` の `$guard->login()` | 既存の passkey ログインテストが行を作る（追加検査は置かない） |
| SSO（Socialite） | する | **数える** | `SocialAuthController` L111 / L139 の `Auth::login(..., remember: true)` | 同上 |
| **remember me による自動復元** | する（`viaRemember=true`） | **数える** | `SessionGuard::user()` L197-202 が `fireLoginEvent($user, true)` を呼ぶ | G-1 テスト 7 |
| 新規登録直後の自動ログイン | する | **数える** | Fortify の登録後 login | G-1 テスト 11 |
| **招待受諾** | **受諾そのものは発火しない** | **前段の登録ログインとして数える** | `InvitationAcceptanceController::show` は未ログインなら token を session へ退避して `register` へ誘導し、`store`（受諾）は **auth 必須**。よって受諾の瞬間に `Login` は無く、その前段の登録の自動ログインが記録される。結果として「参加した時刻」が最終ログインになり、意図どおりである | G-1 テスト 11 |
| local 限定デバッグログイン | する | 数える（実害なし） | `DebugLoginController` は `LocalOnly` middleware の背後 = production に存在しない | 検査を置かない |
| **Filament 管理画面（`admin` guard）** | する | **数えない** | `$event->user` は `App\Models\AdminUser`。これは `Illuminate\Foundation\Auth\User` を直接継承する**別クラス**で `App\Models\User` の派生ではない。`RecordSecurityEvent::asUser()` が `null` に丸めるため `user_id` が付かず、利用者の行には**構造的に**混ざらない | G-1 テスト 10 |
| API キー（`api-key` guard） | しない | **数えない** | 機械アクセスであって人が入った事実ではない。`ApiKeyGuard` は `Login` を発火しない（実読で確認）。活動は `api_keys.last_used_at` が別に持つ | 検査を置かない（発火しないものは検査できない） |
| OAuth トークン（`mcp-oauth` / `api-oauth`） | しない | **数えない** | 同上。`oauth_sessions.last_used_at` が別に持つ | 同上 |
| セッション継続中の通常リクエスト | しない | **数えない** | 「最終ログイン」はセッション確立の時刻であり最終活動時刻ではない | G-2（クエリ数）が間接的に守る |
| `logout` / `login_failed` / その他の種別 | — | **数えない** | 種別が `login` 以外 | G-1 テスト 4 |

### remember me を数える判断（既存 listener と意図的に違える）

`app/Listeners/Auth/StampRecentAuthOnLogin.php` は `viaRemember()` が true の Login を**除外**する。
本設計は**除外しない**。問いが違うからである。

- あちらの問い: 「**たった今、資格情報を提示したか**」（機微操作の step-up を免除してよいか）。
  cookie の自動復元は資格情報の提示ではないので除外が正しい。
- こちらの問い: 「**この人は最後にいつこのシステムに入ったか**」（休眠の検出）。
  cookie で自動的に入ったのも「入った」である。除外すると
  「毎日使っているのに半年前から未ログインに見える人」が生まれ、機能の名前が果たすべき役割を裏切る。

**同じ `Login` イベントを 2 つの機能が別の条件で読む**。統合してはいけない 2 概念である（思考原則 4）。

### 「最終ログイン」であって「最終活動」ではない

remember me を使う利用者は cookie の寿命の間ログイン行が増えないため、値は最終活動より古くなりうる。
**これは仕様である**（doc/02 §2.4 の項目名は `最終ログイン日時`）。最終活動を出す機能は作らない。

---

## 施策 A: 最終ログインの一括取得サービス

### 変更箇所

- 新規ファイル: `app/Services/Security/LastLoginLookup.php`
- 置き場所の根拠: `app/Services/Security/` は既に `SecurityEventRecorder`（**書き込みの唯一の窓口**）を持つ。
  本クラスはその表の**読み取り**であり、同じ名前空間に置くのが自然。
  `SecurityEventRecorder` に読み取りメソッドを生やさない（窓口の責務は「記録」であり、
  読み取りを混ぜると窓口の意味が薄まる。思考原則 4）。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし（本クラスは DTO を返さず `array<int, CarbonImmutable>` の写像を返す。§型の根拠を参照）
- テストファイル: `tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php`（新規。施策 G）

### 現行コード

存在しない（新規）。参考にする既存の「1 クエリで写像を作る」流儀は
`UserManagementController::index` の `$pivotRoles` 構築（L42-53）。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 「この利用者は最後にいつこのシステムに入ったか」の読み取り。
 *
 * **記録点を増やさない**: 出所は security_audit_events の `login` 行だけである
 * (書き込みの窓口は SecurityEventRecorder。本クラスは読み取り専用で 1 行も書かない)。
 * users に last_login_at 列を持たない理由は
 * devnotes/20260817-0909-user-last-login-at/conceptual-design.md §2 が正本。
 *
 * **数える対象**: `Illuminate\Auth\Events\Login` が発火したセッション確立すべて
 * (パスワード / 2FA 完了 / パスキー / SSO / remember me による自動復元 / 登録直後)。
 * remember me を**除外しない**ことが App\Listeners\Auth\StampRecentAuthOnLogin との
 * 意図的な差である (あちらの問いは「たった今資格情報を提示したか」で、本クラスの問いは
 * 「最後に入ったのはいつか」。同じイベントを別条件で読む 2 概念であり統合しない)。
 * 機械アクセス (API キー / OAuth トークン) は Login を発火しないため構造的に入らない。
 *
 * ⚠ **前提 1**: users プロバイダを持つセッション系 guard は現在 `web` だけである。
 * 新しいセッション guard / loginUsingId / impersonation / magic-link を足すときは
 * 数え方を読み直すこと (StampRecentAuthOnLogin の ⚠ 注記と同じ性質の前提に立っている)。
 *
 * ⚠ **前提 2 (guard で絞らない理由)**: 本クラスは `metadata.guard` を見ない。
 * Filament の管理画面 (`admin` guard) のログインが混ざらないのは、
 * App\Models\AdminUser が App\Models\User の派生ではない**別クラス**であり、
 * RecordSecurityEvent::asUser() が null を返して `user_id` が付かないためである
 * (= 構造で保証されている)。JSON 列 `metadata` への述語で絞る形は採らない —
 * 索引が効かなくなるうえ、「どの guard を数えるか」の定義が記録側と読み取り側の
 * 2 か所に分かれて食い違う。**この前提は Feature テストで固定してある**
 * (AdminUser が User を継承する変更が入れば赤くなる)。
 *
 * ⚠ **保証しないもの**: 値は「最終**ログイン**」であって「最終**活動**」ではない。
 * remember me の cookie が生きている間は再ログインが起きないため、値は
 * 実際の利用より古くなりうる (仕様。doc/02 §2.4 の項目名に従う)。
 * また security_audit_events の保持期間は未確定であり、将来 purger が入れば
 * 古い値から失われる (この依存は RetentionTableRegistry の根拠文に記録してある)。
 */
final class LastLoginLookup
{
    /**
     * 利用者 id の集合に対する最終ログイン時刻の写像を **1 クエリ**で作る。
     *
     * 行ごとに問い合わせない (N+1 を作らない)。ログイン記録の無い利用者は
     * **キーごと現れない** (null を詰めない = 呼び出し側が `?? null` で受ける)。
     *
     * @param  list<int>  $userIds
     * @return array<int, CarbonImmutable>
     */
    public function forUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return []; // 空集合に whereIn を投げない (アーリーリターン)
        }

        $rows = SecurityAuditEvent::query()
            ->select('user_id')
            ->selectRaw('max(occurred_at) as last_login_at')
            ->whereIn('user_id', $userIds)
            ->where('event_type', SecurityEventType::Login->value)
            ->groupBy('user_id')
            // 集計列にはモデルの casts が効かない (occurred_at の cast は別名には伝播しない)。
            // driver 差 (string / DateTime) を SQL 層で吸収せず、framework の cast で閉じる。
            ->withCasts(['last_login_at' => 'immutable_datetime'])
            ->get();

        /** @var array<int, CarbonImmutable> $map */
        $map = [];
        foreach ($rows as $row) {
            $userId = $row->getAttribute('user_id');
            // bigint の PHP 表現は driver 設定で int / integer-string に揺れる。
            // numeric ではなく integerish で受ける (numeric は 1.5 のような float も通してしまう)
            Assert::integerish($userId);

            $lastLoginAt = $row->getAttribute('last_login_at');
            // 集計値が null になるのは group が成立しない場合だけなので、ここは常に日時である。
            // 黙って捨てず instanceof で narrowing する (mixed を外へ出さない = level 10 対応)
            Assert::isInstanceOf($lastLoginAt, CarbonImmutable::class);

            $map[(int) $userId] = $lastLoginAt;
        }

        return $map;
    }
}
```

### 型の根拠（Codex conceptual Round 1 [Warning] への対応）

`max(occurred_at)` の値はモデルの `casts()` が効かない（cast は実列 `occurred_at` に対してのみ定義され、
別名 `last_login_at` には伝播しない）。driver によって `string` / `DateTime` に揺れるため、
**framework 標準の `Builder::withCasts()`**（`vendor/laravel/framework/.../Eloquent/Builder.php` L1976）で
`immutable_datetime` に固定する。自前で `CarbonImmutable::parse()` の分岐を書かない
（思考原則 1: フレームワークのレンジ内でやる）。
`Assert::isInstanceOf` は narrowing と fail-loud を同時に満たす（黙って捨てる `else` を作らない）。

> **注意（誤読を防ぐ）**: immutable になるのは**別名 `last_login_at` だけ**である。
> `SecurityAuditEvent::casts()` の `occurred_at` は `'datetime'`（**mutable** Carbon）のままで、
> 本設計はそれを変えない。「監査モデルは immutable を返す」と読まないこと。

### セキュリティ不変条件との突き合わせ

| 不変条件 | 判定 |
|---|---|
| 3. cross-org 不可 / クラス起点の主キー同一性クエリ | **母集団に入らない**。本クラスのクエリは `whereIn('user_id', …)` であり、`SecurityAuditEvent` の**主キー**に対する同一性述語（`find` / `whereKey` / `where('id', …)`）を 1 つも持たない。`PrimaryKeyStaticQueryScanner` の `OWNER_COLUMNS` は `user_id` を**テナント絞り込み側**として扱う。よって `DirectFetchInventory` への登録は不要（実装時に `composer test` で確認する） |
| cross-org の実質 | `$userIds` の出所は**呼び出し側が org relation から取った集合だけ**である（施策 B）。本クラス自身は org を知らないので、org を跨ぐ集合を渡すと跨いで返す。**この責務境界を doc comment に書かない**（書くと「渡す側が正しければ安全」という弱い保証を強い保証のように読ませる）代わりに、施策 B 側で relation 経由の集合しか渡らないことを固定し、テストで cross-org 混入を検査する（施策 G のテスト 5） |
| 9. 変更系 route は認可を通る | **母集団が増えない**（GET のみ。route を足さない） |
| 6. PII は CipherSweet | `security_audit_events` に PII 列は無い。`user_id` / `event_type` / `occurred_at` のみ触る |
| 11. キャッシュに入れるのは素のデータだけ | **キャッシュを使わない**（毎回クエリする。1 クエリで足りるため） |

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`array<int, CarbonImmutable>`）
- [x] null 安全（`Webmozart\Assert\Assert` で `mixed` を narrowing。`mixed` を外へ出さない）
- [x] DTO を返している（値の写像を返す。`response()->json()` を書かない）
- [x] Generics の型パラメータが正しい（`@param list<int>` / `@return array<int, CarbonImmutable>`）
- [x] `declare(strict_types=1)` + `final`

### リスク

- **行数の増加に対する読み取り性能**。`security_audit_events` は保持期間未確定 = 単調増加が確定している。
  1 利用者あたりの login 行は年単位で数千行になりうる。→ **施策 D で索引を張り替える**。
- **`withCasts` の挙動が将来の Laravel で変わる可能性**。→ 施策 G のテスト 1（値が ISO8601 で props に出る）が
  実 DB 経由で固定するため、変わればテストが落ちる。

---

## 施策 B: Inertia props への追加

### 変更箇所

- `app/DataTransferObjects/Admin/MemberRowData.php`（全体）
- `app/Http/Controllers/Admin/UserManagementController.php` (L32-79)

### 波及変更

- TypeScript 型定義: `resources/js/types/admin.ts` の `MemberRow`（施策 C）
- API Resource/DTO: `MemberRowData` 自身。**JsonResource は経由しない**（Inertia 専用）
- テストファイル: `tests/Feature/Admin/UserManagementPageTest.php`（施策 G）

### 現行コード（MemberRowData）

```php
final readonly class MemberRowData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleState,       // MemberRoleState value
        public string $roleLabel,
        public string $twoFactorStatus, // disabled|pending|enabled
        public bool $isSelf,
    ) {}

    public static function fromUser(User $user, ?OrganizationRole $orgRole, ?ProjectRole $projectRole, int $currentUserId): self
    {
        $state = MemberRoleState::derive($orgRole, $projectRole);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            twoFactorStatus: $user->twoFactorStatus()->value,
            isSelf: $user->id === $currentUserId,
        );
    }
}
```

### 変更後コード（MemberRowData）

```php
/**
 * ユーザー管理画面 (Admin/Users) のメンバー 1 行分。TS 側 types/admin.ts の MemberRow と対で保守。
 * 表示状態 (roleState) は org ロール × Default Project pivot から毎回導出する (概念設計 D2(a))。
 * email は CipherSweet 復号値。本画面は manageMembers 権限者しか到達できない (403) ため
 * 行レベルの可視性分岐は持たない (PII 可視性は画面到達境界で担保)。
 *
 * lastLoginAt は「最後にいつ入ったか」であり、users の列ではなく security_audit_events の
 * login 行から導出する (App\Services\Security\LastLoginLookup)。**履歴は持たない**。
 * 記録が無い利用者は null で、UI は「記録なし」と表示する — 「一度も入っていない」と
 * 断定しないのは、導出元の保持期間が未確定で将来 purge されうるためである。
 */
final readonly class MemberRowData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleState,       // MemberRoleState value
        public string $roleLabel,
        public string $twoFactorStatus, // disabled|pending|enabled
        public bool $isSelf,
        public ?string $lastLoginAt,    // ISO8601 (オフセット付き) / 記録が無ければ null
    ) {}

    public static function fromUser(
        User $user,
        ?OrganizationRole $orgRole,
        ?ProjectRole $projectRole,
        int $currentUserId,
        ?CarbonImmutable $lastLoginAt,
    ): self {
        $state = MemberRoleState::derive($orgRole, $projectRole);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            twoFactorStatus: $user->twoFactorStatus()->value,
            isSelf: $user->id === $currentUserId,
            // オフセット付きで出す。toDateTimeString() は使わない —
            // 端末側 Intl が UTC を現地時刻として解釈し 9 時間ずれる
            lastLoginAt: $lastLoginAt?->toIso8601String(),
        );
    }
}
```

**引数を末尾に足す**（既存の位置引数の順序を変えない = 呼び出し側の破壊を最小にする）。
`?CarbonImmutable` を**必須引数**にする（既定値 `null` を与えない）ことで、
将来 `fromUser` の呼び出し元が増えたときに「渡し忘れて全員 null」が静かに起きない。

### 現行コード（UserManagementController::index、抜粋）

```php
$members = [];
foreach ($organization->users()->get() as $member) {
    $members[] = MemberRowData::fromUser(
        $member,
        $member->organizationRole($organization),
        $pivotRoles[$member->id] ?? null,
        $user->id,
    );
}
```

### 変更後コード（UserManagementController::index、抜粋）

```php
public function index(
    Request $request,
    DefaultProjectResolver $defaultProjects,
    LastLoginLookup $lastLogins,   // ← DI で受ける (container 解決。new しない)
): Response {
    $organization = $this->resolveCurrentOrganization($request);
    Gate::authorize('manageMembers', $organization); // 撮影者・一般メンバーは 403

    // …（$pivotRoles の構築は現行のまま）…

    // メンバー集合は org relation 経由でのみ解決する (cross-org 越境不能)
    $organizationMembers = $organization->users()->get();

    // 最終ログインは行ごとに引かず、id 集合に対して 1 クエリで写像を作る (N+1 を作らない)。
    // 渡す id 集合は上の relation の結果そのものなので、他組織の利用者は構造的に入らない。
    // pluck() は Collection<int, mixed> に落ちて list<int> の narrowing が自己申告になるため、
    // 型が閉じる array_map + array_values で作る (型を緩めて黙らせない = 禁止事項 2)
    $memberIds = array_values(array_map(
        static fn (User $member): int => $member->id,
        $organizationMembers->all(),
    ));
    $lastLoginMap = $lastLogins->forUserIds($memberIds);

    $members = [];
    foreach ($organizationMembers as $member) {
        $members[] = MemberRowData::fromUser(
            $member,
            $member->organizationRole($organization),
            $pivotRoles[$member->id] ?? null,
            $user->id,
            $lastLoginMap[$member->id] ?? null,
        );
    }

    // …（invitations / Inertia::render は現行のまま）…
}
```

**変更点は 3 つだけ**: (1) DI 引数を 1 つ足す、(2) `$organization->users()->get()` を変数に束ねて
2 度引かない、(3) 写像を作って `fromUser` へ渡す。認可・組織解決・招待の取得は**一切触らない**。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`Response`。既存のまま）
- [x] null 安全（`$lastLoginMap[$member->id] ?? null` で不在キーを明示的に null へ）
- [x] DTO を返している（`MemberRowData`。`response()->json()` 無し）
- [x] Generics の型パラメータが正しい（`array_values(array_map(static fn (User $member): int => …))` で
      `list<int>` が型として閉じる。`@var` の自己申告に頼らない — **型を緩めて黙らせない**）

### リスク

- `fromUser` のシグネチャ変更は**呼び出し元が 1 か所しかない**（`UserManagementController`。
  実読で確認済み）ため波及は小さい。
- `$organization->users()->get()` を 2 度呼ばない形に変える副次効果としてクエリが 1 本減る
  （現行は relation を 1 回だけ呼んでいるので実際には増減なし。**性能改善を主張しない**）。

---

## 施策 C: TS 型と画面表示

### 変更箇所

- `resources/js/types/admin.ts` (L12-20)
- `resources/js/pages/Admin/Users.svelte` (L296-311 のメンバー情報ブロック / import 節)

### 波及変更

- TypeScript 型定義: 本施策そのもの
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/AdminUsers.test.ts` の `membersFixture` **4 行すべて**（施策 G）。
  `lastLoginAt` は optional にしないので、足さなければ `pnpm typecheck` が落ちる（意図的）

### 現行コード（types/admin.ts）

```ts
export interface MemberRow {
    id: number;
    name: string;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    twoFactorStatus: "disabled" | "pending" | "enabled";
    isSelf: boolean;
}
```

### 変更後コード（types/admin.ts）

```ts
export interface MemberRow {
    id: number;
    name: string;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    twoFactorStatus: "disabled" | "pending" | "enabled";
    isSelf: boolean;
    /**
     * 最終ログイン日時 (ISO8601、オフセット付き)。記録が無ければ null。
     * 出所は security_audit_events の login 行 (users の列ではない)。
     * null は「一度も入っていない」と「記録が残っていない」を区別しない。
     */
    lastLoginAt: string | null;
}
```

### 現行コード（Admin/Users.svelte、メンバー情報ブロック）

```svelte
<div class="min-w-0 sm:min-w-40">
    <div class="flex items-center gap-2">
        <p class="truncate text-body">{member.name}</p>
        {#if member.twoFactorStatus === "enabled"}
            <Badge tone="success">2FA</Badge>
        {/if}
        {#if member.roleState === "unassigned"}
            <Badge tone="warning" testId={`unassigned-${member.id}`}>
                未割当
            </Badge>
        {/if}
    </div>
    <p class="truncate text-caption text-text-secondary">
        {member.email}
    </p>
</div>
```

### 変更後コード（Admin/Users.svelte）

import 節に 1 行足す:

```ts
import { formatDateTime } from "@/lib/date-format";
```

メンバー情報ブロック（メール行の直後に 1 行足す）:

```svelte
    <p class="truncate text-caption text-text-secondary">
        {member.email}
    </p>
    <!-- 最終ログイン。値の無い行は「記録なし」(「未ログイン」と断定しない — 導出元の
         security_audit_events は保持期間が未確定で、将来 purge されうるため)。
         表示は閲覧者の端末タイムゾーンで行う (date-format.ts の Intl 経由。DS token のみ) -->
    <p
        class="truncate text-caption text-text-secondary"
        data-testid={`member-last-login-${member.id}`}
    >
        最終ログイン {formatDateTime(member.lastLoginAt, "記録なし")}
    </p>
```

### 設計判断

- **`formatDateTime` の fallback 引数で null を吸収する**（Codex conceptual Round 1 の
  「`null` 分岐後にのみ `formatDateTime()` を呼ぶ」提案は採らない）。
  `resources/js/lib/date-format.ts` は「各ページに散在しがちな `toLocaleDateString('ja-JP')` 呼び出しと
  **null/不正値ハンドリングの SSoT**」と自ら宣言しており、呼び出し側で null 分岐を書くのは
  その SSoT を迂回することになる。`Billing/Index.svelte` の `formatDate(page.balance.nextExpireAt, "—")` が
  既存の準拠例である。
- **`formatDate` ではなく `formatDateTime`** を使う。休眠判定は日付粒度で足りるが、
  doc/02 §2.4 の項目名が `最終ログイン日時` であり、`PasskeySection` の「最終利用」（日付のみ）とは
  意味の粒度が違う（あちらは資格の使用痕跡、こちらは在籍の指標）。
- **DS token のみ**: `text-caption` / `text-text-secondary` は `DESIGN.md` L132 / L96 に定義済み。
  hex 直書き無し、新規 token 無し、新規 component 無し、アイコン追加無し。
- **atomic import 階層に影響なし**: `pages` から `lib` を import する形は既存
  （`Billing/Index.svelte` / `Capture/Index.svelte` と同一）。component 層を跨がない。
- **操作列には入れない**。読み取り情報は左の情報ブロックへ、操作は右の actions 列へ、という
  現行の分離を保つ。F-14（375px でのモバイル縦積み）のレイアウトは、情報ブロック内の
  `<p>` が 1 本増えるだけなので横幅に影響しない。

### リスク

- 行が 1 本増えることでモバイル（375px）のリスト密度が下がる。→ 情報ブロックは既に
  `flex-col` の縦積みで、追加は縦方向のみ。**横スクロールは発生しない**（F-14 の最悪幅構成は
  actions 列側で決まる）。
- `truncate` を付けるため、極端に長いロケール表現でも溢れない。

---

## 施策 D: 監査表の索引の置き換え

### 変更箇所

- 新規: `database/migrations/2026_08_17_000100_replace_security_audit_events_user_event_index.php`
- **`users` にも `security_audit_events` にも列を 1 本も足さない**

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（索引は挙動を変えない。`RetentionTableClassificationTest` は
  **表**単位の台帳であり列も索引も見ないため、台帳の件数・区分は不変）

### 現行コード

`database/migrations/2026_06_11_071300_create_security_audit_events_table.php`:

```php
$table->index(['user_id', 'event_type']);   // 既定名: security_audit_events_user_id_event_type_index
$table->index('occurred_at');
```

### 変更後コード

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * security_audit_events の複合索引を occurred_at まで伸ばす (**列は足さない**)。
 *
 * 用途: /manage/users の最終ログイン表示が
 *   select user_id, max(occurred_at) … where user_id in (…) and event_type = 'login' group by user_id
 * を撃つ。既存の ['user_id','event_type'] は絞り込みには効くが最大値の取得には効かず、
 * 該当利用者の login 行を全件読むことになる。本表は保持期間が未確定 = 単調増加が確定しているため、
 * 行数が増えるほど遅くなる形を残さない。
 *
 * **追加ではなく置き換え**である。新索引は先頭 2 列が旧索引と同じなので、
 * `user_id, event_type` の**前方一致クエリでは代替できる** (B-tree の左端一致)。
 * 「旧索引の全用途を保証する」とは書かない (誇張しない)。並走を残さない (AGENTS.md 思考原則 3)。
 *
 * ⚠ **この migration は短時間の書き込み停止を許容する**。
 * pgsql の CREATE INDEX (非 CONCURRENTLY) は対象表に SHARE lock を取り、索引構築の間
 * INSERT を止める。本表へ INSERT するのは認証経路 (ログイン / ログアウト / ログイン失敗) なので、
 * **構築中はログインが待たされる**。現行の行数では体感できない長さだが、
 * **低トラフィックの時間帯に実行すること**。無停止が要件になった場合の作り直し方は
 * devnotes/20260817-0909-user-last-login-at/detailed-design.md §施策 D の「将来の見直し条件」。
 *
 * ⚠ **rollback (down) も同じ SHARE lock を取る**。切り戻しも同じ条件で実行すること。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table): void {
            // 先に新索引を張ってから旧索引を落とす (索引が 1 本も無い瞬間を作らない)。
            // 既定命名なので新索引は security_audit_events_user_id_event_type_occurred_at_index。
            $table->index(['user_id', 'event_type', 'occurred_at']);
            // 旧索引 security_audit_events_user_id_event_type_index を落とす。
            // 張った側 (2026_06_11_071300_create_security_audit_events_table.php) も配列指定なので
            // 既定命名で一致する。名前を直書きせず配列で指定する (2 か所に同じ文字列を持たない)
            $table->dropIndex(['user_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table): void {
            $table->index(['user_id', 'event_type']);
            $table->dropIndex(['user_id', 'event_type', 'occurred_at']);
        });
    }
};
```

### 既存行の default と backfill 方針（migration を追加するため明示する）

- **列を足さないので default は存在しない**。既存行に書き込む値も無い。
- **backfill は行わない / 必要ない**。`security_audit_events` の `login` 行は
  2026-06 の表作成以降**すべて記録済み**であり（`RecordSecurityEvent` が最初から購読している）、
  既存ユーザーの最終ログインは**索引を張り替えた瞬間から正しい値が出る**。
  これが実質の backfill にあたる（データ移行の作業は 0 件）。
- **一度もログインしていない既存ユーザー**（seeder 由来など）は写像にキーが現れず、
  props が `null` になり、画面は「記録なし」を表示する（施策 C）。

### 索引名（明示名を直書きしない）

- **索引名は Laravel の既定命名に任せる**（新: `security_audit_events_user_id_event_type_occurred_at_index`、
  旧: `security_audit_events_user_id_event_type_index`）。
- `dropIndex(['user_id','event_type'])` は配列指定から既定命名を再構成する。
  **張った側（`2026_06_11_071300_create_security_audit_events_table.php`）も配列指定**なので命名は一致する
  （実読で確認済み）。
- **明示名での drop は採らない**。落とす側だけ名前を直書きすると「同じ文字列を 2 か所に持つ」形になり、
  本リポジトリが繰り返し禁じている二重管理になる。読む人のために**コメントで既定名を示す**にとどめる。

### この migration が許容すること（停止許容の明示。Codex design-review Round 1 [Critical]）

**この migration は「短時間の書き込み停止」を明示的に許容する設計である。**

- pgsql の `CREATE INDEX`（非 `CONCURRENTLY`）は対象表に `SHARE` lock を取り、
  索引構築の間 **INSERT を止める**。`security_audit_events` へ INSERT するのは認証経路
  （ログイン / ログアウト / ログイン失敗）なので、**構築中はログイン処理が待たされる**。
- `DROP INDEX`（非 `CONCURRENTLY`）は `ACCESS EXCLUSIVE` lock を取り、その表の**読み書き両方**を
  一瞬止める。索引 1 本の削除は短時間だが、ゼロではない。
- **rollback（`down()`）も同じ性質の lock を取る**。切り戻しも同じ条件で扱う。

**運用条件（実行する人への要求）**:

1. **低トラフィックの時間帯に実行する**（ログインが数秒待たされても業務が止まらない時間帯）。
2. **行数が小さいうちに済ませる**。本表は保持期間未確定 = 単調増加が確定しているため、
   先送りするほど構築時間が伸びる。
3. 実行前に `select count(*) from security_audit_events` を見て、想定外に大きければ
   下の「将来の見直し条件」へ切り替える。

**`CONCURRENTLY` を今は使わない理由**:

- (a) `CREATE INDEX CONCURRENTLY` はトランザクション内で実行できず、
  Laravel の migration は pgsql で既定でトランザクションに包むため `public $withinTransaction = false;` が要る。
  そうすると**失敗時に途中状態（invalid index）が残り、人手の復旧手順が必要になる**。
  復旧手順を持たない機構を先に置くのは、運用の負債を増やすだけである。
- (b) 現時点で本表の行数は小さく、**リポジトリにデプロイ定義が存在しない**
  （AGENTS.md の route:cache 運用要件の注記）ため、無停止索引構築を要する運用条件が**まだ無い**。
  過剰な機構を先回りして作らない（思考原則 2）。

**将来の見直し条件**: 本表が百万行規模に達する、**または**無停止デプロイ基盤ができたときは、
`CREATE INDEX CONCURRENTLY` + `$withinTransaction = false` + **invalid index の検出と再実行の手順**を
セットで設計し直す。3 点を揃えずに `CONCURRENTLY` だけ入れないこと。

### PHPStan 適合チェック

- [x] `declare(strict_types=1)` あり
- [x] closure の引数・戻り値に型を明示（`function (Blueprint $table): void`）

### リスク

- **索引の張り替え中に認証経路の INSERT が待つ**（上の「許容すること」で明示的に受容した）。
  現行のデータ量では体感できない長さ。
- **rollback（`down()`）も同じ lock を取る**。切り戻しの最中も認証経路が待つ。
- **旧索引に依存する未知のクエリが遅くなる可能性**。→ 新索引は先頭 2 列が旧索引と同じなので、
  `user_id, event_type` の**前方一致の用途では代替できる**（B-tree の左端一致）。
  **それ以外の用途（索引だけで読み取りが完結する列構成に依存していた場合など）は保証しない**。
  本表に対するその種のクエリは実読の範囲では見つかっていないが、
  「旧索引の全用途を包含する」とは書かない（誇張しない）。
- **性能が上がるとは主張しない**。主張するのは「最終ログインの導出が行数の増加に耐えるようになる」ことだけ。

---

## 施策 E: `SecurityAuditEventFactory` の新設

### 変更箇所

- 新規: `database/factories/SecurityAuditEventFactory.php`
- `app/Models/SecurityAuditEvent.php`（`HasFactory` trait の追加。現在は付いていない）
- `docs/factories.md`（Factory 一覧への追記。AGENTS.md 実装規約で必須）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 G が使う

### 現行コード

`SecurityAuditEvent` は `HasFactory` を使っていない（実読で確認）。
既存テストは本モデルを**読むだけ**で、生成は「実際にログインさせる」形で行っている
（`SecurityAuditTrailCoverageTest` / `OwnershipTransferTest` 等）。

### 変更後コード

`app/Models/SecurityAuditEvent.php`:

```php
use Database\Factories\SecurityAuditEventFactory;   // @use の型名解決に必要
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SecurityAuditEvent extends Model
{
    /** @use HasFactory<SecurityAuditEventFactory> */
    use HasFactory;

    // …（既存のまま。casts() の occurred_at は 'datetime' のまま変えない）…
}
```

`database/factories/SecurityAuditEventFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityAuditEvent>
 *
 * 監査行そのものを作る factory。**アプリの記録経路ではない**
 * (本番の記録は App\Services\Security\SecurityEventRecorder の 1 本道のみ)。
 * 過去時刻の行 (「3 か月前のログイン」等) をテストで用意するために置く。
 */
class SecurityAuditEventFactory extends Factory
{
    protected $model = SecurityAuditEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_type' => SecurityEventType::Login->value,
            'metadata' => ['guard' => 'web'],
            'ip_address' => $this->faker->ipv4(),
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    /** 記録対象の利用者を指定する (user_id は所有権キーのため state で明示代入する) */
    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    /** 種別を差し替える (login 以外を数えないことの検査に使う) */
    public function ofType(SecurityEventType $type): self
    {
        return $this->state(fn (): array => ['event_type' => $type->value]);
    }

    /**
     * 発生時刻を指定する (最新の 1 件が選ばれることの検査に使う)。
     *
     * ⚠ 引数が CarbonImmutable なのは**呼び出し側の都合**である。
     * SecurityAuditEvent の casts() は occurred_at を 'datetime' (**mutable** Carbon) と
     * 宣言しており、モデルから読み戻した値は mutable のままである
     * (「監査モデルが immutable を返す」と読まないこと。immutable になるのは
     *  LastLoginLookup が withCasts で作る別名 last_login_at だけである)。
     */
    public function occurredAt(CarbonImmutable $at): self
    {
        return $this->state(fn (): array => ['occurred_at' => $at]);
    }
}
```

`docs/factories.md` の一覧へ 1 行追記:

```
| `SecurityAuditEventFactory` | SecurityAuditEvent | `forUser($user)`, `ofType(SecurityEventType)`, `occurredAt(CarbonImmutable)`。既定は `login` / `now()` / guard=web。**本番の記録経路ではない** (記録の窓口は SecurityEventRecorder) |
```

### 設計判断

- `user_id` は `$fillable` 外の所有権キーだが、**Factory は `$fillable` を経由しない**
  （`Model::newInstance()` → `forceFill` 相当）ため state で直接指定してよい。
  これは `TakeUploadReservationFactory` が `organization_id` を state で入れているのと同じ流儀。
- **`SecurityEventRecorder` を factory から呼ばない**。窓口は本番の記録経路であり、
  テストの都合で過去時刻を注入できるようにすると窓口の契約（`occurred_at = now()`）が緩む。

### PHPStan 適合チェック

- [x] `@extends Factory<SecurityAuditEvent>` を宣言
- [x] `definition()` の戻り値型 `array<string, mixed>`
- [x] state closure の戻り値型を明示

### リスク

- `HasFactory` の追加はモデルの振る舞いを変えない（trait はメソッドを増やすだけ）。

---

## 施策 F: 保持期間台帳への依存の明記

### 変更箇所

- `tests/Support/Retention/RetentionTableRegistry.php` の `security_audit_events` entry の**根拠文のみ**

### 波及変更

- **区分は `undecided` のまま変えない**。よって `RetentionTableClassificationTest` の
  `RETENTION_TABLE_COUNT`（63）も `RETENTION_UNDECIDED_TABLES`（`security_audit_events` を含む）も**変えない**
- テストファイル: **新規テストを作らない**（下記の重要な発見を参照）

### 重要な発見: トリップワイヤは既に存在する

概念設計 §2-4 決着 3(b) は「区分そのものを pin するトリップワイヤを置く」としたが、
**実装済みだった**。`tests/Feature/Retention/RetentionTableClassificationTest.php` の
`RC-8`（L461-474）が `RETENTION_UNDECIDED_TABLES` を**現在値ちょうどで pin** しており、
その一覧に `security_audit_events` が含まれている（L66）。

したがって、誰かが `security_audit_events` に保持期間を決めて区分を動かした瞬間、
RC-8 が「未確定の表の一覧が変わりました」で落ち、その定数を書き換えるレビューが必ず発生する。
**新しい gate を足さない**（同じ事実を 2 か所で検査しない。思考原則 2 / AGENTS.md「二重検査を作らない」）。
本施策がやるのは、その瞬間に読まれる根拠文へ**依存の事実を書き足すこと**だけである。

### 現行コード

```php
RetentionTableEntry::undecided(
    'security_audit_events',
    '認証と権限に関わる操作の証跡。利用者への外部キーが空値化のため退会後も行が残る。'
    .'監査に必要な保持期間が未決である',
),
```

### 変更後コード

```php
RetentionTableEntry::undecided(
    'security_audit_events',
    '認証と権限に関わる操作の証跡。利用者への外部キーが空値化のため退会後も行が残る。'
    .'監査に必要な保持期間が未決である。'
    .'なおこの表の login 行は /manage/users の最終ログイン表示の唯一の出所であり、'
    .'期限を決めて古い行を消すと、休眠の判定に必要な古い値から先に失われる。'
    .'期限を決めるときは devnotes/20260817-0909-user-last-login-at/ を読み直すこと',
),
```

### PHPStan 適合チェック

- [x] 根拠文は 30 文字以上（`RetentionTableEntry::RATIONALE_MIN_LENGTH` = 30。既に大幅に超過）
- [x] 型の変更なし（`undecided()` の呼び出し形は不変）

### リスク

- 根拠文の変更だけなので機械検査は落ちない（RC-3 は長さのみを見る）。
- `devnotes/` へのパス参照は将来ディレクトリが消えると死ぬ。→ devnotes はコミット対象であり
  削除しない運用（AGENTS.md「設計・TODO・devnotes の運用」）。

---

## 施策 G: テスト

### 変更箇所

- `tests/Feature/Admin/UserManagementPageTest.php`（既存 150 行に追記）
- `tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php`（新規）
- `tests/js/pages/AdminUsers.test.ts`（既存 504 行。fixture 更新 + テスト追加）

### テストファースト（AGENTS.md 思考原則 5）

実装前に **G-1 と G-2 を書いて fail を確認**してから施策 A〜C に入る
（`lastLoginAt` が props に無いので `assertInertia` の `where` が落ちる）。

### G-1. Feature: props の値（`UserManagementPageTest.php` に追記）

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | login 記録のあるメンバーは lastLoginAt に ISO8601 が載る | `SecurityAuditEventFactory` で `login` 行を作り、props の `members.*.lastLoginAt` が `CarbonImmutable` の `toIso8601String()` と一致すること（**オフセット付き**であることも合わせて固定する。`toDateTimeString()` への退行を検出する） |
| 2 | login 記録の無いメンバーは lastLoginAt が null | 招待受諾直後などを想定。`->where('members.0.lastLoginAt', null)` |
| 3 | 複数の login 行があれば**最新**が選ばれる | 3 か月前 / 昨日 / 1 年前 の 3 行を作り、昨日が返ること |
| 4 | `login` 以外の種別は数えない | `logout` / `login_failed` / `password_changed` の行しか無いメンバーは null になること（`ofType()` state を使う） |
| 5 | **他組織のメンバーの login 行が混ざらない** | 別組織のユーザーに login 行を作り、当組織の一覧に影響しないこと。加えて**同一 id 帯の取り違えが無い**こと（cross-org 不変条件の behavioral 検査） |
| 6 | **実際のログインで値が入る**（配線の通し確認） | factory ではなく `POST /login` を実行し、その後 `/manage/users` の props に時刻が載ること。`RecordSecurityEvent` → `SecurityEventRecorder` → 導出 の鎖が繋がっていることを固定する（施策 A が「既存の記録に乗る」という前提の behavioral な担保） |
| 7 | **remember me による自動ログイン復元も数える** | **実 HTTP で recaller 経路を踏む**。`viaRemember` を除外していないことの固定であり、**`StampRecentAuthOnLogin` とは逆の扱い**であることを守る（手順は下記） |
| 8 | 認可境界は既存のまま | 既存の「org Member は 403」テストが `lastLoginAt` の露出も同時に塞いでいることを確認（**新規テストは足さない**。403 なら props ごと存在しない） |
| 9 | **2FA 未完了では数えない** | 2FA を有効にした利用者がパスワードだけ通過し `two-factor-challenge` へ回された時点では `login` 行が**増えない**こと。チャレンジを完了させると増えること（対照）。「セッションが確立していない = まだ入っていない」の固定 |
| 10 | **Filament の admin ログインが混ざらない** | `admin` guard でログインしても、`user_id` の付いた `login` 行が増えないこと。`App\Models\AdminUser` が `App\Models\User` の派生になる変更が入れば赤くなる（施策 A の前提 2 の behavioral な固定） |
| 11 | **招待経由で参加した利用者は参加時刻が最終ログインになる** | 招待 URL を未ログインで開く → `register` へ誘導 → 登録（自動ログイン）→ 受諾、の鎖を通したあと `/manage/users` の props にその時刻が載ること。「受諾そのものは `Login` を発火しないが、前段の登録ログインで数えられる」ことの固定 |

### テスト 7（remember me）の実装手順

Codex Round 1 の [Critical] を受けて、**弱い代替案（handler の直接呼び出し）は採らない**。
実 HTTP の recaller 経路を踏む。実現可能性は vendor の実読で確認済み:

1. `POST /login` を `remember` 付きで実行してログインする（1 回目の `login` 行が入る）。
2. `$user->fresh()->remember_token` を読む。
3. セッションを捨てる（`$this->flushSession()`）。
4. recaller cookie を組み立てて 2 回目のリクエストを撃つ。
   - cookie 名: `Auth::guard('web')->getRecallerName()`（`SessionGuard` L884 に public で存在）
   - 値: `"{$user->id}|{$rememberToken}|{$user->getAuthPassword()}"`
   - 渡し方: `$this->withCookie($name, $value)`（テストヘルパ側が暗号化して載せる。
     `MakesHttpRequests` L251）
5. 2 回目のリクエストで `SessionGuard::user()` が recaller 経路に入り
   `fireLoginEvent($user, true)`（L197-202）が発火して **2 本目の `login` 行が入る**こと、
   および `/manage/users` の `lastLoginAt` が 2 回目の時刻になることを検査する。

**この検査が守るもの**: 「recaller 復元でも監査行が増える」という配線そのもの。
listener の内部条件だけを見る形にすり替えない。

### G-2. Feature: クエリ数の行数非依存（新規 `UserManagementLastLoginQueryCountTest.php`）

`tests/Feature/Capture/CaptureManualListQueryCountTest.php` の流儀をそのまま踏襲する。

- メンバー 1 人の組織と 10 人の組織で `/manage/users` を叩き、**発行クエリ数が同じ**であること
- 計測前に暖機の GET を 1 回撃つ / fixture 生成は `DB::flushQueryLog()` で計測外にする
- **同一利用者（owner）で行数だけを変えて比較する**（権限差でクエリ数が変わるため）
- **メンバー数以外の条件を両ケースで揃える**（Codex Round 1 の Suggestion）:
  招待は両ケースとも **0 件** / Default Project は両ケースとも **不在** /
  ロールは全員同一 / 全メンバーに `login` 行を 1 本ずつ持たせる。
  こうしないと招待件数や pivot の有無でクエリ数が動き、
  「最終ログインが N+1 になった」以外の理由で赤/緑が変わって検査の意味が薄れる
- これが落ちる = 誰かが `LastLoginLookup` を行ごとに呼ぶ形へ戻した、という意味になる

### G-3. Vitest: 表示（`AdminUsers.test.ts`）

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | fixture 更新 | `membersFixture` の **4 行すべて**に `lastLoginAt` を足す（1 件は `null` にする）。型必須なので足さないと `pnpm typecheck` が落ちる |
| 2 | 値のある行は日時を表示する | `member-last-login-{id}` に `formatDateTime` の結果（`2026/…` 形式）が含まれること |
| 3 | 値が null の行は「記録なし」を表示する | 「未ログイン」という語が**出ない**ことも合わせて固定する（§4-3 の文言判断の退行検出） |
| 4 | 既存の描画テストが壊れていない | メンバー一覧・招待中・追加フォームの既存アサーションがそのまま緑 |

> **Vitest はブラウザのタイムゾーンに依存する**。`formatDateTime` は `Intl` で
> 実行環境の TZ に変換するため、期待値を固定文字列でハードコードしない
> （`formatDateTime(fixtureValue)` の戻り値と比較するか、`2026/` の部分一致で見る）。

### G-4. 走らせる検証コマンド（AGENTS.md の検証コマンド節）

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`

- `RefreshDatabase` はグローバル適用済み。**個別 `DatabaseTransactions` を書かない**
- テストレーンはホスト全体のグローバルロックで直列化される。heartbeat が出ている間は kill しない

### 既存テストへの影響（削除・上書きをしない）

- `UserManagementPageTest` の既存 shape assertion は `where('members.0.roleState', 'owner')` 等の
  **フィールド単位**なので、フィールドが増えても落ちない（`missing('categoriesUrl')` も無関係）
- `AdminUsers.test.ts` は fixture に必須フィールドが増えるため**型エラーで落ちる**。
  これは意図した波及であり、fixture を直すことで解消する（テストの削除・骨抜きはしない）

---

## 使命・禁止事項チェック（最終確認）

| 項目 | 判定 |
|---|---|
| 使命への寄与 | 直接の生産活動ではない**運用支援**。招待した現場作業者が実際に入れているかを組織管理者が確認できるようにする（オンボーディング不全が無音で放置されない）。概念設計 §6 で「撮影体験そのものの改善ではない」と正直に位置づけ済み |
| 禁止事項 1（テストなし完了） | 施策 G で Feature 8 件 + クエリ数 1 件 + Vitest 4 件を計画。テストファーストで G-1/G-2 の fail を先に見る |
| 禁止事項 2（PHPStan widen） | `withCasts` + `Assert` で `mixed` を narrowing。`@phpstan-ignore` / baseline を使わない |
| 禁止事項 3（dev DB 破壊） | migration は索引の張り替えのみ。`migrate:fresh` を使わない |
| 禁止事項 4（`response()->json()`） | Inertia props + DTO のみ。JsonResource も API 露出も無し |
| 禁止事項 5・6（LLM / prompt） | 該当なし（LLM に触れない） |
| 禁止事項 7（`redirect()->intended()`） | 該当なし（GET のみ） |
| 禁止事項 8（disabled UI） | 操作を 1 つも足さない |
| 禁止事項 9（Artifact） | 成果物はすべて `devnotes/` 配下のファイル |
| 思考原則 2（今必要なものだけ） | 列 0 本 / 記録経路 0 本 / 新規 gate 0 本（既存 RC-8 を使う）/ 絞り込み UI 無し |
| 思考原則 3（並走を残さない） | 索引は追加ではなく**置き換え** |
| 思考原則 4（別概念を統合しない） | 最終ログイン ≠ recent-auth（remember me の扱いを意図的に逆にする）/ ≠ ModelAudit / ≠ 最終活動 / ≠ API キーの last_used_at |
| セキュリティ不変条件 3（cross-org） | current-org 解決のみ・org relation 由来の id 集合のみ・テスト 5 で behavioral に固定 |
| セキュリティ不変条件 9（変更系の認可） | 変更系 route を足さないため母集団不変 |
| DESIGN.md 準拠 | 既存 DS token（`text-caption` / `text-text-secondary`）のみ。hex 直書き無し・token 変更無し |
| Atomic Design 準拠 | 新規 component 無し。`pages` から `lib` の import は既存流儀と同一。アイコン追加無し |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 A〜G が**1 つの意味単位**で動く。`MemberRowData` のシグネチャ変更（B）は TS 型（C）と Vitest fixture（G）を**同時に**直さないと `pnpm typecheck` が落ち、`LastLoginLookup`（A）が無いと B がコンパイルできず、Factory（E）が無いと Feature テスト（G）が書けない。分割すると必ず赤いままの中間状態が生まれる。一方で他ドメイン（撮影 / シナリオ / 課金）へは 1 行も触れないため、独立したブランチで完結する |
| 競合リスク | **低い**。`Admin/Users.svelte` / `MemberRowData` / `UserManagementController` を同時に触る他タスクが無ければ衝突しない。`security_audit_events` の migration を触る他タスクがある場合のみ migration ファイル名（timestamp）の調整が要る。`RetentionTableRegistry` は根拠文 1 か所のみの変更で、区分・件数を動かさないため他タスクの台帳変更と行単位で衝突しにくい |
| 実装順序 | G-1/G-2 のテストを書いて fail を確認 → E（Factory）→ A（Lookup）→ B（DTO/Controller）→ C（TS/Svelte）→ G-3（Vitest）→ D（索引）→ F（台帳根拠文）→ 全検証コマンド |
