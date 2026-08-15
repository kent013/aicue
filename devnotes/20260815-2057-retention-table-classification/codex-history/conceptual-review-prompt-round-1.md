# 使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 13 + PHP 8.4 + Svelte 5 + Inertia.js、テストは Pest / pgsql / --parallel）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか（オーバーエンジニアリング禁止。今必要なものだけ作る）
7. 型安全性: PHPStan level 10 を通せるか
8. 二重管理: 既存の仕組みと同じ事実を 2 か所に書いていないか

【このリポジトリ固有の前提（レビュー時に踏まえること）】
- 目録 (inventory) 方式の gate が多数あり、作法が確立している: deny-by-default / 30 文字以上の根拠 /
  件数を「現在値ちょうど」で pin / 負のコントロール付き / 保証しないものを docblock に明記する
- Feature/Unit lane は RefreshDatabase がグローバル適用され pgsql 実 DB を持つ。Architecture lane は DB を持たない
- テスト専用の目録クラスは tests/Support/ 配下に置く先例がある (ExternalSeam / Recovery / ForbiddenStatement)
- 既存の類似 gate: tests/Architecture/BillingRetentionTargetInventoryTest.php
  （母集団は app/Models/Billing/ という人間の申告。docblock 自身が「purge 対象テーブルの網羅性は保証しない」と明記）

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: retention-table-classification (表ごとの保持期限の分類と実スキーマ整合)

## 背景・課題

### 現状 — 保持期限は 6 系統に分散しており、母集団はどれも人間の申告である

| 系統 | 期限の値の置き場 | 実処理 | 対象表 |
|---|---|---|---|
| 課金取引記録 7 年 | `config/legal.php` の `billing_retention_years` (解決点 `App\Support\Legal\BillingRetention`) | `billing:purge-retention-expired` (daily) | 7 表 |
| 問い合わせ 365 日 | `config/legal.php` の `inquiry_retention_days` | `inquiry:purge` (daily) | `inquiries` |
| 冪等キー | `config/idempotency.php` の `retention_hours` (解決点 `App\Support\Idempotency\IdempotencyRetention`) | `idempotency:prune` (daily) | `idempotency_keys` / `mcp_idempotency_keys` |
| 撮影アップロード予約 | `config/capture.php` | `capture:purge-upload-reservations` (daily) | `take_upload_reservations` |
| 退会の凍結方式 30 日 | `config/account.php` の `deletion_grace_days` (解決点 `App\Support\Account\AccountDeletionGrace`) | `account:purge-deletion-requests` (daily) | `users` (予約が入った行のみ) |
| レンダ出力の世代収束 | 世代定義 (最新 succeeded 1 世代) | `render:reconcile-outputs` (5 分) | 表ではなく S3 実体 |

いずれも「自分の担当表」しか見ていない。**どの系統も母集団が人間の申告**であり、
最も近い `tests/Architecture/BillingRetentionTargetInventoryTest.php` は、その docblock 自身が
「purge 対象テーブルの網羅性は保証しない。母集団は `app/Models/Billing/` という**ディレクトリの
人間の申告**であり、別ディレクトリや Eloquent を経由しない表に置かれた場合この検査は**沈黙する**。
目録は機械が見つけた全部ではなく人間が申告した全部である」(32〜35 行) と明記している。

### 何が起きるか

現在 migration が作る表は **62 表** (+ Laravel の `migrations` 表 = 63 表)。上の 6 系統が
期限に触れているのは **11 表**で、**残り 52 表は「いつまで残るか」を誰も宣言していない**。
そして migration で表を 1 つ足しても、その表がどの保持期限にも属さないことは
**画面にもログにもテストにも出ない**。個人に紐づく列を持つ表が期限なしで単調増加しても無音であり、
気付くのは (a) 容量が問題になったとき、(b) 開示・削除の要請が来たときのどちらかである。
どちらも事後であり、事後には既に「消せたはずのものが残っている」状態が確定している。

### 仮説

**「どの表がどれだけ残るか」を機械が実スキーマから母集団化していないことが原因である**。
母集団を人間の申告 (ディレクトリ / モデル / コマンド) に置く限り、申告の外に置かれた表は
何をしても検出できない。母集団を**実スキーマの表一覧そのもの**に移し、台帳との集合等価を
両方向で強制すれば、「表を 1 つ足したら台帳に 1 行足さないと赤になる」状態を作れる。

成功判定: 新しい migration で表を 1 つ作り、台帳に登録しないまま `composer test` を回すと
その表名を挙げて赤くなること。逆に台帳から 1 行抜いても赤くなること。

## 改善アイデア

**実スキーマの全表を「保持期限を誰が持つか」の区分へ分類する台帳を 1 つ持ち、
台帳と実スキーマの表一覧が両方向で集合等価であることを検査で強制する。**

3 つの原則を置く:

1. **既存の期限を移さない (委譲)**。7 年の年数・起算点・purger の配線は
   `BillingRetentionTarget` 側に残したまま、台帳は「この表の期限の正本はあちら」と宣言し、
   集合の一致だけを機械で結線する。**同じ事実を 2 か所に書かない**。
2. **分類の根拠を文章ではなく構造で裏取りする**。「識別子の対応表だから個人情報は持たない」
   という理由は、その識別子が結局どこへつながるかを見ないと成立しない。よって区分ごとに
   **実スキーマの外部キーを見る検査**を 1 つずつ付ける。
3. **未確定を隠さない**。期限が決まっていない表は「未確定」という区分で台帳に載せ、
   件数と表名を検査が**現在値ちょうど**で pin する。分類しないまま放置する口を作らない。

### 区分 (5 種)

| 区分 | 意味 | 機械の裏取り |
|---|---|---|
| 課金取引の記録 | 7 年保持。期限と実処理の正本は `BillingRetentionTarget` | この区分の表集合が `BillingRetentionTarget` のテーブル集合と**両方向で一致** |
| 定期実行が消す | 掃除のバッチが期限を決める | 期限の**保持者**(解決点クラス / artisan コマンド名) の宣言が必須 |
| 主体と一緒に消える | 独自の期限を持たず、親・利用者・組織の削除に連動する | 実スキーマ上、**外部キーを 1 本以上持つ** (孤立した表を連動と言い張れない) |
| 期限を持たない | 基準データ・基盤の表 (個人に紐づかない) | **期限が要る区分の表への外部キーを 1 本も持たない** (持つなら期限の連鎖の中にある) |
| 未確定 | 保持期限をまだ決めていない | 件数と表名を現在値ちょうどで pin |

全区分に共通して **30 文字以上の根拠**を必須にする (本リポジトリの既存目録と同じ作法)。

### `BillingRetentionTargetInventoryTest` との責務境界

**軸が違う**ので併存させる。二重検査を作らないために境界を両方の docblock に書く。

| | `BillingRetentionTargetInventoryTest` (既存) | 本 gate (新設) |
|---|---|---|
| 母集団 | `app/Models/Billing/` 配下の Eloquent モデル (人間の申告) | 実スキーマの表一覧 (機械の実測) |
| 問い | その課金モデルを 7 年で**消すか消さないか** | その表は**いつまで残るか / 期限を誰が持つか** |
| 持つ値 | 年数・起算点列・purger の配線・実行順 | 持たない (区分と根拠と期限の保持者の名前だけ) |
| lane | Architecture (DB なし) | Feature (実スキーマを引くため DB が要る) |

重なるのは `BillingRetentionTarget` の 7 表だけで、そこは**宣言を写さず結線する**
(集合の両方向一致)。本 gate は年数も起算点も purger 名も書かない。
`BillingRetentionExclusion` (7 年 purge の対象外と裁定した課金モデル) の側とは結線しない
— あちらは「7 年 purge の対象外」という**否定の集合**であり、否定は「期限の持ち主が別にいる」
までしか言っていないので、本台帳では改めて肯定形で分類する。

## 期待効果

- **使命への貢献**: 現場の作業手順書 (SOP) と撮影データは顧客の業務ノウハウそのものである。
  「預けたものがいつまで保持され、いつ消えるか」を機械が答えられる状態は、
  法人顧客に業務データを預けてもらうための前提であり、AI-CUE が扱う資産の性質上、
  後回しにすると導入審査の段階で効いてくる。
- **具体的な改善見込み**:
  - 表を足した PR で分類が漏れると `composer test` が赤くなる (現在は無音)。
  - 「いま期限が決まっていない表は何か」が 1 か所を見れば分かる (現在はどこにも無い)。
  - 「個人情報を持たない」という分類が外部キーで裏取りされる (現在は主張が検証されない)。

## 実装方針（概要）

すべて**テスト側の宣言と検査**で完結させる。**アプリの実行時コードは 1 行も足さない**
(本台帳を実行時に読む消費者がいないため。本リポジトリの先例 —
`Tests\Support\ExternalSeam` / `Tests\Support\Recovery` / `Tests\Support\ForbiddenStatement` —
と同じ置き場所にする)。

- `tests/Support/Retention/RetentionClass.php` — 区分 5 種の enum。区分ごとの義務
  (保持者の宣言が要るか / どの構造検査に掛かるか) を述語で持つ。
- `tests/Support/Retention/RetentionTableEntry.php` — 1 表の宣言 (表名・区分・根拠・期限の保持者)。
- `tests/Support/Retention/RetentionTableRegistry.php` — 全表の宣言 (63 行)。
- `tests/Feature/Retention/RetentionTableClassificationTest.php` — 検査本体。
  実スキーマの表一覧と外部キーを読み、台帳と突き合わせる。判定は純関数に切り出して
  負のコントロールから合成入力で直接呼ぶ (既存 `billingRetentionClassify()` と同じ作法)。
- `tests/Architecture/BillingRetentionTargetInventoryTest.php` — docblock に責務境界を 1 段落追記
  (「表の網羅性は本 gate の担当ではない。実スキーマ全体との集合等価は
  `tests/Feature/Retention/RetentionTableClassificationTest.php` が持つ」)。**検査は増やさない**。
- `docs/architecture.md` — §表ごとの保持期限の分類 を新設 (仕組み・区分・保証しないものを書く。
  **件数と表名は写さない** — 正本は台帳だけ。2 か所に書くと必ず食い違うため)。
- `AGENTS.md` — ドメイン固有規約に 1 項追加 (新しい表を足す人が踏む規約であるため)。

### `config/retention.php` は作らない

lctl 台帳の正典 (aigenba v1) は `config/retention.php` を保存年数の正本として持つが、
**aicue では作らない**。本リポジトリは保存年数を既に 4 か所の config に持ち、
それぞれに「唯一の解決点」クラスと直読禁止 gate が付いている
(`billing_retention_years` / `inquiry_retention_days` / `retention_hours` / `deletion_grace_days`)。
ここへ 5 つ目の置き場を作ると値が二重管理になり、既存の直読禁止 gate と衝突する。
**本 feature が持つのは「分類」であって「値」ではない** ので、値の置き場は動かさない。
(正典との差分。設計上の判断として本書と gate の docblock に記録する)

## 制約・前提

- 実スキーマを引くため **Feature lane** に置く (Architecture lane は DB を持たないので
  常に空振りする。既存 `BillingRetentionTargetInventoryTest` の docblock 40 行が同じ理由で
  schema 照合を Feature lane へ移した実例を残している)。
- pgsql では `Schema::getTables()` を引数なしで呼ぶと**全スキーマ**が返る。
  現在のスキーマに限定して読む (`getCurrentSchemaName()` / 非修飾名を使う)。
- `RefreshDatabase` はグローバル適用済み、`--parallel` 実行。台帳は読み取りのみで DB を汚さない。
- `migrations` 表を含む Laravel 基盤の表も分類の対象にする (除外一覧を作らない = そこへ足すだけで
  検査から逃げられる口を作らないため)。
- 本リポジトリの目録の作法に合わせる: deny-by-default / 30 文字以上の根拠 /
  現在値ちょうどの件数 pin / 負のコントロール付き。

## スコープ外

- **保持期限の値を変えない / 新しい掃除バッチを作らない**。本 feature は分類と検査だけを行う。
  「未確定」と分類された表に期限を付ける作業は、分類が見えるようになった後の別タスクである
  (仕組みが機能していない段階で値を弄らない)。
- 列単位の分類 (どの列が個人情報か) は扱わない。単位は表である。
- 匿名化の対象列の全数一致 (正典の RC-6) は扱わない。aicue には匿名化で決着する経路が
  `ticket_ledger_entries` の畳み込み 1 件しかなく、それは `BillingRetentionTarget` 側が既に持つ。
- 運用手順書 (`docs/account-deletion-runbook.md` 相当) への未確定一覧の転記は行わない
  (件数を 2 か所に書かない方針。正本は台帳)。
- S3 上の実体 (レンダ出力・撮影テイク) の保持は扱わない。単位が表ではないため。
