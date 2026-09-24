'use strict';
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const root=process.env.BM_JOURNEY_ROOT, fresh=process.env.BM_MANAGEMENT_REQUEST;
 assert.match(root||'',/^\/tmp\/bm-integration-[A-Za-z0-9_-]+$/);
 assert.match(fresh||'',/^REC-[0-9]{8}-[A-F0-9]{6}$/);
 const browser=await chromium.launch({headless:true,executablePath:process.env.BM_CHROMIUM_EXECUTABLE||undefined});
 try {
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(String(e)));page.on('dialog',d=>d.accept());
  let lang='nl';const cfg=JSON.parse(fs.readFileSync(root+'/runtime.json'));
  const token=fs.readFileSync(root+'/management-token','utf8');
  await page.route('http://fixture.test/**',async route=>{
   const req=route.request(),name=new URL(req.url()).pathname.split('/').pop();
   if(name==='page')return route.fulfill({contentType:'text/html',body:execFileSync('php',['tests/admin_fixture.php'],{input:JSON.stringify({lang})}).toString()});
   assert.equal(req.headers().requesttoken,'fixture-csrf');
   assert.ok(!(req.postData()||'').includes(token));
   let data={success:true};
   if(name.startsWith('provider-admin-')){
    data=JSON.parse(execFileSync('php',['tests/journey_controller.php'],{input:JSON.stringify({route:name,values:Object.fromEntries(new URLSearchParams(req.postData()||''))})}).toString());
    assert.ok(!JSON.stringify(data).includes(token));
   } else if(name==='runtime')data.settings={...cfg,aws_regions:['us-east-1'],host_trusted:false};
   else if(name==='runtime-status')data.status={state:'missing'};
   else if(name==='management-clients')data.clients=[];
   else if(['provider-status','recovery-status'].includes(name))data.status='pending';
   else throw Error('Unexpected management browser route '+name);
   await route.fulfill({status:data.success?200:400,contentType:'application/json',body:JSON.stringify(data)});
  });
  async function open(){await page.goto('http://fixture.test/page');await page.locator('#bm-provider-admin > summary').click();await page.locator(`[data-request-id="${fresh}"]`).waitFor();}
  await open();
  const recovery=page.locator(`[data-request-id="${fresh}"]`);
  assert.match(await recovery.textContent(),/Toegangsherstel/);
  assert.match(await recovery.textContent(),/BM-000007/);
  assert.equal(await recovery.locator('dd').filter({hasText:'SHA256:'}).count(),2);
  assert.equal(await page.locator('#bm-provider-admin-history button').count(),0);
  const enrollment=page.locator('[data-request-id="REQ-20260923-ABCDEF"]');
  assert.match(await enrollment.textContent(),/Nieuwe aanmelding/);
  const before=fs.readFileSync(root+'/account/.ssh/authorized_keys');
  await enrollment.locator('[data-action="reject"]').click();
  await page.waitForFunction(()=>document.querySelector('#bm-provider-admin-message').textContent.includes('afgewezen'));
  assert.deepEqual(fs.readFileSync(root+'/account/.ssh/authorized_keys'),before);
  for(const width of [1280,375,320]){
   await page.setViewportSize({width,height:900});
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  }
  fs.writeFileSync(root+'/provider-false200',JSON.stringify({type:'text/html',body:'<html>HTTP 200</html>'}));
  await page.locator('#bm-provider-admin-refresh').click();
  await page.waitForFunction(()=>document.querySelector('#bm-provider-admin-message').textContent.includes('ongeldig API-antwoord'));
  assert.equal(await page.locator('#bm-provider-admin-pending button').count(),0);
  fs.unlinkSync(root+'/provider-false200');
  lang='en';await open();
  assert.match(await recovery.textContent(),/Access recovery/);
  assert.equal(await page.locator('#bm-provider-admin-history button').count(),0);
  await recovery.locator('[data-action="approve"]').focus();await page.keyboard.press('Enter');
  await page.waitForFunction(()=>document.querySelector('#bm-provider-admin-message').textContent.includes('Request approved.'));
  assert.ok(!(await page.content()).includes(token));
  assert.deepEqual(errors,[]);
  console.log('Nextcloud server-side provider management browser passed: enrollment/recovery, expiry, fingerprints, rejection, approval, false-200, languages, narrow layout and keyboard.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
