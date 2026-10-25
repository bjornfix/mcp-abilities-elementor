/* Exercise the installed upstream Swiper constructor; no live page is changed. */
const fs=require('node:fs');const vm=require('node:vm');const assert=require('node:assert/strict');
const fixtures=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
const source=fs.readFileSync(process.argv[3]||process.env.MCP_ELEMENTOR_NATIVE_SWIPER_FIXTURE,'utf8');
let checks=0;function check(value,message){checks++;assert.ok(value,message);}
function context(){const c=vm.createContext({module:{exports:{}},exports:{},console,setTimeout,clearTimeout});vm.runInContext(source,c);c.window={Swiper:c.module.exports};return c;}
function params(c,options={}){return new c.window.Swiper(Object.assign({init:false},options)).params;}
function run(c,script){vm.runInContext(script,c);}
let c=context(),before=params(c);check(before.a11y.nextSlideMessage==='Next slide','RED: actual native lightbox defaults use English');check(before.a11y.prevSlideMessage==='Previous slide','native previous default English');
run(c,fixtures.swiper_no);let after=params(c);check(after.a11y.nextSlideMessage==='Neste lysbilde','actual Swiper constructor uses native next translation');check(after.a11y.prevSlideMessage==='Forrige lysbilde','actual Swiper constructor uses native previous translation');
for(const key of Object.keys(before.a11y)){if(!['nextSlideMessage','prevSlideMessage'].includes(key))check(JSON.stringify(after.a11y[key])===JSON.stringify(before.a11y[key]),'unrelated accessibility default retained: '+key);}
check(after.loop===before.loop&&after.speed===before.speed&&after.enabled===before.enabled,'playback and interaction defaults retained');let explicit=params(c,{a11y:{nextSlideMessage:'Custom next',prevSlideMessage:'Custom previous',enabled:false}});check(explicit.a11y.nextSlideMessage==='Custom next'&&explicit.a11y.prevSlideMessage==='Custom previous'&&!explicit.a11y.enabled,'per-instance customer settings authoritative');
let snapshot=JSON.stringify(c.window.Swiper.extendedDefaults);run(c,fixtures.swiper_no);check(snapshot===JSON.stringify(c.window.Swiper.extendedDefaults),'second execution idempotent');
c=context();c.window.Swiper.extendDefaults({speed:123,a11y:{nextSlideMessage:'Existing next',enabled:false,notificationClass:'retain-me'}});run(c,fixtures.swiper_no);after=params(c);check(after.a11y.nextSlideMessage==='Existing next'&&after.a11y.prevSlideMessage==='Forrige lysbilde','existing extended message retained, missing message supplied');check(after.speed===123&&!after.a11y.enabled&&after.a11y.notificationClass==='retain-me','unrelated extended defaults retained');
c=context();snapshot=JSON.stringify(params(c));run(c,fixtures.swiper_en);check(snapshot===JSON.stringify(params(c)),'English/no-configuration site unchanged');
c=context();c.window.Swiper.extendDefaults({a11y:false});run(c,fixtures.swiper_no);check(c.window.Swiper.extendedDefaults.a11y===false,'explicit disabled a11y setting retained');
c=context();run(c,fixtures.swiper_special);check(params(c).a11y.nextSlideMessage===fixtures.special_value,'special native message round trips without script injection');
c=vm.createContext({window:{}});run(c,fixtures.swiper_no);check(!c.window.Swiper,'absent upstream runtime fails closed');
console.log('Actual upstream Swiper regression checks passed: '+checks+'.');
