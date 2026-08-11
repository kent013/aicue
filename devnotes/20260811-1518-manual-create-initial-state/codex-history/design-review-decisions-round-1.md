# 対応マトリクス: design-review Round 1

Codex 全体判定: CHANGES_REQUESTED
（施策 1 = APPROVE / 施策 2・3・4・5 = REQUEST_CHANGES。Critical 0 / Warning 8 / Suggestion 3）

**全 8 Warning に対応する。反論なし。**

## [Suggestion] 施策 1: 「hydrate 済み」という語が不正確

- 判断: **対応する**
- 根拠: 正しい。DB から hydrate されるのではなく、Eloquent インスタンスに属性が明示セットされる。
  語の不正確さは docblock に残ると後続の読者を誤らせる。
- 対応内容: 詳細設計・docblock 案の「戻り値インスタンスが hydrate 済みになる」を
  「**戻り値インスタンス上でも `status` / `scenario_version` が読み出せるようになる**」に置換。

## [Warning] 施策 2: category あり / document あり の経路をテストしていない

- 判断: **対応する**
- 根拠: 重点確認項目 (d) を自分で挙げておきながら、提案テストは `categoryId=null` /
  `document=null` の最短経路だけだった。とくに **pipeline-smoke が踏んだ実際の形は
  `document` あり**であり、`appendDocument()` と `category()->associate()->save()`
  （**2 度目の save**）を通した後も戻り値の属性が保持されることこそ固定すべき契約である。
- 対応内容: fail-first テスト（status 契約）を **category あり + document あり**に寄せ、
  `category_id` / `sourceDocuments()->count()` の assert も加える。
  `UploadedFile::fake()` + `Storage::fake()` の使用と import を設計に明記する。

## [Warning] 施策 2: mutation ② の「もう片方は緑のまま観測できる」は成立しない

- 判断: **対応する**
- 根拠: 正しい。同一テスト内では最初の失敗で停止するため、片方だけ赤くなることを観測できない。
  設計が自分で「同時に消すと観測できない」と書きながら、同じ罠に落ちていた。
- 対応内容: **テストを属性ごとに分割する**（status 契約テスト / scenario_version 契約テスト）。
  こうすれば mutation ②-a は status テストのみ赤・scenario_version テストは緑、
  ②-b はその逆、として**実際に観測できる**。
  「片方は緑のまま」という表現は分割後にのみ使う。

## [Suggestion] 施策 2: import の明記

- 判断: **対応する**
- 対応内容: 追加 import（`VideoManualStatus` / `UploadedFile` / `Storage` / `DB` /
  既存の `Category`）を設計に明記する。

## [Warning] 施策 3: T066 テストの名前・コメントが過大

- 判断: **対応する**
- 根拠: 正しい。テスト名は「明示代入の fail-first 契約」と謳うが、実体はファイル粒度で、
  `create()` の明示代入を消しても `duplicate()` が残れば通る。
  **設計本文で限界を正直に書いているのに、テスト名がそれを裏切っている**のは最悪の形
  （読んだ人が保証されていないものを保証されていると誤認する）。
- 対応内容: 施策 3 に「T066 テストの**名称とコメントの是正**」を追加する。
  **assertion は 1 行も変えない**（禁止事項 3 = 既存テストの削除・上書きに当たらないよう、
  検査内容は不変のまま名前とコメントのみ正確にする）。
  新名称案: `VideoManualService ファイルに status/scenario_version の明示 write が
  少なくとも 1 つ存在する (allowlist の degenerate PASS 防止。ファイル粒度でありメソッド単位の
  fail-first ではない)`。

## [Warning] 施策 4: 「直列化点は VideoManual 行 (Project 行はロックしない)」と矛盾する

- 判断: **対応する**
- 根拠: 正しい。docs/architecture.md L220-221 の既存文言をそのままにすると、
  生成経路が Project 行をロックすることと**同じ節の中で矛盾**する。
- 対応内容: 施策 4 の変更対象に当該段落を追加し、「更新経路の直列化点は VideoManual 行 /
  生成経路は所有元 Project 行」と書き分ける。`duplicate()` の cuts は保存後に
  新 manual を `lockForUpdate()` で再取得してから作ることも同段落に明記する。

## [Warning] 施策 5: AGENTS.md 案だと `duplicate()` の cuts まで生成経路に丸められる

- 判断: **対応する**（最重要）
- 根拠: 正しく、かつ**危険度が最も高い**。`duplicate()` は 2 種類の write を持つ:
  (1) 新 manual の status/scenario_version = 生成初期値、
  (2) 新 manual 配下の cuts = **保存後に新 manual を `lockForUpdate()` で再取得してから作成**。
  提案文のまま丸めると (2) の lock 要求が消えて読め、**既存要求を弱める**。
  施策 5 の緩和策として自ら「(i) を一字も弱めない」と書いていた条件に自分で違反していた。
- 対応内容: AGENTS.md 案に次を明記する —
  「**生成経路が `lockForUpdate()` 免除になるのは、その tx が生成した新規行の初期値
  (`status` / `scenario_version`) の INSERT のみである。生成後の行に対する後続の書き込み
  (`cuts` 等) は (i) 更新経路として扱い、保存済みの新 manual を `lockForUpdate()` で
  再取得した同一 tx 内で行う** (準拠実装: `duplicate()` の `copyCuts`)」。
  `docs/architecture.md` / inventory docblock にも同じ文を置く。

## [Warning] 保証しないもの 1 の粒度が雑

- 判断: **対応する**
- 根拠: 正しい。「第 3 の生成経路には沈黙する」は不正確で、**新しいファイル**が
  `status`/`scenario_version` を明示 write すれば deny-by-default で**検出される**。
  検出できないのは (a) 同一 `VideoManualService.php` 内の新メソッド、
  (b) **明示 write を持たず DB default に依存する生成経路**（本件そのものの再発形）の 2 つ。
- 対応内容: 保証しないもの 1 を上記 (a)/(b) の 2 分岐に書き直す。
  とくに (b) は**本件と同じバグの再発を gate が検出できない**という重要な限界なので明記する。

## [Warning] `take_upload_reservations` の断言に根拠が書かれていない

- 判断: **対応する**
- 根拠: 正しい。「呼び出し側は現状 1 つも無い」は設計時に実際に走査して確認した事実だが、
  その根拠が設計書内に無ければ読者は検証できない。
- 対応内容: 根拠（走査コマンドと結果、確認した呼び出し側）を保証しないもの 3 に明記する。

## [Warning] 検証コマンドが backend 3 本だけ

- 判断: **対応する**
- 根拠: 正しい。AGENTS.md の VERIFICATION_COMMANDS は 9 本あり、`pnpm typecheck:packages` /
  `pnpm build:packages` / `pnpm test:packages` を落としていた。
- 対応内容: AGENTS.md の一覧と同期した全 9 本を記載する。

## [Suggestion] `category()->associate()->save()` 二度目の save で属性が消える可能性は低い

- 判断: **見送る**（設計変更不要。ただし施策 2 の対応で**実測により固定**される）
- 根拠: Eloquent の 2 度目の `save()` は dirty 属性のみ UPDATE し、既にセット済みの属性を
  インスタンスから落とさない。理屈では問題ないが、**理屈で済ませず category あり経路の
  テストで固定する**（上記 Warning への対応で自動的に満たされる）。
