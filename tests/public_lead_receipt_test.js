const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Exercise the actual inline analytics scripts together, without a server or Google tags.
function trackingScripts(relativePath) {
  const template = fs.readFileSync(path.join(__dirname, '../src/views', relativePath), 'utf8');
  return Array.from(template.matchAll(/<script>([\s\S]*?)<\/script>/g), (match) => match[1])
    .filter((script) => /vnvTrackMarketingEvent|generate_lead/.test(script))
    .map((script) => script.replace(/{{[\s\S]*?}}/g, (tag) => tag.includes('ref_code') ? 'GA-QA-123' : ''));
}

const base = trackingScripts('templates/base.twig');
const station = trackingScripts('templates/pages/cateringServiceLanding.twig');
const comparison = trackingScripts('public/pages/catering-stations-south-florida/index.twig');
const stationPath = '/pasta-station-catering-south-florida/';
const comparisonPath = '/catering-stations-south-florida/';
const thankYouPath = '/sucess-request/';
const receipt = '?lead=success&lead_id=42';
const gourmet = '&service=taco&lead_qualified=1';
const general = '&source=gourmet_landing_pasta';

function pageLoad(pagePath, search, pageScripts, storage) {
  const ready = [];
  const browser = {
    location: {pathname: pagePath, search, protocol: 'https:'},
    sessionStorage: {getItem: (key) => storage.get(key), setItem: (key, value) => storage.set(key, value)},
    URLSearchParams,
    document: {
      cookie: '', body: {classList: {add() {}}},
      querySelector: () => null, getElementById: () => null, querySelectorAll: () => [],
      addEventListener(type, callback) { if (type === 'DOMContentLoaded') ready.push(callback); },
    },
  };
  browser.window = browser;
  const context = vm.createContext(browser);
  for (const script of [...base, ...pageScripts]) vm.runInContext(script, context);
  ready.forEach((fn) => fn());
  const entries = Array.from(browser.dataLayer);
  const events = entries.filter((entry) => entry.event === 'generate_lead');
  const commands = entries.filter((entry) => entry[0] === 'event' && entry[1] === 'generate_lead');
  assert.deepEqual(commands.map((command) => command[2].event_id), events.map((event) => event.event_id));
  return events;
}

const scenarios = [
  [stationPath, station, receipt + gourmet + '&lead_form=gourmet_short_form', 'gourmet-request-42'],
  [comparisonPath, comparison, receipt + gourmet + '&lead_form=gourmet_short_form', 'gourmet-request-42'],
  [comparisonPath, comparison, receipt + '&service=taco&lead_qualified=0&lead_form=gourmet_short_form', 'gourmet-request-42'],
  [thankYouPath, [], receipt + general + '&lead_form=public_event_request', 'event-request-42'],
  [stationPath, station, receipt + general + '&lead_form=public_event_request', 'event-request-42'],
  [stationPath, station, receipt + gourmet, 'gourmet-request-42'],
  [comparisonPath, comparison, receipt + gourmet, 'gourmet-request-42'],
  [comparisonPath, [], receipt + gourmet, 'gourmet-request-42'], // Ads cookie expired before return.
  [comparisonPath, comparison, receipt + general, 'event-request-42'],
  [thankYouPath, [], receipt, 'event-request-42'],
  [stationPath, station, '?lead=success', null],
  [stationPath, station, receipt, null],
  [stationPath, station, receipt + gourmet + '&lead_form=unsupported', null],
  [stationPath, station, '?lead=error&lead_id=42' + gourmet, null],
  [thankYouPath, [], '?lead=success&lead_id=0' + general, null],
  [thankYouPath, [], '?lead=success&lead_id=42x' + general, null],
];

for (const [pagePath, scripts, search, expected] of scenarios) {
  const storage = new Map();
  const events = pageLoad(pagePath, search, scripts, storage);
  const label = pagePath + search;
  assert.deepEqual(events.map((event) => event.event_id), expected ? [expected] : [], label);
  if (expected === 'gourmet-request-42') {
    const event = events[0];
    assert.equal(event.form_name, 'gourmet_short_form');
    assert.equal(event.service, 'taco');
    assert.equal(event.lead_qualified, search.includes('lead_qualified=1'));
    assert.equal(event.ref_code, 'GA-QA-123');
    assert.equal(event.landing_url, pagePath);
  } else if (expected) {
    assert.equal(events[0].form_name, 'public_event_request');
    assert.equal(events[0].form_source, search.includes(general) ? 'gourmet_landing_pasta' : 'public_event_request');
  }
  assert.equal(pageLoad(pagePath, search, scripts, storage).length, 0, 'Refresh: ' + label);
}
// A receipt tracked before deployment must keep the same storage key on its next visit.
for (const [query, eventId] of [[gourmet, 'gourmet-request-42'], [general, 'event-request-42']]) {
  const storage = new Map([['vnv_tracked_generate_lead_' + eventId, '1']]);
  assert.equal(pageLoad(stationPath, receipt + query, station, storage).length, 0);
}
console.log(`Lead receipt QA passed on ${scenarios.length} redirects plus their refreshes.`);
