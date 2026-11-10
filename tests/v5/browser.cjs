const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const path=require('node:path');
const root=path.resolve(__dirname,'../../pimex-probki-v5');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 let checks=0;
 const check=(a,b)=>{assert.deepEqual(a,b);checks++;};
 for(const width of [390,1440,1920]){
  const page=await browser.newPage({viewport:{width,height:1000}});const errors=[],requests=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>requests.push(r.url()));
  await page.route('**/*',route=>route.request().url().endsWith('/img.svg')?route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="200" height="160"><path fill="#927c60" d="M20 20h160v100H20z"/></svg>'}):route.fulfill({contentType:'text/html',body:fs.readFileSync('/tmp/pimex-v5-fixture.html','utf8')}));
  await page.goto('https://example.com/probki/');await page.addScriptTag({path:root+'/assets/view.js'});
  const app=page.locator('pimex-samples-v5');await app.locator('.card').first().waitFor();
  const cols=await app.locator('.cards').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length);
  check(await app.locator('.card').count(),cols*4);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  if(width===390)check(await app.locator('.filters').evaluate(el=>el.open),false);
  const groups=await app.locator('.groups').boundingBox(),filters=await app.locator('.filters').boundingBox();check(groups.y<filters.y,true);
  await app.locator('.choose input').first().check();check(await app.locator('.count').textContent(),'1');
  await app.locator('[data-group="tarasy"]').click();check(await app.locator('[data-group="tarasy"]').getAttribute('aria-pressed'),'true');
  check(await app.locator('.count').textContent(),'1');
  await app.locator('[data-group="tarasy"]').click();check(await app.locator('[data-group="tarasy"]').getAttribute('aria-pressed'),'false');
  await app.locator('.choose input').nth(1).check();await app.locator('.choose input').nth(2).check();
  check(await app.locator('.count').textContent(),'3');assert.match(await app.locator('.grand').textContent(),/26,00/);checks++;
  await app.locator('.choose input').nth(3).click();check(await app.locator('.count').textContent(),'3');
  check(await app.locator('.choose input').nth(3).isChecked(),false);
  await app.locator('.search').fill('Model 130');check(await app.locator('.card').count(),1);check(await app.locator('.choose input').first().isDisabled(),true);
  if(width===390)await app.locator('.filters>summary').click();
  await app.locator('.reset').click();check(await app.locator('.count').textContent(),'3');check(await app.locator('.card').count(),cols*4);
  // Filters are independently selectable and preserve the selection basket.
  await app.locator('.filter').first().locator('summary').click();await app.locator('input[data-field="brand"][value="Inny producent"]').check();
  check(await app.locator('.count').textContent(),'3');check((await app.locator('.results').textContent()).startsWith('65 wzorów'),true);
  await app.locator('.reset').click();
  // Simultaneous price bounds may not combine two different length prices.
  const mb=app.locator('.filter').filter({has:page.locator('summary',{hasText:'Cena deski za mb'})});
  const m2=app.locator('.filter').filter({has:page.locator('summary',{hasText:'Cena deski za m²'})});
  await mb.locator('summary').click();await m2.locator('summary').click();
  await mb.locator('input[type="number"]').nth(1).fill('20');await mb.locator('input[type="number"]').nth(1).dispatchEvent('change');
  check(await app.locator('.card').count(),1);
  await m2.locator('input[type="number"]').nth(1).fill('200');await m2.locator('input[type="number"]').nth(1).dispatchEvent('change');check(await app.locator('.card').count(),0);
  await app.locator('.reset').click();
  await app.locator('.pages button').last().click();check(await app.locator('.pages span').textContent(),`2 / ${Math.ceil(130/(cols*4))}`);
  const ids=await app.locator('.ids').inputValue();check(JSON.parse(ids),[1,2,3]);
  await page.evaluate(()=>dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})));
  check(await app.locator('.search').inputValue(),'');check(await app.locator('.count').textContent(),'3');
  check(await app.locator('.pages span').textContent(),`1 / ${Math.ceil(130/(cols*4))}`);
  await page.screenshot({path:`/tmp/pimex-v5-${width}.png`,fullPage:true});
  // Reload storage by rebuilding the view; selections remain, filters reset.
  await page.reload();await page.addScriptTag({path:root+'/assets/view.js'});await app.locator('.card').first().waitFor();
  check(await app.locator('.count').textContent(),'3');check(await app.locator('input[data-field]:checked').count(),0);
  await app.locator('.clear').click();check(await app.locator('.count').textContent(),'0');
  check(await app.locator('.submit').isDisabled(),true);
  const before=requests.length;await page.waitForTimeout(1200);check(requests.length,before);
  check(requests.some(u=>u.includes('admin-ajax')||u.includes('wp-cron')),false);check(errors,[]);
  await page.close();
 }
 // Maximum catalog: bounded cards and safe rendering of untrusted product text.
 {
  let html=fs.readFileSync('/tmp/pimex-v5-fixture.html','utf8');
  const match=html.match(/(<script type="application\/json" class="config">)([\s\S]*?)(<\/script>)/);
  const config=JSON.parse(match[2]);const base=config.items[0];
  config.items=Array.from({length:1500},(_,i)=>({...base,id:i+1,parent:i+1,key:'stress:'+i,aliases:[i+1],title:'Model '+(i+1)}));
  config.items[0].title='<img src=x onerror="window.pimexUnsafe=1">';config.items[0].url='javascript:alert(1)';
  html=html.replace(match[0],match[1]+JSON.stringify(config).replace(/</g,'\\u003c')+match[3]);
  const page=await browser.newPage({viewport:{width:1440,height:900}});let count=0;
  await page.route('**/*',route=>{count++;return route.fulfill({contentType:route.request().url().endsWith('/img.svg')?'image/svg+xml':'text/html',body:route.request().url().endsWith('/img.svg')?'<svg xmlns="http://www.w3.org/2000/svg"/>':html});});
  await page.goto('https://example.com/probki/');const start=Date.now();await page.addScriptTag({path:root+'/assets/view.js'});
  const app=page.locator('pimex-samples-v5');await app.locator('.card').first().waitFor();
  check(await app.locator('.card').count(),20);check((await app.locator('.results').textContent()).startsWith('1500 wzorów'),true);
  check(await app.locator('.title').first().textContent(),config.items[0].title);check(await page.evaluate(()=>window.pimexUnsafe),undefined);
  check(await app.locator('.product-link').first().getAttribute('href'),'');
  await app.locator('.choose input').first().focus();await page.keyboard.press('Space');check(await app.locator('.choose input').first().evaluate(i=>i===i.getRootNode().activeElement),true);
  console.log('Maximum catalog browser mount and first interaction (local fixture): '+(Date.now()-start)+' ms');
  const before=count;await page.waitForTimeout(1000);check(count,before);await page.close();
 }
 // Admin worker: explicit start, sequential requests, stops on errors instead of retrying.
 const page=await browser.newPage();
 await page.route('https://example.com/admin',route=>route.fulfill({contentType:'text/html',body:'<form class="pimex-v5-builder" action="https://example.com/admin-post.php"><input name="action" value="pimex_v5_build"><input name="_wpnonce" value="nonce"><button type="button" data-operation="start">Start</button><button type="button" data-operation="resume">Resume</button><button type="button" data-operation="stop">Stop</button><button type="button" data-operation="cancel">Cancel</button><p role="status"></p></form>'}));
 let requests=0,active=0,peak=0;
 await page.route('**/admin-post.php',async route=>{requests++;active++;peak=Math.max(peak,active);await new Promise(r=>setTimeout(r,100));active--;await route.fulfill({contentType:'application/json',body:JSON.stringify({success:requests<3,data:requests<3?{done:false,scanned:requests*10,samples:3}:{message:'Symulowany błąd'}})});});
 await page.goto('https://example.com/admin');await page.addScriptTag({path:root+'/assets/admin.js'});check(requests,0);
 await page.locator('[data-operation="start"]').click();await page.waitForFunction(()=>document.querySelector('[role=status]').textContent.includes('Symulowany błąd'));
 await page.waitForTimeout(1000);check(requests,3);check(peak,1);check(await page.locator('[data-operation="start"]').isEnabled(),true);
 await page.unroute('**/admin-post.php');requests=0;
 await page.route('**/admin-post.php',async route=>{
  requests++;const op=new URLSearchParams(route.request().postData()).get('operation');
  // Multipart field value is still present in the raw body, so inspect cancellation directly.
  const cancel=route.request().postData().includes('cancel');
  await new Promise(r=>setTimeout(r,150));
  await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:cancel?{done:true,cancelled:true}:{done:false,scanned:10,samples:2}})});
 });
 await page.locator('[data-operation="start"]').click();await page.locator('[data-operation="stop"]').click();
 await page.waitForFunction(()=>document.querySelector('[role=status]').textContent.includes('Możesz wznowić'));
 await page.waitForTimeout(800);check(requests,1);
 await page.locator('[data-operation="cancel"]').click();await page.waitForFunction(()=>document.querySelector('[role=status]').textContent.includes('Anulowano'));check(requests,2);
 await browser.close();console.log(`PASS ${checks} browser assertions across 390/1440/1920 px and admin worker`);
})().catch(e=>{console.error(e);process.exit(1);});
