# Round 3 — Round 2 の指摘への対応

Warning 2 件と、書式に関する Suggestion 1 件に**すべて対応**しました。
反論・見送りにした Warning はありません。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

Codex 判定: CHANGES_REQUESTED / Critical 0 / Warning 2 / Suggestion 9

## [Warning] lock の許容差分がまだ狭い (対象パッケージの一部フィールドだけを列挙している)
- 判断: **対応する**
- 根拠: 指摘が正しい。v0.2.0 → v0.4.1 では `autoload` / `description` /
  `license` / `authors` / `extra` などのメタデータも**正当に**変わりうる。
  実際に上流の `composer.json` を実読すると `config.sort-packages` /
  `allow-plugins` / `minimum-stability` / `prefer-stable` などが増えており、
  lock のエントリにも波及する。フィールドを列挙する形だと
  「正当な更新を失敗扱いにする」判定になり、実装者が判定条件を
  緩める方向へ手を入れる誘因になる (本末転倒)。
- 対応内容: 許容単位を**パッケージのエントリ全体**へ改めた
  (`composer.json` の版制約行 / 対象パッケージのエントリ全体 /
  新規依存のエントリの追加 / ルートの `content-hash` の 4 つ)。
  そのうえで「エントリ全体を許容しても緩まない」ようにするため、
  中身を 4 点で別途確認する条件を足した —
  `version === "v0.4.1"` / `source.type === "git"` かつ VCS の URL が
  裁定 AG-003 どおりであること / `source.reference` が上流の
  **注釈を剥がした commit** `93ba837c661bf2c31b6801c4c9ad866bdff4445e` と一致
  (`git ls-remote --tags` で `refs/tags/v0.4.1^{}` を実測。
  注釈つきタグの object id `37b80705…` とは別物である点は aigenba:T1203 が
  踏んだ罠でもある) / `require` が上流 v0.4.1 の `composer.json` と一致。
  さらに「既存パッケージの名前 → 版の写像が不変」を機械照合する手順を独立の項に分けた。

## [Warning] 「登録簿の陳腐化を検知できる形にする」は主張が強すぎる
- 判断: **対応する**
- 根拠: 指摘が正しい。`classificationRegistryVersion() === '2025-10-09'` の pin が
  見ているのは「**導入したパッケージの中の**登録簿が変わったか」だけである。
  IANA 側が更新されてもパッケージを更新しなければ gate は緑のままで、
  外部の登録簿と突き合わせる仕組みは 1 つも入っていない。
  「検知できる形にする」と書くと、次に読む人が
  「陳腐化は機械で見ている」と誤解して四半期監査を省く危険がある。
- 対応内容: 2 か所を書き換えた。
  (1) 「保証しないもの」の該当項目を
      「本設計が行うのは使用中の登録簿の版を明示的に固定し、将来のパッケージ更新で
      登録簿が変化したときにレビューを要求することまで。IANA 側の更新や、
      同梱の登録簿が既に陳腐化していることを自動で検知するものではない」へ改め、
      定期の見直しは上流と家系の巡回の責務であると明記した。
  (2) 必須ケース表の「登録簿の版」の理由づけに
      「★これは陳腐化の検知ではない」を明示し、
      **同じ限界を gate 自身の docblock へも書く**ことを設計の要求にした
      (保証範囲の正本を検査の docblock に置くのは本アプリの既存の作法)。

## [Suggestion] 実装方針表の E 行が表から外れている
- 判断: **対応する**
- 根拠: Round 1 の改訂で「D の置き場所と意図の明記」節を D 行の直後へ挿入したため、
  E 行が表本体から切り離されていた。書式の壊れであり内容の問題ではない。
- 対応内容: E 行を表本体へ戻し、詳細節 (B / C / D) は表の後ろへまとめた。

## [Suggestion] 使命との整合性 / 禁止事項 / 3 段手順 / リスクの書き方 / ケース 18 件の内訳 / 触らない判断 / 型安全性
- 判断: **見送る** (いずれも肯定の評であり対応不要)
- 根拠: Round 1 の対応が十分であることの確認。とくに
  「正例と混在応答は、常時 deny や先頭要素だけを見る壊れ方を防ぐため必要」
  という評は本設計の意図と一致している。


---

## 改訂後の概念設計 (全文)

# 概念設計: ssrf-pin-v04-upgrade

家系の機能台帳 lctl の feature `ssrf-pin-boundary` (canonical_version t0) の
`projects.aicue.target_version` —
**「kent013/laravel-ssrf-pin を完全区間分類の版 (^0.4) へ改版し回帰テストで受ける (手本 spirux@a41aabbd)」**
への安全追従。裁定 (settle) 待ちではなく、キュレーターが家系全体へ割り当てた
**安全上の追従**である (AG-003b の settle 論点とは独立)。

## 背景・課題

### いま入っている版が持つ穴 (実測で確定)

aicue の `composer.json` は `kent013/laravel-ssrf-pin: "^0.2"`、
`composer.lock` の解決版は `v0.2.0` (reference `eeff6189…`) である。

v0.2 の `UrlSafetyInspector::classifyIp()` は**列挙型の拒否**である
(`DENY_CIDRS_V4` 12 件 / `DENY_CIDRS_V6` 8 件を順に当て、
どれにも当たらなければ「拒否規則に該当しない = 許可」)。この形は
**IANA Special-Purpose Address Registry の特殊用途アドレス 8 区間**を列挙に持たないため、
そこへ解決される host が素通りする:

| 区間 | 用途 |
|---|---|
| `192.0.2.0/24` | TEST-NET-1 (ドキュメント用) |
| `198.51.100.0/24` | TEST-NET-2 |
| `203.0.113.0/24` | TEST-NET-3 |
| `192.88.99.0/24` | 6to4 relay anycast (廃止済み) |
| `2001:db8::/32` | IPv6 ドキュメント用 |
| `2002::/16` | 6to4 |
| `3fff::/20` | IPv6 ドキュメント用 (新) |
| `5f00::/16` | SRv6 SID |

**本リポジトリの現物で実測した** (`vendor/kent013/laravel-ssrf-pin` v0.2.0 を直接叩き、
aicue の pin 値 — schemes `http|https` / ports `80,443` /
`additional_deny_cidrs` 空 / `deny_ip_literals: true` — を与えた):

```
v0.2.0  DNS 応答 203.0.113.10  → allowed=true
v0.2.0  DNS 応答 192.0.2.1     → allowed=true
v0.2.0  DNS 応答 93.184.216.34 → allowed=true   (これは正しい)
```

### 穴が実際に届く経路が aicue には既にある

台帳の aicue セルは古い観測 (`aicue@a5553b5`) では「package を使う app 側の経路は 0 件」と
書いているが、**その後 aicue:T229 で経路ができた** (台帳も差分巡回 2026-08-19 で追記済み):

`app/Services/Mail/Sns/SnsCertificateFetcher.php::inspect()` が
`UrlSafetyInspector::inspect()` を掛けている。これは**無認証の SNS 受け口が誘発する外部取得**で、
攻撃者が提示した URL を検証のために取りに行く経路である。
取得先の host は値オブジェクト `SnsCertificateUrl` が `sns.<region>.amazonaws.com` の
厳格な書式に固定しているので、悪用には DNS を握る (split-horizon / rebinding) 必要があるが、
**判定層の穴は「その host が 203.0.113.x へ解決されたら通す」という形で現に開いている**。
`SnsCertificateFetcher` の docblock 自身が「DNS rebinding は解消しない」と明記しており、
つまり**判定層の網の細かさがそのまま防御の実体**である。

### 上流 v0.4.1 が何を変えたか (上流を clone して実読)

`kent013/laravel-ssrf-pin` を実際に clone し、`v0.2.0..v0.4.1` の差分を読んだ。

- **v0.3** (`46f16c1`): 要求 / 応答の body 対応・`followRedirects`・大きさ制限つき読み出し。
  **判定層 (`src/UrlSafetyInspector.php`) は無変更**。config へ `max_body_bytes` が 1 件増えた。
- **v0.4** (`03fd3b9`) / **v0.4.1** (`93ba837`): 判定層の反転。
  - `resources/ip-classification.json` (registry_version `2025-10-09` / **IPv4 28 区間 / IPv6 22 区間**)
    を単一ソースに、`src/Ip/IpClassificationTable.php` がアドレス空間を**完全に分割**する。
  - load 時に「隙間なし / 重複なし / `globally_reachable` 欠落なし /
    false の区間に `deny_reason` があること」を検査し、崩れていたら例外
    (**表が壊れたまま静かに fail-open しない**)。
  - `Reachability` enum を新設。**`PublicUnicast` だけが許可**で、
    `NotGloballyReachable` と `Unclassified` はどちらも拒否へ倒す。
    「一致しなければ Public」をやめた。
  - 判定経路から IP の文字列比較を排除し `inet_pton` のバイナリ二分探索だけにした。
  - `SsrfDenyReason` に `NotGloballyReachable` を**追加のみ**。
  - `UrlSafetyInspector::classificationRegistryVersion()` を公開 (判定に使った登録簿の版が読める)。

**後方互換**: 上流の `tests/Unit/BackwardCompatibilityTest.php` が
「新規フィールド・新規引数はすべて既定値つきで末尾に追加」を pin している。
`UrlSafetyDecision` は無変更、`UrlSafetyInspector::__construct` は第 6 引数
(`?IpClassificationTable`、既定 null) が末尾に増えただけ。
`SsrfDenyReason` は case の**追加のみ**で削除・改名なし。
**aicue の呼び出し側 (`SnsCertificateFetcher`) は無改修で通る形**である。

**依存**: `composer.json` に `guzzlehttp/psr7: ^2.4` と `psr/http-message: ^1.1 || ^2.0` が
増えている。aicue の `composer.lock` は既に `guzzlehttp/psr7 2.13.0` /
`psr/http-message 2.0` を持つので**新規取得は発生しない**見込みである。

### aicue の現状で v0.4.1 に上げると何が起きるか (実測)

上流 v0.4.1 を clone して aicue の pin 値を与え、DNS 応答を差し替えて測った:

```
v0.4.1  DNS 応答 203.0.113.10 → allowed=false / not_globally_reachable
v0.4.1  DNS 応答 192.0.2.1    → allowed=false / not_globally_reachable
v0.4.1  DNS 応答 198.51.100.7 → allowed=false / not_globally_reachable
v0.4.1  DNS 応答 192.88.99.1  → allowed=false / not_globally_reachable
v0.4.1  DNS 応答 2001:db8::1 / 2002::1 / 3fff::1 / 5f00::1 → すべて false / not_globally_reachable
v0.4.1  DNS 応答 10.0.0.5     → allowed=false / private_range   (従来どおり)
v0.4.1  DNS 応答 93.184.216.34 → allowed=true                    (従来どおり)
v0.4.1  DNS 応答 2606:2800:220:1:248:1893:25c8:1946 → allowed=true
v0.4.1  classificationRegistryVersion() = "2025-10-09"
```

**★ここが本設計で最も重要な発見である。**
aicue の既存テストは SNS 証明書 host の DNS 応答を **`203.0.113.10` (TEST-NET-3)** に
固定している。これは塞がる 8 区間のうちの 1 つなので、版を上げると
**既存テストが「意図せず」赤くなる**。該当は 3 か所:

| ファイル | 行 | 内容 |
|---|---|---|
| `tests/Feature/Mail/SnsCertificateFetcherTest.php` | 38 | `beforeEach` の `bindSnsDnsResolver(['203.0.113.10'])` |
| `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` | 16 | 同 |
| `tests/Feature/Mail/SesSignatureMiddlewareTest.php` | 25 | 同 |

とくに `SnsCertificateFetcherTest` の
「F0 (正のコントロール): 正常系 fixture は SSRF 検査を通る」は
**「境界が変わったらここが最初に赤くなる」ことを目的に置かれた検査**であり、
設計どおりに機能している。**fixture を「本当に公開到達可能なアドレス」へ差し替える**のが
正しい直し方であって、検査を緩めるのではない。

なお repo 内の他の `203.0.113.x` / `192.0.2.x` / `2001:db8::` の出現
(rate limiter のキー・passkey の origin・trusted proxy の CIDR 表記・監査ログの ip_address)
は `UrlSafetyInspector` を通らないので**影響しない**ことを全数確認した。

### `deny_ip_literals: true` が回帰テストの書き方を決める

aicue の pin 値は `deny_ip_literals => true` である。`inspect()` は
**IP literal を分類より前に short-circuit する**ので:

```
v0.4.1  URL http://192.0.2.1/ (deny_ip_literals=true) → false / ip_literal_not_allowed
```

つまり **spirux の手本 (`http://192.0.2.1/` のような IP literal URL を並べる形) を
aicue にそのまま写すと、8 区間を 1 つも検査しないまま緑になる** (`ip_literal_not_allowed` で
落ちるだけ)。aicue の回帰テストは**必ず「host → DNS 応答」経由**で書かなければならない。
これは偽グリーンの罠であり、設計に明記して実装へ渡す。

## 改善アイデア

「共有パッケージの版を上げ、塞がった区間を回帰テストで受け、既存 fixture の
前提崩れを直す」— これだけを行う。判定規則を aicue 側で再実装しない
(判定の正本は共有パッケージにある)。

1. **版制約を上げる** — `composer.json` の `^0.2` → `^0.4`。
2. **lock を再解決する** — 当該パッケージだけを更新し、
   **許容差分を先に決めてから**機械照合で確認する
   (下の「B の許容差分」)。
3. **塞がった 8 区間を回帰テストで固定する** — 新規 Architecture テスト 1 本。
   `app(UrlSafetyInspector::class)` を通し、**DNS 応答経由**で 8 区間が
   `NotGloballyReachable` で拒否されることを pin する。
   併せて (a) 従来から拒否していた古典区分が**緩んでいない**こと、
   (b) 公開到達可能なアドレスは通ること (正のコントロール)、
   (c) 混在応答 (public + 特殊用途) が拒否されること、
   (d) **判定に使われた登録簿の版が `2025-10-09` であること**を固定する。
4. **既存 fixture の前提崩れを直す** — TEST-NET-3 を使っている 3 か所を
   公開到達可能なアドレスへ移し、その値の出所を 1 か所にする。
5. **AGENTS.md の不変条件 8 を実態へ揃える** — 拒否の形が
   「列挙型 deny」から「完全区間分類 + 既定拒否」へ変わり、
   安全境界の一部が**同梱の登録簿の内容**になった。新設 gate の名前と、
   **登録簿の陳腐化**の性質を 1 段落で足す。
   ★書き方に注意する: 「登録簿が古いと fail-open する」は**誤り**である。
   v0.4 は未分類を拒否するので「表に無い = 許可」という v0.2 型の fail-open は消えている。
   起きうるのは「登録簿の到達可能性の判断が最新の IANA の状態を反映しない」= **陳腐化**であり、
   IANA が後から非到達へ移した区間を公開到達可能として持ち続ける (逆向きもある) 形である。
   AGENTS.md にはこの表現で書く。

### なぜ「回帰テスト」が追従の本体なのか

版を上げるだけなら composer の 2 ファイルで済む。だが本 feature の boundary は
「`config/ssrf-pin.php` + `SsrfPinBoundaryTest` + パッケージ配布形」であり、
**「導入した版が実際に何を備えているか」は composer 制約とは独立に固定されなければ、
次に誰かが `composer update` の巻き添えで版を戻したときに黙って穴が開く**。
target_version が「改版し**回帰テストで受ける**」と書いているのはこの意味である。

## 期待効果

- **使命への貢献**: 直接の貢献は「SOP → シナリオ → ナビ撮影」の機能面ではなく、
  その土台の**安全**である。aicue は SES/SNS 経由のメール受信という
  **無認証の入口**を持ち、そこが外部 URL 取得を誘発する。ここで内部宛て・
  非公開到達アドレスへの誘導が通ると、現場の SOP と撮影データを預かる基盤の
  信頼が崩れる。使命の前提条件を守る施策である。
- **具体的な改善見込み**:
  - 素通りしていた IANA 特殊用途 8 区間 (IPv4 4 / IPv6 4) が拒否される。
  - 「拒否規則に当たらない = 許可」という **fail-open の既定が消える**
    (未分類・登録簿破損は拒否 / load 時例外)。
  - 判定経路から IP の文字列比較が消え、表記揺れ由来の取りこぼしが減る。
  - 家系の版の割れ (spirux `^0.4` / template・aigenba・metamovics `^0.3` /
    aicue・motivation `^0.2`) のうち aicue 分が解消し、**家系で最も進んだ版に並ぶ**。

### 保証しないもの (誇張しない)

- **「任意 URL の SSRF が防げるようになる」わけではない**。aicue で
  `UrlSafetyInspector` を通る経路は SNS 証明書取得の 1 本だけで、
  その host は値オブジェクト `SnsCertificateUrl` が
  `sns.<region>.amazonaws.com` に固定している。悪用には**その名前の解決を
  攻撃者が支配している**必要がある (split-horizon DNS / 権威 DNS の侵害)。
  本追従が細くするのは**その条件が成立したときに通る宛先の集合**である。
- **DNS rebinding (TOCTOU) は解消しない**。判定時と接続時で名前解決が変わる形は
  残り、非公開アドレスへの TCP 接続と TLS ClientHello そのものは発生しうる
  (`SnsCertificateFetcher` の docblock が既に明記している。
  一本化は `PinnedHttpClient` の管轄でスコープ外)。
- **登録簿の陳腐化は本追従では解消しないし、検知もしない**。v0.4 は未分類を拒否するので
  「表に無い = 許可」は消えているが、IANA が後から分類を変えた区間に対しては
  同梱の登録簿 (`2025-10-09`) の判断が古くなりうる。
  本設計が行うのは**使用中の登録簿の版を明示的に固定し、将来のパッケージ更新で
  登録簿が変化したときにレビューを要求する**ことまでである。
  IANA 登録簿側の更新や、同梱の登録簿が既に陳腐化していることを
  **自動で検知するものではない** (外部の登録簿と突き合わせない。
  パッケージを更新しなければ gate は緑のままである)。
  四半期監査のような定期の見直しは上流 (`kent013/laravel-ssrf-pin`) と
  家系の巡回の責務であり、本設計では扱わない。

## 実装方針（概要）

| # | 変更 | ファイル |
|---|---|---|
| A | 版制約 `^0.2` → `^0.4` | `composer.json` |
| B | 当該 1 パッケージのみ再解決 | `composer.lock` |
| C | 塞がった区間の回帰 gate を新設 | `tests/Architecture/SsrfPinSpecialPurposeRangeRegressionTest.php` (新規) |
| D | TEST-NET-3 fixture を公開到達可能なアドレスへ移す (出所は 1 か所) | `tests/Pest.php` / `tests/Feature/Mail/SnsCertificateFetcherTest.php` / `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` / `tests/Feature/Mail/SesSignatureMiddlewareTest.php` |
| E | 不変条件 8 の記述を実態へ揃える | `AGENTS.md` |

### D の置き場所と意図の明記

- 値の出所は **`tests/Pest.php`** に置く (`bindSnsDnsResolver()` の隣)。
  `tests/Support/SnsTestData.php` は自然な置き場に見えるが、
  **指紋台帳 + 採用時債務パス**なので触ると債務の整理が連鎖する。
  `tests/Pest.php` は母集合外で、既に `bindSnsDnsResolver()` を持つ同じ関心事の置き場である。
- 出所には次の意図を docblock として残す:
  > この値は**分類表が globally reachable と判定する DNS 応答値**である。
  > 実在ホストの検証でも実到達性の検証でもない
  > (全レーンで `StrayHttpRequestGuard` が外向き HTTP を既定拒否している)。
  > **ここから「本当に到達するか」を確かめる外向き通信を足さない。**
  > TEST-NET-3 (`203.0.113.0/24`) を使っていたが、v0.4 の完全区間分類で
  > `NotGloballyReachable` へ移ったため使えない。


### B の許容差分 (lock 再解決の合格条件)

「動いたのは 1 件だけ」を無条件の合格条件にはしない。v0.4.1 は
`guzzlehttp/psr7: ^2.4` と `psr/http-message: ^1.1 || ^2.0` を**新たに require する**ので、
lock にそれが無ければ増えるのが正しい。手順は次の順で行う:

1. **更新前**に、v0.4.1 の `require` を aicue の `composer.lock` の現物と突き合わせ、
   新規に取る必要がある依存を洗い出す。
   (現時点の実測では `guzzlehttp/psr7 2.13.0` / `psr/http-message 2.0` が
   既に入っており制約を満たすので、**新規取得は 0 件になる見込み**である。
   ただしこれは合格条件ではなく事前の見積である。)
2. `composer update kent013/laravel-ssrf-pin` を**当該パッケージ名を指定して**実行する。
   **`--with-all-dependencies` は使わない** (依存全体の巻き添え解決を避ける)。
3. **許容差分** = 次の 4 つだけ。**単位は「パッケージのエントリ全体」である**
   (`autoload` / `description` / `license` / `authors` / `extra` などの
   メタデータも版更新に伴って正当に変わりうるので、フィールドを列挙して
   絞る形にはしない)。
   - `composer.json`: 対象パッケージの版制約の行
   - `composer.lock`: `kent013/laravel-ssrf-pin` の**パッケージエントリ全体**
   - `composer.lock`: 手順 1 で「新規に取る必要がある」と洗い出した依存の
     **パッケージエントリの追加**
   - `composer.lock` ルートの `content-hash`
4. 対象パッケージのエントリ全体を許容しても緩まないように、
   **エントリの中身を別途 4 点で確認する**:
   `version === "v0.4.1"` / `source.type === "git"` かつ VCS の URL が
   `https://github.com/kent013/laravel-ssrf-pin.git` /
   `source.reference` が上流の**注釈を剥がした commit** `93ba837…` と一致 /
   `require` が上流 v0.4.1 の `composer.json` と一致。
5. これ以外の**既存**パッケージについて「名前 → 版の写像が不変」であることを機械照合する。
   1 件でも動いていたら**やり直す**
   (版上げ前後の lock を JSON 化し、`名前 → 版` の写像を機械照合して確認する。
   aigenba:T1203 が同じ照合を行っており、その作法を踏襲する)。

### C の書き方 (核心)

- `bindSnsDnsResolver()` (`tests/Pest.php`) と同じ作法で
  **`DnsResolverInterface` を差し替える**。`UrlSafetyInspector` 自身は
  `ExternalFakeDeclaration::neverSwapped()` で偽物にできないので、
  差し替えるのは**その依存**である (既存の作法をそのまま使う)。
- **手順は 3 段で、順序が本質である**:
  1. `app()->bind(DnsResolverInterface::class, fn () => new FakeDnsResolver([...], [...]))`
  2. `app()->forgetInstance(UrlSafetyInspector::class)`
  3. `app(UrlSafetyInspector::class)->inspect(...)`

  **2 を飛ばすと偽グリーンになる。** `SsrfPinServiceProvider::register()` は
  `UrlSafetyInspector` を `singleton()` で登録しているので、
  DNS resolver を後から bind しても**既に解決済みの instance は作り直されない** =
  前のケースの resolver で判定してしまう。`bindSnsDnsResolver()` が
  `forgetInstance` を呼んでいるのはこの理由である。
- 検査対象は `app(UrlSafetyInspector::class)` — **アプリの config pin 値 →
  provider の結線 → v0.4 の分類表**を通した実物である
  (テスト内で inspector を直接 `new` すると config pin の結線を検査しない)。
- **IP literal URL を使わない** (`deny_ip_literals: true` で short-circuit する = 偽グリーン)。
- DB 不要なので `tests/Architecture/` に置く (`SsrfPinBoundaryTest` と同じレーン)。

#### C の必須ケース表

本 gate は aicue が第二層 (package 契約検査) を持たない以上、
**「入った版が実際に何を備えているか」を見る唯一の検査**になる。
したがってケースの母集合が差分に現れる形で持つ — データプロバイダで畳んでも
**区間名がケース名として読め、期待 deny 理由が個別に書かれている**こと。
1 件そっと削る変更がレビューで見えなければならない。

| 群 | ケース | DNS 応答 | 期待 |
|---|---|---|---|
| 塞がった 8 区間 | TEST-NET-1 | `192.0.2.1` | deny / `NotGloballyReachable` |
| | TEST-NET-2 | `198.51.100.7` | deny / `NotGloballyReachable` |
| | TEST-NET-3 | `203.0.113.5` | deny / `NotGloballyReachable` |
| | 6to4 relay anycast | `192.88.99.1` | deny / `NotGloballyReachable` |
| | IPv6 ドキュメント用 | `2001:db8::1` | deny / `NotGloballyReachable` |
| | IPv6 6to4 | `2002::1` | deny / `NotGloballyReachable` |
| | IPv6 ドキュメント用 (新) | `3fff::1` | deny / `NotGloballyReachable` |
| | SRv6 SID | `5f00::1` | deny / `NotGloballyReachable` |
| 緩んでいないこと | loopback | `127.0.0.1` | deny / `Loopback` |
| | private 10/8 | `10.0.0.5` | deny / `PrivateRange` |
| | link-local (IMDS) | `169.254.169.254` | deny / `LinkLocal` |
| | CGNAT | `100.64.0.1` | deny / `PrivateRange` |
| | IPv6 ULA | `fc00::1` | deny / `PrivateRange` |
| | IPv6 link-local | `fe80::1` | deny / `LinkLocal` |
| 正のコントロール | 公開到達可能 v4 | `93.184.216.34` | **allow** |
| | 公開到達可能 v6 | `2606:2800:220:1:248:1893:25c8:1946` | **allow** |
| 混在応答 | public + TEST-NET-1 | `93.184.216.34` + `192.0.2.1` | deny / `NotGloballyReachable` |
| 登録簿の版 | 判定に使われた登録簿 | — | `classificationRegistryVersion() === '2025-10-09'` |

- **正のコントロールが要る理由**: 全ケースが deny だと
  「何かの理由で常に deny になる壊れ方」(例: config の取り違え) で緑になる。
- **混在応答が要る理由**: `inspect()` は A + AAAA の**全件**を分類して
  1 件でも非公開なら拒否する。この「全件検査」が緩むと、
  攻撃者は公開 IP を 1 つ混ぜるだけで通せる。
- **登録簿の版を pin する理由**: 安全境界の一部が**登録簿の内容**になった。
  上流が登録簿を更新すればここが赤くなる。**これは意図である** —
  更新時に登録簿の差分と上の全回帰ケースを見直すための入口として置く
  (`config/ssrf-pin.php` へ `registry_version` を足す代わりの手当。
  同ファイルは採用時債務パスなので触らない)。
  ★**これは陳腐化の検知ではない**。見ているのは「導入した版の中の登録簿が変わったか」
  だけで、IANA 側の更新は 1 度も参照しない
  (パッケージを更新しなければ緑のままである)。gate の docblock にもこの限界を書く。

### 触らないもの (意図的)

- **`config/ssrf-pin.php` は 1 文字も変えない**。pin 値 5 つ
  (`allowed_schemes` / `allowed_ports` / `max_redirect_hops` /
  `additional_deny_cidrs` / `deny_ip_literals`) を維持する。
  v0.4.1 の package 側 config が持つ `max_body_bytes` は
  `mergeConfigFrom` で package 既定 (1 MiB) が入る。
  aicue は `PinnedHttpClient` を 1 か所も使っていないので、
  この値は aicue の判定にも取得にも影響しない。
- **`tests/Architecture/SsrfPinBoundaryTest.php` も変えない**。
  pin 値の固定と「境界で拒否できる」ことの検査は v0.4.1 でもそのまま通る
  (IP literal 拒否 / スキーム / ポートはいずれも分類層より前で決まる)。
  回帰は**別ファイル**で足す。

## 制約・前提

- **判定規則を aicue 側で再実装しない**。判定の正本は共有パッケージ
  `kent013/laravel-ssrf-pin` にあり、aicue は「版で追随 + 回帰テストで受ける」形をとる
  (spirux の手本と同じ)。
- **配布経路は AG-003 のとおり**: `composer.json` の `repositories` は
  VCS 参照 (`https://github.com/kent013/laravel-ssrf-pin.git`)、版は明示指定。
  この形は変えない (版番号だけを上げる)。
- **不変条件 (正典由来。詳細設計で全数を列挙する)**:
  1. 判定は `UrlSafetyInspector` に一元化されたまま (aicue 側に deny 規則の実装を作らない)。
  2. `config/ssrf-pin.php` の pin 値 5 つを維持。
  3. 境界 gate が緩まない (既存の `SsrfPinBoundaryTest` を弱めない・削らない)。
  4. 外部 URL 取得は SSRF 検査経由 (AGENTS.md セキュリティ不変条件 8)。
  5. 配布経路は VCS 参照 + 版指定 (AG-003)。
- **乖離台帳との関係** (Phase 3-0 で正式に確認する。ここでは前提として押さえる):
  - `composer.json` / `composer.lock` / `AGENTS.md` / `tests/Pest.php` は
    `docs/template-fingerprints.json` の `entries` に**無い** = 突合の母集合外。
  - `config/ssrf-pin.php` と `tests/Architecture/SsrfPinBoundaryTest.php` と
    `tests/Support/SnsTestData.php` は**指紋台帳にあり、かつ採用時債務一覧にもある**。
    債務パスは採用時ハッシュとの一致まで見られるので、**変更すると
    「変更したまま債務に残す」が選べなくなる**。3 件はいずれも**触らない**設計にする
    (`SnsTestData.php` に定数を足したくなるが、これがまさに債務パスなので避ける)。
- PHPStan level 10 / Pest / `RefreshDatabase` はグローバル適用 (Architecture レーンは DB なし) /
  テストデータは Factory / DTO + JsonResource。
- 新規テストファイルの作法上の制約 (既存 gate 由来):
  - `declare(strict_types=1);` 必須 (`StrictTypesDeclarationGateTest`)。
  - グローバル名前空間で**非複合名の `use` を書かない**
    (`NoNonCompoundGlobalUseTest`。`use ReflectionMethod;` の類は違反になる)。
  - キャッシュに触らない (触ると `CachePayloadPlainDataGateTest` の面目録への登録が要る)。
  - 外向き HTTP を出さない (`StrayHttpRequestGuard` は Architecture レーンでも既定 ON)。
- PHPStan level 10 との整合:
  - fake DNS resolver は**自作しない**。package 同梱の
    `Kent013\SsrfPin\Testing\FakeDnsResolver` (`DnsResolverInterface` 実装) を使う
    (`tests/Pest.php` が既にこの形で使っている)。
  - ケース値は `FakeDnsResolver` の受ける形 (`array<string, list<string>>`) を守る。
    `mixed` の配列や無名クラスで型検査を回避しない。
  - 期待する拒否理由は `Kent013\SsrfPin\Enums\SsrfDenyReason` の case を
    そのまま渡す (文字列比較にしない)。

## スコープ外

| 項目 | 理由 |
|---|---|
| 第二層 (`SsrfPinPackageContractTest` — 導入した版が実際に何を備えるかの契約検査) の新設 | 正典が「**第二層は t0 の必須要素ではない**」と明示している (台帳の 2026-08-18 夕 第 2 ラウンド)。保有は laravel-claude-template と aigenba の 2 本の先行分。本追従の target_version にも含まれない。過大化させない |
| `config/ssrf-pin.php` への `registry_version` の pin (spirux はやっている) | pin 値 5 つ維持の不変条件に反し、かつ同ファイルは**採用時債務パス**なので触ると債務の整理が連鎖する。登録簿の版は `classificationRegistryVersion()` で読めるので、**新設 gate の中で pin する**ことで同じ目的 (陳腐化の検知) を達成する |
| `config/ssrf-pin.php` への `max_body_bytes` の明示 | aicue は `PinnedHttpClient` を 1 か所も使っていない。`mergeConfigFrom` で package 既定 1 MiB が入るので実効挙動に差が無く、上と同じ債務パスの問題がある |
| `PinnedHttpClient` への取得の一本化 | 本 feature の boundary が「呼び出し側は各機能側」と切っている。加えて `PinnedResponse` が本文を返せる形になったのは v0.3 以降で、`SnsCertificateFetcher` を書き換える判断は `mail-ses-suppression` 側の管轄 (aicue:T229 の裁定 AG-199 で「inspect → fetch」を採る判断が既に済んでいる) |
| `docs/ses-mail-runbook.md` の 403 切り分け表の文言更新 (「private IP へ解決されていないか」→ 拒否区分の広がり) | 同ファイルは**指紋台帳 + 採用時債務パス**である。現行の記述は誤りではなく (private IP は依然その一例) 網羅的でないだけなので、債務の整理を伴う変更に見合わない。再判定の条件: 同ファイルが別の理由で債務から外れたとき |
| `docs/architecture.md` の SNS 節 | 「DNS 解決失敗のみ 503・他は 403」という記述は v0.4.1 でもそのまま正しい (`NotGloballyReachable` は 403 側に落ちる)。更新すべき事実が無い |
| 家系全体の版の扱い / 正典の版を 1 つ上げるかの判断 (AG-003b の settle 論点) | aicue 1 リポジトリ分の安全追従であり、settle の代行はしない。他リポジトリの追従も本設計の対象外 |
| aigenba の gate 名の割れ (`SsrfPinBoundaryTest` 名の統一) | aicue には既に正典と同名の gate がある。他リポジトリの話 |
| TypeScript 側の URL 安全性判定 | 本 feature の boundary が明示的に除外 (`capture-core-package` の管轄)。aicue に該当実装は無い |


---

残る懸念があれば挙げてください。無ければ全体判定 APPROVED をお願いします。
