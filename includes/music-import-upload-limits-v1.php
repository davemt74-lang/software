<?php
declare(strict_types=1);

/** Interpret PHP ini shorthand. Zero and -1 mean no configured ceiling. */
function vp3_music_import_ini_bytes_v1(string $value): int
{
    $value=trim($value);
    if($value===''||$value==='0'||$value==='-1')return PHP_INT_MAX;
    if(!preg_match('/^([0-9]+)([kmg]?)b?$/i',$value,$parts))return 0;
    $base=(int)$parts[1];
    if($base<1)return 0;
    $power=match(strtolower($parts[2]??'')){'k'=>1,'m'=>2,'g'=>3,default=>0};
    for($i=0;$i<$power;$i++){
        if($base>intdiv(PHP_INT_MAX,1024))return PHP_INT_MAX;
        $base*=1024;
    }
    return $base;
}

/** Leave room for multipart headers, session and CSRF fields. */
function vp3_music_import_safe_chunk_v1(int $configured,string $uploadLimit,string $postLimit): int
{
    $upload=vp3_music_import_ini_bytes_v1($uploadLimit);
    $post=vp3_music_import_ini_bytes_v1($postLimit);
    $max=min($configured,max(0,$upload-262144),max(0,$post-262144));
    return $max>=65536?$max:0;
}

function vp3_music_import_rpp_valid_v1(string $name,string $header,int $bytes): bool
{
    if($bytes<1||$bytes>64*1024*1024||!preg_match('/\.rpp(?:-bak)?$/i',$name))return false;
    // REAPER emits UTF-8 text; some edited files carry a BOM.
    return preg_match('/^(?:\xEF\xBB\xBF)?\s*<REAPER_PROJECT\b/i',$header)===1;
}
