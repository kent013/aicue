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
