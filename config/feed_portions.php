<?php
if(!function_exists('feed_portion_options_local')){
    function feed_portion_options_local(){
        return [
            'al_amir' => 'Amir',
            'mian_hameed' => 'Hamid',
            'm_ilyas' => 'Ilyas',
        ];
    }
}

if(!function_exists('feed_portion_keys_local')){
    function feed_portion_keys_local(){
        return array_keys(feed_portion_options_local());
    }
}

if(!function_exists('feed_default_portion_key_local')){
    function feed_default_portion_key_local(){
        return 'al_amir';
    }
}

if(!function_exists('normalize_feed_portion_key_local')){
    function normalize_feed_portion_key_local($value){
        $value = strtolower(trim((string)$value));
        $allowed = feed_portion_options_local();
        return isset($allowed[$value]) ? $value : '';
    }
}

if(!function_exists('normalize_feed_portion_list_local')){
    function normalize_feed_portion_list_local($value){
        $allowed = feed_portion_options_local();
        $out = [];
        $rawList = [];

        if(is_array($value)){
            $rawList = $value;
        } else {
            $raw = trim((string)$value);
            if($raw !== ''){
                $rawList = preg_split('/[,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        foreach($rawList as $item){
            $key = strtolower(trim((string)$item));
            if($key === '') continue;
            if(isset($allowed[$key]) && !isset($out[$key])){
                $out[$key] = $key;
            }
        }

        if(count($out) === 0){
            $default = feed_default_portion_key_local();
            $out[$default] = $default;
        }

        return array_values($out);
    }
}

if(!function_exists('feed_portion_list_to_csv_local')){
    function feed_portion_list_to_csv_local($value){
        $list = normalize_feed_portion_list_local($value);
        return implode(',', $list);
    }
}

if(!function_exists('normalize_feed_portion_local')){
    function normalize_feed_portion_local($value){
        $list = normalize_feed_portion_list_local($value);
        return isset($list[0]) ? $list[0] : feed_default_portion_key_local();
    }
}

if(!function_exists('feed_portion_label_local')){
    function feed_portion_label_local($key){
        $options = feed_portion_options_local();
        $key = normalize_feed_portion_local($key);
        return isset($options[$key]) ? $options[$key] : $options[feed_default_portion_key_local()];
    }
}

if(!function_exists('feed_portion_labels_local')){
    function feed_portion_labels_local($value){
        $options = feed_portion_options_local();
        $list = normalize_feed_portion_list_local($value);
        $labels = [];
        foreach($list as $key){
            if(isset($options[$key])) $labels[] = $options[$key];
        }
        return $labels;
    }
}

if(!function_exists('feed_portion_labels_string_local')){
    function feed_portion_labels_string_local($value, $sep = ', '){
        return implode($sep, feed_portion_labels_local($value));
    }
}

if(!function_exists('feed_portion_list_has_key_local')){
    function feed_portion_list_has_key_local($value, $key){
        $list = normalize_feed_portion_list_local($value);
        $key = normalize_feed_portion_key_local($key);
        if($key === '') return false;
        return in_array($key, $list, true);
    }
}

if(!function_exists('feed_portion_setting_key_local')){
    function feed_portion_setting_key_local($portionKey){
        $portionKey = normalize_feed_portion_local($portionKey);
        return 'bilty_rate_value_column_' . $portionKey;
    }
}
?>
