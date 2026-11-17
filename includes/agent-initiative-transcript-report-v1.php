<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-runtime-v1.php';
require_once __DIR__.'/agent-initiative-research-status-v1.php';

/** Project verified public research onto the current saved recording item that prompted it. */
function vp3_initiative_transcript_report_followups_v1(PDO $pdo,array $user,int $agentId,int $conversationId,int $sessionId): array
{
    if($sessionId<1||$conversationId<1)return [];
    try{
        return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$conversationId,$sessionId):array{
            $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$conversationId);
            $policy=$context['policy'];
            if(($policy['conversation_id']??null)!==$conversationId)return [];
            $live=$context['user'];
            $owner=(int)$live['id'];
            $grant=vp3_transcript_research_grant_v1($pdo,$owner);
            if($grant==='')return [];
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $rows=vp3_initiative_research_status_candidates_v1($pdo,$owner,$agentId,$policy,$now->getTimestamp(),transcriptSessionId:$sessionId);
            $candidates=[];
            $refs=[];
            foreach($rows as $row){
                $goal=json_decode((string)$row['goal'],true);
                $binding=$goal['binding']??null;
                $primary=$goal['primary']??null;
                $sources=$goal['sources']??null;
                if(!is_array($binding)||($binding['enabled']??null)!==true||($binding['conversation_id']??null)!==$conversationId
                    ||($binding['agent_id']??0)!==$agentId||($binding['owner_user_id']??($agentId===0?$owner:null))!==$owner
                    ||!is_string($binding['revision']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$binding['revision'])
                    ||($goal['tool']??null)!=='topic.research'||($goal['report']??null)!==true
                    ||!is_string($primary)||!str_starts_with($primary,'transcript-research:'.$sessionId.':')
                    ||!vp3_public_research_transcript_ref_v1($primary)||!is_array($sources)||array_keys($sources)!==[$primary]
                    ||!is_string($sources[$primary])||!preg_match('/\A[a-f0-9]{64}\z/D',$sources[$primary]))continue;
                $candidates[]=[$row,$goal];
                $refs[$primary]=true;
            }
            if(!$candidates)return [];

            // Pausing Initiative stops new work. Delivered results can still be checked under current source grants.
            $scope=['user'=>$live,'principal'=>vp3_initiative_principal_v1($pdo,$live,$agentId),
                'agent_id'=>$agentId,'conversation_id'=>$conversationId,'verification_only'=>true];
            $coverage=[];
            $currentSources=vp3_initiative_transcript_research_evidence_v1($pdo,$scope,$policy,$now,array_keys($refs),$coverage);
            if(!$currentSources)return [];
            $followups=[];
            foreach($candidates as [$row,$goal]){
                $primary=$goal['primary'];
                $source=$currentSources[$primary]??null;
                if(isset($followups[$primary])||($source['revision']??null)!==$goal['sources'][$primary])continue;
                $completion=vp3_initiative_research_status_completion_v1($pdo,$owner,$agentId,$conversationId,$row,$goal,$now->getTimestamp(),true);
                if(($completion['state']??null)!=='completed')continue;
                $result=json_decode((string)$row['result_json'],true);
                $research=vp3_initiative_research_tool_result_v1($result['initiative_tool_result']);
                if($research===null)continue;
                $item=$source['research'];
                $followups[$primary]=[
                    'plugin_id'=>$item['plugin'],
                    'section_key'=>$item['section'],
                    'item_id'=>$item['item_id'],
                    'summary'=>$research['draft'],
                    'chat_url'=>vp3_top_of_mind_media_href_v1($agentId,$conversationId,$completion['message_id']),
                    'outcome'=>$research['output']['status'],
                    'sources'=>array_map(static fn(array $source):array=>['title'=>$source['title'],'url'=>$source['url']],$research['sources']),
                ];
            }
            $currentScope=vp3_initiative_transcript_capabilities_scope_v1($pdo,$scope,$policy);
            $currentPolicy=vp3_initiative_policy_v1($currentScope['user'],$agentId);
            if(($currentPolicy['conversation_id']??null)!==$conversationId
                ||vp3_initiative_knowledge_scope_v1($currentPolicy['knowledge_scope']??null)!==vp3_initiative_knowledge_scope_v1($policy['knowledge_scope']??null)
                ||!hash_equals($grant,vp3_transcript_research_grant_v1($pdo,$owner)))return [];
            return array_values($followups);
        });
    }catch(RuntimeException $error){
        if(!in_array($error->getCode(),[403,404,503],true))throw $error;
        return [];
    }
}
