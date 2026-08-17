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

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (解析対象に config/ を含む)
- Pest テストフレームワーク (RefreshDatabase をグローバル適用・--parallel 実行)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- パスキーは laravel/fortify 1.37 + laravel/passkeys 0.2 系の公式統合

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性。**正規表現の網羅性と誤検出**を特に見ること）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert 使用）
4. テスト計画の網羅性（各施策に Pest テスト）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク（**登録済みパスキーが無効化される経路が生まれていないか**）
8. 波及変更の網羅性
9. セキュリティ（AGENTS.md のセキュリティ不変条件。設定の fail-fast、例外文からの情報露出）
10. DESIGN.md 準拠 / 11. Atomic Design 準拠 (本件は UI 変更を含まないため該当しない見込み)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: パスキー境界ハードニング 4 施策の未達分の完遂 (aicue:T216)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

本設計の位置づけ: 教材設計そのものではなく、**現場作業者が撮影 PWA へログインし続けられること
(認証手段の可用性と継続性)** を守る基盤である。

### 禁止事項（AGENTS.md より転記）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本設計は 1・2 に直接掛かる (全施策にテストを割り当てる / 型を緩めない)。
> 4〜8 は該当しない (UI / LLM / 応答形式を触らない)。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`。解析対象に **`config/` を含む**）
- **Pest**（`composer test`）。**RefreshDatabase** + `--parallel`（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成
- `declare(strict_types=1)` + 日本語コメント
- コードフォーマット: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260817-1309-todo-t216-passkey-hardening-completion/conceptual-design.md`
  (Codex 概念設計レビュー Round 2 で APPROVED)

## 目的と台帳の根拠

- 台帳 (lctl) の feature: **`auth-passkey-hardening`** (パスキー境界ハードニング。
  成文化は spirux:T1108、発祥は aigenba)。aicue セルは **status: pending**、
  観測点 aicue@bac558f (領域深掘り 2026-08-16 auth 2 周目) で未達 3 点が実読確認されている。
- **裁定 2026-08-04**: 「fortify 1.37 に全員整合。施策 2〜4 と削除処理の非原子性の固定は
  1.37 でも全て必要。**許可する接続元の末尾スラッシュは正規化受理で統一する**」
  (整合差分 8 件は analysis の §5)。本設計の施策 B は、この裁定のうち
  aicue が逆を向いている点 (末尾スラッシュの拒否) を是正するものである。
- 直近の aicue 側の作業: **aicue:T166** (aicue@4f19a90 / aicue@dfd3712) が施策 1 の一部と
  施策 2 の前半を入れた。台帳はその後の巡回で「施策 1 が充足した」という記述を**取り消して**おり、
  固定されているのは `laravel/passkeys` の版だけで `laravel/fortify` の版を見る式は無い、
  と再確認している。設計は `devnotes/20260815-1111-passkey-config-hardening/`。
- 本設計が閉じる未達: **施策 1**（`laravel/fortify` の版の固定）/ **施策 2 の残差**
  （裁定整合と例外文の生値除去）/ **施策 3b**（パッケージ側の削除処理への対応）。
  施策 3a と施策 4 は実装済みのため触らない。
- 台帳への書き込み (`append_event`) は本設計では**行わない**。実装完了後の報告は実装タスクの責務。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | `laravel/fortify` の版の固定 (台帳施策 1) | `tests/Architecture/PasskeyPackageContractTest.php` / `docs/auth-security-mechanisms.md` | High |
| B | 許可する接続元の正規化と裁定整合・例外文の生値除去 (台帳施策 2) | `app/Support/PasskeyOriginCanonicalizer.php` (新規) / `config/fortify.php` / `app/Support/PasskeyConfigValidator.php` / `tests/Unit/Support/PasskeyOriginCanonicalizerTest.php` (新規) / `tests/Unit/Support/PasskeyConfigValidatorTest.php` / `tests/Architecture/PasskeyPackageContractTest.php` / `.env.example` / `docs/auth-security-mechanisms.md` | High |
| C | パッケージ側の削除処理への対応 (台帳施策 3b) | `tests/Architecture/PasskeyPackageContractTest.php` / `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php` (新規) / `docs/auth-security-mechanisms.md` | High |
| D | 逸脱の登録 (検証点が正典と違うことの記録) | `docs/template-divergence.md` / `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` | Medium |

---

## 施策 A: `laravel/fortify` の版の固定

### 変更箇所

- ファイル: `tests/Architecture/PasskeyPackageContractTest.php` (L212-299 の版固定ブロックの末尾に 2 本追加)
- ファイル: `docs/auth-security-mechanisms.md` (§5 運用上の注意の「版 pin の対象」の記述)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 上記契約検査 1 本のみ (既存テストの削除・上書きはしない)
- **ドキュメント: 必須**。現行 `docs/auth-security-mechanisms.md` §5 は
  「版 pin が対象にするのは `laravel/passkeys` **だけ**である
  (`laravel/fortify` は 1.x の semver 管理なので minor pin を足さない)」と書いており、
  本施策と**正面から矛盾する**。同じ変更で書き換える。

### 現行コード

```php
// tests/Architecture/PasskeyPackageContractTest.php (既存。laravel/passkeys だけを見ている)
test('composer.json が laravel/passkeys を直接要求する (直接 import しているため)', function (): void {
    $require = composerRequireBlock();
    expect(array_key_exists('laravel/passkeys', $require))->toBeTrue(/* … */);
    $constraint = $require['laravel/passkeys'];
    expect($constraint)->toBeString();
    /** @var string $constraint */
    expect(preg_match('/^\^0\.2(?:\.\d+)?$/', $constraint))->toBe(1, /* … */);
});

test('composer.lock の laravel/passkeys が 0.2 系 (契約検査の検証済み範囲)', function (): void {
    $version = lockedPackageVersion('laravel/passkeys');
    expect($version)->toBeString('composer.lock に laravel/passkeys が無い');
    /** @var string $version */
    expect(str_starts_with(ltrim($version, 'v'), '0.2.'))->toBeTrue(/* … */);
});
```

実測 (2026-08-17 時点): `composer.json` は `"laravel/fortify": "^1.37"`、
`composer.lock` の解決値は `v1.37.2`。**制約も解決値も既に正しい**が、
それを固定する式が無いため退行を検出できない。

### 変更後コード

```php
/*
 * 版の固定 (下限側)。laravel/passkeys は laravel/fortify の推移依存であり、
 * **パスキーの公式統合が入ったのは fortify 1.37 である**。1.37 未満へ退行すると
 * `Features::passkeys()` という有効化点そのものが消え、本ファイルの他の契約検査
 * (route 名 7 本 / 写像 sentinel / 実効キー) が母集団を失う。
 * したがって「上限を締める」ためではなく「**退行を遮断する**」ために固定する。
 *
 * 解決値まで見るのは、制約だけでは lock が手で書き換えられた場合を捕まえられないため
 * (laravel/passkeys 側と同じ理由・同じ規約形)。
 * 1.37 系を外れるとき (minor 更新 / 脆弱性対応の版上げ) は、
 * FortifyServiceProvider::configurePasskeys() の写像と fortify.passkeys.* のキー名を
 * 再確認してから、同じ変更でこの固定値を直すこと。
 */
test('composer.json の laravel/fortify が 1.37 系を下限に固定している', function (): void {
    $require = composerRequireBlock();

    expect(array_key_exists('laravel/fortify', $require))->toBeTrue(
        'laravel/fortify の直接要求が無い。パスキーの公式統合はこのパッケージが供給する'
    );

    $constraint = $require['laravel/fortify'];
    expect($constraint)->toBeString();
    /** @var string $constraint */
    // laravel/passkeys 側と同じ規約形。前方一致では `^1.37 || ^2.0` のような
    // 「下限を実質無効化する書き方」を通してしまうため、書き方を 1 種類へ絞る。
    expect(preg_match('/^\^1\.37(?:\.\d+)?$/', $constraint))->toBe(
        1,
        "laravel/fortify の制約は '^1.37' か '^1.37.<patch>' の形だけを許す: {$constraint}"
    );
});

test('composer.lock の laravel/fortify が 1.37 系 (公式パスキー統合が入った系列)', function (): void {
    $version = lockedPackageVersion('laravel/fortify');

    expect($version)->toBeString('composer.lock に laravel/fortify が無い');
    /** @var string $version */
    expect(str_starts_with(ltrim($version, 'v'), '1.37.'))->toBeTrue(
        "laravel/fortify の解決版が 1.37 系を外れている: {$version}。"
        .'configurePasskeys() の写像と fortify.passkeys.* のキー名を再確認してから固定値を更新すること'
    );
});
```

ドキュメント側 (`docs/auth-security-mechanisms.md` §5) は次の内容へ差し替える:

> キー名は `laravel/fortify` / `laravel/passkeys` の契約であり、変わると宣言は
> **無言で効かなくなり既定へ戻る**。版の固定は **2 つのパッケージの両方**を対象にする —
> `laravel/passkeys` は 0.x で後方互換の保証が無いため 0.2 系へ、
> `laravel/fortify` は**公式パスキー統合が入った 1.37 系**へ固定する
> (1.37 未満への退行は `Features::passkeys()` という有効化点そのものを消す)。
> どちらも `composer.json` の制約と `composer.lock` の解決値の 2 面を見る。
> Fortify 側の写像は `PasskeyPackageContractTest` の実効値の契約テストが守る。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (追加するのはテストのみ。ヘルパは既存の
      `composerRequireBlock(): array` / `lockedPackageVersion(string): ?string` を再利用)
- [x] null 安全: `lockedPackageVersion()` は `?string` を返すので `toBeString()` の後に
      `/** @var string */` で絞る (既存 2 本と同じ作法)
- [x] DTO を返している: 該当なし (テスト)
- [x] Generics: 該当なし

### テスト計画

- [x] 新規テスト 2 本 (上記)。**先に赤くする方法**: 一時的に正規表現を `^\^1\.38$` に、
      解決値の期待を `1.38.` にして赤を確認してから正しい値へ戻す
      (現行の composer.json / composer.lock は既に 1.37 系のため、そのままでは緑になる)。
      これは「検査が母集団を持っていること」の負のコントロールであり、
      **赤を見た記録を実装ログに残す**
- [x] 既存テストの更新: なし (追加のみ)
- [x] `DatabaseTransactions` は使わない (Architecture レーン)

### リスク

- **fortify の minor 更新で赤くなる**。意図した挙動 (契約検査の前提を読み直す契機) だが、
  依存の脆弱性対応で急ぎ版を上げるときに 1 手増える。緩和として、失敗メッセージに
  「何を再確認してから固定値を更新するか」を書く。
- 供給網の運用 (`pnpm run audit:gate`) と衝突しない: gate は advisory を見るだけで、
  版の固定検査は `composer test` 側にある。上げるときは同じ変更で固定値を直す。

---

## 施策 B: 許可する接続元の正規化と裁定整合・例外文の生値除去

### 変更箇所

- 新規: `app/Support/PasskeyOriginCanonicalizer.php`
- ファイル: `config/fortify.php` (L28-56 の宣言ブロック)
- ファイル: `app/Support/PasskeyConfigValidator.php` (L61-176 の検証本体と docblock)
- 新規: `tests/Unit/Support/PasskeyOriginCanonicalizerTest.php`
- ファイル: `tests/Unit/Support/PasskeyConfigValidatorTest.php` (期待の更新と追加)
- ファイル: `tests/Architecture/PasskeyPackageContractTest.php` (実効値が正規形である検査を 1 本追加)
- ファイル: `.env.example` (許可する接続元の書き方の注記)
- ファイル: `docs/auth-security-mechanisms.md` (§5 運用上の注意)

### 波及変更

- TypeScript 型定義: **なし** (`resources/js/lib/passkeys.ts` は origin 設定を読まない。
  ブラウザは自分の origin を申告するだけで、サーバ側設定は transport に現れない)
- API Resource/DTO: なし
- Inertia Props: なし
- テストファイル: 上記 3 本 (新規 1・更新 2)
- 設定の実効値の変化: `passkeys.allowed_origins` の要素が正規形になる
  (末尾スラッシュの除去 / 既定 port の除去)。読み手は `webauthn-lib` のみ

### 現行コード

```php
// config/fortify.php (抜粋)
$derivedOrigin = ($appUrlScheme !== '' && $appUrlHost !== '')
    ? $appUrlScheme.'://'.$appUrlHost.$appUrlPort
    : '';

$declaredOriginsValue = env('PASSKEYS_ALLOWED_ORIGINS');
$declaredOrigins = is_string($declaredOriginsValue) ? trim($declaredOriginsValue) : '';

$rawAllowedOrigins = $declaredOrigins !== ''
    ? array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $declaredOrigins))
    : [$derivedOrigin];
```

```php
// app/Support/PasskeyConfigValidator.php (抜粋。生値を例外文へ入れている / 既定 port を素通しする)
if (preg_match('#^https://([a-z0-9.-]+)(?::(\d{1,5}))?$#', $origin, $m) !== 1) {
    throw new RuntimeException(sprintf(
        'Passkey allowed origin "%s" is invalid. '
        .'Each origin must be "https://dns-name[:port]" with no path, query or trailing slash. '
        .'Plain http, IPv4/IPv6 literals and bracketed hosts are not accepted in production.',
        $origin,
    ));
}
```

現状の帰結:

- `https://app.example.com/` (末尾スラッシュ) → **本番が起動しない** (裁定と逆)
- `https://app.example.com:443` (既定 port) → **起動するが手続きが全件失敗する**
- 例外文に許可元・身元の識別子の**生値**が入る (配備ログへ焼き付く)

### 変更後コード

#### B-1. 正規化器 (新規)

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * パスキーの「許可する接続元」の正規形を決める唯一の場所。
 *
 * ⚠ **本クラスは接続元だけを扱う**。身元の識別子 (relying party id) には適用しない —
 * パスキーは身元の識別子に束縛されるため、この値を書き換える処理を増やすと
 * **登録済みパスキーが全件使えなくなる**方向の事故を作る。
 *
 * ⚠ **不正な値の修復器ではない**。解釈できない文字列は前後空白の除去と小文字化だけを施して
 * **そのまま返す** (検証器が拒否できる形を保つ)。正規化で不正入力が偶然有効化されてはならない。
 *
 * ⚠ **純粋な静的関数**である (config/fortify.php の評価時に呼ばれるため)。
 * サービスコンテナ解決・入出力・設定の読み出し・例外送出のいずれも行わない。
 *
 * 正規形へ寄せる変形は 3 つだけ:
 *   1. 前後空白の除去と小文字化 (RFC 3986 上 scheme と host は大小文字を区別しない)
 *   2. 根を表す末尾スラッシュ 1 個の除去 (裁定 2026-08-04「末尾スラッシュは正規化受理で統一」)
 *   3. scheme に対応する既定 port の除去 (https は 443 / http は 80)
 *
 * 3 が要る理由: ブラウザが申告する origin は既定 port を含まない。
 * 照合は webauthn-lib の `in_array(..., true)` = **厳密な文字列比較**なので、
 * `https://example.com:443` と書いた設定は一致せず**全ての手続きが無言で失敗する**。
 */
final class PasskeyOriginCanonicalizer
{
    /** scheme ごとの既定 port (書かれていても意味を持たない port) */
    private const DEFAULT_PORTS = ['https' => 443, 'http' => 80];

    /** 接続元 1 件を正規形へ寄せる (解釈できない値は小文字化して返すだけ)。 */
    public static function canonicalize(string $origin): string
    {
        $value = strtolower(trim($origin));

        // scheme://host[:port][/] だけを対象にする。path / query / fragment を持つ値は
        // 一致せず、**修復せずそのまま返す** (検証器が位置付きで拒否する)。
        if (preg_match('#^([a-z][a-z0-9+.\-]*)://([^/?\#]+?)(?::(\d{1,5}))?/?$#', $value, $matches) !== 1) {
            return $value;
        }

        $scheme = $matches[1];
        $host = $matches[2];
        $port = $matches[3] ?? '';

        if ($port !== '' && (self::DEFAULT_PORTS[$scheme] ?? null) === (int) $port) {
            $port = '';
        }

        return $scheme.'://'.$host.($port === '' ? '' : ':'.$port);
    }

    /**
     * 宣言 (CSV) から接続元の列を作る。**空要素は落とさない**
     * (設定の書き損じ = 余分なカンマ を起動時に表面化させるため)。
     *
     * @param  string|null  $declared  PASSKEYS_ALLOWED_ORIGINS の宣言値 (未宣言は null)
     * @param  string  $derivedOrigin  APP_URL から導出した接続元 (宣言が無いときの既定)
     * @return list<string>
     */
    public static function declaredList(?string $declared, string $derivedOrigin): array
    {
        $csv = $declared === null ? '' : trim($declared);

        // 宣言が無い / 空文字なら APP_URL からの導出 1 件に倒す
        // (env ファイルにキーだけ残す運用を壊さないため、空文字は「未宣言」と同じ扱い)。
        if ($csv === '') {
            return [self::canonicalize($derivedOrigin)];
        }

        return array_map(self::canonicalize(...), explode(',', $csv));
    }
}
```

#### B-2. 宣言側 (`config/fortify.php`)

```php
use App\Support\PasskeyOriginCanonicalizer;

// …

// APP_URL の origin (scheme://host[:port])。path / query は落とし、正規形へ寄せる。
$derivedOrigin = PasskeyOriginCanonicalizer::canonicalize(
    ($appUrlScheme !== '' && $appUrlHost !== '')
        ? $appUrlScheme.'://'.$appUrlHost.$appUrlPort
        : ''
);

$declaredOriginsValue = env('PASSKEYS_ALLOWED_ORIGINS');

// 宣言があれば CSV を**正規形へ寄せて**保持する (空要素は落とさない)。
// 正規形の定義は App\Support\PasskeyOriginCanonicalizer ただ 1 か所であり、
// 本番起動時の検査 (App\Support\PasskeyConfigValidator) も同じ器で「正規形か」を見る。
$rawAllowedOrigins = PasskeyOriginCanonicalizer::declaredList(
    is_string($declaredOriginsValue) ? $declaredOriginsValue : null,
    $derivedOrigin,
);
```

- 身元の識別子 (`$declaredRelyingPartyId`) の行は**変更しない** (trim + 小文字化のまま)。
- `raw_allowed_origins` / `allowed_origins` の組み立ては現行のまま
  (前者は空要素を保持、後者は空要素を除いた列)。

#### B-3. 検証器 (`app/Support/PasskeyConfigValidator.php`)

変更点は 4 つ。**検査の順序と本数は変えない** (1 身元の識別子の空 → 2 DNS 名 →
3 空要素 → 4 接続元 0 件 → 5 書式 → 6 相互整合 → 7 導出鍵)。

1. **例外文から生値を落とし、位置を出す**。接続元は 1 始まりの序数、
   身元の識別子は環境変数名で指す。
2. **正規形からの逸脱を落とす検査を追加**する (書式検査と相互整合の間)。
   判定は正規化器へ委譲し、正規形の定義を 2 か所に持たない。
3. docblock を裁定に合わせて書き直す (末尾スラッシュは宣言側で受理される旨)。
4. 非 ASCII ホストを拒否する根拠をコメントで明示する (実装は現行のままで拒否されるが、
   **意図した拒否である**ことをテストで固定する)。

```php
foreach ($allowedOrigins as $index => $origin) {
    $position = $index + 1;   // 例外文には**位置だけ**を出す (生の設定値は出さない)

    // 5. 書式。scheme は**小文字 https のみ** (production の WebAuthn は TLS 必須)。
    //    path / query / fragment / userinfo / 末尾スラッシュ / 非 ASCII ホストを弾く。
    //    ★ここに非正規形が届くのは「宣言側 (config/fortify.php) を通らない経路が
    //      設定した」場合だけである。webauthn-lib は厳密比較なので、その値は
    //      **全手続きを無言で失敗させる**。黙って受理せず起動時に落とす。
    if (preg_match('#^https://([a-z0-9.\-]+)(?::(\d{1,5}))?$#', $origin, $m) !== 1) {
        throw new RuntimeException(sprintf(
            'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) is invalid. '
            .'Each origin must be "https://dns-name[:port]" with no path, query, userinfo '
            .'or trailing slash. Plain http, IPv4/IPv6 literals, bracketed hosts and '
            .'non-ASCII hosts (use punycode) are not accepted in production. '
            .'The offending value is not printed here on purpose (it would be baked into deploy logs).',
            $position,
        ));
    }

    $host = $m[1];
    $port = $m[2] ?? '';

    if (! $this->isDnsName($host)) {
        throw new RuntimeException(sprintf(
            'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) has an invalid host. '
            .'Each label must be 1-63 alphanumeric/hyphen characters and must not start or end with a hyphen.',
            $position,
        ));
    }

    if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
        throw new RuntimeException(sprintf(
            'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) has an out-of-range port.',
            $position,
        ));
    }

    // 5b. 正規形からの逸脱 (既定 port の明示など)。ブラウザは既定 port を申告しないため、
    //     `:443` と書かれた設定は厳密比較に一致せず**全手続きが無言で失敗する**。
    //     正規形の定義は PasskeyOriginCanonicalizer ただ 1 か所に置き、ここでは
    //     「宣言側と同じ器に掛けて変化しないこと」だけを見る (判定基準を割らない)。
    if (PasskeyOriginCanonicalizer::canonicalize($origin) !== $origin) {
        throw new RuntimeException(sprintf(
            'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) is not in canonical form. '
            .'Do not declare the default port (":443"); browsers never send it, so the '
            .'strict comparison in webauthn-lib fails for every ceremony.',
            $position,
        ));
    }

    // 6. 身元の識別子との相互整合 (現行と同じ判定。例外文から生値だけを落とす)
    if ($host !== $relyingPartyId && ! str_ends_with($host, '.'.$relyingPartyId)) {
        throw new RuntimeException(sprintf(
            'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) does not belong to the '
            .'configured relying party id. The origin host must equal the relying party id '
            .'or be a subdomain of it, otherwise every passkey ceremony fails.',
            $position,
        ));
    }
}
```

身元の識別子側 (検査 2) と空要素 (検査 3) も同様に生値を落とす:

```php
if (! $this->isDnsName($relyingPartyId) || ! str_contains($relyingPartyId, '.')) {
    throw new RuntimeException(
        'Passkey relying party id is not an accepted production DNS name. '
        .'Set PASSKEYS_RELYING_PARTY_ID to a dotted DNS name (e.g. app.example.com); '
        .'IP addresses, "localhost", single labels and non-ASCII names (use punycode) are rejected. '
        .'(Public suffixes such as "co.uk" are not rejected here: this check has no Public Suffix List.) '
        .'The offending value is not printed here on purpose.'
    );
}

// 検査 3 (空要素) も位置を出す
foreach ($rawAllowedOrigins as $index => $raw) {
    if (trim($raw) === '') {
        throw new RuntimeException(sprintf(
            'PASSKEYS_ALLOWED_ORIGINS entry #%d is empty (a stray or trailing comma). '
            .'List each origin exactly once as "https://host[:port]".',
            $index + 1,
        ));
    }
}
```

#### B-4. 実効値が正規形であることの検査 (契約検査へ 1 本)

宣言側が正規化器を呼ばなくなったこと (= 施策 B の配線が外れたこと) を検出する。

```php
test('実効値の許可する接続元が正規形である (宣言側が正規化器を通っている)', function (): void {
    $origins = config('passkeys.allowed_origins');
    expect($origins)->toBeArray();
    /** @var array<int, mixed> $origins */
    foreach ($origins as $origin) {
        expect($origin)->toBeString();
        /** @var string $origin */
        expect(PasskeyOriginCanonicalizer::canonicalize($origin))->toBe(
            $origin,
            '宣言側 (config/fortify.php) が正規化器を通っていない可能性がある'
        );
    }
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示 (`canonicalize(): string` / `declaredList(): array` に
      `@return list<string>`)
- [x] null 安全: `declaredList(?string $declared, …)` で `null` を明示的に受ける。
      `config/fortify.php` 側は `env()` の `mixed` を `is_string()` で絞ってから渡す
      (level 10 の解析対象に `config/` が入っている)
- [x] **`parse_url()` の戻り値を未検査で使わない**: 正規化器は `parse_url()` を使わず
      正規表現 1 本で判定する (`parse_url()` は `false` / 欠けたキーを返しうるため)
- [x] `preg_match()` の戻り値は `!== 1` で比較する (`false` と `0` を混同しない)
- [x] DTO を返している: 該当なし (文字列を返す全域関数)
- [x] Generics: `list<string>` を明示

### テスト計画

**新規 `tests/Unit/Support/PasskeyOriginCanonicalizerTest.php`** (表駆動):

| 入力 | 期待 | 意図 |
|---|---|---|
| `https://app.example.com` | 同じ | 正規形は不変 (冪等) |
| ` HTTPS://App.Example.com ` | `https://app.example.com` | 空白除去と小文字化 |
| `https://app.example.com/` | `https://app.example.com` | **裁定 2026-08-04 の受理** |
| `https://app.example.com:443` | `https://app.example.com` | 既定 port の除去 |
| `https://app.example.com:443/` | `https://app.example.com` | 2 変形の同時適用 |
| `http://localhost:80` | `http://localhost` | http の既定 port |
| `https://app.example.com:8443` | 同じ | 既定でない port は残す |
| `https://app.example.com/path` | 同じ (小文字化のみ) | **修復しない** (検証器が拒否する) |
| `https://app.example.com?x=1` | 同じ | 同上 |
| `https://app.example.com#f` | 同じ | 同上 |
| `https://user@app.example.com` | 同じ | 同上 (利用者情報) |
| `https://app.example.com//` | 同じ | 末尾スラッシュは 1 個だけ落とす |
| `https://аpp.example.com` (キリル文字) | 同じ | 非 ASCII を修復しない |
| `` (空文字) | 空文字 | 空要素を潰さない |

- [ ] 冪等性: 上記すべてについて `canonicalize(canonicalize($x)) === canonicalize($x)`
- [ ] `declaredList(null, 'https://app.example.com/')` → `['https://app.example.com']`
- [ ] `declaredList('https://a.example.com/, https://b.example.com:443', $derived)` →
      `['https://a.example.com', 'https://b.example.com']`
- [ ] `declaredList('https://a.example.com,,', $derived)` → 空要素が**残る** (3 要素)
- [ ] `declaredList('', $derived)` / `declaredList('   ', $derived)` → 導出 1 件へ倒れる
- [ ] **純粋性の固定**: 正規化器のリフレクションで
      `App\Support\PasskeyOriginCanonicalizer` が `RuntimeException` を投げず
      コンテナに触れないことを、ソース字句 (`app(` / `config(` / `throw`) の
      不在で固定する (config 評価時に呼ばれるため)

**更新 `tests/Unit/Support/PasskeyConfigValidatorTest.php`**:

- [ ] **既存の「末尾スラッシュ → 例外」を削除しない**。意味が変わるので
      `検査 5` の表から末尾スラッシュ行を外し、代わりに
      「**宣言側を通った値は末尾スラッシュを含まない**」ことを
      正規化器のテストが担うと注記する (テストの削除ではなく移動)
- [ ] 追加: `https://app.example.com:443` → 例外 (`not in canonical form`)
- [ ] 追加: 非 ASCII ホスト (`https://аpp.example.com`) → 例外 (`is invalid`)
- [ ] 追加: punycode ホスト (`https://xn--p1ai.example.com`) → 例外を投げない
- [ ] 追加: 身元の識別子が非 ASCII → 例外 (`not an accepted production DNS name`)
- [ ] 追加 (**逆輸入候補 2 の機械化**): すべての違反系ケースについて、
      例外文が**生値を含まない**ことを 1 本の表駆動テストで固定する
      (`expect($e->getMessage())->not->toContain($rawValue)`)
- [ ] 追加: 位置が 1 始まりで正しく出る (2 件目が違反なら `#2`)
- [ ] 既存の期待 (`relying party id is empty` / `not an accepted production DNS name` /
      `empty entry` → `entry #1 is empty` / `allowed origins are empty` / `is invalid` /
      `out-of-range port` / `does not belong to` / 導出鍵 2 本) は文言変更に追随させる

**更新 `tests/Architecture/PasskeyPackageContractTest.php`**: B-4 の 1 本。

**確認済みで変更不要**: `tests/Feature/Support/ProductionEnvGuardTest.php` は
`config()` を直接差し替えて検証器を叩くため、宣言側の正規化の影響を受けない
(実効値を正規形で与えているので緑のまま)。

### リスク

- **設定の実効値が変わる**: 既定 port / 末尾スラッシュ付きの宣言をしている環境では
  `passkeys.allowed_origins` の値が変わる。これは「今まで無言で失敗していた設定が
  正しく動くようになる」方向で、パスキーが**使えなくなる**方向の変化は無い
  (身元の識別子は触らないため、登録済みパスキーの束縛は変わらない)。
- **例外文から生値が消えることで診断性が落ちる**: 位置 (何番目か) と環境変数名は残すので、
  運用者は自分の `.env` を見れば特定できる。配備ログへ設定値を焼き付けないことを優先する
  (家系の逆輸入候補 2 と同じ判断)。
- **非 ASCII を punycode へ変換しない**: 国際化ドメインを使う運用者は自分で punycode を
  書く必要がある。変換を実装すると「変換結果が正しいことを誰も検査できない層」が増えるため、
  拒否して運用者に書かせる側を選ぶ (誇張しない)。

---

## 施策 C: パッケージ側の削除処理への対応

### 変更箇所

- ファイル: `tests/Architecture/PasskeyPackageContractTest.php` (検出器 + 契約 2 本 + 自己テスト)
- 新規: `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php`
- ファイル: `docs/auth-security-mechanisms.md` (§5 に「削除の原子性は誰が担うか」を追記)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし / 実装コード: **なし**
  (本施策は**性質の固定**であって挙動の変更ではない)

### 現行コード (パッケージ側。実読)

```php
// vendor/laravel/passkeys/src/Actions/DeletePasskey.php
public function __invoke(Authenticatable $user, Passkey $passkey): void
{
    $passkey->delete();

    PasskeyDeleted::dispatch($user, $passkey);
}
```

行の削除とイベント発火が**同一トランザクションで包まれていない**。
本アプリでは `EnsureLoginMethodRemains` が削除 route 全体を
`DB::transaction()` で包む (実読: 同 middleware の `handle()` が
`(1) transaction を開き (2) User 行を lockForUpdate し (3) 投影を評価し (4) 同一 tx 内で $next()`)
ため、**結果として原子的**である。しかしその事実はどこにも固定されていない。

`app/` と `tests/` から `DeletePasskey` を参照する箇所は **0 件** (台帳の指摘どおり)。

### 変更後コード

```php
/*
 * パッケージ側の削除処理の非原子性 (台帳 auth-passkey-hardening 施策 3b)。
 *
 * vendor の DeletePasskey は「行を消してからイベントを発火する」形で、2 つを
 * トランザクションで包まない。本アプリではこの性質を **EnsureLoginMethodRemains が
 * 削除 route 全体を transaction で包むこと**で埋めている
 * (route への付与は PasskeyRouteProtectionTest / LoginMethodRemovalRouteTest が固定済み。
 *  巻き戻りの実挙動は tests/Feature/Auth/PasskeyDeletionAtomicityTest が固定する)。
 *
 * ここで固定するのは**前提そのもの** = 「埋め合わせが要る状態が続いていること」である。
 * パッケージ側が自前で transaction を持つようになったら赤くなり、
 * 二重の境界になっていないかを読み直す契機になる。
 */

/** 指定メソッドの vendor 実装の本文 (行範囲を実ファイルから取り出す) */
function passkeyVendorMethodSource(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);
    $file = $reflection->getFileName();
    expect($file)->toBeString();
    /** @var string $file */
    $lines = file($file);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */

    return implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
}

/** トランザクション境界を張る書き方を含むか (字句の包含判定) */
function declaresTransactionBoundary(string $source): bool
{
    foreach (['DB::transaction', '->transaction(', 'beginTransaction', 'transactional('] as $token) {
        if (str_contains($source, $token)) {
            return true;
        }
    }

    return false;
}

test('検出器の自己テスト (正例・負例)', function (): void {
    expect(declaresTransactionBoundary('DB::transaction(function () { $x->delete(); });'))->toBeTrue();
    expect(declaresTransactionBoundary('$connection->beginTransaction();'))->toBeTrue();
    expect(declaresTransactionBoundary("\$passkey->delete();\nPasskeyDeleted::dispatch(\$user, \$passkey);"))->toBeFalse();
});

test('パッケージ側の削除処理は行削除とイベント発火をトランザクションで包まない (埋め合わせの前提)', function (): void {
    $source = passkeyVendorMethodSource(DeletePasskey::class, '__invoke');

    expect($source)->toContain('->delete()')
        ->and($source)->toContain('PasskeyDeleted::dispatch');
    expect(declaresTransactionBoundary($source))->toBeFalse(
        'vendor が自前で transaction を持つようになった。EnsureLoginMethodRemains の '
        .'transaction と二重の境界になっていないか読み直すこと'
    );
});

test('パッケージ側の登録処理も同様に包まない (登録経路には埋め合わせが無いことの明示)', function (): void {
    $source = passkeyVendorMethodSource(StorePasskey::class, '__invoke');

    expect(declaresTransactionBoundary($source))->toBeFalse();
    // 登録 route には ensure-login-method が付かない (手段を減らす操作ではないため)。
    // したがって登録経路は **非原子性がそのまま残る** = 既知の窓であることを明示する。
});
```

**新規 `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php`** (実挙動):

```php
test('パッケージ側の削除処理を単体で呼ぶと、購読側が失敗しても行は消えている (非原子性の実挙動)', function (): void {
    $user = User::factory()->ssoOnly()->create();
    $passkey = Passkey::factory()->for($user)->create();

    Event::listen(PasskeyDeleted::class, function (): void {
        throw new RuntimeException('listener failure');
    });

    expect(fn () => app(DeletePasskey::class)($user, $passkey))
        ->toThrow(RuntimeException::class, 'listener failure');

    // ★包まれていないので行は消えたまま = これが埋め合わせの必要な状態である
    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
});

test('HTTP 削除経路では購読側の失敗で削除ごと巻き戻る (関門がトランザクション境界)', function (): void {
    $user = User::factory()->ssoOnly()->create();
    $passkeys = Passkey::factory()->count(2)->for($user)->create();   // 手段が残る状態
    $target = $passkeys->first();

    Event::listen(PasskeyDeleted::class, function (): void {
        throw new RuntimeException('listener failure');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->delete("/user/passkeys/{$target->getKey()}"))
        ->toThrow(RuntimeException::class, 'listener failure');

    // 行も監査記録も同じ transaction で巻き戻る
    expect(Passkey::query()->whereKey($target->getKey())->exists())->toBeTrue();
    expect(SecurityAuditEvent::query()->where('event_type', SecurityEventType::PasskeyDeleted->value)->count())->toBe(0);
});
```

ドキュメント (`docs/auth-security-mechanisms.md` §5) へ 1 項追記:

> - **削除の原子性はアプリ側が埋めている**。パッケージ側の削除処理は
>   「行を消してからイベントを発火する」形で 2 つをトランザクションで包まない。
>   本アプリは `EnsureLoginMethodRemains` が削除 route 全体をトランザクションで包むため、
>   購読側 (監査記録など) が失敗すると**削除ごと巻き戻る**。
>   **登録経路にはこの埋め合わせが無い** (手段を減らす操作ではないため関門が付かない) —
>   登録の購読側が失敗した場合、行は残りイベント処理だけが失われる。
>   前提の固定は `PasskeyPackageContractTest`、実挙動は `PasskeyDeletionAtomicityTest`。

### PHPStan 適合チェック

- [x] `ReflectionMethod::getFileName()` は `string|false` を返すため
      `expect()->toBeString()` の後に `/** @var string */` で絞る
- [x] `file()` は `list<string>|false` を返すため同様に絞る
- [x] 追加するのはテストのみで、`app/` の型は変わらない

### テスト計画

- [ ] **先に赤くする**: `declaresTransactionBoundary()` の自己テストの負例を
      いったん `toBeTrue()` にして赤を確認する (検出器が実際に判定していること)
- [ ] **先に赤くする**: 実挙動テスト 2 本のうち、HTTP 経路のほうは
      `EnsureLoginMethodRemains` から `DB::transaction` を外すと赤になることを
      ローカルで一度確認する (**確認後に必ず戻す**。コミットしない)
- [ ] `RefreshDatabase` はグローバル適用 (個別 `DatabaseTransactions` は使わない)。
      middleware の `DB::transaction()` はテストの外側トランザクションの中では
      **セーブポイント**になり、巻き戻りは pgsql で正しく起きる
- [ ] テストデータは Factory (`User::factory()->ssoOnly()` / `Passkey::factory()`)

### リスク

- **字句の包含判定は誇張しない**: 検出できるのは対象メソッドの本文に現れる 4 つの字句だけで、
  vendor が別のヘルパ経由でトランザクションを開いた場合は沈黙する。
  失敗メッセージと docblock にこの限界を書く。
- **Event::listen で足した購読は他のテストへ漏れない** (Pest は各テストでアプリを作り直す)。
- HTTP 経路のテストは `withoutExceptionHandling()` を使うため、
  例外の型が変わると赤くなる (意図した固定)。

---

## 施策 D: 逸脱の登録 (検証点が正典と違うことの記録)

### 変更箇所

- ファイル: `docs/template-divergence.md` (登録エントリを 1 件追加、冒頭の件数 23 → 24)
- ファイル: `tests/Architecture/TemplateDivergenceLedgerFormatTest.php`
  (`TEMPLATE_DIVERGENCE_ENTRY_COUNT` を 23 → 24)

### 背景

家系の正典 (テンプレート / aigenba) は許可する接続元の検査を**設定の評価時**に行い、
正規形にならなければその場で落とす。aicue は **本番起動時の関門** (`ProductionEnvGuard`) で
検査する形を採っている (aicue:T166 の判断)。理由は、config はすべての環境で評価されるため、
評価時に例外を投げると開発環境とテストレーンまで起動不能にできることである。
本設計はこの構図を維持するので、**逸脱として登録する** (AGENTS.md「テンプレートとの関係」)。

### 追加するエントリ (書式は `docs/template-divergence.md` の登録メタ表 9 行ちょうど)

```markdown
## D25 パスキー設定の検査を「設定の評価時」ではなく「本番起動時の関門」で行う

| 行 | 値 |
|---|---|
| 対象パス | `app/Support/PasskeyConfigValidator.php` / `app/Support/PasskeyOriginCanonicalizer.php` |
| 業務要件起因の説明 | 撮影 PWA の主要ログイン導線がパスキーであり、設定の評価時に例外を投げる正典の形では開発環境とテストレーンまで起動不能にできる。本アプリは受け入れホストと接続元の信頼設定で「本番起動時に落とす」関門を先に確立しており、パスキーもそこへ相乗りする |
| 揃え続ける不変条件と保証機構 | 正規形の定義は 1 か所 (`PasskeyOriginCanonicalizer`) で、宣言側は正規形へ寄せ、検証側は正規形からの逸脱を落とす。本番で書式・相互整合・導出鍵の宣言が不正なら起動しない (`ProductionEnvGuardTest` / `PasskeyConfigValidatorTest` / `PasskeyOriginCanonicalizerTest`) |
| 再判定の条件 | 正典が検査の置き場所を変えたとき、または本番以外でも設定事故を早期に検出したい要求が出たとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260815-1111-passkey-config-hardening/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

- **対象パスの重複禁止**: 実読で確認済み — `app/Support/PasskeyConfigValidator.php` /
  `app/Support/PasskeyOriginCanonicalizer.php` / `config/fortify.php` のいずれも
  現行の登録簿に現れない。`config/fortify.php` は本エントリの対象パスに**含めない**
  (逸脱の実体は検査の置き場所であり、宣言側は正典と同じ役割のため)。
- **決めた日**は逸脱を最初に決めた日 (aicue:T166 の設計日 2026-08-15) を書く
  (再判断で書き換えない、という登録簿の規約に従う)。
- **根拠**は `T<n>` か `devnotes/<dir>/` のどちらかで、ディレクトリが実在すること。
  aicue:T216 は登録時点で `docs/TODO.md` にあるが、逸脱を決めたのは前タスクなので
  devnotes のディレクトリを指す。

### テスト計画

- [ ] **先に赤くする**: 登録簿へエントリを足し、`TEMPLATE_DIVERGENCE_ENTRY_COUNT` を
      更新する**前に** `composer test -- --filter=TemplateDivergenceLedgerFormat` を走らせ、
      件数の不一致で赤くなることを確認する (件数同期の検査が生きていることの確認)
- [ ] その後に定数と冒頭の「登録エントリ: N 件」を 24 へ揃えて緑にする

### リスク

- 登録メタ表は形式が機械強制されている (9 行・値域・対象パスの実在と重複・件数の 3 点一致)。
  書き損じは同テストが検出するので、赤を見てから直す。

---

## 変更ファイル一覧

### 新規

| ファイル | 役割 |
|---|---|
| `app/Support/PasskeyOriginCanonicalizer.php` | 許可する接続元の正規形を決める唯一の場所 (純粋な静的関数) |
| `tests/Unit/Support/PasskeyOriginCanonicalizerTest.php` | 正規化の表駆動テスト・冪等性・純粋性の固定 |
| `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php` | 削除の原子性 (パッケージ側の非原子性とアプリ側の埋め合わせ) の実挙動 |

### 変更

| ファイル | 変更内容 |
|---|---|
| `config/fortify.php` | 接続元の組み立てを正規化器へ委譲 (身元の識別子の行は触らない) |
| `app/Support/PasskeyConfigValidator.php` | 例外文から生値を除去し位置を出す / 正規形からの逸脱を落とす検査を追加 / docblock を裁定へ整合 |
| `tests/Architecture/PasskeyPackageContractTest.php` | `laravel/fortify` の版の固定 2 本 / 実効値が正規形である検査 1 本 / パッケージ側の非原子性の固定 2 本 + 検出器の自己テスト 1 本 |
| `tests/Unit/Support/PasskeyConfigValidatorTest.php` | 文言変更への追随と、既定 port・非 ASCII・punycode・位置表示・生値非露出の追加 |
| `docs/auth-security-mechanisms.md` | §5 の版の固定の記述を是正 / 正規化の受理範囲 / 削除の原子性の担い手を追記 |
| `.env.example` | 許可する接続元の注記 (末尾スラッシュと既定 port は正規化して受理する) |
| `docs/template-divergence.md` | D25 の登録と冒頭の件数 |
| `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` | 登録件数の定数 |

### 削除

- なし (後方互換の並走を残す変更ではないため、消すべき旧実装が無い)

### 触らないと決めたファイル

- `AGENTS.md`: 運用要件 (パスキー) の意味が変わらないため
  (下の「破壊的変更の判定」)。
- `app/DataTransferObjects/Auth/LoginMethodRemoval.php` (施策 3a。実装済み)
- `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` (施策 4。実装済み)
- `app/Http/Middleware/EnsureLoginMethodRemains.php` (施策 C は性質の固定であり挙動を変えない)

## 破壊的変更の判定 (AGENTS.md 運用要件の更新要否)

環境変数 → config という**通常の宣言経路**を通った設定について、
**新たに拒否されるようになるものは無い**。

| 設定の形 | 現在 | 変更後 | 向き |
|---|---|---|---|
| 末尾スラッシュ付き | 本番で起動しない | 受理して正しく動く | 緩和 |
| 既定 port 付き (`:443`) | 起動するが手続きが全件失敗 | 正規化して正しく動く | 是正 |
| path / query / 利用者情報付き | 拒否 | 拒否 | 変化なし |
| 非 ASCII ホスト | 拒否 | 拒否 (明示テストを追加) | 変化なし |
| port 0 / 範囲外 port | 拒否 | 拒否 | 変化なし |
| 導出鍵の未宣言 / 32 文字未満 | 拒否 | 拒否 | 変化なし |

したがって **AGENTS.md 「運用要件 (パスキー)」の文面は更新しない**。
`PASSKEYS_USER_HANDLE_SECRET` の宣言必須という既存の破壊的性質もそのまま維持する。

**config を直接差し替える経路**からは、変更後は正規形でない値が拒否される。
これは意図した設計 (受理は宣言点、検証は正規形の一致) であり、
影響を受けるのはテストコードだけである (実測: `ProductionEnvGuardTest` は
実効値を正規形で与えているため緑のまま)。

## テストファースト計画 (どのテストを先に赤にするか)

| 順 | 赤にするテスト | 赤の作り方 | 緑にする実装 |
|---|---|---|---|
| 1 | `PasskeyOriginCanonicalizerTest` (表駆動 + 冪等 + 純粋性) | クラスが存在しないので**全件赤** | 施策 B-1 |
| 2 | `PasskeyConfigValidatorTest` の追加分 (既定 port / 非 ASCII / punycode / 位置表示 / 生値非露出) | 既定 port は現在**通ってしまう**ので赤。生値非露出は現在の文言で赤 | 施策 B-3 |
| 3 | `PasskeyPackageContractTest` の「実効値が正規形」 | 宣言側が未委譲のうちは、既定 port を含む設定で赤にできる (一時的に `.env` ではなくテスト内 `config()` で確認) | 施策 B-2 |
| 4 | `PasskeyPackageContractTest` の fortify 版固定 2 本 | 期待値を一時的に 1.38 にして赤を確認してから 1.37 へ戻す (負のコントロール) | 施策 A |
| 5 | `PasskeyPackageContractTest` の検出器の自己テスト | 負例の期待を反転して赤を確認してから戻す | 施策 C |
| 6 | `PasskeyDeletionAtomicityTest` の 2 本 | ファイルが無いので赤。HTTP 経路は `EnsureLoginMethodRemains` の `DB::transaction` を一時的に外して赤を確認し**必ず戻す** | 施策 C (実装変更なし = 現行挙動の固定) |
| 7 | `TemplateDivergenceLedgerFormatTest` | 登録簿へ 1 件足した時点で件数不一致により赤 | 施策 D の定数更新 |

各段の赤は**実装ログに残す** (禁止事項 1 = テストなしの実装完了報告を作らないため)。

## 受け入れ条件 (機械検証可能)

1. `composer test` が全件緑 (グローバルテストロックの待機は正常。kill しない)。
2. `composer phpstan` がエラー 0 (level 10。`@phpstan-ignore` / baseline を足さない)。
3. `vendor/bin/pint --test` が緑。
4. `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が緑。
5. 台帳施策 1 の充足: `rg -n "laravel/fortify" tests/` が**版を検査する式**を返す
   (制約の正規表現と解決値の前方一致の 2 本)。
6. 台帳施策 2 の充足:
   - `PasskeyOriginCanonicalizer::canonicalize('https://app.example.com/')`
     が `https://app.example.com` を返す (裁定 2026-08-04)
   - 同 `('https://app.example.com:443')` が `https://app.example.com` を返す
   - `PasskeyConfigValidator` が `https://app.example.com:443` を拒否する
   - すべての違反系の例外文が、与えた許可元と身元の識別子の**生の文字列を含まない**
   - `config('passkeys.allowed_origins')` の全要素が正規形である
7. 台帳施策 3b の充足: `rg -n "DeletePasskey" app/ tests/` が **0 件でない**
   (契約検査と実挙動テストの両方が参照する)。
8. `docs/auth-security-mechanisms.md` §5 に
   「版の固定の対象は `laravel/passkeys` だけ」という**現行の記述が残っていない**
   (`rg -n "laravel/fortify は 1.x の semver" docs/` が 0 件)。
9. `docs/template-divergence.md` の冒頭件数と
   `TEMPLATE_DIVERGENCE_ENTRY_COUNT` と実エントリ数の 3 点が一致する
   (`TemplateDivergenceLedgerFormatTest` が判定)。
10. `git grep -n "PASSKEYS_RP_ID"` が 0 件 (改名を見送った判断が実装に混入していない)。

## 保証しないもの / やらないと決めたこと

**保証しないもの (誇張しない)**:

- 検査が見るのは**書式と相互整合まで**である。「その host を実際に運用しているか」
  「証明書があるか」は検査できない。
- **公開接尾辞の一覧を持たない**ため、`co.uk` のような値を身元の識別子に置いた設定は
  起動時に通る (既知の限界として既存テストが記録済み。本設計でも変えない)。
- 正規化器が正規形へ寄せるのは 3 つの変形 (空白と大小文字 / 根の末尾スラッシュ 1 個 /
  既定 port) **だけ**である。それ以外の不正な値は**修復しない**
  (path / query / 利用者情報 / 非 ASCII / 二重スラッシュはそのまま返して検証器が拒否する)。
- パッケージ側の非原子性の検出は**対象メソッド本文の字句の包含判定**であり、
  別のヘルパ経由でトランザクションを開く書き方には沈黙する。
- **登録経路 (パスキーの追加) の非原子性は埋めない**。削除経路と違い手段保持の関門が
  付かないため、購読側が失敗すると行だけが残る。既知の窓として文書化と契約検査で
  可視化するに留める。
- 検査が走るのは **`Features::passkeys()` が有効な本番起動時**だけである
  (キルスイッチを切った環境には設定を要求しない)。この性質は現行のままで変えない。
- 版の固定が守るのは **composer の宣言と解決値**であって、実際に読み込まれた
  vendor のコードが改変されていないことではない。

**やらないと決めたこと (理由付き)**:

| やらないこと | 理由 |
|---|---|
| `PASSKEYS_RELYING_PARTY_ID` → `PASSKEYS_RP_ID` の改名 | 旧名を宣言している環境では新名が未宣言になり、身元の識別子が `APP_URL` 由来へ**無言で戻る** = 登録済みパスキーが全件使えなくなる。安全にやるには「旧名が残っていたら起動時に落とす」検査が要り、得られるのは名前の短さだけ (思考原則 2)。現行名は config のキー名とパッケージ側の用語の双方に一致している。**再検討の条件**: 家系の裁定として改名が決まったとき (そのとき旧名の検出も同じ変更に含める) |
| 検査を「設定の評価時」へ移す (正典の形) | config はすべての環境で評価されるため、評価時に落とすと開発環境とテストレーンまで起動不能にできる。本番起動時 fail-fast の運用要件 (AGENTS.md) を維持し、逸脱として D25 に登録する |
| 本番で非暗号化接続の許可元を落とす専用検査の新設 | **既に等価**である (本番の書式検査が `https://` 以外を受理しない)。二重に持たない |
| 国際化ドメインの punycode 変換 | 変換結果の正しさを誰も検査できない層が増える。拒否して運用者に punycode を書かせる |
| 公開接尾辞の一覧の導入 | 依存が増える一方、誤設定の結果は「パスキーが使えない」であって権限昇格ではない。設定するのは攻撃者ではなく運用者である |
| 登録経路へのトランザクション被せ | 手段保持の関門が無い経路なので被せ方の設計が別途要る。本タスクの範囲外 (必要になったら独立した設計で起こす) |
| 台帳への書き込み | 設計フェーズの責務ではない。実装完了後に `status_reported` を出す |

## 全検証コマンド (すべて緑であること)

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

(`AGENTS.md` の検証コマンド節と一致。`verification-commands-doc-sync` テストが
`package.json` との同期を強制しているため、この一覧を勝手に削らない。
テストレーンはホスト全体で 1 本ずつしか走らないので**待ち時間は正常**であり、
30 秒ごとの heartbeat が出ている間はハングではない。)

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `docs/auth-security-mechanisms.md` / `docs/template-divergence.md` / `.env.example` / 契約検査という**他の設計と衝突しやすい共有ファイル**を触る。加えて 4 施策が「正規化器 → 宣言側 → 検証器 → 検査」と 1 本の依存線で連なっており、部分適用すると宣言側だけが正規化して検証側が旧文言、のような中途半端な状態を作れる |
| 競合リスク | `tests/Architecture/PasskeyPackageContractTest.php` に 3 施策が集中するため、同ファイルを触る他タスクとは順序を分ける。`docs/template-divergence.md` の件数定数は他の逸脱登録と衝突しやすいので、main へのマージ直前に rebase して件数を取り直す |

---

## 関連する現行コード

### config/fortify.php (パスキー宣言ブロック。冒頭 66 行)
```php
<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| パスキー (WebAuthn) の設定
|--------------------------------------------------------------------------
|
| ⚠ **宣言場所がここである理由**: Laravel\Fortify\FortifyServiceProvider::register() の
| configurePasskeys() が `passkeys.*` を `config('fortify.passkeys.*')` から
| **無条件に上書きする**ため、アプリ側 config/passkeys.php を置いても効かない。
| Fortify が読むこのキーが唯一の宣言点である。
|
| ⚠ **他の config ファイルを config() で読まない** (読み込み順に依存するため)。
| ここでは env() だけを見る (APP_KEY / APP_URL は config/app.php と同じ env を読む)。
|
| 既定値は APP_URL / APP_KEY からの導出で、同一オリジン PWA (v1 スコープ) では
| 通常 env の宣言なしで正しく動く。ただし **PASSKEYS_USER_HANDLE_SECRET だけは
| production で宣言が必須** (未宣言だと APP_KEY ローテートで登録済みパスキーが全件無効)。
| 検査は App\Support\PasskeyConfigValidator (ProductionEnvGuard 経由) が起動時に行う。
| 運用契約は docs/auth-security-mechanisms.md §5。
|
*/

$appUrl = parse_url((string) env('APP_URL', ''));

$appUrlScheme = is_array($appUrl) && is_string($appUrl['scheme'] ?? null) ? strtolower($appUrl['scheme']) : '';
$appUrlHost = is_array($appUrl) && is_string($appUrl['host'] ?? null) ? strtolower($appUrl['host']) : '';
$appUrlPort = is_array($appUrl) && is_int($appUrl['port'] ?? null) ? ':'.$appUrl['port'] : '';

// APP_URL の origin (scheme://host[:port])。path / query は落とす。
$derivedOrigin = ($appUrlScheme !== '' && $appUrlHost !== '')
    ? $appUrlScheme.'://'.$appUrlHost.$appUrlPort
    : '';

$declaredRelyingPartyIdValue = env('PASSKEYS_RELYING_PARTY_ID');
$declaredRelyingPartyId = is_string($declaredRelyingPartyIdValue) ? strtolower(trim($declaredRelyingPartyIdValue)) : '';

$declaredOriginsValue = env('PASSKEYS_ALLOWED_ORIGINS');
$declaredOrigins = is_string($declaredOriginsValue) ? trim($declaredOriginsValue) : '';

// 宣言があれば CSV を trim + **小文字化**して保持する (空要素は落とさない)。
// ★小文字化は load-bearing である。webauthn-lib の照合は
//   `in_array($normalizedOrigin, $this->fullOrigins, true)` = **strict な文字列比較**で
//   (vendor/web-auth/webauthn-lib/src/CeremonyStep/CheckAllowedOrigins.php 実測)、
//   ブラウザは常に小文字の origin を申告する。`HTTPS://App.Example.com` と書かれた設定は
//   一致せず**全ての手続きが無言で失敗する**ため、宣言の時点で小文字へ正規化する
//   (scheme と host は RFC 3986 上 case-insensitive なので、正規化は意味を変えない)。
// 宣言が無い / 空文字なら APP_URL からの導出 1 件に倒す
// (env ファイルにキーだけ残す運用を壊さないため、空文字は「未宣言」と同じ扱い)。
$rawAllowedOrigins = $declaredOrigins !== ''
    ? array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $declaredOrigins))
    : [$derivedOrigin];

// ⚠ **値そのものは trim しない**。「既にパスキーがある環境は現行 APP_KEY の値を
//    そのまま宣言すれば維持できる」という運用契約を守るため
//    (APP_KEY に前後空白が含まれていた場合、trim すると別の鍵になり全件無効になる)。
//    trim を使うのは「宣言されたか (空白だけではないか)」の判定にだけ留める。
$declaredUserHandleSecretValue = env('PASSKEYS_USER_HANDLE_SECRET');
$declaredUserHandleSecret = is_string($declaredUserHandleSecretValue) ? $declaredUserHandleSecretValue : '';
$userHandleSecretDeclared = trim($declaredUserHandleSecret) !== '';

return [
```

### config/fortify.php (passkeys ブロック)
```php
    /*
    |--------------------------------------------------------------------------
    | Passkeys
    |--------------------------------------------------------------------------
    |
    | ファイル冒頭のコメント参照。FortifyServiceProvider::configurePasskeys() が
    | このブロックを passkeys.* へ写す (アプリ側 config/passkeys.php は効かない)。
    |
    */

    'passkeys' => [
        /*
        | 身元の識別子 (relying party id)。パスキーはこの値に束縛され、
        | 一致するドメインでしか検証できない。host のみ (scheme / port を含めない)。
        | 未宣言なら APP_URL の host。Fortify が passkeys.relying_party_id へ写す。
        */
        'relying_party_id' => $declaredRelyingPartyId !== '' ? $declaredRelyingPartyId : $appUrlHost,

        /*
        | 許可する接続元 (allowed origins)。ブラウザが申告した origin がこの列に無ければ
        | WebAuthn の手続きを受け付けない。`scheme://host[:port]` 形式。
        | Fortify が passkeys.allowed_origins へ写し、webauthn-lib が読む。**空要素を除いた列**。
        */
        'allowed_origins' => array_values(array_filter(
            $rawAllowedOrigins,
            static fn (string $v): bool => $v !== '',
        )),

        /*
        | フィルタ前の接続元列 (trim・小文字化済み。**空要素を保持する**)。
        | ここでの「生」は「env の原文」ではなく「空要素を除去する前」の意味である。config 段で落ちた空要素を
        | 起動時 fail-fast で表面化させるために PasskeyConfigValidator が読む
        | (trustedproxy.raw_proxies と同じ役割)。**Fortify は本キーを読まない**
        | (検査専用。passkeys.* へは写らない)。
        */
        'raw_allowed_origins' => $rawAllowedOrigins,

        /*
        | 利用者ハンドルの導出鍵。hash_hmac の鍵として使われ、**変わると
        | 登録済みパスキーが全件無効になる**。未宣言なら APP_KEY に倒れるため、
        | APP_KEY ローテートがパスキー全件失効を意味してしまう。
        | production では宣言必須 (PasskeyConfigValidator が起動時に検査)。
        */
        'user_handle_secret' => $userHandleSecretDeclared
            ? $declaredUserHandleSecret
            : (string) env('APP_KEY', ''),

        /*
        | 導出鍵が **APP_KEY と独立して宣言されたか**。値の一致では判定しない
        | (既存パスキーを維持するために現行 APP_KEY と同じ値を意図して宣言する
        |  移行が正当なため)。config:cache 後も真偽値として残る。
        | **Fortify は本キーを読まない** (検査専用)。
        */
        'user_handle_secret_declared' => $userHandleSecretDeclared,
    ],

];
```

### app/Support/PasskeyConfigValidator.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * パスキー (WebAuthn) 設定 (config/fortify.php の passkeys ブロック → Fortify が passkeys.* へ写す)
 * の production 起動時検証。
 *
 * `TrustedProxiesConfigValidator` / `TrustedHostsConfigValidator` と同形
 * (final / 純粋クラス / RuntimeException / ProductionEnvGuard から try-catch で写像)。
 *
 * 背景: パスキーは**単独でログインできる強い資格**であり、正しさが 3 つの設定値に依存する。
 * これらは既定で APP_URL / APP_KEY から導出されるため、設定事故が
 * **利用者が認証しようとした瞬間まで表面化しない** (登録はできるのに検証が全件失敗する、
 * あるいは APP_KEY ローテートで登録済みパスキーが全件無効になる)。
 * production では起動時に落として、デプロイ前に気づけるようにする。
 *
 * ⚠ 本 validator は **意図的にデプロイ時の破壊的変更**である
 * (TRUSTED_PROXIES と同性質)。`PASSKEYS_USER_HANDLE_SECRET` を宣言せずに
 * production を起動すると fail-fast する。既にパスキーが登録済みの環境では
 * **現行 APP_KEY の値をそのまま宣言すれば既存パスキーは維持される**
 * (宣言の有無で判定し、値の一致では判定しないため)。
 * 運用契約は docs/auth-security-mechanisms.md §5。
 *
 * 限界 (誇張しない): 本 validator は **書式と相互整合**しか見ない。
 * 「その host を本当に運用しているか」「TLS 証明書があるか」は検査できない。
 * **Public Suffix List を持たないため、`co.uk` のような public suffix を身元の識別子に
 * 置いた設定は通ってしまう** (ブラウザ側は PSL を見るので実際の手続きは失敗する)。
 * したがって本 validator は **WebAuthn の完全な妥当性検査ではない**。
 * PSL 判定のために依存を足すことはしない (TrustedHostsConfigValidator と同じ判断。
 * 誤設定の結果は「パスキーが使えない」であって権限昇格ではなく、
 * 設定するのは攻撃者ではなく運用者であるため)。この限界は
 * PasskeyConfigValidatorTest に**既知の限界として明示的なテストで記録する**。
 * production の身元の識別子・接続元は **DNS 名のみ**を対象とする
 * (IPv4 / IPv6 リテラルと単一ラベルは reject する = WebAuthn の relying party id にできない)。
 */
final class PasskeyConfigValidator
{
    /** DNS 名の最大長 (末尾ドットを含まない) */
    private const MAX_DNS_NAME_LENGTH = 253;

    /** DNS ラベルの最大長 */
    private const MAX_DNS_LABEL_LENGTH = 63;

    /** 導出鍵の最小長 (短い値は typo / placeholder の可能性が高い) */
    private const MIN_USER_HANDLE_SECRET_LENGTH = 32;

    /**
     * @param  string  $relyingPartyId  config 通過後の身元の識別子 (host のみ)
     * @param  list<string>  $allowedOrigins  config 通過後の許可する接続元 (空要素除去済み)
     * @param  list<string>  $rawAllowedOrigins  フィルタ前の接続元列 (trim・小文字化済み、空要素を保持)
     * @param  bool  $userHandleSecretDeclared  導出鍵が専用 env で宣言されたか
     * @param  string  $userHandleSecret  解決後の導出鍵
     *
     * @throws RuntimeException
     */
    public function validateForProduction(
        string $relyingPartyId,
        array $allowedOrigins,
        array $rawAllowedOrigins,
        bool $userHandleSecretDeclared,
        string $userHandleSecret,
    ): void {
        // 1. 身元の識別子。空 = APP_URL に host が無い (パスキーの手続きが実行時例外になる)。
        if ($relyingPartyId === '') {
            throw new RuntimeException(
                'Passkey relying party id is empty in production. '
                .'Set PASSKEYS_RELYING_PARTY_ID, or make sure APP_URL contains a host. '
                .'See docs/auth-security-mechanisms.md.'
            );
        }

        // 2. 身元の識別子は production で受け付ける dotted DNS 名でなければならない。
        //    IP リテラル / localhost / 単一ラベルは WebAuthn の relying party id にできない。
        //    (public suffix かどうかはここでは見ない = PSL を持たない。docblock の限界を参照)
        if (! $this->isDnsName($relyingPartyId) || ! str_contains($relyingPartyId, '.')) {
            throw new RuntimeException(sprintf(
                'Passkey relying party id "%s" is not an accepted production DNS name. '
                .'It must be a dotted DNS name (e.g. app.example.com), not an IP address, '
                .'"localhost" or a single label. '
                .'(Public suffixes such as "co.uk" are not rejected here: this check has no Public Suffix List.)',
                $relyingPartyId,
            ));
        }

        // 3. 接続元の宣言に空要素がある = 設定の書き損じ (末尾カンマ / 連続カンマ)。
        //    config 段で落ちた事実を黙って正規化せず、起動時に表面化させる。
        foreach ($rawAllowedOrigins as $raw) {
            if (trim($raw) === '') {
                throw new RuntimeException(
                    'PASSKEYS_ALLOWED_ORIGINS contains an empty entry '
                    .'(a stray or trailing comma). List each origin exactly once as '
                    .'"https://host[:port]".'
                );
            }
        }

        // 4. 接続元が 1 件も無いと vendor が手続き実行時に例外を投げる (起動時には落ちない)。
        if ($allowedOrigins === []) {
            throw new RuntimeException(
                'Passkey allowed origins are empty in production. '
                .'Set PASSKEYS_ALLOWED_ORIGINS, or make sure APP_URL contains a scheme and host.'
            );
        }

        foreach ($allowedOrigins as $origin) {
            // 5. 書式。scheme は**小文字 https のみ** (production の WebAuthn は TLS 必須)。
            //    path / query / fragment / userinfo / 末尾スラッシュを弾く。
            //    ★大文字を通さないのは意図的である: 宣言側 (config/fortify.php) が小文字へ
            //      正規化するので、ここに大文字が届くのは「別経路が正規化せずに設定した」場合だけ。
            //      webauthn-lib は strict 比較なので、その値は**全手続きを無言で失敗させる**。
            //      黙って受理せず起動時に落とす (運用者が env へ書く大文字は config が吸収する)。
            if (preg_match('#^https://([a-z0-9.-]+)(?::(\d{1,5}))?$#', $origin, $m) !== 1) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" is invalid. '
                    .'Each origin must be "https://dns-name[:port]" with no path, query or trailing slash. '
                    .'Plain http, IPv4/IPv6 literals and bracketed hosts are not accepted in production.',
                    $origin,
                ));
            }

            $host = $m[1];   // 正規表現が小文字だけを通すので strtolower しない
            $port = $m[2] ?? '';

            if (! $this->isDnsName($host)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" has an invalid host. '
                    .'Each label must be 1-63 alphanumeric/hyphen characters and must not start or end with a hyphen.',
                    $origin,
                ));
            }

            if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" has an out-of-range port.',
                    $origin,
                ));
            }

            // 6. WebAuthn は「身元の識別子が接続元 host と一致するか、その上位ドメインである」
            //    ことを要求する。ここが食い違うと**全ての手続きが失敗する** (登録も検証も)。
            if ($host !== $relyingPartyId && ! str_ends_with($host, '.'.$relyingPartyId)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" does not belong to the relying party id "%s". '
                    .'The origin host must equal the relying party id or be a subdomain of it, '
                    .'otherwise every passkey ceremony fails.',
                    $origin,
                    $relyingPartyId,
                ));
            }
        }

        // 7. 導出鍵は **APP_KEY から独立して宣言されている**こと。
        //    未宣言だと APP_KEY に倒れ、鍵ローテートで登録済みパスキーが全件無効になる。
        if (! $userHandleSecretDeclared) {
            throw new RuntimeException(
                'PASSKEYS_USER_HANDLE_SECRET is not set in production. '
                .'Without it the passkey user handle is derived from APP_KEY, so rotating APP_KEY '
                .'silently invalidates every registered passkey. '
                .'When migrating an environment that already has passkeys, declare the current APP_KEY value. '
                .'See docs/auth-security-mechanisms.md.'
            );
        }

        if (strlen($userHandleSecret) < self::MIN_USER_HANDLE_SECRET_LENGTH) {
            throw new RuntimeException(sprintf(
                'PASSKEYS_USER_HANDLE_SECRET is shorter than %d characters. '
                .'Use a long random value (e.g. php -r "echo bin2hex(random_bytes(32));").',
                self::MIN_USER_HANDLE_SECRET_LENGTH,
            ));
        }
    }

    /**
     * DNS 名として妥当か (ラベル単位で検査する)。
     *
     * 包含正規表現 `[A-Za-z0-9.-]+` だけでは `-example.com` / `example..com` /
     * `example.com.` が通ってしまうため、ドットで分割して 1 ラベルずつ見る。
     *
     * ⚠ これは純粋な DNS 構文検証ではなく **production の WebAuthn 用に採用する DNS 名の方針**である
     * (DNS 構文自体は全数字の末尾ラベルも大文字も禁止していない)。
     *
     * **大文字を受理しない**のは、宣言側 (config/fortify.php) が小文字へ正規化するためである。
     * ここに大文字が届くのは別経路が未正規化のまま設定した場合で、その値は
     * webauthn-lib の strict 比較に一致せず**全手続きを無言で失敗させる**。
     *
     * **末尾ラベルに英字を 1 文字以上要求する**のは、`192.168.001.001` のような
     * 「filter_var では IP と認められないが実質 IP アドレスの書き損じ」を弾くため
     * (全数字の TLD は存在しない)。punycode (`xn--p1ai`) は英字を含むので通る。
     */
    private function isDnsName(string $host): bool
    {
        if ($host === '' || strlen($host) > self::MAX_DNS_NAME_LENGTH) {
            return false;
        }

        // IPv4 / IPv6 リテラルは relying party id にも origin host にも使えない。
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > self::MAX_DNS_LABEL_LENGTH) {
                return false;   // 空ラベル = 連続ドット / 先頭ドット / 末尾ドット
            }
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;   // ハイフン開始 / ハイフン終了 / 大文字 / 不正文字
            }
        }

        // 末尾ラベル (TLD 相当) は英字を含むこと。
        return preg_match('/[a-z]/', $labels[count($labels) - 1]) === 1;
    }
}
```

### app/Support/ProductionEnvGuard.php (パスキー検査の呼び出し部)
```php
        // パスキー (WebAuthn) の身元 / 接続元 / 利用者ハンドル導出鍵を起動時検証。
        // **キルスイッチが有効なときだけ**検査する (機能を止めている環境に設定を要求しない)。
        // 有効化点は config/fortify.php の Features::passkeys([...]) ただ 1 箇所。
        if (Features::enabled(Features::passkeys())) {
            $relyingPartyIdValue = config('passkeys.relying_party_id');
            $relyingPartyId = is_string($relyingPartyIdValue) ? $relyingPartyIdValue : '';
            // ★読み出し元が 2 つに分かれる理由:
            //   - 実効値 (passkeys.*) = **実際に手続きで使われる値**。Fortify の上書き後の姿を検査する。
            //   - 宣言の事実 (fortify.passkeys.raw_allowed_origins / user_handle_secret_declared) は
            //     検査専用キーで Fortify は passkeys.* へ写さない。宣言元から読むしかない。
            $originsValue = config('passkeys.allowed_origins', []);
            $rawOriginsValue = config('fortify.passkeys.raw_allowed_origins', []);
            $userHandleSecretValue = config('passkeys.user_handle_secret');
            $userHandleSecret = is_string($userHandleSecretValue) ? $userHandleSecretValue : '';
            $userHandleSecretDeclared = config('fortify.passkeys.user_handle_secret_declared') === true;

            // 文字列以外が混ざった config を **黙って除去しない** (除去すると設定破損を見逃す)。
            // trusted hosts / proxies 側の stringList() は「silent drop を raw で表面化させる」形だが、
            // passkeys は config が必ず string 列を返す設計なので、破損はそのまま violation にする。
            if (! $this->isStringList($originsValue) || ! $this->isStringList($rawOriginsValue)) {
                $errors[] = 'passkeys.allowed_origins and fortify.passkeys.raw_allowed_origins must be lists of strings.';
            } else {
                try {
                    (new PasskeyConfigValidator)->validateForProduction(
                        $relyingPartyId,
                        $originsValue,
                        $rawOriginsValue,
                        $userHandleSecretDeclared,
                        $userHandleSecret,
                    );
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        return $errors;
```

### tests/Architecture/PasskeyPackageContractTest.php (版の固定ブロック)
```php
/*
 * 版 pin。laravel/passkeys は **0.x** であり semver の後方互換保証が無い
 * (0.3.0 で設定キー名・contract・route 名が予告なく変わりうる)。
 * 本ファイルの他の契約検査と config/fortify.php の passkeys ブロックのキー名は
 * **0.2 系に対して検証する契約**であり、その前提が黙って動かないように 2 つの側面を固定する:
 *
 *   - composer.json の直接要求 = 「直接 import しているので直接要求する」設計意思と許容範囲。
 *     これが無いと laravel/fortify の推移要求が緩んだ瞬間に 0.3 系が無言で入る。
 *   - composer.lock の解決値 = **いま実際に動いている版**。
 *     制約だけ見ても、lock が手で書き換えられた / platform 設定で別版が入った場合を捕まえられない。
 *
 * 0.2.x を外れるときは、本ファイルの契約検査 (route 名 7 本 / confirmPassword /
 * limiter / モデル差し替え / Response contract 4 本 / binder / 写像 sentinel) と
 * Fortify の configurePasskeys() が読むキー名 (fortify.passkeys.*) を
 * 再確認してから、この pin を更新すること。
 */

/** @return array<string, mixed> composer.json の require ブロック */
function composerRequireBlock(): array
{
    $raw = file_get_contents(base_path('composer.json'));
    expect($raw)->toBeString();
    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBeArray();
    /** @var array<string, mixed> $decoded */
    $require = $decoded['require'] ?? null;
    expect($require)->toBeArray();

    /** @var array<string, mixed> $require */
    return $require;
}

/** composer.lock の解決版 (例 "v0.2.1") を返す */
function lockedPackageVersion(string $name): ?string
{
    $raw = file_get_contents(base_path('composer.lock'));
    expect($raw)->toBeString();
    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    $packages = $decoded['packages'] ?? [];
    expect($packages)->toBeArray();

    /** @var array<int, array<string, mixed>> $packages */
    foreach ($packages as $package) {
        if (($package['name'] ?? null) === $name && is_string($package['version'] ?? null)) {
            return $package['version'];
        }
    }

    return null;
}

test('composer.json が laravel/passkeys を直接要求する (直接 import しているため)', function (): void {
    $require = composerRequireBlock();

    // ★`toHaveKey($key, $value)` の第 2 引数は**期待値**であってメッセージではない。
    //   ここで見たいのは「キーが在ること」なので array_key_exists を明示的に検査する。
    expect(array_key_exists('laravel/passkeys', $require))->toBeTrue(
        'laravel/passkeys を直接 import しているのに直接要求が無い。'
        .'laravel/fortify の推移要求が緩むと 0.3 系が無言で入る'
    );

    $constraint = $require['laravel/passkeys'];
    expect($constraint)->toBeString();
    /** @var string $constraint */
    // 書き方は caret 1 種類に絞る (composer.json の他 20 件超がすべて caret のため)。
    // **前方一致では不十分**: `^0.20` / `^0.2 || ^1.0` / `^0.2.1 || ^0.3` / `^0.2@dev` が通り、
    // 特に `|| ^0.3` は「0.3 系を入れない」というこの検査の目的を破る。
    expect(preg_match('/^\^0\.2(?:\.\d+)?$/', $constraint))->toBe(
        1,
        "laravel/passkeys の制約は '^0.2' か '^0.2.<patch>' の形だけを許す: {$constraint}"
    );
});

test('composer.lock の laravel/passkeys が 0.2 系 (契約検査の検証済み範囲)', function (): void {
    $version = lockedPackageVersion('laravel/passkeys');

    expect($version)->toBeString('composer.lock に laravel/passkeys が無い');
    /** @var string $version */
    expect(str_starts_with(ltrim($version, 'v'), '0.2.'))->toBeTrue(
        "laravel/passkeys の解決版が 0.2 系を外れている: {$version}。"
        .'本ファイルの契約検査と fortify.passkeys.* のキー名を再確認してから pin を更新すること'
    );
});
```

### app/Http/Middleware/EnsureLoginMethodRemains.php (handle)
```php
final class EnsureLoginMethodRemains
{
    public function __construct(
        private readonly LoginMethodInventory $inventory,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->pass($next, $request);   // 未認証は auth middleware の責務
        }

        return DB::transaction(function () use ($request, $next, $user): Response {
            // (2) 対象 User 行をロック (以降の投影評価はこのロック下でのみ有効)
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // (3) ロック取得後に投影を評価する
            $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));

            if ($remaining->isEmpty()) {
                return $this->reject($request);
            }

            // (4) 同一トランザクション内で削除まで完了させる
            return $this->pass($next, $request);
        });
    }

    /**
     * route から「今から何を除去しようとしているか」を決める。
     *
     * 対象 passkey が当該 User に属することは **binder が 404 で確定済み**
     * (App\Http\Routing\SelfScopedPasskeyBinder)。DTO 側でも二重に assert する。
     */
    private function removalFor(Request $request, User $user): LoginMethodRemoval
    {
        $passkey = $request->route('passkey');
        if ($passkey instanceof Passkey) {
            return LoginMethodRemoval::passkey($passkey, $user);
        }

        // 将来の除去 route (password 削除 / SSO 解除) はここに分岐を足す。
        // 未知の除去 route を素通しさせないため fail-closed で落とす
        // (LoginMethodRemovalRouteTest が「middleware を付けたのに分岐が無い」を先に検出する)。
        throw new LogicException(
            'EnsureLoginMethodRemains: 除去対象を決定できない route です。removalFor() に分岐を追加してください。',
        );
    }
```

### vendor/laravel/passkeys/src/Actions/DeletePasskey.php (全文)
```php
<?php

declare(strict_types=1);

namespace Laravel\Passkeys\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Passkey;

class DeletePasskey
{
    /**
     * Delete the given passkey.
     */
    public function __invoke(Authenticatable $user, Passkey $passkey): void
    {
        $passkey->delete();

        PasskeyDeleted::dispatch($user, $passkey);
    }
}
```

### tests/Unit/Support/PasskeyConfigValidatorTest.php (現行の期待。文言変更の影響範囲)
```php
<?php

declare(strict_types=1);

use App\Support\PasskeyConfigValidator;

/*
 * パスキー (WebAuthn) 設定の production 起動時検証 (T166)。
 *
 * 検査するのは **書式と相互整合**まで。「その host を実際に運用しているか」
 * 「証明書があるか」は検査できない (誇張しない)。
 */

/** 有効な baseline を作り、指定引数だけ差し替えて検証を実行する */
function validatePasskeyConfig(
    string $relyingPartyId = 'app.example.com',
    ?array $allowedOrigins = null,
    ?array $rawAllowedOrigins = null,
    bool $userHandleSecretDeclared = true,
    ?string $userHandleSecret = null,
): void {
    $allowedOrigins ??= ['https://app.example.com'];
    $rawAllowedOrigins ??= $allowedOrigins;
    $userHandleSecret ??= str_repeat('a', 32);

    (new PasskeyConfigValidator)->validateForProduction(
        $relyingPartyId,
        array_values($allowedOrigins),
        array_values($rawAllowedOrigins),
        $userHandleSecretDeclared,
        $userHandleSecret,
    );
}

test('有効な設定は例外を投げない', function (): void {
    expect(fn () => validatePasskeyConfig())->not->toThrow(RuntimeException::class);
});

test('接続元が身元の識別子の下位ドメインでも通る', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://pwa.app.example.com']))
        ->not->toThrow(RuntimeException::class);
});

test('接続元の port 付き宣言が通る', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://app.example.com:8443']))
        ->not->toThrow(RuntimeException::class);
});

// --- 検査 1: 身元の識別子が空 ---

test('身元の識別子が空なら例外', function (): void {
    expect(fn () => validatePasskeyConfig(relyingPartyId: ''))
        ->toThrow(RuntimeException::class, 'relying party id is empty');
});

// --- 検査 2: 身元の識別子の DNS 名検査 (負のコントロール) ---

test('身元の識別子が production の DNS 名でないなら例外', function (string $relyingPartyId): void {
    expect(fn () => validatePasskeyConfig(
        relyingPartyId: $relyingPartyId,
        // 接続元は身元の識別子と一致させ、検査 6 ではなく検査 2 で落ちることを確かめる
        allowedOrigins: ['https://'.$relyingPartyId],
    ))->toThrow(RuntimeException::class, 'not an accepted production DNS name');
})->with([
    'localhost (単一ラベル)' => 'localhost',
    'IPv4 リテラル' => '192.0.2.1',
    'IPv4 の書き損じ (filter_var では IP と認められない)' => '192.168.001.001',
    'ハイフン開始' => '-example.com',
    'ハイフン終了' => 'example-.com',
    '連続ドット' => 'example..com',
    '先頭ドット' => '.example.com',
    '末尾ドット' => 'example.com.',
    '空白混じり' => 'exam ple.com',
    'IPv6 リテラル' => '2001:db8::1',
    // 宣言側 (config/fortify.php) は小文字化するので、ここに大文字が届くのは
    // 別経路が未正規化のまま設定した場合だけ。その値は webauthn-lib の strict 比較に
    // 一致せず全手続きを無言で失敗させるため、起動時に落とす。
    '大文字を含む (別経路の未正規化値)' => 'APP.example.com',
]);

/*
 * 既知の限界 (documented limitation)。
 * 本 validator は Public Suffix List を持たないため、`co.uk` のような public suffix を
 * 身元の識別子に置いた設定は通ってしまう (ブラウザ側は PSL を見るので実際の手続きは失敗する)。
 * PSL 判定のために依存を足すことはしない (誤設定の結果は「パスキーが使えない」であって
 * 権限昇格ではなく、設定するのは攻撃者ではなく運用者であるため)。
 * PSL 判定を入れたらこのテストが赤くなり、設計変更に気づける。
 */
test('既知の限界 (documented limitation): public suffix の身元識別子は通る', function (): void {
    expect(fn () => validatePasskeyConfig(
        relyingPartyId: 'co.uk',
        allowedOrigins: ['https://co.uk'],
    ))->not->toThrow(RuntimeException::class);
});

// --- 検査 3: 生の接続元列に空要素 ---

test('生の接続元列に空要素があれば例外 (有効値と併存していても落ちる)', function (): void {
    expect(fn () => validatePasskeyConfig(
        allowedOrigins: ['https://app.example.com'],
        rawAllowedOrigins: ['https://app.example.com', ''],
    ))->toThrow(RuntimeException::class, 'empty entry');
});

// --- 検査 4: 接続元が空 ---

test('接続元が 1 件も無ければ例外', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: [], rawAllowedOrigins: []))
        ->toThrow(RuntimeException::class, 'allowed origins are empty');
});

// --- 検査 5: 接続元の書式 ---

test('接続元の書式が不正なら例外', function (string $origin): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: [$origin]))
        ->toThrow(RuntimeException::class, 'is invalid');
})->with([
    '平文 http' => 'http://app.example.com',
    'scheme が大文字' => 'HTTPS://app.example.com',
    'host が大文字' => 'https://APP.example.com',
    '末尾スラッシュ' => 'https://app.example.com/',
    'path 付き' => 'https://app.example.com/path',
    'userinfo 付き' => 'https://user@app.example.com',
    'query 付き' => 'https://app.example.com?x=1',
]);

test('接続元の port が範囲外なら例外', function (string $origin): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: [$origin]))
        ->toThrow(RuntimeException::class, 'out-of-range port');
})->with([
    'port 0' => 'https://app.example.com:0',
    'port 70000' => 'https://app.example.com:70000',
]);

// --- 検査 6: 身元の識別子と接続元の相互整合 ---

test('接続元が別ドメインなら例外', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://evil.example.net']))
        ->toThrow(RuntimeException::class, 'does not belong to');
});

test('接尾辞が一致するだけの host は例外 (接尾辞一致だけの実装なら通ってしまう境界)', function (): void {
    // "notapp.example.com" は ".app.example.com" で終わらないので下位ドメインではない。
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://notapp.example.com']))
        ->toThrow(RuntimeException::class, 'does not belong to');
});

// --- 検査 7: 導出鍵 ---

test('導出鍵が未宣言なら例外 (値が十分長くても落ちる)', function (): void {
    expect(fn () => validatePasskeyConfig(
        userHandleSecretDeclared: false,
        userHandleSecret: str_repeat('a', 64),
    ))->toThrow(RuntimeException::class, 'PASSKEYS_USER_HANDLE_SECRET is not set');
});

test('導出鍵が 32 文字未満なら例外', function (): void {
    expect(fn () => validatePasskeyConfig(userHandleSecret: str_repeat('a', 31)))
        ->toThrow(RuntimeException::class, 'shorter than 32 characters');
});

// --- 検査の順序 ---

test('複数違反があるときは最初の違反 (身元の識別子) を報告する', function (): void {
    expect(fn () => validatePasskeyConfig(
        relyingPartyId: '',
        userHandleSecretDeclared: false,
    ))->toThrow(RuntimeException::class, 'relying party id is empty');
});
```
