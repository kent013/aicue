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
