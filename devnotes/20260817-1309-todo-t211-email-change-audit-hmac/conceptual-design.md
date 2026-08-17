# 概念設計: メール変更の監査記録へ鍵つきハッシュ 2 値を載せる (aicue:T211)

## 目的と台帳の根拠

家系の機能台帳 lctl の feature `auth-email-change-protection` に対する
**裁定 AG-195 (2026-08-16)** への追従である。

裁定の本文 (台帳より):

> 家系統一で「載せる」。全リポジトリのメール変更の監査記録に、変更前後のアドレスの
> 鍵つきハッシュ (HMAC) 2 値を記録する。生アドレスと鍵なしハッシュは禁止。
> 正典形は aigenba の実装 (`app/Support/EmailHash.php` の HMAC(app.key) と、
> 記録側の `old_email_hash` / `new_email_hash` の 2 値)。専用鍵 (app.key と別の監査用鍵) への
> 切り出しは任意の改善として残す。

台帳における本リポジトリの状態は `aicue: update_pending` で、追従の中身は
「メール変更の監査記録へ HMAC ハッシュ 2 値を追加する (正典形は aigenba の EmailHash)」の
1 点だけである (旧アドレスへの通知・監査記録の存在・変更前の再認証という他の物差しは
充足済みのまま)。

先行して追従を終えた実装が家系に 1 本ある — laravel-claude-template:T129 が
2026-08-17 に同じ 2 値 (`old_email_hash` / `new_email_hash`) を追加し、既存の
`EmailHash` を再利用して 2 本目の算出も専用監査鍵も作らなかったことを台帳へ報告している。
本設計はその形に揃える。

なお、台帳へ書くときに単一リポジトリでのみ意味が通る ID
(T 番号など) は `<repo>:ID` の形にする規律があるため、本設計でも
`aicue:T211` / `laravel-claude-template:T129` のように書く。

## 背景・課題

`aicue` のメール変更の監査行は現在 metadata を 1 つも持たない (`null`)。
実装 (`app/Actions/Fortify/UpdateUserProfileInformation.php`) は

```php
$this->recorder->record(SecurityEventType::EmailChanged, $user);
```

と種別と利用者だけを記録し、契約テスト
(`tests/Feature/Security/SecurityAuditTrailCoverageTest.php`) が
`metadata` が `null` であることを固定している。

このため監査行から分かるのは「この利用者のメールアドレスが、この時刻に、この接続元から
変わった」ことまでで、**どのアドレスからどのアドレスへ変わったか**は残らない。
`users.email` は CipherSweet で暗号化されているが**上書きされる**ので、旧アドレスは
2 回目の変更や退会の後には復元できない。乗っ取りの調査で最も知りたい
「攻撃者がどの宛先へ書き換えたか」「本人が使っていた宛先はどれだったか」が
事後には追えない状態である。

一方で「監査行に個人情報を残さない」という要求も同時にある。監査表は追記専用で、
一度書いた値は前進のみの移行でしか消せない。裁定 AG-195 はこの交換関係を
**鍵つきハッシュ (HMAC) なら載せてよい**と決着させた — 鍵を持たない者には乱数であり、
鍵保持者でも復元はできず、手元の候補アドレスとの一致を確かめられるだけだからである。

## 改善アイデア

メール変更を記録する 1 箇所で、変更前後のアドレスの HMAC 値 2 つを監査 metadata に載せる。

- キー名は正典形と同じ `old_email_hash` / `new_email_hash` (家系で照合できるようにする)
- 値は既存の `App\Support\EmailHash::compute()` (HMAC-SHA256 / 鍵は `app.key`) の戻り値
- 平文アドレスは metadata に載せない (裁定の「生アドレスと鍵なしハッシュは禁止」を守る)
- **観測専用**である。この 2 値で分岐する処理は 1 つも作らない

## 既存資産の再利用可否 (実読で確認した)

`AGENTS.md` ドメイン固有規約 5 が定める流量制限のキー規約のために、本リポジトリには
既に `EmailNormalizer` → `EmailHash` の 2 クラスがある。実読した結果は次のとおりで、
**そのまま再利用できる**。

| クラス | 実体 | 判断 |
|---|---|---|
| `app/Support/EmailHash.php` | `hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'))` を返す。docblock に「単純 sha256 は辞書攻撃に弱いため HMAC を使う」「APP_KEY ローテーション時は前後の hash を突合できない」と明記済み | **再利用する**。正典形 (aigenba の `EmailHash`) と同じ HMAC(app.key) であり、2 本目の算出を作る理由が無い |
| `app/Support/EmailNormalizer.php` | `trim` + 小文字化のみ。`Str::transliterate()` は使わない旨を docblock が明記 | **呼び出さない**。`EmailHash` が内部で同値の正規化 (`mb_strtolower(trim())`) を再適用しており、呼び出し側で二重に掛ける意味が無い |

稼働実績も確認した — `EmailHash` は流量制限のキー生成
(`FortifyServiceProvider` / `AppServiceProvider`) と配信抑制の照合
(`Services/Mail/EmailSuppressionService`) で既に本番経路に乗っており、
`tests/Unit/Support/EmailHashTest.php` が hex 64 桁・正規化の収束・鍵つきであることを
固定している。

## 期待効果

- **使命への貢献**: 本アプリは現場の作業手順書と、そこから作った動画マニュアルという
  組織の資産を預かる。登録メールアドレスはパスワード再設定の宛先なので、ここを
  静かに書き換えられるとアカウントごと資産を奪える。奪われた後に
  「どの宛先へ移されたか」を追える状態にしておくことは、被害の把握と復旧の前提になる。
- **具体的な改善**: 乗っ取り調査で、手元の候補アドレス (通報された宛先・別の記録に
  残るアドレス) と監査行のハッシュを突き合わせられるようになる。退会後も監査行は
  残る (`user_id` は `nullOnDelete`) ため、退会を挟んだ追跡もできる。
- **家系との整合**: 台帳の `aicue` セルが `update_pending` → 追従済みへ進む。
  同じキー名で家系 6 リポジトリの監査行を同じ問いで読めるようになる。

## 実装方針 (概要)

変更は記録している 1 箇所だけである。

1. `UpdateUserProfileInformation::update()` で、**保存 (`forceFill()->save()`) の前に**
   旧アドレス・新アドレスの HMAC を算出する。
   算出を先に置く理由は、`EmailHash::compute()` が `config('app.key')` が文字列であることを
   `Assert` で要求しており、前提違反で例外になるなら**不可逆な状態変更 (メールアドレスの
   書き換え・確認済みの解除・旧アドレスへの通知) の前**に落ちるべきだからである。
2. 算出した 2 値を `record()` の metadata として渡す。
3. 実装のコメントを裁定の文言へ揃える — 現行の「平文 email は metadata に載せない」を
   「**生アドレスと鍵なしの変換値は載せない**」へ言い換え、載せる 2 値が
   観測専用であることを明記する。
4. 契約テストを「metadata が `null`」から「2 値ちょうどで、値が `EmailHash::compute()` と
   一致し、保存された JSON に平文アドレスが現れない」へ書き換える。

記録の窓口は `SecurityEventRecorder::record()` (best-effort) のままにする。
`recordOrFail()` へ変えない — `AGENTS.md` ドメイン固有規約 16 が
「失効以外に `recordOrFail()` を使わない (監査の失敗でログインを落とすことになる)」と
定めており、メール変更はその「失効」ではない。

## 制約・前提

- **観測専用の規律**: 2 値で分岐する処理を作らない。先例は退会の監査 metadata
  (`AccountDeletionAuditContext`) で、`docs/architecture.md` が
  「これは観測であって防御ではない」と明記している。本件も同じ扱いにする。
- **平文を載せない**: metadata に入るのは 64 桁の hex 2 本だけである。
- **記録経路の目録**: `tests/Architecture/SecurityEventCoverageTest.php` の
  `securityEventRecordingMap()` は `email_changed` を
  `caller = UpdateUserProfileInformation` / `covered_by =
  tests/Feature/Security/SecurityAuditTrailCoverageTest.php` として既に宣言している。
  記録経路のクラスも担保テストのファイルも変えないので、**目録は 1 行も動かない**。
- **表も列も増やさない**: `security_audit_events.metadata` は既存の nullable な JSON 列で、
  migration は不要である。保持期限の分類 (`RetentionTableRegistry`) も表単位なので動かない。
- **監査表は追記専用**: 裁定より前に記録された `email_changed` 行に 2 値は付かない。
  遡及付与はしない。

## スコープ外 (今回やらないこと)

| やらないこと | 理由 |
|---|---|
| 監査専用鍵 (`app.key` とは別の鍵) への切り出し | 裁定が「任意の改善として残す」と明記している。鍵を増やすと本番の必須環境変数と起動時検査 (`ProductionEnvGuard`) が増える。今必要なものだけ作る。**再評価の契機**は「`APP_KEY` のローテーション運用が具体化したとき」である (ローテートすると前後の監査行を突合できなくなるため、そのときに鍵の寿命を分けるかを決める。Codex 概念設計レビュー Round 1 の指摘への対応) |
| 変更判定の正規化 (大文字小文字だけの違いを「変更なし」と見る) | 台帳の 2026-08-16 の所見が指摘する別件で、影響先は監査ではなく「確認済みの解除」と「旧アドレスへの通知」である。別 TODO として起票するのが筋で、混ぜると受け入れ条件が 2 系統になる |
| メール変更の流量制限・変更後の再認証の鮮度失効 | 家系の他リポジトリが持つ上積みだが、裁定 AG-195 の対象ではない |
| 既存の `email_changed` 行への遡及付与 | 監査表は追記専用。過去の行に後から値を書くのは監査証跡の性質に反する |
| `EmailHash` を `EmailNormalizer` 経由に書き換える | 現行の内部再適用と結果が同値であり、流量制限のキー生成という稼働中の経路を触る理由が無い |
| 通知メール本文・画面への 2 値の表示 | 監査行の読み手は調査者であり、`Filament` の一覧は metadata 列を表示していない。露出面を増やさない |

## 保証しないもの (誇張しない)

- **復元はできない**。ハッシュは候補アドレスとの一致確認にしか使えない (鍵保持者でも同じ)。
- **`APP_KEY` をローテートすると前後の値を突合できない** (`EmailHash` の docblock が既に
  宣言している制約)。監査鍵を分けない以上、この制約は監査行にも及ぶ。
- **監査行が書けなくてもメール変更は成立する** (`record()` は best-effort)。本設計は
  この既存の意味を変えない。
- **変更判定が正規化されていないため、大文字小文字だけが違う入力も「変更」として記録され、
  そのとき 2 値は一致する**。一致すること自体に意味を持たせない (観測専用)。
- 平文アドレスの露出面 (旧アドレスへの通知メール・`users` 表) は本変更で増えも減りもしない。
