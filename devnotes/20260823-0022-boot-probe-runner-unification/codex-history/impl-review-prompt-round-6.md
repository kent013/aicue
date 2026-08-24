# Codex 実装レビュー依頼 (impl-review Round 6 / 新セッション)

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

## 経緯 (Round 6 も新しいセッションなので文脈は保持されていない)

T249 は「起動 probe の共通 runner への一元化 (家系正典 v1 追従)」。変更はすべて tests/ 配下で、
アプリコード (app/ routes/ config/ database/ bootstrap/) は 1 バイトも変更していない。
Round 1〜3 の指摘は対応済み。Round 4 で「取り込んだ自己検査の子がリポジトリの .env を読む」を解消し、
Round 5 で「実挙動テストの偽グリーンと資格情報の扱い」を直した。**本ラウンドは Round 5 の全指摘への対応を見てほしい。**

### Round 5 の Codex 返答 (全文)

結論として、Round 4 の最重要問題のうち S9 関連の2件は解消しています。一方、`idempotency-claim-probe.php` の裏取り不足は、主張を狭め切れていないため未解消です。

## `tests/Unit/Support/Process/BootProbeRunnerTest.php`

判定: 概ね妥当。ただし後片付けに残件あり。

- 未正規化パスを `isInside()` に渡していた問題は解消しています。`$result->temporaryRoot.'/.env'` との完全一致は、この経路で最も強い検査です。
- 実 `.env` の解析と資格情報 digest を廃止し、真偽値だけを報告する変更も妥当です。秘密や推測用 verifier は出力されず、`.env` の有無にも依存しません。
- 退避なし／ありの負の裏取りも、4項目すべてが反転しており十分です。

- [Warning] S11 は `mkdir($base, ..., true)` で作られた祖先をまだ戻していません。`storage/framework` 自体が存在しない環境では、最後に削除するのは `storage/framework/testing` だけで、作成した親が残ります。P-10d と同じ `$createdAncestors` 方式が必要です。Round 4 のこの指摘は完全には解消していません。

- [Suggestion] `$report` の PHPDoc が `array<string, string>` のままですが、追加された3項目は bool です。PHPStan対象外でも、実態に合わせて `array<string, mixed>` またはshapeへ直す方が安全です。

## `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

判定: 修正が必要です。

- [Critical] `idempotency-claim-probe.php` について、実挙動の裏取りがないまま、G-8 は「リポジトリ `.env` を読む子は0件」という不変条件を主張しています。

  スコープ境界として `ConcurrentProbeObservation` を変更しない判断自体は妥当です。しかしその場合、同経路を「実挙動で裏取り済み」と同じ集合に入れることはできません。現在は次の3点が互いに矛盾しています。

  - `behaviour_proof` の定義は「実挙動で裏取りしている検査」
  - 当該entryは「環境ファイル切り替えを直接測る検査は無い」と明記
  - G-8は全child entryについて0件を不変条件として表明

  対応はどちらかです。

  - 実効 `environmentFilePath()` の完全一致を子→DTO→親まで実測する
  - `behaviorally_verified` と `structurally_pinned` を分け、後者について「実際に読まない」とは主張しない

- [Critical] `phpBootProbeMentionsEnvironmentPathRelocation()` はG-9の証拠として偽グリーンになります。

  名前トークン側では、受け手を問わないため次でも通ります。

  ```php
  $unrelated->useEnvironmentPath($dir);
  ```

  文字列側は `str_contains()` なので、次も通ります。

  ```php
  '$app->notUseEnvironmentPath($dir);'
  'useEnvironmentPath is required'
  '$app->useEnvironmentPathX($dir);'
  ```

  存在を肯定する証拠として使う走査では、拾いすぎは安全側ではありません。G-6が未解決の受け手を証拠に数えないのと同じ理由です。

  また、文字列トークン側の素の部分文字列一致は共通規約(e)を満たしません。接頭辞・打ち消し・接尾辞の負例は名前トークンにしか置かれておらず、文字列分岐を裏取りできていません。

  したがってG-9は現状、共通規約のうち以下が不十分です。

  - (b): 意味を解決できない呼び出しを退避の証拠として採用
  - (c): 文字列分岐の負例が不足
  - (e): 文字列内をトークン完全一致ではなく部分文字列で判定

  (d)の「収集結果を判定に使う」と母集団非空は満たしています。

- [Warning] `fake-wiring-probe.php` の `behaviour_proof` に指定したP-8も、専用ファイル由来のCiphersweet鍵が効いたことは示しますが、`environmentFilePath()` 自体や「他の `.env` 値を一切読んでいないこと」は直接測りません。G-8の広い主張に使うなら、S9同様のパス完全一致が適切です。

G-8のテスト名を「申告上」へ直した点と、名指し先の実在確認は改善されています。ただし実在確認は実挙動の証明ではありません。

## `tests/Support/ExternalFakes/FakeWiringProbeRunner.php`

判定: 実装本体は妥当です。

- 4段の環境合成、使い捨て鍵、外側／内側一時ディレクトリ、timeoutのfail-closedは設計どおりです。

- [Warning] 汎用runnerの必須契約をこの特定呼び出し側だけに記述しても、`BootProbeRunner` の公開契約そのものは訂正されません。また「各経路の実挙動の検査が守る」とありますが、idempotency経路にはその検査がありません。バイト一致を維持する判断は理解できますが、少なくとも記述は「一部経路は構造pinのみ」と合わせる必要があります。

## `tests/Support/Process/BootProbeRunner.php`

判定: 実装上の新規問題なし。

- [Warning] 「ここが開発者ローカルenvを締め出す唯一の統制点」は、環境ファイルを含む一般的な説明としては依然不正確です。別ファイルの訂正表ではなく、このクラスから直接たどれる契約にするのが望まれます。

## `tests/Support/Process/BootProbeResult.php`

判定: 実装上の新規問題なし。

- [Warning] 既出のとおり、`timedOut === true && exitCode === 0` が可能なのに「強制終了なら124」とするPHPDocは実装と一致していません。今回の呼び出し側は誤記に依存していません。

## `tests/Architecture/ExternalFakeBootProbeTest.php`

判定: P-7、P-10c、P-11、P-13〜P-16は妥当です。

- [Warning] P-10dの祖先削除は、並列workerが途中で同じ場所へ書いた場合にも無条件で`rmdir()`します。S11では「空なら削除」という配慮があるのに、こちらは非空時にwarningまたは失敗になり得ます。作成した祖先についても、空であることを確認してから深い順に戻す方が並列契約に合います。

## `tests/Support/ExternalFakes/fake-wiring-probe.php`

判定: 指摘なし。

専用環境ファイルの設定位置、marker、書き出し先、使い捨て鍵の観測は妥当です。

## `tests/Support/StrictTypesRuntimeProbe.php`

判定: 指摘なし。

アプリ起動を測らない経路を共通runnerへ統合しない判断は妥当です。

## 詳細設計・検証証跡

- [Warning] S1の「実装2本のみSHA一致、自己検査は意図的差分」への更新は十分です。
- [Warning] 一方、S6の詳細設計は依然としてG-1〜G-7、旧走査仕様を記述しており、実装済みのG-8/G-9、FQCN解決、`behaviour_proof`契約と一致していません。
- EmailPromotionTestの失敗は、提示された同一コードでのpass/fail、単独green、変更領域の非連結性から、T249の回帰とは見なしません。別件の順序依存flakeとして扱ってよいです。ただし「全体スイートが安定してgreen」とは報告できません。B/Cの2回連続greenは、受入条件の字面自体は満たしています。

## 全体判定

**CHANGES_REQUESTED**

S9のパス検査と資格情報処理は解消済みです。しかし、Round 4の3件目は「別featureを変更しない」という線引きと、「全child entryで実挙動上 `.env` 読み込み0件」という主張が両立していません。さらにG-9の文字列分岐は部分文字列一致による偽グリーンを持つため、セキュリティ不変条件の裏打ちとしては承認できません。

### Round 5 への対応マトリクス (本ラウンドで実施した内容)

# 対応マトリクス: impl-review Round 5

Round 5 の Codex 判定は **CHANGES_REQUESTED**。
「S9 のパス検査と資格情報の扱いは解消済み」と認めたうえで、残る [Critical] は 2 件:
(1) `idempotency-claim-probe.php` の裏取り不足と G-8 の主張の不整合、
(2) G-9 の走査器が拾いすぎ (偽グリーン)。いずれも受諾して直した。

---

## [Critical] G-8 の「全 child entry で `.env` 読み込み 0 件」と、裏取りの無い経路の同居

- 判断: **対応する** (Codex の提示した 2 案のうち **後者**「分類を分ける」を採る)
- 根拠: 指摘のとおり、次の 3 つは互いに矛盾していた —
  `behaviour_proof` の定義 (「実挙動で裏取りしている検査」) /
  当該 entry の但し書き (「直接測る検査は無い」) / G-8 の不変条件 (全 child entry で 0 件)。
  前者の案 (子 → DTO → 親まで実測する) は別 feature
  (`process-concurrency-test-harness`) の観測契約 `ConcurrentProbeObservation` を
  4 段にわたって変えることになり、本 TODO の boundary の外である。
- 対応内容: 申告の欄そのものを作り替えた。
  - `boots_repository_env: bool` + `behaviour_proof: string` を廃止し、
    **`env_isolation`** (`behavioural` / `structural` / `none` / 子が居なければ `null`) と
    **`env_isolation_proof`** (根拠) の 2 欄にした。
  - G-8 が固定するのは 4 点:
    (1) `none` の子入口は**ちょうど 0 件**、
    (2) `child_entry` は分類を 2 値のどちらかで申告し根拠を必ず持つ、
    (3) **`structural` の集合を完全一致で pin する** (現在 1 件 =
    `tests/Support/Concurrency/idempotency-claim-probe.php`)、
    (4) 子が居ない kind は `env_isolation` が `null` かつ根拠が空。
  - **docblock の主張を狭めた** — 「子はリポジトリの `.env` を読まない」を全経路については
    主張せず、主張できるのは `behavioural` の経路だけで、その根拠は本検査ではなく
    名指しされた実挙動の検査 (S9 / P-17 / P-8) であると明記した。
    `structural` の経路については**「実際に読まない」とは主張しない**と逐語で書いた。
  - テスト名も
    「G-8 退避も裏取りも無い子入口は 0 件で、実挙動の裏取りが無い経路は完全一致で pin されている」
    へ改めた。

## [Critical] `phpBootProbeMentionsEnvironmentPathRelocation()` が偽グリーンになる

- 判断: **対応する** (全面的に受諾)
- 根拠: 指摘の 4 形すべてが実際に通っていた —
  `$unrelated->useEnvironmentPath($dir)` (受け手を問わない) /
  `'$app->notUseEnvironmentPath($dir);'` / `'useEnvironmentPath is required'` /
  `'$app->useEnvironmentPathX($dir);'` (文字列側が素の部分文字列一致)。
  存在を肯定する証拠に使う走査で拾いすぎるのは安全側ではない (共通規約 (b))。
  文字列側の部分文字列一致は共通規約 (e) 違反でもある。
- 対応内容:
  1. 判定を **4 トークンの完全一致** `$app` `->` `useEnvironmentPath` `(` にした
     (`phpBootProbeHasEnvironmentPathCall()`)。**受け手を綴り (`$app`) で固定する** —
     変数の型は字句では解決できないので、これが本 gate で取れるいちばん強い形である
     (別名で受ける子入口は赤になる = 拾いすぎない側へ倒す)。
  2. 文字列側は**中身を PHP として字句解析し直し**、同じ 4 トークンの並びを探す
     (単一引用符は引用符を落としてから解析。ヒアドキュメント・ナウドキュメント本文はそのまま)。
     素の部分文字列一致をやめたので (e) を満たす。
  3. 見本表を 9 件 → 19 件へ拡張し、**文字列分岐の負例**を足した —
     接頭辞・打ち消し・接尾辞の 3 形を**文字列の中でも**落とすこと、散文
     (`'useEnvironmentPath is required'`)、名前だけ (`'useEnvironmentPath'`)、
     受け手が `$app` でない 2 形 (実コード / 文字列)、`(` が続かない形。
  4. G-9 の docblock の「主張しないこと」に**受け手の型は解決しない**ことを明記した。

## [Warning] `fake-wiring-probe.php` の裏取り (P-8) は `environmentFilePath()` を測らない

- 判断: **対応する** (P-17 を新設して `behavioural` の根拠を強くする)
- 根拠: 指摘のとおり、P-8 は「専用ファイル由来の鍵が効いた」ことは示すが、
  読んだ環境ファイルそのものは測っていなかった。
  この経路は S9 と違って**専用ファイルを実際に読む**ので、完全一致で測るのが自然である。
- 対応内容:
  - 子入口が `env_file_path` (= `$app->environmentFilePath()`) を報告するようにした
    (先頭コメントの責務も 6 → 7 へ更新)。
  - **P-17** を新設 — 子が読んだ環境ファイルの絶対パスが
    `<起動側が作った 0700 の置き場>/<起動側が渡した env ファイル名>` と**完全一致**する。
    期待値は起動側が渡した 2 つの値から一意に決まるので、配下判定ではなく完全一致で測る。
  - 当該 entry の `env_isolation_proof` を P-17 + P-8 の 2 本の名指しへ更新した。

## [Warning] S11 が `mkdir(recursive)` で作った祖先を戻さない

- 判断: **対応する**
- 対応内容: P-10d と同じ `$createdAncestors` 方式にした (深い順に集めて逆順に作り、
  `finally` で深い順に戻す)。`--parallel` の他 worker と競合しないよう
  **空でなければ触らずに打ち切る**。

## [Warning] P-10d の祖先削除が無条件 `rmdir()` で並列契約に合わない

- 判断: **対応する** (Codex は S11 との非対称を指摘した。S11 側に揃えるのではなく両方を揃えた)
- 対応内容: P-10d の後始末も「空であることを確かめてから深い順に戻す」形にした。

## [Suggestion] S9 / S10 の `$report` の PHPDoc が `array<string, string>` のまま

- 判断: **対応する**
- 対応内容: 追加した 3 項目が bool なので `array<string, mixed>` へ直した
  (取り込み元は string 固定だった旨をコメントに残した)。

## [Warning] 起動器の公開契約そのものが訂正されていない

- 判断: **一部対応する**
- 根拠: 趣旨に同意するが、`tests/Support/Process/BootProbeRunner.php` は
  **取得時の sha256 と一致したまま**の共有ファイルで、ここを編集すると
  実装 2 本のバイト一致も崩れる。取り込み元へ還す方が正しい直し方である。
- 対応内容:
  - 訂正は引き続き `FakeWiringProbeRunner` の訂正表に置くが、
    記述を**実態に合わせた** — 「各経路の実挙動の検査が守る」ではなく
    「**一部経路は構造 pin のみである**」と書き、どの経路がどちらかを G-8 の分類へ委ねた。
  - 起動器の docblock から**直接たどれる**場所として、
    起動器が名指ししている自己検査 (`tests/Unit/Support/Process/BootProbeRunnerTest.php`。
    既に意図的な差分を持つファイル) の先頭に**呼び出し側の必須契約**を書いた。
  - 上流 (laravel-claude-template) への申し送りとして devnotes に残す。

## [Warning] `BootProbeResult` の PHPDoc の食い違い

- 判断: **見送る** (Round 2〜5 で同じ判断。呼び出し側は誤記に依存していない / 上流申し送り)

## [Warning] 詳細設計 S6 が G-8 / G-9 / FQCN 解決を含まない

- 判断: **対応する**
- 対応内容: S6 に **【実装時に確定した事項】** 節を足し、G-8 / G-9 の新設、
  G-6 の FQCN 解決 (`PhpReferenceScanner`)、`env_isolation` 契約、
  軸 A / 軸 B の申告の増加 (main の前進への追随) を記載した。

## 全体テストの flake (T253 由来の EmailPromotionTest)

- 判断: **T249 の回帰ではない。別件として報告する** (Codex も同じ結論)
- 追加の裏取り (Round 5 で実施): **main の作業ツリー (T249 の変更なし)** で
  `vendor/bin/pest tests/Feature/Admin tests/Feature/Auth/EmailPromotionTest.php` を走らせると
  **同一の 2 件が同一の内容で失敗する** (77 tests / 75 passed / 2 failed)。
  Filament (Livewire) を先に描画した同一プロセスで standalone Blade の確認画面を描くと
  Livewire の `<style>` / `<script>` が注入されるためで、**順序依存の既存欠陥**である。
  `tests/Feature/Filament` との組み合わせでは再現しない (78 / 78 passed) ので、
  Livewire の静的状態を立てるのは `tests/Feature/Admin` 側である。
  T249 の差分は Livewire にも Filament にも Blade にも触れていない。
- 申し送り: **別 TODO 候補**である (本 TODO では直さない)。


### 参考: Round 4 の Codex 返答と対応マトリクス (経緯として)

結論から言うと、Round 3 で確認された「S9/S10 の子が現在のリポジトリ `.env` を読む」実害は解消しています。バイト一致を崩した判断も妥当で、正典 v1 (2) への適合という説明も成立します。

ただし、新しい実挙動テストには偽グリーンと資格情報取り扱いの問題が残っています。

### `tests/Unit/Support/Process/BootProbeRunnerTest.php`

- [Critical] S9 は存在しない `env_file_path` を、そのまま `BootProbeRunner::isInside()` に渡しています。同メソッドは「両引数とも `realpath` 済み」を契約としているため、Round 1 の P-14 と同じ穴が再発しています。例えば `<temporaryRoot>/x/../../repo/.env` を配下と誤判定できます。ここは `env_file_path === $result->temporaryRoot.'/.env'` の完全一致、または `.` / `..` を拒否した正規化済みパスとして検査すべきです。

- [Critical] リポジトリ `.env` の番兵抽出が Dotenv の構文と一致していません。

  `preg_match('/^KEY=(.+)$/m')` では、`export KEY=...`、インラインコメント、変数展開、エスケープ、重複定義などを正しく解釈できません。例えば `DB_PASSWORD=secret # local` では `secret # local` をハッシュするため、子が実際に `secret` を読み込んでも不一致となり、漏洩を見逃します。

  また、追跡外の `.env` と実資格情報の存在をテスト成立条件にするため、新しい checkout や秘密を置かない CI で偽レッドになります。実 DB パスワードの無塩 SHA-256 は、失敗時に Pest が期待値・実値を表示するとオフライン推測用の verifier にもなります。実資格情報ではなく制御された非秘密の番兵を使うか、環境ファイルパスの厳密一致を実挙動の境界にしてください。

- [Warning] S11 は `storage/framework/testing` を再帰作成した場合に戻しません。今回はこのファイルのバイト一致を既に意図的に崩しているため、以前の「共有ファイルなので見送る」という理由は成立しません。生成物不変条件に合わせて、作成した祖先を戻すべきです。

現在の `useEnvironmentPath()` の位置は適切です。`bootstrap/app.php` で Application を構築した後、Console Kernel の bootstrap より前に環境ファイルの場所を切り替えているため、提示された現行経路では repository `.env` は読み込まれません。

### `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

- [Critical] `idempotency-claim-probe.php` の `behaviour_proof` は、記載内容だけでは「repository `.env` を読まない」ことを裏取りできません。実効 DB 座標の確認は、DB 値がプロセス環境などで上書きされていれば、repository `.env` から別の資格情報を読み込んでいても通ります。専用環境ファイルへの切り替えが実際に効いたことを、`environmentFilePath()` の厳密一致などで測る必要があります。

- [Warning] G-8 は以前より正確に限界を説明していますが、テスト名の「リポジトリの `.env` を読んで起動する子は 0 件」は依然として実測ではなく申告値の集計です。本文が認めているとおり、G-8 自身は境界ではありません。「申告上 0 件で、裏取り名が登録されている」程度へ名前も揃えると誇張がなくなります。

- [Suggestion] `behaviour_proof` は任意の非空文字列で通るため、実在する検査との機械的な結び付きはありません。人間向け目録として残すなら現在の明記で許容できますが、セキュリティ境界とは扱わないことが必要です。

G-2 を2件の完全一致 pinへ変えた判断は妥当です。単一子の boot probe と、2子を絶対 deadline で同期・回収する concurrency harness は回収契約が異なり、同じ runnerへ統合すべき概念ではありません。

### `tests/Support/Process/BootProbeRunner.php`

- [Warning] runner の説明には、プロセス環境が「唯一の統制点」とありますが、Laravelを起動する呼び出し側が `useEnvironmentPath()` を設定しなければ repository `.env` は別経路から読み込まれます。今回の事故の根そのものなので、「Laravel起動時の環境ファイル隔離は呼び出し側の必須契約」と明記すべきです。

実装本体のプロセス回収、終了コード保持、予約鍵、書き出し先退避について新しい指摘はありません。

### `tests/Support/Process/BootProbeResult.php`

- [Warning] 既出のとおり、`timedOut === true && exitCode === 0` が可能なのに「強制終了なら `TIMEOUT_EXIT_CODE`」とするPHPDocは実装と一致していません。今回の呼び出し側は誤記に依存していません。

### `tests/Architecture/ExternalFakeBootProbeTest.php`

判定: 指摘なし。P-16を含む正規化検査、timeoutのfail-closed、書き出し先の実体・向きの検査は妥当です。

### `tests/Support/ExternalFakes/FakeWiringProbeRunner.php`

判定: 指摘なし。環境ファイルとプロセス環境の責務分離、外側・内側双方の一時ディレクトリ、予約鍵の委譲は設計と一致しています。

### `tests/Support/ExternalFakes/fake-wiring-probe.php`

判定: 指摘なし。専用環境ファイル、marker、書き出し先報告、使い捨て鍵の確認は妥当です。

### `tests/Support/StrictTypesRuntimeProbe.php`

判定: 指摘なし。アプリ起動を測らない経路を共通 runner に載せない判断は適切です。

### 詳細設計・受入証跡

- [Warning] 詳細設計は依然として「取り込み3本すべてバイト一致」と「3つのSHA-256一致」を受入条件にしています。今回の逸脱判断自体は正しいものの、設計・受入条件を「2本一致＋自己検査1本はセキュリティ修正による意図的差分」へ更新する必要があります。

- [Critical] 提示時点では、2回目の `composer test`、`pnpm test`、`pnpm test:packages` が未完了です。全検証コマンド完走と全体テスト2回連続greenという受入条件は、まだ充足した証跡になっていません。

全体判定: **CHANGES_REQUESTED**

# 対応マトリクス: impl-review Round 4

Round 4 の Codex 判定は **CHANGES_REQUESTED**。
「Round 3 の実害 (S9 / S10 の子がリポジトリの `.env` を読む) は解消しており、
バイト一致を崩した判断も妥当・正典 v1 (2) への適合という説明も成立する」と認めたうえで、
**新しく書いた実挙動テストに偽グリーンと資格情報の取り扱いの問題が残る**という指摘である。

---

## [Critical] S9 が未正規化の `env_file_path` を `isInside()` に渡している (Round 1 の P-14 と同じ穴)

- 判断: **対応する**
- 根拠: 指摘のとおり。`BootProbeRunner::isInside()` は両引数が正規化済みであることを契約にしており、
  `<temporaryRoot>/x/../../repo/.env` を配下と誤判定できる。
  配下判定は**そもそも要らない** — 期待値は起動器が予約鍵で渡した一時ディレクトリから
  一意に決まる (`LARAVEL_STORAGE_PATH = <root>/storage` なので `dirname()` は `<root>`、
  `environmentFilePath()` は `<root>/.env`)。
- 対応内容: 配下判定を捨て、**完全一致**にした。
  `expect($report['env_file_path'])->toBe($result->temporaryRoot.'/.env', …)`。
  「正規化の前提が要らないので、完全一致が最も強い」ことをコメントに書いた。

## [Critical] 番兵抽出が Dotenv の構文と一致せず、実資格情報を digest で出力し、偽レッドにもなる

- 判断: **対応する** (番兵の作り方を全面的に替える)
- 根拠: 3 つとも正しい。
  1. `preg_match('/^KEY=(.+)$/m')` は `export` 付き・インラインコメント・変数展開・
     引用・重複定義を解釈できない。`DB_PASSWORD=secret # local` では
     `secret # local` を hash するので、子が `secret` を読んでいても不一致になり**漏洩を見逃す**。
  2. 追跡外の `.env` に実資格情報が在ることをテスト成立条件にすると、
     見本から起こしたチェックアウト (`.env.example` は `CIPHERSWEET_KEY=` が空で
     `DB_PASSWORD` を持たない — 実測) や秘密を置かない CI で**偽レッド**になる。
  3. 実 DB パスワードの無塩 SHA-256 を期待値として表示すると、
     失敗時の出力が**オフライン推測の検証器**になる。
- 対応内容: `.env` を読むのをやめ、**制御された非秘密の番兵**へ替えた。
  子は digest ではなく**真偽 2 つ**を報告する —
  `ciphersweet_key_present` / `db_password_present`。
  この 2 つの設定値は**環境ファイルからしか来ない** (`config/ciphersweet.php` は既定を持たず、
  `config/database.php` は空文字を既定にする) ので、**非空なら環境ファイルが読まれた証拠**になる。
  S9 は両方が偽であることを**無条件で**測る。
  - 秘密も digest もテスト出力に出ない
  - `.env` の中身・存在に依存しないので偽レッドにならない
  - 条件分岐が消えたので空振りの緑も無くなった (Round 4 時点の実装は
    「値が在るときだけ測る」形で、そこも指摘の余地があった)
- 負の裏取り (実測。同一の起動器で退避の有無だけを替えて比較):

  ```
  退避なし: env_file_path=<repo>/.env.testing  env_file_exists=true
            ciphersweet_key_present=true  db_password_present=true   ← 4 つとも赤側
  退避あり: env_file_path=/tmp/boot-probe-…/.env  env_file_exists=false
            ciphersweet_key_present=false db_password_present=false  ← 4 つとも緑側
  ```

## [Critical] `idempotency-claim-probe.php` の `behaviour_proof` が裏取りになっていない

- 判断: **一部対応する** (申告文を正確にし、機械の裏打ちを 1 つ足す。実挙動の測定は足さない)
- 根拠:
  - 指摘は正しい。実効 DB 座標の確認は、DB 値がプロセス環境で上書きされていれば
    `.env` から別の資格情報を読んでいても通る。**あの申告文は裏取りを過大に述べていた。**
  - ただし当該ファイルは**別 feature (lctl: `process-concurrency-test-harness`) の持ち物**で、
    観測は fail-closed な DTO (`ConcurrentProbeObservation`) を通る。項目を足すには
    子 → DTO → runner → 呼び出し側の 4 段を変えることになり、
    本 TODO の boundary (「子を 2 本立てて合図で同期させる並行テストは含まない」) を越える。
    T249 で他 feature の契約を書き換えるのは思考原則 2 (今必要なものだけ作る) に反する。
- 対応内容:
  1. 申告文を**事実へ直した** — 「環境ファイルの切り替えそのものが効いたことを直接測る検査は
     **無い**」と明記し、切り替えが段 8 の `useEnvironmentPath()` /
     `loadEnvironmentFrom()` で構造的に固定されていることを書いた。
  2. **機械の裏打ちを 1 つ足した (G-9 新設)** — `child_entry` の申告ファイルは
     正規化トークン (名前または文字列) に `useEnvironmentPath` を**必ず持つ**。
     Laravel が読む環境ファイルはこの呼び出しでしか動かないので、
     **持たない子入口は既定でリポジトリの `.env` を読む** = 新しい子入口を素直に足すと赤になる。
     併せて `behaviour_proof` の先頭語が**実在するパス**であることも機械で確かめる
     (実在しない検査名で申告を通す形を塞ぐ)。
  3. G-9 の限界を docblock に書いた — **呼び出しが効く位置に在ることは字句では見ない**。
     位置の正しさは各経路の実挙動の検査 (S9 / P-8) が担い、
     `idempotency-claim-probe.php` には実挙動の検査が無いことを申告に明記した。
  4. 走査器の見本検査を 9 件足した (名前トークン / 文字列トークン / ヒアドキュメント本文の正例、
     コメントのみの負例 2、接頭辞・打ち消し・接尾辞の 3 形の負例、
     「退避を持たない子入口」の負例 = G-9 で赤になる形)。

## [Warning] G-8 のテスト名が実測ではなく申告値の集計である

- 判断: **対応する**
- 対応内容: テスト名を
  「G-8 **申告上**リポジトリの .env を読む子は 0 件で、child_entry は裏取りの検査を名指ししている」
  へ改めた。docblock の「主張しないこと」も、実在しない名前は G-9 が落とすことを反映して書き直した。

## [Suggestion] `behaviour_proof` は任意の非空文字列で通る (機械の結び付きが無い)

- 判断: **一部対応する**
- 対応内容: G-9 で「先頭語が実在するパスであること」を機械で確かめるようにした。
  検査の**中身**との結び付きは依然として無いので、その旨を docblock に明記し
  「セキュリティ境界ではなく人間向けの目録である」という位置付けを維持した。

## [Warning] 起動器の docblock に「環境ファイルの隔離は呼び出し側の必須契約」と明記すべき

- 判断: **対応する** (ただし置き場所を替える)
- 根拠: 指摘の趣旨に同意する。ただし `tests/Support/Process/BootProbeRunner.php` は
  **取得時の sha256 と一致したまま**の共有ファイルであり、ここを編集すると
  2 本目のバイト一致も崩れる。S1 の設計は「取り込んだ docblock の訂正は
  `FakeWiringProbeRunner` の docblock に置く」と定めているので、そこへ書く。
- 対応内容: `FakeWiringProbeRunner` の訂正表へ 1 行追加
  (「統制点は `proc_open` の環境配列だけ」→ **プロセス環境はそれで唯一だが `.env` は別経路**) し、
  続けて **「呼び出し側の必須契約」** 節を新設した — Laravel を起こす子は環境ファイルの
  置き場所を自分で退避すること、退避の手段は 2 通り (専用の環境ファイル / 実在しない場所)、
  この契約を守る検査は G-8 / G-9 と各経路の実挙動の検査であること。

## [Warning] S11 が `storage/framework/testing` を再帰作成した場合に戻さない

- 判断: **対応する**
- 根拠: 指摘のとおり。バイト一致を既に意図的に崩しているので「共有ファイルだから見送る」は成り立たない。
- 対応内容: `$createdBase` を持ち、`finally` で**自分が作った場合だけ**戻す。
  `--parallel` の他 worker が同じ場所を使うので、**空でなければ触らない**。

## [Warning] `BootProbeResult` の PHPDoc の食い違い (`timedOut && exitCode === 0`)

- 判断: **見送る** (上流申し送り。Round 2 / Round 3 でも同じ判断を Codex が受け入れている)
- 根拠: 呼び出し側は `timedOut` を見る契約で誤記に依存しておらず、実行時のバグではない。
  当該ファイルは**バイト一致のまま**保つ方が価値が高い (実装 2 本の一致は維持できている)。

## [Warning] 詳細設計の受入条件が「取り込み 3 本すべてバイト一致」のままである

- 判断: **既に対応済み** (Round 4 のプロンプトを組んだ時点では反映が間に合っていなかった)
- 対応内容: `detailed-design.md` の S1 に **【実装時の変更】** 節を挿し、
  受入条件の「取り込みの同一性」も
  「実装 2 本は sha256 一致 / 自己検査 1 本はセキュリティ修正による意図的差分」へ書き換えてある。

## [Critical] 2 回目の `composer test` / `pnpm test` / `pnpm test:packages` が未完了

- 判断: **対応する**
- 根拠: 機械的な受入条件である。
- 対応内容: Round 4 の修正をすべて入れ終えたうえで、**最終形で** `composer test` を
  2 回連続 + `pnpm test` + `pnpm test:packages` を走らせ直し、結果を Round 5 のプロンプトと
  最終報告に載せる (Round 4 のプロンプトに載せた 2 回目は、その最中に修正を入れたため
  最終形の証跡として数えない)。


### 詳細設計書 (S1 の【実装時の変更】と S6 の【実装時に確定した事項】を反映済み)

# 詳細設計: boot-probe-runner-unification

家系の機能台帳 lctl の feature `subprocess-boot-probe-harness` (正典 aigenba / `canonical_version: v1`) への
aicue 追従。**アプリコード (`app/` / `routes/` / `config/` / `database/` / `bootstrap/`) は 1 バイトも変更しない**。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  （撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg /
> 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の
   **1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. **Artifact の使用**(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

> **本設計との関係**: 4 / 5 / 6 / 7 / 8 は該当なし (API も LLM も UI も触らない)。
> 1 は S6 の gate 新設と S2〜S4 のテスト計画で満たす。2 は「PHPStan のエラーが 0 のまま」で満たす
> (テストは aicue の解析対象外。後述)。3 は該当なし (DB を 1 度も張らない)。
> 9 は本設計フロー全体で守る (成果物は `devnotes/` 配下のファイルのみ)。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。**ただし aicue の解析対象は `app` / `config` /
  `database` / `routes` でテストを含まない**。本設計はアプリコードを変更しないので
  「PHPStan のエラーが 0 のまま」であることが要件であり、**ハーネスが level 10 で検査されるとは主張しない**
  (`phpstan.neon` は触らない。理由は「乖離台帳の確認」の節)
- **Pest** テストフレームワーク (`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行 (`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止)。
  **本設計のテストは DB を 1 度も張らない**
- **テストデータは必ず Factory で生成** — 本設計はモデルを 1 つも使わないので該当なし
  (新モデルも Factory も追加しない)
- **DTO + JsonResource** パターン — 本設計は API を 1 本も増やさないので該当なし
- **アーリーリターン** 推奨 / `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [`devnotes/20260823-0022-boot-probe-runner-unification/conceptual-design.md`](./conceptual-design.md)
  (Codex 概念設計レビュー Round 5 で APPROVED)
- **概念設計からの変更 1 件**: 「経路 2 (`StrictTypesRuntimeProbe`) も共通 runner へ載せ替える」という
  方針は**撤回した** (詳細設計レビュー Round 1 の [Critical] を受諾)。理由は S5 に書く。
  概念設計の当該節には撤回の注記を残してある。

## 正典 v1 の不変条件と、本設計での担い手 (全件の対応表)

| # | 正典 v1 の不変条件 (feature_yaml の boundary が正本) | 本設計での担い手 | 検査 |
|---|--------------------------------------------------|----------------|------|
| (1) | 子は `PHP_BINARY` で起こす (親と同じ実行体) | `BootProbeRunner::spawn()` の `proc_open([PHP_BINARY, ...$phpArguments], …)` (S1 でバイト一致取り込み) | 自己検査 S9 (子が報告する `PHP_BINARY` が親と一致) |
| (2) | 環境変数は 3 段 (許可一覧の継承 → ケース共通の基底 → ケース別上書き。ケース別が最後に効く)。開発者ローカルの env を入力集合から外す | `BootProbeRunner::composeEnv()` の `array_merge($inherited, baseEnv(), $caseEnv, $reserved)` | 自己検査 S1 / S2 / S3 / S4 + 呼び出し側 **P-7** |
| (3) | 出力の管を非ブロッキングで逐次読み、制限時間超過は SIGTERM → 猶予 → SIGKILL、全ての管を閉じてから必ず `proc_close` | `BootProbeRunner::spawn()` / `readAvailable()` / `reclaim()` | 自己検査 S7 / S8 / S12 / S13 / S14 |
| (4) | 終了コードは実行中フラグが初めて false になった時点の非負値を保存し、`-1` や `proc_close` の戻り値で上書きしない。取れなければ 124 へ正規化 | `BootProbeRunner::spawn()` の `$exitCode` 確定と `TIMEOUT_EXIT_CODE` | 自己検査 S6 / S7 / S12 / S13 / S14 + 呼び出し側 **P-15** (制限時間超過の解釈) |
| (5) | 子の書き出し先を環境変数でリポジトリ外の一時ディレクトリへ逃がす + **その環境変数が実際に効いていること自体を gate が検査する** | runner の `RESERVED_ENV_KEYS` 7 キー + `createTemporaryRoot()` の fail-closed / **aicue 側の実働証明は S4 の P-13 (実体) と P-14 (向き)** | 自己検査 S4(c) / S9 / S10 / S11 + 呼び出し側 **P-11 / P-13 / P-14** |
| (6) | runner 自身の自己検査を持つ (許可一覧の網羅性 / 上書きの適用順 / 終了コードの保持 / 制限時間の回収) | `tests/Unit/Support/Process/BootProbeRunnerTest.php` (S1 でバイト一致取り込み。14 本) | それ自体 |

**正典が含まないもの (boundary が明記。本設計もやらない)**: 子プロセスで何を観測するかという個別の主張 /
子を 2 本立てて合図で同期させる並行テスト / 静的走査の基盤そのもの (`static-scanner-substrate`) /
HTTP サーバーの常駐起動 / テストレーンの構成。

> **S6 (全数申告 gate) の位置付けを先に断っておく**: S6 は**正典 v1 の 6 不変条件のいずれでもない**。
> aicue 側の**上積み**である。根拠は 2 つ — (a) 正典テンプレートも本 feature の追従で同型の gate
> (`tests/Architecture/SubprocessProbeLaunchGateTest.php`) を新設しており、台帳の実装報告に
> 「新設 gate」として記録されている。(b) AGENTS.md 禁止事項 1 が「不変条件は対応する
> Architecture/Feature テストへの登録まで含めて実装済み」と定めるので、載せ替え一度きりでは規約を満たさない。
> **正典の boundary が除く「静的走査」は走査の基盤を持つ feature (`static-scanner-substrate`) を指しており、
> 追従の中で走査を使う gate を置くことを禁じてはいない** (テンプレートの先例がその読みを支持する)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 共通 runner をテンプレートからバイト一致で取り込む | `tests/Support/Process/BootProbeRunner.php` / `tests/Support/Process/BootProbeResult.php` / `tests/Unit/Support/Process/BootProbeRunnerTest.php` (新規 3) | 高 |
| S2 | 経路 1 の起こし手を runner へ載せ替える | `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` (変更) | 高 |
| S3 | 子入口スクリプトへ実働証明の観測点を足す | `tests/Support/ExternalFakes/fake-wiring-probe.php` (変更) | 高 |
| S4 | 呼び出し側 gate を新契約へ揃え、正典 (5) の実働証明を足す | `tests/Architecture/ExternalFakeBootProbeTest.php` (変更) | 高 |
| S5 | 経路 2 を**載せ替えない**理由を docblock へ明記する | `tests/Support/StrictTypesRuntimeProbe.php` (docblock のみ変更) | 中 |
| S6 | 一元化に関する退行を検出する全数申告 gate を新設する | `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` (新規 1) | 高 |

**アプリコード・`docs/`・`phpstan.neon`・指紋台帳・逸脱の登録簿は 1 行も触らない。**
既存テストの**削除・主張の後退は 0 件**である。

---

## S1: 共通 runner をテンプレートからバイト一致で取り込む

### 変更箇所 (すべて新規)

| パス | 取り込み元 | 設計時に実測した sha256 (テンプレート指紋台帳の記録値と一致) |
|------|-----------|--------------------------------------------------|
| `tests/Support/Process/BootProbeRunner.php` | `laravel-claude-template` 同パス | `bd21b337cc7e4327debba02a3ba46cb496f0a66f0980ccf08cb3847a18430162` |
| `tests/Support/Process/BootProbeResult.php` | 同 | `00b14167ebfa9710abdb36edf8989bb66350320ee191c3993debd06ed27902cb` |
| `tests/Unit/Support/Process/BootProbeRunnerTest.php` | 同 | `9db128d89629dc5f4cd891a2f22d063451e3e524480141ff05e7ad0aa261d014` |

### 取り込みの手順 (fail-first。ファイル内容は 1 バイトも変えない)

1. lctl の `get_source` で `laravel-claude-template:docs/template-fingerprints.json` を取得し、
   `entries` に上記 3 パスが登録されていることを確認する
2. 同じく `get_source` で 3 ファイルを取得し、**各ファイルの sha256 が台帳の記録値と一致**することを確認する。
   1 件でも食い違えばそこで止め、原因 (世代のずれ) を報告する
3. **自己検査 `tests/Unit/Support/Process/BootProbeRunnerTest.php` だけを先に配置し、
   `Tests\Support\Process\BootProbeRunner` が未定義で赤になることを確認する** (fail-first)。
   ファイル内容は変えずに実現できる
4. 実装 2 本 (`BootProbeRunner.php` / `BootProbeResult.php`) を配置し、自己検査 14 本が緑になることを確認する
5. **`vendor/bin/pint --test` で非破壊に整形の一致を確認する**。落ちたら
   「取り込み元が aicue の Pint 設定と食い違っている」という事実なので、**整形せずに報告して止まる**
   (`composer fix` は書き換えるので、この段では**実行しない**)
6. 配置後にもう一度 3 ファイルの sha256 を取り、手順 2 の値と一致することを確認する

> **【実装時の変更 — Codex 実装レビュー Round 3 の [Critical] により】**
> **取り込み 3 本のうち 1 本 (`tests/Unit/Support/Process/BootProbeRunnerTest.php`) は
> バイト一致を崩した。** 取り込み元の S9 / S10 の検体は `bootstrap/app.php` を素で読むため、
> **リポジトリの `.env` がそのまま子の設定に載る** (実測: DB パスワードと実 `CIPHERSWEET_KEY`)。
> これは正典 v1 (2)「開発者ローカルの環境変数を入力集合から外す」を**環境ファイルという
> 別経路で迂回する**形であり、バイト一致より不変条件を優先した
> (AGENTS.md §セキュリティ不変条件「アプリ都合で緩めない」)。
> 修正は「起動前に環境ファイルの置き場所を起動器の一時ディレクトリへ逃がす (fail-closed)」+
> 「S9 が実挙動で 2 方向 (環境ファイルの場所 / `.env` の実値の番兵) から測る」の 2 点である。
> 機械的な代価は無い (当該パスは指紋台帳のキーにも採用時債務にも無いので、突合 gate は
> 赤くならず `LedgerPins` の件数も変わらない)。詳細は
> `codex-history/impl-review-decisions-round-3.md`。
> **上流 (laravel-claude-template) への申し送りが 1 件残る**: 正典側で同じ修正が入って
> 再取り込みできるようになったら、この逸脱は消える。

> **なぜバイト一致に固執するか**: このパスは aicue の指紋台帳 (281 パス) に無い = 未受領のテンプレートパスである。
> バイト一致で入れれば、将来の指紋台帳の再生成で**記録値と一致して母集合に入り、逸脱 0 件・債務 0 件**になる。
> 1 バイトでも変えると意図的逸脱の登録 (`LedgerPins::DIVERGENCE_ENTRY_COUNT` の更新を伴う) が必要になる。

### 依存の確認 (実装前に満たされていること — 設計時に実読で確認済み)

| 依存 | aicue での実在 |
|------|--------------|
| `Webmozart\Assert\Assert` | `composer.json` の `require` に `webmozart/assert: ^2.4` |
| `FilesystemIterator` / `RecursiveDirectoryIterator` / `RecursiveIteratorIterator` / `SplFileInfo` | PHP 標準 (SPL) |
| `posix_kill` (自己検査 S12 / S14 が任意で使う) | 無ければ検査側が早期 return する形なので必須ではない |
| `pcntl_signal` (自己検査 S14) | 無ければ S14 は `skip` になる (**成功扱いにはならない**) |
| 名前空間 `Tests\Support\Process` の autoload | `composer.json` の `autoload-dev` の `Tests\` → `tests/` に含まれる |

### 取り込み先で通ることの事前実測 (設計時に実施済み)

自己検査 S9 / S10 は「アプリを子で起こし、書き出し先が一時ディレクトリを指し、
`storage/logs/laravel.log` がそこに実在する」ことを測る。runner と同じ環境合成を手で再現して実測した結果:

- 子は **exit 0**、標準エラーは空
- `storagePath()` / `getCachedConfigPath()` / `getCachedRoutesPath()` / `view.compiled` /
  `logging.channels.single.path` の**全てが一時ディレクトリ配下**
- 一時ディレクトリ配下に実際に書かれたのは `storage/logs/laravel.log` /
  `bootstrap-cache/services.php` / `bootstrap-cache/packages.php` の 3 件。**リポジトリ側は 0 件**
- 子が受け取ったプロセス環境は `PATH` / `HOME` / `TMPDIR` + 基底 3 + 予約 7 のみ

### 取り込んだ docblock と aicue の構成の齟齬 (1 か所。**書き換えない**)

| 取り込んだ記述 (`BootProbeRunner` の docblock 末尾) | aicue での実際 |
|---|---|
| 「`app` / `routes` / `config` / `database` / `bootstrap` へ持ち出すと**外部到達統制の subprocess 0 件 pin** に触れる (AGENTS.md セキュリティ不変条件 **15**)。同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`」 | aicue の外部到達点の目録は **AGENTS.md セキュリティ不変条件 9** であり、`php -l` の真値取り出しは `tests/Support/GlobalUse/PhpLintOracle.php` (`Architecture/` が入らない) にある。**趣旨 (tests/ 専用であり app/ へ持ち出さない) は aicue でもそのまま成り立つ** |

**書き換えると共有パスの逸脱になる**ので、この訂正表は
**`tests/Support/ExternalFakes/FakeWiringProbeRunner.php` の docblock** (= runner を使う限り必ず存在する
aicue 所有のファイル) に置く。S6 の gate へ置くと、将来 S6 が消えたときに訂正も一緒に消えてしまう。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 自己検査 1 本が同時に入る。既存テストの変更は無し
- 指紋台帳 / 逸脱の登録簿: **変更しない** (理由は「乖離台帳の確認」の節)

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (取り込み元が `BootProbeResult` を返す)
- [x] null 安全 (`Webmozart\Assert\Assert` を全面的に使用)
- [x] DTO を返している (`BootProbeResult` readonly)
- [x] Generics の型パラメータが正しい (`list<non-empty-string>` / `array<non-empty-string, string>`)
- 注: **aicue の PHPStan 解析対象に `tests` は含まれない**ので、これは「取り込み元が level 10 を通っている」
  ことの確認であり、aicue で level 10 が回るという主張ではない

### テスト計画

- [x] 新規テスト (取り込み): `tests/Unit/Support/Process/BootProbeRunnerTest.php` の S1〜S14 —
  親 env の非漏洩 / 継承規則 / ケース別上書きの勝ち / env 集合の完全一致 / 予約鍵の拒否 / 終了コードの保持 /
  制限時間と強制終了 / 大量出力で詰まらない / 書き出し先の向きと実体 / 起動前 fail-closed の残骸なし /
  管を閉じた子の回収 / 終了後の読み切りと上限 / 段階的強制終了
- [x] 既存テストの更新: なし
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (取り込み元も使っていない)

### リスク

- **`ext-pcntl` が無い環境**: S14 が `skip` になる (成功扱いにはならない)。aicue の devcontainer / CI は
  Linux で pcntl を持つ
- **将来のテンプレート更新との追従遅れ**: 指紋台帳を再生成しない限り検査上の食い違いは生じない。
  再判定の契機は「指紋台帳の世代を上げるとき」であり、そのときに 3 パスも一緒に見直す

---

## S2: 経路 1 の起こし手を runner へ載せ替える

### 変更箇所

- ファイル: `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` (全 301 行)
  - **クラス docblock を全面的に書き直す** (下記「docblock の書き直し要件」)
  - `ALLOWED_ENV_FILE_KEYS` から `APP_KEY` を外す
  - `ALLOWED_PROCESS_ENV_KEYS` を `CASE_ENV_KEYS` へ改称・意味変更
  - `MARKER_RELATIVE_PATH` を新設
  - `run()` を `withEnvironmentDirectory()` + `interpret()` の構成へ組み替える
  - `Symfony\Component\Process\Process` の `use` を落とし、
    `Tests\Support\Process\BootProbeRunner` / `Tests\Support\Process\BootProbeResult` の `use` を足す
    (`FakeClassCatalog` は同一 namespace なので `use` は不要。
    **`Webmozart\Assert\Assert` は使わない** — 例外契約を `RuntimeException` 1 本に統一するため)

### 責務の再分割 (何を残し、何を委ねるか)

| 責務 | 載せ替え後の担い手 |
|------|------------------|
| 一時ディレクトリ 0700 / 環境ファイル 0600 の作成と権限の事前検査 | **`FakeWiringProbeRunner` に残す** (正典より締まった aicue 固有の強化) |
| 使い捨て鍵の生成 (`APP_KEY` / `CIPHERSWEET_KEY`) | **残す** |
| 環境ファイルの許可キーの deny-by-default | **残す** (ただし `APP_KEY` を外す。理由は下) |
| 子の起こし方 (実行体・環境の 3 段合成・作業ディレクトリ) | **`BootProbeRunner` へ委ねる** |
| 出力の逐次読み・制限時間・段階的強制終了・`proc_close` | **`BootProbeRunner` へ委ねる** |
| 書き出し先の退避 (7 キー) | **`BootProbeRunner` へ委ねる** (従来は `APP_CONFIG_CACHE` 1 キーのみ) |
| 子の出力の解釈 (fail-closed) | **残す** (`interpret()` として純関数に切り出す) |

### docblock の書き直し要件 (現行の説明は載せ替え後に**事実でなくなる**)

現行 docblock は「子の環境は `env -i` で空にしてから 3 件だけを載せる」と書いており、載せ替え後は嘘になる。
次の 5 点を書く:

1. **環境は 4 段**である — 継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` /
   `CACHE_STORE`) → ケース別 (本クラスが渡す 3 件) → 予約 (書き出し先 7 キー。runner が決める)。
   統制点は `proc_open` へ渡す環境配列であり、開発者ローカルの env はここで締め出される
2. **鍵の置き場所が 2 つに分かれる** — `APP_KEY` は**ケース別上書き**、`CIPHERSWEET_KEY` は**環境ファイル**。
   理由 (Laravel の環境変数リポジトリは immutable でプロセス環境を上書きしない) まで書く
3. **一時ディレクトリが 2 つある** — 外側 (本クラスが作る環境ファイルの置き場。0700) と
   内側 (runner が作る書き出し先の退避先)。どちらもリポジトリ外で、どちらも必ず消える
4. **設定キャッシュの退避先は runner の予約鍵**であり、本クラスからは渡せない (渡すと例外)
5. **取り込んだ `BootProbeRunner` の docblock の訂正表** (S1 の表。不変条件番号 15 → 9 /
   `PhpLintOracle` のパス)。取り込みはバイト一致なので向こうは直せない

### 環境の 4 段の確定表 (実装者向けの正本)

| 段 | 出所 | 中身 |
|---|------|------|
| 1. 継承 | `BootProbeRunner::INHERITED_ENV_KEYS` | `PATH` / `HOME` / `TMPDIR` (親に非空で在るときだけ) |
| 2. 基底 | `BootProbeRunner::baseEnv()` | `APP_KEY` / `QUEUE_CONNECTION=database` / `CACHE_STORE=array` |
| 3. ケース別 | `FakeWiringProbeRunner::CASE_ENV_KEYS` | `FAKE_WIRING_PROBE_ENV_DIR` / `FAKE_WIRING_PROBE_ENV_FILE` / **`APP_KEY` (使い捨て)** |
| 4. 予約 | `BootProbeRunner::RESERVED_ENV_KEYS` | `LARAVEL_STORAGE_PATH` / `VIEW_COMPILED_PATH` / `APP_CONFIG_CACHE` / `APP_ROUTES_CACHE` / `APP_SERVICES_CACHE` / `APP_PACKAGES_CACHE` / `APP_EVENTS_CACHE` |

> **`APP_KEY` をケース別へ置く理由 (設計時に子プロセスで実測して確定した)**:
> Laravel の `Env::getRepository()` は **immutable** で、**プロセス環境に既に在る値を Dotenv は上書きしない**。
> runner の基底が `APP_KEY` を載せる以上、0600 の環境ファイルに書いた使い捨て鍵は**無視される**。
> 実測 — 環境ファイルに `APP_KEY=base64:ZmlsZS1r…` / プロセス環境に `APP_KEY=base64:cnVubmVy…` を置くと
> `config('app.key')` は**プロセス環境側**になった。同じ実測で、プロセス環境に無い `CIPHERSWEET_KEY` は
> **環境ファイルの値が効いた**。よって `APP_KEY` はケース別上書きへ移し、`CIPHERSWEET_KEY` は環境ファイルに残す。

> **`APP_CONFIG_CACHE` を渡してはならない**: runner の予約鍵なので、渡すと `run()` が例外にする。
> 退避は runner が一時ディレクトリ配下 (`bootstrap-cache/config.php`) へ向けて行う。

### 現行コード (抜粋)

```php
public const array ALLOWED_ENV_FILE_KEYS = [
    'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
    'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
];

public const array ALLOWED_PROCESS_ENV_KEYS = [
    'FAKE_WIRING_PROBE_ENV_DIR', 'FAKE_WIRING_PROBE_ENV_FILE', 'APP_CONFIG_CACHE',
];

public static function run(
    string $environment, bool $fakeExternals, bool $fakeStorage, bool $fakeLlm,
    ?string $baseDirectory = null, float $timeout = 120.0,
): array {
    $base = $baseDirectory ?? sys_get_temp_dir();
    $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700) || ! is_dir($directory)) { /* 例外 */ }

    try {
        // …環境ファイル書き出し・権限検査…
        $configCachePath = $directory.'/config-cache-absent.php';
        $process = new Process(
            ['env', '-i', /* 3 キー */, PHP_BINARY, self::probeScriptPath()],
            FakeClassCatalog::repoRoot(), null, null, $timeout,
        );
        $process->run();

        return [
            'exitCode' => $process->getExitCode() ?? -1,
            'output' => self::decode($process->getOutput()),
            // …
        ];
    } finally {
        self::removeDirectory($directory);
    }
}
```

### 変更後コード

```php
use Tests\Support\Process\BootProbeResult;
use Tests\Support\Process\BootProbeRunner;

/**
 * 子が実働証明の印を書く先 (`storage_path()` からの相対パス)。
 *
 * ★正典 v1 (5) の実働証明の観測点。退避が効いていなければ印はリポジトリ側へ落ち、
 *   起動器の `writtenRelativePaths` に現れない = P-13 が赤になる。
 */
public const string MARKER_RELATIVE_PATH = 'app/private/fake-wiring-probe-marker.txt';

/**
 * 一時環境ファイルに書いてよいキー (deny-by-default)。
 *
 * ★`APP_KEY` は**ここに置けない**。Laravel の環境変数リポジトリは immutable で、
 *   プロセス環境に既に在る値を Dotenv は上書きしない。BootProbeRunner の基底が
 *   `APP_KEY` を載せる以上、ここへ書いても無視される (設計時に子プロセスで実測)。
 *   使い捨て `APP_KEY` は CASE_ENV_KEYS 側 (ケース別上書き) が運ぶ。
 *
 * @var list<string>
 */
public const array ALLOWED_ENV_FILE_KEYS = [
    'APP_ENV', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
    'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
];

/**
 * BootProbeRunner へ渡す**ケース別上書き**のキー (正典 v1 (2) の第 3 段)。
 *
 * ★`TESTING_FAKE_*` はここに**無い**。偽物の宣言はプロセス環境へ 1 件も載せず、
 *   0600 の環境ファイルの中だけに置く (P-7 の危険接頭辞の禁止をそのまま維持する)。
 * ★`APP_CONFIG_CACHE` ほかの書き出し先は runner の**予約鍵**なので渡さない (渡すと例外)。
 * ★この一覧は P-7 がリテラルで完全一致 pin する (増やすと赤になる)。
 *
 * @var list<string>
 */
public const array CASE_ENV_KEYS = [
    'FAKE_WIRING_PROBE_ENV_DIR',
    'FAKE_WIRING_PROBE_ENV_FILE',
    'APP_KEY',
];

/**
 * 環境ファイルの置き場所を 0700 で用意し、**本体がどう終わっても必ず消す**足場。
 *
 * ★`run()` の `finally` をここへ切り出したのは、**後始末そのものを検査から直接呼べるように**
 *   するためである (P-10c)。制限時間超過の経路は「`interpret()` が例外を投げる」(P-15) と
 *   「本体が例外を投げれば中身ごと消える」(P-10c) の合成で覆う。
 *   **プロセスの挙動を偽装する注入の継ぎ目ではない** — 起こし方も回収も BootProbeRunner のままである。
 *
 * ★**リポジトリの中には作らない** (正典 v1 (5) の fail-closed)。内側の退避先は
 *   BootProbeRunner が同じ検査を持つが、外側 (この環境ファイルの置き場) にも同じ境界が要る。
 *   判定は BootProbeRunner::isInside() を使う (境界規則を 2 か所で持たない)。
 * ★権限は callback を呼ぶ**前に**実効値で確かめる。どの失敗でも作った置き場所を消してから投げる。
 *
 * @template T
 * @param  callable(string): T  $body  引数は作った置き場所の絶対パス
 * @return T
 */
public static function withEnvironmentDirectory(?string $baseDirectory, callable $body): mixed
{
    $base = $baseDirectory ?? sys_get_temp_dir();

    // ★`Webmozart\Assert` を使わない — あちらは InvalidArgumentException を投げるので、
    //   呼び出し側の例外契約が RuntimeException と 2 本立てになってしまう。
    //   この境界は明示検査で RuntimeException に統一する。
    if (! str_starts_with($base, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException("観測用の置き場所は絶対パスであること: {$base}");
    }

    if (! is_dir($base) || ! is_writable($base)) {
        throw new RuntimeException("観測用の置き場所を使用できない: {$base}");
    }

    $created = rtrim($base, DIRECTORY_SEPARATOR).'/fake-wiring-probe-'.bin2hex(random_bytes(8));

    if (! mkdir($created, 0700) || ! is_dir($created)) {
        throw new RuntimeException("観測用の一時ディレクトリを作れない: {$created}");
    }

    try {
        $directory = realpath($created);
        if (! is_string($directory) || $directory === '') {
            throw new RuntimeException("観測用の一時ディレクトリを正規化できない: {$created}");
        }

        // 正典 (5) の fail-closed。ここを緩めると環境ファイルがリポジトリへ落ちる。
        // ★両辺とも realpath 済みで比べる (FakeClassCatalog::repoRoot() は dirname() の結果で
        //   正規化されていないため、symlink 越しだと素の比較が取り違える)。
        $repositoryRoot = realpath(FakeClassCatalog::repoRoot());
        if (! is_string($repositoryRoot) || $repositoryRoot === '') {
            throw new RuntimeException('リポジトリ root を正規化できない');
        }

        if (BootProbeRunner::isInside($repositoryRoot, $directory)) {
            throw new RuntimeException(
                "観測用の一時ディレクトリがリポジトリ内にある: {$directory}"
            );
        }

        // 実効の権限で確かめる (chmod の戻り値だけでは umask 等の影響を捕まえられない)。
        if (! chmod($directory, 0700) || self::mode($directory) !== 0700) {
            throw new RuntimeException("観測用の一時ディレクトリを 0700 にできない: {$directory}");
        }

        return $body($directory);
    } finally {
        self::removeDirectory($created);
    }
}

/**
 * 観測を 1 回走らせる。
 *
 * @param  positive-int  $timeoutSeconds
 * @return array{
 *     exitCode: int,
 *     output: array<string, mixed>,
 *     envFileValues: array<string, string>,
 *     caseEnvValues: array<string, string>,
 *     directory: string,
 *     directoryMode: int,
 *     envFileMode: int,
 *     temporaryRoot: string,
 *     writtenRelativePaths: list<string>,
 * }
 */
public static function run(
    string $environment,
    bool $fakeExternals,
    bool $fakeStorage,
    bool $fakeLlm,
    ?string $baseDirectory = null,
    int $timeoutSeconds = 120,
): array {
    // 置き場所の作成・リポジトリ外の fail-closed・0700 の確認・後片付けは helper が持つ。
    return self::withEnvironmentDirectory(
        $baseDirectory,
        static function (string $directory) use ($environment, $fakeExternals, $fakeStorage, $fakeLlm, $timeoutSeconds): array {
            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
            self::writeEnvFile($envFilePath, $values);

            $directoryMode = self::mode($directory);
            $envFileMode = self::mode($envFilePath);
            self::assertSafePermissions($directoryMode, $envFileMode);

            $caseEnv = self::caseEnvValues($directory);

            // 子の起こし方・回収・書き出し先の退避は共通 runner が持つ
            // (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
            $result = BootProbeRunner::run([self::probeScriptPath()], $caseEnv, $timeoutSeconds);

            return self::interpret($result, $values, $caseEnv, $directory, $directoryMode, $envFileMode);
        },
    );
}

/**
 * ケース別上書きの中身 (使い捨て鍵はここで作る)。
 *
 * @return array<string, string>
 */
public static function caseEnvValues(string $directory): array
{
    $values = [
        'FAKE_WIRING_PROBE_ENV_DIR' => $directory,
        'FAKE_WIRING_PROBE_ENV_FILE' => self::ENV_FILE_NAME,
        // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    ];

    foreach (array_keys($values) as $key) {
        if (! in_array($key, self::CASE_ENV_KEYS, true)) {
            throw new RuntimeException("ケース別上書きに置けないキー: {$key}");
        }
    }

    return $values;
}

/**
 * runner の結果を観測結果へ翻訳する (**純関数**。子を起こさずに負例を測れる)。
 *
 * ★fail-closed を 4 つ持つ:
 *   1. 制限時間超過 (`timedOut`) は**通常の非ゼロ終了と区別して例外**にする。
 *      false や非ゼロ終了へ落とすと「観測できなかった」ことが沈黙する (fail-open)
 *   2. 出力が空 → 例外 (観測が成立していない)
 *   3. JSON として読めない → 例外
 *   4. トップレベルが配列でない → 例外
 * ★判定には `timedOut` を使い、`exitCode === 124` を直接読まない
 *   (終了要求を受けてから自分で `exit(0)` する子は `timedOut` かつ `exitCode === 0` になりうる)。
 *
 * @param  array<string, string>  $envFileValues
 * @param  array<string, string>  $caseEnv
 * @return array{
 *     exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>,
 *     caseEnvValues: array<string, string>, directory: string, directoryMode: int,
 *     envFileMode: int, temporaryRoot: string, writtenRelativePaths: list<string>,
 * }
 */
public static function interpret(
    BootProbeResult $result,
    array $envFileValues,
    array $caseEnv,
    string $directory,
    int $directoryMode,
    int $envFileMode,
): array {
    if ($result->timedOut) {
        throw new RuntimeException(
            '観測用の子プロセスが制限時間を超えて強制終了された (観測が成立していない)。'
            ."終了コード: {$result->exitCode} / 標準エラー: ".$result->stderr
        );
    }

    return [
        'exitCode' => $result->exitCode,
        'output' => self::decode($result->stdout),
        'envFileValues' => $envFileValues,
        'caseEnvValues' => $caseEnv,
        'directory' => $directory,
        'directoryMode' => $directoryMode,
        'envFileMode' => $envFileMode,
        'temporaryRoot' => $result->temporaryRoot,
        'writtenRelativePaths' => $result->writtenRelativePaths,
    ];
}
```

`envFileValues()` からは `APP_KEY` の行を削る。
`decode()` / `writeEnvFile()` / `mode()` / `assertSafePermissions()` / `probeScriptPath()` /
`probeAppHost()` / `removeDirectory()` は**現行のまま**。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/ExternalFakeBootProbeTest.php` (S4 で扱う)。
  `FakeWiringProbeRunner` を参照する他のファイルは無い (設計時に実測: 参照は同 gate 1 本のみ)
- 削除される公開面: `ALLOWED_PROCESS_ENV_KEYS` (→ `CASE_ENV_KEYS`)。参照元は P-7 のみなので S4 で追随する

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (完全な array shape を PHPDoc で固定)
- [x] null 安全 (`?? -1` のような黙った既定値を持たない。`timedOut` は必ず例外へ)
- [x] 配列返却について: **プロセス実行結果の境界は `BootProbeResult` に統一**した。
      子の JSON payload だけが `array<string, mixed>` として残り、それは呼び出し側 gate が
      各キーを検証してから使う (「無型の配列が 1 つも無い」とは主張しない)
- [x] Generics の型パラメータが正しい (`array<string, string>` / `list<string>` /
      `withEnvironmentDirectory()` は `@template T` で戻り値を素通しする)

### テスト計画

S4 に集約する (呼び出し側 gate が唯一の利用者であるため)。

### リスク

- **`float $timeout` → `int $timeoutSeconds` の型変更**: `BootProbeRunner::run()` が `positive-int` を要求するため。
  影響を受ける呼び出しは P-10 の `0.01` 1 か所だけで、S4 で扱う
- **観測が変わらないことの確認**: 基底の `QUEUE_CONNECTION=database` / `CACHE_STORE=array` が
  観測 (container の解決結果・転送先 host) を変えないこと。**P-1 / P-2 / P-3 が主張を変えずに
  緑のままであること**がその実測になる

---

## S3: 子入口スクリプトへ実働証明の観測点を足す

### 変更箇所

- ファイル: `tests/Support/ExternalFakes/fake-wiring-probe.php`
  - `use Tests\Support\ExternalFakes\FakeWiringProbeRunner;` を**足す**
  - 先頭コメントの「責務は 4 つだけ」を**書き直す** (下記)
  - `bootstrap()` 直後に実働証明の印を書く
  - 出力 JSON へ `write_targets` / `key_digests` を足す

### 先頭コメントの書き直し要件

現行は「責務は 4 つだけ (DB へ接続しない / container から解決する / 転送先 URL を組み立てて読む /
終了コードを返す)」と書いているが、観測点が増えるので事実でなくなる。**責務 6 つ**へ改める:

1. DB へ接続しない
2. container から解決する
3. 転送先 URL を組み立てて読む (**偽物が有効なときだけ**)
4. **実働証明の印を `storage_path()` 経由で 1 本書く** (正典 v1 (5))
5. **起動しきったアプリが解決した書き出し先 8 種と、効いた鍵 2 種の digest を報告する**
6. 終了コードを返す

**観測しないもの**: HTTP サーバもブラウザも起動しない / 設定キャッシュ**有り**の起動は観測しない /
外部へ 1 度も通信しない (転送先は組み立てて URL を読むだけ)。

### 現行コード (抜粋)

```php
$app->make(Kernel::class)->bootstrap();

$resolved = [];
foreach (ExternalFakeDeclaration::swaps() as $swap) {
    $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
}
// …
fwrite(STDOUT, json_encode([
    'resolved' => $resolved,
    'redirect_host' => $redirectHost,
    'process_environment_keys' => $processEnvironmentKeys,
], JSON_THROW_ON_ERROR));
```

### 変更後コード

```php
use Tests\Support\ExternalFakes\FakeWiringProbeRunner;

// …

$app->make(Kernel::class)->bootstrap();

/*
 * ★正典 v1 (5) の**実働証明**の観測点 (lctl feature: subprocess-boot-probe-harness)。
 *   「書き出し先を環境変数で退避した」ことは、退避が**効いていなければ**既定の場所
 *   (リポジトリの storage/) へ書かれ、観測は緑のまま嘘になる。そこで
 *   Laravel の storage_path() 経由で印を 1 本置き、それが起動器の一時ディレクトリ配下に
 *   現れたことを呼び出し側 (P-13) が確かめる。
 *   置き場所 (storage/app/private) は起動器が事前に掘っている。
 */
$markerPath = $app->storagePath(FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
if (file_put_contents($markerPath, 'fake-wiring-probe') === false) {
    throw new RuntimeException("観測の印を書けない: {$markerPath}");
}

// …resolved / redirectHost の観測 (現行のまま) …

fwrite(STDOUT, json_encode([
    'resolved' => $resolved,
    'redirect_host' => $redirectHost,
    'process_environment_keys' => $processEnvironmentKeys,
    // ★P-14 (向き): 起動しきったアプリが解決した書き出し先。呼び出し側が
    //   「1 件残らず一時ディレクトリ配下で、リポジトリの外」であることを確かめる。
    'write_targets' => [
        'storage' => $app->storagePath(),
        'config_cache' => $app->getCachedConfigPath(),
        'routes_cache' => $app->getCachedRoutesPath(),
        'services_cache' => $app->getCachedServicesPath(),
        'packages_cache' => $app->getCachedPackagesPath(),
        'events_cache' => $app->getCachedEventsPath(),
        'view_compiled' => (string) config('view.compiled'),
        'log_path' => (string) config('logging.channels.single.path'),
    ],
    // ★P-8 (使い捨て鍵が子で効いたこと)。鍵そのものは出力しない (テスト出力へ鍵を流さない)。
    'key_digests' => [
        'app' => hash('sha256', (string) config('app.key')),
        'ciphersweet' => hash('sha256', (string) config('ciphersweet.providers.string.key')),
    ],
], JSON_THROW_ON_ERROR));
```

> **`echo` を使わない**: AGENTS.md の禁止する文の規約により `fwrite(STDOUT, …)` で書く (現行と同じ)。
> **例外は既存の `catch (Throwable $e)` が拾う**ので、印が書けなければ JSON の `error` として出て
> 非ゼロ終了になる (沈黙しない)。

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: S4 が新しいキー (`write_targets` / `key_digests`) を読む

### PHPStan適合チェック

- 対象外 (`tests/` は aicue の解析対象に含まれない)。`Webmozart\Assert\Assert` による実行時検証は現行のまま

### テスト計画

S4 に集約する。

### リスク

- **`production` ケースでは印が書かれない**: bootstrap が `ProductionEnvGuard` で落ちるため
  (印を書く行は `bootstrap()` の後に置く)。P-13 / P-14 / P-8 は `fake` ケースだけで測る
- **`storage/app/private` が掘られていること**への依存: runner の `createTemporaryRoot()` が
  掘る 6 つの下位のうちの 1 つ。掘られなくなれば `file_put_contents` が false を返して**例外**になる
  (fail-closed。沈黙しない)

---

## S4: 呼び出し側 gate を新契約へ揃え、正典 (5) の実働証明を足す

### 変更箇所

- ファイル: `tests/Architecture/ExternalFakeBootProbeTest.php`
  - L7 の `use Symfony\Component\Process\Exception\ProcessTimedOutException;` を削除し、
    `use Tests\Support\Process\BootProbeResult;` / `use Tests\Support\Process\BootProbeRunner;` を足す
  - **先頭 docblock を書き直す** (現行の「`env -i` で空にし、鍵 2 つは環境ファイルの使い捨て値」は
    載せ替え後に事実でなくなる。4 段の環境合成 / 鍵の置き場所が 2 つに分かれること / 書き出し先 7 キーの
    退避 / 実働証明を P-13・P-14 が持つこと、へ改める)
  - `externalFakeProbeRun()` の戻り値 shape を更新
  - **P-7 / P-8 / P-11 を書き換え**、**P-10 を 4 本へ分割**、**P-13 / P-14 / P-15 を追加**
  - **P-1〜P-6 / P-9 / P-12 は 1 文字も変えない**

### 検査ごとの扱い (主張の増減を明示する)

| 検査 | 扱い | 内容 |
|------|------|------|
| P-1〜P-6 / P-9 / P-12 | **変更なし** | 偽物/本物の厳密一致・転送先ホスト・production の起動失敗・fail-closed・環境ファイルの許可集合・権限と負のコントロール・宣言の型 |
| P-7 | **書き換え (同じ主張を新しい土台で) + 定数の pin を追加** | 子が受け取ったプロセス環境の集合が `継承(実在分) + 基底 3 + ケース別 3 + 予約 7` と**完全一致**し、危険接頭辞が 1 件も無い。**併せて `INHERITED_ENV_KEYS` / `RESERVED_ENV_KEYS` / `CASE_ENV_KEYS` をリテラルで完全一致 pin する** |
| P-8 | **強化** | 起動側の配列ではなく、**子で実際に効いた** `app.key` / `ciphersweet` の digest が、生成した使い捨て値と一致し、親の設定値とは一致しない |
| P-10 | **分割 (4 本)** | P-10 = 正常終了・非ゼロ終了で置き場所が残らない / P-10b = 作れない置き場所では子を起こさずに失敗し残骸なし / **P-10c = 本体が例外を投げても中身ごと消える** / **P-10d = リポジトリ内の置き場所は本体を呼ばずに拒否し残骸なし** |
| P-11 | **書き換え (同じ主張を新しい土台で)** | 設定キャッシュの退避先が runner の一時ディレクトリ配下で、**書かれていない** |
| P-13 | **追加 (正典 (5) の実働証明・実体)** | 子が `storage_path()` 経由で書いた印が `writtenRelativePaths` に現れる |
| P-14 | **追加 (正典 (5) の実働証明・向き)** | 子が解決した書き出し先 8 種が 1 件残らず一時ディレクトリ配下で、`base_path()` の外 |
| P-15 | **追加 (fail-closed の負例。子を起こさない)** | `interpret()` が `timedOut` / 空出力 / 非 JSON / 非配列 JSON で例外になる |

> **制限時間超過の後始末をどう覆うか (Round 1 の [Critical] への回答)**:
> 旧 P-10 は「timeout でも**外側**の置き場所が消える」ことを実 timeout (0.01 秒) で測っていた。
> `BootProbeRunner` の制限時間は `positive-int` (最小 1 秒) で、子の起動は実測 **0.28〜0.32 秒**なので、
> 呼び出し側から実 timeout を再現するには観測用スクリプトへ「眠る分岐」を足すことになり、
> **観測の責務を汚す**。そこで**後始末の足場そのもの (`withEnvironmentDirectory()`) を直接呼ぶ** P-10c を置く。
> timeout 経路がこの `finally` を通ることは、
> **P-15 (`interpret()` が `timedOut` で例外を投げる)** と **P-10c (本体の例外で置き場所が消える)** の
> **合成**で示す。制限時間と段階的強制終了そのものの実プロセス実測は、取り込んだ自己検査
> S7 / S12 / S14 が持つ (子を 30 秒眠らせて 1 秒で落とす形)。

### 変更後コード (主要部)

```php
test('P-7 子が実際に受け取ったプロセス環境が 4 段の合成結果と完全一致する', function (): void {
    // (0) 4 段の定数そのものをリテラルで pin する。実装側の定数から期待値を組み立てるだけだと、
    //     実装と期待値を同時に変えたときに緑のまま通ってしまう。
    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR'])
        ->and(BootProbeRunner::RESERVED_ENV_KEYS)->toBe([
            'LARAVEL_STORAGE_PATH',
            'VIEW_COMPILED_PATH',
            'APP_CONFIG_CACHE',
            'APP_ROUTES_CACHE',
            'APP_SERVICES_CACHE',
            'APP_PACKAGES_CACHE',
            'APP_EVENTS_CACHE',
        ])
        ->and(FakeWiringProbeRunner::CASE_ENV_KEYS)->toBe([
            'FAKE_WIRING_PROBE_ENV_DIR',
            'FAKE_WIRING_PROBE_ENV_FILE',
            'APP_KEY',
        ]);

    $run = externalFakeProbeRun('fake');
    $keys = $run['output']['process_environment_keys'] ?? null;
    expect($keys)->toBeArray();
    /** @var list<mixed> $keys */
    $actual = array_map(static fn (mixed $key): string => (string) $key, $keys);

    // (a) 危険な接頭辞が 1 件も無いこと (env -i の時代からの主張をそのまま維持する)。
    //     TESTING_FAKE_* は**プロセス環境へ載せない** (0600 の環境ファイルの中だけに置く)。
    foreach (['DB_', 'PG', 'AWS_', 'STRIPE_', 'TESTING_FAKE_', 'GOOGLE_'] as $prefix) {
        $leaked = array_values(array_filter(
            $actual,
            static fn (string $key): bool => str_starts_with($key, $prefix)
        ));
        expect($leaked)->toBe([], "禁止する接頭辞 {$prefix} のキーが子へ流れている");
    }

    // (b) 集合の完全一致 (deny-by-default)。「以下」ではないので 1 本足しただけで赤くなる。
    $inherited = array_values(array_filter(
        ['PATH', 'HOME', 'TMPDIR'],
        static function (string $key): bool {
            $value = getenv($key);

            return is_string($value) && $value !== '';
        },
    ));
    $expected = array_values(array_unique(array_merge(
        $inherited,
        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
        ['FAKE_WIRING_PROBE_ENV_DIR', 'FAKE_WIRING_PROBE_ENV_FILE', 'APP_KEY'],
        ['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'APP_CONFIG_CACHE',
            'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE'],
    )));
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

test('P-8 使い捨て鍵が子で実際に効き、親の設定値の複写ではない', function (): void {
    $run = externalFakeProbeRun('fake');

    $digests = $run['output']['key_digests'] ?? null;
    expect($digests)->toBeArray();
    /** @var array<string, mixed> $digests */

    // (a) 子で効いた APP_KEY が、起動側が生成した使い捨て値と一致する
    expect($digests['app'] ?? null)->toBe(hash('sha256', $run['caseEnvValues']['APP_KEY']));
    // (b) 子で効いた CIPHERSWEET_KEY が、環境ファイルへ書いた使い捨て値と一致する
    expect($digests['ciphersweet'] ?? null)->toBe(hash('sha256', $run['envFileValues']['CIPHERSWEET_KEY']));
    // (c) いずれも親の設定値の複写ではない
    expect($digests['app'])->not->toBe(hash('sha256', (string) config('app.key')))
        ->and($digests['ciphersweet'])
        ->not->toBe(hash('sha256', (string) config('ciphersweet.providers.string.key')));
});

test('P-10 正常終了・非ゼロ終了のいずれでも環境ファイルの置き場所が残らない', function (): void {
    foreach (['fake', 'real', 'production'] as $case) {
        $run = externalFakeProbeRun($case);

        expect(is_dir($run['directory']))->toBeFalse("一時ディレクトリが残っている: {$case}")
            ->and(array_values(array_diff(scandir($run['baseDirectory']) ?: [], ['.', '..'])))
            ->toBe([], "一時ディレクトリの親に残骸がある: {$case}");
    }
});

test('P-10b 作れない置き場所では子を起こさずに失敗し、残骸を残さない', function (): void {
    $base = sys_get_temp_dir().'/fake-wiring-probe-readonly-'.bin2hex(random_bytes(6));
    expect(mkdir($base, 0500))->toBeTrue();

    try {
        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base))
            ->toThrow(RuntimeException::class);

        expect(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
    } finally {
        rmdir($base);
    }
})->skip(
    // root で走ると 0500 でも書けてしまい、負のコントロールが成立しない。
    // **成功扱いにはしない** — 測れていないことをテスト結果に出す。
    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'root では書き込み権限の負のコントロールを作れない',
);

test('P-10c 本体が例外を投げても置き場所が中身ごと消える (制限時間超過の後始末)', function (): void {
    // 制限時間超過は interpret() が例外にする (P-15)。その例外が外側の finally を通ることを
    // ここで決定的に測る (実 timeout を作るには子を 1 秒以上眠らせる必要があり、
    // それは観測用スクリプトの責務を汚すので採らない)。
    // ★空のディレクトリではなく**中身のある**状態で測る — 実際の制限時間超過では
    //   .env.probe が既に書かれているので、再帰削除まで示さないと主張と距離がある。
    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
    expect(mkdir($base, 0700))->toBeTrue();

    $created = null;

    try {
        expect(function () use ($base, &$created): mixed {
            return FakeWiringProbeRunner::withEnvironmentDirectory(
                $base,
                static function (string $directory) use (&$created): mixed {
                    $created = $directory;

                    // 実際の走行と同じく環境ファイルを置き、さらに下位ディレクトリの中にも番兵を置く。
                    expect(file_put_contents($directory.'/.env.probe', "APP_ENV=x\n"))->not->toBeFalse();
                    expect(mkdir($directory.'/nested', 0700))->toBeTrue();
                    expect(file_put_contents($directory.'/nested/sentinel.txt', 'x'))->not->toBeFalse();

                    throw new RuntimeException('本体の失敗');
                },
            );
        })->toThrow(RuntimeException::class);

        // 置き場所は作られ (= 検査が空振りしていない)、中身ごと消えている。
        expect($created)->toBeString()
            ->and(is_dir((string) $created))->toBeFalse('置き場所が残っている')
            ->and(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
    } finally {
        rmdir($base);
    }
});

test('P-10d リポジトリ内の置き場所は本体を呼ばずに拒否し、残骸を残さない', function (): void {
    // 正典 v1 (5) の fail-closed を**外側**でも測る (内側は取り込んだ自己検査 S11 が持つ)。
    $base = base_path('storage/framework/testing');
    // ★このテストが作った場合だけ後で戻す (走行が生成物を残さないため)。
    $createdBase = ! is_dir($base);
    if ($createdBase) {
        expect(mkdir($base, 0755, true))->toBeTrue();
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
        if ($createdBase) {
            rmdir($base);
        }
    }
});

test('P-11 設定キャッシュの退避先が一時ディレクトリ配下で、書かれていない', function (): void {
    $run = externalFakeProbeRun('fake');

    $targets = $run['output']['write_targets'] ?? null;
    expect($targets)->toBeArray();
    /** @var array<string, mixed> $targets */
    $configCache = $targets['config_cache'] ?? null;
    expect($configCache)->toBeString();
    /** @var string $configCache */

    expect(BootProbeRunner::isInside($run['temporaryRoot'], $configCache))->toBeTrue()
        // 設定キャッシュ**無し**の起動を観測している (書かれていたら前提が崩れている)。
        ->and($run['writtenRelativePaths'])->not->toContain('bootstrap-cache/config.php');
});

test('P-13 実働証明(実体): 子が storage_path() 経由で書いた印が一時ディレクトリ配下に現れる', function (): void {
    $run = externalFakeProbeRun('fake');

    expect($run['writtenRelativePaths'])
        ->toContain('storage/'.FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
});

test('P-14 実働証明(向き): 子が解決した書き出し先が 1 件残らず一時ディレクトリ配下でリポジトリの外', function (): void {
    $run = externalFakeProbeRun('fake');

    $targets = $run['output']['write_targets'] ?? null;
    expect($targets)->toBeArray();
    /** @var array<string, mixed> $targets */

    $repositoryRoot = realpath(base_path());
    expect($repositoryRoot)->toBeString();
    /** @var string $repositoryRoot */

    $expectedKeys = ['storage', 'config_cache', 'routes_cache', 'services_cache',
        'packages_cache', 'events_cache', 'view_compiled', 'log_path'];
    expect(array_keys($targets))->toBe($expectedKeys, '観測点の集合が変わっている');

    foreach ($expectedKeys as $key) {
        $path = $targets[$key];
        expect($path)->toBeString();
        /** @var string $path */

        // 区切り文字を境界にした配下判定 (素の前方一致は /a と /ab を取り違える)。
        // isInside は同一パスも true にするので、base_path() 自身も「外ではない」に入る。
        expect(BootProbeRunner::isInside($run['temporaryRoot'], $path))
            ->toBeTrue("書き出し先 {$key} が一時ディレクトリの外を指している: {$path}")
            ->and(BootProbeRunner::isInside($repositoryRoot, $path))
            ->toBeFalse("書き出し先 {$key} がリポジトリ側を指している: {$path}");
    }
});

test('P-15 fail-closed: interpret() は観測が成立していない結果を沈黙させない', function (): void {
    $make = static fn (string $stdout, bool $timedOut, int $exitCode): BootProbeResult
        => new BootProbeResult(
            stdout: $stdout, stderr: '', exitCode: $exitCode, timedOut: $timedOut,
            elapsedSeconds: 0.1, temporaryRoot: '/tmp/boot-probe-x',
            writtenRelativePaths: [], pid: 1,
        );

    $call = static fn (BootProbeResult $result): array => FakeWiringProbeRunner::interpret(
        $result, [], [], '/tmp/dir', 0700, 0600,
    );

    // (a) 制限時間超過は通常の非ゼロ終了と区別して例外にする (fail-open 防止)
    expect(fn (): array => $call($make('{"resolved":{}}', true, 124)))->toThrow(RuntimeException::class);
    // (b) 空出力 / (c) JSON でない / (d) トップレベルが配列でない
    expect(fn (): array => $call($make('', false, 0)))->toThrow(RuntimeException::class);
    expect(fn (): array => $call($make('not json', false, 0)))->toThrow(RuntimeException::class);
    expect(fn (): array => $call($make('"scalar"', false, 0)))->toThrow(RuntimeException::class);
});
```

`externalFakeProbeRun()` の shape 更新 (`configCachePath` / `configCacheExists` を落とし、
`caseEnvValues` / `temporaryRoot` / `writtenRelativePaths` を足す) と `afterAll` の後片付けは現行のまま。

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイルのみ。`FakeWiringProbeRunner` の他の利用者は無い

### PHPStan適合チェック

- 対象外 (`tests/`)。ただし `output` の各キーは `expect()->toBeArray()` / `toBeString()` で
  型を確かめてから使う (現行の `externalFakeProbeResolved()` と同じ作法)

### テスト計画

- [x] バグ修正ではないので再現テストは不要。**載せ替え前に赤を作る**手順は「実装順 (fail-first)」に書く
- [x] 既存テスト `tests/Architecture/ExternalFakeBootProbeTest.php` の更新 (上表のとおり)
- [x] 新規: P-10b / P-10c / P-10d / P-13 / P-14 / P-15
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (本ファイルは DB を張らない)

### リスク

- **`root` で走らせると P-10b が `skip`**: 成功扱いにはならない (測れていないことがテスト結果に出る)
- **`write_targets` のキー集合を pin している**: 観測点を足したら赤になる = 意図しない拡張が黙って通らない

---

## S5: 経路 2 を**載せ替えない**理由を docblock へ明記する

### 判断とその根拠 (概念設計からの変更点)

概念設計は「経路 2 (`StrictTypesRuntimeProbe`) も共通 runner へ載せ替える」としていたが、**撤回する**。

| 根拠 | 内容 |
|------|------|
| 正典の boundary | 正典が測るのは「**起動順序**に由来する壊れ方」である。`declare(strict_types=1)` の実効性は単一ファイルのコンパイル指令であり、アプリの起動順序ではない |
| 無関係な前提が付く | runner に載せると Laravel 固有の基底環境 (`QUEUE_CONNECTION` / `CACHE_STORE`)・書き出し先 7 キーの予約・一時ディレクトリの構築・作業ディレクトリのリポジトリ root 固定という、検体の判定に無関係な前提が付く |
| `PhpLintOracle` との一貫性 | `tests/Support/GlobalUse/PhpLintOracle.php` も「PHP を子で起こすがアプリは起こさない」経路で、載せ替えない。片方だけ載せる根拠が無い |
| 意味が変わる | 現行の Symfony `Process` は親環境を継承するが、載せ替えると許可一覧のみになる。23 検体が通ることは、この意味変更が安全である証明にならない |
| **家系の先例** | **正典テンプレートは子プロセス起動 4 経路のうちアプリを起こす 3 本だけを載せ替え、残る 1 本 (`tests/Feature/Queue/QueueWorkerLeaseGuardTest.php`) は「載せ替えない理由を docblock へ明記」して残している** |

aicue で**アプリを起こす経路は経路 1 の 1 本だけ**なので、テンプレートと同じ捌き方で経路 2 を残す。

### 変更箇所

- ファイル: `tests/Support/StrictTypesRuntimeProbe.php` — **クラス docblock に 1 節足すだけ**。
  実装コードは 1 行も変えない

```php
 * ★**共通の起動器 (Tests\Support\Process\BootProbeRunner) には載せない**
 *   (lctl feature: subprocess-boot-probe-harness)。あちらが測るのは「**起動順序**に由来する
 *   壊れ方」であり、本クラスが測るのは単一ファイルのコンパイル指令が効くかである。
 *   載せるとアプリ起動用の基底環境・書き出し先 7 キーの予約・一時ディレクトリの構築という
 *   検体の判定に無関係な前提が付く。同じ理由で `tests/Support/GlobalUse/PhpLintOracle.php`
 *   (`php -l` の真値取り出し) も載せていない。
 *   ★回収は Symfony の Process に委ねる (既定の制限時間つきで、超過すれば例外になる)。
```

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし / テストファイル: なし (実装を変えないため)

### PHPStan適合チェック

- 対象外 (`tests/`)。コード変更が無いので現状維持

### テスト計画

- [x] 既存テスト `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` の 4 本は**主張も実装も変えない**
- [x] 新規テストなし (docblock のみの変更のため)。**この判断そのものは S6 の軸 A の申告
      (`launches_app: false` + 理由) として機械に登録される** = AGENTS.md 禁止事項 1 を満たす

### リスク

- なし (実行されるコードを変更しない)

---

## S6: 一元化に関する退行を検出する全数申告 gate を新設する

### 位置付け (再掲)

**正典 v1 の 6 不変条件のいずれでもない。aicue 側の上積みである。**
根拠は (a) 正典テンプレートも同型の gate を本 feature の追従で新設している、
(b) AGENTS.md 禁止事項 1 が不変条件のテスト登録を要求している、の 2 点。

### 変更箇所

- ファイル: `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` (新規)

### なぜテンプレートと同じパス・同じ実装にしないか

| 論点 | 判断 |
|------|------|
| パス名 | テンプレートの `tests/Architecture/SubprocessProbeLaunchGateTest.php` は**テンプレートの指紋台帳に登録済みの共有パス**である (設計時に実測)。そこへ aicue 固有の申告内容を置くと共有パスに食い違う内容が乗り、将来の指紋台帳の再生成で意図的逸脱の登録が必要になる。**aicue 既存の命名 (`FfmpegProcessLaunchInventoryTest`) に倣った固有名**を使う |
| 走査器 | テンプレートは `nikic/php-parser` の `NameResolver` を使うが、**aicue は php-parser を直接依存に持たず、アプリ・テストのどこでも使っていない** (vendor には larastan 経由で在るだけ。設計時に実測)。aicue の静的走査の基盤は `tests/Support/PhpTokenScan.php` (`token_get_all` の正規化) と `tests/Support/TrackedPhpSourceFiles.php` (git 追跡下の列挙) である |
| 走査対象 | **名前解決を要する判定 (どのクラスの `new` か / `proc_open` の別名 import か) を一切しない**。字句走査で決定できるのは「定数 `PHP_BINARY` を参照しているか」「文字列に特定のパスが現れるか」までであり、そこに主張を閉じる |

### 3 つの軸 (いずれも `PhpTokenScan::normalize()` の上に建てる)

| 軸 | 判定 | 走査後の実測 (載せ替え後) |
|---|------|------------------------|
| **軸 A (`PHP_BINARY` 参照)** | `T_STRING` かつ text が `PHP_BINARY` のトークンを持つ。**または** `use` `const` の並びに `PHP_BINARY` が現れる (別名 import の fail-closed) | 7 ファイル |
| **軸 B (アプリの起動点)** | 文字列トークン (`T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE`) の値が `bootstrap/app.php` を含む | 5 ファイル |
| **軸 C (子入口の参照)** | 文字列トークンの値が `fake-wiring-probe.php` を含む | 2 ファイル |

**コメント・docblock は `PhpTokenScan::normalize()` が落とすので数えない** (現行の
`FakeWiringProbeRunner` の docblock にある `fake-wiring-probe.php` は軸 C に入らない)。

### この gate が主張すること / 主張しないこと (docblock に書く)

**主張する**: 「`PHP_BINARY` の字句参照 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**」。

**主張しない** (名指しで書く):

1. 「アプリを子プロセスで起こす経路が共通の起動器ちょうど 1 本である」こと
2. 文字列リテラルの `'php'` / `env php` / シェルスクリプト経由 / 変数から取り出した実行体パスの検出
3. **起動呼び出しの分類** — 「どのクラスの `new` か」「`proc_open` かその別名か」といった判定は
   名前解決を要するので**一切行わない** (行えば「緑のまま嘘をつく」)
4. **名前の解決** — G-6 が照合するのは**名前トークンの末尾要素**という字句の一致であり、
   その名前が実際にどのクラスを指すかは解決しない。したがって:
   - **扱う**: `BootProbeRunner::run(` / `Tests\Support\Process\BootProbeRunner::run(`
     (`T_NAME_QUALIFIED`) / `\Tests\…\BootProbeRunner::run(` (`T_NAME_FULLY_QUALIFIED`) —
     いずれも末尾要素が `BootProbeRunner` なので**検出する**
   - **扱わない**: `use … as Runner; Runner::run(` — **別名は追えない** (負例として恒久テストに固定する)
5. 文字列を分割して針を避ける形 (`'fake-wiring-'.'probe.php'`) の検出

**一元化そのものの証拠は S2〜S4 の載せ替えの実測であり、本 gate は退行の検出器である。**

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

use Tests\Support\PhpTokenScan;
use Tests\Support\TrackedPhpSourceFiles;

/*
| `tests/` 配下の**3 種類の字句参照**の全数申告 inventory —
|   (A) 定数 `PHP_BINARY` の参照 / (B) 文字列 `bootstrap/app.php` の参照 /
|   (C) 文字列 `fake-wiring-probe.php` (既存の子入口) の参照。
| lctl feature: subprocess-boot-probe-harness (正典 v1 の作法へ追従したあとの退行を検出する)。
| **本 gate は正典 v1 の 6 不変条件ではなく aicue 側の上積みである** (根拠: 正典テンプレートの
| 同型 gate と AGENTS.md 禁止事項 1)。
|
| **名前のとおり、これは「起動の全数」ではなく「参照の全数」の inventory である。**
| 「PHP の子プロセスを起こしうる箇所を漏れなく数える」ことは**していない** (下記の主張しないこと)。
|
| 【主張すること】【主張しないこと】は上表のとおり (docblock へ逐語で書く)。
*/

/**
 * 軸 A: `tests/` 配下で `PHP_BINARY` を参照してよいファイルの全数申告 (deny-by-default)。
 *
 * entry は 4 つの欄を独立に持つ (「件数合わせの allowlist」へ流れないための構造):
 *  - `launches_app`: アプリを起こすと申告するか (**補助的な申告値**。実際の起動経路の
 *    全数性を表すものではなく、「アプリを起こす」と申告する先が分散していないことだけを固定する)
 *  - `subject` / `recovery` / `reason`
 *
 * @return array<string, array{launches_app: bool, subject: non-empty-string, recovery: non-empty-string, reason: non-empty-string}>
 */
function phpBinaryReferenceInventory(): array
{
    return [
        'tests/Support/Process/BootProbeRunner.php' => [
            'launches_app' => true,
            'subject' => 'アプリを子プロセスで起こして起動順序を測る (PHP_BINARY)',
            'recovery' => '本クラス自身 (制限時間・段階的強制終了・終了コードの保持・一時ディレクトリの後片付け)',
            'reason' => '共通の起動器そのもの (lctl feature: subprocess-boot-probe-harness)',
        ],
        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
            'launches_app' => false,
            'subject' => '起動器の自己検査。参照は期待値の比較と、子へ渡す検体文字列の中だけである',
            'recovery' => '起動器 (本ファイルは直接の起動 API を持たず、BootProbeRunner 経由でのみ子を起こす)',
            'reason' => 'バイト一致で取り込んだ共有ファイルなので編集しない。起動器を通してしか子を起こさない',
        ],
        'tests/Support/StrictTypesRuntimeProbe.php' => [
            'launches_app' => false,
            'subject' => '検体 PHP を子で読み込み declare(strict_types=1) の実効性を測る。アプリは起こさない',
            'recovery' => 'Symfony の Process (既定の制限時間つきで、超過すれば例外になる)',
            'reason' => '起動順序ではなく単一ファイルのコンパイル指令を測る層である。起動器に載せると '
                .'Laravel 固有の基底環境・書き出し先 7 キーの予約という無関係な前提が付く '
                .'(同じ理由で PhpLintOracle も載せていない)',
        ],
        'tests/Support/GlobalUse/PhpLintOracle.php' => [
            'launches_app' => false,
            'subject' => '`php -l` を真値として取り出す (構文検査のみ。アプリは起こさない)',
            'recovery' => '同クラス (Symfony Process が管を読み切り、終了コードが null なら例外にする)',
            'reason' => 'アプリを起動しないので環境の 3 段合成も書き出し先の退避も要らない',
        ],
        'tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php' => [
            'launches_app' => false,
            'subject' => 'テスト DB の用意スクリプトを起こす (DB へは接続しない)。アプリは起こさない',
            'recovery' => '同ファイルの helper (管を読み切って proc_close する)',
            'reason' => 'アプリの起動順序ではなくスクリプトの契約を測る層である '
                .'(lctl feature: php-test-pgsql-lane 側の関心事。本 feature とは distinct_from の関係)',
        ],
        'tests/Architecture/NoNonCompoundGlobalUseTest.php' => [
            'launches_app' => false,
            'subject' => '診断メッセージへ実行体のパスを載せるだけ (子は起こさない)',
            'recovery' => '該当なし (起動しない)',
            'reason' => '起動は PhpLintOracle が行い、本ファイルは失敗時の診断に PHP_BINARY を印字するだけである',
        ],
        'tests/Feature/Console/PipelineSmokeCommandTest.php' => [
            'launches_app' => false,
            'subject' => 'ffmpeg の代役として設定値へ実行体のパスを入れるだけ (テストから子は起こさない)',
            'recovery' => '該当なし (起動するのはアプリ側の合成経路であり、本 feature の射程外)',
            'reason' => 'アプリの起動順序を測る経路ではない (ffmpeg 起動の統制は '
                .'tests/Architecture/FfmpegProcessLaunchInventoryTest.php が持つ)',
        ],
    ];
}

/**
 * 軸 B: `tests/` 配下でアプリの起動点 (`bootstrap/app.php`) を参照してよいファイルの全数申告。
 *
 * `kind` は 3 値:
 *  - `child_entry` : 子プロセスで読み込まれる入口 / 子へ渡す検体文字列
 *  - `in_process`  : 同一プロセスでのアプリ起動 (子プロセスではない)
 *  - `inventory`   : 検査定義としてパス文字列を保持するだけ
 *
 * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', reason: non-empty-string}>
 */
function appBootEntryReferenceInventory(): array
{
    return [
        'tests/Support/ExternalFakes/fake-wiring-probe.php' => [
            'kind' => 'child_entry',
            'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
        ],
        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
            'kind' => 'child_entry',
            'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
        ],
        'tests/TestCase.php' => [
            'kind' => 'in_process',
            'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
        ],
        'tests/Architecture/CacheGuardWiringGateTest.php' => [
            'kind' => 'inventory',
            'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
        ],
        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
            'kind' => 'inventory',
            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
        ],
    ];
}

/**
 * 軸 C: 子入口スクリプトのパスを参照してよいファイルの全数申告。
 *
 * `reference_kind` は 2 値: `runtime` (実行経路として子入口を起こす) / `inventory` (検査定義)。
 *
 * @return array<string, array{reference_kind: 'runtime'|'inventory', reason: non-empty-string}>
 */
function childEntryReferenceInventory(): array
{
    return [
        'tests/Support/ExternalFakes/FakeWiringProbeRunner.php' => [
            'reference_kind' => 'runtime',
            'reason' => '子入口を起こす唯一の呼び出し元。起こし方と回収は BootProbeRunner に委ねる',
        ],
        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
            'reference_kind' => 'inventory',
            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
        ],
    ];
}
```

検査は **7 本**:

| # | 検査 |
|---|------|
| G-1 | 軸 A: 実測と申告のファイル集合が完全一致する |
| G-2 | 軸 A: `launches_app: true` の entry は `tests/Support/Process/BootProbeRunner.php` ちょうど 1 件 |
| G-3 | 軸 A: `subject` / `recovery` / `reason` の 3 欄がいずれも空でない |
| G-4 | 軸 B: 実測と申告のファイル集合が完全一致し、`kind` が 3 値のいずれかである |
| G-5 | 軸 C: 実測と申告のファイル集合が完全一致し、`reference_kind` が 2 値のいずれかである |
| G-6 | 軸 C: `reference_kind: runtime` はちょうど 1 件で、そのファイルは**トークン列 `BootProbeRunner` `::` `run` `(`** を持つ (未使用の `use` では通らない) |
| G-7 | 走査が空振りしていない (走査根が実在し、3 軸の母集団がいずれも非空) + 走査器の見本検査 |

G-7 の見本表 (`token_get_all` へ直接与える検体。ファイル走査を経由しない。**すべて恒久のテスト**):

**3 軸の判定**

| 検体 | 軸 A | 軸 B | 軸 C |
|------|-----|-----|-----|
| `<?php $x = [PHP_BINARY];` | 1 | 0 | 0 |
| `<?php // PHP_BINARY` (コメントのみ) | 0 | 0 | 0 |
| `<?php $s = "PHP_BINARY";` (文字列のみ) | 0 | 0 | 0 |
| `<?php use const PHP_BINARY as Runtime; $x = Runtime;` | **1** (fail-closed) | 0 | 0 |
| `<?php $x = MY_PHP_BINARY;` (接頭辞) | **0** | 0 | 0 |
| `<?php $x = NOT_PHP_BINARY;` (打ち消し) | **0** | 0 | 0 |
| `<?php $x = PHP_BINARY_PATH;` (接尾辞) | **0** | 0 | 0 |
| `<?php require 'bootstrap/app.php';` | 0 | 1 | 0 |
| `<?php // require bootstrap/app.php` (コメントのみ) | 0 | 0 | 0 |
| `<?php $p = __DIR__.'/fake-wiring-probe.php';` | 0 | 0 | 1 |
| `<?php $a = 'fake-wiring-'."probe.php";` | 0 | 0 | **0** (**射程外**。限界を期待値として固定する) |

**G-6 のトークン列判定 (名前トークンの**末尾要素**が `BootProbeRunner` + `::` + `run` + `(`)**

| 検体 | 判定 |
|------|------|
| `<?php BootProbeRunner::run([]);` | **あり** (正例。`T_STRING`) |
| `<?php Tests\Support\Process\BootProbeRunner::run([]);` | **あり** (`T_NAME_QUALIFIED` の末尾要素で照合) |
| `<?php \Tests\Support\Process\BootProbeRunner::run([]);` | **あり** (`T_NAME_FULLY_QUALIFIED` も同じ規則) |
| `<?php use Tests\Support\Process\BootProbeRunner as Runner; Runner::run([]);` | **なし** (**射程外**。別名は名前解決を要する。限界を期待値として固定する) |
| `<?php use Tests\Support\Process\BootProbeRunner;` (未使用の import のみ) | **なし** |
| `<?php // BootProbeRunner::run(` (コメントのみ) | **なし** |
| `<?php $s = "BootProbeRunner::run(";` (文字列のみ) | **なし** |
| `<?php OtherBootProbeRunner::run([]);` (接頭辞つきクラス名) | **なし** |
| `<?php BootProbeRunnerX::run([]);` (接尾辞つきクラス名) | **なし** |
| `<?php BootProbeRunner::runner([]);` (接尾辞つきメソッド名) | **なし** |
| `<?php BootProbeRunner::RUN;` (定数参照) | **なし** |

### 【実装時に確定した事項】 (Codex 実装レビュー Round 1〜5 と main の前進を反映)

本節の設計 (G-1〜G-7 / 3 軸の申告) はそのまま実装したが、レビューと main の前進で次を足した:

| 追加・変更 | 内容 |
|---|---|
| G-6 の判定を **FQCN 解決**へ | 字句の末尾一致では別名 import で黙り、同名の別クラスを誤検出する (規約 (a))。`Tests\Support\PhpReferenceScanner` を使い、受け手が静的に確定できない形は**証拠に数えない** (存在を主張する検査なので fail-closed 側) |
| **G-8 を新設** | 子入口の**環境ファイル隔離の分類** (`env_isolation`: `behavioural` / `structural` / `none`) と根拠の記載を deny-by-default で強制する。`none` は 0 件、`structural` の集合は完全一致 pin。**`structural` の経路について「実際に `.env` を読まない」とは主張しない** |
| **G-9 を新設** | `child_entry` は `$app->useEnvironmentPath(` を**4 トークンの完全一致**で持つ (実コード / 子へ渡す検体文字列の中を字句解析し直す。素の部分文字列一致は使わない = 規約 (e))。`behavioural` の根拠は実在パスを名指しする |
| 軸 A の申告が 7 → 8 件 | main の前進で `tests/Support/Concurrency/SymfonyProbeProcessFactory.php` (別 feature `process-concurrency-test-harness` の起こし手) が入った |
| **G-2 を「1 件固定」→「2 件の完全一致 pin」へ** | 本 feature の boundary は「子を 2 本立てて合図で同期させる並行テスト」を明示的に除く。別 feature が自分の回収規約 (単一の絶対 deadline) を持つので統合しない (思考原則 4)。固定するのは**申告先の集合**であり「起動経路が 1 本」ではない |
| 軸 B の申告が 5 → 8 件 | `idempotency-claim-probe.php` (`child_entry`) / `RemovedSurfaceScanTargets.php` (`inventory`) ほか、main の前進分 |

呼び出し側 gate も P-16 (正規化判定の検出力) と **P-17 (子が読んだ環境ファイルの絶対パスが
起動側の専用ファイルと完全一致する)** を足してある。

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイルのみ新規。**既存テストは 1 本も変更しない**

### PHPStan適合チェック

- 対象外 (`tests/`)。申告の shape は PHPDoc で固定し、`kind` / `reference_kind` は文字列リテラル型で閉じる

### テスト計画

- [x] 新規テスト: G-1 〜 G-7 (上表)
- [x] 負例が効くことの確認 (実装時に手で 1 度試す。**恒久のテストにはしない** — 実ファイルを
      一時的に汚す形になるため): 未登録ファイルへ `PHP_BINARY` を足すと G-1 が赤 /
      `runtime` のファイルから `BootProbeRunner::run(` を消すと G-6 が赤
- [x] 走査器の見本検査 (G-7) は**恒久のテスト**として持つ (実ファイルを汚さない)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (DB を張らない)

### リスク

- **`TrackedPhpSourceFiles` は git 追跡下しか見ない**: **新規 4 ファイルは `git add` するまで走査に入らない**。
  実装時にこれを落とすと G-1 が実測 0 件寄りになったまま緑に見える。
  **実装順の段 8 で `git add` 後にもう一度全体を走らせる**ことを手順に入れる
- **軸 B の母集団が広い**: `bootstrap/app.php` は文字列で 5 ファイルに現れる。将来増えるたびに申告が要る =
  意図した摩擦である。無関係な理由で増えるなら `kind: inventory` として 1 行足すだけで済む
- **`--parallel` 安全**: 3 軸とも読み取りのみで、プロセスも DB も張らない

---

## 実装順 (fail-first)

| 段 | やること | ここで何が赤になるか |
|---|---------|-------------------|
| 1 | S1: **自己検査 1 本だけ**を配置する | `Tests\Support\Process\BootProbeRunner` が未定義で**赤** |
| 2 | S1: 実装 2 本を配置する | 段 1 の赤が緑へ。`vendor/bin/pint --test` で非破壊確認 (落ちたら整形せず報告して止まる) |
| 3 | S6: gate を新設する。申告は**載せ替え後の姿**で書く | 軸 A の実測に旧 `FakeWiringProbeRunner.php` (`PHP_BINARY` を直接持つ) が現れて G-1 が**赤**。軸 C も G-6 が**赤** (`runtime` の参照元がまだ `BootProbeRunner::run(` を持たない) |
| 4 | S4: P-13 / P-14 / P-15 / P-10c と P-7 の定数 pin・P-8 の新契約を**先に書く** | 子がまだ印も `write_targets` も `key_digests` も返さないので**赤**。`interpret()` / `withEnvironmentDirectory()` / `CASE_ENV_KEYS` / `MARKER_RELATIVE_PATH` が無いので**未定義で赤** |
| 5 | S2 + S3 を実装する | 段 4 の赤が緑へ。P-7 / P-10 / P-10b / P-11 もここで新契約へ揃える |
| 6 | S5: `StrictTypesRuntimeProbe` の docblock に「載せ替えない理由」を足す | (赤は生じない。判断の登録は段 3 の軸 A の申告が担う) |
| 7 | S6 の申告を実測へ合わせる (旧 `FakeWiringProbeRunner` が軸 A から落ちる) | 段 3 の赤が緑へ |
| 8 | **新規 4 ファイルを `git add` してから**全体を走らせる | `TrackedPhpSourceFiles` が新規ファイルを見るようになる。ここで G-1 / G-4 / G-5 の集合一致を最終確認する |
| 9 | 受入条件の全コマンドを走らせる | — |

**実装時に必ず確かめること (Codex 詳細設計レビュー Round 4 の申し送り)**:

1. `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` の**末尾要素の抽出**が G-7 の見本表どおりに動くこと
   (PHP の版によってトークンの分かれ方が違う可能性があるので、見本で先に確かめる)
2. P-10d の基底 (`storage/framework/testing`) を**新規作成した環境**でも、
   親階層へ生成物を残さないこと
3. 全体実行は `--parallel`、新規 2 ファイルの測定は **`composer test` の引数転送が実際に効く形**
   (`composer test -- <path> <path>`) で走ること — 効かないなら `vendor/bin/pest <path> <path>` を使う
4. **`vendor/bin/pint --test` の確認後も、取り込んだ 3 ファイルの sha256 が変わっていないこと**

## 受入条件

**AGENTS.md L336-338 の検証コマンドを全件緑にする** (PHP のみの変更でも全件が規約である):

| コマンド | 期待 |
|---------|------|
| `composer test` | 緑。**`--parallel` で 2 回連続**走らせる |
| `composer phpstan` | エラー **0 のまま** (アプリコードを変更しないので現状維持) |
| `vendor/bin/pint --test` | 緑。**取り込んだ 3 ファイルに差分が出たら整形せず報告して止まる** |
| `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` | 緑 (本設計は JS を触らないので現状維持の確認) |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 緑 (同上) |

個別に緑を確認するテスト (通常のテストコマンドで。設計時の手動実測では代替しない):

- `tests/Unit/Support/Process/BootProbeRunnerTest.php` (S1〜S14)
- `tests/Architecture/ExternalFakeBootProbeTest.php` (P-1〜P-15。P-10 は 4 本)
- `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` (既存 4 本。**主張も実装も変えない**)
- `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` (G-1〜G-7)

**取り込みの同一性**: 配置後の sha256 が取得時の値と一致するのは実装 2 本
(`bd21b337…` / `00b14167…`) だけである。自己検査 (`9db128d8…`) は上記の
**【実装時の変更】**により意図的にバイト一致を崩した。

**生成物を残さないこと** (2 本立て):

1. **追跡下**: 意図した新規 4 ファイルを `git add` した直後を基準に、テスト走行前後で
   `git status --porcelain` が**変化しない**
2. **ignored な既知の書き出し場所**: `storage/logs/` / `storage/framework/views/` / `bootstrap/cache/` / `storage/framework/testing/` の
   **相対パス一覧と各ファイルの sha256** を走行前後で取り、**一致する**ことを確認する
   (「見比べる」ではなく機械で突き合わせる)。ただし `--parallel` では他の worker も書くので、
   **単独実行 (`composer test -- --filter=ExternalFakeBootProbe`) で確認する**

**実行時間の増分が説明できること** (「後退が無いこと」ではない — 取り込んだ自己検査の
S7 / S12 / S14 は制限時間 1 秒 + 猶予 2 秒などの**固定の待ち時間**を持つので総実時間は原理的に増える):

**除外オプションに依存せず、全体走行 3 本の引き算で測る** (Pest の `--exclude-filter` は
テスト**名**のパターンを除くものでファイルを除外できないため):

| 測定 | コマンド |
|------|---------|
| (a) 実装**前**の全体 | `composer test` (`--parallel`) |
| (b) 実装**後**の全体 | `composer test` (`--parallel`) |
| (c) 実装**後**の新規 2 ファイルだけ | `composer test -- tests/Unit/Support/Process/BootProbeRunnerTest.php tests/Architecture/PhpBootProbeReferenceInventoryTest.php` |

- 試行回数: 各 3 回。集計は**中央値**。**(a) (b) (c) の中央値を必ず併記して報告する**
  (差だけを出すとノイズかどうか判断できない)
- 判定: **(b) − (a) − (c) が (a) の 5% 以内**
  (= 新規ファイルの固定コストを差し引いた残りが、既存テストへの影響。
  S5 の載せ替えを取りやめたので**ほぼ 0 であるべき**)
- 超えたら**閾値を動かさず原因を報告する**

## 乖離台帳の確認 (app-design Phase 3-0)

`docs/template-fingerprints.json` の `entries` (281 パス) と
`tests/Support/TemplateDivergence/adoption-debt.tsv` (171 件) を設計時に実測で確認した結果:

| 判定対象 | 指紋台帳のキーに在るか | 採用時債務に在るか | 本設計での扱い |
|---------|--------------------|-----------------|--------------|
| `tests/Support/Process/BootProbeRunner.php` / `BootProbeResult.php` / `tests/Unit/Support/Process/BootProbeRunnerTest.php` (取り込み 3 件) | **無い** (aicue が未受領のテンプレートパス) | 無い | **バイト一致で取り込む**。将来 指紋台帳を再生成しても記録値と一致して母集合に入り、**逸脱 0 件・債務 0 件**になる。今回は台帳を触らない (再生成は他パスの再観測を巻き込む世代操作であり別議題) |
| `tests/Architecture/SubprocessProbeLaunchGateTest.php` (テンプレートの同型 gate) | **無い** (aicue は未受領) | 無い | **このパスを使わない**。テンプレート側では**指紋台帳に登録済みの共有パス**なので、aicue 固有の申告内容を置くと将来の再生成で逸脱の登録が要る。aicue 既存の命名に倣った `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` を使う |
| `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` / `fake-wiring-probe.php` / `tests/Architecture/ExternalFakeBootProbeTest.php` / `tests/Support/StrictTypesRuntimeProbe.php` (変更 4 件) | **無い** (いずれも aicue 固有のテスト支援コード) | 無い | 指紋機構の母集合外。**逸脱の登録は行わない** |
| `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` (新規 1 件) | **無い** (テンプレートに存在しないパス) | 無い | 同上。テンプレート自身も「呼び出し側とドメイン結線部は各アプリの持ち物」という分類を採っているので、「テンプレートの形から外れた判断」ではない |
| `phpstan.neon` | **在る** | **在る** | **触らない**。債務パスは「変更したまま債務に残す」を選べず (突合 gate の `mutatedDebtPaths` が落ちる)、(1) 採用時の姿へ戻す / (2) テンプレートへ同期して債務から削る / (3) 意図的逸脱として登録して債務から削る の 3 択を迫られる。いずれも本 TODO の目的と無関係な重い操作なので、解析対象は現状のまま据え置く |
| `tests/Architecture/NoNonCompoundGlobalUseTest.php` (軸 A で**申告するだけ**のファイル) | **在る** | **在る** | **1 行も変更しない**。gate の申告に載せるだけでファイル自体には触れない (触ると債務の 3 択を迫られる) |
| `tests/TestCase.php` / `tests/Architecture/CacheGuardWiringGateTest.php` (軸 B で**申告するだけ**) | 設計時の実測では**無い** | 無い | **1 行も変更しない** (申告に載せるだけ) |
| `docs/architecture.md` / `docs/template-divergence.md` | — | — | **触らない**。正典は文書を要求しておらず、道具の説明は各ファイルの docblock を正本にする |

- `LedgerPins::DIVERGENCE_ENTRY_COUNT` (36) / `FINGERPRINT_POPULATION_COUNT` (281) /
  `ADOPTION_DEBT_COUNT` (171) は**いずれも変更しない** (登録の追加・削除が無いため)
- **「登録するか迷ったら登録する」の原則との関係**: 本設計の新設・変更分は
  (a) 取り込む 3 本はテンプレートと**バイト一致**であり逸脱ではない、
  (b) 変更する 4 本と新設する 1 本は**テンプレートに無い aicue 固有の領域**への上積みである、
  (c) 共有パスへ食い違う内容を置く唯一の候補 (テンプレートの gate パス) は**意図的に避けた**、
  の 3 点から**逸脱を 1 件も作らない**。したがって登録の対象にならない

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規 4 本 (S1 の 3 / S6 の 1) + 既存 4 本の変更で、変更はすべて `tests/` 配下に閉じる。「実装順 (fail-first)」の 9 段に依存の鎖があり (取り込み → gate の赤 → 呼び出し側の赤 → 載せ替え → 申告の追随 → `git add` 後の再走)、分割すると各段の赤を確認できない。`composer test` 全体と `--parallel` の 2 回実行まで含めて 1 本の worktree で完結する |
| 競合リスク | 低。`tests/Support/Process/` は新設ディレクトリで、変更する 4 本はいずれも本 feature 専用の狭い領域である。`docs/`・`app/`・台帳ファイル・`phpstan.neon` を触らないので、他 worktree の変更と行単位で衝突しない |

## スコープ外 (明示)

1. **アプリコード (`app/` / `routes/` / `config/` / `database/` / `bootstrap/`) の変更** — 1 バイトも触らない
2. **`docs/` の変更** — 正典は文書を要求していない。道具の説明は各ファイルの docblock を正本にする
3. **指紋台帳 (`docs/template-fingerprints.json`) の再生成と `LedgerPins` の件数更新** — 世代操作なので別議題
4. **`phpstan.neon` へのテストパスの追加** — 採用時債務パスなので触らない (上の表)
5. **観測対象の拡張** — 観測対象となる外部 fake の種類は増やさない。内訳:
   追加する (P-13 / P-14 = 正典 (5) の実働証明、P-15 / P-10c = fail-closed と後始末の負例) /
   強化する (P-8 = 起動側の配列確認 → 子での実効値確認) /
   言い直すだけ (P-7 / P-11) / 分割するだけ (P-10) / 一切変えない (P-1〜P-6 / P-9 / P-12)
6. **`tests/Support/StrictTypesRuntimeProbe.php` の共通 runner への載せ替え** — アプリを起こさない経路であり
   正典の boundary の外。**載せ替えない理由を docblock と gate の申告に残す** (S5)。
   **再判定の条件**: 当該経路がアプリの起動を伴うようになったとき、または「アプリを起こさない PHP 子プロセス」の
   回収規約を家系が別 feature として立てたとき
7. **`tests/Support/GlobalUse/PhpLintOracle.php` の載せ替え** — 同上 (`php -l` はアプリを起動しない)
8. **`proc_open` を直呼びする既存 3 経路の載せ替え**
   (`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` / `tests/Architecture/SkillsLockIgnoreCoverageTest.php` /
   `tests/Architecture/GitIndexNormalizationTest.php`) — `git` / シェルスクリプト / 別スクリプトの起動であり、
   **PHP の実行体でアプリを起こす経路ではない**
9. **子を 2 本立てて合図で同期させる並行テスト** — 別 feature (`process-concurrency-test-harness`)
10. **`tests/` 全域のプロセス起動 API の全数申告** — 実測すると**母集団は 25 ファイル**で、
    `Process::fake()` の単体テストや `git ls-files` の列挙まで含む。3 欄の申告を 25 件書くのは
    本 TODO の目的から外れた別作業である。**加えて aicue は名前解決器を持たない**ので、
    字句走査で起動呼び出しを分類すると誤検出と見逃しの両方が出る。
    **再判定の条件**: 本設計の gate が捕まえられない形で子プロセスの起動が実際に足されたとき、
    または `nikic/php-parser` が aicue の直接依存に入ったとき
11. **`docs/TODO.md` への登録** — `/app-todo-add` の責務


---

## Round 4 + Round 5 の修正差分 (main 取り込み後の commit 24794d23 との差分)

```diff
diff --git a/tests/Architecture/ExternalFakeBootProbeTest.php b/tests/Architecture/ExternalFakeBootProbeTest.php
index 9aecfd03..65107739 100644
--- a/tests/Architecture/ExternalFakeBootProbeTest.php
+++ b/tests/Architecture/ExternalFakeBootProbeTest.php
@@ -428,8 +428,18 @@ static function (string $directory) use (&$bodyCalled): mixed {
         expect($bodyCalled)->toBeFalse('リポジトリ内なのに本体が呼ばれた')
             ->and(glob($base.'/fake-wiring-probe-*'))->toBe($before, '拒否経路が残骸を残している');
     } finally {
-        // 深い順に戻す (作った分だけ)。
+        // 深い順に戻す (作った分だけ)。★`--parallel` の他 worker が同じ場所を使うので、
+        //   **空でなければ触らない** (無条件の rmdir は他 worker の生成物と競合する)。
         foreach ($createdAncestors as $directory) {
+            if (! is_dir($directory)) {
+                continue;
+            }
+
+            $remaining = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
+            if ($remaining !== []) {
+                break;
+            }
+
             rmdir($directory);
         }
     }
@@ -515,6 +525,24 @@ static function (string $directory) use (&$bodyCalled): mixed {
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
diff --git a/tests/Architecture/PhpBootProbeReferenceInventoryTest.php b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
index 1f45c343..3b7acca3 100644
--- a/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
+++ b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
@@ -22,6 +22,8 @@
 |
 | 「`PHP_BINARY` の字句参照 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
 | 既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**」。
+| 加えて「子入口 (`child_entry`) は**環境ファイルの退避を字句として持ち**、裏取りの名指しは
+| **実在するパス**である」(G-9)。
 |
 | ## 主張しないこと (名指しで書く)
 |
@@ -32,6 +34,8 @@
 |     網羅的な分類は**行わない** (行えば「緑のまま嘘をつく」)。
 |     G-6 が確かめるのは**共通の起動器への静的呼び出しが在ること**だけである
 |  4. 文字列を分割して針を避ける形 (`'fake-wiring-'.'probe.php'`) の検出
+|  5. **環境ファイルの退避が実際に効く位置に在ること** (G-9 は字句の在否だけを見る。
+|     位置の正しさは各経路の実挙動の検査が担う)
 |
 | ## 軸ごとの名前解決の扱い (AGENTS.md §静的検査の共通規約 (a) / (b))
 |
@@ -92,7 +96,16 @@ function phpBootProbeBinaryReferenceInventory(): array
             'launches_app' => false,
             'subject' => '起動器の自己検査。参照は期待値の比較と、子へ渡す検体文字列の中だけである',
             'recovery' => '起動器 (本ファイルは直接の起動 API を持たず、BootProbeRunner 経由でのみ子を起こす)',
-            'reason' => 'バイト一致で取り込んだ共有ファイルなので編集しない。起動器を通してしか子を起こさない',
+            'reason' => 'テンプレートから取り込んだ共有ファイルである (T249 のローカル修正 1 件を除いて '
+                .'バイト一致。修正の理由は当該 docblock)。起動器を通してしか子を起こさない',
+        ],
+        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php' => [
+            'launches_app' => true,
+            'subject' => '実プロセス 2 本を合図で同期させる並行テストの子を起こす (子はアプリを起動する)',
+            'recovery' => '同 harness の runner (単一の絶対 deadline + 段階的強制終了。Symfony 側の制限時間は無効化)',
+            'reason' => '別 feature (lctl: process-concurrency-test-harness) の正典 v1 が持つ回収規約に属する。'
+                .'本 feature (subprocess-boot-probe-harness) の boundary は「子を 2 本立てて合図で同期させる '
+                .'並行テスト」を明示的に除いているので、共通の起動器へは載せない',
         ],
         'tests/Support/StrictTypesRuntimeProbe.php' => [
             'launches_app' => false,
@@ -139,11 +152,21 @@ function phpBootProbeBinaryReferenceInventory(): array
  *  - `in_process`  : 同一プロセスでのアプリ起動 (子プロセスではない)
  *  - `inventory`   : 検査定義・診断文としてパス文字列を保持するだけ
  *
- * `boots_repository_env` は「その経路で起きた**子**が、リポジトリの `.env` を読んで起動するか」。
- * **これは望ましさの宣言ではなく、危険面の目録である** (G-8 が件数と場所を pin する)。
- * 詳細は G-8 の docblock を読むこと。
+ * `env_isolation` は**子入口だけが持つ**欄で、「リポジトリの `.env` を読まないことを
+ * **何が守っているか**」を 3 値で分類する:
+ *
+ *  - `behavioural` : **実挙動の検査が在る** (子が読んだ環境ファイルの場所を実測して固定している)
+ *  - `structural`  : **退避の呼び出しが在ることを字句で pin しているだけ** (G-9)。
+ *    実挙動の裏取りは無いので、**この経路について「実際に読まない」とは主張しない**
+ *  - `none`        : どちらも無い (**申告できる値だが、G-8 が 0 件で pin する**)
  *
- * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', boots_repository_env: bool, reason: non-empty-string}>
+ * `env_isolation_proof` は上の分類の根拠 (`behavioural` なら検査の名前)。
+ * 子入口でない kind (`in_process` / `inventory`) は `env_isolation` を `null`、
+ * 根拠を空文字にする (子が居ないので分類の対象が無い)。
+ *
+ * 詳細と、この分類で**何を主張しないか**は G-8 の docblock を読むこと。
+ *
+ * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', env_isolation: 'behavioural'|'structural'|'none'|null, env_isolation_proof: string, reason: non-empty-string}>
  */
 function phpBootProbeAppBootEntryReferenceInventory(): array
 {
@@ -151,45 +174,75 @@ function phpBootProbeAppBootEntryReferenceInventory(): array
         'tests/Support/ExternalFakes/fake-wiring-probe.php' => [
             'kind' => 'child_entry',
             // 専用の 0600 環境ファイルへ固定して起動する (リポジトリの .env は読まない)。
-            'boots_repository_env' => false,
+            'env_isolation' => 'behavioural',
+            'env_isolation_proof' => 'tests/Architecture/ExternalFakeBootProbeTest.php P-17 '
+                .'(子が報告した環境ファイルの絶対パスが、起動側が用意した専用ファイルと完全一致する) '
+                .'+ 同 P-8 (子で実際に効いた鍵が専用ファイルの使い捨て値と一致し、親の設定値とは一致しない)',
             'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
         ],
+        'tests/Support/Concurrency/idempotency-claim-probe.php' => [
+            'kind' => 'child_entry',
+            // 段 8 で useEnvironmentPath() / loadEnvironmentFrom() を専用の一時 env ファイルへ向ける。
+            // ★実挙動の裏取りは無い (この経路について「実際に読まない」とは主張しない)。
+            'env_isolation' => 'structural',
+            'env_isolation_proof' => '段 8 の $app->useEnvironmentPath() / loadEnvironmentFrom() を '
+                .'G-9 が字句で pin するだけである。読んだ環境ファイルの場所を実測する検査は無い。'
+                .'足すには子の観測 DTO (Tests\Support\Concurrency\ConcurrentProbeObservation) から '
+                .'親までの 4 段を変えることになり、それは別 feature '
+                .'(lctl: process-concurrency-test-harness) の契約なので本 TODO では行わない',
+            'reason' => '実プロセス並行テストの子入口。別 feature (process-concurrency-test-harness) の持ち物である',
+        ],
         'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
             'kind' => 'child_entry',
-            // ★S9 / S10 の検体はリポジトリ root を作業ディレクトリにして bootstrap/app.php を
-            //   読むため、**リポジトリの .env がそのまま子の設定に載る** (実測で確認済み。G-8)。
-            'boots_repository_env' => true,
+            // ★T249 のローカル修正で、S9 / S10 の検体は起動前に環境ファイルの置き場所を
+            //   起動器の一時ディレクトリへ逃がす (取り込み元の姿ではリポジトリの .env を読んでいた)。
+            'env_isolation' => 'behavioural',
+            'env_isolation_proof' => 'tests/Unit/Support/Process/BootProbeRunnerTest.php S9 '
+                .'(子が報告した環境ファイルの絶対パスが <一時ディレクトリ>/.env と完全一致し、'
+                .'その場所に環境ファイルが実在しないこと + 環境ファイルからしか来ない設定値 2 つが空であること)',
             'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
         ],
         'tests/TestCase.php' => [
             'kind' => 'in_process',
             // 同一プロセスなので phpunit.xml の <server force> が効く (秘密は無害化済み)。
-            'boots_repository_env' => false,
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
             'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
         ],
         'tests/Support/Cache/IsolatedApplicationProbe.php' => [
             'kind' => 'in_process',
-            'boots_repository_env' => false,
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
             'reason' => 'キャッシュ受け皿の結線を測るための第 2 のアプリを同一プロセスで組み立てる。子プロセスではない',
         ],
         'tests/Architecture/CacheGuardWiringGateTest.php' => [
             'kind' => 'inventory',
-            'boots_repository_env' => false,
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
             'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
         ],
         'tests/Architecture/BughuntExecutedRouteOrderingTest.php' => [
             'kind' => 'inventory',
-            'boots_repository_env' => false,
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
             'reason' => '記録器の位置を固定する検査が、違反時の直し方を案内する診断文にパス文字列を持つ',
         ],
         'tests/Architecture/InertiaErrorScreenContractTest.php' => [
             'kind' => 'inventory',
-            'boots_repository_env' => false,
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
             'reason' => '例外応答の最終整形スロットの登録位置を検査する側が、照合する場所としてパス文字列を持つ',
         ],
+        'tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php' => [
+            'kind' => 'inventory',
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
+            'reason' => '撤去表面の走査対象の定義が、走査根の 1 つとしてパス文字列を持つ',
+        ],
         'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
             'kind' => 'inventory',
-            'boots_repository_env' => false,
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
             'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
         ],
     ];
@@ -306,6 +359,90 @@ function phpBootProbeCallsBootProbeRunner(string $relativePath, string $source):
     return false;
 }
 
+/**
+ * 正規化済みトークン列が**環境ファイルの退避の呼び出し**
+ * `$app` `->` `useEnvironmentPath` `(` を持つか (4 トークンの**完全一致**)。
+ *
+ * ★受け手を `$app` に固定する。名前だけを見ると `$unrelated->useEnvironmentPath(…)` も
+ *   証拠になってしまい、**存在を肯定する検査で拾いすぎる** (AGENTS.md §静的検査の共通規約 (b))。
+ *   変数の型は字句では解決できないので、**受け手の綴りまで固定する**のが本 gate で取れる
+ *   いちばん強い形である (摩擦は「子入口では `$app` という名前で受ける」だけ)。
+ * ★語彙一致は区切り (`->`) で割ったトークンの完全一致で判定するので、
+ *   接頭辞つき (`myUseEnvironmentPath`) / 打ち消しつき (`notUseEnvironmentPath`) /
+ *   接尾辞つき (`useEnvironmentPathX`) は**別トークン**として落ちる (同規約 (e))。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ */
+function phpBootProbeHasEnvironmentPathCall(array $tokens): bool
+{
+    $count = count($tokens);
+    for ($i = 0; $i + 3 < $count; $i++) {
+        if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== '$app') {
+            continue;
+        }
+
+        if ($tokens[$i + 1]['id'] !== T_OBJECT_OPERATOR) {
+            continue;
+        }
+
+        if ($tokens[$i + 2]['id'] !== T_STRING || $tokens[$i + 2]['text'] !== 'useEnvironmentPath') {
+            continue;
+        }
+
+        if ($tokens[$i + 3]['id'] === null && $tokens[$i + 3]['text'] === '(') {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * ソースが**環境ファイルの退避**を持つか (実コード、または子へ渡す検体ソースの中)。
+ *
+ * 判定は 2 段で、**どちらもトークンの完全一致**である (素の部分文字列一致は使わない):
+ *
+ *  1. ソース自身の正規化トークン列に `$app->useEnvironmentPath(` が在る
+ *     (`fake-wiring-probe.php` / `idempotency-claim-probe.php` のような実コード)
+ *  2. 文字列トークン (ヒアドキュメント・ナウドキュメントの本文を含む) の中身を
+ *     **PHP として字句解析し直し**、同じ 4 トークンの並びが在る
+ *     (`BootProbeRunnerTest` のように、子へ渡す検体ソースを文字列で持つ形)
+ *
+ * ★段 2 を素の部分文字列一致で書くと `'useEnvironmentPath is required'` のような
+ *   ただの散文や、`'$app->notUseEnvironmentPath(…)'` のような打ち消しつきまで通る。
+ *   **文字列の中も字句解析して同じ規則で判定する** (AGENTS.md §静的検査の共通規約 (e))。
+ * ★コメント・docblock は `PhpTokenScan::normalize()` が落とすので数えない。
+ *
+ * **主張しないこと**: 呼び出しが**実際に効く位置** (アプリ起動より前) に在ることは見ない。
+ * 位置の正しさは各経路の実挙動の検査が担う (G-8 の `env_isolation` 参照)。
+ */
+function phpBootProbeMentionsEnvironmentPathRelocation(string $source): bool
+{
+    $tokens = PhpTokenScan::normalize($source);
+
+    if (phpBootProbeHasEnvironmentPathCall($tokens)) {
+        return true;
+    }
+
+    foreach ($tokens as $token) {
+        if (! in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
+            continue;
+        }
+
+        $body = $token['text'];
+        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && strlen($body) >= 2) {
+            // 引用符を落とす (中身だけを字句解析する)。
+            $body = substr($body, 1, -1);
+        }
+
+        if (phpBootProbeHasEnvironmentPathCall(PhpTokenScan::normalize('<?php '.$body))) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
 /**
  * 走査の母集団: git 追跡下の `tests/` 配下の `*.php` (相対パス => ソース)。
  *
@@ -381,13 +518,30 @@ function phpBootProbeDeclaredPaths(array $inventory): array
     );
 });
 
-test('G-2 軸 A: アプリを起こすと申告するのは共通の起動器ちょうど 1 件である', function (): void {
+/**
+ * G-2: 「アプリを起こす」と申告してよい起こし手の**完全一致 pin**。
+ *
+ * ★**1 件ではなく 2 件である**。本 feature (subprocess-boot-probe-harness) の boundary は
+ *   「子を 2 本立てて合図で同期させる並行テスト」を明示的に**除いて**おり、そちらは別 feature
+ *   (lctl: process-concurrency-test-harness) が自分の回収規約 (単一の絶対 deadline) を持つ。
+ *   両者を 1 本の起動器へ統合するのは「別物の概念を似ているからで統合する」ことになる
+ *   (AGENTS.md 思考原則 4)。
+ * ★したがって本検査が固定するのは**申告先の集合そのもの**であり、
+ *   「起動経路が 1 本である」ことではない (それは字句走査では裏が取れない。冒頭の
+ *   「主張しないこと」1 を参照)。3 本目が現れたら**どちらの feature の規約に属するのか**を
+ *   申告に書くことになり、レビューに必ず見える。
+ */
+test('G-2 軸 A: アプリを起こすと申告する起こし手が完全一致で pin されている', function (): void {
     $launching = array_keys(array_filter(
         phpBootProbeBinaryReferenceInventory(),
         static fn (array $entry): bool => $entry['launches_app'],
     ));
+    sort($launching);
 
-    expect($launching)->toBe(['tests/Support/Process/BootProbeRunner.php']);
+    expect($launching)->toBe([
+        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php',
+        'tests/Support/Process/BootProbeRunner.php',
+    ]);
 });
 
 test('G-3 軸 A: subject / recovery / reason の 3 欄がいずれも空でない', function (): void {
@@ -457,87 +611,167 @@ function phpBootProbeDeclaredPaths(array $inventory): array
 });
 
 /**
- * G-8: リポジトリの `.env` を読んで起動する**子**の目録 (危険面の pin)。
+ * G-8: 子プロセスがリポジトリの `.env` を読んで起動しないこと (申告 0 件の pin + 裏取りの名指し)。
  *
- * ## 何を測っているか
+ * ## 何を守っているか
  *
  * 共通の起動器は `proc_open` へ渡す環境配列で開発者ローカルの env を締め出すが、
  * **`.env` ファイルの読み込みまでは止めない**。子の作業ディレクトリはリポジトリ root なので、
- * 子が `bootstrap/app.php` を素で読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
+ * 子が `bootstrap/app.php` を**素で**読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
+ * これは正典 v1 (2) の「開発者ローカルの環境変数を入力集合から外す」を、
+ * 環境変数ではなく**環境ファイル**の経路で迂回してしまう形である。
  *
- * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査の S9 / S10 が使う検体でこれを確かめたところ、
- * 子の設定には `.env` 由来の値が入っていた — 外部サービスの資格情報
+ * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査 S9 / S10 の検体を取り込み元の姿
+ * (環境ファイルの置き場所を移さない形) で走らせると、子の設定に `.env` 由来の
+ * **DB のパスワードと実 `CIPHERSWEET_KEY`** が載った。外部サービスの資格情報
  * (Stripe / AWS / Google / SMTP) は本チェックアウトではいずれも空だったが、
- * **DB のパスワードと `CIPHERSWEET_KEY` は実値が載った**。
- * **「空だった」のはこのチェックアウトの性質であって、保証ではない。**
+ * **「空だった」のはこのチェックアウトの性質であって保証ではない。**
+ * この実測を受けて S9 / S10 の検体には**起動前に環境ファイルの置き場所を一時ディレクトリへ
+ * 逃がす 1 行**を入れた (取り込み元からの意図的な逸脱。理由は当該 docblock)。
  *
- * ## なぜ止めずに目録にするのか
+ * ## 何を機械で固定しているか
  *
- * 当該検体は**テンプレートからバイト一致で取り込んだ共有ファイル**の中にあり、
- * ここで書き換えると意図的逸脱の登録が要る (T249 の受入条件は「取り込み 3 本を編集しない」)。
- * したがって本 gate は**除去ではなく封じ込め**を担う —
- * この性質を持つ経路が**申告なしに増えない**ことだけを機械で固定する。
+ *  1. `env_isolation` が `none` の子入口は**ちょうど 0 件**である (完全一致 pin)。
+ *     退避も裏取りも無い子入口を足すには申告を書き換えることになり、レビューに必ず見える
+ *  2. `child_entry` は `env_isolation` を `behavioural` / `structural` のどちらかで申告し、
+ *     **根拠の欄 (`env_isolation_proof`) を必ず持つ** (空では通らない)
+ *  3. **`structural` の集合は完全一致で pin する** — 実挙動の裏取りが無い経路が
+ *     黙って増えないようにするため。**この集合について「実際に `.env` を読まない」とは
+ *     主張しない** (下の「主張しないこと」を参照)
+ *  4. `child_entry` 以外 (`in_process` / `inventory`) は定義上この分類の対象でないので、
+ *     `env_isolation` が `null` であること・根拠が空であることを両方向で固定する
+ *     (取り違えの検出)
  *
- * ## 対比 (なぜ他の経路は false なのか)
+ * ## 対比 (なぜ同一プロセスは対象外なのか)
  *
- *  - 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
- *    効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
- *    **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
- *    これが子と同一プロセスの非対称の正体である
- *  - `fake-wiring-probe.php` は専用の 0600 環境ファイルへ `useEnvironmentPath()` /
- *    `loadEnvironmentFrom()` で固定するので、リポジトリの `.env` を読まない
+ * 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
+ * 効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
+ * **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
+ * これが子と同一プロセスの非対称の正体である。
  *
- * ## 主張しないこと (誇張しない。Codex 実装レビュー Round 3 の指摘)
+ * ## 主張しないこと (誇張しない)
  *
- * **本検査が機械的に確かめるのは「申告」であって「実挙動」ではない。**
- * `boots_repository_env` の値と、その経路の子が実際に何を読むかを結び付ける検査は**持っていない**。
- * したがって次の退行は**本検査を通ってしまう**:
+ * **「子はリポジトリの `.env` を読まない」を全経路について主張しない。**
+ * 主張できるのは `env_isolation: behavioural` の経路だけで、そちらの根拠は本検査ではなく
+ * **名指しされた実挙動の検査そのもの**である:
  *
- *  1. `fake-wiring-probe.php` から `useEnvironmentPath()` を落としつつ申告を `false` のままにする
- *  2. 新しい `child_entry` が `.env` を読むのに `false` と申告する
- *  3. 既存の `true` のファイルの中で、`.env` を読む検体を増やす (ファイル単位の件数は変わらない)
+ *  - `tests/Unit/Support/Process/BootProbeRunnerTest.php` の S9 — 子が報告した環境ファイルの
+ *    絶対パスが `<一時ディレクトリ>/.env` と完全一致し、そこに実在しないこと
+ *  - `tests/Architecture/ExternalFakeBootProbeTest.php` の P-17 / P-8 — 子が報告した
+ *    環境ファイルの絶対パスが起動側の専用ファイルと完全一致し、効いた鍵がその中身と一致すること
  *
- * **よって本検査はセキュリティ境界ではなく、上流課題を見える場所に置くための暫定の台帳である。**
- * 「危険面が申告なしに増えない」とは読めない (読めるのは「申告が黙って書き換わらない」までである)。
+ * `env_isolation: structural` の経路 (現在 1 件) については
+ * **「実際に読まない」とは主張しない** — 分かっているのは「退避の呼び出しが字句として在る」
+ * ことだけである (G-9)。呼び出しが**効く位置**に在るかも、他の値を読んでいないかも見ていない。
  *
- * ## 上流への申し送り (本検査では代替できない)
+ * さらに、本検査が機械で確かめるのは**申告と根拠の記載**であって、
+ * 名指しした検査が実際に何を測っているかではない。したがって次は本検査を通る:
  *
- * 正典側 (lctl feature: subprocess-boot-probe-harness) で
- * 「アプリを起こす自己検査の子にも専用の環境ファイルを読ませる」ことを**先に**行うべきである。
- * 併せて「リポジトリの `.env` へ置いた番兵が子の設定に現れないこと」を測る自己検査があれば、
- * 実挙動の側で固定できる。解消されて再取り込みしたら、本 pin の `true` は 0 件になる。
+ *  1. `env_isolation_proof` に**実在はするが何も測っていない**検査名を書く
+ *     (実在しない名前は G-9 が落とす)
+ *  2. 既存の `child_entry` の中で、`.env` を読む検体を**増やす** (ファイル単位の申告は変わらない)
  */
-test('G-8 リポジトリの .env を読むと申告した経路は 1 件だけである (申告の pin。実挙動は測らない)', function (): void {
+test('G-8 退避も裏取りも無い子入口は 0 件で、実挙動の裏取りが無い経路は完全一致で pin されている', function (): void {
     $inventory = phpBootProbeAppBootEntryReferenceInventory();
 
-    $bootsRepositoryEnv = array_keys(array_filter(
-        $inventory,
-        static fn (array $entry): bool => $entry['boots_repository_env'],
-    ));
+    $childEntries = [];
+    $structuralOnly = [];
 
-    // ★件数と場所を完全一致で pin する。増やすには「なぜその子が .env を読んでよいのか」を
-    //   申告に書くことになり、レビューに必ず見える。
-    expect($bootsRepositoryEnv)->toBe(
-        ['tests/Unit/Support/Process/BootProbeRunnerTest.php'],
-        'リポジトリの .env を読んで起動する子が増減している。'
-        .'増やすなら G-8 の docblock を読み、なぜ専用の環境ファイルを使えないのかを申告すること',
-    );
+    foreach ($inventory as $path => $entry) {
+        if ($entry['kind'] !== 'child_entry') {
+            // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
+            //   定義上この分類の対象ではない。取り違えを防ぐために両方向で固定する。
+            expect($entry['env_isolation'])
+                ->toBeNull("子が居ない経路に env_isolation が申告されている: {$path}")
+                ->and(trim($entry['env_isolation_proof']))
+                ->toBe('', "子が居ない経路に根拠の記載がある (kind の取り違え): {$path}");
 
-    // ★`true` を申告してよいのは**バイト一致で取り込んだ共有ファイル**だけである
-    //   (aicue が自分で書いたファイルには、専用の環境ファイルを使わない言い訳が無い)。
-    foreach ($bootsRepositoryEnv as $path) {
-        expect(str_starts_with($path, 'tests/Unit/Support/Process/'))
-            ->toBeTrue("aicue 所有のファイルがリポジトリの .env を読む子を持っている: {$path}");
+            continue;
+        }
+
+        $childEntries[] = $path;
+
+        // ★分類は 2 値のどちらかで、根拠の記載を必ず持つ (申告だけで済ませない)。
+        expect(in_array($entry['env_isolation'], ['behavioural', 'structural'], true))
+            ->toBeTrue("child_entry の env_isolation が behavioural / structural の外: {$path}")
+            ->and(trim($entry['env_isolation_proof']))
+            ->not->toBe('', "child_entry に env_isolation の根拠が無い: {$path}");
+
+        if ($entry['env_isolation'] === 'structural') {
+            $structuralOnly[] = $path;
+        }
     }
 
-    // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
-    //   定義上この危険面を持たない。取り違えを防ぐために両方向で固定する。
-    foreach ($inventory as $path => $entry) {
+    sort($structuralOnly);
+
+    // ★**実挙動の裏取りが無い子入口**の集合を完全一致で pin する。
+    //   増やすには申告を書き換えることになり、「なぜ実挙動で測らないのか」がレビューに必ず見える。
+    //   減らす (behavioural へ上げる) ときも同じ。
+    expect($structuralOnly)->toBe(
+        ['tests/Support/Concurrency/idempotency-claim-probe.php'],
+        '実挙動の裏取りを持たない子入口が増減している。'
+        .'足すなら G-8 の docblock を読み、なぜ実挙動で測れないのかを根拠の欄に書くこと',
+    );
+
+    // ★母集団が空のまま緑になる形を塞ぐ (AGENTS.md §静的検査の共通規約 (b) の 3 点目)。
+    expect($childEntries)->not->toBe([], 'child_entry が 1 件も無い (走査か申告が壊れている)');
+});
+
+/**
+ * G-9: `child_entry` は**環境ファイルの退避の呼び出しを字句として持つ** (G-8 の申告への機械の裏打ち)。
+ *
+ * G-8 が見るのは申告と根拠の記載までである。そこへ**2 つだけ機械の裏打ち**を足す:
+ *
+ *  1. `child_entry` の申告ファイルは `$app->useEnvironmentPath(` を**トークンの完全一致**で持つ
+ *     (実コード、または子へ渡す検体ソースの文字列の中。判定は
+ *     `phpBootProbeMentionsEnvironmentPathRelocation()`)。Laravel が読む環境ファイルは
+ *     この呼び出しでしか動かないので、**持たない子入口は既定でリポジトリの `.env` を読む**
+ *     = 新しい子入口を素直に足すと赤になる
+ *  2. `env_isolation_proof` が**検査を名指ししている場合**、その先頭語は
+ *     **実在するパス**である (走査母集団の中に在る)。実在しない検査名で申告を通す形を塞ぐ。
+ *     `structural` の根拠は検査名ではなく散文なので、この検査は
+ *     **`behavioural` の entry にだけ**適用する
+ *
+ * **主張しないこと**:
+ *
+ *  - 呼び出しが**実際に効く位置** (アプリ起動より前) に在ること。字句では決められないので、
+ *    位置の正しさは実挙動の検査 (`BootProbeRunnerTest` の S9 /
+ *    `ExternalFakeBootProbeTest` の P-17) が担う
+ *  - **受け手が本当に Laravel の Application であること**。変数の型は字句では解決できないので、
+ *    受け手は**綴り (`$app`) で固定している**。別名で受ける子入口は赤になる (拾いすぎない側)
+ *  - 名指しした検査が**実際に何を測っているか** (実在の確認までである)
+ */
+test('G-9 child_entry は退避の呼び出しを字句として持ち、behavioural の名指しは実在パスである', function (): void {
+    $sources = phpBootProbeTestSources();
+    $childEntries = 0;
+
+    foreach (phpBootProbeAppBootEntryReferenceInventory() as $path => $entry) {
         if ($entry['kind'] !== 'child_entry') {
-            expect($entry['boots_repository_env'])
-                ->toBeFalse("子プロセスではない経路に .env 読み込みが申告されている: {$path}");
+            continue;
+        }
+
+        $childEntries++;
+
+        expect($sources)->toHaveKey($path);
+        expect(phpBootProbeMentionsEnvironmentPathRelocation($sources[$path]))
+            ->toBeTrue(
+                "child_entry が環境ファイルの退避 (\$app->useEnvironmentPath( ) を持っていない: {$path}"
+            );
+
+        if ($entry['env_isolation'] !== 'behavioural') {
+            // `structural` の根拠は検査名ではなく散文なので、実在確認の対象にしない。
+            continue;
         }
+
+        // 名指しは「パス + 括弧つきの説明」の形なので、先頭語をパスとして見る。
+        $named = strtok(trim($entry['env_isolation_proof']), " \t");
+        expect(is_string($named) ? $named : '')->not->toBe('', "env_isolation_proof が空: {$path}");
+        expect(array_key_exists((string) $named, $sources))
+            ->toBeTrue("env_isolation_proof が実在しない検査を名指ししている: {$path} => {$named}");
     }
+
+    // 母集団が空のまま緑になる形を塞ぐ。
+    expect($childEntries)->toBeGreaterThan(0, 'child_entry が 1 件も無い (走査か申告が壊れている)');
 });
 
 test('G-7 走査が空振りしていない (走査根が実在し、3 軸の母集団が非空)', function (): void {
@@ -605,6 +839,41 @@ function phpBootProbeDeclaredPaths(array $inventory): array
     ["<?php \$p = 'fake-wiring-probe.txt';", false, false, false],
 ]);
 
+test('G-7 走査器の見本検査: 環境ファイルの退避の字句判定 (名前・文字列の両方 / 3 形の否定)', function (
+    string $sample,
+    bool $expected,
+): void {
+    expect(phpBootProbeMentionsEnvironmentPathRelocation($sample))->toBe($expected, $sample);
+})->with([
+    // --- 正例: 実コード / 単一引用符の中 / ナウドキュメントの本文 (3 分岐すべて) ---
+    ['<?php $app->useEnvironmentPath($dir);', true],
+    ["<?php \$code = '\$app->useEnvironmentPath(\$dir);';", true],
+    ["<?php \$code = <<<'PHP'
+\$app->useEnvironmentPath(\$dir);
+PHP;", true],
+    // --- 負例: コメントだけ (正規化が落とす) ---
+    ['<?php // useEnvironmentPath', false],
+    ['<?php /** useEnvironmentPath */ $x = 1;', false],
+    // --- 負例: 接頭辞つき・打ち消しつき・接尾辞つきの**名前** (実コード側) ---
+    ['<?php $app->myUseEnvironmentPath($dir);', false],
+    ['<?php $app->notUseEnvironmentPath($dir);', false],
+    ['<?php $app->useEnvironmentPathX($dir);', false],
+    // --- 負例: 同じ 3 形を**文字列の中**でも落とす (段 2 が部分文字列一致でないことの裏取り) ---
+    ["<?php \$code = '\$app->myUseEnvironmentPath(\$dir);';", false],
+    ["<?php \$code = '\$app->notUseEnvironmentPath(\$dir);';", false],
+    ["<?php \$code = '\$app->useEnvironmentPathX(\$dir);';", false],
+    // --- 負例: 文字列の中の散文・呼び出しでない形 ---
+    ["<?php \$msg = 'useEnvironmentPath is required';", false],
+    ["<?php \$s = 'useEnvironmentPath';", false],
+    // --- 負例: 受け手が \$app でない (存在を肯定する検査なので拾いすぎない) ---
+    ['<?php $unrelated->useEnvironmentPath($dir);', false],
+    ["<?php \$code = '\$unrelated->useEnvironmentPath(\$dir);';", false],
+    // --- 負例: 呼び出しでない形 (`(` が続かない) ---
+    ['<?php $app->useEnvironmentPath;', false],
+    // --- 負例: 退避を持たない子入口 (これが G-9 で赤になる形) ---
+    ["<?php \$app = require 'bootstrap/app.php'; \$app->make(Kernel::class)->bootstrap();", false],
+]);
+
 test('G-7 走査器の見本検査: 共通の起動器への静的呼び出しを完全修飾名で判定する', function (
     string $sample,
     bool $expected,
diff --git a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
index 5e13009e..2e3c955f 100644
--- a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
+++ b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
@@ -56,9 +56,35 @@
  * |---|---|
  * | 「外部到達統制の subprocess 0 件 pin に触れる (AGENTS.md セキュリティ不変条件 **15**)」 | aicue の外部到達点の目録は **セキュリティ不変条件 9** である |
  * | 「同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`」 | aicue では `tests/Support/GlobalUse/PhpLintOracle.php` (`Architecture/` が入らない) |
+ * | 「統制点は `proc_open` へ渡す環境配列だけ」 | **プロセス環境の統制点はそれで唯一だが、環境ファイル (`.env`) は別経路である** |
  *
  * **趣旨 (`tests/` 専用であり `app/` へ持ち出さない) は aicue でもそのまま成り立つ。**
  *
+ * ### 呼び出し側の必須契約 (T249 の実測から。起動器の docblock には書かれていない)
+ *
+ * **Laravel を起こす子は、環境ファイルの置き場所を自分で退避しなければならない。**
+ * 起動器が締め出すのは*プロセス環境*だけで、`.env` の読み込みは止めない。子の作業ディレクトリは
+ * リポジトリ root なので、`bootstrap/app.php` を素で読むと Laravel は**リポジトリの `.env` を
+ * そのまま設定へ載せる** (実測: DB パスワードと実 `CIPHERSWEET_KEY` が子の設定に載った)。
+ * 退避の手段は 2 通りで、どちらでもよい:
+ *
+ *  - **専用の環境ファイルを読ませる** — 本クラスの経路 (`useEnvironmentPath()` +
+ *    `loadEnvironmentFrom()` を子入口が呼ぶ)
+ *  - **実在しない場所を指させる** — 起動器の自己検査 (S9 / S10) の経路
+ *    (一時ディレクトリを環境パスにすると `safeLoad()` は何も読まない)
+ *
+ * この契約の守り方は経路ごとに強さが違う。**一部の経路は字句の pin だけである**:
+ *
+ *  - 本クラスの経路 / 起動器の自己検査 (S9) — **実挙動で測る**
+ *    (`ExternalFakeBootProbeTest` の P-17 が読んだ環境ファイルの絶対パスを完全一致で、
+ *     S9 が同じことを起動器側で)
+ *  - 実プロセス並行テストの子入口 — **字句の pin だけ** (退避の呼び出しが在ることまで)。
+ *    別 feature の観測契約なので実測は足していない
+ *
+ * どの経路がどちらかの正本は
+ * `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` の軸 B の申告
+ * (`env_isolation`) であり、G-8 が分類を、G-9 が字句の裏打ちを固定する。
+ *
  * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
  * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
  * 本番混入防止は ProductionEnvGuard の二重判定が受け持つ)。
diff --git a/tests/Support/ExternalFakes/fake-wiring-probe.php b/tests/Support/ExternalFakes/fake-wiring-probe.php
index f0009799..25530f21 100644
--- a/tests/Support/ExternalFakes/fake-wiring-probe.php
+++ b/tests/Support/ExternalFakes/fake-wiring-probe.php
@@ -12,13 +12,14 @@
 /*
  * 別プロセスで「宣言した差し替えが実際に効いているか」を観測して JSON を書き出す。
  *
- * ★責務は 6 つだけ:
+ * ★責務は 7 つだけ:
  *   1. DB へ接続しない
  *   2. container から解決する
  *   3. 転送先 URL を組み立てて読む (**偽物が有効なときだけ**)
  *   4. **実働証明の印を storage_path() 経由で 1 本書く** (正典 v1 (5))
  *   5. **起動しきったアプリが解決した書き出し先 8 種と、効いた鍵 2 種の digest を報告する**
- *   6. 終了コードを返す
+ *   6. **実際に読んだ環境ファイルの絶対パスを報告する** (P-17。専用ファイルへの固定が効いた証拠)
+ *   7. 終了コードを返す
  * ★**観測しないもの**: HTTP サーバもブラウザも起動しない /
  *   設定キャッシュ**有り**の起動は観測しない / 外部へ 1 度も通信しない
  *   (転送先は組み立てて URL を読むだけ)。
@@ -92,6 +93,10 @@
         'resolved' => $resolved,
         'redirect_host' => $redirectHost,
         'process_environment_keys' => $processEnvironmentKeys,
+        // ★P-17 (環境ファイルの隔離): 起動しきったアプリが**実際に読んだ**環境ファイルの
+        //   絶対パス。呼び出し側が「起動側が用意した専用ファイルと完全一致する」ことを確かめる
+        //   (= リポジトリの .env を読んでいない、を実挙動で示す唯一の観測点)。
+        'env_file_path' => $app->environmentFilePath(),
         // ★P-14 (向き): 起動しきったアプリが解決した書き出し先。呼び出し側が
         //   「1 件残らず一時ディレクトリ配下で、リポジトリの外」であることを確かめる。
         'write_targets' => [
diff --git a/tests/Unit/Support/Process/BootProbeRunnerTest.php b/tests/Unit/Support/Process/BootProbeRunnerTest.php
index eefdd14a..fcb76a79 100644
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
 
 test('S9: 書き出し先の退避が効いている (向き) / 親と同じ実行体で起きる', function (): void {
-    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['LOG_CHANNEL' => 'single']);
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'single']);
     expect($result->exitCode)->toBe(0, $result->stderr);
 
-    /** @var array<string, string> $report */
+    // ★報告には真偽値も混ざるので `mixed` で受ける (取り込み元は string 固定だった)。
+    /** @var array<string, mixed> $report */
     $report = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);
 
     // 正典 (1): 親と同じ実行体で起こす。
     expect($report['php_binary'])->toBe(PHP_BINARY);
 
+    // ★aicue のローカル修正 (T249) の実働証明 — **申告ではなく実挙動を測る**。無条件の 2 方向で、
+    //   どちらもリポジトリの `.env` の**中身に依存しない** (見本から起こしたチェックアウトでも
+    //   同じ強さで成立し、テスト出力に秘密も digest も出ない)。
+    //
+    //   (a) **場所**: Laravel が読む環境ファイルは `environmentFilePath()` の**ちょうど 1 本**で、
+    //       それが `<一時ディレクトリ>/.env` **と完全一致**し、しかも**実在しない**。
+    //       ★配下判定 (`isInside()`) では測らない — あちらは両引数が正規化済みであることを
+    //         契約にしており、`..` を含む未正規化のパスを渡すと取り違える。ここは
+    //         起動器が予約鍵で渡した一時ディレクトリから期待値が一意に決まるので、
+    //         **完全一致**で測るのが最も強く、正規化の前提も要らない。
+    expect($report['env_file_path'])->toBe(
+        $result->temporaryRoot.'/.env',
+        '子が読む環境ファイルが起動器の一時ディレクトリの直下でない',
+    )->and($report['env_file_exists'])
+        ->toBeFalse("子が環境ファイルを読み込んでいる: {$report['env_file_path']}");
+
+    //   (b) **中身**: 環境ファイルからしか来ない設定値が**空である**。
+    //       `config/ciphersweet.php` の鍵は既定を持たず、`config/database.php` のパスワードは
+    //       空文字を既定にするので、**非空なら環境ファイルが読まれた**ことになる。
+    //       (a) が「読む先」を、(b) が「読んだ結果」を測るので、
+    //       「置き場所は移したが値は別経路で入った」形もここで落ちる。
+    expect($report['ciphersweet_key_present'])
+        ->toBeFalse('子の設定に CIPHERSWEET_KEY が載っている (環境ファイルが読まれた)')
+        ->and($report['db_password_present'])
+        ->toBeFalse('子の設定に DB_PASSWORD が載っている (環境ファイルが読まれた)');
+
     foreach (['storage', 'config_cache', 'routes_cache', 'services_cache', 'packages_cache',
         'events_cache', 'view_compiled', 'log_path'] as $key) {
         expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
@@ -214,7 +292,7 @@ static function (string $key): bool {
 });
 
 test('S10: 書き出し先の退避が効いている (実体) と後片付け', function (): void {
-    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['LOG_CHANNEL' => 'single']);
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'single']);
 
     expect($result->exitCode)->toBe(0, $result->stderr)
         ->and($result->writtenRelativePaths)->toContain('storage/logs/laravel.log')
@@ -223,19 +301,43 @@ static function (string $key): bool {
 
 test('S11: 一時ディレクトリがリポジトリ内なら起動前に失敗し残骸を残さない', function (): void {
     $base = base_path('storage/framework/testing');
-    if (! is_dir($base)) {
-        mkdir($base, 0o755, true);
+    // ★aicue のローカル修正 (T249): **このテストが作った階層を 1 つ残らず**戻す
+    //   (受入条件の「走行が生成物を残さない」。取り込み元は `storage/framework` を新規作成した
+    //   環境でそれを残していた)。`--parallel` の他 worker が同じ場所を使うので、
+    //   **空でなければ触らない**。
+    $createdAncestors = [];   // 深い順
+    for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
+        $createdAncestors[] = $candidate;
+    }
+    foreach (array_reverse($createdAncestors) as $directory) {
+        expect(mkdir($directory, 0o755))->toBeTrue("後始末の対象を作れない: {$directory}");
     }
 
-    $before = glob($base.'/boot-probe-*');
-    expect($before)->toBeArray();
-    assert(is_array($before));
+    try {
+        $before = glob($base.'/boot-probe-*');
+        expect($before)->toBeArray();
+        assert(is_array($before));
 
-    expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], temporaryBase: $base))
-        ->toThrow(RuntimeException::class);
+        expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], temporaryBase: $base))
+            ->toThrow(RuntimeException::class);
 
-    $after = glob($base.'/boot-probe-*');
-    expect($after)->toBe($before, '起動前の fail-closed が残骸を残している');
+        $after = glob($base.'/boot-probe-*');
+        expect($after)->toBe($before, '起動前の fail-closed が残骸を残している');
+    } finally {
+        // 深い順に戻す (作った分だけ)。空でなければ他 worker が使っているので触らない。
+        foreach ($createdAncestors as $directory) {
+            if (! is_dir($directory)) {
+                continue;
+            }
+
+            $remaining = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
+            if ($remaining !== []) {
+                break;
+            }
+
+            rmdir($directory);
+        }
+    }
 
     // 境界判定そのものを pin する (`/repo` と `/repository` を取り違えない)。
     expect(BootProbeRunner::isInside('/repo', '/repo'))->toBeTrue()

```

---

## T249 の実装差分 全体 (main との差分。tests/ 配下のみ)

```diff
diff --git a/tests/Architecture/ExternalFakeBootProbeTest.php b/tests/Architecture/ExternalFakeBootProbeTest.php
index e555fffe..65107739 100644
--- a/tests/Architecture/ExternalFakeBootProbeTest.php
+++ b/tests/Architecture/ExternalFakeBootProbeTest.php
@@ -4,8 +4,9 @@
 
 use App\Support\ExternalFakes\ExternalFakeBinding;
 use App\Support\ExternalFakes\ExternalFakeDeclaration;
-use Symfony\Component\Process\Exception\ProcessTimedOutException;
 use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
+use Tests\Support\Process\BootProbeResult;
+use Tests\Support\Process\BootProbeRunner;
 
 /*
  * 別プロセスで「宣言した差し替えが実際に効いているか」を実測する
@@ -15,9 +16,23 @@
  * 「実際の起動 (遅延読み込み provider・設定の解決順) でも効いているか」までは示せない。
  * ここでは子プロセスを起こし、起動しきったアプリの container から解決して観測する。
  *
- * ★子プロセスへ実際の外部資格情報を渡さない。プロセスの環境変数は `env -i` で空にし、
- *   設定は専用の一時環境ファイル 1 つだけから読む。書いてよいキーに外部サービスの
- *   資格情報は 1 つも無く、鍵の 2 つは使い捨ての生成値である (P-6 / P-7 / P-8)。
+ * ★子の起こし方・回収・書き出し先の退避は共通の起動器
+ *   (`Tests\Support\Process\BootProbeRunner`) が持つ
+ *   (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
+ *
+ * ★**子プロセスへ実際の外部資格情報を渡さない**。子の環境は**4 段**で組み立てる —
+ *   継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE`) →
+ *   ケース別 (`FakeWiringProbeRunner::CASE_ENV_KEYS` の 3 件) → 予約 (書き出し先 7 キー)。
+ *   統制点は `proc_open` へ渡す環境配列であり、開発者ローカルの env はそこで締め出される (P-7)。
+ *
+ * ★**使い捨て鍵の置き場所は 2 つに分かれる**。`APP_KEY` は**ケース別上書き**、
+ *   `CIPHERSWEET_KEY` は**環境ファイル**である (Laravel の環境変数リポジトリは immutable で、
+ *   プロセス環境に既に在る値を Dotenv は上書きしないため)。どちらも親の実鍵の複写ではないこと、
+ *   かつ**子で実際に効いた**ことを P-8 が digest で測る。
+ *
+ * ★**正典 v1 (5) の実働証明**は P-13 (実体) と P-14 (向き) が持つ。「書き出し先を退避した」は、
+ *   退避が効いていなければ既定の場所へ書かれて観測が緑のまま嘘になるので、
+ *   子が `storage_path()` 経由で置いた印が起動器の一時ディレクトリ配下に現れることまで測る。
  *
  * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
  * キャッシュが古いときの本番事故は ProductionEnvGuard の二重判定が受け持つ。
@@ -57,11 +72,12 @@ function externalFakeProbeBaseDirectories(?string $add = null): array
  *     exitCode: int,
  *     output: array<string, mixed>,
  *     envFileValues: array<string, string>,
+ *     caseEnvValues: array<string, string>,
  *     directory: string,
  *     directoryMode: int,
  *     envFileMode: int,
- *     configCachePath: string,
- *     configCacheExists: bool,
+ *     temporaryRoot: string,
+ *     writtenRelativePaths: list<string>,
  *     baseDirectory: string,
  * }
  */
@@ -90,12 +106,51 @@ function externalFakeProbeRun(string $case): array
         $cache[$case] = [...$result, 'baseDirectory' => $base];
     }
 
-    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, configCachePath: string, configCacheExists: bool, baseDirectory: string} $entry */
+    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, caseEnvValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, temporaryRoot: string, writtenRelativePaths: list<string>, baseDirectory: string} $entry */
     $entry = $cache[$case];
 
     return $entry;
 }
 
+/**
+ * 書き出し先が**正規化済みの絶対パス**であることを確かめる (`.` / `..` を 1 つも含まない)。
+ *
+ * ★`BootProbeRunner::isInside()` の契約は「両引数とも realpath 済み」である。ところが
+ *   書き出し先の多く (設定キャッシュ等) は**まだ存在しないファイル**なので realpath できず、
+ *   子が返す文字列をそのまま渡すことになる。ここを素通しにすると
+ *   `<一時 root>/../../<リポジトリ>/…` のような形が
+ *   「一時 root の配下かつリポジトリの外」と判定され、**実際にはリポジトリ内へ解決される**のに
+ *   P-11 / P-14 が緑のまま通る (fail-open)。
+ *   予約パスの組み立てに `..` が混じる退行を見逃さないため、配下判定の**前に**弾く。
+ */
+function externalFakeProbeIsNormalizedAbsolutePath(string $path): bool
+{
+    if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
+        return false;
+    }
+
+    foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
+        if ($segment === '.' || $segment === '..') {
+            return false;
+        }
+    }
+
+    return true;
+}
+
+/**
+ * 上の述語で書き出し先を検査する (診断文つき)。
+ *
+ * ★述語そのものの検出力は P-16 が**恒久の負例**で裏取りする
+ *   (実データが常に正常なので、この helper を空実装にしても P-11 / P-14 は緑のままになる。
+ *   AGENTS.md §静的検査の共通規約 (c) の「検出力は負例で裏取りする」に当たる)。
+ */
+function externalFakeProbeAssertNormalizedPath(string $path, string $label): void
+{
+    expect(externalFakeProbeIsNormalizedAbsolutePath($path))
+        ->toBeTrue("書き出し先 {$label} が正規化された絶対パスでない: {$path}");
+}
+
 /**
  * 観測結果の `resolved` を「解決キー => 実際に解決されたクラス」として取り出す。
  *
@@ -182,13 +237,33 @@ function externalFakeProbeResolved(array $output): array
         ->and(array_values(array_diff($keys, FakeWiringProbeRunner::ALLOWED_ENV_FILE_KEYS)))->toBe([]);
 });
 
-test('P-7 子が実際に受け取ったプロセス環境が許可した 3 件ちょうどである', function (): void {
-    $keys = externalFakeProbeRun('fake')['output']['process_environment_keys'] ?? null;
+test('P-7 子が実際に受け取ったプロセス環境が 4 段の合成結果と完全一致する', function (): void {
+    // (0) 4 段の定数そのものをリテラルで pin する。実装側の定数から期待値を組み立てるだけだと、
+    //     実装と期待値を同時に変えたときに緑のまま通ってしまう。
+    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR'])
+        ->and(BootProbeRunner::RESERVED_ENV_KEYS)->toBe([
+            'LARAVEL_STORAGE_PATH',
+            'VIEW_COMPILED_PATH',
+            'APP_CONFIG_CACHE',
+            'APP_ROUTES_CACHE',
+            'APP_SERVICES_CACHE',
+            'APP_PACKAGES_CACHE',
+            'APP_EVENTS_CACHE',
+        ])
+        ->and(FakeWiringProbeRunner::CASE_ENV_KEYS)->toBe([
+            'FAKE_WIRING_PROBE_ENV_DIR',
+            'FAKE_WIRING_PROBE_ENV_FILE',
+            'APP_KEY',
+        ]);
+
+    $run = externalFakeProbeRun('fake');
+    $keys = $run['output']['process_environment_keys'] ?? null;
     expect($keys)->toBeArray();
     /** @var list<mixed> $keys */
     $actual = array_map(static fn (mixed $key): string => (string) $key, $keys);
 
-    // (b) 危険な接頭辞が 1 件も無いこと
+    // (a) 危険な接頭辞が 1 件も無いこと (env -i の時代からの主張をそのまま維持する)。
+    //     TESTING_FAKE_* は**プロセス環境へ載せない** (0600 の環境ファイルの中だけに置く)。
     foreach (['DB_', 'PG', 'AWS_', 'STRIPE_', 'TESTING_FAKE_', 'GOOGLE_'] as $prefix) {
         $leaked = array_values(array_filter(
             $actual,
@@ -197,19 +272,43 @@ function externalFakeProbeResolved(array $output): array
         expect($leaked)->toBe([], "禁止する接頭辞 {$prefix} のキーが子へ流れている");
     }
 
-    // (a)(c) 許可した 3 件がすべて存在し、それ以外の余りが無いこと (deny-by-default)
-    $expected = FakeWiringProbeRunner::ALLOWED_PROCESS_ENV_KEYS;
+    // (b) 集合の完全一致 (deny-by-default)。「以下」ではないので 1 本足しただけで赤くなる。
+    $inherited = array_values(array_filter(
+        ['PATH', 'HOME', 'TMPDIR'],
+        static function (string $key): bool {
+            $value = getenv($key);
+
+            return is_string($value) && $value !== '';
+        },
+    ));
+    $expected = array_values(array_unique(array_merge(
+        $inherited,
+        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
+        ['FAKE_WIRING_PROBE_ENV_DIR', 'FAKE_WIRING_PROBE_ENV_FILE', 'APP_KEY'],
+        ['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'APP_CONFIG_CACHE',
+            'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE'],
+    )));
     sort($actual);
     sort($expected);
 
     expect($actual)->toBe($expected);
 });
 
-test('P-8 一時環境ファイルの鍵は親の設定値の複写ではない', function (): void {
-    $values = externalFakeProbeRun('fake')['envFileValues'];
+test('P-8 使い捨て鍵が子で実際に効き、親の設定値の複写ではない', function (): void {
+    $run = externalFakeProbeRun('fake');
 
-    expect($values['APP_KEY'] ?? null)->not->toBe(config('app.key'))
-        ->and($values['CIPHERSWEET_KEY'] ?? null)->not->toBe(config('ciphersweet.providers.string.key'));
+    $digests = $run['output']['key_digests'] ?? null;
+    expect($digests)->toBeArray();
+    /** @var array<string, mixed> $digests */
+
+    // (a) 子で効いた APP_KEY が、起動側が生成した使い捨て値と一致する
+    expect($digests['app'] ?? null)->toBe(hash('sha256', $run['caseEnvValues']['APP_KEY']));
+    // (b) 子で効いた CIPHERSWEET_KEY が、環境ファイルへ書いた使い捨て値と一致する
+    expect($digests['ciphersweet'] ?? null)->toBe(hash('sha256', $run['envFileValues']['CIPHERSWEET_KEY']));
+    // (c) いずれも親の設定値の複写ではない
+    expect($digests['app'])->not->toBe(hash('sha256', (string) config('app.key')))
+        ->and($digests['ciphersweet'])
+        ->not->toBe(hash('sha256', (string) config('ciphersweet.providers.string.key')));
 });
 
 test('P-9 一時ディレクトリ 0700 / 環境ファイル 0600 であり、違えば子を起こさない', function (): void {
@@ -225,7 +324,7 @@ function externalFakeProbeResolved(array $output): array
         ->toThrow(RuntimeException::class);
 });
 
-test('P-10 正常終了・非ゼロ終了・timeout のいずれでも一時ディレクトリが残らない', function (): void {
+test('P-10 正常終了・非ゼロ終了のいずれでも環境ファイルの置き場所が残らない', function (): void {
     foreach (['fake', 'real', 'production'] as $case) {
         $run = externalFakeProbeRun($case);
 
@@ -233,27 +332,134 @@ function externalFakeProbeResolved(array $output): array
             ->and(array_values(array_diff(scandir($run['baseDirectory']) ?: [], ['.', '..'])))
             ->toBe([], "一時ディレクトリの親に残骸がある: {$case}");
     }
+});
 
-    // timeout でも finally を必ず通ること。
-    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
-    expect(mkdir($base, 0700))->toBeTrue();
+test('P-10b 作れない置き場所では子を起こさずに失敗し、残骸を残さない', function (): void {
+    $base = sys_get_temp_dir().'/fake-wiring-probe-readonly-'.bin2hex(random_bytes(6));
+    expect(mkdir($base, 0500))->toBeTrue();
 
     try {
-        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base, 0.01))
-            ->toThrow(ProcessTimedOutException::class);
+        // ★失敗の**段**まで固定する。message を見ないと「子を起こしたあとで別の理由で
+        //   落ちた」場合も緑になり、「子を起こさずに」の部分が主張だけになる。
+        //   この message は置き場所の検査 (= 子を起こす前) だけが投げる。
+        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base))
+            ->toThrow(RuntimeException::class, '観測用の置き場所を使用できない');
 
         expect(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
     } finally {
         rmdir($base);
     }
+})->skip(
+    // root で走ると 0500 でも書けてしまい、負のコントロールが成立しない。
+    // **成功扱いにはしない** — 測れていないことをテスト結果に出す。
+    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
+    'root では書き込み権限の負のコントロールを作れない',
+);
+
+test('P-10c 本体が例外を投げても置き場所が中身ごと消える (制限時間超過の後始末)', function (): void {
+    // 制限時間超過は interpret() が例外にする (P-15)。その例外が外側の finally を通ることを
+    // ここで決定的に測る (実 timeout を作るには子を 1 秒以上眠らせる必要があり、
+    // それは観測用スクリプトの責務を汚すので採らない)。
+    // ★空のディレクトリではなく**中身のある**状態で測る — 実際の制限時間超過では
+    //   .env.probe が既に書かれているので、再帰削除まで示さないと主張と距離がある。
+    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
+    expect(mkdir($base, 0700))->toBeTrue();
+
+    $created = null;
+
+    try {
+        expect(function () use ($base, &$created): mixed {
+            return FakeWiringProbeRunner::withEnvironmentDirectory(
+                $base,
+                static function (string $directory) use (&$created): mixed {
+                    $created = $directory;
+
+                    // 実際の走行と同じく環境ファイルを置き、さらに下位ディレクトリの中にも番兵を置く。
+                    expect(file_put_contents($directory.'/.env.probe', "APP_ENV=x\n"))->not->toBeFalse();
+                    expect(mkdir($directory.'/nested', 0700))->toBeTrue();
+                    expect(file_put_contents($directory.'/nested/sentinel.txt', 'x'))->not->toBeFalse();
+
+                    throw new RuntimeException('本体の失敗');
+                },
+            );
+        })->toThrow(RuntimeException::class);
+
+        // 置き場所は作られ (= 検査が空振りしていない)、中身ごと消えている。
+        expect($created)->toBeString()
+            ->and(is_dir((string) $created))->toBeFalse('置き場所が残っている')
+            ->and(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
+    } finally {
+        rmdir($base);
+    }
+});
+
+test('P-10d リポジトリ内の置き場所は本体を呼ばずに拒否し、残骸を残さない', function (): void {
+    // 正典 v1 (5) の fail-closed を**外側**でも測る (内側は取り込んだ自己検査 S11 が持つ)。
+    $base = base_path('storage/framework/testing');
+
+    // ★このテストが作った階層を**1 つ残らず**戻す (走行が生成物を残さないため)。
+    //   `mkdir(recursive)` + `rmdir($base)` だけだと、親を新規作成した環境
+    //   (新しい checkout など) で `storage/framework` が残る。
+    $createdAncestors = [];   // 深い順
+    for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
+        $createdAncestors[] = $candidate;
+    }
+    foreach (array_reverse($createdAncestors) as $directory) {
+        expect(mkdir($directory, 0755))->toBeTrue("後始末の対象を作れない: {$directory}");
+    }
+
+    try {
+        $before = glob($base.'/fake-wiring-probe-*');
+        expect($before)->toBeArray();
+
+        $bodyCalled = false;
+
+        expect(function () use ($base, &$bodyCalled): mixed {
+            return FakeWiringProbeRunner::withEnvironmentDirectory(
+                $base,
+                static function (string $directory) use (&$bodyCalled): mixed {
+                    $bodyCalled = true;
+
+                    return $directory;
+                },
+            );
+        })->toThrow(RuntimeException::class);
+
+        expect($bodyCalled)->toBeFalse('リポジトリ内なのに本体が呼ばれた')
+            ->and(glob($base.'/fake-wiring-probe-*'))->toBe($before, '拒否経路が残骸を残している');
+    } finally {
+        // 深い順に戻す (作った分だけ)。★`--parallel` の他 worker が同じ場所を使うので、
+        //   **空でなければ触らない** (無条件の rmdir は他 worker の生成物と競合する)。
+        foreach ($createdAncestors as $directory) {
+            if (! is_dir($directory)) {
+                continue;
+            }
+
+            $remaining = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
+            if ($remaining !== []) {
+                break;
+            }
+
+            rmdir($directory);
+        }
+    }
 });
 
-test('P-11 設定キャッシュの指し先は一時ディレクトリ配下の絶対パスで、存在しない', function (): void {
+test('P-11 設定キャッシュの退避先が一時ディレクトリ配下で、書かれていない', function (): void {
     $run = externalFakeProbeRun('fake');
 
-    expect(str_starts_with($run['configCachePath'], '/'))->toBeTrue()
-        ->and(str_starts_with($run['configCachePath'], $run['directory'].'/'))->toBeTrue()
-        ->and($run['configCacheExists'])->toBeFalse();
+    $targets = $run['output']['write_targets'] ?? null;
+    expect($targets)->toBeArray();
+    /** @var array<string, mixed> $targets */
+    $configCache = $targets['config_cache'] ?? null;
+    expect($configCache)->toBeString();
+    /** @var string $configCache */
+    // 配下判定の前に正規化を確かめる (`..` 経由でリポジトリへ戻る形を通さない)。
+    externalFakeProbeAssertNormalizedPath($configCache, 'config_cache');
+
+    expect(BootProbeRunner::isInside($run['temporaryRoot'], $configCache))->toBeTrue()
+        // 設定キャッシュ**無し**の起動を観測している (書かれていたら前提が崩れている)。
+        ->and($run['writtenRelativePaths'])->not->toContain('bootstrap-cache/config.php');
 });
 
 test('P-12 宣言の型: 観測が読む swaps() は ExternalFakeBinding の列である', function (): void {
@@ -261,3 +467,105 @@ function externalFakeProbeResolved(array $output): array
         expect($swap)->toBeInstanceOf(ExternalFakeBinding::class);
     }
 });
+
+test('P-13 実働証明(実体): 子が storage_path() 経由で書いた印が一時ディレクトリ配下に現れる', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    expect($run['writtenRelativePaths'])
+        ->toContain('storage/'.FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
+});
+
+test('P-14 実働証明(向き): 子が解決した書き出し先が 1 件残らず一時ディレクトリ配下でリポジトリの外', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    $targets = $run['output']['write_targets'] ?? null;
+    expect($targets)->toBeArray();
+    /** @var array<string, mixed> $targets */
+    $repositoryRoot = realpath(base_path());
+    expect($repositoryRoot)->toBeString();
+    /** @var string $repositoryRoot */
+    $expectedKeys = ['storage', 'config_cache', 'routes_cache', 'services_cache',
+        'packages_cache', 'events_cache', 'view_compiled', 'log_path'];
+    expect(array_keys($targets))->toBe($expectedKeys, '観測点の集合が変わっている');
+
+    foreach ($expectedKeys as $key) {
+        $path = $targets[$key];
+        expect($path)->toBeString();
+        /** @var string $path */
+
+        // ★配下判定の**前に**正規化を確かめる。isInside は realpath 済みを前提にするので、
+        //   `..` を含む形は「一時 root 配下かつリポジトリ外」と誤判定されうる (fail-open)。
+        externalFakeProbeAssertNormalizedPath($path, $key);
+
+        // 区切り文字を境界にした配下判定 (素の前方一致は /a と /ab を取り違える)。
+        // isInside は同一パスも true にするので、base_path() 自身も「外ではない」に入る。
+        expect(BootProbeRunner::isInside($run['temporaryRoot'], $path))
+            ->toBeTrue("書き出し先 {$key} が一時ディレクトリの外を指している: {$path}")
+            ->and(BootProbeRunner::isInside($repositoryRoot, $path))
+            ->toBeFalse("書き出し先 {$key} がリポジトリ側を指している: {$path}");
+    }
+});
+
+test('P-15 fail-closed: interpret() は観測が成立していない結果を沈黙させない', function (): void {
+    $make = static fn (string $stdout, bool $timedOut, int $exitCode): BootProbeResult => new BootProbeResult(
+        stdout: $stdout, stderr: '', exitCode: $exitCode, timedOut: $timedOut,
+        elapsedSeconds: 0.1, temporaryRoot: '/tmp/boot-probe-x',
+        writtenRelativePaths: [], pid: 1,
+    );
+
+    $call = static fn (BootProbeResult $result): array => FakeWiringProbeRunner::interpret(
+        $result, [], [], '/tmp/dir', 0700, 0600,
+    );
+
+    // (a) 制限時間超過は通常の非ゼロ終了と区別して例外にする (fail-open 防止)
+    expect(fn (): array => $call($make('{"resolved":{}}', true, 124)))->toThrow(RuntimeException::class);
+    // (b) 空出力 / (c) JSON でない / (d) トップレベルが配列でない
+    expect(fn (): array => $call($make('', false, 0)))->toThrow(RuntimeException::class);
+    expect(fn (): array => $call($make('not json', false, 0)))->toThrow(RuntimeException::class);
+    expect(fn (): array => $call($make('"scalar"', false, 0)))->toThrow(RuntimeException::class);
+});
+
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
+test('P-16 正規化判定の検出力: 正常な絶対パスは通り、`..` / `.` / 相対パスは弾く', function (
+    string $path,
+    bool $expected,
+): void {
+    expect(externalFakeProbeIsNormalizedAbsolutePath($path))->toBe($expected, $path);
+})->with([
+    // --- 正例 (実データと同じ形。これが false になると P-11 / P-14 が偽レッドになる) ---
+    ['/tmp/boot-probe-abc/storage', true],
+    ['/tmp/boot-probe-abc/bootstrap-cache/config.php', true],
+    ['/tmp/boot-probe-abc/storage/framework/views', true],
+    // --- 負例: `..` でリポジトリ側へ戻れる形 (これを通すと P-11 / P-14 が fail-open) ---
+    ['/tmp/boot-probe-abc/../../workspace/bootstrap/cache/config.php', false],
+    ['/tmp/boot-probe-abc/..', false],
+    ['/../tmp/boot-probe-abc/storage', false],
+    // --- 負例: `.` セグメント ---
+    ['/tmp/boot-probe-abc/./storage', false],
+    ['/tmp/./boot-probe-abc/storage', false],
+    // --- 負例: 相対パス (絶対パス前提が崩れた形) ---
+    ['tmp/boot-probe-abc/storage', false],
+    ['./storage', false],
+    ['../storage', false],
+    // --- 紛らわしいが正当な形 (素の部分文字列判定なら誤って弾く 3 形) ---
+    ['/tmp/boot-probe-abc/..hidden', true],
+    ['/tmp/boot-probe-abc/.hidden', true],
+    ['/tmp/boot-probe-abc/a..b/storage', true],
+]);
diff --git a/tests/Architecture/PhpBootProbeReferenceInventoryTest.php b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
new file mode 100644
index 00000000..3b7acca3
--- /dev/null
+++ b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
@@ -0,0 +1,905 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\PhpTokenScan;
+use Tests\Support\ReferenceKind;
+use Tests\Support\TrackedPhpSourceFiles;
+
+/*
+| `tests/` 配下の**3 種類の字句参照**の全数申告 inventory —
+|   (A) 定数 `PHP_BINARY` の参照 / (B) 文字列 `bootstrap/app.php` の参照 /
+|   (C) 文字列 `fake-wiring-probe.php` (既存の子入口) の参照。
+| lctl feature: subprocess-boot-probe-harness (正典 v1 の作法へ追従したあとの退行を検出する)。
+| **本 gate は正典 v1 の 6 不変条件ではなく aicue 側の上積みである** (根拠: 正典テンプレートの
+| 同型 gate と AGENTS.md 禁止事項 1)。
+|
+| **名前のとおり、これは「起動の全数」ではなく「参照の全数」の inventory である。**
+| 「PHP の子プロセスを起こしうる箇所を漏れなく数える」ことは**していない**。
+|
+| ## 主張すること
+|
+| 「`PHP_BINARY` の字句参照 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
+| 既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**」。
+| 加えて「子入口 (`child_entry`) は**環境ファイルの退避を字句として持ち**、裏取りの名指しは
+| **実在するパス**である」(G-9)。
+|
+| ## 主張しないこと (名指しで書く)
+|
+|  1. 「アプリを子プロセスで起こす経路が共通の起動器ちょうど 1 本である」こと
+|  2. 文字列リテラルの `'php'` / `env php` / シェルスクリプト経由 /
+|     変数から取り出した実行体パスの検出
+|  3. **起動呼び出しの分類** — 「どのクラスの `new` か」「`proc_open` かその別名か」といった
+|     網羅的な分類は**行わない** (行えば「緑のまま嘘をつく」)。
+|     G-6 が確かめるのは**共通の起動器への静的呼び出しが在ること**だけである
+|  4. 文字列を分割して針を避ける形 (`'fake-wiring-'.'probe.php'`) の検出
+|  5. **環境ファイルの退避が実際に効く位置に在ること** (G-9 は字句の在否だけを見る。
+|     位置の正しさは各経路の実挙動の検査が担う)
+|
+| ## 軸ごとの名前解決の扱い (AGENTS.md §静的検査の共通規約 (a) / (b))
+|
+|  - **G-6 は完全修飾名で突き合わせる**。`Tests\Support\PhpReferenceScanner` が
+|    `use` / group use / 別名つき取り込みを解いた FQCN を返すので、それを
+|    `Tests\Support\Process\BootProbeRunner` と完全一致で比べる。
+|    したがって `use … as Runner; Runner::run(` も**正しく検出する**一方、
+|    **同名の別クラス** (`Other\BootProbeRunner::run(`) は**検出しない** (短名一致ではない)。
+|    受け手が静的に確定できない形 (`$runner::run(` / `static::` 等) は
+|    **「呼んでいる証拠」として数えない** — G-6 は存在を主張する検査なので、
+|    未解決を証拠に数える方が危険側だからである
+|  - **軸 A は名前トークンの末尾要素**で判定する。定数の参照には `PhpReferenceScanner` の
+|    母集団 (クラス名の参照 / 構築 / 呼び出し) が対応しないためで、
+|    ここは**拾いすぎる方向** ((b) の許す側) へ倒してある。
+|    帰結として `Foo\PHP_BINARY` という**別の定数**も軸 A に入る
+|    (申告を 1 行足せば済むので、見逃すより安全側である)
+|
+| **一元化そのものの証拠は載せ替えの実測 (`ExternalFakeBootProbeTest` の P-7〜P-15) であり、
+| 本 gate は退行の検出器である。**
+|
+| ## 走査対象と走査の意味論
+|
+|  - 母集団は `Tests\Support\TrackedPhpSourceFiles` が返す **git 追跡下の `*.php`** のうち
+|    `tests/` 配下 (**未追跡のファイルは母集団に入らない**。`TrackedPhpSourceFiles` の docblock)
+|  - 判定は `Tests\Support\PhpTokenScan::normalize()` の上に建てる。
+|    **コメント・docblock は正規化が落とすので数えない**
+|  - 軸 A の「定数の参照」は**名前トークンの末尾要素の完全一致**で判定する
+|    (`T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`)。区切りは `\` である。
+|    `\PHP_BINARY` と `use const Foo\PHP_BINARY as X;` の別名 import も末尾要素で拾うので
+|    fail-closed になる。接頭辞つき (`MY_PHP_BINARY`) / 打ち消しつき (`NOT_PHP_BINARY`) /
+|    接尾辞つき (`PHP_BINARY_PATH`) は**別のトークン**なので拾わない
+|    (AGENTS.md §静的検査の共通規約 (e) の 3 形。G-7 が両方向を固定する)
+|  - 軸 B / 軸 C の「文字列の参照」は文字列トークン
+|    (`T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE`) の**素の部分文字列**一致である
+|    (ヒアドキュメント・ナウドキュメントの本文を含む)
+*/
+
+/**
+ * 軸 A: `tests/` 配下で `PHP_BINARY` を参照してよいファイルの全数申告 (deny-by-default)。
+ *
+ * entry は 4 つの欄を独立に持つ (「件数合わせの allowlist」へ流れないための構造):
+ *  - `launches_app`: アプリを起こすと申告するか (**補助的な申告値**。実際の起動経路の
+ *    全数性を表すものではなく、「アプリを起こす」と申告する先が分散していないことだけを固定する)
+ *  - `subject` / `recovery` / `reason`
+ *
+ * @return array<string, array{launches_app: bool, subject: non-empty-string, recovery: non-empty-string, reason: non-empty-string}>
+ */
+function phpBootProbeBinaryReferenceInventory(): array
+{
+    return [
+        'tests/Support/Process/BootProbeRunner.php' => [
+            'launches_app' => true,
+            'subject' => 'アプリを子プロセスで起こして起動順序を測る (PHP_BINARY)',
+            'recovery' => '本クラス自身 (制限時間・段階的強制終了・終了コードの保持・一時ディレクトリの後片付け)',
+            'reason' => '共通の起動器そのもの (lctl feature: subprocess-boot-probe-harness)',
+        ],
+        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
+            'launches_app' => false,
+            'subject' => '起動器の自己検査。参照は期待値の比較と、子へ渡す検体文字列の中だけである',
+            'recovery' => '起動器 (本ファイルは直接の起動 API を持たず、BootProbeRunner 経由でのみ子を起こす)',
+            'reason' => 'テンプレートから取り込んだ共有ファイルである (T249 のローカル修正 1 件を除いて '
+                .'バイト一致。修正の理由は当該 docblock)。起動器を通してしか子を起こさない',
+        ],
+        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php' => [
+            'launches_app' => true,
+            'subject' => '実プロセス 2 本を合図で同期させる並行テストの子を起こす (子はアプリを起動する)',
+            'recovery' => '同 harness の runner (単一の絶対 deadline + 段階的強制終了。Symfony 側の制限時間は無効化)',
+            'reason' => '別 feature (lctl: process-concurrency-test-harness) の正典 v1 が持つ回収規約に属する。'
+                .'本 feature (subprocess-boot-probe-harness) の boundary は「子を 2 本立てて合図で同期させる '
+                .'並行テスト」を明示的に除いているので、共通の起動器へは載せない',
+        ],
+        'tests/Support/StrictTypesRuntimeProbe.php' => [
+            'launches_app' => false,
+            'subject' => '検体 PHP を子で読み込み declare(strict_types=1) の実効性を測る。アプリは起こさない',
+            'recovery' => 'Symfony の Process (既定の制限時間つきで、超過すれば例外になる)',
+            'reason' => '起動順序ではなく単一ファイルのコンパイル指令を測る層である。起動器に載せると '
+                .'Laravel 固有の基底環境・書き出し先 7 キーの予約という無関係な前提が付く '
+                .'(同じ理由で PhpLintOracle も載せていない)',
+        ],
+        'tests/Support/GlobalUse/PhpLintOracle.php' => [
+            'launches_app' => false,
+            'subject' => '`php -l` を真値として取り出す (構文検査のみ。アプリは起こさない)',
+            'recovery' => '同クラス (Symfony Process が管を読み切り、終了コードが null なら例外にする)',
+            'reason' => 'アプリを起動しないので環境の 3 段合成も書き出し先の退避も要らない',
+        ],
+        'tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php' => [
+            'launches_app' => false,
+            'subject' => 'テスト DB の用意スクリプトを起こす (DB へは接続しない)。アプリは起こさない',
+            'recovery' => '同ファイルの helper (管を読み切って proc_close する)',
+            'reason' => 'アプリの起動順序ではなくスクリプトの契約を測る層である '
+                .'(lctl feature: php-test-pgsql-lane 側の関心事。本 feature とは distinct_from の関係)',
+        ],
+        'tests/Architecture/NoNonCompoundGlobalUseTest.php' => [
+            'launches_app' => false,
+            'subject' => '診断メッセージへ実行体のパスを載せるだけ (子は起こさない)',
+            'recovery' => '該当なし (起動しない)',
+            'reason' => '起動は PhpLintOracle が行い、本ファイルは失敗時の診断に PHP_BINARY を印字するだけである',
+        ],
+        'tests/Feature/Console/PipelineSmokeCommandTest.php' => [
+            'launches_app' => false,
+            'subject' => 'ffmpeg の代役として設定値へ実行体のパスを入れるだけ (テストから子は起こさない)',
+            'recovery' => '該当なし (起動するのはアプリ側の合成経路であり、本 feature の射程外)',
+            'reason' => 'アプリの起動順序を測る経路ではない (ffmpeg 起動の統制は '
+                .'tests/Architecture/FfmpegProcessLaunchInventoryTest.php が持つ)',
+        ],
+    ];
+}
+
+/**
+ * 軸 B: `tests/` 配下でアプリの起動点 (`bootstrap/app.php`) を参照してよいファイルの全数申告。
+ *
+ * `kind` は 3 値:
+ *  - `child_entry` : 子プロセスで読み込まれる入口 / 子へ渡す検体文字列
+ *  - `in_process`  : 同一プロセスでのアプリ起動 (子プロセスではない)
+ *  - `inventory`   : 検査定義・診断文としてパス文字列を保持するだけ
+ *
+ * `env_isolation` は**子入口だけが持つ**欄で、「リポジトリの `.env` を読まないことを
+ * **何が守っているか**」を 3 値で分類する:
+ *
+ *  - `behavioural` : **実挙動の検査が在る** (子が読んだ環境ファイルの場所を実測して固定している)
+ *  - `structural`  : **退避の呼び出しが在ることを字句で pin しているだけ** (G-9)。
+ *    実挙動の裏取りは無いので、**この経路について「実際に読まない」とは主張しない**
+ *  - `none`        : どちらも無い (**申告できる値だが、G-8 が 0 件で pin する**)
+ *
+ * `env_isolation_proof` は上の分類の根拠 (`behavioural` なら検査の名前)。
+ * 子入口でない kind (`in_process` / `inventory`) は `env_isolation` を `null`、
+ * 根拠を空文字にする (子が居ないので分類の対象が無い)。
+ *
+ * 詳細と、この分類で**何を主張しないか**は G-8 の docblock を読むこと。
+ *
+ * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', env_isolation: 'behavioural'|'structural'|'none'|null, env_isolation_proof: string, reason: non-empty-string}>
+ */
+function phpBootProbeAppBootEntryReferenceInventory(): array
+{
+    return [
+        'tests/Support/ExternalFakes/fake-wiring-probe.php' => [
+            'kind' => 'child_entry',
+            // 専用の 0600 環境ファイルへ固定して起動する (リポジトリの .env は読まない)。
+            'env_isolation' => 'behavioural',
+            'env_isolation_proof' => 'tests/Architecture/ExternalFakeBootProbeTest.php P-17 '
+                .'(子が報告した環境ファイルの絶対パスが、起動側が用意した専用ファイルと完全一致する) '
+                .'+ 同 P-8 (子で実際に効いた鍵が専用ファイルの使い捨て値と一致し、親の設定値とは一致しない)',
+            'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
+        ],
+        'tests/Support/Concurrency/idempotency-claim-probe.php' => [
+            'kind' => 'child_entry',
+            // 段 8 で useEnvironmentPath() / loadEnvironmentFrom() を専用の一時 env ファイルへ向ける。
+            // ★実挙動の裏取りは無い (この経路について「実際に読まない」とは主張しない)。
+            'env_isolation' => 'structural',
+            'env_isolation_proof' => '段 8 の $app->useEnvironmentPath() / loadEnvironmentFrom() を '
+                .'G-9 が字句で pin するだけである。読んだ環境ファイルの場所を実測する検査は無い。'
+                .'足すには子の観測 DTO (Tests\Support\Concurrency\ConcurrentProbeObservation) から '
+                .'親までの 4 段を変えることになり、それは別 feature '
+                .'(lctl: process-concurrency-test-harness) の契約なので本 TODO では行わない',
+            'reason' => '実プロセス並行テストの子入口。別 feature (process-concurrency-test-harness) の持ち物である',
+        ],
+        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
+            'kind' => 'child_entry',
+            // ★T249 のローカル修正で、S9 / S10 の検体は起動前に環境ファイルの置き場所を
+            //   起動器の一時ディレクトリへ逃がす (取り込み元の姿ではリポジトリの .env を読んでいた)。
+            'env_isolation' => 'behavioural',
+            'env_isolation_proof' => 'tests/Unit/Support/Process/BootProbeRunnerTest.php S9 '
+                .'(子が報告した環境ファイルの絶対パスが <一時ディレクトリ>/.env と完全一致し、'
+                .'その場所に環境ファイルが実在しないこと + 環境ファイルからしか来ない設定値 2 つが空であること)',
+            'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
+        ],
+        'tests/TestCase.php' => [
+            'kind' => 'in_process',
+            // 同一プロセスなので phpunit.xml の <server force> が効く (秘密は無害化済み)。
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
+            'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
+        ],
+        'tests/Support/Cache/IsolatedApplicationProbe.php' => [
+            'kind' => 'in_process',
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
+            'reason' => 'キャッシュ受け皿の結線を測るための第 2 のアプリを同一プロセスで組み立てる。子プロセスではない',
+        ],
+        'tests/Architecture/CacheGuardWiringGateTest.php' => [
+            'kind' => 'inventory',
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
+            'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
+        ],
+        'tests/Architecture/BughuntExecutedRouteOrderingTest.php' => [
+            'kind' => 'inventory',
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
+            'reason' => '記録器の位置を固定する検査が、違反時の直し方を案内する診断文にパス文字列を持つ',
+        ],
+        'tests/Architecture/InertiaErrorScreenContractTest.php' => [
+            'kind' => 'inventory',
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
+            'reason' => '例外応答の最終整形スロットの登録位置を検査する側が、照合する場所としてパス文字列を持つ',
+        ],
+        'tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php' => [
+            'kind' => 'inventory',
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
+            'reason' => '撤去表面の走査対象の定義が、走査根の 1 つとしてパス文字列を持つ',
+        ],
+        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
+            'kind' => 'inventory',
+            'env_isolation' => null,
+            'env_isolation_proof' => '',
+            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
+        ],
+    ];
+}
+
+/**
+ * 軸 C: 子入口スクリプトのパスを参照してよいファイルの全数申告。
+ *
+ * `reference_kind` は 2 値: `runtime` (実行経路として子入口を起こす) / `inventory` (検査定義)。
+ *
+ * @return array<string, array{reference_kind: 'runtime'|'inventory', reason: non-empty-string}>
+ */
+function phpBootProbeChildEntryReferenceInventory(): array
+{
+    return [
+        'tests/Support/ExternalFakes/FakeWiringProbeRunner.php' => [
+            'reference_kind' => 'runtime',
+            'reason' => '子入口を起こす唯一の呼び出し元。起こし方と回収は BootProbeRunner に委ねる',
+        ],
+        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
+            'reference_kind' => 'inventory',
+            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
+        ],
+    ];
+}
+
+/** 走査の針 (2 箇所に書かない)。 */
+const PHP_BOOT_PROBE_APP_ENTRY_NEEDLE = 'bootstrap/app.php';
+
+const PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE = 'fake-wiring-probe.php';
+
+/** G-6 が完全修飾名で突き合わせる共通の起動器。 */
+const PHP_BOOT_PROBE_RUNNER_FQCN = 'Tests\\Support\\Process\\BootProbeRunner';
+
+/**
+ * 名前トークンの末尾要素 (区切りは `\`)。
+ *
+ * `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` は 1 トークンで届くので、
+ * 素の部分文字列一致ではなく区切りで割った完全一致で比べる
+ * (AGENTS.md §静的検査の共通規約 (e))。
+ */
+function phpBootProbeLastNameSegment(string $name): string
+{
+    $segments = explode('\\', $name);
+
+    return $segments[count($segments) - 1];
+}
+
+/**
+ * ソースが定数 `$constant` を**名前として**参照しているか。
+ *
+ * 文字列リテラルの中の同じ綴りは数えない (トークン種別で区別する)。
+ */
+function phpBootProbeReferencesConstant(string $source, string $constant): bool
+{
+    foreach (PhpTokenScan::normalize($source) as $token) {
+        if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+            continue;
+        }
+
+        if (phpBootProbeLastNameSegment($token['text']) === $constant) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * ソースの**文字列トークン**に `$needle` が現れるか
+ * (ヒアドキュメント・ナウドキュメントの本文を含む。コメントは正規化が落とす)。
+ */
+function phpBootProbeReferencesStringNeedle(string $source, string $needle): bool
+{
+    foreach (PhpTokenScan::normalize($source) as $token) {
+        if (! in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
+            continue;
+        }
+
+        if (str_contains($token['text'], $needle)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * ソースが**共通の起動器**への静的呼び出し `BootProbeRunner::run(` を持つか。
+ *
+ * ★照合は**完全修飾名**で行う (AGENTS.md §静的検査の共通規約 (a))。
+ *   `Tests\Support\PhpReferenceScanner` が `use` / group use / 別名つき取り込みを解いた
+ *   FQCN を返すので、短名一致で同名の別クラスを拾うことも、別名 1 つで黙ることも無い。
+ * ★受け手が静的に確定できない形 (`$runner::run(` / `static::` 等) は
+ *   **証拠として数えない**。G-6 は「呼んでいる」ことを主張する検査なので、
+ *   未解決を肯定側へ数える方が危険である。
+ */
+function phpBootProbeCallsBootProbeRunner(string $relativePath, string $source): bool
+{
+    foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
+        if ($site->kind !== ReferenceKind::StaticCall || $site->name !== 'run') {
+            continue;
+        }
+
+        if (! $site->receiver->isResolved()) {
+            continue;
+        }
+
+        if ($site->receiver->fqcn() === PHP_BOOT_PROBE_RUNNER_FQCN) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * 正規化済みトークン列が**環境ファイルの退避の呼び出し**
+ * `$app` `->` `useEnvironmentPath` `(` を持つか (4 トークンの**完全一致**)。
+ *
+ * ★受け手を `$app` に固定する。名前だけを見ると `$unrelated->useEnvironmentPath(…)` も
+ *   証拠になってしまい、**存在を肯定する検査で拾いすぎる** (AGENTS.md §静的検査の共通規約 (b))。
+ *   変数の型は字句では解決できないので、**受け手の綴りまで固定する**のが本 gate で取れる
+ *   いちばん強い形である (摩擦は「子入口では `$app` という名前で受ける」だけ)。
+ * ★語彙一致は区切り (`->`) で割ったトークンの完全一致で判定するので、
+ *   接頭辞つき (`myUseEnvironmentPath`) / 打ち消しつき (`notUseEnvironmentPath`) /
+ *   接尾辞つき (`useEnvironmentPathX`) は**別トークン**として落ちる (同規約 (e))。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ */
+function phpBootProbeHasEnvironmentPathCall(array $tokens): bool
+{
+    $count = count($tokens);
+    for ($i = 0; $i + 3 < $count; $i++) {
+        if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== '$app') {
+            continue;
+        }
+
+        if ($tokens[$i + 1]['id'] !== T_OBJECT_OPERATOR) {
+            continue;
+        }
+
+        if ($tokens[$i + 2]['id'] !== T_STRING || $tokens[$i + 2]['text'] !== 'useEnvironmentPath') {
+            continue;
+        }
+
+        if ($tokens[$i + 3]['id'] === null && $tokens[$i + 3]['text'] === '(') {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * ソースが**環境ファイルの退避**を持つか (実コード、または子へ渡す検体ソースの中)。
+ *
+ * 判定は 2 段で、**どちらもトークンの完全一致**である (素の部分文字列一致は使わない):
+ *
+ *  1. ソース自身の正規化トークン列に `$app->useEnvironmentPath(` が在る
+ *     (`fake-wiring-probe.php` / `idempotency-claim-probe.php` のような実コード)
+ *  2. 文字列トークン (ヒアドキュメント・ナウドキュメントの本文を含む) の中身を
+ *     **PHP として字句解析し直し**、同じ 4 トークンの並びが在る
+ *     (`BootProbeRunnerTest` のように、子へ渡す検体ソースを文字列で持つ形)
+ *
+ * ★段 2 を素の部分文字列一致で書くと `'useEnvironmentPath is required'` のような
+ *   ただの散文や、`'$app->notUseEnvironmentPath(…)'` のような打ち消しつきまで通る。
+ *   **文字列の中も字句解析して同じ規則で判定する** (AGENTS.md §静的検査の共通規約 (e))。
+ * ★コメント・docblock は `PhpTokenScan::normalize()` が落とすので数えない。
+ *
+ * **主張しないこと**: 呼び出しが**実際に効く位置** (アプリ起動より前) に在ることは見ない。
+ * 位置の正しさは各経路の実挙動の検査が担う (G-8 の `env_isolation` 参照)。
+ */
+function phpBootProbeMentionsEnvironmentPathRelocation(string $source): bool
+{
+    $tokens = PhpTokenScan::normalize($source);
+
+    if (phpBootProbeHasEnvironmentPathCall($tokens)) {
+        return true;
+    }
+
+    foreach ($tokens as $token) {
+        if (! in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
+            continue;
+        }
+
+        $body = $token['text'];
+        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && strlen($body) >= 2) {
+            // 引用符を落とす (中身だけを字句解析する)。
+            $body = substr($body, 1, -1);
+        }
+
+        if (phpBootProbeHasEnvironmentPathCall(PhpTokenScan::normalize('<?php '.$body))) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * 走査の母集団: git 追跡下の `tests/` 配下の `*.php` (相対パス => ソース)。
+ *
+ * @return array<string, string>
+ */
+function phpBootProbeTestSources(): array
+{
+    /** @var array<string, string>|null $cache */
+    static $cache = null;
+
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $sources = [];
+    foreach (TrackedPhpSourceFiles::all(base_path()) as $file) {
+        if (! str_starts_with($file['relative'], 'tests/')) {
+            continue;
+        }
+
+        $source = file_get_contents($file['absolute']);
+        if ($source === false) {
+            // 読めないファイルを黙って落とすと走査が縮む (fail-closed)。
+            throw new RuntimeException('走査対象を読めなかった: '.$file['relative']);
+        }
+
+        $sources[$file['relative']] = $source;
+    }
+
+    $cache = $sources;
+
+    return $cache;
+}
+
+/**
+ * 実測: 述語が真になった相対パスの昇順リスト。
+ *
+ * @param  callable(string): bool  $matches
+ * @return list<string>
+ */
+function phpBootProbeMeasure(callable $matches): array
+{
+    $hits = [];
+    foreach (phpBootProbeTestSources() as $relative => $source) {
+        if ($matches($source)) {
+            $hits[] = $relative;
+        }
+    }
+
+    sort($hits);
+
+    return $hits;
+}
+
+/** 申告のキーを昇順で取り出す。 @param array<string, mixed> $inventory @return list<string> */
+function phpBootProbeDeclaredPaths(array $inventory): array
+{
+    $paths = array_keys($inventory);
+    sort($paths);
+
+    return $paths;
+}
+
+test('G-1 軸 A: PHP_BINARY を参照するファイルの集合が全数申告と完全一致する', function (): void {
+    $measured = phpBootProbeMeasure(
+        static fn (string $source): bool => phpBootProbeReferencesConstant($source, 'PHP_BINARY'),
+    );
+
+    expect($measured)->toBe(
+        phpBootProbeDeclaredPaths(phpBootProbeBinaryReferenceInventory()),
+        '未申告のファイルが PHP_BINARY を参照している、または申告が実体より多い。'
+        .'足すときは launches_app / subject / recovery / reason の 4 欄を埋めること',
+    );
+});
+
+/**
+ * G-2: 「アプリを起こす」と申告してよい起こし手の**完全一致 pin**。
+ *
+ * ★**1 件ではなく 2 件である**。本 feature (subprocess-boot-probe-harness) の boundary は
+ *   「子を 2 本立てて合図で同期させる並行テスト」を明示的に**除いて**おり、そちらは別 feature
+ *   (lctl: process-concurrency-test-harness) が自分の回収規約 (単一の絶対 deadline) を持つ。
+ *   両者を 1 本の起動器へ統合するのは「別物の概念を似ているからで統合する」ことになる
+ *   (AGENTS.md 思考原則 4)。
+ * ★したがって本検査が固定するのは**申告先の集合そのもの**であり、
+ *   「起動経路が 1 本である」ことではない (それは字句走査では裏が取れない。冒頭の
+ *   「主張しないこと」1 を参照)。3 本目が現れたら**どちらの feature の規約に属するのか**を
+ *   申告に書くことになり、レビューに必ず見える。
+ */
+test('G-2 軸 A: アプリを起こすと申告する起こし手が完全一致で pin されている', function (): void {
+    $launching = array_keys(array_filter(
+        phpBootProbeBinaryReferenceInventory(),
+        static fn (array $entry): bool => $entry['launches_app'],
+    ));
+    sort($launching);
+
+    expect($launching)->toBe([
+        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php',
+        'tests/Support/Process/BootProbeRunner.php',
+    ]);
+});
+
+test('G-3 軸 A: subject / recovery / reason の 3 欄がいずれも空でない', function (): void {
+    foreach (phpBootProbeBinaryReferenceInventory() as $path => $entry) {
+        expect(trim($entry['subject']))->not->toBe('', "subject が空: {$path}")
+            ->and(trim($entry['recovery']))->not->toBe('', "recovery が空: {$path}")
+            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
+    }
+});
+
+test('G-4 軸 B: アプリの起動点を参照するファイルの集合が全数申告と完全一致し、kind が 3 値である', function (): void {
+    $measured = phpBootProbeMeasure(
+        static fn (string $source): bool => phpBootProbeReferencesStringNeedle(
+            $source,
+            PHP_BOOT_PROBE_APP_ENTRY_NEEDLE,
+        ),
+    );
+
+    expect($measured)->toBe(
+        phpBootProbeDeclaredPaths(phpBootProbeAppBootEntryReferenceInventory()),
+        '未申告のファイルがアプリの起動点を参照している (kind と reason を 1 行足すこと)',
+    );
+
+    foreach (phpBootProbeAppBootEntryReferenceInventory() as $path => $entry) {
+        // `toContain` は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
+        expect(in_array($entry['kind'], ['child_entry', 'in_process', 'inventory'], true))
+            ->toBeTrue("kind が 3 値の外: {$path}")
+            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
+    }
+});
+
+test('G-5 軸 C: 子入口を参照するファイルの集合が全数申告と完全一致し、reference_kind が 2 値である', function (): void {
+    $measured = phpBootProbeMeasure(
+        static fn (string $source): bool => phpBootProbeReferencesStringNeedle(
+            $source,
+            PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE,
+        ),
+    );
+
+    expect($measured)->toBe(
+        phpBootProbeDeclaredPaths(phpBootProbeChildEntryReferenceInventory()),
+        '未申告のファイルが子入口スクリプトを参照している',
+    );
+
+    foreach (phpBootProbeChildEntryReferenceInventory() as $path => $entry) {
+        // `toContain` は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
+        expect(in_array($entry['reference_kind'], ['runtime', 'inventory'], true))
+            ->toBeTrue("reference_kind が 2 値の外: {$path}")
+            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
+    }
+});
+
+test('G-6 軸 C: runtime はちょうど 1 件で、共通の起動器を実際に呼んでいる', function (): void {
+    $runtime = array_keys(array_filter(
+        phpBootProbeChildEntryReferenceInventory(),
+        static fn (array $entry): bool => $entry['reference_kind'] === 'runtime',
+    ));
+
+    expect($runtime)->toBe(['tests/Support/ExternalFakes/FakeWiringProbeRunner.php']);
+
+    $sources = phpBootProbeTestSources();
+    foreach ($runtime as $path) {
+        expect($sources)->toHaveKey($path);
+        expect(phpBootProbeCallsBootProbeRunner($path, $sources[$path]))
+            ->toBeTrue("{$path} が ".PHP_BOOT_PROBE_RUNNER_FQCN.'::run( を呼んでいない (子の起こし方が一元化から外れている)');
+    }
+});
+
+/**
+ * G-8: 子プロセスがリポジトリの `.env` を読んで起動しないこと (申告 0 件の pin + 裏取りの名指し)。
+ *
+ * ## 何を守っているか
+ *
+ * 共通の起動器は `proc_open` へ渡す環境配列で開発者ローカルの env を締め出すが、
+ * **`.env` ファイルの読み込みまでは止めない**。子の作業ディレクトリはリポジトリ root なので、
+ * 子が `bootstrap/app.php` を**素で**読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
+ * これは正典 v1 (2) の「開発者ローカルの環境変数を入力集合から外す」を、
+ * 環境変数ではなく**環境ファイル**の経路で迂回してしまう形である。
+ *
+ * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査 S9 / S10 の検体を取り込み元の姿
+ * (環境ファイルの置き場所を移さない形) で走らせると、子の設定に `.env` 由来の
+ * **DB のパスワードと実 `CIPHERSWEET_KEY`** が載った。外部サービスの資格情報
+ * (Stripe / AWS / Google / SMTP) は本チェックアウトではいずれも空だったが、
+ * **「空だった」のはこのチェックアウトの性質であって保証ではない。**
+ * この実測を受けて S9 / S10 の検体には**起動前に環境ファイルの置き場所を一時ディレクトリへ
+ * 逃がす 1 行**を入れた (取り込み元からの意図的な逸脱。理由は当該 docblock)。
+ *
+ * ## 何を機械で固定しているか
+ *
+ *  1. `env_isolation` が `none` の子入口は**ちょうど 0 件**である (完全一致 pin)。
+ *     退避も裏取りも無い子入口を足すには申告を書き換えることになり、レビューに必ず見える
+ *  2. `child_entry` は `env_isolation` を `behavioural` / `structural` のどちらかで申告し、
+ *     **根拠の欄 (`env_isolation_proof`) を必ず持つ** (空では通らない)
+ *  3. **`structural` の集合は完全一致で pin する** — 実挙動の裏取りが無い経路が
+ *     黙って増えないようにするため。**この集合について「実際に `.env` を読まない」とは
+ *     主張しない** (下の「主張しないこと」を参照)
+ *  4. `child_entry` 以外 (`in_process` / `inventory`) は定義上この分類の対象でないので、
+ *     `env_isolation` が `null` であること・根拠が空であることを両方向で固定する
+ *     (取り違えの検出)
+ *
+ * ## 対比 (なぜ同一プロセスは対象外なのか)
+ *
+ * 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
+ * 効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
+ * **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
+ * これが子と同一プロセスの非対称の正体である。
+ *
+ * ## 主張しないこと (誇張しない)
+ *
+ * **「子はリポジトリの `.env` を読まない」を全経路について主張しない。**
+ * 主張できるのは `env_isolation: behavioural` の経路だけで、そちらの根拠は本検査ではなく
+ * **名指しされた実挙動の検査そのもの**である:
+ *
+ *  - `tests/Unit/Support/Process/BootProbeRunnerTest.php` の S9 — 子が報告した環境ファイルの
+ *    絶対パスが `<一時ディレクトリ>/.env` と完全一致し、そこに実在しないこと
+ *  - `tests/Architecture/ExternalFakeBootProbeTest.php` の P-17 / P-8 — 子が報告した
+ *    環境ファイルの絶対パスが起動側の専用ファイルと完全一致し、効いた鍵がその中身と一致すること
+ *
+ * `env_isolation: structural` の経路 (現在 1 件) については
+ * **「実際に読まない」とは主張しない** — 分かっているのは「退避の呼び出しが字句として在る」
+ * ことだけである (G-9)。呼び出しが**効く位置**に在るかも、他の値を読んでいないかも見ていない。
+ *
+ * さらに、本検査が機械で確かめるのは**申告と根拠の記載**であって、
+ * 名指しした検査が実際に何を測っているかではない。したがって次は本検査を通る:
+ *
+ *  1. `env_isolation_proof` に**実在はするが何も測っていない**検査名を書く
+ *     (実在しない名前は G-9 が落とす)
+ *  2. 既存の `child_entry` の中で、`.env` を読む検体を**増やす** (ファイル単位の申告は変わらない)
+ */
+test('G-8 退避も裏取りも無い子入口は 0 件で、実挙動の裏取りが無い経路は完全一致で pin されている', function (): void {
+    $inventory = phpBootProbeAppBootEntryReferenceInventory();
+
+    $childEntries = [];
+    $structuralOnly = [];
+
+    foreach ($inventory as $path => $entry) {
+        if ($entry['kind'] !== 'child_entry') {
+            // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
+            //   定義上この分類の対象ではない。取り違えを防ぐために両方向で固定する。
+            expect($entry['env_isolation'])
+                ->toBeNull("子が居ない経路に env_isolation が申告されている: {$path}")
+                ->and(trim($entry['env_isolation_proof']))
+                ->toBe('', "子が居ない経路に根拠の記載がある (kind の取り違え): {$path}");
+
+            continue;
+        }
+
+        $childEntries[] = $path;
+
+        // ★分類は 2 値のどちらかで、根拠の記載を必ず持つ (申告だけで済ませない)。
+        expect(in_array($entry['env_isolation'], ['behavioural', 'structural'], true))
+            ->toBeTrue("child_entry の env_isolation が behavioural / structural の外: {$path}")
+            ->and(trim($entry['env_isolation_proof']))
+            ->not->toBe('', "child_entry に env_isolation の根拠が無い: {$path}");
+
+        if ($entry['env_isolation'] === 'structural') {
+            $structuralOnly[] = $path;
+        }
+    }
+
+    sort($structuralOnly);
+
+    // ★**実挙動の裏取りが無い子入口**の集合を完全一致で pin する。
+    //   増やすには申告を書き換えることになり、「なぜ実挙動で測らないのか」がレビューに必ず見える。
+    //   減らす (behavioural へ上げる) ときも同じ。
+    expect($structuralOnly)->toBe(
+        ['tests/Support/Concurrency/idempotency-claim-probe.php'],
+        '実挙動の裏取りを持たない子入口が増減している。'
+        .'足すなら G-8 の docblock を読み、なぜ実挙動で測れないのかを根拠の欄に書くこと',
+    );
+
+    // ★母集団が空のまま緑になる形を塞ぐ (AGENTS.md §静的検査の共通規約 (b) の 3 点目)。
+    expect($childEntries)->not->toBe([], 'child_entry が 1 件も無い (走査か申告が壊れている)');
+});
+
+/**
+ * G-9: `child_entry` は**環境ファイルの退避の呼び出しを字句として持つ** (G-8 の申告への機械の裏打ち)。
+ *
+ * G-8 が見るのは申告と根拠の記載までである。そこへ**2 つだけ機械の裏打ち**を足す:
+ *
+ *  1. `child_entry` の申告ファイルは `$app->useEnvironmentPath(` を**トークンの完全一致**で持つ
+ *     (実コード、または子へ渡す検体ソースの文字列の中。判定は
+ *     `phpBootProbeMentionsEnvironmentPathRelocation()`)。Laravel が読む環境ファイルは
+ *     この呼び出しでしか動かないので、**持たない子入口は既定でリポジトリの `.env` を読む**
+ *     = 新しい子入口を素直に足すと赤になる
+ *  2. `env_isolation_proof` が**検査を名指ししている場合**、その先頭語は
+ *     **実在するパス**である (走査母集団の中に在る)。実在しない検査名で申告を通す形を塞ぐ。
+ *     `structural` の根拠は検査名ではなく散文なので、この検査は
+ *     **`behavioural` の entry にだけ**適用する
+ *
+ * **主張しないこと**:
+ *
+ *  - 呼び出しが**実際に効く位置** (アプリ起動より前) に在ること。字句では決められないので、
+ *    位置の正しさは実挙動の検査 (`BootProbeRunnerTest` の S9 /
+ *    `ExternalFakeBootProbeTest` の P-17) が担う
+ *  - **受け手が本当に Laravel の Application であること**。変数の型は字句では解決できないので、
+ *    受け手は**綴り (`$app`) で固定している**。別名で受ける子入口は赤になる (拾いすぎない側)
+ *  - 名指しした検査が**実際に何を測っているか** (実在の確認までである)
+ */
+test('G-9 child_entry は退避の呼び出しを字句として持ち、behavioural の名指しは実在パスである', function (): void {
+    $sources = phpBootProbeTestSources();
+    $childEntries = 0;
+
+    foreach (phpBootProbeAppBootEntryReferenceInventory() as $path => $entry) {
+        if ($entry['kind'] !== 'child_entry') {
+            continue;
+        }
+
+        $childEntries++;
+
+        expect($sources)->toHaveKey($path);
+        expect(phpBootProbeMentionsEnvironmentPathRelocation($sources[$path]))
+            ->toBeTrue(
+                "child_entry が環境ファイルの退避 (\$app->useEnvironmentPath( ) を持っていない: {$path}"
+            );
+
+        if ($entry['env_isolation'] !== 'behavioural') {
+            // `structural` の根拠は検査名ではなく散文なので、実在確認の対象にしない。
+            continue;
+        }
+
+        // 名指しは「パス + 括弧つきの説明」の形なので、先頭語をパスとして見る。
+        $named = strtok(trim($entry['env_isolation_proof']), " \t");
+        expect(is_string($named) ? $named : '')->not->toBe('', "env_isolation_proof が空: {$path}");
+        expect(array_key_exists((string) $named, $sources))
+            ->toBeTrue("env_isolation_proof が実在しない検査を名指ししている: {$path} => {$named}");
+    }
+
+    // 母集団が空のまま緑になる形を塞ぐ。
+    expect($childEntries)->toBeGreaterThan(0, 'child_entry が 1 件も無い (走査か申告が壊れている)');
+});
+
+test('G-7 走査が空振りしていない (走査根が実在し、3 軸の母集団が非空)', function (): void {
+    expect(is_dir(base_path('tests')))->toBeTrue('走査根 tests/ が実在しない');
+
+    $sources = phpBootProbeTestSources();
+    expect(count($sources))->toBeGreaterThan(100, '母集団が縮んでいる (走査が壊れている可能性)');
+
+    // 申告したパスは 3 軸とも実在する (改名・移動に気づかずに申告だけが残るのを防ぐ)。
+    foreach ([
+        phpBootProbeBinaryReferenceInventory(),
+        phpBootProbeAppBootEntryReferenceInventory(),
+        phpBootProbeChildEntryReferenceInventory(),
+    ] as $inventory) {
+        expect($inventory)->not->toBeEmpty();
+        foreach (array_keys($inventory) as $path) {
+            // `toHaveKey` の第 2 引数は**期待する値**なので、診断文は素の真偽で書く。
+            expect(array_key_exists($path, $sources))
+                ->toBeTrue("申告したパスが母集団に無い (改名・移動・git add 忘れ): {$path}");
+        }
+    }
+});
+
+test('G-7 走査器の見本検査: 3 軸の判定が見本表どおりである', function (
+    string $sample,
+    bool $axisA,
+    bool $axisB,
+    bool $axisC,
+): void {
+    expect(phpBootProbeReferencesConstant($sample, 'PHP_BINARY'))->toBe($axisA, "軸 A: {$sample}")
+        ->and(phpBootProbeReferencesStringNeedle($sample, PHP_BOOT_PROBE_APP_ENTRY_NEEDLE))
+        ->toBe($axisB, "軸 B: {$sample}")
+        ->and(phpBootProbeReferencesStringNeedle($sample, PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE))
+        ->toBe($axisC, "軸 C: {$sample}");
+})->with([
+    // [検体, 軸 A, 軸 B, 軸 C]
+    ['<?php $x = [PHP_BINARY];', true, false, false],
+    ['<?php // PHP_BINARY', false, false, false],
+    ['<?php $s = "PHP_BINARY";', false, false, false],
+    ['<?php use const PHP_BINARY as Runtime; $x = Runtime;', true, false, false],
+    // 完全修飾・修飾つきの定数参照も末尾要素で拾う (fail-closed)。
+    ['<?php $x = \PHP_BINARY;', true, false, false],
+    ['<?php use const Foo\PHP_BINARY as Runtime; $x = Runtime;', true, false, false],
+    // 接頭辞つき・打ち消しつき・接尾辞つきは別トークンなので拾わない。
+    ['<?php $x = MY_PHP_BINARY;', false, false, false],
+    ['<?php $x = NOT_PHP_BINARY;', false, false, false],
+    ['<?php $x = PHP_BINARY_PATH;', false, false, false],
+    ["<?php require 'bootstrap/app.php';", false, true, false],
+    ['<?php // require bootstrap/app.php', false, false, false],
+    ["<?php \$p = __DIR__.'/fake-wiring-probe.php';", false, false, true],
+    // 文字列を分割して針を避ける形は**射程外**。限界を期待値として固定する。
+    ['<?php $a = \'fake-wiring-\'."probe.php";', false, false, false],
+    // ★軸 B / C は**素の部分文字列**一致である (軸 A の語彙一致とは判定が違う)。
+    //   接頭辞つき・打ち消しつき・接尾辞つきは**いずれも一致する** = 申告が要る側へ倒れる。
+    //   見逃す方向ではなく拾いすぎる方向なので (b) の許す側であり、
+    //   紛らわしい綴りを足した人には「1 行申告する」という摩擦だけが掛かる。
+    ["<?php \$p = 'vendor/bootstrap/app.php';", false, true, false],
+    ["<?php \$p = 'not-bootstrap/app.php';", false, true, false],
+    ["<?php \$p = 'bootstrap/app.php.bak';", false, true, false],
+    ["<?php \$p = 'old-fake-wiring-probe.php';", false, false, true],
+    ["<?php \$p = 'fake-wiring-probe.php.disabled';", false, false, true],
+    // 針の一部だけでは一致しない (部分文字列一致の下界も固定する)。
+    ["<?php \$p = 'bootstrap/app.phpx';", false, true, false],
+    ["<?php \$p = 'bootstrap/application.php';", false, false, false],
+    ["<?php \$p = 'fake-wiring-probe.txt';", false, false, false],
+]);
+
+test('G-7 走査器の見本検査: 環境ファイルの退避の字句判定 (名前・文字列の両方 / 3 形の否定)', function (
+    string $sample,
+    bool $expected,
+): void {
+    expect(phpBootProbeMentionsEnvironmentPathRelocation($sample))->toBe($expected, $sample);
+})->with([
+    // --- 正例: 実コード / 単一引用符の中 / ナウドキュメントの本文 (3 分岐すべて) ---
+    ['<?php $app->useEnvironmentPath($dir);', true],
+    ["<?php \$code = '\$app->useEnvironmentPath(\$dir);';", true],
+    ["<?php \$code = <<<'PHP'
+\$app->useEnvironmentPath(\$dir);
+PHP;", true],
+    // --- 負例: コメントだけ (正規化が落とす) ---
+    ['<?php // useEnvironmentPath', false],
+    ['<?php /** useEnvironmentPath */ $x = 1;', false],
+    // --- 負例: 接頭辞つき・打ち消しつき・接尾辞つきの**名前** (実コード側) ---
+    ['<?php $app->myUseEnvironmentPath($dir);', false],
+    ['<?php $app->notUseEnvironmentPath($dir);', false],
+    ['<?php $app->useEnvironmentPathX($dir);', false],
+    // --- 負例: 同じ 3 形を**文字列の中**でも落とす (段 2 が部分文字列一致でないことの裏取り) ---
+    ["<?php \$code = '\$app->myUseEnvironmentPath(\$dir);';", false],
+    ["<?php \$code = '\$app->notUseEnvironmentPath(\$dir);';", false],
+    ["<?php \$code = '\$app->useEnvironmentPathX(\$dir);';", false],
+    // --- 負例: 文字列の中の散文・呼び出しでない形 ---
+    ["<?php \$msg = 'useEnvironmentPath is required';", false],
+    ["<?php \$s = 'useEnvironmentPath';", false],
+    // --- 負例: 受け手が \$app でない (存在を肯定する検査なので拾いすぎない) ---
+    ['<?php $unrelated->useEnvironmentPath($dir);', false],
+    ["<?php \$code = '\$unrelated->useEnvironmentPath(\$dir);';", false],
+    // --- 負例: 呼び出しでない形 (`(` が続かない) ---
+    ['<?php $app->useEnvironmentPath;', false],
+    // --- 負例: 退避を持たない子入口 (これが G-9 で赤になる形) ---
+    ["<?php \$app = require 'bootstrap/app.php'; \$app->make(Kernel::class)->bootstrap();", false],
+]);
+
+test('G-7 走査器の見本検査: 共通の起動器への静的呼び出しを完全修飾名で判定する', function (
+    string $sample,
+    bool $expected,
+): void {
+    expect(phpBootProbeCallsBootProbeRunner('tests/Sample.php', $sample))->toBe($expected, $sample);
+})->with([
+    // --- 正例: 完全修飾名が起動器に解決される 3 形 ---
+    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::run([]);', true],
+    ['<?php Tests\Support\Process\BootProbeRunner::run([]);', true],
+    ['<?php \Tests\Support\Process\BootProbeRunner::run([]);', true],
+    // ★別名つき取り込みも**解決するので検出する** (短名一致では黙っていた形)。
+    ['<?php use Tests\Support\Process\BootProbeRunner as Runner; Runner::run([]);', true],
+    // --- 負例: 同名の別クラス (短名一致なら誤検出していた形) ---
+    ['<?php use Other\BootProbeRunner; BootProbeRunner::run([]);', false],
+    ['<?php Other\BootProbeRunner::run([]);', false],
+    // 取り込みが無い短名は「現在の名前空間の下」に解決されるので起動器ではない。
+    ['<?php BootProbeRunner::run([]);', false],
+    // --- 負例: 接頭辞つき・接尾辞つきのクラス名 / 接尾辞つきのメソッド名 ---
+    ['<?php use Tests\Support\Process\OtherBootProbeRunner; OtherBootProbeRunner::run([]);', false],
+    ['<?php use Tests\Support\Process\BootProbeRunnerX; BootProbeRunnerX::run([]);', false],
+    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::runner([]);', false],
+    // --- 負例: 呼び出しではない形 ---
+    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::RUN;', false],
+    ['<?php use Tests\Support\Process\BootProbeRunner;', false],
+    ['<?php // BootProbeRunner::run(', false],
+    ['<?php $s = "BootProbeRunner::run(";', false],
+    // --- 負例: 受け手が静的に確定できない形は**証拠に数えない** (存在を主張する検査のため) ---
+    ['<?php $runner = Tests\Support\Process\BootProbeRunner::class; $runner::run([]);', false],
+]);
diff --git a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
index 7002bdf6..2e3c955f 100644
--- a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
+++ b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
@@ -6,30 +6,84 @@
 
 use JsonException;
 use RuntimeException;
-use Symfony\Component\Process\Process;
+use Tests\Support\Process\BootProbeResult;
+use Tests\Support\Process\BootProbeRunner;
 
 /**
  * 観測用スクリプト (fake-wiring-probe.php) を子プロセスで走らせる。
  *
- * 子の環境は**完全に作り直す** (親から引き継がない)。決め方は 3 段:
- * 1. プロセスの環境変数は `env -i` で空にしてから、必要な分だけを渡す
- *    (親のシェルに残った TESTING_FAKE_* に結果を左右されない。
- *     bug-hunt のスクリプトが DB 資格情報を遮断するときと同じ手である)
- * 2. 設定の出所は**専用の一時環境ファイル 1 つだけ**にする
- *    (`FAKE_WIRING_PROBE_ENV_DIR` / `…_FILE` で子へ渡し、子が
- *     `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定する)。
- *     親のチェックアウトの `.env` / `.env.bughunt.local` は**読ませない**
- *     = 実 Stripe / 外部ログイン / S3 の資格情報は子の設定に 1 つも入らない
- * 3. 設定キャッシュを無効化する。`APP_CONFIG_CACHE` を**存在しない一時パス**へ向け、
- *    キャッシュ無しの起動として観測する (共有の bootstrap/cache を作ったり消したりしない =
- *    並列実行と衝突しない)
+ * ★**子の起こし方・回収・書き出し先の退避は共通の起動器**
+ *   (`Tests\Support\Process\BootProbeRunner`) が持つ
+ *   (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
+ *   本クラスに残るのは「観測用の環境ファイルを安全に用意すること」と
+ *   「子の出力を解釈すること」の 2 つだけである。
  *
- * ★**親の実鍵を複写しない**。`APP_KEY` / `CIPHERSWEET_KEY` は起動のたびに
- *   **使い捨ての値をその場で生成する** (観測は解決と経路の組み立てだけで、既存データの
- *   復号も DB 接続もしないため実鍵は要らない)。これで一時ファイルは秘密を 1 つも持たない。
- * ★それでも置き場所は保護する: 専用の一時ディレクトリを 0700 で作り、環境ファイルは
- *   作成時点から 0600 にする。起動前に権限を確かめ、0600 でなければ**子を起こさずに失敗させる**。
- *   後片付けは finally で行い、timeout・JSON の解釈失敗・Process の例外でも必ず通る。
+ * ## 1. 子の環境は 4 段で決まる
+ *
+ * 継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE`) →
+ * ケース別 (本クラスの `CASE_ENV_KEYS` の 3 件) → 予約 (書き出し先 7 キー。起動器が決める)。
+ * **統制点は `proc_open` へ渡す環境配列**である — 子はその配列だけを受け取るので、
+ * 開発者ローカルの env (`TESTING_FAKE_*` / DB 資格情報など) はここで締め出される。
+ * 後ろの段が前の段に勝つので、ケース別上書きは基底に勝つ。
+ *
+ * ## 2. 使い捨て鍵の置き場所は 2 つに分かれる
+ *
+ * `APP_KEY` は**ケース別上書き**、`CIPHERSWEET_KEY` は**環境ファイル**に置く。
+ * Laravel の環境変数リポジトリは **immutable** で、**プロセス環境に既に在る値を Dotenv は
+ * 上書きしない**ためである。起動器の基底が `APP_KEY` を載せる以上、環境ファイルへ書いた
+ * 使い捨て鍵は無視される (設計時に子プロセスで実測して確定した)。
+ * どちらの鍵も**親の実鍵を複写しない** — 起動のたびにその場で生成する
+ * (観測は解決と経路の組み立てだけで、既存データの復号も DB 接続もしないため実鍵は要らない)。
+ *
+ * ## 3. 一時ディレクトリが 2 つある
+ *
+ *  - **外側**: 本クラスが作る**環境ファイルの置き場**。0700 で作り、環境ファイルは 0600。
+ *    起動前に実効の権限を確かめ、違えば**子を起こさずに失敗させる**。
+ *    後片付けは `withEnvironmentDirectory()` の `finally` が行い、本体がどう終わっても通る
+ *  - **内側**: 起動器が作る**書き出し先の退避先**。子の storage / 設定キャッシュ等はここへ向く
+ *
+ * どちらも**リポジトリの外**であることを起動前に確かめる (正典 v1 (5) の fail-closed)。
+ * 境界の判定は `BootProbeRunner::isInside()` を使う (規則を 2 か所で持たない)。
+ *
+ * ## 4. 設定キャッシュの退避先は起動器の予約鍵である
+ *
+ * `APP_CONFIG_CACHE` ほか 7 キーは起動器が一時ディレクトリから導く**予約鍵**なので、
+ * 本クラスからは渡せない (渡すと `BootProbeRunner::run()` が例外にする)。
+ *
+ * ## 5. 取り込んだ `BootProbeRunner` の docblock の訂正 (向こうはバイト一致なので直せない)
+ *
+ * | 取り込んだ記述 | aicue での実際 |
+ * |---|---|
+ * | 「外部到達統制の subprocess 0 件 pin に触れる (AGENTS.md セキュリティ不変条件 **15**)」 | aicue の外部到達点の目録は **セキュリティ不変条件 9** である |
+ * | 「同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`」 | aicue では `tests/Support/GlobalUse/PhpLintOracle.php` (`Architecture/` が入らない) |
+ * | 「統制点は `proc_open` へ渡す環境配列だけ」 | **プロセス環境の統制点はそれで唯一だが、環境ファイル (`.env`) は別経路である** |
+ *
+ * **趣旨 (`tests/` 専用であり `app/` へ持ち出さない) は aicue でもそのまま成り立つ。**
+ *
+ * ### 呼び出し側の必須契約 (T249 の実測から。起動器の docblock には書かれていない)
+ *
+ * **Laravel を起こす子は、環境ファイルの置き場所を自分で退避しなければならない。**
+ * 起動器が締め出すのは*プロセス環境*だけで、`.env` の読み込みは止めない。子の作業ディレクトリは
+ * リポジトリ root なので、`bootstrap/app.php` を素で読むと Laravel は**リポジトリの `.env` を
+ * そのまま設定へ載せる** (実測: DB パスワードと実 `CIPHERSWEET_KEY` が子の設定に載った)。
+ * 退避の手段は 2 通りで、どちらでもよい:
+ *
+ *  - **専用の環境ファイルを読ませる** — 本クラスの経路 (`useEnvironmentPath()` +
+ *    `loadEnvironmentFrom()` を子入口が呼ぶ)
+ *  - **実在しない場所を指させる** — 起動器の自己検査 (S9 / S10) の経路
+ *    (一時ディレクトリを環境パスにすると `safeLoad()` は何も読まない)
+ *
+ * この契約の守り方は経路ごとに強さが違う。**一部の経路は字句の pin だけである**:
+ *
+ *  - 本クラスの経路 / 起動器の自己検査 (S9) — **実挙動で測る**
+ *    (`ExternalFakeBootProbeTest` の P-17 が読んだ環境ファイルの絶対パスを完全一致で、
+ *     S9 が同じことを起動器側で)
+ *  - 実プロセス並行テストの子入口 — **字句の pin だけ** (退避の呼び出しが在ることまで)。
+ *    別 feature の観測契約なので実測は足していない
+ *
+ * どの経路がどちらかの正本は
+ * `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` の軸 B の申告
+ * (`env_isolation`) であり、G-8 が分類を、G-9 が字句の裏打ちを固定する。
  *
  * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
  * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
@@ -37,31 +91,44 @@
  */
 final class FakeWiringProbeRunner
 {
+    /**
+     * 子が実働証明の印を書く先 (`storage_path()` からの相対パス)。
+     *
+     * ★正典 v1 (5) の実働証明の観測点。退避が効いていなければ印はリポジトリ側へ落ち、
+     *   起動器の `writtenRelativePaths` に現れない = P-13 が赤になる。
+     */
+    public const string MARKER_RELATIVE_PATH = 'app/private/fake-wiring-probe-marker.txt';
+
     /**
      * 一時環境ファイルに書いてよいキー (deny-by-default)。
-     * 実資格情報のキーは 1 つも無く、鍵の 2 つは使い捨ての生成値である。
+     * 実資格情報のキーは 1 つも無く、鍵は使い捨ての生成値である。
+     *
+     * ★`APP_KEY` は**ここに置けない**。Laravel の環境変数リポジトリは immutable で、
+     *   プロセス環境に既に在る値を Dotenv は上書きしない。BootProbeRunner の基底が
+     *   `APP_KEY` を載せる以上、ここへ書いても無視される (設計時に子プロセスで実測)。
+     *   使い捨て `APP_KEY` は CASE_ENV_KEYS 側 (ケース別上書き) が運ぶ。
      *
      * @var list<string>
      */
     public const array ALLOWED_ENV_FILE_KEYS = [
-        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
+        'APP_ENV', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
         'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
     ];
 
     /**
-     * 子プロセスへ渡してよい**プロセス環境変数**のキー (上とは別物なので定数を分ける)。
-     * `env -i` で空にしたうえでこの 3 つだけを載せる。
+     * BootProbeRunner へ渡す**ケース別上書き**のキー (正典 v1 (2) の第 3 段)。
      *
-     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
-     *   probe が自分で観測して返す。両方を突き合わせて初めて `env -i` の退行が映る。
+     * ★`TESTING_FAKE_*` はここに**無い**。偽物の宣言はプロセス環境へ 1 件も載せず、
+     *   0600 の環境ファイルの中だけに置く (P-7 の危険接頭辞の禁止をそのまま維持する)。
+     * ★`APP_CONFIG_CACHE` ほかの書き出し先は runner の**予約鍵**なので渡さない (渡すと例外)。
+     * ★この一覧は P-7 がリテラルで完全一致 pin する (増やすと赤になる)。
      *
      * @var list<string>
      */
-    public const array ALLOWED_PROCESS_ENV_KEYS = [
+    public const array CASE_ENV_KEYS = [
         'FAKE_WIRING_PROBE_ENV_DIR',
         'FAKE_WIRING_PROBE_ENV_FILE',
-        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
-        'APP_CONFIG_CACHE',
+        'APP_KEY',
     ];
 
     /** 観測に使う自ホストの URL (実サーバは立てない。経路の組み立てにだけ使う) */
@@ -70,19 +137,91 @@ final class FakeWiringProbeRunner
     /** 環境ファイルの名前 (一時ディレクトリ内で固定) */
     private const string ENV_FILE_NAME = '.env.probe';
 
+    /**
+     * 環境ファイルの置き場所を 0700 で用意し、**本体がどう終わっても必ず消す**足場。
+     *
+     * ★`run()` の `finally` をここへ切り出したのは、**後始末そのものを検査から直接呼べるように**
+     *   するためである (P-10c)。制限時間超過の経路は「`interpret()` が例外を投げる」(P-15) と
+     *   「本体が例外を投げれば中身ごと消える」(P-10c) の合成で覆う。
+     *   **プロセスの挙動を偽装する注入の継ぎ目ではない** — 起こし方も回収も BootProbeRunner のままである。
+     *
+     * ★**リポジトリの中には作らない** (正典 v1 (5) の fail-closed)。内側の退避先は
+     *   BootProbeRunner が同じ検査を持つが、外側 (この環境ファイルの置き場) にも同じ境界が要る。
+     *   判定は BootProbeRunner::isInside() を使う (境界規則を 2 か所で持たない)。
+     * ★権限は callback を呼ぶ**前に**実効値で確かめる。どの失敗でも作った置き場所を消してから投げる。
+     *
+     * @template T
+     *
+     * @param  callable(string): T  $body  引数は作った置き場所の絶対パス
+     * @return T
+     */
+    public static function withEnvironmentDirectory(?string $baseDirectory, callable $body): mixed
+    {
+        $base = $baseDirectory ?? sys_get_temp_dir();
+
+        // ★`Webmozart\Assert` を使わない — あちらは InvalidArgumentException を投げるので、
+        //   呼び出し側の例外契約が RuntimeException と 2 本立てになってしまう。
+        //   この境界は明示検査で RuntimeException に統一する。
+        if (! str_starts_with($base, DIRECTORY_SEPARATOR)) {
+            throw new RuntimeException("観測用の置き場所は絶対パスであること: {$base}");
+        }
+
+        if (! is_dir($base) || ! is_writable($base)) {
+            throw new RuntimeException("観測用の置き場所を使用できない: {$base}");
+        }
+
+        $created = rtrim($base, DIRECTORY_SEPARATOR).'/fake-wiring-probe-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($created, 0700) || ! is_dir($created)) {
+            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$created}");
+        }
+
+        try {
+            $directory = realpath($created);
+            if (! is_string($directory) || $directory === '') {
+                throw new RuntimeException("観測用の一時ディレクトリを正規化できない: {$created}");
+            }
+
+            // 正典 (5) の fail-closed。ここを緩めると環境ファイルがリポジトリへ落ちる。
+            // ★両辺とも realpath 済みで比べる (FakeClassCatalog::repoRoot() は dirname() の結果で
+            //   正規化されていないため、symlink 越しだと素の比較が取り違える)。
+            $repositoryRoot = realpath(FakeClassCatalog::repoRoot());
+            if (! is_string($repositoryRoot) || $repositoryRoot === '') {
+                throw new RuntimeException('リポジトリ root を正規化できない');
+            }
+
+            if (BootProbeRunner::isInside($repositoryRoot, $directory)) {
+                throw new RuntimeException(
+                    "観測用の一時ディレクトリがリポジトリ内にある: {$directory}"
+                );
+            }
+
+            // 実効の権限で確かめる (chmod の戻り値だけでは umask 等の影響を捕まえられない)。
+            if (! chmod($directory, 0700) || self::mode($directory) !== 0700) {
+                throw new RuntimeException("観測用の一時ディレクトリを 0700 にできない: {$directory}");
+            }
+
+            return $body($directory);
+        } finally {
+            self::removeDirectory($created);
+        }
+    }
+
     /**
      * 観測を 1 回走らせる。
      *
-     * @param  string|null  $baseDirectory  一時ディレクトリを作る親 (省略時は sys_get_temp_dir())
+     * @param  string|null  $baseDirectory  環境ファイルの置き場を作る親 (省略時は sys_get_temp_dir())
+     * @param  positive-int  $timeoutSeconds
      * @return array{
      *     exitCode: int,
      *     output: array<string, mixed>,
      *     envFileValues: array<string, string>,
+     *     caseEnvValues: array<string, string>,
      *     directory: string,
      *     directoryMode: int,
      *     envFileMode: int,
-     *     configCachePath: string,
-     *     configCacheExists: bool,
+     *     temporaryRoot: string,
+     *     writtenRelativePaths: list<string>,
      * }
      */
     public static function run(
@@ -91,59 +230,108 @@ public static function run(
         bool $fakeStorage,
         bool $fakeLlm,
         ?string $baseDirectory = null,
-        float $timeout = 120.0,
+        int $timeoutSeconds = 120,
     ): array {
-        $base = $baseDirectory ?? sys_get_temp_dir();
-        $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));
+        // 置き場所の作成・リポジトリ外の fail-closed・0700 の確認・後片付けは helper が持つ。
+        return self::withEnvironmentDirectory(
+            $baseDirectory,
+            static function (string $directory) use ($environment, $fakeExternals, $fakeStorage, $fakeLlm, $timeoutSeconds): array {
+                $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
+                $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
+                self::writeEnvFile($envFilePath, $values);
+
+                $directoryMode = self::mode($directory);
+                $envFileMode = self::mode($envFilePath);
+
+                // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
+                self::assertSafePermissions($directoryMode, $envFileMode);
+
+                $caseEnv = self::caseEnvValues($directory);
+
+                // 子の起こし方・回収・書き出し先の退避は共通 runner が持つ
+                // (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
+                $result = BootProbeRunner::run([self::probeScriptPath()], $caseEnv, $timeoutSeconds);
+
+                return self::interpret($result, $values, $caseEnv, $directory, $directoryMode, $envFileMode);
+            },
+        );
+    }
 
-        if (! mkdir($directory, 0700) || ! is_dir($directory)) {
-            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$directory}");
+    /**
+     * ケース別上書きの中身 (使い捨て鍵はここで作る)。
+     *
+     * @return array<string, string>
+     */
+    public static function caseEnvValues(string $directory): array
+    {
+        $values = [
+            'FAKE_WIRING_PROBE_ENV_DIR' => $directory,
+            'FAKE_WIRING_PROBE_ENV_FILE' => self::ENV_FILE_NAME,
+            // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
+            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
+        ];
+
+        foreach (array_keys($values) as $key) {
+            if (! in_array($key, self::CASE_ENV_KEYS, true)) {
+                throw new RuntimeException("ケース別上書きに置けないキー: {$key}");
+            }
         }
 
-        try {
-            chmod($directory, 0700);
-
-            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
-            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
-            self::writeEnvFile($envFilePath, $values);
-
-            $directoryMode = self::mode($directory);
-            $envFileMode = self::mode($envFilePath);
-
-            // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
-            self::assertSafePermissions($directoryMode, $envFileMode);
-
-            $configCachePath = $directory.'/config-cache-absent.php';
-
-            $process = new Process(
-                [
-                    'env', '-i',
-                    'FAKE_WIRING_PROBE_ENV_DIR='.$directory,
-                    'FAKE_WIRING_PROBE_ENV_FILE='.self::ENV_FILE_NAME,
-                    'APP_CONFIG_CACHE='.$configCachePath,
-                    PHP_BINARY,
-                    self::probeScriptPath(),
-                ],
-                FakeClassCatalog::repoRoot(),
-                null,
-                null,
-                $timeout,
+        return $values;
+    }
+
+    /**
+     * runner の結果を観測結果へ翻訳する (**純関数**。子を起こさずに負例を測れる)。
+     *
+     * ★fail-closed を 4 つ持つ:
+     *   1. 制限時間超過 (`timedOut`) は**通常の非ゼロ終了と区別して例外**にする。
+     *      false や非ゼロ終了へ落とすと「観測できなかった」ことが沈黙する (fail-open)
+     *   2. 出力が空 → 例外 (観測が成立していない)
+     *   3. JSON として読めない → 例外
+     *   4. トップレベルが配列でない → 例外
+     * ★判定には `timedOut` を使い、`exitCode === 124` を直接読まない
+     *   (終了要求を受けてから自分で `exit(0)` する子は `timedOut` かつ `exitCode === 0` になりうる)。
+     *
+     * @param  array<string, string>  $envFileValues
+     * @param  array<string, string>  $caseEnv
+     * @return array{
+     *     exitCode: int,
+     *     output: array<string, mixed>,
+     *     envFileValues: array<string, string>,
+     *     caseEnvValues: array<string, string>,
+     *     directory: string,
+     *     directoryMode: int,
+     *     envFileMode: int,
+     *     temporaryRoot: string,
+     *     writtenRelativePaths: list<string>,
+     * }
+     */
+    public static function interpret(
+        BootProbeResult $result,
+        array $envFileValues,
+        array $caseEnv,
+        string $directory,
+        int $directoryMode,
+        int $envFileMode,
+    ): array {
+        if ($result->timedOut) {
+            throw new RuntimeException(
+                '観測用の子プロセスが制限時間を超えて強制終了された (観測が成立していない)。'
+                ."終了コード: {$result->exitCode} / 標準エラー: ".$result->stderr
             );
-            $process->run();
-
-            return [
-                'exitCode' => $process->getExitCode() ?? -1,
-                'output' => self::decode($process->getOutput()),
-                'envFileValues' => $values,
-                'directory' => $directory,
-                'directoryMode' => $directoryMode,
-                'envFileMode' => $envFileMode,
-                'configCachePath' => $configCachePath,
-                'configCacheExists' => file_exists($configCachePath),
-            ];
-        } finally {
-            self::removeDirectory($directory);
         }
+
+        return [
+            'exitCode' => $result->exitCode,
+            'output' => self::decode($result->stdout),
+            'envFileValues' => $envFileValues,
+            'caseEnvValues' => $caseEnv,
+            'directory' => $directory,
+            'directoryMode' => $directoryMode,
+            'envFileMode' => $envFileMode,
+            'temporaryRoot' => $result->temporaryRoot,
+            'writtenRelativePaths' => $result->writtenRelativePaths,
+        ];
     }
 
     /**
@@ -161,7 +349,6 @@ public static function envFileValues(
         // 形式は現行の設定が受理する形に合わせる (妥当性は「子が起動できたこと」自体が示す)。
         $values = [
             'APP_ENV' => $environment,
-            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
             'APP_URL' => self::PROBE_APP_URL,
             'APP_DEBUG' => 'false',
             'CIPHERSWEET_KEY' => bin2hex(random_bytes(32)),
diff --git a/tests/Support/ExternalFakes/fake-wiring-probe.php b/tests/Support/ExternalFakes/fake-wiring-probe.php
index 8c18778b..25530f21 100644
--- a/tests/Support/ExternalFakes/fake-wiring-probe.php
+++ b/tests/Support/ExternalFakes/fake-wiring-probe.php
@@ -6,14 +6,23 @@
 use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use Illuminate\Contracts\Console\Kernel;
 use Illuminate\Foundation\Application;
+use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
 use Webmozart\Assert\Assert;
 
 /*
  * 別プロセスで「宣言した差し替えが実際に効いているか」を観測して JSON を書き出す。
  *
- * ★責務は 4 つだけ: DB へ接続しない / container から解決する /
- *   転送先 URL を組み立てて読む / 終了コードを返す。
- *   HTTP サーバもブラウザも起動しない。
+ * ★責務は 7 つだけ:
+ *   1. DB へ接続しない
+ *   2. container から解決する
+ *   3. 転送先 URL を組み立てて読む (**偽物が有効なときだけ**)
+ *   4. **実働証明の印を storage_path() 経由で 1 本書く** (正典 v1 (5))
+ *   5. **起動しきったアプリが解決した書き出し先 8 種と、効いた鍵 2 種の digest を報告する**
+ *   6. **実際に読んだ環境ファイルの絶対パスを報告する** (P-17。専用ファイルへの固定が効いた証拠)
+ *   7. 終了コードを返す
+ * ★**観測しないもの**: HTTP サーバもブラウザも起動しない /
+ *   設定キャッシュ**有り**の起動は観測しない / 外部へ 1 度も通信しない
+ *   (転送先は組み立てて URL を読むだけ)。
  * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md §禁止する文)。
  * ★読み込む環境ファイルを**専用の一時ファイルだけ**に固定する (親のチェックアウトの
  *   .env / .env.bughunt.local を読ませない = 実資格情報が子の設定へ入らない)。
@@ -45,6 +54,19 @@
 
     $app->make(Kernel::class)->bootstrap();
 
+    /*
+     * ★正典 v1 (5) の**実働証明**の観測点 (lctl feature: subprocess-boot-probe-harness)。
+     *   「書き出し先を環境変数で退避した」ことは、退避が**効いていなければ**既定の場所
+     *   (リポジトリの storage/) へ書かれ、観測は緑のまま嘘になる。そこで
+     *   Laravel の storage_path() 経由で印を 1 本置き、それが起動器の一時ディレクトリ配下に
+     *   現れたことを呼び出し側 (P-13) が確かめる。
+     *   置き場所 (storage/app/private) は起動器が事前に掘っている。
+     */
+    $markerPath = $app->storagePath(FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
+    if (file_put_contents($markerPath, 'fake-wiring-probe') === false) {
+        throw new RuntimeException("観測の印を書けない: {$markerPath}");
+    }
+
     $resolved = [];
     foreach (ExternalFakeDeclaration::swaps() as $swap) {
         $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
@@ -71,6 +93,27 @@
         'resolved' => $resolved,
         'redirect_host' => $redirectHost,
         'process_environment_keys' => $processEnvironmentKeys,
+        // ★P-17 (環境ファイルの隔離): 起動しきったアプリが**実際に読んだ**環境ファイルの
+        //   絶対パス。呼び出し側が「起動側が用意した専用ファイルと完全一致する」ことを確かめる
+        //   (= リポジトリの .env を読んでいない、を実挙動で示す唯一の観測点)。
+        'env_file_path' => $app->environmentFilePath(),
+        // ★P-14 (向き): 起動しきったアプリが解決した書き出し先。呼び出し側が
+        //   「1 件残らず一時ディレクトリ配下で、リポジトリの外」であることを確かめる。
+        'write_targets' => [
+            'storage' => $app->storagePath(),
+            'config_cache' => $app->getCachedConfigPath(),
+            'routes_cache' => $app->getCachedRoutesPath(),
+            'services_cache' => $app->getCachedServicesPath(),
+            'packages_cache' => $app->getCachedPackagesPath(),
+            'events_cache' => $app->getCachedEventsPath(),
+            'view_compiled' => (string) config('view.compiled'),
+            'log_path' => (string) config('logging.channels.single.path'),
+        ],
+        // ★P-8 (使い捨て鍵が子で効いたこと)。鍵そのものは出力しない (テスト出力へ鍵を流さない)。
+        'key_digests' => [
+            'app' => hash('sha256', (string) config('app.key')),
+            'ciphersweet' => hash('sha256', (string) config('ciphersweet.providers.string.key')),
+        ],
     ], JSON_THROW_ON_ERROR));
 
     exit(0);
diff --git a/tests/Support/Process/BootProbeResult.php b/tests/Support/Process/BootProbeResult.php
new file mode 100644
index 00000000..c0af2ec0
--- /dev/null
+++ b/tests/Support/Process/BootProbeResult.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Process;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * probe 1 回分の観測結果 (一時ディレクトリを消す前に採取したスナップショットを含む)。
+ *
+ * `Tests\Support\Process\BootProbeRunner` が唯一の生成者である (lctl feature:
+ * subprocess-boot-probe-harness)。
+ */
+final readonly class BootProbeResult
+{
+    /**
+     * @param  non-negative-int  $exitCode  強制終了なら BootProbeRunner::TIMEOUT_EXIT_CODE
+     * @param  non-empty-string  $temporaryRoot  実行に使った一時ディレクトリ (実行後は消えている)
+     * @param  list<non-empty-string>  $writtenRelativePaths  一時ディレクトリ配下に書かれたもの (昇順)
+     * @param  positive-int  $pid  回収した子の pid。**回収済みの死骸の番号**であり操作対象ではない
+     *                             (自己検査が「子が残っていない」ことを確かめるためだけに持つ)
+     */
+    public function __construct(
+        public string $stdout,
+        public string $stderr,
+        public int $exitCode,
+        public bool $timedOut,
+        public float $elapsedSeconds,
+        public string $temporaryRoot,
+        public array $writtenRelativePaths,
+        public int $pid,
+    ) {
+        Assert::natural($exitCode);
+        Assert::true(
+            is_finite($elapsedSeconds) && $elapsedSeconds >= 0.0,
+            '所要時間が有限の非負値でない',
+        );
+        Assert::stringNotEmpty($temporaryRoot);
+        Assert::allStringNotEmpty($writtenRelativePaths);
+        Assert::positiveInteger($pid);
+    }
+}
diff --git a/tests/Support/Process/BootProbeRunner.php b/tests/Support/Process/BootProbeRunner.php
new file mode 100644
index 00000000..df4b1e56
--- /dev/null
+++ b/tests/Support/Process/BootProbeRunner.php
@@ -0,0 +1,656 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Process;
+
+use FilesystemIterator;
+use RecursiveDirectoryIterator;
+use RecursiveIteratorIterator;
+use RuntimeException;
+use SplFileInfo;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 起動順序を子プロセスで実測するための probe 起動器 (lctl feature: subprocess-boot-probe-harness)。
+ *
+ * アプリの壊れ方には「どの順番で組み立てられたか」に由来するものがあり、テストが走る時点で
+ * そのプロセスの起動は終わっているため同じプロセスの中では再現できない。ここは
+ * **小さな子プロセスを 1 つ起こして観測結果を回収する**、その起こし方と後始末だけを持つ。
+ * 何を観測するかは呼び出し側 (gate) の責務である。
+ *
+ * ## 正典 v1 の 6 要素と本実装
+ *
+ *  1. 親と同じ実行体で起こす — `PHP_BINARY` を先頭に固定し、`$phpArguments` はその後ろに置く
+ *  2. 環境変数は 3 段 — 継承 (許可一覧) → 基底 → ケース別上書き。子は `proc_open` に渡した
+ *     配列だけを受け取るので、ここが開発者ローカルの env を締め出す唯一の統制点になる
+ *  3. 出力は非ブロッキングで逐次読み、制限時間を超えたら SIGTERM → 猶予 → SIGKILL で落とし、
+ *     全ての管を閉じてから必ず `proc_close` する
+ *  4. 終了コードは実行中フラグが初めて false になった時点の非負値を保持し、`proc_close` の
+ *     戻り値で上書きしない。強制終了で取れなければ 124 へ正規化する
+ *  5. 子の書き出し先は**リポジトリ外の一時ディレクトリ**へ逃がす (下記 RESERVED_ENV_KEYS)。
+ *     一時ディレクトリがリポジトリ内になったら子を起こす前に例外にする (fail-closed)
+ *  6. 自己検査を持つ — `tests/Unit/Support/Process/BootProbeRunnerTest.php`
+ *
+ * ## 正典 v1 との差分 (1 点だけ)
+ *
+ * 書き出し先の 7 キー (RESERVED_ENV_KEYS) は runner が作った一時ディレクトリから導く
+ * **予約鍵**であり、呼び出し側から渡せない (渡したら例外)。黙って無視すると結果の
+ * `temporaryRoot` / `writtenRelativePaths` が嘘になり、正典 (5) の保証が空洞化するためである。
+ * 環境変数の**順序**は正典と同じで、ケース別上書きが最後に効く。
+ * 「固定鍵を呼び出し側より後ろに置いて上書き不能にする」テンプレート固有の作法は、その理由を
+ * 持つ呼び出し側 (`tests/Architecture/BughuntFakeWiringTest.php`) が `array_merge($env, [...])`
+ * で表現する (runner へ持ち上げると、逆の契約を持つ検査 — 呼び出し側が `APP_KEY` を 2 通り
+ * 与えて観測差を測る `BugHuntInventoryCheckInvariantTest` の CT-3 — が載らなくなる)。
+ *
+ * ## 保証しないこと
+ *
+ *  - **孫プロセスは回収しない**。`proc_terminate()` が届くのは直接の子だけである
+ *    (probe が孫を起こさないことは probe 側の前提)
+ *  - **子が書く先を全部押さえること**は保証しない。退避できるのは Laravel が環境変数で受ける
+ *    既知の書き出し先までで、独自パスへの書き込みは閉じない
+ *  - **子が外部へ通信しないこと**は本クラスの主張ではない (probe の中身の責務)
+ *  - **Unix 系 (Linux / macOS) 前提**である。段階的な強制終了は POSIX のシグナル意味論に依存する
+ *  - **回収不能だった場合の振る舞いは実測していない**。子を落とせなかったときは一時ディレクトリを
+ *    消さずに場所を例外へ書いて残す (生きている子の足元を壊さないため) が、この分岐は
+ *    `SIGKILL` を無視できない以上作り出せないので自己検査で覆えていない
+ *
+ * **`tests/` 専用**である。`app` / `routes` / `config` / `database` / `bootstrap` へ持ち出すと
+ * 外部到達統制の subprocess 0 件 pin に触れる (AGENTS.md セキュリティ不変条件 15)。
+ * 同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`。
+ */
+final class BootProbeRunner
+{
+    /** 強制終了で終了コードが取れなかったときの正規化値 (GNU timeout(1) と同じ)。 */
+    public const int TIMEOUT_EXIT_CODE = 124;
+
+    /** 既定の制限時間 (秒)。実測では probe 1 本が 1 秒前後で終わる。 */
+    public const int DEFAULT_TIMEOUT_SECONDS = 60;
+
+    /** 終了要求から強制終了までの猶予 (秒)。 */
+    public const int TERMINATION_GRACE_SECONDS = 2;
+
+    /** 子の終了を検知してから管を読み切るまでの上限 (秒。孫が管を持っていても回収を止めない)。 */
+    public const int FINAL_DRAIN_SECONDS = 2;
+
+    /** 強制終了を送ってから諦めるまでの最終期限 (秒)。超えたら例外にする。 */
+    public const int KILL_WAIT_SECONDS = 5;
+
+    /**
+     * 親から継承する環境変数 (文字列かつ非空のときだけ継承する。既定値へ差し替えない)。
+     *
+     *  - `PATH`: 子が外部コマンドを解決するため (`PHP_BINARY` は絶対パスなので必須ではない)
+     *  - `HOME`: composer / vendor が HOME 依存の場所を引く
+     *  - `TMPDIR`: 子自身が一時ファイルを作るときの置き場所
+     *
+     * `LC_*` / `TZ` / `LANG` は継承しない (入力集合を広げる。時間帯は `config/app.php` が決める)。
+     *
+     * @var list<non-empty-string>
+     */
+    public const array INHERITED_ENV_KEYS = ['PATH', 'HOME', 'TMPDIR'];
+
+    /**
+     * runner が予約する環境変数 (書き出し先)。呼び出し側が渡したら例外にする。
+     *
+     * @var list<non-empty-string>
+     */
+    public const array RESERVED_ENV_KEYS = [
+        'LARAVEL_STORAGE_PATH',
+        'VIEW_COMPILED_PATH',
+        'APP_CONFIG_CACHE',
+        'APP_ROUTES_CACHE',
+        'APP_SERVICES_CACHE',
+        'APP_PACKAGES_CACHE',
+        'APP_EVENTS_CACHE',
+    ];
+
+    /** ext-pcntl に依存しないためシグナル番号を直接持つ。 */
+    private const int SIGNAL_TERMINATE = 15;
+
+    private const int SIGNAL_KILL = 9;
+
+    /** 出力を 1 回に読む上限 (バイト)。パイプバッファ (64KiB 程度) に合わせる。 */
+    private const int READ_CHUNK_BYTES = 65536;
+
+    /** 読む管が 1 本も無いときに眠る時間 (マイクロ秒)。回転で CPU を焼かないための休符。 */
+    private const int IDLE_SLEEP_MICROSECONDS = 20000;
+
+    /** 出力を待つ 1 回の上限 (マイクロ秒)。 */
+    private const int SELECT_WAIT_MICROSECONDS = 50000;
+
+    /** 基底の暗号鍵の種 (値そのものは観測に影響しない。CI の素の `.env` が空鍵であることへの備え)。 */
+    private const string BASE_APP_KEY_SEED = 'laravel-claude-template:boot-probe';
+
+    /**
+     * probe を 1 本起こして結果を回収する。
+     *
+     * @param  list<non-empty-string>  $phpArguments  `PHP_BINARY` の後ろに置く引数
+     *                                                (`['-r', $code]` / `[$scriptPath]`)
+     * @param  array<non-empty-string, string>  $env  ケース別上書き (基底より後に効く)
+     * @param  positive-int  $timeoutSeconds
+     * @param  ?non-empty-string  $temporaryBase  一時ディレクトリの置き場所。既定は
+     *                                            `sys_get_temp_dir()`。**退避を無効化する口ではない**
+     *                                            (リポジトリ配下を渡すと例外になる。自己検査が
+     *                                            その fail-closed を確かめるための場所指定である)
+     */
+    public static function run(
+        array $phpArguments,
+        array $env = [],
+        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
+        ?string $temporaryBase = null,
+    ): BootProbeResult {
+        Assert::notEmpty($phpArguments, 'probe の引数が空である');
+        Assert::allStringNotEmpty($phpArguments);
+        Assert::allStringNotEmpty(array_keys($env));
+        Assert::allString($env);
+        Assert::positiveInteger($timeoutSeconds);
+
+        $reserved = array_values(array_intersect(self::RESERVED_ENV_KEYS, array_keys($env)));
+        if ($reserved !== []) {
+            throw new RuntimeException(
+                '書き出し先は runner が決める (呼び出し側から渡せない): '.implode(', ', $reserved),
+            );
+        }
+
+        $repositoryRoot = self::repositoryRoot();
+        $temporaryRoot = self::createTemporaryRoot($temporaryBase ?? sys_get_temp_dir(), $repositoryRoot);
+
+        // 「消してよいか」= 子が生存し得ないか。子がいないうちは消してよい (残骸を残さない)。
+        // 遷移: 一時ディレクトリ作成直後 = true → `proc_open` 成功直後 = false
+        //       → 回収成功後 = true / 回収不能 = false のまま
+        $safeToRemove = true;
+
+        try {
+            $result = self::spawn(
+                $phpArguments,
+                self::composeEnv($env, $temporaryRoot),
+                $repositoryRoot,
+                $temporaryRoot,
+                $timeoutSeconds,
+                $safeToRemove,
+            );
+        } catch (Throwable $failure) {
+            // 生きているかもしれない子の足元は消さない (残った場所は spawn() が投げる例外に書く)。
+            if ($safeToRemove) {
+                try {
+                    self::removeDirectory($temporaryRoot);
+                } catch (Throwable $removalFailure) {
+                    // 後片付けの失敗で**本来の例外を捨てない** (previous に残す)
+                    throw new RuntimeException(
+                        '一時ディレクトリを消せなかった: '.$temporaryRoot
+                        .' / 削除の失敗: '.$removalFailure->getMessage(),
+                        0,
+                        $failure,
+                    );
+                }
+            }
+
+            throw $failure;
+        }
+
+        self::removeDirectory($temporaryRoot);   // 正常経路。削除の失敗は例外のまま伝播させる
+
+        return $result;
+    }
+
+    /**
+     * `$candidate` が `$root` の配下か。
+     *
+     * 素の前方一致だと `/repo` が `/repository` を配下と誤判定するので、区切り文字を境界にする。
+     * 自己検査が境界の振る舞いを直接 pin できるよう公開する。
+     *
+     * **両引数とも `realpath` 済みの絶対パス**であること (相対パスや `..` を含む形は受け付けない。
+     * 正規化は呼び出し側の責務であり、ここでは絶対パスであることだけを `Assert` で確かめる)。
+     */
+    public static function isInside(string $root, string $candidate): bool
+    {
+        Assert::startsWith($root, DIRECTORY_SEPARATOR);
+        Assert::startsWith($candidate, DIRECTORY_SEPARATOR);
+
+        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);
+
+        return $candidate === $normalizedRoot
+            || str_starts_with($candidate, $normalizedRoot.DIRECTORY_SEPARATOR);
+    }
+
+    /**
+     * 基底 (呼び出し側が上書きできる hermetic な既定)。**この 3 本しか置かない**。
+     *
+     *  - `APP_KEY`: CI の素の `.env` は `APP_KEY` が空で、encrypter を引いた瞬間に
+     *    `MissingAppKeyException` で死ぬ (ローカル緑 / CI 赤の実測退行)。観測値は鍵に依存しない
+     *  - `QUEUE_CONNECTION`: 開発機の `.env` が `redis` だと観測が変わる
+     *  - `CACHE_STORE`: 1 プロセスで完結させ、DB / redis を張らせない
+     *
+     * **`APP_ENV` は置かない**。「渡さない実行では素の `.env` を読む」という観測が
+     * 呼び出し側 (`BughuntFakeWiringTest`) の複数ケースの前提になっているためである。
+     * ロケール系 (`LANG` / `LC_*`) も置かない (誰も依存せず、置くほど入力集合が広がる)。
+     *
+     * @return array<non-empty-string, string>
+     */
+    private static function baseEnv(): array
+    {
+        return [
+            'APP_KEY' => 'base64:'.base64_encode(hash('sha256', self::BASE_APP_KEY_SEED, true)),
+            'QUEUE_CONNECTION' => 'database',
+            'CACHE_STORE' => 'array',
+        ];
+    }
+
+    /** リポジトリ root (このファイルは `tests/Support/Process/` に居る)。 */
+    private static function repositoryRoot(): string
+    {
+        $root = realpath(dirname(__DIR__, 3));
+
+        if (! is_string($root)) {
+            throw new RuntimeException('リポジトリ root を解決できなかった');
+        }
+
+        return $root;
+    }
+
+    /**
+     * 一時ディレクトリを作り、リポジトリ外であることを確かめて子が書く下位を掘る。
+     *
+     * 途中のどの失敗でも作った root を消してから元の例外を投げ直す (作りかけを残さない)。
+     *
+     * @return non-empty-string
+     */
+    private static function createTemporaryRoot(string $base, string $repositoryRoot): string
+    {
+        Assert::startsWith($base, DIRECTORY_SEPARATOR, '一時ディレクトリの置き場所は絶対パスであること');
+        Assert::directory($base);
+        Assert::writable($base);
+
+        $created = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'boot-probe-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($created, 0o700, true)) {
+            throw new RuntimeException('一時ディレクトリを作れなかった: '.$created);
+        }
+
+        try {
+            $temporaryRoot = realpath($created);
+
+            if (! is_string($temporaryRoot) || $temporaryRoot === '') {
+                throw new RuntimeException('一時ディレクトリを正規化できなかった: '.$created);
+            }
+
+            if (self::isInside($repositoryRoot, $temporaryRoot)) {
+                // 正典 (5) の fail-closed。ここを緩めると probe の書き出しがリポジトリへ落ちる。
+                throw new RuntimeException(
+                    'probe の一時ディレクトリがリポジトリ内にある (書き出し先を退避できない): '.$temporaryRoot,
+                );
+            }
+
+            foreach ([
+                'storage/framework/views',
+                'storage/framework/cache/data',
+                'storage/framework/sessions',
+                'storage/logs',
+                'storage/app/private',
+                'bootstrap-cache',
+            ] as $relative) {
+                $directory = $temporaryRoot.DIRECTORY_SEPARATOR.$relative;
+                if (! mkdir($directory, 0o700, true)) {
+                    throw new RuntimeException('一時ディレクトリの下位を作れなかった: '.$directory);
+                }
+            }
+
+            return $temporaryRoot;
+        } catch (Throwable $failure) {
+            self::removeDirectory($created);
+
+            throw $failure;
+        }
+    }
+
+    /**
+     * 環境変数の 3 段合成 + 予約鍵 (正典 v1 の (2) と (5))。
+     *
+     * @param  array<non-empty-string, string>  $caseEnv
+     * @return array<non-empty-string, string>
+     */
+    private static function composeEnv(array $caseEnv, string $temporaryRoot): array
+    {
+        $inherited = [];
+        foreach (self::INHERITED_ENV_KEYS as $key) {
+            $value = getenv($key);
+            if (is_string($value) && $value !== '') {
+                $inherited[$key] = $value;
+            }
+        }
+
+        $storage = $temporaryRoot.'/storage';
+        $bootstrapCache = $temporaryRoot.'/bootstrap-cache';
+
+        $reserved = [
+            'LARAVEL_STORAGE_PATH' => $storage,
+            'VIEW_COMPILED_PATH' => $storage.'/framework/views',
+            'APP_CONFIG_CACHE' => $bootstrapCache.'/config.php',
+            'APP_ROUTES_CACHE' => $bootstrapCache.'/routes-v7.php',
+            'APP_SERVICES_CACHE' => $bootstrapCache.'/services.php',
+            'APP_PACKAGES_CACHE' => $bootstrapCache.'/packages.php',
+            'APP_EVENTS_CACHE' => $bootstrapCache.'/events.php',
+        ];
+
+        // 予約鍵の宣言 (公開定数) と実体が食い違ったら、S4 の pin も run() の拒否も嘘になる。
+        Assert::same(array_keys($reserved), self::RESERVED_ENV_KEYS, '予約鍵の宣言と実体が食い違っている');
+
+        return array_merge($inherited, self::baseEnv(), $caseEnv, $reserved);
+    }
+
+    /**
+     * 子を起こし、逐次読み・制限時間・回収まで面倒を見る。
+     *
+     * @param  list<non-empty-string>  $phpArguments
+     * @param  array<non-empty-string, string>  $env
+     * @param  non-empty-string  $temporaryRoot
+     * @param  positive-int  $timeoutSeconds
+     */
+    private static function spawn(
+        array $phpArguments,
+        array $env,
+        string $repositoryRoot,
+        string $temporaryRoot,
+        int $timeoutSeconds,
+        bool &$safeToRemove,
+    ): BootProbeResult {
+        $startedAt = microtime(true);
+
+        // 標準入力は /dev/null に向ける。probe が誤って読んでも即 EOF になり、止まる面が 1 つ減る
+        // (管にすると読み手が現れたときに待ち続ける)。
+        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
+
+        $process = proc_open([PHP_BINARY, ...$phpArguments], $descriptors, $pipes, $repositoryRoot, $env);
+
+        if (! is_resource($process)) {
+            throw new RuntimeException('probe の子プロセスを起動できなかった: '.implode(' ', $phpArguments));
+        }
+
+        // ここから先は子が生存しうる。回収できるまで一時ディレクトリを消さない。
+        $safeToRemove = false;
+
+        // 回収の状態は `try` の**前**に置く (`try` 内のどの例外点からも catch が回収を試みられるように)。
+        $state = ['processClosed' => false, 'closeCode' => null];
+
+        try {
+            // pid の取得も回収対象の `try` の中に置く (ここで落ちても子・管・一時ディレクトリを
+            // 一体で回収する = 「proc_open 成功後はどの例外点からも一体で回収する」)。
+            $pid = proc_get_status($process)['pid'];
+
+            foreach ([1, 2] as $descriptor) {
+                $pipe = $pipes[$descriptor] ?? null;
+                if (! is_resource($pipe)) {
+                    throw new RuntimeException('probe の出力管を開けなかった');
+                }
+                if (! stream_set_blocking($pipe, false)) {
+                    throw new RuntimeException('probe の出力を非ブロッキングにできなかった');
+                }
+            }
+
+            $output = [1 => '', 2 => ''];
+            $exitCode = null;          // 実行中フラグが初めて false になった時点の非負値
+            $timedOut = false;
+            $deadline = $startedAt + $timeoutSeconds;
+            $killAt = null;            // 強制終了を送る時刻 (未設定は null)
+            $giveUpAt = null;          // 落とせないと諦める時刻 ($killAt と同時に必ず入る)
+
+            while (true) {
+                self::readAvailable($pipes, $output);   // 詰まらせない (パイプバッファは 64KiB 程度)
+
+                $status = proc_get_status($process);
+                if (! $status['running']) {
+                    if ($exitCode === null && $status['exitcode'] >= 0) {
+                        $exitCode = $status['exitcode'];   // ここで確定させ、以後は上書きしない
+                    }
+                    break;
+                }
+
+                $now = microtime(true);
+
+                // 最終期限は**再送の時刻とは独立**に見る (再送のたびに $killAt を先送りするので、
+                // 期限の確認を再送分岐の中に置くと $giveUpAt を猶予ぶん超過できてしまう)。
+                if ($giveUpAt !== null && $now >= $giveUpAt) {
+                    throw new RuntimeException('probe の子プロセスを強制終了できなかった');
+                }
+
+                if ($killAt === null && $now >= $deadline) {
+                    $timedOut = true;
+                    if (! proc_terminate($process, self::SIGNAL_TERMINATE)) {
+                        throw new RuntimeException('probe の子プロセスへ終了要求を送れなかった');
+                    }
+                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
+                    $giveUpAt = $killAt + self::KILL_WAIT_SECONDS;
+                } elseif ($killAt !== null && $now >= $killAt) {
+                    // 送信失敗でも即座には諦めない (最終期限 $giveUpAt が唯一の打ち切り点)。
+                    proc_terminate($process, self::SIGNAL_KILL);
+                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
+                }
+            }
+
+            // 終了検知後の最終読み取り (上限つき)。孫が管を持ったままでも回収を止めない。
+            $drainUntil = microtime(true) + self::FINAL_DRAIN_SECONDS;
+            while (microtime(true) < $drainUntil && self::hasReadablePipe($pipes)) {
+                self::readAvailable($pipes, $output);
+            }
+
+            $closed = self::reclaim($process, $pipes, $state);
+            $safeToRemove = true;
+
+            if ($exitCode === null) {
+                // シグナルで落ちた子は exitcode が -1 になる → 124 へ正規化する
+                $exitCode = $timedOut
+                    ? self::TIMEOUT_EXIT_CODE
+                    : ($closed >= 0 ? $closed : throw new RuntimeException('probe の終了コードを回収できなかった'));
+            }
+
+            return new BootProbeResult(
+                stdout: $output[1],
+                stderr: $output[2],
+                exitCode: $exitCode,
+                timedOut: $timedOut,
+                elapsedSeconds: microtime(true) - $startedAt,
+                temporaryRoot: $temporaryRoot,
+                writtenRelativePaths: self::collectWritten($temporaryRoot),   // 消す前に採取する
+                pid: $pid,
+            );
+        } catch (Throwable $failure) {
+            // 本来の例外を優先しつつ、回収は最後まで試みる。
+            try {
+                self::reclaim($process, $pipes, $state);   // 2 回目以降は保持値を返すだけ
+                $safeToRemove = true;
+            } catch (Throwable $cleanupFailure) {
+                // **回収できなかった** — 一時ディレクトリは残す (場所を例外に書く)
+                throw new RuntimeException(
+                    'probe の子を回収できなかったため一時ディレクトリを残した: '.$temporaryRoot
+                    .' / 回収の失敗: '.$cleanupFailure->getMessage(),
+                    0,
+                    $failure,
+                );
+            }
+
+            throw $failure;
+        }
+    }
+
+    /**
+     * 読める管が 1 本でも残っているか (EOF 済みは数えない)。
+     *
+     * @param  array<int, resource>  $pipes
+     */
+    private static function hasReadablePipe(array $pipes): bool
+    {
+        foreach ([1, 2] as $descriptor) {
+            $pipe = $pipes[$descriptor] ?? null;
+            if (is_resource($pipe) && ! feof($pipe)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 読めるだけ読む (非ブロッキング)。
+     *
+     * `feof()` の管は `stream_select` の対象から**外す** — EOF 済みの管を残すと即時 ready になり
+     * 回転し続けるためである。読む対象が 1 本も無ければ少し眠って戻る。
+     *
+     * @param  array<int, resource>  $pipes
+     * @param  array<int, string>  $output
+     */
+    private static function readAvailable(array $pipes, array &$output): void
+    {
+        $read = [];
+        foreach ([1, 2] as $descriptor) {
+            $pipe = $pipes[$descriptor] ?? null;
+            if (is_resource($pipe) && ! feof($pipe)) {
+                $read[$descriptor] = $pipe;
+            }
+        }
+
+        if ($read === []) {
+            usleep(self::IDLE_SLEEP_MICROSECONDS);
+
+            return;
+        }
+
+        $write = null;
+        $except = null;
+        $ready = stream_select($read, $write, $except, 0, self::SELECT_WAIT_MICROSECONDS);
+
+        if ($ready === false) {
+            throw new RuntimeException('probe の出力を待てなかった (stream_select が失敗した)');
+        }
+
+        if ($ready === 0) {
+            return;
+        }
+
+        foreach ($read as $descriptor => $pipe) {
+            $chunk = fread($pipe, self::READ_CHUNK_BYTES);
+            if ($chunk === false) {
+                throw new RuntimeException('probe の出力を読めなかった');
+            }
+            $output[(int) $descriptor] .= $chunk;
+        }
+    }
+
+    /**
+     * 子・管・終了コードを回収する (冪等)。
+     *
+     * `proc_close()` は子が生きているあいだ待つ。だから本 runner は「子の終了を確認する」か
+     * 「確実に落とす」かのどちらかを済ませてからしか呼ばない。
+     *
+     * @param  resource  $process
+     * @param  array<int, resource>  $pipes  閉じた管はその場で unset する (部分完了を表現するため)
+     * @param  array{processClosed: bool, closeCode: int|null}  $state
+     */
+    private static function reclaim($process, array &$pipes, array &$state): int
+    {
+        if ($state['processClosed']) {
+            Assert::integer($state['closeCode']);
+
+            return $state['closeCode'];
+        }
+
+        if (proc_get_status($process)['running']) {
+            // シグナル送信が失敗しても即座には諦めない (自然終了を最終期限まで待つ)。
+            proc_terminate($process, self::SIGNAL_TERMINATE);
+            $killAt = microtime(true) + self::TERMINATION_GRACE_SECONDS;
+            $giveUpAt = $killAt + self::KILL_WAIT_SECONDS;
+
+            while (proc_get_status($process)['running']) {
+                $now = microtime(true);
+                if ($now >= $giveUpAt) {
+                    throw new RuntimeException('probe の子プロセスを落とせなかった (最終期限を超えた)');
+                }
+                if ($now >= $killAt) {
+                    proc_terminate($process, self::SIGNAL_KILL);
+                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
+                }
+                usleep(self::IDLE_SLEEP_MICROSECONDS);
+            }
+        }
+
+        foreach ([1, 2] as $descriptor) {
+            $pipe = $pipes[$descriptor] ?? null;
+            if (is_resource($pipe)) {
+                fclose($pipe);
+            }
+            unset($pipes[$descriptor]);
+        }
+
+        // `proc_close()` は -1 を返す場合も資源を閉じている。戻ってきた時点で閉じ済みとして扱う
+        // (「非負のときだけ完了」にすると閉じ済みの資源へ 2 度目を呼ぶ危険がある)。
+        $closeCode = proc_close($process);
+        $state['processClosed'] = true;
+        $state['closeCode'] = $closeCode;
+
+        return $closeCode;
+    }
+
+    /**
+     * 一時ディレクトリ配下に書かれたファイルを相対パスの昇順で採取する。
+     *
+     * @return list<non-empty-string>
+     */
+    private static function collectWritten(string $temporaryRoot): array
+    {
+        $prefix = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
+        $written = [];
+
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
+        );
+
+        /** @var SplFileInfo $file */
+        foreach ($iterator as $file) {
+            if (! $file->isFile()) {
+                continue;
+            }
+
+            $path = $file->getPathname();
+            if (! str_starts_with($path, $prefix)) {
+                // 黙って捨てない (追えないものが出たら設計の前提が崩れている)。
+                throw new RuntimeException('一時ディレクトリ外のファイルを採取した: '.$path);
+            }
+
+            $relative = substr($path, strlen($prefix));
+            Assert::stringNotEmpty($relative);
+            $written[] = $relative;
+        }
+
+        sort($written);
+
+        return $written;
+    }
+
+    /** 再帰削除 (存在しなければ何もしない)。**失敗したら例外**にする (黙って残さない)。 */
+    private static function removeDirectory(string $path): void
+    {
+        if (! is_dir($path)) {
+            return;
+        }
+
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
+            RecursiveIteratorIterator::CHILD_FIRST,
+        );
+
+        /** @var SplFileInfo $entry */
+        foreach ($iterator as $entry) {
+            $removed = $entry->isDir() && ! $entry->isLink()
+                ? rmdir($entry->getPathname())
+                : unlink($entry->getPathname());
+
+            if (! $removed) {
+                throw new RuntimeException('一時ディレクトリの中身を消せなかった: '.$entry->getPathname());
+            }
+        }
+
+        if (! rmdir($path)) {
+            throw new RuntimeException('一時ディレクトリを消せなかった: '.$path);
+        }
+    }
+}
diff --git a/tests/Support/StrictTypesRuntimeProbe.php b/tests/Support/StrictTypesRuntimeProbe.php
index 692781a5..bb2d0ed5 100644
--- a/tests/Support/StrictTypesRuntimeProbe.php
+++ b/tests/Support/StrictTypesRuntimeProbe.php
@@ -23,6 +23,16 @@
  *      例外にする。false を返して黙らない (fail-open 防止)
  *   3. 標識が `STRICT-<nonce>` なら true、`WEAK-<nonce>` なら false
  *   関数名も nonce つきにして、検体側の関数と衝突しないようにする。
+ *
+ * ★**共通の起動器 (Tests\Support\Process\BootProbeRunner) には載せない**
+ *   (lctl feature: subprocess-boot-probe-harness)。あちらが測るのは「**起動順序**に由来する
+ *   壊れ方」であり、本クラスが測るのは単一ファイルのコンパイル指令が効くかである。
+ *   載せるとアプリ起動用の基底環境・書き出し先 7 キーの予約・一時ディレクトリの構築という
+ *   検体の判定に無関係な前提が付く。同じ理由で `tests/Support/GlobalUse/PhpLintOracle.php`
+ *   (`php -l` の真値取り出し) も載せていない。
+ *   ★回収は Symfony の Process に委ねる (既定の制限時間つきで、超過すれば例外になる)。
+ *   ★この判断は `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` の
+ *     軸 A の申告 (`launches_app: false` + 理由) として機械に登録されている。
  */
 final class StrictTypesRuntimeProbe
 {
diff --git a/tests/Unit/Support/Process/BootProbeRunnerTest.php b/tests/Unit/Support/Process/BootProbeRunnerTest.php
new file mode 100644
index 00000000..fcb76a79
--- /dev/null
+++ b/tests/Unit/Support/Process/BootProbeRunnerTest.php
@@ -0,0 +1,423 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\Process\BootProbeRunner;
+
+/*
+| 起動 probe の共通 runner (`Tests\Support\Process\BootProbeRunner`) の自己検査
+| (lctl feature: subprocess-boot-probe-harness の正典 v1 (6) = 「自己検査を持つ」)。
+|
+| runner は「何を観測するか」を持たない道具なので、道具そのものの契約 —
+| 環境変数の 3 段合成 / 予約鍵 / 終了コードの保持 / 制限時間と強制終了 / 出力の逐次読み /
+| 書き出し先の退避と後片付け — をここで固定する。
+|
+| **この自己検査が測らないこと** (詳細設計 P2 と同じ粒度):
+|
+|  1. runner 自身の途中失敗 (`stream_select` の失敗など) を**注入した**経路は測らない。
+|     注入の継ぎ目を公開面へ足す方が害が大きい
+|  2. 起動そのものが失敗する経路 (`proc_open` の失敗) も測らない。移植性のある誘発手段が無い
+|     (常に `PHP_BINARY` と実在する作業ディレクトリで起こすため)
+|  3. 「回収不能なら一時ディレクトリを残す」経路も測らない。子は `SIGKILL` を無視できないので、
+|     移植性のある形でこの状態を作れない
+|
+| 測るのは 2 方向である: 「落とせない子を確実に落とす」(S12 / S14) と
+| 「起動前の fail-closed で残骸を残さない」(S11)。
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
+*/
+
+/** 親 env の漏れを見るための番兵 (S1)。 */
+const BOOT_PROBE_SENTINEL_KEY = 'BOOT_PROBE_SENTINEL';
+
+/**
+ * 子が受け取った環境変数を**丸ごと** JSON で報告させる probe (S1 / S2 / S3 / S4)。
+ *
+ * 鍵を列挙して問い合わせる形にすると「列挙に無い鍵が増えても緑」になる (基底に 1 本足しても
+ * 気づけない)。集合そのものを持ち帰らせて完全一致で測る。
+ */
+const BOOT_PROBE_ENV_REPORT = <<<'PHP'
+    echo json_encode(getenv());
+    PHP;
+
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
+const BOOT_PROBE_PATH_REPORT = <<<'PHP'
+    require 'vendor/autoload.php';
+    $app = require 'bootstrap/app.php';
+    $storagePath = getenv('LARAVEL_STORAGE_PATH');
+    if (! is_string($storagePath) || $storagePath === '') {
+        throw new RuntimeException('LARAVEL_STORAGE_PATH が無い (環境ファイルの退避先を導けない)');
+    }
+    $app->useEnvironmentPath(dirname($storagePath));
+    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
+    Illuminate\Support\Facades\Log::info('boot-probe self check');
+    echo json_encode([
+        'php_binary' => PHP_BINARY,
+        'env_file_path' => $app->environmentFilePath(),
+        'env_file_exists' => file_exists($app->environmentFilePath()),
+        // 秘密そのものも、その digest も出さない (テスト出力が総当りの検証器になるのを避ける)。
+        // この 2 つの設定値は env からしか来ない (config/ciphersweet.php は既定を持たず、
+        // config/database.php は空文字を既定にする) ので、**非空なら環境ファイルが読まれた**証拠になる。
+        'ciphersweet_key_present' => ((string) config('ciphersweet.providers.string.key')) !== '',
+        'db_password_present' => ((string) config('database.connections.pgsql.password')) !== '',
+        'storage' => $app->storagePath(),
+        'config_cache' => $app->getCachedConfigPath(),
+        'routes_cache' => $app->getCachedRoutesPath(),
+        'services_cache' => $app->getCachedServicesPath(),
+        'packages_cache' => $app->getCachedPackagesPath(),
+        'events_cache' => $app->getCachedEventsPath(),
+        'view_compiled' => (string) config('view.compiled'),
+        'log_path' => (string) config('logging.channels.single.path'),
+    ]);
+    PHP;
+
+/**
+ * 子の JSON 報告を配列で受け取る。
+ *
+ * @param  array<non-empty-string, string>  $env
+ * @return array<string, mixed>
+ */
+function bootProbeDecodeReport(string $code, array $env = []): array
+{
+    $result = BootProbeRunner::run(['-r', $code], $env);
+
+    expect($result->exitCode)->toBe(0, "probe が異常終了した: {$result->stderr}");
+
+    /** @var mixed $decoded */
+    $decoded = json_decode(trim($result->stdout), true);
+    expect($decoded)->toBeArray("probe の出力が JSON でない: {$result->stdout} / {$result->stderr}");
+    assert(is_array($decoded));
+
+    /** @var array<string, mixed> $decoded */
+    return $decoded;
+}
+
+test('S1: 親の環境変数は子に現れない', function (): void {
+    putenv(BOOT_PROBE_SENTINEL_KEY.'=leaked');
+
+    try {
+        $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);
+
+        expect($report)->not->toHaveKey(BOOT_PROBE_SENTINEL_KEY, '親の env が子へ漏れている');
+    } finally {
+        putenv(BOOT_PROBE_SENTINEL_KEY);
+    }
+});
+
+test('S2: 許可した継承は規則どおり届く (親に無い鍵は子にも無い)', function (): void {
+    // 継承する鍵の一覧そのものをリテラルで pin する。実装と定数を同時に削っても緑になる形
+    // (期待値を実装側の定数から組み立てるだけの検査) を避ける。
+    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR']);
+
+    $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);
+
+    foreach (BootProbeRunner::INHERITED_ENV_KEYS as $key) {
+        $parent = getenv($key);
+        // runner と同じ規則 (文字列かつ非空のときだけ継承する) で期待値を組む。
+        // 環境によって HOME / TMPDIR が無くても偽レッドにしない。
+        if (is_string($parent) && $parent !== '') {
+            expect($report[$key] ?? null)->toBe($parent, "継承鍵 {$key} が子へ届いていない");
+
+            continue;
+        }
+
+        expect($report)->not->toHaveKey($key, "親に無い継承鍵 {$key} を子が持っている");
+    }
+});
+
+test('S3: ケース別上書きが基底に勝つ (正典 v1 の順序)', function (): void {
+    $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT, ['CACHE_STORE' => 'file']);
+
+    expect($report['CACHE_STORE'])->toBe('file', 'ケース別上書きが基底に負けている');
+});
+
+test('S4: 子が受け取る env の集合が完全一致する (基底 3 本 + 予約 7 本 + 継承分だけ)', function (): void {
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_ENV_REPORT]);
+    expect($result->exitCode)->toBe(0, $result->stderr);
+
+    /** @var array<string, string> $report */
+    $report = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);
+
+    // (a) 集合の完全一致。「以下」ではないので、基底に 1 本足しただけでも赤くなる。
+    $inherited = array_values(array_filter(
+        BootProbeRunner::INHERITED_ENV_KEYS,
+        static function (string $key): bool {
+            $value = getenv($key);
+
+            return is_string($value) && $value !== '';
+        },
+    ));
+    $expectedKeys = array_merge(
+        $inherited,
+        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
+        BootProbeRunner::RESERVED_ENV_KEYS,
+    );
+    sort($expectedKeys);
+    $actualKeys = array_keys($report);
+    sort($actualKeys);
+
+    expect($actualKeys)->toBe($expectedKeys, '子が受け取る env の集合が契約と違う');
+
+    // (b) 基底 3 本の値
+    expect($report['APP_KEY'])->not->toBe('')
+        ->and($report['QUEUE_CONNECTION'])->toBe('database')
+        ->and($report['CACHE_STORE'])->toBe('array');
+
+    // (c) 予約鍵 7 本は一時ディレクトリ配下を指す
+    foreach (BootProbeRunner::RESERVED_ENV_KEYS as $key) {
+        expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
+            ->toBeTrue("予約鍵 {$key} が一時ディレクトリの外を指している: {$report[$key]}");
+    }
+
+    // (d) 集合一致の系として、APP_ENV とロケール系が入っていないことを名指しでも書く
+    // (「渡さない実行は素の .env を読む」は BughuntFakeWiringTest の複数ケースの前提である)。
+    expect($report)->not->toHaveKey('APP_ENV')
+        ->and($report)->not->toHaveKey('LANG')
+        ->and($report)->not->toHaveKey('LC_ALL');
+});
+
+test('S5: 予約鍵は呼び出し側から渡せない', function (): void {
+    expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], ['LARAVEL_STORAGE_PATH' => '/tmp/x']))
+        ->toThrow(RuntimeException::class);
+});
+
+test('S6: 終了コードを保持する', function (): void {
+    $result = BootProbeRunner::run(['-r', 'exit(7);']);
+
+    expect($result->exitCode)->toBe(7, '終了コードが proc_close の戻り値で潰れている')
+        ->and($result->timedOut)->toBeFalse();
+});
+
+test('S7: 制限時間を超えた子を強制終了する', function (): void {
+    $result = BootProbeRunner::run(['-r', 'sleep(30);'], timeoutSeconds: 1);
+
+    expect($result->timedOut)->toBeTrue('制限時間を超えた子が落ちていない')
+        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
+        // 実時間で狭く測らない (CI の負荷で偽レッドにしない)。上限は
+        // 制限時間 + 猶予 + 最終期限 + 余裕で見る。
+        ->and($result->elapsedSeconds)->toBeGreaterThanOrEqual(1.0)
+        ->and($result->elapsedSeconds)->toBeLessThan(
+            1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS + BootProbeRunner::KILL_WAIT_SECONDS + 5.0,
+        );
+});
+
+test('S8: 大量出力で詰まらない', function (): void {
+    // パイプバッファは 64KiB 程度なので、逐次読みでなければ子が固まって S7 経路に落ちる。
+    $result = BootProbeRunner::run(['-r', 'echo str_repeat("x", 1048576);']);
+
+    expect($result->exitCode)->toBe(0, $result->stderr)
+        ->and(strlen($result->stdout))->toBe(1048576)
+        ->and($result->timedOut)->toBeFalse();
+});
+
+test('S9: 書き出し先の退避が効いている (向き) / 親と同じ実行体で起きる', function (): void {
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'single']);
+    expect($result->exitCode)->toBe(0, $result->stderr);
+
+    // ★報告には真偽値も混ざるので `mixed` で受ける (取り込み元は string 固定だった)。
+    /** @var array<string, mixed> $report */
+    $report = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);
+
+    // 正典 (1): 親と同じ実行体で起こす。
+    expect($report['php_binary'])->toBe(PHP_BINARY);
+
+    // ★aicue のローカル修正 (T249) の実働証明 — **申告ではなく実挙動を測る**。無条件の 2 方向で、
+    //   どちらもリポジトリの `.env` の**中身に依存しない** (見本から起こしたチェックアウトでも
+    //   同じ強さで成立し、テスト出力に秘密も digest も出ない)。
+    //
+    //   (a) **場所**: Laravel が読む環境ファイルは `environmentFilePath()` の**ちょうど 1 本**で、
+    //       それが `<一時ディレクトリ>/.env` **と完全一致**し、しかも**実在しない**。
+    //       ★配下判定 (`isInside()`) では測らない — あちらは両引数が正規化済みであることを
+    //         契約にしており、`..` を含む未正規化のパスを渡すと取り違える。ここは
+    //         起動器が予約鍵で渡した一時ディレクトリから期待値が一意に決まるので、
+    //         **完全一致**で測るのが最も強く、正規化の前提も要らない。
+    expect($report['env_file_path'])->toBe(
+        $result->temporaryRoot.'/.env',
+        '子が読む環境ファイルが起動器の一時ディレクトリの直下でない',
+    )->and($report['env_file_exists'])
+        ->toBeFalse("子が環境ファイルを読み込んでいる: {$report['env_file_path']}");
+
+    //   (b) **中身**: 環境ファイルからしか来ない設定値が**空である**。
+    //       `config/ciphersweet.php` の鍵は既定を持たず、`config/database.php` のパスワードは
+    //       空文字を既定にするので、**非空なら環境ファイルが読まれた**ことになる。
+    //       (a) が「読む先」を、(b) が「読んだ結果」を測るので、
+    //       「置き場所は移したが値は別経路で入った」形もここで落ちる。
+    expect($report['ciphersweet_key_present'])
+        ->toBeFalse('子の設定に CIPHERSWEET_KEY が載っている (環境ファイルが読まれた)')
+        ->and($report['db_password_present'])
+        ->toBeFalse('子の設定に DB_PASSWORD が載っている (環境ファイルが読まれた)');
+
+    foreach (['storage', 'config_cache', 'routes_cache', 'services_cache', 'packages_cache',
+        'events_cache', 'view_compiled', 'log_path'] as $key) {
+        expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
+            ->toBeTrue("書き出し先 {$key} がリポジトリ側を指している: {$report[$key]}");
+    }
+});
+
+test('S10: 書き出し先の退避が効いている (実体) と後片付け', function (): void {
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'single']);
+
+    expect($result->exitCode)->toBe(0, $result->stderr)
+        ->and($result->writtenRelativePaths)->toContain('storage/logs/laravel.log')
+        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');
+});
+
+test('S11: 一時ディレクトリがリポジトリ内なら起動前に失敗し残骸を残さない', function (): void {
+    $base = base_path('storage/framework/testing');
+    // ★aicue のローカル修正 (T249): **このテストが作った階層を 1 つ残らず**戻す
+    //   (受入条件の「走行が生成物を残さない」。取り込み元は `storage/framework` を新規作成した
+    //   環境でそれを残していた)。`--parallel` の他 worker が同じ場所を使うので、
+    //   **空でなければ触らない**。
+    $createdAncestors = [];   // 深い順
+    for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
+        $createdAncestors[] = $candidate;
+    }
+    foreach (array_reverse($createdAncestors) as $directory) {
+        expect(mkdir($directory, 0o755))->toBeTrue("後始末の対象を作れない: {$directory}");
+    }
+
+    try {
+        $before = glob($base.'/boot-probe-*');
+        expect($before)->toBeArray();
+        assert(is_array($before));
+
+        expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], temporaryBase: $base))
+            ->toThrow(RuntimeException::class);
+
+        $after = glob($base.'/boot-probe-*');
+        expect($after)->toBe($before, '起動前の fail-closed が残骸を残している');
+    } finally {
+        // 深い順に戻す (作った分だけ)。空でなければ他 worker が使っているので触らない。
+        foreach ($createdAncestors as $directory) {
+            if (! is_dir($directory)) {
+                continue;
+            }
+
+            $remaining = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
+            if ($remaining !== []) {
+                break;
+            }
+
+            rmdir($directory);
+        }
+    }
+
+    // 境界判定そのものを pin する (`/repo` と `/repository` を取り違えない)。
+    expect(BootProbeRunner::isInside('/repo', '/repo'))->toBeTrue()
+        ->and(BootProbeRunner::isInside('/repo', '/repo/inner'))->toBeTrue()
+        ->and(BootProbeRunner::isInside('/repo', '/repository'))->toBeFalse()
+        ->and(BootProbeRunner::isInside('/repo/', '/repo/inner'))->toBeTrue();
+});
+
+test('S12: 管を早く閉じた子でも確実に落として回収する', function (): void {
+    // 子は標準出力・標準エラーを閉じてから寝る。管の EOF だけを終了検知に使う実装は
+    // ここで無限に待つ (= 制限時間が効いていない) ことになる。
+    $result = BootProbeRunner::run(
+        ['-r', 'fclose(STDOUT); fclose(STDERR); sleep(30);'],
+        timeoutSeconds: 1,
+    );
+
+    expect($result->timedOut)->toBeTrue()
+        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
+        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');
+
+    if (! function_exists('posix_kill')) {
+        return;
+    }
+
+    // 回収済みなら pid はもう存在しない (ps ではなく runner が握っていた pid を直接見る)。
+    expect(posix_kill($result->pid, 0))->toBeFalse('子プロセスが残っている');
+});
+
+test('S13: 子の終了後も読み切り、その最終読み取りには上限がある', function (): void {
+    // 子は孫へ標準出力・標準エラーを渡したまま先に終了する。2 方向を同時に測る:
+    //  - 上限が無い実装は、孫が寝ている間ずっと戻れない (孫は回収しない = 保証しないことの 1 つ)
+    //  - 最終読み取りが無い実装は、子の終了後に届いた印を取りこぼす
+    $code = <<<'PHP'
+        $child = proc_open(
+            [PHP_BINARY, '-r', 'usleep(300000); fwrite(STDOUT, "DRAINED"); sleep(6);'],
+            [1 => STDOUT, 2 => STDERR],
+            $pipes,
+        );
+        exit(3);
+        PHP;
+
+    $result = BootProbeRunner::run(['-r', $code]);
+
+    // toContain は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
+    expect($result->stdout)->toContain('DRAINED');
+
+    expect($result->exitCode)->toBe(3, '子の終了コードを取りこぼしている')
+        ->and($result->timedOut)->toBeFalse()
+        ->and($result->elapsedSeconds)->toBeLessThan(
+            BootProbeRunner::FINAL_DRAIN_SECONDS + 2.5,
+            '孫が管を持っている間ずっと待っている (最終読み取りの上限が効いていない)',
+        );
+});
+
+test('S14: 終了要求を無視する子は強制終了で落とす (段階的強制終了)', function (): void {
+    // S7 / S12 の子は SIGTERM で死ぬので、SIGKILL への昇格を消しても緑になってしまう。
+    // ここは**終了要求を無視する子**を使い、猶予の後の強制終了まで到達させる。
+    $result = BootProbeRunner::run(
+        ['-r', 'pcntl_signal(SIGTERM, SIG_IGN); sleep(30);'],
+        timeoutSeconds: 1,
+    );
+
+    expect($result->timedOut)->toBeTrue()
+        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
+        // 終了要求では死なないので、猶予ぶんは必ず経過している (= SIGKILL 経路を通った)。
+        ->and($result->elapsedSeconds)->toBeGreaterThanOrEqual(1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS)
+        ->and($result->elapsedSeconds)->toBeLessThan(
+            1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS + BootProbeRunner::KILL_WAIT_SECONDS,
+            '最終期限を超えるまで落とせていない',
+        )
+        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');
+
+    if (! function_exists('posix_kill')) {
+        return;
+    }
+
+    expect(posix_kill($result->pid, 0))->toBeFalse('強制終了しても子が残っている');
+})->skip(
+    // 子は親と同じ実行体なので、親に ext-pcntl が無ければ子にも無い。
+    // **成功扱いにはしない** — 段階的強制終了を測れていないことをテスト結果に出す。
+    fn (): bool => ! function_exists('pcntl_signal'),
+    'ext-pcntl が無い環境では終了要求を無視する子を作れず、段階的強制終了を測れない',
+);

```

---

## 検証コマンドの実測 (Round 5 の修正を入れた最終形)

個別 (単独実行、いずれも green):

```
tests/Unit/Support/Process/BootProbeRunnerTest.php        : 14 tests / 14 passed / 77 assertions
tests/Architecture/PhpBootProbeReferenceInventoryTest.php : 62 tests / 62 passed / 208 assertions
tests/Architecture/ExternalFakeBootProbeTest.php          : 33 tests / 33 passed / 147 assertions (P-17 を含む)
tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php : 5 tests / 5 passed / 58 assertions
```

静的検査・フロント:

```
composer phpstan (level 10): [OK] No errors
vendor/bin/pint --test: passed
pnpm lint / pnpm typecheck / pnpm build: OK
pnpm typecheck:packages / pnpm build:packages: OK
pnpm test:          179 files / 2398 tests passed
pnpm test:packages:  10 files /  106 tests passed
```

全体走行 (`composer test` = `--parallel --processes=4`) の履歴:

```
Round 4 時点のコード: A=failed(2) / B=passed / C=passed / D=failed(2)
  → 失敗はすべて tests/Feature/Auth/EmailPromotionTest.php の同一 2 件 (T253 由来の順序依存 flake)
Round 5 の修正後 :   E=error(2) / F=error(1)+failed(1)
  → 失敗はすべて tests/Architecture/BughuntSelfTestExecutionTest の
    「scripts/bug-hunt-shard.sh self-test が 120 秒 timeout」。
    **同じマシンで別エージェントが別 worktree の全体テストを同時に走らせていたための負荷**で、
    走行時間も 560 秒 → 929 秒 / 725 秒へ伸びていた。T249 の差分は
    bug-hunt にも shell script にも触れていない。負荷が下がった状態で取り直す。
```

**T253 由来 flake の provenance を確定させた実測 (Round 5 の宿題)**:

```
main の作業ツリー (T249 の変更を 1 バイトも含まない) で:
  vendor/bin/pest tests/Feature/Filament tests/Feature/Auth/EmailPromotionTest.php
    → 78 tests / 78 passed              (再現しない)
  vendor/bin/pest tests/Feature/Admin    tests/Feature/Auth/EmailPromotionTest.php
    → 77 tests / 75 passed / 2 failed   ← **同一の 2 件が同一の内容で失敗する**

= Filament (Livewire) を先に描画した同一プロセスで standalone Blade の確認画面を描くと
  Livewire の <style> / <script> が注入される、という main 側の既存欠陥である。
```

---

## 判定してほしいこと (優先順)

1. Round 5 の [Critical] 2 件 (G-8 の主張と裏取りの不整合 / G-9 走査器の偽グリーン) が解消しているか
   - `env_isolation` を `behavioural` / `structural` に分け、`structural` について
     **「実際に読まない」とは主張しない**と明記した形が、指摘の趣旨を満たしているか
   - 4 トークン完全一致 (`$app` `->` `useEnvironmentPath` `(`) + 文字列の中も字句解析し直す形が
     AGENTS.md §静的検査の共通規約 (b) / (c) / (e) を満たしているか
     (**受け手を綴りで固定する**という割り切りの是非も含めて)
2. Round 5 の [Warning] (P-17 の新設 / S11 と P-10d の後片付け / `$report` の PHPDoc /
   起動器の公開契約の置き場所 / 詳細設計 S6) への対応が十分か
3. その他、詳細設計との不一致・fail-open・偽グリーンの穴
4. **この実装を main へマージしてよいか** (残る指摘があるなら、それが**マージ阻害かどうか**を明記すること)

**全体判定を APPROVED / CHANGES_REQUESTED で明記すること。**
