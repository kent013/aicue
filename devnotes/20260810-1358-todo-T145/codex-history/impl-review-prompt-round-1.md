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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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


# あなたの役割

Laravel + Svelte アプリ (aicue) の**実装レビュアー**である。以下の diff をレビューせよ。

## この PR の性格 (重要)

**極小 PR (PR-C3)** である。オーナー決定により「規約文面の追記」と「それを固定する検査」だけを入れる。
スコープ拡大の提案 (他機能の実装・リファクタ・新規基盤) は**この PR では受け付けない**。
提案するなら「別 PR で」と明示すること。

守るべき制約 (オーナー決定。逸脱不可):
- `config/legal.php` の `consent_version` を `draft-1` から動かさない
- 保持年数 (7) を blade に literal で書かない (`App\Support\Legal\BillingRetention::years()` から描画)
- 追記した文面は**法務レビュー前の草案**である旨を明記する
- 検査は**見出し番号ではなく** `data-legal-retention` 属性と固定文言「取引関係書類等」で照合する
  (節の並べ替え・番号の繰り下げに耐えるため)

## レビュー観点

1. **設計との一致性** — 下の PR-C3 節 (SoT) の要求を満たしているか。過不足はないか
2. **検査の実効性** — gate が **vacuous green (空振り)** にならないか。
   壊したときに本当に赤くなるか。逆に、正当な変更で**偽赤**にならないか
   (特に: 見出し番号の繰り下げ、節の並べ替え、blade の整形、Blade コンパイル結果の変化)
3. **正確性** — token 走査 / Blade::compileString / DOM 解析の扱いに穴はないか
4. **PHPStan 適合性** (level は phpstan.neon 準拠。`@phpstan-ignore` 禁止)
5. **テスト網羅性** — 負のコントロール / 母集団 floor / exact-fit cap / 自己参照コントロールが揃っているか
6. **セキュリティ** — 公開ページの描画に XSS 等の穴を作っていないか
7. **誇張しない docblock** — 「保証しないもの」の記述が実際の検査能力と一致しているか
   (**過大に保証を主張していないか / 逆に実際には守っているのに書き落としていないか**)

## 出力形式

ファイルごとに判定を書き、指摘は次の 3 分類で示すこと。

- `[Critical]` — 必ず修正が要る (設計違反 / 検査が空振り / バグ / 規約違反)
- `[Warning]` — 検討して対応すべき
- `[Suggestion]` — 任意

最後に **全体判定** を `APPROVED` か `CHANGES_REQUESTED` のどちらかで明示せよ。

---

# user 部

## 詳細設計書 (SoT) — PR-C3 節の全文

# PR-C3: 保持期間の公開 (極小 PR)

## C3a. 規約文面 (法務レビュー前の**草案**)

`resources/views/legal/privacy.blade.php` の「3. 第三者提供」と「4. 開示・訂正・削除」の間に
新しい節を挿入する。**文面案は本設計の §付録 A に全文を置く** (オーナーが目視確認できるように)。

- **年数の数値は `\App\Support\Legal\BillingRetention::years()` から描画する**
  (**blade が config を直読しない**。「config を読んでよいのは `BillingRetention` 1 箇所だけ」という
  C3b の検査 1 と整合させる)。blade に `7` の literal を書かない (三者一致の要)。
- `data-legal-retention="billing-records"` のマーカー要素を持たせる。
- **`config/legal.php` の `consent_version` は `draft-1` から動かさない** (オーナー決定)。

## C3b. 三者一致 gate

`BillingRetentionSingleSourceTest` (Architecture)。`LegalConsentVersionSingleSourceTest` と同じ
token 走査 + exact-fit caller inventory の書式:
1. `config('legal.billing_retention_years')` を読んでよいのは `BillingRetention` **1 箇所だけ**
2. `BillingRetention::years()` / `::threshold()` の呼び出し元が **exact-fit の目録**と一致
   (**privacy blade** / purger 群 / horizon テスト)。blade も呼び出し元として目録に載る
3. blade に保持年数の literal (`7` / `７` / `七`) が現れない
4. 空振り検知 + 負のコントロール (fixture ソースで点灯する)

`PrivacyRetentionDeclarationTest` (Feature)。`GET /privacy` を実際に叩き 4 点を検査:
(a) `data-legal-retention="billing-records"` マーカーの存在、
(b) 保持期間の**節見出し**の存在、
(c) 先例由来の固定文言 **「取引関係書類等」** の存在、
(d) **その要素内に** config 由来の年数が現れること。
「節ごと消えた」も「数字だけ別の文脈に残った」も検出できる。

**保証しないもの (誇張しない)**:
- 文面の日本語が法的に正しいか / 7 年が法令上妥当か (**法務レビューの仕事**。本追記は草案)
- 散文部分の意味と実処理の一致 (機械が見るのは数値 1 つとマーカーの存在だけ)
- purge 対象テーブルの網羅性 (inventory への人間の申告)
- 「文面が変わったのに版が上がっていない」こと (`consent_version` を動かさないため)

---

# 共通: 検査が空振りしないことの保証

新設する全 gate に以下を必ず同梱する (本リポジトリの gate 書式)。

| 手段 | 内容 |
|---|---|
| **母集団 floor** | 走査ファイル数 / route 数 / 目録件数が 0 でないことを下限で pin。0 件なら fail |
| **exact-fit cap** | 免除・allowlist の件数を**現在値ちょうど**で pin (余裕を 1 でも持たせない。
`ThrottleCoverageInventoryTest` の cap コメントと同じ理由 — 余裕枠は「根拠なしに免除できる枠」になる) |
| **負のコントロール** | fixture ソース (nowdoc 内。code token にならない) を検出器に当てて**点灯すること**を確認 |
| **自己参照コントロール** | gate ファイル自身を走査して hit 0 件 (説明コメントで偽赤にならない) |
| **正の自己検証** | 実ファイルで検出器が実際に点灯すること (検出器が死んでいないこと) |

# 共通: mutation で赤化を確認する手順

**実装完了の条件**は「テストが緑」ではなく「**壊すと赤くなることを実測した**」である。
各 gate について以下を**実行し、結果を実装ノートに記録する**。

| # | 変異 (実施後は必ず戻す) | 赤くなるべきテスト |
|---|---|---|
| M1 | `AccountDeletionPathGateTest` の起点から `deleteAccount` を外す | 空振り検知 (閉包サイズ floor) |
| M2 | `OrganizationMembershipService` に `Stripe\StripeClient` を型注入するだけの private property を足す | 依存閉包 gate 検査 2 |
| M3 | 同じ注入を `app('cashier.stripe')` の literal 呼び出しで書く | 同上 (fixture 4 形目) |
| M4 | `AccountDeletionFreezeAllowance` から `settings` を削る | 到達性テスト (取消に到達できない) |
| M5 | 同 enum に `dashboard` を足す | exact-fit 検査 3 |
| M6 | 凍結 middleware を priority list で `EnsureProjectBelongsToCurrentOrganization` より**前**へ動かす | `TenantBoundaryOrderingTest` + 他組織 `{project}` が 302 になる behavioral |
| M7 | `PurgeDeletionRequestsCommand` の終了コードを常に `SUCCESS` にする | 「想定外例外で FAILURE」テスト |
| M8 | `deleteAccount` の precondition 差し込み位置をブロッカー判定の**後**へ動かす | 「抽出後に取消 → 削除しない」テスト |
| M9 | 通知の `via()` から予約生存の再確認を外す | 「予約 → 即取消 → メール 0 通」テスト |
| M10 | `BillingRetentionTarget` から `Subscription` を削る | 目録 exact-fit (母集団の分類漏れ) |
| M11 | `Subscription` の起算列を `ends_at` → `created_at` に変える | 「継続中は何年経っても対象外」テスト |
| M12 | `TicketLedgerEntry` を C1 の horizon 対象に入れる | horizon (期限超過が残る) |
| M13 | 畳み込みで `source` を捨てて 1 行に合算する | 6 種比較の「source 別残高」 |
| M13b | 畳み込みの group key から `organization_id` を外す | 7 種比較の「組織ごとの残高一致」(複数組織 fixture) |
| M14 | privacy blade の年数を literal `7` に書き換える | 三者一致 gate 検査 3 |
| M15 | privacy の保持期間の節ごと削除する | `PrivacyRetentionDeclarationTest` (a)(b)(c)(d) |
| M16 | `BillingRetentionPurgeResultDto::isPublicationReady()` から `failClosed === 0` を外す | 公開条件テスト |
| M17 | `AccountDeletionFreezeAllowance` に `settings.account.destroy` を足す | 「予約中は即時削除できない」テスト |
| M18 | `logout` を `auth`+`verified` group の中へ移す | 凍結 gate 検査 7 (`U` に含まれないこと) |
| M19 | `requestAccountDeletion` の冪等 no-op を外し予約中でも通知を発火させる | 「予約 POST 2 回でメール 1 通」テスト |
| M20 | 執行バッチの抽出条件から `whereNotNull('deletion_requested_at')` を外す | 「片列だけの非正規行を due に数えない」テスト |
| M21 | `config/account.php` の `deletion_grace_days` を 0 にする | `AccountDeletionGraceConfigTest` の fail-fast |
| M22 | `purgeAfter()` を `addDaysNoOverflow` に戻す | 「2026-01-31 の 30 日後 = 2026-03-02」behavioral |
| M23 | 通知 `via()` を `fresh() ?? $notifiable` へ戻す | 「執行済み user へ送らない」テスト |
| M24 | redaction 記録の CHECK 制約を外し片列だけ UPDATE する | migration の DB 制約テスト |
| M25 | `recent-auth.confirm` を allowlist から外す | 到達性 (d) 移譲画面へ到達できない |
| M26 | `StripeWebhookEvent` の `anomalyClockColumn()` を null にする | 「未処理の古い webhook が failClosed に計上される」テスト |
| M27 | `AccountDeletionFreezeAllowance` に `billing.auto-recharge.update` を足す | 「予約中に auto-recharge 更新が遮断される」テスト |
| M28 | users の CHECK 制約を外し片列だけ UPDATE する | migration の DB 制約テスト |
| M29 | `PortalConfigurationSpec` の `subscription_update` を `true` にする | `AccountDeletionFreezeRouteGateTest` の**前提検査 3 点** (`--verify` は spec との一致しか見ないため、前提 pin が無いと赤化しない可能性がある。**どのテストが赤くなったかを実装ノートに記録する**) |

**手順**: 1 変異ずつ適用 → 対象テストが**赤いこと**を実測 → 変異を戻す →
全体が緑に戻ることを確認 (`git diff` が空であることも確認する)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | migration 3 / middleware 1 (priority list と group への配線) / route 2 / command 3 / Architecture gate 5 / 既存 gate 更新 8 に及ぶ。`bootstrap/app.php` の priority list・`routes/web.php` の group・`routes/console.php`・`docs/architecture.md` はどれも他タスクと競合しやすい中心ファイルである |
| 競合リスク | `routes/console.php` (スケジュール追加) / `bootstrap/app.php` (middleware 配線) / `docs/architecture.md` (節追加) / `config/legal.php` — いずれも並行タスクが触りうる。**5 PR は直列**に入れ、並行 worktree は作らない |

## 台帳への報告

**C3 完了後に 1 回**。条件は 5 つすべての成立:
(a) C2 デプロイ済み / (b) 初回 `--apply` 完走 /
(c) **`failClosed` を含む期限超過件数が 0** / (d) C3 マージ・デプロイ済み /
(e) 三者一致 gate が green。
A/B/C1/C2 の途中で `implemented` を主張しない。

**併せて台帳へ訂正を出す** (recon-brief の申し送り):
feature_yaml の boundary が「aicue は route `settings.account.destroy` 相当を ProfileController が受ける」と
書いているのは**誤り**。実際に `DELETE /settings/account` を受けるのは
`app/Http/Controllers/Settings/AccountController.php::destroy` で、`ProfileController` は
`/settings` の props を組み立てる読み取り側である。

---

## 付録 A: `privacy.blade.php` へ追記する文面案 (法務レビュー前の**草案**)

> **この文面は法務レビュー前の草案である。** 家系の先例 (spirux の /privacy
> 「取引関係書類等につき最長 7 年」) に揃えたものであり、独自の法的主張を書き起こしていない。
> **「実装が宣言する年数」と「法務が確定する年数」が一致することの確認は人間の仕事である。**
> `config/legal.php` の `consent_version` は本追記では `draft-1` から動かさない
> (版の確定はリリース時のオーナー判断)。

```blade
        <h2 id="retention">4. 保有期間</h2>
        <p data-legal-retention="billing-records">
            当社は、取得した個人情報を利用目的の達成に必要な期間に限り保有し、
            当該期間の経過後は遅滞なく消去または匿名化します。ただし、
            <strong>ご契約およびお支払いに関する取引関係書類等については、
            法令に定める保存期間に従い、取引の終了時から最長{{ \App\Support\Legal\BillingRetention::years() }}年間</strong>
            保有します。
        </p>
        <p>
            保有期間の起算点は取引の終了時（ご契約の終了日、お支払いの確定日等）です。
            継続中のご契約に関する記録は、当該契約が終了するまで保有します。
        </p>
```

> 追記に伴い、既存の「4. 開示・訂正・削除」以降の見出し番号を 1 つずつ繰り下げる
> (`4.` → `5.`)。見出し番号の付け替えは文面の意味を変えないが、
> `PrivacyRetentionDeclarationTest` は**番号ではなく `data-legal-retention` 属性と
> 「取引関係書類等」という語**で検査する (並べ替えに耐えるため)。


## 実装ノート (テストファーストの赤 / mutation の実測)

# T145 (PR-C3) 実装ノート — 保持期間の規約公開

SoT: `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` の **PR-C3 節** と
`recon-brief.md` 冒頭のオーナー決定。

## 変更点 (極小 PR)

| ファイル | 変更 |
|---|---|
| `resources/views/legal/privacy.blade.php` | 「3. 第三者提供」と「4. 開示・訂正・削除」の間に **`4. 保有期間` (`id="retention"`)** を挿入。年数は `\App\Support\Legal\BillingRetention::years()` から描画。`data-legal-retention="billing-records"` マーカー付き。既存「4. 開示・訂正・削除」を **5.** へ繰り下げ。**法務レビュー前の草案である旨を blade コメントに明記** |
| `tests/Architecture/BillingRetentionConfigSingleSourceTest.php` | 検査 1 の走査対象へ `resources/views` を追加 (blade も config 直読しない)。**検査 6** (`years()`/`threshold()` 呼び出し元の exact-fit 目録)、**検査 7** (privacy blade に年数 literal が無い / SSOT 呼び出しちょうど 1 回)、**検査 8** (検出器の負のコントロール) を追加 |
| `tests/Feature/Legal/PrivacyRetentionDeclarationTest.php` | 新規。`GET /privacy` を実際に叩き (a) マーカー (b) 節見出し (c) 固定文言「取引関係書類等」 (d) マーカー内の年数 (e) config を変えると描画も追随、の 5 点 |
| `docs/architecture.md` | 保持期間の節へ「規約側の宣言 (T145)」を追記 (草案である旨・検査の担当範囲・`consent_version` を動かさないこと) |

**触っていないもの**: `config/legal.php` (`consent_version` は `draft-1` のまま。
`billing_retention_years` も 7 のまま = git diff 空)。

## blade の走査方法

`{{ ... }}` は素の PHP ではないため `token_get_all` では見えない。gate は
`Blade::compileString()` で PHP へ落としてから token 走査する。
年数 literal は 2 系統で見る:

1. **散文側** — 生ソースに `N年` / `Ｎ年` / `漢数字年` (数字と「年」の間の空白は許容) が
   現れないこと。`{{ ... }}` の中身には数字が現れないので、literal を書けば必ずこの形で出る。
   見出し番号 (`7. その他`) は「年」が続かないので拾わない = **番号の繰り下げで偽赤にならない**。
2. **コード側** — compile 済み PHP に年数と同じ整数リテラルが無いこと
   (`@php $years = 7; @endphp` の迂回を塞ぐ)。

> fixture 注記: `@endphp` の直後に `{{` を置くと Blade の `@{{` エスケープ記法と衝突して
> raw block が復元されない。負のコントロールでは改行を挟んでいる。

## テストファースト (赤の実測)

実装前 (blade 未変更) に検査を先に置いた結果:

```
tests=13 passed=5 failed=8
- 検査 6 (呼び出し元 exact-fit)  … privacy blade が目録に無い
- 検査 7 (blade の SSOT 呼び出し) … ssotCall 0 != 1
- 検査 8 (負のコントロール)       … fixture の @php 検出 (後述の Blade エスケープ由来。fixture 修正)
- (a) マーカー要素               … null
- (b) 節見出し                   … null
- (c) 固定文言「取引関係書類等」  … 不在
- (d) マーカー内の年数           … null
- (e) config 追随                … null
```

実装後: `tests=13 passed=13`。
`tests/Feature/LegalPagesTest.php` (既存 /privacy の noindex 二重防御) も同時に green。

## mutation による赤化の実測 (入れた変異はすべて戻した)

| # | 変異 | 実測 |
|---|---|---|
| **M14** | blade の `{{ ...::years() }}` を literal `7` に置換 | **赤 3 本**: 検査 6 (blade が呼び出し元から消える) / 検査 7 (`ssotCall 0 != 1`) / Feature (e) (config を 9 にしても表示が 7 のまま)。散文 literal 検出器も同 blade で `7年` に HIT することを別途確認 (検査 7 は先行 assert で停止するため) |
| **M15** | 保有期間の節ごと削除 | **赤 7 本**: 検査 6 / 検査 7 / (a) / (b) / (c) / (d) / (e) |
| **config** | `config/legal.php` を `7 → 9` | `view('legal.privacy')` の描画が **`最長9年間`** に追随 (= 単一出典であることの実測)。同時に検査 2 が `9 is identical to 7` で赤 (オーナー決定の pin が効いている) |

いずれも変異を戻したうえで `git status` が想定どおり (privacy blade / gate / 新規テスト /
architecture.md のみ) であることを確認済み。`config/legal.php` は差分なし。

## 保証しないもの (誇張しない)

- 文面の日本語が法的に正しいか / 7 年が法令上妥当か — **法務レビューの仕事**。本追記は草案。
- 散文の意味と実処理 (purge バッチ) の一致 — 機械が見るのは数値 1 つ・マーカー・固定文言 1 語だけ。
- 「文面が変わったのに `consent_version` が上がっていない」こと — 版を動かさない前提のため対象外。
- 検査 7 の漢数字判定は 1〜99 のみ。privacy blade **以外**の blade の年数 literal には沈黙する。
- 検査 1 の走査に `tests/` は含めない (fail-fast 検証テストが config を書き換えるため)。

## 申し送り

`docs/billing-retention-runbook.md` の「PR-C3 のチェックリスト (必須)」が求める
**初回 `--apply` の出力の証跡** (target 別件数 / `fail_closed` = 0 / `unexpected_failures` = 0 /
`horizon: OK`) は **本番運用の実行結果**であり、本 worktree では取得できない。
**デプロイ時にオーナーが取得して PR/運用記録へ貼ること**。台帳 (lctl) への
`implemented` 報告も、その証跡が揃うまで出さない (設計 §台帳への報告の条件 (b)(c))。


## 実装差分 (git diff)

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index 4207d3f..5ec6c67 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1477,12 +1477,24 @@ ## 退会の猶予期間つき削除 (凍結方式・30 日)
   通知の重複配送も止めない (保証しているのは「予約操作からの job 生成は最大 1 件」まで)。
   予約中のユーザーが他者から招待を受けること自体は止めない
   (受諾 route は凍結対象なので受諾はできない)。
-## 課金記録の保持期間 (7 年) の決着 (T143 / T144)
+## 課金記録の保持期間 (7 年) の決着 (T143 / T144 / T145)
 
 保持年数の正本は `config/legal.php` の `billing_retention_years`、唯一の解決点は
 `App\Support\Legal\BillingRetention` (`BillingRetentionConfigSingleSourceTest` が機械固定)。
 運用手順・障害対応は **`docs/billing-retention-runbook.md` が正本**。
 
+- **規約側の宣言 (T145)**: `/privacy` の「保有期間」節が保持年数を公開する。年数は
+  literal を書かず `BillingRetention::years()` から描画し、**config / SSOT / 文面の三者一致**を
+  `BillingRetentionConfigSingleSourceTest` (検査 6 = 呼び出し元 exact-fit / 検査 7 =
+  blade に literal が無いこと) と `PrivacyRetentionDeclarationTest` (描画結果の側から
+  マーカー `data-legal-retention="billing-records"` / 節見出し / 固定文言「取引関係書類等」/
+  年数の 4 点) が機械固定する。**照合は見出し番号ではなく属性と固定文言**で行う
+  (節の並べ替え・番号の繰り下げで偽赤にしないため)。
+  ⚠ **この文面は法務レビュー前の草案**である (家系の先例に揃えたもので独自の法的主張はしない)。
+  「実装が宣言する年数」と「法務が確定する年数」の一致確認は**人間の仕事**であり、
+  `config/legal.php` の `consent_version` は本追記では `draft-1` から動かしていない
+  (版の確定はリリース時のオーナー判断)。よって「文面が変わったのに版が上がっていない」ことは
+  検査対象外である。
 - **コマンド**: `billing:purge-retention-expired` (既定 dry-run / `--apply` で実処理)。
   日次登録は `routes/console.php` の `Schedule::command('… --apply')->daily()->onOneServer()`。
 - **決着の方式は target で 2 種類ある**。削除で決着する 6 target
diff --git a/resources/views/legal/privacy.blade.php b/resources/views/legal/privacy.blade.php
index dca5042..1e493cf 100644
--- a/resources/views/legal/privacy.blade.php
+++ b/resources/views/legal/privacy.blade.php
@@ -23,7 +23,35 @@
         <h2>3. 第三者提供</h2>
         <p>法令に基づく場合を除き、本人の同意なく個人情報を第三者に提供しません。</p>
 
-        <h2>4. 開示・訂正・削除</h2>
+        {{--
+            保有期間の節。**この文面は法務レビュー前の草案である。**
+            家系の先例 (spirux の /privacy「取引関係書類等につき最長 N 年」) に揃えたもので、
+            独自の法的主張を書き起こしていない。「実装が宣言する年数」と「法務が確定する年数」が
+            一致することの確認は**人間の仕事**であり、確定時は config/legal.php の
+            billing_retention_years と本文面を同じ PR で更新すること
+            (config/legal.php の consent_version は本追記では draft-1 から動かさない。
+             版の確定はリリース時のオーナー判断)。
+
+            年数は literal で書かず App\Support\Legal\BillingRetention::years() から描画する
+            (config / SSOT / 文面の三者一致。BillingRetentionConfigSingleSourceTest 検査 7 が固定)。
+            data-legal-retention 属性は機械照合のマーカーで、見出し番号ではなくこの属性と
+            固定文言「取引関係書類等」で照合する (節の並べ替え・番号の繰り下げに耐えるため。
+            PrivacyRetentionDeclarationTest)。
+        --}}
+        <h2 id="retention">4. 保有期間</h2>
+        <p data-legal-retention="billing-records">
+            当社は、取得した個人情報を利用目的の達成に必要な期間に限り保有し、
+            当該期間の経過後は遅滞なく消去または匿名化します。ただし、
+            <strong>ご契約およびお支払いに関する取引関係書類等については、
+            法令に定める保存期間に従い、取引の終了時から最長{{ \App\Support\Legal\BillingRetention::years() }}年間</strong>
+            保有します。
+        </p>
+        <p>
+            保有期間の起算点は取引の終了時（ご契約の終了日、お支払いの確定日等）です。
+            継続中のご契約に関する記録は、当該契約が終了するまで保有します。
+        </p>
+
+        <h2>5. 開示・訂正・削除</h2>
         <p>利用者は自己の個人情報の開示・訂正・削除を請求できます。手続きはお問い合わせフォームよりご連絡ください。</p>
 
         <p style="margin-top:24px;">
diff --git a/tests/Architecture/BillingRetentionConfigSingleSourceTest.php b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
index 2eb4377..ea8de16 100644
--- a/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
+++ b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
@@ -3,37 +3,59 @@
 declare(strict_types=1);
 
 use App\Support\Legal\BillingRetention;
+use Illuminate\Support\Facades\Blade;
 
 /*
  * Architecture invariant: 課金取引記録の保持年数 (legal.billing_retention_years) の
- * **解決点は App\Support\Legal\BillingRetention の 1 箇所だけ**である。
+ * **解決点は App\Support\Legal\BillingRetention の 1 箇所だけ**であり、
+ * **規約文面 (/privacy) はその 1 箇所から描画される**。
  *
- * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C1 (C1a)
- * とオーナー決定 (課金取引記録の保持 = 7 年)。
+ * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の
+ * PR-C1 (C1a) / PR-C3 (C3b) とオーナー決定 (課金取引記録の保持 = 7 年)。
  *
  * 背景: この数値は「環境ごとに変えてよい運用値」ではなく、**法務文書 (/privacy) が
  * 宣言する値そのもの**である。読む場所が分岐すると「規約が宣言した年数」と
  * 「実際に消える年数」が静かにズレる — 利用者から見て検証不能な形で規約違反が起きる。
- * よって (a) env を使わない (b) config を読むのは SSOT クラス 1 箇所だけ、を機械固定する。
+ * よって (a) env を使わない (b) config を読むのは SSOT クラス 1 箇所だけ
+ * (c) 文面も literal を持たず SSOT から描画する、を機械固定する。
  *
  * ★この gate が保証するもの:
- *   - 検査 1: `'legal.billing_retention_years'` を読むのは BillingRetention だけ (app/ 走査)
+ *   - 検査 1: `'legal.billing_retention_years'` を読むのは BillingRetention だけ
+ *     (app/ config/ database/ routes/ **resources/views/** 走査。blade も直読しない)
  *   - 検査 2: config/legal.php の値が **整数リテラル**である (env() 経由で環境依存にしない)
  *     かつ**オーナー決定の 7** である
  *   - 検査 3: 実行時の `BillingRetention::years()` が config リテラルと一致する
  *   - 検査 4: 空振り検知 (走査ファイル数 / token 数が 0 でない) と
  *     正の自己検証 (SSOT ファイルで検出器が実際に点灯する)
  *   - 検査 5: 負のコントロール (fixture ソースで点灯 / コメント中の表記は点灯しない)
+ *   - 検査 6: `BillingRetention::years()` / `::threshold()` の呼び出し元が
+ *     **exact-fit の目録**と一致する (privacy blade もここに載る)
+ *   - 検査 7: privacy blade が保持年数の **literal を 1 つも持たない**
+ *     (散文の「N 年」も `@php` 内の整数リテラルも両方見る) かつ
+ *     SSOT 呼び出しをちょうど 1 回持つ
+ *   - 検査 8: 検査 6/7 の検出器の負のコントロール (fixture で点灯すること)
  *
  * ★この gate が保証しないもの (誇張しない):
- *   - **tests/ は走査しない**。保持年数の fail-fast (0 以下) を検証するテストは
- *     config を書き換える必要があり、そこを禁止すると検査そのものが書けなくなる
- *   - **規約文面 (privacy blade) との一致は見ない**。文面はまだ存在せず (PR-C3 の担当)、
- *     三者一致 (config / SSOT / 文面) の gate は PR-C3 で本 gate の上に積む
- *   - 動的キー組み立て (`config('legal.'.$key)`) には沈黙する (実測 0 件)
+ *   - **文面の日本語が法的に正しいか / 7 年が法令上妥当か**は見ない。現在の文面は
+ *     **法務レビュー前の草案**であり、「実装が宣言する年数」と「法務が確定する年数」が
+ *     一致することの確認は**人間の仕事**である
+ *   - **散文の意味と実処理の一致**は見ない (機械が見るのは数値 1 つとマーカーの存在だけ)。
+ *     描画結果の側からの照合は tests/Feature/Legal/PrivacyRetentionDeclarationTest.php
+ *   - **「文面が変わったのに版が上がっていない」ことは見ない**
+ *     (本タスクでは `consent_version` を draft-1 から動かさないため)
+ *   - 検査 1 の走査に **tests/ は含めない**。保持年数の fail-fast (0 以下) を検証する
+ *     テストは config を書き換える必要があり、そこを禁止すると検査そのものが書けなくなる
+ *     (検査 6 の呼び出し元目録だけは tests/ も母集団に含む)
+ *   - 動的キー組み立て (`config('legal.'.$key)`) / 変数経由の呼び出しには沈黙する
+ *   - 検査 7 の漢数字判定は **1〜99** のみ対応する (それを超える保持年数は ASCII /
+ *     全角数字の形しか検出しない)
+ *   - privacy blade **以外**の blade に年数の literal が書かれても検査 7 は沈黙する
+ *     (規約文面の所在は 1 ファイルに固定されている前提)
  *
  * 検出方式は LegalConsentVersionSingleSourceTest と同じ token 走査
- * (regex にすると本ファイルの説明コメント自身で偽赤になる)。DB 不使用。
+ * (regex にすると本ファイルの説明コメント自身で偽赤になる)。blade は
+ * `Blade::compileString()` で PHP へ落としてから token 走査する
+ * (`{{ ... }}` は素の PHP ではないため token_get_all では見えない)。DB 不使用。
  */
 
 /** 設定キー: SSOT だけが読んでよい。 */
@@ -45,39 +67,132 @@
 /** 単一出典クラス (repo ルート相対)。 */
 const BILLING_RETENTION_SOURCE_FILE = 'app/Support/Legal/BillingRetention.php';
 
+/** 規約文面 (repo ルート相対)。保持年数を宣言する唯一の view。 */
+const BILLING_RETENTION_PRIVACY_VIEW = 'resources/views/legal/privacy.blade.php';
+
 /** オーナー決定の保持年数 (逸脱不可。変更は規約文面の変更と同義)。 */
 const BILLING_RETENTION_OWNER_DECIDED_YEARS = 7;
 
+/** 検査 1 (config 直読) の走査対象。tests/ は含めない (docblock の「保証しないもの」参照)。 */
+const BILLING_RETENTION_CONFIG_SCAN_DIRS = ['app', 'config', 'database', 'routes', 'resources/views'];
+
+/** 検査 6 (呼び出し元目録) の走査対象。目録は tests/ も母集団に含む。 */
+const BILLING_RETENTION_CALLER_SCAN_DIRS = ['app', 'config', 'database', 'routes', 'resources/views', 'tests'];
+
+/**
+ * 検査 6 の exact-fit inventory: BillingRetention::years() / ::threshold() を
+ * 呼んでよい repo ルート相対パス。**allowlist ではない** — 増えても減っても fail する。
+ * 保持年数に新しく依存する経路を足すときはここへ登録すること (= レビューの目に必ず入る)。
+ *
+ * 本 gate ファイル自身も検査 3 で years() を呼ぶため目録に載せている
+ * (隠れた除外を作らず、exact-fit を文字通りにするため)。
+ *
+ * @var list<string>
+ */
+const BILLING_RETENTION_CALLERS = [
+    'app/Console/Commands/Billing/PurgeBillingRetentionCommand.php',
+    'resources/views/legal/privacy.blade.php',
+    'tests/Architecture/BillingRetentionConfigSingleSourceTest.php',
+    'tests/Feature/Billing/BillingRetentionHorizonTest.php',
+    'tests/Feature/Billing/BillingRetentionPurgeTest.php',
+    'tests/Feature/Billing/TicketLedgerCarryForwardTest.php',
+    'tests/Feature/Legal/PrivacyRetentionDeclarationTest.php',
+];
+
+/**
+ * 空白・コメントを飛ばして次の意味のあるトークン位置を返す。
+ *
+ * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
+ */
+function billingRetentionNextMeaningful(array $tokens, int $index): ?int
+{
+    $count = count($tokens);
+    for ($i = $index + 1; $i < $count; $i++) {
+        $token = $tokens[$i];
+        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+            continue;
+        }
+
+        return $i;
+    }
+
+    return null;
+}
+
 /**
- * 1 ソースを走査して出現数を返す (純関数 = 負のコントロールから直接呼べる)。
+ * 1 ソース (素の PHP) を走査して出現数を返す (純関数 = 負のコントロールから直接呼べる)。
  *
- * @return array{configKey: int, tokens: int}
+ * @return array{configKey: int, ssotCall: int, tokens: int}
  */
 function billingRetentionScanSource(string $source): array
 {
-    $result = ['configKey' => 0, 'tokens' => 0];
+    $tokens = token_get_all($source);
+    $count = count($tokens);
+    $result = ['configKey' => 0, 'ssotCall' => 0, 'tokens' => 0];
 
-    foreach (token_get_all($source) as $token) {
+    for ($i = 0; $i < $count; $i++) {
+        $token = $tokens[$i];
         if (! is_array($token)) {
             continue;
         }
         $result['tokens']++;
-        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
+        [$id, $value] = $token;
+
+        if ($id === T_CONSTANT_ENCAPSED_STRING) {
+            if (trim($value, "'\"") === BILLING_RETENTION_CONFIG_KEY) {
+                $result['configKey']++;
+            }
+
+            continue;
+        }
+
+        // BillingRetention::years() / ::threshold() (部分修飾・完全修飾を問わない)
+        if ($id !== T_STRING && $id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
             continue;
         }
-        if (trim($token[1], "'\"") === BILLING_RETENTION_CONFIG_KEY) {
-            $result['configKey']++;
+        $segments = explode('\\', $value);
+        if (end($segments) !== 'BillingRetention') {
+            continue;
+        }
+        $doubleColon = billingRetentionNextMeaningful($tokens, $i);
+        if ($doubleColon === null
+            || ! is_array($tokens[$doubleColon])
+            || $tokens[$doubleColon][0] !== T_DOUBLE_COLON) {
+            continue; // `use App\Support\Legal\BillingRetention;` 等は呼び出しではない
+        }
+        $method = billingRetentionNextMeaningful($tokens, $doubleColon);
+        if ($method !== null
+            && is_array($tokens[$method])
+            && $tokens[$method][0] === T_STRING
+            && in_array($tokens[$method][1], ['years', 'threshold'], true)) {
+            $result['ssotCall']++;
         }
     }
 
     return $result;
 }
 
+/**
+ * 走査用に PHP ソースを取り出す。blade は `{{ ... }}` が素の PHP ではないため
+ * `Blade::compileString()` で PHP へ落としてから走査する。
+ */
+function billingRetentionSourceForScan(string $absolutePath): ?string
+{
+    $source = file_get_contents($absolutePath);
+    if (! is_string($source)) {
+        return null;
+    }
+
+    return str_ends_with($absolutePath, '.blade.php')
+        ? Blade::compileString($source)
+        : $source;
+}
+
 /**
  * repo ルート相対パス => 走査結果。
  *
  * @param  list<string>  $dirs
- * @return array<string, array{configKey: int, tokens: int}>
+ * @return array<string, array{configKey: int, ssotCall: int, tokens: int}>
  */
 function billingRetentionScanTree(array $dirs): array
 {
@@ -96,8 +211,8 @@ function billingRetentionScanTree(array $dirs): array
             if (! is_string($absolute)) {
                 continue;
             }
-            $source = file_get_contents($absolute);
-            if (! is_string($source)) {
+            $source = billingRetentionSourceForScan($absolute);
+            if ($source === null) {
                 continue;
             }
             $scanned[substr($absolute, strlen($root) + 1)] = billingRetentionScanSource($source);
@@ -155,9 +270,78 @@ function billingRetentionConfigLiteral(): ?int
     return null;
 }
 
+/**
+ * 整数を漢数字へ変換する (1〜99 のみ。範囲外は null)。
+ *
+ * 「七年」のような表記の literal を検出するために使う。
+ */
+function billingRetentionKanjiNumeral(int $value): ?string
+{
+    if ($value < 1 || $value > 99) {
+        return null;
+    }
+
+    $digits = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
+
+    if ($value < 10) {
+        return $digits[$value];
+    }
+
+    $tens = intdiv($value, 10);
+    $ones = $value % 10;
+
+    return ($tens > 1 ? $digits[$tens] : '').'十'.($ones > 0 ? $digits[$ones] : '');
+}
+
+/**
+ * 年数を「N 年」の形で書いた散文 literal を blade の**生ソース**から探す。
+ *
+ * ASCII 数字 / 全角数字 / 漢数字の 3 表記に対応し、数字と「年」の間の空白は許容する。
+ * 生ソースを見るのは、`{{ ... }}` の中身 (= SSOT 呼び出し) には数字が現れないためで、
+ * 逆に literal を書けば必ず「N 年」の形で現れるという文面側の性質を利用している。
+ *
+ * @return list<string> 検出した表記 (空なら違反なし)
+ */
+function billingRetentionProseYearLiterals(string $rawSource, int $years): array
+{
+    $needles = [
+        (string) $years,
+        mb_convert_kana((string) $years, 'N'),
+    ];
+    $kanji = billingRetentionKanjiNumeral($years);
+    if ($kanji !== null) {
+        $needles[] = $kanji;
+    }
+
+    $hits = [];
+    foreach (array_unique($needles) as $needle) {
+        if (preg_match('/'.preg_quote($needle, '/').'\s*年/u', $rawSource) === 1) {
+            $hits[] = $needle.'年';
+        }
+    }
+
+    return $hits;
+}
+
+/**
+ * compile 済み blade の PHP コード側に年数の整数リテラルが現れるかを見る
+ * (`@php $y = 7; @endphp` のような迂回を塞ぐ)。
+ */
+function billingRetentionCodeYearLiteralCount(string $compiledSource, int $years): int
+{
+    $count = 0;
+    foreach (token_get_all($compiledSource) as $token) {
+        if (is_array($token) && $token[0] === T_LNUMBER && (int) $token[1] === $years) {
+            $count++;
+        }
+    }
+
+    return $count;
+}
+
 test('検査 1: 保持年数の config キーを読むのは BillingRetention だけである', function (): void {
     $violations = [];
-    foreach (billingRetentionScanTree(['app', 'config', 'database', 'routes']) as $relative => $scan) {
+    foreach (billingRetentionScanTree(BILLING_RETENTION_CONFIG_SCAN_DIRS) as $relative => $scan) {
         if ($scan['configKey'] > 0 && $relative !== BILLING_RETENTION_SOURCE_FILE) {
             $violations[] = $relative;
         }
@@ -186,13 +370,17 @@ function billingRetentionConfigLiteral(): ?int
 });
 
 test('検査 4: 空振り検知と正の自己検証', function (): void {
-    $scanned = billingRetentionScanTree(['app', 'config', 'database', 'routes']);
+    $scanned = billingRetentionScanTree(BILLING_RETENTION_CONFIG_SCAN_DIRS);
 
     expect(count($scanned))->toBeGreaterThan(0);
     expect(array_sum(array_column($scanned, 'tokens')))->toBeGreaterThan(0);
 
     // 検出器が死んでいたら検査 1 は vacuous green になる。SSOT では必ず 1 件点灯する。
     expect($scanned[BILLING_RETENTION_SOURCE_FILE]['configKey'])->toBe(1);
+
+    // blade も母集団に入っている (compile が空振りして走査対象から落ちていない)
+    expect($scanned)->toHaveKey(BILLING_RETENTION_PRIVACY_VIEW);
+    expect($scanned[BILLING_RETENTION_PRIVACY_VIEW]['tokens'])->toBeGreaterThan(0);
 });
 
 test('検査 5: 負のコントロール (リテラルは検出し、コメント中の表記は検出しない)', function (): void {
@@ -221,3 +409,86 @@ public function run(): void {}
     expect(billingRetentionScanSource($comment)['configKey'])->toBe(0);
     expect(billingRetentionScanSource($comment)['tokens'])->toBeGreaterThan(0);
 });
+
+test('検査 6: BillingRetention::years()/::threshold() の呼び出し元が目録と exact-fit である', function (): void {
+    $callers = [];
+    foreach (billingRetentionScanTree(BILLING_RETENTION_CALLER_SCAN_DIRS) as $relative => $scan) {
+        if ($scan['ssotCall'] > 0 && $relative !== BILLING_RETENTION_SOURCE_FILE) {
+            $callers[] = $relative;
+        }
+    }
+    sort($callers);
+
+    expect($callers)->toBe(BILLING_RETENTION_CALLERS,
+        '保持年数 (BillingRetention::years() / ::threshold()) の依存元が増減しました。'
+        .'新しい経路なら BILLING_RETENTION_CALLERS へ登録し、消えたなら目録から外してください '
+        .'(allowlist ではなく exact-fit の目録です)。実測: '.PHP_EOL.implode(PHP_EOL, $callers));
+});
+
+test('検査 7: privacy blade が年数の literal を持たず SSOT から描画している', function (): void {
+    $raw = file_get_contents(base_path(BILLING_RETENTION_PRIVACY_VIEW));
+    expect($raw)->toBeString();
+    $raw = (string) $raw;
+
+    $years = BillingRetention::years();
+
+    // 正の自己検証: SSOT 呼び出しがちょうど 1 回ある (0 なら文面が数値を失っている)
+    $compiled = Blade::compileString($raw);
+    expect(billingRetentionScanSource($compiled)['ssotCall'])->toBe(1,
+        '/privacy の保持年数は App\Support\Legal\BillingRetention::years() から'
+        .'ちょうど 1 回描画してください。');
+
+    // 散文側の literal ("7年" / "７ 年" / "七年")
+    expect(billingRetentionProseYearLiterals($raw, $years))->toBe([],
+        '/privacy の文面に保持年数の literal を検出しました。config / SSOT / 文面の'
+        .'三者一致が壊れるため、必ず BillingRetention::years() から描画してください。');
+
+    // コード側の literal (@php ブロック等の迂回)
+    expect(billingRetentionCodeYearLiteralCount($compiled, $years))->toBe(0,
+        '/privacy の blade コード側に保持年数と同じ整数リテラルを検出しました。');
+});
+
+test('検査 8: 負のコントロール (呼び出し / 年数 literal の検出器が実際に点灯する)', function (): void {
+    // 呼び出しは検出し、use 文だけは呼び出しに数えない
+    $called = <<<'PHP'
+    <?php
+    use App\Support\Legal\BillingRetention;
+    class Fixture {
+        public function run(): void {
+            $a = BillingRetention::years();
+            $b = \App\Support\Legal\BillingRetention::threshold();
+        }
+    }
+    PHP;
+
+    $importOnly = <<<'PHP'
+    <?php
+    use App\Support\Legal\BillingRetention;
+    class Fixture {
+        public function run(BillingRetention $retention): void {}
+    }
+    PHP;
+
+    expect(billingRetentionScanSource($called)['ssotCall'])->toBe(2);
+    expect(billingRetentionScanSource($importOnly)['ssotCall'])->toBe(0);
+
+    // 散文 literal: 3 表記すべてを検出し、SSOT 呼び出しの形は検出しない
+    expect(billingRetentionProseYearLiterals('最長 7 年間', 7))->toBe(['7年']);
+    expect(billingRetentionProseYearLiterals('最長７年間', 7))->toBe(['７年']);
+    expect(billingRetentionProseYearLiterals('最長七年間', 7))->toBe(['七年']);
+    expect(billingRetentionProseYearLiterals('最長{{ Foo::years() }}年間', 7))->toBe([]);
+    // 年数と無関係な数字は拾わない (見出し番号の繰り下げで偽赤にしない)
+    expect(billingRetentionProseYearLiterals('<h2>7. その他</h2>', 7))->toBe([]);
+
+    // 漢数字変換 (1〜99 と範囲外)
+    expect(billingRetentionKanjiNumeral(7))->toBe('七');
+    expect(billingRetentionKanjiNumeral(10))->toBe('十');
+    expect(billingRetentionKanjiNumeral(25))->toBe('二十五');
+    expect(billingRetentionKanjiNumeral(100))->toBeNull();
+
+    // コード側 literal: @php ブロックの迂回を検出する
+    // (`@endphp` の直後に `{{` を置くと Blade の `@{{` エスケープ記法と衝突するため改行を挟む)
+    $bladeWithPhp = Blade::compileString("@php\n\$years = 7;\n@endphp\n{{ \$years }}年間");
+    expect(billingRetentionCodeYearLiteralCount($bladeWithPhp, 7))->toBe(1);
+    expect(billingRetentionCodeYearLiteralCount(Blade::compileString('{{ Foo::years() }}年間'), 7))->toBe(0);
+});
diff --git a/tests/Feature/Legal/PrivacyRetentionDeclarationTest.php b/tests/Feature/Legal/PrivacyRetentionDeclarationTest.php
new file mode 100644
index 0000000..e134c9c
--- /dev/null
+++ b/tests/Feature/Legal/PrivacyRetentionDeclarationTest.php
@@ -0,0 +1,132 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Legal\BillingRetention;
+use Dom\HTMLDocument;
+
+/*
+ * /privacy が宣言する「課金取引記録の保有期間」の **behavioral** 検査
+ * (SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C3 / C3b)。
+ *
+ * 背景: 保持年数は config/legal.php -> App\Support\Legal\BillingRetention -> 規約文面 の
+ * 三者が一致していなければならない。静的 gate
+ * (tests/Architecture/BillingRetentionConfigSingleSourceTest.php) は「blade が literal を
+ * 持たないこと」までしか見られないので、**実際に描画された HTML** の側からもう一度
+ * 固定する。節ごと消えた場合も、数字だけ別の文脈に残った場合も、ここで赤くなる。
+ *
+ * ★このテストが保証するもの:
+ *   (a) data-legal-retention="billing-records" のマーカー要素が実在する
+ *   (b) 保有期間の**節見出し** (id="retention") が実在する
+ *   (c) 家系の先例由来の固定文言「取引関係書類等」がページ内に実在する
+ *   (d) **マーカー要素の内側に** config 由来の年数が現れる
+ *   (e) config の値を変えると描画も追随する (= literal ではなく SSOT 由来である)
+ *
+ * ★このテストが保証しないもの (誇張しない):
+ *   - 文面の日本語が法的に正しいか / 年数が法令上妥当か (**法務レビューの仕事**。
+ *     現在の文面は法務レビュー前の**草案**である)
+ *   - 散文の意味と実処理 (purge バッチ) の一致。機械が見るのは数値 1 つ・マーカーの
+ *     存在・固定文言 1 語だけである
+ *   - purge 対象テーブルの網羅性 (BillingRetentionTargetInventoryTest の担当)
+ *   - 「文面が変わったのに consent_version が上がっていない」こと
+ *     (本タスクでは版を draft-1 から動かさないため、そもそも検査対象にしない)
+ *
+ * **見出し番号 (「4.」等) では照合しない**。節の並べ替え・番号の繰り下げは文面の意味を
+ * 変えないため、属性 (data-legal-retention / id) と固定文言で照合する。
+ */
+
+/** 保有期間を宣言するマーカー要素の属性値。 */
+const PRIVACY_RETENTION_MARKER_VALUE = 'billing-records';
+
+/** 節見出しの id (番号ではなくこれで照合する)。 */
+const PRIVACY_RETENTION_HEADING_ID = 'retention';
+
+/** 家系の先例 (spirux の /privacy) 由来の固定文言。 */
+const PRIVACY_RETENTION_FIXED_PHRASE = '取引関係書類等';
+
+/** マーカー要素のテキスト内容を取り出す (無ければ null)。 */
+function privacyRetentionMarkerText(string $html): ?string
+{
+    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
+    $nodes = $document->querySelectorAll('[data-legal-retention="'.PRIVACY_RETENTION_MARKER_VALUE.'"]');
+
+    if ($nodes->length !== 1) {
+        return null;
+    }
+
+    $node = $nodes->item(0);
+
+    return $node?->textContent;
+}
+
+/** 保有期間の節見出しのテキストを取り出す (無ければ null)。 */
+function privacyRetentionHeadingText(string $html): ?string
+{
+    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
+    $node = $document->getElementById(PRIVACY_RETENTION_HEADING_ID);
+
+    return $node?->textContent;
+}
+
+it('(a) /privacy が保有期間のマーカー要素をちょうど 1 つ持つ', function (): void {
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    expect(privacyRetentionMarkerText((string) $response->getContent()))->not->toBeNull(
+        'data-legal-retention="'.PRIVACY_RETENTION_MARKER_VALUE.'" の要素が /privacy に '
+        .'ちょうど 1 つ存在しません。保有期間の宣言はこのマーカーで機械照合しています '
+        .'(見出し番号では照合しない)。resources/views/legal/privacy.blade.php を確認してください。');
+});
+
+it('(b) /privacy が保有期間の節見出しを持つ', function (): void {
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    $heading = privacyRetentionHeadingText((string) $response->getContent());
+
+    expect($heading)->not->toBeNull(
+        'id="'.PRIVACY_RETENTION_HEADING_ID.'" の節見出しが /privacy にありません。');
+    expect((string) $heading)->toContain('保有期間');
+});
+
+it('(c) /privacy が先例由来の固定文言「取引関係書類等」を持つ', function (): void {
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    // Pest の toContain は可変長 needle を取るため、説明文は toBeTrue 側へ渡す。
+    expect(str_contains((string) $response->getContent(), PRIVACY_RETENTION_FIXED_PHRASE))->toBeTrue(
+        '固定文言「'.PRIVACY_RETENTION_FIXED_PHRASE.'」が /privacy から消えました。'
+        .'この語は家系の先例 (spirux の /privacy) に揃えた文面の要であり、'
+        .'保持年数が「何に対する期間なのか」を特定しています。');
+});
+
+it('(d) マーカー要素の内側に config 由来の年数が現れる', function (): void {
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    $marker = privacyRetentionMarkerText((string) $response->getContent());
+
+    expect($marker)->not->toBeNull();
+    expect(str_contains((string) $marker, (string) BillingRetention::years()))->toBeTrue(
+        '保持年数がマーカー要素の内側にありません。数字だけ別の文脈に移ると '
+        .'「規約が宣言する年数」が機械照合できなくなります。');
+    // 数字が「何の期間なのか」まで含めて 1 要素に収まっていること
+    expect((string) $marker)->toContain(PRIVACY_RETENTION_FIXED_PHRASE);
+});
+
+it('(e) config の保持年数を変えると /privacy の描画も追随する', function (): void {
+    // literal で書かれていたらここが赤くなる (SSOT 由来であることの behavioral 証明)。
+    $mutated = BillingRetention::years() + 3;
+    config()->set('legal.billing_retention_years', $mutated);
+
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    $marker = privacyRetentionMarkerText((string) $response->getContent());
+
+    expect($marker)->not->toBeNull();
+    expect(str_contains((string) $marker, (string) $mutated))->toBeTrue(
+        'config/legal.php の billing_retention_years を変えても /privacy の表示が変わりません。'
+        .'blade に年数の literal が書かれている疑いがあります '
+        .'(App\Support\Legal\BillingRetention::years() から描画してください)。');
+});

```

## テスト結果

- `composer phpstan` … OK (871 files, no errors)
- `composer test` … passed tests=4306 passed=4304 skipped=2 assertions=18526
- `vendor/bin/pint --test` … passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (130 files / 1299 tests) / `pnpm build` … すべて green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 tests) … green
- `composer test:browser` … chromium 19 passed / webkit 19 passed (skipped 3)
- 新規・拡張分だけの実行: `tests/Architecture/BillingRetentionConfigSingleSourceTest.php` +
  `tests/Feature/Legal/PrivacyRetentionDeclarationTest.php` = 13 tests passed

## 補足: 変更していないファイル

- `config/legal.php` … **差分なし** (`consent_version` は `draft-1` のまま / `billing_retention_years` は 7 のまま)
- `resources/js/` / `resources/css/` … 変更なし (DESIGN.md / Atomic Design 観点の対象外。
  変更したのは `resources/views/legal/privacy.blade.php` = Inertia を通らない法的スタブ blade のみで、
  design token / Svelte component 階層には一切触れていない)
