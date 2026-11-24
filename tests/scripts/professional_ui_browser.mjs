import puppeteer from 'puppeteer';
import { readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';

const fixture = JSON.parse(await readFile(process.env.RUNNER_TEMP+'/ui-browser-fixture.json','utf8'));
const origin = 'http://127.0.0.1:8799';
const browser = await puppeteer.launch({
  executablePath: process.env.CHROME_BIN,
  headless: true,
  timeout: 20000,
  args: ['--disable-dev-shm-usage'],
});
try {
  const page = await browser.newPage();
  await page.setViewport({width:1440,height:1000});
  page.setDefaultTimeout(12000);
  let pageErrors=[];
  page.on('pageerror', error => pageErrors.push(error.message));
  await page.setRequestInterception(true);
  page.on('request', request => {
    const url=request.url();
    void (url.startsWith(origin+'/') || url.startsWith('data:') || url.startsWith('blob:') || url === 'about:blank' ? request.continue() : request.abort()).catch(()=>{});
  });
  await page.goto(origin+'/admin/login',{waitUntil:'load'});
  await page.waitForSelector('input[type=email]',{visible:true});
  await page.type('input[type=email]',fixture.email);
  await page.type('input[type=password]',fixture.password);
  await page.click('button[type=submit]');
  await page.waitForFunction(()=>!location.pathname.endsWith('/login'));
  console.log('BROWSER_LOGIN: PASSED');
  const backgrounds={};
  for (const theme of ['light','dark']) {
    await page.evaluate(value=>localStorage.setItem('theme',value),theme);
    for (const path of fixture.routes) {
      pageErrors=[];
      const response=await page.goto(origin+path,{waitUntil:'load'});
      assert.equal(response?.status(),200,path);
      assert.equal(new URL(page.url()).pathname,path,'Unexpected route redirect '+path);
      await page.waitForSelector('.fi-page',{visible:true});
      await page.waitForFunction(expected=>document.documentElement.classList.contains('dark')===expected,{},theme==='dark');
      const snapshot=await page.evaluate(()=>{
        const root=document.documentElement;
        const sections=[...document.querySelectorAll('.fi-section')].filter(e=>e.getBoundingClientRect().width>0);
        const target=sections[0];
        let luminance=null;
        if(target){
          const canvas=document.createElement('canvas');
          canvas.width=canvas.height=1;
          const ctx=canvas.getContext('2d');
          ctx.fillStyle=getComputedStyle(target).backgroundColor;
          ctx.fillRect(0,0,1,1);
          const d=ctx.getImageData(0,0,1,1).data;
          if(d[3]===255)luminance=.2126*d[0]+.7152*d[1]+.0722*d[2];
        }
        return {nativeSections:sections.length,legacy:!!document.querySelector('.export-card,.export-hero,.export-spinner'),luminance,body:document.body.innerText,overflows:root.scrollWidth>root.clientWidth+5};
      });
      assert.ok(!snapshot.legacy,'Legacy export UI: '+path);
      assert.ok(!snapshot.body.includes('🎉'),'Decorative emoji: '+path);
      assert.equal(pageErrors.length,0,path+': '+pageErrors.join(' | '));
      if(path==='/admin/export-data'){
        assert.ok(snapshot.nativeSections>=4,'Expected native statistics and 3 export sections');
        assert.ok(!snapshot.body.includes('جاري إنشاء ملف Excel'),'Idle spinner text still present');
        assert.ok(!snapshot.overflows,'Export page horizontal overflow');
        backgrounds[theme]=snapshot.luminance;
        console.log('EXPORT_THEME',theme,'section luminance',snapshot.luminance);
      }
      console.log('BROWSER_PAGE_OK',theme,path);
    }
  }
  assert.ok(backgrounds.light>180,'Light cards are not light');
  assert.ok(backgrounds.dark<90,'Dark cards are not dark');
  for(const theme of ['light','dark']){
    await page.setViewport({width:390,height:844,isMobile:true,hasTouch:true});
    await page.evaluate(value=>localStorage.setItem('theme',value),theme);
    await page.goto(origin+'/admin/export-data',{waitUntil:'load'});
    await page.waitForSelector('.fi-page',{visible:true});
    await page.waitForFunction(expected=>document.documentElement.classList.contains('dark')===expected,{},theme==='dark');
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth+5);
    assert.equal(overflow,false,'Mobile export horizontal overflow: '+theme);
    console.log('EXPORT_MOBILE_OK',theme);
  }
  console.log('BROWSER_UI_ACCEPTANCE: PASSED');
} finally {
  await browser.close();
}
