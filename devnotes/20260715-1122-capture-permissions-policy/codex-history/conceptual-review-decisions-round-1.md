# 対応マトリクス: conceptual-review Round 1

## [Warning] microphone 緩和は過剰 / 今必要なものだけ作るに反する (観点1・4・5・6 で反復)
- 判断: 反論する
- 根拠: 撮影 recorder は `resources/js/components/features/capture/CameraRecorder.svelte` L177-179 で
  `getUserMedia({ video, audio: true })` を要求している。W3C Media Capture 上、Permissions-Policy で
  許可されない kind が 1 つでも含まれると getUserMedia 呼び出し**全体**が reject される。
  したがって `microphone=()` のままでは camera を (self) にしても撮影は起動できない
  (audio track 取得が Permissions-Policy で拒否される)。microphone=(self) は「あったら便利」ではなく
  v1 の現行撮影コードが現に要求している必須値。
- 対応内容: 概念設計の「事実」節に CameraRecorder の audio:true と getUserMedia 全体 reject の因果を追記。
  microphone=(self) は維持。

## [Warning] テスト計画が設計に落ちていない (観点2)
- 判断: 対応する
- 根拠: AGENTS.md 禁止事項 #1 (不変条件変更はテスト登録まで含めて実装済み)。
- 対応内容: 概念設計に「テスト観点 (概念)」節を追加 (capture 緩和 / 非 capture 厳格維持 / 他 directive 回帰 /
  opt-out 非送出 / 既存テスト非退行)。詳細設計で Pest Feature テストとして具体化する。

## [Warning] /app/* 全域緩和は XSS 影響半径を広げる。専用 middleware を必要ページのみに付ける案を検討 (観点5・6)
- 判断: 反論する (一部説明を補強)
- 根拠:
  (a) 緩和は `camera=(self)` / `microphone=(self)` = **同一オリジン self のみ**で cross-origin allowlist は開かない。
  (b) capture group は全て同一オリジン・session 認証の小さな凝集 group。実際に camera を使うのは
      `capture.manuals.show` (recorder ページ) だが、同一オリジンの XSS は結局その show ページ経由で
      camera を要求できるため、他の capture ページに header を付けても付けなくても blast radius は縮まらない。
  (c) 書き込み系 (takes.*) は XHR JSON 応答であり、Permissions-Policy は document の browsing context に効く
      ヘッダで JSON XHR 応答には実効しない = 無害。
  (d) 1 ルートに専用 middleware を付けるのは AGENTS.md 思考原則「今必要なものだけ / フレームワークのレンジ内で最小」に反し、
      得られる安全性向上に乏しい。route group 単位が適切な altitude。
- 対応内容: 設計方針は route group (routeIs('capture.*')) 単位を維持。反論根拠を decisions に記録。

## [Suggestion] getName() prefix 比較より $request->routeIs('capture.*') を使え (観点3)
- 判断: 対応する
- 根拠: 意図が明確で null 安全 (未解決ルートでも false)。Laravel 標準 = 先人の知恵。
- 対応内容: 概念設計の実装方針を routeIs('capture.*') に更新。

## [Suggestion] header 値選択を private helper に閉じ戻り値を ?string に (観点7)
- 判断: 対応する
- 根拠: null/空 opt-out contract を型で表現でき PHPStan L10 に素直。
- 対応内容: `resolvePermissionsPolicy(Request): ?string` helper を実装方針に追加。

## [Suggestion] 期待効果を「camera 権限解放」に限定 / 初回 HTML 応答で緩和されることを明示
- 判断: 一部対応
- 根拠: 効果の主眼は video capture の unblock だが、上記のとおり audio も同時に必要。
- 対応内容: 効果記述は「PWA ナビ撮影 (video+audio) の unblock」とし、初回 Inertia GET (manuals.show) 応答で
  緩和される旨は詳細設計のテストで担保 (capture.manuals.show の GET 応答を検証)。
