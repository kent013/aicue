// bfcache 復元が Playwright で再現できるかの単独検証。
// 仮説: playwright-core が既定で付ける --disable-back-forward-cache を外せば
//       Chromium で pageshow.persisted === true を観測できる。
// アプリには一切触れない。http サーバを立てて 2 ページ間を戻るだけ。
import http from 'node:http'
import { chromium, webkit } from '/workspace/.claude/worktrees/tasks/T082/node_modules/.pnpm/node_modules/playwright-core/index.mjs'

const page = (name) => `<!doctype html><html><body><h1>${name}</h1>
<a id="go" href="/b">go</a>
<script>
  window.__persisted = null;
  window.addEventListener('pageshow', (e) => { window.__persisted = e.persisted; });
</script></body></html>`

const server = http.createServer((req, res) => {
  res.setHeader('Content-Type', 'text/html; charset=utf-8')
  // bfcache 判定に効くので明示的にキャッシュ指示は付けない
  res.end(page(req.url === '/b' ? 'B' : 'A'))
})
await new Promise((r) => server.listen(0, '127.0.0.1', r))
const base = `http://127.0.0.1:${server.address().port}`

async function probe(label, browserType, launchOpts) {
  let browser
  try {
    browser = await browserType.launch(launchOpts)
    const ctx = await browser.newContext()
    const p = await ctx.newPage()
    await p.goto(`${base}/a`)
    await p.click('#go')
    await p.waitForURL(`${base}/b`)
    await p.goBack()
    await p.waitForURL(`${base}/a`)
    const persisted = await p.evaluate(() => window.__persisted)
    console.log(`${label}: pageshow.persisted = ${persisted}`)
    return persisted === true
  } catch (e) {
    console.log(`${label}: ERROR ${e.message.split('\n')[0]}`)
    return false
  } finally {
    if (browser) await browser.close()
  }
}

const results = {}
results['chromium (既定 = --disable-back-forward-cache あり)'] =
  await probe('chromium/default', chromium, { headless: true })
results['chromium (ignoreDefaultArgs で bfcache 無効化を外す)'] =
  await probe('chromium/bfcache-enabled', chromium, {
    headless: true,
    ignoreDefaultArgs: ['--disable-back-forward-cache'],
  })
results['webkit (既定)'] = await probe('webkit/default', webkit, { headless: true })

server.close()
console.log('\n=== 結果 ===')
for (const [k, v] of Object.entries(results)) console.log(`${v ? 'RESTORED' : 'no-restore'}  ${k}`)
