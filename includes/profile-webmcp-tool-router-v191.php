<?php
declare(strict_types=1);

require_once __DIR__.'/profile-webmcp-confirmation-v192.php';

const VP3_PROFILE_WEBMCP_TOOL_ROUTER_V191='profile-webmcp-tool-router-v191-20260929';

function vp3_profile_webmcp_router_elapsed_v191(float $startedAt): int
{
    return (int)max(0,round((microtime(true)-$startedAt)*1000));
}

function vp3_profile_webmcp_router_record_v191(
    PDO $pdo,?array $telemetryContext,string $event,string $tool,string $outcome,float $startedAt,array $extra=[]
): void {
    vp3_profile_webmcp_record_v130(
        $pdo,$telemetryContext,$event,$tool,$outcome,
        vp3_profile_webmcp_router_elapsed_v191($startedAt),$extra
    );
}

function vp3_profile_webmcp_dispatch_v191(
    PDO $pdo,
    array $profile,
    ?array $viewer,
    array $manifest,
    string $surface,
    string $tool,
    array $args,
    array $telemetry,
    ?array $telemetryContext,
    float $startedAt,
    callable $respond,
    array $transport=[]
): never {
    if(!in_array($surface,['native_profile','external_site'],true)){
        throw new RuntimeException('Unsupported Profile WebMCP execution surface.');
    }

    $proof=(string)($transport['proof']??'');
    $property=is_array($transport['property']??null)?$transport['property']:null;
    $origin=(string)($transport['origin']??'');
    $agentCtx=is_array($transport['agent_context']??null)?$transport['agent_context']:null;

    if($tool==='vp3.profile.capabilities.get'){
        vp3_profile_webmcp_action_respond_v192($respond,$tool,true,[
            'manifest_version'=>VP3_PROFILE_WEBMCP_MANIFEST_V100,
            'capabilities'=>$manifest['capabilities'],
            'allowed_tools'=>$manifest['allowed_tools'],
        ]);
    }
    if($tool==='vp3.profile.get'){
        vp3_profile_webmcp_action_respond_v192($respond,$tool,true,['profile'=>vp3_profile_webmcp_public_profile_v100($profile)]);
    }
    if($tool==='vp3.intent.resolve'){
        $goal=trim((string)($args['goal']??''));
        vp3_profile_webmcp_action_respond_v192($respond,$tool,true,['resolution'=>vp3_profile_webmcp_resolve_intent_v100($goal,$manifest)]);
    }

    if(str_starts_with($tool,'vp3.agent.')){
        if($surface==='native_profile'){
            $agentCtx=vp3_profile_agent_public_context_v110($pdo,$profile,$viewer);
        }elseif(!$agentCtx){
            throw new RuntimeException('Connected-site Profile Agent context is unavailable.');
        }

        if($tool==='vp3.agent.get'){
            if($surface==='external_site'){
                $agent=vp3_profile_webmcp_external_agent_v120($pdo,$profile);
                if(!$agent)vp3_profile_webmcp_action_respond_v192($respond,$tool,false,['error'=>['code'=>'PROFILE_AGENT_UNAVAILABLE','message'=>'This Profile Agent is unavailable.']],404,'PROFILE_AGENT_UNAVAILABLE');
                vp3_profile_webmcp_action_respond_v192($respond,$tool,true,['agent'=>$agent]);
            }
            $state=vp3_profile_agent_public_state_service_v110($pdo,$agentCtx,0);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,['agent'=>$state['agent']]);
        }
        if($tool==='vp3.agent.chat.start'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_chat_start_v140($pdo,$agentCtx));
        }
        if($tool==='vp3.agent.conversation.get'){
            $cid=max(0,(int)($args['conversation_id']??0));
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_agent_public_state_service_v110($pdo,$agentCtx,$cid));
        }
        if($tool==='vp3.agent.message.send'){
            $cid=max(0,(int)($args['conversation_id']??0));
            $message=trim((string)($args['message']??''));
            $result=vp3_profile_agent_public_message_service_v110($pdo,$agentCtx,$message,$cid);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_message_sent',$tool,'completed',$startedAt,[
                'conversation_id'=>(int)($result['conversation_id']??0),
            ]);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.agent.owner_handoff.request'){
            $cid=max(0,(int)($args['conversation_id']??0));
            $reason=trim((string)($args['reason']??''));
            $result=vp3_profile_agent_public_request_owner_v110($pdo,$agentCtx,$cid,$reason);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_handoff_requested',$tool,'completed',$startedAt,['conversation_id'=>$cid]);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
    }

    if(str_starts_with($tool,'vp3.booking.')){
        $schedulingContext=vp3_profile_webmcp_scheduling_context_v150(
            $profile,$surface,$telemetry,$surface==='native_profile'?$proof:'',$surface==='external_site'?$property:null,$surface==='external_site'?$origin:''
        );
        if($tool==='vp3.booking.options.list'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,['appointment_types'=>vp3_profile_webmcp_scheduling_options_v150($pdo,$profile)]);
        }
        if($tool==='vp3.booking.availability.list'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_scheduling_availability_v150($pdo,$profile,$args));
        }
        if($tool==='vp3.booking.get'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_scheduling_get_v150($pdo,$profile,$args));
        }
        if(in_array($tool,['vp3.booking.prepare','vp3.booking.reschedule.prepare','vp3.booking.cancel.prepare'],true)){
            if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo)){
                vp3_profile_webmcp_action_respond_v192($respond,$tool,false,['error'=>['code'=>'SCHEDULING_UNAVAILABLE','message'=>'Booking confirmation ledger is unavailable.']],503,'SCHEDULING_UNAVAILABLE');
            }
            $operation=match($tool){
                'vp3.booking.prepare'=>'booking.create',
                'vp3.booking.reschedule.prepare'=>'booking.reschedule',
                default=>'booking.cancel',
            };
            $result=vp3_profile_webmcp_scheduling_prepare_v150($pdo,$profile,$schedulingContext,$operation,$args);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_booking_prepared',$tool,'prepared',$startedAt);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',$startedAt);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if(in_array($tool,['vp3.booking.confirm','vp3.booking.reschedule.confirm','vp3.booking.cancel.confirm'],true)){
            if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo)){
                vp3_profile_webmcp_action_respond_v192($respond,$tool,false,['error'=>['code'=>'SCHEDULING_UNAVAILABLE','message'=>'Booking confirmation ledger is unavailable.']],503,'SCHEDULING_UNAVAILABLE');
            }
            $operation=match($tool){
                'vp3.booking.confirm'=>'booking.create',
                'vp3.booking.reschedule.confirm'=>'booking.reschedule',
                default=>'booking.cancel',
            };
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_scheduling_confirm_v150(
                $pdo,$profile,$schedulingContext,$operation,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??''))
            );
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_booking_completed',$tool,'completed',$startedAt,[
                'booking_id'=>(int)($result['booking']['booking_id']??0),
            ]);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
    }

    if(str_starts_with($tool,'vp3.commerce.')){
        $commerceContext=vp3_profile_webmcp_commerce_context_v160(
            $profile,$surface,$telemetry,$surface==='native_profile'?$proof:'',$surface==='external_site'?$property:null,$surface==='external_site'?$origin:''
        );
        if($tool==='vp3.commerce.products.list'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,['products'=>vp3_profile_webmcp_commerce_products_v160($pdo,$profile)]);
        }
        if($tool==='vp3.commerce.product.get'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_commerce_product_detail_v160($pdo,$profile,(string)($args['product_slug']??'')));
        }
        if($tool==='vp3.commerce.checkout.prepare'){
            $result=vp3_profile_webmcp_commerce_checkout_prepare_v160($pdo,$profile,$commerceContext,$args);
            if(!empty($result['confirmation_required'])){
                vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_checkout_prepared',$tool,'prepared',$startedAt);
                vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',$startedAt);
            }
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.commerce.checkout.confirm'){
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_commerce_checkout_confirm_v160(
                $pdo,$profile,$commerceContext,$telemetryContext,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??'')),!empty($args['terms_accepted'])
            );
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_checkout_started',$tool,'checkout_started',$startedAt,[
                'order_id'=>(int)($result['order']['order_id']??0),
            ]);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.commerce.order.get'||$tool==='vp3.commerce.receipt.get'){
            $result=vp3_profile_webmcp_commerce_order_get_v160($pdo,$profile,$args);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$tool==='vp3.commerce.receipt.get'?['receipt'=>$result]:$result);
        }
        if($tool==='vp3.commerce.delivery.get'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_commerce_delivery_get_v160($pdo,$profile,$args));
        }
        if($tool==='vp3.commerce.refund.status'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_commerce_refund_status_v160($pdo,$profile,$args));
        }
        if($tool==='vp3.commerce.refund.prepare'){
            $result=vp3_profile_webmcp_commerce_refund_prepare_v160($pdo,$profile,$commerceContext,$args);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',$startedAt);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.commerce.refund.confirm'){
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_commerce_refund_confirm_v160(
                $pdo,$profile,$commerceContext,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??''))
            ));
        }
    }

    if(str_starts_with($tool,'vp3.campaign')){
        if($tool==='vp3.campaigns.list'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,['campaigns'=>vp3_profile_webmcp_campaigns_list_v170($pdo,$profile)]);
        }
        if($tool==='vp3.campaign.get'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_campaign_get_v170($pdo,$profile,(string)($args['campaign_slug']??'')));
        }
        if($tool==='vp3.campaign.eligibility.get'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_campaign_eligibility_v170($pdo,$profile,(string)($args['campaign_slug']??'')));
        }
        if($tool==='vp3.campaign.participation.prepare'){
            $campaignContext=vp3_profile_webmcp_campaign_context_v170(
                $profile,$surface,$telemetry,$surface==='native_profile'?$proof:'',$surface==='external_site'?$property:null,$surface==='external_site'?$origin:''
            );
            $result=vp3_profile_webmcp_campaign_prepare_v170($pdo,$profile,$campaignContext,$args);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_campaign_prepared',$tool,'prepared',$startedAt);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',$startedAt);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.campaign.participation.confirm'){
            $campaignContext=vp3_profile_webmcp_campaign_context_v170(
                $profile,$surface,$telemetry,$surface==='native_profile'?$proof:'',$surface==='external_site'?$property:null,$surface==='external_site'?$origin:''
            );
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_campaign_confirm_v170(
                $pdo,$profile,$campaignContext,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??''))
            );
            if(empty($result['idempotent_replay'])){
                vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_campaign_participation_completed',$tool,'completed',$startedAt,[
                    'campaign_public_id'=>(string)($result['campaign']['public_id']??''),
                    'participation_status'=>(string)($result['participation']['status']??''),
                ]);
            }
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.campaign.participation.get'){
            $result=vp3_profile_webmcp_campaign_participation_get_v172($pdo,$profile,$args);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_campaign_status_viewed',$tool,'completed',$startedAt,[
                'campaign_public_id'=>(string)($result['campaign']['public_id']??''),
                'participation_status'=>(string)($result['participation']['status']??''),
            ]);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
    }

    if(str_starts_with($tool,'vp3.reward')||str_starts_with($tool,'vp3.loyalty')){
        if($surface!=='native_profile'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,false,['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That connected-site capability is unavailable.']],404,'CAPABILITY_UNAVAILABLE');
        }
        if($tool==='vp3.rewards.wallet.get'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_rewards_wallet_v180($pdo,$viewer));
        }
        if($tool==='vp3.reward.get'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_reward_get_v181($pdo,$viewer,$args));
        }
        if($tool==='vp3.rewards.claim.prepare'){
            $rewardContext=vp3_profile_webmcp_reward_claim_context_v182($profile,$viewer,$telemetry,$proof);
            $result=vp3_profile_webmcp_reward_claim_prepare_v182($pdo,$profile,$viewer,$rewardContext,$args);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_reward_claim_prepared',$tool,'prepared',$startedAt);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',$startedAt);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.rewards.claim.confirm'){
            $rewardContext=vp3_profile_webmcp_reward_claim_context_v182($profile,$viewer,$telemetry,$proof);
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_reward_claim_confirm_v182(
                $pdo,$profile,$viewer,$rewardContext,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??''))
            );
            if(empty($result['idempotent_replay'])){
                vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_reward_claim_handoff_confirmed',$tool,'completed',$startedAt,[
                    'reward_public_id'=>(string)($result['reward_public_id']??''),
                ]);
            }
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.rewards.transfer.contacts.list'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_reward_transfer_contacts_v183($pdo,$viewer));
        }
        if($tool==='vp3.rewards.transfer.prepare'){
            $rewardContext=vp3_profile_webmcp_reward_claim_context_v182($profile,$viewer,$telemetry,$proof);
            $result=vp3_profile_webmcp_reward_transfer_prepare_v183($pdo,$profile,$viewer,$rewardContext,$args);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_reward_transfer_prepared',$tool,'prepared',$startedAt);
            vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',$startedAt);
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.rewards.transfer.confirm'){
            $rewardContext=vp3_profile_webmcp_reward_claim_context_v182($profile,$viewer,$telemetry,$proof);
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_reward_transfer_confirm_v183(
                $pdo,$profile,$viewer,$rewardContext,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??''))
            );
            if(empty($result['idempotent_replay'])){
                vp3_profile_webmcp_router_record_v191($pdo,$telemetryContext,'webmcp_reward_transfer_completed',$tool,'completed',$startedAt,[
                    'reward_public_id'=>(string)($result['reward_public_id']??''),
                    'transfer_public_id'=>(string)($result['transfer_public_id']??''),
                ]);
            }
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
        }
        if($tool==='vp3.loyalty.status.get'){
            vp3_profile_webmcp_action_respond_v192($respond,$tool,true,vp3_profile_webmcp_loyalty_status_v184($pdo,$viewer));
        }
    }

    $message=$surface==='external_site'
        ?'That connected-site capability is unavailable.'
        :'That profile capability is unavailable.';
    vp3_profile_webmcp_action_respond_v192($respond,$tool,false,['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>$message]],404,'CAPABILITY_UNAVAILABLE');
}
