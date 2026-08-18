# Round 2: 概念設計レビュー (Round 1 指摘への対応)

Round 1 の指摘に対する Claude 側の判断と、修正後の概念設計全文を送ります。
反論している箇所は根拠を添えています。反論が妥当かを含めて再判定してください。

# 対応マトリクス: conceptual-review Round 1

## [Critical] SSRF 検査と実接続の結合 (DNS rebinding)

- 判断: 一部対応する / 一部反論する
- 根拠:
  - `Kent013\SsrfPin\PinnedHttpClient::fetch()` の成功結果 `PinnedResponse` は
    `status` / `headers` / `finalUrl` / `hopUrls` しか持たず、**本文を返さない**
    (aicue の vendor 実物 `vendor/kent013/laravel-ssrf-pin/src/Dtos/PinnedResponse.php` を実読)。
    証明書取得は本文が要るので、`PinnedHttpClient` では実装できない。
  - 家系の裁定 AG-199 も「inspect → fetch でよく、取得本体の一本化は
    `ssrf-pin-boundary` の管轄」と明記している。
  - 残る TOCTOU (検査時と接続時で DNS 応答が変わる) の耐性は
    **https + TLS のホスト名検証 + redirect 禁止**が担う。取得先の host は
    `SnsCertificateUrl` により `sns.<region>.amazonaws.com` に固定されているため、
    内部 IP へ振り替えても攻撃者は amazonaws.com の証明書を提示できず TLS で落ちる
    (このとき `ConnectionException` = 通信系 → 503)。
- 対応内容: 「rebinding 耐性をどこが担保するか」を概念設計へ明記した
  (SSRF 判定の節を新設)。`PinnedHttpClient` を使わない理由も併記した。

## [Warning] 「許可ホストだが private IP」「inspect 後に解決先が変わる」のテスト

- 判断: 前者は対応する / 後者は反論する
- 根拠: 前者は `FakeDnsResolver` で表現できる (motivation の準拠テストと同型)。
  後者はテストレーンから TLS ハンドシェイクの失敗を作れないので、
  `Http::fake()` が `ConnectionException` を返す形で「通信系失敗 → 503」を固定するのが上限である。
- 対応内容: テスト計画に「private IP 解決 → 403」「DNS 解決失敗 → 503」を明記。
  rebinding そのものは保証範囲外として書いた (誇張しない)。

## [Critical] ロック保持期間とキャッシュ昇格の責務

- 判断: 反論する (構造は変えない) + 明文化して対応する
- 根拠:
  - ロックの目的は**外向き通信の同時本数に上界を作ること**であり、ロックは
    外向き通信をちょうど包んでいる。署名検証は純粋な CPU 処理 (µs 級) で
    外向き通信を含まないため、ここまでロックを伸ばしても上界は 1 ミリも改善しない。
  - 逆に伸ばすと、裁定 AG-199 が固定を要求している時間の大小関係
    「待ち上限 < HTTP 予算 + 後処理余裕 <= lock の寿命」の左辺に署名検証時間が入り、
    lock 寿命を伸ばす必要が出る = **障害時に後続が 503 になる時間が延びる**。
  - 守るべき不変条件「未検証の PEM をキャッシュへ載せない」は、昇格の条件が
    「`validate()` が例外を投げずに返ったこと」ただ 1 つであることで完全に保たれる。
    ロックの有無は昇格条件に関与しない。
  - 参照実装 spirux も同じ形 (解放は取得 callable の中、署名検証はその後) で、
    裁定は spirux のこの形を (6b)(7) の出所として採用している。
  - Codex 案の `fetchAndVerify(callable $verify)` は取得口へ署名検証を呼び戻す制御反転で、
    取得口が「何のために取るか」を知ることになる。責務が増える割に得るものが無い
    (AGENTS.md 思考原則 2)。
- 対応内容: 「解放から昇格までの窓」を概念設計に明記し、その窓で起こりうること
  (別要求が同じ証明書をもう一度取りに行く) と、それが害にならない理由を書いた。
  併せて Warning 6 の要求どおり**状態遷移表**を概念設計へ追加した。

## [Warning] 応答サイズ上限を受信後に測ることの意味

- 判断: 対応する (主張を狭める)
- 根拠: Laravel の HTTP client は既定で非 stream なので本文は先に全部メモリへ載る。
  `strlen` の位置を変えてもメモリ上限にはならない。自前の逐次読み出しは
  「今必要なものだけ作る」に反する第 2 の機構になる。
- 対応内容: 上限の役割を「**期待と違う応答を検証・キャッシュに固定しない**」に限定して書き、
  メモリ上界ではないと明記した。実メモリは host 固定 + TLS ホスト名検証 + 読み取り上限 +
  redirect 禁止で有界であることを併記した。

## [Warning] 「正常時の外向き通信が消える」は強すぎる

- 判断: 対応する
- 対応内容: 「キャッシュ命中時は外向き通信もロックも発生しない」に書き換えた。

## [Suggestion] 503 後の再送で必ず成功する、は保証ではない

- 判断: 対応する
- 対応内容: 「待たない」を選ぶ根拠を保証ではなく期待値として書き直した。

## [Warning] 単一グローバルロックの可用性リスクの明文化

- 判断: 対応する
- 対応内容: 「攻撃者は形式上正しい別々の証明書 URL を並べることでキャッシュ不命中を作れる」
  「その結果として正当な通知も 503 になりうる」「503 は SNS の再送対象なので抑止漏れには
  ならない」をリスクとして明記した。時間の大小関係と permit=1 の機械固定は元から施策に含む。

## [Warning] PEM 確認と署名検証の役割分離 + 負例テスト

- 判断: 対応する
- 対応内容: 「PEM 確認 = 早期の形式不正の排除」「署名検証 = キャッシュ昇格の唯一の条件」と
  役割を分けて明記し、負例テスト (署名検証が落ちたら 1 バイトもキャッシュされない) を
  テスト計画へ必須として書いた。

## [Suggestion] キャッシュから読み戻した PEM の再検査

- 判断: 対応する
- 根拠: AGENTS.md セキュリティ不変条件 11 が「読み戻しは明示的に検査し、失敗したら
  `forget` する」を要求している。素の文字列でも同じ規律を適用するのが自然で、
  費用は 1 リクエストあたり 1 回の PEM 解析 (外向き通信に比べて無視できる)。
- 対応内容: 読み戻しの検査を「文字列であること + 空でないこと + PEM として読めること」とし、
  失敗したら `forget` して miss 扱いにする方針を追加した。

## [Warning] 責務境界と状態遷移を先に確定する

- 判断: 対応する
- 対応内容: 状態遷移表 (キャッシュ命中 / ロック取得失敗 / SSRF 拒否 / DNS 解決失敗 /
  通信失敗 / サイズ超過 / PEM 不正 / 署名失敗 / 署名成功) を概念設計へ追加した。

## [Warning] 型で未検証 URL を閉め出す

- 判断: 対応する (元設計どおりであることを明記)
- 対応内容: 取得口の公開 API は `SnsCertificateUrl` しか受けず、生成は
  `fromString()` (検証つき) だけであることを設計本文に書いた。

## [Suggestion] 使命との因果をもっと簡潔に

- 判断: 対応する
- 対応内容: 期待効果の 1 行目を書き直した。


---

## 修正後の概念設計 (全文)

# 概念設計: SES/SNS 署名検証の証明書取得経路の強化 (正典 t1 追従)

## 背景・課題

SES のバウンス/苦情通知は SNS 経由で `POST /ses/notification` に届き、
`VerifySnsSignature` middleware → `AwsSnsSignatureVerifier` が署名を検証してから
抑止リストへ反映される。署名の検証には SNS が公開する証明書 (PEM) が必要で、
**無認証のリクエストが外向き通信を誘発する経路**になっている。

aicue の現状は家系の機能台帳 `mail-ses-suppression` の t0 そのままである
(テンプレート `laravel-claude-template@93e91e3` と 14 ファイルがバイト単位で同一)。
台帳の裁定 AG-199 (2026-08-18) は、この feature の正典を **t1** へ引き上げた。
t1 は t0 (403/503 の分離 + 厳格な URL 検証) に証明書取得経路の 8 要件を足したものである。

aicue の t0 実装 (`app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` 113 行) には
次の欠落がある。

1. **両キー同時送信を拒否していない**。`SigningCertURL` を先に読むが、AWS の検証器
   (`aws/aws-php-sns-message-validator` 1.10.0) は `SigningCertUrl` があると
   その値で `SigningCertURL` を**上書きしてから取りに行く**。したがって両方を送られると
   「検査した URL」と「取りに行く URL」が食い違い、アプリ側の追加検証
   (port 443 固定 / query 禁止 / path 形式 / 中国パーティション排除) を回避できる。
   vendor 自身の検証はホスト形式しか見ないので、任意ホストへは飛ばないが
   **同一ホスト上の任意の `.pem`** は取りに行ける。
2. **取得直前の URL 同一性検査が無い**。`certClient` は SDK が渡してきた文字列を
   そのまま取りに行くため、vendor 内部の変換に依存した最後の砦が無い。
3. **SSRF 判定を通していない**。AGENTS.md セキュリティ不変条件 8
   「外部 URL 取得は SSRF 検査経由」に対して、この経路だけが素通りしている
   (基盤 `kent013/laravel-ssrf-pin` は導入済みで `config/ssrf-pin.php` に境界を pin 済み)。
4. **同時取得の抑止が無い**。受け口に `throttle:webhook-ses` (300/分・IP 単位) はあるが、
   回数上限は**同時実行数を縛らない**。証明書がキャッシュされていない状態で通知が集中すると、
   外向き通信が同時に何本も走り worker 占有の上界が作れない。
5. **キャッシュが無い**。正常時も毎回 1 リクエストにつき 1 回の外向き通信が起きる。
6. **例外写像が広すぎる**。`catch (\Throwable)` が `TypeError` などのプログラム不具合まで
   503 に写像するため、実装の壊れが「一時障害」に化けて SNS の再送で永久に繰り返される。
7. **応答の中身を確認していない**。PEM として読めない応答でもそのまま署名検証へ渡す。

いずれも「起きたときに静かに壊れる」性質で、抑止漏れ (= 送信ドメインの評判低下) か
無用な worker 占有につながる。

## 改善アイデア

裁定 AG-199 が定めた t1 の 8 要件を、参照実装 2 本の**合成**として実装する。
どちらか単独では t1 に満たない、と裁定が明記している。

- 構造は motivation (`app/Services/Mail/Sns/SnsCertificateFetcher.php` /
  `SnsCertificateUrl.php`) — 取得口を専用クラス 1 つへ集約し、URL の書式検証を
  **契約ではなく型**で担保する
- そこへ spirux (`app/Services/Mail/Sns/AwsSnsSignatureVerifier.php`) の 3 点を合成する —
  取得直前の URL 同一性検査 / **署名検証が通った証明書だけ**のキャッシュ昇格 /
  通信系に限定した例外写像

t1 の 8 要件と aicue での対応:

| # | 要件 | aicue での対応 |
|---|------|----------------|
| 1 | 両キー同時送信の拒否 (403) | `AwsSnsSignatureVerifier::effectiveCertUrl()` を新設。両方あれば署名不正 |
| 2 | 取得直前の URL 同一性検査 | certClient の閉包に検証済み URL を閉じ込め `hash_equals` で照合 |
| 3 | 厳格 URL 検証の維持 | 既存の判定式を `SnsCertificateUrl` (値オブジェクト) へ移設。credential 拒否を追加 |
| 4 | SSRF 判定 | `UrlSafetyInspector::inspect()` を取得前に掛け、DNS 解決失敗だけ 503・他は 403 |
| 5 | 専用クラスへの集約と直列化 | `SnsCertificateFetcher` へ集約。単一ロックキー (permit 1) で直列化。時間の大小関係を機械固定 |
| 6 | PEM 確認 + 検証済みのみキャッシュ昇格 | `openssl_x509_read` が通り、かつ**署名検証が通ってから** cache へ載せる。キャッシュ障害では止めない |
| 7 | 通信系のみ 503 | `ConnectionException` / `RequestException` だけを写像。それ以外は伝播 |
| 8 | redirect 禁止と短い時間予算 | 既存の `withoutRedirecting()` を維持し、時間予算を config へ出す |

### SSRF 判定の形と、残る危険をどこで押さえるか

`UrlSafetyInspector::inspect()` を取得の前に掛け、拒否理由が「DNS 解決失敗」なら 503、
それ以外 (loopback / private / link-local / 予約 / scheme / port / credential / 不正 host) は 403 にする。
許可された host が private IP に解決される状態は DNS rebinding か split-horizon DNS であり、
再送では直らないので恒久扱いにする。

**取得本体は `PinnedHttpClient` に寄せない**。理由は 2 つ。

- vendor の `PinnedHttpClient::fetch()` が返す `PinnedResponse` は
  `status` / `headers` / `finalUrl` / `hopUrls` だけを持ち、**本文を返さない**
  (`vendor/kent013/laravel-ssrf-pin/src/Dtos/PinnedResponse.php` を実読して確認)。
  証明書取得は本文が要るので、この経路では実装できない
- 家系の裁定 AG-199 も「inspect → fetch でよく、取得本体の一本化は
  `ssrf-pin-boundary` の管轄」と明記している

**残る TOCTOU (検査時と接続時で DNS 応答が変わる) を押さえるのは
https + TLS のホスト名検証 + redirect 禁止の 3 点**である。取得先の host は
`SnsCertificateUrl` により `sns.<region>.amazonaws.com` に固定されているので、
名前解決を内部 IP へ振り替えても攻撃者は amazonaws.com の証明書を提示できず、
TLS ハンドシェイクで落ちる (通信系の失敗 = 503)。
**この耐性はテストレーンでは再現できない**ため、保証しないものとして設計に明記する。

### 待つか待たないか (値の選択)

裁定は「待ち上限 0 (待たない) も適合」とし、不変条件は**時間の大小関係の機械固定**の側にあるとした。
aicue は **待たない (非ブロッキング)** を採る。理由は 2 つ。

- 待つ実装は「待ち時間ぶん worker を占有する」ため、この施策の目的
  (無認証経路が誘発する外向き通信の worker 占有に上界を作る) と正面から競合する
- 取得できなかった要求は 503 を返し、SNS が再送する。**多くの場合**再送時には
  winner がキャッシュを埋め終えているので即成功する
  (winner 自身が通信に失敗した場合やキャッシュ障害では成立しないので、これは保証ではなく
  「待たない」を選ぶ根拠としての期待値である)

### ロックキーを 1 本にする (URL ごとに分けない)

厳格 URL 検証を通る URL は `https://sns.<region>.amazonaws.com/SimpleNotificationService-<英数>.pem`
の形であり、末尾の英数部分は攻撃者が自由に変えられる。URL ごとにロックキーを分けると
**存在しない証明書名を並べるだけで同時取得数を増やせる**ので、ロックキーは 1 本にして
「同時取得数 = 1」を構造的に保つ。キャッシュキーだけを URL の sha256 で分ける。

**引き換えに引き受ける可用性リスク**: 攻撃者は形式上正しい別々の証明書 URL を並べることで
キャッシュ不命中を作れるため、正当な通知の証明書取得まで 503 になりうる。
これは「同時外向き通信数を 1 にする」ことの対価として意図的に引き受ける。
503 は SNS の再送対象なので**抑止漏れ (恒久ドロップ) にはならない**ことが引き受けられる理由である。
受け口には `throttle:webhook-ses` (300/分・IP 単位) が既にあり、単一 IP からの物量は
そこで頭打ちになる。

### ロックの保持範囲と、解放から昇格までの窓

ロックが包むのは**外向き通信ちょうど**である。署名検証はそのあと (ロック解放後) に走り、
検証が通ってはじめてキャッシュへ昇格する。

- 「未検証の PEM をキャッシュへ載せない」は、昇格条件が
  「vendor の `validate()` が例外を投げずに返ったこと」ただ 1 つであることで保たれる。
  ロックの有無は昇格条件に関与しない
- 署名検証は純粋な CPU 処理で外向き通信を含まないため、ここまでロックを伸ばしても
  同時外向き通信数の上界は改善しない。逆に伸ばすと、裁定が固定を求める時間の大小関係
  「待ち上限 < HTTP 予算 + 後処理余裕 <= lock の寿命」の中央に署名検証時間が入り、
  lock 寿命を伸ばす必要が出る = **障害時に後続が 503 になる時間が延びる**
- **窓は実在する**: 解放から昇格までの間に届いた別の要求は、キャッシュ不命中となり
  同じ証明書をもう一度取りに行く。窓の長さは署名検証 1 回ぶん (µs 級) で、
  取得 (数十 ms) に比べて無視でき、起きても「取得が 1 回余分に走る」だけである

### PEM 確認と署名検証の役割分担

- **PEM 確認 (`openssl_x509_read`)** は「形式不正の応答を早期に排除し、
  壊れた応答を検証にもキャッシュにも回さない」ための検査である。
  これが通ったことは「この証明書で署名検証できる」ことを意味しない
- **署名検証 (vendor の `validate()`)** が、キャッシュ昇格の**唯一の条件**である

### 応答サイズ上限の役割 (誇張しない)

上限は「**期待と違う応答を検証・キャッシュに固定しない**」ための検査であって、
**メモリ使用量の上界ではない**。Laravel の HTTP client は既定で非 stream なので本文は
先に全部メモリへ載り、長さを測る位置を変えても上界にはならない。自前の逐次読み出しは
第 2 の機構になるので作らない (思考原則 2)。実メモリが有界であることは
host が型で `sns.<region>.amazonaws.com` に固定され TLS のホスト名検証を通ること +
読み取りの時間上限 + redirect 禁止が担う。

### 状態遷移表 (何が起きたとき、HTTP・ロック・応答・キャッシュがどうなるか)

| 状況 | 外向き HTTP | ロック | 応答 | キャッシュ |
|---|---|---|---|---|
| 両キー同時送信 | 出さない | 取らない | 403 | 触らない |
| URL 書式が不正 | 出さない | 取らない | 403 | 触らない |
| SDK が別 URL を要求 | 出さない | 取らない | 403 | 触らない |
| キャッシュ命中 | 出さない | 取らない | 署名検証へ進む | 読むだけ |
| キャッシュ読みが例外 | 出す (miss 扱い) | 取る | 署名検証へ進む | miss 扱いで続行 |
| 読み戻しが PEM として壊れている | 出す | 取る | 署名検証へ進む | `forget` して miss 扱い |
| ロックを取れない | 出さない | 取れない | 503 | 触らない |
| ロック基盤が例外 | 出さない | 取れない | 503 | 触らない |
| SSRF 判定が DNS 解決失敗 | 出さない | 取得済み→解放 | 503 | 触らない |
| SSRF 判定がその他の拒否 | 出さない | 取得済み→解放 | 403 | 触らない |
| 通信失敗 / HTTP エラー応答 | 出す | 取得済み→解放 | 503 | 触らない |
| 応答サイズ超過 | 出す | 取得済み→解放 | 403 | 触らない |
| PEM として読めない | 出す | 取得済み→解放 | 403 | 触らない |
| 署名検証が失敗 | 出す (不命中時) | 取得済み→解放 | 403 | **書かない** |
| 署名検証が成功 | 出す (不命中時) | 取得済み→解放 | 次へ | 新規取得なら書く (失敗はログのみ) |
| プログラム不具合 (TypeError 等) | — | — | 伝播 (500) | 触らない |

## 期待効果

- **使命への貢献**: 無認証の SNS 受け口が誘発する外向き通信の境界と障害時の挙動を安全にする。
  抑止機構は「現場の担当者へ招待・通知メールが届き続けること」を支える運用基盤であり、
  抑止漏れは送信ドメインの評判を落として到達率を下げる。t1 は
  「一時障害を恒久失敗に化けさせない」「実装不具合を一時障害に隠さない」ことで、
  抑止が静かに止まる経路を減らす
- **セキュリティ不変条件 8 の穴を塞ぐ**。app/ の中で SSRF 検査を通さずに外部 URL を
  取りに行く最後の経路が無くなる
- **キャッシュ命中時は外向き通信もロックも発生しない** (証明書のローテーション・
  キャッシュ失効・キャッシュ障害では通信が再発する)
- 家系の正典 t1 に追従し、`aicue` の `target_version: t1` を満たす

## 実装方針 (概要)

新設 3 ファイル・改修 2 ファイル・テスト改修 3 ファイル・目録更新 3 ファイル。

- 新設 `app/Services/Mail/Sns/SnsCertificateUrl.php` — 検証済み URL の値オブジェクト。
  厳格 URL 検証は t0 の判定式をそのまま移設し、credential (user/pass) 拒否を足す。
  生成口は検証つきの `fromString()` だけで、取得口の公開 API はこの型しか受け取らない
  (未検証の文字列を渡す経路を**契約ではなく型**で消す)
- 新設 `app/Services/Mail/Sns/SnsCertificateFetcher.php` — 証明書取得の唯一の口。
  キャッシュ読み (障害は miss 扱い / 壊れた値は `forget`) / 単一ロックによる直列化 /
  SSRF 検査 / HTTP 取得 / 応答サイズ上限 / PEM 確認 / 検証後の昇格 (`remember()`) を持つ
- 改修 `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` — 両キー拒否・VO 生成・
  certClient の URL 同一性検査・検証成功後の昇格に責務を絞る。HTTP client は持たない
- 改修 `config/services.php` — `services.ses.webhook.*` に取得の時間予算・ロック寿命・
  キャッシュ寿命・応答サイズ上限を置く
- 新設 `tests/Architecture/SnsCertificateFetchContractTest.php` — 取得口の唯一性・
  時間の大小関係・単一ロックキー = permit 1 を機械固定する
- 改修 `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` / 新設
  `tests/Feature/Mail/SnsCertificateFetcherTest.php` / 改修
  `tests/Feature/Mail/SesSignatureMiddlewareTest.php`
- 目録更新 `tests/Architecture/CachePayloadPlainDataGateTest.php` (書き込み経路 + 面) /
  `tests/Architecture/ExternalClientTimeoutInventoryTest.php` (新クラスの免除登録) /
  `tests/Architecture/ValidationAttributeCoverageTest.php` (行番号キーの更新)
- 文書更新 `docs/ses-mail-runbook.md` / `docs/architecture.md`

## 制約・前提

- **受け口 route の throttle は変えない**。`throttle:webhook-ses` が署名検証より前にある
  現状 (aicue:T120 由来) は `path-based-throttle` の管轄であり、本施策の対象外
- **`kent013/laravel-ssrf-pin` は ^0.2 で導入済み**。`UrlSafetyInspector` は
  container から解決でき、境界は `config/ssrf-pin.php` に pin 済み
  (`deny_ip_literals => true`)。版差の判定は `ssrf-pin-boundary` の管轄
- **テストレーンは外向き HTTP が既定拒否** (`StrayHttpRequestGuard`)。証明書取得のテストは
  すべて `Http::fake()` 前提で書く。`UrlSafetyInspector` は実 DNS を引くので、
  テストでは `Kent013\SsrfPin\Contracts\DnsResolverInterface` を
  `Kent013\SsrfPin\Testing\FakeDnsResolver` へ差し替える
  (`UrlSafetyInspector` 自体は `ExternalFakeDeclaration::neverSwapped()` により偽物にできない)
- **キャッシュに入れるのは素のデータだけ** (セキュリティ不変条件 11)。PEM は文字列なので
  適合するが、`CachePayloadPlainDataGateTest` の書き込み目録と面目録への登録が必須
- **`ExternalSeamInventory` への登録は不要**。同目録の `http_facade_reference` 規則は
  `Http::` facade の参照だけを母集団にし、新クラスは既存実装と同じく
  `Illuminate\Http\Client\Factory` を注入して使うため母集団に入らない
- **AWS SDK の実挙動への依存を新設しない**。両キーの上書き順序は裁定が vendor 1.10.0 で
  実読確認済みの事実だが、aicue 側は「両方あれば拒否」なので順序に依存しない
  (単独時の優先順のみ SDK と揃える)

## 保証しないもの (誇張しない)

- **DNS rebinding そのものを検査では再現できない**。耐性は https + TLS のホスト名検証 +
  redirect 禁止が担い、テストで固定できるのは「private IP に解決される host は 403」
  「DNS 解決失敗は 503」「通信系の失敗は 503」までである
- **応答サイズ上限はメモリの上界ではない** (上記のとおり)
- **ロックは worker 占有の上界を条件付きでしか与えない**。1 要求のロック保持が
  lock 寿命を超えた場合 (worker 停止・キャッシュ基盤の長時間停止) は取得が重なりうる。
  所有者つき解放で誤解放は防ぐが、重なり自体は防がない
- **キャッシュ store が共有されない構成 (file 等) ではホストごとに 1 回取りに行く**。
  既定 `database` は共有される
- **署名検証が成功した証明書しかキャッシュに載らない**ことは実装の不変条件だが、
  「キャッシュにある証明書が今も有効」ことは意味しない (寿命で失効させるだけ)

## テストで必ず固定すること

- **署名検証が落ちたら 1 バイトもキャッシュされない** (負例。同じ通知を 2 回送ると
  2 回とも取りに行くことで確認する)
- 両キー同時送信 → 403、取得すらしない
- SDK が検証済み URL 以外を要求 → 403、取得すらしない
- private IP へ解決される host → 403 / DNS 解決失敗 → 503
- 通信系の失敗 (接続・HTTP エラー応答) → 503 / プログラム不具合 → 伝播 (503 にしない)
- サイズ超過・PEM 不正 → 403、かつキャッシュに固定しない
- ロック保持中の別要求 → 503 で、自分では取りに行かない
- キャッシュ読み書きの障害で署名検証を止めない
- 時間の大小関係と「単一ロックキー = permit 1」の機械固定

## スコープ外

- 受け口への流量制限の設計変更 (`path-based-throttle` の管轄)
- 抑止表そのもの (digest 化・鍵世代・参照表) — motivation 固有で本裁定の対象外
- 2 本目の SNS 受け口 (配信明細) — aicue には無い
- `docs/ses-mail-runbook.md` のメールテーマ節がテンプレートより 1 版古い件
  (抑止機構の記述ではないため別件)
- `PinnedHttpClient` への一本化 (`ssrf-pin-boundary` の管轄。裁定も
  「inspect → fetch でよい」と明記)

