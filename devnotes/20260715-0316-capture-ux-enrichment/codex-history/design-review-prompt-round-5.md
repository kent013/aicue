# 詳細設計レビュー Round 5（対応報告 + 一部反論）

Round 4 の指摘に対応しました。機械的懸念（handle 喪失）は採用し、世代 ID は過剰と判断して反論します。

## 対応 + 反論

### [Warning] 同種操作の世代識別 / 古い callback による新 timeout handle 喪失

**採用（handle 自己同定ガード）**: 「古い callback が新しい timeout handle を null 化し得る」機械的懸念に対し、timeout handle の自己同定ガードを追加:
```ts
function armPauseResumeTimeout(op: PauseResumeOperation): void {
    clearPauseResumeTimeout();
    const handle = setTimeout(() => {
        if (pauseResumeTimeout !== handle) return; // 古い callback は新 handle を触らない（早期 return）
        pauseResumeTimeout = null;
        if (pendingOperation !== op) return;
        pendingOperation = null;
        recoverPhaseFromRecorderState();
    }, 2000);
    pauseResumeTimeout = handle;
}
```
これで古い callback が走っても `pauseResumeTimeout !== handle` で早期 return し、新しい handle を null 化せず・新しい pending を解除しません。

**反論（世代 ID は導入しない）**: 「操作ごとの世代 ID/token」は本設計では過剰と判断します（禁止事項6 やたらに複雑な案 / 思考原則 今必要なものだけ作る）。根拠:
1. MediaRecorder の onpause/onresume には識別子が無く、同種イベントは本質的に区別不能。かつ同種操作は同じ終状態（paused/recording）を意図するため conflate しても無害。世代 ID を足しても「この onpause はどの pause 要求のものか」は原理的に判別できず、得られる正しさが無い。
2. requestPause/Resume は `pendingOperation !== null` で多重押下を弾き、`armPauseResumeTimeout` は clear-before-arm のため**生存 timeout は常に 1 つ**。handle 自己同定ガードと合わせ、古い callback が新状態を壊す経路は塞がれている。
3. MediaRecorder のイベント順序保証を前提とする旨をコメント明記済み。

### [Warning] S7 同種交差テスト
対応: 「古い pause タイムアウト（遅延）→ 新しい pause 要求 arm 後に古い callback 発火 → `!== handle` で新 pending/timeout handle が維持される」ケースを追加。

---

handle 自己同定ガードで機械的な handle 喪失は解消され、世代 ID なしでも同種交差に対して安全です。この判断が妥当か、残 Critical/Warning がないか判定してください。全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
