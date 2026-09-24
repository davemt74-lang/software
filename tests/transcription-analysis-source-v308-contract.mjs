import fs from 'node:fs';
import assert from 'node:assert/strict';
const workflow=fs.readFileSync(new URL('../includes/transcription-workflow-config.php',import.meta.url),'utf8');
const apps=fs.readFileSync(new URL('../includes/transcription-apps-wave2.php',import.meta.url),'utf8');

assert.ok(workflow.includes("'source'=>'saved_transcript'"),'manual Analyze must accept saved transcript text when page analysis is absent');
assert.ok(workflow.includes("'source'=>'page_analysis'"),'fresh page-analysis cache remains preferred');
assert.ok(workflow.includes('raw_transcript_fallback_pages'),'analysis response reports transcript fallback use');
assert.ok(!workflow.includes("There is not enough saved transcript analysis to run the selected plugins yet."),'old saved-analysis gate must be removed');
assert.ok(workflow.includes("This transcription has no saved transcript words to analyze yet."),'empty transcripts still fail explicitly');
assert.ok(workflow.includes("Transcript page preparation failed:"),'real page preparation errors remain visible');
assert.ok(apps.includes('LIVE TRANSCRIPT PAGE EVIDENCE'),'plugin prompt accepts cached analysis or raw saved transcript evidence');
assert.ok(apps.includes('saved raw transcript text'),'raw saved transcript is explicitly authoritative evidence');
console.log('Transcription saved-source v308 contract: PASS');
