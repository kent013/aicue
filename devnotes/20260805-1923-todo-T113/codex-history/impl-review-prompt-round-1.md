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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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

---

# system: あなたの役割

Laravel + Svelte アプリの実装レビュアーとして、以下の改善実装をレビューする。

本 TODO (T113) は **施策 B1 (bug-hunt インベントリ drift 解消 + CI 配線) と施策 B2
(ドキュメント乖離 7 件の是正 + 同期ゲート 2 本) のみ**が対象である。
同一設計書に含まれる施策 A1/A2 (PCRE `\R` + pgid race)、C1/C2 (git index 正規化 + 孤児 DB 回収)、
D1 (advisory upgrade) は**別 TODO の担当で、本 diff の対象外**。これらが実装されていないことを
指摘しないこと。

## レビュー観点

1. **設計との一致性**: 詳細設計 (施策 B1/B2) の指定どおりか。逸脱があるなら妥当か
2. **正確性**: 追記した route / middleware / 認可契約の記述が実装と矛盾しないか
3. **PHPStan level 10 適合性** (新規 PHP テスト)
4. **テスト網羅性**: 新設ゲート 2 本が「形骸化しない」こと。空振り防止 (下限ガード)・
   正コントロール・負コントロールが揃っているか。**偽グリーンの余地**があれば Critical
5. **セキュリティ**: bug-hunt に追記した認可契約 (IDOR / 存在オラクル / 詰み) の記述が
   検出力を持つか。誤った契約を書いていないか
6. **本アプリのドメインコードは 1 行も変更していない** (Markdown / YAML / テスト のみ)。
   DESIGN.md / Atomic Design への波及は構造的に存在しない

## 出力形式

- ファイルごとに判定
- 指摘は [Critical] / [Warning] / [Suggestion] に分類
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示

---

# user

## 詳細設計書 (前文 + 施策 B1/B2 の該当部分)

# 詳細設計: audit-followup-maintenance (サイクル 2 監査の残り是正)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- `declare(strict_types=1)` + 日本語コメント
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

> **本バッチ固有の注意**: 本バッチは**アプリのドメインコードを 1 行も変更しない**。
> 変更対象は Architecture テスト / 運用スクリプト / 台帳・規約ドキュメント / lockfile に限られる。
> したがって DTO / JsonResource / Inertia Props / Svelte / DESIGN.md / Atomic Design への
> 波及は**構造的に存在しない**（各施策の「波及変更」欄で個別に確認する）。

## 概念設計リファレンス

- [devnotes/20260805-1813-audit-followup-maintenance/conceptual-design.md](conceptual-design.md)（Codex 合議 Round 5 で **APPROVED**）
- 監査の出典: `devnotes/20260805-1600-audit-cycle-2/`（`audit-report.md` / `tech-debt.md` / `docs-freshness.md`）
- 削除対象 manifest: [nfd-index-entries.txt](nfd-index-entries.txt)（58 行）

### 概念設計 Round 5 の承認条件（本詳細設計が満たすべきもの）

| # | 承認条件 | 本書での対応 |
|---|---|---|
| 1 | 分類優先順位を詳細設計で明記し、テストで固定する | 施策 C2 §分類優先順位（`Protected → Live → Foreign → Orphan → Unlabeled`）+ テスト計画 T-C2-4 |
| 2 | C4 の `--apply` は人間の明示指示なしでは実行しない | 施策 C2 §apply の運用契約 + `AGENTS.md` への明記 + 実装 TODO の受入条件 |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 | グループ |
|---|--------|------------|--------|---|
| A1 | `preg_split('/\R/')` の `/u` 是正 + PCRE `\R` ゲート新設 | `tests/Architecture/GlobalTestLockInventoryTest.php` / `tests/Architecture/BughuntOrchestratorGateInvariantTest.php` / **新規** `tests/Architecture/PcreUnicodeModifierGateTest.php` | High | A |
| A2 | `global-test-lock.sh` の pgid 取得 race 修正 + 回避策撤去 | `scripts/global-test-lock.sh` / `scripts/verify-global-test-lock.sh` / `scripts/run-browser-test.contract.test.ts` | High | A |
| B1 | bug-hunt インベントリ drift 解消 + CI 配線 | `.claude/skills/app-bug-hunt/{screens,operations}.md` / `stories/{S1,S6}*.md` / `.github/workflows/ci.yml` / `tests/js/architecture/ci-workflow-inventory.test.ts` | High | B |
| B2 | ドキュメント乖離 7 件の是正 + 同期ゲート 2 本 | `AGENTS.md` / `README.md` / `.env.example` / `docs/architecture.md` / `docs/worktree-isolation-strategy.md` / `.claude/skills/app-implement/SKILL.md` / **新規** `tests/Architecture/RouteBindingCustomBinderDocSyncTest.php` / **新規** `tests/js/architecture/verification-commands-doc-sync.test.ts` | Medium | B |
| C1 | `doc/reference/` の NFC/NFD 重複解消 + 再発防止ゲート | git index（`doc/reference/` の NFD entry 58 件）/ `docs/worktree-isolation-strategy.md` / **新規** `tests/Architecture/GitIndexNormalizationTest.php` | Medium | C |
| C2 | 孤児テスト DB の回収経路（provenance + 三重 guard + confirm token） | `scripts/ci/ensure-test-db.php` / `scripts/ci/drop-test-db.php` / `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/*`（新規 3 型）/ `scripts/teardown-worktree.sh` / `scripts/README.md` / `AGENTS.md` / **新規** `tests/Unit/Ci/TestDatabaseClassificationTest.php` | Medium | C |
| D1 | 未受容 advisory 4 件の upgrade | `packages/cli/package.json` / `package.json` / `pnpm-lock.yaml` | Medium | D |

---



# 施策 B1: bug-hunt インベントリ drift 解消 + CI 配線

### 変更箇所

- `.claude/skills/app-bug-hunt/screens.md`（3 route 追記 + 説明節）
- `.claude/skills/app-bug-hunt/operations.md`（5 route 追記 + パスキー認可契約の節）
- `.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md`（パスキーログイン手順）
- `.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md`（パスキー登録/削除・初回パスワード設定）
- `.github/workflows/ci.yml`（php job に drift 検知 step を追加）
- `tests/js/architecture/ci-workflow-inventory.test.ts`（**W16** を追加して step を pin）

### 実測（現状の drift）

`bash scripts/bug-hunt-inventory-check.sh` → **exit 3**、未追記は **8 route**
（課題文の 4 本に加え、`passkey.confirm` と options 系 3 本）:

```
== screens (GET×inertia) ==  passkey.confirm-options / passkey.login-options / passkey.registration-options
== operations (非GET×web) ==  passkey.confirm / passkey.destroy / passkey.login / passkey.store / settings.password.store
```

route の実体（`php artisan route:list --json` 実測）:

| method | uri | name | middleware（要点） |
|---|---|---|---|
| GET | `passkeys/confirm/options` | `passkey.confirm-options` | auth + `throttle:passkeys` |
| POST | `passkeys/confirm` | `passkey.confirm` | auth + `throttle:passkeys` |
| GET | `passkeys/login/options` | `passkey.login-options` | guest + `throttle:passkeys` + `NoStore...` |
| POST | `passkeys/login` | `passkey.login` | guest + `throttle:passkeys` |
| GET | `user/passkeys/options` | `passkey.registration-options` | auth + `throttle:passkeys` + `RequireRecentAuth` |
| POST | `user/passkeys` | `passkey.store` | auth + `throttle:passkeys` + `RequireRecentAuth` |
| DELETE | `user/passkeys/{passkey}` | `passkey.destroy` | auth + `throttle:passkeys` + `RequireRecentAuth` + `ensure-login-method` |
| POST | `settings/password` | `settings.password.store` | auth + `throttle:6,1` + verified |

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/js/architecture/ci-workflow-inventory.test.ts`（W16 追加）

### 変更後コード（screens.md）

既存の 3 列書式 `| route (URL) | name | 割当ストーリー |` に合わせて追記する
（アルファベット順の既存並びを維持し、`pricing` の直前に挿入）:

```markdown
| passkeys/confirm/options | passkey.confirm-options | S6 |
| passkeys/login/options | passkey.login-options | S1 |
| user/passkeys/options | passkey.registration-options | S6 |
```

`screens.md` は実体として「GET × web」の表であり、既に `capture.csrf-cookie` / `session.status`
という**非 Inertia の GET** を載せている。ただし現在の見出し（`## 画面一覧`）と
チェックスクリプトのラベル（`screens (GET×inertia)`）は実態とずれているため、
**見出しと冒頭説明を実態に合わせる**（列は増やさない。`coverage/correlate.py` が解析するのは
`operations.md` の 5 列だけで `screens.md` は解析しないので列追加自体は可能だが、
既存 55 行の書き換えを避け、注記で区別する）:

```diff
-## 画面一覧
+## GET × web 一覧 (画面 + 画面に付随する JSON GET)
+
+> 本表は「GET × web セッション面」の一覧であり、**Inertia 画面だけではない**。
+> 以下は画面ではなく**画面に付随する JSON GET**として載せている
+> (bug-hunt は単独で開かず、対応する画面操作の副作用として通過させる):
+> `capture.csrf-cookie` / `session.status` / `passkey.registration-options` /
+> `passkey.login-options` / `passkey.confirm-options`
```

そのうえで、誤解を防ぐ節を追加する:

```markdown
## パスキー options endpoint の扱い (要検出)

`passkey.*-options` の 3 本は**画面ではなく WebAuthn の challenge を返す JSON GET**
(`capture.csrf-cookie` / `session.status` と同じ扱いで表に載せている)。
bug-hunt はこれらを**単独で開くのではなく**、S1/S6 のパスキー操作を UI から実走した
副作用として通過させる。加えて逸脱アイデアとして直叩きを行う:

- `passkey.registration-options` / `passkey.confirm-options` は `RequireRecentAuth` /
  auth の配下。**未ログイン・再認証切れで直叩きしたときに 401/302 で止まり、
  challenge が漏れない**こと。
- `passkey.login-options` は guest 配下。**メールアドレスを列挙できる応答差
  (存在するユーザーと存在しないユーザーで応答が変わる)** が出ないこと (存在オラクル)。
- 3 本とも `throttle:passkeys` 配下。連打時の 429 が**画面上で説明される**こと
  (無反応で詰まないこと。H4)。
```

### 変更後コード（operations.md）

既存の 5 列書式 `| method | route | name | story | 区分 |` に合わせて追記する:

```markdown
| POST | passkeys/confirm | passkey.confirm | S6 | 通常 |
| DELETE | user/passkeys/{passkey} | passkey.destroy | S6 | 通常 |
| POST | passkeys/login | passkey.login | S1 | 通常 |
| POST | user/passkeys | passkey.store | S6 | 通常 |
| POST | settings/password | settings.password.store | S6 | 通常 |
```

加えて、既存の「課金ゲート allowlist と認可」節と同じ粒度で**パスキーの認可契約**を書く
（bug-hunt が「何を破れば finding か」を判断できるようにするため）:

```markdown
## パスキー / ログイン手段の認可・guard 契約 (P106/P107 後、要検出)

正本は `docs/auth-security-mechanisms.md` §5・§6。**認証系は IDOR・詰みが最も出やすい面**
なので、以下の 4 つは必ず破壊を試みる。

- **他人の passkey は 404** (`{passkey}` は `SelfScopedPasskeyBinder` が
  「認証ユーザー所有 + 数値正規化」を担う explicit binder。403 で存在を漏らさない
  = セキュリティ不変条件 2 の実装点)。**他組織・他ユーザーの passkey id を
  `passkey.destroy` に流し込んで 404 以外が返れば finding (Critical)**。
- **唯一のログイン手段は消せない** (`ensure-login-method` middleware)。
  パスキーだけのユーザーが唯一の passkey を削除しようとしたとき、
  **403 で突き放さず「先に別の手段を登録してください」と行き先が示される**こと
  (行き先のない詰みを作らない = H4)。
- **登録・削除は再認証の後ろ** (`RequireRecentAuth`)。再認証が切れた状態で直 POST して
  通ったら finding。再認証を求められたとき、**パスキーしか持たないユーザーが
  `recent-auth.confirm` で詰まない**こと (T107 の `passkeyAvailable` 配線が効いているか)。
- **`throttle:passkeys` / `settings.password.store` の `throttle:6,1`**。
  連打で 429 になったとき**画面上で説明される**こと (無反応にしない)。

`settings.password.store` は **SSO / パスキーのみで登録したユーザーがパスワードを
初めて設定する経路** (T107 で新設)。既存の `user-password.update` (現行パスワード必須) とは
別物なので、**現行パスワードを持たないユーザーが到達できること**、および
**既にパスワードを持つユーザーがこの経路で現行パスワード検証を迂回できないこと**の
両方を見る。
```

### ユーザーストーリーの追加

**S6（`stories/S6-security-2fa-profile.md`）** — 手順 3 と 4 の間に挿入:

```markdown
3-b. **パスキーの登録 → 削除 (T106/T107)**:
   `settings.security` → 「パスキーを追加」→ 再認証 (`RequireRecentAuth`) を求められる →
   `recent-auth.confirm` で通過 → `passkey.registration-options` で challenge 取得 →
   `passkey.store` で登録完了。一覧に登録済みパスキーが出るか。
   - **詰み検証**: 登録直後に**パスワードを削除できない / 唯一の手段を消せない**ことを
     `passkey.destroy` で試す。`ensure-login-method` に弾かれたとき、
     **「先に別のログイン手段を登録してください」という行き先付きの説明**が出るか
     (403 の素っ気ないエラーで終わったら finding = H4)。
   - **IDOR 検証**: 他ユーザーの passkey id を `passkey.destroy` に流し込む →
     **必ず 404** (403 だと「その id は存在する」と漏れる)。
   - 削除成功後、一覧から消えてトーストが 1 つだけ出るか (T026)。

3-c. **パスワード未設定ユーザーの初回パスワード設定 (`settings.password.store`, T107)**:
   SSO / パスキーのみで登録したユーザーで `settings.security` を開く →
   「パスワードを設定」導線が**存在し、押下できる**こと (必須条件未充足で
   disabled にしていないこと = 禁止事項 8)。現行パスワード欄が**要求されない**こと。
   設定後に `login` からパスワードでログインできること。
   - **逸脱**: 既にパスワードを持つユーザーが `settings.password.store` を直 POST して
     現行パスワード検証を迂回できないか (できたら finding = Critical)。
```

「このストーリーで消化する screens / operations」の行にも追記する:

```
- screens: ..., passkey.registration-options, passkey.confirm-options
- operations: ..., passkey.store, passkey.destroy, passkey.confirm, settings.password.store
```

**S1（`stories/S1-guest-registration-funnel.md`）** — 手順 4 の直後に挿入:

```markdown
4-b. **パスキーでのログイン (T106)**:
   S6 でパスキーを登録したユーザーでログアウト → `login` 画面に
   **「パスキーでログイン」導線が出ている**こと → `passkey.login-options` で challenge 取得 →
   `passkey.login` で `dashboard` へ到達できること。
   - **存在オラクル検証**: 存在しないメールアドレスで `passkey.login-options` を叩いたときの
     応答が、存在するユーザーのときと**区別できない**こと (区別できたら finding = High)。
   - **詰み検証**: パスキー非対応ブラウザ / WebAuthn が利用不可の環境で
     「パスキーでログイン」を押したとき、**説明が出て通常ログインに戻れる**こと
     (無反応・白画面なら finding = H4)。
```

同じく消化行に `passkey.login-options` / `passkey.login` を追記する。

### CI 配線（再発防止）

`.github/workflows/ci.yml` の `php` job、`Prepare environment`（`.env` 生成 + `key:generate`）の
**直後**に追加する（`route:list` は APP_KEY を要するが DB は不要なので、Pest より前で fail-fast できる）:

```yaml
      # bug-hunt インベントリ (screens.md / operations.md) と route:list のドリフト検知。
      # T106 (passkey 7 route) / T107 (settings.password.store) で 2 サイクル連続してドリフトし、
      # 「認証系が bug-hunt のカバレッジから丸ごと落ちる」という実害が出た。台帳が正本である以上
      # soft-fail にしない (exit 3 で job を落とす)。判定ロジックは既存スクリプトのままで、
      # PHP 側に再実装しない (自前解析器の重複を増やさない = tech-debt.md §4.4)。
      - name: Bug-hunt inventory drift
        run: bash scripts/bug-hunt-inventory-check.sh
```

`tests/js/architecture/ci-workflow-inventory.test.ts` に **W16** を追加（既存 W1〜W15 の作法に従う）:

```typescript
it("W16: php が bug-hunt インベントリの drift 検知を **実行行として** 持つこと", () => {
    const workflow = loadWorkflow();
    // runScript ではなく runLines を使う: runScript はコメント行も連結するため
    // 「# bug-hunt-inventory-check.sh は将来入れる」というコメントで green になる
    // (既存 W14b/W14c と同じ「実行行だけを見る」方針)。
    const lines = runLines(job(workflow, "php"));
    expect(lines.some((l) => l.includes("scripts/bug-hunt-inventory-check.sh"))).toBe(true);
});
```

`continue-on-error` の不在は既存 W13 が workflow 全体で deny-by-default 済み。

### PHPStan 適合チェック

対象外（Markdown / YAML / TypeScript のみ）。TypeScript は `pnpm typecheck` / `pnpm test` で確認。

### テスト計画

- [x] バグ修正の再現テスト: **W16 を先に追加して現行 ci.yml で赤くなることを確認**してから CI step を足す
- [x] 受入条件: `bash scripts/bug-hunt-inventory-check.sh` → **exit 0**（`echo $?` で直接確認する。パイプすると終了コードが隠れるので注意）
- [x] 既存テストの更新: `ci-workflow-inventory.test.ts`（W1 の job 集合は変更なし）
- [x] `.claude/skills/app-bug-hunt/coverage/correlate.py` が operations.md の**5 列 leading-pipe 書式**に依存しているため、追記行が書式に従っていることを `python3 -m unittest`（coverage/ledger の stdlib テスト）で確認する
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| CI に blocking step を足すことで、route を足すたびに CI が赤くなり開発が止まる | それが deny-by-default の目的（`ScriptsReadmeInventoryTest` の先例と同じ）。逃げ道は既存の `OUT_OF_SCOPE_PREFIXES` で、**理由をコメントに書いて**追加する運用（スクリプト L23-29 に既に規約がある） |
| `php artisan route:list` が CI で失敗する（env 不足） | `Prepare environment` の後ろに置くことで `.env` + `APP_KEY` + passport 鍵が揃った状態にする。DB は不要 |
| options endpoint を screens.md に載せるのは「画面」の定義と食い違う | 既に `capture.csrf-cookie` / `session.status` が同じ扱いで載っている（先例に従う）。誤解防止の節を screens.md に明記する |
| correlate.py の 5 列書式を崩す | 既存行をコピーして値だけ差し替える。stdlib テストで検証 |

---

# 施策 B2: ドキュメント乖離 7 件の是正 + 同期ゲート 2 本

### 変更箇所

| # | 対象 | 内容 |
|---|---|---|
| D1 | `AGENTS.md` §セキュリティ不変条件（L41-57） | T103 の不変条件 2 本を **9/10 として追記** + 番号非対応の注記 |
| D2 | `AGENTS.md` §セキュリティ不変条件（末尾） | **`TRUSTED_PROXIES`（T108）への導線**を 1 行追加 |
| D3 | `README.md` ドキュメント表 | `docs/trusted-proxies-runbook.md` / `docs/auth-security-mechanisms.md` を追加 |
| D4 | `.env.example` L194 | 参照先を `docs/auth-security-mechanisms.md §5` へ訂正 |
| D5 | `docs/architecture.md` L83-85 | `CUSTOM_BINDER` 列挙に `{passkey}` + 同期マーカー |
| D6 | `AGENTS.md` L77-79 / `.claude/skills/app-implement/SKILL.md` L158 | 検証コマンド列に packages 系を補完 |
| D7 | `AGENTS.md`（worktree 節 / 実装規約） | **グローバルテストロックの周知**（待つ・heartbeat・kill 禁止）+ 「4 軸」→ 2 層構造 |
| G1 | **新規** `tests/Architecture/RouteBindingCustomBinderDocSyncTest.php` | D5 の機械強制（双方向） |
| G2 | **新規** `tests/js/architecture/verification-commands-doc-sync.test.ts` | D6 の機械強制（deny-by-default） |

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規ゲート 2 本

### D1: AGENTS.md §セキュリティ不変条件

**現状**: AGENTS.md は 1〜8（8 = SSRF 検査経由）。`docs/app-integration-guide.md` §7 は
T103 で **10 項目に拡張**され、8 = 変更系 route の認可 gate / 9 = 層 2 は binding 直後 /
10 = テストなしの実装完了はない、になっている。**AGENTS.md に新設 2 本が無い**。

**採番の扱い（重要）**: AGENTS.md と guide §7 の番号は**元々 1:1 でない**
（AGENTS #6 = PII CipherSweet / guide #6 = 逆シリアライズ）。
`docs/app-integration-guide.md:71` が「§7 不変条件 8」と guide の番号で参照しており、
`database/migrations/2026_06_11_091300_create_stripe_webhook_events_table.php:12` が
「不変条件 7」を参照している（7 は両者一致）。
**AGENTS.md 側を renumber すると既存参照が壊れる**ため、**1〜8 は据え置いて 9/10 を追記**し、
番号が 1:1 でないことを明記する（`grep -rn "不変条件 [0-9]"` で live 参照が上記 2 件だけであることを確認済み）。

既存 1〜8 は 2〜3 行で言い切り、詳細は guide §7 へ委ねる体裁になっている。
**9/10 も同じ密度に揃える**（middleware 名・順序契約の詳細は guide §7 が正本。
AGENTS.md を runbook 化しない）:

```markdown
9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE は `Gate::authorize` を通すか、
   exemption inventory へ理由付きで登録する(deny-by-default)。
   **層 2(テナント境界 = 404)は層 3(認可 = 403)より前**(逆にすると存在が漏れる)
   (`ControllerAuthorizationGateTest`)
10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
> (本節 6 = PII / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
> 相互参照するときは**番号ではなく項目名**で指すこと。
```

### D2/D3: TRUSTED_PROXIES（T108）への導線

**現状の実測**: `docs/trusted-proxies-runbook.md` は実在し内容も十分。参照元は
`bootstrap/app.php:78` / `app/Support/TrustedProxiesConfigValidator.php` / `config/trustedproxy.php` /
`tests/Architecture/TrustedProxiesRunbookTest.php` / `docs/auth-security-mechanisms.md:212,408`。
**しかし `AGENTS.md` からも `README.md` のドキュメント表からも辿れない**
（`README.md` の表は 6 行しかなく、T108 で増えた runbook を含んでいない）。

AGENTS.md §セキュリティ不変条件の末尾（採番注記の後）に運用要件として 1 行:

```markdown
> **運用要件 (T108)**: production は `TRUSTED_PROXIES` の**明示宣言が必須**
> (未宣言 / `*` / `REMOTE_ADDR` / 書式不正は `ProductionEnvGuard` が起動時 fail-fast)。
> `trustProxies(at:'*')` はレート制限を総当りに無効化するため復活させない。
> 実 hop 一覧・CIDR 管理主体・変更手順は `docs/trusted-proxies-runbook.md` が正本。
```

`README.md` のドキュメント表に 2 行追加:

```markdown
| `docs/auth-security-mechanisms.md` | 認証・セッション・パスキー・SSO・信頼境界の仕組みと不変条件 |
| `docs/trusted-proxies-runbook.md` | client IP の信頼境界(`TRUSTED_PROXIES`)の運用契約 |
```

### D4: `.env.example` の dangling 参照

```diff
-#    運用契約は docs/architecture.md §パスキー (WebAuthn)。
+#    運用契約は docs/auth-security-mechanisms.md §5 パスキー (WebAuthn) の「運用上の注意」。
```

（`docs/architecture.md` に「§パスキー (WebAuthn)」というセクションは存在しない。
内容自体は `docs/auth-security-mechanisms.md` に正確に書かれている = リンクだけが誤り）

### D5 + G1: `docs/architecture.md` の CUSTOM_BINDER 列挙と同期ゲート

**現状**: `docs/architecture.md:83-85` は `CUSTOM_BINDER` を `{organization}` の 1 件しか挙げていない。
実装（`app/Http/Routing/RouteBindingTypes.php:134-140`）は **`organization` + `passkey`** の 2 件。
`{passkey}` は「他人の passkey を 404 に倒す」= セキュリティ不変条件 2 の実装点なので、
inventory 表現の陳腐化として無視できない。

**doc 側の変更（同期マーカーで囲む）**:

```markdown
- **5 分類 (deny-by-default)**: `BIGINT` / `UUID` (param => モデルの map。pattern 適用) /
  `CUSTOM_BINDER` / `NON_MODEL` / `EXTERNAL` (vendor route が持ち込む param を
  route identity ごとに登録)。
  `CUSTOM_BINDER` の現在の登録は以下 (`RouteBindingCustomBinderDocSyncTest` が
  `RouteBindingTypes::CUSTOM_BINDER` と双方向で同期を強制する):
  <!-- CUSTOM_BINDER:BEGIN -->
  - `{organization}` — `MembershipScopedOrganizationBinder`。`{organization:slug}` 併用のため
    pattern を適用せず、binder が入力正規化を担う
  - `{passkey}` — `SelfScopedPasskeyBinder`。Fortify (vendor) が登録する route の param で、
    app 側から `Route::pattern` を掛けると vendor の route 定義変更に追随できないため、
    binder が「認証ユーザー所有 + 数値正規化」を担う (**他人の passkey は 404** =
    セキュリティ不変条件 2 の実装点)
  <!-- CUSTOM_BINDER:END -->
```

マーカー方式にする理由: 散文全体を grep すると、別の文脈で `{passkey}` に言及しただけで
green になる（形骸化）。**囲まれた範囲だけを解析入力にする**ことで双方向検査が成立する
（`ci-workflow-inventory.test.ts` が「YAML を parse してから歩く」のと同じ考え方）。

**ゲート G1 の設計**:

```php
<?php

declare(strict_types=1);

use App\Http\Routing\RouteBindingTypes;
use Webmozart\Assert\Assert;

/*
 * `RouteBindingTypes::CUSTOM_BINDER` と docs/architecture.md の列挙を **双方向** で同期する。
 *
 * なぜ必要か: T106 が `{passkey}` を CUSTOM_BINDER へ足したとき、docs/architecture.md の
 * 「単一 SoT の全 binding param 型 inventory」を説明する節が 1 件のままドリフトした
 * (docs-freshness.md §2-2)。`{passkey}` は「他人の passkey を 404 に倒す」=
 * セキュリティ不変条件 2 の実装点であり、inventory から落ちている影響は小さくない。
 *
 * 解析対象は <!-- CUSTOM_BINDER:BEGIN --> 〜 <!-- CUSTOM_BINDER:END --> の範囲のみ。
 * 文書全体を grep すると別文脈の言及で green になり形骸化する。
 */

/** 同期マーカー。doc 側を書き換えるときは両方を維持すること。 */
const CUSTOM_BINDER_DOC_BEGIN = '<!-- CUSTOM_BINDER:BEGIN -->';
const CUSTOM_BINDER_DOC_END = '<!-- CUSTOM_BINDER:END -->';

/**
 * マーカー間から `{param}` トークンを抽出する (純関数)。
 *
 * @return list<string> 出現順・重複除去済み
 */
function customBinderDocParams(string $markdown): array { /* ... */ }
```

| ID | 内容 | 期待 |
|---|---|---|
| CB1 | マーカーが `docs/architecture.md` に**ちょうど 1 組**存在する | pass |
| CB2 | **forward**: `CUSTOM_BINDER` の全 key が doc の `{key}` として現れる | pass |
| CB3 | **reverse（stale 検出）**: doc の `{param}` が全て `CUSTOM_BINDER` の key である | pass |
| CB4 | **空振り防止**: 抽出した param 数が 1 件以上 かつ `CUSTOM_BINDER` の件数と一致 | pass |
| CB5 | 正コントロール: key を 1 つ増やした合成入力で forward 違反を検出 | **検出** |
| CB6 | 正コントロール: doc に存在しない `{ghost}` を足した合成入力で reverse 違反を検出 | **検出** |
| CB7 | 負コントロール: マーカー外に `{ghost}` があっても違反にならない | 0 件 |

### D6 + G2: 検証コマンド列の同期

**現状の実測**:
- `AGENTS.md:77-79` は 9 本（`composer test` / `composer phpstan` / `pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm test:packages`）で **`pnpm build:packages` が欠落**
- `.claude/skills/app-implement/SKILL.md:158` は
  `vendor/bin/pint --test && pnpm lint && pnpm typecheck && pnpm test && pnpm build` のままで
  **packages 系 3 本を 1 つも含まない**
- CI（`.github/workflows/ci.yml` frontend job）は `typecheck:packages` → `build:packages` →
  `test:packages` → `build` を実行している = **規約と CI が不一致**

**doc 側の変更（G1 と同じマーカー方式で範囲を限定する）**:

```diff
+<!-- VERIFICATION_COMMANDS:BEGIN -->
 - 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
-  `pnpm typecheck:packages` / `pnpm test:packages`(全 green でコミット)
+  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
+  (全 green でコミット。`verification-commands-doc-sync.test.ts` が
+  package.json の検証系 script との同期を deny-by-default で強制する)
+<!-- VERIFICATION_COMMANDS:END -->
```

`app-implement/SKILL.md:158` の品質チェック行（同じマーカーで囲む）:

```diff
+<!-- VERIFICATION_COMMANDS:BEGIN -->
    cd {repo_root}/.claude/worktrees/tasks/{todo_id} && vendor/bin/pint --test && pnpm lint && pnpm typecheck && pnpm test && pnpm build
+   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && pnpm typecheck:packages && pnpm build:packages && pnpm test:packages
+<!-- VERIFICATION_COMMANDS:END -->
```

マーカー方式にする理由（G1 と同じ）: 文書全体を検索すると、
**別の文脈で `pnpm build` に言及しただけで green になる**（形骸化）。
新規ゲートを最初から緩く作る理由がない。

**ゲート G2 の設計**（`ScriptsReadmeInventoryTest` と同じ「exempt は理由付き + 逆方向 stale 検出」の作法）:

```typescript
/**
 * package.json の「検証系 script」が AGENTS.md と app-implement/SKILL.md の
 * 検証コマンド列に載っていることを deny-by-default で強制する。
 *
 * なぜ必要か: T104 で CI frontend job が build:packages を回すようになったが、
 * AGENTS.md への追記が漏れ、app-implement/SKILL.md に至っては packages 系 3 本が
 * 1 つも無い状態が続いた (docs-freshness.md §2-3)。手順どおり実装した worktree は
 * packages のビルド破壊をローカルで検出できず CI で初めて赤くなる。
 *
 * 免除は「理由付き」でしか書けない。免除エントリが package.json から消えたら
 * 逆方向検査が落ちる (stale 免除の残置を許さない)。
 *
 * 照合範囲は <!-- VERIFICATION_COMMANDS:BEGIN --> 〜 END の内側のみ。文書全体を検索すると
 * 別文脈の言及で green になり形骸化する (RouteBindingCustomBinderDocSyncTest と同じ方式)。
 */
const MARKER_BEGIN = "<!-- VERIFICATION_COMMANDS:BEGIN -->";
const MARKER_END = "<!-- VERIFICATION_COMMANDS:END -->";

/** 照合対象ファイル (どちらにも同じ script 集合が載っている必要がある)。 */
const TARGETS = ["AGENTS.md", ".claude/skills/app-implement/SKILL.md"];

const EXEMPT: Record<string, string> = {
    dev: "開発サーバ起動。検証コマンドではない",
    "lint:fix": "lint の自動修正。検証は lint 側が担う",
    "test:ui": "vitest UI の対話起動。CI/検証で回すものではない",
    "test:watch": "watch 実行。単発検証ではない",
    "test:coverage": "カバレッジ計測。検証ゲートではない (test が正本)",
    "audit:gate": "supply-chain gate は CI/nightly の blocking 実行が正本 (AGENTS.md §依存脆弱性に別記)",
};
```

| ID | 内容 | 期待 |
|---|---|---|
| V0 | `TARGETS` の各ファイルに `MARKER_BEGIN` / `MARKER_END` が**ちょうど 1 組ずつ**存在する | pass |
| V1 | 非免除 script が **AGENTS.md のマーカー範囲内**に全て現れる | pass |
| V2 | 非免除 script が **app-implement/SKILL.md のマーカー範囲内**に全て現れる | pass |
| V3 | **逆方向**: `EXEMPT` の全 key が package.json の scripts に実在する（stale 免除の検出） | pass |
| V4 | **空振り防止**: 非免除 script 数が **7 件以上**（現状 `build` / `lint` / `typecheck` / `test` / `build:packages` / `typecheck:packages` / `test:packages`） | pass |
| V5 | 免除理由が空文字・10 文字未満でない | pass |
| V6 | 正コントロール: 合成 package.json に未記載 script を足すと違反を検出 | **検出** |
| V7 | 負コントロール: **マーカー範囲外**に `pnpm ghost:script` があっても照合対象にならない | 0 件 |

**照合方法**: script 名の単純な部分一致は `test` が `test:packages` に含まれて誤 green になる。
**`pnpm <name>` / `pnpm run <name>` というトークン境界付きの正規表現**で照合する
（`new RegExp("pnpm (run )?" + escapeRegExp(name) + "(?![:\\w-])")`）。

### D7: グローバルテストロックの周知 + 「4 軸」の更新

**現状**: `docs/testing-browser.md:188-206` の runbook は内容として完璧だが、
**`AGENTS.md` はロックに一言も触れていない**（grep で 0 件）。
`AGENTS.md:77-79` の検証コマンドを素直に実行したエージェントは
「数分無反応 → ハングと誤認 → 中断 / kill」に倒れうる。ロックは全レーン共通
（`composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages` / `pnpm test:coverage`）
なのに、周知先がブラウザ専用ドキュメントに閉じている。

AGENTS.md §worktree 運用ルールの末尾に追加:

```markdown
- **テストレーンのグローバルロック (T099)**: `composer test` / `composer test:browser` /
  `pnpm test` / `pnpm test:packages` / `pnpm test:coverage` は**ホスト全体で 1 本ずつ**しか
  走らない (worktree 横断で直列化。テスト DB とポートの衝突を構造的に防ぐ)。
  - **待ち時間が出るのは正常**。他レーンが走っていると**エラーにはならず待つ**。
    待機中は **30 秒ごとに heartbeat** が stderr に出るので、出ている間はそのまま待つ
  - **kill しない / ロックファイルを消さない**。中断が必要なら
    **ロック保持者の pid に `kill -TERM`** を送る (プロセスグループが空になるまで解放されない)。
    ロックファイルの手動削除は二重実行を生む
  - 手動復旧の runbook は `docs/testing-browser.md` §グローバルテストロックの手動復旧
```

同時に `AGENTS.md:154` の要約を更新する:

```diff
-- **背景と障害対応**: 分離設計 (vendor / node_modules / テスト DB / 実行時ファイルの 4 軸) の
-  意図は `docs/worktree-isolation-strategy.md`
+- **背景と障害対応**: 分離設計は「**リソース名前空間** (vendor / node_modules / テスト DB /
+  実行時ファイル) と **実行そのもの** (グローバルテストロック)」の 2 層構造。意図は
+  `docs/worktree-isolation-strategy.md`
```

（`docs/worktree-isolation-strategy.md:36-48` は既に 2 層構造へ拡張済みで、AGENTS.md の要約が 1 段古い）

### PHPStan 適合チェック（G1）

- [x] 戻り値の型が明示されている（`list<string>`）
- [x] null 安全（`file_get_contents` の `false` を `Assert::string()` で潰す。`preg_match_all` の戻りも検証）
- [x] DTO を返している（Architecture テストの純関数なので `list<string>` で表現）
- [x] Generics の型パラメータが正しい（`RouteBindingTypes::CUSTOM_BINDER` は `array<string, class-string>`）

### テスト計画

- [x] バグ修正の再現テスト: **G1 の CB2 / G2 の V1・V2 を先に書き、現行の doc で赤くなることを確認**してから doc を直す（テストファースト）
- [x] 新規テスト: `RouteBindingCustomBinderDocSyncTest`（CB1〜CB7）/ `verification-commands-doc-sync.test.ts`（V0〜V7）
- [x] 既存テストの更新: なし（AGENTS.md / README.md / .env.example の変更を検査する既存ゲートは無い）
- [x] `docs/` 変更後に `composer test` / `pnpm test` 全緑
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| AGENTS.md に不変条件を 2 本足すことで、`app-codex-review` スキルが挿入するプロンプトが長くなる | 使命・禁止事項セクションのみを挿入する仕様なので、§セキュリティ不変条件の追記はプロンプト長に影響しない |
| G2 の照合が「AGENTS.md のどこかに `pnpm build` があれば通る」ため形骸化しうる | **マーカー範囲に限定**する（V0 / V7）。加えて V4 の下限ガードと V6 の正コントロールで検出力を担保する |
| doc の同期マーカーが将来の doc 整形で消える | CB1 が「マーカーがちょうど 1 組存在する」ことを検査するので、消えたら即赤くなる |
| 「不変条件 9/10」の追記で番号が guide とずれたままになる | 採番の注記を明記し、相互参照は項目名で行う規約にする（renumber は既存参照 2 件を壊すため選ばない） |

---



## 実装差分 (git diff)

```diff
diff --git a/.claude/skills/app-bug-hunt/operations.md b/.claude/skills/app-bug-hunt/operations.md
index adf6adf..94cd105 100644
--- a/.claude/skills/app-bug-hunt/operations.md
+++ b/.claude/skills/app-bug-hunt/operations.md
@@ -43,6 +43,10 @@ ## 操作一覧 (web セッション面)
 | POST | organizations/{organization:slug}/transfer-ownership | organizations.transfer-ownership | S4 | 通常 |
 | PATCH | organizations/{organization:slug}/two-factor-requirement | organizations.two-factor-requirement.update | S4 | 通常 |
 | PATCH | organizations/{organization:slug} | organizations.update | S4 | 通常 |
+| POST | passkeys/confirm | passkey.confirm | S6 | 通常 |
+| DELETE | user/passkeys/{passkey} | passkey.destroy | S6 | 通常 |
+| POST | passkeys/login | passkey.login | S1 | 通常 |
+| POST | user/passkeys | passkey.store | S6 | 通常 |
 | POST | user/confirm-password | password.confirm.store | S6 | 通常 |
 | POST | forgot-password | password.email | S1 | 通常 |
 | POST | reset-password | password.update | S1 | 通常 |
@@ -70,6 +74,7 @@ ## 操作一覧 (web セッション面)
 | POST | recent-auth/password | recent-auth.password | S6 | 通常 |
 | POST | register | register.store | S1 | 通常 |
 | DELETE | settings/account | settings.account.destroy | S6 | 通常 |
+| POST | settings/password | settings.password.store | S6 | 通常 |
 | POST | user/confirmed-two-factor-authentication | two-factor.confirm | S6 | 通常 |
 | DELETE | user/two-factor-authentication | two-factor.disable | S6 | 通常 |
 | POST | user/two-factor-authentication | two-factor.enable | S6 | 通常 |
@@ -94,3 +99,28 @@ ## 課金ゲート allowlist と認可 (P4 反転後、要検出)
 - `onboarding.activate-personal` は `throttle:10,1` 付き。連打時に 429 が UX として
   説明されるか (無反応にならないか) を見る。
 - 二重課金の観点は S5 の逸脱アイデア参照 (`attempt_token` 冪等 / live pending dedup)。
+
+## パスキー / ログイン手段の認可・guard 契約 (T106/T107 後、要検出)
+
+正本は `docs/auth-security-mechanisms.md` §5・§6。**認証系は IDOR・詰みが最も出やすい面**
+なので、以下の 4 つは必ず破壊を試みる。
+
+- **他人の passkey は 404** (`{passkey}` は `SelfScopedPasskeyBinder` が
+  「認証ユーザー所有 + 数値正規化」を担う explicit binder。403 で存在を漏らさない
+  = セキュリティ不変条件 2 の実装点)。**他組織・他ユーザーの passkey id を
+  `passkey.destroy` に流し込んで 404 以外が返れば finding (Critical)**。
+- **唯一のログイン手段は消せない** (`ensure-login-method` middleware)。
+  パスキーだけのユーザーが唯一の passkey を削除しようとしたとき、
+  **403 で突き放さず「先に別の手段を登録してください」と行き先が示される**こと
+  (行き先のない詰みを作らない = H4)。
+- **登録・削除は再認証の後ろ** (`RequireRecentAuth`)。再認証が切れた状態で直 POST して
+  通ったら finding。再認証を求められたとき、**パスキーしか持たないユーザーが
+  `recent-auth.confirm` で詰まない**こと (T107 の `passkeyAvailable` 配線が効いているか)。
+- **`throttle:passkeys` / `settings.password.store` の `throttle:6,1`**。
+  連打で 429 になったとき**画面上で説明される**こと (無反応にしない)。
+
+`settings.password.store` は **SSO / パスキーのみで登録したユーザーがパスワードを
+初めて設定する経路** (T107 で新設)。既存の `user-password.update` (現行パスワード必須) とは
+別物なので、**現行パスワードを持たないユーザーが到達できること**、および
+**既にパスワードを持つユーザーがこの経路で現行パスワード検証を迂回できないこと**の
+両方を見る。
diff --git a/.claude/skills/app-bug-hunt/screens.md b/.claude/skills/app-bug-hunt/screens.md
index 8f1c3fb..482c24e 100644
--- a/.claude/skills/app-bug-hunt/screens.md
+++ b/.claude/skills/app-bug-hunt/screens.md
@@ -4,7 +4,13 @@ # 画面インベントリ (screens.md) — AI-CUE
 > ストーリー (S1..S7) を割り当てた。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
 > 対象外 (seo/social/sso/2fa下位/legal confirmation 等) は OUT_OF_SCOPE_PREFIXES で除外済み。
 
-## 画面一覧
+## GET × web 一覧 (画面 + 画面に付随する JSON GET)
+
+> 本表は「GET × web セッション面」の一覧であり、**Inertia 画面だけではない**。
+> 以下は画面ではなく**画面に付随する JSON GET** として載せている
+> (bug-hunt は単独で開かず、対応する画面操作の副作用として通過させる):
+> `capture.csrf-cookie` / `session.status` / `passkey.registration-options` /
+> `passkey.login-options` / `passkey.confirm-options`
 
 | route (URL) | name | 割当ストーリー |
 |---|---|---|
@@ -35,6 +41,8 @@ ## 画面一覧
 | organizations/{organization:slug}/onboarding/cli | organizations.onboarding.cli | S4 |
 | organizations/{organization:slug}/onboarding/mcp | organizations.onboarding.mcp | S4 |
 | organizations/{organization:slug}/settings | organizations.settings | S4 |
+| passkeys/confirm/options | passkey.confirm-options | S6 |
+| passkeys/login/options | passkey.login-options | S1 |
 | pricing | pricing | S5 |
 | privacy | legal.privacy | S1 |
 | purchase-tickets | billing.tickets.show | S5 |
@@ -60,6 +68,7 @@ ## 画面一覧
 | terms | legal.terms | S1 |
 | two-factor-challenge | two-factor.login | S1 |
 | user/confirm-password | password.confirm | S6 |
+| user/passkeys/options | passkey.registration-options | S6 |
 
 **非 Inertia の GET (画面ではないが分母に載せているもの)**:
 `capture.csrf-cookie` (撮影 PWA の CSRF cookie 発行) と `session.status`
@@ -67,6 +76,22 @@ ## 画面一覧
 セッション有効性プローブ。auth グループの**外**にあり guest でも 200 +
 `authenticated: false`) は Inertia ページを返さないが、ブラウザ挙動の契約に
 直結するためインベントリに残す (S3 / S6 で観測する)。
+パスキーの `passkey.*-options` 3 本も同じ扱い (次節)。
+
+## パスキー options endpoint の扱い (要検出)
+
+`passkey.*-options` の 3 本は**画面ではなく WebAuthn の challenge を返す JSON GET**
+(`capture.csrf-cookie` / `session.status` と同じ扱いで表に載せている)。
+bug-hunt はこれらを**単独で開くのではなく**、S1/S6 のパスキー操作を UI から実走した
+副作用として通過させる。加えて逸脱アイデアとして直叩きを行う:
+
+- `passkey.registration-options` / `passkey.confirm-options` は `RequireRecentAuth` /
+  auth の配下。**未ログイン・再認証切れで直叩きしたときに 401/302 で止まり、
+  challenge が漏れない**こと。
+- `passkey.login-options` は guest 配下。**メールアドレスを列挙できる応答差
+  (存在するユーザーと存在しないユーザーで応答が変わる)** が出ないこと (存在オラクル)。
+- 3 本とも `throttle:passkeys` 配下。連打時の 429 が**画面上で説明される**こと
+  (無反応で詰まないこと。H4)。
 
 ## 課金ゲート着地 (P4 ゲート反転) の画面遷移
 
diff --git a/.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md b/.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md
index ac00fde..6f1f4d7 100644
--- a/.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md
+++ b/.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md
@@ -8,6 +8,15 @@ ## 手順
 2. `contact` → `contact.store` → `contact.thanks`(問い合わせ完了)。
 3. `register` → `register.store` → `verification.notice` → `verification.send` 再送 → `verification.verify` でメール認証完了。
 4. `login` → `login.store` → 2FA 有効なら `two-factor.login` → `two-factor.login.store` → `dashboard` へ。
+4-b. **パスキーでのログイン (T106)**:
+   S6 でパスキーを登録したユーザーでログアウト → `login` 画面に
+   **「パスキーでログイン」導線が出ている**こと → `passkey.login-options` で challenge 取得 →
+   `passkey.login` で `dashboard` へ到達できること。
+   - **存在オラクル検証**: 存在しないメールアドレスで `passkey.login-options` を叩いたときの
+     応答が、存在するユーザーのときと**区別できない**こと(区別できたら finding = High)。
+   - **詰み検証**: パスキー非対応ブラウザ / WebAuthn が利用不可の環境で
+     「パスキーでログイン」を押したとき、**説明が出て通常ログインに戻れる**こと
+     (無反応・白画面なら finding = H4)。
 5. **登録直後の課金オンボーディング着地 (P4 ゲート反転 / P7 `?plan=` handoff)**:
    新規登録で作られた個人組織は**未契約**なので、業務画面 (`dashboard` 配下の
    プロジェクト等) へ行こうとすると `onboarding.checkout`(`/onboarding/checkout`)へ
@@ -36,8 +45,8 @@ ## 手順
 8. `logout` でログアウト。
 
 ## このストーリーで消化する screens / operations
-- screens: home, register, login, dashboard, onboarding.checkout, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms
-- operations: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store, debug.login-as, onboarding.activate-personal
+- screens: home, register, login, dashboard, onboarding.checkout, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms, passkey.login-options
+- operations: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store, debug.login-as, onboarding.activate-personal, passkey.login
 
 ## 逸脱アイデア (--deviate 時)
 - 認証前ページ(dashboard 等)へ直アクセス → login へ誘導されるか。認証後に login/register を開くと dashboard へ戻るか。
@@ -49,3 +58,8 @@ ## 逸脱アイデア (--deviate 時)
   詰まずに戻れるか。
 - `?plan=` に未知値 / `enterprise` / 巨大文字列を入れる → 正規化されて既定に倒れるか
   (500・存在オラクルにならないか)。
+- `passkey.login-options` を存在しないメール / 巨大文字列 / 非文字列で叩く → 応答差から
+  **ユーザーの存在が判別できないか**(判別できたら finding = High)。`throttle:passkeys` の
+  429 が無反応でなく説明付きで出るか。
+- TOTP を confirmed 済みのユーザーで `passkey.login` を試す → **拒否される**か
+  (`PasskeyLoginPolicy` の assurance 後退防止。通ったら finding = Critical)。
diff --git a/.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md b/.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md
index 573c359..a2c52f3 100644
--- a/.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md
+++ b/.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md
@@ -7,6 +7,24 @@ ## 手順
 1. `settings` → プロフィール編集 `user-profile-information.update`(表示名/メール。PII は保護)、パスワード変更 `user-password.update`。パスワード入力に**「表示」トグル**があるか(T042)。保存成功のトーストが出るか(T026)。
 2. `settings.security` → 2FA 有効化 `two-factor.enable` → `two-factor.confirm`、リカバリコード再生成 `two-factor.regenerate-recovery-codes`(トーストは 1 つのみ, T026)、無効化 `two-factor.disable`。
 3. 機微操作前の再認証: `password.confirm`(confirm-password 画面)→ `password.confirm.store`、`recent-auth.confirm`/`recent-auth.status` → `recent-auth.password`。
+3-b. **パスキーの登録 → 削除 (T106/T107)**:
+   `settings.security` → 「パスキーを追加」→ 再認証(`RequireRecentAuth`)を求められる →
+   `recent-auth.confirm` で通過 → `passkey.registration-options` で challenge 取得 →
+   `passkey.store` で登録完了。一覧に登録済みパスキーが出るか。
+   - **詰み検証**: パスキーだけが唯一のログイン手段になった状態で `passkey.destroy` を試す。
+     `ensure-login-method` に弾かれたとき、**「先に別のログイン手段を登録してください」という
+     行き先付きの説明**が出るか(403 の素っ気ないエラーで終わったら finding = H4)。
+   - **IDOR 検証**: 他ユーザーの passkey id を `passkey.destroy` に流し込む →
+     **必ず 404**(403 だと「その id は存在する」と漏れる)。
+   - 削除成功後、一覧から消えてトーストが 1 つだけ出るか(T026)。
+   - **再認証の詰み検証**: パスキーしか持たないユーザーが `passkey.confirm-options` →
+     `passkey.confirm` で `recent-auth.confirm` を通過できるか(パスワード欄しか出ずに
+     詰んだら finding = H4)。
+3-c. **パスワード未設定ユーザーの初回パスワード設定(`settings.password.store`, T107)**:
+   SSO / パスキーのみで登録したユーザーで `settings.security` を開く →
+   「パスワードを設定」導線が**存在し、押下できる**こと(必須条件未充足で disabled に
+   していないこと = 禁止事項 8)。現行パスワード欄が**要求されない**こと。
+   設定後に `login` からパスワードでログインできること。
 4. アカウント削除 `settings.account.destroy`(確認 → 実行)。
 5. **bfcache 復元時の秘匿・再検証 (`session.status`)**: 認証済み画面 → 外部/別ページへ遷移 →
    **ブラウザバック**で戻す。`resources/js/lib/bfcache-guard.ts` が pageshow 直後に
@@ -18,11 +36,17 @@ ## 手順
 6. 通知センター `notifications.index`(`/notifications`): 通知一覧・空状態の説明、既読化 `notifications.read` / 一括既読 `notifications.read-all` / 開封遷移 `notifications.open`。ブラウザタブ title が固有(「通知 | AI-CUE」)か(T034)。
 
 ## このストーリーで消化する screens / operations
-- screens: settings, settings.security, password.confirm, recent-auth.confirm, recent-auth.status, notifications.index, session.status
-- operations: user-profile-information.update, user-password.update, two-factor.enable, two-factor.confirm, two-factor.disable, two-factor.regenerate-recovery-codes, password.confirm.store, recent-auth.password, settings.account.destroy, notifications.read, notifications.read-all, notifications.open
+- screens: settings, settings.security, password.confirm, recent-auth.confirm, recent-auth.status, notifications.index, session.status, passkey.registration-options, passkey.confirm-options
+- operations: user-profile-information.update, user-password.update, two-factor.enable, two-factor.confirm, two-factor.disable, two-factor.regenerate-recovery-codes, password.confirm.store, recent-auth.password, settings.account.destroy, notifications.read, notifications.read-all, notifications.open, passkey.store, passkey.destroy, passkey.confirm, settings.password.store
 
 ## 逸脱アイデア (--deviate 時)
 - 再認証(recent-auth/confirm-password)を経ずに機微操作(2FA無効化・アカウント削除・オーナー移譲)を直 POST → ブロックされるか。
 - パスワード変更後に旧セッションが無効化されるか。2FA 無効化直後に必須組織(two-factor-requirement)へアクセスできるか。
 - アカウント削除を最後のオーナーが実行 → 組織が孤児化しないか、警告が出るか。
+- **他人の passkey を消せるか**: 別ユーザーの passkey id を `passkey.destroy` へ直 DELETE →
+  **404 以外(特に 403)が返れば存在オラクル = finding (Critical)**。数値でない id / bigint 範囲外も
+  500 にならず 404 か。
+- 再認証切れの状態で `passkey.registration-options` / `passkey.store` を直叩き → 通ったら finding。
+- 既にパスワードを持つユーザーが `settings.password.store` を直 POST して**現行パスワード検証を
+  迂回**できないか(できたら finding = Critical。正規経路は `user-password.update`)。
 - メール変更(`user-profile-information.update`)が **stale セッション(remember 経由で recent_auth 未 stamp)では recent-auth の step-up を要求**して弾かれるか(T031。UI 回避の直接 fetch でも 409 か)。変更成功時に旧アドレスへ通知されるか。
diff --git a/.claude/skills/app-implement/SKILL.md b/.claude/skills/app-implement/SKILL.md
index e099a27..409e03a 100644
--- a/.claude/skills/app-implement/SKILL.md
+++ b/.claude/skills/app-implement/SKILL.md
@@ -153,10 +153,16 @@ ### A-1. 実装
    cd {repo_root}/.claude/worktrees/tasks/{todo_id} && composer test
    ```
 4. 全施策の実装完了後に品質チェック（全コマンド green になるまで修正する）:
+   <!-- VERIFICATION_COMMANDS:BEGIN -->
    ```bash
    cd {repo_root}/.claude/worktrees/tasks/{todo_id} && composer phpstan && composer fix && pnpm lint:fix
+   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && composer test
    cd {repo_root}/.claude/worktrees/tasks/{todo_id} && vendor/bin/pint --test && pnpm lint && pnpm typecheck && pnpm test && pnpm build
+   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && pnpm typecheck:packages && pnpm build:packages && pnpm test:packages
    ```
+   <!-- VERIFICATION_COMMANDS:END -->
+   > テストレーンは**ホスト全体のグローバルロック**で直列化される（AGENTS.md §worktree 運用ルール）。
+   > 待ち時間が出るのは正常で、30 秒ごとの heartbeat が出ていればハングではない。**kill しない**。
 5. **テストが失敗した場合はテスト駆動で修正**
 6. **E2E テスト基盤（Dusk 等）が導入済みなら**、UI変更を含む施策では E2E テストも追加・実行する（未導入のテンプレート初期状態ではスキップ）
 
diff --git a/.env.example b/.env.example
index bc7e433..4b259a8 100644
--- a/.env.example
+++ b/.env.example
@@ -191,7 +191,7 @@ GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
 # relying party id (ホスト) と allowed origins ([APP_URL]) を、user handle secret を
 # APP_KEY から導出する (同一オリジン PWA 前提)。
 # ⚠ APP_KEY をローテートすると既存パスキーの user handle が変わり全件無効になる。
-#    運用契約は docs/architecture.md §パスキー (WebAuthn)。
+#    運用契約は docs/auth-security-mechanisms.md §5 パスキー (WebAuthn) の「運用上の注意」。
 
 # reCAPTCHA v2 invisible (site_key 未設定時は captcha 無しで動く。
 # secret_key は production では未設定 = fail-closed)
diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml
index 8b6ba65..7e91bca 100644
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -63,6 +63,14 @@ jobs:
           cp .env.example .env
           php artisan key:generate
           php artisan passport:keys --force
+      # bug-hunt インベントリ (.claude/skills/app-bug-hunt/{screens,operations}.md) と
+      # route:list のドリフト検知。T106 (passkey 7 route) / T107 (settings.password.store) で
+      # 2 サイクル連続してドリフトし、「認証系が bug-hunt のカバレッジから丸ごと落ちる」
+      # という実害が出た。台帳が正本である以上 soft-fail にしない (exit 3 で job を落とす)。
+      # 判定ロジックは既存スクリプトのままで PHP 側に再実装しない (自前解析器を増やさない)。
+      # route:list は APP_KEY を要するが DB は不要なので、Pest より前で fail-fast できる。
+      - name: Bug-hunt inventory drift
+        run: bash scripts/bug-hunt-inventory-check.sh
       # レンダー smoke テスト (施策 4) の前提。Dockerfile (dev/bughunt) と別に CI runner にも
       # ffmpeg/ffprobe と字幕フォントを導入し、存在・フォント解決を fail-fast 検証する (層 1)。
       - name: Provision ffmpeg for render smoke
diff --git a/AGENTS.md b/AGENTS.md
index ca861c8..fb64684 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -54,6 +54,27 @@ ## セキュリティ不変条件(アプリ都合で緩めない)
 8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
    必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
    安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)
+9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE は `Gate::authorize` を通すか、
+   exemption inventory へ理由付きで登録する(deny-by-default)。
+   **層 2(テナント境界 = 404)は層 3(認可 = 403)より前**(逆にすると存在が漏れる)
+   (`ControllerAuthorizationGateTest`)
+10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
+    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
+    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
+    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
+    `TenantBoundaryOrderingTest`)
+
+> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
+> (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
+> 相互参照するときは**番号ではなく項目名**で指すこと。既存の参照
+> (`docs/app-integration-guide.md` の「§7 不変条件 8」/ stripe webhook migration の「不変条件 7」)
+> を壊すため、どちらの側も renumber しない。
+
+> **運用要件 (T108)**: production は `TRUSTED_PROXIES` の**明示宣言が必須**
+> (未宣言 / `*` / `REMOTE_ADDR` / 書式不正は `ProductionEnvGuard` が起動時 fail-fast する
+> = **初回デプロイ前に設定が要る破壊的変更**)。`trustProxies(at: '*')` はレート制限を
+> 総当りに無効化するため復活させない。実 hop 一覧・CIDR の管理主体・変更手順は
+> `docs/trusted-proxies-runbook.md` が正本。
 
 ## 実装規約
 
@@ -74,9 +95,13 @@ ## 実装規約
   禁止。`tests/js/architecture/atomic-import-graph.test.ts` が強制)。アイコンは
   `@lucide/svelte` のみ。Lucide に無いブランド/SSO ロゴの SVG 内包は
   `components/atoms/icons/` 配下に限る(`svg-inline-allowlist.test.ts` が強制)
+<!-- VERIFICATION_COMMANDS:BEGIN -->
 - 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
-  `pnpm typecheck:packages` / `pnpm test:packages`(全 green でコミット)
+  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
+  (全 green でコミット。`tests/js/architecture/verification-commands-doc-sync.test.ts` が
+  package.json の検証系 script との同期を deny-by-default で強制する。マーカーごと消さないこと)
+<!-- VERIFICATION_COMMANDS:END -->
 
 ## コードベース探索
 
@@ -151,8 +176,18 @@ ## worktree 運用ルール
 - **orphan 化した worktree**(teardown を経ず破棄)は `git worktree prune` で整理。
   検証なしの強制削除は
   `git worktree remove --force .claude/worktrees/tasks/<task-id> && git worktree prune`
-- **背景と障害対応**: 分離設計 (vendor / node_modules / テスト DB / 実行時ファイルの 4 軸) の
-  意図は `docs/worktree-isolation-strategy.md`、`enableGlobalVirtualStore` の前提・落とし穴・
+- **テストレーンのグローバルロック (T099)**: `composer test` / `composer test:browser` /
+  `pnpm test` / `pnpm test:packages` / `pnpm test:coverage` は**ホスト全体で 1 本ずつ**しか
+  走らない (worktree 横断で直列化し、テスト DB とポートの衝突を構造的に防ぐ)
+  - **待ち時間が出るのは正常**。他レーンが走っていると**エラーにはならず待つ**。
+    待機中は **30 秒ごとに heartbeat** が stderr に出るので、出ている間はハングではない
+  - **kill しない / ロックファイルを消さない**。中断が必要なら**ロック保持者の pid に
+    `kill -TERM`** を送る (プロセスグループが空になるまで解放されない)。
+    ロックファイルの手動削除は二重実行を生む
+  - 手動復旧の runbook は `docs/testing-browser.md` §グローバルテストロックの手動復旧
+- **背景と障害対応**: 分離設計は「**リソース名前空間** (vendor / node_modules / テスト DB /
+  実行時ファイル) と **実行そのもの** (グローバルテストロック)」の 2 層構造。意図は
+  `docs/worktree-isolation-strategy.md`、`enableGlobalVirtualStore` の前提・落とし穴・
   復旧手順は `docs/pnpm-global-virtual-store-runbook.md`(GVS 無効化・暗黙 peer・ENOMEM 等)
 
 ## bug-hunt (LLM 探索的バグハント、オプトイン)
diff --git a/README.md b/README.md
index 988fcc8..188fa47 100644
--- a/README.md
+++ b/README.md
@@ -76,4 +76,6 @@ ## ドキュメント
 | `docs/app-integration-guide.md` | ドメインロジックのマッピング規則 + Item 見本チェックリスト |
 | `docs/default-team-pattern.md` | 組織 3 階層と Default Team の仕様 |
 | `docs/template-divergence.md` | テンプレートからの逸脱レジストリ |
+| `docs/auth-security-mechanisms.md` | 認証・セッション・パスキー・SSO・信頼境界の仕組みと不変条件 |
+| `docs/trusted-proxies-runbook.md` | client IP の信頼境界(`TRUSTED_PROXIES`)の運用契約。production は明示宣言が必須(未設定は起動時 fail-fast) |
 | `devnotes/20260611-template-extraction/` | テンプレート設計の調査・決定記録(01〜14) |
diff --git a/docs/architecture.md b/docs/architecture.md
index dd03586..8b6118f 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -80,9 +80,19 @@ ## route binding の型制約 (ドメイン制約: route key は最大 18 桁)
   実 ID が 10^18 に達することは無いため運用上の制約にならないが、
   「適合値の挙動は不変」ではない点に注意。値自体は Architecture テストが pin する
 - **5 分類 (deny-by-default)**: `BIGINT` / `UUID` (param => モデルの map。pattern 適用) /
-  `CUSTOM_BINDER` (`{organization}`。`{organization:slug}` 併用のため pattern を適用せず
-  `MembershipScopedOrganizationBinder` が入力正規化を担う) / `NON_MODEL` / `EXTERNAL`
+  `CUSTOM_BINDER` (explicit binder が入力正規化を担うため pattern を適用しない) /
+  `NON_MODEL` / `EXTERNAL`
   (vendor route が持ち込む param を route identity ごとに登録)。
+  `CUSTOM_BINDER` の現在の登録は以下 (`RouteBindingCustomBinderDocSyncTest` が
+  `RouteBindingTypes::CUSTOM_BINDER` と**双方向**で同期を強制する。マーカーごと消さないこと):
+  <!-- CUSTOM_BINDER:BEGIN -->
+  - `{organization}` — `MembershipScopedOrganizationBinder`。`{organization:slug}` を併用するため
+    数値 pattern を掛けると slug route が全滅する。binder が入力正規化を担う
+  - `{passkey}` — `SelfScopedPasskeyBinder`。Fortify (vendor) が登録する route の param で、
+    app 側から `Route::pattern` を掛けると vendor の route 定義変更に追随できないため、
+    binder が「認証ユーザー所有 + 数値正規化」を担う (**他人の passkey は 404** =
+    セキュリティ不変条件 2 の実装点。403 だと存在が漏れる)
+  <!-- CUSTOM_BINDER:END -->
   未登録 param の出現は `RouteBindingTypeConstraintInventoryTest` が fail させる
   (未知 param を数値と推測しない)。実挙動 (非適合 → 404) は
   `tests/Feature/Routing/RouteBindingTypeConstraintTest` が pgsql 実接続で固定する
diff --git a/tests/Architecture/RouteBindingCustomBinderDocSyncTest.php b/tests/Architecture/RouteBindingCustomBinderDocSyncTest.php
new file mode 100644
index 0000000..5dbdcfa
--- /dev/null
+++ b/tests/Architecture/RouteBindingCustomBinderDocSyncTest.php
@@ -0,0 +1,182 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Routing\RouteBindingTypes;
+use Webmozart\Assert\Assert;
+
+/*
+ * `RouteBindingTypes::CUSTOM_BINDER` と docs/architecture.md の列挙を **双方向** で同期する。
+ *
+ * なぜ必要か: T106 が `{passkey}` を CUSTOM_BINDER へ足したとき、docs/architecture.md の
+ * 「単一 SoT の全 binding param 型 inventory」を説明する節が 1 件のままドリフトした
+ * (監査サイクル 2 の docs-freshness §2-2)。`{passkey}` は「他人の passkey を 404 に倒す」=
+ * セキュリティ不変条件 2 の実装点であり、inventory から落ちている影響は小さくない。
+ *
+ * 解析対象は CUSTOM_BINDER:BEGIN 〜 CUSTOM_BINDER:END の HTML コメントで囲まれた範囲のみ。
+ * 文書全体を grep すると、別の文脈で `{passkey}` に言及しただけで green になり形骸化する
+ * (ci-workflow-inventory.test.ts が「YAML を parse してから歩く」のと同じ考え方)。
+ *
+ * 本テストは DB を触らない (ファイル読み取りのみ)。
+ */
+
+/** 同期マーカー。doc 側を書き換えるときは両方を維持すること。 */
+const CUSTOM_BINDER_DOC_BEGIN = '<!-- CUSTOM_BINDER:BEGIN -->';
+
+/** 同期マーカー (終端)。 */
+const CUSTOM_BINDER_DOC_END = '<!-- CUSTOM_BINDER:END -->';
+
+/**
+ * マーカー間の本文を取り出す (純関数)。
+ *
+ * マーカーがちょうど 1 組でなければ `null` を返す (呼び出し側が違反として報告する)。
+ */
+function customBinderDocSection(string $markdown): ?string
+{
+    if (substr_count($markdown, CUSTOM_BINDER_DOC_BEGIN) !== 1) {
+        return null;
+    }
+    if (substr_count($markdown, CUSTOM_BINDER_DOC_END) !== 1) {
+        return null;
+    }
+
+    $start = strpos($markdown, CUSTOM_BINDER_DOC_BEGIN);
+    $end = strpos($markdown, CUSTOM_BINDER_DOC_END);
+    Assert::integer($start);
+    Assert::integer($end);
+    if ($end <= $start) {
+        return null;
+    }
+
+    return substr($markdown, $start + strlen(CUSTOM_BINDER_DOC_BEGIN), $end - $start - strlen(CUSTOM_BINDER_DOC_BEGIN));
+}
+
+/**
+ * マーカー間から `` `{param}` `` トークンを抽出する (純関数)。
+ *
+ * バッククォート囲みの `{name}` だけを拾う。散文中の `{...}` (例: `{organization:slug}` の
+ * 説明や JSON 例) を巻き込まないようにするため、param 名は `[a-zA-Z][a-zA-Z0-9_]*` に限定する。
+ *
+ * @return list<string> 出現順・重複除去済み
+ */
+function customBinderDocParams(string $section): array
+{
+    $matches = [];
+    $count = preg_match_all('/`\{([a-zA-Z][a-zA-Z0-9_]*)\}`/', $section, $matches);
+    Assert::integer($count, 'CUSTOM_BINDER doc の param 抽出に失敗した');
+
+    /** @var list<string> $names */
+    $names = $matches[1];
+
+    return array_values(array_unique($names));
+}
+
+/**
+ * 定数と doc の乖離を列挙する (純関数)。
+ *
+ * @param  list<string>  $constantKeys  `RouteBindingTypes::CUSTOM_BINDER` の key
+ * @param  list<string>  $docParams  doc のマーカー範囲から抽出した param
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function customBinderDocSyncViolations(array $constantKeys, array $docParams): array
+{
+    $violations = [];
+
+    // forward: 定数にある key が doc に載っているか (追加時の追記漏れ = T106 のドリフト)
+    foreach ($constantKeys as $key) {
+        if (! in_array($key, $docParams, true)) {
+            $violations[] = "CB2: CUSTOM_BINDER の `{{$key}}` が docs/architecture.md の同期マーカー範囲に無い (追加時は 1 行追記すること)";
+        }
+    }
+
+    // reverse: doc にあるが定数に無い = stale (削除時の消し忘れ)
+    foreach ($docParams as $param) {
+        if (! in_array($param, $constantKeys, true)) {
+            $violations[] = "CB3: docs/architecture.md の `{{$param}}` が CUSTOM_BINDER に無い (削除時は doc の行も消すこと)";
+        }
+    }
+
+    return $violations;
+}
+
+/** docs/architecture.md を読む。 */
+function customBinderArchitectureMarkdown(): string
+{
+    $markdown = file_get_contents(base_path('docs/architecture.md'));
+    Assert::string($markdown, 'docs/architecture.md を読めない');
+
+    return $markdown;
+}
+
+test('CB1: docs/architecture.md に CUSTOM_BINDER 同期マーカーがちょうど 1 組あること', function (): void {
+    $markdown = customBinderArchitectureMarkdown();
+
+    expect(substr_count($markdown, CUSTOM_BINDER_DOC_BEGIN))->toBe(1);
+    expect(substr_count($markdown, CUSTOM_BINDER_DOC_END))->toBe(1);
+    expect(customBinderDocSection($markdown))->not->toBeNull();
+});
+
+test('CB2/CB3: CUSTOM_BINDER と docs/architecture.md の列挙が双方向で一致すること', function (): void {
+    $section = customBinderDocSection(customBinderArchitectureMarkdown());
+    Assert::string($section, 'CUSTOM_BINDER 同期マーカーがちょうど 1 組でない (CB1 を先に直すこと)');
+
+    $violations = customBinderDocSyncViolations(
+        array_keys(RouteBindingTypes::CUSTOM_BINDER),
+        customBinderDocParams($section),
+    );
+
+    expect($violations)->toBe([], "CUSTOM_BINDER と doc の乖離:\n".implode("\n", $violations));
+});
+
+test('CB4: 空振り防止 — doc から 1 件以上抽出でき、件数が定数と一致すること', function (): void {
+    $section = customBinderDocSection(customBinderArchitectureMarkdown());
+    Assert::string($section, 'CUSTOM_BINDER 同期マーカーがちょうど 1 組でない (CB1 を先に直すこと)');
+
+    $docParams = customBinderDocParams($section);
+
+    expect(count($docParams))->toBeGreaterThanOrEqual(1);
+    expect(count($docParams))->toBe(count(RouteBindingTypes::CUSTOM_BINDER));
+});
+
+test('CB5 正のコントロール: 定数にだけある key を forward 違反として検出すること', function (): void {
+    $violations = customBinderDocSyncViolations(['organization', 'passkey'], ['organization']);
+
+    expect($violations)->toContain(
+        'CB2: CUSTOM_BINDER の `{passkey}` が docs/architecture.md の同期マーカー範囲に無い (追加時は 1 行追記すること)',
+    );
+});
+
+test('CB6 正のコントロール: doc にだけある param を reverse 違反として検出すること', function (): void {
+    $violations = customBinderDocSyncViolations(['organization'], ['organization', 'ghost']);
+
+    expect($violations)->toContain(
+        'CB3: docs/architecture.md の `{ghost}` が CUSTOM_BINDER に無い (削除時は doc の行も消すこと)',
+    );
+});
+
+test('CB7 負のコントロール: マーカー外の `{ghost}` は照合対象にならないこと', function (): void {
+    $markdown = <<<'MD'
+        散文の中で `{ghost}` に言及している (マーカー外なので照合対象にならない)。
+
+        <!-- CUSTOM_BINDER:BEGIN -->
+        - `{organization}` — MembershipScopedOrganizationBinder
+        <!-- CUSTOM_BINDER:END -->
+
+        こちらも範囲外の `{phantom}`。
+        MD;
+
+    $section = customBinderDocSection($markdown);
+    Assert::string($section);
+
+    expect(customBinderDocParams($section))->toBe(['organization']);
+    expect(customBinderDocSyncViolations(['organization'], customBinderDocParams($section)))->toBe([]);
+});
+
+test('CB1 負のコントロール: マーカーが無い / 二重にあると section を取り出せないこと', function (): void {
+    expect(customBinderDocSection('マーカーの無い文書'))->toBeNull();
+    expect(customBinderDocSection(
+        CUSTOM_BINDER_DOC_BEGIN."\na\n".CUSTOM_BINDER_DOC_END."\n".CUSTOM_BINDER_DOC_BEGIN."\nb\n".CUSTOM_BINDER_DOC_END,
+    ))->toBeNull();
+    // 終端が先に来る (順序が壊れた) ケースも取り出せない
+    expect(customBinderDocSection(CUSTOM_BINDER_DOC_END."\na\n".CUSTOM_BINDER_DOC_BEGIN))->toBeNull();
+});
diff --git a/tests/js/architecture/ci-workflow-inventory.test.ts b/tests/js/architecture/ci-workflow-inventory.test.ts
index 3b148b4..1d6be6f 100644
--- a/tests/js/architecture/ci-workflow-inventory.test.ts
+++ b/tests/js/architecture/ci-workflow-inventory.test.ts
@@ -236,6 +236,21 @@ describe("ci.yml inventory gate", () => {
         expect(job(workflow, "supply-chain-audit").if).toBeUndefined();
     });
 
+    it("W16: php が bug-hunt インベントリの drift 検知を **実行行として** 持つこと", () => {
+        // T106 (passkey 7 route) / T107 (settings.password.store) で 2 サイクル連続して
+        // .claude/skills/app-bug-hunt/{screens,operations}.md がドリフトし、
+        // 「認証系が bug-hunt のカバレッジから丸ごと落ちる」実害が出た。
+        //
+        // runScript ではなく runLines を使う: runScript はコメント行も連結するため
+        // 「# bug-hunt-inventory-check.sh は将来入れる」というコメントで green になる
+        // (既存 W14b/W14c と同じ「実行行だけを見る」方針)。
+        const lines = runLines(job(workflow, "php"));
+        expect(
+            lines.some((l) => l.includes("scripts/bug-hunt-inventory-check.sh")),
+            "php job に bug-hunt インベントリ drift 検知の実行行が無い",
+        ).toBe(true);
+    });
+
     it("W13: continue-on-error が workflow のどこにも現れないこと (soft-fail 禁止)", () => {
         expect(findKeyPaths(workflow, "continue-on-error")).toEqual([]);
     });
diff --git a/tests/js/architecture/verification-commands-doc-sync.test.ts b/tests/js/architecture/verification-commands-doc-sync.test.ts
new file mode 100644
index 0000000..df25018
--- /dev/null
+++ b/tests/js/architecture/verification-commands-doc-sync.test.ts
@@ -0,0 +1,169 @@
+/**
+ * 検証コマンド列の同期ゲート — package.json の「検証系 script」が
+ * AGENTS.md と .claude/skills/app-implement/SKILL.md の検証コマンド列に載っていることを
+ * deny-by-default で強制する。
+ *
+ * なぜ必要か: T104 で CI の frontend job が `pnpm build:packages` を回すようになったが、
+ * AGENTS.md への追記が漏れ、app-implement/SKILL.md に至っては packages 系 3 本が
+ * 1 つも無い状態が続いた (監査サイクル 2 の docs-freshness §2-3)。手順どおり実装した
+ * worktree は packages のビルド破壊をローカルで検出できず、CI で初めて赤くなる。
+ *
+ * 免除は「理由付き」でしか書けない (EXEMPT)。免除エントリが package.json から消えたら
+ * 逆方向検査 (V3) が落ちる = stale 免除の残置を許さない
+ * (tests/Architecture/ScriptsReadmeInventoryTest.php と同じ作法)。
+ *
+ * 照合範囲は VERIFICATION_COMMANDS:BEGIN 〜 END の内側のみ。文書全体を検索すると
+ * 別文脈で `pnpm build` に言及しただけで green になり形骸化する
+ * (RouteBindingCustomBinderDocSyncTest と同じ方式)。
+ */
+import { describe, expect, it } from "vitest";
+import { readFileSync } from "node:fs";
+import { resolve } from "node:path";
+
+const MARKER_BEGIN = "<!-- VERIFICATION_COMMANDS:BEGIN -->";
+const MARKER_END = "<!-- VERIFICATION_COMMANDS:END -->";
+
+/** 照合対象ファイル (どちらにも同じ script 集合が載っている必要がある)。 */
+const TARGETS = ["AGENTS.md", ".claude/skills/app-implement/SKILL.md"] as const;
+
+/**
+ * 検証コマンド列に載せなくてよい script と、その理由。
+ *
+ * 理由を書けないものをここに足さないこと。package.json から消えた key を残置すると
+ * V3 が落ちる。
+ */
+const EXEMPT: Record<string, string> = {
+    dev: "開発サーバ起動。検証コマンドではない",
+    "lint:fix": "lint の自動修正。検証は lint 側が担う",
+    "test:ui": "vitest UI の対話起動。CI/検証で回すものではない",
+    "test:watch": "watch 実行。単発検証ではない",
+    "test:coverage": "カバレッジ計測。検証ゲートではない (test が正本)",
+    "audit:gate": "supply-chain gate は CI/nightly の blocking 実行が正本 (AGENTS.md §依存脆弱性に別記)",
+};
+
+function repoFile(relative: string): string {
+    return readFileSync(resolve(process.cwd(), relative), "utf-8");
+}
+
+/** package.json の scripts を読む (純関数の入力を作る境界)。 */
+export function packageScriptNames(packageJson: string): string[] {
+    const parsed = JSON.parse(packageJson) as { scripts?: Record<string, string> };
+    return Object.keys(parsed.scripts ?? {});
+}
+
+/**
+ * マーカー間の本文を返す (純関数)。マーカーがちょうど 1 組でなければ `null`。
+ */
+export function verificationCommandsSection(markdown: string): string | null {
+    const begins = markdown.split(MARKER_BEGIN).length - 1;
+    const ends = markdown.split(MARKER_END).length - 1;
+    if (begins !== 1 || ends !== 1) return null;
+
+    const start = markdown.indexOf(MARKER_BEGIN);
+    const end = markdown.indexOf(MARKER_END);
+    if (end <= start) return null;
+
+    return markdown.slice(start + MARKER_BEGIN.length, end);
+}
+
+function escapeRegExp(value: string): string {
+    return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
+}
+
+/**
+ * script 名が「`pnpm <name>` / `pnpm run <name>`」としてトークン境界付きで現れるか (純関数)。
+ *
+ * 単純な部分一致にしないのは、`test` が `test:packages` に含まれて誤 green になるため。
+ */
+export function mentionsScript(section: string, name: string): boolean {
+    return new RegExp(`pnpm (run )?${escapeRegExp(name)}(?![:\\w-])`).test(section);
+}
+
+/**
+ * 非免除 script のうち、対象 section に現れないものを返す (純関数)。
+ */
+export function missingVerificationCommands(
+    section: string,
+    scriptNames: string[],
+    exempt: Record<string, string>,
+): string[] {
+    return scriptNames.filter((name) => !(name in exempt)).filter((name) => !mentionsScript(section, name));
+}
+
+const packageJson = repoFile("package.json");
+const scriptNames = packageScriptNames(packageJson);
+const nonExempt = scriptNames.filter((name) => !(name in EXEMPT));
+
+describe("検証コマンド列 ↔ package.json の同期", () => {
+    it("V0: 各対象ファイルに同期マーカーがちょうど 1 組あること", () => {
+        for (const target of TARGETS) {
+            expect(verificationCommandsSection(repoFile(target)), `${target} のマーカーが 1 組でない`).not.toBeNull();
+        }
+    });
+
+    for (const target of TARGETS) {
+        it(`V1/V2: 非免除 script が ${target} のマーカー範囲内に全て現れること`, () => {
+            const section = verificationCommandsSection(repoFile(target));
+            expect(section, `${target} のマーカーが 1 組でない (V0 を先に直すこと)`).not.toBeNull();
+
+            const missing = missingVerificationCommands(section as string, scriptNames, EXEMPT);
+            expect(missing, `${target} の検証コマンド列に無い script: ${missing.join(", ")}`).toEqual([]);
+        });
+    }
+
+    it("V3: EXEMPT の全 key が package.json の scripts に実在すること (stale 免除の検出)", () => {
+        const stale = Object.keys(EXEMPT).filter((name) => !scriptNames.includes(name));
+        expect(stale, `package.json に無い免除の残置: ${stale.join(", ")}`).toEqual([]);
+    });
+
+    it("V4: 空振り防止 — 非免除 script が 7 件以上あること", () => {
+        expect(nonExempt.length).toBeGreaterThanOrEqual(7);
+    });
+
+    it("V5: 免除理由が 10 文字以上あること (理由なし免除を認めない)", () => {
+        for (const [name, reason] of Object.entries(EXEMPT)) {
+            expect(reason.trim().length, `免除 "${name}" の理由が短すぎる`).toBeGreaterThanOrEqual(10);
+        }
+    });
+});
+
+describe("走査関数の正負コントロール (検出器が空振りしていないこと)", () => {
+    it("V6 正のコントロール: 未記載 script を違反として検出する", () => {
+        const section = "`pnpm lint` / `pnpm typecheck`";
+        expect(missingVerificationCommands(section, ["lint", "typecheck", "ghost:script"], {})).toEqual([
+            "ghost:script",
+        ]);
+    });
+
+    it("V7 負のコントロール: マーカー範囲外の記述は照合対象にならない", () => {
+        const markdown = [
+            "範囲外で `pnpm ghost:script` に言及している。",
+            MARKER_BEGIN,
+            "- 検証コマンド: `pnpm lint`",
+            MARKER_END,
+            "こちらも範囲外の `pnpm phantom`。",
+        ].join("\n");
+
+        const section = verificationCommandsSection(markdown);
+        expect(section).not.toBeNull();
+        expect(missingVerificationCommands(section as string, ["lint"], {})).toEqual([]);
+        expect(missingVerificationCommands(section as string, ["ghost:script", "phantom"], {})).toEqual([
+            "ghost:script",
+            "phantom",
+        ]);
+    });
+
+    it("トークン境界: `pnpm test:packages` だけでは `test` を満たさない (部分一致による誤 green の防止)", () => {
+        expect(mentionsScript("`pnpm test:packages`", "test")).toBe(false);
+        expect(mentionsScript("`pnpm test:packages`", "test:packages")).toBe(true);
+        expect(mentionsScript("`pnpm run test`", "test")).toBe(true);
+        // `pnpm build` は `build:packages` を満たさない (逆方向の誤 green も防ぐ)
+        expect(mentionsScript("`pnpm build`", "build:packages")).toBe(false);
+    });
+
+    it("V0 負のコントロール: マーカーが無い / 二重にあると section を取り出せない", () => {
+        expect(verificationCommandsSection("マーカーの無い文書")).toBeNull();
+        expect(verificationCommandsSection(`${MARKER_BEGIN}a${MARKER_END}${MARKER_BEGIN}b${MARKER_END}`)).toBeNull();
+        expect(verificationCommandsSection(`${MARKER_END}a${MARKER_BEGIN}`)).toBeNull();
+    });
+});

```

## 検証結果 (worktree 内で実測)

- `bash scripts/bug-hunt-inventory-check.sh; echo $?` → **0** (実装前は exit 3 / 未追記 8 route)
- `composer test` → **3023 tests / 3021 passed / 0 failed / 2 skipped** (実装前 3016 / 3014 passed)
- `pnpm test` → **1213 passed / 124 files** (実装前 1202 passed)
- `pnpm test:packages` → **106 passed / 10 files**
- `composer phpstan` (level 10) → **No errors**
- `vendor/bin/pint --test` → passed / `pnpm lint` / `pnpm typecheck` → OK
- `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` → OK
- bug-hunt の Python stdlib テスト: `coverage/` 62 tests OK (skipped=1) / `ledger/` 70 tests OK
  (operations.md の 5 列 leading-pipe 書式が correlate.py 依存のため)

### テストファーストの証跡

1. **W16** を先に追加 → 現行 ci.yml で **1 failed** を確認 → CI step 追加後 27 passed
2. **G1 (RouteBindingCustomBinderDocSyncTest)** を先に追加 → CB1 が 0 組マーカーで **failed**
   → docs/architecture.md にマーカー + `{passkey}` 追記後 7 passed
3. **G2 (verification-commands-doc-sync.test.ts)** を先に追加 → **3 failed**
   (マーカー不在) → AGENTS.md / app-implement SKILL.md 修正後 10 passed

### 新設ゲートが実 drift を検出することの確認 (合成 fixture ではなく実ファイルを一時改変)

- docs/architecture.md から `{passkey}` の行を消す → G1 が
  「CB2: CUSTOM_BINDER の `{passkey}` が docs/architecture.md の同期マーカー範囲に無い」で 2 failed → 復元後 7 passed
- AGENTS.md と app-implement/SKILL.md の両方から `pnpm build:packages` を消す → G2 が
  両ファイルについて 2 failed → 復元後 10 passed

## 設計から意図的に逸脱した点

1. **screens.md の `user/passkeys/options` の挿入位置**: 設計は 3 行とも `pricing` の直前と書いているが、
   設計自身が「アルファベット順の既存並びを維持し」と指示している。`user/passkeys/options` は
   `user/confirm-password` の直後が正しい位置なので、そちらに置いた
   (`passkeys/confirm/options` / `passkeys/login/options` は設計どおり `pricing` 直前)。
2. **S6 の手順番号**: 設計は 3-b / 3-c を「手順 3 と 4 の間」に挿入と指示。
   手順 4 がアカウント削除なので、パスワード初回設定 (3-c) を削除の後ろに置くと
   ストーリーとして成立しない。両方とも手順 4 の前に置いた (設計の指示どおり)。
3. **operations.md の節見出し**: 設計の `(P106/P107 後、要検出)` は TODO ID の誤記と判断し
   `(T106/T107 後、要検出)` とした。
4. **app-implement/SKILL.md**: 設計は packages 系 1 行の追加のみだが、マーカー範囲内に
   `composer test` の行も併記した (元の品質チェック手順が別番号で `composer test` を持っており、
   マーカー範囲だけ読むと PHP レーンが抜けて見えるため)。あわせてグローバルテストロックの
   注意書き (待つ / heartbeat / kill しない) をマーカー直後に追記した (設計 D7 の趣旨)。
