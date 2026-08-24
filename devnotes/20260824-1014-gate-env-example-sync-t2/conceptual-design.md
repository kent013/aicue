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
制御文字 0 / TAB 0 / 不正 UTF-8 0)。**足りないのは検査側だけ**である。

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
   へ落とす。判定順は **空行 → コメント → 制御文字 → 代入 → 形式違反** とする。
   この順序は正典 i3 の文面そのままである — 「空白だけの行とコメント行は実効値を作らないので
   飛ばす。**それ以外の行は**素の代入行だけを受理する … 制御文字を含む行も形式違反にする」。
   制御文字の検査は**代入判定より前**に置く (`A=x\x01` を「正常な代入」として受理させない = fail-closed)。
   **判定範囲を明文化する** — 値になりうる行 (空行でもコメントでもない行) に対して:
   - (1) `mb_check_encoding($line, 'UTF-8')` が偽なら形式違反 (**不正 UTF-8 は fail-closed**)
   - (2) `/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]|\xC2[\x80-\x9F]/` に一致したら形式違反
     (C0 制御文字 + **TAB `\x09`** + DEL + **C1 域 U+0080〜U+009F**)。
     C1 は「UTF-8 では必ず `\xC2` + `\x80`〜`\x9F` の 2 バイト」であることを使い、
     (1) で妥当な UTF-8 だと確かめた後に見るので継続バイトとの衝突は起きない
   TAB が許されるのは **「空白だけの行」と「コメントの字下げ」だけ**である
   (i3 が「空白だけの行は飛ばす」「コメント行の字下げは各リポジトリの裁量」と定めているため。
   値になりうる行に TAB が現れたら形式違反)。実測で `.env.example` に TAB は 1 件も無く、
   厳しくしても見本の変更は 0 行である。
   **コメント行の中身は検査しない** (i3 が先に飛ばす対象と定めているため)。これは保証しない範囲として
   docblock に明記し、反証に「コメント行内の NUL は沈黙する」ケースを置いて挙動ごと固定する。
   併せて現行の空行判定 `trim($line) === ''` (PHP の既定 charlist が `\0` と `\x0B` を含む) と
   コメント判定 `/^\s*#/` (`\s` が `\f` を含む) が制御文字だけの行・`\f#` で始まる行を
   検査の外へ逃がす穴を閉じる (`trim($line, " \t")` / `/^[ \t]*#/`)。
   解析器の中の `expect()` は `Webmozart\Assert\Assert` へ置き換え、`preg_split()` の失敗と
   `preg_match()` の `false` は**例外で落とす** (「制御文字なし」「違反なし」へ畳まない = 走査器規約 (b))。
   **不可視文字 (U+200B / U+FEFF 等) は対象外**である (正典が求めるのは制御文字であり、
   不可視文字の無害化は prompt 防御の窓口の責務。保証しない範囲として明記する)。
2. **i6 (`APP_ENV=local`)**: 値の固定の台帳へ**移送**する。単に足すと現行の誠実性の検査
   (`array_intersect(必須キー, 固定キー)` が空であること) が赤くなるため、
   `ENV_EXAMPLE_REQUIRED_KEYS_SETUP` から `APP_ENV` を外して固定側へ移す 1 操作として行う。
   由来は正典 s4 の論理 (「見本は `APP_ENV=local` の開発シードだから `APP_DEBUG=true` を許す」
   という論拠側が固定されておらず黙って失効しうる) をそのまま書く。
3. **i7 (由来の機械検査)**: 台帳の entry を「キーの文字列」から**単一 shape の正規化された構造**
   へ組み替え、由来が空でないことを機械で見る。合成後の shape は
   `list<array{key: non-empty-string, kind: 'value_pin'|'required_key', classification: non-empty-string, origin: non-empty-string, value: ?string}>`
   に固定する (`value` は**常に存在するキー**とし、必須キー側は `null`。optional key にしない)。
   分類名は現行の定数分割をそのまま使い、**entry 側に二重に書かない** (合成関数が定数ごとに付ける)。
   値の固定側にも分類を定義する — `ag007_core` (家系の裁定 AG-007 の 2 件) /
   `canonical_t2` (正典 t2 が足した 1 件 = `APP_ENV`) / `aicue` (本リポジトリ固有の 2 件)。
4. **i9 (件数の申告)**: 台帳自身に**種別ごと**と**分類ごと**の件数を申告させ、正規化後の
   実件数との一致を要求する (map は ksort 後に完全一致で比べ、分類の増減・改名も落とす)。
   申告する実数は次のとおり —
   種別: `value_pin => 5` / `required_key => 35`。
   分類: `value_pin` = `ag007_core 2` / `canonical_t2 1` / `aicue 2`、
   `required_key` = `setup 8` / `production_guard 9` / `integration 14` / `object_storage 4`。
   併せて**分類別 map の合計が種別別 map と一致すること**も見る (片方だけを直した差分を落とす)。
   種別の合計だけを見る形にしない — それでは「AG-007 の 1 件を消して aicue 固有を 1 件足す」
   差分が合計値のまま緑になり、由来の入れ替えが無音になる。
   反証がデータ駆動なので、**駆動元が空になったら落ちる床**
   (反証の件数の申告一致 + 必須のケース名の存在 + 両方向のケースの存在 + 解析の母集団が非空) を併置する。
5. **i12 (前提の実行時確認)**: テスト実行時に読まれている env が見本でないことを**2 段**で見る —
   (1) 解決済み絶対パスが `realpath(base_path('.env.example'))` と一致しないこと、
   (2) `basename(app()->environmentFilePath())` が `.env.example` でないこと。
   (2) は別ディレクトリの同名見本を経由する形まで拒む「拾いすぎる側」の検査で、走査器規約 (b) の
   「見逃す方向へ倒すのは不可」に従って併置する。見本の `realpath()` が解決できないことは
   合格にせず**不合格**にする。**許可する env 名の集合までは固定しない**
   (正当な env 名を足しただけで落ちるのは過剰である = 正典 i12 の但し書き)。

あわせて i13 の docblock を更新する: 正典 s4 が明記を求めた
「**見本をそのまま本番へ写す運用は検出しない (`APP_ENV` ごと写るため)**」と、
i3 / i12 で新たに生じる保証範囲の限界を書く —
**TAB は「空白だけの行」と「コメントの字下げ」でのみ許容し、代入行 (値になりうる行) では形式違反である** /
コメント行の中身は見ない (制御文字も不正 UTF-8 も沈黙する) /
不可視文字 (U+200B / U+FEFF 等) は対象外 /
i12 の主張は「見本を env として選んでいない」ことに限る。

## 期待効果

- **使命への貢献**: 撮影 PWA が依存する 3 枚セット (no-store baseline / bfcache 秘匿 /
  Inertia 履歴暗号化) の土台は `SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` の既定値であり、
  見本がそのまま `.env` になる本リポジトリではこの gate が最初の防壁である。
  t2 追従で「台帳を静かに痩せさせる」「解釈差を招く不正形式 (制御文字・不正 UTF-8) を通す」
  「`APP_ENV` を書き換えて `APP_DEBUG=true` の論拠を失効させる」の 3 経路が塞がる。
- **具体的な改善見込み** (いずれも今日は緑のまま通る操作である):
  - `APP_ENV=local` を `production` に書き換える差分が赤くなる (現状は緑)
  - 台帳の entry を消して同時に見本のキーを消す差分が赤くなる (現状は緑)
  - 値の中に制御文字 (C0 / TAB / DEL / C1) を含む `SESSION_SECURE_COOKIE=true\x01` や、
    不正 UTF-8 を含む代入行が赤くなる (現状は緑)。
    効果は「dotenv・OS の環境変数・配備経路で同じ値として扱われる保証が無い不正形式を拒否する」
    ことであって、特定の攻撃を封じると主張するものではない
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
| `devnotes/{dir}/red-first-evidence.md` | 赤→緑の証跡 (T213 と同じ置き場。指紋台帳の母集合外なので乖離台帳への影響は無い) |

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

1. 反証データセットへ**負例** (値の中の NUL / キー側の SOH / DEL / **TAB を含む代入行** /
   C1 (U+0085) / 不正 UTF-8 / 制御文字だけの行 / `\f#` で始まる行) と、
   **正例** (TAB だけの行 / TAB で字下げしたコメント行 / コメント行の中の NUL /
   妥当な多バイト値) を足す → **赤**
   (現行の解析器は負例を malformed にしないため、負例の側が赤くなる)
2. 台帳の誠実性の検査を新形式 (entry の構造 + 由来 + 件数の申告) へ書き換える → **赤**
   (台帳がまだ旧形式)
3. 誠実性の検査の**負例**を合成入力で足す → **赤** (検査器がまだ無い)
4. i6 / i12 の表明を足す → **赤** (`APP_ENV` は移送前・i12 の表明は未実装)
5. 解析器・台帳・移送を実装して**緑**へ
6. 検出力の裏取り: 制御文字の分岐と件数照合の分岐を一時的に壊して 1・2 の検査が赤くなることを
   確認する (`devnotes/{dir}/red-first-evidence.md` に記録する)

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
