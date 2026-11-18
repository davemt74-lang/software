<?php
declare(strict_types=1);

// The complete native Profile admission/reply path, followed by the real
// query-only specialist projection. Only external inference is simulated.
require_once __DIR__.'/fixtures/profile-specialist-sources-v1.php';
require_once __DIR__.'/fixtures/product-marketing-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-product-marketing-v1.php';
pfs_reset();$pdo=db();$fixture=product_marketing_followthrough_fixture_v1($pdo);
$GLOBALS['pa3Now']=(new DateTimeImmutable('2026-11-16T15:00:00Z'))->getTimestamp()*1000;pfs_clock();
$pdo->prepare('UPDATE profile_agent_handoff_presence_pa3 SET last_seen_ms=? WHERE owner_user_id=1')->execute([$GLOBALS['pa3Now']]);
$message=pp_message('Does this product include installation?');$turn=$message['turn'];
pp_delivery($turn);
vp3_profile_handoff_owner_action_pa3($pdo,ps_owner(),['operation'=>'delegate','turn_id'=>(int)$turn['id'],
    'revision'=>(int)$turn['version'],'conversation_id'=>(int)$turn['conversation_id'],'chat_conversation_id'=>11,
    'chat_agent_id'=>7,'request_id'=>bin2hex(random_bytes(16))]);
$done=vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
if(($done['reason']??null)!=='completed')throw new RuntimeException('The real native reply fixture did not complete: '.json_encode($done));
$turn=vp3_profile_handoff_row_pa3($pdo,(int)$turn['id']);$mid=(int)$turn['answer_message_id'];$cid=(int)$turn['conversation_id'];
$q=$pdo->prepare('SELECT context_json FROM profile_agent_messages WHERE id=?');$q->execute([$mid]);$context=json_decode($q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
$context['product_promotion_key']=$fixture['publications'][0];
$pdo->prepare('UPDATE profile_agent_messages SET context_json=? WHERE id=?')->execute([json_encode($context,JSON_THROW_ON_ERROR),$mid]);
$GLOBALS['pa3Now']=(new DateTimeImmutable('2026-11-17T08:15:00Z'))->getTimestamp()*1000;pfs_clock();
$checks=0;
function pmf_check(bool $condition,string $label):void
{
    global $checks;if(!$condition)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;
}
function pmf_read(int $cid):array
{
    return vp3_coordination_profile_sources_v1(db(),pfs_scope(),pfs_binding(),['conversation:'.$cid],pfs_now());
}
$before=pa4b_snapshot()+pp_no_external_snapshot();$ref='profile:conversation:'.$cid;
$snapshot=pmf_read($cid);$item=$snapshot['items'][$ref]??null;
pmf_check(is_array($item)&&count($item['related_product_marketing']??[])===1,
    'the real native Profile conversation finding contains one verified related promotion');
$link=$item['related_product_marketing'][0];
$source=vp3_product_marketing_review_v1($pdo,pfs_scope(),pfs_now(),[$link['ref']])['sources'][$link['ref']];
pmf_check($link['revision']===$source['revision']&&$link['url']===$source['url']&&$link['product_id']===$fixture['product_ids'][0],
    'Profile and Marketing use the same exact saved product, publication, period and source revision');
$report=vp3_profile_specialist_report_v1($snapshot);
pmf_check(($report['findings'][0]['related_product_marketing'][0]??null)===$link
    &&str_contains(implode(' ',$report['key_findings']),'Does this product include installation?')
    &&str_contains(implode(' ',$report['key_findings']),'saved Agent reply showed the promotion'),
    'all five Profile report sections retain literal visitor attribution and the validated review link');
pmf_check($before===pa4b_snapshot()+pp_no_external_snapshot(),
    'association projection cannot acknowledge, publish, infer, dispatch, mutate a plan or duplicate a native card');
$ownership=[$item['presentation_owner'],$item['profile_presentation_owned'],$item['legacy_delivered']];
pfs_advance(10*86400000);$pdo->exec("UPDATE users SET timezone='Pacific/Auckland' WHERE id=1");
pmf_check((pmf_read($cid)['items'][$ref]??null)===$item,
    'unchanged conversation evidence stays fixed across later days and a timezone preference change');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");
$context['product_promotion_key']=$fixture['publications'][1];
$pdo->prepare('UPDATE profile_agent_messages SET context_json=? WHERE id=?')->execute([json_encode($context,JSON_THROW_ON_ERROR),$mid]);
$changed=pmf_read($cid)['items'][$ref]??null;
pmf_check(is_array($changed)&&$changed['revision']!==$item['revision']
    &&($changed['related_product_marketing'][0]['product_id']??null)===$fixture['product_ids'][1],
    'changing the exact saved promotion evidence changes its association and Profile revision');
pmf_check([$changed['presentation_owner'],$changed['profile_presentation_owned'],$changed['legacy_delivered']]===$ownership,
    'adding or correcting related promotion evidence cannot take ownership from the native Profile presenter');
$pdo->prepare('DELETE FROM agent_workflow_receipts WHERE run_id=? AND action_id=?')->execute([(int)$turn['run_id'],(int)$turn['action_id']]);
$missing=pmf_read($cid)['items'][$ref]??null;
pmf_check(is_array($missing)&&$missing['related_product_marketing']===[]&&$missing['product_marketing_coverage']==='limited',
    'missing reply proof withholds the association while preserving the independently verified native conversation');
pmf_check(!str_contains(implode(' ',$missing['profile_report']['key_findings']),'saved Agent reply showed the promotion'),
    'missing association proof cannot leave stale promotional prose in the Profile report');
foreach([997601,997602] as $asset)unlink(STONEFELLOW_ROOT.'/private/user-media/1/phase6-'.$asset.'.png');
rmdir(STONEFELLOW_ROOT.'/private/user-media/1');rmdir(STONEFELLOW_ROOT.'/private/user-media');
echo 'PRODUCT_MARKETING_PROFILE_FINDINGS_V1=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).')'.PHP_EOL;
