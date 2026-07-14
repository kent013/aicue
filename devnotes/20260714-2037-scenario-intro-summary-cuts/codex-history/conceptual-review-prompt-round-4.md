Round 3 の Warning 1 点＋Suggestion 2 点に対応しました。再評価をお願いします。

- [Warning] point 不在時の要点再掲: 抽出を 3 段化。(i) point.subtitlePrimary 非空 → (ii) 0 件なら top-level step.subtitlePrimary 非空を同規則で収集 → (iii) いずれも 0 件のときのみ定型フォールバック。期待効果を「再掲可能な生成内容がある場合に要点再掲、無い稀なケースは定型の完了締め」に限定明記。
- [Suggestion] 1 件でも上限超過: 件数削減後もなお 1 件が上限超過なら、最後に文字単位で ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS に truncate。
- [Suggestion] config typed accessor: N・truncate 長は config()->integer(...) の正整数保証 accessor で読む。

これで概念設計としての残指摘は解消したと考えます。APPROVED 可否をお願いします。
