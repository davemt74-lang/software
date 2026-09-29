<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-profile-webmcp-v193.php';

function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

t(vp3_agent_profile_webmcp_intent_v193('What can my profile do?'),'explicit my profile');
t(vp3_agent_profile_webmcp_intent_v193('Can customers book through my profile?'),'profile booking handoff');
t(vp3_agent_profile_webmcp_intent_v193('Show my profile WebMCP capabilities'),'explicit WebMCP');
t(vp3_agent_profile_webmcp_intent_v193('What rewards are available on my profile?'),'profile rewards');
t(!vp3_agent_profile_webmcp_intent_v193('Book an appointment tomorrow'),'generic scheduling must not be hijacked');
t(!vp3_agent_profile_webmcp_intent_v193('Search venues for my tour'),'music booking must not be hijacked');
t(!vp3_agent_profile_webmcp_intent_v193('What rewards do I have?'),'generic rewards must not be hijacked');

echo "PROFILE_WEBMCP_AGENT_BRAIN_V193_PHP=PASS\n";
