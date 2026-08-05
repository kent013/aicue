# 多角監査レポート (サイクル 2 完了時点)

- 実施: 2026-08-05 15:50 (JST)
- 対象: `4cbdff8..225550b` — T099〜T106 の 8 TODO
- 起点: c2c 台帳 (aicue inbox) の追従タスク 17 + 実装タスク 8 のうち、実態検証で actionable と確定した 17 件
- 観点別レポート: `mission-alignment.md` / `tech-debt.md` / `ui-consistency.md` / `security.md` / `docs-freshness.md`

## 判定サマリー

| 観点 | 判定 | 要対応 |
|------|------|--------|
| 使命整合性 | DRIFT_DETECTED (部分) | T100-B (packages/cli) と T106 passkey 本体が v1 宣言外。構造問題「v1 の残りを追跡する場所が無い」 |
| 技術的負債 | DEBT_FOUND (全ゲート green) | `\R` の `/u` 欠落 (実害発生済み)、孤児 DB 17 個 221.9 MB、トークン解析器 8 本の重複 |
| UI 一貫性 | INCONSISTENCY_FOUND | **Critical**: `passkeyAvailable` 未配線 5 箇所。High: 踏破不能 CTA 2 本 |
| セキュリティ | RISK_FOUND (Critical 0 / High 2) | **存在オラクル残存** (ability が層 2 より前)、`trustProxies(at:'*')` でレート制限無効 |
| ドキュメント鮮度 | STALE_FOUND | bug-hunt inventory drift (exit 3)、AGENTS.md に T103 の不変条件 2 本欠落 |

## 実測値 (サイクル完了時点の main)

| 項目 | 開始時 | 完了時 |
|---|---|---|
| `composer test` | 2704 passed | **2865 passed** / 0 failed / 2 skipped |
| `pnpm test` | 968 passed | **1130 passed** (119 files) |
| `pnpm test:packages` | 56 passed (CI 未実行) | **106 passed** (CI 実行) |
| PHPStan level 10 | No errors | No errors (779 files) |
| `audit:gate` | fail (26 advisories / high 15) | **PASS** (moderate 4 のみ / accept-risk 0) |
| Architecture gate | 43 本 | **71 本** (12,139 行) |
| CI job | php / frontend の 2 本 | php(pgsql) / frontend / browser-tests / supply-chain-audit |

## サイクルの成果と、それを疑う視点

### 成果として確からしいもの

- **CI が初めて実効化された**。T104 の実査で「CI の PHP テスト 0 件 / Browser テスト 0 件」= 既存回帰網が丸ごと偽グリーンだったことが判明し是正された。これは今サイクル最大の価値
- **各ゲートが空振りでなく実違反を捕捉している**。非複合 use 2 件 / タイトル欠落 4 route / Carbon 8 箇所 / 認可漏れ 3 route + 存在オラクル 4 ケース
- **gate の品質が高い**。逆方向 stale 検出・空振り下限ガード・正/負コントロールが揃っており、形骸化の兆候なし
- **supply-chain が accept-risk 0 件で緑になった**。逃げ道を用意したうえで使わずに済んだ

### 疑うべきもの

- **使命への直接の差分は実質 25 行**。8 TODO / 約 2.7 万行の変更に対し、SOP → シナリオ → 撮影というプロダクト中核はほぼ動いていない
- **T100-B (packages/cli の profile:delete)**: `branding.ts:15` が `APP_SLUG = "app"`、`package.json` が `@app/cli` のままで一度も aicue 化されておらず配布もされていない足場に約 1,900 行を投資。設計の論拠「本番 API キーを消せない」は配布されていない以上成立しない
- **T106 passkey 本体**: 設計自身が「passkey route は 1 本も生えていない / 露出なし」と実測済み。着手理由は台帳が `pending` だったこと。v1 宣言 (PWA・セッション認証) が要求した機能ではない
- **devnotes が +121,305 行 (全体の 87%)**。Codex プロンプト全文の恒久堆積は運用コスト

## 監査起因の TODO 候補

### 即時 (次サイクルで着手)

| 優先度 | 内容 | 出典 |
|---|---|---|
| Critical | `RecentAuthModal` の `passkeyAvailable` 未配線 5 箇所 (passkey-only ユーザーが 5 画面で詰む) | ui |
| High | 存在オラクル残存 — `api.project-in-org` を ability より前へ | security |
| High | `trustProxies(at:'*')` でレート制限が総当りに無効 | security |
| High | 踏破不能 CTA 2 本 / パスワード未設定ユーザーの設定経路が存在しない | ui |
| High | `preg_split('/\R/')` の `/u` 欠落 (実害発生済み: 380→454 行の偽分割) | tech-debt |
| High | bug-hunt inventory に passkey route 7 本未追記 (exit 3 を実測) | docs |

### 中期

| 優先度 | 内容 | 出典 |
|---|---|---|
| Medium | passkey の登録/削除が `SecurityAuditEvent` に残らない + `passkey.destroy` の throttle 欠落 | security |
| Medium | 孤児テスト DB 17 個 221.9 MB (今サイクルも新規発生 = teardown 不全継続) | tech-debt |
| Medium | `doc/reference/` の NFC/NFD 重複 (孤児 DB 掃除の前提) | tech-debt |
| Medium | AGENTS.md に T103 の新セキュリティ不変条件 2 本が未反映 + 軽微 4 件 | docs |
| Medium | advisory 4 件の upgrade (undici は caret 範囲内、valibot は plugin pin 上げ) | tech-debt |
| Low | `global-test-lock.sh` の pgid 取得 race (偽赤のみ) | tech-debt |
| Low | 自前 PHP トークン解析器 8 本の共通基盤化 (`token_get_all` 系と `PhpToken` 系の API 分裂) | tech-debt |

## 最優先の構造課題

**v1 の残りを追跡する場所が無く、`docs/TODO.md` が空になると自動的に c2c 台帳へ吸い寄せられる。**

今サイクルがまさにそれだった。開始時の Open は実機必須の T085 一件のみで、プロダクト作業が枯渇していたため
起点として c2c inbox を選んだ。台帳追従は「やるべきこと」ではあっても「今やるべき最優先」とは限らない。

3 観点 (使命整合性・技術的負債・ドキュメント鮮度) が独立に同じ問題を指摘している:
既知事項が devnotes に埋もれて `docs/TODO.md` に上がらないため、次に何をやるべきかの判断材料が
台帳しか残らない構造になっている。

**次サイクルの提案**: 監査起因 TODO の消化と並行して、v1 スコープの残りを `docs/TODO.md` に
可視化すること (ロードマップ文書か Conditional セクションの活用)。
