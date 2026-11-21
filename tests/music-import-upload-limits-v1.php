<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/music-import-upload-limits-v1.php';
function check_reaper(bool $yes,string $label):void{if(!$yes)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
check_reaper(vp3_music_import_ini_bytes_v1('8M')===8388608,'PHP post-size units');
check_reaper(vp3_music_import_ini_bytes_v1('1G')===1073741824,'PHP giga units');
check_reaper(vp3_music_import_ini_bytes_v1('512K')===524288,'PHP kilo units');
check_reaper(vp3_music_import_ini_bytes_v1('-1')===PHP_INT_MAX,'unlimited setting');
check_reaper(vp3_music_import_ini_bytes_v1('trash')===0,'invalid limit rejected');
check_reaper(vp3_music_import_safe_chunk_v1(8388608,'8M','8M')===8126464,'8 MB post limit leaves multipart headroom');
check_reaper(vp3_music_import_safe_chunk_v1(8388608,'4M','8M')===3932160,'upload_max_filesize caps chunk');
check_reaper(vp3_music_import_safe_chunk_v1(8388608,'8M','2M')===1835008,'post_max_size caps chunk');
check_reaper(vp3_music_import_safe_chunk_v1(8388608,'128K','128K')===0,'impossibly small PHP limits fail closed');
check_reaper(vp3_music_import_rpp_valid_v1('my song.RPP',"<REAPER_PROJECT 0.1\n",100),'uppercase RPP accepted');
check_reaper(vp3_music_import_rpp_valid_v1('backup.rpp-bak',"\xef\xbb\xbf<REAPER_PROJECT 0.1\n",100),'backup with BOM accepted');
check_reaper(!vp3_music_import_rpp_valid_v1('fake.rpp',"PK\x03\x04zip",100),'reject zip disguised as project');
check_reaper(!vp3_music_import_rpp_valid_v1('stems.zip',"<REAPER_PROJECT 0.1",100),'reject invalid extension');
check_reaper(!vp3_music_import_rpp_valid_v1('fake.rpp',"<REAPER_PROJECT 0.1",0),'reject empty project');
check_reaper(!vp3_music_import_rpp_valid_v1('fake.rpp',"<REAPER_PROJECT 0.1",70*1024*1024),'reject oversized project');
echo "REAPER import upload limits: 15 checks passed\n";
