<?php
declare(strict_types=1);

/** Inspect the container in place. Encoded samples stay on disk, including on memory-limited hosts. */
final class VP3PhoneRecordingMediaV1
{
    private int $remainingElements=50000;

    public function __construct(private $file,private int $length,private bool $mp4) {}

    private function invalid(): never
    {
        throw new RuntimeException('Upload a complete MP4 or WebM camera recording.',422);
    }

    private function read(int $offset,int $bytes): string
    {
        if ($bytes<1 || $bytes>128 || fseek($this->file,$offset)!==0) $this->invalid();
        $data=fread($this->file,$bytes);
        if ($data===false || strlen($data)!==$bytes) $this->invalid();
        return $data;
    }

    // EBML identifiers retain the marker bit. Sizes and block track numbers discard it.
    private function vint(string $header,int &$position,bool $identifier): ?int
    {
        if ($position>=strlen($header)) $this->invalid();
        $first=ord($header[$position++]);
        $width=1;$mask=128;
        while ($mask && !($first & $mask)) {++$width;$mask>>=1;}
        if (!$mask || $width>($identifier?4:8) || $position+$width-1>strlen($header)) $this->invalid();
        $value=$identifier?$first:($first ^ $mask);
        $unknown=!$identifier && $value===$mask-1;
        for ($i=1;$i<$width;++$i) {
            $byte=ord($header[$position++]);$value=($value<<8)|$byte;
            $unknown=$unknown && $byte===255;
        }
        return $unknown?null:$value;
    }

    /** A node holds its type, first payload byte, end offset and optional open-ended flag. */
    private function node(int $offset,int $limit): array
    {
        if (--$this->remainingElements<0 || $offset>=$limit) $this->invalid();
        $header=$this->read($offset,min(16,$limit-$offset));
        $open=false;
        if ($this->mp4) {
            if (strlen($header)<8) $this->invalid();
            $type=substr($header,4,4);
            $size=unpack('N',$header)[1];
            $payload=$offset+8;
            if ($size===1) {
                if (strlen($header)<16 || substr($header,8,4)!=="\0\0\0\0") $this->invalid();
                $size=unpack('N',substr($header,12,4))[1];
                $payload+=8;
            }
            $end=$size===0?$limit:$offset+$size;
        } else {
            $width=0;
            $type=$this->vint($header,$width,true);
            $size=$this->vint($header,$width,false);
            $open=$size===null;
            // Browser WebM writers may leave Segment and Cluster lengths open until playback.
            if ($open && !in_array($type,[0x18538067,0x1F43B675],true)) $this->invalid();
            $payload=$offset+$width;
            $end=$open?$limit:$payload+$size;
        }
        if ($end<$payload || $end>$limit) $this->invalid();
        return [$type,$payload,$end,$open];
    }

    private function children(array $parent,int $skip=0): Generator
    {
        for ($p=$parent[1]+$skip;$p<$parent[2];$p=$item[2]) {
            $item=$this->node($p,$parent[2]);
            yield $item;
        }
    }

    private function child(?array $parent,int|string $type): ?array
    {
        if ($parent) foreach ($this->children($parent) as $node) if ($node[0]===$type) return $node;
        return null;
    }

    private function field(?array $node,int $offset,int $length): string
    {
        return $node && $node[1]+$offset+$length<=$node[2] ? $this->read($node[1]+$offset,$length) : '';
    }

    private function number(?array $node,int $offset=0): int
    {
        $bytes=$this->field($node,$offset,4);
        return $bytes===''?0:unpack('N',$bytes)[1];
    }

    private function webmInteger(?array $node): int
    {
        if (!$node || $node[2]<=$node[1] || $node[2]-$node[1]>4) return 0;
        return unpack('N',str_pad($this->read($node[1],$node[2]-$node[1]),4,"\0",STR_PAD_LEFT))[1];
    }

    private function mp4Track(array $track): ?array
    {
        $media=$this->child($track,'mdia');
        if ($this->field($this->child($media,'hdlr'),8,4)!=='vide') return null;
        $table=$this->child($this->child($media,'minf'),'stbl');
        $descriptions=$this->child($table,'stsd');
        if (!$descriptions || $this->number($descriptions,4)<1) return null;
        $header=$this->child($track,'tkhd');
        $id=$this->number($header,$this->field($header,0,1)==="\x01"?20:12);
        if ($id===0) return null;
        foreach ($this->children($descriptions,8) as $entry) {
            if (!in_array($entry[0],['avc1','avc3','hvc1','hev1','mp4v','vp08','vp09','av01'],true)
                || $entry[2]-$entry[1]<78) continue;
            $dimensions=unpack('nwidth/nheight',$this->field($entry,24,4));
            if ($dimensions['width'] && $dimensions['height']) {
                $sizes=$this->child($table,'stsz')??$this->child($table,'stz2');
                return [$id,$this->number($sizes,8)>0];
            }
        }
        return null;
    }

    private function validMp4(): bool
    {
        $first=$this->node(0,$this->length);
        $brandBytes=$first[2]-$first[1];
        if ($first[0]!=='ftyp' || $brandBytes<8 || $brandBytes%4) return false;
        $brands=str_split($this->read($first[1],min(128,$brandBytes)),4);
        unset($brands[1]); // Minor version, between the major brand and compatible brands.
        if (!array_intersect($brands,['isom','iso2','iso3','iso4','iso5','iso6','iso7','iso8','iso9',
                                      'mp41','mp42','M4V ','avc1','dash','cmfc','cmfs'])) return false;
        $video=[];$populated=[];$media=false;
        foreach ($this->children([null,$first[2],$this->length]) as $box) {
            if ($box[0]==='mdat' && $box[2]-$box[1]>4) $media=true;
            if ($box[0]==='moov') foreach ($this->children($box) as $track) {
                if ($track[0]!=='trak' || !($entry=$this->mp4Track($track))) continue;
                [$id,$hasSamples]=$entry;
                $video[$id]=true;
                if ($hasSamples) $populated[$id]=true;
            }
            if ($box[0]==='moof') foreach ($this->children($box) as $fragment) {
                if ($fragment[0]!=='traf') continue;
                $id=$this->number($this->child($fragment,'tfhd'),4);
                foreach ($this->children($fragment) as $run) {
                    if ($run[0]==='trun' && $this->number($run,4)>0) $populated[$id]=true;
                }
            }
        }
        return $media && (bool)array_intersect_key($video,$populated);
    }

    private function videoTrackNumber(array $entry): int
    {
        if ($this->webmInteger($this->child($entry,0x83))!==1) return 0;
        $codec=$this->child($entry,0x86);
        if (!$codec || $codec[2]-$codec[1]<1 || $codec[2]-$codec[1]>30) return 0;
        if (!in_array($this->read($codec[1],$codec[2]-$codec[1]),['V_VP8','V_VP9','V_AV1'],true)) return 0;
        $video=$this->child($entry,0xE0);
        if (!$this->webmInteger($this->child($video,0xB0)) || !$this->webmInteger($this->child($video,0xBA))) return 0;
        return $this->webmInteger($this->child($entry,0xD7));
    }

    private function validWebm(): bool
    {
        $header=$this->node(0,$this->length);
        if ($header[0]!==0x1A45DFA3) return false;
        $doctype=$this->child($header,0x4282);
        if (!$doctype || $doctype[2]-$doctype[1]!==4 || $this->field($doctype,0,4)!=='webm') return false;
        $segment=$this->child([null,$header[2],$this->length],0x18538067);
        if (!$segment || $segment[2]!==$this->length) return false;
        $tracks=[];$hasVideo=false;$clusterEnd=0;$openCluster=false;
        for ($p=$segment[1];$p<$segment[2];) {
            $inCluster=$p<$clusterEnd;
            $item=$this->node($p,$inCluster?$clusterEnd:$segment[2]);
            [$type,$payload,$p]=$item;
            if ($type===0x1F43B675) {
                if ($inCluster && !$openCluster) return false;
                $clusterEnd=$p;$openCluster=$item[3];$p=$payload;
            } elseif (!$inCluster && $type===0x1654AE6B) {
                foreach ($this->children($item) as $entry) {
                    if ($entry[0]===0xAE && ($id=$this->videoTrackNumber($entry))) $tracks[$id]=true;
                }
            } elseif ($inCluster && ($type===0xA3 || $type===0xA0)) {
                $block=$type===0xA3?$item:$this->child($item,0xA1);
                if (!$block || $block[2]-$block[1]<5) return false;
                $width=0;
                $id=$this->vint($this->read($block[1],min(8,$block[2]-$block[1])),$width,false);
                if ($block[2]-$block[1]<=$width+3) return false;
                if (isset($tracks[$id])) $hasVideo=true;
            }
        }
        return $hasVideo;
    }

    public function validate(): void
    {
        if ($this->length<32 || !($this->mp4?$this->validMp4():$this->validWebm())) $this->invalid();
    }
}

function vp3_phone_record_validate_media_v1(string $path,string $mime): void
{
    $type=strtolower(trim(explode(';',$mime,2)[0]));
    if (!in_array($type,['video/mp4','video/webm'],true)) throw new RuntimeException('Unsupported recording format.',422);
    $file=@fopen($path,'rb');
    if (!$file) throw new RuntimeException('The staged camera recording could not be opened.',503);
    try {
        $stat=fstat($file);
        (new VP3PhoneRecordingMediaV1($file,(int)$stat['size'],$type==='video/mp4'))->validate();
    } finally {
        fclose($file);
    }
}
