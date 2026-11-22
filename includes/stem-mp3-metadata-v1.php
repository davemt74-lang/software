<?php
declare(strict_types=1);

/** MPEG Layer III metadata without a shell command or installed decoder. */
function stem_mp3_frame_v1(string $bytes,int $offset=0): ?array
{
    if($offset<0||strlen($bytes)<$offset+4)return null;
    $a=ord($bytes[$offset]);$b=ord($bytes[$offset+1]);$c=ord($bytes[$offset+2]);$d=ord($bytes[$offset+3]);
    if($a!==255||($b&224)!==224)return null;
    $version=($b>>3)&3;$layer=($b>>1)&3;$bitrate=($c>>4)&15;$rate=($c>>2)&3;
    if($version===1||$layer!==1||$bitrate<1||$bitrate>14||$rate===3)return null;
    $rates=[44100,48000,32000];$sampleRate=(int)($rates[$rate]/($version===3?1:($version===2?2:4)));
    $bitrates=$version===3?[0,32,40,48,56,64,80,96,112,128,160,192,224,256,320]:[0,8,16,24,32,40,48,56,64,80,96,112,128,144,160];
    $length=(int)floor(($version===3?144000:72000)*$bitrates[$bitrate]/$sampleRate)+(($c>>1)&1);
    return $length<24?null:['length'=>$length,'sample_rate'=>$sampleRate,'channels'=>(($d>>6)&3)===3?1:2,
        'samples'=>$version===3?1152:576,'version'=>$version,'crc'=>($b&1)===0?2:0];
}

/** Read Xing/Info frame counts when present; otherwise count actual frames in
 * bounded windows. Do not substitute a REAPER item length for source duration. */
function stem_mp3_metadata_v1(string $path): ?array
{
    $size=@filesize($path);if($size===false||$size<48)return null;
    static $cache=[];
    $key=$path.':'.$size.':'.(string)@filemtime($path);
    if(array_key_exists($key,$cache))return $cache[$key];
    $handle=@fopen($path,'rb');if(!$handle)return null;
    try{
        $start=0;$tag=(string)fread($handle,10);
        if(substr($tag,0,3)==='ID3'){
            $v=ord($tag[3]);$parts=array_map('ord',str_split(substr($tag,6,4)));
            if($v<2||$v>4||count($parts)!==4||array_filter($parts,static fn(int $n):bool=>($n&128)!==0))return $cache[$key]=null;
            $start=10+($parts[0]<<21)+($parts[1]<<14)+($parts[2]<<7)+$parts[3]+($v===4&&(ord($tag[5])&16)!==0?10:0);
        }
        if($start+48>$size||fseek($handle,$start)!==0)return $cache[$key]=null;
        $window=(string)fread($handle,131072);$header=null;$found=-1;
        for($i=0,$n=strlen($window)-4;$i<=$n;$i++){
            $first=stem_mp3_frame_v1($window,$i);if($first===null)continue;
            $next=stem_mp3_frame_v1($window,$i+$first['length']);
            if($next!==null&&$next['sample_rate']===$first['sample_rate']&&$next['version']===$first['version']){
                $header=$first;$found=$i;break;
            }
        }
        if($header===null)return $cache[$key]=null;
        $start+=$found;$frames=0;$seconds=0.0;$method='frames';
        $side=$header['version']===3?($header['channels']===1?17:32):($header['channels']===1?9:17);
        $xing=$found+4+$header['crc']+$side;
        if(in_array(substr($window,$xing,4),['Xing','Info'],true)&&strlen($window)>=$xing+12){
            $flags=unpack('Nn',substr($window,$xing+4,4))['n'];
            if(($flags&1)!==0){
                $count=(int)unpack('Nn',substr($window,$xing+8,4))['n'];
                if($count>1&&$count<=intdiv($size-$start,24)){
                    $frames=$count;$seconds=$count*$header['samples']/$header['sample_rate'];$method='xing';
                }
            }
        }
        if($frames===0){
            $offset=$start;$windowStart=$start-$found;
            while($offset+4<=$size&&$frames<2000000){
                $cursor=$offset-$windowStart;
                if($cursor<0||$cursor+4>strlen($window)){
                    if(fseek($handle,$offset)!==0)break;
                    $window=(string)fread($handle,131072);$windowStart=$offset;$cursor=0;
                }
                $frame=stem_mp3_frame_v1($window,$cursor);
                if($frame===null||$frame['sample_rate']!==$header['sample_rate']||$frame['version']!==$header['version']
                    ||$offset+$frame['length']>$size)break;
                $seconds+=$frame['samples']/$frame['sample_rate'];$frames++;$offset+=$frame['length'];
            }
        }
        if($frames<2||!is_finite($seconds)||$seconds<=0||$seconds>43200)return $cache[$key]=null;
        // Keep this request cache bounded even for large Track Libraries.
        if(count($cache)>=128)array_shift($cache);
        return $cache[$key]=['channels'=>$header['channels'],'sample_rate'=>$header['sample_rate'],'bit_depth'=>0,
            'duration_seconds'=>$seconds,'data_bytes'=>$size,'format'=>'MP3','metadata_method'=>$method];
    }finally{fclose($handle);}
}
