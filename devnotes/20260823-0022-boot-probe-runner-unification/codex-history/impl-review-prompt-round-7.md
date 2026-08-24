# Codex 実装レビュー依頼 (impl-review Round 7 / 新セッション (差分のみの最終確認))

## アプリの使命 (North Star) — AGENTS.md より

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項 (自分・Codex 双方に適用) — AGENTS.md より

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


## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたはコードレビュアーとして、Laravel + Svelte アプリ (aicue) の改善実装をレビューする。

**レビュー観点**:

1. 詳細設計との一致性 (過大化・過小化の両方を指摘する)
2. 正確性 (実際に動くか。境界事例・fail-open・偽グリーンの穴)
3. PHPStan level 10 適合性 (ただし本リポジトリの解析対象は app/config/database/routes で tests/ を含まない)
4. DTO / JsonResource パターン (本変更は API を触らないので該当なし)
5. テスト網羅性 (負例・両方向の裏取り・母集団の非空)
6. セキュリティ (資格情報の露出、子プロセスの隔離、fail-closed)
7. AGENTS.md §静的検査 (gate) と走査器の共通規約 5 条 ((a) 完全修飾名 / (b) fail-closed / (c) 負例で裏取り / (d) 使わない走査結果を作らない / (e) 語彙一致はトークン完全一致)
8. DESIGN.md 準拠 / Atomic Design 準拠 (本変更は resources/ を 1 行も触らないので該当なし)

**出力形式**: ファイルごとに判定を書き、指摘は [Critical] / [Warning] / [Suggestion] に分類する。
最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で書く。

**重要な前提 (レビュー範囲)**: 本ラウンドは**新しいセッション**である (前 3 ラウンドの文脈は保持されていない)。
Round 1〜3 の指摘と対応は下に全文を添付する。**Round 3 で唯一残った [Critical] が解消できているか**を
最重要の判定軸にしてほしい。

---

## このラウンドの位置付け

T249 (起動 probe の共通 runner への一元化。変更はすべて tests/ 配下) の実装レビューは Round 6 まで進み、
Round 6 で **マージ阻害は 2 件だけ**と明示された。本ラウンドは**その 2 件への対応だけ**を確認してほしい。
**Round 6 が「解消済み」「マージ阻害としない」と判定した点を蒸し返す必要はない。**

### Round 6 の Codex 返答 (全文)

Round 5 の [Critical] 2件そのものは、実質的に解消しています。ただし、並列実行時の共有ディレクトリ作成競合と、最終形での受入条件未達がマージ阻害です。

## `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

判定: Round 5 の主要指摘は解消。ただし説明と検出範囲に軽微な残件があります。

- G-8 の `behavioural` / `structural` 分離は妥当です。`structural` の集合を完全一致で pin し、その経路について「実際に `.env` を読まない」と主張しないため、以前の「実挙動の裏取りがないのに全経路を安全と表明する」矛盾は解消しています。
- `none` を子入口では通さず、非子入口では `null` と空の根拠を要求する両方向の検査も妥当です。
- G-9 の4トークン完全一致は、Round 5 で挙げた以下の偽グリーンをすべて閉じています。

  - `$unrelated->useEnvironmentPath(...)`
  - `notUseEnvironmentPath`
  - `useEnvironmentPathX`
  - 散文中の `useEnvironmentPath`

- 文字列内部も PHP として再字句解析し、直接コード側と同じ規則を適用したため、共通規約 (c) / (e) を満たしています。
- `$app` という綴りへの固定も、この gate の選択として妥当です。別名は偽陰性ではなく明示的な赤になるため、安全側です。受け手の型を解決しない限界も記載されています。

- [Warning] G-8 の docblock 冒頭は、依然として「子プロセスがリポジトリの `.env` を読んで起動しないこと」と広く表現しています。後段では全経路について主張しないと正しく限定されているため、冒頭も「環境隔離の分類と、実挙動未検証経路の完全一致 pin」程度に合わせるべきです。

- [Warning] 文字列側の走査は「子へ実際に渡される検体文字列」ではなく、申告ファイル内の任意の文字列を対象にします。例えば、実際の検体から退避を削除して未使用の文字列へ次を置いても G-9 は通ります。

  ```php
  $decoy = '$app->useEnvironmentPath($dir);';
  ```

  `structural` が実挙動を保証しないという限定により、これは直ちにセキュリティ主張の破綻にはなりません。ただし「子へ渡す検体ソースの中を検査する」という説明より実際の検出範囲が広いので、未使用文字列も証拠になることを保証外として明記するか、負例で固定するのが望まれます。

## `tests/Architecture/ExternalFakeBootProbeTest.php`

判定: P-17 と既存の実挙動検査は妥当ですが、並列安全性にマージ阻害があります。

- P-17 の完全一致は適切です。P-8 と組み合わせることで、専用ファイルが設定されただけでなく、そのファイル由来の使い捨て値が子で有効になったことも確認できます。
- `$report` の型訂正、P-10d の空ディレクトリ確認、P-16 の正規化負例も妥当です。

- [Critical] P-10d と S11 は、同じ `storage/framework/testing` を別の並列 worker が同時に作成し得ます。両者が存在確認を終えた後、先行 worker が `mkdir()` すると、後続 worker の `mkdir()` は `false` になりテストが失敗します。

  ```php
  for (...) {
      $createdAncestors[] = $candidate;
  }

  foreach (...) {
      expect(mkdir($directory, 0755))->toBeTrue(...);
  }
  ```

  しかも作成処理は `try` の外なので、この競合で失敗した側は自分の後片付けへ入りません。S11 と P-10d は別ファイルにあり、`--parallel` で同時実行され得るため、詳細設計の並列契約を満たしていません。

  各検査専用のランダムな基底を既存のリポジトリ内ディレクトリ直下に作るなど、作成対象そのものを worker 間で共有しない形が必要です。

- [Warning] `is_dir()` → `scandir()` → `rmdir()` も TOCTOU 競合を残します。空確認後に別 worker が書けば `rmdir()` が warning を出し得ます。専用パス化すれば、この問題も同時に解消できます。

## `tests/Unit/Support/Process/BootProbeRunnerTest.php`

判定: S9 と型修正は妥当。S11 の並列競合は修正が必要です。

- S9 の `environmentFilePath()` 完全一致、ファイル非存在、設定値の真偽値確認は妥当です。
- 実 `.env` の解析、秘密値、資格情報 digest に依存しなくなっており、Round 4 の問題は解消しています。
- `$report` の `array<string, mixed>` への訂正も適切です。

- [Critical] S11 は、前項の P-10d と同じ `storage/framework/testing` を共有し、同じ事前列挙→`mkdir()` の競合を持ちます。両ファイルを同時に走らせた場合の負例がありません。共有場所ではなく検査固有の場所を使うべきです。

## `tests/Support/ExternalFakes/FakeWiringProbeRunner.php`

判定: 実装は妥当です。

- 4段の環境合成、使い捨て鍵、外側／内側の一時領域、timeout の fail-closed は設計と一致しています。
- 「一部経路は構造 pin のみ」と記述を訂正したため、idempotency 経路を実挙動検証済みと扱う誤りは解消しています。

- [Warning] `BootProbeRunner` 自身には今も「環境配列が唯一の統制点」という誤解を招く記述が残ります。自己検査は runner の docblock から名指しされているため、今回追加した契約へ辿る経路はできています。SHA 一致を維持する判断を考慮すれば、本変更のマージ阻害とはしません。

## `tests/Support/ExternalFakes/fake-wiring-probe.php`

判定: 問題ありません。

- `env_file_path`、書き出し先、使い捨て鍵の観測は妥当です。
- `environmentFilePath()` 単独はファイル I/O の履歴ではありませんが、P-8 と起動順序を組み合わせているため、この経路の裏取りとして十分です。

## `tests/Support/Process/BootProbeRunner.php`

判定: 実装上の新規問題はありません。

- [Warning] 公開契約の「唯一の統制点」は環境ファイルを含めると不正確です。今回のスコープでは、自己検査と呼び出し側の訂正表へ直接辿れるため、既知の上流申し送りとして許容できます。

## `tests/Support/Process/BootProbeResult.php`

判定: 実装上の新規問題はありません。

- [Warning] `timedOut === true && exitCode === 0` が可能なのに、PHPDoc は強制終了時の終了コードを124と断定しています。今回のコードは `timedOut` を直接判定しており、誤記には依存していません。

## `tests/Support/StrictTypesRuntimeProbe.php`

判定: 問題ありません。

アプリを起動しない strict-types 検体を共通 runner に統合しない判断と、その理由の申告は妥当です。

## 詳細設計書

判定: S6 の Round 5 指摘は反映されていますが、文書全体には更新漏れがあります。

- G-8 / G-9、FQCN 解決、`env_isolation` 分類は記録されており、Round 5 の S6 不一致は解消しています。

- [Warning] S3 は依然として子入口の責務を6件としており、`env_file_path` と P-17を含みません。S4、個別テスト一覧、受入条件にも P-17やG-8/G-9より前の件数表現が残っています。「実装時に確定した事項」だけで補足するのではなく、正本として読む箇所と整合させるべきです。

## 検証証跡

判定: マージ条件を満たしていません。

- 個別テスト、PHPStan、Pint、フロント側の検証は十分です。
- EmailPromotionTest の失敗は、mainでも同条件で再現しており、T249の回帰ではないという判断で問題ありません。
- bug-hunt timeout も、提示された状況からはマシン負荷由来である可能性が高いです。

- [Critical] ただし、Round 5 修正後の最終形では `composer test` が E/F の2回とも完走していません。詳細設計の受入条件は「最終形で2回連続 green」であり、原因がT249外と推定できることは、その条件の達成を代替しません。負荷の落ちた状態で2回連続 green を取得するまで、実装完了・マージ可能とは判定できません。

## 全体判定

Round 5 の [Critical] 2件は解消しています。特に、G-8 の主張範囲の分離とG-9の完全トークン一致は指摘の趣旨を満たしています。

一方で、S11/P-10dには実際の並列作成競合があり、最終形での全体テスト2回連続greenも未取得です。この2点はマージ阻害です。

**CHANGES_REQUESTED**

### Round 6 への対応マトリクス

# 対応マトリクス: impl-review Round 6

Round 6 の Codex 判定は **CHANGES_REQUESTED**。
「Round 5 の [Critical] 2 件は実質的に解消している」と認めたうえで、
**マージ阻害は 2 件だけ**と明示された。両方とも受諾して対応した。

---

## [Critical] S11 と P-10d が共有の `storage/framework/testing` を同時に掘って競合する

- 判断: **対応する** (実在の並列バグである)
- 根拠: 指摘のとおり。両者とも
  「不在の祖先を列挙 → `mkdir()` して `toBeTrue()`」という形で、
  **不在確認と作成の間に他 worker が作れる**。先に作った側が勝ち、後続の `mkdir()` は
  `false` を返して落ちる。しかも作成は `try` の外なので、負けた側は後片付けへ入らない。
  S11 と P-10d は**別ファイル**なので `--parallel` で同時に走りうる。
  詳細設計の「`--parallel` 安全」という前提を満たしていなかった。
- 対応内容 (両方のテスト):
  1. 置き場所を**検査専用の一意な名前**にした —
     `storage/framework/testing/boot-probe-s11-<16 桁>` /
     `storage/framework/testing/fake-wiring-p10d-<16 桁>`。
     葉が専有になるので worker 間で取り合いにならない。
     副次的に、before/after の残骸検査が**他 worker の生成物に汚されなくなる**
     (従来は共有ディレクトリを glob していた)。
  2. 祖先の作成は `@mkdir()` にして、判定を「**作れたか**」から「**在るか** (`is_dir()`)」へ変えた。
     併走する worker が先に作っていても正しく進む。
  3. 後片付けは「掘った分だけ・深い順・空のときだけ」に加えて `@scandir()` / `@rmdir()` にした
     (祖先は共有なので、削除が競合しても検査を落とさない。残るのは元から在ってもおかしくない
     ignored なディレクトリだけである)。

## [Critical] 最終形で `composer test` の 2 回連続 green が取れていない

- 判断: **対応する** (機械的な受入条件である)
- 経緯: E / F は `BughuntSelfTestExecutionTest` が
  「`scripts/bug-hunt-shard.sh self-test` が 120 秒 timeout」で落ちた。
  **同じマシンで別エージェントが別 worktree の全体テストを同時に走らせていた**ための負荷で、
  走行時間も 560 秒 → 929 / 725 秒へ伸びていた (load average 8 超)。
  G / H は走行中に本ラウンドの修正を入れてしまったため、`kill -TERM` で中断した
  (AGENTS.md §worktree 運用ルールの「ロック保持者の pid に `kill -TERM`」に従った)。
- 対応内容: 本ラウンドの修正をすべて入れ終えた**最終形**で、
  targeted 3 本 → 全体 2 本を取り直した。結果は最終報告に記す。

## [Warning] G-8 の docblock 冒頭が広すぎる

- 判断: **対応する**
- 対応内容: 冒頭を
  「G-8: 子入口の**環境ファイル隔離の分類**と、**実挙動が未検証の経路の完全一致 pin**」
  へ改めた (後段の限定と冒頭の言い方を揃えた)。

## [Warning] G-9 の文字列側は「子へ渡される検体」に限定できない (未使用の文字列でも通る)

- 判断: **対応する** (保証外として明記する。検出範囲は狭めない)
- 根拠: 指摘のとおり。ただし「その文字列が実際に子へ渡されるか」は字句走査では追えない。
  狭めようとすると (変数の追跡・呼び出しの解決) 名前解決が要り、
  aicue の走査基盤の外に出る。**拾いすぎる側**なので (b) の許す方向であり、
  `structural` について実挙動を主張していないこととも整合する。
- 対応内容: G-9 の「主張しないこと」に、
  **申告ファイル内の使われていない文字列でも証拠になる**ことを逐語で書いた
  (見本表の「単一引用符の中」の正例がまさにその形であることも併記した)。

## [Warning] 詳細設計の S3 / S4 / 受入条件が P-17・G-8/G-9 より前の記述のまま

- 判断: **対応する**
- 対応内容:
  - S3 の子入口の責務を 6 → **7** へ (7 番目 = 読んだ環境ファイルの絶対パスの報告)。
  - S4 の検査ごとの扱いの表に **P-16 / P-17** の行を足した。
  - 受入条件の個別テスト一覧を `P-1〜P-17` / `G-1〜G-9` へ更新し、
    実装時に追加した検査であることを明記した。

## [Warning] `is_dir()` → `scandir()` → `rmdir()` の TOCTOU

- 判断: **対応する** (上の [Critical] の対応に含まれる)
- 対応内容: 専用パス化で葉の競合は消え、共有の祖先については `@` 付きにして
  競合しても検査を落とさない形にした。

## [Warning] 起動器 (`BootProbeRunner`) の公開契約の「唯一の統制点」記述

- 判断: **見送る** (Codex も「本変更のマージ阻害とはしない」と明記)
- 根拠: 当該ファイルは取得時の sha256 と一致したままの共有ファイルで、直すべき場所は上流である。
  訂正は (a) 起動器が名指ししている自己検査の先頭 (呼び出し側の必須契約) と
  (b) `FakeWiringProbeRunner` の訂正表の 2 か所から辿れる。

## [Warning] `BootProbeResult` の PHPDoc の食い違い

- 判断: **見送る** (Round 2〜6 で同じ判断。呼び出し側は `timedOut` を見ており誤記に依存しない)


---

## 対応の差分 (Round 6 の指摘に対する変更。tests/ 配下 3 ファイル)

```diff
diff --git a/tests/Unit/Support/Process/BootProbeRunnerTest.php b/tests/Unit/Support/Process/BootProbeRunnerTest.php
index eefdd14a..4f856c6b 100644
--- a/tests/Unit/Support/Process/BootProbeRunnerTest.php
+++ b/tests/Unit/Support/Process/BootProbeRunnerTest.php
@@ -23,6 +23,26 @@
 |
 | 測るのは 2 方向である: 「落とせない子を確実に落とす」(S12 / S14) と
 | 「起動前の fail-closed で残骸を残さない」(S11)。
+|
+| ## 呼び出し側の必須契約 (aicue の追記。T249 の実測から)
+|
+| **Laravel を起こす子は、環境ファイルの置き場所を自分で退避しなければならない。**
+| 起動器が締め出すのは `proc_open` へ渡す**プロセス環境**だけで、`.env` の読み込みは止めない。
+| 子の作業ディレクトリはリポジトリ root なので、`bootstrap/app.php` を**素で**読むと
+| Laravel は**リポジトリの `.env` をそのまま**設定へ載せる (実測: DB のパスワードと
+| 実 `CIPHERSWEET_KEY` が子の設定に載った)。起動器の docblock の「統制点は `proc_open` へ渡す
+| 環境配列」という記述は**プロセス環境についてのみ**正しい。
+|
+| 退避の手段は 2 通りで、どちらでもよい:
+|
+|  - **専用の環境ファイルを読ませる** (`tests/Support/ExternalFakes/fake-wiring-probe.php` の形)
+|  - **実在しない場所を指させる** (本ファイルの S9 / S10 の形。一時ディレクトリを環境パスにすると
+|    `safeLoad()` は何も読まない)
+|
+| 契約の遵守は `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` の G-8 / G-9 が
+| 申告と字句で、実挙動は本ファイルの S9 と
+| `tests/Architecture/ExternalFakeBootProbeTest.php` の P-17 / P-8 が測る。
+| **この節は取り込み元 (laravel-claude-template) には無い** — 上流へ還すべき申し送りである。
 */
 
 /** 親 env の漏れを見るための番兵 (S1)。 */
@@ -38,14 +58,45 @@
     echo json_encode(getenv());
     PHP;
 
-/** アプリを起こして書き出し先を JSON で報告させる probe (S9 / S10)。 */
+/**
+ * アプリを起こして書き出し先を JSON で報告させる probe (S9 / S10)。
+ *
+ * ★**aicue のローカル修正 (T249)**: 取り込み元 (laravel-claude-template) の検体は
+ *   `bootstrap/app.php` を素で読むため、**リポジトリの `.env` がそのまま子の設定に載っていた**
+ *   (実測で確認: DB パスワードと実 `CIPHERSWEET_KEY`)。これは正典 v1 (2)
+ *   「開発者ローカルの環境変数を入力集合から外す」を、環境ファイル経由で迂回してしまう。
+ *   そこで**起動前に環境ファイルの置き場所を起動器の一時ディレクトリへ逃がす**。
+ *   一時ディレクトリに `.env` は無いので `safeLoad()` は何も読まず、設定の入力は
+ *   **`proc_open` へ渡した環境配列だけ**になる (= 正典 (2) の統制点が唯一になる)。
+ *   一時ディレクトリの絶対パスは予約鍵 `LARAVEL_STORAGE_PATH` (`<root>/storage`) から導き、
+ *   **取れなければ例外にする** (fail-closed。空文字で `useEnvironmentPath()` を呼ぶと
+ *   退避が無言で外れて `/` を環境ファイルの置き場所にしてしまう)。
+ *   実働は S9 が**無条件に**測る (申告ではなく実挙動) — 読む環境ファイルが
+ *   `<一時ディレクトリ>/.env` と完全一致し実在しないこと (場所) と、環境ファイルからしか
+ *   来ない設定値 2 つが空であること (中身)。**秘密も digest も出力しない**。
+ *   **バイト一致からの意図的な逸脱であり、その理由は上記のとおり
+ *   「セキュリティ不変条件はバイト一致より優先する」である** (AGENTS.md 禁止事項・
+ *   セキュリティ不変条件。詳細は devnotes の実装メモ)。
+ */
 const BOOT_PROBE_PATH_REPORT = <<<'PHP'
     require 'vendor/autoload.php';
     $app = require 'bootstrap/app.php';
+    $storagePath = getenv('LARAVEL_STORAGE_PATH');
+    if (! is_string($storagePath) || $storagePath === '') {
+        throw new RuntimeException('LARAVEL_STORAGE_PATH が無い (環境ファイルの退避先を導けない)');
+    }
+    $app->useEnvironmentPath(dirname($storagePath));
     $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
     Illuminate\Support\Facades\Log::info('boot-probe self check');
     echo json_encode([
         'php_binary' => PHP_BINARY,
+        'env_file_path' => $app->environmentFilePath(),
+        'env_file_exists' => file_exists($app->environmentFilePath()),
+        // 秘密そのものも、その digest も出さない (テスト出力が総当りの検証器になるのを避ける)。
+        // この 2 つの設定値は env からしか来ない (config/ciphersweet.php は既定を持たず、
+        // config/database.php は空文字を既定にする) ので、**非空なら環境ファイルが読まれた**証拠になる。
+        'ciphersweet_key_present' => ((string) config('ciphersweet.providers.string.key')) !== '',
+        'db_password_present' => ((string) config('database.connections.pgsql.password')) !== '',
         'storage' => $app->storagePath(),
         'config_cache' => $app->getCachedConfigPath(),
         'routes_cache' => $app->getCachedRoutesPath(),
@@ -197,15 +248,42 @@ static function (string $key): bool {
 });

(…上は文脈。以下が Round 6 で変えた S11 / P-10d / docblock の該当箇所である)

diff --git a/tests/Architecture/ExternalFakeBootProbeTest.php b/tests/Architecture/ExternalFakeBootProbeTest.php
index 9aecfd03..7aec18d5 100644
--- a/tests/Architecture/ExternalFakeBootProbeTest.php
+++ b/tests/Architecture/ExternalFakeBootProbeTest.php
@@ -395,17 +395,20 @@ static function (string $directory) use (&$created): mixed {
 
 test('P-10d リポジトリ内の置き場所は本体を呼ばずに拒否し、残骸を残さない', function (): void {
     // 正典 v1 (5) の fail-closed を**外側**でも測る (内側は取り込んだ自己検査 S11 が持つ)。
-    $base = base_path('storage/framework/testing');
+    // ★置き場所は**この検査専用の一意な名前**にする。共有の `storage/framework/testing` を
+    //   直接使うと、`--parallel` で同じ場所を掘る別の検査 (`BootProbeRunnerTest` の S11) と
+    //   **作成が競合**する (両者が不在を確かめた後、先に作った側が勝ち、後続が false になる)。
+    $base = base_path('storage/framework/testing/fake-wiring-p10d-'.bin2hex(random_bytes(8)));
 
-    // ★このテストが作った階層を**1 つ残らず**戻す (走行が生成物を残さないため)。
-    //   `mkdir(recursive)` + `rmdir($base)` だけだと、親を新規作成した環境
-    //   (新しい checkout など) で `storage/framework` が残る。
+    // ★このテストが掘った階層を**1 つ残らず**戻す (走行が生成物を残さないため)。
+    //   併走する worker が先に作っていても構わないので、**在ることを確かめる**形にする。
     $createdAncestors = [];   // 深い順
     for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
         $createdAncestors[] = $candidate;
     }
     foreach (array_reverse($createdAncestors) as $directory) {
-        expect(mkdir($directory, 0755))->toBeTrue("後始末の対象を作れない: {$directory}");
+        @mkdir($directory, 0755);
+        expect(is_dir($directory))->toBeTrue("後始末の対象を作れない: {$directory}");
     }
 
     try {
@@ -428,9 +431,20 @@ static function (string $directory) use (&$bodyCalled): mixed {
         expect($bodyCalled)->toBeFalse('リポジトリ内なのに本体が呼ばれた')
             ->and(glob($base.'/fake-wiring-probe-*'))->toBe($before, '拒否経路が残骸を残している');
     } finally {
-        // 深い順に戻す (作った分だけ)。
+        // 深い順に戻す (掘った分だけ)。葉は専有だが**祖先は共有**なので、
+        // 空でなければ他 worker が使っている = 触らずに打ち切る。
+        // 競合で削除に失敗しても検査を落とさない (`@` で警告を抑える)。
         foreach ($createdAncestors as $directory) {
-            rmdir($directory);
+            if (! is_dir($directory)) {
+                continue;
+            }
+
+            $remaining = array_values(array_diff(@scandir($directory) ?: [], ['.', '..']));
+            if ($remaining !== []) {
+                break;
+            }
+
+            @rmdir($directory);
         }
     }
 });
@@ -515,6 +529,24 @@ static function (string $directory) use (&$bodyCalled): mixed {
     expect(fn (): array => $call($make('"scalar"', false, 0)))->toThrow(RuntimeException::class);
 });
 
+test('P-17 環境ファイルの隔離: 子が読んだ環境ファイルが起動側の専用ファイルと完全一致する', function (): void {
+    // ★正典 v1 (2) は「開発者ローカルの環境変数を入力集合から外す」ことを求めるが、
+    //   起動器が締め出すのは**プロセス環境**だけで、`.env` の読み込みは止めない
+    //   (子の作業ディレクトリはリポジトリ root なので、素で起こすとリポジトリの `.env` が載る)。
+    //   本クラスの経路は子入口が `useEnvironmentPath()` / `loadEnvironmentFrom()` で
+    //   専用の 0600 ファイルへ固定するので、**それが実際に効いた**ことをここで測る。
+    // ★配下判定ではなく**完全一致**で測る (期待値は起動側が渡した 2 つの値から一意に決まるので、
+    //   正規化の前提が要らず、これがこの経路で最も強い)。
+    $run = externalFakeProbeRun('fake');
+
+    $expected = $run['directory'].'/'.$run['caseEnvValues']['FAKE_WIRING_PROBE_ENV_FILE'];
+
+    expect($run['output']['env_file_path'] ?? null)->toBe(
+        $expected,
+        '子がリポジトリ側の環境ファイルを読んでいる (専用ファイルへの固定が効いていない)',
+    );
+});
+
 test('P-16 正規化判定の検出力: 正常な絶対パスは通り、`..` / `.` / 相対パスは弾く', function (
     string $path,
     bool $expected,

```

### 変更後の S11 (自己検査) の全文

```php
test('S11: 一時ディレクトリがリポジトリ内なら起動前に失敗し残骸を残さない', function (): void {
    // ★aicue のローカル修正 (T249): 置き場所を**この検査専用の一意な名前**にする。
    //   取り込み元は共有の `storage/framework/testing` を直接使っており、`--parallel` で
    //   同じ場所を掘る別の検査 (`ExternalFakeBootProbeTest` の P-10d) と**作成が競合**した
    //   (両者が不在を確かめた後、先に作った側が勝ち、後続の `mkdir()` が false になる)。
    //   葉を一意にすれば worker 間で取り合いにならず、before/after の残骸検査も
    //   他 worker の生成物に汚されない。
    $base = base_path('storage/framework/testing/boot-probe-s11-'.bin2hex(random_bytes(8)));

    // 掘った階層は**1 つ残らず**戻す (受入条件の「走行が生成物を残さない」)。
    // 併走する worker が先に作っていても構わないので、**作れたか否かではなく在ることを確かめる**。
    $createdAncestors = [];   // 深い順
    for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
        $createdAncestors[] = $candidate;
    }
    foreach (array_reverse($createdAncestors) as $directory) {
        @mkdir($directory, 0o755);
        expect(is_dir($directory))->toBeTrue("後始末の対象を作れない: {$directory}");
    }

    try {
        $before = glob($base.'/boot-probe-*');
        expect($before)->toBeArray();
        assert(is_array($before));

        expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], temporaryBase: $base))
            ->toThrow(RuntimeException::class);

        $after = glob($base.'/boot-probe-*');
        expect($after)->toBe($before, '起動前の fail-closed が残骸を残している');
    } finally {
        // 深い順に戻す (掘った分だけ)。葉は専有だが**祖先は共有**なので、
        // 空でなければ他 worker が使っている = 触らずに打ち切る。
        // 競合で削除に失敗しても検査を落とさない (`@` で警告を抑える) — 残るのは
        // 元から在ってもおかしくない ignored なディレクトリだけである。
        foreach ($createdAncestors as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $remaining = array_values(array_diff(@scandir($directory) ?: [], ['.', '..']));
            if ($remaining !== []) {
                break;
            }

            @rmdir($directory);
        }
    }

    // 境界判定そのものを pin する (`/repo` と `/repository` を取り違えない)。
    expect(BootProbeRunner::isInside('/repo', '/repo'))->toBeTrue()
        ->and(BootProbeRunner::isInside('/repo', '/repo/inner'))->toBeTrue()
        ->and(BootProbeRunner::isInside('/repo', '/repository'))->toBeFalse()
        ->and(BootProbeRunner::isInside('/repo/', '/repo/inner'))->toBeTrue();
});

```

### 変更後の P-10d の全文

```php
test('P-10d リポジトリ内の置き場所は本体を呼ばずに拒否し、残骸を残さない', function (): void {
    // 正典 v1 (5) の fail-closed を**外側**でも測る (内側は取り込んだ自己検査 S11 が持つ)。
    // ★置き場所は**この検査専用の一意な名前**にする。共有の `storage/framework/testing` を
    //   直接使うと、`--parallel` で同じ場所を掘る別の検査 (`BootProbeRunnerTest` の S11) と
    //   **作成が競合**する (両者が不在を確かめた後、先に作った側が勝ち、後続が false になる)。
    $base = base_path('storage/framework/testing/fake-wiring-p10d-'.bin2hex(random_bytes(8)));

    // ★このテストが掘った階層を**1 つ残らず**戻す (走行が生成物を残さないため)。
    //   併走する worker が先に作っていても構わないので、**在ることを確かめる**形にする。
    $createdAncestors = [];   // 深い順
    for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
        $createdAncestors[] = $candidate;
    }
    foreach (array_reverse($createdAncestors) as $directory) {
        @mkdir($directory, 0755);
        expect(is_dir($directory))->toBeTrue("後始末の対象を作れない: {$directory}");
    }

    try {
        $before = glob($base.'/fake-wiring-probe-*');
        expect($before)->toBeArray();

        $bodyCalled = false;

        expect(function () use ($base, &$bodyCalled): mixed {
            return FakeWiringProbeRunner::withEnvironmentDirectory(
                $base,
                static function (string $directory) use (&$bodyCalled): mixed {
                    $bodyCalled = true;

                    return $directory;
                },
            );
        })->toThrow(RuntimeException::class);

        expect($bodyCalled)->toBeFalse('リポジトリ内なのに本体が呼ばれた')
            ->and(glob($base.'/fake-wiring-probe-*'))->toBe($before, '拒否経路が残骸を残している');
    } finally {
        // 深い順に戻す (掘った分だけ)。葉は専有だが**祖先は共有**なので、
        // 空でなければ他 worker が使っている = 触らずに打ち切る。
        // 競合で削除に失敗しても検査を落とさない (`@` で警告を抑える)。
        foreach ($createdAncestors as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $remaining = array_values(array_diff(@scandir($directory) ?: [], ['.', '..']));
            if ($remaining !== []) {
                break;
            }

            @rmdir($directory);
        }
    }
});

```

### G-8 / G-9 の docblock (冒頭の言い方と「主張しないこと」を直した部分)

```php
 * G-8: 子入口の**環境ファイル隔離の分類**と、**実挙動が未検証の経路の完全一致 pin**。
 *
 * ## 何を守っているか
 *
 * 共通の起動器は `proc_open` へ渡す環境配列で開発者ローカルの env を締め出すが、
 * **`.env` ファイルの読み込みまでは止めない**。子の作業ディレクトリはリポジトリ root なので、
 * 子が `bootstrap/app.php` を**素で**読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
 * これは正典 v1 (2) の「開発者ローカルの環境変数を入力集合から外す」を、
 * 環境変数ではなく**環境ファイル**の経路で迂回してしまう形である。
 *
 * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査 S9 / S10 の検体を取り込み元の姿
 * (環境ファイルの置き場所を移さない形) で走らせると、子の設定に `.env` 由来の
 * **DB のパスワードと実 `CIPHERSWEET_KEY`** が載った。外部サービスの資格情報
 * (Stripe / AWS / Google / SMTP) は本チェックアウトではいずれも空だったが、
 * **「空だった」のはこのチェックアウトの性質であって保証ではない。**
 * この実測を受けて S9 / S10 の検体には**起動前に環境ファイルの置き場所を一時ディレクトリへ
 * 逃がす 1 行**を入れた (取り込み元からの意図的な逸脱。理由は当該 docblock)。
 *
 * ## 何を機械で固定しているか
 *
 *  1. `env_isolation` が `none` の子入口は**ちょうど 0 件**である (完全一致 pin)。
 *     退避も裏取りも無い子入口を足すには申告を書き換えることになり、レビューに必ず見える
 *  2. `child_entry` は `env_isolation` を `behavioural` / `structural` のどちらかで申告し、
 *     **根拠の欄 (`env_isolation_proof`) を必ず持つ** (空では通らない)
 *  3. **`structural` の集合は完全一致で pin する** — 実挙動の裏取りが無い経路が
 *     黙って増えないようにするため。**この集合について「実際に `.env` を読まない」とは
 *     主張しない** (下の「主張しないこと」を参照)
 *  4. `child_entry` 以外 (`in_process` / `inventory`) は定義上この分類の対象でないので、
 *     `env_isolation` が `null` であること・根拠が空であることを両方向で固定する
 *     (取り違えの検出)
 *
 * ## 対比 (なぜ同一プロセスは対象外なのか)
 *
 * 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
 * 効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
 * **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
 * これが子と同一プロセスの非対称の正体である。
 *
 * ## 主張しないこと (誇張しない)
 *
 * **「子はリポジトリの `.env` を読まない」を全経路について主張しない。**
 * 主張できるのは `env_isolation: behavioural` の経路だけで、そちらの根拠は本検査ではなく
 * **名指しされた実挙動の検査そのもの**である:
 *
 *  - `tests/Unit/Support/Process/BootProbeRunnerTest.php` の S9 — 子が報告した環境ファイルの
 *    絶対パスが `<一時ディレクトリ>/.env` と完全一致し、そこに実在しないこと
 *  - `tests/Architecture/ExternalFakeBootProbeTest.php` の P-17 / P-8 — 子が報告した
 *    環境ファイルの絶対パスが起動側の専用ファイルと完全一致し、効いた鍵がその中身と一致すること
 *
 * `env_isolation: structural` の経路 (現在 1 件) については
 * **「実際に読まない」とは主張しない** — 分かっているのは「退避の呼び出しが字句として在る」
 * ことだけである (G-9)。呼び出しが**効く位置**に在るかも、他の値を読んでいないかも見ていない。
 *
 * さらに、本検査が機械で確かめるのは**申告と根拠の記載**であって、
 * 名指しした検査が実際に何を測っているかではない。したがって次は本検査を通る:
 *
 *  1. `env_isolation_proof` に**実在はするが何も測っていない**検査名を書く
 *     (実在しない名前は G-9 が落とす)
 *  2. 既存の `child_entry` の中で、`.env` を読む検体を**増やす** (ファイル単位の申告は変わらない)
 */
test('G-8 退避も裏取りも無い子入口は 0 件で、実挙動の裏取りが無い経路は完全一致で pin されている', function (): void {
    $inventory = phpBootProbeAppBootEntryReferenceInventory();

    $childEntries = [];
    $structuralOnly = [];

    foreach ($inventory as $path => $entry) {
        if ($entry['kind'] !== 'child_entry') {
            // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
            //   定義上この分類の対象ではない。取り違えを防ぐために両方向で固定する。
            expect($entry['env_isolation'])
                ->toBeNull("子が居ない経路に env_isolation が申告されている: {$path}")
                ->and(trim($entry['env_isolation_proof']))
                ->toBe('', "子が居ない経路に根拠の記載がある (kind の取り違え): {$path}");

            continue;
        }

        $childEntries[] = $path;

        // ★分類は 2 値のどちらかで、根拠の記載を必ず持つ (申告だけで済ませない)。
        expect(in_array($entry['env_isolation'], ['behavioural', 'structural'], true))
            ->toBeTrue("child_entry の env_isolation が behavioural / structural の外: {$path}")
            ->and(trim($entry['env_isolation_proof']))
            ->not->toBe('', "child_entry に env_isolation の根拠が無い: {$path}");

        if ($entry['env_isolation'] === 'structural') {
            $structuralOnly[] = $path;
        }
    }

    sort($structuralOnly);

    // ★**実挙動の裏取りが無い子入口**の集合を完全一致で pin する。
    //   増やすには申告を書き換えることになり、「なぜ実挙動で測らないのか」がレビューに必ず見える。
    //   減らす (behavioural へ上げる) ときも同じ。
    expect($structuralOnly)->toBe(
        ['tests/Support/Concurrency/idempotency-claim-probe.php'],
        '実挙動の裏取りを持たない子入口が増減している。'
        .'足すなら G-8 の docblock を読み、なぜ実挙動で測れないのかを根拠の欄に書くこと',
    );

    // ★母集団が空のまま緑になる形を塞ぐ (AGENTS.md §静的検査の共通規約 (b) の 3 点目)。
    expect($childEntries)->not->toBe([], 'child_entry が 1 件も無い (走査か申告が壊れている)');
});

 * G-9: `child_entry` は**環境ファイルの退避の呼び出しを字句として持つ** (G-8 の申告への機械の裏打ち)。
 *
 * G-8 が見るのは申告と根拠の記載までである。そこへ**2 つだけ機械の裏打ち**を足す:
 *
 *  1. `child_entry` の申告ファイルは `$app->useEnvironmentPath(` を**トークンの完全一致**で持つ
 *     (実コード、または子へ渡す検体ソースの文字列の中。判定は
 *     `phpBootProbeMentionsEnvironmentPathRelocation()`)。Laravel が読む環境ファイルは
 *     この呼び出しでしか動かないので、**持たない子入口は既定でリポジトリの `.env` を読む**
 *     = 新しい子入口を素直に足すと赤になる
 *  2. `env_isolation_proof` が**検査を名指ししている場合**、その先頭語は
 *     **実在するパス**である (走査母集団の中に在る)。実在しない検査名で申告を通す形を塞ぐ。
 *     `structural` の根拠は検査名ではなく散文なので、この検査は
 *     **`behavioural` の entry にだけ**適用する
 *
 * **主張しないこと**:
 *
 *  - 呼び出しが**実際に効く位置** (アプリ起動より前) に在ること。字句では決められないので、
 *    位置の正しさは実挙動の検査 (`BootProbeRunnerTest` の S9 /
 *    `ExternalFakeBootProbeTest` の P-17) が担う
 *  - **受け手が本当に Laravel の Application であること**。変数の型は字句では解決できないので、
 *    受け手は**綴り (`$app`) で固定している**。別名で受ける子入口は赤になる (拾いすぎない側)
 *  - 名指しした検査が**実際に何を測っているか** (実在の確認までである)
 *  - 文字列側の判定は「**子へ実際に渡される**検体ソース」に限定できない。
 *    申告ファイルの中の**使われていない文字列**に同じ 4 トークンを置いても通る
 *    (見本表の「単一引用符の中」の正例がまさにその形である)。字句走査で
 *    「その文字列が子へ渡されるか」を追うことはできないので、ここは**保証外**にしてある。
 *    `structural` の経路について実挙動を主張していないのはこのためでもある
 */
test('G-9 child_entry は退避の呼び出しを字句として持ち、behavioural の名指しは実在パスである', function (): void {
    $sources = phpBootProbeTestSources();
    $childEntries = 0;

    foreach (phpBootProbeAppBootEntryReferenceInventory() as $path => $entry) {
        if ($entry['kind'] !== 'child_entry') {
            continue;
        }

        $childEntries++;

        expect($sources)->toHaveKey($path);
        expect(phpBootProbeMentionsEnvironmentPathRelocation($sources[$path]))
            ->toBeTrue(
                "child_entry が環境ファイルの退避 (\$app->useEnvironmentPath( ) を持っていない: {$path}"
            );

        if ($entry['env_isolation'] !== 'behavioural') {
            // `structural` の根拠は検査名ではなく散文なので、実在確認の対象にしない。
            continue;
        }

        // 名指しは「パス + 括弧つきの説明」の形なので、先頭語をパスとして見る。
        $named = strtok(trim($entry['env_isolation_proof']), " \t");
        expect(is_string($named) ? $named : '')->not->toBe('', "env_isolation_proof が空: {$path}");
        expect(array_key_exists((string) $named, $sources))
            ->toBeTrue("env_isolation_proof が実在しない検査を名指ししている: {$path} => {$named}");
    }

    // 母集団が空のまま緑になる形を塞ぐ。
    expect($childEntries)->toBeGreaterThan(0, 'child_entry が 1 件も無い (走査か申告が壊れている)');
});

```

---

## 検証コマンドの実測 (Round 6 の修正を入れた最終形。**すべて取り直した**)

```
composer test (--parallel --processes=4) — **2 回連続 green**:
  run I: passed  7467 tests / 7465 passed / 0 failed / 2 skipped / 5 risky  (546.1 秒)
  run J: passed  7467 tests / 7465 passed / 0 failed / 2 skipped / 5 risky  (546.8 秒)

個別 (単独実行、いずれも green):
  tests/Unit/Support/Process/BootProbeRunnerTest.php        : 14 / 14 (78 assertions)
  tests/Architecture/ExternalFakeBootProbeTest.php          : 33 / 33 (148 assertions)
  tests/Architecture/PhpBootProbeReferenceInventoryTest.php : 62 / 62 (208 assertions)

composer phpstan (level 10): [OK] No errors
vendor/bin/pint --test: passed
pnpm lint / typecheck / build / typecheck:packages / build:packages: green
pnpm test: 179 files / 2398 tests passed   /   pnpm test:packages: 10 files / 106 tests passed
  (JS は 1 行も触っていないので Round 5 時点の結果をそのまま採る)

取り込み実装 2 本の sha256 は取得時の記録値と一致したまま:
  bd21b337…  tests/Support/Process/BootProbeRunner.php
  00b14167…  tests/Support/Process/BootProbeResult.php
```

**参考 (Round 6 で「T249 の回帰ではない」と判定済み)**: 全体走行が赤くなる既知の flake は
`tests/Feature/Auth/EmailPromotionTest.php` (main 側 T253 由来) で、main の作業ツリーでも
`vendor/bin/pest tests/Feature/Admin tests/Feature/Auth/EmailPromotionTest.php` で同一 2 件が再現する。
run I / run J はいずれもこの flake を踏まずに完走した。

---

## 判定してほしいこと

1. Round 6 の [Critical] 2 件 (S11 / P-10d の並列作成競合 / 最終形での 2 回連続 green) が
   解消しているか
2. 並列競合の直し方 (置き場所を検査専用の一意名にし、`mkdir` の判定を「作れたか」から
   「在るか」へ、後片付けは空のときだけ・`@` 付きで競合しても落とさない) に穴が無いか
3. **この実装を main へマージしてよいか。** 残る指摘があるなら、それが**マージ阻害かどうか**を明記すること

**全体判定を APPROVED / CHANGES_REQUESTED で明記すること。**
