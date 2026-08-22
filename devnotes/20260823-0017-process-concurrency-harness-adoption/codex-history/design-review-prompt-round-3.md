# Round 3: Round 2 指摘への対応

指摘 20 件を**全件対応**した (反論・見送りは 0 件)。
Round 1 の `response_status` 指摘の撤回、ありがとう。

## Round 2 で指摘された「再確認すべき 4 点」への回答

1. **go token は closure 実行時に確定済みの値を参照しているか** →
   参照キャプチャ (`&$goToken`) にし、handler 先頭で `Assert::stringNotEmpty($goToken)` を通す。
   「万一 go より先に handler へ入った」場合に**黙って空文字を合図に書かず落ちる**門も付けた。
2. **runner 内部値と見本テストの公開 API が一致しているか** →
   同一性検査 (childId / nonce / go token) は **runner 内で完結**させ、外へ出さない。
   裏取りに要る値だけを `ConcurrentProbeResult` (observations / routeName / uri /
   idempotencyKey / expectedRequestHash) で返す。見本テストから `$nonces` / `$goToken` は消えた。
3. **作業の締切を使い切った後でも TERM/KILL/wait が完遂するか** →
   締切を 2 本に分けた。「作業の締切」(既定 60 秒) と「**回収専用の予算**」
   (`REAP_BUDGET_SECONDS = 2.0`) は独立で、`finally` は残り時間にかかわらず回収を完遂する。
   実時間は「作業の締切 + 最大 2 秒」と明記した。
4. **親の raw bytes と middleware が hash する bytes が同じか** →
   実読したところ `hashRequest()` は次のとおりだった:

```php
    {
        $name = $request->route()?->getName();

        return is_string($name) && $name !== '' ? $name : '(unnamed-api-route)';
    }

    /** メソッド + パス + body で同一リクエストかを判定する */
    private function hashRequest(Request $request): string
    {
        return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
    }
}
```

   `Request::create()` の**第 3 引数へ配列を渡すと form parameter になり `getContent()` が空になる**
   ため、設計のままでは食い違っていた。親が `json_encode(..., JSON_THROW_ON_ERROR)` で
   raw bytes を 1 回だけ作り、同じバイト列を両子の入力ファイルへ書き、子は**第 7 引数 content** へ
   そのまま渡す契約にした。期待 hash は `sha256('POST|'.$path.'|'.$rawBytes)` で親が計算し、
   **勝者 / 敗者 / 親の 3 点一致**を検査する。

さらに **signal 名検証の入口統一**は `SignalName` 値オブジェクトで解決した
(`ProcessBarrier::name()` が唯一の生成口で、他のメソッドは `string` を受け取らない。
生成できるのは go / release / ready-a / ready-b / entered-a / entered-b / out-a / out-b の 8 通りだけ)。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

指摘 20 件を**全件対応**（反論・見送りは 0 件）。
Round 1 の `response_status` 指摘は Codex 側から撤回された。

---

## 施策 1

### [Critical] `name()` で検証しても `signal()` / `await()` / `path()` は任意文字列を受ける
- 判断: **対応する**
- 根拠: そのとおり。検証を通す道と通さない道が並存していれば、構造的な保証にならない。
- 対応内容: **`SignalName` 値オブジェクト**を導入し、`ProcessBarrier::name()` を**唯一の生成口**にした。
  `signal()` / `await()` / `present()` / `path()` はすべて `SignalName` を受け取る（`string` を受けない）。
  文字列で書けるのは `name()` の引数（固定語彙 + child ID）だけなので、`../outside` は型で作れない。

### [Warning] `go-a` / `release-b` / `ready`（child ID 無し）のような不正な組合せを作れる
- 判断: **対応する**
- 対応内容: 種別ごとに child ID の要否を固定した —
  `go` / `release` は **child ID 禁止**、`ready` / `entered` / `out` は **child ID 必須**。
  生成できる名前は `go` / `release` / `ready-a` / `ready-b` / `entered-a` / `entered-b` /
  `out-a` / `out-b` の **8 通りだけ**である。

### [Warning] `reader` が `mixed` で型安全性の説明と食い違う
- 判断: **対応する**
- 根拠: そのとおり。`tests` が PHPStan 対象外だからこそ、不要な `mixed` を置かない。
- 対応内容: コンストラクタは `?callable` で受け、内部は `private readonly ?Closure $reader` で保持する
  （`Closure::fromCallable()` で正規化）。

---

## 施策 2（APPROVE）

### [Suggestion] 残留ゼロの検査を `cleanup()` 自身の契約にする
- 判断: **対応する**
- 根拠: 別テストだけに任せると、見本テスト自身の後始末の完全性が弱い。
- 対応内容: `cleanup()` が削除後に**自分で 8 表の残留ゼロを検査**し、残っていれば例外にする契約にした。
  `OutOfTransactionFixturesTest` は「その契約が効いていること」を固定する側に回る。

---

## 施策 3

### [Warning] 勝者側の `error_code` の型が未定義
- 判断: **対応する**
- 対応内容: `?string` とし、**勝者は `null`・敗者は `idempotency_in_progress` の完全一致**と定めた。
  `fromDecodedJson()` は `null` か非空文字列のみを受理する。

### [Warning] DB 座標を `array<string, string>` としたが `db_port` は `int`
- 判断: **対応する**
- 根拠: そのとおり。文字列で持つと厳密比較のために暗黙のキャストが要り、
  「外部観測をキャストで救わない」という自分の方針と矛盾する。
- 対応内容: **`ProbeDatabaseCoordinates`**（`port` は `int`、他は `string`）という型付き DTO を新設し、
  親の期待値も子の観測もこの型で扱う。比較は同型どうしの厳密比較になる。

---

## 施策 4

### [Critical] 単一 deadline を回収にも使うと、締切超過時に残時間 0 で kill 後の wait ができない
- 判断: **対応する**
- 根拠: 決定的な指摘。締切超過はまさに**回収が必要な場面**なのに、
  その時点で回収の予算が 0 になる設計だった（子が残る）。
- 対応内容: **締切を 2 本に分けた** —
  「作業の締切」（既定 60 秒。全通常処理を打ち切る）と
  「**回収専用の予算**」（`REAP_BUDGET_SECONDS = 2.0`。作業の締切とは独立）。
  `finally` は**残時間にかかわらず** TERM → 猶予 → KILL → bounded wait を必ず完遂する。
  「実時間は作業の締切 + 最大 2 秒」と明記した。

### [Critical] 見本テストが runner 内部の `$nonces` / `$goToken` を参照していて実装不能
- 判断: **対応する（両案を組み合わせる）**
- 根拠: そのとおり実装できなかった。
- 対応内容: **同一性の検査は runner 内で完結**させ（nonce と go token は外へ出さない）、
  そのうえで見本テストが行の裏取りに必要な値だけを **`ConcurrentProbeResult` DTO** で返す
  （`observations` / `routeName` / `idempotencyKey` / `expectedRequestHash` / `uri`）。
  内部プロトコル（nonce・go token）は漏らさず、裏取りに要る公開値だけを渡す形にした。

### [Warning] `wait(float $seconds)` は Symfony Process の同名 API を包めない
- 判断: **対応する**
- 対応内容: `SymfonyProbeProcess` が `isRunning()` と**単調時計で独自に bounded wait を実装する**ことを明記した。
  Symfony の `wait()` は使わない。

### [Warning] 「未知 child ID の entered を拒否」は `present()` では検出できない
- 判断: **対応する（保証を狭めるのではなく、検出できる形にする）**
- 根拠: そのとおり。`present()` は割り当て済み 2 名しか見ないので `entered-c` は無視される。
- 対応内容: 完成合図の置き場を**専用ディレクトリ**にし（`.partial` は別ディレクトリなので列挙が安全）、
  **ディレクトリを列挙して許可集合との差分を検査**する形にした。
  未知の完成ファイルが 1 つでもあれば `ConcurrencyProtocolException` にする。施策 7 の #9 は残す。

---

## 施策 5

### [Critical] `$goToken` の値キャプチャは closure 定義時点で未確定
- 判断: **対応する**
- 根拠: 素のバグ。closure 定義時に `$goToken` はまだ代入されていないので、値キャプチャでは反映されない。
- 対応内容: 参照キャプチャ（`&$goToken`）にし、handler の先頭で
  `Assert::stringNotEmpty($goToken)` を通す（go を読む前に handler へ入る形は起こり得ないが、
  起きたら**黙って空文字を合図に書かず落ちる**）。なぜ参照なのかも docblock に書く。

### [Critical] request hash の正本となる raw body が未定義
- 判断: **対応する**
- 根拠: 決定的な指摘。実読すると
  `hashRequest()` は `hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent())`
  である。`Request::create()` の第 3 引数（`$parameters`）へ配列を渡すと
  **form parameter として扱われ `getContent()` は空になる**ので、
  設計のままでは親の期待 hash と middleware が見る内容が食い違った。
- 対応内容: **raw bytes を 1 回だけ親が作る**契約にした —
  親が `json_encode($body, JSON_THROW_ON_ERROR)` で raw JSON を 1 回生成し、
  **同じバイト列**を両子の入力ファイルへ書く。子は
  `Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', …], $rawBytes)`
  の**第 7 引数 content** へそのまま渡す。
  親の期待 hash は `sha256('POST|'.$path.'|'.$rawBytes)`（middleware と同一規則。`path()` は先頭 `/` を含まない）。
  勝者・敗者・親の期待値の **3 点一致**を検査する。

### [Warning] 独自 encoder / parser と phpdotenv の二重解釈（`$` / `${VAR}` の展開）
- 判断: **対応する**
- 対応内容: エスケープ規則に **`$` を含める**（`"` / `\` / `$` をエスケープ）。
  そのうえで施策 7 へ **round-trip の正例**を追加した
  （空文字 / `\` / `"` / `#` / 前後の空白 / `$` / `${NAME}`）。
  さらに「自前 parser の結果と bootstrap 後の実効値が一致すること」を子の段 9 で検査する。

### [Warning] env / 入力ファイルを「作成時点から 0600」にする手順が未明記
- 判断: **対応する**
- 対応内容: `FakeWiringProbeRunner::writeEnvFile()` と同じ手順を明記した —
  `fopen($path, 'x')`（既存ファイルがあれば失敗＝乗っ取られた置き場所へ書き足さない）で作り、
  **中身を書く前に** `chmod($path, 0600)`、書き切れなかった / 閉じられなかったら fail-closed。

---

## 施策 6

### [Critical] fixture callback の `$routeName` が未定義（route 名は runner が後で決める）
- 判断: **対応する**
- 対応内容: `ConcurrencyFixtureKeys` から **`routeName` を削除**した
  （cleanup は `api_key_id` で行うので不要）。route 名は runner が決め、`ConcurrentProbeResult` で返す。

### [Critical] `$nonces` / `$goToken` が見本テストのスコープに存在しない
- 判断: **対応する**（施策 4 の `ConcurrentProbeResult` + runner 内完結で解消）

### [Critical] 「同一 body」の hash 生成規則が未確定（2 子が同じ誤った body でも一致する）
- 判断: **対応する**
- 対応内容: 施策 5 のとおり**親が期待 hash を計算**し、
  勝者 hash / 敗者 hash / **親の期待 hash** の **3 点一致**を検査する。
  さらに保存された行の `request_hash` も親の期待値と突き合わせる（4 点目）。

### [Warning] 行の裏取りが `api_key_id` だけでスコープを確認していない
- 判断: **対応する**
- 対応内容: `api_key_id + route_name + key` で絞り、保存された `request_hash` と
  `response_status` も親の期待値と一致させる。

### [Warning] 観測の `api_key_id` を fixture の値とも一致させるべき / 入力のコピーではなく認証結果から観測すべき
- 判断: **対応する**
- 根拠: 入力値をそのまま書き戻すと「認証が通ったこと」を何も示さない。
- 対応内容: 子は **`ApiActorContext`（`resolve.api-actor` の結果）から** api_key_id を観測する。
  見本テストは 2 子の一致に加えて **fixture の `$keys->apiKeyId` との一致**も検査する。

### [Suggestion] 「プロセス間で共有されるアプリ側ロックは 1 つも無い」は言い過ぎ
- 判断: **対応する**
- 根拠: 既定 cache が array であることから直接言えるのは「Laravel の既定 cache を使う
  プロセス間共有ロックが利用できない状態」までである。
- 対応内容: 主張文を「**Laravel の既定 cache を使うプロセス間共有ロックが利用できない状態で**」へ狭め、
  「`claim()` が unique 制約以外の補助機構を持たない」ことは**実装の実読（前提 P2）**として分離した。

---

## 施策 7

### [Warning] #9 は現在の `present()` では検出できない
- 判断: **対応する**（施策 4 のディレクトリ列挙で検出可能にした。#9 は残す）

### [Warning] env の安全性検査が改行拒否だけでは不足
- 判断: **対応する**
- 対応内容: **round-trip の正例**を追加した（空文字 / `\` / `"` / `#` / 前後空白 / `$` / `${NAME}`）。
  拒否だけでなく**正規入力を誤検出しない**ことも固定する。

### [Suggestion] 失敗経路 25 件を 4 グループへ分ける
- 判断: **対応する**
- 対応内容: `ProcessBarrier` / `ProbeEnvironment` / `ConcurrentProbeObservation` /
  `ConcurrencyProbeRunner` の 4 群に分け、テスト名の接頭辞も揃える。

---

## 施策 8（APPROVE）

指摘なし。

---

## 施策 9

### [Warning] ファイル数がまだ不一致（見出し 16 / 表 17 / 内訳 17）
- 判断: **対応する**
- 対応内容: **新規 17 本（支援 14 + Feature 2 + Unit 1）**へ統一した。
  今回の追加（`SignalName` / `ProbeDatabaseCoordinates` / `ConcurrentProbeResult`）を含めて
  数え直し、見出し・表・内訳・実装モードの 4 か所を同じ母集団から書き直した。

### [Suggestion] 「先行実装」は意味が逆に読める
- 判断: **対応する**
- 対応内容: 「**追従実装**」へ言い換え、
  「将来テンプレートから取り込むときに衝突するのは配置とクラス名だけであり、
  揃えないと決めた差（逸脱）ではない」という判断の書き方に改めた。


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

### 新規ファイル（20 本）

| # | パス | 施策 |
|---|---|---|
| 1 | `tests/Support/Concurrency/SignalName.php` | 1 |
| 2 | `tests/Support/Concurrency/ProcessBarrier.php` | 1 |
| 3 | `tests/Support/Concurrency/BarrierTimeoutException.php` | 1 |
| 4 | `tests/Support/Concurrency/ConcurrencyProtocolException.php` | 1 |
| 5 | `tests/Support/Concurrency/OutOfTransactionFixtures.php` | 2 |
| 6 | `tests/Support/Concurrency/ConcurrencyFixtureKeys.php` | 2 |
| 7 | `tests/Support/Concurrency/ConcurrentProbeObservation.php` | 3 |
| 8 | `tests/Support/Concurrency/ProbeDatabaseCoordinates.php` | 3 |
| 9 | `tests/Support/Concurrency/ProbeEnvironment.php` | 4 |
| 10 | `tests/Support/Concurrency/ProbeLaunchSpec.php` | 4 |
| 11 | `tests/Support/Concurrency/ProbeProcess.php` | 4 |
| 12 | `tests/Support/Concurrency/ProbeProcessFactory.php` | 4 |
| 13 | `tests/Support/Concurrency/SymfonyProbeProcess.php` | 4 |
| 14 | `tests/Support/Concurrency/SymfonyProbeProcessFactory.php` | 4 |
| 15 | `tests/Support/Concurrency/ConcurrentProbeResult.php` | 4 |
| 16 | `tests/Support/Concurrency/ConcurrencyProbeRunner.php` | 4 |
| 17 | `tests/Support/Concurrency/idempotency-claim-probe.php` | 5 |
| 18 | `tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php` | 6 |
| 19 | `tests/Feature/Concurrency/OutOfTransactionFixturesTest.php` | 2 |
| 20 | `tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php` | 7 |

> 内訳: `tests/Support/Concurrency` に **17 本**（うち 1 本は実行スクリプト）、
> `tests/Feature/Concurrency` に **2 本**、`tests/Unit/Support/Concurrency` に **1 本** = **20 本**。
> （家系の比較対象: laravel-claude-template は支援 11 + テスト 5 = 16 本）

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

- 新規: `tests/Support/Concurrency/SignalName.php`
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
6. **名前は型でしか作れない**: 下記 `SignalName` が**唯一の生成口**

### 設計 1-a: `SignalName`（合図名は型でしか作れない）

**検証を通す道と通さない道を並存させない。** メソッドの入口で `string` を受けると、
呼び出し側が検証を迂回して `../outside` を渡せてしまい、「任意文字列を受け付けない」が
構造的に成立しない。そこで**合図名を値オブジェクトにし、生成口を 1 つに絞る**。

```php
/**
 * 合図の名前 (これ以外の形は作れない)。
 *
 * ★`ProcessBarrier::name()` が**唯一の生成口**である。
 *   `ProcessBarrier` の他のメソッドはすべて `SignalName` を受け取り、`string` を受けない。
 *   これで `/` や `..` を含む名前は**型の段階で作れない** (入口ごとの再検証が要らない)。
 * ★種別ごとに child ID の要否が違う。`go-a` や `ready` (child ID 無し) のような
 *   語彙としては正しいがプロトコル上は不正な組合せも作れない。
 *
 * 生成できるのは次の **8 通りだけ**:
 *   go / release / ready-a / ready-b / entered-a / entered-b / out-a / out-b
 */
final readonly class SignalName
{
    /** child ID を**取らない**種別 (プロセス全体で 1 つの合図) */
    public const array GLOBAL_KINDS = ['go', 'release'];

    /** child ID を**必ず取る**種別 (子ごとの合図) */
    public const array PER_CHILD_KINDS = ['ready', 'entered', 'out'];

    /** child ID の形 (パス区切りを構造的に排除する) */
    public const string CHILD_ID_PATTERN = '/\A[a-z]\z/';

    /** @param non-empty-string $value */
    private function __construct(public string $value) {}

    /** 唯一の生成口 (ProcessBarrier::name() から呼ばれる) */
    public static function make(string $kind, ?string $childId = null): self
    {
        if (in_array($kind, self::GLOBAL_KINDS, true)) {
            Assert::null($childId, "{$kind} は child ID を取らない");

            return new self($kind);
        }

        Assert::oneOf($kind, self::PER_CHILD_KINDS);
        Assert::string($childId, "{$kind} は child ID が必須");
        Assert::regex($childId, self::CHILD_ID_PATTERN);

        return new self($kind.'-'.$childId);
    }

    /** 許可される完成合図の全集合 (未知の完成ファイルの検出に使う) */
    public static function allForChildren(array $childIds): array { /* list<self> */ }
}
```

### 設計 1-b: `ProcessBarrier`

**置き場所を 2 つに分ける**のが要点である。

- `{workspace}/signals/` — **完成した合図だけ**（列挙しても書きかけを拾わない）
- `{workspace}/partial/` — `rename()` 前の書きかけ

これで「完成ディレクトリを列挙して許可集合との差分を取る」という
**未知の完成ファイルの検出**が安全に行える（`.partial` を誤検出しない）。

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Closure;
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
 * 5. 合図は書きかけ用ディレクトリへ書いてから rename する (書きかけを相手に見せない)
 * 6. 名前は `SignalName` でしか作れない (このクラスは string の名前を受け取らない)
 *
 * ★**置き場所を 2 つに分ける**: 完成合図は signals/、書きかけは partial/。
 *   同じディレクトリに置くと、完成ファイルの列挙が書きかけを拾って
 *   二重実行の判定が壊れる。列挙を安全にするための分離である。
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

    private readonly ?Closure $reader;

    /** @param (callable(string): string|false)|null $reader 既定は file_get_contents */
    public function __construct(
        private readonly string $workspaceDirectory,
        ?callable $reader = null,
    ) {
        Assert::directory($workspaceDirectory);
        Assert::directory($this->signalDirectory());
        Assert::directory($this->partialDirectory());

        $this->reader = $reader === null ? null : Closure::fromCallable($reader);
    }

    /** 合図名の唯一の生成口 (SignalName へ委譲する) */
    public function name(string $kind, ?string $childId = null): SignalName
    {
        return SignalName::make($kind, $childId);
    }

    /** 合図を置く (partial/ へ書いてから signals/ へ rename) */
    public function signal(SignalName $name, string $payload): void
    {
        $temporary = $this->partialDirectory().'/'.bin2hex(random_bytes(8));

        if (file_put_contents($temporary, $payload) !== strlen($payload)) {
            throw ConcurrencyProtocolException::signalNotWritten($name);
        }

        // 同一 FS 内の rename なので原子的。
        if (! rename($temporary, $this->path($name))) {
            @unlink($temporary);
            throw ConcurrencyProtocolException::signalNotPlaced($name);
        }
    }

    /**
     * 合図が現れるまで待ち、その中身を返す。
     *
     * @param  float  $remainingSeconds 呼び出し側が持つ**絶対 deadline** からの残り時間
     * @param  (callable(): void)|null  $abortIf 待機中に毎周回呼ぶ中断条件
     *   (二重実行の検出・子の異常終了など。呼び先が例外を投げれば締切を待たずに抜ける)
     *
     * @throws BarrierTimeoutException 締切を超えた
     * @throws ConcurrencyProtocolException 合図はあるのに読めない
     */
    public function await(SignalName $name, float $remainingSeconds, ?callable $abortIf = null): string
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
     * 完成合図のディレクトリを**列挙**し、現れている名前を返す。
     *
     * ★prefix の glob は採らない。書きかけは別ディレクトリなので、ここでの列挙は
     *   完成ファイルだけを見る。
     * ★**許可集合に無い完成ファイルが 1 つでもあれば例外**にする
     *   (未知の child ID の合図を「無視」ではなく「拒否」にする)。
     *
     * @param  list<SignalName>  $allowed 許可される完成合図の全集合
     * @return list<SignalName>  現れている合図
     * @throws ConcurrencyProtocolException 未知の完成ファイルがある
     */
    public function present(array $allowed): array { /* scandir + 差分検査 */ }

    /**
     * 合図を読む。**読めない合図は空として通さず例外**にする (fail-closed)。
     *
     * 合図はあるのに読めない = 観測が成立していない。空として通すと後続の照合が
     * 別の理由で落ちて原因が隠れる。
     */
    private function read(SignalName $name): string
    {
        $reader = $this->reader ?? file_get_contents(...);
        $contents = $reader($this->path($name));

        if ($contents === false) {
            throw ConcurrencyProtocolException::signalUnreadable($name);
        }

        return $contents;
    }

    public function path(SignalName $name): string
    {
        return $this->signalDirectory().'/'.$name->value;
    }

    private function signalDirectory(): string { /* workspace . '/signals' */ }

    private function partialDirectory(): string { /* workspace . '/partial' */ }
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

- [x] 戻り値の型が明示されている（`present()` は `list<SignalName>`）
- [x] null 安全（`Assert::directory` / `Assert::oneOf` / `Assert::regex` / `Assert::greaterThan` / `Assert::null`）
- [x] **`mixed` を置かない** — `reader` は `?callable` で受けて `?Closure` で保持する
- [x] 配列は `list<SignalName>` に限定し、名前を素の `string` で回さない
- [x] Generics の型パラメータが正しい（`@param (callable(string): string|false)|null $reader`）

> `tests` は PHPStan の解析対象外なので、これは**静的検査による保証ではなく規律**である。
> 実効的な保証は施策 7 の失敗経路検査が担う（この境界は概念レビューで合意済み）。

### テスト計画

- [x] 施策 7 の **`ProcessBarrier` 群**が固定:
      締切で例外 / 読めない合図を通さない / 中断条件で締切を待たずに抜ける /
      書きかけを完成扱いしない / 未知の完成ファイルを拒否 /
      `go-a` や child ID 無しの `ready` のような不正な組合せを作れない
- [x] 個別の `DatabaseTransactions` を使わない（DB に触らない）

### リスク

- ポーリング間隔（1ms）が短すぎると CPU を食う。子 2 本・数秒の待ちなので許容範囲。
- 書きかけを別ディレクトリへ置くため、`rename()` が**同一 FS 内**であることに依存する
  （同じ workspace の直下なので成立する）。

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
/**
 * 作った検体の主キー (cleanup の対象を推測させないために持ち回る)。
 *
 * ★route 名は**持たない**。route 名を決めるのは runner であり、検体の生成時には
 *   まだ存在しない。掃除は `api_key_id` で足りる (idempotency_keys は cascade 対象)。
 */
final readonly class ConcurrencyFixtureKeys
{
    public function __construct(
        public int $organizationId,
        public int $laratrustTeamId,
        public int $userId,
        public int $apiKeyId,
    ) {}
}
```

**削除順（FK 安全）**:

| 順 | 表 | 条件 |
|---|---|---|
| 1 | `idempotency_keys` | `api_key_id = :apiKeyId`（cascade でも消えるが明示する。route 名で絞らないのは、掃除は取りこぼさないことが最優先だから） |
| 2 | `api_keys` | `organization_id = :organizationId` |
| 3 | `organization_user` | `organization_id = :organizationId` |
| 4 | `custom_teams` | `organization_id = :organizationId` |
| 5 | `organizations` | `id = :organizationId`（**query builder の物理削除**。softDeletes を迂回する） |
| 6 | `role_user` | `team_id = :laratrustTeamId`（teams 削除の cascade でも消えるが明示する） |
| 7 | `teams` | `id = :laratrustTeamId`（**組織を消した後**でなければ restrict で落ちる） |
| 8 | `users` | `id = :userId`（`current_organization_id` は 5 で null 化済み） |

**残留ゼロの検査は `cleanup()` 自身の契約にする**（cascade の当て推量を検査に置き換える）:

```php
/**
 * 呼び出し側が finally で呼ぶ。冪等 (何度呼んでも安全)。
 *
 * ★**削除したあと、自分で残留ゼロを検査する**。呼び出し側のテストだけに任せると、
 *   見本テストの後始末の完全性が「別のテストが緑であること」に依存してしまう。
 *   1 行でも残っていれば例外にする (後続テストを汚した状態で静かに通らない)。
 */
public static function cleanup(ConcurrencyFixtureKeys $keys): void
{
    try {
        self::deleteInForeignKeySafeOrder($keys);   // 下表の 8 段
        self::assertNoResidue($keys);               // 下記 8 表で 0 件
    } finally {
        DB::disconnect(self::CONNECTION_NAME);
        DB::purge(self::CONNECTION_NAME);
    }
}
```

検査する 8 表: `idempotency_keys` / `api_keys` / `organization_user` / `custom_teams` /
`organizations` / `role_user` / `teams` / `users`（それぞれ対象の主キー・外部キーで数えて 0 件）。

`OutOfTransactionFixturesTest`（施策 2）は「**この契約が効いていること**」を固定する側に回る
（わざと 1 表を消し残す偽の削除で `assertNoResidue()` が落ちることを確かめる）。

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
- 新規: `tests/Support/Concurrency/ProbeDatabaseCoordinates.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 7 が本部品の fail-closed を固定する

### 設計

子が返す JSON は**外部入力**である。`tests` は PHPStan の解析対象外なので、
型の保証は**実行時の fail-closed 検証**で作る（概念レビューで明示的に合意した境界）。

**観測項目**（前回設計から大幅に増やした。理由は下の 2 点）:

```php
/** @var list<string> 受理する JSON のキー (deny-by-default。過不足があれば例外) */
private const array REQUIRED_KEYS = [
    // 同一性 (起動時の割り当て・親が出した go token との突合)
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

**DB 座標は型付き DTO にする**: `db_port` は `int`、他は `string` である。
`array<string, string>` で持つと厳密比較のために暗黙のキャストが要り、
「外部観測をキャストで救わない」という本設計の方針と矛盾する。

```php
/** DB 接続座標 (親の期待値も子の観測も同じ型で持ち、同型どうしで厳密比較する) */
final readonly class ProbeDatabaseCoordinates
{
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,        // ★int で持つ (文字列にしてキャストで比べない)
        public string $database,
        public string $username,
        public string $charset,
        public string $sslmode,
        public string $url,      // ★空文字のみ許可 (非空は fail-closed)
    ) {}

    /** 親側: 実行時の実接続設定から作る */
    public static function fromParentConfig(): self { /* … */ }

    /** 子側の観測 JSON から作る (fail-closed) */
    public static function fromDecodedJson(array $value): self { /* … */ }

    public function equals(self $other): bool { /* 全項目の厳密比較 */ }
}
```

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
    private function __construct(
        public string $childId,
        public string $nonce,
        public string $goToken,
        public int $httpStatus,
        /** ★勝者は null、敗者は 'idempotency_in_progress' (409 は 3 コードあるので必須) */
        public ?string $errorCode,
        public int $handlerExecutions,
        public bool $enteredHandler,
        public string $routeName,
        public string $uri,
        public string $requestHash,
        /** ★入力のコピーではなく、認証後の ApiActorContext から観測した値 */
        public int $apiKeyId,
        public string $cacheDefault,
        public string $cacheDriver,
        public ProbeDatabaseCoordinates $database,
    ) {}

    /** @throws ConcurrencyProtocolException 解釈できない観測は通さない */
    public static function fromDecodedJson(mixed $value): self { /* … */ }

    /** 起動時の割り当て・親が出した go token と食い違ったら通さない */
    public function assertIdentity(string $childId, string $nonce, string $goToken): void { /* … */ }

    /** 敗者としての条件 (release の前提)。満たさなければ例外 */
    public function assertLost(string $expectedRequestHash): void
    {
        // http_status === 409
        // かつ error_code === ApiErrorCode::IdempotencyInProgress->value
        //   (★idempotency_conflict / idempotency_indeterminate は通さない)
        // かつ handler_executions === 0 かつ entered_handler === false
        // かつ request_hash === $expectedRequestHash (親が計算した期待値)
    }

    /** 守りたい層以外が無効化されていたか (要素 (3)) */
    public function assertAppLocksDisabled(): void
    {
        // cache_default === 'array' かつ cache_driver === 'array'
    }

    /** 親が渡した DB 座標と完全一致するか (開発 DB 到達の検出) */
    public function assertDatabaseCoordinates(ProbeDatabaseCoordinates $expected): void { /* equals() */ }
}
```

`fromDecodedJson()` の検証（すべて満たさなければ `ConcurrencyProtocolException`）:

1. `$value` が `array<string, mixed>` である
2. キー集合が `REQUIRED_KEYS` と**完全一致**する（欠落も余剰も不可）
3. 各値が期待するスカラー型である（`is_string` / `is_int` / `is_bool` を個別に確認。
   **`(int)` などのキャストで通さない**）
4. `error_code` は **`null` か非空文字列**のみ（空文字は通さない）
5. `handler_executions >= 0` / `http_status` が 100〜599 / `db_url` が**空文字**
6. `entered_handler` と `handler_executions` の整合
   （`true` なら `>= 1`、`false` なら `0`。矛盾する組合せを通さない）

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（`mixed` を受けて明示的に判定し、専用例外へ倒す。`errorCode` は `?string`）
- [x] DTO（配列を素で回さない。DB 座標も `ProbeDatabaseCoordinates` 型）
- [x] Generics の型パラメータが正しい（`@var list<string> REQUIRED_KEYS`）

### テスト計画

- [x] 施策 7 の **`ConcurrentProbeObservation` 群**が固定:
      必須キー欠落 / 未知キー / 型違い（`http_status` が `"409"` でも通さない）/
      `error_code` が空文字なら通さない / `entered_handler` と `handler_executions` の矛盾を通さない /
      `assertIdentity` の childId・nonce・**go token** 不一致 /
      `assertLost` が **`idempotency_conflict` を通さない** / request_hash 不一致を通さない /
      `assertDatabaseCoordinates` が host 違い・port 違い・username 違いを通さない / `db_url` 非空を通さない

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
- 新規: `tests/Support/Concurrency/ConcurrentProbeResult.php`
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
     * env ファイルの 1 行を組み立てる (書式は 1 つだけ)。
     *
     * 形式: `KEY="value"` — 値は必ず二重引用符で囲み、**`\` / `"` / `$` の 3 文字**を
     * バックスラッシュでエスケープする。
     *
     * ★`$` をエスケープするのは、**phpdotenv が二重引用符の中で `$VAR` / `${VAR}` を
     *   変数展開するため**である。エスケープしないと、パスワードに `$` が入っていた場合に
     *   実効値が変わる (子が接続できない、あるいは別の値で接続する)。
     * ★`#` と空白と空文字は引用符の内側にあるので特別扱いは要らない。
     * ★子側の厳格パーサ (`parseEnvFile()`) は**この 1 形式だけ**を受理し、
     *   同じ規則で復号する。往復 (encode → parse → bootstrap 後の実効値) が
     *   一致することは施策 7 の正例で固定する。
     */
    public static function encodeLine(string $key, string $value): string
    {
        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

        return $key.'="'.$escaped.'"'."\n";
    }

    /** 上の書式だけを受理する厳格パーサ (bootstrap 前の検査に使う) */
    public static function parseEnvFile(string $path): array { /* array<string, string> */ }

    /**
     * 保護されたファイルを作る (作成時点から 0600)。
     *
     * ★`FakeWiringProbeRunner::writeEnvFile()` と同じ手順を踏む:
     *   1. `fopen($path, 'x')` で作る (既存ファイルがあれば失敗 =
     *      乗っ取られた置き場所へ書き足さない)
     *   2. **中身を書く前に** `chmod($path, 0600)` する (書いてから絞ると短時間の露出が残る)
     *   3. 書き切れなかった / 閉じられなかったら fail-closed で例外
     */
    public static function writeProtectedFile(string $path, string $contents): void { /* … */ }

    /** ディレクトリ 0700・env ファイル 0600・入力ファイル 0600 でなければ例外 (子を起こさない) */
    public static function assertSafePermissions(int $directoryMode, int $envFileMode, int $inputFileMode): void { /* … */ }
}
```

**秘密の渡し方**: plain API key と request body は **argv に載せない**（プロセス一覧から読める）。
0700 のディレクトリ配下に **0600 の入力ファイル**（`input.json`）を作り、そのパスだけを argv に載せる。

### 設計 4-a-2: request の raw bytes と期待 hash（親が正本を持つ）

`IdempotentRequest::hashRequest()` は実読すると次のとおりである:

```php
return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
```

したがって **`getContent()`（raw body）が hash の正本**である。ここで踏みやすい罠が 1 つある:

> `Request::create($uri, 'POST', $parameters, …)` の**第 3 引数へ配列を渡すと
> form parameter として扱われ、`getContent()` は空文字になる**。
> 親が「この body を送ったはず」と思って計算した hash と、middleware が見る内容が食い違う。

**契約**（親が正本を作り、子は運ぶだけ）:

1. 親が `json_encode($body, JSON_THROW_ON_ERROR)` で **raw JSON を 1 回だけ**生成する
2. **同じバイト列**を両子の入力ファイル（0600）へ書く
3. 子は `Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', …], $rawBytes)`
   の**第 7 引数 content** へそのまま渡す（第 3 引数は空配列）
4. 親の期待 hash は `hash('sha256', 'POST|'.$path.'|'.$rawBytes)`
   （`$path` は `Request::path()` と同じく**先頭の `/` を含まない**形）
5. **勝者の hash / 敗者の hash / 親の期待 hash の 3 点一致**を検査する
   （2 子が一致するだけでは「2 本とも同じ誤った body を送った」形と区別がつかない）

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
    /** 上限つきで終了を待ち、終了コードを返す (時間内に終わらなければ null) */
    public function waitFor(float $seconds): ?int;
}
```

`SymfonyProbeProcessFactory` / `SymfonyProbeProcess` が `Symfony\Component\Process\Process` を包む唯一の実装。

> **`waitFor()` は Symfony の `wait()` を包まない。** Symfony の `Process::wait()` は
> 秒数を受け取る API ではない（`waitUntil()` は述語を取るがタイムアウトは Process 自身の設定に依る）。
> `SymfonyProbeProcess` は **`isRunning()` と単調時計 (`hrtime`) で bounded wait を自前実装する**
> （ポーリング + 上限）。この実装方針を docblock に明記する。

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
    /** **作業の締切** (子の起動 + 合図 + 要求 + 通常の終了待ちを打ち切る) */
    public const float DEFAULT_TIMEOUT_SECONDS = 60.0;

    /**
     * **回収専用の予算** (作業の締切とは独立に確保する)。
     *
     * ★作業の締切を回収にも使うと、**締切超過の瞬間に残り時間が 0** になり、
     *   まさに回収が必要な場面で kill 後の待機ができず子が残る。
     *   回収は「残り時間にかかわらず必ず完遂する」ものなので、予算を分けて持つ。
     *   実時間は「作業の締切 + 最大 REAP_BUDGET_SECONDS」になる。
     */
    private const float REAP_BUDGET_SECONDS = 2.0;

    /** SIGTERM から SIGKILL までの猶予 (REAP_BUDGET_SECONDS の内側) */
    private const float REAP_GRACE_SECONDS = 1.0;

    /** 子の識別子 (固定 2 本。N 本への一般化はしない) */
    public const array CHILD_IDS = ['a', 'b'];

    public static function run(
        string $idempotencyKey,
        string $plainApiKey,
        array $requestBody,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?ProbeProcessFactory $factory = null,
    ): ConcurrentProbeResult { /* … */ }

    /** 作業の残り時間 (絶対 deadline から算出。0 以下なら BarrierTimeoutException) */
    private static function remainingWorkSeconds(int $workDeadlineNs): float { /* … */ }
}
```

### 設計 4-d: `ConcurrentProbeResult`（内部プロトコルを漏らさずに裏取りの材料を渡す）

nonce と go token は**内部プロトコル**なので外へ出さない（同一性の検査は runner 内で完結させる）。
一方、見本テストは行の裏取りに route 名・key・期待 hash が要る。そこだけを返す。

```php
/**
 * runner の結果。
 *
 * ★nonce / go token は**持たない**。同一性の検査 (assertIdentity) は runner の中で
 *   完結しており、内部プロトコルをテストへ漏らさない。
 * ★代わりに、行の裏取り (idempotency_keys のスコープと request_hash) に要る値だけを渡す。
 */
final readonly class ConcurrentProbeResult
{
    /** @param array<string, ConcurrentProbeObservation> $observations childId => 観測 */
    public function __construct(
        public array $observations,
        public string $routeName,
        public string $uri,
        public string $idempotencyKey,
        /** 親が middleware と同一規則で計算した期待 hash (§施策 5) */
        public string $expectedRequestHash,
    ) {}

    /** entered_handler で勝者・敗者に分ける (ちょうど 1:1 でなければ例外) */
    public function partition(): array { /* array{ConcurrentProbeObservation, ConcurrentProbeObservation} */ }
}
```

**`release` を置く条件**（1 つでも欠けたら release せずその場で失敗させる）:

| # | 条件 | 検査 |
|---|---|---|
| 1 | `entered` が**ちょうど 1 子**ぶん | `present()` が完成合図ディレクトリを列挙し、許可集合との差分が無く `entered-*` が 1 件 |
| 2 | その `entered` の中身が **nonce + go token** と一致 | 文字列一致 |
| 3 | 反対側の `out` が**原子的に完成**している | rename 済みのファイルとして読める |
| 4 | その `out` の **childId / nonce / go token が一致** | `assertIdentity()` |
| 5 | その `out` が **409 + `idempotency_in_progress` + ハンドラ 0 + entered=false** かつ **`request_hash` が親の期待値と一致** | `assertLost($expectedRequestHash)`（`idempotency_conflict` を通さない = 2 子が同一要求だったことの証明） |

**中断条件**（締切を待たずに抜ける。3〜5 の待機中は毎周回チェック）:

- `entered` が **2 つ**現れた → `ConcurrencyProtocolException::doubleExecution()`
  （**探している退行そのもの**なので、締切超過という紛らわしい形で出さない）
- **未知の完成合図**が現れた（許可集合に無い child ID など）→
  `ConcurrencyProtocolException::unknownSignal()`（無視ではなく拒否）
- 子が観測を出さずに終了した → `ConcurrencyProtocolException::childDiedEarly()`（`stderr` を添える）

**受理条件**（すべて満たさなければ例外）:

1. 両 process の **exit code が 0**
2. 各子の **stdout の JSON と `out` ファイルの中身が一致**
3. 観測 2 件がそれぞれ `assertIdentity()`（**runner 内で完結**）/ `assertAppLocksDisabled()` /
   `assertDatabaseCoordinates()` を通る
4. 勝者・敗者が **ちょうど 1:1** に分かれる（`entered_handler` で判定）
5. 勝者・敗者・**親の期待値**の **`request_hash` が 3 点一致**する

**片付け**（`finally` で必ず通る。締切超過・JSON 解釈失敗・`Process` の例外のいずれでも）:

1. 生きている子へ `signalTerminate()` → `waitFor(REAP_GRACE_SECONDS)` →
   まだ生きていれば `signalKill()` → `waitFor(REAP_BUDGET_SECONDS - 経過)`
   — **作業の締切の残り時間には依存しない**（回収専用の予算を使う）
2. 一時ディレクトリ（`signals/` ・`partial/` ・出力・env ファイル・入力ファイル）を再帰削除

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`ConcurrentProbeResult`）
- [x] null 安全（`Assert` + `?int` の明示。`exitCode()` / `waitFor()` の `null` を「不明」として扱う）
- [x] DTO を返している（`ConcurrentProbeResult` / `ConcurrentProbeObservation` / `ProbeLaunchSpec` / `ProbeDatabaseCoordinates`）
- [x] Generics の型パラメータが正しい（`array<string, ConcurrentProbeObservation>` / `list<SignalName>`）

### テスト計画

施策 7 が偽 `ProbeProcessFactory` を差して固定する（詳細は施策 7 の表）。
子プロセスを起こさずに検査できるのは、**起動仕様が値になっている**からである。

### リスク

- **最大の危険は開発 DB への到達**。遮断は 9 段（§子プロセスの遮断段）で、
  そのうち親側 5 段（`DB_URL` 前提検査 / DB 名の allowlist 検査 / 許可キー検査 / 値の改行検査 / 権限検査）は
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
| 7 | 子 | env ファイルを**自前の厳格パーサで解析**し、キー集合・型・DB 名を検証する（**`loadEnvironmentFrom()` はその場で解析しない** — 起動時に読む場所を指定するだけなので、bootstrap 前の検査には自前解析が要る）。DB 名は `assertPgsqlTestDatabaseSafe()` へ通す |
| 8 | 子 | `useEnvironmentPath()` / `loadEnvironmentFrom()` → `bootstrap()`。`APP_CONFIG_CACHE` は一時ディレクトリ配下の**存在しない絶対パス**（共有の `bootstrap/cache` を作らない・消さない） |
| 9 | 子 | bootstrap 後・**ready を出す前**に、(a) 実効 DB 座標が宣言と一致すること、(b) 既定 cache が `array` であること、(c) **自前パーサの結果と bootstrap 後の実効値が一致すること**（二重解釈のずれの検出）を検査し、**違えば ready を出さずに非ゼロ終了**する |

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
//
// ★`$goToken` は**参照キャプチャ**である。closure を定義する時点ではまだ go を待っておらず、
//   値キャプチャでは後の代入が反映されない (この closure は go の後にしか実行されないが、
//   値キャプチャだと**空文字を合図に書いてしまう**)。
//   先頭の Assert は「万一 go より先に handler へ入った」場合に**黙って空を書かず落ちる**ための門である。
$goToken = null;
$apiKeyId = null;

Route::post($uri, function (Request $request) use (
    $barrier, $childId, $nonce, &$goToken, &$apiKeyId, $remainingSeconds, &$handlerExecutions,
): JsonResponse {
    Assert::stringNotEmpty($goToken);
    $handlerExecutions++;

    // ★api_key_id は**入力のコピーではなく認証結果から**取る
    //   (入力を書き戻すと「認証が通ったこと」を何も示さない)。
    $apiKeyId = observedApiKeyId($request);

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
//
// ★第 3 引数 ($parameters) は**空配列**である。ここへ body を渡すと form parameter として
//   扱われ `getContent()` が空になり、middleware が hash する内容が親の期待値と食い違う。
//   raw bytes は**第 7 引数 (content)** へ渡す。
$probeRequest = Request::create(
    uri: $uri,
    method: 'POST',
    parameters: [],
    cookies: [],
    files: [],
    server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => "Bearer {$plainApiKey}",
        'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
    ],
    content: $rawBody,   // ★親が作った raw bytes をそのまま運ぶ (子は作り直さない)
);
$response = $httpKernel->handle($probeRequest);

// 敗者は handler へ入らないので、middleware が置いた attribute から認証結果を取る
// (`resolve.api-actor` は `idempotent` より前に走るので、409 の場合も attribute は在る)。
$apiKeyId ??= observedApiKeyId($probeRequest);

// 観測を書く。stdout と out ファイルへ**同じ JSON** を出す (親が一致を検査する)。
fwrite(STDOUT, $json);
$barrier->signal($barrier->name('out', $childId), $json);
exit(0);
```

**`error_code` の取り方**: 応答が 2xx なら `null`、それ以外は応答 body の
`error.code`（`ApiErrorResource` の形）を読む。読めなければ `fromDecodedJson()` 側で弾かれるよう
非空文字列を必ず入れる（黙って `null` にしない）。

**なぜ HTTP kernel を通すのか**: `IdempotentRequest` は
`ApiActorContext` attribute を前提にした順序契約を持ち、配線ミスは fail-closed で 500 になる。
middleware 単体を手で呼ぶとこの契約ごと迂回してしまう。実サーバは立てず、
`Kernel::handle()` でプロセス内の実 middleware 列を通す。

**route 名の一致が load-bearing**: claim のスコープは `(api_key_id, route_name, key)`（前提 P4）。
2 子は**同じ route 名**で登録しなければ衝突しない。
route 名・URI・raw body・Idempotency-Key はすべて**親が決めて入力ファイルで渡す**
（`--parallel` でも他と衝突しないよう nonce 込みにする）。

**body が同一であることが load-bearing**: 違うと `request_hash` が変わり
`idempotency_conflict`（409 の別コード）になり、**status だけを見るテストは緑のまま嘘になる**。
親が raw bytes を 1 回だけ作って両子へ同じものを渡し、
**勝者 hash / 敗者 hash / 親の期待 hash の 3 点一致**で裏を取る（§設計 4-a-2）。

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
> 同一 actor・同一 route・同一 Idempotency-Key・**同一 raw body** の書き込み要求を送ったとき、
> `IdempotentRequest` middleware が**本処理を通したのはちょうど 1 本**であり、
> もう 1 本は本処理を実行せずに **`idempotency_in_progress` (409)** で弾かれる。
> **Laravel の既定 cache を使うプロセス間共有ロックが利用できない状態**で、である。」

主張の**言い方を狭めている**点に注意する。既定 cache が `array` であることから直接言えるのは
「Laravel の既定 cache を経由するプロセス間共有ロックが使えない」までであり、
「アプリ側ロックが 1 つも無い」まで言うと観測を超える。
`claim()` が unique 制約以外の補助機構を持たないことは**実装の実読（前提 P2）**として分離し、
2 つを合わせて初めて「DB の一意制約だけで 1 回に収まる」と読む。

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
    $expectedCoordinates = ProbeDatabaseCoordinates::fromParentConfig();

    // 検体はテストの transaction の**外**に作る (子から見えなければ成立しない)。
    // ★route 名はここでは決まらない (runner が決める) ので、鍵は 4 つだけ持つ。
    [$keys, $plainKey] = OutOfTransactionFixtures::create(function (): array {
        [$organization, $owner] = createOrganizationWithOwner();
        [$apiKey, $plain] = issueApiKey($organization, $owner);

        return [new ConcurrencyFixtureKeys(
            organizationId: $organization->id,
            laratrustTeamId: $organization->laratrust_team_id,
            userId: $owner->id,
            apiKeyId: $apiKey->id,
        ), $plain];
    });

    try {
        // ★同一性 (childId / nonce / go token) の検査は runner の中で完結している。
        //   ここで再検査しない = 内部プロトコルをテストへ漏らさない。
        $result = ConcurrencyProbeRunner::run(
            idempotencyKey: (string) Str::uuid(),
            plainApiKey: $plainKey,
            requestBody: ['title' => '並行 claim の検体'],
        );

        expect($result->observations)->toHaveCount(2);

        // (1) ハンドラ実行回数の**合計が 1** ← 一次観測。本テストの核心
        $executions = array_sum(array_map(
            fn (ConcurrentProbeObservation $o): int => $o->handlerExecutions,
            $result->observations,
        ));
        expect($executions)->toBe(1);

        // (2) 勝者は 201 / entered=true、敗者は 409 + idempotency_in_progress / entered=false
        //     ★status だけでは足りない — 409 は 3 コードあり (P3)、body 違いの conflict でも
        //       (1) まで成立して**緑になる**。error_code の完全一致で塞ぐ。
        [$winner, $loser] = $result->partition();
        expect($winner->httpStatus)->toBe(201);
        expect($winner->handlerExecutions)->toBe(1);
        expect($winner->errorCode)->toBeNull();
        expect($loser->httpStatus)->toBe(409);
        expect($loser->errorCode)->toBe(ApiErrorCode::IdempotencyInProgress->value);
        expect($loser->handlerExecutions)->toBe(0);

        // (3) 2 子は**同一要求**だった。親の期待 hash を含めた**3 点一致**で見る
        //     (2 子の一致だけだと「2 本とも同じ誤った body を送った」形と区別がつかない)
        expect($winner->requestHash)->toBe($result->expectedRequestHash);
        expect($loser->requestHash)->toBe($result->expectedRequestHash);
        expect($winner->routeName)->toBe($result->routeName);
        expect($loser->routeName)->toBe($result->routeName);

        // (4) 認証結果の api_key_id が**検体のもの**と一致する
        //     (★入力のコピーではなく ApiActorContext から観測した値である)
        expect($winner->apiKeyId)->toBe($keys->apiKeyId);
        expect($loser->apiKeyId)->toBe($keys->apiKeyId);

        // (5) 2 子とも既定 cache が array
        //     (= Laravel の既定 cache を使うプロセス間共有ロックが利用できない状態)
        foreach ($result->observations as $observation) {
            $observation->assertAppLocksDisabled();
        }

        // (6) 2 子の実効 DB 座標が親の値と**完全一致**
        //     (driver/host/port/database/username/charset/sslmode。url は空のみ許可)
        foreach ($result->observations as $observation) {
            $observation->assertDatabaseCoordinates($expectedCoordinates);
        }

        // (7) 裏取り: 行は 1 本だけで completed (**別名接続で読む**)。
        //     ★スコープ (api_key_id + route_name + key) まで絞り、
        //       保存された request_hash も親の期待値と突き合わせる。
        $rows = OutOfTransactionFixtures::connection()
            ->table('idempotency_keys')
            ->where('api_key_id', $keys->apiKeyId)
            ->where('route_name', $result->routeName)
            ->where('key', $result->idempotencyKey)
            ->get();
        expect($rows)->toHaveCount(1);
        expect($rows[0]->state)->toBe(IdempotencyState::Completed->value);
        expect($rows[0]->response_status)->toBe(201);
        expect($rows[0]->request_hash)->toBe($result->expectedRequestHash);

        // (8) スコープ外に余分な行が無い (api_key_id 全体で 1 件)
        $all = OutOfTransactionFixtures::connection()
            ->table('idempotency_keys')->where('api_key_id', $keys->apiKeyId)->count();
        expect($all)->toBe(1);
    } finally {
        // 子が commit した行は RefreshDatabase の rollback では消えない。必ず片付ける。
        // ★cleanup() は削除後に自分で 8 表の残留ゼロを検査する (施策 2)。
        OutOfTransactionFixtures::cleanup($keys);
    }
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

**テストは 4 群に分ける**（失敗時に原因を追いやすくするため、名前の接頭辞も揃える）。

#### 群 1: `ProcessBarrier`（合図）

| # | 固定する挙動 |
|---|---|
| 1 | 現れない合図を待ち続けず**締切で例外**になる |
| 2 | 合図はあるのに読めないときは**空として通さず**落ちる（偽の読み手が `false` を決定的に返す。権限に依存しない） |
| 3 | 中断条件が成立したら**締切を待たずに**抜ける |
| 4 | **書きかけ（`partial/`）を完成した合図として扱わない** |
| 5 | **未知の完成ファイル**が置かれたら列挙時に**拒否する**（無視しない） |
| 6 | `go-a` / `release-b`（global 種別に child ID）を**作れない** |
| 7 | child ID 無しの `ready` / `entered` / `out` を**作れない** |
| 8 | `/` や `..` を含む child ID を**作れない** |

#### 群 2: `ProbeEnvironment`（遮断）

| # | 固定する挙動 |
|---|---|
| 9 | `DB_URL` が非空なら**子を起こさない** |
| 10 | dev DB 名なら**子を起こさない**（`assertPgsqlTestDatabaseSafe` 経由） |
| 11 | 許可キー以外を env ファイルへ**書かない** |
| 12 | **env 値に改行 / CR があれば書かずに例外**（キー注入の拒否） |
| 13 | **round-trip の正例**: 空文字 / `\` / `"` / `#` / 前後の空白 / `$` / `${NAME}` を含む値が、`encodeLine()` → `parseEnvFile()` で**元の値に戻る**（`$` の展開で値が変わらないこと。拒否だけでなく**正規入力を誤検出しない**ことも固定する） |
| 14 | 0700 / 0600 以外の権限では**子を起こさない** |
| 15 | 保護ファイルは**作成時点で 0600**（既存ファイルがあれば `fopen('x')` で失敗する） |
| 16 | **未知の `DB_*` / `APP_*` がプロセス環境に混入していたら拒否する**（`env -i` の退行。子の段 6 の判定を純関数として切り出して叩く） |

#### 群 3: `ConcurrentProbeObservation`（観測の型）

| # | 固定する挙動 |
|---|---|
| 17 | 必須キー欠落 / 未知キー / 型違いを**通さない**（キャストで救わない。`http_status` が `"409"` でも通さない） |
| 18 | `error_code` が**空文字**なら通さない（勝者は `null`、敗者は非空） |
| 19 | `entered_handler` と `handler_executions` の**矛盾**を通さない |
| 20 | `assertIdentity` が childId / nonce / **go token** の不一致を通さない |
| 21 | `assertLost` が **`idempotency_conflict` を通さない**（409 でも別コードなら失敗） |
| 22 | `assertLost` が **request_hash 不一致**を通さない |
| 23 | `assertDatabaseCoordinates` が host / port / username 違いを通さない。**`db_url` 非空**も通さない |

#### 群 4: `ConcurrencyProbeRunner`（調停と回収）

| # | 固定する挙動 |
|---|---|
| 24 | **ready の nonce が割り当てと違えば go を出さない** |
| 25 | **go token は ready 検証の後に生成される**（事前に子へ渡らない） |
| 26 | `entered` が 2 つ出たら**締切を待たず**「二重実行を検出」で落ちる |
| 27 | 未知 child ID の `entered` が現れたら**拒否する** |
| 28 | 子が観測を出さずに終わったら**観測なしのまま通さない** |
| 29 | 敗者の `out` の検査（identity / 409 / `idempotency_in_progress` / ハンドラ 0 / request_hash）を通らなければ **release を置かない** |
| 30 | **stdout の JSON と `out` ファイルが不一致**なら通さない |
| 31 | **exit code が非ゼロ**なら通さない |
| 32 | 勝者・敗者が **1:1 に分かれない**（両方 entered=false 等）なら通さない |
| 33 | **作業の締切は段ごとに更新されない**（合図を 3 回待っても総時間が作業の締切を超えない） |
| 34 | 応答しない子へ **`signalTerminate()` → `waitFor()` → `signalKill()` → `waitFor()` が順に要求される**（偽 `ProbeProcess` の呼び出し記録で順序込みに固定） |
| 35 | **作業の締切を使い切った後でも**回収は完遂する（回収専用の予算が独立に確保されている） |

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
**本設計が触る全 23 パス**（新規 20 + 変更 3）を突き合わせた:

| パス群 | 共有パスか | 判断 |
|---|---|---|
| `tests/Support/Concurrency/*`（新規 17） | **無い** | テンプレートに無い領域への上積み |
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
これは逸脱（揃えないと決めた差）ではなく、**同じ正典への aicue 側の追従実装**なので D 登録はしない
（登録簿に載せるべきは「揃えないと決めた差」であって、「同じものを自前で満たした」ことではない）。
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
| 判断根拠 | (1) 新規 20 本・変更 3 本と規模が大きく、うち 4 本（`ProbeEnvironment` / `idempotency-claim-probe.php` / `ConcurrencyProbeRunner` / `OutOfTransactionFixtures`）は**開発 DB 保護**と**検体の commit**に関わるため、独立した worktree で赤を確認しながら進めたい。(2) 実プロセスを起こすテストは環境依存が出やすく、他施策と混ぜると切り分けが難しい。(3) `docs/` の変更（施策 9）が乖離台帳の形式検査に関わるため、単独のコミット列で追える形が望ましい |
| 競合リスク | **低**。`app/` を 1 バイトも触らないため、アプリ機能の改修とは衝突しない。触る既存ファイルは 3 本のみ（`IdempotencyConcurrentClaimTest.php` の docblock / `docs/template-divergence.md` の D7 内 / `docs/architecture.md` の 1〜2 行）。ただし**乖離台帳を触る別 TODO が並走すると `LedgerPins` の件数で衝突しうる**（本設計は件数を変えないので解決は容易） |

---

## 最終確認（使命・禁止事項チェック）

| 観点 | 確認 |
|---|---|
| 使命への寄与 | REST API v1 の write 経路で「同じ要求が同時に来ても本処理は一度だけ」が実経路の証拠を持つ。「思考ゼロ」の前提（作業者へ二重の指示が出ない）を支える土台。ただし主張は middleware 契約までに狭め、撮影・レンダの二重実行防止は**帰結**として書き分けた |
| 禁止 1（テストなしの実装完了） | 全施策にテスト計画がある。ハーネス自身にも失敗経路の検査 **35 件**（4 群）を付ける |
| 禁止 2（PHPStan の widen / baseline） | `phpstan.neon` を**変更しない**。`ignoreErrors` も足さない。`app/` を触らないので新規エラーも出ない |
| 禁止 3（dev DB への破壊操作） | 遮断は **9 段**。うち親側 5 段（`DB_URL` 前提 / DB 名 allowlist / 許可キー / 値の改行 / 権限）は子を起こさずに単体検査できる。子はマイグレーションを実行しない |
| 禁止 4（`response()->json()` 直書き） | 該当なし（probe route の応答は `new JsonResponse(...)`。アプリの API 契約には現れない） |
| 禁止 5・6（LLM / prompt） | 該当なし（LLM を呼ばない） |
| 禁止 7・8（HTTP 応答 / UI） | 該当なし（UI を触らない） |
| 禁止 9（Artifact） | 成果物は本 devnotes 配下のファイルとして出力する |
| `DatabaseTransactions` の個別使用 | しない（`RefreshDatabase` のグローバル適用のまま。検体だけを別名接続で transaction の外へ出す） |
| Factory 使用 | 検体は `createOrganizationWithOwner()`（Factory + provisioning service）と `issueApiKey()`（正規ドメイン生成 helper）で作る。`Model::create()` の手組みはしない |
| DESIGN.md / Atomic Design | 該当なし（UI / frontend の変更が 0 件） |

