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


あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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

### 待つか待たないか (値の選択)

裁定は「待ち上限 0 (待たない) も適合」とし、不変条件は**時間の大小関係の機械固定**の側にあるとした。
aicue は **待たない (非ブロッキング)** を採る。理由は 2 つ。

- 取得できなかった要求は 503 を返し、SNS が再送する。再送時には winner がキャッシュを
  埋め終えているので即成功する。待つ利得がほぼ無い
- 待つ実装は「待ち時間ぶん worker を占有する」ため、この施策の目的
  (無認証経路が誘発する外向き通信の worker 占有に上界を作る) と正面から競合する

### ロックキーを 1 本にする (URL ごとに分けない)

厳格 URL 検証を通る URL は `https://sns.<region>.amazonaws.com/SimpleNotificationService-<英数>.pem`
の形であり、末尾の英数部分は攻撃者が自由に変えられる。URL ごとにロックキーを分けると
**存在しない証明書名を並べるだけで同時取得数を増やせる**ので、ロックキーは 1 本にして
「同時取得数 = 1」を構造的に保つ。キャッシュキーだけを URL の sha256 で分ける。

## 期待効果

- **使命への貢献**: 抑止機構は「動画マニュアルを作る現場の担当者へメールが届き続けること」を
  支える運用基盤である。抑止漏れは送信ドメインの評判を落とし、招待メール・通知メールの
  到達率を下げる。t1 は「一時障害を恒久失敗に化けさせない」「実装不具合を一時障害に隠さない」
  ことで、抑止が静かに止まる経路を減らす
- **セキュリティ不変条件 8 の穴を塞ぐ**。app/ の中で SSRF 検査を通さずに外部 URL を
  取りに行く最後の経路が無くなる
- **正常時の外向き通信が消える**。キャッシュ命中時はロックも通信も無い
- 家系の正典 t1 に追従し、`aicue` の `target_version: t1` を満たす

## 実装方針 (概要)

新設 3 ファイル・改修 2 ファイル・テスト改修 3 ファイル・目録更新 3 ファイル。

- 新設 `app/Services/Mail/Sns/SnsCertificateUrl.php` — 検証済み URL の値オブジェクト。
  厳格 URL 検証は t0 の判定式をそのまま移設し、credential (user/pass) 拒否を足す
- 新設 `app/Services/Mail/Sns/SnsCertificateFetcher.php` — 証明書取得の唯一の口。
  キャッシュ読み (障害は miss 扱い) / 単一ロックによる直列化 / SSRF 検査 / HTTP 取得 /
  応答サイズ上限 / PEM 確認 / 検証後の昇格 (`remember()`) を持つ
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

## スコープ外

- 受け口への流量制限の設計変更 (`path-based-throttle` の管轄)
- 抑止表そのもの (digest 化・鍵世代・参照表) — motivation 固有で本裁定の対象外
- 2 本目の SNS 受け口 (配信明細) — aicue には無い
- `docs/ses-mail-runbook.md` のメールテーマ節がテンプレートより 1 版古い件
  (抑止機構の記述ではないため別件)
- `PinnedHttpClient` への一本化 (`ssrf-pin-boundary` の管轄。裁定も
  「inspect → fetch でよい」と明記)
