<?php
declare(strict_types=1);

function vp3_coding_canvas_command_library_v1(): array
{
    return [
        'app-data'=>['command'=>'/app-data','label'=>'App data','description'=>'The records saved inside this Canvas.','visibility'=>'private'],
        'gmail'=>['command'=>'/gmail','label'=>'Gmail','description'=>'Read messages in a search or selected threads approved for this Canvas.','visibility'=>'private'],
        'music'=>['command'=>'/music','label'=>'Music','description'=>'Read your VP3 catalog and permitted listening history.','visibility'=>'private'],
        'calendar'=>['command'=>'/calendar','label'=>'Calendar','description'=>'Read your upcoming VP3 calendar events.','visibility'=>'private'],
        'products'=>['command'=>'/products','label'=>'Products','description'=>'Read your VP3 product catalog, including drafts.','visibility'=>'private'],
        'public-products'=>['command'=>'/public-products','label'=>'Public products','description'=>'Show your published catalog and open the existing product page.','visibility'=>'public'],
        'public-events'=>['command'=>'/public-events','label'=>'Public events','description'=>'Show your published events.','visibility'=>'public'],
        'public-booking'=>['command'=>'/public-booking','label'=>'Public booking','description'=>'Show public appointment types and open their booking pages.','visibility'=>'public'],
    ];
}

function vp3_coding_canvas_private_source_commands_v1(): array
{
    return ['music','calendar','products'];
}

function vp3_coding_canvas_command_list_v1(mixed $input): array
{
    $library=vp3_coding_canvas_command_library_v1();
    if(!is_array($input)||!array_is_list($input)||count($input)>count($library)){
        throw new InvalidArgumentException('Choose valid Canvas commands.',422);
    }
    $selected=[];
    foreach($input as $command){
        if(!is_string($command)||!isset($library[$command])||isset($selected[$command])){
            throw new InvalidArgumentException('Choose valid Canvas commands.',422);
        }
        $selected[$command]=true;
    }
    return array_keys(array_intersect_key($library,$selected));
}
