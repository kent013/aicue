# 対応マトリクス: design-review Round 1

## [Warning] 施策2: 非 Inertia JSON ログアウトでも `Inertia::clearHistory()` を無条件実行するのは副作用
- 判断: **反論する（無条件実行を維持）。ただし挙動をテストで明示固定する**
- 根拠:
  1. **`X-Inertia` で分岐すると逆にセキュリティホールになる。** 非 Inertia の XHR ログアウトでも、
     そのタブには Inertia の暗号化履歴が残っている。実例が既にリポジトリ内にある:
     `tests/Browser/AuthenticatedPageBfcacheTest.php` の `bfcacheLogoutInBrowser()` は
     Inertia 画面から `fetch('/logout', { Accept: 'application/json' })` でログアウトしている。
     ここで `clearHistory()` を止めると、**Inertia 履歴が復号可能なまま残る** = F-4-01 の再発。
     「このブラウザの履歴はもう無効」という事実はクライアント種別に依存しない。
  2. **フラグが宙に浮いて悪さをするケースは実質無い。** `clearHistory()` は
     `session()->invalidate()` の**後**に走るため、フラグは**ログアウト後の guest session** に載る。
     その後 Inertia 応答が来れば消費される (`/login` でも `/` でも)。
     唯一の残余は「JSON ログアウト後、Inertia 応答を一度も描画しないまま再ログインした」場合で、
     `session()->regenerate()` はデータを引き継ぐためフラグが残り、ログイン後最初の Inertia 応答で
     `history.clear()` が走る。**これは無害かつ自己修復的**: 消えるのはログイン前 (guest) の
     履歴エントリの復号可能性だけで、以降のエントリは新しい鍵で暗号化される。
     セキュリティ的にはむしろ安全側。
  3. 分岐を入れると「どの経路で clearHistory が走るか」がクライアント種別に依存し、
     防御の成立条件が読み手に見えなくなる (原則: 条件分岐で不変条件を弱めない)。
- 対応内容: 実装は無条件のまま維持し、
  (a) `LogoutResponse` の docblock に上記 1〜3 を根拠として明記、
  (b) Feature テストに **「JSON ログアウトでも次の Inertia 応答で `clearHistory` が消費される」**
      ケースを追加して、この挙動を偶然ではなく契約として固定する。

## [Warning] 施策3: 実運用経路 (`X-Inertia` ヘッダ付きの Inertia visit) そのものの保証が弱い
- 判断: **対応する**
- 根拠: 指摘のとおり。実ブラウザのログアウトは `X-Inertia` 付き XHR で、応答は
  302 → XHR が追従 → 着地は **JSON の page オブジェクト**になる。
  現行案は root view (`viewData('page')`) 経由しか見ておらず、実経路を直接は縛れていない。
- 対応内容: Feature テストに
  「`X-Inertia` 付きで `POST /logout` → 着地 `GET /` (`X-Inertia` + `X-Inertia-Version`) の
  **JSON page に `clearHistory: true` が載る**」ケースを追加する。
  version は `Inertia::getVersion()` から取る (不一致だと 409 になるため)。

## [Suggestion] 施策3: 「1 度きり」テストの `/pricing` 依存
- 判断: **対応する**
- 根拠: `/pricing` が将来非 Inertia 化すると `inertiaPagePayload()` が落ちて意図と違う失敗になる。
- 対応内容: 2 回目の取得も `route('home')` にして、契約テストが依存する route を
  「ログアウト着地 = Inertia 応答」の 1 本に集約する。

## [Warning] 施策4: `assertDontSee()` は「一瞬表示されて消えた」を取り逃す
- 判断: **対応する**
- 根拠: 妥当。本件の要件は「PII を**一度も描画しない**」であり、
  終状態だけを見る assertion では要件を証明できない。
  設計上は「復号失敗時に swap 自体が起きない」ので瞬間露出は無いはずだが、
  **その「はず」をテストで機械的に固定する**のがテストファーストの趣旨。
- 対応内容: `back()` の**前**に `MutationObserver` を仕込み、
  DOM 変化のたびに PII 文字列の出現を監視して `window.__piiSeen` に記録する。
  遷移完了後に `__piiSeen === false` を検証する (初期状態も 1 度チェックする)。

## [Suggestion] 施策4: 暗号化検知をヘルパへ抽象化
- 判断: **一部対応する**
- 根拠: 過度な抽象化は不要だが、判定式が 2 箇所以上に出るならヘルパ化の価値はある。
- 対応内容: 判定式は 1 箇所のみなのでヘルパは作らず、
  「Inertia が history state を ArrayBuffer で保存するという前提に依存している」ことを
  コメントで明示し、Inertia 更新時にここを見直す旨を書く。

## [Suggestion] 施策2/5/6 のその他
- 着地の Inertia 契約テストは既に施策 3 に含まれている (追加対応なし)。
- 施策 1・5・6 は APPROVE。変更なし。
