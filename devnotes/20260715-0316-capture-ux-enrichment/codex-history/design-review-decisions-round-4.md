# 対応マトリクス: design-review Round 4

## [Warning] S4 同種操作の世代識別 / 古い callback による新 timeout handle 喪失
- 判断: 一部対応（handle 自己同定を採用）+ 一部反論（世代 ID は過剰）
- 根拠:
  - 「古い callback が新しい timeout handle を null 化し得る」という**機械的懸念は妥当**。これは採用する。
  - ただし「操作ごとの世代 ID/token」の導入は過剰（禁止事項6 やたらに複雑な案 / 思考原則 今必要なものだけ作る）。理由: (a) MediaRecorder の onpause/onresume には識別子が無く、同種イベントは本質的に区別不能。同種は同じ終状態（paused/recording）を意図するため conflate しても無害。(b) requestPause/Resume は `pendingOperation !== null` で多重押下を弾き、`armPauseResumeTimeout` は clear-before-arm のため生存 timeout は常に 1 つ。世代 ID を足しても得られる正しさが無い。
- 対応内容: 世代 ID の代わりに **timeout handle の自己同定ガード**を追加（最小・十分）:
  ```ts
  function armPauseResumeTimeout(op: PauseResumeOperation): void {
      clearPauseResumeTimeout();
      const handle = setTimeout(() => {
          if (pauseResumeTimeout !== handle) return; // 古い callback は新 handle を触らない
          pauseResumeTimeout = null;
          if (pendingOperation !== op) return;
          pendingOperation = null;
          recoverPhaseFromRecorderState();
      }, 2000);
      pauseResumeTimeout = handle;
  }
  ```
  これで「古い callback が先に pauseResumeTimeout=null して新 handle を失う」問題は解消（`!== handle` で早期 return）。MediaRecorder イベント順序保証を前提とする旨をコメント明記。

## [Warning] S7 同種交差テスト
- 判断: 対応する
- 対応内容: 「古い pause タイムアウト（遅延）→ 新しい pause 要求 arm 後に古い callback 発火 → `!== handle` で新 pending/timeout handle が維持される」ケースを追加。
