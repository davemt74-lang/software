<?php
declare(strict_types=1);

require_once __DIR__.'/media-studio-v86.php';

const VP3_CODING_CANVAS_LIBRARY_IMAGE_PREFIX_V1 = 'vp3-canvas-library-image-';
const VP3_CODING_CANVAS_LIBRARY_IMAGE_BYTES_V1 = 4 * 1024 * 1024;

function vp3_coding_canvas_library_image_filename_v1(int $revision): string
{
    return VP3_CODING_CANVAS_LIBRARY_IMAGE_PREFIX_V1.$revision.'.json';
}

function vp3_coding_canvas_library_image_manifest_v1(PDO $pdo,array $canvas,int $revision): array
{
    $row=vp3_coding_canvas_artwork_row_v1($pdo,$canvas,vp3_coding_canvas_library_image_filename_v1($revision));
    $manifest=['generation'=>0,'image'=>null];
    if($row!==null){
        if(strlen((string)$row['content_text'])>2048)throw new RuntimeException('The Canvas image settings could not be verified.',409);
        $parsed=json_decode((string)$row['content_text'],true,6,JSON_THROW_ON_ERROR);
        if(!is_array($parsed)||!is_int($parsed['generation']??null)||$parsed['generation']<1||!array_key_exists('image',$parsed)){
            throw new RuntimeException('The Canvas image settings could not be verified.',409);
        }
        $image=$parsed['image'];
        if($image!==null&&(!is_array($image)||!is_int($image['id']??null)||$image['id']<1||!is_string($image['title']??null)
            ||!is_string($image['digest']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$image['digest']))){
            throw new RuntimeException('The Canvas image could not be verified.',409);
        }
        $manifest=['generation'=>$parsed['generation'],'image'=>$image];
    }
    return $manifest + ['row_id'=>$row['id']??null,'token'=>hash('sha256',json_encode([
        'canvas-library-image-v1',$canvas['id'],$revision,$manifest['generation'],$manifest['image'],
    ],JSON_THROW_ON_ERROR))];
}

/** Copy bytes only from a verified original inside this owner's private Media Library directory. */
function vp3_coding_canvas_library_image_read_v1(PDO $pdo,int $owner,int $id): array
{
    $query=$pdo->prepare("SELECT title,mime_type,file_path FROM user_media_assets WHERE id=? AND user_id=? AND media_type='photo'");
    $query->execute([$id,$owner]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('This photo is unavailable in your Media Library.',404);
    $mime=(string)$row['mime_type'];
    $root=realpath(media_studio_user_root($owner));
    $path=realpath(STONEFELLOW_ROOT.'/'.ltrim((string)$row['file_path'],'/'));
    if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)){
        throw new RuntimeException('This photo is unavailable in your Media Library.',404);
    }
    $bytes=@file_get_contents($path,false,null,0,VP3_CODING_CANVAS_LIBRARY_IMAGE_BYTES_V1+1);
    if($bytes===false)throw new RuntimeException('This photo is unavailable in your Media Library.',404);
    if(strlen($bytes)>VP3_CODING_CANVAS_LIBRARY_IMAGE_BYTES_V1){
        throw new InvalidArgumentException('Choose a JPEG, PNG or WebP image up to 4 MB.',422);
    }
    $dimensions=@getimagesizefromstring($bytes);
    $complete=match($mime){
        'image/png'=>str_starts_with($bytes,"\x89PNG\x0d\x0a\x1a\x0a")&&str_ends_with($bytes,"\x00\x00\x00\x00IEND\xae\x42\x60\x82"),
        'image/jpeg'=>str_starts_with($bytes,"\xff\xd8")&&str_ends_with($bytes,"\xff\xd9"),
        'image/webp'=>strlen($bytes)>=12&&str_starts_with($bytes,'RIFF')&&substr($bytes,8,4)==='WEBP'
            &&(unpack('Vlength',substr($bytes,4,4))['length']??-1)===strlen($bytes)-8,
        default=>false,
    };
    if(!$complete||!$dimensions||$dimensions['mime']!==$mime||max($dimensions[0],$dimensions[1])>4096){
        throw new InvalidArgumentException('Choose a JPEG, PNG or WebP image no larger than 4096 by 4096 pixels.',422);
    }
    return ['id'=>$id,'title'=>mb_substr(trim((string)$row['title']),0,190,'UTF-8') ?: 'Media Library image',
        'mime'=>$mime,'digest'=>hash('sha256',$bytes),'bytes'=>$bytes];
}

function vp3_coding_canvas_library_image_publication_v1(PDO $pdo,array $canvas,bool $lock=false): ?int
{
    require_once __DIR__.'/coding-canvas-publishing-v1.php';
    if(!vp3_coding_canvas_publishing_ready_v1($pdo))return null;
    $revision=vp3_coding_canvas_publication_row_v1($pdo,$canvas,$lock)['revision']??null;
    return $revision===null ? null : (int)$revision;
}

/** Accept only an already authorized Canvas row and exact revision. */
function vp3_coding_canvas_library_image_proof_v1(PDO $pdo,array $canvas,int $revision): array
{
    try {
        $manifest=vp3_coding_canvas_library_image_manifest_v1($pdo,$canvas,$revision);
        $attached=$manifest['image'];
        if($attached!==null){
            $image=vp3_coding_canvas_library_image_read_v1($pdo,(int)$canvas['owner_user_id'],$attached['id']);
            if(!hash_equals($attached['digest'],$image['digest']))throw new RuntimeException('The attached photo changed.',409);
        }
        return ['token'=>$manifest['token'],'attached'=>$attached!==null];
    }catch(InvalidArgumentException|RuntimeException|JsonException $error){
        if($error instanceof PDOException)throw $error;
        throw new RuntimeException('The Canvas image could not be verified.',409,$error);
    }
}

function vp3_coding_canvas_library_image_assert_version_v1(PDO $pdo,array $canvas,int $revision,?string $expected): void
{
    $manifest=vp3_coding_canvas_library_image_manifest_v1($pdo,$canvas,$revision);
    if($expected===null&&$manifest['generation']===0)return;
    if($expected===null||!hash_equals($manifest['token'],$expected)){
        throw new RuntimeException('The Canvas image changed. Reopen this version before applying or testing it.',409);
    }
    if($manifest['image']!==null)vp3_coding_canvas_library_image_proof_v1($pdo,$canvas,$revision);
}

/** The server action supplies this projection only to the authenticated parent, never to a guest release. */
function vp3_coding_canvas_library_image_view_v1(PDO $pdo,array $user,array $canvas,int $cid,int $revision): array
{
    try{$manifest=vp3_coding_canvas_library_image_manifest_v1($pdo,$canvas,$revision);}
    catch(JsonException|RuntimeException){return ['state'=>'unavailable'];}
    $isPublished=vp3_coding_canvas_library_image_publication_v1($pdo,$canvas)===$revision;
    $active=(int)$canvas['active_revision']===$revision;
    $draft=(int)$canvas['draft_revision']===$revision;
    $editable=$cid>0&&$cid===(int)$canvas['conversation_id']&&personal_capability_has_v242('personal_knowledge.manage',$user);
    $mode=$editable ? ($isPublished ? ($canvas['draft_revision']===null ? 'draft_copy' : 'select_draft')
        : ($active||$draft ? 'edit' : 'read_only')) : 'read_only';
    $view=['state'=>'ready','token'=>$manifest['token'],'mode'=>$mode,'image'=>null];
    $attachment=$manifest['image'];
    if($attachment===null)return $view;
    $view['image']=['id'=>$attachment['id'],'title'=>$attachment['title'],'available'=>false];
    try{$image=vp3_coding_canvas_library_image_read_v1($pdo,(int)$user['id'],$attachment['id']);}
    catch(InvalidArgumentException|RuntimeException){return $view;}
    if(hash_equals($attachment['digest'],$image['digest'])){
        $view['image']['available']=true;
        $view['image']['data']='data:'.$image['mime'].';base64,'.base64_encode($image['bytes']);
    }
    return $view;
}

function vp3_coding_canvas_library_image_save_v1(PDO $pdo,array $user,int $agent,int $cid,string $canvasId,
    int $revision,string $expected,?int $imageId): array
{
    vp3_coding_canvas_authority_v1($pdo,$user,$agent,true);
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$expected)||$revision<1||($imageId!==null&&$imageId<1)){
        throw new InvalidArgumentException('Select the current app image before saving.',422);
    }
    $canvasId=vp3_coding_canvas_id_v1($canvasId);
    $owner=(int)$user['id'];
    return vp3_coding_canvas_atomic_v1($pdo,$owner,static function()use($pdo,$user,$agent,$cid,$canvasId,$revision,$expected,$imageId,$owner):array{
        $canvas=vp3_coding_canvas_require_row_v1($pdo,$owner,$agent,$canvasId,true);
        if($cid<1||$cid!==(int)$canvas['conversation_id']){
            throw new RuntimeException('Open the Canvas in its original Agent Chat to change the image.',409);
        }
        $base=vp3_coding_canvas_details_v1($pdo,$canvas,$revision);
        $manifest=vp3_coding_canvas_library_image_manifest_v1($pdo,$canvas,$revision);
        if(!hash_equals($manifest['token'],$expected)){
            throw new RuntimeException('The Canvas image changed. Reopen this version and try again.',409);
        }
        $image=$imageId===null ? null : array_intersect_key(vp3_coding_canvas_library_image_read_v1($pdo,$owner,$imageId),
            ['id'=>true,'title'=>true,'digest'=>true]);
        if($manifest['image']===$image)return $base;
        if(vp3_coding_canvas_library_image_publication_v1($pdo,$canvas,true)===$revision){
            if($canvas['draft_revision']!==null){
                throw new RuntimeException('Select the existing private draft before attaching the image.',409);
            }
            $base=vp3_coding_canvas_save_draft_v1($pdo,$user,$agent,$cid,$canvasId,$base['canvas']['title'],
                $base['revision']['artifact'],[],expectedActive:$base['canvas']['active_revision'],commands:$base['revision']['commands']);
            $revision=$base['revision']['id'];
            $manifest=vp3_coding_canvas_library_image_manifest_v1($pdo,$canvas,$revision);
        }elseif(!in_array($base['revision']['status'],['active','draft'],true)){
            throw new RuntimeException('Select the active app or current draft before changing its image.',409);
        }
        $json=json_encode(['generation'=>$manifest['generation']+1,'image'=>$image],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if($manifest['row_id']===null){
            vp3_coding_canvas_insert_item_v1($pdo,$owner,(int)$canvas['folder_id'],'App image for version '.$revision,
                vp3_coding_canvas_library_image_filename_v1($revision),$json);
        }else{
            $pdo->prepare("UPDATE knowledge_items SET content_text=?,file_size=?,updated_at=CURRENT_TIMESTAMP
                WHERE id=? AND created_by_user_id=? AND folder_id=?")
                ->execute([$json,strlen($json),(int)$manifest['row_id'],$owner,(int)$canvas['folder_id']]);
        }
        $pdo->prepare('UPDATE vp3_coding_canvas_versions_v1 SET tested_state_revision=NULL WHERE canvas_id=? AND revision=?')
            ->execute([$canvasId,$revision]);
        return vp3_coding_canvas_details_v1($pdo,vp3_coding_canvas_require_row_v1($pdo,$owner,$agent,$canvasId),$revision);
    });
}
