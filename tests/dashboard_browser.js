'use strict';
// Optional real-browser suite: NODE_PATH and PLAYWRIGHT_BROWSERS_PATH may point to an isolated install.
const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
    const browser = await chromium.launch({headless: true, executablePath: process.env.BM_CHROMIUM_EXECUTABLE || undefined});
    let scenarios = 0;
    try {
        const page = await browser.newPage({timezoneId: 'Europe/Amsterdam'});
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        for (const theme of ['light', 'dark']) {
        for (const width of [1280, 768, 375, 320]) {
            await page.setViewportSize({width, height: 900});
            for (const lang of ['nl', 'en']) {
                const translations = JSON.parse(fs.readFileSync('app/backupstatus/l10n/' + lang + '.json')).translations;
                for (const state of ['missing', 'ok', 'failed', 'running']) {
                    for (const long of [false, true]) {
                        const status = {state};
                        if (state !== 'missing') Object.assign(status, {
                            last_success: long ? '2026-09-21T11:37:04.' + '5'.repeat(300) + '+00:00' : '2026-09-21T11:37:04.538632+00:00',
                            last_attempt: '2026-09-22T06:46:41.403748+00:00',
                            generation: '20260921T113652Z-' + 'f4e6efba0a16'.repeat(long ? 150 : 1),
                        });
                        if (state === 'failed') Object.assign(status, {error_code: 'ssh_authentication', error: 'RAW BACKEND ERROR <script>bad()</script>'.repeat(long ? 100 : 1)});
                        const html = execFileSync('php', ['tests/dashboard_fixture.php'], {input: JSON.stringify({status, lang, theme, longLabel:long})}).toString();
                        await page.setContent(html);
                        const labels = {missing:'No successful backup',ok:'Backup current',failed:'Backup failed',running:'Backup running'};
                        assert.equal(await page.locator('h3').textContent(), translations[labels[state]]);
                        assert.equal(await page.locator('dt').first().evaluate(el => el.parentElement.querySelector('dd') !== null), true);
                        if (state === 'missing') {
                            assert.equal(await page.locator('time').count(), 0);
                            assert.equal(await page.locator('dd').first().textContent().then(s=>s.trim()), translations.Unknown);
                        } else {
                            const time = page.locator('time').first();
                            assert(!(await time.textContent()).includes('T11:37'));
                            assert((await time.textContent()).includes('13:37:04') || (await time.textContent()).includes('1:37:04'));
                            assert.equal(await time.getAttribute('title'), status.last_success);
                            assert.equal(await page.locator('details').getAttribute('open'), null);
                            await page.keyboard.press('Tab');
                            assert.equal(await page.evaluate(()=>document.activeElement.tagName), 'SUMMARY');
                            assert.equal(await page.locator('summary').evaluate(el => getComputedStyle(el).outlineStyle), 'solid');
                            await page.keyboard.press('Enter');
                            assert.equal(await page.locator('details').evaluate(el=>el.open), true);
                            assert.equal(await page.locator('code').first().textContent(), status.generation);
                        }
                        if (state === 'failed') assert.equal(await page.locator('[role=alert]').textContent().then(s=>s.trim()), translations['SSH authentication failed.']);
                        assert(!(await page.locator('body').textContent()).includes('RAW BACKEND ERROR'));
                        const geometry = await page.evaluate(() => ({
                            overflow: document.documentElement.scrollWidth > innerWidth,
                            overlap: [...document.querySelectorAll('.bm-metadata-row')].some(row => {
                                const a=row.querySelector('dt').getBoundingClientRect(), b=row.querySelector('dd').getBoundingClientRect();
                                return Math.min(a.right,b.right)>Math.max(a.left,b.left)+1 && Math.min(a.bottom,b.bottom)>Math.max(a.top,b.top)+1;
                            }),
                        }));
                        assert.deepEqual(geometry, {overflow:false,overlap:false}, JSON.stringify({width,lang,state,long}));
                        const contrast = await page.locator('dt').first().evaluate(el => {
                            const rgb=s=>s.match(/[0-9.]+/g).slice(0,3).map(Number);
                            const luminance=values=>values.map(v=>{v/=255;return v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4;}).reduce((a,v,i)=>a+v*[0.2126,0.7152,0.0722][i],0);
                            const fg=luminance(rgb(getComputedStyle(el).color));
                            const bg=luminance(rgb(getComputedStyle(el.closest('.bm-status-card')).backgroundColor));
                            return (Math.max(fg,bg)+0.05)/(Math.min(fg,bg)+0.05);
                        });
                        assert(contrast>=4.5, 'Insufficient label contrast');
                        if (theme==='light' && !long && state==='failed' && ((width===1280 && lang==='en') || (width===375 && lang==='nl'))) {
                            await page.screenshot({path:`/tmp/bm-dashboard-${lang}-${width}.png`,fullPage:true});
                        }
                        if (state !== 'missing') {
                            await page.keyboard.press('Space');
                            assert.equal(await page.locator('details').evaluate(el=>el.open), false);
                        }
                        scenarios++;
                    }
                }
            }
        }
        }
        for (const locale of ['invalid_locale_!', '']) {
            await page.setContent(execFileSync('php', ['tests/dashboard_fixture.php'], {input:JSON.stringify({lang:'en',locale,status:{state:'ok',last_success:'2026-09-21T11:37:04.538632+00:00'}})}).toString());
            assert(!(await page.locator('time').textContent()).includes('Unknown'));
        }
        await page.setContent(execFileSync('php', ['tests/dashboard_fixture.php'], {input:JSON.stringify({lang:'nl',status:{state:'failed',last_success:'invalid date',error:'unknown raw error'}})}).toString());
        assert.equal(await page.locator('time').textContent(), 'Onbekend');
        assert((await page.locator('[role=alert]').textContent()).includes('serverlogboeken'));
        assert.deepEqual(errors, []);
        console.log(`Dashboard browser tests passed: ${scenarios} layout/status/language cases, locale/date/error fallbacks and keyboard details/focus.`);
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
