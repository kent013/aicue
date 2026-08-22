# Round 2: Round 1 指摘への対応

指摘 24 件のうち **23 件を対応**、**1 件に反論**した (実読で事実誤認を確認)。

## 反論する 1 件を先に示す — `response_status` は NOT NULL ではない

Round 1 施策 6 の [Critical]「migration では `response_status` が NOT NULL だが `claim()` は
`null` を insert している。初回 claim 自体が失敗する」は**事実誤認**である。
Round 1 のプロンプトへ初期 migration しか添付しなかった私の落ち度だが、
**後続 migration が nullable 化している**。全文を添付する:

```php
<?php

declare(strict_types=1);

use App\Enums\Idempotency\IdempotencyState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 冪等キーに状態列を足し、実行**前** claim を可能にする。
 *
 * - `state`: processing / completed / indeterminate (App\Enums\Idempotency\IdempotencyState)
 * - `response_status` を nullable 化する (claim 時点ではまだ応答が無いため)
 *
 * **既存行は削除せず `completed` へ backfill する**。現行実装は 2xx の JsonResponse しか
 * 保存しない (旧 IdempotentRequest::handle の `$response->isSuccessful()` 分岐) ため、
 * 既存行の決着は構造上すべて「成功」で既知である。ここを indeterminate に倒すと
 * デプロイ直後の正当な再送 (成功の再生) が最大 24h ぶん 409 に化ける。
 * 既存行を **削除**しないのは、デプロイを跨いだ再送が二重実行になるのを防ぐため。
 *
 * 既存の unique 2 本 (api_key_id / user_id の NULL distinct 前提) には触らない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->string('state', 24)->nullable()->after('request_hash');
            $table->unsignedSmallInteger('response_status')->nullable()->change();
        });

        // 既存行の backfill (決着は既知 = completed)
        DB::table('idempotency_keys')
            ->whereNull('state')
            ->update(['state' => IdempotencyState::Completed->value]);

        // backfill 後に NOT NULL 化する。**DB default は付けない**
        // (default があると「state を書き忘れた INSERT」が黙って completed になる)
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->string('state', 24)->nullable(false)->change();
        });

        // 期限切れ行の state 別 prune を index で支える
        // (prune は `where state = ? and expires_at <= ?` で回す)
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->index(['state', 'expires_at'], 'idempotency_keys_state_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex('idempotency_keys_state_expires_at_index');
            $table->dropColumn('state');
        });

        // down では response_status を NOT NULL に戻さない:
        // 戻す時点で claim 行 (response_status = null) が残っていると ALTER が失敗する。
        // ロールバックの安全性を優先し、nullable のままにする (前方互換)。
        //
        // ⚠ **この migration は実質 irreversible である**。down() はスキーマを
        //    「state 無し / response_status nullable」に戻すだけで、旧コードが前提とする
        //    「全行が完了応答を持つ」状態には戻せない (processing / indeterminate 行が
        //    response_status = null のまま残り、旧 replayResponse が null status で壊れる)。
        //    **旧コードへ戻す前に `DELETE FROM idempotency_keys WHERE response_status IS NULL`
        //    を人手で実行する**こと (削除しても失うのは未確定の claim だけで、
        //    再送は再実行になる = ロールバック時点では旧契約と同じ挙動)。
        //    手順は docs/api-idempotency.md の「ロールバック手順」節が正本。
    }
};

```

要点:
- `$table->unsignedSmallInteger('response_status')->nullable()->change();` が up() の冒頭にある
- `down()` のコメントが「戻す時点で claim 行 (response_status = null) が残っていると ALTER が失敗する」と書いており、
  **`null` の claim 行が正当な状態**であることを裏づけている

したがって前提は成立しており、`database/` を変更する必要は無い。
ただし**指摘の実質**（実効 schema を設計書から追えるべき）は正しいので、
詳細設計に「§前提の実読」節を新設し、後続 migration を名指しで参照するようにした (P1)。

## 特に大きく変えた点

1. **409 の種類を観測する** — 指摘のとおり `http_status` だけでは body 違いの
   `idempotency_conflict` でも全条件が成立して緑になる。観測へ `error_code` と `request_hash` を足し、
   敗者の `idempotency_in_progress` を完全一致で検査し、2 子の `request_hash` 一致も検査する。
2. **go token 方式** — `observed_go: bool` の自己申告をやめた。親は**全 ready の nonce 検証後に初めて**
   ランダムな go token を生成する。事前に渡らないので、go を読まずに正しい値は書けない。
3. **子側の環境実測** — `env -i` の退行を子自身が bootstrap 前に検出する段を足した (段 6)。
4. **cleanup の完全化** — FK を全数実読したところ、`organizations.laratrust_team_id` が
   **restrictOnDelete** で「組織を消せば全部消える」が成り立たず、`role_user.user_id` には FK が無い
   ことが分かった。設計のままでは**残留が出た**。主キーの DTO + FK 安全な削除順 + **8 表の残留ゼロ検査**にした。
5. **起動仕様の値化** — `ProbeLaunchSpec` / `ProbeProcessFactory` を入れ、偽物が合図を書けるようにした。
6. **プロセス抽象の分割** — `stopAndReap()` を `signalTerminate()` / `signalKill()` / `wait()` の 3 操作へ分け、
   「停止・kill・wait をそれぞれ要求した」が**順序込みで実際に固定できる**ようにした。
7. **単一の絶対 deadline** / **exit code 0 と stdout-out 一致の検査** / **秘密を argv に載せない** /
   **env 値の改行拒否** / **本数の数え直し**。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

Codex の指摘 24 件のうち **23 件を対応**、**1 件に反論**（実読で事実誤認を確認）。

---

## 施策 1

### [Critical] `present('entered-')` の glob が `.partial` を拾う
- 判断: **対応する**
- 根拠: 実在のバグ。`signal()` は `{target}.{random}.partial` を作るので、
  `entered-a.xxxx.partial` は `entered-*` に**一致する**。二重実行の判定が壊れる。
- 対応内容: prefix の glob をやめ、**親が割り当てた 2 つの完成ファイル名だけ**を調べる形に変えた
  (`present(list<string> $names)`)。`.partial` は構造的に候補へ入らない。
  併せて `entered` の中身も nonce + go token と照合する。

### [Critical] `ready` は存在だけでは不十分 (空・別 child・誤 nonce でも通る)
- 判断: **対応する**
- 対応内容: `ready-{childId}` の中身をその子へ割り当てた nonce と**完全一致**で検査する。
  一致しなければ go を出さない。

### [Warning] `path()` が `/` や `..` を受け入れてディレクトリ外を指せる
- 判断: **対応する**
- 対応内容: 合図名を**固定語彙** (`ready` / `go` / `entered` / `release` / `out`) と
  child ID の正規表現 (`/\A[a-z]\z/`) の組合せに限定し、パス区切りを含む名前を拒否する。

---

## 施策 2

### [Critical] cleanup が「cascade を利用する」止まりで残留ゼロの根拠が無い
- 判断: **対応する**
- 根拠: 指摘のとおり。実読で FK を全数確認したところ、
  `organizations.laratrust_team_id` は **restrictOnDelete** であり、
  「組織を消せば全部消える」は**成り立たない** (teams を先に消せない / 後に消す必要がある)。
  さらに `role_user.user_id` には FK が無い (polymorphic) ため、利用者を消しても連鎖しない。
  設計のまま実装すると**残留が出る**。
- 対応内容: `ConcurrencyFixtureKeys`（作った主キーを持つ値オブジェクト）を新設し、
  **FK 安全な削除順を明記**した。実読で確認した FK は次のとおり:
  - `organizations.laratrust_team_id` → `teams` **restrictOnDelete**（組織 → teams の順）
  - `organization_user` / `custom_teams` / `api_keys` の `organization_id` → cascade
  - `idempotency_keys.api_key_id` → cascade
  - `users.current_organization_id` → **nullOnDelete**
  - `role_user.team_id` → cascade（`role_user.user_id` には FK 無し）
  - `organizations` は softDeletes → **query builder で物理削除**する
  そのうえで **cleanup 後に 8 表の残留ゼロを別名接続から検査**する（cascade の当て推量を検査に置き換える）。

### [Warning] `create()` の callback 失敗時に別名接続の disconnect/purge が保証されない
- 判断: **対応する**
- 対応内容: `create()` の `finally` で既定接続名を戻すのに加え、
  **失敗時は別名接続を disconnect + purge** し、成功時だけ後続の読み取り・cleanup 用に維持する。

### [Warning] `issueApiKey()` は Factory ではない
- 判断: **対応する（記述の是正）**
- 根拠: 指摘のとおり。`issueApiKey()` は `ApiKey::generatePlainKey()` +
  `ApiKey::createForOrganization()` を使う。プレーンキーの生成規則がドメイン側にあるため
  Factory では作れない（Factory で作るとテストが本物のキー形式を持てない）。
- 対応内容: 「**Factory または既存の正規ドメイン生成 helper**」と正確に書き直し、
  `issueApiKey` を使う理由も添えた。`Model::create()` の手組みは引き続きしない。

---

## 施策 3

### [Critical] 409 の**種類**を観測していない（conflict でも緑になる）
- 判断: **対応する**
- 根拠: 決定的な指摘。`IdempotentRequest` は 409 を 3 コード返す
  （`idempotency_conflict` / `idempotency_in_progress` / `idempotency_indeterminate`）。
  status だけを見ると、body 違いによる **conflict でも同じ観測になる**。
  本テストが主張したいのは `in_progress` なので、これでは証明になっていない。
- 対応内容: 観測へ `error_code` / `request_hash` / `route_name` / `uri` / `api_key_id` / `go_token` を追加し、
  敗者について `idempotency_in_progress` を**完全一致**で検査する。
  さらに 2 子の `request_hash` が**一致する**ことも検査する（body 違いを構造的に排除する）。

### [Critical] `observed_go` が自己申告にすぎない
- 判断: **対応する**
- 根拠: そのとおり。子が go を待たずに走って最後に `true` と書いても親は検出できない。
- 対応内容: **go token 方式**へ変更した。親は**全 ready の nonce を検証した後に初めて**
  ランダムな go token を生成して `go` へ書く。子はそれを読み、`entered` と最終観測へ**含める**。
  **go token は事前の引数で渡さない**ので、go を待たずに正しい値を書くことはできない。

### [Warning] `entered_handler` と `handler_executions` の整合が未定義
- 判断: **対応する**
- 対応内容: 勝者 `entered=true / executions=1`、敗者 `entered=false / executions=0` を
  見本テストで固定する。

---

## 施策 4

### [Critical] `ALLOWED_PROCESS_ENV_KEYS` が宣言だけで、子側の実測検査が無い
- 判断: **対応する**
- 根拠: 指摘のとおり。`env -i` が壊れると親の `DB_URL` が子へ継承され、
  phpdotenv は immutable なので**既存の環境変数が env ファイルより優先**される。
  接続前ガードを迂回しうる実在の穴である。
  なお `FakeWiringProbeRunner` の probe は既に「子が実際に受け取った環境を自分で観測して返す」
  作法を持っており、そちらへ合わせるのが自然だった（踏襲漏れ）。
- 対応内容: 子の**最初期（autoload の直後・bootstrap の前）**に `getenv()` のキー集合を取り、
  許可 3 キーとの**完全一致**でなければ非ゼロ終了する。
  失敗経路の検査に「継承された `DB_URL` / `DB_DATABASE` を拒否する」負例を追加した。

### [Critical] 見本テストが DB 座標のうち database 名しか比較していない
- 判断: **対応する**
- 対応内容: driver / host / port / database / username / charset / sslmode を
  親が渡した値と**完全一致**で検査する。`url` は空文字以外を拒否し、非文字列も fail-closed にする。

### [Critical] `?array $processes` の注入では偽 process が workspace を知れない
- 判断: **対応する**
- 根拠: 実装できない設計だった。偽 process は ready / entered / out を**書く**必要があるので、
  barrier のディレクトリを知らなければ失敗経路検査 #4〜#7 が組めない。
- 対応内容: `ProbeLaunchSpec`（workspace / childId / nonce / 引数）と
  `ProbeProcessFactory`（spec を受けて `ProbeProcess` を作る）を新設し、
  runner は factory を注入で受け取る形にした。偽物も同じ spec を受け取る。

### [Critical] 正常終了コードの検査が無い
- 判断: **対応する**
- 対応内容: 回収条件に「**両 process の exit code が 0**」と
  「**stdout の JSON と原子的 out ファイルの中身が一致**」を追加した。

### [Warning] 待機ごとに締切が更新され、総時間が締切を大幅に超える
- 判断: **対応する**
- 根拠: そのとおり。「全体の締切」と書きながら実装は段ごとの締切だった。
- 対応内容: runner 開始時に**単一の絶対 deadline**（単調時計）を作り、
  すべての待機・release 後の終了待ち・回収へ**残り時間**を渡す。

### [Warning] `stopAndReap()` 一操作では「停止・kill・wait を要求した」と言えない
- 判断: **対応する（保証を狭めるのではなく、操作を分ける）**
- 対応内容: `ProbeProcess` を `signalTerminate()` / `signalKill()` / `wait(float): ?int` の
  3 操作へ分けた。失敗経路検査は**呼ばれた順序**を固定できる。
  これで保証文（「runner が停止・kill・wait をそれぞれ要求する」）が実際に成立する。

---

## 施策 5

### [Critical] route closure が `$nonce` / `$timeoutSeconds` を `use` していない
- 判断: **対応する**
- 根拠: 素のバグ。指摘どおり未定義変数になる。
- 対応内容: `use` に全依存を明示した（go token も渡す）。

### [Critical] `loadEnvironmentFrom()` は env をその場で解析しない
- 判断: **対応する**
- 根拠: そのとおり。bootstrap 前の DB 名検査に使う値の取得手段が未定義だった。
- 対応内容: 子は bootstrap 前に**専用 env ファイルを自前の厳格パーサで解析**し、
  キー集合・型・DB 名を検証する。書き手（親）側も
  **値に改行 / CR を含む場合は書かずに例外**にし、`KEY="value"`（`"` と `\` をエスケープ）の
  1 形式だけを使う（キー注入を構造的に不可能にする）。

### [Critical] cache の array 固定は **ready を出す前**に子自身が検査すべき
- 判断: **対応する**
- 根拠: 正典 v1 の要素 (3) は「**守りたい層以外を無効化してから測る**」であり、
  測った後に「実は無効化できていなかった」と分かって赤くなるのでは要素を満たさない。
  指摘が正典の読みとして正しい。
- 対応内容: bootstrap 後・request 前に、既定 store と解決 driver がともに `array` であること、
  および実効 DB 座標が宣言と一致することを検査し、**違えば ready を出さずに非ゼロ終了**する。

### [Warning] plain API key を argv で渡すとプロセス一覧から読める
- 判断: **対応する**
- 対応内容: 秘密（plain API key / body）は **0600 の入力ファイル**へ置き、
  argv には秘密でない値（workspace パス・childId・入力ファイル名）だけを載せる。

### [Warning] 「本番同等の middleware 列」は言い過ぎ（throttle / project-in-org / ability が無い）
- 判断: **対応する**
- 対応内容: 「**冪等 middleware の前提を満たす最小 probe 経路**」へ表現を狭めた。
  不足 middleware を足す案は採らない（測りたいのは claim の調停であり、
  throttle を挟むと 2 本の到達が乱れて目的に反する）。この判断も明記した。

---

## 施策 6

### [Critical] `[201, 409]` と handler 合計 1 では in_progress を証明できない
- 判断: **対応する**（施策 3 と同一の対応）

### [Critical] `response_status` が NOT NULL なので初回 claim が失敗するのでは
- 判断: **反論する（事実誤認）**
- 根拠: **実読で確認した**。プロンプトへ添付したのは初期 migration だけだったため
  そう見えたが、後続の
  `database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php` が
  ```php
  $table->unsignedSmallInteger('response_status')->nullable()->change();
  ```
  で **nullable 化している**。同 migration の `down()` にも
  「戻す時点で claim 行 (response_status = null) が残っていると ALTER が失敗する」
  という記述があり、`null` の claim 行が正当な状態であることを裏づけている。
  したがって前提は成立しており、`database/` を変更する必要は無い。
- 対応内容: **指摘の実質は正しい**（実効 schema を設計書から追えるべき）ので、
  詳細設計に「実効 schema」節を追加し、後続 migration を名指しで参照するようにした。

### [Critical] cleanup closure が省略記号のまま
- 判断: **対応する**（施策 2 と同一の対応。削除順・条件・根拠・残留検査を展開した）

### [Warning] 「最大 4 接続」は suite 全体では誤り
- 判断: **対応する（記述の是正）**
- 根拠: 私の記述は「本テストが増やす接続」の話だったが、
  「同時最大 4 本」と書けば suite 全体の話に読める。曖昧だった。
- 対応内容: 「**当該 worker 内で最大 4 本**（既定 1 + 別名 1 + 子 2）。
  suite 全体では他 worker の接続にこの**うち 3 本**（別名 1 + 子 2）が加算される」と書き分けた。

---

## 施策 7

### [Critical] 現在の注入点では fake が workspace を知れず #4〜#7 を実装できない
- 判断: **対応する**（施策 4 の `ProbeLaunchSpec` / `ProbeProcessFactory` 導入で解消）

### [Warning] 「存在するが読めない」テストは root 実行では chmod 000 でも読めて不安定
- 判断: **対応する**
- 根拠: devcontainer は root 実行になりうるので実在のリスク。
- 対応内容: `ProcessBarrier` の読み取りを**注入可能な小さな読み手**へ分離し
  （コンストラクタで `callable(string): string|false` を受ける。既定は `file_get_contents`）、
  `false` を決定的に返す偽物で検査する。権限に依存しない。

### [Warning] 失敗経路が不足
- 判断: **対応する（全 9 件を追加）**
- 対応内容: ready nonce 不一致 / 未知 child ID の entered / `.partial` を完成扱いしない /
  go token 未観測 / stdout と out の不一致 / 非ゼロ終了 / 全体 deadline が段ごとに更新されない /
  未知 `DB_*` の混入拒否 / env 値の改行によるキー注入拒否 — を検査表へ追加した（計 21 件）。

---

## 施策 8

### [Warning] docblock の是正は in_progress の完全一致観測が入ってからにすべき
- 判断: **対応する**
- 対応内容: 施策 8 に「**施策 3・5・6 の完了を前提とする**」と依存を明記した
  （施策一覧の順序でも後ろに置く）。

---

## 施策 9

### [Warning] ファイル数の台帳が内部で一致していない
- 判断: **対応する**
- 根拠: そのとおり。`OutOfTransactionFixturesTest.php` が施策一覧に無く、本数も合っていなかった。
- 対応内容: 新規・変更ファイルを**全件列挙し直し**、
  fingerprint 判定・実装モード・本数の記述をすべて同じ母集団から書き直した
  （新規 16 本 = 支援 13 + テスト 3 / 変更 3 本）。

### [Suggestion] D7 の再判定記録に実更新日と feature revision を併記
- 判断: **対応する**
- 対応内容: feature revision (`14-3117f6369f21`) を再判定記録へ併記する形にした。


---

## 修正後の詳細設計 (全文)

# 詳細設計: process-concurrency-harness-adoption

家系の機能台帳 lctl の feature `process-concurrency-test-harness`
(feature_revision `14-3117f6369f21` / canonical_version **v1**) への aicue 追従。

---

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

> 本設計に直接効くのは **1**(テスト必須) と **2**(PHPStan を緩めない) と **3**(dev DB 保護)。
> 4〜8 は UI / LLM / HTTP 応答の規約で、本設計は `app/` を触らないため該当しない。
> 9 は成果物を devnotes 配下のファイルとして出すことで満たす。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。解析対象は `app / config / database / routes` で
  **`tests` は含まない**（`phpstan.neon`）。本設計は `phpstan.neon` を**変更しない**（§施策 9）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは Factory または既存の正規ドメイン生成 helper で生成**（`Model::create()` の手組み禁止）。
  本設計は `createOrganizationWithOwner()`（Factory + `OrganizationProvisioningService`）と
  `issueApiKey()`（`ApiKey::generatePlainKey()` + `ApiKey::createForOrganization()`）を使う。
  API キーは**プレーンキーの生成規則がドメイン側にある**ため Factory では作れない
  （Factory で作るとテストが本物のキー形式を持てず、実 guard を通せない）
- 新モデルの追加は無し（Factory の新設も無い）
- **DTO パターン**（本設計は HTTP 応答を作らないので JsonResource は登場しない。
  子との受け渡しは値オブジェクト + **実行時 fail-closed 検証**で担保する）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12
- 禁止する文（`echo` 等）の走査は `tests/` にも及ぶ。実行スクリプトの標準出力は `fwrite(STDOUT, …)` を使う
- 全 PHP ファイルに `declare(strict_types=1);`

---

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー Round 3 で **APPROVED**）

### 正典 v1 の不変条件（全 6 要素）と本設計での実現先

| # | 正典が要求すること | 実現する施策 |
|---|---|---|
| (1) | フレームワークを自前で起動し、**引数で受けた ready / go ファイル**で親と同期してから対象処理を叩く実行スクリプト | 施策 1・5 |
| (2) | フィクスチャを**テストの transaction の外**（別名の独立接続）に作り、**末尾で明示的に片付ける** | 施策 2 |
| (3) | **守りたい層以外を意図的に無効化**してから測る（子のキャッシュを配列固定にし、プロセス間でアプリ側ロックが共有されない状態で DB 層だけで守れることを確かめる） | 施策 4・5 |
| (4) | 合図待ちのループで**ファイル状態のキャッシュを毎回捨てる** | 施策 1 |
| (5) | 待ちには**締切**を置き、超えたら例外にする | 施策 1・4 |
| (6) | 重いので**実プロセス版は 1 本に絞り**、細かい分岐は同一プロセスのテストへ回す | 施策 6・8 |

### 正典が「含まない」と明記しているもの（= スコープ外の根拠）

- 何を並行で守るかという個別の不変条件（ハーネスは**証明する道具**であって主張ではない）
- **同一プロセス内の並行テスト**（DB の一意制約の実効性は証明できない**別層**）
- テスト実行を直列化して衝突を避ける仕組み（`global-test-lock` — こちらは逆に**意図的に衝突させる**）
- テストレーンの構成（`pest-lane-wiring` / `php-test-pgsql-lane`）

---

## 前提の実読（設計が依存している既存の事実）

実装前に前提が崩れていないか確認できるよう、依存している事実を出典つきで固定する。

| # | 前提 | 出典（実読済み） |
|---|---|---|
| P1 | `idempotency_keys.response_status` は **nullable** である（claim 行は `null` で入る） | `database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php` が `$table->unsignedSmallInteger('response_status')->nullable()->change();` を実行。同 migration の `down()` も「claim 行 (response_status = null) が残っていると ALTER が失敗する」と書き、`null` が正当な状態であることを裏づける。**初期 migration だけを見ると NOT NULL に見えるので注意** |
| P2 | claim の調停者は **unique 2 本のみ**（cache ロック等の補助機構を持たない） | `App\Http\Middleware\IdempotentRequest::claim()` の docblock と実装（`insertOrIgnore` → `inserted === 1` で claimed） |
| P3 | 409 は **3 コード**ある（`idempotency_conflict` / `idempotency_in_progress` / `idempotency_indeterminate`） | `App\Enums\ApiErrorCode`。**status だけでは in_progress を証明できない**ことの根拠 |
| P4 | claim のスコープは `(api_key_id, route_name, key)` | 同 middleware の docblock + `create_idempotency_keys_table` の unique 2 本 |
| P5 | テストレーンは `DB_URL` を**空に固定**している | `phpunit.xml` の `<server name="DB_URL" value="" force="true"/>` |
| P6 | テスト DB 名は `<slug>_test_<worktree-hash>`（paratest は更に `_test_<token>`）で、allowlist と dev denylist を持つ単一点ガードがある | `tests/bootstrap.php` + `Tests\Support\Ci\TestDatabaseEnv` |
| P7 | 子プロセスの回収規約の先行例がある（`env -i` / 専用 env ファイル / 0700・0600 / 締切 / fail-closed / finally） | `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` と `fake-wiring-probe.php`（`useEnvironmentPath()` → `loadEnvironmentFrom()` → `bootstrap()` の順） |
| P8 | `getStore()` は境界迂回として通常経路 0 件に固定されている | `tests/Architecture/CachePayloadPlainDataGateTest.php`（cache の観測に `getStore()` を使わない根拠） |
| P9 | 検体の FK は下表のとおり（cascade の当て推量をしない） | §施策 2 の FK 表 |

---

## 施策一覧

### 新規ファイル（16 本）

| # | パス | 施策 |
|---|---|---|
| 1 | `tests/Support/Concurrency/ProcessBarrier.php` | 1 |
| 2 | `tests/Support/Concurrency/BarrierTimeoutException.php` | 1 |
| 3 | `tests/Support/Concurrency/ConcurrencyProtocolException.php` | 1 |
| 4 | `tests/Support/Concurrency/OutOfTransactionFixtures.php` | 2 |
| 5 | `tests/Support/Concurrency/ConcurrencyFixtureKeys.php` | 2 |
| 6 | `tests/Support/Concurrency/ConcurrentProbeObservation.php` | 3 |
| 7 | `tests/Support/Concurrency/ProbeEnvironment.php` | 4 |
| 8 | `tests/Support/Concurrency/ProbeLaunchSpec.php` | 4 |
| 9 | `tests/Support/Concurrency/ProbeProcess.php` | 4 |
| 10 | `tests/Support/Concurrency/ProbeProcessFactory.php` | 4 |
| 11 | `tests/Support/Concurrency/SymfonyProbeProcess.php` | 4 |
| 12 | `tests/Support/Concurrency/SymfonyProbeProcessFactory.php` | 4 |
| 13 | `tests/Support/Concurrency/ConcurrencyProbeRunner.php` | 4 |
| 14 | `tests/Support/Concurrency/idempotency-claim-probe.php` | 5 |
| 15 | `tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php` | 6 |
| 16 | `tests/Feature/Concurrency/OutOfTransactionFixturesTest.php` | 2 |
| 17 | `tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php` | 7 |

> 内訳: `tests/Support/Concurrency` に **14 本**（うち 1 本は実行スクリプト）、
> `tests/Feature/Concurrency` に **2 本**、`tests/Unit/Support/Concurrency` に **1 本** = **17 本**。

### 変更ファイル（3 本）

| # | パス | 変更内容 | 施策 |
|---|---|---|---|
| 1 | `tests/Feature/Api/IdempotencyConcurrentClaimTest.php` | 冒頭 docblock のみ | 8 |
| 2 | `docs/template-divergence.md` | D7 の中へ再判定の記録（登録の増減なし） | 9 |
| 3 | `docs/architecture.md` | テスト機構の節へ 1〜2 行 | 9 |

**アプリコード（`app/` / `routes/` / `config/` / `database/`）の変更は 0 件。**

### 施策の依存順

```
施策 1 (barrier) ─┐
施策 3 (観測の型) ─┼→ 施策 4 (runner) → 施策 5 (probe) → 施策 6 (見本テスト) → 施策 8 (docblock 是正)
施策 2 (検体)   ─┘                                    ↘ 施策 7 (失敗経路)
施策 9 (台帳・文書) は最後
```

施策 8 は**施策 3・5・6 の完了を前提**にする
（`idempotency_in_progress` の完全一致観測が入って初めて docblock の主張が成立するため）。

---

## 施策 1: 合図の待ち合わせ（barrier）と締切

### 変更箇所

- 新規: `tests/Support/Concurrency/ProcessBarrier.php`
- 新規: `tests/Support/Concurrency/BarrierTimeoutException.php`
- 新規: `tests/Support/Concurrency/ConcurrencyProtocolException.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 7 が本部品の失敗経路を固定する

### 設計

合図は**ファイルの存在と中身**で表す。正典 v1 の要素 (1)(4)(5) を 1 クラスに閉じる。

**規律 6 点**（(5)(6) は正典 v1 に無い後発の規律。(5) は aigenba が持つ）:

1. **子ごとに分ける**: `ready-{childId}` は子の数だけ、`go` は 1 つだけ
2. **中身を照合する**: 存在だけでは足りない（空・別 child・誤 nonce でも通ってしまう）
3. **毎回 `clearstatcache()`**: 捨てないと合図に気付くのが遅れ、2 本の実行が重ならない（要素 (4)）
4. **締切は単調時計**: `hrtime(true)` で測る（壁時計は NTP 補正で戻りうる）
5. **書きかけを見せない**: 一時ファイルへ書いてから `rename()`（同一 FS 上の rename は原子的）
6. **名前は固定語彙のみ**: 任意文字列を受け付けない（`/` や `..` でディレクトリ外を指させない）

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Webmozart\Assert\Assert;

/**
 * 実プロセス並行テストの合図の待ち合わせ (正典 v1 の要素 (1)(4)(5))。
 *
 * 規律 6 点:
 * 1. ready は**子ごと**に分ける (共有 ready だと片方だけ準備できた状態で go を出せてしまい、
 *    「全員の準備を確認してから同一の合図で解き放つ」という最重要前提が**緑のまま**壊れる)
 * 2. 存在だけでなく**中身を照合**する (空・別 child・誤 nonce を通さない)
 * 3. 待ちのループでは**毎回 clearstatcache()** する — 捨てないと合図に気付くのが遅れ、
 *    2 本の実行が重ならず並行テストの意味が消える (正典が名指しする作法)
 * 4. 締切は**単調時計** (hrtime) で測る (壁時計は補正で戻りうる)
 * 5. 合図は一時ファイル → rename の 2 段で置く (書きかけを相手に見せない)
 * 6. 合図名は**固定語彙 + child ID の正規表現**だけ (パス区切りを含む名前を作らせない)
 *
 * ★読み取りは**注入可能な読み手**越しに行う。`file_get_contents() === false` を
 *   決定的に再現するためで、権限 (chmod 000) に依存する検査は root 実行で不安定になる。
 *
 * **保証しないもの**: 合図の順序関係だけを保証する。実際に処理が重なったかどうかは
 * 呼び出し側 (ConcurrencyProbeRunner) が entered / release の 3 段で構成する。
 */
final class ProcessBarrier
{
    /** 待ちのポーリング間隔 (マイクロ秒) */
    private const int POLL_INTERVAL_MICROSECONDS = 1_000;

    /** 合図の固定語彙 (これ以外の名前は作れない) */
    public const array SIGNAL_KINDS = ['ready', 'go', 'entered', 'release', 'out'];

    /** child ID の形 (パス区切りを構造的に排除する) */
    public const string CHILD_ID_PATTERN = '/\A[a-z]\z/';

    /** @param (callable(string): string|false)|null $reader 既定は file_get_contents */
    public function __construct(
        private readonly string $directory,
        private readonly mixed $reader = null,
    ) {
        Assert::directory($directory);
    }

    /** 合図名を組み立てる (固定語彙 + child ID のみ。任意文字列を受けない) */
    public function name(string $kind, ?string $childId = null): string
    {
        Assert::oneOf($kind, self::SIGNAL_KINDS);

        if ($childId === null) {
            return $kind;
        }

        Assert::regex($childId, self::CHILD_ID_PATTERN);

        return $kind.'-'.$childId;
    }

    /** 合図を置く (一時ファイル → rename。書きかけを相手に見せない) */
    public function signal(string $name, string $payload): void
    {
        $target = $this->path($name);

        // ★`.partial` は**別のディレクトリ**へ置く。同じディレクトリに置くと
        //   完成ファイルの列挙 (glob) が書きかけを拾う穴になる (実際に踏みかけた)。
        //   本設計では列挙自体を「割り当て済みの完成名だけを調べる」形にしてあるが、
        //   置き場所も分けて二重に塞ぐ。
        $temporary = $this->partialDirectory().'/'.bin2hex(random_bytes(8));

        if (file_put_contents($temporary, $payload) !== strlen($payload)) {
            throw ConcurrencyProtocolException::signalNotWritten($name);
        }

        if (! rename($temporary, $target)) {
            @unlink($temporary);
            throw ConcurrencyProtocolException::signalNotPlaced($name);
        }
    }

    /**
     * 合図が現れるまで待ち、その中身を返す。
     *
     * @param  float  $remainingSeconds runner が持つ**単一の絶対 deadline** からの残り時間
     * @param  (callable(): void)|null  $abortIf 待機中に毎周回呼ぶ中断条件
     *   (二重実行の検出・子の異常終了など。呼び先が例外を投げれば締切を待たずに抜ける)
     *
     * @throws BarrierTimeoutException 締切を超えた
     * @throws ConcurrencyProtocolException 合図はあるのに読めない
     */
    public function await(string $name, float $remainingSeconds, ?callable $abortIf = null): string
    {
        Assert::greaterThan($remainingSeconds, 0.0);

        $deadline = hrtime(true) + (int) ($remainingSeconds * 1_000_000_000);

        while (true) {
            if ($abortIf !== null) {
                $abortIf();
            }

            // ★毎周回捨てる。捨てないと合図に気付くのが遅れ、2 本の実行が重ならない。
            clearstatcache(true, $this->path($name));

            if (is_file($this->path($name))) {
                return $this->read($name);
            }

            if (hrtime(true) >= $deadline) {
                throw BarrierTimeoutException::waitingFor($name, $remainingSeconds);
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * 合図が現れているか (待たずに 1 回だけ見る)。
     *
     * ★引数は**親が割り当てた完成名の一覧**である。prefix の glob は採らない —
     *   `entered-a` の書きかけ (`.partial`) を拾って二重実行の判定が壊れるため。
     *
     * @param  list<string>  $names 調べる完成名 (割り当て済みのものだけ)
     * @return list<string>  現れている名前
     */
    public function present(array $names): array
    {
        $found = [];
        foreach ($names as $name) {
            clearstatcache(true, $this->path($name));
            if (is_file($this->path($name))) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * 合図を読む。**読めない合図は空として通さず例外**にする (fail-closed)。
     *
     * 合図はあるのに読めない = 観測が成立していない。空として通すと後続の照合が
     * 別の理由で落ちて原因が隠れる。
     */
    private function read(string $name): string
    {
        $reader = $this->reader ?? file_get_contents(...);
        $contents = $reader($this->path($name));

        if ($contents === false) {
            throw ConcurrencyProtocolException::signalUnreadable($name);
        }

        return $contents;
    }

    public function path(string $name): string { /* directory . '/' . $name */ }

    private function partialDirectory(): string { /* directory . '/.partial' (作成は構築時) */ }
}
```

**例外の型を 2 つに分ける理由**:

- `BarrierTimeoutException`（`RuntimeException` 継承）: **締切を超えた**
- `ConcurrencyProtocolException`（`RuntimeException` 継承）: **プロトコルが破られた**
  - `doubleExecution()` — 探している退行そのもの。**締切超過という紛らわしい形で出さない**
  - `childDiedEarly(string $childId, int $exitCode, string $stderr)`
  - `identityMismatch()` / `goTokenMismatch()` / `unexpectedObservation()`
  - `signalUnreadable()` / `signalNotWritten()` / `signalNotPlaced()`

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`present()` は `list<string>`）
- [x] null 安全（`Assert::directory` / `Assert::oneOf` / `Assert::regex` / `Assert::greaterThan`）
- [x] 配列返却は `list<string>` に限定し、値は型付き例外で扱う
- [x] Generics の型パラメータが正しい（`@param (callable(string): string|false)|null $reader`）

> `tests` は PHPStan の解析対象外なので、これは**静的検査による保証ではなく規律**である。
> 実効的な保証は施策 7 の失敗経路検査が担う（この境界は概念レビューで合意済み）。

### テスト計画

- [x] 施策 7 が固定（`ProcessBarrier` 分）:
      締切で例外 / 読めない合図を通さない / 中断条件で締切を待たずに抜ける /
      `.partial` を完成扱いしない / 固定語彙外・不正 child ID の名前を拒否
- [x] 個別の `DatabaseTransactions` を使わない（DB に触らない）

### リスク

- ポーリング間隔（1ms）が短すぎると CPU を食う。子 2 本・数秒の待ちなので許容範囲。
- `.partial` を別ディレクトリへ置くため、`rename()` が**同一 FS 内**であることに依存する
  （同じ一時ディレクトリの直下なので成立する）。

---

## 施策 2: transaction 外の検体置き場と確実な後始末

### 変更箇所

- 新規: `tests/Support/Concurrency/OutOfTransactionFixtures.php`
- 新規: `tests/Support/Concurrency/ConcurrencyFixtureKeys.php`
- 新規: `tests/Feature/Concurrency/OutOfTransactionFixturesTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `OutOfTransactionFixturesTest.php`（本施策に含む）+ 施策 6 が実利用する

### 設計 2-a: 検体を transaction の外へ出す

`RefreshDatabase` が検体を**未コミットの transaction の中**に置くため、子プロセスからは見えない。
既定接続の設定を**複製した別名接続**を作り、**閉じた区間だけ**既定接続をそこへ差し替えて生成し、
その接続の**明示トランザクションで commit** する（正典 v1 の要素 (2)）。

```php
/**
 * テストの transaction の外に検体を作る (正典 v1 の要素 (2))。
 *
 * ★**片付けは呼び出し側の責任**である。ここで作った行は `RefreshDatabase` の
 *   rollback では消えない。放置すると同一 worker の後続テストへ漏れる。
 * ★既定接続の差し替えは**閉じた区間だけ**で、finally で必ず元へ戻す。
 *   **失敗時は別名接続を disconnect + purge** し、成功時だけ後続の読み取り・cleanup 用に維持する。
 */
final class OutOfTransactionFixtures
{
    public const string CONNECTION_NAME = 'concurrency_out_of_transaction';

    /**
     * @template T
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function create(Closure $callback): mixed
    {
        $original = config('database.default');
        Assert::stringNotEmpty($original);
        Assert::same($original, 'pgsql', 'このハーネスは pgsql レーンを前提にする');

        self::register($original);

        $succeeded = false;
        try {
            config(['database.default' => self::CONNECTION_NAME]);
            $result = DB::connection(self::CONNECTION_NAME)->transaction($callback);
            $succeeded = true;

            return $result;
        } finally {
            config(['database.default' => $original]);

            // ★失敗したら別名接続を残さない (握ったまま抜けると接続が漏れる)
            if (! $succeeded) {
                DB::disconnect(self::CONNECTION_NAME);
                DB::purge(self::CONNECTION_NAME);
            }
        }
    }

    /** 別名接続を登録する (既定接続設定の**完全な複製**。座標は 1 文字も変えない) */
    private static function register(string $original): void
    {
        $base = config("database.connections.{$original}");
        Assert::isArray($base);

        config(['database.connections.'.self::CONNECTION_NAME => $base]);
        DB::purge(self::CONNECTION_NAME);
    }

    /** 別名接続で読む (親の裏取り用。既定接続の transaction の中を見に行かない) */
    public static function connection(): ConnectionInterface
    {
        return DB::connection(self::CONNECTION_NAME);
    }

    /** 呼び出し側が finally で呼ぶ。冪等 (何度呼んでも安全) */
    public static function cleanup(ConcurrencyFixtureKeys $keys): void { /* §2-b */ }
}
```

### 設計 2-b: 後始末の完全性（cascade の当て推量をしない）

**FK を全数実読した結果**（当て推量を排除するためここに固定する）:

| 子 | 親 | 挙動 | 出典 |
|---|---|---|---|
| `idempotency_keys.api_key_id` | `api_keys` | **cascade** | `create_idempotency_keys_table` |
| `idempotency_keys.user_id` | `users` | **cascade** | 同上 |
| `api_keys.organization_id` | `organizations` | **cascade** | `create_api_keys_table` |
| `organization_user.organization_id` / `.user_id` | `organizations` / `users` | **cascade** | `create_organizations_tables` |
| `custom_teams.organization_id` | `organizations` | **cascade** | 同上 |
| `organizations.laratrust_team_id` | `teams` | **restrictOnDelete** | 同上 |
| `users.current_organization_id` | `organizations` | **nullOnDelete** | 同上 |
| `role_user.team_id` | `teams` | **cascade** | `laratrust_setup_tables` |
| `role_user.user_id` | — | **FK 無し**（polymorphic） | 同上 |

**ここから導かれる 2 つの落とし穴**（設計に必ず織り込む）:

1. `organizations.laratrust_team_id` が **restrictOnDelete** なので
   「組織を消せば全部消える」は成り立たない。**組織 → teams の順**でなければ削除できない
2. `role_user.user_id` には FK が無いので、**利用者を消しても role_user は連鎖しない**
   （`teams` 削除の cascade で消える経路に依存する）
3. `organizations` は **softDeletes** を持つので、Eloquent の `delete()` では物理削除されない。
   **query builder で物理削除**する

```php
/** 作った検体の主キー (cleanup の対象を推測させないために持ち回る) */
final readonly class ConcurrencyFixtureKeys
{
    public function __construct(
        public int $organizationId,
        public int $laratrustTeamId,
        public int $userId,
        public int $apiKeyId,
        public string $routeName,   // idempotency_keys の掃除に使う
    ) {}
}
```

**削除順（FK 安全）**:

| 順 | 表 | 条件 |
|---|---|---|
| 1 | `idempotency_keys` | `api_key_id = :apiKeyId`（cascade でも消えるが明示する） |
| 2 | `api_keys` | `organization_id = :organizationId` |
| 3 | `organization_user` | `organization_id = :organizationId` |
| 4 | `custom_teams` | `organization_id = :organizationId` |
| 5 | `organizations` | `id = :organizationId`（**query builder の物理削除**。softDeletes を迂回する） |
| 6 | `role_user` | `team_id = :laratrustTeamId`（teams 削除の cascade でも消えるが明示する） |
| 7 | `teams` | `id = :laratrustTeamId`（**組織を消した後**でなければ restrict で落ちる） |
| 8 | `users` | `id = :userId`（`current_organization_id` は 5 で null 化済み） |

**残留ゼロの検査**（cascade の当て推量を検査に置き換える）:
cleanup 後に上記 **8 表**それぞれについて、対象の主キー / 外部キーで数えて **0 件**であることを
別名接続から確認する。見本テスト（施策 6）と `OutOfTransactionFixturesTest`（本施策）の**双方**で行う。

cleanup は `finally` で必ず通り、**接続の後始末も finally**（`DB::disconnect` + `DB::purge`）で行う。

### 採らなかった案（記録）

> **モデル / Factory 単位で接続を指定する Laravel 標準経路を優先する** — 採らない。
> Laravel の Factory には接続を指定する第一級 API が無く、検体は複数モデル
> （利用者 / 組織 / laratrust team / default team / 組織メンバー / ロール割当 / API キー）にまたがる。
> 閉じた区間で既定を差し替えて `finally` で戻すほうが指定漏れが構造的に起きない。
> 家系の先行 2 例（aigenba / laravel-claude-template）も同じ形である。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`create()` は `@template T`）
- [x] null 安全（`Assert::stringNotEmpty` / `Assert::isArray` / `Assert::same`）
- [x] DTO を使う（`ConcurrencyFixtureKeys`。cleanup 対象を配列で渡さない）
- [x] Generics の型パラメータが正しい（`@template T` + `\Closure(): T`）

### テスト計画

- [x] 新規: `tests/Feature/Concurrency/OutOfTransactionFixturesTest.php`
  - 「`create()` で作った行が**別接続から見える**」（transaction の外に出ている）
  - 「`cleanup()` の後、**8 表すべてで残留が 0**」（後続テストを汚さない）
  - 「`create()` の中で例外が出ても**既定接続名が元へ戻り、別名接続が disconnect + purge される**」
  - 「別名接続の座標が既定接続と一致する」（別 DB を向いていない）
  - 「`organizations` が**物理削除**されている」（softDeletes の迂回が効いている）
- [x] 個別の `DatabaseTransactions` を使わない
- [x] テストデータは Factory / 正規ドメイン生成 helper で生成する

### リスク

- 片付け漏れが同一 worker の後続テストを汚す → **8 表の残留ゼロ検査**で塞ぐ。
- 既定接続の差し替え中に他のコードが `DB::` を触ると別名接続へ行く。
  区間を検体生成だけに絞ることで最小化する。

---

## 施策 3: 一次観測の型（fail-closed）

### 変更箇所

- 新規: `tests/Support/Concurrency/ConcurrentProbeObservation.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 7 が本部品の fail-closed を固定する

### 設計

子が返す JSON は**外部入力**である。`tests` は PHPStan の解析対象外なので、
型の保証は**実行時の fail-closed 検証**で作る（概念レビューで明示的に合意した境界）。

**観測項目**（前回設計から大幅に増やした。理由は下の 2 点）:

```php
private const array REQUIRED_KEYS = [
    // 同一性 (起動時の割り当てとの突合)
    'child_id', 'nonce', 'go_token',
    // 何が起きたか (一次観測)
    'http_status', 'error_code', 'handler_executions', 'entered_handler',
    // 何を送ったか (2 子が同一要求だったことの証明)
    'route_name', 'uri', 'request_hash', 'api_key_id',
    // 守りたい層以外が無効化されていたか (要素 (3))
    'cache_default', 'cache_driver',
    // どこへ繋いだか (開発 DB 到達の検出)
    'db_driver', 'db_host', 'db_port', 'db_database', 'db_username', 'db_charset', 'db_sslmode', 'db_url',
];
```

**なぜ `error_code` が要るか（決定的）**: `IdempotentRequest` は 409 を **3 コード**返す（前提 P3）。
`http_status` だけを見ると、**2 子の body が違って `idempotency_conflict` になった場合でも**
「勝者 201 / 敗者 409 / ハンドラ合計 1 / 行 1 件 completed」がすべて成立し、**緑になる**。
それは本テストが主張したい `idempotency_in_progress`（= claim 行が processing で見えた）の証明ではない。
`error_code` の完全一致検査と、2 子の `request_hash` 一致検査の**両方**で塞ぐ。

**なぜ `go_token` が要るか（決定的）**: `observed_go: bool` は**自己申告**にすぎず、
子が go を待たずに走って最後に `true` と書いても親は検出できない。
親は**全 ready の nonce を検証した後に初めて**ランダムな go token を生成して `go` へ書く。
**go token は事前の引数で渡さない**ので、go を読まずに正しい値を書くことは構造的にできない。

```php
/**
 * 子プロセス 1 本ぶんの一次観測。
 *
 * ★勝者の判定は**行の最終状態ではなくこの一次観測**で行う (正典・家系の作法)。
 *   行だけを見ると「2 本とも本処理を実行したが後着が上書きした」形と区別がつかない。
 * ★`fromDecodedJson()` は **fail-closed**。必須キーの欠落・型違い・**未知キー**の
 *   いずれでも例外にする (子と親のプロトコル退行を黙って受け入れない)。
 * ★**キャストで救わない**。整数 cast の飽和で別の値が通る穴を家系が実際に踏んでいる。
 */
final readonly class ConcurrentProbeObservation
{
    private function __construct(/* 上記 21 項目に対応する public readonly プロパティ */) {}

    /** @throws ConcurrencyProtocolException 解釈できない観測は通さない */
    public static function fromDecodedJson(mixed $value): self { /* … */ }

    /** 起動時の割り当て・親が出した go token と食い違ったら通さない */
    public function assertIdentity(string $childId, string $nonce, string $goToken): void { /* … */ }

    /** 敗者としての条件 (release の前提)。満たさなければ例外 */
    public function assertLost(): void
    {
        // http_status === 409 かつ error_code === 'idempotency_in_progress'
        // かつ handler_executions === 0 かつ entered_handler === false
    }

    /** 守りたい層以外が無効化されていたか (要素 (3)) */
    public function assertAppLocksDisabled(): void
    {
        // cache_default === 'array' かつ cache_driver === 'array'
    }

    /** 親が渡した DB 座標と完全一致するか (開発 DB 到達の検出) */
    public function assertDatabaseCoordinates(array $expected): void { /* 7 項目 + url は空のみ許可 */ }
}
```

`fromDecodedJson()` の検証（すべて満たさなければ `ConcurrencyProtocolException`）:

1. `$value` が `array<string, mixed>` である
2. キー集合が `REQUIRED_KEYS` と**完全一致**する（欠落も余剰も不可）
3. 各値が期待するスカラー型である（`is_string` / `is_int` / `is_bool` を個別に確認。
   **`(int)` などのキャストで通さない**）
4. `handler_executions >= 0` / `http_status` が 100〜599 / `db_url` が空文字

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（`mixed` を受けて明示的に判定し、専用例外へ倒す）
- [x] DTO（配列を素で回さない）
- [x] Generics の型パラメータが正しい（`@var list<string> REQUIRED_KEYS` / `array<string, string> $expected`）

### テスト計画

- [x] 施策 7 が固定:
      必須キー欠落 / 未知キー / 型違い（`http_status` が `"409"` でも通さない）/
      `assertIdentity` の childId・nonce・**go token** 不一致 /
      `assertLost` が `idempotency_conflict` を**通さない** /
      `assertDatabaseCoordinates` が host 違い・port 違いを通さない / `db_url` 非空を通さない

### リスク

- キー集合の完全一致は、子の出力を増やすたびに親も直す必要がある。
  これは**意図した硬さ**（プロトコル退行を黙って通さない）であり、緩めない。

---

## 施策 4: 子の起動・遮断・回収・調停

### 変更箇所

- 新規: `tests/Support/Concurrency/ProbeEnvironment.php`
- 新規: `tests/Support/Concurrency/ProbeLaunchSpec.php`
- 新規: `tests/Support/Concurrency/ProbeProcess.php`（interface）
- 新規: `tests/Support/Concurrency/ProbeProcessFactory.php`（interface）
- 新規: `tests/Support/Concurrency/SymfonyProbeProcess.php`
- 新規: `tests/Support/Concurrency/SymfonyProbeProcessFactory.php`
- 新規: `tests/Support/Concurrency/ConcurrencyProbeRunner.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 7（偽 factory を差して回収規約とプロトコルを固定する）

### 設計 4-a: `ProbeEnvironment`（開発 DB への到達遮断）

**aicue でいちばん危険な部分**なので、判断を 1 クラスへ集める（子を起こさずに検査できる形にする）。
`FakeWiringProbeRunner` の 6 点規約（前提 P7）を**明示的に踏襲**する（docblock で名指しする）。

```php
/**
 * 子プロセスの設定の出所を作る (開発 DB への到達遮断の中心)。
 *
 * 作法は tests/Support/ExternalFakes/FakeWiringProbeRunner.php の 6 点規約を踏襲する:
 * env -i で環境を作り直す / 専用の一時 env ファイル 1 つだけを設定の出所にする /
 * ディレクトリ 0700・env ファイル 0600 を起動前に検査して違えば子を起こさない /
 * 締切つき実行 / 解釈できない子の出力は fail-closed / finally で必ず片付ける。
 *
 * ★相手 (FakeWiringProbeRunner) は **DB へ接続しないこと**が要件なので DB 座標を渡さない。
 *   こちらは**接続することが要件**なので、遮断の設計を独自に持つ。
 *   「似ているから」で共通基底へ寄せない (寄せると DB 遮断が片方の都合で緩む)。
 * ★**相手と違う判断をした点を黙って作らない**: 相手は APP_KEY / CIPHERSWEET_KEY を
 *   使い捨てで生成し「一時ファイルは秘密を 1 つも持たない」を達成している。
 *   こちらは**既存行 (CipherSweet で暗号化された PII) を読む必要がある**ため親の実鍵を渡す。
 *   そのぶん置き場所を守る (0700 / 0600 / 起動前の権限検査 / finally での削除)。
 */
final class ProbeEnvironment
{
    /** 子の env ファイルへ書いてよいキー (deny-by-default) */
    public const array ALLOWED_ENV_FILE_KEYS = [
        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY', 'BCRYPT_ROUNDS',
        'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
        'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_SSLMODE',
        'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'MAIL_MAILER',
    ];

    /** 子へ渡してよい**プロセス環境変数** (env -i で空にしたうえでこれだけ載せる) */
    public const array ALLOWED_PROCESS_ENV_KEYS = [
        'CONCURRENCY_PROBE_ENV_DIR',
        'CONCURRENCY_PROBE_ENV_FILE',
        'APP_CONFIG_CACHE',
    ];

    /**
     * 親の**実行時の実接続設定**から子の env 値を作る。
     *
     * ★値の出所は `config('database.connections.pgsql')` であり env の再読解ではない
     *   (親と子が同じ DB を見ることが構造的に保証される)。
     * ★`DB_URL` は**空文字で固定**する。キーを消すと子の .env 読み込みで復活する
     *   (家系のテンプレートが実装レビューで見つけた実在の穴)。
     *
     * @return array<string, string>
     * @throws RuntimeException 前提が崩れているとき (子を起こさせない)
     */
    public static function envFileValues(): array
    {
        Assert::same(config('database.default'), 'pgsql', 'このハーネスは pgsql レーンを前提にする');

        $config = config('database.connections.pgsql');
        Assert::isArray($config);

        // ★前提検査 1: 親が DB_URL 主体で接続していると、設定配列の host/port/database は
        //   実効座標とは限らない (URL 解析結果が優先される)。その場合は子を起こさない。
        //   現行レーンは phpunit.xml が DB_URL を空に固定しており前提は成立している (P5)。
        //   成立しなくなった日に赤くなる形にしておく。
        $url = $config['url'] ?? null;
        if ($url !== null && $url !== '') {
            throw new RuntimeException(
                'このハーネスは個別キー接続のレーンを前提にする (DB_URL 主体の設定では'
                .'設定配列の host/port/database が実効座標とは限らないため子を起こさない)'
            );
        }

        // ★前提検査 2: 既存の単一点ガードを**親側でも**通す (allowlist 一致 + dev denylist)。
        TestDatabaseEnv::assertPgsqlTestDatabaseSafe((string) ($config['database'] ?? ''));

        $values = [ /* DB 座標 7 項目 + DB_URL='' + APP_* + CACHE_STORE=array
                       + QUEUE_CONNECTION=sync + SESSION_DRIVER=array + MAIL_MAILER=array */ ];

        // ★前提検査 3: 許可キー以外を書かない (deny-by-default)
        foreach (array_keys($values) as $key) {
            Assert::oneOf($key, self::ALLOWED_ENV_FILE_KEYS);
        }

        // ★前提検査 4: 値に改行 / CR が入っていたら**書かずに例外**にする。
        //   env ファイルは 1 行 1 キーなので、値の改行は**別キーの注入**になる。
        foreach ($values as $key => $value) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new RuntimeException("env 値に改行を含むキーは書けない: {$key}");
            }
        }

        return $values;
    }

    /**
     * env ファイルの 1 行を組み立てる。
     *
     * 形式は `KEY="value"` の 1 つだけ (`"` と `\` をエスケープ)。
     * 引用符・`#`・空白を含む値でも**別キーを注入できない**形にする。
     * 子側の厳格パーサはこの 1 形式だけを受理する。
     */
    public static function encodeLine(string $key, string $value): string { /* … */ }

    /** ディレクトリ 0700・env ファイル 0600・入力ファイル 0600 でなければ例外 (子を起こさない) */
    public static function assertSafePermissions(int $directoryMode, int $envFileMode, int $inputFileMode): void { /* … */ }
}
```

**秘密の渡し方**: plain API key と request body は **argv に載せない**（プロセス一覧から読める）。
0700 のディレクトリ配下に **0600 の入力ファイル**（`input.json`）を作り、そのパスだけを argv に載せる。

### 設計 4-b: `ProbeLaunchSpec` / `ProbeProcess` / `ProbeProcessFactory`

失敗経路の検査で**偽物が合図を書ける**ようにするため、起動仕様を明示的な値にする。

```php
/** 子 1 本の起動仕様 (偽物も同じものを受け取る) */
final readonly class ProbeLaunchSpec
{
    public function __construct(
        public string $workspaceDirectory,  // 合図・出力・env ファイルの置き場
        public string $childId,
        public string $nonce,
        public string $scriptPath,
        public string $environmentDirectory,
        public string $environmentFileName,
        public string $inputFileName,
        public string $configCachePath,
    ) {}
}

interface ProbeProcessFactory
{
    public function create(ProbeLaunchSpec $spec): ProbeProcess;
}

/**
 * 子プロセス 1 本の抽象。
 *
 * ★**操作を分けている**のは、失敗経路の検査が「runner が停止・強制終了・待機を
 *   それぞれ要求したこと」を**順序込みで固定できる**ようにするためである。
 *   1 メソッドに束ねると、検査は「何かを呼んだ」しか言えない。
 *
 * **保証の境界**: 施策 7 が主張するのは「runner がこの抽象へ要求すること」までである。
 * 実 OS プロセスに対するシグナルの実効性は**保証範囲外**とする
 * (実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない)。
 */
interface ProbeProcess
{
    public function start(): void;
    public function isRunning(): bool;
    public function exitCode(): ?int;
    public function output(): string;
    public function errorOutput(): string;
    public function signalTerminate(): void;   // SIGTERM
    public function signalKill(): void;        // SIGKILL
    public function wait(float $seconds): ?int; // 終了コードを取る (取れなければ null)
}
```

`SymfonyProbeProcessFactory` / `SymfonyProbeProcess` が `Symfony\Component\Process\Process` を包む唯一の実装。
起動コマンドは `['env', '-i', 'CONCURRENCY_PROBE_ENV_DIR=…', 'CONCURRENCY_PROBE_ENV_FILE=…', 'APP_CONFIG_CACHE=…', PHP_BINARY, $scriptPath, $workspace, $childId, $inputFileName]`。

### 設計 4-c: `ConcurrencyProbeRunner`（調停）

```php
/**
 * 実プロセス 2 本を barrier で同期させて走らせ、一次観測を回収する。
 *
 * 段取り:
 *  1. 子ごとの ready を全員ぶん待ち、**中身の nonce を照合**する
 *  2. **ここで初めて** go token をランダム生成し、go を 1 つ置く
 *     (事前に渡さないので、go を読まずに正しい token を書くことは構造的にできない)
 *  3. entered を待つ (割り当て済みの 2 つの完成名だけを調べる。prefix の glob は使わない)
 *  4. **反対側の out を待ち、中身を完全に検査する**
 *  5. 検査をすべて通ったら release を置く
 *  6. 両方の終了を待ち、exit code 0 と stdout/out の一致を確かめて観測を返す
 *
 * ★4 の検査を通す前に release しない。「出てきたから release して、あとから赤くする」形は
 *   結果的に赤にはなるがプロトコルの証拠が弱い。
 * ★3〜5 の待機中は**常に**「2 つ目の entered / 子の異常終了」を監視する
 *   (単一ファイルだけを待つブロッキングにすると、二重実行の即時検出という性質が失われる)。
 * ★締切は**単一の絶対 deadline**である。段ごとに更新すると総時間が締切を大幅に超える。
 */
final class ConcurrencyProbeRunner
{
    /** 全体の締切 (子の起動 + 合図 + 要求 + 回収のすべてを含む) */
    public const float DEFAULT_TIMEOUT_SECONDS = 60.0;

    /** SIGTERM から SIGKILL までの猶予 */
    private const float REAP_GRACE_SECONDS = 1.0;

    /** 子の識別子 (固定 2 本。N 本への一般化はしない) */
    public const array CHILD_IDS = ['a', 'b'];

    /**
     * @param  array<string, string>  $expectedDatabaseCoordinates 親が渡した DB 座標
     * @return array<string, ConcurrentProbeObservation>  childId => 観測
     */
    public static function run(
        string $idempotencyKey,
        string $plainApiKey,
        array $requestBody,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?ProbeProcessFactory $factory = null,
    ): array { /* … */ }

    /** 残り時間 (単一の絶対 deadline から算出。0 以下なら BarrierTimeoutException) */
    private static function remaining(int $deadlineNs): float { /* … */ }
}
```

**`release` を置く条件**（1 つでも欠けたら release せずその場で失敗させる）:

| # | 条件 | 検査 |
|---|---|---|
| 1 | `entered` が**ちょうど 1 子**ぶん | `present()` が割り当て済み 2 名を調べて 1 件 |
| 2 | その `entered` の中身が **nonce + go token** と一致 | 文字列一致 |
| 3 | 反対側の `out` が**原子的に完成**している | rename 済みのファイルとして読める |
| 4 | その `out` の **childId / nonce / go token が一致** | `assertIdentity()` |
| 5 | その `out` が **409 + `idempotency_in_progress` + ハンドラ 0 + entered=false** | `assertLost()` |
| 6 | その `out` の **`request_hash` が親の期待値と一致** | 2 子が同一要求だった証明（conflict 経路の排除） |

**中断条件**（締切を待たずに抜ける。3〜5 の待機中は毎周回チェック）:

- `entered` が **2 つ**現れた → `ConcurrencyProtocolException::doubleExecution()`
  （**探している退行そのもの**なので、締切超過という紛らわしい形で出さない）
- 子が観測を出さずに終了した → `ConcurrencyProtocolException::childDiedEarly()`（`stderr` を添える）

**回収条件**（すべて満たさなければ例外）:

1. 両 process の **exit code が 0**
2. 各子の **stdout の JSON と `out` ファイルの中身が一致**
3. 観測 2 件がそれぞれ `assertIdentity()` / `assertAppLocksDisabled()` /
   `assertDatabaseCoordinates()` を通る

**片付け**（`finally` で必ず通る。締切超過・JSON 解釈失敗・`Process` の例外のいずれでも）:

1. 生きている子へ `signalTerminate()` → `wait(REAP_GRACE_SECONDS)` →
   まだ生きていれば `signalKill()` → `wait(残り)`
2. 一時ディレクトリ（合図・`.partial`・出力・env ファイル・入力ファイル）を再帰削除

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`array<string, ConcurrentProbeObservation>`）
- [x] null 安全（`Assert` + `?int` の明示。`exitCode()` の `null` を「不明」として扱う）
- [x] DTO を返している（観測の値オブジェクト / `ProbeLaunchSpec`）
- [x] Generics の型パラメータが正しい（`array<string, string>` / `list<string>`）

### テスト計画

施策 7 が偽 `ProbeProcessFactory` を差して固定する（詳細は施策 7 の表）。
子プロセスを起こさずに検査できるのは、**起動仕様が値になっている**からである。

### リスク

- **最大の危険は開発 DB への到達**。遮断は 9 段（§子プロセスの遮断段）で、
  そのうち親側 4 段（`DB_URL` 前提検査 / DB 名の allowlist 検査 / 許可キー検査 / 権限検査）は
  **子を起こさずに単体検査できる**形にしてある。
- 実鍵（`APP_KEY` / `CIPHERSWEET_KEY`）を一時ファイルへ書く。
  `FakeWiringProbeRunner` の「一時ファイルは秘密を 1 つも持たない」は達成できない
  （暗号化 PII を読むため）。**0700 / 0600 + 起動前検査 + finally 削除**で守り、差分を docblock に明記する。

---

## 施策 5: 実行スクリプト（子プロセスの本体）

### 変更箇所

- 新規: `tests/Support/Concurrency/idempotency-claim-probe.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 6 が実プロセスで叩く

### 子プロセスの遮断段（9 段）

**`env -i` は親のプロセス環境を消すだけで、子の Laravel がチェックアウトの `.env` を読むことは止められない。**
遮断は「環境変数を消す」ではなく「**設定の出所を差し替える**」で作る。
機構は aicue に**既に実在し実働している**（前提 P7）。

| 段 | 誰が | 内容 |
|---|---|---|
| 1 | 親 | `DB_URL` 主体でないことを検査（非空なら子を起こさない） |
| 2 | 親 | DB 名を `TestDatabaseEnv::assertPgsqlTestDatabaseSafe()` へ通す（落ちたら子を起こさない） |
| 3 | 親 | env ファイルへ書くキーを allowlist で検査し、値の改行を拒否する |
| 4 | 親 | ディレクトリ 0700 / env ファイル 0600 / 入力ファイル 0600 を起動前に検査する |
| 5 | 親 | `env -i` で環境を作り直し、許可 3 キーだけを載せる |
| 6 | 子 | **autoload の直後・bootstrap の前**に `getenv()` のキー集合を取り、許可 3 キーとの**完全一致**でなければ非ゼロ終了する（`env -i` の退行で親の `DB_URL` が継承されると、phpdotenv は immutable なので**環境変数が env ファイルより優先**され、遮断を迂回する） |
| 7 | 子 | env ファイルを**自前の厳格パーサで解析**し、キー集合・型・DB 名を検証する（`loadEnvironmentFrom()` はその場で解析しないので、bootstrap 前の検査には自前解析が要る）。DB 名は `assertPgsqlTestDatabaseSafe()` へ通す |
| 8 | 子 | `useEnvironmentPath()` / `loadEnvironmentFrom()` → `bootstrap()`。`APP_CONFIG_CACHE` は一時ディレクトリ配下の**存在しない絶対パス**（共有の `bootstrap/cache` を作らない・消さない） |
| 9 | 子 | bootstrap 後・**ready を出す前**に、実効 DB 座標が宣言と一致すること / 既定 cache が `array` であることを検査し、**違えば ready を出さずに非ゼロ終了**する |

段 9 が **ready の前**であることが正典の要素 (3)（「守りたい層以外を無効化**してから**測る」）の核心である。
測った後に「実は無効化できていなかった」と分かって赤くなるのでは、要素を満たしたことにならない。

子は**マイグレーションを一切実行しない**（スキーマは親のレーンが用意済み）。`RefreshDatabase` も使わない。

### 設計

```php
<?php

declare(strict_types=1);

/*
 * 実プロセス並行テストの子 (正典 v1 の要素 (1))。
 *
 * ★責務は 6 つだけ: 受け取った環境を検査する / 設定の出所を固定する / 起動前に DB 座標を検査する /
 *   起動後に「守りたい層以外の無効化」を検査してから準備完了を告げる /
 *   要求を 1 回だけ投げる / 観測を JSON で書く。
 * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md)。
 * ★秘密 (plain API key / body) は argv に載せない。0600 の入力ファイルから読む。
 */

require __DIR__.'/../../../vendor/autoload.php';

// [段 6] bootstrap の前に、**子が実際に受け取った**プロセス環境を検査する。
//        組み立て側の配列を見ても env -i の退行は映らない (観測できるのは子だけ)。
$received = array_keys(getenv());
sort($received);
$allowed = ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS;
sort($allowed);
if ($received !== $allowed) {
    fwrite(STDERR, '継承された環境変数がある (env -i の退行): '.implode(',', array_diff($received, $allowed)));
    exit(70);
}

// [段 7] env ファイルを自前の厳格パーサで解析し、bootstrap 前に DB 名を検査する。
$values = ProbeEnvironment::parseEnvFile($environmentDirectory.'/'.$environmentFile);
TestDatabaseEnv::assertPgsqlTestDatabaseSafe($values['DB_DATABASE']);

// [段 8] 設定の出所を専用の一時 env ファイル 1 つへ固定してから起動する。
/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->useEnvironmentPath($environmentDirectory);
$app->loadEnvironmentFrom($environmentFile);
$app->make(Kernel::class)->bootstrap();

// [段 9] **ready を出す前に**「守りたい層以外の無効化」と実効 DB 座標を検査する。
if (config('cache.default') !== 'array' || Cache::getDefaultDriver() !== 'array') {
    fwrite(STDERR, 'cache が array に固定できていない (守りたい層以外を無効化できていない)');
    exit(71);
}
assertEffectiveDatabaseCoordinatesMatch($values); // 違えば exit 72

// probe route を**この子の app インスタンスへ**登録する。
// ハンドラは**テスト側コード**なので、アプリコードを 1 バイトも触らずに待たせられる。
//
// ★middleware 列は「**冪等 middleware の前提を満たす最小 probe 経路**」である。
//   本番の順序契約は auth → throttle → resolve.api-actor → api.project-in-org
//   → api-key.ability → idempotent → controller だが、throttle を挟むと 2 本の到達が
//   乱れて測りたいものと別の分岐になるため入れない。**「本番同等」とは主張しない**。
Route::post($uri, function () use (
    $barrier, $childId, $nonce, $goToken, $remainingSeconds, &$handlerExecutions,
): JsonResponse {
    $handlerExecutions++;

    // 勝者だけがここへ来る。入ったことを告げ、親の release を待つ。
    // これで敗者は**勝者の claim 行が processing のまま在る間に必ず claim へ到達する**。
    $barrier->signal($barrier->name('entered', $childId), $nonce.':'.$goToken);
    $barrier->await($barrier->name('release'), $remainingSeconds());

    return new JsonResponse(['data' => ['ok' => true]], 201);
})->middleware(['auth:api-key,api-oauth', 'resolve.api-actor', 'idempotent'])->name($routeName);

// 準備完了を告げ、go を待つ (起動コストはここまでで払い切る)。
$barrier->signal($barrier->name('ready', $childId), $nonce);
$goToken = $barrier->await($barrier->name('go'), $remainingSeconds());

// 要求を 1 回だけ投げる (実サーバは立てない。プロセス内の実 middleware 列を通す)。
$response = $httpKernel->handle(Request::create($uri, 'POST', $body, … , [
    'HTTP_AUTHORIZATION' => "Bearer {$plainApiKey}",
    'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
]));

// 観測を書く。stdout と out ファイルへ**同じ JSON** を出す (親が一致を検査する)。
fwrite(STDOUT, $json);
$barrier->signal($barrier->name('out', $childId), $json);
exit(0);
```

**なぜ HTTP kernel を通すのか**: `IdempotentRequest` は
`ApiActorContext` attribute を前提にした順序契約を持ち、配線ミスは fail-closed で 500 になる。
middleware 単体を手で呼ぶとこの契約ごと迂回してしまう。実サーバは立てず、
`Kernel::handle()` でプロセス内の実 middleware 列を通す。

**route 名の一致が load-bearing**: claim のスコープは `(api_key_id, route_name, key)`（前提 P4）。
2 子は**同じ route 名**で登録しなければ衝突しない。
route 名・URI・body・Idempotency-Key はすべて**親が決めて入力ファイルで渡す**
（`--parallel` でも他と衝突しないよう nonce 込みにする）。

**body が同一であることが load-bearing**: 違うと `request_hash` が変わり
`idempotency_conflict`（409 の別コード）になる。親が 1 つの body を決めて両子へ同じものを渡し、
観測の `request_hash` 一致でも裏を取る。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（クロージャに `: JsonResponse`）
- [x] null 安全（`Assert::stringNotEmpty` で引数と入力ファイルを検証）
- [x] `response()->json()` の直書きをしない → **`new JsonResponse(...)`** を使う。
      これは HTTP エンドポイントの応答ではなく**テスト用 probe route の応答**であり、
      アプリの API 契約には現れない（`app/` 配下ではないので各種走査根にも入らない）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] 施策 6 が実プロセスで叩く（本スクリプト自身の単体検査は作らない —
      実プロセスを起こすテストを増やすと正典の要素 (6) に反する）
- [x] 起動前に落ちる経路（段 1〜5・7）は施策 7 が `ProbeEnvironment` 側で固定する

### リスク

- 起動コスト（autoload + bootstrap + 段 9 の検査）を `ready` の**前**に払い切ることが重要。
  払い切らないと go の後に起動コストが乗り、2 本の到達時刻がばらつく。
- `exit` コードを段ごとに分ける（70 / 71 / 72）ので、失敗時にどの段で落ちたかが親の `stderr` から分かる。

---

## 施策 6: 見本テスト（実プロセス版は**この 1 本だけ**）

### 変更箇所

- 新規: `tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本施策そのもの

### 主張文（測っていないものを主張しない）

> 「準備完了を**全員ぶん**確認してから**同一の合図で同時に解き放った実プロセス 2 本**が、
> 同一 actor・同一 route・同一 Idempotency-Key・**同一 body** の書き込み要求を送ったとき、
> `IdempotentRequest` middleware が**本処理を通したのはちょうど 1 本**であり、
> もう 1 本は本処理を実行せずに **`idempotency_in_progress` (409)** で弾かれる。
> **プロセス間で共有されるアプリ側ロックは 1 つも無い**状態で、である。」

### 設計

```php
/*
 * 冪等キーの実行前 claim を**実プロセス 2 本**で証明する
 * (正典 v1 の要素 (6): 実プロセス版はこの 1 本だけ)。
 *
 * 守られている実装 (App\Http\Middleware\IdempotentRequest::claim) は
 * 「unique 制約が唯一の調停者で、cache ロック等の補助機構は使わない」と宣言している。
 * 本テストはその宣言を実経路の証拠にする — 子の cache を配列固定にし
 * **プロセス間で共有されるアプリ側ロックが 1 つも無い**状態を作ってから測るので、
 * 「アプリ側のロックが効かなくても DB の一意制約だけで本処理が 1 回に収まる」まで言い切れる。
 *
 * ★細かい分岐 (再生 / conflict / indeterminate / 期限切れ再 claim / 順序) は
 *   **同一プロセス**の tests/Feature/Api/IdempotencyConcurrentClaimTest.php が持つ。ここへ足さない。
 */

test('実プロセス 2 本の同時 claim で本処理はちょうど 1 回だけ通る', function (): void {
    $expectedCoordinates = ProbeEnvironment::databaseCoordinates();

    // 検体はテストの transaction の**外**に作る (子から見えなければ成立しない)
    [$keys, $plainKey] = OutOfTransactionFixtures::create(function (): array {
        [$organization, $owner] = createOrganizationWithOwner();
        [$apiKey, $plain] = issueApiKey($organization, $owner);

        return [new ConcurrencyFixtureKeys(
            organizationId: $organization->id,
            laratrustTeamId: $organization->laratrust_team_id,
            userId: $owner->id,
            apiKeyId: $apiKey->id,
            routeName: $routeName,
        ), $plain];
    });

    try {
        $observations = ConcurrencyProbeRunner::run(
            idempotencyKey: (string) Str::uuid(),
            plainApiKey: $plainKey,
            requestBody: ['title' => '並行 claim の検体'],
        );

        expect($observations)->toHaveCount(2);

        // (1) 2 子とも**親が ready 検証後に生成した** go token を持ち帰った
        //     (= go を待たずに走ってはいない。自己申告の bool ではない)
        foreach ($observations as $childId => $observation) {
            $observation->assertIdentity($childId, $nonces[$childId], $goToken);
        }

        // (2) ハンドラ実行回数の**合計が 1** ← 一次観測。本テストの核心
        $executions = array_sum(array_map(fn ($o) => $o->handlerExecutions, $observations));
        expect($executions)->toBe(1);

        // (3) 勝者は 201 / entered=true、敗者は 409 + idempotency_in_progress / entered=false
        //     ★status だけでは足りない — 409 は 3 コードあり、body 違いの conflict でも
        //       (2) まで成立して**緑になる**。error_code の完全一致で塞ぐ。
        [$winner, $loser] = partitionByEntered($observations);
        expect($winner->httpStatus)->toBe(201);
        expect($winner->handlerExecutions)->toBe(1);
        expect($loser->httpStatus)->toBe(409);
        expect($loser->errorCode)->toBe(ApiErrorCode::IdempotencyInProgress->value);
        expect($loser->handlerExecutions)->toBe(0);

        // (4) 2 子は**同一要求**だった (conflict 経路を構造的に排除する)
        expect($winner->requestHash)->toBe($loser->requestHash);
        expect($winner->routeName)->toBe($loser->routeName);
        expect($winner->apiKeyId)->toBe($loser->apiKeyId);

        // (5) 2 子とも既定 cache が array (= プロセス間共有ロックの土台が不在)
        foreach ($observations as $observation) {
            $observation->assertAppLocksDisabled();
        }

        // (6) 2 子の実効 DB 座標が親の渡した値と**完全一致** (driver/host/port/database/
        //     username/charset/sslmode。url は空のみ許可)
        foreach ($observations as $observation) {
            $observation->assertDatabaseCoordinates($expectedCoordinates);
        }

        // (7) 裏取り: 行は 1 本だけで completed (**別名接続で読む**)
        $rows = OutOfTransactionFixtures::connection()
            ->table('idempotency_keys')->where('api_key_id', $keys->apiKeyId)->get();
        expect($rows)->toHaveCount(1);
        expect($rows[0]->state)->toBe(IdempotencyState::Completed->value);
        expect($rows[0]->response_status)->toBe(201);
    } finally {
        // 子が commit した行は RefreshDatabase の rollback では消えない。必ず片付ける。
        OutOfTransactionFixtures::cleanup($keys);
    }
});

test('片付けの後、検体は 8 表すべてで残留 0 である', function (): void {
    // cascade の当て推量を検査に置き換える (FK の実読結果は詳細設計 §施策 2 の表)
});
```

### 施策 6 が**やらないこと**（要素 (6) の遵守）

実行中 / 再生 / conflict / indeterminate / 期限切れ再 claim / 順序といった分岐は
**同一プロセス**の `tests/Feature/Api/IdempotencyConcurrentClaimTest.php` に残す。
ここへ 2 本目の実プロセステストを足さない。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（観測は型付き値。`assertLost()` 等が内部で fail-closed に検査する）
- [x] DTO を使う（`ConcurrentProbeObservation` / `ConcurrencyFixtureKeys`）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] バグ修正ではないので再現テストは不要。ただし**先に赤を見る**手順は踏む（AGENTS.md 思考原則 5）:
      (a) 親が `release` を置かない状態 → 締切例外になること
      (b) 子の handler で `entered` を出さない状態 → 「観測なしのまま通さない」ことを確認してから本実装を通す
- [x] 既存テストの更新: 施策 8（docblock のみ）
- [x] 個別の `DatabaseTransactions` を使わない
- [x] テストデータは Factory / 正規ドメイン生成 helper で生成する

### リスク

- 子 2 本の起動で数秒かかる（家系の実測: テンプレートで約 3 秒）。1 本に絞る限り許容範囲。
- 片付け漏れが同一 worker の後続テストを汚す → `finally` + **8 表の残留ゼロ検査**で塞ぐ。
- **接続数**: 本テストが走る **worker 内で最大 4 本**（親の既定接続 1 + 親の別名接続 1 + 子 2）。
  paratest はテストを worker へ分配するので本テストは実行全体で 1 回・1 worker でのみ走り、
  **suite 全体としては他 worker の接続にこのうち 3 本**（別名 1 + 子 2）**が加算される**だけである。
  worker 数に比例しては増えない。
  なお家系 (motivation) が踏んだ「テストごとのスキーマ再作成による排他ロック枯渇」は、
  本設計が**スキーマを作り直さない**（既存 worker DB へ行を足して消すだけ）ため構造的に起こらない。

---

## 施策 7: ハーネス自身の失敗経路の検査

### 変更箇所

- 新規: `tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本施策そのもの

### 設計

ハーネスが**黙って緑になる**壊れ方を塞ぐ。
家系では aigenba とテンプレートが持ち、台帳の gates 欄が名指ししている層である。
**子プロセスを 1 本も起こさない**（偽 `ProbeProcessFactory` を差す / 純関数を直接叩く）。

| # | 固定する挙動 | 対象 |
|---|---|---|
| 1 | 現れない合図を待ち続けず**締切で例外**になる | `ProcessBarrier` |
| 2 | 合図はあるのに読めないときは**空として通さず**落ちる（偽の読み手で `false` を決定的に返す。権限に依存しない） | `ProcessBarrier` |
| 3 | 中断条件が成立したら**締切を待たずに**抜ける | `ProcessBarrier` |
| 4 | **`.partial` を完成した合図として扱わない** | `ProcessBarrier::present()` |
| 5 | 固定語彙外の合図名・不正な child ID（`/` や `..` を含む）を**拒否する** | `ProcessBarrier::name()` |
| 6 | **ready の nonce が割り当てと違えば go を出さない** | `ConcurrencyProbeRunner` |
| 7 | **go token は ready 検証の後に生成される**（事前に渡らない） | `ConcurrencyProbeRunner` |
| 8 | **go token を持たない / 違う観測を通さない** | `ConcurrentProbeObservation::assertIdentity` |
| 9 | 未知 child ID の `entered` を**通さない** | `ConcurrencyProbeRunner` |
| 10 | `entered` が 2 つ出たら**締切を待たず**「二重実行を検出」で落ちる | `ConcurrencyProbeRunner` |
| 11 | 子が観測を出さずに終わったら**観測なしのまま通さない** | `ConcurrencyProbeRunner` |
| 12 | 敗者の `out` の検査（identity / 409 / `idempotency_in_progress` / ハンドラ 0 / request_hash）を通らなければ **release を置かない** | `ConcurrencyProbeRunner` |
| 13 | 敗者が **`idempotency_conflict`** でも release しない（コード違いを通さない） | `assertLost` |
| 14 | **stdout の JSON と `out` ファイルが不一致**なら通さない | `ConcurrencyProbeRunner` |
| 15 | **exit code が非ゼロ**なら通さない | `ConcurrencyProbeRunner` |
| 16 | 応答しない子へ **`signalTerminate()` → `wait()` → `signalKill()` → `wait()` が順に要求される** | 偽 `ProbeProcess` の呼び出し記録 |
| 17 | **締切は段ごとに更新されない**（全体の絶対 deadline で打ち切られる） | `ConcurrencyProbeRunner` |
| 18 | 必須キー欠落 / 未知キー / 型違いを**通さない**（キャストで救わない） | `ConcurrentProbeObservation::fromDecodedJson` |
| 19 | DB 座標の host / port / username 違いを**通さない**。`db_url` 非空も通さない | `assertDatabaseCoordinates` |
| 20 | `DB_URL` が非空なら**子を起こさない** | `ProbeEnvironment::envFileValues` |
| 21 | dev DB 名なら**子を起こさない** | `ProbeEnvironment::envFileValues`（`assertPgsqlTestDatabaseSafe` 経由） |
| 22 | 許可キー以外を env ファイルへ**書かない** | `ProbeEnvironment::envFileValues` |
| 23 | **env 値に改行があれば書かずに例外**（キー注入の拒否） | `ProbeEnvironment::envFileValues` |
| 24 | 0700 / 0600 以外の権限では**子を起こさない** | `ProbeEnvironment::assertSafePermissions` |
| 25 | **未知の `DB_*` / `APP_*` がプロセス環境に混入していたら拒否する**（`env -i` の退行） | 子の段 6 の判定関数（純関数として切り出す） |

### 保証の境界（明記する）

> 本検査が主張するのは「**runner が `ProbeProcess` へ停止・強制終了・待機をそれぞれ要求すること**」までである。
> **実 OS プロセスに対するシグナルの実効性は保証範囲外**とする
> （実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない）。
> 操作を 3 つに分けているのは、この主張を**呼び出し順込みで実際に固定できる**ようにするためである。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全
- [x] 偽 `ProbeProcess` / 偽 `ProbeProcessFactory` は interface を実装する（`mixed` を返さない）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] 本施策そのものがテスト
- [x] 個別の `DatabaseTransactions` を使わない（DB に触らない）

### リスク

- 偽物を差す注入点（`run()` の `?ProbeProcessFactory $factory`）が本番経路と乖離する。
  既定値 `null` のときに `SymfonyProbeProcessFactory` を作る形にし、分岐を 1 か所に留める。

---

## 施策 8: 既存テストの「保証しないこと」宣言の是正

> **前提**: 施策 3・5・6 の完了後に行う（`idempotency_in_progress` の完全一致観測が入って初めて、
> 下の docblock の主張が成立する）。

### 変更箇所

- `tests/Feature/Api/IdempotencyConcurrentClaimTest.php`（**冒頭 docblock のみ**。テスト本体は無変更）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本施策そのもの（既存テストの削除・上書きはしない）

### 現行コード

```php
/*
 * 冪等キーの「実行前 claim」契約 (T139)。
 * …
 * ★**保証しないこと**: PHP のテストは単一プロセスであり、真の並行 2 本は走らせていない。
 *   `RefreshDatabase` 下では全操作が同一接続・同一トランザクション内で見えるため、
 *   claim の commit も別接続からの可視性も検証していない。本番で後着から claim が
 *   見えるのは「middleware を包む外側 transaction が無い + pgsql の autocommit /
 *   read committed」という前提の帰結であって、テストによる保証ではない。
 */
```

### 変更後コード

```php
/*
 * 冪等キーの「実行前 claim」契約 (T139)。
 * …
 * ★**このテストが保証しないこと**: 単一プロセスであり、真の並行 2 本は走らせていない。
 *   細かい分岐 (再生 / conflict / indeterminate / 期限切れ再 claim / 順序) を
 *   決定的に固定するのが本テストの役割である。
 *
 * ★**実プロセス 2 本での裏取りは別にある**:
 *   tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php が
 *   barrier で同期させた実プロセス 2 本で、
 *   (a) claim の commit が別接続 (別プロセス) から見えること
 *   (b) 本処理を通したのはちょうど 1 本で、もう 1 本は idempotency_in_progress で弾かれること
 *   を測っている。**埋まったのはこの 2 点だけ**である —
 *   任意の production route や実ジョブの副作用まで保証したわけではない。
 */
```

### PHPStan適合チェック

- [x] コメントのみの変更（型に影響しない）

### テスト計画

- [x] 既存テストの削除・上書きをしない（docblock のみ）
- [x] 既存テストが引き続き緑であることを確認する

### リスク

- 保証範囲を広く書きすぎると「ここは証明済み」と誤読される。
  「埋まったのはこの 2 点だけ」と明示的に狭める。

---

## 施策 9: 乖離台帳 D7 の再判定記録と文書追記

### 変更箇所

- `docs/template-divergence.md`（**D7 の中へ再判定の記録**。登録の追加・削除はしない）
- `docs/architecture.md`（テスト機構の節へ 1〜2 行）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: **`tests/Support/TemplateDivergence/LedgerPins.php` は変更しない**（下記）

### 乖離台帳の確認（app-design Phase 3-0 の手順）

`docs/template-fingerprints.json` の `entries`（**281 キー**）を実読して、
**本設計が触る全 20 パス**（新規 17 + 変更 3）を突き合わせた:

| パス群 | 共有パスか | 判断 |
|---|---|---|
| `tests/Support/Concurrency/*`（新規 14） | **無い** | テンプレートに無い領域への上積み |
| `tests/Feature/Concurrency/*`（新規 2） | **無い** | 同上 |
| `tests/Unit/Support/Concurrency/*`（新規 1） | **無い** | 同上 |
| `tests/Feature/Api/IdempotencyConcurrentClaimTest.php` | **無い** | docblock のみの変更 |
| `docs/template-divergence.md` | **無い** | 登録簿そのもの |
| `docs/architecture.md` | **無い** | 追記のみ |
| `phpstan.neon` | **在る**（かつ**採用時債務** 65 行目） | **触らない**（下記） |
| `tests/bootstrap.php` | **在る** | **触らない**（`TestDatabaseEnv` を読むだけ） |

**新規登録（D 番号）を作らない判断の根拠**:
「登録するか迷ったら登録する」（登録簿の記録の原則）に照らして検討したが、
本設計の新設物は**テンプレート側の同名機構と競合しない純粋な上積み**であり、
かつ**テンプレートが同じ feature を実装済み**（`laravel-claude-template` は v1 で implemented）なので、
将来テンプレートから取り込む際に**衝突するのは配置とクラス名だけ**である。
これは逸脱（揃えないと決めた差）ではなく**先行実装**なので、D 登録はしない。
代わりに `docs/architecture.md` へ機構の存在を 1〜2 行残し、取り込み時に気付ける形にする。

**`phpstan.neon` を触らない判断**:
家系のテンプレートは追従時に `phpstan.neon` へハーネスを追加している（テンプレートは `tests` を解析対象に持つ）。
一方 aicue の `phpstan.neon` の `paths` は `app / config / database / routes` で **`tests` を含まない**。
したがって**追加しても解析されず、意味がない**。加えて `phpstan.neon` は
共有パスかつ `adoption-debt.tsv` の採用時債務に在るため、変更すると
(1) 採用時の姿へ戻す / (2) テンプレートへ同期して債務から削る / (3) 意図的逸脱として登録を書き債務から削る
の三択を迫られる。**意味のない変更のためにこの三択を発生させない**のが最小である。

### D7 の扱い（**完了扱いにしない。据え置いたうえで再判定の事実を記録する**）

| D7 の欄 | どうするか |
|---|---|
| 状態 | **恒久のまま据え置き**（完了・削除にしない） |
| 対象パス / 揃え続ける不変条件 | **変更しない** |
| 再判定の記録（追記） | 「{実際に台帳を更新した日} 再判定（lctl feature `process-concurrency-test-harness` rev `14-3117f6369f21` への追従作業時）。非トランザクションの検体置き場（`OutOfTransactionFixtures`）は導入したが、正典 v1 の要素 (6) により実プロセス版は 1 本に絞る。その 1 本は冪等 claim へ割り当てたため、preview 上限の実証は逐次境界のまま据え置く」 |
| 次回再判定の条件（更新） | 「実プロセス並行テストの本数制約を見直すとき、または preview 上限の直列化に退行が疑われたとき」 |

**`LedgerPins` の件数**: 登録の**追加も削除もしない**ので
`DIVERGENCE_ENTRY_COUNT`（36）/ `FINGERPRINT_POPULATION_COUNT`（281）/ `ADOPTION_DEBT_COUNT`（171）は
**いずれも変更しない**。

### `docs/architecture.md` への追記（1〜2 行）

実プロセス並行テストという新しい層が入り、しかも**子が DB へ接続する**（= 開発 DB 保護に関わる）ため、
機構の存在と保証範囲を指せるようにする。

> 実プロセス並行テスト: `tests/Support/Concurrency` のハーネスが barrier で同期した実プロセス 2 本を走らせ、
> `tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php` が
> 「冪等 claim の本処理はちょうど 1 回・敗者は `idempotency_in_progress`」を実経路で固定する
> （実プロセス版はこの 1 本だけ）。子の DB 座標は親の実接続設定から作られ、
> `TestDatabaseEnv::assertPgsqlTestDatabaseSafe()` を親子で 2 回通る。

### PHPStan適合チェック

- [x] 文書のみの変更（型に影響しない）

### テスト計画

- [x] 乖離台帳の形式検査（宣言行 / 見出しの実数 / `LedgerPins` の 3 点一致）が緑のままであることを確認する
      （件数を変えないので変化しないはず）
- [x] `composer test` 全体が緑

### リスク

- D7 を「解決済み」と誤読されると、preview 上限の subprocess 実証が入ったと勘違いされる。
  **「完了扱いにしない」と明記**して塞ぐ。

---

## スコープ外（正典が要求しない一般化・過大化はしない）

| # | やらないこと | 理由 |
|---|---|---|
| 1 | 既存の同一プロセス並行テスト 3 本をハーネスへ載せ替える | 正典の要素 (6) が「細かい分岐は同一プロセスのテストへ回す」と**同一プロセス側の存続を前提**にしている |
| 2 | 実プロセス並行テストを 2 本以上作る | 要素 (6) に反する |
| 3 | D7（org 同時 preview 上限）の実証を subprocess へ移す | #2 と同じ。据え置きの判断と根拠は登録簿へ記録する（施策 9） |
| 4 | 課金のチケット確保（`ticket-reserve-commit`）を実プロセスで裏取りする | 同上。道具ができるので**次の TODO で選べる**状態にはなる |
| 5 | 子プロセス数を N 本に一般化する / 任意の処理を叩ける汎用ハーネスにする | 「今必要なものだけ作る」。正典も 2 本立てを基本形として書いている |
| 6 | `FakeWiringProbeRunner` との共通基底の抽出 | 目的が逆（DB へ接続しない / する）。統合すると DB 遮断が緩む |
| 7 | `phpstan.neon` へのハーネス追加 | aicue は `tests` を解析対象に含めない。かつ共有パス + 採用時債務（施策 9） |
| 8 | アプリコード（`app/`）の変更 | 家系の先行 2 例が「本番コードは 1 バイトも変更していない」で達成済み。aicue も注入点を必要としない |
| 9 | 非トランザクションの独立テストレーン（別 suite / 別 CI ジョブ）の新設 | 検体を別名接続へ出すだけで足りる。レーン構成は正典の boundary が**含まないと明記** |
| 10 | ハーネスを見張る Architecture gate（目録検査）の新設 | 正典 gates 欄が「正典にはハーネス自体を見張る検査は無い」と明記 |
| 11 | 解決済み cache store の**具象クラス**の検査 | `getStore()` は `CachePayloadPlainDataGateTest` が境界迂回として deny-by-default で 0 件に固定（前提 P8）。`config('cache.default')` と `CacheManager::getDefaultDriver()` で足りる |
| 12 | `DB_URL` 主体の設定を実効座標へ展開する仕組み | 前提検査で fail-fast すれば足りる（オーバーエンジニアリング） |
| 13 | probe 経路へ throttle / project-in-org / ability を足して「本番同等」にする | throttle は 2 本の到達を乱して測りたいものと別の分岐にする。**「本番同等」と主張しない**ことで解決する |
| 14 | 実 OS プロセスへの SIGKILL の実効性を検査する | 実プロセスを起こすテストが増え、要素 (6) に反する。保証範囲外と明記する |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) 新規 17 本・変更 3 本と規模が大きく、うち 4 本（`ProbeEnvironment` / `idempotency-claim-probe.php` / `ConcurrencyProbeRunner` / `OutOfTransactionFixtures`）は**開発 DB 保護**と**検体の commit**に関わるため、独立した worktree で赤を確認しながら進めたい。(2) 実プロセスを起こすテストは環境依存が出やすく、他施策と混ぜると切り分けが難しい。(3) `docs/` の変更（施策 9）が乖離台帳の形式検査に関わるため、単独のコミット列で追える形が望ましい |
| 競合リスク | **低**。`app/` を 1 バイトも触らないため、アプリ機能の改修とは衝突しない。触る既存ファイルは 3 本のみ（`IdempotencyConcurrentClaimTest.php` の docblock / `docs/template-divergence.md` の D7 内 / `docs/architecture.md` の 1〜2 行）。ただし**乖離台帳を触る別 TODO が並走すると `LedgerPins` の件数で衝突しうる**（本設計は件数を変えないので解決は容易） |

---

## 最終確認（使命・禁止事項チェック）

| 観点 | 確認 |
|---|---|
| 使命への寄与 | REST API v1 の write 経路で「同じ要求が同時に来ても本処理は一度だけ」が実経路の証拠を持つ。「思考ゼロ」の前提（作業者へ二重の指示が出ない）を支える土台。ただし主張は middleware 契約までに狭め、撮影・レンダの二重実行防止は**帰結**として書き分けた |
| 禁止 1（テストなしの実装完了） | 全施策にテスト計画がある。ハーネス自身にも失敗経路の検査 **25 件**を付ける |
| 禁止 2（PHPStan の widen / baseline） | `phpstan.neon` を**変更しない**。`ignoreErrors` も足さない。`app/` を触らないので新規エラーも出ない |
| 禁止 3（dev DB への破壊操作） | 遮断は **9 段**。うち親側 4 段は子を起こさずに単体検査できる。子はマイグレーションを実行しない |
| 禁止 4（`response()->json()` 直書き） | 該当なし（probe route の応答は `new JsonResponse(...)`。アプリの API 契約には現れない） |
| 禁止 5・6（LLM / prompt） | 該当なし（LLM を呼ばない） |
| 禁止 7・8（HTTP 応答 / UI） | 該当なし（UI を触らない） |
| 禁止 9（Artifact） | 成果物は本 devnotes 配下のファイルとして出力する |
| `DatabaseTransactions` の個別使用 | しない（`RefreshDatabase` のグローバル適用のまま。検体だけを別名接続で transaction の外へ出す） |
| Factory 使用 | 検体は `createOrganizationWithOwner()`（Factory + provisioning service）と `issueApiKey()`（正規ドメイン生成 helper）で作る。`Model::create()` の手組みはしない |
| DESIGN.md / Atomic Design | 該当なし（UI / frontend の変更が 0 件） |

