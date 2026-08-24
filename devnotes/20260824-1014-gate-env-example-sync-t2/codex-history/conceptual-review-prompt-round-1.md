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

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【本件の追加文脈】
- 本件は家系 (6 リポジトリ) 共有の機能台帳 lctl の feature `gate-env-example-sync` の
  正典 t2 への追従であり、正典の不変条件 i1〜i14 は 2026-08-22 に確定済み (curator 裁定)。
  正典が定めた不変条件そのものの妥当性は本レビューの争点ではない (aicue 単独では動かせない)。
  争点は「aicue でどう充足するか」と「過剰・不足・既存資産の破壊が無いか」である。
- 対象は Architecture テスト 1 本 (`tests/Architecture/EnvExampleInvariantTest.php`, 現行 477 行) と
  テンプレート乖離台帳の登録である。アプリ実行コード・DB・UI の変更は無い。

---

## 参考: 正典 (lctl feature gate-env-example-sync) の確定した不変条件のうち本件に関わるもの

## 確定した設計（不変条件）

- **i1**: 本 gate の対象は、そのリポジトリが commit して配る**ローカル開発用の見本
  1 枚** — `.env.example` である。本番用のひな形 (`.env.production-template`)・
  実行中の `.env`・テスト用の env・bug-hunt 用の見本は対象に含めない。
  これらはそれぞれ別の feature が担当する
- **i2**: 見本の解析は**純粋関数**として持つ (文字列を受け取り、代入・重複・形式違反を
  返す)。ファイルを読むのは入出力のアダプタだけに閉じ、判定は純粋関数の出力しか見ない。
  解析は `env()` / `config()` を呼ばない (見本の値がテスト実行時の設定に影響しない・
  されないことを構造で担保する)
- **i3**: 行の受理規則を固定する。空白だけの行とコメント行は実効値を作らないので飛ばす。
  それ以外の行は**素の代入行だけ**を受理する — キーは `[A-Z][A-Z0-9_]*`、等号の直後から
  行末までが値。`export` つきの行・先頭に空白のある代入・小文字のキー・等号の前に
  空白のある代入・素の区切り線は形式違反として落とす。制御文字を含む行も形式違反にする
  (見た目が同じでも dotenv・OS の環境変数・配備の経路で同じ値として扱われる保証が無い)。
  等号の**後ろ**の空白は値の一部として保つ。改行は CRLF / CR / LF のいずれでも行に割る。
  コメント行の字下げを許すかは各リポジトリの裁量とする (どちらでも偽の緑を作らない)
- **i4**: 代入キーは見本の中で**全キー一意**であること。重複は不合格にする。
  理由は「dotenv が後勝ちだから」ではない — 同一ファイル内の重複の解決順は
  仕様で保証されておらず、構成 (上書きを許すか) と実装で変わりうる。だから
  「どちらが実効値か」を選ばず、重複そのものを曖昧な設定として禁じる
- **i5**: 値の固定は「そのキーの**実代入がちょうど 1 件**あり、その値が台帳の値と
  完全一致する」ことを要求する。部分一致 (ファイル全体に文字列が含まれるか) や
  存在確認では固定にならない — コメント行だけでも通ってしまう
- **i6**: 固定する値は、**向きが安全側・危険側で決まるもの**に限る (セキュア Cookie の
  有効化・セッションの暗号化など) 。加えて見本の**用途宣言**として `APP_ENV=local` を
  固定する。運用の好みで変わる値 (ログの出力量・保存先ドライバの選択) は固定しない —
  固定しても変更検出器になるだけで、安全性を上げない
- **i7**: 必須キーの台帳を持ち、素の代入行としての存在を要求する (値は見ない)。
  台帳の entry は 1 件ごとに**分類**と**由来** (なぜ必須なのか) を持ち、
  由来が空でないことを機械で検査する。台帳は**床であって天井ではない** —
  台帳に載っていないキーを見本へ足すことは違反にしない
- **i8**: 台帳自身の誠実性を機械で検査する。少なくとも (1) entry が 1 件以上ある、
  (2) キーの綴りが代入行として成立する、(3) キーが台帳の中で一意である
  (値の固定と必須キーの二重登録も、種別をまたいだ重複も禁じる)、(4) 種別と値・分類の
  組み合わせが整合する、の 4 つを見る
- **i9**: 台帳から entry が**静かに消えても緑にならない**こと。種別ごと・分類ごとの
  件数を台帳自身に申告させ、実件数との一致を要求する。件数の申告は摩擦だが、
  「見本からキーを消す変更は台帳の entry と申告件数の両方の更新を要求する」という
  意図した摩擦である。検査をデータ駆動で回す形にする場合は、駆動元が空になったときに
  落ちる検査 (床) を併せて持つ
- **i10**: **壊れたら赤くなることを機械で示す**反証データセットを恒久で持つ。
  見本を実際に壊すのではなく、壊した本文を合成して i2 の純粋関数へ食わせる。
  少なくともコメントで偽装した代入・重複・`export` つき・先頭に空白のある代入・
  小文字のキー・等号の前の空白・空入力を含み、正常系の下限も対で置く
- **i11**: 見本の値の中の `${VAR}` 参照は、**同じファイルの先行行で定義されたキー**だけを
  指してよい。自己参照と前方参照は不合格にする (解決できずリテラルのまま残り、
  画面や送信メールへそのまま出る)。実行環境からの注入を期待する参照は、理由付きの
  許可台帳へ登録したものだけを通す (既定は拒否)
- **i12**: 検査の前提そのものを固定する。テスト実行時に**読み込まれている env ファイルが
  見本ファイルでない**ことを実行時に確認する。主張はここに限り、許可する env の名前の
  集合までは固定しない (正当な env 名を足しただけで落ちるのは過剰である)
- **i13**: 検査が**保証しない範囲を検査自身が明記する**。少なくとも (1) 実行中の `.env`・
  プロセスの環境変数・設定キャッシュには効かない、(2) キー網羅は存在だけを見て値を
  見ない (空の値も通る)、(3) config の既定値と見本の値の一致は見ない (同期の検査ではなく
  提示の検査である)、(4) 台帳に載せていない要求の欠落は検出しない、を書く
- **i14**: gate は 1 リポジトリにつき**1 本のファイル**へ集める。同じ関心事の検査を
  別名のファイルへ並置しない。正典のファイル名は
  `tests/Architecture/EnvExampleInvariantTest.php` とする

不変条件を支える構成: i2 の純粋関数が i3 / i4 / i5 の判定材料 (代入・重複・形式違反) を
1 か所で作り、i10 の反証はその純粋関数へ直接入力を与える。i7 / i8 / i9 は台帳という
1 つのデータに対する 3 層の検査で、i8 が「台帳が壊れていないこと」、i9 が「台帳が
痩せていないこと」、i7 が「台帳と現物が一致すること」を分担する。i11 と i12 は
gate の前提側を押さえる 2 本で、i11 は見本の値が実環境で意味を持つことを、
i12 は見本が検査の実行環境に混入していないことを担保する。


## 参考: 現行の gate (tests/Architecture/EnvExampleInvariantTest.php) の抜粋

以下は現行実装のうち本件で変える部分である (全 477 行のうち解析器・台帳・誠実性の検査)。

```php
/**
 * 見本ファイルの本文を行単位で解析する (**純粋関数**。ファイルを読まない)。
 *
 * 行の分類:
 *   - 空白だけの行 → 実効値に影響しないので飛ばす
 *   - `^\s*#` の行 → コメント。同上
 *   - それ以外     → 素の代入行 `^[A-Z][A-Z0-9_]*=` **のみ**受理する
 *
 * ★これは dotenv の構文検査ではない。dotenv は `export FOO=1` も小文字のキーも読むが、
 *   本リポジトリの見本ファイルではそれらを許さない (存在検査・重複検査の母集合から
 *   外れたまま実効値だけを変えられる迂回になるため)。「見本に許す最小の書式」である。
 *
 * ★重複キーの値は**最初に現れた方**を記録する。dotenv は同一ファイル内の重複を
 *   **後に現れた方**で解決する。両者は食い違うので、重複が 1 件でもあると値の固定の検査は
 *   「実効値ではない値」を見ることになる。だから重複そのものを違反にする
 *   (どちらの解決順に合わせるかを選ばない)。
 *
 * 改行は CRLF / CR / LF のいずれでも行に割る (行末に CR を残さない)。
 * ★ただし**反証の表に CR 単独の行は無い** — 分割の規則が将来 CR 単独を落とすように弱っても
 *   赤くならない (保証範囲を誇張しないための注記)。
 * 値は前後の空白を落とさない (見本に書いてあるとおりを返す = 等号の後ろの空白は値の一部)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParseContents(string $contents): array
{
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */
    $values = [];
    $duplicateKeys = [];
    $malformedLineNumbers = [];

    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s*#/', $line) === 1) {
            continue;
        }
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            $malformedLineNumbers[] = $index + 1;

            continue;
        }
        $key = $matches[1];
        if (array_key_exists($key, $values)) {
            // 同じキーが 3 回以上でも、重複の一覧にはキー名を 1 度だけ載せる (診断の安定)。
            if (! in_array($key, $duplicateKeys, true)) {
                $duplicateKeys[] = $key;
            }

            continue;
        }
        $values[$key] = $matches[2];
    }

    return [
        'values' => $values,
        'duplicateKeys' => $duplicateKeys,
        'malformedLineNumbers' => $malformedLineNumbers,
    ];
}
/**
 * 値の固定: 裁定 AG-007 が名指しする 2 件。
 * 緩めるには家系の機能台帳側の裁定変更が要る (本リポジトリ単独では動かせない)。
 *
 * ★形式はキーと値の組の**リスト**にする (キー付きの連想配列にしない)。
 *   連想配列のリテラルは同じ定数の中の重複キーをコンパイル時に後勝ちで無音に潰すため、
 *   「行を足しただけに見える差分」で既存の固定を反転できてしまう。
 *   リストなら重複がそのまま残り、下の誠実性の検査が同じ機構で捕まえられる。
 */
const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
    ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
];

/**
 * 値の固定: 本リポジトリ固有の追加 (裁定で必須とされたものではない純増。個別に理由を書く)。
 * - ADMIN_MFA_REQUIRED=true: false にすると管理画面の二要素が実質無効になる。
 *   local の値が本番へ写る事故の側が危険なので、見本は安全側で固定する。
 * - MCP_STRICT_TRANSPORT=true: false にすると Origin を送らないクライアントを受け入れる
 *   (DNS 再バインドの面が広がる)。
 */
const ENV_EXAMPLE_VALUE_PINS_AICUE = [
    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true'],
    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true'],
];

/**
 * 値の固定の台帳の合成 (重複した組を保持したまま連結する)。
 *
 * @return list<array{key: string, value: string}>
 */
function envExampleValuePinEntries(): array
{
    return array_merge(ENV_EXAMPLE_VALUE_PINS_AG007_CORE, ENV_EXAMPLE_VALUE_PINS_AICUE);
}

/**
 * キー網羅の台帳。分類ごとに定数を分ける (平らな 1 本の配列にしない)。
test('台帳の誠実性: 値の固定とキー網羅の二重登録・台帳の中の重複が無い', function (): void {
    // 値の固定は存在の検査を含むので、キー網羅への二重登録は台帳の腐敗になる
    // (どちらを緩めたのか追えなくなる)。機械的に禁じる。
    $required = envExampleRequiredKeys();

    $pinKeys = [];
    foreach (envExampleValuePinEntries() as $entry) {
        $pinKeys[] = $entry['key'];
    }

    // 組のリスト形式は重複を保持するので、この一意性の検査 1 本で
    // 台帳の中 (同じ定数の中) と台帳の間 (2 つの定数にまたがる重複) の両方を捕まえられる。
    expect(array_values(array_unique($pinKeys)))->toBe($pinKeys);
    expect(array_values(array_intersect($required, $pinKeys)))->toBe([]);
    expect(array_values(array_unique($required)))->toBe($required);
});
```

---

## 概念設計

# 概念設計: gate-env-example-sync の正典 t2 追従 (aicue)

## 背景・課題

家系の機能台帳 lctl の feature `gate-env-example-sync` は 2026-08-22 に正典を **t2** へ確定した
(`design.settled_at: 2026-08-22T01:29:16+09:00` / `doc_sha: 97d72c394bcb`)。
台帳の aicue セルは `status=update_pending` / `version=t1` / `target_version=t2` である。

`.env.example` は本リポジトリでも**読み物ではなく生きた既定値**である。3 つの経路
(`composer setup` / composer.json の post-root-package-install / `scripts/setup-worktree.sh` の
復旧案内) が見本をそのまま `.env` にするため、見本の欠落・危険な値は文書の不備ではなく
**実環境の不備**になる。これを守る gate が `tests/Architecture/EnvExampleInvariantTest.php`
(477 行) である。

正典 t2 は t1 (値の固定 × キー網羅 × 行の形式 × 重複 + 台帳の誠実性) に 9 点を足した。
aicue は全 477 行の実読で **4 点を満たし 5 点を欠く**ことを確認した。

| 正典 t2 の追加分 | aicue の現状 | 判定 |
|---|---|---|
| i2 解析器を純粋関数と入出力に分ける | `envExampleParseContents()` / `envExampleParse()` に分離済み | 満たす |
| i10 反証データセット | R1〜R16 の 16 件をデータ駆動で保持 | 満たす |
| i11 `${VAR}` の自己参照・前方参照の禁止 (許可台帳つき既定拒否) | `collectUnresolvedEnvRefs()` + `ENV_EXTERNAL_REF_ALLOWLIST` | 満たす |
| i13 保証しない範囲の明記 | 冒頭 docblock に 4 項目 | 満たす |
| i3 制御文字を含む行を形式違反にする | 受理正規表現 `^([A-Z][A-Z0-9_]*)=(.*)$` が `\t` / `\x01` 等を値として素通し | **欠く** |
| i6 `APP_ENV=local` を値の固定に入れる | `ENV_EXAMPLE_REQUIRED_KEYS_SETUP` の存在確認のみ (値が動いても緑) | **欠く** |
| i7 台帳 entry ごとの由来を機械検査する | 分類ごとに定数 4 本、由来は定数の docblock の散文のみ | **欠く** |
| i9 種別ごと・分類ごとの件数申告と実件数の照合 | 無い (entry と見本のキーを同時に消せば静かに緑) | **欠く** |
| i12 実行時に読まれている env が見本でないことの確認 | `tests/` に該当する表明が 1 本も無い | **欠く** |

見本ファイル `.env.example` 自体は t2 の構造の不変条件を**今日そのまま満たしている**
(実測: 代入 81 行 / 形式違反 0 / 重複 0 / `APP_ENV=local` / 未解決の `${VAR}` 0 /
制御文字 0 / タブ 0)。**足りないのは検査側だけ**である。

観測点は `aicue@00e8eaaa`。`git diff 00e8eaaa..HEAD -- tests/Architecture/EnvExampleInvariantTest.php .env.example`
は空差分で、gate の最終変更は T213 (`3f94d6e`) = 台帳の reported ref と一致する。

## 改善アイデア

**`tests/Architecture/EnvExampleInvariantTest.php` 1 本を t2 の不変条件集合へ拡張する**。
足すのは欠けている 5 点だけで、正典が「表現形は不変条件に含めない」(s9) と定めているため、
現行の A 形 (分類ごとの定数 + グローバル関数の解析器) を保ったまま entry に項目を足す形で
充足させる。aigenba の B 形 (値オブジェクト 8 クラス + 契約) への組み替えは行わない
(能力の差ではなく語彙の差であり、8 クラスの新設は思考原則 2 に反する)。

5 点の充足方針:

1. **i3 (制御文字)**: 解析の純粋関数に制御文字の検出を足し、含む行を `malformedLineNumbers`
   へ落とす。判定順は 空行 → コメント → **制御文字** → 代入 → 形式違反 とし、制御文字の検査を
   代入判定より**前**に置く (`A=x\x01` を「正常な代入」として受理させない = fail-closed)。
   併せて現行の空行判定 `trim($line) === ''` (PHP の既定 charlist が `\0` と `\x0B` を含む) と
   コメント判定 `/^\s*#/` (`\s` が `\f` を含む) が制御文字だけの行・`\f#` で始まる行を
   検査の外へ逃がす穴を閉じる (`trim($line, " \t")` / `/^[ \t]*#/`)。
   タブ (`\x09`) は許容し、コメント行の中身は検査しない (どちらも保証範囲として明記する)。
2. **i6 (`APP_ENV=local`)**: 値の固定の台帳へ**移送**する。単に足すと現行の誠実性の検査
   (`array_intersect(必須キー, 固定キー)` が空であること) が赤くなるため、
   `ENV_EXAMPLE_REQUIRED_KEYS_SETUP` から `APP_ENV` を外して固定側へ移す 1 操作として行う。
   由来は正典 s4 の論理 (「見本は `APP_ENV=local` の開発シードだから `APP_DEBUG=true` を許す」
   という論拠側が固定されておらず黙って失効しうる) をそのまま書く。
3. **i7 (由来の機械検査)**: 台帳の entry を「キーの文字列」から
   「キー + 種別 + 分類 + 由来 (+ 固定値)」の構造へ組み替え、由来が空でないことを機械で見る。
   分類は現行の定数分割 (`SETUP` / `PRODUCTION_GUARD` / `INTEGRATION` / `OBJECT_STORAGE`) を
   そのまま分類名として使い、**分類名を entry 側に二重に書かない** (合成関数が付ける)。
4. **i9 (件数の申告)**: 種別ごと (値の固定 / 必須キー) と分類ごとの件数を台帳自身に申告させ、
   実件数との一致を要求する。反証がデータ駆動なので、**駆動元が空になったら落ちる床**
   (反証の件数の申告一致 + 必須のケース名の存在 + 両方向のケースの存在) を併置する。
5. **i12 (前提の実行時確認)**: `basename(app()->environmentFilePath())` が `.env.example`
   でないことを見る表明を 1 本足す。**許可する env 名の集合までは固定しない**
   (正当な env 名を足しただけで落ちるのは過剰である = 正典 i12 の但し書き)。

あわせて i13 の docblock を更新する: 正典 s4 が明記を求めた
「**見本をそのまま本番へ写す運用は検出しない (`APP_ENV` ごと写るため)**」と、
i3 / i12 で新たに生じる保証範囲の限界 (タブは許容 / コメント行の中身は見ない /
主張は「見本を env として選んでいない」ことに限る) を書く。

## 期待効果

- **使命への貢献**: 撮影 PWA が依存する 3 枚セット (no-store baseline / bfcache 秘匿 /
  Inertia 履歴暗号化) の土台は `SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` の既定値であり、
  見本がそのまま `.env` になる本リポジトリではこの gate が最初の防壁である。
  t2 追従で「台帳を静かに痩せさせる」「制御文字を混ぜて値を差し替える」
  「`APP_ENV` を書き換えて `APP_DEBUG=true` の論拠を失効させる」の 3 経路が塞がる。
- **具体的な改善見込み** (いずれも今日は緑のまま通る操作である):
  - `APP_ENV=local` を `production` に書き換える差分が赤くなる (現状は緑)
  - 台帳の entry を消して同時に見本のキーを消す差分が赤くなる (現状は緑)
  - 値の中に制御文字を混ぜた `SESSION_SECURE_COOKIE=true\x01` が赤くなる (現状は緑)
  - 由来を書かない entry の追加が赤くなる (現状は機械検査が無い)
  - テスト実行時に見本 env が読まれる配線に変わったら赤くなる (現状は無検査)
- **家系への貢献**: aicue セルが t1 → t2 へ進み、`update_pending` が解消する。

## 実装方針（概要）

変更は**アプリコード 0 / テスト 1 本 / 登録簿系 3 ファイル**である。

| ファイル | 変更 |
|---|---|
| `tests/Architecture/EnvExampleInvariantTest.php` | t2 の 5 点の追加 (唯一の実質変更) |
| `docs/template-divergence.md` | 新規登録 **D50** の追加 + 冒頭の「登録エントリ: 46 件」を 47 件へ |
| `tests/Support/TemplateDivergence/LedgerPins.php` | `DIVERGENCE_ENTRY_COUNT` 46→47 / `ADOPTION_DEBT_COUNT` 148→147 |
| `tests/Support/TemplateDivergence/adoption-debt.tsv` | gate ファイルの 1 行を削除 |
| `.env.example` | **変更なし** (t2 の構造の不変条件を既に満たすことを実測済み) |

### 乖離台帳の扱い (必須の段)

`tests/Architecture/EnvExampleInvariantTest.php` は
**`docs/template-fingerprints.json` の母集合に在り** (テンプレート側 sha256 `add11034…`)、
かつ **`tests/Support/TemplateDivergence/adoption-debt.tsv` の債務パス**である
(採用時のアプリ側 sha256 `d672f63c…` = **現在の内容と一致**)。
したがって本改修は債務パスを採用時の姿から動かすため、突合 gate の `mutatedDebtPaths` で
必ず赤くなる。app-design スキル 3-0 が示す 3 択のうち **(3) 意図的逸脱として登録を書き
債務から削る**を採る。理由は 2 つ:

- (1) 「採用時の姿へ戻す」は t2 追従そのものの放棄になる
- (2) 「テンプレートへ同期して債務から削る」は本リポジトリから実行できない
  (テンプレート側は同 feature で `version=t1` / `target_version=t2` の追従待ちであり、
  aicue が先に t2 へ進む形は家系の正典が想定している追従経路である)

`.env.example` / `adoption-debt.tsv` / `LedgerPins.php` / `docs/template-divergence.md` は
いずれも指紋台帳の母集合外なので、これら自身の変更に追加の登録は要らない
(`adoption-debt.tsv` は既に D34 で登録済み)。

### テストファースト

**赤くする対象は自分自身 (gate) である**。「先に赤くしてから本体を書く」を次の順で守る:

1. 反証データセットへ制御文字のケース (値の中 / キーの側 / 制御文字だけの行 / `\f#`) と
   正例 (タブを含む値 / タブだけの行 / 制御文字を含むコメント行) を足す → **赤**
   (現行の解析器はこれらを malformed にしない)
2. 台帳の誠実性の検査を新形式 (entry の構造 + 由来 + 件数の申告) へ書き換える → **赤**
   (台帳がまだ旧形式)
3. 誠実性の検査の**負例**を合成入力で足す → **赤** (検査器がまだ無い)
4. i6 / i12 の表明を足す → **赤** (`APP_ENV` は移送前・i12 の表明は未実装)
5. 解析器・台帳・移送を実装して**緑**へ
6. 検出力の裏取り: 制御文字の分岐を一時的に壊して 1 の反証が赤くなることを確認する
   (`red-first-evidence.md` に記録する)

## 制約・前提

- **AGENTS.md の禁止事項 3 (既存テストの削除・上書き)**: 既存の 7 本のテストは 1 本も消さない。
  誠実性の検査は**同じ 4 観点を新形式へ読み替えて温存する** (i8 の (1)〜(4) は t2 でも不変条件)。
  R1〜R16 の反証も名前ごと残す (番号を詰めず R17 以降を足す)。
- **AGENTS.md 「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」**が発火する
  (走査ロジック・判定条件・目録のすべてを変える)。よって
  (1) 負例と正例をテストファーストで / (2) 解決できない形を落とす分岐
  (`preg_match` の `false` を「制御文字なし」へ畳まない) / (3) 走査が空振りしていないことの検査
  (台帳の件数申告 + 反証の駆動元の床 + 解析の母集団が非空) / (4) docblock に走査対象と
  保証しないものを書く — の 4 点を同じ変更で揃える。
- **AGENTS.md 「静的検査 (gate) と走査器の共通規約」**のうち (b)(c)(d) が該当する
  ((a) は名前解決を伴わないため無関係、(e) は語彙一致の否定形を持たないため無関係)。
- **i14 (1 リポジトリ 1 ファイル)** は既に充足。同居する
  `tests/Architecture/BughuntEnvExampleContractTest.php` は boundary が testing グループへ
  除外した**別 feature の gate** (`.env.bughunt.local.example` の契約) なので別名並置には
  当たらず、統合しない。
- **受理規則が逆向きの解析器 2 本の同居を統合しない**。末尾の `collectUnresolvedEnvRefs()` は
  `export` つき・先頭空白つきを意図的に許容し、対象も見本 3 枚 (`.env.example` /
  `.env.bughunt.local.example` / `.env.testing`) である。正典 s11 は「他の commit 済み
  env ファイルへ広げるかは各 feature の判断」としており撤去は求められていないため、
  現状を維持し docblock の対比表も維持する。
- 新設する関数・定数には `envExample` / `ENV_EXAMPLE_` の prefix を付ける
  (Pest のグローバル空間で他ファイルと衝突させない。T213 と同じ規約)。
- `tests/` は PHPStan の解析対象外 (`phpstan.neon` の paths は app/config/database/routes) だが、
  型注記は将来の編入に耐える形で書く (T213 の既存方針を踏襲)。
- 実行環境の前提: Architecture lane は `Tests\TestCase` を extend し Laravel app 上で走るため
  `app()->environmentFilePath()` が呼べる。`phpunit.xml` は `<server name="APP_ENV" value="testing" force="true"/>`
  を持ち `.env.testing` が実在するので、i12 の表明は `.env.testing` を観測して緑になる。

## スコープ外

- **`.env.example` の内容変更**。t2 の構造の不変条件を既に満たす (実測済み)。
  `SECURITY_HSTS_ENABLED` / `SECURITY_CSP_ENABLED` を見本へ載せる判断は t2 の要求ではなく、
  現行 docblock が「この 2 件の欠落は検出しない」と明記する範囲を維持する。
- **本番用ひな形 (`.env.production-template`)**。正典 s1 が本 feature の対象外と確定し、
  かつ aicue は同ファイルを持たない。
- **禁止キーの台帳と本番 fail-fast コードの機械結線** (正典 s2 で範囲外)。
- **テスト lane の env の前提の固定** (`.env.testing` の値 / phpunit の宣言文)。
  正典の未決論点 q1 で帰属が未定であり、i12 は「読まれている env が見本でないこと」だけを主張する。
- **aigenba 形 (値オブジェクト 8 クラス) への組み替え**。正典 s9 が表現形を不変条件に含めない。
- **`app/Support/ProductionEnvGuard.php` との機械結線**。guard が読むのは config のキーであって
  環境変数名ではないため、結ぶには config の構文解析が要る (現行 docblock の判断を維持)。
- **lctl への `append_event`** と **`docs/TODO.md` の更新** (本設計フローの責務外)。
