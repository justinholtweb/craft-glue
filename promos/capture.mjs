import puppeteer from '/Users/jholt/Sites/plugin-shots/node_modules/puppeteer/lib/esm/puppeteer/puppeteer.js'
const BASE = 'https://plugin-testing.ddev.site:33019'
const OUT = process.argv[2]
const [A, B, SECTION] = process.argv.slice(3)
const only = process.argv[6] ? process.argv[6].split(',') : null
const HIDE = ['#alerts', '#puppy-panel', '#yo-panel', '#queuebert-bar', '#upr-root', '#upr-bar', '#upr-modal', '.puppy-panel', '#notifications-wrapper', '#global-footer']
const sleep = ms => new Promise(r => setTimeout(r, ms))

const browser = await puppeteer.launch({ headless: 'new', acceptInsecureCerts: true, args: ['--ignore-certificate-errors'] })
const page = await browser.newPage()
page.on('dialog', d => d.accept())
await page.setViewport({ width: 1500, height: 1000, deviceScaleFactor: 2 })
await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle2' })
const login = await page.evaluate(async () => {
  const info = await fetch('/index.php?p=actions/users/session-info', { headers: { Accept: 'application/json' } }).then(r => r.json())
  const body = new URLSearchParams({ loginName: 'admin', password: process.env.GLUE_CP_PASS || 'claudepassword', CRAFT_CSRF_TOKEN: info.csrfTokenValue })
  const r = await fetch('/index.php?p=actions/users/login', { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
  return r.status
})
console.log('login', login)
await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'networkidle2' })
console.log('url after login', page.url())

async function tidy() {
  await page.evaluate((hide) => {
    hide.forEach(s => document.querySelectorAll(s).forEach(e => e.style.display = 'none'))
    // The harness has ~150 plugins installed; their nav items say nothing about Glue.
    document.querySelectorAll('#global-sidebar nav > ul > li').forEach(li => {
      const t = li.textContent.trim()
      if (!/^(Dashboard|Entries|Assets|Users|Glue|History|Duplicates|Settings|Utilities)/.test(t)) li.style.display = 'none'
    })
    document.querySelectorAll('.alert, .announcement, #alerts').forEach(e => e.style.display = 'none')
  }, HIDE)
}

async function shot(name, url, prep, height = 1100, clip = null) {
  if (only && !only.includes(name)) return
  await page.setViewport({ width: 1500, height, deviceScaleFactor: 2 })
  await page.goto(BASE + url, { waitUntil: 'load', timeout: 90000 })
  await sleep(2500)
  await tidy()
  if (prep) await prep()
  await sleep(600)
  if (clip) {
    const el = await page.evaluateHandle(clip)
    await el.asElement().screenshot({ path: `${OUT}/${name}.png` })
  } else {
    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false })
  }
  console.log('shot', name)
}

await shot('glue-merge', `/admin/glue/merge?a=${A}&b=${B}`, async () => {
  for (const h of ['releaseBody', 'releaseTopics', 'releaseBlocks']) {
    await page.click(`label[for="glue-${h}-combine"]`)
  }
  await sleep(2500)
}, 2100, () => document.getElementById('glue-fields').closest('.glue-panel'))

await shot('glue-index', `/admin/entries/promoReleases`, async () => {
  // The shared harness is often busy with other plugins' queue jobs; the index loads slowly.
    await page.waitForSelector(`tr[data-id="${A}"] .checkbox`, { timeout: 120000 })
  // The harness's admin was renamed by another plugin's tests; the author column is noise here.
  await page.evaluate(() => {
    const ths = [...document.querySelectorAll('table.data thead th')]
    const i = ths.findIndex(th => /Authors|Expiry/.test(th.textContent))
    ths.forEach((th, n) => { if (/Authors|Expiry/.test(th.textContent)) { th.style.display = 'none'; document.querySelectorAll(`table.data tbody tr > :nth-child(${n + 1})`).forEach(td => td.style.display = 'none') } })
  })
  // Other plugins' test sections share this harness; keep the sidebar to this fixture's.
  await page.evaluate(() => {
    document.querySelectorAll('[data-key^="section:"], [data-key^="*"]').forEach(a => {
      const t = a.textContent.trim()
      const mine = a.classList.contains('sel') || ['All entries', 'Events', 'Topics'].includes(t)
      const li = a.closest('li')
      if (li && !mine) li.style.display = 'none'
    })
    // An emptied group heading (Structures) is left alone if anything under it survived.
    document.querySelectorAll('[data-key]').forEach(() => {})
  })
  for (const id of [A, B]) await page.click(`tr[data-id="${id}"] .checkbox`)
  await sleep(700)
  const handle = await page.evaluateHandle(() => [...document.querySelectorAll('button.btn.secondary.menubtn')].find(b => b.offsetParent && !b.textContent.trim()))
  await handle.asElement().click()
  await sleep(900)
}, 1000)

await shot('glue-duplicates', `/admin/glue/duplicates?section=${SECTION}`, async () => {
  // The Libby pair is the history fixture's own merge result, not a duplicate anyone wrote.
  await page.evaluate(() => {
    const h = [...document.querySelectorAll('#content *')].find(e => e.children.length <= 2 && /^Libby now available at all branches \(\d\)$/.test(e.textContent.trim()))
    if (h) h.parentElement.style.display = 'none'
  })
}, 1100, () => document.querySelector('#content'))

await shot('glue-history', `/admin/glue/history`, async () => {
  await page.evaluate(() => {
    const keep = /Maya Lin Torres|Libby|Volunteer/
    document.querySelectorAll('table.data tbody tr').forEach(tr => {
      // Warning rows follow their merge row; keep them with it.
      if (tr.querySelector('td[colspan]')) { tr.style.display = tr.previousElementSibling?.style.display || ''; return }
      if (!keep.test(tr.textContent)) tr.style.display = 'none'
    })
    // "By" is the harness admin under another plugin's test name.
    const ths = [...document.querySelectorAll('table.data thead th')]
    const n = ths.findIndex(th => th.textContent.trim() === 'By')
    document.querySelectorAll('.pagination, .glue-pagination, nav[aria-label*="agination"]').forEach(e => e.style.display = 'none')
    document.querySelectorAll('#content p, #content div').forEach(e => { if (/^Page \d+ of \d+/.test(e.textContent.trim()) && e.children.length < 4) e.style.display = 'none' })
    if (n >= 0) { ths[n].style.display = 'none'; document.querySelectorAll(`table.data tbody tr > td:nth-child(${n + 1})`).forEach(td => { if (!td.hasAttribute('colspan')) td.style.display = 'none' }) }
  })
}, 1100, () => document.querySelector('#content'))

await browser.close()
