'use strict';
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const root=process.env.BM_JOURNEY_ROOT;
 assert.match(root||'',/^\/tmp\/bm-integration-[A-Za-z0-9_-]+$/);
 const browser=await chromium.launch({headless:true,executablePath:process.env.BM_CHROMIUM_EXECUTABLE||undefined});
 try {
  const page=await browser.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(String(e)));
  const cfg=JSON.parse(fs.readFileSync(root+'/runtime.json'));
  let forceHttp=false, posts=0, fresh, lang="nl";
  await page.route('http://fixture.test/**',async route=>{
   const request=route.request();const name=new URL(request.url()).pathname.split('/').pop();
   if(name==='page')return route.fulfill({contentType:'text/html',body:execFileSync('php',['tests/admin_fixture.php'],{input:JSON.stringify({lang})}).toString()});
   assert.equal(request.headers().requesttoken,'fixture-csrf');
   let data={success:true};
   if(['request-recovery','recovery-status','provider-status','provider-admin-requests','provider-admin-action'].includes(name)){
    if(name==='request-recovery'){
     posts++;
     if(forceHttp)return route.fulfill({status:403,contentType:'text/html',body:'CSRF rejection fixture'});
    }
    data=JSON.parse(execFileSync('php',['tests/journey_controller.php'],{input:JSON.stringify({route:name,values:Object.fromEntries(new URLSearchParams(request.postData()||''))})}).toString());
    if(name==='request-recovery'&&data.success)fresh=data.requestId;
   } else if(name==='runtime')data.settings={...cfg,aws_regions:['us-east-1'],host_trusted:false};
   else if(name==='runtime-status')data.status={state:'missing'};
   else if(name==='management-clients')data.clients=[{client_id:'BM-000007',status:'active'}];
   else throw Error('Unexpected browser route '+name);
   await route.fulfill({status:data.success?200:400,contentType:'application/json',body:JSON.stringify(data)});
  });
  await page.goto('http://fixture.test/page');
  await page.locator('#bm-recover').waitFor({state:'visible'});
  await page.locator('#bm-recover').click();
  await page.waitForFunction(()=>document.querySelector('#bm-provider-message').textContent.includes('Toestemming'));
  assert.equal(posts,1);
  await page.locator('#bm-consent').check();
  forceHttp=true;await page.locator('#bm-recover').click();
  await page.waitForFunction(()=>document.querySelector('#bm-provider-message').textContent.includes('onleesbaar'));
  assert.match(await page.locator('#bm-provider-diagnostic').textContent(),/HTTP 403/);
  await page.locator('#bm-provider-section > summary').click();await page.locator('#bm-provider-section > summary').click();
  assert.match(await page.locator('#bm-provider-message').textContent(),/onleesbaar/);
  forceHttp=false;
  fs.writeFileSync(root+'/provider-false200', JSON.stringify({type:'text/html',body:'<html>Generic HTTP 200</html>'}));
  await page.locator('#bm-recover').click();
  await page.waitForFunction(()=>document.querySelector('#bm-provider-message').textContent.includes('ongeldig API-antwoord'));
  assert.equal(JSON.parse(fs.readFileSync(root+'/appconfig.json')).recovery_request_id, undefined);
  lang='en'; await page.goto('http://fixture.test/page');
  await page.waitForFunction(()=>document.querySelector('#bm-provider-message').textContent.includes('invalid API response'));
  assert.ok(await page.locator('#bm-provider-section').evaluate(el=>el.classList.contains('bm-status-red')));
  lang='nl'; await page.goto('http://fixture.test/page');
  await page.waitForFunction(()=>document.querySelector('#bm-provider-message').textContent.includes('ongeldig API-antwoord'));
  fs.unlinkSync(root+'/provider-false200');
  await page.locator('#bm-consent').check();
  await page.locator('#bm-recover').click();
  await page.waitForFunction(()=>document.querySelector('#bm-provider-message').textContent.includes('REC-'));
  assert.ok(fresh);fs.writeFileSync(root+'/browser-request.json',JSON.stringify({success:true,requestId:fresh}));
  await page.locator('#bm-recover').click();
  await page.waitForFunction(()=>document.querySelector('#bm-provider-message').textContent.includes('goedkeuring'));
  assert.ok(await page.locator('#bm-provider-section').evaluate(el=>el.classList.contains('bm-status-red')));
  for(const width of [1280,375,320]){
   await page.setViewportSize({width,height:900});
   await page.locator('#bm-provider-url').fill('https://'+'long.'.repeat(40)+'example.test');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   await page.locator('#bm-recover').focus();assert.equal(await page.locator('#bm-recover').evaluate(el=>el===document.activeElement),true);
  }
  lang='en';
  await page.goto('http://fixture.test/page');
  await page.locator('#bm-recover').waitFor({state:'visible'});
  await page.locator('#bm-consent').check();
  await page.locator('#bm-recover').focus();
  await page.keyboard.press('Enter');
  await page.waitForFunction(()=>document.querySelector('#bm-provider-message').textContent.includes('awaiting provider approval'));
  assert.deepEqual(errors,[]);
  console.log('Actual admin template/JS -> routes/controller -> RuntimeService -> provider -> private DB browser recovery passed.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
