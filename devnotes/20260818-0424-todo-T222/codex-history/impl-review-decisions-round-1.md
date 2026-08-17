# 対応マトリクス: impl-review Round 1

## [Warning] 通知中継の実測が `success` だけで、`keep([self::SUCCESS])` へ縮めても全検査が緑のまま

- 判断: **対応する**
- 根拠: 指摘のとおり。跳ね返り 2 本は代表値でよいが、それだけだと
  「延命されるキー集合そのもの」がどのテストにも固定されていない。
  中継の契約はキー集合であり、middleware に依存しないので
  `relayTo()` を直に呼ぶ形で dataset 化すれば、2 middleware × 4 キーへ広げずに閉じられる
  (詳細設計もこの分岐を許容していた: 「dataset で 4 キーを回してもよい」)。
- 対応内容: `tests/Feature/Inertia/FlashNotificationRelayBounceTest.php` に 2 本追加した。
  - 「中継は NOTIFICATION_KEYS の全キーを 1 hop 延命し、その次で失効する」
    (`->with(FlashNotificationRelay::NOTIFICATION_KEYS)` の dataset。
     `ageFlashData()` を要求境界に見立てて 3 hop 進め、2 hop 目で残り 3 hop 目で消えることを固定)
  - 「中継は通知キー以外を延命しない (負のコントロール)」(`new_api_key` が 1 hop で失効する)
  ファイル冒頭の docblock も「代表値 + 実装で一意に決まる」から
  「キー集合は dataset で固定する」へ書き換えた。

## [Critical] 必須検証コマンドが未完了 (`composer test` / `pnpm test` / `pnpm test:packages`)

- 判断: **対応する** (Round 1 提出時点ではグローバルテストロック待ちで実行中だった)
- 根拠: リポジトリ規約どおり全 green でなければコミットできない。
- 対応内容: 全数を完走させた。結果は Round 2 のプロンプトに転記した
  (`composer test` 5791 tests / 5789 passed / 2 skipped / 0 failed、
   `pnpm test` 161 files 2009 tests passed、`pnpm test:packages` 10 files 106 tests passed)。

## その他 (指摘なし)

`FlashNotificationRelay.php` / 2 つの middleware / `HandleInertiaRequests` /
`flash-to-toast.ts` / PHP・TS の 2 本の drift gate は「問題なし」判定のため変更しない。
