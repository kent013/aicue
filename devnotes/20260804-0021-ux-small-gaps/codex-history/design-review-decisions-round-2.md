# 対応マトリクス: design-review Round 2

判定: B-1 REQUEST_CHANGES / C REQUEST_CHANGES (Critical) / A-1・A-2 REQUEST_CHANGES /
B-2 APPROVE → 全体 CHANGES_REQUESTED。**すべて対応する** (反論なし)。

## [Warning] B-1-1: toast 3 秒待機の後に着地を 2 秒待つと制御条件 (iii) を判定できない

- 判断: **対応する**
- 根拠: 決定的に正しい。「toast 失敗 → その後 2 秒で着地を確認」だと、
  4 秒目に着地したケースまで「着地済み = 制御条件を満たした fail」と誤分類する。
- 対応内容: **同一の 3 秒ループ内で toast と着地の両方を観測**する helper に変更する。
  戻り値を `{toastVisible, landedWithinDeadline, elapsedMs}` にし、
  toast 失敗時は**追加待機せず**その記録だけで分類する。

## [Critical] C-1: in-flight ガードは「画面状態を破棄した後に古い Promise が完了する」競合を防げない

- 判断: **対応する (世代番号を導入)**
- 根拠: 決定的に正しい。指摘どおり 2 つの実害がある。
  1. 取得中に confirm / disable が成功 → `resetEnrollmentAssets()` で消したはずの
     `qrSvg` / `setupKey` が、遅れて解決した fetch で**再格納**される (secret の画面残置)。
  2. 古い run が `loadingEnrollmentAssets` を握ったままになり、
     直後の再有効化が in-flight ガードで**拒否**される (enrollment が始まらない)。
- 対応内容: `enrollmentGeneration` (number) を導入する。
  - `loadEnrollmentAssets()` は開始時に `const generation = ++enrollmentGeneration;` を取り、
    解決後に `generation !== enrollmentGeneration` なら**結果も loading 状態も反映しない**。
  - `resetEnrollmentAssets()` は `enrollmentGeneration += 1` して進行中の取得を無効化し、
    `loadingEnrollmentAssets = false` も自分で戻す。
  - in-flight 早期リターンは**撤去**する (後着優先になるため不要)。
  - `finally` で loading を戻さない (古い run が新しい run の loading を消さないため)。

## [Warning] C-2: 上記 lifecycle 競合のテストが無い

- 判断: **対応する**
- 対応内容: `SettingsSecurityTwoFactorConfirm.test.ts` に追加:
  「fetch 保留中に confirm 成功を発火 → その後 fetch を解決しても
  `two-factor-setup-key` が再表示されない」。
  併せて「reset 後に再有効化すると新しい取得が走る (loading が解放されている)」も固定する。

## [Warning] A-1/A-2-1: cross-layout (AppLayout → GuestLayout/AuthLayout) の遷移が E2E で覆われていない

- 判断: **対応する**
- 根拠: 妥当。A-1 の目的そのもの (アカウント削除 → `/` で成功 toast) は、
  旧 layout の `ToastContainer.onDestroy` と新 layout の flash 消費の**順序**に依存する。
  B-1 (AppLayout → AppLayout) では覆えない。
- 対応内容: `tests/Browser/FlashToastTest.php` に **2 本目**を追加する。
  「UI ログイン (recent-auth が `StampRecentAuthOnLogin` で stamp される) → `/settings` →
  アカウント削除 → `/` (GuestLayout) で `toast-success` が可視」。
  - `$this->actingAs()` は `Login` イベントを発火しないため recent-auth が stamp されない。
    したがって**この 1 本だけは UI ログイン**で始める (ハーネス内部仕様に依存しない)。
  - B-2 の適用条件を「B-1 の 2 本のうち**いずれか**が制御条件つきで fail した場合」に拡張する。

## [APPROVE] B-2

- 適用条件の拡張のみ反映 (上記)。
