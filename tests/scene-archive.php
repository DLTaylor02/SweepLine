<?php
/** Read every native scene group, as the game's folder loader does. */
function slSceneText(ZipArchive $zip,string $base): string
{
    $text='';
    for($i=0;$i<$zip->numFiles;$i++){
        $name=$zip->getNameIndex($i);
        if(str_starts_with($name,$base.'main/MissionGroup/')&&str_ends_with($name,'/items.level.json'))$text.=$zip->getFromIndex($i);
    }
    return $text;
}
