<?php
if(!function_exists('feed_portion_options_local')){
    function feed_portion_options_local(){
        return [
            'al_amir' => 'Amir',
            'mian_hameed' => 'Hameed',
            'm_ilyas' => 'Ilyas',
        ];
    }
}

if(!function_exists('feed_default_portion_key_local')){
    function feed_default_portion_key_local(){
        return 'al_amir';
    }
}

if(!function_exists('normalize_feed_portion_local')){
    function normalize_feed_portion_local($value){
        $value = strtolower(trim((string)$value));
        $allowed = feed_portion_options_local();
        if(isset($allowed[$value])){
            return $value;
        }
        return feed_default_portion_key_local();
    }
}

if(!function_exists('feed_portion_label_local')){
    function feed_portion_label_local($key){
        $options = feed_portion_options_local();
        $key = normalize_feed_portion_local($key);
        return isset($options[$key]) ? $options[$key] : $options[feed_default_portion_key_local()];
    }
}

if(!function_exists('feed_portion_setting_key_local')){
    function feed_portion_setting_key_local($portionKey){
        $portionKey = normalize_feed_portion_local($portionKey);
        return 'bilty_rate_value_column_' . $portionKey;
    }
}
?>
