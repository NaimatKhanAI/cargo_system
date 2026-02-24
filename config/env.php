<?php
function load_env_file($path){
if(!file_exists($path) || !is_readable($path)){
return;
}

$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if($lines === false){
return;
}

foreach($lines as $line){
$line = trim($line);
if($line === '' || strpos($line, '#') === 0){
continue;
}

$eqPos = strpos($line, '=');
if($eqPos === false){
continue;
}

$name = trim(substr($line, 0, $eqPos));
$value = trim(substr($line, $eqPos + 1));
if($name === ''){
continue;
}

if((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))){
$value = substr($value, 1, -1);
}

if(getenv($name) === false){
putenv($name . "=" . $value);
$_ENV[$name] = $value;
$_SERVER[$name] = $value;
}
}
}

function env_get($name, $default = null){
$val = getenv($name);
if($val === false){
return $default;
}
return $val;
}

