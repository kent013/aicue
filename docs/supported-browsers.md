# サポート対象ブラウザ方針

AI-CUE が「どのブラウザで、どのレベルまで動作を保証しているか」の正本。
`no-store` baseline (`NoStoreCacheHeadersForAuthenticatedPages`) と bfcache 秘匿・再検証
(`resources/js/lib/bfcache-guard.ts`) の**保証範囲を語るための前提**として置く。

「対応している」という言葉を検証レベルと切り離さないこと。
本書では **Current (実際に回っている検証)** と **Target (到達目標)** を分けて書く。

## 対象ブラウザ

撮影 PWA と管理画面はプラットフォーム前提が違うため分けて定義する。

| 面 | URL 空間 | 主要ブラウザ |
|----|----------|--------------|
| **撮影 PWA** | `/app/*` (`manifest.webmanifest`, ホーム画面追加) | **iOS Safari** (standalone 含む) / Android Chrome |
| **管理画面** | 上記以外 | デスクトップ Chrome / Edge / Firefox / Safari |

撮影 PWA が中核 (使命 = 現場作業者がスマホで撮る) であり、**iOS Safari が最重要**。
bfcache 周りの設計判断はすべてこの前提から来ている
(Safari は `Cache-Control: no-store` のページでも bfcache に格納しうる)。

## Current — マージ後に実際に保証していること

| 区分 | 対象 | 扱い |
|------|------|------|
| **自動回帰テスト (恒久)** | **Chromium + WebKit** (Playwright / pest-plugin-browser) | `composer test:browser` が両レーンを実行する。カバーしているのは**秘匿の配線** (pagehide で秘匿属性が付き実描画が止まる / pageshow でプローブが走り秘匿が解ける) と**通常遷移で誤発火しないこと**。**bfcache 復元そのものは下記の理由でカバーできていない** |
| **ユニット (vitest)** | `tests/js/lib/bfcache-guard.test.ts` | guard の分岐 (persisted 有無 / 秘匿属性 有無 / プローブ成功・失敗・エラー / 再試行) と負のコントロールを固定。**復元シナリオの分岐ロジックはここが唯一の恒久回帰** |
| **実機受入確認 (手動)** | **iOS Safari 実機** (PWA standalone 含む) | **「恒久テスト済み」とは表現しない**。実施したら**日時・端末・OS バージョン・結果**を devnotes に記録する |

レーンの実行方法・前提は `docs/testing-browser.md`。

### bfcache 復元が自動回帰でカバーできていない理由 (実測)

**Playwright は自動化インスペクタを接続した状態でブラウザを起動するため、Chromium /
WebKit のどちらも「戻る」で bfcache 復元を行わない**。
`Cache-Control: no-store` の付かない公開ページ間ですら、戻ると JS 実行コンテキストごと
作り直される (= 通常の再取得) ことを実測している。

そのため `tests/Browser/AuthenticatedPageBfcacheTest.php` のシナリオ 2〜4 は、
**ハーネスの bfcache 再現能力を毎回実測**し、再現できない環境では理由付きで skip する。
再現できる環境 (将来ツール側が対応した場合) では、
`pageshow.persisted === true` を観測できなければ**失敗する**正のコントロールが効く。

**skip は合格ではない**。現時点で復元シナリオを担保しているのは
vitest のユニットテスト (分岐ロジック) と実機受入確認 (未実施) だけである。

### 実機受入確認の再確認条件

一度きりの確認では陳腐化する。**以下のいずれかに変更が入ったら再実施する**:

- `resources/js/lib/bfcache-guard.ts` (bfcache guard 本体)
- `resources/css/app.css` の秘匿オーバーレイのスタイル (`#bfcache-guard-overlay` 周辺)
- プローブ endpoint (`routes/web.php` の `session.status` /
  `App\Http\Controllers\Auth\SessionStatusController` / `SessionStatusResource`)

記録先: `devnotes/<日付>-<topic>/` に日時・端末・iOS バージョン・実施シナリオ・結果を残す。
**本書には「いつ・何を確認したか」を書かない** (記録の二重管理を作らない)。

> 現時点でこのリポジトリに iOS 実機受入確認の記録はまだない。
> **bfcache 復元後の実挙動 (PII が出ないこと) を実環境で確認できているものは無い**
> — 自動回帰が復元を再現できない以上、実機確認は**補完ではなく現状唯一の実環境検証手段**である。

## 未対応事項 (誤読を防ぐため明示列挙する)

- **どちらのレーンも bfcache 復元そのものを再現していない** (上記「実測」節)。
  Chromium は加えて、cookie 変更時に CCNS (`Cache-Control: no-store`) ページを
  bfcache から evict する仕様でもある。
- **Playwright WebKit ≠ 実機 iOS Safari**。bfcache 挙動・PWA standalone モード・
  iOS 固有の WebKit ビルド差がある。WebKit レーンの green を
  **「iOS Safari 対応を実証した」と言い換えない**。
- **Firefox / Edge のブラウザ自動テストレーンは持たない** (Firefox は `no-store` で
  bfcache 格納自体を拒否するため、本件のリスク面では最も安全側)。

## Target — 到達目標 (未達)

| 目標 | 現状 |
|------|------|
| **bfcache 復元シナリオの恒久自動回帰** (Playwright 側が bfcache を無効化しない構成、または別ハーネス) | **未達** — 現状は分岐ロジックの vitest のみ |
| iOS Safari 実機での受入確認を**定期的に**回す (再確認条件のトリガ運用) | 未着手 |
| Android Chrome 実機での撮影フロー確認 | 未着手 |

Target を Current に格上げするときは、**何をどう検証したか**を Current の表に書いてから行う。
