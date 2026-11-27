<?php
declare(strict_types=1);
const AGENT_GMAIL_DOCUMENT_MAX_BYTES_V1=2097152;

/** Legacy HomeServer Chat cannot receive these native provider file inputs. */
function agent_gmail_document_cloud_route_v1(PDO $pdo,array $user,int $agent): void
{
    if (!function_exists('vp3_agent_runtime_plan_v420')) require_once __DIR__.'/agent-runtime-routing-v420.php';
    $plan=vp3_agent_runtime_plan_v420($pdo,$user,$agent,'chat',['service'=>'conversation','background'=>false]);
    if (!empty($plan['blocked'])||($plan['route']??null)!=='vp3_cloud'||empty($plan['cloud']['ready'])) {
        throw new DomainException('Attachment review needs an available Cloud PDF/image model under this Agent’s current compute preference. No attachment analysis was dispatched.',503);
    }
}

/** Provider-bound input: no URL, uploaded browser data, active document or archive. */
function agent_gmail_document_input_v1(array $document): array
{
    if (array_keys($document)!==['mime','name','bytes']||!is_string($document['bytes'])||$document['bytes']===''
        ||strlen($document['bytes'])>AGENT_GMAIL_DOCUMENT_MAX_BYTES_V1||!is_string($document['name'])||$document['name']===''||!mb_check_encoding($document['name'],'UTF-8')||mb_strlen($document['name'])>180||preg_match('/[\p{Cc}\p{Cf}\/\\\\]/u',$document['name'])
        ||!in_array($document['mime'],['application/pdf','image/jpeg','image/png','image/webp'],true)) throw new DomainException('Invalid attachment input.',422);
    if ($document['mime']==='application/pdf') {
        if (!str_starts_with($document['bytes'],'%PDF-')||!str_contains(substr($document['bytes'],-2048),'%%EOF')
            ||preg_match('/\/Encrypt\b/',$document['bytes'])) throw new DomainException('This PDF is incomplete or password protected. Use an unlocked original.',422);
    } else {
        $image=@getimagesizefromstring($document['bytes']);
        if (!$image||($image['mime']??'')!==$document['mime']||$image[0]<1||$image[1]<1||$image[0]>6000||$image[1]>6000||$image[0]*$image[1]>12000000) {
            throw new DomainException('This image cannot be reviewed. Use a readable image up to 12 megapixels.',422);
        }
    }
    return $document;
}

function agent_gmail_document_provider_content_v1(string $provider,array $document,string $text): array
{
    $document=agent_gmail_document_input_v1($document);
    $data=base64_encode($document['bytes']);$mime=$document['mime'];
    if ($provider==='openai') {
        $part=$mime==='application/pdf'
            ? ['type'=>'input_file','filename'=>preg_match('/\.pdf$/i',$document['name'])?$document['name']:$document['name'].'.pdf','file_data'=>'data:application/pdf;base64,'.$data]
            : ['type'=>'input_image','image_url'=>'data:'.$mime.';base64,'.$data];
        return [['type'=>'input_text','text'=>$text],$part];
    }
    if ($provider!=='anthropic') throw new DomainException('The selected provider does not support private document review.',503);
    return [['type'=>$mime==='application/pdf'?'document':'image','source'=>['type'=>'base64','media_type'=>$mime,'data'=>$data]],['type'=>'text','text'=>$text]];
}
