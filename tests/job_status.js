'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const source=fs.readFileSync('app/backupstatus/js/admin.js','utf8');
const code=source.slice(source.indexOf('        let currentJob ='),source.indexOf('        function action('));
(async()=>{
    for (const lang of ['nl','en']) {
        const translations=JSON.parse(fs.readFileSync('app/backupstatus/l10n/'+lang+'.json')).translations;
        const tr=text=>translations[text]||text;
        const elements={}; const saved={'backupmanager-job':'fixture'};
        let response; let refreshes=0; let timer=0;
        const timers=new Map();
        const context={
            localStorage:{getItem:k=>saved[k]||'',setItem:(k,v)=>saved[k]=v,removeItem:k=>delete saved[k]},
            window:{setTimeout:(fn,ms)=>{timers.set(++timer,{fn,ms});return timer;},clearTimeout:id=>timers.delete(id),dispatchEvent(){}},
            request:async()=>{if(response instanceof Error)throw response;return response;},
            tr, uiError:error=>error.message, errorMessage:record=>tr(record.error_message||'The operation failed. Check the server logs.'),
            byId:id=>elements[id]||=( {} ), root:{}, renderConnectionTest(){},loadStatus:async()=>refreshes++,
            jobStates:{queued:'Queued',running:'Running',completed:'Completed',failed:'Failed',cancelled:'Cancelled'},
            CustomEvent:class{},
        };
        vm.createContext(context);vm.runInContext(code+'\nglobalThis.poll=pollJob; globalThis.trackJob=track;',context);
        for (const state of ['queued','running']) {
            response={job:{id:'fixture',action:'test',state}};
            await context.poll();
            assert.equal(elements['bm-cancel'].disabled,false, 'Queued/running connection probes support safe cancellation');
            assert.equal(timers.size,1);
            assert.equal([...timers.values()][0].ms,3000);
        }
        response=new Error('network fixture');await context.poll();
        assert.equal([...timers.values()][0].ms,15000);
        response={job:{id:'fixture',action:'test',state:'failed',error:'RAW AUTH EXCEPTION',error_message:'SSH authentication failed.',error_code:'ssh_authentication'}};
        await context.poll();
        assert(!elements['bm-job'].textContent.includes('RAW'));
        assert(elements['bm-job'].textContent.includes(tr('SSH authentication failed.')));
        assert.equal(elements['bm-job-diagnostic'].textContent,'ssh_authentication');
        assert.equal(saved['backupmanager-job'],undefined);
        assert.equal(timers.size,0);
        assert.equal(refreshes,1);
        for (const state of ['completed','cancelled']) {
            response={job:{id:state,action:'verify',state}};
            context.trackJob(response);
            await new Promise(resolve=>setImmediate(resolve));
            assert.equal(saved['backupmanager-job'],undefined);
            assert(elements['bm-job'].textContent.includes(tr(state==='completed'?'Completed':'Cancelled')));
        }
    }
    console.log('Job polling lifecycle, retry, terminal cleanup and localized errors passed in Dutch and English.');
})().catch(error=>{console.error(error);process.exitCode=1;});
