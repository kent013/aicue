# 対応マトリクス: impl-review Round 1

## [Critical] `docs/architecture.md` (S11) が差分に無い
- 判断: **反論する** (指摘は事実誤認。ただし原因は Claude 側の差分抽出範囲)
- 根拠: S11 は実装済みである。Round 1 に渡した `git diff` を
  `app/ resources/ tests/ routes/ database/ config/` に限定していたため、`docs/` が
  差分に現れなかった。`docs/architecture.md` の §撮影 PWA には
  「サムネイル生成 (media queue。T183)」「サムネイルは容量 Quota に計上する (事後計上)」
  「サムネイルについて保証しないもの」の 3 項目を追記済みで、worker 契約
  (`queue:work database-media --timeout=240`) と時間予算の連鎖
  (ffmpeg 60 < job 180 < worker 240 < retry_after 300) も明記している。
- 対応内容: Round 2 で `docs/` と `.claude/` を含む差分を渡し、実物を確認してもらう。

## [Critical] bug-hunt 注釈 (S6) と生成物が差分に無い
- 判断: **反論する** (同上。差分抽出範囲の問題)
- 根拠: `.claude/skills/app-bug-hunt/inventory/annotations.toml` へ
  `[routes."capture.takes.thumbnail"]` (kind=画面 / story=S3 / kubun=通常) を追記し、
  `python3 scripts/bug-hunt-inventory.py generate` で `screens.md` / `operations.md` を
  再生成済み (手書きしていない)。`scripts/bug-hunt-inventory-check.sh` は exit 0
  (画面 69 件 / 操作 79 件で一致)。
- 対応内容: Round 2 の差分に `.claude/skills/app-bug-hunt/` を含める。

## [Warning] `TakeObjectStorageTest` の ContentType 検証が保証範囲を誇張している
- 判断: **対応する**
- 根拠: 指摘のとおり `Storage::fake('s3')` はローカル disk で、`mimeType()` は
  **拡張子から導出**される。`.jpg` を書けば option を渡さなくても `image/jpeg` になるため、
  この assert には**負のコントロールが無い** (実装から option を消しても緑のまま)。
  「ContentType 付きで書かれる」というテスト名は嘘になる。
- 対応内容: テスト名を「往復が同一バイト列になる」へ変更し、`mimeType()` の assert を削除。
  代わりに**何をどこで保証しているか**をコメントで明示した
  (ContentType の実記録は fake adapter の sidecar を見る `FakeStorageRouteTest` が担う /
  実 S3 の応答ヘッダは本タスクでは保証しない)。

## [Warning] `CaptureShow.test.ts` の期待値ブロックのインデント崩れ
- 判断: **対応する**
- 根拠: 一括置換でネストの深い呼び出し (`vi.waitFor` の中) だけインデントが浅くなっていた。
  `pnpm lint` の走査根は `resources/js` のみなので機械では落ちないが、読みにくい。
- 対応内容: 該当 2 箇所のうち崩れていた 1 箇所を修正した。
