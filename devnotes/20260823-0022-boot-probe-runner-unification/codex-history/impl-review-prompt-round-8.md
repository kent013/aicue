# Codex 実装レビュー依頼 (impl-review Round 8 / 新セッション (残り 1 件の確認))

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

T249 (起動 probe の共通 runner への一元化。変更はすべて tests/ 配下) の実装レビューは Round 7 まで進み、
**マージ阻害は 1 件だけ**残っていた — S11 / P-10d が共有の祖先ディレクトリを作成・削除することで
`--parallel` の他 worker と競合しうる、という指摘である。**その 1 件への対応だけ**を確認してほしい。
**Round 7 が「問題ありません」「解消しています」と判定した点を蒸し返す必要はない。**

### Round 7 の Codex 返答 (全文)

## `tests/Unit/Support/Process/BootProbeRunnerTest.php`

判定: **修正が必要です。**

- [Critical] S11 は葉を一意にしたことで直接の名前競合は解消しましたが、共有祖先を削除するため、並列競合がまだ残っています。

成立する interleaving は次のとおりです。

1. worker A/B が、存在しない共有祖先 `storage/framework/testing` をそれぞれ `$createdAncestors` に記録
2. B が共有祖先を作成・確認した直後、専用葉を作る前に停止
3. A が専用葉を削除し、空になった共有祖先も削除
4. B が専用葉を `mkdir()` するが、親が消えているため失敗
5. `is_dir($directory)` が `false` となりテスト失敗

`@mkdir()` は警告を隠すだけで、この「親の確認と子の作成の間に親を削除される」競合を防ぎません。`scandir()` 後の `rmdir()` にも同じ TOCTOU があります。

修正は、確実に既存であるディレクトリ直下に一意な葉を1つだけ作り、削除対象もその葉だけに限定する形が安全です。例えば、既存性を確認した `base_path()` または既存の固定ディレクトリを親にし、共有祖先を `$createdAncestors` に含めない構造です。

## `tests/Architecture/ExternalFakeBootProbeTest.php`

判定: **修正が必要です。**

- [Critical] P-10d も S11 と同じ共有祖先の作成・削除競合を残しています。一意なのは末端だけであり、`storage/framework/testing` などの祖先は両テストで共有されています。

空確認時に他 worker の葉がまだ作られていなければ、先行 worker が共有祖先を削除できます。したがって「空でなければ打ち切る」だけでは保護できません。

P-10d も、既存の親直下に専用葉だけを作成し、後片付けでは専用葉以外を削除しない形にする必要があります。

## `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

判定: **問題ありません。**

- G-8 冒頭の主張範囲は実装に合う表現へ修正されています。
- G-9 が未使用文字列も証拠にできる限界も明記され、検出力を誇張していません。
- Round 6 の Warning への対応として妥当です。

## 詳細設計書

判定: **問題ありません。**

S3、S4、個別テスト一覧、受入条件が P-17・G-8・G-9 を含む現在の実装へ更新されており、Round 6 の更新漏れは解消しています。

## 検証証跡

判定: **Round 6 の受入条件を満たしています。**

最終形で `composer test` が2回連続 green になっているため、このマージ阻害は解消しています。ただし、実走行が2回成功したことは、上記の成立可能な race condition を否定しません。

## 全体判定

Round 6 の2件のうち、全体テスト2回連続 green は解消しています。一方、並列競合は形を変えて残っています。共有祖先を削除することで、別 worker の「親確認→専用葉作成」の途中へ干渉できます。

この競合は詳細設計の並列安全契約に反するため、引き続きマージ阻害です。

**CHANGES_REQUESTED**

### Round 7 への対応マトリクス

# 対応マトリクス: impl-review Round 7

Round 7 の判定は **CHANGES_REQUESTED**。残るマージ阻害は **1 件だけ**で、
Round 6 の並列競合の直し方が不十分だったという指摘である (受諾して直した)。
もう 1 件のマージ阻害 (最終形での 2 回連続 green) は**解消済み**と判定された。

---

## [Critical] 共有祖先の**削除**が、別 worker の「親を確かめて葉を作る」に干渉しうる

- 判断: **対応する** (指摘は正しい)
- 根拠: Round 6 の直し方は葉を一意にしたが、**祖先は共有のまま**で、
  「空なら削除する」後片付けを残していた。worker A が
  `storage/framework/testing` を空と判定して削除する瞬間、worker B は
  「親が在る」と確かめた直後で葉を作ろうとしている、という並びが成立する
  (B の `mkdir()` が ENOENT で落ちる)。**空確認では防げない**。
- 対応内容 (S11 / P-10d の両方):
  - 親を `storage/framework/testing` に固定し、**掘らない・消さない**。
    このディレクトリは `.gitignore` が **git 追跡下**にある (実測:
    `git ls-files storage/` に `storage/framework/testing/.gitignore` が在る) ので
    **どのチェックアウトにも実在する**。不在なら前提が崩れているので**掘らずに赤くする**
    (fail-closed。作りにいくと競合が戻る)。
  - その直下に**一意な葉**を 1 つだけ作り (`boot-probe-s11-<16 桁>` /
    `fake-wiring-p10d-<16 桁>`)、後片付けは**自分の葉だけ**を `@rmdir()` する。
  - 結果として **共有の祖先には作成でも削除でも一切触れない**ので、
    Round 6 / Round 7 で指摘された競合の並びがどちらも構造的に成立しない。
  - `$createdAncestors` の仕組みは両ファイルから**削除した** (残すと再発の余地になる)。

## 解消済みと判定された項目 (記録)

- **最終形での `composer test` 2 回連続 green** — run I / run J
  (いずれも 7467 tests / 7465 passed / 0 failed / 2 skipped / 5 risky)。
- G-8 冒頭の主張範囲 / G-9 の限界の明記 / 詳細設計 S3・S4・受入条件の更新 —
  いずれも「問題ありません」。
- EmailPromotionTest の flake は Round 6 で「T249 の回帰ではない」と判定済み。

## 本ラウンドの修正後にやり直した検証

Round 7 の修正は S11 と P-10d の**置き場所の作り方と片付け方だけ**なので、
targeted 2 本 + 全体 2 回連続を取り直した (結果は最終報告に記す)。


---

## 対応後の S11 (自己検査) の全文

```php
test('S11: 一時ディレクトリがリポジトリ内なら起動前に失敗し残骸を残さない', function (): void {
    // ★aicue のローカル修正 (T249): 置き場所は**この検査専用の一意な葉**にする。
    //   取り込み元は共有の `storage/framework/testing` を直接使い、不在なら再帰的に掘っていた。
    //   `--parallel` では同じ場所を使う別の検査 (`ExternalFakeBootProbeTest` の P-10d) と
    //   **作成でも削除でも競合する** (先に作った側が勝つ / 先に消した側が他方の親を消す)。
    //   親 `storage/framework/testing` は `.gitignore` が git 追跡下にあるので
    //   **どのチェックアウトにも実在する**。よって**掘らない・消さない**で、
    //   一意な葉を 1 つだけ作って自分の分だけ片付ける (共有の祖先に一切触れない)。
    $parent = base_path('storage/framework/testing');
    expect(is_dir($parent))
        ->toBeTrue("前提が崩れている (追跡下の .gitignore で常在するはずの場所が無い): {$parent}");

    $base = $parent.'/boot-probe-s11-'.bin2hex(random_bytes(8));
    expect(mkdir($base, 0o755))->toBeTrue("専用の置き場所を作れない: {$base}");

    try {
        $before = glob($base.'/boot-probe-*');
        expect($before)->toBeArray();
        assert(is_array($before));

        expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], temporaryBase: $base))
            ->toThrow(RuntimeException::class);

        $after = glob($base.'/boot-probe-*');
        expect($after)->toBe($before, '起動前の fail-closed が残骸を残している');
    } finally {
        // 専用の葉だけを消す (共有の親には触れない = 他 worker の「親を確かめて葉を作る」を邪魔しない)。
        @rmdir($base);
    }

    // 境界判定そのものを pin する (`/repo` と `/repository` を取り違えない)。
    expect(BootProbeRunner::isInside('/repo', '/repo'))->toBeTrue()
        ->and(BootProbeRunner::isInside('/repo', '/repo/inner'))->toBeTrue()
        ->and(BootProbeRunner::isInside('/repo', '/repository'))->toBeFalse()
        ->and(BootProbeRunner::isInside('/repo/', '/repo/inner'))->toBeTrue();
});

```

## 対応後の P-10d の全文

```php
test('P-10d リポジトリ内の置き場所は本体を呼ばずに拒否し、残骸を残さない', function (): void {
    // 正典 v1 (5) の fail-closed を**外側**でも測る (内側は取り込んだ自己検査 S11 が持つ)。
    // ★置き場所は**この検査専用の一意な葉**にする。共有の `storage/framework/testing` を
    //   直接使う / 不在なら掘る形にすると、`--parallel` で同じ場所を使う別の検査
    //   (`BootProbeRunnerTest` の S11) と**作成でも削除でも競合する**
    //   (先に作った側が勝つ / 先に消した側が他方の親を消す)。
    //   親は `.gitignore` が git 追跡下にあるのでどのチェックアウトにも実在する。
    //   **掘らない・消さない**で、一意な葉を 1 つだけ作って自分の分だけ片付ける。
    $parent = base_path('storage/framework/testing');
    expect(is_dir($parent))
        ->toBeTrue("前提が崩れている (追跡下の .gitignore で常在するはずの場所が無い): {$parent}");

    $base = $parent.'/fake-wiring-p10d-'.bin2hex(random_bytes(8));
    expect(mkdir($base, 0755))->toBeTrue("専用の置き場所を作れない: {$base}");

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
        // 専用の葉だけを消す (共有の親には触れない = 他 worker の「親を確かめて葉を作る」を邪魔しない)。
        @rmdir($base);
    }
});

```

## 事実の裏取り

```
$ git ls-files storage/
storage/app/.gitignore
storage/app/private/.gitignore
storage/app/public/.gitignore
storage/framework/.gitignore
storage/framework/cache/.gitignore
storage/framework/cache/data/.gitignore
storage/framework/sessions/.gitignore
storage/framework/testing/.gitignore     ← **追跡下にある** = どのチェックアウトにも実在する
storage/framework/views/.gitignore
storage/logs/.gitignore
```

= 親 `storage/framework/testing` は git が clone 時に必ず作る。したがって
**両テストは共有の祖先を作りも消しもしない**。作るのは一意な葉 1 つだけで、消すのも自分の葉だけである。
親が不在なら前提が崩れているので**掘らずに赤くする** (fail-closed)。

## 検証の実測 (Round 7 の修正を入れた最終形)

```
composer test (--parallel --processes=4) — **2 回連続 green**:
  run K: passed  7467 tests / 7465 passed / 0 failed / 2 skipped / 5 risky
  run L: passed  7467 tests / 7465 passed / 0 failed / 2 skipped / 5 risky

個別:
  tests/Unit/Support/Process/BootProbeRunnerTest.php : 14 / 14 (79 assertions)
  tests/Architecture/ExternalFakeBootProbeTest.php   : 33 / 33 (149 assertions)

vendor/bin/pint --test: passed
```

さらに、この確認のあとに **main を取り込み直した** (main が T254 / T257 / T259 の分だけ前進していた)。
コンフリクトは 0 件で、取り込み後に
`tests/Architecture/PhpBootProbeReferenceInventoryTest.php` は 62 / 62 green
(= 新しく入ったファイル群が 3 軸の申告と食い違っていない)。
`pnpm lint` / `typecheck` / `build` / `typecheck:packages` / `build:packages` も取り込み後に green。
取り込み後の全体テスト 2 回連続と phpstan / pint は実行中で、結果は最終報告に載せる。

---

## 判定してほしいこと

1. Round 7 の [Critical] (共有祖先の作成・削除競合) が**構造的に**解消しているか
2. **この実装を main へマージしてよいか。** 残る指摘があるなら、それが**マージ阻害かどうか**を明記すること

**全体判定を APPROVED / CHANGES_REQUESTED で明記すること。**
