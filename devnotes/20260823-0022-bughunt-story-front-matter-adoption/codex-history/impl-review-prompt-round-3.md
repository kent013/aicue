# 実装レビュー Round 3 (T245)

Round 2 の指摘 (Critical 0 / Warning 4 / Suggestion 0) に **4 件すべて対応**した (見送り 0 件)。
Round 2 で「対応済み / 妥当」と判定された W2 (AC-06 の負例) と W7 (既存ドリフトの正本化) は
そのままである。

## 検証結果 (Round 2 の修正をすべて入れた版で全数を流し直した)

| コマンド | 結果 |
|---|---|
| `composer test` | **6428 tests / 6426 passed / 0 failed / 2 skipped** (30807 assertions) |
| `composer phpstan` (level 10) | **No errors** |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` / `pnpm build` | すべて green |
| `pnpm test` | **173 files / 2366 tests passed** |
| `pnpm typecheck:packages` / `pnpm build:packages` | green |
| `pnpm test:packages` | **10 files / 106 tests passed** |
| `python3 -m unittest test_story_front_matter` (stories/) | **82 tests OK** (Round 1: 73 → Round 2: 81 → 82) |
| `python3 -m unittest test_bug_hunt_inventory` (scripts/tests/) | 75 tests OK |
| `python3 -m unittest test_correlate` (coverage/) | 60 tests OK |
| `python3 scripts/bug-hunt-inventory.py check` | **exit 0** (画面 71 件 / 操作 79 件) |
| 移行の検算 (`migrate_story_assignment.py verify`) | **成功** (変換前のみ 0 件 / 変換後のみ = S7 の追加分と完全一致 / 対象外 route は両側とも空集合 / 7 枚の `## 手順` 節の sha256 が全件一致) |

これで Round 2 の **W4 (全体テスト結果が未確定)** は解消した。

## 今回みてほしい点

1. **W1 の作り直し** — 全面禁止をやめ、`FORBIDDEN_VALUE_LEADING_CHARS = "&*|>{"` の
   **位置依存**の判定にした。README §1 A5 にも「位置で判定する」を明記して正本と読み手を
   一致させ、正例 (`R&D の手順` / `横幅 * 高さを確認する` / `入力 > 出力` が**通る**) を足した。
   **読み取り器と README の文法がこれで一致しているか**を見てほしい。
2. **W2 の作り直し** — `parse_violations()` / `assert_parse_rejects()` /
   `assert_card_rejects()` を置き、**狙った違反メッセージを名指しで assert** する形へ
   AC-01 の全負例と AC-02 / AC-05 / AC-10 / AC-11 / AC-12 / AC-13 の負例を書き換えた。
   **「別の違反で緑になる」経路が残っていないか**を見てほしい。
3. 残りの指摘 (W3 = D40 と stories/README の `not_applicable` 非採用理由) の文言が
   適切になっているか。

---

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

Codex Round 2 の全体判定は **CHANGES_REQUESTED** (Critical 0 / Warning 4 / Suggestion 0)。
**4 件すべてに対応した** (見送り 0 件)。

重点 3 点のうち **W2 (AC-06 の負例)** と **W7 (既存ドリフトの正本化)** は「対応済み / 妥当」と
判定された。**W4 (禁止文字の追加)** は「過度に狭い」と指摘されたので下記 1 で作り直した。

## [Warning] 1. A5 対応が README の制限文法より狭い

- 判断: **対応する**
- 根拠: 指摘のとおり。独自パーサなので `&` `*` `|` `>` `{` は**存在するだけでは**
  YAML の構造にならない。全面禁止すると `R&D` / `横幅 * 高さ` / `入力 > 出力` のような
  自然な値まで拒み、**読み取り器が README より狭くなる** —
  「README が文法の正本、ここは従う読み手」という docstring に反する。
- 対応内容: **位置依存の判定**へ作り直した。
  - `FORBIDDEN_VALUE_CHARS` (どこに現れても不可) は README §1 A4 のとおり `#` `:` 角括弧 引用符に戻した
  - `FORBIDDEN_VALUE_LEADING_CHARS = "&*|>{"` を新設し、**値 (と配列要素) の先頭**にだけ禁じた
    (YAML ならそこで構造が始まる位置である)
  - README の A5 に「機械的には値の先頭に置けないことで閉じる。**位置で判定する**ので
    途中に現れる分には使ってよい」を明記し、正本と読み手を一致させた
  - 正例 `test_ac_01_accepts_structural_chars_inside_values` を足し、
    `R&D の手順` / `横幅 * 高さを確認する` / `入力 > 出力` が**通る**ことを固定した
  - 負例に「配列要素の先頭」の形 (`setup: [&anchor 準備する]`) を足した
  - フローマップ `{a: b}` は `:` で落ちるので、期待する違反メッセージもそれに合わせた

## [Warning] 2. 新しい負例が「正しい理由」を機械固定していない

- 判断: **対応する**
- 根拠: 指摘のとおり。`synthetic_violations()` は読み取り器の違反と中身の違反を合成するので、
  非空だけを見ると**狙った分岐が壊れても別の違反で緑になる**。
  例として挙げられた「`title: |` を受理するよう後退しても H1 不一致で落ちる」は実際に成立する。
  AC-06 で直したのと同型の問題であり、手元でメッセージを実測しただけでは回帰検出に残らない。
- 対応内容: 判定ヘルパを 2 本置き、**狙った違反メッセージを名指しで assert** する形へ全面的に直した。
  - `parse_violations(raw)` — **読み取り器の違反だけ**を返す (中身の違反を混ぜない)
  - `assert_parse_rejects(raw, needle)` / `assert_card_rejects(needle, **kwargs)`
  適用範囲は AC-01 の全負例に加え、AC-02 / AC-05 / AC-10 / AC-11 / AC-12 / AC-13 の負例まで広げた
  (例: `id:S1` → 「半角コロンの後に半角空白 1 つが要る」/ `title: &anchor …` →
  「値の先頭に YAML の構造記号がある」/ `accounts: [guest,owner]` → 「配列の区切りが」)。

## [Warning] 3. D40 と stories/README の `not_applicable` 非採用理由が古い

- 判断: **対応する**
- 根拠: 指摘のとおり。`SKILL.md` を採用時債務から外して実際に更新したので、
  「採用時債務に在るため触らない」という理由はもう成立しない。
  非採用の判断自体は該当カード 0 枚なので妥当である。
- 対応内容: 両方の理由を書き換えた。
  「**読む対象が 1 枚も無い契約を先回りして置かない** (思考原則 2)。置くべき時期は
  再判定の条件が名指ししており、そのときの置き場は `SKILL.md` である」。
  D40 の観点表にも `(該当カードが 0 枚)` を添えた。

## [Warning] 4. 全体テスト結果が未確定

- 判断: **対応する**
- 根拠: 禁止事項 1 と「全 green でコミット」の完了条件はテスト結果でしか満たせない。
- 対応内容: Round 2 の修正をすべて入れてから検証コマンドを**全数**流し直し、
  結果を Round 3 のプロンプトへ実測値で載せる。
  (Round 2 のプロンプトに載せた `composer test` は Round 2 の修正前の版だったので破棄した。)

---

## Round 2 対応後の差分 (変更した 4 ファイルの main からの現在の差分全体)

```diff
diff --git a/.claude/skills/app-bug-hunt/stories/README.md b/.claude/skills/app-bug-hunt/stories/README.md
index 730d570b..65116c45 100644
--- a/.claude/skills/app-bug-hunt/stories/README.md
+++ b/.claude/skills/app-bug-hunt/stories/README.md
@@ -1,44 +1,199 @@
-# ストーリーカード (stories/) — スケルトン
+# シナリオカード (stories/) — 書式の正本
 
-> **これはテンプレート同梱のスケルトンである。** 各カードはユーザーが実際に辿るジャーニーを 1 本ずつ記述する。
-> bug-hunt はこのカードを 1 枚ずつ実走する。テンプレートは共通コア (認証・組織/プロジェクト・招待・課金・
-> 2FA・認可境界) の骨子だけを置く。**アプリ固有のジャーニー (ドメイン中核フロー) は S3 を中心に肉付けし、
-> 画面/操作を screens.md / operations.md と対応させること。**
+bug-hunt はここに置いたカードを 1 枚ずつ実走する。カードは「利用者が実際に辿るジャーニー」を
+1 本ずつ記述したもので、**どの画面・操作・機能を消化するか (割当) の正本もカードの前付け**である。
 
-## カードのフォーマット
+> **割当を `inventory/annotations.toml` に書かない。** 注釈が持つのは route ごとの意味
+> (`kind` / `kubun` / `reason`) だけで、割当は本ファイルの規約に従ってカードの前付けに書く。
+> 目録 (`screens.md` / `operations.md`) の「割当ストーリー」列は前付けから逆引き生成される。
+>
+> 機械検査は `stories/test_story_front_matter.py` (前付けの契約) と
+> `scripts/bug-hunt-inventory.py` (割当と目録の突合) の 2 本が分担する。前者は
+> `tests/Architecture/BughuntStoryToolSelfTest.php` が `composer test` の配線に載せる。
+
+## 1. 前付けの制限文法
+
+カードの先頭に前付けを置く。YAML に見えるが**読むのは下記の制限文法だけ**であり、
+汎用 YAML パーサは使わない (読み取り器は `story_front_matter.py`)。
+
+- **A1** 1 行目が厳密に `---`。次に現れる「行頭から `---` だけ」の行で閉じる
+  (本文中の水平線・表の区切り行に影響されない)
+- **A2** 1 行 1 項目。`key: value` (半角コロン + 半角空白 1 つ) だけを認める
+- **A3** key は `^[a-z][a-z0-9_]*$`。**重複 key は fail**
+- **A4** 値は 3 形のみ
+  - **素のスカラー** — 前後空白なし・**引用符禁止**・`#` `:` 角括弧を含めない
+  - **真偽値** — `true` / `false` のリテラルのみ
+  - **配列** — `[]` または `[a, b, c]` (要素は `, ` 区切り。ネスト不可・引用符禁止)
+- **A5** コメント行・空行・複数行スカラー・アンカー・参照・ネストマップは書けない。
+  機械的には**値 (と配列要素) の先頭に `&` `*` `|` `>` `{` を置けない**ことで閉じる
+  (YAML ならそこで構造が始まる記号である)。**位置で判定する**ので、`R&D` や
+  `横幅 * 高さ` のように途中に現れる分には使ってよい
+- **A6** key の並び順が下記の正準順序と一致する
+
+## 2. 前付けの項目定義 (必須 13 key + 条件付き 1 key)
+
+正準順序はこの表の並びである。
+
+| # | key | 値 | 説明 |
+|---|---|---|---|
+| 1 | `id` | スカラー `^S[1-9][0-9]*$` | カード番号 (ゼロ埋め禁止)。**一意** |
+| 2 | `title` | スカラー (非空) | H1 見出し `# {id}: {title}` と機械一致させる |
+| 3 | `surface` | スカラー | 対象面。**表 A に実在する語だけ** (未登録は fail) |
+| 4 | `lane` | `parallel_browser` / `serial_parent` | 実行方式 |
+| 5 | `priority` | `P1` / `P2` / `P3` | 落ちたときに走行全体が無意味になるかで決める |
+| 6 | `applicability` | `applicable` / `not_applicable` | 本アプリに該当する面か |
+| 7 | `not_applicable_reason` | スカラー (非空) | **`not_applicable` のときだけ**この位置に置く。`applicable` にあれば fail |
+| 8 | `depends_on` | 配列 (他カードの `id`) | 先に走らせる必要があるカード。無ければ `[]` |
+| 9 | `reseed_before` | 真偽値 | 開始前に初期データへ戻すか |
+| 10 | `accounts` | 配列 (下記のトークン語彙) | 使用アカウント |
+| 11 | `setup` | 配列 (一行の準備事項) | 無ければ `[]` |
+| 12 | `covers_screens` | 配列 (route 名) | 消化する画面 (safe method の web route) |
+| 13 | `covers_operations` | 配列 (route 名) | 消化する操作 (非 safe method の web route) |
+| 14 | `covers_capabilities` | 配列 `^[A-Z]+-[0-9]{2}$` | 消化する capability (`capability-catalog.md` の id) |
+
+**`covers_*` の値の実在は本ファイル側の検査では見ない** (見るのは形だけである)。
+実在の突合は目録側 (`scripts/bug-hunt-inventory.py`) の責務で、同じ規則を 2 か所に持たない。
+
+## 3. `covers_*` の 3 欄に何を書くか
+
+| 欄 | 母集合 | 検査 |
+|---|---|---|
+| `covers_screens` | **safe method (GET / HEAD / OPTIONS) の web route** | 実在 / 欄の意味 / 対象外でないこと / 分母の被覆 |
+| `covers_operations` | **非 safe method の web route** | 同上 |
+| `covers_capabilities` | `capability-catalog.md` の `capability_id 索引` の id | **実在・形・一意まで** (分母・被覆は見ない) |
+
+- **対象内 (`kubun` が `外` でない) の web route は、1 枚以上の `applicable` なカードに載ること。**
+  **区分 `終` は対象内**である (`外` だけが対象外)。載せる先が無い route は、注釈の区分を
+  `外` にして理由を書くこと (目録に見える形で宣言する)。
+- 1 つの route を複数のカードが挙げてよい (別視点で踏むのは正常)。**1 枚のカードの配列の中で
+  同じ値を 2 回書くことはできない**。
+- 対象面 (`surface`) が `admin_console` / `cli_or_api` の語彙は**予約**である。分母は
+  ブラウザ (web 面) に閉じているので、該当するカードは今は無い。
+
+## 4. 表 A: 対象面 (surface) の語彙
+
+家系必須の 11 語は**削除・改名しない** (追記は自由)。
+
+<!-- STORY-SURFACE-VOCABULARY:BEGIN -->
+
+| surface | 面 | 由来 |
+|---|---|---|
+| `signup_funnel` | 登録・ログインファネル | テンプレート同梱 |
+| `invitation` | 招待フロー | テンプレート同梱 |
+| `core_journey` | アプリ中核ジャーニー (AI-CUE = SOP からマニュアル動画まで) | テンプレート同梱 |
+| `org_project_admin` | 組織・プロジェクト管理 | テンプレート同梱 |
+| `billing` | 課金 | テンプレート同梱 |
+| `account_security` | セキュリティ (2FA / プロフィール) | テンプレート同梱 |
+| `authz_boundary` | 認可境界 (IDOR) | テンプレート同梱 |
+| `result_view` | 結果・レポートの閲覧 | 予約 |
+| `admin_console` | 管理画面 | 予約 |
+| `cli_or_api` | CLI / REST 面 | 予約 |
+| `public_share` | 未認証で到達する共有リンク面 | 予約 |
+
+<!-- STORY-SURFACE-VOCABULARY:END -->
+
+## 5. 表 B: カード目録
+
+実在するカードと 1 対 1 にする。`lane` / `priority` / `depends_on` の写しは**置かない**
+(第二の正本を作らないため。正本はカードの前付けである)。
+
+<!-- STORY-CARD-INVENTORY:BEGIN -->
+
+| id | surface |
+|---|---|
+| S1 | `signup_funnel` |
+| S2 | `invitation` |
+| S3 | `core_journey` |
+| S4 | `org_project_admin` |
+| S5 | `billing` |
+| S6 | `account_security` |
+| S7 | `authz_boundary` |
+
+<!-- STORY-CARD-INVENTORY:END -->
+
+## 6. 番号規約と S8 以降の識別規約
+
+- **D1** 番号は識別子であって意味を持たない。家系間の対応は `surface` で取る
+- **D2** 既存番号の面を付け替えない (S1〜S7 の `(id, surface)` は家系で固定)
+- **D3** `id` は一意
+- **D4** 欠番を作らない。`S1` から最大番号まで連番。該当面が無くてもカードを消さず
+  `applicability: not_applicable` で残す
+- **D5** ファイル名は `S{n}-{任意の kebab}.md`。機械一致するのは**先頭セグメント `S{n}`** だけ
+- **D7** S8 以降は番号でなく**対象面**で識別する。足すときは 3 か所を同じ変更で直す —
+  表 A に面を 1 行 / 表 B に 1 行 / カードを 1 枚
+
+## 7. 使用アカウントのトークン (`accounts`)
+
+`guest` / `owner` / `admin` / `member` / `platform_admin` の 5 語だけ。**語彙を拡張しない**
+(増やすと家系間の突合が緩む)。AI-CUE の ProjectRole (編集者 / 撮影者) のような
+アプリ固有の役割は、トークンではなく**本文の散文**で表す。
+
+> `accounts` と `database/seeders/ManualTestSeeder.php` の一致は機械検査しない
+> (家系の正典も同じ。PR レビューの義務である)。
+
+## 8. カード本文の確定形
+
+前付けを閉じたあとの本文は次の形にする。
 
 ```markdown
-# S{n}: {ジャーニー名}
+# {id}: {title}
 
-- 前提状態: (どのアカウント・どの初期データから始めるか。reseed 要否)
-- 目的: (このジャーニーでユーザーが達成したいこと)
+## 目的
+(このジャーニーで利用者が達成したいこと。散文)
 
 ## 手順
 1. (操作) → (期待)
-2. ...
-
-## このストーリーで消化する screens / operations
-- screens: (screens.md の該当行)
-- operations: (operations.md の該当行)
 
 ## 逸脱アイデア (--deviate 時)
 - (IDOR 探索・二重送信・戻る/リロード・隣接 ID 書き換え 等)
 ```
 
-## 並列 fan-out マップ (scripts/bug-hunt-shard.sh の stories_for_shard)
+> 見出しの直後に空行を置くかは契約ではない (節の中身が空でなければよい)。
+> ただし `## 手順` 節の中身は移行で 1 バイトも変えていないので、既存カードの形を保つこと。
+
+- **J1** H1 見出しは `# {id}: {title}` に固定し、前付けと機械一致させる
+- **J2** `## 目的` をちょうど 1 個持ち、節の中身が空でない
+- **J3** `## 逸脱アイデア (--deviate 時)` をちょうど 1 個持ち、節の中身が空でない
+- **H1** 旧メタ節 (`- 前提状態:` / `- 目的:` の箇条 /
+  `## このストーリーで消化する screens / operations`) を**残さない**。同じ事実が前付けと
+  散文の 2 か所に並ぶと、カード 1 枚の中に二重の正本ができる
+- **F2** `applicability: not_applicable` のカードは `## 手順` 節を持たない
+- **G6** 兆候番号 (`H{n}`) の**意味**はカードに書かない (語彙の正本は `SKILL.md` の
+  横断ヒューリスティクス表)。カードは `H4` のような参照だけを持つ
+
+## 9. 実行方式・依存・初期化要否の正本
 
-固定マップは S3↔S7 の状態依存を shard-1 に閉じ込める。cap=4、`--parallel` は 2/4。
-S1..S7 は browser story。CLI/REST 面・管理画面など特殊 guard を要する面は subagent fan-out に含めず親が直列追走する
-(アプリが追加する場合は S8 以降として本 README とカードに記述する)。
+- **E4** `lane` / `depends_on` / `reseed_before` の**正本はカードの前付け**である。
+  本ファイルは写しを持たない
+- **E1** `depends_on` の参照は実在し、自己参照でなく、循環しない
+- **E2** `depends_on` を持つなら `reseed_before` は `false` (片方向のみ)
+- **E3** `parallel_browser` のカードは `serial_parent` のカードに依存しない
+- **E5** `scripts/bug-hunt-shard.sh` の固定 fan-out マップは**前付けからの派生キャッシュ**である。
+  両者の一致は**機械検査しない** (家系の正典も未達)。カードの `lane` / `depends_on` を
+  変えたら固定マップを手で追随させること
 
-## テンプレート初期カード (共通コアの骨子)
+## 10. 本アプリが正典から外している契約
 
-| カード | 面 | 概要 (アプリが肉付け) |
+家系の正典が持つ契約のうち、本アプリが**採らない**ものを明示する
+(逸脱の登録は `docs/template-divergence.md` **D40**)。
+
+| 外している契約 | 理由 | 再判定の条件 |
 |---|---|---|
-| S1 | 登録/ログインファネル | ゲスト → 新規登録 → メール認証 → 初回ログイン |
-| S2 | 招待フロー | 組織オーナーがメンバーを招待 → 招待受諾 (別 cookie) |
-| S3 | **アプリ中核ジャーニー** | ドメインの主要価値フロー (アプリが定義。最重要) |
-| S4 | 組織・プロジェクト管理 | 組織/プロジェクトの作成・編集・切替・削除 |
-| S5 | 課金 | プラン選択 → checkout → サブスク状態確認 (Stripe fake) |
-| S6 | セキュリティ (2FA/プロフィール) | 2FA 有効化・プロフィール編集・パスワード変更・セッション管理 |
-| S7 | 認可境界 (IDOR) | 組織 A/B を跨いだ read/write が弾かれるか (S3 後の状態を使う) |
+| ステップ表の書式 (正準 4 列ヘッダ `\| step \| 操作 \| 期待 \| 注目 \|` / 疎な step 識別子 `{id}-{3 桁}` / 副ブロック / 期待欄・注目欄の書き分け) | 所見台帳の finding は story までしか指さず **step を指す欄を持たない**ため、ステップ識別子を入れても読む機械が 1 つも無い。手順は散文の番号付きリストのまま置く | `ledger/findings.schema.json` に step を指す欄が入ったとき / 家系の正典が t2 以降でステップ表を版の名前に含めたとき |
+| `not_applicable` のカードを実走対象から外す契約 (`SKILL.md` 側) | 該当カードが **0 枚**であり、読む対象が 1 枚も無い契約を先回りして置かない | `applicability` に `not_applicable` を取るカードを 1 枚でも置くことになったとき (そのときの置き場は `SKILL.md`) |
+
+正典との差で**採る側**にしたものは次の 2 点である (いずれも既存 **D20** が説明する)。
+
+| 観点 | 家系の正典 | 本アプリ |
+|---|---|---|
+| `covers_screens` の母集合 | `kind` が `screen` / `read` / `redirect` の web route | **safe method の web route** (`kind` の語彙が `画面` / `JSON` で違うため、`kind` に依存させない) |
+| `covers_capabilities` の検査 | 実在 / 欄の意味 / 分母 / 被覆の 4 段 | **実在・形・一意まで** (機能カタログが継承宣言の欄 `no_route` / `coverage_surface` / `covered_via` を持たないため、分母・被覆は見ない) |
+
+## 11. 保証しないもの
+
+- **割当が痩せたこと**は検出できない。目録側が見るのは「1 枚以上のカードに載っていること」
+  だけなので、ある route が `S3 S7` から `S3` へ減っても緑のままである (PR レビューの義務)
+- `web` group を宣言していない面 (機械向け API / Filament 管理画面 / MCP / 現在の webhook の
+  大半) には沈黙する (既存 **D20** の保証境界)
+- カードの前付けと `scripts/bug-hunt-shard.sh` の固定マップの一致 (上記 E5)
+- `accounts` と seeder の一致 (上記 7 節)
diff --git a/.claude/skills/app-bug-hunt/stories/story_front_matter.py b/.claude/skills/app-bug-hunt/stories/story_front_matter.py
new file mode 100644
index 00000000..9bd649b7
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/stories/story_front_matter.py
@@ -0,0 +1,245 @@
+#!/usr/bin/env python3
+"""シナリオカードの前付け (制限文法) の読み取り器。
+
+文法の**正本は `README.md`** であり、ここは**従う読み手**である。
+読み取り器を書き換えて文法を広げてはならない (広げるなら README と自己テストを同じ変更で直す)。
+
+**この読み取り器が見るもの** (制限文法 = README §1):
+
+- 前付けの区切り (1 行目が厳密に `---` / 次に現れる「行頭から `---` だけ」の行で閉じる)
+- 1 行 1 項目の `key: value` (半角コロン + 半角空白 1 つ)
+- key の書式・重複・**この文法に無い key**
+- 値の 3 形 (素のスカラー / 真偽値 / 配列) と、key ごとにどの形を取るか
+
+**この読み取り器が見ないもの** (見るのは呼び出し側である):
+
+- 必須 key の全数と正準順序 / 閉じた語彙 / 表 A・表 B との突合 / 本文の確定形
+  … `test_story_front_matter.py` が見る
+- `covers_*` の値の実在 / 欄の意味 / 分母の被覆 … `scripts/bug-hunt-inventory.py` が見る
+
+**例外を投げない** (読み取り不能そのものを除く)。違反は並びで返す。1 件目で止めると
+直すたびに再実行が要るためである。
+
+依存は標準ライブラリのみ (AGENTS.md §bug-hunt)。
+"""
+from __future__ import annotations
+
+import re
+from dataclasses import dataclass
+from pathlib import Path
+
+CANONICAL_KEYS = (
+    "id", "title", "surface", "lane", "priority", "applicability",
+    "not_applicable_reason",
+    "depends_on", "reseed_before", "accounts", "setup",
+    "covers_screens", "covers_operations", "covers_capabilities",
+)
+CONDITIONAL_KEY = "not_applicable_reason"
+REQUIRED_KEYS = tuple(k for k in CANONICAL_KEYS if k != CONDITIONAL_KEY)
+
+SCALAR_KEYS = frozenset({
+    "id", "title", "surface", "lane", "priority", "applicability", CONDITIONAL_KEY,
+})
+BOOL_KEYS = frozenset({"reseed_before"})
+ARRAY_KEYS = frozenset({
+    "depends_on", "accounts", "setup",
+    "covers_screens", "covers_operations", "covers_capabilities",
+})
+
+LANE_VOCABULARY = ("parallel_browser", "serial_parent")
+PRIORITY_VOCABULARY = ("P1", "P2", "P3")
+APPLICABILITY_VOCABULARY = ("applicable", "not_applicable")
+ACCOUNT_VOCABULARY = ("guest", "owner", "admin", "member", "platform_admin")
+
+# 照合はすべて fullmatch() で行う (Python の `$` は**末尾改行の直前にも一致する**ため、
+# match() + `$` は「厳密一致」と同義ではない)。
+CARD_ID_RE = re.compile(r"S[1-9][0-9]*")
+KEY_RE = re.compile(r"[a-z][a-z0-9_]*")
+FILENAME_RE = re.compile(r"S[1-9][0-9]*-.+\.md")
+ROUTE_TOKEN_RE = re.compile(r"[a-z0-9]+([._-][a-z0-9]+)*")
+CAPABILITY_TOKEN_RE = re.compile(r"[A-Z]+-[0-9]{2}")
+SURFACE_TOKEN_RE = re.compile(r"[a-z][a-z0-9_]*")
+
+FRONT_MATTER_DELIMITER = "---"
+BOOLEAN_LITERALS = {"true": True, "false": False}
+ARRAY_SEPARATOR = ", "
+# スカラーと配列要素に**どこに現れても**許さない文字 (README §1 の A4)。
+# 引用符・注釈・区切り・入れ子の記号である。
+FORBIDDEN_VALUE_CHARS = "#:[]'\""
+# 値の**先頭**にだけ許さない記号 (README §1 の A5)。YAML ならここで構造が始まる —
+# `&` アンカー / `*` 参照 / `|` `>` 複数行スカラー / `{` フローマップ。
+#
+# ★ **位置で判定する** (文字そのものを禁じない)。`R&D` や `横幅 * 高さ` のような
+#   自然な値まで拒むと、読み取り器が README の文法より狭くなる
+#   (「README が正本、ここは従う読み手」に反する)。
+FORBIDDEN_VALUE_LEADING_CHARS = "&*|>{"
+
+# 除外は**閉じたリテラル集合**にする (パターン除外を作らない)。
+EXCLUDED_FILENAMES = frozenset({"README.md"})
+
+
+class StoryReadError(Exception):
+    """カードを読むこと自体が成立しない状態 (置き場が無い / 候補が 0 件 / 読み取り不能)。"""
+
+
+@dataclass(frozen=True)
+class Card:
+    """1 枚のカード。値は制限文法で読めた形のまま持つ。"""
+
+    filename: str
+    text: str
+    front_matter: dict[str, object]
+    keys_in_order: tuple[str, ...]
+    body: str
+
+
+def _scalar_violation(key: str, value: str) -> str | None:
+    if value == "":
+        return f"{key}: スカラーが空である"
+    if value != value.strip():
+        return f"{key}: スカラーの前後に空白がある"
+    for char in FORBIDDEN_VALUE_CHARS:
+        if char in value:
+            return f"{key}: スカラーに使えない文字がある: {char!r}"
+    if value[0] in FORBIDDEN_VALUE_LEADING_CHARS:
+        return f"{key}: 値の先頭に YAML の構造記号がある: {value[0]!r}"
+
+    return None
+
+
+def _parse_array(key: str, value: str) -> tuple[list[str], list[str]]:
+    """配列を読む。`[]` か `[a, b, c]` だけを認める (ネスト不可・引用符禁止)。"""
+    if not (value.startswith("[") and value.endswith("]")):
+        return [], [f"{key}: 配列が角括弧で囲まれていない: {value!r}"]
+    inner = value[1:-1]
+    if inner == "":
+        return [], []
+
+    elements = inner.split(ARRAY_SEPARATOR)
+    violations: list[str] = []
+    for element in elements:
+        violation = _scalar_violation(key, element)
+        if violation is not None:
+            violations.append(f"{violation} (要素 {element!r})")
+        elif "," in element:
+            violations.append(f"{key}: 配列の区切りが '{ARRAY_SEPARATOR}' でない: {element!r}")
+
+    return elements, violations
+
+
+def parse_front_matter(
+    text: str,
+) -> tuple[dict[str, object], tuple[str, ...], list[str], str]:
+    """前付けを読み、(値, 出現順の key, 違反, 本文) を返す。**例外を投げない**。"""
+    violations: list[str] = []
+    lines = text.split("\n")
+
+    if not lines or lines[0] != FRONT_MATTER_DELIMITER:
+        violations.append(f"1 行目が {FRONT_MATTER_DELIMITER!r} でない")
+
+        return {}, (), violations, text
+
+    close = None
+    for index in range(1, len(lines)):
+        if lines[index] == FRONT_MATTER_DELIMITER:
+            close = index
+            break
+    if close is None:
+        violations.append(f"前付けが {FRONT_MATTER_DELIMITER!r} で閉じていない")
+
+        return {}, (), violations, text
+
+    values: dict[str, object] = {}
+    order: list[str] = []
+    for line in lines[1:close]:
+        if line == "":
+            violations.append("前付けに空行がある")
+            continue
+        key, separator, rest = line.partition(":")
+        if separator == "":
+            violations.append(f"key: value の形でない: {line!r}")
+            continue
+        if not rest.startswith(" "):
+            violations.append(f"半角コロンの後に半角空白 1 つが要る: {line!r}")
+            continue
+        value = rest[1:]
+        if KEY_RE.fullmatch(key) is None:
+            violations.append(f"key の書式が契約外: {key!r}")
+            continue
+        if key in values:
+            violations.append(f"key が重複している: {key}")
+            continue
+        if key not in CANONICAL_KEYS:
+            violations.append(f"この文法に無い key: {key}")
+            continue
+
+        if key in BOOL_KEYS:
+            if value not in BOOLEAN_LITERALS:
+                violations.append(f"{key}: 真偽値が true / false でない: {value!r}")
+                continue
+            values[key] = BOOLEAN_LITERALS[value]
+        elif key in ARRAY_KEYS:
+            elements, element_violations = _parse_array(key, value)
+            violations += element_violations
+            if element_violations:
+                continue
+            values[key] = elements
+        elif key in SCALAR_KEYS:
+            violation = _scalar_violation(key, value)
+            if violation is not None:
+                violations.append(violation)
+                continue
+            values[key] = value
+        else:
+            # 正準 key に足したのに型集合 (SCALAR/BOOL/ARRAY) への登録を忘れた形。
+            # 黙ってスカラー扱いにせず、内部契約の違反として落とす (fail-closed)。
+            violations.append(f"{key}: どの型集合にも登録されていない key である")
+            continue
+        order.append(key)
+
+    return values, tuple(order), violations, "\n".join(lines[close + 1:])
+
+
+def parse_card(filename: str, text: str) -> tuple[Card, list[str]]:
+    """1 枚分の本文からカードを作る。違反があってもカードは返す (呼び出し側が判断する)。"""
+    values, order, violations, body = parse_front_matter(text)
+
+    return (
+        Card(filename=filename, text=text, front_matter=values, keys_in_order=order, body=body),
+        [f"{filename}: {v}" for v in violations],
+    )
+
+
+def stories_dir() -> Path:
+    return Path(__file__).resolve().parent
+
+
+def read_cards(directory: Path | None = None) -> tuple[list[Card], list[str]]:
+    """候補母集団 (`*.md` から `EXCLUDED_FILENAMES` を引いた全件) を読む。
+
+    **パターンで発見しない**。`S8.md` のような命名違反を「存在しないもの」にしないため、
+    全件走査してから命名契約を検査する (命名の判定は呼び出し側の責務)。
+
+    読むこと自体が成立しない場合 (置き場が無い / 候補が 0 件 / 読み取り不能) は
+    `StoryReadError` を投げる。**違反 0 件と母集団 0 件を混ぜない**ためである。
+    """
+    target = stories_dir() if directory is None else directory
+    if not target.is_dir():
+        raise StoryReadError(f"カードの置き場が無い: {target}")
+
+    candidates = [p for p in sorted(target.glob("*.md")) if p.name not in EXCLUDED_FILENAMES]
+    if not candidates:
+        raise StoryReadError(f"カードの候補が 1 件も無い: {target}")
+
+    cards: list[Card] = []
+    violations: list[str] = []
+    for path in candidates:
+        try:
+            text = path.read_text(encoding="utf-8")
+        except (OSError, UnicodeDecodeError) as exc:
+            raise StoryReadError(f"カードを読めない: {path} ({exc})") from exc
+        card, card_violations = parse_card(path.name, text)
+        cards.append(card)
+        violations += card_violations
+
+    return cards, violations
diff --git a/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py b/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py
new file mode 100644
index 00000000..164f2a8b
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py
@@ -0,0 +1,1407 @@
+#!/usr/bin/env python3
+"""シナリオカードの書式契約の自己テスト (標準ライブラリのみ)。
+
+    cd .claude/skills/app-bug-hunt/stories && python3 -m unittest test_story_front_matter
+
+`composer test` からは `tests/Architecture/BughuntStoryToolSelfTest.php` が起動する。
+
+**走査対象**: `stories/*.md` から `story_front_matter.EXCLUDED_FILENAMES` を引いた全件と、
+書式の正本 `README.md` のマーカー区間 2 つ (表 A = 許可する対象面の語彙 /
+表 B = カード目録)。判定に使う純関数 (`card_violations` / `graph_violations` /
+`marker_table` / `partition_violations`) は**合成入力にも実データにも同じものを使う**ので、
+負例は実ファイル母集団が 0 件になっても走る。
+
+**保証しないもの**:
+
+- `covers_screens` / `covers_operations` / `covers_capabilities` の値の**実在**は見ない
+  (形だけを見る)。実在・欄の意味・分母の被覆は `scripts/bug-hunt-inventory.py` の責務で、
+  同じ規則を 2 か所に持たない (B16)。
+- `lane` / `depends_on` と `scripts/bug-hunt-shard.sh` の固定 fan-out マップの一致は見ない
+  (固定マップは派生キャッシュ。E5)。
+- 兆候番号 (`H{n}`) の意味がカードに書かれていないことは見ない (G6)。
+- 手順の書式 (ステップ表・step 識別子) は**採っていない**ので検査しない
+  (`docs/template-divergence.md` D40)。
+"""
+from __future__ import annotations
+
+import re
+import unittest
+
+import story_front_matter as sfm
+
+STORIES_DIR = sfm.stories_dir()
+README_PATH = STORIES_DIR / "README.md"
+
+SURFACE_MARKER = "STORY-SURFACE-VOCABULARY"
+INVENTORY_MARKER = "STORY-CARD-INVENTORY"
+SURFACE_TABLE_HEADER = ("surface", "面", "由来")
+INVENTORY_TABLE_HEADER = ("id", "surface")
+
+# 家系必須の対象面。削除・改名は fail (追記は自由)。
+FAMILY_REQUIRED_SURFACES = (
+    "signup_funnel", "invitation", "core_journey", "org_project_admin", "billing",
+    "account_security", "authz_boundary", "result_view", "admin_console",
+    "cli_or_api", "public_share",
+)
+
+# 家系固定: 既存番号の面を付け替えない。
+FAMILY_SURFACE_PIN = (
+    ("S1", "signup_funnel"),
+    ("S2", "invitation"),
+    ("S3", "core_journey"),
+    ("S4", "org_project_admin"),
+    ("S5", "billing"),
+    ("S6", "account_security"),
+    ("S7", "authz_boundary"),
+)
+PINNED_IDS = frozenset(card_id for card_id, _ in FAMILY_SURFACE_PIN)
+
+# 旧メタ節。前付けと散文の二重正本を残さない (H1)。
+LEGACY_META_PATTERNS = (
+    "- 前提状態:",
+    "- 目的:",
+    "## このストーリーで消化する",
+)
+
+PURPOSE_HEADING = "## 目的"
+DEVIATION_HEADING = "## 逸脱アイデア (--deviate 時)"
+STEPS_HEADING = "## 手順"
+
+
+# --------------------------------------------------------------------------- #
+# 判定の純関数 (合成入力にも実データにも同じものを使う)
+# --------------------------------------------------------------------------- #
+def marker_table(
+    text: str, marker: str, header: tuple[str, ...]
+) -> tuple[list[tuple[str, ...]], list[str]]:
+    """マーカー区間から表を抜き、構造契約を検査して (データ行, 違反) を返す。
+
+    契約 (**空行の位置も契約である**):
+
+        <!-- {marker}:BEGIN -->
+        (空行 1 行)
+        | 正準ヘッダ |
+        | 正準区切り行 |     ← 各セルはちょうど `---`
+        | データ行 |         ← 1 行以上。**読み飛ばしを一切しない**
+        (空行 1 行)
+        <!-- {marker}:END -->
+
+    BEGIN / END はそれぞれちょうど 1 個で、**BEGIN が END より前**にあること。
+    表の中に空行を挟まないこと。
+    """
+    violations: list[str] = []
+    begin, end = f"<!-- {marker}:BEGIN -->", f"<!-- {marker}:END -->"
+    if text.count(begin) != 1 or text.count(end) != 1:
+        violations.append(f"{marker}: マーカー区間がちょうど 1 対でない")
+        return [], violations
+    if text.index(begin) > text.index(end):
+        violations.append(f"{marker}: END が BEGIN より前にある")
+        return [], violations
+
+    # BEGIN 行の残り / 空行 / 表 / 空行 / END 行の手前、で 4 つの空要素に挟まれる。
+    raw = text.split(begin, 1)[1].split(end, 1)[0].split("\n")
+    if len(raw) < 5 or raw[0] != "" or raw[1] != "" or raw[-1] != "" or raw[-2] != "":
+        violations.append(f"{marker}: マーカー区間の空行の配置が契約外")
+        return [], violations
+
+    lines = raw[2:-2]
+    if any(line.strip() == "" for line in lines):
+        violations.append(f"{marker}: 表の中に空行がある")
+        return [], violations
+
+    expected_header = "| " + " | ".join(header) + " |"
+    if lines[0] != expected_header:
+        violations.append(f"{marker}: 正準ヘッダでない: {lines[0]!r} (期待 {expected_header!r})")
+        return [], violations
+    if len(lines) < 2:
+        violations.append(f"{marker}: 区切り行が無い")
+        return [], violations
+    expected_separator = "|" + "|".join(["---"] * len(header)) + "|"
+    if lines[1] != expected_separator:
+        violations.append(
+            f"{marker}: 正準区切り行でない: {lines[1]!r} (期待 {expected_separator!r})"
+        )
+        return [], violations
+
+    rows: list[tuple[str, ...]] = []
+    for line in lines[2:]:
+        if not line.startswith("|") or not line.endswith("|"):
+            violations.append(f"{marker}: 区間に表以外の行がある: {line!r}")
+            continue
+        cols = tuple(c.strip() for c in line.strip("|").split("|"))
+        if len(cols) != len(header):
+            violations.append(f"{marker}: データ行の列数が {len(header)} でない: {line!r}")
+            continue
+        rows.append(cols)
+
+    if not rows:
+        violations.append(f"{marker}: データ行が 1 行も無い")
+
+    return rows, violations
+
+
+def unwrap_code(value: str) -> tuple[str, bool]:
+    """1 対のバッククォートを外す。装飾がそれ以外なら第 2 要素が False。"""
+    if len(value) >= 2 and value.startswith("`") and value.endswith("`") and "`" not in value[1:-1]:
+        return value[1:-1], True
+
+    return value, False
+
+
+def surface_vocabulary(text: str) -> tuple[list[str], list[str]]:
+    """表 A を読み、許可する対象面の語彙と違反を返す (C1 / C2 / C3)。"""
+    rows, violations = marker_table(text, SURFACE_MARKER, SURFACE_TABLE_HEADER)
+    surfaces: list[str] = []
+    for cols in rows:
+        token, decorated = unwrap_code(cols[0])
+        if not decorated:
+            violations.append(f"表 A: surface の装飾が 1 対のバッククォートでない: {cols[0]!r}")
+            continue
+        if sfm.SURFACE_TOKEN_RE.fullmatch(token) is None:
+            violations.append(f"表 A: surface が snake_case 1 語でない: {token!r}")
+            continue
+        if token in surfaces:
+            violations.append(f"表 A: surface が重複している: {token}")
+            continue
+        surfaces.append(token)
+
+    for required in FAMILY_REQUIRED_SURFACES:
+        if required not in surfaces:
+            violations.append(f"表 A: 家系必須の対象面が無い: {required}")
+
+    return surfaces, violations
+
+
+def card_inventory(text: str) -> tuple[list[tuple[str, str]], list[str]]:
+    """表 B を読み、(id, surface) の並びと違反を返す (C4 / C5)。"""
+    rows, violations = marker_table(text, INVENTORY_MARKER, INVENTORY_TABLE_HEADER)
+    entries: list[tuple[str, str]] = []
+    seen: set[str] = set()
+    for cols in rows:
+        card_id = cols[0]
+        token, decorated = unwrap_code(cols[1])
+        if sfm.CARD_ID_RE.fullmatch(card_id) is None:
+            violations.append(f"表 B: id の書式が契約外: {card_id!r}")
+            continue
+        if not decorated:
+            violations.append(f"表 B: surface の装飾が 1 対のバッククォートでない: {cols[1]!r}")
+            continue
+        if card_id in seen:
+            violations.append(f"表 B: id が重複している: {card_id}")
+            continue
+        seen.add(card_id)
+        entries.append((card_id, token))
+
+    return entries, violations
+
+
+def section_body(text: str, heading: str) -> str | None:
+    """H2 見出しの直後から次の H2 見出しの直前までを返す。無ければ None。"""
+    lines = text.splitlines()
+    start = None
+    for index, line in enumerate(lines):
+        if line == heading:
+            start = index + 1
+            break
+    if start is None:
+        return None
+    end = len(lines)
+    for index in range(start, len(lines)):
+        if lines[index].startswith("## "):
+            end = index
+            break
+
+    return "\n".join(lines[start:end])
+
+
+def card_violations(card: sfm.Card, surfaces: tuple[str, ...] | list[str]) -> list[str]:
+    """カード 1 枚の契約を検査する (B / F2 / H1 / J 群)。
+
+    ★ 前付けの**文法**違反は `story_front_matter.parse_card()` が既に返しているので、
+      ここでは重ねて見ない。ここが見るのは「読めた前付けの中身」と本文である。
+    """
+    violations: list[str] = []
+    prefix = f"{card.filename}:"
+    values = card.front_matter
+
+    # --- B1: 必須 key の全数と正準順序 (条件付き key は applicability で決まる) ---
+    applicability = values.get("applicability")
+    expected = list(sfm.REQUIRED_KEYS)
+    if applicability == "not_applicable":
+        expected.insert(sfm.CANONICAL_KEYS.index(sfm.CONDITIONAL_KEY), sfm.CONDITIONAL_KEY)
+    if list(card.keys_in_order) != expected:
+        violations.append(f"{prefix} key の全数か正準順序が契約外: {list(card.keys_in_order)}")
+        return violations
+
+    def scalar(key: str) -> str:
+        value = values.get(key)
+
+        return value if isinstance(value, str) else ""
+
+    def array(key: str) -> list[str]:
+        value = values.get(key)
+
+        return [str(v) for v in value] if isinstance(value, list) else []
+
+    # --- B2 / B4〜B7 / B10 / B11: 語彙と書式 ---
+    if sfm.CARD_ID_RE.fullmatch(scalar("id")) is None:
+        violations.append(f"{prefix} id の書式が契約外: {scalar('id')!r}")
+    if scalar("title") == "":
+        violations.append(f"{prefix} title が空である")
+    if scalar("surface") not in surfaces:
+        violations.append(f"{prefix} surface が表 A に無い: {scalar('surface')!r}")
+    if scalar("lane") not in sfm.LANE_VOCABULARY:
+        violations.append(f"{prefix} 未知の lane: {scalar('lane')!r}")
+    if scalar("priority") not in sfm.PRIORITY_VOCABULARY:
+        violations.append(f"{prefix} 未知の priority: {scalar('priority')!r}")
+    if scalar("applicability") not in sfm.APPLICABILITY_VOCABULARY:
+        violations.append(f"{prefix} 未知の applicability: {scalar('applicability')!r}")
+    if not isinstance(values.get("reseed_before"), bool):
+        violations.append(f"{prefix} reseed_before が真偽値でない")
+    for account in array("accounts"):
+        if account not in sfm.ACCOUNT_VOCABULARY:
+            violations.append(f"{prefix} 未知の accounts トークン: {account!r}")
+
+    # --- B8: 条件付き key の値 ---
+    if applicability == "not_applicable" and scalar(sfm.CONDITIONAL_KEY) == "":
+        violations.append(f"{prefix} not_applicable_reason が空である")
+
+    # --- B9 / B12〜B15 + AC-13: 配列の形と重複 ---
+    for key, pattern in (
+        ("depends_on", sfm.CARD_ID_RE),
+        ("covers_screens", sfm.ROUTE_TOKEN_RE),
+        ("covers_operations", sfm.ROUTE_TOKEN_RE),
+        ("covers_capabilities", sfm.CAPABILITY_TOKEN_RE),
+    ):
+        for element in array(key):
+            if pattern.fullmatch(element) is None:
+                violations.append(f"{prefix} {key} の要素の書式が契約外: {element!r}")
+    for key in sfm.ARRAY_KEYS:
+        elements = array(key)
+        duplicates = sorted({e for e in elements if elements.count(e) > 1})
+        if duplicates:
+            violations.append(f"{prefix} {key} に重複した要素がある: {', '.join(duplicates)}")
+    for element in array("setup"):
+        if element.strip() == "":
+            violations.append(f"{prefix} setup に空の要素がある")
+
+    # --- J1: H1 見出しと前付けの機械一致 ---
+    expected_heading = f"# {scalar('id')}: {scalar('title')}"
+    headings = [line for line in card.body.splitlines() if line.startswith("# ")]
+    if headings[:1] != [expected_heading]:
+        violations.append(f"{prefix} H1 見出しが前付けと一致しない (期待 {expected_heading!r})")
+
+    # --- F2: not_applicable のカードは手順を持たない ---
+    has_steps = any(line == STEPS_HEADING for line in card.body.splitlines())
+    if applicability == "not_applicable" and has_steps:
+        violations.append(f"{prefix} not_applicable のカードに {STEPS_HEADING} 節がある")
+
+    # --- H1: 旧メタ節が残っていない ---
+    for line in card.body.splitlines():
+        for pattern in LEGACY_META_PATTERNS:
+            if line.startswith(pattern):
+                violations.append(f"{prefix} 旧メタ節が残っている: {line!r}")
+
+    # --- J2 / J3: 本文の確定形 (ちょうど 1 個 + 中身が空でない) ---
+    for heading in (PURPOSE_HEADING, DEVIATION_HEADING):
+        count = sum(1 for line in card.body.splitlines() if line == heading)
+        if count != 1:
+            violations.append(f"{prefix} {heading} 節がちょうど 1 個でない ({count} 個)")
+            continue
+        body = section_body(card.body, heading)
+        if body is None or body.strip() == "":
+            violations.append(f"{prefix} {heading} 節の中身が空である")
+
+    return violations
+
+
+def graph_violations(cards: list[sfm.Card]) -> list[str]:
+    """カード横断の契約を検査する (D3 / D4 / D5 / E1 / E2 / E3)。"""
+    violations: list[str] = []
+    ids: list[str] = []
+    by_id: dict[str, sfm.Card] = {}
+
+    for card in cards:
+        # --- D5: ファイル名の先頭セグメントだけを機械一致させる ---
+        if sfm.FILENAME_RE.fullmatch(card.filename) is None:
+            violations.append(f"{card.filename}: ファイル名が S{{n}}-{{kebab}}.md でない")
+            continue
+        card_id = str(card.front_matter.get("id", ""))
+        if sfm.CARD_ID_RE.fullmatch(card_id) is None:
+            violations.append(f"{card.filename}: id の書式が契約外で番号規約を判定できない")
+            continue
+        if card.filename.split("-", 1)[0] != card_id:
+            violations.append(f"{card.filename}: ファイル名の先頭セグメントが id ({card_id}) と違う")
+            continue
+        # --- D3: id は一意 ---
+        if card_id in by_id:
+            violations.append(f"{card.filename}: id が重複している: {card_id}")
+            continue
+        ids.append(card_id)
+        by_id[card_id] = card
+
+    # --- D4: 欠番を作らない (S1 から最大番号まで連番) ---
+    if ids:
+        numbers = sorted(int(i[1:]) for i in ids)
+        if numbers != list(range(1, numbers[-1] + 1)):
+            violations.append(f"カード番号に欠番がある: {numbers}")
+
+    # --- E1: depends_on の実在・自己参照・循環 ---
+    for card_id, card in by_id.items():
+        for dependency in card.front_matter.get("depends_on", []) or []:
+            if dependency == card_id:
+                violations.append(f"{card.filename}: depends_on が自己参照している")
+            elif dependency not in by_id:
+                violations.append(f"{card.filename}: depends_on に実在しないカード: {dependency}")
+
+    def reaches_self(start: str) -> bool:
+        """start から depends_on を辿って start 自身へ戻れるか (自己参照を含む)。"""
+        stack, seen = [start], set()
+        while stack:
+            node = stack.pop()
+            for dependency in by_id[node].front_matter.get("depends_on") or []:
+                key = str(dependency)
+                if key == start:
+                    return True
+                if key in by_id and key not in seen:
+                    seen.add(key)
+                    stack.append(key)
+
+        return False
+
+    for card_id, card in by_id.items():
+        if reaches_self(card_id):
+            violations.append(f"{card.filename}: depends_on が循環している")
+
+    # --- E2 / E3 ---
+    for card_id, card in by_id.items():
+        dependencies = [str(d) for d in (card.front_matter.get("depends_on") or [])]
+        if dependencies and card.front_matter.get("reseed_before") is not False:
+            violations.append(f"{card.filename}: depends_on を持つなら reseed_before は false")
+        if card.front_matter.get("lane") == "parallel_browser":
+            for dependency in dependencies:
+                if dependency in by_id and by_id[dependency].front_matter.get("lane") == "serial_parent":
+                    violations.append(
+                        f"{card.filename}: parallel_browser のカードが serial_parent に依存している"
+                    )
+
+    return violations
+
+
+# --------------------------------------------------------------------------- #
+# AC-14: 全数点呼
+# --------------------------------------------------------------------------- #
+# 詳細設計の全数対応表の全 58 項目。**ここが点呼の基準**である。
+ALL_INVARIANTS = (
+    "A1", "A2", "A3", "A4", "A5", "A6",
+    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
+    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
+    "C1", "C2", "C3", "C4", "C5",
+    "D1", "D2", "D3", "D4", "D5", "D6", "D7",
+    "E1", "E2", "E3", "E4", "E5",
+    "F1", "F2",
+    "G1", "G2", "G3", "G4", "G5", "G6",
+    "H1",
+    "I1", "I2", "I3", "I4", "I5", "I6", "I7",
+    "J1", "J2", "J3",
+)
+EXPECTED_TOTAL = 58
+
+# --- 分類 (互いに排他。和が ALL_INVARIANTS と一致する) ---
+ADOPTED = (
+    "A1", "A2", "A3", "A4", "A5", "A6",
+    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
+    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
+    "C1", "C2", "C3", "C4", "C5",
+    "D1", "D2", "D3", "D4", "D5", "D7",
+    "E1", "E2", "E3", "E4", "E5",
+    "F2",
+    "G6",
+    "H1",
+    "I1", "I2", "I3", "I4", "I6",
+    "J1", "J2", "J3",
+)
+DIFFERENCES = ("I5", "I7")                                  # aicue 固有差 (既存 D20 が説明)
+NOT_ADOPTED = ("D6", "F1", "G1", "G2", "G3", "G4", "G5")    # 新規 D40 が説明
+
+# --- 担い手 (集合同士の重複を許す。B16 のように両側に現れる項目がある) ---
+STORY_SIDE = (
+    "A1", "A2", "A3", "A4", "A5", "A6",
+    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
+    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
+    "C1", "C2", "C3", "C4", "C5",
+    "D1", "D2", "D3", "D4", "D5", "D7",
+    "E1", "E2", "E3", "E4",
+    "F2", "H1", "J1", "J2", "J3",
+)
+INVENTORY_SIDE = ("B16", "I1", "I2", "I3", "I4", "I6")
+NON_MECHANICAL = ("E5", "G6")
+
+SUBJECT_TO_TESTS = {
+    "AC-01": (
+        "test_ac_01_accepts_canonical_front_matter",
+        "test_ac_01_accepts_horizontal_rule_in_body",
+        "test_ac_01_accepts_structural_chars_inside_values",
+        "test_ac_01_rejects_quoted_scalar",
+        "test_ac_01_rejects_duplicate_key",
+        "test_ac_01_rejects_key_out_of_canonical_order",
+        "test_ac_01_rejects_missing_required_key",
+        "test_ac_01_rejects_unknown_key",
+        "test_ac_01_rejects_blank_and_comment_line",
+        "test_ac_01_rejects_missing_delimiter",
+        "test_ac_01_rejects_malformed_key_value_separator",
+        "test_ac_01_rejects_malformed_key_syntax",
+        "test_ac_01_rejects_malformed_array_syntax",
+        "test_ac_01_rejects_yaml_structures",
+        "test_ac_01_rejects_key_outside_type_sets",
+    ),
+    "AC-02": (
+        "test_ac_02_accepts_real_cards_vocabulary",
+        "test_ac_02_rejects_unknown_lane",
+        "test_ac_02_rejects_unknown_priority",
+        "test_ac_02_rejects_unknown_account",
+        "test_ac_02_rejects_zero_padded_id",
+        "test_ac_02_rejects_non_boolean_reseed",
+    ),
+    "AC-03": (
+        "test_ac_03_accepts_real_card_naming",
+        "test_ac_03_rejects_gap_in_card_numbers",
+        "test_ac_03_rejects_duplicate_id",
+        "test_ac_03_rejects_filename_without_id_segment",
+    ),
+    "AC-04": (
+        "test_ac_04_accepts_surface_vocabulary_table",
+        "test_ac_04_rejects_removed_family_surface",
+        "test_ac_04_rejects_wrong_table_header",
+        "test_ac_04_rejects_duplicate_surface_row",
+        "test_ac_04_rejects_prose_line_inside_marker",
+        "test_ac_04_rejects_reversed_markers",
+        "test_ac_04_rejects_blank_line_layout_change",
+        "test_ac_04_rejects_non_canonical_separator_row",
+    ),
+    "AC-05": (
+        "test_ac_05_accepts_inventory_matching_cards",
+        "test_ac_05_rejects_card_missing_from_inventory",
+        "test_ac_05_rejects_inventory_row_without_card",
+        "test_ac_05_rejects_surface_outside_vocabulary",
+        "test_ac_05_rejects_inventory_table_with_extra_column",
+    ),
+    "AC-06": (
+        "test_ac_06_accepts_family_surface_pin",
+        "test_ac_06_rejects_reassigned_family_surface",
+    ),
+    "AC-07": (
+        "test_ac_07_accepts_real_dependencies",
+        "test_ac_07_rejects_dependency_cycle",
+        "test_ac_07_rejects_self_dependency",
+        "test_ac_07_rejects_unknown_dependency",
+    ),
+    "AC-08": (
+        "test_ac_08_accepts_dependency_without_reseed",
+        "test_ac_08_rejects_reseed_with_dependency",
+    ),
+    "AC-09": (
+        "test_ac_09_accepts_serial_depending_on_parallel",
+        "test_ac_09_rejects_parallel_depending_on_serial",
+    ),
+    "AC-10": (
+        "test_ac_10_accepts_not_applicable_card",
+        "test_ac_10_rejects_steps_in_not_applicable_card",
+        "test_ac_10_rejects_reason_on_applicable_card",
+        "test_ac_10_rejects_missing_reason_on_not_applicable_card",
+    ),
+    "AC-11": (
+        "test_ac_11_accepts_matching_heading",
+        "test_ac_11_rejects_heading_mismatch",
+        "test_ac_11_rejects_missing_heading",
+    ),
+    "AC-12": (
+        "test_ac_12_accepts_real_cards_without_legacy_meta",
+        "test_ac_12_rejects_legacy_meta_section",
+        "test_ac_12_rejects_legacy_purpose_bullet",
+    ),
+    "AC-13": (
+        "test_ac_13_accepts_covers_shape",
+        "test_ac_13_rejects_duplicate_array_element",
+        "test_ac_13_rejects_malformed_route_token",
+        "test_ac_13_rejects_malformed_capability_token",
+    ),
+    "AC-14": (
+        "test_ac_14_accepts_complete_partition",
+        "test_ac_14_accepts_explicit_subject_to_test_mapping",
+        "test_ac_14_rejects_missing_invariant",
+        "test_ac_14_rejects_duplicate_classification",
+        "test_ac_14_rejects_adopted_without_bearer",
+        "test_ac_14_rejects_unknown_bearer_id",
+        "test_ac_14_rejects_wrong_total",
+    ),
+    "AC-15": (
+        "test_ac_15_accepts_canonical_body",
+        "test_ac_15_rejects_missing_purpose_section",
+        "test_ac_15_rejects_duplicate_purpose_section",
+        "test_ac_15_rejects_empty_purpose_section",
+        "test_ac_15_rejects_missing_deviation_section",
+        "test_ac_15_rejects_duplicate_deviation_section",
+        "test_ac_15_rejects_empty_deviation_section",
+    ),
+}
+
+INVARIANT_TO_SUBJECT = {
+    "A1": "AC-01", "A2": "AC-01", "A3": "AC-01", "A4": "AC-01", "A5": "AC-01", "A6": "AC-01",
+    "B1": "AC-01",
+    "B2": "AC-02", "B5": "AC-02", "B6": "AC-02", "B7": "AC-02", "B10": "AC-02",
+    "B11": "AC-02", "B12": "AC-02",
+    "B3": "AC-11",
+    "B4": "AC-05",
+    "B8": "AC-10",
+    "B9": "AC-07",
+    "B13": "AC-13", "B14": "AC-13", "B15": "AC-13", "B16": "AC-13",
+    "C1": "AC-04", "C2": "AC-04", "C3": "AC-04",
+    "C4": "AC-05", "C5": "AC-05",
+    "D1": "AC-06", "D2": "AC-06",
+    "D3": "AC-03", "D4": "AC-03", "D5": "AC-03",
+    "D7": "AC-05",
+    "E1": "AC-07", "E2": "AC-08", "E3": "AC-09", "E4": "AC-05",
+    "F2": "AC-10",
+    "H1": "AC-12",
+    "J1": "AC-11", "J2": "AC-15", "J3": "AC-15",
+}
+
+
+def partition_violations(
+    all_invariants: tuple[str, ...],
+    adopted: tuple[str, ...],
+    differences: tuple[str, ...],
+    not_adopted: tuple[str, ...],
+    bearers: tuple[str, ...],
+    expected_total: int,
+) -> list[str]:
+    """分類と担い手の整合を見て違反の並びを返す (実データにも合成入力にも使う純関数)。"""
+    violations: list[str] = []
+    if len(all_invariants) != expected_total:
+        violations.append(f"全数が {expected_total} 件でない: {len(all_invariants)}")
+    if len(all_invariants) != len(set(all_invariants)):
+        violations.append("全数の一覧に重複がある")
+
+    classified = [*adopted, *differences, *not_adopted]
+    if len(classified) != len(set(classified)):
+        violations.append("分類が重複している")
+    if set(classified) != set(all_invariants):
+        missing = sorted(set(all_invariants) - set(classified))
+        extra = sorted(set(classified) - set(all_invariants))
+        violations.append(f"分類の和が全数と一致しない (不足 {missing} / 余分 {extra})")
+
+    for key in adopted:
+        if key not in bearers:
+            violations.append(f"担い手の無い採用項目: {key}")
+    for key in sorted(set(bearers) - set(all_invariants)):
+        violations.append(f"担い手集合に未知の ID: {key}")
+
+    return violations
+
+
+# --------------------------------------------------------------------------- #
+# 合成入力 (実ファイル母集団が 0 件になりうる違反分岐を必ず走らせる)
+# --------------------------------------------------------------------------- #
+BASE_VALUES: dict[str, object] = {
+    "id": "S1",
+    "title": "見本カード",
+    "surface": "signup_funnel",
+    "lane": "parallel_browser",
+    "priority": "P1",
+    "applicability": "applicable",
+    "depends_on": [],
+    "reseed_before": True,
+    "accounts": ["guest"],
+    "setup": [],
+    "covers_screens": ["home"],
+    "covers_operations": ["login.store"],
+    "covers_capabilities": ["AUTH-01"],
+}
+BASE_BODY = (
+    "# S1: 見本カード\n"
+    "\n"
+    "## 目的\n"
+    "見本のカードである。\n"
+    "\n"
+    "## 手順\n"
+    "1. 開く → 見える\n"
+    "\n"
+    "## 逸脱アイデア (--deviate 時)\n"
+    "- 二重送信してみる\n"
+)
+BASE_SURFACES = list(FAMILY_REQUIRED_SURFACES)
+
+
+def render_value(value: object) -> str:
+    if isinstance(value, bool):
+        return "true" if value else "false"
+    if isinstance(value, list):
+        return "[" + ", ".join(str(v) for v in value) + "]"
+
+    return str(value)
+
+
+def render_front_matter(values: dict[str, object], order: list[str] | None = None) -> str:
+    keys = order if order is not None else [k for k in sfm.CANONICAL_KEYS if k in values]
+
+    return "---\n" + "".join(f"{k}: {render_value(values[k])}\n" for k in keys) + "---\n"
+
+
+def build_card(
+    *,
+    values: dict[str, object] | None = None,
+    order: list[str] | None = None,
+    body: str | None = None,
+    filename: str = "S1-sample.md",
+    raw: str | None = None,
+) -> tuple[sfm.Card, list[str]]:
+    text = raw if raw is not None else render_front_matter(
+        dict(BASE_VALUES) if values is None else values, order
+    ) + "\n" + (BASE_BODY if body is None else body)
+
+    return sfm.parse_card(filename, text)
+
+
+def synthetic_violations(**kwargs: object) -> list[str]:
+    """合成カード 1 枚の文法違反と中身の違反を合わせて返す。"""
+    card, parse = build_card(**kwargs)  # type: ignore[arg-type]
+
+    return parse + card_violations(card, BASE_SURFACES)
+
+
+def parse_violations(raw: str) -> list[str]:
+    """**読み取り器の違反だけ**を返す (中身の違反を混ぜない)。
+
+    ★ 負例で `synthetic_violations()` の非空だけを見ると、狙った分岐が壊れても
+      **別の違反**で緑になる (例: 読み取り器が `title: |` を受理するよう後退しても、
+      H1 見出しの不一致で落ちるので気付けない)。負例は必ず狙った分岐を名指しする。
+    """
+    return sfm.parse_front_matter(raw)[2]
+
+
+# --------------------------------------------------------------------------- #
+# 実データ (母集団)
+# --------------------------------------------------------------------------- #
+class StoryFrontMatterContractTest(unittest.TestCase):
+    """カードの書式契約。実データと合成入力の両方を同じ純関数で判定する。"""
+
+    @classmethod
+    def setUpClass(cls) -> None:
+        cls.readme = README_PATH.read_text(encoding="utf-8")
+        cls.cards, cls.parse_violations = sfm.read_cards(STORIES_DIR)
+        cls.surfaces, cls.surface_violations = surface_vocabulary(cls.readme)
+        cls.inventory, cls.inventory_violations = card_inventory(cls.readme)
+
+    # ----------------------------------------------------------------- #
+    # 負例の共通ヘルパ (狙った分岐を名指しする)
+    # ----------------------------------------------------------------- #
+    def assert_parse_rejects(self, raw: str, needle: str) -> None:
+        """読み取り器が**その理由で**落とすこと (別の違反での緑を許さない)。"""
+        violations = parse_violations(raw)
+        self.assertTrue(
+            any(needle in v for v in violations),
+            f"{needle!r} を含む違反が無い: {violations}",
+        )
+
+    def assert_card_rejects(self, needle: str, **kwargs: object) -> None:
+        """カードの中身の検査が**その理由で**落とすこと。"""
+        violations = synthetic_violations(**kwargs)
+        self.assertTrue(
+            any(needle in v for v in violations),
+            f"{needle!r} を含む違反が無い: {violations}",
+        )
+
+    # ----------------------------------------------------------------- #
+    # 母集団の非空 (走査が空振りしていないこと)
+    # ----------------------------------------------------------------- #
+    def test_population_is_not_empty(self) -> None:
+        """カード母集団と表 A / 表 B のデータ行がいずれも空でないこと。"""
+        self.assertNotEqual([], self.cards, "カード母集団が 0 件 (走査根が壊れている)")
+        self.assertNotEqual([], self.surfaces)
+        self.assertNotEqual([], self.inventory)
+
+    def test_real_cards_parse_without_violations(self) -> None:
+        """実カードの前付けが制限文法で読めること。"""
+        self.assertEqual([], self.parse_violations)
+
+    def test_real_cards_have_no_content_violations(self) -> None:
+        """実カードの中身が契約に反していないこと。"""
+        violations: list[str] = []
+        for card in self.cards:
+            violations += card_violations(card, self.surfaces)
+        self.assertEqual([], violations)
+
+    def test_real_cards_have_no_graph_violations(self) -> None:
+        """番号規約と依存の契約に反していないこと。"""
+        self.assertEqual([], graph_violations(self.cards))
+
+    # ----------------------------------------------------------------- #
+    # AC-01: 制限文法 + 必須 key 全数 + 正準順序 + 重複なし
+    # ----------------------------------------------------------------- #
+    def test_ac_01_accepts_canonical_front_matter(self) -> None:
+        self.assertEqual([], synthetic_violations())
+
+    def test_ac_01_accepts_horizontal_rule_in_body(self) -> None:
+        """本文中の水平線で前付けが閉じたことにならないこと (A1)。"""
+        body = BASE_BODY.replace("## 手順\n", "## 手順\n---\n")
+        card, parse = build_card(body=body)
+        self.assertEqual([], parse)
+        self.assertEqual("S1", card.front_matter["id"])
+
+    def test_ac_01_rejects_quoted_scalar(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES, title='"見本カード"')) + "\n" + BASE_BODY
+        self.assert_parse_rejects(raw, "スカラーに使えない文字がある")
+
+    def test_ac_01_rejects_duplicate_key(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "id: S1\n", "id: S1\nid: S2\n"
+        ) + "\n" + BASE_BODY
+        self.assert_parse_rejects(raw, "key が重複している")
+
+    def test_ac_01_rejects_key_out_of_canonical_order(self) -> None:
+        order = [k for k in sfm.CANONICAL_KEYS if k in BASE_VALUES]
+        order[0], order[1] = order[1], order[0]
+        self.assert_card_rejects("key の全数か正準順序が契約外", order=order)
+
+    def test_ac_01_rejects_missing_required_key(self) -> None:
+        values = {k: v for k, v in BASE_VALUES.items() if k != "priority"}
+        self.assert_card_rejects("key の全数か正準順序が契約外", values=values)
+
+    def test_ac_01_rejects_unknown_key(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "---\nid: S1\n", "---\nid: S1\nowner: kento\n"
+        ) + "\n" + BASE_BODY
+        self.assert_parse_rejects(raw, "この文法に無い key: owner")
+
+    def test_ac_01_rejects_blank_and_comment_line(self) -> None:
+        for injected, needle in (
+            ("\n", "前付けに空行がある"),
+            ("# コメント\n", "key: value の形でない"),
+        ):
+            with self.subTest(injected=injected):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "id: S1\n", "id: S1\n" + injected
+                ) + "\n" + BASE_BODY
+                self.assert_parse_rejects(raw, needle)
+
+    def test_ac_01_rejects_missing_delimiter(self) -> None:
+        for raw, needle in (
+            # 1 行目が `---` でない
+            (render_front_matter(dict(BASE_VALUES))[4:] + "\n" + BASE_BODY, "1 行目が"),
+            # 閉じる `---` が無い
+            (render_front_matter(dict(BASE_VALUES))[:-4] + "\n" + BASE_BODY, "で閉じていない"),
+        ):
+            with self.subTest(raw=raw[:20]):
+                self.assert_parse_rejects(raw, needle)
+
+    def test_ac_01_rejects_malformed_key_value_separator(self) -> None:
+        """`key: value` (半角コロン + 半角空白 1 つ) 以外を認めないこと (A2)。"""
+        for broken, needle in (
+            ("id:S1", "半角コロンの後に半角空白 1 つが要る"),
+            ("id:  S1", "スカラーの前後に空白がある"),
+            ("id : S1", "key の書式が契約外"),
+            ("id S1", "key: value の形でない"),
+        ):
+            with self.subTest(broken=broken):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "id: S1", broken, 1
+                ) + "\n" + BASE_BODY
+                self.assert_parse_rejects(raw, needle)
+
+    def test_ac_01_rejects_malformed_key_syntax(self) -> None:
+        """key が `^[a-z][a-z0-9_]*$` でないこと (A3)。"""
+        for broken in ("Id: S1", "1id: S1", "id-x: S1", "-: S1"):
+            with self.subTest(broken=broken):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "id: S1", broken, 1
+                ) + "\n" + BASE_BODY
+                self.assert_parse_rejects(raw, "key の書式が契約外")
+
+    def test_ac_01_rejects_malformed_array_syntax(self) -> None:
+        """配列は `[]` か `[a, b]` だけで、区切りの揺れとネストを認めないこと (A4)。"""
+        for broken, needle in (
+            ("accounts: [guest,owner]", "配列の区切りが"),      # 区切りに空白が無い
+            ("accounts: [guest ,owner]", "配列の区切りが"),     # 要素の後ろに空白
+            ("accounts: [ guest]", "スカラーの前後に空白がある"),  # 要素の前に空白
+            ("accounts: [[guest]]", "スカラーに使えない文字がある"),  # ネスト
+            ("accounts: guest", "配列が角括弧で囲まれていない"),   # 角括弧が無い
+            ("accounts: [guest", "配列が角括弧で囲まれていない"),  # 閉じていない
+        ):
+            with self.subTest(broken=broken):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "accounts: [guest]", broken, 1
+                ) + "\n" + BASE_BODY
+                self.assert_parse_rejects(raw, needle)
+
+    def test_ac_01_rejects_yaml_structures(self) -> None:
+        """複数行スカラー・アンカー・参照・フローマップ・ネストマップを認めないこと (A5)。
+
+        ★ これらを「素のスカラーとして黙って受ける」と、A5 を守っているとは言えなくなる
+          (値としては読めてしまうため)。読み取り器が構造記号を値から締め出すことで閉じる。
+        """
+        for broken, needle in (
+            ("title: |", "値の先頭に YAML の構造記号がある"),          # 複数行スカラー (リテラル)
+            ("title: >", "値の先頭に YAML の構造記号がある"),          # 複数行スカラー (畳み込み)
+            ("title: &anchor 見本カード", "値の先頭に YAML の構造記号がある"),  # アンカー
+            ("title: *anchor", "値の先頭に YAML の構造記号がある"),     # 参照
+            ("title: {a: b}", "スカラーに使えない文字がある"),          # フローマップ (`:` で落ちる)
+            ("setup: [&anchor 準備する]", "値の先頭に YAML の構造記号がある"),  # 配列要素の先頭
+        ):
+            with self.subTest(broken=broken):
+                target = "setup: []" if broken.startswith("setup") else "title: 見本カード"
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    target, broken, 1
+                ) + "\n" + BASE_BODY
+                self.assert_parse_rejects(raw, needle)
+
+        # ネストマップ (字下げした続き行) は key の書式で落ちる。
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "title: 見本カード", "title: 見本カード\n  nested: value", 1
+        ) + "\n" + BASE_BODY
+        self.assert_parse_rejects(raw, "key の書式が契約外")
+
+    def test_ac_01_accepts_structural_chars_inside_values(self) -> None:
+        """構造記号は**先頭以外**なら使えること (読み取り器が README より狭くならない)。"""
+        for value in ("R&D の手順", "横幅 * 高さを確認する", "入力 > 出力"):
+            with self.subTest(value=value):
+                self.assertEqual([], parse_violations(
+                    render_front_matter(dict(BASE_VALUES, title=value))
+                    + "\n" + BASE_BODY.replace("# S1: 見本カード", f"# S1: {value}")
+                ))
+
+    def test_ac_01_rejects_key_outside_type_sets(self) -> None:
+        """正準 key なのに型集合へ登録し忘れた形を黙ってスカラーにしないこと (fail-closed)。"""
+        self.assert_parse_rejects("---\nghost: x\n---\n", "この文法に無い key: ghost")
+        original = sfm.CANONICAL_KEYS
+        sfm.CANONICAL_KEYS = (*original, "ghost")
+        try:
+            self.assert_parse_rejects(
+                "---\nghost: x\n---\n", "どの型集合にも登録されていない key である"
+            )
+        finally:
+            sfm.CANONICAL_KEYS = original
+
+    # ----------------------------------------------------------------- #
+    # AC-02: 閉じた語彙と値の書式
+    # ----------------------------------------------------------------- #
+    def test_ac_02_accepts_real_cards_vocabulary(self) -> None:
+        for card in self.cards:
+            with self.subTest(card=card.filename):
+                self.assertIn(card.front_matter["lane"], sfm.LANE_VOCABULARY)
+                self.assertIn(card.front_matter["priority"], sfm.PRIORITY_VOCABULARY)
+                self.assertIn(card.front_matter["applicability"], sfm.APPLICABILITY_VOCABULARY)
+
+    def test_ac_02_rejects_unknown_lane(self) -> None:
+        self.assert_card_rejects("未知の lane", values=dict(BASE_VALUES, lane="serial"))
+
+    def test_ac_02_rejects_unknown_priority(self) -> None:
+        self.assert_card_rejects("未知の priority", values=dict(BASE_VALUES, priority="P0"))
+
+    def test_ac_02_rejects_unknown_account(self) -> None:
+        self.assert_card_rejects(
+            "未知の accounts トークン", values=dict(BASE_VALUES, accounts=["photographer"])
+        )
+
+    def test_ac_02_rejects_zero_padded_id(self) -> None:
+        self.assert_card_rejects(
+            "id の書式が契約外",
+            values=dict(BASE_VALUES, id="S01"), body=BASE_BODY.replace("# S1: ", "# S01: "),
+        )
+
+    def test_ac_02_rejects_non_boolean_reseed(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "reseed_before: true", "reseed_before: yes"
+        ) + "\n" + BASE_BODY
+        self.assert_parse_rejects(raw, "真偽値が true / false でない")
+
+    # ----------------------------------------------------------------- #
+    # AC-03: 命名・id の一意性・欠番
+    # ----------------------------------------------------------------- #
+    def test_ac_03_accepts_real_card_naming(self) -> None:
+        self.assertEqual([], graph_violations(self.cards))
+
+    def test_ac_03_rejects_gap_in_card_numbers(self) -> None:
+        first, _ = build_card(filename="S1-a.md")
+        third, _ = build_card(
+            values=dict(BASE_VALUES, id="S3"),
+            body=BASE_BODY.replace("# S1: ", "# S3: "),
+            filename="S3-c.md",
+        )
+        self.assertNotEqual([], graph_violations([first, third]))
+
+    def test_ac_03_rejects_duplicate_id(self) -> None:
+        first, _ = build_card(filename="S1-a.md")
+        clone, _ = build_card(filename="S1-b.md")
+        self.assertNotEqual([], graph_violations([first, clone]))
+
+    def test_ac_03_rejects_filename_without_id_segment(self) -> None:
+        card, _ = build_card(filename="story-one.md")
+        self.assertNotEqual([], graph_violations([card]))
+
+    # ----------------------------------------------------------------- #
+    # AC-04: 表 A の構造契約と家系必須 11 語
+    # ----------------------------------------------------------------- #
+    def test_ac_04_accepts_surface_vocabulary_table(self) -> None:
+        self.assertEqual([], self.surface_violations)
+        for required in FAMILY_REQUIRED_SURFACES:
+            self.assertIn(required, self.surfaces)
+
+    def test_ac_04_rejects_removed_family_surface(self) -> None:
+        broken = self.readme.replace("| `public_share` |", "| `shared_link` |")
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_wrong_table_header(self) -> None:
+        broken = self.readme.replace("| surface | 面 | 由来 |", "| surface | 面 |")
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_duplicate_surface_row(self) -> None:
+        broken = self.readme.replace(
+            "| `billing` | 課金 | テンプレート同梱 |",
+            "| `billing` | 課金 | テンプレート同梱 |\n| `billing` | 課金 (写し) | テンプレート同梱 |",
+        )
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_reversed_markers(self) -> None:
+        """END が BEGIN より前にある区間を通さないこと。"""
+        broken = self.readme.replace(
+            f"<!-- {SURFACE_MARKER}:BEGIN -->", "@@BEGIN@@", 1
+        ).replace(
+            f"<!-- {SURFACE_MARKER}:END -->", f"<!-- {SURFACE_MARKER}:BEGIN -->", 1
+        ).replace("@@BEGIN@@", f"<!-- {SURFACE_MARKER}:END -->", 1)
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_blank_line_layout_change(self) -> None:
+        """空行の配置も契約であること (区間直後の空行を削る / 表の中に空行を挟む)。"""
+        for broken in (
+            self.readme.replace(
+                f"<!-- {SURFACE_MARKER}:BEGIN -->\n\n| surface",
+                f"<!-- {SURFACE_MARKER}:BEGIN -->\n| surface",
+                1,
+            ),
+            self.readme.replace(
+                "| `billing` | 課金 | テンプレート同梱 |",
+                "| `billing` | 課金 | テンプレート同梱 |\n",
+                1,
+            ),
+        ):
+            with self.subTest(broken=broken[:0]):
+                _, violations = surface_vocabulary(broken)
+                self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_non_canonical_separator_row(self) -> None:
+        """区切り行は各セルがちょうど `---` であること。"""
+        broken = self.readme.replace(
+            f"<!-- {SURFACE_MARKER}:BEGIN -->\n\n| surface | 面 | 由来 |\n|---|---|---|",
+            f"<!-- {SURFACE_MARKER}:BEGIN -->\n\n| surface | 面 | 由来 |\n|-|-|-|",
+            1,
+        )
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_prose_line_inside_marker(self) -> None:
+        """区間の中の非表行を読み飛ばさないこと (読み飛ばしを一切しない)。"""
+        broken = self.readme.replace(
+            "| `billing` | 課金 | テンプレート同梱 |",
+            "| `billing` | 課金 | テンプレート同梱 |\nこの語彙はあとで整理する。",
+        )
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    # ----------------------------------------------------------------- #
+    # AC-05: surface の所属と表 B とカードの 1 対 1
+    # ----------------------------------------------------------------- #
+    def inventory_mismatch(self, inventory: list[tuple[str, str]], cards: list[sfm.Card]) -> list[str]:
+        """表 B と実在カードの 1 対 1 を判定する (C5 / D7)。"""
+        violations: list[str] = []
+        declared = dict(inventory)
+        actual = {
+            str(c.front_matter.get("id")): str(c.front_matter.get("surface")) for c in cards
+        }
+        for card_id in sorted(set(actual) - set(declared)):
+            violations.append(f"表 B に載っていないカード: {card_id}")
+        for card_id in sorted(set(declared) - set(actual)):
+            violations.append(f"表 B の行に対応するカードが無い: {card_id}")
+        for card_id in sorted(set(declared) & set(actual)):
+            if declared[card_id] != actual[card_id]:
+                violations.append(f"表 B とカードの surface が違う: {card_id}")
+
+        return violations
+
+    def test_ac_05_accepts_inventory_matching_cards(self) -> None:
+        self.assertEqual([], self.inventory_violations)
+        self.assertEqual([], self.inventory_mismatch(self.inventory, self.cards))
+        for card in self.cards:
+            self.assertIn(card.front_matter["surface"], self.surfaces)
+
+    def test_ac_05_rejects_card_missing_from_inventory(self) -> None:
+        extra, _ = build_card(
+            values=dict(BASE_VALUES, id="S8", surface="result_view"),
+            body=BASE_BODY.replace("# S1: ", "# S8: "),
+            filename="S8-result.md",
+        )
+        self.assertNotEqual([], self.inventory_mismatch(self.inventory, [*self.cards, extra]))
+
+    def test_ac_05_rejects_inventory_row_without_card(self) -> None:
+        broken = self.readme.replace(
+            "| S7 | `authz_boundary` |",
+            "| S7 | `authz_boundary` |\n| S8 | `result_view` |",
+        )
+        inventory, violations = card_inventory(broken)
+        self.assertEqual([], violations)
+        self.assertNotEqual([], self.inventory_mismatch(inventory, self.cards))
+
+    def test_ac_05_rejects_surface_outside_vocabulary(self) -> None:
+        self.assert_card_rejects(
+            "surface が表 A に無い", values=dict(BASE_VALUES, surface="not_registered")
+        )
+
+    def test_ac_05_rejects_inventory_table_with_extra_column(self) -> None:
+        """表 B に lane / priority / depends_on の写しを置けないこと (C4 / E4)。"""
+        broken = self.readme.replace("| id | surface |\n|---|---|", "| id | surface | lane |\n|---|---|---|")
+        _, violations = card_inventory(broken)
+        self.assertNotEqual([], violations)
+
+    # ----------------------------------------------------------------- #
+    # AC-06: 家系固定 (id, surface)
+    # ----------------------------------------------------------------- #
+    def family_pin_actual(self, cards: list[sfm.Card]) -> tuple[tuple[str, str], ...]:
+        return tuple(sorted(
+            (str(card.front_matter["id"]), str(card.front_matter["surface"]))
+            for card in cards
+            if str(card.front_matter.get("id")) in PINNED_IDS
+        ))
+
+    def test_ac_06_accepts_family_surface_pin(self) -> None:
+        """S1 から S7 の (id, surface) を家系で固定する。
+
+        番号は識別子であって意味を持たないが、**既存番号の面を付け替えない**ことが
+        家系固定の本体である (D1 / D2)。検査側のリテラルと完全一致で突き合わせる。
+
+        ★ pin の対象は PINNED_IDS に属するカードだけである。S8 以降を正規の手続き
+          (表 A に面を足し、表 B に 1 行、カードを 1 枚) で足しても落ちない。
+        """
+        self.assertEqual(tuple(sorted(FAMILY_SURFACE_PIN)), self.family_pin_actual(self.cards))
+
+    def test_ac_06_rejects_reassigned_family_surface(self) -> None:
+        # ★ **実カード 7 枚のうち S1 の面だけを差し替える**。カードを減らした集合で比べると
+        #   「6 枚足りない」で落ちてしまい、面の付け替えを検出したことにならない
+        #   (共通規約 (c): 正しい理由で落ちること)。
+        others = [c for c in self.cards if str(c.front_matter.get("id")) != "S1"]
+        self.assertEqual(6, len(others))
+        pin = tuple(sorted(FAMILY_SURFACE_PIN))
+
+        reassigned, _ = build_card(values=dict(BASE_VALUES, id="S1", surface="billing"))
+        self.assertNotEqual(pin, self.family_pin_actual([*others, reassigned]))
+
+        # 正の対照: 面を正しい値へ戻すと一致する (落ちた理由が面の付け替えであることの裏取り)。
+        restored, _ = build_card(values=dict(BASE_VALUES, id="S1", surface="signup_funnel"))
+        self.assertEqual(pin, self.family_pin_actual([*others, restored]))
+
+    # ----------------------------------------------------------------- #
+    # AC-07 / AC-08 / AC-09: 依存と実行方式
+    # ----------------------------------------------------------------- #
+    def two_cards(self, first: dict[str, object], second: dict[str, object]) -> list[sfm.Card]:
+        a, _ = build_card(
+            values=first, body=BASE_BODY.replace("# S1: ", f"# {first['id']}: "),
+            filename=f"{first['id']}-a.md",
+        )
+        b, _ = build_card(
+            values=second, body=BASE_BODY.replace("# S1: ", f"# {second['id']}: "),
+            filename=f"{second['id']}-b.md",
+        )
+
+        return [a, b]
+
+    def test_ac_07_accepts_real_dependencies(self) -> None:
+        self.assertEqual([], graph_violations(self.cards))
+
+    def test_ac_07_rejects_dependency_cycle(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1", depends_on=["S2"], reseed_before=False),
+            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertNotEqual([], graph_violations(cards))
+
+    def test_ac_07_rejects_self_dependency(self) -> None:
+        card, _ = build_card(values=dict(BASE_VALUES, depends_on=["S1"], reseed_before=False))
+        self.assertNotEqual([], graph_violations([card]))
+
+    def test_ac_07_rejects_unknown_dependency(self) -> None:
+        card, _ = build_card(values=dict(BASE_VALUES, depends_on=["S9"], reseed_before=False))
+        self.assertNotEqual([], graph_violations([card]))
+
+    def test_ac_08_accepts_dependency_without_reseed(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1"),
+            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertEqual([], graph_violations(cards))
+
+    def test_ac_08_rejects_reseed_with_dependency(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1"),
+            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=True),
+        )
+        self.assertNotEqual([], graph_violations(cards))
+
+    def test_ac_09_accepts_serial_depending_on_parallel(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1", lane="parallel_browser"),
+            dict(BASE_VALUES, id="S2", lane="serial_parent", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertEqual([], graph_violations(cards))
+
+    def test_ac_09_rejects_parallel_depending_on_serial(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1", lane="serial_parent"),
+            dict(BASE_VALUES, id="S2", lane="parallel_browser", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertNotEqual([], graph_violations(cards))
+
+    # ----------------------------------------------------------------- #
+    # AC-10: not_applicable カードの中身
+    # ----------------------------------------------------------------- #
+    NOT_APPLICABLE_VALUES = {
+        "id": "S1",
+        "title": "見本カード",
+        "surface": "signup_funnel",
+        "lane": "parallel_browser",
+        "priority": "P3",
+        "applicability": "not_applicable",
+        "not_applicable_reason": "本アプリに該当する面が無いため実走しない",
+        "depends_on": [],
+        "reseed_before": False,
+        "accounts": [],
+        "setup": [],
+        "covers_screens": [],
+        "covers_operations": [],
+        "covers_capabilities": [],
+    }
+    NOT_APPLICABLE_BODY = (
+        "# S1: 見本カード\n"
+        "\n"
+        "## 目的\n"
+        "該当面が無いことを記録として残す。\n"
+        "\n"
+        "## 逸脱アイデア (--deviate 時)\n"
+        "- 該当面が生えていないか確認する\n"
+    )
+
+    def test_ac_10_accepts_not_applicable_card(self) -> None:
+        self.assertEqual([], synthetic_violations(
+            values=dict(self.NOT_APPLICABLE_VALUES), body=self.NOT_APPLICABLE_BODY,
+        ))
+
+    def test_ac_10_rejects_steps_in_not_applicable_card(self) -> None:
+        body = self.NOT_APPLICABLE_BODY.replace(
+            "## 逸脱アイデア", "## 手順\n1. 開く\n\n## 逸脱アイデア"
+        )
+        self.assert_card_rejects(
+            "not_applicable のカードに ## 手順 節がある",
+            values=dict(self.NOT_APPLICABLE_VALUES), body=body,
+        )
+
+    def test_ac_10_rejects_reason_on_applicable_card(self) -> None:
+        values = dict(self.NOT_APPLICABLE_VALUES, applicability="applicable")
+        self.assertNotEqual([], synthetic_violations(
+            values=values, body=self.NOT_APPLICABLE_BODY,
+        ))
+
+    def test_ac_10_rejects_missing_reason_on_not_applicable_card(self) -> None:
+        values = {
+            k: v for k, v in self.NOT_APPLICABLE_VALUES.items() if k != sfm.CONDITIONAL_KEY
+        }
+        self.assertNotEqual([], synthetic_violations(
+            values=values, body=self.NOT_APPLICABLE_BODY,
+        ))
+
+    # ----------------------------------------------------------------- #
+    # AC-11: H1 見出しと前付けの機械一致
+    # ----------------------------------------------------------------- #
+    def test_ac_11_accepts_matching_heading(self) -> None:
+        self.assertEqual([], synthetic_violations())
+
+    def test_ac_11_rejects_heading_mismatch(self) -> None:
+        self.assert_card_rejects(
+            "H1 見出しが前付けと一致しない",
+            body=BASE_BODY.replace("# S1: 見本カード", "# S1: 別のタイトル"),
+        )
+
+    def test_ac_11_rejects_missing_heading(self) -> None:
+        self.assert_card_rejects(
+            "H1 見出しが前付けと一致しない", body=BASE_BODY.replace("# S1: 見本カード\n\n", "")
+        )
+
+    # ----------------------------------------------------------------- #
+    # AC-12: 旧メタ節が残っていない
+    # ----------------------------------------------------------------- #
+    def test_ac_12_accepts_real_cards_without_legacy_meta(self) -> None:
+        for card in self.cards:
+            with self.subTest(card=card.filename):
+                for pattern in LEGACY_META_PATTERNS:
+                    for line in card.body.splitlines():
+                        self.assertFalse(line.startswith(pattern), line)
+
+    def test_ac_12_rejects_legacy_meta_section(self) -> None:
+        self.assert_card_rejects(
+            "旧メタ節が残っている",
+            body=BASE_BODY + "\n## このストーリーで消化する screens / operations\n- screens: home\n",
+        )
+
+    def test_ac_12_rejects_legacy_purpose_bullet(self) -> None:
+        for legacy in ("- 前提状態: ゲスト\n", "- 目的: 何かする\n"):
+            with self.subTest(legacy=legacy):
+                self.assert_card_rejects(
+                    "旧メタ節が残っている", body=BASE_BODY.replace("## 目的\n", "## 目的\n" + legacy)
+                )
+
+    # ----------------------------------------------------------------- #
+    # AC-13: covers_* は形だけを見る (実在は目録側)
+    # ----------------------------------------------------------------- #
+    def test_ac_13_accepts_covers_shape(self) -> None:
+        """実在しない route 名でも**形が正しければ**ここでは通ること (B16)。"""
+        values = dict(BASE_VALUES, covers_screens=["not.a.real.route"])
+        self.assertEqual([], synthetic_violations(values=values))
+
+    def test_ac_13_rejects_duplicate_array_element(self) -> None:
+        self.assert_card_rejects(
+            "covers_operations に重複した要素がある",
+            values=dict(BASE_VALUES, covers_operations=["login.store", "login.store"]),
+        )
+
+    def test_ac_13_rejects_malformed_route_token(self) -> None:
+        self.assert_card_rejects(
+            "covers_screens の要素の書式が契約外",
+            values=dict(BASE_VALUES, covers_screens=["Home Page"]),
+        )
+
+    def test_ac_13_rejects_malformed_capability_token(self) -> None:
+        self.assert_card_rejects(
+            "covers_capabilities の要素の書式が契約外",
+            values=dict(BASE_VALUES, covers_capabilities=["auth-1"]),
+        )
+
+    # ----------------------------------------------------------------- #
+    # AC-14: 全数点呼
+    # ----------------------------------------------------------------- #
+    def test_ac_14_accepts_complete_partition(self) -> None:
+        """実データの 58 項目が 3 分類へ過不足なく割れ、採用項目に担い手が居ること。"""
+        self.assertEqual([], partition_violations(
+            ALL_INVARIANTS, ADOPTED, DIFFERENCES, NOT_ADOPTED,
+            (*STORY_SIDE, *INVENTORY_SIDE, *NON_MECHANICAL), EXPECTED_TOTAL,
+        ))
+        # 非機械保証は「保証しないもの」の節と 1 対 1 にする (黙って落とさない)。
+        self.assertEqual(("E5", "G6"), NON_MECHANICAL)
+
+    def test_ac_14_accepts_explicit_subject_to_test_mapping(self) -> None:
+        """stories 側が担う項目が、実在する検査へ**明示的に**紐づいていること。
+
+        ★ 主題名からテスト名を**推測しない**。`AC-01` から作った `test_ac_01` は
+          実際の `test_ac_01_rejects_quoted_scalar` と一致せず、hasattr が常に偽になる。
+        """
+        for key in STORY_SIDE:
+            self.assertIn(key, INVARIANT_TO_SUBJECT, f"{key} に主題が無い")
+            self.assertIn(INVARIANT_TO_SUBJECT[key], SUBJECT_TO_TESTS)
+
+        for subject, names in SUBJECT_TO_TESTS.items():
+            for name in names:
+                self.assertTrue(callable(getattr(self, name, None)), f"{name} が実在しない")
+            self.assertTrue(any("accepts" in n for n in names), f"{subject} に正例が無い")
+            self.assertTrue(any("rejects" in n for n in names), f"{subject} に負例が無い")
+
+    def test_ac_14_rejects_missing_invariant(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1", "A2"), ("A1",), (), (), ("A1",), 2,
+        ))
+
+    def test_ac_14_rejects_duplicate_classification(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), ("A1",), (), ("A1",), 1,
+        ))
+
+    def test_ac_14_rejects_adopted_without_bearer(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), (), (), (), 1,
+        ))
+
+    def test_ac_14_rejects_unknown_bearer_id(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), (), (), ("A1", "Z9"), 1,
+        ))
+
+    def test_ac_14_rejects_wrong_total(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), (), (), ("A1",), 58,
+        ))
+
+    # ----------------------------------------------------------------- #
+    # AC-15: カード本文の確定形
+    # ----------------------------------------------------------------- #
+    def test_ac_15_accepts_canonical_body(self) -> None:
+        self.assertEqual([], synthetic_violations())
+
+    def test_ac_15_rejects_missing_purpose_section(self) -> None:
+        for body in (
+            BASE_BODY.replace("## 目的\n見本のカードである。\n\n", ""),
+            BASE_BODY.replace("## 目的", "## 目的:"),
+        ):
+            with self.subTest(body=body[:40]):
+                self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_duplicate_purpose_section(self) -> None:
+        body = BASE_BODY + "\n## 目的\n2 つ目の目的。\n"
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_empty_purpose_section(self) -> None:
+        body = BASE_BODY.replace("## 目的\n見本のカードである。\n", "## 目的\n\n")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_duplicate_deviation_section(self) -> None:
+        body = BASE_BODY + "\n## 逸脱アイデア (--deviate 時)\n- もう 1 つ\n"
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_empty_deviation_section(self) -> None:
+        body = BASE_BODY.replace("## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n",
+                                 "## 逸脱アイデア (--deviate 時)\n\n")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_missing_deviation_section(self) -> None:
+        for body in (
+            BASE_BODY.replace("## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n", ""),
+            BASE_BODY.replace("## 逸脱アイデア (--deviate 時)", "## 逸脱アイデア"),
+        ):
+            with self.subTest(body=body[-40:]):
+                self.assertNotEqual([], synthetic_violations(body=body))
+
+
+class ReadCardsTest(unittest.TestCase):
+    """候補母集団の作り方 (パターンで発見しない)。"""
+
+    def test_readme_is_excluded_and_others_are_not(self) -> None:
+        """除外は閉じたリテラル集合 1 件だけで、他の `*.md` は全件が候補になること。
+
+        ★ **件数を pin しない**。S8 以降を正規の手続き (表 A に面を足し、表 B に 1 行、
+          カードを 1 枚) で足せることが D7 の契約であり、ここで 7 枚に固定すると
+          AC-06 が S8 を阻害しないよう作ってある意味が消える。
+          母集団の非空は `test_population_is_not_empty`、表 B との 1 対 1 は AC-05 が持つ。
+        """
+        self.assertEqual(frozenset({"README.md"}), sfm.EXCLUDED_FILENAMES)
+        names = {card.filename for card in sfm.read_cards(STORIES_DIR)[0]}
+        self.assertNotIn("README.md", names)
+        self.assertNotEqual(set(), names)
+        self.assertEqual(
+            {p.name for p in STORIES_DIR.glob("*.md")} - sfm.EXCLUDED_FILENAMES, names
+        )
+
+    def test_missing_directory_is_a_read_error(self) -> None:
+        with self.assertRaises(sfm.StoryReadError):
+            sfm.read_cards(STORIES_DIR / "no-such-dir")
+
+
+if __name__ == "__main__":
+    unittest.main()
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 14198914..11d119a2 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 36 件
+登録エントリ: 37 件
 
 ## 記録の原則
 
@@ -717,10 +717,10 @@ ## D14 実行した route の記録をアプリ側の観測器で採る (退避
 
 | 行 | 内容 |
 |---|---|
-| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` |
-| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する |
-| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う |
-| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき |
+| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` / `.claude/skills/app-bug-hunt/coverage/test_correlate.py` |
+| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する。併せて、割当列が複数値になった目録を照合器が取り違えずに読む |
+| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う。割当セルの分解は `test_correlate.py` が値域の両方向で固定する |
+| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき / 家系の正典が割当列の分解を実装したとき |
 | 決めた日 | 2026-08-15 |
 | 決めた人 | 開発者 |
 | 根拠 | T164 |
@@ -733,6 +733,7 @@ ## D14 実行した route の記録をアプリ側の観測器で採る (退避
 | 採取の起動 | 走行中の LLM (探索エージェント) が退避コマンドを呼ぶ | 起動時に `provision` が env で仕込み、以後は無条件 |
 | 遮断された要求の扱い | 通信履歴なので 302/403 も「叩いた」側に残り、後段で除外しきれない | 遮断 middleware より**内側**に置いてあるため、そもそも記録に現れない |
 | 主入力が欠けたとき | 照合器が「全 in_scope を未実行 candidate」として出力し 0 で終わる | **終了コード 3 で落ちる** (worklist を出さない) |
+| 目録の割当列の読み方 (理由 2) | セルをそのままキーにするので `S3 S7` の行は `S3` の finding と一致しない | **セルを検証してから分解**し、各 story へ索引する (単一値の挙動は不変。正典に無い上乗せ = 家系への還流候補) |
 
 ### なぜ正当な差分か(logic-driven)
 
@@ -770,6 +771,18 @@ ### 揃えている不変条件(これは保証し続ける)
 - 記録器が既定 no-op であること (env 既定 false + production 除外) と ok/blocked の写像は
   `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` が実 HTTP 要求で固定する
 
+理由 2 (割当列の分解) が揃え続けるのは次である。
+
+> 「**目録の割当列に載ったカードは、すべてその finding の索引先になる**」
+
+- 割当セルの値域 (`S{n}` を番号の昇順で半角空白 1 つ区切り、または `-`) は
+  書き出し側 (`scripts/bug-hunt-inventory.py`) が自分の出力を突き合わせ、
+  読み手 (`correlate.py`) が `fullmatch` で強制する。**寛容に正規化しない**
+- 契約外のセル (前後空白 / 連続空白 / 降順 / 重複 / 未知の綴り) は照合器が
+  **終了コード 3** で落ちる (目録の手編集と生成器の故障を黙って進めない)
+- 両側の定数が一致することと、生成側が書くセルを読み手が同じ値へ分解することは
+  `scripts/tests/test_bug_hunt_inventory.py` が同一ケースの列挙で固定する
+
 ### 保証しないもの (誇張しない)
 
 - **web グループ外は観測しない** (`api/*` / Filament `/admin` / MCP)。分母に載っていれば
@@ -777,6 +790,9 @@ ### 保証しないもの (誇張しない)
 - **部分欠測は検出しない**。分かるのは「名前付き route の行が 1 件も無い」「別 run が混ざった」
   「失敗マーカーが残せた」まで
 - **偽造耐性は無い**。記録ファイルは worktree 内にあり、書き換えを検出する仕組みは持たない
+- 割当セルに書かれたカードが**実在するか**は照合器では見ない (目録は生成物であり、
+  割当列は実在するカードの前付けからしか作られない。手編集で紛れ込んだ id は
+  目録の byte 一致検査が落とす)
 
 ### 関連
 
@@ -1134,7 +1150,7 @@ ## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 
 
 | 行 | 内容 |
 |---|---|
-| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` |
+| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` / `scripts/tests/test_bug_hunt_inventory.py` / `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` / `.claude/skills/app-bug-hunt/SKILL.md` / `scripts/README.md` |
 | 業務要件起因の説明 | 機能カタログの id 列が所見記録の語彙の正本であり、Python ツールを標準ライブラリだけで書く規約から注釈は TOML になる |
 | 揃え続ける不変条件と保証機構 | 目録は実装と注釈から再生成でき、ずれていたら CI が落ちる。`BugHuntInventoryCheckInvariantTest` と生成器の自己テストが 4 段の判定を固定する |
 | 再判定の条件 | 家系の正典が id 列を持つ形へ変わったとき / Python に依存を足す裁定が出たとき / 中間 JSON を読む道具が家系に現れたとき |
@@ -1153,6 +1169,9 @@ ## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 
 | 機能カタログ (`capability-catalog.md`) | 生成物。3 列は 機能 / 対応する画面 / 対応する操作 | **生成しない**。3 列は `id` / `機能 (actor→outcome)` / `代表機構 (route name)` を維持し、参照整合だけを検査する |
 | 注釈ファイル | `inventory/annotations.yaml` | **`inventory/annotations.toml`** |
 | 中間成果物 | `inventory/inventory.json` をコミットする | **持たない** (生成・検査の実行中にだけ存在する) |
+| 割当の正本 | カードの前付け (`covers_screens` / `covers_operations`) | **同じ** (2026-08-23 に注釈の `story` を撤去して一本化した。以前は注釈側が正本だった) |
+| `covers_screens` の母集合 | `kind` が `screen` / `read` / `redirect` の web route | **safe method (GET / HEAD / OPTIONS) の web route** (`kind` の語彙が `画面` / `JSON` で違うため `kind` に依存させない) |
+| `covers_capabilities` の検査 | 実在 / 欄の意味 / 分母 / 被覆の 4 段 | **実在・形・一意まで** (機能カタログが継承宣言の欄 `no_route` / `coverage_surface` / `covered_via` を持たないため、分母・被覆は見ない) |
 
 ### なぜ正当な差分か (logic-driven)
 
@@ -1178,9 +1197,11 @@ ### 揃えている不変条件 (これは保証し続ける)
 | 不変条件 | 担い手 |
 |---|---|
 | 抽出が成功し、宣言した抽出条件で走り、母集合が 0 件でないこと (段 1) | `scripts/bug-hunt-inventory.py` (exit 2) / `scripts/tests/test_bug_hunt_inventory.py` |
-| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない |
+| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない。割当を注釈へ書き戻す道は未知の項目として塞ぐ |
+| 対象内 (区分が `外` でない) の route が 1 枚以上のカードの `covers_*` に載っていること (段 2) | 同上 (exit 3)。載せた route の実在・欄の意味・対象外でないことも見る |
 | 生成物が再生成の結果と byte 一致すること (段 3) | 同上 (exit 3)。手編集と再生成の忘れをまとめて捕まえる |
 | 機能カタログの代表機構が実在し、id が重複しないこと (段 4) | 同上 (exit 3) |
+| カードが挙げる capability が実在すること (段 4) | 同上 (exit 3)。**被覆漏れは見ない** |
 | 検査シェルが判定を持たず、終了コード 0 / 2 / 3 を実際に返すこと | `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` (sandbox 実走) |
 | 生成器の自己テストが `composer test` の下で実走すること | `tests/Architecture/BughuntInventoryToolSelfTest.php` |
 | 抽出コマンドが事実だけを書き出すこと (面の判定を持たない) | `tests/Feature/Bughunt/InventoryScanCommandTest.php` |
@@ -1194,13 +1215,23 @@ ### 揃えている不変条件 (これは保証し続ける)
 必ず目録に入り注釈を要求される。
 注釈の**内容**の妥当性 (割当が適切か) は見ない。画面題名の欠落も検出しない。
 機能カタログの網羅性も見ない (代表機構の実在と id の一意性まで)。
+**割当が痩せたこと**も検出できない — 見るのは「1 枚以上のカードに載っていること」だけなので、
+ある route が `S3 S7` から `S3` へ減っても緑のままである (PR レビューの義務)。
 目録の母集合は T164 の記録器が観測しうる route の**部分集合**であり、両者は一致しない。
 
+**対象パスに運用文書 2 本を含める理由 (範囲を誇張しない)**: `.claude/skills/app-bug-hunt/SKILL.md` と
+`scripts/README.md` は本エントリで**目録の生成方式に関わる記述だけ**を説明する
+(どこを直して再生成するか / 割当の正本はどこか)。両ファイルには本エントリと無関係な
+テンプレート差分も含まれうるが、それらは本エントリが説明したことにはならない。
+2026-08-23 に採用時債務一覧から本エントリへ移した (割当の正本を一本化したのに、
+運用手順が廃止済みの入力先へ誘導したままになるのを避けるため)。
+
 ### 再検討の条件 (解消条件)
 
 - 家系の正典が id 列を持つ形へ変わったとき (機能カタログの生成を採り直す)
 - 本リポジトリの Python に依存を足す裁定が出たとき (注釈を YAML へ寄せる)
 - 中間 JSON を読む道具が家系に現れたとき
+- 機能カタログが継承宣言の欄を持つ形になったとき (`covers_capabilities` の被覆判定を採り直す)
 
 ### 関連
 
@@ -2259,3 +2290,79 @@ ### 関連
 
 - 実装: `tests/Architecture/PasskeyPackageContractTest.php`
 - 設計: `devnotes/20260821-2015-auth-method-change-notification/`
+
+---
+
+## D40 シナリオカードの前付けは採るが、ステップ表の書式は採らない
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `.claude/skills/app-bug-hunt/stories/README.md` / `.claude/skills/app-bug-hunt/stories/test_story_front_matter.py` |
+| 業務要件起因の説明 | 所見台帳の finding は story までしか指さず step を指す欄を持たないため、ステップ識別子を入れても読む機械が 1 つも無い |
+| 揃え続ける不変条件と保証機構 | 前付けの制限文法・番号規約・表 A / 表 B との突合は `stories/test_story_front_matter.py` が強制し、`BughuntStoryToolSelfTest` が composer test の配線に載せる |
+| 再判定の条件 | `ledger/findings.schema.json` に step を指す欄が入ったとき / 家系の正典が t2 以降でステップ表を版の名前に含めたとき / `applicability` に `not_applicable` を取るカードを 1 枚でも置くことになったとき |
+| 決めた日 | 2026-08-22 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260823-0022-bughunt-story-front-matter-adoption/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+家系の正典 (機能台帳 `bughunt-story-front-matter` の t1) は、シナリオカードに制限文法の前付けを
+置いて割当の正本にし、併せて**手順をステップ表の書式で書く**ことまでを 1 つの契約にしている。
+本アプリは**前付けは全面的に採る**が、次の 2 点は採らないので登録する。
+
+| 外している契約 | 本アプリの形 |
+|---|---|
+| ステップ表の書式 (正準 4 列ヘッダ `step / 操作 / 期待 / 注目` / 疎な step 識別子 `{id}-{3 桁}` / 副ブロック / 期待欄・注目欄の書き分け) | **散文の番号付きリストのまま**置く |
+| `not_applicable` のカードを実走対象から外す契約 (`SKILL.md` 側が持つ) | **持たない** (該当カードが 0 枚) |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **step 識別子を読む機械が 1 つも無い**。所見台帳の schema
+   (`.claude/skills/app-bug-hunt/ledger/findings.schema.json`) は finding の位置を
+   `story_id` / `route_name` / `capability_tag` で指し、**step を指す欄を持たない**。
+   識別子を振っても照合器・抑制機構・目録のどれもそれで join しないので、
+   増えるのは「振り直してはいけない番号」という保守債務だけである。
+   正典が step を切ったのは finding が step を指す形を前提にしているためで、
+   その前提が本アプリには無い。
+2. **`not_applicable` の実走除外は該当カードが 0 枚である**。本アプリは家系必須 7 面の
+   すべてに実カードがあり、`not_applicable` を取るカードは 1 枚も無い。
+   **読む対象が 1 枚も無い契約を先回りして置かない** (思考原則 2「今必要なものだけ作る」)。
+   置くべき時期は本エントリの再判定の条件が名指ししている — `applicability` に
+   `not_applicable` を取るカードを 1 枚でも置くことになったときである。
+   そのときの置き場は `SKILL.md` (実走の手順の正本) になる。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「**割当の正本はカードの前付けだけであり、前付けは制限文法と番号規約を機械で満たす**」
+
+| 不変条件 | 担い手 |
+|---|---|
+| 前付けの制限文法 (区切り / 1 行 1 項目 / key の書式と重複 / 値の 3 形) | `.claude/skills/app-bug-hunt/stories/story_front_matter.py` |
+| 必須 13 key の全数と正準順序・閉じた語彙・値の書式 | `stories/test_story_front_matter.py` (AC-01 / AC-02) |
+| 番号規約 (命名 / 一意 / 欠番なし / 家系固定の `(id, surface)`) | 同上 (AC-03 / AC-06) |
+| 表 A の構造と家系必須 11 語・表 B とカードの 1 対 1 | 同上 (AC-04 / AC-05) |
+| 依存と実行方式の整合 (実在 / 自己参照 / 循環 / 初期化 / 直列待ち) | 同上 (AC-07 / AC-08 / AC-09) |
+| 本文の確定形と旧メタ節の不在 (二重の正本を残さない) | 同上 (AC-10 / AC-11 / AC-12 / AC-15) |
+| 採用した不変条件の全数点呼 (未割当 0 件・担い手の実在) | 同上 (AC-14) |
+| 上記が `composer test` の下で実走し、検査を削って緑にできないこと | `tests/Architecture/BughuntStoryToolSelfTest.php` (件数の下限 + 中核負例の成功表示) |
+
+### 保証しないもの (誇張しない)
+
+- **ステップ表を採らない帰結**: step 識別子の再採番の禁止・副ブロックの個数・期待欄と注目欄の
+  書き分けは 1 つも検査しない (概念ごと持たない)
+- 兆候番号 (`H{n}`) の意味をカードに書かないことは**文書規約であり機械検査しない**
+  (正典もこれ単独の検査は持たない)
+- `lane` / `depends_on` と `scripts/bug-hunt-shard.sh` の固定 fan-out マップの一致は見ない
+  (固定マップは前付けからの派生キャッシュ。**正典も未達**)
+- `accounts` と `database/seeders/ManualTestSeeder.php` の一致は見ない (正典も同じ)
+- `covers_*` の値の**実在**は前付け側では見ない (形だけ)。実在・欄の意味・分母の被覆は
+  目録側 (D20) の責務である
+
+### 関連
+
+- 実装: `.claude/skills/app-bug-hunt/stories/` (README.md / story_front_matter.py /
+  test_story_front_matter.py / S1〜S7 のカード)
+- gate: `tests/Architecture/BughuntStoryToolSelfTest.php` /
+  `tests/Support/Bughunt/StoryFrontMatterPins.php`
+- 設計: `devnotes/20260823-0022-bughunt-story-front-matter-adoption/`
diff --git a/tests/Support/Bughunt/StoryFrontMatterPins.php b/tests/Support/Bughunt/StoryFrontMatterPins.php
new file mode 100644
index 00000000..02020947
--- /dev/null
+++ b/tests/Support/Bughunt/StoryFrontMatterPins.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Bughunt;
+
+/**
+ * シナリオカードの書式契約の自己テストに対する固定値 (不変の scalar / 配列定数だけを持つ)。
+ *
+ * ★**解析・ファイル I/O・プロセス実行を一切持たない**。値の置き場所を 1 か所にするための型である。
+ *   Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
+ *   固定値はクラス定数として置く (`Tests\Support\TemplateDivergence\LedgerPins` と同じ理由・同じ作法)。
+ * ★**これは免除の一覧ではない**。個別の検査を名指しして無効化する仕組みは本機構のどこにも無い。
+ */
+final class StoryFrontMatterPins
+{
+    /** インスタンス化しない (定数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 活きている検査の件数の下限 (実測値)。
+     *
+     * ★**下限**である (上振れは許す)。減ることだけを禁じ、検査を削って緑にする道を塞ぐ。
+     */
+    public const int MIN_TESTS = 82;
+
+    /**
+     * 中核の負例。名前だけでなく `... ok` の成功表示まで照合して skip 逃げを塞ぐ。
+     *
+     * @var list<string>
+     */
+    public const array CORE_NEGATIVES = [
+        'test_ac_01_rejects_quoted_scalar',
+        'test_ac_01_rejects_duplicate_key',
+        'test_ac_01_rejects_key_out_of_canonical_order',
+        'test_ac_02_rejects_unknown_lane',
+        'test_ac_03_rejects_gap_in_card_numbers',
+        'test_ac_04_rejects_removed_family_surface',
+        'test_ac_05_rejects_card_missing_from_inventory',
+        'test_ac_06_rejects_reassigned_family_surface',
+        'test_ac_07_rejects_dependency_cycle',
+        'test_ac_08_rejects_reseed_with_dependency',
+        'test_ac_09_rejects_parallel_depending_on_serial',
+        'test_ac_10_rejects_steps_in_not_applicable_card',
+        'test_ac_11_rejects_heading_mismatch',
+        'test_ac_12_rejects_legacy_meta_section',
+        'test_ac_13_rejects_duplicate_array_element',
+        'test_ac_15_rejects_missing_purpose_section',
+    ];
+}
```
