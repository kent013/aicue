# T136 mutation evidence — 同意バージョン解決点の単一化と gate

詳細設計 `devnotes/20260809-0027-legal-consent-single-source/detailed-design.md` §施策 4
「mutation で赤化を確認する手順（必須）」M1〜M7 の実施記録。

- worktree: `/workspace/.claude/worktrees/tasks/T136` (branch `todo/T136`)
- 実施日: 2026-08-09 (JST)
- 実行コマンド: `composer test -- --filter='LegalConsentVersionSingleSource'`
  (M7 のみ `composer phpstan` + `composer test -- --filter='LegalConsent'`)
- **戻し方**: 全 mutation を Edit で 1 行ずつ手で戻した (`git checkout --` は 1 度も使っていない。
  未コミットの実装差分が同居していたため)。最終確認は `git status --short` / `git diff` で
  mutation の痕跡 0 を確認済み (下記 §戻し確認)。

## テストファースト (実装前の赤)

gate + Unit テストのみを置き、施策 1〜3 を実装する前に 1 回走らせた実測:

```
{"tool":"pest","result":"failed","tests":13,"passed":6,"assertions":35,"failed":5,"errors":2}
```

| 赤くなったもの | 内容 |
|---|---|
| G1 | `app/Actions/Fortify/CreateNewUser.php` / `app/Actions/Inquiry/CreateInquiryAction.php` / `app/Services/Auth/SocialAccountService.php` の 3 本を列挙 |
| G3 | 呼び出し元 0 本 (inventory 3 本と不一致) |
| G4 | `database/factories/InquiryFactory.php` の `'draft-1'` を列挙 |
| 到達境界: 検出器が実ファイルで実際に点灯する | `app/Support/Legal/LegalConsent.php` が不在 (error) |
| Unit `LegalConsentTest` 3 本 | `App\Support\Legal\LegalConsent` not found / Error |

G2 と負のコントロール 3 本は実装前から green (env 口は元から `config/legal.php` 1 箇所のみ、
検出器の fixture 判定は実装非依存)。

## M1〜M7 実測

| # | mutation | 期待 | **実測** | 判定 |
|---|---|---|---|---|
| M1 | `CreateNewUser.php` の `LegalConsent::version()` を `config()->string('legal.consent_version')` に戻す | G1 + G3 が fail | failed 3/10: **G1** (`app/Actions/Fortify/CreateNewUser.php` を列挙) / **G3** (実測 2 本 vs 目録 3 本) / **到達境界: 検出器が実ファイルで実際に点灯する** (目録先頭 = CreateNewUser の versionCall が 0 になるため) | ✅ 期待どおり (設計の予想より 1 本多く赤い = 検出が強い側の差分) |
| M2 | 同じ場所を**二重引用符** `config()->string("legal.consent_version")` にする | G1 が fail (引用符正規化) | failed 3/10: **G1** / G3 / 到達境界 (M1 と同じ組) | ✅ 引用符を問わず検出 |
| M3 | `app/Support/Environment.php::isLocal()` に `\App\Support\Legal\LegalConsent::version();` を 1 行足す | G3 が exact-fit 不一致で fail | failed 1/10: **G3 のみ** (実測 4 本 vs 目録 3 本。完全修飾形を検出) | ✅ 新経路が必ず可視化される |
| M4 | `database/factories/InquiryFactory.php` の値を `'draft-1'` へ戻す | G4 が fail | failed 1/10: **G4 のみ** (`database/factories/InquiryFactory.php` を列挙) | ✅ |
| M5 | `config/legal.php` の `env('LEGAL_CONSENT_VERSION', ...)` を `'draft-1'` の直値へ置換 | G2 の正の側 (`envName === 1`) が fail | failed 1/10: **G2 のみ** (`Failed asserting that 0 is identical to 1`) | ✅ env 口ごと消える退行を検出 |
| M6 | `legalConsentScanSource()` を常に 0 を返すよう殺す | 到達境界(検出器) + 負のコントロール 3 本が fail | failed **6**/10: **G2** / **G3** / **G4** / **到達境界: 検出器が実ファイルで実際に点灯する** / **負のコントロール (引用符)** / **負のコントロール (use 文)** | ✅ G1 だけが vacuous green になったが、他 6 本が確実に捕捉 (G2/G3/G4 は正の側 assertion / exact-fit を持つため空振りできない) |
| M7 | `LegalConsent::version()` の `Assert::stringNotEmpty()` を削除 | PHPStan `return.type` error + Unit「空文字なら例外」が fail | **PHPStan**: `app/Support/Legal/LegalConsent.php:40 Method App\Support\Legal\LegalConsent::version() should return non-empty-string but returns string. (return.type)` → `[ERROR] Found 1 error`。**Unit**: failed 1/13 `it_同意バージョンが空文字なら例外を投げる (空版の証跡を書かせない)` = `Exception "InvalidArgumentException" not thrown.` | ✅ fail-fast は型検査とテストの二重で消せない |

### M6 の補足 (設計の予測との差分)

設計は「G1/G2/G4 は vacuous green になるが、到達境界 + 負のコントロール 3 本が捕まえる」と
予測していた。実測では **G2 / G3 / G4 も赤くなった**:

- G2 は正の側 `expect(...['envName'])->toBe(1)` を持つため 0 で fail
- G3 は exact-fit (目録 3 本 vs 実測 0 本) のため fail
- G4 は除外の空振り検査 `expect(...['placeholder'])->toBeGreaterThan(0)` を持つため fail
- 負のコントロールのうち「コメント / docblock 中の表記は検出しない」だけは 0 期待なので
  **殺した検出器でも green のまま**である (設計が「3 本」と書いたうちの 1 本は
  検出器の死では赤くならない = 正直に記録する)

vacuous green で生き残ったのは **G1 のみ**であり、設計の意図 (空振り green の排除) は満たされている。

## 戻し確認

全 mutation を戻したあとの `git status --short`:

```
 M app/Actions/Fortify/CreateNewUser.php
 M app/Actions/Inquiry/CreateInquiryAction.php
 M app/Services/Auth/SocialAccountService.php
 M database/factories/InquiryFactory.php
?? app/Support/Legal/
?? tests/Architecture/LegalConsentVersionSingleSourceTest.php
?? tests/Unit/Support/Legal/
```

- `config/legal.php` (M5) と `app/Support/Environment.php` (M3) は **status に現れない** =
  完全に元へ戻っている
- `git diff` の内容は施策 2/3 の変更のみ (mutation 由来の行は 0)
- `tests/Architecture/LegalConsentVersionSingleSourceTest.php` (M6) は untracked のため diff に
  現れないので、`legalConsentScanSource()` の本体を実読して mutation 行が無いことを確認済み
