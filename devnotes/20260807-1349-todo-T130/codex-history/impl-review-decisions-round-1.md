# 対応マトリクス: impl-review Round 1

## [Critical] `ALLOWED_URL_PATTERNS` の `:*` が userinfo 詐称を許可する (tests/Support/StrayHttpRequestGuard.php)

- 判断: **対応する**
- 根拠: 実測で確認した。`Str::is('http://127.0.0.1:*', 'http://127.0.0.1:80@api.frankfurter.dev/')`
  は **true**、その URL を Guzzle Uri でパースすると **host = `api.frankfurter.dev` / userinfo =
  `127.0.0.1:80`**。指摘のとおり「自機宛て loopback だけ許可」という不変条件が破れている。
  さらに第 2 層を外した状態で実挙動を計測したところ、**1 本目の要求は実際に外部へ送信され**、
  301 リダイレクト後の `https://api.frankfurter.dev/` で初めて framework 側が
  `StrayRequestException` を出していた (= 外部到達が起きている)。誤検知ではなく実在の穴。
- 対応内容:
  - `StrayHttpRequestGuard::LOOPBACK_HOSTS` (`ALLOWED_URL_PATTERNS` のホスト部と 1:1) を追加。
  - `matchesAllowedPattern()` / `isLoopbackHost()` / `isSmuggledLoopbackUrl()` を追加。
    「許可パターンに一致するが**パース済み実ホストが loopback でない**」URL を判定する第 2 層。
    URL がパース不能な場合は fail-closed (拒否) にする。
  - `__invoke` の先頭で第 2 層を評価し、該当したら accumulator へ記録して
    `StrayRequestException` を throw する (framework の stub handler へ到達させない)。
  - glob には「以降に `@` を含まない」を表現する手段が無いため、パターンの書き換えでは
    解けないことを定数の docblock に明記した。

## [Critical] gate が userinfo bypass を検出できない (tests/Architecture/StrayHttpEgressLaneGateTest.php)

- 判断: **対応する**
- 根拠: 指摘のとおり `strayHttpEgressPatternViolations()` はパターンの**形**しか見ておらず、
  「実質 loopback に閉じているか」を保証していなかった。
- 対応内容:
  - 定数 `STRAY_HTTP_EGRESS_SMUGGLED_URLS` (実測で許可パターンに glob 一致する userinfo 詐称 URL) を追加。
  - 本体テスト `許可判定が userinfo 詐称で loopback を騙る URL を拒否すること (第 2 層)` を追加。
    「glob では一致する」ことと「第 2 層では拒否される」ことの**両方**を固定し、
    本物の loopback は通る (偽陽性側) ことも固定した。
  - 本体テスト `LOOPBACK_HOSTS が ALLOWED_URL_PATTERNS のホスト部と 1:1 対応していること` を追加
    (片側だけ増える形骸化を防ぐ)。
  - `http://[::1]:1@evil.example/` は **URI としてパース不能**であり Guzzle が要求を組み立てられない
    (= `[::1]:*` 経由の詐称は到達不能) ことを実測し、その旨をコメントに残して母集団から除外した。
  - mutation M12 (第 2 層を常に false 化) / M13 (`LOOPBACK_HOSTS` に余分なホスト追加) で
    両テストが赤くなることを確認済み。

## [Warning] case H が userinfo 型を固定していない (tests/Feature/Support/StrayHttpRequestGuardTest.php)

- 判断: **対応する**
- 根拠: 指摘のとおり。加えて自分で mutation を回したところ、**最初に書いた case J 自体が空振り**
  していた (第 2 層を外しても緑のまま。理由はリダイレクト経路で別の stray 例外が出るため)。
- 対応内容:
  - case H に「glob 単体では userinfo 詐称を弾けない」という事実そのものの固定と、
    `isSmuggledLoopbackUrl()` の真偽両側 (詐称 = true / 本物の loopback = false /
    許可パターン非一致 = false) を追加。
  - case J (behavioral) を追加。**accumulator に記録された URL が元 URL と完全一致すること**を
    assert する形にした。`toContain('api.frankfurter.dev')` のような緩い判定では
    第 2 層の有無を区別できない (mutation M11 で実証)。
  - 詳細は `devnotes/20260807-1349-todo-T130/mutation-evidence.md` §M11。

## [OK] とされた項目 (tests/Pest.php / enum / RegistrationTest / AuthThrottleCoverageTest / ThrottleExemptionPremiseTest)

- 判断: 変更なし。
