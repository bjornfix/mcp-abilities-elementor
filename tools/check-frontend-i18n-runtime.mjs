import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const [fixtureFile, hooksFile, i18nFile, mode] = process.argv.slice(2);
const fixtures = JSON.parse(fs.readFileSync(fixtureFile, 'utf8'));
let checks = 0;
const check = (value, expected, message) => { assert.deepEqual(JSON.parse(JSON.stringify(value)), expected, message); checks++; };
function runtime() {
  const window = {wp: {}};
  const context = vm.createContext({ window, console, setTimeout, clearTimeout });
  Object.defineProperty(context, 'document', {get() {throw new Error('Bridge must not access DOM');}});
  vm.runInContext(fs.readFileSync(hooksFile,'utf8'), context);
  vm.runInContext(fs.readFileSync(i18nFile,'utf8'), context);
  return {context, i:window.wp.i18n};
}
let r = runtime();
if (mode === '--baseline-red') {
  check(r.i.__('Next slide','elementor'), 'Neste lysbilde', 'observed empty frontend catalog must reproduce untranslated native arrow');
  process.exit(0);
}
check(r.i.__('Next slide','elementor'), 'Next slide', 'actual WordPress empty-catalog source fallback reproduced');
r = runtime(); // Isolate the fresh-catalog case: gettext itself initializes a default header.
vm.runInContext(fixtures.no_script,r.context);
check(r.i.__('Next slide','elementor'), 'Neste lysbilde', 'native Norwegian next message reaches actual WordPress gettext');
check(r.i.__('Previous slide','elementor'), 'Forrige lysbilde', 'native Norwegian previous message reaches actual WordPress gettext');
check(r.i.getLocaleData('elementor')[''].lang,'nb_NO','native locale retained');
r = runtime(); r.i.__('Next slide','elementor');
const initializedHeader = JSON.parse(JSON.stringify(r.i.getLocaleData('elementor')['']));
vm.runInContext(fixtures.no_script,r.context);
check(r.i.__('Next slide','elementor'),'Neste lysbilde','existing default catalog initialized by gettext still receives missing translation');
check(r.i.getLocaleData('elementor')[''],initializedHeader,'default native header preserved');
r = runtime(); vm.runInContext(fixtures.english_script,r.context);
check(r.i.__('Next slide','elementor'),'Next slide','English source unchanged');
r = runtime();
r.i.setLocaleData({'':{domain:'elementor',lang:'fr_FR','plural-forms':'nplurals=2; plural=n>1;'},'Next slide':['Existing next'],'Unrelated':['Keep']},'elementor');
const metadata = JSON.parse(JSON.stringify(r.i.getLocaleData('elementor')['']));
vm.runInContext(fixtures.no_script,r.context);
check(r.i.__('Next slide','elementor'),'Existing next','existing native JavaScript translation remains authoritative');
check(r.i.__('Previous slide','elementor'),'Forrige lysbilde','only missing message filled');
check(r.i.__('Unrelated','elementor'),'Keep','unrelated message preserved');
check(r.i.getLocaleData('elementor')[''],metadata,'existing native catalog locale and plural rule preserved');
const once = JSON.parse(JSON.stringify(r.i.getLocaleData('elementor'))); vm.runInContext(fixtures.no_script,r.context);
check(r.i.getLocaleData('elementor'),once,'repeat execution leaves catalog identical');
r = runtime(); vm.runInContext(fixtures.special_script,r.context);
check(r.i.__('Next slide','elementor'),fixtures.special_value,'quotes and script-like translated text survive safe JSON transport');
vm.runInContext(fixtures.no_script,vm.createContext({window:{}})); checks++;
vm.runInContext(fixtures.no_script,vm.createContext({window:{wp:{i18n:{}}}})); checks++;
console.log(`Actual WordPress i18n runtime checks passed: ${checks}.`);
