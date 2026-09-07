<?php
declare(strict_types=1);

const VP3_AGENT_MANIFEST_VERSION = '1.0';

function vp3_agent_manifest_public_profile(PDO $pdo,int $ownerUserId): ?array
{
    $profile=profile_for_user($pdo,$ownerUserId,false);
    if(!$profile||empty($profile['is_public'])||empty($profile['username'])||empty($profile['is_active']))return null;
    return $profile;
}

function vp3_agent_manifest_links(array $profile,?array $workspace=null): array
{
    $fields=[
        'website'=>'website_url','instagram'=>'instagram_url','tiktok'=>'tiktok_url',
        'youtube'=>'youtube_url','spotify'=>'spotify_url','apple_music'=>'apple_music_url',
    ];
    $out=[];
    foreach($fields as $label=>$field){
        $value=trim((string)($profile[$field]??''));
        if($value===''&&$workspace)$value=trim((string)($workspace[$field]??''));
        if($value!==''&&filter_var($value,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['http','https'],true))$out[$label]=$value;
    }
    return $out;
}

function vp3_agent_manifest_profile(PDO $pdo,array $profile): array
{
    $username=(string)$profile['username'];
    $catalog=profile_public_catalog($pdo,$profile,null);
    $workspace=is_array($catalog['workspace']??null)?$catalog['workspace']:null;
    $bio=trim((string)($profile['bio']??''));if($bio===''&&$workspace)$bio=trim((string)($workspace['bio']??''));
    $collections=[];
    foreach(['tracks'=>'music','shows'=>'shows','posts'=>'posts','merch'=>'merch','photos'=>'photos'] as $key=>$label){
        $count=is_array($catalog[$key]??null)?count($catalog[$key]):0;
        if($count>0)$collections[$label]=['count'=>$count,'endpoint'=>url('/api/agent-content.php?username='.rawurlencode($username).'&collection='.$label)];
    }
    $agent=profile_active_agent($pdo,$profile);
    return [
        'schema'=>'vp3-agent-manifest',
        'version'=>VP3_AGENT_MANIFEST_VERSION,
        'subject'=>[
            'type'=>'vp3_profile','username'=>$username,
            'name'=>trim((string)($profile['display_name']??''))?:$username,
            'url'=>profile_public_url($username),
            'bio'=>mb_strimwidth($bio,0,2000,'…'),
            'links'=>vp3_agent_manifest_links($profile,$workspace),
        ],
        'agent_access'=>[
            'public_content'=>true,
            'structured_content'=>true,
            'gateway_managed'=>true,
            'agent_messaging'=>false,
            'note'=>'Only public profile content is exposed here. Private VP3, CRM, Analytics and HomeServer data are never included.',
        ],
        'endpoints'=>[
            'content'=>url('/api/agent-content.php?username='.rawurlencode($username)),
            'human_profile'=>profile_public_url($username),
        ],
        'collections'=>$collections,
        'profile_agent'=>[
            'available'=>(bool)$agent,
            'name'=>$agent?trim((string)($agent['display_name']??'')):'',
            'messaging_available'=>false,
        ],
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_agent_manifest_property(PDO $pdo,array $property): array
{
    $profile=vp3_agent_manifest_public_profile($pdo,(int)$property['owner_user_id']);
    $domain=trim((string)$property['domain']);
    return [
        'schema'=>'vp3-agent-manifest',
        'version'=>VP3_AGENT_MANIFEST_VERSION,
        'subject'=>[
            'type'=>'connected_website','name'=>trim((string)$property['label'])?:$domain,
            'domain'=>$domain,'url'=>'https://'.$domain.'/',
            'vp3_profile'=>$profile?profile_public_url((string)$profile['username']):null,
        ],
        'agent_access'=>[
            'public_content'=>true,
            'structured_content'=>false,
            'gateway_managed'=>(bool)trim((string)($property['secret_hash']??'')),
            'agent_messaging'=>false,
            'note'=>'This manifest describes the connected property only. It does not expose private VP3 account, CRM, Analytics, Gateway policy or HomeServer data.',
        ],
        'endpoints'=>[
            'website'=>'https://'.$domain.'/',
            'owner_public_manifest'=>$profile?url('/api/agent-manifest.php?username='.rawurlencode((string)$profile['username'])):null,
        ],
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_agent_content_collection(PDO $pdo,array $profile,string $collection=''): array
{
    $catalog=profile_public_catalog($pdo,$profile,null);$workspace=is_array($catalog['workspace']??null)?$catalog['workspace']:null;
    $username=(string)$profile['username'];$displayName=trim((string)($profile['display_name']??''))?:$username;
    $bio=trim((string)($profile['bio']??''));if($bio===''&&$workspace)$bio=trim((string)($workspace['bio']??''));
    $base=[
        'profile'=>[
            'username'=>$username,'name'=>$displayName,'url'=>profile_public_url($username),
            'bio'=>mb_strimwidth($bio,0,4000,'…'),'links'=>vp3_agent_manifest_links($profile,$workspace),
        ],
    ];
    $wanted=strtolower(trim($collection));
    if($wanted===''||$wanted==='music'){
        $rows=[];foreach(array_slice(is_array($catalog['tracks']??null)?$catalog['tracks']:[],0,100) as $row)$rows[]=[
            'id'=>(int)($row['id']??0),'title'=>(string)($row['title']??''),'album'=>(string)($row['album']??''),
            'genre'=>(string)($row['genre']??''),'duration_seconds'=>(int)($row['duration_seconds']??0),
            'description'=>mb_strimwidth(trim((string)($row['description']??'')),0,1000,'…'),
        ];
        if($wanted==='music')return ['profile'=>$base['profile'],'collection'=>'music','items'=>$rows];$base['music']=$rows;
    }
    if($wanted===''||$wanted==='shows'){
        $rows=[];foreach(array_slice(is_array($catalog['shows']??null)?$catalog['shows']:[],0,100) as $row){$when=strtotime((string)($row['show_date']??''));if($when!==false&&$when<time())continue;$rows[]=[
            'id'=>(int)($row['id']??0),'event_name'=>(string)($row['event_name']??''),'venue'=>(string)($row['venue']??''),
            'city'=>(string)($row['city']??''),'region'=>(string)($row['region']??''),'date'=>(string)($row['show_date']??''),
            'status'=>(string)($row['show_status']??'scheduled'),'ticket_url'=>(string)($row['ticket_url']??''),
            'notes'=>mb_strimwidth(trim((string)($row['notes']??'')),0,1000,'…'),
        ];}
        if($wanted==='shows')return ['profile'=>$base['profile'],'collection'=>'shows','items'=>$rows];$base['shows']=$rows;
    }
    if($wanted===''||$wanted==='posts'){
        $rows=[];foreach(array_slice(is_array($catalog['posts']??null)?$catalog['posts']:[],0,50) as $row)$rows[]=[
            'id'=>(int)($row['id']??0),'title'=>(string)($row['title']??''),'published_at'=>(string)($row['published_at']??$row['created_at']??''),
            'body'=>mb_strimwidth(trim((string)($row['body']??'')),0,3000,'…'),
        ];
        if($wanted==='posts')return ['profile'=>$base['profile'],'collection'=>'posts','items'=>$rows];$base['posts']=$rows;
    }
    if($wanted===''||$wanted==='merch'){
        $rows=[];foreach(array_slice(is_array($catalog['merch']??null)?$catalog['merch']:[],0,50) as $row)$rows[]=[
            'id'=>(int)($row['id']??0),'title'=>(string)($row['title']??''),'description'=>mb_strimwidth(trim((string)($row['description']??'')),0,1500,'…'),
            'price_cents'=>(int)($row['price_cents']??0),'purchase_url'=>(string)($row['purchase_url']??$row['url']??''),
        ];
        if($wanted==='merch')return ['profile'=>$base['profile'],'collection'=>'merch','items'=>$rows];$base['merch']=$rows;
    }
    if($wanted===''||$wanted==='photos'){
        $rows=[];foreach(array_slice(is_array($catalog['photos']??null)?$catalog['photos']:[],0,50) as $row)$rows[]=[
            'id'=>(int)($row['id']??0),'title'=>(string)($row['title']??''),'caption'=>mb_strimwidth(trim((string)($row['caption']??'')),0,1000,'…'),
        ];
        if($wanted==='photos')return ['profile'=>$base['profile'],'collection'=>'photos','items'=>$rows];$base['photos']=$rows;
    }
    if($wanted!==''&&!in_array($wanted,['music','shows','posts','merch','photos'],true))throw new RuntimeException('Unknown public content collection.');
    return $base;
}
