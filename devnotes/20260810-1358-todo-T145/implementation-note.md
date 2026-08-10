# T145 (PR-C3) 実装ノート — 保持期間の規約公開

SoT: `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` の **PR-C3 節** と
`recon-brief.md` 冒頭のオーナー決定。

## 変更点 (極小 PR)

| ファイル | 変更 |
|---|---|
| `resources/views/legal/privacy.blade.php` | 「3. 第三者提供」と「4. 開示・訂正・削除」の間に **`4. 保有期間` (`id="retention"`)** を挿入。年数は `\App\Support\Legal\BillingRetention::years()` から描画。`data-legal-retention="billing-records"` マーカー付き。既存「4. 開示・訂正・削除」を **5.** へ繰り下げ。**法務レビュー前の草案である旨を blade コメントに明記** |
| `tests/Architecture/BillingRetentionConfigSingleSourceTest.php` | 検査 1 の走査対象へ `resources/views` を追加 (blade も config 直読しない)。**検査 6** (`years()`/`threshold()` 呼び出し元の exact-fit 目録。部分修飾 / 完全修飾 / `namespace\` 相対 / `use ... as` alias / group use / 大文字小文字違いをすべて同一視)、**検査 7** (privacy blade に年数 literal が無い / SSOT 呼び出しちょうど 1 回 / **自己参照コントロール = gate 自身は hit 0**)、**検査 8〜11** (検出器・alias 解決・case 正規化・`T_NAME_RELATIVE` の負のコントロール) を追加 |
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
| **alias 迂回** (Codex R1) | 既存 caller を `use ... BillingRetention as Retention;` へ書き換え | **緑のまま** (= alias 解決が効き、目録から消えない)。さらに alias 経由で `years()` を呼ぶファイルを新設すると **検査 6 が赤** (未登録の呼び出し元として検出)。probe は削除済み |
| **自己参照** (Codex R2) | gate ファイルへ `// 説明コメント: 取引関係書類等は最長 7 年間保有する。` を 1 行足す | **検査 7 が赤** (自己参照コントロールが循環していない) |
| **case 迂回** (Codex R3) | `use ... BillingRetention as Retention;` + `retention::YEARS();` を呼ぶファイルを新設 | **検査 6 が赤** (大文字小文字の違いで素通りできない) |
| **namespace 相対** (Codex R4) | `namespace\billingretention::YEARS();` を呼ぶファイルを新設 | **検査 6 が赤** (T_NAME_RELATIVE を母集団に含めている) |

いずれも変異を戻したうえで `git status` が想定どおり (privacy blade / gate / 新規テスト /
architecture.md のみ) であることを確認済み。`config/legal.php` は差分なし。

## Codex 実装レビュー (5 ラウンド)

| Round | 判定 | 内容 |
|---|---|---|
| 1 | APPROVED (Warning 3) | alias import 迂回 / 自己参照コントロール不足 / 年数の部分一致。**すべて検査ファイル内で完結**するため、極小 PR のスコープを広げずにこの PR で対応 |
| 2 | CHANGES_REQUESTED (Critical 1) | 自己参照コントロールが SoT (hit 0 件) と**逆**だった。gate の生ソースから年数表記を一掃し `toBe([])` へ反転。alias 解析を FQCN 厳密一致 + `use function`/`const`・trait adaptation・closure の除外へ書き直し |
| 3 | CHANGES_REQUESTED (Critical 1) | PHP のクラス名 / alias / メソッド名は **case-insensitive** なので比較 4 箇所を正規化。mixed group use の entry 読み飛ばしにも対応 |
| 4 | CHANGES_REQUESTED (Critical 1) | `namespace\BillingRetention::years()` (**T_NAME_RELATIVE**) が母集団から漏れていた。呼び出し側の token 集合を import parser と分けて定義し追加 |
| 5 | **APPROVED** | 指摘なし。`self::` / `parent::` / `static::` は字面で対象クラスを決められないため保証外側に属する、との評価も一致 |

各ラウンドのプロンプト・返答・対応マトリクスは `codex-history/` と `impl-review-round-N.md` に保存。

## 保証しないもの (誇張しない)

- 文面の日本語が法的に正しいか / 7 年が法令上妥当か — **法務レビューの仕事**。本追記は草案。
- 散文の意味と実処理 (purge バッチ) の一致 — 機械が見るのは数値 1 つ・マーカー・固定文言 1 語だけ。
- 「文面が変わったのに `consent_version` が上がっていない」こと — 版を動かさない前提のため対象外。
- 検査 7 の漢数字判定は 1〜99 のみ。privacy blade **以外**の blade の年数 literal には沈黙する。
- 検査 1 の走査に `tests/` は含めない (fail-fast 検証テストが config を書き換えるため)。
- 呼び出し検出は**静的に解決できる直接呼び出し**まで。動的・間接呼び出し
  (`$class::years()` / `call_user_func()` / 可変メソッド / 文字列キーの container 解決)、および
  `self::` / `parent::` / `static::` (字面では対象クラスが決まらない) には沈黙する。
- 呼び出し検出は最終セグメント一致も併用するため、**別 namespace の同名クラスを過検出する**。
  deny-by-default の目録として意図的に過検出側へ倒している (取りこぼしより赤い方が安全)。

## 申し送り

`docs/billing-retention-runbook.md` の「PR-C3 のチェックリスト (必須)」が求める
**初回 `--apply` の出力の証跡** (target 別件数 / `fail_closed` = 0 / `unexpected_failures` = 0 /
`horizon: OK`) は **本番運用の実行結果**であり、本 worktree では取得できない。
**デプロイ時にオーナーが取得して PR/運用記録へ貼ること**。台帳 (lctl) への
`implemented` 報告も、その証跡が揃うまで出さない (設計 §台帳への報告の条件 (b)(c))。
