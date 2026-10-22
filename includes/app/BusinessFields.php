<?php
namespace ACF_VF\App;

/** Business presets expand to native ACF types and validate through every shared record write. */
class BusinessFields {
    private static $booted=false;
    public static function boot(): void {
        if (self::$booted) { return; } self::$booted=true;
        add_filter('acf_vf/app/validate_fields',array(__CLASS__,'validate'),25,5);
    }

    public static function presets(): array {
        return array(
            'phone'=>array('label'=>'Phone','type'=>'text','instructions'=>'International phone number, for example +14155551234.','business'=>array('preset'=>'phone')),
            'money'=>array('label'=>'Money','type'=>'number','step'=>'0.01','business'=>array('preset'=>'money','precision'=>2)),
            'currency'=>array('label'=>'Currency','type'=>'select','choices'=>array_combine(array('USD','EUR','GBP','CAD','AUD','JPY','CHF','NZD','CNY','INR'),array('USD','EUR','GBP','CAD','AUD','JPY','CHF','NZD','CNY','INR')),'business'=>array('preset'=>'currency','allowed'=>array('USD','EUR','GBP','CAD','AUD','JPY','CHF','NZD','CNY','INR'))),
            'country'=>array('label'=>'Country','type'=>'select','choices'=>array_combine(self::countries(),self::countries()),'business'=>array('preset'=>'country')),
            'address'=>array('label'=>'Address','type'=>'group','sub_fields'=>array(
                array('name'=>'line1','label'=>'Address line 1','type'=>'text'),array('name'=>'line2','label'=>'Address line 2','type'=>'text'),array('name'=>'city','label'=>'City','type'=>'text'),array('name'=>'region','label'=>'Region','type'=>'text'),array('name'=>'postal_code','label'=>'Postal code','type'=>'text'),array('name'=>'country','label'=>'Country','type'=>'select','choices'=>array_combine(self::countries(),self::countries()))
            ),'business'=>array('preset'=>'address','required_parts'=>array('line1','city','country'))),
            'date_range'=>array('label'=>'Date range','type'=>'group','sub_fields'=>array(array('name'=>'start','label'=>'Start','type'=>'date_picker','return_format'=>'Y-m-d','display_format'=>'Y-m-d'),array('name'=>'end','label'=>'End','type'=>'date_picker','return_format'=>'Y-m-d','display_format'=>'Y-m-d')),'business'=>array('preset'=>'date_range')),
            'file'=>array('label'=>'File','type'=>'file','return_format'=>'id','business'=>array('preset'=>'file','max_bytes'=>10485760,'mime_types'=>array('application/pdf','image/jpeg','image/png','text/plain')))
        );
    }

    private static function countries(): array {
        return explode(' ','AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW');
    }

    /** Identity, labels, and explicit constraints survive preset expansion. */
    public static function apply_preset(array $field): array {
        $preset=$field['business']['preset'] ?? '';
        $presets=self::presets();
        if (!is_string($preset) || !isset($presets[$preset])) { return $field; }
        $base=$presets[$preset];
        $field=array_replace($base,$field);
        $field['business']=array_replace($base['business'],$field['business']);
        $field['type']=$base['type'];
        if (in_array($preset,array('country','currency'),true) && isset($field['business']['allowed']) && is_array($field['business']['allowed']) && $field['business']['allowed']) {
            $allowed=array_values(array_filter($field['business']['allowed'],'is_string'));
            if ($allowed) { $field['choices']=array_combine($allowed,$allowed); }
        }
        if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
            foreach ($field['sub_fields'] as &$child) { if (!is_array($child)) { continue; } if (!isset($child['key'])) { $child['key']=($field['key'] ?? 'field_vfa_'.($field['name'] ?? 'business')).'_'.$child['name']; } } unset($child);
        }
        return $field;
    }

    public static function validate_value($value, array $field, bool $retained_file = false) {
        if (empty($field['business'])) { return true; }
        if (!is_array($field['business'])) { return 'The business-field settings are invalid.'; }
        $rules=$field['business']; $preset=$rules['preset'] ?? '';
        $presets=self::presets();
        if (!is_string($preset) || !isset($presets[$preset])) { return 'The business-field preset is unknown.'; }
        if (Rules::empty_value($value)) { return true; }
        if (is_array($value) && in_array($preset,array('address','date_range'),true)) {
            // Native ACF form posts use field keys; the record API uses stable child names.
            foreach ((array)($field['sub_fields'] ?? array()) as $child) {
                if (isset($child['key'],$child['name']) && array_key_exists($child['key'],$value)) {
                    if (array_key_exists($child['name'],$value)) { return 'A structured value contains duplicate child identities.'; }
                    $value[$child['name']]=$value[$child['key']]; unset($value[$child['key']]);
                }
            }
        }
        switch ($preset) {
            case 'phone': return is_string($value) && preg_match('/^\+[1-9][0-9]{6,14}$/D',$value) ? true : 'Use an international phone number with + and 7 to 15 digits.';
            case 'money':
                $precision=$rules['precision'] ?? 2;
                if (!is_int($precision) || $precision<0 || $precision>6) { return 'Money precision must be between 0 and 6.'; }
                if (!is_scalar($value) || is_bool($value) || !preg_match('/^-?\d+(?:\.\d{1,'.max(1,$precision).'})?$/D',(string)$value) || ($precision===0 && strpos((string)$value,'.')!==false) || !is_finite((float)$value) || abs((float)$value)>1e12) { return 'Enter a finite monetary amount with the configured precision.'; }
                if ((isset($rules['min']) && (!is_numeric($rules['min']) || (float)$value<(float)$rules['min'])) || (isset($rules['max']) && (!is_numeric($rules['max']) || (float)$value>(float)$rules['max']))) { return 'The amount is outside its allowed range.'; }
                return true;
            case 'currency': case 'country':
                $pattern=$preset==='country' ? '/^[A-Z]{2}$/D' : '/^[A-Z]{3}$/D';
                if (!is_string($value) || !preg_match($pattern,$value)) { return 'Use the configured uppercase country or currency code.'; }
                if ($preset==='country' && !in_array($value,self::countries(),true)) { return 'The country code is invalid.'; }
                $allowed=$rules['allowed'] ?? ($preset==='country' ? self::countries() : $presets['currency']['business']['allowed']);
                return is_array($allowed) && in_array($value,$allowed,true) ? true : 'This country or currency is not allowed.';
            case 'address':
                if (!is_array($value) || array_diff(array_keys($value),array('line1','line2','city','region','postal_code','country'))) { return 'Use the structured address fields.'; }
                foreach ($value as $part=>$text) { if (!is_string($text) || strlen($text)>500) { return 'Address parts must be text of at most 500 bytes.'; } }
                if (isset($rules['required_parts']) && !is_array($rules['required_parts'])) { return 'Required address parts must be an array of part names.'; }
                foreach ((array)($rules['required_parts'] ?? array('line1','city','country')) as $required) { if (!is_string($required) || !in_array($required,array('line1','line2','city','region','postal_code','country'),true)) { return 'The required address part is unknown.'; } if (!isset($value[$required]) || trim($value[$required])==='') { return 'Complete the required address parts.'; } }
                if (!empty($value['country']) && !in_array($value['country'],self::countries(),true)) { return 'The address country code is invalid.'; }
                return true;
            case 'date_range':
                if (!is_array($value) || array_diff(array_keys($value),array('start','end')) || !isset($value['start'],$value['end'])) { return 'A date range requires start and end dates.'; }
                $start=self::date($value['start']); $end=self::date($value['end']);
                if (!$start || !$end || $start>$end) { return 'Use valid dates with the end on or after the start.'; }
                if (isset($rules['max_days'])) {
                    if (!is_numeric($rules['max_days']) || $rules['max_days']<0 || (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days>(int)$rules['max_days']) { return 'The date range exceeds the allowed duration.'; }
                }
                return true;
            case 'file':
                if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value<1 || get_post_type((int)$value)!=='attachment' || (!$retained_file && !current_user_can('read_post',(int)$value))) { return 'Choose an accessible media attachment.'; }
                $attachment=(int)$value;
                $max=$rules['max_bytes'] ?? 10485760;
                $allowed=$rules['mime_types'] ?? $presets['file']['business']['mime_types'];
                if (!is_numeric($max) || $max<1 || !is_array($allowed) || !in_array(get_post_mime_type($attachment),$allowed,true)) { return 'The attachment type is not allowed.'; }
                $path=get_attached_file($attachment); $size=$path && is_file($path) ? filesize($path) : false;
                if ($size===false || $size>(int)$max) { return 'The attachment is missing or exceeds its size limit.'; }
                return true;
        }
        return 'The business-field configuration is invalid.';
    }

    private static function date($value) {
        if (!is_string($value)) { return false; }
        // Raw ACF date-picker storage uses YYYYMMDD; APIs may send ISO dates.
        if (preg_match('/^\d{8}$/D',$value)) { $value=substr($value,0,4).'-'.substr($value,4,2).'-'.substr($value,6,2); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) { return false; }
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d')===$value ? $value : false;
    }

    public static function validate(array $errors, string $object, array $fields, int $id=0, array $context=array()): array {
        foreach (Schema::fields($object) as $name=>$field) {
            $value=$fields[$name] ?? null;
            // Canonical recalculation retains an existing file without granting read access.
            // Its attachment identity, type, local presence and size are still validated.
            $retained_file=($field['business']['preset'] ?? '')==='file' && Records::derived_unchanged($object,$id,$name,$value,$context);
            $result=self::validate_value($value,$field,$retained_file);
            if ($result!==true) { $errors[]=array('field'=>$name,'code'=>'business_type','message'=>$result); }
        }
        return $errors;
    }
}
